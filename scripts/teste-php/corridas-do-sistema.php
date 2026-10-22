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

    resumo();
}
