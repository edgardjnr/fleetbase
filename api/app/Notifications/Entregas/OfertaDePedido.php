<?php

namespace App\Notifications\Entregas;

use App\Support\Entregas\Distribuicao\Distribuicao;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\OrderPing;

/**
 * Entregas RestaurantePro: a oferta de um pedido aberto a um motoboy (ver App\Support\Entregas\Distribuicao\Distribuidor).
 *
 * É o OrderPing do Fleet-Ops (`order_ping` no push, `order.ping` no socket: o app trata como pedido novo e o cartão do
 * alarme mostra Aceitar e Recusar), com o título da oferta, os dados `entregas_oferta`/`entregas_oferta_vence_em`/`entregas_oferta_segundos` (segundos que faltam, calculados na hora
 * do envio: o app não depende do relógio do celular) e o
 * TTL do que falta até vencer, no máximo $segundosDaOferta (30 s, com ou sem rodadas) (AvisosDoMotoboy).
 */
class OfertaDePedido extends OrderPing
{
    public function __construct(Order $order, $distance, public \DateTimeInterface $venceEm, public int $segundosDaOferta = Distribuicao::SEGUNDOS_DA_OFERTA)
    {
        parent::__construct($order, $distance);
        $this->data = array_merge($this->data ?? [], $this->dadosDaOferta()); // também no push original de reserva (CanalFcmEntregas)

        $this->title   = 'Oferta para você';
        $this->message = AvisosDoMotoboy::textoDaColeta($distance);
    }

    /** Os dados extras do push (strings, como o FCM exige). */
    public function dadosDaOferta(): array
    {
        return [
            'entregas_oferta'          => '1',
            'entregas_oferta_vence_em' => $this->venceEm->format(DATE_ATOM),
            'entregas_oferta_segundos' => (string) max(0, $this->venceEm->getTimestamp() - now()->getTimestamp()),
        ];
    }
}
