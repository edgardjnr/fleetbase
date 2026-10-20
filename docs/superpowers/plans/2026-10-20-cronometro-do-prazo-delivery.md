# Cronômetro do prazo de entrega (Delivery) — plano de implementação

> **Para agentes:** SUB-SKILL OBRIGATÓRIA: use superpowers:subagent-driven-development (recomendado) ou superpowers:executing-plans para executar este plano tarefa a tarefa. Os passos usam caixas (`- [ ]`) para acompanhar.

**Objetivo:** cada pedido nasce com o prazo de entrega no `meta` (`entregas_prazo_em`), e o console (lista, detalhe e mapa ao vivo) e o portal da loja (card e mapa) mostram o cronômetro em verde, amarelo (15 min ou menos) e vermelho ("Atrasado há N min").

**Arquitetura:** uma regra só no servidor (`App\Support\Entregas\PrazoDaEntrega`), gravada na criação: o `CriadorDoPedidoIfood` grava o prazo do iFood antes do save e um hook `Order::creating` (`GravarPrazoDoPedido`) grava os 50 min padrão quando falta. O `meta` já sai no recurso v1 (app, portal) e no completo do console; o recurso enxuto das listas do console não traz o `meta`, então o middleware que já põe as notas nas listas passa a pôr também o prazo. O `PedidosNoMapa` devolve `prazo_em` nos três mapas (console, portal e Mapa do líder do app). Cada addon (Fleet-Ops e portal) tem a sua função pura `cronometro`, com os mesmos testes, e um serviço `entregas-relogio` que anda a cada 30 s.

**Tecnologias:** Laravel (PHP 8.2, testes em php-wasm), Ember (Glimmer, ember-leaflet, `Badge` do ember-ui), ember-intl, `node:test`.

**Spec:** `docs/superpowers/specs/2026-10-20-cronometro-do-prazo-design.md`. O app do motoboy tem plano próprio; este plano entrega o que o app usa: o `meta.entregas_prazo_em` no recurso do pedido e o `prazo_em` em `PedidosNoMapa::doLider`/`umDoLider`.

---

## Antes de começar (leia)

- **Onde trabalhar:** worktree `/c/tmp/cr`, ramo `cronometro-do-prazo`, a partir da `main` (commit `553b772c`, o da spec). A pasta principal (`C:\Users\Edgardjr\Documents\vibe coding\Delivery`) tem `CLAUDE.md` não rastreados e mudanças de outra pessoa (`CLAUDE.md` da raiz, `console/app/router.js`): **nunca commite nada da pasta principal**. Os `CLAUDE.md` de assunto (não rastreados) só existem na pasta principal; a Tarefa 13 os edita lá, **sem commit**.
- **Fuso:** o servidor roda no horário de Brasília. Nunca formate em UTC para gravar (`api/CLAUDE.md`, "Fuso"). O prazo sai sempre em ISO com `-03:00` (`date_default_timezone_get()`).
- **Testes PHP** (php-wasm, PHP 8.2), na raiz da worktree: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/<arquivo>.php`. Cada teste imprime `PASSA`/`FALHA` por caso e termina com `FALHAS: <n>`; sai com 1 se houver falha.
- **Testes do front:** `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/<arquivo>.test.mjs` (termina com `# fail 0`).
- **Tradução:** `node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal` precisa sair com 0. Na worktree ele precisa da junção `console/node_modules` → pasta principal (Tarefa 0). Remova a junção só com `cmd //c rmdir`, **nunca** com `rm -rf` (apagaria o `node_modules` da pasta principal).
- **Mensagens de commit** em português, terminando com a linha `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Arquivos

| Arquivo | Papel |
|---|---|
| Criar `api/app/Support/Entregas/PrazoDaEntrega.php` | A regra do prazo (padrão de 50 min, iFood), leitura do `meta` e formato ISO no fuso do app |
| Criar `api/app/Listeners/Entregas/GravarPrazoDoPedido.php` | Hook `Order::creating`: grava o padrão quando o `meta` não tem prazo legível |
| Modificar `api/app/Providers/AppServiceProvider.php` | Registra o hook (`gravarPrazoDosPedidos`) |
| Modificar `api/app/Support/Entregas/Ifood/PedidoDoIfood.php` | `mapear` devolve `prazo_em`; `data()` vira pública |
| Modificar `api/app/Support/Entregas/Ifood/CriadorDoPedidoIfood.php` | Grava o prazo no `meta` do `Order::create` |
| Modificar `api/app/Support/Entregas/PedidosNoMapa.php` | `prazo_em` em cada pedido (console, portal, líder) |
| Modificar `api/app/Http/Middleware/IncluirNotasNaListaDePedidos.php` | Põe também o prazo no `meta` do recurso enxuto das listas do console |
| Criar `scripts/teste-php/prazo-da-entrega.php` | Testes da regra, do hook e da ligação |
| Modificar `scripts/teste-php/ifood-pedido.php`, `ifood-criador.php`, `pedidos-no-mapa.php`, `notas-na-lista.php`, `imports-dos-providers.php` | Casos novos |
| Criar `packages/fleetops/addon/utils/entregas-cronometro.js` | Função pura `cronometro` do console |
| Criar `packages/customer-portal/addon/utils/entregas-cronometro.js` | Cópia da função no portal |
| Criar `scripts/teste-portal/cronometro.test.mjs` | Os mesmos casos nas duas cópias |
| Modificar `packages/fleetops/addon/utils/entregas-pedidos-no-mapa.js` e `packages/customer-portal/addon/utils/pedidos-no-mapa.js` | `classeDoRotulo` (cor do rótulo do alfinete) |
| Modificar `scripts/teste-portal/entregas-pedidos-no-mapa.test.mjs` e `pedidos-no-mapa.test.mjs` | Casos de `classeDoRotulo` |
| Modificar `packages/fleetops/addon/components/map/leaflet-live-map.{js,hbs}` e `packages/fleetops/addon/styles/fleetops-engine.css` | Rótulo do alfinete na cor do prazo (console) |
| Modificar `packages/customer-portal/addon/components/portal/order/workspace/map.{js,hbs}` e `packages/customer-portal/addon/styles/customer-portal-engine.css` | Rótulo do alfinete na cor do prazo (portal) e o estilo do card |
| Criar `packages/fleetops/addon/services/entregas-relogio.js` + `app/services/entregas-relogio.js` | Relógio de 30 s do console |
| Criar `packages/customer-portal/addon/services/entregas-relogio.js` + `app/services/entregas-relogio.js` | Relógio de 30 s do portal |
| Criar `packages/fleetops/addon/components/prazo-do-pedido.{js,hbs}`, `addon/components/cell/prazo-do-pedido.hbs` + re-exports em `app/components/` | Cronômetro do console (`Badge`) |
| Modificar `packages/fleetops/addon/controllers/operations/orders/index.js` | Coluna "Prazo" |
| Modificar `packages/fleetops/addon/components/order/panel-header.hbs` | Cronômetro ao lado do status no detalhe |
| Modificar `packages/fleetops/translations/{pt-br,en-us}.yaml` | `fleet-ops.ui.cronometro.*` |
| Modificar `packages/customer-portal/addon/components/portal/order/list-card.{js,hbs}` | Cronômetro no card (sai o `textoDoEta`) |
| Modificar `packages/customer-portal/addon/utils/entregas-pedido.js` e `scripts/teste-portal/card-do-pedido.test.mjs` | Sai o `textoDoEta` e o teste dele |
| Modificar `packages/customer-portal/translations/{pt-br,en-us}.yaml` | `customer-portal.ui.entregas.card.prazo*` |
| Documentação (pasta principal, sem commit) | `api/CLAUDE.md`, `api/app/Support/Entregas/Ifood/CLAUDE.md`, `packages/fleetops/CLAUDE.md`, `packages/customer-portal/CLAUDE.md`, `api/app/Http/Controllers/Entregas/CLAUDE.md`, `docs/historico.md` |

## Decisões tomadas neste plano (fora da letra da spec)

1. **A lista do console não recebia o `meta`.** A spec diz que o `meta` "já vai, sem código novo" ao console; vale para o detalhe, mas a lista (`GET int/v1/orders`) e o painel do mapa (`fleet-ops/live/orders`) usam o recurso enxuto `Index\Order`, cujo `meta` é só `{"_index_resource": true}`. O `IncluirNotasNaListaDePedidos` (que já põe as notas ali) passa a pôr o `entregas_prazo_em`, na mesma consulta. Sem isso, a coluna "Prazo" ficaria vazia.
2. **Texto traduzido:** a função pura devolve `texto` (como na spec), montado por um terceiro parâmetro `textos` (`{faltam(n), atrasado(n)}`); o padrão é o pt-BR. As telas passam o `intl.t`.
3. **Arredondamento (decisão do coordenador, 2026-10-20):** para cima nos dois lados. Enquanto falta, `ceil(restante em min)`; depois do prazo, `max(1, ceil(atraso em min))`: 0 s, 1 s e 60 s de atraso = "Atrasado há 1 min"; 61 s e 120 s = 2; 121 s = 3. Nunca "há 0 min". No instante exato do prazo, já é atrasado. O app segue a mesma regra.
10. **Formato gravado (decisão do coordenador):** o `meta.entregas_prazo_em` sai sempre em `Y-m-d\TH:i:sP` (ex.: `2026-10-20T19:57:51-03:00`), com a data já no fuso do app, sem microssegundos, para o Hermes do app ler sem surpresa. O php-wasm confere o formato.
4. **Cores = tokens do `Badge` do ember-ui:** `success` (verde), `warning` (amarelo) e `error` (vermelho; o ember-ui não tem `danger`). O `Badge` tem fundo escuro e texto claro nos dois temas. No mapa, o rótulo usa cores fixas equivalentes (verde-700, amarelo-400 com texto escuro, vermelho-600).
5. **Hook:** grava o padrão quando o `meta` não tem a chave **ou** tem um prazo ilegível; `meta` em texto solto (não JSON) fica como está, sem prazo.
6. **Rótulo do alfinete sem prazo** (pedido de antes do deploy): continua o vermelho-escuro de hoje.
7. **Coluna "Prazo" não ordena** (o prazo está num JSON; ordenar exigiria mudar o `queryRecord` do Fleet-Ops).
8. **Relógio do mapa:** o mapa do console relê a cada 20 s e o do portal a cada 5 s; o cronômetro do rótulo usa esse relógio (≤ 30 s). Lista, coluna e detalhe usam o serviço `entregas-relogio` (30 s e ao voltar para a aba).
9. **Nomes:** traduções em `fleet-ops.ui.cronometro.*` (já existem chaves `prazo` em outros ramos do YAML) e `customer-portal.ui.entregas.card.prazo*`.

---

### Tarefa 0: Worktree e linha de base

**Arquivos:** nenhum (preparo).

- [ ] **Passo 1: Criar a worktree a partir da `main`**

```bash
git -C "/c/Users/Edgardjr/Documents/vibe coding/Delivery" worktree add /c/tmp/cr -b cronometro-do-prazo main
git -C /c/tmp/cr rev-parse --show-toplevel
git -C /c/tmp/cr log --oneline -1
```

Esperado: `C:/tmp/cr` e `553b772c Spec: cronômetro do prazo de entrega ...`.

- [ ] **Passo 2: Junção do `console/node_modules` (para o i18n-check e o @babel/parser)**

No PowerShell:

```powershell
New-Item -ItemType Junction -Path 'C:\tmp\cr\console\node_modules' -Target 'C:\Users\Edgardjr\Documents\vibe coding\Delivery\console\node_modules'
```

Esperado: a junção listada (`Mode l----`). Ela é ignorada pelo git.

- [ ] **Passo 3: Rodar a linha de base**

```bash
cd /c/tmp/cr
for t in ifood-pedido ifood-criador pedidos-no-mapa notas-na-lista imports-dos-providers; do PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php | tail -1; done
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs 2>&1 | grep -E '^# (pass|fail)'
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal > /dev/null; echo "i18n: $?"
```

Esperado: cinco linhas `FALHAS: 0`, `# fail 0` e `i18n: 0`. Se algo falhar aqui, pare e avise: a falha é anterior a este plano.

---

### Tarefa 1: Regra do prazo (`PrazoDaEntrega`)

**Arquivos:**
- Criar: `api/app/Support/Entregas/PrazoDaEntrega.php`
- Modificar: `api/app/Support/Entregas/Ifood/PedidoDoIfood.php` (só a visibilidade de `data()`)
- Teste: criar `scripts/teste-php/prazo-da-entrega.php`

- [ ] **Passo 1: Escrever o teste que falha**

Crie `scripts/teste-php/prazo-da-entrega.php`:

```php
<?php

// Prazo de entrega (cronômetro): a regra do prazo (PrazoDaEntrega), o hook Order::creating (GravarPrazoDoPedido) e a
// ligação no AppServiceProvider. Spec: docs/superpowers/specs/2026-10-20-cronometro-do-prazo-design.md.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/prazo-da-entrega.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/fixtures-ifood.php';

use App\Support\Entregas\PrazoDaEntrega;
use Illuminate\Support\Carbon;

$brasilia = new DateTimeZone('America/Sao_Paulo');
$quinze   = new DateTimeImmutable('2026-10-05 15:00:00', $brasilia);

echo '== Padrão (RestaurantePro, portal, central, API): 50 min depois do max(criação, agendado)' . PHP_EOL;
confere(PrazoDaEntrega::padrao($quinze) === '2026-10-05T15:50:00-03:00', 'sem agendamento: criação + 50 min, no fuso de Brasília');
confere(PrazoDaEntrega::padrao($quinze, new DateTimeImmutable('2026-10-05 16:20:00', $brasilia)) === '2026-10-05T17:10:00-03:00', 'agendado depois da criação: conta do horário agendado');
confere(PrazoDaEntrega::padrao($quinze, new DateTimeImmutable('2026-10-05 14:00:00', $brasilia)) === '2026-10-05T15:50:00-03:00', 'agendado no passado: conta da criação');
confere(PrazoDaEntrega::padrao(new DateTimeImmutable('2026-10-05T18:00:00Z')) === '2026-10-05T15:50:00-03:00', 'criação em UTC sai em -03:00 (nunca UTC)');
$mutavel = Carbon::parse('2026-10-05 15:00:00');
PrazoDaEntrega::padrao($mutavel);
confere($mutavel->format('H:i') === '15:00', 'não altera o Carbon (mutável) de quem chamou');

echo '== Formato gravado: Y-m-d\TH:i:sP, sem microssegundos (o Hermes do app lê sem surpresa)' . PHP_EOL;
const FORMATO_GRAVADO = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/';
$comMicro = new DateTimeImmutable('2026-10-05 15:00:00.987654', $brasilia);
confere(PrazoDaEntrega::padrao($comMicro) === '2026-10-05T15:50:00-03:00', 'criação com microssegundos: eles não saem');
confere(preg_match(FORMATO_GRAVADO, PrazoDaEntrega::padrao($comMicro)) === 1, 'padrão no formato exato');
confere(PrazoDaEntrega::formatar(new DateTimeImmutable('2026-10-20T22:57:51.123456Z')) === '2026-10-20T19:57:51-03:00', 'formatar: UTC com fração vira -03:00 sem fração (o exemplo da spec)');
$comFracao                                 = pedidoEmDinheiroComTroco();
$comFracao['delivery']['deliveryDateTime'] = '2026-10-05T18:40:00.500Z';
confere(PrazoDaEntrega::doIfood($comFracao) === '2026-10-05T15:40:00-03:00', 'iFood com fração de segundo: sai sem a fração');
confere(preg_match(FORMATO_GRAVADO, PrazoDaEntrega::doIfood(pedidoAgendado())) === 1, 'iFood agendado no formato exato');

echo '== iFood' . PHP_EOL;
confere(PrazoDaEntrega::doIfood(pedidoEmDinheiroComTroco()) === '2026-10-05T15:40:00-03:00', 'imediato: o deliveryDateTime (18:40Z = 15:40 em Brasília)');
confere(PrazoDaEntrega::doIfood(pedidoDeTestePagoOnline()) === '2026-10-05T15:45:00-03:00', 'pedido de teste: o deliveryDateTime dele');
confere(PrazoDaEntrega::doIfood(pedidoAgendado()) === '2026-10-05T17:30:00-03:00', 'agendado: o fim da janela (20:30Z)');
$semFim = pedidoAgendado('2026-10-05T21:00:00.000Z');
unset($semFim['schedule']['deliveryDateTimeEnd']);
confere(PrazoDaEntrega::doIfood($semFim) === '2026-10-05T18:30:00-03:00', 'agendado sem o fim: início da janela + 30 min');
$fimIlegivel                                   = pedidoAgendado('2026-10-05T21:00:00.000Z');
$fimIlegivel['schedule']['deliveryDateTimeEnd'] = 'amanhã';
confere(PrazoDaEntrega::doIfood($fimIlegivel) === '2026-10-05T18:30:00-03:00', 'fim ilegível: vale como sem o fim');
$semJanela = pedidoAgendado();
unset($semJanela['schedule']);
confere(PrazoDaEntrega::doIfood($semJanela) === null, 'agendado sem janela: null (vale o padrão), mesmo com deliveryDateTime');
$comOffset                                 = pedidoEmDinheiroComTroco();
$comOffset['delivery']['deliveryDateTime'] = '2026-10-05T18:40:00+00:00';
confere(PrazoDaEntrega::doIfood($comOffset) === '2026-10-05T15:40:00-03:00', 'data com +00:00 também sai em -03:00');
foreach (['ausente' => null, 'texto' => 'tomorrow', 'só a data' => '2026-10-05', 'ano absurdo' => '1999-10-05T18:40:00Z', 'lista' => ['2026-10-05T18:40:00Z']] as $caso => $valor) {
    $pedido                             = pedidoEmDinheiroComTroco();
    $pedido['delivery']['deliveryDateTime'] = $valor;
    confere(PrazoDaEntrega::doIfood($pedido) === null, "imediato com data {$caso}: null (vale o padrão)");
}
confere(PrazoDaEntrega::doIfood([]) === null, 'pedido vazio: null');

echo '== Leitura do meta' . PHP_EOL;
$prazo = '2026-10-05T15:40:00-03:00';
confere(PrazoDaEntrega::daMeta([PrazoDaEntrega::CHAVE => $prazo, 'outra' => 1]) === $prazo, 'array (cast do Eloquent)');
confere(PrazoDaEntrega::daMeta(json_encode([PrazoDaEntrega::CHAVE => $prazo])) === $prazo, 'JSON da coluna (lista do console)');
confere(PrazoDaEntrega::daMeta((object) [PrazoDaEntrega::CHAVE => $prazo]) === $prazo, 'objeto');
confere(PrazoDaEntrega::daMeta([PrazoDaEntrega::CHAVE => ' ' . $prazo . ' ']) === $prazo, 'espaços em volta saem');
confere(PrazoDaEntrega::daMeta([PrazoDaEntrega::CHAVE => '2026-10-05T18:40:00Z']) === '2026-10-05T18:40:00Z', 'ISO com Z também é legível (os clientes convertem)');
foreach ([
    'nulo'               => null,
    'vazio'              => [],
    'texto que não é JSON' => 'meta solto',
    'número'             => 123,
    'prazo ilegível'     => [PrazoDaEntrega::CHAVE => 'amanhã'],
    'só a data'          => [PrazoDaEntrega::CHAVE => '2026-10-05'],
    'sem fuso'           => [PrazoDaEntrega::CHAVE => '2026-10-05 15:40:00'],
    'ano absurdo'        => [PrazoDaEntrega::CHAVE => '1999-10-05T15:40:00-03:00'],
    'prazo não texto'    => [PrazoDaEntrega::CHAVE => ['x']],
] as $caso => $meta) {
    confere(PrazoDaEntrega::daMeta($meta) === null, "meta {$caso}: null");
}

echo '== Prazo do pedido (model ou objeto com meta)' . PHP_EOL;
confere(PrazoDaEntrega::doPedido((object) ['meta' => [PrazoDaEntrega::CHAVE => $prazo]]) === $prazo, 'lê o meta do pedido');
confere(PrazoDaEntrega::doPedido(new stdClass()) === null, 'pedido sem meta (de antes do deploy): null');

class PedidoQueFalhaAoLer
{
    public function __isset($chave) { return true; }
    public function __get($chave) { throw new \RuntimeException('banco: SELECT segredo'); }
}
confere(PrazoDaEntrega::doPedido(new PedidoQueFalhaAoLer()) === null, 'falha ao ler o meta: null, sem lançar');

resumo();
```

- [ ] **Passo 2: Rodar e ver falhar**

```bash
cd /c/tmp/cr && PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/prazo-da-entrega.php
```

Esperado: erro fatal `Class "App\Support\Entregas\PrazoDaEntrega" not found` e saída 1.

- [ ] **Passo 3: Tornar pública a leitura de data do iFood**

Em `api/app/Support/Entregas/Ifood/PedidoDoIfood.php`, troque

```php
    /** Data ISO (com hora) em UTC, ano de 2000 a 2037; null para o resto ("tomorrow", texto, lista, ano absurdo). */
    protected static function data($valor): ?DateTimeImmutable
```

por

```php
    /**
     * Data ISO (com hora) em UTC, ano de 2000 a 2037; null para o resto ("tomorrow", texto, lista, ano absurdo). Pública:
     * o PrazoDaEntrega lê as datas do pedido do iFood com a mesma regra.
     */
    public static function data($valor): ?DateTimeImmutable
```

- [ ] **Passo 4: Criar `api/app/Support/Entregas/PrazoDaEntrega.php`**

```php
<?php

namespace App\Support\Entregas;

use App\Support\Entregas\Ifood\PedidoDoIfood;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Entregas RestaurantePro: o prazo de entrega do pedido, gravado na criação no `meta` (chave CHAVE), em texto ISO com o
 * fuso do app ("2026-10-20T19:57:51-03:00"). O console, o portal da loja e o app do motoboy mostram o cronômetro: verde
 * com mais de 15 min, amarelo com 15 min ou menos, vermelho "Atrasado há N min". Desenho:
 * docs/superpowers/specs/2026-10-20-cronometro-do-prazo-design.md.
 *
 * - iFood imediato: `delivery.deliveryDateTime`, a hora prometida ao cliente (na sonda de 05/10, createdAt + 45 min).
 * - iFood agendado: o fim da janela (`schedule.deliveryDateTimeEnd`); sem o fim, o início + 30 min; sem janela, o padrão.
 * - iFood sem data legível e todos os outros (RestaurantePro, portal da loja, central, API): o padrão, 50 min depois do
 *   max(criação, scheduled_at). O agendado pela central conta do horário agendado.
 *
 * Quem grava: o CriadorDoPedidoIfood (no Order::create, antes do save) e o GravarPrazoDoPedido (Order::creating: o padrão,
 * quando o meta ainda não tem um prazo legível). O prazo é o da criação: ORDER_PATCHED, CHANGE_PREPARATION_TIME e
 * reagendamento não o mudam. Nunca em UTC: a data do iFood (ISO com Z) sai no fuso do app (date_default_timezone_get(),
 * que o Laravel define pelo app.timezone). O prazo não é dado sensível: vai aos motoboys e ao socket.
 */
final class PrazoDaEntrega
{
    /** Chave do prazo no meta do pedido. */
    public const CHAVE = 'entregas_prazo_em';

    /** Prazo padrão: tantos minutos depois do max(criação, scheduled_at). */
    public const MINUTOS_PADRAO = 50;

    /** iFood agendado sem o fim da janela: o início dela mais tantos minutos. */
    public const MINUTOS_DEPOIS_DO_INICIO_DA_JANELA = 30;

    /** Prazo com ano fora deste intervalo é ilegível. */
    public const ANO_MINIMO = 2000;
    public const ANO_MAXIMO = 2037;

    /** O padrão: 50 min depois do max(criação, agendado para). */
    public static function padrao(DateTimeInterface $criacao, ?DateTimeInterface $agendadoPara = null): string
    {
        $base = DateTimeImmutable::createFromInterface($criacao);
        if ($agendadoPara !== null && $agendadoPara > $base) {
            $base = DateTimeImmutable::createFromInterface($agendadoPara);
        }

        return static::formatar($base->modify('+' . static::MINUTOS_PADRAO . ' minutes'));
    }

    /** O prazo do pedido do Logistics do iFood, ou null sem data legível (aí vale o padrão). */
    public static function doIfood(array $pedido): ?string
    {
        $programado = is_string($pedido['orderTiming'] ?? null) && strtoupper(trim($pedido['orderTiming'])) === 'SCHEDULED';
        if ($programado) {
            $janela = is_array($pedido['schedule'] ?? null) ? $pedido['schedule'] : [];
            $fim    = PedidoDoIfood::data($janela['deliveryDateTimeEnd'] ?? null);
            if ($fim !== null) {
                return static::formatar($fim);
            }
            $inicio = PedidoDoIfood::data($janela['deliveryDateTimeStart'] ?? null);

            return $inicio !== null ? static::formatar($inicio->modify('+' . static::MINUTOS_DEPOIS_DO_INICIO_DA_JANELA . ' minutes')) : null;
        }

        $entrega   = is_array($pedido['delivery'] ?? null) ? $pedido['delivery'] : [];
        $prometida = PedidoDoIfood::data($entrega['deliveryDateTime'] ?? null);

        return $prometida !== null ? static::formatar($prometida) : null;
    }

    /** O prazo legível guardado no meta (array do cast, objeto ou o JSON da coluna), ou null. */
    public static function daMeta($meta): ?string
    {
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }
        if (is_object($meta)) {
            $meta = (array) $meta;
        }
        $prazo = is_array($meta) ? ($meta[static::CHAVE] ?? null) : null;

        return static::legivel($prazo) ? trim($prazo) : null;
    }

    /** O prazo do pedido (model do Eloquent ou objeto com `meta`), ou null. Nunca lança. */
    public static function doPedido(object $pedido): ?string
    {
        try {
            return static::daMeta($pedido->meta ?? null);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Texto ISO com data, hora e fuso (Z ou ±hh:mm), ano de ANO_MINIMO a ANO_MAXIMO. */
    public static function legivel($prazo): bool
    {
        if (!is_string($prazo) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', trim($prazo))) {
            return false;
        }

        try {
            $ano = (int) (new DateTimeImmutable(trim($prazo)))->format('Y');
        } catch (\Exception) {
            return false;
        }

        return $ano >= static::ANO_MINIMO && $ano <= static::ANO_MAXIMO;
    }

    /** Formato gravado: exatamente Y-m-d\TH:i:sP, sem microssegundos (o Hermes do app lê sem surpresa). */
    public const FORMATO = 'Y-m-d\TH:i:sP';

    /** ISO 8601 no fuso do app (nunca UTC), sem fração de segundo: "2026-10-20T19:57:51-03:00". */
    public static function formatar(DateTimeInterface $data): string
    {
        return DateTimeImmutable::createFromInterface($data)
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format(static::FORMATO);
    }
}
```

- [ ] **Passo 5: Rodar e ver passar**

```bash
cd /c/tmp/cr && PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/prazo-da-entrega.php | tail -3
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-pedido.php | tail -1
```

Esperado: `FALHAS: 0` nos dois.

- [ ] **Passo 6: Commit**

```bash
cd /c/tmp/cr && git add api/app/Support/Entregas/PrazoDaEntrega.php api/app/Support/Entregas/Ifood/PedidoDoIfood.php scripts/teste-php/prazo-da-entrega.php
git commit -F - <<'EOF'
Prazo de entrega: regra única no servidor (PrazoDaEntrega)

iFood imediato pelo deliveryDateTime, agendado pelo fim da janela (ou início + 30 min), demais 50 min depois do
max(criação, scheduled_at); ISO no fuso de Brasília.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Tarefa 2: Hook `Order::creating` (`GravarPrazoDoPedido`)

**Arquivos:**
- Criar: `api/app/Listeners/Entregas/GravarPrazoDoPedido.php`
- Modificar: `api/app/Providers/AppServiceProvider.php`
- Modificar: `scripts/teste-php/imports-dos-providers.php`
- Teste: `scripts/teste-php/prazo-da-entrega.php` (casos novos)

- [ ] **Passo 1: Escrever os casos que falham**

Em `scripts/teste-php/prazo-da-entrega.php`, troque a linha `use Illuminate\Support\Carbon;` por

```php
use App\Listeners\Entregas\GravarPrazoDoPedido;
use Illuminate\Support\Carbon;
```

e insira, logo **antes** da última linha (`resumo();`):

```php
echo '== Hook Order::creating (GravarPrazoDoPedido)' . PHP_EOL;
reiniciarIfood(); // relógio em 2026-10-05 15:00 (Brasília) e log vazio

#[\AllowDynamicProperties]
class PedidoNovo
{
    public function __construct(public $meta = null, public $scheduled_at = null) {}
}

$pedido = new PedidoNovo();
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta === [PrazoDaEntrega::CHAVE => '2026-10-05T15:50:00-03:00'], 'pedido sem meta: ganha o padrão (agora + 50 min)');
confere(preg_match(FORMATO_GRAVADO, $pedido->meta[PrazoDaEntrega::CHAVE]) === 1, 'gravado em Y-m-d\TH:i:sP, sem microssegundos');

$pedido = new PedidoNovo(['origem' => 'portal']);
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta === ['origem' => 'portal', PrazoDaEntrega::CHAVE => '2026-10-05T15:50:00-03:00'], 'as outras chaves do meta ficam');

$pedido = new PedidoNovo(null, Carbon::parse('2026-10-05 16:20:00'));
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta[PrazoDaEntrega::CHAVE] === '2026-10-05T17:10:00-03:00', 'agendado (Carbon do cast): conta do horário agendado');

$pedido = new PedidoNovo(null, '2026-10-05 16:20:00');
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta[PrazoDaEntrega::CHAVE] === '2026-10-05T17:10:00-03:00', 'agendado em texto (hora de Brasília): conta do horário agendado');

$pedido = new PedidoNovo(null, '2026-10-05 14:00:00');
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta[PrazoDaEntrega::CHAVE] === '2026-10-05T15:50:00-03:00', 'agendado no passado: conta de agora');

$pedido = new PedidoNovo(null, 'qualquer coisa');
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta[PrazoDaEntrega::CHAVE] === '2026-10-05T15:50:00-03:00', 'scheduled_at ilegível: conta de agora');

$pedido = new PedidoNovo([PrazoDaEntrega::CHAVE => '2026-10-05T15:40:00-03:00']);
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta === [PrazoDaEntrega::CHAVE => '2026-10-05T15:40:00-03:00'], 'meta já com o prazo do iFood: não sobrescreve');

$pedido = new PedidoNovo([PrazoDaEntrega::CHAVE => 'amanhã', 'x' => 1]);
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta === [PrazoDaEntrega::CHAVE => '2026-10-05T15:50:00-03:00', 'x' => 1], 'prazo ilegível (cliente da API): troca pelo padrão');

$pedido = new PedidoNovo('');
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta === [PrazoDaEntrega::CHAVE => '2026-10-05T15:50:00-03:00'], 'meta texto vazio: ganha o padrão');

$pedido = new PedidoNovo('meta solto');
GravarPrazoDoPedido::aoCriar($pedido);
confere($pedido->meta === 'meta solto', 'meta em texto solto (não JSON): fica como está, sem prazo');

$erro = excecao(fn () => GravarPrazoDoPedido::aoCriar(new PedidoQueFalhaAoLer()));
confere($erro === null, 'falha ao ler o pedido: não lança (roda dentro da criação)');
confere(logou('[entregas] falha ao gravar o prazo do pedido', 'warning') && logsSem(['segredo', 'SELECT']), 'warning curto, só com a classe da exceção');

echo '== Ligação no AppServiceProvider' . PHP_EOL;
$provider = file_get_contents('/repo/api/app/Providers/AppServiceProvider.php');
confere(str_contains($provider, 'Order::creating(fn ($pedido) => GravarPrazoDoPedido::aoCriar($pedido));'), 'Order::creating → GravarPrazoDoPedido');
confere(str_contains($provider, '$this->gravarPrazoDosPedidos();') && str_contains($provider, 'use App\Listeners\Entregas\GravarPrazoDoPedido;'), 'chamado no boot, com o import');
```

- [ ] **Passo 2: Rodar e ver falhar**

```bash
cd /c/tmp/cr && PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/prazo-da-entrega.php | tail -5
```

Esperado: erro fatal `Class "App\Listeners\Entregas\GravarPrazoDoPedido" not found` e saída 1.

- [ ] **Passo 3: Criar `api/app/Listeners/Entregas/GravarPrazoDoPedido.php`**

```php
<?php

namespace App\Listeners\Entregas;

use App\Support\Entregas\PrazoDaEntrega;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: Order::creating → todo pedido novo nasce com o prazo de entrega no meta
 * (PrazoDaEntrega::CHAVE): 50 min depois do max(agora, scheduled_at). O pedido que já chega com um prazo legível (o do
 * iFood, gravado pelo CriadorDoPedidoIfood antes do save) fica como veio; um prazo ilegível é trocado pelo padrão.
 *
 * Vale para todo caminho que cria pelo Eloquent: portal da loja (o RegrasPortalLoja descarta o meta da requisição, e o
 * hook roda depois, no model), central (console), API v1 e a integração de outros sistemas (CriadorDoPedidoDaIntegracao).
 * O creating não dispara o updated: a distribuição e os observadores dos pedidos não percebem. Um insert pelo query
 * builder (ou um saveQuietly na criação) escapa.
 *
 * Meta em texto solto (não JSON) fica como está, sem prazo: o meta é de quem criou. Registrado no
 * AppServiceProvider::gravarPrazoDosPedidos. Nunca lança: roda dentro da criação do pedido; a falha vira o warning
 * `[entregas] falha ao gravar o prazo do pedido` (só a classe da exceção) e o pedido nasce sem prazo.
 */
class GravarPrazoDoPedido
{
    public static function aoCriar(object $pedido): void
    {
        try {
            $meta = $pedido->meta ?? null;
            if ($meta === null || $meta === '') {
                $meta = [];
            }
            if (!is_array($meta) || PrazoDaEntrega::daMeta($meta) !== null) {
                return;
            }

            $meta[PrazoDaEntrega::CHAVE] = PrazoDaEntrega::padrao(now(), static::agendadoPara($pedido->scheduled_at ?? null));
            $pedido->meta                = $meta;
        } catch (\Throwable $e) {
            try {
                Log::warning('[entregas] falha ao gravar o prazo do pedido', ['erro' => get_class($e)]);
            } catch (\Throwable) {
            }
        }
    }

    /** O scheduled_at como data: o Carbon do cast do Eloquent ou o texto 'Y-m-d H:i:s' no fuso do app; o resto, null. */
    protected static function agendadoPara($valor): ?\DateTimeInterface
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor;
        }
        if (!is_string($valor) || trim($valor) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable(trim($valor), new \DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            return null;
        }
    }
}
```

- [ ] **Passo 4: Registrar o hook no `AppServiceProvider`**

Em `api/app/Providers/AppServiceProvider.php`:

1. Depois de `use App\Http\Controllers\Entregas\DriverControllerSemGeocodificacao;`, acrescente a linha:

```php
use App\Listeners\Entregas\GravarPrazoDoPedido;
```

2. No `boot()`, troque

```php
        $this->manterPayloadDosPedidos();
        $this->lancarCorridasNoFinanceiro();
    }
```

por

```php
        $this->manterPayloadDosPedidos();
        $this->lancarCorridasNoFinanceiro();
        $this->gravarPrazoDosPedidos();
    }

    /**
     * Entregas: todo pedido novo nasce com o prazo de entrega no meta (entregas_prazo_em; 50 min depois da criação ou do
     * horário agendado). O do iFood já chega com o prazo do iFood e fica como veio (ver GravarPrazoDoPedido e
     * PrazoDaEntrega). O creating não dispara o updated: os observadores dos pedidos não percebem.
     */
    protected function gravarPrazoDosPedidos(): void
    {
        Order::creating(fn ($pedido) => GravarPrazoDoPedido::aoCriar($pedido));
    }
```

(O `aoCriar` devolve `void`: a arrow function devolve `null`, e só `false` cancelaria a criação.)

- [ ] **Passo 5: Incluir o arquivo novo no teste de imports**

Em `scripts/teste-php/imports-dos-providers.php`, troque

```php
        $app . '/Notifications/Entregas/AvisosDoMotoboy.php',
    ]
);
```

por

```php
        $app . '/Notifications/Entregas/AvisosDoMotoboy.php',
        // prazo de entrega (cronômetro); o GravarPrazoDoPedido entra pelo glob de Listeners/Entregas
        $app . '/Support/Entregas/PrazoDaEntrega.php',
        $app . '/Support/Entregas/PedidosNoMapa.php',
        $app . '/Support/Entregas/Ifood/PedidoDoIfood.php',
        $app . '/Support/Entregas/Ifood/CriadorDoPedidoIfood.php',
        $app . '/Http/Middleware/IncluirNotasNaListaDePedidos.php',
    ]
);
```

- [ ] **Passo 6: Rodar e ver passar**

```bash
cd /c/tmp/cr
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/prazo-da-entrega.php | tail -1
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/imports-dos-providers.php | grep -E 'GravarPrazo|PrazoDaEntrega|FALHA'
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Listeners/Entregas/GravarPrazoDoPedido.php api/app/Support/Entregas/PrazoDaEntrega.php api/app/Providers/AppServiceProvider.php
```

Esperado: `FALHAS: 0`; `PASSA api/app/Listeners/Entregas/GravarPrazoDoPedido.php`, `PASSA api/app/Support/Entregas/PrazoDaEntrega.php` e `FALHAS: 0`; a sintaxe sem erro (saída 0).

- [ ] **Passo 7: Commit**

```bash
cd /c/tmp/cr && git add api/app/Listeners/Entregas/GravarPrazoDoPedido.php api/app/Providers/AppServiceProvider.php scripts/teste-php/prazo-da-entrega.php scripts/teste-php/imports-dos-providers.php
git commit -F - <<'EOF'
Prazo de entrega: hook Order::creating grava o padrão de 50 min no meta

Não sobrescreve o prazo legível (o do iFood); nunca lança dentro da criação.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Tarefa 3: Pedido do iFood grava o prazo dele

**Arquivos:**
- Modificar: `api/app/Support/Entregas/Ifood/PedidoDoIfood.php`
- Modificar: `api/app/Support/Entregas/Ifood/CriadorDoPedidoIfood.php`
- Teste: `scripts/teste-php/ifood-pedido.php`, `scripts/teste-php/ifood-criador.php`

- [ ] **Passo 1: Escrever os casos que falham**

Em `scripts/teste-php/ifood-pedido.php`, insira logo **antes** da última linha (`resumo();`):

```php
echo '== Prazo de entrega (PrazoDaEntrega, vai no meta do pedido)' . PHP_EOL;
confere(PedidoDoIfood::mapear(pedidoEmDinheiroComTroco(), LAT_COLETA, LNG_COLETA, $agora)['prazo_em'] === '2026-10-05T15:40:00-03:00', 'imediato: o deliveryDateTime do iFood, em hora de Brasília');
confere(PedidoDoIfood::mapear(pedidoAgendado(), LAT_COLETA, LNG_COLETA, $agora)['prazo_em'] === '2026-10-05T17:30:00-03:00', 'agendado: o fim da janela');
$prazoSemData = pedidoEmDinheiroComTroco();
unset($prazoSemData['delivery']['deliveryDateTime']);
confere(PedidoDoIfood::mapear($prazoSemData, LAT_COLETA, LNG_COLETA, $agora)['prazo_em'] === '2026-10-05T15:50:00-03:00', 'sem data legível: o padrão (agora + 50 min)');
$prazoSemJanela = pedidoAgendado();
unset($prazoSemJanela['schedule']);
confere(PedidoDoIfood::mapear($prazoSemJanela, LAT_COLETA, LNG_COLETA, $agora)['prazo_em'] === '2026-10-05T15:50:00-03:00', 'agendado sem janela: o padrão');
```

Em `scripts/teste-php/ifood-criador.php`:

1. Troque

```php
confere(logou('[entregas] ifood: pedido criado', 'info') && logsSem(['Cliente Ficticio', 'CLIENTE FICTICIO', '0800 000 0002', 'Rua Ficticia', '33334444']), 'log sem nome, telefone, endereço nem localizador');
```

por

```php
confere(logou('[entregas] ifood: pedido criado', 'info') && logsSem(['Cliente Ficticio', 'CLIENTE FICTICIO', '0800 000 0002', 'Rua Ficticia', '33334444']), 'log sem nome, telefone, endereço nem localizador');
confere($pedido->meta === ['entregas_prazo_em' => '2026-10-05T15:40:00-03:00'], 'prazo do iFood no meta, já no create (o hook do creating não sobrescreve)');
```

2. Troque

```php
confere($pedido->adhocAoCriar === true, 'nasce adhoc: o fleetops:dispatch-orders despacha o agendado sem ligar o adhoc');
```

por

```php
confere($pedido->adhocAoCriar === true, 'nasce adhoc: o fleetops:dispatch-orders despacha o agendado sem ligar o adhoc');
confere($pedido->meta === ['entregas_prazo_em' => '2026-10-05T17:30:00-03:00'], 'agendado: prazo = fim da janela');
```

3. Troque

```php
confere($pedido->adhocAoCriar === false && $pedido->adhoc === false, 'adhoc falso: a central atribui');

echo '== Sem coordenadas de entrega: não nasce adhoc' . PHP_EOL;
```

por

```php
confere($pedido->adhocAoCriar === false && $pedido->adhoc === false, 'adhoc falso: a central atribui');
confere($pedido->meta === ['entregas_prazo_em' => '2026-10-05T15:50:00-03:00'], 'agendado sem janela: o prazo padrão (agora + 50 min)');

echo '== Sem coordenadas de entrega: não nasce adhoc' . PHP_EOL;
```

- [ ] **Passo 2: Rodar e ver falhar**

```bash
cd /c/tmp/cr
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-pedido.php | grep -E 'FALHA|Prazo'
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-criador.php | grep -E 'FALHA'
```

Esperado: `FALHA` nos quatro casos de prazo de `ifood-pedido.php` (com aviso de chave indefinida `prazo_em`) e nos três de `ifood-criador.php`; `FALHAS: 4` e `FALHAS: 3`.

- [ ] **Passo 3: `mapear` devolve o prazo**

Em `api/app/Support/Entregas/Ifood/PedidoDoIfood.php`:

1. Troque `use App\Support\Entregas\ItensDoPedido;` por

```php
use App\Support\Entregas\ItensDoPedido;
use App\Support\Entregas\PrazoDaEntrega;
```

2. No docblock da classe, troque

```php
 * - Produtos (`items`): `itens` no formato do app (ItensDoPedido), na ordem do `index`, e o JSON na linha (itens()).
```

por

```php
 * - Produtos (`items`): `itens` no formato do app (ItensDoPedido), na ordem do `index`, e o JSON na linha (itens()).
 * - Prazo de entrega (`prazo_em`, PrazoDaEntrega::doIfood: imediato = deliveryDateTime; agendado = fim da janela ou
 *   início + 30 min; sem data legível, o padrão de 50 min): vai no meta do Order (CriadorDoPedidoIfood).
```

3. No `return` do `mapear`, troque

```php
            'notas'           => 'iFood #' . $numero . $marcas,
            'itens'           => $itens,
```

por

```php
            'notas'           => 'iFood #' . $numero . $marcas,
            'prazo_em'        => PrazoDaEntrega::doIfood($pedido) ?? PrazoDaEntrega::padrao($agora),
            'itens'           => $itens,
```

- [ ] **Passo 4: O criador grava o prazo no `meta`**

Em `api/app/Support/Entregas/Ifood/CriadorDoPedidoIfood.php`:

1. Troque `use App\Support\Entregas\Coordenada;` por

```php
use App\Support\Entregas\Coordenada;
use App\Support\Entregas\PrazoDaEntrega;
```

2. No docblock da classe, troque

```php
 * - Order tipo transport, status created, internal_id = número do iFood, notas "iFood #4821";
```

por

```php
 * - Order tipo transport, status created, internal_id = número do iFood, notas "iFood #4821" e o prazo de entrega do
 *   iFood no meta (PrazoDaEntrega::CHAVE), já no create: o hook do Order::creating (GravarPrazoDoPedido) não o sobrescreve;
```

3. No `Order::create`, troque

```php
                'scheduled_at'      => $dados['scheduled_at'],
                'notes'             => $dados['notas'],
            ]);
```

por

```php
                'scheduled_at'      => $dados['scheduled_at'],
                'notes'             => $dados['notas'],
                // o prazo do iFood (hora prometida ao cliente ou fim da janela) antes do save; ver PrazoDaEntrega
                'meta'              => [PrazoDaEntrega::CHAVE => $dados['prazo_em']],
            ]);
```

- [ ] **Passo 5: Rodar e ver passar (com os testes que usam o `mapear` e o criador)**

```bash
cd /c/tmp/cr
for t in ifood-pedido ifood-criador ifood-processar ifood-agendador fila-ifood fuso; do echo "$t: $(PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php | tail -1)"; done
```

Esperado: `FALHAS: 0` nos seis.

- [ ] **Passo 6: Commit**

```bash
cd /c/tmp/cr && git add api/app/Support/Entregas/Ifood/PedidoDoIfood.php api/app/Support/Entregas/Ifood/CriadorDoPedidoIfood.php scripts/teste-php/ifood-pedido.php scripts/teste-php/ifood-criador.php
git commit -F - <<'EOF'
Prazo de entrega: pedido do iFood nasce com o prazo do iFood no meta

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Tarefa 4: `prazo_em` nos pedidos do mapa (console, portal e Mapa do líder)

**Arquivos:**
- Modificar: `api/app/Support/Entregas/PedidosNoMapa.php`
- Teste: `scripts/teste-php/pedidos-no-mapa.php`

- [ ] **Passo 1: Escrever os casos que falham**

Em `scripts/teste-php/pedidos-no-mapa.php`:

1. Troque

```php
    require '/repo/api/app/Support/Entregas/Coordenada.php';
    require '/repo/api/app/Support/Entregas/PedidosNoMapa.php';
```

por

```php
    require '/repo/api/app/Support/Entregas/Coordenada.php';
    require '/repo/api/app/Support/Entregas/PrazoDaEntrega.php';
    require '/repo/api/app/Support/Entregas/PedidosNoMapa.php';
```

2. Troque as duas linhas dos pedidos `order_coleta` e `order_entrega` em `Order::$todos`

```php
        pedido('order_coleta', 'started', 'vendor-b', $recente, '2026-10-05 08:10:00', lugar(-21.19, -47.82, 'Rua B, 20'), ['driverAssigned' => $motoboy, 'started' => true]),
        pedido('order_entrega', 'enroute', 'contato-a', $recente, '2026-10-05 08:20:00', lugar(-21.2, -47.83, null, 'Casa do cliente'), ['driverAssigned' => $motoboy, 'started' => true]),
```

por

```php
        pedido('order_coleta', 'started', 'vendor-b', $recente, '2026-10-05 08:10:00', lugar(-21.19, -47.82, 'Rua B, 20'), ['driverAssigned' => $motoboy, 'started' => true, 'meta' => ['entregas_prazo_em' => '2026-10-05T09:20:00-03:00']]),
        pedido('order_entrega', 'enroute', 'contato-a', $recente, '2026-10-05 08:20:00', lugar(-21.2, -47.83, null, 'Casa do cliente'), ['driverAssigned' => $motoboy, 'started' => true, 'meta' => ['entregas_prazo_em' => 'amanhã']]),
```

3. Troque

```php
    confere(array_keys($central[0] ?? []) === ['id', 'numero', 'latitude', 'longitude', 'endereco', 'status', 'motoboy', 'aceito', 'criado_em', 'loja'],
        'campos do console, com a loja');
```

por

```php
    confere(array_keys($central[0] ?? []) === ['id', 'numero', 'latitude', 'longitude', 'endereco', 'status', 'motoboy', 'aceito', 'criado_em', 'prazo_em', 'loja'],
        'campos do console, com o prazo e a loja');
    confere(($porId['order_coleta']['prazo_em'] ?? null) === '2026-10-05T09:20:00-03:00', 'prazo_em = o entregas_prazo_em do meta');
    confere(array_key_exists('prazo_em', $porId['order_sem_motoboy'] ?? []) && $porId['order_sem_motoboy']['prazo_em'] === null && $porId['order_entrega']['prazo_em'] === null,
        'sem prazo (pedido de antes do deploy) ou prazo ilegível: null');
```

4. Troque

```php
    confere(array_keys($loja[0] ?? []) === ['id', 'numero', 'latitude', 'longitude', 'endereco', 'status', 'motoboy', 'aceito', 'criado_em'],
        'campos do portal, sem a loja');
```

por

```php
    confere(array_keys($loja[0] ?? []) === ['id', 'numero', 'latitude', 'longitude', 'endereco', 'status', 'motoboy', 'aceito', 'criado_em', 'prazo_em'],
        'campos do portal, com o prazo e sem a loja');
    confere(array_column($loja, 'prazo_em', 'id') === ['order_entrega' => null, 'order_sem_motoboy' => null], 'portal: prazo ilegível ou ausente = null');
```

5. Troque

```php
    confere(array_keys($lider[0] ?? []) === ['id', 'numero', 'latitude', 'longitude', 'endereco', 'status', 'motoboy', 'aceito', 'criado_em', 'loja', 'motoboy_id', 'atualizado_em', 'coleta'],
        'campos da central, mais motoboy_id, atualizado_em e coleta');
```

por

```php
    confere(array_keys($lider[0] ?? []) === ['id', 'numero', 'latitude', 'longitude', 'endereco', 'status', 'motoboy', 'aceito', 'criado_em', 'prazo_em', 'loja', 'motoboy_id', 'atualizado_em', 'coleta'],
        'campos da central, mais motoboy_id, atualizado_em e coleta');
    confere(($doLider['order_ifood']['prazo_em'] ?? null) === '2026-10-05T09:15:00-03:00', 'líder: o prazo do pedido (cor do rótulo no Mapa do líder do app)');
```

6. No pedido `order_ifood`, troque

```php
        'payload' => (object) ['dropoff' => $rua, 'pickup' => $lojaA],
    ]);
```

por

```php
        'payload' => (object) ['dropoff' => $rua, 'pickup' => $lojaA],
        'meta' => ['entregas_prazo_em' => '2026-10-05T09:15:00-03:00', 'entregas' => ['km_rota' => ['metros' => 1200]]],
    ]);
```

7. Troque

```php
    confere(array_keys($lojaComIfood['order_ifood'] ?? []) === ['id', 'numero', 'latitude', 'longitude', 'endereco', 'status', 'motoboy', 'aceito', 'criado_em'],
        'loja: os mesmos campos, sem a loja');
```

por

```php
    confere(array_keys($lojaComIfood['order_ifood'] ?? []) === ['id', 'numero', 'latitude', 'longitude', 'endereco', 'status', 'motoboy', 'aceito', 'criado_em', 'prazo_em'],
        'loja: os mesmos campos, sem a loja');
    confere(($centralComIfood['order_ifood']['prazo_em'] ?? null) === '2026-10-05T09:15:00-03:00' && ($lojaComIfood['order_ifood']['prazo_em'] ?? null) === '2026-10-05T09:15:00-03:00',
        'central e loja: o mesmo prazo (não é dado sensível); o resto do meta não sai');
    confere(!str_contains(json_encode($lojaComIfood), 'km_rota'), 'do meta, só o prazo vai ao mapa');
```

- [ ] **Passo 2: Rodar e ver falhar**

```bash
cd /c/tmp/cr && PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/pedidos-no-mapa.php | grep -E 'FALHA'
```

Esperado: `FALHA` nos casos de campos e de prazo; `FALHAS:` maior que 0.

- [ ] **Passo 3: Incluir o `prazo_em` no `PedidosNoMapa`**

Em `api/app/Support/Entregas/PedidosNoMapa.php`:

1. No docblock da classe, troque

```php
 * Em andamento = status fora de StatusDoPedido::ENCERRADOS e atualizado nas últimas
```

por

```php
 * Prazo (nos três): `prazo_em`, o prazo de entrega gravado no meta (PrazoDaEntrega::doPedido; null sem prazo legível).
 * O rótulo "#4821 · Carlos" do alfinete ganha a cor do cronômetro (verde, amarelo, vermelho). Do meta, só o prazo sai.
 *
 * Em andamento = status fora de StatusDoPedido::ENCERRADOS e atualizado nas últimas
```

2. Nos três `@return` (daCentral, daLoja e doLider), troque `criado_em: ?string` por `criado_em: ?string, prazo_em: ?string` (são três ocorrências; use substituir todas).

3. No `itens()`, troque

```php
                'criado_em' => $pedido->created_at?->toIso8601String(),
            ], $extra ? $extra($pedido) : []);
```

por

```php
                'criado_em' => $pedido->created_at?->toIso8601String(),
                // prazo de entrega (cronômetro; ver PrazoDaEntrega): a cor do rótulo do alfinete; null sem prazo
                'prazo_em'  => PrazoDaEntrega::doPedido($pedido),
            ], $extra ? $extra($pedido) : []);
```

(`PrazoDaEntrega` está no mesmo namespace: sem `use`.)

- [ ] **Passo 4: Rodar e ver passar (com os testes que usam o PedidosNoMapa)**

```bash
cd /c/tmp/cr
for t in pedidos-no-mapa mapa mapa-da-loja lider; do echo "$t: $(PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php | tail -1)"; done
```

Esperado: `FALHAS: 0` nos quatro.

- [ ] **Passo 5: Commit**

```bash
cd /c/tmp/cr && git add api/app/Support/Entregas/PedidosNoMapa.php scripts/teste-php/pedidos-no-mapa.php
git commit -F - <<'EOF'
Prazo de entrega: prazo_em nos pedidos do mapa (console, portal e Mapa do líder)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Tarefa 5: Prazo no `meta` das listas do console (recurso enxuto)

O recurso `Index\Order` do Fleet-Ops (lista `GET int/v1/orders` e painel `GET int/v1/fleet-ops/live/orders`) troca o `meta` por `{"_index_resource": true}`. O `IncluirNotasNaListaDePedidos` passa a pôr ali o `entregas_prazo_em`, na mesma consulta das notas.

**Arquivos:**
- Modificar (reescrever): `api/app/Http/Middleware/IncluirNotasNaListaDePedidos.php`
- Teste: `scripts/teste-php/notas-na-lista.php`

- [ ] **Passo 1: Escrever os casos que falham**

Em `scripts/teste-php/notas-na-lista.php`:

1. Troque

```php
rodar(CONSOLE . 'queryRecord', new JsonResponse(['orders' => [item('order-1', '4821') + ['notes' => 'x']]]));
confere(consultas() === 0, 'todos os itens já com notes: não consulta');
```

por

```php
$completo         = item('order-1', '4821') + ['notes' => 'x'];
$completo['meta'] = ['_index_resource' => true, 'entregas_prazo_em' => '2026-10-05T15:40:00-03:00'];
rodar(CONSOLE . 'queryRecord', new JsonResponse(['orders' => [$completo]]));
confere(consultas() === 0, 'todos os itens já com notes e prazo: não consulta');
```

2. Insira logo **antes** da última linha (`resumo();`):

```php
echo '== Prazo de entrega no meta do recurso enxuto' . PHP_EOL;
cenario();
Banco::inserir('orders', ['uuid' => 'order-p1', 'company_uuid' => 'empresa-1', 'internal_id' => '4822', 'notes' => 'iFood #4822', 'meta' => json_encode(['entregas_prazo_em' => '2026-10-05T15:40:00-03:00', 'entregas' => ['km_rota' => ['metros' => 1200]]])], false);
Banco::inserir('orders', ['uuid' => 'order-p2', 'company_uuid' => 'empresa-1', 'internal_id' => null, 'notes' => null, 'meta' => json_encode(['entregas_prazo_em' => 'amanhã'])], false);
Banco::inserir('orders', ['uuid' => 'order-p3', 'company_uuid' => 'empresa-2', 'internal_id' => null, 'notes' => null, 'meta' => json_encode(['entregas_prazo_em' => '2026-10-05T15:40:00-03:00'])], false);
Banco::$consultadas = [];
$resposta = rodar(CONSOLE . 'queryRecord', new JsonResponse(['orders' => [item('order-p1', '4822'), item('order-p2'), item('order-p3'), item('order-1', '4821')]]));
$lista    = $resposta->getData(true)['orders'];
confere($lista[0]['meta'] === ['_index_resource' => true, 'entregas_prazo_em' => '2026-10-05T15:40:00-03:00'], 'pedido com prazo: o prazo entra no meta do item (o resto do meta do banco não vai)');
confere($lista[0]['notes'] === 'iFood #4822', 'as notas continuam chegando junto');
confere($lista[1]['meta'] === ['_index_resource' => true], 'prazo ilegível no banco: o meta fica como veio');
confere($lista[2]['meta'] === ['_index_resource' => true], 'pedido de OUTRA empresa: sem prazo');
confere($lista[3]['meta'] === ['_index_resource' => true] && $lista[3]['notes'] === 'iFood #4821', 'pedido sem meta no banco: só as notas');
confere(consultas() === 1, 'notas e prazo na MESMA consulta');
$resposta = rodar(AO_VIVO, new JsonResponse(['data' => [item('order-p1', '4822')]]));
confere(($resposta->getData(true)['data'][0]['meta']['entregas_prazo_em'] ?? null) === '2026-10-05T15:40:00-03:00', 'painel do mapa ({"data": [...]}): também recebe o prazo');
$semMeta = item('order-p1', '4822');
unset($semMeta['meta']);
$resposta = rodar(CONSOLE . 'queryRecord', new JsonResponse(['orders' => [$semMeta]]));
confere($resposta->getData(true)['orders'][0]['meta'] === ['entregas_prazo_em' => '2026-10-05T15:40:00-03:00'], 'item sem meta: ganha um, só com o prazo');
$jaComPrazo                               = item('order-p1', '4822');
$jaComPrazo['meta']['entregas_prazo_em'] = '2026-10-05T16:00:00-03:00';
$resposta                                 = rodar(CONSOLE . 'queryRecord', new JsonResponse(['orders' => [$jaComPrazo]]));
confere($resposta->getData(true)['orders'][0]['meta']['entregas_prazo_em'] === '2026-10-05T16:00:00-03:00' && $resposta->getData(true)['orders'][0]['notes'] === 'iFood #4822',
    'item que já traz o prazo: fica como veio (as notas que faltam chegam)');
```

- [ ] **Passo 2: Rodar e ver falhar**

```bash
cd /c/tmp/cr && PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/notas-na-lista.php | grep -E 'FALHA'
```

Esperado: `FALHA` nos casos de prazo (o caso "todos os itens já com notes e prazo" passa: o código atual também não consulta).

- [ ] **Passo 3: Reescrever `api/app/Http/Middleware/IncluirNotasNaListaDePedidos.php`**

Conteúdo completo:

```php
<?php

namespace App\Http\Middleware;

use App\Support\Entregas\PrazoDaEntrega;
use Closure;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\LiveController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController as OrderDoConsole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: põe o `notes` e o prazo de entrega (`meta.entregas_prazo_em`) nos itens das listas de pedidos
 * do console.
 *
 * - Notas: o selo "iFood #4821" do console (tabela, quadro e painel do mapa) reconhece o pedido do iFood pelas notas
 *   "iFood #N" mais o internal_id igual a N (CriadorDoPedidoIfood).
 * - Prazo: a coluna "Prazo" da tabela (cronômetro; PrazoDaEntrega) lê o `meta.entregas_prazo_em`.
 *
 * As listas usam o recurso enxuto do Fleet-Ops (Resources\v1\Index\Order), que traz o `internal_id` mas não o `notes`, e
 * cujo `meta` é só `{"_index_resource": true}`. O código do Fleet-Ops vem do Composer (mudança em packages/*\/server não
 * chega à produção), então o acréscimo é feito aqui, na resposta, com uma consulta só por lista (uuid, notes e meta). Do
 * meta do banco, só o prazo legível vai para o item; o resto (o km da rota, por exemplo) continua só no detalhe.
 *
 * Só em GET e só nas duas rotas de lista do console (o grupo fleetbase.protected, como o RegrasDoPedidoIfood):
 *  - GET int/v1/orders (OrderController@queryRecord, a lista da tabela e do quadro; com `?single` devolve um pedido
 *    completo, que já traz as notas e o meta, e fica de fora);
 *  - GET int/v1/fleet-ops/live/orders (LiveController@orders, o painel de pedidos do mapa; o resultado em cache é o
 *    recurso, não a resposta, então o acréscimo não vai para o cache).
 * O detalhe (int/v1/orders/{id}) usa o recurso completo e não é tocado.
 *
 * Formato das respostas (JSON 200): a lista dos pedidos vem em `{"orders": [...]}` (queryRecord, com `links` e `meta`
 * da paginação ao lado), em `{"data": [...]}` (o padrão do recurso, caso o nome do envelope não esteja definido no
 * processo) ou como array na raiz. Os três são tratados. Cada item tem `uuid` (requisição interna).
 *
 * Item que já traz o `notes` ou o prazo fica com o que trouxe. O filtro da empresa é explícito (nenhum model registra o
 * CompanyScope nesta versão) e o item só recebe nota e prazo lidos da empresa da sessão. Nunca lança: qualquer falha vira
 * um warning curto (só a classe da exceção) e a resposta original segue. Lista vazia, itens completos ou sem empresa na
 * sessão não consultam o banco.
 */
class IncluirNotasNaListaDePedidos
{
    public const LISTA_DO_CONSOLE = OrderDoConsole::class . '@queryRecord';
    public const LISTA_AO_VIVO    = LiveController::class . '@orders';

    public function handle(Request $request, Closure $next)
    {
        $resposta = $next($request);

        try {
            if ($this->ehListaDePedidos($request)) {
                return $this->completarItens($resposta);
            }
        } catch (\Throwable $e) {
            Log::warning('[entregas] notas na lista de pedidos: ' . get_class($e));
        }

        return $resposta;
    }

    private function ehListaDePedidos(Request $request): bool
    {
        if ($request->method() !== 'GET') {
            return false;
        }
        $rota = $request->route();
        $acao = $rota instanceof Route ? ltrim($rota->getActionName(), '\\') : null;

        if ($acao === static::LISTA_AO_VIVO) {
            return true;
        }

        return $acao === static::LISTA_DO_CONSOLE && !$request->boolean('single');
    }

    private function completarItens($resposta)
    {
        $empresa = session('company');
        if (!$resposta instanceof JsonResponse || $resposta->getStatusCode() !== 200 || !is_string($empresa) || $empresa === '') {
            return $resposta;
        }

        // objetos (não arrays): um `{}` vazio do corpo continua `{}` ao reescrever
        $corpo = $resposta->getData(false);
        $itens = $this->listaDe($corpo);
        if ($itens === null || $itens === []) {
            return $resposta;
        }

        $uuids = [];
        foreach ($itens as $item) {
            if ($this->faltaAlgo($item)) {
                $uuids[] = $item->uuid;
            }
        }
        if ($uuids === []) {
            return $resposta;
        }

        $linhas = DB::table('orders')
            ->where('company_uuid', $empresa)
            ->whereIn('uuid', array_values(array_unique($uuids)))
            ->get(['uuid', 'notes', 'meta']);

        $porUuid = [];
        foreach ($linhas as $linha) {
            $porUuid[$linha->uuid] = $linha;
        }

        foreach ($itens as $item) {
            if (!$this->faltaAlgo($item) || !isset($porUuid[$item->uuid])) {
                continue;
            }
            $linha = $porUuid[$item->uuid];

            if (!property_exists($item, 'notes') && ($linha->notes ?? null) !== null) {
                $item->notes = $linha->notes;
            }

            $prazo = PrazoDaEntrega::daMeta($linha->meta ?? null);
            if ($prazo !== null && !$this->temPrazo($item)) {
                if (is_array($item->meta ?? null)) {
                    $item->meta = (object) $item->meta;
                } elseif (!is_object($item->meta ?? null)) {
                    $item->meta = new \stdClass();
                }
                $item->meta->{PrazoDaEntrega::CHAVE} = $prazo;
            }
        }

        // status e cabeçalhos ficam como estão
        $resposta->setData($corpo);

        return $resposta;
    }

    /** Item de pedido (com uuid) a que falta o `notes` ou o prazo no `meta`. */
    private function faltaAlgo($item): bool
    {
        return is_object($item) && isset($item->uuid) && is_string($item->uuid)
            && (!property_exists($item, 'notes') || !$this->temPrazo($item));
    }

    private function temPrazo(object $item): bool
    {
        return isset($item->meta) && is_object($item->meta) && property_exists($item->meta, PrazoDaEntrega::CHAVE);
    }

    /** A lista de pedidos do corpo (os itens são objetos: mudá-los muda o corpo), ou null. */
    private function listaDe($corpo): ?array
    {
        if (is_array($corpo)) {
            return $corpo;
        }
        if (is_object($corpo)) {
            foreach (['orders', 'data'] as $chave) {
                if (isset($corpo->$chave) && is_array($corpo->$chave)) {
                    return $corpo->$chave;
                }
            }
        }

        return null;
    }
}
```

- [ ] **Passo 4: Rodar e ver passar**

```bash
cd /c/tmp/cr
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/notas-na-lista.php | tail -1
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/imports-dos-providers.php | tail -1
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Http/Middleware/IncluirNotasNaListaDePedidos.php
```

Esperado: `FALHAS: 0`, `FALHAS: 0` e sintaxe sem erro.

- [ ] **Passo 5: Commit**

```bash
cd /c/tmp/cr && git add api/app/Http/Middleware/IncluirNotasNaListaDePedidos.php scripts/teste-php/notas-na-lista.php
git commit -F - <<'EOF'
Prazo de entrega: listas do console recebem o prazo no meta do recurso enxuto

O Index\Order do Fleet-Ops troca o meta por {"_index_resource": true}; o middleware das notas põe ali o
entregas_prazo_em, na mesma consulta.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Tarefa 6: Função `cronometro` no console e no portal

**Arquivos:**
- Criar: `packages/fleetops/addon/utils/entregas-cronometro.js`
- Criar: `packages/customer-portal/addon/utils/entregas-cronometro.js`
- Teste: criar `scripts/teste-portal/cronometro.test.mjs`

- [ ] **Passo 1: Escrever o teste que falha**

Crie `scripts/teste-portal/cronometro.test.mjs`:

```js
// Cronômetro do prazo de entrega (spec docs/superpowers/specs/2026-10-20-cronometro-do-prazo-design.md). A função do
// console (packages/fleetops/addon/utils/entregas-cronometro.js) e a do portal
// (packages/customer-portal/addon/utils/entregas-cronometro.js) são cópias: os mesmos casos rodam nas duas.
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/cronometro.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as doConsole from '../../packages/fleetops/addon/utils/entregas-cronometro.js';
import * as doPortal from '../../packages/customer-portal/addon/utils/entregas-cronometro.js';
import { ENCERRADOS as ENCERRADOS_DO_PORTAL } from '../../packages/customer-portal/addon/utils/entregas-pedido.js';

const PRAZO = '2026-10-20T19:57:51-03:00';
const T = Date.parse(PRAZO);
const MIN = 60000;
const SEG = 1000;

for (const [nome, m] of [
    ['console', doConsole],
    ['portal', doPortal],
]) {
    test(`${nome}: mais de 15 min = no prazo (verde), minutos arredondados para cima`, () => {
        assert.deepEqual(m.cronometro(PRAZO, T - 32 * MIN), { minutos: 32, estado: 'no_prazo', texto: '32 min' });
        assert.deepEqual(m.cronometro(PRAZO, T - (31 * MIN + 1 * SEG)), { minutos: 32, estado: 'no_prazo', texto: '32 min' });
        assert.deepEqual(m.cronometro(PRAZO, T - (15 * MIN + 1 * SEG)), { minutos: 16, estado: 'no_prazo', texto: '16 min' });
    });

    test(`${nome}: 15 min ou menos = perto (amarelo); 59 s viram "1 min"`, () => {
        assert.deepEqual(m.cronometro(PRAZO, T - 15 * MIN), { minutos: 15, estado: 'perto', texto: '15 min' });
        assert.deepEqual(m.cronometro(PRAZO, T - 12 * MIN), { minutos: 12, estado: 'perto', texto: '12 min' });
        assert.deepEqual(m.cronometro(PRAZO, T - 59 * SEG), { minutos: 1, estado: 'perto', texto: '1 min' });
        assert.deepEqual(m.cronometro(PRAZO, T - 1), { minutos: 1, estado: 'perto', texto: '1 min' });
    });

    test(`${nome}: no prazo exato e depois = atrasado (vermelho), minutos para cima com mínimo 1, nunca "há 0 min"`, () => {
        const atrasado = (minutos) => ({ minutos, estado: 'atrasado', texto: `Atrasado há ${minutos} min` });
        assert.deepEqual(m.cronometro(PRAZO, T), atrasado(1), '0 s de atraso');
        assert.deepEqual(m.cronometro(PRAZO, T + 1 * SEG), atrasado(1), '1 s');
        assert.deepEqual(m.cronometro(PRAZO, T + 60 * SEG), atrasado(1), '60 s');
        assert.deepEqual(m.cronometro(PRAZO, T + 61 * SEG), atrasado(2), '61 s');
        assert.deepEqual(m.cronometro(PRAZO, T + 120 * SEG), atrasado(2), '120 s');
        assert.deepEqual(m.cronometro(PRAZO, T + 121 * SEG), atrasado(3), '121 s');
        assert.deepEqual(m.cronometro(PRAZO, T + 7 * MIN), atrasado(7), '7 min');
        assert.deepEqual(m.cronometro(PRAZO, T + (6 * MIN + 1 * SEG)), atrasado(7), '6 min 1 s');
    });

    test(`${nome}: prazo em UTC (Z) e agora como Date valem igual`, () => {
        assert.deepEqual(m.cronometro('2026-10-20T22:57:51Z', T - 32 * MIN), { minutos: 32, estado: 'no_prazo', texto: '32 min' });
        assert.deepEqual(m.cronometro(PRAZO, new Date(T + 7 * MIN)), { minutos: 7, estado: 'atrasado', texto: 'Atrasado há 7 min' });
    });

    test(`${nome}: prazo ausente ou ilegível (ou agora inválido) = null`, () => {
        for (const valor of [undefined, null, '', '   ', 'amanhã', '2026-10-20', 'x2026-10-20T19:57:51-03:00', '2026-13-45T99:99:00-03:00', 123, {}, []]) {
            assert.equal(m.cronometro(valor, T), null, `prazo ${JSON.stringify(valor)}`);
        }
        assert.equal(m.cronometro(PRAZO, NaN), null);
        assert.equal(m.cronometro(PRAZO, 'agora'), null);
    });

    test(`${nome}: textos traduzidos pelo terceiro parâmetro`, () => {
        const textos = { faltam: (n) => `${n} min left`, atrasado: (n) => `Late by ${n} min` };
        assert.equal(m.cronometro(PRAZO, T - 32 * MIN, textos).texto, '32 min left');
        assert.equal(m.cronometro(PRAZO, T + 7 * MIN, textos).texto, 'Late by 7 min');
    });

    test(`${nome}: prazo do pedido lido do meta (model ou objeto)`, () => {
        assert.equal(m.prazoDoPedido({ meta: { entregas_prazo_em: PRAZO } }), PRAZO);
        assert.equal(m.prazoDoPedido({ meta: { _index_resource: true } }), null);
        assert.equal(m.prazoDoPedido({ meta: { entregas_prazo_em: '  ' } }), null);
        assert.equal(m.prazoDoPedido({ meta: null }), null);
        assert.equal(m.prazoDoPedido({}), null);
        assert.equal(m.prazoDoPedido(null), null);
    });

    test(`${nome}: cronômetro do pedido some no encerrado e sem prazo`, () => {
        const emAndamento = { status: 'started', meta: { entregas_prazo_em: PRAZO } };
        assert.deepEqual(m.cronometroDoPedido(emAndamento, T - 12 * MIN), { minutos: 12, estado: 'perto', texto: '12 min' });
        for (const status of m.ENCERRADOS) {
            assert.equal(m.cronometroDoPedido({ ...emAndamento, status }, T - 12 * MIN), null, `status ${status}`);
        }
        assert.equal(m.cronometroDoPedido({ status: 'started', meta: {} }, T), null, 'pedido de antes do deploy');
        assert.equal(m.cronometroDoPedido(null, T), null);
    });

    test(`${nome}: tipo do Badge (tokens do ember-ui) por estado`, () => {
        assert.deepEqual(m.TIPO_DO_BADGE, { no_prazo: 'success', perto: 'warning', atrasado: 'error' });
    });
}

test('console e portal: as mesmas constantes, e os encerrados do portal', () => {
    assert.equal(doConsole.CHAVE_DO_PRAZO, 'entregas_prazo_em');
    assert.equal(doPortal.CHAVE_DO_PRAZO, doConsole.CHAVE_DO_PRAZO);
    assert.equal(doConsole.PERTO_MS, 15 * MIN);
    assert.equal(doPortal.PERTO_MS, doConsole.PERTO_MS);
    assert.equal(doConsole.INTERVALO_DO_CRONOMETRO_MS, 30000);
    assert.equal(doPortal.INTERVALO_DO_CRONOMETRO_MS, doConsole.INTERVALO_DO_CRONOMETRO_MS);
    assert.deepEqual(doPortal.ENCERRADOS, doConsole.ENCERRADOS);
    assert.deepEqual(doPortal.ENCERRADOS, ENCERRADOS_DO_PORTAL);
});
```

- [ ] **Passo 2: Rodar e ver falhar**

```bash
cd /c/tmp/cr && node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/cronometro.test.mjs 2>&1 | tail -12
```

Esperado: erro `Cannot find module .../entregas-cronometro.js` e `# fail 1` (ou mais).

- [ ] **Passo 3: Criar `packages/fleetops/addon/utils/entregas-cronometro.js`**

```js
/**
 * Entregas: cronômetro do prazo de entrega (spec docs/superpowers/specs/2026-10-20-cronometro-do-prazo-design.md). O
 * servidor grava o prazo na criação do pedido, no meta (entregas_prazo_em, ISO com o fuso de Brasília; ver
 * App\Support\Entregas\PrazoDaEntrega). Aqui só a conta, com o relógio do aparelho:
 * - restante > 15 min → 'no_prazo' (verde), minutos para cima ("32 min");
 * - 0 < restante ≤ 15 min → 'perto' (amarelo), minutos para cima (59 s = "1 min");
 * - restante ≤ 0 → 'atrasado' (vermelho), minutos para cima, no mínimo 1 (0 s a 60 s = "Atrasado há 1 min", 61 s = 2;
 *   nunca "há 0 min").
 * Prazo ausente ou ilegível → null (sem cronômetro).
 *
 * Funções puras, sem import do Ember, testadas em scripts/teste-portal/cronometro.test.mjs. O portal da loja tem a cópia
 * (customer-portal/addon/utils/entregas-cronometro.js): um engine não importa do outro. Mudou aqui, mude lá.
 */

/** Chave do prazo no meta do pedido (PrazoDaEntrega::CHAVE). */
export const CHAVE_DO_PRAZO = 'entregas_prazo_em';

/** Faltando isto ou menos, o cronômetro fica amarelo. */
export const PERTO_MS = 15 * 60000;

/** De quanto em quanto tempo as telas recalculam o cronômetro (serviço entregas-relogio). */
export const INTERVALO_DO_CRONOMETRO_MS = 30000;

/** Pedido encerrado (StatusDoPedido::ENCERRADOS do servidor): sem cronômetro. */
export const ENCERRADOS = ['completed', 'done', 'canceled', 'cancelled', 'order_canceled', 'expired'];

/** Tipo do Badge do ember-ui (tokens de cor) por estado: verde, amarelo e vermelho. */
export const TIPO_DO_BADGE = { no_prazo: 'success', perto: 'warning', atrasado: 'error' };

/** Textos padrão (pt-BR); as telas passam os do intl. */
export const TEXTOS_PADRAO = {
    faltam: (n) => `${n} min`,
    atrasado: (n) => `Atrasado há ${n} min`,
};

const ISO_COM_HORA = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/;

/**
 * cronometro(prazoIso, agora, textos) → null | { minutos, estado: 'no_prazo' | 'perto' | 'atrasado', texto }.
 * `agora` em milissegundos (Date.now()) ou Date.
 */
export function cronometro(prazoIso, agora = Date.now(), textos = TEXTOS_PADRAO) {
    if (typeof prazoIso !== 'string' || !ISO_COM_HORA.test(prazoIso.trim())) {
        return null;
    }

    const prazo = Date.parse(prazoIso.trim());
    const instante = agora instanceof Date ? agora.getTime() : typeof agora === 'number' ? agora : NaN;

    if (!Number.isFinite(prazo) || !Number.isFinite(instante)) {
        return null;
    }

    const restante = prazo - instante;

    if (restante > 0) {
        const minutos = Math.ceil(restante / 60000);

        return { minutos, estado: restante > PERTO_MS ? 'no_prazo' : 'perto', texto: textos.faltam(minutos) };
    }

    // para cima também depois do prazo, com mínimo 1: 0 s a 60 s = 1; 61 s a 120 s = 2 (a mesma regra do app)
    const minutos = Math.max(1, Math.ceil(-restante / 60000));

    return { minutos, estado: 'atrasado', texto: textos.atrasado(minutos) };
}

/** O prazo gravado no meta do pedido (model do Ember Data ou objeto simples), ou null. */
export function prazoDoPedido(pedido) {
    const meta = pedido?.meta;
    const prazo = meta && typeof meta === 'object' ? meta[CHAVE_DO_PRAZO] : null;

    return typeof prazo === 'string' && prazo.trim() !== '' ? prazo : null;
}

/** Cronômetro do pedido: null quando o pedido está encerrado (concluído, cancelado, expirado) ou não tem prazo. */
export function cronometroDoPedido(pedido, agora = Date.now(), textos = TEXTOS_PADRAO) {
    if (!pedido || ENCERRADOS.includes(pedido.status)) {
        return null;
    }

    return cronometro(prazoDoPedido(pedido), agora, textos);
}
```

- [ ] **Passo 4: Criar a cópia `packages/customer-portal/addon/utils/entregas-cronometro.js`**

O mesmo conteúdo do passo 3, só com o cabeçalho trocado por:

```js
/**
 * Entregas: cronômetro do prazo de entrega no portal da loja (spec
 * docs/superpowers/specs/2026-10-20-cronometro-do-prazo-design.md). O servidor grava o prazo na criação do pedido, no
 * meta (entregas_prazo_em, ISO com o fuso de Brasília; ver App\Support\Entregas\PrazoDaEntrega). Aqui só a conta, com o
 * relógio do aparelho:
 * - restante > 15 min → 'no_prazo' (verde), minutos para cima ("32 min");
 * - 0 < restante ≤ 15 min → 'perto' (amarelo), minutos para cima (59 s = "1 min");
 * - restante ≤ 0 → 'atrasado' (vermelho), minutos para cima, no mínimo 1 (0 s a 60 s = "Atrasado há 1 min", 61 s = 2;
 *   nunca "há 0 min").
 * Prazo ausente ou ilegível → null (sem cronômetro).
 *
 * Funções puras, sem import do Ember, testadas em scripts/teste-portal/cronometro.test.mjs. Cópia da do console
 * (fleetops/addon/utils/entregas-cronometro.js): um engine não importa do outro, e o teste roda os mesmos casos nas duas.
 * Os ENCERRADOS são os de utils/entregas-pedido.js (o teste confere). Mudou aqui, mude lá.
 */
```

Para não errar a cópia, gere o arquivo assim e só depois troque o cabeçalho (o primeiro bloco de comentário, do `/**` da linha 1 até o primeiro `*/`) pelo bloco acima:

```bash
cd /c/tmp/cr && cp packages/fleetops/addon/utils/entregas-cronometro.js packages/customer-portal/addon/utils/entregas-cronometro.js
```

Confira que só o cabeçalho difere:

```bash
diff <(sed -n '/^export const CHAVE_DO_PRAZO/,$p' packages/fleetops/addon/utils/entregas-cronometro.js) <(sed -n '/^export const CHAVE_DO_PRAZO/,$p' packages/customer-portal/addon/utils/entregas-cronometro.js) && echo IGUAIS
```

Esperado: `IGUAIS`.

- [ ] **Passo 5: Rodar e ver passar**

```bash
cd /c/tmp/cr && node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/cronometro.test.mjs 2>&1 | grep -E '^# (tests|pass|fail)'
```

Esperado: `# tests 19`, `# pass 19`, `# fail 0`.

- [ ] **Passo 6: Commit**

```bash
cd /c/tmp/cr && git add packages/fleetops/addon/utils/entregas-cronometro.js packages/customer-portal/addon/utils/entregas-cronometro.js scripts/teste-portal/cronometro.test.mjs
git commit -F - <<'EOF'
Cronômetro do prazo: função pura no console e no portal (mesmos testes nas duas cópias)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Tarefa 7: Cor do rótulo do alfinete (`classeDoRotulo`)

**Arquivos:**
- Modificar: `packages/fleetops/addon/utils/entregas-pedidos-no-mapa.js`
- Modificar: `packages/customer-portal/addon/utils/pedidos-no-mapa.js`
- Teste: `scripts/teste-portal/entregas-pedidos-no-mapa.test.mjs`, `scripts/teste-portal/pedidos-no-mapa.test.mjs`

- [ ] **Passo 1: Escrever os casos que falham**

Em `scripts/teste-portal/entregas-pedidos-no-mapa.test.mjs`, troque a linha do import

```js
import { ALFINETE, nomeNoAlfinete, rotuloNoAlfinete, mesmaLista, pedidosValidos, tempoDesde } from '../../packages/fleetops/addon/utils/entregas-pedidos-no-mapa.js';
```

por

```js
import { ALFINETE, classeDoRotulo, nomeNoAlfinete, rotuloNoAlfinete, mesmaLista, pedidosValidos, tempoDesde } from '../../packages/fleetops/addon/utils/entregas-pedidos-no-mapa.js';
```

e acrescente no fim do arquivo:

```js
test('rótulo do alfinete: a classe segue o prazo (verde, amarelo, vermelho); sem prazo, a de antes', () => {
    const prazo = '2026-10-20T19:57:51-03:00';
    const t = Date.parse(prazo);
    assert.equal(classeDoRotulo({ prazo_em: prazo }, t - 32 * 60000), 'entregas-nome-no-alfinete entregas-prazo-no_prazo');
    assert.equal(classeDoRotulo({ prazo_em: prazo }, t - 12 * 60000), 'entregas-nome-no-alfinete entregas-prazo-perto');
    assert.equal(classeDoRotulo({ prazo_em: prazo }, t + 7 * 60000), 'entregas-nome-no-alfinete entregas-prazo-atrasado');
    assert.equal(classeDoRotulo({ prazo_em: null }, t), 'entregas-nome-no-alfinete', 'pedido de antes do deploy');
    assert.equal(classeDoRotulo({ prazo_em: 'amanhã' }, t), 'entregas-nome-no-alfinete');
    assert.equal(classeDoRotulo({}, t), 'entregas-nome-no-alfinete', 'API antiga, sem prazo_em');
    assert.equal(classeDoRotulo(null, t), 'entregas-nome-no-alfinete');
});

test('pedidos válidos: o prazo_em passa junto', () => {
    assert.deepEqual(pedidosValidos([{ id: 'order_a', latitude: -21.18, longitude: -47.81, prazo_em: '2026-10-20T19:57:51-03:00' }]), [
        { id: 'order_a', latitude: -21.18, longitude: -47.81, prazo_em: '2026-10-20T19:57:51-03:00' },
    ]);
});
```

Em `scripts/teste-portal/pedidos-no-mapa.test.mjs`, troque as duas linhas de import

```js
import { ALFINETE, nomeNoAlfinete, rotuloNoAlfinete, mesmaLista, pedidosValidos, semOPedidoAberto, tempoDesde } from '../../packages/customer-portal/addon/utils/pedidos-no-mapa.js';
import { ALFINETE as ALFINETE_DO_CONSOLE } from '../../packages/fleetops/addon/utils/entregas-pedidos-no-mapa.js';
```

por

```js
import { ALFINETE, classeDoRotulo, nomeNoAlfinete, rotuloNoAlfinete, mesmaLista, pedidosValidos, semOPedidoAberto, tempoDesde } from '../../packages/customer-portal/addon/utils/pedidos-no-mapa.js';
import { ALFINETE as ALFINETE_DO_CONSOLE, classeDoRotulo as classeDoRotuloDoConsole } from '../../packages/fleetops/addon/utils/entregas-pedidos-no-mapa.js';
```

e acrescente no fim do arquivo:

```js
test('rótulo do alfinete: a mesma classe do console, pelo prazo', () => {
    const prazo = '2026-10-20T19:57:51-03:00';
    const t = Date.parse(prazo);
    for (const [pedido, agora] of [
        [{ prazo_em: prazo }, t - 32 * 60000],
        [{ prazo_em: prazo }, t - 12 * 60000],
        [{ prazo_em: prazo }, t + 7 * 60000],
        [{ prazo_em: null }, t],
        [{}, t],
        [null, t],
    ]) {
        assert.equal(classeDoRotulo(pedido, agora), classeDoRotuloDoConsole(pedido, agora));
    }
    assert.equal(classeDoRotulo({ prazo_em: prazo }, t + 7 * 60000), 'entregas-nome-no-alfinete entregas-prazo-atrasado');
    assert.equal(classeDoRotulo({}, t), 'entregas-nome-no-alfinete');
});
```

- [ ] **Passo 2: Rodar e ver falhar**

```bash
cd /c/tmp/cr && node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/entregas-pedidos-no-mapa.test.mjs scripts/teste-portal/pedidos-no-mapa.test.mjs 2>&1 | grep -E 'classeDoRotulo|^# fail'
```

Esperado: `SyntaxError: The requested module ... does not provide an export named 'classeDoRotulo'` e `# fail` maior que 0.

- [ ] **Passo 3: `classeDoRotulo` no console**

Em `packages/fleetops/addon/utils/entregas-pedidos-no-mapa.js`:

1. Troque o início do arquivo

```js
/**
 * Entregas: os alfinetes dos pedidos em andamento no mapa ao vivo do console (resposta.pedidos de
```

por

```js
import { cronometro } from './entregas-cronometro';

/**
 * Entregas: os alfinetes dos pedidos em andamento no mapa ao vivo do console (resposta.pedidos de
```

2. Logo depois da função `rotuloNoAlfinete` (antes de `/** Mesma lista? ...`), acrescente:

```js
/**
 * Classe do rótulo fixo do alfinete ("#4821 · Carlos"): o fundo segue o cronômetro do prazo de entrega (prazo_em do
 * PedidosNoMapa): entregas-prazo-no_prazo (verde), -perto (amarelo) ou -atrasado (vermelho). Sem prazo (pedido de antes
 * do deploy ou API antiga), só a classe de sempre (vermelho-escuro).
 */
export function classeDoRotulo(pedido, agora = Date.now()) {
    const estado = cronometro(pedido?.prazo_em, agora)?.estado;

    return estado ? `entregas-nome-no-alfinete entregas-prazo-${estado}` : 'entregas-nome-no-alfinete';
}
```

- [ ] **Passo 4: `classeDoRotulo` no portal**

Em `packages/customer-portal/addon/utils/pedidos-no-mapa.js`:

1. Troque `import { coordenadaValida, motoboyDoPedido } from './motoboys-no-mapa';` por

```js
import { coordenadaValida, motoboyDoPedido } from './motoboys-no-mapa';
import { cronometro } from './entregas-cronometro';
```

2. Logo depois da função `rotuloNoAlfinete` (antes de `/** Mesma lista? ...`), acrescente o mesmo bloco do passo 3:

```js
/**
 * Classe do rótulo fixo do alfinete ("#4821 · Carlos"): o fundo segue o cronômetro do prazo de entrega (prazo_em do
 * PedidosNoMapa): entregas-prazo-no_prazo (verde), -perto (amarelo) ou -atrasado (vermelho). Sem prazo (pedido de antes
 * do deploy ou API antiga), só a classe de sempre (vermelho-escuro). A mesma do console (o teste confere).
 */
export function classeDoRotulo(pedido, agora = Date.now()) {
    const estado = cronometro(pedido?.prazo_em, agora)?.estado;

    return estado ? `entregas-nome-no-alfinete entregas-prazo-${estado}` : 'entregas-nome-no-alfinete';
}
```

- [ ] **Passo 5: Rodar e ver passar**

```bash
cd /c/tmp/cr && node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/entregas-pedidos-no-mapa.test.mjs scripts/teste-portal/pedidos-no-mapa.test.mjs scripts/teste-portal/card-do-pedido.test.mjs 2>&1 | grep -E '^# (pass|fail)'
```

Esperado: `# fail 0`.

- [ ] **Passo 6: Commit**

```bash
cd /c/tmp/cr && git add packages/fleetops/addon/utils/entregas-pedidos-no-mapa.js packages/customer-portal/addon/utils/pedidos-no-mapa.js scripts/teste-portal/entregas-pedidos-no-mapa.test.mjs scripts/teste-portal/pedidos-no-mapa.test.mjs
git commit -F - <<'EOF'
Cronômetro do prazo: classe do rótulo do alfinete pelo estado do prazo (console e portal)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Tarefa 8: Rótulo colorido nos mapas (templates e CSS)

**Arquivos:**
- Modificar: `packages/fleetops/addon/components/map/leaflet-live-map.js` e `.hbs`
- Modificar: `packages/fleetops/addon/styles/fleetops-engine.css`
- Modificar: `packages/customer-portal/addon/components/portal/order/workspace/map.js` e `.hbs`
- Modificar: `packages/customer-portal/addon/styles/customer-portal-engine.css`

O `className` do tooltip do ember-leaflet só vale na criação (é uma `leafletOption`). Por isso o template põe o tooltip dentro de um `{{#each}}` com a classe como chave: quando o estado muda (no máximo duas vezes por pedido), o rótulo é recriado com a classe nova.

- [ ] **Passo 1: Mapa ao vivo do console (JS)**

Em `packages/fleetops/addon/components/map/leaflet-live-map.js`:

1. Troque

```js
import { ALFINETE, mesmaLista, pedidosValidos, rotuloNoAlfinete, tempoDesde } from '../../utils/entregas-pedidos-no-mapa';
```

por

```js
import { ALFINETE, classeDoRotulo, mesmaLista, pedidosValidos, rotuloNoAlfinete, tempoDesde } from '../../utils/entregas-pedidos-no-mapa';
```

2. Troque

```js
    /** Entregas: os pedidos em andamento (alfinete vermelho no endereço de entrega) e o relógio do "há X min". */
    @tracked pedidosNoMapa = [];
    @tracked relogio = Date.now();
    alfinete = ALFINETE;
    rotuloNoAlfinete = rotuloNoAlfinete;
```

por

```js
    /**
     * Entregas: os pedidos em andamento (alfinete vermelho no endereço de entrega) e o relógio do "há X min" e da cor do
     * rótulo pelo prazo de entrega (o relogio anda a cada releitura, ~20 s, e a cada evento do socket).
     */
    @tracked pedidosNoMapa = [];
    @tracked relogio = Date.now();
    alfinete = ALFINETE;
    rotuloNoAlfinete = rotuloNoAlfinete;
    classeDoRotulo = classeDoRotulo;
```

- [ ] **Passo 2: Mapa ao vivo do console (template)**

Em `packages/fleetops/addon/components/map/leaflet-live-map.hbs`, troque

```hbs
                        {{! Entregas: pedido já aceito, número do pedido e nome do motoboy fixos embaixo do alfinete (vermelho, para não confundir com o do capacete) }}
                        {{#let (this.rotuloNoAlfinete pedido) as |nome|}}
                            {{#if nome}}
                                <marker.tooltip @permanent={{true}} @direction="bottom" @className="entregas-nome-no-alfinete">{{nome}}</marker.tooltip>
                            {{/if}}
                        {{/let}}
```

por

```hbs
                        {{! Entregas: pedido já aceito, número do pedido e nome do motoboy fixos embaixo do alfinete; o fundo segue o prazo de entrega (verde, amarelo, vermelho; sem prazo, o vermelho-escuro de sempre). O className do tooltip só vale na criação: o each com a classe como chave recria o rótulo quando o estado muda }}
                        {{#let (this.rotuloNoAlfinete pedido) as |nome|}}
                            {{#if nome}}
                                {{#each (array (this.classeDoRotulo pedido this.relogio)) key="@identity" as |classe|}}
                                    <marker.tooltip @permanent={{true}} @direction="bottom" @className={{classe}}>{{nome}}</marker.tooltip>
                                {{/each}}
                            {{/if}}
                        {{/let}}
```

- [ ] **Passo 3: CSS do console**

Em `packages/fleetops/addon/styles/fleetops-engine.css`, troque

```css
.leaflet-tooltip-bottom.entregas-nome-no-alfinete::before {
    border-bottom-color: rgba(153, 27, 27, 0.92);
}
```

por

```css
.leaflet-tooltip-bottom.entregas-nome-no-alfinete::before {
    border-bottom-color: rgba(153, 27, 27, 0.92);
}

/* Entregas: o fundo do rótulo do alfinete segue o prazo de entrega (utils/entregas-cronometro.js, classeDoRotulo): verde
   no prazo, amarelo a 15 min ou menos, vermelho atrasado. Sem prazo (pedido de antes do deploy), o vermelho-escuro acima. */
.leaflet-tooltip.entregas-nome-no-alfinete.entregas-prazo-no_prazo {
    background: rgba(21, 128, 61, 0.95);
    color: #f0fdf4;
}

.leaflet-tooltip-bottom.entregas-nome-no-alfinete.entregas-prazo-no_prazo::before {
    border-bottom-color: rgba(21, 128, 61, 0.95);
}

.leaflet-tooltip.entregas-nome-no-alfinete.entregas-prazo-perto {
    background: rgba(250, 204, 21, 0.95);
    color: #422006;
}

.leaflet-tooltip-bottom.entregas-nome-no-alfinete.entregas-prazo-perto::before {
    border-bottom-color: rgba(250, 204, 21, 0.95);
}

.leaflet-tooltip.entregas-nome-no-alfinete.entregas-prazo-atrasado {
    background: rgba(220, 38, 38, 0.95);
    color: #fef2f2;
}

.leaflet-tooltip-bottom.entregas-nome-no-alfinete.entregas-prazo-atrasado::before {
    border-bottom-color: rgba(220, 38, 38, 0.95);
}
```

- [ ] **Passo 4: Mapa do portal (JS)**

Em `packages/customer-portal/addon/components/portal/order/workspace/map.js`:

1. Troque

```js
import { ALFINETE, mesmaLista, pedidosValidos, rotuloNoAlfinete, semOPedidoAberto, tempoDesde } from '../../../../utils/pedidos-no-mapa';
```

por

```js
import { ALFINETE, classeDoRotulo, mesmaLista, pedidosValidos, rotuloNoAlfinete, semOPedidoAberto, tempoDesde } from '../../../../utils/pedidos-no-mapa';
```

2. Troque

```js
    _iconeDoAlfinete = null;
    rotuloNoAlfinete = rotuloNoAlfinete;
```

por

```js
    _iconeDoAlfinete = null;
    rotuloNoAlfinete = rotuloNoAlfinete;
    // a cor do rótulo do alfinete pelo prazo de entrega; o relogio anda a cada consulta (5 s)
    classeDoRotulo = classeDoRotulo;
```

- [ ] **Passo 5: Mapa do portal (template)**

Em `packages/customer-portal/addon/components/portal/order/workspace/map.hbs`, troque

```hbs
                {{!-- Entregas: pedido já aceito, número do pedido e nome do motoboy fixos embaixo do alfinete (vermelho, para não confundir com o do capacete) --}}
                {{#let (this.rotuloNoAlfinete pedido) as |nome|}}
                    {{#if nome}}
                        <marker.tooltip @permanent={{true}} @direction="bottom" @className="entregas-nome-no-alfinete">{{nome}}</marker.tooltip>
                    {{/if}}
                {{/let}}
```

por

```hbs
                {{!-- Entregas: pedido já aceito, número do pedido e nome do motoboy fixos embaixo do alfinete; o fundo segue o prazo de entrega (verde, amarelo, vermelho; sem prazo, o vermelho-escuro de sempre). O className do tooltip só vale na criação: o each com a classe como chave recria o rótulo quando o estado muda --}}
                {{#let (this.rotuloNoAlfinete pedido) as |nome|}}
                    {{#if nome}}
                        {{#each (array (this.classeDoRotulo pedido this.relogio)) key="@identity" as |classe|}}
                            <marker.tooltip @permanent={{true}} @direction="bottom" @className={{classe}}>{{nome}}</marker.tooltip>
                        {{/each}}
                    {{/if}}
                {{/let}}
```

- [ ] **Passo 6: CSS do portal**

Em `packages/customer-portal/addon/styles/customer-portal-engine.css`, troque

```css
.fleetbase-portal .leaflet-tooltip-bottom.entregas-nome-no-alfinete::before {
    border-bottom-color: rgb(153 27 27 / 92%);
}
```

por

```css
.fleetbase-portal .leaflet-tooltip-bottom.entregas-nome-no-alfinete::before {
    border-bottom-color: rgb(153 27 27 / 92%);
}

/* Entregas: o fundo do rótulo do alfinete segue o prazo de entrega (utils/entregas-cronometro.js, classeDoRotulo): verde
   no prazo, amarelo a 15 min ou menos, vermelho atrasado. Sem prazo (pedido de antes do deploy), o vermelho-escuro acima.
   As mesmas cores do console. */
.fleetbase-portal .leaflet-tooltip.entregas-nome-no-alfinete.entregas-prazo-no_prazo {
    background: rgb(21 128 61 / 95%);
    color: #f0fdf4;
}

.fleetbase-portal .leaflet-tooltip-bottom.entregas-nome-no-alfinete.entregas-prazo-no_prazo::before {
    border-bottom-color: rgb(21 128 61 / 95%);
}

.fleetbase-portal .leaflet-tooltip.entregas-nome-no-alfinete.entregas-prazo-perto {
    background: rgb(250 204 21 / 95%);
    color: #422006;
}

.fleetbase-portal .leaflet-tooltip-bottom.entregas-nome-no-alfinete.entregas-prazo-perto::before {
    border-bottom-color: rgb(250 204 21 / 95%);
}

.fleetbase-portal .leaflet-tooltip.entregas-nome-no-alfinete.entregas-prazo-atrasado {
    background: rgb(220 38 38 / 95%);
    color: #fef2f2;
}

.fleetbase-portal .leaflet-tooltip-bottom.entregas-nome-no-alfinete.entregas-prazo-atrasado::before {
    border-bottom-color: rgb(220 38 38 / 95%);
}
```

- [ ] **Passo 7: Conferir sintaxe (templates e JS)**

```bash
cd /c/tmp/cr
node scripts/i18n-check.cjs fleetops customer-portal > /dev/null; echo "i18n: $?"
node -e "const fs=require('fs'),p=require('path');const d=fs.readdirSync('console/node_modules/.pnpm').filter(x=>x.startsWith('@babel+parser@7')).sort().pop();const {parse}=require(p.resolve('console/node_modules/.pnpm',d,'node_modules/@babel/parser'));for(const f of process.argv.slice(1)){parse(fs.readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy']});console.log('OK',f)}" packages/fleetops/addon/components/map/leaflet-live-map.js packages/customer-portal/addon/components/portal/order/workspace/map.js packages/fleetops/addon/utils/entregas-pedidos-no-mapa.js packages/customer-portal/addon/utils/pedidos-no-mapa.js
```

Esperado: `i18n: 0` (o check também compila os `.hbs`) e `OK` nos quatro arquivos.

- [ ] **Passo 8: Commit**

```bash
cd /c/tmp/cr && git add packages/fleetops/addon/components/map/leaflet-live-map.js packages/fleetops/addon/components/map/leaflet-live-map.hbs packages/fleetops/addon/styles/fleetops-engine.css packages/customer-portal/addon/components/portal/order/workspace/map.js packages/customer-portal/addon/components/portal/order/workspace/map.hbs packages/customer-portal/addon/styles/customer-portal-engine.css
git commit -F - <<'EOF'
Cronômetro do prazo: rótulo do alfinete na cor do prazo nos mapas do console e do portal

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Tarefa 9: Serviço `entregas-relogio` (console e portal)

**Arquivos:**
- Criar: `packages/fleetops/addon/services/entregas-relogio.js` e `packages/fleetops/app/services/entregas-relogio.js`
- Criar: `packages/customer-portal/addon/services/entregas-relogio.js` e `packages/customer-portal/app/services/entregas-relogio.js`

Sem teste unitário (serviço do Ember; a conta está na função pura, já testada). A conferência é a sintaxe e o teste na tela (Tarefa 14).

- [ ] **Passo 1: Criar `packages/fleetops/addon/services/entregas-relogio.js`**

```js
import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { INTERVALO_DO_CRONOMETRO_MS } from '../utils/entregas-cronometro';

/**
 * Entregas: o relógio do cronômetro do prazo de entrega (coluna "Prazo" da tabela de pedidos e cabeçalho do detalhe).
 * Um só para todas as telas: `agora` anda a cada 30 s e na volta para a aba. Quem lê `agora` num getter se atualiza
 * sozinho (tracked). O relógio é o do aparelho.
 */
export default class EntregasRelogioService extends Service {
    @tracked agora = Date.now();

    constructor() {
        super(...arguments);
        this._intervalo = setInterval(() => this.#tique(), INTERVALO_DO_CRONOMETRO_MS);
        this._aoVoltarParaAba = () => {
            if (!document.hidden) this.#tique();
        };
        if (typeof document !== 'undefined') {
            document.addEventListener('visibilitychange', this._aoVoltarParaAba);
        }
    }

    #tique() {
        if (this.isDestroying || this.isDestroyed) return;
        this.agora = Date.now();
    }

    willDestroy() {
        super.willDestroy(...arguments);
        clearInterval(this._intervalo);
        if (typeof document !== 'undefined') {
            document.removeEventListener('visibilitychange', this._aoVoltarParaAba);
        }
    }
}
```

- [ ] **Passo 2: Re-export do console**

Crie `packages/fleetops/app/services/entregas-relogio.js`:

```js
export { default } from '@fleetbase/fleetops-engine/services/entregas-relogio';
```

- [ ] **Passo 3: Cópia no portal**

Crie `packages/customer-portal/addon/services/entregas-relogio.js` com o mesmo conteúdo do passo 1, só com o docblock trocado por:

```js
/**
 * Entregas: o relógio do cronômetro do prazo de entrega no card do pedido da tela Pedidos. Um só para todos os cards:
 * `agora` anda a cada 30 s e na volta para a aba. Quem lê `agora` num getter se atualiza sozinho (tracked). O relógio é
 * o do aparelho. Cópia do serviço do console (um engine não importa do outro).
 */
```

E crie `packages/customer-portal/app/services/entregas-relogio.js`:

```js
export { default } from '@fleetbase/customer-portal-engine/services/entregas-relogio';
```

- [ ] **Passo 4: Conferir a sintaxe**

```bash
cd /c/tmp/cr && node -e "const fs=require('fs'),p=require('path');const d=fs.readdirSync('console/node_modules/.pnpm').filter(x=>x.startsWith('@babel+parser@7')).sort().pop();const {parse}=require(p.resolve('console/node_modules/.pnpm',d,'node_modules/@babel/parser'));for(const f of process.argv.slice(1)){parse(fs.readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy']});console.log('OK',f)}" packages/fleetops/addon/services/entregas-relogio.js packages/fleetops/app/services/entregas-relogio.js packages/customer-portal/addon/services/entregas-relogio.js packages/customer-portal/app/services/entregas-relogio.js
```

Esperado: `OK` nos quatro.

- [ ] **Passo 5: Commit**

```bash
cd /c/tmp/cr && git add packages/fleetops/addon/services/entregas-relogio.js packages/fleetops/app/services/entregas-relogio.js packages/customer-portal/addon/services/entregas-relogio.js packages/customer-portal/app/services/entregas-relogio.js
git commit -F - <<'EOF'
Cronômetro do prazo: serviço entregas-relogio (30 s e na volta à aba) no console e no portal

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Tarefa 10: Console — coluna "Prazo" e cronômetro no detalhe

**Arquivos:**
- Criar: `packages/fleetops/addon/components/prazo-do-pedido.js` e `.hbs`; `packages/fleetops/app/components/prazo-do-pedido.js`
- Criar: `packages/fleetops/addon/components/cell/prazo-do-pedido.hbs`; `packages/fleetops/app/components/cell/prazo-do-pedido.js`
- Modificar: `packages/fleetops/addon/controllers/operations/orders/index.js`
- Modificar: `packages/fleetops/addon/components/order/panel-header.hbs`
- Modificar: `packages/fleetops/translations/pt-br.yaml` e `en-us.yaml`

- [ ] **Passo 1: Traduções (antes do código, para o i18n-check acusar o que faltar)**

Em `packages/fleetops/translations/pt-br.yaml`, troque

```yaml
    origem:
      coluna: Origem
```

por

```yaml
    origem:
      coluna: Origem
    cronometro:
      coluna: Prazo
      titulo: Prazo de entrega
      faltam: '{n} min'
      atrasado: 'Atrasado há {n} min'
```

Em `packages/fleetops/translations/en-us.yaml`, troque

```yaml
        origem:
            coluna: Source
```

por

```yaml
        origem:
            coluna: Source
        cronometro:
            coluna: Deadline
            titulo: Delivery deadline
            faltam: '{n} min'
            atrasado: 'Late by {n} min'
```

- [ ] **Passo 2: Componente `PrazoDoPedido`**

Crie `packages/fleetops/addon/components/prazo-do-pedido.js`:

```js
import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { TIPO_DO_BADGE, cronometroDoPedido } from '../utils/entregas-cronometro';

/**
 * Entregas: cronômetro do prazo de entrega do pedido (meta.entregas_prazo_em, gravado pelo servidor na criação; ver
 * App\Support\Entregas\PrazoDaEntrega). Badge verde com mais de 15 min, amarelo com 15 min ou menos e vermelho
 * "Atrasado há N min". Some no pedido encerrado e no pedido sem prazo (criado antes do deploy). O relógio é o serviço
 * entregas-relogio (30 s). Usado na coluna "Prazo" da tabela (cell/prazo-do-pedido) e no cabeçalho do detalhe
 * (order/panel-header). A lista recebe o prazo pelo IncluirNotasNaListaDePedidos (o recurso enxuto não traz o meta).
 */
export default class PrazoDoPedidoComponent extends Component {
    @service intl;
    @service entregasRelogio;

    get cronometro() {
        return cronometroDoPedido(this.args.order, this.entregasRelogio.agora, {
            faltam: (n) => this.intl.t('fleet-ops.ui.cronometro.faltam', { n }),
            atrasado: (n) => this.intl.t('fleet-ops.ui.cronometro.atrasado', { n }),
        });
    }

    get tipo() {
        return TIPO_DO_BADGE[this.cronometro?.estado] ?? 'info';
    }
}
```

Crie `packages/fleetops/addon/components/prazo-do-pedido.hbs`:

```hbs
{{#if this.cronometro}}
    <Badge @type={{this.tipo}} @icon="clock" title={{t "fleet-ops.ui.cronometro.titulo"}} ...attributes>{{this.cronometro.texto}}</Badge>
{{/if}}
```

Crie `packages/fleetops/app/components/prazo-do-pedido.js`:

```js
export { default } from '@fleetbase/fleetops-engine/components/prazo-do-pedido';
```

- [ ] **Passo 3: Célula da tabela**

Crie `packages/fleetops/addon/components/cell/prazo-do-pedido.hbs`:

```hbs
<PrazoDoPedido @order={{@row}} />
```

Crie `packages/fleetops/app/components/cell/prazo-do-pedido.js`:

```js
export { default } from '@fleetbase/fleetops-engine/components/cell/prazo-do-pedido';
```

- [ ] **Passo 4: Coluna "Prazo" na tabela de pedidos**

Em `packages/fleetops/addon/controllers/operations/orders/index.js`, troque

```js
            {
                label: this.intl.t('column.status'),
                valuePath: 'status',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/multi-option',
                fetchUri: 'orders/statuses',
            },
```

por

```js
            {
                label: this.intl.t('column.status'),
                valuePath: 'status',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/multi-option',
                fetchUri: 'orders/statuses',
            },
            {
                // Entregas: cronômetro do prazo de entrega (verde; amarelo a 15 min; vermelho atrasado; some no encerrado).
                // Sem ordenação: o prazo fica no meta (JSON) do pedido
                label: this.intl.t('fleet-ops.ui.cronometro.coluna'),
                valuePath: 'meta.entregas_prazo_em',
                cellComponent: 'cell/prazo-do-pedido',
                resizable: true,
            },
```

- [ ] **Passo 5: Cronômetro ao lado do status no detalhe**

Em `packages/fleetops/addon/components/order/panel-header.hbs`, troque

```hbs
                    <Badge @status={{@resource.status}} />
                    <PedidoDeOrigem::Selo @order={{@resource}} />
```

por

```hbs
                    <Badge @status={{@resource.status}} />
                    <PrazoDoPedido @order={{@resource}} />
                    <PedidoDeOrigem::Selo @order={{@resource}} />
```

- [ ] **Passo 6: Conferir tradução, templates e JS**

```bash
cd /c/tmp/cr
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal > /tmp/i18n-cr.txt; echo "i18n: $?"; grep -A3 '== fleetops$' /tmp/i18n-cr.txt
node -e "const fs=require('fs'),p=require('path');const d=fs.readdirSync('console/node_modules/.pnpm').filter(x=>x.startsWith('@babel+parser@7')).sort().pop();const {parse}=require(p.resolve('console/node_modules/.pnpm',d,'node_modules/@babel/parser'));for(const f of process.argv.slice(1)){parse(fs.readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy']});console.log('OK',f)}" packages/fleetops/addon/components/prazo-do-pedido.js packages/fleetops/app/components/prazo-do-pedido.js packages/fleetops/app/components/cell/prazo-do-pedido.js packages/fleetops/addon/controllers/operations/orders/index.js
```

Esperado: `i18n: 0`, no bloco do fleetops `faltando em pt-br: 0`, `erros de sintaxe: 0` e `sem tradução em en-us/pt-br: 0`; `OK` nos quatro arquivos.

- [ ] **Passo 7: Commit**

```bash
cd /c/tmp/cr && git add packages/fleetops/addon/components/prazo-do-pedido.js packages/fleetops/addon/components/prazo-do-pedido.hbs packages/fleetops/app/components/prazo-do-pedido.js packages/fleetops/addon/components/cell/prazo-do-pedido.hbs packages/fleetops/app/components/cell/prazo-do-pedido.js packages/fleetops/addon/controllers/operations/orders/index.js packages/fleetops/addon/components/order/panel-header.hbs packages/fleetops/translations/pt-br.yaml packages/fleetops/translations/en-us.yaml
git commit -F - <<'EOF'
Console: cronômetro do prazo na coluna Prazo da tabela e ao lado do status no detalhe

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Tarefa 11: Portal — cronômetro no card (sai o `textoDoEta`)

**Arquivos:**
- Modificar: `packages/customer-portal/addon/components/portal/order/list-card.js` e `.hbs`
- Modificar: `packages/customer-portal/addon/utils/entregas-pedido.js`
- Modificar: `packages/customer-portal/addon/styles/customer-portal-engine.css`
- Modificar: `packages/customer-portal/translations/pt-br.yaml` e `en-us.yaml`
- Teste: `scripts/teste-portal/card-do-pedido.test.mjs`

- [ ] **Passo 1: Tirar o teste do `textoDoEta`**

Em `scripts/teste-portal/card-do-pedido.test.mjs`:

1. Troque `import { nomeDoCliente, textoDoEta } from '../../packages/customer-portal/addon/utils/entregas-pedido.js';` por

```js
import { nomeDoCliente } from '../../packages/customer-portal/addon/utils/entregas-pedido.js';
```

2. Apague o teste inteiro que começa em `test('tempo até a entrega no card: o eta do Fleet-Ops é um objeto (segundos), nunca "[object Object]"', () => {` e termina no `});` seguinte (é o último teste do arquivo). O cronômetro que entra no lugar é testado em `scripts/teste-portal/cronometro.test.mjs`.

- [ ] **Passo 2: Tirar o `textoDoEta` do util**

Em `packages/customer-portal/addon/utils/entregas-pedido.js`, apague o bloco inteiro do fim do arquivo que começa em

```js
/**
 * Tempo até a entrega no card do pedido. O primeiro valor útil da lista vale: o `eta` do recurso do pedido é um objeto do
```

e termina no `}` que fecha a `export function textoDoEta(valores) {` (o último bloco do arquivo).

Confira que ninguém mais usa:

```bash
cd /c/tmp/cr && grep -rn "textoDoEta\|etaLabel\|trackingSummary\|portal-order-card-eta" packages/customer-portal/addon scripts/teste-portal
```

Esperado: só as linhas de `list-card.js`, `list-card.hbs` e do CSS, que os passos seguintes trocam.

- [ ] **Passo 3: Traduções**

Em `packages/customer-portal/translations/pt-br.yaml`, troque

```yaml
      card:
        portal: "Portal"
        procurando-motoboy: "procurando motoboy"
```

por

```yaml
      card:
        portal: "Portal"
        procurando-motoboy: "procurando motoboy"
        prazo: "Prazo de entrega"
        prazo-faltam: "{n} min"
        prazo-atrasado: "Atrasado há {n} min"
```

Em `packages/customer-portal/translations/en-us.yaml`, troque

```yaml
      card:
        portal: "Portal"
        procurando-motoboy: "looking for a courier"
```

por

```yaml
      card:
        portal: "Portal"
        procurando-motoboy: "looking for a courier"
        prazo: "Delivery deadline"
        prazo-faltam: "{n} min"
        prazo-atrasado: "Late by {n} min"
```

- [ ] **Passo 4: Card (JS)**

Substitua o conteúdo de `packages/customer-portal/addon/components/portal/order/list-card.js` por:

```js
import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { CANCELAVEIS, nomeDoCliente, origemDoPedido } from '../../../utils/entregas-pedido';
import { TIPO_DO_BADGE, cronometroDoPedido } from '../../../utils/entregas-cronometro';
import { capaceteDaSituacao } from '../../../utils/motoboys-no-mapa';

/**
 * Entregas: card do pedido na lista da tela Pedidos (coluna da esquerda), no desenho do sketch 002 ("A compacto"):
 * linha 1 com o número do pedido (o do iFood ou do sistema da loja; pedido feito no portal, o código de rastreio), a
 * origem e a situação; linha 2 com o motoboy (capacete na cor da situação) ou "procurando motoboy", o cliente, a hora e o
 * cronômetro do prazo de entrega (relógio com fundo verde no prazo, amarelo a 15 min ou menos, vermelho "Atrasado há
 * N min"; meta.entregas_prazo_em, gravado pelo servidor; some no encerrado e sem prazo). Sem os endereços: a entrega
 * fica no detalhe e no alfinete do mapa.
 */
export default class PortalOrderListCardComponent extends Component {
    @service entregasMapaDaLoja;
    @service entregasRelogio;
    @service intl;

    get order() {
        return this.args.order ?? {};
    }

    get identifier() {
        return this.order.public_id ?? this.order.id;
    }

    get trackingNumber() {
        return this.order.tracking_number?.tracking_number ?? this.order.tracking_number ?? this.order.public_id ?? this.order.id;
    }

    get origem() {
        return origemDoPedido(this.order.notes, this.order.internal_id);
    }

    get numero() {
        return this.origem?.numero ?? null;
    }

    get cliente() {
        return nomeDoCliente(this.order.payload?.dropoff);
    }

    get hora() {
        return this.order.scheduled_at ?? this.order.created_at;
    }

    // o motoboy vem da consulta do mapa (só depois do aceite); antes dele, o pedido ainda cancelável espera um motoboy
    get motoboy() {
        return this.entregasMapaDaLoja.motoboyDoPedido(this.order.public_id);
    }

    get capacete() {
        return capaceteDaSituacao(this.motoboy?.situacao);
    }

    get procurandoMotoboy() {
        return !this.motoboy && CANCELAVEIS.includes(this.order.status);
    }

    // cronômetro do prazo de entrega: o relógio (serviço entregas-relogio) anda a cada 30 s
    get cronometro() {
        return cronometroDoPedido(this.order, this.entregasRelogio.agora, {
            faltam: (n) => this.intl.t('customer-portal.ui.entregas.card.prazo-faltam', { n }),
            atrasado: (n) => this.intl.t('customer-portal.ui.entregas.card.prazo-atrasado', { n }),
        });
    }

    get tipoDoPrazo() {
        return TIPO_DO_BADGE[this.cronometro?.estado] ?? 'info';
    }
}
```

- [ ] **Passo 5: Card (template)**

Em `packages/customer-portal/addon/components/portal/order/list-card.hbs`, troque

```hbs
            {{#if this.etaLabel}}
                <span class="portal-order-card-eta"><FaIcon @icon="clock" /> {{this.etaLabel}}</span>
            {{/if}}
```

por

```hbs
            {{!-- Entregas: cronômetro do prazo de entrega (relógio com o fundo na cor do estado: verde, amarelo, vermelho) --}}
            {{#if this.cronometro}}
                <span class="portal-order-card-prazo" title={{t "customer-portal.ui.entregas.card.prazo"}}>
                    <Badge @type={{this.tipoDoPrazo}} @icon="clock">{{this.cronometro.texto}}</Badge>
                </span>
            {{/if}}
```

- [ ] **Passo 6: CSS do card**

Em `packages/customer-portal/addon/styles/customer-portal-engine.css`, troque

```css
.fleetbase-portal .portal-order-card-eta {
    flex: 0 0 auto;
    color: #0f172a;
    font-size: 0.8125rem;
    font-weight: 700;
    white-space: nowrap;
}

.fleetbase-portal .portal-order-card-eta svg {
    margin-right: 0.125rem;
    color: #94a3b8;
}

body[data-theme='dark'].fleetbase-portal .portal-order-card-eta {
    color: #f8fafc;
}
```

por

```css
/* Entregas: cronômetro do prazo de entrega no card (Badge do ember-ui com o relógio: success = verde no prazo, warning =
   amarelo a 15 min ou menos, error = vermelho atrasado; fundo escuro e texto claro nos dois temas) */
.fleetbase-portal .portal-order-card-prazo {
    flex: 0 0 auto;
    white-space: nowrap;
}
```

- [ ] **Passo 7: Rodar os testes e as conferências**

```bash
cd /c/tmp/cr
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs 2>&1 | grep -E '^# (pass|fail)'
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal > /tmp/i18n-cr.txt; echo "i18n: $?"; grep -A3 '== customer-portal$' /tmp/i18n-cr.txt
node -e "const fs=require('fs'),p=require('path');const d=fs.readdirSync('console/node_modules/.pnpm').filter(x=>x.startsWith('@babel+parser@7')).sort().pop();const {parse}=require(p.resolve('console/node_modules/.pnpm',d,'node_modules/@babel/parser'));for(const f of process.argv.slice(1)){parse(fs.readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy']});console.log('OK',f)}" packages/customer-portal/addon/components/portal/order/list-card.js packages/customer-portal/addon/utils/entregas-pedido.js
```

Esperado: `# fail 0`; `i18n: 0`, com `faltando em pt-br: 0`, `erros de sintaxe: 0` e `sem tradução em en-us/pt-br: 0` no customer-portal; `OK` nos dois arquivos.

- [ ] **Passo 8: Commit**

```bash
cd /c/tmp/cr && git add packages/customer-portal/addon/components/portal/order/list-card.js packages/customer-portal/addon/components/portal/order/list-card.hbs packages/customer-portal/addon/utils/entregas-pedido.js packages/customer-portal/addon/styles/customer-portal-engine.css packages/customer-portal/translations/pt-br.yaml packages/customer-portal/translations/en-us.yaml scripts/teste-portal/card-do-pedido.test.mjs
git commit -F - <<'EOF'
Portal: cronômetro do prazo no card do pedido (sai o "N min" da estimativa do Fleet-Ops)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Tarefa 12: Verificação completa

**Arquivos:** nenhum (só conferência).

- [ ] **Passo 1: Todos os testes PHP**

```bash
cd /c/tmp/cr && for f in scripts/teste-php/*.php; do case "$f" in *stubs*|*fixtures*|*ifood-stub-banco*) continue;; esac; r=$(PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs "$f" 2>&1 | tail -1); [ "$r" = "FALHAS: 0" ] || echo "$f -> $r"; done; echo FIM
```

Esperado: só `FIM` (nenhum arquivo listado). Se aparecer um teste que já falhava na linha de base (Tarefa 0), registre e avise; não "conserte" teste fora do escopo.

- [ ] **Passo 2: Sintaxe de todo PHP alterado**

```bash
cd /c/tmp/cr && PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs $(git diff --name-only main -- 'api/*.php') scripts/teste-php/prazo-da-entrega.php
```

Esperado: saída 0, sem `ERRO`.

- [ ] **Passo 3: Todos os testes do front**

```bash
cd /c/tmp/cr && node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs 2>&1 | grep -E '^# (tests|pass|fail)'
```

Esperado: `# fail 0`.

- [ ] **Passo 4: Tradução e JS alterado**

```bash
cd /c/tmp/cr
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal > /dev/null; echo "i18n: $?"
node -e "const fs=require('fs'),p=require('path');const d=fs.readdirSync('console/node_modules/.pnpm').filter(x=>x.startsWith('@babel+parser@7')).sort().pop();const {parse}=require(p.resolve('console/node_modules/.pnpm',d,'node_modules/@babel/parser'));for(const f of process.argv.slice(1)){parse(fs.readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy']});console.log('OK',f)}" $(git diff --name-only main -- 'packages/*.js')
```

Esperado: `i18n: 0` e `OK` em cada `.js` alterado.

- [ ] **Passo 5: Conferir o que vai no ramo**

```bash
cd /c/tmp/cr && git status --short && git log --oneline main..HEAD
```

Esperado: árvore limpa (a junção `console/node_modules` não aparece: é ignorada) e os 11 commits das Tarefas 1 a 11.

- [ ] **Passo 6: Remover a junção**

```bash
cmd //c rmdir "C:\tmp\cr\console\node_modules"
ls "/c/Users/Edgardjr/Documents/vibe coding/Delivery/console/node_modules/.pnpm" | head -1
```

Esperado: a junção some e o `node_modules` da pasta principal continua lá (o `ls` lista um item). **Nunca** use `rm -rf` na junção.

- [ ] **Passo 7: Entregar o ramo**

Não faça merge nem push sozinho. Avise o Edgard: ramo `cronometro-do-prazo` (worktree `/c/tmp/cr`) pronto, com os testes verdes; merge na `main`, push e deploy são com ele (Tarefa 14).

---

### Tarefa 13: Documentação (pasta principal, sem commit)

Os `CLAUDE.md` de assunto e o `docs/historico.md` **não são rastreados**: edite-os na pasta principal (`C:\Users\Edgardjr\Documents\vibe coding\Delivery`) e **não commite**. Não toque no `CLAUDE.md` da raiz (tem mudança de outra pessoa).

- [ ] **Passo 1: `api/CLAUDE.md` (onde os hooks do `AppServiceProvider` são documentados)**

Logo antes de `## Armadilhas do Fleetbase`, insira:

```markdown
## Prazo de entrega (cronômetro)

Decisão de 2026-10-20 (spec `docs/superpowers/specs/2026-10-20-cronometro-do-prazo-design.md`; plano `docs/superpowers/plans/2026-10-20-cronometro-do-prazo-delivery.md`). Cada pedido nasce com o prazo de entrega no `meta`, chave `entregas_prazo_em`, sempre no formato exato `Y-m-d\TH:i:sP` com o fuso de Brasília e sem microssegundos (`2026-10-20T19:57:51-03:00`; o Hermes do app lê assim). Console, portal da loja e app do motoboy mostram o cronômetro: verde com mais de 15 min, amarelo com 15 min ou menos, vermelho "Atrasado há N min"; some no pedido encerrado.

- **Regra única:** `App\Support\Entregas\PrazoDaEntrega`. iFood imediato = `delivery.deliveryDateTime`; agendado = fim da janela (sem o fim, início + 30 min); sem data legível e todos os outros (RestaurantePro, portal, central, API) = 50 min depois do `max(criação, scheduled_at)`.
- **Quem grava:**
  - o `CriadorDoPedidoIfood`, no `Order::create` (antes do save);
  - o hook `Order::creating` → `GravarPrazoDoPedido` (`AppServiceProvider::gravarPrazoDosPedidos`), quando o `meta` não tem um prazo legível (prazo ilegível vira o padrão; `meta` em texto solto fica sem prazo). O `creating` não dispara o `updated`: a distribuição e os observadores não percebem. O `RegrasPortalLoja` descarta o `meta` da requisição, e o hook roda depois, no model. Insert pelo query builder (ou criação com `saveQuietly`) escapa. Falha vira o warning `[entregas] falha ao gravar o prazo do pedido` (só a classe; `entregas_application`) e o pedido nasce sem prazo.
- **O prazo é o da criação:** ORDER_PATCHED, CHANGE_PREPARATION_TIME do iFood e reagendamento pela central não o mudam. Pedidos de antes do deploy ficam sem a chave (sem cronômetro); não há preenchimento retroativo.
- **Onde sai:** o recurso v1 (app, portal) e o completo do console já trazem o `meta`. O recurso enxuto das listas do console (`Index\Order`: `GET int/v1/orders` e `fleet-ops/live/orders`) troca o `meta` por `{"_index_resource": true}`: o `IncluirNotasNaListaDePedidos` põe ali só o `entregas_prazo_em`, na mesma consulta das notas. O `PedidosNoMapa` (console, portal e Mapa do líder) devolve `prazo_em`. O prazo não é sensível: vai aos motoboys e ao socket.
- **Testes:** `scripts/teste-php/prazo-da-entrega.php` e os casos novos de `ifood-pedido.php`, `ifood-criador.php`, `pedidos-no-mapa.php` e `notas-na-lista.php`; front em `scripts/teste-portal/cronometro.test.mjs`.
- **Ao atualizar o fleetops-api ou o core-api, confira:** se o `Index\Order` passou a trazer o `meta` completo (aí o acréscimo do prazo sobra; ele não sobrescreve um prazo que já venha); se o cast `Json` do `meta` e o `HasMetaAttributes` mudaram; e se algum caminho de criação de pedido passou a usar `saveQuietly`/`createQuietly` (pula o `creating`).
```

- [ ] **Passo 2: `api/app/Support/Entregas/Ifood/CLAUDE.md`**

1. Troque

```markdown
   - Order: cliente = Vendor da loja, tipo `transport`, `internal_id` = número do iFood, notas "iFood #4821";
```

por

```markdown
   - Order: cliente = Vendor da loja, tipo `transport`, `internal_id` = número do iFood, notas "iFood #4821" e o prazo de entrega no `meta` (`entregas_prazo_em`, `PrazoDaEntrega::doIfood`: imediato = `deliveryDateTime`; agendado = fim da janela ou início + 30 min; sem data legível, 50 min depois da criação; ver "Prazo de entrega (cronômetro)" em `api/CLAUDE.md`);
```

2. Troque

```markdown
- `entregas_ifood_pedidos`: dados do iFood de cada pedido (0800, cobrança, observações, `exige_codigo`, `cancelado_pelo_ifood_em`, `teste`, `agendado`, `despachar_em`, `despachado_em`, `itens`). Fica fora do `meta` do Order de propósito.
```

por

```markdown
- `entregas_ifood_pedidos`: dados do iFood de cada pedido (0800, cobrança, observações, `exige_codigo`, `cancelado_pelo_ifood_em`, `teste`, `agendado`, `despachar_em`, `despachado_em`, `itens`). Fica fora do `meta` do Order de propósito (o `meta` sai na API v1 e no socket). A exceção é o prazo de entrega (`entregas_prazo_em`, 2026-10-20), que não é sensível.
```

3. Troque

```markdown
- **A conferir no primeiro agendado:** o formato do `schedule`.
```

por

```markdown
- **A conferir no primeiro agendado:** o formato do `schedule`, inclusive o `deliveryDateTimeEnd` (o fim da janela é o prazo de entrega; sem ele, vale o início + 30 min).
```

4. Troque

```markdown
Nada novo no `meta`.
```

por

```markdown
Nada novo no `meta` (exceto o prazo de entrega, `entregas_prazo_em`, desde 2026-10-20).
```

5. Troque

```markdown
(aí o `IncluirNotasNaListaDePedidos` sobra; ele não sobrescreve um `notes` que já venha).
```

por

```markdown
(aí o `IncluirNotasNaListaDePedidos` sobra; ele não sobrescreve um `notes` que já venha). Ele também põe o `entregas_prazo_em` no `meta` do recurso enxuto (cronômetro): confira se o `Index\Order` passou a trazer o `meta` completo.
```

- [ ] **Passo 3: `packages/fleetops/CLAUDE.md`**

1. Logo depois do item que começa com `- **Pedido novo da central** (Pedidos → Novo; decisão de 2026-10-13,`, insira:

```markdown
- **Cronômetro do prazo de entrega** (decisão de 2026-10-20; servidor em "Prazo de entrega (cronômetro)" de `api/CLAUDE.md`): `Badge` com relógio (`success` verde com mais de 15 min, `warning` amarelo com 15 min ou menos, `error` vermelho "Atrasado há N min"), que some no pedido encerrado e no sem prazo.
  - Onde: coluna "Prazo" da tabela de pedidos (`cell/prazo-do-pedido`, sem ordenação: o prazo fica no `meta`) e ao lado do status no cabeçalho do detalhe (`order/panel-header`). Componente `PrazoDoPedido`.
  - A lista recebe o prazo pelo `IncluirNotasNaListaDePedidos` (o recurso enxuto não traz o `meta`): sem a API nova, a coluna fica vazia e só o detalhe mostra.
  - Função pura `utils/entregas-cronometro.js` (`cronometro`, `cronometroDoPedido`, `TIPO_DO_BADGE`), copiada no portal (`customer-portal/addon/utils/entregas-cronometro.js`: mudou aqui, mude lá); relógio no serviço `entregas-relogio` (30 s e na volta para a aba). Traduções `fleet-ops.ui.cronometro.*`. Teste: `scripts/teste-portal/cronometro.test.mjs` (os mesmos casos nas duas cópias).
```

2. Troque

```markdown
    - Pedido **aceito** (`aceito` = `orders.started`): o número do pedido e o nome do motoboy ficam fixos embaixo do alfinete ("#4821 · Carlos", `rotuloNoAlfinete`, decisão de 2026-10-19), num rótulo vermelho-escuro (`entregas-nome-no-alfinete`), diferente do rótulo do capacete.
```

por

```markdown
    - Pedido **aceito** (`aceito` = `orders.started`): o número do pedido e o nome do motoboy ficam fixos embaixo do alfinete ("#4821 · Carlos", `rotuloNoAlfinete`, decisão de 2026-10-19), num rótulo (`entregas-nome-no-alfinete`) diferente do do capacete. Desde 2026-10-20 o fundo segue o prazo de entrega (`prazo_em` do `PedidosNoMapa`, `classeDoRotulo`: `entregas-prazo-no_prazo` verde, `-perto` amarelo, `-atrasado` vermelho); sem prazo, o vermelho-escuro de antes. O `className` do tooltip do ember-leaflet só vale na criação: o template recria o rótulo com um `{{#each}}` que tem a classe como chave. A cor anda com o `relogio` do mapa (a cada releitura, ~20 s, e a cada evento do socket).
```

- [ ] **Passo 4: `packages/customer-portal/CLAUDE.md`**

1. Troque o trecho

```markdown
o cliente, a hora e o tempo até a entrega quando existe (`textoDoEta` em `utils/entregas-pedido.js`: o `eta` do recurso do pedido é um **objeto** do Fleet-Ops, com `completion_seconds`/`active_stop_seconds`, e vira "N min"; mostrado cru, saía "[object Object]" — corrigido em 2026-10-20).
```

por

```markdown
o cliente, a hora e o **cronômetro do prazo de entrega** (decisão de 2026-10-20, no lugar do "N min" da estimativa do Fleet-Ops, o `textoDoEta`, que saiu): `Badge` com relógio, verde com mais de 15 min, amarelo com 15 min ou menos, vermelho "Atrasado há N min"; some no encerrado e no pedido sem prazo. Lê o `meta.entregas_prazo_em` do pedido (servidor: "Prazo de entrega (cronômetro)" em `api/CLAUDE.md`); função pura `utils/entregas-cronometro.js` (cópia da do console), relógio no serviço `entregas-relogio` (30 s); traduções `customer-portal.ui.entregas.card.prazo*`; teste `scripts/teste-portal/cronometro.test.mjs`.
```

2. Troque

```markdown
    - Pedido aceito: "#4821 · Carlos" fixo embaixo do alfinete (número do pedido e motoboy; `rotuloNoAlfinete`, a mesma regra do console em `packages/fleetops/CLAUDE.md`).
```

por

```markdown
    - Pedido aceito: "#4821 · Carlos" fixo embaixo do alfinete (número do pedido e motoboy; `rotuloNoAlfinete`, a mesma regra do console em `packages/fleetops/CLAUDE.md`), com o fundo na cor do prazo de entrega (`classeDoRotulo`, `prazo_em` do `PedidosNoMapa::daLoja`; sem prazo, o vermelho-escuro de antes). A cor anda com a consulta de 5 s.
```

- [ ] **Passo 5: `api/app/Http/Controllers/Entregas/CLAUDE.md` (app do motoboy)**

Troque

```markdown
`pedidos` = `PedidosNoMapa::doLider` (os do mapa do console, com o número do iFood, `motoboy_id`, coleta e `atualizado_em`)
```

por

```markdown
`pedidos` = `PedidosNoMapa::doLider` (os do mapa do console, com o número do iFood, `motoboy_id`, coleta, `atualizado_em` e, desde 2026-10-20, o `prazo_em` para a cor do rótulo; ver "Prazo de entrega (cronômetro)" em `api/CLAUDE.md`)
```

- [ ] **Passo 6: `docs/historico.md`**

Acrescente no fim:

```markdown
34. Cronômetro do prazo de entrega (2026-10-20, ramo `cronometro-do-prazo`; spec `docs/superpowers/specs/2026-10-20-cronometro-do-prazo-design.md`): prazo gravado na criação no `meta` (`entregas_prazo_em`; iFood pelo prazo do pedido, demais 50 min), coluna "Prazo" e cabeçalho no console, card do portal (sai o "N min" da estimativa) e rótulo do alfinete na cor do prazo nos mapas. O app do motoboy tem ramo próprio.
```

- [ ] **Passo 7: Conferir que nada foi commitado na pasta principal**

```bash
git -C "/c/Users/Edgardjr/Documents/vibe coding/Delivery" status --short | head -30
git -C "/c/Users/Edgardjr/Documents/vibe coding/Delivery" log --oneline -1
```

Esperado: os mesmos arquivos não rastreados/modificados de antes (`CLAUDE.md`, `console/app/router.js`, os `CLAUDE.md` de assunto, `docs/historico.md`) e o último commit da `main` sem mudança.

---

### Tarefa 14: Implantação (com o Edgard)

O Claude não tem SSH: quem roda na VPS é o Edgard. Sem migration (o prazo vai no `meta`). A ordem com o app não importa: sem a chave no `meta`, nenhum cliente mostra o cronômetro.

- [ ] **Passo 1: Merge e push (o Edgard decide)**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/Delivery"
git rev-parse --show-toplevel   # precisa ser .../Delivery (a home também é um repo git)
git merge --no-ff cronometro-do-prazo -m "Merge branch 'cronometro-do-prazo'"
git push origin main
```

(Push em `main` não faz deploy.)

- [ ] **Passo 2: API na VPS**

```bash
cd ~/entregas && bash deploy/atualizar.sh api
```

Reinicia application, queue, queue-ifood e scheduler e roda o `deploy.sh`. Sem migration nova.

- [ ] **Passo 3: Console na VPS (~15 min)**

```bash
cd ~/entregas && SEM_PULL=1 bash deploy/atualizar.sh console
```

- [ ] **Passo 4: Recarregar as telas**

Console e portal com **Ctrl+Shift+R** (o console guarda o `fleetbase.config.json` no localStorage por 1 h).

- [ ] **Passo 5: Conferir na produção (loja de testes Terraço Pizza Bar; pedidos de teste nela não precisam ser cancelados)**

1. Pelo portal da loja de testes, crie um pedido: o card mostra o relógio verde "50 min" (ou "49 min").
2. No console: a coluna "Prazo" da lista mostra o mesmo cronômetro; no detalhe, ao lado do status.
3. Depois do aceite de um motoboy: o rótulo "#… · Nome" embaixo do alfinete fica verde no mapa ao vivo e no mapa do portal.
4. Pedidos de antes do deploy: sem cronômetro (esperado).
5. Logs sem falha do hook:

```bash
docker service logs --since 30m entregas_application 2>&1 | grep -E 'prazo do pedido|notas na lista de pedidos' | tail
```

Esperado: nenhuma linha.

6. No primeiro pedido de teste do iFood: o prazo é o `deliveryDateTime` do iFood (na sonda, criação + 45 min), não 50 min.

---

## Auto-revisão (skill writing-plans)

**1. Cobertura da spec**

| Requisito da spec | Tarefa |
|---|---|
| `PrazoDaEntrega`: iFood imediato, agendado (fim; sem fim, início + 30; sem janela, padrão), sem data legível, demais 50 min do `max(criação, scheduled_at)` | 1 (regra), 3 (iFood no `mapear`) |
| Chave `entregas_prazo_em`, ISO com fuso de Brasília, nunca UTC (data com `Z` convertida); formato exato `Y-m-d\TH:i:sP` sem microssegundos (decisão do coordenador) | 1 (testes de `Z`, `+00:00`, fração de segundo e regex do formato), 2 (formato no hook) |
| Hook `Order::creating` que não sobrescreve; criador do iFood grava antes do save | 2, 3 |
| `meta` no recurso v1 (app, portal) e no interno (console) | Conferido: v1 e completo trazem; a lista enxuta não traz → Tarefa 5 |
| Pedidos antigos sem chave → sem cronômetro | 4 (`prazo_em` null), 6 (`cronometroDoPedido` null) |
| Função `cronometro(prazoIso, agora)` com limites de 15 min e 0, arredondamento (para cima nos dois lados, mínimo 1 no atraso: 0/60 s = 1, 61/120 s = 2, 121 s = 3), ausente/ilegível | 6 |
| Atualização a cada 30 s | 9 (serviço), 8 (mapas pelo relógio deles, ≤ 30 s) |
| Cores sucesso/aviso/perigo legíveis nos dois temas | 10, 11 (`Badge`), 8 (rótulo) |
| Portal: card, relógio de volta com fundo na cor; `textoDoEta` sai | 11 |
| Console: coluna "Prazo" e detalhe ao lado do status | 10 |
| Alfinete com fundo na cor do estado nos três mapas; `PedidosNoMapa` com `prazo_em` | 4 (servidor, inclui `doLider`/`umDoLider`), 7 e 8 (console e portal); Mapa do líder no plano do app |
| Some no concluído ou cancelado | 6 (`ENCERRADOS`) |
| Testes php-wasm (regra, hook, criador, `PedidosNoMapa`) | 1 a 5 |
| Testes dos clientes em `scripts/teste-portal/` | 6, 7 |
| i18n "Atrasado há N min" e "Prazo" em pt e en, `i18n-check` com 0 | 10, 11, 12 |
| Entrega: um ramo, deploy `api` e `console`, sem migration | 12, 14 |

Fora deste plano, de propósito: app do motoboy (plano separado); mudança do prazo depois da criação (fora da spec).

**2. Placeholders:** nenhum "TBD"/"TODO"; todo passo que muda código traz o código. A cópia do portal na Tarefa 6 é gerada por `cp` e conferida por `diff` (só o cabeçalho, dado por inteiro, muda).

**3. Consistência de nomes:** `PrazoDaEntrega::{CHAVE, FORMATO, padrao, doIfood, daMeta, doPedido, legivel, formatar}`; a constante de teste `FORMATO_GRAVADO` é definida na seção de formato da Tarefa 1 e reusada no hook (Tarefa 2), que é inserido depois dela no mesmo arquivo (Tarefas 1 a 5); `GravarPrazoDoPedido::aoCriar` e `AppServiceProvider::gravarPrazoDosPedidos` (Tarefa 2 e o teste de ligação); `prazo_em` no `mapear` (3) e no `PedidosNoMapa` (4), lido por `classeDoRotulo` (7); `cronometro`, `prazoDoPedido`, `cronometroDoPedido`, `TIPO_DO_BADGE`, `ENCERRADOS`, `CHAVE_DO_PRAZO`, `PERTO_MS`, `INTERVALO_DO_CRONOMETRO_MS` (6, usados em 7, 9, 10, 11); serviço `entregasRelogio.agora` (9, usado em 10 e 11); chaves `fleet-ops.ui.cronometro.{coluna,titulo,faltam,atrasado}` e `customer-portal.ui.entregas.card.{prazo,prazo-faltam,prazo-atrasado}` (10, 11).
