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

resumo();
