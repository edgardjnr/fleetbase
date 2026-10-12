<?php

namespace App\Listeners\Entregas;

use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: Order::updating → o pedido nunca perde a coleta e a entrega (payload) numa gravação. Se o
 * payload_uuid vai de preenchido para vazio, volta ao anterior e sai o warning
 * `[entregas] pedido ia perder a coleta e a entrega (payload); mantido`, com a ação da rota (docker service logs
 * entregas_application).
 *
 * Por quê (2026-10-12): a troca de motoboy pelo console deixou o RP570671 e o RP444998 com payload_uuid NULL. Sem payload,
 * o pedido some da lista do motoboy (a consulta da API v1 do Fleet-Ops exige payload_uuid) e dos mapas (o alfinete vem do
 * endereço de entrega), mas continua com o motoboy (capacete amarelo). Trocar um payload por outro passa.
 *
 * Registrado no AppServiceProvider::manterPayloadDosPedidos. Só pega o que passa pelo Eloquent (save/update); um
 * update pelo query builder escapa. Nunca lança: roda dentro da gravação do pedido.
 */
class ManterPayloadDoPedido
{
    public static function aoAtualizar(object $pedido): void
    {
        try {
            if (!$pedido->isDirty('payload_uuid')) {
                return;
            }
            // lidos em variáveis: empty() numa propriedade mágica consulta o __isset, não o valor
            $anterior = $pedido->getOriginal('payload_uuid');
            $novo     = $pedido->payload_uuid;
            if (empty($anterior) || !empty($novo)) {
                return;
            }

            $pedido->payload_uuid = $anterior;
            // uma relação vazia já carregada responderia "sem payload" até o fim da requisição
            if ($pedido->relationLoaded('payload')) {
                $pedido->unsetRelation('payload');
            }

            Log::warning('[entregas] pedido ia perder a coleta e a entrega (payload); mantido', [
                'pedido' => $pedido->public_id,
                'acao'   => static::acao(),
            ]);
        } catch (\Throwable $e) {
            try {
                Log::warning('[entregas] falha ao conferir o payload do pedido', ['erro' => get_class($e)]);
            } catch (\Throwable) {
            }
        }
    }

    protected static function acao(): ?string
    {
        try {
            return request()?->route()?->getActionName();
        } catch (\Throwable) {
            return null;
        }
    }
}
