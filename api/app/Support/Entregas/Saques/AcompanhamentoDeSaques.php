<?php

namespace App\Support\Entregas\Saques;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: acompanha no Asaas os saques em andamento (rotina entregas:saques-conferir, a cada minuto).
 * Com transfer_id: GET /transfers/{id} e aplica o status (Saques::aplicarStatus). Sem transfer_id há mais de 5 min (a API
 * caiu no meio do envio): procura nas transferências recentes pelo externalReference; achou, adota; não achou, falha e
 * devolve o valor. Erro de rede ou 404 nunca dá por falho: tenta no minuto seguinte. Uma central com problema não para
 * as outras.
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
        $limite     = now()->subMinutes(Saque::MINUTOS_SEM_TRANSFERENCIA)->format('Y-m-d H:i:s');
        $recentes   = false;
        $terminaram = 0;
        foreach ($saques as $saque) {
            if ($saque->transfer_id !== null) {
                $resposta = $asaas->transferencia((string) $saque->transfer_id);
                if ($resposta->ok() && is_array($resposta->dados)) {
                    $terminaram += Saques::aplicarStatus($saque, $resposta->dados) ? 1 : 0;
                }
                continue;
            }
            if ((string) $saque->created_at > $limite) {
                continue;
            }
            if ($recentes === false) {
                $recentes = static::transferenciasRecentes($asaas);
            }
            if ($recentes === null) {
                continue;
            }
            $achada = $recentes[(string) $saque->external_reference] ?? null;
            if ($achada) {
                $agora = now()->format('Y-m-d H:i:s');
                DB::table(Saque::TABELA)->where('id', $saque->id)->whereNull('transfer_id')->update(['transfer_id' => (string) $achada['id'], 'situacao' => Saque::PROCESSANDO, 'updated_at' => $agora]);
                $terminaram += Saques::aplicarStatus(Saques::recarregar($saque), $achada) ? 1 : 0;
                continue;
            }
            $terminaram += Saques::falhar($saque, 'Saque interrompido antes de chegar ao Asaas.') ? 1 : 0;
        }

        return $terminaram;
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
