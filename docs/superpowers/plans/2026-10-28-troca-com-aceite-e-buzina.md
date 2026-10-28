# Troca com aceite e buzina na loja: plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A troca e a atribuição de motoboy antes da coleta viram uma oferta ao escolhido (o mesmo cartão de aceitar do
pedido novo; recusou → fila). O portal da loja toca uma buzina de moto quando um motoboy aceita um pedido dela.

**Architecture:** A `TrocaDoMotoboy` devolve o pedido à fila (sem motoboy, aberto, não iniciado, `dispatched`) e, dentro
da mesma `TravaDoPedido`, chama um método novo do `Distribuidor` que cria a distribuição com a primeira oferta ao escolhido;
dali em diante é o ciclo de sempre. A buzina é uma rota nova `loja/aceites` lida a cada 10 s por um serviço do portal
iniciado no `Portal::Topo`, que toca `/sons/buzina.mp3` a cada aceite novo.

**Tech Stack:** Laravel (api/app, PHP 8.2; testes php-wasm em `scripts/teste-php/`), Ember (packages/fleetops e
packages/customer-portal; testes `scripts/teste-portal/` com `node --test`), React Native (`entregas-navigator`, só um
texto do líder).

**Spec:** `docs/superpowers/specs/2026-10-28-troca-com-aceite-e-buzina-design.md`. Leia antes:
`api/app/Support/Entregas/Distribuicao/CLAUDE.md`, `api/app/Http/Controllers/Entregas/CLAUDE.md` (seção do líder) e
`packages/customer-portal/CLAUDE.md`.

**Como rodar os testes PHP** (sem PHP no Windows; o php-wasm já está instalado em `C:\tmp\php-wasm`), na raiz do repo, no
Git Bash:

```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/<arquivo>.php
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs <arquivos.php>
```

Cada teste termina com `FALHAS: <n>`; o comando sai com 1 se houver falha.

---

## Arquivos

| Arquivo | O quê |
|---|---|
| `api/app/Support/Entregas/Distribuicao/Distribuidor.php` | novo `oferecerAoEscolhidoSemTrava` + `linhaDoEscolhido` |
| `scripts/teste-php/troca-com-aceite.php` (novo) | o ciclo depois da oferta ao escolhido |
| `api/app/Support/Entregas/TrocaDoMotoboy.php` | devolve à fila + oferta; resposta com `oferta` |
| `scripts/teste-php/lider.php` | stubs novos + casos da troca com aceite |
| `api/app/Support/Entregas/AceitesDaLoja.php` (novo) | os aceites recentes da loja |
| `api/app/Http/Controllers/Entregas/PortalLojaController.php` | `aceites()` |
| `api/app/Providers/RouteServiceProvider.php` | rota `loja/aceites` + limitador `entregas-loja-aceites` |
| `scripts/teste-php/aceites-da-loja.php` (novo) | a consulta e a ligação da rota |
| `packages/fleetops/addon/utils/painel-inicial.js` | `avisoDaAtribuicao` |
| `packages/fleetops/addon/components/home/painel-inicial.js`, `utils/ao-vivo-da-central.js`, `utils/tela-de-pedidos-base.js` | o aviso depois do Atribuir/Trocar |
| `packages/fleetops/translations/{pt-br,en-us}.yaml` | textos |
| `scripts/teste-portal/painel-inicial.test.mjs` | `avisoDaAtribuicao` |
| `packages/customer-portal/addon/utils/buzina.js` (novo), `addon/services/entregas-buzina.js` (novo), `app/services/entregas-buzina.js` (novo) | a buzina |
| `packages/customer-portal/addon/components/portal/topo.js` | inicia e encerra a buzina |
| `scripts/teste-portal/buzina.test.mjs` (novo) | funções puras da buzina |
| `console/public/sons/buzina.mp3` (do Edgard), `console/ember-cli-build.js` | o áudio, fora do fingerprint |
| `entregas-navigator`: `src/utils/mapa-do-lider.ts`, `src/screens/MapaDoLiderScreen.tsx`, `translations/{pt,en}.json`, `scripts/testes/mapa-do-lider.teste.ts` | texto do líder (APK) |
| CLAUDE.md dos assuntos + `docs/historico.md` | documentação |

---

### Task 1: Oferta ao escolhido no Distribuidor

**Files:**
- Modify: `api/app/Support/Entregas/Distribuicao/Distribuidor.php`
- Test: `scripts/teste-php/troca-com-aceite.php` (novo)

- [ ] **Step 1: Escrever o teste que falha**

Crie `scripts/teste-php/troca-com-aceite.php`:

```php
<?php

// Oferta ao motoboy escolhido (troca ou atribuição pela central e pelo líder antes da coleta):
// Distribuidor::oferecerAoEscolhidoSemTrava e o ciclo depois dela, com e sem rodadas. O reset do pedido na
// TrocaDoMotoboy tem teste em lider.php. Spec: docs/superpowers/specs/2026-10-28-troca-com-aceite-e-buzina-design.md.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/troca-com-aceite.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Jobs\Entregas\AvancarOferta;
use App\Notifications\Entregas\OfertaDePedido;
use App\Support\Entregas\Distribuicao\Candidatos;
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

function ponto(array $p): object
{
    return new class($p) { public function __construct(private array $p) {} public function getLat() { return $this->p[0]; } public function getLng() { return $this->p[1]; } };
}

/** Motoboy livre a $km ao norte da coleta (0,009° de latitude ≈ 1 km); sem $km, sem posição. */
function motoboyA(string $id, string $nome, ?float $km): array
{
    $posicao = $km === null ? null : [-21.1700 + 0.009 * $km, -47.8100];
    $motoboy = new Driver(['uuid' => 'd-' . $id, 'public_id' => 'driver_' . $id, 'company_uuid' => 'empresa-1', 'name' => $nome, 'online' => true, 'location' => $posicao ? ponto($posicao) : null]);

    return ['motoboy' => $motoboy, 'posicao' => $posicao, 'distancia' => ($km ?? 0) * 1000];
}

/**
 * Pedido aberto (R = 6 km). $naBusca: os que a busca de candidatos acha (a busca falsa filtra pelo raio, como o
 * distanceSphere); $todos: os cadastrados (o escolhido pode estar fora da busca).
 */
function cenario(array $naBusca, array $todos, bool $rodadas = true): Distribuidor
{
    reiniciarFleetbase();
    reiniciarIfood();
    Relogio::$agora = '2026-10-28 10:00:00';
    Config::$valores['services.entregas.distribuicao']         = '1';
    Config::$valores['services.entregas.distribuicao_rodadas'] = $rodadas ? '1' : '';
    session(['company' => 'empresa-1']);
    $pedido          = new Order(['uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1', 'adhoc' => true, 'dispatched' => true, 'status' => 'dispatched']);
    $pedido->payload = (object) ['pickup' => (object) ['location' => ponto([-21.1700, -47.8100])], 'dropoff' => (object) ['location' => ponto([-21.1800, -47.8100])]];
    Order::$todos[]  = $pedido;
    Driver::$todos   = array_map(fn ($c) => $c['motoboy'], $todos);
    Candidatos::$buscarMotoboys = fn ($pedido, $gpsRecente, $raio = null) => array_values(array_filter($naBusca, fn ($c) => $c['distancia'] <= ($raio ?? 6000)));
    Candidatos::$buscarParadas  = fn () => [];
    $estimador = new class extends EstimadorDeTempo {
        public function matriz(array $pontos): array { $m = []; foreach ($pontos as $i => $a) { foreach ($pontos as $j => $b) { $m[$i][$j] = (float) Pontos::segundos($a, $b); } } return ['durations' => $m, 'aproximado' => true]; }
    };

    return new Distribuidor(new FilaDeCandidatos($estimador));
}

function pedidoDoCenario(): Order { return Order::$todos[0]; }
function ofertas(): array { return DB::table('entregas_ofertas')->orderBy('id')->get()->all(); }
function distribuicoes(): array { return DB::table('entregas_distribuicoes')->orderBy('id')->get()->all(); }
function avisos(): array { return array_map(fn ($a) => [$a[0], get_class($a[1])], Driver::$avisos); }

$ana   = motoboyA('ana', 'Ana', 2);
$bruno = motoboyA('bruno', 'Bruno', 4);
$zeca  = motoboyA('zeca', 'Zeca', 20); // além de 2R (12 km): nenhuma rodada o acharia

echo '== Em rodadas: a oferta vai primeiro ao escolhido, mesmo fora do raio' . PHP_EOL;
$dist   = cenario([$ana, $bruno], [$ana, $bruno, $zeca]);
$oferta = $dist->oferecerAoEscolhidoSemTrava(pedidoDoCenario(), $zeca['motoboy']);
$o      = ofertas()[0] ?? null;
confere($oferta !== null && $o?->id === $oferta->id && $o->motoboy_uuid === 'd-zeca' && $o->resposta === 'pendente', 'oferta pendente ao escolhido, devolvida ao chamador (' . json_encode($o) . ')');
confere($o->volta === 1 && $o->rodada === 1 && $o->raio_m === 6000 && $o->posicao === 1 && $o->vence_em === '2026-10-28 10:00:50', 'volta 1, rodada 1 (raio R), posição 1, 50 s');
confere($o->tempo_estimado_s > 0 && (bool) $o->aproximado === true, 'tempo até o cliente estimado pela posição dele (linha reta)');
$d = distribuicoes()[0];
confere(count(distribuicoes()) === 1 && $d->fase === 'ofertas' && $d->lista_aberta_em === null, 'distribuição nova em ofertas, com a lista fechada');
confere(array_column(json_decode($d->fila, true), 'public_id') === ['driver_zeca'], 'fila gravada para o painel: só o escolhido');
confere(avisos() === [['driver_zeca', OfertaDePedido::class]] && Driver::$avisos[0][1]->segundosDaOferta === 50, 'push "Oferta para você" de 50 s só ao escolhido');
confere((Fila::$jobs[0] ?? null) instanceof AvancarOferta && Fila::$jobs[0]->delay === 50, 'job AvancarOferta em 50 s');
confere(Distribuicoes::ofertaParaAceite('order-1', 'd-zeca')?->id === $oferta->id && Distribuicoes::ofertaParaAceite('order-1', 'd-ana') === null, 'só o escolhido pode aceitar (a lista está fechada)');
confere(logou('oferta ao escolhido enviada', 'info') && logsSem(['Zeca', 'Ana', '-21.1']), 'log da oferta ao escolhido, só com ids e números');

echo '== Recusou: a fila de sempre, e o anterior pode receber' . PHP_EOL;
Relogio::$agora = '2026-10-28 10:00:20';
// Ana faz o papel do motoboy de quem o pedido foi tirado: é candidata como qualquer um (decisão do Edgard)
confere($dist->recusar('order-1', $zeca['motoboy']) === true, 'o escolhido recusa');
confere(ofertas()[0]->resposta === 'recusada' && (ofertas()[1]->motoboy_uuid ?? null) === 'd-ana' && ofertas()[1]->volta === 1 && ofertas()[1]->rodada === 1, 'próxima oferta pela rodada 1: Ana, a mais perto');
confere(!in_array('d-zeca', array_column(array_slice(ofertas(), 1), 'motoboy_uuid'), true), 'o escolhido não volta na volta 1');

echo '== Venceu: a fila de sempre' . PHP_EOL;
$dist = cenario([$ana, $bruno], [$ana, $bruno]);
$dist->oferecerAoEscolhidoSemTrava(pedidoDoCenario(), $bruno['motoboy']);
Relogio::$agora = '2026-10-28 10:00:51';
(new AvancarOferta(1))->handle($dist);
confere(ofertas()[0]->resposta === 'vencida' && (ofertas()[1]->motoboy_uuid ?? null) === 'd-ana', 'o escolhido deixou vencer: Ana');

echo '== Distribuição em curso: redespachada' . PHP_EOL;
$dist = cenario([$ana, $bruno], [$ana, $bruno]);
$dist->iniciar(pedidoDoCenario());
confere(ofertas()[0]->motoboy_uuid === 'd-ana' && ofertas()[0]->resposta === 'pendente', 'antes: a distribuição oferecia a Ana');
$dist->oferecerAoEscolhidoSemTrava(pedidoDoCenario(), $bruno['motoboy']);
[$velha, $nova] = distribuicoes();
confere($velha->fase === 'encerrada' && $velha->motivo === 'redespachada' && ofertas()[0]->resposta === 'cancelada', 'a anterior encerra como redespachada e a oferta de Ana é cancelada');
confere($nova->fase === 'ofertas' && ofertas()[1]->distribuicao_id === $nova->id && ofertas()[1]->motoboy_uuid === 'd-bruno', 'a nova oferece a Bruno');

echo '== Escolhido sem posição' . PHP_EOL;
$semGps = motoboyA('sem', 'Sem GPS', null);
$dist   = cenario([$ana], [$ana, $semGps]);
$dist->oferecerAoEscolhidoSemTrava(pedidoDoCenario(), $semGps['motoboy']);
$o = ofertas()[0];
confere($o->motoboy_uuid === 'd-sem' && $o->tempo_estimado_s === 0 && (bool) $o->aproximado === true && avisos() === [['driver_sem', OfertaDePedido::class]], 'sem posição: oferta assim mesmo, tempo 0 aproximado');

echo '== Sem rodadas' . PHP_EOL;
$dist   = cenario([$ana, $bruno], [$ana, $bruno], false);
$oferta = $dist->oferecerAoEscolhidoSemTrava(pedidoDoCenario(), $bruno['motoboy']);
confere($oferta->vence_em === '2026-10-28 10:00:30' && $oferta->raio_m === null, 'oferta de 30 s, sem raio de rodada');
Relogio::$agora = '2026-10-28 10:00:10';
$dist->recusar('order-1', $bruno['motoboy']);
confere((ofertas()[1]->motoboy_uuid ?? null) === 'd-ana' && distribuicoes()[0]->fase === 'ofertas', 'recusou: a fila do ciclo sem rodadas (Ana)');

echo '== Distribuição desligada' . PHP_EOL;
$dist = cenario([$ana], [$ana, $bruno]);
Config::$valores['services.entregas.distribuicao'] = '';
confere($dist->oferecerAoEscolhidoSemTrava(pedidoDoCenario(), $bruno['motoboy']) === null && distribuicoes() === [] && avisos() === [], 'desligada: null, nada gravado, ninguém avisado');

echo '== Falha: abre a todos com o alarme geral' . PHP_EOL;
$dist = cenario([$ana, $bruno], [$ana, $bruno]);
Candidatos::$buscarParadas = fn () => throw new \RuntimeException('banco fora');
$oferta = $dist->oferecerAoEscolhidoSemTrava(pedidoDoCenario(), $bruno['motoboy']);
$d      = distribuicoes()[0];
confere($oferta === null && $d->fase === 'aberta' && $d->motivo === 'falha', 'a falha abre a distribuição (falha) e devolve null, sem lançar');
confere(array_column(avisos(), 1) === [OrderPing::class, OrderPing::class], 'alarme geral a todos no raio');
confere(logou('falha na oferta ao escolhido; aberta a todos', 'warning'), 'log da falha');

resumo(); // dos stubs: imprime "FALHAS: <n>"
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/troca-com-aceite.php`
Expected: erro fatal `Call to undefined method ...Distribuidor::oferecerAoEscolhidoSemTrava()`.

- [ ] **Step 3: Implementar**

Em `api/app/Support/Entregas/Distribuicao/Distribuidor.php`:

1. No docblock da classe, na linha da lista que fala de `encerrar e registrarAceite`, troque por:

```php
 * - encerrar, registrarAceite e oferecerAoEscolhidoSemTrava: sem a trava (rodam dentro de quem já a segura: o aceite no
 *   middleware, a TrocaDoMotoboy).
```

2. Logo depois do método `registrarAceite` (antes de `proximoPasso`), acrescente:

```php
    /**
     * Sem a trava (a TrocaDoMotoboy já a segura): a troca ou a atribuição pela central ou pelo líder antes da coleta.
     * Encerra a distribuição em curso do pedido (redespachada), cria outra e oferece primeiro ao motoboy escolhido (volta
     * 1, rodada 1, lista fechada), mesmo fora do raio, ocupado ou com o GPS velho: quem escolheu foi a central ou o líder.
     * Recusou ou venceu: o ciclo de sempre (com ou sem rodadas); o escolhido fica fora da volta 1 por já ter linha nela.
     * Nunca lança: uma falha abre a todos (falha) com o alarme geral. Devolve a oferta, ou null (desligada ou falha).
     */
    public function oferecerAoEscolhidoSemTrava(Order $pedido, Driver $motoboy): ?object
    {
        if (!Distribuicao::ligada()) {
            return null;
        }
        $distribuicao = null;
        try {
            $anterior = Distribuicoes::doPedido((string) $pedido->uuid);
            if ($anterior) {
                $this->encerrarSemTrava($anterior, Distribuicao::REDESPACHADA);
            }
            $distribuicao = Distribuicoes::criar($pedido);
            Log::info('[entregas] distribuição: iniciada', ['pedido' => $pedido->public_id, 'distribuicao' => $distribuicao->id, 'escolhido' => $motoboy->public_id]);
            $linha = $this->linhaDoEscolhido($pedido, $motoboy);
            Distribuicoes::gravarFila((int) $distribuicao->id, [$linha]);
            $raio   = Distribuicao::emRodadas() ? Distribuicao::raioDaRodada(Distribuicao::raioBase($pedido), 1) : null;
            $oferta = Distribuicoes::criarOferta($distribuicao, $linha, 1, 1, 1, $raio);
            try {
                AvancarOferta::agendar((int) $oferta->id);
            } catch (\Throwable $e) {
                // a fila fora do ar: a varredura vence a oferta (FOLGA_DA_VARREDURA_S depois do vence_em)
                Log::warning('[entregas] distribuição: job da oferta não entrou na fila', ['oferta' => $oferta->id, 'erro' => get_class($e)]);
            }
            try {
                $motoboy->notify(new OfertaDePedido($pedido, $linha['distancia_m'], Distribuicoes::data($oferta->vence_em), Distribuicao::segundosDaOferta()));
            } catch (\Throwable $e) {
                Log::warning('[entregas] distribuição: push da oferta falhou', ['oferta' => $oferta->id, 'erro' => get_class($e)]);
            }
            Log::info('[entregas] distribuição: oferta ao escolhido enviada', ['pedido' => $pedido->public_id, 'oferta' => $oferta->id, 'motoboy' => $motoboy->public_id, 'tempo_s' => $linha['tempo_s']]);

            return $oferta;
        } catch (\Throwable $erro) {
            if (!$distribuicao) {
                Log::warning('[entregas] distribuição: falha na oferta ao escolhido', ['pedido' => $pedido->public_id, 'erro' => get_class($erro)]);

                return null;
            }
            try {
                $this->abrirSemTrava($distribuicao, $pedido, Distribuicao::FALHA);
                Log::warning('[entregas] distribuição: falha na oferta ao escolhido; aberta a todos', ['pedido' => $pedido->public_id, 'distribuicao' => $distribuicao->id, 'erro' => get_class($erro)]);
            } catch (\Throwable $e) {
                $this->abrirPorFalha($distribuicao, $pedido->public_id, $erro);
            }

            return null;
        }
    }

    /**
     * A linha da fila do escolhido: com posição (e coleta com coordenada), o tempo até o cliente pela FilaDeCandidatos, com
     * as paradas dele e o encaixe do ciclo; sem posição, tempo 0 e aproximado (a oferta sai assim mesmo).
     */
    protected function linhaDoEscolhido(Order $pedido, Driver $motoboy): array
    {
        $posicao = Pontos::de($motoboy->location);
        $coleta  = Pontos::de($pedido->payload?->pickup?->location);
        if ($posicao && $coleta) {
            $uuid      = (string) $motoboy->uuid;
            $empresa   = (string) $pedido->company_uuid;
            $candidato = [
                'motoboy'       => $motoboy,
                'posicao'       => $posicao,
                'distancia'     => Pontos::metros($posicao, $coleta),
                'paradas'       => Candidatos::paradas($empresa, [$uuid])[$uuid] ?? [],
                'corridas_hoje' => Candidatos::corridasHoje($empresa, [$uuid])[$uuid] ?? 0,
            ];
            $atraso = Distribuicao::emRodadas() ? Distribuicao::ATRASO_MAXIMO_EM_RODADAS_S : Distribuicao::ATRASO_MAXIMO_S;
            $fila   = $this->fila->para($pedido, [$candidato], $atraso);
            if ($fila !== []) {
                return $fila[0];
            }
        }

        return [
            'motoboy_uuid'  => (string) $motoboy->uuid,
            'public_id'     => (string) $motoboy->public_id,
            'nome'          => (string) $motoboy->name,
            'tempo_s'       => 0,
            'encaixe'       => false,
            'aproximado'    => true,
            'livre'         => true,
            'distancia_m'   => 0,
            'corridas_hoje' => 0,
            'desempate'     => false,
        ];
    }
```

`Candidatos::paradas` lançar (banco) cai no `catch` de fora: é o caso "Falha" do teste.

- [ ] **Step 4: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/troca-com-aceite.php`
Expected: todas `PASSA`, `FALHAS: 0`.

Rode também os do ciclo (nada pode quebrar):

```bash
for t in distribuicao-ciclo distribuicao-rodadas distribuicao-fila barrar-aceite filtrar-pedidos-abertos avisos-push; do PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php | tail -1; done
```
Expected: `FALHAS: 0` em todos.

- [ ] **Step 5: Commit**

```bash
git add api/app/Support/Entregas/Distribuicao/Distribuidor.php scripts/teste-php/troca-com-aceite.php
git commit -m "Distribuição: oferta ao motoboy escolhido (troca e atribuição antes da coleta)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: A troca devolve o pedido à fila com a oferta ao escolhido

**Files:**
- Modify: `api/app/Support/Entregas/TrocaDoMotoboy.php`
- Test: `scripts/teste-php/lider.php`

- [ ] **Step 1: Preparar os stubs do lider.php**

Em `scripts/teste-php/lider.php`:

1. No stub do `Cache::lock` (namespace `Illuminate\Support\Facades`), marque quando a trava está tomada. Troque a classe
   `Cache` inteira por:

```php
    class Cache
    {
        public static bool $ocupada = false;
        public static array $travas = [];
        /** True enquanto a função da trava roda (para conferir o que acontece dentro dela). */
        public static bool $dentro = false;

        public static function lock(string $chave, int $validade)
        {
            return new class($chave) {
                public function __construct(private string $chave) {}

                public function block(int $espera, \Closure $fazer)
                {
                    if (Cache::$ocupada) {
                        throw new \Illuminate\Contracts\Cache\LockTimeoutException('ocupada');
                    }
                    Cache::$travas[] = $this->chave;
                    Cache::$dentro   = true;
                    try {
                        return $fazer();
                    } finally {
                        Cache::$dentro = false;
                    }
                }
            };
        }
    }
```

2. No stub `Order` (namespace `Fleetbase\FleetOps\Models`), acrescente as propriedades e o `save()`:

```php
        public $started = true;
        public $started_at = '2026-10-28 09:50:00';
        /** O que estava no pedido em cada save(): o reset da troca com aceite. */
        public array $salvos = [];

        public function save(): bool
        {
            $this->salvos[] = ['motoboy' => $this->driver_assigned_uuid, 'adhoc' => $this->adhoc, 'started' => $this->started, 'started_at' => $this->started_at, 'status' => $this->status];

            return true;
        }
```

3. Depois do bloco `namespace App\Support\Entregas { ... }` dos dublês (`PedidosNoMapa`, `MapaDoLider`), acrescente:

```php
namespace App\Support\Entregas\Distribuicao {
    // dublês: o Distribuidor tem teste próprio (troca-com-aceite.php)
    class Distribuicao
    {
        public static bool $ligada = false;
        public static function ligada(): bool { return static::$ligada; }
    }

    class Distribuidor
    {
        public static array $chamadas = [];
        public static ?object $oferta = null;

        public function oferecerAoEscolhidoSemTrava($pedido, $motoboy): ?object
        {
            static::$chamadas[] = ['pedido' => $pedido->uuid, 'motoboy' => $motoboy->uuid, 'dentro_da_trava' => \Illuminate\Support\Facades\Cache::$dentro, 'motoboy_no_pedido' => $pedido->driver_assigned_uuid, 'adhoc' => $pedido->adhoc];

            return static::$oferta;
        }
    }
}
```

4. No `namespace {` principal: acrescente aos `use` as linhas
   `use App\Support\Entregas\Distribuicao\Distribuicao;` e `use App\Support\Entregas\Distribuicao\Distribuidor;`;
   antes do `require '/repo/api/app/Support/Entregas/TrocaDoMotoboy.php';` acrescente
   `require '/repo/api/app/Support/Entregas/SituacaoDoMotoboy.php';`; e, junto de `function response()`, acrescente:

```php
    date_default_timezone_set('America/Sao_Paulo');
    function app($classe) { return new $classe(); }
```

5. Na função `pedido(...)`, antes do `return $p;`, acrescente:

```php
        $p->started              = $extra['started'] ?? !in_array($status, ['created', 'dispatched'], true);
        $p->started_at           = $p->started ? '2026-10-28 09:50:00' : null;
```

6. As respostas 200 passam a trazer `oferta`. Troque as duas comparações exatas:
   - `$resposta->dados === ['pedido' => ['id' => 'order_ifood', 'motoboy_uuid' => 'm-comum']]` por
     `$resposta->dados === ['pedido' => ['id' => 'order_ifood', 'motoboy_uuid' => 'm-comum'], 'oferta' => null]`
   - `$resposta->dados === ['pedido' => ['id' => 'order_ifood', 'motoboy_uuid' => 'm-novo']]` por
     `$resposta->dados === ['pedido' => ['id' => 'order_ifood', 'motoboy_uuid' => 'm-novo'], 'oferta' => null]`

   E no título da seção `echo '== LiderController@trocarMotoboy (TrocaDoMotoboy)' . PHP_EOL;` troque por
   `echo '== LiderController@trocarMotoboy (TrocaDoMotoboy), com a distribuição desligada: troca direta' . PHP_EOL;`.

- [ ] **Step 2: Escrever os casos novos (que falham)**

Em `scripts/teste-php/lider.php`, logo antes de `echo '== PedidoPassadoParaOutro' . PHP_EOL;`, acrescente:

```php
    echo '== Troca com aceite (distribuição ligada, antes da coleta)' . PHP_EOL;
    Distribuicao::$ligada        = true;
    Distribuidor::$chamadas      = [];
    Distribuidor::$oferta        = (object) ['id' => 7, 'vence_em' => '2026-10-28 10:00:50'];
    Driver::$todos[1]->avisos    = [];
    Driver::$todos[2]->avisos    = [];
    Order::$todos[]              = $aceito = pedido('o-aceito', 'started', 'm-comum', ['internal_id' => '5001']);
    Driver::$todos[1]->current_job_uuid = 'o-aceito';
    Cache::$travas = [];
    Log::$linhas   = [];
    $resposta      = $troca('order_aceito', 'driver_novo');
    confere($resposta->status === 200 && $resposta->dados === ['pedido' => ['id' => 'order_aceito', 'motoboy_uuid' => null], 'oferta' => ['motoboy' => 'driver_novo', 'nome' => 'Novo', 'vence_em' => '2026-10-28T10:00:50-03:00']],
        'aceito e não coletado: 200 com o pedido sem motoboy e a oferta ao escolhido (' . json_encode($resposta->dados) . ')');
    confere($aceito->salvos === [['motoboy' => null, 'adhoc' => true, 'started' => false, 'started_at' => null, 'status' => 'dispatched']] && $aceito->atribuicoes === [],
        'devolvido à fila num save só (sem motoboy, aberto, não iniciado, dispatched), sem assignDriver');
    confere(Distribuidor::$chamadas === [['pedido' => 'o-aceito', 'motoboy' => 'm-novo', 'dentro_da_trava' => true, 'motoboy_no_pedido' => null, 'adhoc' => true]],
        'a oferta ao escolhido sai dentro da trava, depois do reset');
    confere(Cache::$travas === ['entregas:pedido:o-aceito'] && Driver::$todos[1]->current_job_uuid === null, 'uma trava só; o pedido atual do anterior é limpo');
    confere((Driver::$todos[1]->avisos[0]->message ?? null) === 'Pedido #5001 passou para outro motoboy.' && Driver::$todos[2]->avisos === [],
        'o anterior é avisado; o escolhido não recebe este aviso (recebe a oferta do Distribuidor)');
    confere(Log::$linhas === [['info', '[entregas] troca do motoboy: oferta ao escolhido', ['pedido' => 'order_aceito', 'anterior' => 'driver_comum', 'novo' => 'driver_novo', 'lider' => 'u-lider', 'oferta' => 7]]],
        'log "troca do motoboy: oferta ao escolhido" só com ids');

    Order::$todos[]         = $semMotoboy = pedido('o-sem', 'dispatched', null, ['adhoc' => true]);
    Distribuidor::$chamadas = [];
    Log::$linhas            = [];
    $resposta               = $troca('order_sem', 'driver_comum');
    confere($resposta->status === 200 && ($resposta->dados['oferta']['motoboy'] ?? null) === 'driver_comum' && $semMotoboy->driver_assigned_uuid === null && count(Distribuidor::$chamadas) === 1,
        'Atribuir (pedido sem motoboy): oferta ao escolhido, o pedido segue sem motoboy');
    confere(Driver::$todos[1]->avisos === [Driver::$todos[1]->avisos[0]] && Log::$linhas[0][2]['anterior'] === null, 'sem anterior: ninguém mais é avisado; log com anterior nulo');

    Order::$todos[]         = $coletado = pedido('o-coletado', 'enroute', 'm-comum');
    Distribuidor::$chamadas = [];
    Log::$linhas            = [];
    $resposta               = $troca('order_coletado', 'driver_novo');
    confere($resposta->status === 200 && $resposta->dados['oferta'] === null && $coletado->driver_assigned_uuid === 'm-novo' && $coletado->salvos === [] && Distribuidor::$chamadas === [],
        'já coletado (enroute): troca direta, como antes');
    confere(Log::$linhas[0][1] === '[entregas] líder trocou o motoboy', 'já coletado: o log de sempre');

    Order::$todos[]         = $falhou = pedido('o-falhou', 'started', 'm-comum');
    Distribuidor::$oferta   = null;
    $resposta               = $troca('order_falhou', 'driver_novo');
    confere($resposta->status === 200 && $resposta->dados['oferta'] === null && $falhou->driver_assigned_uuid === null && $falhou->adhoc === true,
        'a oferta falhou (o Distribuidor abriu a todos): 200 sem oferta, o pedido continua na fila');
    Distribuicao::$ligada = false;
```

- [ ] **Step 3: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/lider.php`
Expected: FALHA nos casos novos e nos dois com `'oferta' => null` (a resposta ainda não traz `oferta`).

- [ ] **Step 4: Implementar**

Em `api/app/Support/Entregas/TrocaDoMotoboy.php`:

1. `use` novos (em ordem alfabética com os outros):

```php
use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Distribuidor;
```

2. Troque o docblock da classe por:

```php
/**
 * Entregas RestaurantePro: a troca do motoboy de um pedido, pelo líder na aba Mapa do app (LiderController@trocarMotoboy)
 * e pela central no console (PainelInicialController@atribuir: Atribuir e Trocar).
 *
 * Antes da coleta, com a distribuição ligada (decisão do Edgard de 2026-10-28, spec
 * docs/superpowers/specs/2026-10-28-troca-com-aceite-e-buzina-design.md): o escolhido precisa aceitar. Com a TravaDoPedido
 * (a mesma do aceite e do cancelamento), relê o pedido e o devolve à fila (sem motoboy, aberto, não iniciado,
 * `dispatched`, num save só: os observadores toleram, nada vai ao iFood e a atribuição do valor congelado é limpa) e,
 * ainda dentro da trava, o Distribuidor cria a distribuição com a primeira oferta ao escolhido (o cartão "Oferta para
 * você"); recusou ou venceu, segue a fila de sempre. Fora da trava haveria um instante com o pedido aberto e sem
 * distribuição, com o aceite livre a qualquer um.
 *
 * Depois da coleta (status de SituacaoDoMotoboy::STATUS_EM_ENTREGA) ou com a distribuição desligada: a troca direta do
 * console (o pedido fica com o status que tinha), que desliga o pedido aberto (adhoc falso: com ele ligado, o
 * HandleOrderDriverAssigned do Fleet-Ops não avisa o motoboy novo e o reenvio do pedido aberto continuaria) e chama o
 * assignDriver do Order em modo silencioso. O save dele dispara o OrderObserver do Fleet-Ops, que dispara o
 * OrderDriverAssigned uma vez só (o assignDriver sem o silencioso dispara o evento antes do save, e o observer de novo
 * depois: o motoboy novo receberia dois avisos). O OrderDriverAssigned leva o push "Novo pedido para você" ao motoboy novo
 * (AvisosDoMotoboy) e, no pedido iFood, o ObservadorDosPedidosIfood manda o assignDriver ao iFood.
 *
 * Nos dois casos, o motoboy anterior recebe o PedidoPassadoParaOutro.
 */
```

3. No `trocar(...)`, troque o trecho do `try` até o `return [200, ...]` (do `try {` da trava até o fim do método) por:

```php
        try {
            [$resultado, $anteriorUuid, $oferta] = TravaDoPedido::executar((string) $pedido->uuid, fn () => static::trocarComATrava($empresaUuid, (string) $pedido->uuid, $novo));
        } catch (LockTimeoutException) {
            return [409, ['errors' => ['Outra pessoa está mexendo neste pedido. Tente de novo.']]];
        }

        if ($resultado === 'sumiu') {
            return [404, ['errors' => ['Pedido não encontrado.']]];
        }
        if ($resultado === 'encerrado') {
            return [409, ['errors' => ['Este pedido já foi encerrado.']]];
        }
        if ($resultado === 'nao_despachado') {
            return [409, ['errors' => ['Este pedido ainda não foi despachado pela central.']]];
        }

        $atualizado = static::pedido($empresaUuid, (string) $pedido->uuid, PedidosNoMapa::RELACOES_DO_LIDER) ?? $pedido;
        if ($resultado === 'trocado') {
            $anterior = static::avisarAnterior($empresaUuid, $anteriorUuid, $atualizado);
            Log::info('[entregas] líder trocou o motoboy', [
                'pedido'   => $atualizado->public_id,
                'anterior' => $anterior,
                'novo'     => $novo->public_id,
                'lider'    => $liderUuid,
            ]);
        }
        if ($resultado === 'oferecido') {
            $anterior = static::avisarAnterior($empresaUuid, $anteriorUuid, $atualizado);
            Log::info('[entregas] troca do motoboy: oferta ao escolhido', [
                'pedido'   => $atualizado->public_id,
                'anterior' => $anterior,
                'novo'     => $novo->public_id,
                'lider'    => $liderUuid,
                'oferta'   => $oferta?->id,
            ]);
        }

        return [200, ['pedido' => PedidosNoMapa::umDoLider($atualizado), 'oferta' => static::ofertaNaResposta($oferta, $novo)]];
    }
```

4. Troque o método `trocarComATrava` inteiro por:

```php
    /**
     * Dentro da trava: relê o pedido (outro líder, o aceite ou o cancelamento podem ter mudado) e troca: antes da coleta,
     * com a distribuição ligada, devolve à fila e oferece ao escolhido; senão, a troca direta.
     *
     * @return array{0: string, 1: ?string, 2: ?object} resultado (sumiu, encerrado, nao_despachado, igual, trocado,
     *                                                   oferecido), o uuid do motoboy anterior e a oferta criada
     */
    protected static function trocarComATrava(string $empresaUuid, string $pedidoUuid, Driver $novo): array
    {
        $pedido = static::pedido($empresaUuid, $pedidoUuid);
        if (!$pedido) {
            return ['sumiu', null, null];
        }
        if (static::encerrado($pedido)) {
            return ['encerrado', null, null];
        }
        if (static::naoDespachado($pedido)) {
            return ['nao_despachado', null, null];
        }
        if ((string) $pedido->driver_assigned_uuid === (string) $novo->uuid) {
            return ['igual', null, null];
        }

        $anterior = $pedido->driver_assigned_uuid ?: null;
        if (static::vaiPorOferta($pedido)) {
            static::devolverAFila($pedido);
            static::limparPedidoAtualDoAnterior($empresaUuid, $anterior, $pedidoUuid);
            // sem a trava de novo (não é reentrante): o Distribuidor grava sem tomá-la; nunca lança
            $oferta = app(Distribuidor::class)->oferecerAoEscolhidoSemTrava($pedido, $novo);

            return ['oferecido', $anterior, $oferta];
        }

        $pedido->adhoc = false;
        $pedido->assignDriver($novo, true);
        static::limparPedidoAtualDoAnterior($empresaUuid, $anterior, $pedidoUuid);

        return ['trocado', $anterior, null];
    }

    /** O escolhido precisa aceitar: a distribuição ligada e o pedido ainda não saiu da loja. */
    protected static function vaiPorOferta($pedido): bool
    {
        return Distribuicao::ligada() && !in_array(strtolower((string) $pedido->status), SituacaoDoMotoboy::STATUS_EM_ENTREGA, true);
    }

    /**
     * O pedido volta a ser aberto, sem motoboy e não iniciado, num save só (com eventos: o socket, os caches e o valor
     * congelado ficam sabendo; nenhum OrderDriverAssigned sai com motoboy nulo). O dispatched_at fica: o startOrder do
     * próximo aceite exige o pedido despachado, e a linha do tempo ganha um segundo "iniciado".
     */
    protected static function devolverAFila($pedido): void
    {
        $pedido->driver_assigned_uuid = null;
        $pedido->adhoc                = true;
        $pedido->started              = false;
        $pedido->started_at           = null;
        $pedido->status               = 'dispatched';
        $pedido->save();
    }

    /** A oferta na resposta (console e líder mostram "Oferta enviada a Fulano"): o escolhido e quando vence (ISO, -03:00). */
    protected static function ofertaNaResposta(?object $oferta, Driver $novo): ?array
    {
        if (!$oferta) {
            return null;
        }

        return [
            'motoboy'  => (string) $novo->public_id,
            'nome'     => (string) $novo->name,
            'vence_em' => (new \DateTimeImmutable(substr((string) $oferta->vence_em, 0, 19)))->format('Y-m-d\TH:i:sP'),
        ];
    }
```

`SituacaoDoMotoboy` está no mesmo namespace (`App\Support\Entregas`): não precisa de `use`.

- [ ] **Step 5: Rodar e ver passar**

```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/lider.php
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/imports-dos-providers.php
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/TrocaDoMotoboy.php api/app/Support/Entregas/Distribuicao/Distribuidor.php
```
Expected: `FALHAS: 0` nos dois testes; sintaxe sem erro.

Procure outros testes que carregam a `TrocaDoMotoboy` e rode-os também:

```bash
grep -l "TrocaDoMotoboy" scripts/teste-php/*.php
```
(ex.: o teste do painel inicial). Se algum comparar a resposta exata do `trocar`, acrescente `'oferta' => null` como no
Step 1.6 e, se ele não definir `Distribuicao`/`app()`, a troca direta continua valendo com `Distribuicao::ligada()` falso
(Config vazia).

- [ ] **Step 6: Commit**

```bash
git add api/app/Support/Entregas/TrocaDoMotoboy.php scripts/teste-php/lider.php
git commit -m "Troca de motoboy antes da coleta: o pedido volta à fila com a oferta ao escolhido

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Rota `loja/aceites` (API)

**Files:**
- Create: `api/app/Support/Entregas/AceitesDaLoja.php`
- Modify: `api/app/Http/Controllers/Entregas/PortalLojaController.php`, `api/app/Providers/RouteServiceProvider.php`
- Test: `scripts/teste-php/aceites-da-loja.php` (novo)

- [ ] **Step 1: Escrever o teste que falha**

Crie `scripts/teste-php/aceites-da-loja.php`:

```php
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
    confere(in_array('with driverAssigned,trackingNumber', end(Order::$consultas)->passos, true) && in_array('limit 50', end(Order::$consultas)->passos, true), 'carrega o motoboy e o rastreio numa consulta só; no máximo 50');
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
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/aceites-da-loja.php`
Expected: erro fatal no `require` de `AceitesDaLoja.php` (não existe).

- [ ] **Step 3: Implementar a classe**

Crie `api/app/Support/Entregas/AceitesDaLoja.php`:

```php
<?php

namespace App\Support\Entregas;

use Fleetbase\FleetOps\Models\Order;

/**
 * Entregas RestaurantePro: os aceites recentes dos pedidos da loja, para a buzina do portal (serviço entregas-buzina,
 * GET int/v1/entregas/loja/aceites). Aceito = com motoboy e started_at nas últimas HORAS. Cada aceite grava outro
 * started_at (a troca com aceite devolve o pedido à fila, e o aceite do novo motoboy grava de novo), então o par (pedido,
 * aceito_em) identifica o aceite; a troca direta depois da coleta não muda o started_at e não toca. Só o primeiro nome do
 * motoboy: nada de id, telefone ou posição. started_at é DATETIME em hora de Brasília (ver "Fuso" em api/CLAUDE.md).
 */
class AceitesDaLoja
{
    public const HORAS  = 2;
    public const LIMITE = 50;

    /**
     * @param array<string> $donos customer_uuid da loja e do contato do usuário (PortalLojaController::donosDosPedidos)
     *
     * @return array<int, array{pedido: string, numero: string, aceito_em: string, motoboy: ?string}>
     */
    public static function listar(string $empresa, array $donos): array
    {
        if ($donos === []) {
            return [];
        }

        // nenhum model registra o CompanyScope nesta versão: a empresa é filtrada aqui
        $pedidos = Order::where('company_uuid', $empresa)
            ->whereIn('customer_uuid', $donos)
            ->whereNotNull('driver_assigned_uuid')
            ->whereNull('deleted_at')
            ->where('started_at', '>=', now()->subHours(static::HORAS)->format('Y-m-d H:i:s'))
            ->with(['driverAssigned', 'trackingNumber'])
            ->orderBy('started_at', 'desc')
            ->limit(static::LIMITE)
            ->get();

        $aceites = [];
        foreach ($pedidos as $pedido) {
            $aceitoEm = static::data($pedido->started_at);
            if (!$aceitoEm) {
                continue;
            }
            $aceites[] = [
                'pedido'    => (string) $pedido->public_id,
                'numero'    => (string) ($pedido->internal_id ?: ($pedido->trackingNumber?->tracking_number ?: $pedido->public_id)),
                'aceito_em' => PrazoDaEntrega::formatar($aceitoEm),
                'motoboy'   => static::primeiroNome($pedido->driverAssigned?->name),
            ];
        }

        return $aceites;
    }

    public static function primeiroNome(?string $nome): ?string
    {
        $partes = preg_split('/\s+/', trim((string) $nome));

        return ($partes[0] ?? '') === '' ? null : $partes[0];
    }

    /** O started_at: Carbon (cast do model) ou texto DATETIME sem fuso, lido no fuso do app. */
    protected static function data($valor): ?\DateTimeInterface
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor;
        }
        if (!is_string($valor) || trim($valor) === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable(substr(trim($valor), 0, 19), new \DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            return null;
        }
    }
}
```

- [ ] **Step 4: Controller, rota e limitador**

Em `api/app/Http/Controllers/Entregas/PortalLojaController.php`:
- acrescente `use App\Support\Entregas\AceitesDaLoja;` (primeiro dos `use App\Support\...`, ordem alfabética);
- logo depois do método `resumoDoDia()`, acrescente:

```php
    /**
     * Os aceites recentes dos pedidos da loja (AceitesDaLoja), para a buzina do portal, que lê a cada 10 s em qualquer
     * tela (serviço entregas-buzina). Usuário sem loja: 404.
     */
    public function aceites()
    {
        $vendor = $this->lojaDaSessao();

        return response()->json(['aceites' => AceitesDaLoja::listar((string) session('company'), $this->donosDosPedidos($vendor))]);
    }
```

Em `api/app/Providers/RouteServiceProvider.php`:
- logo depois da linha `RateLimiter::for('entregas-loja-mapa', ...)` (e do comentário dela), acrescente:

```php
        // Entregas RestaurantePro: buzina do portal da loja (cada aba do portal lê os aceites a cada 10 s, em qualquer tela),
        // até 30 chamadas por minuto por usuário, num balde separado do throttle:60,1 das outras rotas da loja
        RateLimiter::for('entregas-loja-aceites', fn (Request $request) => Limit::perMinute(30)->by('entregas-loja-aceites:' . (session('user') ?: $request->ip())));
```

- logo depois da linha `Route::get('loja/motoboys', [PortalLojaController::class, 'motoboysNoMapa'])->middleware('throttle:entregas-loja-mapa');`, acrescente:

```php
                        Route::get('loja/aceites', [PortalLojaController::class, 'aceites'])->middleware('throttle:entregas-loja-aceites');
```

- [ ] **Step 5: Rodar e ver passar**

```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/aceites-da-loja.php
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/imports-dos-providers.php
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/mapa-da-loja.php
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/AceitesDaLoja.php api/app/Http/Controllers/Entregas/PortalLojaController.php api/app/Providers/RouteServiceProvider.php
```
Expected: `FALHAS: 0` nos três; sintaxe sem erro. (O `mapa-da-loja.php` carrega o `PortalLojaController` de verdade: se ele
falhar por não achar a classe `AceitesDaLoja`, acrescente `require '/repo/api/app/Support/Entregas/AceitesDaLoja.php';`
junto dos outros `require` dele.)

- [ ] **Step 6: Commit**

```bash
git add api/app/Support/Entregas/AceitesDaLoja.php api/app/Http/Controllers/Entregas/PortalLojaController.php api/app/Providers/RouteServiceProvider.php scripts/teste-php/aceites-da-loja.php
git commit -m "Portal da loja: rota loja/aceites para a buzina

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Console: o aviso depois do Atribuir/Trocar

**Files:**
- Modify: `packages/fleetops/addon/utils/painel-inicial.js`, `packages/fleetops/addon/components/home/painel-inicial.js`, `packages/fleetops/addon/utils/ao-vivo-da-central.js`, `packages/fleetops/addon/utils/tela-de-pedidos-base.js`, `packages/fleetops/translations/pt-br.yaml`, `packages/fleetops/translations/en-us.yaml`
- Test: `scripts/teste-portal/painel-inicial.test.mjs`

- [ ] **Step 1: Escrever o teste que falha**

Em `scripts/teste-portal/painel-inicial.test.mjs`, acrescente `avisoDaAtribuicao,` à lista do `import { ... } from '../../packages/fleetops/addon/utils/painel-inicial.js';` e, no fim do arquivo:

```js
test('avisoDaAtribuicao: oferta ao escolhido ou troca direta', () => {
    assert.deepEqual(avisoDaAtribuicao({ pedido: {}, oferta: { motoboy: 'driver_a', nome: ' Carlos ', vence_em: '2026-10-28T10:00:50-03:00' } }), { chave: 'atribuir.oferta-enviada', nome: 'Carlos' });
    assert.deepEqual(avisoDaAtribuicao({ pedido: {}, oferta: { motoboy: 'driver_a', nome: '' } }), { chave: 'atribuir.oferta-sem-nome', nome: null });
    assert.deepEqual(avisoDaAtribuicao({ pedido: {}, oferta: null }), { chave: 'atribuir.enviado', nome: null });
    assert.deepEqual(avisoDaAtribuicao({ pedido: {} }), { chave: 'atribuir.enviado', nome: null });
    assert.deepEqual(avisoDaAtribuicao(undefined), { chave: 'atribuir.enviado', nome: null });
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/painel-inicial.test.mjs`
Expected: FAIL (`avisoDaAtribuicao` não exportada).

- [ ] **Step 3: Implementar a função**

No fim de `packages/fleetops/addon/utils/painel-inicial.js`:

```js
/**
 * O aviso depois do Atribuir ou do Trocar (POST entregas/painel-inicial/pedidos/{id}/motoboy): com `oferta` na resposta,
 * o pedido voltou à fila esperando o aceite do escolhido (TrocaDoMotoboy antes da coleta); sem ela, a troca foi direta.
 * Devolve a chave (dentro de fleet-ops.ui.painel-inicial) e o nome do escolhido.
 */
export function avisoDaAtribuicao(resposta) {
    const oferta = resposta?.oferta;
    if (!oferta) {
        return { chave: 'atribuir.enviado', nome: null };
    }
    const nome = typeof oferta.nome === 'string' ? oferta.nome.trim() : '';

    return nome ? { chave: 'atribuir.oferta-enviada', nome } : { chave: 'atribuir.oferta-sem-nome', nome: null };
}
```

- [ ] **Step 4: Usar nos dois lugares que atribuem**

`packages/fleetops/addon/components/home/painel-inicial.js`: acrescente `avisoDaAtribuicao,` à lista importada de
`'../../utils/painel-inicial'` (o `import { ... } from '../../utils/painel-inicial';` que termina na linha 23) e troque o
corpo do `try` do `atribuir` por:

```js
            const resposta = yield this.fetch.post(`entregas/painel-inicial/pedidos/${pedidoId}/motoboy`, { motoboy: motoboyId });
            this.atribuindo = null;
            const aviso = avisoDaAtribuicao(resposta);
            this.notifications.success(this.intl.t(`${P}.${aviso.chave}`, { nome: aviso.nome }));
            yield this.carregarAoVivo.perform();
```

`packages/fleetops/addon/utils/ao-vivo-da-central.js`, método `atribuir`: devolva a resposta:

```js
    /** Atribui (ou troca) o motoboy do pedido pelo TrocaDoMotoboy (só admin; a API devolve 403 para os outros). Devolve a resposta (com `oferta` quando o escolhido precisa aceitar). */
    async atribuir(pedidoId, motoboyId) {
        this.enviando = true;
        try {
            const resposta = await this.fetch.post(`entregas/painel-inicial/pedidos/${pedidoId}/motoboy`, { motoboy: motoboyId });
            await this.carregar();

            return resposta;
        } finally {
            this.enviando = false;
        }
    }
```

`packages/fleetops/addon/utils/tela-de-pedidos-base.js`: no import da linha 10, inclua `avisoDaAtribuicao`
(`import { situacaoDoPedido, motoboysParaAtribuir, avisoDaAtribuicao } from './painel-inicial';`) e, no `enviar`, troque
as linhas do `try` por:

```js
            const resposta = await this.aoVivo.atribuir(pedidoId, motoboyId);
            this.atribuindo = null;
            const aviso = avisoDaAtribuicao(resposta);
            this.notifications.success(this.intl.t(`${PI}.${aviso.chave}`, { nome: aviso.nome }));
```

- [ ] **Step 5: Traduções**

`packages/fleetops/translations/pt-br.yaml` (grupo `painel-inicial` → `atribuir`, linhas ~683-684, e
`tela-pedidos` → `detalhe`, linha ~749; mantenha a indentação do arquivo):

```yaml
        nota: O motoboy escolhido recebe a oferta para aceitar. Se recusar, a distribuição continua.
        enviado: Motoboy atribuído.
        oferta-enviada: Oferta enviada a {nome}. Se recusar, o pedido volta para a fila.
        oferta-sem-nome: Oferta enviada. Se o motoboy recusar, o pedido volta para a fila.
```
e
```yaml
        nota-troca: Antes da coleta, o escolhido precisa aceitar; se recusar, o pedido volta para a fila. O motoboy atual sai do pedido e recebe o aviso.
```

`packages/fleetops/translations/en-us.yaml` (mesmas posições, indentação do arquivo):

```yaml
            nota: The chosen driver gets the offer to accept. If they decline, dispatch keeps going.
            enviado: Driver assigned.
            oferta-enviada: Offer sent to {nome}. If they decline, the order goes back to the queue.
            oferta-sem-nome: Offer sent. If the driver declines, the order goes back to the queue.
```
e
```yaml
            nota-troca: Before pickup, the chosen driver must accept; if they decline, the order goes back to the queue. The current driver is removed and notified.
```

Atenção: as chaves `${P}.${aviso.chave}` são montadas no JS e o `i18n-check` não as vê; confira à mão que
`fleet-ops.ui.painel-inicial.atribuir.oferta-enviada` e `.oferta-sem-nome` existem nas duas línguas.

- [ ] **Step 6: Rodar e conferir**

```bash
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/painel-inicial.test.mjs scripts/teste-portal/tela-de-pedidos.test.mjs
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal
```
Expected: testes passam; i18n-check sai com exit 0. Confira que os JS alterados parseiam com o `@babel/parser` (ver
`packages/CLAUDE.md`, que diz o comando e o caminho em `console/node_modules/.pnpm/@babel+parser@7*`).

- [ ] **Step 7: Commit**

```bash
git add packages/fleetops/addon/utils/painel-inicial.js packages/fleetops/addon/components/home/painel-inicial.js packages/fleetops/addon/utils/ao-vivo-da-central.js packages/fleetops/addon/utils/tela-de-pedidos-base.js packages/fleetops/translations/pt-br.yaml packages/fleetops/translations/en-us.yaml scripts/teste-portal/painel-inicial.test.mjs
git commit -m "Console: aviso de oferta enviada ao escolhido depois do Atribuir/Trocar

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Portal: a buzina

**Files:**
- Create: `packages/customer-portal/addon/utils/buzina.js`, `packages/customer-portal/addon/services/entregas-buzina.js`, `packages/customer-portal/app/services/entregas-buzina.js`, `scripts/teste-portal/buzina.test.mjs`
- Modify: `packages/customer-portal/addon/components/portal/topo.js`, `console/ember-cli-build.js`

- [ ] **Step 1: Escrever o teste que falha**

Crie `scripts/teste-portal/buzina.test.mjs`:

```js
// A buzina do portal da loja (packages/customer-portal/addon/utils/buzina.js): quando toca a cada leitura de loja/aceites.
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/buzina.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ARQUIVO_DA_BUZINA, chavesDosAceites, registrarAceites } from '../../packages/customer-portal/addon/utils/buzina.js';

const aceite = (pedido, aceitoEm) => ({ pedido, numero: '1', aceito_em: aceitoEm, motoboy: 'Ana' });

test('chavesDosAceites: pedido + aceito_em, ignorando linha fora do formato', () => {
    assert.deepEqual(chavesDosAceites({ aceites: [aceite('order_a', '2026-10-28T19:00:00-03:00'), { pedido: 'order_b' }, null, aceite('', 'x')] }), ['order_a|2026-10-28T19:00:00-03:00']);
    assert.deepEqual(chavesDosAceites(null), []);
    assert.deepEqual(chavesDosAceites({ aceites: 'x' }), []);
});

test('registrarAceites: a primeira leitura só registra', () => {
    const { vistas, tocar } = registrarAceites(null, { aceites: [aceite('order_a', 't1')] });
    assert.equal(tocar, false);
    assert.deepEqual([...vistas], ['order_a|t1']);
});

test('registrarAceites: aceite novo toca uma vez, mesmo com vários na mesma leitura', () => {
    let estado = registrarAceites(null, { aceites: [aceite('order_a', 't1')] });
    estado = registrarAceites(estado.vistas, { aceites: [aceite('order_a', 't1')] });
    assert.equal(estado.tocar, false, 'nada novo');
    estado = registrarAceites(estado.vistas, { aceites: [aceite('order_b', 't2'), aceite('order_c', 't3'), aceite('order_a', 't1')] });
    assert.equal(estado.tocar, true, 'dois novos: um toque');
    estado = registrarAceites(estado.vistas, { aceites: [aceite('order_b', 't2'), aceite('order_c', 't3'), aceite('order_a', 't1')] });
    assert.equal(estado.tocar, false, 'os mesmos de novo: não toca');
});

test('registrarAceites: a troca aceita (mesmo pedido, outro aceito_em) toca de novo', () => {
    const antes = registrarAceites(null, { aceites: [aceite('order_a', 't1')] });
    const depois = registrarAceites(antes.vistas, { aceites: [aceite('order_a', 't9')] });
    assert.equal(depois.tocar, true);
});

test('registrarAceites: o que saiu da janela de 2 h não fica guardado; lista vazia não toca', () => {
    const antes = registrarAceites(null, { aceites: [aceite('order_a', 't1')] });
    const vazio = registrarAceites(antes.vistas, { aceites: [] });
    assert.equal(vazio.tocar, false);
    assert.equal(vazio.vistas.size, 0);
});

test('o arquivo da buzina fica em /sons (console/public, fora do fingerprint)', () => {
    assert.equal(ARQUIVO_DA_BUZINA, '/sons/buzina.mp3');
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/buzina.test.mjs`
Expected: FAIL (módulo não existe).

- [ ] **Step 3: As funções puras**

Crie `packages/customer-portal/addon/utils/buzina.js`:

```js
// Entregas: a buzina de moto do portal da loja quando um motoboy aceita um pedido dela (serviço entregas-buzina, rota
// entregas/loja/aceites). Funções puras, testadas em scripts/teste-portal/buzina.test.mjs.

/** O áudio da buzina: console/public/sons/ (fora do fingerprint, no console/ember-cli-build.js). */
export const ARQUIVO_DA_BUZINA = '/sons/buzina.mp3';

/** As chaves dos aceites da resposta: o par pedido + aceito_em (a troca aceita grava outro aceito_em e toca de novo). */
export function chavesDosAceites(resposta) {
    const aceites = Array.isArray(resposta?.aceites) ? resposta.aceites : [];
    const chaves = [];
    for (const aceite of aceites) {
        if (typeof aceite?.pedido === 'string' && aceite.pedido && typeof aceite?.aceito_em === 'string' && aceite.aceito_em) {
            chaves.push(`${aceite.pedido}|${aceite.aceito_em}`);
        }
    }

    return chaves;
}

/**
 * Compara a leitura com as chaves já vistas. vistas null = primeira leitura: só registra (não toca pelo que já estava
 * aceito ao abrir o portal). Depois, tocar = true se apareceu alguma chave nova (várias na mesma leitura: um toque só).
 * As vistas passam a ser só as desta leitura (a janela de 2 h do servidor), para não crescer sem fim.
 */
export function registrarAceites(vistas, resposta) {
    const chaves = chavesDosAceites(resposta);
    if (vistas === null || vistas === undefined) {
        return { vistas: new Set(chaves), tocar: false };
    }

    return { vistas: new Set(chaves), tocar: chaves.some((chave) => !vistas.has(chave)) };
}
```

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/buzina.test.mjs`
Expected: PASS.

- [ ] **Step 4: O serviço**

Crie `packages/customer-portal/addon/services/entregas-buzina.js`:

```js
import Service, { inject as service } from '@ember/service';
import { race, restartableTask, timeout, waitForEvent } from 'ember-concurrency';
import { espera } from '../utils/entregas-pedido';
import { ARQUIVO_DA_BUZINA, registrarAceites } from '../utils/buzina';

/** Leitura dos aceites com a aba à vista; com erro, a espera cresce (espera). */
const INTERVALO_MS = 10000;

/**
 * Entregas: a buzina de moto no portal da loja quando um motoboy aceita um pedido dela (spec
 * docs/superpowers/specs/2026-10-28-troca-com-aceite-e-buzina-design.md). Lê entregas/loja/aceites a cada 10 s com a aba à
 * vista (oculta: espera ela voltar) e toca o áudio uma vez por leitura com aceite novo; a primeira leitura não toca. O
 * navegador só libera o som depois de um clique ou tecla na página: o primeiro gesto "destrava" o áudio (play e pause
 * mudos). Iniciado e encerrado pelo Portal::Topo, que fica em todas as telas do portal.
 */
export default class EntregasBuzinaService extends Service {
    @service fetch;

    vistas = null;
    audio = null;
    destravar = null;

    @restartableTask *acompanhar() {
        let falhas = 0;
        this.escutarGestos();
        while (!this.isDestroying) {
            const oculta = document.hidden;
            if (!oculta) {
                try {
                    const resposta = yield this.fetch.get('entregas/loja/aceites');
                    const { vistas, tocar } = registrarAceites(this.vistas, resposta);
                    this.vistas = vistas;
                    falhas = 0;
                    if (tocar) {
                        this.tocar();
                    }
                } catch {
                    falhas++;
                }
            }
            const proxima = timeout(espera(INTERVALO_MS, falhas));
            yield oculta ? race([proxima, waitForEvent(document, 'visibilitychange')]) : proxima;
        }
    }

    /** Saiu do portal: para de ler e esquece as vistas (a volta não toca pelo que foi aceito enquanto estava fora). */
    encerrar() {
        this.acompanhar.cancelAll();
        this.pararDeEscutarGestos();
        this.vistas = null;
    }

    obterAudio() {
        if (!this.audio && typeof Audio !== 'undefined') {
            try {
                this.audio = new Audio(ARQUIVO_DA_BUZINA);
                this.audio.preload = 'auto';
            } catch {
                this.audio = null;
            }
        }

        return this.audio;
    }

    /** Toca do começo. Sem som liberado pelo navegador ou sem o arquivo, não faz nada. */
    tocar() {
        const audio = this.obterAudio();
        if (!audio) {
            return;
        }
        try {
            audio.currentTime = 0;
            audio.play()?.catch?.(() => {});
        } catch {
            // sem som liberado: nada
        }
    }

    escutarGestos() {
        if (this.destravar) {
            return;
        }
        this.destravar = () => {
            const audio = this.obterAudio();
            if (audio) {
                try {
                    audio.muted = true;
                    audio.play()?.then?.(
                        () => {
                            audio.pause();
                            audio.currentTime = 0;
                            audio.muted = false;
                        },
                        () => {
                            audio.muted = false;
                        }
                    );
                } catch {
                    audio.muted = false;
                }
            }
            this.pararDeEscutarGestos();
        };
        document.addEventListener('pointerdown', this.destravar, true);
        document.addEventListener('keydown', this.destravar, true);
    }

    pararDeEscutarGestos() {
        if (this.destravar) {
            document.removeEventListener('pointerdown', this.destravar, true);
            document.removeEventListener('keydown', this.destravar, true);
            this.destravar = null;
        }
    }
}
```

Crie o re-export `packages/customer-portal/app/services/entregas-buzina.js` (o mesmo modelo de
`app/services/entregas-conversas.js`):

```js
export { default } from '@fleetbase/customer-portal-engine/services/entregas-buzina';
```

- [ ] **Step 5: Iniciar no Topo**

Em `packages/customer-portal/addon/components/portal/topo.js`:
- acrescente `@service entregasBuzina;` logo abaixo de `@service entregasConversas;`;
- no `constructor`, depois de `this.entregasConversas.acompanharLista.perform();`, acrescente
  `this.entregasBuzina.acompanhar.perform();`;
- no `willDestroy`, depois de `this.entregasConversas.encerrar();`, acrescente `this.entregasBuzina.encerrar();`;
- no docblock da classe, troque "é ele quem acompanha a lista de conversas (o número de não lidas e o aviso sonoro)
  enquanto o portal está aberto." por "é ele quem acompanha a lista de conversas (o número de não lidas e o aviso sonoro) e
  a buzina dos aceites (entregas-buzina) enquanto o portal está aberto."

- [ ] **Step 6: O áudio fora do fingerprint**

Em `console/ember-cli-build.js`, no `fingerprint.exclude`, acrescente `'sons/'` depois de `'images/entregas/'`:

```js
        fingerprint: { exclude: ['leaflet/', 'leaflet-images/', 'images/entregas/', 'sons/', 'landing/', 'socketcluster-client.min.js', 'fleetbase.config.json', 'extensions.json'] },
```
(confira a linha exata no arquivo; só acrescente o `'sons/'`, mantendo o resto.)

Crie a pasta `console/public/sons/`. O arquivo `buzina.mp3` entra na Task 6.

- [ ] **Step 7: Conferir**

```bash
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/buzina.test.mjs
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal
```
Expected: PASS; exit 0. Confira que `buzina.js`, `entregas-buzina.js` e `topo.js` parseiam com o `@babel/parser`
(`packages/CLAUDE.md`).

- [ ] **Step 8: Commit**

```bash
git add packages/customer-portal/addon/utils/buzina.js packages/customer-portal/addon/services/entregas-buzina.js packages/customer-portal/app/services/entregas-buzina.js packages/customer-portal/addon/components/portal/topo.js console/ember-cli-build.js scripts/teste-portal/buzina.test.mjs
git commit -m "Portal da loja: buzina de moto quando um motoboy aceita um pedido

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: O áudio da buzina (arquivo do Edgard)

**Files:**
- Create: `console/public/sons/buzina.mp3`

- [ ] **Step 1: Achar o arquivo**

O Edgard disse que deixaria o áudio no projeto. Procure na raiz e em `console/public`:

```bash
git status --short
ls -la *.mp3 *.ogg *.wav *.m4a 2>/dev/null
```

Se não houver arquivo de áudio, **pare e pergunte ao Edgard** onde está (não invente nem baixe um som).

- [ ] **Step 2: Colocar no lugar**

- mp3: `git mv` (ou `mv`, se não rastreado) para `console/public/sons/buzina.mp3`;
- `.ogg`, `.wav` ou `.m4a`: mova para `console/public/sons/buzina.<ext>` e troque a extensão em `ARQUIVO_DA_BUZINA`
  (`packages/customer-portal/addon/utils/buzina.js`) e na expectativa do teste (`scripts/teste-portal/buzina.test.mjs`).
  Rode o teste de novo.

Confira o tamanho (`ls -la console/public/sons/`): acima de ~500 KB, avise o Edgard (o navegador baixa na primeira
abertura do portal).

- [ ] **Step 3: Commit**

```bash
git add console/public/sons/
git commit -m "Portal da loja: áudio da buzina

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: App do líder: texto da oferta (repo `entregas-navigator`)

Repo separado: `C:\Users\Edgardjr\Documents\vibe coding\entregas-navigator` (leia o `CLAUDE.md` dele antes; confirme
`git rev-parse --show-toplevel`). Sem esta tarefa, tudo funciona; só o toast do líder diria "passou para Fulano" quando o
pedido, na verdade, está esperando o aceite. Push na `main` gera APK, e cada APK vira a versão mínima (trava "Atualize o
app"): **não faça push sem o Edgard pedir**.

**Files:**
- Modify: `src/utils/mapa-do-lider.ts`, `src/screens/MapaDoLiderScreen.tsx`, `translations/pt.json`, `translations/en.json`
- Test: `scripts/testes/mapa-do-lider.teste.ts`

- [ ] **Step 1: Teste que falha**

Em `scripts/testes/mapa-do-lider.teste.ts`, acrescente `ofertaDaTroca,` ao `import { ... } from '../../src/utils/mapa-do-lider.ts';` e, no fim:

```ts
test('ofertaDaTroca: o escolhido que precisa aceitar, ou null na troca direta', () => {
    assert.deepEqual(ofertaDaTroca({ pedido: {}, oferta: { motoboy: 'driver_a', nome: ' Carlos ', vence_em: '2026-10-28T10:00:50-03:00' } }), { motoboy: 'driver_a', nome: 'Carlos' });
    assert.deepEqual(ofertaDaTroca({ oferta: { motoboy: 'driver_a', nome: '' } }), { motoboy: 'driver_a', nome: null });
    assert.equal(ofertaDaTroca({ pedido: {}, oferta: null }), null);
    assert.equal(ofertaDaTroca({ pedido: {} }), null);
    assert.equal(ofertaDaTroca({ oferta: { nome: 'x' } }), null);
    assert.equal(ofertaDaTroca(null), null);
});
```

Run: `node --experimental-strip-types --test scripts/testes/mapa-do-lider.teste.ts`
Expected: FAIL.

- [ ] **Step 2: Implementar**

Em `src/utils/mapa-do-lider.ts`, depois de `erroDaTroca`:

```ts
/**
 * A oferta da resposta da troca (TrocaDoMotoboy antes da coleta: o pedido voltou à fila esperando o aceite do escolhido).
 * Sem ela (troca direta depois da coleta, servidor antigo): null.
 */
export function ofertaDaTroca(resposta: unknown): { motoboy: string; nome: string | null } | null {
    const oferta = (resposta as { oferta?: unknown } | null)?.oferta as { motoboy?: unknown; nome?: unknown } | null | undefined;
    if (!oferta || typeof oferta !== 'object' || typeof oferta.motoboy !== 'string' || !oferta.motoboy) {
        return null;
    }
    const nome = typeof oferta.nome === 'string' ? oferta.nome.trim() : '';

    return { motoboy: oferta.motoboy, nome: nome || null };
}
```

Em `src/screens/MapaDoLiderScreen.tsx`: inclua `ofertaDaTroca` no `import { ... } from '../utils/mapa-do-lider';` e troque
`toast.success(t('MapaDoLider.trocado', { pedido: rotulo, nome: opcao.nome }));` por:

```tsx
                const oferta = ofertaDaTroca(resposta);
                toast.success(
                    oferta
                        ? t('MapaDoLider.ofertaEnviada', { pedido: rotulo, nome: oferta.nome ?? opcao.nome })
                        : t('MapaDoLider.trocado', { pedido: rotulo, nome: opcao.nome })
                );
```

Traduções (grupo `MapaDoLider`, ao lado de `trocado`, linha ~1223):
- `translations/pt.json`: `"ofertaEnviada": "Pedido {{pedido}} oferecido a {{nome}}. Se recusar, volta para a fila.",`
- `translations/en.json`: `"ofertaEnviada": "Order {{pedido}} offered to {{nome}}. If they decline, it goes back to the queue.",`

- [ ] **Step 3: Rodar**

```bash
node --experimental-strip-types --test scripts/testes/mapa-do-lider.teste.ts
```
Expected: PASS. Rode também o lint/tsc que o `CLAUDE.md` do app pedir.

- [ ] **Step 4: Commit (sem push)**

```bash
git add src/utils/mapa-do-lider.ts src/screens/MapaDoLiderScreen.tsx translations/pt.json translations/en.json scripts/testes/mapa-do-lider.teste.ts
git commit -m "Líder: aviso de oferta enviada quando o escolhido precisa aceitar a troca

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Documentação e verificação final

**Files:**
- Modify: `api/app/Support/Entregas/Distribuicao/CLAUDE.md`, `api/app/Http/Controllers/Entregas/CLAUDE.md` (e a cópia no `CLAUDE.md` do `entregas-navigator`), `api/app/Support/Entregas/Painel/CLAUDE.md`, `packages/customer-portal/CLAUDE.md`, `packages/fleetops/CLAUDE.md`, `docs/historico.md`

- [ ] **Step 1: Distribuição**

Em `api/app/Support/Entregas/Distribuicao/CLAUDE.md`, nova seção antes de "## Logs":

```markdown
## Oferta ao escolhido (troca e atribuição)

Decisão do Edgard de 2026-10-28 (spec `docs/superpowers/specs/2026-10-28-troca-com-aceite-e-buzina-design.md`). A troca e
a atribuição de motoboy pela central (Atribuir/Trocar do console) e pelo líder (aba Mapa) **antes da coleta** viram oferta:

- A `TrocaDoMotoboy`, com a `TravaDoPedido`, devolve o pedido à fila (sem motoboy, `adhoc` ligado, `started` falso,
  `started_at` nulo, status `dispatched`, num `save()` só) e, **dentro da mesma trava**, chama
  `Distribuidor::oferecerAoEscolhidoSemTrava` (não toma a trava, como o `encerrar` e o `registrarAceite`). Fora da trava
  haveria um instante com o pedido aberto e sem distribuição, com o aceite livre.
- O `oferecerAoEscolhidoSemTrava` encerra a distribuição em curso (`redespachada`, a pendente de outro vira `cancelada`),
  cria outra e grava a oferta ao escolhido: volta 1, rodada 1 (raio R em rodadas), lista fechada, 50 s (30 s sem rodadas),
  push `OfertaDePedido` e job `AvancarOferta`. Vale **mesmo fora do raio, ocupado, com o GPS velho ou com o app abaixo da
  versão mínima** (quem escolheu foi a central ou o líder; com o app velho, o aceite dele leva 426). O tempo da fila sai
  da `FilaDeCandidatos` com a posição dele; sem posição, 0 e aproximado. Log `oferta ao escolhido enviada`.
- Recusou ou venceu: o ciclo de sempre. O escolhido fica fora da volta 1 (já tem linha nela). **O motoboy anterior é
  candidato como qualquer um** (decisão do Edgard).
- Falha: abre a todos (`falha`) com o alarme geral e devolve null (log `falha na oferta ao escolhido; aberta a todos`).
  Nunca lança: a troca responde 200 com `oferta: null`.
- Depois da coleta (`SituacaoDoMotoboy::STATUS_EM_ENTREGA`) ou com a distribuição desligada: a troca direta de antes.
- **Efeitos do reset** (pesquisa de 2026-10-28): nenhum `OrderDriverAssigned` com motoboy nulo; nada vai ao iFood no
  intervalo (o iFood continua com o anterior); no aceite, o `assignDriver` do novo (o `goingToOrigin` não se repete; um 409
  do iFood vira a recusa com aviso de sempre); o valor congelado limpa a atribuição e, no aceite, multiplicador e "entrega
  junto" (inclusive da loja) passam a ser os do novo; a linha do tempo do pedido ganha um segundo "iniciado".
- **Risco aceito:** o escolhido com oferta pendente de outro pedido recebe esta também (duas ofertas ao mesmo tempo).
- Testes: `scripts/teste-php/troca-com-aceite.php` e os casos "Troca com aceite" de `lider.php`.
```

Na seção "## Logs", acrescente `oferta ao escolhido enviada` ao ciclo e `falha na oferta ao escolhido`,
`falha na oferta ao escolhido; aberta a todos` às falhas. Em "## Testes", acrescente `troca-com-aceite.php`.

- [ ] **Step 2: App do motoboy e painel**

Em `api/app/Http/Controllers/Entregas/CLAUDE.md`, troque o bullet "**A troca** segue a do console: ..." por:

```markdown
  - **A troca** (`TrocaDoMotoboy`, a mesma do Atribuir/Trocar do console): **antes da coleta, com a distribuição ligada,
    o escolhido precisa aceitar** (2026-10-28; ver "Oferta ao escolhido" em `api/app/Support/Entregas/Distribuicao/CLAUDE.md`):
    o pedido volta à fila e o escolhido recebe o cartão "Oferta para você"; recusou, a fila de sempre. A resposta traz
    `oferta: {motoboy, nome, vence_em}` (null na troca direta); log `[entregas] troca do motoboy: oferta ao escolhido`.
    Depois da coleta (ou com a distribuição desligada), a troca direta: com a `TravaDoPedido`, desliga o pedido aberto
    (`adhoc` falso) e chama o `assignDriver($motoboy, true)` do Order (silencioso: o save → `OrderObserver` → um
    `OrderDriverAssigned`, com o push "Novo pedido para você" ao novo e, no pedido iFood, o `assignDriver` ao iFood); log
    `[entregas] líder trocou o motoboy`. Nos dois casos, o `current_job_uuid` do anterior é limpo se apontava para o pedido e
    o anterior recebe o `PedidoPassadoParaOutro` ("Pedido #4821 passou para outro motoboy.", canal `avisos`, tipo
    `entregas_pedido_trocado`, **sem `id`** nos dados: o app recarrega a lista e, se ele estava com aquele pedido aberto,
    para o alarme e volta). O toast do líder com a oferta ("Pedido X oferecido a Y. Se recusar, volta para a fila.",
    `ofertaDaTroca`) vem no APK seguinte; antes dele, o toast diz "passou para Y".
```

Copie o mesmo bullet no `CLAUDE.md` do `entregas-navigator` (seção copiada; a regra do arquivo manda atualizar os dois).

Em `api/app/Support/Entregas/Painel/CLAUDE.md`, na seção `POST painel-inicial/pedidos/{id}/motoboy`, troque a lista que
começa em "Usa o `TrocaDoMotoboy::trocar`, a mesma troca do líder:" até "O log sai como \"líder trocou o motoboy\"." por:

```markdown
Usa o `TrocaDoMotoboy::trocar`, a mesma troca do líder. Antes da coleta, com a distribuição ligada, o pedido volta à
fila e o escolhido recebe a oferta (ver "Oferta ao escolhido" em `api/app/Support/Entregas/Distribuicao/CLAUDE.md`); a
resposta traz `oferta: {motoboy, nome, vence_em}` e o console avisa "Oferta enviada a Fulano. Se recusar, o pedido volta
para a fila." (`avisoDaAtribuicao`). Depois da coleta, a troca direta (trava, `adhoc` falso, um aviso só ao motoboy novo
e o `assignDriver` ao iFood), e a distribuição se encerra sozinha pelo `ObservadorDaDistribuicao`. Logs: "troca do
motoboy: oferta ao escolhido" ou "líder trocou o motoboy".
```

Em `packages/fleetops/CLAUDE.md`, onde fala "O admin atribui ou troca o motoboy pela folha", acrescente: "Antes da coleta,
o escolhido precisa aceitar (o pedido volta a "oferta" na tela; aviso `painel-inicial.atribuir.oferta-enviada`)."

- [ ] **Step 3: Portal**

Em `packages/customer-portal/CLAUDE.md`, na lista da API do portal (perto de `resumo-do-dia`), acrescente:

```markdown
  - `aceites` (`AceitesDaLoja`, limitador `entregas-loja-aceites`, 30 por minuto por usuário): os pedidos da loja aceitos nas últimas 2 h (`started_at`, com motoboy, não apagados), o mais recente primeiro, até 50: `{aceites: [{pedido, numero, aceito_em, motoboy}]}` (`aceito_em` ISO -03:00; `motoboy` só o primeiro nome). Teste: `scripts/teste-php/aceites-da-loja.php`;
```

E na parte do front:

```markdown
  - **buzina** (2026-10-28, spec `docs/superpowers/specs/2026-10-28-troca-com-aceite-e-buzina-design.md`): o serviço `entregas-buzina`, iniciado e encerrado pelo `Portal::Topo` (vale em todas as telas), lê `loja/aceites` a cada 10 s com a aba à vista e toca `/sons/buzina.mp3` (`console/public/sons/`, fora do fingerprint) uma vez por leitura com aceite novo (chave `pedido|aceito_em`: a troca aceita toca de novo; a troca direta depois da coleta não). A primeira leitura não toca. O navegador só libera o som depois do primeiro clique ou tecla (o gesto "destrava" o áudio, mudo). Funções puras em `utils/buzina.js` (`scripts/teste-portal/buzina.test.mjs`);
```

- [ ] **Step 4: Histórico**

Em `docs/historico.md`, acrescente o próximo número da lista:

```markdown
50. Troca com aceite e buzina na loja (2026-10-28; desenho `docs/superpowers/specs/2026-10-28-troca-com-aceite-e-buzina-design.md`, plano `docs/superpowers/plans/2026-10-28-troca-com-aceite-e-buzina.md`): a troca e a atribuição de motoboy antes da coleta viram oferta ao escolhido (cartão de aceitar; recusou, volta à fila; o anterior é candidato como qualquer um); depois da coleta, a troca direta de antes. O portal da loja toca uma buzina de moto a cada aceite (rota `loja/aceites`, serviço `entregas-buzina`).
```
(confira o número: é o seguinte ao último item do arquivo.)

- [ ] **Step 5: Verificação final**

```bash
for t in troca-com-aceite lider aceites-da-loja distribuicao-ciclo distribuicao-rodadas distribuicao-fila barrar-aceite filtrar-pedidos-abertos avisos-push mapa-da-loja imports-dos-providers; do echo "== $t"; PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php | tail -1; done
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit $?"
```
Expected: `FALHAS: 0` em todos os PHP; todos os testes de portal passam; `exit 0`.

- [ ] **Step 6: Commit**

```bash
git rev-parse --show-toplevel   # deve ser .../vibe coding/Delivery
git add api/app/Support/Entregas/Distribuicao/CLAUDE.md api/app/Http/Controllers/Entregas/CLAUDE.md api/app/Support/Entregas/Painel/CLAUDE.md packages/customer-portal/CLAUDE.md packages/fleetops/CLAUDE.md docs/historico.md
git commit -m "Troca com aceite e buzina na loja: documentação

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Implantação (Edgard, na VPS; não faz parte da execução)

1. Push da `main` do Delivery (quando o Edgard pedir).
2. `cd ~/entregas && bash deploy/atualizar.sh api` (sem migration).
3. `bash deploy/atualizar.sh console` (console e portal, ~15 min), depois Ctrl+Shift+R.
4. Teste combinado: Trocar pelo console um pedido aceito e não coletado → cartão "Oferta para você" no celular do
   escolhido → recusar → próxima oferta; aceitar → buzina no portal da loja (clicar uma vez na página antes). **O Terraço
   é loja real:** combinar antes.
5. APK do líder (Task 7) quando o Edgard quiser: push no `entregas-navigator` gera o APK, que vira a versão mínima.
