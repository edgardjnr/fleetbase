<?php

namespace App\Notifications\Entregas;

use Fleetbase\Support\PushNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;

/**
 * Entregas RestaurantePro: o saque Pix do motoboy caiu (o Asaas respondeu DONE). Só push, canal "avisos" do app
 * (AvisosDoMotoboy). Os dados levam o uuid do saque (`saque`), não `id`: o app abre a carteira pelo tipo (TIPO).
 */
class SaqueConcluido extends Notification implements ShouldQueue
{
    use Queueable;

    public const TIPO = 'entregas_saque';

    public string $title;

    public string $message;

    public array $data;

    public function __construct(public string $saque, public float $valor)
    {
        $this->title   = 'Saque concluído';
        $this->message = 'Seu saque de ' . PagamentoRegistrado::reais($valor) . ' caiu no Pix.';
        $this->data    = ['type' => self::TIPO, 'saque' => $saque];
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
