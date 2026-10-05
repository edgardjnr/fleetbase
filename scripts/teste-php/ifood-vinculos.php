<?php

// Integração iFood: vínculo das lojas (VinculosIfood): userCode, troca do código, escolha da loja, renovação e perda.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-vinculos.php

require __DIR__ . '/stubs-ifood.php';

use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\ErroDeVinculo;
use App\Support\Entregas\Ifood\ErroIfood;
use App\Support\Entregas\Ifood\VinculoPerdido;
use App\Support\Entregas\Ifood\VinculosIfood;
use Illuminate\Support\Facades\Cache;
use Teste\Banco;
use Teste\Http;
use Teste\Relogio;
use Teste\Trava;

const EMPRESA = 'empresa-1';
const LOJA_A  = 'vendor-a';
const LOJA_B  = 'vendor-b';

function vinculos(): VinculosIfood
{
    return new VinculosIfood(new ClienteIfood());
}

function respostaDoCodigo(): void
{
    Http::responder(200, ['userCode' => 'ABCD-EFGH', 'authorizationCodeVerifier' => 'verificador-1', 'verificationUrl' => 'https://portal.ifood.com.br/apps/code', 'verificationUrlComplete' => 'https://portal.ifood.com.br/apps/code?c=ABCD-EFGH', 'expiresIn' => 600]);
}

function respostaDoToken(string $acesso = 'token-1', ?string $refresh = 'refresh-1'): void
{
    $corpo = ['accessToken' => $acesso, 'type' => 'bearer', 'expiresIn' => 21600];
    if ($refresh !== null) {
        $corpo['refreshToken'] = $refresh;
    }
    Http::responder(200, $corpo);
}

/** Uma loja já vinculada, gravada direto na tabela. */
function vinculada(string $vendor, string $merchant, string $expiraEm, string $acesso = 'token-1', ?string $refresh = 'refresh-1'): object
{
    Banco::inserir('entregas_ifood_lojas', [
        'company_uuid' => EMPRESA, 'vendor_uuid' => $vendor, 'merchant_id' => $merchant, 'nome_ifood' => 'Loja ' . $merchant,
        'access_token' => encrypt($acesso), 'refresh_token' => $refresh === null ? null : encrypt($refresh), 'expira_em' => $expiraEm,
        'situacao' => 'vinculada', 'vinculado_em' => '2026-10-05 12:00:00', 'renovado_em' => null, 'created_at' => '2026-10-05 12:00:00', 'updated_at' => '2026-10-05 12:00:00',
    ], false);

    return linhaDa($vendor);
}

function linhaDa(string $vendor): ?object
{
    foreach (Banco::linhas('entregas_ifood_lojas') as $linha) {
        if ($linha->vendor_uuid === $vendor) {
            return $linha;
        }
    }

    return null;
}

echo '== Passo 1: código de vínculo' . PHP_EOL;
reiniciarIfood();
respostaDoCodigo();
$codigo = vinculos()->iniciar(LOJA_A);
confere($codigo === ['codigo' => 'ABCD-EFGH', 'link' => 'https://portal.ifood.com.br/apps/code?c=ABCD-EFGH', 'expira_em_segundos' => 600], 'devolve o código, o link com o código e a validade');
$chave = VinculosIfood::chaveDoVerificador(LOJA_A);
confere(isset(Cache::$dados[$chave]) && Cache::$dados[$chave] !== 'verificador-1' && decrypt(Cache::$dados[$chave]) === 'verificador-1', 'verificador no cache, cifrado, por loja');
confere(Cache::$validades[$chave] === 600, 'pelo tempo do código (10 min)');
confere(Banco::linhas('entregas_ifood_lojas') === [], 'nada gravado na tabela ainda');

echo '== Passo 2: uma loja na conta' . PHP_EOL;
respostaDoToken();
Http::responder(200, [['id' => 'merchant-1', 'name' => 'Pizzaria Um', 'corporateName' => 'Pizzaria Um LTDA']]);
$resultado = vinculos()->concluir(EMPRESA, LOJA_A, '  AUTH-1  ');
confere($resultado === ['situacao' => 'vinculada'], 'vinculada direto');
confere(Http::$chamadas[1]['dados']['authorizationCode'] === 'AUTH-1' && Http::$chamadas[1]['dados']['authorizationCodeVerifier'] === 'verificador-1', 'troca com o código (sem espaços) e o verificador guardado');
confere(Http::$chamadas[2]['token'] === 'token-1', 'lista as lojas com o token novo');
$linha = linhaDa(LOJA_A);
confere($linha && $linha->merchant_id === 'merchant-1' && $linha->nome_ifood === 'Pizzaria Um' && $linha->company_uuid === EMPRESA && $linha->situacao === 'vinculada', 'linha com merchant, nome e situação');
confere($linha->access_token !== 'token-1' && decrypt($linha->access_token) === 'token-1' && decrypt($linha->refresh_token) === 'refresh-1', 'tokens cifrados');
confere($linha->expira_em === '2026-10-06 00:00:00', 'validade = agora + expiresIn (6 h)');
confere(!isset(Cache::$dados[$chave]), 'verificador apagado (o código vale uma vez)');
confere(logou('[entregas] ifood: loja vinculada', 'info'), 'log da loja vinculada');

echo '== Vincular de novo a mesma loja atualiza a linha' . PHP_EOL;
respostaDoCodigo();
vinculos()->iniciar(LOJA_A);
respostaDoToken('token-9', 'refresh-9');
Http::responder(200, [['id' => 'merchant-1', 'name' => 'Pizzaria Um']]);
vinculos()->concluir(EMPRESA, LOJA_A, 'AUTH-2');
confere(count(Banco::linhas('entregas_ifood_lojas')) === 1 && decrypt(linhaDa(LOJA_A)->access_token) === 'token-9', 'uma linha só, com o token novo');

echo '== Código vencido' . PHP_EOL;
reiniciarIfood();
$erro = excecao(fn () => vinculos()->concluir(EMPRESA, LOJA_A, 'AUTH-1'));
confere($erro instanceof ErroDeVinculo && str_contains($erro->getMessage(), 'venceu'), 'sem verificador no cache: "código venceu"');
confere(Http::$chamadas === [], 'nem chama o iFood');

echo '== Várias lojas na conta: a central escolhe' . PHP_EOL;
reiniciarIfood();
respostaDoCodigo();
vinculos()->iniciar(LOJA_A);
respostaDoToken();
Http::responder(200, [['id' => 'merchant-1', 'name' => 'Pizzaria Centro'], ['id' => 'merchant-2', 'name' => 'Pizzaria Bairro']]);
$resultado = vinculos()->concluir(EMPRESA, LOJA_A, 'AUTH-1');
confere($resultado === ['situacao' => 'escolher', 'lojas' => [['id' => 'merchant-1', 'nome' => 'Pizzaria Centro'], ['id' => 'merchant-2', 'nome' => 'Pizzaria Bairro']]], 'devolve as lojas para escolher');
confere(Banco::linhas('entregas_ifood_lojas') === [], 'nada gravado antes da escolha');
$escolha = Cache::$dados[VinculosIfood::chaveDaEscolha(LOJA_A)] ?? null;
confere(is_string($escolha) && !str_contains($escolha, 'token-1') && Cache::$validades[VinculosIfood::chaveDaEscolha(LOJA_A)] === 600, 'tokens da escolha no cache, cifrados, por 10 min');
$erro = excecao(fn () => vinculos()->escolher(EMPRESA, LOJA_A, 'merchant-9'));
confere($erro instanceof ErroDeVinculo && Banco::linhas('entregas_ifood_lojas') === [], 'loja fora da conta é recusada');
$resultado = vinculos()->escolher(EMPRESA, LOJA_A, 'merchant-2');
confere($resultado === ['situacao' => 'vinculada'] && linhaDa(LOJA_A)->merchant_id === 'merchant-2' && linhaDa(LOJA_A)->nome_ifood === 'Pizzaria Bairro', 'vincula a escolhida');
confere(!isset(Cache::$dados[VinculosIfood::chaveDaEscolha(LOJA_A)]) && count(Http::$chamadas) === 3, 'escolha apagada, sem chamar o iFood de novo');
$erro = excecao(fn () => vinculos()->escolher(EMPRESA, LOJA_A, 'merchant-1'));
confere($erro instanceof ErroDeVinculo && str_contains($erro->getMessage(), 'venceu'), 'escolha usada não vale de novo');

echo '== Conta sem lojas' . PHP_EOL;
reiniciarIfood();
respostaDoCodigo();
vinculos()->iniciar(LOJA_A);
respostaDoToken();
Http::responder(200, []);
confere(excecao(fn () => vinculos()->concluir(EMPRESA, LOJA_A, 'AUTH-1')) instanceof ErroDeVinculo, 'conta sem lojas: erro de vínculo');

echo '== Merchant já ligado a outra loja' . PHP_EOL;
reiniciarIfood();
vinculada(LOJA_B, 'merchant-1', '2026-10-05 23:00:00');
respostaDoCodigo();
vinculos()->iniciar(LOJA_A);
respostaDoToken('token-a');
Http::responder(200, [['id' => 'merchant-1', 'name' => 'Pizzaria Um']]);
$erro = excecao(fn () => vinculos()->concluir(EMPRESA, LOJA_A, 'AUTH-1'));
confere($erro instanceof ErroDeVinculo && str_contains($erro->getMessage(), 'outra loja'), 'recusa: "já está vinculada a outra loja"');
confere(linhaDa(LOJA_A) === null && decrypt(linhaDa(LOJA_B)->access_token) === 'token-1', 'a outra loja continua como estava');

echo '== Resposta do token sem refresh token' . PHP_EOL;
reiniciarIfood();
respostaDoCodigo();
vinculos()->iniciar(LOJA_A);
respostaDoToken('token-1', null);
Http::responder(200, [['id' => 'merchant-1', 'name' => 'Pizzaria Um']]);
vinculos()->concluir(EMPRESA, LOJA_A, 'AUTH-1');
confere(linhaDa(LOJA_A)->refresh_token === null && logou('resposta do token sem refresh token', 'warning'), 'grava sem refresh e avisa no log (a conferir no primeiro vínculo real)');

echo '== Desvincular' . PHP_EOL;
reiniciarIfood();
vinculada(LOJA_A, 'merchant-1', '2026-10-05 23:00:00');
vinculos()->desvincular(LOJA_A);
$linha = linhaDa(LOJA_A);
confere($linha->situacao === 'desvinculada' && $linha->merchant_id === null && $linha->access_token === null && $linha->refresh_token === null, 'sem tokens e sem merchant');
confere(vinculos()->vinculadas() === [] && VinculosIfood::resumo(LOJA_A) === ['situacao' => null, 'nome' => null, 'merchant_id' => null], 'fora do polling e "—" na tela');
respostaDoCodigo();
vinculos()->iniciar(LOJA_B);
respostaDoToken('token-b');
Http::responder(200, [['id' => 'merchant-1', 'name' => 'Pizzaria Um']]);
confere(vinculos()->concluir(EMPRESA, LOJA_B, 'AUTH-1') === ['situacao' => 'vinculada'], 'o merchant fica livre para outra loja');

echo '== Token válido e renovação' . PHP_EOL;
reiniciarIfood();
$longe = vinculada(LOJA_A, 'merchant-1', '2026-10-05 20:00:00');
confere(vinculos()->tokenValido($longe) === 'token-1' && Http::$chamadas === [], 'vence em 2 h: usa o atual, sem chamar o iFood');
$perto = vinculada(LOJA_B, 'merchant-2', '2026-10-05 18:04:00', 'token-b', 'refresh-b');
respostaDoToken('token-b2', 'refresh-b2');
confere(vinculos()->tokenValido($perto) === 'token-b2', 'vence em 4 min: renova antes');
confere(Http::$chamadas[0]['dados']['refreshToken'] === 'refresh-b', 'renova com o refresh guardado');
$linha = linhaDa(LOJA_B);
confere(decrypt($linha->access_token) === 'token-b2' && decrypt($linha->refresh_token) === 'refresh-b2' && $linha->expira_em === '2026-10-06 00:00:00' && $linha->renovado_em === '2026-10-05 18:00:00', 'grava o token, o refresh novo, a validade e a data');
respostaDoToken('token-b3', null);
vinculos()->renovar(linhaDa(LOJA_B));
confere(decrypt(linhaDa(LOJA_B)->refresh_token) === 'refresh-b2', 'sem refresh novo na resposta, o atual continua');

echo '== Outro processo já renovou' . PHP_EOL;
Relogio::$agora = '2026-10-05 18:10:00';
$antigo = $perto;
$chamadas = count(Http::$chamadas);
confere(vinculos()->renovar($antigo) === 'token-b3' && count(Http::$chamadas) === $chamadas, 'vínculo lido antes da renovação: usa o token novo sem gastar o refresh');

echo '== 401: renova e repete uma vez' . PHP_EOL;
reiniciarIfood();
$vinculo = vinculada(LOJA_A, 'merchant-1', '2026-10-05 20:00:00');
$tokens  = [];
respostaDoToken('token-2', 'refresh-2');
$resultado = vinculos()->comToken($vinculo, function (string $token) use (&$tokens) {
    $tokens[] = $token;
    if ($token === 'token-1') {
        throw new ErroIfood('pedido', 401);
    }

    return 'ok';
});
confere($resultado === 'ok' && $tokens === ['token-1', 'token-2'], 'tenta com o atual, renova e repete com o novo');
$tentativas = new ArrayObject();
respostaDoToken('token-3', 'refresh-3');
$erro = excecao(fn () => vinculos()->comToken(linhaDa(LOJA_A), function (string $token) use ($tentativas) {
    $tentativas[] = $token;
    throw new ErroIfood('pedido', 401);
}));
confere($erro instanceof ErroIfood && $erro->status === 401 && $tentativas->getArrayCopy() === ['token-2', 'token-3'], 'o segundo 401 sobe (só uma repetição)');
$erro = excecao(fn () => vinculos()->comToken(linhaDa(LOJA_A), fn () => throw new ErroIfood('pedido', 404)));
confere($erro instanceof ErroIfood && $erro->status === 404 && count(Http::$chamadas) === 2, 'outro erro sobe sem renovar');

echo '== Refresh recusado = vínculo perdido' . PHP_EOL;
reiniciarIfood();
$vinculo = vinculada(LOJA_A, 'merchant-1', '2026-10-05 18:01:00');
Http::responder(401, ['error' => ['code' => 'Unauthorized', 'message' => 'Bad credentials']]);
$erro  = excecao(fn () => vinculos()->tokenValido($vinculo));
$linha = linhaDa(LOJA_A);
confere($erro instanceof VinculoPerdido && $linha->situacao === 'vinculo_perdido' && $linha->access_token === null, 'marca vinculo_perdido e apaga o access token');
confere($linha->refresh_token !== 'refresh-1' && decrypt($linha->refresh_token) === 'refresh-1', 'o refresh fica (cifrado), para reativar depois de corrigir a configuração');
confere(logou('[entregas] ifood: vínculo perdido', 'warning') && vinculos()->vinculadas() === [], 'log "vínculo perdido" e fora do polling');
confere(VinculosIfood::resumo(LOJA_A)['situacao'] === 'vinculo_perdido', 'a tela mostra "vínculo perdido"');
reiniciarIfood();
$vinculo = vinculada(LOJA_A, 'merchant-1', '2026-10-05 18:01:00');
Http::responder(503, 'Service Unavailable');
$erro = excecao(fn () => vinculos()->tokenValido($vinculo));
confere($erro instanceof ErroIfood && linhaDa(LOJA_A)->situacao === 'vinculada', '5xx na renovação: erro temporário, continua vinculada');
reiniciarIfood();
$vinculo = vinculada(LOJA_A, 'merchant-1', '2026-10-05 17:59:00', 'token-1', null);
confere(excecao(fn () => vinculos()->tokenValido($vinculo)) instanceof VinculoPerdido && Http::$chamadas === [], 'sem refresh guardado e token vencido: perdido sem chamar o iFood');

echo '== Renovação proativa (entregas:ifood-tokens)' . PHP_EOL;
reiniciarIfood();
vinculada('vendor-1', 'merchant-1', '2026-10-05 18:30:00', 'token-1', 'refresh-1');
vinculada('vendor-2', 'merchant-2', '2026-10-05 23:00:00', 'token-2', 'refresh-2');
vinculada('vendor-3', 'merchant-3', '2026-10-05 18:20:00', 'token-3', 'refresh-3');
vinculada('vendor-4', 'merchant-4', '2026-10-05 18:40:00', 'token-4', 'refresh-4');
respostaDoToken('token-1b', 'refresh-1b');
Http::responder(400, ['error' => 'invalid_grant']);
Http::responder(500, 'erro');
$resultado = vinculos()->renovarVencendo();
confere($resultado === ['renovados' => 1, 'perdidos' => 1, 'falhas' => 1], 'renova os que vencem em menos de 1 h (1 ok, 1 perdido, 1 falha)');
confere(array_map(fn ($c) => $c['dados']['refreshToken'], Http::$chamadas) === ['refresh-1', 'refresh-3', 'refresh-4'], 'o que vence em 5 h fica para depois');
confere(linhaDa('vendor-3')->situacao === 'vinculo_perdido' && linhaDa('vendor-4')->situacao === 'vinculada', 'perdido só o recusado');
confere(logou('renovação do token falhou', 'warning'), 'falha temporária no log');

// ---- revisão de qualidade (itens 1 a 10) ----

/** Outro processo mexe na linha da loja (direto no banco em memória). */
function mexer(string $vendor, array $valores): void
{
    foreach (Banco::$tabelas['entregas_ifood_lojas'] as $id => $linha) {
        if ($linha['vendor_uuid'] === $vendor) {
            Banco::$tabelas['entregas_ifood_lojas'][$id] = array_merge($linha, $valores);
        }
    }
}

echo '== 1. Token ilegível no banco (APP_KEY mudou)' . PHP_EOL;
reiniciarIfood();
vinculada(LOJA_A, 'merchant-1', '2026-10-05 20:00:00');
mexer(LOJA_A, ['access_token' => 'eyJpdiI6-cifrado-com-outra-chave']);
$erro  = excecao(fn () => vinculos()->tokenValido(linhaDa(LOJA_A)));
$linha = linhaDa(LOJA_A);
confere($erro instanceof VinculoPerdido, 'access token ilegível: VinculoPerdido (o polling pula a loja e segue com as outras)');
confere($linha->situacao === 'vinculo_perdido' && $linha->access_token === 'eyJpdiI6-cifrado-com-outra-chave' && decrypt($linha->refresh_token) === 'refresh-1', 'vinculo_perdido, mantendo o texto cifrado no banco');
confere(logou('[entregas] ifood: token ilegível (APP_KEY mudou?)', 'warning') && Http::$chamadas === [], 'log "token ilegível" e sem chamar o iFood');
reiniciarIfood();
vinculada(LOJA_A, 'merchant-1', '2026-10-05 18:01:00');
mexer(LOJA_A, ['refresh_token' => 'lixo-corrompido']);
$erro  = excecao(fn () => vinculos()->tokenValido(linhaDa(LOJA_A)));
$linha = linhaDa(LOJA_A);
confere($erro instanceof VinculoPerdido && $linha->situacao === 'vinculo_perdido' && $linha->refresh_token === 'lixo-corrompido' && Http::$chamadas === [], 'refresh ilegível na renovação: vinculo_perdido, texto mantido, sem chamar o iFood');
reiniciarIfood();
$vinculo = vinculada(LOJA_A, 'merchant-1', '2026-10-05 20:00:00');
$erro    = excecao(fn () => vinculos()->comToken($vinculo, function (string $token) {
    mexer(LOJA_A, ['access_token' => 'lixo-corrompido']);
    throw new ErroIfood('pedido', 401);
}));
confere($erro instanceof VinculoPerdido && linhaDa(LOJA_A)->situacao === 'vinculo_perdido', 'ilegível na releitura do 401: VinculoPerdido');
reiniciarIfood();
Cache::put(VinculosIfood::chaveDoVerificador(LOJA_A), 'lixo-corrompido', 600);
$erro = excecao(fn () => vinculos()->concluir(EMPRESA, LOJA_A, 'AUTH-1'));
confere($erro instanceof ErroDeVinculo && str_contains($erro->getMessage(), 'código novo') && Http::$chamadas === [], 'verificador ilegível no cache: ErroDeVinculo pedindo um código novo');
confere(!isset(Cache::$dados[VinculosIfood::chaveDoVerificador(LOJA_A)]), 'e o verificador ilegível sai do cache');
Cache::put(VinculosIfood::chaveDaEscolha(LOJA_A), 'lixo-corrompido', 600);
$erro = excecao(fn () => vinculos()->escolher(EMPRESA, LOJA_A, 'merchant-1'));
confere($erro instanceof ErroDeVinculo && str_contains($erro->getMessage(), 'código novo') && !isset(Cache::$dados[VinculosIfood::chaveDaEscolha(LOJA_A)]), 'escolha ilegível no cache: ErroDeVinculo pedindo um código novo');
reiniciarIfood();
vinculada('vendor-1', 'merchant-1', '2026-10-05 18:30:00', 'token-1', 'refresh-1');
vinculada('vendor-2', 'merchant-2', '2026-10-05 18:30:00', 'token-2', 'refresh-2');
mexer('vendor-1', ['refresh_token' => 'lixo-corrompido']);
respostaDoToken('token-2b', 'refresh-2b');
confere(vinculos()->renovarVencendo() === ['renovados' => 1, 'perdidos' => 1, 'falhas' => 0] && decrypt(linhaDa('vendor-2')->access_token) === 'token-2b', 'entregas:ifood-tokens: a loja ilegível cai e a outra renova');

echo '== 2. Refresh: só 400/401 do /oauth/token derrubam o vínculo' . PHP_EOL;
foreach ([403 => ['error' => 'forbidden'], 404 => ['message' => 'Not Found'], 408 => 'Request Timeout', 409 => ['message' => 'Conflict'], 200 => ['type' => 'bearer']] as $status => $corpo) {
    reiniciarIfood();
    $vinculo = vinculada(LOJA_A, 'merchant-1', '2026-10-05 18:01:00');
    Http::responder($status, $corpo);
    $erro  = excecao(fn () => vinculos()->tokenValido($vinculo));
    $linha = linhaDa(LOJA_A);
    confere($erro instanceof ErroIfood && $linha->situacao === 'vinculada' && decrypt($linha->access_token) === 'token-1' && decrypt($linha->refresh_token) === 'refresh-1', ($status === 200 ? '200 sem accessToken' : "{$status}") . ': erro sobe, a loja continua vinculada com os tokens');
}
reiniciarIfood();
$vinculo = vinculada(LOJA_A, 'merchant-1', '2026-10-05 18:01:00');
Http::responder(400, ['error' => 'invalid_grant']);
$erro = excecao(fn () => vinculos()->tokenValido($vinculo));
confere($erro instanceof VinculoPerdido && linhaDa(LOJA_A)->situacao === 'vinculo_perdido' && decrypt(linhaDa(LOJA_A)->refresh_token) === 'refresh-1', '400 (invalid_grant): vínculo perdido, refresh guardado');
vinculos()->desvincular(LOJA_A);
confere(linhaDa(LOJA_A)->refresh_token === null && linhaDa(LOJA_A)->access_token === null, 'desvincular continua apagando tudo');
reiniciarIfood();
vinculada(LOJA_A, 'merchant-1', '2026-10-05 20:00:00');
vinculos()->perderPorMerchant('merchant-1', 403);
confere(linhaDa(LOJA_A)->situacao === 'vinculo_perdido' && linhaDa(LOJA_A)->access_token === null && decrypt(linhaDa(LOJA_A)->refresh_token) === 'refresh-1', 'revogado no polling (403): access token apagado, refresh guardado');

echo '== 3. 401 com o token já renovado por outro processo' . PHP_EOL;
reiniciarIfood();
$vinculo = vinculada(LOJA_A, 'merchant-1', '2026-10-05 20:00:00');
$tokens  = [];
$resultado = vinculos()->comToken($vinculo, function (string $token) use (&$tokens) {
    $tokens[] = $token;
    if ($token === 'token-1') {
        // outro processo renovou enquanto esta chamada ia ao iFood
        mexer(LOJA_A, ['access_token' => encrypt('token-2'), 'refresh_token' => encrypt('refresh-2'), 'renovado_em' => '2026-10-05 18:00:00']);
        throw new ErroIfood('pedido', 401);
    }

    return 'ok';
});
confere($resultado === 'ok' && $tokens === ['token-1', 'token-2'] && Http::$chamadas === [], 'repete com o token do outro processo, sem gastar o refresh de novo');
reiniciarIfood();
$velho  = vinculada(LOJA_A, 'merchant-1', '2026-10-05 18:04:00');
$tokens = [];
respostaDoToken('token-2', 'refresh-2');
respostaDoToken('token-3', 'refresh-3');
$resultado = vinculos()->comToken($velho, function (string $token) use (&$tokens) {
    $tokens[] = $token;
    if ($token === 'token-2') {
        throw new ErroIfood('pedido', 401);
    }

    return 'ok';
});
confere($resultado === 'ok' && $tokens === ['token-2', 'token-3'] && decrypt(linhaDa(LOJA_A)->refresh_token) === 'refresh-3', 'token recém-renovado recusado (vínculo lido antes): renova de novo, não repete o recusado');
reiniciarIfood();
$vinculo = vinculada(LOJA_A, 'merchant-1', '2026-10-05 20:00:00', 'token-1', null);
$erro    = excecao(fn () => vinculos()->comToken($vinculo, fn () => throw new ErroIfood('pedido', 401)));
confere($erro instanceof VinculoPerdido && linhaDa(LOJA_A)->situacao === 'vinculo_perdido', 'token recusado e sem refresh: vínculo perdido');

echo '== 4. Exceções fora dos tipos esperados' . PHP_EOL;
reiniciarIfood();
$vinculo = vinculada(LOJA_A, 'merchant-1', '2026-10-05 18:01:00');
Trava::$ocupadas['entregas:ifood-token:' . $vinculo->id] = true;
$erro = excecao(fn () => vinculos()->tokenValido($vinculo));
confere($erro instanceof ErroIfood && $erro->status === 0 && $erro->temporario() && $erro->operacao === 'refresh' && linhaDa(LOJA_A)->situacao === 'vinculada', 'trava da renovação ocupada até o fim da espera: ErroIfood temporário (status 0)');
reiniciarIfood();
vinculada('vendor-1', 'merchant-1', '2026-10-05 18:30:00', 'token-1', 'refresh-1');
vinculada('vendor-2', 'merchant-2', '2026-10-05 18:30:00', 'token-2', 'refresh-2');
vinculada('vendor-3', 'merchant-3', '2026-10-05 18:30:00', 'token-3', 'refresh-3');
Http::responderCom(fn () => throw new \LogicException('falha inesperada'));
Trava::$ocupadas['entregas:ifood-token:2'] = true;
respostaDoToken('token-3b', 'refresh-3b');
$resultado = vinculos()->renovarVencendo();
confere($resultado === ['renovados' => 1, 'perdidos' => 0, 'falhas' => 2] && decrypt(linhaDa('vendor-3')->access_token) === 'token-3b', 'erro inesperado e trava ocupada em duas lojas: a terceira renova');
confere(logou('[entregas] ifood: renovação do token falhou', 'warning') && str_contains(json_encode(Illuminate\Support\Facades\Log::$registros), 'LogicException'), 'log com a classe do erro');
confere(logsSem(['token-', 'refresh-']), 'sem token nos logs');

echo '== 5. Renovação: outro processo mexeu no vínculo durante a chamada' . PHP_EOL;
reiniciarIfood();
$vinculo = vinculada(LOJA_A, 'merchant-1', '2026-10-05 18:01:00');
Http::responderCom(function () {
    // outro processo renovou (e o iFood girou o refresh) enquanto este esperava a resposta
    mexer(LOJA_A, ['access_token' => encrypt('token-outro'), 'refresh_token' => encrypt('refresh-outro'), 'renovado_em' => '2026-10-05 18:00:00']);

    return [200, ['accessToken' => 'token-2', 'type' => 'bearer', 'expiresIn' => 21600, 'refreshToken' => 'refresh-2']];
});
confere(vinculos()->tokenValido($vinculo) === 'token-outro', 'devolve o token gravado pelo outro processo');
confere(decrypt(linhaDa(LOJA_A)->access_token) === 'token-outro' && decrypt(linhaDa(LOJA_A)->refresh_token) === 'refresh-outro', 'não sobrescreve o refresh do outro processo');
reiniciarIfood();
$vinculo = vinculada(LOJA_A, 'merchant-1', '2026-10-05 18:01:00');
Http::responderCom(function () {
    vinculos()->desvincular(LOJA_A);

    return [200, ['accessToken' => 'token-2', 'type' => 'bearer', 'expiresIn' => 21600, 'refreshToken' => 'refresh-2']];
});
$erro  = excecao(fn () => vinculos()->tokenValido($vinculo));
$linha = linhaDa(LOJA_A);
confere($erro instanceof VinculoPerdido && $linha->situacao === 'desvinculada' && $linha->access_token === null && $linha->refresh_token === null && $linha->expira_em === null, 'desvinculada no meio da renovação: não grava os tokens de volta');

echo '== 6. Lista de lojas falhou depois da troca do código' . PHP_EOL;
reiniciarIfood();
respostaDoCodigo();
vinculos()->iniciar(LOJA_A);
respostaDoToken();
Http::responder(503, 'Service Unavailable');
$erro = excecao(fn () => vinculos()->concluir(EMPRESA, LOJA_A, 'AUTH-1'));
confere($erro instanceof ErroIfood && $erro->status === 503 && linhaDa(LOJA_A) === null, 'iFood fora ao listar as lojas: ErroIfood, nada gravado');
$pendentes = Cache::$dados[VinculosIfood::chaveDosTokens(LOJA_A)] ?? null;
confere(is_string($pendentes) && !str_contains($pendentes, 'token-1') && Cache::$validades[VinculosIfood::chaveDosTokens(LOJA_A)] === 600, 'tokens guardados no cache, cifrados, por 10 min');
Http::responder(200, [['id' => 'merchant-1', 'name' => 'Pizzaria Um']]);
$resultado = vinculos()->concluir(EMPRESA, LOJA_A, 'AUTH-1');
confere($resultado === ['situacao' => 'vinculada'] && decrypt(linhaDa(LOJA_A)->access_token) === 'token-1', 'repetir conclui com os tokens guardados');
confere(Http::urls() === ['POST /authentication/v1.0/oauth/userCode', 'POST /authentication/v1.0/oauth/token', 'GET /merchant/v1.0/merchants', 'GET /merchant/v1.0/merchants'], 'sem trocar o código de novo (vale uma vez só)');
confere(!isset(Cache::$dados[VinculosIfood::chaveDosTokens(LOJA_A)]), 'tokens guardados apagados depois do vínculo');
reiniciarIfood();
respostaDoCodigo();
vinculos()->iniciar(LOJA_A);
respostaDoToken();
Http::responder(503, 'Service Unavailable');
excecao(fn () => vinculos()->concluir(EMPRESA, LOJA_A, 'AUTH-1'));
respostaDoCodigo();
vinculos()->iniciar(LOJA_A);
confere(!isset(Cache::$dados[VinculosIfood::chaveDosTokens(LOJA_A)]), 'um código novo descarta os tokens guardados');
respostaDoToken('token-1');
Http::responder(503, 'Service Unavailable');
excecao(fn () => vinculos()->concluir(EMPRESA, LOJA_A, 'AUTH-2'));
vinculos()->desvincular(LOJA_A);
confere(!isset(Cache::$dados[VinculosIfood::chaveDosTokens(LOJA_A)]), 'desvincular também');
reiniciarIfood();
Cache::put(VinculosIfood::chaveDosTokens(LOJA_A), 'lixo-corrompido', 600);
$erro = excecao(fn () => vinculos()->concluir(EMPRESA, LOJA_A, 'AUTH-1'));
confere($erro instanceof ErroDeVinculo && str_contains($erro->getMessage(), 'código novo') && !isset(Cache::$dados[VinculosIfood::chaveDosTokens(LOJA_A)]), 'tokens guardados ilegíveis: ErroDeVinculo pedindo um código novo');

echo '== 8. Segredos fora do stack trace' . PHP_EOL;
foreach ([['concluir', 'codigoDeAutorizacao'], ['renovar', 'tokenRecusado'], ['lojasIfood', 'token'], ['gravar', 'tokens'], ['refreshDaResposta', 'tokens'], ['expiraEm', 'tokens']] as [$metodo, $parametro]) {
    $sensivel = false;
    foreach ((new ReflectionMethod(VinculosIfood::class, $metodo))->getParameters() as $p) {
        if ($p->getName() === $parametro) {
            $sensivel = (bool) $p->getAttributes(SensitiveParameter::class);
        }
    }
    confere($sensivel, "VinculosIfood::{$metodo}(\${$parametro}) sensível");
}

echo '== 9. Corrida no índice único' . PHP_EOL;
reiniciarIfood();
respostaDoCodigo();
vinculos()->iniciar(LOJA_A);
respostaDoToken('token-a', 'refresh-a');
Http::responder(200, [['id' => 'merchant-1', 'name' => 'Pizzaria Um']]);
// a loja B grava o mesmo merchant entre a checagem e o insert da loja A
Banco::$antesDeInserir['entregas_ifood_lojas'] = fn () => vinculada(LOJA_B, 'merchant-1', '2026-10-05 23:00:00');
$erro = excecao(fn () => vinculos()->concluir(EMPRESA, LOJA_A, 'AUTH-1'));
confere($erro instanceof ErroDeVinculo && str_contains($erro->getMessage(), 'outra loja'), 'merchant gravado por outra loja no meio: o mesmo ErroDeVinculo da checagem');
confere(linhaDa(LOJA_A) === null && decrypt(linhaDa(LOJA_B)->access_token) === 'token-1', 'a outra loja continua como estava');
reiniciarIfood();
respostaDoCodigo();
vinculos()->iniciar(LOJA_A);
respostaDoToken('token-a', 'refresh-a');
Http::responder(200, [['id' => 'merchant-1', 'name' => 'Pizzaria Um']]);
// outra aba da mesma loja criou a linha (desvinculada) entre a checagem e o insert
Banco::$antesDeInserir['entregas_ifood_lojas'] = fn () => Banco::inserir('entregas_ifood_lojas', ['company_uuid' => EMPRESA, 'vendor_uuid' => LOJA_A, 'situacao' => 'desvinculada'], false);
confere(vinculos()->concluir(EMPRESA, LOJA_A, 'AUTH-1') === ['situacao' => 'vinculada'], 'linha da mesma loja criada no meio: atualiza em vez de falhar');
confere(count(Banco::linhas('entregas_ifood_lojas')) === 1 && linhaDa(LOJA_A)->merchant_id === 'merchant-1' && decrypt(linhaDa(LOJA_A)->access_token) === 'token-a', 'uma linha só, vinculada');

echo '== 10. Sem refresh token: vale até o access token vencer' . PHP_EOL;
reiniciarIfood();
$vinculo = vinculada(LOJA_A, 'merchant-1', '2026-10-05 18:04:00', 'token-1', null);
confere(vinculos()->tokenValido($vinculo) === 'token-1' && linhaDa(LOJA_A)->situacao === 'vinculada' && Http::$chamadas === [], 'vence em 4 min, sem refresh: usa o atual (não cai antes da hora)');
vinculada(LOJA_B, 'merchant-2', '2026-10-05 18:30:00', 'token-b', null);
confere(vinculos()->renovarVencendo() === ['renovados' => 0, 'perdidos' => 0, 'falhas' => 0] && linhaDa(LOJA_A)->situacao === 'vinculada' && linhaDa(LOJA_B)->situacao === 'vinculada' && Http::$chamadas === [], 'entregas:ifood-tokens não derruba quem ainda tem token válido');
Relogio::$agora = '2026-10-05 18:31:00';
confere(vinculos()->renovarVencendo() === ['renovados' => 0, 'perdidos' => 2, 'falhas' => 0] && linhaDa(LOJA_A)->situacao === 'vinculo_perdido' && linhaDa(LOJA_B)->situacao === 'vinculo_perdido', 'vencido e sem refresh: vínculo perdido');

echo '== Resumos para a tela' . PHP_EOL;
reiniciarIfood();
vinculada(LOJA_A, 'merchant-1', '2026-10-05 23:00:00');
$resumos = VinculosIfood::resumos([LOJA_A, LOJA_B]);
confere($resumos === [LOJA_A => ['situacao' => 'vinculada', 'nome' => 'Loja merchant-1', 'merchant_id' => 'merchant-1'], LOJA_B => ['situacao' => null, 'nome' => null, 'merchant_id' => null]], 'situação, nome e merchant de cada loja (sem tokens)');
confere(VinculosIfood::resumos([]) === [], 'lista vazia');

echo '== Sem tokens nos logs' . PHP_EOL;
confere(logsSem(['token-', 'refresh-', 'verificador-1', 'AUTH-']), 'nenhum log com token, refresh, verificador ou código');

resumo();
