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

    resumo();
}
