<?php

// Financeiro: lançamento automático das corridas (LancadorDeCorridas), conferência, estorno dos cancelados depois da
// conclusão, e o observador do pedido (ObservadorFinanceiroDosPedidos + job LancarCorridaDoPedido). O CalculoEntregas é
// trocado por um falso (os pedidos e os valores vêm prontos); o próprio CalculoEntregas, com o valor que não muda depois
// de lançado, é testado no ganhos-motoboy.php.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/financeiro-lancador.php

require __DIR__ . '/stubs-financeiro.php';

use App\Console\Commands\Entregas\FinanceiroConferir;
use App\Console\Commands\Entregas\FinanceiroLancarHistorico;
use App\Jobs\Entregas\LancarCorridaDoPedido;
use App\Listeners\Entregas\ObservadorFinanceiroDosPedidos;
use App\Support\Entregas\CalculoEntregas;
use App\Support\Entregas\Financeiro\LancadorDeCorridas;
use Teste\Relogio;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Teste\Banco;

/** A query que o filtro do pedidosConcluidos recebe: registra as chamadas (whereNotExists roda a subconsulta num registrador). */
class QueryRegistrada
{
    public array $chamadas = [];

    public function __call($metodo, $argumentos)
    {
        if (in_array($metodo, ['whereExists', 'whereNotExists'], true) && ($argumentos[0] ?? null) instanceof \Closure) {
            $sub = new QueryRegistrada();
            ($argumentos[0])($sub);
            $argumentos = [$sub->chamadas];
        }
        $this->chamadas[] = [$metodo, $argumentos];

        return $this;
    }
}

// o CalculoEntregas falso: os pedidos de teste (Order::$todos) com os valores prontos; o filtro é interpretado como o SQL
// (orders.uuid = / in; whereNotExists da corrida do motoboy)
class CalculoFalso extends CalculoEntregas
{
    public array $chamadas = [];
    public array $sessaoNoCalculo = [];
    public array $fusos = [];

    public function pedidosConcluidos(string $companyUuid, Carbon $inicio, Carbon $fim, ?\Closure $filtro = null): Collection
    {
        $query = new QueryRegistrada();
        if ($filtro) {
            $filtro($query);
        }
        $this->chamadas[] = ['empresa' => $companyUuid, 'inicio' => $inicio->format('Y-m-d H:i:s'), 'fim' => $fim->format('Y-m-d H:i:s'), 'filtro' => $query->chamadas];

        $pedidos = array_filter(Order::$todos, function ($p) use ($companyUuid, $inicio, $fim, $query) {
            $pagavel = $p->status === 'completed' || !empty($p->pago_mesmo_cancelado);
            if ($p->company_uuid !== $companyUuid || !$pagavel || !$p->driver_assigned_uuid || $p->concluido < $inicio->format('Y-m-d H:i:s') || $p->concluido > $fim->format('Y-m-d H:i:s')) {
                return false;
            }
            foreach ($query->chamadas as [$metodo, $args]) {
                if ($metodo === 'where' && $args[0] === 'orders.uuid' && $p->uuid !== $args[1]) {
                    return false;
                }
                if ($metodo === 'whereIn' && $args[0] === 'orders.uuid' && !in_array($p->uuid, $args[1], true)) {
                    return false;
                }
                if (in_array($metodo, ['whereExists', 'whereNotExists'], true)) {
                    // a subconsulta: from entregas_lancamentos (corrida da conta_tipo do where) ou from vendors (cliente é loja)
                    $de    = null;
                    $conta = null;
                    foreach ($args[0] as [$m, $a]) {
                        $de    = $m === 'from' ? $a[0] : $de;
                        $conta = $m === 'where' && $a[0] === 'entregas_lancamentos.conta_tipo' ? $a[1] : $conta;
                    }
                    $existe = false;
                    if ($de === 'vendors') {
                        $existe = $p->customer_uuid !== null;
                    } else {
                        foreach (Banco::$tabelas['entregas_lancamentos'] ?? [] as $l) {
                            $existe = $existe || ($l['order_uuid'] === $p->uuid && $l['conta_tipo'] === $conta && $l['tipo'] === 'corrida');
                        }
                    }
                    if ($existe !== ($metodo === 'whereExists')) {
                        return false;
                    }
                }
            }

            return true;
        });
        foreach ($pedidos as $p) {
            $p->entregas_concluido_em = $p->concluido;
        }

        return new Collection(array_values($pedidos));
    }

    public function entregas(Collection $pedidos, string $fuso, int $limiteCalculos = self::LIMITE_CALCULOS): array
    {
        $this->sessaoNoCalculo[] = session('company');
        $this->fusos[]           = $fuso;
        $entregas                = [];
        foreach ($pedidos as $p) {
            $faixa      = $p->valor_m === null ? null : ['de_km' => 2.0, 'ate_km' => 4.0, 'motoboy' => $p->valor_m, 'loja' => $p->valor_l];
            $entregas[] = [
                'loja'             => $p->customer_uuid ? 'loja:vendor_a' : null,
                'loja_nome'        => 'Pizzaria Ficticia',
                'pedido'           => $p->public_id,
                'id_interno'       => $p->internal_id,
                'motoboy'          => 'driver_1',
                'motoboy_nome'     => 'Motoca',
                'concluido_em'     => Carbon::parse($p->concluido, date_default_timezone_get())->setTimezone(new \DateTimeZone($fuso))->toIso8601String(),
                'origem'           => 'Rua da Loja, 1',
                'destino'          => 'Rua X, 10',
                'km'               => $p->valor_m === null ? null : 3.21,
                'fonte'            => 'osrm',
                'faixa'            => $faixa,
                'valor_motoboy'    => $p->valor_m,
                'valor_loja'       => $p->valor_m === null ? null : $p->valor_l,
                'cancelado_pago'   => $p->status !== 'completed',
                'cancelado_origem' => $p->status !== 'completed' ? 'ifood' : null,
                'order_uuid'       => $p->uuid,
                'motoboy_uuid'     => $p->driver_assigned_uuid,
                'loja_uuid'        => $p->customer_uuid,
            ];
        }

        return [$entregas, 0];
    }
}

/** Pedido concluído do motoboy driver-1, da loja vendor-a (8,00 ao motoboy, 11,37 da loja). */
function pedidoConcluido(string $id, string $concluido, array $extra = []): Order
{
    $pedido = new Order($extra + [
        'uuid'                 => 'uuid-' . $id,
        'public_id'            => $id,
        'internal_id'          => 'RP' . substr(md5($id), 0, 4),
        'company_uuid'         => 'empresa-1',
        'status'               => 'completed',
        'driver_assigned_uuid' => 'driver-1',
        'customer_uuid'        => 'vendor-a',
        'concluido'            => $concluido,
        'valor_m'              => 8.0,
        'valor_l'              => 11.37,
    ]);
    Order::$todos[] = $pedido;

    return $pedido;
}

function lancamentosDo(string $pedido): array
{
    return array_values(array_filter(linhasDe('entregas_lancamentos'), fn ($l) => $l['order_uuid'] === 'uuid-' . $pedido));
}

function daConta(array $lancamentos, string $conta): ?array
{
    foreach ($lancamentos as $l) {
        if ($l['conta_tipo'] === $conta && $l['tipo'] === 'corrida') {
            return $l;
        }
    }

    return null;
}

echo '== lancar' . PHP_EOL;
reiniciarFinanceiro();
\Teste\Sessao::$dados = [];
$calculo  = new CalculoFalso();
$lancador = new LancadorDeCorridas($calculo);
// 22:30 de Brasília do dia 3 = 01:30 UTC do dia 4: a data de referência é o dia 3
$a        = pedidoConcluido('order_a', '2026-10-03 22:30:00');
$r        = $lancador->lancar('empresa-1', new Collection([$a]));
$linhas   = lancamentosDo('order_a');
$moto     = daConta($linhas, 'motoboy');
$loja     = daConta($linhas, 'loja');
confere($r === ['lancados' => 2, 'sem_valor' => 0] && count($linhas) === 2, 'pedido concluído: dois lançamentos (' . json_encode($r) . ')');
confere($moto && $moto['conta_uuid'] === 'driver-1' && (float) $moto['valor'] === 8.0 && $moto['chave_unica'] === 'corrida:motoboy:uuid-order_a', 'motoboy: valor_motoboy, com a chave do pedido');
confere($loja && $loja['conta_uuid'] === 'vendor-a' && (float) $loja['valor'] === 11.37 && $loja['chave_unica'] === 'corrida:loja:uuid-order_a' && $loja['fatura_uuid'] === null, 'loja: valor_loja, sem fatura');
confere($moto['data_referencia'] === '2026-10-03' && $loja['data_referencia'] === '2026-10-03', 'data de referência: o dia da conclusão em Brasília (22:30 do dia 3)');
confere($moto['company_uuid'] === 'empresa-1' && $moto['criado_por'] === null && $moto['tipo'] === 'corrida', 'da empresa, lançado pelo sistema');
$detalhes = json_decode((string) $moto['detalhes'], true);
confere(array_keys($detalhes) === ['pedido', 'numero', 'km', 'faixa', 'loja_nome', 'destino', 'cancelado_pago', 'cancelado_origem'], 'detalhes no formato do contrato');
confere($detalhes['pedido'] === 'order_a' && $detalhes['numero'] === $a->internal_id && $detalhes['km'] === 3.21 && $detalhes['faixa'] == ['de_km' => 2, 'ate_km' => 4, 'acima' => false] && array_keys($detalhes['faixa']) === ['de_km', 'ate_km', 'acima'] && $detalhes['loja_nome'] === 'Pizzaria Ficticia' && $detalhes['destino'] === 'Rua X, 10', 'pedido, número, km, faixa só com os limites, loja e destino');
confere(!str_contains((string) $moto['detalhes'], '11.37') && !str_contains((string) $moto['detalhes'], 'motoboy'), 'a conta do motoboy não guarda o valor da loja');
confere($calculo->sessaoNoCalculo === ['empresa-1'] && $calculo->fusos === ['America/Sao_Paulo'], 'calcula com a empresa na sessão (faixas) e no fuso dela');

$r = $lancador->lancar('empresa-1', new Collection([$a]));
confere($r === ['lancados' => 0, 'sem_valor' => 0] && count(linhasDe('entregas_lancamentos')) === 2, 'repetido: não duplica');

$b = pedidoConcluido('order_b', '2026-10-04 10:00:00', ['customer_uuid' => null]);
$r = $lancador->lancar('empresa-1', new Collection([$b]));
confere($r['lancados'] === 1 && count(lancamentosDo('order_b')) === 1 && daConta(lancamentosDo('order_b'), 'motoboy'), 'sem loja (Vendor): só o motoboy');

$c = pedidoConcluido('order_c', '2026-09-30 23:59:00');
$r = $lancador->lancar('empresa-1', new Collection([$c]));
confere($r === ['lancados' => 0, 'sem_valor' => 0] && !lancamentosDo('order_c'), 'concluído antes do corte (1º/10): nada');

$d = pedidoConcluido('order_d', '2026-10-04 11:00:00', ['valor_m' => null]);
$r = $lancador->lancar('empresa-1', new Collection([$d]));
confere($r === ['lancados' => 0, 'sem_valor' => 1] && !lancamentosDo('order_d'), 'sem valor: sem_valor e nada lançado');

$e = pedidoConcluido('order_e', '2026-10-04 12:00:00', ['status' => 'canceled', 'pago_mesmo_cancelado' => true]);
$r = $lancador->lancar('empresa-1', new Collection([$e]));
$det = json_decode((string) (daConta(lancamentosDo('order_e'), 'motoboy')['detalhes'] ?? 'null'), true);
confere($r['lancados'] === 2 && ($det['cancelado_pago'] ?? null) === true && ($det['cancelado_origem'] ?? null) === 'ifood', 'cancelado pago entra, marcado nos detalhes');

Setting::$valores = [];
$f                = pedidoConcluido('order_f', '2026-10-04 13:00:00');
confere($lancador->lancar('empresa-1', new Collection([$f])) === ['lancados' => 0, 'sem_valor' => 0] && !lancamentosDo('order_f'), 'empresa sem data de corte: nada é lançado');

echo '== conferir' . PHP_EOL;
reiniciarFinanceiro();
$calculo  = new CalculoFalso();
$lancador = new LancadorDeCorridas($calculo);
$ja       = pedidoConcluido('order_ja', '2026-10-04 10:00:00');
$lancador->lancar('empresa-1', new Collection([$ja]));
$falta    = pedidoConcluido('order_falta', '2026-10-04 11:00:00');
$semValor = pedidoConcluido('order_sv', '2026-10-04 12:00:00', ['valor_m' => null]);
$calculo->chamadas = [];
$semLanc           = $lancador->pedidosSemLancamento('empresa-1', Carbon::parse('2026-09-28 00:00:00'));
confere(array_map(fn ($p) => $p->public_id, $semLanc->all()) === ['order_falta', 'order_sv'] && count(linhasDe('entregas_lancamentos')) === 2, 'pedidosSemLancamento: só consulta (o painel usa), sem lançar');
$calculo->chamadas = [];
$r = $lancador->conferir('empresa-1', Carbon::parse('2026-09-28 00:00:00'));
confere($r['lancados'] === 2 && $r['sem_valor'] === 1 && count(lancamentosDo('order_falta')) === 2, 'lança o que faltou (' . json_encode(['lancados' => $r['lancados'], 'sem_valor' => $r['sem_valor']]) . ')');
confere(array_column($r['sem_lancamento'], 'id') === ['order_sv'] && ($r['sem_lancamento'][0]['numero'] ?? null) === $semValor->internal_id && ($r['sem_lancamento'][0]['concluido_em'] ?? null) === '2026-10-04 12:00:00', 'e devolve os que continuam sem lançamento (sem valor)');
$chamada = $calculo->chamadas[0] ?? [];
confere(($chamada['inicio'] ?? null) === '2026-09-28 00:00:00' && ($chamada['fim'] ?? null) === '2026-10-05 15:00:00', 'de $inicio até agora');
$sub = $chamada['filtro'][0][1][0] ?? [];
confere(($chamada['filtro'][0][0] ?? null) === 'whereNotExists' && in_array(['from', ['entregas_lancamentos']], $sub, true) && in_array(['whereColumn', ['entregas_lancamentos.order_uuid', 'orders.uuid']], $sub, true), 'o banco já tira os pedidos com corrida lançada (whereNotExists)');
confere(in_array(['where', ['entregas_lancamentos.conta_tipo', 'motoboy']], $sub, true) && in_array(['where', ['entregas_lancamentos.tipo', 'corrida']], $sub, true), 'pela corrida do motoboy');
$calculo->chamadas = [];
Setting::$valores  = [];
confere($lancador->conferir('empresa-1', Carbon::parse('2026-09-28 00:00:00')) === ['lancados' => 0, 'sem_valor' => 0, 'sem_lancamento' => []] && $calculo->chamadas === [], 'sem data de corte: nem consulta');

echo '== Corrida da loja que faltou (lançamento numa transação; a conferência refaz)' . PHP_EOL;
reiniciarFinanceiro();
$calculo  = new CalculoFalso();
$lancador = new LancadorDeCorridas($calculo);
$t        = pedidoConcluido('order_t', '2026-10-04 10:00:00');
// o insert da loja (o segundo) falha: o do motoboy não pode ficar sozinho
Banco::$antesDeInserir['entregas_lancamentos'] = function () {
    Banco::$antesDeInserir['entregas_lancamentos'] = function () {
        Banco::$falharComo['entregas_lancamentos'] = ['HY000', 2006];
    };
};
confere(excecao(fn () => $lancador->lancar('empresa-1', new Collection([$t]))) !== null && !lancamentosDo('order_t'), 'falha no lançamento da loja: o do motoboy é desfeito (os dois numa transação)');
unset(Banco::$antesDeInserir['entregas_lancamentos'], Banco::$falharComo['entregas_lancamentos']);
// o que já está no banco de antes da transação: corrida do motoboy sem a da loja (pedido com loja)
$s = pedidoConcluido('order_s', '2026-10-04 11:00:00');
$u = pedidoConcluido('order_u', '2026-10-04 12:00:00', ['customer_uuid' => null]);
foreach ([$s, $u] as $pedido) {
    App\Support\Entregas\Financeiro\Lancamentos::inserir(['company_uuid' => 'empresa-1', 'conta_tipo' => 'motoboy', 'conta_uuid' => 'driver-1', 'tipo' => 'corrida', 'valor' => 8,
        'data_referencia' => '2026-10-04', 'order_uuid' => $pedido->uuid, 'chave_unica' => 'corrida:motoboy:' . $pedido->uuid]);
}
$calculo->chamadas = [];
$r = $lancador->conferir('empresa-1', Carbon::parse('2026-09-28 00:00:00'));
confere(count(lancamentosDo('order_t')) === 2 && daConta(lancamentosDo('order_s'), 'loja') && (float) daConta(lancamentosDo('order_s'), 'loja')['valor'] === 11.37, 'conferência: lança o que faltou e a corrida da loja que faltou (' . json_encode(['lancados' => $r['lancados']]) . ')');
confere(count(lancamentosDo('order_s')) === 2 && count(lancamentosDo('order_u')) === 1, 'sem duplicar a do motoboy; pedido sem loja continua só com o motoboy');
$daLoja = $calculo->chamadas[1]['filtro'] ?? [];
confere(array_column($daLoja, 0) === ['whereExists', 'whereNotExists', 'whereExists'] && in_array(['from', ['vendors']], $daLoja[2][1][0] ?? [], true), 'a consulta da loja: com corrida do motoboy, sem a da loja, cliente é loja (vendors)');
$calculo->chamadas = [];
$lancador->conferir('empresa-1', Carbon::parse('2026-09-28 00:00:00'));
confere(count(linhasDe('entregas_lancamentos')) === 5, 'de novo: nada');

echo '== estornarCancelados' . PHP_EOL;
reiniciarFinanceiro();
$calculo  = new CalculoFalso();
$lancador = new LancadorDeCorridas($calculo);
$cancelado = pedidoConcluido('order_x', '2026-10-03 10:00:00');
$ok        = pedidoConcluido('order_ok', '2026-10-03 11:00:00');
$pago      = pedidoConcluido('order_pago', '2026-10-03 12:00:00', ['status' => 'canceled', 'pago_mesmo_cancelado' => true]);
$semMoto   = pedidoConcluido('order_sm', '2026-10-03 13:00:00');
$lancador->lancar('empresa-1', new Collection([$cancelado, $ok, $pago, $semMoto]));
confere(count(linhasDe('entregas_lancamentos')) === 8, 'quatro pedidos lançados (motoboy e loja)');
$cancelado->status            = 'canceled';
$semMoto->driver_assigned_uuid = null; // deixou de entrar no relatório, mas continua concluído
$n       = $lancador->estornarCancelados('empresa-1', Carbon::parse('2026-09-05 15:00:00'));
$estorno = array_values(array_filter(linhasDe('entregas_lancamentos'), fn ($l) => $l['tipo'] === 'estorno'));
confere($n === 2 && count($estorno) === 2, "pedido concluído e depois cancelado sem pagamento: estorna os dois lançamentos ({$n})");
confere(count(array_filter($estorno, fn ($l) => $l['order_uuid'] === 'uuid-order_x' && $l['motivo'] === 'Pedido cancelado após a conclusão' && $l['criado_por'] === null)) === 2, 'com o motivo, pelo sistema');
confere((float) (array_values(array_filter($estorno, fn ($l) => $l['conta_tipo'] === 'motoboy'))[0]['valor'] ?? 0) === -8.0, 'o estorno tem o valor oposto');
confere($lancador->estornarCancelados('empresa-1', Carbon::parse('2026-09-05 15:00:00')) === 0, 'de novo: nada (já estornado)');
confere(count(array_filter(linhasDe('entregas_lancamentos'), fn ($l) => $l['tipo'] === 'estorno' && in_array($l['order_uuid'], ['uuid-order_ok', 'uuid-order_pago', 'uuid-order_sm'], true))) === 0, 'concluído, cancelado pago e concluído sem motoboy: intactos');
confere($lancador->estornarCancelados('empresa-1', Carbon::parse('2026-10-04 00:00:00')) === 0, 'só olha os lançamentos a partir de $desde');

echo '== estornarCancelados depois de "Não pagar" / "Não cobrar" (não debita duas vezes)' . PHP_EOL;

/** Ajuste com pedido (o "Não pagar" do motoboy, o "Não cobrar" da loja, ou um desconto parcial). */
function ajusteDoPedido(string $conta, string $contaUuid, string $pedido, float $valor): int
{
    return App\Support\Entregas\Financeiro\Lancamentos::inserir(['company_uuid' => 'empresa-1', 'conta_tipo' => $conta, 'conta_uuid' => $contaUuid,
        'tipo' => 'ajuste', 'valor' => $valor, 'data_referencia' => '2026-10-04', 'order_uuid' => $pedido, 'motivo' => 'Ajuste', 'criado_por' => 'user-admin']);
}

/** Soma dos lançamentos do pedido na conta (o que o motoboy/a loja ainda tem daquele pedido). */
function liquidoDoPedido(string $pedido, string $conta): float
{
    return round(array_sum(array_map(fn ($l) => (float) $l['valor'], array_filter(linhasDe('entregas_lancamentos'), fn ($l) => $l['order_uuid'] === 'uuid-' . $pedido && $l['conta_tipo'] === $conta))), 2);
}

reiniciarFinanceiro();
$calculo  = new CalculoFalso();
$lancador = new LancadorDeCorridas($calculo);
$np       = pedidoConcluido('order_np', '2026-10-03 10:00:00');
$pp       = pedidoConcluido('order_pp', '2026-10-03 11:00:00');
$ae       = pedidoConcluido('order_ae', '2026-10-03 12:00:00');
$lancador->lancar('empresa-1', new Collection([$np, $pp, $ae]));
ajusteDoPedido('motoboy', 'driver-1', 'uuid-order_np', -8.0);    // "Não pagar" inteiro
ajusteDoPedido('loja', 'vendor-a', 'uuid-order_np', -11.37);     // "Não cobrar" inteiro
ajusteDoPedido('motoboy', 'driver-1', 'uuid-order_pp', -3.0);    // desconto de 3,00
ajusteDoPedido('motoboy', 'driver-2', 'uuid-order_pp', -5.0);    // de outra conta: não conta
$estornado = ajusteDoPedido('motoboy', 'driver-1', 'uuid-order_ae', -8.0);
App\Support\Entregas\Financeiro\Lancamentos::estornar(App\Support\Entregas\Financeiro\Lancamentos::porId('empresa-1', $estornado), 'user-admin', null);
foreach ([$np, $pp, $ae] as $pedido) {
    $pedido->status = 'canceled';
}
$n = $lancador->estornarCancelados('empresa-1', Carbon::parse('2026-09-05 15:00:00'));
confere(liquidoDoPedido('order_np', 'motoboy') === 0.0 && liquidoDoPedido('order_np', 'loja') === 0.0, '"Não pagar"/"Não cobrar" do valor inteiro: o estorno automático não tira de novo (motoboy ' . liquidoDoPedido('order_np', 'motoboy') . ', loja ' . liquidoDoPedido('order_np', 'loja') . ')');
$parcial = array_values(array_filter(linhasDe('entregas_lancamentos'), fn ($l) => $l['tipo'] === 'estorno' && $l['order_uuid'] === 'uuid-order_pp' && $l['conta_tipo'] === 'motoboy' && $l['motivo'] === 'Pedido cancelado após a conclusão'));
confere(count($parcial) === 1 && (float) $parcial[0]['valor'] === -5.0 && liquidoDoPedido('order_pp', 'loja') === 0.0, 'desconto de 3,00 antes: estorna só os 5,00 que sobram (' . json_encode(array_column($parcial, 'valor')) . ')');
confere(round(array_sum(array_map(fn ($l) => (float) $l['valor'], array_filter(linhasDe('entregas_lancamentos'), fn ($l) => $l['order_uuid'] === 'uuid-order_pp' && $l['conta_uuid'] === 'driver-1'))), 2) === 0.0, 'o motoboy fica com zero do pedido cancelado');
confere(liquidoDoPedido('order_ae', 'motoboy') === 0.0 && count(array_filter(linhasDe('entregas_lancamentos'), fn ($l) => $l['tipo'] === 'estorno' && $l['order_uuid'] === 'uuid-order_ae' && $l['conta_tipo'] === 'motoboy' && $l['motivo'] === 'Pedido cancelado após a conclusão' && (float) $l['valor'] === -8.0)) === 1, 'ajuste já estornado não desconta: a corrida é estornada inteira');
confere($n === 4, "estornos criados: motoboy do order_pp e do order_ae, loja do order_pp e do order_ae ({$n})");
confere($lancador->estornarCancelados('empresa-1', Carbon::parse('2026-09-05 15:00:00')) === 0 && liquidoDoPedido('order_np', 'motoboy') === 0.0, 'de novo: nada');

echo '== relancarEstornados (o pedido voltou a valer depois do estorno automático)' . PHP_EOL;
reiniciarFinanceiro();
$calculo  = new CalculoFalso();
$lancador = new LancadorDeCorridas($calculo);
$v        = pedidoConcluido('order_v', '2026-10-03 10:00:00');
$w        = pedidoConcluido('order_w', '2026-10-03 11:00:00');
$lancador->lancar('empresa-1', new Collection([$v, $w]));
ajusteDoPedido('motoboy', 'driver-1', 'uuid-order_v', -3.0); // desconto antes do cancelamento: o motoboy tinha 5,00
$v->status = 'canceled';
$w->status = 'canceled';
$lancador->estornarCancelados('empresa-1', Carbon::parse('2026-09-05 15:00:00'));
confere(liquidoDoPedido('order_v', 'motoboy') === 0.0 && liquidoDoPedido('order_v', 'loja') === 0.0, 'cancelado: estornado');
// order_w: depois do estorno automático, o motoboy ganhou uma corrida nova que o admin estornou à mão (a última corrida
// do motoboy está estornada à mão: não volta); a da loja só tem o estorno automático (volta)
$w->pago_mesmo_cancelado = true;
$wManual = App\Support\Entregas\Financeiro\Lancamentos::inserir(['company_uuid' => 'empresa-1', 'conta_tipo' => 'motoboy', 'conta_uuid' => 'driver-1', 'tipo' => 'corrida',
    'valor' => 8, 'data_referencia' => '2026-10-03', 'order_uuid' => 'uuid-order_w', 'chave_unica' => 'corrida:motoboy:uuid-order_w:2']);
App\Support\Entregas\Financeiro\Lancamentos::estornar(App\Support\Entregas\Financeiro\Lancamentos::porId('empresa-1', $wManual), 'user-admin', 'Lançada por engano');
$v->pago_mesmo_cancelado = true; // o iFood marcou depois como cancelado pago
$n      = $lancador->relancarEstornados('empresa-1', Carbon::parse('2026-09-05 15:00:00'));
$nova   = array_values(array_filter(linhasDe('entregas_lancamentos'), fn ($l) => $l['chave_unica'] === 'corrida:motoboy:uuid-order_v:2'));
confere(count($nova) === 1 && $nova[0]['tipo'] === 'corrida' && (float) $nova[0]['valor'] === 5.0 && $nova[0]['data_referencia'] === '2026-10-03' && $nova[0]['criado_por'] === null, 'motoboy: corrida relançada (chave :2) com o que o estorno tirou (5,00), na data da corrida: ' . json_encode($nova[0] ?? null));
confere(liquidoDoPedido('order_v', 'motoboy') === 5.0 && liquidoDoPedido('order_v', 'loja') === 11.37, 'o pedido volta a valer como antes do cancelamento (motoboy 5,00 com o desconto; loja 11,37)');
confere(($nova[0]['motivo'] ?? null) === 'Pedido voltou a valer depois do cancelamento' && json_decode((string) $nova[0]['detalhes'], true)['pedido'] === 'order_v', 'com o motivo e os detalhes da corrida');
confere(liquidoDoPedido('order_w', 'motoboy') === 0.0 && liquidoDoPedido('order_w', 'loja') === 11.37, 'corrida estornada à mão pelo admin não volta; a estornada pelo sistema volta');
confere($n === 3, "relançados: motoboy e loja do order_v e loja do order_w ({$n})");
confere($lancador->relancarEstornados('empresa-1', Carbon::parse('2026-09-05 15:00:00')) === 0, 'de novo: nada (já tem corrida valendo)');
$v->pago_mesmo_cancelado = false;
$lancador->estornarCancelados('empresa-1', Carbon::parse('2026-09-05 15:00:00'));
confere(liquidoDoPedido('order_v', 'motoboy') === 0.0 && liquidoDoPedido('order_v', 'loja') === 0.0, 'cancelado de novo: estorna a corrida relançada');
$v->pago_mesmo_cancelado = true;
$lancador->relancarEstornados('empresa-1', Carbon::parse('2026-09-05 15:00:00'));
confere(count(array_filter(linhasDe('entregas_lancamentos'), fn ($l) => $l['chave_unica'] === 'corrida:motoboy:uuid-order_v:3')) === 1 && liquidoDoPedido('order_v', 'motoboy') === 5.0, 'e volta de novo (chave :3)');
$lancamentos = array_filter(linhasDe('entregas_lancamentos'), fn ($l) => $l['conta_uuid'] === 'driver-1' && $l['order_uuid'] === 'uuid-order_v');
$situacoes   = App\Support\Entregas\Financeiro\Quitacao::creditos(array_map(fn ($l) => (object) $l, $lancamentos));
confere(count(array_filter($situacoes, fn ($s) => $s['situacao'] === 'aberto')) === 1 && round(array_sum(array_column($situacoes, 'restante')), 2) === 5.0, 'no extrato: só a corrida que vale fica a receber (5,00)');

echo '== lancarPedido (job)' . PHP_EOL;
reiniciarFinanceiro();
// o worker da fila reaproveita o processo entre jobs: a sessão volta como estava
\Teste\Sessao::$dados = ['company' => 'empresa-anterior'];
$calculo  = new CalculoFalso();
$lancador = new LancadorDeCorridas($calculo);
$g        = pedidoConcluido('order_g', '2026-10-05 14:00:00');
pedidoConcluido('order_h', '2026-10-05 14:10:00');
$lancador->lancarPedido('uuid-order_g');
confere(count(lancamentosDo('order_g')) === 2 && !lancamentosDo('order_h'), 'lança só o pedido pedido');
confere($calculo->sessaoNoCalculo === ['empresa-1'], 'calcula com a empresa do pedido na sessão');
confere(session('company') === 'empresa-anterior', 'e devolve a sessão como estava (' . json_encode(session('company')) . ')');
confere(excecao(fn () => $lancador->lancarPedido('uuid-nao-existe')) === null && count(linhasDe('entregas_lancamentos')) === 2, 'pedido que não existe: nada');
$g->status = 'canceled';
$lancador->estornarCancelados('empresa-1', Carbon::parse('2026-09-05 15:00:00'));
confere(count(lancamentosDo('order_g')) === 4 && session('company') === 'empresa-anterior', 'estornarCancelados também devolve a sessão');
$g->pago_mesmo_cancelado = true;
$lancador->relancarEstornados('empresa-1', Carbon::parse('2026-09-05 15:00:00'));
confere(count(lancamentosDo('order_g')) === 6 && session('company') === 'empresa-anterior', 'relancarEstornados também');
\Teste\Sessao::$dados = [];
$lancador->lancar('empresa-1', new Collection([$g]));
confere(session('company') === null, 'sem empresa na sessão antes: sem empresa depois');

echo '== Observador do pedido' . PHP_EOL;
reiniciarFinanceiro();
$pedido = new Order(['uuid' => 'uuid-order_obs', 'public_id' => 'order_obs', 'company_uuid' => 'empresa-1', 'status' => 'completed', 'alterados' => ['status']]);
\Teste\Config::$valores['services.entregas.financeiro'] = null;
ObservadorFinanceiroDosPedidos::aoAtualizar($pedido);
confere(\Teste\Fila::$jobs === [], 'chave desligada: não enfileira');
\Teste\Config::$valores['services.entregas.financeiro'] = '1';
ObservadorFinanceiroDosPedidos::aoAtualizar($pedido);
$job = \Teste\Fila::$jobs[0] ?? null;
confere($job instanceof LancarCorridaDoPedido && $job->orderUuid === 'uuid-order_obs' && count(\Teste\Fila::$jobs) === 1, 'status mudou para concluído: enfileira o job com o uuid');
confere($job instanceof \Illuminate\Contracts\Queue\ShouldQueue && $job->afterCommit === true, 'na fila, depois do commit');
foreach (['done' => 1, 'canceled' => 1, 'CANCELED' => 1, 'order_canceled' => 1, 'started' => 0, 'dispatched' => 0] as $status => $esperado) {
    \Teste\Fila::$jobs = [];
    $pedido->status    = $status;
    ObservadorFinanceiroDosPedidos::aoAtualizar($pedido);
    confere(count(\Teste\Fila::$jobs) === $esperado, "status {$status}: " . ($esperado ? 'enfileira' : 'não enfileira'));
}
\Teste\Fila::$jobs = [];
$pedido->status    = 'completed';
$pedido->alterados = ['driver_assigned_uuid'];
ObservadorFinanceiroDosPedidos::aoAtualizar($pedido);
confere(\Teste\Fila::$jobs === [], 'concluído, mas o status não mudou nesta gravação: não enfileira');
$pedido->alterados  = ['status'];
\Teste\Fila::$falhar = new \RuntimeException('Redis fora do ar');
confere(excecao(fn () => ObservadorFinanceiroDosPedidos::aoAtualizar($pedido)) === null && logou('[entregas] financeiro', 'warning'), 'fila fora do ar: não lança, só registra');
\Teste\Fila::$falhar = null;
confere(excecao(fn () => ObservadorFinanceiroDosPedidos::aoAtualizar(new \stdClass())) === null, 'objeto estranho: não lança');

echo '== Job LancarCorridaDoPedido' . PHP_EOL;
reiniciarFinanceiro();
\Teste\Sessao::$dados = ['company' => 'empresa-do-job-anterior'];
pedidoConcluido('order_job', '2026-10-05 14:30:00');
$calculo = new CalculoFalso();
(new LancarCorridaDoPedido('uuid-order_job'))->handle(new LancadorDeCorridas($calculo));
confere(count(lancamentosDo('order_job')) === 2 && $calculo->sessaoNoCalculo === ['empresa-1'], 'lança a corrida do pedido, com a empresa dele na sessão');
confere(session('company') === 'empresa-do-job-anterior', 'a sessão do worker não fica com a empresa deste job');
\Teste\Config::$valores['services.entregas.financeiro'] = null;
pedidoConcluido('order_job2', '2026-10-05 14:40:00');
(new LancarCorridaDoPedido('uuid-order_job2'))->handle(new LancadorDeCorridas(new CalculoFalso()));
confere(!lancamentosDo('order_job2'), 'chave desligada quando o job roda: nada');

echo '== Comando entregas:financeiro-conferir' . PHP_EOL;

/** Empresas com data de corte no Setting e na tabela settings (o comando procura as empresas pela tabela). */
function corteDa(string $empresa, ?string $corte): void
{
    Setting::$valores['company.' . $empresa . '.entregas.financeiro_corte'] = $corte;
    Banco::inserir('settings', ['key' => 'company.' . $empresa . '.entregas.financeiro_corte', 'value' => json_encode($corte)], false);
}

reiniciarFinanceiro();
Setting::$valores = [];
corteDa('empresa-1', '2026-10-01');
corteDa('empresa-2', '2026-10-04');
corteDa('empresa-3', null);
Banco::inserir('settings', ['key' => 'company.empresa-4.entregas.faixas', 'value' => '[]'], false);
\Fleetbase\Models\Company::$fusos['empresa-2'] = 'America/Sao_Paulo';
$calculo = new CalculoFalso();
$comando = new FinanceiroConferir();
\Teste\Config::$valores['services.entregas.financeiro'] = null;
confere($comando->handle(new LancadorDeCorridas($calculo)) === 0 && $calculo->chamadas === [], 'chave desligada: não faz nada');
\Teste\Config::$valores['services.entregas.financeiro'] = '1';
pedidoConcluido('order_c1', '2026-10-04 10:00:00');
pedidoConcluido('order_c2', '2026-10-04 11:00:00', ['company_uuid' => 'empresa-2', 'driver_assigned_uuid' => 'driver-2', 'customer_uuid' => null]);
confere($comando->handle(new LancadorDeCorridas($calculo)) === 0, 'sai com SUCCESS');
confere(count(lancamentosDo('order_c1')) === 2 && count(lancamentosDo('order_c2')) === 1, 'lança o que faltou em cada empresa com corte');
$porEmpresa = [];
foreach ($calculo->chamadas as $chamada) {
    $porEmpresa[$chamada['empresa']][] = $chamada['inicio'];
}
confere(array_keys($porEmpresa) === ['empresa-1', 'empresa-2'], 'só as empresas com data de corte válida');
confere(($porEmpresa['empresa-1'][0] ?? null) === '2026-10-01 00:00:00' && ($porEmpresa['empresa-2'][0] ?? null) === '2026-10-04 00:00:00', 'a conferência começa no corte quando ele é recente (max(corte, hoje − 30 dias))');
Relogio::$agora    = '2026-11-20 15:00:00';
$calculo->chamadas = [];
$comando->handle(new LancadorDeCorridas($calculo));
confere(($calculo->chamadas[0]['inicio'] ?? null) === '2026-10-21 15:00:00', 'e em hoje − 30 dias quando o corte é antigo (' . ($calculo->chamadas[0]['inicio'] ?? '') . ')');
Banco::$falharAoConsultar['entregas_lancamentos'] = new \RuntimeException('banco fora do ar');
confere(excecao(fn () => $comando->handle(new LancadorDeCorridas($calculo))) === null && logou('[entregas] financeiro: conferência falhou', 'warning'), 'falha numa empresa: registra e segue');
unset(Banco::$falharAoConsultar['entregas_lancamentos']);

reiniciarFinanceiro();
Setting::$valores = [];
corteDa('empresa-1', '2026-10-01');
Relogio::$agora = '2026-10-05 15:00:00';
$calculo        = new CalculoFalso();
$volta          = pedidoConcluido('order_volta', '2026-10-04 10:00:00');
$comando->handle(new LancadorDeCorridas($calculo));
$volta->status = 'canceled';
$comando->handle(new LancadorDeCorridas($calculo));
confere(liquidoDoPedido('order_volta', 'motoboy') === 0.0 && liquidoDoPedido('order_volta', 'loja') === 0.0, 'conferência: lança e, cancelado depois, estorna');
$volta->pago_mesmo_cancelado = true;
$comando->handle(new LancadorDeCorridas($calculo));
confere(liquidoDoPedido('order_volta', 'motoboy') === 8.0 && liquidoDoPedido('order_volta', 'loja') === 11.37, 'e relança quando o pedido volta a valer (cancelado pago marcado depois)');

echo '== Comando entregas:financeiro-lancar-historico' . PHP_EOL;
reiniciarFinanceiro();
Setting::$valores = [];
corteDa('empresa-1', '2026-09-20');
\Teste\Config::$valores['services.entregas.financeiro'] = null; // roda mesmo com a chave desligada
Relogio::$agora = '2026-10-05 15:00:00';
pedidoConcluido('order_h1', '2026-09-19 23:00:00');
pedidoConcluido('order_h2', '2026-09-20 10:00:00');
pedidoConcluido('order_h3', '2026-10-01 10:00:00');
pedidoConcluido('order_h4', '2026-10-05 14:00:00');
$calculo   = new CalculoFalso();
$historico = new FinanceiroLancarHistorico();
confere($historico->handle($calculo) === 0, 'sai com SUCCESS');
confere(!lancamentosDo('order_h1') && count(lancamentosDo('order_h2')) === 2 && count(lancamentosDo('order_h3')) === 2 && count(lancamentosDo('order_h4')) === 2, 'lança do corte até agora (antes do corte, nada)');
$janelas = array_map(fn ($c) => [$c['inicio'], $c['fim']], $calculo->chamadas);
confere(count($janelas) === 3 && $janelas[0] === ['2026-09-20 00:00:00', '2026-09-26 23:59:59'] && $janelas[2][1] === '2026-10-05 15:00:00', 'em janelas de 7 dias, a última até agora (' . json_encode($janelas) . ')');
$antes = count(linhasDe('entregas_lancamentos'));
$historico->handle(new CalculoFalso());
confere(count(linhasDe('entregas_lancamentos')) === $antes, 'repetir é seguro');
$historico->opcoes = ['company' => 'empresa-9'];
$calculo           = new CalculoFalso();
$historico->handle($calculo);
confere($calculo->chamadas === [], '--company sem data de corte: nada');

echo '== Ligações (agenda, providers, configuração, stack)' . PHP_EOL;
$kernel = file_get_contents('/repo/api/app/Console/Kernel.php');
$app    = file_get_contents('/repo/api/app/Providers/AppServiceProvider.php');
$config = file_get_contents('/repo/api/config/services.php');
$stack  = file_get_contents('/repo/deploy/docker-stack.yml');
$modelo = file_get_contents('/repo/deploy/stack.env.example');
confere(str_contains($kernel, "\$schedule->command('entregas:financeiro-conferir')->everyFiveMinutes()->when(fn () => Financeiro::ligada())->withoutOverlapping(10)->runInBackground()->appendOutputTo(static::SAIDA_DO_CONTAINER);") && str_contains($kernel, 'use App\Support\Entregas\Financeiro\Financeiro;'), 'agenda: conferência a cada 5 min, só com a chave ligada');
confere(str_contains(file_get_contents('/repo/api/app/Console/Commands/Entregas/FinanceiroConferir.php'), "'entregas:financeiro-conferir'") && str_contains(file_get_contents('/repo/api/app/Console/Commands/Entregas/FinanceiroLancarHistorico.php'), "'entregas:financeiro-lancar-historico {--company=}'"), 'assinaturas dos comandos');
confere(str_contains($app, 'Order::updated(fn ($pedido) => ObservadorFinanceiroDosPedidos::aoAtualizar($pedido));') && str_contains($app, '$this->lancarCorridasNoFinanceiro();') && str_contains($app, 'use App\Listeners\Entregas\ObservadorFinanceiroDosPedidos;'), 'AppServiceProvider: Order::updated → observador do financeiro');
confere(str_contains($config, "'financeiro' => env('ENTREGAS_FINANCEIRO'),"), 'config/services.php: services.entregas.financeiro');
confere(str_contains($stack, 'ENTREGAS_FINANCEIRO: ${ENTREGAS_FINANCEIRO:-}') && preg_match('/^ENTREGAS_FINANCEIRO=\r?$/m', $modelo) === 1, 'stack e modelo do stack.env');

resumo();
