<?php

// Ganhos do motoboy: valor congelado das entregas (CalculoEntregas + ValoresCongelados), o que o app recebe
// (GanhosDoMotoboy), quem é o motoboy da requisição (MotoboyDaSessao), as rotas (MotoboyController) e a migration.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ganhos-motoboy.php

require __DIR__ . '/stubs-ganhos.php';

use App\Http\Controllers\Entregas\MotoboyController;
use App\Support\Entregas\CalculoEntregas;
use App\Support\Entregas\GanhosDoMotoboy;
use App\Support\Entregas\MotoboyDaSessao;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Support\OSRM;
use Fleetbase\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Teste\Banco;
use Teste\ConsultaRegistrada;
use Teste\Ponto;

echo '== Migration da tabela entregas_valores_pedido' . PHP_EOL;
$migration = require '/repo/api/database/migrations/2026_10_03_120000_create_entregas_valores_pedido_table.php';
$migration->up();
$colunas = [];
foreach ((Schema::$criadas['entregas_valores_pedido'] ?? null)?->colunas ?? [] as $coluna) {
    $colunas[$coluna->argumentos[0] ?? $coluna->tipo] = $coluna;
}
confere(isset($colunas['order_uuid']) && array_key_exists('unique', $colunas['order_uuid']->modificadores), 'order_uuid único: um valor por pedido');
confere(isset($colunas['company_uuid']) && array_key_exists('index', $colunas['company_uuid']->modificadores), 'company_uuid com índice');
confere(($colunas['valor_motoboy']->argumentos ?? null) === ['valor_motoboy', 10, 2] && ($colunas['valor_loja']->argumentos ?? null) === ['valor_loja', 10, 2], 'valores em decimal(10,2)');
confere(($colunas['de_km']->argumentos ?? null) === ['de_km', 8, 2] && ($colunas['ate_km']->argumentos ?? null) === ['ate_km', 8, 2], 'limites da faixa em decimal(8,2)');
confere(array_key_exists('nullable', $colunas['chave']->modificadores ?? []) && ($colunas['acima']->modificadores['default'] ?? null) === [false], 'chave pode ser nula; acima começa falso');
confere(isset($colunas['id'], $colunas['metros'], $colunas['fonte'], $colunas['timestamps']), 'id, metros, fonte e timestamps');
$migration->down();
confere(!isset(Schema::$criadas['entregas_valores_pedido']), 'o down apaga a tabela');

echo '== Valor congelado (CalculoEntregas)' . PHP_EOL;

// faixas da central: o valor cobrado da loja na faixa de 2 a 4 km é 11,37, um número que não aparece em mais nada
const FAIXAS = [
    ['ate_km' => 1, 'motoboy' => 5, 'loja' => 7],
    ['ate_km' => 2, 'motoboy' => 6, 'loja' => 8],
    ['ate_km' => 4, 'motoboy' => 8, 'loja' => 11.37],
    ['ate_km' => 6, 'motoboy' => 10, 'loja' => 14],
];

function reiniciar(array $faixas = FAIXAS): void
{
    Setting::$valores = ['entregas.faixas' => $faixas];
    Banco::$tabelas   = [];
    Banco::$upserts   = 0;
    OSRM::$chamadas   = 0;
    OSRM::$metros     = 3210.4;
}

function lugar(string $id, ?float $lat, ?float $lng, string $nome): Place
{
    return new Place(['uuid' => 'uuid-' . $id, 'public_id' => $id, 'name' => $nome, 'street1' => 'Rua ' . $nome . ', 10', 'neighborhood' => 'Centro', 'location' => $lat === null ? null : new Ponto($lat, $lng)]);
}

// pedido concluído do motoboy Motoca, da Loja Centro para um cliente a uns 3 km
function pedido(string $id, array $atributos = []): Order
{
    return new Order($atributos + [
        'uuid'                 => 'uuid-' . $id,
        'public_id'            => $id,
        'customer_uuid'        => 'uuid-vendor_centro',
        'driver_assigned_uuid' => 'uuid-driver_motoca',
        'driverAssigned'       => new Driver(['uuid' => 'uuid-driver_motoca', 'public_id' => 'driver_motoca', 'name' => 'Motoca']),
        'payload'              => new Payload(lugar('place_loja', -21.17, -47.81, 'Loja Centro'), lugar('place_cliente-' . $id, -21.2, -47.8, 'Cliente')),
    ]);
}

// km já calculado e guardado no meta (como o relatório deixa), com a chave das coordenadas atuais do pedido
function comKm(Order $pedido, float $metros, string $fonte = 'osrm'): Order
{
    $o            = $pedido->payload->pickup->location;
    $d            = $pedido->payload->dropoff->location;
    $pedido->meta = ['entregas' => ['km_rota' => ['metros' => $metros, 'fonte' => $fonte, 'chave' => md5(implode(',', [$o->getLat(), $o->getLng(), $d->getLat(), $d->getLng()]))]]];

    return $pedido;
}

$calculo       = new CalculoEntregas();
Vendor::$lojas = [new Vendor(['uuid' => 'uuid-vendor_centro', 'public_id' => 'vendor_centro', 'name' => 'Loja Centro'])];

reiniciar();
$pedidos                = new Collection([comKm(pedido('order_a'), 3210.4)]);
[$entregas, $pendentes] = $calculo->entregas($pedidos, 'America/Sao_Paulo');
confere($entregas[0]['km'] === 3.21 && $entregas[0]['valor_motoboy'] === 8.0 && $entregas[0]['valor_loja'] === 11.37, 'primeira consulta: valores da faixa de 2 a 4 km (8,00 / 11,37)');
$linha = Banco::$tabelas['entregas_valores_pedido']['uuid-order_a'] ?? [];
confere(($linha['metros'] ?? null) === 3210 && ($linha['fonte'] ?? null) === 'osrm' && ($linha['valor_motoboy'] ?? null) === 8.0 && ($linha['valor_loja'] ?? null) === 11.37, 'a linha congelada guarda o km e os dois valores');
confere(($linha['company_uuid'] ?? null) === 'empresa' && ($linha['de_km'] ?? null) === 2.0 && ($linha['ate_km'] ?? null) === 4.0 && ($linha['acima'] ?? null) === false, 'e a empresa e os limites da faixa');
confere(Banco::$upserts === 1 && $pendentes === 0 && OSRM::$chamadas === 0, 'uma gravação só; com o km guardado, o OSRM não é chamado');
$faltando = array_diff(array_keys($linha), array_keys($colunas), ['created_at', 'updated_at']);
confere($linha && !$faltando, 'toda coluna gravada existe na migration' . ($faltando ? ' (faltam: ' . implode(', ', $faltando) . ')' : ''));

Setting::$valores['entregas.faixas'] = [['ate_km' => 4, 'motoboy' => 9, 'loja' => 12], ['ate_km' => 6, 'motoboy' => 12, 'loja' => 16]];
[$entregas]                          = $calculo->entregas($pedidos, 'America/Sao_Paulo');
confere($entregas[0]['valor_motoboy'] === 8.0 && $entregas[0]['valor_loja'] === 11.37, 'tabela nova: o pedido já calculado continua com 8,00 / 11,37 (a nova daria 9,00 / 12,00)');
confere($entregas[0]['faixa'] === ['de_km' => 2.0, 'ate_km' => 4.0, 'motoboy' => 8.0, 'loja' => 11.37], 'a faixa vem da linha congelada, em números (o banco devolve texto)');
confere(Banco::$upserts === 1, 'nada é regravado');

comKm($pedidos->first(), 4500.0);
[$entregas] = $calculo->entregas($pedidos, 'America/Sao_Paulo');
confere($entregas[0]['valor_motoboy'] === 12.0 && $entregas[0]['valor_loja'] === 16.0, 'km diferente (4,5): recalcula com a tabela vigente, faixa de 4 a 6 km (12,00 / 16,00)');
$regravada = Banco::$tabelas['entregas_valores_pedido']['uuid-order_a'] ?? [];
confere(($regravada['metros'] ?? null) === 4500 && ($regravada['de_km'] ?? null) === 4.0 && ($regravada['ate_km'] ?? null) === 6.0 && ($regravada['valor_motoboy'] ?? null) === 12.0 && ($regravada['valor_loja'] ?? null) === 16.0 && Banco::$upserts === 2, 'e regrava a linha com o km, a faixa e os valores novos');

reiniciar();
$longe      = new Collection([comKm(pedido('order_longe'), 8000.0)]);
[$entregas] = $calculo->entregas($longe, 'America/Sao_Paulo');
confere(($entregas[0]['faixa']['acima'] ?? false) === true && $entregas[0]['valor_motoboy'] === 10.0, 'km 8: acima da última faixa vale a última (10,00)');
[$entregas] = $calculo->entregas($longe, 'America/Sao_Paulo');
confere(($entregas[0]['faixa']['acima'] ?? false) === true && $entregas[0]['faixa']['de_km'] === 6.0 && Banco::$upserts === 1, 'lida do banco, continua "acima de 6 km", sem regravar');

reiniciar();
[$entregas] = $calculo->entregas(new Collection([comKm(pedido('order_limite'), 1004.6)]), 'America/Sao_Paulo');
confere($entregas[0]['km'] === 1.0 && $entregas[0]['valor_motoboy'] === 5.0, 'a faixa sai do km exibido: 1.004,6 m = 1,00 km, primeira faixa (5,00)');

reiniciar([]);
[$entregas] = $calculo->entregas(new Collection([comKm(pedido('order_b'), 1500.0)]), 'America/Sao_Paulo');
confere($entregas[0]['km'] === 1.5 && $entregas[0]['faixa'] === null && $entregas[0]['valor_motoboy'] === null && $entregas[0]['valor_loja'] === null, 'sem faixas cadastradas: km aparece, valores nulos');
confere(Banco::$upserts === 0 && empty(Banco::$tabelas['entregas_valores_pedido']), 'e nada é gravado');

reiniciar();
$semPosicao             = pedido('order_c', ['payload' => new Payload(lugar('place_loja', -21.17, -47.81, 'Loja Centro'), lugar('place_x', null, null, 'Cliente'))]);
[$entregas, $pendentes] = $calculo->entregas(new Collection([$semPosicao]), 'America/Sao_Paulo');
confere($pendentes === 1 && $entregas[0]['km'] === null && $entregas[0]['valor_motoboy'] === null && Banco::$upserts === 0, 'destino sem posição e sem km guardado: pendente, sem valor');

reiniciar();
[$entregas] = $calculo->entregas(new Collection([comKm(pedido('order_d'), 3210.4)]), 'America/Sao_Paulo');
confere(array_keys($entregas[0]) === ['loja', 'loja_nome', 'pedido', 'id_interno', 'motoboy', 'motoboy_nome', 'concluido_em', 'origem', 'destino', 'km', 'fonte', 'faixa', 'valor_motoboy', 'valor_loja'], 'o formato do relatório e do extrato não muda');
confere($entregas[0]['loja'] === 'loja:vendor_centro' && $entregas[0]['concluido_em'] === '2026-10-03T19:42:00-03:00', "loja dona do pedido e conclusão no fuso da organização ({$entregas[0]['concluido_em']})");

echo '== Valor de um pedido (card de aceitar e detalhes)' . PHP_EOL;
reiniciar();
$aberto = pedido('order_e', ['status' => 'dispatched', 'adhoc' => true, 'driver_assigned_uuid' => null, 'driverAssigned' => null]);
$valor  = $calculo->valorDoPedido($aberto);
confere($valor === ['km' => 3.21, 'fonte' => 'osrm', 'faixa' => ['de_km' => 2.0, 'ate_km' => 4.0, 'motoboy' => 8.0, 'loja' => 11.37], 'valor_motoboy' => 8.0], 'sem km guardado: calcula a rota na hora (3,21 km → 8,00)');
confere(OSRM::$chamadas === 1 && ($aberto->meta['entregas']['km_rota']['metros'] ?? null) === 3210.4 && isset(Banco::$tabelas['entregas_valores_pedido']['uuid-order_e']), 'chama o OSRM uma vez, guarda o km no pedido e congela o valor');
$valor = $calculo->valorDoPedido($aberto);
confere(OSRM::$chamadas === 1 && Banco::$upserts === 1 && $valor['valor_motoboy'] === 8.0, 'a segunda consulta não chama o OSRM nem regrava');

reiniciar();
OSRM::$metros = null;
$valor        = $calculo->valorDoPedido(pedido('order_f', ['status' => 'dispatched', 'adhoc' => true, 'driver_assigned_uuid' => null]));
confere($valor['fonte'] === 'estimativa' && $valor['km'] === 2.6 && $valor['valor_motoboy'] === 8.0, "OSRM fora do ar: estimativa (2.000 m em linha reta × 1,3 = {$valor['km']} km)");

reiniciar();
$valor = $calculo->valorDoPedido(pedido('order_g', ['payload' => new Payload(lugar('place_loja', -21.17, -47.81, 'Loja Centro'), lugar('place_y', null, null, 'Cliente'))]));
confere($valor === ['km' => null, 'fonte' => null, 'faixa' => null, 'valor_motoboy' => null] && OSRM::$chamadas === 0, 'destino sem posição: km e valor nulos');

echo '== O que o app recebe (GanhosDoMotoboy)' . PHP_EOL;
$entrega = [
    'loja'          => 'loja:vendor_centro',
    'loja_nome'     => 'Loja Centro',
    'pedido'        => 'order_a',
    'id_interno'    => 'IF-1',
    'motoboy'       => 'driver_motoca',
    'motoboy_nome'  => 'Motoca',
    'concluido_em'  => '2026-10-03T19:42:00-03:00',
    'origem'        => 'Rua Loja Centro, 10, Centro',
    'destino'       => 'Rua Cliente, 10, Centro',
    'km'            => 3.21,
    'fonte'         => 'estimativa',
    'faixa'         => ['de_km' => 2.0, 'ate_km' => 4.0, 'motoboy' => 8.0, 'loja' => 11.37],
    'valor_motoboy' => 8.0,
    'valor_loja'    => 11.37,
];
$linha = GanhosDoMotoboy::linha($entrega);
confere(array_keys($linha) === ['pedido', 'concluido_em', 'loja', 'destino', 'km', 'aproximado', 'faixa', 'valor'], 'só os campos do app');
confere($linha['valor'] === 8.0 && $linha['loja'] === 'Loja Centro' && $linha['aproximado'] === true, 'valor do motoboy, nome da loja e km estimado marcado como aproximado');
confere($linha['faixa'] === ['de_km' => 2.0, 'ate_km' => 4.0, 'acima' => false], 'a faixa vai só com os limites');
$semKm  = ['km' => null, 'fonte' => null, 'faixa' => null, 'valor_motoboy' => null, 'valor_loja' => null] + $entrega;
$resumo = GanhosDoMotoboy::resumo([$entrega, $semKm], '2026-10-01', '2026-10-03', 1);
confere($resumo['totais'] === ['entregas' => 2, 'km' => 3.21, 'valor' => 8.0] && $resumo['pendentes'] === 1 && $resumo['inicio'] === '2026-10-01', 'totais: corridas, km e valor (só o que tem valor)');
confere(!str_contains(json_encode($resumo), '11.37'), 'o valor cobrado da loja (11,37) não aparece');
$valor = GanhosDoMotoboy::valor(new Order(['public_id' => 'order_e']), ['km' => 3.21, 'fonte' => 'osrm', 'faixa' => ['de_km' => 2.0, 'ate_km' => 4.0, 'motoboy' => 8.0, 'loja' => 11.37], 'valor_motoboy' => 8.0]);
confere($valor === ['pedido' => 'order_e', 'km' => 3.21, 'aproximado' => false, 'faixa' => ['de_km' => 2.0, 'ate_km' => 4.0, 'acima' => false], 'valor' => 8.0], 'valor de um pedido no formato do app, sem o valor da loja');

$motoca = new Driver(['uuid' => 'uuid-driver_motoca', 'user_uuid' => 'usuario-motoca', 'public_id' => 'driver_motoca']);
confere(GanhosDoMotoboy::podeVer(new Order(['driver_assigned_uuid' => 'uuid-driver_motoca']), $motoca), 'vê o pedido dele');
confere(!GanhosDoMotoboy::podeVer(new Order(['driver_assigned_uuid' => 'uuid-driver_outro']), $motoca), 'não vê o pedido de outro motoboy');
confere(GanhosDoMotoboy::podeVer(new Order(['adhoc' => true, 'status' => 'dispatched']), $motoca), 'vê o pedido aberto (avulso, sem motoboy)');
confere(GanhosDoMotoboy::podeVer(new Order(['adhoc' => true, 'status' => 'dispatched', 'driver_assigned_uuid' => '']), $motoca), 'motoboy gravado vazio conta como sem motoboy');
confere(!GanhosDoMotoboy::podeVer(new Order(['adhoc' => true, 'status' => 'canceled']), $motoca), 'não vê pedido aberto já encerrado');
confere(!GanhosDoMotoboy::podeVer(new Order(['adhoc' => false, 'status' => 'created']), $motoca), 'não vê pedido sem motoboy que não é aberto');
confere(GanhosDoMotoboy::diasDoPeriodo('2026-07-01', '2026-10-01') === 92 && GanhosDoMotoboy::diasDoPeriodo('2026-07-01', '2026-10-02') === 93, 'dias do período: de 1º/7 a 1º/10 são 92');

echo '== Quem é o motoboy (MotoboyDaSessao)' . PHP_EOL;
Driver::$todos     = [$motoca, new Driver(['uuid' => 'uuid-driver_outra-empresa', 'user_uuid' => 'usuario-motoca', 'company_uuid' => 'outra'])];
$GLOBALS['sessao'] = ['company' => 'empresa', 'user' => 'usuario-motoca'];
confere(MotoboyDaSessao::motoboy(new Request([], '12|abcdef'))?->uuid === 'uuid-driver_motoca', 'token de usuário: o Driver dele na empresa da sessão');
confere(MotoboyDaSessao::motoboy(new Request([], 'flb_live_abc123')) === null, 'chave de API: ninguém, mesmo que o dono da chave tenha cadastro de motoboy');
confere(MotoboyDaSessao::motoboy(new Request([], null)) === null, 'sem token: ninguém');
$GLOBALS['sessao'] = ['company' => 'empresa', 'user' => 'usuario-admin'];
confere(MotoboyDaSessao::motoboy(new Request([], '13|xyz')) === null, 'usuário sem cadastro de motoboy: ninguém');
$GLOBALS['sessao'] = ['company' => 'terceira', 'user' => 'usuario-motoca'];
confere(MotoboyDaSessao::motoboy(new Request([], '12|abcdef')) === null, 'cadastro de motoboy só em outra empresa: ninguém');
$GLOBALS['sessao'] = ['company' => 'empresa', 'user' => 'usuario-motoca'];

echo '== Rota de ganhos (MotoboyController::ganhos)' . PHP_EOL;

// o pedidosConcluidos real consulta o banco: este registra o filtro que a rota passa e aplica o filtro do motoboy na lista
class CalculoDeTeste extends CalculoEntregas
{
    public array $wheres  = [];
    public array $periodo = [];

    public function pedidosConcluidos(string $companyUuid, Carbon $inicio, Carbon $fim, ?\Closure $filtro = null): Collection
    {
        $consulta = new ConsultaRegistrada();
        if ($filtro) {
            $filtro($consulta);
        }
        $this->wheres  = $consulta->wheres;
        $this->periodo = [$inicio->format('Y-m-d H:i:s'), $fim->format('Y-m-d H:i:s')];
        $motoboy       = $consulta->wheres[0][1] ?? null;

        return new Collection(array_values(array_filter(Order::$todos, fn ($pedido) => $pedido->driver_assigned_uuid === $motoboy)));
    }
}

reiniciar();
Order::$todos = [comKm(pedido('order_meu'), 3210.4), comKm(pedido('order_de-outro', ['driver_assigned_uuid' => 'uuid-driver_outro']), 1500.0)];
$controller   = new MotoboyController();
$calculoTeste = new CalculoDeTeste();
$resposta     = $controller->ganhos(new Request(['inicio' => '2026-10-01', 'fim' => '2026-10-03'], '12|abc'), $calculoTeste);
confere($resposta->status === 200 && array_column($resposta->dados['entregas'], 'pedido') === ['order_meu'], 'só as corridas do motoboy do token');
confere($calculoTeste->wheres === [['orders.driver_assigned_uuid', 'uuid-driver_motoca']], 'o filtro vai ao banco pelo motoboy');
confere($calculoTeste->periodo === ['2026-10-01 03:00:00', '2026-10-04 02:59:59'], 'do dia 1 ao dia 3 no fuso da organização (em UTC: ' . implode(' a ', $calculoTeste->periodo) . ')');
confere($resposta->dados['totais'] === ['entregas' => 1, 'km' => 3.21, 'valor' => 8.0], 'total a receber do período');
confere(!str_contains(json_encode($resposta->dados), '11.37') && !str_contains(json_encode($resposta->dados), 'valor_loja'), 'nada do valor cobrado da loja');
confere($controller->ganhos(new Request(['inicio' => '2026-07-01', 'fim' => '2026-10-02'], '12|abc'), $calculoTeste)->status === 422, 'mais de 3 meses: 422');
confere($controller->ganhos(new Request(['inicio' => '2026-10-01', 'fim' => '2026-10-03'], 'flb_live_abc'), $calculoTeste)->status === 403, 'chave de API: 403');

echo '== Rota do valor (MotoboyController::valor)' . PHP_EOL;
reiniciar();
Order::$todos = [
    pedido('order_meu', ['status' => 'started']),
    pedido('order_de-outro', ['driver_assigned_uuid' => 'uuid-driver_outro']),
    pedido('order_aberto', ['status' => 'dispatched', 'adhoc' => true, 'driver_assigned_uuid' => null, 'driverAssigned' => null]),
    pedido('order_outra-empresa', ['company_uuid' => 'outra']),
];
$resposta = $controller->valor(new Request([], '12|abc'), 'order_meu', new CalculoEntregas());
confere($resposta->status === 200 && $resposta->dados === ['pedido' => 'order_meu', 'km' => 3.21, 'aproximado' => false, 'faixa' => ['de_km' => 2.0, 'ate_km' => 4.0, 'acima' => false], 'valor' => 8.0], 'pedido dele: km, faixa e valor dele');
$resposta = $controller->valor(new Request([], '12|abc'), 'uuid-order_aberto', new CalculoEntregas());
confere($resposta->status === 200 && $resposta->dados['pedido'] === 'order_aberto', 'pedido aberto, achado também pelo uuid');
confere($controller->valor(new Request([], '12|abc'), 'order_de-outro', new CalculoEntregas())->status === 404, 'pedido de outro motoboy: 404');
confere($controller->valor(new Request([], '12|abc'), 'order_outra-empresa', new CalculoEntregas())->status === 404, 'pedido de outra empresa: 404');
confere($controller->valor(new Request([], '12|abc'), 'uuid-order_outra-empresa', new CalculoEntregas())->status === 404, 'pedido de outra empresa, nem pelo uuid: 404 (a empresa não escapa pelo "ou")');
confere($controller->valor(new Request([], '12|abc'), 'order_nao-existe', new CalculoEntregas())->status === 404, 'pedido que não existe: 404');
confere($controller->valor(new Request([], 'flb_live_abc'), 'order_meu', new CalculoEntregas())->status === 403, 'chave de API: 403');

resumo();
