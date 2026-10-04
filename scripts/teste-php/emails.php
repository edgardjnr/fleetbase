<?php

// E-mails em pt-BR: CodigosPorEmail, EmailsEmPortugues, CanalEmailEntregas e o assunto dos Mailables.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/emails.php

require __DIR__ . '/stubs.php';
require __DIR__ . '/stubs-emails.php';

// as cópias em packages/ (carregadas pelos testes) têm de ser as versões que a produção instala (api/composer.lock)
function confereVersaoDoCore(): void
{
    $local    = json_decode(file_get_contents('/repo/packages/core-api/composer.json'), true)['version'] ?? null;
    $producao = null;
    foreach (json_decode(file_get_contents('/repo/api/composer.lock'), true)['packages'] ?? [] as $pacote) {
        if (($pacote['name'] ?? null) === 'fleetbase/core-api') {
            $producao = ltrim((string) $pacote['version'], 'v');
        }
    }
    confere($local !== null && $local === $producao, "core-api local ({$local}) é a versão da produção ({$producao})");
}

echo '== Versões' . PHP_EOL;
confereVersaoDoCore();
confereVersaoDoFleetOps();

use App\Notifications\Entregas\Email\CodigosPorEmail;
use App\Notifications\Entregas\Email\Saudacao;

echo PHP_EOL . '== Saudação' . PHP_EOL;
confere(Saudacao::para('Ana') === 'Olá, Ana!', 'saudação com nome');
confere(Saudacao::para('  ') === 'Olá!', 'saudação sem nome (só espaços)');
confere(Saudacao::para(null) === 'Olá!', 'saudação sem nome (null)');

echo PHP_EOL . '== Códigos por e-mail' . PHP_EOL;
$codigos = [
    'email_verification'    => ['123456 é o seu código de verificação', 'Confirme seu e-mail', 'Olá, Ana! Use o código abaixo para confirmar seu e-mail no Entregas RestaurantePro.', 'O código vale por 1 hora. Se não foi você, ignore este e-mail.'],
    '2fa'                   => ['123456 é o seu código de acesso', 'Seu código de acesso', 'Olá, Ana! Para concluir a entrada na sua conta, digite este código:', 'Se você não tentou entrar agora, troque sua senha.'],
    'driver_login'          => ['123456 é o seu código para entrar no app', 'Entrar no app de entregas', 'Olá, Ana! Digite este código no app para entrar:', 'Se não foi você, ignore este e-mail.'],
    'driver_password_reset' => ['123456 é o seu código para redefinir a senha', 'Redefinir a senha do app', 'Olá, Ana! Use este código no app para criar uma nova senha:', 'Se não foi você, ignore este e-mail.'],
    'tipo_inventado'        => ['123456 é o seu código', 'Seu código', 'Olá, Ana! Use este código para continuar:', 'Se não foi você, ignore este e-mail.'],
];
foreach ($codigos as $tipo => [$assunto, $titulo, $texto, $observacao]) {
    $t = CodigosPorEmail::textos($tipo, 'Ana');
    confere(CodigosPorEmail::assunto($tipo, '123456') === $assunto, "assunto de {$tipo}");
    confere($t['titulo'] === $titulo && $t['texto'] === $texto && $t['observacao'] === $observacao, "título, texto e observação de {$tipo}");
}
confere(CodigosPorEmail::conhece('2fa') && !CodigosPorEmail::conhece('tipo_inventado') && !CodigosPorEmail::conhece(null), 'conhece só os tipos da tabela');
confere(CodigosPorEmail::textos('2fa', null)['texto'] === 'Olá! Para concluir a entrada na sua conta, digite este código:', 'texto sem nome');

resumo();
