# Motoboys no mapa do portal da loja — plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** a tela Pedidos do portal da loja mostra no mapa todos os motoboys online (e os offline que já aceitaram um
pedido ainda aberto), com o capacete do console na cor da situação, o nome fixo e a posição relida a cada 5 s, deslizando.

**Architecture:** uma rota nova da API (`GET int/v1/entregas/loja/motoboys`) monta a lista em
`App\Support\Entregas\MotoboysNoMapaDaLoja`, reaproveitando a consulta de pedidos em andamento do mapa do console
(`SituacaoDoMotoboy::pedidosEmAndamento`), sem nunca expor o id ou o telefone do motoboy. No portal, o mapa da tela Pedidos
consulta a rota a cada 5 s e entrega a lista a uma camada própria (`utils/camada-de-motoboys.js`) que cria, move
(deslizando) e remove os marcadores no Leaflet; o serviço da rota inclui o motoboy do pedido aberto no enquadramento, uma
vez por pedido. O mapinha do painel "Motoboy" sai, e a rota do motoboy do pedido deixa de mandar a posição.

**Tech Stack:** Laravel 10 / PHP 8.2 (código em `api/app`; pacotes Fleetbase do Composer: core-api, fleetops-api 0.6.65),
testes PHP com php-wasm (`scripts/teste-php`), Ember (engine `packages/customer-portal`), Leaflet 1.9, ember-concurrency,
testes das funções JS com `node --test` (Node 22) e um hook de resolução para os imports sem extensão.

**Desenho aprovado:** `docs/superpowers/specs/2026-10-04-motoboys-no-mapa-do-portal-design.md`.

---

## Ambiente

- **Worktree:** `C:\tmp\mp` (Git Bash: `/c/tmp/mp`), criada com `git worktree add --detach C:/tmp/mp origin/main` sobre
  `d4cb2d2d`. Todos os comandos rodam a partir dela. **Não mexa na pasta principal** (`Documents/vibe coding/Delivery`):
  outra sessão trabalha lá.
- **php-wasm** (já instalado): `PHP_WASM_DIR=/c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/0f61616a-8954-4e49-8c48-e9952984ad2b/scratchpad/php-wasm`.
  Nos comandos abaixo, `$PHPW` é esse caminho: comece cada comando com
  `export PHP_WASM_DIR=/c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/0f61616a-8954-4e49-8c48-e9952984ad2b/scratchpad/php-wasm;`.
- **Dependências do console:** `cd /c/tmp/mp/console && pnpm install --frozen-lockfile --prefer-offline` (Task 0). O
  `i18n-check` e o parse com o `@babel/parser` leem `console/node_modules/.pnpm` da própria worktree.
- **Commits:** sem push (o push é do Edgard). Toda mensagem termina com a linha
  `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`. Antes do primeiro commit de cada task, confira
  `git rev-parse --show-toplevel` = `C:/tmp/mp`.

## Mapa de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `api/app/Support/Entregas/SituacaoDoMotoboy.php` (mudar) | ganha `pedidosEmAndamento()`, a consulta única dos pedidos em andamento por motoboy |
| `api/app/Http/Controllers/Entregas/MapaController.php` (mudar) | mapa do console passa a usar `pedidosEmAndamento()` |
| `api/app/Support/Entregas/MotoboysNoMapaDaLoja.php` (novo) | lista do mapa do portal: visibilidade, situação, id opaco e pedidos da loja |
| `api/app/Http/Controllers/Entregas/PortalLojaController.php` (mudar) | rota `motoboysNoMapa()`; `motoboy()` sem posição; `donosDosPedidos()` |
| `api/app/Providers/RouteServiceProvider.php` (mudar) | rota `loja/motoboys` e limitador `entregas-loja-mapa` |
| `api/app/Support/Entregas/StatusDoPedido.php` (mudar) | só o docblock |
| `scripts/teste-php/mapa-da-loja.php` (novo) | testes php-wasm das três peças da API |
| `packages/customer-portal/addon/utils/motoboys-no-mapa.js` (novo) | funções puras: capacete, coordenada, distância, salto, interpolação, filtros |
| `packages/customer-portal/addon/utils/camada-de-motoboys.js` (novo) | marcadores no Leaflet: cria, desliza, salta, destaca e remove |
| `packages/customer-portal/addon/utils/entregas-pedido.js` (mudar) | `INTERVALO_MAPA_MS` |
| `packages/customer-portal/addon/services/customer-portal-order-route-preview.js` (mudar) | enquadramento com o motoboy do pedido aberto |
| `packages/customer-portal/addon/components/portal/order/workspace/map.js` (mudar) | ciclo de 5 s e camada de motoboys |
| `packages/customer-portal/addon/components/portal/order/details/motoboy.hbs` e `.js` (mudar) | sem o mapinha |
| `packages/customer-portal/addon/styles/customer-portal-engine.css` (mudar) | rótulo do nome e destaque |
| `packages/customer-portal/translations/en-us.yaml` e `pt-br.yaml` (mudar) | sai `courier-position` |
| `scripts/teste-portal/resolver.mjs` e `resolver-hook.mjs` (novos) | hook que completa `.js` nos imports relativos (só para os testes no Node) |
| `scripts/teste-portal/motoboys-no-mapa.test.mjs` e `camada-de-motoboys.test.mjs` (novos) | testes `node --test` |
| `scripts/teste-isolamento-lojas.mjs` (mudar) | item 9b |
| `CLAUDE.md` (mudar) | decisão, mapa de motoboys, riscos e histórico |

---

### Task 0: Preparar a worktree

**Files:** nenhum arquivo do repo.

- [ ] **Step 1: Branch e confirmação**

```bash
cd /c/tmp/mp && git switch -c motoboys-no-mapa && git rev-parse --show-toplevel && git log --oneline -2
```

Esperado: `C:/tmp/mp` e, no topo, o commit do desenho (`Desenho: motoboys no mapa do portal da loja…`) sobre `d4cb2d2d`.

- [ ] **Step 2: Dependências do console**

```bash
cd /c/tmp/mp/console && pnpm install --frozen-lockfile --prefer-offline 2>&1 | tail -3
ls -d node_modules/.pnpm/@babel+parser@7* node_modules/.pnpm/js-yaml@4* | head -3
```

Esperado: a instalação termina sem erro e as duas pastas existem.

- [ ] **Step 3: Script de parse com o Babel (fora do repo)**

```bash
cat > /c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/8f4e227e-f0ea-4b3d-a7ed-2deaa2b49efc/scratchpad/babel-check.cjs <<'EOF'
// Parse dos JS alterados com o @babel/parser do console (mesmos plugins do build): pega import duplicado e erro de sintaxe.
// Uso: node babel-check.cjs <arquivo.js>...  (na raiz da worktree)
const fs = require('fs');
const path = require('path');
const pnpm = path.resolve('console/node_modules/.pnpm');
const pasta = fs.readdirSync(pnpm).filter((d) => d.startsWith('@babel+parser@7')).sort().pop();
const parser = require(path.join(pnpm, pasta, 'node_modules/@babel/parser'));
let falhas = 0;
for (const arquivo of process.argv.slice(2)) {
    try {
        parser.parse(fs.readFileSync(arquivo, 'utf8'), { sourceType: 'module', plugins: ['decorators-legacy', 'classProperties'] });
        console.log('OK   ' + arquivo);
    } catch (erro) {
        falhas++;
        console.log('ERRO ' + arquivo + ': ' + erro.message);
    }
}
process.exit(falhas ? 1 : 0);
EOF
cd /c/tmp/mp && node /c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/8f4e227e-f0ea-4b3d-a7ed-2deaa2b49efc/scratchpad/babel-check.cjs packages/customer-portal/addon/components/portal/order/details.js
```

Esperado: `OK   packages/customer-portal/addon/components/portal/order/details.js`. Nos passos seguintes, `babel-check.cjs` é esse
arquivo.

- [ ] **Step 4: Linha de base dos testes PHP**

```bash
export PHP_WASM_DIR=/c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/0f61616a-8954-4e49-8c48-e9952984ad2b/scratchpad/php-wasm; cd /c/tmp/mp && node scripts/teste-php/rodar.mjs scripts/teste-php/mapa.php | tail -1
```

Esperado: `FALHAS: 0`.

---

### Task 1: Consulta compartilhada dos pedidos em andamento

**Files:**
- Create: `scripts/teste-php/mapa-da-loja.php`
- Modify: `api/app/Support/Entregas/SituacaoDoMotoboy.php`
- Modify: `api/app/Http/Controllers/Entregas/MapaController.php`

- [ ] **Step 1: Escrever o teste (stubs + seção 1)**

Crie `scripts/teste-php/mapa-da-loja.php`:

```php
<?php

// Mapa de motoboys do portal da loja: a consulta compartilhada com o mapa do console
// (SituacaoDoMotoboy::pedidosEmAndamento), a lista do portal (MotoboysNoMapaDaLoja) e as rotas do PortalLojaController
// (loja/motoboys e o motoboy do pedido, que deixou de mandar a posição).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/mapa-da-loja.php

namespace Illuminate\Support {
    class Collection implements \IteratorAggregate, \Countable, \JsonSerializable
    {
        public function __construct(protected array $itens = []) {}
        public function jsonSerialize(): mixed { return $this->itens; }
        public function all(): array { return $this->itens; }
        public function count(): int { return count($this->itens); }
        public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->itens); }
        public function pluck(string $campo): static { return new static(array_map(fn ($item) => $item->$campo ?? null, $this->itens)); }
    }

    class Carbon extends \DateTimeImmutable
    {
        public function subHours(int $horas): static { return $this->modify("-{$horas} hours"); }
        public function gte($outra): bool { return $this >= $outra; }
    }
}

namespace Teste {
    use Illuminate\Support\Collection;

    class NaoEncontrado extends \RuntimeException {}

    class Abortado extends \RuntimeException
    {
        public function __construct(public int $status, string $mensagem = '') { parent::__construct($mensagem); }
    }

    class Ponto
    {
        public function __construct(private float $lat, private float $lng) {}
        public function getLat(): float { return $this->lat; }
        public function getLng(): float { return $this->lng; }
    }

    /** where(fn ($q) => $q->where(...)->orWhere(...)): a linha passa se bater com qualquer condição do grupo. */
    class Grupo
    {
        private array $condicoes = [];
        public function where($coluna, $valor): static { $this->condicoes[] = [$coluna, $valor]; return $this; }
        public function orWhere($coluna, $valor): static { $this->condicoes[] = [$coluna, $valor]; return $this; }
        public function aceita($linha): bool
        {
            foreach ($this->condicoes as [$coluna, $valor]) {
                if (($linha->$coluna ?? null) === $valor) {
                    return true;
                }
            }

            return false;
        }
    }

    /** Consulta em memória: aplica os filtros que o código usa e registra as chamadas. */
    class Consulta
    {
        public static array $registro = [];

        public function __construct(private string $modelo, private array $linhas) {}

        private function anotar(string $chamada): static
        {
            static::$registro[$this->modelo][] = $chamada;

            return $this;
        }

        public function where($coluna, $operador = null, $valor = null): static
        {
            if ($coluna instanceof \Closure) {
                $grupo = new Grupo();
                $coluna($grupo);
                $this->linhas = array_filter($this->linhas, fn ($linha) => $grupo->aceita($linha));

                return $this->anotar('where (grupo)');
            }
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

        public function whereIn($coluna, $valores): static
        {
            $valores = $valores instanceof Collection ? $valores->all() : $valores;
            $this->linhas = array_filter($this->linhas, fn ($linha) => in_array($linha->$coluna ?? null, $valores, true));

            return $this->anotar("whereIn {$coluna}");
        }

        public function whereNotIn($coluna, array $valores): static
        {
            $this->linhas = array_filter($this->linhas, fn ($linha) => !in_array($linha->$coluna ?? null, $valores, true));

            return $this->anotar("whereNotIn {$coluna}");
        }

        public function with($relacoes): static { return $this->anotar('with ' . implode(',', (array) $relacoes)); }

        public function get(array $colunas = ['*']): Collection { return new Collection(array_values($this->linhas)); }

        public function firstOrFail()
        {
            $linha = reset($this->linhas);
            if ($linha === false) {
                throw new NaoEncontrado('não encontrado');
            }

            return $linha;
        }
    }

    abstract class Modelo
    {
        public static array $todos = [];

        public static function where($coluna, $operador = null, $valor = null)
        {
            return (new Consulta(static::class, static::$todos))->where(...func_get_args());
        }
    }

    class Resposta
    {
        public function __construct(public $dados) {}
    }

    class Fabrica
    {
        public function json($dados) { return new Resposta(json_decode(json_encode($dados), true)); }
    }
}

namespace Fleetbase\FleetOps\Models {
    #[\AllowDynamicProperties]
    class Vendor extends \Teste\Modelo { public static array $todos = []; }
    class Driver extends \Teste\Modelo { public static array $todos = []; }
    class Order extends \Teste\Modelo { public static array $todos = []; }
}

namespace App\Http\Controllers {
    class Controller {}
}

namespace App\Support\Entregas {
    // a loja do usuário da sessão: "usuario-a" é da Loja A (Vendor vendor-a, contato contato-a); o resto não tem loja
    class LojaDoUsuario
    {
        public static function vendor(?string $userUuid): ?\Fleetbase\FleetOps\Models\Vendor
        {
            if ($userUuid !== 'usuario-a') {
                return null;
            }
            $vendor       = new \Fleetbase\FleetOps\Models\Vendor();
            $vendor->uuid = 'vendor-a';

            return $vendor;
        }

        public static function contato(?string $userUuid): ?object
        {
            return $userUuid === 'usuario-a' ? (object) ['uuid' => 'contato-a'] : null;
        }
    }
}

namespace {
    use App\Http\Controllers\Entregas\PortalLojaController;
    use App\Support\Entregas\MotoboysNoMapaDaLoja;
    use App\Support\Entregas\SituacaoDoMotoboy as S;
    use Fleetbase\FleetOps\Models\Driver;
    use Fleetbase\FleetOps\Models\Order;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Collection;
    use Teste\Abortado;
    use Teste\NaoEncontrado;
    use Teste\Ponto;

    require '/repo/api/app/Support/Entregas/StatusDoPedido.php';
    require '/repo/api/app/Support/Entregas/SituacaoDoMotoboy.php';

    const EMPRESA = 'empresa-a';
    $agora   = new Carbon('2026-10-04 12:00:00');
    $recente = new Carbon('2026-10-04 11:30:00');
    $antigo  = new Carbon('2026-10-03 20:00:00');
    $sessao  = ['company' => EMPRESA, 'user' => 'usuario-a'];

    function session($chave) { global $sessao; return $sessao[$chave] ?? null; }
    function now() { global $agora; return $agora; }
    function response() { return new \Teste\Fabrica(); }
    function config($chave) { return $chave === 'app.key' ? 'base64:chave-de-teste' : null; }
    function abort_if($condicao, int $status, string $mensagem = ''): void
    {
        if ($condicao) {
            throw new Abortado($status, $mensagem);
        }
    }

    function pedido(string $motoboy, ?string $status, bool $aceito, string $id, string $dono, $atualizado, string $empresa = EMPRESA): object
    {
        return (object) [
            'company_uuid' => $empresa, 'driver_assigned_uuid' => $motoboy, 'status' => $status, 'started' => $aceito,
            'public_id' => $id, 'customer_uuid' => $dono, 'updated_at' => $atualizado,
        ];
    }

    function motoboy(string $uuid, bool $online, ?Ponto $local, string $nome, string $empresa = EMPRESA): object
    {
        static $n = 0;
        $n++;

        return (object) [
            'uuid' => $uuid, 'public_id' => 'driver_' . substr($uuid, 2), 'company_uuid' => $empresa, 'online' => $online ? 1 : 0,
            'location' => $local, 'name' => $nome, 'phone' => sprintf('+551699990%04d', $n), 'email' => "{$uuid}@teste.com",
        ];
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

    echo '== SituacaoDoMotoboy::pedidosEmAndamento (consulta do mapa do console e do portal)' . PHP_EOL;
    Order::$todos = [
        pedido('m-a', 'started', true, 'order_1', 'vendor-a', $recente),
        pedido('m-a', 'enroute', true, 'order_2', 'vendor-b', $recente),
        pedido('m-b', 'dispatched', false, 'order_3', 'vendor-a', $recente),
        pedido('m-b', 'completed', true, 'order_4', 'vendor-a', $recente),
        pedido('m-c', 'enroute', true, 'order_5', 'vendor-a', $antigo),
        pedido('m-fora', 'started', true, 'order_6', 'vendor-a', $recente),
        pedido('m-a', 'started', true, 'order_7', 'vendor-a', $recente, 'empresa-b'),
    ];
    $porMotoboy = S::pedidosEmAndamento(EMPRESA, new Collection(['m-a', 'm-b', 'm-c']));
    $ids        = fn (array $pedidos) => array_map(fn ($pedido) => $pedido->public_id, $pedidos);
    confere(array_keys($porMotoboy) === ['m-a', 'm-b'], 'agrupa pelo uuid do motoboy; sem pedido em andamento, o motoboy fica fora');
    confere($ids($porMotoboy['m-a'] ?? []) === ['order_1', 'order_2'], 'pedidos em andamento de qualquer loja, só da empresa');
    confere($ids($porMotoboy['m-b'] ?? []) === ['order_3'], 'pedido encerrado fica fora');
    confere(!isset($porMotoboy['m-c']), 'pedido parado há mais de 12 h fica fora');
    confere(($porMotoboy['m-b'][0]->started ?? null) === false && ($porMotoboy['m-a'][1]->customer_uuid ?? null) === 'vendor-b',
        'cada pedido traz started e customer_uuid (visibilidade e pedidos da loja)');
    confere(in_array('where company_uuid =', \Teste\Consulta::$registro[Order::class] ?? [], true), 'pedidos filtrados pela empresa');

    echo PHP_EOL . "FALHAS: {$falhas}" . PHP_EOL;
}
```

- [ ] **Step 2: Rodar e ver falhar**

```bash
export PHP_WASM_DIR=/c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/0f61616a-8954-4e49-8c48-e9952984ad2b/scratchpad/php-wasm; cd /c/tmp/mp && node scripts/teste-php/rodar.mjs scripts/teste-php/mapa-da-loja.php; echo "exit=$?"
```

Esperado: erro fatal `Call to undefined method App\Support\Entregas\SituacaoDoMotoboy::pedidosEmAndamento()` e `exit=1`.

- [ ] **Step 3: Implementar `pedidosEmAndamento()`**

Substitua o conteúdo de `api/app/Support/Entregas/SituacaoDoMotoboy.php` por:

```php
<?php

namespace App\Support\Entregas;

use Fleetbase\FleetOps\Models\Order;

/**
 * Entregas RestaurantePro: a situação do motoboy que vira a cor do capacete no mapa ao vivo do console
 * (MapaController@motoboys → packages/fleetops/addon/utils/entregas-capacete.js) e no mapa de motoboys do portal da loja
 * (MotoboysNoMapaDaLoja → packages/customer-portal/addon/utils/motoboys-no-mapa.js).
 *
 * - entrega (vermelho): tem pedido a caminho do cliente (o motoboy já tocou "a caminho", status enroute);
 * - coleta (amarelo): tem pedido aceito ou atribuído que ainda não saiu da loja (started, dispatched...);
 * - livre (verde): online e sem pedido em andamento;
 * - offline (cinza): fora do ar e sem pedido em andamento.
 * Pedido em andamento vale mais que o "online": o motoboy com entrega na mão continua ocupado mesmo se o app
 * desligar o online. Com mais de um pedido, vale o mais adiantado (entrega antes de coleta).
 */
class SituacaoDoMotoboy
{
    public const LIVRE   = 'livre';
    public const COLETA  = 'coleta';
    public const ENTREGA = 'entrega';
    public const OFFLINE = 'offline';

    /** Status de pedido que já saiu da loja (fluxo transport: created → dispatched → started → enroute → completed). */
    public const STATUS_EM_ENTREGA = ['enroute', 'picked_up', 'dropping_off', 'in_progress'];

    /** Pedido parado há mais que isto não ocupa o motoboy no mapa (pedido de teste ou esquecido sem concluir). */
    public const HORAS_PEDIDO_EM_ANDAMENTO = 12;

    /**
     * Pedidos em andamento de cada motoboy, agrupados pelo uuid dele: não encerrados e atualizados nas últimas
     * HORAS_PEDIDO_EM_ANDAMENTO horas, de qualquer loja da empresa. Consulta única do mapa do console e do portal.
     *
     * @param iterable<string> $motoboyUuids
     *
     * @return array<string, array<int, object>> uuid do motoboy => pedidos (driver_assigned_uuid, status, started, public_id, customer_uuid)
     */
    public static function pedidosEmAndamento(string $companyUuid, iterable $motoboyUuids): array
    {
        $pedidos = Order::where('company_uuid', $companyUuid)
            ->whereIn('driver_assigned_uuid', $motoboyUuids)
            ->whereNotIn('status', StatusDoPedido::ENCERRADOS)
            ->where('updated_at', '>=', now()->subHours(static::HORAS_PEDIDO_EM_ANDAMENTO))
            ->get(['driver_assigned_uuid', 'status', 'started', 'public_id', 'customer_uuid']);

        $porMotoboy = [];
        foreach ($pedidos as $pedido) {
            $porMotoboy[$pedido->driver_assigned_uuid][] = $pedido;
        }

        return $porMotoboy;
    }

    /**
     * @param iterable<string|null> $statusDosPedidos status dos pedidos atribuídos ao motoboy (orders.status)
     */
    public static function classificar(bool $online, iterable $statusDosPedidos): string
    {
        $situacao = $online ? static::LIVRE : static::OFFLINE;

        foreach ($statusDosPedidos as $status) {
            $status = strtolower((string) $status);
            if (in_array($status, StatusDoPedido::ENCERRADOS, true)) {
                continue;
            }
            if (in_array($status, static::STATUS_EM_ENTREGA, true)) {
                return static::ENTREGA;
            }
            $situacao = static::COLETA;
        }

        return $situacao;
    }
}
```

- [ ] **Step 4: O mapa do console passa a usar a consulta compartilhada**

Em `api/app/Http/Controllers/Entregas/MapaController.php`:

1. Apague as linhas `use App\Support\Entregas\StatusDoPedido;` e `use Fleetbase\FleetOps\Models\Order;` (ficam sem uso).
2. Substitua o método `motoboys()` inteiro por:

```php
    public function motoboys()
    {
        $motoboys = Driver::where('company_uuid', session('company'))
            ->applyDirectivesForPermissions('fleet-ops list driver')
            ->get(['uuid', 'public_id', 'online']);

        $pedidosPorMotoboy = SituacaoDoMotoboy::pedidosEmAndamento(session('company'), $motoboys->pluck('uuid'));

        return response()->json([
            'motoboys' => $motoboys->map(fn ($motoboy) => [
                'uuid'      => $motoboy->uuid,
                'public_id' => $motoboy->public_id,
                // array_map, e não array_column: com o model do Eloquent, o array_column pula status nulo
                'situacao'  => SituacaoDoMotoboy::classificar(
                    (bool) $motoboy->online,
                    array_map(fn ($pedido) => $pedido->status, $pedidosPorMotoboy[$motoboy->uuid] ?? [])
                ),
            ])->values(),
        ]);
    }
```

- [ ] **Step 5: Rodar os dois testes**

```bash
export PHP_WASM_DIR=/c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/0f61616a-8954-4e49-8c48-e9952984ad2b/scratchpad/php-wasm; cd /c/tmp/mp && node scripts/teste-php/rodar.mjs scripts/teste-php/mapa-da-loja.php | tail -3 && node scripts/teste-php/rodar.mjs scripts/teste-php/mapa.php | tail -1
```

Esperado: as linhas `PASSA …` da seção e `FALHAS: 0` nos dois.

- [ ] **Step 6: Commit**

```bash
cd /c/tmp/mp && git add scripts/teste-php/mapa-da-loja.php api/app/Support/Entregas/SituacaoDoMotoboy.php api/app/Http/Controllers/Entregas/MapaController.php && git commit -q -F - <<'EOF'
API: pedidos em andamento por motoboy numa consulta só (mapa do console e do portal)

SituacaoDoMotoboy::pedidosEmAndamento agrupa os pedidos não encerrados das
últimas 12 h pelo motoboy, com started, public_id e customer_uuid. O
MapaController passa a usá-la; teste novo em scripts/teste-php/mapa-da-loja.php.

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>
EOF
```

---

### Task 2: Lista do mapa do portal (`MotoboysNoMapaDaLoja`)

**Files:**
- Create: `api/app/Support/Entregas/MotoboysNoMapaDaLoja.php`
- Modify: `scripts/teste-php/mapa-da-loja.php`

- [ ] **Step 1: Escrever o teste (seção 2)**

Em `scripts/teste-php/mapa-da-loja.php`, insira antes da linha `    echo PHP_EOL . "FALHAS: {$falhas}" . PHP_EOL;`:

```php
    echo '== MotoboysNoMapaDaLoja::listar (mapa do portal da loja)' . PHP_EOL;
    require '/repo/api/app/Support/Entregas/MotoboysNoMapaDaLoja.php';

    $naRua = new Ponto(-21.1702, -47.8101);
    Driver::$todos = [
        motoboy('m-livre', true, $naRua, 'Livre'),
        motoboy('m-coleta', true, new Ponto(-21.18, -47.82), 'Coleta'),
        motoboy('m-entrega', true, new Ponto(-21.19, -47.83), 'Entrega'),
        motoboy('m-off', false, $naRua, 'Offline'),
        motoboy('m-off-atribuido', false, $naRua, 'Offline atribuído'),
        motoboy('m-off-aceito', false, new Ponto(-21.2, -47.84), 'Offline com pedido aceito'),
        motoboy('m-sem-gps', true, new Ponto(0.0, 0.0), 'Sem GPS'),
        motoboy('m-sem-local', true, null, 'Sem local'),
        motoboy('m-velho', true, $naRua, 'Pedido velho'),
        motoboy('m-contato', true, $naRua, 'Pedido do contato'),
        motoboy('m-outra-empresa', true, $naRua, 'Outra empresa', 'empresa-b'),
    ];
    Order::$todos = [
        pedido('m-coleta', 'started', true, 'order_a1', 'vendor-a', $recente),
        pedido('m-entrega', 'enroute', true, 'order_b1', 'vendor-b', $recente),
        pedido('m-off-atribuido', 'dispatched', false, 'order_a2', 'vendor-a', $recente),
        pedido('m-off-aceito', 'started', true, 'order_b2', 'vendor-b', $recente),
        pedido('m-velho', 'enroute', true, 'order_a3', 'vendor-a', $antigo),
        pedido('m-contato', 'started', true, 'order_c1', 'contato-a', $recente),
        pedido('m-livre', 'completed', true, 'order_a4', 'vendor-a', $recente),
    ];
    $lista   = MotoboysNoMapaDaLoja::listar(EMPRESA, ['vendor-a', 'contato-a']);
    $porNome = array_column($lista, null, 'nome');
    confere(array_keys($porNome) === ['Livre', 'Coleta', 'Entrega', 'Offline com pedido aceito', 'Pedido velho', 'Pedido do contato'],
        'aparecem os online e o offline com pedido aceito; sem coordenada e outra empresa ficam fora');
    confere(!isset($porNome['Offline']) && !isset($porNome['Offline atribuído']),
        'offline sem pedido aceito fica fora, mesmo com pedido só atribuído (a última posição pode ser a casa)');
    confere(array_column($lista, 'situacao', 'nome') === [
        'Livre' => 'livre', 'Coleta' => 'coleta', 'Entrega' => 'entrega',
        'Offline com pedido aceito' => 'coleta', 'Pedido velho' => 'livre', 'Pedido do contato' => 'coleta',
    ], 'situação pela regra do console; pedido parado há mais de 12 h não conta');
    confere(array_column($lista, 'pedidos', 'nome') === [
        'Livre' => [], 'Coleta' => ['order_a1'], 'Entrega' => [],
        'Offline com pedido aceito' => [], 'Pedido velho' => [], 'Pedido do contato' => ['order_c1'],
    ], 'pedidos: só os da loja da sessão (Vendor e contato do usuário), nunca os de outra loja');
    confere(array_keys($lista[0] ?? []) === ['id', 'nome', 'latitude', 'longitude', 'situacao', 'pedidos'],
        'só id, nome, latitude, longitude, situação e pedidos');
    confere(($porNome['Livre']['latitude'] ?? null) === -21.1702 && ($porNome['Livre']['longitude'] ?? null) === -47.8101, 'coordenadas em número');
    $json  = json_encode($lista);
    $vazou = array_filter(Driver::$todos, fn ($m) => str_contains($json, $m->uuid) || str_contains($json, $m->public_id) || str_contains($json, $m->phone));
    confere($vazou === [] && !str_contains($json, 'driver_') && !str_contains($json, '@teste.com'), 'sem uuid, public_id, telefone nem e-mail do motoboy');
    confere(preg_match('/^[0-9a-f]{16}$/', $porNome['Livre']['id'] ?? '') === 1, 'id opaco de 16 caracteres hexadecimais');
    confere(($porNome['Livre']['id'] ?? null) === substr(hash_hmac('sha256', 'm-livre', 'base64:chave-de-teste'), 0, 16), 'id = HMAC do uuid com a chave do app');
    confere(count(array_unique(array_column($lista, 'id'))) === count($lista), 'um id diferente por motoboy');
    confere((MotoboysNoMapaDaLoja::listar(EMPRESA, ['vendor-a', 'contato-a'])[0]['id'] ?? null) === ($lista[0]['id'] ?? false), 'id estável entre consultas');
    confere(in_array('where company_uuid =', \Teste\Consulta::$registro[Driver::class] ?? [], true), 'motoboys filtrados pela empresa');
    confere(in_array('with user', \Teste\Consulta::$registro[Driver::class] ?? [], true), 'usuário do motoboy (nome) carregado junto');
```

- [ ] **Step 2: Rodar e ver falhar**

```bash
export PHP_WASM_DIR=/c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/0f61616a-8954-4e49-8c48-e9952984ad2b/scratchpad/php-wasm; cd /c/tmp/mp && node scripts/teste-php/rodar.mjs scripts/teste-php/mapa-da-loja.php; echo "exit=$?"
```

Esperado: erro fatal no `require` de `MotoboysNoMapaDaLoja.php` (arquivo não existe) e `exit=1`.

- [ ] **Step 3: Implementar**

Crie `api/app/Support/Entregas/MotoboysNoMapaDaLoja.php`:

```php
<?php

namespace App\Support\Entregas;

use Fleetbase\FleetOps\Models\Driver;

/**
 * Entregas RestaurantePro: os motoboys no mapa do portal da loja (PortalLojaController@motoboysNoMapa, consultado a cada
 * 5 s com o mapa aberto).
 *
 * Decisão de 2026-10-04: a loja vê todos os motoboys online, com a cor da situação (a regra do mapa do console,
 * SituacaoDoMotoboy) e o nome, inclusive os que levam pedidos de outras lojas. Aparece também o offline que já aceitou um
 * pedido ainda em andamento (está trabalhando). Some o offline sem pedido aceito, mesmo com pedido só atribuído pela
 * central (a última posição dele pode ser a casa), e quem não tem coordenada válida.
 *
 * Nunca sai daqui o id do motoboy (public_id ou uuid: com ele, o canal driver.<id> do socket entrega a posição ao vivo e o
 * telefone), nem telefone, e-mail, veículo ou pedido de outra loja: o `id` é um HMAC do uuid com a chave do app, e
 * `pedidos` traz só os pedidos dos donos informados (a loja da sessão e o contato do usuário).
 */
class MotoboysNoMapaDaLoja
{
    /**
     * @param array<int, string> $donos customer_uuid dos pedidos da loja: o Vendor e o contato do usuário
     *
     * @return array<int, array{id: string, nome: ?string, latitude: float, longitude: float, situacao: string, pedidos: array<int, string>}>
     */
    public static function listar(string $companyUuid, array $donos): array
    {
        // o nome do motoboy vem do usuário dele: carregado junto, e não um a um dentro do accessor
        $motoboys          = Driver::where('company_uuid', $companyUuid)->with('user')->get();
        $pedidosPorMotoboy = SituacaoDoMotoboy::pedidosEmAndamento($companyUuid, $motoboys->pluck('uuid'));

        $lista = [];
        foreach ($motoboys as $motoboy) {
            $pedidos = $pedidosPorMotoboy[$motoboy->uuid] ?? [];
            $online  = (bool) $motoboy->online;

            // offline só aparece trabalhando: com um pedido aceito (started) ainda em andamento
            if (!$online && !static::aceitouAlgum($pedidos)) {
                continue;
            }

            $latitude  = $motoboy->location?->getLat();
            $longitude = $motoboy->location?->getLng();
            if (!static::coordenadaValida($latitude, $longitude)) {
                continue;
            }

            // array_map, e não array_column: com o model do Eloquent, o array_column pula status nulo
            $situacao = SituacaoDoMotoboy::classificar($online, array_map(fn ($pedido) => $pedido->status, $pedidos));
            if ($situacao === SituacaoDoMotoboy::OFFLINE) {
                continue;
            }

            $daLoja = array_filter($pedidos, fn ($pedido) => in_array($pedido->customer_uuid, $donos, true));

            $lista[] = [
                'id'        => static::idOpaco($motoboy->uuid),
                'nome'      => $motoboy->name,
                'latitude'  => (float) $latitude,
                'longitude' => (float) $longitude,
                'situacao'  => $situacao,
                'pedidos'   => array_values(array_map(fn ($pedido) => $pedido->public_id, $daLoja)),
            ];
        }

        return $lista;
    }

    /** Id estável para o portal saber qual capacete mover, sem permitir chegar ao motoboy (nem ao canal do socket). */
    public static function idOpaco(string $uuid): string
    {
        return substr(hash_hmac('sha256', $uuid, (string) config('app.key')), 0, 16);
    }

    protected static function aceitouAlgum(array $pedidos): bool
    {
        foreach ($pedidos as $pedido) {
            if ($pedido->started) {
                return true;
            }
        }

        return false;
    }

    /** Números dentro da faixa e fora do (0, 0), que é o "sem GPS" do Fleetbase (mesmo critério do CalculoEntregas). */
    protected static function coordenadaValida($latitude, $longitude): bool
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

- [ ] **Step 4: Rodar e ver passar**

```bash
export PHP_WASM_DIR=/c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/0f61616a-8954-4e49-8c48-e9952984ad2b/scratchpad/php-wasm; cd /c/tmp/mp && node scripts/teste-php/rodar.mjs scripts/teste-php/mapa-da-loja.php | tail -16
```

Esperado: todas as linhas `PASSA` e `FALHAS: 0`.

- [ ] **Step 5: Commit**

```bash
cd /c/tmp/mp && git add api/app/Support/Entregas/MotoboysNoMapaDaLoja.php scripts/teste-php/mapa-da-loja.php && git commit -q -F - <<'EOF'
API: lista do mapa de motoboys do portal (online e offline com pedido aceito)

MotoboysNoMapaDaLoja monta id opaco (HMAC do uuid), nome, coordenadas,
situação (regra do console) e os pedidos da própria loja; offline sem pedido
aceito e coordenada inválida ficam fora. Nunca sai id, telefone nem e-mail.

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>
EOF
```

---

### Task 3: Rota `loja/motoboys` e motoboy do pedido sem posição

**Files:**
- Modify: `api/app/Http/Controllers/Entregas/PortalLojaController.php`
- Modify: `api/app/Providers/RouteServiceProvider.php`
- Modify: `api/app/Support/Entregas/StatusDoPedido.php` (docblock)
- Modify: `scripts/teste-php/mapa-da-loja.php`

- [ ] **Step 1: Escrever o teste (seção 3)**

Em `scripts/teste-php/mapa-da-loja.php`, insira antes da linha `    echo PHP_EOL . "FALHAS: {$falhas}" . PHP_EOL;`:

```php
    echo '== PortalLojaController (rota loja/motoboys e motoboy do pedido sem posição)' . PHP_EOL;
    require '/repo/api/app/Http/Controllers/Entregas/PortalLojaController.php';

    $controller = new PortalLojaController();
    $sessao     = ['company' => EMPRESA, 'user' => 'usuario-a'];
    $resposta   = $controller->motoboysNoMapa()->dados;
    confere(array_keys($resposta) === ['motoboys'], 'resposta { motoboys: [...] }');
    confere($resposta['motoboys'] === json_decode(json_encode(MotoboysNoMapaDaLoja::listar(EMPRESA, ['vendor-a', 'contato-a'])), true),
        'a lista do MotoboysNoMapaDaLoja, com a empresa da sessão e os donos da loja (Vendor e contato do usuário)');

    $sessao = ['company' => EMPRESA, 'user' => 'usuario-sem-loja'];
    try {
        $controller->motoboysNoMapa();
        confere(false, 'usuário sem loja recebe 404');
    } catch (Abortado $erro) {
        confere($erro->status === 404, 'usuário sem loja recebe 404');
    }

    $sessao = ['company' => EMPRESA, 'user' => 'usuario-a'];
    $doMapa = (object) ['name' => 'Coleta', 'photo_url' => 'https://foto.teste/coleta.jpg', 'location' => new Ponto(-21.18, -47.82)];
    Order::$todos = [
        (object) ['company_uuid' => EMPRESA, 'customer_uuid' => 'vendor-a', 'public_id' => 'order_a1', 'uuid' => 'uuid-a1', 'status' => 'started', 'started' => true, 'started_at' => $recente, 'driverAssigned' => $doMapa],
        (object) ['company_uuid' => EMPRESA, 'customer_uuid' => 'vendor-a', 'public_id' => 'order_a9', 'uuid' => 'uuid-a9', 'status' => 'completed', 'started' => true, 'started_at' => $recente, 'driverAssigned' => $doMapa],
        (object) ['company_uuid' => EMPRESA, 'customer_uuid' => 'vendor-b', 'public_id' => 'order_b1', 'uuid' => 'uuid-b1', 'status' => 'started', 'started' => true, 'started_at' => $recente, 'driverAssigned' => $doMapa],
    ];
    confere(($controller->motoboy('order_a1')->dados['motoboy'] ?? null) === ['nome' => 'Coleta', 'foto' => 'https://foto.teste/coleta.jpg', 'aceitou' => true],
        'motoboy do pedido: nome, foto e aceite, sem latitude e longitude');
    confere(($controller->motoboy('uuid-a1')->dados['motoboy']['nome'] ?? null) === 'Coleta', 'pedido também pelo uuid');
    $encerrado = $controller->motoboy('order_a9')->dados;
    confere(array_key_exists('motoboy', $encerrado) && $encerrado['motoboy'] === null, 'pedido encerrado: motoboy null');
    try {
        $controller->motoboy('order_b1');
        confere(false, 'pedido de outra loja: 404');
    } catch (NaoEncontrado) {
        confere(true, 'pedido de outra loja: 404');
    }
    confere(!defined(PortalLojaController::class . '::HORAS_POSICAO'), 'sem a regra das 4 h (HORAS_POSICAO)');
```

- [ ] **Step 2: Rodar e ver falhar**

```bash
export PHP_WASM_DIR=/c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/0f61616a-8954-4e49-8c48-e9952984ad2b/scratchpad/php-wasm; cd /c/tmp/mp && node scripts/teste-php/rodar.mjs scripts/teste-php/mapa-da-loja.php; echo "exit=$?"
```

Esperado: erro fatal `Call to undefined method App\Http\Controllers\Entregas\PortalLojaController::motoboysNoMapa()` e
`exit=1`.

- [ ] **Step 3: Controller**

Em `api/app/Http/Controllers/Entregas/PortalLojaController.php`:

1. Depois de `use App\Support\Entregas\LojaDoUsuario;`, acrescente `use App\Support\Entregas\MotoboysNoMapaDaLoja;`.
2. Apague o bloco:

```php
    /** A loja só vê a posição do motoboy até estas horas depois do aceite (LGPD). */
    public const HORAS_POSICAO = 4;

```

3. Substitua o método `motoboy()` inteiro (do docblock `/** Motoboy do pedido em andamento …` até o `}` que fecha o
   método, antes de `protected function lojaDaSessao()`) por:

```php
    /** Motoboy do pedido em andamento (nome, foto e aceite). Só pedido que o portal mostra a este usuário. */
    public function motoboy(string $id)
    {
        $vendor = $this->lojaDaSessao();

        // name e photo_url do Driver leem o usuário dele e o avatar do usuário: vêm junto, e não sob demanda dentro dos accessors
        $pedido = Order::where('company_uuid', session('company'))
            ->whereIn('customer_uuid', $this->donosDosPedidos($vendor))
            ->where(fn ($q) => $q->where('public_id', $id)->orWhere('uuid', $id))
            ->with('driverAssigned.user.avatar')
            ->firstOrFail();

        $motoboy = $pedido->driverAssigned;
        if (!$motoboy || in_array($pedido->status, StatusDoPedido::ENCERRADOS, true)) {
            return response()->json(['motoboy' => null]);
        }

        // a posição fica no mapa de motoboys (motoboysNoMapa), que mostra todos os motoboys online
        return response()->json([
            'motoboy' => [
                'nome'    => $motoboy->name,
                'foto'    => $motoboy->photo_url,
                'aceitou' => (bool) $pedido->started,
            ],
        ]);
    }

    /**
     * Motoboys no mapa do portal (todos os online e os offline com pedido aceito), consultado a cada 5 s com o mapa aberto.
     * Sem id, telefone nem pedido de outra loja (MotoboysNoMapaDaLoja).
     */
    public function motoboysNoMapa()
    {
        $vendor = $this->lojaDaSessao();

        return response()->json([
            'motoboys' => MotoboysNoMapaDaLoja::listar(session('company'), $this->donosDosPedidos($vendor)),
        ]);
    }

    /**
     * Os donos de pedido que o portal mostra a este usuário (PortalOrderService::accountCustomerUuids): a loja e o contato
     * do usuário (a central pode lançar o pedido no contato).
     *
     * @return array<int, string>
     */
    protected function donosDosPedidos(Vendor $vendor): array
    {
        return array_values(array_filter([
            $vendor->uuid,
            LojaDoUsuario::contato(session('user'))?->uuid,
        ]));
    }
```

- [ ] **Step 4: Rota e limitador**

Em `api/app/Providers/RouteServiceProvider.php`, troque

```php
        RateLimiter::for('entregas-motoboy', fn (Request $request) => Limit::perMinute(60)->by('entregas-motoboy:' . (session('user') ?: $request->ip())));
```

por

```php
        RateLimiter::for('entregas-motoboy', fn (Request $request) => Limit::perMinute(60)->by('entregas-motoboy:' . (session('user') ?: $request->ip())));

        // Entregas RestaurantePro: mapa de motoboys do portal da loja (cada aba com o mapa aberto consulta a cada 5 s), até 60
        // chamadas por minuto por usuário, num balde separado do throttle:60,1 que as outras rotas da loja dividem
        RateLimiter::for('entregas-loja-mapa', fn (Request $request) => Limit::perMinute(60)->by('entregas-loja-mapa:' . (session('user') ?: $request->ip())));
```

e troque

```php
                        Route::get('loja/pedidos/{id}/motoboy', [PortalLojaController::class, 'motoboy'])->middleware('throttle:60,1');
```

por

```php
                        Route::get('loja/pedidos/{id}/motoboy', [PortalLojaController::class, 'motoboy'])->middleware('throttle:60,1');
                        Route::get('loja/motoboys', [PortalLojaController::class, 'motoboysNoMapa'])->middleware('throttle:entregas-loja-mapa');
```

- [ ] **Step 5: Docblock do `StatusDoPedido`**

Em `api/app/Support/Entregas/StatusDoPedido.php`, troque o docblock da classe por:

```php
/**
 * Entregas RestaurantePro: os status de pedido (orders.status, gravados pelo Fleet-Ops) que as regras do Entregas
 * conferem, numa lista só: o cancelamento pela loja (RegrasPortalLoja), o aceite do motoboy
 * (BarrarAceiteDePedidoEncerrado), o motoboy do pedido no portal (PortalLojaController), os pedidos em andamento dos
 * mapas de motoboys (SituacaoDoMotoboy) e o reenvio do aviso de pedido aberto (ReenviarPedidosAbertos).
 */
```

- [ ] **Step 6: Rodar os testes e a sintaxe**

```bash
export PHP_WASM_DIR=/c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/0f61616a-8954-4e49-8c48-e9952984ad2b/scratchpad/php-wasm; cd /c/tmp/mp && node scripts/teste-php/rodar.mjs scripts/teste-php/mapa-da-loja.php | tail -10 && node scripts/teste-php/rodar.mjs scripts/teste-php/mapa.php | tail -1 && node scripts/teste-php/sintaxe.mjs api/app/Providers/RouteServiceProvider.php api/app/Http/Controllers/Entregas/PortalLojaController.php api/app/Http/Controllers/Entregas/MapaController.php api/app/Support/Entregas/SituacaoDoMotoboy.php api/app/Support/Entregas/MotoboysNoMapaDaLoja.php api/app/Support/Entregas/StatusDoPedido.php
```

Esperado: `FALHAS: 0` nos dois testes e a sintaxe sem erro (exit 0).

- [ ] **Step 7: Commit**

```bash
cd /c/tmp/mp && git add api/app/Http/Controllers/Entregas/PortalLojaController.php api/app/Providers/RouteServiceProvider.php api/app/Support/Entregas/StatusDoPedido.php scripts/teste-php/mapa-da-loja.php && git commit -q -F - <<'EOF'
API: rota loja/motoboys do portal; motoboy do pedido sem posição

GET int/v1/entregas/loja/motoboys devolve o mapa de motoboys da loja, com
limitador próprio (60/min por usuário). loja/pedidos/{id}/motoboy deixa de
mandar latitude e longitude (sai a regra das 4 h): a posição fica no mapa.

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>
EOF
```

---

### Task 4: Funções puras do mapa no portal

**Files:**
- Create: `scripts/teste-portal/resolver.mjs`
- Create: `scripts/teste-portal/resolver-hook.mjs`
- Create: `scripts/teste-portal/motoboys-no-mapa.test.mjs`
- Create: `packages/customer-portal/addon/utils/motoboys-no-mapa.js`
- Modify: `packages/customer-portal/addon/utils/entregas-pedido.js`

- [ ] **Step 1: Hook de resolução dos testes**

Crie `scripts/teste-portal/resolver.mjs`:

```js
// Os utils do portal importam uns aos outros sem extensão (como o build do Ember espera); no Node, o import precisa do .js.
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
import { register } from 'node:module';

register('./resolver-hook.mjs', import.meta.url);
```

Crie `scripts/teste-portal/resolver-hook.mjs`:

```js
// Completa o .js dos imports relativos sem extensão (registrado pelo resolver.mjs).
export async function resolve(especificador, contexto, proximo) {
    if (/^\.\.?\//.test(especificador) && !/\.[cm]?js$/.test(especificador)) {
        try {
            return await proximo(`${especificador}.js`, contexto);
        } catch {
            // segue com o especificador original
        }
    }

    return proximo(especificador, contexto);
}
```

- [ ] **Step 2: Escrever o teste**

Crie `scripts/teste-portal/motoboys-no-mapa.test.mjs`:

```js
// Funções puras do mapa de motoboys do portal (packages/customer-portal/addon/utils/motoboys-no-mapa.js).
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    CAPACETES,
    DESLIZE_MS,
    SALTO_METROS,
    capaceteDaSituacao,
    coordenadaValida,
    distanciaEmMetros,
    deveSaltar,
    interpolar,
    motoboysValidos,
    motoboyDoPedido,
} from '../../packages/customer-portal/addon/utils/motoboys-no-mapa.js';
import { INTERVALO_MAPA_MS } from '../../packages/customer-portal/addon/utils/entregas-pedido.js';

test('capacete de cada situação, com as imagens do mapa do console', () => {
    assert.equal(capaceteDaSituacao('livre'), '/engines-dist/images/capacete-verde.png');
    assert.equal(capaceteDaSituacao('coleta'), '/engines-dist/images/capacete-amarelo.png');
    assert.equal(capaceteDaSituacao('entrega'), '/engines-dist/images/capacete-vermelho.png');
    assert.equal(capaceteDaSituacao('offline'), null, 'offline não aparece no portal');
    assert.equal(capaceteDaSituacao(undefined), null);
    assert.deepEqual(Object.keys(CAPACETES), ['livre', 'coleta', 'entrega']);
});

test('coordenada válida: número finito, dentro da faixa e fora do (0, 0)', () => {
    assert.equal(coordenadaValida(-21.17, -47.81), true);
    assert.equal(coordenadaValida('-21.17', '-47.81'), true, 'texto numérico vale');
    assert.equal(coordenadaValida(0, 0), false, '(0, 0) é o sem GPS do Fleetbase');
    assert.equal(coordenadaValida(0.00005, -0.00005), false);
    assert.equal(coordenadaValida(null, -47.81), false);
    assert.equal(coordenadaValida('', -47.81), false);
    assert.equal(coordenadaValida(-21.17, undefined), false);
    assert.equal(coordenadaValida(91, 10), false);
    assert.equal(coordenadaValida(10, 181), false);
    assert.equal(coordenadaValida(Number.NaN, 10), false);
    assert.equal(coordenadaValida('abc', 10), false);
});

test('distância em metros (haversine)', () => {
    assert.equal(distanciaEmMetros([-21.17, -47.81], [-21.17, -47.81]), 0);
    // 0,01° de latitude ≈ 1.112 m
    const metros = distanciaEmMetros([-21.17, -47.81], [-21.18, -47.81]);
    assert.ok(metros > 1100 && metros < 1125, `esperava ~1.112 m, veio ${metros}`);
});

test('salto ou deslize', () => {
    const aqui = [-21.17, -47.81];
    const perto = [-21.1705, -47.81]; // ~56 m
    const longe = [-21.18, -47.81]; // ~1,1 km
    assert.equal(deveSaltar(null, aqui), true, 'primeira posição salta');
    assert.equal(deveSaltar(aqui, perto), false, 'perto desliza');
    assert.equal(deveSaltar(aqui, longe), true, `mais de ${SALTO_METROS} m salta`);
    assert.equal(deveSaltar(aqui, perto, { reduzirMovimento: true }), true, 'menos animação: salta');
});

test('interpolação em linha reta, com a fração limitada a 0..1', () => {
    assert.deepEqual(interpolar([0, 0], [10, 20], 0), [0, 0]);
    assert.deepEqual(interpolar([0, 0], [10, 20], 0.5), [5, 10]);
    assert.deepEqual(interpolar([0, 0], [10, 20], 1), [10, 20]);
    assert.deepEqual(interpolar([0, 0], [10, 20], 1.7), [10, 20]);
    assert.deepEqual(interpolar([0, 0], [10, 20], -1), [0, 0]);
});

test('motoboys válidos: com id, situação conhecida e coordenada válida', () => {
    const lista = [
        { id: 'a1', nome: 'Ana', latitude: -21.17, longitude: -47.81, situacao: 'livre', pedidos: [] },
        { id: 'b2', nome: 'Bia', latitude: 0, longitude: 0, situacao: 'livre', pedidos: [] },
        { id: 'c3', nome: 'Caio', latitude: -21.18, longitude: -47.82, situacao: 'offline', pedidos: [] },
        { id: '', nome: 'Sem id', latitude: -21.18, longitude: -47.82, situacao: 'coleta', pedidos: [] },
        null,
        { id: 'd4', nome: 'Davi', latitude: '-21.19', longitude: '-47.83', situacao: 'entrega', pedidos: ['order_x'] },
    ];
    assert.deepEqual(
        motoboysValidos(lista).map((motoboy) => motoboy.id),
        ['a1', 'd4']
    );
    assert.deepEqual(motoboysValidos(undefined), []);
    assert.deepEqual(motoboysValidos({ motoboys: [] }), []);
});

test('motoboy do pedido aberto, pelo public_id em pedidos', () => {
    const lista = [{ id: 'a1', pedidos: [] }, { id: 'b2', pedidos: ['order_x', 'order_y'] }, { id: 'c3' }];
    assert.equal(motoboyDoPedido(lista, 'order_y')?.id, 'b2');
    assert.equal(motoboyDoPedido(lista, 'order_z'), null);
    assert.equal(motoboyDoPedido(lista, null), null);
    assert.equal(motoboyDoPedido(undefined, 'order_x'), null);
});

test('intervalos: consulta de 5 s e deslize um pouco menor', () => {
    assert.equal(INTERVALO_MAPA_MS, 5000);
    assert.ok(DESLIZE_MS < INTERVALO_MAPA_MS);
});
```

- [ ] **Step 3: Rodar e ver falhar**

```bash
cd /c/tmp/mp && node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/motoboys-no-mapa.test.mjs 2>&1 | tail -8
```

Esperado: falha com `Cannot find module …/utils/motoboys-no-mapa.js` (`# fail 1` ou erro de carregamento).

- [ ] **Step 4: Implementar o util**

Crie `packages/customer-portal/addon/utils/motoboys-no-mapa.js`:

```js
// Entregas: funções puras do mapa de motoboys do portal da loja (capacete por situação, coordenada válida, distância,
// salto ou deslize, interpolação e motoboy do pedido aberto). Sem Ember e sem imports: os testes rodam no Node
// (scripts/teste-portal/motoboys-no-mapa.test.mjs).

/**
 * Capacete de cada situação: a mesma regra e as mesmas imagens do mapa do console (utils/entregas-capacete.js do
 * Fleet-Ops, que publica os PNGs em /engines-dist/images). Offline não aparece no portal. Caminhos literais e completos:
 * o build troca cada um pelo nome com hash (fingerprint); montado por partes, não troca.
 */
export const CAPACETES = {
    livre: '/engines-dist/images/capacete-verde.png',
    coleta: '/engines-dist/images/capacete-amarelo.png',
    entrega: '/engines-dist/images/capacete-vermelho.png',
};

/** Acima disto (em metros) o capacete salta em vez de deslizar: GPS voltando depois de um tempo sem sinal. */
export const SALTO_METROS = 1000;

/** Duração do deslize: um pouco menor que o intervalo da consulta (5 s), para o capacete chegar antes da próxima posição. */
export const DESLIZE_MS = 4500;

export function capaceteDaSituacao(situacao) {
    return CAPACETES[situacao] ?? null;
}

function vazio(valor) {
    return valor === null || valor === undefined || valor === '';
}

/** Coordenada que dá para pôr no mapa: números finitos, dentro da faixa e fora do (0, 0), que é o "sem GPS" do Fleetbase. */
export function coordenadaValida(latitude, longitude) {
    if (vazio(latitude) || vazio(longitude)) {
        return false;
    }

    const lat = Number(latitude);
    const lng = Number(longitude);

    if (!Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) {
        return false;
    }

    return !(Math.abs(lat) <= 0.0001 && Math.abs(lng) <= 0.0001);
}

/** Distância em metros entre dois pontos [lat, lng] (haversine). */
export function distanciaEmMetros([lat1, lng1], [lat2, lng2]) {
    const raio = 6371000;
    const radianos = (graus) => (graus * Math.PI) / 180;
    const dLat = radianos(lat2 - lat1);
    const dLng = radianos(lng2 - lng1);
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(radianos(lat1)) * Math.cos(radianos(lat2)) * Math.sin(dLng / 2) ** 2;

    return 2 * raio * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

/** Salta (sem deslizar) na primeira posição, em pulos de mais de SALTO_METROS e quando o sistema pede menos animação. */
export function deveSaltar(de, para, { reduzirMovimento = false } = {}) {
    if (!de || reduzirMovimento) {
        return true;
    }

    return distanciaEmMetros(de, para) > SALTO_METROS;
}

/** Ponto entre `de` e `para` na fração do deslize (limitada a 0..1), em linha reta. */
export function interpolar(de, para, fracao) {
    const f = Math.min(1, Math.max(0, fracao));

    return [de[0] + (para[0] - de[0]) * f, de[1] + (para[1] - de[1]) * f];
}

/** Motoboys da resposta da API que dá para desenhar: com id, situação conhecida e coordenada válida. */
export function motoboysValidos(lista) {
    return (Array.isArray(lista) ? lista : []).filter(
        (motoboy) => motoboy && typeof motoboy.id === 'string' && motoboy.id !== '' && capaceteDaSituacao(motoboy.situacao) && coordenadaValida(motoboy.latitude, motoboy.longitude)
    );
}

/** O motoboy que leva o pedido aberto (pelo public_id em `pedidos`), ou null. */
export function motoboyDoPedido(motoboys, publicId) {
    if (!publicId) {
        return null;
    }

    return (Array.isArray(motoboys) ? motoboys : []).find((motoboy) => Array.isArray(motoboy?.pedidos) && motoboy.pedidos.includes(publicId)) ?? null;
}
```

- [ ] **Step 5: Intervalo do mapa**

Em `packages/customer-portal/addon/utils/entregas-pedido.js`, troque

```js
/** Intervalo da atualização automática da lista de pedidos. */
export const INTERVALO_LISTA_MS = 30000;
```

por

```js
/** Intervalo da atualização automática da lista de pedidos. */
export const INTERVALO_LISTA_MS = 30000;

/** Intervalo da consulta dos motoboys no mapa (posição e situação de todos os motoboys online). */
export const INTERVALO_MAPA_MS = 5000;
```

- [ ] **Step 6: Rodar e ver passar**

```bash
cd /c/tmp/mp && node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/motoboys-no-mapa.test.mjs 2>&1 | tail -8
```

Esperado: `# pass 8` e `# fail 0` (o aviso `MODULE_TYPELESS_PACKAGE_JSON` do Node pode aparecer e não é erro).

- [ ] **Step 7: Commit**

```bash
cd /c/tmp/mp && git add scripts/teste-portal packages/customer-portal/addon/utils/motoboys-no-mapa.js packages/customer-portal/addon/utils/entregas-pedido.js && git commit -q -F - <<'EOF'
Portal da loja: funções puras do mapa de motoboys e testes no Node

Capacete por situação (as imagens do console), coordenada válida, distância,
salto ou deslize, interpolação e motoboy do pedido aberto; INTERVALO_MAPA_MS
de 5 s. Testes com node --test e um hook que completa o .js dos imports.

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>
EOF
```

---

### Task 5: Camada dos capacetes no Leaflet

**Files:**
- Create: `scripts/teste-portal/camada-de-motoboys.test.mjs`
- Create: `packages/customer-portal/addon/utils/camada-de-motoboys.js`

- [ ] **Step 1: Escrever o teste**

Crie `scripts/teste-portal/camada-de-motoboys.test.mjs`:

```js
// Camada dos capacetes no mapa do portal (packages/customer-portal/addon/utils/camada-de-motoboys.js), com um Leaflet
// falso: cria, desliza, salta, destaca e remove os marcadores.
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import CamadaDeMotoboys from '../../packages/customer-portal/addon/utils/camada-de-motoboys.js';
import { DESLIZE_MS } from '../../packages/customer-portal/addon/utils/motoboys-no-mapa.js';

class ListaDeClasses {
    nomes = new Set();

    toggle(nome, ligar) {
        if (ligar) {
            this.nomes.add(nome);
        } else {
            this.nomes.delete(nome);
        }
    }

    contains(nome) {
        return this.nomes.has(nome);
    }
}

function leafletFalso() {
    const marcadores = [];
    const L = {
        icon: (opcoes) => ({ opcoes }),
        marker(latlng, opcoes) {
            const elementoDoRotulo = { classList: new ListaDeClasses() };
            const marcador = {
                latlng: [...latlng],
                opcoes,
                icone: opcoes.icon,
                zIndexOffset: 0,
                mapa: null,
                tooltip: null,
                bindTooltip(conteudo, opcoesDoRotulo) {
                    this.tooltip = { conteudo, opcoes: opcoesDoRotulo, getElement: () => elementoDoRotulo };
                    return this;
                },
                getTooltip() {
                    return this.tooltip;
                },
                addTo(mapa) {
                    this.mapa = mapa;
                    return this;
                },
                remove() {
                    this.mapa = null;
                    return this;
                },
                getLatLng() {
                    return { lat: this.latlng[0], lng: this.latlng[1] };
                },
                setLatLng(latlng) {
                    this.latlng = [...latlng];
                    return this;
                },
                setIcon(icone) {
                    this.icone = icone;
                    return this;
                },
                setZIndexOffset(z) {
                    this.zIndexOffset = z;
                    return this;
                },
            };
            marcadores.push(marcador);
            return marcador;
        },
    };

    return { L, marcadores };
}

function montar({ reduzirMovimento = false } = {}) {
    const { L, marcadores } = leafletFalso();
    const relogio = { agora: 1000 };
    const quadros = [];
    const mapa = { nome: 'mapa' };
    const camada = new CamadaDeMotoboys(mapa, {
        L,
        reduzirMovimento: () => reduzirMovimento,
        agora: () => relogio.agora,
        pedirQuadro: (passo) => {
            quadros.push(passo);
            return quadros.length;
        },
        cancelarQuadro: () => {
            quadros.length = 0;
        },
        criarRotulo: () => ({ textContent: '' }),
    });
    // roda o próximo quadro pedido pela animação, no instante t
    const quadro = (t) => {
        relogio.agora = t;
        const passo = quadros.shift();
        passo?.(t);
    };

    return { camada, marcadores, quadro, quadros, relogio, mapa };
}

const ana = (latitude, longitude, extra = {}) => ({ id: 'a1', nome: 'Ana', latitude, longitude, situacao: 'livre', pedidos: [], ...extra });
const bia = (extra = {}) => ({ id: 'b2', nome: 'Bia', latitude: -21.18, longitude: -47.82, situacao: 'coleta', pedidos: ['order_x'], ...extra });

test('cria o capacete na posição, com o ícone da situação e o nome como texto', () => {
    const { camada, marcadores, mapa } = montar();
    camada.atualizar([ana(-21.17, -47.81)]);
    assert.equal(marcadores.length, 1);
    const [marcador] = marcadores;
    assert.deepEqual(marcador.latlng, [-21.17, -47.81]);
    assert.equal(marcador.icone.opcoes.iconUrl, '/engines-dist/images/capacete-verde.png');
    assert.deepEqual(marcador.icone.opcoes.iconSize, [36, 36]);
    assert.deepEqual(marcador.icone.opcoes.tooltipAnchor, [0, 13]);
    assert.equal(marcador.opcoes.interactive, false);
    assert.equal(marcador.opcoes.keyboard, false);
    assert.equal(marcador.tooltip.opcoes.permanent, true);
    assert.equal(marcador.tooltip.opcoes.direction, 'bottom');
    assert.equal(marcador.tooltip.opcoes.className, 'entregas-nome-motoboy');
    assert.equal(marcador.tooltip.conteudo.textContent, 'Ana');
    assert.equal(marcador.mapa, mapa);
});

test('nome com HTML entra como texto', () => {
    const { camada, marcadores } = montar();
    camada.atualizar([ana(-21.17, -47.81, { nome: '<img src=x onerror=alert(1)>' })]);
    assert.equal(marcadores[0].tooltip.conteudo.textContent, '<img src=x onerror=alert(1)>');
});

test('posição nova perto: desliza durante DESLIZE_MS', () => {
    const { camada, marcadores, quadro } = montar();
    camada.atualizar([ana(-21.17, -47.81)]);
    camada.atualizar([ana(-21.1705, -47.81)]); // ~56 m, no instante 1000
    const [marcador] = marcadores;
    assert.deepEqual(marcador.latlng, [-21.17, -47.81], 'ainda não andou');
    quadro(1000 + DESLIZE_MS / 2);
    assert.ok(Math.abs(marcador.latlng[0] - -21.17025) < 1e-9, `metade do caminho, veio ${marcador.latlng[0]}`);
    quadro(1000 + DESLIZE_MS + 1);
    assert.deepEqual(marcador.latlng, [-21.1705, -47.81], 'chegou');
});

test('posição nova no meio do deslize: recomeça do ponto onde está', () => {
    const { camada, marcadores, quadro, relogio } = montar();
    camada.atualizar([ana(-21.17, -47.81)]);
    camada.atualizar([ana(-21.1705, -47.81)]);
    quadro(1000 + DESLIZE_MS / 2); // em -21.17025
    relogio.agora = 1000 + DESLIZE_MS / 2;
    camada.atualizar([ana(-21.171, -47.81)]);
    quadro(1000 + DESLIZE_MS); // metade do deslize novo, de -21.17025 a -21.171
    assert.ok(Math.abs(marcadores[0].latlng[0] - -21.170625) < 1e-9, `veio ${marcadores[0].latlng[0]}`);
});

test('mesmo destino durante o deslize: o deslize segue no tempo dele', () => {
    const { camada, marcadores, quadro, relogio } = montar();
    camada.atualizar([ana(-21.17, -47.81)]);
    camada.atualizar([ana(-21.1705, -47.81)]);
    quadro(1000 + DESLIZE_MS / 2);
    relogio.agora = 1000 + DESLIZE_MS / 2;
    camada.atualizar([ana(-21.1705, -47.81)]);
    quadro(1000 + DESLIZE_MS + 1);
    assert.deepEqual(marcadores[0].latlng, [-21.1705, -47.81], 'chegou no tempo do primeiro deslize');
});

test('pulo de mais de 1 km: salta sem animação', () => {
    const { camada, marcadores, quadros } = montar();
    camada.atualizar([ana(-21.17, -47.81)]);
    camada.atualizar([ana(-21.19, -47.81)]); // ~2,2 km
    assert.deepEqual(marcadores[0].latlng, [-21.19, -47.81]);
    assert.equal(quadros.length, 0);
});

test('menos animação no sistema: salta', () => {
    const { camada, marcadores, quadros } = montar({ reduzirMovimento: true });
    camada.atualizar([ana(-21.17, -47.81)]);
    camada.atualizar([ana(-21.1705, -47.81)]);
    assert.deepEqual(marcadores[0].latlng, [-21.1705, -47.81]);
    assert.equal(quadros.length, 0);
});

test('troca de situação troca o ícone; troca de nome troca o rótulo', () => {
    const { camada, marcadores } = montar();
    camada.atualizar([ana(-21.17, -47.81)]);
    camada.atualizar([ana(-21.17, -47.81, { situacao: 'entrega', nome: 'Ana Paula' })]);
    assert.equal(marcadores.length, 1, 'o mesmo marcador');
    assert.equal(marcadores[0].icone.opcoes.iconUrl, '/engines-dist/images/capacete-vermelho.png');
    assert.equal(marcadores[0].tooltip.conteudo.textContent, 'Ana Paula');
});

test('destaque: por cima dos outros e com o rótulo destacado; sai quando o pedido fecha', () => {
    const { camada, marcadores } = montar();
    camada.atualizar([ana(-21.17, -47.81), bia()], { destaque: 'b2' });
    const [marcadorDaAna, marcadorDaBia] = marcadores;
    assert.equal(marcadorDaBia.zIndexOffset, 1000);
    assert.equal(marcadorDaBia.tooltip.getElement().classList.contains('entregas-nome-motoboy-destaque'), true);
    assert.equal(marcadorDaAna.zIndexOffset, 0);
    camada.atualizar([ana(-21.17, -47.81), bia()], { destaque: null });
    assert.equal(marcadorDaBia.zIndexOffset, 0);
    assert.equal(marcadorDaBia.tooltip.getElement().classList.contains('entregas-nome-motoboy-destaque'), false);
});

test('quem saiu da lista sai do mapa; quem é inválido não entra', () => {
    const { camada, marcadores, mapa } = montar();
    camada.atualizar([ana(-21.17, -47.81), bia()]);
    camada.atualizar([ana(-21.17, -47.81), { id: 'c3', nome: 'Caio', latitude: 0, longitude: 0, situacao: 'livre', pedidos: [] }]);
    assert.equal(marcadores.length, 2, 'Caio, em (0, 0), não ganhou marcador');
    assert.equal(marcadores[1].mapa, null, 'Bia saiu do mapa');
    assert.equal(marcadores[0].mapa, mapa);
});

test('destruir tira todos do mapa e para a animação', () => {
    const { camada, marcadores, quadros } = montar();
    camada.atualizar([ana(-21.17, -47.81)]);
    camada.atualizar([ana(-21.1705, -47.81)]);
    assert.equal(quadros.length, 1);
    camada.destruir();
    assert.equal(marcadores[0].mapa, null);
    assert.equal(quadros.length, 0, 'quadro cancelado');
    camada.atualizar([ana(-21.17, -47.81)]);
    assert.equal(marcadores.length, 1, 'depois de destruída não desenha mais');
});
```

- [ ] **Step 2: Rodar e ver falhar**

```bash
cd /c/tmp/mp && node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/camada-de-motoboys.test.mjs 2>&1 | tail -8
```

Esperado: falha com `Cannot find module …/utils/camada-de-motoboys.js`.

- [ ] **Step 3: Implementar a camada**

Crie `packages/customer-portal/addon/utils/camada-de-motoboys.js`:

```js
import { DESLIZE_MS, capaceteDaSituacao, deveSaltar, interpolar, motoboysValidos } from './motoboys-no-mapa';

// Entregas: os capacetes dos motoboys no mapa do portal da loja. Cria, move e remove os marcadores direto no Leaflet, com
// um laço de animação só para todos, que anima só quem está deslizando. O nome entra como texto (o Leaflet poria uma
// string de rótulo no innerHTML). Marcador não interativo: clicar no capacete não faz nada, porque o portal não tem
// detalhes do motoboy para mostrar. O Leaflet, o relógio, os quadros e o rótulo vêm por opção (os testes usam falsos)

/** Intervalo mínimo entre dois quadros desenhados (~30 por segundo): poupa o celular com muitos motoboys andando. */
const QUADRO_MS = 33;

/** O capacete do motoboy do pedido aberto fica por cima dos outros. */
const Z_DESTAQUE = 1000;

const reduzirMovimentoDoSistema = () => globalThis.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches === true;

export default class CamadaDeMotoboys {
    /** id do motoboy → { marcador, rotulo, situacao, destacado, de, para, inicio } */
    itens = new Map();
    quadro = null;
    ultimoQuadro = -Infinity;

    constructor(
        map,
        {
            L = globalThis.L,
            reduzirMovimento = reduzirMovimentoDoSistema,
            agora = () => performance.now(),
            pedirQuadro = (passo) => requestAnimationFrame(passo),
            cancelarQuadro = (id) => cancelAnimationFrame(id),
            criarRotulo = () => document.createElement('span'),
        } = {}
    ) {
        this.map = map;
        this.L = L;
        this.reduzirMovimento = reduzirMovimento;
        this.agora = agora;
        this.pedirQuadro = pedirQuadro;
        this.cancelarQuadro = cancelarQuadro;
        this.criarRotulo = criarRotulo;
    }

    /**
     * Desenha a lista da última consulta: cria quem chegou, move quem já estava e tira quem saiu. `destaque` é o id do
     * motoboy do pedido aberto no detalhe (ou null).
     */
    atualizar(lista, { destaque = null } = {}) {
        if (!this.map || !this.L) {
            return;
        }

        const vistos = new Set();
        const agora = this.agora();

        for (const motoboy of motoboysValidos(lista)) {
            const destino = [Number(motoboy.latitude), Number(motoboy.longitude)];
            let item = this.itens.get(motoboy.id);

            vistos.add(motoboy.id);

            if (item) {
                this.moverPara(item, destino, agora);
            } else {
                item = this.criar(motoboy, destino);
                this.itens.set(motoboy.id, item);
            }

            if (item.situacao !== motoboy.situacao) {
                item.situacao = motoboy.situacao;
                item.marcador.setIcon(this.icone(motoboy.situacao));
            }

            const nome = motoboy.nome ?? '';
            if (item.rotulo.textContent !== nome) {
                item.rotulo.textContent = nome;
            }

            this.destacar(item, motoboy.id === destaque);
        }

        for (const [id, item] of this.itens) {
            if (!vistos.has(id)) {
                item.marcador.remove();
                this.itens.delete(id);
            }
        }

        this.animar();
    }

    /** Tira os capacetes do mapa e para a animação (o mapa saiu da tela); depois disso, atualizar não desenha mais. */
    destruir() {
        if (this.quadro !== null) {
            this.cancelarQuadro(this.quadro);
            this.quadro = null;
        }

        for (const item of this.itens.values()) {
            item.marcador.remove();
        }

        this.itens.clear();
        this.map = null;
    }

    criar(motoboy, destino) {
        const rotulo = this.criarRotulo();
        rotulo.textContent = motoboy.nome ?? '';

        const marcador = this.L.marker(destino, { icon: this.icone(motoboy.situacao), interactive: false, keyboard: false });
        marcador.bindTooltip(rotulo, { permanent: true, direction: 'bottom', className: 'entregas-nome-motoboy', interactive: false });
        marcador.addTo(this.map);

        return { marcador, rotulo, situacao: motoboy.situacao, destacado: false, de: null, para: null, inicio: 0 };
    }

    // o mesmo tamanho e a mesma âncora do rótulo do capacete no mapa do console
    icone(situacao) {
        return this.L.icon({ iconUrl: capaceteDaSituacao(situacao), iconSize: [36, 36], tooltipAnchor: [0, 13] });
    }

    destacar(item, destacado) {
        if (item.destacado === destacado) {
            return;
        }

        item.destacado = destacado;
        item.marcador.setZIndexOffset(destacado ? Z_DESTAQUE : 0);
        item.marcador.getTooltip()?.getElement()?.classList.toggle('entregas-nome-motoboy-destaque', destacado);
    }

    moverPara(item, destino, agora) {
        // já está indo para lá: o deslize segue no tempo dele
        if (item.para && item.para[0] === destino[0] && item.para[1] === destino[1]) {
            return;
        }

        const atual = item.marcador.getLatLng();
        const de = [atual.lat, atual.lng];

        if (de[0] === destino[0] && de[1] === destino[1]) {
            item.para = null;
            return;
        }

        if (deveSaltar(de, destino, { reduzirMovimento: this.reduzirMovimento() })) {
            item.para = null;
            item.marcador.setLatLng(destino);
            return;
        }

        // posição nova no meio do deslize: recomeça do ponto onde o capacete está
        item.de = de;
        item.para = destino;
        item.inicio = agora;
    }

    animar() {
        if (this.quadro !== null || ![...this.itens.values()].some((item) => item.para)) {
            return;
        }

        const passo = (agora) => {
            this.quadro = null;

            if (!this.map) {
                return;
            }

            const desenhar = agora - this.ultimoQuadro >= QUADRO_MS;
            let andando = false;

            for (const item of this.itens.values()) {
                if (!item.para) {
                    continue;
                }

                const fracao = (agora - item.inicio) / DESLIZE_MS;

                if (fracao >= 1) {
                    item.marcador.setLatLng(item.para);
                    item.para = null;
                } else {
                    andando = true;
                    if (desenhar) {
                        item.marcador.setLatLng(interpolar(item.de, item.para, fracao));
                    }
                }
            }

            if (desenhar) {
                this.ultimoQuadro = agora;
            }

            if (andando) {
                this.quadro = this.pedirQuadro(passo);
            }
        };

        this.quadro = this.pedirQuadro(passo);
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

```bash
cd /c/tmp/mp && node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/motoboys-no-mapa.test.mjs scripts/teste-portal/camada-de-motoboys.test.mjs 2>&1 | tail -8 && node /c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/8f4e227e-f0ea-4b3d-a7ed-2deaa2b49efc/scratchpad/babel-check.cjs packages/customer-portal/addon/utils/camada-de-motoboys.js packages/customer-portal/addon/utils/motoboys-no-mapa.js packages/customer-portal/addon/utils/entregas-pedido.js
```

Esperado: `# pass 19`, `# fail 0` e `OK` nos três arquivos.

- [ ] **Step 5: Commit**

```bash
cd /c/tmp/mp && git add scripts/teste-portal/camada-de-motoboys.test.mjs packages/customer-portal/addon/utils/camada-de-motoboys.js && git commit -q -F - <<'EOF'
Portal da loja: camada dos capacetes dos motoboys no Leaflet

Cria, desliza (4,5 s, um laço de animação para todos), salta (primeira posição,
mais de 1 km ou menos animação), destaca e remove os marcadores; nome como
texto e marcador não interativo. Testes com um Leaflet falso.

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>
EOF
```

---

### Task 6: Enquadramento com o motoboy do pedido aberto

**Files:**
- Modify: `packages/customer-portal/addon/services/customer-portal-order-route-preview.js`

- [ ] **Step 1: Campos novos**

Troque

```js
    @tracked routingError;
    routingControl;
    signature;
```

por

```js
    @tracked routingError;
    routingControl;
    signature;
    // Entregas: o pedido aberto no detalhe (public_id), o motoboy dele no mapa ({ pedido, coordenadas }, informado pelo
    // mapa a cada consulta de 5 s) e o pedido já enquadrado com o motoboy: coleta, entrega e motoboy entram no
    // enquadramento uma vez por pedido; depois só o capacete anda
    pedidoSelecionado = null;
    motoboyDoPedido = null;
    pedidoEnquadradoComMotoboy = null;
```

- [ ] **Step 2: `updateSelectedOrder` com o motoboy no enquadramento**

Troque

```js
        this.selectedOrderRoutePoints = routePoints;

        if (!this.map || signature === this.signature) {
            return;
        }

        this.signature = signature;

        if (routeCoordinates.length === 0) {
            this.clearSelectedOrder();
            return;
        }

        this.focusRoute(routeCoordinates, { paddingBottomRight: [560, 0] });
```

por

```js
        this.selectedOrderRoutePoints = routePoints;

        // Entregas: outro pedido no detalhe: o enquadramento com o motoboy vale de novo, uma vez para este pedido
        const publicId = order?.public_id ?? null;
        if (publicId !== this.pedidoSelecionado) {
            this.pedidoSelecionado = publicId;
            this.pedidoEnquadradoComMotoboy = null;
        }

        if (!this.map || signature === this.signature) {
            return;
        }

        this.signature = signature;

        if (routeCoordinates.length === 0) {
            this.clearSelectedOrder();
            return;
        }

        this.focusRoute(this.comMotoboyDoPedido(routeCoordinates), { paddingBottomRight: [560, 0] });
```

- [ ] **Step 3: `clearSelectedOrder` e os métodos novos**

Troque

```js
    clearSelectedOrder() {
        this.selectedOrderRoutePoints = [];

        if (this.signature?.startsWith?.('order:')) {
            this.clear();
        }
    }
```

por

```js
    clearSelectedOrder() {
        this.selectedOrderRoutePoints = [];
        // Entregas: sem pedido aberto, sem motoboy do pedido nem enquadramento pendente
        this.pedidoSelecionado = null;
        this.motoboyDoPedido = null;
        this.pedidoEnquadradoComMotoboy = null;

        if (this.signature?.startsWith?.('order:')) {
            this.clear();
        }
    }

    // Entregas: o mapa informa, a cada consulta de 5 s, onde está o motoboy do pedido aberto (coordenadas [lat, lng]) ou
    // null quando o pedido não tem motoboy no mapa. Na primeira vez que ele aparece para o pedido, o mapa enquadra coleta,
    // entrega e motoboy; depois o enquadramento não muda mais
    definirMotoboyDoPedido(publicId, coordenadas) {
        this.motoboyDoPedido = publicId && coordenadas ? { pedido: publicId, coordenadas } : null;

        const pedidoNoMapa = Boolean(publicId) && publicId === this.pedidoSelecionado && this.signature?.startsWith?.('order:');

        if (!this.motoboyDoPedido || !pedidoNoMapa || this.pedidoEnquadradoComMotoboy === publicId || !this.map) {
            return;
        }

        const routeCoordinates = this.selectedOrderRoutePoints.map((point) => point.coordinates);

        this.focusRoute(this.comMotoboyDoPedido(routeCoordinates), { paddingBottomRight: [560, 0] });
    }

    // Entregas: as coordenadas do enquadramento do pedido aberto, com o motoboy dele quando já se sabe onde está; marca o
    // pedido como enquadrado com o motoboy
    comMotoboyDoPedido(routeCoordinates) {
        const motoboy = this.motoboyDoPedido;

        if (!motoboy || motoboy.pedido !== this.pedidoSelecionado) {
            return routeCoordinates;
        }

        this.pedidoEnquadradoComMotoboy = motoboy.pedido;

        return [...routeCoordinates, motoboy.coordenadas];
    }
```

- [ ] **Step 4: O recálculo da rota (OSRM) mantém o motoboy no quadro**

Troque

```js
        this.routingControl.on('routesfound', ({ routes }) => {
            this.route = routes?.[0] ?? null;
            this.routingError = null;
            this.focusRoute(routeCoordinates, { paddingBottomRight: [560, 0] });
        });
```

por

```js
        this.routingControl.on('routesfound', ({ routes }) => {
            this.route = routes?.[0] ?? null;
            this.routingError = null;
            this.focusRoute(this.comMotoboyDoPedido(routeCoordinates), { paddingBottomRight: [560, 0] });
        });
```

- [ ] **Step 5: `clear()` zera os campos novos**

Troque

```js
        this.routeCoordinates = [];
        this.signature = null;
    }
```

por

```js
        this.routeCoordinates = [];
        this.signature = null;
        this.pedidoSelecionado = null;
        this.motoboyDoPedido = null;
        this.pedidoEnquadradoComMotoboy = null;
    }
```

- [ ] **Step 6: Parse e commit**

```bash
cd /c/tmp/mp && node /c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/8f4e227e-f0ea-4b3d-a7ed-2deaa2b49efc/scratchpad/babel-check.cjs packages/customer-portal/addon/services/customer-portal-order-route-preview.js && git add packages/customer-portal/addon/services/customer-portal-order-route-preview.js && git commit -q -F - <<'EOF'
Portal da loja: mapa enquadra coleta, entrega e o motoboy do pedido, uma vez

O serviço da rota recebe a posição do motoboy do pedido aberto e a inclui no
enquadramento na primeira vez que ele aparece (também no recálculo do OSRM);
depois o mapa fica parado e só o capacete anda.

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>
EOF
```

Esperado: `OK` no parse e o commit criado.

---

### Task 7: Ciclo de 5 s no mapa da tela Pedidos

**Files:**
- Modify: `packages/customer-portal/addon/components/portal/order/workspace/map.js`

- [ ] **Step 1: Imports e serviço**

Troque o topo do arquivo

```js
import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class PortalOrderWorkspaceMapComponent extends Component {
    @service customerPortalOrderRoutePreview;
    @service intl;

    willDestroy() {
        super.willDestroy(...arguments);
        this.customerPortalOrderRoutePreview.unregisterMap();
    }
```

por

```js
import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { race, task, timeout, waitForEvent } from 'ember-concurrency';
import CamadaDeMotoboys from '../../../../utils/camada-de-motoboys';
import { motoboyDoPedido, motoboysValidos } from '../../../../utils/motoboys-no-mapa';
import { INTERVALO_MAPA_MS, espera } from '../../../../utils/entregas-pedido';

export default class PortalOrderWorkspaceMapComponent extends Component {
    @service customerPortalOrderRoutePreview;
    @service fetch;
    @service intl;

    // Entregas: os capacetes dos motoboys (utils/camada-de-motoboys) e a última lista que a API devolveu
    camadaDeMotoboys = null;
    ultimosMotoboys = [];

    willDestroy() {
        super.willDestroy(...arguments);
        this.camadaDeMotoboys?.destruir();
        this.camadaDeMotoboys = null;
        this.customerPortalOrderRoutePreview.unregisterMap();
    }
```

- [ ] **Step 2: `setupMap`, `syncRoutePreview`, o ciclo e o desenho**

Troque o fim do arquivo

```js
    @action setupMap(event) {
        this.customerPortalOrderRoutePreview.registerMap(event);
        this.syncRoutePreview();
    }

    @action syncRoutePreview() {
        if (this.args.selectedOrder) {
            this.customerPortalOrderRoutePreview.updateSelectedOrder(this.args.selectedOrder);
            return;
        }

        this.customerPortalOrderRoutePreview.clearSelectedOrder();
        this.customerPortalOrderRoutePreview.updateDraft(this.args.draft);
    }
}
```

por

```js
    @action setupMap(event) {
        this.customerPortalOrderRoutePreview.registerMap(event);
        // Entregas: os capacetes dos motoboys, relidos a cada 5 s enquanto o mapa existe
        this.camadaDeMotoboys?.destruir();
        this.camadaDeMotoboys = new CamadaDeMotoboys(event?.target ?? event);
        this.syncRoutePreview();
        this.acompanharMotoboys.perform();
    }

    @action syncRoutePreview() {
        if (this.args.selectedOrder) {
            this.customerPortalOrderRoutePreview.updateSelectedOrder(this.args.selectedOrder);
        } else {
            this.customerPortalOrderRoutePreview.clearSelectedOrder();
            this.customerPortalOrderRoutePreview.updateDraft(this.args.draft);
        }

        // Entregas: outro pedido aberto (ou nenhum): o destaque e o enquadramento do motoboy mudam na hora, com a última lista
        this.desenharMotoboys();
    }

    // Entregas: os motoboys no mapa (GET int/v1/entregas/loja/motoboys) a cada 5 s, enquanto o mapa existe. Com a aba
    // oculta, não consulta; a próxima volta começa assim que ela aparece. Em falha (429 inclusive), os capacetes ficam onde
    // estavam e a espera dobra até 2 min. O EC cancela a task quando o mapa sai da tela (Tabela ou outra página)
    @task({ restartable: true }) *acompanharMotoboys() {
        let falhas = 0;

        while (!this.isDestroying && !this.isDestroyed) {
            const oculta = document.hidden;

            if (!oculta) {
                try {
                    const resposta = yield this.fetch.get('entregas/loja/motoboys');
                    this.ultimosMotoboys = Array.isArray(resposta?.motoboys) ? resposta.motoboys : [];
                    this.desenharMotoboys();
                    falhas = 0;
                } catch {
                    falhas++;
                }
            }

            const proxima = timeout(espera(INTERVALO_MAPA_MS, falhas));
            yield oculta ? race([proxima, waitForEvent(document, 'visibilitychange')]) : proxima;
        }
    }

    // Entregas: desenha os capacetes da última consulta, com o motoboy do pedido aberto destacado, e informa ao serviço da
    // rota onde ele está (para o enquadramento)
    desenharMotoboys() {
        const motoboys = motoboysValidos(this.ultimosMotoboys);
        const publicId = this.args.selectedOrder?.public_id ?? null;
        const doPedido = motoboyDoPedido(motoboys, publicId);

        this.camadaDeMotoboys?.atualizar(motoboys, { destaque: doPedido?.id ?? null });
        this.customerPortalOrderRoutePreview.definirMotoboyDoPedido(publicId, doPedido ? [Number(doPedido.latitude), Number(doPedido.longitude)] : null);
    }
}
```

- [ ] **Step 3: Parse e commit**

```bash
cd /c/tmp/mp && node /c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/8f4e227e-f0ea-4b3d-a7ed-2deaa2b49efc/scratchpad/babel-check.cjs packages/customer-portal/addon/components/portal/order/workspace/map.js && git add packages/customer-portal/addon/components/portal/order/workspace/map.js && git commit -q -F - <<'EOF'
Portal da loja: mapa da tela Pedidos mostra os motoboys a cada 5 s

O mapa consulta loja/motoboys com a aba visível (espera crescente em erro),
entrega a lista à camada dos capacetes e destaca o motoboy do pedido aberto.

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>
EOF
```

Esperado: `OK` no parse e o commit criado.

---

### Task 8: Painel sem mapinha, CSS e traduções

**Files:**
- Modify: `packages/customer-portal/addon/components/portal/order/details/motoboy.hbs`
- Modify: `packages/customer-portal/addon/components/portal/order/details/motoboy.js`
- Modify: `packages/customer-portal/addon/styles/customer-portal-engine.css`
- Modify: `packages/customer-portal/translations/en-us.yaml`
- Modify: `packages/customer-portal/translations/pt-br.yaml`

- [ ] **Step 1: Template do painel**

Substitua o conteúdo de `motoboy.hbs` por:

```hbs
{{!-- Entregas: motoboy do pedido (nome e foto), com os dados que o ciclo do detalhe consulta. A posição fica no mapa grande
     da tela Pedidos, que mostra todos os motoboys online (Workspace::Map) --}}
<ContentPanel @title={{t "customer-portal.ui.entregas.courier"}} @open={{true}} @wrapperClass="bordered-top">
    {{#if @motoboy}}
        <div class="flex items-center space-x-3">
            {{#if @motoboy.foto}}
                <img src={{@motoboy.foto}} alt="" class="w-10 h-10 rounded-full object-cover flex-shrink-0" />
            {{/if}}
            <div class="text-sm text-gray-700 dark:text-gray-200">
                {{!-- Entregas: com o pedido já aceito (@aceito), o texto é o de quem aceitou, mesmo que a resposta do motoboy seja
                     anterior ao aceite (a consulta depois do aceite falhou) e ainda diga que ele não aceitou --}}
                {{#if (or @motoboy.aceitou @aceito)}}
                    {{t "customer-portal.ui.entregas.courier-accepted" name=@motoboy.nome}}
                {{else}}
                    {{t "customer-portal.ui.entregas.courier-assigned" name=@motoboy.nome}}
                {{/if}}
            </div>
        </div>
    {{else if (and @consultado (not @aceito))}}
        <p class="text-sm text-gray-500 dark:text-gray-400">{{t "customer-portal.ui.entregas.courier-waiting"}}</p>
    {{else}}
        {{!-- Entregas: carregando antes da primeira resposta e também com o pedido já aceito e sem motoboy na resposta (a consulta
             depois do aceite falhou): "aguardando" contradiria o detalhe, que já mostra o aceite --}}
        <Spinner />
    {{/if}}
</ContentPanel>
```

- [ ] **Step 2: Componente do painel**

Substitua o conteúdo de `motoboy.js` por:

```js
import Component from '@glimmer/component';

// Entregas: o painel do motoboy no detalhe do pedido, só apresentação. O ciclo do detalhe consulta o motoboy e passa
// @motoboy ({ nome, foto, aceitou }; null = ninguém chamado ainda), @consultado (se a primeira resposta já veio; antes
// dela, o carregando) e @aceito (o detalhe relido já mostra o pedido aceito: sem motoboy na resposta, o painel fica no
// carregando em vez de "aguardando"). Com o pedido encerrado, o detalhe não mostra o painel. A posição fica no mapa
// grande da tela Pedidos (Workspace::Map), com todos os motoboys online
export default class PortalOrderDetailsMotoboyComponent extends Component {}
```

- [ ] **Step 3: CSS do rótulo**

No fim de `packages/customer-portal/addon/styles/customer-portal-engine.css` (depois da última linha,
`/* stylelint-enable no-duplicate-selectors */`), acrescente:

```css

/* Entregas: nome do motoboy fixo embaixo do capacete no mapa de motoboys do portal (cópia do entregas-nome-motoboy do mapa
   do console, cujo CSS não carrega aqui) e o destaque azul do motoboy do pedido aberto */
.fleetbase-portal .leaflet-tooltip.entregas-nome-motoboy {
    padding: 1px 6px;
    border: none;
    border-radius: 9999px;
    background: rgb(15 23 42 / 88%);
    box-shadow: 0 1px 4px rgb(0 0 0 / 35%);
    color: #f8fafc;
    font-size: 11px;
    font-weight: 600;
    line-height: 16px;
    white-space: nowrap;
}

.fleetbase-portal .leaflet-tooltip-bottom.entregas-nome-motoboy::before {
    border-bottom-color: rgb(15 23 42 / 88%);
}

.fleetbase-portal .leaflet-tooltip.entregas-nome-motoboy.entregas-nome-motoboy-destaque {
    background: #2563eb;
}

.fleetbase-portal .leaflet-tooltip-bottom.entregas-nome-motoboy.entregas-nome-motoboy-destaque::before {
    border-bottom-color: #2563eb;
}
```

- [ ] **Step 4: Traduções**

Em `packages/customer-portal/translations/en-us.yaml`, apague a linha
`      courier-position: Courier's last known position`. Em `packages/customer-portal/translations/pt-br.yaml`, apague a
linha `      courier-position: Última posição do motoboy`. Confira que não sobrou uso:

```bash
cd /c/tmp/mp && grep -rn "courier-position" packages/customer-portal --include=*.hbs --include=*.js --include=*.yaml
```

Esperado: nenhuma linha.

- [ ] **Step 5: Validar**

```bash
cd /c/tmp/mp && node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit=$?"; node /c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/8f4e227e-f0ea-4b3d-a7ed-2deaa2b49efc/scratchpad/babel-check.cjs packages/customer-portal/addon/components/portal/order/details/motoboy.js
```

Esperado: `exit=0` e `OK`.

- [ ] **Step 6: Commit**

```bash
cd /c/tmp/mp && git add packages/customer-portal/addon/components/portal/order/details/motoboy.hbs packages/customer-portal/addon/components/portal/order/details/motoboy.js packages/customer-portal/addon/styles/customer-portal-engine.css packages/customer-portal/translations/en-us.yaml packages/customer-portal/translations/pt-br.yaml && git commit -q -F - <<'EOF'
Portal da loja: painel do motoboy sem mapinha; rótulo do nome no mapa

A posição fica só no mapa grande. CSS do rótulo copiado do mapa do console,
com a variante azul do motoboy do pedido aberto; sai courier-position.

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>
EOF
```

---

### Task 9: Item 9b no teste de isolamento

**Files:**
- Modify: `scripts/teste-isolamento-lojas.mjs`

- [ ] **Step 1: Item novo**

Logo depois do item 9 (o bloco `await item('9', 'B não vê o motoboy do pedido de A (404)', …);`, que termina em
`        return '';\n    });`), insira:

```js

    await item('9b', 'Mapa dos motoboys sem id, telefone nem pedido de outra loja', [[estado.tokenA, SEM_LOGIN_A], ...deB], async () => {
        const campos = ['id', 'nome', 'latitude', 'longitude', 'situacao', 'pedidos'];
        const ra = await chamar('GET', 'int/v1/entregas/loja/motoboys', { token: estado.tokenA });
        exigir(ra.status === 200 && Array.isArray(ra.json?.motoboys), () => `controle (A pede o mapa): ${resumo(ra)}`);
        const rb = await chamar('GET', 'int/v1/entregas/loja/motoboys', { token: estado.tokenB });
        exigir(rb.status === 200 && Array.isArray(rb.json?.motoboys), () => `B pede o mapa: ${resumo(rb)}`);
        const problemas = [];
        for (const [loja, r] of [
            ['A', ra],
            ['B', rb],
        ]) {
            if (/driver_/.test(JSON.stringify(r.json))) {
                problemas.push(`${loja}: a resposta tem um id driver_…`);
            }
            for (const motoboy of r.json.motoboys) {
                const extras = Object.keys(motoboy).filter((chave) => !campos.includes(chave));
                if (extras.length) {
                    problemas.push(`${loja}: campos a mais (${extras.join(', ')})`);
                }
            }
        }
        // o pedido de teste já foi cancelado (item 13a); vale também para os pedidos em andamento de A no momento
        const pedidosDeA = new Set([...ra.json.motoboys.flatMap((m) => m.pedidos ?? []), estado.pedido?.public_id].filter(Boolean));
        const deAEmB = rb.json.motoboys.flatMap((m) => m.pedidos ?? []).filter((id) => pedidosDeA.has(id));
        if (deAEmB.length) {
            problemas.push(`B vê pedido(s) de A: ${deAEmB.join(', ')}`);
        }
        exigir(!problemas.length, () => problemas.join('; '));
        return `${rb.json.motoboys.length} motoboy(s) no mapa de B`;
    });
```

- [ ] **Step 2: Conferir a sintaxe**

```bash
cd /c/tmp/mp && node --check scripts/teste-isolamento-lojas.mjs && echo OK
```

Esperado: `OK`.

- [ ] **Step 3: Commit**

```bash
cd /c/tmp/mp && git add scripts/teste-isolamento-lojas.mjs && git commit -q -F - <<'EOF'
Teste de isolamento: item 9b confere o mapa de motoboys das lojas

As duas lojas recebem só id, nome, coordenadas, situação e pedidos, sem
driver_; B não vê pedido de A em pedidos.

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>
EOF
```

---

### Task 10: CLAUDE.md

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Introdução do Portal da loja**

Troque `cria e acompanha os próprios pedidos e vê o extrato, sem enxergar nada de outra loja. Os motoboys continuam recebendo os pedidos de todas.`
por `cria e acompanha os próprios pedidos e vê o extrato, sem enxergar os pedidos de outra loja. Os motoboys continuam recebendo os pedidos de todas, e o mapa do portal mostra todos os motoboys online (ver "Mapa de motoboys").`

- [ ] **Step 2: API do portal**

Troque a linha

```
  - `pedidos/{id}/motoboy`: nome e foto; a posição só com o pedido aceito há no máximo 4 h.
```

por

```
  - `pedidos/{id}/motoboy`: nome, foto e aceite (a posição fica no mapa de motoboys);
  - `motoboys`: o mapa de motoboys (`App\Support\Entregas\MotoboysNoMapaDaLoja`), com limitador próprio `entregas-loja-mapa` (60 por minuto por usuário).
```

- [ ] **Step 3: Bloco "Mapa de motoboys"**

Logo antes da linha que começa com `- **Pedido da central:**`, insira:

```
- **Mapa de motoboys** (decisão de 2026-10-04; desenho: `docs/superpowers/specs/2026-10-04-motoboys-no-mapa-do-portal-design.md`):
  - a tela Pedidos, no modo Mapa, mostra os motoboys online e os offline que já aceitaram um pedido ainda aberto, com o capacete do console na cor da situação (verde livre, amarelo indo à loja, vermelho a caminho do cliente) e o nome fixo embaixo. Some o offline sem pedido aceito, inclusive com pedido só atribuído pela central (a última posição pode ser a casa dele);
  - **a loja vê as entregas das outras lojas** (risco aceito pelo Edgard);
  - `Workspace::Map` consulta `loja/motoboys` a cada 5 s com a aba visível (espera crescente em erro), e `utils/camada-de-motoboys.js` desliza cada capacete até a posição nova (salta na primeira posição, em pulos de mais de 1 km e com "reduzir animações");
  - o motoboy do pedido aberto fica por cima, com o rótulo azul, e entra uma vez no enquadramento (`definirMotoboyDoPedido` no serviço da rota). O painel "Motoboy" do detalhe não tem mais mapinha;
  - o id do motoboy nunca vai para a loja: com ele, o canal `driver.<id>` do socket entrega a posição ao vivo e o telefone. A resposta traz só um id opaco (HMAC do uuid), nome, coordenadas, situação e os `public_id` dos pedidos da própria loja;
  - testes: `scripts/teste-php/mapa-da-loja.php` (php-wasm) e `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs`.
```

- [ ] **Step 4: Mapa ao vivo do console**

Troque a linha `  - Teste: \`scripts/teste-php/mapa.php\`.` por:

```
  - A consulta dos pedidos em andamento (`SituacaoDoMotoboy::pedidosEmAndamento`) é a mesma do mapa de motoboys do portal da loja.
  - Teste: `scripts/teste-php/mapa.php`.
```

- [ ] **Step 5: Riscos conhecidos**

Depois da linha `  - o upload do core aceita \`disk\`/\`path\` de usuários que não são de loja.`, acrescente:

```
  - a loja vê todos os motoboys online, com nome completo e situação, inclusive os que levam pedidos de outras lojas: um capacete vermelho parado numa casa indica o endereço de um cliente de outra loja;
  - o motoboy que esquece de ficar offline ao fim do expediente continua no mapa das lojas, possivelmente em casa (o app rastreia mesmo fechado). A central orienta os motoboys.
```

- [ ] **Step 6: Histórico**

Depois do item 11 do Histórico, acrescente:

```
12. Motoboys no mapa do portal da loja (2026-10-04): todos os motoboys online com o capacete e o nome, posição a cada 5 s com deslize e destaque do motoboy do pedido aberto; a rota do motoboy do pedido deixou de mandar a posição.
```

- [ ] **Step 7: Commit**

```bash
cd /c/tmp/mp && git add CLAUDE.md && git commit -q -F - <<'EOF'
CLAUDE.md: mapa de motoboys do portal (decisão, rota, riscos e histórico)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>
EOF
```

---

### Task 11: Verificação final e build local

**Files:** nenhum arquivo novo (corrige o que falhar).

- [ ] **Step 1: Todos os testes**

```bash
export PHP_WASM_DIR=/c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/0f61616a-8954-4e49-8c48-e9952984ad2b/scratchpad/php-wasm; cd /c/tmp/mp && for t in mapa-da-loja mapa ganhos-motoboy reenvio avisos-push; do printf '%s: ' "$t"; node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php | tail -1; done; node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/motoboys-no-mapa.test.mjs scripts/teste-portal/camada-de-motoboys.test.mjs 2>&1 | grep -E "^# (pass|fail)"; node --test scripts/package-linker.test.mjs 2>&1 | grep -E "^# (pass|fail)"
```

Esperado: `FALHAS: 0` em todos os testes PHP, `# fail 0` nos dois `node --test`.

- [ ] **Step 2: i18n e parse de todos os JS alterados**

```bash
cd /c/tmp/mp && node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit=$?"; node /c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/8f4e227e-f0ea-4b3d-a7ed-2deaa2b49efc/scratchpad/babel-check.cjs $(git diff --name-only d4cb2d2d -- 'packages/*.js')
```

Esperado: `exit=0` e `OK` em todos.

- [ ] **Step 3: Build de produção do console**

```bash
cd /c/tmp/mp/console && DISABLE_RUNTIME_CONFIG=false pnpm build --environment production 2>&1 | tail -15
```

Esperado (~10 a 15 min): `Built project successfully`. Confira que as imagens do capacete foram reescritas com o
fingerprint no bundle do portal:

```bash
cd /c/tmp/mp/console && grep -rlo "capacete-verde-[0-9a-f]*\.png" dist/engines-dist/@fleetbase/customer-portal-engine/ | head -3; ls dist/engines-dist/images | grep capacete
```

Esperado: um arquivo JS do customer-portal-engine com `capacete-verde-<hash>.png` e os quatro PNGs com hash em
`dist/engines-dist/images`. Se o caminho no JS ficar sem hash e o PNG só existir com hash, anote: o capacete sairia
quebrado, e a correção é apontar `CAPACETES` para o nome que o build gerou (investigar antes de seguir).

- [ ] **Step 4: Teste no navegador contra a produção (depois do deploy da API)**

Só depois que o Edgard publicar a API (Task 12, passo 2). Grave `console/dist/fleetbase.config.json` com o `API_HOST` de
produção (procedimento do CLAUDE.md), sirva com `npx serve -s dist -l 4200`, entre no portal
(`http://localhost:4200/customer-portal`) com um usuário de loja e confira:

- os capacetes aparecem com o nome e se movem a cada 5 s com um motoboy andando;
- com um pedido aberto que tem motoboy, ele fica por cima, com o rótulo azul, e o mapa enquadra coleta, entrega e ele uma vez;
- o painel "Motoboy" não tem mais mapinha;
- na aba Rede, `loja/motoboys` para com a aba oculta e no modo Tabela, e volta ao reabrir;
- o console do navegador sem erros.

---

### Task 12: Entrega (com o Edgard)

- [ ] **Step 1: Push** (o Edgard roda; o modo automático bloqueia o push pelo Claude)

```bash
git -C /c/tmp/mp fetch origin && git -C /c/tmp/mp log --oneline origin/main..HEAD && git -C /c/tmp/mp push origin HEAD:main
```

Se a `origin/main` tiver andado, rebase antes: `git -C /c/tmp/mp rebase origin/main` e rode de novo a Task 11, passos 1 e 2.

- [ ] **Step 2: Deploy da API** (na VPS)

```bash
cd ~/entregas && bash deploy/atualizar.sh api
```

Checagem: `curl -s -o /dev/null -w '%{http_code}\n' https://entregas-api.restaurantepro.com.br/int/v1/entregas/loja/motoboys`
sem token responde 401 (antes do deploy, 404).

- [ ] **Step 3: Teste no navegador** (Task 11, passo 4), com o build local.

- [ ] **Step 4: Deploy do console** (na VPS)

```bash
cd ~/entregas && SEM_PULL=1 bash deploy/atualizar.sh console
```

Depois: abrir o portal em janela anônima (a lista de extensões fica 1 h no localStorage) e repetir a conferência do
passo 3 em produção.

- [ ] **Step 5: Teste de isolamento** (opcional, o Edgard roda): `node scripts/teste-isolamento-lojas.mjs` com o
`deploy/teste-lojas.env`; o item 9b deve passar.
