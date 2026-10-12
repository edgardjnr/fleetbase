<?php

// Integração: criação e despacho do pedido (CriadorDoPedidoDaIntegracao), a geocodificação de reserva e a leitura da
// linha pela loja (PedidosDaIntegracao::daLoja).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-criador.php

require __DIR__ . '/stubs-integracao.php';

use App\Support\Entregas\Integracao\ChavesDeIntegracao;
use App\Support\Entregas\Integracao\CriadorDoPedidoDaIntegracao;
use App\Support\Entregas\Integracao\ErroDaIntegracao;
use App\Support\Entregas\Integracao\GeocodificadorDaIntegracao;
use App\Support\Entregas\Integracao\PedidoDaIntegracao;
use App\Support\Entregas\Integracao\PedidosDaIntegracao;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Teste\Banco;
use Teste\Http;
use Teste\Trava;

/** A loja A (a mesma linha a cada chamada: inserir de novo repetiria a chave_hash, que é única). */
function lojaA(): object
{
    return ChavesDeIntegracao::ativa('empresa-1', 'vendor-a') ?? lojaIntegradaA();
}

function criar(array $mudancas = []): array
{
    $dados = PedidoDaIntegracao::validar(corpoDoPedido($mudancas))['dados'];

    return (new CriadorDoPedidoDaIntegracao(new GeocodificadorDaIntegracao()))->criar(lojaA(), $dados);
}

echo '== Pedido com coordenadas: cria e despacha' . PHP_EOL;
reiniciarIntegracao();
[$linha, $novo] = criar();
$pedido         = Order::$criados[0] ?? null;
confere($novo === true && count(Order::$criados) === 1, 'um pedido novo');
confere($pedido->customer_uuid === 'vendor-a' && $pedido->customer_type === 'fleet-ops:vendor' && $pedido->company_uuid === 'empresa-1', 'cliente = a loja');
confere($pedido->empresaNaSessao === 'empresa-1' && $pedido->type === 'transport' && $pedido->order_config_uuid === 'config-transport', 'empresa na sessão e tipo transport');
confere($pedido->internal_id === '4821' && $pedido->notes === "RestaurantePro #4821\nSem cebola", 'número no internal_id e nas notas, com as observações');
$destino = Place::$criados[0];
confere($destino->location->getLat() === -21.17 && $destino->location->getLng() === -47.81 && !isset($destino->owner_uuid), 'entrega = Place novo nas coordenadas, sem dono');
confere($destino->name === 'MARIA FICTICIA' && $destino->street1 === 'RUA FICTICIA, 45' && $destino->street2 === 'AP 12' && $destino->city === 'RIBEIRÃO PRETO' && $destino->province === 'SP' && $destino->postal_code === '14090-000', 'endereço em maiúsculas (com acento)');
confere(!isset($destino->phone), 'o telefone do cliente não vai para o Place');
$payload = Payload::$salvos[0];
confere($payload->pickup->uuid === 'place-loja' && $payload->dropoff === $destino && $pedido->payload_uuid === $payload->uuid, 'coleta = Local da loja');
confere($linha->order_uuid === $pedido->uuid && $linha->pedido_externo_id === 'ord-6f1c' && $linha->vendor_uuid === 'vendor-a' && $linha->sistema === 'RestaurantePro', 'linha ligada ao pedido');
confere($linha->cobranca_forma === 'dinheiro' && $linha->cobranca_centavos === 5890 && $linha->troco_para_centavos === 10000 && $linha->cliente_telefone === '16999990000', 'cobrança e telefone na linha');
confere($linha->complemento === 'Ap 12' && $linha->referencia === 'Perto da praça' && $linha->observacoes === 'Sem cebola' && $linha->sem_localizacao === false, 'complemento, referência e observações');
confere($pedido->adhocAoCriar === true && $pedido->chamadas === ['saveQuietly', 'firstDispatchWithActivity'] && $pedido->travadoNoDespacho === [true], 'despachado como pedido aberto, com a trava');
confere($linha->despachado_em === '2026-10-05 15:00:00' && Trava::$ocupadas === [], 'despachado_em marcado; trava solta');
confere(Http::$chamadas === [], 'com coordenadas, não consulta o Google');
confere(logou('[entregas] integração: pedido criado', 'info') && logsSem(['Maria Ficticia', 'MARIA FICTICIA', 'Rua Ficticia', '99999', 'Sem cebola']), 'log sem nome, endereço, telefone nem observações');

echo '== Repetido: devolve o mesmo, sem criar outro' . PHP_EOL;
[$repetida, $novo] = criar(['numero' => '9999']);
confere($novo === false && $repetida->id === $linha->id && count(Order::$criados) === 1, 'mesmo pedido_externo_id: o mesmo pedido');
confere(logou('[entregas] integração: pedido repetido', 'info'), 'log do repetido');

echo '== Sem coordenadas: geocodifica' . PHP_EOL;
reiniciarIntegracao();
Http::responder(200, ['status' => 'OK', 'results' => [['geometry' => ['location' => ['lat' => -21.19, 'lng' => -47.8], 'location_type' => 'ROOFTOP']]]]);
[$linha] = criar(['entrega' => ['latitude' => null, 'longitude' => null]]);
$chamada = Http::$chamadas[0] ?? null;
confere($chamada && $chamada['metodo'] === 'GET' && $chamada['url'] === GeocodificadorDaIntegracao::URL && $chamada['timeout'] === 5, 'uma consulta ao Geocoding, com timeout de 5 s');
confere($chamada['dados']['address'] === 'Rua Ficticia, 45, Jardim Ficticio, Ribeirão Preto, SP, 14090-000, Brasil' && $chamada['dados']['key'] === 'chave-google' && $chamada['dados']['region'] === 'br', 'endereço completo, a chave e a região');
confere(Place::$criados[0]->location->getLat() === -21.19 && $linha->sem_localizacao === false && Order::$criados[0]->dispatched === true, 'usa o ponto do Google e despacha');
confere(logou('[entregas] integração: endereço geocodificado', 'info') && logsSem(['Rua Ficticia', 'chave-google']), 'log sem endereço nem chave');

echo '== Sem localização: nasce sem despacho' . PHP_EOL;
$casos = [
    'Google sem resultado'   => [fn () => Http::responder(200, ['status' => 'ZERO_RESULTS', 'results' => []]), ['entrega' => ['latitude' => null, 'longitude' => null]]],
    'Google fora do ar'      => [fn () => Http::falharConexao(), ['entrega' => ['latitude' => null, 'longitude' => null]]],
    'Google APPROXIMATE'     => [fn () => Http::responder(200, ['status' => 'OK', 'results' => [['geometry' => ['location' => ['lat' => -21.19, 'lng' => -47.8], 'location_type' => 'APPROXIMATE']]]]), ['entrega' => ['latitude' => null, 'longitude' => null]]],
    'Google partial_match'   => [fn () => Http::responder(200, ['status' => 'OK', 'results' => [['partial_match' => true, 'geometry' => ['location' => ['lat' => -21.19, 'lng' => -47.8], 'location_type' => 'ROOFTOP']]]]), ['entrega' => ['latitude' => null, 'longitude' => null]]],
    'longe da loja (50 km+)' => [fn () => Http::responder(200, ['status' => 'ZERO_RESULTS', 'results' => []]), ['entrega' => ['latitude' => -23.55, 'longitude' => -46.63]]],
    'coordenada 0,0'         => [fn () => Http::responder(200, ['status' => 'ZERO_RESULTS', 'results' => []]), ['entrega' => ['latitude' => 0, 'longitude' => 0]]],
];
foreach ($casos as $caso => [$preparar, $mudancas]) {
    reiniciarIntegracao();
    $preparar();
    [$linha] = criar($mudancas);
    $pedido  = Order::$criados[0];
    confere($linha->sem_localizacao === true && $pedido->adhocAoCriar === false && $pedido->chamadas === [] && $linha->despachado_em === null, "{$caso}: sem despacho, adhoc falso");
    confere($pedido->notes === "RestaurantePro #4821 [SEM LOCALIZAÇÃO]\nSem cebola", "{$caso}: marca nas notas");
    confere(abs(Place::$criados[0]->location->getLat() - (-21.1775 + 0.009)) < 0.000001 && Place::$criados[0]->location->getLng() === -47.8103, "{$caso}: entrega ~1 km ao norte da loja");
    confere(logou('pedido sem localização', 'warning'), "{$caso}: aviso no log");
    if (str_starts_with($caso, 'longe') || str_starts_with($caso, 'coordenada')) {
        confere(count(Http::$chamadas) === 1, "{$caso}: tenta o Google uma vez");
    }
}
echo '== Coordenada inválida recebida: reserva do Google' . PHP_EOL;
reiniciarIntegracao();
Http::responder(200, ['status' => 'OK', 'results' => [['geometry' => ['location' => ['lat' => -21.19, 'lng' => -47.8], 'location_type' => 'ROOFTOP']]]]);
[$linha] = criar(['entrega' => ['latitude' => 0, 'longitude' => 0]]);
confere(count(Http::$chamadas) === 1 && $linha->sem_localizacao === false && Place::$criados[0]->location->getLat() === -21.19 && Order::$criados[0]->dispatched === true, '0,0 recebido com Google ROOFTOP perto: usa o ponto do Google e despacha');
reiniciarIntegracao();
Http::responder(200, ['status' => 'OK', 'results' => [['geometry' => ['location' => ['lat' => -21.19, 'lng' => -47.8], 'location_type' => 'APPROXIMATE']]]]);
criar(['entrega' => ['latitude' => null, 'longitude' => null]]);
confere(logou('endereço geocodificado sem precisão', 'warning') && logsSem(['Rua Ficticia', 'chave-google']), 'aviso de imprecisão sem endereço');

reiniciarIntegracao();
unset(\Teste\Config::$valores['services.google_maps.api_key']);
[$linha] = criar(['entrega' => ['latitude' => null, 'longitude' => null]]);
confere(Http::$chamadas === [] && $linha->sem_localizacao === true, 'sem a chave do Google: nem consulta, sem localização');

echo '== Loja sem local de coleta válido' . PHP_EOL;
reiniciarIntegracao();
Vendor::$todos[0]->place_uuid = null;
$erro = excecao(fn () => criar());
confere($erro instanceof ErroDaIntegracao && $erro->status === 409 && Order::$criados === [] && Banco::linhas('entregas_integracao_pedidos') === [], '409, nada criado');
confere(logou('loja sem local de coleta válido', 'error'), 'log de erro');

echo '== Banco falhou na linha: desfaz tudo' . PHP_EOL;
reiniciarIntegracao();
Banco::$falharComo['entregas_integracao_pedidos'] = ['HY000', 2006];
$erro = excecao(fn () => criar());
confere($erro instanceof \Illuminate\Database\QueryException && Order::$criados === [] && Place::$criados === [], 'erro sobe e o Order e o Place são desfeitos');

echo '== Despacho com a trava ocupada: fica para o acompanhamento' . PHP_EOL;
reiniciarIntegracao();
Trava::$ocupadas['entregas:pedido:order-uuid-1'] = true;
[$linha] = criar();
confere($linha->despachado_em === null && Order::$criados[0]->chamadas === [] && logou('trava do pedido ocupada', 'warning'), 'não despacha agora; despachado_em nulo');

echo '== PedidosDaIntegracao::daLoja' . PHP_EOL;
reiniciarIntegracao();
[$linha] = criar();
$loja    = lojaA();
confere(PedidosDaIntegracao::daLoja($loja, 'ord-6f1c')?->id === $linha->id, 'pelo pedido_externo_id');
confere(PedidosDaIntegracao::daLoja($loja, 'order_1')?->id === $linha->id, 'pelo public_id do Order');
$outra = clone $loja;
$outra->vendor_uuid = 'vendor-b';
confere(PedidosDaIntegracao::daLoja($outra, 'ord-6f1c') === null && PedidosDaIntegracao::daLoja($outra, 'order_1') === null, 'a outra loja não acha');
confere(PedidosDaIntegracao::daLoja($loja, '') === null && PedidosDaIntegracao::daLoja($loja, 'nao-existe') === null, 'vazio ou desconhecido: null');

resumo();
