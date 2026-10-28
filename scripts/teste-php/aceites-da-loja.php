<?php

// Aceites recentes dos pedidos da loja para a buzina do portal (AceitesDaLoja) e a ligação da rota loja/aceites
// (controller, rota, limitador e o ProtegerPortalLoja). Spec: docs/superpowers/specs/2026-10-28-troca-com-aceite-e-buzina-design.md.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/aceites-da-loja.php

namespace Teste {
    class Agora extends \DateTimeImmutable
    {
        public function subHours(int $horas): static { return $this->modify("-{$horas} hours"); }
    }

    /** Consulta em memória: where (= e >=), whereIn, whereNull, whereNotNull, with, orderBy e limit. */
    class Consulta
    {
        public array $passos = [];
        private ?array $ordem = null;
        private ?int $limite = null;

        public function __construct(private array $linhas) {}

        public function where($coluna, $operador = null, $valor = null): static
        {
            if (func_num_args() === 2) {
                [$valor, $operador] = [$operador, '='];
            }
            $this->passos[] = "where {$coluna} {$operador}";
            $this->linhas   = array_filter($this->linhas, function ($linha) use ($coluna, $operador, $valor) {
                $atual = $linha->$coluna ?? null;

                return $operador === '>=' ? $atual !== null && (string) $atual >= (string) $valor : $atual === $valor;
            });

            return $this;
        }

        public function whereIn($coluna, array $valores): static { $this->passos[] = "whereIn {$coluna}"; $this->linhas = array_filter($this->linhas, fn ($l) => in_array($l->$coluna ?? null, $valores, true)); return $this; }
        public function whereNull($coluna): static { $this->passos[] = "whereNull {$coluna}"; $this->linhas = array_filter($this->linhas, fn ($l) => ($l->$coluna ?? null) === null); return $this; }
        public function whereNotNull($coluna): static { $this->passos[] = "whereNotNull {$coluna}"; $this->linhas = array_filter($this->linhas, fn ($l) => ($l->$coluna ?? null) !== null); return $this; }
        public function with($relacoes): static { $this->passos[] = 'with ' . implode(',', (array) $relacoes); return $this; }
        public function orderBy($coluna, $direcao = 'asc'): static { $this->passos[] = "orderBy {$coluna} {$direcao}"; $this->ordem = [$coluna, $direcao]; return $this; }
        public function limit(int $n): static { $this->passos[] = "limit {$n}"; $this->limite = $n; return $this; }

        public function get(): array
        {
            $linhas = array_values($this->linhas);
            if ($this->ordem) {
                [$coluna, $direcao] = $this->ordem;
                usort($linhas, fn ($a, $b) => $direcao === 'desc' ? strcmp((string) $b->$coluna, (string) $a->$coluna) : strcmp((string) $a->$coluna, (string) $b->$coluna));
            }

            return $this->limite === null ? $linhas : array_slice($linhas, 0, $this->limite);
        }
    }
}

namespace Fleetbase\FleetOps\Models {
    class Order
    {
        public static array $todos = [];
        public static array $consultas = [];

        public static function where($coluna, $operador = null, $valor = null)
        {
            $consulta            = new \Teste\Consulta(static::$todos);
            static::$consultas[] = $consulta;

            return func_num_args() === 2 ? $consulta->where($coluna, $operador) : $consulta->where($coluna, $operador, $valor);
        }
    }
}

namespace {
    use App\Support\Entregas\AceitesDaLoja;
    use Fleetbase\FleetOps\Models\Order;

    require '/repo/api/app/Support/Entregas/PrazoDaEntrega.php';
    require '/repo/api/app/Support/Entregas/AceitesDaLoja.php';

    date_default_timezone_set('America/Sao_Paulo');
    function now() { return new \Teste\Agora('2026-10-28 20:00:00'); }

    $falhas = 0;
    function confere(bool $ok, string $caso): void
    {
        global $falhas;
        if (!$ok) {
            $falhas++;
        }
        echo ($ok ? 'PASSA ' : 'FALHA ') . $caso . PHP_EOL;
    }

    function pedido(string $id, array $extra = []): object
    {
        return (object) array_merge([
            'public_id' => "order_{$id}", 'company_uuid' => 'empresa-a', 'customer_uuid' => 'vendor-a', 'driver_assigned_uuid' => 'm-1',
            'deleted_at' => null, 'started_at' => '2026-10-28 19:30:00', 'internal_id' => null, 'trackingNumber' => null,
            'driverAssigned' => (object) ['name' => 'Carlos Alberto Souza'],
        ], $extra);
    }

    echo '== AceitesDaLoja::listar' . PHP_EOL;
    Order::$todos = [
        pedido('ifood', ['internal_id' => '4821', 'started_at' => '2026-10-28 19:55:10']),
        pedido('rastreio', ['trackingNumber' => (object) ['tracking_number' => 'RP77'], 'started_at' => '2026-10-28 18:00:00']),
        pedido('velho', ['started_at' => '2026-10-28 17:59:59']),
        pedido('sem-motoboy', ['driver_assigned_uuid' => null]),
        pedido('nao-aceito', ['started_at' => null]),
        pedido('outra-loja', ['customer_uuid' => 'vendor-b']),
        pedido('contato', ['customer_uuid' => 'contato-a', 'started_at' => '2026-10-28 19:40:00', 'driverAssigned' => (object) ['name' => '  Ana  ']]),
        pedido('outra-empresa', ['company_uuid' => 'empresa-b']),
        pedido('apagado', ['deleted_at' => '2026-10-28 19:00:00']),
        pedido('sem-nome', ['started_at' => '2026-10-28 19:00:00', 'driverAssigned' => null]),
    ];
    $aceites = AceitesDaLoja::listar('empresa-a', ['vendor-a', 'contato-a']);
    confere($aceites === [
        ['pedido' => 'order_ifood', 'numero' => '4821', 'aceito_em' => '2026-10-28T19:55:10-03:00', 'motoboy' => 'Carlos'],
        ['pedido' => 'order_contato', 'numero' => 'order_contato', 'aceito_em' => '2026-10-28T19:40:00-03:00', 'motoboy' => 'Ana'],
        ['pedido' => 'order_sem-nome', 'numero' => 'order_sem-nome', 'aceito_em' => '2026-10-28T19:00:00-03:00', 'motoboy' => null],
        ['pedido' => 'order_rastreio', 'numero' => 'RP77', 'aceito_em' => '2026-10-28T18:00:00-03:00', 'motoboy' => 'Carlos'],
    ], 'só os da loja e do contato, com motoboy, aceitos nas últimas 2 h (inclusive), não apagados, o mais recente primeiro (' . json_encode($aceites) . ')');
    confere(in_array('with driverAssigned.user,trackingNumber', end(Order::$consultas)->passos, true) && in_array('limit 50', end(Order::$consultas)->passos, true), 'carrega o motoboy e o rastreio numa consulta só; no máximo 50');
    confere(!str_contains(json_encode($aceites), 'm-1') && !str_contains(json_encode($aceites), 'Souza'), 'nada de id do motoboy nem nome completo');
    confere(AceitesDaLoja::listar('empresa-a', []) === [], 'sem donos: lista vazia (não vira "todos os pedidos")');

    Order::$todos = array_map(fn ($i) => pedido("p{$i}", ['started_at' => sprintf('2026-10-28 19:%02d:00', $i)]), range(0, 59));
    confere(count(AceitesDaLoja::listar('empresa-a', ['vendor-a'])) === 50, 'no máximo 50 linhas');

    echo '== primeiroNome' . PHP_EOL;
    confere(AceitesDaLoja::primeiroNome('Carlos Alberto') === 'Carlos' && AceitesDaLoja::primeiroNome("  Ana\tMaria ") === 'Ana', 'o primeiro nome, sem espaços');
    confere(AceitesDaLoja::primeiroNome(null) === null && AceitesDaLoja::primeiroNome('   ') === null, 'sem nome: null');

    echo '== Ligação da rota' . PHP_EOL;
    $rotas      = file_get_contents('/repo/api/app/Providers/RouteServiceProvider.php');
    $controller = file_get_contents('/repo/api/app/Http/Controllers/Entregas/PortalLojaController.php');
    $protecao   = file_get_contents('/repo/api/app/Http/Middleware/ProtegerPortalLoja.php');
    foreach ([
        "Route::get('loja/aceites', [PortalLojaController::class, 'aceites'])->middleware('throttle:entregas-loja-aceites');",
        "RateLimiter::for('entregas-loja-aceites', fn (Request \$request) => Limit::perMinute(30)->by('entregas-loja-aceites:' . (session('user') ?: \$request->ip())));",
    ] as $trecho) {
        confere(str_contains($rotas, $trecho), "rota/limitador: {$trecho}");
    }
    confere(str_contains($controller, 'use App\Support\Entregas\AceitesDaLoja;') && str_contains($controller, "AceitesDaLoja::listar((string) session('company'), \$this->donosDosPedidos(\$vendor))"),
        'o controller usa a loja da sessão (404 sem loja) e os donos dos pedidos');
    confere(str_contains($protecao, '#^[A-Z]+ entregas/loja/.+$#'), 'o ProtegerPortalLoja já libera entregas/loja/* ao usuário de loja');

    echo PHP_EOL . "FALHAS: {$falhas}" . PHP_EOL;
}
