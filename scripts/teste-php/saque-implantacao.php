<?php

// Saque Pix: o stack (ENTREGAS_SAQUE, ASAAS_API_URL e o Docker secret na API e no agendador) e o modelo do stack.env.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-implantacao.php

require __DIR__ . '/stubs-ifood.php';

$stack  = file_get_contents('/repo/deploy/docker-stack.yml');
$modelo = file_get_contents('/repo/deploy/stack.env.example');
$readme = file_get_contents('/repo/deploy/README.md');

confere(str_contains($stack, 'ENTREGAS_SAQUE: ${ENTREGAS_SAQUE:-}'), 'ENTREGAS_SAQUE no x-api-env');
confere(str_contains($stack, 'ASAAS_API_URL: ${ASAAS_API_URL:-https://api.asaas.com/v3}'), 'ASAAS_API_URL com o padrão de produção');
confere(preg_match('/^secrets:\r?\n  entregas_saque_chave:\r?\n    external: true/m', $stack) === 1, 'secret externo entregas_saque_chave no fim do stack');

/** O bloco de um serviço (do nome até o próximo serviço de 2 espaços). */
function servico(string $stack, string $nome): string
{
    preg_match('/^  ' . preg_quote($nome, '/') . ':\r?\n(.*?)(?=^  [a-z][a-z-]*:\r?\n|^[a-z])/ms', $stack, $m);

    return $m[1] ?? '';
}

confere(str_contains(servico($stack, 'application'), 'secrets: [entregas_saque_chave]'), 'a API recebe o secret');
confere(str_contains(servico($stack, 'scheduler'), 'secrets: [entregas_saque_chave]'), 'o agendador recebe o secret (rotina)');
confere(!str_contains(servico($stack, 'queue'), 'entregas_saque_chave') && !str_contains(servico($stack, 'console'), 'entregas_saque_chave'), 'fila e console não precisam do secret');
confere(preg_match('/^ENTREGAS_SAQUE=\r?$/m', $modelo) === 1 && preg_match('/^ASAAS_API_URL=\r?$/m', $modelo) === 1, 'modelo do stack.env');
confere(str_contains($readme, 'openssl rand -base64 32 | docker secret create entregas_saque_chave -'), 'README: como criar o secret');

resumo();
