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

use App\Support\Entregas\Financeiro\Carteira;
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
confere(($extrato['conta'] ?? null) === ['tipo' => 'motoboy', 'id' => 'driver_1', 'nome' => 'João Silva', 'pix' => ['chave' => '11999994321', 'tipo' => 'telefone'], 'excluido' => false], 'conta: tipo, public_id, nome, Pix e excluido (console): ' . json_encode($extrato['conta'] ?? null, JSON_UNESCAPED_UNICODE));
confere(($extrato['inicio'] ?? null) === '2026-10-03' && ($extrato['fim'] ?? null) === '2026-10-12', 'início e fim do filtro');
confere(reais($extrato['saldo'] ?? null) === 31.5, 'saldo = soma de todos os lançamentos (8,50 + 10 + 30 − 15 − 2 = 31,50): ' . json_encode($extrato['saldo'] ?? null));
confere(reais($extrato['saldo_anterior'] ?? null) === 8.5, 'saldo anterior = soma antes do início (a corrida de 01/10)');
confere(($extrato['totais'] ?? null) === ['creditos' => 40.0, 'debitos' => 17.0], 'totais do período: créditos 40,00 e débitos 17,00 (positivo): ' . json_encode($extrato['totais'] ?? null));
confere(($extrato['ultimo_pagamento'] ?? null) === ['id' => $pagamento->uuid, 'valor' => 15.0, 'data' => '2026-10-06', 'forma' => 'pix'], 'último pagamento: ' . json_encode($extrato['ultimo_pagamento'] ?? null));
confere(array_column($extrato['lancamentos'] ?? [], 'id') === [$ids['ajuste'], $ids['pagamento'], $ids['diaria'], $ids['corrida2']], 'só os do período e da conta, mais novo primeiro: ' . json_encode(array_column($extrato['lancamentos'] ?? [], 'id')));

$corrida2 = linhaDoExtrato($extrato, $ids['corrida2']);
confere(($corrida2['tipo'] ?? null) === 'corrida' && ($corrida2['valor'] ?? null) === 10.0 && ($corrida2['data'] ?? null) === '2026-10-03', 'corrida: tipo, valor e data');
confere(($corrida2['pedido'] ?? null) === ['id' => 'order_2', 'numero' => 'RP2'], 'corrida: pedido (public_id e número) dos detalhes');
confere(($corrida2['detalhes'] ?? null) === ['km' => 3.21, 'faixa' => ['de_km' => 2.0, 'ate_km' => 4.0, 'acima' => false], 'loja_nome' => 'Pizzaria Ficticia', 'destino' => 'Rua X, 10', 'cancelado_pago' => false, 'cancelado_origem' => null, 'junto' => null, 'multiplicador' => null], 'corrida: detalhes no formato do contrato: ' . json_encode($corrida2['detalhes'] ?? null, JSON_UNESCAPED_UNICODE));
// entrega junto e chuva nos detalhes: lançamento montado à mão (fora do banco, para não mexer nos saldos dos casos abaixo),
// com os detalhes em texto como o MySQL devolve
$comSelos   = (object) ['id' => 9999, 'company_uuid' => 'empresa-1', 'conta_tipo' => 'motoboy', 'conta_uuid' => 'driver-1', 'tipo' => 'corrida', 'valor' => 7.2,
    'data_referencia' => '2026-10-03', 'order_uuid' => 'order-selos', 'created_at' => '2026-10-12 10:00:00',
    'detalhes' => json_encode(['pedido' => 'order_selos', 'numero' => 'RP9', 'km' => 3.21, 'faixa' => ['de_km' => 3, 'ate_km' => 6, 'acima' => false],
        'loja_nome' => 'Pizzaria Ficticia', 'destino' => 'Rua X, 10', 'cancelado_pago' => false, 'cancelado_origem' => null,
        'junto' => 2, 'multiplicador' => ['nome' => 'Chuva', 'fator' => 1.2]])];
$linhaSelos = ExtratoDaConta::linhas([$comSelos], [], true, [])[0] ?? [];
confere(($linhaSelos['detalhes']['junto'] ?? null) === 2 && ($linhaSelos['detalhes']['multiplicador'] ?? null) === ['nome' => 'Chuva', 'fator' => 1.2], 'extrato: 2ª entrega e a chuva saem nos detalhes: ' . json_encode($linhaSelos['detalhes'] ?? null, JSON_UNESCAPED_UNICODE));
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
confere(!array_key_exists('excluido', $publico['conta'] ?? []), 'público: sem o campo excluido (só o console)');
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
confere(($lista[0] ?? null) === ['id' => 'driver_1', 'nome' => 'João Silva', 'saldo' => 31.5, 'ultimo_pagamento' => ['id' => $pagamento->uuid, 'valor' => 15.0, 'data' => '2026-10-06', 'forma' => 'pix'], 'corridas' => 1, 'valor_corridas' => 10.0, 'tem_pix' => false, 'excluido' => false],'João: saldo, último pagamento, corridas do período (só a de 03/10) e sem Pix: ' . json_encode($lista[0] ?? null, JSON_UNESCAPED_UNICODE));
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

echo '== Motoboy excluído (soft delete) com saldo' . PHP_EOL;
cenario();
comoAdmin();
Driver::$todos[] = new Driver(['uuid' => 'driver-4', 'public_id' => 'driver_4', 'company_uuid' => 'empresa-1', 'name' => 'Paulo Antigo', 'deleted_at' => '2026-10-10 12:00:00']);
Driver::$todos[] = new Driver(['uuid' => 'driver-5', 'public_id' => 'driver_5', 'company_uuid' => 'empresa-1', 'name' => 'Zerado Antigo', 'deleted_at' => '2026-10-10 12:00:00']);
Lancamentos::inserir(['company_uuid' => 'empresa-1', 'conta_tipo' => 'motoboy', 'conta_uuid' => 'driver-4', 'tipo' => 'diaria', 'valor' => 12, 'data_referencia' => '2026-10-08']);
Lancamentos::inserir(['company_uuid' => 'empresa-1', 'conta_tipo' => 'motoboy', 'conta_uuid' => 'driver-5', 'tipo' => 'diaria', 'valor' => 5, 'data_referencia' => '2026-10-08']);
Lancamentos::inserir(['company_uuid' => 'empresa-1', 'conta_tipo' => 'motoboy', 'conta_uuid' => 'driver-5', 'tipo' => 'ajuste', 'valor' => -5, 'data_referencia' => '2026-10-09']);
$lista = array_column($controller->motoboys(pedidoFeito())->dados['motoboys'] ?? [], null, 'id');
confere(isset($lista['driver_4']) && $lista['driver_4']['excluido'] === true && $lista['driver_4']['saldo'] === 12.0 && $lista['driver_4']['nome'] === 'Paulo Antigo', 'excluído com saldo: na lista, marcado excluido: true');
confere(!isset($lista['driver_5']), 'excluído com saldo zero: fora da lista');
confere(($lista['driver_1']['excluido'] ?? null) === false && ($lista['driver_2']['excluido'] ?? null) === false, 'os outros com excluido: false');
$resposta = $controller->registrarPagamento(pedidoFeito(['conta_tipo' => 'motoboy', 'conta' => 'driver_4', 'valor' => '12,00', 'forma' => 'pix', 'data' => '2026-10-12']));
confere($resposta->status === 200 && ($resposta->dados['saldo'] ?? null) === 0.0, 'aceita o pagamento ao excluído (acerto final): ' . json_encode($resposta->dados));
$resposta = $controller->lancar(pedidoFeito(['conta_tipo' => 'motoboy', 'contas' => ['driver_4'], 'tipo' => 'ajuste', 'valor' => 1, 'data' => '2026-10-12', 'motivo' => 'Acerto']));
confere($resposta->status === 200, 'aceita lançamento para o excluído');
$extrato = $controller->extratoDoMotoboy(pedidoFeito(), 'driver_4');
confere($extrato->status === 200 && ($extrato->dados['conta']['excluido'] ?? null) === true && ($extrato->dados['conta']['nome'] ?? null) === 'Paulo Antigo', 'extrato do excluído: conta.excluido = true');
confere(($controller->extratoDoMotoboy(pedidoFeito(), 'driver_1')->dados['conta']['excluido'] ?? null) === false, 'extrato de quem não foi excluído: conta.excluido = false');

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
    'milhar sem centavos (1.500)' => ['valor' => '1.500'],
    'milhar negativo (-2.000)'    => ['tipo' => 'ajuste', 'valor' => '-2.000'],
    'milhões (1.234.567)'         => ['valor' => '1.234.567'],
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
confere(erroDa($controller->lancar(pedidoFeito(array_merge($base, ['valor' => '1.500'])))) === 'Valor ambíguo: para mil e quinhentos, escreva 1500 ou 1.500,00 (vírgula antes dos centavos).', 'milhar com ponto e sem vírgula: a mensagem explica');
$resposta = $controller->lancar(pedidoFeito(array_merge($base, ['valor' => '1.500,00'])));
confere($resposta->status === 200 && ($resposta->dados['lancamentos'][0]['valor'] ?? null) === 1500.0, 'milhar com vírgula ("1.500,00") vale 1500');
$resposta = $controller->lancar(pedidoFeito(array_merge($base, ['valor' => '1.50'])));
confere($resposta->status === 200 && ($resposta->dados['lancamentos'][0]['valor'] ?? null) === 1.5, 'ponto com duas casas ("1.50") continua decimal');

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
// o número (internal_id) se repete entre dias e lojas: só vale o pedido com corrida lançada na conta escolhida
Order::$todos[] = new Order(['uuid' => 'order-r8a', 'public_id' => 'order_r8a', 'internal_id' => 'RP8', 'company_uuid' => 'empresa-1']);
Order::$todos[] = new Order(['uuid' => 'order-r8b', 'public_id' => 'order_r8b', 'internal_id' => 'RP8', 'company_uuid' => 'empresa-1']);
Order::$todos[] = new Order(['uuid' => 'order-r9a', 'public_id' => 'order_r9a', 'internal_id' => 'RP9', 'company_uuid' => 'empresa-1']);
Order::$todos[] = new Order(['uuid' => 'order-r9b', 'public_id' => 'order_r9b', 'internal_id' => 'RP9', 'company_uuid' => 'empresa-1']);
foreach (['order-r8b', 'order-r9a', 'order-r9b'] as $pedidoDaCorrida) {
    Lancamentos::inserir(['company_uuid' => 'empresa-1', 'conta_tipo' => 'motoboy', 'conta_uuid' => 'driver-1', 'tipo' => 'corrida', 'valor' => 7,
        'data_referencia' => '2026-10-08', 'order_uuid' => $pedidoDaCorrida, 'chave_unica' => 'corrida:motoboy:' . $pedidoDaCorrida]);
}
$resposta = $controller->lancar(pedidoFeito(array_merge($base, ['tipo' => 'ajuste', 'valor' => -1, 'pedido' => 'RP8'])));
confere($resposta->status === 200 && ($resposta->dados['lancamentos'][0]['pedido']['id'] ?? null) === 'order_r8b', 'número repetido: o pedido com corrida na conta (' . json_encode($resposta->dados['lancamentos'][0]['pedido'] ?? null) . ')');
$antes    = count(linhasDe('entregas_lancamentos'));
$resposta = $controller->lancar(pedidoFeito(array_merge($base, ['tipo' => 'ajuste', 'valor' => -1, 'pedido' => 'RP9'])));
confere($resposta->status === 422 && erroDa($resposta) === 'Há mais de um pedido com esse número; use o código do pedido (order_…)' && count(linhasDe('entregas_lancamentos')) === $antes, 'dois pedidos com o número e corrida na conta → 422 (' . erroDa($resposta) . ')');
confere($controller->lancar(pedidoFeito(array_merge($base, ['tipo' => 'ajuste', 'valor' => -1, 'pedido' => 'order_r9a'])))->status === 200, 'pelo código do pedido (public_id) resolve');
confere($controller->lancar(pedidoFeito(array_merge($base, ['tipo' => 'ajuste', 'valor' => -1, 'pedido' => 'order-r9b'])))->status === 200, 'pelo uuid também');
$resposta = $controller->lancar(pedidoFeito(array_merge($base, ['tipo' => 'ajuste', 'valor' => -1, 'pedido' => 'RP8', 'contas' => ['driver_2']])));
confere($resposta->status === 422 && erroDa($resposta) === 'Pedido não encontrado.', 'número sem corrida na conta escolhida → 422 (' . erroDa($resposta) . ')');

Lancamentos::estornar(Lancamentos::porId('empresa-1', $ids['corrida1']), null, 'Pedido cancelado após a conclusão');
$antes    = count(linhasDe('entregas_lancamentos'));
$resposta = $controller->lancar(pedidoFeito(array_merge($base, ['tipo' => 'ajuste', 'valor' => -8.5, 'pedido' => 'order_1', 'motivo' => 'Não pagar'])));
confere($resposta->status === 422 && erroDa($resposta) === 'A corrida deste pedido já foi estornada.' && count(linhasDe('entregas_lancamentos')) === $antes, 'ajuste negativo de pedido com a corrida já estornada → 422 (' . erroDa($resposta) . ')');
confere($controller->lancar(pedidoFeito(array_merge($base, ['tipo' => 'ajuste', 'valor' => -1, 'pedido' => 'order_1', 'contas' => ['driver_2']])))->status === 200, 'na conta de outro motoboy (sem a corrida estornada): aceita');
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
    'milhar sem centavos'  => [['valor' => '1.500'], 422],
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
// as mesmas regras e mensagens do ciclo de cada loja (CicloDeCobranca::validar, integração B2 × B3)
$resposta = $controller->salvarConfiguracoes(pedidoFeito(['cobranca_padrao' => ['ciclo' => 'semanal', 'dia_fechamento' => 7, 'prazo_dias' => 3]]));
confere(erroDa($resposta) === \App\Support\Entregas\Financeiro\CicloDeCobranca::validar(['ciclo' => 'semanal', 'dia_fechamento' => 7, 'prazo_dias' => 3]), 'ciclo padrão inválido: a mensagem do CicloDeCobranca::validar (' . erroDa($resposta) . ')');
$resposta = $controller->salvarConfiguracoes(pedidoFeito(['cobranca_padrao' => ['ciclo' => 'mensal', 'dia_fechamento' => 0, 'prazo_dias' => '61']]));
confere(erroDa($resposta) === 'O prazo de vencimento vai de 0 a 60 dias.', 'prazo em texto acima de 60: a mensagem do CicloDeCobranca');
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

echo '== App do motoboy: extrato (MotoboyController@extrato)' . PHP_EOL;

/** Chamada do app com o token do motoboy (Sanctum "id|token") e a sessão montada pelo core. */
function doApp(array $dados = [], string $usuario = 'user-m1', string $token = '12|abc'): RequestComArquivo
{
    session(['company' => 'empresa-1', 'user' => $usuario]);
    $request        = pedidoFeito($dados);
    $request->token = $token;

    return $request;
}

['ids' => $ids, 'pagamento' => $pagamento] = cenario();
ChavePix::salvar('empresa-1', 'driver-1', '11999994321', 'telefone');
$app = new App\Http\Controllers\Entregas\MotoboyController();
confere($app->extrato(doApp(['inicio' => '2026-10-01', 'fim' => '2026-10-12'], 'user-m1', 'flb_live_chave'))->status === 403, 'chave de API (sem "|") → 403');
confere($app->extrato(doApp(['inicio' => '2026-10-01', 'fim' => '2026-10-12'], 'user-loja'))->status === 403, 'usuário sem cadastro de motoboy → 403');
foreach ([
    'sem início'      => ['fim' => '2026-10-12'],
    'sem fim'         => ['inicio' => '2026-10-01'],
    'data inválida'   => ['inicio' => '2026-10-01', 'fim' => '2026-10-32'],
    'fim antes'       => ['inicio' => '2026-10-12', 'fim' => '2026-10-01'],
    'mais de 92 dias' => ['inicio' => '2026-07-01', 'fim' => '2026-10-12'],
] as $caso => $dados) {
    $resposta = $app->extrato(doApp($dados));
    confere($resposta->status === 422 && erroDa($resposta) !== null, "extrato {$caso} → 422 (" . erroDa($resposta) . ')');
}
$resposta = $app->extrato(doApp(['inicio' => '2026-07-12', 'fim' => '2026-10-12']));
confere($resposta->status === 200, '92 dias: aceito');
$resposta = $app->extrato(doApp(['inicio' => '2026-10-03', 'fim' => '2026-10-12']));
$extrato  = $resposta->dados;
$tudo     = json_encode($extrato, JSON_UNESCAPED_UNICODE);
confere($resposta->status === 200 && ($extrato['conta']['id'] ?? null) === 'driver_1' && reais($extrato['saldo'] ?? null) === 31.5, 'o extrato do motoboy do token');
confere(($extrato['conta']['pix'] ?? null) === ['chave' => '11999994321', 'tipo' => 'telefone'], 'com a chave Pix dele (só leitura)');
confere(array_column($extrato['lancamentos'] ?? [], 'id') === [$ids['ajuste'], $ids['pagamento'], $ids['diaria'], $ids['corrida2']], 'só os lançamentos dele no período');
confere(!str_contains($tudo, 'observacao_interna') && !str_contains($tudo, 'combinado com o João') && !str_contains($tudo, 'criado_por') && !str_contains($tudo, 'comprovante_url'), 'nunca a observação interna, quem lançou ou o link do comprovante na lista');
confere(!str_contains($tudo, '"valor":12') && linhaDoExtrato($extrato, $ids['loja1']) === null, 'nunca o valor da loja nem lançamento da conta da loja');
$daMaria = $app->extrato(doApp(['inicio' => '2026-10-01', 'fim' => '2026-10-12'], 'user-m2'))->dados;
confere(array_column($daMaria['lancamentos'] ?? [], 'id') === [$ids['daMaria']] && ($daMaria['conta']['id'] ?? null) === 'driver_2', 'outro motoboy vê só o dele');
// a empresa vem da sessão: o mesmo usuário noutra empresa não acha o cadastro
session(['company' => 'empresa-2', 'user' => 'user-m1']);
$request        = pedidoFeito(['inicio' => '2026-10-01', 'fim' => '2026-10-12']);
$request->token = '12|abc';
confere($app->extrato($request)->status === 403, 'sessão de outra empresa: sem cadastro de motoboy → 403');

echo '== App do motoboy: recibo (MotoboyController@pagamento)' . PHP_EOL;
$resposta = $app->pagamento(doApp(), $pagamento->uuid);
$recibo   = $resposta->dados;
confere($resposta->status === 200 && ($recibo['id'] ?? null) === $pagamento->uuid && ($recibo['valor'] ?? null) === 15.0 && ($recibo['forma'] ?? null) === 'pix' && ($recibo['data'] ?? null) === '2026-10-06' && ($recibo['estornado'] ?? null) === false, 'recibo do pagamento dele: ' . json_encode($recibo));
confere(($recibo['comprovante']['tipo'] ?? null) === 'image/jpeg' && ($recibo['comprovante']['nome'] ?? null) === 'pix.jpg' && str_contains((string) ($recibo['comprovante']['url'] ?? ''), '/v1/entregas/comprovantes/' . $pagamento->uuid . '?expires='), 'comprovante com o link assinado, o tipo e o nome');
$assinada = end(\Illuminate\Support\Facades\URL::$assinadas);
confere(($assinada['nome'] ?? null) === 'entregas.comprovantes.mostrar' && ($assinada['parametros'] ?? null) === ['uuid' => $pagamento->uuid], 'o link aponta para a rota nomeada do comprovante');
confere(array_keys($recibo) === ['id', 'valor', 'forma', 'data', 'estornado', 'comprovante'] && !str_contains(json_encode($recibo), 'pix do banco X'), 'só os campos do contrato (sem observação interna)');
confere($app->pagamento(doApp([], 'user-m2'), $pagamento->uuid)->status === 404, 'pagamento de outro motoboy → 404');
$daLoja = Pagamentos::registrar('empresa-1', 'loja', 'vendor-a', 5, 'pix', '2026-10-09', null, null, 'user-admin');
confere($app->pagamento(doApp(), $daLoja->uuid)->status === 404, 'recebimento de loja → 404');
confere($app->pagamento(doApp(), 'nao-existe')->status === 404, 'pagamento inexistente → 404');
confere($app->pagamento(doApp([], 'user-m1', 'flb_live_chave'), $pagamento->uuid)->status === 403, 'recibo com chave de API → 403');
$semArquivo = Pagamentos::registrar('empresa-1', 'motoboy', 'driver-1', 5, 'dinheiro', '2026-10-09', null, null, 'user-admin');
confere(nulo($app->pagamento(doApp(), $semArquivo->uuid)->dados, 'comprovante'), 'pagamento sem arquivo: comprovante null');
Pagamentos::estornar($semArquivo, 'user-admin');
confere(($app->pagamento(doApp(), $semArquivo->uuid)->dados['estornado'] ?? null) === true, 'pagamento estornado: estornado true');

echo '== Comprovante por URL assinada (ComprovantesController@mostrar)' . PHP_EOL;
$comprovantes = new App\Http\Controllers\Entregas\ComprovantesController();
session(['company' => null, 'user' => null]);
$resposta = $comprovantes->mostrar(pedidoFeito(), $pagamento->uuid);
confere(($resposta->disco ?? null) === 'local' && ($resposta->caminho ?? null) === 'entregas/comprovantes/empresa-1/' . $pagamento->uuid . '.jpg', 'serve o arquivo do disco local (sem sessão: a assinatura da URL é a proteção)');
confere(($resposta->nome ?? null) === 'pix.jpg' && ($resposta->disposicao ?? null) === 'inline' && ($resposta->cabecalhos['Content-Type'] ?? null) === 'image/jpeg' && ($resposta->cabecalhos['X-Content-Type-Options'] ?? null) === 'nosniff', 'inline, com o tipo gravado e nosniff: ' . json_encode($resposta));
Banco::$tabelas['entregas_pagamentos'][(int) $pagamento->id]['comprovante_nome'] = "pix\n../a\\b.jpg";
confere(($comprovantes->mostrar(pedidoFeito(), $pagamento->uuid)->nome ?? null) === 'pix_.._a_b.jpg', 'nome do arquivo sem quebra de linha nem barras no Content-Disposition');
confere($comprovantes->mostrar(pedidoFeito(), 'nao-existe')->status === 404, 'pagamento inexistente → 404');
confere($comprovantes->mostrar(pedidoFeito(), $semArquivo->uuid)->status === 404, 'pagamento sem comprovante → 404');
\Teste\Disco::$arquivos = [];
confere($comprovantes->mostrar(pedidoFeito(), $pagamento->uuid)->status === 404, 'arquivo que sumiu do disco → 404');

echo '== App do motoboy: carteira (Carteira::doMotoboy)' . PHP_EOL;
// o cenário: João (driver-1) com saldo 31,50 e o pagamento de 15,00 (Pix) em 06/10; hoje = 12/10/2026
['pagamento' => $pagamento] = cenario();
ChavePix::salvar('empresa-1', 'driver-1', '11999994321', 'telefone');
$carteira = Carteira::doMotoboy('empresa-1', 'driver-1');
confere(array_keys($carteira) === ['a_receber', 'mes', 'recebido_no_mes', 'pagamentos', 'pix'], 'só os campos do contrato: ' . json_encode(array_keys($carteira)));
confere(reais($carteira['a_receber']) === 31.5 && $carteira['mes'] === '2026-10' && reais($carteira['recebido_no_mes']) === 15.0, 'a receber = saldo da conta; recebido no mês = o pagamento de 06/10: ' . json_encode($carteira));
confere($carteira['pagamentos'] === [['id' => $pagamento->uuid, 'valor' => 15.0, 'data' => '2026-10-06', 'forma' => 'pix', 'estornado' => false]], 'o pagamento dele, sem comprovante nem observação interna');
confere($carteira['pix'] === ['chave' => '11999994321', 'tipo' => 'telefone'], 'a chave Pix dele ({chave, tipo})');

Pagamentos::registrar('empresa-1', 'motoboy', 'driver-1', 20.0, 'dinheiro', '2026-09-28', null, null, 'user-admin'); // mês passado
$estornado = Pagamentos::registrar('empresa-1', 'motoboy', 'driver-1', 7.0, 'pix', '2026-10-09', null, null, 'user-admin');
Pagamentos::estornar($estornado, 'user-admin');
Pagamentos::registrar('empresa-1', 'motoboy', 'driver-1', 1.0, 'pix', '2026-10-10', null, null, 'user-admin');
Pagamentos::registrar('empresa-1', 'motoboy', 'driver-1', 2.0, 'pix', '2026-10-11', null, null, 'user-admin');
Pagamentos::registrar('empresa-1', 'motoboy', 'driver-1', 3.0, 'transferencia', '2026-10-11', null, null, 'user-admin');
Pagamentos::registrar('empresa-1', 'motoboy', 'driver-2', 99.0, 'pix', '2026-10-11', null, null, 'user-admin'); // da Maria
$carteira = Carteira::doMotoboy('empresa-1', 'driver-1');
confere(reais($carteira['recebido_no_mes']) === 21.0, 'recebido no mês sem o de setembro, sem o estornado e sem o da Maria (15 + 1 + 2 + 3): ' . json_encode($carteira['recebido_no_mes']));
confere(array_column($carteira['pagamentos'], 'data') === ['2026-10-11', '2026-10-11', '2026-10-10', '2026-10-09', '2026-10-06'], 'os 5 mais recentes, mais novo primeiro (o de setembro fica de fora): ' . json_encode(array_column($carteira['pagamentos'], 'data')));
confere(array_column($carteira['pagamentos'], 'valor') === [3.0, 2.0, 1.0, 7.0, 15.0] && array_column($carteira['pagamentos'], 'forma')[0] === 'transferencia', 'no mesmo dia, o lançado por último primeiro');
confere(array_column($carteira['pagamentos'], 'estornado') === [false, false, false, true, false], 'o estornado vem na lista, marcado');
confere(reais($carteira['a_receber']) === 5.5, 'a receber = saldo (31,50 − 20 − 1 − 2 − 3; o estornado se anula): ' . json_encode($carteira['a_receber']));
Pagamentos::registrar('empresa-1', 'motoboy', 'driver-1', 30.0, 'pix', '2026-10-12', null, null, 'user-admin');
confere(reais(Carteira::doMotoboy('empresa-1', 'driver-1')['a_receber']) === -24.5, 'pagou a mais: a receber negativo (o app mostra "Adiantamento")');
confere(Carteira::doMotoboy('empresa-1', 'driver-sem-conta') === ['a_receber' => 0.0, 'mes' => '2026-10', 'recebido_no_mes' => 0.0, 'pagamentos' => [], 'pix' => null], 'motoboy sem lançamentos: zeros, lista vazia e sem Pix');

echo '== Rotas (RouteServiceProvider)' . PHP_EOL;
$rotas = file_get_contents('/repo/api/app/Providers/RouteServiceProvider.php');

/** O trecho do grupo que começa em $inicio (até o fim do group). */
function grupoDasRotas(string $rotas, string $inicio): string
{
    $comeco = strpos($rotas, $inicio);
    if ($comeco === false) {
        return '';
    }
    $fim = strpos($rotas, '});', $comeco);

    return substr($rotas, $comeco, $fim === false ? null : $fim - $comeco);
}

$console = grupoDasRotas($rotas, "Route::prefix('int/v1/entregas')");
foreach ([
    "Route::get('financeiro/painel', [FinanceiroController::class, 'painel'])",
    "Route::get('financeiro/motoboys', [FinanceiroController::class, 'motoboys'])",
    "Route::get('financeiro/motoboys/{id}/extrato', [FinanceiroController::class, 'extratoDoMotoboy'])",
    "Route::put('financeiro/motoboys/{id}/pix', [FinanceiroController::class, 'salvarPix'])",
    "Route::post('financeiro/lancamentos', [FinanceiroController::class, 'lancar'])",
    "Route::post('financeiro/lancamentos/{id}/estornar', [FinanceiroController::class, 'estornarLancamento'])",
    "Route::post('financeiro/pagamentos', [FinanceiroController::class, 'registrarPagamento'])",
    "Route::post('financeiro/pagamentos/{id}/estornar', [FinanceiroController::class, 'estornarPagamento'])",
    "Route::get('financeiro/configuracoes', [FinanceiroController::class, 'configuracoes'])",
    "Route::put('financeiro/configuracoes', [FinanceiroController::class, 'salvarConfiguracoes'])",
] as $linha) {
    confere(str_contains($console, $linha), 'console (fleetbase.protected): ' . $linha);
}
confere(str_contains($console, "->middleware(['fleetbase.protected'])"), 'o grupo das rotas do console é o fleetbase.protected');

$doApp = grupoDasRotas($rotas, "->middleware(['fleetbase.api', 'throttle:entregas-motoboy'])");
confere(str_contains($doApp, "Route::get('extrato', [MotoboyController::class, 'extrato'])") && str_contains($doApp, "Route::get('pagamentos/{id}', [MotoboyController::class, 'pagamento'])"), 'app: extrato e pagamentos/{id} no grupo v1/entregas/motoboy (fleetbase.api, throttle:entregas-motoboy)');

$doComprovante = grupoDasRotas($rotas, "Route::prefix('v1/entregas/comprovantes')");
confere(str_contains($doComprovante, "->middleware(['signed', 'throttle:60,1'])"), 'comprovante: grupo próprio com signed e throttle:60,1 (sem token)');
confere(str_contains($doComprovante, "Route::get('{uuid}', [ComprovantesController::class, 'mostrar'])->name(Pagamentos::ROTA_DO_COMPROVANTE)"), 'comprovante: GET {uuid} com o nome Pagamentos::ROTA_DO_COMPROVANTE');
confere(!str_contains($doComprovante, 'fleetbase.api') && !str_contains($doComprovante, 'fleetbase.protected'), 'comprovante fora dos grupos do Fleetbase (o link abre sem token)');
foreach (['FinanceiroController', 'ComprovantesController'] as $classe) {
    confere(str_contains($rotas, 'use App\\Http\\Controllers\\Entregas\\' . $classe . ';'), "use do {$classe} no provider");
}
confere(str_contains($rotas, 'use App\\Support\\Entregas\\Financeiro\\Pagamentos;'), 'use do Pagamentos (o nome da rota) no provider');

resumo();
