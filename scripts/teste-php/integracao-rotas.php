<?php

// Integração: as rotas externas (IntegracaoController: conexão, criar, consultar, cancelar) e o que o console mostra
// (PainelDaIntegracao).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-rotas.php

require __DIR__ . '/stubs-integracao.php';

use App\Http\Controllers\Entregas\IntegracaoController;
use App\Support\Entregas\Integracao\CancelamentoPelaOrigem;
use App\Support\Entregas\Integracao\ChavesDeIntegracao;
use App\Support\Entregas\Integracao\CriadorDoPedidoDaIntegracao;
use App\Support\Entregas\Integracao\PainelDaIntegracao;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Http\Request;
use Teste\Trava;

function pedido(array $dados = [], ?string $chave = CHAVE_DA_LOJA_A): Request
{
    $request         = new Request($dados);
    $request->token  = $chave;
    $request->metodo = 'POST';

    return $request;
}

function controlador(): IntegracaoController
{
    return new IntegracaoController();
}

function criarPelaRota(array $mudancas = []): \Teste\RespostaJson
{
    return controlador()->criar(pedido(corpoDoPedido($mudancas)), app(CriadorDoPedidoDaIntegracao::class));
}

echo '== Autenticação' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
\Teste\Config::$valores['services.entregas.integracao'] = '';
$r = criarPelaRota();
confere($r->status === 409 && $r->dados === ['errors' => ['Integração desligada.']], 'desligada: 409');
\Teste\Config::$valores['services.entregas.integracao'] = '1';
foreach ([null, 'flb_live_xyz', 'ent_live_' . str_repeat('B', 40)] as $chave) {
    $r = controlador()->criar(pedido(corpoDoPedido(), $chave), app(CriadorDoPedidoDaIntegracao::class));
    confere($r->status === 401 && $r->dados === ['errors' => ['Chave de integração inválida ou revogada.']], 'chave inválida: 401 (' . var_export($chave, true) . ')');
}
ChavesDeIntegracao::revogar('empresa-1', 'vendor-a');
confere(criarPelaRota()->status === 401, 'chave revogada: 401');
reiniciarIntegracao();
lojaIntegradaA();
\Fleetbase\FleetOps\Models\Vendor::$todos = [];
$r = criarPelaRota();
confere($r->status === 409 && $r->dados['errors'] === ['Loja não encontrada no Entregas. Fale com a central.'], 'loja apagada: 409');

echo '== Conexão' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA(false);
$r = controlador()->conexao(pedido(['sistema' => 'RestaurantePro', 'webhook_url' => 'http://inseguro.com.br/x']));
confere($r->status === 422 && str_contains($r->dados['errors'][0], 'webhook_url recusada'), 'URL http: 422');
$r = controlador()->conexao(pedido(['sistema' => '', 'webhook_url' => 'https://api.restaurantepro.com.br/x']));
confere($r->status === 422 && str_contains($r->dados['errors'][0], 'sistema'), 'sem sistema: 422');
$r = controlador()->conexao(pedido(['sistema' => 'RestaurantePro', 'webhook_url' => 'https://200.150.10.20/functions/v1/entregas-webhook?t=abc']));
confere($r->status === 200 && $r->dados['loja'] === ['nome' => 'Pizzaria Ficticia'] && preg_match('/^[0-9a-f]{64}$/', $r->dados['webhook_segredo']) === 1, 'conectada: nome da loja e o segredo');
$loja = ChavesDeIntegracao::ativa('empresa-1', 'vendor-a');
confere($loja->webhook_url === 'https://200.150.10.20/functions/v1/entregas-webhook?t=abc' && ChavesDeIntegracao::segredo($loja) === $r->dados['webhook_segredo'] && $loja->ultimo_uso_em === '2026-10-05 15:00:00', 'URL, segredo e uso gravados');

echo '== Criar' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
$r = criarPelaRota(['cobranca' => ['forma' => 'cheque'], 'numero' => null]);
confere($r->status === 422 && count($r->dados['errors']) === 2 && Order::$criados === [], 'corpo inválido: 422 com todos os erros, nada criado');
$r = criarPelaRota();
confere($r->status === 201 && $r->dados['id'] === 'order_1' && $r->dados['pedido_externo_id'] === 'ord-6f1c' && $r->dados['status'] === 'dispatched', '201 com o pedido');
confere(array_key_exists('codigo_rastreio', $r->dados) && $r->dados['motoboy'] === null && $r->dados['cancelamento'] === null && $r->dados['sem_localizacao'] === false, 'campos do recurso');
confere(\Teste\Sessao::$dados['company'] === 'empresa-1', 'empresa da chave na sessão');
$r = criarPelaRota(['numero' => '1111']);
confere($r->status === 200 && $r->dados['id'] === 'order_1' && count(Order::$criados) === 1, 'repetido: 200 com o mesmo pedido');
reiniciarIntegracao();
lojaIntegradaA();
\Fleetbase\FleetOps\Models\Vendor::$todos[0]->place_uuid = null;
$r = criarPelaRota();
confere($r->status === 409 && str_contains($r->dados['errors'][0], 'local de coleta'), 'loja sem coleta: 409');

echo '== Consultar' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
criarPelaRota();
$pedidoCriado = Order::$criados[0];
$pedidoCriado->tracking = 'RP0001';
$r = controlador()->consultar(pedido(), 'ord-6f1c');
confere($r->status === 200 && $r->dados['codigo_rastreio'] === 'RP0001' && $r->dados['link_rastreio'] === 'https://entregas.restaurantepro.com.br/~/track-order?order=RP0001', 'pelo id da origem, com o rastreio');
$pedidoCriado->started        = true;
$pedidoCriado->driverAssigned = new Driver(['uuid' => 'driver-1', 'name' => 'Motoboy Ficticio', 'photo_url' => null]);
confere(controlador()->consultar(pedido(), 'order_1')->dados['motoboy'] === ['nome' => 'Motoboy Ficticio', 'foto_url' => null], 'pelo public_id, com o motoboy depois do aceite');
confere(controlador()->consultar(pedido(), 'ord-outro')->status === 404, 'desconhecido: 404');
\Teste\Banco::inserir('entregas_integracao_pedidos', ['company_uuid' => 'empresa-1', 'vendor_uuid' => 'vendor-b', 'order_uuid' => 'order-b', 'pedido_externo_id' => 'ord-b', 'cobranca_forma' => 'pix'], false);
confere(controlador()->consultar(pedido(), 'ord-b')->status === 404, 'pedido de outra loja: 404');

echo '== Cancelar' . PHP_EOL;
$r = controlador()->cancelar(pedido(), 'ord-6f1c', new CancelamentoPelaOrigem());
confere($r->status === 200 && $r->dados['resultado'] === 'cancelado' && $r->dados['cancelamento'] === ['por' => 'loja', 'pago' => false], 'cancelado, com o recurso atualizado');
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao(['status' => 'completed']);
$r = controlador()->cancelar(pedido(), 'ord-1', new CancelamentoPelaOrigem());
confere($r->status === 409 && $r->dados['resultado'] === 'ja_concluido' && $r->dados['errors'] === ['O pedido já foi concluído.'], 'concluído: 409');
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao();
Trava::$ocupadas['entregas:pedido:order-1'] = true;
$r = controlador()->cancelar(pedido(), 'ord-1', new CancelamentoPelaOrigem());
confere($r->status === 503, 'trava ocupada: 503');
confere(controlador()->cancelar(pedido(), 'ord-x', new CancelamentoPelaOrigem())->status === 404, 'desconhecido: 404');

echo '== Painel do console' . PHP_EOL;
reiniciarIntegracao();
confere(PainelDaIntegracao::daLoja(null) === ['ligada' => true, 'ativa' => false, 'chave_final' => null, 'sistema' => null, 'conectada' => false, 'webhook_host' => null, 'gerada_em' => null, 'conectada_em' => null, 'ultimo_uso_em' => null, 'ultima_falha_aviso' => null, 'ultima_falha_aviso_em' => null], 'sem chave');
$loja = lojaIntegradaA();
$p    = PainelDaIntegracao::daLoja($loja);
confere($p['ativa'] && $p['chave_final'] === 'AAAA' && $p['sistema'] === 'RestaurantePro' && $p['conectada'] && $p['webhook_host'] === 'api.restaurantepro.com.br', 'conectada: final da chave, sistema e só o host do webhook');
confere($p['gerada_em'] === '2026-10-05T08:00:00-03:00' && $p['conectada_em'] === '2026-10-05T09:00:00-03:00', 'datas em ISO -03:00');
confere(!str_contains(json_encode($p), 'segredo') && !str_contains(json_encode($p), '?t=abc'), 'sem segredo nem o token da URL');
confere(PainelDaIntegracao::doPedido(null, null) === ['integracao' => false], 'pedido que não é da integração');
pedidoDaIntegracao(['status' => 'canceled'], ['cancelado_em' => '2026-10-05 14:58:00', 'cancelado_por' => 'loja', 'pago_mesmo_cancelado' => true, 'ultimo_aviso' => 'cancelado']);
$p = PainelDaIntegracao::doPedido(linhaDaIntegracao(), $loja);
confere($p['integracao'] === true && $p['sistema'] === 'RestaurantePro' && $p['pedido_externo_id'] === 'ord-1' && $p['numero'] === '4821', 'ids');
confere($p['cobranca'] === ['forma' => 'dinheiro', 'centavos' => 5890, 'troco_para_centavos' => 10000, 'texto' => 'Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100'], 'cobrança');
confere($p['ultimo_aviso'] === 'cancelado' && $p['cancelado_por'] === 'loja' && $p['pago_mesmo_cancelado'] === true && $p['cancelado_em'] === '2026-10-05T14:58:00-03:00', 'avisos e cancelamento');
confere(!array_key_exists('cliente_telefone', $p) && !str_contains(json_encode($p), '16999990000'), 'sem o telefone do cliente');

resumo();
