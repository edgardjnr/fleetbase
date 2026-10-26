<?php

namespace App\Notifications\Entregas;

use Fleetbase\Support\PushNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;

/**
 * Entregas RestaurantePro: o saque Pix do motoboy falhou e o valor (e a taxa) voltou ao saldo (Saques::falhar). Só push,
 * canal "avisos". O motivo vem do Asaas ou do Entregas (até 120 caracteres no texto).
 */
class SaqueNaoConcluido extends Notification implements ShouldQueue
{
    use Queueable;

    public const TIPO = 'entregas_saque';

    public string $title;

    public string $message;

    public array $data;

    public function __construct(public string $saque, public float $valor, public string $motivo)
    {
        $this->title   = 'Saque não concluído';
        $this->message = 'O saque de ' . PagamentoRegistrado::reais($valor) . ' não foi concluído e o valor voltou para o seu saldo. Motivo: ' . mb_substr(trim($motivo), 0, 120);
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
