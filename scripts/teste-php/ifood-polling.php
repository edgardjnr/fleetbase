<?php

// Integração iFood: o polling (entregas:ifood-polling): lotes, gravação antes do ack, 429, 401, 403, limpeza, uma loja
// com erro inesperado não para as outras e a varredura dos eventos pendentes antigos.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-polling.php

require __DIR__ . '/stubs-ifood.php';

use App\Console\Commands\Entregas\PollingIfood;
use App\Jobs\Entregas\ProcessarPedidoIfood;
use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\VinculosIfood;
use Illuminate\Support\Facades\Cache;
use Teste\Banco;
use Teste\Config;
use Teste\Fila;
use Teste\Http;

function lojaVinculada(string $vendor, string $merchant, string $token, string $expiraEm = '2026-10-05 23:00:00'): void
{
    Banco::inserir('entregas_ifood_lojas', [
        'company_uuid' => 'empresa-1', 'vendor_uuid' => $vendor, 'merchant_id' => $merchant, 'nome_ifood' => 'Loja ' . $merchant,
        'access_token' => encrypt($token), 'refresh_token' => encrypt('refresh-' . $merchant), 'expira_em' => $expiraEm, 'situacao' => 'vinculada',
        'vinculado_em' => '2026-10-05 12:00:00', 'renovado_em' => null, 'created_at' => '2026-10-05 12:00:00', 'updated_at' => '2026-10-05 12:00:00',
    ], false);
}

function eventoDoIfood(string $id, string $codigo, string $pedido, string $merchant = 'merchant-1'): array
{
    return ['id' => $id, 'code' => $codigo, 'fullCode' => $codigo, 'orderId' => $pedido, 'merchantId' => $merchant, 'createdAt' => '2026-10-05T17:59:00.000Z', 'salesChannel' => 'IFOOD'];
}

function rodarPolling(?VinculosIfood $vinculos = null): int
{
    return (new PollingIfood())->handle($vinculos ?? new VinculosIfood(new ClienteIfood()), new ClienteIfood());
}

// tokenValido lança $falha para o merchant dado (erro inesperado: banco, bug)
class VinculosComFalha extends VinculosIfood
{
    public function __construct(public \Throwable $falha, public string $merchant = 'merchant-1')
    {
        parent::__construct(new ClienteIfood());
    }

    public function tokenValido(object $vinculo): string
    {
        if ($vinculo->merchant_id === $this->merchant) {
            throw $this->falha;
        }

        return parent::tokenValido($vinculo);
    }
}

/** Evento pendente gravado em $gravadoEm (created_at), como o polling grava. */
function pendente(string $id, string $pedido, string $gravadoEm, string $codigo = 'PLC'): void
{
    Banco::inserir('entregas_ifood_eventos', [
        'evento_id' => $id, 'merchant_id' => 'merchant-1', 'pedido_ifood_id' => $pedido, 'codigo' => $codigo, 'criado_no_ifood' => null,
        'payload' => null, 'processado_em' => null, 'ignorado' => false, 'created_at' => $gravadoEm, 'updated_at' => $gravadoEm,
    ], false);
}

/** Quantos logs têm $trecho na mensagem. */
function vezesNoLog(string $trecho): int
{
    return count(array_filter(\Illuminate\Support\Facades\Log::$registros, fn ($registro) => str_contains($registro[1], $trecho)));
}

/** O contexto do primeiro log com $trecho. */
function contextoDoLog(string $trecho): ?array
{
    foreach (\Illuminate\Support\Facades\Log::$registros as [, $mensagem, $contexto]) {
        if (str_contains($mensagem, $trecho)) {
            return $contexto;
        }
    }

    return null;
}

function jobs(): array
{
    return array_map(fn (ProcessarPedidoIfood $job) => $job->pedidoIfoodId, Fila::$jobs);
}

echo '== Desligada' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
Config::$valores['services.ifood.ativo'] = '';
confere(rodarPolling() === 0 && Http::$chamadas === [], 'ENTREGAS_IFOOD vazio: sai sem chamar o iFood');

echo '== Duas lojas, um polling por token' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
lojaVinculada('vendor-b', 'merchant-2', 'token-b');
Http::responder(200, [eventoDoIfood('ev-1', 'PLC', 'pedido-1'), eventoDoIfood('ev-2', 'CFM', 'pedido-1'), eventoDoIfood('ev-1', 'PLC', 'pedido-1')]);
Http::responder(202);
Http::responder(204);
rodarPolling();
confere(Http::urls() === ['GET /events/v1.0/events:polling', 'POST /events/v1.0/events/acknowledgment', 'GET /events/v1.0/events:polling'], 'polling da loja A, ack, polling da loja B (204, sem ack)');
confere(Http::$chamadas[0]['token'] === 'token-a' && Http::$chamadas[0]['headers'] === ['x-polling-merchants' => 'merchant-1'], 'loja A com o token e o merchant dela');
confere(Http::$chamadas[2]['token'] === 'token-b' && Http::$chamadas[2]['headers'] === ['x-polling-merchants' => 'merchant-2'], 'loja B com o token e o merchant dela');
confere(count(Banco::linhas('entregas_ifood_eventos')) === 2, 'eventos gravados uma vez (o repetido não entra)');
confere(Http::$chamadas[1]['dados'] === [['id' => 'ev-1'], ['id' => 'ev-2']] && Http::$chamadas[1]['token'] === 'token-a', 'ack dos recebidos, com o token da loja');
confere(jobs() === ['pedido-1'], 'um processamento por pedido');

echo '== Evento repetido em outra rodada' . PHP_EOL;
Fila::$jobs = [];
foreach (Banco::$tabelas['entregas_ifood_eventos'] as $id => $linha) {
    Banco::$tabelas['entregas_ifood_eventos'][$id]['processado_em'] = '2026-10-05 18:00:10';
}
Http::responder(200, [eventoDoIfood('ev-1', 'PLC', 'pedido-1')]);
Http::responder(202);
Http::responder(204);
rodarPolling();
confere(count(Banco::linhas('entregas_ifood_eventos')) === 2 && Http::$chamadas[4]['dados'] === [['id' => 'ev-1']], 'não grava de novo, mas manda o ack');
confere(jobs() === [], 'nada pendente: nenhum processamento');

echo '== Banco fora do ar: sem ack' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
Banco::$falhar['entregas_ifood_eventos'] = 'MySQL server has gone away';
Http::responder(200, [eventoDoIfood('ev-1', 'PLC', 'pedido-1')]);
rodarPolling();
confere(Http::urls() === ['GET /events/v1.0/events:polling'], 'nenhum ack (o iFood reenvia na rodada seguinte)');
confere(jobs() === [] && logou('falha ao gravar os eventos', 'error'), 'nenhum processamento; erro no log');

echo '== Ack falhou: o processamento segue' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
Http::responder(200, [eventoDoIfood('ev-1', 'PLC', 'pedido-1')]);
Http::responder(500, 'erro');
rodarPolling();
confere(count(Banco::linhas('entregas_ifood_eventos')) === 1 && jobs() === ['pedido-1'] && logou('ack falhou', 'warning'), 'evento gravado e enfileirado; aviso no log');

echo '== 429: pausa pelo Retry-After' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
lojaVinculada('vendor-b', 'merchant-2', 'token-b');
Http::responder(429, ['message' => 'Too Many Requests'], ['Retry-After' => '17']);
rodarPolling();
confere(count(Http::$chamadas) === 1, 'para a rodada (a loja B fica para depois)');
confere(Cache::$dados[PollingIfood::CHAVE_PAUSA] === true && Cache::$validades[PollingIfood::CHAVE_PAUSA] === 17, 'pausa de 17 s no cache');
confere(logou('[entregas] ifood: 429, esperando 17 s', 'warning'), 'log "429, esperando 17 s"');
rodarPolling();
confere(count(Http::$chamadas) === 1, 'durante a pausa, não chama o iFood');

echo '== 401: renova e repete' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
Http::responder(401, ['error' => ['code' => 'Unauthorized']]);
Http::responder(200, ['accessToken' => 'token-a2', 'type' => 'bearer', 'expiresIn' => 21600, 'refreshToken' => 'refresh-2']);
Http::responder(204);
rodarPolling();
confere(Http::urls() === ['GET /events/v1.0/events:polling', 'POST /authentication/v1.0/oauth/token', 'GET /events/v1.0/events:polling'] && Http::$chamadas[2]['token'] === 'token-a2', 'polling, token novo, polling de novo');

echo '== Token vencendo: renova antes do polling' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a', '2026-10-05 18:02:00');
Http::responder(200, ['accessToken' => 'token-a2', 'type' => 'bearer', 'expiresIn' => 21600, 'refreshToken' => 'refresh-2']);
Http::responder(204);
rodarPolling();
confere(Http::urls() === ['POST /authentication/v1.0/oauth/token', 'GET /events/v1.0/events:polling'] && Http::$chamadas[1]['token'] === 'token-a2', 'renovação proativa (vence em 2 min)');

echo '== Vínculo perdido sai do polling' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a', '2026-10-05 18:02:00');
lojaVinculada('vendor-b', 'merchant-2', 'token-b');
Http::responder(400, ['error' => 'invalid_grant']);
Http::responder(204);
rodarPolling();
confere(Http::urls() === ['POST /authentication/v1.0/oauth/token', 'GET /events/v1.0/events:polling'] && Http::$chamadas[1]['token'] === 'token-b', 'a loja A cai (refresh recusado); a B segue');
confere(logou('[entregas] ifood: vínculo perdido', 'warning'), 'log de vínculo perdido');

echo '== 403: o dono revogou' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
Http::responder(403, ['unauthorizedMerchants' => ['merchant-1']]);
rodarPolling();
confere(Banco::linhas('entregas_ifood_lojas')[0]->situacao === 'vinculo_perdido' && logou('polling falhou', 'warning'), 'loja vira vínculo perdido');

echo '== Lotes de até 100 lojas por token' . PHP_EOL;
$comToken = [];
foreach (range(1, 150) as $i) {
    $comToken[] = ['token' => 'token-unico', 'vinculo' => (object) ['id' => $i], 'merchant_id' => "merchant-{$i}"];
}
$comToken[] = ['token' => 'token-outro', 'vinculo' => (object) ['id' => 999], 'merchant_id' => 'merchant-999'];
$lotes      = PollingIfood::lotes($comToken);
confere(count($lotes) === 3 && count($lotes[0]['merchants']) === 100 && count($lotes[1]['merchants']) === 50 && $lotes[2]['merchants'] === ['merchant-999'], '150 lojas do mesmo token = 100 + 50; outro token, outro lote');
confere($lotes[1]['vinculo']->id === 101 && $lotes[2]['token'] === 'token-outro', 'cada lote leva o primeiro vínculo dele');

echo '== Limpeza diária' . PHP_EOL;
reiniciarIfood();
foreach (['velho' => '2026-09-27 10:00:00', 'recente' => '2026-10-01 10:00:00', 'pendente' => null] as $id => $processado) {
    Banco::inserir('entregas_ifood_eventos', ['evento_id' => $id, 'merchant_id' => 'm', 'pedido_ifood_id' => 'p', 'codigo' => 'CFM', 'processado_em' => $processado], false);
}
rodarPolling();
confere(array_column(Banco::linhas('entregas_ifood_eventos'), 'evento_id') === ['recente', 'pendente'], 'apaga só os processados há mais de 7 dias');
confere(Cache::$validades[PollingIfood::CHAVE_LIMPEZA] === 86400, 'uma vez por dia');
Banco::inserir('entregas_ifood_eventos', ['evento_id' => 'velho-2', 'merchant_id' => 'm', 'pedido_ifood_id' => 'p', 'codigo' => 'CFM', 'processado_em' => '2026-09-01 10:00:00'], false);
rodarPolling();
confere(count(Banco::linhas('entregas_ifood_eventos')) === 3, 'na mesma data, não apaga de novo');

echo '== Sem dados sensíveis no log' . PHP_EOL;
confere(logsSem(['token-', 'refresh-']), 'nenhum token nos logs');

echo '== Erro inesperado no token de uma loja: as outras seguem' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
lojaVinculada('vendor-b', 'merchant-2', 'token-b');
Http::responder(204);
rodarPolling(new VinculosComFalha(new \RuntimeException('falha qualquer')));
confere(Http::urls() === ['GET /events/v1.0/events:polling'] && Http::$chamadas[0]['token'] === 'token-b', 'a loja B faz o polling');
$contexto = contextoDoLog('token indisponível para o polling');
confere(($contexto['merchant'] ?? null) === 'merchant-1' && ($contexto['loja'] ?? null) === 'vendor-a' && ($contexto['erro'] ?? null) === 'RuntimeException' && ($contexto['mensagem'] ?? null) === 'falha qualquer', 'log com os ids, a classe e a mensagem');

echo '== Erro do banco no token: sem a mensagem (SQL com valores) no log' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
lojaVinculada('vendor-b', 'merchant-2', 'token-b');
Http::responder(204);
rodarPolling(new VinculosComFalha(new Teste\ErroDeBanco("SQLSTATE[HY000]: General error (SQL: update x set nome = 'CLIENTE FICTICIO')")));
$contexto = contextoDoLog('token indisponível para o polling');
confere(Http::$chamadas[0]['token'] === 'token-b' && ($contexto['erro'] ?? null) === 'Teste\ErroDeBanco' && !array_key_exists('mensagem', $contexto), 'a loja B segue; log só com a classe');
confere(logsSem(['CLIENTE FICTICIO', 'SQL']), 'nada do SQL no log');

echo '== Erro inesperado no polling de uma loja: as outras seguem' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
lojaVinculada('vendor-b', 'merchant-2', 'token-b');
Http::responderCom(fn () => throw new \LogicException('inesperado'));
Http::responder(200, [eventoDoIfood('ev-9', 'PLC', 'pedido-9', 'merchant-2')]);
Http::responder(202);
rodarPolling();
confere(Http::urls() === ['GET /events/v1.0/events:polling', 'GET /events/v1.0/events:polling', 'POST /events/v1.0/events/acknowledgment'] && Http::$chamadas[1]['token'] === 'token-b', 'a loja B faz o polling e o ack');
$contexto = contextoDoLog('polling falhou');
confere(jobs() === ['pedido-9'] && ($contexto['merchants'] ?? null) === ['merchant-1'] && ($contexto['erro'] ?? null) === 'LogicException', 'pedido da loja B enfileirado; log com o merchant e a classe');

echo '== Varredura: pendente antigo volta para a fila; recente não' . PHP_EOL;
reiniciarIfood();
pendente('ev-1', 'pedido-antigo', '2026-10-05 17:50:00');
pendente('ev-2', 'pedido-antigo', '2026-10-05 17:51:00', 'CFM');
pendente('ev-3', 'pedido-recente', '2026-10-05 17:59:00');
pendente('ev-4', 'pedido-limite', '2026-10-05 12:00:00');
rodarPolling();
confere(jobs() === ['pedido-antigo', 'pedido-limite'], 'gravados entre 2 min e 6 h atrás: um job por pedido; o de 1 min fica');
confere(Cache::$validades[PollingIfood::chaveDaVarredura('pedido-antigo')] === 300, 'marcado por 5 min');

echo '== Varredura: não enfileira de novo em 5 min' . PHP_EOL;
Fila::$jobs = [];
rodarPolling();
confere(jobs() === [], 'na rodada seguinte, nada');
Cache::forget(PollingIfood::chaveDaVarredura('pedido-antigo'));
rodarPolling();
confere(jobs() === ['pedido-antigo'], 'passados os 5 min (marca vencida), enfileira de novo');

echo '== Varredura: pendente há mais de 6 h só vai para o log, uma vez' . PHP_EOL;
reiniciarIfood();
pendente('ev-1', 'pedido-velho', '2026-10-05 11:59:59');
pendente('ev-2', 'pedido-velho', '2026-10-05 10:00:00', 'CFM');
pendente('ev-3', 'pedido-de-8-dias', '2026-09-27 10:00:00');
rodarPolling();
rodarPolling();
confere(jobs() === [], 'não enfileira');
confere(vezesNoLog('evento pendente há mais de 6 h') === 1 && logou('evento pendente há mais de 6 h', 'warning'), 'um warning só, em duas rodadas');
confere((contextoDoLog('evento pendente há mais de 6 h')['pedido_ifood'] ?? null) === 'pedido-velho', 'com o id do pedido (o de mais de 7 dias fica de fora)');

echo '== Varredura: até 50 por rodada, sem deixar os outros para trás' . PHP_EOL;
reiniciarIfood();
foreach (range(1, 60) as $i) {
    pendente("ev-{$i}", "pedido-{$i}", '2026-10-05 17:00:00');
}
rodarPolling();
confere(count(jobs()) === 50, '50 na primeira rodada');
$primeiros = jobs();
Fila::$jobs = [];
rodarPolling();
confere(count(jobs()) === 10 && !array_intersect(jobs(), $primeiros), 'os 10 restantes na seguinte');

echo '== Varredura com o polling falhando (5xx, rede) ou em pausa' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
pendente('ev-1', 'pedido-antigo', '2026-10-05 17:50:00');
Http::responder(503, 'fora do ar');
rodarPolling();
confere(logou('polling falhou', 'warning') && jobs() === ['pedido-antigo'], '503: enfileira o pendente antigo mesmo assim');
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
pendente('ev-1', 'pedido-antigo', '2026-10-05 17:50:00');
Http::falharConexao();
rodarPolling();
confere(jobs() === ['pedido-antigo'], 'rede fora: idem');
reiniciarIfood();
pendente('ev-1', 'pedido-antigo', '2026-10-05 17:50:00');
Cache::put(PollingIfood::CHAVE_PAUSA, true, 60);
rodarPolling();
confere(Http::$chamadas === [] && jobs() === ['pedido-antigo'], 'em pausa (429): sem chamar o iFood, mas enfileira');
reiniciarIfood();
pendente('ev-1', 'pedido-antigo', '2026-10-05 17:50:00');
Config::$valores['services.ifood.ativo'] = '';
rodarPolling();
confere(jobs() === [], 'integração desligada: nada');

echo '== Pedido com evento novo e pendente antigo: um job só' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
pendente('ev-0', 'pedido-1', '2026-10-05 17:50:00');
Http::responder(200, [eventoDoIfood('ev-1', 'CFM', 'pedido-1')]);
Http::responder(202);
rodarPolling();
confere(jobs() === ['pedido-1'], 'o lote enfileira; a varredura não repete');
Fila::$jobs = [];
Http::responder(204);
rodarPolling();
confere(jobs() === [], 'nem na rodada seguinte (dentro dos 5 min)');
confere(logsSem(['token-', 'refresh-']), 'nenhum token nos logs');

resumo();
