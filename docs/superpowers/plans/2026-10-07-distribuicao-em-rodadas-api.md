# Distribuição em rodadas (API): plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Com `ENTREGAS_DISTRIBUICAO_RODADAS=1`, o servidor oferece o pedido aberto a um motoboy por vez, por 20 s, em rodadas de raio crescente (R, 1,5R, 2R) e em voltas até alguém aceitar, sem nunca mandar o alarme a todos; a partir da rodada 2 da volta 1 a lista "Novos pedidos" fica aberta a todos até 2R.

**Architecture:** Tudo em `api/app` (nada em `packages/*/server` chega à produção), atrás de `Distribuicao::emRodadas()`. O `Distribuidor` ganha um caminho novo (`avancarEmRodadas`) chamado pelo `avancarSemTrava` quando a chave está ligada; o caminho de hoje (30 s, encaixe, prazo de 3 min, abre a todos) fica intacto. A migration nova acrescenta `volta`, `rodada`, `volta_iniciada_em` e `lista_aberta_em` à distribuição e `volta`, `rodada`, `raio_m` às ofertas. Um job novo (`AvancarDistribuicao`) dá o próximo passo quando a volta seguinte ainda não pode começar; a varredura avança as paradas. Aceite, recusa/dispensa, lista do app e painel do console leem as colunas novas. Spec: `docs/superpowers/specs/2026-10-07-distribuicao-em-rodadas-design.md`.

**Tech Stack:** Laravel 10 (PHP 8.2), Fleet-Ops (Composer), MySQL, Redis (cache, travas e fila), FCM via `CanalFcmEntregas`. Testes com php-wasm (`scripts/teste-php/rodar.mjs`, stubs `stubs-ifood.php` + `stubs-ifood-fleetbase.php`).

**Desvios do spec, decididos aqui (o código é a referência):**

1. Os testes do ciclo em rodadas vão num arquivo novo, `scripts/teste-php/distribuicao-rodadas.php` (o spec cita `distribuicao-ciclo.php`), para o `distribuicao-ciclo.php` de hoje continuar sem mudança nenhuma (a chave desligada é o caso dele).
2. `volta_iniciada_em` é **nulo** (o spec diz só timestamp): `ADD COLUMN` de um `TIMESTAMP NOT NULL` numa tabela com linhas falha no MySQL estrito. Nulo vale o `despachada_em` (distribuições de antes da migration); o `criar` grava sempre.
3. O campo `encaixe` continua na fila gravada e nas ofertas (a coluna existe e é `NOT NULL`), **sempre falso** em rodadas. O console do outro plano deixa de mostrá-lo.
4. "Aguardando motoboy" (uma volta inteira sem ninguém): não agenda job; quem tenta de novo é a varredura, a cada minuto. O intervalo de 1 min entre voltas usa o job novo `AvancarDistribuicao` (com a varredura de reserva). A varredura só avança distribuições despachadas nas últimas 24 h (como o encerramento de hoje), para um pedido esquecido não girar para sempre.
5. `Distribuidor::recusar()` continua devolvendo `bool` (os testes de hoje); a rota passa a chamar o novo `recusarOuDispensar()` (`'recusada'`, `'dispensada'` ou `null`). A dispensa grava **uma linha por volta** (dispensar de novo devolve `dispensada` sem linha nova).
6. `Distribuicoes::ofertaParaAceite` passa a ignorar as linhas `dispensada` e `aceita_pela_lista` ao procurar a última oferta (senão uma dispensa de outro, gravada depois, tiraria a oferta de quem a tem).
7. No aceite com a lista aberta, quem tem a oferta pendente (ou a vencida sem ninguém oferecido depois, como hoje) grava `aceita` nela; os outros, a linha `aceita_pela_lista` (sem `raio_m`). A linha `dispensada` leva o raio da rodada atual.
8. "Quem chega depois leva o 409 'Este pedido passou para outro motoboy.'": hoje esse caso (pedido aberto já iniciado por outro) responde o `400 Order has already started.` do Fleet-Ops, em inglês. Com as rodadas ligadas, o `BarrarAceiteDePedidoEncerrado` passa a responder o 409 do spec; desligadas, nada muda.
9. `POST .../distribuicao/abrir` em rodadas responde 409 com duas mensagens: "A lista deste pedido já está aberta a todos." e "Este pedido não está em oferta.". `raio_m` do painel é o raio da rodada atual, pelo `getAdhocDistance()` do pedido.
10. O push da oferta leva o teto do TTL no próprio objeto (`OfertaDePedido::$segundosDaOferta`), porque o teste de push (`avisos-push.php`) roda sem `config()`.

---

## Convenções

- **Pasta de trabalho:** `C:/tmp/dr` (ramo `distribuicao-rodadas`). Antes de commitar: `git rev-parse --show-toplevel` deve dar `C:/tmp/dr` (a home também é um repo).
- **Rodar um teste:** `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/<arquivo>.php` (a pasta é a instalação do php-wasm desta máquina; ver o cabeçalho do `rodar.mjs`). Imprime `PASSA`/`FALHA` por caso e termina em `FALHAS: <n>`; sai com 0 só com `FALHAS: 0`.
- **Sintaxe:** `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/sintaxe.mjs <arquivo.php> [...]` (`php -l` do 8.2).
- **Datas no banco:** TIMESTAMP gravado como texto no fuso do app (`Distribuicoes::agora()`), lido com `Distribuicoes::data()`. Nunca formate em UTC.
- **Logs:** prefixo `[entregas] distribuição:`, só ids e números.
- **Trava:** a `TravaDoPedido` não é reentrante. Dentro dela só se chamam os métodos `*SemTrava`, o `proximoPasso`/`proximoPassoOuAbrir` e as gravações de `Distribuicoes`; nunca `iniciar`, `avancar`, `avancarOuAbrir`, `vencer`, `recusar`, `recusarOuDispensar`, `abrirATodos` ou `mostrarATodos`. `registrarAceite`, `registrarAceitePelaLista` e `encerrar` não tomam a trava (rodam dentro do aceite).
- **Commits:** mensagem em português, terminando com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Nada de push.

## Estrutura de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `api/database/migrations/2026_10_07_120000_add_rodadas_entregas_distribuicao_table.php` (novo) | Colunas `volta`, `rodada`, `volta_iniciada_em`, `lista_aberta_em`; nas ofertas `volta`, `rodada`, `raio_m` e o índice `(distribuicao_id, volta)`. |
| `api/config/services.php` | `services.entregas.distribuicao_rodadas` (`ENTREGAS_DISTRIBUICAO_RODADAS`). |
| `deploy/docker-stack.yml`, `deploy/stack.env.example` | A variável nova no `x-api-env` e no modelo. |
| `api/app/Support/Entregas/Distribuicao/Distribuicao.php` | Constantes das rodadas, `emRodadas()`, `segundosDaOferta()`, `raioDaRodada()`. |
| `api/app/Support/Entregas/Distribuicao/Encaixe.php` | `noFim()`: "termina tudo e depois vai". |
| `api/app/Support/Entregas/Distribuicao/FilaDeCandidatos.php` | Parâmetro `$noFim`. |
| `api/app/Support/Entregas/Distribuicao/Candidatos.php` | Raio da rodada na busca. |
| `api/app/Support/Entregas/Distribuicao/Distribuicoes.php` | Gravação de volta/rodada/raio, lista aberta, linhas sem oferta, consultas novas. |
| `api/app/Support/Entregas/Distribuicao/Distribuidor.php` | O ciclo em rodadas, recusar × dispensar, "Mostrar a todos agora", aceite pela lista. |
| `api/app/Jobs/Entregas/AvancarDistribuicao.php` (novo) | Job atrasado: o próximo passo quando a volta seguinte pode começar. |
| `api/app/Jobs/Entregas/AvancarOferta.php` | Atraso pelo `segundosDaOferta()`. |
| `api/app/Notifications/Entregas/OfertaDePedido.php`, `AvisosDoMotoboy.php` | Teto do TTL (20 s em rodadas). |
| `api/app/Console/Commands/Entregas/VarrerDistribuicoes.php` | Em rodadas: sem prazo; avança as paradas. |
| `api/app/Console/Commands/Entregas/ReenviarPedidosAbertos.php` | Só o docblock (o comportamento já é o do spec). |
| `api/app/Http/Middleware/BarrarAceiteDePedidoEncerrado.php` | Aceite com a lista aberta; 409 para quem chega depois. |
| `api/app/Http/Controllers/Entregas/MotoboyController.php` | `recusar` → `recusarOuDispensar`. |
| `api/app/Http/Controllers/Entregas/DistribuicaoController.php` | "Mostrar a todos agora" e os campos novos do painel. |
| `api/app/Http/Middleware/FiltrarPedidosAbertosDoMotoboy.php` | Lista aberta, dispensados, `entregas_distribuicao` e o acréscimo até 2R. |
| `scripts/teste-php/distribuicao-rodadas.php` (novo) | Colunas, `Distribuicoes`, ciclo, recusar × dispensar, mostrar, aceite pela lista, job e varredura em rodadas. |
| `scripts/teste-php/distribuicao-encaixe.php`, `distribuicao-fila.php`, `avisos-push.php`, `barrar-aceite.php`, `distribuicao-rotas.php`, `filtrar-pedidos-abertos.php`, `imports-dos-providers.php` | Casos novos acrescentados no fim (os de hoje ficam como estão). |
| `CLAUDE.md` | Subseção "Rodadas" na seção da distribuição e o item 24 do histórico. |

---

### Task 1: Chave, constantes e migration

**Files:**
- Modify: `api/config/services.php` (bloco `entregas`)
- Modify: `deploy/docker-stack.yml` (`x-api-env`)
- Modify: `deploy/stack.env.example`
- Modify: `api/app/Support/Entregas/Distribuicao/Distribuicao.php`
- Create: `api/database/migrations/2026_10_07_120000_add_rodadas_entregas_distribuicao_table.php`
- Create: `scripts/teste-php/distribuicao-rodadas.php`
- Modify: `scripts/teste-php/distribuicao-encaixe.php`

- [ ] **Step 1: Escrever os testes que falham**

Em `scripts/teste-php/distribuicao-encaixe.php`, insira **antes** da linha `echo '== Pontos (texto e não finito)' . PHP_EOL;`:

```php
echo '== emRodadas(), segundosDaOferta() e raioDaRodada()' . PHP_EOL;
\Teste\Config::$valores['services.entregas.distribuicao']         = '1';
\Teste\Config::$valores['services.entregas.distribuicao_rodadas'] = '1';
confere(Distribuicao::emRodadas() === true && Distribuicao::segundosDaOferta() === 20, 'rodadas ligadas: oferta de 20 s');
\Teste\Config::$valores['services.entregas.distribuicao'] = '';
confere(Distribuicao::emRodadas() === false && Distribuicao::segundosDaOferta() === 30, 'rodadas só valem com a distribuição ligada');
\Teste\Config::$valores['services.entregas.distribuicao']         = '1';
\Teste\Config::$valores['services.entregas.distribuicao_rodadas'] = '';
confere(Distribuicao::emRodadas() === false && Distribuicao::segundosDaOferta() === 30, 'rodadas desligadas: 30 s, como hoje');
confere([Distribuicao::raioDaRodada(6000, 1), Distribuicao::raioDaRodada(6000, 2), Distribuicao::raioDaRodada(6000, 3)] === [6000, 9000, 12000], 'raios das rodadas: R, 1,5R e 2R');
confere(Distribuicao::raioDaRodada(6000, 0) === 6000 && Distribuicao::raioDaRodada(6000, 9) === 12000, 'rodada fora da faixa fica entre 1 e 3');
\Teste\Config::$valores['services.entregas.distribuicao']         = null;
\Teste\Config::$valores['services.entregas.distribuicao_rodadas'] = null;
```

Crie `scripts/teste-php/distribuicao-rodadas.php` (os `use` de todas as tasks seguintes já ficam aqui; `use` não carrega a classe):

```php
<?php

// Distribuição em rodadas (ENTREGAS_DISTRIBUICAO_RODADAS): colunas novas, tabelas (Distribuicoes), o ciclo em rodadas e
// voltas (Distribuidor), recusar × dispensar, "Mostrar a todos agora", o aceite pela lista, o job AvancarDistribuicao e a
// varredura. Com a chave desligada valem os testes de distribuicao-ciclo.php, sem mudança.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-rodadas.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Console\Commands\Entregas\VarrerDistribuicoes;
use App\Jobs\Entregas\AvancarDistribuicao;
use App\Jobs\Entregas\AvancarOferta;
use App\Notifications\Entregas\OfertaDePedido;
use App\Support\Entregas\Distribuicao\Candidatos;
use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Distribuicoes;
use App\Support\Entregas\Distribuicao\Distribuidor;
use App\Support\Entregas\Distribuicao\EstimadorDeTempo;
use App\Support\Entregas\Distribuicao\FilaDeCandidatos;
use App\Support\Entregas\Distribuicao\Pontos;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\OrderPing;
use Illuminate\Support\Facades\DB;
use Teste\Config;
use Teste\Fila;
use Teste\Relogio;
use Teste\Trava;

echo '== Colunas novas (migration add_rodadas)' . PHP_EOL;
reiniciarFleetbase();
reiniciarIfood();
$id = DB::table('entregas_distribuicoes')->insertGetId(['pedido_uuid' => 'order-1', 'company_uuid' => 'empresa-1', 'despachada_em' => '2026-10-07 10:00:00', 'fase' => 'ofertas']);
$d  = DB::table('entregas_distribuicoes')->where('id', $id)->first();
confere(($d->volta ?? null) === 1 && ($d->rodada ?? null) === 1 && property_exists($d, 'volta_iniciada_em') && $d->volta_iniciada_em === null && property_exists($d, 'lista_aberta_em') && $d->lista_aberta_em === null, 'distribuição de antes: volta 1, rodada 1, sem início de volta nem lista aberta (' . json_encode($d) . ')');
$idOferta = DB::table('entregas_ofertas')->insertGetId(['distribuicao_id' => $id, 'pedido_uuid' => 'order-1', 'motoboy_uuid' => 'd-a', 'posicao' => 1, 'oferecida_em' => '2026-10-07 10:00:00', 'vence_em' => '2026-10-07 10:00:30', 'resposta' => 'pendente']);
$o        = DB::table('entregas_ofertas')->where('id', $idOferta)->first();
confere(($o->volta ?? null) === 1 && ($o->rodada ?? null) === 1 && property_exists($o, 'raio_m') && $o->raio_m === null, 'oferta de antes: volta 1, rodada 1, sem raio (' . json_encode($o) . ')');
DB::table('entregas_ofertas')->where('id', $idOferta)->update(['resposta' => 'aceita_pela_lista']);
confere(DB::table('entregas_ofertas')->where('distribuicao_id', $id)->where('volta', 1)->value('resposta') === 'aceita_pela_lista', 'a resposta nova cabe na coluna (texto) e a volta filtra');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-encaixe.php`
Expected: erro fatal `Call to undefined method ...Distribuicao::emRodadas()` (sai com 1).

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-rodadas.php`
Expected: `FALHA distribuição de antes...`, e o `where('volta', 1)` lança `coluna desconhecida volta em entregas_ofertas` (sai com 1).

- [ ] **Step 3: Configuração e deploy**

Em `api/config/services.php`, no bloco `'entregas' => [`, troque

```php
        // 1 = matriz de tempos pelo OSRM_HOST; vazio = linha reta. Ligar só com um OSRM próprio
        'distribuicao_osrm' => env('ENTREGAS_DISTRIBUICAO_OSRM'),
    ],
```

por

```php
        // 1 = matriz de tempos pelo OSRM_HOST; vazio = linha reta. Ligar só com um OSRM próprio
        'distribuicao_osrm' => env('ENTREGAS_DISTRIBUICAO_OSRM'),
        // 1 = distribuição em rodadas (20 s, raio crescente, voltas até aceitar, sem alarme a todos); só vale com a
        // distribuição ligada. Vazio = o ciclo de 30 s que abre a todos. Ligar só com o APK 30 em todos os celulares
        'distribuicao_rodadas' => env('ENTREGAS_DISTRIBUICAO_RODADAS'),
    ],
```

Em `deploy/docker-stack.yml`, troque

```yaml
  ENTREGAS_DISTRIBUICAO_OSRM: ${ENTREGAS_DISTRIBUICAO_OSRM:-}
```

por

```yaml
  ENTREGAS_DISTRIBUICAO_OSRM: ${ENTREGAS_DISTRIBUICAO_OSRM:-}
  # distribuição em rodadas (20 s, raio R/1,5R/2R, voltas até aceitar, sem alarme a todos): 1 liga; vazio = ciclo de 30 s
  # que abre a todos. Só vale com ENTREGAS_DISTRIBUICAO=1; ligar só com o APK 30 em todos os celulares
  ENTREGAS_DISTRIBUICAO_RODADAS: ${ENTREGAS_DISTRIBUICAO_RODADAS:-}
```

Em `deploy/stack.env.example`, troque

```
ENTREGAS_DISTRIBUICAO_OSRM=
```

por

```
ENTREGAS_DISTRIBUICAO_OSRM=
# Distribuição em rodadas (só vale com ENTREGAS_DISTRIBUICAO=1): 1 = oferta de 20 s, raio crescente (R, 1,5R, 2R) e
# voltas até alguém aceitar, sem alarme a todos; a lista "Novos pedidos" abre a todos (até 2R) a partir da rodada 2.
# Vazio = o ciclo de 30 s que abre a todos. Ligue SÓ com o APK 30 em todos os celulares (o anterior toca 3 min e vários
# celulares tocariam juntos). Também só chega aos containers com o docker-stack.yml novo colado no Portainer.
ENTREGAS_DISTRIBUICAO_RODADAS=
```

- [ ] **Step 4: Constantes e funções em `Distribuicao`**

Em `api/app/Support/Entregas/Distribuicao/Distribuicao.php`, troque o docblock da classe

```php
/**
 * Entregas RestaurantePro: distribuição de pedidos abertos. Em vez do alarme a todos os motoboys do raio
 * (HandleOrderDispatched do Fleet-Ops), o pedido é oferecido a um motoboy por vez, por SEGUNDOS_DA_OFERTA, na ordem do
 * menor tempo estimado até o cliente (FilaDeCandidatos). Esgotada a fila, ou passados MINUTOS_ATE_ABRIR do despacho,
 * abre a todos no raio, como antes. Ligada por ENTREGAS_DISTRIBUICAO=1 (config services.entregas.distribuicao).
 * Spec: docs/superpowers/specs/2026-10-07-distribuicao-de-pedidos-design.md.
 */
```

por

```php
/**
 * Entregas RestaurantePro: distribuição de pedidos abertos. Em vez do alarme a todos os motoboys do raio
 * (HandleOrderDispatched do Fleet-Ops), o pedido é oferecido a um motoboy por vez, por SEGUNDOS_DA_OFERTA, na ordem do
 * menor tempo estimado até o cliente (FilaDeCandidatos). Esgotada a fila, ou passados MINUTOS_ATE_ABRIR do despacho,
 * abre a todos no raio, como antes. Ligada por ENTREGAS_DISTRIBUICAO=1 (config services.entregas.distribuicao).
 * Spec: docs/superpowers/specs/2026-10-07-distribuicao-de-pedidos-design.md.
 *
 * Em rodadas (ENTREGAS_DISTRIBUICAO_RODADAS=1, emRodadas()): oferta de SEGUNDOS_DA_OFERTA_EM_RODADAS, rodadas de raio
 * R, 1,5R e 2R (raioDaRodada), voltas até alguém aceitar (a seguinte só SEGUNDOS_ENTRE_VOLTAS depois do início da
 * anterior), lista aberta a partir da rodada 2 da volta 1 e nunca o alarme a todos (só na falha).
 * Spec: docs/superpowers/specs/2026-10-07-distribuicao-em-rodadas-design.md.
 */
```

e troque

```php
    public const PENDENTE = 'pendente';
    public const RECUSADA = 'recusada';
    public const VENCIDA  = 'vencida';
    // 'aceita' e 'cancelada' são as mesmas constantes acima
```

por

```php
    public const PENDENTE = 'pendente';
    public const RECUSADA = 'recusada';
    public const VENCIDA  = 'vencida';
    // 'aceita' e 'cancelada' são as mesmas constantes acima
    /** Em rodadas: o motoboy dispensou o pedido pela lista aberta, sem oferta (linha sem oferta, uma por volta). */
    public const DISPENSADA        = 'dispensada';
    /** Em rodadas: aceito pela lista aberta por quem não tinha a oferta. */
    public const ACEITA_PELA_LISTA = 'aceita_pela_lista';

    /** Em rodadas: cada oferta dura isto (o ciclo de hoje, SEGUNDOS_DA_OFERTA). */
    public const SEGUNDOS_DA_OFERTA_EM_RODADAS = 20;
    /** Raio de cada rodada em múltiplos do raio de pedido aberto (R = Order::getAdhocDistance()). */
    public const MULTIPLICADOR_DA_RODADA = [1 => 1.0, 2 => 1.5, 3 => 2.0];
    public const ULTIMA_RODADA           = 3;
    /** A volta nova só começa este tempo depois do início da anterior. */
    public const SEGUNDOS_ENTRE_VOLTAS = 60;
    /** A varredura avança a distribuição em ofertas, sem oferta pendente, parada (updated_at) há mais que isto. */
    public const SEGUNDOS_PARADA = 60;
    /** A lista aberta mostra o pedido ao motoboy com a coleta até este múltiplo de R da posição dele. */
    public const MULTIPLICADOR_DA_LISTA = 2.0;

    /** Resultados do "Mostrar a todos agora" (Distribuidor::mostrarATodos). */
    public const LISTA_ABERTA_AGORA = 'aberta';
    public const LISTA_JA_ABERTA    = 'ja_aberta';
    public const FORA_DE_OFERTAS    = 'fora_de_ofertas';

    /** Distribuição em rodadas: ENTREGAS_DISTRIBUICAO_RODADAS=1, e só com a distribuição ligada. */
    public static function emRodadas(): bool
    {
        return static::ligada() && filter_var(config('services.entregas.distribuicao_rodadas'), FILTER_VALIDATE_BOOLEAN);
    }

    /** Quanto dura uma oferta: 20 s em rodadas, 30 s no ciclo de hoje. */
    public static function segundosDaOferta(): int
    {
        return static::emRodadas() ? static::SEGUNDOS_DA_OFERTA_EM_RODADAS : static::SEGUNDOS_DA_OFERTA;
    }

    /** Raio (m) da rodada 1 a ULTIMA_RODADA a partir do raio de pedido aberto; fora da faixa, a mais próxima. */
    public static function raioDaRodada(int $raio, int $rodada): int
    {
        $rodada = max(1, min(static::ULTIMA_RODADA, $rodada));

        return (int) round($raio * static::MULTIPLICADOR_DA_RODADA[$rodada]);
    }
```

- [ ] **Step 5: A migration**

Crie `api/database/migrations/2026_10_07_120000_add_rodadas_entregas_distribuicao_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: distribuição em rodadas (ENTREGAS_DISTRIBUICAO_RODADAS; ver App\Support\Entregas\Distribuicao).
 * Na distribuição: a volta e a rodada atuais, o início da volta e quando a lista "Novos pedidos" abriu. Em cada oferta: a
 * volta, a rodada e o raio em que saiu. As respostas novas (dispensada, aceita_pela_lista) cabem na coluna `resposta`
 * (texto). volta_iniciada_em é nulo nas distribuições de antes (vale o despachada_em): ADD COLUMN de um TIMESTAMP NOT
 * NULL numa tabela com linhas falha no MySQL estrito.
 * O nome termina em "_entregas_distribuicao_table.php" para o banco em memória dos testes (stubs-ifood.php) ler o esquema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entregas_distribuicoes', function (Blueprint $table) {
            $table->unsignedInteger('volta')->default(1)->after('fase');
            $table->unsignedTinyInteger('rodada')->default(1)->after('volta');
            $table->timestamp('volta_iniciada_em')->nullable()->after('rodada');
            $table->timestamp('lista_aberta_em')->nullable()->after('volta_iniciada_em');
        });

        Schema::table('entregas_ofertas', function (Blueprint $table) {
            $table->unsignedInteger('volta')->default(1)->after('posicao');
            $table->unsignedTinyInteger('rodada')->default(1)->after('volta');
            $table->unsignedInteger('raio_m')->nullable()->after('rodada');
            $table->index(['distribuicao_id', 'volta']);
        });
    }

    public function down(): void
    {
        Schema::table('entregas_ofertas', function (Blueprint $table) {
            $table->dropIndex(['distribuicao_id', 'volta']);
            $table->dropColumn(['volta', 'rodada', 'raio_m']);
        });

        Schema::table('entregas_distribuicoes', function (Blueprint $table) {
            $table->dropColumn(['volta', 'rodada', 'volta_iniciada_em', 'lista_aberta_em']);
        });
    }
};
```

- [ ] **Step 6: Rodar e ver passar**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-encaixe.php`
Expected: `FALHAS: 0`.

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-rodadas.php`
Expected: `FALHAS: 0`.

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-stub-banco.php`
Expected: `FALHAS: 0` (o esquema em memória ganhou as colunas sem quebrar o resto).

- [ ] **Step 7: Commit**

```bash
git rev-parse --show-toplevel
git add api/config/services.php deploy/docker-stack.yml deploy/stack.env.example api/app/Support/Entregas/Distribuicao/Distribuicao.php api/database/migrations/2026_10_07_120000_add_rodadas_entregas_distribuicao_table.php scripts/teste-php/distribuicao-rodadas.php scripts/teste-php/distribuicao-encaixe.php
git commit -m "$(cat <<'EOF'
Distribuição em rodadas: chave, constantes e migration (volta, rodada, lista aberta)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Fila "termina tudo e depois vai" e o raio da rodada

**Files:**
- Modify: `api/app/Support/Entregas/Distribuicao/Encaixe.php`
- Modify: `api/app/Support/Entregas/Distribuicao/FilaDeCandidatos.php`
- Modify: `api/app/Support/Entregas/Distribuicao/Candidatos.php`
- Test: `scripts/teste-php/distribuicao-encaixe.php`, `scripts/teste-php/distribuicao-fila.php`

- [ ] **Step 1: Escrever os testes que falham**

Em `scripts/teste-php/distribuicao-encaixe.php`, insira **antes** da linha `echo '== ligada()' . PHP_EOL;`:

```php
echo '== Encaixe::noFim (distribuição em rodadas)' . PHP_EOL;
confere(Encaixe::noFim(0, [], 3, 4, $dur) === ['tempo_s' => 300 + $loja + 900, 'encaixe' => false, 'atraso_s' => 0], 'livre: igual ao calcular');
$r = Encaixe::noFim(0, $base, 3, 4, $dur);
confere($r === ['tempo_s' => 300 + $loja + 600 + $cliente + 600 + $loja + 900, 'encaixe' => false, 'atraso_s' => 0], 'ocupado: termina A (coleta e entrega) e só depois vai, mesmo com a loja nova no caminho (' . json_encode($r) . ')');
confere(Encaixe::noFim(0, $soEntrega, 3, 4, $dur)['tempo_s'] === $terminaEVai, 'só a entrega A: termina e vai');
```

Em `scripts/teste-php/distribuicao-fila.php`, insira **antes** da linha `Candidatos::$buscarMotoboys = null;` (a penúltima instrução do arquivo):

```php
echo '== Rodadas: "termina tudo e depois vai" e o raio da rodada' . PHP_EOL;
Candidatos::$buscarMotoboys = fn (Order $p, bool $gpsRecente) => [
    ['motoboy' => motoboy('a', 'Ana', [-21.1610, -47.8100]), 'posicao' => [-21.1610, -47.8100], 'distancia' => 1000.0],
    ['motoboy' => motoboy('b', 'Bia', [-21.1682, -47.8100]), 'posicao' => [-21.1682, -47.8100], 'distancia' => 200.0],
    ['motoboy' => motoboy('c', 'Caio', [-21.1673, -47.8100]), 'posicao' => [-21.1673, -47.8100], 'distancia' => 300.0],
];
Candidatos::$buscarParadas = fn (string $empresa, array $uuids) => ['d-c' => [[-21.1709, -47.8100, 'entrega']]];
$fila = (new FilaDeCandidatos($estimador))->para($pedido, Candidatos::elegiveis($pedido, []), true);
confere(array_column($fila, 'public_id') === ['driver_b', 'driver_a', 'driver_c'] && array_column($fila, 'tempo_s') === [425, 575, 602], 'no fim: C (ocupado) termina a entrega dele e só depois vai à loja; fica atrás de A (' . json_encode(array_column($fila, 'tempo_s')) . ')');
confere(array_filter(array_column($fila, 'encaixe')) === [] && $fila[2]['livre'] === false, 'no fim: encaixe sempre falso; C continua marcado como ocupado');
$raioRecebido = 'nenhum';
Candidatos::$buscarMotoboys = function (Order $p, bool $gpsRecente, ?int $raio = null) use (&$raioRecebido) {
    $raioRecebido = $raio;

    return [];
};
Candidatos::elegiveis($pedido, [], 9000);
confere($raioRecebido === 9000, 'elegiveis repassa o raio da rodada à busca');
Candidatos::elegiveis($pedido, []);
confere($raioRecebido === null, 'sem raio: null (a busca usa o raio de pedido aberto)');
```

(Os tempos 425, 575 e 602 s saem da linha reta × 1,3 a 25 km/h com as paradas de 3 min na loja e 2 min no cliente; conferidos fora do PHP.)

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-encaixe.php`
Expected: erro fatal `Call to undefined method ...Encaixe::noFim()`.

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-fila.php`
Expected: `FALHA no fim: ...` (o terceiro argumento é ignorado e C vem em 2º) e `FALHA elegiveis repassa o raio...`.

- [ ] **Step 3: `Encaixe::noFim`**

Em `api/app/Support/Entregas/Distribuicao/Encaixe.php`, insira **antes** de `    /** Segundos de chegada em cada parada da sequência, a partir do ponto de partida. */`:

```php
    /**
     * "Termina tudo e depois vai" (distribuição em rodadas): as paradas que ele ainda tem, na ordem, e depois P e D. Sem
     * encaixe no meio e sem o limite de atraso: o pedido novo nunca atrasa quem já espera.
     *
     * @param array<int, array{indice: int, tipo: string}> $base paradas que faltam, na ordem
     *
     * @return array{tempo_s: int, encaixe: bool, atraso_s: int}
     */
    public static function noFim(int $posicao, array $base, int $p, int $d, callable $dur): array
    {
        $sequencia = array_merge($base, [['indice' => $p, 'tipo' => 'coleta'], ['indice' => $d, 'tipo' => 'entrega']]);
        $chegadas  = static::chegadas($posicao, $sequencia, $dur);

        return ['tempo_s' => (int) end($chegadas), 'encaixe' => false, 'atraso_s' => 0];
    }

```

- [ ] **Step 4: `FilaDeCandidatos::para` com `$noFim`**

Em `api/app/Support/Entregas/Distribuicao/FilaDeCandidatos.php`, troque

```php
    /**
     * @param array<int, array{motoboy: object, posicao: array, distancia: float, paradas: array}> $candidatos
     *
     * @return array<int, array{motoboy_uuid: string, public_id: string, nome: string, tempo_s: int, encaixe: bool, aproximado: bool, livre: bool, distancia_m: int}>
     */
    public function para(Order $pedido, array $candidatos): array
```

por

```php
    /**
     * @param array<int, array{motoboy: object, posicao: array, distancia: float, paradas: array}> $candidatos
     * @param bool $noFim distribuição em rodadas: "termina tudo e depois vai" (Encaixe::noFim), sem encaixe no meio
     *
     * @return array<int, array{motoboy_uuid: string, public_id: string, nome: string, tempo_s: int, encaixe: bool, aproximado: bool, livre: bool, distancia_m: int}>
     */
    public function para(Order $pedido, array $candidatos, bool $noFim = false): array
```

e troque

```php
            $resultado = Encaixe::calcular($posicao, $base, 0, 1, $dur);
```

por

```php
            $resultado = $noFim ? Encaixe::noFim($posicao, $base, 0, 1, $dur) : Encaixe::calcular($posicao, $base, 0, 1, $dur);
```

- [ ] **Step 5: `Candidatos` com o raio da rodada**

Em `api/app/Support/Entregas/Distribuicao/Candidatos.php`, troque

```php
    /** @var null|\Closure(Order, bool): array<int, array{motoboy: Driver, posicao: array, distancia: float}> */
    public static ?\Closure $buscarMotoboys = null;
```

por

```php
    /** @var null|\Closure(Order, bool, ?int): array<int, array{motoboy: Driver, posicao: array, distancia: float}> */
    public static ?\Closure $buscarMotoboys = null;
```

troque

```php
    /**
     * Os candidatos elegíveis à oferta, com as paradas: fora os uuids excluídos (quem já respondeu neste despacho,
     * quem tem oferta pendente de outro pedido).
     *
     * @return array<int, array{motoboy: Driver, posicao: array, distancia: float, paradas: array}>
     */
    public static function elegiveis(Order $pedido, array $excluidos): array
    {
        $motoboys = array_values(array_filter(static::noRaio($pedido, true), fn ($c) => !in_array((string) $c['motoboy']->uuid, $excluidos, true)));
```

por

```php
    /**
     * Os candidatos elegíveis à oferta, com as paradas: fora os uuids excluídos (quem já respondeu neste despacho ou
     * nesta volta, quem tem oferta pendente de outro pedido). $raio: o da rodada (em rodadas); null = raio de pedido aberto.
     *
     * @return array<int, array{motoboy: Driver, posicao: array, distancia: float, paradas: array}>
     */
    public static function elegiveis(Order $pedido, array $excluidos, ?int $raio = null): array
    {
        $motoboys = array_values(array_filter(static::noRaio($pedido, true, $raio), fn ($c) => !in_array((string) $c['motoboy']->uuid, $excluidos, true)));
```

e troque

```php
    /** @return array<int, array{motoboy: Driver, posicao: array, distancia: float}> */
    public static function noRaio(Order $pedido, bool $gpsRecente): array
    {
        if (static::$buscarMotoboys) {
            return (static::$buscarMotoboys)($pedido, $gpsRecente);
        }
```

por

```php
    /**
     * $raio em metros (o da rodada); null = o raio de pedido aberto (Order::getAdhocDistance).
     *
     * @return array<int, array{motoboy: Driver, posicao: array, distancia: float}>
     */
    public static function noRaio(Order $pedido, bool $gpsRecente, ?int $raio = null): array
    {
        if (static::$buscarMotoboys) {
            return (static::$buscarMotoboys)($pedido, $gpsRecente, $raio);
        }
```

e, na mesma função, troque

```php
            ->distanceSphere('location', $coleta, $pedido->getAdhocDistance())
```

por

```php
            ->distanceSphere('location', $coleta, $raio ?? $pedido->getAdhocDistance())
```

- [ ] **Step 6: Rodar e ver passar**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-encaixe.php`
Expected: `FALHAS: 0`.

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-fila.php`
Expected: `FALHAS: 0`.

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-ciclo.php`
Expected: `FALHAS: 0` (o ciclo de hoje não mudou).

- [ ] **Step 7: Commit**

```bash
git add api/app/Support/Entregas/Distribuicao/Encaixe.php api/app/Support/Entregas/Distribuicao/FilaDeCandidatos.php api/app/Support/Entregas/Distribuicao/Candidatos.php scripts/teste-php/distribuicao-encaixe.php scripts/teste-php/distribuicao-fila.php
git commit -m "$(cat <<'EOF'
Distribuição em rodadas: fila "termina tudo e depois vai" e busca pelo raio da rodada

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: `Distribuicoes` (gravação e consultas das rodadas)

**Files:**
- Modify: `api/app/Support/Entregas/Distribuicao/Distribuicoes.php`
- Test: `scripts/teste-php/distribuicao-rodadas.php`

- [ ] **Step 1: Escrever o teste que falha**

Em `scripts/teste-php/distribuicao-rodadas.php`, insira **antes** da última linha (`resumo();`):

```php
echo '== Distribuicoes em rodadas' . PHP_EOL;
reiniciarFleetbase();
reiniciarIfood();
Relogio::$agora = '2026-10-07 10:00:00';
Config::$valores['services.entregas.distribuicao']         = '1';
Config::$valores['services.entregas.distribuicao_rodadas'] = '1';
$d = Distribuicoes::criar(new Order(['uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1', 'adhoc' => true]));
confere($d->volta === 1 && $d->rodada === 1 && $d->volta_iniciada_em === '2026-10-07 10:00:00' && $d->lista_aberta_em === null, 'criar: volta 1, rodada 1, iniciada agora, lista fechada');
$o = Distribuicoes::criarOferta($d, ['motoboy_uuid' => 'd-a', 'tempo_s' => 300, 'encaixe' => false, 'aproximado' => true], 1, 1, 1, 6000);
confere($o->vence_em === '2026-10-07 10:00:20' && $o->volta === 1 && $o->rodada === 1 && $o->raio_m === 6000, 'criarOferta em rodadas: vence em 20 s, com volta, rodada e raio (' . json_encode($o) . ')');
Relogio::$agora = '2026-10-07 10:00:25';
Distribuicoes::irParaRodada($d->id, 2);
confere(Distribuicoes::abrirLista($d->id) === true && Distribuicoes::abrirLista($d->id) === false, 'abrirLista: abre uma vez só');
$d = Distribuicoes::porId($d->id);
confere($d->rodada === 2 && $d->lista_aberta_em === '2026-10-07 10:00:25' && $d->updated_at === '2026-10-07 10:00:25', 'irParaRodada e abrirLista gravam a rodada, a hora e o updated_at');
$dispensa = Distribuicoes::registrarResposta($d, 'd-b', 'dispensada', 9000);
confere($dispensa->resposta === 'dispensada' && $dispensa->oferecida_em === '2026-10-07 10:00:25' && $dispensa->vence_em === '2026-10-07 10:00:25' && $dispensa->respondida_em === '2026-10-07 10:00:25' && $dispensa->volta === 1 && $dispensa->rodada === 2 && $dispensa->raio_m === 9000 && $dispensa->posicao === 2 && $dispensa->tempo_estimado_s === null, 'registrarResposta: linha sem oferta, oferecida = vence = respondida = agora (' . json_encode($dispensa) . ')');
confere(Distribuicoes::ofertaParaAceite('order-1', 'd-a')?->id === $o->id, 'ofertaParaAceite ignora a linha dispensada: a pendente de A continua valendo');
Distribuicoes::responder($o->id, 'vencida');
confere(Distribuicoes::motoboysDaVolta($d->id, 1) === ['d-a', 'd-b'] && Distribuicoes::motoboysQueDispensaramNaVolta($d->id, 1) === ['d-b'], 'quem tem linha na volta (qualquer resposta) e quem recusou ou dispensou (a vencida não some da lista)');
Relogio::$agora = '2026-10-07 10:01:00';
Distribuicoes::novaVolta($d->id, 2);
$d = Distribuicoes::porId($d->id);
confere($d->volta === 2 && $d->rodada === 1 && $d->volta_iniciada_em === '2026-10-07 10:01:00' && Distribuicoes::motoboysDaVolta($d->id, 2) === [], 'novaVolta: volta 2, rodada 1, iniciada agora, ninguém perguntado ainda');
Relogio::$agora = '2026-10-07 10:02:01';
confere(array_map(fn ($x) => $x->id, Distribuicoes::emOfertasParadasHa(60)) === [1], 'emOfertasParadasHa: em ofertas, sem pendente, parada há mais de 60 s');
Distribuicoes::tocar(1);
confere(Distribuicoes::emOfertasParadasHa(60) === [] && Distribuicoes::porId(1)->updated_at === '2026-10-07 10:02:01', 'tocar: updated_at agora');
Relogio::$agora = '2026-10-07 10:03:05';
Distribuicoes::criarOferta($d, ['motoboy_uuid' => 'd-a', 'tempo_s' => 300, 'encaixe' => false, 'aproximado' => true], 3, 2, 1, 6000);
confere(Distribuicoes::emOfertasParadasHa(60) === [], 'com oferta pendente: não está parada');
Relogio::$agora = '2026-10-09 10:00:00';
DB::table('entregas_ofertas')->update(['resposta' => 'vencida']);
confere(Distribuicoes::emOfertasParadasHa(60) === [], 'despachada há mais de 24 h: a varredura não mexe');
Distribuicoes::criar(new Order(['uuid' => 'order-2', 'public_id' => 'order_2', 'company_uuid' => 'empresa-1']));
confere(array_map(fn ($x) => $x->pedido_uuid, Distribuicoes::comListaAberta('empresa-1')) === ['order-1'] && Distribuicoes::comListaAberta('empresa-2') === [], 'comListaAberta: só as em ofertas com a lista aberta, da empresa');

```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-rodadas.php`
Expected: `FALHA criar: volta 1, rodada 1, iniciada agora...` e depois erro fatal `Call to undefined method ...Distribuicoes::irParaRodada()`.

- [ ] **Step 3: Implementar**

Em `api/app/Support/Entregas/Distribuicao/Distribuicoes.php`:

(a) No `criar`, troque

```php
            'despachada_em' => $agora,
            'fase'          => Distribuicao::FASE_OFERTAS,
```

por

```php
            'despachada_em'     => $agora,
            'fase'              => Distribuicao::FASE_OFERTAS,
            'volta_iniciada_em' => $agora, // volta e rodada: 1 (padrão da coluna)
```

(b) Troque o `criarOferta` inteiro

```php
    /** @param array{motoboy_uuid: string, tempo_s: int, encaixe: bool, aproximado: bool} $candidato */
    public static function criarOferta(object $distribuicao, array $candidato, int $posicao): object
    {
        $agora = now()->format('Y-m-d H:i:s');
        $vence = static::data($agora)->addSeconds(Distribuicao::SEGUNDOS_DA_OFERTA)->format('Y-m-d H:i:s');
        $id    = DB::table(static::OFERTAS)->insertGetId([
            'distribuicao_id'  => (int) $distribuicao->id,
            'pedido_uuid'      => (string) $distribuicao->pedido_uuid,
            'motoboy_uuid'     => $candidato['motoboy_uuid'],
            'posicao'          => $posicao,
            'tempo_estimado_s' => (int) $candidato['tempo_s'],
            'encaixe'          => (bool) $candidato['encaixe'],
            'aproximado'       => (bool) $candidato['aproximado'],
            'oferecida_em'     => $agora,
            'vence_em'         => $vence,
            'resposta'         => Distribuicao::PENDENTE,
            'created_at'       => $agora,
            'updated_at'       => $agora,
        ]);

        return static::oferta($id);
    }
```

por

```php
    /**
     * A oferta pendente a um motoboy, vencendo em Distribuicao::segundosDaOferta(). $volta, $rodada e $raioM: em que volta,
     * rodada e raio ela saiu (o ciclo de hoje grava 1, 1 e null).
     *
     * @param array{motoboy_uuid: string, tempo_s: int, encaixe: bool, aproximado: bool} $candidato
     */
    public static function criarOferta(object $distribuicao, array $candidato, int $posicao, int $volta = 1, int $rodada = 1, ?int $raioM = null): object
    {
        $agora = now()->format('Y-m-d H:i:s');
        $vence = static::data($agora)->addSeconds(Distribuicao::segundosDaOferta())->format('Y-m-d H:i:s');
        $id    = DB::table(static::OFERTAS)->insertGetId([
            'distribuicao_id'  => (int) $distribuicao->id,
            'pedido_uuid'      => (string) $distribuicao->pedido_uuid,
            'motoboy_uuid'     => $candidato['motoboy_uuid'],
            'posicao'          => $posicao,
            'volta'            => $volta,
            'rodada'           => $rodada,
            'raio_m'           => $raioM,
            'tempo_estimado_s' => (int) $candidato['tempo_s'],
            'encaixe'          => (bool) $candidato['encaixe'],
            'aproximado'       => (bool) $candidato['aproximado'],
            'oferecida_em'     => $agora,
            'vence_em'         => $vence,
            'resposta'         => Distribuicao::PENDENTE,
            'created_at'       => $agora,
            'updated_at'       => $agora,
        ]);

        return static::oferta($id);
    }

    /**
     * Uma linha sem oferta (em rodadas: `dispensada` pela lista, `aceita_pela_lista`), na volta e rodada atuais da
     * distribuição: oferecida, vencida e respondida agora.
     */
    public static function registrarResposta(object $distribuicao, string $motoboyUuid, string $resposta, ?int $raioM = null): object
    {
        $agora = static::agora();
        $id    = DB::table(static::OFERTAS)->insertGetId([
            'distribuicao_id'  => (int) $distribuicao->id,
            'pedido_uuid'      => (string) $distribuicao->pedido_uuid,
            'motoboy_uuid'     => $motoboyUuid,
            'posicao'          => count(static::ofertas((int) $distribuicao->id)) + 1,
            'volta'            => (int) ($distribuicao->volta ?? 1),
            'rodada'           => (int) ($distribuicao->rodada ?? 1),
            'raio_m'           => $raioM,
            'tempo_estimado_s' => null,
            'encaixe'          => false,
            'aproximado'       => false,
            'oferecida_em'     => $agora,
            'vence_em'         => $agora,
            'resposta'         => $resposta,
            'respondida_em'    => $agora,
            'created_at'       => $agora,
            'updated_at'       => $agora,
        ]);

        return static::oferta($id);
    }

    public static function irParaRodada(int $id, int $rodada): void
    {
        DB::table(static::TABELA)->where('id', $id)->update(['rodada' => $rodada, 'updated_at' => static::agora()]);
    }

    /** Volta nova: rodada 1, iniciada agora. */
    public static function novaVolta(int $id, int $volta): void
    {
        $agora = static::agora();
        DB::table(static::TABELA)->where('id', $id)->update(['volta' => $volta, 'rodada' => 1, 'volta_iniciada_em' => $agora, 'updated_at' => $agora]);
    }

    /** Grava lista_aberta_em, se a lista ainda estava fechada. True se abriu agora. */
    public static function abrirLista(int $id): bool
    {
        $agora = static::agora();

        return DB::table(static::TABELA)->where('id', $id)->whereNull('lista_aberta_em')->update(['lista_aberta_em' => $agora, 'updated_at' => $agora]) > 0;
    }

    /** updated_at agora: a varredura só volta a esta distribuição depois de SEGUNDOS_PARADA. */
    public static function tocar(int $id): void
    {
        DB::table(static::TABELA)->where('id', $id)->update(['updated_at' => static::agora()]);
    }

    /** @return array<string> uuids com qualquer linha na volta (oferta de qualquer resposta, dispensa): fora das ofertas da volta */
    public static function motoboysDaVolta(int $distribuicaoId, int $volta): array
    {
        return array_values(array_unique(DB::table(static::OFERTAS)->where('distribuicao_id', $distribuicaoId)->where('volta', $volta)->pluck('motoboy_uuid')->all()));
    }

    /** @return array<string> uuids que recusaram ou dispensaram na volta: o pedido some da lista aberta deles */
    public static function motoboysQueDispensaramNaVolta(int $distribuicaoId, int $volta): array
    {
        return array_values(array_unique(DB::table(static::OFERTAS)->where('distribuicao_id', $distribuicaoId)->where('volta', $volta)
            ->whereIn('resposta', [Distribuicao::RECUSADA, Distribuicao::DISPENSADA])->pluck('motoboy_uuid')->all()));
    }

    /**
     * Em rodadas: as distribuições em ofertas, sem oferta pendente, paradas (updated_at) há mais de $segundos e
     * despachadas nas últimas 24 h (a varredura avança; as mais antigas ficam com o observador do Order).
     */
    public static function emOfertasParadasHa(int $segundos): array
    {
        $limite = now()->subSeconds($segundos)->format('Y-m-d H:i:s');
        $dia    = now()->subDays(1)->format('Y-m-d H:i:s');
        $linhas = DB::table(static::TABELA)->where('fase', Distribuicao::FASE_OFERTAS)->where('updated_at', '<', $limite)
            ->where('despachada_em', '>=', $dia)->orderBy('id')->get()->all();

        return array_values(array_filter($linhas, fn ($d) => !static::ofertaPendente((int) $d->id)));
    }

    /** Em rodadas: as distribuições em ofertas com a lista aberta da empresa (a lista do app acrescenta as além de R). */
    public static function comListaAberta(string $empresa, int $limite = 50): array
    {
        return DB::table(static::TABELA)->where('company_uuid', $empresa)->where('fase', Distribuicao::FASE_OFERTAS)
            ->whereNotNull('lista_aberta_em')->orderBy('id', 'desc')->limit($limite)->get()->all();
    }
```

(c) No `ofertaParaAceite`, troque

```php
        $ultima = DB::table(static::OFERTAS)->where('distribuicao_id', $distribuicao->id)->orderBy('id', 'desc')->first();
```

por

```php
        // a última oferta de verdade: as linhas sem oferta (dispensa, aceite pela lista) não tiram a oferta de quem a tem
        $ultima = DB::table(static::OFERTAS)->where('distribuicao_id', $distribuicao->id)
            ->whereNotIn('resposta', [Distribuicao::DISPENSADA, Distribuicao::ACEITA_PELA_LISTA])->orderBy('id', 'desc')->first();
```

- [ ] **Step 4: Rodar e ver passar**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-rodadas.php`
Expected: `FALHAS: 0`.

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-ciclo.php`
Expected: `FALHAS: 0` (sem a chave, a oferta continua vencendo em 30 s).

- [ ] **Step 5: Commit**

```bash
git add api/app/Support/Entregas/Distribuicao/Distribuicoes.php scripts/teste-php/distribuicao-rodadas.php
git commit -m "$(cat <<'EOF'
Distribuição em rodadas: Distribuicoes grava volta, rodada, raio e lista aberta

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Push da oferta com o teto de 20 s

**Files:**
- Modify: `api/app/Notifications/Entregas/OfertaDePedido.php`
- Modify: `api/app/Notifications/Entregas/AvisosDoMotoboy.php` (`validade`)
- Test: `scripts/teste-php/avisos-push.php`

- [ ] **Step 1: Escrever o teste que falha**

Em `scripts/teste-php/avisos-push.php`, insira **antes** da linha `echo '== Aviso sem tradução' . PHP_EOL;`:

```php
echo '== Oferta de 20 s (distribuição em rodadas)' . PHP_EOL;
$oferta20 = new App\Notifications\Entregas\OfertaDePedido(pedidoDoTeste(), 850, $venceEm, 20);
$relogio('2026-10-07 09:59:00');
confere((enviado($oferta20)['android']['ttl'] ?? null) === '20s', 'oferta de 20 s: ttl no máximo 20s');
$relogio('2026-10-07 10:00:20');
confere((enviado($oferta20)['android']['ttl'] ?? null) === '10s', 'oferta de 20 s faltando 10 s: ttl 10s');
\Carbon\CarbonImmutable::$agoraTeste = null;
confere($oferta->segundosDaOferta === 30 && $oferta20->segundosDaOferta === 20, 'sem o 4º argumento: 30 s, como hoje');
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/avisos-push.php`
Expected: `FALHA oferta de 20 s: ttl no máximo 20s` (sai `30s`) e aviso de propriedade indefinida `segundosDaOferta`.

- [ ] **Step 3: Implementar**

Em `api/app/Notifications/Entregas/OfertaDePedido.php`, troque

```php
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\OrderPing;
```

por

```php
use App\Support\Entregas\Distribuicao\Distribuicao;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\OrderPing;
```

troque

```php
 * do envio: o app não depende do relógio do celular) e o
 * TTL de SEGUNDOS_DA_OFERTA (AvisosDoMotoboy).
 */
```

por

```php
 * do envio: o app não depende do relógio do celular) e o
 * TTL do que falta até vencer, no máximo $segundosDaOferta (30 s; 20 s na distribuição em rodadas) (AvisosDoMotoboy).
 */
```

e troque

```php
    public function __construct(Order $order, $distance, public \DateTimeInterface $venceEm)
```

por

```php
    public function __construct(Order $order, $distance, public \DateTimeInterface $venceEm, public int $segundosDaOferta = Distribuicao::SEGUNDOS_DA_OFERTA)
```

Em `api/app/Notifications/Entregas/AvisosDoMotoboy.php`, troque

```php
    /** O android.ttl do alarme: a oferta vale o tempo que falta até vencer (1 s a SEGUNDOS_DA_OFERTA); o resto em VALIDADE_ALARME. */
```

por

```php
    /** O android.ttl do alarme: a oferta vale o tempo que falta até vencer (1 s ao segundosDaOferta dela: 30 s, ou 20 s em rodadas); o resto em VALIDADE_ALARME. */
```

e troque

```php
        return max(1, min(Distribuicao::SEGUNDOS_DA_OFERTA, $falta)) . 's';
```

por

```php
        return max(1, min($notificacao->segundosDaOferta, $falta)) . 's';
```

Depois confira se o `use App\Support\Entregas\Distribuicao\Distribuicao;` do `AvisosDoMotoboy.php` ainda é usado (`grep -n "Distribuicao::" api/app/Notifications/Entregas/AvisosDoMotoboy.php`); se não houver mais uso, apague a linha do `use`.

- [ ] **Step 4: Rodar e ver passar**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/avisos-push.php`
Expected: `FALHAS: 0`.

- [ ] **Step 5: Commit**

```bash
git add api/app/Notifications/Entregas/OfertaDePedido.php api/app/Notifications/Entregas/AvisosDoMotoboy.php scripts/teste-php/avisos-push.php
git commit -m "$(cat <<'EOF'
Distribuição em rodadas: push da oferta com o teto de TTL da própria oferta (20 s em rodadas)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: O ciclo em rodadas e voltas (`Distribuidor`) e o job `AvancarDistribuicao`

**Files:**
- Create: `api/app/Jobs/Entregas/AvancarDistribuicao.php`
- Modify: `api/app/Jobs/Entregas/AvancarOferta.php`
- Modify: `api/app/Support/Entregas/Distribuicao/Distribuidor.php`
- Test: `scripts/teste-php/distribuicao-rodadas.php`

- [ ] **Step 1: Escrever os testes que falham**

Em `scripts/teste-php/distribuicao-rodadas.php`, insira **antes** da última linha (`resumo();`):

```php
echo '== Rodadas: R, 1,5R, 2R e a volta nova' . PHP_EOL;

function ponto(array $p): object
{
    return new class($p) { public function __construct(private array $p) {} public function getLat() { return $this->p[0]; } public function getLng() { return $this->p[1]; } };
}

/** Motoboy livre a $km ao norte da coleta (0,009° de latitude ≈ 1 km), no formato da busca de Candidatos. */
function motoboyA(string $id, string $nome, float $km): array
{
    $posicao = [-21.1700 + 0.009 * $km, -47.8100];
    $motoboy = new Driver(['uuid' => 'd-' . $id, 'public_id' => 'driver_' . $id, 'company_uuid' => 'empresa-1', 'name' => $nome, 'online' => true, 'location' => ponto($posicao)]);

    return ['motoboy' => $motoboy, 'posicao' => $posicao, 'distancia' => $km * 1000];
}

/** Os motoboys disponíveis; a busca falsa filtra pelo raio pedido, como o distanceSphere (null = R = 6000). */
function definirMotoboys(array $motoboys): void
{
    Driver::$todos              = array_map(fn ($c) => $c['motoboy'], $motoboys);
    Candidatos::$buscarMotoboys = fn ($pedido, $gpsRecente, $raio = null) => array_values(array_filter($motoboys, fn ($c) => $c['distancia'] <= ($raio ?? 6000)));
}

/** Pedido aberto (R = 6 km) com as rodadas ligadas; tempos em linha reta. Devolve o Distribuidor. */
function cenarioRodadas(array $motoboys): Distribuidor
{
    reiniciarFleetbase();
    reiniciarIfood();
    Relogio::$agora = '2026-10-07 10:00:00';
    Config::$valores['services.entregas.distribuicao']         = '1';
    Config::$valores['services.entregas.distribuicao_rodadas'] = '1';
    session(['company' => 'empresa-1']);
    $pedido          = new Order(['uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1', 'adhoc' => true, 'dispatched' => true, 'status' => 'dispatched']);
    $pedido->payload = (object) ['pickup' => (object) ['location' => ponto([-21.1700, -47.8100])], 'dropoff' => (object) ['location' => ponto([-21.1800, -47.8100])]];
    Order::$todos[]  = $pedido;
    definirMotoboys($motoboys);
    Candidatos::$buscarParadas = fn () => [];
    $estimador = new class extends EstimadorDeTempo {
        public function matriz(array $pontos): array { $m = []; foreach ($pontos as $i => $a) { foreach ($pontos as $j => $b) { $m[$i][$j] = (float) Pontos::segundos($a, $b); } } return ['durations' => $m, 'aproximado' => true]; }
    };

    return new Distribuidor(new FilaDeCandidatos($estimador));
}

function pedidoDoCenario(): Order { return Order::$todos[0]; }
function ofertas(): array { return DB::table('entregas_ofertas')->orderBy('id')->get()->all(); }
function distribuicao(): ?object { return DB::table('entregas_distribuicoes')->where('id', 1)->first(); }
function avisos(): array { return array_map(fn ($a) => [$a[0], get_class($a[1])], Driver::$avisos); }
function alarmesGerais(): array { return array_values(array_filter(avisos(), fn ($a) => $a[1] === OrderPing::class)); }

$ana   = motoboyA('ana', 'Ana', 2);
$bruno = motoboyA('bruno', 'Bruno', 4);
$caio  = motoboyA('caio', 'Caio', 7);
$davi  = motoboyA('davi', 'Davi', 11);

$dist = cenarioRodadas([$ana, $bruno, $caio, $davi]);
$dist->iniciar(pedidoDoCenario());
$o = ofertas()[0];
confere($o->motoboy_uuid === 'd-ana' && $o->volta === 1 && $o->rodada === 1 && $o->raio_m === 6000 && $o->vence_em === '2026-10-07 10:00:20' && $o->encaixe === false, 'volta 1 · rodada 1 (6 km): Ana, a mais perto, por 20 s (' . json_encode($o) . ')');
confere(avisos() === [['driver_ana', OfertaDePedido::class]] && Driver::$avisos[0][1]->segundosDaOferta === 20, 'push da oferta de 20 s só para Ana');
confere(Fila::$jobs[0] instanceof AvancarOferta && Fila::$jobs[0]->delay === 20, 'job AvancarOferta em 20 s');
confere(distribuicao()->lista_aberta_em === null && distribuicao()->fase === 'ofertas', 'rodada 1 da volta 1: lista fechada');

Relogio::$agora = '2026-10-07 10:00:20';
(new AvancarOferta(1))->handle($dist);
confere(ofertas()[0]->resposta === 'vencida' && ofertas()[1]->motoboy_uuid === 'd-bruno' && ofertas()[1]->rodada === 1, 'Ana deixou vencer: Bruno, ainda na rodada 1');

Relogio::$agora = '2026-10-07 10:00:40';
confere($dist->recusar('order-1', $bruno['motoboy']) === true, 'Bruno recusa');
confere(ofertas()[2]->motoboy_uuid === 'd-caio' && ofertas()[2]->rodada === 2 && ofertas()[2]->raio_m === 9000 && distribuicao()->rodada === 2, 'rodada 1 sem ninguém novo: rodada 2 (9 km), Caio');
confere(distribuicao()->lista_aberta_em === '2026-10-07 10:00:40' && logou('lista aberta', 'info') && logou('rodada 2 (raio 9000 m)', 'info'), 'ao sair da rodada 1 da volta 1: lista aberta (e os logs)');

Relogio::$agora = '2026-10-07 10:01:00';
(new AvancarOferta(3))->handle($dist);
confere(ofertas()[3]->motoboy_uuid === 'd-davi' && ofertas()[3]->rodada === 3 && ofertas()[3]->raio_m === 12000, 'rodada 3 (12 km): Davi');

Relogio::$agora = '2026-10-07 10:01:20';
(new AvancarOferta(4))->handle($dist);
confere(ofertas()[4]->motoboy_uuid === 'd-ana' && ofertas()[4]->volta === 2 && ofertas()[4]->rodada === 1 && distribuicao()->volta === 2 && distribuicao()->volta_iniciada_em === '2026-10-07 10:01:20', 'depois da rodada 3, já com 1 min da volta 1: volta 2, Ana de novo');
confere(logou('volta 2', 'info'), 'log: volta 2');

Relogio::$agora = '2026-10-07 10:01:40';
(new AvancarOferta(5))->handle($dist);
confere(ofertas()[5]->motoboy_uuid === 'd-bruno' && ofertas()[5]->volta === 2, 'quem recusou na volta 1 recebe de novo na volta 2');
confere(distribuicao()->fase === 'ofertas' && alarmesGerais() === [] && count(avisos()) === 6, 'nunca abre a todos: só ofertas, uma por vez');
confere(logsSem(['Ana', 'Bruno', '-21.1']), 'logs só com ids e números');

echo '== Rodadas: um motoboy só (1 min entre voltas)' . PHP_EOL;
$dist = cenarioRodadas([$ana]);
$dist->iniciar(pedidoDoCenario());
Relogio::$agora = '2026-10-07 10:00:20';
(new AvancarOferta(1))->handle($dist);
$job = end(Fila::$jobs);
confere(count(ofertas()) === 1 && distribuicao()->rodada === 3 && distribuicao()->fase === 'ofertas' && distribuicao()->lista_aberta_em === '2026-10-07 10:00:20', 'ninguém mais até 2R: rodada 3, lista aberta, sem oferta nova');
confere($job instanceof AvancarDistribuicao && $job->pedidoUuid === 'order-1' && $job->delay === 40 && alarmesGerais() === [], 'próximo passo agendado para quando completar 1 min da volta (40 s); sem alarme a todos');
Relogio::$agora = '2026-10-07 10:01:00';
$job->handle($dist);
confere(count(ofertas()) === 2 && ofertas()[1]->motoboy_uuid === 'd-ana' && ofertas()[1]->volta === 2 && distribuicao()->volta === 2, 'com 1 min: volta 2, Ana de novo');
$job->handle($dist);
confere(count(ofertas()) === 2, 'job repetido com a oferta pendente: espera');
$preso = new AvancarDistribuicao('order-1');
Trava::$ocupadas['entregas:pedido:order-1'] = true;
$preso->handle($dist);
confere($preso->liberadoPor === 2, 'job com a trava ocupada: volta à fila em 2 s');
unset(Trava::$ocupadas['entregas:pedido:order-1']);
Config::$valores['services.entregas.distribuicao_rodadas'] = '';
DB::table('entregas_ofertas')->update(['resposta' => 'vencida']);
(new AvancarDistribuicao('order-1'))->handle($dist);
confere(count(ofertas()) === 2, 'job com as rodadas desligadas: não faz nada');

echo '== Rodadas: ninguém em 2R' . PHP_EOL;
$dist = cenarioRodadas([]);
$dist->iniciar(pedidoDoCenario());
$job = end(Fila::$jobs);
confere(ofertas() === [] && distribuicao()->fase === 'ofertas' && distribuicao()->rodada === 3 && distribuicao()->lista_aberta_em === '2026-10-07 10:00:00' && Driver::$avisos === [], 'sem ninguém no despacho: fica em ofertas, lista aberta, sem alarme');
confere($job instanceof AvancarDistribuicao && $job->delay === 60, 'tenta de novo quando completar 1 min');
Relogio::$agora = '2026-10-07 10:01:00';
$job->handle($dist);
confere(distribuicao()->volta === 2 && distribuicao()->rodada === 3 && distribuicao()->updated_at === '2026-10-07 10:01:00' && logou('aguardando motoboy', 'info') && count(Fila::$jobs) === 1, 'volta 2 inteira sem ninguém: para e espera, sem outro job (a varredura tenta a cada minuto); no máximo uma volta por passo');

echo '== Rodadas: recálculo a cada oferta e oferta pendente de outro pedido' . PHP_EOL;
$dist = cenarioRodadas([$ana, $bruno]);
$dist->iniciar(pedidoDoCenario());
definirMotoboys([$ana, $bruno, motoboyA('eva', 'Eva', 1)]); // Eva ficou disponível durante a oferta de Ana
Relogio::$agora = '2026-10-07 10:00:20';
(new AvancarOferta(1))->handle($dist);
confere(ofertas()[1]->motoboy_uuid === 'd-eva' && ofertas()[1]->rodada === 1, 'quem ficou disponível entra na hora: Eva (1 km) antes de Bruno');

$dist = cenarioRodadas([$ana, $bruno]);
Distribuicoes::criarOferta((object) ['id' => 99, 'pedido_uuid' => 'order-9'], ['motoboy_uuid' => 'd-ana', 'tempo_s' => 1, 'encaixe' => false, 'aproximado' => false], 1);
$dist->iniciar(pedidoDoCenario());
$doPedido = fn () => array_values(array_filter(ofertas(), fn ($o) => $o->pedido_uuid === 'order-1'));
confere(count($doPedido()) === 1 && $doPedido()[0]->motoboy_uuid === 'd-bruno', 'Ana tem oferta pendente de outro pedido: Bruno primeiro');
DB::table('entregas_ofertas')->where('pedido_uuid', 'order-9')->update(['resposta' => 'recusada']);
Relogio::$agora = '2026-10-07 10:00:20';
$dist->vencer((int) $doPedido()[0]->id);
confere($doPedido()[1]->motoboy_uuid === 'd-ana' && $doPedido()[1]->rodada === 1 && $doPedido()[1]->volta === 1, 'livre de novo e ainda não perguntada nesta volta: Ana entra na mesma rodada');

echo '== Rodadas: pedido com motoboy e falha no ciclo' . PHP_EOL;
$dist = cenarioRodadas([$ana]);
$dist->iniciar(pedidoDoCenario());
pedidoDoCenario()->driver_assigned_uuid = 'd-x';
Relogio::$agora = '2026-10-07 10:00:20';
$dist->vencer(1);
confere(distribuicao()->fase === 'encerrada' && distribuicao()->motivo === 'atribuida' && count(ofertas()) === 1, 'pedido já com motoboy: encerra (atribuida) sem oferecer a mais ninguém');

$dist = cenarioRodadas([$ana, $bruno]);
$dist->iniciar(pedidoDoCenario());
$quebrado = new Distribuidor(new FilaDeCandidatos(new class extends EstimadorDeTempo {
    public function matriz(array $pontos): array { throw new \RuntimeException('bug no estimador'); }
}));
Relogio::$agora = '2026-10-07 10:00:20';
$quebrado->vencer(1);
confere(distribuicao()->fase === 'aberta' && distribuicao()->motivo === 'falha' && count(alarmesGerais()) === 2, 'falha depois do vencimento: aberta (falha) com o alarme geral, a rede de segurança de hoje');

```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-rodadas.php`
Expected: `FALHA volta 1 · rodada 1 (6 km)...` (sai a oferta de 30 s do ciclo de hoje, sem raio) e, mais adiante, `FALHA ... nunca abre a todos` (o ciclo de hoje abre por `fila_esgotada`); depois erro fatal `Class "App\Jobs\Entregas\AvancarDistribuicao" not found`.

- [ ] **Step 3: O job `AvancarDistribuicao`**

Crie `api/app/Jobs/Entregas/AvancarDistribuicao.php`:

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
 * Entregas RestaurantePro: distribuição em rodadas. Depois da rodada 3, a volta seguinte só começa
 * SEGUNDOS_ENTRE_VOLTAS depois do início da anterior: o Distribuidor agenda este job para essa hora e ele dá o próximo
 * passo (Distribuidor::avancarOuAbrir). Fila `default` (o worker `queue`), com atraso. Repetido não faz mal: com uma
 * oferta pendente o passo só espera. Trava do pedido ocupada: volta à fila em ESPERA_DA_TRAVA s, até $tries vezes.
 * Reserva para job perdido (Redis sem persistência): o comando entregas:distribuicao-varrer.
 */
class AvancarDistribuicao implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const ESPERA_DA_TRAVA = 2;

    public int $tries = 3;

    /** Abaixo do retry_after da conexão redis (90 s). */
    public int $timeout = 60;

    public function __construct(public string $pedidoUuid)
    {
        $this->afterCommit = true;
    }

    public static function agendar(string $pedidoUuid, int $segundos): void
    {
        static::dispatch($pedidoUuid)->delay(now()->addSeconds(max(1, $segundos)));
    }

    public function handle(Distribuidor $distribuidor): void
    {
        if (!Distribuicao::emRodadas()) {
            return;
        }
        try {
            $distribuidor->avancarOuAbrir($this->pedidoUuid);
        } catch (LockTimeoutException $e) {
            Log::info('[entregas] distribuição: trava ocupada ao avançar a distribuição; tentando de novo');
            $this->release(static::ESPERA_DA_TRAVA);
        }
    }
}
```

- [ ] **Step 4: `AvancarOferta` pelo tempo da oferta**

Em `api/app/Jobs/Entregas/AvancarOferta.php`, troque

```php
        static::dispatch($ofertaId)->delay(now()->addSeconds(Distribuicao::SEGUNDOS_DA_OFERTA));
```

por

```php
        static::dispatch($ofertaId)->delay(now()->addSeconds(Distribuicao::segundosDaOferta()));
```

e, no docblock, troque `vence a oferta de um pedido aberto SEGUNDOS_DA_OFERTA depois de enviada` por `vence a oferta de um pedido aberto Distribuicao::segundosDaOferta() depois de enviada (30 s; 20 s em rodadas)`.

- [ ] **Step 5: O ciclo em rodadas no `Distribuidor`**

Em `api/app/Support/Entregas/Distribuicao/Distribuidor.php`:

(a) Troque

```php
use App\Jobs\Entregas\AvancarOferta;
```

por

```php
use App\Jobs\Entregas\AvancarDistribuicao;
use App\Jobs\Entregas\AvancarOferta;
```

(b) No docblock da classe, troque

```php
 * Pedido que já tem motoboy ou está encerrado (o Order::updated não viu: saveQuietly) não recebe oferta nem alarme: a
```

por

```php
 * Em rodadas (Distribuicao::emRodadas), o avancarSemTrava segue o avancarEmRodadas: oferta de 20 s, rodadas R, 1,5R e
 * 2R, voltas até alguém aceitar e nunca o alarme a todos (sem prazo de 3 min; só a falha ainda abre com o alarme geral).
 * recusarOuDispensar, mostrarATodos e registrarAceitePelaLista são do ciclo em rodadas.
 *
 * Pedido que já tem motoboy ou está encerrado (o Order::updated não viu: saveQuietly) não recebe oferta nem alarme: a
```

(c) Logo **depois** do método `avancar` (que termina em `    }` depois de `$this->proximoPasso($pedidoUuid);` e `});`), insira:

```php

    /**
     * Como o avancar, mas uma falha no passo abre a todos (falha) com o alarme geral, como depois de uma resposta. Usado
     * pelo job AvancarDistribuicao e pela varredura em rodadas. Lança LockTimeoutException se a trava não sair.
     */
    public function avancarOuAbrir(string $pedidoUuid): void
    {
        TravaDoPedido::executar($pedidoUuid, function () use ($pedidoUuid) {
            $this->proximoPassoOuAbrir($pedidoUuid);
        });
    }
```

(d) Troque o método `avancarSemTrava` inteiro (de `    protected function avancarSemTrava(object $distribuicao, Order $pedido): void` até o `    }` que fecha o método, logo antes de `    protected function abrirSemTrava(`) por:

```php
    protected function avancarSemTrava(object $distribuicao, Order $pedido): void
    {
        if ($distribuicao->fase !== Distribuicao::FASE_OFERTAS || $this->encerrouPeloPedido($distribuicao, $pedido)) {
            return;
        }
        if (Distribuicao::emRodadas()) {
            $this->avancarEmRodadas($distribuicao, $pedido);

            return;
        }
        // o prazo vale antes da pendente: aos MINUTOS_ATE_ABRIR abre a todos, cancelando a oferta que ainda corre
        $despachada = Distribuicoes::data($distribuicao->despachada_em);
        if ($despachada && now() >= $despachada->addMinutes(Distribuicao::MINUTOS_ATE_ABRIR)) {
            $this->abrirSemTrava($distribuicao, $pedido, Distribuicao::PRAZO);

            return;
        }
        if (Distribuicoes::ofertaPendente((int) $distribuicao->id)) {
            return; // alguém ainda está decidindo
        }

        $excluidos = array_merge(Distribuicoes::motoboysQueResponderam((int) $distribuicao->id), Distribuicoes::motoboysComOfertaPendente());
        $fila      = $this->fila->para($pedido, Candidatos::elegiveis($pedido, $excluidos));
        if ($fila === []) {
            // vazia não apaga a última fila gravada (o painel a mostra)
            $jaOfereceu = Distribuicoes::ofertas((int) $distribuicao->id) !== [];
            $this->abrirSemTrava($distribuicao, $pedido, $jaOfereceu ? Distribuicao::FILA_ESGOTADA : Distribuicao::SEM_CANDIDATO);

            return;
        }
        Distribuicoes::gravarFila((int) $distribuicao->id, $fila);
        if (!$this->oferecerAoPrimeiro($distribuicao, $pedido, $fila, 1, 1, null)) {
            $this->abrirSemTrava($distribuicao, $pedido, Distribuicao::FILA_ESGOTADA);
        }
    }

    /**
     * Um passo do ciclo em rodadas, já sob a trava e com a distribuição em `ofertas`: com uma oferta pendente, espera;
     * senão oferece ao primeiro da rodada atual. Rodada vazia passa à seguinte (sair da rodada 1 da volta 1 abre a
     * lista). Depois da rodada 3: volta nova (rodada 1, todos de novo) se já passou SEGUNDOS_ENTRE_VOLTAS do início da
     * volta; senão agenda o próximo passo (AvancarDistribuicao) e para. No máximo uma volta nova por passo: uma volta
     * inteira sem ninguém para e espera a varredura. Nunca abre a todos.
     */
    protected function avancarEmRodadas(object $distribuicao, Order $pedido): void
    {
        $id = (int) $distribuicao->id;
        if (Distribuicoes::ofertaPendente($id)) {
            return; // alguém ainda está decidindo
        }
        $raioBase  = max(1, (int) $pedido->getAdhocDistance());
        $volta     = max(1, (int) ($distribuicao->volta ?? 1));
        $rodada    = max(1, min(Distribuicao::ULTIMA_RODADA, (int) ($distribuicao->rodada ?? 1)));
        $voltaNova = false;

        while (true) {
            if ($this->oferecerNaRodada($distribuicao, $pedido, $volta, $rodada, Distribuicao::raioDaRodada($raioBase, $rodada))) {
                return;
            }
            if ($rodada < Distribuicao::ULTIMA_RODADA) {
                if ($volta === 1 && $rodada === 1 && Distribuicoes::abrirLista($id)) {
                    Log::info('[entregas] distribuição: lista aberta', ['pedido' => $pedido->public_id, 'distribuicao' => $id]);
                }
                $rodada++;
                Distribuicoes::irParaRodada($id, $rodada);
                Log::info('[entregas] distribuição: rodada ' . $rodada . ' (raio ' . Distribuicao::raioDaRodada($raioBase, $rodada) . ' m)', ['pedido' => $pedido->public_id, 'distribuicao' => $id, 'volta' => $volta]);

                continue;
            }
            if ($voltaNova) {
                // a volta inteira sem ninguém: para (a varredura tenta de novo a cada minuto)
                Distribuicoes::tocar($id);
                Log::info('[entregas] distribuição: aguardando motoboy', ['pedido' => $pedido->public_id, 'distribuicao' => $id, 'volta' => $volta]);

                return;
            }
            $inicio = Distribuicoes::data($distribuicao->volta_iniciada_em ?? null) ?? Distribuicoes::data($distribuicao->despachada_em);
            $falta  = $inicio ? $inicio->getTimestamp() + Distribuicao::SEGUNDOS_ENTRE_VOLTAS - now()->getTimestamp() : 0;
            if ($falta > 0) {
                Distribuicoes::tocar($id);
                try {
                    AvancarDistribuicao::agendar((string) $pedido->uuid, $falta);
                } catch (\Throwable $e) {
                    // a fila fora do ar: a varredura avança (SEGUNDOS_PARADA depois do updated_at)
                    Log::warning('[entregas] distribuição: job da próxima volta não entrou na fila', ['distribuicao' => $id, 'erro' => get_class($e)]);
                }

                return;
            }
            $volta++;
            $rodada    = 1;
            $voltaNova = true;
            Distribuicoes::novaVolta($id, $volta);
            Log::info('[entregas] distribuição: volta ' . $volta, ['pedido' => $pedido->public_id, 'distribuicao' => $id]);
        }
    }

    /**
     * A oferta da rodada: os disponíveis até o raio, fora quem tem qualquer linha nesta volta e quem tem oferta pendente
     * de outro pedido, na ordem "termina tudo e depois vai". False se ninguém recebeu.
     */
    protected function oferecerNaRodada(object $distribuicao, Order $pedido, int $volta, int $rodada, int $raio): bool
    {
        $id        = (int) $distribuicao->id;
        $excluidos = array_merge(Distribuicoes::motoboysDaVolta($id, $volta), Distribuicoes::motoboysComOfertaPendente());
        $fila      = $this->fila->para($pedido, Candidatos::elegiveis($pedido, $excluidos, $raio), true);
        if ($fila === []) {
            return false; // vazia não apaga a última fila gravada (o painel a mostra)
        }
        Distribuicoes::gravarFila($id, $fila);

        return $this->oferecerAoPrimeiro($distribuicao, $pedido, $fila, $volta, $rodada, $raio);
    }

    /**
     * Oferece ao primeiro da fila que ainda existe e que não ganhou, nesse meio-tempo, a oferta de outro pedido (outra
     * trava): grava a oferta, agenda o AvancarOferta e manda o push. False se ninguém da fila serviu.
     */
    protected function oferecerAoPrimeiro(object $distribuicao, Order $pedido, array $fila, int $volta, int $rodada, ?int $raio): bool
    {
        $ocupados = Distribuicoes::motoboysComOfertaPendente();
        $primeiro = null;
        $motoboy  = null;
        foreach ($fila as $candidato) {
            if (in_array($candidato['motoboy_uuid'], $ocupados, true)) {
                continue;
            }
            $motoboy = Driver::where('uuid', $candidato['motoboy_uuid'])->first();
            if ($motoboy) {
                $primeiro = $candidato;
                break;
            }
        }
        if (!$primeiro) {
            return false;
        }
        $posicao = count(Distribuicoes::ofertas((int) $distribuicao->id)) + 1;
        $oferta  = Distribuicoes::criarOferta($distribuicao, $primeiro, $posicao, $volta, $rodada, $raio);
        try {
            AvancarOferta::agendar((int) $oferta->id);
        } catch (\Throwable $e) {
            // a fila fora do ar: a varredura vence a oferta (FOLGA_DA_VARREDURA_S depois do vence_em)
            Log::warning('[entregas] distribuição: job da oferta não entrou na fila', ['oferta' => $oferta->id, 'erro' => get_class($e)]);
        }
        try {
            $motoboy->notify(new OfertaDePedido($pedido, $primeiro['distancia_m'], Distribuicoes::data($oferta->vence_em), Distribuicao::segundosDaOferta()));
        } catch (\Throwable $e) {
            // o envio para a fila falhou (Redis); a oferta vence sozinha (o FCM roda no worker)
            Log::warning('[entregas] distribuição: push da oferta falhou', ['oferta' => $oferta->id, 'erro' => get_class($e)]);
        }
        Log::info('[entregas] distribuição: oferta enviada', ['pedido' => $pedido->public_id, 'oferta' => $oferta->id, 'motoboy' => $motoboy->public_id, 'posicao' => $posicao, 'tempo_s' => $primeiro['tempo_s'], 'encaixe' => $primeiro['encaixe'], 'volta' => $volta, 'rodada' => $rodada]);

        return true;
    }
```

- [ ] **Step 6: Rodar e ver passar**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-rodadas.php`
Expected: `FALHAS: 0`.

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-ciclo.php`
Expected: `FALHAS: 0` (o ciclo de hoje, inclusive "nenhum da fila existe: abre a todos (fila_esgotada)" e o job de 30 s, não mudou).

- [ ] **Step 7: Commit**

```bash
git add api/app/Jobs/Entregas/AvancarDistribuicao.php api/app/Jobs/Entregas/AvancarOferta.php api/app/Support/Entregas/Distribuicao/Distribuidor.php scripts/teste-php/distribuicao-rodadas.php
git commit -m "$(cat <<'EOF'
Distribuição em rodadas: ciclo R, 1,5R, 2R e voltas até aceitar, sem alarme a todos

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Recusar × dispensar, "Mostrar a todos agora" e o aceite pela lista (`Distribuidor`)

**Files:**
- Modify: `api/app/Support/Entregas/Distribuicao/Distribuidor.php`
- Test: `scripts/teste-php/distribuicao-rodadas.php`

- [ ] **Step 1: Escrever os testes que falham**

Em `scripts/teste-php/distribuicao-rodadas.php`, insira **antes** da última linha (`resumo();`):

```php
echo '== Recusar × dispensar (Distribuidor)' . PHP_EOL;
$dist = cenarioRodadas([$ana, $bruno, $caio, $davi]);
$dist->iniciar(pedidoDoCenario());                                                // Ana
confere($dist->recusarOuDispensar('order-1', $bruno['motoboy']) === null && count(ofertas()) === 1, 'lista fechada e sem oferta dele: null (a rota responde 409)');
Relogio::$agora = '2026-10-07 10:00:10';
confere($dist->recusarOuDispensar('order-1', $ana['motoboy']) === 'recusada' && ofertas()[0]->resposta === 'recusada' && ofertas()[1]->motoboy_uuid === 'd-bruno', 'com a oferta dele: recusada, e passa ao próximo na hora');
Relogio::$agora = '2026-10-07 10:00:20';
$dist->recusarOuDispensar('order-1', $bruno['motoboy']);                         // rodada 2: Caio; lista aberta
Relogio::$agora = '2026-10-07 10:00:25';
confere($dist->recusarOuDispensar('order-1', $davi['motoboy']) === 'dispensada', 'lista aberta, sem oferta dele: dispensada');
$linha = ofertas()[3];
confere($linha->motoboy_uuid === 'd-davi' && $linha->resposta === 'dispensada' && $linha->volta === 1 && $linha->rodada === 2 && $linha->raio_m === 9000 && ofertas()[2]->resposta === 'pendente', 'linha dispensada na volta e na rodada atuais; a oferta de Caio continua');
confere($dist->recusarOuDispensar('order-1', $davi['motoboy']) === 'dispensada' && count(ofertas()) === 4, 'dispensar de novo na mesma volta: sem linha nova');
confere(logou('oferta dispensada', 'info'), 'log: oferta dispensada');
Relogio::$agora = '2026-10-07 10:00:40';
(new AvancarOferta(3))->handle($dist);                                            // Caio vence; Davi dispensou
$job = end(Fila::$jobs);
confere(count(ofertas()) === 4 && distribuicao()->rodada === 3 && $job instanceof AvancarDistribuicao && $job->delay === 20, 'quem dispensou não recebe oferta nesta volta: rodada 3 vazia, próximo passo quando completar 1 min');
Relogio::$agora = '2026-10-07 10:01:00';
$job->handle($dist);
confere(ofertas()[4]->motoboy_uuid === 'd-ana' && ofertas()[4]->volta === 2, 'volta 2: todos de novo, inclusive quem recusou');
Config::$valores['services.entregas.distribuicao_rodadas'] = '';
confere($dist->recusarOuDispensar('order-1', $davi['motoboy']) === null, 'rodadas desligadas: sem dispensa');

$dist = cenarioRodadas([$ana, $bruno]);
$dist->iniciar(pedidoDoCenario());
Distribuicoes::abrirLista(1);
pedidoDoCenario()->driver_assigned_uuid = 'd-ana';
confere($dist->recusarOuDispensar('order-1', $bruno['motoboy']) === null, 'pedido já com motoboy: null (409)');

echo '== Mostrar a todos agora e aceite pela lista (Distribuidor)' . PHP_EOL;
$dist = cenarioRodadas([$ana, $bruno]);
$dist->iniciar(pedidoDoCenario());
confere($dist->mostrarATodos('order-1') === 'aberta' && distribuicao()->lista_aberta_em === '2026-10-07 10:00:00' && distribuicao()->fase === 'ofertas' && ofertas()[0]->resposta === 'pendente' && count(avisos()) === 1, 'abre a lista na hora; a oferta de Ana continua, sem alarme geral');
confere($dist->mostrarATodos('order-1') === 'ja_aberta', 'já aberta: ja_aberta');
$dist->registrarAceitePelaLista(distribuicao(), 'd-bruno');
confere(ofertas()[0]->resposta === 'cancelada' && ofertas()[1]->resposta === 'aceita_pela_lista' && ofertas()[1]->motoboy_uuid === 'd-bruno' && distribuicao()->fase === 'encerrada' && distribuicao()->motivo === 'aceita' && logou('aceita pela lista', 'info'), 'aceite pela lista: a pendente de Ana vira cancelada, Bruno ganha a linha aceita_pela_lista, encerrada (aceita)');
confere($dist->mostrarATodos('order-1') === 'fora_de_ofertas', 'encerrada: fora_de_ofertas');
$dist = cenarioRodadas([$ana]);
$dist->iniciar(pedidoDoCenario());
pedidoDoCenario()->driver_assigned_uuid = 'd-x';
confere($dist->mostrarATodos('order-1') === 'fora_de_ofertas' && distribuicao()->motivo === 'atribuida', 'pedido já com motoboy: encerra (atribuida)');
confere(!isset(Trava::$ocupadas['entregas:pedido:order-1']), 'a trava é solta');

```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-rodadas.php`
Expected: erro fatal `Call to undefined method ...Distribuidor::recusarOuDispensar()`.

- [ ] **Step 3: Implementar**

Em `api/app/Support/Entregas/Distribuicao/Distribuidor.php`, troque o método `recusar` inteiro

```php
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
            $this->proximoPassoOuAbrir($pedidoUuid);

            return true; // a recusa foi gravada, mesmo que o passo seguinte tenha falhado
        });
    }
```

por

```php
    /** O motoboy recusou. False se ele não tem oferta pendente neste pedido. */
    public function recusar(string $pedidoUuid, Driver $motoboy): bool
    {
        return TravaDoPedido::executar($pedidoUuid, fn () => $this->recusarSemTrava($pedidoUuid, $motoboy));
    }

    /**
     * Recusar e Dispensar (POST v1/entregas/motoboy/pedidos/{id}/recusar): com a oferta pendente dele, recusa (RECUSADA);
     * senão, em rodadas e com a lista aberta, grava a linha `dispensada` na volta atual (DISPENSADA: some da lista dele e
     * não recebe oferta até a volta seguinte; uma linha por volta). Null: nada com ele (a rota responde 409).
     */
    public function recusarOuDispensar(string $pedidoUuid, Driver $motoboy): ?string
    {
        return TravaDoPedido::executar($pedidoUuid, function () use ($pedidoUuid, $motoboy) {
            if ($this->recusarSemTrava($pedidoUuid, $motoboy)) {
                return Distribuicao::RECUSADA;
            }

            return $this->dispensarSemTrava($pedidoUuid, $motoboy) ? Distribuicao::DISPENSADA : null;
        });
    }

    /**
     * "Mostrar a todos agora" (console, em rodadas): abre a lista na hora, sem alarme geral; o ciclo segue.
     * LISTA_ABERTA_AGORA, LISTA_JA_ABERTA ou FORA_DE_OFERTAS (fora de ofertas, pedido com motoboy, encerrado ou apagado).
     */
    public function mostrarATodos(string $pedidoUuid): string
    {
        return TravaDoPedido::executar($pedidoUuid, function () use ($pedidoUuid) {
            $distribuicao = Distribuicoes::doPedido($pedidoUuid);
            $pedido       = $distribuicao ? Order::where('uuid', $pedidoUuid)->first() : null;
            if ($distribuicao && !$pedido) {
                $this->encerrarSemTrava($distribuicao, Distribuicao::CANCELADA);

                return Distribuicao::FORA_DE_OFERTAS;
            }
            if (!$distribuicao || $distribuicao->fase !== Distribuicao::FASE_OFERTAS || $this->encerrouPeloPedido($distribuicao, $pedido)) {
                return Distribuicao::FORA_DE_OFERTAS;
            }
            if (!Distribuicoes::abrirLista((int) $distribuicao->id)) {
                return Distribuicao::LISTA_JA_ABERTA;
            }
            Log::info('[entregas] distribuição: lista aberta', ['pedido' => $pedido->public_id, 'distribuicao' => $distribuicao->id, 'pela_central' => true]);

            return Distribuicao::LISTA_ABERTA_AGORA;
        });
    }

    /** Sem a trava (o middleware do aceite já a segura): aceite pela lista aberta de quem não tinha a oferta. */
    public function registrarAceitePelaLista(object $distribuicao, ?string $motoboyUuid): void
    {
        $id = (int) $distribuicao->id;
        Distribuicoes::cancelarPendentes($id);
        if ($motoboyUuid) {
            Distribuicoes::registrarResposta($distribuicao, $motoboyUuid, Distribuicao::ACEITA_PELA_LISTA);
        }
        Distribuicoes::mudarFase($id, Distribuicao::FASE_ENCERRADA, Distribuicao::ACEITA);
        Log::info('[entregas] distribuição: aceita pela lista', ['distribuicao' => $id] + ($motoboyUuid ? $this->motoboyNoLog($motoboyUuid) : []));
    }

    /** Só de dentro da trava: a recusa da oferta pendente dele e o próximo passo. False se ele não tem oferta pendente. */
    protected function recusarSemTrava(string $pedidoUuid, Driver $motoboy): bool
    {
        $distribuicao = Distribuicoes::doPedido($pedidoUuid);
        $oferta       = $distribuicao ? Distribuicoes::ofertaPendente((int) $distribuicao->id) : null;
        if (!$oferta || (string) $oferta->motoboy_uuid !== (string) $motoboy->uuid) {
            return false;
        }
        Distribuicoes::responder((int) $oferta->id, Distribuicao::RECUSADA);
        Log::info('[entregas] distribuição: oferta recusada', ['oferta' => $oferta->id, 'motoboy' => $motoboy->public_id]);
        $this->proximoPassoOuAbrir($pedidoUuid);

        return true; // a recusa foi gravada, mesmo que o passo seguinte tenha falhado
    }

    /**
     * Só de dentro da trava: em rodadas, com a distribuição em ofertas, a lista aberta e o pedido ainda sem motoboy, grava
     * a linha `dispensada` na volta (se ainda não recusou nem dispensou nela). False se não cabe dispensa.
     */
    protected function dispensarSemTrava(string $pedidoUuid, Driver $motoboy): bool
    {
        if (!Distribuicao::emRodadas()) {
            return false;
        }
        $distribuicao = Distribuicoes::doPedido($pedidoUuid);
        if (!$distribuicao || $distribuicao->fase !== Distribuicao::FASE_OFERTAS || !$distribuicao->lista_aberta_em) {
            return false;
        }
        $pedido = Order::where('uuid', $pedidoUuid)->first();
        if (!$pedido || $this->motivoParaEncerrar($pedido)) {
            return false;
        }
        $volta = (int) ($distribuicao->volta ?? 1);
        if (!in_array((string) $motoboy->uuid, Distribuicoes::motoboysQueDispensaramNaVolta((int) $distribuicao->id, $volta), true)) {
            $raio = Distribuicao::raioDaRodada(max(1, (int) $pedido->getAdhocDistance()), (int) ($distribuicao->rodada ?? 1));
            Distribuicoes::registrarResposta($distribuicao, (string) $motoboy->uuid, Distribuicao::DISPENSADA, $raio);
            Log::info('[entregas] distribuição: oferta dispensada', ['pedido' => $pedido->public_id, 'distribuicao' => $distribuicao->id, 'motoboy' => $motoboy->public_id, 'volta' => $volta]);
        }

        return true;
    }
```

- [ ] **Step 4: Rodar e ver passar**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-rodadas.php`
Expected: `FALHAS: 0`.

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-ciclo.php`
Expected: `FALHAS: 0` (`recusar()` continua com o mesmo contrato).

- [ ] **Step 5: Commit**

```bash
git add api/app/Support/Entregas/Distribuicao/Distribuidor.php scripts/teste-php/distribuicao-rodadas.php
git commit -m "$(cat <<'EOF'
Distribuição em rodadas: recusar × dispensar, Mostrar a todos agora e aceite pela lista

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Varredura em rodadas (sem prazo; avança as paradas)

**Files:**
- Modify: `api/app/Console/Commands/Entregas/VarrerDistribuicoes.php`
- Test: `scripts/teste-php/distribuicao-rodadas.php`

- [ ] **Step 1: Escrever o teste que falha**

Em `scripts/teste-php/distribuicao-rodadas.php`, insira **antes** da última linha (`resumo();`):

```php
echo '== Varredura em rodadas' . PHP_EOL;
$dist = cenarioRodadas([$ana]);
$dist->iniciar(pedidoDoCenario());
Relogio::$agora = '2026-10-07 10:03:05';
(new VarrerDistribuicoes())->handle($dist);
confere(distribuicao()->fase === 'ofertas' && distribuicao()->motivo === null && alarmesGerais() === [], 'passados 3 min do despacho: não abre a todos (sem prazo em rodadas)');
confere(ofertas()[0]->resposta === 'vencida' && ofertas()[1]->motoboy_uuid === 'd-ana' && ofertas()[1]->volta === 2, 'a pendente vencida há mais de 20 s vence e o ciclo segue (volta 2)');

$dist = cenarioRodadas([]);
$dist->iniciar(pedidoDoCenario());                       // rodada 3; o job de 60 s "se perdeu"
Relogio::$agora = '2026-10-07 10:00:50';
(new VarrerDistribuicoes())->handle($dist);
confere(distribuicao()->volta === 1, 'parada há menos de 1 min: espera');
Relogio::$agora = '2026-10-07 10:01:05';
(new VarrerDistribuicoes())->handle($dist);
confere(distribuicao()->volta === 2 && distribuicao()->updated_at === '2026-10-07 10:01:05' && logou('aguardando motoboy', 'info'), 'parada há mais de 1 min e sem pendente: avança (volta 2) e toca o updated_at');
definirMotoboys([$ana]);
Relogio::$agora = '2026-10-07 10:02:10';
(new VarrerDistribuicoes())->handle($dist);
confere(count(ofertas()) === 1 && ofertas()[0]->motoboy_uuid === 'd-ana' && ofertas()[0]->volta === 2, 'quem fica disponível recebe no passo seguinte da varredura');

$dist = cenarioRodadas([]);
$dist->iniciar(pedidoDoCenario());
definirMotoboys([$ana]);
Relogio::$agora = '2026-10-09 10:00:00';
(new VarrerDistribuicoes())->handle($dist);
confere(ofertas() === [] && distribuicao()->volta === 1, 'despachada há mais de 24 h: a varredura não avança');

$dist = cenarioRodadas([$ana]);
$dist->iniciar(pedidoDoCenario());
Config::$valores['services.entregas.distribuicao_rodadas'] = '';
Relogio::$agora = '2026-10-07 10:03:05';
(new VarrerDistribuicoes())->handle($dist);
confere(distribuicao()->fase === 'aberta' && distribuicao()->motivo === 'prazo', 'rodadas desligadas no meio: volta o ciclo de hoje (o prazo de 3 min abre a todos)');

```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-rodadas.php`
Expected: `FALHA passados 3 min do despacho: não abre a todos` (a varredura de hoje abre pelo prazo) e `FALHA parada há mais de 1 min...`.

- [ ] **Step 3: Implementar**

Em `api/app/Console/Commands/Entregas/VarrerDistribuicoes.php`, troque o docblock da classe

```php
 * - distribuição em ofertas há mais de MINUTOS_ATE_ABRIR: abre a todos (prazo), mesmo com oferta pendente (o abrirATodos a cancela).
 * O encerramento só olha as distribuições despachadas nas últimas 24 h (as mais antigas, o observador do Order encerra).
 * Vencer e abrir pelo prazo não têm esse limite.
```

por

```php
 * - distribuição em ofertas há mais de MINUTOS_ATE_ABRIR: abre a todos (prazo), mesmo com oferta pendente (o abrirATodos a cancela).
 * Em rodadas (Distribuicao::emRodadas) não há prazo: no lugar dele, avança as distribuições em ofertas sem oferta pendente
 * paradas há mais de SEGUNDOS_PARADA (volta que esperava o intervalo, ninguém disponível, job perdido); cada tentativa sem
 * candidato toca o updated_at.
 * O encerramento e o avanço em rodadas só olham as distribuições despachadas nas últimas 24 h (as mais antigas, o
 * observador do Order encerra). Vencer e abrir pelo prazo não têm esse limite.
```

e troque

```php
        foreach (Distribuicoes::emOfertasHaMais(Distribuicao::MINUTOS_ATE_ABRIR) as $distribuicao) {
            $this->tentar('abrir a distribuição', ['distribuicao' => $distribuicao->id], fn () => $distribuidor->abrirATodos((string) $distribuicao->pedido_uuid, Distribuicao::PRAZO));
        }

        return self::SUCCESS;
```

por

```php
        if (Distribuicao::emRodadas()) {
            foreach (Distribuicoes::emOfertasParadasHa(Distribuicao::SEGUNDOS_PARADA) as $distribuicao) {
                $this->tentar('avançar a distribuição', ['distribuicao' => $distribuicao->id], fn () => $distribuidor->avancarOuAbrir((string) $distribuicao->pedido_uuid));
            }

            return self::SUCCESS;
        }

        foreach (Distribuicoes::emOfertasHaMais(Distribuicao::MINUTOS_ATE_ABRIR) as $distribuicao) {
            $this->tentar('abrir a distribuição', ['distribuicao' => $distribuicao->id], fn () => $distribuidor->abrirATodos((string) $distribuicao->pedido_uuid, Distribuicao::PRAZO));
        }

        return self::SUCCESS;
```

- [ ] **Step 4: Rodar e ver passar**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-rodadas.php`
Expected: `FALHAS: 0`.

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-ciclo.php`
Expected: `FALHAS: 0`.

- [ ] **Step 5: Commit**

```bash
git add api/app/Console/Commands/Entregas/VarrerDistribuicoes.php scripts/teste-php/distribuicao-rodadas.php
git commit -m "$(cat <<'EOF'
Distribuição em rodadas: varredura sem prazo, avança as distribuições paradas

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: O aceite com a lista aberta (`BarrarAceiteDePedidoEncerrado`)

**Files:**
- Modify: `api/app/Http/Middleware/BarrarAceiteDePedidoEncerrado.php`
- Test: `scripts/teste-php/barrar-aceite.php`

- [ ] **Step 1: Escrever os testes que falham**

Em `scripts/teste-php/barrar-aceite.php`, insira **antes** da última linha (`resumo();`):

```php
echo '== Distribuição em rodadas: lista aberta e quem chega depois' . PHP_EOL;

function comRodadas(array $ofertas, bool $listaAberta): void
{
    comDistribuicao($ofertas);
    Config::$valores['services.entregas.distribuicao_rodadas'] = '1';
    if ($listaAberta) {
        Distribuicoes::abrirLista(1);
    }
}

comRodadas([['d-a', 'pendente']], false);
session(['user' => 'u-b']);
confere(aceitar('13|token-do-motoboy-b', ['assign' => 'driver_b'])->status === 409, 'lista fechada (rodada 1 da volta 1): só quem tem a oferta, como hoje');

comRodadas([['d-a', 'pendente']], true);
session(['user' => 'u-b']);
confere(aceitar('13|token-do-motoboy-b', ['assign' => 'driver_b']) === 'passou', 'lista aberta: B aceita com a oferta de A pendente');
$linhas = DB::table('entregas_ofertas')->orderBy('id')->get()->all();
confere(count($linhas) === 2 && $linhas[0]->resposta === 'cancelada' && $linhas[1]->resposta === 'aceita_pela_lista' && $linhas[1]->motoboy_uuid === 'd-b', 'a oferta de A vira cancelada; B ganha a linha aceita_pela_lista');
confere(DB::table('entregas_distribuicoes')->where('id', 1)->value('fase') === 'encerrada' && DB::table('entregas_distribuicoes')->where('id', 1)->value('motivo') === 'aceita', 'distribuição encerrada (aceita)');

comRodadas([['d-a', 'pendente']], true);
confere(aceitar(TOKEN_A, ['assign' => 'driver_a']) === 'passou' && DB::table('entregas_ofertas')->where('id', 1)->value('resposta') === 'aceita' && DB::table('entregas_ofertas')->count() === 1, 'lista aberta, quem tem a oferta aceita: aceita na própria oferta, sem linha nova');

comRodadas([['d-a', 'pendente']], true);
session(['user' => 'u-b']);
$falha = response()->json(['error' => 'Order has already started.'], 400);
confere(aceitar('13|token-do-motoboy-b', ['assign' => 'driver_b'], API . 'startOrder', $falha) === $falha && DB::table('entregas_ofertas')->where('id', 1)->value('resposta') === 'pendente' && DB::table('entregas_ofertas')->count() === 1, 'o Fleet-Ops recusou o aceite: nada gravado');

comRodadas([['d-a', 'pendente']], true);
Config::$valores['services.entregas.distribuicao_rodadas'] = '';
session(['user' => 'u-b']);
confere(aceitar('13|token-do-motoboy-b', ['assign' => 'driver_b'])->status === 409, 'rodadas desligadas: o lista_aberta_em não vale (só quem tem a oferta)');

cenario(['adhoc' => true, 'driver_assigned_uuid' => 'd-b', 'started' => true]);
Config::$valores['services.entregas.distribuicao']         = '1';
Config::$valores['services.entregas.distribuicao_rodadas'] = '1';
$resposta = aceitar(TOKEN_A, ['assign' => 'driver_a']);
confere($resposta->status === 409 && $resposta->dados === ['error' => 'Este pedido passou para outro motoboy.', 'errors' => ['Este pedido passou para outro motoboy.']], 'em rodadas: pedido aberto já aceito por B, A chega depois: 409');
session(['user' => 'u-b']);
confere(aceitar('13|token-do-motoboy-b', ['assign' => 'driver_b']) === 'passou', 'o próprio B: passa (o Fleet-Ops responde)');
Config::$valores['services.entregas.distribuicao_rodadas'] = '';
session(['user' => 'u-a']);
confere(aceitar(TOKEN_A, ['assign' => 'driver_a']) === 'passou', 'rodadas desligadas: como hoje (o Fleet-Ops responde "already started")');

```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/barrar-aceite.php`
Expected: `FALHA lista aberta: B aceita com a oferta de A pendente` (hoje leva 409) e `FALHA em rodadas: pedido aberto já aceito por B...`.

- [ ] **Step 3: Implementar**

Em `api/app/Http/Middleware/BarrarAceiteDePedidoEncerrado.php`:

(a) No docblock da classe, troque

```php
 * aceita (`Distribuidor::registrarAceite`, sem trava própria: já roda dentro desta). Na fase `aberta` o aceite é livre.
```

por

```php
 * aceita (`Distribuidor::registrarAceite`, sem trava própria: já roda dentro desta). Na fase `aberta` o aceite é livre.
 * Em rodadas (ENTREGAS_DISTRIBUICAO_RODADAS), com a lista aberta (`lista_aberta_em`) qualquer motoboy aceita: quem tem
 * a oferta grava `aceita` nela; os outros, a linha `aceita_pela_lista` (`Distribuidor::registrarAceitePelaLista`, que
 * cancela a pendente). E quem tenta aceitar um pedido aberto que outro já iniciou leva 409 "Este pedido passou para
 * outro motoboy." (sem rodadas, segue o "Order has already started." do Fleet-Ops).
```

(b) Em `aceitarComATrava`, troque

```php
        // distribuição de pedidos abertos: na fase ofertas só quem tem a oferta aceita (Distribuicoes::ofertaParaAceite)
        if ($atual && Distribuicao::ligada() && Distribuicoes::emOfertas((string) $atual->uuid)) {
            $quem   = $this->quemAceita($request);
```

por

```php
        if ($atual && $this->aceitoPorOutroNaDistribuicao($request, $atual)) {
            Log::info('[entregas] aceite do motoboy barrado: pedido passou para outro motoboy', [
                'pedido'  => $pedido->public_id,
                'motoboy' => $request->input('assign'),
                'ip'      => $request->ip(),
            ]);

            return $this->recusar('Este pedido passou para outro motoboy.', 409);
        }

        // distribuição de pedidos abertos: na fase ofertas só quem tem a oferta aceita (Distribuicoes::ofertaParaAceite)
        if ($atual && Distribuicao::ligada() && Distribuicoes::emOfertas((string) $atual->uuid)) {
            $distribuicao = Distribuicoes::doPedido((string) $atual->uuid);
            if (Distribuicao::emRodadas() && $distribuicao && $distribuicao->lista_aberta_em) {
                return $this->aceitarPelaLista($request, $next, $pedido, $distribuicao);
            }
            $quem   = $this->quemAceita($request);
```

(c) Insira **antes** do método `    /** O uuid de quem está aceitando: o motoboy da sessão ou, sem ele, o `assign` (public_id). */`:

```php
    /**
     * Lista aberta (distribuição em rodadas): qualquer motoboy aceita (o primeiro leva; a trava garante um só). Com o 2xx
     * do startOrder, quem tem a oferta (a pendente, ou a vencida enquanto ninguém foi oferecido depois) grava `aceita`
     * nela; os outros, `aceita_pela_lista`. Uma falha ao registrar nunca derruba o aceite (a varredura encerra).
     */
    protected function aceitarPelaLista(Request $request, Closure $next, Order $pedido, object $distribuicao)
    {
        $quem     = $this->quemAceita($request);
        $oferta   = $quem ? Distribuicoes::ofertaParaAceite((string) $pedido->uuid, $quem) : null;
        $resposta = $next($request);
        if ($this->deuCerto($resposta)) {
            try {
                $oferta
                    ? app(Distribuidor::class)->registrarAceite($oferta)
                    : app(Distribuidor::class)->registrarAceitePelaLista($distribuicao, $quem);
            } catch (\Throwable $e) {
                Log::warning('[entregas] distribuição: falha ao registrar o aceite', [
                    'pedido' => $pedido->public_id,
                    'erro'   => get_class($e),
                ]);
            }
        }

        return $resposta;
    }

    /**
     * Em rodadas: o pedido aberto já foi aceito (iniciado, com motoboy) e quem tenta aceitar é outro (motoboy da sessão
     * ou `assign`). Sem rodadas: false (o Fleet-Ops responde "Order has already started.").
     */
    protected function aceitoPorOutroNaDistribuicao(Request $request, $pedido): bool
    {
        if (!Distribuicao::emRodadas() || !$pedido->adhoc || !$pedido->driver_assigned_uuid || !$pedido->started) {
            return false;
        }
        $quem = $this->quemAceita($request);

        return $quem !== null && $quem !== (string) $pedido->driver_assigned_uuid;
    }

```

- [ ] **Step 4: Rodar e ver passar**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/barrar-aceite.php`
Expected: `FALHAS: 0` (os casos de hoje continuam passando).

- [ ] **Step 5: Commit**

```bash
git add api/app/Http/Middleware/BarrarAceiteDePedidoEncerrado.php scripts/teste-php/barrar-aceite.php
git commit -m "$(cat <<'EOF'
Distribuição em rodadas: aceite livre com a lista aberta e 409 para quem chega depois

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: Rotas: recusar/dispensar (app) e o painel com "Mostrar a todos agora" (console)

**Files:**
- Modify: `api/app/Http/Controllers/Entregas/MotoboyController.php` (`recusar`)
- Modify: `api/app/Http/Controllers/Entregas/DistribuicaoController.php`
- Test: `scripts/teste-php/distribuicao-rotas.php`

- [ ] **Step 1: Escrever os testes que falham**

Em `scripts/teste-php/distribuicao-rotas.php`, insira **antes** da última linha (`resumo();`):

```php
echo '== Rodadas: recusar × dispensar' . PHP_EOL;
function cenarioRodadas(): Distribuidor
{
    $dist = cenario();
    Config::$valores['services.entregas.distribuicao_rodadas'] = '1';

    return $dist;
}

$dist     = cenarioRodadas();
$resposta = (new MotoboyController())->recusar(requisicao('12|token-do-motoboy-a'), 'order_1', $dist);
confere($resposta->status === 200 && $resposta->dados === ['resultado' => 'recusada'], 'A recusa a oferta dele: 200 recusada');
$d = Distribuicoes::doPedido('order-1');
confere($d->fase === 'ofertas' && $d->lista_aberta_em !== null && $d->rodada === 3, 'sem outro candidato até 2R: segue em ofertas (não abre a todos), lista aberta, esperando a volta seguinte');

$dist = cenarioRodadas();
session(['user' => 'u-b']);
$resposta = (new MotoboyController())->recusar(requisicao('13|token-do-motoboy-b'), 'order_1', $dist);
confere($resposta->status === 409 && $resposta->dados === ['errors' => ['Esta oferta não está mais com você.']], 'lista fechada e sem oferta dele: 409');
Distribuicoes::abrirLista(1);
$resposta = (new MotoboyController())->recusar(requisicao('13|token-do-motoboy-b'), 'order_1', $dist);
confere($resposta->status === 200 && $resposta->dados === ['resultado' => 'dispensada'], 'lista aberta, sem oferta dele: 200 dispensada');
$linhas = Distribuicoes::ofertas(1);
confere(count($linhas) === 2 && $linhas[1]->resposta === 'dispensada' && $linhas[1]->motoboy_uuid === 'd-b' && $linhas[1]->volta === 1 && $linhas[0]->resposta === 'pendente', 'linha dispensada na volta; a oferta de A segue pendente');
confere((new MotoboyController())->recusar(requisicao('13|token-do-motoboy-b'), 'order_1', $dist)->dados === ['resultado' => 'dispensada'] && count(Distribuicoes::ofertas(1)) === 2, 'dispensar de novo na mesma volta: 200 sem linha nova');

$dist = cenarioRodadas();
Distribuicoes::abrirLista(1);
Order::$todos[0]->driver_assigned_uuid = 'd-a';
session(['user' => 'u-b']);
confere((new MotoboyController())->recusar(requisicao('13|token-do-motoboy-b'), 'order_1', $dist)->status === 409, 'pedido com motoboy: 409');

$dist = cenario(); // rodadas desligadas
Distribuicoes::abrirLista(1);
session(['user' => 'u-b']);
confere((new MotoboyController())->recusar(requisicao('13|token-do-motoboy-b'), 'order_1', $dist)->status === 409, 'rodadas desligadas: sem dispensa (409, como hoje)');

$dist = cenarioRodadas();
\Teste\Trava::$ocupadas['entregas:pedido:order-1'] = true;
confere((new MotoboyController())->recusar(requisicao('12|token-do-motoboy-a'), 'order_1', $dist)->status === 503, 'trava ocupada: 503');
unset(\Teste\Trava::$ocupadas['entregas:pedido:order-1']);

echo '== Rodadas: painel e "Mostrar a todos agora"' . PHP_EOL;
$dist = cenarioRodadas();
\Fleetbase\Support\Auth::$usuario = $admin;
$dados = (new DistribuicaoController())->painel(requisicao('sessao'), 'order_1')->dados;
confere($dados['rodadas'] === true && $dados['volta'] === 1 && $dados['rodada'] === 1 && $dados['raio_m'] === 6000 && $dados['lista_aberta_em'] === null, 'painel: rodadas, volta, rodada, raio da rodada e lista fechada (' . json_encode($dados) . ')');
confere($dados['historico'][0]['volta'] === 1 && $dados['historico'][0]['rodada'] === 1 && $dados['historico'][0]['raio_m'] === null, 'histórico com volta, rodada e raio');
$resposta = (new DistribuicaoController())->abrir(requisicao('sessao'), 'order_1', $dist);
confere($resposta->status === 200 && $resposta->dados['fase'] === 'ofertas' && $resposta->dados['lista_aberta_em'] === Distribuicoes::data('2026-10-07 10:00:00')->toIso8601String() && $resposta->dados['oferta']['motoboy'] === 'Ana', 'Mostrar a todos agora: lista aberta, segue em ofertas com a oferta de A (' . json_encode($resposta->dados) . ')');
confere(Driver::$avisos === [], 'sem alarme geral');
$resposta = (new DistribuicaoController())->abrir(requisicao('sessao'), 'order_1', $dist);
confere($resposta->status === 409 && $resposta->dados === ['errors' => ['A lista deste pedido já está aberta a todos.']], 'já aberta: 409');
$dist->registrarAceite(Distribuicoes::oferta(1));
$resposta = (new DistribuicaoController())->abrir(requisicao('sessao'), 'order_1', $dist);
confere($resposta->status === 409 && $resposta->dados === ['errors' => ['Este pedido não está em oferta.']], 'encerrada: 409');

$dist = cenarioRodadas();
\Fleetbase\Support\Auth::$usuario = $admin;
\Teste\Trava::$ocupadas['entregas:pedido:order-1'] = true;
confere((new DistribuicaoController())->abrir(requisicao('sessao'), 'order_1', $dist)->status === 503, 'Mostrar com a trava ocupada: 503');
unset(\Teste\Trava::$ocupadas['entregas:pedido:order-1']);
Config::$valores['services.entregas.distribuicao_rodadas'] = '';
confere((new DistribuicaoController())->painel(requisicao('sessao'), 'order_1')->dados['rodadas'] === false, 'painel: rodadas = false com a chave desligada');

```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-rotas.php`
Expected: `FALHA lista aberta, sem oferta dele: 200 dispensada` (sai 409), `FALHA painel: rodadas, volta...` (chaves ausentes) e `FALHA Mostrar a todos agora...` (hoje abre a todos).

- [ ] **Step 3: `MotoboyController::recusar`**

Em `api/app/Http/Controllers/Entregas/MotoboyController.php`, troque

```php
        try {
            $recusou = $distribuidor->recusar((string) $pedido->uuid, $motoboy);
        } catch (LockTimeoutException $e) {
            return response()->json(['errors' => ['Este pedido está sendo atualizado. Tente de novo.']], 503);
        }

        return $recusou
            ? response()->json(['resultado' => 'recusada'])
            : response()->json(['errors' => ['Esta oferta não está mais com você.']], 409);
```

por

```php
        try {
            // 'recusada' (a oferta dele) ou, em rodadas com a lista aberta, 'dispensada'; null = nada com ele
            $resultado = $distribuidor->recusarOuDispensar((string) $pedido->uuid, $motoboy);
        } catch (LockTimeoutException $e) {
            return response()->json(['errors' => ['Este pedido está sendo atualizado. Tente de novo.']], 503);
        }

        return $resultado
            ? response()->json(['resultado' => $resultado])
            : response()->json(['errors' => ['Esta oferta não está mais com você.']], 409);
```

- [ ] **Step 4: `DistribuicaoController`**

Em `api/app/Http/Controllers/Entregas/DistribuicaoController.php`:

(a) Troque o docblock da classe

```php
/**
 * Entregas RestaurantePro: painel "Distribuição" no detalhe do pedido do console (só administradores) e o botão
 * "Abrir a todos agora". GET int/v1/entregas/pedidos/{id}/distribuicao e POST .../distribuicao/abrir.
 */
```

por

```php
/**
 * Entregas RestaurantePro: painel "Distribuição" no detalhe do pedido do console (só administradores) e o botão
 * "Abrir a todos agora" ("Mostrar a todos agora" em rodadas: só abre a lista, sem alarme geral; o ciclo segue).
 * GET int/v1/entregas/pedidos/{id}/distribuicao e POST .../distribuicao/abrir.
 */
```

(b) No `painel`, troque

```php
        return response()->json(static::resposta((string) $pedido->uuid));
    }

    public function abrir(Request $request, string $id, Distribuidor $distribuidor)
```

por

```php
        return response()->json(static::resposta((string) $pedido->uuid, $pedido));
    }

    public function abrir(Request $request, string $id, Distribuidor $distribuidor)
```

(c) No `abrir`, troque

```php
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
```

por

```php
        try {
            $resultado = Distribuicao::emRodadas()
                ? $distribuidor->mostrarATodos((string) $pedido->uuid)
                : ($distribuidor->abrirATodos((string) $pedido->uuid, Distribuicao::ABERTA_PELA_CENTRAL) ? Distribuicao::LISTA_ABERTA_AGORA : Distribuicao::FORA_DE_OFERTAS);
        } catch (LockTimeoutException $e) {
            return response()->json(['errors' => ['O pedido está sendo atualizado. Tente de novo.']], 503);
        }
        if ($resultado === Distribuicao::LISTA_JA_ABERTA) {
            return response()->json(['errors' => ['A lista deste pedido já está aberta a todos.']], 409);
        }
        if ($resultado !== Distribuicao::LISTA_ABERTA_AGORA) {
            return response()->json(['errors' => ['Este pedido não está em oferta.']], 409);
        }

        return response()->json(static::resposta((string) $pedido->uuid, $pedido));
    }
```

(d) Troque o início do `resposta` e o `$linha`

```php
    /** O painel: a distribuição não encerrada do pedido ou, sem ela, a última (para o histórico); `distribuicao: false` se nunca houve. */
    public static function resposta(string $pedidoUuid): array
    {
```

por

```php
    /**
     * O painel: a distribuição não encerrada do pedido ou, sem ela, a última (para o histórico); `distribuicao: false` se
     * nunca houve. Com o pedido, `raio_m` = o raio da rodada atual (R pelo getAdhocDistance).
     */
    public static function resposta(string $pedidoUuid, ?Order $pedido = null): array
    {
```

e troque

```php
            'tempo_estimado_s' => $o->tempo_estimado_s !== null ? (int) $o->tempo_estimado_s : null,
```

por

```php
            'tempo_estimado_s' => $o->tempo_estimado_s !== null ? (int) $o->tempo_estimado_s : null,
            'volta'            => (int) ($o->volta ?? 1),
            'rodada'           => (int) ($o->rodada ?? 1),
            'raio_m'           => isset($o->raio_m) ? (int) $o->raio_m : null,
```

(e) No array devolvido, troque

```php
            'distribuicao'  => true,
            'ligada'        => Distribuicao::ligada(), // desligada: o console não relê nem oferece o Abrir a todos
```

por

```php
            'distribuicao'    => true,
            'ligada'          => Distribuicao::ligada(), // desligada: o console não relê nem oferece o Abrir a todos
            'rodadas'         => Distribuicao::emRodadas(),
            'volta'           => (int) ($distribuicao->volta ?? 1),
            'rodada'          => (int) ($distribuicao->rodada ?? 1),
            'raio_m'          => $pedido ? Distribuicao::raioDaRodada(max(1, (int) $pedido->getAdhocDistance()), (int) ($distribuicao->rodada ?? 1)) : null,
            'lista_aberta_em' => Distribuicoes::data($distribuicao->lista_aberta_em ?? null)?->toIso8601String(),
```

- [ ] **Step 5: Rodar e ver passar**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-rotas.php`
Expected: `FALHAS: 0` (os casos de hoje continuam: sem a chave, o abrir ainda abre a todos e o recusar sem oferta dá 409).

- [ ] **Step 6: Commit**

```bash
git add api/app/Http/Controllers/Entregas/MotoboyController.php api/app/Http/Controllers/Entregas/DistribuicaoController.php scripts/teste-php/distribuicao-rotas.php
git commit -m "$(cat <<'EOF'
Distribuição em rodadas: rota recusar/dispensar e painel com Mostrar a todos agora, volta, rodada e raio

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 10: A lista do app com a lista aberta e o acréscimo até 2R (`FiltrarPedidosAbertosDoMotoboy`)

**Files:**
- Modify: `api/app/Http/Middleware/FiltrarPedidosAbertosDoMotoboy.php`
- Test: `scripts/teste-php/filtrar-pedidos-abertos.php`

- [ ] **Step 1: Escrever os testes que falham**

Em `scripts/teste-php/filtrar-pedidos-abertos.php`, insira **antes** da última linha (`resumo();`):

```php
echo '== Rodadas: lista fechada × aberta, dispensados e entregas_distribuicao' . PHP_EOL;

// o recurso do Fleet-Ops (Http\Resources\v1\Order) só com o que o teste confere
eval('namespace Fleetbase\FleetOps\Http\Resources\v1; class Order { public function __construct(public $resource) {} public function resolve($request = null) { return ["id" => $this->resource->public_id, "status" => $this->resource->status, "adhoc" => $this->resource->adhoc]; } }');

function ponto(array $p): object
{
    return new class($p) { public function __construct(private array $p) {} public function getLat() { return $this->p[0]; } public function getLng() { return $this->p[1]; } };
}

/** O cenário de sempre com as rodadas ligadas e A parado em (-21.17, -47.81). */
function cenarioRodadas(): void
{
    cenario();
    Config::$valores['services.entregas.distribuicao_rodadas'] = '1';
    Driver::$todos[0]->location = ponto([-21.1700, -47.8100]);
}

function item(array $itens, string $id): ?array
{
    foreach ($itens as $item) {
        if (($item['id'] ?? null) === $id) {
            return $item;
        }
    }

    return null;
}

cenarioRodadas();
$itens = itens(listar('12|token-do-motoboy-a'));
confere(array_column($itens, 'id') === ['order_1', 'order_3', 'order_4'], 'lista fechada (pedido 2, oferta de B): A não vê, como hoje (' . json_encode(array_column($itens, 'id')) . ')');
confere((item($itens, 'order_1')['entregas_distribuicao'] ?? null) === true && isset(item($itens, 'order_1')['entregas_oferta']), 'a oferta dele: entregas_oferta e entregas_distribuicao');
confere(!isset(item($itens, 'order_3')['entregas_distribuicao']) && !isset(item($itens, 'order_4')['entregas_distribuicao']), 'fora de ofertas (aberta) e sem distribuição: sem entregas_distribuicao');

Distribuicoes::abrirLista(2);
$itens = itens(listar('12|token-do-motoboy-a'));
confere(array_column($itens, 'id') === ['order_1', 'order_2', 'order_3', 'order_4'] && (item($itens, 'order_2')['entregas_distribuicao'] ?? null) === true && !isset(item($itens, 'order_2')['entregas_oferta']), 'lista aberta: A vê o pedido 2 (oferta de B), sem entregas_oferta');
Distribuicoes::registrarResposta(Distribuicoes::porId(2), 'd-a', 'dispensada');
confere(ids(listar('12|token-do-motoboy-a')) === ['order_1', 'order_3', 'order_4'], 'A dispensou o pedido 2 nesta volta: some da lista dele');
session(['user' => 'u-b']);
confere(ids(listar('13|token-do-motoboy-b', ['adhoc' => 1, 'unassigned' => 1, 'nearby' => 'driver_b'])) === ['order_2', 'order_3', 'order_4'], 'B (com a oferta do 2) continua vendo; o pedido 1 (lista fechada, oferta de A) não');
session(['user' => 'u-a']);
Distribuicoes::novaVolta(2, 2);
confere(ids(listar('12|token-do-motoboy-a')) === ['order_1', 'order_2', 'order_3', 'order_4'], 'volta nova: A volta a ver o pedido 2');

echo '== Rodadas: pedidos com a lista aberta além de R (até 2R)' . PHP_EOL;
/** Pedido aberto da empresa com a coleta a $km ao norte de A, em distribuição; com a lista aberta se pedido. */
function pedidoLonge(int $n, float $km, bool $listaAberta, array $extra = []): void
{
    $pedido          = new Order($extra + ['uuid' => "order-$n", 'public_id' => "order_$n", 'company_uuid' => 'empresa-1', 'adhoc' => true, 'status' => 'dispatched', 'driver_assigned_uuid' => null]);
    $pedido->payload = (object) ['pickup' => (object) ['location' => ponto([-21.1700 + 0.009 * $km, -47.8100])]];
    Order::$todos[]  = $pedido;
    $d               = Distribuicoes::criar($pedido);
    if ($listaAberta) {
        Distribuicoes::abrirLista($d->id);
    }
}

cenarioRodadas();
pedidoLonge(5, 10, true);                                   // 10 km: dentro de 2R (12 km)
pedidoLonge(6, 13, true);                                   // 13 km: fora de 2R
pedidoLonge(7, 3, false);                                   // lista fechada
pedidoLonge(8, 5, true, ['driver_assigned_uuid' => 'd-b']); // já tem motoboy
pedidoLonge(9, 8, true, ['status' => 'canceled']);          // encerrado
$itens = itens(listar('12|token-do-motoboy-a'));
confere(array_column($itens, 'id') === ['order_1', 'order_3', 'order_4', 'order_5'], 'acrescenta só o pedido 5 (lista aberta, coleta a 10 km ≤ 2R, sem motoboy) (' . json_encode(array_column($itens, 'id')) . ')');
confere(item($itens, 'order_5') === ['id' => 'order_5', 'status' => 'dispatched', 'adhoc' => true, 'entregas_distribuicao' => true], 'no formato do recurso do Fleet-Ops, com entregas_distribuicao (' . json_encode(item($itens, 'order_5')) . ')');
confere(ids(listar('12|token-do-motoboy-a', ['adhoc' => 1, 'unassigned' => 1], new JsonResponse([]))) === ['order_5'], 'lista do Fleet-Ops vazia: ainda acrescenta');
Distribuicoes::registrarResposta(Distribuicoes::doPedido('order-5'), 'd-a', 'dispensada');
confere(!in_array('order_5', ids(listar('12|token-do-motoboy-a')), true), 'dispensado nesta volta: não acrescenta');

cenarioRodadas();
pedidoLonge(5, 10, true);
Distribuicoes::criarOferta(Distribuicoes::doPedido('order-5'), ['motoboy_uuid' => 'd-a', 'tempo_s' => 900, 'encaixe' => false, 'aproximado' => true], 1, 1, 2, 9000);
$item5 = item(itens(listar('12|token-do-motoboy-a')), 'order_5');
confere(($item5['entregas_oferta']['segundos_restantes'] ?? null) === 20 && ($item5['entregas_distribuicao'] ?? null) === true, 'a oferta dele além de R (rodada 2) também vem, com entregas_oferta de 20 s');

cenarioRodadas();
Driver::$todos[0]->location = null;
pedidoLonge(5, 10, true);
confere(ids(listar('12|token-do-motoboy-a')) === ['order_1', 'order_3', 'order_4'], 'motoboy sem posição: nada a acrescentar');

cenario(); // rodadas desligadas
Driver::$todos[0]->location = ponto([-21.1700, -47.8100]);
pedidoLonge(5, 10, true);
$itens = itens(listar('12|token-do-motoboy-a'));
confere(array_column($itens, 'id') === ['order_1', 'order_3', 'order_4'] && !isset(item($itens, 'order_1')['entregas_distribuicao']), 'rodadas desligadas: nada muda (sem acréscimo nem entregas_distribuicao)');

```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/filtrar-pedidos-abertos.php`
Expected: `FALHA a oferta dele: entregas_oferta e entregas_distribuicao`, `FALHA lista aberta: A vê o pedido 2...` e `FALHA acrescenta só o pedido 5...`.

- [ ] **Step 3: Implementar**

Em `api/app/Http/Middleware/FiltrarPedidosAbertosDoMotoboy.php`:

(a) Troque os `use`

```php
use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Distribuicoes;
use App\Support\Entregas\MotoboyDaSessao;
use Closure;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController;
```

por

```php
use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Distribuicoes;
use App\Support\Entregas\Distribuicao\Pontos;
use App\Support\Entregas\MotoboyDaSessao;
use App\Support\Entregas\StatusDoPedido;
use Closure;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController;
use Fleetbase\FleetOps\Http\Resources\v1\Order as OrderResource;
use Fleetbase\FleetOps\Models\Order;
```

(b) No docblock da classe, troque

```php
 * Funciona com o APK atual (que só lista): o APK novo lê o entregas_oferta para o cronômetro.
 */
```

por

```php
 * Funciona com o APK atual (que só lista): o APK novo lê o entregas_oferta para o cronômetro.
 *
 * Em rodadas (ENTREGAS_DISTRIBUICAO_RODADAS): com a lista aberta (`lista_aberta_em`) o pedido em `ofertas` aparece para
 * todos, menos quem recusou ou dispensou na volta atual; todo pedido em `ofertas` leva `entregas_distribuicao: true` (o
 * app chama a rota de recusa no Dispensar). Como a lista do Fleet-Ops (`nearby`) só traz pedidos até R, acrescenta os
 * pedidos da empresa com a lista aberta cuja coleta está até 2R da posição do motoboy (drivers.location) e que não
 * vieram, no formato do Http\Resources\v1\Order, com as mesmas regras.
 */
```

(c) Troque o método `filtrar` inteiro (de `    private function filtrar(Request $request, $resposta)` até o `    }` antes de `    /** A lista de pedidos do corpo`) por:

```php
    private function filtrar(Request $request, $resposta)
    {
        if (!$resposta instanceof JsonResponse || $resposta->getStatusCode() !== 200) {
            return $resposta;
        }
        $corpo   = $resposta->getData(false);
        $itens   = $this->listaDe($corpo);
        $rodadas = Distribuicao::emRodadas();
        // em rodadas, até a lista vazia pode ganhar os pedidos com a lista aberta além de R
        if ($itens === null || (!$itens && !$rodadas)) {
            return $resposta;
        }

        // o item da lista traz `id` = public_id (Resources/v1/Order, requisição pública). Sem join (a empresa é filtrada
        // nas duas tabelas): os uuids dos pedidos da lista e, entre eles, os que estão em fase ofertas
        $ids = [];
        foreach ($itens as $item) {
            if (is_object($item) && isset($item->id) && is_string($item->id)) {
                $ids[] = $item->id;
            }
        }
        if ($ids === [] && !$rodadas) {
            return $resposta;
        }
        // só agora o motoboy da sessão: lista vazia ou sem id nem chega aqui (fora das rodadas)
        $motoboy = MotoboyDaSessao::motoboy($request);
        if (!$motoboy) {
            return $resposta;
        }
        $motoboyUuid = (string) $motoboy->uuid;
        $empresa     = (string) session('company');
        $uuidDe      = $ids ? DB::table('orders')->where('company_uuid', $empresa)->whereIn('public_id', array_values(array_unique($ids)))->pluck('uuid', 'public_id')->all() : [];
        $porPublicId = [];
        if ($uuidDe !== []) {
            $emOfertas = DB::table(Distribuicoes::TABELA)
                ->where('company_uuid', $empresa)
                ->where('fase', Distribuicao::FASE_OFERTAS)
                ->whereIn('pedido_uuid', array_values($uuidDe))
                ->get(['id', 'pedido_uuid', 'volta', 'lista_aberta_em']);
            $publicIdDe = array_flip($uuidDe); // uuid => public_id
            foreach ($emOfertas as $linha) {
                if (isset($publicIdDe[$linha->pedido_uuid])) {
                    $porPublicId[$publicIdDe[$linha->pedido_uuid]] = $linha;
                }
            }
        }

        $filtrados = [];
        foreach ($itens as $item) {
            $id = is_object($item) ? ($item->id ?? null) : null;
            if (!is_string($id) || !isset($porPublicId[$id])) {
                $filtrados[] = $item;
                continue;
            }
            $paraEle = $this->paraOMotoboy($item, $porPublicId[$id], $motoboyUuid, $rodadas);
            if ($paraEle) {
                $filtrados[] = $paraEle;
            }
        }
        if ($rodadas) {
            foreach ($this->alemDeR($request, $motoboy, $empresa, array_values($uuidDe)) as [$item, $linha]) {
                $paraEle = $this->paraOMotoboy($item, $linha, $motoboyUuid, true);
                if ($paraEle) {
                    $filtrados[] = $paraEle;
                }
            }
        }

        $resposta->setData($this->comLista($corpo, $filtrados));

        return $resposta;
    }

    /**
     * O item de um pedido em fase ofertas para este motoboy, ou null (não aparece para ele). Com a oferta pendente dele:
     * `entregas_oferta`. Em rodadas: com a lista aberta aparece para todos, menos quem recusou ou dispensou na volta
     * atual, e todo item leva `entregas_distribuicao: true`.
     */
    private function paraOMotoboy(object $item, object $distribuicao, string $motoboyUuid, bool $rodadas): ?object
    {
        $oferta = Distribuicoes::ofertaPendente((int) $distribuicao->id);
        $dele   = $oferta && (string) $oferta->motoboy_uuid === $motoboyUuid;
        if (!$dele) {
            if (!$rodadas || !$distribuicao->lista_aberta_em) {
                return null; // oferecido a outro, lista fechada: o motoboy não vê
            }
            if (in_array($motoboyUuid, Distribuicoes::motoboysQueDispensaramNaVolta((int) $distribuicao->id, (int) ($distribuicao->volta ?? 1)), true)) {
                return null; // recusou ou dispensou nesta volta
            }
        }
        if ($dele) {
            $venceEm               = Distribuicoes::data($oferta->vence_em);
            $item->entregas_oferta = [
                'vence_em'           => $venceEm?->toIso8601String(),
                'tempo_estimado_s'   => (int) $oferta->tempo_estimado_s,
                'segundos_restantes' => $venceEm ? max(0, $venceEm->getTimestamp() - now()->getTimestamp()) : 0,
            ];
        }
        if ($rodadas) {
            $item->entregas_distribuicao = true;
        }

        return $item;
    }

    /**
     * Em rodadas: os pedidos da empresa em ofertas com a lista aberta que não vieram na lista do Fleet-Ops, abertos, sem
     * motoboy, não encerrados e com a coleta até MULTIPLICADOR_DA_LISTA × R da posição do motoboy, no formato do
     * Http\Resources\v1\Order.
     *
     * @return array<int, array{0: object, 1: object}> [item, distribuição]
     */
    private function alemDeR(Request $request, $motoboy, string $empresa, array $jaVieram): array
    {
        $posicao = Pontos::de($motoboy->location);
        if (!$posicao) {
            return [];
        }
        $distribuicoes = [];
        foreach (Distribuicoes::comListaAberta($empresa) as $linha) {
            if (!in_array((string) $linha->pedido_uuid, $jaVieram, true)) {
                $distribuicoes[(string) $linha->pedido_uuid] = $linha;
            }
        }
        if ($distribuicoes === []) {
            return [];
        }
        $pedidos = Order::whereIn('uuid', array_keys($distribuicoes))
            ->where('company_uuid', $empresa)
            ->with(['payload.pickup', 'trackingStatuses', 'driverAssigned', 'vehicleAssigned', 'customer', 'facilitator'])
            ->get();

        $extras = [];
        foreach ($pedidos as $pedido) {
            if (!$pedido->adhoc || $pedido->driver_assigned_uuid || in_array(strtolower((string) $pedido->status), StatusDoPedido::ENCERRADOS, true)) {
                continue;
            }
            $coleta = Pontos::de($pedido->getPickupLocation());
            if (!$coleta || Pontos::metros($posicao, $coleta) > Distribuicao::MULTIPLICADOR_DA_LISTA * max(1, (int) $pedido->getAdhocDistance())) {
                continue;
            }
            $item = json_decode(json_encode((new OrderResource($pedido))->resolve($request)));
            if (is_object($item)) {
                $extras[] = [$item, $distribuicoes[(string) $pedido->uuid]];
            }
        }

        return $extras;
    }
```

- [ ] **Step 4: Rodar e ver passar**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/filtrar-pedidos-abertos.php`
Expected: `FALHAS: 0` (os casos de hoje, inclusive "lista vazia ou sem id: sai antes de buscar o motoboy e o banco" e "erro no banco", continuam).

- [ ] **Step 5: Commit**

```bash
git add api/app/Http/Middleware/FiltrarPedidosAbertosDoMotoboy.php scripts/teste-php/filtrar-pedidos-abertos.php
git commit -m "$(cat <<'EOF'
Distribuição em rodadas: lista do app com a lista aberta, dispensados e pedidos até 2R

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 11: Reenvio (conferir e documentar)

O spec pede: nada de reenvio em `ofertas`, aviso "sem motoboy" mantido. O `ReenviarPedidosAbertos::emDistribuicao` já pula os pedidos em `ofertas` (`Distribuicao::ligada() && Distribuicoes::emOfertas()`), e em rodadas a fase `ofertas` dura até o fim; o aviso à central é tratado antes do pulo. Os testes `reenvio.php` ("Distribuição em ofertas: sem reenvio" e "o aviso à central segue") já cobrem. Só o docblock muda.

**Files:**
- Modify: `api/app/Console/Commands/Entregas/ReenviarPedidosAbertos.php` (docblock)
- Test: `scripts/teste-php/reenvio.php` (sem mudança)

- [ ] **Step 1: Atualizar o docblock**

Em `api/app/Console/Commands/Entregas/ReenviarPedidosAbertos.php`, troque

```php
 * aos motoboys espera (emDistribuicao); o aviso à central não é afetado. Na fase `aberta` segue como acima.
```

por

```php
 * aos motoboys espera (emDistribuicao); o aviso à central não é afetado. Na fase `aberta` segue como acima. Em rodadas
 * (ENTREGAS_DISTRIBUICAO_RODADAS) a fase `ofertas` dura até o aceite ou o encerramento: esses pedidos nunca recebem
 * reenvio, e o aviso "sem motoboy" aos 12 min continua.
```

- [ ] **Step 2: Rodar**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/reenvio.php`
Expected: `FALHAS: 0`.

- [ ] **Step 3: Commit**

```bash
git add api/app/Console/Commands/Entregas/ReenviarPedidosAbertos.php
git commit -m "$(cat <<'EOF'
Distribuição em rodadas: documenta que o reenvio nunca alcança pedidos em rodadas

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 12: Imports, sintaxe e todos os testes

**Files:**
- Modify: `scripts/teste-php/imports-dos-providers.php`

- [ ] **Step 1: Incluir os arquivos novos e mudados no teste de imports**

Em `scripts/teste-php/imports-dos-providers.php`, troque

```php
        $app . '/Jobs/Entregas/AvancarOferta.php',
```

por

```php
        $app . '/Jobs/Entregas/AvancarOferta.php',
        $app . '/Jobs/Entregas/AvancarDistribuicao.php',
        $app . '/Http/Middleware/BarrarAceiteDePedidoEncerrado.php',
```

- [ ] **Step 2: Rodar o teste de imports**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/imports-dos-providers.php`
Expected: `FALHAS: 0`. Se acusar um nome sem `use`, acrescente o `use` no arquivo apontado e rode de novo.

- [ ] **Step 3: Sintaxe (`php -l` do 8.2) de tudo o que mudou**

Run:

```bash
PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/config/services.php api/database/migrations/2026_10_07_120000_add_rodadas_entregas_distribuicao_table.php api/app/Support/Entregas/Distribuicao/Distribuicao.php api/app/Support/Entregas/Distribuicao/Distribuicoes.php api/app/Support/Entregas/Distribuicao/Distribuidor.php api/app/Support/Entregas/Distribuicao/Encaixe.php api/app/Support/Entregas/Distribuicao/FilaDeCandidatos.php api/app/Support/Entregas/Distribuicao/Candidatos.php api/app/Jobs/Entregas/AvancarDistribuicao.php api/app/Jobs/Entregas/AvancarOferta.php api/app/Notifications/Entregas/OfertaDePedido.php api/app/Notifications/Entregas/AvisosDoMotoboy.php api/app/Console/Commands/Entregas/VarrerDistribuicoes.php api/app/Console/Commands/Entregas/ReenviarPedidosAbertos.php api/app/Http/Middleware/BarrarAceiteDePedidoEncerrado.php api/app/Http/Middleware/FiltrarPedidosAbertosDoMotoboy.php api/app/Http/Controllers/Entregas/MotoboyController.php api/app/Http/Controllers/Entregas/DistribuicaoController.php scripts/teste-php/distribuicao-rodadas.php scripts/teste-php/distribuicao-encaixe.php scripts/teste-php/distribuicao-fila.php scripts/teste-php/distribuicao-rotas.php scripts/teste-php/filtrar-pedidos-abertos.php scripts/teste-php/barrar-aceite.php scripts/teste-php/avisos-push.php scripts/teste-php/imports-dos-providers.php
```

Expected: uma linha `OK  <arquivo>` por arquivo e saída 0.

- [ ] **Step 4: Todos os testes php-wasm relacionados**

Run:

```bash
for t in distribuicao-rodadas distribuicao-ciclo distribuicao-encaixe distribuicao-fila distribuicao-rotas filtrar-pedidos-abertos barrar-aceite reenvio avisos-push cartao-do-alarme imports-dos-providers ifood-stub-banco ifood-migrations lider pedidos-no-mapa mapa-da-loja; do echo "== $t"; PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php > /tmp/saida-$t.txt 2>&1; echo "saída $?"; tail -1 /tmp/saida-$t.txt; done
```

Expected: para cada teste, `saída 0` e `FALHAS: 0`. Uma falha: leia `/tmp/saida-<teste>.txt`, corrija e rode de novo só esse teste antes de seguir.

- [ ] **Step 5: Commit**

```bash
git add scripts/teste-php/imports-dos-providers.php
git commit -m "$(cat <<'EOF'
Distribuição em rodadas: teste de imports cobre o job novo e o aceite

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 13: CLAUDE.md

**Files:**
- Modify: `CLAUDE.md` (seção "Distribuição de pedidos abertos" e "Histórico")

- [ ] **Step 1: Subseção nova**

Em `CLAUDE.md`, troque

```markdown
## Marca Entregas RestaurantePro (sem Fleetbase na tela)
```

por

```markdown
### Rodadas (`ENTREGAS_DISTRIBUICAO_RODADAS`)

Decisão de 2026-10-07, depois do primeiro teste real. Desenho: `docs/superpowers/specs/2026-10-07-distribuicao-em-rodadas-design.md`; plano da API: `docs/superpowers/plans/2026-10-07-distribuicao-em-rodadas-api.md` (**o código é a referência**). Console e APK 30 vêm em plano próprio.

- **Chave:** `ENTREGAS_DISTRIBUICAO_RODADAS=1` (`services.entregas.distribuicao_rodadas`, `Distribuicao::emRodadas()`), só vale com `ENTREGAS_DISTRIBUICAO=1`. Vazia: o ciclo das seções acima (30 s, encaixe, 3 min, abre a todos), sem mudança. **Ligue só com o APK 30 em todos os celulares** (o anterior toca 3 min e vários celulares tocariam juntos). Está no `x-api-env`: cole o `docker-stack.yml` novo no Portainer.
- **O ciclo** (`Distribuidor::avancarEmRodadas`, sob a `TravaDoPedido`): oferta de **20 s**, um motoboy por vez, e **nunca abre a todos** (sem prazo de 3 min, sem `OrderPing` geral; só a `falha` ainda abre com o alarme geral, como rede de segurança).
  - Rodadas: 1 = até R, 2 = até 1,5R, 3 = até 2R (R = `Order::getAdhocDistance()`, `Distribuicao::raioDaRodada`).
  - Candidatos da rodada: disponíveis até o raio, GPS de menos de 30 min, **fora** quem tem qualquer linha nesta volta (oferta de qualquer resposta ou dispensa) e quem tem oferta pendente de outro pedido. Recalculados a cada oferta.
  - Rodada vazia passa à seguinte. Sair da rodada 1 da volta 1 grava `lista_aberta_em`.
  - Depois da rodada 3: volta nova (rodada 1, todos de novo, inclusive quem recusou) só 1 min depois do início da volta (`volta_iniciada_em`; nulo nas distribuições antigas = `despachada_em`). Antes disso, o job `AvancarDistribuicao` volta na hora certa (a varredura é a reserva). No máximo uma volta nova por passo: uma volta inteira sem ninguém para ("aguardando motoboy") e a varredura tenta a cada minuto.
  - A fila é "termina tudo e depois vai" (`Encaixe::noFim`): sem encaixe no meio nem o limite de 10 min; o `encaixe` fica sempre falso.
- **Aceite** (`BarrarAceiteDePedidoEncerrado`): com a lista fechada, só quem tem a oferta (como antes). Com a lista aberta, qualquer motoboy (o primeiro leva; a pendente vira `cancelada`): quem tinha a oferta grava `aceita` nela; os outros ganham a linha `aceita_pela_lista` (`Distribuidor::registrarAceitePelaLista`). Motivo `aceita` nos dois. Em rodadas, quem tenta aceitar um pedido aberto que outro já iniciou leva 409 "Este pedido passou para outro motoboy." (sem rodadas, segue o "Order has already started." do Fleet-Ops).
- **Recusar e Dispensar** (`POST v1/entregas/motoboy/pedidos/{id}/recusar`, `Distribuidor::recusarOuDispensar`): oferta pendente dele → 200 `{"resultado": "recusada"}` e o próximo passo na hora; lista aberta sem oferta dele → 200 `{"resultado": "dispensada"}` (linha `dispensada` na volta, uma só por volta: some da lista dele e não recebe oferta até a volta seguinte); senão 409 "Esta oferta não está mais com você."; trava ocupada 503.
- **Lista do app** (`FiltrarPedidosAbertosDoMotoboy`): lista fechada como antes; aberta para todos, menos quem recusou ou dispensou na volta atual; `entregas_distribuicao: true` em todo pedido em `ofertas`. Acrescenta os pedidos com a lista aberta cuja coleta está até 2R da posição do motoboy (`drivers.location`) e que o `nearby` do Fleet-Ops (só até R) não trouxe, no formato `Http\Resources\v1\Order` (limite de 50 distribuições por consulta).
- **Console:** `POST .../distribuicao/abrir` vira "Mostrar a todos agora" (`Distribuidor::mostrarATodos`): grava `lista_aberta_em`, sem alarme, e o ciclo segue; 409 "A lista deste pedido já está aberta a todos." ou "Este pedido não está em oferta.". O `GET .../distribuicao` traz `rodadas`, `volta`, `rodada`, `raio_m` (da rodada atual) e `lista_aberta_em`; cada linha do histórico, `volta`, `rodada` e `raio_m`.
- **Varredura:** vence as pendentes vencidas e encerra (como antes) e **avança** as distribuições em `ofertas` sem pendente e com `updated_at` de mais de 1 min (despachadas nas últimas 24 h). Não abre pelo prazo.
- **Reenvio:** em rodadas a fase `ofertas` dura até o fim, então o `ReenviarPedidosAbertos` nunca reenvia esses pedidos; o aviso "sem motoboy" aos 12 min continua.
- **Push:** `OfertaDePedido` leva `segundosDaOferta` (20 em rodadas), o teto do `android.ttl`.
- **Dados:** migration `2026_10_07_120000_add_rodadas_entregas_distribuicao_table` (`volta`, `rodada`, `volta_iniciada_em`, `lista_aberta_em`; nas ofertas `volta`, `rodada`, `raio_m` e o índice `(distribuicao_id, volta)`). Respostas novas: `dispensada` e `aceita_pela_lista` (linhas sem oferta: `oferecida_em` = `vence_em` = `respondida_em`).
- **Logs** (`[entregas] distribuição:`): `rodada <n> (raio <m> m)`, `volta <n>`, `lista aberta`, `oferta dispensada`, `aceita pela lista`, `aguardando motoboy`, `job da próxima volta não entrou na fila`, `trava ocupada ao avançar a distribuição`.
- **Ligar e desligar no meio:** ligada, as distribuições em `ofertas` seguem no ciclo novo no próximo passo (volta 1, rodada 1 pelas colunas padrão); desligada, voltam ao ciclo antigo (o prazo de 3 min pode abrir a todos na hora). O job `AvancarDistribuicao` com a chave desligada não faz nada.
- **Riscos aceitos:** com um motoboy só, ele recebe a oferta a cada 1 min até aceitar (20 s tocando, 40 s parado); o motoboy até 2R vê na lista pedidos de lojas a 12 km; o tempo é em linha reta (OSRM próprio continua sendo a melhoria); job `AvancarDistribuicao` repetido não faz mal (com pendente, espera).
- **Testes:** `scripts/teste-php/distribuicao-rodadas.php` (novo) e os casos "Rodadas" no fim de `distribuicao-encaixe.php`, `distribuicao-fila.php`, `distribuicao-rotas.php`, `filtrar-pedidos-abertos.php`, `barrar-aceite.php` e `avisos-push.php`. Os testes de antes continuam valendo com a chave desligada.
- **Implantação:** `bash deploy/atualizar.sh api` (migration nova) → console (plano próprio) → APK 30 em todos os celulares → `ENTREGAS_DISTRIBUICAO_RODADAS=1` no `stack.env` e o `docker-stack.yml` novo no Portainer (Update the stack, "Re-pull image" desligado) → teste controlado na loja de teste, com os motoboys avisados. Se algo der errado: `ENTREGAS_DISTRIBUICAO_RODADAS=` vazio e Update the stack.

## Marca Entregas RestaurantePro (sem Fleetbase na tela)
```

- [ ] **Step 2: Histórico**

Acrescente ao fim do `CLAUDE.md` (depois do item 23 do histórico):

```bash
printf '%s\n' '24. Distribuição em rodadas (2026-10-07, ramo `distribuicao-rodadas`): oferta de 20 s em rodadas R, 1,5R e 2R e voltas até alguém aceitar, sem alarme a todos; lista "Novos pedidos" aberta a partir da rodada 2 (até 2R), Recusar/Dispensar pelo servidor e "Mostrar a todos agora" no console, atrás de `ENTREGAS_DISTRIBUICAO_RODADAS` (ver "Distribuição de pedidos abertos" → "Rodadas").' >> CLAUDE.md
```

Confira com `tail -3 CLAUDE.md` que o item 24 ficou numa linha própria depois do 23 (se o arquivo não terminava em quebra de linha, junte as duas linhas à mão).

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "$(cat <<'EOF'
Documentação: distribuição em rodadas no CLAUDE.md

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

## Auto-revisão do plano (feita ao escrever)

- **Cobertura do spec:**
  - §2 critério da fila e encaixe fora → Task 2 (`Encaixe::noFim`, `FilaDeCandidatos::para(..., true)`); 20 s → Tasks 1, 3, 4, 5; rodadas R/1,5R/2R → Tasks 1 e 5; quem entra em cada rodada → Task 5 (`oferecerNaRodada`); volta nova e intervalo de 1 min → Task 5; recálculo a cada oferta → Task 5 (teste da Eva); lista "Novos pedidos" → Task 10; alarme um por vez → Task 5 (nenhum `OrderPing`); Recusar e Dispensar → Tasks 6 e 9; sem ninguém → Tasks 5 e 7; central (aviso de 12 min) → Task 11; sai prazo/alarme geral/reenvios → Tasks 5, 7 e 11; botão do console → Tasks 6 e 9; chave → Task 1.
  - §3 passo do ciclo (itens 1 a 6) → Task 5; motoboy com oferta pendente de outro pedido conta como não perguntado → Task 5 (teste).
  - §4 aceite (fechada, aberta, `aceita`/`aceita_pela_lista`, 409 de quem chega depois) → Tasks 3 (`ofertaParaAceite`), 6 e 8.
  - §5 tabela da rota recusar → Task 9.
  - §6 dados → Tasks 1 e 3.
  - §7 lista do app (fechada, aberta, dispensados, `entregas_oferta`, `entregas_distribuicao`, acréscimo até 2R, erro devolve o original) → Task 10.
  - §8 painel (campos `volta`, `rodada`, `raio_m`, `lista_aberta_em`, `rodadas`; histórico com volta/rodada/raio; Mostrar a todos) → Task 9. A parte visual é do plano do console.
  - §9 app → fora deste plano (APK 30); o servidor já entrega `segundos_restantes`/`entregas_oferta_segundos` de 20 s (Tasks 4 e 10).
  - §10 chave e compatibilidade (só com a distribuição ligada; ligar e desligar no meio) → Tasks 1, 5 e 7 (teste "rodadas desligadas no meio").
  - §11 varredura → Task 7. §12 erros (falha abre a todos; push falhou; job perdido; logs novos) → Tasks 5 e 7. §13 testes → Tasks 1 a 12.
- **Placeholders:** nenhum "TBD"/"implementar depois"; todo passo de código traz o código.
- **Nomes consistentes:** `emRodadas`, `segundosDaOferta`, `raioDaRodada`, `SEGUNDOS_ENTRE_VOLTAS`, `SEGUNDOS_PARADA`, `MULTIPLICADOR_DA_LISTA`, `LISTA_ABERTA_AGORA`/`LISTA_JA_ABERTA`/`FORA_DE_OFERTAS` (Task 1) são os usados nas Tasks 5 a 10; `criarOferta(..., $volta, $rodada, $raioM)`, `registrarResposta`, `irParaRodada`, `novaVolta`, `abrirLista`, `tocar`, `motoboysDaVolta`, `motoboysQueDispensaramNaVolta`, `emOfertasParadasHa`, `comListaAberta` (Task 3) idem; `avancarOuAbrir`, `recusarOuDispensar`, `mostrarATodos`, `registrarAceitePelaLista` (Tasks 5 e 6) são os chamados pelo job, pela varredura, pelo middleware e pelos controllers; `AvancarDistribuicao::agendar(string, int)` e `->pedidoUuid` batem com os testes.
