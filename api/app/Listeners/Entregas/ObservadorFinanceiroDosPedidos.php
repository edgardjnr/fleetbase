<?php

namespace App\Listeners\Entregas;

use App\Jobs\Entregas\LancarCorridaDoPedido;
use App\Support\Entregas\Financeiro\Financeiro;
use App\Support\Entregas\StatusDoPedido;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: lança a corrida no financeiro quando o pedido conclui (ou é cancelado: o cancelado pago pelo
 * iFood ou pela integração também entra), sem atrasar a conclusão: enfileira o LancarCorridaDoPedido. Registrado no
 * AppServiceProvider (`Order::updated`), como os outros observadores. O que escapar (saveQuietly, fila fora do ar), o
 * entregas:financeiro-conferir lança em até 5 min. Nunca lança exceção: roda dentro da requisição do app ou do console.
 */
class ObservadorFinanceiroDosPedidos
{
    /** Status que podem tornar o pedido pagável (o relatório só conta 'completed' e o cancelado pago). */
    public const CONCLUIDOS = ['completed', 'done'];

    public static function aoAtualizar(object $pedido): void
    {
        try {
            if (!Financeiro::ligada() || !$pedido->wasChanged('status')) {
                return;
            }
            // o status pode vir com maiúsculas (API v1)
            $status = strtolower((string) $pedido->status);
            if (!in_array($status, static::CONCLUIDOS, true) && !in_array($status, StatusDoPedido::CANCELADOS, true)) {
                return;
            }
            LancarCorridaDoPedido::enfileirar((string) $pedido->uuid);
        } catch (\Throwable $e) {
            Log::warning('[entregas] financeiro: falha ao enfileirar o lançamento da corrida', ['pedido' => $pedido->public_id ?? null, 'erro' => get_class($e)]);
        }
    }
}
