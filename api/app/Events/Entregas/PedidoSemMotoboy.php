<?php

namespace App\Events\Entregas;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Entregas RestaurantePro: pedido aberto que ninguém aceitou em ~12 min do despacho (ReenviarPedidosAbertos). Vai no
 * canal da empresa para o console avisar a central com som e notificação fixa (serviço pedido-sem-motoboy do
 * Fleet-Ops), que atribui um motoboy à mão. Transmitido na hora (ShouldBroadcastNow): o comando roda no agendador, sem
 * passar pela fila.
 */
class PedidoSemMotoboy implements ShouldBroadcastNow
{
    public const NOME = 'entregas.pedido_sem_motoboy';

    public function __construct(
        public string $empresaUuid,
        public string $pedidoUuid,
        public string $pedidoPublicId,
        public ?string $numero,
        public int $minutos,
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
                'id'      => $this->pedidoPublicId,
                'uuid'    => $this->pedidoUuid,
                // número curto do pedido (internal_id; no iFood, o número do iFood) ou, sem ele, o public_id
                'numero'  => $this->numero ?: $this->pedidoPublicId,
                'minutos' => $this->minutos,
            ],
        ];
    }
}
