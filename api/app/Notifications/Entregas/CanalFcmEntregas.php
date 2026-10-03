<?php

namespace App\Notifications\Entregas;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Messaging\MulticastSendReport;
use NotificationChannels\Fcm\FcmChannel;

/**
 * Entregas RestaurantePro: canal de push (FCM) de todos os avisos, no lugar do FcmChannel do pacote (a troca fica no
 * AppServiceProvider). Envia como o pacote (laravel-notification-channels/fcm 4.5.0), mas antes passa a mensagem pelo
 * AvisosDoMotoboy (pt-BR, canal e formato). Se a adaptação falhar, envia a mensagem original: nenhum aviso se perde.
 *
 * Ao atualizar o pacote FCM, confira se o send() do FcmChannel mudou.
 */
class CanalFcmEntregas extends FcmChannel
{
    public function send(mixed $notifiable, Notification $notification): ?Collection
    {
        $tokens = Arr::wrap($notifiable->routeNotificationFor('fcm', $notification));

        if (empty($tokens)) {
            return null;
        }

        $original = $notification->toFcm($notifiable);

        try {
            $mensagem = AvisosDoMotoboy::adaptar($notification, clone $original);
        } catch (\Throwable $erro) {
            Log::error('[entregas] aviso push sem adaptação', ['notificacao' => get_class($notification), 'erro' => $erro->getMessage()]);
            $mensagem = $original;
        }

        return Collection::make($tokens)
            ->chunk(self::TOKENS_PER_REQUEST)
            ->map(fn ($tokens) => ($mensagem->client ?? $this->client)->sendMulticast($mensagem, $tokens->all()))
            ->map(fn (MulticastSendReport $report) => $this->checkReportForFailures($notifiable, $notification, $report));
    }
}
