<?php

namespace App\Notifications\Entregas;

use Fleetbase\Support\PushNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;

/**
 * Entregas RestaurantePro: a central trocou a chave Pix do motoboy (FinanceiroController@salvarPix). O saque fica bloqueado
 * por 24 h (Saque::CARENCIA_PIX_HORAS) e o aviso deixa o motoboy reclamar se não foi ele quem pediu.
 */
class PixDeSaqueAlterado extends Notification implements ShouldQueue
{
    use Queueable;

    public const TIPO = 'entregas_pix_alterado';

    public string $title;

    public string $message;

    public array $data;

    /** @param string $liberadoEm "28/10 às 10:00" (no fuso da empresa) */
    public function __construct(public string $liberadoEm)
    {
        $this->title   = 'Chave Pix alterada';
        $this->message = 'A central alterou a chave Pix dos seus saques. Novos saques a partir de ' . $liberadoEm . '. Se você não pediu a troca, fale com a central.';
        $this->data    = ['type' => self::TIPO];
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
