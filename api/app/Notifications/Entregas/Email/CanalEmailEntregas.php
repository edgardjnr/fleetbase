<?php

namespace App\Notifications\Entregas\Email;

use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: canal de e-mail no lugar do MailChannel do Laravel (bind no AppServiceProvider; o
 * ChannelManager o resolve pelo container). Vale para as notificações do core-api, do Fleet-Ops e do portal.
 *
 * - Motoboy não recebe e-mail: Driver (pedido atribuído, despachado, cancelado...) e o convite de organização para
 *   usuário do tipo driver. Ele já recebe push e entra pelo app; o código de login por e-mail é Mailable, não passa aqui.
 * - Notificação conhecida sai com assunto e texto em pt-BR (EmailsEmPortugues); desconhecida sai como veio, já no
 *   layout novo (views em api/resources/views/vendor), e aparece no log como "[entregas] e-mail sem tradução".
 *
 * O send() repete o do MailChannel do Laravel 10 (commit 74e222ce) e só troca a MailMessage. Ao atualizar o Laravel,
 * confira o send() do pai e os métodos usados (buildView, additionalMessageData, messageBuilder). Ao atualizar o
 * core-api ou o fleetops-api, confira também as views sobrescritas (fleetbase::layout.mail, fleetbase::mail.verification,
 * fleetbase::mail.user-credentials, fleetbase::mail.test), o alias mail-layout, as variáveis delas (code, type, user,
 * plaintextPassword, mailer, currentHour) e as classes e propriedades do EmailsEmPortugues.
 */
class CanalEmailEntregas extends MailChannel
{
    /** configuração de envio da mensagem original que passa para a traduzida */
    private const ENVIO = ['mailer', 'from', 'replyTo', 'cc', 'bcc', 'attachments', 'rawAttachments', 'tags', 'metadata', 'priority', 'callbacks', 'theme', 'level'];

    public function send($notifiable, Notification $notification)
    {
        if (self::paraMotoboy($notifiable, $notification)) {
            Log::info('[entregas] e-mail ao motoboy não enviado', ['aviso' => get_class($notification), 'motoboy' => $notifiable->public_id ?? null]);

            return null;
        }

        $message = $notification->toMail($notifiable);

        if (!$notifiable->routeNotificationFor('mail', $notification) && !$message instanceof Mailable) {
            return null;
        }

        if ($message instanceof Mailable) {
            return $message->send($this->mailer);
        }

        $message = $this->emPortugues($notifiable, $notification, $message);

        return $this->mailer->mailer($message->mailer ?? null)->send(
            $this->buildView($message),
            array_merge($message->data(), $this->additionalMessageData($notification)),
            $this->messageBuilder($notifiable, $notification, $message)
        );
    }

    public static function paraMotoboy($notifiable, $notification): bool
    {
        if ($notifiable instanceof \Fleetbase\FleetOps\Models\Driver) {
            return true;
        }

        return get_class($notification) === 'Fleetbase\Notifications\UserInvited' && ($notifiable->type ?? null) === 'driver';
    }

    /** copia remetente, mailer, anexos, etiquetas etc. da original para a traduzida */
    public static function copiarEnvio(MailMessage $original, MailMessage $traduzida): MailMessage
    {
        foreach (self::ENVIO as $propriedade) {
            $traduzida->{$propriedade} = $original->{$propriedade};
        }

        return $traduzida;
    }

    private function emPortugues($notifiable, Notification $notification, $original)
    {
        if (!$original instanceof MailMessage) {
            return $original;
        }

        if (!EmailsEmPortugues::conhece($notification)) {
            Log::warning('[entregas] e-mail sem tradução', ['aviso' => get_class($notification)]);

            return $original;
        }

        // falha ao montar: EmailsEmPortugues já registrou o motivo; sai a original
        $traduzida = EmailsEmPortugues::traduzir($notification, $notifiable, $original);

        return $traduzida === null ? $original : self::copiarEnvio($original, $traduzida);
    }
}
