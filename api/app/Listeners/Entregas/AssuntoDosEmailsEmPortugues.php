<?php

namespace App\Listeners\Entregas;

use App\Notifications\Entregas\Email\CodigosPorEmail;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: assunto em pt-BR dos Mailables do core-api (VerificationMail, UserCredentialsMail e TestMail),
 * que fixam o assunto em inglês no PHP. O corpo deles vem das views sobrescritas (vendor/fleetbase/mail). As
 * notificações não passam aqui: já saem em pt-BR pelo CanalEmailEntregas (têm __laravel_notification nos dados).
 *
 * O Mailable é reconhecido pelos dados da view (Mailable::buildViewData):
 * - VerificationMail: code e type;
 * - UserCredentialsMail: plaintextPassword;
 * - TestMail: mailSubject (propriedade pública).
 * Nunca devolve false: o Mailer usa events->until() e false cancelaria o envio.
 */
class AssuntoDosEmailsEmPortugues
{
    public function handle(MessageSending $evento)
    {
        $dados = $evento->data;
        if (isset($dados['__laravel_notification'])) {
            return null;
        }

        try {
            $assunto = $this->assunto($dados);
            if ($assunto !== null) {
                $evento->message->subject($assunto);
            }
        } catch (\Throwable $erro) {
            Log::warning('[entregas] falha ao traduzir o assunto do e-mail', ['erro' => $erro->getMessage()]);
        }

        return null;
    }

    private function assunto(array $dados): ?string
    {
        if (isset($dados['code'], $dados['type'])) {
            if (!CodigosPorEmail::conhece($dados['type'])) {
                Log::warning('[entregas] e-mail sem tradução', ['aviso' => 'VerificationMail', 'tipo' => $dados['type']]);
            }

            return CodigosPorEmail::assunto($dados['type'], (string) $dados['code']);
        }

        // o CustomerCredentialsMail do Fleet-Ops (acesso ao portal de um contato) também traz plaintextPassword, mas tem
        // customer e corpo próprio (em inglês): fica com o assunto original
        if (array_key_exists('plaintextPassword', $dados) && !array_key_exists('customer', $dados)) {
            return 'Seus dados de acesso ao Entregas RestaurantePro';
        }

        if (isset($dados['mailSubject'])) {
            return 'Teste de e-mail do Entregas RestaurantePro';
        }

        return null;
    }
}
