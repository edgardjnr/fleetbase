<?php

// Integração iFood: modo homologação (log de cada chamada e erro simulado no ClienteIfood) e os cenários do comando
// entregas:ifood-homologacao (HomologacaoIfood): token vencido, token inválido, erro simulado.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-homologacao.php

require __DIR__ . '/stubs-ifood.php';

use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\ErroIfood;
use App\Support\Entregas\Ifood\HomologacaoIfood;
use App\Support\Entregas\Ifood\VinculosIfood;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Teste\Banco;
use Teste\Config;
use Teste\Http;

function homologacao(bool $ligada): void
{
    reiniciarIfood();
    Config::$valores['services.ifood.homologacao'] = $ligada ? '1' : '';
}

/** Os registros do log com a mensagem dada. */
function registros(string $mensagem): array
{
    return array_values(array_filter(Log::$registros, fn ($r) => $r[1] === '[entregas] ifood: ' . $mensagem));
}

function lojaVinculada(string $vendor, string $merchant, string $expiraEm = '2026-10-05 20:00:00', string $acesso = 'token-1'): object
{
    Banco::inserir('entregas_ifood_lojas', [
        'company_uuid' => 'empresa-1', 'vendor_uuid' => $vendor, 'merchant_id' => $merchant, 'nome_ifood' => 'Loja ' . $merchant,
        'access_token' => encrypt($acesso), 'refresh_token' => encrypt('refresh-1'), 'expira_em' => $expiraEm,
        'situacao' => 'vinculada', 'vinculado_em' => '2026-10-05 09:00:00', 'renovado_em' => null, 'created_at' => '2026-10-05 09:00:00', 'updated_at' => '2026-10-05 09:00:00',
    ], false);

    return HomologacaoIfood::vinculo($merchant);
}

echo '== Modo desligado: nada muda' . PHP_EOL;
homologacao(false);
confere(!ClienteIfood::emHomologacao(), 'sem ENTREGAS_IFOOD_HOMOLOGACAO = desligado');
Cache::put(ClienteIfood::chaveDaSimulacao('polling'), ['status' => 429, 'espera' => 30]);
Http::responder(204);
confere((new ClienteIfood())->polling('token-1', ['m1']) === [], 'o polling vai ao iFood mesmo com uma simulação no cache');
confere(count(Http::$chamadas) === 1 && Cache::get(ClienteIfood::chaveDaSimulacao('polling')) !== null, 'a simulação não é consumida');
confere(Log::$registros === [], 'nenhum log de chamada');
confere(excecao(fn () => HomologacaoIfood::simular('polling', 429)) instanceof InvalidArgumentException, 'simular exige o modo ligado');
confere(excecao(fn () => HomologacaoIfood::vencerToken((object) ['id' => 1])) instanceof InvalidArgumentException, 'token vencido exige o modo ligado');

echo '== Log de cada chamada' . PHP_EOL;
homologacao(true);
$cliente = new ClienteIfood();
Http::responder(204);
$cliente->polling('token-1', ['m1', 'm2']);
$log = registros('chamada');
confere(count($log) === 1 && $log[0][0] === 'info', 'uma linha "chamada" por chamada');
confere($log[0][2]['operacao'] === 'polling' && $log[0][2]['status'] === 204 && $log[0][2]['lojas'] === 2 && is_int($log[0][2]['ms']), 'operação, status, tempo e quantidade de lojas');
confere($log[0][2]['excludeHeartbeat'] === 'true' && $log[0][2]['x-polling-merchants'] === 'm1,m2', 'com o excludeHeartbeat e as lojas do x-polling-merchants (critérios do polling)');
$ultima = end(Http::$chamadas);
confere(($ultima['headers']['x-polling-merchants'] ?? null) === 'm1,m2' && ($ultima['dados']['excludeHeartbeat'] ?? null) === 'true', 'e os dois vão de verdade na chamada');
confere(!str_contains(json_encode(Log::$registros), 'token-1'), 'sem o token no log');

Http::responder(200, [['id' => 'e1', 'code' => 'PLC', 'orderId' => 'p1'], ['id' => 'e2', 'code' => 'DDCR', 'orderId' => 'p1'], ['id' => 'e3', 'code' => 'PLC', 'orderId' => 'p2']]);
$cliente->polling('token-1', ['m1']);
$eventos = registros('eventos recebidos');
confere(count($eventos) === 1 && $eventos[0][2] === ['quantidade' => 3, 'codigos' => ['PLC', 'DDCR']], 'eventos recebidos: quantidade e códigos, sem payload');

Http::responder(202);
$cliente->ack('token-1', ['e1', 'e2', 'e3']);
$ack = registros('chamada');
confere(end($ack)[2]['operacao'] === 'ack' && end($ack)[2]['status'] === 202 && end($ack)[2]['eventos'] === 3, 'ack com o status e a quantidade de eventos');

Http::responder(200, ['id' => 'p1', 'customer' => ['name' => 'Fulano de Tal']]);
$cliente->pedidoLogistics('token-1', 'p1');
$pedido = registros('chamada');
confere(end($pedido)[2]['operacao'] === 'pedido' && end($pedido)[2]['pedido_ifood'] === 'p1', 'GET do pedido com o id do pedido');
confere(!str_contains(json_encode(Log::$registros), 'Fulano'), 'sem dado do cliente no log');

Http::responder(202);
$cliente->acaoLogistica('token-1', 'p1', 'assignDriver', ['workerName' => 'Motoboy Silva', 'workerPhone' => '16999998888', 'workerVehicleType' => 'MOTORCYCLE']);
$acao = registros('chamada');
confere(end($acao)[2]['operacao'] === 'assignDriver' && end($acao)[2]['status'] === 202, 'ação com o nome da ação');
confere(!str_contains(json_encode(Log::$registros), 'Silva') && !str_contains(json_encode(Log::$registros), '16999998888'), 'sem nome e telefone do motoboy');

Http::responder(400, ['message' => 'Confirmation code is invalid']);
$erro = excecao(fn () => $cliente->verificarCodigo('token-1', 'p1', '1234'));
$codigo = registros('chamada');
confere($erro instanceof ErroIfood && end($codigo)[2]['status'] === 400 && end($codigo)[2]['operacao'] === 'verifyDeliveryCode', 'resposta de erro também sai no log');
confere(!str_contains(json_encode(Log::$registros), '1234'), 'sem o código digitado');

Http::falharConexao();
$erro = excecao(fn () => $cliente->polling('token-1', ['m1']));
$rede = registros('chamada');
confere($erro instanceof ErroIfood && $erro->status === 0 && end($rede)[2]['status'] === 0, 'falha de rede: status 0 no log');

echo '== Erro simulado' . PHP_EOL;
homologacao(true);
HomologacaoIfood::simular('polling', 429, 30);
$cliente = new ClienteIfood();
$erro    = excecao(fn () => $cliente->polling('token-1', ['m1']));
confere($erro instanceof ErroIfood && $erro->status === 429 && $erro->retryAfter === 30 && $erro->operacao === 'polling', '429 com o Retry-After escolhido');
confere(Http::$chamadas === [], 'sem ir ao iFood');
$simulado = registros('erro simulado (homologação)');
confere(count($simulado) === 1 && $simulado[0][0] === 'warning' && $simulado[0][2]['retry_after'] === 30, 'log "erro simulado (homologação)"');
Http::responder(204);
confere($cliente->polling('token-1', ['m1']) === [] && count(Http::$chamadas) === 1, 'vale uma vez: a chamada seguinte vai ao iFood');

HomologacaoIfood::simular('polling', 429, 9999);
confere(excecao(fn () => $cliente->polling('token-1', ['m1']))->retryAfter === ClienteIfood::ESPERA_MAXIMA_429, 'Retry-After com o mesmo teto da resposta real');
HomologacaoIfood::simular('polling', 429);
confere(excecao(fn () => $cliente->polling('token-1', ['m1']))->retryAfter === ClienteIfood::ESPERA_PADRAO_429, '429 sem espera: o padrão de 60 s');

HomologacaoIfood::simular('dispatch', 500);
$erro = excecao(fn () => $cliente->acaoLogistica('token-1', 'p1', 'dispatch'));
confere($erro instanceof ErroIfood && $erro->status === 500 && $erro->temporario() && $erro->retryAfter === null, '500 na ação: temporário, como a resposta real');
HomologacaoIfood::simular('assignDriver', 409);
Http::responder(202);
confere(excecao(fn () => $cliente->acaoLogistica('token-1', 'p1', 'goingToOrigin')) === null, 'a simulação vale só para a operação escolhida');
confere(excecao(fn () => $cliente->acaoLogistica('token-1', 'p1', 'assignDriver', ['workerName' => 'X']))->status === 409, 'e é consumida por ela');
HomologacaoIfood::simular('pedido', 404);
confere(excecao(fn () => $cliente->pedidoLogistics('token-1', 'p1'))->status === 404, '404 no GET do pedido');

confere(excecao(fn () => HomologacaoIfood::simular('refresh', 500)) instanceof InvalidArgumentException, 'o token nunca é simulado (refresh recusado derrubaria o vínculo)');
confere(excecao(fn () => HomologacaoIfood::simular('polling', 401)) instanceof InvalidArgumentException, 'status fora da lista recusado');
HomologacaoIfood::simular('ack', 503);
HomologacaoIfood::simular('dispatch', 500);
HomologacaoIfood::limpar();
confere(HomologacaoIfood::situacao()['simulados'] === [], 'limpar tira as simulações pendentes');

echo '== Loja do comando' . PHP_EOL;
homologacao(true);
$a = lojaVinculada('vendor-uuid-a', 'merchant-a');
confere($a->merchant_id === 'merchant-a', '--loja pelo merchant id');
confere(HomologacaoIfood::vinculo(null)->merchant_id === 'merchant-a', 'sem --loja: a única vinculada');
lojaVinculada('vendor-uuid-b', 'merchant-b');
confere(excecao(fn () => HomologacaoIfood::vinculo(null)) instanceof InvalidArgumentException, 'duas vinculadas sem --loja: erro');
Banco::inserir('vendors', ['uuid' => 'vendor-uuid-b', 'public_id' => 'vendor_teste', 'name' => 'Terraço Teste'], false);
confere(HomologacaoIfood::vinculo('vendor_teste')->merchant_id === 'merchant-b', '--loja pelo public_id do Fornecedor');
confere(excecao(fn () => HomologacaoIfood::vinculo('vendor_outra')) instanceof InvalidArgumentException, 'loja não vinculada: erro');
$situacao = HomologacaoIfood::situacao();
confere(count($situacao['lojas']) === 2 && $situacao['lojas'][1]['loja'] === 'Terraço Teste' && $situacao['lojas'][1]['fornecedor'] === 'vendor_teste', 'situação com o nome e o Fornecedor');
confere(!str_contains(json_encode($situacao), 'cifrado') && !str_contains(json_encode($situacao), 'token'), 'situação sem tokens');

echo '== Token vencido: renovação proativa' . PHP_EOL;
homologacao(true);
$loja = lojaVinculada('vendor-uuid-a', 'merchant-a');
HomologacaoIfood::vencerToken($loja);
$vinculos = new VinculosIfood(new ClienteIfood());
Http::responder(200, ['accessToken' => 'token-2', 'type' => 'bearer', 'expiresIn' => 21600, 'refreshToken' => 'refresh-2']);
$token = $vinculos->tokenValido(HomologacaoIfood::vinculo('merchant-a'));
confere($token === 'token-2' && Http::urls() === ['POST /authentication/v1.0/oauth/token'], 'renova antes de usar');
$renovado = registros('token renovado');
confere(count($renovado) === 1 && $renovado[0][2]['motivo'] === 'vencimento' && $renovado[0][2]['merchant'] === 'merchant-a', 'log "token renovado" com motivo vencimento');
confere(!str_contains(json_encode(Log::$registros), 'token-2') && !str_contains(json_encode(Log::$registros), 'refresh-2'), 'sem os tokens no log');

echo '== Token inválido: 401, renova e repete' . PHP_EOL;
homologacao(true);
$loja = lojaVinculada('vendor-uuid-a', 'merchant-a');
HomologacaoIfood::invalidarToken($loja);
$loja = HomologacaoIfood::vinculo('merchant-a');
confere(decrypt($loja->access_token) === HomologacaoIfood::TOKEN_INVALIDO && decrypt($loja->refresh_token) === 'refresh-1', 'só o access token muda; o refresh fica');
$vinculos = new VinculosIfood(new ClienteIfood());
Http::responder(401, ['message' => 'token expired']);
Http::responder(200, ['accessToken' => 'token-2', 'type' => 'bearer', 'expiresIn' => 21600, 'refreshToken' => 'refresh-2']);
Http::responder(200, ['id' => 'p1']);
$resposta = $vinculos->comToken($loja, fn ($t) => (new ClienteIfood())->pedidoLogistics($t, 'p1'));
confere($resposta === ['id' => 'p1'], 'a chamada repetida com o token novo responde');
confere(Http::$chamadas[0]['token'] === HomologacaoIfood::TOKEN_INVALIDO && Http::$chamadas[2]['token'] === 'token-2', 'primeiro o inválido, depois o renovado');
confere(count(registros('token recusado (401), renovando')) === 1 && registros('token renovado')[0][2]['motivo'] === '401', 'logs do 401 e da renovação com motivo 401');
$chamadas = array_map(fn ($r) => $r[2]['operacao'] . ' ' . $r[2]['status'], registros('chamada'));
confere($chamadas === ['pedido 401', 'refresh 200', 'pedido 200'], 'a sequência no log: 401, refresh, repetição');

resumo();
