<?php

namespace App\Events\Entregas;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Entregas RestaurantePro: o iFood recusou uma ação de logística de um pedido (409 ou outro 4xx), o vínculo da loja
 * caiu, o motoboy não tem telefone, ou as tentativas acabaram com o iFood fora do ar (AcoesIfood). Vai no canal da
 * empresa para o console avisar a central (serviço ifood-acao-recusada do Fleet-Ops), que confere o pedido no Gestor
 * de Pedidos do iFood. Enviado por TransmissaoNoSocket, que confere o retorno do socket; o ShouldBroadcastNow só marca o
 * evento como imediato, sem fila. Sem dados do cliente.
 */
class IfoodAcaoRecusada implements ShouldBroadcastNow
{
    public const NOME = 'entregas.ifood_acao_recusada';

    public function __construct(
        public string $empresaUuid,
        public string $pedidoUuid,
        public string $pedidoPublicId,
        public ?string $numero,
        public string $acao,
        public int $status,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('company.' . $this->empresaUuid)];
    }

    public function broadcastAs(): string
    {
        return static::NOME;
    }

    public function broadcastWith(): array
    {
        return [
            'event'      => static::NOME,
            'created_at' => now()->toIso8601String(),
            'data'       => [
                'id'     => $this->pedidoPublicId,
                'uuid'   => $this->pedidoUuid,
                // número do iFood (internal_id) ou, sem ele, o public_id
                'numero' => $this->numero ?: $this->pedidoPublicId,
                'acao'   => $this->acao,
                // 0 = não chegou a ser uma resposta do iFood (vínculo perdido, motoboy sem telefone, iFood fora do ar)
                'status' => $this->status,
            ],
        ];
    }
}
