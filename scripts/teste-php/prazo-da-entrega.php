<?php

// Prazo de entrega (cronômetro): a regra do prazo (PrazoDaEntrega), o hook Order::creating (GravarPrazoDoPedido) e a
// ligação no AppServiceProvider. Spec: docs/superpowers/specs/2026-10-20-cronometro-do-prazo-design.md.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/prazo-da-entrega.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/fixtures-ifood.php';

use App\Support\Entregas\PrazoDaEntrega;
use App\Listeners\Entregas\GravarPrazoDoPedido;
use Illuminate\Support\Carbon;

$brasilia = new DateTimeZone('America/Sao_Paulo');
$quinze   = new DateTimeImmutable('2026-10-05 15:00:00', $brasilia);

echo '== Padrão (RestaurantePro, portal, central, API): 50 min depois do max(criação, agendado)' . PHP_EOL;
confere(PrazoDaEntrega::padrao($quinze) === '2026-10-05T15:50:00-03:00', 'sem agendamento: criação + 50 min, no fuso de Brasília');
confere(PrazoDaEntrega::padrao($quinze, new DateTimeImmutable('2026-10-05 16:20:00', $brasilia)) === '2026-10-05T17:10:00-03:00', 'agendado depois da criação: conta do horário agendado');
confere(PrazoDaEntrega::padrao($quinze, new DateTimeImmutable('2026-10-05 14:00:00', $brasilia)) === '2026-10-05T15:50:00-03:00', 'agendado no passado: conta da criação');
confere(PrazoDaEntrega::padrao(new DateTimeImmutable('2026-10-05T18:00:00Z')) === '2026-10-05T15:50:00-03:00', 'criação em UTC sai em -03:00 (nunca UTC)');
$mutavel = Carbon::parse('2026-10-05 15:00:00');
PrazoDaEntrega::padrao($mutavel);
confere($mutavel->format('H:i') === '15:00', 'não altera o Carbon (mutável) de quem chamou');

echo '== Formato gravado: Y-m-d\TH:i:sP, sem microssegundos (o Hermes do app lê sem surpresa)' . PHP_EOL;
const FORMATO_GRAVADO = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/';
$comMicro = new DateTimeImmutable('2026-10-05 15:00:00.987654', $brasilia);
confere(PrazoDaEntrega::padrao($comMicro) === '2026-10-05T15:50:00-03:00', 'criação com microssegundos: eles não saem');
confere(preg_match(FORMATO_GRAVADO, PrazoDaEntrega::padrao($comMicro)) === 1, 'padrão no formato exato');
confere(PrazoDaEntrega::formatar(new DateTimeImmutable('2026-10-20T22:57:51.123456Z')) === '2026-10-20T19:57:51-03:00', 'formatar: UTC com fração vira -03:00 sem fração (o exemplo da spec)');
$comFracao                                 = pedidoEmDinheiroComTroco();
$comFracao['delivery']['deliveryDateTime'] = '2026-10-05T18:40:00.500Z';
confere(PrazoDaEntrega::doIfood($comFracao) === '2026-10-05T15:40:00-03:00', 'iFood com fração de segundo: sai sem a fração');
confere(preg_match(FORMATO_GRAVADO, PrazoDaEntrega::doIfood(pedidoAgendado())) === 1, 'iFood agendado no formato exato');

echo '== iFood' . PHP_EOL;
confere(PrazoDaEntrega::doIfood(pedidoEmDinheiroComTroco()) === '2026-10-05T15:40:00-03:00', 'imediato: o deliveryDateTime (18:40Z = 15:40 em Brasília)');
confere(PrazoDaEntrega::doIfood(pedidoDeTestePagoOnline()) === '2026-10-05T15:45:00-03:00', 'pedido de teste: o deliveryDateTime dele');
confere(PrazoDaEntrega::doIfood(pedidoAgendado()) === '2026-10-05T17:30:00-03:00', 'agendado: o fim da janela (20:30Z)');
$semFim = pedidoAgendado('2026-10-05T21:00:00.000Z');
unset($semFim['schedule']['deliveryDateTimeEnd']);
confere(PrazoDaEntrega::doIfood($semFim) === '2026-10-05T18:30:00-03:00', 'agendado sem o fim: início da janela + 30 min');
$fimIlegivel                                   = pedidoAgendado('2026-10-05T21:00:00.000Z');
$fimIlegivel['schedule']['deliveryDateTimeEnd'] = 'amanhã';
confere(PrazoDaEntrega::doIfood($fimIlegivel) === '2026-10-05T18:30:00-03:00', 'fim ilegível: vale como sem o fim');
$semJanela = pedidoAgendado();
unset($semJanela['schedule']);
confere(PrazoDaEntrega::doIfood($semJanela) === null, 'agendado sem janela: null (vale o padrão), mesmo com deliveryDateTime');
$comOffset                                 = pedidoEmDinheiroComTroco();
$comOffset['delivery']['deliveryDateTime'] = '2026-10-05T18:40:00+00:00';
confere(PrazoDaEntrega::doIfood($comOffset) === '2026-10-05T15:40:00-03:00', 'data com +00:00 também sai em -03:00');
foreach (['ausente' => null, 'texto' => 'tomorrow', 'só a data' => '2026-10-05', 'ano absurdo' => '1999-10-05T18:40:00Z', 'lista' => ['2026-10-05T18:40:00Z']] as $caso => $valor) {
    $pedido                             = pedidoEmDinheiroComTroco();
    $pedido['delivery']['deliveryDateTime'] = $valor;
    confere(PrazoDaEntrega::doIfood($pedido) === null, "imediato com data {$caso}: null (vale o padrão)");
}
confere(PrazoDaEntrega::doIfood([]) === null, 'pedido vazio: null');

echo '== Leitura do meta' . PHP_EOL;
$prazo = '2026-10-05T15:40:00-03:00';
confere(PrazoDaEntrega::daMeta([PrazoDaEntrega::CHAVE => $prazo, 'outra' => 1]) === $prazo, 'array (cast do Eloquent)');
confere(PrazoDaEntrega::daMeta(json_encode([PrazoDaEntrega::CHAVE => $prazo])) === $prazo, 'JSON da coluna (lista do console)');
confere(PrazoDaEntrega::daMeta((object) [PrazoDaEntrega::CHAVE => $prazo]) === $prazo, 'objeto');
confere(PrazoDaEntrega::daMeta([PrazoDaEntrega::CHAVE => ' ' . $prazo . ' ']) === $prazo, 'espaços em volta saem');
confere(PrazoDaEntrega::daMeta([PrazoDaEntrega::CHAVE => '2026-10-05T18:40:00Z']) === '2026-10-05T18:40:00Z', 'ISO com Z também é legível (os clientes convertem)');
foreach ([
    'nulo'               => null,
    'vazio'              => [],
    'texto que não é JSON' => 'meta solto',
    'número'             => 123,
    'prazo ilegível'     => [PrazoDaEntrega::CHAVE => 'amanhã'],
    'só a data'          => [PrazoDaEntrega::CHAVE => '2026-10-05'],
    'sem fuso'           => [PrazoDaEntrega::CHAVE => '2026-10-05 15:40:00'],
    'ano absurdo'        => [PrazoDaEntrega::CHAVE => '1999-10-05T15:40:00-03:00'],
    'prazo não texto'    => [PrazoDaEntrega::CHAVE => ['x']],
] as $caso => $meta) {
    confere(PrazoDaEntrega::daMeta($meta) === null, "meta {$caso}: null");
}

echo '== Prazo do pedido (model ou objeto com meta)' . PHP_EOL;
confere(PrazoDaEntrega::doPedido((object) ['meta' => [PrazoDaEntrega::CHAVE => $prazo]]) === $prazo, 'lê o meta do pedido');
confere(PrazoDaEntrega::doPedido(new stdClass()) === null, 'pedido sem meta (de antes do deploy): null');

class PedidoQueFalhaAoLer
{
    public function __isset($chave) { return true; }
    public function __get($chave) { throw new \RuntimeException('banco: SELECT segredo'); }
}
confere(PrazoDaEntrega::doPedido(new PedidoQueFalhaAoLer()) === null, 'falha ao ler o meta: null, sem lançar');

echo '== Hook Order::creating (GravarPrazoDoPedido)' . PHP_EOL;
reiniciarIfood(); // relógio em 2026-10-05 15:00 (Brasília) e log vazio

#[\AllowDynamicProperties]
class PedidoNovo
{
    public function __construct(public $meta = null, public $scheduled_at = null) {}
}

$pedido = new PedidoNovo();
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta === [PrazoDaEntrega::CHAVE => '2026-10-05T15:50:00-03:00'], 'pedido sem meta: ganha o padrão (agora + 50 min)');
confere(preg_match(FORMATO_GRAVADO, $pedido->meta[PrazoDaEntrega::CHAVE]) === 1, 'gravado em Y-m-d\TH:i:sP, sem microssegundos');

$pedido = new PedidoNovo(['origem' => 'portal']);
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta === ['origem' => 'portal', PrazoDaEntrega::CHAVE => '2026-10-05T15:50:00-03:00'], 'as outras chaves do meta ficam');

$pedido = new PedidoNovo(null, Carbon::parse('2026-10-05 16:20:00'));
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta[PrazoDaEntrega::CHAVE] === '2026-10-05T17:10:00-03:00', 'agendado (Carbon do cast): conta do horário agendado');

$pedido = new PedidoNovo(null, '2026-10-05 16:20:00');
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta[PrazoDaEntrega::CHAVE] === '2026-10-05T17:10:00-03:00', 'agendado em texto (hora de Brasília): conta do horário agendado');

$pedido = new PedidoNovo(null, '2026-10-05 14:00:00');
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta[PrazoDaEntrega::CHAVE] === '2026-10-05T15:50:00-03:00', 'agendado no passado: conta de agora');

$pedido = new PedidoNovo(null, 'qualquer coisa');
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta[PrazoDaEntrega::CHAVE] === '2026-10-05T15:50:00-03:00', 'scheduled_at ilegível: conta de agora');

$pedido = new PedidoNovo([PrazoDaEntrega::CHAVE => '2026-10-05T15:40:00-03:00']);
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta === [PrazoDaEntrega::CHAVE => '2026-10-05T15:40:00-03:00'], 'meta já com o prazo do iFood: não sobrescreve');

$pedido = new PedidoNovo([PrazoDaEntrega::CHAVE => 'amanhã', 'x' => 1]);
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta === [PrazoDaEntrega::CHAVE => '2026-10-05T15:50:00-03:00', 'x' => 1], 'prazo ilegível (cliente da API): troca pelo padrão');

$pedido = new PedidoNovo('');
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta === [PrazoDaEntrega::CHAVE => '2026-10-05T15:50:00-03:00'], 'meta texto vazio: ganha o padrão');

$pedido = new PedidoNovo('meta solto');
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta === 'meta solto', 'meta em texto solto (não JSON): fica como está, sem prazo');

$erro = excecao(fn () => GravarPrazoDoPedido::aoCriar(new PedidoQueFalhaAoLer()));
confere($erro === null, 'falha ao ler o pedido: não lança (roda dentro da criação)');
confere(logou('[entregas] falha ao gravar o prazo do pedido', 'warning') && logsSem(['segredo', 'SELECT']), 'warning curto, só com a classe da exceção');

#[AllowDynamicProperties]
class PedidoComAgendamentoQueFalha
{
    public $meta = null;

    public function __get($nome)
    {
        if ($nome === 'scheduled_at') {
            throw new RuntimeException('segredo SELECT');
        }

        return null;
    }
}

echo '== Hook: meta como objeto ou JSON, formato canônico, agendamento ilegível' . PHP_EOL;
$pedido = new PedidoNovo((object) ['origem' => 'api']);
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta === ['origem' => 'api', PrazoDaEntrega::CHAVE => '2026-10-05T15:50:00-03:00'], 'meta stdClass: vira array, mantém as outras chaves e ganha o padrão');

$pedido = new PedidoNovo('{"origem":"api","n":2}');
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta === ['origem' => 'api', 'n' => 2, PrazoDaEntrega::CHAVE => '2026-10-05T15:50:00-03:00'], 'meta em texto JSON: decodificado, mantém as outras chaves e ganha o padrão');

$pedido = new PedidoNovo(['x' => 1, PrazoDaEntrega::CHAVE => '2026-10-05T18:40:00Z']);
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta === ['x' => 1, PrazoDaEntrega::CHAVE => '2026-10-05T15:40:00-03:00'], 'prazo em Z: regravado no formato canônico (mesmo instante, Brasília)');

$pedido = new PedidoNovo([PrazoDaEntrega::CHAVE => '2026-10-05T18:40:00.123456Z']);
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta[PrazoDaEntrega::CHAVE] === '2026-10-05T15:40:00-03:00', 'prazo com microssegundos: regravado sem fração');

$pedido = new PedidoNovo([PrazoDaEntrega::CHAVE => '2026-10-05T15:40-03:00']);
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta[PrazoDaEntrega::CHAVE] === '2026-10-05T15:40:00-03:00', 'prazo sem segundos: regravado com :00');

$pedido = new PedidoNovo((object) [PrazoDaEntrega::CHAVE => '2026-10-05T18:40:00Z']);
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta === [PrazoDaEntrega::CHAVE => '2026-10-05T15:40:00-03:00'], 'meta objeto com prazo em Z: vira array canônico');

$pedido = new PedidoNovo([PrazoDaEntrega::CHAVE => '2026-10-05T15:40:00-03:00']);
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta === [PrazoDaEntrega::CHAVE => '2026-10-05T15:40:00-03:00'], 'prazo já canônico: intacto');

$pedido = new PedidoComAgendamentoQueFalha();
$erro   = excecao(fn () => GravarPrazoDoPedido::aoCriar($pedido));
confere($erro === null && $pedido->meta === [PrazoDaEntrega::CHAVE => '2026-10-05T15:50:00-03:00'], 'scheduled_at que lança: o pedido ganha o padrão a partir de agora, sem lançar');

echo '== Ligação no AppServiceProvider' . PHP_EOL;
$provider = file_get_contents('/repo/api/app/Providers/AppServiceProvider.php');
confere(str_contains($provider, 'Order::creating(fn ($pedido) => GravarPrazoDoPedido::aoCriar($pedido));'), 'Order::creating → GravarPrazoDoPedido');
confere(str_contains($provider, '$this->gravarPrazoDosPedidos();') && str_contains($provider, 'use App\Listeners\Entregas\GravarPrazoDoPedido;'), 'chamado no boot, com o import');

resumo();
