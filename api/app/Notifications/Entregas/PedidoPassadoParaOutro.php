<?php

namespace App\Notifications\Entregas;

use Fleetbase\Support\PushNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;

/**
 * Entregas RestaurantePro: aviso ao motoboy de que o líder dos motoboys passou o pedido dele para outro motoboy
 * (TrocaDoMotoboy). Só push (sai pelo CanalFcmEntregas, no canal "avisos" do app; o AvisosDoMotoboy conhece a classe).
 *
 * Os dados não levam `id`: o app abre a tela do pedido (e toca o alarme) em todo push com id "order_…", e este pedido já
 * não é dele. O app novo recarrega a lista de pedidos pelo tipo (TIPO); o APK antigo só mostra a notificação.
 * Guarda só textos (número e public_id), sem o Order: a notificação vai para a fila.
 */
class PedidoPassadoParaOutro extends Notification implements ShouldQueue
{
    use Queueable;

    public const TIPO = 'entregas_pedido_trocado';

    public string $title;

    public string $message;

    public array $data;

    public function __construct(public string $numero, public string $pedido)
    {
        $this->title   = 'Pedido passado para outro motoboy';
        $this->message = 'Pedido ' . static::rotulo($numero) . ' passou para outro motoboy.';
        $this->data    = ['type' => self::TIPO, 'pedido' => $pedido];
    }

    /** "#4821" para o número do iFood (só dígitos); o código de rastreio fica como está. */
    public static function rotulo(string $numero): string
    {
        return ctype_digit($numero) ? '#' . $numero : $numero;
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
