<?php

// Integração iFood: o polling (entregas:ifood-polling): lotes, gravação antes do ack, eventos sem pedido e sem id, 429
// por token (polling e ack), 401 (polling e ack), 403 (com e sem lista, erro ao tratar), limites da rodada (tempo e
// falhas seguidas) com o cursor do ponto de partida, token pedido dentro do laço dos lotes, limpeza, uma loja com erro
// inesperado não para as outras e a varredura dos eventos pendentes antigos (marca maior que o prazo do job, ordem,
// dispatch que falha), relógio monotônico.
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

function lojaVinculada(string $vendor, string $merchant, string $token, string $expiraEm = '2026-10-05 20:00:00'): void
{
    Banco::inserir('entregas_ifood_lojas', [
        'company_uuid' => 'empresa-1', 'vendor_uuid' => $vendor, 'merchant_id' => $merchant, 'nome_ifood' => 'Loja ' . $merchant,
        'access_token' => encrypt($token), 'refresh_token' => encrypt('refresh-' . $merchant), 'expira_em' => $expiraEm, 'situacao' => 'vinculada',
        'vinculado_em' => '2026-10-05 09:00:00', 'renovado_em' => null, 'created_at' => '2026-10-05 09:00:00', 'updated_at' => '2026-10-05 09:00:00',
    ], false);
}

function eventoDoIfood(string $id, string $codigo, string $pedido, string $merchant = 'merchant-1'): array
{
    return ['id' => $id, 'code' => $codigo, 'fullCode' => $codigo, 'orderId' => $pedido, 'merchantId' => $merchant, 'createdAt' => '2026-10-05T17:59:00.000Z', 'salesChannel' => 'IFOOD'];
}

function rodarPolling(?VinculosIfood $vinculos = null, ?PollingIfood $polling = null): int
{
    return ($polling ?? new PollingIfood())->handle($vinculos ?? new VinculosIfood(new ClienteIfood()), new ClienteIfood());
}

// relógio da rodada controlado pelo teste
class PollingComRelogio extends PollingIfood
{
    public static float $agora = 1000.0;

    protected function relogio(): float
    {
        return static::$agora;
    }
}

// a lista das lojas vinculadas falha (banco fora do ar): erro não tratado dentro do buscarEventos
class VinculosForaDoAr extends VinculosIfood
{
    public function __construct()
    {
        parent::__construct(new ClienteIfood());
    }

    public function vinculadas(): array
    {
        throw new Teste\ErroDeBanco('SQLSTATE[HY000] [2002] Connection refused');
    }
}

function situacaoDa(string $merchant): ?string
{
    foreach (Banco::linhas('entregas_ifood_lojas') as $linha) {
        if ($linha->merchant_id === $merchant) {
            return $linha->situacao;
        }
    }

    return null;
}

/** Evento processado em $processadoEm (para a limpeza). */
function processado(string $id, string $processadoEm): void
{
    Banco::inserir('entregas_ifood_eventos', ['evento_id' => $id, 'merchant_id' => 'm', 'pedido_ifood_id' => 'p-' . $id, 'codigo' => 'CFM', 'processado_em' => $processadoEm, 'created_at' => $processadoEm], false);
}

function idsDosEventos(): array
{
    return array_column(Banco::linhas('entregas_ifood_eventos'), 'evento_id');
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
    Banco::$tabelas['entregas_ifood_eventos'][$id]['processado_em'] = '2026-10-05 15:00:10';
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

echo '== 429 no primeiro lote: pausa só a loja dele; o segundo segue' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
lojaVinculada('vendor-b', 'merchant-2', 'token-b');
Http::responder(429, ['message' => 'Too Many Requests'], ['Retry-After' => '17']);
Http::responder(204);
rodarPolling();
confere(count(Http::$chamadas) === 2 && Http::$chamadas[1]['token'] === 'token-b', 'a loja B faz o polling na mesma rodada');
confere((Cache::$dados[PollingIfood::chaveDaPausa(1)] ?? null) === true && (Cache::$validades[PollingIfood::chaveDaPausa(1)] ?? null) === 17, 'pausa de 17 s da loja A no cache');
confere(!isset(Cache::$dados[PollingIfood::chaveDaPausa(2)]), 'a loja B não fica em pausa');
confere(logou('[entregas] ifood: 429, esperando 17 s', 'warning'), 'log "429, esperando 17 s"');
Http::responder(204);
rodarPolling();
confere(count(Http::$chamadas) === 3 && Http::$chamadas[2]['token'] === 'token-b', 'durante a pausa, só a loja B');
Http::responder(204);
rodarPolling();
confere(count(Http::$chamadas) === 4 && Http::$chamadas[3]['token'] === 'token-b', 'e de novo na rodada seguinte');
Cache::forget(PollingIfood::chaveDaPausa(1));
Http::responder(204);
Http::responder(204);
rodarPolling();
confere(array_column(array_slice(Http::$chamadas, 4), 'token') === ['token-a', 'token-b'], 'pausa vencida: a loja A volta');

echo '== 429 no segundo lote' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
lojaVinculada('vendor-b', 'merchant-2', 'token-b');
Http::responder(200, [eventoDoIfood('ev-1', 'PLC', 'pedido-1')]);
Http::responder(202);
Http::responder(429, ['message' => 'Too Many Requests'], ['Retry-After' => '30']);
rodarPolling();
confere(Http::urls() === ['GET /events/v1.0/events:polling', 'POST /events/v1.0/events/acknowledgment', 'GET /events/v1.0/events:polling'] && jobs() === ['pedido-1'], 'a loja A grava, confirma e enfileira');
confere((Cache::$validades[PollingIfood::chaveDaPausa(2)] ?? null) === 30 && !isset(Cache::$dados[PollingIfood::chaveDaPausa(1)]), 'só a loja B em pausa (30 s)');
Http::responder(204);
rodarPolling();
confere(count(Http::$chamadas) === 4 && Http::$chamadas[3]['token'] === 'token-a', 'na rodada seguinte, só a loja A');

echo '== 429 num token com mais de um lote: as lojas do token param, o outro token segue' . PHP_EOL;
reiniciarIfood();
foreach (range(1, 101) as $i) {
    lojaVinculada("vendor-{$i}", "merchant-{$i}", 'token-unico');
}
lojaVinculada('vendor-b', 'merchant-b', 'token-b');
Http::responder(429, null, ['Retry-After' => '20']);
Http::responder(204);
rodarPolling();
confere(count(Http::$chamadas) === 2 && Http::$chamadas[1]['token'] === 'token-b', 'o segundo lote do mesmo token fica de fora; o outro token faz o polling');
confere((Cache::$validades[PollingIfood::chaveDaPausa(1)] ?? null) === 20 && (Cache::$validades[PollingIfood::chaveDaPausa(101)] ?? null) === 20 && !isset(Cache::$dados[PollingIfood::chaveDaPausa(102)]), 'pausa de todas as lojas do token (e só delas)');

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
lojaVinculada('vendor-a', 'merchant-1', 'token-a', '2026-10-05 15:02:00');
Http::responder(200, ['accessToken' => 'token-a2', 'type' => 'bearer', 'expiresIn' => 21600, 'refreshToken' => 'refresh-2']);
Http::responder(204);
rodarPolling();
confere(Http::urls() === ['POST /authentication/v1.0/oauth/token', 'GET /events/v1.0/events:polling'] && Http::$chamadas[1]['token'] === 'token-a2', 'renovação proativa (vence em 2 min)');

echo '== Vínculo perdido sai do polling' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a', '2026-10-05 15:02:00');
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

echo '== 403: só perde as lojas do lote' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
lojaVinculada('vendor-b', 'merchant-2', 'token-b');
Http::responder(403, ['unauthorizedMerchants' => ['merchant-1', 'merchant-2']]);
Http::responder(204);
rodarPolling();
confere(situacaoDa('merchant-1') === 'vinculo_perdido' && situacaoDa('merchant-2') === 'vinculada', 'a loja B (de outro lote) continua vinculada');
confere(count(Http::$chamadas) === 2 && Http::$chamadas[1]['token'] === 'token-b', 'e faz o polling dela');

echo '== 403 sem lista: pausa o lote em vez de repetir a cada 30 s' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
Http::responder(403, 'Forbidden');
rodarPolling();
confere(situacaoDa('merchant-1') === 'vinculada', 'a loja continua vinculada');
confere((Cache::$validades[PollingIfood::chaveDaPausa(1)] ?? null) === 300, 'lote em pausa por 5 min');
confere(logou('403 sem a lista de lojas', 'warning'), 'warning no log');
rodarPolling();
confere(count(Http::$chamadas) === 1, 'na rodada seguinte, não chama o iFood');
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
Http::responder(403, '{"unauthorizedMerchants": ["merchant-1", "merch');
rodarPolling();
confere(situacaoDa('merchant-1') === 'vinculada' && isset(Cache::$dados[PollingIfood::chaveDaPausa(1)]), 'corpo truncado: pausa, sem derrubar a loja');
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
lojaVinculada('vendor-b', 'merchant-2', 'token-b');
Http::responder(403, ['unauthorizedMerchants' => ['merchant-2']]);
Http::responder(204);
rodarPolling();
confere(situacaoDa('merchant-1') === 'vinculada' && situacaoDa('merchant-2') === 'vinculada' && isset(Cache::$dados[PollingIfood::chaveDaPausa(1)]), 'lista só com loja de fora do lote: ninguém perde, o lote pausa');

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
foreach (['velho' => '2026-09-27 07:00:00', 'recente' => '2026-10-01 07:00:00', 'pendente' => null] as $id => $processado) {
    Banco::inserir('entregas_ifood_eventos', ['evento_id' => $id, 'merchant_id' => 'm', 'pedido_ifood_id' => 'p', 'codigo' => 'CFM', 'processado_em' => $processado], false);
}
rodarPolling();
confere(array_column(Banco::linhas('entregas_ifood_eventos'), 'evento_id') === ['recente', 'pendente'], 'apaga só os processados há mais de 7 dias');
confere(Cache::$validades[PollingIfood::CHAVE_LIMPEZA] === 86400, 'uma vez por dia');
Banco::inserir('entregas_ifood_eventos', ['evento_id' => 'velho-2', 'merchant_id' => 'm', 'pedido_ifood_id' => 'p', 'codigo' => 'CFM', 'processado_em' => '2026-09-01 07:00:00'], false);
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
pendente('ev-1', 'pedido-antigo', '2026-10-05 14:50:00');
pendente('ev-2', 'pedido-antigo', '2026-10-05 14:51:00', 'CFM');
pendente('ev-3', 'pedido-recente', '2026-10-05 14:59:00');
pendente('ev-4', 'pedido-limite', '2026-10-05 09:00:00');
rodarPolling();
confere(jobs() === ['pedido-limite', 'pedido-antigo'], 'gravados entre 2 min e 6 h atrás: um job por pedido, o mais antigo primeiro; o de 1 min fica');
confere(ProcessarPedidoIfood::PRAZO_MINUTOS * 60 + 300 === 2100, 'prazo do job + 5 min = 35 min');
confere(Cache::$validades[PollingIfood::chaveDaVarredura('pedido-antigo')] === 2100, 'marcado por 35 min (o job vive até 30 min)');

echo '== Varredura: não enfileira de novo enquanto o job pode estar vivo' . PHP_EOL;
Fila::$jobs = [];
rodarPolling();
confere(jobs() === [], 'na rodada seguinte, nada');
Cache::forget(PollingIfood::chaveDaVarredura('pedido-antigo'));
rodarPolling();
confere(jobs() === ['pedido-antigo'], 'marca vencida: enfileira de novo');
confere(Cache::$validades[PollingIfood::chaveDaVarredura('pedido-antigo')] === 7200, 'e a espera cresce: 2 h');
Cache::forget(PollingIfood::chaveDaVarredura('pedido-antigo'));
rodarPolling();
confere(Cache::$validades[PollingIfood::chaveDaVarredura('pedido-antigo')] === 14400, 'depois 4 h');
Cache::forget(PollingIfood::chaveDaVarredura('pedido-antigo'));
rodarPolling();
confere(Cache::$validades[PollingIfood::chaveDaVarredura('pedido-antigo')] === 14400 && count(jobs()) === 3, 'e fica em 4 h');

echo '== Varredura: pendente há mais de 6 h só vai para o log, uma vez' . PHP_EOL;
reiniciarIfood();
pendente('ev-1', 'pedido-velho', '2026-10-05 08:59:59');
pendente('ev-2', 'pedido-velho', '2026-10-05 07:00:00', 'CFM');
pendente('ev-3', 'pedido-de-8-dias', '2026-09-27 07:00:00');
rodarPolling();
rodarPolling();
confere(jobs() === [], 'não enfileira');
confere(vezesNoLog('evento pendente há mais de 6 h') === 1 && logou('evento pendente há mais de 6 h', 'warning'), 'um warning só, em duas rodadas');
confere((contextoDoLog('evento pendente há mais de 6 h')['pedido_ifood'] ?? null) === 'pedido-velho', 'com o id do pedido (o de mais de 7 dias fica de fora)');

echo '== Varredura: até 50 por rodada, sem deixar os outros para trás' . PHP_EOL;
reiniciarIfood();
foreach (range(1, 60) as $i) {
    pendente("ev-{$i}", "pedido-{$i}", '2026-10-05 14:00:00');
}
rodarPolling();
confere(count(jobs()) === 50, '50 na primeira rodada');
$primeiros = jobs();
Fila::$jobs = [];
rodarPolling();
confere(count(jobs()) === 10 && !array_intersect(jobs(), $primeiros), 'os 10 restantes na seguinte');

echo '== Varredura: os de evento mais antigo primeiro' . PHP_EOL;
reiniciarIfood();
// gravados do mais novo (pedido-1, 3 min atrás) para o mais antigo (pedido-60, 62 min atrás); o pedido-5 tem também um
// evento de 5 h30 atrás, que conta como o mais antigo dele
foreach (range(1, 60) as $i) {
    pendente("ev-{$i}", "pedido-{$i}", date('Y-m-d H:i:s', strtotime('2026-10-05 14:57:00') - ($i - 1) * 60));
}
pendente('ev-61', 'pedido-5', '2026-10-05 09:30:00', 'CFM');
rodarPolling();
$esperados = array_merge(['pedido-5'], array_map(fn ($i) => "pedido-{$i}", array_values(array_diff(range(60, 12), [5]))));
confere(jobs() === $esperados, 'na ordem do evento mais antigo de cada pedido; os mais novos ficam para a seguinte');

echo '== Varredura com o polling falhando (5xx, rede) ou em pausa' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
pendente('ev-1', 'pedido-antigo', '2026-10-05 14:50:00');
Http::responder(503, 'fora do ar');
rodarPolling();
confere(logou('polling falhou', 'warning') && jobs() === ['pedido-antigo'], '503: enfileira o pendente antigo mesmo assim');
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
pendente('ev-1', 'pedido-antigo', '2026-10-05 14:50:00');
Http::falharConexao();
rodarPolling();
confere(jobs() === ['pedido-antigo'], 'rede fora: idem');
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
pendente('ev-1', 'pedido-antigo', '2026-10-05 14:50:00');
Cache::put(PollingIfood::chaveDaPausa(1), true, 60);
rodarPolling();
confere(Http::$chamadas === [] && jobs() === ['pedido-antigo'], 'em pausa (429): sem chamar o iFood, mas enfileira');
reiniciarIfood();
pendente('ev-1', 'pedido-antigo', '2026-10-05 14:50:00');
Config::$valores['services.ifood.ativo'] = '';
rodarPolling();
confere(jobs() === [], 'integração desligada: nada');

echo '== Pedido com evento novo e pendente antigo: um job só' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
pendente('ev-0', 'pedido-1', '2026-10-05 14:50:00');
Http::responder(200, [eventoDoIfood('ev-1', 'CFM', 'pedido-1')]);
Http::responder(202);
rodarPolling();
confere(jobs() === ['pedido-1'], 'o lote enfileira; a varredura não repete');
confere(Cache::$validades[PollingIfood::chaveDaVarredura('pedido-1')] === 2100, 'marca do lote = prazo do job + 5 min');
Fila::$jobs = [];
Http::responder(204);
rodarPolling();
confere(jobs() === [], 'nem na rodada seguinte (dentro dos 35 min)');
confere(logsSem(['token-', 'refresh-']), 'nenhum token nos logs');

echo '== Evento sem orderId: ack, sem gravar, com log' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
Http::responder(200, [
    ['id' => 'ev-x', 'code' => 'KEEPALIVE', 'fullCode' => 'KEEPALIVE', 'merchantId' => 'merchant-1', 'metadata' => ['nota' => 'DADO-DO-PAYLOAD']],
    ['id' => 'ev-y', 'fullCode' => 'SEM-CODE', 'orderId' => 'pedido-y', 'merchantId' => 'merchant-1'],
    eventoDoIfood('ev-1', 'PLC', 'pedido-1'),
]);
Http::responder(202);
rodarPolling();
confere((Http::$chamadas[1]['dados'] ?? null) === [['id' => 'ev-x'], ['id' => 'ev-y'], ['id' => 'ev-1']], 'ack de todos, inclusive os sem pedido');
confere(idsDosEventos() === ['ev-1'] && jobs() === ['pedido-1'], 'só o evento com pedido é gravado e enfileirado');
$contexto = contextoDoLog('eventos sem pedido descartados');
confere(logou('[entregas] ifood: eventos sem pedido descartados', 'info') && ($contexto['quantidade'] ?? null) === 2 && ($contexto['codigos'] ?? null) === ['KEEPALIVE'], 'log info com a quantidade e os códigos');
confere(logsSem(['DADO-DO-PAYLOAD', 'pedido-y']), 'sem o payload no log');

echo '== 401 no ack: renova e confirma de novo' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
Http::responder(200, [eventoDoIfood('ev-1', 'PLC', 'pedido-1')]);
Http::responder(401, ['error' => ['code' => 'Unauthorized']]);
Http::responder(200, ['accessToken' => 'token-a2', 'type' => 'bearer', 'expiresIn' => 21600, 'refreshToken' => 'refresh-2']);
Http::responder(202);
rodarPolling();
confere(Http::urls() === ['GET /events/v1.0/events:polling', 'POST /events/v1.0/events/acknowledgment', 'POST /authentication/v1.0/oauth/token', 'POST /events/v1.0/events/acknowledgment'], 'polling, ack recusado, token novo, ack de novo');
confere(Http::$chamadas[3]['token'] === 'token-a2' && Http::$chamadas[3]['dados'] === [['id' => 'ev-1']] && !logou('ack falhou') && jobs() === ['pedido-1'], 'o segundo ack vai com o token novo');

echo '== Erro na fila depois do ack: os outros lotes seguem' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
lojaVinculada('vendor-b', 'merchant-2', 'token-b');
Fila::$falhar = new \RuntimeException('Connection refused [tcp://redis:6379]');
Http::responder(200, [eventoDoIfood('ev-1', 'PLC', 'pedido-1')]);
Http::responder(202);
Http::responder(200, [eventoDoIfood('ev-2', 'PLC', 'pedido-2', 'merchant-2')]);
Http::responder(202);
$saida = rodarPolling();
confere(Http::urls() === ['GET /events/v1.0/events:polling', 'POST /events/v1.0/events/acknowledgment', 'GET /events/v1.0/events:polling', 'POST /events/v1.0/events/acknowledgment'], 'a loja B faz o polling e o ack');
confere(idsDosEventos() === ['ev-1', 'ev-2'] && vezesNoLog('falha ao enfileirar os pedidos do lote') === 2 && logou('falha ao enfileirar', 'error'), 'eventos gravados; um erro no log por lote');
confere(!isset(Cache::$dados[PollingIfood::chaveDaVarredura('pedido-1')]) && $saida === 0, 'sem marca: a varredura enfileira depois');

echo '== Rodada passou de 25 s: não abre lote novo' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
lojaVinculada('vendor-b', 'merchant-2', 'token-b');
lojaVinculada('vendor-c', 'merchant-3', 'token-c');
pendente('ev-0', 'pedido-antigo', '2026-10-05 14:50:00');
PollingComRelogio::$agora = 1000.0;
Http::responderCom(function () {
    PollingComRelogio::$agora += 26;

    return [204, null, []];
});
rodarPolling(null, new PollingComRelogio());
confere(count(Http::$chamadas) === 1 && Http::$chamadas[0]['token'] === 'token-a', 'só o primeiro lote');
confere(logou('rodada passou do tempo', 'warning') && (contextoDoLog('rodada passou do tempo')['lotes_restantes'] ?? null) === 2, 'warning com os lotes restantes');
confere(jobs() === ['pedido-antigo'], 'a varredura roda');
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
lojaVinculada('vendor-b', 'merchant-2', 'token-b');
PollingComRelogio::$agora = 1000.0;
Http::responderCom(function () {
    PollingComRelogio::$agora += 24;

    return [204, null, []];
});
Http::responder(204);
rodarPolling(null, new PollingComRelogio());
confere(count(Http::$chamadas) === 2 && !logou('rodada passou do tempo'), 'com 24 s, o lote seguinte ainda roda');

echo '== 3 falhas temporárias seguidas: encerra os lotes da rodada' . PHP_EOL;
reiniciarIfood();
foreach (['a', 'b', 'c', 'd'] as $i => $letra) {
    lojaVinculada("vendor-{$letra}", 'merchant-' . ($i + 1), "token-{$letra}");
}
pendente('ev-0', 'pedido-antigo', '2026-10-05 14:50:00');
Http::responder(503, 'fora do ar');
Http::falharConexao();
Http::responder(502, 'bad gateway');
$saida = rodarPolling();
confere(count(Http::$chamadas) === 3, 'a loja D fica para a próxima rodada');
confere(logou('falhas temporárias seguidas', 'warning') && (contextoDoLog('falhas temporárias seguidas')['lotes_restantes'] ?? null) === 1, 'warning com os lotes restantes');
confere(jobs() === ['pedido-antigo'] && $saida === 0, 'a varredura roda');
reiniciarIfood();
foreach (['a', 'b', 'c', 'd', 'e'] as $i => $letra) {
    lojaVinculada("vendor-{$letra}", 'merchant-' . ($i + 1), "token-{$letra}");
}
Http::responder(503, 'fora do ar');
Http::responder(204);
Http::responder(503, 'fora do ar');
Http::falharConexao();
Http::responder(204);
rodarPolling();
confere(count(Http::$chamadas) === 5 && !logou('falhas temporárias seguidas'), 'um polling bom no meio zera a contagem');
reiniciarIfood();
foreach (['a', 'b', 'c', 'd'] as $i => $letra) {
    lojaVinculada("vendor-{$letra}", 'merchant-' . ($i + 1), "token-{$letra}");
}
Http::responder(400, ['message' => 'Bad request']);
Http::responder(400, ['message' => 'Bad request']);
Http::responder(400, ['message' => 'Bad request']);
Http::responder(204);
rodarPolling();
confere(count(Http::$chamadas) === 4, '400 não é falha temporária: segue com as outras lojas');

echo '== Erro não tratado na busca: varredura e limpeza rodam' . PHP_EOL;
reiniciarIfood();
pendente('ev-1', 'pedido-antigo', '2026-10-05 14:50:00');
processado('velho', '2026-09-01 07:00:00');
$saida = rodarPolling(new VinculosForaDoAr());
confere($saida === 1 && logou('polling interrompido', 'error'), 'FAILURE e erro no log');
confere(jobs() === ['pedido-antigo'] && idsDosEventos() === ['ev-1'], 'enfileira o pendente e apaga o processado antigo');
confere(logsSem(['Connection refused']), 'sem a mensagem do erro do banco');

echo '== Varredura falhando: a limpeza roda' . PHP_EOL;
// a consulta dos pendentes falha (o dispatch que falha é tratado pedido a pedido, mais acima)
class PollingComVarreduraFalhando extends PollingIfood
{
    protected function pedidosPendentes(string $antesDe, string $desde): array
    {
        throw new \RuntimeException('falha na consulta dos pendentes');
    }
}
reiniciarIfood();
pendente('ev-1', 'pedido-antigo', '2026-10-05 14:50:00');
processado('velho', '2026-09-01 07:00:00');
$saida = rodarPolling(null, new PollingComVarreduraFalhando());
confere($saida === 1 && logou('varredura dos pendentes falhou', 'error'), 'FAILURE e erro no log');
confere(idsDosEventos() === ['ev-1'], 'a limpeza apagou o processado antigo');

echo '== Limpeza falhando: a marca do dia fica para depois' . PHP_EOL;
reiniciarIfood();
processado('velho', '2026-09-01 07:00:00');
Banco::$falhar['entregas_ifood_eventos'] = 'MySQL server has gone away';
$saida = rodarPolling();
confere($saida === 1 && logou('limpeza dos eventos antigos falhou', 'error') && !isset(Cache::$dados[PollingIfood::CHAVE_LIMPEZA]), 'erro no log, sem a marca do dia');
unset(Banco::$falhar['entregas_ifood_eventos']);
rodarPolling();
confere(idsDosEventos() === [] && isset(Cache::$dados[PollingIfood::CHAVE_LIMPEZA]), 'na rodada seguinte, apaga e marca');

echo '== Cursor: depois do teto de 25 s, a rodada seguinte começa pelo lote não atendido' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
lojaVinculada('vendor-b', 'merchant-2', 'token-b');
lojaVinculada('vendor-c', 'merchant-3', 'token-c');
$cadaChamadaLeva26s = function () {
    PollingComRelogio::$agora += 26;

    return [204, null, []];
};
$primeirosTokens = [];
foreach (range(1, 4) as $rodada) {
    PollingComRelogio::$agora = 1000.0;
    Http::responderCom($cadaChamadaLeva26s);
    rodarPolling(null, new PollingComRelogio());
    $primeirosTokens[] = end(Http::$chamadas)['token'];
}
confere($primeirosTokens === ['token-a', 'token-b', 'token-c', 'token-a'], 'cada rodada começa pelo primeiro lote não atendido na anterior; passou do fim, volta ao primeiro');
confere(Cache::get(PollingIfood::CHAVE_CURSOR) === 1, 'cursor no cache = índice do próximo lote');
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
lojaVinculada('vendor-b', 'merchant-2', 'token-b');
Http::responder(204);
Http::responder(204);
rodarPolling();
Http::responder(204);
Http::responder(204);
rodarPolling();
confere(array_column(Http::$chamadas, 'token') === ['token-a', 'token-b', 'token-a', 'token-b'], 'todos atendidos: a rodada seguinte começa do início');

echo '== Cursor: 3 falhas seguidas também avançam o ponto de partida' . PHP_EOL;
reiniciarIfood();
foreach (['a', 'b', 'c', 'd'] as $i => $letra) {
    lojaVinculada("vendor-{$letra}", 'merchant-' . ($i + 1), "token-{$letra}");
}
Http::responder(503, 'fora do ar');
Http::responder(503, 'fora do ar');
Http::responder(503, 'fora do ar');
rodarPolling();
foreach (range(1, 4) as $i) {
    Http::responder(204);
}
rodarPolling();
confere(array_column(array_slice(Http::$chamadas, 3), 'token') === ['token-d', 'token-a', 'token-b', 'token-c'], 'a rodada seguinte começa pela loja D');

echo '== Token pedido dentro do laço dos lotes, sob o teto de 25 s' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
lojaVinculada('vendor-b', 'merchant-2', 'token-b', '2026-10-05 15:02:00');
lojaVinculada('vendor-c', 'merchant-3', 'token-c', '2026-10-05 15:02:00');
PollingComRelogio::$agora = 1000.0;
Http::responderCom($cadaChamadaLeva26s);
rodarPolling(null, new PollingComRelogio());
confere(Http::urls() === ['GET /events/v1.0/events:polling'], 'as lojas que ficaram para a próxima rodada não renovam o token nesta');
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a', '2026-10-05 15:02:00');
lojaVinculada('vendor-b', 'merchant-2', 'token-b');
Cache::put(PollingIfood::chaveDaPausa(1), true, 60);
Http::responder(204);
rodarPolling();
confere(Http::urls() === ['GET /events/v1.0/events:polling'] && Http::$chamadas[0]['token'] === 'token-b', 'loja em pausa: nem renova o token');

echo '== Varredura: dispatch que falha tira a marca e não para os outros pedidos' . PHP_EOL;
class PollingComFilaFalhando extends PollingIfood
{
    public static array $falharPara = [];

    protected function despachar(string $pedido): void
    {
        if (in_array($pedido, static::$falharPara, true)) {
            throw new \RuntimeException('Connection refused [tcp://redis:6379]');
        }
        parent::despachar($pedido);
    }
}
reiniciarIfood();
pendente('ev-1', 'pedido-1', '2026-10-05 14:50:00');
pendente('ev-2', 'pedido-2', '2026-10-05 14:51:00');
pendente('ev-3', 'pedido-3', '2026-10-05 14:52:00');
PollingComFilaFalhando::$falharPara = ['pedido-2'];
$saida = rodarPolling(null, new PollingComFilaFalhando());
confere(jobs() === ['pedido-1', 'pedido-3'], 'os outros pedidos da rodada são enfileirados');
confere(!isset(Cache::$dados[PollingIfood::chaveDaVarredura('pedido-2')]) && !isset(Cache::$dados[PollingIfood::chaveDasVezesDaVarredura('pedido-2')]), 'o pedido que falhou fica sem marca e sem contar a vez');
confere(logou('falha ao enfileirar pedidos da varredura', 'error') && (contextoDoLog('falha ao enfileirar pedidos da varredura')['quantidade'] ?? null) === 1, 'erro no log com a quantidade');
confere($saida === 0 && !logou('varredura dos pendentes falhou'), 'a varredura não é interrompida');
PollingComFilaFalhando::$falharPara = [];
Fila::$jobs = [];
rodarPolling(null, new PollingComFilaFalhando());
confere(jobs() === ['pedido-2'], 'na rodada seguinte, o pedido que falhou é enfileirado');

echo '== Erro ao tratar o 403: os outros lotes seguem' . PHP_EOL;
class VinculosQueNaoPerdem extends VinculosIfood
{
    public function __construct()
    {
        parent::__construct(new ClienteIfood());
    }

    public function perderPorMerchant(string $merchantId, int $status): void
    {
        throw new Teste\ErroDeBanco("SQLSTATE[HY000]: General error (SQL: update x set merchant_id = 'merchant-1')");
    }
}
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
lojaVinculada('vendor-b', 'merchant-2', 'token-b');
Http::responder(403, ['unauthorizedMerchants' => ['merchant-1']]);
Http::responder(204);
$saida = rodarPolling(new VinculosQueNaoPerdem());
confere(count(Http::$chamadas) === 2 && Http::$chamadas[1]['token'] === 'token-b' && $saida === 0, 'a loja B faz o polling; a rodada não cai');
confere(logou('falha ao tratar o 403', 'error') && (contextoDoLog('falha ao tratar o 403')['erro'] ?? null) === 'Teste\ErroDeBanco', 'erro no log, só com a classe');
confere((Cache::$validades[PollingIfood::chaveDaPausa(1)] ?? null) === 300 && logsSem(['SQL']), 'lote em pausa por 5 min; nada do SQL no log');

echo '== 429 no ack: pausa as lojas do token' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
lojaVinculada('vendor-b', 'merchant-2', 'token-b');
Http::responder(200, [eventoDoIfood('ev-1', 'PLC', 'pedido-1')]);
Http::responder(429, ['message' => 'Too Many Requests'], ['Retry-After' => '40']);
Http::responder(204);
rodarPolling();
confere(Http::urls() === ['GET /events/v1.0/events:polling', 'POST /events/v1.0/events/acknowledgment', 'GET /events/v1.0/events:polling'] && Http::$chamadas[2]['token'] === 'token-b', 'a loja B (outro token) segue');
confere(idsDosEventos() === ['ev-1'] && jobs() === ['pedido-1'], 'o evento fica gravado e enfileirado');
confere((Cache::$validades[PollingIfood::chaveDaPausa(1)] ?? null) === 40 && !isset(Cache::$dados[PollingIfood::chaveDaPausa(2)]), 'só a loja A em pausa, pelo Retry-After');
confere(logou('429 no ack, esperando 40 s', 'warning'), 'warning no log');
reiniciarIfood();
foreach (range(1, 101) as $i) {
    lojaVinculada("vendor-{$i}", "merchant-{$i}", 'token-unico');
}
Http::responder(200, [eventoDoIfood('ev-1', 'PLC', 'pedido-1')]);
Http::responder(429, null, ['Retry-After' => '25']);
rodarPolling();
confere(count(Http::$chamadas) === 2 && (Cache::$validades[PollingIfood::chaveDaPausa(101)] ?? null) === 25, 'o segundo lote do mesmo token fica de fora e em pausa');

echo '== Evento sem id: descartado, contado no log, sem payload' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
Http::responder(200, [
    ['code' => 'PLC', 'orderId' => 'pedido-sem-id', 'merchantId' => 'merchant-1', 'metadata' => ['nota' => 'DADO-DO-PAYLOAD']],
    ['id' => '', 'code' => 'CFM', 'orderId' => 'pedido-sem-id', 'merchantId' => 'merchant-1'],
    eventoDoIfood('ev-1', 'PLC', 'pedido-1'),
]);
Http::responder(202);
rodarPolling();
confere((Http::$chamadas[1]['dados'] ?? null) === [['id' => 'ev-1']] && idsDosEventos() === ['ev-1'], 'ack e gravação só do evento com id');
$contexto = contextoDoLog('eventos sem id descartados');
confere(($contexto['quantidade'] ?? null) === 2 && ($contexto['codigos'] ?? null) === ['PLC', 'CFM'], 'log com a quantidade e os códigos');
confere(logsSem(['DADO-DO-PAYLOAD', 'pedido-sem-id']), 'sem o payload no log');

echo '== Relógio monotônico' . PHP_EOL;
class PollingRelogioReal extends PollingIfood
{
    public function agora(): float
    {
        return $this->relogio();
    }
}
confere(abs((new PollingRelogioReal())->agora() - hrtime(true) / 1e9) < 1, 'relogio() = hrtime(true) / 1e9');

echo '== Limpeza: pendentes há mais de 30 dias' . PHP_EOL;
reiniciarIfood();
pendente('ev-31', 'pedido-31', '2026-09-04 14:00:00');
pendente('ev-25', 'pedido-25', '2026-09-10 14:00:00');
rodarPolling();
confere(idsDosEventos() === ['ev-25'], 'apaga o pendente de 31 dias; o de 25 fica');
confere(logou('pendentes há mais de 30 dias apagados', 'warning') && (contextoDoLog('pendentes há mais de 30 dias')['quantidade'] ?? null) === 1, 'warning com a quantidade');

resumo();
