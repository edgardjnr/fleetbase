<?php

namespace App\Listeners\Entregas;

use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Distribuidor;
use App\Support\Entregas\StatusDoPedido;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: Order::updated → encerra a distribuição do pedido aberto quando ele ganha motoboy (aceite,
 * atribuição pela central, troca pelo líder), é encerrado (cancelamento, conclusão) ou deixa de ser aberto sem motoboy
 * (a central desligou o adhoc: cancelada). Registrado no
 * AppServiceProvider::distribuirPedidosAbertos, ao lado do ObservadorDosPedidosIfood.
 *
 * Não toma a TravaDoPedido (o Distribuidor::encerrar só grava): roda dentro do startOrder (aceite), que está dentro da
 * trava do BarrarAceiteDePedidoEncerrado, e dentro da TrocaDoMotoboy, que também a segura (a trava não é reentrante).
 * No aceite, o encerramento daqui (atribuida, oferta cancelada) é depois sobrescrito pelo registrarAceite do middleware
 * (aceita): é o esperado.
 *
 * Nunca lança: roda dentro da gravação do pedido, e uma falha aqui não pode impedi-la (vai para o log). O que escapar
 * (saveQuietly, bulk-assign pelo query builder), o próprio Distribuidor e a varredura encerram ao ver o pedido.
 */
class ObservadorDaDistribuicao
{
    public static function aoAtualizar(object $pedido): void
    {
        try {
            if (!Distribuicao::ligada() || !$pedido->wasChanged(['driver_assigned_uuid', 'status', 'adhoc'])) {
                return;
            }
            $motivo = null;
            if ($pedido->wasChanged('driver_assigned_uuid') && $pedido->driver_assigned_uuid) {
                $motivo = Distribuicao::ATRIBUIDA;
            } elseif ($pedido->wasChanged('status') && in_array(strtolower((string) $pedido->status), StatusDoPedido::ENCERRADOS, true)) {
                $motivo = Distribuicao::CANCELADA;
            } elseif ($pedido->wasChanged('adhoc') && !$pedido->adhoc && !$pedido->driver_assigned_uuid) {
                // a central desligou o "pedido aberto" sem atribuir motoboy: não há mais o que oferecer
                $motivo = Distribuicao::CANCELADA;
            }
            if ($motivo) {
                app(Distribuidor::class)->encerrar((string) $pedido->uuid, $motivo);
            }
        } catch (\Throwable $e) {
            Log::warning('[entregas] distribuição: falha ao encerrar a distribuição do pedido', ['pedido' => $pedido->public_id ?? null, 'erro' => get_class($e)]);
        }
    }
}
