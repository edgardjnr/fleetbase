# Ganhos do motoboy no app — plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Objetivo:** o Início do app do motoboy vira "Meus ganhos" (período com atalhos e De/Até, total a receber e corridas por dia), e o card de aceitar e os detalhes do pedido mostram o km da entrega e o valor que o motoboy recebe, com o valor de cada entrega congelado no servidor.

**Arquitetura:** na API (`api/app`), uma tabela nova (`entregas_valores_pedido`) guarda o valor congelado de cada entrega; o `CalculoEntregas` (usado pelo relatório da central e pelo extrato da loja) passa a ler e gravar nela; duas rotas novas na API v1 (`v1/entregas/motoboy/*`) respondem só ao token do próprio motoboy, sem o valor da loja. No app (`entregas-navigator`), funções puras em `src/utils/ganhos.ts`, um hook com cache para o valor de um pedido, um componente usado pelo card e pelos detalhes, e a tela nova no lugar da `DriverDashboardScreen`.

**Tecnologias:** Laravel 10 / PHP 8.2 (código em `api/app`; pacotes Fleetbase do Composer: core-api 1.6.61, fleetops-api 0.6.65), testes PHP com php-wasm (`scripts/teste-php`), React Native 0.86 + Tamagui + `@fleetbase/sdk` 1.2.13 (adapter axios), `@react-native-community/datetimepicker` 8, date-fns 4, testes das funções puras com `node --test` (Node 22, `--experimental-strip-types`).

Spec: `docs/superpowers/specs/2026-10-03-ganhos-do-motoboy-design.md`.

---

## Ambiente (vale para todas as tarefas)

- **Repo Delivery:** trabalhe só na worktree `C:\tmp\gm` (no Git Bash: `/c/tmp/gm`). Ela já existe, está em `origin/main` + o commit da spec, e tem `console/node_modules` ligado (junção) à cópia principal, para o `i18n-check`. Não mexa em `C:\Users\Edgardjr\Documents\vibe coding\Delivery` (outra sessão pode estar lá).
- **Repo do app:** worktree `C:\tmp\nv` (`/c/tmp/nv`), criada na Tarefa 8 a partir de `origin/main` do `entregas-navigator`. Não mexa na cópia principal `Documents/vibe coding/entregas-navigator`.
- Antes de cada commit: `git rev-parse --show-toplevel` tem de mostrar `C:/tmp/gm` (Delivery) ou `C:/tmp/nv` (app). Commite só os arquivos da tarefa. **Nada de push** até a Tarefa 14, e só com aprovação do Edgard.
- Mensagens de commit em português, terminando com a linha `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`.
- Pasta de apoio (fora dos repos):
  ```bash
  SCRATCH=/c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/0f61616a-8954-4e49-8c48-e9952984ad2b/scratchpad
  ```
  - `$SCRATCH/php-wasm`: php-wasm já instalado (`PHP_WASM_DIR` dos testes PHP).
  - `$SCRATCH/conferir-tsx.cjs`: confere a sintaxe de arquivos TS/TSX do app com o `@babel/parser` do console (o app não tem `node_modules` nesta máquina). Pega import duplicado e erro de JSX/TS.
- Testes PHP (na raiz da worktree Delivery): `PHP_WASM_DIR=$SCRATCH/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/<teste>.php` — imprime `PASSA`/`FALHA` por caso e termina com `FALHAS: <n>`; sai com 1 se houver falha.
- Não há PHP instalado nem `node_modules` no app: o build do APK (GitHub Actions) é a checagem final do JavaScript.
- Os arquivos dos dois repos estão com CRLF no disco (`core.autocrlf=true`); os trechos "trocar X por Y" deste plano estão com LF. A ferramenta Edit casa os dois; numa troca por script, normalize as quebras de linha antes.
- O código deste plano foi ensaiado numa cópia de rascunho antes da execução (todos os trechos "trocar" casaram uma vez só; teste PHP com `FALHAS: 0`, `# pass 7` no app e sintaxe OK em todos os arquivos). Se algo divergir, confira primeiro se o arquivo de origem mudou na `origin/main`.

## Mapa de arquivos

Repo Delivery (`C:\tmp\gm`):

| Arquivo | O quê |
|---|---|
| `scripts/teste-php/sintaxe.mjs` (novo) | `php -l` com o PHP 8.2 do php-wasm |
| `scripts/teste-php/stubs-ganhos.php` (novo) | stubs do Laravel/Fleetbase para o teste dos ganhos |
| `scripts/teste-php/ganhos-motoboy.php` (novo) | teste: migration, congelamento, o que o app recebe, rotas |
| `api/database/migrations/2026_10_03_120000_create_entregas_valores_pedido_table.php` (novo) | tabela do valor congelado |
| `api/app/Support/Entregas/ValoresCongelados.php` (novo) | leitura e gravação da tabela |
| `api/app/Support/Entregas/CalculoEntregas.php` (mudar) | congela e lê o valor; `valorDoPedido()` |
| `api/app/Support/Entregas/GanhosDoMotoboy.php` (novo) | formato do app (sem valor da loja), quem pode ver, período |
| `api/app/Support/Entregas/MotoboyDaSessao.php` (novo) | o Driver do token |
| `api/app/Http/Controllers/Entregas/MotoboyController.php` (novo) | `ganhos` e `valor` |
| `api/app/Providers/RouteServiceProvider.php` (mudar) | rotas `v1/entregas/motoboy/*` e limite por usuário |
| `packages/fleetops/translations/pt-br.yaml`, `en-us.yaml` (mudar) | textos de Pagamento e cobrança |
| `api/deploy.sh` (mudar) | migrations do app também no banco sandbox |
| `CLAUDE.md` (mudar) | documentação |

Repo do app (`C:\tmp\nv`):

| Arquivo | O quê |
|---|---|
| `src/utils/ganhos.ts` (novo) | funções puras: período, atalhos, formatação, agrupamento |
| `scripts/testes/ganhos.teste.ts` (novo) | testes `node --test` das funções puras |
| `translations/pt.json`, `en.json` (mudar) | `MeusGanhosScreen.*`, `ValorDaEntrega.*` |
| `src/hooks/use-valor-da-entrega.ts` (novo) | valor de um pedido com cache |
| `src/components/ValorDaEntrega.tsx` (novo) | faixa do card e seção dos detalhes |
| `src/components/AdhocOrderCard.tsx` (mudar) | faixa do valor no card |
| `src/screens/OrderScreen.tsx` (mudar) | seção "Valor da entrega" |
| `src/screens/MeusGanhosScreen.tsx` (novo) | tela do Início |
| `src/navigation/DriverNavigator.tsx` (mudar) | Início = Meus ganhos; `Order` e `Entity` na pilha do Início |
| `src/screens/DriverDashboardScreen.tsx` (apagar) | Início antigo |

---

## Fase A — API e console (repo Delivery, worktree `C:\tmp\gm`)

### Tarefa 1: verificador de sintaxe PHP e branch da worktree

**Files:**
- Create: `scripts/teste-php/sintaxe.mjs`

- [ ] **Passo 1: conferir a branch da worktree** (criada junto com o plano)

```bash
cd /c/tmp/gm && git branch --show-current && git log --oneline -2
```
Esperado: `ganhos-do-motoboy`, com os commits do plano e da spec no topo.

- [ ] **Passo 2: criar `scripts/teste-php/sintaxe.mjs`**

```js
// Confere a sintaxe de arquivos PHP com o PHP 8.2 da produção (php -l no php-wasm), sem PHP instalado.
// Uso, na raiz do repo (mesma instalação do rodar.mjs):
//   PHP_WASM_DIR=<pasta> node scripts/teste-php/sintaxe.mjs api/app/Arquivo.php [outros.php...]
// Sai com 1 se algum arquivo tiver erro de sintaxe.
import path from 'node:path';
import { statSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const raiz = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const arquivos = process.argv.slice(2);
if (!arquivos.length) {
    console.error('Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/sintaxe.mjs <arquivo.php>...');
    process.exit(2);
}

const carregar = createRequire(path.join(path.resolve(process.env.PHP_WASM_DIR || '.'), 'index.js'));
const { PHP } = carregar('@php-wasm/universal');
const { loadNodeRuntime, createNodeFsMountHandler } = carregar('@php-wasm/node');

let falhas = 0;
for (const arquivo of arquivos) {
    // o php -l aceita pasta (e "", que vira a raiz do repo) e responde OK sem ler nada
    if (!statSync(arquivo, { throwIfNoEntry: false })?.isFile()) {
        falhas++;
        console.log(`ERRO ${arquivo}\n     não é um arquivo (ou não existe)`);
        continue;
    }
    // um PHP por arquivo: depois do cli() a instância não serve mais (reusar devolve o resultado do primeiro arquivo, um falso OK)
    // processId: fora do Vitest, o php-wasm exige o id do processo (usado pelo gerenciador de travas de arquivo)
    const php = new PHP(await loadNodeRuntime('8.2', { emscriptenOptions: { processId: 1 } }));
    await php.mount('/repo', createNodeFsMountHandler(raiz));
    const caminho = '/repo/' + path.relative(raiz, path.resolve(arquivo)).split(path.sep).join('/');
    const resposta = await php.cli(['php', '-l', caminho]);
    // em erro, a última linha é a do stderr ("PHP Parse error: ... on line N"); o stdout termina em "Errors parsing"
    const saida = ((await resposta.stdoutText) + (await resposta.stderrText)).trim();
    const ok = (await resposta.exitCode) === 0;
    if (!ok) falhas++;
    console.log(`${ok ? 'OK  ' : 'ERRO'} ${arquivo}${ok ? '' : '\n     ' + saida.split('\n').pop()}`);
}
// process.exit: o php-wasm mantém o event loop vivo e o Node não terminaria sozinho
process.exit(falhas ? 1 : 0);
```

- [ ] **Passo 3: conferir num arquivo bom e num com erro**

```bash
cd /c/tmp/gm && printf '<?php\nclass A { public function f() { return 1 } }\n' > ruim.php
PHP_WASM_DIR=$SCRATCH/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/CalculoEntregas.php ruim.php; echo "saída=$?"; rm -f ruim.php
```
Esperado: `OK   api/app/Support/Entregas/CalculoEntregas.php`, `ERRO ruim.php` com `syntax error, unexpected token "}"`, e `saída=1`.

- [ ] **Passo 4: commit**

```bash
cd /c/tmp/gm && git rev-parse --show-toplevel && git add scripts/teste-php/sintaxe.mjs && git commit -q -m "Testes PHP: sintaxe.mjs confere arquivos com o php -l do PHP 8.2 (php-wasm)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>" && git log --oneline -1
```

---

### Tarefa 2: tabela `entregas_valores_pedido` e stubs do teste

**Files:**
- Create: `scripts/teste-php/stubs-ganhos.php`
- Create: `scripts/teste-php/ganhos-motoboy.php`
- Create: `api/database/migrations/2026_10_03_120000_create_entregas_valores_pedido_table.php`
- Modify: `api/deploy.sh` (migrations do app também no banco sandbox)

- [ ] **Passo 1: criar `scripts/teste-php/stubs-ganhos.php`** (usado por todas as seções do teste; já traz o que as Tarefas 3 a 5 precisam)

```php
<?php

// Stubs dos testes dos ganhos do motoboy (ganhos-motoboy.php). Independentes do stubs.php: o Order, o Driver e o
// Carbon daqui têm o que o CalculoEntregas, o MotoboyController e a migration usam. Sem vendor e sem banco: a tabela
// entregas_valores_pedido é um arranjo em memória (Teste\Banco) e as consultas filtram listas (Teste\Consulta).

namespace Illuminate\Support {
    class Collection implements \IteratorAggregate, \Countable, \ArrayAccess
    {
        public function __construct(protected array $itens = []) {}
        public function all(): array { return $this->itens; }
        public function count(): int { return count($this->itens); }
        public function isEmpty(): bool { return !$this->itens; }
        public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->itens); }
        public function first() { return $this->itens ? reset($this->itens) : null; }
        public function get($chave, $padrao = null) { return $this->itens[$chave] ?? $padrao; }
        public function values(): static { return new static(array_values($this->itens)); }
        public function filter(?callable $funcao = null): static { return new static($funcao ? array_filter($this->itens, $funcao) : array_filter($this->itens)); }
        public function unique(): static { return new static(array_unique($this->itens)); }
        public function pluck(string $campo): static { return new static(array_map(fn ($item) => is_array($item) ? ($item[$campo] ?? null) : ($item->$campo ?? null), $this->itens)); }

        public function keyBy(string $campo): static
        {
            $indexado = [];
            foreach ($this->itens as $item) {
                $indexado[is_array($item) ? $item[$campo] : $item->$campo] = $item;
            }

            return new static($indexado);
        }

        public function offsetExists($chave): bool { return isset($this->itens[$chave]); }
        public function offsetGet($chave): mixed { return $this->itens[$chave]; }
        public function offsetSet($chave, $valor): void { if ($chave === null) { $this->itens[] = $valor; } else { $this->itens[$chave] = $valor; } }
        public function offsetUnset($chave): void { unset($this->itens[$chave]); }
    }

    // o Carbon do Laravel, só no que o CalculoEntregas e o MotoboyController usam
    class Carbon extends \DateTime
    {
        public static function parse($valor, $fuso = null): static
        {
            return new static($valor, $fuso ? new \DateTimeZone($fuso) : null);
        }

        public static function createFromFormat($formato, $valor, $fuso = null): static|false
        {
            $data = \DateTime::createFromFormat($formato, $valor, is_string($fuso) ? new \DateTimeZone($fuso) : $fuso);

            return $data ? new static($data->format('Y-m-d H:i:s.u'), $data->getTimezone()) : false;
        }

        public function setTimezone($fuso): static
        {
            parent::setTimezone(is_string($fuso) ? new \DateTimeZone($fuso) : $fuso);

            return $this;
        }

        public function startOfDay(): static { $this->setTime(0, 0, 0, 0); return $this; }
        public function endOfDay(): static { $this->setTime(23, 59, 59, 999999); return $this; }
        public function utc(): static { return $this->setTimezone('UTC'); }
        public function toIso8601String(): string { return $this->format('Y-m-d\TH:i:sP'); }
    }
}

namespace Illuminate\Support\Facades {
    class DB
    {
        public static function table(string $tabela) { return new \Teste\Tabela($tabela); }
        public static function raw($sql) { return $sql; }
    }

    class Log
    {
        public static array $registros = [];
        public static function info($mensagem, array $contexto = []) { self::$registros[] = ['info', $mensagem, $contexto]; }
        public static function warning($mensagem, array $contexto = []) { self::$registros[] = ['warning', $mensagem, $contexto]; }
        public static function error($mensagem, array $contexto = []) { self::$registros[] = ['error', $mensagem, $contexto]; }
    }

    // a migration: Schema::create entrega um Blueprint que só registra as colunas
    class Schema
    {
        public static array $criadas = [];

        public static function create(string $tabela, \Closure $definicao): void
        {
            $blueprint = new \Illuminate\Database\Schema\Blueprint();
            $definicao($blueprint);
            self::$criadas[$tabela] = $blueprint;
        }

        public static function dropIfExists(string $tabela): void { unset(self::$criadas[$tabela]); }
    }
}

namespace Illuminate\Database\Migrations {
    abstract class Migration {}
}

namespace Illuminate\Database\Schema {
    class Blueprint
    {
        /** @var \Teste\Coluna[] */
        public array $colunas = [];

        public function __call($tipo, $argumentos)
        {
            return $this->colunas[] = new \Teste\Coluna($tipo, $argumentos);
        }
    }
}

namespace Illuminate\Http {
    class Request
    {
        public function __construct(public array $dados = [], public ?string $token = null) {}
        public function bearerToken() { return $this->token; }
        public function input($chave, $padrao = null) { return $this->dados[$chave] ?? $padrao; }
        // o formato das datas é conferido pelo Laravel (não testado aqui)
        public function validate(array $regras) { return $this->dados; }
    }
}

namespace App\Http\Controllers {
    // o real estende o Controller do Laravel; os nossos controllers só herdam dele
    class Controller {}
}

namespace Fleetbase\Models {
    class Setting
    {
        public static array $valores = [];
        public static function lookupCompany($chave, $padrao = null) { return self::$valores[$chave] ?? $padrao; }
    }

    class Company
    {
        public static string $fuso = 'America/Sao_Paulo';
        public static function where($coluna, $valor) { return new \Teste\ValorFixo(self::$fuso); }
    }
}

namespace Fleetbase\FleetOps\Support {
    class OSRM
    {
        public static int $chamadas = 0;
        /** Metros da rota que o OSRM devolve; null = o serviço falha. */
        public static ?float $metros = 3210.4;

        public static function getRouteFromCoordinatesString($coordenadas, $opcoes = [])
        {
            self::$chamadas++;
            if (self::$metros === null) {
                throw new \RuntimeException('OSRM fora do ar');
            }

            return ['code' => 'Ok', 'routes' => [['distance' => self::$metros]]];
        }
    }

    class Utils
    {
        /** Distância em linha reta, em metros (a estimativa do cálculo multiplica por 1,3). */
        public static float $linhaReta = 2000;
        public static function calculateDrivingDistanceAndTime($origem, $destino) { return (object) ['distance' => self::$linhaReta, 'time' => 0]; }
    }
}

namespace Fleetbase\FleetOps\Models {
    trait PreencheAtributos
    {
        public function __construct(array $atributos = [])
        {
            foreach ($atributos as $chave => $valor) {
                $this->$chave = $valor;
            }
        }
    }

    class Place
    {
        use PreencheAtributos;
        public $uuid;
        public $public_id;
        public $name;
        public $street1;
        public $street2;
        public $neighborhood;
        public $address;
        public $city;
        public $location;
    }

    class Payload
    {
        public function __construct(public ?Place $pickup = null, public ?Place $dropoff = null) {}
        public function getPickupOrFirstWaypoint() { return $this->pickup; }
        public function getDropoffOrLastWaypoint() { return $this->dropoff; }
    }

    class Vendor
    {
        use PreencheAtributos;
        public static array $lojas = [];
        public $uuid;
        public $public_id;
        public $name;
        public static function withTrashed() { return new \Teste\ConsultaLojas(); }
    }

    class Driver
    {
        use PreencheAtributos;
        public static array $todos = [];
        public $uuid;
        public $public_id;
        public $name;
        public $user_uuid;
        public $company_uuid = 'empresa';
        public static function where($coluna, $valor) { return (new \Teste\Consulta(self::$todos))->where($coluna, $valor); }
    }

    class Order
    {
        use PreencheAtributos;
        public static array $todos = [];
        public $uuid;
        public $public_id;
        public $company_uuid          = 'empresa';
        public $status                = 'completed';
        public $adhoc                 = false;
        public $driver_assigned_uuid  = null;
        public $customer_uuid         = null;
        public $internal_id           = null;
        public $payload               = null;
        public $driverAssigned        = null;
        public $entregas_concluido_em = '2026-10-03 22:42:00';
        public $timestamps            = true;
        public array $meta            = [];

        public function getMeta($chave) { return \Teste\pegar($this->meta, $chave); }
        public function updateMeta($chave, $valor) { \Teste\definir($this->meta, $chave, $valor); return true; }
        public static function where($coluna, $valor = null) { return (new \Teste\Consulta(self::$todos))->where($coluna, $valor); }
    }
}

namespace Teste {
    class Ponto
    {
        public function __construct(private float $lat, private float $lng) {}
        public function getLat() { return $this->lat; }
        public function getLng() { return $this->lng; }
    }

    class Coluna
    {
        public array $modificadores = [];
        public function __construct(public string $tipo, public array $argumentos) {}
        public function __call($modificador, $argumentos) { $this->modificadores[$modificador] = $argumentos; return $this; }
    }

    class ValorFixo
    {
        public function __construct(private $valor) {}
        public function value($coluna) { return $this->valor; }
    }

    class Resposta
    {
        public function __construct(public $dados, public int $status = 200) {}
    }

    // tabela entregas_valores_pedido em memória: order_uuid => linha
    class Banco
    {
        public static array $tabelas = [];
        public static int $upserts   = 0;
    }

    class Tabela
    {
        private ?array $filtro = null;
        public function __construct(private string $nome) {}

        public function whereIn($coluna, array $valores) { $this->filtro = [$coluna, $valores]; return $this; }

        public function get()
        {
            [$coluna, $valores] = $this->filtro ?? [null, []];
            $linhas             = array_filter(Banco::$tabelas[$this->nome] ?? [], fn ($linha) => $coluna === null || in_array($linha[$coluna], $valores, true));

            return new \Illuminate\Support\Collection(array_values(array_map(fn ($linha) => (object) static::comoMysql($linha), $linhas)));
        }

        public function upsert(array $linhas, $unicas, array $atualizar)
        {
            Banco::$upserts++;
            foreach ($linhas as $linha) {
                $chave     = $linha['order_uuid'];
                $existente = Banco::$tabelas[$this->nome][$chave] ?? null;
                if ($existente === null) {
                    Banco::$tabelas[$this->nome][$chave] = $linha;
                    continue;
                }
                foreach ($atualizar as $coluna) {
                    // no MySQL, a coluna da lista de atualização que não veio na linha daria erro ou voltaria ao padrão
                    if (!array_key_exists($coluna, $linha)) {
                        throw new \LogicException("upsert: a coluna {$coluna} não veio na linha");
                    }
                    $existente[$coluna] = $linha[$coluna];
                }
                Banco::$tabelas[$this->nome][$chave] = $existente;
            }

            return count($linhas);
        }

        // como o PDO do MySQL devolve: decimal em texto ("8.00"), booleano em inteiro
        private static function comoMysql(array $linha): array
        {
            foreach (['de_km', 'ate_km', 'valor_motoboy', 'valor_loja'] as $coluna) {
                $linha[$coluna] = number_format((float) $linha[$coluna], 2, '.', '');
            }
            $linha['acima'] = (int) !empty($linha['acima']);

            return $linha;
        }
    }

    // consulta sobre uma lista de objetos, com a precedência do SQL: OU de grupos E (o AND liga mais forte que o OR, e um
    // where com closure vira um subgrupo entre parênteses). Assim, uma consulta sem os parênteses certos falha no teste
    // como falharia no MySQL (ex.: a empresa escapando por um orWhere solto)
    class Consulta
    {
        private array $grupos = [[]];
        public function __construct(private array $itens) {}

        public function where($coluna, $valor = null)
        {
            $this->grupos[array_key_last($this->grupos)][] = $coluna instanceof \Closure ? $this->subgrupo($coluna) : fn ($item) => $item->$coluna === $valor;

            return $this;
        }

        public function orWhere($coluna, $valor = null)
        {
            $this->grupos[] = [];

            return $this->where($coluna, $valor);
        }

        public function with($relacoes) { return $this; }

        public function first()
        {
            foreach ($this->itens as $item) {
                if ($this->passa($item)) {
                    return $item;
                }
            }

            return null;
        }

        private function subgrupo(\Closure $definicao): \Closure
        {
            $subgrupo = new self([]);
            $definicao($subgrupo);

            return fn ($item) => $subgrupo->passa($item);
        }

        private function passa($item): bool
        {
            foreach ($this->grupos as $grupo) {
                foreach ($grupo as $condicao) {
                    if (!$condicao($item)) {
                        continue 2;
                    }
                }

                return true;
            }

            return false;
        }
    }

    class ConsultaLojas
    {
        private array $uuids = [];
        public function without($relacao) { return $this; }
        public function whereIn($coluna, $valores) { $this->uuids = $valores instanceof \Illuminate\Support\Collection ? $valores->all() : (array) $valores; return $this; }
        public function get() { return new \Illuminate\Support\Collection(array_values(array_filter(\Fleetbase\FleetOps\Models\Vendor::$lojas, fn ($loja) => in_array($loja->uuid, $this->uuids, true)))); }
    }

    // a query que o filtro do pedidosConcluidos recebe: só registra os where
    class ConsultaRegistrada
    {
        public array $wheres = [];
        public function where($coluna, $valor = null) { $this->wheres[] = [$coluna, $valor]; return $this; }
    }

    function pegar(array $dados, string $chave)
    {
        foreach (explode('.', $chave) as $parte) {
            if (!is_array($dados) || !array_key_exists($parte, $dados)) {
                return null;
            }
            $dados = $dados[$parte];
        }

        return $dados;
    }

    function definir(array &$dados, string $chave, $valor): void
    {
        $atual = &$dados;
        foreach (explode('.', $chave) as $parte) {
            if (!isset($atual[$parte]) || !is_array($atual[$parte])) {
                $atual[$parte] = [];
            }
            $atual = &$atual[$parte];
        }
        $atual = $valor;
    }
}

namespace {
    function session($chave = null)
    {
        return $chave === null ? $GLOBALS['sessao'] : ($GLOBALS['sessao'][$chave] ?? null);
    }

    function response()
    {
        return new class {
            public function json($dados = [], $status = 200) { return new \Teste\Resposta($dados, $status); }
        };
    }

    function now()
    {
        return new \Illuminate\Support\Carbon('now', new \DateTimeZone('UTC'));
    }

    function collect($itens = [])
    {
        return new \Illuminate\Support\Collection(is_array($itens) ? $itens : iterator_to_array($itens));
    }

    function data_get($alvo, $chave, $padrao = null)
    {
        foreach (explode('.', (string) $chave) as $parte) {
            if (is_array($alvo) && array_key_exists($parte, $alvo)) {
                $alvo = $alvo[$parte];
            } elseif (is_object($alvo) && isset($alvo->$parte)) {
                $alvo = $alvo->$parte;
            } else {
                return $padrao;
            }
        }

        return $alvo;
    }

    $GLOBALS['sessao'] = ['company' => 'empresa', 'user' => 'usuario-motoca'];

    // classes do api/app (App\...) carregadas direto do repositório montado em /repo
    spl_autoload_register(function (string $classe) {
        if (str_starts_with($classe, 'App\\')) {
            $arquivo = '/repo/api/app/' . str_replace('\\', '/', substr($classe, 4)) . '.php';
            if (is_file($arquivo)) {
                require $arquivo;
            }
        }
    });

    // como o HandleExceptions do Laravel: warning e notice viram ErrorException; deprecation só aparece na saída
    set_error_handler(function (int $nivel, string $mensagem, string $arquivo = '', int $linha = 0): bool {
        if (in_array($nivel, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
            return false;
        }

        throw new \ErrorException($mensagem, 0, $nivel, $arquivo, $linha);
    });

    $GLOBALS['falhas'] = 0;

    function confere(bool $ok, string $descricao): void
    {
        if (!$ok) {
            $GLOBALS['falhas']++;
        }
        echo ($ok ? 'PASSA ' : 'FALHA ') . $descricao . PHP_EOL;
    }

    function resumo(): void
    {
        echo PHP_EOL . 'FALHAS: ' . $GLOBALS['falhas'] . PHP_EOL;
    }
}
```

- [ ] **Passo 2: criar `scripts/teste-php/ganhos-motoboy.php` com a seção da migration** (as Tarefas 3 a 5 acrescentam seções antes do `resumo();`)

```php
<?php

// Ganhos do motoboy: valor congelado das entregas (CalculoEntregas + ValoresCongelados), o que o app recebe
// (GanhosDoMotoboy), quem é o motoboy da requisição (MotoboyDaSessao), as rotas (MotoboyController) e a migration.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ganhos-motoboy.php

require __DIR__ . '/stubs-ganhos.php';

use App\Http\Controllers\Entregas\MotoboyController;
use App\Support\Entregas\CalculoEntregas;
use App\Support\Entregas\GanhosDoMotoboy;
use App\Support\Entregas\MotoboyDaSessao;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Support\OSRM;
use Fleetbase\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Teste\Banco;
use Teste\ConsultaRegistrada;
use Teste\Ponto;

echo '== Migration da tabela entregas_valores_pedido' . PHP_EOL;
$migration = require '/repo/api/database/migrations/2026_10_03_120000_create_entregas_valores_pedido_table.php';
$migration->up();
$colunas = [];
foreach ((Schema::$criadas['entregas_valores_pedido'] ?? null)?->colunas ?? [] as $coluna) {
    $colunas[$coluna->argumentos[0] ?? $coluna->tipo] = $coluna;
}
confere(isset($colunas['order_uuid']) && array_key_exists('unique', $colunas['order_uuid']->modificadores), 'order_uuid único: um valor por pedido');
confere(isset($colunas['company_uuid']) && array_key_exists('index', $colunas['company_uuid']->modificadores), 'company_uuid com índice');
confere(($colunas['valor_motoboy']->argumentos ?? null) === ['valor_motoboy', 10, 2] && ($colunas['valor_loja']->argumentos ?? null) === ['valor_loja', 10, 2], 'valores em decimal(10,2)');
confere(($colunas['de_km']->argumentos ?? null) === ['de_km', 8, 2] && ($colunas['ate_km']->argumentos ?? null) === ['ate_km', 8, 2], 'limites da faixa em decimal(8,2)');
confere(array_key_exists('nullable', $colunas['chave']->modificadores ?? []) && ($colunas['acima']->modificadores['default'] ?? null) === [false], 'chave pode ser nula; acima começa falso');
confere(isset($colunas['id'], $colunas['metros'], $colunas['fonte'], $colunas['timestamps']), 'id, metros, fonte e timestamps');
$migration->down();
confere(!isset(Schema::$criadas['entregas_valores_pedido']), 'o down apaga a tabela');

resumo();
```

- [ ] **Passo 3: rodar e ver falhar**

```bash
cd /c/tmp/gm && PHP_WASM_DIR=$SCRATCH/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ganhos-motoboy.php; echo "saída=$?"
```
Esperado: erro de PHP ao abrir a migration (`require(/repo/api/database/migrations/2026_10_03_120000_...): Failed to open stream: No such file or directory`) e `saída=1`.

- [ ] **Passo 4: criar a migration `api/database/migrations/2026_10_03_120000_create_entregas_valores_pedido_table.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: valor congelado de cada entrega (App\Support\Entregas\CalculoEntregas e ValoresCongelados).
 * Fica numa tabela própria, e não no `meta` do pedido, porque o `meta` sai na API v1 e no socket: aqui estão o valor
 * pago ao motoboy e o cobrado da loja. Roda no `php artisan migrate --force` do deploy.sh, no banco principal e no sandbox
 * (o sandbox:migrate dos pacotes não roda esta pasta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_valores_pedido', function (Blueprint $table) {
            $table->id();
            $table->char('company_uuid', 36)->index();
            $table->char('order_uuid', 36)->unique();
            // chave das coordenadas da rota (a mesma do meta.entregas.km_rota do pedido)
            $table->string('chave', 32)->nullable();
            // km usado no cálculo, em metros, e de onde veio (osrm ou estimativa)
            $table->unsignedInteger('metros');
            $table->string('fonte', 20);
            // faixa e valores da tabela vigente quando a entrega foi calculada
            $table->decimal('de_km', 8, 2);
            $table->decimal('ate_km', 8, 2);
            $table->boolean('acima')->default(false);
            $table->decimal('valor_motoboy', 10, 2);
            $table->decimal('valor_loja', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_valores_pedido');
    }
};
```

- [ ] **Passo 5: rodar e ver passar; conferir a sintaxe**

```bash
cd /c/tmp/gm && PHP_WASM_DIR=$SCRATCH/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ganhos-motoboy.php; echo "saída=$?"
PHP_WASM_DIR=$SCRATCH/php-wasm node scripts/teste-php/sintaxe.mjs api/database/migrations/2026_10_03_120000_create_entregas_valores_pedido_table.php scripts/teste-php/stubs-ganhos.php scripts/teste-php/ganhos-motoboy.php
```
Esperado: 7 linhas `PASSA`, `FALHAS: 0`, `saída=0`; os três arquivos com `OK`.

- [ ] **Passo 6: `api/deploy.sh`, as migrations do app também no banco sandbox** — o modo de teste do console usa o banco sandbox, e o `sandbox:migrate` só roda as migrations dos pacotes. Sem esta linha, "Pagamento e cobrança" em modo de teste daria 500 depois da Tarefa 3. Em `api/deploy.sh`, trocar:

```sh
# Run migrations for sandbox too
php artisan sandbox:migrate --force
```
por:
```sh
# Run migrations for sandbox too
php artisan sandbox:migrate --force

# Entregas RestaurantePro: as migrations do app (api/database/migrations) também no banco sandbox, que o modo de teste
# do console usa; o sandbox:migrate acima só roda as dos pacotes (mesma conexão: SANDBOX_DB_CONNECTION, padrão "sandbox")
php artisan migrate --force --database="${SANDBOX_DB_CONNECTION:-sandbox}" --path=database/migrations
```
Conferir a sintaxe do shell:

```bash
cd /c/tmp/gm && bash -n api/deploy.sh && echo SINTAXE_SH_OK
```
Esperado: `SINTAXE_SH_OK`.

- [ ] **Passo 7: commit**

```bash
cd /c/tmp/gm && git rev-parse --show-toplevel && git add api/database/migrations/2026_10_03_120000_create_entregas_valores_pedido_table.php api/deploy.sh scripts/teste-php/stubs-ganhos.php scripts/teste-php/ganhos-motoboy.php && git commit -q -m "API: tabela entregas_valores_pedido (valor congelado de cada entrega) e teste da migration

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>" && git log --oneline -1
```

---

### Tarefa 3: valor congelado no `CalculoEntregas`

**Files:**
- Create: `api/app/Support/Entregas/ValoresCongelados.php`
- Modify: `api/app/Support/Entregas/CalculoEntregas.php` (docblock da classe; construtor; `entregas()`; métodos novos `valorDoPedido()`, `valorCongelado()`, `valorDaLinha()`)
- Test: `scripts/teste-php/ganhos-motoboy.php`

- [ ] **Passo 1: acrescentar a seção do congelamento ao teste** — em `scripts/teste-php/ganhos-motoboy.php`, colar este bloco **imediatamente antes** da última linha (`resumo();`):

```php
echo '== Valor congelado (CalculoEntregas)' . PHP_EOL;

// faixas da central: o valor cobrado da loja na faixa de 2 a 4 km é 11,37, um número que não aparece em mais nada
const FAIXAS = [
    ['ate_km' => 1, 'motoboy' => 5, 'loja' => 7],
    ['ate_km' => 2, 'motoboy' => 6, 'loja' => 8],
    ['ate_km' => 4, 'motoboy' => 8, 'loja' => 11.37],
    ['ate_km' => 6, 'motoboy' => 10, 'loja' => 14],
];

function reiniciar(array $faixas = FAIXAS): void
{
    Setting::$valores = ['entregas.faixas' => $faixas];
    Banco::$tabelas   = [];
    Banco::$upserts   = 0;
    OSRM::$chamadas   = 0;
    OSRM::$metros     = 3210.4;
}

function lugar(string $id, ?float $lat, ?float $lng, string $nome): Place
{
    return new Place(['uuid' => 'uuid-' . $id, 'public_id' => $id, 'name' => $nome, 'street1' => 'Rua ' . $nome . ', 10', 'neighborhood' => 'Centro', 'location' => $lat === null ? null : new Ponto($lat, $lng)]);
}

// pedido concluído do motoboy Motoca, da Loja Centro para um cliente a uns 3 km
function pedido(string $id, array $atributos = []): Order
{
    return new Order($atributos + [
        'uuid'                 => 'uuid-' . $id,
        'public_id'            => $id,
        'customer_uuid'        => 'uuid-vendor_centro',
        'driver_assigned_uuid' => 'uuid-driver_motoca',
        'driverAssigned'       => new Driver(['uuid' => 'uuid-driver_motoca', 'public_id' => 'driver_motoca', 'name' => 'Motoca']),
        'payload'              => new Payload(lugar('place_loja', -21.17, -47.81, 'Loja Centro'), lugar('place_cliente-' . $id, -21.2, -47.8, 'Cliente')),
    ]);
}

// km já calculado e guardado no meta (como o relatório deixa), com a chave das coordenadas atuais do pedido
function comKm(Order $pedido, float $metros, string $fonte = 'osrm'): Order
{
    $o            = $pedido->payload->pickup->location;
    $d            = $pedido->payload->dropoff->location;
    $pedido->meta = ['entregas' => ['km_rota' => ['metros' => $metros, 'fonte' => $fonte, 'chave' => md5(implode(',', [$o->getLat(), $o->getLng(), $d->getLat(), $d->getLng()]))]]];

    return $pedido;
}

$calculo       = new CalculoEntregas();
Vendor::$lojas = [new Vendor(['uuid' => 'uuid-vendor_centro', 'public_id' => 'vendor_centro', 'name' => 'Loja Centro'])];

reiniciar();
$pedidos                = new Collection([comKm(pedido('order_a'), 3210.4)]);
[$entregas, $pendentes] = $calculo->entregas($pedidos, 'America/Sao_Paulo');
confere($entregas[0]['km'] === 3.21 && $entregas[0]['valor_motoboy'] === 8.0 && $entregas[0]['valor_loja'] === 11.37, 'primeira consulta: valores da faixa de 2 a 4 km (8,00 / 11,37)');
$linha = Banco::$tabelas['entregas_valores_pedido']['uuid-order_a'] ?? [];
confere(($linha['metros'] ?? null) === 3210 && ($linha['fonte'] ?? null) === 'osrm' && ($linha['valor_motoboy'] ?? null) === 8.0 && ($linha['valor_loja'] ?? null) === 11.37, 'a linha congelada guarda o km e os dois valores');
confere(($linha['company_uuid'] ?? null) === 'empresa' && ($linha['de_km'] ?? null) === 2.0 && ($linha['ate_km'] ?? null) === 4.0 && ($linha['acima'] ?? null) === false, 'e a empresa e os limites da faixa');
confere(Banco::$upserts === 1 && $pendentes === 0 && OSRM::$chamadas === 0, 'uma gravação só; com o km guardado, o OSRM não é chamado');
$faltando = array_diff(array_keys($linha), array_keys($colunas), ['created_at', 'updated_at']);
confere($linha && !$faltando, 'toda coluna gravada existe na migration' . ($faltando ? ' (faltam: ' . implode(', ', $faltando) . ')' : ''));

Setting::$valores['entregas.faixas'] = [['ate_km' => 4, 'motoboy' => 9, 'loja' => 12], ['ate_km' => 6, 'motoboy' => 12, 'loja' => 16]];
[$entregas]                          = $calculo->entregas($pedidos, 'America/Sao_Paulo');
confere($entregas[0]['valor_motoboy'] === 8.0 && $entregas[0]['valor_loja'] === 11.37, 'tabela nova: o pedido já calculado continua com 8,00 / 11,37 (a nova daria 9,00 / 12,00)');
confere($entregas[0]['faixa'] === ['de_km' => 2.0, 'ate_km' => 4.0, 'motoboy' => 8.0, 'loja' => 11.37], 'a faixa vem da linha congelada, em números (o banco devolve texto)');
confere(Banco::$upserts === 1, 'nada é regravado');

comKm($pedidos->first(), 4500.0);
[$entregas] = $calculo->entregas($pedidos, 'America/Sao_Paulo');
confere($entregas[0]['valor_motoboy'] === 12.0 && $entregas[0]['valor_loja'] === 16.0, 'km diferente (4,5): recalcula com a tabela vigente, faixa de 4 a 6 km (12,00 / 16,00)');
$regravada = Banco::$tabelas['entregas_valores_pedido']['uuid-order_a'] ?? [];
confere(($regravada['metros'] ?? null) === 4500 && ($regravada['de_km'] ?? null) === 4.0 && ($regravada['ate_km'] ?? null) === 6.0 && ($regravada['valor_motoboy'] ?? null) === 12.0 && ($regravada['valor_loja'] ?? null) === 16.0 && Banco::$upserts === 2, 'e regrava a linha com o km, a faixa e os valores novos');

reiniciar();
$longe      = new Collection([comKm(pedido('order_longe'), 8000.0)]);
[$entregas] = $calculo->entregas($longe, 'America/Sao_Paulo');
confere(($entregas[0]['faixa']['acima'] ?? false) === true && $entregas[0]['valor_motoboy'] === 10.0, 'km 8: acima da última faixa vale a última (10,00)');
[$entregas] = $calculo->entregas($longe, 'America/Sao_Paulo');
confere(($entregas[0]['faixa']['acima'] ?? false) === true && $entregas[0]['faixa']['de_km'] === 6.0 && Banco::$upserts === 1, 'lida do banco, continua "acima de 6 km", sem regravar');

reiniciar();
[$entregas] = $calculo->entregas(new Collection([comKm(pedido('order_limite'), 1004.6)]), 'America/Sao_Paulo');
confere($entregas[0]['km'] === 1.0 && $entregas[0]['valor_motoboy'] === 5.0, 'a faixa sai do km exibido: 1.004,6 m = 1,00 km, primeira faixa (5,00)');

reiniciar([]);
[$entregas] = $calculo->entregas(new Collection([comKm(pedido('order_b'), 1500.0)]), 'America/Sao_Paulo');
confere($entregas[0]['km'] === 1.5 && $entregas[0]['faixa'] === null && $entregas[0]['valor_motoboy'] === null && $entregas[0]['valor_loja'] === null, 'sem faixas cadastradas: km aparece, valores nulos');
confere(Banco::$upserts === 0 && empty(Banco::$tabelas['entregas_valores_pedido']), 'e nada é gravado');

reiniciar();
$semPosicao             = pedido('order_c', ['payload' => new Payload(lugar('place_loja', -21.17, -47.81, 'Loja Centro'), lugar('place_x', null, null, 'Cliente'))]);
[$entregas, $pendentes] = $calculo->entregas(new Collection([$semPosicao]), 'America/Sao_Paulo');
confere($pendentes === 1 && $entregas[0]['km'] === null && $entregas[0]['valor_motoboy'] === null && Banco::$upserts === 0, 'destino sem posição e sem km guardado: pendente, sem valor');

reiniciar();
[$entregas] = $calculo->entregas(new Collection([comKm(pedido('order_d'), 3210.4)]), 'America/Sao_Paulo');
confere(array_keys($entregas[0]) === ['loja', 'loja_nome', 'pedido', 'id_interno', 'motoboy', 'motoboy_nome', 'concluido_em', 'origem', 'destino', 'km', 'fonte', 'faixa', 'valor_motoboy', 'valor_loja'], 'o formato do relatório e do extrato não muda');
confere($entregas[0]['loja'] === 'loja:vendor_centro' && $entregas[0]['concluido_em'] === '2026-10-03T19:42:00-03:00', "loja dona do pedido e conclusão no fuso da organização ({$entregas[0]['concluido_em']})");

echo '== Valor de um pedido (card de aceitar e detalhes)' . PHP_EOL;
reiniciar();
$aberto = pedido('order_e', ['status' => 'dispatched', 'adhoc' => true, 'driver_assigned_uuid' => null, 'driverAssigned' => null]);
$valor  = $calculo->valorDoPedido($aberto);
confere($valor === ['km' => 3.21, 'fonte' => 'osrm', 'faixa' => ['de_km' => 2.0, 'ate_km' => 4.0, 'motoboy' => 8.0, 'loja' => 11.37], 'valor_motoboy' => 8.0], 'sem km guardado: calcula a rota na hora (3,21 km → 8,00)');
confere(OSRM::$chamadas === 1 && ($aberto->meta['entregas']['km_rota']['metros'] ?? null) === 3210.4 && isset(Banco::$tabelas['entregas_valores_pedido']['uuid-order_e']), 'chama o OSRM uma vez, guarda o km no pedido e congela o valor');
$valor = $calculo->valorDoPedido($aberto);
confere(OSRM::$chamadas === 1 && Banco::$upserts === 1 && $valor['valor_motoboy'] === 8.0, 'a segunda consulta não chama o OSRM nem regrava');

reiniciar();
OSRM::$metros = null;
$valor        = $calculo->valorDoPedido(pedido('order_f', ['status' => 'dispatched', 'adhoc' => true, 'driver_assigned_uuid' => null]));
confere($valor['fonte'] === 'estimativa' && $valor['km'] === 2.6 && $valor['valor_motoboy'] === 8.0, "OSRM fora do ar: estimativa (2.000 m em linha reta × 1,3 = {$valor['km']} km)");

reiniciar();
$valor = $calculo->valorDoPedido(pedido('order_g', ['payload' => new Payload(lugar('place_loja', -21.17, -47.81, 'Loja Centro'), lugar('place_y', null, null, 'Cliente'))]));
confere($valor === ['km' => null, 'fonte' => null, 'faixa' => null, 'valor_motoboy' => null] && OSRM::$chamadas === 0, 'destino sem posição: km e valor nulos');
```

- [ ] **Passo 2: rodar e ver falhar**

```bash
cd /c/tmp/gm && PHP_WASM_DIR=$SCRATCH/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ganhos-motoboy.php; echo "saída=$?"
```
Esperado: a seção da migration passa; na do congelamento, `FALHA a linha congelada guarda o km e os dois valores` e `FALHA tabela nova: ...` (hoje nada é gravado e o valor sai sempre da tabela vigente) e, adiante, erro de PHP `Call to undefined method App\Support\Entregas\CalculoEntregas::valorDoPedido()`; `saída=1`.

- [ ] **Passo 3: criar `api/app/Support/Entregas/ValoresCongelados.php`**

```php
<?php

namespace App\Support\Entregas;

use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: acesso à tabela entregas_valores_pedido, o valor congelado de cada entrega.
 * Quando congelar e quando recalcular é regra do CalculoEntregas; aqui só a leitura e a gravação.
 *
 * Fica fora do `meta` do pedido de propósito: o `meta` sai na API v1 (recurso Order) e nos eventos do socket, e aqui
 * estão o valor pago ao motoboy e o cobrado da loja.
 */
class ValoresCongelados
{
    public const TABELA = 'entregas_valores_pedido';

    /** Colunas regravadas quando o pedido já tem valor e o km mudou. */
    public const COLUNAS_ATUALIZADAS = ['chave', 'metros', 'fonte', 'de_km', 'ate_km', 'acima', 'valor_motoboy', 'valor_loja', 'updated_at'];

    /**
     * Linhas dos pedidos, indexadas pelo order_uuid (arrays, com os decimais como o banco devolve: texto).
     *
     * @param array<int, string|null> $orderUuids
     */
    public function carregar(array $orderUuids): array
    {
        $orderUuids = array_values(array_unique(array_filter($orderUuids)));
        $linhas     = [];

        // em lotes: o relatório de 3 meses da central pode passar de mil pedidos
        foreach (array_chunk($orderUuids, 1000) as $lote) {
            foreach (DB::table(static::TABELA)->whereIn('order_uuid', $lote)->get() as $linha) {
                $linhas[$linha->order_uuid] = (array) $linha;
            }
        }

        return $linhas;
    }

    /** Grava as linhas novas e regrava as dos pedidos cujo km mudou: um upsert por lote. */
    public function gravar(array $linhas): void
    {
        if (!$linhas) {
            return;
        }

        $agora  = now();
        $linhas = array_map(fn (array $linha) => $linha + ['created_at' => $agora, 'updated_at' => $agora], array_values($linhas));

        foreach (array_chunk($linhas, 500) as $lote) {
            DB::table(static::TABELA)->upsert($lote, ['order_uuid'], static::COLUNAS_ATUALIZADAS);
        }
    }
}
```

- [ ] **Passo 4: docblock da classe `CalculoEntregas`** — em `api/app/Support/Entregas/CalculoEntregas.php`, trocar:

```php
 * O período é filtrado pela data em que o pedido foi concluído (tracking status COMPLETED),
 * no fuso da organização.
 */
class CalculoEntregas
```
por:
```php
 * O período é filtrado pela data em que o pedido foi concluído (tracking status COMPLETED),
 * no fuso da organização.
 *
 * O valor de cada entrega é congelado (ValoresCongelados, tabela entregas_valores_pedido): na primeira vez que o pedido
 * tem km e há faixas cadastradas, a faixa e os dois valores ficam gravados, e uma tabela de faixas nova só vale para as
 * entregas calculadas depois (o app do motoboy calcula ao mostrar o pedido no card de aceitar). Se o km mudar (endereço
 * alterado, estimativa trocada pela rota do OSRM), o valor é recalculado com a tabela vigente.
 */
class CalculoEntregas
```

- [ ] **Passo 5: construtor** — no mesmo arquivo, trocar:

```php
    protected bool $osrmFalhou = false;

```
por:
```php
    protected bool $osrmFalhou = false;

    protected ValoresCongelados $congelados;

    public function __construct(?ValoresCongelados $congelados = null)
    {
        $this->congelados = $congelados ?? new ValoresCongelados();
    }

```

- [ ] **Passo 6: `entregas()` lendo e gravando o valor congelado** — trocar o método inteiro (do docblock `Uma linha por pedido...` até o `return [$entregas, $pendentes];` e a chave que fecha o método) por:

```php
    /**
     * Uma linha por pedido, na ordem de conclusão: loja, motoboy, km, faixa e os dois valores (congelados: ver
     * valorCongelado). Calcula no máximo $limiteCalculos rotas (o extrato do portal da loja e o app do motoboy passam um
     * limite menor), primeiro as das entregas sem km e depois as estimativas a refazer.
     * Retorna [entregas, pendentes] (pendentes = pedidos ainda sem km).
     */
    public function entregas(Collection $pedidos, string $fuso, int $limiteCalculos = self::LIMITE_CALCULOS): array
    {
        $this->osrmFalhou = false;

        $pendentes  = 0;
        $entregas   = [];
        $gravar     = [];
        $faixas     = $this->faixas();
        $lojas      = $this->lojasDosPedidos($pedidos);
        $comCota    = $this->pedidosComCota($pedidos, $limiteCalculos);
        $congelados = $this->congelados->carregar($pedidos->pluck('uuid')->all());

        foreach ($pedidos as $i => $pedido) {
            $rota = $this->rotaDoPedido($pedido, isset($comCota[$i]));
            if ($rota === null) {
                $pendentes++;
            }

            $motoboy           = $pedido->driverAssigned;
            $coleta            = $pedido->payload?->getPickupOrFirstWaypoint();
            $km                = $rota ? round($rota['metros'] / 1000, 2) : null;
            $valor             = $rota ? $this->valorCongelado($pedido, $rota, $faixas, $congelados[$pedido->uuid] ?? null, $gravar) : null;
            [$loja, $lojaNome] = $this->lojaDoPedido($pedido, $coleta, $lojas);

            $entregas[] = [
                'loja'          => $loja,
                'loja_nome'     => $lojaNome,
                'pedido'        => $pedido->public_id,
                'id_interno'    => $pedido->internal_id,
                'motoboy'       => $motoboy?->public_id,
                'motoboy_nome'  => $motoboy?->name,
                'concluido_em'  => Carbon::parse($pedido->entregas_concluido_em, 'UTC')->setTimezone($fuso)->toIso8601String(),
                'origem'        => $this->enderecoCurto($coleta),
                'destino'       => $this->enderecoCurto($pedido->payload?->getDropoffOrLastWaypoint()),
                'km'            => $km,
                'fonte'         => $rota['fonte'] ?? null,
                'faixa'         => $valor['faixa'] ?? null,
                'valor_motoboy' => $valor['motoboy'] ?? null,
                'valor_loja'    => $valor['loja'] ?? null,
            ];
        }

        $this->congelados->gravar($gravar);

        return [$entregas, $pendentes];
    }

    /**
     * Km e valor de um pedido para o app do motoboy (card de aceitar e detalhes): calcula a rota agora, se faltar, e
     * congela o valor como em entregas().
     *
     * @return array{km: ?float, fonte: ?string, faixa: ?array, valor_motoboy: ?float}
     */
    public function valorDoPedido(Order $pedido): array
    {
        $this->osrmFalhou = false;

        $rota = $this->rotaDoPedido($pedido, true);
        if ($rota === null) {
            return ['km' => null, 'fonte' => null, 'faixa' => null, 'valor_motoboy' => null];
        }

        $gravar = [];
        $linha  = $this->congelados->carregar([$pedido->uuid])[$pedido->uuid] ?? null;
        $valor  = $this->valorCongelado($pedido, $rota, $this->faixas(), $linha, $gravar);
        $this->congelados->gravar($gravar);

        return [
            'km'            => round($rota['metros'] / 1000, 2),
            'fonte'         => $rota['fonte'] ?? null,
            'faixa'         => $valor['faixa'] ?? null,
            'valor_motoboy' => $valor['motoboy'] ?? null,
        ];
    }

    /**
     * Valor da entrega para esta rota: o congelado, se foi calculado com o mesmo km (metros); senão, a faixa da tabela
     * vigente, que entra em $gravar (congela, ou recongela quando o km mudou). Sem faixas cadastradas e sem valor
     * congelado para este km, null.
     *
     * @return array{faixa: array, motoboy: float, loja: float}|null
     */
    protected function valorCongelado(Order $pedido, array $rota, array $faixas, ?array $linha, array &$gravar): ?array
    {
        $metros = (int) round((float) $rota['metros']);
        if ($linha !== null && (int) $linha['metros'] === $metros) {
            return $this->valorDaLinha($linha);
        }

        // a faixa sai do mesmo km exibido (metros reais); os metros inteiros só identificam o km da linha congelada
        $faixa = $this->faixaDoKm($faixas, round((float) $rota['metros'] / 1000, 2));
        if ($faixa === null) {
            return null;
        }

        $nova = [
            'company_uuid'  => $pedido->company_uuid,
            'order_uuid'    => $pedido->uuid,
            'chave'         => $rota['chave'] ?? null,
            'metros'        => $metros,
            'fonte'         => $rota['fonte'] ?? 'osrm',
            'de_km'         => $faixa['de_km'],
            'ate_km'        => $faixa['ate_km'],
            'acima'         => !empty($faixa['acima']),
            'valor_motoboy' => $faixa['motoboy'],
            'valor_loja'    => $faixa['loja'],
        ];
        $gravar[] = $nova;

        return $this->valorDaLinha($nova);
    }

    /** Linha congelada no formato do relatório: a faixa (com os dois valores) e os valores soltos, em números. */
    protected function valorDaLinha(array $linha): array
    {
        $faixa = [
            'de_km'   => (float) $linha['de_km'],
            'ate_km'  => (float) $linha['ate_km'],
            'motoboy' => (float) $linha['valor_motoboy'],
            'loja'    => (float) $linha['valor_loja'],
        ];
        if (!empty($linha['acima'])) {
            $faixa['acima'] = true;
        }

        return ['faixa' => $faixa, 'motoboy' => $faixa['motoboy'], 'loja' => $faixa['loja']];
    }
```

- [ ] **Passo 7: rodar e ver passar; conferir a sintaxe e os outros testes PHP**

```bash
cd /c/tmp/gm && PHP_WASM_DIR=$SCRATCH/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ganhos-motoboy.php; echo "saída=$?"
PHP_WASM_DIR=$SCRATCH/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/CalculoEntregas.php api/app/Support/Entregas/ValoresCongelados.php
PHP_WASM_DIR=$SCRATCH/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/reenvio.php | tail -1
PHP_WASM_DIR=$SCRATCH/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/avisos-push.php | tail -1
```
Esperado: todas as linhas `PASSA` e `FALHAS: 0` (saída=0) no ganhos-motoboy; os dois arquivos `OK`; `FALHAS: 0` no reenvio e no avisos-push.

- [ ] **Passo 8: commit**

```bash
cd /c/tmp/gm && git rev-parse --show-toplevel && git add api/app/Support/Entregas/ValoresCongelados.php api/app/Support/Entregas/CalculoEntregas.php scripts/teste-php/ganhos-motoboy.php && git commit -q -m "API: valor de cada entrega congelado (relatório, extrato da loja e app usam o mesmo valor)

Na primeira vez que o pedido tem km e há faixas, a faixa e os dois valores ficam em
entregas_valores_pedido; uma tabela de faixas nova só vale para as entregas calculadas
depois. Km diferente recalcula. valorDoPedido() calcula um pedido (card do motoboy).

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>" && git log --oneline -1
```

---

### Tarefa 4: o que o app recebe (`GanhosDoMotoboy`) e quem é o motoboy (`MotoboyDaSessao`)

**Files:**
- Create: `api/app/Support/Entregas/GanhosDoMotoboy.php`
- Create: `api/app/Support/Entregas/MotoboyDaSessao.php`
- Test: `scripts/teste-php/ganhos-motoboy.php`

- [ ] **Passo 1: acrescentar as seções ao teste** — colar **imediatamente antes** de `resumo();`:

```php
echo '== O que o app recebe (GanhosDoMotoboy)' . PHP_EOL;
$entrega = [
    'loja'          => 'loja:vendor_centro',
    'loja_nome'     => 'Loja Centro',
    'pedido'        => 'order_a',
    'id_interno'    => 'IF-1',
    'motoboy'       => 'driver_motoca',
    'motoboy_nome'  => 'Motoca',
    'concluido_em'  => '2026-10-03T19:42:00-03:00',
    'origem'        => 'Rua Loja Centro, 10, Centro',
    'destino'       => 'Rua Cliente, 10, Centro',
    'km'            => 3.21,
    'fonte'         => 'estimativa',
    'faixa'         => ['de_km' => 2.0, 'ate_km' => 4.0, 'motoboy' => 8.0, 'loja' => 11.37],
    'valor_motoboy' => 8.0,
    'valor_loja'    => 11.37,
];
$linha = GanhosDoMotoboy::linha($entrega);
confere(array_keys($linha) === ['pedido', 'concluido_em', 'loja', 'destino', 'km', 'aproximado', 'faixa', 'valor'], 'só os campos do app');
confere($linha['valor'] === 8.0 && $linha['loja'] === 'Loja Centro' && $linha['aproximado'] === true, 'valor do motoboy, nome da loja e km estimado marcado como aproximado');
confere($linha['faixa'] === ['de_km' => 2.0, 'ate_km' => 4.0, 'acima' => false], 'a faixa vai só com os limites');
$semKm  = ['km' => null, 'fonte' => null, 'faixa' => null, 'valor_motoboy' => null, 'valor_loja' => null] + $entrega;
$resumo = GanhosDoMotoboy::resumo([$entrega, $semKm], '2026-10-01', '2026-10-03', 1);
confere($resumo['totais'] === ['entregas' => 2, 'km' => 3.21, 'valor' => 8.0] && $resumo['pendentes'] === 1 && $resumo['inicio'] === '2026-10-01', 'totais: corridas, km e valor (só o que tem valor)');
confere(!str_contains(json_encode($resumo), '11.37'), 'o valor cobrado da loja (11,37) não aparece');
$valor = GanhosDoMotoboy::valor(new Order(['public_id' => 'order_e']), ['km' => 3.21, 'fonte' => 'osrm', 'faixa' => ['de_km' => 2.0, 'ate_km' => 4.0, 'motoboy' => 8.0, 'loja' => 11.37], 'valor_motoboy' => 8.0]);
confere($valor === ['pedido' => 'order_e', 'km' => 3.21, 'aproximado' => false, 'faixa' => ['de_km' => 2.0, 'ate_km' => 4.0, 'acima' => false], 'valor' => 8.0], 'valor de um pedido no formato do app, sem o valor da loja');

$motoca = new Driver(['uuid' => 'uuid-driver_motoca', 'user_uuid' => 'usuario-motoca', 'public_id' => 'driver_motoca']);
confere(GanhosDoMotoboy::podeVer(new Order(['driver_assigned_uuid' => 'uuid-driver_motoca']), $motoca), 'vê o pedido dele');
confere(!GanhosDoMotoboy::podeVer(new Order(['driver_assigned_uuid' => 'uuid-driver_outro']), $motoca), 'não vê o pedido de outro motoboy');
confere(GanhosDoMotoboy::podeVer(new Order(['adhoc' => true, 'status' => 'dispatched']), $motoca), 'vê o pedido aberto (avulso, sem motoboy)');
confere(GanhosDoMotoboy::podeVer(new Order(['adhoc' => true, 'status' => 'dispatched', 'driver_assigned_uuid' => '']), $motoca), 'motoboy gravado vazio conta como sem motoboy');
confere(!GanhosDoMotoboy::podeVer(new Order(['adhoc' => true, 'status' => 'canceled']), $motoca), 'não vê pedido aberto já encerrado');
confere(!GanhosDoMotoboy::podeVer(new Order(['adhoc' => false, 'status' => 'created']), $motoca), 'não vê pedido sem motoboy que não é aberto');
confere(GanhosDoMotoboy::diasDoPeriodo('2026-07-01', '2026-10-01') === 92 && GanhosDoMotoboy::diasDoPeriodo('2026-07-01', '2026-10-02') === 93, 'dias do período: de 1º/7 a 1º/10 são 92');

echo '== Quem é o motoboy (MotoboyDaSessao)' . PHP_EOL;
Driver::$todos     = [$motoca, new Driver(['uuid' => 'uuid-driver_outra-empresa', 'user_uuid' => 'usuario-motoca', 'company_uuid' => 'outra'])];
$GLOBALS['sessao'] = ['company' => 'empresa', 'user' => 'usuario-motoca'];
confere(MotoboyDaSessao::motoboy(new Request([], '12|abcdef'))?->uuid === 'uuid-driver_motoca', 'token de usuário: o Driver dele na empresa da sessão');
confere(MotoboyDaSessao::motoboy(new Request([], 'flb_live_abc123')) === null, 'chave de API: ninguém, mesmo que o dono da chave tenha cadastro de motoboy');
confere(MotoboyDaSessao::motoboy(new Request([], null)) === null, 'sem token: ninguém');
$GLOBALS['sessao'] = ['company' => 'empresa', 'user' => 'usuario-admin'];
confere(MotoboyDaSessao::motoboy(new Request([], '13|xyz')) === null, 'usuário sem cadastro de motoboy: ninguém');
$GLOBALS['sessao'] = ['company' => 'terceira', 'user' => 'usuario-motoca'];
confere(MotoboyDaSessao::motoboy(new Request([], '12|abcdef')) === null, 'cadastro de motoboy só em outra empresa: ninguém');
$GLOBALS['sessao'] = ['company' => 'empresa', 'user' => 'usuario-motoca'];
```

- [ ] **Passo 2: rodar e ver falhar**

```bash
cd /c/tmp/gm && PHP_WASM_DIR=$SCRATCH/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ganhos-motoboy.php; echo "saída=$?"
```
Esperado: erro de PHP `Class "App\Support\Entregas\GanhosDoMotoboy" not found`; `saída=1`.

- [ ] **Passo 3: criar `api/app/Support/Entregas/GanhosDoMotoboy.php`**

```php
<?php

namespace App\Support\Entregas;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;

/**
 * Entregas RestaurantePro: o que o app do motoboy (Navigator) recebe dos valores das entregas (MotoboyController).
 * Só o valor pago a ele: nunca o valor cobrado da loja, a margem ou dados de outro motoboy.
 */
class GanhosDoMotoboy
{
    /** Maior período da tela Meus ganhos, em dias entre o início e o fim: 3 meses, como o extrato da loja. */
    public const MAX_DIAS = 92;

    /** Rotas novas calculadas por consulta dos ganhos (o app não repete a chamada como a tela da central). */
    public const LIMITE_CALCULOS = 10;

    /** Pedido que o motoboy pode consultar: o dele, ou um aberto (avulso, sem motoboy e não encerrado). */
    public static function podeVer(Order $pedido, Driver $motoboy): bool
    {
        $atribuido = (string) $pedido->driver_assigned_uuid;
        if ($atribuido !== '') {
            return $atribuido === $motoboy->uuid;
        }

        return (bool) $pedido->adhoc && !in_array($pedido->status, StatusDoPedido::ENCERRADOS, true);
    }

    /** Uma entrega do CalculoEntregas::entregas() como o app recebe. */
    public static function linha(array $entrega): array
    {
        return [
            'pedido'       => $entrega['pedido'],
            'concluido_em' => $entrega['concluido_em'],
            'loja'         => $entrega['loja_nome'],
            'destino'      => $entrega['destino'],
            'km'           => $entrega['km'],
            'aproximado'   => ($entrega['fonte'] ?? null) === 'estimativa',
            'faixa'        => static::limitesDaFaixa($entrega['faixa'] ?? null),
            'valor'        => $entrega['valor_motoboy'],
        ];
    }

    /** Resposta da rota de ganhos: as entregas do período, quantas ainda estão sem km e os totais. */
    public static function resumo(array $entregas, string $inicio, string $fim, int $pendentes): array
    {
        $linhas = array_map([static::class, 'linha'], $entregas);

        return [
            'inicio'    => $inicio,
            'fim'       => $fim,
            'pendentes' => $pendentes,
            'totais'    => [
                'entregas' => count($linhas),
                'km'       => round(array_sum(array_map(fn ($linha) => $linha['km'] ?? 0, $linhas)), 2),
                'valor'    => round(array_sum(array_map(fn ($linha) => $linha['valor'] ?? 0, $linhas)), 2),
            ],
            'entregas'  => $linhas,
        ];
    }

    /** Resposta da rota do valor de um pedido, a partir do CalculoEntregas::valorDoPedido(). */
    public static function valor(Order $pedido, array $valor): array
    {
        return [
            'pedido'     => $pedido->public_id,
            'km'         => $valor['km'],
            'aproximado' => ($valor['fonte'] ?? null) === 'estimativa',
            'faixa'      => static::limitesDaFaixa($valor['faixa'] ?? null),
            'valor'      => $valor['valor_motoboy'],
        ];
    }

    /** Dias do início ao fim (fim − início), com as datas AAAA-MM-DD já validadas. */
    public static function diasDoPeriodo(string $inicio, string $fim): int
    {
        $utc = new \DateTimeZone('UTC');
        $de  = \DateTimeImmutable::createFromFormat('!Y-m-d', $inicio, $utc);
        $ate = \DateTimeImmutable::createFromFormat('!Y-m-d', $fim, $utc);

        return (int) $de->diff($ate)->format('%r%a');
    }

    /** Só os limites: a faixa do relatório também traz o valor cobrado da loja. */
    protected static function limitesDaFaixa(?array $faixa): ?array
    {
        return $faixa ? ['de_km' => $faixa['de_km'], 'ate_km' => $faixa['ate_km'], 'acima' => !empty($faixa['acima'])] : null;
    }
}
```

- [ ] **Passo 4: criar `api/app/Support/Entregas/MotoboyDaSessao.php`**

```php
<?php

namespace App\Support\Entregas;

use Fleetbase\FleetOps\Models\Driver;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: o motoboy da requisição nas rotas do app do motoboy (MotoboyController, API v1).
 *
 * Vem do token, nunca de parâmetro: o Driver da empresa da sessão cujo usuário é o da sessão (o
 * AuthenticateOnceWithBasicAuth do core grava `company` e `user` na sessão ao autenticar). Só vale token de usuário
 * (Sanctum, `id|token`): uma chave de API (a `flb_live_` do APK, a da integração iFood) autentica como o admin que a
 * criou, e esse admin pode ter cadastro de motoboy.
 */
class MotoboyDaSessao
{
    public static function motoboy(Request $request): ?Driver
    {
        // chave de API não tem "|"; o token que o login do motoboy devolve é "id|token" (plainTextToken do Sanctum)
        if (!str_contains((string) $request->bearerToken(), '|')) {
            return null;
        }

        $usuario = session('user');
        $empresa = session('company');
        if (!$usuario || !$empresa) {
            return null;
        }

        return Driver::where('company_uuid', $empresa)->where('user_uuid', $usuario)->first();
    }
}
```

- [ ] **Passo 5: rodar e ver passar; conferir a sintaxe**

```bash
cd /c/tmp/gm && PHP_WASM_DIR=$SCRATCH/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ganhos-motoboy.php; echo "saída=$?"
PHP_WASM_DIR=$SCRATCH/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/GanhosDoMotoboy.php api/app/Support/Entregas/MotoboyDaSessao.php
```
Esperado: tudo `PASSA`, `FALHAS: 0`, `saída=0`; os dois arquivos `OK`.

- [ ] **Passo 6: commit**

```bash
cd /c/tmp/gm && git rev-parse --show-toplevel && git add api/app/Support/Entregas/GanhosDoMotoboy.php api/app/Support/Entregas/MotoboyDaSessao.php scripts/teste-php/ganhos-motoboy.php && git commit -q -m "API: formato dos ganhos para o app (sem valor da loja) e motoboy pelo token

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>" && git log --oneline -1
```

---

### Tarefa 5: rotas do motoboy (`MotoboyController`)

**Files:**
- Create: `api/app/Http/Controllers/Entregas/MotoboyController.php`
- Modify: `api/app/Providers/RouteServiceProvider.php`
- Test: `scripts/teste-php/ganhos-motoboy.php`

- [ ] **Passo 1: acrescentar as seções ao teste** — colar **imediatamente antes** de `resumo();`:

```php
echo '== Rota de ganhos (MotoboyController::ganhos)' . PHP_EOL;

// o pedidosConcluidos real consulta o banco: este registra o filtro que a rota passa e aplica o filtro do motoboy na lista
class CalculoDeTeste extends CalculoEntregas
{
    public array $wheres  = [];
    public array $periodo = [];

    public function pedidosConcluidos(string $companyUuid, Carbon $inicio, Carbon $fim, ?\Closure $filtro = null): Collection
    {
        $consulta = new ConsultaRegistrada();
        if ($filtro) {
            $filtro($consulta);
        }
        $this->wheres  = $consulta->wheres;
        $this->periodo = [$inicio->format('Y-m-d H:i:s'), $fim->format('Y-m-d H:i:s')];
        $motoboy       = $consulta->wheres[0][1] ?? null;

        return new Collection(array_values(array_filter(Order::$todos, fn ($pedido) => $pedido->driver_assigned_uuid === $motoboy)));
    }
}

reiniciar();
Order::$todos = [comKm(pedido('order_meu'), 3210.4), comKm(pedido('order_de-outro', ['driver_assigned_uuid' => 'uuid-driver_outro']), 1500.0)];
$controller   = new MotoboyController();
$calculoTeste = new CalculoDeTeste();
$resposta     = $controller->ganhos(new Request(['inicio' => '2026-10-01', 'fim' => '2026-10-03'], '12|abc'), $calculoTeste);
confere($resposta->status === 200 && array_column($resposta->dados['entregas'], 'pedido') === ['order_meu'], 'só as corridas do motoboy do token');
confere($calculoTeste->wheres === [['orders.driver_assigned_uuid', 'uuid-driver_motoca']], 'o filtro vai ao banco pelo motoboy');
confere($calculoTeste->periodo === ['2026-10-01 03:00:00', '2026-10-04 02:59:59'], 'do dia 1 ao dia 3 no fuso da organização (em UTC: ' . implode(' a ', $calculoTeste->periodo) . ')');
confere($resposta->dados['totais'] === ['entregas' => 1, 'km' => 3.21, 'valor' => 8.0], 'total a receber do período');
confere(!str_contains(json_encode($resposta->dados), '11.37') && !str_contains(json_encode($resposta->dados), 'valor_loja'), 'nada do valor cobrado da loja');
confere($controller->ganhos(new Request(['inicio' => '2026-07-01', 'fim' => '2026-10-02'], '12|abc'), $calculoTeste)->status === 422, 'mais de 3 meses: 422');
confere($controller->ganhos(new Request(['inicio' => '2026-10-01', 'fim' => '2026-10-03'], 'flb_live_abc'), $calculoTeste)->status === 403, 'chave de API: 403');

echo '== Rota do valor (MotoboyController::valor)' . PHP_EOL;
reiniciar();
Order::$todos = [
    pedido('order_meu', ['status' => 'started']),
    pedido('order_de-outro', ['driver_assigned_uuid' => 'uuid-driver_outro']),
    pedido('order_aberto', ['status' => 'dispatched', 'adhoc' => true, 'driver_assigned_uuid' => null, 'driverAssigned' => null]),
    pedido('order_outra-empresa', ['company_uuid' => 'outra']),
];
$resposta = $controller->valor(new Request([], '12|abc'), 'order_meu', new CalculoEntregas());
confere($resposta->status === 200 && $resposta->dados === ['pedido' => 'order_meu', 'km' => 3.21, 'aproximado' => false, 'faixa' => ['de_km' => 2.0, 'ate_km' => 4.0, 'acima' => false], 'valor' => 8.0], 'pedido dele: km, faixa e valor dele');
$resposta = $controller->valor(new Request([], '12|abc'), 'uuid-order_aberto', new CalculoEntregas());
confere($resposta->status === 200 && $resposta->dados['pedido'] === 'order_aberto', 'pedido aberto, achado também pelo uuid');
confere($controller->valor(new Request([], '12|abc'), 'order_de-outro', new CalculoEntregas())->status === 404, 'pedido de outro motoboy: 404');
confere($controller->valor(new Request([], '12|abc'), 'order_outra-empresa', new CalculoEntregas())->status === 404, 'pedido de outra empresa: 404');
confere($controller->valor(new Request([], '12|abc'), 'uuid-order_outra-empresa', new CalculoEntregas())->status === 404, 'pedido de outra empresa, nem pelo uuid: 404 (a empresa não escapa pelo "ou")');
confere($controller->valor(new Request([], '12|abc'), 'order_nao-existe', new CalculoEntregas())->status === 404, 'pedido que não existe: 404');
confere($controller->valor(new Request([], 'flb_live_abc'), 'order_meu', new CalculoEntregas())->status === 403, 'chave de API: 403');
```

- [ ] **Passo 2: rodar e ver falhar**

```bash
cd /c/tmp/gm && PHP_WASM_DIR=$SCRATCH/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ganhos-motoboy.php; echo "saída=$?"
```
Esperado: erro de PHP `Class "App\Http\Controllers\Entregas\MotoboyController" not found`; `saída=1`.

- [ ] **Passo 3: criar `api/app/Http/Controllers/Entregas/MotoboyController.php`**

```php
<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\CalculoEntregas;
use App\Support\Entregas\GanhosDoMotoboy;
use App\Support\Entregas\MotoboyDaSessao;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Entregas RestaurantePro: ganhos do motoboy no app (Navigator), na API v1, com o token dele.
 * - ganhos: as entregas concluídas dele no período, com o valor de cada uma e o total (tela Início do app);
 * - valor: km, faixa e valor de um pedido dele ou aberto (card de aceitar e detalhes do pedido).
 * O motoboy vem do token (MotoboyDaSessao); as respostas só trazem o valor pago a ele (GanhosDoMotoboy). Usuário de
 * loja nem chega aqui: o ProtegerPortalLoja nega a API v1 a ele.
 */
class MotoboyController extends Controller
{
    public function ganhos(Request $request, CalculoEntregas $calculo)
    {
        $motoboy = MotoboyDaSessao::motoboy($request);
        if (!$motoboy) {
            return $this->soParaMotoboy();
        }

        $request->validate([
            'inicio' => ['required', 'date_format:Y-m-d'],
            'fim'    => ['required', 'date_format:Y-m-d', 'after_or_equal:inicio'],
        ]);

        // até 3 meses por consulta, como o extrato da loja: carrega de uma vez os pedidos do período e calcula rotas
        if (GanhosDoMotoboy::diasDoPeriodo($request->input('inicio'), $request->input('fim')) > GanhosDoMotoboy::MAX_DIAS) {
            return response()->json(['errors' => ['Escolha um período de até 3 meses.']], 422);
        }

        $companyUuid = session('company');
        $fuso        = Company::where('uuid', $companyUuid)->value('timezone') ?: 'America/Sao_Paulo';
        $inicio      = Carbon::createFromFormat('Y-m-d', $request->input('inicio'), $fuso)->startOfDay()->utc();
        $fim         = Carbon::createFromFormat('Y-m-d', $request->input('fim'), $fuso)->endOfDay()->utc();

        $pedidos = $calculo->pedidosConcluidos($companyUuid, $inicio, $fim, function ($query) use ($motoboy) {
            $query->where('orders.driver_assigned_uuid', $motoboy->uuid);
        });
        [$entregas, $pendentes] = $calculo->entregas($pedidos, $fuso, GanhosDoMotoboy::LIMITE_CALCULOS);

        return response()->json(GanhosDoMotoboy::resumo($entregas, $request->input('inicio'), $request->input('fim'), $pendentes));
    }

    public function valor(Request $request, string $id, CalculoEntregas $calculo)
    {
        $motoboy = MotoboyDaSessao::motoboy($request);
        if (!$motoboy) {
            return $this->soParaMotoboy();
        }

        // nenhum model registra o CompanyScope nesta versão: a empresa é filtrada aqui
        $pedido = Order::where('company_uuid', session('company'))
            ->where(fn ($query) => $query->where('public_id', $id)->orWhere('uuid', $id))
            ->with(['payload.pickup', 'payload.dropoff', 'payload.waypoints'])
            ->first();

        if (!$pedido || !GanhosDoMotoboy::podeVer($pedido, $motoboy)) {
            return response()->json(['errors' => ['Pedido não encontrado.']], 404);
        }

        return response()->json(GanhosDoMotoboy::valor($pedido, $calculo->valorDoPedido($pedido)));
    }

    protected function soParaMotoboy()
    {
        return response()->json(['errors' => ['Disponível só para motoboys.']], 403);
    }
}
```

- [ ] **Passo 4: rodar e ver passar**

```bash
cd /c/tmp/gm && PHP_WASM_DIR=$SCRATCH/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ganhos-motoboy.php; echo "saída=$?"
```
Esperado: tudo `PASSA`, `FALHAS: 0`, `saída=0`.

- [ ] **Passo 5: rotas e limite por usuário** — em `api/app/Providers/RouteServiceProvider.php`:

Trocar os imports:
```php
use App\Http\Controllers\Entregas\LojasController;
use App\Http\Controllers\Entregas\PagamentoMotoboysController;
use App\Http\Controllers\Entregas\PortalLojaController;
use App\Http\Middleware\BarrarAceiteDePedidoEncerrado;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
```
por:
```php
use App\Http\Controllers\Entregas\LojasController;
use App\Http\Controllers\Entregas\MotoboyController;
use App\Http\Controllers\Entregas\PagamentoMotoboysController;
use App\Http\Controllers\Entregas\PortalLojaController;
use App\Http\Middleware\BarrarAceiteDePedidoEncerrado;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
```

Trocar:
```php
        $this->app['router']->pushMiddlewareToGroup('fleetbase.api', BarrarAceiteDePedidoEncerrado::class);

        $this->routes(
```
por:
```php
        $this->app['router']->pushMiddlewareToGroup('fleetbase.api', BarrarAceiteDePedidoEncerrado::class);

        // Entregas RestaurantePro: rotas do app do motoboy, até 60 chamadas por minuto por usuário. O throttle do grupo
        // fleetbase.api conta por IP (roda antes da autenticação); este roda depois dela, com o usuário na sessão
        RateLimiter::for('entregas-motoboy', fn (Request $request) => Limit::perMinute(60)->by('entregas-motoboy:' . (session('user') ?: $request->ip())));

        $this->routes(
```

Trocar:
```php
                        Route::get('loja/pedidos/{id}/motoboy', [PortalLojaController::class, 'motoboy'])->middleware('throttle:60,1');
                    });
            }
        );
```
por:
```php
                        Route::get('loja/pedidos/{id}/motoboy', [PortalLojaController::class, 'motoboy'])->middleware('throttle:60,1');
                    });

                // Entregas RestaurantePro: ganhos do motoboy no app (tela Início, card de aceitar e detalhes), na API v1 com o
                // token dele (MotoboyController; só token de motoboy, nunca com o valor cobrado da loja)
                Route::prefix('v1/entregas/motoboy')
                    ->middleware(['fleetbase.api', 'throttle:entregas-motoboy'])
                    ->group(function () {
                        Route::get('ganhos', [MotoboyController::class, 'ganhos']);
                        Route::get('pedidos/{id}/valor', [MotoboyController::class, 'valor']);
                    });
            }
        );
```

- [ ] **Passo 6: conferir a sintaxe**

```bash
cd /c/tmp/gm && PHP_WASM_DIR=$SCRATCH/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Http/Controllers/Entregas/MotoboyController.php api/app/Providers/RouteServiceProvider.php
```
Esperado: os dois `OK`.

- [ ] **Passo 7: commit**

```bash
cd /c/tmp/gm && git rev-parse --show-toplevel && git add api/app/Http/Controllers/Entregas/MotoboyController.php api/app/Providers/RouteServiceProvider.php scripts/teste-php/ganhos-motoboy.php && git commit -q -m "API: rotas do motoboy v1/entregas/motoboy (ganhos do período e valor de um pedido)

Só com token de motoboy (chave de API → 403), pedido de outro motoboy → 404, até 3
meses por consulta e 60 chamadas por minuto por usuário.

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>" && git log --oneline -1
```

---

### Tarefa 6: textos do console (Pagamento e cobrança)

**Files:**
- Modify: `packages/fleetops/translations/pt-br.yaml` (linhas 99 e 143)
- Modify: `packages/fleetops/translations/en-us.yaml` (linhas 99 e 143)

- [ ] **Passo 1: `pt-br.yaml`** — trocar a linha:
```yaml
      how-it-works: 'Km = rota de rua da loja (local de coleta) até o cliente, só ida, calculada uma vez por pedido. Cada entrega vale o valor da faixa de km em que cai: um valor pago ao motoboy e outro cobrado da loja. Contam só pedidos concluídos, pela data de conclusão.'
```
por:
```yaml
      how-it-works: 'Km = rota de rua da loja (local de coleta) até o cliente, só ida, calculada uma vez por pedido. Cada entrega vale o valor da faixa de km em que cai: um valor pago ao motoboy e outro cobrado da loja. O valor fica gravado na primeira vez que a entrega é calculada (normalmente quando o pedido aparece para os motoboys). Contam só pedidos concluídos, pela data de conclusão.'
```
e a linha:
```yaml
      bands-help: 'Cada entrega vale o valor da faixa em que o km dela cai (ex.: 2,4 km cai na faixa de 2 a 3 km). Acima da última faixa, vale o valor da última.'
```
por:
```yaml
      bands-help: 'Cada entrega vale o valor da faixa em que o km dela cai (ex.: 2,4 km cai na faixa de 2 a 3 km). Acima da última faixa, vale o valor da última. Mudanças valem para as entregas calculadas a partir de agora; as já calculadas mantêm o valor.'
```

- [ ] **Passo 2: `en-us.yaml`** — trocar a linha:
```yaml
            how-it-works: 'Km = street route from the store (pickup place) to the customer, one way, calculated once per order. Each delivery is worth the value of the km band it falls in: one amount paid to the driver and another charged to the store. Only completed orders count, by completion date.'
```
por:
```yaml
            how-it-works: 'Km = street route from the store (pickup place) to the customer, one way, calculated once per order. Each delivery is worth the value of the km band it falls in: one amount paid to the driver and another charged to the store. The value is saved the first time the delivery is calculated (usually when the order is shown to drivers). Only completed orders count, by completion date.'
```
e a linha:
```yaml
            bands-help: 'Each delivery is worth the value of the band its km falls in (e.g. 2.4 km falls in the 2 to 3 km band). Above the last band, the last band value applies.'
```
por:
```yaml
            bands-help: 'Each delivery is worth the value of the band its km falls in (e.g. 2.4 km falls in the 2 to 3 km band). Above the last band, the last band value applies. Changes apply to deliveries calculated from now on; deliveries already calculated keep their value.'
```
(mantenha a indentação de cada arquivo: 6 espaços no pt-br, 12 no en-us)

- [ ] **Passo 3: validar as traduções**

```bash
cd /c/tmp/gm && node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal > /tmp/i18n.txt 2>&1; echo "saída=$?"; grep -A3 "== fleetops$" /tmp/i18n.txt
```
Esperado: `saída=0`; na seção `fleetops`, `faltando em pt-br: 0` e `sem tradução em en-us/pt-br: 0`.

- [ ] **Passo 4: commit**

```bash
cd /c/tmp/gm && git rev-parse --show-toplevel && git add packages/fleetops/translations/pt-br.yaml packages/fleetops/translations/en-us.yaml && git commit -q -m "Console: Pagamento e cobrança explica o valor congelado (tabela nova vale para as entregas novas)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>" && git log --oneline -1
```

---

### Tarefa 7: documentação (`CLAUDE.md`)

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Passo 1: Pagamento e cobrança** — no `CLAUDE.md`, logo depois do item que começa com `  - **Valores por faixa de km** (`Setting` ...` (termina em `` `PUT .../faixas` substitui a tabela.``), inserir:

```markdown
  - **Valor congelado por entrega** (tabela `entregas_valores_pedido`, migration em `api/database/migrations`, que o `deploy.sh` roda no banco principal e no sandbox; fica fora do `meta` de propósito, porque o `meta` sai na API v1 e no socket): na primeira vez que o pedido tem km e há faixas, o `CalculoEntregas` grava a faixa e os dois valores (`ValoresCongelados`). Relatório, extrato da loja e app do motoboy leem o congelado.
    - **Mudar a tabela de faixas só vale para as entregas calculadas depois.** Normalmente o valor congela quando o pedido aparece no card de aceitar do motoboy.
    - Km diferente (endereço alterado, estimativa trocada pela rota do OSRM) recalcula com a tabela vigente.
    - Valor congelado errado: apague as linhas do período nessa tabela; elas voltam com a tabela atual na próxima consulta.
```

- [ ] **Passo 2: estrutura** — no item `` - `scripts/teste-php/`: testes de comportamento do PHP ...``, acrescentar ao fim da frase: `` O `sintaxe.mjs` confere a sintaxe com o `php -l` do PHP 8.2.``

- [ ] **Passo 3: App do motoboy** — na seção `## App do motoboy (Navigator próprio)`, logo depois do item que começa com `- **Reenvio de pedido aberto:**`, inserir:

```markdown
- **Início = Meus ganhos** (`src/screens/MeusGanhosScreen.tsx`): atalhos (Hoje, 7 dias, Este mês, Mês passado) e De/Até, total a receber e corridas concluídas por dia. Abre sempre no mês atual; tocar numa corrida abre os detalhes.
  - O card de aceitar (`AdhocOrderCard`) e os detalhes (`OrderScreen`) mostram km (loja → cliente), faixa e o valor do motoboy (`ValorDaEntrega` + `use-valor-da-entrega`, cache de 5 min por pedido).
  - API: `api/app/Http/Controllers/Entregas/MotoboyController.php`, `GET v1/entregas/motoboy/ganhos?inicio&fim` (até 3 meses) e `GET v1/entregas/motoboy/pedidos/{id}/valor` (pedido dele ou aberto). Só token de motoboy (`MotoboyDaSessao`: chave de API → 403), nunca com o valor da loja (`GanhosDoMotoboy`), 60 chamadas por minuto por usuário.
  - Funções puras do app em `src/utils/ganhos.ts`, testadas com `node --experimental-strip-types --test scripts/testes/ganhos.teste.ts`.
```

- [ ] **Passo 4: Histórico** — depois do item `10. Portal da loja (2026-10-03, ramo ``portal-da-loja``):` e seus subitens, acrescentar:

```markdown
11. Ganhos do motoboy no app (2026-10-03): Início com filtro de período e total a receber, valor da entrega no card de aceitar e nos detalhes, e valor congelado por entrega (`entregas_valores_pedido`).
```

- [ ] **Passo 5: commit**

```bash
cd /c/tmp/gm && git rev-parse --show-toplevel && git add CLAUDE.md && git commit -q -m "CLAUDE.md: valor congelado, rotas do motoboy e Início Meus ganhos

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>" && git log --oneline -1
```

---

## Fase B — App do motoboy (repo `entregas-navigator`, worktree `C:\tmp\nv`)

### Tarefa 8: worktree do app e funções puras (`src/utils/ganhos.ts`)

**Files:**
- Create: `src/utils/ganhos.ts`
- Create: `scripts/testes/ganhos.teste.ts`

- [ ] **Passo 1: criar a worktree e a branch**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && git fetch origin && git worktree add -b ganhos-do-motoboy /c/tmp/nv origin/main && git -C /c/tmp/nv log --oneline -1
```
Esperado: a worktree criada em `C:/tmp/nv` na branch `ganhos-do-motoboy`, no último commit da `origin/main` (alarme de pedido).

- [ ] **Passo 2: escrever o teste `scripts/testes/ganhos.teste.ts`** (fora de `__tests__` e sem `.test.` no nome, para o jest do projeto não pegá-lo)

```ts
// Testes das funções puras da tela Meus ganhos e do valor da entrega (src/utils/ganhos.ts), com o Node puro:
//   node --experimental-strip-types --test scripts/testes/ganhos.teste.ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { agruparPorDia, ajustarPeriodo, atalhoDoPeriodo, diasEntre, formatarKm, formatarReais, horaDe, numeroKm, periodoDoAtalho, somarDias } from '../../src/utils/ganhos.ts';
import type { EntregaDoMotoboy } from '../../src/utils/ganhos.ts';

// "hoje" às 15h30 no fuso do celular
const dia = (iso: string) => new Date(Number(iso.slice(0, 4)), Number(iso.slice(5, 7)) - 1, Number(iso.slice(8, 10)), 15, 30);

test('atalhos no meio do mês', () => {
    const hoje = dia('2026-10-15');
    assert.deepEqual(periodoDoAtalho('hoje', hoje), { inicio: '2026-10-15', fim: '2026-10-15' });
    assert.deepEqual(periodoDoAtalho('7dias', hoje), { inicio: '2026-10-09', fim: '2026-10-15' });
    assert.deepEqual(periodoDoAtalho('mes', hoje), { inicio: '2026-10-01', fim: '2026-10-15' });
    assert.deepEqual(periodoDoAtalho('mesPassado', hoje), { inicio: '2026-09-01', fim: '2026-09-30' });
});

test('atalhos na virada do ano, em fevereiro e no dia 1º', () => {
    assert.deepEqual(periodoDoAtalho('mesPassado', dia('2026-01-10')), { inicio: '2025-12-01', fim: '2025-12-31' });
    assert.deepEqual(periodoDoAtalho('7dias', dia('2026-01-03')), { inicio: '2025-12-28', fim: '2026-01-03' });
    assert.deepEqual(periodoDoAtalho('mesPassado', dia('2028-03-05')), { inicio: '2028-02-01', fim: '2028-02-29' });
    assert.deepEqual(periodoDoAtalho('mes', dia('2026-03-01')), { inicio: '2026-03-01', fim: '2026-03-01' });
});

test('atalho destacado pelo período escolhido', () => {
    const hoje = dia('2026-10-15');
    assert.equal(atalhoDoPeriodo({ inicio: '2026-09-01', fim: '2026-09-30' }, hoje), 'mesPassado');
    assert.equal(atalhoDoPeriodo({ inicio: '2026-10-02', fim: '2026-10-15' }, hoje), null);
});

test('dias entre datas e soma de dias', () => {
    assert.equal(diasEntre('2026-07-01', '2026-10-01'), 92);
    assert.equal(diasEntre('2026-10-03', '2026-10-03'), 0);
    assert.equal(somarDias('2026-02-27', 2), '2026-03-01');
    assert.equal(somarDias('2026-01-01', -1), '2025-12-31');
});

test('ajuste do período: data futura, ordem dos campos e limite de 3 meses', () => {
    const hoje = dia('2026-10-15');
    const atual = { inicio: '2026-10-01', fim: '2026-10-15' };
    assert.deepEqual(ajustarPeriodo(atual, 'fim', '2026-11-20', hoje), { periodo: { inicio: '2026-10-01', fim: '2026-10-15' }, limitado: false });
    assert.deepEqual(ajustarPeriodo(atual, 'inicio', '2026-10-12', hoje), { periodo: { inicio: '2026-10-12', fim: '2026-10-15' }, limitado: false });
    assert.deepEqual(ajustarPeriodo({ inicio: '2026-10-05', fim: '2026-10-10' }, 'inicio', '2026-10-12', hoje), { periodo: { inicio: '2026-10-12', fim: '2026-10-12' }, limitado: false });
    assert.deepEqual(ajustarPeriodo({ inicio: '2026-10-05', fim: '2026-10-10' }, 'fim', '2026-10-01', hoje), { periodo: { inicio: '2026-10-01', fim: '2026-10-01' }, limitado: false });
    assert.deepEqual(ajustarPeriodo(atual, 'inicio', '2026-01-01', hoje), { periodo: { inicio: '2026-01-01', fim: '2026-04-03' }, limitado: true });
    assert.deepEqual(ajustarPeriodo({ inicio: '2026-01-01', fim: '2026-01-31' }, 'fim', '2026-10-15', hoje), { periodo: { inicio: '2026-07-15', fim: '2026-10-15' }, limitado: true });
});

test('formatação de reais e de km', () => {
    assert.equal(formatarReais(8), 'R$ 8,00');
    assert.equal(formatarReais(1234.5), 'R$ 1.234,50');
    assert.equal(formatarReais(1234567.891), 'R$ 1.234.567,89');
    assert.equal(formatarReais(0), 'R$ 0,00');
    assert.equal(formatarReais(null), '—');
    assert.equal(formatarKm(3.21), '3,2 km');
    assert.equal(formatarKm(0.84), '0,8 km');
    assert.equal(formatarKm(71.4), '71,4 km');
    assert.equal(formatarKm(null), '—');
    assert.equal(numeroKm(3), '3');
    assert.equal(numeroKm(2.5), '2,5');
});

test('agrupamento por dia, do mais recente para o mais antigo, pela data do servidor', () => {
    const corrida = (pedido: string, concluido_em: string, valor: number | null): EntregaDoMotoboy => ({
        pedido,
        concluido_em,
        loja: 'Loja Centro',
        destino: null,
        km: valor === null ? null : 2,
        aproximado: false,
        faixa: null,
        valor,
    });
    const dias = agruparPorDia([
        corrida('a', '2026-10-02T23:50:00-03:00', 8),
        corrida('b', '2026-10-03T10:00:00-03:00', 6),
        corrida('c', '2026-10-03T19:42:00-03:00', 8.5),
        corrida('d', '2026-10-03T12:00:00-03:00', null),
    ]);
    assert.deepEqual(dias.map((secao) => secao.dia), ['2026-10-03', '2026-10-02']);
    assert.deepEqual(dias[0].data.map((item) => item.pedido), ['c', 'd', 'b']);
    assert.equal(dias[0].entregas, 3);
    assert.equal(dias[0].valor, 14.5);
    assert.equal(dias[0].temValor, true);
    assert.equal(agruparPorDia([corrida('x', '2026-10-01T09:00:00-03:00', null)])[0].temValor, false);
    assert.equal(horaDe('2026-10-03T19:42:00-03:00'), '19:42');
});
```

- [ ] **Passo 3: rodar e ver falhar**

```bash
cd /c/tmp/nv && node --experimental-strip-types --test scripts/testes/ganhos.teste.ts 2>&1 | tail -5
```
Esperado: falha por `Cannot find module '.../src/utils/ganhos.ts'` (`# fail 1` ou erro de carregamento).

- [ ] **Passo 4: criar `src/utils/ganhos.ts`**

```ts
// Entregas: funções puras da tela Meus ganhos (Início) e do valor da entrega (card de aceitar e detalhes do pedido).
// Sem imports, para os testes rodarem com o Node puro: node --experimental-strip-types --test scripts/testes/ganhos.teste.ts

export type Atalho = 'hoje' | '7dias' | 'mes' | 'mesPassado';

/** Período da consulta, em datas 'AAAA-MM-DD' (as duas inclusivas). */
export type Periodo = { inicio: string; fim: string };

/** Limites da faixa de km (o app não recebe os valores da tabela, só o valor pago ao motoboy). */
export type Faixa = { de_km: number; ate_km: number; acima: boolean };

/** Uma corrida da rota v1/entregas/motoboy/ganhos. */
export type EntregaDoMotoboy = {
    pedido: string;
    /** ISO 8601 no fuso da organização, ex.: '2026-10-03T19:42:00-03:00'. */
    concluido_em: string;
    loja: string | null;
    destino: string | null;
    km: number | null;
    aproximado: boolean;
    faixa: Faixa | null;
    valor: number | null;
};

/** Resposta da rota v1/entregas/motoboy/ganhos. */
export type RespostaGanhos = {
    inicio: string;
    fim: string;
    pendentes: number;
    totais: { entregas: number; km: number; valor: number };
    entregas: EntregaDoMotoboy[];
};

/** Resposta da rota v1/entregas/motoboy/pedidos/{id}/valor. */
export type ValorDoPedido = { pedido: string; km: number | null; aproximado: boolean; faixa: Faixa | null; valor: number | null };

/** Uma seção da lista: as corridas de um dia (`data` é o campo que a SectionList lê). */
export type DiaDeGanhos = { dia: string; entregas: number; valor: number; temValor: boolean; data: EntregaDoMotoboy[] };

/** Maior período, em dias entre o início e o fim: 3 meses, o mesmo limite da API. */
export const MAX_DIAS = 92;

export const ATALHOS: Atalho[] = ['hoje', '7dias', 'mes', 'mesPassado'];

const doisDigitos = (numero: number) => String(numero).padStart(2, '0');

/** Data local do celular em 'AAAA-MM-DD'. */
export function paraIso(data: Date): string {
    return `${data.getFullYear()}-${doisDigitos(data.getMonth() + 1)}-${doisDigitos(data.getDate())}`;
}

/** 'AAAA-MM-DD' em Date local ao meio-dia (ao meio-dia, somar e subtrair dias nunca cai no dia vizinho). */
export function deIso(iso: string): Date {
    const [ano, mes, dia] = iso.split('-').map(Number);
    return new Date(ano, mes - 1, dia, 12, 0, 0, 0);
}

export function somarDias(iso: string, dias: number): string {
    const data = deIso(iso);
    return paraIso(new Date(data.getFullYear(), data.getMonth(), data.getDate() + dias, 12));
}

/** Dias do início ao fim (fim − início). */
export function diasEntre(inicio: string, fim: string): number {
    return Math.round((deIso(fim).getTime() - deIso(inicio).getTime()) / 86400000);
}

export function periodoDoAtalho(atalho: Atalho, hoje: Date): Periodo {
    const fim = paraIso(hoje);
    const ano = hoje.getFullYear();
    const mes = hoje.getMonth();
    if (atalho === 'hoje') return { inicio: fim, fim };
    if (atalho === '7dias') return { inicio: somarDias(fim, -6), fim };
    if (atalho === 'mesPassado') return { inicio: paraIso(new Date(ano, mes - 1, 1, 12)), fim: paraIso(new Date(ano, mes, 0, 12)) };
    return { inicio: paraIso(new Date(ano, mes, 1, 12)), fim };
}

/** O atalho cujo período é exatamente este (para destacar o botão), ou null. */
export function atalhoDoPeriodo(periodo: Periodo, hoje: Date): Atalho | null {
    return (
        ATALHOS.find((atalho) => {
            const doAtalho = periodoDoAtalho(atalho, hoje);
            return doAtalho.inicio === periodo.inicio && doAtalho.fim === periodo.fim;
        }) ?? null
    );
}

/**
 * Período depois de o motoboy escolher a data de um dos campos: nunca depois de hoje; se o De passar do Até (ou o Até
 * ficar antes do De), o outro campo vai para a mesma data; se passar de MAX_DIAS, o outro campo se aproxima até o limite
 * (`limitado` avisa a tela).
 */
export function ajustarPeriodo(atual: Periodo, campo: 'inicio' | 'fim', data: string, hoje: Date): { periodo: Periodo; limitado: boolean } {
    const limite = paraIso(hoje);
    const escolhida = data > limite ? limite : data;
    let inicio = campo === 'inicio' ? escolhida : atual.inicio;
    let fim = campo === 'fim' ? escolhida : atual.fim;
    if (fim > limite) fim = limite;
    if (inicio > fim) {
        if (campo === 'inicio') fim = inicio;
        else inicio = fim;
    }
    let limitado = false;
    if (diasEntre(inicio, fim) > MAX_DIAS) {
        limitado = true;
        if (campo === 'inicio') fim = somarDias(inicio, MAX_DIAS);
        else inicio = somarDias(fim, -MAX_DIAS);
    }
    return { periodo: { inicio, fim }, limitado };
}

/** R$ 1.234,56 (sem Intl: o Hermes do app pode vir sem os dados de locale). Sem valor: '—'. */
export function formatarReais(valor: number | null | undefined): string {
    if (valor === null || valor === undefined || !Number.isFinite(Number(valor))) return '—';
    const centavos = Math.round(Math.abs(Number(valor)) * 100);
    const reais = String(Math.floor(centavos / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return `${Number(valor) < 0 ? '-' : ''}R$ ${reais},${doisDigitos(centavos % 100)}`;
}

/** 3,2 km (uma casa decimal). Sem km: '—'. */
export function formatarKm(km: number | null | undefined): string {
    if (km === null || km === undefined || !Number.isFinite(Number(km))) return '—';
    return `${(Math.round(Number(km) * 10) / 10).toFixed(1).replace('.', ',')} km`;
}

/** Limite de faixa sem unidade: 3, 2,5. */
export function numeroKm(km: number): string {
    return String(Math.round(Number(km) * 100) / 100).replace('.', ',');
}

/** Dia 'AAAA-MM-DD' do concluido_em como veio do servidor (fuso da organização, sem converter para o do celular). */
export function diaDe(concluidoEm: string): string {
    return concluidoEm.slice(0, 10);
}

/** Hora 'HH:MM' do concluido_em como veio do servidor. */
export function horaDe(concluidoEm: string): string {
    return concluidoEm.slice(11, 16);
}

/** Seções da lista: um dia por seção, do mais recente para o mais antigo; dentro do dia, da última corrida para a primeira. */
export function agruparPorDia(entregas: EntregaDoMotoboy[]): DiaDeGanhos[] {
    const ordenadas = [...entregas].sort((a, b) => (a.concluido_em < b.concluido_em ? 1 : a.concluido_em > b.concluido_em ? -1 : 0));
    const dias: DiaDeGanhos[] = [];
    for (const entrega of ordenadas) {
        const dia = diaDe(entrega.concluido_em);
        let secao = dias[dias.length - 1];
        if (!secao || secao.dia !== dia) {
            secao = { dia, entregas: 0, valor: 0, temValor: false, data: [] };
            dias.push(secao);
        }
        secao.data.push(entrega);
        secao.entregas += 1;
        if (entrega.valor !== null) {
            secao.valor = Math.round((secao.valor + entrega.valor) * 100) / 100;
            secao.temValor = true;
        }
    }
    return dias;
}
```

- [ ] **Passo 5: rodar e ver passar; conferir a sintaxe**

```bash
cd /c/tmp/nv && node --experimental-strip-types --test scripts/testes/ganhos.teste.ts 2>&1 | tail -9
node $SCRATCH/conferir-tsx.cjs src/utils/ganhos.ts scripts/testes/ganhos.teste.ts
```
Esperado: `# pass 7` e `# fail 0`; os dois arquivos `OK`.

- [ ] **Passo 6: commit**

```bash
cd /c/tmp/nv && git rev-parse --show-toplevel && git add src/utils/ganhos.ts scripts/testes/ganhos.teste.ts && git commit -q -m "Ganhos: funções puras (período, atalhos, R$, km, agrupamento por dia) com testes node:test

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>" && git log --oneline -1
```

---

### Tarefa 9: textos do app (pt e en)

**Files:**
- Modify: `translations/pt.json` (fim do arquivo)
- Modify: `translations/en.json` (fim do arquivo)

- [ ] **Passo 1: `translations/pt.json`** — trocar o fim do arquivo:

```json
        "bateria": {
            "titulo": "Economia de bateria",
            "texto": "Libere o Entregas da economia de bateria para receber pedidos com o app fechado.",
            "botao": "Liberar"
        }
    }
}
```
por:
```json
        "bateria": {
            "titulo": "Economia de bateria",
            "texto": "Libere o Entregas da economia de bateria para receber pedidos com o app fechado.",
            "botao": "Liberar"
        }
    },
    "MeusGanhosScreen": {
        "titulo": "Meus ganhos",
        "atalhos": {
            "hoje": "Hoje",
            "7dias": "7 dias",
            "mes": "Este mês",
            "mesPassado": "Mês passado"
        },
        "de": "De",
        "ate": "Até",
        "totalAReceber": "Total a receber",
        "corridas": {
            "one": "{{count}} corrida",
            "other": "{{count}} corridas"
        },
        "semValores": "Os valores ainda não foram cadastrados pela central.",
        "pendentes": {
            "one": "{{count}} corrida ainda sem km. Puxe a lista para atualizar.",
            "other": "{{count}} corridas ainda sem km. Puxe a lista para atualizar."
        },
        "vazio": "Nenhuma corrida concluída neste período.",
        "erro": "Não foi possível carregar os ganhos. Puxe a lista para tentar de novo.",
        "calculando": "calculando…",
        "semLoja": "Loja não identificada",
        "periodoMaximo": "O período máximo é de 3 meses.",
        "erroAoAbrir": "Não foi possível abrir o pedido.",
        "formatoDia": "EEE, dd/MM",
        "formatoData": "dd/MM/yyyy"
    },
    "ValorDaEntrega": {
        "titulo": "Valor da entrega",
        "calculando": "Calculando valor…",
        "resumo": "Entrega de {{km}} · Você recebe {{valor}}",
        "resumoSemValor": "Entrega de {{km}}",
        "distancia": "Distância (loja → cliente)",
        "faixa": "Faixa",
        "faixaDeAte": "de {{de}} a {{ate}} km",
        "faixaAcima": "acima de {{de}} km",
        "voceRecebe": "Você recebe",
        "semValor": "ainda não cadastrado"
    }
}
```

- [ ] **Passo 2: `translations/en.json`** — trocar o fim do arquivo:

```json
        "bateria": {
            "titulo": "Battery saver",
            "texto": "Exempt Entregas from battery saving to get orders with the app closed.",
            "botao": "Allow"
        }
    }
}
```
por:
```json
        "bateria": {
            "titulo": "Battery saver",
            "texto": "Exempt Entregas from battery saving to get orders with the app closed.",
            "botao": "Allow"
        }
    },
    "MeusGanhosScreen": {
        "titulo": "My earnings",
        "atalhos": {
            "hoje": "Today",
            "7dias": "7 days",
            "mes": "This month",
            "mesPassado": "Last month"
        },
        "de": "From",
        "ate": "To",
        "totalAReceber": "Total to receive",
        "corridas": {
            "one": "{{count}} ride",
            "other": "{{count}} rides"
        },
        "semValores": "The office has not set the values yet.",
        "pendentes": {
            "one": "{{count}} ride still without km. Pull the list to refresh.",
            "other": "{{count}} rides still without km. Pull the list to refresh."
        },
        "vazio": "No completed rides in this period.",
        "erro": "Could not load your earnings. Pull the list to try again.",
        "calculando": "calculating…",
        "semLoja": "Unknown store",
        "periodoMaximo": "The maximum period is 3 months.",
        "erroAoAbrir": "Could not open the order.",
        "formatoDia": "EEE, MM/dd",
        "formatoData": "MM/dd/yyyy"
    },
    "ValorDaEntrega": {
        "titulo": "Delivery value",
        "calculando": "Calculating value…",
        "resumo": "{{km}} delivery · You get {{valor}}",
        "resumoSemValor": "{{km}} delivery",
        "distancia": "Distance (store → customer)",
        "faixa": "Band",
        "faixaDeAte": "{{de}} to {{ate}} km",
        "faixaAcima": "over {{de}} km",
        "voceRecebe": "You get",
        "semValor": "not set yet"
    }
}
```

- [ ] **Passo 3: conferir JSON e chaves iguais nos dois idiomas**

```bash
cd /c/tmp/nv && node -e "
const pt = require('./translations/pt.json'), en = require('./translations/en.json');
const chaves = (o, p = '') => Object.entries(o).flatMap(([k, v]) => (v && typeof v === 'object' ? chaves(v, p + k + '.') : [p + k]));
for (const secao of ['MeusGanhosScreen', 'ValorDaEntrega']) {
  const a = chaves(pt[secao]).sort().join(), b = chaves(en[secao]).sort().join();
  console.log(secao, a === b ? 'chaves iguais' : 'DIFERENTES');
}
console.log(Object.keys(pt).length === Object.keys(en).length ? 'seções iguais' : 'SEÇÕES DIFERENTES');"
```
Esperado: `MeusGanhosScreen chaves iguais`, `ValorDaEntrega chaves iguais`, `seções iguais` (e nenhum erro de JSON).

- [ ] **Passo 4: commit**

```bash
cd /c/tmp/nv && git rev-parse --show-toplevel && git add translations/pt.json translations/en.json && git commit -q -m "Textos da tela Meus ganhos e do valor da entrega (pt e en)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>" && git log --oneline -1
```

---

### Tarefa 10: hook e componente do valor da entrega

**Files:**
- Create: `src/hooks/use-valor-da-entrega.ts`
- Create: `src/components/ValorDaEntrega.tsx`

- [ ] **Passo 1: criar `src/hooks/use-valor-da-entrega.ts`**

```ts
import { useEffect, useState } from 'react';
import useFleetbase from './use-fleetbase';
import type { ValorDoPedido } from '../utils/ganhos';

// Entregas: km, faixa e valor de um pedido para o motoboy (rota v1/entregas/motoboy/pedidos/{id}/valor), usados pelo card
// de aceitar e pelos detalhes do pedido: cache em memória por pedido (5 min) e uma chamada por vez para o mesmo pedido.
// O servidor responde 404 para pedido de outro motoboy e 403 sem token de motoboy: o SDK rejeita e a tela esconde o valor.
const VALIDADE_MS = 5 * 60 * 1000;
const guardados: Record<string, { valor: ValorDoPedido; em: number }> = {};
const emAndamento: Record<string, Promise<ValorDoPedido>> = {};

const guardado = (id?: string | null): ValorDoPedido | null => {
    const item = id ? guardados[id] : undefined;
    return item && Date.now() - item.em < VALIDADE_MS ? item.valor : null;
};

function buscarValor(adapter: any, id: string): Promise<ValorDoPedido> {
    const pronto = guardado(id);
    if (pronto) return Promise.resolve(pronto);
    if (!emAndamento[id]) {
        emAndamento[id] = adapter
            .get(`entregas/motoboy/pedidos/${id}/valor`)
            .then((valor: ValorDoPedido) => {
                guardados[id] = { valor, em: Date.now() };
                return valor;
            })
            .finally(() => {
                delete emAndamento[id];
            });
    }
    return emAndamento[id];
}

const useValorDaEntrega = (id?: string | null) => {
    const { adapter } = useFleetbase();
    const [valor, setValor] = useState<ValorDoPedido | null>(() => guardado(id));
    const [carregando, setCarregando] = useState(() => !!id && !guardado(id));
    const [erro, setErro] = useState<Error | null>(null);

    useEffect(() => {
        if (!adapter || !id) {
            setCarregando(false);
            return;
        }

        let ativo = true;
        const pronto = guardado(id);
        setValor(pronto);
        setCarregando(!pronto);
        setErro(null);
        buscarValor(adapter, id)
            .then((novo) => {
                if (ativo) setValor(novo);
            })
            .catch((falha) => {
                if (ativo) setErro(falha);
            })
            .finally(() => {
                if (ativo) setCarregando(false);
            });

        return () => {
            ativo = false;
        };
    }, [adapter, id]);

    return { valor, carregando, erro };
};

export default useValorDaEntrega;
```

- [ ] **Passo 2: criar `src/components/ValorDaEntrega.tsx`**

```tsx
import { Separator, Text, XStack, YStack, useTheme } from 'tamagui';
import { FontAwesomeIcon } from '@fortawesome/react-native-fontawesome';
import { faMoneyBillWave } from '@fortawesome/free-solid-svg-icons';
import { useLanguage } from '../contexts/LanguageContext';
import useValorDaEntrega from '../hooks/use-valor-da-entrega';
import { formatarKm, formatarReais, numeroKm } from '../utils/ganhos';
import { SectionHeader, SectionInfoLine } from './Content';
import SafeSpinner from './SafeSpinner';

// Entregas: km da entrega (loja → cliente, o que é pago) e o valor que o motoboy recebe por ela.
// - compacto: a faixa verde do card de aceitar (AdhocOrderCard);
// - detalhado: a seção "Valor da entrega" dos detalhes do pedido (OrderScreen).
// Com erro (404/403), sem pedido ou sem km (pedido sem coordenadas), não mostra nada: o card e os detalhes ficam como antes.
type Props = { pedido?: string | null; variante: 'compacto' | 'detalhado' };

const ValorDaEntrega = ({ pedido, variante }: Props) => {
    const theme = useTheme();
    const { t } = useLanguage();
    const { valor, carregando } = useValorDaEntrega(pedido);

    if (!valor && !carregando) return null;
    if (valor && valor.km === null) return null;

    const prefixo = valor?.aproximado ? '≈ ' : '';
    const km = valor ? prefixo + formatarKm(valor.km) : '';
    const dinheiro = valor && valor.valor !== null ? prefixo + formatarReais(valor.valor) : null;

    if (variante === 'compacto') {
        return (
            <XStack mx='$3' mb='$3' px='$3' py='$2' gap='$2' alignItems='center' borderRadius='$4' borderWidth={1} bg='$success' borderColor='$successBorder'>
                {valor ? <FontAwesomeIcon icon={faMoneyBillWave} color={theme['$successText'].val} /> : <SafeSpinner color={theme['$successText'].val} />}
                <Text color='$successText' fontSize={16} fontWeight='bold' flex={1}>
                    {!valor ? t('ValorDaEntrega.calculando') : dinheiro ? t('ValorDaEntrega.resumo', { km, valor: dinheiro }) : t('ValorDaEntrega.resumoSemValor', { km })}
                </Text>
            </XStack>
        );
    }

    const faixa = valor?.faixa;
    const textoDaFaixa = !faixa
        ? '—'
        : faixa.acima
          ? t('ValorDaEntrega.faixaAcima', { de: numeroKm(faixa.de_km) })
          : t('ValorDaEntrega.faixaDeAte', { de: numeroKm(faixa.de_km), ate: numeroKm(faixa.ate_km) });

    return (
        <YStack>
            <SectionHeader title={t('ValorDaEntrega.titulo')} />
            <YStack py='$2'>
                {!valor ? (
                    <XStack px='$3' py='$2' gap='$2' alignItems='center'>
                        <SafeSpinner color={theme['$textSecondary'].val} />
                        <Text color='$textSecondary'>{t('ValorDaEntrega.calculando')}</Text>
                    </XStack>
                ) : (
                    <>
                        <SectionInfoLine title={t('ValorDaEntrega.distancia')} value={km} />
                        <Separator />
                        <SectionInfoLine title={t('ValorDaEntrega.faixa')} value={textoDaFaixa} />
                        <Separator />
                        <SectionInfoLine title={t('ValorDaEntrega.voceRecebe')} value={dinheiro ?? t('ValorDaEntrega.semValor')} />
                    </>
                )}
            </YStack>
        </YStack>
    );
};

export default ValorDaEntrega;
```

- [ ] **Passo 3: conferir a sintaxe**

```bash
cd /c/tmp/nv && node $SCRATCH/conferir-tsx.cjs src/hooks/use-valor-da-entrega.ts src/components/ValorDaEntrega.tsx
```
Esperado: os dois `OK`.

- [ ] **Passo 4: conferir os imports** (o parser não confere se os nomes existem)

```bash
cd /c/tmp/nv && grep -n "export const SectionHeader\|export const SectionInfoLine" src/components/Content.tsx && grep -n "export default SafeSpinner" src/components/SafeSpinner.tsx && grep -n "export const useLanguage" src/contexts/LanguageContext.tsx && grep -n "^export default useFleetbase" src/hooks/use-fleetbase.ts && grep -n "export function formatarKm\|export function formatarReais\|export function numeroKm\|export type ValorDoPedido" src/utils/ganhos.ts
```
Esperado: uma linha para cada nome importado.

- [ ] **Passo 5: commit**

```bash
cd /c/tmp/nv && git rev-parse --show-toplevel && git add src/hooks/use-valor-da-entrega.ts src/components/ValorDaEntrega.tsx && git commit -q -m "Valor da entrega: hook com cache por pedido e componente (faixa do card e seção dos detalhes)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>" && git log --oneline -1
```

---

### Tarefa 11: valor no card de aceitar e nos detalhes

**Files:**
- Modify: `src/components/AdhocOrderCard.tsx`
- Modify: `src/screens/OrderScreen.tsx`

- [ ] **Passo 1: card** — em `src/components/AdhocOrderCard.tsx`, trocar:
```tsx
import Badge from './Badge';
```
por:
```tsx
import Badge from './Badge';
import ValorDaEntrega from './ValorDaEntrega';
```
e trocar:
```tsx
                        <Text color='$infoText' fontSize='$6' fontWeight='bold'>
                            {t('AdhocOrderCard.availableNearby', { distance: formatMeters(distance) })}
                        </Text>
                    </XStack>
```
por:
```tsx
                        <Text color='$infoText' fontSize='$6' fontWeight='bold'>
                            {t('AdhocOrderCard.availableNearby', { distance: formatMeters(distance) })}
                        </Text>
                    </XStack>
                    {/* Entregas: km da entrega (loja → cliente) e o valor que o motoboy recebe */}
                    <ValorDaEntrega pedido={order.id} variante='compacto' />
```

- [ ] **Passo 2: detalhes** — em `src/screens/OrderScreen.tsx`, trocar:
```tsx
import SafeSpinner from '../components/SafeSpinner';
```
por:
```tsx
import SafeSpinner from '../components/SafeSpinner';
import ValorDaEntrega from '../components/ValorDaEntrega';
```
e trocar:
```tsx
                </ActionContainer>
                <SectionHeader title={t('OrderScreen.orderInformation')} />
```
por:
```tsx
                </ActionContainer>
                {/* Entregas: km da entrega, faixa e o valor que o motoboy recebe */}
                <ValorDaEntrega pedido={order.id} variante='detalhado' />
                <SectionHeader title={t('OrderScreen.orderInformation')} />
```

- [ ] **Passo 3: conferir a sintaxe**

```bash
cd /c/tmp/nv && node $SCRATCH/conferir-tsx.cjs src/components/AdhocOrderCard.tsx src/screens/OrderScreen.tsx && git diff --stat
```
Esperado: os dois `OK`; o diff com 2 arquivos e só linhas acrescentadas.

- [ ] **Passo 4: commit**

```bash
cd /c/tmp/nv && git rev-parse --show-toplevel && git add src/components/AdhocOrderCard.tsx src/screens/OrderScreen.tsx && git commit -q -m "Card de aceitar e detalhes do pedido mostram o km da entrega e o valor do motoboy

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>" && git log --oneline -1
```

---

### Tarefa 12: tela Meus ganhos no Início

**Files:**
- Create: `src/screens/MeusGanhosScreen.tsx`
- Modify: `src/navigation/DriverNavigator.tsx`
- Delete: `src/screens/DriverDashboardScreen.tsx`

- [ ] **Passo 1: criar `src/screens/MeusGanhosScreen.tsx`**

```tsx
import { useCallback, useMemo, useRef, useState } from 'react';
import { Platform, Pressable, RefreshControl, SectionList } from 'react-native';
import { useFocusEffect, useNavigation } from '@react-navigation/native';
import DateTimePicker, { DateTimePickerAndroid } from '@react-native-community/datetimepicker';
import { Separator, Text, XStack, YStack, useTheme } from 'tamagui';
import { FontAwesomeIcon } from '@fortawesome/react-native-fontawesome';
import { faCalendarDays, faChevronRight, faInfoCircle, faTriangleExclamation } from '@fortawesome/free-solid-svg-icons';
import { format } from 'date-fns';
import { useLanguage } from '../contexts/LanguageContext';
import useFleetbase from '../hooks/use-fleetbase';
import useAppTheme from '../hooks/use-app-theme';
import useRessincronizar from '../hooks/use-ressincronizar';
import { dateFnsLocaleOptions, translate } from '../utils/localize';
import { toast } from '../utils/toast';
import SafeSpinner from '../components/SafeSpinner';
import Spacer from '../components/Spacer';
import { ATALHOS, agruparPorDia, ajustarPeriodo, atalhoDoPeriodo, deIso, formatarKm, formatarReais, horaDe, paraIso, periodoDoAtalho } from '../utils/ganhos';
import type { Atalho, DiaDeGanhos, EntregaDoMotoboy, Periodo, RespostaGanhos } from '../utils/ganhos';

// Entregas: Início do app = Meus ganhos. Corridas concluídas do motoboy no período (rota v1/entregas/motoboy/ganhos), com o
// valor de cada uma e o total a receber. Abre sempre no mês atual; um atalho escolhido (Hoje, 7 dias, Este mês, Mês passado)
// acompanha a data do dia quando a tela volta a aparecer. Recarrega ao voltar para a aba e quando o app volta para a frente.

type Escolha = { atalho: Atalho | null; periodo: Periodo };
type ModoDeCarga = 'tela' | 'puxar' | 'silencioso';
type CampoDeData = 'inicio' | 'fim';
type Tom = 'info' | 'warning' | 'error';

const CAMPOS: CampoDeData[] = ['inicio', 'fim'];

const CORES_DO_AVISO: Record<Tom, [string, string, string]> = {
    info: ['$info', '$infoBorder', '$infoText'],
    warning: ['$warning', '$warningBorder', '$warningText'],
    error: ['$error', '$errorBorder', '$errorText'],
};

const Aviso = ({ tom, texto }: { tom: Tom; texto: string }) => {
    const theme = useTheme();
    const [fundo, borda, cor] = CORES_DO_AVISO[tom];

    return (
        <XStack alignItems='center' gap='$2' px='$3' py='$2' borderRadius='$4' borderWidth={1} bg={fundo} borderColor={borda}>
            <FontAwesomeIcon icon={tom === 'error' ? faTriangleExclamation : faInfoCircle} color={theme[cor].val} size={14} />
            <Text color={cor} fontSize={14} flex={1}>
                {texto}
            </Text>
        </XStack>
    );
};

const MeusGanhosScreen = () => {
    const theme = useTheme();
    const navigation = useNavigation();
    const { isDarkMode } = useAppTheme();
    const { t } = useLanguage();
    const { fleetbase, adapter } = useFleetbase();
    const [escolha, setEscolha] = useState<Escolha>(() => ({ atalho: 'mes', periodo: periodoDoAtalho('mes', new Date()) }));
    const [dados, setDados] = useState<RespostaGanhos | null>(null);
    const [carregando, setCarregando] = useState(true);
    const [atualizando, setAtualizando] = useState(false);
    const [erro, setErro] = useState(false);
    const [campoNoIos, setCampoNoIos] = useState<CampoDeData | null>(null);
    const [abrindo, setAbrindo] = useState<string | null>(null);

    // refs: as funções de carga não mudam a cada render (o useFleetbase troca de instância logo depois de montar)
    const adapterRef = useRef(adapter);
    adapterRef.current = adapter;
    const fleetbaseRef = useRef(fleetbase);
    fleetbaseRef.current = fleetbase;
    const escolhaRef = useRef(escolha);
    escolhaRef.current = escolha;
    const consultaRef = useRef(0);
    const carregouRef = useRef(false);

    const carregar = useCallback(async (periodo: Periodo, modo: ModoDeCarga) => {
        const api = adapterRef.current;
        if (!api) return;
        // descarta a resposta de uma consulta antiga (o motoboy trocou de período antes de ela voltar)
        const consulta = ++consultaRef.current;
        if (modo === 'tela') {
            setCarregando(true);
            setDados(null);
            setErro(false);
        }
        if (modo === 'puxar') setAtualizando(true);
        try {
            const resposta = await api.get('entregas/motoboy/ganhos', { inicio: periodo.inicio, fim: periodo.fim });
            if (consulta !== consultaRef.current) return;
            carregouRef.current = true;
            setDados(resposta);
            setErro(false);
        } catch (falha) {
            if (consulta !== consultaRef.current) return;
            console.warn('[ganhos] erro ao carregar:', falha);
            setErro(true);
        } finally {
            if (consulta === consultaRef.current) {
                setCarregando(false);
                setAtualizando(false);
            }
        }
    }, []);

    // período em vigor: com um atalho escolhido, recalcula pela data de hoje (o app pode ficar aberto de um dia para o outro);
    // `mudou` avisa que o período virou e a lista na tela é de outro período
    const periodoEmVigor = useCallback((): { periodo: Periodo; mudou: boolean } => {
        const { atalho, periodo } = escolhaRef.current;
        if (!atalho) return { periodo, mudou: false };
        const atual = periodoDoAtalho(atalho, new Date());
        const mudou = atual.inicio !== periodo.inicio || atual.fim !== periodo.fim;
        if (mudou) {
            setEscolha({ atalho, periodo: atual });
        }
        return { periodo: atual, mudou };
    }, []);

    // recarrega sem esconder a lista; se o período virou ou nada carregou ainda, mostra o carregando no lugar da lista velha
    const recarregar = useCallback(
        (modo: ModoDeCarga) => {
            const { periodo, mudou } = periodoEmVigor();
            carregar(periodo, mudou || !carregouRef.current ? 'tela' : modo);
        },
        [carregar, periodoEmVigor]
    );

    useFocusEffect(
        useCallback(() => {
            recarregar('silencioso');
        }, [recarregar])
    );

    useRessincronizar(() => {
        recarregar('silencioso');
    });

    const aplicar = (nova: Escolha) => {
        setEscolha(nova);
        carregar(nova.periodo, 'tela');
    };

    const escolherAtalho = (atalho: Atalho) => {
        aplicar({ atalho, periodo: periodoDoAtalho(atalho, new Date()) });
    };

    const escolherData = (campo: CampoDeData, data: Date) => {
        const hoje = new Date();
        const { periodo, limitado } = ajustarPeriodo(escolhaRef.current.periodo, campo, paraIso(data), hoje);
        if (limitado) {
            toast.info(translate('MeusGanhosScreen.periodoMaximo'));
        }
        aplicar({ atalho: atalhoDoPeriodo(periodo, hoje), periodo });
    };

    const abrirCalendario = (campo: CampoDeData) => {
        if (Platform.OS !== 'android') {
            setCampoNoIos(campo);
            return;
        }
        DateTimePickerAndroid.open({
            value: deIso(escolhaRef.current.periodo[campo]),
            mode: 'date',
            maximumDate: new Date(),
            onChange: (evento, data) => {
                if (evento.type === 'set' && data) {
                    escolherData(campo, data);
                }
            },
        });
    };

    const abrirPedido = async (id: string) => {
        if (abrindo || !fleetbaseRef.current) return;
        setAbrindo(id);
        try {
            const pedido = await fleetbaseRef.current.orders.findRecord(id);
            navigation.navigate('Order', { order: pedido.serialize() });
        } catch (falha) {
            console.warn('[ganhos] erro ao abrir o pedido:', falha);
            toast.error(translate('MeusGanhosScreen.erroAoAbrir'));
        } finally {
            setAbrindo(null);
        }
    };

    const entregas = dados?.entregas ?? [];
    const secoes = useMemo(() => agruparPorDia(dados?.entregas ?? []), [dados]);
    const totais = dados?.totais ?? { entregas: 0, km: 0, valor: 0 };
    const semValores = entregas.length > 0 && !entregas.some((entrega) => entrega.valor !== null);
    const pendentes = dados?.pendentes ?? 0;
    const corDoIcone = theme['$textSecondary'].val;
    const corridas = (quantidade: number) => t(quantidade === 1 ? 'MeusGanhosScreen.corridas.one' : 'MeusGanhosScreen.corridas.other', { count: quantidade });

    const cabecalho = (
        <YStack px='$3' pt='$4' pb='$3' gap='$3'>
            <Text color='$textPrimary' fontSize={22} fontWeight='bold'>
                {t('MeusGanhosScreen.titulo')}
            </Text>
            <XStack flexWrap='wrap' gap='$2'>
                {ATALHOS.map((atalho) => {
                    const ativo = escolha.atalho === atalho;
                    return (
                        <Pressable key={atalho} onPress={() => escolherAtalho(atalho)}>
                            <XStack px='$3' py='$2' borderRadius='$10' borderWidth={1} bg={ativo ? '$info' : '$surface'} borderColor={ativo ? '$infoBorder' : '$borderColorWithShadow'}>
                                <Text color={ativo ? '$infoText' : '$textPrimary'} fontSize={14}>
                                    {t(`MeusGanhosScreen.atalhos.${atalho}`)}
                                </Text>
                            </XStack>
                        </Pressable>
                    );
                })}
            </XStack>
            <XStack gap='$2'>
                {CAMPOS.map((campo) => (
                    <Pressable key={campo} style={{ flex: 1 }} onPress={() => abrirCalendario(campo)}>
                        <XStack alignItems='center' gap='$2' px='$3' py='$2' borderRadius='$4' borderWidth={1} bg='$surface' borderColor='$borderColorWithShadow'>
                            <FontAwesomeIcon icon={faCalendarDays} color={corDoIcone} size={14} />
                            <YStack>
                                <Text color='$textSecondary' fontSize={12}>
                                    {t(campo === 'inicio' ? 'MeusGanhosScreen.de' : 'MeusGanhosScreen.ate')}
                                </Text>
                                <Text color='$textPrimary' fontSize={15}>
                                    {format(deIso(escolha.periodo[campo]), t('MeusGanhosScreen.formatoData'))}
                                </Text>
                            </YStack>
                        </XStack>
                    </Pressable>
                ))}
            </XStack>
            <YStack borderRadius='$6' bg='$surface' px='$4' py='$4' gap='$1' borderWidth={1} borderColor={isDarkMode ? '$transparent' : '$gray-300'}>
                <Text color='$textSecondary' fontSize={14}>
                    {t('MeusGanhosScreen.totalAReceber')}
                </Text>
                <Text color='$textPrimary' fontSize={30} fontWeight='bold'>
                    {!dados || semValores ? '—' : formatarReais(totais.valor)}
                </Text>
                <Text color='$textSecondary' fontSize={14}>
                    {dados ? `${corridas(totais.entregas)} · ${formatarKm(totais.km)}` : '—'}
                </Text>
            </YStack>
            {semValores && <Aviso tom='warning' texto={t('MeusGanhosScreen.semValores')} />}
            {pendentes > 0 && <Aviso tom='info' texto={t(pendentes === 1 ? 'MeusGanhosScreen.pendentes.one' : 'MeusGanhosScreen.pendentes.other', { count: pendentes })} />}
            {erro && <Aviso tom='error' texto={t('MeusGanhosScreen.erro')} />}
            {campoNoIos && (
                <DateTimePicker
                    value={deIso(escolha.periodo[campoNoIos])}
                    mode='date'
                    display='inline'
                    maximumDate={new Date()}
                    onChange={(evento, data) => {
                        const campo = campoNoIos;
                        setCampoNoIos(null);
                        if (evento.type === 'set' && data) {
                            escolherData(campo, data);
                        }
                    }}
                />
            )}
        </YStack>
    );

    const renderizarCorrida = ({ item }: { item: EntregaDoMotoboy }) => {
        const prefixo = item.aproximado ? '≈ ' : '';
        return (
            <Pressable onPress={() => abrirPedido(item.pedido)}>
                <XStack px='$3' py='$3' gap='$3' alignItems='center' bg='$background'>
                    <Text color='$textSecondary' fontSize={14} width={44}>
                        {horaDe(item.concluido_em)}
                    </Text>
                    <YStack flex={1}>
                        <Text color='$textPrimary' fontSize={15} fontWeight='600' numberOfLines={1}>
                            {item.loja || t('MeusGanhosScreen.semLoja')}
                        </Text>
                        {!!item.destino && (
                            <Text color='$textSecondary' fontSize={13} numberOfLines={1}>
                                {item.destino}
                            </Text>
                        )}
                    </YStack>
                    {item.km === null ? (
                        <Text color='$textSecondary' fontSize={13}>
                            {t('MeusGanhosScreen.calculando')}
                        </Text>
                    ) : (
                        <YStack alignItems='flex-end'>
                            <Text color='$textPrimary' fontSize={15} fontWeight='bold'>
                                {item.valor === null ? '—' : prefixo + formatarReais(item.valor)}
                            </Text>
                            <Text color='$textSecondary' fontSize={13}>
                                {prefixo + formatarKm(item.km)}
                            </Text>
                        </YStack>
                    )}
                    {abrindo === item.pedido ? <SafeSpinner color={corDoIcone} /> : <FontAwesomeIcon icon={faChevronRight} color={corDoIcone} size={12} />}
                </XStack>
            </Pressable>
        );
    };

    const renderizarDia = ({ section }: { section: DiaDeGanhos }) => (
        <XStack px='$3' py='$2' bg='$surface' justifyContent='space-between' alignItems='center'>
            <Text color='$textPrimary' fontSize={14} fontWeight='bold'>
                {format(deIso(section.dia), t('MeusGanhosScreen.formatoDia'), dateFnsLocaleOptions())}
            </Text>
            <Text color='$textSecondary' fontSize={14}>
                {corridas(section.entregas)} · {section.temValor ? formatarReais(section.valor) : '—'}
            </Text>
        </XStack>
    );

    const vazio = carregando ? (
        <YStack py='$6' alignItems='center'>
            <SafeSpinner color={corDoIcone} size='large' />
        </YStack>
    ) : erro ? null : (
        <YStack py='$6' px='$4' alignItems='center'>
            <Text color='$textSecondary' fontSize={15} textAlign='center'>
                {t('MeusGanhosScreen.vazio')}
            </Text>
        </YStack>
    );

    return (
        <YStack flex={1} bg='$background'>
            <SectionList
                sections={secoes}
                keyExtractor={(item) => item.pedido}
                renderItem={renderizarCorrida}
                renderSectionHeader={renderizarDia}
                ListHeaderComponent={cabecalho}
                ListEmptyComponent={vazio}
                ListFooterComponent={<Spacer height={120} />}
                ItemSeparatorComponent={() => <Separator borderColor='$borderColorWithShadow' />}
                stickySectionHeadersEnabled={false}
                refreshControl={<RefreshControl refreshing={atualizando} onRefresh={() => recarregar('puxar')} tintColor={theme['$blue-500'].val} />}
                showsVerticalScrollIndicator={false}
            />
        </YStack>
    );
};

export default MeusGanhosScreen;
```

- [ ] **Passo 2: navegação** — em `src/navigation/DriverNavigator.tsx`:

Trocar:
```tsx
import DriverDashboardScreen from '../screens/DriverDashboardScreen';
```
por:
```tsx
import MeusGanhosScreen from '../screens/MeusGanhosScreen';
```

Trocar o bloco inteiro do `DriverDashboardTab`:
```tsx
const DriverDashboardTab = createNativeStackNavigator({
    initialRouteName: 'DriverDashboard',
    screens: {
        DriverDashboard: {
            screen: DriverDashboardScreen,
            options: ({ route, navigation }) => {
                return {
                    headerShown: false,
                };
            },
        },
    },
});
```
por:
```tsx
// opções da tela de um item do pedido (Entity), usadas pelas pilhas do Início e dos Pedidos
const entityScreenOptions = ({ route, navigation }) => {
    const params = route.params ?? {};
    const entity = params.entity;

    return {
        headerTitle: '',
        headerShown: true,
        headerLeft: (props) => (
            <Text color='$textPrimary' fontSize={20} fontWeight='bold' numberOfLines={1}>
                {entity.name ?? entity.tracking_number.tracking_number}
            </Text>
        ),
        headerRight: (props) => (
            <XStack alignItems='center' space='$2'>
                <Badge status={entity.tracking_number.status_code.toLowerCase()} />
                <HeaderButton icon={faTimes} onPress={() => navigation.goBack()} />
            </XStack>
        ),
        headerStyle: {
            backgroundColor: getTheme('background'),
            headerTintColor: getTheme('borderColor'),
        },
        presentation: 'modal',
    };
};

const DriverDashboardTab = createNativeStackNavigator({
    initialRouteName: 'DriverDashboard',
    screens: {
        // Entregas: o Início é a tela Meus ganhos (filtro de período, total a receber e corridas)
        DriverDashboard: {
            screen: MeusGanhosScreen,
            options: ({ route, navigation }) => {
                return {
                    headerShown: false,
                };
            },
        },
        // detalhes da corrida tocada na lista de ganhos (o voltar retorna à lista)
        Order: {
            screen: OrderScreen,
            options: ({ route, navigation }) => {
                return {
                    headerShown: false,
                };
            },
        },
        Entity: {
            screen: EntityScreen,
            options: entityScreenOptions,
        },
    },
});
```

No `DriverTaskTab`, trocar o bloco `Entity` (que hoje tem as opções escritas por extenso):
```tsx
        Entity: {
            screen: EntityScreen,
            options: ({ route, navigation }) => {
                const params = route.params ?? {};
                const entity = params.entity;

                return {
                    headerTitle: '',
                    headerShown: true,
                    headerLeft: (props) => (
                        <Text color='$textPrimary' fontSize={20} fontWeight='bold' numberOfLines={1}>
                            {entity.name ?? entity.tracking_number.tracking_number}
                        </Text>
                    ),
                    headerRight: (props) => (
                        <XStack alignItems='center' space='$2'>
                            <Badge status={entity.tracking_number.status_code.toLowerCase()} />
                            <HeaderButton icon={faTimes} onPress={() => navigation.goBack()} />
                        </XStack>
                    ),
                    headerStyle: {
                        backgroundColor: getTheme('background'),
                        headerTintColor: getTheme('borderColor'),
                    },
                    presentation: 'modal',
                };
            },
        },
```
por:
```tsx
        Entity: {
            screen: EntityScreen,
            options: entityScreenOptions,
        },
```

- [ ] **Passo 3: apagar o Início antigo**

```bash
cd /c/tmp/nv && git rm -q src/screens/DriverDashboardScreen.tsx && grep -rn "DriverDashboardScreen" src --include=*.tsx --include=*.ts | grep -v "t('DriverDashboardScreen" ; echo "referências restantes: $?"
```
Esperado: nenhuma linha de import/uso (`referências restantes: 1`, isto é, o grep não achou nada).

- [ ] **Passo 4: conferir a sintaxe e os imports**

```bash
cd /c/tmp/nv && node $SCRATCH/conferir-tsx.cjs src/screens/MeusGanhosScreen.tsx src/navigation/DriverNavigator.tsx
grep -n "export function dateFnsLocaleOptions\|export function translate" src/utils/localize.js
grep -n "^export const toast\|^export default Spacer\|^export default useRessincronizar\|^export default function useAppTheme" src/utils/toast.js src/components/Spacer.tsx src/hooks/use-ressincronizar.ts src/hooks/use-app-theme.ts
grep -n "export const ATALHOS\|export function agruparPorDia\|export function ajustarPeriodo\|export function atalhoDoPeriodo\|export function deIso\|export function horaDe\|export function paraIso\|export function periodoDoAtalho\|export type RespostaGanhos\|export type DiaDeGanhos" src/utils/ganhos.ts
```
Esperado: os dois arquivos `OK`; uma linha para cada nome importado (2 do `localize.js`, 4 do grep seguinte e 10 do `ganhos.ts`).

- [ ] **Passo 5: commit**

```bash
cd /c/tmp/nv && git rev-parse --show-toplevel && git add src/screens/MeusGanhosScreen.tsx src/navigation/DriverNavigator.tsx && git commit -q -m "Início vira Meus ganhos: período com atalhos e De/Até, total a receber e corridas por dia

Abre sempre no mês atual; tocar numa corrida abre os detalhes na própria aba. O Início
antigo (rastreamento, coordenadas, velocidade) sai.

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>" && git log --oneline -1 && git status --short
```
Esperado: o commit inclui a remoção de `src/screens/DriverDashboardScreen.tsx` (feita pelo `git rm` no Passo 3) e o `git status` fica limpo.

---

## Fase C — Verificação e entrega

### Tarefa 13: verificação completa

- [ ] **Passo 1: API e console**

```bash
cd /c/tmp/gm
for t in ganhos-motoboy reenvio avisos-push; do PHP_WASM_DIR=$SCRATCH/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php | tail -1; done
PHP_WASM_DIR=$SCRATCH/php-wasm node scripts/teste-php/sintaxe.mjs $(git diff --name-only origin/main -- '*.php')
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal > /dev/null; echo "i18n=$?"
git log --oneline origin/main..HEAD
```
Esperado: `FALHAS: 0` três vezes; todos os PHP alterados com `OK`; `i18n=0`; 9 commits (spec, plano e Tarefas 1 a 7).

- [ ] **Passo 2: app**

```bash
cd /c/tmp/nv
node --experimental-strip-types --test scripts/testes/ganhos.teste.ts 2>&1 | grep -E "^# (pass|fail)"
node $SCRATCH/conferir-tsx.cjs $(git diff --name-only --diff-filter=AM origin/main -- '*.ts' '*.tsx')
node -e "require('./translations/pt.json'); require('./translations/en.json'); console.log('JSON ok')"
git log --oneline origin/main..HEAD
```
Esperado: `# pass 7`, `# fail 0`; todos `OK`; `JSON ok`; 5 commits (Tarefas 8 a 12).

- [ ] **Passo 3: revisão do código** — use o skill `superpowers:requesting-code-review` sobre os dois diffs (`git -C /c/tmp/gm diff origin/main...HEAD` e `git -C /c/tmp/nv diff origin/main...HEAD`), com foco em: nenhuma resposta das rotas do motoboy traz `valor_loja`/margem; o motoboy sai sempre do token; o formato do relatório e do extrato não mudou; nenhum import inexistente no app. Corrija o que for confirmado e repita os Passos 1 e 2.

### Tarefa 14: publicar e implantar (só com aprovação do Edgard)

Antes de cada passo com efeito fora desta máquina (push, deploy), pergunte ao Edgard.

- [ ] **Passo 1: push da API e do console**

```bash
cd /c/tmp/gm && git fetch origin && git rebase origin/main && git push origin HEAD:main
```
Esperado: push aceito (se o rebase tiver conflito, pare e resolva com o Edgard).

- [ ] **Passo 2: deploy na VPS (o Edgard roda)**

```bash
cd ~/entregas && bash deploy/atualizar.sh api
bash deploy/atualizar.sh console
docker exec $(docker ps -q -f name=entregas_application | head -1) php artisan migrate:status | grep entregas_valores_pedido
```
Esperado: a migration `2026_10_03_120000_create_entregas_valores_pedido_table` como `Ran`.

- [ ] **Passo 3: checagem da rota (daqui)**

```bash
curl -s -m 20 -w '\nHTTP %{http_code}\n' 'https://entregas-api.restaurantepro.com.br/v1/entregas/motoboy/ganhos?inicio=2026-10-01&fim=2026-10-03'
```
Esperado: `HTTP 401` com "No api credentials found" (antes do deploy era `HTTP 404` "There is nothing to see here.": a rota passou a existir e exige token).

- [ ] **Passo 4: console** — o Edgard abre Fleet-Ops → Recursos → Pagamento e cobrança (Ctrl+Shift+R), período do mês atual: os valores têm de ser os mesmos de antes do deploy (o primeiro carregamento congela tudo com a tabela atual) e o texto das faixas fala do valor congelado.

- [ ] **Passo 5: push do app e APK**

```bash
cd /c/tmp/nv && git fetch origin && git rebase origin/main && git push origin HEAD:main
gh run list --repo edgardjnr/entregas-navigator --limit 1
gh run watch --repo edgardjnr/entregas-navigator $(gh run list --repo edgardjnr/entregas-navigator --limit 1 --json databaseId -q '.[0].databaseId') --exit-status
```
Esperado: o workflow "Build APK (Entregas)" termina com sucesso e publica o artifact `entregas-motoboy-<n>`.

- [ ] **Passo 6: teste no celular (o Edgard, com o APK novo)**
  1. Início abre em "Meus ganhos", com "Este mês" destacado e o período do dia 1 até hoje.
  2. Total do mês de um motoboy = o "a pagar" dele em Pagamento e cobrança no mesmo período.
  3. Atalhos e De/Até trocam a lista; De depois do Até ajusta o outro campo; mais de 3 meses mostra o aviso.
  4. Tocar numa corrida abre os detalhes, com a seção "Valor da entrega"; voltar retorna à lista.
  5. Pedido aberto novo: o card mostra "Entrega de X km · Você recebe R$ Y"; depois de concluído, o mesmo valor aparece na lista e em Pagamento e cobrança.

### Tarefa 15: memória

- [ ] **Passo 1:** atualizar `C:\Users\Edgardjr\.claude\projects\C--Users-Edgardjr-Documents-vibe-coding-Delivery\memory\` com um arquivo `ganhos-do-motoboy.md` (o que entrou, commits dos dois repos, número do APK, o que o Edgard já testou e o que falta) e a linha correspondente no `MEMORY.md`.
