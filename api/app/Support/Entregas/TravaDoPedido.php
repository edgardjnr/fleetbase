<?php

namespace App\Support\Entregas;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Entregas RestaurantePro: trava por pedido que põe em fila o aceite do motoboy e o cancelamento (pela loja no
 * portal ou pela API v1).
 *
 * Por quê: os dois caminhos vêm do Composer e não sabem um do outro. No cancelamento pelo portal da loja
 * (RegrasPortalLoja), a regra confere que nenhum motoboy aceitou e só depois o portal grava o status. No aceite
 * pelo app (BarrarAceiteDePedidoEncerrado), a regra confere que o pedido não foi encerrado e só depois o
 * Fleet-Ops o inicia. Sem a trava, um aceite no mesmo instante entra entre a conferência e a gravação do
 * cancelamento, e o pedido cancelado fica iniciado; se for concluído, entra no pagamento dos motoboys e na
 * cobrança da loja. Com a trava, quem chega depois espera o outro terminar e relê o pedido antes de decidir.
 * O cancelamento pela API v1 (DELETE v1/orders/{id}/cancel, o caminho provável da integração iFood) entra na
 * mesma fila pelo BarrarAceiteDePedidoEncerrado, sem conferência: só não corre junto com um aceite.
 *
 * A trava fica no cache, que em produção é o Redis (CACHE_DRIVER=redis no deploy/docker-stack.yml): vale entre
 * os workers do Octane e entre as réplicas da API. Com um cache que não seja compartilhado, ela não serviria.
 */
class TravaDoPedido
{
    /** Validade da trava, em segundos: se o processo morrer com ela, ela se solta sozinha depois disto. */
    public const VALIDADE = 30;

    /** Quanto esperar pela trava, em segundos, antes de desistir (quem chama responde 409). */
    public const ESPERA = 10;

    /**
     * Roda $fazer com a trava do pedido e devolve o que ele devolver. A trava é solta no fim, mesmo com erro.
     *
     * @throws LockTimeoutException se a trava não sair em ESPERA segundos
     */
    public static function executar(string $pedidoUuid, Closure $fazer): mixed
    {
        return Cache::lock("entregas:pedido:{$pedidoUuid}", static::VALIDADE)->block(static::ESPERA, $fazer);
    }
}
