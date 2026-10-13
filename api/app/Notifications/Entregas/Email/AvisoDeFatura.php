<?php

namespace App\Notifications\Entregas\Email;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Entregas RestaurantePro: base dos e-mails de fatura para a loja (FaturaEmitida, LembreteDeFatura, FaturaVencida). Já
 * saem em pt-BR (o CanalEmailEntregas não os traduz nem registra "sem tradução"), no layout dos e-mails
 * (vendor/notifications/email.blade.php). Enviados pelo App\Support\Entregas\Financeiro\AvisosDaFatura com
 * Notification::route('mail', [...]) ao e-mail da loja e dos usuários do portal dela.
 *
 * $fatura: numero ('FAT-0031'), loja, empresa (nomes; podem faltar), periodo_inicio, periodo_fim, vencimento ('Y-m-d'),
 * total, em_aberto (reais) e url (a fatura no portal).
 */
abstract class AvisoDeFatura extends Notification
{
    public function __construct(public array $fatura) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    abstract public function toMail($notifiable): MailMessage;

    /** 1234.5 → 'R$ 1.234,50'. */
    public static function reais($valor): string
    {
        return 'R$ ' . number_format((float) $valor, 2, ',', '.');
    }

    /** '2026-10-08' → '08/10/2026'; texto que não é data volta como veio. */
    public static function data(?string $dia): string
    {
        $dia = substr((string) $dia, 0, 10);

        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dia, $partes) ? "{$partes[3]}/{$partes[2]}/{$partes[1]}" : $dia;
    }

    protected function numero(): string
    {
        return (string) ($this->fatura['numero'] ?? '');
    }

    protected function periodo(): string
    {
        return static::data($this->fatura['periodo_inicio'] ?? null) . ' a ' . static::data($this->fatura['periodo_fim'] ?? null);
    }

    protected function saudacao(): string
    {
        return Saudacao::para($this->fatura['loja'] ?? null);
    }

    /** O nome da empresa (operadora) para o texto, sem virar link; sem nome, "a central". */
    protected function empresa(): string
    {
        $nome = trim(TextoDoEmail::semLink($this->fatura['empresa'] ?? null));

        return $nome === '' ? 'a central' : $nome;
    }

    protected function comBotao(MailMessage $mensagem): MailMessage
    {
        $url = (string) ($this->fatura['url'] ?? '');

        return $url === '' ? $mensagem : $mensagem->action('Ver a fatura', $url);
    }
}
