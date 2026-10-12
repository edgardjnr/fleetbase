<?php

// Integração: a URL dos avisos só pode ser https pública (sem sondar a VPS pela rede interna).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-webhook-url.php

require __DIR__ . '/stubs-integracao.php';

use App\Support\Entregas\Integracao\UrlDoWebhook;

$publico = resolverPublico();

echo '== Aceitas' . PHP_EOL;
confere(UrlDoWebhook::recusa('https://api.restaurantepro.com.br/functions/v1/entregas-webhook?t=abc', $publico) === null, 'https público');
confere(UrlDoWebhook::recusa('https://api.exemplo.com.br:443/x', $publico) === null, 'porta 443 explícita');
confere(UrlDoWebhook::recusa('https://200.150.10.20/x', $publico) === null, 'IP público direto');
foreach (['https://cafe.be/x', 'https://dead.beef.cc/x', 'https://xn--aa-zia.com.br/x', 'https://1.2.3.4.nip.io/x', 'https://123.com.br/x'] as $u) {
    confere(UrlDoWebhook::recusa($u, $publico) === null, "aceita: {$u}");
}

echo '== Recusadas' . PHP_EOL;
foreach ([
    'http://api.restaurantepro.com.br/x'          => 'http',
    'ftp://api.exemplo.com.br/x'                  => 'outro esquema',
    'não é url'                                   => 'texto',
    'https://localhost/x'                         => 'localhost',
    'https://algo.localhost/x'                    => '*.localhost',
    'https://127.0.0.1/x'                         => 'loopback',
    'https://0.0.0.0/x'                           => '0.0.0.0',
    'https://10.0.0.5/x'                          => '10.x',
    'https://172.16.3.4/x'                        => '172.16.x',
    'https://192.168.0.10/x'                      => '192.168.x',
    'https://169.254.169.254/latest/meta-data'    => 'metadados da nuvem',
    'https://[::1]/x'                             => 'IPv6 loopback',
    'https://[fd00::1]/x'                         => 'IPv6 privado',
    'https://entregas_application/x'              => 'nome do Swarm, sem ponto',
    'https://usuario:senha@api.exemplo.com.br/x'  => 'com usuário e senha',
    'https://api.exemplo.com.br:8443/x'           => 'porta fora da 443',
    'https://[::ffff:127.0.0.1]/x' => 'IPv4 embutido em IPv6 (loopback)',
    'https://[::ffff:10.0.0.1]/x' => 'IPv4 embutido em IPv6 (privado)',
    'https://[::ffff:169.254.169.254]/x' => 'IPv4 embutido em IPv6 (metadados)',
    'https://[2001:4860:4860::8888]/x' => 'IPv6 literal público',
    'https://100.64.1.1/x' => 'CGNAT',
    'https://224.0.0.1/x' => 'multicast',
    'https://localhost./x' => 'localhost com ponto final',
    'https://entregas_application./x' => 'nome do Swarm com ponto final',
    'https://0177.0.0.1/x' => 'IPv4 octal',
    'https://127.1/x' => 'IPv4 abreviado',
    'https://%31%32%37.0.0.1.evil.com/x' => 'host com percent-encoding',
    'https://açaí.com.br/x' => 'acento sem punycode',
    'https://a_b.exemplo.com.br/x' => 'sublinhado',
    'https://-x.com.br/x' => 'rótulo começa com hífen',
    'https://a..b.com.br/x' => 'rótulo vazio',
    'https://0x7f000001/x' => 'IPv4 hexa inteiro',
    'https://2130706433/x' => 'IPv4 decimal inteiro',
] as $url => $caso) {
    confere(UrlDoWebhook::recusa($url, $publico) !== null, "recusa: {$caso}");
}
confere(UrlDoWebhook::recusa('https://interno.exemplo.com.br/x', fn ($host) => ['10.0.0.7']) !== null, 'nome que resolve para IP privado');
confere(UrlDoWebhook::recusa('https://misto.exemplo.com.br/x', fn ($host) => ['200.150.10.20', '192.168.1.1']) !== null, 'nome com um dos IPs privado');
confere(UrlDoWebhook::recusa('https://sumiu.exemplo.com.br/x', fn ($host) => []) !== null, 'nome que não resolve');
confere(UrlDoWebhook::recusa('https://so6.exemplo.com.br/x', fn ($h) => ['2001:db8::1']) !== null, 'nome que resolve só para IPv6');
$ok = UrlDoWebhook::conferir('https://API.Exemplo.com.br/x', $publico);
confere($ok === ['motivo' => null, 'host' => 'api.exemplo.com.br', 'ip' => '200.150.10.20'], 'conferir: host limpo e IP conferido');
$pontoFinal = 'endereço inválido (sem ponto no fim do domínio)';
foreach (['https://api.exemplo.com.br./x', 'https://API.Exemplo.com.br./x', 'https://localhost./x', 'https://entregas_application./x'] as $u) {
    confere(UrlDoWebhook::conferir($u, $publico) === ['motivo' => $pontoFinal, 'host' => null, 'ip' => null], "ponto no fim do domínio: {$u}");
}
confere(UrlDoWebhook::conferir('https://localhost/x', $publico)['ip'] === null, 'conferir: recusa sem IP');
confere(UrlDoWebhook::recusa('https://' . str_repeat('a', 500) . '.com.br/x', $publico) !== null, 'URL longa demais');

resumo();
