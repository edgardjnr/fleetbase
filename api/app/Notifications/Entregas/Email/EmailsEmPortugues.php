<?php

namespace App\Notifications\Entregas\Email;

use Fleetbase\Support\Utils;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: assunto e texto em pt-BR das notificações por e-mail dos pacotes (core-api 1.6.61 e
 * fleetops-api 0.6.65), que vêm fixos em inglês. Usado pelo CanalEmailEntregas; textos no desenho
 * docs/superpowers/specs/2026-10-04-emails-em-portugues-design.md.
 *
 * Cada entrada lê só propriedades públicas da notificação, o notifiable e a URL do botão da mensagem original. Se a
 * leitura falhar (o pacote mudou), devolve null, registra "[entregas] falha ao montar o e-mail em pt-BR" e o e-mail sai
 * como veio. Ao atualizar os pacotes, confira as classes de ENTRADAS e as propriedades lidas em cada método.
 */
class EmailsEmPortugues
{
    /** classe da notificação => método que monta o e-mail */
    private const ENTRADAS = [
        'Fleetbase\Notifications\UserForgotPassword'           => 'esqueciASenha',
        'Fleetbase\Notifications\UserInvited'                  => 'convite',
        'Fleetbase\Notifications\UserEmailChange'              => 'trocaDeEmail',
        'Fleetbase\Notifications\UserCreated'                  => 'novoUsuario',
        'Fleetbase\Notifications\UserAcceptedCompanyInvite'    => 'conviteAceito',
        'Fleetbase\FleetOps\Notifications\OrderDispatchFailed' => 'falhaNoDespacho',
        'Fleetbase\FleetOps\Notifications\OrderCompleted'      => 'pedidoEntregue',
        'Fleetbase\FleetOps\Notifications\OrderFailed'         => 'pedidoComFalha',
        'Fleetbase\FleetOps\Notifications\OrderCanceled'       => 'pedidoCancelado',
    ];

    /** motivos de falha de despacho do Fleet-Ops (HandleOrderDispatched) */
    private const MOTIVOS_DE_DESPACHO = [
        'No driver assigned for order to dispatch to.'                => 'nenhum motoboy atribuído ao pedido',
        'Order was dispatched, but driver was unable to be notified.' => 'o pedido foi despachado, mas o motoboy não pôde ser avisado',
    ];

    public static function conhece(object $notificacao): bool
    {
        return isset(self::ENTRADAS[get_class($notificacao)]);
    }

    public static function traduzir(object $notificacao, $notifiable, MailMessage $original): ?MailMessage
    {
        $metodo = self::ENTRADAS[get_class($notificacao)] ?? null;
        if ($metodo === null) {
            return null;
        }

        try {
            return self::$metodo($notificacao, $notifiable, $original);
        } catch (\Throwable $erro) {
            Log::warning('[entregas] falha ao montar o e-mail em pt-BR', ['aviso' => get_class($notificacao), 'erro' => $erro->getMessage()]);

            return null;
        }
    }

    private static function esqueciASenha($n, $notifiable, MailMessage $original): MailMessage
    {
        $codigo = $n->verificationCode->code;
        // a loja (usuário customer) redefine a senha no portal, não no console
        $url = ($notifiable->type ?? null) === 'customer'
            ? Utils::consoleUrl('customer-portal/auth/reset-password/' . $n->verificationCode->uuid, ['code' => $codigo])
            : $n->url;

        return (new MailMessage())
            ->subject('Redefina sua senha do Entregas RestaurantePro')
            ->greeting('Redefinir sua senha')
            ->line(Saudacao::para($notifiable->name ?? null) . ' Recebemos um pedido para redefinir a senha da sua conta. Toque no botão para criar uma nova.')
            ->action('Criar nova senha', $url)
            ->line('Se a página pedir, use o código: ' . $codigo)
            ->line('Se não foi você, ignore este e-mail: sua senha continua a mesma.');
    }

    private static function convite($n, $notifiable, MailMessage $original): MailMessage
    {
        $empresa = TextoDoEmail::semLink($n->company->name);
        $quem    = trim(TextoDoEmail::semLink($n->sender->name ?? null));
        $texto   = $quem !== ''
            ? "{$quem} convidou você para a equipe {$empresa} no Entregas RestaurantePro."
            : "Você foi convidado para a equipe {$empresa} no Entregas RestaurantePro.";

        return (new MailMessage())
            ->subject("Você foi convidado para a equipe {$empresa}")
            ->greeting('Você foi convidado!')
            ->line(Saudacao::para($notifiable->name ?? null) . ' ' . $texto)
            ->action('Aceitar convite', $n->url)
            ->line('Código do convite: ' . $n->invite->code);
    }

    private static function trocaDeEmail($n, $notifiable, MailMessage $original): MailMessage
    {
        $codigo  = $n->verificationCode;
        $usuario = $codigo->subject;

        return (new MailMessage())
            ->subject('Confirme seu novo e-mail')
            ->greeting('Confirme seu novo e-mail')
            ->line(Saudacao::para($usuario->name ?? null) . ' Foi pedida a troca do e-mail de login da sua conta.')
            ->line('E-mail atual: ' . (data_get($codigo->meta, 'old_email') ?: '—'))
            ->line('Novo e-mail: ' . (data_get($codigo->meta, 'new_email') ?: '—'))
            ->action('Confirmar troca de e-mail', $n->url)
            ->line('Se o botão não abrir, use o código: ' . $codigo->code)
            ->line('Se não foi você, ignore este e-mail: o e-mail da conta não muda.');
    }

    private static function novoUsuario($n, $notifiable, MailMessage $original): MailMessage
    {
        $nome    = TextoDoEmail::semLink($n->user->name);
        $email   = TextoDoEmail::semLink($n->user->email);
        $empresa = TextoDoEmail::semLink($n->company->name ?? null);

        return (new MailMessage())
            ->subject(trim("Novo usuário na equipe {$empresa}"))
            ->greeting('Novo usuário na equipe')
            ->line(trim("{$nome} ({$email}) entrou na equipe {$empresa}") . '.')
            ->action('Ver usuários', Utils::consoleUrl('iam/users'));
    }

    private static function conviteAceito($n, $notifiable, MailMessage $original): MailMessage
    {
        $nome    = TextoDoEmail::semLink($n->user->name);
        $empresa = TextoDoEmail::semLink($n->company->name);

        return (new MailMessage())
            ->subject("{$nome} aceitou o convite para a equipe {$empresa}")
            ->greeting('Convite aceito')
            ->line("{$nome} agora faz parte da equipe {$empresa}.")
            ->action('Ver a equipe', Utils::consoleUrl('iam/users'));
    }

    private static function falhaNoDespacho($n, $notifiable, MailMessage $original): MailMessage
    {
        $codigo   = self::codigoDoPedido($n->order);
        $loja     = self::nomeDaLoja($n->order);
        $motivoOriginal = trim((string) ($n->reason ?? ''));
        $motivo   = self::MOTIVOS_DE_DESPACHO[$motivoOriginal] ?? null;
        if ($motivo === null && $motivoOriginal !== '') {
            Log::info('[entregas] motivo de falha de despacho sem tradução', ['motivo' => $motivoOriginal]);
        }

        $mensagem = (new MailMessage())
            ->subject("Pedido {$codigo} não foi despachado")
            ->greeting('O pedido não foi despachado')
            ->line($loja !== '' ? "O pedido {$codigo} da loja {$loja} não pôde ser despachado." : "O pedido {$codigo} não pôde ser despachado.")
            ->line('Motivo: ' . ($motivo ?? 'não informado') . '.');

        return self::comBotao($mensagem, 'Acompanhar o pedido', $original->actionUrl);
    }

    private static function pedidoEntregue($n, $notifiable, MailMessage $original): MailMessage
    {
        $codigo = self::codigoDoPedido($n->order);

        return self::comBotao(
            (new MailMessage())->subject("Pedido {$codigo} entregue")->greeting('Pedido entregue')->line("O pedido {$codigo} foi entregue."),
            'Acompanhar o pedido',
            $original->actionUrl
        );
    }

    private static function pedidoComFalha($n, $notifiable, MailMessage $original): MailMessage
    {
        $codigo   = self::codigoDoPedido($n->order);
        $mensagem = (new MailMessage())->subject("Pedido {$codigo} com falha na entrega")->greeting('Falha na entrega')->line("A entrega do pedido {$codigo} falhou.");

        return self::comBotao(self::comMotivo($mensagem, $n->reason ?? null), 'Acompanhar o pedido', $original->actionUrl);
    }

    private static function pedidoCancelado($n, $notifiable, MailMessage $original): MailMessage
    {
        $codigo   = self::codigoDoPedido($n->order);
        $mensagem = (new MailMessage())->subject("Pedido {$codigo} cancelado")->greeting('Pedido cancelado')->line("O pedido {$codigo} foi cancelado.");

        return self::comBotao(self::comMotivo($mensagem, $n->reason ?? null), 'Acompanhar o pedido', $original->actionUrl);
    }

    /** número de rastreio do pedido; sem ele, o public_id */
    private static function codigoDoPedido($pedido): string
    {
        $rastreio = $pedido->trackingNumber->tracking_number ?? $pedido->tracking ?? null;

        return (string) ($rastreio ?: ($pedido->public_id ?? ''));
    }

    /** a loja do pedido é o Vendor cliente (orders.customer_uuid); sem cliente, vazio */
    private static function nomeDaLoja($pedido): string
    {
        return trim(TextoDoEmail::semLink($pedido->customer->name ?? null));
    }

    private static function comMotivo(MailMessage $mensagem, ?string $motivo): MailMessage
    {
        $motivo = trim((string) $motivo);

        return $motivo === '' ? $mensagem : $mensagem->line('Motivo: ' . $motivo);
    }

    private static function comBotao(MailMessage $mensagem, string $texto, ?string $url): MailMessage
    {
        return $url ? $mensagem->action($texto, $url) : $mensagem;
    }
}
