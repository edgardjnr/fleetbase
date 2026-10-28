<?php

namespace App\Support\Entregas\PrePago;

use App\Support\Entregas\PedidoRecusado;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Entregas RestaurantePro: o pedido novo da loja pré-paga só sai com crédito para pelo menos a entrega mais barata
 * (CarteiraDaLoja). Confere e cria com a trava da carteira da loja: o pedido criado já reserva crédito, então dois pedidos
 * seguidos não passam com o crédito de um. Loja pós-paga: cria direto. Usado pelo RegrasPortalLoja (POST orders do
 * portal) e pelo ChamarMotoboyController.
 */
final class CreditoNoPedido
{
    /** Trava da carteira (Redis): validade e espera, em segundos. */
    public const VALIDADE_DA_TRAVA = 30;
    public const ESPERA_DA_TRAVA   = 10;

    /**
     * @return mixed o que $criar devolver
     *
     * @throws PedidoRecusado 422 sem crédito; 409 com outro pedido da loja sendo criado
     */
    public static function criar(string $empresa, string $lojaUuid, Closure $criar)
    {
        if (!PrePago::ehPrePaga($empresa, $lojaUuid)) {
            return $criar();
        }

        try {
            return Cache::lock('entregas:carteira:' . $lojaUuid, static::VALIDADE_DA_TRAVA)->block(static::ESPERA_DA_TRAVA, function () use ($empresa, $lojaUuid, $criar) {
                if ($semCredito = CarteiraDaLoja::motivoDoBloqueio($empresa, $lojaUuid)) {
                    throw new PedidoRecusado(422, $semCredito);
                }

                return $criar();
            });
        } catch (LockTimeoutException $e) {
            throw new PedidoRecusado(409, 'Outro pedido da loja está sendo criado. Tente de novo em alguns segundos.');
        }
    }
}
