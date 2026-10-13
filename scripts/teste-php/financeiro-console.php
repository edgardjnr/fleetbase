<?php

// Financeiro, API do console e do app (tarefa B2): o extrato de uma conta (ExtratoDaConta), o painel (PainelFinanceiro),
// o FinanceiroController (só admin), o extrato e o recibo do motoboy no app (MotoboyController) e o comprovante por URL
// assinada (ComprovantesController), no banco em memória do stubs-ifood.php.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/financeiro-console.php

require __DIR__ . '/stubs-financeiro.php';

// a base das notificações do Laravel (o PagamentoRegistrado a estende; o envio é o notify do Driver falso)
if (!class_exists(\Illuminate\Notifications\Notification::class, false)) {
    eval('namespace Illuminate\Notifications; class Notification {}');
}

use App\Support\Entregas\Financeiro\ChavePix;
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

echo '== FinanceiroController: só administradores' . PHP_EOL;

/** O usuário da sessão (Auth::getUserFromSession): admin ou não. */
class UsuarioDeTeste
{
    public function __construct(public string $uuid, public bool $admin) {}
    public function isNotAdmin(): bool { return !$this->admin; }
}

/** Request com arquivos (o multipart do comprovante): file('campo'). */
class RequestComArquivo extends \Illuminate\Http\Request
{
    public array $arquivos = [];
    public function file($chave = null, $padrao = null) { return $chave === null ? $this->arquivos : ($this->arquivos[$chave] ?? $padrao); }
}

function comoAdmin(): void
{
    \Fleetbase\Support\Auth::$usuario = new UsuarioDeTeste('user-admin', true);
    session(['company' => 'empresa-1', 'user' => 'user-admin']);
}

function pedidoFeito(array $dados = [], array $arquivos = []): RequestComArquivo
{
    $request           = new RequestComArquivo($dados);
    $request->arquivos = $arquivos;

    return $request;
}

/** A primeira mensagem de erro da resposta. */
function erroDa($resposta): ?string
{
    return $resposta->dados['errors'][0] ?? null;
}

$controller = new App\Http\Controllers\Entregas\FinanceiroController();
cenario();
\Fleetbase\Support\Auth::$usuario = new UsuarioDeTeste('user-comum', false);
session(['company' => 'empresa-1', 'user' => 'user-comum']);
$chamadas = [
    'painel'              => fn () => $controller->painel(pedidoFeito()),
    'motoboys'            => fn () => $controller->motoboys(pedidoFeito()),
    'extratoDoMotoboy'    => fn () => $controller->extratoDoMotoboy(pedidoFeito(), 'driver_1'),
    'salvarPix'           => fn () => $controller->salvarPix(pedidoFeito(['tipo' => 'cpf', 'chave' => '12345678901']), 'driver_1'),
    'lancar'              => fn () => $controller->lancar(pedidoFeito(['conta_tipo' => 'motoboy', 'contas' => ['driver_1'], 'tipo' => 'diaria', 'valor' => 30, 'data' => '2026-10-12'])),
    'estornarLancamento'  => fn () => $controller->estornarLancamento(pedidoFeito(), (string) $ids['diaria']),
    'registrarPagamento'  => fn () => $controller->registrarPagamento(pedidoFeito(['conta_tipo' => 'motoboy', 'conta' => 'driver_1', 'valor' => '10.00', 'forma' => 'pix', 'data' => '2026-10-12'])),
    'estornarPagamento'   => fn () => $controller->estornarPagamento(pedidoFeito(), 'x'),
    'configuracoes'       => fn () => $controller->configuracoes(pedidoFeito()),
    'salvarConfiguracoes' => fn () => $controller->salvarConfiguracoes(pedidoFeito(['corte' => '2026-11-01'])),
];
$antes = count(linhasDe('entregas_lancamentos'));
foreach ($chamadas as $metodo => $chamar) {
    $resposta = $chamar();
    confere($resposta->status === 403 && str_contains((string) erroDa($resposta), 'administradores'), "{$metodo}: não admin → 403");
}
\Fleetbase\Support\Auth::$usuario = null;
confere($controller->motoboys(pedidoFeito())->status === 403, 'sem usuário na sessão → 403');
confere(count(linhasDe('entregas_lancamentos')) === $antes && \Fleetbase\Models\Setting::$valores['company.empresa-1.entregas.financeiro_corte'] === '2026-10-01', 'nada foi gravado pelos não admins');

echo '== Motoboys (lista do console)' . PHP_EOL;
['ids' => $ids, 'pagamento' => $pagamento] = cenario();
comoAdmin();
ChavePix::salvar('empresa-1', 'driver-2', '12345678901', 'cpf');
$resposta = $controller->motoboys(pedidoFeito(['inicio' => '2026-10-02', 'fim' => '2026-10-12']));
$lista    = $resposta->dados['motoboys'] ?? [];
confere($resposta->status === 200 && array_column($lista, 'id') === ['driver_1', 'driver_2'], 'todos os motoboys da empresa (não os de outra), por saldo: ' . json_encode(array_column($lista, 'id')));
confere(($lista[0] ?? null) === ['id' => 'driver_1', 'nome' => 'João Silva', 'saldo' => 31.5, 'ultimo_pagamento' => ['id' => $pagamento->uuid, 'valor' => 15.0, 'data' => '2026-10-06', 'forma' => 'pix'], 'corridas' => 1, 'valor_corridas' => 10.0, 'tem_pix' => false], 'João: saldo, último pagamento, corridas do período (só a de 03/10) e sem Pix: ' . json_encode($lista[0] ?? null, JSON_UNESCAPED_UNICODE));
confere(($lista[1]['saldo'] ?? null) === 25.0 && nulo($lista[1] ?? null, 'ultimo_pagamento') && ($lista[1]['corridas'] ?? null) === 0 && ($lista[1]['tem_pix'] ?? null) === true, 'Maria: saldo da diária, sem pagamento, sem corridas, com Pix');
Lancamentos::estornar(Lancamentos::porId('empresa-1', $ids['corrida2']), 'user-admin', null);
$lista = $controller->motoboys(pedidoFeito(['inicio' => '2026-10-02', 'fim' => '2026-10-12']))->dados['motoboys'];
confere(($lista[0]['corridas'] ?? null) === 0 && ($lista[0]['valor_corridas'] ?? null) === 0.0, 'corrida estornada não conta no período');
Driver::$todos[] = new Driver(['uuid' => 'driver-3', 'public_id' => 'driver_3', 'company_uuid' => 'empresa-1', 'name' => 'Ana Lima']);
$lista = $controller->motoboys(pedidoFeito(['inicio' => '2026-10-02', 'fim' => '2026-10-12']))->dados['motoboys'];
confere(array_column($lista, 'id') === ['driver_2', 'driver_1', 'driver_3'], 'motoboy sem lançamento entra com saldo 0; mesmo saldo, por nome: ' . json_encode(array_column($lista, 'id')));
confere($controller->motoboys(pedidoFeito(['inicio' => '2026-10-12', 'fim' => '2026-10-01']))->status === 422, 'fim antes do início → 422');
confere($controller->motoboys(pedidoFeito(['inicio' => '2026-02-30', 'fim' => '2026-10-01']))->status === 422, 'data inexistente → 422');
confere($controller->motoboys(pedidoFeito())->status === 200, 'sem período: este mês');

echo '== Extrato do motoboy (console)' . PHP_EOL;
['ids' => $ids] = cenario();
comoAdmin();
$resposta = $controller->extratoDoMotoboy(pedidoFeito(['inicio' => '2026-10-03', 'fim' => '2026-10-12']), 'driver_1');
confere($resposta->status === 200 && ($resposta->dados['conta']['id'] ?? null) === 'driver_1' && count($resposta->dados['lancamentos'] ?? []) === 4, 'extrato do João no período');
confere(($resposta->dados['lancamentos'][2]['observacao_interna'] ?? null) === 'combinado com o João', 'console: com a observação interna');
confere($controller->extratoDoMotoboy(pedidoFeito(), 'driver_x')->status === 404, 'motoboy de outra empresa → 404');
confere($controller->extratoDoMotoboy(pedidoFeito(), 'driver_9')->status === 404, 'motoboy inexistente → 404');
confere($controller->extratoDoMotoboy(pedidoFeito(['inicio' => '2026-10-03', 'fim' => '2026-10-12', 'tipo' => 'diaria']), 'driver_1')->dados['lancamentos'][0]['id'] === $ids['diaria'], 'filtro de tipo');
confere($controller->extratoDoMotoboy(pedidoFeito(['tipo' => 'qualquer']), 'driver_1')->status === 422, 'tipo desconhecido → 422');
confere(count($controller->extratoDoMotoboy(pedidoFeito(), 'driver_1')->dados['lancamentos'] ?? []) === 5, 'sem período: a conta inteira');

echo '== Chave Pix' . PHP_EOL;
foreach ([
    'tipo desconhecido'        => ['tipo' => 'banco', 'chave' => '123'],
    'sem chave'                => ['tipo' => 'cpf', 'chave' => '  '],
    'CPF com 10 dígitos'       => ['tipo' => 'cpf', 'chave' => '1234567890'],
    'CNPJ curto'               => ['tipo' => 'cnpj', 'chave' => '123'],
    'telefone com letras'      => ['tipo' => 'telefone', 'chave' => '1199999abcd'],
    'e-mail sem arroba'        => ['tipo' => 'email', 'chave' => 'joao.com'],
    'aleatória curta'          => ['tipo' => 'aleatoria', 'chave' => 'abc'],
] as $caso => $dados) {
    $resposta = $controller->salvarPix(pedidoFeito($dados), 'driver_1');
    confere($resposta->status === 422 && erroDa($resposta) !== null, "Pix {$caso} → 422 (" . erroDa($resposta) . ')');
}
confere(ChavePix::doMotoboy('empresa-1', 'driver-1') === null, 'nada gravado com chave inválida');
$resposta = $controller->salvarPix(pedidoFeito(['tipo' => 'cpf', 'chave' => '123.456.789-01']), 'driver_1');
confere($resposta->status === 200 && $resposta->dados === ['pix' => ['chave' => '12345678901', 'tipo' => 'cpf']], 'CPF com pontuação gravado só com os dígitos: ' . json_encode($resposta->dados));
$resposta = $controller->salvarPix(pedidoFeito(['tipo' => 'email', 'chave' => ' Joao@Exemplo.com ']), 'driver_1');
confere($resposta->dados === ['pix' => ['chave' => 'joao@exemplo.com', 'tipo' => 'email']], 'trocar a chave: e-mail em minúsculas');
confere(count(linhasDe('entregas_motoboys_financeiro')) === 1 && ChavePix::doMotoboy('empresa-1', 'driver-1') === ['chave' => 'joao@exemplo.com', 'tipo' => 'email'], 'uma linha por motoboy (troca, não duplica)');
confere($controller->salvarPix(pedidoFeito(['tipo' => 'cpf', 'chave' => '12345678901']), 'driver_x')->status === 404, 'Pix de motoboy de outra empresa → 404');

echo '== Lançar (diária, bônus, ajuste, saldo inicial)' . PHP_EOL;
['ids' => $ids] = cenario();
comoAdmin();
$base = ['conta_tipo' => 'motoboy', 'contas' => ['driver_1'], 'tipo' => 'diaria', 'valor' => 30, 'data' => '2026-10-12', 'pedido' => null, 'motivo' => 'Diária', 'observacao_interna' => null];
$antes = count(linhasDe('entregas_lancamentos'));
foreach ([
    'conta_tipo inválido'         => ['conta_tipo' => 'cliente'],
    'tipo de outra conta'         => ['tipo' => 'corrida'],
    'diária na loja'              => ['conta_tipo' => 'loja', 'contas' => ['vendor_a']],
    'valor zero'                  => ['valor' => 0],
    'valor 0,001'                 => ['valor' => 0.001],
    'valor texto'                 => ['valor' => 'trinta'],
    'diária negativa'             => ['valor' => -30],
    'bônus negativo'              => ['tipo' => 'bonus', 'valor' => '-5'],
    'pedido em diária'            => ['pedido' => 'order_1'],
    'pedido inexistente'          => ['tipo' => 'ajuste', 'pedido' => 'order_99'],
    'sem contas'                  => ['contas' => []],
    'contas não é lista'          => ['contas' => 'driver_1'],
    'conta de outra empresa'      => ['contas' => ['driver_1', 'driver_x']],
    'mais de 100 contas'          => ['contas' => array_map(fn ($n) => 'driver_' . $n, range(1, 101))],
    'data inválida'               => ['data' => '12/10/2026'],
    'motivo longo'                => ['motivo' => str_repeat('a', 256)],
    'observação longa'            => ['observacao_interna' => str_repeat('a', 501)],
] as $caso => $mudanca) {
    $resposta = $controller->lancar(pedidoFeito(array_merge($base, $mudanca)));
    confere($resposta->status === 422 && erroDa($resposta) !== null, "lançar com {$caso} → 422 (" . erroDa($resposta) . ')');
}
confere(count(linhasDe('entregas_lancamentos')) === $antes, 'nenhum lançamento gravado nos casos inválidos');

$resposta = $controller->lancar(pedidoFeito(array_merge($base, ['contas' => ['driver_1', 'driver_2', 'driver_1'], 'observacao_interna' => 'chuva'])));
$criados  = $resposta->dados['lancamentos'] ?? [];
confere($resposta->status === 200 && count($criados) === 2, 'diária para dois motoboys (o repetido conta uma vez): um lançamento por conta');
$gravados = array_slice(linhasDe('entregas_lancamentos'), -2);
confere(array_column($gravados, 'conta_uuid') === ['driver-1', 'driver-2'] && array_unique(array_column($gravados, 'tipo')) === ['diaria'], 'gravados nas contas certas');
confere(($gravados[0]['valor'] ?? null) == 30 && ($gravados[0]['data_referencia'] ?? null) === '2026-10-12' && ($gravados[0]['criado_por'] ?? null) === 'user-admin' && ($gravados[0]['observacao_interna'] ?? null) === 'chuva' && ($gravados[0]['motivo'] ?? null) === 'Diária', 'valor, data, quem lançou, motivo e observação');
confere(($criados[0]['tipo'] ?? null) === 'diaria' && ($criados[0]['criado_por'] ?? null) === ['nome' => 'Edgard'] && ($criados[0]['observacao_interna'] ?? null) === 'chuva', 'resposta no formato Lancamento: ' . json_encode($criados[0] ?? null, JSON_UNESCAPED_UNICODE));

$resposta = $controller->lancar(pedidoFeito(array_merge($base, ['tipo' => 'ajuste', 'valor' => '-2,50', 'pedido' => 'RP2', 'motivo' => 'Atraso'])));
$ajuste   = $resposta->dados['lancamentos'][0] ?? [];
confere($resposta->status === 200 && ($ajuste['valor'] ?? null) === -2.5 && ($ajuste['pedido'] ?? null) === ['id' => 'order_2', 'numero' => 'RP2'], 'ajuste negativo com o pedido pelo número (texto "-2,50"): ' . json_encode($ajuste, JSON_UNESCAPED_UNICODE));
$gravado = linhasDe('entregas_lancamentos');
$gravado = end($gravado);
confere(($gravado['order_uuid'] ?? null) === 'order-2' && json_decode((string) $gravado['detalhes'], true) === ['pedido' => 'order_2', 'numero' => 'RP2'], 'ajuste: order_uuid e o pedido nos detalhes');
confere($controller->lancar(pedidoFeito(array_merge($base, ['tipo' => 'ajuste', 'valor' => 5, 'pedido' => 'order_1'])))->status === 200, 'ajuste positivo com o public_id do pedido');
confere($controller->lancar(pedidoFeito(array_merge($base, ['tipo' => 'saldo_inicial', 'valor' => -40])))->status === 200, 'saldo inicial negativo (o motoboy devia)');
$resposta = $controller->lancar(pedidoFeito(array_merge($base, ['conta_tipo' => 'loja', 'contas' => ['vendor_a'], 'tipo' => 'ajuste', 'valor' => 50, 'motivo' => 'Mensalidade'])));
$gravado  = linhasDe('entregas_lancamentos');
$gravado  = end($gravado);
confere($resposta->status === 200 && ($gravado['conta_tipo'] ?? null) === 'loja' && ($gravado['conta_uuid'] ?? null) === 'vendor-a' && nulo($gravado ?: null, 'fatura_uuid'), 'ajuste na loja: sem fatura (entra no próximo rascunho)');

echo '== Estornar lançamento' . PHP_EOL;
['ids' => $ids, 'pagamento' => $pagamento] = cenario();
comoAdmin();
confere($controller->estornarLancamento(pedidoFeito(), '999')->status === 404, 'lançamento inexistente → 404');
confere($controller->estornarLancamento(pedidoFeito(), 'abc')->status === 404, 'id que não é número → 404');
Banco::inserir('entregas_lancamentos', ['uuid' => 'lanc-x', 'company_uuid' => 'empresa-2', 'conta_tipo' => 'motoboy', 'conta_uuid' => 'driver-x', 'tipo' => 'diaria', 'valor' => 10, 'data_referencia' => '2026-10-05'], false);
confere($controller->estornarLancamento(pedidoFeito(), (string) Banco::$proximoId['entregas_lancamentos'])->status === 404, 'lançamento de outra empresa → 404');
confere($controller->estornarLancamento(pedidoFeito(), (string) $ids['pagamento'])->status === 422, 'pagamento se estorna pelo pagamento → 422');
$resposta = $controller->estornarLancamento(pedidoFeito(['motivo' => 'Lançada por engano']), (string) $ids['diaria']);
$estorno  = $resposta->dados['lancamento'] ?? [];
confere($resposta->status === 200 && ($estorno['tipo'] ?? null) === 'estorno' && ($estorno['estorno_de'] ?? null) === $ids['diaria'] && ($estorno['valor'] ?? null) === -30.0 && ($estorno['motivo'] ?? null) === 'Lançada por engano' && ($estorno['criado_por'] ?? null) === ['nome' => 'Edgard'], 'estorno criado: ' . json_encode($estorno, JSON_UNESCAPED_UNICODE));
confere($controller->estornarLancamento(pedidoFeito(), (string) $ids['diaria'])->status === 409, 'estornar de novo → 409');
confere($controller->estornarLancamento(pedidoFeito(), (string) $estorno['id'])->status === 422, 'estorno de estorno → 422');
Banco::inserir('entregas_faturas', ['uuid' => 'fatura-1', 'company_uuid' => 'empresa-1', 'vendor_uuid' => 'vendor-a', 'numero' => 1, 'periodo_inicio' => '2026-09-28', 'periodo_fim' => '2026-10-04', 'vencimento' => '2026-10-08', 'situacao' => 'emitida', 'total' => 12], false);
Banco::$tabelas['entregas_lancamentos'][$ids['loja1']]['fatura_uuid'] = 'fatura-1';
$resposta = $controller->estornarLancamento(pedidoFeito(['motivo' => 'Não cobrar']), (string) $ids['loja1']);
confere($resposta->status === 200 && nulo($resposta->dados['lancamento'] ?? null, 'fatura'), 'lançamento de loja numa fatura emitida: estorna, e o estorno fica sem fatura');

echo '== Registrar pagamento (multipart)' . PHP_EOL;
['ids' => $ids] = cenario();
comoAdmin();
$base   = ['conta_tipo' => 'motoboy', 'conta' => 'driver_1', 'valor' => '50.00', 'forma' => 'pix', 'data' => '2026-10-12'];
$antes  = [count(linhasDe('entregas_pagamentos')), count(linhasDe('entregas_lancamentos'))];
foreach ([
    'conta_tipo inválido'  => [['conta_tipo' => 'cliente'], 422],
    'motoboy inexistente'  => [['conta' => 'driver_9'], 404],
    'outra empresa'        => [['conta' => 'driver_x'], 404],
    'valor texto'          => [['valor' => 'cinquenta'], 422],
    'valor zero'           => [['valor' => '0.00'], 422],
    'forma inválida'       => [['forma' => 'cheque'], 422],
    'data inválida'        => [['data' => '2026-13-01'], 422],
] as $caso => [$mudanca, $status]) {
    $resposta = $controller->registrarPagamento(pedidoFeito(array_merge($base, $mudanca)));
    confere($resposta->status === $status && erroDa($resposta) !== null, "pagamento com {$caso} → {$status} (" . erroDa($resposta) . ')');
}
$resposta = $controller->registrarPagamento(pedidoFeito($base, ['comprovante' => new UploadedFile('virus.exe', 'application/x-msdownload', 100)]));
confere($resposta->status === 422 && erroDa($resposta) === 'O comprovante precisa ser uma imagem ou um PDF.', 'comprovante que não é imagem nem PDF → 422 com a mensagem do Pagamentos');
confere([count(linhasDe('entregas_pagamentos')), count(linhasDe('entregas_lancamentos'))] === $antes && Driver::$avisos === [], 'nada gravado e nenhum push nos casos inválidos');

$resposta = $controller->registrarPagamento(pedidoFeito($base + ['observacao_interna' => 'adiantamento combinado', 'fatura' => 'qualquer'], ['file' => new UploadedFile('pix.pdf', 'application/pdf', 2000)]));
$dados    = $resposta->dados;
confere($resposta->status === 200 && reais($dados['saldo'] ?? null) === -18.5, 'pagamento acima do saldo aceito (adiantamento): saldo 31,50 − 50 = −18,50: ' . json_encode($dados['saldo'] ?? null));
$registrado = Pagamentos::porUuid('empresa-1', (string) ($dados['pagamento']['id'] ?? ''));
confere($registrado !== null && $registrado->criado_por === 'user-admin' && $registrado->observacao_interna === 'adiantamento combinado' && $registrado->comprovante_nome === 'pix.pdf', 'pagamento gravado com quem fez, observação e o comprovante do campo "file" (o "fatura" é ignorado)');
confere(($dados['pagamento']['valor'] ?? null) === 50.0 && ($dados['pagamento']['forma'] ?? null) === 'pix' && ($dados['pagamento']['data'] ?? null) === '2026-10-12' && ($dados['pagamento']['estornado'] ?? null) === false, 'resposta: o pagamento no formato do recibo');
confere(($dados['pagamento']['comprovante']['tipo'] ?? null) === 'application/pdf' && ($dados['pagamento']['comprovante']['nome'] ?? null) === 'pix.pdf' && str_contains((string) ($dados['pagamento']['comprovante']['url'] ?? ''), 'signature='), 'resposta: comprovante com URL assinada, tipo e nome');
confere(!str_contains(json_encode($dados), 'adiantamento combinado'), 'a resposta não repete a observação interna');
confere(count(Driver::$avisos) === 1 && Driver::$avisos[0][0] === 'driver_1' && Driver::$avisos[0][1] instanceof App\Notifications\Entregas\PagamentoRegistrado, 'push PagamentoRegistrado ao motoboy');
$aviso = Driver::$avisos[0][1] ?? null;
confere($aviso && $aviso->title === 'Pagamento registrado' && $aviso->message === 'Pagamento de R$ 50,00 registrado (Pix).', 'push: "Pagamento de R$ 50,00 registrado (Pix)."');
confere($aviso && $aviso->data === ['type' => 'entregas_pagamento', 'pagamento' => $registrado->uuid], 'push: dados {type: entregas_pagamento, pagamento: uuid}, sem id de pedido');

$resposta = $controller->registrarPagamento(pedidoFeito(['conta_tipo' => 'motoboy', 'conta' => 'driver_2', 'valor' => '1234,5', 'forma' => 'transferencia', 'data' => '2026-10-12'], ['comprovante' => new UploadedFile('a.jpg', 'image/jpeg', 10)]));
confere($resposta->status === 200 && ($resposta->dados['pagamento']['valor'] ?? null) === 1234.5, 'valor com vírgula ("1234,5") e comprovante no campo "comprovante"');
confere((Driver::$avisos[1][1]->message ?? null) === 'Pagamento de R$ 1.234,50 registrado (transferência).', 'push com milhar e a forma em pt-BR');

$resposta = $controller->registrarPagamento(pedidoFeito(['conta_tipo' => 'loja', 'conta' => 'vendor_a', 'valor' => '12.00', 'forma' => 'dinheiro', 'data' => '2026-10-12']));
$gravado  = linhasDe('entregas_lancamentos');
$gravado  = end($gravado);
confere($resposta->status === 200 && ($gravado['tipo'] ?? null) === 'recebimento' && reais($resposta->dados['saldo'] ?? null) === 0.0 && count(Driver::$avisos) === 2, 'recebimento da loja: lançamento recebimento, saldo 0 e nenhum push');
confere($controller->registrarPagamento(pedidoFeito(['conta_tipo' => 'loja', 'conta' => 'driver_1', 'valor' => '12.00', 'forma' => 'pix', 'data' => '2026-10-12']))->status === 404, 'conta loja com id de motoboy → 404');

// o push falhou: o pagamento continua gravado, e a falha vai para o log
Driver::$todos[0] = new class(['uuid' => 'driver-1', 'public_id' => 'driver_1', 'company_uuid' => 'empresa-1', 'name' => 'João Silva']) extends Driver {
    public function notify($notificacao): void { throw new \RuntimeException('FCM fora'); }
};
$resposta = $controller->registrarPagamento(pedidoFeito($base));
confere($resposta->status === 200 && logou('[entregas] financeiro: push do pagamento não saiu', 'warning'), 'push que falha não desfaz o pagamento (log de aviso)');

echo '== Estornar pagamento' . PHP_EOL;
['pagamento' => $pagamento] = cenario();
comoAdmin();
confere($controller->estornarPagamento(pedidoFeito(), 'nao-existe')->status === 404, 'pagamento inexistente → 404');
$resposta = $controller->estornarPagamento(pedidoFeito(), $pagamento->uuid);
confere($resposta->status === 200 && ($resposta->dados['pagamento']['estornado'] ?? null) === true && reais($resposta->dados['saldo'] ?? null) === 46.5, 'estornado: pagamento.estornado e o saldo volta (31,50 + 15 = 46,50)');
confere($controller->estornarPagamento(pedidoFeito(), $pagamento->uuid)->status === 409, 'estornar de novo → 409');
session(['company' => 'empresa-2']);
confere($controller->estornarPagamento(pedidoFeito(), $pagamento->uuid)->status === 404, 'pagamento de outra empresa → 404');

echo '== Configurações' . PHP_EOL;
cenario();
comoAdmin();
$resposta = $controller->configuracoes(pedidoFeito());
confere($resposta->dados === ['corte' => '2026-10-01', 'cobranca_padrao' => ['ciclo' => 'semanal', 'dia_fechamento' => 0, 'prazo_dias' => 3], 'ligada' => true], 'GET: corte, ciclo padrão e ligada: ' . json_encode($resposta->dados));
foreach ([
    'corte inválido'        => ['corte' => '2026-02-30'],
    'ciclo inválido'        => ['cobranca_padrao' => ['ciclo' => 'diario', 'dia_fechamento' => 0, 'prazo_dias' => 3]],
    'semanal dia 7'         => ['cobranca_padrao' => ['ciclo' => 'semanal', 'dia_fechamento' => 7, 'prazo_dias' => 3]],
    'mensal dia 29'         => ['cobranca_padrao' => ['ciclo' => 'mensal', 'dia_fechamento' => 29, 'prazo_dias' => 3]],
    'prazo 61'              => ['cobranca_padrao' => ['ciclo' => 'semanal', 'dia_fechamento' => 0, 'prazo_dias' => 61]],
    'prazo negativo'        => ['cobranca_padrao' => ['ciclo' => 'semanal', 'dia_fechamento' => 0, 'prazo_dias' => -1]],
    'sem prazo'             => ['cobranca_padrao' => ['ciclo' => 'semanal', 'dia_fechamento' => 0]],
    'cobrança não é objeto' => ['cobranca_padrao' => 'semanal'],
] as $caso => $dados) {
    $resposta = $controller->salvarConfiguracoes(pedidoFeito($dados));
    confere($resposta->status === 422 && erroDa($resposta) !== null, "PUT com {$caso} → 422 (" . erroDa($resposta) . ')');
}
confere(\Fleetbase\Models\Setting::$valores === ['company.empresa-1.entregas.financeiro_corte' => '2026-10-01'], 'nada gravado nos casos inválidos');
$resposta = $controller->salvarConfiguracoes(pedidoFeito(['corte' => '2026-11-01', 'cobranca_padrao' => ['ciclo' => 'mensal', 'dia_fechamento' => '0', 'prazo_dias' => 10]]));
confere($resposta->status === 200 && $resposta->dados === ['corte' => '2026-11-01', 'cobranca_padrao' => ['ciclo' => 'mensal', 'dia_fechamento' => 0, 'prazo_dias' => 10], 'ligada' => true], 'PUT grava e responde o formato completo: ' . json_encode($resposta->dados));
confere((\Fleetbase\Models\Setting::$valores['company.empresa-1.entregas.cobranca_padrao'] ?? null) === ['ciclo' => 'mensal', 'dia_fechamento' => 0, 'prazo_dias' => 10], 'ciclo padrão gravado no Setting da empresa (números)');
$resposta = $controller->salvarConfiguracoes(pedidoFeito(['cobranca_padrao' => ['ciclo' => 'quinzenal', 'dia_fechamento' => 5, 'prazo_dias' => 0]]));
confere(($resposta->dados['corte'] ?? null) === '2026-11-01' && ($resposta->dados['cobranca_padrao'] ?? null) === ['ciclo' => 'quinzenal', 'dia_fechamento' => 0, 'prazo_dias' => 0], 'PUT só com o ciclo mantém o corte; quinzenal grava dia 0');
\Teste\Config::$valores['services.entregas.financeiro'] = null;
$resposta = $controller->salvarConfiguracoes(pedidoFeito(['corte' => null]));
confere($resposta->dados['corte'] === null && $resposta->dados['ligada'] === false && array_key_exists('company.empresa-1.entregas.financeiro_corte', \Fleetbase\Models\Setting::$valores) && \Fleetbase\Models\Setting::$valores['company.empresa-1.entregas.financeiro_corte'] === null, 'corte null tira a data de corte; ligada segue a variável do servidor');

echo '== Painel (PainelFinanceiro::montar)' . PHP_EOL;

// o CalculoEntregas falso do painel: os pedidos concluídos sem lançamento (a consulta do pedidosSemLancamento) vêm de
// $pedidos, filtrados pelo período pedido; registra o período de cada chamada
class CalculoDoPainel extends App\Support\Entregas\CalculoEntregas
{
    public array $pedidos  = [];
    public array $chamadas = [];

    public function pedidosConcluidos(string $companyUuid, \Illuminate\Support\Carbon $inicio, \Illuminate\Support\Carbon $fim, ?\Closure $filtro = null): \Illuminate\Support\Collection
    {
        $this->chamadas[] = [$companyUuid, $inicio->format('Y-m-d H:i:s'), $fim->format('Y-m-d H:i:s')];

        return new \Illuminate\Support\Collection(array_values(array_filter($this->pedidos, fn ($p) => $p->company_uuid === $companyUuid
            && $p->entregas_concluido_em >= $inicio->format('Y-m-d H:i:s') && $p->entregas_concluido_em <= $fim->format('Y-m-d H:i:s'))));
    }
}

use App\Support\Entregas\Financeiro\LancadorDeCorridas;
use App\Support\Entregas\Financeiro\PainelFinanceiro;

/** Uma fatura na tabela; devolve o uuid. */
function fatura(string $uuid, string $loja, ?int $numero, string $situacao, float $total, string $vencimento, ?string $email = '2026-10-05 10:00:00'): string
{
    Banco::inserir('entregas_faturas', ['uuid' => $uuid, 'company_uuid' => 'empresa-1', 'vendor_uuid' => $loja, 'numero' => $numero, 'periodo_inicio' => '2026-09-28',
        'periodo_fim' => '2026-10-04', 'vencimento' => $vencimento, 'situacao' => $situacao, 'total' => $total, 'email_enviado_em' => $email], false);

    return $uuid;
}

function lancamentoDeLoja(string $loja, string $tipo, float $valor, string $dia, ?string $fatura = null, ?string $pedido = null): int
{
    return Lancamentos::inserir(['company_uuid' => 'empresa-1', 'conta_tipo' => 'loja', 'conta_uuid' => $loja, 'tipo' => $tipo, 'valor' => $valor,
        'data_referencia' => $dia, 'fatura_uuid' => $fatura, 'order_uuid' => $pedido]);
}

$calculo  = new CalculoDoPainel();
$lancador = new LancadorDeCorridas($calculo);

reiniciarFinanceiro();
Relogio::$agora = '2026-10-12 10:00:00';
$vazio = PainelFinanceiro::montar('empresa-1', '2026-10-01', '2026-10-31', $lancador);
confere($vazio === ['inicio' => '2026-10-01', 'fim' => '2026-10-31', 'a_pagar' => 0.0, 'adiantado' => 0.0, 'a_receber' => 0.0, 'vencido' => 0.0, 'nao_faturado' => 0.0,
    'periodo' => ['cobrado' => 0.0, 'pago_motoboys' => 0.0, 'margem' => 0.0], 'atencao' => []], 'sem lançamentos nem faturas: tudo zero: ' . json_encode($vazio));

['ids' => $ids] = cenario();
Vendor::$todos[] = new Vendor(['uuid' => 'vendor-b', 'public_id' => 'vendor_b', 'company_uuid' => 'empresa-1', 'name' => 'Burger Hub']);
// motoboys: João 31,50, Maria 30,00 (diária 25 + corrida antiga 5) e Ana com saldo inicial −50 (adiantamento)
Driver::$todos[] = new Driver(['uuid' => 'driver-3', 'public_id' => 'driver_3', 'company_uuid' => 'empresa-1', 'name' => 'Ana Lima']);
Lancamentos::inserir(['company_uuid' => 'empresa-1', 'conta_tipo' => 'motoboy', 'conta_uuid' => 'driver-3', 'tipo' => 'saldo_inicial', 'valor' => -50, 'data_referencia' => '2026-10-01']);
// corrida antiga (mais de 30 dias) sem loja e corrida estornada sem loja: fora do "sem loja"
Lancamentos::inserir(['company_uuid' => 'empresa-1', 'conta_tipo' => 'motoboy', 'conta_uuid' => 'driver-2', 'tipo' => 'corrida', 'valor' => 5, 'data_referencia' => '2026-09-01', 'order_uuid' => 'order-velho', 'chave_unica' => 'corrida:motoboy:order-velho', 'detalhes' => ['pedido' => 'order_velho', 'numero' => 'RP0']]);
$estornada = Lancamentos::inserir(['company_uuid' => 'empresa-1', 'conta_tipo' => 'motoboy', 'conta_uuid' => 'driver-2', 'tipo' => 'corrida', 'valor' => 9, 'data_referencia' => '2026-10-04', 'order_uuid' => 'order-3', 'chave_unica' => 'corrida:motoboy:order-3', 'detalhes' => ['pedido' => 'order_3', 'numero' => 'RP3']]);
Lancamentos::estornar(Lancamentos::porId('empresa-1', $estornada), null, 'Pedido cancelado após a conclusão');
// lojas: a vendor-a tem a corrida de 01/10 (12,00, sem fatura) e um ajuste −2 sem fatura; duas faturas emitidas (100 vencida, 40 a vencer e sem
// e-mail) e 30 recebidos (mais 10 recebidos e estornados); a vendor-b tem um rascunho com 20 + 15 e uma fatura cancelada
lancamentoDeLoja('vendor-a', 'ajuste', -2, '2026-10-10');
$vencida  = fatura('fatura-31', 'vendor-a', 31, 'emitida', 100, '2026-10-08');
$aVencer  = fatura('fatura-32', 'vendor-a', 32, 'emitida', 40, '2026-10-20', null);
$rascunho = fatura('fatura-r', 'vendor-b', null, 'rascunho', 0, '2026-10-15', null);
fatura('fatura-c', 'vendor-b', 30, 'cancelada', 999, '2026-10-01', null);
lancamentoDeLoja('vendor-b', 'corrida', 20, '2026-10-02', $rascunho);
lancamentoDeLoja('vendor-b', 'corrida', 15, '2026-10-03', $rascunho);
Pagamentos::registrar('empresa-1', 'loja', 'vendor-a', 30, 'pix', '2026-10-09', null, null, 'user-admin');
$devolvido = Pagamentos::registrar('empresa-1', 'loja', 'vendor-a', 10, 'pix', '2026-10-09', null, null, 'user-admin');
Pagamentos::estornar($devolvido, 'user-admin');
// pedidos concluídos sem lançamento (corridas sem valor): um há 2 h, outro há 30 min (ainda dentro da 1 h)
$calculo->pedidos = [
    (object) ['uuid' => 'order-7', 'public_id' => 'order_7', 'internal_id' => 'RP7', 'company_uuid' => 'empresa-1', 'entregas_concluido_em' => '2026-10-12 08:00:00'],
    (object) ['uuid' => 'order-8', 'public_id' => 'order_8', 'internal_id' => 'RP8', 'company_uuid' => 'empresa-1', 'entregas_concluido_em' => '2026-10-12 09:30:00'],
];

$painel = PainelFinanceiro::montar('empresa-1', '2026-10-01', '2026-10-31', $lancador);
confere(($painel['a_pagar'] ?? null) === 61.5 && ($painel['adiantado'] ?? null) === 50.0, 'a pagar = soma dos saldos positivos (31,50 + 30); adiantado = soma dos negativos (50): ' . json_encode([$painel['a_pagar'] ?? null, $painel['adiantado'] ?? null]));
confere(($painel['a_receber'] ?? null) === 110.0 && ($painel['vencido'] ?? null) === 70.0, 'a receber = em aberto das emitidas (70 + 40); vencido = a parte vencida (70): ' . json_encode([$painel['a_receber'] ?? null, $painel['vencido'] ?? null]));
confere(($painel['nao_faturado'] ?? null) === 45.0, 'não faturado = lançamentos de loja fora de fatura emitida (12 − 2 + rascunho 35), sem recebimentos: ' . json_encode($painel['nao_faturado'] ?? null));
confere(($painel['periodo'] ?? null) === ['cobrado' => 45.0, 'pago_motoboys' => 71.5, 'margem' => -26.5], 'período: cobrado (corridas e ajustes da loja), pago aos motoboys (corridas, diárias, ajustes; o estorno abate, o saldo inicial não conta) e margem: ' . json_encode($painel['periodo'] ?? null));
$tipos = array_column($painel['atencao'] ?? [], 'tipo');
confere($tipos === ['fatura_vencida', 'rascunho', 'email_nao_enviado', 'sem_loja', 'sem_valor'], 'atenção na ordem do contrato: ' . json_encode($tipos));
confere(($painel['atencao'][0] ?? null) === ['tipo' => 'fatura_vencida', 'fatura' => $vencida, 'numero' => 'FAT-0031', 'loja' => 'Pizzaria Ficticia', 'valor' => 70.0, 'vencimento' => '2026-10-08'], 'fatura vencida com o em aberto: ' . json_encode($painel['atencao'][0] ?? null, JSON_UNESCAPED_UNICODE));
confere(($painel['atencao'][1] ?? null) === ['tipo' => 'rascunho', 'fatura' => $rascunho, 'loja' => 'Burger Hub', 'valor' => 35.0], 'rascunho com a soma dos itens: ' . json_encode($painel['atencao'][1] ?? null, JSON_UNESCAPED_UNICODE));
confere(($painel['atencao'][2] ?? null) === ['tipo' => 'email_nao_enviado', 'fatura' => $aVencer, 'numero' => 'FAT-0032', 'loja' => 'Pizzaria Ficticia'], 'emitida sem e-mail: ' . json_encode($painel['atencao'][2] ?? null, JSON_UNESCAPED_UNICODE));
confere(($painel['atencao'][3] ?? null) === ['tipo' => 'sem_loja', 'pedido' => 'order_2', 'numero' => 'RP2'], 'corrida do motoboy sem lançamento da loja (últimos 30 dias, não estornada): ' . json_encode($painel['atencao'][3] ?? null));
confere(($painel['atencao'][4] ?? null) === ['tipo' => 'sem_valor', 'pedido' => 'order_7', 'numero' => 'RP7'], 'pedido concluído há mais de 1 h sem lançamento: ' . json_encode($painel['atencao'][4] ?? null));
$consulta = end($calculo->chamadas);
confere($consulta === ['empresa-1', '2026-10-01 00:00:00', '2026-10-12 10:00:00'], 'sem valor: a consulta da conferência do corte (no máximo 30 dias) até agora; a 1 h é filtrada depois: ' . json_encode($consulta));
confere(!array_filter(linhasDe('entregas_lancamentos'), fn ($l) => in_array($l['order_uuid'], ['order-7', 'order-8'], true)), 'o painel não lança nada (só consulta)');

$depois = PainelFinanceiro::montar('empresa-1', '2026-10-02', '2026-10-31', $lancador);
confere(($depois['periodo'] ?? null) === ['cobrado' => 33.0, 'pago_motoboys' => 63.0, 'margem' => -30.0] && ($depois['a_pagar'] ?? null) === 61.5, 'outro período muda só a margem (os saldos são de agora): ' . json_encode($depois['periodo'] ?? null));

\Teste\Config::$valores['services.entregas.financeiro'] = null;
$desligado = PainelFinanceiro::montar('empresa-1', '2026-10-01', '2026-10-31', $lancador);
confere(!in_array('sem_valor', array_column($desligado['atencao'], 'tipo'), true), 'com o financeiro desligado não há "sem valor" (nada é lançado sozinho)');
\Teste\Config::$valores['services.entregas.financeiro'] = '1';
\Fleetbase\Models\Setting::$valores = [];
$semCorte = PainelFinanceiro::montar('empresa-1', '2026-10-01', '2026-10-31', $lancador);
confere(!in_array('sem_valor', array_column($semCorte['atencao'], 'tipo'), true), 'sem data de corte não há "sem valor"');
\Fleetbase\Models\Setting::$valores = ['company.empresa-1.entregas.financeiro_corte' => '2026-01-01'];
PainelFinanceiro::montar('empresa-1', '2026-10-01', '2026-10-31', $lancador);
confere((end($calculo->chamadas)[1] ?? null) === '2026-09-12 00:00:00', 'corte antigo: a consulta volta no máximo 30 dias');
$calculo->pedidos = array_map(fn ($n) => (object) ['uuid' => 'order-s' . $n, 'public_id' => 'order_s' . $n, 'internal_id' => null, 'company_uuid' => 'empresa-1', 'entregas_concluido_em' => sprintf('2026-10-11 %02d:00:00', $n)], range(0, 23));
$muitos = array_values(array_filter(PainelFinanceiro::montar('empresa-1', '2026-10-01', '2026-10-31', $lancador)['atencao'], fn ($item) => $item['tipo'] === 'sem_valor'));
confere(count($muitos) === 20 && $muitos[0]['pedido'] === 'order_s23' && $muitos[0]['numero'] === null, 'no máximo 20 "sem valor", os mais recentes primeiro');
\Teste\Banco::$falharAoConsultar = [];
$calculo->pedidos = [];

echo '== Painel no controller' . PHP_EOL;
comoAdmin();
\Teste\Container::$instancias[LancadorDeCorridas::class] = $lancador;
$resposta = $controller->painel(pedidoFeito(['inicio' => '2026-10-01', 'fim' => '2026-10-31']));
confere($resposta->status === 200 && ($resposta->dados['a_receber'] ?? null) === 110.0 && ($resposta->dados['inicio'] ?? null) === '2026-10-01', 'GET painel com o período');
$resposta = $controller->painel(pedidoFeito());
confere($resposta->status === 200 && ($resposta->dados['inicio'] ?? null) === '2026-10-01' && ($resposta->dados['fim'] ?? null) === '2026-10-12', 'sem período: este mês até hoje');
confere($controller->painel(pedidoFeito(['inicio' => '2026-10-31', 'fim' => '2026-10-01']))->status === 422, 'período invertido → 422');

resumo();
