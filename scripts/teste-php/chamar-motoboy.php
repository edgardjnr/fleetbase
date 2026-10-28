<?php

// Chamar motoboy pelo portal da loja: a tabela entregas_portal_pedidos, o CriadorDoChamado, o PedidosDoPortal, o
// ChamarMotoboyController e os leitores da linha (app do motoboy, comanda, detalhe do portal).
// Spec: docs/superpowers/specs/2026-10-28-chamar-motoboy-portal-design.md.
// Uso: PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/chamar-motoboy.php

require __DIR__ . '/stubs-integracao.php';

use App\Support\Entregas\Integracao\CobrancaDaIntegracao;
use App\Support\Entregas\Integracao\DadosDaIntegracaoDoMotoboy;
use App\Support\Entregas\Portal\PedidosDoPortal;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Teste\Banco;

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

resumo();
