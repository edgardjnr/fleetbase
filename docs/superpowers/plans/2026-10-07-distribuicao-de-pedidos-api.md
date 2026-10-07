# Distribuição de pedidos abertos (API): plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O pedido aberto deixa de ir por alarme a todos os motoboys do raio e passa a ser oferecido a um motoboy por vez, por 30 s, na ordem do menor tempo estimado até o cliente (contando a carga de quem já leva pedido), abrindo a todos quando a fila acaba ou aos 3 min.

**Architecture:** Tudo em `api/app` (nada em `packages/*/server` chega à produção). Um listener nosso substitui o `HandleOrderDispatched` do Fleet-Ops nos pedidos abertos e inicia uma *distribuição* (tabela `entregas_distribuicoes`) com *ofertas* (`entregas_ofertas`). O `Distribuidor` monta a fila (`Candidatos` → `EstimadorDeTempo` (matriz do OSRM) → `Encaixe` → `FilaDeCandidatos`), oferece por push (`OfertaDePedido`, extensão do `OrderPing`) e avança por job atrasado (`AvancarOferta`), com um comando de varredura por minuto como reserva. O aceite é restrito no `BarrarAceiteDePedidoEncerrado`, a lista do app é filtrada por middleware, e tudo fica atrás de `ENTREGAS_DISTRIBUICAO=1`. Spec: `docs/superpowers/specs/2026-10-07-distribuicao-de-pedidos-design.md`.

**Tech Stack:** Laravel 10 (PHP 8.2), Fleet-Ops (Composer), MySQL, Redis (cache, travas e fila), OSRM (`table`), FCM via `CanalFcmEntregas`. Testes com php-wasm (`scripts/teste-php/rodar.mjs`, stubs `stubs-ifood.php` + `stubs-ifood-fleetbase.php`).

**Desvios do spec, decididos aqui (o código é a referência):** as tabelas são lidas com `DB::table` numa classe `Distribuicoes` (como `PedidosIfood`), sem models Eloquent, porque é o que o banco em memória dos testes simula. `encerrar` e `registrarAceite` não tomam a `TravaDoPedido` (rodam dentro de quem já a segura: o middleware do aceite e a `TrocaDoMotoboy`). O motivo `redespachada` encerra a distribuição anterior quando o mesmo pedido é despachado de novo.

---

## Convenções

- **Rodar um teste:** `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/<arquivo>.php` (a pasta é a da instalação do php-wasm, ver o cabeçalho do `rodar.mjs`). Sai com 0 quando termina em `FALHAS: 0`.
- **Sintaxe:** `node scripts/teste-php/sintaxe.mjs` confere todo o `api/app` com o `php -l` do 8.2. Rode antes de cada commit.
- **Datas no banco:** TIMESTAMP, gravadas como texto no fuso do app (`now()->format('Y-m-d H:i:s')`) e lidas com `Carbon::parse(substr($texto, 0, 19), date_default_timezone_get())`. Nunca formate em UTC (ver "Fuso" no CLAUDE.md).
- **Logs:** prefixo `[entregas] distribuição:`, só ids e números.
- **Pontos:** sempre `[lat, lng]` (array de dois floats) dentro do nosso código; a conversão do `Point` do Fleet-Ops fica em `Pontos::de()`.
- **Commits:** mensagem em português, terminando com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Confirme `git rev-parse --show-toplevel` antes (a home também é um repo).

## Estrutura de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `api/database/migrations/2026_10_07_100000_create_entregas_distribuicao_table.php` | As duas tabelas (`entregas_distribuicoes`, `entregas_ofertas`). |
| `api/config/services.php` | `services.entregas.distribuicao` (`ENTREGAS_DISTRIBUICAO`). |
| `api/app/Support/Entregas/Distribuicao/Distribuicao.php` | Constantes (prazos, fases, motivos, respostas) e `ligada()`. |
| `api/app/Support/Entregas/Distribuicao/Pontos.php` | `[lat, lng]` a partir de um `Point`; linha reta (Haversine). |
| `api/app/Support/Entregas/Distribuicao/Distribuicoes.php` | Leitura e gravação das duas tabelas (`DB::table`). |
| `api/app/Support/Entregas/Distribuicao/Encaixe.php` | Funções puras: melhor inserção da coleta e entrega novas na sequência do motoboy. |
| `api/app/Support/Entregas/Distribuicao/EstimadorDeTempo.php` | Matriz de durações: OSRM `table`, reserva em linha reta. |
| `api/app/Support/Entregas/Distribuicao/Candidatos.php` | Consulta dos motoboys (raio, online, GPS recente) e das paradas em andamento. |
| `api/app/Support/Entregas/Distribuicao/FilaDeCandidatos.php` | Junta candidatos, matriz e encaixe; ordena. |
| `api/app/Support/Entregas/Distribuicao/Distribuidor.php` | O ciclo: iniciar, avançar, vencer, recusar, abrir a todos, encerrar, registrar aceite. |
| `api/app/Notifications/Entregas/OfertaDePedido.php` | Push da oferta (extensão do `OrderPing`). |
| `api/app/Notifications/Entregas/AvisosDoMotoboy.php` | Texto da oferta, dados `entregas_oferta*` e TTL de 30 s. |
| `api/app/Jobs/Entregas/AvancarOferta.php` | Job atrasado de 30 s que vence a oferta. |
| `api/app/Console/Commands/Entregas/VarrerDistribuicoes.php` | `entregas:distribuicao-varrer`, a cada minuto. |
| `api/app/Console/Kernel.php` | Agendamento do comando. |
| `api/app/Listeners/Entregas/DistribuirPedidoAberto.php` | No lugar do `HandleOrderDispatched` nos pedidos abertos. |
| `api/app/Listeners/Entregas/ObservadorDaDistribuicao.php` | `Order::updated` → encerrar (atribuída, cancelada). |
| `api/app/Providers/AppServiceProvider.php` | Troca do listener e o gancho do observador. |
| `api/app/Http/Middleware/BarrarAceiteDePedidoEncerrado.php` | Regra: na fase ofertas só quem tem a oferta aceita. |
| `api/app/Http/Middleware/FiltrarPedidosAbertosDoMotoboy.php` | A lista `GET v1/orders?adhoc=1&unassigned=1` só com a oferta dele e os abertos a todos. |
| `api/app/Http/Controllers/Entregas/MotoboyController.php` | `POST v1/entregas/motoboy/pedidos/{id}/recusar`. |
| `api/app/Http/Controllers/Entregas/DistribuicaoController.php` | Painel do console e "Abrir a todos agora". |
| `api/app/Providers/RouteServiceProvider.php` | Rotas, limitador e o middleware da lista. |
| `api/app/Console/Commands/Entregas/ReenviarPedidosAbertos.php` | Pula pedidos em fase ofertas. |
| `scripts/teste-php/stubs-ifood.php`, `stubs-ifood-fleetbase.php` | Stubs: migrations da distribuição, `insertGetId`, `dispatch()->delay()`, `Driver::notify`. |
| `scripts/teste-php/distribuicao-*.php`, `barrar-aceite.php`, `filtrar-pedidos-abertos.php` | Testes. |
| `CLAUDE.md` | Seção nova "Distribuição de pedidos abertos". |

---

### Task 1: Migration, config e stubs de teste

**Files:**
- Create: `api/database/migrations/2026_10_07_100000_create_entregas_distribuicao_table.php`
- Modify: `api/config/services.php` (bloco `entregas`)
- Modify: `scripts/teste-php/stubs-ifood.php` (glob das migrations em `Teste\Banco::esquema()`, `insertGetId` na `Teste\Consulta`, `dispatch()` com `delay()`)
- Modify: `scripts/teste-php/stubs-ifood-fleetbase.php` (`Driver::notify`)
- Test: `scripts/teste-php/ifood-stub-banco.php` (já existe; passa a conhecer as duas tabelas)

- [ ] **Step 1: Escrever a migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: distribuição de pedidos abertos (oferta um a um pelo tempo até o cliente; ver
 * App\Support\Entregas\Distribuicao). Uma distribuição por despacho do pedido; uma oferta por motoboy oferecido.
 * O nome do arquivo termina em "_entregas_distribuicao_table.php" porque o banco em memória dos testes
 * (scripts/teste-php/stubs-ifood.php) lê o esquema dos arquivos *_entregas_ifood_*_table.php e *_entregas_distribuicao_*.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_distribuicoes', function (Blueprint $table) {
            $table->id();
            $table->char('pedido_uuid', 36);
            $table->char('company_uuid', 36)->index();
            $table->timestamp('despachada_em');
            // ofertas | aberta | encerrada
            $table->string('fase', 16)->index();
            // aceita | atribuida | cancelada | aberta_pela_central | fila_esgotada | prazo | sem_candidato | redespachada
            $table->string('motivo', 32)->nullable();
            // a última fila calculada: [{motoboy_uuid, public_id, nome, tempo_s, encaixe, aproximado, livre}]
            $table->json('fila')->nullable();
            $table->timestamp('aberta_em')->nullable();
            $table->timestamp('encerrada_em')->nullable();
            $table->timestamps();
            $table->index(['pedido_uuid', 'fase']);
        });

        Schema::create('entregas_ofertas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distribuicao_id')->index();
            $table->char('pedido_uuid', 36);
            $table->char('motoboy_uuid', 36);
            $table->unsignedSmallInteger('posicao');
            $table->unsignedInteger('tempo_estimado_s')->nullable();
            $table->boolean('encaixe')->default(false);
            $table->boolean('aproximado')->default(false);
            $table->timestamp('oferecida_em');
            $table->timestamp('vence_em');
            // pendente | aceita | recusada | vencida | cancelada
            $table->string('resposta', 16);
            $table->timestamp('respondida_em')->nullable();
            $table->timestamps();
            $table->index(['pedido_uuid', 'resposta']);
            $table->index(['motoboy_uuid', 'resposta']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_ofertas');
        Schema::dropIfExists('entregas_distribuicoes');
    }
};
```

- [ ] **Step 2: A chave de configuração**

Em `api/config/services.php`, no bloco `'entregas' => [...]`, acrescente:

```php
        // distribuição de pedidos abertos (oferta um a um): 1 liga; vazio volta ao alarme geral do Fleet-Ops
        'distribuicao' => env('ENTREGAS_DISTRIBUICAO'),
```

- [ ] **Step 3: Stubs. O glob das migrations**

Em `scripts/teste-php/stubs-ifood.php`, na linha com `glob(dirname(__DIR__, 2) . '/api/database/migrations/*_entregas_ifood_*_table.php')` (dentro de `Teste\Banco::esquema()`), troque por dois globs:

```php
            $arquivos = array_merge(
                glob(dirname(__DIR__, 2) . '/api/database/migrations/*_entregas_ifood_*_table.php') ?: [],
                glob(dirname(__DIR__, 2) . '/api/database/migrations/*_entregas_distribuicao_*.php') ?: []
            );
            foreach ($arquivos as $arquivo) {
```

(e feche o `foreach` como estava). Confira a função `exigirColuna` logo abaixo (comentário "Lança como o MySQL ... na tabela entregas_ifood_*"): se ela decide pelo prefixo do nome da tabela, troque a decisão por "a tabela está no esquema lido" (`isset(self::esquema()[$tabela])`), para `entregas_distribuicoes` e `entregas_ofertas` também serem conferidas.

- [ ] **Step 4: Stubs. `insertGetId` na `Teste\Consulta`**

Logo depois do método `insert(array $linhas): bool` da classe `Teste\Consulta`, acrescente:

```php
        /** Como o insertGetId do query builder: insere uma linha e devolve o id (sequencial por tabela). */
        public function insertGetId(array $linha): int
        {
            $linha['id'] ??= count(Banco::$tabelas[$this->tabela] ?? []) + 1;
            $this->insert([$linha]);

            return (int) $linha['id'];
        }
```

Se o `insert` já atribui `id` sozinho, leia a linha gravada de volta (`end(Banco::$tabelas[$this->tabela])['id']`) em vez de calcular.

- [ ] **Step 5: Stubs. `dispatch()` com `delay()`**

No trait `Dispatchable` de `stubs-ifood.php` (linha ~280), troque o `return null;` por um objeto encadeável que guarda o atraso:

```php
    trait Dispatchable
    {
        // com Fila::$falhar, lança como o dispatch com o Redis fora do ar
        public static function dispatch(...$argumentos)
        {
            if (\Teste\Fila::$falhar) {
                throw \Teste\Fila::$falhar;
            }
            $job                 = new static(...$argumentos);
            \Teste\Fila::$jobs[] = $job;

            return new \Teste\DespachoPendente($job);
        }
    }
```

e, no namespace `Teste`, ao lado da classe `Fila`:

```php
    /** O PendingDispatch do Laravel: delay() e onQueue() ficam anotados no job (Fila::$atrasos[<índice>] = segundos). */
    class DespachoPendente
    {
        public static array $atrasos = [];

        public function __construct(private object $job) {}

        public function delay($quando)
        {
            $segundos = $quando instanceof \DateTimeInterface ? $quando->getTimestamp() - now()->getTimestamp() : (int) $quando;
            self::$atrasos[count(Fila::$jobs) - 1] = $segundos;

            return $this;
        }

        public function onQueue($fila) { return $this; }
        public function afterCommit() { return $this; }
    }
```

Em `reiniciarIfood()` (ou onde `Fila::$jobs = []` é zerado), zere também `\Teste\DespachoPendente::$atrasos = [];`.

- [ ] **Step 6: Stubs. `Driver::notify`**

Em `scripts/teste-php/stubs-ifood-fleetbase.php`, na classe `Fleetbase\FleetOps\Models\Driver`, acrescente a propriedade e o método (se já existirem, pule):

```php
        public $status = 'available';
        public $distance = null;
        /** Avisos enviados (notify): [[motoboy public_id, notificação]] */
        public static array $avisos = [];

        public function notify($notificacao): void { self::$avisos[] = [$this->public_id, $notificacao]; }
```

e zere `Driver::$avisos = [];` em `reiniciarFleetbase()`.

- [ ] **Step 7: Rodar o teste de sanidade do banco**

Run: `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-stub-banco.php`
Expected: `FALHAS: 0`. Acrescente nesse arquivo, antes do `resumo()`, duas conferências:

```php
echo '== tabelas da distribuição' . PHP_EOL;
DB::table('entregas_distribuicoes')->insert([['pedido_uuid' => 'o-1', 'company_uuid' => 'e-1', 'despachada_em' => '2026-10-07 10:00:00', 'fase' => 'ofertas']]);
confere(DB::table('entregas_distribuicoes')->where('fase', 'ofertas')->count() === 1, 'entregas_distribuicoes conhecida');
confere(excecao(fn () => DB::table('entregas_ofertas')->where('coluna_inexistente', 1)->get()) !== null, 'coluna inexistente em entregas_ofertas falha');
```

- [ ] **Step 8: Commit**

```bash
git add api/database/migrations/2026_10_07_100000_create_entregas_distribuicao_table.php api/config/services.php scripts/teste-php/stubs-ifood.php scripts/teste-php/stubs-ifood-fleetbase.php scripts/teste-php/ifood-stub-banco.php
git commit -m "Distribuição: tabelas, chave ENTREGAS_DISTRIBUICAO e stubs de teste"
```

---

### Task 2: `Distribuicao` (constantes) e `Pontos`

**Files:**
- Create: `api/app/Support/Entregas/Distribuicao/Distribuicao.php`
- Create: `api/app/Support/Entregas/Distribuicao/Pontos.php`
- Test: `scripts/teste-php/distribuicao-encaixe.php` (começa aqui; a Task 3 continua nele)

- [ ] **Step 1: Teste dos pontos**

```php
<?php

// Distribuição de pedidos abertos: pontos (Pontos) e o encaixe da coleta e entrega novas na sequência do motoboy (Encaixe).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-encaixe.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Pontos;

echo '== Pontos' . PHP_EOL;
$ponto = new class { public function getLat() { return -21.1775; } public function getLng() { return -47.8103; } };
confere(Pontos::de($ponto) === [-21.1775, -47.8103], 'Point do Fleet-Ops vira [lat, lng]');
confere(Pontos::de(null) === null, 'nulo: null');
confere(Pontos::de(new class { public function getLat() { return 0.0; } public function getLng() { return 0.0; } }) === null, '0,0 não vale');
confere(Pontos::de(new class { public function getLat() { return 95; } public function getLng() { return 10; } }) === null, 'fora da faixa não vale');
$metros = Pontos::metros([-21.1775, -47.8103], [-21.1775, -47.8003]);
confere($metros > 1000 && $metros < 1100, 'linha reta: ~1,04 km por 0,01° de longitude a 21° S (' . round($metros) . ' m)');
confere(Pontos::segundos([-21.1775, -47.8103], [-21.1775, -47.8003]) === (int) round($metros * Distribuicao::FATOR_LINHA_RETA / Distribuicao::METROS_POR_SEGUNDO), 'estimativa: × 1,3 a 25 km/h');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-encaixe.php`
Expected: erro de PHP (classe `Pontos` não encontrada), saída 1.

- [ ] **Step 3: `Distribuicao`**

```php
<?php

namespace App\Support\Entregas\Distribuicao;

/**
 * Entregas RestaurantePro: distribuição de pedidos abertos. Em vez do alarme a todos os motoboys do raio
 * (HandleOrderDispatched do Fleet-Ops), o pedido é oferecido a um motoboy por vez, por SEGUNDOS_DA_OFERTA, na ordem do
 * menor tempo estimado até o cliente (FilaDeCandidatos). Esgotada a fila, ou passados MINUTOS_ATE_ABRIR do despacho,
 * abre a todos no raio, como antes. Ligada por ENTREGAS_DISTRIBUICAO=1 (config services.entregas.distribuicao).
 * Spec: docs/superpowers/specs/2026-10-07-distribuicao-de-pedidos-design.md.
 */
class Distribuicao
{
    public const SEGUNDOS_DA_OFERTA = 30;
    public const MINUTOS_ATE_ABRIR  = 3;
    /** Posição do GPS mais velha que isto não serve para estimar (drivers.updated_at). */
    public const GPS_MINUTOS = 5;
    /** Atraso máximo aceito numa entrega já aceita ao encaixar o pedido novo. */
    public const ATRASO_MAXIMO_S = 600;
    public const PARADA_LOJA_S    = 180;
    public const PARADA_CLIENTE_S = 120;
    /** Reserva em linha reta quando o OSRM falha: metros × fator, a 25 km/h. */
    public const FATOR_LINHA_RETA  = 1.3;
    public const METROS_POR_SEGUNDO = 25000 / 3600;
    /** Acima disto, o OSRM (max-table-size padrão 100) recusa a matriz: estimativa em linha reta. */
    public const MAX_PONTOS_DA_MATRIZ = 100;
    /** Oferta vencida há mais que isto sem o job (Redis reiniciado): a varredura vence. */
    public const FOLGA_DA_VARREDURA_S = 20;

    public const FASE_OFERTAS   = 'ofertas';
    public const FASE_ABERTA    = 'aberta';
    public const FASE_ENCERRADA = 'encerrada';

    public const ACEITA              = 'aceita';
    public const ATRIBUIDA           = 'atribuida';
    public const CANCELADA           = 'cancelada';
    public const ABERTA_PELA_CENTRAL = 'aberta_pela_central';
    public const FILA_ESGOTADA       = 'fila_esgotada';
    public const PRAZO               = 'prazo';
    public const SEM_CANDIDATO       = 'sem_candidato';
    public const REDESPACHADA        = 'redespachada';

    public const PENDENTE = 'pendente';
    public const RECUSADA = 'recusada';
    public const VENCIDA  = 'vencida';
    // 'aceita' e 'cancelada' são as mesmas constantes acima

    public static function ligada(): bool
    {
        $valor = config('services.entregas.distribuicao');

        return is_string($valor) ? !in_array(strtolower(trim($valor)), ['', '0', 'false', 'off'], true) : (bool) $valor;
    }
}
```

- [ ] **Step 4: `Pontos`**

```php
<?php

namespace App\Support\Entregas\Distribuicao;

/** Pontos como [lat, lng] e a linha reta entre eles (Haversine). */
class Pontos
{
    /** [lat, lng] de um Point do Fleet-Ops (getLat/getLng), ou null se não é um ponto válido (0,0 e fora da faixa não valem). */
    public static function de($ponto): ?array
    {
        if (!is_object($ponto) || !method_exists($ponto, 'getLat') || !method_exists($ponto, 'getLng')) {
            return null;
        }
        $lat = (float) $ponto->getLat();
        $lng = (float) $ponto->getLng();
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat === 0.0 && $lng === 0.0)) {
            return null;
        }

        return [$lat, $lng];
    }

    public static function metros(array $a, array $b): float
    {
        $raio = 6371000.0;
        $dLat = deg2rad($b[0] - $a[0]);
        $dLng = deg2rad($b[1] - $a[1]);
        $h    = sin($dLat / 2) ** 2 + cos(deg2rad($a[0])) * cos(deg2rad($b[0])) * sin($dLng / 2) ** 2;

        return 2 * $raio * asin(min(1.0, sqrt($h)));
    }

    /** Segundos pela linha reta × FATOR_LINHA_RETA a 25 km/h (a reserva quando o OSRM falha). */
    public static function segundos(array $a, array $b): int
    {
        return (int) round(static::metros($a, $b) * Distribuicao::FATOR_LINHA_RETA / Distribuicao::METROS_POR_SEGUNDO);
    }
}
```

- [ ] **Step 5: Rodar e ver passar**

Run: `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-encaixe.php`
Expected: `FALHAS: 0`.

- [ ] **Step 6: Commit**

```bash
git add api/app/Support/Entregas/Distribuicao/Distribuicao.php api/app/Support/Entregas/Distribuicao/Pontos.php scripts/teste-php/distribuicao-encaixe.php
git commit -m "Distribuição: constantes e pontos"
```

---

### Task 3: `Encaixe` (funções puras)

**Files:**
- Create: `api/app/Support/Entregas/Distribuicao/Encaixe.php`
- Test: `scripts/teste-php/distribuicao-encaixe.php`

A sequência do motoboy é uma lista de paradas `['indice' => int, 'tipo' => 'coleta'|'entrega']`, onde `indice` aponta para um ponto da matriz. `$dur($i, $j)` devolve os segundos de rota entre dois índices. O índice `$posicao` é a posição atual do motoboy; `$p` e `$d` são a coleta e a entrega novas. Chegada = saída da parada anterior + rota; saída = chegada + parada fixa (loja ou cliente).

- [ ] **Step 1: Testes do encaixe (acrescente antes do `resumo()`)**

```php
echo '== Encaixe' . PHP_EOL;
use App\Support\Entregas\Distribuicao\Encaixe;

// matriz fixa: índices 0 = motoboy, 1 = coleta A, 2 = entrega A, 3 = coleta nova (P), 4 = entrega nova (D)
$m = [
    0 => [0 => 0, 1 => 300, 2 => 900, 3 => 300, 4 => 1200],
    1 => [0 => 300, 1 => 0, 2 => 600, 3 => 0, 4 => 900],
    2 => [0 => 900, 1 => 600, 2 => 0, 3 => 600, 4 => 300],
    3 => [0 => 300, 1 => 0, 2 => 600, 3 => 0, 4 => 900],
    4 => [0 => 1200, 1 => 900, 2 => 300, 3 => 900, 4 => 0],
];
$dur = fn (int $i, int $j) => (float) $m[$i][$j];
$loja = Distribuicao::PARADA_LOJA_S; $cliente = Distribuicao::PARADA_CLIENTE_S;

$livre = Encaixe::calcular(0, [], 3, 4, $dur);
confere($livre === ['tempo_s' => 300 + $loja + 900, 'encaixe' => false, 'atraso_s' => 0], 'livre: posição → loja → cliente, com a parada na loja (' . json_encode($livre) . ')');

// ocupado ainda na coleta de A, e a loja nova é a mesma (dur 1→3 = 0): pega os dois juntos e entrega A antes (mais perto)
$base = [['indice' => 1, 'tipo' => 'coleta'], ['indice' => 2, 'tipo' => 'entrega']];
$r    = Encaixe::calcular(0, $base, 3, 4, $dur);
// melhor: 0→1 (300) +loja, 1→3 (0) +loja, 3→2 (600) +cliente, 2→4 (300) = 300+180+0+180+600+120+300 = 1680; A chega em 1260 em vez de 300+180+600 = 1080: atraso 180 ≤ 600
confere($r['tempo_s'] === 1680 && $r['encaixe'] === true && $r['atraso_s'] === 180, 'mesma loja: coleta junto, entrega A primeiro, D depois (' . json_encode($r) . ')');

// atraso acima do limite: a entrega nova fica a 2 h de tudo; encaixar D antes de A atrasaria A demais → "termina e vai"
$longe = $m;
foreach ([0, 1, 2, 3] as $i) { $longe[$i][4] = 7200; $longe[4][$i] = 7200; }
$durLonge = fn (int $i, int $j) => (float) $longe[$i][$j];
$r = Encaixe::calcular(0, $base, 3, 4, $durLonge);
confere($r['atraso_s'] <= Distribuicao::ATRASO_MAXIMO_S, 'nunca atrasa uma entrega já aceita mais que o limite');
confere($r['encaixe'] === true && $r['tempo_s'] === 300 + $loja + 0 + $loja + 600 + $cliente + 7200, 'coleta no caminho (mesma loja) e D por último: encaixe só na coleta (' . json_encode($r) . ')');

// ocupado já em entrega (só a entrega A falta) e nada no caminho: termina A e vai
$soEntrega = [['indice' => 2, 'tipo' => 'entrega']];
$r = Encaixe::calcular(0, $soEntrega, 3, 4, $dur);
$terminaEVai = 900 + $cliente + 600 + $loja + 900;          // 0→2, 2→3, 3→4
$pegaAntes   = 300 + $loja + 600 + $cliente + 300;          // 0→3, 3→2, 2→4 = 1500: A atrasa 300+180+600-900 = 180 → cabe e é mais rápido
confere($r['tempo_s'] === min($terminaEVai, $pegaAntes) && $r['encaixe'] === true, 'passa na loja antes de entregar A quando compensa (' . json_encode($r) . ')');

// base vazia e dur nula: zero mais as paradas
confere(Encaixe::calcular(0, [], 3, 4, fn () => 0.0)['tempo_s'] === $loja, 'tudo no mesmo ponto: só a parada na loja');
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-encaixe.php`
Expected: erro de PHP (classe `Encaixe` não encontrada).

- [ ] **Step 3: Implementar `Encaixe`**

```php
<?php

namespace App\Support\Entregas\Distribuicao;

/**
 * Onde a coleta (P) e a entrega (D) do pedido novo entram na sequência de paradas que o motoboy ainda tem, e quanto
 * tempo ele leva até D. Funções puras: a matriz vem de fora ($dur(i, j) em segundos entre dois índices de ponto).
 *
 * - Chegada numa parada = saída da anterior + rota; saída = chegada + parada fixa (PARADA_LOJA_S ou PARADA_CLIENTE_S).
 * - Testa todas as inserções com P antes de D (inclusive P e D no fim = "termina tudo e depois vai").
 * - Uma inserção cabe se nenhuma entrega já aceita chega mais de ATRASO_MAXIMO_S depois do que chegaria sem o pedido novo.
 * - Vale a que cabe com a menor chegada em D. P e D no fim sempre cabem (atraso 0).
 */
class Encaixe
{
    /**
     * @param int                                           $posicao índice do ponto onde o motoboy está
     * @param array<int, array{indice: int, tipo: string}> $base    paradas que faltam, na ordem
     * @param callable(int, int): float                     $dur     segundos de rota entre dois índices
     *
     * @return array{tempo_s: int, encaixe: bool, atraso_s: int} tempo até D; encaixe = P ou D entraram antes do fim; o maior atraso imposto
     */
    public static function calcular(int $posicao, array $base, int $p, int $d, callable $dur): array
    {
        $n              = count($base);
        $chegadasDaBase = static::chegadas($posicao, $base, $dur);
        $melhor         = null;

        for ($i = 0; $i <= $n; $i++) {
            for ($j = $i; $j <= $n; $j++) {
                $sequencia = array_merge(
                    array_slice($base, 0, $i),
                    [['indice' => $p, 'tipo' => 'coleta']],
                    array_slice($base, $i, $j - $i),
                    [['indice' => $d, 'tipo' => 'entrega']],
                    array_slice($base, $j)
                );
                $chegadas = static::chegadas($posicao, $sequencia, $dur);

                // atraso das paradas da base: a base ocupa, na sequência nova, as posições fora de i (P) e j + 1 (D)
                $atraso  = 0;
                $k       = 0;
                foreach ($sequencia as $pos => $parada) {
                    if ($pos === $i || $pos === $j + 1) {
                        continue;
                    }
                    if ($parada['tipo'] === 'entrega') {
                        $atraso = max($atraso, $chegadas[$pos] - $chegadasDaBase[$k]);
                    }
                    $k++;
                }
                if ($atraso > Distribuicao::ATRASO_MAXIMO_S) {
                    continue;
                }

                $tempo = $chegadas[$j + 1];
                if ($melhor === null || $tempo < $melhor['tempo_s']) {
                    $melhor = ['tempo_s' => $tempo, 'encaixe' => !($i === $n && $j === $n), 'atraso_s' => $atraso];
                }
            }
        }

        return $melhor;
    }

    /** Segundos de chegada em cada parada da sequência, a partir do ponto de partida. */
    public static function chegadas(int $partida, array $sequencia, callable $dur): array
    {
        $chegadas = [];
        $saida    = 0;
        $anterior = $partida;
        foreach ($sequencia as $parada) {
            $chegada    = $saida + (int) round((float) $dur($anterior, $parada['indice']));
            $chegadas[] = $chegada;
            $saida      = $chegada + ($parada['tipo'] === 'coleta' ? Distribuicao::PARADA_LOJA_S : Distribuicao::PARADA_CLIENTE_S);
            $anterior   = $parada['indice'];
        }

        return $chegadas;
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-encaixe.php`
Expected: `FALHAS: 0`. Se a conta de algum caso der diferente, recalcule à mão com a regra do docblock e corrija o **teste** só se a conta à mão confirmar o código; senão corrija o código.

- [ ] **Step 5: Commit**

```bash
git add api/app/Support/Entregas/Distribuicao/Encaixe.php scripts/teste-php/distribuicao-encaixe.php
git commit -m "Distribuição: encaixe do pedido novo na sequência do motoboy"
```

---

### Task 4: `EstimadorDeTempo` (matriz do OSRM com reserva em linha reta)

**Files:**
- Create: `api/app/Support/Entregas/Distribuicao/EstimadorDeTempo.php`
- Test: `scripts/teste-php/distribuicao-fila.php` (começa aqui)

O `Http` falso de `stubs-ifood.php` enfileira respostas com `Teste\Http::responder($status, $corpo)` e `Teste\Http::falharConexao()`, e guarda as URLs em `Teste\Http::urls()`. O host do OSRM vem de `config('fleetops.osrm.host')` (o `fleetops.osrm.host` já existe na produção: `OSRM_HOST`), lido no teste de `Teste\Config::$valores`.

- [ ] **Step 1: Teste**

```php
<?php

// Distribuição de pedidos abertos: matriz de tempos (EstimadorDeTempo) e a fila de candidatos (FilaDeCandidatos).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-fila.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\EstimadorDeTempo;
use App\Support\Entregas\Distribuicao\Pontos;
use Teste\Config;
use Teste\Http;

Config::$valores['fleetops.osrm.host'] = 'http://osrm:5000';

echo '== EstimadorDeTempo' . PHP_EOL;
$pontos = [[-21.17, -47.81], [-21.18, -47.80], [-21.19, -47.79]];
reiniciarIfood();
Http::responder(200, ['code' => 'Ok', 'durations' => [[0, 100, 200], [100, 0, 150], [200, 150, 0]]]);
$matriz = (new EstimadorDeTempo())->matriz($pontos);
confere($matriz['aproximado'] === false && $matriz['durations'][0][2] === 200.0, 'OSRM: devolve a matriz (' . json_encode($matriz['durations'][0]) . ')');
confere(str_starts_with(Http::urls()[0], 'http://osrm:5000/table/v1/driving/-47.81,-21.17;-47.8,-21.18;-47.79,-21.19'), 'URL do table com lng,lat (' . Http::urls()[0] . ')');

reiniciarIfood();
Http::falharConexao();
$matriz = (new EstimadorDeTempo())->matriz($pontos);
confere($matriz['aproximado'] === true && $matriz['durations'][0][1] === (float) Pontos::segundos($pontos[0], $pontos[1]), 'OSRM fora: linha reta marcada como aproximada');
confere(logou('OSRM indisponível', 'warning'), 'fica no log');

reiniciarIfood();
Http::responder(200, ['code' => 'NoTable']);
confere((new EstimadorDeTempo())->matriz($pontos)['aproximado'] === true, 'resposta sem durations: aproximada');

reiniciarIfood();
Http::responder(200, ['code' => 'Ok', 'durations' => [[0, null, 200], [100, 0, 150], [200, 150, 0]]]);
$matriz = (new EstimadorDeTempo())->matriz($pontos);
confere($matriz['durations'][0][1] === (float) Pontos::segundos($pontos[0], $pontos[1]) && $matriz['aproximado'] === false, 'null numa célula (sem rota): só aquela célula em linha reta');

reiniciarIfood();
$muitos = array_map(fn ($i) => [-21.17 + $i / 1000, -47.81], range(0, Distribuicao::MAX_PONTOS_DA_MATRIZ));
confere((new EstimadorDeTempo())->matriz($muitos)['aproximado'] === true && Http::urls() === [], 'acima do máximo de pontos: nem chama o OSRM');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-fila.php`
Expected: erro de PHP (classe `EstimadorDeTempo` não encontrada).

- [ ] **Step 3: Implementar**

```php
<?php

namespace App\Support\Entregas\Distribuicao;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Matriz de durações (segundos) entre pontos [lat, lng]: uma chamada ao serviço `table` do OSRM (o mesmo OSRM_HOST do
 * km do pagamento). Quando o OSRM falha, não responde em TIMEOUT_S, devolve algo sem `durations` ou há pontos demais
 * (MAX_PONTOS_DA_MATRIZ), a matriz inteira sai em linha reta (Pontos::segundos) e marcada `aproximado`. Uma célula
 * null (sem rota entre dois pontos) sai em linha reta sem marcar.
 */
class EstimadorDeTempo
{
    public const TIMEOUT_S = 3;

    /** @return array{durations: array<int, array<int, float>>, aproximado: bool} */
    public function matriz(array $pontos): array
    {
        $n = count($pontos);
        if ($n > Distribuicao::MAX_PONTOS_DA_MATRIZ) {
            Log::warning('[entregas] distribuição: pontos demais para a matriz do OSRM; estimativa em linha reta', ['pontos' => $n]);

            return ['durations' => $this->linhaReta($pontos), 'aproximado' => true];
        }

        $durations = $this->peloOsrm($pontos);
        if ($durations === null) {
            return ['durations' => $this->linhaReta($pontos), 'aproximado' => true];
        }

        foreach ($durations as $i => &$linha) {
            foreach ($linha as $j => &$valor) {
                $valor = is_numeric($valor) ? (float) $valor : (float) Pontos::segundos($pontos[$i], $pontos[$j]);
            }
        }

        return ['durations' => $durations, 'aproximado' => false];
    }

    protected function peloOsrm(array $pontos): ?array
    {
        $coordenadas = implode(';', array_map(fn ($p) => $p[1] . ',' . $p[0], $pontos));
        $url         = rtrim((string) config('fleetops.osrm.host', 'https://router.project-osrm.org'), '/') . "/table/v1/driving/{$coordenadas}";

        try {
            $resposta  = Http::timeout(static::TIMEOUT_S)->get($url, ['annotations' => 'duration']);
            $durations = $resposta->json('durations');
        } catch (\Throwable $e) {
            Log::warning('[entregas] distribuição: OSRM indisponível; estimativa em linha reta', ['erro' => get_class($e), 'pontos' => count($pontos)]);

            return null;
        }

        if (!is_array($durations) || count($durations) !== count($pontos)) {
            Log::warning('[entregas] distribuição: OSRM indisponível; estimativa em linha reta', ['motivo' => 'resposta sem durations', 'pontos' => count($pontos)]);

            return null;
        }

        return $durations;
    }

    protected function linhaReta(array $pontos): array
    {
        $matriz = [];
        foreach ($pontos as $i => $a) {
            foreach ($pontos as $j => $b) {
                $matriz[$i][$j] = $i === $j ? 0.0 : (float) Pontos::segundos($a, $b);
            }
        }

        return $matriz;
    }
}
```

Se o `Teste\PedidoHttp` falso não tiver `json('chave')` com argumento, leia `$resposta->json()['durations'] ?? null`.

- [ ] **Step 4: Rodar e ver passar**

Run: `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-fila.php`
Expected: `FALHAS: 0`.

- [ ] **Step 5: Commit**

```bash
git add api/app/Support/Entregas/Distribuicao/EstimadorDeTempo.php scripts/teste-php/distribuicao-fila.php
git commit -m "Distribuição: matriz de tempos pelo OSRM com reserva em linha reta"
```

---

### Task 5: `Candidatos` e `FilaDeCandidatos`

**Files:**
- Create: `api/app/Support/Entregas/Distribuicao/Candidatos.php`
- Create: `api/app/Support/Entregas/Distribuicao/FilaDeCandidatos.php`
- Test: `scripts/teste-php/distribuicao-fila.php`

`Candidatos` tem duas consultas ao banco (motoboys no raio e paradas em andamento) que o banco em memória não simula (`distanceSphere`, `whereRaw`). Por isso elas ficam atrás de closures trocáveis (`Candidatos::$buscarMotoboys`, `Candidatos::$buscarParadas`), como o `AvisosDoMotoboy::$cartao`, e o teste cobre a montagem e a fila. Um candidato é `['motoboy' => Driver, 'posicao' => [lat, lng], 'distancia' => m, 'paradas' => [[lat, lng, 'coleta'|'entrega'], ...]]`.

- [ ] **Step 1: Teste da fila (acrescente antes do `resumo()` de `distribuicao-fila.php`)**

```php
echo '== FilaDeCandidatos' . PHP_EOL;
use App\Support\Entregas\Distribuicao\Candidatos;
use App\Support\Entregas\Distribuicao\FilaDeCandidatos;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;

/** Um Point como o do Fleet-Ops (getLat/getLng). */
function ponto(array $p): object
{
    return new class($p) { public function __construct(private array $p) {} public function getLat() { return $this->p[0]; } public function getLng() { return $this->p[1]; } };
}

function motoboy(string $id, string $nome, array $posicao): Driver
{
    return new Driver(['uuid' => 'd-' . $id, 'public_id' => 'driver_' . $id, 'company_uuid' => 'empresa-1', 'name' => $nome, 'online' => true, 'location' => ponto($posicao)]);
}

/** Pedido com coleta e entrega, como o Order real (payload->pickup/dropoff com location). */
function pedidoCom(array $coleta, array $entrega): Order
{
    $pedido          = new Order(['uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1', 'adhoc' => true, 'status' => 'dispatched']);
    $pedido->payload = (object) ['pickup' => (object) ['location' => ponto($coleta)], 'dropoff' => (object) ['location' => ponto($entrega)]];

    return $pedido;
}

reiniciarFleetbase();
reiniciarIfood();
$coleta  = [-21.1700, -47.8100];
$entrega = [-21.1800, -47.8100];
$pedido  = pedidoCom($coleta, $entrega);
// A livre a 1 km da loja; B livre a 200 m; C ocupado (uma entrega a 100 m da loja) a 300 m
Candidatos::$buscarMotoboys = fn (Order $p, bool $gpsRecente) => [
    ['motoboy' => motoboy('a', 'Ana', [-21.1610, -47.8100]), 'posicao' => [-21.1610, -47.8100], 'distancia' => 1000.0],
    ['motoboy' => motoboy('b', 'Bia', [-21.1682, -47.8100]), 'posicao' => [-21.1682, -47.8100], 'distancia' => 200.0],
    ['motoboy' => motoboy('c', 'Caio', [-21.1673, -47.8100]), 'posicao' => [-21.1673, -47.8100], 'distancia' => 300.0],
];
Candidatos::$buscarParadas = fn (string $empresa, array $uuids) => ['d-c' => [[-21.1709, -47.8100, 'entrega']]];

// a matriz sai da linha reta (o teste não depende do OSRM) e o estimador anota os pontos recebidos
$estimador = new class extends EstimadorDeTempo {
    public array $pontosRecebidos = [];
    public function matriz(array $pontos): array
    {
        $this->pontosRecebidos = $pontos;
        $m = [];
        foreach ($pontos as $i => $a) { foreach ($pontos as $j => $b) { $m[$i][$j] = (float) Pontos::segundos($a, $b); } }

        return ['durations' => $m, 'aproximado' => false];
    }
};
$fila = (new FilaDeCandidatos($estimador))->para($pedido, Candidatos::elegiveis($pedido, []));
confere(count($estimador->pontosRecebidos) === 2 + 3 + 1, 'uma matriz só: coleta, entrega, 3 posições e 1 parada');
confere(array_column($fila, 'public_id') === ['driver_b', 'driver_c', 'driver_a'], 'ordem pelo tempo até o cliente: B (perto, livre), C (ocupado mas perto, com encaixe), A (longe) (' . json_encode(array_column($fila, 'public_id')) . ')');
confere($fila[0]['livre'] === true && $fila[1]['livre'] === false && $fila[1]['encaixe'] === true, 'livre e encaixe marcados');
confere($fila[0]['tempo_s'] > 0 && $fila[0]['aproximado'] === false && $fila[0]['motoboy_uuid'] === 'd-b' && $fila[0]['nome'] === 'Bia' && $fila[0]['distancia_m'] === 200, 'campos da fila (' . json_encode($fila[0]) . ')');

$fila = (new FilaDeCandidatos($estimador))->para($pedido, Candidatos::elegiveis($pedido, ['d-b']));
confere(array_column($fila, 'public_id') === ['driver_c', 'driver_a'], 'excluído (já respondeu ou tem oferta pendente) não entra');

Candidatos::$buscarMotoboys = fn () => [];
confere((new FilaDeCandidatos($estimador))->para($pedido, Candidatos::elegiveis($pedido, [])) === [], 'sem candidato: fila vazia');

// empate: dois livres no mesmo ponto → public_id
Candidatos::$buscarMotoboys = fn () => [
    ['motoboy' => motoboy('z', 'Zé', $coleta), 'posicao' => $coleta, 'distancia' => 0.0],
    ['motoboy' => motoboy('m', 'Mia', $coleta), 'posicao' => $coleta, 'distancia' => 0.0],
];
Candidatos::$buscarParadas = fn () => [];
confere(array_column((new FilaDeCandidatos($estimador))->para($pedido, Candidatos::elegiveis($pedido, [])), 'public_id') === ['driver_m', 'driver_z'], 'empate: pelo public_id');

$pedidoSemEntrega = pedidoCom($coleta, [0.0, 0.0]);
confere((new FilaDeCandidatos($estimador))->para($pedidoSemEntrega, Candidatos::elegiveis($pedidoSemEntrega, [])) === [], 'pedido sem entrega válida: fila vazia (vai abrir a todos)');
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-fila.php`
Expected: erro de PHP (classe `Candidatos` não encontrada).

- [ ] **Step 3: `Candidatos`**

```php
<?php

namespace App\Support\Entregas\Distribuicao;

use App\Support\Entregas\SituacaoDoMotoboy;
use App\Support\Entregas\StatusDoPedido;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Utils;

/**
 * Quem pode receber a oferta de um pedido aberto e o que cada um ainda tem para fazer.
 *
 * Motoboys: os da empresa do pedido, online, com status `available` (o do Fleet-Ops, manual), com posição válida dentro
 * do raio de pedido aberto da coleta (Order::getAdhocDistance, linha reta, a mesma consulta do HandleOrderDispatched,
 * agora filtrada pela empresa) e, para a oferta, com o GPS atualizado há menos de GPS_MINUTOS (drivers.updated_at).
 * Paradas: os pedidos em andamento dele (a regra do capacete do mapa: SituacaoDoMotoboy), na ordem de aceite
 * (started_at, depois dispatched_at), com a coleta (se ainda não pegou) e a entrega.
 *
 * As duas consultas ficam atrás de closures trocáveis para os testes (o banco em memória não simula distanceSphere).
 */
class Candidatos
{
    /** @var null|\Closure(Order, bool): array<int, array{motoboy: Driver, posicao: array, distancia: float}> */
    public static ?\Closure $buscarMotoboys = null;
    /** @var null|\Closure(string, array<string>): array<string, array<int, array{0: float, 1: float, 2: string}>> */
    public static ?\Closure $buscarParadas = null;

    /**
     * Os candidatos elegíveis à oferta, com as paradas: fora os uuids excluídos (quem já respondeu neste despacho,
     * quem tem oferta pendente de outro pedido).
     *
     * @return array<int, array{motoboy: Driver, posicao: array, distancia: float, paradas: array}>
     */
    public static function elegiveis(Order $pedido, array $excluidos): array
    {
        $motoboys = array_values(array_filter(static::noRaio($pedido, true), fn ($c) => !in_array((string) $c['motoboy']->uuid, $excluidos, true)));
        if ($motoboys === []) {
            return [];
        }
        $paradas = static::paradas((string) $pedido->company_uuid, array_map(fn ($c) => (string) $c['motoboy']->uuid, $motoboys));
        foreach ($motoboys as &$candidato) {
            $candidato['paradas'] = $paradas[(string) $candidato['motoboy']->uuid] ?? [];
        }

        return $motoboys;
    }

    /** @return array<int, array{motoboy: Driver, posicao: array, distancia: float}> */
    public static function noRaio(Order $pedido, bool $gpsRecente): array
    {
        if (static::$buscarMotoboys) {
            return (static::$buscarMotoboys)($pedido, $gpsRecente);
        }

        $coleta = $pedido->getPickupLocation();
        if (!Utils::isPoint($coleta)) {
            return [];
        }

        $motoboys = Driver::where(['status' => 'available', 'online' => 1])
            ->where('company_uuid', $pedido->company_uuid)
            ->whereNull('deleted_at')
            ->whereNotNull('location')
            ->whereRaw('ST_Y(location) BETWEEN -90 AND 90 AND ST_X(location) BETWEEN -180 AND 180 AND NOT (ST_X(location) = 0 AND ST_Y(location) = 0)')
            ->when($gpsRecente, fn ($q) => $q->where('updated_at', '>=', now()->subMinutes(Distribuicao::GPS_MINUTOS)))
            ->distanceSphere('location', $coleta, $pedido->getAdhocDistance())
            ->distanceSphereValue('location', $coleta)
            ->withoutGlobalScopes()
            ->get();

        $candidatos = [];
        foreach ($motoboys as $motoboy) {
            $posicao = Pontos::de($motoboy->location);
            if ($posicao) {
                $candidatos[] = ['motoboy' => $motoboy, 'posicao' => $posicao, 'distancia' => (float) ($motoboy->distance ?? 0)];
            }
        }

        return $candidatos;
    }

    /** @return array<string, array<int, array{0: float, 1: float, 2: string}>> uuid do motoboy => paradas [lat, lng, tipo] */
    public static function paradas(string $empresa, array $motoboyUuids): array
    {
        if (static::$buscarParadas) {
            return (static::$buscarParadas)($empresa, $motoboyUuids);
        }

        $pedidos = Order::where('company_uuid', $empresa)
            ->whereIn('driver_assigned_uuid', $motoboyUuids)
            ->whereNotIn('status', StatusDoPedido::ENCERRADOS)
            ->where('updated_at', '>=', now()->subHours(SituacaoDoMotoboy::HORAS_PEDIDO_EM_ANDAMENTO))
            ->with(['payload.pickup', 'payload.dropoff'])
            ->orderByRaw('COALESCE(started_at, dispatched_at, created_at)')
            ->get();

        $porMotoboy = [];
        foreach ($pedidos as $pedido) {
            $coleta    = Pontos::de($pedido->payload?->pickup?->location);
            $entrega   = Pontos::de($pedido->payload?->dropoff?->location);
            $emEntrega = in_array(strtolower((string) $pedido->status), SituacaoDoMotoboy::STATUS_EM_ENTREGA, true);
            if (!$emEntrega && $coleta) {
                $porMotoboy[$pedido->driver_assigned_uuid][] = [$coleta[0], $coleta[1], 'coleta'];
            }
            if ($entrega) {
                $porMotoboy[$pedido->driver_assigned_uuid][] = [$entrega[0], $entrega[1], 'entrega'];
            }
        }

        return $porMotoboy;
    }
}
```

- [ ] **Step 4: `FilaDeCandidatos`**

```php
<?php

namespace App\Support\Entregas\Distribuicao;

use Fleetbase\FleetOps\Models\Order;

/**
 * A fila de um pedido aberto: para cada candidato, o tempo até o cliente novo (Encaixe) com uma matriz só do OSRM
 * (coleta, entrega, posições e paradas de todos). Ordem: menor tempo; empate: o livre primeiro; depois o public_id.
 */
class FilaDeCandidatos
{
    public function __construct(protected EstimadorDeTempo $estimador) {}

    /**
     * @param array<int, array{motoboy: object, posicao: array, distancia: float, paradas: array}> $candidatos
     *
     * @return array<int, array{motoboy_uuid: string, public_id: string, nome: string, tempo_s: int, encaixe: bool, aproximado: bool, livre: bool, distancia_m: int}>
     */
    public function para(Order $pedido, array $candidatos): array
    {
        $coleta  = Pontos::de($pedido->payload?->pickup?->location);
        $entrega = Pontos::de($pedido->payload?->dropoff?->location);
        if (!$coleta || !$entrega || $candidatos === []) {
            return [];
        }

        // índices da matriz: 0 = coleta (P), 1 = entrega (D), depois posição e paradas de cada candidato
        $pontos = [$coleta, $entrega];
        $plano  = [];
        foreach ($candidatos as $candidato) {
            $posicao  = count($pontos);
            $pontos[] = $candidato['posicao'];
            $base     = [];
            foreach ($candidato['paradas'] as $parada) {
                $base[]   = ['indice' => count($pontos), 'tipo' => $parada[2]];
                $pontos[] = [$parada[0], $parada[1]];
            }
            $plano[] = [$candidato, $posicao, $base];
        }

        $matriz = $this->estimador->matriz($pontos);
        $dur    = fn (int $i, int $j) => (float) ($matriz['durations'][$i][$j] ?? 0);

        $fila = [];
        foreach ($plano as [$candidato, $posicao, $base]) {
            $resultado = Encaixe::calcular($posicao, $base, 0, 1, $dur);
            $fila[]    = [
                'motoboy_uuid' => (string) $candidato['motoboy']->uuid,
                'public_id'    => (string) $candidato['motoboy']->public_id,
                'nome'         => (string) $candidato['motoboy']->name,
                'tempo_s'      => $resultado['tempo_s'],
                'encaixe'      => $resultado['encaixe'],
                'aproximado'   => $matriz['aproximado'],
                'livre'        => $base === [],
                'distancia_m'  => (int) round($candidato['distancia']),
            ];
        }

        usort($fila, fn ($a, $b) => [$a['tempo_s'], $a['livre'] ? 0 : 1, $a['public_id']] <=> [$b['tempo_s'], $b['livre'] ? 0 : 1, $b['public_id']]);

        return $fila;
    }
}
```

- [ ] **Step 5: Rodar e ver passar**

Run: `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-fila.php`
Expected: `FALHAS: 0`. Se a ordem B, C, A não sair, imprima `json_encode($fila)` e confira: C tem a entrega a ~100 m da loja (≈ 20 s em linha reta mais a parada do cliente), então `posição → entrega → P → D` é mais rápido que A, que está a 1 km.

- [ ] **Step 6: Commit**

```bash
git add api/app/Support/Entregas/Distribuicao/Candidatos.php api/app/Support/Entregas/Distribuicao/FilaDeCandidatos.php scripts/teste-php/distribuicao-fila.php
git commit -m "Distribuição: candidatos no raio e a fila pelo tempo até o cliente"
```

---

### Task 6: `Distribuicoes` (leitura e gravação das tabelas)

**Files:**
- Create: `api/app/Support/Entregas/Distribuicao/Distribuicoes.php`
- Test: `scripts/teste-php/distribuicao-ciclo.php` (começa aqui)

- [ ] **Step 1: Teste**

```php
<?php

// Distribuição de pedidos abertos: as tabelas (Distribuicoes) e o ciclo (Distribuidor): iniciar, avançar, recusar,
// vencer, prazo, abrir a todos, encerrar, aceite, desligada.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-ciclo.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Distribuicoes;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\DB;
use Teste\Relogio;

function pedidoAberto(array $extra = []): Order
{
    return new Order($extra + ['uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1', 'adhoc' => true, 'dispatched' => true, 'status' => 'dispatched']);
}

echo '== Distribuicoes' . PHP_EOL;
reiniciarFleetbase();
reiniciarIfood();
Relogio::$agora = '2026-10-07 10:00:00';
$d = Distribuicoes::criar(pedidoAberto());
confere($d->id === 1 && $d->fase === 'ofertas' && $d->despachada_em === '2026-10-07 10:00:00' && $d->company_uuid === 'empresa-1', 'criar: fase ofertas, despachada agora (' . json_encode($d) . ')');
confere(Distribuicoes::emOfertas('order-1') === true && Distribuicoes::emOfertas('order-2') === false, 'emOfertas');
confere(Distribuicoes::doPedido('order-1')?->id === 1, 'doPedido: a que não está encerrada');

$o = Distribuicoes::criarOferta($d, ['motoboy_uuid' => 'd-a', 'tempo_s' => 500, 'encaixe' => false, 'aproximado' => false], 1);
confere($o->resposta === 'pendente' && $o->vence_em === '2026-10-07 10:00:30' && $o->posicao === 1 && $o->tempo_estimado_s === 500, 'criarOferta: pendente, vence em 30 s (' . json_encode($o) . ')');
confere(Distribuicoes::ofertaPendente(1)?->id === $o->id, 'ofertaPendente');
confere(Distribuicoes::motoboysComOfertaPendente() === ['d-a'], 'motoboysComOfertaPendente (qualquer pedido)');

Relogio::$agora = '2026-10-07 10:00:10';
Distribuicoes::responder($o->id, 'recusada');
$o = DB::table('entregas_ofertas')->where('id', $o->id)->first();
confere($o->resposta === 'recusada' && $o->respondida_em === '2026-10-07 10:00:10', 'responder grava a resposta e a hora');
confere(Distribuicoes::motoboysQueResponderam(1) === ['d-a'] && Distribuicoes::motoboysComOfertaPendente() === [], 'quem respondeu sai dos pendentes');

$o2 = Distribuicoes::criarOferta($d, ['motoboy_uuid' => 'd-b', 'tempo_s' => 700, 'encaixe' => true, 'aproximado' => true], 2);
confere(Distribuicoes::ofertaParaAceite('order-1', 'd-b')?->id === $o2->id, 'ofertaParaAceite: a pendente dele');
confere(Distribuicoes::ofertaParaAceite('order-1', 'd-a') === null, 'quem recusou não tem oferta para aceitar');
Distribuicoes::responder($o2->id, 'vencida');
confere(Distribuicoes::ofertaParaAceite('order-1', 'd-b')?->id === $o2->id, 'vencida, mas ninguém foi oferecido depois: ainda vale');
$o3 = Distribuicoes::criarOferta($d, ['motoboy_uuid' => 'd-c', 'tempo_s' => 900, 'encaixe' => false, 'aproximado' => false], 3);
confere(Distribuicoes::ofertaParaAceite('order-1', 'd-b') === null, 'depois que outro foi oferecido, a vencida não vale mais');

Distribuicoes::gravarFila(1, [['motoboy_uuid' => 'd-c', 'tempo_s' => 900]]);
confere(json_decode(Distribuicoes::doPedido('order-1')->fila, true)[0]['tempo_s'] === 900, 'gravarFila (JSON)');

Relogio::$agora = '2026-10-07 10:01:40';
Distribuicoes::mudarFase(1, 'aberta', 'fila_esgotada');
$d = DB::table('entregas_distribuicoes')->where('id', 1)->first();
confere($d->fase === 'aberta' && $d->motivo === 'fila_esgotada' && $d->aberta_em === '2026-10-07 10:01:40' && $d->encerrada_em === null, 'mudarFase aberta grava aberta_em');
confere(Distribuicoes::cancelarPendentes(1) === 1 && DB::table('entregas_ofertas')->where('id', $o3->id)->value('resposta') === 'cancelada', 'cancelarPendentes');
confere(Distribuicoes::emOfertas('order-1') === false && Distribuicoes::doPedido('order-1')?->id === 1, 'aberta: não está em ofertas, mas é a do pedido');
Distribuicoes::mudarFase(1, 'encerrada', 'atribuida');
$d = DB::table('entregas_distribuicoes')->where('id', 1)->first();
confere($d->fase === 'encerrada' && $d->encerrada_em === '2026-10-07 10:01:40' && Distribuicoes::doPedido('order-1') === null, 'encerrada: grava encerrada_em e some do doPedido');

Relogio::$agora = '2026-10-07 10:05:00';
$d2 = Distribuicoes::criar(pedidoAberto());
Distribuicoes::criarOferta($d2, ['motoboy_uuid' => 'd-a', 'tempo_s' => 1, 'encaixe' => false, 'aproximado' => false], 1);
Relogio::$agora = '2026-10-07 10:06:00';
confere(array_map(fn ($o) => $o->id, Distribuicoes::pendentesVencidasHa(20)) === [4], 'pendentesVencidasHa: a que venceu há mais de 20 s');
confere(Distribuicoes::pendentesVencidasHa(60) === [], 'folga maior: nenhuma');
Relogio::$agora = '2026-10-07 10:08:30';
confere(array_map(fn ($d) => $d->id, Distribuicoes::emOfertasHaMais(3)) === [2], 'emOfertasHaMais: despachada há mais de 3 min');
confere(array_map(fn ($d) => $d->id, Distribuicoes::naoEncerradas()) === [2], 'naoEncerradas');
confere(Distribuicoes::data('2026-10-07 10:06:00')->toIso8601String() === \Carbon\Carbon::parse('2026-10-07 10:06:00', date_default_timezone_get())->toIso8601String() && Distribuicoes::data(null) === null, 'data: texto do banco no fuso do app');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-ciclo.php`
Expected: erro de PHP (classe `Distribuicoes` não encontrada).

- [ ] **Step 3: Implementar**

```php
<?php

namespace App\Support\Entregas\Distribuicao;

use Carbon\Carbon;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * As tabelas entregas_distribuicoes e entregas_ofertas (query builder, como PedidosIfood). Datas em TIMESTAMP gravadas
 * como texto no fuso do app e lidas nesse fuso (ver "Fuso" no CLAUDE.md). Sem regra de negócio: isso é o Distribuidor.
 */
class Distribuicoes
{
    public const TABELA  = 'entregas_distribuicoes';
    public const OFERTAS = 'entregas_ofertas';

    public static function agora(): string
    {
        return now()->format('Y-m-d H:i:s');
    }

    public static function data(?string $texto): ?Carbon
    {
        return $texto ? Carbon::parse(substr($texto, 0, 19), date_default_timezone_get()) : null;
    }

    /** A distribuição não encerrada do pedido (ofertas ou aberta), ou null. */
    public static function doPedido(string $pedidoUuid): ?object
    {
        return DB::table(static::TABELA)->where('pedido_uuid', $pedidoUuid)->whereIn('fase', [Distribuicao::FASE_OFERTAS, Distribuicao::FASE_ABERTA])->orderBy('id', 'desc')->first();
    }

    public static function porId(int $id): ?object
    {
        return DB::table(static::TABELA)->where('id', $id)->first();
    }

    public static function emOfertas(string $pedidoUuid): bool
    {
        return DB::table(static::TABELA)->where('pedido_uuid', $pedidoUuid)->where('fase', Distribuicao::FASE_OFERTAS)->exists();
    }

    public static function criar(Order $pedido): object
    {
        $agora = static::agora();
        $id    = DB::table(static::TABELA)->insertGetId([
            'pedido_uuid'   => (string) $pedido->uuid,
            'company_uuid'  => (string) $pedido->company_uuid,
            'despachada_em' => $agora,
            'fase'          => Distribuicao::FASE_OFERTAS,
            'created_at'    => $agora,
            'updated_at'    => $agora,
        ]);

        return static::porId($id);
    }

    public static function mudarFase(int $id, string $fase, string $motivo): void
    {
        $agora  = static::agora();
        $campos = ['fase' => $fase, 'motivo' => $motivo, 'updated_at' => $agora];
        if ($fase === Distribuicao::FASE_ABERTA) {
            $campos['aberta_em'] = $agora;
        }
        if ($fase === Distribuicao::FASE_ENCERRADA) {
            $campos['encerrada_em'] = $agora;
        }
        DB::table(static::TABELA)->where('id', $id)->update($campos);
    }

    public static function gravarFila(int $id, array $fila): void
    {
        DB::table(static::TABELA)->where('id', $id)->update(['fila' => json_encode($fila, JSON_UNESCAPED_UNICODE), 'updated_at' => static::agora()]);
    }

    /** @param array{motoboy_uuid: string, tempo_s: int, encaixe: bool, aproximado: bool} $candidato */
    public static function criarOferta(object $distribuicao, array $candidato, int $posicao): object
    {
        $agora = now();
        $id    = DB::table(static::OFERTAS)->insertGetId([
            'distribuicao_id'  => (int) $distribuicao->id,
            'pedido_uuid'      => (string) $distribuicao->pedido_uuid,
            'motoboy_uuid'     => $candidato['motoboy_uuid'],
            'posicao'          => $posicao,
            'tempo_estimado_s' => (int) $candidato['tempo_s'],
            'encaixe'          => (bool) $candidato['encaixe'],
            'aproximado'       => (bool) $candidato['aproximado'],
            'oferecida_em'     => $agora->format('Y-m-d H:i:s'),
            'vence_em'         => $agora->copy()->addSeconds(Distribuicao::SEGUNDOS_DA_OFERTA)->format('Y-m-d H:i:s'),
            'resposta'         => Distribuicao::PENDENTE,
            'created_at'       => $agora->format('Y-m-d H:i:s'),
            'updated_at'       => $agora->format('Y-m-d H:i:s'),
        ]);

        return static::oferta($id);
    }

    public static function oferta(int $id): ?object
    {
        return DB::table(static::OFERTAS)->where('id', $id)->first();
    }

    public static function ofertaPendente(int $distribuicaoId): ?object
    {
        return DB::table(static::OFERTAS)->where('distribuicao_id', $distribuicaoId)->where('resposta', Distribuicao::PENDENTE)->orderBy('id', 'desc')->first();
    }

    /** As ofertas da distribuição, na ordem (o histórico do painel). */
    public static function ofertas(int $distribuicaoId): array
    {
        return DB::table(static::OFERTAS)->where('distribuicao_id', $distribuicaoId)->orderBy('id')->get()->all();
    }

    public static function responder(int $ofertaId, string $resposta): void
    {
        DB::table(static::OFERTAS)->where('id', $ofertaId)->update(['resposta' => $resposta, 'respondida_em' => static::agora(), 'updated_at' => static::agora()]);
    }

    /** Marca canceladas as pendentes da distribuição; devolve quantas. */
    public static function cancelarPendentes(int $distribuicaoId): int
    {
        return DB::table(static::OFERTAS)->where('distribuicao_id', $distribuicaoId)->where('resposta', Distribuicao::PENDENTE)
            ->update(['resposta' => Distribuicao::CANCELADA, 'respondida_em' => static::agora(), 'updated_at' => static::agora()]);
    }

    /** @return array<string> uuids de quem recusou ou deixou vencer nesta distribuição */
    public static function motoboysQueResponderam(int $distribuicaoId): array
    {
        return array_values(array_unique(DB::table(static::OFERTAS)->where('distribuicao_id', $distribuicaoId)->whereIn('resposta', [Distribuicao::RECUSADA, Distribuicao::VENCIDA])->pluck('motoboy_uuid')->all()));
    }

    /** @return array<string> uuids com oferta pendente em qualquer pedido */
    public static function motoboysComOfertaPendente(): array
    {
        return array_values(array_unique(DB::table(static::OFERTAS)->where('resposta', Distribuicao::PENDENTE)->pluck('motoboy_uuid')->all()));
    }

    /**
     * A oferta que autoriza o motoboy a aceitar o pedido: a pendente dele ou, se a dele venceu, a vencida enquanto ninguém
     * foi oferecido depois (o job venceu antes do toque chegar). Null = não pode.
     */
    public static function ofertaParaAceite(string $pedidoUuid, string $motoboyUuid): ?object
    {
        $distribuicao = static::doPedido($pedidoUuid);
        if (!$distribuicao || $distribuicao->fase !== Distribuicao::FASE_OFERTAS) {
            return null;
        }
        $ultima = DB::table(static::OFERTAS)->where('distribuicao_id', $distribuicao->id)->orderBy('id', 'desc')->first();
        if (!$ultima || (string) $ultima->motoboy_uuid !== $motoboyUuid) {
            return null;
        }

        return in_array($ultima->resposta, [Distribuicao::PENDENTE, Distribuicao::VENCIDA], true) ? $ultima : null;
    }

    /** Pendentes cujo vence_em passou há mais de $folga segundos (o job não veio). */
    public static function pendentesVencidasHa(int $folga): array
    {
        $limite = now()->subSeconds($folga)->format('Y-m-d H:i:s');

        return DB::table(static::OFERTAS)->where('resposta', Distribuicao::PENDENTE)->where('vence_em', '<', $limite)->orderBy('id')->get()->all();
    }

    public static function emOfertasHaMais(int $minutos): array
    {
        $limite = now()->subMinutes($minutos)->format('Y-m-d H:i:s');

        return DB::table(static::TABELA)->where('fase', Distribuicao::FASE_OFERTAS)->where('despachada_em', '<', $limite)->orderBy('id')->get()->all();
    }

    public static function naoEncerradas(): array
    {
        return DB::table(static::TABELA)->whereIn('fase', [Distribuicao::FASE_OFERTAS, Distribuicao::FASE_ABERTA])->orderBy('id')->get()->all();
    }
}
```

A `Teste\Consulta` compara `where('vence_em', '<', $limite)` como texto; `Y-m-d H:i:s` ordena certo. O `now()` dos stubs lê `Teste\Relogio::$agora`.

- [ ] **Step 4: Rodar e ver passar**

Run: `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-ciclo.php`
Expected: `FALHAS: 0`.

- [ ] **Step 5: Commit**

```bash
git add api/app/Support/Entregas/Distribuicao/Distribuicoes.php scripts/teste-php/distribuicao-ciclo.php
git commit -m "Distribuição: leitura e gravação das tabelas"
```

---

### Task 7: Push da oferta (`OfertaDePedido` e `AvisosDoMotoboy`)

**Files:**
- Create: `api/app/Notifications/Entregas/OfertaDePedido.php`
- Modify: `api/app/Notifications/Entregas/AvisosDoMotoboy.php` (`texto()`, `comoDados()`, `adaptar()`)
- Test: `scripts/teste-php/avisos-push.php` (já existe; usa `stubs.php`)

O push da oferta é o alarme de pedido aberto de hoje (`type: order_ping`, cartão com valor, km, mapa) com três diferenças: título "Oferta para você", os dados `entregas_oferta=1` e `entregas_oferta_vence_em` (ISO), e TTL de 30 s em vez de 15 min.

- [ ] **Step 1: Teste (acrescente a `avisos-push.php`, antes do `resumo()`; siga o padrão dos casos de `LembretePedidoAberto` que já estão lá)**

```php
echo '== Oferta de pedido (distribuição)' . PHP_EOL;
$venceEm = \Carbon\Carbon::parse('2026-10-07 10:00:30', date_default_timezone_get());
$oferta  = new \App\Notifications\Entregas\OfertaDePedido($pedido, 850, $venceEm);
$mensagem = \App\Notifications\Entregas\AvisosDoMotoboy::adaptar($oferta, mensagemFcm(['type' => 'order_ping', 'id' => $pedido->public_id]));
confere($mensagem->data['title'] === 'Oferta para você' && str_contains($mensagem->data['body'], '850 m'), 'título da oferta e a distância da coleta');
confere($mensagem->data['entregas_oferta'] === '1' && $mensagem->data['entregas_oferta_vence_em'] === $venceEm->toIso8601String(), 'dados da oferta');
confere($mensagem->custom['android']['ttl'] === '30s', 'vence em 30 s (android.ttl)');
confere($mensagem->data['type'] === 'order_ping', 'continua um pedido aberto para o app (type order_ping)');
```

Use o mesmo pedido falso e o mesmo helper que montam a `FcmMessage` nos casos existentes do arquivo (se o helper tiver outro nome, troque `mensagemFcm`).

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/avisos-push.php`
Expected: erro de PHP (classe `OfertaDePedido` não encontrada).

- [ ] **Step 3: `OfertaDePedido`**

```php
<?php

namespace App\Notifications\Entregas;

use Carbon\Carbon;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\OrderPing;

/**
 * Entregas RestaurantePro: a oferta de um pedido aberto a um motoboy (ver App\Support\Entregas\Distribuicao\Distribuidor).
 *
 * É o OrderPing do Fleet-Ops (`order_ping` no push, `order.ping` no socket: o app trata como pedido novo e o cartão do
 * alarme mostra Aceitar e Recusar), com o título da oferta, os dados `entregas_oferta`/`entregas_oferta_vence_em` e o
 * TTL de SEGUNDOS_DA_OFERTA (AvisosDoMotoboy).
 */
class OfertaDePedido extends OrderPing
{
    public function __construct(Order $order, $distance, public Carbon $venceEm)
    {
        parent::__construct($order, $distance);

        $this->title   = 'Oferta para você';
        $this->message = AvisosDoMotoboy::textoDaColeta($distance);
    }

    /** Os dados extras do push (strings, como o FCM exige). */
    public function dadosDaOferta(): array
    {
        return ['entregas_oferta' => '1', 'entregas_oferta_vence_em' => $this->venceEm->toIso8601String()];
    }
}
```

- [ ] **Step 4: `AvisosDoMotoboy`**

Três mudanças:

1. No `match` de `texto()`, **antes** da linha do `LembretePedidoAberto`:

```php
            $notificacao instanceof OfertaDePedido       => [$notificacao->title, $notificacao->message],
```

2. Em `adaptar()`, na chamada `static::comoDados($mensagem, $titulo, $corpo, static::cartao($notificacao))`, passe os extras e a validade:

```php
            return static::comoDados($mensagem, $titulo, $corpo, array_merge(static::cartao($notificacao), static::extras($notificacao)), static::validade($notificacao));
```

e, no ramo do alarme comum (`$mensagem->custom['android']['ttl'] = self::VALIDADE_ALARME;`), use `static::validade($notificacao)` no lugar da constante.

3. Dois métodos novos e a assinatura do `comoDados`:

```php
    /** Dados extras do push além do cartão: hoje, só os da oferta (OfertaDePedido). */
    protected static function extras(Notification $notificacao): array
    {
        return $notificacao instanceof OfertaDePedido ? $notificacao->dadosDaOferta() : [];
    }

    /** O android.ttl do alarme: a oferta vence em SEGUNDOS_DA_OFERTA; o resto em VALIDADE_ALARME. */
    protected static function validade(Notification $notificacao): string
    {
        return $notificacao instanceof OfertaDePedido ? Distribuicao::SEGUNDOS_DA_OFERTA . 's' : self::VALIDADE_ALARME;
    }

    protected static function comoDados(FcmMessage $mensagem, string $titulo, string $corpo, array $cartao = [], string $validade = self::VALIDADE_ALARME): FcmMessage
```

e, dentro de `comoDados`, `['priority' => 'high', 'ttl' => $validade]`. Acrescente `use App\Support\Entregas\Distribuicao\Distribuicao;` no topo.

- [ ] **Step 5: Rodar e ver passar**

Run: `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/avisos-push.php`
Expected: `FALHAS: 0` (os casos antigos continuam passando).

- [ ] **Step 6: Commit**

```bash
git add api/app/Notifications/Entregas/OfertaDePedido.php api/app/Notifications/Entregas/AvisosDoMotoboy.php scripts/teste-php/avisos-push.php
git commit -m "Distribuição: push da oferta (30 s, dados entregas_oferta*)"
```

---

### Task 8: `Distribuidor` e o job `AvancarOferta`

**Files:**
- Create: `api/app/Support/Entregas/Distribuicao/Distribuidor.php`
- Create: `api/app/Jobs/Entregas/AvancarOferta.php`
- Test: `scripts/teste-php/distribuicao-ciclo.php`

Regras de trava: `iniciar`, `avancar`, `vencer`, `recusar` e `abrirATodos` tomam a `TravaDoPedido` (a mesma do aceite e do cancelamento) e relêem a distribuição com ela. `encerrar` e `registrarAceite` **não** tomam: rodam dentro de quem já a segura (o aceite no middleware; a `TrocaDoMotoboy`) e são um `UPDATE` só. O `Distribuidor` nunca lança para o chamador do despacho: o listener captura e cai no alarme geral.

- [ ] **Step 1: Teste do ciclo (acrescente a `distribuicao-ciclo.php`, antes do `resumo()`)**

```php
echo '== Distribuidor' . PHP_EOL;
use App\Jobs\Entregas\AvancarOferta;
use App\Notifications\Entregas\OfertaDePedido;
use App\Support\Entregas\Distribuicao\Candidatos;
use App\Support\Entregas\Distribuicao\Distribuidor;
use App\Support\Entregas\Distribuicao\EstimadorDeTempo;
use App\Support\Entregas\Distribuicao\FilaDeCandidatos;
use App\Support\Entregas\Distribuicao\Pontos;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Notifications\OrderPing;
use Illuminate\Support\Facades\Log;
use Teste\Config;
use Teste\DespachoPendente;
use Teste\Fila;
use Teste\Trava;

function ponto(array $p): object
{
    return new class($p) { public function __construct(private array $p) {} public function getLat() { return $this->p[0]; } public function getLng() { return $this->p[1]; } };
}

/** Pedido aberto com coleta e entrega e dois motoboys livres (A a 200 m, B a 1 km). Devolve o Distribuidor. */
function cenario(): Distribuidor
{
    reiniciarFleetbase();
    reiniciarIfood();
    Relogio::$agora = '2026-10-07 10:00:00';
    Config::$valores['services.entregas.distribuicao'] = '1';
    session(['company' => 'empresa-1']);
    $pedido          = pedidoAberto();
    $pedido->payload = (object) ['pickup' => (object) ['location' => ponto([-21.1700, -47.8100])], 'dropoff' => (object) ['location' => ponto([-21.1800, -47.8100])]];
    Order::$todos[]  = $pedido;
    $a = new Driver(['uuid' => 'd-a', 'public_id' => 'driver_a', 'company_uuid' => 'empresa-1', 'name' => 'Ana', 'online' => true, 'location' => ponto([-21.1682, -47.8100])]);
    $b = new Driver(['uuid' => 'd-b', 'public_id' => 'driver_b', 'company_uuid' => 'empresa-1', 'name' => 'Bia', 'online' => true, 'location' => ponto([-21.1610, -47.8100])]);
    Driver::$todos  = [$a, $b];
    Candidatos::$buscarMotoboys = fn () => [
        ['motoboy' => $a, 'posicao' => [-21.1682, -47.8100], 'distancia' => 200.0],
        ['motoboy' => $b, 'posicao' => [-21.1610, -47.8100], 'distancia' => 1000.0],
    ];
    Candidatos::$buscarParadas = fn () => [];
    $estimador = new class extends EstimadorDeTempo {
        public function matriz(array $pontos): array { $m = []; foreach ($pontos as $i => $a) { foreach ($pontos as $j => $b) { $m[$i][$j] = (float) Pontos::segundos($a, $b); } } return ['durations' => $m, 'aproximado' => false]; }
    };

    return new Distribuidor(new FilaDeCandidatos($estimador));
}

function pedidoDoCenario(): Order { return Order::$todos[0]; }
function ofertas(): array { return DB::table('entregas_ofertas')->orderBy('id')->get()->all(); }
function distribuicao(): ?object { return DB::table('entregas_distribuicoes')->where('id', 1)->first(); }
function avisos(): array { return array_map(fn ($a) => [$a[0], get_class($a[1])], Driver::$avisos); }

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
confere(distribuicao()->fase === 'ofertas' && count(ofertas()) === 1 && ofertas()[0]->motoboy_uuid === 'd-a' && ofertas()[0]->resposta === 'pendente', 'iniciar: distribuição em ofertas e a 1ª oferta ao mais rápido (A)');
confere(avisos() === [['driver_a', OfertaDePedido::class]] && Driver::$avisos[0][1]->venceEm->format('H:i:s') === '10:00:30', 'push da oferta a A, vencendo em 30 s');
confere(count(Fila::$jobs) === 1 && Fila::$jobs[0] instanceof AvancarOferta && Fila::$jobs[0]->ofertaId === 1 && DespachoPendente::$atrasos[0] === 30, 'job AvancarOferta atrasado 30 s');
confere(json_decode(distribuicao()->fila, true)[0]['public_id'] === 'driver_a' && count(json_decode(distribuicao()->fila, true)) === 2, 'a fila calculada fica gravada');
confere(!isset(Trava::$ocupadas['entregas:pedido:order-1']), 'a trava do pedido é solta');
confere(logou('oferta enviada', 'info'), 'log: oferta enviada');

// recusa de A: passa a B na hora
Relogio::$agora = '2026-10-07 10:00:10';
confere($dist->recusar('order-1', Driver::$todos[0]) === true, 'recusar: a oferta pendente dele');
confere(ofertas()[0]->resposta === 'recusada' && ofertas()[1]->motoboy_uuid === 'd-b' && ofertas()[1]->resposta === 'pendente' && ofertas()[1]->posicao === 2, 'recusou: a 2ª oferta vai a B');
confere(avisos()[1] === ['driver_b', OfertaDePedido::class], 'push a B');
confere($dist->recusar('order-1', Driver::$todos[0]) === false, 'recusar de novo (sem oferta pendente dele): false');

// o job vence a oferta de B: fila esgotada → aberta a todos (OrderPing comum a todos no raio, inclusive a quem recusou)
Relogio::$agora = '2026-10-07 10:00:45';
(new AvancarOferta(2))->handle($dist);
confere(ofertas()[1]->resposta === 'vencida', 'job: a oferta pendente vence');
confere(distribuicao()->fase === 'aberta' && distribuicao()->motivo === 'fila_esgotada' && distribuicao()->aberta_em === '2026-10-07 10:00:45', 'fila esgotada: aberta a todos');
confere(array_slice(avisos(), 2) === [['driver_a', OrderPing::class], ['driver_b', OrderPing::class]], 'OrderPing comum a todos no raio');
confere(logou('aberta a todos', 'info'), 'log: aberta a todos');

// job velho (oferta já respondida): nada
$antes = count(Driver::$avisos);
(new AvancarOferta(1))->handle($dist);
confere(count(Driver::$avisos) === $antes && count(ofertas()) === 2, 'job de oferta já respondida: não faz nada');

// encerrar (central atribuiu) e registrar aceite
$dist->encerrar('order-1', 'atribuida');
confere(distribuicao()->fase === 'encerrada' && distribuicao()->motivo === 'atribuida', 'encerrar: fase encerrada com o motivo');
$dist->encerrar('order-1', 'cancelada');
confere(distribuicao()->motivo === 'atribuida', 'encerrar de novo não muda o motivo');

echo '== Prazo de 3 min e o aceite' . PHP_EOL;
$dist = cenario();
$dist->iniciar(pedidoDoCenario());
Relogio::$agora = '2026-10-07 10:03:05';
$dist->avancar('order-1');
confere(distribuicao()->fase === 'aberta' && distribuicao()->motivo === 'prazo' && ofertas()[0]->resposta === 'cancelada', 'passados 3 min do despacho, abre a todos (prazo) e cancela a pendente');

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
$oferta = Distribuicoes::ofertaParaAceite('order-1', 'd-a');
$dist->registrarAceite($oferta);
confere(ofertas()[0]->resposta === 'aceita' && distribuicao()->fase === 'encerrada' && distribuicao()->motivo === 'aceita', 'registrarAceite: oferta aceita e distribuição encerrada (aceita)');

echo '== Uma oferta por vez por motoboy' . PHP_EOL;
$dist = cenario();
$dist->iniciar(pedidoDoCenario());
$outro          = pedidoAberto(['uuid' => 'order-2', 'public_id' => 'order_2']);
$outro->payload = pedidoDoCenario()->payload;
Order::$todos[] = $outro;
$dist->iniciar($outro);
$doOutro = array_values(array_filter(ofertas(), fn ($o) => $o->pedido_uuid === 'order-2'));
confere(count($doOutro) === 1 && $doOutro[0]->motoboy_uuid === 'd-b', 'A tem oferta pendente do 1º pedido: o 2º vai a B');

echo '== Sem candidato, abrir pela central, redespacho, desligada' . PHP_EOL;
$dist = cenario();
Candidatos::$buscarMotoboys = fn () => [];
$dist->iniciar(pedidoDoCenario());
confere(distribuicao()->fase === 'aberta' && distribuicao()->motivo === 'sem_candidato' && ofertas() === [], 'sem candidato: abre na hora');

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
confere($dist->abrirATodos('order-1', 'aberta_pela_central') === true && distribuicao()->motivo === 'aberta_pela_central' && ofertas()[0]->resposta === 'cancelada', 'abrir pela central');
confere($dist->abrirATodos('order-1', 'aberta_pela_central') === false, 'já aberta: false');

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
Relogio::$agora = '2026-10-07 10:00:20';
$dist->iniciar(pedidoDoCenario());
confere(distribuicao()->fase === 'encerrada' && distribuicao()->motivo === 'redespachada' && DB::table('entregas_distribuicoes')->where('id', 2)->value('fase') === 'ofertas', 'redespacho: encerra a anterior e cria outra');

$dist = cenario();
Config::$valores['services.entregas.distribuicao'] = '';
confere(Distribuicao::ligada() === false, 'desligada');
$dist->iniciar(pedidoDoCenario());
confere(distribuicao() === null && Driver::$avisos === [], 'desligada: iniciar não faz nada (o listener chama o Fleet-Ops)');

echo '== Trava ocupada' . PHP_EOL;
$dist = cenario();
Trava::$ocupadas['entregas:pedido:order-1'] = true;
confere(excecao(fn () => $dist->iniciar(pedidoDoCenario())) instanceof \Illuminate\Contracts\Cache\LockTimeoutException, 'iniciar com a trava ocupada lança (o listener trata)');
unset(Trava::$ocupadas['entregas:pedido:order-1']);
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-ciclo.php`
Expected: erro de PHP (classe `Distribuidor` não encontrada).

- [ ] **Step 3: `Distribuidor`**

```php
<?php

namespace App\Support\Entregas\Distribuicao;

use App\Jobs\Entregas\AvancarOferta;
use App\Notifications\Entregas\OfertaDePedido;
use App\Support\Entregas\TravaDoPedido;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\OrderPing;
use Illuminate\Support\Facades\Log;

/**
 * O ciclo da distribuição de um pedido aberto (ver Distribuicao):
 *
 * - iniciar: encerra a distribuição anterior do pedido (redespacho), cria outra em `ofertas` e avança;
 * - avancar: passado o prazo, abre a todos; senão recalcula a fila (fora quem já respondeu neste despacho e quem tem
 *   oferta pendente de outro pedido), oferece ao primeiro (push OfertaDePedido + job AvancarOferta em 30 s) ou, sem
 *   candidato, abre a todos;
 * - recusar / vencer: a resposta da oferta pendente e o próximo passo;
 * - abrirATodos: fase `aberta` e o OrderPing comum a todos no raio (daí em diante vale o que já existe: reenvios e o
 *   aviso "sem motoboy");
 * - encerrar e registrarAceite: um UPDATE só, sem a trava (rodam dentro de quem já a segura).
 *
 * iniciar, avancar, vencer, recusar e abrirATodos rodam sob a TravaDoPedido e relêem a distribuição com ela. Lançam
 * LockTimeoutException se a trava não sair: o chamador decide (o listener cai no alarme geral; o job volta à fila).
 */
class Distribuidor
{
    public function __construct(protected FilaDeCandidatos $fila) {}

    public function iniciar(Order $pedido): void
    {
        if (!Distribuicao::ligada()) {
            return;
        }
        TravaDoPedido::executar((string) $pedido->uuid, function () use ($pedido) {
            $anterior = Distribuicoes::doPedido((string) $pedido->uuid);
            if ($anterior) {
                $this->encerrarSemTrava($anterior, Distribuicao::REDESPACHADA);
            }
            $distribuicao = Distribuicoes::criar($pedido);
            Log::info('[entregas] distribuição: iniciada', ['pedido' => $pedido->public_id, 'distribuicao' => $distribuicao->id]);
            $this->avancarSemTrava($distribuicao, $pedido);
        });
    }

    public function avancar(string $pedidoUuid): void
    {
        TravaDoPedido::executar($pedidoUuid, function () use ($pedidoUuid) {
            $distribuicao = Distribuicoes::doPedido($pedidoUuid);
            $pedido       = $distribuicao ? Order::where('uuid', $pedidoUuid)->first() : null;
            if ($distribuicao && $pedido) {
                $this->avancarSemTrava($distribuicao, $pedido);
            }
        });
    }

    /** O job: a oferta pendente venceu. */
    public function vencer(int $ofertaId): void
    {
        $oferta = Distribuicoes::oferta($ofertaId);
        if (!$oferta || $oferta->resposta !== Distribuicao::PENDENTE) {
            return;
        }
        TravaDoPedido::executar((string) $oferta->pedido_uuid, function () use ($ofertaId) {
            $oferta = Distribuicoes::oferta($ofertaId);
            if (!$oferta || $oferta->resposta !== Distribuicao::PENDENTE) {
                return;
            }
            Distribuicoes::responder($ofertaId, Distribuicao::VENCIDA);
            Log::info('[entregas] distribuição: oferta vencida', ['oferta' => $ofertaId, 'motoboy' => $oferta->motoboy_uuid]);
            $this->proximoPasso((string) $oferta->pedido_uuid);
        });
    }

    /** O motoboy recusou. False se ele não tem oferta pendente neste pedido. */
    public function recusar(string $pedidoUuid, Driver $motoboy): bool
    {
        return TravaDoPedido::executar($pedidoUuid, function () use ($pedidoUuid, $motoboy) {
            $distribuicao = Distribuicoes::doPedido($pedidoUuid);
            $oferta       = $distribuicao ? Distribuicoes::ofertaPendente((int) $distribuicao->id) : null;
            if (!$oferta || (string) $oferta->motoboy_uuid !== (string) $motoboy->uuid) {
                return false;
            }
            Distribuicoes::responder((int) $oferta->id, Distribuicao::RECUSADA);
            Log::info('[entregas] distribuição: oferta recusada', ['oferta' => $oferta->id, 'motoboy' => $motoboy->public_id]);
            $this->proximoPasso($pedidoUuid);

            return true;
        });
    }

    /** Abre a todos (central ou varredura). False se a distribuição não está em ofertas. */
    public function abrirATodos(string $pedidoUuid, string $motivo): bool
    {
        return TravaDoPedido::executar($pedidoUuid, function () use ($pedidoUuid, $motivo) {
            $distribuicao = Distribuicoes::doPedido($pedidoUuid);
            $pedido       = $distribuicao ? Order::where('uuid', $pedidoUuid)->first() : null;
            if (!$distribuicao || !$pedido || $distribuicao->fase !== Distribuicao::FASE_OFERTAS) {
                return false;
            }
            $this->abrirSemTrava($distribuicao, $pedido, $motivo);

            return true;
        });
    }

    /** Sem a trava: pedido ganhou motoboy ou foi encerrado. Não muda uma distribuição já encerrada. */
    public function encerrar(string $pedidoUuid, string $motivo): void
    {
        $distribuicao = Distribuicoes::doPedido($pedidoUuid);
        if ($distribuicao) {
            $this->encerrarSemTrava($distribuicao, $motivo);
        }
    }

    /** Sem a trava (o middleware do aceite já a segura): a oferta foi aceita. */
    public function registrarAceite(object $oferta): void
    {
        Distribuicoes::responder((int) $oferta->id, Distribuicao::ACEITA);
        Distribuicoes::cancelarPendentes((int) $oferta->distribuicao_id);
        Distribuicoes::mudarFase((int) $oferta->distribuicao_id, Distribuicao::FASE_ENCERRADA, Distribuicao::ACEITA);
        Log::info('[entregas] distribuição: encerrada (aceita)', ['oferta' => $oferta->id, 'motoboy' => $oferta->motoboy_uuid]);
    }

    protected function proximoPasso(string $pedidoUuid): void
    {
        $distribuicao = Distribuicoes::doPedido($pedidoUuid);
        $pedido       = $distribuicao ? Order::where('uuid', $pedidoUuid)->first() : null;
        if ($distribuicao && $pedido) {
            $this->avancarSemTrava($distribuicao, $pedido);
        }
    }

    protected function avancarSemTrava(object $distribuicao, Order $pedido): void
    {
        if ($distribuicao->fase !== Distribuicao::FASE_OFERTAS) {
            return;
        }
        if (Distribuicoes::ofertaPendente((int) $distribuicao->id)) {
            return; // alguém ainda está decidindo
        }
        $despachada = Distribuicoes::data($distribuicao->despachada_em);
        if ($despachada && now()->greaterThanOrEqualTo($despachada->addMinutes(Distribuicao::MINUTOS_ATE_ABRIR))) {
            $this->abrirSemTrava($distribuicao, $pedido, Distribuicao::PRAZO);

            return;
        }

        $excluidos = array_merge(Distribuicoes::motoboysQueResponderam((int) $distribuicao->id), Distribuicoes::motoboysComOfertaPendente());
        $fila      = $this->fila->para($pedido, Candidatos::elegiveis($pedido, $excluidos));
        Distribuicoes::gravarFila((int) $distribuicao->id, $fila);

        if ($fila === []) {
            $jaOfereceu = Distribuicoes::ofertas((int) $distribuicao->id) !== [];
            $this->abrirSemTrava($distribuicao, $pedido, $jaOfereceu ? Distribuicao::FILA_ESGOTADA : Distribuicao::SEM_CANDIDATO);

            return;
        }

        $primeiro = $fila[0];
        $motoboy  = Driver::where('uuid', $primeiro['motoboy_uuid'])->first();
        if (!$motoboy) {
            $this->abrirSemTrava($distribuicao, $pedido, Distribuicao::FILA_ESGOTADA);

            return;
        }
        $posicao = count(Distribuicoes::ofertas((int) $distribuicao->id)) + 1;
        $oferta  = Distribuicoes::criarOferta($distribuicao, $primeiro, $posicao);
        AvancarOferta::agendar((int) $oferta->id);
        try {
            $motoboy->notify(new OfertaDePedido($pedido, $primeiro['distancia_m'], Distribuicoes::data($oferta->vence_em)));
        } catch (\Throwable $e) {
            // o push falhou (FCM fora): a oferta vence sozinha e passa ao próximo
            Log::warning('[entregas] distribuição: push da oferta falhou', ['oferta' => $oferta->id, 'erro' => get_class($e)]);
        }
        Log::info('[entregas] distribuição: oferta enviada', ['pedido' => $pedido->public_id, 'oferta' => $oferta->id, 'motoboy' => $motoboy->public_id, 'posicao' => $posicao, 'tempo_s' => $primeiro['tempo_s'], 'encaixe' => $primeiro['encaixe']]);
    }

    protected function abrirSemTrava(object $distribuicao, Order $pedido, string $motivo): void
    {
        Distribuicoes::cancelarPendentes((int) $distribuicao->id);
        Distribuicoes::mudarFase((int) $distribuicao->id, Distribuicao::FASE_ABERTA, $motivo);
        Log::info('[entregas] distribuição: aberta a todos (' . $motivo . ')', ['pedido' => $pedido->public_id, 'distribuicao' => $distribuicao->id]);

        foreach (Candidatos::noRaio($pedido, false) as $candidato) {
            try {
                $candidato['motoboy']->notify(new OrderPing($pedido, $candidato['distancia']));
            } catch (\Throwable $e) {
                Log::warning('[entregas] distribuição: alarme geral falhou para um motoboy', ['motoboy' => $candidato['motoboy']->public_id, 'erro' => get_class($e)]);
            }
        }
    }

    protected function encerrarSemTrava(object $distribuicao, string $motivo): void
    {
        if ($distribuicao->fase === Distribuicao::FASE_ENCERRADA) {
            return;
        }
        Distribuicoes::cancelarPendentes((int) $distribuicao->id);
        Distribuicoes::mudarFase((int) $distribuicao->id, Distribuicao::FASE_ENCERRADA, $motivo);
        Log::info('[entregas] distribuição: encerrada (' . $motivo . ')', ['pedido' => $distribuicao->pedido_uuid, 'distribuicao' => $distribuicao->id]);
    }
}
```

- [ ] **Step 4: `AvancarOferta`**

```php
<?php

namespace App\Jobs\Entregas;

use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Distribuidor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: vence a oferta de um pedido aberto SEGUNDOS_DA_OFERTA depois de enviada e passa ao próximo
 * motoboy (Distribuidor::vencer). Fila `default` (o worker `queue`), com atraso. Um job de oferta já respondida sai sem
 * fazer nada. Reserva para job perdido (Redis sem persistência): o comando entregas:distribuicao-varrer.
 */
class AvancarOferta implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public int $ofertaId)
    {
        $this->afterCommit = true;
    }

    public static function agendar(int $ofertaId): void
    {
        static::dispatch($ofertaId)->delay(now()->addSeconds(Distribuicao::SEGUNDOS_DA_OFERTA));
    }

    public function handle(Distribuidor $distribuidor): void
    {
        if (!Distribuicao::ligada()) {
            return;
        }
        try {
            $distribuidor->vencer($this->ofertaId);
        } catch (LockTimeoutException $e) {
            Log::info('[entregas] distribuição: trava ocupada ao vencer a oferta; tentando de novo', ['oferta' => $this->ofertaId]);
            $this->release(2);
        }
    }
}
```

Se o `InteractsWithQueue` dos stubs não tiver `release`, o teste não passa por esse ramo; confira que `stubs-ifood.php` define o trait com `release($s)` anotando em `Teste\Fila` (o `EnviarAcaoIfood` já usa `release`, então existe).

- [ ] **Step 5: Rodar e ver passar**

Run: `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-ciclo.php`
Expected: `FALHAS: 0`. Pontos de atenção: `Order::where('uuid', ...)` nos stubs usa `Order::$todos`; `Driver::where('uuid', ...)` usa `Driver::$todos` (o `cenario()` preenche os dois). O `logou()` procura o trecho no `Log::$registros`.

- [ ] **Step 6: Commit**

```bash
git add api/app/Support/Entregas/Distribuicao/Distribuidor.php api/app/Jobs/Entregas/AvancarOferta.php scripts/teste-php/distribuicao-ciclo.php
git commit -m "Distribuição: o ciclo das ofertas e o job que vence a oferta"
```

---

### Task 9: Listener no lugar do `HandleOrderDispatched`, observador e provider

**Files:**
- Create: `api/app/Listeners/Entregas/DistribuirPedidoAberto.php`
- Create: `api/app/Listeners/Entregas/ObservadorDaDistribuicao.php`
- Modify: `api/app/Providers/AppServiceProvider.php` (`boot()`)
- Test: `scripts/teste-php/distribuicao-ciclo.php`

O Fleet-Ops registra três listeners no `OrderDispatched` (`packages/fleetops/server/src/Providers/EventServiceProvider.php`: `HandleOrderDispatched`, `SendResourceLifecycleWebhook`, `NotifyOrderEvent`). O Laravel não tira um listener só, então o provider esquece o evento e registra os dois que ficam mais o nosso. Como os providers do Composer dão `boot` antes do `AppServiceProvider`, a troca roda em `$this->app->booted()`, para garantir a ordem. O nosso listener **estende** o original: nos pedidos que não são abertos (ou com a distribuição desligada) chama o `parent::handle`; nos abertos repete a parte do despacho (atividade DISPATCHED, `dispatched_at`) e inicia a distribuição. Se a distribuição falhar (banco, trava), cai no alarme geral do próprio Fleet-Ops.

- [ ] **Step 1: Teste do observador e do listener (acrescente a `distribuicao-ciclo.php`, antes do `resumo()`)**

```php
echo '== ObservadorDaDistribuicao (Order::updated)' . PHP_EOL;
use App\Listeners\Entregas\DistribuirPedidoAberto;
use App\Listeners\Entregas\ObservadorDaDistribuicao;

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
\Teste\Container::$instancias[Distribuidor::class] = $dist;
$pedido                       = pedidoDoCenario();
$pedido->driver_assigned_uuid = 'd-b';
$pedido->alterados            = ['driver_assigned_uuid'];
ObservadorDaDistribuicao::aoAtualizar($pedido);
confere(distribuicao()->fase === 'encerrada' && distribuicao()->motivo === 'atribuida' && ofertas()[0]->resposta === 'cancelada', 'ganhou motoboy: encerra (atribuida) e cancela a oferta pendente');

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
\Teste\Container::$instancias[Distribuidor::class] = $dist;
$pedido            = pedidoDoCenario();
$pedido->status    = 'canceled';
$pedido->alterados = ['status'];
ObservadorDaDistribuicao::aoAtualizar($pedido);
confere(distribuicao()->motivo === 'cancelada', 'cancelado: encerra (cancelada)');

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
$pedido            = pedidoDoCenario();
$pedido->alterados = ['updated_at'];
ObservadorDaDistribuicao::aoAtualizar($pedido);
confere(distribuicao()->fase === 'ofertas', 'outra mudança: nada');
Config::$valores['services.entregas.distribuicao'] = '';
$pedido->status = 'completed'; $pedido->alterados = ['status'];
ObservadorDaDistribuicao::aoAtualizar($pedido);
confere(distribuicao()->fase === 'ofertas', 'desligada: nada');
Config::$valores['services.entregas.distribuicao'] = '1';
$pedido->alterados = ['status'];
\Teste\Container::$instancias[Distribuidor::class] = new class($dist->fila ?? null) extends Distribuidor { public function __construct($f) {} public function encerrar(string $p, string $m): void { throw new \RuntimeException('banco fora'); } };
ObservadorDaDistribuicao::aoAtualizar($pedido);
confere(logou('falha ao encerrar a distribuição', 'warning'), 'erro no observador: só o log (nunca lança)');
unset(\Teste\Container::$instancias[Distribuidor::class]);

echo '== DistribuirPedidoAberto' . PHP_EOL;
$dist = cenario();
\Teste\Container::$instancias[Distribuidor::class] = $dist;
$listener = new class extends DistribuirPedidoAberto { public array $geral = []; protected function alarmeGeral($pedido): void { $this->geral[] = $pedido->public_id; } };
$listener->distribuir(pedidoDoCenario());
confere(distribuicao()->fase === 'ofertas' && $listener->geral === [], 'ligada: inicia a distribuição e não manda o alarme geral');

$dist = cenario();
Trava::$ocupadas['entregas:pedido:order-1'] = true;
\Teste\Container::$instancias[Distribuidor::class] = $dist;
$listener->distribuir(pedidoDoCenario());
confere($listener->geral === ['order_1'] && logou('falha ao iniciar a distribuição', 'error'), 'distribuição falhou (trava ocupada): alarme geral do Fleet-Ops e log');
unset(Trava::$ocupadas['entregas:pedido:order-1'], \Teste\Container::$instancias[Distribuidor::class]);
```

Se a propriedade `fila` do `Distribuidor` for `protected`, a classe anônima do observador compila mesmo assim (o `$dist->fila ?? null` só precisa existir fora: troque por `null` se der erro de acesso).

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-ciclo.php`
Expected: erro de PHP (classe `ObservadorDaDistribuicao` não encontrada).

- [ ] **Step 3: `ObservadorDaDistribuicao`**

```php
<?php

namespace App\Listeners\Entregas;

use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Distribuidor;
use App\Support\Entregas\StatusDoPedido;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: Order::updated → encerra a distribuição do pedido aberto quando ele ganha motoboy (aceite,
 * atribuição pela central, troca pelo líder) ou é encerrado (cancelamento). Registrado no AppServiceProvider, ao lado
 * do ObservadorDosPedidosIfood. Nunca lança: roda dentro da gravação do pedido.
 */
class ObservadorDaDistribuicao
{
    public static function aoAtualizar(object $pedido): void
    {
        try {
            if (!Distribuicao::ligada() || !$pedido->wasChanged(['driver_assigned_uuid', 'status'])) {
                return;
            }
            $motivo = null;
            if ($pedido->wasChanged('driver_assigned_uuid') && $pedido->driver_assigned_uuid) {
                $motivo = Distribuicao::ATRIBUIDA;
            } elseif ($pedido->wasChanged('status') && in_array(strtolower((string) $pedido->status), StatusDoPedido::ENCERRADOS, true)) {
                $motivo = Distribuicao::CANCELADA;
            }
            if ($motivo) {
                app(Distribuidor::class)->encerrar((string) $pedido->uuid, $motivo);
            }
        } catch (\Throwable $e) {
            Log::warning('[entregas] distribuição: falha ao encerrar a distribuição do pedido', ['pedido' => $pedido->public_id ?? null, 'erro' => get_class($e)]);
        }
    }
}
```

O `wasChanged` do stub aceita array e string (`(array) $campos`).

- [ ] **Step 4: `DistribuirPedidoAberto`**

```php
<?php

namespace App\Listeners\Entregas;

use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Distribuidor;
use Fleetbase\FleetOps\Events\OrderDispatched;
use Fleetbase\FleetOps\Listeners\HandleOrderDispatched;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: no lugar do HandleOrderDispatched do Fleet-Ops (trocado no AppServiceProvider). Pedido que
 * não é aberto, ou distribuição desligada: o original. Pedido aberto: a mesma parte do despacho (atividade DISPATCHED,
 * dispatched_at) e, em vez do alarme a todos do raio, a distribuição (Distribuidor::iniciar). Se a distribuição falhar
 * (banco, trava do pedido ocupada), o alarme geral do próprio Fleet-Ops, para o pedido não ficar sem ninguém.
 *
 * Ao atualizar o fleetops-api, confira o handle() do HandleOrderDispatched (a parte repetida aqui) e os métodos
 * protegidos usados: doesntHaveDispatchActivity, getDispatchActivity, nearbyAvailableDrivers e notifyAdhocDriver.
 */
class DistribuirPedidoAberto extends HandleOrderDispatched
{
    public function handle(OrderDispatched $event)
    {
        /** @var Order $order */
        $order = $event->getModelRecord();
        if (!$order || !$order->adhoc || !Distribuicao::ligada()) {
            return parent::handle($event);
        }

        session(['company' => $order->company_uuid]);

        if ($this->doesntHaveDispatchActivity($order)) {
            $activity = $this->getDispatchActivity($order);
            if ($activity) {
                $order->setStatus($activity->code);
                $order->createActivity($activity, $order->getLastLocation());
            }
        }
        $order->dispatched    = true;
        $order->dispatched_at = Carbon::now();
        $order->save();
        $order->flushAttributesCache();
        $order->load(['company', 'payload.pickup', 'payload.dropoff']);

        $this->distribuir($order);
    }

    /** Inicia a distribuição; em falha, o alarme geral. Separado para o teste. */
    public function distribuir(Order $order): void
    {
        try {
            app(Distribuidor::class)->iniciar($order);
        } catch (\Throwable $e) {
            Log::error('[entregas] distribuição: falha ao iniciar a distribuição; alarme geral', ['pedido' => $order->public_id, 'erro' => get_class($e)]);
            $this->alarmeGeral($order);
        }
    }

    /** O que o Fleet-Ops faz no pedido aberto: OrderPing a todos os motoboys livres no raio. */
    protected function alarmeGeral(Order $order): void
    {
        $pickup = $order->getPickupLocation();
        if (!Utils::isPoint($pickup)) {
            return;
        }
        $this->nearbyAvailableDrivers($pickup, $order->getAdhocDistance())->each(function ($driver) use ($order) {
            try {
                $this->notifyAdhocDriver($driver, $order);
            } catch (\Throwable $e) {
                // como o original: segue em silêncio
            }
        });
    }
}
```

Para os stubs: confira que `stubs-ifood-fleetbase.php` tem a classe `Fleetbase\FleetOps\Listeners\HandleOrderDispatched` (vazia, com `handle`, `doesntHaveDispatchActivity`, `getDispatchActivity`, `nearbyAvailableDrivers` e `notifyAdhocDriver` que não fazem nada) e `Fleetbase\FleetOps\Events\OrderDispatched` com `getModelRecord()`. Se não tiver, acrescente no namespace certo:

```php
namespace Fleetbase\FleetOps\Listeners {
    class HandleOrderDispatched
    {
        public static array $originais = [];
        public function handle($event) { self::$originais[] = $event; }
        protected function doesntHaveDispatchActivity($order): bool { return false; }
        protected function getDispatchActivity($order) { return null; }
        protected function nearbyAvailableDrivers($pickup, $distance) { return new \Illuminate\Support\Collection([]); }
        protected function notifyAdhocDriver($driver, $order): void {}
    }
}
namespace Fleetbase\FleetOps\Events {
    class OrderDispatched { public function __construct(public $pedido) {} public function getModelRecord() { return $this->pedido; } }
}
```

- [ ] **Step 5: `AppServiceProvider`**

No `boot()`, depois de `$this->acompanharPedidosIfood();`, acrescente `$this->distribuirPedidosAbertos();` e o método:

```php
    /**
     * Entregas: distribuição de pedidos abertos (ver App\Support\Entregas\Distribuicao). O listener nosso entra no lugar
     * do HandleOrderDispatched do Fleet-Ops; o Laravel não tira um listener só, então o evento é esquecido e os outros
     * dois do EventServiceProvider do Fleet-Ops são registrados de novo. Roda depois de todos os providers (booted),
     * porque os do Composer dão boot antes deste. Ao atualizar o fleetops-api, confira a lista de listeners do
     * OrderDispatched em packages/fleetops/server/src/Providers/EventServiceProvider.php.
     */
    protected function distribuirPedidosAbertos(): void
    {
        Order::updated(fn ($pedido) => ObservadorDaDistribuicao::aoAtualizar($pedido));

        $this->app->booted(function () {
            Event::forget(OrderDispatched::class);
            Event::listen(OrderDispatched::class, SendResourceLifecycleWebhook::class);
            Event::listen(OrderDispatched::class, NotifyOrderEvent::class);
            Event::listen(OrderDispatched::class, DistribuirPedidoAberto::class);
        });
    }
```

Com os `use`:

```php
use App\Listeners\Entregas\DistribuirPedidoAberto;
use App\Listeners\Entregas\ObservadorDaDistribuicao;
use Fleetbase\FleetOps\Events\OrderDispatched;
use Fleetbase\FleetOps\Listeners\NotifyOrderEvent;
use Fleetbase\Listeners\SendResourceLifecycleWebhook;
```

- [ ] **Step 6: Rodar e ver passar; sintaxe**

Run: `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-ciclo.php` → `FALHAS: 0`.
Run: `node scripts/teste-php/sintaxe.mjs` → sem erro.
Run também os testes do iFood que passam pelo provider (`ifood-acoes.php`, `ifood-cancelamento.php`): continuam em `FALHAS: 0`.

- [ ] **Step 7: Commit**

```bash
git add api/app/Listeners/Entregas/DistribuirPedidoAberto.php api/app/Listeners/Entregas/ObservadorDaDistribuicao.php api/app/Providers/AppServiceProvider.php scripts/teste-php/distribuicao-ciclo.php scripts/teste-php/stubs-ifood-fleetbase.php
git commit -m "Distribuição: listener no lugar do alarme geral e encerramento pelo Order::updated"
```

---

### Task 10: Varredura por minuto (`entregas:distribuicao-varrer`)

**Files:**
- Create: `api/app/Console/Commands/Entregas/VarrerDistribuicoes.php`
- Modify: `api/app/Console/Kernel.php`
- Test: `scripts/teste-php/distribuicao-ciclo.php`

- [ ] **Step 1: Teste (acrescente antes do `resumo()`)**

```php
echo '== entregas:distribuicao-varrer' . PHP_EOL;
use App\Console\Commands\Entregas\VarrerDistribuicoes;

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
Relogio::$agora = '2026-10-07 10:00:55';          // a oferta venceu às 10:00:30 e o job não veio (25 s > 20 s de folga)
(new VarrerDistribuicoes())->handle($dist);
confere(ofertas()[0]->resposta === 'vencida' && ofertas()[1]->motoboy_uuid === 'd-b', 'oferta pendente vencida há mais de 20 s: vence e passa ao próximo');

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
Relogio::$agora = '2026-10-07 10:00:40';          // venceu há 10 s: o job ainda pode vir
(new VarrerDistribuicoes())->handle($dist);
confere(ofertas()[0]->resposta === 'pendente', 'vencida há menos de 20 s: espera o job');

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
Distribuicoes::responder(1, 'recusada');          // sem pendente e sem job: a distribuição ficaria presa
Relogio::$agora = '2026-10-07 10:03:10';
(new VarrerDistribuicoes())->handle($dist);
confere(distribuicao()->fase === 'aberta' && distribuicao()->motivo === 'prazo', 'em ofertas há mais de 3 min: abre a todos (prazo)');

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
pedidoDoCenario()->driver_assigned_uuid = 'd-b';  // a central atribuiu e o observador não rodou
(new VarrerDistribuicoes())->handle($dist);
confere(distribuicao()->fase === 'encerrada' && distribuicao()->motivo === 'atribuida', 'pedido já com motoboy: encerra');

$dist = cenario();
$dist->iniciar(pedidoDoCenario());
pedidoDoCenario()->status = 'canceled';
(new VarrerDistribuicoes())->handle($dist);
confere(distribuicao()->motivo === 'cancelada', 'pedido encerrado: encerra (cancelada)');

$dist = cenario();
Config::$valores['services.entregas.distribuicao'] = '';
confere((new VarrerDistribuicoes())->handle($dist) === 0, 'desligada: sai sem agir');
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-ciclo.php`
Expected: erro de PHP (classe `VarrerDistribuicoes` não encontrada).

- [ ] **Step 3: Implementar**

```php
<?php

namespace App\Console\Commands\Entregas;

use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Distribuicoes;
use App\Support\Entregas\Distribuicao\Distribuidor;
use App\Support\Entregas\StatusDoPedido;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: reserva da distribuição de pedidos abertos, a cada minuto (Kernel). O caminho normal é o job
 * AvancarOferta; esta varredura pega o que ficou preso quando o Redis reiniciou (fila sem persistência):
 * - oferta pendente vencida há mais de FOLGA_DA_VARREDURA_S: vence (e o Distribuidor passa ao próximo);
 * - distribuição em ofertas há mais de MINUTOS_ATE_ABRIR sem oferta pendente: abre a todos (prazo);
 * - distribuição não encerrada cujo pedido já tem motoboy, está encerrado ou sumiu: encerra.
 * Uma falha num item não para os outros (log).
 */
class VarrerDistribuicoes extends Command
{
    protected $signature   = 'entregas:distribuicao-varrer';
    protected $description = 'Entregas: vence ofertas presas e abre ou encerra distribuições de pedidos abertos';

    public function handle(Distribuidor $distribuidor): int
    {
        if (!Distribuicao::ligada()) {
            return self::SUCCESS;
        }

        foreach (Distribuicoes::pendentesVencidasHa(Distribuicao::FOLGA_DA_VARREDURA_S) as $oferta) {
            $this->tentar('vencer a oferta', ['oferta' => $oferta->id], fn () => $distribuidor->vencer((int) $oferta->id));
        }

        foreach (Distribuicoes::naoEncerradas() as $distribuicao) {
            $pedido = Order::where('uuid', $distribuicao->pedido_uuid)->first();
            $motivo = match (true) {
                !$pedido                                                                       => Distribuicao::CANCELADA,
                in_array(strtolower((string) $pedido->status), StatusDoPedido::ENCERRADOS, true) => Distribuicao::CANCELADA,
                (bool) $pedido->driver_assigned_uuid                                             => Distribuicao::ATRIBUIDA,
                default                                                                         => null,
            };
            if ($motivo) {
                $this->tentar('encerrar a distribuição', ['distribuicao' => $distribuicao->id], fn () => $distribuidor->encerrar((string) $distribuicao->pedido_uuid, $motivo));
            }
        }

        foreach (Distribuicoes::emOfertasHaMais(Distribuicao::MINUTOS_ATE_ABRIR) as $distribuicao) {
            if (Distribuicoes::ofertaPendente((int) $distribuicao->id)) {
                continue; // a pendente vence pelo job (ou pelo laço acima na próxima rodada)
            }
            $this->tentar('abrir a distribuição', ['distribuicao' => $distribuicao->id], fn () => $distribuidor->abrirATodos((string) $distribuicao->pedido_uuid, Distribuicao::PRAZO));
        }

        return self::SUCCESS;
    }

    protected function tentar(string $acao, array $contexto, \Closure $fazer): void
    {
        try {
            $fazer();
        } catch (\Throwable $e) {
            Log::warning('[entregas] distribuição: varredura não conseguiu ' . $acao, $contexto + ['erro' => get_class($e)]);
        }
    }
}
```

O `Command` falso dos stubs tem `SUCCESS` e os métodos de saída vazios.

- [ ] **Step 4: Kernel**

Em `api/app/Console/Kernel.php`, logo depois da linha do `entregas:ifood-tokens`, acrescente:

```php
        // distribuição de pedidos abertos: reserva do job AvancarOferta (ver VarrerDistribuicoes)
        $schedule->command('entregas:distribuicao-varrer')->everyMinute()->when(fn () => Distribuicao::ligada())->withoutOverlapping(5)->runInBackground()->appendOutputTo(static::SAIDA_DO_CONTAINER);
```

com `use App\Support\Entregas\Distribuicao\Distribuicao;` no topo.

- [ ] **Step 5: Rodar e ver passar; sintaxe**

Run: `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-ciclo.php` → `FALHAS: 0`.
Run: `node scripts/teste-php/sintaxe.mjs`.
Se existir um teste do Kernel (`scripts/teste-php/ifood-agendamento.php` ou parecido, que lê `Teste\EventoAgendado`), rode-o também e acrescente a conferência do comando novo (`everyMinute`, `withoutOverlapping(5)`, `runInBackground`, `appendOutputTo`).

- [ ] **Step 6: Commit**

```bash
git add api/app/Console/Commands/Entregas/VarrerDistribuicoes.php api/app/Console/Kernel.php scripts/teste-php/distribuicao-ciclo.php
git commit -m "Distribuição: varredura por minuto como reserva do job"
```

---

### Task 11: Regra do aceite (`BarrarAceiteDePedidoEncerrado`)

**Files:**
- Modify: `api/app/Http/Middleware/BarrarAceiteDePedidoEncerrado.php`
- Test: `scripts/teste-php/barrar-aceite.php`

Na fase `ofertas`, só quem tem a oferta pendente (ou a vencida, enquanto ninguém foi oferecido depois) aceita. Quem aceita é o motoboy da sessão ou, sem ele, o `assign`. Aceite válido: depois do `$next`, se a resposta for 2xx, `registrarAceite`. Tudo já roda dentro da `TravaDoPedido`.

- [ ] **Step 1: Teste (acrescente a `barrar-aceite.php`, antes do `resumo()`)**

```php
echo '== Distribuição: só quem tem a oferta aceita' . PHP_EOL;
use App\Support\Entregas\Distribuicao\Distribuicoes;
use Illuminate\Support\Facades\DB;
use Teste\Config;

function comDistribuicao(array $ofertas): void
{
    cenario(['adhoc' => true, 'driver_assigned_uuid' => null]);
    Config::$valores['services.entregas.distribuicao'] = '1';
    \Teste\Relogio::$agora = '2026-10-07 10:00:00';
    $d = Distribuicoes::criar(Order::$todos[0]);
    foreach ($ofertas as $i => [$motoboy, $resposta]) {
        $o = Distribuicoes::criarOferta($d, ['motoboy_uuid' => $motoboy, 'tempo_s' => 100, 'encaixe' => false, 'aproximado' => false], $i + 1);
        if ($resposta !== 'pendente') {
            Distribuicoes::responder($o->id, $resposta);
        }
    }
}

comDistribuicao([['d-a', 'pendente']]);
confere(aceitar(TOKEN_A, ['assign' => 'driver_a']) === 'passou', 'A tem a oferta pendente: passa');
confere(DB::table('entregas_ofertas')->where('id', 1)->value('resposta') === 'aceita' && DB::table('entregas_distribuicoes')->where('id', 1)->value('fase') === 'encerrada', 'aceite registrado: oferta aceita, distribuição encerrada');

comDistribuicao([['d-a', 'pendente']]);
session(['user' => 'u-b']);
$resposta = aceitar('13|token-do-motoboy-b', ['assign' => 'driver_b']);
confere($resposta->status === 409 && $resposta->dados === ['error' => 'Este pedido está sendo oferecido a outro motoboy.', 'errors' => ['Este pedido está sendo oferecido a outro motoboy.']], 'B tenta aceitar a oferta de A: 409');
confere(DB::table('entregas_ofertas')->where('id', 1)->value('resposta') === 'pendente', 'a oferta de A continua pendente');

comDistribuicao([['d-a', 'vencida']]);
confere(aceitar(TOKEN_A, ['assign' => 'driver_a']) === 'passou', 'a oferta de A venceu, mas ninguém foi oferecido depois: passa');

comDistribuicao([['d-a', 'vencida'], ['d-b', 'pendente']]);
confere(aceitar(TOKEN_A, ['assign' => 'driver_a'])->status === 409, 'a oferta de A venceu e B já foi oferecido: 409');

comDistribuicao([['d-a', 'recusada'], ['d-b', 'pendente']]);
confere(aceitar(TOKEN_A, ['assign' => 'driver_a'])->status === 409, 'A recusou: 409');

comDistribuicao([['d-a', 'pendente']]);
session(['user' => 'u-sem-cadastro']);
confere(aceitar('flb_live_chave-do-apk', ['assign' => 'driver_a']) === 'passou', 'chave de API com assign = quem tem a oferta: passa');
confere(aceitar('flb_live_chave-do-apk', ['assign' => 'driver_b'])->status === 409, 'chave de API com assign de outro: 409');

comDistribuicao([['d-a', 'pendente']]);
Distribuicoes::mudarFase(1, 'aberta', 'fila_esgotada');
session(['user' => 'u-b']);
confere(aceitar('13|token-do-motoboy-b', ['assign' => 'driver_b']) === 'passou', 'fase aberta: qualquer um aceita, como hoje');

comDistribuicao([['d-a', 'pendente']]);
Config::$valores['services.entregas.distribuicao'] = '';
confere(aceitar('13|token-do-motoboy-b', ['assign' => 'driver_b']) === 'passou', 'desligada: a regra não vale');
Config::$valores['services.entregas.distribuicao'] = '1';

```

Para o último caso, acrescente ao helper `aceitar()` do arquivo um parâmetro opcional `$proximo = null` e use `fn () => $proximo ?? 'passou'`; então:

```php
comDistribuicao([['d-a', 'pendente']]);
$falha = response()->json(['error' => 'Order has already started.'], 400);
confere(aceitar(TOKEN_A, ['assign' => 'driver_a'], API . 'startOrder', $falha) === $falha && DB::table('entregas_ofertas')->where('id', 1)->value('resposta') === 'pendente', 'o Fleet-Ops recusou o aceite: a oferta continua pendente');
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/barrar-aceite.php`
Expected: falhas nos casos novos (409 esperado, 'passou' recebido).

- [ ] **Step 3: Implementar**

Em `aceitarComATrava`, entre a conferência `passouParaOutroMotoboy` e o `return $next($request);`, acrescente:

```php
        // distribuição de pedidos abertos: na fase ofertas só quem tem a oferta aceita (Distribuicoes::ofertaParaAceite)
        if ($atual && Distribuicao::ligada() && Distribuicoes::emOfertas((string) $atual->uuid)) {
            $quem   = $this->quemAceita($request);
            $oferta = $quem ? Distribuicoes::ofertaParaAceite((string) $atual->uuid, $quem) : null;
            if (!$oferta) {
                Log::info('[entregas] aceite do motoboy barrado: pedido oferecido a outro motoboy', [
                    'pedido'  => $pedido->public_id,
                    'motoboy' => $request->input('assign'),
                    'ip'      => $request->ip(),
                ]);

                return $this->recusar('Este pedido está sendo oferecido a outro motoboy.', 409);
            }

            $resposta = $next($request);
            if ($this->deuCerto($resposta)) {
                app(Distribuidor::class)->registrarAceite($oferta);
            }

            return $resposta;
        }
```

e os métodos:

```php
    /** O uuid de quem está aceitando: o motoboy da sessão ou, sem ele, o `assign` (public_id). */
    protected function quemAceita(Request $request): ?string
    {
        $daSessao = MotoboyDaSessao::motoboy($request);
        if ($daSessao) {
            return (string) $daSessao->uuid;
        }
        $assign = $request->input('assign');
        if (is_string($assign) && $assign !== '') {
            return Driver::where('public_id', $assign)->first()?->uuid;
        }

        return null;
    }

    /** A resposta do startOrder foi 2xx (o Response do Laravel tem getStatusCode; a dos testes, `status`). */
    protected function deuCerto($resposta): bool
    {
        if (is_object($resposta) && method_exists($resposta, 'getStatusCode')) {
            return $resposta->getStatusCode() < 300;
        }
        if (is_object($resposta) && isset($resposta->status)) {
            return (int) $resposta->status < 300;
        }

        return true;
    }
```

Os `use`: `App\Support\Entregas\Distribuicao\Distribuicao`, `...\Distribuicoes`, `...\Distribuidor`. Atualize o docblock da classe com um parágrafo sobre a regra nova. O `'passou'` dos testes é string, por isso `deuCerto` aceita o que não é resposta; a `Teste\RespostaJson` tem `status`.

- [ ] **Step 4: Rodar e ver passar**

Run: `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/barrar-aceite.php` → `FALHAS: 0`. O `app(Distribuidor::class)` dos stubs constrói a classe com as dependências do construtor (`FilaDeCandidatos` → `EstimadorDeTempo`): se o container falso não resolver o encadeamento, registre `\Teste\Container::$instancias[Distribuidor::class] = new Distribuidor(new FilaDeCandidatos(new EstimadorDeTempo()));` no `comDistribuicao()`.

- [ ] **Step 5: Commit**

```bash
git add api/app/Http/Middleware/BarrarAceiteDePedidoEncerrado.php scripts/teste-php/barrar-aceite.php
git commit -m "Distribuição: na fase ofertas só quem tem a oferta aceita o pedido"
```

---

### Task 12: A lista do app (`FiltrarPedidosAbertosDoMotoboy`)

**Files:**
- Create: `api/app/Http/Middleware/FiltrarPedidosAbertosDoMotoboy.php`
- Modify: `api/app/Providers/RouteServiceProvider.php` (`pushMiddlewareToGroup('fleetbase.api', ...)`)
- Test: `scripts/teste-php/filtrar-pedidos-abertos.php`

A lista "Novos pedidos" do app é `GET v1/orders?nearby=driver_x&adhoc=1&unassigned=1&dispatched=1` (`Api\v1\OrderController@query`, resposta `OrderResource::collection`: itens com `id` = public_id). O middleware roda depois do `$next`: tira os pedidos com distribuição em `ofertas` que não são a oferta do motoboy da sessão e acrescenta `entregas_oferta` ao que é. Nunca lança.

- [ ] **Step 1: Teste**

```php
<?php

// Distribuição de pedidos abertos: a lista "Novos pedidos" do app (GET v1/orders?adhoc=1&unassigned=1) só com a oferta
// do motoboy e os pedidos abertos a todos (FiltrarPedidosAbertosDoMotoboy).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/filtrar-pedidos-abertos.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Http\Middleware\FiltrarPedidosAbertosDoMotoboy;
use App\Support\Entregas\Distribuicao\Distribuicoes;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Teste\Banco;
use Teste\Config;

eval('namespace Illuminate\Http; class JsonResponse { public $headers = []; private string $json; public function __construct($dados = null, private int $status = 200, array $headers = []) { $this->json = json_encode($dados); $this->headers = $headers; } public function getStatusCode(): int { return $this->status; } public function getData($assoc = false) { return json_decode($this->json, $assoc); } public function setData($dados = []) { $this->json = json_encode($dados); return $this; } public function getContent() { return $this->json; } }');

const LISTA = 'Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController@query';

/** Motoboys A e B; pedidos 1 (em ofertas, oferecido a A), 2 (em ofertas, oferecido a B), 3 (aberto a todos), 4 (sem distribuição). */
function cenario(): void
{
    reiniciarFleetbase();
    reiniciarIfood();
    Config::$valores['services.entregas.distribuicao'] = '1';
    \Teste\Relogio::$agora = '2026-10-07 10:00:00';
    session(['company' => 'empresa-1', 'user' => 'u-a']);
    Driver::$todos[] = new Driver(['uuid' => 'd-a', 'public_id' => 'driver_a', 'company_uuid' => 'empresa-1', 'user_uuid' => 'u-a']);
    Driver::$todos[] = new Driver(['uuid' => 'd-b', 'public_id' => 'driver_b', 'company_uuid' => 'empresa-1', 'user_uuid' => 'u-b']);
    foreach ([1, 2, 3, 4] as $n) {
        Banco::inserir('orders', ['uuid' => "order-$n", 'public_id' => "order_$n", 'company_uuid' => 'empresa-1'], false);
    }
    $pedido = fn ($n) => new Order(['uuid' => "order-$n", 'public_id' => "order_$n", 'company_uuid' => 'empresa-1', 'adhoc' => true]);
    $d1 = Distribuicoes::criar($pedido(1));
    Distribuicoes::criarOferta($d1, ['motoboy_uuid' => 'd-a', 'tempo_s' => 400, 'encaixe' => false, 'aproximado' => false], 1);
    $d2 = Distribuicoes::criar($pedido(2));
    Distribuicoes::criarOferta($d2, ['motoboy_uuid' => 'd-b', 'tempo_s' => 500, 'encaixe' => false, 'aproximado' => false], 1);
    $d3 = Distribuicoes::criar($pedido(3));
    Distribuicoes::mudarFase($d3->id, 'aberta', 'fila_esgotada');
}

function listar(string $token, array $query = ['adhoc' => 1, 'unassigned' => 1, 'nearby' => 'driver_a'], $resposta = null, string $acao = LISTA)
{
    $request         = new Request($query);
    $request->token  = $token;
    $request->rota   = new Route($acao, []);
    $request->metodo = 'GET';
    $resposta      ??= new JsonResponse(['data' => array_map(fn ($n) => ['id' => "order_$n", 'status' => 'dispatched', 'adhoc' => true], [1, 2, 3, 4])]);

    return (new FiltrarPedidosAbertosDoMotoboy())->handle($request, fn () => $resposta);
}

function ids($resposta): array { return array_column($resposta->getData(true)['data'], 'id'); }

echo '== A lista do motoboy A' . PHP_EOL;
cenario();
$resposta = listar('12|token-do-motoboy-a');
confere(ids($resposta) === ['order_1', 'order_3', 'order_4'], 'A vê a oferta dele, o aberto a todos e o sem distribuição; não vê a oferta de B (' . json_encode(ids($resposta)) . ')');
$item = $resposta->getData(true)['data'][0];
confere($item['entregas_oferta']['vence_em'] === \Carbon\Carbon::parse('2026-10-07 10:00:30', date_default_timezone_get())->toIso8601String() && $item['entregas_oferta']['tempo_estimado_s'] === 400, 'a oferta dele traz entregas_oferta (vence_em ISO e tempo) (' . json_encode($item['entregas_oferta'] ?? null) . ')');
confere(!isset($resposta->getData(true)['data'][1]['entregas_oferta']), 'os outros não trazem entregas_oferta');

cenario();
session(['user' => 'u-b']);
confere(ids(listar('13|token-do-motoboy-b', ['adhoc' => 1, 'unassigned' => 1, 'nearby' => 'driver_b'])) === ['order_2', 'order_3', 'order_4'], 'B vê a dele');

echo '== Quando não filtra' . PHP_EOL;
cenario();
confere(ids(listar('flb_live_chave-do-apk')) === ['order_1', 'order_2', 'order_3', 'order_4'], 'sem motoboy na sessão (chave de API): lista inteira');
confere(ids(listar('12|token-do-motoboy-a', ['nearby' => 'driver_a'])) === ['order_1', 'order_2', 'order_3', 'order_4'], 'sem adhoc=1&unassigned=1: lista inteira');
confere(ids(listar('12|token-do-motoboy-a', ['adhoc' => 1, 'unassigned' => 1], null, 'Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController@find')) === ['order_1', 'order_2', 'order_3', 'order_4'], 'outra ação: lista inteira');
Config::$valores['services.entregas.distribuicao'] = '';
confere(ids(listar('12|token-do-motoboy-a')) === ['order_1', 'order_2', 'order_3', 'order_4'], 'desligada: lista inteira');
Config::$valores['services.entregas.distribuicao'] = '1';

cenario();
$lista = new JsonResponse([['id' => 'order_2'], ['id' => 'order_3']]);
confere(array_column(listar('12|token-do-motoboy-a', ['adhoc' => 1, 'unassigned' => 1], $lista)->getData(true), 'id') === ['order_3'], 'corpo como array simples também é filtrado');
$texto = 'não é json';
confere(listar('12|token-do-motoboy-a', ['adhoc' => 1, 'unassigned' => 1], $texto) === $texto, 'resposta que não é JsonResponse: devolvida como veio');

cenario();
Banco::$falharAoConsultar = new \RuntimeException('banco fora');
$resposta = listar('12|token-do-motoboy-a');
confere(ids($resposta) === ['order_1', 'order_2', 'order_3', 'order_4'] && logou('filtro da lista', 'warning'), 'erro no banco: a resposta original e o log');
Banco::$falharAoConsultar = null;

resumo();
```

Se `Banco::$falharAoConsultar` tiver outro nome ou formato em `stubs-ifood.php`, use o que estiver lá (seção "Como os stubs simulam banco" do `ifood-stub-banco.php`).

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/filtrar-pedidos-abertos.php`
Expected: erro de PHP (classe não encontrada).

- [ ] **Step 3: Implementar**

```php
<?php

namespace App\Http\Middleware;

use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Distribuicoes;
use App\Support\Entregas\MotoboyDaSessao;
use Closure;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: a lista "Novos pedidos" do app (GET v1/orders?adhoc=1&unassigned=1, Api\v1\OrderController@query)
 * com a distribuição de pedidos abertos ligada: o pedido em fase `ofertas` só aparece para o motoboy que tem a oferta
 * pendente dele, com `entregas_oferta: {vence_em, tempo_estimado_s}`; os abertos a todos e os sem distribuição
 * continuam. Grupo fleetbase.api, depois do $next. Nunca lança: em erro devolve a resposta original e registra.
 * Funciona com o APK atual (que só lista): o APK novo lê o entregas_oferta para o cronômetro.
 */
class FiltrarPedidosAbertosDoMotoboy
{
    public const LISTA = OrderController::class . '@query';

    public function handle(Request $request, Closure $next)
    {
        $resposta = $next($request);

        try {
            if ($this->ehListaDeAbertos($request) && Distribuicao::ligada()) {
                $motoboy = MotoboyDaSessao::motoboy($request);
                if ($motoboy) {
                    return $this->filtrar($resposta, (string) $motoboy->uuid, (string) session('company'));
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[entregas] distribuição: filtro da lista: ' . get_class($e));
        }

        return $resposta;
    }

    private function ehListaDeAbertos(Request $request): bool
    {
        if ($request->method() !== 'GET' || !$request->boolean('adhoc') || !$request->boolean('unassigned')) {
            return false;
        }
        $rota = $request->route();
        $acao = $rota instanceof Route ? ltrim($rota->getActionName(), '\\') : null;

        return $acao === static::LISTA;
    }

    private function filtrar($resposta, string $motoboyUuid, string $empresa)
    {
        if (!$resposta instanceof JsonResponse || $resposta->getStatusCode() !== 200) {
            return $resposta;
        }
        $corpo = $resposta->getData(false);
        $itens = $this->listaDe($corpo);
        if (!$itens) {
            return $resposta;
        }

        // os pedidos em fase ofertas da empresa, pelo public_id (o item da lista traz `id` = public_id)
        $emOfertas = DB::table(Distribuicoes::TABELA . ' as d')
            ->join('orders as o', 'o.uuid', '=', 'd.pedido_uuid')
            ->where('d.company_uuid', $empresa)
            ->where('d.fase', Distribuicao::FASE_OFERTAS)
            ->get(['o.public_id', 'd.id', 'd.pedido_uuid']);
        if ($emOfertas->isEmpty()) {
            return $resposta;
        }
        $porPublicId = [];
        foreach ($emOfertas as $linha) {
            $porPublicId[$linha->public_id] = $linha;
        }

        $filtrados = [];
        foreach ($itens as $item) {
            $id = is_object($item) ? ($item->id ?? null) : null;
            if (!is_string($id) || !isset($porPublicId[$id])) {
                $filtrados[] = $item;
                continue;
            }
            $oferta = Distribuicoes::ofertaPendente((int) $porPublicId[$id]->id);
            if (!$oferta || (string) $oferta->motoboy_uuid !== $motoboyUuid) {
                continue; // oferecido a outro: o motoboy não vê
            }
            $item->entregas_oferta = [
                'vence_em'         => Distribuicoes::data($oferta->vence_em)?->toIso8601String(),
                'tempo_estimado_s' => (int) $oferta->tempo_estimado_s,
            ];
            $filtrados[] = $item;
        }

        $resposta->setData($this->comLista($corpo, $filtrados));

        return $resposta;
    }

    /** A lista de pedidos do corpo (array simples, ou objeto com `data`/`orders`), ou null. */
    private function listaDe($corpo): ?array
    {
        if (is_array($corpo)) {
            return $corpo;
        }
        if (is_object($corpo)) {
            foreach (['data', 'orders'] as $chave) {
                if (isset($corpo->$chave) && is_array($corpo->$chave)) {
                    return $corpo->$chave;
                }
            }
        }

        return null;
    }

    private function comLista($corpo, array $lista)
    {
        if (is_array($corpo)) {
            return $lista;
        }
        foreach (['data', 'orders'] as $chave) {
            if (isset($corpo->$chave) && is_array($corpo->$chave)) {
                $corpo->$chave = $lista;
                break;
            }
        }

        return $corpo;
    }
}
```

A `Teste\Consulta` faz `join` com tabelas externas (`Banco::inserir('orders', ..., false)`) e aceita `where('d.coluna', ...)`? Se o prefixo `d.`/`o.` não for entendido pelo stub, use a forma sem alias: `DB::table('entregas_distribuicoes')->join('orders', 'orders.uuid', '=', 'entregas_distribuicoes.pedido_uuid')->where('entregas_distribuicoes.company_uuid', ...)` e confira em `notas-na-lista.php`/`ifood-acompanhar.php` como o join é escrito nos testes que já existem; siga o mesmo formato.

- [ ] **Step 4: Registrar o middleware**

Em `api/app/Providers/RouteServiceProvider.php`, depois da linha `pushMiddlewareToGroup('fleetbase.api', AvisarOnlineDoMotoboy::class)`:

```php
        // distribuição de pedidos abertos: a lista "Novos pedidos" do app só com a oferta dele e os abertos a todos
        $this->app['router']->pushMiddlewareToGroup('fleetbase.api', FiltrarPedidosAbertosDoMotoboy::class);
```

com o `use App\Http\Middleware\FiltrarPedidosAbertosDoMotoboy;`.

- [ ] **Step 5: Rodar e ver passar; sintaxe**

Run: `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/filtrar-pedidos-abertos.php` → `FALHAS: 0`. Run: `node scripts/teste-php/sintaxe.mjs`.

- [ ] **Step 6: Commit**

```bash
git add api/app/Http/Middleware/FiltrarPedidosAbertosDoMotoboy.php api/app/Providers/RouteServiceProvider.php scripts/teste-php/filtrar-pedidos-abertos.php
git commit -m "Distribuição: a lista do app só com a oferta do motoboy e os abertos a todos"
```

---

### Task 13: Rotas: recusar (app) e o painel (console)

**Files:**
- Modify: `api/app/Http/Controllers/Entregas/MotoboyController.php` (`recusar`)
- Create: `api/app/Http/Controllers/Entregas/DistribuicaoController.php`
- Modify: `api/app/Providers/RouteServiceProvider.php` (rotas e limitador)
- Test: `scripts/teste-php/distribuicao-rotas.php`

- [ ] **Step 1: Teste**

```php
<?php

// Distribuição de pedidos abertos: a recusa pelo app (POST v1/entregas/motoboy/pedidos/{id}/recusar) e o painel do
// console (GET int/v1/entregas/pedidos/{id}/distribuicao, POST .../distribuicao/abrir).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-rotas.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Http\Controllers\Entregas\DistribuicaoController;
use App\Http\Controllers\Entregas\MotoboyController;
use App\Support\Entregas\Distribuicao\Candidatos;
use App\Support\Entregas\Distribuicao\Distribuicoes;
use App\Support\Entregas\Distribuicao\Distribuidor;
use App\Support\Entregas\Distribuicao\EstimadorDeTempo;
use App\Support\Entregas\Distribuicao\FilaDeCandidatos;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Http\Request;
use Teste\Config;

function cenario(): Distribuidor
{
    reiniciarFleetbase();
    reiniciarIfood();
    Config::$valores['services.entregas.distribuicao'] = '1';
    \Teste\Relogio::$agora = '2026-10-07 10:00:00';
    session(['company' => 'empresa-1', 'user' => 'u-a']);
    Driver::$todos[] = new Driver(['uuid' => 'd-a', 'public_id' => 'driver_a', 'company_uuid' => 'empresa-1', 'user_uuid' => 'u-a', 'name' => 'Ana']);
    Driver::$todos[] = new Driver(['uuid' => 'd-b', 'public_id' => 'driver_b', 'company_uuid' => 'empresa-1', 'user_uuid' => 'u-b', 'name' => 'Bia']);
    Order::$todos[]  = new Order(['uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1', 'adhoc' => true, 'status' => 'dispatched']);
    Candidatos::$buscarMotoboys = fn () => [];
    $dist = new Distribuidor(new FilaDeCandidatos(new EstimadorDeTempo()));
    \Teste\Container::$instancias[Distribuidor::class] = $dist;
    $d = Distribuicoes::criar(Order::$todos[0]);
    Distribuicoes::criarOferta($d, ['motoboy_uuid' => 'd-a', 'tempo_s' => 400, 'encaixe' => true, 'aproximado' => false], 1);

    return $dist;
}

function requisicao(string $token, array $dados = []): Request
{
    $request        = new Request($dados);
    $request->token = $token;

    return $request;
}

echo '== Recusar' . PHP_EOL;
$dist = cenario();
$resposta = (new MotoboyController())->recusar(requisicao('12|token-do-motoboy-a'), 'order_1', $dist);
confere($resposta->status === 200 && $resposta->dados === ['resultado' => 'recusada'], 'A recusa a oferta dele: 200');
confere(Distribuicoes::oferta(1)->resposta === 'recusada', 'a oferta fica recusada');
confere(Distribuicoes::doPedido('order-1')->fase === 'aberta', 'sem outro candidato: aberta a todos');

$dist = cenario();
session(['user' => 'u-b']);
$resposta = (new MotoboyController())->recusar(requisicao('13|token-do-motoboy-b'), 'order_1', $dist);
confere($resposta->status === 409 && $resposta->dados === ['errors' => ['Esta oferta não está mais com você.']], 'B não tem oferta: 409');
confere((new MotoboyController())->recusar(requisicao('flb_live_chave'), 'order_1', $dist)->status === 403, 'chave de API: 403');
confere((new MotoboyController())->recusar(requisicao('13|token-do-motoboy-b'), 'order_9', $dist)->status === 404, 'pedido inexistente: 404');

echo '== Painel' . PHP_EOL;
$dist = cenario();
\Teste\Auth::$usuario = (object) ['is_admin' => true];       // use o helper de usuário admin que os testes do IfoodPedidosController já usam
$resposta = (new DistribuicaoController())->painel(requisicao('sessao'), 'order_1');
confere($resposta->status === 200 && $resposta->dados['fase'] === 'ofertas' && $resposta->dados['oferta']['motoboy'] === 'Ana' && $resposta->dados['oferta']['vence_em'] === Distribuicoes::data('2026-10-07 10:00:30')->toIso8601String(), 'painel: fase, oferta atual com nome e vence_em (' . json_encode($resposta->dados) . ')');
confere(count($resposta->dados['historico']) === 1 && $resposta->dados['historico'][0]['resposta'] === 'pendente' && $resposta->dados['historico'][0]['encaixe'] === true, 'histórico das ofertas');
confere($resposta->dados['fila'] === [], 'fila (JSON gravado; vazia aqui)');

$resposta = (new DistribuicaoController())->abrir(requisicao('sessao'), 'order_1');
confere($resposta->status === 200 && $resposta->dados['fase'] === 'aberta' && $resposta->dados['motivo'] === 'aberta_pela_central', 'abrir: fase aberta pela central');
confere((new DistribuicaoController())->abrir(requisicao('sessao'), 'order_1')->status === 409, 'já aberta: 409');
confere((new DistribuicaoController())->painel(requisicao('sessao'), 'order_9')->status === 404, 'pedido inexistente: 404');
Order::$todos[] = new Order(['uuid' => 'order-2', 'public_id' => 'order_2', 'company_uuid' => 'empresa-1']);
confere((new DistribuicaoController())->painel(requisicao('sessao'), 'order_2')->dados === ['distribuicao' => false], 'pedido sem distribuição: {distribuicao: false}');

\Teste\Auth::$usuario = (object) ['is_admin' => false];
confere((new DistribuicaoController())->painel(requisicao('sessao'), 'order_1')->status === 403, 'não admin: 403');

resumo();
```

Veja em `scripts/teste-php/ifood-conclusao.php` (ou no teste que cobre o `IfoodPedidosController@liberarSemCodigo`) como o usuário admin é simulado (`Fleetbase\Support\Auth::getUserFromSession` falso) e use o mesmo helper no lugar de `\Teste\Auth::$usuario`.

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-rotas.php`
Expected: erro de PHP (método `recusar` não existe).

- [ ] **Step 3: `MotoboyController@recusar`**

```php
    /**
     * Distribuição de pedidos abertos: o motoboy recusa a oferta que está com ele (o Distribuidor passa ao próximo na hora).
     * 409 se não há oferta pendente dele neste pedido (já venceu, outro foi oferecido, pedido aberto a todos).
     */
    public function recusar(Request $request, string $id, Distribuidor $distribuidor)
    {
        $motoboy = MotoboyDaSessao::motoboy($request);
        if (!$motoboy) {
            return $this->soParaMotoboy();
        }

        $pedido = Order::where('company_uuid', session('company'))
            ->where(fn ($query) => $query->where('public_id', $id)->orWhere('uuid', $id))
            ->first();
        if (!$pedido) {
            return response()->json(['errors' => ['Pedido não encontrado.']], 404);
        }

        try {
            $recusou = $distribuidor->recusar((string) $pedido->uuid, $motoboy);
        } catch (LockTimeoutException $e) {
            return response()->json(['errors' => ['Este pedido está sendo atualizado. Tente de novo.']], 503);
        }

        return $recusou
            ? response()->json(['resultado' => 'recusada'])
            : response()->json(['errors' => ['Esta oferta não está mais com você.']], 409);
    }
```

`use App\Support\Entregas\Distribuicao\Distribuidor;` e `use Illuminate\Contracts\Cache\LockTimeoutException;`.

- [ ] **Step 4: `DistribuicaoController`**

```php
<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Distribuicoes;
use App\Support\Entregas\Distribuicao\Distribuidor;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Support\Auth;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: painel "Distribuição" no detalhe do pedido do console (só administradores) e o botão
 * "Abrir a todos agora". GET int/v1/entregas/pedidos/{id}/distribuicao e POST .../distribuicao/abrir.
 */
class DistribuicaoController extends Controller
{
    public function painel(Request $request, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $pedido = $this->pedido($id);
        if (!$pedido) {
            return response()->json(['errors' => ['Pedido não encontrado.']], 404);
        }

        return response()->json(static::resposta((string) $pedido->uuid));
    }

    public function abrir(Request $request, string $id, Distribuidor $distribuidor)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $pedido = $this->pedido($id);
        if (!$pedido) {
            return response()->json(['errors' => ['Pedido não encontrado.']], 404);
        }

        try {
            $abriu = $distribuidor->abrirATodos((string) $pedido->uuid, Distribuicao::ABERTA_PELA_CENTRAL);
        } catch (LockTimeoutException $e) {
            return response()->json(['errors' => ['O pedido está sendo atualizado. Tente de novo.']], 503);
        }
        if (!$abriu) {
            return response()->json(['errors' => ['Este pedido não está em oferta.']], 409);
        }

        return response()->json(static::resposta((string) $pedido->uuid));
    }

    /** O painel: a distribuição não encerrada do pedido ou, sem ela, a última (para o histórico); `distribuicao: false` se nunca houve. */
    public static function resposta(string $pedidoUuid): array
    {
        $distribuicao = Distribuicoes::doPedido($pedidoUuid) ?? Distribuicoes::ultimaDoPedido($pedidoUuid);
        if (!$distribuicao) {
            return ['distribuicao' => false];
        }
        $ofertas = Distribuicoes::ofertas((int) $distribuicao->id);
        $nomes   = static::nomes(array_map(fn ($o) => (string) $o->motoboy_uuid, $ofertas));
        $atual   = null;
        foreach ($ofertas as $oferta) {
            if ($oferta->resposta === Distribuicao::PENDENTE) {
                $atual = $oferta;
            }
        }
        $linha = fn ($o) => [
            'posicao'          => (int) $o->posicao,
            'motoboy'          => $nomes[(string) $o->motoboy_uuid] ?? '—',
            'tempo_estimado_s' => $o->tempo_estimado_s !== null ? (int) $o->tempo_estimado_s : null,
            'encaixe'          => (bool) $o->encaixe,
            'aproximado'       => (bool) $o->aproximado,
            'oferecida_em'     => Distribuicoes::data($o->oferecida_em)?->toIso8601String(),
            'vence_em'         => Distribuicoes::data($o->vence_em)?->toIso8601String(),
            'resposta'         => $o->resposta,
            'respondida_em'    => Distribuicoes::data($o->respondida_em)?->toIso8601String(),
        ];

        return [
            'distribuicao'  => true,
            'fase'          => $distribuicao->fase,
            'motivo'        => $distribuicao->motivo,
            'despachada_em' => Distribuicoes::data($distribuicao->despachada_em)?->toIso8601String(),
            'aberta_em'     => Distribuicoes::data($distribuicao->aberta_em)?->toIso8601String(),
            'encerrada_em'  => Distribuicoes::data($distribuicao->encerrada_em)?->toIso8601String(),
            'oferta'        => $atual ? $linha($atual) : null,
            'fila'          => $distribuicao->fila ? (json_decode((string) $distribuicao->fila, true) ?: []) : [],
            'historico'     => array_map($linha, $ofertas),
        ];
    }

    /** @return array<string, string> uuid => nome */
    protected static function nomes(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }
        $nomes = [];
        foreach (Driver::whereIn('uuid', array_values(array_unique($uuids)))->get() as $motoboy) {
            $nomes[(string) $motoboy->uuid] = (string) $motoboy->name;
        }

        return $nomes;
    }

    protected function pedido(string $id): ?Order
    {
        // nenhum model registra o CompanyScope nesta versão: a empresa é filtrada aqui
        return Order::where('company_uuid', session('company'))
            ->where(fn ($query) => $query->where('public_id', $id)->orWhere('uuid', $id))
            ->first();
    }

    protected function negarSeNaoAdmin(Request $request)
    {
        $usuario = Auth::getUserFromSession($request);
        if (!$usuario || $usuario->isNotAdmin()) {
            return response()->json(['errors' => ['Somente administradores podem ver a distribuição do pedido.']], 403);
        }

        return null;
    }
}
```

Acrescente em `Distribuicoes`:

```php
    /** A última distribuição do pedido, encerrada ou não (o painel mostra o histórico depois do aceite). */
    public static function ultimaDoPedido(string $pedidoUuid): ?object
    {
        return DB::table(static::TABELA)->where('pedido_uuid', $pedidoUuid)->orderBy('id', 'desc')->first();
    }
```

A `ConsultaDeModelo` dos stubs tem `whereIn` e `get`? Se não tiver `get`, use `first()` num laço por uuid (são poucas ofertas) ou acrescente `get()` ao stub (devolve `Collection` dos que passam nos filtros).

- [ ] **Step 5: Rotas e limitador**

Em `RouteServiceProvider::boot()`, ao lado dos outros `RateLimiter::for`:

```php
        // distribuição de pedidos abertos: a recusa da oferta pelo app (30 por minuto por motoboy)
        RateLimiter::for('entregas-motoboy-recusa', fn (Request $request) => Limit::perMinute(30)->by('entregas-motoboy-recusa:' . (session('user') ?: $request->ip())));
```

No grupo `Route::prefix('v1/entregas/motoboy')->middleware(['fleetbase.api', 'throttle:entregas-motoboy'])`, acrescente:

```php
                        Route::post('pedidos/{id}/recusar', [MotoboyController::class, 'recusar'])->middleware('throttle:entregas-motoboy-recusa');
```

No grupo `Route::prefix('int/v1/entregas')->middleware(['fleetbase.protected'])`, depois das rotas `pedidos/{id}/ifood`:

```php
                        // painel "Distribuição" no detalhe do pedido e o "Abrir a todos agora"
                        Route::get('pedidos/{id}/distribuicao', [DistribuicaoController::class, 'painel']);
                        Route::post('pedidos/{id}/distribuicao/abrir', [DistribuicaoController::class, 'abrir']);
```

com `use App\Http\Controllers\Entregas\DistribuicaoController;`.

- [ ] **Step 6: Rodar e ver passar; sintaxe**

Run: `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-rotas.php` → `FALHAS: 0`. Run: `node scripts/teste-php/sintaxe.mjs`.

- [ ] **Step 7: Commit**

```bash
git add api/app/Http/Controllers/Entregas/MotoboyController.php api/app/Http/Controllers/Entregas/DistribuicaoController.php api/app/Support/Entregas/Distribuicao/Distribuicoes.php api/app/Providers/RouteServiceProvider.php scripts/teste-php/distribuicao-rotas.php
git commit -m "Distribuição: recusa pelo app e painel do console"
```

---

### Task 14: O reenvio pula pedidos em fase ofertas

**Files:**
- Modify: `api/app/Console/Commands/Entregas/ReenviarPedidosAbertos.php`
- Test: `scripts/teste-php/reenvio.php` (usa `stubs.php`, que não tem `DB`)

O `ReenviarPedidosAbertos` manda o `LembretePedidoAberto` a cada 4 min aos pedidos abertos sem aceite. Com a distribuição em `ofertas`, o reenvio esperaria: o pedido ainda está sendo oferecido um a um. Na fase `aberta` segue como hoje (os reenvios contam do `dispatched_at`, então o primeiro vem ~1 min depois da abertura por prazo).

- [ ] **Step 1: Teste (acrescente a `reenvio.php`, antes do `resumo()`)**

```php
echo '== Distribuição em ofertas: sem reenvio' . PHP_EOL;
// o stubs.php não tem DB: o comando trata a ausência como "não está em ofertas" (a consulta falha e o reenvio segue)
$comando = new class extends \App\Console\Commands\Entregas\ReenviarPedidosAbertos {
    public array $emOfertas = [];
    protected function emDistribuicao($pedido): bool { return in_array($pedido->uuid, $this->emOfertas, true); }
};
```

Depois, no cenário que já existe de "pedido aberto há 4 min recebe o reenvio", rode a versão `$comando` com `emOfertas = [uuid do pedido]` e confira que `Teste\Registro::$avisos` fica vazio e o cache do reenvio não é gravado; e com `emOfertas = []` o reenvio sai como antes. Siga a forma como o arquivo instancia e roda o comando hoje (`handle()` com os argumentos que ele usa).

- [ ] **Step 2: Implementar**

No laço do `handle()`, logo depois de ler `$estado` (antes do `if ($estado['vezes'] >= self::MAX_REENVIOS ...)`), acrescente:

```php
            // distribuição de pedidos abertos: enquanto o pedido está sendo oferecido um a um, o reenvio espera
            if ($this->emDistribuicao($pedido)) {
                $this->line('Pedido ' . $pedido->public_id . ': em oferta um a um (distribuição); sem reenvio.');
                continue;
            }
```

e o método:

```php
    /** O pedido está na fase ofertas da distribuição (ver App\Support\Entregas\Distribuicao). Em erro (banco), false. */
    protected function emDistribuicao($pedido): bool
    {
        try {
            return Distribuicao::ligada() && Distribuicoes::emOfertas((string) $pedido->uuid);
        } catch (\Throwable $e) {
            return false;
        }
    }
```

com os `use App\Support\Entregas\Distribuicao\Distribuicao;` e `use App\Support\Entregas\Distribuicao\Distribuicoes;`. Anote no docblock da classe. O `catch (\Throwable)` cobre o `Error` de classe `DB` inexistente nos testes com `stubs.php` e um banco fora na produção (aí o reenvio segue, que é o comportamento seguro).

- [ ] **Step 3: Rodar; sintaxe**

Run: `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/reenvio.php` → `FALHAS: 0`. Run: `node scripts/teste-php/sintaxe.mjs`.

- [ ] **Step 4: Commit**

```bash
git add api/app/Console/Commands/Entregas/ReenviarPedidosAbertos.php scripts/teste-php/reenvio.php
git commit -m "Distribuição: o reenvio espera enquanto o pedido está em oferta um a um"
```

---

### Task 15: Documentação, conferência geral e deploy

**Files:**
- Modify: `CLAUDE.md` (seção nova "Distribuição de pedidos abertos", antes de "Marca Entregas RestaurantePro")
- Modify: `deploy/stack.env.example`: `ENTREGAS_DISTRIBUICAO=`

- [ ] **Step 1: Seção do CLAUDE.md**

```markdown
## Distribuição de pedidos abertos (oferta um a um)

Decisão de 2026-10-07; desenho: `docs/superpowers/specs/2026-10-07-distribuicao-de-pedidos-design.md`; plano: `docs/superpowers/plans/2026-10-07-distribuicao-de-pedidos-api.md` (o código é a referência). Ligada por `ENTREGAS_DISTRIBUICAO=1` no `stack.env` (`config('services.entregas.distribuicao')`); vazia, tudo volta ao alarme geral do Fleet-Ops sem deploy.

- **O que muda:** o pedido aberto (adhoc) não vai mais por alarme a todos os motoboys do raio. Nasce uma **distribuição** (`entregas_distribuicoes`) e o servidor oferece a **um motoboy por vez, por 30 s** (`entregas_ofertas`), na ordem do **menor tempo estimado até o cliente**. Fila esgotada, 3 min do despacho, sem candidato ou "Abrir a todos agora" no console → fase **aberta**: o `OrderPing` comum a todos no raio e, daí em diante, os reenvios e o aviso "sem motoboy" de sempre.
- **Código:** `App\Support\Entregas\Distribuicao\` (`Distribuicao` constantes, `Distribuicoes` tabelas, `Candidatos`, `EstimadorDeTempo` (OSRM `table`, reserva em linha reta × 1,3 a 25 km/h), `Encaixe` (funções puras), `FilaDeCandidatos`, `Distribuidor` (o ciclo, sob a `TravaDoPedido`; `encerrar` e `registrarAceite` sem a trava)); listener `DistribuirPedidoAberto` (estende o `HandleOrderDispatched`; o `AppServiceProvider::distribuirPedidosAbertos` esquece o `OrderDispatched` em `booted` e registra de novo o webhook, o `NotifyOrderEvent` e o nosso); `ObservadorDaDistribuicao` (`Order::updated`: ganhou motoboy → `atribuida`, encerrado → `cancelada`); job `AvancarOferta` (fila `default`, atraso de 30 s); comando `entregas:distribuicao-varrer` (a cada minuto, reserva para Redis reiniciado).
- **Candidatos:** online, `status=available`, no raio de pedido aberto da coleta (o mesmo de hoje, filtrado pela empresa), GPS com menos de 5 min. Carga = pedidos em andamento (regra do capacete). **Encaixe:** testa onde a coleta e a entrega novas entram na sequência dele; vale a mais rápida que não atrase nenhuma entrega já aceita mais de 10 min. Paradas fixas: 3 min na loja, 2 min no cliente. Sem tempo de preparo. Empate: o livre primeiro.
- **A fila é recalculada a cada passo**; saem quem já respondeu neste despacho e quem tem oferta pendente de outro pedido (uma oferta por vez por motoboy). Recusa e silêncio só passam ao próximo. A fila calculada fica no JSON `fila` da distribuição, para o painel.
- **Aceite:** `BarrarAceiteDePedidoEncerrado`: em fase ofertas só quem tem a oferta pendente (ou a vencida, enquanto ninguém foi oferecido depois) aceita; outro recebe 409 "Este pedido está sendo oferecido a outro motoboy.". **Recusa:** `POST v1/entregas/motoboy/pedidos/{id}/recusar` (limitador `entregas-motoboy-recusa`). **Lista do app:** `FiltrarPedidosAbertosDoMotoboy` (grupo `fleetbase.api`, depois do `$next`) tira da `GET v1/orders?adhoc=1&unassigned=1` os pedidos em oferta a outro e põe `entregas_oferta: {vence_em, tempo_estimado_s}` na oferta dele. Funciona com o APK atual.
- **Push:** `OfertaDePedido` (extensão do `OrderPing`, título "Oferta para você", dados `entregas_oferta=1` e `entregas_oferta_vence_em`, `android.ttl` 30 s).
- **Console:** `GET int/v1/entregas/pedidos/{id}/distribuicao` e `POST .../distribuicao/abrir` (só admin; `DistribuicaoController`). O painel fica no plano `2026-10-07-distribuicao-de-pedidos-console-e-app.md`.
- **Reenvio:** `ReenviarPedidosAbertos` pula pedidos em fase ofertas.
- **Logs** (`[entregas] distribuição:`): `iniciada`, `oferta enviada`, `oferta recusada`, `oferta vencida`, `aberta a todos (<motivo>)`, `encerrada (<motivo>)`, `OSRM indisponível; estimativa em linha reta`, `push da oferta falhou`, `falha ao iniciar a distribuição; alarme geral`, `filtro da lista`. Listener e job no `entregas_queue`; aceite, recusa e painel no `entregas_application`; varredura no `entregas_scheduler`.
- **Armadilhas:** a `TravaDoPedido` não é reentrante: nada que rode dentro do aceite (middleware) ou da `TrocaDoMotoboy` pode chamar `iniciar/avancar/vencer/recusar/abrirATodos`. O `OrderResource` da API v1 traz `id` = public_id (o filtro da lista faz join com `orders`). O OSRM recusa `table` acima de 100 pontos (`max-table-size`): aí a rodada sai em linha reta. Pedido sem entrega com coordenadas abre a todos na hora.
- **Ao atualizar o fleetops-api, confira:** a lista de listeners do `OrderDispatched` no `EventServiceProvider` do Fleet-Ops; o `handle()` do `HandleOrderDispatched` (a parte repetida no `DistribuirPedidoAberto`) e os métodos `doesntHaveDispatchActivity`, `getDispatchActivity`, `nearbyAvailableDrivers`, `notifyAdhocDriver`; o `Api\v1\OrderController@query` (filtros `adhoc`/`unassigned`/`nearby`) e o formato do `OrderResource`.
- **Testes:** `scripts/teste-php/distribuicao-encaixe.php`, `distribuicao-fila.php`, `distribuicao-ciclo.php`, `distribuicao-rotas.php`, `filtrar-pedidos-abertos.php`, `barrar-aceite.php`, `avisos-push.php`, `reenvio.php`.
- **Implantação:** `bash deploy/atualizar.sh api` (migrations) → `ENTREGAS_DISTRIBUICAO=1` no `stack.env` e Update the stack ("Re-pull image" desligado) → console (painel) → APK (cartão de 30 s e Recusar pelo servidor). **A conferir no primeiro teste real:** o `table` responde no `OSRM_HOST`; o push com TTL de 30 s chega em celular com economia de bateria; o tempo da chamada `table` no pico.
```

Acrescente também ao "Histórico" do CLAUDE.md: `22. Distribuição de pedidos abertos (2026-10-07, ramo `distribuicao-de-pedidos`): oferta um a um pelo tempo até o cliente, abrindo a todos ao esgotar a fila ou aos 3 min.`

- [ ] **Step 2: Modelo de env**

Em `deploy/stack.env.example`, perto das variáveis `ENTREGAS_*`, acrescente:

```
# Distribuição de pedidos abertos (oferta um a um pelo tempo até o cliente): 1 liga; vazio = alarme geral do Fleet-Ops
ENTREGAS_DISTRIBUICAO=
```

- [ ] **Step 3: Conferência geral**

Run, um por um, e todos em `FALHAS: 0`:

```bash
for t in distribuicao-encaixe distribuicao-fila distribuicao-ciclo distribuicao-rotas filtrar-pedidos-abertos barrar-aceite avisos-push reenvio notas-na-lista ifood-stub-banco ifood-acoes ifood-cancelamento; do PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php || echo "FALHOU: $t"; done
node scripts/teste-php/sintaxe.mjs
```

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md deploy/stack.env.example
git commit -m "Distribuição: documentação e modelo de env"
```

- [ ] **Step 5: Deploy (quem roda é o Edgard)**

1. `git push` do ramo e merge em `main`.
2. Na VPS: `cd ~/entregas && bash deploy/atualizar.sh api` (roda as migrations e o `config:cache`). Nada muda ainda (chave vazia).
3. `ENTREGAS_DISTRIBUICAO=1` no `stack.env`; Portainer → Stacks → entregas → Editor → Update the stack, "Re-pull image" desligado.
4. Teste com a loja de teste: um pedido do portal com dois motoboys online; conferir no `docker service logs entregas_queue 2>&1 | grep 'distribuição'` a sequência `iniciada` → `oferta enviada` → (recusa ou vencida) → `oferta enviada` → `aberta a todos` ou `encerrada (aceita)`; no app antigo, o motoboy sem oferta não vê o pedido na lista e recebe 409 se tentar aceitar pelo alarme velho.
5. Se algo der errado: `ENTREGAS_DISTRIBUICAO=` vazio e Update the stack. Distribuições em curso param; o aceite volta a ser livre; os reenvios seguem.

---

## Auto-revisão do plano (feita ao escrever)

- **Cobertura do spec:** seções 3 (fluxo) → Tasks 8, 9, 10; 4 (candidatos e tempo) → Tasks 2 a 5; 5 (servidor) → Tasks 1, 6, 7, 11, 12, 13, 14; 8 (bordas) → casos nos testes das Tasks 8, 10, 11, 12; 9 (testes) → cada Task; 10 (implantação) → Task 15. Seções 6 e 7 (app e console) ficam no plano `2026-10-07-distribuicao-de-pedidos-console-e-app.md`.
- **Nomes consistentes:** `Distribuidor::iniciar/avancar/vencer/recusar/abrirATodos/encerrar/registrarAceite`; `Distribuicoes::criar/doPedido/ultimaDoPedido/porId/emOfertas/mudarFase/gravarFila/criarOferta/oferta/ofertaPendente/ofertas/responder/cancelarPendentes/motoboysQueResponderam/motoboysComOfertaPendente/ofertaParaAceite/pendentesVencidasHa/emOfertasHaMais/naoEncerradas/agora/data`; `Candidatos::elegiveis/noRaio/paradas` e os closures `$buscarMotoboys/$buscarParadas`; `FilaDeCandidatos::para`; `Encaixe::calcular/chegadas`; `EstimadorDeTempo::matriz`; `AvancarOferta::agendar`; `OfertaDePedido::dadosDaOferta`.
- **Decisões tomadas no plano, fora do spec:** `DB::table` em vez de Eloquent; `encerrar`/`registrarAceite` sem trava; motivo `redespachada`; o `Distribuicoes::ultimaDoPedido` para o painel depois do aceite; o listener por herança do `HandleOrderDispatched`; a troca do evento em `booted`.
