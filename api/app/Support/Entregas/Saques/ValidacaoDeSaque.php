<?php

namespace App\Support\Entregas\Saques;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: a resposta à validação de saque do Asaas (Integrações → Mecanismos de segurança na conta da
 * central). O Asaas pergunta antes de cada transferência por API; só executa com APPROVED. A central é achada pelo hash
 * do token do cabeçalho asaas-access-token. Aprova só o saque nosso em andamento da central com o mesmo transfer.id
 * (ou, se o Asaas chamou antes de gravarmos o id, o saque "enviando" dos últimos 2 min com o mesmo valor), mesmo valor e,
 * se o pedido trouxer, a mesma chave Pix. O teste da central (R$ 1,00) é recusado e confirma a configuração. Toda
 * decisão vai ao log (sem o token).
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

        $saque = static::saqueDaTransferencia($empresa, $id, $valor);
        if (!$saque) {
            return static::decisao($empresa, $id, false, 'Transferência não reconhecida pelo Entregas.');
        }
        if (Saque::centavos($saque->valor) !== $valor) {
            return static::decisao($empresa, $id, false, 'Valor diferente do saque pedido.', (string) $saque->uuid);
        }
        $chaveDeles = $transfer['bankAccount']['pixAddressKey'] ?? ($transfer['pixAddressKey'] ?? null);
        if (is_string($chaveDeles) && trim($chaveDeles) !== '' && !static::mesmaChave((string) $saque->chave_pix, (string) $saque->tipo_chave, $chaveDeles)) {
            return static::decisao($empresa, $id, false, 'Chave Pix diferente da cadastrada.', (string) $saque->uuid);
        }

        return static::decisao($empresa, $id, true, 'Saque do motoboy.', (string) $saque->uuid);
    }

    /** A nossa chave e a do Asaas são a mesma (telefone: uma termina com a outra, por causa do +55). */
    public static function mesmaChave(string $nossa, string $tipo, string $deles): bool
    {
        $limpa = fn (string $chave) => strtolower((string) preg_replace('/[\s.\-\/()+]/', '', $chave));
        $a     = $limpa($nossa);
        $b     = $limpa($deles);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($tipo === 'telefone') {
            return str_ends_with($b, $a) || str_ends_with($a, $b);
        }

        return $a === $b;
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

    /** O saque em andamento da central com este id; ou o "enviando" recente sem id e com o mesmo valor (e grava o id). */
    private static function saqueDaTransferencia(string $empresa, string $id, int $valor): ?object
    {
        $saque = DB::table(Saque::TABELA)->where('company_uuid', $empresa)->where('transfer_id', $id)->whereIn('situacao', Saque::EM_ANDAMENTO)->first();
        if ($saque) {
            return $saque;
        }

        $desde      = now()->subMinutes(Saque::MINUTOS_DA_VALIDACAO)->format('Y-m-d H:i:s');
        $candidatos = DB::table(Saque::TABELA)
            ->where('company_uuid', $empresa)
            ->where('situacao', Saque::ENVIANDO)
            ->whereNull('transfer_id')
            ->where('created_at', '>=', $desde)
            ->orderBy('id')
            ->get();
        foreach ($candidatos as $candidato) {
            if (Saque::centavos($candidato->valor) !== $valor) {
                continue;
            }
            $gravou = DB::table(Saque::TABELA)->where('id', $candidato->id)->whereNull('transfer_id')->update(['transfer_id' => $id, 'updated_at' => now()->format('Y-m-d H:i:s')]);
            if ($gravou > 0) {
                return DB::table(Saque::TABELA)->where('id', $candidato->id)->first();
            }
        }

        return null;
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
