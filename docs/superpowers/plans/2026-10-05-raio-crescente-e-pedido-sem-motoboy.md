# Raio crescente e aviso "sem motoboy": plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** etapa 1 da integração iFood (spec `docs/superpowers/specs/2026-10-05-integracao-ifood-logistics-design.md`):
nos reenvios de pedido aberto, o raio cresce (R → 1,5R → 2R → 2R) e, com ~12 min sem aceite, a central recebe no
console um aviso fixo com som. Vale para **todos** os pedidos abertos (portal, central e, depois, iFood).

**Architecture:** o `ReenviarPedidosAbertos` (agendador, a cada minuto) passa a calcular o raio pelo número do
reenvio e, uma vez por despacho, transmite o evento `entregas.pedido_sem_motoboy` (`PedidoSemMotoboy`) no canal
`company.<uuid>`. No console, um serviço do Fleet-Ops (`pedido-sem-motoboy`), iniciado na rota raiz do engine, escuta
o canal (util `escutar-canal-da-empresa`, também usado pela lista "Operações ao vivo"), toca um som e mostra uma
notificação fixa que abre o pedido; ela some quando o pedido ganha motoboy, é iniciado, cancelado ou concluído.

**Tech Stack:** Laravel 10 / PHP 8.2 (`api/app`), Ember (engine `packages/fleetops/addon`), SocketCluster, testes
php-wasm (`scripts/teste-php`) e `node --test` (`scripts/teste-portal`).

## Contexto para quem executa

- Leia o `CLAUDE.md` da raiz (seções "Reenvio de pedido aberto" e "Mapa ao vivo") antes de começar. Tudo em pt-BR:
  textos, comentários, nomes novos.
- PHP próprio vai em `api/app`; nada em `packages/*/server` chega à produção.
- O frontend do Fleet-Ops fica em `packages/fleetops/addon`; serviços novos precisam do re-export em
  `packages/fleetops/app/services/`.
- Testes de PHP sem PHP instalado: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs <teste.php>`.
  Se `/c/tmp/php-wasm` não existir: `npm i --prefix /c/tmp/php-wasm @php-wasm/node@3.1.54 @php-wasm/universal@3.1.54`.
  Sintaxe: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs <arquivo.php>`.
- Testes de JS: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/<arquivo>.test.mjs`
  (o resolver aceita imports sem extensão, como o build do Ember).
- Comandos em Git Bash, na raiz do repo (`C:\Users\Edgardjr\Documents\vibe coding\Delivery`). Confirme
  `git rev-parse --show-toplevel` antes de cada commit (a home também é um repo git).
- Commits terminam com `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`. **Não** faça push.

## Arquivos

| Arquivo | Ação | Papel |
|---|---|---|
| `api/app/Console/Commands/Entregas/ReenviarPedidosAbertos.php` | Modificar | Raio por reenvio e aviso à central |
| `api/app/Events/Entregas/PedidoSemMotoboy.php` | Criar | Evento `entregas.pedido_sem_motoboy` no canal da empresa |
| `scripts/teste-php/stubs.php` | Modificar | `internal_id` no Order e `toIso8601String` no Carbon |
| `scripts/teste-php/stubs-reenvio.php` | Criar | `broadcast()`, `Channel`, `ShouldBroadcastNow` e `Log` do teste do reenvio |
| `scripts/teste-php/reenvio.php` | Modificar | Casos novos e ajuste das distâncias dos antigos |
| `packages/fleetops/addon/utils/escutar-canal-da-empresa.js` | Criar | Consumidor próprio do canal `company.<uuid>` com reinscrição |
| `scripts/teste-portal/escutar-canal-da-empresa.test.mjs` | Criar | Testes do util |
| `packages/fleetops/addon/components/layout/fleet-ops-sidebar/operations-monitor.js` | Modificar | Passa a usar o util |
| `packages/fleetops/addon/utils/pedido-sem-motoboy.js` | Criar | Funções puras: ler o aviso e os eventos que o encerram |
| `scripts/teste-portal/pedido-sem-motoboy.test.mjs` | Criar | Testes do util |
| `packages/fleetops/addon/utils/som-de-alerta.js` | Criar | Três toques (Web Audio) |
| `packages/fleetops/addon/services/pedido-sem-motoboy.js` | Criar | Escuta, toca e mostra/fecha a notificação |
| `packages/fleetops/app/services/pedido-sem-motoboy.js` | Criar | Re-export do serviço |
| `packages/fleetops/addon/routes/application.js` | Modificar | Inicia o serviço ao entrar no Fleet-Ops |
| `packages/fleetops/translations/pt-br.yaml`, `en-us.yaml` | Modificar | Texto do aviso |
| `CLAUDE.md` | Modificar | Documenta a regra nova |

---

### Task 1: raio crescente no reenvio

**Files:**
- Modify: `api/app/Console/Commands/Entregas/ReenviarPedidosAbertos.php`
- Modify: `scripts/teste-php/reenvio.php`

O primeiro aviso (no despacho) é do Fleet-Ops e usa o raio R (`getAdhocPingDistance()`, 6000 m no stub). Os
reenvios 1, 2 e 3 passam a usar 1,5R, 2R e 2R. No stub, o motoboy entra quando `distance <= raio`.

- [ ] **Step 1: ajustar as distâncias dos testes antigos que mudariam com o raio maior**

Em `scripts/teste-php/reenvio.php`, o motoboy "Longe" (9000 m) e o "Motoca" fora do raio (9000 m) passariam a
receber no 1º reenvio (1,5 × 6000 = 9000). Troque por 13000 m (fora até de 2R = 12000):

```php
reiniciar([pedido('PED-B', '12:00:30')], [motoboy('Motoca', 1200), motoboy('Ocupado', 800, 'busy'), motoboy('Longe', 13000), motoboy('Offline', 500, 'available', 0)]);
```

```php
echo '== Sem motoboy no raio até 12:07' . PHP_EOL;
$motoca = motoboy('Motoca', 13000);
reiniciar([pedido('PED-C', '12:00:30')], [$motoca]);
rodarMinutos('12:01:00', 30, function ($agora) use ($motoca) {
    $motoca->distance = $agora >= utc('12:07:00') ? 1200 : 13000;
});
```

- [ ] **Step 2: escrever os testes do raio**

Em `scripts/teste-php/reenvio.php`, antes de `resumo();`, acrescente:

```php
echo '== Raio crescente (R = 6000 m)' . PHP_EOL;
confere(ReenviarPedidosAbertos::raioDoReenvio(6000, 1) === 9000, '1º reenvio: 1,5R');
confere(ReenviarPedidosAbertos::raioDoReenvio(6000, 2) === 12000, '2º reenvio: 2R');
confere(ReenviarPedidosAbertos::raioDoReenvio(6000, 3) === 12000, '3º reenvio: 2R');
confere(ReenviarPedidosAbertos::raioDoReenvio(6000, 9) === 12000, 'além do 3º: continua 2R');
confere(ReenviarPedidosAbertos::raioDoReenvio(5000, 1) === 7500, 'arredonda para metros inteiros');

reiniciar([pedido('PED-R', '12:00:30')], [motoboy('Perto', 5000), motoboy('Medio', 8000), motoboy('Longe', 11000), motoboy('MuitoLonge', 13000)]);
rodarMinutos('12:01:00', 30);
$porMinuto = [];
foreach (Registro::$avisos as $a) {
    $porMinuto[$a['quando']->format('H:i')][] = $a['motoboy'];
}
$rodadas = array_values($porMinuto);
confere(count($rodadas) === 3, 'três reenvios (' . implode(', ', array_keys($porMinuto)) . ')');
confere(($rodadas[0] ?? []) === ['Perto', 'Medio'], '1º reenvio até 9 km: ' . implode(', ', $rodadas[0] ?? []));
confere(($rodadas[1] ?? []) === ['Perto', 'Medio', 'Longe'], '2º reenvio até 12 km: ' . implode(', ', $rodadas[1] ?? []));
confere(($rodadas[2] ?? []) === ['Perto', 'Medio', 'Longe'], '3º reenvio até 12 km: ' . implode(', ', $rodadas[2] ?? []));
```

- [ ] **Step 3: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/reenvio.php`
Expected: erro de PHP "Call to undefined method ...raioDoReenvio()" (sai com 1).

- [ ] **Step 4: implementar**

Em `ReenviarPedidosAbertos.php`, acrescente a constante e o método e troque o raio da busca:

```php
    /** Raio de cada reenvio em relação ao raio do primeiro aviso (R, getAdhocPingDistance): 1,5R, 2R e 2R. */
    public const MULTIPLICADORES_DO_RAIO = [1.5, 2, 2];
```

```php
    /**
     * Raio do reenvio de número $reenvio (1, 2, 3...), em metros. Depois do último multiplicador, repete o último.
     */
    public static function raioDoReenvio(int $raio, int $reenvio): int
    {
        $multiplicadores = self::MULTIPLICADORES_DO_RAIO;
        $indice          = max(0, min($reenvio, count($multiplicadores)) - 1);

        return (int) round($raio * $multiplicadores[$indice]);
    }
```

No `handle()`, substitua as linhas da busca e das mensagens:

```php
            $raio     = self::raioDoReenvio($pedido->getAdhocPingDistance(), $estado['vezes'] + 1);
            $motoboys = $this->getNearbyDriversForOrder($pedido, $coleta, $raio, $testing);
            if ($motoboys->isEmpty()) {
                $this->line('Pedido ' . $pedido->public_id . ': nenhum motoboy livre a até ' . $raio . ' m da coleta.');
                continue;
            }
```

```php
            $this->info('Pedido ' . $pedido->public_id . ': aviso ' . $vezes . ' de ' . self::MAX_REENVIOS . ' reenviado a ' . $motoboys->count() . ' motoboy(s) (raio ' . $raio . ' m).');
```

No docblock da classe, troque "para os mesmos motoboys do primeiro aviso (online, livres e dentro do raio da
coleta)" por:

```
 * Aqui o pedido não é alterado. O aviso volta a cada INTERVALO_MINUTOS, no máximo MAX_REENVIOS vezes, para os motoboys
 * online e livres perto da coleta, num raio que cresce a cada reenvio (MULTIPLICADORES_DO_RAIO sobre o raio R do
 * primeiro aviso: 1,5R, 2R e 2R). Sem motoboy no raio, tenta de novo no minuto seguinte sem gastar reenvio. A contagem
 * fica no cache, por pedido e despacho: despachar o pedido de novo recomeça.
```

- [ ] **Step 5: rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/reenvio.php`
Expected: todas as linhas `PASSA` e `FALHAS: 0`.

- [ ] **Step 6: commit**

```bash
git add api/app/Console/Commands/Entregas/ReenviarPedidosAbertos.php scripts/teste-php/reenvio.php
git commit -m "Reenvio de pedido aberto: raio cresce a cada reenvio (1,5R, 2R, 2R)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: evento `entregas.pedido_sem_motoboy` no reenvio

**Files:**
- Create: `api/app/Events/Entregas/PedidoSemMotoboy.php`
- Create: `scripts/teste-php/stubs-reenvio.php`
- Modify: `scripts/teste-php/stubs.php`
- Modify: `api/app/Console/Commands/Entregas/ReenviarPedidosAbertos.php`
- Modify: `scripts/teste-php/reenvio.php`

Regra: com `AVISO_CENTRAL_MINUTOS` (= 4 × 3 = 12) menos `FOLGA_SEGUNDOS` desde o despacho, sem motoboy e não
iniciado, transmite uma vez por despacho, **mesmo sem motoboy no raio** (é quando mais importa). Se o socket falhar,
registra no log e tenta de novo no minuto seguinte. O aviso sai antes das checagens de reenvio do laço, porque o
pedido com 3 reenvios feitos é pulado por elas.

- [ ] **Step 1: stubs do teste**

Crie `scripts/teste-php/stubs-reenvio.php`:

```php
<?php

// Stubs do teste do reenvio (reenvio.php), além do stubs.php: a transmissão no socket (broadcast), o Channel, o
// ShouldBroadcastNow e o Log.

namespace Illuminate\Broadcasting {
    class Channel
    {
        public function __construct(public string $name) {}
    }
}

namespace Illuminate\Contracts\Broadcasting {
    interface ShouldBroadcastNow {}
}

namespace Illuminate\Support\Facades {
    class Log
    {
        public static array $linhas = [];
        public static function warning($mensagem, array $contexto = []) { self::$linhas[] = ['warning', $mensagem, $contexto]; }
        public static function info($mensagem, array $contexto = []) { self::$linhas[] = ['info', $mensagem, $contexto]; }
    }
}

namespace Teste {
    class Socket
    {
        public static array $transmitidos = [];
        public static bool $falhar        = false;
    }
}

namespace {
    function broadcast($evento)
    {
        if (\Teste\Socket::$falhar) {
            throw new \RuntimeException('socket fora do ar');
        }
        \Teste\Socket::$transmitidos[] = ['quando' => \Carbon\CarbonImmutable::now(), 'evento' => $evento];
    }
}
```

Em `scripts/teste-php/stubs.php`:
- na classe `Fleetbase\FleetOps\Models\Order`, depois de `public $driver_assigned_uuid = null;`, acrescente
  `public $internal_id          = null;`;
- na classe `Illuminate\Support\Carbon`, depois de `toDateTimeString()`, acrescente
  `public function toIso8601String() { return $this->format('Y-m-d\TH:i:sP'); }`.

Em `scripts/teste-php/reenvio.php`, logo depois de `require __DIR__ . '/stubs.php';`:

```php
require __DIR__ . '/stubs-reenvio.php';
```

e, nos `use`, acrescente `use App\Events\Entregas\PedidoSemMotoboy;`, `use Illuminate\Support\Facades\Log;` e
`use Teste\Socket;`. Na função `reiniciar()`, acrescente ao fim:

```php
    Socket::$transmitidos = [];
    Socket::$falhar       = false;
    Log::$linhas          = [];
```

- [ ] **Step 2: escrever os testes**

Em `scripts/teste-php/reenvio.php`, antes de `resumo();`:

```php
function avisosACentral(): array
{
    return array_map(fn ($t) => $t['quando']->format('H:i:s'), Socket::$transmitidos);
}

echo '== Aviso à central: ninguém aceita' . PHP_EOL;
$p              = pedido('PED-S1', '12:00:30');
$p->internal_id = '4821';
reiniciar([$p], [motoboy('Motoca', 1200)]);
rodarMinutos('12:01:00', 30);
$h = avisosACentral();
confere(count($h) === 1, 'um aviso só em 30 min (' . implode(', ', $h) . ')');
confere(($h[0] ?? '') >= '12:12:00' && ($h[0] ?? '') < '12:13:00', 'sai uns 12 min depois do despacho');
$evento = Socket::$transmitidos[0]['evento'] ?? null;
confere($evento instanceof PedidoSemMotoboy, 'o evento é o PedidoSemMotoboy');
confere($evento?->broadcastOn()[0]->name === 'company.empresa', 'no canal company.<uuid da empresa>');
confere($evento?->broadcastAs() === 'entregas.pedido_sem_motoboy', 'nome entregas.pedido_sem_motoboy (o console espera este)');
$dados = $evento?->broadcastWith() ?? [];
confere(($dados['event'] ?? null) === 'entregas.pedido_sem_motoboy', 'event no corpo da mensagem');
confere(($dados['data'] ?? null) === ['id' => 'PED-S1', 'uuid' => 'uuid-PED-S1', 'numero' => '4821', 'minutos' => 12], 'dados: public_id, uuid, número e minutos (' . json_encode($dados['data'] ?? null) . ')');

echo '== Aviso à central: sem número interno' . PHP_EOL;
reiniciar([pedido('PED-S2', '12:00:30')], [motoboy('Motoca', 1200)]);
rodarMinutos('12:01:00', 15);
confere((Socket::$transmitidos[0]['evento'] ?? null)?->broadcastWith()['data']['numero'] === 'PED-S2', 'número = public_id');

echo '== Aviso à central: nenhum motoboy no raio o tempo todo' . PHP_EOL;
reiniciar([pedido('PED-S3', '12:00:30')], [motoboy('Motoca', 20000)]);
rodarMinutos('12:01:00', 30);
confere(horarios('PED-S3') === [] && count(avisosACentral()) === 1, 'sem reenvio, mas a central é avisada (' . implode(', ', avisosACentral()) . ')');

echo '== Aviso à central: aceito às 12:06' . PHP_EOL;
$p = pedido('PED-S4', '12:00:30');
reiniciar([$p], [motoboy('Motoca', 1200)]);
rodarMinutos('12:01:00', 30, function ($agora) use ($p) {
    if ($agora >= utc('12:06:00')) {
        $p->driver_assigned_uuid = 'uuid-motoca';
    }
});
confere(avisosACentral() === [], 'pedido aceito não avisa a central');

echo '== Aviso à central: despachado de novo às 12:20' . PHP_EOL;
$p = pedido('PED-S5', '12:00:30');
reiniciar([$p], [motoboy('Motoca', 1200)]);
rodarMinutos('12:01:00', 40, function ($agora) use ($p) {
    if ($agora->format('H:i') === '12:20') {
        $p->dispatched_at = utc('12:20:00');
    }
});
$h = avisosACentral();
confere(count($h) === 2 && ($h[1] ?? '') >= '12:31:30' && ($h[1] ?? '') < '12:33:00', 'um aviso por despacho (' . implode(', ', $h) . ')');

echo '== Aviso à central: socket fora do ar até 12:13:30' . PHP_EOL;
reiniciar([pedido('PED-S6', '12:00:30')], [motoboy('Motoca', 1200)]);
rodarMinutos('12:01:00', 30, function ($agora) {
    Socket::$falhar = $agora < utc('12:13:30');
});
$h = avisosACentral();
confere(count($h) === 1 && ($h[0] ?? '') >= '12:14:00' && ($h[0] ?? '') < '12:15:00', 'tenta de novo no minuto seguinte e avisa uma vez (' . implode(', ', $h) . ')');
$falhasNoLog = array_filter(Log::$linhas, fn ($l) => $l[1] === '[entregas] aviso de pedido sem motoboy não chegou ao socket');
confere(count($falhasNoLog) === 2, 'cada falha fica no log (' . count($falhasNoLog) . ')');
confere(count(horarios('PED-S6')) === 3, 'os reenvios aos motoboys não param por causa do socket');
```

- [ ] **Step 3: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/reenvio.php`
Expected: `FALHA` nos casos "Aviso à central" (nenhuma transmissão), saída com 1.

- [ ] **Step 4: criar o evento**

Crie `api/app/Events/Entregas/PedidoSemMotoboy.php`:

```php
<?php

namespace App\Events\Entregas;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Entregas RestaurantePro: pedido aberto que ninguém aceitou em ~12 min do despacho (ReenviarPedidosAbertos). Vai no
 * canal da empresa para o console avisar a central com som e notificação fixa (serviço pedido-sem-motoboy do
 * Fleet-Ops), que atribui um motoboy à mão. Transmitido na hora (ShouldBroadcastNow): o comando roda no agendador, sem
 * passar pela fila.
 */
class PedidoSemMotoboy implements ShouldBroadcastNow
{
    public const NOME = 'entregas.pedido_sem_motoboy';

    public function __construct(
        public string $empresaUuid,
        public string $pedidoUuid,
        public string $pedidoPublicId,
        public ?string $numero,
        public int $minutos,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('company.' . $this->empresaUuid)];
    }

    public function broadcastAs(): string
    {
        return static::NOME;
    }

    public function broadcastWith(): array
    {
        return [
            'event'      => static::NOME,
            'created_at' => now()->toIso8601String(),
            'data'       => [
                'id'      => $this->pedidoPublicId,
                'uuid'    => $this->pedidoUuid,
                // número curto do pedido (internal_id; no iFood, o número do iFood) ou, sem ele, o public_id
                'numero'  => $this->numero ?: $this->pedidoPublicId,
                'minutos' => $this->minutos,
            ],
        ];
    }
}
```

- [ ] **Step 5: transmitir no reenvio**

Em `ReenviarPedidosAbertos.php`:

Nos `use`, acrescente:

```php
use App\Events\Entregas\PedidoSemMotoboy;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\Log;
```

Constante nova, depois de `FOLGA_SEGUNDOS`:

```php
    /** Minutos sem aceite, desde o despacho, para avisar a central (o momento do último reenvio). */
    public const AVISO_CENTRAL_MINUTOS = self::INTERVALO_MINUTOS * self::MAX_REENVIOS;
```

No `handle()`, a primeira linha dentro do `foreach ($pedidos as $pedido) {` passa a ser:

```php
            $this->avisarCentralSeParado($pedido, $agora);
```

Método novo, depois de `pedidosAbertos()`:

```php
    /**
     * Com AVISO_CENTRAL_MINUTOS sem aceite desde o despacho, avisa a central no socket (PedidoSemMotoboy), uma vez por
     * despacho, mesmo sem motoboy no raio. Falha no socket fica no log e tenta de novo no minuto seguinte.
     */
    protected function avisarCentralSeParado(Order $pedido, CarbonImmutable $agora): void
    {
        $parado = $agora->getTimestamp() - $pedido->dispatched_at->getTimestamp();
        if ($parado < self::AVISO_CENTRAL_MINUTOS * 60 - self::FOLGA_SEGUNDOS) {
            return;
        }

        $chave = 'entregas:pedido-sem-motoboy:' . $pedido->uuid . ':' . $pedido->dispatched_at->getTimestamp();
        if (Cache::get($chave)) {
            return;
        }

        $minutos = (int) round($parado / 60);

        try {
            broadcast(new PedidoSemMotoboy(
                (string) $pedido->company_uuid,
                (string) $pedido->uuid,
                (string) $pedido->public_id,
                $pedido->internal_id ? (string) $pedido->internal_id : null,
                $minutos
            ));
            Cache::put($chave, true, now()->addDay());
            $this->warn('Pedido ' . $pedido->public_id . ': ' . $minutos . ' min sem motoboy; central avisada.');
        } catch (\Throwable $e) {
            Log::warning('[entregas] aviso de pedido sem motoboy não chegou ao socket', ['pedido' => $pedido->public_id, 'erro' => $e->getMessage()]);
        }
    }
```

No docblock da classe, acrescente antes de "Ao atualizar o fleetops-api":

```
 * Aviso à central: com AVISO_CENTRAL_MINUTOS sem aceite desde o despacho (o momento do último reenvio), transmite
 * entregas.pedido_sem_motoboy (App\Events\Entregas\PedidoSemMotoboy) no canal da empresa, uma vez por despacho, mesmo
 * sem motoboy no raio. O console toca um som e mostra um aviso fixo até o pedido ganhar motoboy.
 *
```

- [ ] **Step 6: rodar os testes e a sintaxe**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/reenvio.php`
Expected: `FALHAS: 0`.

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Console/Commands/Entregas/ReenviarPedidosAbertos.php api/app/Events/Entregas/PedidoSemMotoboy.php`
Expected: `OK` nos dois.

Rode também os outros testes que carregam o `stubs.php`, para confirmar que os stubs novos não quebraram nada:

Run: `for t in avisos-push emails; do PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php | tail -1; done`
Expected: `FALHAS: 0` nos dois.

- [ ] **Step 7: commit**

```bash
git add api/app/Events/Entregas/PedidoSemMotoboy.php api/app/Console/Commands/Entregas/ReenviarPedidosAbertos.php scripts/teste-php/stubs.php scripts/teste-php/stubs-reenvio.php scripts/teste-php/reenvio.php
git commit -m "Reenvio de pedido aberto: avisa a central no socket com 12 min sem motoboy

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: util `escutar-canal-da-empresa` (e a lista Operações ao vivo usando ele)

**Files:**
- Create: `packages/fleetops/addon/utils/escutar-canal-da-empresa.js`
- Create: `scripts/teste-portal/escutar-canal-da-empresa.test.mjs`
- Modify: `packages/fleetops/addon/components/layout/fleet-ops-sidebar/operations-monitor.js`

O laço "inscreve no canal, consome, e se o canal fechar (outra tela fecha o mesmo canal) espera 5 s e se inscreve de
novo" já existe no mapa e na lista "Operações ao vivo". O serviço novo seria a terceira cópia; ele vira util, sem
imports do Ember (testável no Node). O mapa (`leaflet-live-map.js`) fica como está: o laço dele também cuida da
visibilidade da aba.

- [ ] **Step 1: escrever os testes**

Crie `scripts/teste-portal/escutar-canal-da-empresa.test.mjs`:

```js
// Consumidor próprio do canal company.<uuid> (packages/fleetops/addon/utils/escutar-canal-da-empresa.js).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import escutarCanalDaEmpresa from '../../packages/fleetops/addon/utils/escutar-canal-da-empresa.js';

const esperar = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// canal do SocketCluster em memória: o consumidor é um iterador assíncrono com return()
function canalFalso(nome) {
    const fila = [];
    let acordar = null;
    let fechado = false;
    const consumidor = {
        [Symbol.asyncIterator]() {
            return this;
        },
        async next() {
            while (!fila.length && !fechado) {
                await new Promise((resolve) => (acordar = resolve));
            }
            if (!fila.length) return { done: true, value: undefined };
            return { done: false, value: fila.shift() };
        },
        async return() {
            fechado = true;
            acordar?.();
            return { done: true, value: undefined };
        },
    };
    return {
        nome,
        consumidor,
        emitir(mensagem) {
            fila.push(mensagem);
            acordar?.();
        },
        fechar() {
            fechado = true;
            acordar?.();
        },
    };
}

function socketFalso() {
    const canais = [];
    return {
        canais,
        instance() {
            return {
                subscribe(nome) {
                    const canal = canalFalso(nome);
                    canais.push(canal);
                    return { createConsumer: () => canal.consumidor };
                },
            };
        },
    };
}

test('entrega as mensagens do canal da empresa', async () => {
    const socket = socketFalso();
    const recebidas = [];
    const escuta = escutarCanalDaEmpresa({ socket, currentUser: { companyId: 'emp-1' }, aoReceber: (m) => recebidas.push(m), esperaMs: 10 });
    await esperar(5);
    assert.equal(socket.canais[0].nome, 'company.emp-1');
    socket.canais[0].emitir({ event: 'order.updated' });
    await esperar(5);
    assert.deepEqual(recebidas, [{ event: 'order.updated' }]);
    escuta.parar();
});

test('canal fechado por outra tela: inscreve de novo depois da espera', async () => {
    const socket = socketFalso();
    const recebidas = [];
    const escuta = escutarCanalDaEmpresa({ socket, currentUser: { companyId: 'emp-1' }, aoReceber: (m) => recebidas.push(m.event), esperaMs: 10 });
    await esperar(5);
    socket.canais[0].fechar();
    await esperar(30);
    assert.equal(socket.canais.length, 2);
    socket.canais[1].emitir({ event: 'entregas.motoboy_online' });
    await esperar(5);
    assert.deepEqual(recebidas, ['entregas.motoboy_online']);
    escuta.parar();
});

test('sem empresa ainda: espera e tenta de novo', async () => {
    const socket = socketFalso();
    const usuario = { companyId: null };
    const escuta = escutarCanalDaEmpresa({ socket, currentUser: usuario, aoReceber: () => {}, esperaMs: 10 });
    await esperar(5);
    assert.equal(socket.canais.length, 0);
    usuario.companyId = 'emp-2';
    await esperar(30);
    assert.equal(socket.canais[0]?.nome, 'company.emp-2');
    escuta.parar();
});

test('parar: fecha o consumidor e não entrega mais nada', async () => {
    const socket = socketFalso();
    const recebidas = [];
    const escuta = escutarCanalDaEmpresa({ socket, currentUser: { companyId: 'emp-1' }, aoReceber: (m) => recebidas.push(m), esperaMs: 10 });
    await esperar(5);
    escuta.parar();
    socket.canais[0].emitir({ event: 'order.updated' });
    await esperar(30);
    assert.deepEqual(recebidas, []);
    assert.equal(socket.canais.length, 1);
});

test('erro no aoReceber não derruba a escuta', async () => {
    const socket = socketFalso();
    const recebidas = [];
    const erros = [];
    const escuta = escutarCanalDaEmpresa({
        socket,
        currentUser: { companyId: 'emp-1' },
        aoReceber: (m) => {
            if (m.event === 'quebra') throw new Error('falhou');
            recebidas.push(m.event);
        },
        aoFalhar: (erro) => erros.push(erro.message),
        esperaMs: 10,
    });
    await esperar(5);
    socket.canais[0].emitir({ event: 'quebra' });
    socket.canais[0].emitir({ event: 'order.updated' });
    await esperar(5);
    assert.deepEqual(recebidas, ['order.updated']);
    assert.deepEqual(erros, ['falhou']);
    escuta.parar();
});
```

- [ ] **Step 2: rodar e ver falhar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/escutar-canal-da-empresa.test.mjs`
Expected: FAIL com "Cannot find module ... escutar-canal-da-empresa.js".

- [ ] **Step 3: implementar**

Crie `packages/fleetops/addon/utils/escutar-canal-da-empresa.js`:

```js
/**
 * Entregas RestaurantePro: escuta o canal `company.<uuid>` do socket com um consumidor próprio. Outras telas usam o
 * mesmo canal, e desinscrever derrubaria a delas; por isso cada uma consome sem fechar o canal. Se o canal for fechado
 * por outra tela (o order-socket-events fecha ao sair de Pedidos) ou cair, espera `esperaMs` e se inscreve de novo.
 * Sem empresa no usuário (login ainda carregando), espera e tenta de novo.
 *
 * Sem imports do Ember, para testar no Node (scripts/teste-portal/escutar-canal-da-empresa.test.mjs).
 *
 * @param {object} opcoes
 * @param {object} opcoes.socket serviço socket do ember-core (instance() devolve o cliente SocketCluster)
 * @param {object} opcoes.currentUser serviço current-user (companyId)
 * @param {(mensagem: object) => void} opcoes.aoReceber chamado a cada mensagem do canal
 * @param {(erro: Error) => void} [opcoes.aoFalhar] erros do socket ou do aoReceber (a escuta continua)
 * @param {number} [opcoes.esperaMs] espera antes de se inscrever de novo
 * @returns {{ parar: () => void }}
 */
export default function escutarCanalDaEmpresa({ socket, currentUser, aoReceber, aoFalhar = () => {}, esperaMs = 5000 }) {
    let ativo = true;
    let consumidor = null;
    let espera = null;

    (async () => {
        while (ativo) {
            try {
                const empresa = currentUser?.companyId;
                if (empresa) {
                    consumidor = socket.instance().subscribe(`company.${empresa}`).createConsumer();
                    for await (const mensagem of consumidor) {
                        if (!ativo) break;
                        try {
                            aoReceber(mensagem);
                        } catch (erro) {
                            aoFalhar(erro);
                        }
                    }
                }
            } catch (erro) {
                aoFalhar(erro);
            }
            if (ativo) {
                await new Promise((resolve) => {
                    espera = setTimeout(resolve, esperaMs);
                });
            }
        }
    })();

    return {
        parar() {
            ativo = false;
            clearTimeout(espera);
            try {
                consumidor?.return();
            } catch (erro) {
                aoFalhar(erro);
            }
            consumidor = null;
        },
    };
}
```

- [ ] **Step 4: rodar e ver passar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/escutar-canal-da-empresa.test.mjs`
Expected: `# pass 5`, `# fail 0`.

- [ ] **Step 5: a lista "Operações ao vivo" passa a usar o util**

Em `packages/fleetops/addon/components/layout/fleet-ops-sidebar/operations-monitor.js`:

Acrescente o import, depois do import de `aplicarOnlineDoMotoboy`:

```js
import escutarCanalDaEmpresa from '../../../utils/escutar-canal-da-empresa';
```

Substitua os métodos `escutarSocketDoOnline()` e `pararSocketDoOnline()` (com o docblock acima deles) por:

```js
    /**
     * Entregas: o online do motoboy muda na lista assim que ele liga ou desliga no app, como o capacete do mapa
     * (util escutar-canal-da-empresa: consumidor próprio, que volta a se inscrever se outra tela fechar o canal).
     */
    escutarSocketDoOnline() {
        this._canalDaEmpresa ??= escutarCanalDaEmpresa({
            socket: this.socket,
            currentUser: this.currentUser,
            aoReceber: (mensagem) => {
                if (mensagem?.event === EVENTO_ONLINE_DO_MOTOBOY) {
                    aplicarOnlineDoMotoboy(this.store, mensagem.data);
                }
            },
            aoFalhar: (err) => debug('Socket do online dos motoboys: ' + err?.message),
        });
    }

    pararSocketDoOnline() {
        this._canalDaEmpresa?.parar();
        this._canalDaEmpresa = null;
    }
```

O constructor continua chamando `this.escutarSocketDoOnline()` e o `registerDestructor` continua chamando
`this.pararSocketDoOnline()`.

- [ ] **Step 6: conferir o parse do JS**

Run:
```bash
P=$(ls -d console/node_modules/.pnpm/@babel+parser@7* | head -1)/node_modules/@babel/parser
for f in packages/fleetops/addon/utils/escutar-canal-da-empresa.js packages/fleetops/addon/components/layout/fleet-ops-sidebar/operations-monitor.js; do node -e "require('./$P').parse(require('fs').readFileSync('$f','utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties','classPrivateMethods']});console.log('ok $f')"; done
```
Expected: `ok` nos dois.

- [ ] **Step 7: commit**

```bash
git add packages/fleetops/addon/utils/escutar-canal-da-empresa.js scripts/teste-portal/escutar-canal-da-empresa.test.mjs packages/fleetops/addon/components/layout/fleet-ops-sidebar/operations-monitor.js
git commit -m "Fleet-Ops: util para escutar o canal da empresa no socket, usado pela lista Operações ao vivo

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: util `pedido-sem-motoboy` (funções puras)

**Files:**
- Create: `packages/fleetops/addon/utils/pedido-sem-motoboy.js`
- Create: `scripts/teste-portal/pedido-sem-motoboy.test.mjs`

Os eventos do Fleet-Ops que tiram o pedido de "sem motoboy" chegam no mesmo canal: `order.driver_assigned`,
`order.started`, `order.canceled`, `order.completed` e `order.failed` (`ResourceLifecycleEvent::broadcastAs` =
`order.<eventName>`). O `data.id` deles é o `public_id`, mas numa chamada interna do console pode vir o `uuid`; por
isso o util devolve todos os ids que vierem.

- [ ] **Step 1: escrever os testes**

Crie `scripts/teste-portal/pedido-sem-motoboy.test.mjs`:

```js
// Aviso "sem motoboy" do console (packages/fleetops/addon/utils/pedido-sem-motoboy.js).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { avisoSemMotoboy, pedidosResolvidos, EVENTO_PEDIDO_SEM_MOTOBOY } from '../../packages/fleetops/addon/utils/pedido-sem-motoboy.js';

test('lê o aviso do evento entregas.pedido_sem_motoboy', () => {
    assert.equal(EVENTO_PEDIDO_SEM_MOTOBOY, 'entregas.pedido_sem_motoboy');
    assert.deepEqual(avisoSemMotoboy({ event: 'entregas.pedido_sem_motoboy', data: { id: 'order_a', uuid: 'u-a', numero: '4821', minutos: 12 } }), {
        id: 'order_a',
        uuid: 'u-a',
        numero: '4821',
        minutos: 12,
    });
});

test('aviso sem número ou minutos: usa o id e 0', () => {
    assert.deepEqual(avisoSemMotoboy({ event: 'entregas.pedido_sem_motoboy', data: { id: 'order_a' } }), { id: 'order_a', uuid: null, numero: 'order_a', minutos: 0 });
});

test('outros eventos ou aviso sem id não são aviso', () => {
    assert.equal(avisoSemMotoboy({ event: 'order.updated', data: { id: 'order_a' } }), null);
    assert.equal(avisoSemMotoboy({ event: 'entregas.pedido_sem_motoboy', data: {} }), null);
    assert.equal(avisoSemMotoboy(null), null);
});

test('eventos que resolvem o pedido devolvem os ids dele', () => {
    for (const event of ['order.driver_assigned', 'order.started', 'order.canceled', 'order.completed', 'order.failed']) {
        assert.deepEqual(pedidosResolvidos({ event, data: { id: 'order_a' } }), ['order_a'], event);
    }
    assert.deepEqual(pedidosResolvidos({ event: 'order.canceled', data: { id: 'u-a', public_id: 'order_a', uuid: 'u-a' } }), ['u-a', 'order_a']);
});

test('outros eventos não resolvem nada', () => {
    assert.deepEqual(pedidosResolvidos({ event: 'order.updated', data: { id: 'order_a' } }), []);
    assert.deepEqual(pedidosResolvidos({ event: 'order.started', data: {} }), []);
    assert.deepEqual(pedidosResolvidos(undefined), []);
});
```

- [ ] **Step 2: rodar e ver falhar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/pedido-sem-motoboy.test.mjs`
Expected: FAIL com "Cannot find module".

- [ ] **Step 3: implementar**

Crie `packages/fleetops/addon/utils/pedido-sem-motoboy.js`:

```js
/**
 * Entregas RestaurantePro: aviso à central de pedido aberto sem motoboy (serviço pedido-sem-motoboy).
 *
 * O evento vem do reenvio de pedidos abertos (App\Events\Entregas\PedidoSemMotoboy, ~12 min sem aceite), com
 * `data = {id: public_id, uuid, numero, minutos}`. O aviso some quando chega, no mesmo canal, um evento do Fleet-Ops que
 * tira o pedido dessa situação.
 */
export const EVENTO_PEDIDO_SEM_MOTOBOY = 'entregas.pedido_sem_motoboy';

/** Eventos do Fleet-Ops (order.<eventName>) que encerram o aviso: o pedido ganhou motoboy ou saiu do ar. */
const EVENTOS_QUE_RESOLVEM = new Set(['order.driver_assigned', 'order.started', 'order.canceled', 'order.completed', 'order.failed']);

/**
 * @returns {{id: string, uuid: ?string, numero: string, minutos: number} | null}
 */
export function avisoSemMotoboy(mensagem) {
    if (mensagem?.event !== EVENTO_PEDIDO_SEM_MOTOBOY) return null;

    const dados = mensagem.data ?? {};
    if (typeof dados.id !== 'string' || !dados.id) return null;

    return {
        id: dados.id,
        uuid: typeof dados.uuid === 'string' && dados.uuid ? dados.uuid : null,
        numero: dados.numero ? String(dados.numero) : dados.id,
        minutos: Number(dados.minutos) || 0,
    };
}

/**
 * Ids (public_id e/ou uuid) do pedido cujo aviso deve sumir. O data.id é o public_id, mas numa chamada interna do
 * console pode vir o uuid: devolve todos os que vierem.
 *
 * @returns {string[]}
 */
export function pedidosResolvidos(mensagem) {
    if (!EVENTOS_QUE_RESOLVEM.has(mensagem?.event)) return [];

    const dados = mensagem.data ?? {};

    return [...new Set([dados.id, dados.public_id, dados.uuid].filter((id) => typeof id === 'string' && id))];
}
```

- [ ] **Step 4: rodar e ver passar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/pedido-sem-motoboy.test.mjs`
Expected: `# pass 5`, `# fail 0`.

- [ ] **Step 5: commit**

```bash
git add packages/fleetops/addon/utils/pedido-sem-motoboy.js scripts/teste-portal/pedido-sem-motoboy.test.mjs
git commit -m "Fleet-Ops: funções do aviso de pedido sem motoboy

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: som, serviço, tradução e início no Fleet-Ops

**Files:**
- Create: `packages/fleetops/addon/utils/som-de-alerta.js`
- Create: `packages/fleetops/addon/services/pedido-sem-motoboy.js`
- Create: `packages/fleetops/app/services/pedido-sem-motoboy.js`
- Modify: `packages/fleetops/addon/routes/application.js`
- Modify: `packages/fleetops/translations/pt-br.yaml`, `packages/fleetops/translations/en-us.yaml`

O serviço não tem teste automatizado (depende do Ember); a lógica testável já está nos utils das tasks 3 e 4. A
verificação dele é manual, na task 6.

- [ ] **Step 1: som de alerta**

Crie `packages/fleetops/addon/utils/som-de-alerta.js` (mesmo método do `customer-portal/addon/utils/som-de-mensagem.js`,
com três toques mais insistentes):

```js
// Entregas: o aviso sonoro de pedido sem motoboy no console (serviço pedido-sem-motoboy). Três toques curtos gerados
// pelo Web Audio, sem arquivo de som. O navegador só libera o som depois de um clique ou tecla na página: o serviço
// chama prepararSomDeAlerta() a cada gesto, e a partir daí o aviso toca até com a aba em segundo plano.

let contexto = null;

function obterContexto() {
    const Contexto = globalThis.AudioContext ?? globalThis.webkitAudioContext;

    if (!Contexto) {
        return null;
    }

    if (!contexto) {
        try {
            contexto = new Contexto();
        } catch {
            return null;
        }
    }

    return contexto;
}

/** Libera o som (chamado num clique ou tecla do usuário). */
export function prepararSomDeAlerta() {
    const audio = obterContexto();

    if (audio?.state === 'suspended') {
        audio.resume().catch(() => {});
    }
}

/** Três toques (mi, mi, lá agudos). Sem som liberado ou sem Web Audio, não faz nada. */
export function tocarSomDeAlerta() {
    const audio = obterContexto();

    if (!audio) {
        return;
    }

    if (audio.state === 'suspended') {
        audio.resume().catch(() => {});
    }

    try {
        const agora = audio.currentTime;

        [
            [1318.5, 0],
            [1318.5, 0.22],
            [1760, 0.44],
        ].forEach(([frequencia, atraso]) => {
            const oscilador = audio.createOscillator();
            const volume = audio.createGain();

            oscilador.type = 'square';
            oscilador.frequency.value = frequencia;
            volume.gain.setValueAtTime(0.0001, agora + atraso);
            volume.gain.exponentialRampToValueAtTime(0.25, agora + atraso + 0.02);
            volume.gain.exponentialRampToValueAtTime(0.0001, agora + atraso + 0.18);
            oscilador.connect(volume);
            volume.connect(audio.destination);
            oscilador.start(agora + atraso);
            oscilador.stop(agora + atraso + 0.2);
        });
    } catch {
        // sem som: a notificação fixa continua avisando
    }
}
```

- [ ] **Step 2: serviço**

Crie `packages/fleetops/addon/services/pedido-sem-motoboy.js`:

```js
import Service, { inject as service } from '@ember/service';
import { debug } from '@ember/debug';
import escutarCanalDaEmpresa from '../utils/escutar-canal-da-empresa';
import { avisoSemMotoboy, pedidosResolvidos } from '../utils/pedido-sem-motoboy';
import { prepararSomDeAlerta, tocarSomDeAlerta } from '../utils/som-de-alerta';

const ROTA_DO_PEDIDO = 'console.fleet-ops.operations.orders.index.details';

/**
 * Entregas RestaurantePro: avisa a central quando um pedido aberto passa ~12 min sem motoboy (evento
 * entregas.pedido_sem_motoboy do ReenviarPedidosAbertos). Toca um som e mostra uma notificação fixa; o clique abre o
 * pedido. Ela some quando o pedido ganha motoboy, é iniciado, cancelado, concluído ou falha (utils/pedido-sem-motoboy).
 *
 * Iniciado na rota raiz do Fleet-Ops (routes/application.js) e ativo daí em diante, em qualquer tela do console.
 * Evento perdido (console fechado na hora) não volta: o aviso é só para quem está com o console aberto.
 */
export default class PedidoSemMotoboyService extends Service {
    @service socket;
    @service currentUser;
    @service notifications;
    @service intl;
    @service hostRouter;

    /** public_id e uuid do pedido → { publicId, uuid, notificacao } */
    avisos = new Map();
    canal = null;

    iniciar() {
        if (this.canal) return;

        this.canal = escutarCanalDaEmpresa({
            socket: this.socket,
            currentUser: this.currentUser,
            aoReceber: (mensagem) => this.#receber(mensagem),
            aoFalhar: (err) => debug('Pedido sem motoboy: ' + err?.message),
        });

        if (typeof document !== 'undefined') {
            this._liberarSom = () => prepararSomDeAlerta();
            document.addEventListener('pointerdown', this._liberarSom, true);
            document.addEventListener('keydown', this._liberarSom, true);
        }
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this.canal?.parar();
        this.canal = null;

        if (this._liberarSom && typeof document !== 'undefined') {
            document.removeEventListener('pointerdown', this._liberarSom, true);
            document.removeEventListener('keydown', this._liberarSom, true);
        }
    }

    #receber(mensagem) {
        const aviso = avisoSemMotoboy(mensagem);
        if (aviso) {
            this.#mostrar(aviso);
        }

        pedidosResolvidos(mensagem).forEach((id) => this.#fechar(id));
    }

    #mostrar({ id, uuid, numero, minutos }) {
        const atual = this.avisos.get(id);
        if (atual && !atual.notificacao?.dismiss) return;

        tocarSomDeAlerta();

        const entrada = { publicId: id, uuid, notificacao: null };
        entrada.notificacao = this.notifications.warning(this.intl.t('fleet-ops.ui.pedido-sem-motoboy.aviso', { numero, minutos }), {
            autoClear: false,
            onClick: () => {
                this.hostRouter.transitionTo(ROTA_DO_PEDIDO, id);
                this.#fechar(id);
            },
        });

        this.avisos.set(id, entrada);
        if (uuid) {
            this.avisos.set(uuid, entrada);
        }
    }

    #fechar(id) {
        const entrada = this.avisos.get(id);
        if (!entrada) return;

        this.avisos.delete(entrada.publicId);
        if (entrada.uuid) {
            this.avisos.delete(entrada.uuid);
        }
        this.notifications.removeNotification(entrada.notificacao);
    }
}
```

Crie `packages/fleetops/app/services/pedido-sem-motoboy.js`:

```js
export { default } from '@fleetbase/fleetops-engine/services/pedido-sem-motoboy';
```

- [ ] **Step 3: iniciar ao entrar no Fleet-Ops**

Em `packages/fleetops/addon/routes/application.js`, acrescente o serviço depois de `@service mapSettings;`:

```js
    @service pedidoSemMotoboy;
```

e, no `beforeModel`, logo depois do bloco `if (this.abilities.cannot('fleet-ops see extension')) { ... }`:

```js
        // Entregas: aviso de pedido aberto sem motoboy (serviço pedido-sem-motoboy); ativo daí em diante
        this.pedidoSemMotoboy.iniciar();
```

- [ ] **Step 4: tradução**

Em `packages/fleetops/translations/pt-br.yaml`, logo antes da linha `    order-form:` (filha de `  ui:`, linha ~207):

```yaml
    pedido-sem-motoboy:
      aviso: 'Pedido {numero} está há {minutos} min sem motoboy. Clique para abrir e atribuir.'
```

Em `packages/fleetops/translations/en-us.yaml`, logo antes da linha `    order-form:` (linha ~7979; nesse arquivo as
filhas usam 8 espaços):

```yaml
    pedido-sem-motoboy:
        aviso: 'Order {numero} has had no driver for {minutos} min. Click to open and assign.'

```

- [ ] **Step 5: validações**

Run: `node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo exit=$?`
Expected: `exit=0`.

Run:
```bash
P=$(ls -d console/node_modules/.pnpm/@babel+parser@7* | head -1)/node_modules/@babel/parser
for f in packages/fleetops/addon/utils/som-de-alerta.js packages/fleetops/addon/services/pedido-sem-motoboy.js packages/fleetops/app/services/pedido-sem-motoboy.js packages/fleetops/addon/routes/application.js; do node -e "require('./$P').parse(require('fs').readFileSync('$f','utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties','classPrivateMethods']});console.log('ok $f')"; done
```
Expected: `ok` nos quatro.

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs 2>&1 | grep -E "^# (pass|fail)"`
Expected: `# fail 0`.

- [ ] **Step 6: commit**

```bash
git add packages/fleetops/addon/utils/som-de-alerta.js packages/fleetops/addon/services/pedido-sem-motoboy.js packages/fleetops/app/services/pedido-sem-motoboy.js packages/fleetops/addon/routes/application.js packages/fleetops/translations/pt-br.yaml packages/fleetops/translations/en-us.yaml
git commit -m "Console: som e aviso fixo à central quando um pedido aberto fica sem motoboy

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: verificação no console e documentação

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: build local do console**

Run: `cd console && DISABLE_RUNTIME_CONFIG=false pnpm build --environment production` (≈10 min; `run_in_background`).
Expected: termina sem erro.

- [ ] **Step 2: teste manual com evento injetado**

1. Grave `console/dist/fleetbase.config.json` com `API_HOST=https://entregas-api.restaurantepro.com.br` (ver
   "Build do console" no `CLAUDE.md`) e sirva com `npx serve -s dist -l 4200`.
2. Faça login nessa aba, abra Fleet-Ops → Pedidos e clique uma vez na página (libera o som).
3. O socket de produção recusa o `localhost`, então o evento é injetado no canal. No console do navegador (ou pelo
   `javascript_tool` do `claude-in-chrome`), ache a instância do app e use o serviço do socket:
   ```js
   const app = Object.values(window).find((v) => v?.__container__ && v.lookup);
   const socket = app.lookup('service:socket');
   const empresa = app.lookup('service:current-user').companyId;
   socket.instance()._channelDataDemux.write(`company.${empresa}`, { event: 'entregas.pedido_sem_motoboy', data: { id: 'order_teste', uuid: 'u-teste', numero: '9999', minutos: 12 } });
   ```
   Expected: três toques e a notificação amarela "Pedido 9999 está há 12 min sem motoboy. Clique para abrir e
   atribuir." que não some sozinha.
4. Injete `{ event: 'order.driver_assigned', data: { id: 'order_teste' } }` no mesmo canal.
   Expected: a notificação some.
5. Injete o aviso de novo e clique nele. Expected: navega para o detalhe de `order_teste` (que não existe: o console
   mostra o erro de pedido não encontrado, o que confirma a navegação) e a notificação some.
6. Confira que a lista "Operações ao vivo" continua reagindo ao `entregas.motoboy_online` (injete
   `{ event: 'entregas.motoboy_online', data: { uuid: '<uuid de um motoboy da lista>', online: true } }`).

- [ ] **Step 3: documentar no `CLAUDE.md`**

Na seção "App do motoboy (Navigator próprio)", substitua o parágrafo **Reenvio de pedido aberto** por:

```markdown
- **Reenvio de pedido aberto:** o `fleetops:dispatch-adhoc` (agendado pelo Fleet-Ops a cada minuto) roda a nossa `api/app/Console/Commands/Entregas/ReenviarPedidosAbertos.php`, trocada no `AppServiceProvider`. O original nunca achava pedido (Carbon mutável) e, corrigido só nisso, avisaria em dobro por até 2 dias. Agora o aviso volta a cada 4 min, no máximo 3 vezes, para os motoboys livres perto da coleta, com texto em pt-BR (`LembretePedidoAberto`). **O raio cresce a cada reenvio** (decisão de 2026-10-05, vale para todos os pedidos): o primeiro aviso (do despacho) vai até o raio de pedido aberto do Fleet-Ops (R); os reenvios, até 1,5R, 2R e 2R. **Com ~12 min sem aceite**, uma vez por despacho e mesmo sem motoboy no raio, o comando transmite `entregas.pedido_sem_motoboy` (`App\Events\Entregas\PedidoSemMotoboy`) no canal `company.<uuid>`; o console (serviço `pedido-sem-motoboy` do Fleet-Ops, iniciado na rota raiz do engine) toca três toques e mostra um aviso fixo que abre o pedido e some quando o pedido ganha motoboy, é iniciado, cancelado, concluído ou falha. Falha no socket fica no log do `entregas_scheduler` (`[entregas] aviso de pedido sem motoboy não chegou ao socket`). **Ao atualizar o fleetops-api, confira se os métodos herdados ainda existem** (lista no docblock da classe). Teste: `scripts/teste-php/reenvio.php`, `scripts/teste-portal/pedido-sem-motoboy.test.mjs` e `escutar-canal-da-empresa.test.mjs`.
```

E, na seção "Mapa ao vivo", no item da lista **Operações ao vivo**, troque "(consumidor próprio, em qualquer tela do
Fleet-Ops)" por "(util `escutar-canal-da-empresa.js`, consumidor próprio, em qualquer tela do Fleet-Ops)".

- [ ] **Step 4: commit**

```bash
git add CLAUDE.md
git commit -m "CLAUDE.md: raio crescente e aviso de pedido sem motoboy

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 5: avisar o Edgard sobre o deploy**

O deploy é dele (sem push automático): `git push` (com a autorização dele) e, na VPS,
`cd ~/entregas && bash deploy/atualizar.sh` (API + console). Depois, Ctrl+Shift+R no console. Para conferir em
produção: um pedido de teste na loja Terraço Pizza Bar sem ninguém aceitar deve gerar o aviso em ~12 min (avise antes:
os motoboys reais no raio recebem o alarme, agora num raio maior a cada reenvio).
