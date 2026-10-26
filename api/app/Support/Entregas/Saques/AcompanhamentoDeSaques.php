<?php

namespace App\Support\Entregas\Saques;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: acompanha no Asaas os saques em andamento (rotina entregas:saques-conferir, a cada minuto).
 * Com transfer_id: GET /transfers/{id} e aplica o status (Saques::aplicarStatus, que confere valor e externalReference).
 * 404 num saque criado há mais de 30 min (órfão): procura nas recentes pelo externalReference; achou, adota (log crítico);
 * não achou, falha e devolve. Sem transfer_id há mais de 5 min (a API caiu no meio do envio): procura pelo
 * externalReference; achou, adota; não achou, falha e devolve. Erro de rede, outro erro ou 404 recente nunca dá por
 * falho: tenta no minuto seguinte. Um saque com problema não para os outros, e uma central não para as outras.
 */
final class AcompanhamentoDeSaques
{
    public const PAGINAS = 5;

    public const POR_PAGINA = 100;

    /** Quantos saques terminaram (concluídos ou falhos) nesta passada. */
    public static function conferir(): int
    {
        $porEmpresa = [];
        foreach (DB::table(Saque::TABELA)->whereIn('situacao', Saque::EM_ANDAMENTO)->orderBy('id')->get() as $saque) {
            $porEmpresa[(string) $saque->company_uuid][] = $saque;
        }

        $terminaram = 0;
        foreach ($porEmpresa as $empresa => $saques) {
            try {
                $terminaram += static::daEmpresa((string) $empresa, $saques);
            } catch (\Throwable $e) {
                Log::warning('[entregas] saque: acompanhamento falhou', ['empresa' => $empresa, 'erro' => get_class($e)]);
            }
        }

        return $terminaram;
    }

    private static function daEmpresa(string $empresa, array $saques): int
    {
        $conexao = ConexaoAsaas::daEmpresa($empresa);
        $chave   = $conexao ? Cofre::decifrar((string) $conexao->chave_cifrada) : null;
        if ($chave === null) {
            Log::warning('[entregas] saque: sem a chave do Asaas para acompanhar', ['empresa' => $empresa, 'saques' => count($saques)]);

            return 0;
        }

        $asaas      = new AsaasCliente($chave);
        $recentes   = false;
        $terminaram = 0;
        foreach ($saques as $saque) {
            try {
                $terminaram += static::umSaque($asaas, $saque, $recentes) ? 1 : 0;
            } catch (\Throwable $e) {
                Log::warning('[entregas] saque: acompanhamento do saque falhou', ['saque' => $saque->uuid ?? null, 'erro' => get_class($e)]);
            }
        }

        return $terminaram;
    }

    /**
     * Um saque em andamento; true se terminou agora. $recentes: as transferências recentes do Asaas, buscadas uma vez
     * por central e só se alguém precisar (false = ainda não buscou; null = o Asaas não respondeu).
     */
    private static function umSaque(AsaasCliente $asaas, object $saque, array|false|null &$recentes): bool
    {
        if ($saque->transfer_id !== null) {
            $resposta = $asaas->transferencia((string) $saque->transfer_id);
            if ($resposta->ok() && is_array($resposta->dados)) {
                return Saques::aplicarStatus($saque, $resposta->dados);
            }
            $orfao = now()->subMinutes(Saque::MINUTOS_ORFAO)->format('Y-m-d H:i:s');
            if ($resposta->status !== 404 || (string) $saque->created_at > $orfao) {
                return false;
            }
            // o id que temos não existe no Asaas há mais de 30 min: procura o saque pelo externalReference
            $achada = static::achar($asaas, $saque, $recentes);
            if ($achada === false) {
                return false;
            }
            if ($achada === null) {
                Log::warning('[entregas] saque: transferência não encontrada no Asaas', ['empresa' => $saque->company_uuid, 'saque' => $saque->uuid, 'transfer' => $saque->transfer_id]);

                return Saques::falhar($saque, 'Transferência não encontrada no Asaas.');
            }
            Log::critical('[entregas] saque: transferência do saque trocada pela achada no Asaas', ['empresa' => $saque->company_uuid, 'saque' => $saque->uuid, 'antes' => $saque->transfer_id, 'achada' => (string) $achada['id']]);

            return static::adotar($saque, $achada);
        }

        $limite = now()->subMinutes(Saque::MINUTOS_SEM_TRANSFERENCIA)->format('Y-m-d H:i:s');
        if ((string) $saque->created_at > $limite) {
            return false;
        }
        $achada = static::achar($asaas, $saque, $recentes);
        if ($achada === false) {
            return false;
        }
        if ($achada === null) {
            return Saques::falhar($saque, 'Saque interrompido antes de chegar ao Asaas.');
        }
        Log::warning('[entregas] saque: transferência achada pelo externalReference', ['empresa' => $saque->company_uuid, 'saque' => $saque->uuid, 'transfer' => (string) $achada['id']]);

        return static::adotar($saque, $achada);
    }

    /** A transferência do saque nas recentes (pelo externalReference); null se não está lá; false se o Asaas não respondeu. */
    private static function achar(AsaasCliente $asaas, object $saque, array|false|null &$recentes): array|false|null
    {
        if ($recentes === false) {
            $recentes = static::transferenciasRecentes($asaas);
        }
        if ($recentes === null) {
            return false;
        }

        return $recentes[(string) $saque->external_reference] ?? null;
    }

    /**
     * Adota a transferência achada: só se ela confere com o saque (valor e externalReference) e ele ainda está em
     * andamento (um saque que falhou no meio, valor já devolvido, não volta a processar); depois aplica o status.
     */
    private static function adotar(object $saque, array $achada): bool
    {
        if (!Saques::confere($saque, $achada) || !Saques::gravarTransferencia($saque, (string) $achada['id'])) {
            return false;
        }

        return Saques::aplicarStatus(Saques::recarregar($saque), $achada);
    }

    /** externalReference => transferência, das mais recentes (até 500); null se o Asaas não respondeu. */
    private static function transferenciasRecentes(AsaasCliente $asaas): ?array
    {
        $porReferencia = [];
        for ($pagina = 0; $pagina < static::PAGINAS; $pagina++) {
            $resposta = $asaas->transferencias($pagina * static::POR_PAGINA, static::POR_PAGINA);
            if (!$resposta->ok()) {
                return null;
            }
            $itens = is_array($resposta->dados['data'] ?? null) ? $resposta->dados['data'] : [];
            foreach ($itens as $item) {
                $referencia = $item['externalReference'] ?? null;
                if (is_string($referencia) && $referencia !== '' && is_string($item['id'] ?? null)) {
                    $porReferencia[$referencia] = $item;
                }
            }
            if (count($itens) < static::POR_PAGINA || ($resposta->dados['hasMore'] ?? null) === false) {
                break;
            }
        }

        return $porReferencia;
    }
}
