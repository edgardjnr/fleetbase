<?php

namespace App\Notifications\Entregas\Email;

use Illuminate\Notifications\Messages\MailMessage;

/** Entregas RestaurantePro: lembrete à loja na véspera do vencimento, com valor em aberto (entregas:financeiro-avisos). */
class LembreteDeFatura extends AvisoDeFatura
{
    public function toMail($notifiable): MailMessage
    {
        $mensagem = (new MailMessage())
            ->subject("Fatura {$this->numero()} vence amanhã")
            ->greeting('A fatura vence amanhã')
            ->line("{$this->saudacao()} A fatura {$this->numero()}, das entregas de {$this->periodo()}, vence amanhã, " . static::data($this->fatura['vencimento'] ?? null) . '.')
            ->line('Em aberto: ' . static::reais($this->fatura['em_aberto'] ?? 0));

        return $this->comBotao($mensagem)
            ->line('Se você já pagou, desconsidere este aviso.')
            ->line("Dúvidas sobre a fatura? Fale com {$this->empresa()}.");
    }
}
