<?php

namespace App\Listeners\Entregas;

use App\Support\Entregas\Sistema\CorridasFinalizadas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: Order::updated → quando o status do pedido muda para concluído (completed/done), grava a
 * corrida no registro permanente do dono do sistema (CorridasFinalizadas, origem `conclusao`, hora = agora em
 * Brasília). Registrado no AppServiceProvider::registrarCorridasDoSistema, sem depender de chave nenhuma (nem da
 * ENTREGAS_FINANCEIRO). O que escapar (saveQuietly, query builder, banco fora do ar) o entregas:corridas-conferir grava
 * em até 5 min.
 *
 * Relê o pedido do banco só com CorridasFinalizadas::COLUNAS_DO_PEDIDO (pelo DB::table, sem os escopos do Eloquent): o
 * model pode ter vindo de um select parcial (sem a empresa, a loja ou o motoboy). Sem a linha, usa o próprio model.
 *
 * Nunca lança: roda dentro da gravação do pedido (aviso no log só com o pedido e a classe do erro).
 */
class ObservadorDasCorridasDoSistema
{
    public static function aoAtualizar(object $pedido): void
    {
        try {
            if (!$pedido->wasChanged('status')) {
                return;
            }
            // o status pode vir com maiúsculas (API v1)
            if (!in_array(strtolower((string) $pedido->status), CorridasFinalizadas::CONCLUIDOS, true)) {
                return;
            }
            $uuid = (string) ($pedido->uuid ?? '');
            if ($uuid === '') {
                return;
            }
            $linha = DB::table('orders')->select(CorridasFinalizadas::COLUNAS_DO_PEDIDO)->where('uuid', $uuid)->first();
            CorridasFinalizadas::registrarPedido($linha ?? $pedido, CorridasFinalizadas::CONCLUSAO, now()->format('Y-m-d H:i:s'));
        } catch (\Throwable $e) {
            Log::warning('[entregas] sistema: falha ao registrar a corrida finalizada', ['pedido' => $pedido->public_id ?? null, 'erro' => get_class($e)]);
        }
    }
}
