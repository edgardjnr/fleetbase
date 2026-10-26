<?php

// Comanda do pedido na folha do console (GET int/v1/entregas/pedidos/{id}/comanda): PedidoAbertoController@comanda e
// Painel\ComandaDaCentral. iFood, integração, pedido sem linha (portal/central), nome do cliente, valores só para admin,
// 404 de outra empresa, concluído, cancelado, destino sem coordenada, falhas da rota e do valor, e a proteção do portal.
// Uso: PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/comanda-do-pedido.php

require __DIR__ . '/stubs-financeiro.php';

use App\Http\Controllers\Entregas\PedidoAbertoController;
use App\Http\Middleware\BarrarMotoboyNoConsole;
use App\Http\Middleware\ProtegerPortalLoja;
use App\Support\Entregas\CalculoEntregas;
use App\Support\Entregas\Ifood\CobrancaIfood;
use App\Support\Entregas\Integracao\CobrancaDaIntegracao;
use App\Support\Entregas\Painel\ComandaDaCentral;
use App\Support\Entregas\RotaDoPedido;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Teste\Banco;

class UsuarioDaComanda
{
    public function __construct(public string $uuid, public bool $admin) {}
    public function isNotAdmin(): bool { return !$this->admin; }
}

/** A rota do mapa (RotaDoPedido) sem OSRM nem cache: devolve $resposta ou lança $falha. */
class RotaFalsa extends RotaDoPedido
{
    public int $chamadas = 0;
    public ?\Throwable $falha = null;
    public function __construct(public ?array $resposta = null) {}
    public function doPedido(Order $pedido): ?array
    {
        $this->chamadas++;
        if ($this->falha) {
            throw $this->falha;
        }

        return $this->resposta;
    }
}

/** O CalculoEntregas sem banco: guarda o motoboy pedido e devolve $resposta ou lança $falha. */
class CalculoFalso extends CalculoEntregas
{
    public array $chamadas = [];
    public ?\Throwable $falha = null;
    public function __construct(public array $resposta = []) {}
    public function valorDoPedido(Order $pedido, ?string $motoboyUuid = null): array
    {
        $this->chamadas[] = [$pedido->public_id, $motoboyUuid];
        if ($this->falha) {
            throw $this->falha;
        }

        return $this->resposta;
    }
}

const ROTA = ['linha' => [[-21.1775, -47.8103], [-21.18, -47.81]], 'metros' => 3200, 'segundos' => 540, 'aproximado' => false];
const VALOR = [
    'km' => 3.2, 'fonte' => 'osrm', 'faixa' => ['de_km' => 3.0, 'ate_km' => 4.0, 'motoboy' => 8.0, 'loja' => 12.0], 'valor_motoboy' => 9.6,
    'posicao' => 1, 'tabela' => 'normal', 'multiplicador' => ['nome' => 'Chuva', 'motoboy' => 1.2, 'loja' => 1.5],
];
const ITENS = '[{"quantidade":2,"nome":"Pizza Calabresa","opcoes":[{"quantidade":1,"nome":"Borda recheada"}],"observacao":"sem cebola"}]';

/** A loja A (stubs), o cliente Mariana, o motoboy Carlos e o pedido order_1 (a caminho), com o rastreio e outro pedido em outra empresa. */
function cenario(array $order = [], array $destino = []): Order
{
    reiniciarFinanceiro();
    session(['company' => 'empresa-1', 'user' => 'user-admin']);
    \Teste\Relogio::$agora = '2026-10-24 21:00:00';

    $coleta          = Place::$todos[0]; // place-loja, dono vendor-a
    $coleta->address = 'AV. BRASIL, 500 - CENTRO';
    $coleta->street1 = 'AV. BRASIL, 500';
    $coleta->phone   = '+551633330000';
    Vendor::$todos[0]->phone = '+551633331111';

    $cliente = new Place($destino + [
        'uuid' => 'place-cliente', 'company_uuid' => 'empresa-1', 'name' => 'MARIANA SOUZA', 'street1' => 'RUA DAS FLORES, 123',
        'street2' => 'APTO 12 · Ref.: PERTO DA PRAÇA', 'neighborhood' => 'JARDIM PAULISTA', 'city' => 'RIBEIRÃO PRETO',
        'address' => 'RUA DAS FLORES, 123 - JARDIM PAULISTA', 'location' => new Point(-21.18, -47.81),
    ]);
    $payload          = new Payload();
    $payload->pickup  = $coleta;
    $payload->dropoff = $cliente;

    $motoboy         = new Driver(['uuid' => 'driver-1', 'public_id' => 'driver_1', 'company_uuid' => 'empresa-1', 'name' => 'Carlos Motoboy', 'phone' => '+5516999990000']);
    Driver::$todos[] = $motoboy;

    $pedido = new Order($order + [
        'uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1', 'status' => 'driver_enroute', 'started' => true,
        'driver_assigned_uuid' => 'driver-1', 'customer_uuid' => 'vendor-a', 'internal_id' => null, 'tracking_number_uuid' => 'tn-1',
        'payload' => $payload, 'driverAssigned' => $motoboy, 'trackingNumber' => (object) ['tracking_number' => 'RP123456'],
        'created_at' => Carbon::parse('2026-10-24 20:30:00'), 'updated_at' => Carbon::parse('2026-10-24 20:55:00'),
        'meta' => ['entregas_prazo_em' => '2026-10-24T21:20:00-03:00'],
    ]);
    Order::$todos[] = $pedido;
    Order::$todos[] = new Order(['uuid' => 'order-9', 'public_id' => 'order_9', 'company_uuid' => 'empresa-2', 'status' => 'created', 'payload' => $payload]);

    $status = fn ($code, $texto, $quando, $numero = 'tn-1', $apagado = null) => Banco::inserir('tracking_statuses', [
        'tracking_number_uuid' => $numero, 'code' => $code, 'status' => $texto, 'details' => $texto . ' (detalhe)', 'created_at' => $quando, 'deleted_at' => $apagado,
    ], false);
    $status('DRIVER_ENROUTE', 'Driver enroute', '2026-10-24 20:52:00');
    $status('CREATED', 'Order created', '2026-10-24 20:30:05');
    $status('DISPATCHED', 'Order dispatched', '2026-10-24 20:31:00');
    $status('STARTED', 'Order started', '2026-10-24 20:40:00', 'tn-1', '2026-10-24 20:41:00'); // apagado
    $status('CREATED', 'Order created', '2026-10-24 19:00:00', 'tn-outro');                     // outro pedido

    return $pedido;
}

function linhaIfood(array $linha = []): void
{
    Banco::inserir('entregas_ifood_pedidos', $linha + [
        'company_uuid' => 'empresa-1', 'order_uuid' => 'order-1', 'pedido_ifood_id' => 'pedido-real-1', 'numero' => '4821', 'merchant_id' => 'merchant-1',
        'teste' => true, 'cobrar_centavos' => 5890, 'forma_pagamento' => 'CASH', 'troco_para_centavos' => 10000, 'observacoes' => 'Sem cebola',
        'complemento' => 'Apto 12', 'referencia' => 'Perto da praça', 'telefone_0800' => '08007000000', 'localizador' => '12345678',
        'telefone_expira_em' => '2026-10-24 23:00:00', 'itens' => ITENS, 'created_at' => '2026-10-24 20:30:00', 'updated_at' => '2026-10-24 20:30:00',
    ], false);
}

function linhaIntegracao(array $linha = []): void
{
    Banco::inserir('entregas_integracao_pedidos', $linha + [
        'company_uuid' => 'empresa-1', 'vendor_uuid' => 'vendor-a', 'order_uuid' => 'order-1', 'pedido_externo_id' => 'rp-570671', 'sistema' => 'RestaurantePro',
        'numero' => 'RP570671', 'cobranca_forma' => 'dinheiro', 'cobranca_centavos' => 4500, 'troco_para_centavos' => 5000,
        'observacoes' => 'Tocar o interfone', 'complemento' => 'Casa 2', 'referencia' => null, 'cliente_telefone' => '+5516988887777',
        'itens' => ITENS, 'created_at' => '2026-10-24 20:30:00', 'updated_at' => '2026-10-24 20:30:00',
    ], false);
}

function comoUsuario(bool $admin): Request
{
    \Fleetbase\Support\Auth::$usuario = new UsuarioDaComanda($admin ? 'user-admin' : 'user-comum', $admin);

    return new Request();
}

function comanda(string $id, bool $admin = true, ?RotaFalsa $rota = null, ?CalculoFalso $calculo = null)
{
    return (new PedidoAbertoController())->comanda(comoUsuario($admin), $id, $rota ?? new RotaFalsa(ROTA), $calculo ?? new CalculoFalso(VALOR));
}

function json($dados): string
{
    return json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

echo '== Pedido do iFood (admin)' . PHP_EOL;
cenario(['internal_id' => '4821']);
linhaIfood();
$calculo = new CalculoFalso(VALOR);
$r       = comanda('order_1', true, null, $calculo);
$d       = $r->dados;
confere($r->status === 200, '200');
confere($d['origem'] === 'ifood' && $d['sistema'] === null && $d['numero'] === '4821' && $d['teste'] === true, 'origem iFood, número do iFood e pedido de teste');
confere($d['cobranca'] === ['texto' => CobrancaIfood::texto(5890, 'CASH', 10000), 'centavos' => 5890, 'forma' => CobrancaIfood::forma('CASH'), 'troco_para_centavos' => 10000] && $d['cobranca']['texto'] !== null, 'cobrança igual à do app (CobrancaIfood)');
confere($d['observacoes'] === 'Sem cebola', 'observação do cliente');
confere(($d['itens'][0]['nome'] ?? null) === 'Pizza Calabresa' && $d['itens'][0]['quantidade'] === 2 && $d['itens'][0]['opcoes'][0]['nome'] === 'Borda recheada', 'produtos pelo ItensDoPedido');
confere($d['cliente'] === ['nome' => 'MARIANA SOUZA', 'endereco' => 'RUA DAS FLORES, 123', 'bairro' => 'JARDIM PAULISTA', 'cidade' => 'RIBEIRÃO PRETO', 'complemento' => 'Apto 12', 'referencia' => 'Perto da praça'], 'cliente: nome cru, rua com número, bairro, cidade, complemento e referência da linha do iFood (não o street2)');
confere(!str_contains(json($d), '08007000000') && !str_contains(json($d), '12345678') && !str_contains(json($d), 'localizador') && !str_contains(json($d), 'telefone_0800'), 'sem o 0800 e sem o localizador do iFood');
confere(!str_contains(json($d), '+5516999990000'), 'sem o telefone do motoboy');
confere($d['loja'] === ['nome' => 'Pizzaria Ficticia', 'endereco' => 'AV. BRASIL, 500 - CENTRO', 'telefone' => '+551633331111'], 'loja: o Vendor dono, endereço do Local de coleta e o telefone da loja');
confere($d['motoboy'] === ['id' => 'driver_1', 'nome' => 'Carlos Motoboy'], 'motoboy: public_id e nome');
$p = $d['pedido'];
confere($p['id'] === 'order_1' && $p['numero'] === '4821' && $p['latitude'] === -21.18 && $p['longitude'] === -47.81 && $p['motoboy_id'] === 'driver_1', 'pedido no formato do umDoLider (id, número, destino, motoboy_id)');
confere($p['loja'] === 'Pizzaria Ficticia' && $p['coleta'] === ['latitude' => -21.1775, 'longitude' => -47.8103] && $p['prazo_em'] === '2026-10-24T21:20:00-03:00', 'pedido com loja, coleta e prazo');
confere($p['criado_em'] === '2026-10-24T20:30:00-03:00' && $p['atualizado_em'] === '2026-10-24T20:55:00-03:00' && $p['aceito'] === true && $p['status'] === 'driver_enroute', 'pedido com as datas em Brasília, aceito e status');
confere(array_column($d['linha_do_tempo'], 'codigo') === ['CREATED', 'DISPATCHED', 'DRIVER_ENROUTE'], 'linha do tempo do mais antigo ao mais novo, sem o apagado e sem o de outro pedido');
confere($d['linha_do_tempo'][0] === ['codigo' => 'CREATED', 'texto' => 'Order created', 'detalhes' => 'Order created (detalhe)', 'em' => '2026-10-24T20:30:05-03:00'], 'item da linha do tempo com a hora em ISO de Brasília');
confere($d['rota'] === ROTA, 'rota do RotaDoPedido');
confere($d['valores'] === ['km' => 3.2, 'aproximado' => false, 'faixa' => ['de_km' => 3.0, 'ate_km' => 4.0, 'acima' => false], 'valor_motoboy' => 9.6, 'valor_loja' => 18.0, 'junto' => null, 'multiplicador' => ['nome' => 'Chuva', 'motoboy' => 1.2, 'loja' => 1.5]], 'admin: valores com o da loja = faixa × multiplicador da loja');
confere($calculo->chamadas === [['order_1', 'driver-1']], 'valor calculado para o motoboy do pedido');
confere($d['encerrado'] === false && $d['situacao_final'] === null && $d['conclusao'] === null, 'em andamento: não encerrado, sem conclusão');
confere(comanda('order-1')->status === 200, 'acha também pelo uuid');

echo '== Não admin' . PHP_EOL;
cenario(['internal_id' => '4821']);
linhaIfood();
$calculo = new CalculoFalso(VALOR);
$r       = comanda('order_1', false, null, $calculo);
confere($r->status === 200 && $r->dados['valores'] === null && $calculo->chamadas === [], 'não admin: valores null, sem calcular');
confere($r->dados['cobranca']['centavos'] === 5890 && $r->dados['itens'] !== null, 'não admin lê a cobrança e os produtos');

echo '== Integração' . PHP_EOL;
cenario(['internal_id' => 'RP570671']);
linhaIntegracao();
$d = comanda('order_1')->dados;
confere($d['origem'] === 'integracao' && $d['sistema'] === 'RestaurantePro' && $d['numero'] === 'RP570671' && $d['teste'] === false, 'origem integração com o sistema e o número');
confere($d['cobranca'] === ['texto' => CobrancaDaIntegracao::texto('dinheiro', 4500, 5000), 'centavos' => 4500, 'forma' => CobrancaDaIntegracao::forma('dinheiro'), 'troco_para_centavos' => 5000], 'cobrança igual à do app (CobrancaDaIntegracao)');
confere($d['observacoes'] === 'Tocar o interfone' && $d['cliente']['complemento'] === 'Casa 2' && $d['cliente']['referencia'] === null, 'observação e complemento da integração');
confere(count($d['itens'] ?? []) === 1, 'produtos da integração');
confere(!str_contains(json($d), '+5516988887777'), 'sem o telefone do cliente da integração');

echo '== Pedido do portal ou da central (sem linha)' . PHP_EOL;
cenario([], ['name' => 'JOÃO', 'street2' => 'FUNDOS']);
$d = comanda('order_1')->dados;
confere($d['origem'] === null && $d['sistema'] === null && $d['teste'] === false, 'sem origem');
confere($d['cobranca'] === null && $d['itens'] === null && $d['observacoes'] === null, 'sem cobrança, produtos e observação');
confere($d['numero'] === 'RP123456' && $d['pedido']['numero'] === 'RP123456', 'sem internal_id: o número de rastreio');
confere($d['cliente']['nome'] === 'JOÃO' && $d['cliente']['complemento'] === 'FUNDOS' && $d['cliente']['referencia'] === null, 'complemento do street2 do Local');

echo '== Nome do cliente (sem nome = null)' . PHP_EOL;
$local = fn (array $campos) => (object) ($campos + ['street1' => 'RUA DAS FLORES, 123', 'address' => 'RUA DAS FLORES, 123 - CENTRO']);
confere(ComandaDaCentral::nomeDoCliente($local(['name' => 'CLIENTE IFOOD'])) === null, '"CLIENTE IFOOD" = sem nome');
confere(ComandaDaCentral::nomeDoCliente($local(['name' => 'Cliente iFood'])) === null, '"Cliente iFood" = sem nome');
confere(ComandaDaCentral::nomeDoCliente($local(['name' => '   '])) === null, 'nome vazio = sem nome');
confere(ComandaDaCentral::nomeDoCliente($local(['name' => null])) === null, 'nome nulo = sem nome');
confere(ComandaDaCentral::nomeDoCliente($local(['name' => 'rua das flores, 123'])) === null, 'nome igual à rua = sem nome');
confere(ComandaDaCentral::nomeDoCliente($local(['name' => 'RUA DAS FLORES'])) === null, 'rua que começa com o nome = sem nome');
confere(ComandaDaCentral::nomeDoCliente($local(['name' => ' Mariana Souza '])) === 'Mariana Souza', 'nome de verdade, cru e sem espaços sobrando');
confere(ComandaDaCentral::nomeDoCliente(null) === null, 'sem Local = sem nome');
confere(ComandaDaCentral::nomeDoCliente($local(['name' => 'CAIO DINIZ SALLES', 'street1' => 'R. RUBEM UBIDA, 150', 'address' => 'CAIO DINIZ SALLES - R. RUBEM UBIDA, 150, APTO 72, RIBEIRÃO PRETO, SP, 14021575, BRAZIL'])) === 'CAIO DINIZ SALLES', 'address "NOME - RUA" (como o Fleetbase monta) mantém o nome');
confere(ComandaDaCentral::nomeDoCliente($local(['name' => 'R. RUBEM UBIDA, 150', 'street1' => 'R. RUBEM UBIDA, 150', 'address' => 'R. RUBEM UBIDA, 150 - R. RUBEM UBIDA, 150, BRAZIL'])) === null, 'nome igual à rua continua sem nome, mesmo com o address "NOME - RUA"');
cenario([], ['name' => 'CLIENTE IFOOD']);
confere(comanda('order_1')->dados['cliente']['nome'] === null, 'na resposta: "CLIENTE IFOOD" vira null');

echo '== Outra empresa e inexistente' . PHP_EOL;
cenario();
$r = comanda('order_9');
confere($r->status === 404 && $r->dados === ['errors' => ['Pedido não encontrado.']], 'pedido de outra empresa: 404');
confere(comanda('order_nao_existe')->status === 404, 'pedido inexistente: 404');
session(['company' => 'empresa-2']);
confere(comanda('order_1')->status === 404, 'sessão de outra empresa não lê o pedido da empresa-1');

echo '== Concluído' . PHP_EOL;
cenario(['status' => 'completed']);
Banco::inserir('entregas_corridas_finalizadas', ['order_uuid' => 'order-1', 'company_uuid' => 'empresa-1', 'pedido' => 'order_1', 'numero' => null, 'loja_nome' => 'Pizzaria Ficticia', 'motoboy_uuid' => 'driver-1', 'motoboy_nome' => 'Carlos Motoboy', 'finalizada_em' => '2026-10-24 21:10:00', 'origem' => 'conclusao'], false);
$d = comanda('order_1')->dados;
confere($d['encerrado'] === true && $d['situacao_final'] === 'concluido', 'concluído: encerrado');
confere($d['conclusao'] === ['em' => '2026-10-24T21:10:00-03:00', 'minutos' => 40], 'conclusão pela corrida finalizada, com os minutos desde a criação');
cenario(['status' => 'completed']);
Banco::inserir('tracking_statuses', ['tracking_number_uuid' => 'tn-1', 'code' => 'COMPLETED', 'status' => 'Order completed', 'details' => null, 'created_at' => '2026-10-24 21:05:30'], false);
$d = comanda('order_1')->dados;
confere($d['conclusao'] === ['em' => '2026-10-24T21:05:30-03:00', 'minutos' => 35], 'sem a corrida finalizada: o status COMPLETED');
confere(end($d['linha_do_tempo'])['codigo'] === 'COMPLETED' && end($d['linha_do_tempo'])['detalhes'] === null, 'COMPLETED no fim da linha do tempo');
cenario(['status' => 'completed', 'tracking_number_uuid' => null]);
$d = comanda('order_1')->dados;
confere($d['conclusao'] === ['em' => '2026-10-24T20:55:00-03:00', 'minutos' => 25] && $d['linha_do_tempo'] === [], 'sem os dois: o updated_at (como o relatório); sem rastreio, linha do tempo vazia');

echo '== Cancelado' . PHP_EOL;
cenario(['status' => 'canceled']);
linhaIfood(['cancelado_pelo_ifood_em' => '2026-10-24 20:50:00']);
$d = comanda('order_1')->dados;
confere($d['encerrado'] === true && $d['situacao_final'] === 'cancelado' && $d['conclusao'] === null, 'cancelado: encerrado, sem conclusão');
cenario(['status' => 'expired']);
$d = comanda('order_1')->dados;
confere($d['encerrado'] === true && $d['situacao_final'] === null, 'expirado: encerrado, sem situação final');

echo '== Destino sem coordenada' . PHP_EOL;
cenario([], ['location' => new Point(0, 0)]);
$r = comanda('order_1', true, new RotaFalsa(null));
$p = $r->dados['pedido'];
confere($r->status === 200 && $p['id'] === 'order_1' && $p['latitude'] === null && $p['longitude'] === null && $p['endereco'] === 'RUA DAS FLORES, 123 - JARDIM PAULISTA', 'pedido no mesmo formato, com latitude e longitude null');
confere($r->dados['rota'] === null, 'sem rota');

echo '== Falhas da rota e do valor não derrubam a folha' . PHP_EOL;
cenario();
$rota          = new RotaFalsa(ROTA);
$rota->falha   = new \RuntimeException('osrm');
$calculo       = new CalculoFalso(VALOR);
$calculo->falha = new \RuntimeException('banco');
$r = comanda('order_1', true, $rota, $calculo);
confere($r->status === 200 && $r->dados['rota'] === null, 'rota com erro: null');
confere($r->dados['valores'] === ['km' => null, 'aproximado' => false, 'faixa' => null, 'valor_motoboy' => null, 'valor_loja' => null, 'junto' => null, 'multiplicador' => null], 'valor com erro: admin recebe os valores vazios');
confere(logou('[entregas] comanda: falha ao montar a rota do pedido', 'warning') && logou('[entregas] comanda: falha ao calcular o valor do pedido', 'warning'), 'as duas falhas no log');
confere(logsSem(['MARIANA', 'RUA DAS FLORES']), 'o log não leva o cliente nem o endereço');
cenario(['driver_assigned_uuid' => null, 'driverAssigned' => null, 'started' => false, 'status' => 'dispatched']);
$calculo = new CalculoFalso(VALOR);
$d       = comanda('order_1', true, null, $calculo)->dados;
confere($d['motoboy'] === null && $d['pedido']['motoboy_id'] === null && $calculo->chamadas === [['order_1', null]], 'sem motoboy: motoboy null e a prévia do valor sem motoboy');

echo '== Rota e proteção' . PHP_EOL;
$provider = file_get_contents(__DIR__ . '/../../api/app/Providers/RouteServiceProvider.php');
confere(str_contains($provider, 'use App\Http\Controllers\Entregas\PedidoAbertoController;'), 'provider importa o controller');
confere(str_contains($provider, "Route::get('pedidos/{id}/comanda', [PedidoAbertoController::class, 'comanda']);"), 'rota da comanda');
$inicio = strpos($provider, "Route::prefix('int/v1/entregas')");
$fim    = strpos($provider, "Route::prefix('v1/entregas/motoboy')");
$rota   = strpos($provider, "Route::get('pedidos/{id}/comanda'");
confere($inicio !== false && $rota > $inicio && $rota < $fim && str_contains(substr($provider, $inicio, 120), "->middleware(['fleetbase.protected'])"), 'no grupo int/v1/entregas com fleetbase.protected');
$liberada = false;
foreach (ProtegerPortalLoja::PERMITIDAS_INTERNAS as $padrao) {
    $liberada = $liberada || preg_match($padrao, 'GET entregas/pedidos/order_1/comanda') === 1;
}
confere(!$liberada, 'loja do portal: a rota não está nas PERMITIDAS_INTERNAS (403)');
confere(str_starts_with('int/v1/entregas/pedidos/order_1/comanda', BarrarMotoboyNoConsole::PREFIXOS[0]), 'motoboy: a rota está no prefixo que o BarrarMotoboyNoConsole barra');

resumo();
