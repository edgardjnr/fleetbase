<?php

// Financeiro, API do console e do app (tarefa B2): o extrato de uma conta (ExtratoDaConta), o painel (PainelFinanceiro),
// o FinanceiroController (só admin), o extrato e o recibo do motoboy no app (MotoboyController) e o comprovante por URL
// assinada (ComprovantesController), no banco em memória do stubs-ifood.php.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/financeiro-console.php

require __DIR__ . '/stubs-financeiro.php';

use App\Support\Entregas\Financeiro\ExtratoDaConta;
use App\Support\Entregas\Financeiro\Lancamentos;
use App\Support\Entregas\Financeiro\Pagamentos;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vendor;
use Illuminate\Http\UploadedFile;
use Teste\Banco;
use Teste\Relogio;

/** Arredondamento para comparar reais. */
function reais($valor): ?float
{
    return $valor === null ? null : round((float) $valor, 2);
}

/** A chave existe e vale null (o `?? 'x'` não serve: null também cai no padrão). */
function nulo(?array $dados, string $chave): bool
{
    return is_array($dados) && array_key_exists($chave, $dados) && $dados[$chave] === null;
}

/** O lançamento do extrato com este id (ou null). */
function linhaDoExtrato(array $extrato, int $id): ?array
{
    foreach ($extrato['lancamentos'] ?? [] as $linha) {
        if (($linha['id'] ?? null) === $id) {
            return $linha;
        }
    }

    return null;
}

/**
 * O cenário comum: empresa-1 com dois motoboys (driver-1 João, driver-2 Maria), a loja vendor-a, um admin (user-admin) e
 * os lançamentos do João: corrida 01/10 (8,50, order-1), corrida 03/10 (10,00, order-2), diária 05/10 (30,00), pagamento
 * 06/10 (15,00, Pix, com comprovante e observação interna) e ajuste −2,00 do pedido order-2 em 07/10. A Maria tem uma
 * diária (não pode aparecer no extrato do João); a loja, a corrida do order-1 (valor da loja).
 */
function cenario(): array
{
    reiniciarFinanceiro();
    Relogio::$agora = '2026-10-12 10:00:00';
    \Illuminate\Support\Str::$gerados = 0;

    Driver::$todos[] = new Driver(['uuid' => 'driver-1', 'public_id' => 'driver_1', 'company_uuid' => 'empresa-1', 'user_uuid' => 'user-m1', 'name' => 'João Silva']);
    Driver::$todos[] = new Driver(['uuid' => 'driver-2', 'public_id' => 'driver_2', 'company_uuid' => 'empresa-1', 'user_uuid' => 'user-m2', 'name' => 'Maria Souza']);
    Driver::$todos[] = new Driver(['uuid' => 'driver-x', 'public_id' => 'driver_x', 'company_uuid' => 'empresa-2', 'user_uuid' => 'user-x', 'name' => 'Outra Empresa']);
    Order::$todos[]  = new Order(['uuid' => 'order-1', 'public_id' => 'order_1', 'internal_id' => 'RP1', 'company_uuid' => 'empresa-1']);
    Order::$todos[]  = new Order(['uuid' => 'order-2', 'public_id' => 'order_2', 'internal_id' => 'RP2', 'company_uuid' => 'empresa-1']);
    Order::$todos[]  = new Order(['uuid' => 'order-3', 'public_id' => 'order_3', 'internal_id' => 'RP3', 'company_uuid' => 'empresa-1']);
    Banco::inserir('users', ['uuid' => 'user-admin', 'name' => 'Edgard'], false);

    $corrida = fn (string $conta, string $contaUuid, string $pedido, string $numero, float $valor, string $dia) => Lancamentos::inserir([
        'company_uuid' => 'empresa-1', 'conta_tipo' => $conta, 'conta_uuid' => $contaUuid, 'tipo' => 'corrida', 'valor' => $valor,
        'data_referencia' => $dia, 'order_uuid' => $pedido, 'chave_unica' => 'corrida:' . $conta . ':' . $pedido,
        'detalhes' => ['pedido' => str_replace('-', '_', $pedido), 'numero' => $numero, 'km' => 3.21, 'faixa' => ['de_km' => 2, 'ate_km' => 4, 'acima' => false],
            'loja_nome' => 'Pizzaria Ficticia', 'destino' => 'Rua X, 10', 'cancelado_pago' => false, 'cancelado_origem' => null],
    ]);

    $ids                = [];
    $ids['corrida1']    = $corrida('motoboy', 'driver-1', 'order-1', 'RP1', 8.5, '2026-10-01');
    $ids['corrida2']    = $corrida('motoboy', 'driver-1', 'order-2', 'RP2', 10.0, '2026-10-03');
    $ids['loja1']       = $corrida('loja', 'vendor-a', 'order-1', 'RP1', 12.0, '2026-10-01');
    $ids['diaria']      = Lancamentos::inserir(['company_uuid' => 'empresa-1', 'conta_tipo' => 'motoboy', 'conta_uuid' => 'driver-1', 'tipo' => 'diaria', 'valor' => 30,
        'data_referencia' => '2026-10-05', 'motivo' => 'Diária', 'observacao_interna' => 'combinado com o João', 'criado_por' => 'user-admin']);
    $pagamento          = Pagamentos::registrar('empresa-1', 'motoboy', 'driver-1', 15.0, 'pix', '2026-10-06', new UploadedFile('pix.jpg', 'image/jpeg', 1000), 'pix do banco X', 'user-admin');
    $ids['pagamento']   = (int) Banco::$proximoId['entregas_lancamentos'];
    $ids['ajuste']      = Lancamentos::inserir(['company_uuid' => 'empresa-1', 'conta_tipo' => 'motoboy', 'conta_uuid' => 'driver-1', 'tipo' => 'ajuste', 'valor' => -2,
        'data_referencia' => '2026-10-07', 'order_uuid' => 'order-2', 'motivo' => 'Entrega com atraso', 'criado_por' => 'user-admin']);
    $ids['daMaria']     = Lancamentos::inserir(['company_uuid' => 'empresa-1', 'conta_tipo' => 'motoboy', 'conta_uuid' => 'driver-2', 'tipo' => 'diaria', 'valor' => 25,
        'data_referencia' => '2026-10-05', 'motivo' => 'Diária']);

    return ['ids' => $ids, 'pagamento' => $pagamento];
}

echo '== Extrato da conta (ExtratoDaConta::montar)' . PHP_EOL;
['ids' => $ids, 'pagamento' => $pagamento] = cenario();
Banco::inserir('entregas_motoboys_financeiro', ['company_uuid' => 'empresa-1', 'driver_uuid' => 'driver-1', 'chave_pix' => '11999994321', 'tipo_chave' => 'telefone'], false);

$extrato = ExtratoDaConta::montar('empresa-1', 'motoboy', 'driver-1', '2026-10-03', '2026-10-12', false);
confere(($extrato['conta'] ?? null) === ['tipo' => 'motoboy', 'id' => 'driver_1', 'nome' => 'João Silva', 'pix' => ['chave' => '11999994321', 'tipo' => 'telefone']], 'conta: tipo, public_id, nome e Pix: ' . json_encode($extrato['conta'] ?? null, JSON_UNESCAPED_UNICODE));
confere(($extrato['inicio'] ?? null) === '2026-10-03' && ($extrato['fim'] ?? null) === '2026-10-12', 'início e fim do filtro');
confere(reais($extrato['saldo'] ?? null) === 31.5, 'saldo = soma de todos os lançamentos (8,50 + 10 + 30 − 15 − 2 = 31,50): ' . json_encode($extrato['saldo'] ?? null));
confere(reais($extrato['saldo_anterior'] ?? null) === 8.5, 'saldo anterior = soma antes do início (a corrida de 01/10)');
confere(($extrato['totais'] ?? null) === ['creditos' => 40.0, 'debitos' => 17.0], 'totais do período: créditos 40,00 e débitos 17,00 (positivo): ' . json_encode($extrato['totais'] ?? null));
confere(($extrato['ultimo_pagamento'] ?? null) === ['id' => $pagamento->uuid, 'valor' => 15.0, 'data' => '2026-10-06', 'forma' => 'pix'], 'último pagamento: ' . json_encode($extrato['ultimo_pagamento'] ?? null));
confere(array_column($extrato['lancamentos'] ?? [], 'id') === [$ids['ajuste'], $ids['pagamento'], $ids['diaria'], $ids['corrida2']], 'só os do período e da conta, mais novo primeiro: ' . json_encode(array_column($extrato['lancamentos'] ?? [], 'id')));

$corrida2 = linhaDoExtrato($extrato, $ids['corrida2']);
confere(($corrida2['tipo'] ?? null) === 'corrida' && ($corrida2['valor'] ?? null) === 10.0 && ($corrida2['data'] ?? null) === '2026-10-03', 'corrida: tipo, valor e data');
confere(($corrida2['pedido'] ?? null) === ['id' => 'order_2', 'numero' => 'RP2'], 'corrida: pedido (public_id e número) dos detalhes');
confere(($corrida2['detalhes'] ?? null) === ['km' => 3.21, 'faixa' => ['de_km' => 2.0, 'ate_km' => 4.0, 'acima' => false], 'loja_nome' => 'Pizzaria Ficticia', 'destino' => 'Rua X, 10', 'cancelado_pago' => false, 'cancelado_origem' => null], 'corrida: detalhes no formato do contrato: ' . json_encode($corrida2['detalhes'] ?? null, JSON_UNESCAPED_UNICODE));
// a situação usa a conta inteira: o pagamento de 15 quita a corrida de 01/10 (fora do período) e 6,50 desta (o ajuste −2 do pedido abate 2)
confere(($corrida2['situacao'] ?? null) === 'parcial' && ($corrida2['valor_pago'] ?? null) === 6.5, 'situação calculada com a conta inteira: corrida de 03/10 paga em parte (6,50): ' . json_encode([$corrida2['situacao'] ?? null, $corrida2['valor_pago'] ?? null]));
confere(($corrida2['estornado'] ?? null) === false && array_key_exists('estorno_de', $corrida2) && $corrida2['estorno_de'] === null, 'corrida: não estornada, sem estorno_de');
confere(array_key_exists('fatura', $corrida2) && $corrida2['fatura'] === null && $corrida2['pagamento'] === null, 'corrida do motoboy: sem fatura e sem pagamento');
confere(preg_match('/^2026-10-12T10:00:00-03:00$/', (string) ($corrida2['criado_em'] ?? '')) === 1, 'criado_em em ISO 8601 no fuso da empresa: ' . ($corrida2['criado_em'] ?? 'null'));
confere(array_key_exists('criado_por', $corrida2) && $corrida2['criado_por'] === null, 'corrida lançada pelo sistema: criado_por null');

$diaria = linhaDoExtrato($extrato, $ids['diaria']);
confere(($diaria['situacao'] ?? null) === 'aberto' && ($diaria['valor_pago'] ?? null) === 0.0, 'diária a receber');
confere(($diaria['motivo'] ?? null) === 'Diária' && ($diaria['observacao_interna'] ?? null) === 'combinado com o João', 'diária: motivo e observação interna (console)');
confere(($diaria['criado_por'] ?? null) === ['nome' => 'Edgard'], 'diária: lançada por Edgard');
confere(nulo($diaria, 'pedido') && nulo($diaria, 'detalhes'), 'diária: sem pedido e sem detalhes');

$linhaPagamento = linhaDoExtrato($extrato, $ids['pagamento']);
confere(($linhaPagamento['valor'] ?? null) === -15.0 && nulo($linhaPagamento, 'situacao') && nulo($linhaPagamento, 'valor_pago'), 'pagamento: débito, sem situação');
$pagamentoDaLinha = $linhaPagamento['pagamento'] ?? [];
confere(($pagamentoDaLinha['id'] ?? null) === $pagamento->uuid && ($pagamentoDaLinha['forma'] ?? null) === 'pix' && ($pagamentoDaLinha['tem_comprovante'] ?? null) === true && ($pagamentoDaLinha['estornado'] ?? null) === false, 'pagamento: {id, forma, tem_comprovante, estornado}: ' . json_encode($pagamentoDaLinha));
confere(str_contains((string) ($pagamentoDaLinha['comprovante_url'] ?? ''), '/v1/entregas/comprovantes/' . $pagamento->uuid . '?expires='), 'console: comprovante_url assinada do pagamento');
confere(($linhaPagamento['observacao_interna'] ?? null) === 'pix do banco X', 'pagamento: observação interna no console');

$ajuste = linhaDoExtrato($extrato, $ids['ajuste']);
confere(($ajuste['pedido'] ?? null) === ['id' => 'order_2', 'numero' => 'RP2'], 'ajuste com pedido sem detalhes: pedido lido do Order');
confere(($ajuste['motivo'] ?? null) === 'Entrega com atraso' && nulo($ajuste, 'situacao'), 'ajuste negativo: motivo, sem situação');

echo '== Extrato público (app e portal)' . PHP_EOL;
$publico = ExtratoDaConta::montar('empresa-1', 'motoboy', 'driver-1', '2026-10-03', '2026-10-12', true);
$tudo    = json_encode($publico, JSON_UNESCAPED_UNICODE);
confere(!str_contains($tudo, 'observacao_interna') && !str_contains($tudo, 'combinado com o João') && !str_contains($tudo, 'pix do banco X'), 'público: nunca a observação interna');
confere(!str_contains($tudo, 'criado_por') && !str_contains($tudo, 'Edgard'), 'público: sem criado_por');
confere(!str_contains($tudo, 'comprovante_url'), 'público: sem a URL do comprovante na lista (o recibo tem a sua)');
confere(linhaDoExtrato($publico, $ids['daMaria']) === null && linhaDoExtrato($publico, $ids['loja1']) === null, 'público: sem lançamento de outra conta (outro motoboy, a loja)');
confere(($publico['conta']['pix'] ?? null) === ['chave' => '11999994321', 'tipo' => 'telefone'], 'público: o motoboy vê a chave Pix dele');
confere(reais($publico['saldo'] ?? null) === 31.5 && count($publico['lancamentos'] ?? []) === 4, 'público: mesmo saldo e mesmos lançamentos');
confere((linhaDoExtrato($publico, $ids['pagamento'])['pagamento'] ?? null) === ['id' => $pagamento->uuid, 'forma' => 'pix', 'tem_comprovante' => true, 'estornado' => false], 'público: pagamento só com id, forma, tem_comprovante e estornado');

echo '== Filtro por tipo, estornos e período aberto' . PHP_EOL;
$soDiarias = ExtratoDaConta::montar('empresa-1', 'motoboy', 'driver-1', '2026-10-03', '2026-10-12', false, 'diaria');
confere(array_column($soDiarias['lancamentos'] ?? [], 'id') === [$ids['diaria']], 'filtro tipo=diaria: só a diária');
confere(($soDiarias['totais'] ?? null) === ['creditos' => 40.0, 'debitos' => 17.0] && reais($soDiarias['saldo'] ?? null) === 31.5, 'o filtro de tipo não muda saldo nem totais do período');

$estorno = Lancamentos::estornar(Lancamentos::porId('empresa-1', $ids['diaria']), 'user-admin', 'Lançada por engano');
$depois  = ExtratoDaConta::montar('empresa-1', 'motoboy', 'driver-1', null, null, false);
confere(count($depois['lancamentos'] ?? []) === 6 && ($depois['saldo_anterior'] ?? null) === 0.0, 'sem período: todos os lançamentos da conta, saldo anterior 0');
confere((linhaDoExtrato($depois, $ids['diaria'])['estornado'] ?? null) === true && (linhaDoExtrato($depois, $ids['diaria'])['situacao'] ?? null) === 'anulado', 'diária estornada: estornado e anulada');
$linhaDoEstorno = linhaDoExtrato($depois, (int) $estorno);
confere(($linhaDoEstorno['tipo'] ?? null) === 'estorno' && ($linhaDoEstorno['estorno_de'] ?? null) === $ids['diaria'] && ($linhaDoEstorno['valor'] ?? null) === -30.0, 'estorno: tipo, estorno_de e valor oposto');
confere(($linhaDoEstorno['motivo'] ?? null) === 'Lançada por engano' && ($linhaDoEstorno['data'] ?? null) === '2026-10-12', 'estorno: motivo e data de hoje');

Pagamentos::estornar(Pagamentos::porUuid('empresa-1', $pagamento->uuid), 'user-admin');
$semPagamento = ExtratoDaConta::montar('empresa-1', 'motoboy', 'driver-1', null, null, false);
confere((linhaDoExtrato($semPagamento, $ids['pagamento'])['pagamento']['estornado'] ?? null) === true && (linhaDoExtrato($semPagamento, $ids['pagamento'])['estornado'] ?? null) === true, 'pagamento estornado: pagamento.estornado e estornado');
confere(nulo($semPagamento, 'ultimo_pagamento'), 'pagamento estornado não é o último pagamento');

echo '== Conta da loja' . PHP_EOL;
$daLoja = ExtratoDaConta::montar('empresa-1', 'loja', 'vendor-a', null, null, false);
confere(($daLoja['conta'] ?? null) === ['tipo' => 'loja', 'id' => 'vendor_a', 'nome' => 'Pizzaria Ficticia'], 'conta loja: sem pix: ' . json_encode($daLoja['conta'] ?? null, JSON_UNESCAPED_UNICODE));
confere(array_column($daLoja['lancamentos'] ?? [], 'id') === [$ids['loja1']] && reais($daLoja['saldo'] ?? null) === 12.0, 'conta loja: só a corrida da loja');
$uuidFatura = 'fatura-1';
Banco::inserir('entregas_faturas', ['uuid' => $uuidFatura, 'company_uuid' => 'empresa-1', 'vendor_uuid' => 'vendor-a', 'numero' => 31, 'periodo_inicio' => '2026-09-28', 'periodo_fim' => '2026-10-04', 'vencimento' => '2026-10-08', 'situacao' => 'emitida', 'total' => 12], false);
Banco::$tabelas['entregas_lancamentos'][$ids['loja1']]['fatura_uuid'] = $uuidFatura;
$faturada = ExtratoDaConta::montar('empresa-1', 'loja', 'vendor-a', null, null, false);
confere(($faturada['lancamentos'][0]['fatura'] ?? null) === ['id' => $uuidFatura, 'numero' => 'FAT-0031'], 'lançamento faturado: {id, numero FAT-0031}');

resumo();
