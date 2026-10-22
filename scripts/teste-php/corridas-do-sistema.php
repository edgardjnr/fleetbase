<?php

// Corridas do sistema (relatório do dono, todas as organizações): migrations, registro permanente
// (CorridasFinalizadas), preço por central (PrecosDasCentrais), relatório (RelatorioDasCorridas), observador, comando
// entregas:corridas-conferir, agenda, controller (403 para quem não é o administrador do sistema) e rotas.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/corridas-do-sistema.php

namespace {
    require __DIR__ . '/stubs-financeiro.php';
    date_default_timezone_set('America/Sao_Paulo');

    use App\Console\Commands\Entregas\CorridasConferir;
    use App\Console\Kernel;
    use App\Http\Controllers\Entregas\CorridasDoSistemaController;
    use App\Listeners\Entregas\ObservadorDasCorridasDoSistema;
    use App\Support\Entregas\Sistema\CorridasFinalizadas;
    use App\Support\Entregas\Sistema\PrecosDasCentrais;
    use App\Support\Entregas\Sistema\RelatorioDasCorridas;
    use Fleetbase\FleetOps\Models\Order;
    use Fleetbase\Support\Auth;
    use Illuminate\Console\Scheduling\Schedule;
    use Illuminate\Http\Request;
    use Teste\Banco;
    use Teste\Relogio;

    function preparar(): void
    {
        reiniciarFinanceiro();
        Relogio::$agora = '2026-10-22 10:00:00';
    }

    /** Organizações (central-a, central-b e uma excluída), a loja, o motoboy e o usuário dele. */
    function cadastros(): void
    {
        Banco::inserir('companies', ['uuid' => 'central-a', 'name' => 'Central Norte', 'deleted_at' => null], false);
        Banco::inserir('companies', ['uuid' => 'central-b', 'name' => 'Central Sul', 'deleted_at' => null], false);
        Banco::inserir('companies', ['uuid' => 'central-x', 'name' => 'Central Antiga', 'deleted_at' => '2026-09-01 10:00:00'], false);
        Banco::inserir('vendors', ['uuid' => 'loja-1', 'name' => 'Pizzaria Boa', 'company_uuid' => 'central-a'], false);
        Banco::inserir('drivers', ['uuid' => 'motoboy-1', 'user_uuid' => 'usuario-1', 'company_uuid' => 'central-a'], false);
        Banco::inserir('users', ['uuid' => 'usuario-1', 'name' => 'Carlos Silva'], false);
    }

    /** Uma linha de orders (uuid = 'uuid-' + public_id, salvo se vier); devolve a linha como objeto. */
    function pedido(array $campos): object
    {
        $linha = $campos + ['company_uuid' => 'central-a', 'status' => 'completed', 'internal_id' => null, 'customer_uuid' => null, 'driver_assigned_uuid' => null, 'tracking_number_uuid' => null, 'updated_at' => '2026-10-22 09:00:00', 'deleted_at' => null];
        $linha['uuid'] ??= 'uuid-' . $linha['public_id'];
        Banco::inserir('orders', $linha, false);

        return (object) $linha;
    }

    /** Status COMPLETED no rastreio (tracking_statuses), com a hora em Brasília. */
    function concluidoNoRastreio(string $rastreio, string $quando): void
    {
        Banco::inserir('tracking_statuses', ['tracking_number_uuid' => $rastreio, 'code' => 'COMPLETED', 'company_uuid' => 'central-a', 'created_at' => $quando, 'deleted_at' => null], false);
    }

    /** A linha do registro de um pedido (pelo public_id), ou []. */
    function linhaDoPedido(string $publicId): array
    {
        foreach (linhasDe('entregas_corridas_finalizadas') as $linha) {
            if ($linha['pedido'] === $publicId) {
                return $linha;
            }
        }

        return [];
    }

    /** Os public_id registrados, em ordem alfabética. */
    function pedidosRegistrados(): array
    {
        $pedidos = array_column(linhasDe('entregas_corridas_finalizadas'), 'pedido');
        sort($pedidos);

        return $pedidos;
    }

    /** Uma corrida já registrada (linha pronta), para o relatório. */
    function corrida(string $pedido, string $company, string $finalizadaEm, array $extra = []): void
    {
        Banco::inserir('entregas_corridas_finalizadas', $extra + [
            'order_uuid' => 'uuid-' . $pedido, 'company_uuid' => $company, 'pedido' => $pedido, 'numero' => null,
            'loja_uuid' => null, 'loja_nome' => null, 'motoboy_uuid' => null, 'motoboy_nome' => null,
            'finalizada_em' => $finalizadaEm, 'origem' => 'conclusao', 'created_at' => $finalizadaEm,
        ], false);
    }

    /** Um preço já cadastrado (linha pronta). */
    function preco(string $company, string $valor, string $desde): void
    {
        Banco::inserir('entregas_precos_centrais', ['company_uuid' => $company, 'valor' => $valor, 'vale_a_partir_de' => $desde, 'criado_por' => null, 'created_at' => '2026-10-01 08:00:00'], false);
    }

    /** O usuário da sessão: 'admin' = administrador do sistema; 'user' = qualquer outro (inclusive o Administrator de uma organização). */
    function usuario(string $tipo): object
    {
        return new class($tipo) {
            public string $uuid = 'user-edgard';
            public function __construct(public string $type) {}
            public function isAdmin(): bool { return $this->type === 'admin'; }
            public function isNotAdmin(): bool { return $this->type !== 'admin'; }
        };
    }

    function api(): CorridasDoSistemaController
    {
        return new CorridasDoSistemaController();
    }

    function req(array $dados = []): Request
    {
        return new Request($dados);
    }

    function erroDe($resposta): string
    {
        return (string) ($resposta->dados['errors'][0] ?? '');
    }

    echo '== Migrations' . PHP_EOL;
    preparar();
    \Illuminate\Support\Facades\Schema::$criadas = [];
    $migration = require '/repo/api/database/migrations/2026_10_22_100000_create_entregas_sistema_tables.php';
    $migration->up();
    $colunasDe = fn (string $tabela) => array_values(array_filter(array_map(fn ($c) => $c->tipo === 'id' ? 'id' : ($c->argumentos[0] ?? null), \Illuminate\Support\Facades\Schema::$criadas[$tabela]->colunas ?? []), 'is_string')); // index([...]) tem lista, não nome
    $corridas  = $colunasDe('entregas_corridas_finalizadas');
    confere(array_diff(['id', 'order_uuid', 'company_uuid', 'pedido', 'numero', 'loja_uuid', 'loja_nome', 'motoboy_uuid', 'motoboy_nome', 'finalizada_em', 'origem', 'created_at'], $corridas) === [], 'corridas: as colunas que a classe usa');
    confere(!in_array('updated_at', $corridas, true) && !in_array('deleted_at', $corridas, true), 'corridas: sem updated_at nem deleted_at (a linha nunca muda nem sai)');
    confere(array_diff(['id', 'company_uuid', 'valor', 'vale_a_partir_de', 'criado_por', 'created_at'], $colunasDe('entregas_precos_centrais')) === [], 'preços: as colunas');
    $migration->down();
    confere(!isset(\Illuminate\Support\Facades\Schema::$criadas['entregas_corridas_finalizadas']) && !isset(\Illuminate\Support\Facades\Schema::$criadas['entregas_precos_centrais']), 'o down apaga as duas');
    confere(Banco::unicasDe('entregas_corridas_finalizadas') === ['order_uuid'], 'order_uuid único (o banco em memória leu a migration)');
    Banco::inserir('entregas_precos_centrais', ['company_uuid' => 'central-a', 'valor' => '2.00', 'vale_a_partir_de' => '2026-10-01'], false);
    confere(excecao(fn () => Banco::inserir('entregas_precos_centrais', ['company_uuid' => 'central-a', 'valor' => '3.00', 'vale_a_partir_de' => '2026-10-01'], false)) instanceof \Illuminate\Database\QueryException, 'preços: uma linha por central e data');

    echo '== Registro (CorridasFinalizadas)' . PHP_EOL;
    preparar();
    cadastros();
    $p1 = pedido(['public_id' => 'order_a1', 'internal_id' => '4821', 'customer_uuid' => 'loja-1', 'driver_assigned_uuid' => 'motoboy-1', 'tracking_number_uuid' => 'rastreio-a1', 'updated_at' => '2026-10-22 09:40:00']);
    concluidoNoRastreio('rastreio-a1', '2026-10-22 09:35:00');
    concluidoNoRastreio('rastreio-a1', '2026-10-22 09:31:00');
    confere(CorridasFinalizadas::registrarLote([$p1], CorridasFinalizadas::CONFERENCIA) === 1, 'grava o pedido concluído');
    $linha = linhaDoPedido('order_a1');
    confere(($linha['company_uuid'] ?? null) === 'central-a' && ($linha['order_uuid'] ?? null) === 'uuid-order_a1' && ($linha['numero'] ?? null) === '4821', 'central, pedido e número');
    confere(($linha['loja_uuid'] ?? null) === 'loja-1' && $linha['loja_nome'] === 'Pizzaria Boa' && $linha['motoboy_uuid'] === 'motoboy-1' && $linha['motoboy_nome'] === 'Carlos Silva', 'nomes da loja e do motoboy gravados na hora');
    confere(($linha['finalizada_em'] ?? null) === '2026-10-22 09:31:00' && $linha['origem'] === 'conferencia' && $linha['created_at'] === '2026-10-22 10:00:00', 'finalizada_em = o primeiro COMPLETED do rastreio (Brasília)');
    confere(CorridasFinalizadas::registrarLote([$p1], CorridasFinalizadas::HISTORICO) === 0 && CorridasFinalizadas::registrarPedido($p1, CorridasFinalizadas::CONCLUSAO, '2026-10-22 11:00:00') === false, 'o mesmo pedido de novo: não grava');
    confere(count(linhasDe('entregas_corridas_finalizadas')) === 1 && linhaDoPedido('order_a1')['origem'] === 'conferencia' && linhaDoPedido('order_a1')['finalizada_em'] === '2026-10-22 09:31:00', 'idempotente: fica a primeira linha, sem mudar');

    $semRastreio = pedido(['public_id' => 'order_a2', 'customer_uuid' => 'contato-1', 'updated_at' => '2026-10-22 08:00:00']);
    $motoboySumido = pedido(['public_id' => 'order_a3', 'driver_assigned_uuid' => 'motoboy-apagado', 'updated_at' => '2026-10-22 08:10:00']);
    confere(CorridasFinalizadas::registrarLote([$semRastreio, $motoboySumido, $semRastreio], CorridasFinalizadas::CONFERENCIA) === 2, 'lote com repetido: uma linha por pedido');
    $l2 = linhaDoPedido('order_a2');
    confere(($l2['finalizada_em'] ?? null) === '2026-10-22 08:00:00', 'sem COMPLETED no rastreio: o updated_at do pedido');
    confere($l2['loja_uuid'] === null && $l2['loja_nome'] === null && $l2['motoboy_uuid'] === null && $l2['motoboy_nome'] === null && $l2['numero'] === null, 'cliente que não é loja e sem motoboy: campos vazios');
    $l3 = linhaDoPedido('order_a3');
    confere(($l3['motoboy_uuid'] ?? null) === 'motoboy-apagado' && $l3['motoboy_nome'] === null, 'motoboy que não existe mais: fica o uuid, sem nome');

    $doObservador = pedido(['public_id' => 'order_a4', 'tracking_number_uuid' => 'rastreio-a4']);
    concluidoNoRastreio('rastreio-a4', '2026-10-22 09:00:00');
    confere(CorridasFinalizadas::registrarPedido($doObservador, CorridasFinalizadas::CONCLUSAO, '2026-10-22 10:00:00') === true && linhaDoPedido('order_a4')['finalizada_em'] === '2026-10-22 10:00:00' && linhaDoPedido('order_a4')['origem'] === 'conclusao', 'a hora passada pelo observador vale');

    $comCarbon = (object) ['uuid' => 'uuid-order_a5', 'company_uuid' => 'central-a', 'public_id' => 'order_a5', 'updated_at' => \Illuminate\Support\Carbon::parse('2026-10-22T12:00:00Z')];
    CorridasFinalizadas::registrarLote([$comCarbon], CorridasFinalizadas::CONFERENCIA);
    confere((linhaDoPedido('order_a5')['finalizada_em'] ?? null) === '2026-10-22 09:00:00', 'updated_at em Carbon UTC: gravado na hora de Brasília');
    confere(CorridasFinalizadas::registrarLote([(object) ['uuid' => 'sem-empresa', 'public_id' => 'order_x']], CorridasFinalizadas::CONFERENCIA) === 0, 'pedido sem organização: ignorado');
    confere(CorridasFinalizadas::iso('2026-10-22 09:31:00') === '2026-10-22T09:31:00-03:00', 'iso: o instante com o fuso de Brasília');
    $completo = pedido(['public_id' => 'order_a6', 'internal_id' => '77', 'customer_uuid' => 'loja-1', 'driver_assigned_uuid' => 'motoboy-1', 'tracking_number_uuid' => 'rastreio-a6', 'updated_at' => '2026-10-22 09:45:00']);
    concluidoNoRastreio('rastreio-a6', '2026-10-22 09:44:00');
    $soAsColunas = (object) array_intersect_key((array) $completo, array_flip(CorridasFinalizadas::COLUNAS_DO_PEDIDO));
    $l6 = CorridasFinalizadas::registrarLote([$soAsColunas], CorridasFinalizadas::CONFERENCIA) === 1 ? linhaDoPedido('order_a6') : [];
    confere(($l6['numero'] ?? null) === '77' && $l6['loja_nome'] === 'Pizzaria Boa' && $l6['motoboy_nome'] === 'Carlos Silva' && $l6['finalizada_em'] === '2026-10-22 09:44:00', 'só com COLUNAS_DO_PEDIDO (o select das consultas de orders): a linha sai completa');

    echo '== Conferência (CorridasFinalizadas::conferir)' . PHP_EOL;
    preparar();
    cadastros();
    pedido(['public_id' => 'order_ok', 'updated_at' => '2026-10-21 20:00:00']);
    pedido(['public_id' => 'order_done', 'status' => 'done', 'updated_at' => '2026-10-22 09:00:00']);
    pedido(['public_id' => 'order_excluido', 'updated_at' => '2026-10-22 09:10:00', 'deleted_at' => '2026-10-22 09:20:00']);
    pedido(['public_id' => 'order_cancelado_depois', 'status' => 'canceled', 'tracking_number_uuid' => 'rastreio-cd', 'updated_at' => '2026-10-22 09:50:00']);
    concluidoNoRastreio('rastreio-cd', '2026-10-22 09:30:00');
    pedido(['public_id' => 'order_cancelado', 'status' => 'canceled', 'updated_at' => '2026-10-22 09:00:00']);
    pedido(['public_id' => 'order_andamento', 'status' => 'started', 'updated_at' => '2026-10-22 09:00:00']);
    pedido(['public_id' => 'order_antigo', 'updated_at' => '2026-10-10 09:00:00']);
    pedido(['public_id' => 'order_outra_central', 'company_uuid' => 'central-b', 'updated_at' => '2026-10-22 09:00:00']);
    pedido(['public_id' => 'order_ja_tem', 'updated_at' => '2026-10-22 09:00:00']);
    corrida('order_ja_tem', 'central-a', '2026-10-22 08:59:00');
    $n = CorridasFinalizadas::conferir('2026-10-20 10:00:00');
    confere($n === 5, "grava as 5 que faltavam ({$n})");
    confere(pedidosRegistrados() === ['order_cancelado_depois', 'order_done', 'order_excluido', 'order_ja_tem', 'order_ok', 'order_outra_central'], 'concluído, done, excluído, cancelado depois do COMPLETED e de outra central entram; cancelado sem conclusão, em andamento e antigo não');
    confere(linhaDoPedido('order_cancelado_depois')['finalizada_em'] === '2026-10-22 09:30:00' && linhaDoPedido('order_cancelado_depois')['origem'] === 'conferencia', 'o cancelado depois de finalizado: pela hora do COMPLETED');
    confere(linhaDoPedido('order_ja_tem')['origem'] === 'conclusao', 'o que já tinha linha fica como estava');
    confere(CorridasFinalizadas::conferir('2026-10-20 10:00:00') === 0, 'de novo: nada');
    pedido(['public_id' => 'order_excluido_inteiro', 'status' => 'canceled', 'tracking_number_uuid' => 'rastreio-ei', 'updated_at' => '2026-10-22 09:55:00', 'deleted_at' => '2026-10-22 09:56:00']);
    Banco::inserir('tracking_statuses', ['tracking_number_uuid' => 'rastreio-ei', 'code' => 'COMPLETED', 'company_uuid' => 'central-a', 'created_at' => '2026-10-22 09:40:00', 'deleted_at' => '2026-10-22 09:56:00'], false);
    confere(CorridasFinalizadas::conferir('2026-10-20 10:00:00') === 1 && linhaDoPedido('order_excluido_inteiro')['finalizada_em'] === '2026-10-22 09:40:00', 'pedido excluído com o COMPLETED do rastreio também excluído: conta, pela hora do COMPLETED');

    echo '== Histórico (CorridasFinalizadas::importarHistorico)' . PHP_EOL;
    preparar();
    cadastros();
    foreach ([1, 2, 3, 4, 5] as $i) {
        pedido(['public_id' => "order_h{$i}", 'updated_at' => "2025-12-0{$i} 12:00:00"]);
    }
    pedido(['public_id' => 'order_h_cancelado', 'status' => 'canceled', 'tracking_number_uuid' => 'rastreio-h', 'updated_at' => '2026-01-01 12:00:00']);
    concluidoNoRastreio('rastreio-h', '2025-12-31 23:50:00');
    pedido(['public_id' => 'order_h_aberto', 'status' => 'created', 'updated_at' => '2026-01-01 12:00:00']);
    confere(CorridasFinalizadas::vazia(), 'tabela vazia antes');
    confere(CorridasFinalizadas::importarHistorico(2) === 6, 'importa tudo, em lotes de 2, sem limite de data');
    confere(array_values(array_unique(array_column(linhasDe('entregas_corridas_finalizadas'), 'origem'))) === ['historico'] && linhaDoPedido('order_h_cancelado')['finalizada_em'] === '2025-12-31 23:50:00' && linhaDoPedido('order_h_aberto') === [], 'origem historico; o cancelado depois de finalizado entra; o aberto não');
    confere(CorridasFinalizadas::importarHistorico(2) === 0 && count(linhasDe('entregas_corridas_finalizadas')) === 6 && !CorridasFinalizadas::vazia(), 'de novo: nada (idempotente)');

    echo '== Histórico: vários lotes de COMPLETED no rastreio' . PHP_EOL;
    preparar();
    cadastros();
    foreach ([1, 2, 3, 4, 5] as $i) {
        pedido(['public_id' => "order_r{$i}", 'status' => 'canceled', 'tracking_number_uuid' => "rastreio-r{$i}", 'updated_at' => '2026-01-10 12:00:00']);
        concluidoNoRastreio("rastreio-r{$i}", "2026-01-0{$i} 20:00:00");
    }
    concluidoNoRastreio('rastreio-r5', '2026-01-09 20:00:00');
    confere(CorridasFinalizadas::importarHistorico(2) === 5, 'só pelo rastreio: 6 COMPLETED em lotes de 2 (2 + 2 + 2 + vazio), os 5 pedidos');
    confere(pedidosRegistrados() === ['order_r1', 'order_r2', 'order_r3', 'order_r4', 'order_r5'] && linhaDoPedido('order_r1')['finalizada_em'] === '2026-01-01 20:00:00' && linhaDoPedido('order_r5')['finalizada_em'] === '2026-01-05 20:00:00', 'cada um pelo primeiro COMPLETED do seu rastreio');
    confere(CorridasFinalizadas::importarHistorico(2) === 0, 'de novo: nada');

    echo '== Preço por central (PrecosDasCentrais)' . PHP_EOL;
    foreach ([['2,50', 2.5], ['2.5', 2.5], ['0', 0.0], ['1000', 1000.0], ['12,34', 12.34]] as [$digitado, $esperado]) {
        confere(PrecosDasCentrais::valor($digitado) === $esperado, "valor \"{$digitado}\" = {$esperado}");
    }
    foreach (['-1', '1000,01', '1,234', 'abc', '', '1.000,00', '1e2', '1E2', '2,5e1', '1e-2', '0x1A', '.5', '2,', ' 2 5'] as $digitado) {
        confere(PrecosDasCentrais::valor($digitado) === null, "valor \"{$digitado}\": inválido");
    }
    confere(PrecosDasCentrais::valor(null) === null && PrecosDasCentrais::valor(2.5) === 2.5, 'null é inválido; número vale');
    confere(number_format(PrecosDasCentrais::valor('-0'), 2, '.', '') === '0.00', '"-0" vira 0,00 (sem o sinal)');
    confere(PrecosDasCentrais::data('2026-10-01') === '2026-10-01' && PrecosDasCentrais::data('2026-02-30') === null && PrecosDasCentrais::data('01/10/2026') === null && PrecosDasCentrais::data(null) === null, 'data: só Y-m-d que existe');
    confere(PrecosDasCentrais::data('2020-01-01') === '2020-01-01' && PrecosDasCentrais::data('2100-12-31') === '2100-12-31' && PrecosDasCentrais::data('2019-12-31') === null && PrecosDasCentrais::data('2101-01-01') === null && PrecosDasCentrais::data('0001-01-01') === null, 'data: só anos de 2020 a 2100');

    $vigencia = [['vale' => '2026-10-01', 'valor' => 2.0], ['vale' => '2026-10-15', 'valor' => 2.5]];
    confere(PrecosDasCentrais::precoNoDia($vigencia, '2026-09-30') === null, 'antes do primeiro preço: sem preço');
    confere(PrecosDasCentrais::precoNoDia($vigencia, '2026-10-01') === 2.0 && PrecosDasCentrais::precoNoDia($vigencia, '2026-10-14') === 2.0, 'do primeiro dia até a véspera do seguinte: o primeiro');
    confere(PrecosDasCentrais::precoNoDia($vigencia, '2026-10-15') === 2.5 && PrecosDasCentrais::precoNoDia($vigencia, '2026-12-01') === 2.5, 'do dia da troca em diante: o novo');
    confere(PrecosDasCentrais::precoNoDia([], '2026-10-15') === null, 'central sem preço: null');

    preparar();
    cadastros();
    $r = PrecosDasCentrais::criar(['company' => 'central-a', 'valor' => '2,50', 'vale_a_partir_de' => '2026-10-01'], 'user-edgard', '2026-10-22 10:00:00');
    confere(is_array($r) && $r['company'] === 'central-a' && $r['valor'] === 2.5 && $r['vale_a_partir_de'] === '2026-10-01' && is_int($r['id']), 'cria: {company, id, valor, vale_a_partir_de}');
    $linha = linhasDe('entregas_precos_centrais')[0] ?? [];
    confere(($linha['valor'] ?? null) === '2.50' && $linha['criado_por'] === 'user-edgard' && $linha['created_at'] === '2026-10-22 10:00:00', 'grava o valor com 2 casas, quem criou e a hora de Brasília');
    $r = PrecosDasCentrais::criar(['company' => 'central-a', 'valor' => '3', 'vale_a_partir_de' => '2026-10-01'], null, '2026-10-22 10:00:00');
    confere($r === 'Esta central já tem um preço a partir de 01/10/2026. Apague o antigo para trocar.', 'mesma central e data: erro');
    $semOrganizacao = 'Organização não encontrada.';
    $valorInvalido  = 'O preço por corrida vai de R$ 0,00 a R$ 1.000,00, com até duas casas.';
    $semData        = 'Informe a data a partir da qual o preço vale.';
    $avisos         = [];
    set_error_handler(function ($numero, $mensagem) use (&$avisos) { $avisos[] = $mensagem; return true; });
    foreach ([
        'central excluída'       => [['company' => 'central-x', 'valor' => '2', 'vale_a_partir_de' => '2026-10-01'], $semOrganizacao],
        'central que não existe' => [['company' => 'nao-existe', 'valor' => '2', 'vale_a_partir_de' => '2026-10-01'], $semOrganizacao],
        'sem central'            => [['valor' => '2', 'vale_a_partir_de' => '2026-10-01'], $semOrganizacao],
        'central em array'       => [['company' => ['central-a'], 'valor' => '2', 'vale_a_partir_de' => '2026-10-01'], $semOrganizacao],
        'central em número'      => [['company' => 123, 'valor' => '2', 'vale_a_partir_de' => '2026-10-01'], $semOrganizacao],
        'valor acima de 1000'    => [['company' => 'central-b', 'valor' => '1000,01', 'vale_a_partir_de' => '2026-10-01'], $valorInvalido],
        'valor negativo'         => [['company' => 'central-b', 'valor' => '-2', 'vale_a_partir_de' => '2026-10-01'], $valorInvalido],
        'valor "1e2"'            => [['company' => 'central-b', 'valor' => '1e2', 'vale_a_partir_de' => '2026-10-01'], $valorInvalido],
        'valor em array'         => [['company' => 'central-b', 'valor' => ['2'], 'vale_a_partir_de' => '2026-10-01'], $valorInvalido],
        'sem valor'              => [['company' => 'central-b', 'vale_a_partir_de' => '2026-10-01'], $valorInvalido],
        'data inválida'          => [['company' => 'central-b', 'valor' => '2', 'vale_a_partir_de' => '2026-13-01'], $semData],
        'ano antes de 2020'      => [['company' => 'central-b', 'valor' => '2', 'vale_a_partir_de' => '2019-12-31'], $semData],
        'ano depois de 2100'     => [['company' => 'central-b', 'valor' => '2', 'vale_a_partir_de' => '2101-01-01'], $semData],
        'data em array'          => [['company' => 'central-b', 'valor' => '2', 'vale_a_partir_de' => ['2026-10-01']], $semData],
    ] as $caso => [$dados, $mensagem]) {
        $erro = PrecosDasCentrais::criar($dados, null, '2026-10-22 10:00:00');
        confere($erro === $mensagem, "{$caso}: \"{$mensagem}\" (" . (is_string($erro) ? $erro : 'criou') . ')');
    }
    restore_error_handler();
    confere($avisos === [], 'nos erros, nenhum aviso do PHP (sem "Array to string conversion")' . ($avisos ? ': ' . implode(' | ', $avisos) : ''));
    confere(count(linhasDe('entregas_precos_centrais')) === 1, 'nos erros, nada gravado');

    PrecosDasCentrais::criar(['company' => 'central-a', 'valor' => '3', 'vale_a_partir_de' => '2026-10-15'], null, '2026-10-22 10:00:00');
    preco('central-x', '1.00', '2026-08-01');
    $lista = PrecosDasCentrais::lista();
    confere(array_column($lista, 'nome') === ['Central Antiga', 'Central Norte', 'Central Sul'], 'lista: as organizações ativas e a excluída que tem preço, por nome');
    confere(array_column($lista[1]['precos'], 'vale_a_partir_de') === ['2026-10-15', '2026-10-01'] && $lista[1]['precos'][0]['valor'] === 3.0 && $lista[2]['precos'] === [], 'preços do mais recente para o mais antigo; central sem preço com a lista vazia');
    $id = $lista[1]['precos'][0]['id'];
    confere(PrecosDasCentrais::apagar($id) === true && PrecosDasCentrais::apagar($id) === false && PrecosDasCentrais::apagar(0) === false, 'apagar: true uma vez; de novo (e id 0): false');
    confere(PrecosDasCentrais::nomes(['central-a', '9f3b2c1d-0000-4000-8000-000000000000']) === ['central-a' => 'Central Norte', '9f3b2c1d-0000-4000-8000-000000000000' => '9f3b2c1d'], 'nome da central; a que não existe mais vira o uuid curto');
    confere(PrecosDasCentrais::porCentral(['central-a']) === ['central-a' => [['vale' => '2026-10-01', 'valor' => 2.5]]], 'porCentral: vigência em ordem de data');

    // dois cadastros iguais ao mesmo tempo: o outro grava entre o exists() e o insert; o índice único barra (23000)
    Banco::$antesDeInserir['entregas_precos_centrais'] = fn () => preco('central-b', '9.00', '2026-11-01');
    $r = PrecosDasCentrais::criar(['company' => 'central-b', 'valor' => '2', 'vale_a_partir_de' => '2026-11-01'], null, '2026-10-22 10:00:00');
    confere($r === 'Esta central já tem um preço a partir de 01/11/2026. Apague o antigo para trocar.', 'corrida entre o exists e o insert (23000): a mesma mensagem da data repetida');
    confere(PrecosDasCentrais::porCentral(['central-b']) === ['central-b' => [['vale' => '2026-11-01', 'valor' => 9.0]]], 'fica só o que gravou primeiro');
    Banco::$falharComo['entregas_precos_centrais'] = ['HY000', 2006];
    $falha = excecao(fn () => PrecosDasCentrais::criar(['company' => 'central-b', 'valor' => '2', 'vale_a_partir_de' => '2026-11-02'], null, '2026-10-22 10:00:00'));
    confere($falha instanceof \Illuminate\Database\QueryException && $falha->getCode() === 'HY000', 'outro erro do banco: sobe (não vira mensagem)');

    preparar();
    Banco::inserir('companies', ['uuid' => 'c-zeta', 'name' => 'Zeta', 'deleted_at' => null], false);
    Banco::inserir('companies', ['uuid' => 'c-area', 'name' => 'Área Sul', 'deleted_at' => null], false);
    Banco::inserir('companies', ['uuid' => 'c-avenida', 'name' => 'avenida', 'deleted_at' => null], false);
    Banco::inserir('companies', ['uuid' => 'c-bairro', 'name' => 'Bairro', 'deleted_at' => null], false);
    confere(array_column(PrecosDasCentrais::lista(), 'nome') === ['Área Sul', 'avenida', 'Bairro', 'Zeta'], 'lista por nome sem olhar acento nem caixa ("Área Sul" antes de "Zeta")');
    confere(PrecosDasCentrais::compararNomes('Área Sul', 'Zeta') < 0 && PrecosDasCentrais::compararNomes('central', 'Central') !== 0, 'compararNomes: acento e caixa não pesam; empate pelo texto');

    echo '== Relatório (RelatorioDasCorridas)' . PHP_EOL;
    $precos = ['central-a' => [['vale' => '2026-10-01', 'valor' => 2.0], ['vale' => '2026-10-15', 'valor' => 2.5]], 'central-b' => [['vale' => '2026-10-10', 'valor' => 3.0]]];
    $nomes  = ['central-a' => 'Central Norte', 'central-b' => 'Central Sul'];
    $linhaDoRelatorio = fn (string $pedido, string $company, string $quando) => (object) ['id' => 0, 'company_uuid' => $company, 'pedido' => $pedido, 'numero' => null, 'loja_nome' => null, 'motoboy_nome' => null, 'finalizada_em' => $quando, 'origem' => 'conclusao'];
    $doPeriodo = [
        $linhaDoRelatorio('a1', 'central-a', '2026-10-05 12:00:00'),
        $linhaDoRelatorio('b1', 'central-b', '2026-10-09 23:59:59'),
        $linhaDoRelatorio('b2', 'central-b', '2026-10-10 00:00:00'),
        $linhaDoRelatorio('a2', 'central-a', '2026-10-15 00:00:00'),
        $linhaDoRelatorio('a3', 'central-a', '2026-10-20 18:00:00'),
    ];
    $r = RelatorioDasCorridas::agregar($doPeriodo, $precos, $nomes);
    confere($r['total'] === ['corridas' => 5, 'a_cobrar' => 10.0, 'sem_preco' => 1], 'total: 5 corridas, R$ 10,00, 1 sem preço');
    confere(array_column($r['centrais'], 'nome') === ['Central Norte', 'Central Sul'], 'centrais por nome');
    [$norte, $sul] = $r['centrais'];
    confere($norte['company'] === 'central-a' && $norte['corridas'] === 3 && $norte['a_cobrar'] === 7.0 && $norte['sem_preco'] === 0 && $norte['precos'] === [2.0, 2.5], 'troca de preço no meio do período: dois preços (o console mostra "variado"); o dia da troca já vale o novo');
    confere($sul['corridas'] === 2 && $sul['a_cobrar'] === 3.0 && $sul['sem_preco'] === 1 && $sul['precos'] === [3.0], 'antes do primeiro preço: sem preço (R$ 0,00) e contada');
    $porPedido = array_column($r['corridas'], null, 'pedido');
    confere($porPedido['b1']['preco'] === null && $porPedido['a2']['preco'] === 2.5 && $porPedido['a1']['finalizada_em'] === '2026-10-05T12:00:00-03:00' && $porPedido['a1']['central'] === 'Central Norte' && $porPedido['a1']['company'] === 'central-a' && $porPedido['a1']['origem'] === 'conclusao', 'cada corrida: preço do dia, ISO com -03:00, central e origem');
    confere($r['lista_cortada'] === false && count($r['corridas']) === 5, 'lista inteira');
    $cortado = RelatorioDasCorridas::agregar($doPeriodo, $precos, $nomes, 2);
    confere(count($cortado['corridas']) === 2 && $cortado['lista_cortada'] === true && $cortado['total']['corridas'] === 5 && $cortado['total']['a_cobrar'] === 10.0, 'acima do limite: lista cortada, totais completos');
    confere(array_keys((array) $doPeriodo[0]) === RelatorioDasCorridas::COLUNAS, 'COLUNAS: as colunas que o agregar lê');
    $gerador = (function () use ($doPeriodo) { yield from $doPeriodo; })();
    confere(RelatorioDasCorridas::agregar($gerador, $precos, $nomes)['total'] === $r['total'], 'agregar aceita um iterável (o cursor do banco)');

    $centavos = ['c-dez' => [['vale' => '2026-10-01', 'valor' => 0.1]], 'c-sete' => [['vale' => '2026-10-01', 'valor' => 0.07]]];
    $linhas   = array_map(fn ($i) => $linhaDoRelatorio("d{$i}", 'c-dez', '2026-10-02 10:00:00'), range(1, 3));
    foreach (range(1, 100) as $i) {
        $linhas[] = $linhaDoRelatorio("s{$i}", 'c-sete', '2026-10-02 11:00:00');
    }
    $somas = RelatorioDasCorridas::agregar($linhas, $centavos, ['c-dez' => 'Dez', 'c-sete' => 'Sete']);
    $porNome = array_column($somas['centrais'], null, 'nome');
    confere($porNome['Dez']['a_cobrar'] === 0.3 && $porNome['Sete']['a_cobrar'] === 7.0 && $somas['total']['a_cobrar'] === 7.3, 'soma em centavos: 0,10 × 3 = 0,30; 0,07 × 100 = 7,00; total 7,30');

    $acentos = RelatorioDasCorridas::agregar([$linhaDoRelatorio('z1', 'c-zeta', '2026-10-02 10:00:00'), $linhaDoRelatorio('a1', 'c-area', '2026-10-02 11:00:00')], [], ['c-zeta' => 'Zeta', 'c-area' => 'Área Sul']);
    confere(array_column($acentos['centrais'], 'nome') === ['Área Sul', 'Zeta'] && $acentos['total']['sem_preco'] === 2 && $acentos['total']['a_cobrar'] === 0.0, 'centrais por nome sem olhar acento; sem preço: R$ 0,00 e contadas');

    preparar();
    cadastros();
    corrida('order_set_30', 'central-a', '2026-09-30 23:59:59');
    corrida('order_out_01', 'central-a', '2026-10-01 00:00:00');
    corrida('order_out_31', 'central-b', '2026-10-31 23:59:59');
    corrida('order_nov_01', 'central-a', '2026-11-01 00:00:00');
    corrida('order_sumida', '9f3b2c1d-0000-4000-8000-000000000000', '2026-10-10 10:00:00');
    preco('central-a', '2.00', '2026-10-01');
    $r = RelatorioDasCorridas::montar('2026-10-01', '2026-10-31');
    confere(is_array($r) && $r['inicio'] === '2026-10-01' && $r['fim'] === '2026-10-31' && array_column($r['corridas'], 'pedido') === ['order_out_01', 'order_sumida', 'order_out_31'], 'o período inteiro em Brasília (00:00:00 a 23:59:59), em ordem de finalização');
    confere(is_array($r) && in_array('9f3b2c1d', array_column($r['centrais'], 'nome'), true) && $r['total']['a_cobrar'] === 2.0, 'central que não existe mais: o uuid curto; só a Norte tem preço');
    $porCentral = is_array($r) ? array_column($r['centrais'], null, 'company') : [];
    confere(is_array($r) && $r['total'] === ['corridas' => 3, 'a_cobrar' => 2.0, 'sem_preco' => 2] && $porCentral['central-b']['sem_preco'] === 1 && $porCentral['central-a']['sem_preco'] === 0 && $r['lista_cortada'] === false, 'montar: sem preço contado por central e no total; lista inteira');
    $r = RelatorioDasCorridas::montar('2026-10-01', '2026-10-31', 2);
    confere(is_array($r) && array_column($r['corridas'], 'pedido') === ['order_out_01', 'order_sumida'] && $r['lista_cortada'] === true && $r['total'] === ['corridas' => 3, 'a_cobrar' => 2.0, 'sem_preco' => 2], 'montar com limite 2: lista cortada, totais de todas');
    corrida('order_mesma_hora', 'central-a', '2026-10-01 00:00:00');
    $r = RelatorioDasCorridas::montar('2026-10-01', '2026-10-01');
    confere(is_array($r) && array_column($r['corridas'], 'pedido') === ['order_out_01', 'order_mesma_hora'], 'mesma hora: pela ordem de gravação (id)');
    foreach ([
        'data inválida'       => [['2026-10-01', '31/10/2026'], 'Informe o início e o fim do período.'],
        'ano antes de 2020'   => [['2019-12-31', '2020-01-05'], 'Informe o início e o fim do período.'],
        'fim antes do início' => [['2026-10-31', '2026-10-01'], 'O fim do período precisa ser igual ou depois do início.'],
        '367 dias'            => [['2025-01-01', '2026-01-02'], 'O período pode ter até 366 dias.'],
        'sem datas'           => [[null, null], 'Informe o início e o fim do período.'],
    ] as $caso => [[$de, $ate], $mensagem]) {
        confere(RelatorioDasCorridas::montar($de, $ate) === $mensagem, "{$caso}: \"{$mensagem}\"");
    }
    confere(is_array(RelatorioDasCorridas::montar('2024-01-01', '2024-12-31')), '366 dias (ano bissexto inteiro): pode');

    echo '== Observador (Order::updated)' . PHP_EOL;
    preparar();
    cadastros();
    $concluido = function (string $publicId, string $status, array $alterados = ['status']) {
        $pedido            = new Order(['uuid' => 'uuid-' . $publicId, 'public_id' => $publicId, 'company_uuid' => 'central-a', 'status' => $status, 'internal_id' => '77', 'customer_uuid' => 'loja-1', 'driver_assigned_uuid' => 'motoboy-1', 'tracking_number_uuid' => null]);
        $pedido->alterados = $alterados;

        return $pedido;
    };
    Relogio::$agora = '2026-10-22 10:07:00';
    ObservadorDasCorridasDoSistema::aoAtualizar($concluido('order_obs', 'completed'));
    $l = linhaDoPedido('order_obs');
    confere(($l['origem'] ?? null) === 'conclusao' && $l['finalizada_em'] === '2026-10-22 10:07:00' && $l['motoboy_nome'] === 'Carlos Silva' && $l['loja_nome'] === 'Pizzaria Boa', 'concluído: grava com a hora de agora (Brasília) e origem conclusao');
    ObservadorDasCorridasDoSistema::aoAtualizar($concluido('order_obs_done', 'DONE'));
    confere(linhaDoPedido('order_obs_done') !== [], 'done (com maiúsculas, da API v1): grava');
    ObservadorDasCorridasDoSistema::aoAtualizar($concluido('order_obs_andamento', 'enroute'));
    ObservadorDasCorridasDoSistema::aoAtualizar($concluido('order_obs_cancelado', 'canceled'));
    ObservadorDasCorridasDoSistema::aoAtualizar($concluido('order_obs_outro_campo', 'completed', ['meta']));
    confere(linhaDoPedido('order_obs_andamento') === [] && linhaDoPedido('order_obs_cancelado') === [] && linhaDoPedido('order_obs_outro_campo') === [], 'outro status, cancelado ou status que não mudou: nada');
    ObservadorDasCorridasDoSistema::aoAtualizar($concluido('order_obs', 'completed'));
    confere(count(array_filter(linhasDe('entregas_corridas_finalizadas'), fn ($linha) => $linha['pedido'] === 'order_obs')) === 1, 'concluído de novo (reaberto): continua uma linha só');

    pedido(['public_id' => 'order_obs_relido', 'internal_id' => '88', 'customer_uuid' => 'loja-1', 'driver_assigned_uuid' => 'motoboy-1', 'tracking_number_uuid' => 'rastreio-relido', 'updated_at' => '2026-10-22 10:07:00']);
    concluidoNoRastreio('rastreio-relido', '2026-10-22 09:01:00');
    $parcial            = new Order(['uuid' => 'uuid-order_obs_relido', 'status' => 'completed']); // carregado com select parcial
    $parcial->alterados = ['status'];
    ObservadorDasCorridasDoSistema::aoAtualizar($parcial);
    $l = linhaDoPedido('order_obs_relido');
    confere(($l['company_uuid'] ?? null) === 'central-a' && $l['numero'] === '88' && $l['loja_nome'] === 'Pizzaria Boa' && $l['motoboy_nome'] === 'Carlos Silva' && $l['finalizada_em'] === '2026-10-22 10:07:00', 'model só com uuid e status: relê o pedido do banco e grava completo, com a hora de agora');

    Banco::$falhar['entregas_corridas_finalizadas'] = 'banco fora do ar';
    confere(excecao(fn () => ObservadorDasCorridasDoSistema::aoAtualizar($concluido('order_obs_falha', 'completed'))) === null && logou('[entregas] sistema: falha ao registrar a corrida finalizada', 'warning'), 'banco fora do ar: não lança (a conclusão segue), só avisa no log');
    confere(logsSem(['Carlos Silva', 'Pizzaria Boa']), 'o log não leva nomes');
    unset(Banco::$falhar['entregas_corridas_finalizadas']);
    Banco::$falharAoConsultar['orders'] = new \RuntimeException('banco fora do ar');
    confere(excecao(fn () => ObservadorDasCorridasDoSistema::aoAtualizar($concluido('order_obs_sem_leitura', 'completed'))) === null, 'falha ao reler o pedido: não lança');
    unset(Banco::$falharAoConsultar['orders']);

    $provedor = file_get_contents('/repo/api/app/Providers/AppServiceProvider.php');
    $boot     = substr($provedor, (int) strpos($provedor, 'public function boot()'));
    confere(str_contains($provedor, 'use App\Listeners\Entregas\ObservadorDasCorridasDoSistema;') && str_contains($provedor, 'Order::updated(fn ($pedido) => ObservadorDasCorridasDoSistema::aoAtualizar($pedido));'), 'AppServiceProvider: Order::updated → observador das corridas do sistema');
    confere(str_contains(substr($boot, 0, (int) strpos($boot, '}')), '$this->registrarCorridasDoSistema();'), 'ligado no boot()');


    resumo();
}
