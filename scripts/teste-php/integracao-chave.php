<?php

// Integração: a chave da loja (gerar, achar, revogar), a conexão do webhook, o segredo e as marcas de uso e de falha.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-chave.php

require __DIR__ . '/stubs-integracao.php';

use App\Support\Entregas\Integracao\ChavesDeIntegracao;
use App\Support\Entregas\Integracao\Integracao;
use Teste\Banco;
use Teste\Relogio;

function ativaDaLojaA(): ?object
{
    return ChavesDeIntegracao::ativa('empresa-1', 'vendor-a');
}

echo '== Gerar' . PHP_EOL;
reiniciarIntegracao();
$chave = ChavesDeIntegracao::gerar('empresa-1', 'vendor-a');
confere(preg_match('/^ent_live_[0-9A-Za-z]{40}$/', $chave) === 1, 'formato ent_live_ + 40 caracteres');
$linhas = Banco::linhas('entregas_integracao_lojas');
confere(count($linhas) === 1 && $linhas[0]->chave_hash === hash('sha256', $chave) && $linhas[0]->chave_final === substr($chave, -4), 'guarda só o hash e os 4 últimos');
confere(!str_contains(json_encode($linhas), $chave), 'a chave em texto não fica no banco');
confere(logou('[entregas] integração: chave gerada', 'info') && logsSem([$chave]), 'log sem a chave');
$outra = ChavesDeIntegracao::gerar('empresa-1', 'vendor-a');
confere($outra !== $chave && ChavesDeIntegracao::daChave($chave) === null && ChavesDeIntegracao::daChave($outra)?->vendor_uuid === 'vendor-a', 'gerar de novo revoga a anterior');
confere(ativaDaLojaA()->chave_hash === hash('sha256', $outra), 'ativa = a mais nova');

echo '== daChave' . PHP_EOL;
foreach ([null, '', 'flb_live_' . str_repeat('A', 40), 'ent_live_curta', $outra . 'x'] as $invalida) {
    confere(ChavesDeIntegracao::daChave($invalida) === null, 'inválida: ' . var_export($invalida, true));
}
confere(ChavesDeIntegracao::daChave(' ' . $outra . ' ')?->vendor_uuid === 'vendor-a', 'espaços em volta não atrapalham');

echo '== Revogar' . PHP_EOL;
confere(ChavesDeIntegracao::revogar('empresa-1', 'vendor-a') === true && ChavesDeIntegracao::daChave($outra) === null && ativaDaLojaA() === null, 'revogada não autentica');
confere(ChavesDeIntegracao::revogar('empresa-1', 'vendor-a') === false, 'sem chave ativa: nada a revogar');
confere(logou('[entregas] integração: chave revogada', 'info'), 'log da revogação');

echo '== Conectar e segredo' . PHP_EOL;
reiniciarIntegracao();
$loja    = lojaIntegradaA(false);
$segredo = ChavesDeIntegracao::conectar($loja, '  RestaurantePro ', 'https://api.restaurantepro.com.br/functions/v1/entregas-webhook?t=abc');
$lida    = ativaDaLojaA();
confere(preg_match('/^[0-9a-f]{64}$/', $segredo) === 1 && $lida->webhook_segredo !== $segredo && str_starts_with($lida->webhook_segredo, 'cifrado:'), 'segredo de 64 hex, guardado cifrado');
confere(ChavesDeIntegracao::segredo($lida) === $segredo && $lida->sistema === 'RestaurantePro' && $lida->conectada_em === '2026-10-05 15:00:00', 'segredo legível, sistema limpo, conectada agora');
confere($loja->webhook_url === 'https://api.restaurantepro.com.br/functions/v1/entregas-webhook?t=abc', 'o objeto de quem chamou também fica atualizado');
confere(logsSem([$segredo]), 'log sem o segredo');
$lida->webhook_segredo = 'texto-corrompido';
confere(ChavesDeIntegracao::segredo($lida) === null && logou('segredo do webhook ilegível', 'warning'), 'segredo ilegível: null com aviso');
$lida->webhook_segredo = null;
confere(ChavesDeIntegracao::segredo($lida) === null, 'sem segredo: null');

echo '== Uso' . PHP_EOL;
reiniciarIntegracao();
ChavesDeIntegracao::marcarUso(lojaIntegradaA());
confere(ativaDaLojaA()->ultimo_uso_em === '2026-10-05 15:00:00', 'marca o uso');
Relogio::$agora = '2026-10-05 15:00:30';
ChavesDeIntegracao::marcarUso(ativaDaLojaA());
confere(ativaDaLojaA()->ultimo_uso_em === '2026-10-05 15:00:00', 'no mesmo minuto não grava de novo');
Relogio::$agora = '2026-10-05 15:01:05';
ChavesDeIntegracao::marcarUso(ativaDaLojaA());
confere(ativaDaLojaA()->ultimo_uso_em === '2026-10-05 15:01:05', 'depois de 1 min grava');

echo '== Falha do aviso' . PHP_EOL;
ChavesDeIntegracao::registrarFalhaDeAviso(ativaDaLojaA(), str_repeat('x', 300));
confere(mb_strlen(ativaDaLojaA()->ultima_falha_aviso) === 190 && ativaDaLojaA()->ultima_falha_aviso_em === '2026-10-05 15:01:05', 'registra a falha (até 190 caracteres)');
ChavesDeIntegracao::limparFalhaDeAviso(ativaDaLojaA());
confere(ativaDaLojaA()->ultima_falha_aviso === null && ativaDaLojaA()->ultima_falha_aviso_em === null, 'limpa a falha');

echo '== Integracao' . PHP_EOL;
confere(Integracao::ligada(), 'ligada com ENTREGAS_INTEGRACAO=1');
\Teste\Config::$valores['services.entregas.integracao'] = '';
confere(!Integracao::ligada(), 'vazia = desligada');
confere(Integracao::linkDeRastreio('RP12345') === 'https://entregas.restaurantepro.com.br/~/track-order?order=RP12345', 'link de rastreio');
confere(Integracao::linkDeRastreio(null) === null && Integracao::linkDeRastreio('  ') === null, 'sem código: sem link');
confere(Integracao::nomeDoSistema('Saipos <b>') === 'Saipos b' && Integracao::nomeDoSistema('') === 'Integração' && Integracao::nomeDoSistema(null) === 'Integração', 'nome do sistema limpo, com padrão');
confere(Integracao::nomeDoSistema('iFood') === 'Integração' && Integracao::nomeDoSistema(' IFOOD Loja') === 'Integração', 'nome começando com ifood vira o padrão');
confere(mb_strlen(Integracao::nomeDoSistema(str_repeat('a', 60))) === 40, 'nome do sistema com até 40 caracteres');
\Teste\Config::$valores['services.entregas.console_url'] = '';
confere(Integracao::linkDeRastreio('RP1') === 'https://entregas.restaurantepro.com.br/~/track-order?order=RP1', 'console_url vazia: o domínio padrão');

resumo();
