<?php

namespace App\Listeners\Entregas;

use App\Jobs\Entregas\EnviarAvisoDaIntegracao;
use App\Support\Entregas\Integracao\Integracao;
use App\Support\Entregas\Integracao\PedidosDaIntegracao;
use App\Support\Entregas\StatusDoPedido;
use Fleetbase\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: converte a mudança de um pedido da integração no aviso de volta ao sistema de origem
 * (EnviarAvisoDaIntegracao). Registrado no AppServiceProvider, como o ObservadorDosPedidosIfood:
 * - `Order::updated` com mudança em driver_assigned_uuid, started ou status;
 * - `OrderDriverAssigned` (atribuição em lote);
 * - o que escapar (saveQuietly, job perdido) o entregas:integracao-acompanhar acha em até 1 min.
 *
 * No cancelamento que não veio da origem, grava na linha quem cancelou: "loja" (usuário de loja, o portal), "central"
 * (o console, a API v1) ou "sistema" (expirado). Nunca lança: roda dentro da requisição do app ou do console.
 */
class ObservadorDosPedidosDaIntegracao
{
    public const CAMPOS = ['driver_assigned_uuid', 'started', 'status'];

    public static function aoAtualizar(object $pedido): void
    {
        try {
            if (!Integracao::ligada() || !$pedido->wasChanged(static::CAMPOS)) {
                return;
            }
            $linha = PedidosDaIntegracao::doPedido((string) $pedido->uuid);
            if (!$linha) {
                return;
            }
            // o status pode vir com maiúsculas (API v1): a mesma regra do SequenciaDaIntegracao::alvo
            $status    = strtolower((string) $pedido->status);
            $cancelado = in_array($status, StatusDoPedido::CANCELADOS, true) || $status === 'expired';
            if ($cancelado && !$linha->cancelado_em) {
                PedidosDaIntegracao::atualizar($linha, [
                    'cancelado_em'  => now()->toDateTimeString(),
                    'cancelado_por' => $status === 'expired' ? 'sistema' : static::quemCancelou(),
                ]);
            }
            EnviarAvisoDaIntegracao::enfileirar((string) $pedido->uuid);
        } catch (\Throwable $e) {
            Log::warning('[entregas] integração: falha ao enfileirar o aviso do pedido', ['pedido' => $pedido->public_id ?? null, 'erro' => get_class($e)]);
        }
    }

    /** OrderDriverAssigned (Fleet-Ops): o evento guarda o uuid do pedido (modelUuid). */
    public static function aoAtribuirMotoboy(object $evento): void
    {
        try {
            $uuid = $evento->modelUuid ?? null;
            if (Integracao::ligada() && is_string($uuid) && $uuid !== '' && PedidosDaIntegracao::ehDaIntegracao($uuid)) {
                EnviarAvisoDaIntegracao::enfileirar($uuid);
            }
        } catch (\Throwable $e) {
            Log::warning('[entregas] integração: falha ao enfileirar o aviso do pedido', ['erro' => get_class($e)]);
        }
    }

    /** "loja" se quem está na sessão é usuário de loja (portal), senão "central". */
    protected static function quemCancelou(): string
    {
        try {
            $usuario = session('user');
            if (!$usuario) {
                return 'central';
            }

            return User::where('uuid', $usuario)->first()?->type === 'customer' ? 'loja' : 'central';
        } catch (\Throwable $e) {
            return 'central';
        }
    }
}
