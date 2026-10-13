<?php

namespace App\Notifications\Entregas\Email;

use Illuminate\Notifications\Messages\MailMessage;

/** Entregas RestaurantePro: e-mail à loja quando a fatura é emitida (AvisosDaFatura::emitida; reenviar pelo console). */
class FaturaEmitida extends AvisoDeFatura
{
    public function toMail($notifiable): MailMessage
    {
        $mensagem = (new MailMessage())
            ->subject("Fatura {$this->numero()} emitida")
            ->greeting('Fatura emitida')
            ->line("{$this->saudacao()} A fatura {$this->numero()}, das entregas de {$this->periodo()}, foi emitida.")
            ->line('Valor: ' . static::reais($this->fatura['total'] ?? 0))
            ->line('Vencimento: ' . static::data($this->fatura['vencimento'] ?? null));

        return $this->comBotao($mensagem)->line("Dúvidas sobre a fatura? Fale com {$this->empresa()}.");
    }
}
