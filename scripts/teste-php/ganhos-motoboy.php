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

resumo();
