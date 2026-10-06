<?php

namespace App\Listeners\Entregas;

use App\Jobs\Entregas\EnviarAcaoIfood;
use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\PedidosIfood;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: converte mudança de um pedido iFood no Fleetbase em envio de ação ao iFood (EnviarAcaoIfood).
 * Registrado no AppServiceProvider::boot, sobre o código do Composer (nada em packages/fleetops/server chega à produção):
 *
 * - `Order::updated` (evento do Eloquent) com mudança em driver_assigned_uuid, started ou status. Pega o aceite do
 *   pedido aberto (startOrder: assignDriver + started + atividade, todos com save()), a atribuição pela central
 *   (PUT int/v1/orders/{id}, assign-order do motorista), o "A caminho" e a conclusão (updateActivity → setStatus →
 *   save()) e a API v1 (PUT v1/orders/{id}). No Fleet-Ops desta versão, todo status passa por setStatus(…, true) =
 *   save(). Escapam: o bulk-assign-driver (query builder, sem eventos do Eloquent) e o scheduleOrder (saveQuietly);
 * - `OrderDriverAssigned` (evento do Fleet-Ops): cobre o bulk-assign-driver, que dispara o evento pelo job
 *   NotifyBulkAssignedDriver (quando não é silencioso);
 * - o que ainda escapar (scheduleOrder, um job perdido no Redis sem persistência) o entregas:ifood-acompanhar acha em
 *   até 30 s, pela mesma regra (reconciliação).
 *
 * Nunca lança: roda dentro da requisição do app ou do console, e uma falha aqui não pode impedir a gravação do pedido
 * (vai para o log). Só olha a tabela do iFood quando um dos três campos mudou e a integração está ligada.
 */
class ObservadorDosPedidosIfood
{
    /** Campos do Order cuja mudança pode pedir uma ação no iFood. */
    public const CAMPOS = ['driver_assigned_uuid', 'started', 'status'];

    /** Order::updated (Eloquent). */
    public static function aoAtualizar(object $pedido): void
    {
        try {
            if (!ClienteIfood::ligada() || !$pedido->wasChanged(static::CAMPOS)) {
                return;
            }
            static::avisar((string) $pedido->uuid);
        } catch (\Throwable $e) {
            Log::warning('[entregas] ifood: falha ao enfileirar a ação do pedido', ['pedido' => $pedido->public_id ?? null, 'erro' => get_class($e)]);
        }
    }

    /** OrderDriverAssigned (Fleet-Ops): o evento guarda o uuid do pedido (modelUuid). */
    public static function aoAtribuirMotoboy(object $evento): void
    {
        try {
            if (!ClienteIfood::ligada()) {
                return;
            }
            // o evento já traz o uuid do pedido (ResourceLifecycleEvent::$modelUuid): sem reler o Order no banco
            $uuid = $evento->modelUuid ?? null;
            if (is_string($uuid) && $uuid !== '') {
                static::avisar($uuid);
            }
        } catch (\Throwable $e) {
            Log::warning('[entregas] ifood: falha ao enfileirar a ação do pedido', ['erro' => get_class($e)]);
        }
    }

    protected static function avisar(string $orderUuid): void
    {
        if (PedidosIfood::ehDoIfood($orderUuid)) {
            EnviarAcaoIfood::enfileirar($orderUuid);
        }
    }
}
