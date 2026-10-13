<?php

namespace App\Notifications\Entregas;

use Fleetbase\Support\PushNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;

/**
 * Entregas RestaurantePro: aviso ao motoboy de que a central registrou um pagamento a ele (Financeiro, console →
 * Motoboys → Registrar pagamento). Só push (sai pelo CanalFcmEntregas, no canal "avisos" do app; o AvisosDoMotoboy
 * conhece a classe). "Pagamento de R$ 150,00 registrado (Pix)."
 *
 * Os dados levam o uuid do pagamento (`pagamento`) e não `id`: o app abre o recibo (tela Recibo) pelo tipo (TIPO); com
 * `id` "order_…" ele abriria a tela de pedido. Guarda só textos e números: a notificação vai para a fila.
 */
class PagamentoRegistrado extends Notification implements ShouldQueue
{
    use Queueable;

    public const TIPO = 'entregas_pagamento';

    /** Como cada forma aparece no texto (a forma "outro" sai sem os parênteses). */
    public const FORMAS = ['pix' => 'Pix', 'dinheiro' => 'dinheiro', 'transferencia' => 'transferência'];

    public string $title;

    public string $message;

    public array $data;

    public function __construct(public string $pagamento, public float $valor, public string $forma)
    {
        $forma         = static::FORMAS[$forma] ?? null;
        $this->title   = 'Pagamento registrado';
        $this->message = 'Pagamento de ' . static::reais($valor) . ' registrado' . ($forma ? ' (' . $forma . ')' : '') . '.';
        $this->data    = ['type' => self::TIPO, 'pagamento' => $pagamento];
    }

    /** 1234.5 → "R$ 1.234,50". */
    public static function reais(float $valor): string
    {
        return 'R$ ' . number_format($valor, 2, ',', '.');
    }

    public function via($notifiable)
    {
        return [FcmChannel::class];
    }

    public function toFcm($notifiable)
    {
        return PushNotification::createFcmMessage($this->title, $this->message, $this->data);
    }
}
