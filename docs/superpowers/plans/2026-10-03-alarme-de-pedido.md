# Alarme de pedido e avisos em pt-BR — plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** alarme de pedido em loop, que toca mesmo no silencioso e acende a tela no celular bloqueado, com o app fora da frente, e todos os avisos ao motoboy em pt-BR.

**Architecture:** no servidor (`api/app`), um canal FCM nosso (`CanalFcmEntregas`) substitui o do pacote e passa todo push pelo `AvisosDoMotoboy`, que põe o texto em pt-BR, escolhe o canal do app e, com a chave `ENTREGAS_ALARME_POR_DADOS` ligada, manda o alarme como push de dados. No app, o `MainApplication` usa o ponto de extensão da react-native-notifications (`INotificationsApplication`) para o `PushDoEntregas`, que transforma o push de dados de alarme numa notificação insistente no canal de alarme com tela cheia (`AlarmeDePedido`, `AlarmePedidoActivity`). O JS lê o push inicial e o alarme pendente para abrir o pedido e verifica, ao abrir, o que pode bloquear o alarme.

**Tech Stack:** Laravel 10 / PHP 8.2 (fleetops-api 0.6.65, core-api 1.6.61, laravel-notification-channels/fcm 4.5.0); React Native 0.86 com Kotlin (react-native-notifications 5.1.0, androidx.core); testes de PHP com php-wasm 3.1.54.

Desenho: `docs/superpowers/specs/2026-10-03-alarme-de-pedido-design.md`.

---

## Para quem executa

- **Dois repositórios:**
  - Servidor: `edgardjnr/fleetbase` numa **worktree destacada** em `C:\tmp\em` (criada a partir de `origin/main`). Commits locais ali; o push é `git push origin HEAD:main`, só com aprovação do Edgard. **Nunca mexa na pasta `C:\Users\Edgardjr\Documents\vibe coding\Delivery`**: outra sessão do Claude trabalha nela.
  - App: `edgardjnr/entregas-navigator` em `C:\Users\Edgardjr\Documents\vibe coding\entregas-navigator`, branch `main`. Cada push na `main` gera o APK no GitHub Actions. Commits locais por tarefa; push só na Task 17, com aprovação.
- **Não há PHP nem Android SDK nesta máquina.**
  - PHP: testes de comportamento com php-wasm (Task 1). Pasta com o php-wasm instalado nesta sessão:
    `PHP_WASM_DIR=/c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/a6f158cb-f434-432c-85c6-a9dfe4b9f49e/scratchpad/teste-reenvio`.
    Em outra máquina: `npm i --prefix <pasta> @php-wasm/node@3.1.54 @php-wasm/universal@3.1.54`.
  - Sintaxe de PHP: `PHP_PARSER_DIR=/c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/a6f158cb-f434-432c-85c6-a9dfe4b9f49e/scratchpad/php-lint node scripts/php-lint.cjs <arquivos>`.
  - Sintaxe de TS/TSX: `@babel/parser` instalado em `.../scratchpad/ferramentas` (Task 13, passo 1).
  - Kotlin: só compila no CI (Task 17). Revise o código com cuidado antes do push.
- Comentários e textos em pt-BR, no estilo do código vizinho. Kotlin com 2 espaços (como o `MainApplication.kt`); PHP e TS com 4.
- Rode os comandos de Bash com caminhos absolutos ou `git -C`; não use `cd ... &&` encadeado com comandos de escrita.

## Mapa de arquivos

Servidor (`C:\tmp\em`):

| Arquivo | Responsabilidade |
|---|---|
| `scripts/teste-php/rodar.mjs` (novo) | Roda um teste PHP no PHP 8.2 (php-wasm) com o repo montado em `/repo` |
| `scripts/teste-php/stubs.php` (novo) | Stubs de Laravel/Fleetbase, autoload do `api/app`, `confere()`/`resumo()` |
| `scripts/teste-php/stubs-avisos.php` (novo) | Stubs do pacote FCM, kreait, Log e notificações do Fleet-Ops/core |
| `scripts/teste-php/reenvio.php` (novo) | Testes do reenvio de pedido aberto (vindos do scratchpad) |
| `scripts/teste-php/avisos-push.php` (novo) | Testes do `AvisosDoMotoboy` e do `CanalFcmEntregas` |
| `api/app/Notifications/Entregas/AvisosDoMotoboy.php` (novo) | Texto pt-BR, canal e formato de cada push |
| `api/app/Notifications/Entregas/CanalFcmEntregas.php` (novo) | Canal FCM: adapta e envia; erro → envia o original |
| `api/app/Notifications/Entregas/LembretePedidoAberto.php` | Usa o texto da coleta do `AvisosDoMotoboy` |
| `api/app/Console/Commands/Entregas/ReenviarPedidosAbertos.php` | Usa `StatusDoPedido::ENCERRADOS` |
| `api/app/Support/Entregas/StatusDoPedido.php` | Docblock cita o reenvio |
| `api/app/Providers/AppServiceProvider.php` | Troca `FcmChannel` → `CanalFcmEntregas` |
| `CLAUDE.md` | Documentação |

App (`entregas-navigator`):

| Arquivo | Responsabilidade |
|---|---|
| `android/app/src/main/res/values/entregas_strings.xml` (novo) | Textos nativos em pt-BR |
| `android/app/src/main/java/io/fleetbase/navigator/AlarmeDePedido.kt` (novo) | Canal de alarme, notificação insistente, pendente, cancelar |
| `android/app/src/main/java/io/fleetbase/navigator/SilenciarAlarmeReceiver.kt` (novo) | Botão "Silenciar" e arrastar para o lado |
| `android/app/src/main/java/io/fleetbase/navigator/AlarmePedidoActivity.kt` (novo) | Tela cheia sobre o bloqueio |
| `android/app/src/main/java/io/fleetbase/navigator/PushDoEntregas.kt` (novo) | Push da biblioteca: alarme fora da frente |
| `android/app/src/main/java/io/fleetbase/navigator/MainApplication.kt` | Canais + `INotificationsApplication` |
| `android/app/src/main/java/io/fleetbase/navigator/AlertaPedidoModule.kt` | Cancela o alarme; `verificar`, `resolver`, `consumirPendente` |
| `android/app/src/main/AndroidManifest.xml` | Permissões, activity e receiver |
| `src/utils/alerta-pedido.ts` | Funções JS do alarme |
| `src/contexts/NotificationContext.tsx` | `consumirNotificacaoInicial` |
| `src/hooks/use-verificacao-do-alarme.ts` (novo) | Aviso ao abrir o app |
| `src/layouts/DriverLayout.tsx` | Abre push inicial e alarme pendente; usa a verificação |
| `src/contexts/LocationContext.tsx` | Notificação fixa do rastreamento em pt-BR |
| `translations/pt.json`, `translations/en.json` | Textos JS |

---

## Fase 1 — Servidor

### Task 1: Executor de testes PHP no repo + testes do reenvio

**Files:**
- Create: `C:\tmp\em\scripts\teste-php\rodar.mjs`
- Create: `C:\tmp\em\scripts\teste-php\stubs.php`
- Create: `C:\tmp\em\scripts\teste-php\reenvio.php`

- [ ] **Step 1: Criar o executor**

`scripts/teste-php/rodar.mjs`:

```js
// Testes de comportamento do PHP do api/app sem PHP instalado (não há PHP no Windows): roda um arquivo de teste no
// PHP 8.2 (php-wasm, a versão da produção) com o repositório montado em /repo.
//
// Instalação, uma vez, numa pasta fora do repo:
//   npm i --prefix <pasta> @php-wasm/node@3.1.54 @php-wasm/universal@3.1.54
// Uso, na raiz do repo:
//   PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/avisos-push.php
//
// O teste imprime PASSA/FALHA por caso e termina com "FALHAS: <n>"; sai com 1 se houver falha ou erro de PHP.
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const raiz = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const arquivo = process.argv[2];
if (!arquivo) {
    console.error('Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs <teste.php>');
    process.exit(2);
}

const carregar = createRequire(path.join(path.resolve(process.env.PHP_WASM_DIR || '.'), 'index.js'));
const { PHP } = carregar('@php-wasm/universal');
const { loadNodeRuntime, createNodeFsMountHandler } = carregar('@php-wasm/node');

const php = new PHP(await loadNodeRuntime('8.2', { emscriptenOptions: { processId: 1 } }));
await php.mount('/repo', createNodeFsMountHandler(raiz));

const caminho = '/repo/' + path.relative(raiz, path.resolve(arquivo)).split(path.sep).join('/');
let saida;
let erros;
try {
    const resultado = await php.run({ scriptPath: caminho });
    saida = resultado.text;
    erros = resultado.errors;
} catch (erro) {
    // o php-wasm lança quando o PHP termina com erro fatal; a saída vem junto
    saida = erro.response ? new TextDecoder().decode(erro.response.bytes) : '';
    erros = erro.response ? erro.response.errors : String(erro);
}
process.stdout.write(saida);
if (erros) process.stderr.write(erros);
process.exit(/FALHAS: 0\s*$/.test(saida) ? 0 : 1);
```

- [ ] **Step 2: Criar os stubs base**

`scripts/teste-php/stubs.php` (os stubs usados no teste do reenvio, mais o autoload do `api/app` e as funções do teste):

```php
<?php

// Stubs mínimos do Laravel/Fleetbase para os testes de scripts/teste-php: rodam os arquivos reais do Fleet-Ops
// (fleetops-api 0.6.65) e os nossos do api/app sem vendor nem banco. Ver rodar.mjs.

namespace Illuminate\Console {
    class Command
    {
        public array $opcoes = ['sandbox' => false, 'testing' => false, 'days' => 2];
        public array $saida  = [];

        public function option($chave = null) { return $this->opcoes[$chave] ?? null; }
        public function info($m) { $this->saida[] = $m; }
        public function warn($m) { $this->saida[] = $m; }
        public function line($m) { $this->saida[] = $m; }
        public function error($m) { $this->saida[] = $m; }
        public function alert($m) { $this->saida[] = $m; }
        public function table($cabecalho, $linhas) {}
    }
}

namespace Illuminate\Database\Eloquent {
    class Collection implements \IteratorAggregate, \Countable
    {
        public function __construct(public array $itens = []) {}
        public function isEmpty() { return !$this->itens; }
        public function count(): int { return count($this->itens); }
        public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->itens); }
        public function map($f) { return new static(array_map($f, $this->itens)); }
    }
}

namespace Illuminate\Support {
    // como o Carbon mutável do Laravel: sub* altera o próprio objeto e devolve $this
    class Carbon extends \DateTime
    {
        public static function now($tz = null): static { return new static(\Carbon\CarbonImmutable::$agoraTeste ?? 'now', new \DateTimeZone('UTC')); }
        public function subHours($n) { $this->modify("-{$n} hours"); return $this; }
        public function subMinutes($n) { $this->modify("-{$n} minutes"); return $this; }
        public function subDays($n) { $this->modify("-{$n} days"); return $this; }
        public function toDateTimeString() { return $this->format('Y-m-d H:i:s'); }
    }
}

namespace Illuminate\Support\Facades {
    class Cache
    {
        public static array $dados = [];
        public static function get($chave, $padrao = null) { return array_key_exists($chave, self::$dados) ? self::$dados[$chave] : $padrao; }
        public static function put($chave, $valor, $ttl = null) { self::$dados[$chave] = $valor; return true; }
    }
}

namespace Carbon {
    class CarbonImmutable extends \DateTimeImmutable
    {
        public static ?string $agoraTeste = null;
        public static function now($tz = null): static { return new static(self::$agoraTeste ?? 'now', new \DateTimeZone('UTC')); }
        public function subMinutes($n) { return $this->modify("-{$n} minutes"); }
        public function subSeconds($n) { return $this->modify("-{$n} seconds"); }
        public function subDays($n) { return $this->modify("-{$n} days"); }
        public function addDay() { return $this->modify('+1 day'); }
    }
}

namespace Illuminate\Notifications { class Notification {} }
namespace Illuminate\Contracts\Queue { interface ShouldQueue {} }
namespace Illuminate\Bus { trait Queueable {} }
namespace Fleetbase\LaravelMysqlSpatial\Types { class Point {} }

namespace Fleetbase\FleetOps\Support {
    class Utils
    {
        public static function castBoolean($v) { return filter_var($v, FILTER_VALIDATE_BOOLEAN); }
        public static function isPoint($p) { return $p instanceof \Fleetbase\LaravelMysqlSpatial\Types\Point; }
        public static function formatMeters($m, $abreviado = true) { return round($m / 1000, 1) . 'km'; }
    }
}

namespace Fleetbase\FleetOps\Models {
    class Order
    {
        public $uuid;
        public $public_id;
        public $adhoc                = 1;
        public $dispatched           = 1;
        public $started              = 0;
        public $driver_assigned_uuid = null;
        public $deleted_at           = null;
        public $status               = 'dispatched';
        public $created_at;
        public $dispatched_at;
        public $payload        = true;
        public $company_uuid   = 'empresa';
        public $adhoc_distance = 6000;

        public static function on($conexao) { return new \Teste\ConsultaPedidos(); }
        public function getPickupLocation() { return new \Fleetbase\LaravelMysqlSpatial\Types\Point(); }
        public function getAdhocPingDistance(): int { return (int) $this->adhoc_distance; }
        public function setRelations(array $relacoes) { return $this; }
    }

    class Driver
    {
        public $name;
        public $public_id;
        public $online       = 1;
        public $status       = 'available';
        public $distance;
        public $company_uuid = 'empresa';

        public static function query() { return new \Teste\ConsultaMotoboys(); }
        public function notify($aviso) { \Teste\Registro::$avisos[] = ['quando' => \Carbon\CarbonImmutable::now(), 'motoboy' => $this->name, 'aviso' => $aviso]; }
    }
}

namespace Teste {
    use Illuminate\Database\Eloquent\Collection;

    class Registro
    {
        public static array $avisos = [];
    }

    function comparar($x, $op, $y)
    {
        $x = $x instanceof \DateTimeInterface ? $x->getTimestamp() : $x;
        $y = $y instanceof \DateTimeInterface ? $y->getTimestamp() : $y;

        return match ($op) { '=' => $x == $y, '!=', '<>' => $x != $y, '>=' => $x >= $y, '<=' => $x <= $y, '>' => $x > $y, '<' => $x < $y };
    }

    // Pedidos em memória com os filtros que o comando monta. Os limites do whereBetween são lidos no get(), como o
    // Laravel faz ao formatar os bindings na execução.
    class ConsultaPedidos
    {
        public static array $pedidos        = [];
        public static ?array $limitesUsados = null;
        private array $filtros              = [];
        private array $entre                = [];

        public function withoutGlobalScopes() { return $this; }

        public function where($coluna, $op = null, $valor = null)
        {
            if (is_array($coluna)) {
                foreach ($coluna as $c => $v) {
                    $this->filtros[] = fn ($p) => $p->$c == $v;
                }

                return $this;
            }
            if (func_num_args() === 2) {
                $valor = $op;
                $op    = '=';
            }
            $this->filtros[] = fn ($p) => comparar($p->$coluna, $op, $valor);

            return $this;
        }

        public function whereNull($c) { $this->filtros[] = fn ($p) => $p->$c === null; return $this; }
        public function whereNotIn($c, array $valores) { $this->filtros[] = fn ($p) => !in_array($p->$c, $valores, true); return $this; }
        public function whereBetween($c, array $limites) { $this->entre = [$c, $limites[0], $limites[1]]; return $this; }
        public function whereHas($relacao, $cb = null) { if ($relacao === 'payload') { $this->filtros[] = fn ($p) => (bool) $p->payload; } return $this; }
        public function with($relacoes) { return $this; }

        public function get()
        {
            [$c, $de, $ate]      = $this->entre;
            self::$limitesUsados = [$de->format('Y-m-d H:i:s'), $ate->format('Y-m-d H:i:s')];
            $filtros             = array_merge($this->filtros, [fn ($p) => comparar($p->$c, '>=', $de) && comparar($p->$c, '<=', $ate)]);

            return new Collection(array_values(array_filter(self::$pedidos, function ($p) use ($filtros) {
                foreach ($filtros as $f) {
                    if (!$f($p)) {
                        return false;
                    }
                }

                return true;
            })));
        }
    }

    // Motoboys em memória: online/status pelos where, raio pelo distanceSphere (todos da mesma empresa)
    class ConsultaMotoboys
    {
        public static array $motoboys = [];
        private array $filtros        = [];
        private $raio                 = null;

        public function where($coluna, $op = null, $valor = null)
        {
            if ($coluna instanceof \Closure) {
                return $this;
            }
            if (is_array($coluna)) {
                foreach ($coluna as $c => $v) {
                    $this->filtros[] = fn ($m) => $m->$c == $v;
                }

                return $this;
            }
            if (func_num_args() === 2) {
                $valor = $op;
                $op    = '=';
            }
            $this->filtros[] = fn ($m) => comparar($m->$coluna, $op, $valor);

            return $this;
        }

        public function whereNull($c) { return $this; }
        public function whereNotNull($c) { return $this; }
        public function whereRaw($sql) { return $this; }
        public function withoutGlobalScopes() { return $this; }
        public function distanceSphere($coluna, $ponto, $distancia) { $this->raio = $distancia; return $this; }
        public function distanceSphereValue($coluna, $ponto) { return $this; }

        public function get()
        {
            $raio    = $this->raio;
            $filtros = $this->filtros;

            return new Collection(array_values(array_filter(self::$motoboys, function ($m) use ($raio, $filtros) {
                if ($raio !== null && $m->distance > $raio) {
                    return false;
                }
                foreach ($filtros as $f) {
                    if (!$f($m)) {
                        return false;
                    }
                }

                return true;
            })));
        }
    }
}

namespace {
    function now()
    {
        return \Carbon\CarbonImmutable::now();
    }

    // classes do api/app (App\...) carregadas direto do repositório montado em /repo
    spl_autoload_register(function (string $classe) {
        if (str_starts_with($classe, 'App\\')) {
            $arquivo = '/repo/api/app/' . str_replace('\\', '/', substr($classe, 4)) . '.php';
            if (is_file($arquivo)) {
                require $arquivo;
            }
        }
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

- [ ] **Step 3: Criar o teste do reenvio**

`scripts/teste-php/reenvio.php`:

```php
<?php

// Reenvio do aviso de pedido aberto (ReenviarPedidosAbertos), com os arquivos reais do Fleet-Ops (fleetops-api 0.6.65).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/reenvio.php

require __DIR__ . '/stubs.php';
require '/repo/packages/fleetops/server/src/Notifications/OrderPing.php';
require '/repo/packages/fleetops/server/src/Console/Commands/DispatchAdhocOrders.php';

use App\Console\Commands\Entregas\ReenviarPedidosAbertos;
use App\Notifications\Entregas\LembretePedidoAberto;
use Carbon\CarbonImmutable;
use Fleetbase\FleetOps\Console\Commands\DispatchAdhocOrders;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\Cache;
use Teste\ConsultaMotoboys;
use Teste\ConsultaPedidos;
use Teste\Registro;

function utc(string $hora): DateTimeImmutable
{
    return new DateTimeImmutable('2026-10-03 ' . $hora, new DateTimeZone('UTC'));
}

function pedido(string $id, string $despachadoEm): Order
{
    $p                = new Order();
    $p->uuid          = 'uuid-' . $id;
    $p->public_id     = $id;
    $p->created_at    = utc('11:50:00');
    $p->dispatched_at = utc($despachadoEm);

    return $p;
}

function motoboy(string $nome, int $distancia, string $status = 'available', int $online = 1): Driver
{
    $m            = new Driver();
    $m->name      = $nome;
    $m->public_id = 'driver_' . $nome;
    $m->distance  = $distancia;
    $m->status    = $status;
    $m->online    = $online;

    return $m;
}

function reiniciar(array $pedidos, array $motoboys): void
{
    ConsultaPedidos::$pedidos   = $pedidos;
    ConsultaMotoboys::$motoboys = $motoboys;
    Cache::$dados               = [];
    Registro::$avisos           = [];
}

// roda o comando a cada minuto, de 2 a 6 s depois do minuto cheio, como o agendador
function rodarMinutos(string $de, int $minutos, ?callable $antes = null): void
{
    for ($i = 0; $i < $minutos; $i++) {
        $agora                       = utc($de)->modify("+{$i} minutes")->modify('+' . (2 + ($i * 7) % 5) . ' seconds');
        CarbonImmutable::$agoraTeste = $agora->format('Y-m-d H:i:s');
        if ($antes) {
            $antes($agora);
        }
        (new ReenviarPedidosAbertos())->handle();
    }
}

function horarios(string $pedido): array
{
    $avisos = array_filter(Registro::$avisos, fn ($a) => $a['aviso']->order->public_id === $pedido);

    return array_values(array_map(fn ($a) => $a['quando']->format('H:i:s'), $avisos));
}

function intervalos(array $horarios): array
{
    $saida = [];
    for ($i = 1; $i < count($horarios); $i++) {
        $saida[] = utc($horarios[$i])->getTimestamp() - utc($horarios[$i - 1])->getTimestamp();
    }

    return $saida;
}

echo '== Original do Fleet-Ops (fleetops-api 0.6.65)' . PHP_EOL;
reiniciar([pedido('PED-A', '12:05:00')], [motoboy('Motoca', 1200)]);
CarbonImmutable::$agoraTeste = '2026-10-03 12:10:00';
$encontrados                 = (new DispatchAdhocOrders())->getDispatchableOrders(2);
[$de, $ate]                  = ConsultaPedidos::$limitesUsados;
confere($de === $ate, "os dois limites do whereBetween viram o mesmo instante ({$de} e {$ate})");
confere($encontrados->isEmpty(), 'o pedido aberto há 5 min não é encontrado');

echo '== Nosso comando, mesmo pedido' . PHP_EOL;
reiniciar([pedido('PED-A', '12:05:00')], [motoboy('Motoca', 1200)]);
(new ReenviarPedidosAbertos())->handle();
[$de, $ate] = ConsultaPedidos::$limitesUsados;
confere($de === '2026-10-03 11:54:00' && $ate === '2026-10-03 12:06:30', "janela de despacho de {$de} a {$ate}");
confere(count(Registro::$avisos) === 1, 'o pedido aberto há 5 min recebe o aviso');

echo '== Linha do tempo: despachado 12:00:30 e ninguém aceita' . PHP_EOL;
reiniciar([pedido('PED-B', '12:00:30')], [motoboy('Motoca', 1200), motoboy('Ocupado', 800, 'busy'), motoboy('Longe', 9000), motoboy('Offline', 500, 'available', 0)]);
rodarMinutos('12:01:00', 30);
$h = horarios('PED-B');
confere(count($h) === 3, 'três avisos extras e nada depois, em 30 min (' . implode(', ', $h) . ')');
confere(($h[0] ?? '') >= '12:04:00' && ($h[0] ?? '') < '12:05:00', 'o primeiro sai uns 4 min depois do despacho');
$gaps = intervalos($h);
confere($gaps && min($gaps) >= 230 && max($gaps) <= 250, 'cerca de 4 min entre um aviso e outro (' . implode('s, ', $gaps) . 's)');
$nomes = array_values(array_unique(array_map(fn ($a) => $a['motoboy'], Registro::$avisos)));
confere($nomes === ['Motoca'], 'só o motoboy online, livre e no raio recebe (' . implode(', ', $nomes) . ')');
$aviso = Registro::$avisos[0]['aviso'];
confere($aviso instanceof LembretePedidoAberto, 'o aviso é o LembretePedidoAberto');
confere($aviso->title === 'Pedido ainda sem motoboy' && $aviso->message === 'Coleta a 1,2 km de você. Toque para ver o pedido.', "texto em pt-BR: {$aviso->title} / {$aviso->message}");
confere($aviso->data === ['id' => 'PED-B', 'type' => 'order_ping'], 'dados do push iguais aos do primeiro aviso (order_ping)');

echo '== Sem motoboy no raio até 12:07' . PHP_EOL;
$motoca = motoboy('Motoca', 9000);
reiniciar([pedido('PED-C', '12:00:30')], [$motoca]);
rodarMinutos('12:01:00', 30, function ($agora) use ($motoca) {
    $motoca->distance = $agora >= utc('12:07:00') ? 1200 : 9000;
});
$h = horarios('PED-C');
confere(count($h) === 3 && $h[0] >= '12:07:00' && $h[0] < '12:08:00', 'não gasta reenvio sem motoboy e avisa assim que um entra no raio (' . implode(', ', $h) . ')');

echo '== Aceito às 12:06' . PHP_EOL;
$p = pedido('PED-D', '12:00:30');
reiniciar([$p], [motoboy('Motoca', 1200)]);
rodarMinutos('12:01:00', 20, function ($agora) use ($p) {
    if ($agora >= utc('12:06:00')) {
        $p->driver_assigned_uuid = 'uuid-motoca';
    }
});
confere(count(horarios('PED-D')) === 1, 'para de avisar depois do aceite (' . implode(', ', horarios('PED-D')) . ')');

echo '== Cancelado pela loja às 12:06' . PHP_EOL;
$p = pedido('PED-E', '12:00:30');
reiniciar([$p], [motoboy('Motoca', 1200)]);
rodarMinutos('12:01:00', 20, function ($agora) use ($p) {
    if ($agora >= utc('12:06:00')) {
        $p->status = 'canceled';
    }
});
confere(count(horarios('PED-E')) === 1, 'para de avisar depois do cancelamento (' . implode(', ', horarios('PED-E')) . ')');

echo '== Despachado de novo às 12:20' . PHP_EOL;
$p = pedido('PED-F', '12:00:30');
reiniciar([$p], [motoboy('Motoca', 1200)]);
rodarMinutos('12:01:00', 40, function ($agora) use ($p) {
    if ($agora->format('H:i') === '12:20') {
        $p->dispatched_at = utc('12:20:00');
    }
});
$h = horarios('PED-F');
confere(count($h) === 6 && ($h[3] ?? '') >= '12:23:30' && ($h[3] ?? '') < '12:25:00', 'um novo despacho recomeça a contagem (' . implode(', ', $h) . ')');

echo '== Dois pedidos ao mesmo tempo' . PHP_EOL;
reiniciar([pedido('PED-G1', '12:00:30'), pedido('PED-G2', '12:02:10')], [motoboy('Motoca', 1200)]);
rodarMinutos('12:01:00', 30);
confere(count(horarios('PED-G1')) === 3 && count(horarios('PED-G2')) === 3, 'cada pedido tem a própria contagem (' . implode(', ', horarios('PED-G1')) . ' | ' . implode(', ', horarios('PED-G2')) . ')');

echo '== Texto da distância' . PHP_EOL;
$p = pedido('PED-H', '12:00:00');
confere((new LembretePedidoAberto($p, 800))->message === 'Coleta a 800 m de você. Toque para ver o pedido.', '800 m');
confere((new LembretePedidoAberto($p, 15000))->message === 'Coleta a 15,0 km de você. Toque para ver o pedido.', '15,0 km');
confere((new LembretePedidoAberto($p, null))->message === 'Toque para ver o pedido.', 'sem distância');

resumo();
```

- [ ] **Step 4: Rodar e ver passar (é o código que já está em produção)**

```bash
PHP_WASM_DIR=/c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/a6f158cb-f434-432c-85c6-a9dfe4b9f49e/scratchpad/teste-reenvio node /c/tmp/em/scripts/teste-php/rodar.mjs /c/tmp/em/scripts/teste-php/reenvio.php
```

Esperado: 19 linhas `PASSA`, `FALHAS: 0`, exit 0.

- [ ] **Step 5: Commit**

```bash
git -C /c/tmp/em add scripts/teste-php/rodar.mjs scripts/teste-php/stubs.php scripts/teste-php/reenvio.php
git -C /c/tmp/em commit -m "Testes de PHP sem PHP instalado: scripts/teste-php (php-wasm) e o teste do reenvio"
```

(Toda mensagem de commit deste plano termina com a linha `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`; use `-F -` com heredoc para mensagens de várias linhas.)

- [x] **Ajustes da revisão de qualidade** (commit seguinte ao da Task 1): o `now()` do stub devolve o Carbon mutável, como o do Laravel (senão o teste deixava passar o bug de Carbon mutável); warning e notice do PHP viram `ErrorException`, como no `HandleExceptions` do Laravel; `confereVersaoDoFleetOps()`; cenário de pedidos que não são oferecidos (não ad hoc, iniciado, apagado, sem carga, criado há 3 dias); relógio fixo na seção "Nosso comando"; comentários no `rodar.mjs`. O `reenvio.php` passa a ter 25 casos.

---

### Task 2: Reenvio usa a lista única `StatusDoPedido::ENCERRADOS`

**Files:**
- Modify: `C:\tmp\em\api\app\Console\Commands\Entregas\ReenviarPedidosAbertos.php`
- Modify: `C:\tmp\em\api\app\Support\Entregas\StatusDoPedido.php` (docblock)
- Test: `C:\tmp\em\scripts\teste-php\reenvio.php`

- [ ] **Step 1: Escrever o teste que falha**

Em `reenvio.php`, antes da linha final `resumo();`:

```php
echo '== Lista única de status encerrados' . PHP_EOL;
confere(!defined(ReenviarPedidosAbertos::class . '::ENCERRADOS'), 'o reenvio usa StatusDoPedido::ENCERRADOS, sem lista própria');
foreach (App\Support\Entregas\StatusDoPedido::ENCERRADOS as $status) {
    $p         = pedido('PED-S', '12:00:30');
    $p->status = $status;
    reiniciar([$p], [motoboy('Motoca', 1200)]);
    rodarMinutos('12:01:00', 6);
    confere(horarios('PED-S') === [], "pedido {$status} não é reenviado");
}
```

- [ ] **Step 2: Rodar e ver falhar**

Mesmo comando da Task 1, passo 4. Esperado: `FALHA o reenvio usa StatusDoPedido::ENCERRADOS, sem lista própria`, `FALHAS: 1`.

- [ ] **Step 3: Implementar**

Em `ReenviarPedidosAbertos.php`:
1. Nos `use`, depois de `use App\Notifications\Entregas\LembretePedidoAberto;`, acrescente `use App\Support\Entregas\StatusDoPedido;`.
2. Apague o bloco:
```php
    /** Pedido nesses status não é mais oferecido aos motoboys. */
    public const ENCERRADOS = ['completed', 'done', 'canceled', 'cancelled', 'order_canceled', 'expired'];

```
3. Troque `->whereNotIn('status', self::ENCERRADOS)` por `->whereNotIn('status', StatusDoPedido::ENCERRADOS)`.

Em `StatusDoPedido.php`, no docblock da classe, troque a linha

```php
 * (BarrarAceiteDePedidoEncerrado) e a posição do motoboy no portal (PortalLojaController).
```

por

```php
 * (BarrarAceiteDePedidoEncerrado), a posição do motoboy no portal (PortalLojaController) e o reenvio do aviso de
 * pedido aberto (ReenviarPedidosAbertos).
```

Ainda em `StatusDoPedido.php`, o docblock da constante `ENCERRADOS` também passa a citar o reenvio (em uma linha passaria de 120 colunas; fica multilinha, como o da classe):

```php
    /**
     * Pedido encerrado: a loja não cancela, o motoboy não aceita, a loja não vê mais o motoboy e o aviso de
     * pedido aberto não é reenviado.
     */
```

- [ ] **Step 4: Rodar e ver passar**

Mesmo comando. Esperado: `FALHAS: 0` (32 casos: os 25 da Task 1, a checagem da lista própria e um por status de `StatusDoPedido::ENCERRADOS`, hoje 6).

- [ ] **Step 5: Commit**

```bash
git -C /c/tmp/em add api/app/Console/Commands/Entregas/ReenviarPedidosAbertos.php api/app/Support/Entregas/StatusDoPedido.php scripts/teste-php/reenvio.php
git -C /c/tmp/em commit -m "Reenvio de pedido aberto: usa a lista única StatusDoPedido::ENCERRADOS"
```

---

### Task 3: `AvisosDoMotoboy` — textos em pt-BR

**Files:**
- Create: `C:\tmp\em\scripts\teste-php\stubs-avisos.php`
- Create: `C:\tmp\em\scripts\teste-php\avisos-push.php`
- Create: `C:\tmp\em\api\app\Notifications\Entregas\AvisosDoMotoboy.php`

- [ ] **Step 1: Criar os stubs dos avisos**

`scripts/teste-php/stubs-avisos.php`:

```php
<?php

// Stubs dos testes de avisos push (avisos-push.php), além do stubs.php: o pacote FCM (laravel-notification-channels/fcm
// 4.5.0, MIT, copiado: o formato da mensagem e o envio do canal), o kreait, partes do Laravel e as notificações do
// Fleet-Ops e do core com o que o AvisosDoMotoboy lê delas. O OrderPing é o real.

namespace Illuminate\Contracts\Events {
    interface Dispatcher
    {
        public function dispatch($event, $payload = [], $halt = false);
    }
}

namespace Illuminate\Notifications\Events {
    class NotificationFailed
    {
        public function __construct(public $notifiable, public $notification, public $channel, public $data = []) {}
    }
}

namespace Illuminate\Support {
    class Arr
    {
        public static function wrap($valor): array
        {
            return $valor === null ? [] : (is_array($valor) ? $valor : [$valor]);
        }
    }

    class Collection implements \IteratorAggregate, \Countable
    {
        public function __construct(protected array $itens = []) {}
        public static function make($itens = []): static { return new static(is_array($itens) ? $itens : iterator_to_array($itens)); }
        public function chunk(int $tamanho): static { return new static(array_map(fn ($parte) => new static($parte), array_chunk($this->itens, $tamanho, true))); }
        public function map(callable $funcao): static { return new static(array_map($funcao, $this->itens)); }
        public function filter(?callable $funcao = null): static { return new static($funcao ? array_filter($this->itens, $funcao) : array_filter($this->itens)); }
        public function each(callable $funcao): static { foreach ($this->itens as $chave => $valor) { $funcao($valor, $chave); } return $this; }
        public function all(): array { return $this->itens; }
        public function count(): int { return count($this->itens); }
        public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->itens); }
    }
}

namespace Illuminate\Support\Facades {
    class Log
    {
        public static array $registros = [];
        public static function warning($mensagem, array $contexto = []) { self::$registros[] = ['warning', $mensagem, $contexto]; }
        public static function error($mensagem, array $contexto = []) { self::$registros[] = ['error', $mensagem, $contexto]; }
    }
}

namespace Kreait\Firebase\Contract {
    interface Messaging
    {
        public function sendMulticast($message, $registrationTokens, bool $validateOnly = false);
    }
}

namespace Kreait\Firebase\Messaging {
    interface Message extends \JsonSerializable {}

    class SendReport
    {
        public function __construct(private bool $falhou = false) {}
        public function isFailure(): bool { return $this->falhou; }
    }

    class MulticastSendReport
    {
        public function __construct(private array $itens = []) {}
        public function getItems(): array { return $this->itens; }
    }
}

namespace NotificationChannels\Fcm\Resources {
    abstract class FcmResource
    {
        public static function create(...$args): static { return new static(...$args); }
        abstract public function toArray(): array;
    }

    class Notification extends FcmResource
    {
        public function __construct(public ?string $title = null, public ?string $body = null, public ?string $image = null) {}
        public function toArray(): array { return array_filter(['title' => $this->title, 'body' => $this->body, 'image' => $this->image]); }
    }
}

namespace NotificationChannels\Fcm {
    use Illuminate\Contracts\Events\Dispatcher;
    use Illuminate\Notifications\Events\NotificationFailed;
    use Illuminate\Notifications\Notification;
    use Illuminate\Support\Arr;
    use Illuminate\Support\Collection;
    use Kreait\Firebase\Contract\Messaging;
    use Kreait\Firebase\Messaging\Message;
    use Kreait\Firebase\Messaging\MulticastSendReport;
    use Kreait\Firebase\Messaging\SendReport;
    use NotificationChannels\Fcm\Resources\Notification as NotificacaoFcm;

    class FcmMessage implements Message
    {
        public function __construct(
            public ?string $name = null,
            public ?string $token = null,
            public ?string $topic = null,
            public ?string $condition = null,
            public ?array $data = [],
            public array $custom = [],
            public ?NotificacaoFcm $notification = null,
            public ?Messaging $client = null,
        ) {}

        public function data(?array $data): self { $this->data = $data; return $this; }
        public function custom(?array $custom): self { $this->custom = $custom; return $this; }
        public function usingClient(Messaging $client): self { $this->client = $client; return $this; }

        public function toArray()
        {
            return array_filter([
                'name'         => $this->name,
                'data'         => $this->data,
                'token'        => $this->token,
                'topic'        => $this->topic,
                'condition'    => $this->condition,
                'notification' => $this->notification?->toArray(),
                ...$this->custom,
            ]);
        }

        public function jsonSerialize(): mixed { return $this->toArray(); }
    }

    class FcmChannel
    {
        const TOKENS_PER_REQUEST = 500;

        public function __construct(protected Dispatcher $events, protected Messaging $client) {}

        public function send(mixed $notifiable, Notification $notification): ?Collection
        {
            $tokens = Arr::wrap($notifiable->routeNotificationFor('fcm', $notification));

            if (empty($tokens)) {
                return null;
            }

            $fcmMessage = $notification->toFcm($notifiable);

            return Collection::make($tokens)
                ->chunk(self::TOKENS_PER_REQUEST)
                ->map(fn ($tokens) => ($fcmMessage->client ?? $this->client)->sendMulticast($fcmMessage, $tokens->all()))
                ->map(fn (MulticastSendReport $report) => $this->checkReportForFailures($notifiable, $notification, $report));
        }

        protected function checkReportForFailures(mixed $notifiable, Notification $notification, MulticastSendReport $report): MulticastSendReport
        {
            Collection::make($report->getItems())
                ->filter(fn (SendReport $report) => $report->isFailure())
                ->each(fn (SendReport $report) => $this->dispatchFailedNotification($notifiable, $notification, $report));

            return $report;
        }

        protected function dispatchFailedNotification(mixed $notifiable, Notification $notification, SendReport $report): void
        {
            $this->events->dispatch(new NotificationFailed($notifiable, $notification, self::class, ['report' => $report]));
        }
    }
}

namespace Fleetbase\Support {
    // o createFcmMessage do core-api 1.6.61 (src/Support/PushNotification.php), sem configurar o cliente do Firebase
    class PushNotification
    {
        public static ?\Kreait\Firebase\Contract\Messaging $cliente = null;

        public static function createFcmMessage(string $title, string $body, array $data = []): \NotificationChannels\Fcm\FcmMessage
        {
            $mensagem = (new \NotificationChannels\Fcm\FcmMessage(notification: new \NotificationChannels\Fcm\Resources\Notification(title: $title, body: $body)))
                ->data($data)
                ->custom([
                    'android' => [
                        'notification' => ['color' => '#4391EA', 'sound' => 'default'],
                        'fcm_options'  => ['analytics_label' => 'analytics'],
                    ],
                    'apns' => [
                        'payload'     => ['aps' => ['sound' => 'default']],
                        'fcm_options' => ['analytics_label' => 'analytics'],
                    ],
                ]);

            return static::$cliente ? $mensagem->usingClient(static::$cliente) : $mensagem;
        }
    }
}

namespace Fleetbase\FleetOps\Notifications {
    // o que o AvisosDoMotoboy lê das notificações reais do fleetops-api 0.6.65: título e texto originais (em inglês),
    // dados e o pedido; o toFcm é igual ao delas
    abstract class AvisoDoFleetOps extends \Illuminate\Notifications\Notification
    {
        public $order;
        public string $title   = '';
        public string $message = '';
        public array $data     = [];

        public function toFcm($notifiable)
        {
            return \Fleetbase\Support\PushNotification::createFcmMessage($this->title, $this->message, $this->data);
        }
    }

    class OrderAssigned extends AvisoDoFleetOps {}
    class OrderDispatched extends AvisoDoFleetOps {}
    class OrderCanceled extends AvisoDoFleetOps {}
    class OrderFailed extends AvisoDoFleetOps {}
    class OrderCompleted extends AvisoDoFleetOps {}
    class WaypointCompleted extends AvisoDoFleetOps {}
}

namespace Fleetbase\Notifications {
    class ChatMessageReceived extends \Fleetbase\FleetOps\Notifications\AvisoDoFleetOps {}
    class TestPushNotification extends \Fleetbase\FleetOps\Notifications\AvisoDoFleetOps {}
}
```

- [ ] **Step 2: Escrever o teste que falha**

`scripts/teste-php/avisos-push.php`:

```php
<?php

// Avisos push do motoboy (AvisosDoMotoboy e CanalFcmEntregas): texto em pt-BR, canal e formato.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/avisos-push.php

require __DIR__ . '/stubs.php';
require __DIR__ . '/stubs-avisos.php';
require '/repo/packages/fleetops/server/src/Notifications/OrderPing.php';

use App\Notifications\Entregas\AvisosDoMotoboy;
use App\Notifications\Entregas\LembretePedidoAberto;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\OrderAssigned;
use Fleetbase\FleetOps\Notifications\OrderCanceled;
use Fleetbase\FleetOps\Notifications\OrderCompleted;
use Fleetbase\FleetOps\Notifications\OrderDispatched;
use Fleetbase\FleetOps\Notifications\OrderFailed;
use Fleetbase\FleetOps\Notifications\OrderPing;
use Fleetbase\FleetOps\Notifications\WaypointCompleted;
use Fleetbase\Notifications\ChatMessageReceived;
use Fleetbase\Notifications\TestPushNotification;

function pedidoDoTeste(): Order
{
    $pedido            = new Order();
    $pedido->uuid      = 'uuid-1';
    $pedido->public_id = 'order_abc';

    return $pedido;
}

// aviso do Fleet-Ops com o título e o texto originais (em inglês) e os dados que ele manda
function aviso(string $classe, string $titulo, string $texto, array $dados)
{
    $notificacao          = new $classe();
    $notificacao->title   = $titulo;
    $notificacao->message = $texto;
    $notificacao->data    = $dados;

    return $notificacao;
}

function avisosDoFleetOps(): array
{
    return [
        'atribuído' => aviso(OrderAssigned::class, 'New order RP-1 assigned!', 'You have a new order assigned, tap for details.', ['id' => 'order_abc', 'type' => 'order_assigned']),
        'liberado'  => aviso(OrderDispatched::class, 'Order RP-1 has been dispatched!', 'An order has just been dispatched to you and is ready to be started.', ['id' => 'order_abc', 'type' => 'order_dispatched']),
        'cancelado' => aviso(OrderCanceled::class, 'Order RP-1 was canceled', 'Order RP-1 has been canceled.', ['id' => 'order_abc', 'type' => 'order_canceled']),
        'falhou'    => aviso(OrderFailed::class, 'Order RP-1 delivery has has failed', 'Order RP-1 delivery has failed.', ['id' => 'order_abc', 'type' => 'order_canceled']),
        'concluído' => aviso(OrderCompleted::class, 'Order RP-1 has been completed.', 'Order RP-1 has been completed by agent.', ['id' => 'order_abc', 'type' => 'order_completed']),
        'parada'    => aviso(WaypointCompleted::class, 'Order RP-1 driver has arrived', 'Driver has arrived', ['id' => 'waypoint_1', 'type' => 'waypoint_completed']),
        'chat'      => aviso(ChatMessageReceived::class, 'Message from Edgard Junior', 'preciso de motoca', ['id' => 'chat_message_1', 'type' => 'chat_message_received', 'channel' => 'chat_channel_1']),
        'teste'     => aviso(TestPushNotification::class, 'Teste', 'Push de teste do admin', ['id' => 'abc123', 'message' => 'Test Push Notification', 'type' => 'test']),
    ];
}

echo '== Textos em pt-BR' . PHP_EOL;
$avisos    = avisosDoFleetOps();
$esperados = [
    'atribuído' => ['Novo pedido para você', 'Pedido RP-1. Toque para ver os detalhes.'],
    'liberado'  => ['Pedido RP-1 liberado para você', 'Toque para ver e iniciar a entrega.'],
    'cancelado' => ['Pedido RP-1 cancelado', 'O pedido RP-1 foi cancelado.'],
    'falhou'    => ['Entrega do pedido RP-1 não concluída', 'A entrega do pedido RP-1 falhou.'],
    'concluído' => ['Pedido RP-1 concluído', 'O pedido RP-1 foi concluído.'],
    'parada'    => ['Pedido RP-1: parada concluída', 'Uma parada do pedido RP-1 foi concluída.'],
    'chat'      => ['Mensagem de Edgard Junior', 'preciso de motoca'],
    'teste'     => ['Teste', 'Push de teste do admin'],
];
foreach ($esperados as $nome => $esperado) {
    $obtido = AvisosDoMotoboy::texto($avisos[$nome]);
    confere($obtido === $esperado, $nome . ': ' . json_encode($obtido, JSON_UNESCAPED_UNICODE));
}

$casos = [
    'pedido novo'               => [new OrderPing(pedidoDoTeste(), 1234), ['Novo pedido disponível', 'Coleta a 1,2 km de você. Toque para ver o pedido.']],
    'pedido novo sem distância' => [new OrderPing(pedidoDoTeste()), ['Novo pedido disponível', 'Toque para ver o pedido.']],
    'reenvio (já em pt-BR)'     => [new LembretePedidoAberto(pedidoDoTeste(), 800), ['Pedido ainda sem motoboy', 'Coleta a 800 m de você. Toque para ver o pedido.']],
    'cancelado sem código'      => [aviso(OrderCanceled::class, 'Order  was canceled', 'Order  has been canceled.', ['type' => 'order_canceled']), ['Pedido cancelado', 'O pedido foi cancelado.']],
    'parada sem código'         => [aviso(WaypointCompleted::class, 'Order  driver has arrived', 'x', ['type' => 'waypoint_completed']), ['Parada concluída', 'Uma parada do pedido foi concluída.']],
    'atribuído sem código'      => [aviso(OrderAssigned::class, 'New order  assigned!', 'You have a new order assigned, tap for details.', ['type' => 'order_assigned']), ['Novo pedido para você', 'Toque para ver os detalhes.']],
    'chat com outro título'     => [aviso(ChatMessageReceived::class, 'Something', 'oi', ['type' => 'chat_message_received']), ['Nova mensagem', 'oi']],
];
foreach ($casos as $nome => [$notificacao, $esperado]) {
    $obtido = AvisosDoMotoboy::texto($notificacao);
    confere($obtido === $esperado, $nome . ': ' . json_encode($obtido, JSON_UNESCAPED_UNICODE));
}

$agendado        = aviso(OrderAssigned::class, 'New order RP-2 assigned!', 'You have a new order scheduled for 2026-10-03 18:00:00', ['type' => 'order_assigned']);
$agendado->order = (object) ['scheduled_at' => new DateTimeImmutable('2026-10-03 18:00:00', new DateTimeZone('UTC')), 'company' => (object) ['timezone' => 'America/Sao_Paulo']];
$obtido          = AvisosDoMotoboy::texto($agendado);
confere($obtido === ['Novo pedido para você', 'Pedido RP-2 agendado para 03/10 às 15:00.'], 'atribuído agendado, no fuso da organização: ' . json_encode($obtido, JSON_UNESCAPED_UNICODE));
$agendado->order = (object) ['scheduled_at' => new DateTimeImmutable('2026-10-03 18:00:00', new DateTimeZone('UTC')), 'company' => null];
confere((AvisosDoMotoboy::texto($agendado)[1] ?? null) === 'Pedido RP-2 agendado para 03/10 às 15:00.', 'agendado sem fuso usa America/Sao_Paulo');
$agendado->order = null;
confere((AvisosDoMotoboy::texto($agendado)[1] ?? null) === 'Pedido RP-2. Toque para ver os detalhes.', 'agendado sem data cai no texto comum');

confere(AvisosDoMotoboy::texto(new Illuminate\Notifications\Notification()) === null, 'classe sem tradução devolve null');

resumo();
```

- [ ] **Step 3: Rodar e ver falhar**

```bash
PHP_WASM_DIR=/c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/a6f158cb-f434-432c-85c6-a9dfe4b9f49e/scratchpad/teste-reenvio node /c/tmp/em/scripts/teste-php/rodar.mjs /c/tmp/em/scripts/teste-php/avisos-push.php
```

Esperado: erro fatal `Class "App\Notifications\Entregas\AvisosDoMotoboy" not found`, exit 1.

- [ ] **Step 4: Implementar o `AvisosDoMotoboy` (textos; o formato vem na Task 4)**

`api/app/Notifications/Entregas/AvisosDoMotoboy.php`:

```php
<?php

namespace App\Notifications\Entregas;

use Fleetbase\FleetOps\Notifications\OrderAssigned;
use Fleetbase\FleetOps\Notifications\OrderCanceled;
use Fleetbase\FleetOps\Notifications\OrderCompleted;
use Fleetbase\FleetOps\Notifications\OrderDispatched;
use Fleetbase\FleetOps\Notifications\OrderFailed;
use Fleetbase\FleetOps\Notifications\OrderPing;
use Fleetbase\FleetOps\Notifications\WaypointCompleted;
use Fleetbase\Notifications\ChatMessageReceived;
use Fleetbase\Notifications\TestPushNotification;
use Illuminate\Notifications\Notification;

/**
 * Entregas RestaurantePro: o push que o motoboy recebe, em pt-BR e no formato que o app trata. O CanalFcmEntregas
 * passa todo push por aqui.
 *
 * Os avisos do Fleet-Ops e do core saem em inglês; cada classe conhecida ganha título e texto em pt-BR. O código do
 * pedido vem do título original ("Order X …" / "New order X …"); sem ele, a frase sai sem o código.
 */
class AvisosDoMotoboy
{
    /**
     * Título e texto em pt-BR do aviso, ou null se a classe não tem tradução.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function texto(Notification $notificacao): ?array
    {
        $codigo = static::codigo((string) ($notificacao->title ?? ''));

        return match (true) {
            $notificacao instanceof LembretePedidoAberto => [$notificacao->title, $notificacao->message],
            $notificacao instanceof OrderPing            => ['Novo pedido disponível', static::textoDaColeta($notificacao->distance)],
            $notificacao instanceof OrderAssigned        => ['Novo pedido para você', static::textoDoAtribuido($notificacao, $codigo)],
            $notificacao instanceof OrderDispatched      => [static::comCodigo('Pedido %s liberado para você', 'Pedido liberado para você', $codigo), 'Toque para ver e iniciar a entrega.'],
            $notificacao instanceof OrderFailed          => [static::comCodigo('Entrega do pedido %s não concluída', 'Entrega não concluída', $codigo), static::comCodigo('A entrega do pedido %s falhou.', 'A entrega do pedido falhou.', $codigo)],
            $notificacao instanceof OrderCanceled        => [static::comCodigo('Pedido %s cancelado', 'Pedido cancelado', $codigo), static::comCodigo('O pedido %s foi cancelado.', 'O pedido foi cancelado.', $codigo)],
            $notificacao instanceof OrderCompleted       => [static::comCodigo('Pedido %s concluído', 'Pedido concluído', $codigo), static::comCodigo('O pedido %s foi concluído.', 'O pedido foi concluído.', $codigo)],
            $notificacao instanceof WaypointCompleted    => [static::comCodigo('Pedido %s: parada concluída', 'Parada concluída', $codigo), static::comCodigo('Uma parada do pedido %s foi concluída.', 'Uma parada do pedido foi concluída.', $codigo)],
            $notificacao instanceof ChatMessageReceived  => [static::tituloDoChat((string) $notificacao->title), (string) $notificacao->message],
            $notificacao instanceof TestPushNotification => [(string) $notificacao->title, (string) $notificacao->message],
            default                                      => null,
        };
    }

    /** "Coleta a 1,2 km de você. Toque para ver o pedido." (sem distância, só o convite). */
    public static function textoDaColeta($distancia): string
    {
        if (!$distancia) {
            return 'Toque para ver o pedido.';
        }

        $metros = (float) $distancia;
        $texto  = $metros < 1000 ? (int) round($metros) . ' m' : number_format($metros / 1000, 1, ',', '.') . ' km';

        return 'Coleta a ' . $texto . ' de você. Toque para ver o pedido.';
    }

    /** O código de rastreamento que o Fleet-Ops põe no título original ("Order X …" / "New order X …"). */
    protected static function codigo(string $tituloOriginal): ?string
    {
        return preg_match('/^(?:New order|Order) (\S+)/', $tituloOriginal, $achado) ? $achado[1] : null;
    }

    protected static function comCodigo(string $comCodigo, string $semCodigo, ?string $codigo): string
    {
        return $codigo === null ? $semCodigo : str_replace('%s', $codigo, $comCodigo);
    }

    protected static function textoDoAtribuido(OrderAssigned $notificacao, ?string $codigo): string
    {
        $agendado = str_starts_with((string) $notificacao->message, 'You have a new order scheduled for');
        $quando   = $agendado ? static::quandoAgendado($notificacao) : null;

        if ($quando !== null) {
            return static::comCodigo('Pedido %s agendado para ' . $quando . '.', 'Pedido agendado para ' . $quando . '.', $codigo);
        }

        return static::comCodigo('Pedido %s. Toque para ver os detalhes.', 'Toque para ver os detalhes.', $codigo);
    }

    /** "03/10 às 15:00", no fuso da organização do pedido (America/Sao_Paulo se faltar). */
    protected static function quandoAgendado(OrderAssigned $notificacao): ?string
    {
        try {
            $agendado = $notificacao->order?->scheduled_at;
            if (!$agendado instanceof \DateTimeInterface) {
                return null;
            }

            $fuso  = $notificacao->order?->company?->timezone ?: 'America/Sao_Paulo';
            $local = \DateTimeImmutable::createFromInterface($agendado)->setTimezone(new \DateTimeZone($fuso));

            return $local->format('d/m') . ' às ' . $local->format('H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    protected static function tituloDoChat(string $tituloOriginal): string
    {
        $prefixo = 'Message from ';

        return str_starts_with($tituloOriginal, $prefixo) ? 'Mensagem de ' . substr($tituloOriginal, strlen($prefixo)) : 'Nova mensagem';
    }
}
```

- [ ] **Step 5: Rodar e ver passar**

Mesmo comando do passo 3. Esperado: `FALHAS: 0` (26 casos). Rode também o `reenvio.php` (Task 1, passo 4): `FALHAS: 0`.

- [ ] **Step 6: Sintaxe na versão da produção**

```bash
PHP_PARSER_DIR=/c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/a6f158cb-f434-432c-85c6-a9dfe4b9f49e/scratchpad/php-lint node /c/tmp/em/scripts/php-lint.cjs /c/tmp/em/api/app/Notifications/Entregas/AvisosDoMotoboy.php
```

Esperado: `ok` e exit 0.

- [ ] **Step 7: Commit**

```bash
git -C /c/tmp/em add api/app/Notifications/Entregas/AvisosDoMotoboy.php scripts/teste-php/stubs-avisos.php scripts/teste-php/avisos-push.php
git -C /c/tmp/em commit -m "Avisos ao motoboy em pt-BR: textos por classe de notificação (AvisosDoMotoboy)"
```

- [x] **Ajustes da revisão de qualidade** (commit seguinte ao da Task 3): `textoDaColeta()` arredonda os metros antes de escolher a unidade (999,6 m sai "1,0 km", e não "1000 m"; 0,4 m e "0.0" ficam só com o convite, sem "Coleta a 0 m"); o `avisos-push.php` usa as notificações reais do Fleet-Ops e do core (cópias em `packages/`) em vez de stubs inventados: o `stubs-avisos.php` perdeu os blocos `AvisoDoFleetOps` e `Fleetbase\Notifications`, e o `aviso()` cria a instância com `newInstanceWithoutConstructor()`, porque o construtor real pede os modelos (na Task 5, o `AvisoQuebrado`, que herda do `OrderCanceled` real, nasce do mesmo jeito); casos novos: distância (999,6, 0,4 e "0.0"), frases sem o código de rastreamento (liberado, falhou, concluído) e agendado sem código. O `avisos-push.php` passa a ter 26 casos (a Task 4 termina com 73 e a Task 5 com 80).

---

### Task 4: `AvisosDoMotoboy` — canal e formato (chave do push de dados)

**Files:**
- Modify: `C:\tmp\em\api\app\Notifications\Entregas\AvisosDoMotoboy.php`
- Test: `C:\tmp\em\scripts\teste-php\avisos-push.php`

- [ ] **Step 1: Escrever o teste que falha**

Em `avisos-push.php`, antes da linha final `resumo();`:

```php
function enviado($notificacao): array
{
    return AvisosDoMotoboy::adaptar($notificacao, $notificacao->toFcm(null))->toArray();
}

echo '== Canal e formato (chave desligada, o padrão)' . PHP_EOL;
putenv('ENTREGAS_ALARME_POR_DADOS');
$avisos = avisosDoFleetOps();
$ping   = enviado(new OrderPing(pedidoDoTeste(), 1234));
confere(($ping['notification'] ?? null) === ['title' => 'Novo pedido disponível', 'body' => 'Coleta a 1,2 km de você. Toque para ver o pedido.'], 'pedido novo: push comum com o texto em pt-BR');
confere(($ping['android']['notification']['channel_id'] ?? null) === 'alarme_pedido', 'pedido novo no canal "alarme_pedido"');
confere(!isset($ping['android']['priority']), 'sem prioridade de push de dados com a chave desligada');
$canais = ['atribuído' => 'alarme_pedido', 'liberado' => 'alarme_pedido', 'chat' => 'mensagens', 'cancelado' => 'avisos', 'falhou' => 'avisos', 'concluído' => 'avisos', 'parada' => 'avisos', 'teste' => 'avisos'];
foreach ($canais as $nome => $canal) {
    $mensagem = enviado($avisos[$nome]);
    confere(($mensagem['android']['notification']['channel_id'] ?? null) === $canal, "{$nome}: canal \"{$canal}\"");
}
$chat = enviado($avisos['chat']);
confere(($chat['notification']['title'] ?? null) === 'Mensagem de Edgard Junior' && ($chat['data']['channel'] ?? null) === 'chat_channel_1', 'chat: texto em pt-BR e os dados da conversa mantidos');
confere(($chat['android']['notification']['color'] ?? null) === '#4391EA', 'o resto do push do Fleet-Ops (cor, som) fica');

echo '== Alarme como push de dados (chave ligada)' . PHP_EOL;
foreach (['1', 'true', 'on', 'ON'] as $valor) {
    putenv("ENTREGAS_ALARME_POR_DADOS={$valor}");
    confere(AvisosDoMotoboy::alarmePorDados(), "chave '{$valor}' liga");
}
foreach (['0', 'false', 'off', ''] as $valor) {
    putenv("ENTREGAS_ALARME_POR_DADOS={$valor}");
    confere(!AvisosDoMotoboy::alarmePorDados(), "chave '{$valor}' desliga");
}
putenv('ENTREGAS_ALARME_POR_DADOS=1');
$ping = enviado(new OrderPing(pedidoDoTeste(), 1234));
confere(!isset($ping['notification']), 'pedido novo vai sem bloco de notificação');
confere(($ping['data'] ?? null) === ['id' => 'order_abc', 'type' => 'order_ping', 'title' => 'Novo pedido disponível', 'body' => 'Coleta a 1,2 km de você. Toque para ver o pedido.', 'android_channel_id' => 'pedidos'], 'dados com id, tipo, título, texto e o canal do APK antigo: ' . json_encode($ping['data'] ?? null, JSON_UNESCAPED_UNICODE));
confere(($ping['android']['priority'] ?? null) === 'high' && ($ping['android']['ttl'] ?? null) === '900s', 'prioridade alta e validade de 15 min');
confere(!isset($ping['android']['notification']), 'sem android.notification (senão o Android mostra como push comum)');
confere(isset($ping['android']['fcm_options'], $ping['apns']), 'o resto do push (fcm_options, apns) fica');
foreach (['atribuído', 'liberado'] as $nome) {
    $mensagem = enviado($avisos[$nome]);
    confere(!isset($mensagem['notification']) && ($mensagem['android']['priority'] ?? null) === 'high', "{$nome} também vai como push de dados");
}
$cancelado = enviado($avisos['cancelado']);
confere(($cancelado['notification']['title'] ?? null) === 'Pedido RP-1 cancelado' && !isset($cancelado['android']['priority']), 'cancelado continua push comum com a chave ligada');
$comTipos = aviso(OrderAssigned::class, 'New order RP-1 assigned!', 'You have a new order assigned, tap for details.', ['id' => 'order_abc', 'type' => 'order_assigned', 'tentativa' => 2, 'urgente' => true, 'vazio' => null]);
$dados    = enviado($comTipos)['data'];
confere($dados['tentativa'] === '2' && $dados['urgente'] === '1' && !array_key_exists('vazio', $dados), 'dados do push de dados todos como texto (nulos saem)');
putenv('ENTREGAS_ALARME_POR_DADOS');

echo '== Aviso sem tradução' . PHP_EOL;

class AvisoNovoDoFleetOps extends Illuminate\Notifications\Notification
{
    public string $title   = 'Something new';
    public string $message = 'Body';
    public array $data     = ['type' => 'algo_novo'];

    public function toFcm($notifiable)
    {
        return Fleetbase\Support\PushNotification::createFcmMessage($this->title, $this->message, $this->data);
    }
}

Illuminate\Support\Facades\Log::$registros = [];
$mensagem                                  = enviado(new AvisoNovoDoFleetOps());
confere(($mensagem['notification']['title'] ?? null) === 'Something new' && !isset($mensagem['android']['notification']['channel_id']), 'segue como veio (texto e canal)');
confere((Illuminate\Support\Facades\Log::$registros[0][1] ?? null) === '[entregas] aviso push sem tradução', 'e fica no log');
```

- [ ] **Step 2: Rodar e ver falhar**

Mesmo comando da Task 3, passo 3. Esperado: erro fatal `Call to undefined method App\Notifications\Entregas\AvisosDoMotoboy::adaptar()`.

- [ ] **Step 3: Implementar**

Em `AvisosDoMotoboy.php`:

1. Nos `use`, acrescente (em ordem alfabética, depois de `use Illuminate\Notifications\Notification;`):
```php
use Illuminate\Support\Facades\Log;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as NotificacaoFcm;
```

2. Troque o docblock da classe por:
```php
/**
 * Entregas RestaurantePro: o push que o motoboy recebe, em pt-BR e no formato que o app trata. O CanalFcmEntregas
 * passa todo push por aqui.
 *
 * - Texto: os avisos do Fleet-Ops e do core saem em inglês; cada classe conhecida ganha título e texto em pt-BR. O
 *   código do pedido vem do título original ("Order X …" / "New order X …"); sem ele, a frase sai sem o código. Classe
 *   sem tradução segue como veio e fica registrada no log.
 * - Canal: cada tipo vai para um canal do app (alarme, mensagens, avisos). O APK sem o canal usa o padrão "pedidos".
 * - Alarme (pedido novo, reenvio, atribuído, liberado): com ENTREGAS_ALARME_POR_DADOS ligada, vira push de dados de
 *   alta prioridade, e o app (APK 16+) toca o alarme em loop com tela cheia. Desligada (padrão), vai como push comum no
 *   canal de alarme. Só ligar com todos no APK 16: no antigo, tocar no push de dados não abre o pedido.
 */
```

3. Logo depois de `class AvisosDoMotoboy` + `{`, antes do `texto()`, acrescente:
```php
    /** Tipos (data.type) que tocam o alarme de pedido no app. */
    public const TIPOS_DE_ALARME = ['order_ping', 'order_assigned', 'order_dispatched'];

    /** Canal do app por tipo de aviso (criados no MainApplication do app). */
    public const CANAIS = [
        'order_ping'            => 'alarme_pedido',
        'order_assigned'        => 'alarme_pedido',
        'order_dispatched'      => 'alarme_pedido',
        'chat_message_received' => 'mensagens',
        'order_canceled'        => 'avisos',
        'order_completed'       => 'avisos',
        'waypoint_completed'    => 'avisos',
        'test'                  => 'avisos',
    ];

    /** Canal do push de dados no APK antigo (a biblioteca de push mostra nele; o APK 16 usa o canal do alarme). */
    public const CANAL_PADRAO = 'pedidos';

    /** Validade do push de dados de alarme: o FCM descarta depois disso, e um alarme velho não toca quando o celular volta. */
    public const VALIDADE_ALARME = '900s';

    /** Adapta o push montado pela notificação: texto em pt-BR, canal e formato. */
    public static function adaptar(Notification $notificacao, FcmMessage $mensagem): FcmMessage
    {
        $tipo  = (string) ($mensagem->data['type'] ?? '');
        $texto = static::texto($notificacao);

        if ($texto === null) {
            Log::warning('[entregas] aviso push sem tradução', ['notificacao' => get_class($notificacao), 'tipo' => $tipo]);

            return $mensagem;
        }

        [$titulo, $corpo] = $texto;

        if (in_array($tipo, self::TIPOS_DE_ALARME, true) && static::alarmePorDados()) {
            return static::comoDados($mensagem, $titulo, $corpo);
        }

        $mensagem->notification = new NotificacaoFcm(title: $titulo, body: $corpo);

        if (isset(self::CANAIS[$tipo])) {
            $mensagem->custom['android']['notification']['channel_id'] = self::CANAIS[$tipo];
        }

        return $mensagem;
    }
```

4. Depois de `textoDaColeta()`, acrescente:
```php
    /** ENTREGAS_ALARME_POR_DADOS ligada (1, true ou on): o alarme vai como push de dados. Desligada por padrão. */
    public static function alarmePorDados(): bool
    {
        $valor = getenv('ENTREGAS_ALARME_POR_DADOS');

        return $valor !== false && in_array(strtolower(trim($valor)), ['1', 'true', 'on'], true);
    }
```

5. No fim da classe (depois de `tituloDoChat()`), acrescente:
```php
    /**
     * Push de dados: sem bloco de notificação (o app monta a notificação), título e texto nos dados, todos os dados como
     * texto (exigência do FCM), prioridade alta e validade curta.
     */
    protected static function comoDados(FcmMessage $mensagem, string $titulo, string $corpo): FcmMessage
    {
        $dados = [];
        foreach ((array) $mensagem->data as $chave => $valor) {
            if ($valor !== null) {
                $dados[$chave] = is_scalar($valor) ? (string) $valor : json_encode($valor);
            }
        }

        $mensagem->data         = array_merge($dados, ['title' => $titulo, 'body' => $corpo, 'android_channel_id' => self::CANAL_PADRAO]);
        $mensagem->notification = null;

        $android = (array) ($mensagem->custom['android'] ?? []);
        unset($android['notification']);
        $mensagem->custom['android'] = array_merge($android, ['priority' => 'high', 'ttl' => self::VALIDADE_ALARME]);

        return $mensagem;
    }
```

- [ ] **Step 4: Rodar e ver passar**

Mesmo comando. Esperado: `FALHAS: 0` (73 casos).

- [ ] **Step 5: Sintaxe**

Mesmo comando da Task 3, passo 6. Esperado: `ok`.

- [ ] **Step 6: Commit**

```bash
git -C /c/tmp/em add api/app/Notifications/Entregas/AvisosDoMotoboy.php scripts/teste-php/avisos-push.php
git -C /c/tmp/em commit -m "Avisos ao motoboy: canal do app por tipo e alarme como push de dados (chave ENTREGAS_ALARME_POR_DADOS)"
```

- [x] **Ajustes da revisão de qualidade** (commit seguinte ao da Task 4): o alarme como push comum (chave desligada) também leva `android.ttl = 900s`, porque um alerta de pedido velho não pode tocar horas depois, quando o celular volta a ter rede (no APK 16 o canal de alarme toca até no silencioso), e o docblock da `VALIDADE_ALARME` passa a dizer que vale para todo push de alarme; o `comoDados()` converte os dados com um `match` (escalar e `Stringable` viram texto; o resto vira JSON com `JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE`, e `?: ''` no lugar do `false`), em vez do `json_encode` simples, que deixava um objeto como `{}` e um `false` com UTF-8 inválido; `CANAIS` ganha `order_failed` => `avisos` (o Fleet-Ops hoje manda o `OrderFailed` com o tipo `order_canceled`); o docblock do `adaptar()` diz que ele altera e devolve a mesma instância (o canal da Task 5 passa um clone); casos novos no `avisos-push.php`: validade só nos três alarmes (e não no chat nem no cancelado), som, `fcm_options` e `apns` do Fleetbase mantidos no push comum, chave `' on '` com espaços, canais do chat e do cancelado com a chave ligada, `order_failed` e, no push de dados, lista, acentos, objeto com `__toString` e UTF-8 inválido. O `avisos-push.php` passa a ter 73 casos (a Task 5 termina com 80).

---

### Task 5: `CanalFcmEntregas` e a troca no `AppServiceProvider`

**Files:**
- Create: `C:\tmp\em\api\app\Notifications\Entregas\CanalFcmEntregas.php`
- Modify: `C:\tmp\em\api\app\Providers\AppServiceProvider.php`
- Test: `C:\tmp\em\scripts\teste-php\avisos-push.php`

- [ ] **Step 1: Escrever o teste que falha**

Em `avisos-push.php`, antes da linha final `resumo();`:

```php
echo '== CanalFcmEntregas' . PHP_EOL;

class MessagingFalso implements Kreait\Firebase\Contract\Messaging
{
    public array $enviados = [];

    public function sendMulticast($message, $registrationTokens, bool $validateOnly = false)
    {
        $this->enviados[] = [$message, $registrationTokens];

        return new Kreait\Firebase\Messaging\MulticastSendReport([new Kreait\Firebase\Messaging\SendReport(false), new Kreait\Firebase\Messaging\SendReport(true)]);
    }
}

class EventosFalsos implements Illuminate\Contracts\Events\Dispatcher
{
    public array $eventos = [];

    public function dispatch($event, $payload = [], $halt = false)
    {
        $this->eventos[] = $event;
    }
}

class MotoboyFalso
{
    public function __construct(public array $tokens) {}

    public function routeNotificationFor($canal, $notificacao)
    {
        return $this->tokens;
    }
}

// mensagem que falha ao ser copiada: força o erro na adaptação
class MensagemQuebrada extends NotificationChannels\Fcm\FcmMessage
{
    public function __clone()
    {
        throw new RuntimeException('falha de teste');
    }
}

class AvisoQuebrado extends OrderCanceled
{
    public function toFcm($notifiable)
    {
        return new MensagemQuebrada(data: ['type' => 'order_canceled'], notification: new NotificationChannels\Fcm\Resources\Notification(title: 'Order RP-1 was canceled', body: 'Order RP-1 has been canceled.'));
    }
}

putenv('ENTREGAS_ALARME_POR_DADOS=1');
$padrao     = new MessagingFalso();
$daMensagem = new MessagingFalso();
$eventos    = new EventosFalsos();
$canal      = new App\Notifications\Entregas\CanalFcmEntregas($eventos, $padrao);

Fleetbase\Support\PushNotification::$cliente = $daMensagem;
$canal->send(new MotoboyFalso(['token-1', 'token-2']), new OrderPing(pedidoDoTeste(), 1234));
confere(count($daMensagem->enviados) === 1 && $padrao->enviados === [], 'envia pelo cliente da mensagem (o do Fleetbase)');
[$enviada, $tokens] = $daMensagem->enviados[0] ?? [null, null];
confere($tokens === ['token-1', 'token-2'], 'para os tokens do motoboy');
confere($enviada !== null && $enviada->notification === null && ($enviada->data['title'] ?? null) === 'Novo pedido disponível', 'a mensagem enviada é a adaptada');
confere(count($eventos->eventos) === 1 && $eventos->eventos[0] instanceof Illuminate\Notifications\Events\NotificationFailed, 'token com falha gera NotificationFailed, como no pacote');
confere($canal->send(new MotoboyFalso([]), new OrderPing(pedidoDoTeste(), 1234)) === null, 'motoboy sem token: nada é enviado');

Fleetbase\Support\PushNotification::$cliente = null;
Illuminate\Support\Facades\Log::$registros    = [];
$canal->send(new MotoboyFalso(['token-1']), (new ReflectionClass(AvisoQuebrado::class))->newInstanceWithoutConstructor());
$original = $padrao->enviados[0][0] ?? null;
confere($original instanceof MensagemQuebrada && $original->notification->title === 'Order RP-1 was canceled', 'erro na adaptação: envia a mensagem original');
confere((Illuminate\Support\Facades\Log::$registros[0][1] ?? null) === '[entregas] aviso push sem adaptação', 'e registra o erro no log');
putenv('ENTREGAS_ALARME_POR_DADOS');
```

- [ ] **Step 2: Rodar e ver falhar**

Mesmo comando. Esperado: erro fatal `Class "App\Notifications\Entregas\CanalFcmEntregas" not found`.

- [ ] **Step 3: Implementar o canal**

`api/app/Notifications/Entregas/CanalFcmEntregas.php`:

```php
<?php

namespace App\Notifications\Entregas;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Messaging\MulticastSendReport;
use NotificationChannels\Fcm\FcmChannel;

/**
 * Entregas RestaurantePro: canal de push (FCM) de todos os avisos, no lugar do FcmChannel do pacote (a troca fica no
 * AppServiceProvider). Envia como o pacote (laravel-notification-channels/fcm 4.5.0), mas antes passa a mensagem pelo
 * AvisosDoMotoboy (pt-BR, canal e formato). Se a adaptação falhar, envia a mensagem original: nenhum aviso se perde.
 *
 * Ao atualizar o pacote FCM, confira se o send() do FcmChannel mudou.
 */
class CanalFcmEntregas extends FcmChannel
{
    public function send(mixed $notifiable, Notification $notification): ?Collection
    {
        $tokens = Arr::wrap($notifiable->routeNotificationFor('fcm', $notification));

        if (empty($tokens)) {
            return null;
        }

        $original = $notification->toFcm($notifiable);

        try {
            $mensagem = AvisosDoMotoboy::adaptar($notification, clone $original);
        } catch (\Throwable $erro) {
            Log::error('[entregas] aviso push sem adaptação', ['notificacao' => get_class($notification), 'erro' => $erro->getMessage()]);
            $mensagem = $original;
        }

        return Collection::make($tokens)
            ->chunk(self::TOKENS_PER_REQUEST)
            ->map(fn ($tokens) => ($mensagem->client ?? $this->client)->sendMulticast($mensagem, $tokens->all()))
            ->map(fn (MulticastSendReport $report) => $this->checkReportForFailures($notifiable, $notification, $report));
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Mesmo comando. Esperado: `FALHAS: 0` (80 casos com os blocos acima; 106 depois dos ajustes da revisão que fecham esta Task, mais abaixo).

- [ ] **Step 5: Trocar o canal no `AppServiceProvider`**

Em `api/app/Providers/AppServiceProvider.php`:
1. Nos `use`, depois de `use App\Console\Commands\Entregas\ReenviarPedidosAbertos;`, acrescente `use App\Notifications\Entregas\CanalFcmEntregas;`; depois de `use Illuminate\Support\Str;`, acrescente `use NotificationChannels\Fcm\FcmChannel;`.
2. No `register()`, depois da linha `$this->app->bind(DispatchAdhocOrders::class, ReenviarPedidosAbertos::class);`, acrescente:
```php

        // Entregas: todo push (FCM) sai em pt-BR e no formato do app do motoboy (ver CanalFcmEntregas e AvisosDoMotoboy)
        $this->app->bind(FcmChannel::class, CanalFcmEntregas::class);
```

- [ ] **Step 6: Sintaxe**

```bash
PHP_PARSER_DIR=/c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/a6f158cb-f434-432c-85c6-a9dfe4b9f49e/scratchpad/php-lint node /c/tmp/em/scripts/php-lint.cjs /c/tmp/em/api/app/Notifications/Entregas/CanalFcmEntregas.php /c/tmp/em/api/app/Providers/AppServiceProvider.php
```

Esperado: dois `ok`.

- [ ] **Step 7: Commit**

```bash
git -C /c/tmp/em add api/app/Notifications/Entregas/CanalFcmEntregas.php api/app/Providers/AppServiceProvider.php scripts/teste-php/avisos-push.php
git -C /c/tmp/em commit -m "Push do motoboy: CanalFcmEntregas no lugar do FcmChannel (adapta e, se falhar, envia o original)"
```

- [x] **Ajustes da revisão de qualidade** (commit seguinte ao da Task 5): o kreait 7.24.1 não valida a mensagem localmente (o `sendMulticast` devolve a recusa do servidor como falha no `SendReport`, sem exceção) e o pacote FCM só dispara `NotificationFailed`, que não tem ouvinte: se o FCM recusasse o formato novo (dados sem bloco de notificação, `android.ttl`/`priority`, `channel_id`), todo push daquele tipo sumiria sem uma linha de log. O `CanalFcmEntregas` passou a (1) registrar cada push recusado (`[entregas] push recusado pelo FCM`, com a classe da notificação, `mensagem_invalida` e o erro do FCM; o `checkReportForFailures` é sobrescrito e chama o do pacote em seguida, então o `NotificationFailed` continua saindo, com o canal `FcmChannel`) e (2) reenviar o push ORIGINAL (o do Fleet-Ops, que funciona hoje) aos tokens cuja recusa foi de mensagem inválida (`SendReport::messageWasInvalid()`, o 400 do FCM), com o aviso `[entregas] push adaptado recusado pelo FCM; enviado o original`. Não há laço: se a mensagem não foi adaptada (erro na adaptação), uma recusa só vai para o log. O erro da adaptação agora leva a exceção inteira (chave `exception`, com o rastreio). Stubs: `SendReport` com `messageWasInvalid`, `error` e `target`, `MessageTarget` com `value` e `Collection::merge`. O `avisos-push.php` passa de 80 para 98 casos (o `reenvio.php` segue com 32). Segunda rodada da revisão: o reenvio deixa de fora o token malformado (`!messageTargetWasInvalid()`: o 400 de token também é `messageWasInvalid()`, e o original não adiantaria); o log da recusa leva o `motoboy` (`public_id`, para responder "por que o alarme do motoboy X não tocou?"); a recusa de token que não existe mais (`messageWasSentToUnknownToken()`, o 404, token velho que ninguém limpa) vai como `info` e as demais como `warning`; o `avisos-push.php` chega a 106 casos (o `reenvio.php` segue com 32).

---

### Task 6: `LembretePedidoAberto` usa o texto da coleta do `AvisosDoMotoboy`

**Files:**
- Modify: `C:\tmp\em\api\app\Notifications\Entregas\LembretePedidoAberto.php`

- [ ] **Step 1: Refatorar (os testes já cobrem: "800 m", "15,0 km", "sem distância" no `reenvio.php` e o reenvio no `avisos-push.php`)**

Substitua o arquivo inteiro por:

```php
<?php

namespace App\Notifications\Entregas;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\OrderPing;

/**
 * Entregas RestaurantePro: aviso repetido de pedido aberto (ver ReenviarPedidosAbertos).
 *
 * É o OrderPing do Fleet-Ops, com o mesmo tipo (`order_ping` no push, `order.ping` no socket), que o app trata como
 * pedido novo. Só o título muda; o texto da coleta é o mesmo do primeiro aviso (AvisosDoMotoboy).
 */
class LembretePedidoAberto extends OrderPing
{
    public function __construct(Order $order, $distance = null)
    {
        parent::__construct($order, $distance);

        $this->title   = 'Pedido ainda sem motoboy';
        $this->message = AvisosDoMotoboy::textoDaColeta($distance);
    }
}
```

- [ ] **Step 2: Rodar os dois testes**

```bash
W=/c/tmp/em; P=/c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/a6f158cb-f434-432c-85c6-a9dfe4b9f49e/scratchpad/teste-reenvio
PHP_WASM_DIR=$P node $W/scripts/teste-php/rodar.mjs $W/scripts/teste-php/reenvio.php | tail -1
PHP_WASM_DIR=$P node $W/scripts/teste-php/rodar.mjs $W/scripts/teste-php/avisos-push.php | tail -1
```

Esperado: `FALHAS: 0` nos dois.

- [ ] **Step 3: Commit**

```bash
git -C /c/tmp/em add api/app/Notifications/Entregas/LembretePedidoAberto.php
git -C /c/tmp/em commit -m "Reenvio de pedido aberto: texto da coleta igual ao do primeiro aviso (AvisosDoMotoboy)"
```

---

### Task 7: Documentação do servidor (CLAUDE.md)

**Files:**
- Modify: `C:\tmp\em\CLAUDE.md`

- [ ] **Step 1: Estrutura**

Depois da linha `- \`deploy/\`: stack, modelo de env, \`atualizar.sh\` e README do deploy.`, acrescente:

```markdown
- `scripts/teste-php/`: testes de comportamento do PHP do `api/app` sem PHP instalado (php-wasm, PHP 8.2), com os arquivos reais do Fleet-Ops e stubs do resto. Uso no cabeçalho do `rodar.mjs`.
```

- [ ] **Step 2: App do motoboy**

Substitua a linha que começa com `- Alarme de novo pedido: canal \`pedidos\`` por:

```markdown
- **Avisos ao motoboy (push):** todos passam pelo `CanalFcmEntregas` (troca do `FcmChannel` no `AppServiceProvider`), que usa o `AvisosDoMotoboy` (`api/app/Notifications/Entregas/`):
  - texto em pt-BR por classe de notificação; aviso novo do Fleet-Ops sem tradução aparece no log como `[entregas] aviso push sem tradução`;
  - canal do app: `alarme_pedido` (pedido novo, reenvio, atribuído, liberado), `mensagens` (chat, toque longo) e `avisos` (status, som normal). APK sem o canal usa o padrão `pedidos`.
  - Recusa do FCM aparece no log como `[entregas] push recusado pelo FCM`; se o formato adaptado for recusado como inválido, o canal reenvia o push original do Fleet-Ops. **Ao atualizar o pacote `laravel-notification-channels/fcm` (hoje 4.5.0), confira o `send()` do `FcmChannel`**: o `CanalFcmEntregas` repete o dele.
- **Alarme de novo pedido:** com o app aberto, a tela do pedido toca o `AlertaPedido` (loop até aceitar/iniciar, máx. 3 min). Com o app fora da frente, o canal `alarme_pedido` (APK 16+) toca no silencioso.
  - Com `ENTREGAS_ALARME_POR_DADOS=1` nos serviços da API (Portainer), o alarme vai como push de dados e o APK 16+ toca em loop com tela cheia. **Só ligar com todos os motoboys no APK 16:** no antigo, tocar no push de dados não abre o pedido.
```

- [ ] **Step 3: Commit**

```bash
git -C /c/tmp/em add CLAUDE.md
git -C /c/tmp/em commit -m "CLAUDE.md: avisos ao motoboy em pt-BR, canais do app e chave do alarme por dados; testes de PHP"
```

---

## Fase 2 — App nativo (Android)

Pasta: `N=/c/Users/Edgardjr/Documents/vibe\ coding/entregas-navigator` (nos comandos, use o caminho entre aspas: `"/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator"`).

### Task 8: Textos nativos e canais

**Files:**
- Create: `android/app/src/main/res/values/entregas_strings.xml`
- Modify: `android/app/src/main/java/io/fleetbase/navigator/MainApplication.kt`

- [ ] **Step 1: Textos em pt-BR**

`android/app/src/main/res/values/entregas_strings.xml`:

```xml
<?xml version="1.0" encoding="utf-8"?>
<!-- Entregas: textos nativos do app, em pt-BR (canais de notificação e alarme de pedido). -->
<resources>
    <string name="canal_alarme_nome">Alarme de novo pedido</string>
    <string name="canal_alarme_descricao">Toca em loop, mesmo no silencioso, quando chega um pedido novo ou atribuído a você</string>
    <string name="canal_mensagens_nome">Mensagens</string>
    <string name="canal_mensagens_descricao">Mensagens da central e das lojas</string>
    <string name="canal_avisos_nome">Avisos de pedidos</string>
    <string name="canal_avisos_descricao">Cancelamentos e outras mudanças nos seus pedidos</string>
    <string name="canal_pedidos_nome">Pedidos</string>
    <string name="canal_pedidos_descricao">Avisos de pedidos que não têm canal próprio</string>
    <string name="alarme_titulo_padrao">Novo pedido!</string>
    <string name="alarme_ver_pedido">Ver pedido</string>
    <string name="alarme_silenciar">Silenciar</string>
</resources>
```

- [ ] **Step 2: Canais no `MainApplication`**

Em `MainApplication.kt`:
1. Imports: acrescente `import android.app.NotificationChannel` (já existe), `import androidx.annotation.RequiresApi`.
2. No `onCreate()`, troque `criarCanalDePedidos()` por `criarCanais()`.
3. Substitua a função `criarCanalDePedidos()` inteira (com o docblock) e o `companion object` por:

```kotlin
  /**
   * Entregas: canais das notificações, com nome e descrição em pt-BR. O canal é imutável depois de criado: para trocar
   * o som, mude o ID. "pedidos" é o padrão do FCM (default_notification_channel_id no manifest) e o que o Android usa
   * quando o push pede um canal que o APK não tem. Ver AvisosDoMotoboy no api/app.
   */
  private fun criarCanais() {
    if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
    val toqueLongo = Uri.parse("android.resource://$packageName/${R.raw.novo_pedido}")
    val usoToque = AudioAttributes.Builder()
      .setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE)
      .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
      .build()
    getSystemService(NotificationManager::class.java)?.createNotificationChannels(
      listOf(
        canal(CANAL_PEDIDOS, R.string.canal_pedidos_nome, R.string.canal_pedidos_descricao) { setSound(toqueLongo, usoToque); vibrarLongo() },
        canal(CANAL_MENSAGENS, R.string.canal_mensagens_nome, R.string.canal_mensagens_descricao) { setSound(toqueLongo, usoToque); vibrarLongo() },
        canal(CANAL_AVISOS, R.string.canal_avisos_nome, R.string.canal_avisos_descricao) { enableVibration(true) },
        AlarmeDePedido.canal(this),
      )
    )
  }

  @RequiresApi(Build.VERSION_CODES.O)
  private fun canal(id: String, nome: Int, descricao: Int, configurar: NotificationChannel.() -> Unit) =
    NotificationChannel(id, getString(nome), NotificationManager.IMPORTANCE_HIGH).apply {
      description = getString(descricao)
      configurar()
    }

  @RequiresApi(Build.VERSION_CODES.O)
  private fun NotificationChannel.vibrarLongo() {
    enableVibration(true)
    vibrationPattern = longArrayOf(0, 800, 600, 800, 600, 800)
    enableLights(true)
  }

  companion object {
    const val CANAL_PEDIDOS = "pedidos"
    const val CANAL_MENSAGENS = "mensagens"
    const val CANAL_AVISOS = "avisos"
  }
```

(O `AlarmeDePedido.canal` vem na Task 9; o build só fecha depois dela.)

- [ ] **Step 3: Commit**

```bash
git -C "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" add android/app/src/main/res/values/entregas_strings.xml android/app/src/main/java/io/fleetbase/navigator/MainApplication.kt
git -C "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" commit -m "Canais de notificação em pt-BR: mensagens e avisos de pedidos (alarme na próxima)"
```

---

### Task 9: `AlarmeDePedido` e `SilenciarAlarmeReceiver`

**Files:**
- Create: `android/app/src/main/java/io/fleetbase/navigator/AlarmeDePedido.kt`
- Create: `android/app/src/main/java/io/fleetbase/navigator/SilenciarAlarmeReceiver.kt`
- Modify: `android/app/src/main/AndroidManifest.xml`

- [ ] **Step 1: `AlarmeDePedido.kt`**

```kotlin
package io.fleetbase.navigator

import android.app.Activity
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.media.AudioAttributes
import android.net.Uri
import android.os.Build
import android.os.Bundle
import androidx.annotation.RequiresApi
import androidx.core.app.NotificationCompat
import com.wix.reactnativenotifications.core.notification.PushNotificationProps
import org.json.JSONObject
import java.lang.ref.WeakReference

/**
 * Entregas: alarme de pedido com o app fora da frente (fechado, em segundo plano ou com a tela bloqueada).
 *
 * Com o push de dados ligado no servidor (ENTREGAS_ALARME_POR_DADOS, ver AvisosDoMotoboy no api/app), pedido novo,
 * reenvio, atribuído e liberado chegam como dados e o PushDoEntregas chama disparar(): notificação no canal de alarme
 * (toca mesmo no silencioso), com o som em loop (FLAG_INSISTENT) até abrir, silenciar ou passar de 3 min, e tela cheia
 * no celular bloqueado (AlarmePedidoActivity). Tocar abre o app no pedido, com os dados do push como extras (como o
 * push comum), que a react-native-notifications entrega ao JS.
 *
 * O último alarme fica guardado ("pendente") por até 3 min: se o motoboy voltar ao app por outro caminho (desbloqueando
 * com o app na frente, pelo ícone), o DriverLayout abre o pedido com consumirPendente().
 */
object AlarmeDePedido {
  const val CANAL = "alarme_pedido"
  const val DURACAO_MS = 3 * 60 * 1000L
  const val EXTRA_TITULO = "titulo"
  const val EXTRA_TEXTO = "texto"
  const val EXTRA_DADOS = "dados"

  private const val TAG = "alarme_pedido"
  private const val PREFERENCIAS = "entregas_alarme_pedido"
  private const val CHAVE_DADOS = "dados"
  private const val CHAVE_QUANDO = "quando"
  private val TIPOS = setOf("order_ping", "order_assigned", "order_dispatched")

  private var tela: WeakReference<Activity>? = null

  fun ehAlarme(tipo: String?): Boolean = tipo != null && tipo in TIPOS

  @RequiresApi(Build.VERSION_CODES.O)
  fun canal(contexto: Context): NotificationChannel =
    NotificationChannel(CANAL, contexto.getString(R.string.canal_alarme_nome), NotificationManager.IMPORTANCE_HIGH).apply {
      description = contexto.getString(R.string.canal_alarme_descricao)
      setSound(
        Uri.parse("android.resource://${contexto.packageName}/${R.raw.novo_pedido}"),
        AudioAttributes.Builder()
          .setUsage(AudioAttributes.USAGE_ALARM)
          .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
          .build()
      )
      enableVibration(true)
      vibrationPattern = longArrayOf(0, 800, 600, 800, 600, 800)
      enableLights(true)
      lockscreenVisibility = Notification.VISIBILITY_PUBLIC
    }

  /** Mostra o alarme do pedido; devolve o id da notificação. */
  fun disparar(contexto: Context, props: PushNotificationProps): Int {
    val dados = props.asBundle()
    val id = ("alarme:" + (dados.getString("id") ?: "")).hashCode()
    val titulo = props.title ?: contexto.getString(R.string.alarme_titulo_padrao)
    val texto = props.body ?: ""
    val flags = PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE

    val telaCheia = Intent(contexto, AlarmePedidoActivity::class.java).apply {
      addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_NO_USER_ACTION)
      putExtra(EXTRA_TITULO, titulo)
      putExtra(EXTRA_TEXTO, texto)
      putExtra(EXTRA_DADOS, dados)
    }
    val silenciar = PendingIntent.getBroadcast(contexto, id, Intent(contexto, SilenciarAlarmeReceiver::class.java), flags)

    val construtor = NotificationCompat.Builder(contexto, CANAL)
      .setSmallIcon(R.drawable.ic_notification)
      .setContentTitle(titulo)
      .setContentText(texto)
      .setStyle(NotificationCompat.BigTextStyle().bigText(texto))
      .setCategory(NotificationCompat.CATEGORY_ALARM)
      .setPriority(NotificationCompat.PRIORITY_MAX)
      .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
      .setAutoCancel(true)
      .setTimeoutAfter(DURACAO_MS)
      .setFullScreenIntent(PendingIntent.getActivity(contexto, id, telaCheia, flags), true)
      .setDeleteIntent(silenciar)
      .addAction(0, contexto.getString(R.string.alarme_silenciar), silenciar)
    intentDoPedido(contexto, dados)?.let { construtor.setContentIntent(PendingIntent.getActivity(contexto, id + 1, it, flags)) }

    val notificacao = construtor.build()
    notificacao.flags = notificacao.flags or Notification.FLAG_INSISTENT

    guardarPendente(contexto, dados)
    contexto.getSystemService(NotificationManager::class.java)?.notify(TAG, id, notificacao)
    return id
  }

  /** O intent que abre o app no pedido: os dados do push como extras (com google.message_id), como no push comum. */
  fun intentDoPedido(contexto: Context, dados: Bundle): Intent? =
    contexto.packageManager.getLaunchIntentForPackage(contexto.packageName)?.apply {
      addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
      putExtras(dados)
    }

  /** Para o alarme: tira as notificações de alarme, fecha a tela cheia e apaga o pendente. */
  fun cancelarTodos(contexto: Context) {
    contexto.getSystemService(NotificationManager::class.java)?.let { gerenciador ->
      gerenciador.activeNotifications.filter { it.tag == TAG }.forEach { gerenciador.cancel(TAG, it.id) }
    }
    esquecerPendente(contexto)
    tela?.get()?.finish()
    tela = null
  }

  fun registrarTela(atividade: Activity) {
    tela = WeakReference(atividade)
  }

  fun esquecerTela(atividade: Activity) {
    if (tela?.get() === atividade) tela = null
  }

  fun esquecerPendente(contexto: Context) {
    preferencias(contexto).edit().clear().apply()
  }

  /** O alarme pendente (até 3 min), uma vez só: lê, apaga o pendente e tira as notificações de alarme. */
  fun consumirPendente(contexto: Context): Bundle? {
    val prefs = preferencias(contexto)
    val json = prefs.getString(CHAVE_DADOS, null) ?: return null
    val quando = prefs.getLong(CHAVE_QUANDO, 0L)
    cancelarTodos(contexto)
    if (System.currentTimeMillis() - quando > DURACAO_MS) return null
    return try {
      val objeto = JSONObject(json)
      Bundle().apply { objeto.keys().forEach { chave -> putString(chave, objeto.optString(chave)) } }
    } catch (_: Exception) {
      null
    }
  }

  private fun guardarPendente(contexto: Context, dados: Bundle) {
    val objeto = JSONObject()
    @Suppress("DEPRECATION")
    dados.keySet().forEach { chave -> dados.get(chave)?.let { objeto.put(chave, it.toString()) } }
    preferencias(contexto).edit()
      .putString(CHAVE_DADOS, objeto.toString())
      .putLong(CHAVE_QUANDO, System.currentTimeMillis())
      .apply()
  }

  private fun preferencias(contexto: Context) = contexto.getSharedPreferences(PREFERENCIAS, Context.MODE_PRIVATE)
}
```

- [ ] **Step 2: `SilenciarAlarmeReceiver.kt`**

```kotlin
package io.fleetbase.navigator

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/** Entregas: botão "Silenciar" e arrastar para o lado na notificação de alarme de pedido. */
class SilenciarAlarmeReceiver : BroadcastReceiver() {
  override fun onReceive(contexto: Context, intent: Intent) {
    AlarmeDePedido.cancelarTodos(contexto)
  }
}
```

- [ ] **Step 3: Manifest**

Em `AndroidManifest.xml`, logo depois de `<uses-permission android:name="android.permission.POST_NOTIFICATIONS" />`:

```xml
  <!-- Entregas: alarme de pedido em tela cheia e verificação da economia de bateria (AlarmeDePedido, AlertaPedido) -->
  <uses-permission android:name="android.permission.USE_FULL_SCREEN_INTENT" />
  <uses-permission android:name="android.permission.REQUEST_IGNORE_BATTERY_OPTIMIZATIONS" />
```

E dentro de `<application>`, logo depois do `</activity>` da `MainActivity`:

```xml
    <!-- Entregas: alarme de pedido em tela cheia sobre a tela de bloqueio e o "Silenciar" (AlarmeDePedido) -->
    <activity android:name=".AlarmePedidoActivity"
      android:exported="false"
      android:showWhenLocked="true"
      android:turnScreenOn="true"
      android:excludeFromRecents="true"
      android:launchMode="singleInstance"
      android:taskAffinity="${applicationId}.alarme"
      android:theme="@android:style/Theme.DeviceDefault.NoActionBar" />
    <receiver android:name=".SilenciarAlarmeReceiver" android:exported="false" />
```

- [ ] **Step 4: Commit**

```bash
git -C "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" add android/app/src/main/java/io/fleetbase/navigator/AlarmeDePedido.kt android/app/src/main/java/io/fleetbase/navigator/SilenciarAlarmeReceiver.kt android/app/src/main/AndroidManifest.xml
git -C "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" commit -m "Alarme de pedido fora da frente: notificação insistente no canal de alarme e Silenciar"
```

---

### Task 10: `AlarmePedidoActivity` (tela cheia)

**Files:**
- Create: `android/app/src/main/java/io/fleetbase/navigator/AlarmePedidoActivity.kt`

- [ ] **Step 1: A activity**

```kotlin
package io.fleetbase.navigator

import android.app.Activity
import android.app.KeyguardManager
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.util.TypedValue
import android.view.Gravity
import android.view.ViewGroup
import android.view.WindowManager
import android.widget.Button
import android.widget.LinearLayout
import android.widget.TextView

/**
 * Entregas: alarme de pedido em tela cheia sobre a tela de bloqueio (o full-screen intent do AlarmeDePedido).
 * "Ver pedido" pede o desbloqueio e abre o app no pedido; "Silenciar" (ou voltar) para o alarme. Exigir o desbloqueio
 * impede usar o app pela tela de bloqueio. Fecha sozinha quando o alarme acaba.
 */
class AlarmePedidoActivity : Activity() {

  private val handler = Handler(Looper.getMainLooper())

  override fun onCreate(savedInstanceState: Bundle?) {
    super.onCreate(savedInstanceState)
    aparecerSobreOBloqueio()
    AlarmeDePedido.registrarTela(this)
    handler.postDelayed({ finish() }, AlarmeDePedido.DURACAO_MS)

    val titulo = intent.getStringExtra(AlarmeDePedido.EXTRA_TITULO) ?: getString(R.string.alarme_titulo_padrao)
    val texto = intent.getStringExtra(AlarmeDePedido.EXTRA_TEXTO) ?: ""
    setContentView(montarTela(titulo, texto))
  }

  private fun aparecerSobreOBloqueio() {
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
      setShowWhenLocked(true)
      setTurnScreenOn(true)
    } else {
      @Suppress("DEPRECATION")
      window.addFlags(WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED or WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON)
    }
    window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
  }

  private fun verPedido() {
    val abrir = Runnable {
      val dados = intent.getBundleExtra(AlarmeDePedido.EXTRA_DADOS)
      AlarmeDePedido.cancelarTodos(this)
      dados?.let { AlarmeDePedido.intentDoPedido(this, it) }?.let { startActivity(it) }
      finish()
    }
    val bloqueio = getSystemService(KeyguardManager::class.java)
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && bloqueio != null && bloqueio.isKeyguardLocked) {
      bloqueio.requestDismissKeyguard(this, object : KeyguardManager.KeyguardDismissCallback() {
        override fun onDismissSucceeded() = abrir.run()
      })
    } else {
      abrir.run()
    }
  }

  private fun silenciar() {
    AlarmeDePedido.cancelarTodos(this)
    finish()
  }

  @Deprecated("Deprecated in Java")
  @Suppress("DEPRECATION")
  override fun onBackPressed() {
    silenciar()
  }

  override fun onDestroy() {
    handler.removeCallbacksAndMessages(null)
    AlarmeDePedido.esquecerTela(this)
    super.onDestroy()
  }

  private fun montarTela(titulo: String, texto: String): LinearLayout =
    LinearLayout(this).apply {
      orientation = LinearLayout.VERTICAL
      gravity = Gravity.CENTER
      setBackgroundColor(Color.parseColor("#111827"))
      setPadding(dp(32), dp(48), dp(32), dp(48))
      addView(rotulo(titulo, 30f, Color.WHITE, negrito = true))
      addView(rotulo(texto, 18f, Color.parseColor("#D1D5DB"), negrito = false).apply { setPadding(0, dp(16), 0, dp(48)) })
      addView(botao(getString(R.string.alarme_ver_pedido), "#16A34A") { verPedido() })
      addView(botao(getString(R.string.alarme_silenciar), "#374151") { silenciar() }.apply {
        (layoutParams as LinearLayout.LayoutParams).topMargin = dp(16)
      })
    }

  private fun rotulo(conteudo: String, tamanhoSp: Float, cor: Int, negrito: Boolean) =
    TextView(this).apply {
      text = conteudo
      setTextColor(cor)
      setTextSize(TypedValue.COMPLEX_UNIT_SP, tamanhoSp)
      gravity = Gravity.CENTER
      if (negrito) setTypeface(typeface, Typeface.BOLD)
    }

  private fun botao(conteudo: String, cor: String, aoTocar: () -> Unit) =
    Button(this).apply {
      text = conteudo
      setTextColor(Color.WHITE)
      setTextSize(TypedValue.COMPLEX_UNIT_SP, 20f)
      isAllCaps = false
      background = GradientDrawable().apply {
        setColor(Color.parseColor(cor))
        cornerRadius = dp(12).toFloat()
      }
      layoutParams = LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(64))
      setOnClickListener { aoTocar() }
    }

  private fun dp(valor: Int): Int = (valor * resources.displayMetrics.density).toInt()
}
```

- [ ] **Step 2: Commit**

```bash
git -C "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" add android/app/src/main/java/io/fleetbase/navigator/AlarmePedidoActivity.kt
git -C "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" commit -m "Alarme de pedido em tela cheia sobre a tela de bloqueio (Ver pedido / Silenciar)"
```

---

### Task 11: `PushDoEntregas` e o `MainApplication` como `INotificationsApplication`

**Files:**
- Create: `android/app/src/main/java/io/fleetbase/navigator/PushDoEntregas.kt`
- Modify: `android/app/src/main/java/io/fleetbase/navigator/MainApplication.kt`

- [ ] **Step 1: `PushDoEntregas.kt`**

```kotlin
package io.fleetbase.navigator

import android.content.Context
import android.os.Bundle
import com.wix.reactnativenotifications.core.AppLaunchHelper
import com.wix.reactnativenotifications.core.AppLifecycleFacade
import com.wix.reactnativenotifications.core.JsIOHelper
import com.wix.reactnativenotifications.core.notification.PushNotification

/**
 * Entregas: cada push que chega pela react-native-notifications (MainApplication.getPushNotification). Com o app fora
 * da frente, o push de dados de alarme (pedido novo, reenvio, atribuído, liberado) vira o alarme do AlarmeDePedido; o
 * resto segue como a biblioteca faz. Com o app na frente, a biblioteca entrega ao JS (DriverLayout).
 */
class PushDoEntregas(
  contexto: Context,
  dados: Bundle,
  ciclo: AppLifecycleFacade,
  abertura: AppLaunchHelper,
  js: JsIOHelper,
) : PushNotification(contexto, dados, ciclo, abertura, js) {

  // a biblioteca só chama isto com o app fora da frente
  override fun postNotification(notificationId: Int?): Int {
    val tipo = mNotificationProps.asBundle().getString("type")
    if (AlarmeDePedido.ehAlarme(tipo) && !mNotificationProps.isDataOnlyPushNotification) {
      return AlarmeDePedido.disparar(mContext, mNotificationProps)
    }
    return super.postNotification(notificationId)
  }

  // o motoboy tocou numa notificação: quem abre o pedido é o evento "opened" no JS, não o alarme pendente
  override fun onOpened() {
    AlarmeDePedido.esquecerPendente(mContext)
    super.onOpened()
  }
}
```

- [ ] **Step 2: `MainApplication` implementa `INotificationsApplication`**

Em `MainApplication.kt`:
1. Imports: acrescente
```kotlin
import android.content.Context
import android.os.Bundle
import com.wix.reactnativenotifications.core.AppLaunchHelper
import com.wix.reactnativenotifications.core.AppLifecycleFacade
import com.wix.reactnativenotifications.core.JsIOHelper
import com.wix.reactnativenotifications.core.notification.INotificationsApplication
import com.wix.reactnativenotifications.core.notification.IPushNotification
```
2. Troque `class MainApplication : Application(), ReactApplication {` por `class MainApplication : Application(), ReactApplication, INotificationsApplication {`.
3. Depois do `onCreate()`, acrescente:
```kotlin

  /** Entregas: todo push da react-native-notifications passa pelo PushDoEntregas (alarme de pedido fora da frente). */
  override fun getPushNotification(
    context: Context,
    bundle: Bundle,
    facade: AppLifecycleFacade,
    defaultAppLaunchHelper: AppLaunchHelper,
  ): IPushNotification = PushDoEntregas(context, bundle, facade, defaultAppLaunchHelper, JsIOHelper())
```

- [ ] **Step 3: Commit**

```bash
git -C "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" add android/app/src/main/java/io/fleetbase/navigator/PushDoEntregas.kt android/app/src/main/java/io/fleetbase/navigator/MainApplication.kt
git -C "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" commit -m "Push de dados de alarme vira o alarme nativo com o app fora da frente (PushDoEntregas)"
```

---

### Task 12: `AlertaPedidoModule` — cancela o alarme, verificação e pendente

**Files:**
- Modify: `android/app/src/main/java/io/fleetbase/navigator/AlertaPedidoModule.kt`

- [ ] **Step 1: Imports**

Acrescente aos imports:

```kotlin
import android.app.NotificationManager
import android.content.Intent
import android.media.AudioManager
import android.os.PowerManager
import android.provider.Settings
import androidx.core.app.NotificationManagerCompat
import com.facebook.react.bridge.Arguments
import com.facebook.react.bridge.Promise
```

- [ ] **Step 2: `iniciar()` cancela o alarme da notificação**

No começo de `fun iniciar() {`, antes de `handler.post {`, acrescente:

```kotlin
    // a tela do pedido assumiu o som: tira o alarme da notificação (sem som duplicado)
    AlarmeDePedido.cancelarTodos(contexto)
```

- [ ] **Step 3: Métodos novos**

Depois de `fun parar() { ... }` (antes do `override fun invalidate()`), acrescente:

```kotlin
  /** O que pode impedir o alarme de pedido (verificação ao abrir o app, useVerificacaoDoAlarme). */
  @ReactMethod
  fun verificar(promise: Promise) {
    val notificacoes = contexto.getSystemService(NotificationManager::class.java)
    val audio = contexto.getSystemService(AudioManager::class.java)
    val energia = contexto.getSystemService(PowerManager::class.java)
    val canalAlarme =
      if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
        notificacoes?.getNotificationChannel(AlarmeDePedido.CANAL)?.importance != NotificationManager.IMPORTANCE_NONE
      } else {
        true
      }
    val telaCheia =
      if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) notificacoes?.canUseFullScreenIntent() != false else true
    promise.resolve(
      Arguments.createMap().apply {
        putBoolean("notificacoes", NotificationManagerCompat.from(contexto).areNotificationsEnabled())
        putBoolean("canalAlarme", canalAlarme)
        putBoolean("telaCheia", telaCheia)
        putBoolean("volumeAlarme", (audio?.getStreamVolume(AudioManager.STREAM_ALARM) ?: 1) > 0)
        putBoolean("bateria", energia?.isIgnoringBatteryOptimizations(contexto.packageName) != false)
      }
    )
  }

  /** Resolve um item da verificação: abre a configuração certa ou sobe o volume do alarme para 80%. */
  @ReactMethod
  fun resolver(item: String) {
    val pacote = contexto.packageName
    if (item == "volumeAlarme") {
      contexto.getSystemService(AudioManager::class.java)?.let {
        val maximo = it.getStreamMaxVolume(AudioManager.STREAM_ALARM)
        it.setStreamVolume(AudioManager.STREAM_ALARM, Math.ceil(maximo * 0.8).toInt(), AudioManager.FLAG_SHOW_UI)
      }
      return
    }
    val detalhes = Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:$pacote"))
    val intent = when {
      item == "notificacoes" && Build.VERSION.SDK_INT >= Build.VERSION_CODES.O ->
        Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE, pacote)
      item == "canalAlarme" && Build.VERSION.SDK_INT >= Build.VERSION_CODES.O ->
        Intent(Settings.ACTION_CHANNEL_NOTIFICATION_SETTINGS)
          .putExtra(Settings.EXTRA_APP_PACKAGE, pacote)
          .putExtra(Settings.EXTRA_CHANNEL_ID, AlarmeDePedido.CANAL)
      item == "telaCheia" && Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE ->
        Intent(Settings.ACTION_MANAGE_APP_USE_FULL_SCREEN_INTENT, Uri.parse("package:$pacote"))
      item == "bateria" -> Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:$pacote"))
      else -> detalhes
    }
    try {
      contexto.startActivity(intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    } catch (_: Exception) {
      contexto.startActivity(detalhes.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    }
  }

  /** O alarme que tocou com o app fora da frente, para o DriverLayout abrir o pedido (uma vez só). */
  @ReactMethod
  fun consumirPendente(promise: Promise) {
    promise.resolve(AlarmeDePedido.consumirPendente(contexto)?.let { Arguments.fromBundle(it) })
  }
```

E atualize o docblock da classe para:

```kotlin
/**
 * Entregas: toca o alarme de "novo pedido" em loop (e vibra) até o JS chamar parar()
 * — o motoboy aceitou, recusou ou saiu da tela do pedido. Para sozinho após 3 minutos.
 * Usa o fluxo de ALARME, que toca mesmo com o celular no silencioso. Também verifica o que pode
 * impedir o alarme (verificar/resolver) e entrega ao JS o alarme que tocou fora da frente (consumirPendente).
 */
```

- [ ] **Step 4: Commit**

```bash
git -C "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" add android/app/src/main/java/io/fleetbase/navigator/AlertaPedidoModule.kt
git -C "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" commit -m "AlertaPedido: tira o alarme da notificação ao abrir o pedido; verificação do alarme e alarme pendente"
```

---

## Fase 3 — App (JS)

### Task 13: `alerta-pedido.ts`

**Files:**
- Modify: `src/utils/alerta-pedido.ts`

- [ ] **Step 1: Ferramenta de sintaxe (uma vez)**

```bash
F=/c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/a6f158cb-f434-432c-85c6-a9dfe4b9f49e/scratchpad/ferramentas
mkdir -p "$F" && npm i --prefix "$F" --no-audit --no-fund @babel/parser@7 >/dev/null 2>&1 && cat > "$F/sintaxe-ts.cjs" <<'EOF'
// Uso: node sintaxe-ts.cjs arquivo.ts [arquivo.tsx...] — só sintaxe (TS/TSX), sai com 1 se algum não parsear
const fs = require('fs');
const { parse } = require('./node_modules/@babel/parser');
let falhou = false;
for (const arquivo of process.argv.slice(2)) {
    try {
        parse(fs.readFileSync(arquivo, 'utf8'), { sourceType: 'module', plugins: ['typescript', 'jsx'] });
        console.log('ok   ', arquivo);
    } catch (e) {
        falhou = true;
        console.log('ERRO ', arquivo, '-', e.message);
    }
}
process.exit(falhou ? 1 : 0);
EOF
```

- [ ] **Step 2: Substituir o arquivo**

`src/utils/alerta-pedido.ts`:

```ts
import { NativeModules, Platform } from 'react-native';

// Entregas: alarme de "novo pedido" em loop, tocado pelo módulo nativo AlertaPedido (Android).
// Para sozinho após 3 minutos; no iOS não faz nada.
const modulo = Platform.OS === 'android' ? NativeModules.AlertaPedido : null;

export const iniciarAlertaPedido = () => modulo?.iniciar?.();

export const pararAlertaPedido = () => modulo?.parar?.();

// O que pode impedir o alarme de pedido, na ordem em que a verificação ao abrir o app avisa.
export const ITENS_DO_ALARME = ['notificacoes', 'canalAlarme', 'telaCheia', 'volumeAlarme', 'bateria'] as const;
export type ItemDoAlarme = (typeof ITENS_DO_ALARME)[number];
export type VerificacaoDoAlarme = Record<ItemDoAlarme, boolean>;

export const verificarAlarme = async (): Promise<VerificacaoDoAlarme | null> => (modulo?.verificar ? modulo.verificar() : null);

export const primeiroProblemaDoAlarme = (verificacao: VerificacaoDoAlarme | null): ItemDoAlarme | null =>
    ITENS_DO_ALARME.find((item) => verificacao?.[item] === false) ?? null;

export const resolverProblemaDoAlarme = (item: ItemDoAlarme) => modulo?.resolver?.(item);

// O alarme que tocou com o app fora da frente (os dados do push), uma vez só; null se não há.
export const consumirAlarmePendente = async (): Promise<Record<string, string> | null> =>
    modulo?.consumirPendente ? ((await modulo.consumirPendente()) ?? null) : null;
```

- [ ] **Step 3: Sintaxe**

```bash
node /c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/a6f158cb-f434-432c-85c6-a9dfe4b9f49e/scratchpad/ferramentas/sintaxe-ts.cjs "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator/src/utils/alerta-pedido.ts"
```

Esperado: `ok`.

- [ ] **Step 4: Commit**

```bash
git -C "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" add src/utils/alerta-pedido.ts
git -C "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" commit -m "alerta-pedido: verificação do alarme e alarme pendente no JS"
```

---

### Task 14: Push inicial e alarme pendente abrem o pedido

**Files:**
- Modify: `src/contexts/NotificationContext.tsx`
- Modify: `src/layouts/DriverLayout.tsx`

- [ ] **Step 1: `consumirNotificacaoInicial` no contexto**

Em `NotificationContext.tsx`, depois da função `removeNotificationListener` (antes do `useEffect`):

```tsx
    // Entregas: o push que abriu o app fechado (a biblioteca guarda como "push inicial"); lido uma vez pelo DriverLayout
    const consumirNotificacaoInicial = async () => {
        try {
            return (await Notifications.getInitialNotification()) ?? null;
        } catch (erro) {
            return null;
        }
    };
```

E troque o `value` do provider:

```tsx
        <NotificationContext.Provider value={{ notifications, lastNotification, deviceToken, addNotificationListener, removeNotificationListener, consumirNotificacaoInicial }}>{children}</NotificationContext.Provider>
```

- [ ] **Step 2: `DriverLayout` abre os pendentes**

Em `DriverLayout.tsx`:
1. Troque `import { useEffect } from 'react';` por `import { useEffect, useRef } from 'react';` e `import { View } from 'react-native';` por `import { AppState, View } from 'react-native';`.
2. Depois de `import useFleetbase from '../hooks/use-fleetbase';`, acrescente:
```tsx
import { consumirAlarmePendente } from '../utils/alerta-pedido';
```
3. Troque `const { addNotificationListener, removeNotificationListener } = useNotification();` por:
```tsx
    const { addNotificationListener, removeNotificationListener, consumirNotificacaoInicial } = useNotification();
```
4. Depois de `const { reloadActiveOrders, reloadNearbyOrders } = useOrderManager();`, acrescente:
```tsx
    const inicialLidaRef = useRef(false);
```
5. No `useEffect`, troque o trecho final

```tsx
        addNotificationListener(handlePushNotification);

        return () => {
            removeNotificationListener(handlePushNotification);
        };
    }, [addNotificationListener, removeNotificationListener, fleetbase, tabNavigation, navigation]);
```

por

```tsx
        // Entregas: o push que abriu o app fechado (lido uma vez) e o alarme que tocou com o app fora da frente
        // (voltando ao app pelo ícone ou desbloqueando) abrem o pedido como se o motoboy tivesse tocado no push
        const abrirAvisosPendentes = async () => {
            if (AppState.currentState !== 'active') return;
            if (!inicialLidaRef.current) {
                inicialLidaRef.current = true;
                const inicial = await consumirNotificacaoInicial();
                if (inicial) {
                    handlePushNotification(inicial, 'opened');
                    return;
                }
            }
            const alarme = await consumirAlarmePendente();
            if (alarme) {
                handlePushNotification({ payload: alarme }, 'opened');
            }
        };

        addNotificationListener(handlePushNotification);
        abrirAvisosPendentes();
        const assinatura = AppState.addEventListener('change', (estado) => {
            if (estado === 'active') abrirAvisosPendentes();
        });

        return () => {
            removeNotificationListener(handlePushNotification);
            assinatura.remove();
        };
    }, [addNotificationListener, removeNotificationListener, consumirNotificacaoInicial, fleetbase, tabNavigation, navigation]);
```

- [ ] **Step 3: Sintaxe**

```bash
F=/c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/a6f158cb-f434-432c-85c6-a9dfe4b9f49e/scratchpad/ferramentas
N="/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator"
node $F/sintaxe-ts.cjs "$N/src/contexts/NotificationContext.tsx" "$N/src/layouts/DriverLayout.tsx"
```

Esperado: dois `ok`.

- [ ] **Step 4: Commit**

```bash
git -C "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" add src/contexts/NotificationContext.tsx src/layouts/DriverLayout.tsx
git -C "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" commit -m "Push que abre o app fechado e alarme pendente abrem o pedido"
```

---

### Task 15: Verificação do alarme ao abrir o app

**Files:**
- Create: `src/hooks/use-verificacao-do-alarme.ts`
- Modify: `src/layouts/DriverLayout.tsx`
- Modify: `translations/pt.json`, `translations/en.json`

- [ ] **Step 1: O hook**

`src/hooks/use-verificacao-do-alarme.ts`:

```ts
import { useEffect, useRef } from 'react';
import { Alert, AppState } from 'react-native';
import { useLanguage } from '../contexts/LanguageContext';
import { verificarAlarme, primeiroProblemaDoAlarme, resolverProblemaDoAlarme } from '../utils/alerta-pedido';

// Entregas: ao abrir o app e ao voltar para ele, avisa o primeiro problema que impede o alarme de pedido (notificações,
// canal de alarme, tela cheia, volume, bateria) e oferece o botão que resolve. "Agora não" vale até o app ser aberto
// de novo. A primeira verificação espera uns segundos para não disputar a tela com o pedido de permissão do Android.
const useVerificacaoDoAlarme = () => {
    const { t } = useLanguage();
    const tRef = useRef(t);
    tRef.current = t;
    const adiadoRef = useRef(false);
    const mostrandoRef = useRef(false);

    useEffect(() => {
        const verificar = async () => {
            if (adiadoRef.current || mostrandoRef.current) return;
            const problema = primeiroProblemaDoAlarme(await verificarAlarme());
            if (!problema) return;

            const traduzir = tRef.current;
            mostrandoRef.current = true;
            Alert.alert(
                traduzir(`AlarmeDePedido.${problema}.titulo`),
                traduzir(`AlarmeDePedido.${problema}.texto`),
                [
                    {
                        text: traduzir('AlarmeDePedido.agoraNao'),
                        style: 'cancel',
                        onPress: () => {
                            adiadoRef.current = true;
                            mostrandoRef.current = false;
                        },
                    },
                    {
                        text: traduzir(`AlarmeDePedido.${problema}.botao`),
                        onPress: () => {
                            mostrandoRef.current = false;
                            resolverProblemaDoAlarme(problema);
                        },
                    },
                ],
                { cancelable: false }
            );
        };

        const primeira = setTimeout(verificar, 3000);
        const assinatura = AppState.addEventListener('change', (estado) => {
            if (estado === 'active') verificar();
        });
        return () => {
            clearTimeout(primeira);
            assinatura.remove();
        };
    }, []);
};

export default useVerificacaoDoAlarme;
```

- [ ] **Step 2: Usar no `DriverLayout`**

Em `DriverLayout.tsx`:
1. Depois de `import { consumirAlarmePendente } from '../utils/alerta-pedido';`, acrescente `import useVerificacaoDoAlarme from '../hooks/use-verificacao-do-alarme';`.
2. Depois de `const inicialLidaRef = useRef(false);`, acrescente `useVerificacaoDoAlarme();`.

- [ ] **Step 3: Traduções (script que preserva o formato dos JSON: 4 espaços, CRLF)**

```bash
N="/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator"
node -e '
const fs = require("fs");
const textos = {
    pt: {
        LocationContext: { notificationTitle: "Entregas", notificationText: "Localização ativa para receber pedidos próximos", notificationChannel: "Localização" },
        AlarmeDePedido: {
            agoraNao: "Agora não",
            notificacoes: { titulo: "Notificações desligadas", texto: "Sem notificações, você não recebe o alarme de novos pedidos.", botao: "Ativar notificações" },
            canalAlarme: { titulo: "Alarme de pedidos desligado", texto: "O alarme de novo pedido está desativado nas notificações do app. Ative para ouvir os novos pedidos.", botao: "Ativar alarme" },
            telaCheia: { titulo: "Alarme em tela cheia", texto: "Permita as notificações em tela cheia para o alarme acender a tela com o celular bloqueado.", botao: "Permitir" },
            volumeAlarme: { titulo: "Volume do alarme zerado", texto: "Com o volume de alarme no zero, o alarme de novos pedidos não toca.", botao: "Aumentar volume" },
            bateria: { titulo: "Economia de bateria", texto: "Libere o Entregas da economia de bateria para receber pedidos com o app fechado.", botao: "Liberar" },
        },
    },
    en: {
        LocationContext: { notificationTitle: "Entregas", notificationText: "Location active to receive nearby orders", notificationChannel: "Location" },
        AlarmeDePedido: {
            agoraNao: "Not now",
            notificacoes: { titulo: "Notifications off", texto: "Without notifications you will not get the new order alarm.", botao: "Turn on notifications" },
            canalAlarme: { titulo: "Order alarm off", texto: "The new order alarm is turned off in the app notifications. Turn it on to hear new orders.", botao: "Turn on alarm" },
            telaCheia: { titulo: "Full screen alarm", texto: "Allow full screen notifications so the alarm can wake the screen when the phone is locked.", botao: "Allow" },
            volumeAlarme: { titulo: "Alarm volume is zero", texto: "With the alarm volume at zero, the new order alarm does not ring.", botao: "Raise volume" },
            bateria: { titulo: "Battery saver", texto: "Exempt Entregas from battery saving to get orders with the app closed.", botao: "Exempt" },
        },
    },
};
for (const idioma of ["pt", "en"]) {
    const caminho = process.argv[1] + "/translations/" + idioma + ".json";
    const json = JSON.parse(fs.readFileSync(caminho, "utf8"));
    Object.assign(json.LocationContext, textos[idioma].LocationContext);
    json.AlarmeDePedido = textos[idioma].AlarmeDePedido;
    fs.writeFileSync(caminho, (JSON.stringify(json, null, 4) + "\n").replace(/\n/g, "\r\n"));
    console.log("ok", idioma);
}
' "$N"
git -C "$N" diff --stat -- translations
```

Esperado: `ok pt`, `ok en`, e o diff só com as linhas novas (cerca de +30 por arquivo).

- [ ] **Step 4: Sintaxe**

```bash
F=/c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/a6f158cb-f434-432c-85c6-a9dfe4b9f49e/scratchpad/ferramentas
N="/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator"
node $F/sintaxe-ts.cjs "$N/src/hooks/use-verificacao-do-alarme.ts" "$N/src/layouts/DriverLayout.tsx"
```

Esperado: dois `ok`.

- [ ] **Step 5: Commit**

```bash
git -C "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" add src/hooks/use-verificacao-do-alarme.ts src/layouts/DriverLayout.tsx translations/pt.json translations/en.json
git -C "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" commit -m "Verificação do alarme ao abrir o app (notificações, tela cheia, volume, bateria)"
```

---

### Task 16: Notificação fixa do rastreamento em pt-BR

**Files:**
- Modify: `src/contexts/LocationContext.tsx`

- [ ] **Step 1: Configurar a notificação**

No `BackgroundGeolocation.ready({ ... })`, logo depois do bloco `backgroundPermissionRationale: { ... },`, acrescente:

```tsx
                // Entregas: notificação fixa do rastreamento em pt-BR (a da biblioteca vem em inglês)
                notification: {
                    title: translate('LocationContext.notificationTitle'),
                    text: translate('LocationContext.notificationText'),
                    channelName: translate('LocationContext.notificationChannel'),
                },
```

- [ ] **Step 2: Sintaxe**

```bash
node /c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/a6f158cb-f434-432c-85c6-a9dfe4b9f49e/scratchpad/ferramentas/sintaxe-ts.cjs "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator/src/contexts/LocationContext.tsx"
```

Esperado: `ok`.

- [ ] **Step 3: Commit**

```bash
git -C "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" add src/contexts/LocationContext.tsx
git -C "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" commit -m "Notificação fixa do rastreamento de localização em pt-BR"
```

---

## Fase 4 — Build, implantação e teste

### Task 17: Push do app e APK 16 no CI

- [ ] **Step 1: Revisão antes do push (sem compilador local)**

Confira, arquivo por arquivo, contra este plano: imports usados existem, nomes iguais entre arquivos (`AlarmeDePedido.CANAL`, `EXTRA_DADOS`, `intentDoPedido`, `cancelarTodos`, `consumirPendente`, `esquecerPendente`, `registrarTela`, `esquecerTela`, `ehAlarme`, `disparar`, `canal`), e que `R.string.*` usados estão no `entregas_strings.xml`.

- [ ] **Step 2: Pedir aprovação ao Edgard para o push** (gera o build no CI). Com o "sim":

```bash
git -C "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" push origin main
```

- [ ] **Step 3: Acompanhar o build**

```bash
gh run list -R edgardjnr/entregas-navigator --limit 3
gh run watch -R edgardjnr/entregas-navigator <id-do-run> --exit-status
```

Se falhar, `gh run view -R edgardjnr/entregas-navigator <id> --log-failed | tail -80`, corrija o erro de compilação (commit novo) e faça push de novo (pedir aprovação de novo só se o Edgard pediu para aprovar cada push).

- [ ] **Step 4: Baixar o APK para a área de trabalho**

```bash
gh run download -R edgardjnr/entregas-navigator <id-do-run> -D /c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/a6f158cb-f434-432c-85c6-a9dfe4b9f49e/scratchpad/apk16
cp /c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/a6f158cb-f434-432c-85c6-a9dfe4b9f49e/scratchpad/apk16/*/*.apk "/c/Users/Edgardjr/Desktop/entregas-motoboy-16.apk"
ls -la "/c/Users/Edgardjr/Desktop/entregas-motoboy-16.apk"
```

### Task 18: Push do servidor e deploy (chave desligada)

- [ ] **Step 1: Rodar todos os testes PHP e a sintaxe**

```bash
W=/c/tmp/em; P=/c/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/a6f158cb-f434-432c-85c6-a9dfe4b9f49e/scratchpad
PHP_WASM_DIR=$P/teste-reenvio node $W/scripts/teste-php/rodar.mjs $W/scripts/teste-php/reenvio.php | tail -1
PHP_WASM_DIR=$P/teste-reenvio node $W/scripts/teste-php/rodar.mjs $W/scripts/teste-php/avisos-push.php | tail -1
PHP_PARSER_DIR=$P/php-lint node $W/scripts/php-lint.cjs $W/api/app/Notifications/Entregas/*.php $W/api/app/Console/Commands/Entregas/*.php $W/api/app/Providers/AppServiceProvider.php $W/api/app/Support/Entregas/StatusDoPedido.php
```

Esperado: `FALHAS: 0` duas vezes e todos `ok`.

- [ ] **Step 2: Trazer a main e pedir aprovação do push**

```bash
git -C /c/tmp/em fetch origin main
git -C /c/tmp/em rebase origin/main
git -C /c/tmp/em log --oneline origin/main..HEAD
```

Com o "sim" do Edgard: `git -C /c/tmp/em push origin HEAD:main`.

- [ ] **Step 3: Combinar o deploy**

Perguntar ao Edgard se o portal da loja já está em produção: o `atualizar.sh api` leva tudo o que está na `main`. Comandos para ele na VPS:

```bash
cd ~/entregas && bash deploy/atualizar.sh api
docker exec $(docker ps -q -f name=entregas_queue) printenv ENTREGAS_ALARME_POR_DADOS || echo "chave desligada (sem a variável)"
docker exec $(docker ps -q -f name=entregas_queue) php /fleetbase/api/artisan tinker --execute="echo get_class(app(\NotificationChannels\Fcm\FcmChannel::class)), PHP_EOL;"
```

Esperado na última linha: `App\Notifications\Entregas\CanalFcmEntregas` (o canal novo está no lugar do `FcmChannel` na fila, que é quem envia os push). Se aparecer `NotificationChannels\Fcm\FcmChannel`, a fila ainda roda a imagem antiga.

### Task 19: Compatibilidade com o APK 15 (chave desligada)

Com o APK 15 no celular do Motoca, guiar o Edgard:
- [ ] App fechado: criar pedido de teste perto do Motoca (portal ou console). Esperado: "Novo pedido disponível / Coleta a … de você." com o toque longo; tocar abre o pedido.
- [ ] Mensagem no chat (console). Esperado: "Mensagem de …", toque longo.
- [ ] Cancelar o pedido de teste. Esperado: "Pedido … cancelado".

### Task 20: APK 16 com a chave desligada

O Edgard instala `entregas-motoboy-16.apk` por cima do 15.
- [ ] Ao abrir: a verificação pode pedir bateria/tela cheia; seguir os botões. Esperado: depois de resolver, não aparece mais.
- [ ] Celular no silencioso, app fechado: pedido de teste. Esperado: som de alarme (uma vez), "Novo pedido disponível"; tocar abre o pedido direto (app estava fechado).
- [ ] Chat: toque longo no canal "Mensagens". Cancelamento: som normal no canal "Avisos de pedidos".
- [ ] Notificação fixa da localização: "Entregas — Localização ativa para receber pedidos próximos".

### Task 21: Ligar a chave e testar o alarme completo

- [ ] Perguntar ao Edgard se ainda há motoboy com APK antigo (avisar do efeito) e escolher um horário calmo.
- [ ] O Edgard acrescenta `ENTREGAS_ALARME_POR_DADOS: "1"` no ambiente dos serviços da API no stack (Portainer → Stacks → entregas → Editor; "Re-pull image" desligado) e faz o update.
- [ ] Testes: tela bloqueada + silencioso + app fechado (tela acende, loop, "Ver pedido" pede desbloqueio e abre o pedido); "Silenciar"; app em segundo plano desbloqueado (notificação no topo, loop; tocar abre sem som duplicado); app aberto (como hoje); reenvio aos 4/8/12 min; pedido atribuído pela central ao Motoca; voltar ao app pelo ícone com o alarme tocando (abre o pedido).

### Task 22: Fechamento

- [ ] CLAUDE.md (worktree `C:\tmp\em`): na seção "App do motoboy", trocar "(APK 16+)" pelo número real do APK e registrar o resultado dos testes e o estado da chave; commit e push (com aprovação).
- [ ] Atualizar a memória `app-motoboy-instabilidade.md` e o índice `MEMORY.md`.
- [ ] Remover a worktree: `git -C "/c/Users/Edgardjr/Documents/vibe coding/Delivery" worktree remove C:/tmp/em` (só depois de todos os pushes).
