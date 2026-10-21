<?php

// Entrega junto (segunda tabela de faixas) e multiplicador por período: valor final (ValorDaEntrega), congelamento das
// duas tabelas e da atribuição (CalculoEntregas + ValoresCongelados), prévia do card, reserva por horários, o observador,
// o que o app recebe (GanhosDoMotoboy) e a migration. Os períodos (Multiplicadores, rotas) ficam no
// financeiro-multiplicadores.php.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/entrega-junto.php

require __DIR__ . '/stubs-ganhos.php';

use App\Listeners\Entregas\ObservadorDoValorDaEntrega;
use App\Support\Entregas\AtribuicaoDoValor;
use App\Support\Entregas\CalculoEntregas;
use App\Support\Entregas\ValorDaEntrega;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Teste\Banco;
use Teste\Ponto;

const NORMAL = [
    ['ate_km' => 1, 'motoboy' => 5, 'loja' => 7],
    ['ate_km' => 2, 'motoboy' => 6, 'loja' => 8],
    ['ate_km' => 4, 'motoboy' => 8, 'loja' => 11.37],
    ['ate_km' => 6, 'motoboy' => 10, 'loja' => 14],
];
// segunda tabela ("a partir da 2ª entrega junto"), com limites próprios: 3,21 km cai em 3 a 6 (6,00 / 9,00)
const JUNTAS = [
    ['ate_km' => 3, 'motoboy' => 4, 'loja' => 6],
    ['ate_km' => 6, 'motoboy' => 6, 'loja' => 9],
];

/** Pedidos em aberto, períodos e posições controlados pelo teste (sem banco de pedidos nem tabela de períodos). */
class AtribuicaoFalsa extends AtribuicaoDoValor
{
    public int $emAberto      = 0;
    public ?object $periodo   = null;
    public array $periodos    = [];
    public array $posicoes    = [];
    public array $chamadas    = [];

    public function emAberto(string $empresa, string $motoboyUuid, string $exceto): int
    {
        $this->chamadas[] = ['emAberto', $motoboyUuid, $exceto];

        return $this->emAberto;
    }

    public function multiplicadorEm(string $empresa, string $instante): ?object
    {
        $this->chamadas[] = ['multiplicadorEm', $instante];

        return $this->periodo;
    }

    public function multiplicadoresDoPeriodo(string $empresa, string $de, string $ate): array
    {
        $this->chamadas[] = ['multiplicadoresDoPeriodo', $de, $ate];

        return $this->periodos;
    }

    public function posicoesPorHorarios(string $empresa, array $pedidos): array
    {
        $this->chamadas[] = ['posicoesPorHorarios', $pedidos];

        return $this->posicoes;
    }
}

/** O cálculo do observador: registra quem foi chamado e com qual empresa na sessão. */
class CalculoQueRegistra extends CalculoEntregas
{
    public array $registrados = [];
    public array $sessoes     = [];
    public bool $falhar       = false;

    public function registrarAtribuicao(Order $pedido): void
    {
        if ($this->falhar) {
            throw new \RuntimeException('banco fora do ar');
        }
        $this->registrados[] = $pedido->uuid;
        $this->sessoes[]     = session('company');
    }
}

/** O período como o banco devolve (decimal em texto). */
function periodoDeChuva(string $inicio = '2026-10-03 18:00:00', string $fim = '2026-10-03 22:00:00'): object
{
    return (object) ['uuid' => 'mult-chuva', 'nome' => 'Chuva', 'mult_motoboy' => '1.20', 'mult_loja' => '1.10', 'inicio' => $inicio, 'fim' => $fim];
}

function reiniciar(array $juntas = JUNTAS): void
{
    Setting::$valores   = ['entregas.faixas' => NORMAL, 'entregas.faixas_juntas' => $juntas];
    Banco::$tabelas     = [];
    Banco::$upserts     = 0;
    Banco::$consultas   = [];
    Banco::$falharAoLer = [];
    Log::$registros     = [];
    Order::$todos       = [];
}

function lugar(string $id, ?float $lat, ?float $lng, string $nome): Place
{
    return new Place(['uuid' => 'uuid-' . $id, 'public_id' => $id, 'name' => $nome, 'street1' => 'Rua ' . $nome . ', 10', 'neighborhood' => 'Centro', 'location' => $lat === null ? null : new Ponto($lat, $lng)]);
}

// pedido do motoboy Motoca, da Loja Centro para um cliente a uns 3 km
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

// km já calculado e guardado no meta, com a chave das coordenadas atuais (o OSRM não é chamado)
function comKm(Order $pedido, float $metros): Order
{
    $o            = $pedido->payload->pickup->location;
    $d            = $pedido->payload->dropoff->location;
    $pedido->meta = ['entregas' => ['km_rota' => ['metros' => $metros, 'fonte' => 'osrm', 'chave' => md5(implode(',', [$o->getLat(), $o->getLng(), $d->getLat(), $d->getLng()]))]]];

    return $pedido;
}

/** A linha congelada como está no banco em memória (sem a conversão do MySQL). */
function linhaDe(string $id): array
{
    return Banco::$tabelas['entregas_valores_pedido']['uuid-' . $id] ?? [];
}

function logou(string $trecho): bool
{
    foreach (Log::$registros as [, $mensagem]) {
        if (str_contains($mensagem, $trecho)) {
            return true;
        }
    }

    return false;
}

Vendor::$lojas = [new Vendor(['uuid' => 'uuid-vendor_centro', 'public_id' => 'vendor_centro', 'name' => 'Loja Centro'])];

echo '== Valor final (ValorDaEntrega)' . PHP_EOL;

// linha congelada como o MySQL devolve: decimal em texto, booleano em 0/1
$base = [
    'de_km' => '2.00', 'ate_km' => '4.00', 'acima' => 0, 'valor_motoboy' => '8.00', 'valor_loja' => '11.37',
    'junto_de_km' => '3.00', 'junto_ate_km' => '6.00', 'junto_acima' => 0, 'junto_motoboy' => '6.00', 'junto_loja' => '9.00',
];
$chuva = ['multiplicador_uuid' => 'mult-chuva', 'multiplicador_nome' => 'Chuva', 'mult_motoboy' => '1.20', 'mult_loja' => '1.10'];

$v = ValorDaEntrega::final($base);
confere($v['motoboy'] === 8.0 && $v['loja'] === 11.37 && $v['tabela'] === 'normal' && $v['posicao'] === null && $v['multiplicador'] === null, 'sem atribuição: tabela normal × 1');
confere($v['faixa'] === ['de_km' => 2.0, 'ate_km' => 4.0, 'motoboy' => 8.0, 'loja' => 11.37], 'a faixa da tabela normal, em números');

$v = ValorDaEntrega::final(['posicao' => 1, 'mult_motoboy' => '1.50', 'mult_loja' => '1.00', 'multiplicador_nome' => 'Chuva'] + $base);
confere($v['motoboy'] === 12.0 && $v['loja'] === 11.37 && $v['tabela'] === 'normal', '1ª entrega com ×1,5 ao motoboy e ×1 à loja: 12,00 / 11,37');
confere($v['multiplicador'] === ['nome' => 'Chuva', 'motoboy' => 1.5, 'loja' => 1.0], 'o multiplicador aplicado, com os dois fatores');

$v = ValorDaEntrega::final(['posicao' => 2] + $chuva + $base);
confere($v['motoboy'] === 7.2 && $v['loja'] === 9.9 && $v['tabela'] === 'junto' && $v['posicao'] === 2, '2ª entrega com chuva: segunda tabela × fatores (6 × 1,2 = 7,20; 9 × 1,1 = 9,90)');
confere($v['faixa'] === ['de_km' => 3.0, 'ate_km' => 6.0, 'motoboy' => 6.0, 'loja' => 9.0], 'a faixa é a da segunda tabela');

$v = ValorDaEntrega::final(['posicao' => 3, 'junto_motoboy' => null, 'junto_loja' => null] + $base);
confere($v['motoboy'] === 8.0 && $v['tabela'] === 'normal' && $v['posicao'] === 3, 'segunda tabela vazia no cálculo: tabela normal (a posição fica guardada)');

$v = ValorDaEntrega::final(['posicao' => 4, 'junto_acima' => 1] + $base);
confere(($v['faixa']['acima'] ?? null) === true && $v['tabela'] === 'junto', 'acima da última faixa da segunda tabela');

confere(ValorDaEntrega::final(['posicao' => 1, 'mult_motoboy' => '1.10', 'mult_loja' => '1.10', 'multiplicador_nome' => 'Chuva'] + $base)['loja'] === 12.51, 'arredonda a 2 casas (11,37 × 1,1 = 12,507 → 12,51)');

echo '== Posição pelos horários' . PHP_EOL;
$outros = [
    ['aceite' => '2026-10-21 18:40:00', 'fim' => null],                  // ainda levando
    ['aceite' => '2026-10-21 18:30:00', 'fim' => '2026-10-21 18:55:00'], // terminou antes
    ['aceite' => '2026-10-21 19:05:00', 'fim' => null],                  // aceito depois
    ['aceite' => '2026-10-21 18:50:00', 'fim' => '2026-10-21 19:10:00'], // levando no instante
    ['aceite' => null, 'fim' => null],                                   // sem horário
];
confere(ValorDaEntrega::posicaoPorHorarios('2026-10-21 19:00:00', $outros) === 3, 'dois pedidos em mãos às 19:00: 3ª entrega');
confere(ValorDaEntrega::posicaoPorHorarios('2026-10-21 19:00:00', []) === 1, 'sem outros: 1ª');

echo '== Fator visível a cada conta' . PHP_EOL;
$m = ['nome' => 'Chuva', 'motoboy' => 1.2, 'loja' => 1.0];
confere(ValorDaEntrega::doMotoboy($m) === ['nome' => 'Chuva', 'fator' => 1.2] && ValorDaEntrega::daLoja($m) === null, 'motoboy vê ×1,2; a loja (×1) não vê selo');
confere(ValorDaEntrega::doMotoboy(null) === null && ValorDaEntrega::daLoja(['nome' => 'Chuva', 'motoboy' => 1.0, 'loja' => 1.1]) === ['nome' => 'Chuva', 'fator' => 1.1], 'sem multiplicador: nada; loja ×1,1: selo da loja');

echo '== Migration das colunas novas' . PHP_EOL;
$migration = require '/repo/api/database/migrations/2026_10_21_100100_add_junto_e_multiplicador_to_entregas_valores_pedido_table.php';
$migration->up();
$colunas = [];
foreach ((Schema::$alteradas['entregas_valores_pedido'][0] ?? null)?->colunas ?? [] as $coluna) {
    $colunas[$coluna->argumentos[0] ?? $coluna->tipo] = $coluna;
}
$esperadas = ['junto_de_km', 'junto_ate_km', 'junto_acima', 'junto_motoboy', 'junto_loja', 'motoboy_uuid', 'atribuido_em', 'posicao', 'multiplicador_uuid', 'multiplicador_nome', 'mult_motoboy', 'mult_loja'];
confere(array_keys($colunas) === $esperadas, 'as doze colunas, na ordem');
confere(!array_filter($colunas, fn ($c) => !array_key_exists('nullable', $c->modificadores)), 'todas nulas: as linhas antigas valem como antes');
confere($colunas['junto_motoboy']->argumentos === ['junto_motoboy', 10, 2] && $colunas['mult_motoboy']->argumentos === ['mult_motoboy', 4, 2] && $colunas['posicao']->tipo === 'unsignedTinyInteger', 'valores em decimal(10,2), fatores em decimal(4,2), posição tinyint');
confere(array_diff(ValorDaEntrega::COLUNAS_DA_ATRIBUICAO, $esperadas) === [], 'toda coluna da atribuição existe na migration');
$migration->down();
$drop = (Schema::$alteradas['entregas_valores_pedido'][1] ?? null)?->colunas[0] ?? null;
confere($drop && $drop->tipo === 'dropColumn' && $drop->argumentos[0] === $esperadas, 'o down apaga as doze');

echo '== Congela as duas tabelas' . PHP_EOL;
reiniciar();
$atribuicao = new AtribuicaoFalsa();
$calculo    = new CalculoEntregas(null, $atribuicao);
$pedidos    = new Collection([comKm(pedido('order_a'), 3210.4)]);
[$entregas] = $calculo->entregas($pedidos, 'America/Sao_Paulo');
$a          = linhaDe('order_a');
confere(($a['junto_de_km'] ?? null) === 3.0 && ($a['junto_ate_km'] ?? null) === 6.0 && ($a['junto_acima'] ?? null) === false && ($a['junto_motoboy'] ?? null) === 6.0 && ($a['junto_loja'] ?? null) === 9.0, 'congela também a faixa da segunda tabela (3 a 6 km: 6,00 / 9,00)');
confere($entregas[0]['valor_motoboy'] === 8.0 && $entregas[0]['valor_loja'] === 11.37 && $entregas[0]['tabela'] === 'normal' && $entregas[0]['posicao'] === null && $entregas[0]['multiplicador'] === null, 'sem atribuição e sem horário de aceite: tabela normal, nada a reconstituir');
confere($atribuicao->chamadas === [], 'e nenhuma consulta de atribuição');
Setting::$valores['entregas.faixas_juntas'] = [['ate_km' => 6, 'motoboy' => 1, 'loja' => 2]];
$calculo->entregas($pedidos, 'America/Sao_Paulo');
confere(linhaDe('order_a')['junto_motoboy'] === 6.0 && Banco::$upserts === 1, 'segunda tabela nova não muda o pedido já calculado');
reiniciar([]);
$calculo->entregas(new Collection([comKm(pedido('order_b'), 3210.4)]), 'America/Sao_Paulo');
$b = linhaDe('order_b');
confere(array_key_exists('junto_motoboy', $b) && $b['junto_motoboy'] === null && $b['junto_de_km'] === null && $b['junto_acima'] === null, 'segunda tabela vazia: as colunas dela ficam nulas');

echo '== Prévia no card (valorDoPedido)' . PHP_EOL;
reiniciar();
$atribuicao = new AtribuicaoFalsa();
$calculo    = new CalculoEntregas(null, $atribuicao);
$aberto     = comKm(pedido('order_p', ['driver_assigned_uuid' => null, 'driverAssigned' => null, 'status' => 'created', 'adhoc' => true]), 3210.4);
$atribuicao->emAberto = 1;
$atribuicao->periodo  = periodoDeChuva();
$v = $calculo->valorDoPedido($aberto, 'uuid-driver_motoca');
confere($v['valor_motoboy'] === 7.2 && $v['tabela'] === 'junto' && $v['posicao'] === 2 && $v['multiplicador'] === ['nome' => 'Chuva', 'motoboy' => 1.2, 'loja' => 1.1], 'motoboy que já leva um pedido, com chuva: 6 × 1,2 = 7,20 pela segunda tabela');
confere($v['faixa'] === ['de_km' => 3.0, 'ate_km' => 6.0, 'motoboy' => 6.0, 'loja' => 9.0] && $v['km'] === 3.21, 'com a faixa da segunda tabela');
confere(in_array(['emAberto', 'uuid-driver_motoca', 'uuid-order_p'], $atribuicao->chamadas, true), 'conta os pedidos em aberto dele, sem este');
confere((linhaDe('order_p')['motoboy_uuid'] ?? null) === null, 'a prévia não grava a atribuição');
$v = $calculo->valorDoPedido($aberto);
confere($v['valor_motoboy'] === 9.6 && $v['posicao'] === 1 && $v['tabela'] === 'normal', 'sem motoboy (alarme aberto a vários): tabela normal × chuva (8 × 1,2 = 9,60)');
$atribuicao->periodo  = null;
$atribuicao->emAberto = 0;
$v = $calculo->valorDoPedido($aberto, 'uuid-driver_motoca');
confere($v['valor_motoboy'] === 8.0 && $v['tabela'] === 'normal' && $v['multiplicador'] === null, 'livre e sem período: como hoje');

echo '== Atribuição (registrarAtribuicao)' . PHP_EOL;
reiniciar();
$atribuicao = new AtribuicaoFalsa();
$calculo    = new CalculoEntregas(null, $atribuicao);
$p          = comKm(pedido('order_c', ['status' => 'started']), 3210.4);
$atribuicao->emAberto = 1;
$atribuicao->periodo  = periodoDeChuva();
$calculo->registrarAtribuicao($p);
$c = linhaDe('order_c');
confere(($c['motoboy_uuid'] ?? null) === 'uuid-driver_motoca' && ($c['posicao'] ?? null) === 2 && ($c['multiplicador_uuid'] ?? null) === 'mult-chuva' && ($c['multiplicador_nome'] ?? null) === 'Chuva' && ($c['mult_motoboy'] ?? null) === 1.2 && ($c['mult_loja'] ?? null) === 1.1, 'grava o motoboy, a posição 2 e a chuva');
confere(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($c['atribuido_em'] ?? '')) === 1, 'o instante da atribuição em Brasília, sem fuso');
confere(($c['junto_motoboy'] ?? null) === 6.0 && ($c['valor_motoboy'] ?? null) === 8.0, 'e congela o km com as duas tabelas');
$p->status  = 'completed';
[$entregas] = $calculo->entregas(new Collection([$p]), 'America/Sao_Paulo');
confere($entregas[0]['valor_motoboy'] === 7.2 && $entregas[0]['valor_loja'] === 9.9 && $entregas[0]['tabela'] === 'junto' && $entregas[0]['posicao'] === 2, 'no relatório: 7,20 / 9,90 pela segunda tabela');
$atribuicao->emAberto = 0;
$atribuicao->periodo  = null;
confere($calculo->valorDoPedido($p, 'uuid-driver_motoca')['valor_motoboy'] === 7.2, 'atribuição gravada: o card mostra ela, não a prévia');

comKm($p, 4500.0);
[$entregas] = $calculo->entregas(new Collection([$p]), 'America/Sao_Paulo');
$c          = linhaDe('order_c');
confere($c['metros'] === 4500 && $c['posicao'] === 2 && $c['mult_motoboy'] === 1.2, 'km novo: recongela o km e mantém a atribuição');
confere($entregas[0]['valor_motoboy'] === 7.2 && $entregas[0]['faixa']['de_km'] === 3.0, '4,5 km: mesma faixa da segunda tabela (3 a 6) × 1,2');

$p->driver_assigned_uuid = 'uuid-driver_outro';
$calculo->registrarAtribuicao($p);
$c = linhaDe('order_c');
confere($c['motoboy_uuid'] === 'uuid-driver_outro' && $c['posicao'] === 1 && $c['multiplicador_uuid'] === null && $c['mult_motoboy'] === null, 'troca de motoboy: recalcula para o novo (1ª, sem período)');
$p->driver_assigned_uuid = null;
$calculo->registrarAtribuicao($p);
$c = linhaDe('order_c');
confere($c['motoboy_uuid'] === null && $c['posicao'] === null && $c['atribuido_em'] === null, 'pedido sem motoboy: a atribuição é apagada');
confere($c['metros'] === 4500 && $c['valor_motoboy'] === 10.0, 'e a parte do km fica');

reiniciar();
$atribuicao = new AtribuicaoFalsa();
$calculo    = new CalculoEntregas(null, $atribuicao);
$l          = comKm(pedido('order_l'), 3210.4);
$calculo->entregas(new Collection([$l]), 'America/Sao_Paulo');
Banco::$tabelas['entregas_lancamentos'] = [1 => ['id' => 1, 'order_uuid' => 'uuid-order_l', 'tipo' => 'corrida', 'conta_tipo' => 'motoboy', 'valor' => '8.00']];
$atribuicao->emAberto = 2;
$atribuicao->periodo  = periodoDeChuva();
$calculo->registrarAtribuicao($l);
confere((linhaDe('order_l')['posicao'] ?? null) === null && $atribuicao->chamadas === [], 'corrida lançada no financeiro: a atribuição não muda (nem consulta)');

$s = pedido('order_s', ['payload' => new Payload(lugar('place_loja', null, null, 'Loja'), lugar('place_x', null, null, 'Cliente'))]);
$calculo->registrarAtribuicao($s);
confere(linhaDe('order_s') === [] && logou('sem km na atribuição'), 'pedido sem endereço: nada gravado, fica para a reserva (log)');

echo '== Reserva por horários (pedido que escapou do observador)' . PHP_EOL;
reiniciar();
$atribuicao = new AtribuicaoFalsa();
$calculo    = new CalculoEntregas(null, $atribuicao);
$r          = comKm(pedido('order_r', ['started_at' => '2026-10-03 19:00:00']), 3210.4);
$atribuicao->periodos = [periodoDeChuva()];
$atribuicao->posicoes = ['uuid-order_r' => 2];
[$entregas] = $calculo->entregas(new Collection([$r]), 'America/Sao_Paulo');
confere($entregas[0]['valor_motoboy'] === 7.2 && $entregas[0]['valor_loja'] === 9.9 && $entregas[0]['posicao'] === 2 && $entregas[0]['tabela'] === 'junto', 'reconstitui pelo aceite (19:00, dentro da chuva) e pela posição dos horários');
$x = linhaDe('order_r');
confere(($x['atribuido_em'] ?? null) === '2026-10-03 19:00:00' && ($x['motoboy_uuid'] ?? null) === 'uuid-driver_motoca' && ($x['posicao'] ?? null) === 2 && ($x['mult_motoboy'] ?? null) === 1.2, 'e grava a atribuição reconstituída');
$chamada = array_values(array_filter($atribuicao->chamadas, fn ($c) => $c[0] === 'posicoesPorHorarios'))[0] ?? null;
confere($chamada !== null && $chamada[1] === [['uuid' => 'uuid-order_r', 'motoboy' => 'uuid-driver_motoca', 'instante' => '2026-10-03 19:00:00']], 'as posições numa consulta só, com o aceite');
$antes = count($atribuicao->chamadas);
$calculo->entregas(new Collection([$r]), 'America/Sao_Paulo');
confere(count($atribuicao->chamadas) === $antes, 'na consulta seguinte, já gravada: nada a reconstituir');

$d          = comKm(pedido('order_d', ['dispatched_at' => '2026-10-03 20:00:00']), 3210.4);
$atribuicao->posicoes = [];
[$entregas] = $calculo->entregas(new Collection([$d]), 'America/Sao_Paulo');
confere($entregas[0]['valor_motoboy'] === 9.6 && (linhaDe('order_d')['atribuido_em'] ?? null) === '2026-10-03 20:00:00', 'sem started_at: vale o dispatched_at (atribuído pela central); 1ª com chuva = 9,60');

reiniciar([]);
$atribuicao = new AtribuicaoFalsa();
$calculo    = new CalculoEntregas(null, $atribuicao);
$q          = comKm(pedido('order_q', ['started_at' => '2026-10-03 23:30:00']), 3210.4);
$atribuicao->periodos = [periodoDeChuva()];
[$entregas] = $calculo->entregas(new Collection([$q]), 'America/Sao_Paulo');
confere($entregas[0]['valor_motoboy'] === 8.0 && (linhaDe('order_q')['motoboy_uuid'] ?? null) === null, 'aceite fora do período e sem segunda tabela: nada muda, nada gravado');
confere(!array_filter($atribuicao->chamadas, fn ($c) => $c[0] === 'posicoesPorHorarios'), 'nem consulta as posições');

echo '== Observador (Order::updated)' . PHP_EOL;
reiniciar();
$registra = new CalculoQueRegistra(null, new AtribuicaoFalsa());
ObservadorDoValorDaEntrega::$calculo = fn () => $registra;
Order::$todos = [comKm(pedido('order_o', ['company_uuid' => 'empresa-o']), 3210.4)];
$evento = fn (array $mudou, string $uuid = 'uuid-order_o') => new class($mudou, $uuid) {
    public $public_id    = 'order_o';
    public $company_uuid = 'empresa-o';
    public function __construct(private array $mudou, public string $uuid) {}
    public function wasChanged($campo) { return in_array($campo, $this->mudou, true); }
};
ObservadorDoValorDaEntrega::aoAtualizar($evento(['status']));
confere($registra->registrados === [], 'sem troca de motoboy: nada');
ObservadorDoValorDaEntrega::aoAtualizar($evento(['driver_assigned_uuid', 'status']));
confere($registra->registrados === ['uuid-order_o'] && $registra->sessoes === ['empresa-o'], 'motoboy mudou: registra o pedido relido, com a empresa dele na sessão');
confere(session('company') === 'empresa', 'e devolve a sessão como estava');
ObservadorDoValorDaEntrega::aoAtualizar($evento(['driver_assigned_uuid'], 'uuid-sumiu'));
confere(count($registra->registrados) === 1, 'pedido que não se acha mais: nada');
$registra->falhar = true;
ObservadorDoValorDaEntrega::aoAtualizar($evento(['driver_assigned_uuid']));
confere(logou('falha ao gravar a atribuição') && session('company') === 'empresa', 'falha: só o log, nunca lança, e a sessão volta');
ObservadorDoValorDaEntrega::$calculo = null;
$app = str_replace("\r\n", "\n", file_get_contents('/repo/api/app/Providers/AppServiceProvider.php'));
confere(str_contains($app, 'Order::updated(fn ($pedido) => ObservadorDoValorDaEntrega::aoAtualizar($pedido));') && str_contains($app, '$this->congelarValorNaAtribuicao();') && str_contains($app, 'use App\Listeners\Entregas\ObservadorDoValorDaEntrega;'), 'AppServiceProvider: Order::updated → observador da atribuição');

resumo();
