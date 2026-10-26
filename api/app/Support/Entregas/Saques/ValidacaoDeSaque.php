<?php

namespace App\Support\Entregas\Saques;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: a resposta à validação de saque do Asaas (Integrações → Mecanismos de segurança na conta da
 * central). O Asaas pergunta antes de cada transferência por API; só executa com APPROVED. A central é achada pelo hash
 * do token do cabeçalho asaas-access-token. O teste da central (R$ 1,00) é recusado e confirma a configuração. Toda
 * decisão vai ao log (sem o token).
 *
 * Aprova só o saque nosso em andamento, e a validação NUNCA grava o transfer_id (quem grava é o envio, com o id da
 * resposta do NOSSO POST; uma transferência criada por quem tem a chave de API não pode "tomar" um saque):
 * - por id: o saque em andamento da central com este transfer_id, valor igual e, se o pedido trouxer, a mesma chave Pix
 *   (e o mesmo externalReference);
 * - sem id gravado (o Asaas chamou antes de recebermos a resposta do POST), só um saque "enviando" sem id dos últimos
 *   2 min: (a) o pedido traz o externalReference dele ("saque:<uuid>"), com valor igual (e chave igual, se vier); ou
 *   (b) sem externalReference: um único candidato com o mesmo valor e o pedido traz a chave Pix, igual à do saque (o
 *   dinheiro iria para o próprio motoboy). Mais de um candidato ou sem chave para conferir: recusa.
 * Ao aprovar, grava `aprovado_em` no saque (a conclusão sem ele vai ao log como crítica).
 */
final class ValidacaoDeSaque
{
    /** @return array{0: int, 1: array} [status HTTP, corpo] */
    public static function decidir(?string $token, array $corpo): array
    {
        $token   = is_string($token) ? trim($token) : '';
        $hash    = hash('sha256', $token);
        $conexao = $token !== '' ? DB::table(Saque::CONEXOES)->where('token_hash', $hash)->first() : null;
        if (!$conexao || !hash_equals((string) $conexao->token_hash, $hash)) {
            Log::warning('[entregas] saque: validação com token inválido');

            return [401, static::recusa('Token inválido.')];
        }

        $empresa  = (string) $conexao->company_uuid;
        $transfer = is_array($corpo['transfer'] ?? null) ? $corpo['transfer'] : [];
        $id       = is_scalar($transfer['id'] ?? null) ? (string) $transfer['id'] : '';
        $valor    = Saque::centavos($transfer['value'] ?? 0);
        if (($corpo['type'] ?? null) !== 'TRANSFER' || $id === '') {
            return static::decisao($empresa, $id, false, 'Operação não reconhecida pelo Entregas.');
        }

        if (static::ehOTeste($conexao, $id, $valor)) {
            $agora = now()->format('Y-m-d H:i:s');
            DB::table(Saque::CONEXOES)->where('id', $conexao->id)->update(['validacao_confirmada_em' => $agora, 'teste_transfer_id' => $id, 'updated_at' => $agora]);

            return static::decisao($empresa, $id, false, 'Teste do Entregas: validação funcionando.');
        }
        if (!Saque::ligada()) {
            return static::decisao($empresa, $id, false, 'Saque Pix desligado no Entregas.');
        }

        $chaveDeles = static::texto($transfer['bankAccount']['pixAddressKey'] ?? null) ?? static::texto($transfer['pixAddressKey'] ?? null);
        $referencia = static::texto($transfer['externalReference'] ?? null);

        $saque = DB::table(Saque::TABELA)->where('company_uuid', $empresa)->where('transfer_id', $id)->whereIn('situacao', Saque::EM_ANDAMENTO)->first();
        if ($saque) {
            if ($referencia !== null && $referencia !== (string) $saque->external_reference) {
                return static::decisao($empresa, $id, false, 'Transferência não reconhecida pelo Entregas.', (string) $saque->uuid);
            }

            return static::conferirEAprovar($empresa, $id, $saque, $valor, $chaveDeles);
        }

        $candidatos = DB::table(Saque::TABELA)
            ->where('company_uuid', $empresa)
            ->where('situacao', Saque::ENVIANDO)
            ->whereNull('transfer_id')
            ->where('created_at', '>=', now()->subMinutes(Saque::MINUTOS_DA_VALIDACAO)->format('Y-m-d H:i:s'))
            ->orderBy('id')
            ->get()
            ->all();

        if ($referencia !== null) {
            foreach ($candidatos as $candidato) {
                if ((string) $candidato->external_reference === $referencia) {
                    return static::conferirEAprovar($empresa, $id, $candidato, $valor, $chaveDeles);
                }
            }

            return static::decisao($empresa, $id, false, 'Transferência não reconhecida pelo Entregas.');
        }

        $mesmoValor = array_values(array_filter($candidatos, fn ($c) => Saque::centavos($c->valor) === $valor));
        if (count($mesmoValor) !== 1) {
            return static::decisao($empresa, $id, false, 'Transferência não reconhecida pelo Entregas.');
        }
        if ($chaveDeles === null) {
            // sem o id nem o externalReference, só a chave garante que o dinheiro vai para o motoboy
            return static::decisao($empresa, $id, false, 'Transferência sem chave Pix para conferir.', (string) $mesmoValor[0]->uuid);
        }

        return static::conferirEAprovar($empresa, $id, $mesmoValor[0], $valor, $chaveDeles);
    }

    /** A nossa chave e a do Asaas são a mesma (telefone: as duas como 55 + DDD + número, comparadas inteiras). */
    public static function mesmaChave(string $nossa, string $tipo, string $deles): bool
    {
        $limpa = $tipo === 'telefone'
            ? fn (string $chave) => static::telefone($chave)
            : fn (string $chave) => strtolower((string) preg_replace('/[\s.\-\/()+]/', '', $chave));
        $a = $limpa($nossa);
        $b = $limpa($deles);

        return $a !== '' && $a === $b;
    }

    /** Só os dígitos; com 10 ou 11 (DDD + número, sem o país) ganha o "55" na frente. */
    public static function telefone(string $chave): string
    {
        $digitos = (string) preg_replace('/\D/', '', $chave);

        return in_array(strlen($digitos), [10, 11], true) ? '55' . $digitos : $digitos;
    }

    /** O teste da central: o id gravado ou, sem ele, R$ 1,00 até 2 min depois de o teste ser pedido. */
    private static function ehOTeste(object $conexao, string $id, int $valor): bool
    {
        if (($conexao->teste_transfer_id ?? null) !== null) {
            return (string) $conexao->teste_transfer_id === $id;
        }
        $pedido = $conexao->teste_pedido_em ?? null;

        return $pedido !== null
            && (string) $pedido >= now()->subMinutes(Saque::MINUTOS_DA_VALIDACAO)->format('Y-m-d H:i:s')
            && $valor === Saque::centavos(ConexaoAsaas::VALOR_DO_TESTE);
    }

    /** Valor igual e, se o pedido trouxe a chave Pix, a mesma chave: APPROVED (e grava aprovado_em); senão, recusa. */
    private static function conferirEAprovar(string $empresa, string $id, object $saque, int $valor, ?string $chaveDeles): array
    {
        if (Saque::centavos($saque->valor) !== $valor) {
            return static::decisao($empresa, $id, false, 'Valor diferente do saque pedido.', (string) $saque->uuid);
        }
        if ($chaveDeles !== null && !static::mesmaChave((string) $saque->chave_pix, (string) $saque->tipo_chave, $chaveDeles)) {
            return static::decisao($empresa, $id, false, 'Chave Pix diferente da cadastrada.', (string) $saque->uuid);
        }
        $agora = now()->format('Y-m-d H:i:s');
        DB::table(Saque::TABELA)->where('id', $saque->id)->update(['aprovado_em' => $agora, 'updated_at' => $agora]);

        return static::decisao($empresa, $id, true, 'Saque do motoboy.', (string) $saque->uuid);
    }

    /** O texto aparado, ou null se não é texto ou está vazio. */
    private static function texto($valor): ?string
    {
        return is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
    }

    private static function decisao(string $empresa, string $id, bool $aprovar, string $motivo, ?string $saque = null): array
    {
        Log::info('[entregas] saque: validação', ['empresa' => $empresa, 'transfer' => $id, 'decisao' => $aprovar ? 'APPROVED' : 'REFUSED', 'motivo' => $motivo, 'saque' => $saque]);

        return [200, $aprovar ? ['status' => 'APPROVED'] : static::recusa($motivo)];
    }

    private static function recusa(string $motivo): array
    {
        return ['status' => 'REFUSED', 'refuseReason' => $motivo];
    }
}
