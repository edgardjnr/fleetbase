<?php

namespace App\Listeners\Entregas;

use App\Support\Entregas\CalculoEntregas;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: Order::updated → quando o motoboy do pedido muda (aceite, atribuição pela central, troca pelo
 * líder, pedido que volta a ficar sem motoboy), grava a parte da atribuição do valor congelado (posição e multiplicador:
 * CalculoEntregas::registrarAtribuicao). Registrado no AppServiceProvider::congelarValorNaAtribuicao. Independe da
 * distribuição estar ligada.
 *
 * Relê o pedido (instância própria: o cálculo grava o km no meta com timestamps desligados, e isso não pode vazar para a
 * instância que o Fleet-Ops ainda vai salvar) e põe a empresa dele na sessão (as faixas são da empresa da sessão; o
 * aceite pelo app e os comandos da fila podem rodar sem ela).
 *
 * Nunca lança: roda dentro da gravação do pedido (vai para o log). O que escapar (saveQuietly, atribuição pelo query
 * builder) o CalculoEntregas reconstitui pelos horários quando o pedido concluir.
 */
class ObservadorDoValorDaEntrega
{
    /** Testes: devolve o cálculo no lugar do container. */
    public static ?\Closure $calculo = null;

    public static function aoAtualizar(object $pedido): void
    {
        try {
            if (!$pedido->wasChanged('driver_assigned_uuid')) {
                return;
            }

            $fresco = Order::where('company_uuid', $pedido->company_uuid)
                ->where('uuid', $pedido->uuid)
                ->with(['payload.pickup', 'payload.dropoff', 'payload.waypoints'])
                ->first();
            if (!$fresco) {
                return;
            }

            $calculo  = static::$calculo ? (static::$calculo)() : app(CalculoEntregas::class);
            $anterior = session('company');
            session(['company' => $pedido->company_uuid]);
            try {
                $calculo->registrarAtribuicao($fresco);
            } finally {
                // sem empresa antes (fila, comando): tira a chave; com null nela, o Setting::lookupFromCompany monta
                // 'company..'
                $anterior === null ? session()->forget('company') : session(['company' => $anterior]);
            }
        } catch (\Throwable $e) {
            Log::warning('[entregas] valor: falha ao gravar a atribuição do pedido', ['pedido' => $pedido->public_id ?? null, 'erro' => get_class($e)]);
        }
    }
}
