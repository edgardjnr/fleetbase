<?php

namespace App\Notifications\Entregas;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Messaging\MulticastSendReport;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;

/**
 * Entregas RestaurantePro: canal de push (FCM) de todos os avisos, no lugar do FcmChannel do pacote (a troca fica no
 * AppServiceProvider). Envia como o pacote (laravel-notification-channels/fcm 4.5.0), mas antes passa a mensagem pelo
 * AvisosDoMotoboy (pt-BR, canal e formato). Se a adaptação falhar, envia a mensagem original: nenhum aviso se perde.
 *
 * O kreait não valida a mensagem localmente: uma recusa do FCM vem no relatório de envio (sem exceção) e o pacote só
 * dispara NotificationFailed, que não tem ouvinte aqui. Por isso o canal registra no log cada push recusado e, se o FCM
 * recusar o formato adaptado como inválido, envia o push original (o do Fleet-Ops, que funcionava antes) aos tokens
 * recusados.
 *
 * Ao atualizar o pacote FCM, confira se o send() do FcmChannel mudou: este repete o dele.
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
            Log::error('[entregas] aviso push sem adaptação', ['notificacao' => get_class($notification), 'exception' => $erro]);
            $mensagem = $original;
        }

        $relatorios = $this->enviar($mensagem, $tokens);

        // o FCM recusou o formato adaptado (mensagem inválida): esses motoboys recebem o push original, como antes
        $recusados = $mensagem === $original ? [] : $this->tokensComMensagemInvalida($relatorios);
        if ($recusados) {
            Log::warning('[entregas] push adaptado recusado pelo FCM; enviado o original', ['notificacao' => get_class($notification), 'tokens' => count($recusados)]);
            $relatorios = $relatorios->merge($this->enviar($original, $recusados));
        }

        return $relatorios->map(fn (MulticastSendReport $relatorio) => $this->checkReportForFailures($notifiable, $notification, $relatorio));
    }

    /** Registra no log cada push recusado pelo FCM (o pacote só dispara NotificationFailed, que não tem ouvinte aqui). */
    protected function checkReportForFailures(mixed $notifiable, Notification $notification, MulticastSendReport $report): MulticastSendReport
    {
        foreach ($report->getItems() as $item) {
            if ($item->isFailure()) {
                Log::warning('[entregas] push recusado pelo FCM', [
                    'notificacao'       => get_class($notification),
                    'mensagem_invalida' => $item->messageWasInvalid(),
                    'erro'              => $item->error()?->getMessage(),
                ]);
            }
        }

        return parent::checkReportForFailures($notifiable, $notification, $report);
    }

    private function enviar(FcmMessage $mensagem, array $tokens): Collection
    {
        return Collection::make($tokens)
            ->chunk(self::TOKENS_PER_REQUEST)
            ->map(fn ($lote) => ($mensagem->client ?? $this->client)->sendMulticast($mensagem, $lote->all()));
    }

    private function tokensComMensagemInvalida(Collection $relatorios): array
    {
        $tokens = [];
        foreach ($relatorios as $relatorio) {
            foreach ($relatorio->getItems() as $item) {
                if ($item->isFailure() && $item->messageWasInvalid()) {
                    $tokens[] = $item->target()->value();
                }
            }
        }

        return $tokens;
    }
}
