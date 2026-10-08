<?php

// Trava "Atualize o app" do motoboy: a versão publicada no MinIO (AppDoMotoboy), a versão mínima fixada à mão, o
// middleware ExigirAppAtualizado (426 para o app desatualizado, exceções) e a fila de ofertas (podeReceberOferta).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/app-do-motoboy.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Http\Middleware\ExigirAppAtualizado;
use App\Support\Entregas\AppDoMotoboy;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Teste\Config;
use Teste\Http;

const URL_DO_JSON = 'https://s3arquivos.restaurantepro.com.br/entregas/APK/entregas-motoboy.json';
const APK_40      = 'https://s3arquivos.restaurantepro.com.br/entregas/APK/entregas-motoboy-40.apk';
const ACEITE      = 'Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController@startOrder';
const VERSAO      = 'App\Http\Controllers\Entregas\MotoboyController@app';
const OUTRA       = 'Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController@query';

/** O middleware com a consulta de pedido em andamento trocada (a do banco é a SituacaoDoMotoboy, testada no mapa). */
class ExigirAppDoTeste extends ExigirAppAtualizado
{
    public static bool $emAndamento = false;

    protected function temPedidoEmAndamento(Driver $motoboy): bool
    {
        return static::$emAndamento;
    }
}

function cenario(bool $trava = true, ?int $fixada = null): void
{
    reiniciarFleetbase();
    reiniciarIfood();
    Config::$valores['services.entregas.app_trava']         = $trava ? '1' : '';
    Config::$valores['services.entregas.app_versao_url']    = URL_DO_JSON;
    Config::$valores['services.entregas.app_versao_minima'] = $fixada;
    ExigirAppDoTeste::$emAndamento = false;
    session(['company' => 'empresa-1', 'user' => 'u-a']);
    Driver::$todos[] = new Driver(['uuid' => 'd-a', 'public_id' => 'driver_a', 'company_uuid' => 'empresa-1', 'user_uuid' => 'u-a', 'name' => 'A']);
    Order::$todos[]  = new Order(['uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1', 'status' => 'dispatched', 'adhoc' => false, 'driver_assigned_uuid' => 'd-a']);
    Order::$todos[]  = new Order(['uuid' => 'order-2', 'public_id' => 'order_2', 'company_uuid' => 'empresa-1', 'status' => 'dispatched', 'adhoc' => true, 'driver_assigned_uuid' => null]);
}

/** Publicado no MinIO: a versão 40. */
function publicado(int $versao = 40): void
{
    Http::responder(200, ['versao' => $versao, 'url' => "https://s3arquivos.restaurantepro.com.br/entregas/APK/entregas-motoboy-{$versao}.apk"]);
}

/** Roda o middleware: $versao = o cabeçalho X-Entregas-App (null = sem ele). Devolve a resposta ou 'passou'. */
function chamar(?string $versao, string $acao = OUTRA, string $token = '12|token-do-motoboy', string $pedido = 'order_9')
{
    $request        = new Request();
    $request->token = $token;
    $request->rota  = new Route($acao, ['id' => $pedido]);
    if ($versao !== null) {
        $request->cabecalhos['x-entregas-app'] = $versao;
    }

    return (new ExigirAppDoTeste())->handle($request, fn () => 'passou');
}

echo '== Versão publicada no MinIO' . PHP_EOL;
cenario();
publicado();
confere(AppDoMotoboy::minima() === ['versao' => 40, 'url' => APK_40], 'lê o JSON {versao, url}');
confere(Http::$chamadas[0]['url'] === URL_DO_JSON && Http::$chamadas[0]['timeout'] === 3, 'no endereço configurado, com timeout de 3 s');
confere(AppDoMotoboy::minima()['versao'] === 40 && count(Http::$chamadas) === 1, 'cache de 1 min: a segunda leitura não vai ao MinIO');
Cache::forget(AppDoMotoboy::CACHE_PUBLICADA);
Http::falharConexao();
confere(AppDoMotoboy::minima()['versao'] === 40, 'MinIO fora do ar: vale a última lida');
confere(count(array_filter(Log::$registros, fn ($l) => str_contains($l[1], 'falha ao ler a versão publicada'))) === 1, 'e fica no log');

cenario();
Http::falharConexao();
confere(AppDoMotoboy::minima() === null && !AppDoMotoboy::desatualizado(null), 'nada lido nunca: sem versão mínima, ninguém é barrado');
cenario();
Http::responder(200, ['versao' => 41, 'url' => 'http://inseguro/apk']);
confere(AppDoMotoboy::minima() === null, 'link sem https: ignorado');
cenario();
Http::responder(200, ['versao' => 'abc', 'url' => APK_40]);
confere(AppDoMotoboy::minima() === null, 'versão ilegível: ignorada');
cenario();
Http::responder(404, '<Error>NoSuchKey</Error>');
confere(AppDoMotoboy::minima() === null, '404 do MinIO (antes do primeiro upload): sem trava');

echo '== Versão fixada à mão (emergência)' . PHP_EOL;
cenario(true, 38);
confere(AppDoMotoboy::minima() === ['versao' => 38, 'url' => 'https://s3arquivos.restaurantepro.com.br/entregas/APK/entregas-motoboy-38.apk'], 'ENTREGAS_APP_VERSAO_MINIMA manda, com o link do mesmo número na pasta do JSON');
confere(Http::$chamadas === [], 'e nem consulta o MinIO');

echo '== Trava desligada' . PHP_EOL;
cenario(false);
confere(chamar(null) === 'passou' && chamar('3') === 'passou', 'qualquer versão passa');
confere(Http::$chamadas === [], 'sem consultar o MinIO');
confere(AppDoMotoboy::versaoDoUsuario('u-a') === 3, 'mas registra a versão do usuário (fila de ofertas pronta quando a trava ligar)');

echo '== Trava ligada' . PHP_EOL;
cenario();
publicado();
confere(chamar('40') === 'passou' && chamar('41') === 'passou', 'versão mínima ou acima: passa');
Log::$registros = [];
$resposta       = chamar('39');
confere($resposta->status === 426 && $resposta->dados['errors'] === ['Atualize o app para continuar.'], 'abaixo da mínima: 426 "Atualize o app para continuar."');
confere(($resposta->dados['entregas_app'] ?? null) === ['trava' => true, 'versao' => 39, 'versao_minima' => 40, 'url' => APK_40, 'desatualizado' => true], 'com a versão mínima e o link do APK (' . json_encode($resposta->dados['entregas_app'] ?? null) . ')');
confere(chamar(null)->status === 426, 'sem o cabeçalho (APK 34 e anteriores): 426');
confere(chamar('lixo')->status === 426, 'cabeçalho ilegível: 426');
confere(count(array_filter(Log::$registros, fn ($l) => str_contains($l[1], 'chamada barrada'))) === 1, 'um log por motoboy a cada 10 min');
confere(chamar('39', VERSAO) === 'passou', 'a rota da versão (MotoboyController@app) nunca é barrada');
confere(chamar(null, OUTRA, 'flb_live_chave-do-apk') === 'passou', 'chave de API (sem "|"): passa');
session(['user' => 'u-sem-cadastro']);
confere(chamar('39') === 'passou', 'usuário sem cadastro de motoboy: passa');

echo '== Pedido em andamento: termina a entrega' . PHP_EOL;
cenario();
publicado();
ExigirAppDoTeste::$emAndamento = true;
confere(chamar('39') === 'passou' && chamar(null) === 'passou', 'com pedido em andamento, as chamadas passam (concluir, código do iFood, posição)');
confere(chamar('39', ACEITE, '12|t', 'order_1') === 'passou', 'aceite do pedido que a central passou para ele: passa');
confere(chamar('39', ACEITE, '12|t', 'order_2')->status === 426, 'aceite de pedido aberto: 426');
confere(chamar('39', ACEITE, '12|t', 'order_x')->status === 426, 'aceite de pedido que não existe: 426');

echo '== Fila de ofertas' . PHP_EOL;
cenario();
publicado();
AppDoMotoboy::registrar('u-novo', 40);
AppDoMotoboy::registrar('u-velho', 39);
confere(AppDoMotoboy::podeReceberOferta('u-novo') === true, 'app na versão mínima: recebe oferta');
confere(AppDoMotoboy::podeReceberOferta('u-velho') === false, 'app abaixo: não recebe');
confere(AppDoMotoboy::podeReceberOferta('u-sem-registro') === false, 'sem versão registrada (APK antigo): não recebe');
Config::$valores['services.entregas.app_trava'] = '';
confere(AppDoMotoboy::podeReceberOferta('u-velho') === true && AppDoMotoboy::podeReceberOferta(null) === true, 'trava desligada: todos recebem');

echo '== Situação para o app' . PHP_EOL;
cenario();
publicado();
confere(AppDoMotoboy::situacao(40) === ['trava' => true, 'versao' => 40, 'versao_minima' => 40, 'url' => APK_40, 'desatualizado' => false], 'em dia');
confere(AppDoMotoboy::situacao(null)['desatualizado'] === true, 'sem versão: desatualizado');

resumo();
