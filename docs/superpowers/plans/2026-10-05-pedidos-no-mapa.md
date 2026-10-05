# Pedidos em andamento no mapa — plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Objetivo:** cada pedido em andamento aparece como um alfinete vermelho no endereço de entrega, no mapa ao vivo do
console (todos os pedidos) e no modo Mapa da tela Pedidos do portal da loja (só os pedidos da loja).

**Arquitetura:** a classe `App\Support\Entregas\PedidosNoMapa` monta a lista no servidor. As duas rotas que já
alimentam os capacetes (`entregas/mapa/motoboys` e `entregas/loja/motoboys`) passam a devolver também `pedidos`. Os dois
mapas desenham os alfinetes no mesmo ciclo dos capacetes, com funções puras testáveis em `utils/`.

**Tecnologias:** Laravel (PHP 8.2, testado com php-wasm), Ember/Glimmer com ember-leaflet, `node --test`.

Spec: `docs/superpowers/specs/2026-10-05-pedidos-no-mapa-design.md`.

Comandos de teste (na raiz do repo, Git Bash):

```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/<arquivo>.php
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
```

---

### Tarefa 1: `Coordenada` e `PedidosNoMapa` (servidor)

**Arquivos:**
- Criar: `api/app/Support/Entregas/Coordenada.php`
- Criar: `api/app/Support/Entregas/PedidosNoMapa.php`
- Modificar: `api/app/Support/Entregas/MotoboysNoMapaDaLoja.php` (o `coordenadaValida` passa a usar o `Coordenada::valida`)
- Modificar: `scripts/teste-php/mapa-da-loja.php` (require do `Coordenada.php`)
- Teste: `scripts/teste-php/pedidos-no-mapa.php`

- [ ] **Passo 1: escrever o teste que falha** em `scripts/teste-php/pedidos-no-mapa.php`:

```php
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
```

- [ ] **Passo 2: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/pedidos-no-mapa.php`
Esperado: erro fatal `Failed opening required '/repo/api/app/Support/Entregas/Coordenada.php'`, exit 1.

- [ ] **Passo 3: criar `api/app/Support/Entregas/Coordenada.php`**

```php
<?php

namespace App\Support\Entregas;

/**
 * Entregas RestaurantePro: coordenada que dá para pôr no mapa. Números dentro da faixa e fora do (0, 0), que é o "sem
 * GPS" do Fleetbase (mesmo critério do CalculoEntregas). Usada pelos capacetes do portal (MotoboysNoMapaDaLoja) e pelos
 * alfinetes dos pedidos (PedidosNoMapa).
 */
class Coordenada
{
    public static function valida($latitude, $longitude): bool
    {
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return false;
        }

        $latitude  = (float) $latitude;
        $longitude = (float) $longitude;

        if (abs($latitude) > 90 || abs($longitude) > 180) {
            return false;
        }

        return !(abs($latitude) <= 0.0001 && abs($longitude) <= 0.0001);
    }
}
```

- [ ] **Passo 4: criar `api/app/Support/Entregas/PedidosNoMapa.php`**

```php
<?php

namespace App\Support\Entregas;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vendor;

/**
 * Entregas RestaurantePro: os pedidos em andamento no mapa, um alfinete vermelho no endereço de entrega (desenho:
 * docs/superpowers/specs/2026-10-05-pedidos-no-mapa-design.md).
 *
 * - daCentral: todos os pedidos da empresa, com o nome da loja (MapaController@motoboys, mapa ao vivo do console);
 * - daLoja: só os pedidos dos donos informados (a loja da sessão e o contato do usuário), sem o nome da loja
 *   (PortalLojaController@motoboysNoMapa). A loja nunca recebe pedido, endereço ou cliente de outra loja (LGPD).
 *
 * Em andamento = status fora de StatusDoPedido::ENCERRADOS e atualizado nas últimas
 * SituacaoDoMotoboy::HORAS_PEDIDO_EM_ANDAMENTO horas, com ou sem motoboy (a mesma regra dos capacetes). Pedido sem destino
 * com coordenada válida fica de fora. Do motoboy sai só o nome: nunca o id (com ele, o canal driver.<id> do socket entrega
 * a posição e o telefone), o telefone ou o e-mail.
 */
class PedidosNoMapa
{
    /** No máximo tantos alfinetes, dos pedidos mais novos para os mais antigos. */
    public const LIMITE = 300;

    /**
     * @return array<int, array{id: string, numero: string, latitude: float, longitude: float, endereco: ?string, status: ?string, motoboy: ?string, criado_em: ?string, loja: ?string}>
     */
    public static function daCentral(string $companyUuid): array
    {
        $pedidos = static::consulta($companyUuid)
            ->applyDirectivesForPermissions('fleet-ops list order')
            ->with(['payload.pickup', 'payload.dropoff', 'driverAssigned.user', 'trackingNumber'])
            ->get();
        $lojas = static::nomesDasLojas($pedidos);

        return static::itens($pedidos, fn ($pedido) => ['loja' => static::nomeDaLoja($pedido, $lojas)]);
    }

    /**
     * @param array<int, string> $donos customer_uuid dos pedidos da loja: o Vendor e o contato do usuário
     *
     * @return array<int, array{id: string, numero: string, latitude: float, longitude: float, endereco: ?string, status: ?string, motoboy: ?string, criado_em: ?string}>
     */
    public static function daLoja(string $companyUuid, array $donos): array
    {
        if ($donos === []) {
            return [];
        }

        $pedidos = static::consulta($companyUuid)
            ->whereIn('customer_uuid', $donos)
            ->with(['payload.dropoff', 'driverAssigned.user', 'trackingNumber'])
            ->get();

        return static::itens($pedidos);
    }

    protected static function consulta(string $companyUuid)
    {
        return Order::where('company_uuid', $companyUuid)
            ->whereNotIn('status', StatusDoPedido::ENCERRADOS)
            ->where('updated_at', '>=', now()->subHours(SituacaoDoMotoboy::HORAS_PEDIDO_EM_ANDAMENTO))
            ->orderBy('created_at', 'desc')
            ->limit(static::LIMITE);
    }

    protected static function itens(iterable $pedidos, ?\Closure $extra = null): array
    {
        $lista = [];
        foreach ($pedidos as $pedido) {
            $destino   = $pedido->payload?->dropoff;
            $latitude  = $destino?->location?->getLat();
            $longitude = $destino?->location?->getLng();
            if (!Coordenada::valida($latitude, $longitude)) {
                continue;
            }

            $lista[] = array_merge([
                'id'        => $pedido->public_id,
                'numero'    => $pedido->trackingNumber?->tracking_number ?: $pedido->public_id,
                'latitude'  => (float) $latitude,
                'longitude' => (float) $longitude,
                'endereco'  => $destino->address ?: $destino->name,
                'status'    => $pedido->status,
                'motoboy'   => $pedido->driverAssigned?->name,
                'criado_em' => $pedido->created_at?->toIso8601String(),
            ], $extra ? $extra($pedido) : []);
        }

        return $lista;
    }

    /**
     * Nome de cada loja (Vendor) dona dos pedidos, numa consulta só, pelo uuid. O uuid de um contato nunca está em
     * `vendors`, e o `place` que o Vendor sempre carrega ($with) não é usado aqui.
     *
     * @return array<string, ?string>
     */
    protected static function nomesDasLojas(iterable $pedidos): array
    {
        $uuids = [];
        foreach ($pedidos as $pedido) {
            if ($pedido->customer_uuid) {
                $uuids[$pedido->customer_uuid] = true;
            }
        }
        if ($uuids === []) {
            return [];
        }

        $nomes = [];
        foreach (Vendor::without('place')->whereIn('uuid', array_keys($uuids))->get(['uuid', 'name']) as $vendor) {
            $nomes[$vendor->uuid] = $vendor->name;
        }

        return $nomes;
    }

    /** A loja dona do pedido; sem loja cadastrada, o nome do local de coleta (como na cobrança). */
    protected static function nomeDaLoja($pedido, array $lojas): ?string
    {
        return ($lojas[$pedido->customer_uuid] ?? null) ?: ($pedido->payload?->pickup?->name ?: null);
    }
}
```

- [ ] **Passo 5: `MotoboysNoMapaDaLoja` passa a usar o `Coordenada`**

Em `api/app/Support/Entregas/MotoboysNoMapaDaLoja.php`, troque o corpo do `coordenadaValida` (e o docblock dele) por:

```php
    /** Números dentro da faixa e fora do (0, 0), que é o "sem GPS" do Fleetbase (Coordenada). */
    protected static function coordenadaValida($latitude, $longitude): bool
    {
        return Coordenada::valida($latitude, $longitude);
    }
```

Em `scripts/teste-php/mapa-da-loja.php`, antes de `require '/repo/api/app/Support/Entregas/MotoboysNoMapaDaLoja.php';`,
acrescente:

```php
    require '/repo/api/app/Support/Entregas/Coordenada.php';
```

- [ ] **Passo 6: rodar os testes**

Run:
```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/pedidos-no-mapa.php
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/mapa-da-loja.php
```
Esperado: os dois terminam com `FALHAS: 0`.

- [ ] **Passo 7: sintaxe no PHP 8.2**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/Coordenada.php api/app/Support/Entregas/PedidosNoMapa.php api/app/Support/Entregas/MotoboysNoMapaDaLoja.php`
(confira o uso no cabeçalho do `sintaxe.mjs`). Esperado: sem erro de sintaxe.

- [ ] **Passo 8: commit**

```bash
git add api/app/Support/Entregas/Coordenada.php api/app/Support/Entregas/PedidosNoMapa.php api/app/Support/Entregas/MotoboysNoMapaDaLoja.php scripts/teste-php/pedidos-no-mapa.php scripts/teste-php/mapa-da-loja.php
git commit -m "Pedidos no mapa: lista dos alfinetes no servidor (central e loja)"
```

---

### Tarefa 2: as rotas dos capacetes devolvem `pedidos`

**Arquivos:**
- Modificar: `api/app/Http/Controllers/Entregas/MapaController.php` (`motoboys`)
- Modificar: `api/app/Http/Controllers/Entregas/PortalLojaController.php` (`motoboysNoMapa`)
- Teste: `scripts/teste-php/mapa.php`, `scripts/teste-php/mapa-da-loja.php`

- [ ] **Passo 1: testes que falham**

Nos dois testes, a classe `PedidosNoMapa` é um dublê que anota a chamada e devolve uma lista fixa. A classe real já tem
teste próprio (Tarefa 1).

Em `scripts/teste-php/mapa.php`, acrescente um bloco de namespace antes do `namespace {` global:

```php
namespace App\Support\Entregas {
    // dublê: a lista real tem teste próprio (pedidos-no-mapa.php)
    class PedidosNoMapa
    {
        public static array $chamadas = [];
        public static function daCentral(string $empresa): array { static::$chamadas[] = ['daCentral', $empresa]; return [['id' => 'order_x']]; }
        public static function daLoja(string $empresa, array $donos): array { static::$chamadas[] = ['daLoja', $empresa, $donos]; return [['id' => 'order_y']]; }
    }
}
```

e, logo depois de `$resposta = (new MapaController())->motoboys()->dados['motoboys'];` e das conferências da seção
`MapaController@motoboys`, acrescente:

```php
    $completa = (new MapaController())->motoboys()->dados;
    confere(array_keys($completa) === ['motoboys', 'pedidos'], 'resposta { motoboys, pedidos }');
    confere($completa['pedidos'] === [['id' => 'order_x']] && end(\App\Support\Entregas\PedidosNoMapa::$chamadas) === ['daCentral', EMPRESA],
        'pedidos = PedidosNoMapa::daCentral com a empresa da sessão');
```

Em `scripts/teste-php/mapa-da-loja.php`, dentro do bloco `namespace App\Support\Entregas { ... }` que já existe,
acrescente a mesma classe `PedidosNoMapa` acima. Troque a conferência

```php
    confere(array_keys($resposta) === ['motoboys'], 'resposta { motoboys: [...] }');
```

por

```php
    confere(array_keys($resposta) === ['motoboys', 'pedidos'], 'resposta { motoboys, pedidos }');
    confere($resposta['pedidos'] === [['id' => 'order_y']] && end(\App\Support\Entregas\PedidosNoMapa::$chamadas) === ['daLoja', EMPRESA, ['vendor-a', 'contato-a']],
        'pedidos = PedidosNoMapa::daLoja com a empresa e os donos da loja (Vendor e contato do usuário)');
```

- [ ] **Passo 2: rodar e ver falhar**

Run:
```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/mapa.php
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/mapa-da-loja.php
```
Esperado: `FALHA resposta { motoboys, pedidos }` nos dois.

- [ ] **Passo 3: `MapaController@motoboys`**

Acrescente `use App\Support\Entregas\PedidosNoMapa;` aos imports. No `return response()->json([...])` do `motoboys()`,
depois da chave `'motoboys' => ...->values(),`, acrescente:

```php
            // Entregas: os alfinetes dos pedidos em andamento (todos da empresa), relidos junto com os capacetes
            'pedidos'  => PedidosNoMapa::daCentral(session('company')),
```

No docblock da classe, acrescente a linha:

```php
 * - motoboys também traz `pedidos`: os pedidos em andamento, um alfinete no endereço de entrega (PedidosNoMapa).
```

- [ ] **Passo 4: `PortalLojaController@motoboysNoMapa`**

Acrescente `use App\Support\Entregas\PedidosNoMapa;` aos imports. Troque o método por:

```php
    /**
     * Motoboys no mapa do portal (todos os online e os offline com pedido aceito), consultado a cada 5 s com o mapa aberto.
     * Sem id, telefone nem pedido de outra loja (MotoboysNoMapaDaLoja). Traz também os alfinetes dos pedidos em andamento
     * da loja (PedidosNoMapa::daLoja): só os dela, nunca os das outras lojas.
     */
    public function motoboysNoMapa()
    {
        $vendor = $this->lojaDaSessao();
        $donos  = $this->donosDosPedidos($vendor);

        return response()->json([
            'motoboys' => MotoboysNoMapaDaLoja::listar(session('company'), $donos),
            'pedidos'  => PedidosNoMapa::daLoja(session('company'), $donos),
        ]);
    }
```

- [ ] **Passo 5: rodar os testes**

Run: os dois comandos do Passo 2 e mais `scripts/teste-php/pedidos-no-mapa.php`.
Esperado: `FALHAS: 0` nos três.

- [ ] **Passo 6: sintaxe**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Http/Controllers/Entregas/MapaController.php api/app/Http/Controllers/Entregas/PortalLojaController.php`
Esperado: sem erro.

- [ ] **Passo 7: commit**

```bash
git add api/app/Http/Controllers/Entregas/MapaController.php api/app/Http/Controllers/Entregas/PortalLojaController.php scripts/teste-php/mapa.php scripts/teste-php/mapa-da-loja.php
git commit -m "Pedidos no mapa: rotas dos capacetes devolvem os alfinetes"
```

---

### Tarefa 3: funções puras do console

**Arquivos:**
- Criar: `packages/fleetops/addon/utils/entregas-pedidos-no-mapa.js`
- Teste: `scripts/teste-portal/entregas-pedidos-no-mapa.test.mjs`

- [ ] **Passo 1: teste que falha**

```js
// Alfinetes dos pedidos em andamento no mapa ao vivo do console (packages/fleetops/addon/utils/entregas-pedidos-no-mapa.js).
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ALFINETE, mesmaLista, pedidosValidos, tempoDesde } from '../../packages/fleetops/addon/utils/entregas-pedidos-no-mapa.js';

test('alfinete vermelho em SVG, com a ponta embaixo no meio', () => {
    assert.match(ALFINETE.url, /^data:image\/svg\+xml;charset=UTF-8,/);
    assert.match(decodeURIComponent(ALFINETE.url), /#dc2626/);
    assert.deepEqual(ALFINETE.tamanho, [26, 36]);
    assert.deepEqual(ALFINETE.ponta, [13, 35]);
});

test('pedidos válidos: com id e coordenada no mapa, em número', () => {
    const lista = pedidosValidos([
        { id: 'order_a', latitude: '-21.18', longitude: '-47.81', status: 'started' },
        { id: 'order_zero', latitude: 0, longitude: 0 },
        { id: '', latitude: -21.1, longitude: -47.8 },
        { latitude: -21.1, longitude: -47.8 },
        { id: 'order_fora', latitude: 91, longitude: 10 },
        { id: 'order_nulo', latitude: null, longitude: -47.8 },
        null,
    ]);
    assert.deepEqual(lista, [{ id: 'order_a', latitude: -21.18, longitude: -47.81, status: 'started' }]);
    assert.deepEqual(pedidosValidos(undefined), [], 'resposta de API antiga, sem pedidos');
    assert.deepEqual(pedidosValidos('x'), []);
});

test('mesma lista: compara o conteúdo', () => {
    assert.equal(mesmaLista([{ id: 'a', status: 'started' }], [{ id: 'a', status: 'started' }]), true);
    assert.equal(mesmaLista([{ id: 'a', status: 'started' }], [{ id: 'a', status: 'enroute' }]), false);
    assert.equal(mesmaLista([], []), true);
});

test('tempo desde a criação: agora, minutos e horas', () => {
    const agora = Date.parse('2026-10-05T12:00:00Z');
    assert.deepEqual(tempoDesde('2026-10-05T11:59:40Z', agora), { unidade: 'agora', n: 0 });
    assert.deepEqual(tempoDesde('2026-10-05T11:48:00Z', agora), { unidade: 'min', n: 12 });
    assert.deepEqual(tempoDesde('2026-10-05T11:00:30Z', agora), { unidade: 'min', n: 59 });
    assert.deepEqual(tempoDesde('2026-10-05T09:30:00Z', agora), { unidade: 'h', n: 2 });
    assert.deepEqual(tempoDesde('2026-10-05T12:05:00Z', agora), { unidade: 'agora', n: 0 }, 'relógio adiantado do servidor');
    assert.equal(tempoDesde(null, agora), null);
    assert.equal(tempoDesde('xx', agora), null);
});
```

- [ ] **Passo 2: rodar e ver falhar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/entregas-pedidos-no-mapa.test.mjs`
Esperado: FAIL com `Cannot find module .../entregas-pedidos-no-mapa.js`.

- [ ] **Passo 3: implementar `packages/fleetops/addon/utils/entregas-pedidos-no-mapa.js`**

```js
/**
 * Entregas: os alfinetes dos pedidos em andamento no mapa ao vivo do console (resposta.pedidos de
 * GET int/v1/entregas/mapa/motoboys, montada pelo PedidosNoMapa::daCentral). Funções puras, testadas em
 * scripts/teste-portal/entregas-pedidos-no-mapa.test.mjs. O portal da loja tem a cópia dele
 * (customer-portal/addon/utils/pedidos-no-mapa.js): um engine não importa do outro.
 */

const SVG_DO_ALFINETE =
    '<svg xmlns="http://www.w3.org/2000/svg" width="26" height="36" viewBox="0 0 26 36">' +
    '<path d="M13 1C6.4 1 1 6.3 1 12.9 1 21.8 13 35 13 35s12-13.2 12-22.1C25 6.3 19.6 1 13 1z" fill="#dc2626" stroke="#7f1d1d" stroke-width="1.5"/>' +
    '<circle cx="13" cy="13" r="4.5" fill="#fff"/></svg>';

/** Alfinete vermelho: a imagem, o tamanho e a ponta (o ponto exato do endereço de entrega). */
export const ALFINETE = {
    url: `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(SVG_DO_ALFINETE)}`,
    tamanho: [26, 36],
    ponta: [13, 35],
    popup: [0, -32],
};

/** Número finito, dentro da faixa e fora do (0, 0), o "sem GPS" do Fleetbase (o Coordenada do servidor). */
function coordenadaValida(latitude, longitude) {
    if (latitude === null || longitude === null || latitude === '' || longitude === '') {
        return false;
    }

    const lat = Number(latitude);
    const lng = Number(longitude);

    if (!Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) {
        return false;
    }

    return !(Math.abs(lat) <= 0.0001 && Math.abs(lng) <= 0.0001);
}

/** Os pedidos que dá para pôr no mapa (com id e coordenada válida), com as coordenadas em número. */
export function pedidosValidos(pedidos) {
    if (!Array.isArray(pedidos)) {
        return [];
    }

    return pedidos
        .filter((pedido) => pedido && typeof pedido.id === 'string' && pedido.id !== '' && coordenadaValida(pedido.latitude, pedido.longitude))
        .map((pedido) => ({ ...pedido, latitude: Number(pedido.latitude), longitude: Number(pedido.longitude) }));
}

/** Mesma lista? Só troca (e redesenha os alfinetes) quando algo mudou. */
export function mesmaLista(a, b) {
    return JSON.stringify(a ?? []) === JSON.stringify(b ?? []);
}

/** Tempo desde a criação do pedido: { unidade: 'agora' | 'min' | 'h', n }, ou null sem data. */
export function tempoDesde(criadoEm, agora = Date.now()) {
    const inicio = Date.parse(criadoEm ?? '');

    if (!Number.isFinite(inicio)) {
        return null;
    }

    const minutos = Math.max(0, Math.floor((agora - inicio) / 60000));

    if (minutos < 1) {
        return { unidade: 'agora', n: 0 };
    }

    return minutos < 60 ? { unidade: 'min', n: minutos } : { unidade: 'h', n: Math.floor(minutos / 60) };
}
```

- [ ] **Passo 4: rodar e ver passar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/entregas-pedidos-no-mapa.test.mjs`
Esperado: todos os testes passam.

- [ ] **Passo 5: commit**

```bash
git add packages/fleetops/addon/utils/entregas-pedidos-no-mapa.js scripts/teste-portal/entregas-pedidos-no-mapa.test.mjs
git commit -m "Pedidos no mapa: funções puras dos alfinetes do console"
```

---

### Tarefa 4: alfinetes no mapa ao vivo do console

**Arquivos:**
- Modificar: `packages/fleetops/addon/components/map/leaflet-live-map.js`
- Modificar: `packages/fleetops/addon/components/map/leaflet-live-map.hbs`
- Modificar: `packages/fleetops/translations/pt-br.yaml` e `en-us.yaml` (seção `fleet-ops.ui.map.leaflet-live-map`)

- [ ] **Passo 1: componente**

Em `leaflet-live-map.js`:

1. Import, depois do `import aplicarOnlineDoMotoboy ...`:

```js
import { ALFINETE, mesmaLista, pedidosValidos, tempoDesde } from '../../utils/entregas-pedidos-no-mapa';
```

2. Serviço, junto dos outros `@service`:

```js
    @service hostRouter;
```

3. Estado, depois de `@tracked situacoesDosMotoboys = {};`:

```js
    /** Entregas: os pedidos em andamento (alfinete vermelho no endereço de entrega) e o relógio do "há X min". */
    @tracked pedidosNoMapa = [];
    @tracked relogio = Date.now();
    alfinete = ALFINETE;
```

4. Na task `acompanharSituacoesDosMotoboys`, logo depois do `if (JSON.stringify(situacoes) !== ...) { ... }`:

```js
                    // os alfinetes dos pedidos vêm na mesma resposta; API antiga (sem pedidos) = nenhum alfinete
                    const pedidos = pedidosValidos(resposta?.pedidos);
                    if (!mesmaLista(pedidos, this.pedidosNoMapa)) {
                        this.pedidosNoMapa = pedidos;
                    }
                    this.relogio = Date.now();
```

5. Depois do `capaceteDoMotoboy = (driver) => ...`:

```js
    /** Entregas: "há X min" do alfinete; o relogio (atualizado a cada releitura) faz o texto andar. */
    tempoDoPedido = (pedido, relogio) => {
        const tempo = tempoDesde(pedido?.criado_em, relogio);

        return tempo ? this.intl.t(`fleet-ops.ui.map.leaflet-live-map.pedido-ha-${tempo.unidade}`, { n: tempo.n }) : '';
    };

    /** Entregas: o botão "Abrir pedido" do alfinete. */
    @action abrirPedido(id) {
        this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index.details', id);
    }
```

- [ ] **Passo 2: template**

Em `leaflet-live-map.hbs`, logo depois do `{{/each}}` do `{{#each this.places as |place|}}` (antes do
`{{#each this.serviceAreaActions.serviceAreas ...}}`), acrescente:

```hbs
                {{! Entregas: um alfinete vermelho no endereço de entrega de cada pedido em andamento (PedidosNoMapa), por baixo dos capacetes }}
                {{#each this.pedidosNoMapa key="id" as |pedido|}}
                    <layers.marker
                        @lat={{pedido.latitude}}
                        @lng={{pedido.longitude}}
                        @icon={{icon iconUrl=this.alfinete.url iconSize=this.alfinete.tamanho iconAnchor=this.alfinete.ponta popupAnchor=this.alfinete.popup}}
                        @zIndexOffset={{-1000}}
                        @riseOnHover={{true}}
                        @title={{pedido.numero}}
                        @draggable={{false}}
                        as |marker|
                    >
                        <marker.popup @minWidth="220">
                            <div class="text-xs space-y-0.5">
                                <div class="text-sm font-semibold">{{pedido.numero}}</div>
                                <div><span class="text-gray-500">{{t "fleet-ops.ui.map.leaflet-live-map.pedido-loja"}}:</span> {{n-a pedido.loja}}</div>
                                <div><span class="text-gray-500">{{t "fleet-ops.ui.map.leaflet-live-map.pedido-status"}}:</span> {{smart-humanize pedido.status}}</div>
                                <div><span class="text-gray-500">{{t "fleet-ops.ui.map.leaflet-live-map.pedido-motoboy"}}:</span> {{or pedido.motoboy (t "fleet-ops.ui.map.leaflet-live-map.pedido-sem-motoboy")}}</div>
                                <div><span class="text-gray-500">{{t "fleet-ops.ui.map.leaflet-live-map.pedido-criado"}}:</span> {{this.tempoDoPedido pedido this.relogio}}</div>
                                <div><span class="text-gray-500">{{t "fleet-ops.ui.map.leaflet-live-map.pedido-entrega"}}:</span> {{n-a pedido.endereco}}</div>
                                <Button @size="xs" @type="primary" @text={{t "fleet-ops.ui.map.leaflet-live-map.pedido-abrir"}} @onClick={{fn this.abrirPedido pedido.id}} @wrapperClass="pt-1" />
                            </div>
                        </marker.popup>
                    </layers.marker>
                {{/each}}
```

- [ ] **Passo 3: textos**

`packages/fleetops/translations/pt-br.yaml` (indentação de 2 espaços; a seção começa em `      leaflet-live-map:`),
logo abaixo da linha `      leaflet-live-map:`:

```yaml
        pedido-loja: Loja
        pedido-status: Status
        pedido-motoboy: Motoboy
        pedido-sem-motoboy: Sem motoboy
        pedido-criado: Criado
        pedido-entrega: Entrega
        pedido-abrir: Abrir pedido
        pedido-ha-agora: agora mesmo
        pedido-ha-min: "há {n} min"
        pedido-ha-h: "há {n} h"
```

`packages/fleetops/translations/en-us.yaml` (indentação de 4 espaços; a seção começa em `            leaflet-live-map:`),
logo abaixo da linha `            leaflet-live-map:`:

```yaml
                pedido-loja: Store
                pedido-status: Status
                pedido-motoboy: Courier
                pedido-sem-motoboy: No courier
                pedido-criado: Created
                pedido-entrega: Delivery
                pedido-abrir: Open order
                pedido-ha-agora: just now
                pedido-ha-min: "{n} min ago"
                pedido-ha-h: "{n} h ago"
```

- [ ] **Passo 4: validar**

Run:
```bash
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal
node -e "const p=require(require('fs').readdirSync('console/node_modules/.pnpm').filter(d=>d.startsWith('@babel+parser@7')).map(d=>'./console/node_modules/.pnpm/'+d+'/node_modules/@babel/parser')[0]);for(const f of ['packages/fleetops/addon/components/map/leaflet-live-map.js','packages/fleetops/addon/utils/entregas-pedidos-no-mapa.js'])p.parse(require('fs').readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties','classPrivateProperties','classPrivateMethods']});console.log('ok')"
```
Esperado: i18n-check com exit 0; o parse imprime `ok`.

- [ ] **Passo 5: commit**

```bash
git add packages/fleetops/addon/components/map/leaflet-live-map.js packages/fleetops/addon/components/map/leaflet-live-map.hbs packages/fleetops/translations/pt-br.yaml packages/fleetops/translations/en-us.yaml
git commit -m "Pedidos no mapa: alfinetes no mapa ao vivo do console"
```

---

### Tarefa 5: funções puras do portal

**Arquivos:**
- Criar: `packages/customer-portal/addon/utils/pedidos-no-mapa.js`
- Teste: `scripts/teste-portal/pedidos-no-mapa.test.mjs`

- [ ] **Passo 1: teste que falha**

```js
// Alfinetes dos pedidos da loja no mapa do portal (packages/customer-portal/addon/utils/pedidos-no-mapa.js).
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ALFINETE, mesmaLista, pedidosValidos, semOPedidoAberto, tempoDesde } from '../../packages/customer-portal/addon/utils/pedidos-no-mapa.js';
import { ALFINETE as ALFINETE_DO_CONSOLE } from '../../packages/fleetops/addon/utils/entregas-pedidos-no-mapa.js';

test('o mesmo alfinete do console', () => {
    assert.deepEqual(ALFINETE, ALFINETE_DO_CONSOLE);
});

test('pedidos válidos: com id e coordenada no mapa, em número', () => {
    const lista = pedidosValidos([
        { id: 'order_a', latitude: '-21.18', longitude: '-47.81' },
        { id: 'order_zero', latitude: 0, longitude: 0 },
        { latitude: -21.1, longitude: -47.8 },
        null,
    ]);
    assert.deepEqual(lista, [{ id: 'order_a', latitude: -21.18, longitude: -47.81 }]);
    assert.deepEqual(pedidosValidos(undefined), []);
});

test('o pedido aberto no detalhe sai (a rota já mostra P e D)', () => {
    const pedidos = [{ id: 'order_a' }, { id: 'order_b' }];
    assert.deepEqual(semOPedidoAberto(pedidos, 'order_a'), [{ id: 'order_b' }]);
    assert.equal(semOPedidoAberto(pedidos, null), pedidos);
    assert.equal(semOPedidoAberto(pedidos, undefined), pedidos);
});

test('mesma lista e tempo desde a criação', () => {
    assert.equal(mesmaLista([{ id: 'a' }], [{ id: 'a' }]), true);
    assert.equal(mesmaLista([{ id: 'a' }], [{ id: 'b' }]), false);
    const agora = Date.parse('2026-10-05T12:00:00Z');
    assert.deepEqual(tempoDesde('2026-10-05T11:48:00Z', agora), { unidade: 'min', n: 12 });
    assert.deepEqual(tempoDesde('2026-10-05T09:30:00Z', agora), { unidade: 'h', n: 2 });
    assert.deepEqual(tempoDesde('2026-10-05T11:59:40Z', agora), { unidade: 'agora', n: 0 });
    assert.equal(tempoDesde(undefined, agora), null);
});
```

- [ ] **Passo 2: rodar e ver falhar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/pedidos-no-mapa.test.mjs`
Esperado: FAIL com `Cannot find module .../pedidos-no-mapa.js`.

- [ ] **Passo 3: implementar `packages/customer-portal/addon/utils/pedidos-no-mapa.js`**

```js
import { coordenadaValida } from './motoboys-no-mapa';

/**
 * Entregas: os alfinetes dos pedidos em andamento da loja no mapa do portal (resposta.pedidos de
 * GET int/v1/entregas/loja/motoboys, montada pelo PedidosNoMapa::daLoja: só os pedidos da loja). Funções puras, testadas
 * em scripts/teste-portal/pedidos-no-mapa.test.mjs. Cópia das do console (fleetops/addon/utils/entregas-pedidos-no-mapa.js):
 * um engine não importa do outro, e o teste confere que o alfinete é o mesmo.
 */

const SVG_DO_ALFINETE =
    '<svg xmlns="http://www.w3.org/2000/svg" width="26" height="36" viewBox="0 0 26 36">' +
    '<path d="M13 1C6.4 1 1 6.3 1 12.9 1 21.8 13 35 13 35s12-13.2 12-22.1C25 6.3 19.6 1 13 1z" fill="#dc2626" stroke="#7f1d1d" stroke-width="1.5"/>' +
    '<circle cx="13" cy="13" r="4.5" fill="#fff"/></svg>';

/** Alfinete vermelho: a imagem, o tamanho e a ponta (o ponto exato do endereço de entrega). */
export const ALFINETE = {
    url: `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(SVG_DO_ALFINETE)}`,
    tamanho: [26, 36],
    ponta: [13, 35],
    popup: [0, -32],
};

/** Os pedidos que dá para pôr no mapa (com id e coordenada válida), com as coordenadas em número. */
export function pedidosValidos(pedidos) {
    if (!Array.isArray(pedidos)) {
        return [];
    }

    return pedidos
        .filter((pedido) => pedido && typeof pedido.id === 'string' && pedido.id !== '' && coordenadaValida(pedido.latitude, pedido.longitude))
        .map((pedido) => ({ ...pedido, latitude: Number(pedido.latitude), longitude: Number(pedido.longitude) }));
}

/** Sem o pedido aberto no detalhe: a rota dele já mostra P e D no mapa. */
export function semOPedidoAberto(pedidos, publicId) {
    return publicId ? pedidos.filter((pedido) => pedido.id !== publicId) : pedidos;
}

/** Mesma lista? Só troca (e redesenha os alfinetes) quando algo mudou. */
export function mesmaLista(a, b) {
    return JSON.stringify(a ?? []) === JSON.stringify(b ?? []);
}

/** Tempo desde a criação do pedido: { unidade: 'agora' | 'min' | 'h', n }, ou null sem data. */
export function tempoDesde(criadoEm, agora = Date.now()) {
    const inicio = Date.parse(criadoEm ?? '');

    if (!Number.isFinite(inicio)) {
        return null;
    }

    const minutos = Math.max(0, Math.floor((agora - inicio) / 60000));

    if (minutos < 1) {
        return { unidade: 'agora', n: 0 };
    }

    return minutos < 60 ? { unidade: 'min', n: minutos } : { unidade: 'h', n: Math.floor(minutos / 60) };
}
```

- [ ] **Passo 4: rodar e ver passar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs`
Esperado: todos passam (inclusive os testes que já existiam).

- [ ] **Passo 5: commit**

```bash
git add packages/customer-portal/addon/utils/pedidos-no-mapa.js scripts/teste-portal/pedidos-no-mapa.test.mjs
git commit -m "Pedidos no mapa: funções puras dos alfinetes do portal"
```

---

### Tarefa 6: alfinetes no mapa do portal da loja

**Arquivos:**
- Modificar: `packages/customer-portal/addon/components/portal/order/workspace/map.js`
- Modificar: `packages/customer-portal/addon/components/portal/order/workspace/map.hbs`
- Modificar: `packages/customer-portal/translations/pt-br.yaml` e `en-us.yaml` (seção `customer-portal.ui.entregas`)

- [ ] **Passo 1: componente**

Em `map.js`:

1. Imports, junto dos outros:

```js
import { tracked } from '@glimmer/tracking';
import { ALFINETE, mesmaLista, pedidosValidos, semOPedidoAberto, tempoDesde } from '../../../../utils/pedidos-no-mapa';
```

2. Estado, depois de `ultimosMotoboys = [];`:

```js
    // Entregas: os pedidos em andamento da loja (alfinete vermelho no endereço de entrega) e o relógio do "há X min"
    @tracked pedidosNoMapa = [];
    @tracked relogio = Date.now();
    _iconeDoAlfinete = null;
```

3. Getters e helpers, depois do `get markers() { ... }`:

```js
    // Entregas: o pedido aberto no detalhe já mostra P e D pela rota: o alfinete dele sai
    get alfinetes() {
        return semOPedidoAberto(this.pedidosNoMapa, this.args.selectedOrder?.public_id ?? null);
    }

    // um ícone só para todos os alfinetes (criado quando o Leaflet já existe)
    get iconeDoAlfinete() {
        const L = globalThis.L;

        if (!this._iconeDoAlfinete && L?.icon) {
            this._iconeDoAlfinete = L.icon({ iconUrl: ALFINETE.url, iconSize: ALFINETE.tamanho, iconAnchor: ALFINETE.ponta, popupAnchor: ALFINETE.popup });
        }

        return this._iconeDoAlfinete ?? undefined;
    }

    // "há X min" do alfinete; o relogio (atualizado a cada consulta) faz o texto andar
    tempoDoPedido = (pedido, relogio) => {
        const tempo = tempoDesde(pedido?.criado_em, relogio);

        return tempo ? this.intl.t(`customer-portal.ui.entregas.mapa-pedido-ha-${tempo.unidade}`, { n: tempo.n }) : '';
    };
```

4. Na task `acompanharMotoboys`, dentro do `if (resposta) {`, logo depois de
`this.ultimosMotoboys = Array.isArray(resposta.motoboys) ? resposta.motoboys : [];`:

```js
                    // os alfinetes dos pedidos da loja vêm na mesma resposta; API antiga (sem pedidos) = nenhum alfinete
                    const pedidos = pedidosValidos(resposta.pedidos);
                    if (!mesmaLista(pedidos, this.pedidosNoMapa)) {
                        this.pedidosNoMapa = pedidos;
                    }
                    this.relogio = Date.now();
```

- [ ] **Passo 2: template**

Em `map.hbs`, depois do `{{/each}}` dos `this.markers` (antes de `</LeafletMap>`):

```hbs
        {{!-- Entregas: alfinete vermelho no endereço de entrega de cada pedido em andamento da loja (PedidosNoMapa::daLoja), por baixo dos capacetes --}}
        {{#each this.alfinetes key="id" as |pedido|}}
            <layers.marker @lat={{pedido.latitude}} @lng={{pedido.longitude}} @icon={{this.iconeDoAlfinete}} @zIndexOffset={{-1000}} @riseOnHover={{true}} as |marker|>
                <marker.popup @minWidth="200">
                    <div class="text-sm font-semibold">{{pedido.numero}}</div>
                    <div class="text-xs"><span class="text-gray-500">{{t "customer-portal.ui.entregas.mapa-pedido-status"}}:</span> {{smart-humanize pedido.status}}</div>
                    <div class="text-xs"><span class="text-gray-500">{{t "customer-portal.ui.entregas.mapa-pedido-motoboy"}}:</span> {{or pedido.motoboy (t "customer-portal.ui.entregas.mapa-pedido-sem-motoboy")}}</div>
                    <div class="text-xs"><span class="text-gray-500">{{t "customer-portal.ui.entregas.mapa-pedido-criado"}}:</span> {{this.tempoDoPedido pedido this.relogio}}</div>
                    <div class="text-xs"><span class="text-gray-500">{{t "customer-portal.ui.entregas.mapa-pedido-entrega"}}:</span> {{n-a pedido.endereco}}</div>
                    <LinkTo @route="portal.orders.details" @model={{pedido.id}} class="text-xs font-semibold mt-1 inline-block">{{t "customer-portal.ui.entregas.mapa-pedido-ver"}}</LinkTo>
                </marker.popup>
            </layers.marker>
        {{/each}}
```

- [ ] **Passo 3: textos**

`packages/customer-portal/translations/pt-br.yaml`, logo abaixo da linha `      zoom-in: Aproximar` (seção
`customer-portal.ui.entregas`, indentação de 6 espaços):

```yaml
      mapa-pedido-status: Status
      mapa-pedido-motoboy: Motoboy
      mapa-pedido-sem-motoboy: Sem motoboy
      mapa-pedido-criado: Criado
      mapa-pedido-entrega: Entrega
      mapa-pedido-ver: Ver pedido
      mapa-pedido-ha-agora: agora mesmo
      mapa-pedido-ha-min: "há {n} min"
      mapa-pedido-ha-h: "há {n} h"
```

`packages/customer-portal/translations/en-us.yaml`, logo abaixo da linha `      zoom-in: Zoom in`:

```yaml
      mapa-pedido-status: Status
      mapa-pedido-motoboy: Courier
      mapa-pedido-sem-motoboy: No courier
      mapa-pedido-criado: Created
      mapa-pedido-entrega: Delivery
      mapa-pedido-ver: View order
      mapa-pedido-ha-agora: just now
      mapa-pedido-ha-min: "{n} min ago"
      mapa-pedido-ha-h: "{n} h ago"
```

- [ ] **Passo 4: validar**

Run:
```bash
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal
node -e "const p=require(require('fs').readdirSync('console/node_modules/.pnpm').filter(d=>d.startsWith('@babel+parser@7')).map(d=>'./console/node_modules/.pnpm/'+d+'/node_modules/@babel/parser')[0]);for(const f of ['packages/customer-portal/addon/components/portal/order/workspace/map.js','packages/customer-portal/addon/utils/pedidos-no-mapa.js'])p.parse(require('fs').readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties','classPrivateProperties','classPrivateMethods']});console.log('ok')"
```
Esperado: exit 0 e `ok`.

- [ ] **Passo 5: commit**

```bash
git add packages/customer-portal/addon/components/portal/order/workspace/map.js packages/customer-portal/addon/components/portal/order/workspace/map.hbs packages/customer-portal/translations/pt-br.yaml packages/customer-portal/translations/en-us.yaml
git commit -m "Pedidos no mapa: alfinetes da loja no mapa do portal"
```

---

### Tarefa 7: build, documentação e verificação final

**Arquivos:**
- Modificar: `CLAUDE.md` (seções "Mapa ao vivo" e "Mapa de motoboys" do portal; histórico)
- Modificar: `docs/superpowers/specs/2026-10-05-pedidos-no-mapa-design.md` (nomes finais das chaves de tradução)

- [ ] **Passo 1: build do console** (confere os templates, que nenhum teste cobre)

Run: `cd console && DISABLE_RUNTIME_CONFIG=false pnpm build --environment production` (~10 min)
Esperado: build sem erro.

- [ ] **Passo 2: testes completos**

Run:
```bash
for f in pedidos-no-mapa mapa mapa-da-loja conversas-da-loja; do PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$f.php | tail -1; done
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
```
Esperado: `FALHAS: 0` em todos; todos os testes do node passam.

- [ ] **Passo 3: documentação**

`CLAUDE.md`, seção "Mapa ao vivo", acrescente o item:

```markdown
  - **Pedidos em andamento = alfinete vermelho no endereço de entrega** (decisão de 2026-10-05; desenho: `docs/superpowers/specs/2026-10-05-pedidos-no-mapa-design.md`). A lista vem em `pedidos` na mesma resposta de `entregas/mapa/motoboys` (`App\Support\Entregas\PedidosNoMapa::daCentral`: não encerrado e atualizado nas últimas 12 h, com ou sem motoboy, até 300) e é relida junto com os capacetes. O popup mostra número, loja, status, motoboy, "há X min" e endereço, e tem o botão Abrir pedido. Funções puras em `utils/entregas-pedidos-no-mapa.js`; testes `scripts/teste-php/pedidos-no-mapa.php` e `scripts/teste-portal/entregas-pedidos-no-mapa.test.mjs`.
```

Na seção "Mapa de motoboys" do portal, acrescente o item:

```markdown
  - **Pedidos da loja no mapa** (decisão de 2026-10-05): alfinete vermelho no endereço de entrega de cada pedido em andamento **só da própria loja** (o endereço é dado do cliente; a loja nunca vê os pedidos das outras). Vem em `pedidos` na resposta de `loja/motoboys` (`PedidosNoMapa::daLoja`, sem o nome da loja e sem id de motoboy), no mesmo ciclo de 5 s. O pedido aberto no detalhe não ganha alfinete (já tem P e D). Funções puras em `utils/pedidos-no-mapa.js`.
```

No "Histórico", acrescente o item 15:

```markdown
15. Pedidos em andamento no mapa (2026-10-05): alfinete vermelho no endereço de entrega, todos no console e só os da loja no portal.
```

Na spec, na seção "Textos", troque os prefixos pelos usados: `fleet-ops.ui.map.leaflet-live-map.pedido-*` e
`customer-portal.ui.entregas.mapa-pedido-*`.

- [ ] **Passo 4: commit**

```bash
git add CLAUDE.md docs/superpowers/specs/2026-10-05-pedidos-no-mapa-design.md
git commit -m "Pedidos no mapa: documentação"
```

- [ ] **Passo 5: deploy (Edgard, na VPS)**

```bash
cd ~/entregas && bash deploy/atualizar.sh
```

Depois, Ctrl+Shift+R no console. Para conferir: crie um pedido na loja de testes (Terraço Pizza Bar) e veja o alfinete
no mapa do console e no modo Mapa do portal dela.
