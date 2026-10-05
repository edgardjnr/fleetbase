<?php

// Pedidos em andamento no mapa (PedidosNoMapa): o alfinete vermelho no endereço de entrega, no mapa do console (daCentral,
// todos os pedidos, com a loja) e no do portal (daLoja, só os da loja, sem a loja e sem nenhum id de motoboy).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/pedidos-no-mapa.php

namespace Illuminate\Support {
    class Carbon extends \DateTimeImmutable
    {
        public function subHours(int $horas): static { return $this->modify("-{$horas} hours"); }
        public function toIso8601String(): string { return $this->format(\DateTimeInterface::ATOM); }
    }
}

namespace Teste {
    class Ponto
    {
        public function __construct(private float $lat, private float $lng) {}
        public function getLat(): float { return $this->lat; }
        public function getLng(): float { return $this->lng; }
    }

    /** Consulta em memória: aplica os filtros que o código usa e registra as chamadas. */
    class Consulta
    {
        public static array $registro = [];
        private ?int $limite = null;

        public function __construct(private string $modelo, private array $linhas) {}

        private function anotar(string $chamada): static
        {
            static::$registro[$this->modelo][] = $chamada;

            return $this;
        }

        public function where($coluna, $operador = null, $valor = null): static
        {
            if (func_num_args() === 2) {
                [$operador, $valor] = ['=', $operador];
            }
            $this->anotar("where {$coluna} {$operador}");
            $this->linhas = array_filter($this->linhas, function ($linha) use ($coluna, $operador, $valor) {
                $atual = $linha->$coluna ?? null;

                return match ($operador) {
                    '='  => $atual === $valor,
                    '>=' => $atual >= $valor,
                };
            });

            return $this;
        }

        public function whereIn($coluna, array $valores): static
        {
            $this->linhas = array_filter($this->linhas, fn ($linha) => in_array($linha->$coluna ?? null, $valores, true));

            return $this->anotar("whereIn {$coluna}");
        }

        public function whereNotIn($coluna, array $valores): static
        {
            // como no SQL: status nulo não passa no NOT IN
            $this->linhas = array_filter($this->linhas, fn ($linha) => ($linha->$coluna ?? null) !== null && !in_array($linha->$coluna, $valores, true));

            return $this->anotar("whereNotIn {$coluna}");
        }

        public function with($relacoes): static { return $this->anotar('with ' . implode(',', (array) $relacoes)); }
        public function anotarSemRelacao($relacoes): static { return $this->anotar('without ' . implode(',', (array) $relacoes)); }
        public function applyDirectivesForPermissions(string $permissao): static { return $this->anotar("permissao {$permissao}"); }

        public function orderBy(string $coluna, string $direcao = 'asc'): static
        {
            $linhas = array_values($this->linhas);
            usort($linhas, fn ($a, $b) => $direcao === 'desc' ? $b->$coluna <=> $a->$coluna : $a->$coluna <=> $b->$coluna);
            $this->linhas = $linhas;

            return $this->anotar("orderBy {$coluna} {$direcao}");
        }

        public function limit(int $limite): static
        {
            $this->limite = $limite;

            return $this->anotar("limit {$limite}");
        }

        public function get(array $colunas = ['*']): array
        {
            $linhas = array_values($this->linhas);
            if ($this->limite !== null) {
                $linhas = array_slice($linhas, 0, $this->limite);
            }
            if ($colunas !== ['*']) {
                $linhas = array_map(fn ($linha) => (object) array_intersect_key((array) $linha, array_flip($colunas)), $linhas);
            }

            return $linhas;
        }
    }

    abstract class Modelo
    {
        public static array $todos = [];

        public static function where($coluna, $operador = null, $valor = null)
        {
            return (new Consulta(static::class, static::$todos))->where(...func_get_args());
        }

        public static function without($relacoes)
        {
            return (new Consulta(static::class, static::$todos))->anotarSemRelacao($relacoes);
        }
    }
}

namespace Fleetbase\FleetOps\Models {
    class Order extends \Teste\Modelo { public static array $todos = []; }
    class Vendor extends \Teste\Modelo { public static array $todos = []; }
}

namespace {
    use App\Support\Entregas\PedidosNoMapa;
    use Fleetbase\FleetOps\Models\Order;
    use Fleetbase\FleetOps\Models\Vendor;
    use Illuminate\Support\Carbon;
    use Teste\Ponto;

    require '/repo/api/app/Support/Entregas/StatusDoPedido.php';
    require '/repo/api/app/Support/Entregas/SituacaoDoMotoboy.php';
    require '/repo/api/app/Support/Entregas/Coordenada.php';
    require '/repo/api/app/Support/Entregas/PedidosNoMapa.php';

    const EMPRESA = 'empresa-a';
    $agora   = new Carbon('2026-10-05 12:00:00');
    $recente = new Carbon('2026-10-05 11:30:00');
    $antigo  = new Carbon('2026-10-04 20:00:00');

    function now() { global $agora; return $agora; }

    function lugar(?float $lat, ?float $lng, ?string $endereco, ?string $nome = null): object
    {
        return (object) ['location' => $lat === null ? null : new Ponto($lat, $lng), 'address' => $endereco, 'name' => $nome];
    }

    function pedido(string $id, ?string $status, string $dono, $atualizado, string $criado, ?object $destino, array $extra = []): object
    {
        return (object) array_merge([
            'company_uuid' => EMPRESA, 'public_id' => $id, 'status' => $status, 'customer_uuid' => $dono, 'updated_at' => $atualizado,
            'created_at' => new Carbon($criado), 'payload' => (object) ['dropoff' => $destino, 'pickup' => null],
            'driverAssigned' => null, 'trackingNumber' => null,
        ], $extra);
    }

    $falhas = 0;
    function confere(bool $ok, string $caso): void
    {
        global $falhas;
        if (!$ok) {
            $falhas++;
        }
        echo ($ok ? 'PASSA ' : 'FALHA ') . $caso . PHP_EOL;
    }

    $motoboy = (object) ['uuid' => 'uuid-motoboy-1', 'public_id' => 'driver_m1', 'name' => 'João Motoboy', 'phone' => '+5516999990001'];
    $rua     = lugar(-21.18, -47.81, 'Rua A, 10');
    Vendor::$todos = [
        (object) ['uuid' => 'vendor-a', 'name' => 'Loja A', 'company_uuid' => EMPRESA],
        (object) ['uuid' => 'vendor-b', 'name' => 'Loja B', 'company_uuid' => EMPRESA],
    ];
    Order::$todos = [
        pedido('order_sem_motoboy', 'created', 'vendor-a', $recente, '2026-10-05 11:00:00', $rua, ['trackingNumber' => (object) ['tracking_number' => 'RP123']]),
        pedido('order_coleta', 'started', 'vendor-b', $recente, '2026-10-05 11:10:00', lugar(-21.19, -47.82, 'Rua B, 20'), ['driverAssigned' => $motoboy]),
        pedido('order_entrega', 'enroute', 'contato-a', $recente, '2026-10-05 11:20:00', lugar(-21.2, -47.83, null, 'Casa do cliente'), ['driverAssigned' => $motoboy]),
        pedido('order_sem_loja', 'dispatched', 'contato-x', $recente, '2026-10-05 10:50:00', $rua, ['payload' => (object) ['dropoff' => $rua, 'pickup' => (object) ['name' => 'Pizzaria Sem Cadastro']]]),
        pedido('order_concluido', 'completed', 'vendor-a', $recente, '2026-10-05 11:05:00', $rua),
        pedido('order_cancelado', 'canceled', 'vendor-a', $recente, '2026-10-05 11:05:00', $rua),
        pedido('order_expirado', 'expired', 'vendor-a', $recente, '2026-10-05 11:05:00', $rua),
        pedido('order_sem_status', null, 'vendor-a', $recente, '2026-10-05 11:05:00', $rua),
        pedido('order_velho', 'started', 'vendor-a', $antigo, '2026-10-04 19:00:00', $rua),
        pedido('order_sem_destino', 'created', 'vendor-a', $recente, '2026-10-05 11:05:00', null),
        pedido('order_sem_local', 'created', 'vendor-a', $recente, '2026-10-05 11:05:00', lugar(null, null, 'Rua sem GPS')),
        pedido('order_zero', 'created', 'vendor-a', $recente, '2026-10-05 11:05:00', lugar(0.0, 0.0, 'Rua (0, 0)')),
        pedido('order_outra_empresa', 'created', 'vendor-a', $recente, '2026-10-05 11:05:00', $rua, ['company_uuid' => 'empresa-b']),
    ];

    echo '== PedidosNoMapa::daCentral (mapa ao vivo do console)' . PHP_EOL;
    $central = PedidosNoMapa::daCentral(EMPRESA);
    $porId   = array_column($central, null, 'id');
    confere(array_column($central, 'id') === ['order_entrega', 'order_coleta', 'order_sem_motoboy', 'order_sem_loja'],
        'só os em andamento, com ou sem motoboy, dos mais novos para os mais antigos');
    confere(!isset($porId['order_concluido']) && !isset($porId['order_cancelado']) && !isset($porId['order_expirado']) && !isset($porId['order_sem_status']),
        'concluído, cancelado, expirado e sem status ficam fora');
    confere(!isset($porId['order_velho']), 'pedido parado há mais de 12 h fica fora');
    confere(!isset($porId['order_sem_destino']) && !isset($porId['order_sem_local']) && !isset($porId['order_zero']),
        'sem destino, sem coordenada ou no (0, 0) fica fora');
    confere(!isset($porId['order_outra_empresa']), 'pedido de outra empresa fica fora');
    confere(array_keys($central[0] ?? []) === ['id', 'numero', 'latitude', 'longitude', 'endereco', 'status', 'motoboy', 'criado_em', 'loja'],
        'campos do console, com a loja');
    confere(($porId['order_sem_motoboy']['numero'] ?? null) === 'RP123' && ($porId['order_coleta']['numero'] ?? null) === 'order_coleta',
        'número de rastreio; sem ele, o public_id');
    confere(($porId['order_coleta']['latitude'] ?? null) === -21.19 && ($porId['order_coleta']['longitude'] ?? null) === -47.82, 'coordenadas do destino em número');
    confere(($porId['order_coleta']['endereco'] ?? null) === 'Rua B, 20' && ($porId['order_entrega']['endereco'] ?? null) === 'Casa do cliente',
        'endereço do destino; sem endereço, o nome do local');
    confere(($porId['order_coleta']['motoboy'] ?? null) === 'João Motoboy' && array_key_exists('motoboy', $porId['order_sem_motoboy'] ?? []) && $porId['order_sem_motoboy']['motoboy'] === null,
        'nome do motoboy; sem motoboy, null');
    confere(($porId['order_coleta']['status'] ?? null) === 'started', 'status cru (o front traduz)');
    confere(($porId['order_sem_motoboy']['criado_em'] ?? null) === '2026-10-05T11:00:00+00:00', 'criado_em em ISO 8601');
    confere(($porId['order_sem_motoboy']['loja'] ?? null) === 'Loja A' && ($porId['order_coleta']['loja'] ?? null) === 'Loja B',
        'loja = nome do Fornecedor dono do pedido');
    confere(($porId['order_sem_loja']['loja'] ?? null) === 'Pizzaria Sem Cadastro', 'sem loja cadastrada: o nome do local de coleta');
    confere(array_key_exists('loja', $porId['order_entrega'] ?? []) && $porId['order_entrega']['loja'] === null, 'sem loja e sem coleta: null');
    $registro = \Teste\Consulta::$registro[Order::class] ?? [];
    confere(in_array('where company_uuid =', $registro, true) && in_array('permissao fleet-ops list order', $registro, true),
        'filtra pela empresa e pela permissão do Fleet-Ops');
    confere(in_array('limit 300', $registro, true), 'limite de 300 alfinetes');

    echo '== PedidosNoMapa::daLoja (portal da loja)' . PHP_EOL;
    $loja = PedidosNoMapa::daLoja(EMPRESA, ['vendor-a', 'contato-a']);
    confere(array_column($loja, 'id') === ['order_entrega', 'order_sem_motoboy'], 'só os pedidos da loja (Fornecedor e contato do usuário)');
    confere(array_keys($loja[0] ?? []) === ['id', 'numero', 'latitude', 'longitude', 'endereco', 'status', 'motoboy', 'criado_em'],
        'campos do portal, sem a loja');
    $json = json_encode($loja);
    confere(!str_contains($json, 'uuid-motoboy-1') && !str_contains($json, 'driver_m1') && !str_contains($json, '+5516'),
        'sem uuid, public_id nem telefone do motoboy');
    confere(PedidosNoMapa::daLoja(EMPRESA, []) === [], 'sem donos: lista vazia');

    echo '== limite' . PHP_EOL;
    Order::$todos = array_map(fn ($n) => pedido("order_{$n}", 'created', 'vendor-a', $recente, '2026-10-05 11:00:00', $rua), range(1, 305));
    confere(count(PedidosNoMapa::daCentral(EMPRESA)) === 300, 'no máximo 300 alfinetes');

    echo PHP_EOL . "FALHAS: {$falhas}" . PHP_EOL;
}
