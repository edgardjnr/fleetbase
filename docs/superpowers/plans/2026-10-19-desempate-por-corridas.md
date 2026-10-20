# Desempate por corridas do dia: plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Objetivo:** na fila da distribuição, os motoboys que entregariam o cliente em até 3 min do mais rápido são
ordenados por menos corridas hoje.

**Arquitetura:** a ordenação sai do `FilaDeCandidatos::para` para uma função pura, `FilaDeCandidatos::ordenar`. A
contagem fica numa consulta nova, `Candidatos::corridasHoje`, chamada no `Candidatos::elegiveis` (uma consulta para
todos os candidatos, trocável nos testes). A fila gravada ganha `corridas_hoje` e `desempate`, que o painel do console
mostra. Por fim, uma frase na tela Sobre do app.

**Tecnologia:** Laravel (PHP 8.2, testado com php-wasm), Ember (painel do Fleet-Ops, testes com `node --test`), React
Native (só tradução).

**Spec:** `docs/superpowers/specs/2026-10-19-desempate-por-corridas-design.md`.

---

## Onde trabalhar

A `main` do Delivery tem mudanças não commitadas do Edgard (os `CLAUDE.md` por pasta e o `console/app/router.js`):
**não mexa nelas nem as commite.** Trabalhe numa worktree:

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/Delivery"
git worktree add /c/tmp/dc -b desempate-corridas main
cd /c/tmp/dc
```

O php-wasm está em `/c/tmp/php-wasm`. Comando de um teste PHP (na raiz da worktree):

```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/distribuicao-fila.php
```

O teste imprime `PASSA`/`FALHA` por caso e termina com `FALHAS: <n>`.

O app (`entregas-navigator`) fica em `C:\Users\Edgardjr\Documents\vibe coding\entregas-navigator`, na `main`, com um
`CLAUDE.md` não rastreado do Edgard (não o commite). Para ele, use a worktree `/c/tmp/dcapp` (Tarefa 4).

## Arquivos

| Arquivo | O que muda |
|---|---|
| `api/app/Support/Entregas/Distribuicao/Distribuicao.php` | constante `DESEMPATE_CORRIDAS_S = 180` |
| `api/app/Support/Entregas/Distribuicao/FilaDeCandidatos.php` | `corridas_hoje` na linha; `ordenar()` público e estático, com o grupo de desempate e o `desempate` |
| `api/app/Support/Entregas/Distribuicao/Candidatos.php` | `$buscarCorridasHoje`, `corridasHoje()` e `corridas_hoje` em cada candidato do `elegiveis` |
| `scripts/teste-php/distribuicao-fila.php` | casos de `ordenar`, `corridasHoje` e `para` |
| `packages/fleetops/addon/utils/distribuicao.js` | `corridasHoje(item)` |
| `packages/fleetops/addon/components/order/details/distribuicao.js` e `.hbs` | "N corridas hoje" e "desempate" na fila |
| `packages/fleetops/translations/pt-br.yaml` e `en-us.yaml` | `corridas-hoje` e `desempate` |
| `scripts/teste-portal/distribuicao.test.mjs` | teste do `corridasHoje` |
| `entregas-navigator/translations/pt.json` e `en.json`, `src/screens/SobreScreen.tsx` | parágrafo `ofertaDesempate` |
| `api/app/Support/Entregas/Distribuicao/CLAUDE.md` (na pasta principal, não na worktree) | a regra nova |

---

### Tarefa 1: ordenação com o grupo de desempate (`FilaDeCandidatos::ordenar`)

**Arquivos:**
- Modificar: `api/app/Support/Entregas/Distribuicao/Distribuicao.php` (depois de `ATRASO_MAXIMO_EM_RODADAS_S`)
- Modificar: `api/app/Support/Entregas/Distribuicao/FilaDeCandidatos.php`
- Testar: `scripts/teste-php/distribuicao-fila.php` (antes do `resumo();` final)

- [ ] **Passo 1: escrever os testes que falham**

Em `scripts/teste-php/distribuicao-fila.php`, logo antes da última linha (`resumo();`), acrescente:

```php
echo '== Desempate por corridas do dia (ordenar)' . PHP_EOL;
/** Uma linha da fila como a do FilaDeCandidatos::para; $corridas null = sem a chave (fila de antes do desempate). */
function linhaDaFila(string $id, int $tempo, ?int $corridas, bool $livre = true): array
{
    $linha = ['motoboy_uuid' => 'd-' . $id, 'public_id' => 'driver_' . $id, 'nome' => $id, 'tempo_s' => $tempo, 'encaixe' => false, 'aproximado' => false, 'livre' => $livre, 'distancia_m' => 0];
    if ($corridas !== null) {
        $linha['corridas_hoje'] = $corridas;
    }

    return $linha;
}

confere(Distribuicao::DESEMPATE_CORRIDAS_S === 180, 'limite do desempate: 3 min');

// o exemplo da spec: Ana 10 min (4), Beto 12 min (1), Caio 13 min (2), Davi 14 min (0, fora: 4 min acima da Ana)
$fila = FilaDeCandidatos::ordenar([linhaDaFila('davi', 840, 0), linhaDaFila('ana', 600, 4), linhaDaFila('caio', 780, 2), linhaDaFila('beto', 720, 1)]);
confere(array_column($fila, 'public_id') === ['driver_beto', 'driver_caio', 'driver_ana', 'driver_davi'], 'até 3 min do mais rápido: menos corridas primeiro; o resto pelo tempo (' . json_encode(array_column($fila, 'public_id')) . ')');
confere(array_column($fila, 'desempate') === [true, true, false, false], 'desempate marcado em quem passou à frente de alguém mais rápido (' . json_encode(array_column($fila, 'desempate')) . ')');

// Beto recusou: o grupo é refeito a partir da Ana (até 13 min)
$fila = FilaDeCandidatos::ordenar([linhaDaFila('ana', 600, 4), linhaDaFila('caio', 780, 2), linhaDaFila('davi', 840, 0)]);
confere(array_column($fila, 'public_id') === ['driver_caio', 'driver_ana', 'driver_davi'], 'fila refeita sem o Beto: Caio, Ana, Davi (' . json_encode(array_column($fila, 'public_id')) . ')');

$fila = FilaDeCandidatos::ordenar([linhaDaFila('a', 600, 5), linhaDaFila('b', 780, 0), linhaDaFila('c', 781, 0)]);
confere(array_column($fila, 'public_id') === ['driver_b', 'driver_a', 'driver_c'], 'limite exato: 180 s entra no grupo, 181 s fica fora (' . json_encode(array_column($fila, 'public_id')) . ')');

$fila = FilaDeCandidatos::ordenar([linhaDaFila('a', 700, 2), linhaDaFila('b', 650, 2)]);
confere(array_column($fila, 'public_id') === ['driver_b', 'driver_a'] && array_column($fila, 'desempate') === [false, false], 'empate em corridas: o menor tempo, sem desempate');

$fila = FilaDeCandidatos::ordenar([linhaDaFila('c', 650, 0), linhaDaFila('a', 600, 0, false), linhaDaFila('b', 600, 0)]);
confere(array_column($fila, 'public_id') === ['driver_b', 'driver_a', 'driver_c'], 'todos com 0: a ordem de antes (tempo, o livre, o public_id) (' . json_encode(array_column($fila, 'public_id')) . ')');

$fila = FilaDeCandidatos::ordenar([linhaDaFila('a', 600, null), linhaDaFila('b', 700, 1)]);
confere(array_column($fila, 'public_id') === ['driver_a', 'driver_b'] && $fila[0]['corridas_hoje'] === 0, 'linha sem corridas_hoje vale 0');

confere(FilaDeCandidatos::ordenar([]) === [], 'fila vazia: vazia');
```

- [ ] **Passo 2: rodar e ver falhar**

Rode o comando de `distribuicao-fila.php`. Esperado: erro de PHP (`Undefined constant ...DESEMPATE_CORRIDAS_S` ou
`Call to undefined method ...::ordenar()`), saída com código 1.

- [ ] **Passo 3: a constante**

Em `Distribuicao.php`, logo depois da linha `public const ATRASO_MAXIMO_EM_RODADAS_S = 300;`, acrescente:

```php
    /**
     * Desempate por corridas do dia: quem entregaria o cliente em até isto acima do mais rápido da fila entra no grupo
     * ordenado por menos corridas hoje (FilaDeCandidatos::ordenar). Decisão do Edgard (2026-10-19).
     */
    public const DESEMPATE_CORRIDAS_S = 180;
```

- [ ] **Passo 4: a ordenação**

Em `FilaDeCandidatos.php`, troque o comentário da classe:

```php
/**
 * A fila de um pedido aberto: para cada candidato, o tempo até o cliente novo (Encaixe) com uma matriz só do OSRM
 * (coleta, entrega, posições e paradas de todos). Ordem (ordenar): quem está até Distribuicao::DESEMPATE_CORRIDAS_S
 * do mais rápido vem primeiro, por menos corridas hoje; o resto pelo tempo. Empates: o tempo, o livre, o public_id.
 * Spec do desempate: docs/superpowers/specs/2026-10-19-desempate-por-corridas-design.md.
 */
```

No `@return` do `para`, acrescente `corridas_hoje: int, desempate: bool` ao fim do array shape. No `$fila[] = [...]`,
depois de `'distancia_m'  => (int) round($candidato['distancia']),`, acrescente:

```php
                'corridas_hoje' => (int) ($candidato['corridas_hoje'] ?? 0),
```

Troque a linha do `usort(...)` e o `return $fila;` do `para` por:

```php
        return static::ordenar($fila);
    }

    /**
     * Ordena a fila: o grupo de quem está até DESEMPATE_CORRIDAS_S do menor tempo vem primeiro, por menos corridas hoje
     * (empate: o tempo, o livre, o public_id); depois o resto pelo tempo, o livre e o public_id. `desempate` marca quem
     * ficou à frente de alguém com tempo menor. Linha sem corridas_hoje vale 0.
     *
     * @param array<int, array> $fila linhas do para()
     *
     * @return array<int, array>
     */
    public static function ordenar(array $fila): array
    {
        foreach ($fila as &$linha) {
            $linha['corridas_hoje'] = (int) ($linha['corridas_hoje'] ?? 0);
            $linha['desempate']     = false;
        }
        unset($linha);

        $porTempo = fn ($a, $b) => [$a['tempo_s'], $a['livre'] ? 0 : 1, $a['public_id']] <=> [$b['tempo_s'], $b['livre'] ? 0 : 1, $b['public_id']];
        usort($fila, $porTempo);
        if ($fila === []) {
            return [];
        }

        $limite = $fila[0]['tempo_s'] + Distribuicao::DESEMPATE_CORRIDAS_S;
        $grupo  = array_values(array_filter($fila, fn ($l) => $l['tempo_s'] <= $limite));
        $resto  = array_values(array_filter($fila, fn ($l) => $l['tempo_s'] > $limite));
        usort($grupo, fn ($a, $b) => [$a['corridas_hoje'], $a['tempo_s'], $a['livre'] ? 0 : 1, $a['public_id']] <=> [$b['corridas_hoje'], $b['tempo_s'], $b['livre'] ? 0 : 1, $b['public_id']]);

        // desempate: alguém atrás dele no grupo chegaria antes (o resto chega sempre depois de todo o grupo)
        $total = count($grupo);
        for ($i = 0; $i < $total; $i++) {
            for ($j = $i + 1; $j < $total; $j++) {
                if ($grupo[$j]['tempo_s'] < $grupo[$i]['tempo_s']) {
                    $grupo[$i]['desempate'] = true;
                    break;
                }
            }
        }

        return array_merge($grupo, $resto);
    }
```

- [ ] **Passo 5: rodar e ver passar**

Rode o comando de `distribuicao-fila.php`. Esperado: todos os casos `PASSA`, `FALHAS: 0`. Os casos antigos continuam
valendo: sem `corridas_hoje`, todos ficam com 0, e a ordem é a de antes.

- [ ] **Passo 6: commit**

```bash
git add api/app/Support/Entregas/Distribuicao/Distribuicao.php api/app/Support/Entregas/Distribuicao/FilaDeCandidatos.php scripts/teste-php/distribuicao-fila.php
git commit -m "Distribuição: desempate por corridas do dia na fila (até 3 min do mais rápido)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 2: contar as corridas de hoje (`Candidatos::corridasHoje`)

**Arquivos:**
- Modificar: `api/app/Support/Entregas/Distribuicao/Candidatos.php`
- Testar: `scripts/teste-php/distribuicao-fila.php` (antes do `resumo();` final)

Regra (spec, "Corridas hoje"): pedidos da empresa com `driver_assigned_uuid` do motoboy, `deleted_at` nulo, status fora
de `StatusDoPedido::CANCELADOS` e de `expired`, e aceite (`started_at`; se nulo, `dispatched_at`) a partir das 00:00 de
hoje no fuso do app. As duas colunas são DATETIME em hora de Brasília. Compare com o texto `Y-m-d H:i:s` no fuso do
app, **nunca em UTC** (`api/CLAUDE.md`, "Fuso").

A consulta usa só o que o banco em memória dos testes (`stubs-ifood.php`, classe `Teste\Consulta`) entende: `where`
com closure e `orWhere`, `whereIn`, `whereNotIn`, `whereNull` e `get`. O `COALESCE` vira um filtro em PHP. O stub
**não** tem `whereRaw` nem `selectRaw` com `COUNT`.

- [ ] **Passo 1: escrever os testes que falham**

Antes do `resumo();` final de `distribuicao-fila.php`, acrescente:

```php
echo '== Corridas de hoje (Candidatos::corridasHoje)' . PHP_EOL;
use Illuminate\Support\Facades\DB;

reiniciarIfood(); // banco em memória vazio; o relógio dos stubs está em 2026-10-05 15:00 (Brasília)
$pedidoDoDia = fn (array $c) => DB::table('orders')->insert([$c + ['company_uuid' => 'empresa-1', 'deleted_at' => null, 'status' => 'completed', 'started_at' => null, 'dispatched_at' => null]]);
$pedidoDoDia(['driver_assigned_uuid' => 'd-a', 'started_at' => '2026-10-05 09:00:00']);                           // conta
$pedidoDoDia(['driver_assigned_uuid' => 'd-a', 'status' => 'dispatched', 'dispatched_at' => '2026-10-05 14:50:00']); // atribuído pela central: conta pelo despacho
$pedidoDoDia(['driver_assigned_uuid' => 'd-a', 'started_at' => '2026-10-04 23:59:59', 'dispatched_at' => '2026-10-04 23:50:00']); // ontem
$pedidoDoDia(['driver_assigned_uuid' => 'd-a', 'status' => 'canceled', 'started_at' => '2026-10-05 10:00:00']);
$pedidoDoDia(['driver_assigned_uuid' => 'd-a', 'status' => 'expired', 'started_at' => '2026-10-05 10:00:00']);
$pedidoDoDia(['driver_assigned_uuid' => 'd-a', 'started_at' => '2026-10-05 10:00:00', 'deleted_at' => '2026-10-05 11:00:00']);
$pedidoDoDia(['driver_assigned_uuid' => 'd-b', 'status' => 'started', 'started_at' => '2026-10-05 00:00:00']);    // meia-noite em ponto: conta
$pedidoDoDia(['driver_assigned_uuid' => 'd-b', 'company_uuid' => 'outra', 'started_at' => '2026-10-05 10:00:00']);
$pedidoDoDia(['driver_assigned_uuid' => 'd-c', 'started_at' => '2026-10-05 10:00:00']);                           // não foi pedido
$pedidoDoDia(['driver_assigned_uuid' => 'd-b']);                                                                    // sem started_at nem dispatched_at
$corridas = Candidatos::corridasHoje('empresa-1', ['d-a', 'd-b']);
ksort($corridas);
confere($corridas === ['d-a' => 2, 'd-b' => 1], 'hoje em Brasília, sem cancelados, expirados, apagados, outra empresa e outro motoboy (' . json_encode($corridas) . ')');
confere(Candidatos::corridasHoje('empresa-1', []) === [], 'sem motoboys: vazio, sem consultar');

Banco::$falharAoConsultar['orders'] = new \RuntimeException('banco fora');
confere(Candidatos::corridasHoje('empresa-1', ['d-a']) === [], 'falha na consulta: vazio (todos com 0)');
confere(logou('não deu para contar as corridas de hoje', 'warning'), 'falha fica no log');

reiniciarIfood();
Candidatos::$buscarCorridasHoje = fn () => throw new \RuntimeException('falhou');
confere(Candidatos::corridasHoje('empresa-1', ['d-a']) === [] && logou('não deu para contar as corridas de hoje', 'warning'), 'falha da closure dos testes: vazio, com o log');

// elegiveis põe corridas_hoje em cada candidato, e para() leva o número para a fila
Candidatos::$buscarMotoboys = fn () => [
    ['motoboy' => motoboy('a', 'Ana', $coleta), 'posicao' => $coleta, 'distancia' => 0.0],
    ['motoboy' => motoboy('b', 'Bia', $coleta), 'posicao' => $coleta, 'distancia' => 0.0],
];
Candidatos::$buscarParadas      = fn () => [];
Candidatos::$buscarCorridasHoje = fn (string $empresa, array $uuids) => ['d-a' => 3];
$elegiveis = Candidatos::elegiveis($pedido, []);
confere(array_column($elegiveis, 'corridas_hoje') === [3, 0], 'elegiveis: corridas_hoje de cada candidato, 0 para quem não aparece');
$fila = (new FilaDeCandidatos($estimador))->para($pedido, $elegiveis);
confere(array_column($fila, 'public_id') === ['driver_b', 'driver_a'] && array_column($fila, 'corridas_hoje') === [0, 3], 'para: mesmo tempo, Bia (0 hoje) antes de Ana (3)');
Candidatos::$buscarMotoboys     = null;
Candidatos::$buscarParadas      = null;
Candidatos::$buscarCorridasHoje = null;
```

No topo do arquivo, junto dos outros `use`, acrescente `use Teste\Banco;`, se ainda não estiver lá.

- [ ] **Passo 2: rodar e ver falhar**

Rode o comando de `distribuicao-fila.php`. Esperado: erro de PHP `Call to undefined method ...Candidatos::corridasHoje()`
ou acesso a uma propriedade estática não declarada (`$buscarCorridasHoje`).

- [ ] **Passo 3: a consulta**

Em `Candidatos.php`:

1. Acrescente aos `use`: `use Illuminate\Support\Facades\DB;` e `use Illuminate\Support\Facades\Log;`.
2. No comentário da classe, antes de "As duas consultas ficam atrás de closures…", acrescente:

```php
 * Corridas hoje (corridasHoje): pedidos dele aceitos desde as 00:00 de Brasília, sem cancelados e expirados, para o
 * desempate da fila (FilaDeCandidatos::ordenar).
```

   e troque "As duas consultas" por "As três consultas".

3. Depois de `public static ?\Closure $buscarParadas = null;`:

```php
    /** @var null|\Closure(string, array<string>): array<string, int> */
    public static ?\Closure $buscarCorridasHoje = null;
```

4. No `elegiveis`, troque o bloco que vai de `$paradas = static::paradas(...)` até o `foreach` por:

```php
        $uuids    = array_map(fn ($c) => (string) $c['motoboy']->uuid, $motoboys);
        $paradas  = static::paradas((string) $pedido->company_uuid, $uuids);
        $corridas = static::corridasHoje((string) $pedido->company_uuid, $uuids);
        foreach ($motoboys as &$candidato) {
            $candidato['paradas']       = $paradas[(string) $candidato['motoboy']->uuid] ?? [];
            $candidato['corridas_hoje'] = $corridas[(string) $candidato['motoboy']->uuid] ?? 0;
        }
```

   No `@return` do `elegiveis`, acrescente `corridas_hoje: int` ao array shape.

5. Depois do método `paradas`, acrescente:

```php
    /**
     * Corridas de hoje por motoboy (só os que têm alguma): pedidos da empresa atribuídos a ele, não cancelados nem
     * expirados, com o aceite (started_at; sem ele, dispatched_at: atribuído pela central e não iniciado) desde as
     * 00:00 de hoje no fuso do app. As colunas são DATETIME em hora de Brasília: comparadas com o texto no fuso do app.
     * Falha: vazio (todos com 0, a fila fica só por tempo) e o log; nunca lança.
     *
     * @return array<string, int> uuid do motoboy => corridas
     */
    public static function corridasHoje(string $empresa, array $motoboyUuids): array
    {
        if ($motoboyUuids === []) {
            return [];
        }

        try {
            if (static::$buscarCorridasHoje) {
                return (static::$buscarCorridasHoje)($empresa, $motoboyUuids);
            }

            $inicio  = now()->format('Y-m-d') . ' 00:00:00';
            $pedidos = DB::table('orders')
                ->where('company_uuid', $empresa)
                ->whereIn('driver_assigned_uuid', $motoboyUuids)
                ->whereNull('deleted_at')
                ->whereNotIn('status', [...StatusDoPedido::CANCELADOS, 'expired'])
                ->where(fn ($q) => $q->where('started_at', '>=', $inicio)->orWhere('dispatched_at', '>=', $inicio))
                ->get(['driver_assigned_uuid', 'started_at', 'dispatched_at']);

            $corridas = [];
            foreach ($pedidos as $p) {
                // o COALESCE(started_at, dispatched_at): aceito ontem e redespachado hoje não conta
                $aceite = substr((string) ($p->started_at ?? $p->dispatched_at ?? ''), 0, 19);
                if ($aceite >= $inicio) {
                    $corridas[$p->driver_assigned_uuid] = ($corridas[$p->driver_assigned_uuid] ?? 0) + 1;
                }
            }

            return $corridas;
        } catch (\Throwable $e) {
            Log::warning('[entregas] distribuição: não deu para contar as corridas de hoje; fila só por tempo', ['erro' => get_class($e)]);

            return [];
        }
    }
```

- [ ] **Passo 4: rodar e ver passar**

Rode o comando de `distribuicao-fila.php`. Esperado: `FALHAS: 0`.

- [ ] **Passo 5: rodar os outros testes da distribuição**

Nesses testes, `$buscarCorridasHoje` é nulo, então a consulta real roda no banco em memória (tabela `orders` vazia):
todos ficam com 0, a ordem é a de antes e nenhum log é gravado.

```bash
for t in distribuicao-ciclo distribuicao-rodadas distribuicao-encaixe distribuicao-rotas filtrar-pedidos-abertos barrar-aceite avisos-push reenvio imports-dos-providers fuso; do
  echo "== $t"; PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php 2>&1 | tail -1
done
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/Distribuicao/Candidatos.php api/app/Support/Entregas/Distribuicao/FilaDeCandidatos.php api/app/Support/Entregas/Distribuicao/Distribuicao.php
```

Esperado: `FALHAS: 0` em todos, e o `sintaxe.mjs` saindo com 0. Se um teste falhar porque compara o `Banco::$consultadas` ou os logs inteiros, ajuste a
expectativa dele para incluir a consulta a `orders`. Não troque a consulta.

- [ ] **Passo 6: commit**

```bash
git add api/app/Support/Entregas/Distribuicao/Candidatos.php scripts/teste-php/distribuicao-fila.php
git commit -m "Distribuição: conta as corridas de hoje de cada candidato para o desempate

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 3: painel do console ("N corridas hoje" e "desempate")

**Arquivos:**
- Modificar: `packages/fleetops/addon/utils/distribuicao.js`
- Modificar: `packages/fleetops/addon/components/order/details/distribuicao.js` (`get fila()`, import)
- Modificar: `packages/fleetops/addon/components/order/details/distribuicao.hbs` (bloco da fila)
- Modificar: `packages/fleetops/translations/pt-br.yaml` e `en-us.yaml` (`fleet-ops.ui.distribuicao`)
- Testar: `scripts/teste-portal/distribuicao.test.mjs`

- [ ] **Passo 1: escrever o teste que falha**

Em `scripts/teste-portal/distribuicao.test.mjs`, acrescente `corridasHoje,` ao import (em ordem alfabética, depois de
`chaveDaResposta,`) e, no fim do arquivo:

```js
test('corridas de hoje de uma linha da fila: inteiro ≥ 0 ou null (fila de antes do desempate)', () => {
    assert.equal(corridasHoje({ corridas_hoje: 3 }), 3);
    assert.equal(corridasHoje({ corridas_hoje: 0 }), 0);
    assert.equal(corridasHoje({ corridas_hoje: '2' }), 2);
    assert.equal(corridasHoje({}), null);
    assert.equal(corridasHoje({ corridas_hoje: null }), null);
    assert.equal(corridasHoje({ corridas_hoje: -1 }), null);
    assert.equal(corridasHoje({ corridas_hoje: 1.5 }), null);
    assert.equal(corridasHoje(null), null);
});
```

- [ ] **Passo 2: rodar e ver falhar**

```bash
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/distribuicao.test.mjs
```

Esperado: falha de import (`corridasHoje` não exportado).

- [ ] **Passo 3: a função**

Em `packages/fleetops/addon/utils/distribuicao.js`, depois de `inteiroPositivo`:

```js
/** Corridas de hoje de uma linha da fila (desempate): inteiro ≥ 0, ou null quando a fila é de antes do desempate. */
export function corridasHoje(item) {
    const bruto = item?.corridas_hoje;
    if (bruto === null || bruto === undefined || bruto === '') {
        return null;
    }
    const valor = Number(bruto);

    return Number.isInteger(valor) && valor >= 0 ? valor : null;
}
```

- [ ] **Passo 4: rodar e ver passar**

Rode o comando do Passo 2. Esperado: todos os testes passam.

- [ ] **Passo 5: componente, template e traduções**

No `distribuicao.js` do componente, acrescente `corridasHoje,` ao import de `'../../../utils/distribuicao'` (depois de
`chaveDoMotivo,`) e troque o `get fila()` por:

```js
    get fila() {
        return (this.painel?.fila ?? []).map((item, index) => {
            const corridas = corridasHoje(item);

            return {
                posicao: index + 1,
                nome: item.nome || '—',
                tempo: this.tempoTexto(item.tempo_s),
                // com e sem rodadas (o servidor só limita o atraso: 5 min em rodadas, 10 min sem)
                encaixe: item.encaixe === true,
                aproximado: item.aproximado === true,
                livre: item.livre === true,
                // desempate por corridas do dia (2026-10-19); a fila gravada antes não traz as chaves
                temCorridas: corridas !== null,
                corridas,
                desempate: item.desempate === true,
            };
        });
    }
```

No `distribuicao.hbs`, depois da linha `{{#unless item.livre}}· {{t "fleet-ops.ui.distribuicao.ocupado"}}{{/unless}}`:

```hbs
                                        {{#if item.temCorridas}}· {{t "fleet-ops.ui.distribuicao.corridas-hoje" n=item.corridas}}{{/if}}
                                        {{#if item.desempate}}· {{t "fleet-ops.ui.distribuicao.desempate"}}{{/if}}
```

Em `translations/pt-br.yaml`, depois de `      ocupado: já leva pedido` (seção `distribuicao`):

```yaml
      corridas-hoje: '{n, plural, =0 {nenhuma corrida hoje} one {# corrida hoje} other {# corridas hoje}}'
      desempate: desempate
```

Em `translations/en-us.yaml`, depois de `            ocupado: already carrying an order` (mesma indentação):

```yaml
            corridas-hoje: '{n, plural, =0 {no rides today} one {# ride today} other {# rides today}}'
            desempate: tie-break
```

- [ ] **Passo 6: conferir as traduções e os testes**

```bash
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit $?"
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/distribuicao.test.mjs
```

Esperado: `exit 0` e todos os testes passando. Se o `i18n-check` reclamar de "desempate" igual nas duas línguas ou de
palavra em inglês, siga a orientação dele (`packages/CLAUDE.md`).

- [ ] **Passo 7: commit**

```bash
git add packages/fleetops/addon/utils/distribuicao.js packages/fleetops/addon/components/order/details/distribuicao.js packages/fleetops/addon/components/order/details/distribuicao.hbs packages/fleetops/translations/pt-br.yaml packages/fleetops/translations/en-us.yaml scripts/teste-portal/distribuicao.test.mjs
git commit -m "Painel da distribuição: corridas de hoje e a marca do desempate na fila

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 4: tela Sobre do app

**Repositório:** `entregas-navigator`, em worktree.

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator"
git worktree add /c/tmp/dcapp -b desempate-corridas main
cd /c/tmp/dcapp
```

**Arquivos:**
- Modificar: `translations/pt.json` e `translations/en.json` (`SobreScreen`, depois de `oferta1`)
- Modificar: `src/screens/SobreScreen.tsx` (passo `story.oferta`, ~linha 32)

- [ ] **Passo 1: as traduções**

Em `translations/pt.json`, na seção `SobreScreen`, logo depois da linha `"oferta1": ...`:

```json
        "ofertaDesempate": "Se dois ou mais motoboys chegariam ao cliente com até 3 minutos de diferença, a oferta vai primeiro para quem fez menos corridas hoje.",
```

Em `translations/en.json`, no mesmo lugar:

```json
        "ofertaDesempate": "If two or more couriers would reach the customer within 3 minutes of each other, the offer goes first to whoever has done the fewest rides today.",
```

Mantenha a indentação do arquivo.

- [ ] **Passo 2: o parágrafo na tela**

Em `src/screens/SobreScreen.tsx`, troque:

```tsx
        paragrafos: [{ chave: 'oferta1' }, { chave: 'oferta2', forte: true }, { chave: 'oferta3' }],
```

por:

```tsx
        paragrafos: [{ chave: 'oferta1' }, { chave: 'ofertaDesempate' }, { chave: 'oferta2', forte: true }, { chave: 'oferta3' }],
```

- [ ] **Passo 3: conferir**

```bash
node -e "for (const l of ['pt','en']) { const j = require('./translations/' + l + '.json'); if (!j.SobreScreen.ofertaDesempate) throw new Error(l); } console.log('ok')"
npx tsc --noEmit -p . 2>&1 | tail -5
```

Esperado: `ok`, e o `tsc` sem erro novo em `SobreScreen.tsx`. Se o projeto já tiver erros antigos, compare com
`git stash` para confirmar que nenhum é novo. Se houver teste de paridade das traduções em `scripts/testes/`, rode-o
também (`node --experimental-strip-types --test scripts/testes/<arquivo>.teste.ts`).

- [ ] **Passo 4: commit**

```bash
git add translations/pt.json translations/en.json src/screens/SobreScreen.tsx
git commit -m "Sobre: explica o desempate por corridas do dia na oferta

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 5: documentação da distribuição

O `api/app/Support/Entregas/Distribuicao/CLAUDE.md` **não está rastreado** na `main` (faz parte da reorganização dos
`CLAUDE.md` que o Edgard ainda não commitou) e por isso não existe na worktree. Edite-o **na pasta principal**
(`C:\Users\Edgardjr\Documents\vibe coding\Delivery`) e **não o commite**: ele entra no commit da reorganização.

- [ ] **Passo 1: "A fila"**

Troque a linha `- **Ordem:** menor tempo até o cliente. Empate: o livre primeiro, depois o \`public_id\`.` por:

```markdown
- **Ordem** (`FilaDeCandidatos::ordenar`, decisão do Edgard de 2026-10-19; spec `docs/superpowers/specs/2026-10-19-desempate-por-corridas-design.md`):
  - **desempate por corridas do dia:** quem entregaria o cliente em até **3 min** (`Distribuicao::DESEMPATE_CORRIDAS_S` = 180) do mais rápido forma um grupo ordenado por **menos corridas hoje** (empate: menor tempo, o livre, o `public_id`). O resto vem depois, pelo menor tempo, o livre e o `public_id`. A cada passo, a fila é refeita a partir do mais rápido que sobrou;
  - **corridas hoje** (`Candidatos::corridasHoje`, uma consulta para todos os candidatos): pedidos da empresa atribuídos a ele, sem cancelados (`StatusDoPedido::CANCELADOS`), `expired` e apagados, com o aceite (`started_at`; sem ele, `dispatched_at`) desde as 00:00 de Brasília. Em andamento e concluídos contam. Falha: todos com 0 (fila só por tempo) e o warning `não deu para contar as corridas de hoje; fila só por tempo`;
  - cada linha da fila gravada traz `corridas_hoje` e `desempate` (passou à frente de alguém mais rápido);
  - vale com e sem rodadas; em rodadas, o grupo é formado entre os candidatos da rodada. O cliente pode esperar até 3 min a mais.
```

- [ ] **Passo 2: "Painel do console"**

Em "Conteúdo", depois de `"≈" na linha reta)`, acrescente `, "N corridas hoje" e "desempate" (só na fila gravada depois
de 2026-10-19; funções puras: \`corridasHoje\`)`.

- [ ] **Passo 3: "Logs" e "Testes"**

Em "Logs", nas falhas, acrescente `` `não deu para contar as corridas de hoje; fila só por tempo` ``. Em "Testes" não
muda nada: os casos novos estão no `distribuicao-fila.php` e no `distribuicao.test.mjs`, que já estão listados.

- [ ] **Passo 4: APK**

Na seção "APK", acrescente o item: `- **Tela Sobre:** o parágrafo \`ofertaDesempate\` explica o desempate por corridas
do dia (ramo \`desempate-corridas\` do app). Se a regra mudar, revise o texto.`

---

## Integração (com o Edgard)

Sem push nem deploy sem ele. Depois das tarefas:

1. Merge do `desempate-corridas` na `main` do Delivery (fast-forward se a `main` não andou) e do app na `main` dele.
2. O push e o deploy ficam com o Edgard:
   - `bash deploy/atualizar.sh api`, sem migration;
   - `bash deploy/atualizar.sh console`;
   - o APK pelo GitHub Actions do app, só por causa da tela Sobre.
3. **A conferir no primeiro pedido real:** no painel "Distribuição", a fila mostra "N corridas hoje" coerente com o dia
   de cada motoboy, e a marca "desempate" aparece quando alguém mais lento, com menos corridas, passa à frente. Confira
   também se `docker service logs entregas_queue 2>&1 | grep 'corridas de hoje'` está vazio.
4. Remova as worktrees `/c/tmp/dc` e `/c/tmp/dcapp` depois do merge.
