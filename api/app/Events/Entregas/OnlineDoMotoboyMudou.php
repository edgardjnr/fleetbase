<?php

namespace App\Events\Entregas;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Entregas RestaurantePro: o motoboy ligou ou desligou o online no app. Vai no canal da empresa para o mapa ao vivo
 * do console trocar a cor do capacete na hora (o toggle-online do Fleet-Ops grava com updateQuietly e não avisa
 * ninguém). Transmitido na hora (ShouldBroadcastNow), sem passar pela fila; a empresa vem no próprio evento, não da
 * sessão. Disparado pelo AvisarOnlineDoMotoboy.
 */
class OnlineDoMotoboyMudou implements ShouldBroadcastNow
{
    public const NOME = 'entregas.motoboy_online';

    public function __construct(
        public string $empresaUuid,
        public string $motoboyUuid,
        public ?string $motoboyPublicId,
        public bool $online,
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
                'id'     => $this->motoboyPublicId,
                'uuid'   => $this->motoboyUuid,
                'online' => $this->online,
            ],
        ];
    }
}
