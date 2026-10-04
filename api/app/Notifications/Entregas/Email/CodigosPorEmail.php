<?php

namespace App\Notifications\Entregas\Email;

/**
 * Entregas RestaurantePro: textos em pt-BR dos e-mails de código (VerificationMail do core-api), por tipo de código
 * (`VerificationCode::for`). Usado pela view vendor/fleetbase/mail/verification.blade.php (título, texto, observação) e
 * pelo AssuntoDosEmailsEmPortugues (assunto). Tipo fora da tabela usa o texto genérico.
 */
class CodigosPorEmail
{
    /** tipo => [assunto (%s = código), título, frase depois da saudação, observação] */
    private const TEXTOS = [
        'email_verification'    => ['%s é o seu código de verificação', 'Confirme seu e-mail', 'Use o código abaixo para confirmar seu e-mail no Entregas RestaurantePro.', 'O código vale por 1 hora. Se não foi você, ignore este e-mail.'],
        '2fa'                   => ['%s é o seu código de acesso', 'Seu código de acesso', 'Para concluir a entrada na sua conta, digite este código:', 'Se você não tentou entrar agora, troque sua senha.'],
        'driver_login'          => ['%s é o seu código para entrar no app', 'Entrar no app de entregas', 'Digite este código no app para entrar:', 'Se não foi você, ignore este e-mail.'],
        'driver_password_reset' => ['%s é o seu código para redefinir a senha', 'Redefinir a senha do app', 'Use este código no app para criar uma nova senha:', 'Se não foi você, ignore este e-mail.'],
    ];

    private const PADRAO = ['%s é o seu código', 'Seu código', 'Use este código para continuar:', 'Se não foi você, ignore este e-mail.'];

    public static function conhece(?string $tipo): bool
    {
        return $tipo !== null && isset(self::TEXTOS[$tipo]);
    }

    public static function assunto(?string $tipo, string $codigo): string
    {
        return sprintf(self::linha($tipo)[0], $codigo);
    }

    /** @return array{titulo: string, texto: string, observacao: string} */
    public static function textos(?string $tipo, ?string $nome): array
    {
        [, $titulo, $frase, $observacao] = self::linha($tipo);

        return ['titulo' => $titulo, 'texto' => Saudacao::para($nome) . ' ' . $frase, 'observacao' => $observacao];
    }

    private static function linha(?string $tipo): array
    {
        return self::conhece($tipo) ? self::TEXTOS[$tipo] : self::PADRAO;
    }
}
