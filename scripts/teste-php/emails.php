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

resumo();
