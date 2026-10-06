<?php

// Fuso do servidor (horário de Brasília) no relatório, no extrato e nos ganhos: o período vai ao banco no fuso do app (o
// mesmo da sessão do MySQL) e a conclusão, texto cru do banco, é lida nesse fuso. Ver CLAUDE.md, "Fuso (horário de
// Brasília)". Os outros casos do CalculoEntregas e da rota de ganhos ficam no ganhos-motoboy.php.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/fuso-relatorio.php

require __DIR__ . '/stubs-ganhos.php';

use App\Http\Controllers\Entregas\MotoboyController;
use App\Support\Entregas\CalculoEntregas;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Teste\Banco;
use Teste\Ponto;

// como na produção: o Laravel faz date_default_timezone_set(config('app.timezone'))
date_default_timezone_set('America/Sao_Paulo');

Setting::$valores = ['entregas.faixas' => [['ate_km' => 4, 'motoboy' => 8, 'loja' => 11]]];
Banco::$tabelas   = [];
Vendor::$lojas    = [new Vendor(['uuid' => 'uuid-vendor_centro', 'public_id' => 'vendor_centro', 'name' => 'Loja Centro'])];

function lugar(string $id, float $lat, float $lng): Place
{
    return new Place(['uuid' => 'uuid-' . $id, 'public_id' => $id, 'name' => $id, 'street1' => 'Rua ' . $id, 'location' => new Ponto($lat, $lng)]);
}

function pedido(string $id, string $concluidoEm): Order
{
    $pedido = new Order([
        'uuid'                  => 'uuid-' . $id,
        'public_id'             => $id,
        'customer_uuid'         => 'uuid-vendor_centro',
        'driver_assigned_uuid'  => 'uuid-driver_motoca',
        'driverAssigned'        => new Driver(['uuid' => 'uuid-driver_motoca', 'public_id' => 'driver_motoca', 'name' => 'Motoca']),
        'payload'               => new Payload(lugar('place_loja', -21.17, -47.81), lugar('place_cliente', -21.2, -47.8)),
        'entregas_concluido_em' => $concluidoEm,
    ]);
    $pedido->meta = ['entregas' => ['km_rota' => ['metros' => 3000.0, 'fonte' => 'osrm', 'chave' => md5(implode(',', [-21.17, -47.81, -21.2, -47.8]))]]];

    return $pedido;
}

echo '== Conclusão (texto do banco) lida no fuso do app' . PHP_EOL;
// 22:30 de Brasília: com o banco em -03:00, o MySQL devolve o TIMESTAMP já nessa hora; lido como UTC, virava 19:30
[$entregas] = (new CalculoEntregas())->entregas(new Collection([pedido('order_noite', '2026-10-03 22:30:00')]), 'America/Sao_Paulo');
confere($entregas[0]['concluido_em'] === '2026-10-03T22:30:00-03:00', "pedido concluído às 22:30 aparece às 22:30 de Brasília ({$entregas[0]['concluido_em']})");

echo '== Período da rota de ganhos (MotoboyController::ganhos)' . PHP_EOL;

// registra o período que a rota passa ao banco, sem consultar
class CalculoDeTeste extends CalculoEntregas
{
    public array $periodo = [];

    public function pedidosConcluidos(string $companyUuid, Carbon $inicio, Carbon $fim, ?\Closure $filtro = null): Collection
    {
        $this->periodo = [$inicio->format('Y-m-d H:i:s P'), $fim->format('Y-m-d H:i:s P')];

        return new Collection([]);
    }
}

Driver::$todos     = [new Driver(['uuid' => 'uuid-driver_motoca', 'user_uuid' => 'usuario-motoca', 'public_id' => 'driver_motoca'])];
$GLOBALS['sessao'] = ['company' => 'empresa', 'user' => 'usuario-motoca'];
$calculo           = new CalculoDeTeste();
$resposta          = (new MotoboyController())->ganhos(new Request(['inicio' => '2026-10-01', 'fim' => '2026-10-03'], '12|abc'), $calculo);
confere($resposta->status === 200, 'a rota responde');
// o where do query builder formata a data no fuso do próprio objeto: em UTC, iria "03:00" para uma sessão em -03:00
confere($calculo->periodo === ['2026-10-01 00:00:00 -03:00', '2026-10-03 23:59:59 -03:00'], 'do dia 1 ao dia 3 em Brasília, já no fuso do app (' . implode(' a ', $calculo->periodo) . ')');

resumo();
