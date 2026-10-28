<?php

// Chamar motoboy pelo portal da loja: a tabela entregas_portal_pedidos, o CriadorDoChamado, o PedidosDoPortal, o
// ChamarMotoboyController e os leitores da linha (app do motoboy, comanda, detalhe do portal).
// Spec: docs/superpowers/specs/2026-10-28-chamar-motoboy-portal-design.md.
// Uso: PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/chamar-motoboy.php

require __DIR__ . '/stubs-integracao.php';

use App\Http\Controllers\Entregas\ChamarMotoboyController;
use App\Support\Entregas\Integracao\CobrancaDaIntegracao;
use App\Support\Entregas\Integracao\DadosDaIntegracaoDoMotoboy;
use App\Support\Entregas\PedidoRecusado;
use App\Support\Entregas\Portal\ChamadoDaLoja;
use App\Support\Entregas\Portal\CriadorDoChamado;
use App\Support\Entregas\Portal\PedidosDoPortal;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Http\Request;
use Teste\Banco;
use Teste\Sessao;
use Teste\Trava;


/** Uma linha da entregas_portal_pedidos (dados fictícios), com $campos por cima. */
function linhaDoPortal(array $campos = []): void
{
    Banco::inserir('entregas_portal_pedidos', $campos + [
        'company_uuid' => 'empresa-1', 'vendor_uuid' => 'vendor-a', 'order_uuid' => 'order-1', 'place_uuid' => 'place-cliente',
        'chave' => '6f1c0e8a-2b1d-4c3e-9a7f-0d5b8c2e4f10', 'cliente_nome' => 'Mariana Souza', 'cliente_telefone' => '16999990000',
        'complemento' => 'Ap 12', 'referencia' => 'Perto da praça', 'observacoes' => 'Tocar o interfone',
        'cobranca_forma' => 'dinheiro', 'cobranca_centavos' => 5890, 'troco_para_centavos' => 10000,
        'created_at' => '2026-10-05 14:00:00', 'updated_at' => '2026-10-05 14:00:00',
    ], false);
}

/** O Local do cliente (dono = a loja A). */
function localDoCliente(array $campos = []): Place
{
    $lugar = new Place($campos + [
        'uuid' => 'place-cliente', 'company_uuid' => 'empresa-1', 'owner_uuid' => 'vendor-a', 'owner_type' => 'fleet-ops:vendor',
        'name' => 'MARIANA SOUZA', 'street1' => 'RUA OLINDA, 45', 'street2' => 'AP 12', 'neighborhood' => 'CENTRO',
        'city' => 'RIBEIRÃO PRETO', 'province' => 'SP', 'postal_code' => '14010-000', 'location' => new Point(-21.18, -47.81),
    ]);
    Place::$todos[] = $lugar;

    return $lugar;
}

/** Os dados validados de um chamado (o corpo do painel), com $mudancas por cima. */
function dadosDoChamado(array $mudancas = []): array
{
    $r = ChamadoDaLoja::validar(array_replace_recursive([
        'chave'      => '6f1c0e8a-2b1d-4c3e-9a7f-0d5b8c2e4f10',
        'cliente'    => ['nome' => 'Mariana Souza', 'telefone' => '(16) 99999-0000'],
        'entrega'    => ['rua' => 'Rua Olinda, 45', 'bairro' => 'Centro', 'cidade' => 'Ribeirão Preto', 'uf' => 'SP', 'cep' => '14010-000', 'latitude' => -21.18, 'longitude' => -47.81, 'complemento' => 'Ap 12', 'referencia' => 'Perto da praça'],
        'cobranca'   => ['forma' => 'dinheiro', 'valor' => '58,90', 'troco_para' => '100'],
        'observacao' => 'Tocar o interfone',
    ], $mudancas));

    return $r['dados'] ?? throw new \RuntimeException('chamado de teste inválido: ' . implode(' ', $r['erros']));
}

function lojaA(): Vendor
{
    return Vendor::where('uuid', 'vendor-a')->first();
}

function coletaA(): Place
{
    return Place::where('uuid', 'place-loja')->first();
}

/** Chama e devolve [linha, pedido, novo]; uma recusa volta como PedidoRecusado. */
function chamar(array $mudancas = [], ?Place $coleta = null)
{
    try {
        return (new CriadorDoChamado())->criar(lojaA(), $coleta ?? coletaA(), dadosDoChamado($mudancas), 'usuario-da-loja');
    } catch (PedidoRecusado $e) {
        return $e;
    }
}

// a loja e a coleta da sessão vêm do LojaDoUsuario (Eloquent com whereHas); aqui são fixas. O crédito é contado e pode
// ser recusado pelo teste (a regra dele tem os testes no prepago-loja.php)
class ControllerDeTeste extends ChamarMotoboyController
{
    public ?Vendor $loja = null;
    public int $creditos = 0;
    public ?PedidoRecusado $recusa = null;
    /** Roda dentro da conferência do crédito, antes da recusa (a requisição gêmea gravando no meio), ou null. */
    public ?\Closure $antesDoCredito = null;

    protected function lojaDaSessao(): ?Vendor { return $this->loja; }
    protected function coletaDaLoja(Vendor $vendor): ?Place { return coletaA(); }
    protected function comCredito(string $empresa, string $lojaUuid, \Closure $criar)
    {
        $this->creditos++;
        if ($this->antesDoCredito) {
            ($this->antesDoCredito)();
        }
        if ($this->recusa) {
            throw $this->recusa;
        }

        return $criar();
    }
}

function controller(): ControllerDeTeste
{
    reiniciarIntegracao();
    Sessao::$dados = ['company' => 'empresa-1', 'user' => 'usuario-da-loja'];
    $c       = new ControllerDeTeste();
    $c->loja = lojaA();

    return $c;
}

function corpoDaTela(array $mudancas = []): array
{
    return array_replace_recursive([
        'chave'      => '6f1c0e8a-2b1d-4c3e-9a7f-0d5b8c2e4f10',
        'cliente'    => ['nome' => 'Mariana Souza', 'telefone' => '16999990000'],
        'entrega'    => ['rua' => 'Rua Olinda, 45', 'bairro' => 'Centro', 'cidade' => 'Ribeirão Preto', 'uf' => 'SP', 'cep' => '14010-000', 'latitude' => -21.18, 'longitude' => -47.81, 'complemento' => 'Ap 12', 'referencia' => null],
        'cobranca'   => ['forma' => 'pago'],
        'observacao' => null,
    ], $mudancas);
}

$raiz = dirname(__DIR__, 2);

echo '== Tabela entregas_portal_pedidos' . PHP_EOL;
$esquema = Banco::esquema()['entregas_portal_pedidos'] ?? null;
confere($esquema !== null, 'a migration cria a tabela');
foreach (['id', 'company_uuid', 'vendor_uuid', 'order_uuid', 'place_uuid', 'chave', 'cliente_nome', 'cliente_telefone', 'complemento', 'referencia', 'observacoes', 'cobranca_forma', 'cobranca_centavos', 'troco_para_centavos', 'created_at', 'updated_at'] as $coluna) {
    confere(in_array($coluna, $esquema['colunas'] ?? [], true), "coluna {$coluna}");
}
confere(in_array('order_uuid', $esquema['unicas'] ?? [], true), 'um pedido, uma linha (order_uuid único)');
confere(in_array(['vendor_uuid', 'chave'], $esquema['compostas'] ?? [], true), 'a chave do chamado é única por loja');
confere(($esquema['padroes']['cobranca_centavos'] ?? null) === 0, 'cobranca_centavos começa em 0');
$migration = file_get_contents($raiz . '/api/database/migrations/2026_10_28_150000_create_entregas_portal_pedidos_table.php');
confere(str_contains($migration, "->charset('ascii')->collation('ascii_bin')"), 'chave em ascii_bin');
confere(str_contains($migration, "\$table->index(['vendor_uuid', 'cliente_telefone'], 'entregas_portal_ped_tel_index');"), 'índice da busca pelo telefone');

echo '== PedidosDoPortal: linha do pedido e da chave' . PHP_EOL;
reiniciarIntegracao();
linhaDoPortal();
confere(PedidosDoPortal::doPedido('order-1')?->cliente_telefone === '16999990000', 'doPedido acha a linha');
confere(PedidosDoPortal::doPedido('order-2') === null && PedidosDoPortal::doPedido(null) === null && PedidosDoPortal::doPedido('') === null, 'sem linha: null');
confere(PedidosDoPortal::daChave('vendor-a', '6f1c0e8a-2b1d-4c3e-9a7f-0d5b8c2e4f10')?->order_uuid === 'order-1', 'daChave da loja');
confere(PedidosDoPortal::daChave('vendor-b', '6f1c0e8a-2b1d-4c3e-9a7f-0d5b8c2e4f10') === null, 'a chave de outra loja não serve');
$fonte = file_get_contents($raiz . '/api/app/Support/Entregas/Portal/PedidosDoPortal.php');
confere(str_contains($fonte, 'catch (QueryException $e)') && str_contains($fonte, 'pedidos do portal ilegíveis no banco'), 'doPedido engole o erro de banco (tabela ainda sem migration) com warning');

echo '== PedidosDoPortal: cliente do telefone' . PHP_EOL;
reiniciarIntegracao();
localDoCliente();
localDoCliente(['uuid' => 'place-antigo', 'street1' => 'RUA ANTIGA, 1']);
linhaDoPortal(['order_uuid' => 'order-0', 'place_uuid' => 'place-antigo', 'chave' => '00000000-0000-4000-8000-000000000000', 'cliente_nome' => 'Mari', 'complemento' => null]);
linhaDoPortal();
linhaDoPortal(['vendor_uuid' => 'vendor-b', 'order_uuid' => 'order-b', 'chave' => '11111111-1111-4111-8111-111111111111', 'cliente_nome' => 'De outra loja']);
$c = PedidosDoPortal::clienteDoTelefone('empresa-1', 'vendor-a', '16999990000');
confere($c['nome'] === 'Mariana Souza' && $c['telefone'] === '16999990000' && $c['pedidos'] === 2, 'nome do último pedido e a contagem só da loja');
confere($c['endereco'] === ['rua' => 'RUA OLINDA, 45', 'bairro' => 'CENTRO', 'cidade' => 'RIBEIRÃO PRETO', 'uf' => 'SP', 'cep' => '14010-000', 'complemento' => 'Ap 12', 'referencia' => 'Perto da praça', 'latitude' => -21.18, 'longitude' => -47.81], 'último endereço: o Local + complemento e referência da linha');
confere(PedidosDoPortal::clienteDoTelefone('empresa-1', 'vendor-a', '16988887777') === null, 'telefone sem pedido: null');
confere(PedidosDoPortal::clienteDoTelefone('empresa-1', 'vendor-c', '16999990000') === null, 'loja sem o telefone: null (não vê o de outra loja)');
Place::$todos = array_values(array_filter(Place::$todos, fn ($p) => $p->uuid !== 'place-cliente'));
confere(PedidosDoPortal::clienteDoTelefone('empresa-1', 'vendor-a', '16999990000')['endereco'] === null, 'Local apagado: só o nome');

echo '== PedidosDoPortal: no formato da integração (app do motoboy)' . PHP_EOL;
reiniciarIntegracao();
linhaDoPortal();
$linha  = PedidosDoPortal::comoLinhaDaIntegracao(PedidosDoPortal::doPedido('order-1'));
$pedido = new Order(['uuid' => 'order-1', 'public_id' => 'order_1', 'status' => 'driver_enroute', 'driver_assigned_uuid' => 'driver-1']);
$r      = DadosDaIntegracaoDoMotoboy::resposta($linha, $pedido, 'driver-1');
confere($r['integracao'] === true && $r['sistema'] === 'Portal' && $r['numero'] === null, 'integracao true, selo "Portal", sem número da origem');
confere($r['cobranca']['texto'] === CobrancaDaIntegracao::texto('dinheiro', 5890, 10000) && $r['cobranca']['centavos'] === 5890 && $r['cobranca']['troco_para_centavos'] === 10000, 'a cobrança do app');
confere($r['observacoes'] === 'Tocar o interfone' && $r['complemento'] === 'Ap 12' && $r['referencia'] === 'Perto da praça', 'observação, complemento e referência');
confere($r['telefone'] === '16999990000' && $r['itens'] === null && $r['cancelado'] === false && $r['pago_mesmo_cancelado'] === false, 'telefone ao motoboy do pedido; sem itens nem cancelamento pago');
confere(DadosDaIntegracaoDoMotoboy::resposta($linha, $pedido, 'driver-2')['telefone'] === null, 'outro motoboy: sem telefone');
confere(PedidosDoPortal::comoLinhaDaIntegracao(null) === null, 'sem linha: null');
linhaDoPortal(['order_uuid' => 'order-2', 'chave' => '22222222-2222-4222-8222-222222222222', 'cobranca_forma' => 'pago', 'cobranca_centavos' => 0, 'troco_para_centavos' => null]);
$pago = DadosDaIntegracaoDoMotoboy::resposta(PedidosDoPortal::comoLinhaDaIntegracao(PedidosDoPortal::doPedido('order-2')), $pedido, 'driver-1');
confere($pago['cobranca']['forma'] === 'pago' && $pago['cobranca']['texto'] === null, 'já pago: o app mostra "Já pago"');

echo '== PedidosDoPortal: resumo da resposta' . PHP_EOL;
reiniciarIntegracao();
localDoCliente();
linhaDoPortal();
$resumo = PedidosDoPortal::resumo(new Order(['uuid' => 'order-1', 'public_id' => 'order_1', 'tracking' => 'RP123456']), PedidosDoPortal::doPedido('order-1'));
confere($resumo === ['id' => 'order_1', 'numero' => 'RP123456', 'cliente' => 'Mariana Souza', 'endereco' => 'RUA OLINDA, 45 · Ap 12 · CENTRO', 'cobranca' => CobrancaDaIntegracao::texto('dinheiro', 5890, 10000)], 'id, rastreio, cliente, endereço e cobrança');
confere(PedidosDoPortal::resumo(new Order(['uuid' => 'order-1', 'public_id' => 'order_1']), PedidosDoPortal::doPedido('order-1'))['numero'] === 'order_1', 'sem rastreio: o public_id');

echo '== CriadorDoChamado: endereço novo' . PHP_EOL;
reiniciarIntegracao();
[$linha, $pedido, $novo] = chamar();
$destino = Place::$criados[0] ?? null;
confere($novo === true && count(Order::$criados) === 1 && count(Place::$criados) === 1, 'um pedido e um Local novos');
confere($pedido->customer_uuid === 'vendor-a' && $pedido->customer_type === 'fleet-ops:vendor' && $pedido->company_uuid === 'empresa-1' && $pedido->empresaNaSessao === 'empresa-1', 'cliente = a loja, empresa na sessão');
confere($pedido->type === 'transport' && $pedido->order_config_uuid === 'config-transport' && $pedido->adhocAoCriar === true && $pedido->status === 'dispatched', 'tipo transport, criado como pedido aberto e despachado');
confere(!isset($pedido->notes) && ($pedido->meta['customer_portal']['created_by_uuid'] ?? null) === 'usuario-da-loja', 'sem notas (observação com # viraria selo); meta.customer_portal como o portal grava');
confere($destino->owner_uuid === 'vendor-a' && $destino->owner_type === 'fleet-ops:vendor' && $destino->company_uuid === 'empresa-1', 'Local do cliente com dono = a loja');
confere($destino->name === 'MARIANA SOUZA' && $destino->street1 === 'RUA OLINDA, 45' && $destino->street2 === 'AP 12' && $destino->neighborhood === 'CENTRO' && $destino->city === 'RIBEIRÃO PRETO' && $destino->province === 'SP' && $destino->postal_code === '14010-000' && $destino->country === 'BR', 'endereço em maiúsculas (com acento)');
confere($destino->location->getLat() === -21.18 && $destino->location->getLng() === -47.81 && !isset($destino->phone), 'ponto marcado; o telefone não vai para o Local');
$payload = Payload::$salvos[0];
confere($payload->pickup->uuid === 'place-loja' && $payload->dropoff === $destino && $pedido->payload_uuid === $payload->uuid, 'coleta = Local da loja, entrega = o Local do cliente');
confere($linha->order_uuid === $pedido->uuid && $linha->vendor_uuid === 'vendor-a' && $linha->company_uuid === 'empresa-1' && $linha->place_uuid === $destino->uuid && $linha->chave === '6f1c0e8a-2b1d-4c3e-9a7f-0d5b8c2e4f10', 'linha ligada ao pedido, ao Local e à chave');
confere($linha->cliente_nome === 'Mariana Souza' && $linha->cliente_telefone === '16999990000' && $linha->complemento === 'Ap 12' && $linha->referencia === 'Perto da praça' && $linha->observacoes === 'Tocar o interfone', 'cliente, complemento, referência e observação na linha');
confere($linha->cobranca_forma === 'dinheiro' && (int) $linha->cobranca_centavos === 5890 && (int) $linha->troco_para_centavos === 10000, 'cobrança na linha');
confere($pedido->chamadas === ['saveQuietly', 'firstDispatchWithActivity'] && $pedido->travadoNoDespacho === [true] && Trava::$ocupadas === [], 'despachado como pedido aberto, com a trava, e a trava solta');
confere(logou('[entregas] portal da loja: motoboy chamado', 'info') && logsSem(['Mariana', 'MARIANA', 'OLINDA', 'Olinda', '16999990000', 'interfone']), 'log sem nome, endereço, telefone nem observação');

echo '== CriadorDoChamado: mesma chave' . PHP_EOL;
[$repetida, $mesmo, $novo] = chamar(['cobranca' => ['valor' => '99']]);
confere($novo === false && $mesmo === $pedido && $repetida->id === $linha->id && count(Order::$criados) === 1, 'a repetição devolve o mesmo pedido, sem criar outro');
confere(logou('[entregas] portal da loja: chamado repetido', 'info'), 'log da repetição');

echo '== CriadorDoChamado: mesmo cliente no mesmo ponto reaproveita o Local' . PHP_EOL;
[$linha2, $pedido2, $novo2] = chamar(['chave' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'cliente' => ['nome' => 'mariana souza '], 'entrega' => ['rua' => 'RUA OLINDA, 45', 'latitude' => -21.18009, 'complemento' => 'ap 12']]);
confere($novo2 === true && count(Order::$criados) === 2 && count(Place::$criados) === 1 && $linha2->place_uuid === $destino->uuid, 'nome, rua e complemento iguais (sem diferença de caixa) e ponto a 10 m: o mesmo Local');
confere(Payload::$salvos[1]->dropoff === $destino, 'a entrega do pedido novo é o Local de antes');
[$linha3] = chamar(['chave' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'cliente' => ['nome' => 'Joana Souza']]);
confere(count(Place::$criados) === 2 && $linha3->place_uuid !== $destino->uuid && $destino->name === 'MARIANA SOUZA', 'outro nome no mesmo telefone: Local novo, o antigo não muda');
[$linha4] = chamar(['chave' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'entrega' => ['latitude' => -21.181]]);
confere(count(Place::$criados) === 3 && $linha4->place_uuid !== $destino->uuid, 'mesmo cliente a mais de 30 m: Local novo');
[$linha5] = chamar(['chave' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'cliente' => ['telefone' => '16988887777']]);
confere(count(Place::$criados) === 4 && $linha5->place_uuid !== $destino->uuid, 'outro telefone: Local novo');

echo '== CriadorDoChamado: recusas' . PHP_EOL;
reiniciarIntegracao();
$r = chamar(['entrega' => ['latitude' => -21.1776, 'longitude' => -47.8103]]);
confere($r instanceof PedidoRecusado && $r->status === 422 && str_contains($r->getMessage(), 'mesmo lugar da loja') && Order::$criados === [] && Place::$criados === [], 'ponto a menos de 30 m da loja: 422, sem nada criado');
$r = chamar(['entrega' => ['latitude' => -21.7, 'longitude' => -47.81]]);
confere($r instanceof PedidoRecusado && $r->status === 422 && str_contains($r->getMessage(), 'mais de 50 km') && Order::$criados === [], 'ponto a mais de 50 km: 422');
$semPonto = new Place(['uuid' => 'place-loja', 'company_uuid' => 'empresa-1', 'owner_uuid' => 'vendor-a', 'owner_type' => 'fleet-ops:vendor', 'location' => null]);
$r = chamar([], $semPonto);
confere($r instanceof PedidoRecusado && $r->status === 422 && str_contains($r->getMessage(), 'endereço de coleta') && logou('[entregas] portal da loja: loja sem local de coleta válido', 'warning'), 'loja sem coleta válida: 422 e warning');
$deOutraLoja = new Place(['uuid' => 'place-x', 'company_uuid' => 'empresa-1', 'owner_uuid' => 'vendor-b', 'owner_type' => 'fleet-ops:vendor', 'location' => new Point(-21.1775, -47.8103)]);
confere(chamar([], $deOutraLoja) instanceof PedidoRecusado, 'coleta com outro dono: recusa');

echo '== CriadorDoChamado: falha no despacho' . PHP_EOL;
reiniciarIntegracao();
Order::$falharDespacho = true;
[$linha, $pedido, $novo] = chamar();
confere($novo === true && count(Order::$criados) === 1 && $linha !== null && $pedido->status === 'created', 'o pedido existe (status created, o da criação) mesmo com o despacho falhando');
confere(logou('[entregas] portal da loja: falha ao despachar o pedido aos motoboys', 'error') && Trava::$ocupadas === [], 'erro no log (a central despacha à mão) e a trava solta');

echo '== CriadorDoChamado: chave com o pedido apagado' . PHP_EOL;
reiniciarIntegracao();
linhaDoPortal(['order_uuid' => 'order-apagado']);
$r = chamar();
confere($r instanceof PedidoRecusado && $r->status === 422 && $r->getMessage() === 'Atualize a página e tente de novo.' && Order::$criados === [] && Place::$criados === [], 'linha da chave sem o pedido: 422 "Atualize a página", sem bater na chave única');
$r = null;
try {
    (new CriadorDoChamado())->repetido(lojaA(), '6f1c0e8a-2b1d-4c3e-9a7f-0d5b8c2e4f10');
} catch (PedidoRecusado $e) {
    $r = $e;
}
confere($r?->status === 422, 'repetido lança a recusa (não devolve null)');
confere((new CriadorDoChamado())->repetido(lojaA(), '99999999-9999-4999-8999-999999999999') === null, 'chave sem linha: null');

echo '== CriadorDoChamado: erro do banco sem dados no log' . PHP_EOL;
reiniciarIntegracao();
// como o QueryException do Laravel: a mensagem traz o SQL com os valores
Banco::$antesDeInserir['entregas_portal_pedidos'] = fn () => throw new \Teste\ErroDeBanco('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away (SQL: insert into entregas_portal_pedidos (cliente_nome, cliente_telefone, observacoes) values (Mariana Souza, 16999990000, Tocar o interfone))', 'HY000', 2006);
$erro = null;
try {
    chamar();
} catch (\RuntimeException $e) {
    $erro = $e;
}
confere($erro !== null && get_class($erro) === \RuntimeException::class && $erro->getPrevious() === null, 'relança um RuntimeException genérico, sem a QueryException encadeada');
confere($erro !== null && !str_contains($erro->getMessage(), 'Mariana') && !str_contains($erro->getMessage(), 'insert'), 'a mensagem não traz o SQL nem os valores');
$registro = array_values(array_filter(\Illuminate\Support\Facades\Log::$registros, fn ($r) => $r[1] === '[entregas] portal da loja: falha ao gravar o chamado'))[0] ?? null;
confere(($registro[0] ?? null) === 'error' && ($registro[2] ?? null) === ['erro' => \Teste\ErroDeBanco::class, 'sqlstate' => 'HY000', 'loja' => 'vendor_a'], 'log de erro só com a classe, o SQLSTATE e a loja');
confere(logsSem(['Mariana', 'MARIANA', '16999990000', 'interfone', 'insert into']), 'nenhum log com o SQL, o nome, o telefone ou a observação');
confere(Order::$criados === [] && (Banco::$tabelas['entregas_portal_pedidos'] ?? []) === [], 'nada gravado (a transação desfez)');

echo '== ChamarMotoboyController@chamar' . PHP_EOL;
$c = controller();
$r = $c->chamar(new Request(corpoDaTela()), new CriadorDoChamado());
confere($r->status === 201 && $r->dados['pedido']['id'] === Order::$criados[0]->public_id && $r->dados['pedido']['cliente'] === 'Mariana Souza' && $r->dados['pedido']['cobranca'] === null && $c->creditos === 1, '201 com o resumo; já pago = cobrança null; passou pelo crédito');
$r = $c->chamar(new Request(corpoDaTela()), new CriadorDoChamado());
confere($r->status === 200 && $r->dados['pedido']['id'] === Order::$criados[0]->public_id && count(Order::$criados) === 1 && $c->creditos === 1, 'mesma chave: 200 com o mesmo pedido, sem passar pelo crédito de novo');
$r = $c->chamar(new Request(corpoDaTela(['chave' => 'x', 'cliente' => ['telefone' => '123']])), new CriadorDoChamado());
confere($r->status === 422 && in_array('Atualize a página e tente de novo.', $r->dados['errors'], true) && in_array('Informe o telefone do cliente com DDD.', $r->dados['errors'], true), 'corpo inválido: 422 com as mensagens');
$c->recusa = new PedidoRecusado(422, 'Crédito insuficiente. Recarregue a carteira.');
$r         = $c->chamar(new Request(corpoDaTela(['chave' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee'])), new CriadorDoChamado());
confere($r->status === 422 && $r->dados === ['errors' => ['Crédito insuficiente. Recarregue a carteira.']] && count(Order::$criados) === 1, 'sem crédito: 422 com a mensagem, sem pedido');
$c->recusa = null;
// a requisição gêmea (mesma chave) criou o pedido enquanto esta conferia o crédito: a recusa (sem crédito, que o
// próprio pedido gêmeo reservou, ou a trava ocupada) vira o pedido dela, 200
foreach ([new PedidoRecusado(422, 'Crédito insuficiente. Recarregue a carteira.'), new PedidoRecusado(409, 'Outra operação está em andamento.')] as $recusa) {
    $g                 = controller();
    $g->antesDoCredito = function () { chamar(); };
    $g->recusa         = $recusa;
    $r                 = $g->chamar(new Request(corpoDaTela()), new CriadorDoChamado());
    confere($r->status === 200 && $r->dados['pedido']['id'] === (Order::$criados[0]->public_id ?? '?') && count(Order::$criados) === 1, "recusa {$recusa->status} com o pedido gêmeo já criado: 200 com ele");
}
$g = controller();
linhaDoPortal(['order_uuid' => 'order-apagado']);
$r = $g->chamar(new Request(corpoDaTela()), new CriadorDoChamado());
confere($r->status === 422 && $r->dados === ['errors' => ['Atualize a página e tente de novo.']] && $g->creditos === 0 && Order::$criados === [], 'chave com o pedido apagado: 422 "Atualize a página", sem passar pelo crédito');
$c = controller();
$r         = $c->chamar(new Request(corpoDaTela(['chave' => 'ffffffff-ffff-4fff-8fff-ffffffffffff','entrega' => ['latitude' => -21.1776, 'longitude' => -47.8103]])), new CriadorDoChamado());
confere($r->status === 422 && str_contains($r->dados['errors'][0], 'mesmo lugar da loja'), 'recusa do criador: 422 com a mensagem');
$c->loja = null;
$r       = $c->chamar(new Request(corpoDaTela()), new CriadorDoChamado());
confere($r->status === 403 && str_contains($r->dados['errors'][0], 'não está ligado a nenhuma loja'), 'usuário sem loja: 403');

echo '== ChamarMotoboyController@clientes' . PHP_EOL;
$c = controller();
localDoCliente();
linhaDoPortal();
$r = $c->clientes(new Request(['telefone' => '(16) 99999-0000']));
confere($r->dados['cliente']['nome'] === 'Mariana Souza' && $r->dados['cliente']['pedidos'] === 1, 'telefone formatado acha o cliente da loja');
confere($c->clientes(new Request(['telefone' => '9999']))->dados === ['cliente' => null], 'telefone incompleto: cliente null (sem 422)');
$c->loja = new Vendor(['uuid' => 'vendor-b', 'public_id' => 'vendor_b', 'company_uuid' => 'empresa-1', 'name' => 'Outra']);
confere($c->clientes(new Request(['telefone' => '16999990000']))->dados === ['cliente' => null], 'outra loja não vê o cliente');
$c->loja = null;
confere($c->clientes(new Request(['telefone' => '16999990000']))->status === 403, 'sem loja: 403');

echo '== Rotas e liberação' . PHP_EOL;
$rotas = file_get_contents($raiz . '/api/app/Providers/RouteServiceProvider.php');
confere(str_contains($rotas, "Route::get('loja/clientes', [ChamarMotoboyController::class, 'clientes'])->middleware('throttle:entregas-loja-clientes');"), 'GET loja/clientes no limitador nomeado');
$limitador = 'RateLimiter::for(\'entregas-loja-clientes\', fn (Request $request) => Limit::perMinute(60)->by(\'entregas-loja-clientes:\' . (session(\'user\') ?: $request->ip())));';
confere(str_contains($rotas, $limitador), 'limitador entregas-loja-clientes: 60 por minuto por usuário, balde próprio');
confere(str_contains($rotas, "Route::post('loja/chamar-motoboy', [ChamarMotoboyController::class, 'chamar'])->middleware('throttle:entregas-loja-chamar');"), 'POST loja/chamar-motoboy no limitador nomeado');
// o throttle sem nome divide um contador por usuário entre todas as rotas que o usam (o 60,1 da loja esgotaria um 20,1)
$limitador = 'RateLimiter::for(\'entregas-loja-chamar\', fn (Request $request) => Limit::perMinute(20)->by(\'entregas-loja-chamar:\' . (session(\'user\') ?: $request->ip())));';
confere(str_contains($rotas, $limitador),'limitador entregas-loja-chamar: 20 por minuto por usuário, balde próprio');
confere(str_contains($rotas, 'use App\Http\Controllers\Entregas\ChamarMotoboyController;'), 'import do controller');
$proteger = file_get_contents($raiz . '/api/app/Http/Middleware/ProtegerPortalLoja.php');
confere(str_contains($proteger, "'#^[A-Z]+ entregas/loja/.+$#'"), 'liberadas à loja pelo ProtegerPortalLoja (entregas/loja/*)');
$ctl = file_get_contents($raiz . '/api/app/Http/Controllers/Entregas/ChamarMotoboyController.php');
confere(str_contains($ctl, 'return CreditoNoPedido::criar($empresa, $lojaUuid, $criar);'), 'o crédito da loja pré-paga é o CreditoNoPedido');

echo '== Leitores da linha do portal' . PHP_EOL;
$motoboy = file_get_contents($raiz . '/api/app/Http/Controllers/Entregas/MotoboyController.php');
confere(str_contains($motoboy, 'DadosDaIntegracaoDoMotoboy::resposta(PedidosDaIntegracao::doPedido((string) $pedido->uuid) ?? PedidosDoPortal::comoLinhaDaIntegracao(PedidosDoPortal::doPedido((string) $pedido->uuid)), $pedido, (string) $motoboy->uuid)'), 'app do motoboy: a linha do portal no formato da integração');
$aberto = file_get_contents($raiz . '/api/app/Http/Controllers/Entregas/PedidoAbertoController.php');
confere(str_contains($aberto, 'portal: PedidosDoPortal::doPedido($uuid),'), 'comanda da central: a linha do portal');
$portal = file_get_contents($raiz . '/api/app/Http/Controllers/Entregas/PortalLojaController.php');
confere(str_contains($portal, '?? PedidosDaIntegracao::doPedido((string) $pedido->uuid) ?? PedidosDoPortal::doPedido((string) $pedido->uuid);'), 'detalhe do portal: a observação do pedido chamado');

resumo();
