<?php

// Financeiro: lançamento automático das corridas (LancadorDeCorridas), conferência, estorno dos cancelados depois da
// conclusão, e o observador do pedido (ObservadorFinanceiroDosPedidos + job LancarCorridaDoPedido). O CalculoEntregas é
// trocado por um falso (os pedidos e os valores vêm prontos); o próprio CalculoEntregas, com o valor que não muda depois
// de lançado, é testado no ganhos-motoboy.php.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/financeiro-lancador.php

require __DIR__ . '/stubs-financeiro.php';

use App\Support\Entregas\CalculoEntregas;
use App\Support\Entregas\Financeiro\LancadorDeCorridas;
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
        if ($metodo === 'whereNotExists' && ($argumentos[0] ?? null) instanceof \Closure) {
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
                if ($metodo === 'whereNotExists') {
                    foreach (Banco::$tabelas['entregas_lancamentos'] ?? [] as $l) {
                        if ($l['order_uuid'] === $p->uuid && $l['conta_tipo'] === 'motoboy' && $l['tipo'] === 'corrida') {
                            return false;
                        }
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

echo '== lancarPedido (job)' . PHP_EOL;
reiniciarFinanceiro();
\Teste\Sessao::$dados = [];
$lancador = new LancadorDeCorridas(new CalculoFalso());
$g        = pedidoConcluido('order_g', '2026-10-05 14:00:00');
pedidoConcluido('order_h', '2026-10-05 14:10:00');
$lancador->lancarPedido('uuid-order_g');
confere(count(lancamentosDo('order_g')) === 2 && !lancamentosDo('order_h'), 'lança só o pedido pedido');
confere(session('company') === 'empresa-1', 'com a empresa do pedido na sessão');
confere(excecao(fn () => $lancador->lancarPedido('uuid-nao-existe')) === null && count(linhasDe('entregas_lancamentos')) === 2, 'pedido que não existe: nada');

resumo();
