<?php

namespace App\Listeners\Entregas;

use App\Support\Entregas\PrazoDaEntrega;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: Order::creating → todo pedido novo nasce com o prazo de entrega no meta
 * (PrazoDaEntrega::CHAVE): 50 min depois do max(agora, scheduled_at). O pedido que já chega com um prazo legível (o do
 * iFood, gravado pelo CriadorDoPedidoIfood antes do save) fica como veio; um prazo ilegível é trocado pelo padrão.
 *
 * Vale para todo caminho que cria pelo Eloquent: portal da loja (o RegrasPortalLoja descarta o meta da requisição, e o
 * hook roda depois, no model), central (console), API v1 e a integração de outros sistemas (CriadorDoPedidoDaIntegracao).
 * O creating não dispara o updated: a distribuição e os observadores dos pedidos não percebem. Um insert pelo query
 * builder (ou um saveQuietly na criação) escapa.
 *
 * Meta em texto solto (não JSON) fica como está, sem prazo: o meta é de quem criou. Registrado no
 * AppServiceProvider::gravarPrazoDosPedidos. Nunca lança: roda dentro da criação do pedido; a falha vira o warning
 * `[entregas] falha ao gravar o prazo do pedido` (só a classe da exceção) e o pedido nasce sem prazo.
 */
class GravarPrazoDoPedido
{
    public static function aoCriar(object $pedido): void
    {
        try {
            $meta = $pedido->meta ?? null;
            if ($meta === null || $meta === '') {
                $meta = [];
            }
            if (!is_array($meta) || PrazoDaEntrega::daMeta($meta) !== null) {
                return;
            }

            $meta[PrazoDaEntrega::CHAVE] = PrazoDaEntrega::padrao(now(), static::agendadoPara($pedido->scheduled_at ?? null));
            $pedido->meta                = $meta;
        } catch (\Throwable $e) {
            try {
                Log::warning('[entregas] falha ao gravar o prazo do pedido', ['erro' => get_class($e)]);
            } catch (\Throwable) {
            }
        }
    }

    /** O scheduled_at como data: o Carbon do cast do Eloquent ou o texto 'Y-m-d H:i:s' no fuso do app; o resto, null. */
    protected static function agendadoPara($valor): ?\DateTimeInterface
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor;
        }
        if (!is_string($valor) || trim($valor) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable(trim($valor), new \DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            return null;
        }
    }
}
