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
 * dispara NotificationFailed, que não tem ouvinte aqui. Por isso o canal registra no log cada push recusado (com o
 * motoboy; token que não existe mais, o 404, vai como info) e, se o FCM recusar o formato adaptado como inválido, envia
 * o push original (o do Fleet-Ops, que funcionava antes) aos tokens recusados, menos os de token malformado.
 *
 * Ao atualizar o pacote FCM (laravel-notification-channels/fcm) ou o kreait/firebase-php, confira: o send() do FcmChannel
 * (este repete o dele), o checkReportForFailures() (este o sobrescreve) e, no SendReport do kreait, messageWasInvalid(),
 * messageTargetWasInvalid() e messageWasSentToUnknownToken(), que este canal usa.
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
            Log::warning('[entregas] push adaptado recusado pelo FCM; enviado o original', ['notificacao' => get_class($notification), 'motoboy' => $notifiable->public_id ?? null, 'tokens' => count($recusados)]);
            $relatorios = $relatorios->merge($this->enviar($original, $recusados));
        }

        return $relatorios->map(fn (MulticastSendReport $relatorio) => $this->checkReportForFailures($notifiable, $notification, $relatorio));
    }

    /** Registra no log cada push recusado pelo FCM (o pacote só dispara NotificationFailed, que não tem ouvinte aqui). */
    protected function checkReportForFailures(mixed $notifiable, Notification $notification, MulticastSendReport $report): MulticastSendReport
    {
        foreach ($report->getItems() as $item) {
            if (!$item->isFailure()) {
                continue;
            }

            $contexto = [
                'notificacao'       => get_class($notification),
                'motoboy'           => $notifiable->public_id ?? null,
                'mensagem_invalida' => $item->messageWasInvalid(),
                'erro'              => $item->error()?->getMessage(),
            ];

            // token que não existe mais (404): o motoboy trocou de celular ou reinstalou o app, e ninguém limpa os tokens velhos
            if ($item->messageWasSentToUnknownToken()) {
                Log::info('[entregas] push recusado pelo FCM', $contexto);
            } else {
                Log::warning('[entregas] push recusado pelo FCM', $contexto);
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

    /** Tokens para os quais o FCM recusou a mensagem (400), menos os de token malformado: o push original não os alcançaria. */
    private function tokensComMensagemInvalida(Collection $relatorios): array
    {
        $tokens = [];
        foreach ($relatorios as $relatorio) {
            foreach ($relatorio->getItems() as $item) {
                // messageTargetWasInvalid() é a heurística do kreait sobre o texto do erro: se errar, no pior caso é uma chamada a mais, ou nenhum reenvio, e a recusa segue no log
                if ($item->isFailure() && $item->messageWasInvalid() && !$item->messageTargetWasInvalid()) {
                    $tokens[] = $item->target()->value();
                }
            }
        }

        return $tokens;
    }
}
