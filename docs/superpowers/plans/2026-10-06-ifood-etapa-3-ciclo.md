# iFood etapa 3 (servidor): ciclo da entrega, cancelamento pelo iFood e rotas do motoboy

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** etapa 3 da integração iFood Logistics (spec `docs/superpowers/specs/2026-10-05-integracao-ifood-logistics-design.md`,
seção 3 inteira e as rotas do motoboy da seção 4): o servidor informa ao iFood cada etapa da entrega (`assignDriver`,
`goingToOrigin`, `arrivedAtOrigin`, `dispatch`, `arrivedAtDestination`) a partir dos eventos do Fleetbase e do GPS,
cancela o pedido quando chega o CAN (com o pago mesmo cancelado no relatório e na cobrança), proíbe o cancelamento do
nosso lado e entrega ao app as rotas de dados iFood, conclusão e código de entrega, com a trava "Atualize o app" atrás de
um interruptor desligado.

**Architecture:** tudo em `api/app` (nada em `packages/*/server` chega à produção). O gancho é o evento `updated` do
Eloquent no `Order` (registrado por nós no `AppServiceProvider`) mais o evento `OrderDriverAssigned` do Fleet-Ops, que
enfileiram um job `EnviarAcaoIfood` por pedido. O job relê o pedido e envia, em ordem, as ações que faltam depois da
`ultima_acao` (`AcoesIfood` + `SequenciaIfood`, funções puras). O comando `entregas:ifood-acompanhar` (30 s) detecta a
chegada pelo GPS (`ChegadaPeloGps`) e reconcilia o que o gancho não pegou. O CAN cancela pelo `CancelamentoPeloIfood`
(com a `TravaDoPedido`). O middleware `RegrasDoPedidoIfood` barra o cancelamento (API v1 e console) e a conclusão comum
sem a rota nova (interruptor `ENTREGAS_IFOOD_EXIGE_APP_NOVO`). A conclusão do app passa pela `ConclusaoIfood`
(`concluir-ifood` e `codigo-ifood`), que libera a conclusão comum do Fleet-Ops.

```
Order salvo (aceite, atribuição, started, enroute, completed) ──updated──> ObservadorDosPedidosIfood ──> EnviarAcaoIfood (fila)
OrderDriverAssigned (atribuição em lote) ─────────────────────────────────┘                              │
entregas:ifood-acompanhar (30 s): GPS a 100 m da coleta/entrega + reconciliação ──────────────────────────┤
                                                                                                         ▼
                                     AcoesIfood::sincronizar (trava entregas:ifood-acao:<uuid>) ──> iFood (202 / 409 / 429 / 5xx)
                                       ultima_acao, motoboy_no_ifood, recusa_* ; recusa → log + IfoodAcaoRecusada (console)
App: concluir-ifood ──> ConclusaoIfood: arrivedAtDestination na hora → precisa_codigo | pode_concluir
App: codigo-ifood ────> verifyDeliveryCode na hora → certo: conclusao_liberada_em → conclusão comum (update-activity)
CAN (ProcessarPedidoIfood) ──> CancelamentoPeloIfood (TravaDoPedido + trava das ações) → Order::cancel() → push ao motoboy
```

**Tech Stack:** Laravel 10 / PHP 8.2 (`api/app`), Http do Laravel, Redis (cache, travas e fila), testes php-wasm
(`scripts/teste-php`).

**Depois deste plano:** o plano 2 (`docs/superpowers/plans/2026-10-06-ifood-etapa-4-app-console.md`) faz o APK, o console e
o portal sobre as rotas das Tasks 10 e 11 e o evento da Task 4. A trava "Atualize o app" (Task 9) só pode ser ligada
depois do APK do plano 2 em todos os celulares.

## Contexto para quem executa

- Leia o `CLAUDE.md` da raiz (seções "Fuso (horário de Brasília)", "Portal da loja", "Integração iFood (etapa 2)" e
  "App do motoboy") e a spec (seções 3, 4 e 5). A referência da API é `docs/ifood/referencia-logistics.md`; a seção final,
  "Descobertas da sonda", vale mais que o resto: as ações respondem 202 sem evento de volta, código errado é HTTP 400
  `Confirmation code is invalid`, o DDCR chega logo depois da confirmação.
- **Pedido de teste também pede o código.** Ao concluir um pedido de teste pelo Gestor de Pedidos, o iFood pediu "Qual
  o código de PEDIDO?". Por isso este plano **não** pula o código no `isTest`: segue o `exige_codigo` (DDCR) em todo
  pedido. Sem como obter o código, a central usa "Liberar sem código" (Task 11). A spec é corrigida na Task 14.
- PHP próprio vai em `api/app`. O código de `packages/fleetops/server` é uma cópia do fleetops-api publicado e só serve
  para ler (os ganchos abaixo foram conferidos nele).
- Testes de PHP sem PHP instalado: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs <teste.php>`. Se
  `/c/tmp/php-wasm` não existir: `npm i --prefix /c/tmp/php-wasm @php-wasm/node@3.1.54 @php-wasm/universal@3.1.54`.
  Sintaxe: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs <arquivo.php>...`.
- Os testes do iFood usam `stubs-ifood.php` e `stubs-ifood-fleetbase.php` (banco em memória que conhece as colunas das
  migrations `*_entregas_ifood_*`, `Http` com fila de respostas, `Cache` com trava, `Log`, relógio fixo em
  `2026-10-05 15:00:00` de Brasília, fila de jobs, socket falso). As Tasks 1 e 4 acrescentam ao stub o que o ciclo usa.
- Comandos em Git Bash, na raiz da worktree. Confirme `git rev-parse --show-toplevel` antes de cada commit (a home
  também é um repo git). Use sempre `git add <arquivos da task>`, nunca `git add -A`.
- Commits terminam com `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`. **Não** faça push.
- O código de cada task foi escrito e rodado contra os testes deste plano (php-wasm, PHP 8.2) numa cópia de rascunho
  antes de o plano ser publicado: se um teste falhar, confira primeiro se o arquivo foi copiado inteiro e se as tasks
  anteriores foram feitas.

## Onde enganchar cada ação (pesquisa no fleetops-api desta versão)

| Momento no Fleetbase | Como o Fleet-Ops grava | Gancho escolhido |
|---|---|---|
| Aceite do pedido aberto (`POST v1/orders/{id}/start` com `assign`) | `assignDriver($id, true)` (o silent se perde: dispara `OrderDriverAssigned` e `save()`), `started = true` + `save()`, `updateActivity` → `setStatus('started', true)` → `save()` | `Order::updated` (driver_assigned_uuid/started/status) |
| Atribuição pela central (`PUT int/v1/orders/{id}`, `POST int/v1/drivers/{id}/assign-order`, `PUT v1/orders/{id}`) | `$record->update()` / `assignDriver()` → `save()`; o `OrderObserver` chama `notifyDriverAssigned()` | `Order::updated` |
| Atribuição em lote (`PATCH int/v1/orders/bulk-assign-driver`) | query builder (sem eventos do Eloquent); o job `NotifyBulkAssignedDriver` dispara `OrderDriverAssigned` | `Event::listen(OrderDriverAssigned)` |
| `scheduleOrder` do console | `saveQuietly()`: nenhum evento | reconciliação do `entregas:ifood-acompanhar` |
| "A caminho" e conclusão (`update-activity` da v1 e do console) | `Order::updateActivity` → `setStatus($code, true)` → `save()`; `enroute` não tem evento de domínio | `Order::updated` (status) |
| Posição do motoboy (`v1/drivers/{id}/track`) | `updateQuietly` no Driver, `Position::create`, `broadcast(DriverLocationChanged)` (fora do dispatcher do Laravel) | leitura de `drivers.location` a cada 30 s no `entregas:ifood-acompanhar` |
| Cancelamento | `Order::cancel()`: atividade "canceled", `setStatus` + `save()`, `OrderCanceled` na fila; o `HandleOrderCanceled` faz `$driver->notify(OrderCanceled)` | o CAN chama `cancel()`; o texto do push sai do `AvisosDoMotoboy` |

Por que `Order::updated` e não os eventos de domínio: o `OrderStarted` não tem listener e só sai em alguns caminhos, o
`enroute` não tem evento, e o `OrderDispatched`/`OrderCanceled` rodam na fila depois do commit. O `updated` do Eloquent
pega todos os caminhos que passam por `save()` (e nesta versão todo status passa por `setStatus(…, true)`), roda no
processo que gravou e não depende de middleware por rota. O que não passa por `save()` fica com o `OrderDriverAssigned`
(lote) e com a reconciliação a cada 30 s (o `entregas:ifood-acompanhar` recalcula a ação que o estado pede).

## Decisões deste plano (além da spec)

- **Reconciliação pelo estado:** o job não recebe "a ação a enviar"; relê o pedido e calcula o alvo
  (`SequenciaIfood::alvoPeloPedido`: motoboy → assignDriver, `started` → goingToOrigin, `enroute` → dispatch,
  `completed` → arrivedAtDestination). Um job atrasado ou repetido não manda nada fora de ordem, e o comando de 30 s
  conserta o que o gancho perdeu (Redis sem persistência).
- **Um job por pedido de cada vez:** `EnviarAcaoIfood::enfileirar` marca o pedido como pendente no cache; o job apaga a
  marca antes de ler o pedido. Trava `entregas:ifood-acao:<uuid>` (90 s) nos envios.
- **Recusa não se repete sozinha:** com `recusa_acao` gravada, o `entregas:ifood-acompanhar` não reenvia; a próxima
  mudança do pedido (ou a conclusão do app) tenta de novo, e uma ação aceita limpa a recusa. Tentativas esgotadas com o
  iFood fora do ar viram recusa (status do erro) e aviso à central.
- **Colunas novas** em `entregas_ifood_pedidos` (migration da Task 1): `motoboy_no_ifood` (troca de motoboy),
  `recusa_acao`/`recusa_status`/`recusa_em` (painel do console e reconciliação), `conclusao_liberada_em` (trava
  "Atualize o app") e `conclusao_sem_codigo` (pedido concluído sem o código: liberado pela central ou concluído no
  console).
- **Conclusão pelo fluxo comum:** a rota `concluir-ifood`/`codigo-ifood` não conclui o pedido; avisa o iFood, confere o
  código e grava `conclusao_liberada_em`. O app conclui pelo `update-activity` de sempre (atividade, prova de entrega,
  `OrderCompleted`), que a trava deixa passar. Assim não copiamos a conclusão do Fleet-Ops, e uma nova tentativa depois de
  liberado não chama o iFood de novo (o código já conferido daria erro).
- **Recusa do `arrivedAtDestination` na conclusão não prende o motoboy:** segue para o código (ou libera), com a recusa
  registrada e a central avisada. iFood fora do ar (429, 5xx, rede) responde 503 "tente de novo".
- **Pedido de teste segue o `exige_codigo`** (o iFood pediu o código no teste). Saída da central: "Liberar sem código"
  (`POST int/v1/entregas/pedidos/{id}/ifood/liberar-sem-codigo`, só admin), registrado em `conclusao_sem_codigo` e no
  log; o iFood conclui sozinho 4 h depois. A conclusão pelo console de um pedido que exigia código também marca
  `conclusao_sem_codigo` (com warning no log).
- **Cancelamento proibido num middleware só** (`RegrasDoPedidoIfood`, nos grupos `fleetbase.api` e
  `fleetbase.protected`, antes do `BarrarAceiteDePedidoEncerrado`): `cancelOrder` da v1, `cancel` e `bulkCancel` do
  console e a atividade "canceled" (inclusive o arrastar do quadro). O portal fica no `RegrasPortalLoja` (spec).
  A spec dizia "no BarrarAceiteDePedidoEncerrado"; um lugar só para as regras do iFood fica mais claro.
- **Trava "Atualize o app"** no mesmo middleware, só com `ENTREGAS_IFOOD_EXIGE_APP_NOVO=1` (desligada por padrão). Pega o
  `update-activity` da v1 com atividade que conclui e o `POST v1/orders/{id}/complete`. A conclusão pelo console passa.
- **CAN com duas travas:** `TravaDoPedido` (aceite e cancelamentos) e, dentro dela, a das ações do iFood: o
  `pago_mesmo_cancelado` lê a `ultima_acao` definitiva. Idempotente: o job chama de novo enquanto o CAN estiver pendente.
- **Push do cancelamento** pelo próprio `OrderCanceled` do Fleet-Ops (um push só): o `AvisosDoMotoboy` troca o texto
  quando o pedido foi cancelado pelo iFood ("Pedido #4821 cancelado pelo iFood" + "Você recebe por esta entrega…").
- **Pago mesmo cancelado no relatório:** `CalculoEntregas::pedidosConcluidos` junta a `entregas_ifood_pedidos`; entra o
  concluído (data do COMPLETED) ou o pago mesmo cancelado (data do cancelamento). Vale para a tela "Pagamento e
  cobrança", o extrato da loja e os ganhos do app. Cada entrega ganha `cancelado_pago`.
- **Telefone do motoboy no `assignDriver`:** DDD + número, só dígitos, sem o 55 (como a sonda viu ser aceito). Motoboy
  sem nome ou telefone = recusa local (status 0), sem chamar o iFood.
- **Código de entrega:** 3 a 10 dígitos; limitador próprio `entregas-ifood-codigo` (10 por minuto por motoboy) contra
  tentar todos os códigos.
- **Alarme:** os dados `entregas_ifood` (número) e `entregas_cobrar` (texto da `CobrancaIfood`) entram no push já nesta
  etapa (o APK antigo ignora); o cartão é do plano 2.
- **Painel do console** (`GET int/v1/entregas/pedidos/{id}/ifood`, só admin) também é servidor e entra aqui; as ações vão
  pelo código (`assignDriver`…), traduzidas no console (convenção "texto que vem da API").

## Fora deste plano

- **ORDER_PATCHED (OPA)** (spec, seção 2: buscar o pedido de novo, atualizar entrega, observações e pagamento, push
  "Endereço alterado" ao motoboy): o plano 2A o deixou "para a etapa 3", mas não é da seção 3 e não entra aqui; continua só
  registrado. Fica como pendência para um plano próprio (antes da homologação, se o iFood testar alteração).
- **"Evento mais antigo que o estado atual é ignorado"** (spec, seção 2): sem efeito nesta etapa. Os eventos de etapa da
  entrega (GTO, AAO, DSP…) só vêm de entregador do iFood e continuam só registrados; o estado das nossas ações é a
  `ultima_acao`, que nunca volta.

## A conferir (a referência e a sonda não bastaram)

- **Troca de motoboy depois do `goingToOrigin`:** a documentação não diz se o iFood aceita outro `assignDriver`. Um 409
  vira recusa com aviso à central. Conferir com a loja de teste (Task 15).
- **Raio de 100 m da chegada** (`ChegadaPeloGps::RAIO_METROS`): adequado? Conferir na Task 15 e na etapa 5.
- **Como obter o código de entrega de um pedido de teste:** provavelmente na página de testes do Portal do
  Desenvolvedor (`developer.ifood.com.br`, área de testes do app) ou no Gestor de Pedidos; não está em nenhum campo do
  Logistics (sonda). Conferir na Task 15; sem ele, "Liberar sem código".
- **`fleetbase.protected` preenchido antes do nosso `pushMiddlewareToGroup`:** o `BarrarAceiteDePedidoEncerrado` já usa o
  mesmo padrão no `fleetbase.api`; conferir na Task 15 (`php artisan route:list` e o cancelamento barrado no console).
- **Formato do `workerPhone`:** a sonda aceitou "16999990000"; o iFood pode recusar outro formato (400 → recusa).
- **Resposta 2xx do `verifyDeliveryCode`:** a documentação diz `{"success": true|false}`; tratamos `success: false` como
  código incorreto e qualquer outro 2xx como certo.

## Arquivos

| Arquivo | Ação | Papel |
|---|---|---|
| `api/database/migrations/2026_10_06_120000_add_ciclo_to_entregas_ifood_pedidos_table.php` | Criar | Colunas do ciclo |
| `api/app/Support/Entregas/Ifood/SequenciaIfood.php` | Criar | Ordem das ações e alvo pelo estado (puro) |
| `api/app/Support/Entregas/Ifood/CobrancaIfood.php` | Criar | Texto da cobrança na porta (puro) |
| `api/app/Support/Entregas/Ifood/ChegadaPeloGps.php` | Criar | Chegada pelo GPS (puro) |
| `api/app/Support/Entregas/Ifood/ClienteIfood.php` | Modificar | `acaoLogistica`, `verificarCodigo`, `exigeAppNovo` |
| `api/app/Support/Entregas/Ifood/PedidosIfood.php` | Criar | Linha da `entregas_ifood_pedidos` pelo Order |
| `api/app/Events/Entregas/IfoodAcaoRecusada.php` | Criar | Aviso à central no socket |
| `api/app/Support/Entregas/Ifood/AcoesIfood.php` | Criar | Envio das ações, recusas, troca de motoboy |
| `api/app/Jobs/Entregas/EnviarAcaoIfood.php` | Criar | Job das ações (fila) |
| `api/app/Listeners/Entregas/ObservadorDosPedidosIfood.php` | Criar | Gancho do `Order::updated` e do `OrderDriverAssigned` |
| `api/app/Console/Commands/Entregas/AcompanharIfood.php` | Criar | `entregas:ifood-acompanhar` (GPS e reconciliação) |
| `api/app/Support/Entregas/Ifood/CancelamentoPeloIfood.php` | Criar | CAN: cancela, pago mesmo cancelado, texto do push |
| `api/app/Support/Entregas/Ifood/ConclusaoIfood.php` | Criar | Conclusão do app e código de entrega |
| `api/app/Support/Entregas/Ifood/DadosIfoodDoMotoboy.php` | Criar | O que o app recebe (puro) |
| `api/app/Http/Middleware/RegrasDoPedidoIfood.php` | Criar | Cancelamento proibido e trava "Atualize o app" |
| `api/app/Http/Controllers/Entregas/IfoodPedidosController.php` | Criar | Painel do console e "Liberar sem código" |
| `api/app/Jobs/Entregas/ProcessarPedidoIfood.php` | Modificar | CAN chama o `CancelamentoPeloIfood` |
| `api/app/Notifications/Entregas/AvisosDoMotoboy.php` | Modificar | Texto do push "cancelado pelo iFood" |
| `api/app/Support/Entregas/CalculoEntregas.php` | Modificar | Pago mesmo cancelado no relatório |
| `api/app/Support/Entregas/GanhosDoMotoboy.php` | Modificar | `cancelado_pago` no app |
| `api/app/Support/Entregas/CartaoDoAlarme.php` | Modificar | `entregas_ifood` e `entregas_cobrar` no alarme |
| `api/app/Http/Middleware/RegrasPortalLoja.php` | Modificar | Portal não cancela pedido iFood |
| `api/app/Http/Controllers/Entregas/MotoboyController.php` | Modificar | Rotas `ifood`, `concluir-ifood`, `codigo-ifood` |
| `api/app/Providers/AppServiceProvider.php` | Modificar | Registra o observador |
| `api/app/Providers/RouteServiceProvider.php` | Modificar | Rotas, limitador e middleware |
| `api/app/Console/Kernel.php` | Modificar | Agenda o `entregas:ifood-acompanhar` |
| `api/config/services.php` | Modificar | `services.ifood.exige_app_novo` |
| `deploy/docker-stack.yml`, `deploy/stack.env.example` | Modificar | `ENTREGAS_IFOOD_EXIGE_APP_NOVO` |
| `scripts/teste-php/stubs-ifood.php`, `stubs-ifood-fleetbase.php` | Modificar | O que o ciclo usa |
| `scripts/teste-php/ifood-*.php` (8 novos, 3 alterados), `ganhos-motoboy.php`, `cartao-do-alarme.php` | Criar/Modificar | Testes |
| `CLAUDE.md`, spec | Modificar | Documentação |

---

### Task 1: migration do ciclo e o banco em memória dos testes

**Files:**
- Create: `api/database/migrations/2026_10_06_120000_add_ciclo_to_entregas_ifood_pedidos_table.php`
- Modify: `scripts/teste-php/stubs-ifood.php` (Schema::table, colunas novas no esquema, coluna ausente = NULL)
- Test: `scripts/teste-php/ifood-migrations.php`

- [ ] **Step 1: teste das colunas novas**

Em `scripts/teste-php/ifood-migrations.php`, troque a linha:

```php
echo '== down' . PHP_EOL;
```

por:

```php
echo '== entregas_ifood_pedidos: ciclo da entrega (etapa 3)' . PHP_EOL;
Schema::$alteradas = [];
(require '/repo/api/database/migrations/2026_10_06_120000_add_ciclo_to_entregas_ifood_pedidos_table.php')->up();
$ciclo = [];
foreach (Schema::$alteradas['entregas_ifood_pedidos'][0]->colunas ?? [] as $coluna) {
    $ciclo[$coluna->argumentos[0]] = $coluna;
}
confere(tem($ciclo, 'motoboy_no_ifood', 'char', ['nullable']), 'motoboy_no_ifood (troca de motoboy)');
confere(tem($ciclo, 'recusa_acao', 'string', ['nullable']) && tem($ciclo, 'recusa_status', 'unsignedSmallInteger', ['nullable']) && tem($ciclo, 'recusa_em', 'timestamp', ['nullable']), 'última recusa do iFood');
confere(tem($ciclo, 'conclusao_liberada_em', 'timestamp', ['nullable']), 'conclusao_liberada_em (trava "Atualize o app")');
confere(tem($ciclo, 'conclusao_sem_codigo', 'boolean') && ($ciclo['conclusao_sem_codigo']->modificadores['default'] ?? null) === [false], 'conclusao_sem_codigo começa falso');
confere(excecao(fn () => \Teste\Banco::exigirColuna('entregas_ifood_pedidos', 'recusa_acao')) === null, 'o banco em memória dos testes conhece as colunas novas');
confere(excecao(fn () => \Teste\Banco::exigirColuna('entregas_ifood_pedidos', 'coluna_que_nao_existe')) !== null, 'e continua recusando coluna inexistente');

echo '== down' . PHP_EOL;
```

- [ ] **Step 2: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-migrations.php`
Expected: erro fatal (a migration não existe; `Schema::$alteradas` não existe no stub).

- [ ] **Step 3: a migration**

Crie `api/database/migrations/2026_10_06_120000_add_ciclo_to_entregas_ifood_pedidos_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: colunas do ciclo da entrega iFood (etapa 3; App\Support\Entregas\Ifood\AcoesIfood e
 * ConclusaoIfood). Ficam na entregas_ifood_pedidos, fora do `meta` do Order, como as da etapa 2.
 * - `motoboy_no_ifood`: o Driver do último assignDriver aceito; outro motoboy no pedido = troca (assignDriver de novo);
 * - `recusa_acao`, `recusa_status`, `recusa_em`: a última ação que o iFood recusou (4xx), para o painel do console e para
 *   o entregas:ifood-acompanhar não repetir sozinho uma ação recusada (uma ação aceita depois limpa as três);
 * - `conclusao_liberada_em`: o iFood já sabe que o motoboy chegou e, quando exigido, conferiu o código: a conclusão
 *   comum do app passa pela trava "Atualize o app" (RegrasDoPedidoIfood);
 * - `conclusao_sem_codigo`: o pedido exigia o código e foi concluído sem ele (a central liberou no console, ou concluiu
 *   ela mesma): o iFood fica com a confirmação pendente e conclui sozinho 4 h depois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entregas_ifood_pedidos', function (Blueprint $table) {
            $table->char('motoboy_no_ifood', 36)->nullable();
            $table->string('recusa_acao', 30)->nullable();
            $table->unsignedSmallInteger('recusa_status')->nullable();
            $table->timestamp('recusa_em')->nullable();
            $table->timestamp('conclusao_liberada_em')->nullable();
            $table->boolean('conclusao_sem_codigo')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('entregas_ifood_pedidos', function (Blueprint $table) {
            $table->dropColumn(['motoboy_no_ifood', 'recusa_acao', 'recusa_status', 'recusa_em', 'conclusao_liberada_em', 'conclusao_sem_codigo']);
        });
    }
};
```

- [ ] **Step 4: o stub entende `Schema::table`**

Em `scripts/teste-php/stubs-ifood.php`, na classe `Schema` (namespace `Illuminate\Support\Facades`), troque:

```php
        public static function dropIfExists(string $tabela): void { unset(self::$criadas[$tabela]); }
    }
```

por:

```php
        public static function dropIfExists(string $tabela): void { unset(self::$criadas[$tabela]); }

        /** Schema::table (migration que acrescenta colunas): tabela => [Blueprint, ...], na ordem. */
        public static array $alteradas = [];

        public static function table(string $tabela, \Closure $definicao): void
        {
            $blueprint = new \Illuminate\Database\Schema\Blueprint();
            $definicao($blueprint);
            self::$alteradas[$tabela][] = $blueprint;
        }
    }
```

Ainda no `stubs-ifood.php`, troque o método `esquema()` inteiro da classe `Banco` por:

```php
        private static function esquema(): array
        {
            if (self::$esquema !== null) {
                return self::$esquema;
            }
            $guardadas  = \Illuminate\Support\Facades\Schema::$criadas;
            $alteradas  = \Illuminate\Support\Facades\Schema::$alteradas;
            self::$esquema = [];
            // as que criam e as que acrescentam colunas (Schema::table), na ordem dos arquivos (a data no nome)
            foreach (glob(dirname(__DIR__, 2) . '/api/database/migrations/*_entregas_ifood_*_table.php') ?: [] as $arquivo) {
                \Illuminate\Support\Facades\Schema::$criadas   = [];
                \Illuminate\Support\Facades\Schema::$alteradas = [];
                (require $arquivo)->up();
                $blueprints = [];
                foreach (\Illuminate\Support\Facades\Schema::$criadas as $tabela => $blueprint) {
                    self::$esquema[$tabela] = ['colunas' => [], 'unicas' => []];
                    $blueprints[]           = [$tabela, $blueprint];
                }
                foreach (\Illuminate\Support\Facades\Schema::$alteradas as $tabela => $lista) {
                    foreach ($lista as $blueprint) {
                        $blueprints[] = [$tabela, $blueprint];
                    }
                }
                foreach ($blueprints as [$tabela, $blueprint]) {
                    $colunas = [];
                    $unicas  = [];
                    foreach ($blueprint->colunas as $coluna) {
                        $primeiro = $coluna->argumentos[0] ?? null;
                        if ($coluna->tipo === 'timestamps') {
                            array_push($colunas, 'created_at', 'updated_at');
                        } elseif ($coluna->tipo === 'id') {
                            $colunas[] = $primeiro ?? 'id';
                        } elseif (is_string($primeiro)) {
                            // index([...]) e afins não são colunas (o primeiro argumento é uma lista)
                            $colunas[] = $primeiro;
                            if (array_key_exists('unique', $coluna->modificadores)) {
                                $unicas[] = $primeiro;
                            }
                        }
                    }
                    self::$esquema[$tabela] = [
                        'colunas' => array_merge(self::$esquema[$tabela]['colunas'] ?? [], $colunas),
                        'unicas'  => array_merge(self::$esquema[$tabela]['unicas'] ?? [], $unicas),
                    ];
                }
            }
            \Illuminate\Support\Facades\Schema::$criadas   = $guardadas;
            \Illuminate\Support\Facades\Schema::$alteradas = $alteradas;

            return self::$esquema;
        }
```

E, no `Banco::inserir`, troque:

```php
            $id                             = self::$proximoId[$tabela] = (self::$proximoId[$tabela] ?? 0) + 1;
            self::$tabelas[$tabela][$id] = ['id' => $id] + $linha;
```

por:

```php
            $id                             = self::$proximoId[$tabela] = (self::$proximoId[$tabela] ?? 0) + 1;
            // como o MySQL, a coluna que o insert não trouxe existe na linha (NULL; os defaults das migrations não são lidos)
            self::$tabelas[$tabela][$id] = ['id' => $id] + $linha + array_fill_keys(self::esquema()[$tabela]['colunas'] ?? [], null);
```

- [ ] **Step 5: rodar os testes do iFood**

Run: `for t in scripts/teste-php/ifood-*.php; do echo "$t $(PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs $t 2>&1 | tail -1)"; done`
Expected: todos com `FALHAS: 0` (os antigos continuam passando; o `ifood-migrations.php` passa com os casos novos).

- [ ] **Step 6: commit**

```bash
git rev-parse --show-toplevel
git add api/database/migrations/2026_10_06_120000_add_ciclo_to_entregas_ifood_pedidos_table.php scripts/teste-php/stubs-ifood.php scripts/teste-php/ifood-migrations.php
git commit -m "iFood etapa 3: colunas do ciclo da entrega (troca de motoboy, recusa, conclusão liberada)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: funções puras (ordem das ações, cobrança e chegada pelo GPS)

**Files:**
- Create: `api/app/Support/Entregas/Ifood/SequenciaIfood.php`
- Create: `api/app/Support/Entregas/Ifood/CobrancaIfood.php`
- Create: `api/app/Support/Entregas/Ifood/ChegadaPeloGps.php`
- Test: `scripts/teste-php/ifood-sequencia.php`

- [ ] **Step 1: o teste**

Crie `scripts/teste-php/ifood-sequencia.php`:

```php
<?php

// Integração iFood (etapa 3): funções puras da ordem das ações (SequenciaIfood), do texto da cobrança (CobrancaIfood) e
// da chegada pelo GPS (ChegadaPeloGps).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-sequencia.php

require __DIR__ . '/stubs-ifood.php';

use App\Support\Entregas\Ifood\ChegadaPeloGps;
use App\Support\Entregas\Ifood\CobrancaIfood;
use App\Support\Entregas\Ifood\SequenciaIfood;

// --- SequenciaIfood ---
confere(SequenciaIfood::faltando(null, 'goingToOrigin') === ['assignDriver', 'goingToOrigin'], 'nada enviado: assignDriver e goingToOrigin');
confere(SequenciaIfood::faltando('goingToOrigin', 'dispatch') === ['arrivedAtOrigin', 'dispatch'], 'GPS falhou e tocou "A caminho": arrivedAtOrigin e dispatch');
confere(SequenciaIfood::faltando('dispatch', 'dispatch') === [], 'alvo igual à última: nada');
confere(SequenciaIfood::faltando('dispatch', 'goingToOrigin') === [], 'alvo atrás da última: nada (não volta etapa)');
confere(SequenciaIfood::faltando('assignDriver', null) === [], 'sem alvo: nada');
confere(SequenciaIfood::faltando('acaoInventada', 'assignDriver') === ['assignDriver'], 'última desconhecida vale como nenhuma');
confere(SequenciaIfood::maisAdiante(null, 'assignDriver', 'dispatch', 'goingToOrigin') === 'dispatch', 'mais adiante: dispatch');
confere(SequenciaIfood::maisAdiante(null, null) === null, 'mais adiante sem nenhuma: null');

confere(SequenciaIfood::alvoPeloPedido('dispatched', false, null) === null, 'sem motoboy: nada');
confere(SequenciaIfood::alvoPeloPedido('dispatched', false, 'driver-1') === 'assignDriver', 'com motoboy: assignDriver');
confere(SequenciaIfood::alvoPeloPedido('dispatched', true, 'driver-1') === 'goingToOrigin', 'iniciado (antes da atividade): goingToOrigin');
confere(SequenciaIfood::alvoPeloPedido('started', true, 'driver-1') === 'goingToOrigin', 'status started: goingToOrigin');
confere(SequenciaIfood::alvoPeloPedido('enroute', true, 'driver-1') === 'dispatch', 'enroute: dispatch');
confere(SequenciaIfood::alvoPeloPedido('completed', true, 'driver-1') === 'arrivedAtDestination', 'concluído: arrivedAtDestination');
confere(SequenciaIfood::alvoPeloPedido('canceled', true, 'driver-1') === null, 'cancelado: nada');
confere(SequenciaIfood::alvoPeloPedido('expired', false, 'driver-1') === null, 'expirado: nada');

confere(SequenciaIfood::saiuParaEntrega('dispatch') && SequenciaIfood::saiuParaEntrega('arrivedAtDestination'), 'dispatch aceito: saiu para entrega');
confere(!SequenciaIfood::saiuParaEntrega('arrivedAtOrigin') && !SequenciaIfood::saiuParaEntrega(null), 'antes do dispatch: não saiu');

// --- CobrancaIfood ---
confere(CobrancaIfood::texto(0, 'CASH', 10000) === null, 'nada a cobrar: null');
confere(CobrancaIfood::texto(5890, 'CASH', 10000) === 'Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100', 'dinheiro com troco (exemplo da spec)');
confere(CobrancaIfood::texto(5890, 'CASH', 5000) === 'Cobrar R$ 58,90 · dinheiro', 'troco menor que o valor não aparece');
confere(CobrancaIfood::texto(5890, 'CASH', 10050) === 'Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100,50', 'troco com centavos');
confere(CobrancaIfood::texto(5890, 'CREDIT', 10000) === 'Cobrar R$ 58,90 · cartão de crédito', 'troco só vale para dinheiro');
confere(CobrancaIfood::texto(123456, 'CASH+CREDIT', 200000) === 'Cobrar R$ 1.234,56 · dinheiro + cartão de crédito · troco p/ R$ 2.000', 'duas formas e milhar');
confere(CobrancaIfood::texto(5890, null, null) === 'Cobrar R$ 58,90', 'sem forma');
confere(CobrancaIfood::texto(5890, 'MISTO', null) === 'Cobrar R$ 58,90 · formas variadas', 'MISTO');
confere(CobrancaIfood::texto(5890, 'BOLETO_X', null) === 'Cobrar R$ 58,90 · boleto_x', 'forma desconhecida em minúsculas');

// --- ChegadaPeloGps (loja em -21.1775, -47.8103; 0,0009° de latitude ≈ 100 m) ---
$loja    = [-21.1775, -47.8103];
$cliente = [-21.1685, -47.8103];
confere(ChegadaPeloGps::acao('goingToOrigin', [-21.1780, -47.8103], $loja, $cliente) === 'arrivedAtOrigin', 'indo à loja e a ~56 m: arrivedAtOrigin');
confere(ChegadaPeloGps::acao('goingToOrigin', [-21.1795, -47.8103], $loja, $cliente) === null, 'indo à loja e a ~220 m: nada');
confere(ChegadaPeloGps::acao('assignDriver', [-21.1775, -47.8103], $loja, $cliente) === null, 'ainda não iniciou: nada');
confere(ChegadaPeloGps::acao('arrivedAtOrigin', [-21.1685, -47.8103], $loja, $cliente) === null, 'na loja (sem dispatch) e no cliente: nada');
confere(ChegadaPeloGps::acao('dispatch', [-21.1690, -47.8103], $loja, $cliente) === 'arrivedAtDestination', 'saiu para entrega e a ~56 m do cliente: arrivedAtDestination');
confere(ChegadaPeloGps::acao('dispatch', [-21.1775, -47.8103], $loja, $cliente) === null, 'saiu para entrega e ainda na loja: nada');
confere(ChegadaPeloGps::acao('dispatch', [0.0, 0.0], $loja, $cliente) === null, 'sem GPS (0, 0): nada');
confere(ChegadaPeloGps::acao('dispatch', null, $loja, $cliente) === null, 'sem posição: nada');
confere(ChegadaPeloGps::acao('dispatch', [-21.1690, -47.8103], $loja, null) === null, 'entrega sem coordenada: nada');

resumo();
```

- [ ] **Step 2: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-sequencia.php`
Expected: erro fatal `Class "App\Support\Entregas\Ifood\SequenciaIfood" not found`.

- [ ] **Step 3: `SequenciaIfood`**

Crie `api/app/Support/Entregas/Ifood/SequenciaIfood.php`:

```php
<?php

namespace App\Support\Entregas\Ifood;

use App\Support\Entregas\StatusDoPedido;

/**
 * Entregas RestaurantePro: a ordem das ações de logística do iFood e o que cada estado do pedido no Fleetbase pede.
 * Funções puras.
 *
 * O iFood exige a ordem assignDriver → goingToOrigin → arrivedAtOrigin → dispatch → arrivedAtDestination (fora dela,
 * 409) e não devolve evento das nossas ações (sonda de 2026-10-05): a coluna `ultima_acao` da entregas_ifood_pedidos é a
 * única fonte do que já foi aceito. faltando() lista as ações a enviar, em ordem, para chegar a uma ação alvo (ex.: o GPS
 * falhou e o motoboy tocou "A caminho": arrivedAtOrigin e depois dispatch).
 *
 * alvoPeloPedido() traduz o estado do pedido (fluxo transport do Fleet-Ops: created → dispatched → started → enroute →
 * completed; FleetOps.php do fleetops-api) na ação que ele pede:
 * - com motoboy atribuído (aceite do pedido aberto ou atribuição pela central) → assignDriver;
 * - iniciado (`started`, gravado pelo startOrder antes da atividade) → goingToOrigin;
 * - status enroute ("A caminho" no app) → dispatch;
 * - status completed (conclusão pelo app ou pela central) → arrivedAtDestination (o código, quando exigido, é a rota
 *   codigo-ifood do app; a central que conclui no console não confere código);
 * - sem motoboy, ou cancelado/expirado → nada.
 * As chegadas (arrivedAtOrigin e, antes da conclusão, arrivedAtDestination) não têm estado no Fleetbase: vêm do GPS
 * (ChegadaPeloGps) ou da conclusão do app (ConclusaoIfood).
 */
final class SequenciaIfood
{
    public const ATRIBUIR          = 'assignDriver';
    public const INDO_A_LOJA       = 'goingToOrigin';
    public const CHEGOU_NA_LOJA    = 'arrivedAtOrigin';
    public const SAIU_PARA_ENTREGA = 'dispatch';
    public const CHEGOU_NO_CLIENTE = 'arrivedAtDestination';

    /** As ações, na ordem que o iFood exige. */
    public const ACOES = [self::ATRIBUIR, self::INDO_A_LOJA, self::CHEGOU_NA_LOJA, self::SAIU_PARA_ENTREGA, self::CHEGOU_NO_CLIENTE];

    /** Posição da ação na ordem; -1 para nenhuma (null) ou desconhecida. */
    public static function posicao(?string $acao): int
    {
        $posicao = $acao === null ? false : array_search($acao, static::ACOES, true);

        return $posicao === false ? -1 : $posicao;
    }

    /** As ações depois de $ultima até $alvo (inclusive), em ordem; [] se o alvo não está adiante da última. */
    public static function faltando(?string $ultima, ?string $alvo): array
    {
        $de  = static::posicao($ultima);
        $ate = static::posicao($alvo);

        return $ate > $de ? array_slice(static::ACOES, $de + 1, $ate - $de) : [];
    }

    /** A mais adiantada das ações dadas (null e desconhecidas não contam), ou null. */
    public static function maisAdiante(?string ...$acoes): ?string
    {
        $melhor = null;
        foreach ($acoes as $acao) {
            if (static::posicao($acao) > static::posicao($melhor)) {
                $melhor = $acao;
            }
        }

        return $melhor;
    }

    /** A ação que o estado do pedido pede (ver o docblock da classe), ou null. */
    public static function alvoPeloPedido(?string $status, bool $iniciado, ?string $motoboyUuid): ?string
    {
        if ($motoboyUuid === null || $motoboyUuid === '') {
            return null;
        }
        if ($status === 'completed') {
            return static::CHEGOU_NO_CLIENTE;
        }
        if (in_array($status, StatusDoPedido::ENCERRADOS, true)) {
            return null;
        }
        if ($status === 'enroute') {
            return static::SAIU_PARA_ENTREGA;
        }

        return $iniciado ? static::INDO_A_LOJA : static::ATRIBUIR;
    }

    /** O dispatch já foi aceito pelo iFood: um CAN daqui em diante vale como entrega paga (pago_mesmo_cancelado). */
    public static function saiuParaEntrega(?string $ultima): bool
    {
        return static::posicao($ultima) >= static::posicao(static::SAIU_PARA_ENTREGA);
    }
}
```

- [ ] **Step 4: `CobrancaIfood`**

Crie `api/app/Support/Entregas/Ifood/CobrancaIfood.php`:

```php
<?php

namespace App\Support\Entregas\Ifood;

/**
 * Entregas RestaurantePro: o texto da cobrança na porta de um pedido iFood, para o motoboy (alarme, card de aceitar e
 * detalhes do pedido no app): "Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100". Função pura sobre as colunas da
 * entregas_ifood_pedidos (cobrar_centavos, forma_pagamento, troco_para_centavos), gravadas pelo PedidoDoIfood.
 *
 * - Nada a cobrar (0, pago online) = null: o app não mostra a faixa.
 * - A forma vem como o iFood manda, em maiúsculas (CASH, CREDIT, DEBIT…), duas formas juntas com "+" ou "MISTO"
 *   (PedidoDoIfood::cobranca). Forma desconhecida sai em minúsculas, como veio.
 * - O troco só aparece com dinheiro entre as formas e quando é maior que o valor a cobrar (troco para R$ 50 num pedido
 *   de R$ 58,90 não faz sentido: o cliente pagaria a diferença de outro jeito).
 */
final class CobrancaIfood
{
    /** Formas de pagamento do iFood em pt-BR (o que não estiver aqui sai em minúsculas). */
    public const FORMAS = [
        'CASH'           => 'dinheiro',
        'CREDIT'         => 'cartão de crédito',
        'DEBIT'          => 'cartão de débito',
        'MEAL_VOUCHER'   => 'vale-refeição',
        'FOOD_VOUCHER'   => 'vale-alimentação',
        'PIX'            => 'Pix',
        'DIGITAL_WALLET' => 'carteira digital',
        'MISTO'          => 'formas variadas',
    ];

    /** "Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100", ou null quando não há nada a cobrar na porta. */
    public static function texto(int $centavos, ?string $forma, ?int $trocoParaCentavos): ?string
    {
        if ($centavos <= 0) {
            return null;
        }

        $partes = ['Cobrar ' . static::reais($centavos)];

        $formaLegivel = static::forma($forma);
        if ($formaLegivel !== null) {
            $partes[] = $formaLegivel;
        }

        if ($trocoParaCentavos !== null && $trocoParaCentavos > $centavos && static::temDinheiro($forma)) {
            $partes[] = 'troco p/ ' . static::reais($trocoParaCentavos, true);
        }

        return implode(' · ', $partes);
    }

    /** A forma em pt-BR ("CASH+CREDIT" → "dinheiro + cartão de crédito"), ou null sem forma. */
    public static function forma(?string $forma): ?string
    {
        $forma = $forma === null ? '' : strtoupper(trim($forma));
        if ($forma === '') {
            return null;
        }

        $nomes = array_map(
            fn (string $parte) => static::FORMAS[$parte] ?? strtolower($parte),
            array_values(array_filter(array_map('trim', explode('+', $forma)), fn (string $parte) => $parte !== ''))
        );

        return $nomes ? implode(' + ', $nomes) : null;
    }

    /** "R$ 58,90"; com $semCentavosSeInteiro, "R$ 100" em vez de "R$ 100,00". */
    public static function reais(int $centavos, bool $semCentavosSeInteiro = false): string
    {
        if ($semCentavosSeInteiro && $centavos % 100 === 0) {
            return 'R$ ' . number_format(intdiv($centavos, 100), 0, ',', '.');
        }

        return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
    }

    protected static function temDinheiro(?string $forma): bool
    {
        return in_array('CASH', array_map('trim', explode('+', strtoupper((string) $forma))), true);
    }
}
```

- [ ] **Step 5: `ChegadaPeloGps`**

Crie `api/app/Support/Entregas/Ifood/ChegadaPeloGps.php`:

```php
<?php

namespace App\Support\Entregas\Ifood;

use App\Support\Entregas\Coordenada;

/**
 * Entregas RestaurantePro: chegada do motoboy à coleta ou à entrega pela última posição do GPS (entregas:ifood-acompanhar,
 * a cada 30 s). Função pura: dada a etapa em que o pedido está (a mais adiantada entre a última ação aceita pelo iFood e
 * a que o estado do pedido pede: SequenciaIfood::alvoPeloPedido), a posição do motoboy e as duas paradas, diz a ação de
 * chegada a enviar, ou null.
 *
 * - etapa goingToOrigin (indo à loja) e motoboy a até RAIO_METROS da coleta → arrivedAtOrigin;
 * - etapa dispatch (saiu para a entrega) e motoboy a até RAIO_METROS da entrega → arrivedAtDestination;
 * - qualquer outra etapa, posição ausente ou sem GPS (0, 0), parada sem coordenada → null.
 *
 * O raio de 100 m é da spec; se é adequado é ponto a conferir na etapa 5 (a coleta é o Local da loja, e o motoboy
 * costuma parar na frente).
 */
final class ChegadaPeloGps
{
    public const RAIO_METROS = 100;

    /**
     * @param array{0: float, 1: float}|null $motoboy [latitude, longitude]
     * @param array{0: float, 1: float}|null $coleta
     * @param array{0: float, 1: float}|null $entrega
     */
    public static function acao(?string $etapa, ?array $motoboy, ?array $coleta, ?array $entrega, float $raio = self::RAIO_METROS): ?string
    {
        if (!static::valida($motoboy)) {
            return null;
        }

        if ($etapa === SequenciaIfood::INDO_A_LOJA && static::valida($coleta) && static::metros($motoboy, $coleta) <= $raio) {
            return SequenciaIfood::CHEGOU_NA_LOJA;
        }

        if ($etapa === SequenciaIfood::SAIU_PARA_ENTREGA && static::valida($entrega) && static::metros($motoboy, $entrega) <= $raio) {
            return SequenciaIfood::CHEGOU_NO_CLIENTE;
        }

        return null;
    }

    protected static function valida(?array $ponto): bool
    {
        return is_array($ponto) && count($ponto) === 2 && Coordenada::valida($ponto[0] ?? null, $ponto[1] ?? null);
    }

    protected static function metros(array $a, array $b): float
    {
        return PedidoDoIfood::metrosEntre((float) $a[0], (float) $a[1], (float) $b[0], (float) $b[1]);
    }
}
```

- [ ] **Step 6: rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-sequencia.php`
Expected: `FALHAS: 0`.

- [ ] **Step 7: commit**

```bash
git rev-parse --show-toplevel
git add api/app/Support/Entregas/Ifood/SequenciaIfood.php api/app/Support/Entregas/Ifood/CobrancaIfood.php api/app/Support/Entregas/Ifood/ChegadaPeloGps.php scripts/teste-php/ifood-sequencia.php
git commit -m "iFood etapa 3: ordem das ações, texto da cobrança e chegada pelo GPS (funções puras)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: ações de logística e código de entrega no `ClienteIfood`

**Files:**
- Modify: `api/app/Support/Entregas/Ifood/ClienteIfood.php`
- Modify: `api/config/services.php`
- Modify: `scripts/teste-php/stubs-ifood.php` (`send` no Http falso)
- Test: `scripts/teste-php/ifood-cliente-acoes.php`

- [ ] **Step 1: o teste**

Crie `scripts/teste-php/ifood-cliente-acoes.php`:

```php
<?php

// Integração iFood (etapa 3): as ações de logística e o código de entrega no ClienteIfood.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-cliente-acoes.php

require __DIR__ . '/stubs-ifood.php';

use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\ErroIfood;
use Teste\Http;

echo '== Ações de logística' . PHP_EOL;
reiniciarIfood();
Http::responder(202);
(new ClienteIfood())->acaoLogistica('token-a', 'pedido-real-1', 'goingToOrigin');
confere(Http::urls() === ['POST /logistics/v1.0/orders/pedido-real-1/goingToOrigin'], 'POST na URL da ação');
confere(Http::$chamadas[0]['dados'] === null && Http::$chamadas[0]['token'] === 'token-a' && Http::$chamadas[0]['timeout'] === 15, 'sem corpo, com o token e o tempo limite');

reiniciarIfood();
Http::responder(202);
(new ClienteIfood())->acaoLogistica('token-a', 'pedido/estranho', 'assignDriver', ['workerName' => 'Fulano', 'workerPhone' => '16999990000', 'workerVehicleType' => 'MOTORCYCLE']);
confere(Http::urls() === ['POST /logistics/v1.0/orders/pedido%2Festranho/assignDriver'], 'id do pedido codificado na URL');
confere(Http::$chamadas[0]['dados'] === ['workerName' => 'Fulano', 'workerPhone' => '16999990000', 'workerVehicleType' => 'MOTORCYCLE'], 'assignDriver com o corpo');

reiniciarIfood();
$erro = excecao(fn () => (new ClienteIfood())->acaoLogistica('token-a', 'pedido-real-1', 'cancel'));
confere($erro instanceof InvalidArgumentException && Http::$chamadas === [], 'ação desconhecida: nem chama o iFood');

reiniciarIfood();
Http::responder(409, ['errorType' => 'CONFLICT', 'description' => 'Invalid state']);
$erro = excecao(fn () => (new ClienteIfood())->acaoLogistica('token-a', 'pedido-real-1', 'dispatch'));
confere($erro instanceof ErroIfood && $erro->status === 409 && $erro->operacao === 'dispatch' && str_contains($erro->corpo, 'Invalid state'), '409: ErroIfood com a ação e o corpo');

reiniciarIfood();
Http::responder(429, null, ['Retry-After' => '30']);
$erro = excecao(fn () => (new ClienteIfood())->acaoLogistica('token-a', 'pedido-real-1', 'dispatch'));
confere($erro instanceof ErroIfood && $erro->limiteExcedido() && $erro->retryAfter === 30, '429 com o Retry-After');

echo '== Código de entrega' . PHP_EOL;
reiniciarIfood();
Http::responder(200, ['success' => true]);
$resposta = (new ClienteIfood())->verificarCodigo('token-a', 'pedido-real-1', '1234');
confere(Http::urls() === ['POST /logistics/v1.0/orders/pedido-real-1/verifyDeliveryCode'] && Http::$chamadas[0]['dados'] === ['code' => '1234'], 'verifyDeliveryCode com {code}');
confere($resposta === ['success' => true], 'devolve o JSON');

reiniciarIfood();
Http::responder(400, ['errorType' => 'NOT_FOUND', 'description' => 'Confirmation code is invalid', 'code' => '400']);
$erro = excecao(fn () => (new ClienteIfood())->verificarCodigo('token-a', 'pedido-real-1', '9999'));
confere($erro instanceof ErroIfood && $erro->status === 400 && $erro->operacao === 'verifyDeliveryCode', 'código errado (sonda): ErroIfood 400');
$rastro = (new ReflectionMethod(ClienteIfood::class, 'verificarCodigo'))->getParameters()[2]->getAttributes(SensitiveParameter::class);
confere(count($rastro) === 1, 'o código fica fora do stack trace (#[\SensitiveParameter])');

echo '== Trava "Atualize o app"' . PHP_EOL;
reiniciarIfood();
confere(ClienteIfood::exigeAppNovo() === false, 'desligada por padrão');
\Teste\Config::$valores['services.ifood.exige_app_novo'] = '1';
confere(ClienteIfood::exigeAppNovo() === true, 'ENTREGAS_IFOOD_EXIGE_APP_NOVO=1 liga');

resumo();
```

- [ ] **Step 2: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-cliente-acoes.php`
Expected: erro fatal `Call to undefined method ...ClienteIfood::acaoLogistica()`.

- [ ] **Step 3: POST sem corpo no Http falso**

Em `scripts/teste-php/stubs-ifood.php`, na classe `PedidoHttp`, troque:

```php
        public function post($url, $dados = []) { return $this->enviar('POST', $url, $dados); }
```

por:

```php
        public function post($url, $dados = []) { return $this->enviar('POST', $url, $dados); }
        // send('POST', $url) sem opções = POST sem corpo (as ações de logística do iFood); dados = null
        public function send($metodo, $url, array $opcoes = []) { return $this->enviar(strtoupper($metodo), $url, $opcoes['json'] ?? null); }
```

- [ ] **Step 4: `ClienteIfood`**

Em `api/app/Support/Entregas/Ifood/ClienteIfood.php`, troque:

```php
    /** Código de vínculo (userCode, authorizationCodeVerifier, verificationUrl, verificationUrlComplete, expiresIn). */
```

por:

```php
    /**
     * Trava "Atualize o app" ligada (ENTREGAS_IFOOD_EXIGE_APP_NOVO=1): a conclusão comum de pedido iFood (atividade
     * "completed" da API v1) só passa depois da rota concluir-ifood do APK novo (RegrasDoPedidoIfood). Desligada por
     * padrão: só ligar com o APK novo em todos os celulares.
     */
    public static function exigeAppNovo(): bool
    {
        return filter_var(config('services.ifood.exige_app_novo'), FILTER_VALIDATE_BOOLEAN);
    }

    /** Código de vínculo (userCode, authorizationCodeVerifier, verificationUrl, verificationUrlComplete, expiresIn). */
```

E troque:

```php
    protected function token(string $operacao, #[\SensitiveParameter] array $campos): array
```

por:

```php
    /**
     * Ação de logística (POST /logistics/v1.0/orders/{id}/<acao>, SequenciaIfood::ACOES), que responde 202 sem corpo e
     * sem evento de volta (sonda de 2026-10-05). Só o assignDriver leva corpo ({workerName, workerPhone,
     * workerVehicleType}); as outras vão sem corpo nenhum, como na sonda. O ErroIfood leva o nome da ação na operação.
     */
    public function acaoLogistica(#[\SensitiveParameter] string $token, string $pedidoId, string $acao, ?array $corpo = null): void
    {
        if (!in_array($acao, SequenciaIfood::ACOES, true)) {
            throw new InvalidArgumentException("ação de logística desconhecida: {$acao}");
        }

        $url = $this->baseUrl . '/logistics/v1.0/orders/' . rawurlencode($pedidoId) . '/' . $acao;
        $this->enviar($acao, fn () => $corpo === null
            ? $this->comToken($token)->send('POST', $url)
            : $this->comToken($token)->post($url, $corpo));
    }

    /**
     * Confere o código de entrega que o cliente passou ao motoboy (POST .../verifyDeliveryCode com {"code"}). Devolve o
     * JSON da resposta 2xx (a documentação diz {"success": true|false}). Código errado veio como HTTP 400
     * "Confirmation code is invalid" na sonda (a documentação dizia 422): os dois chegam aqui como ErroIfood.
     */
    public function verificarCodigo(#[\SensitiveParameter] string $token, string $pedidoId, #[\SensitiveParameter] string $codigo): array
    {
        $resposta = $this->enviar('verifyDeliveryCode', fn () => $this->comToken($token)
            ->post($this->baseUrl . '/logistics/v1.0/orders/' . rawurlencode($pedidoId) . '/verifyDeliveryCode', ['code' => $codigo]));

        return (array) $resposta->json();
    }

    protected function token(string $operacao, #[\SensitiveParameter] array $campos): array
```

- [ ] **Step 5: configuração**

Em `api/config/services.php`, troque:

```php
        'base_url'      => env('IFOOD_BASE_URL', 'https://merchant-api.ifood.com.br'),
```

por:

```php
        'base_url'      => env('IFOOD_BASE_URL', 'https://merchant-api.ifood.com.br'),
        // trava "Atualize o app" (RegrasDoPedidoIfood): 1 só depois do APK com a conclusão iFood em todos os celulares
        'exige_app_novo' => env('ENTREGAS_IFOOD_EXIGE_APP_NOVO'),
```

- [ ] **Step 6: rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-cliente-acoes.php && PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-cliente.php`
Expected: os dois com `FALHAS: 0`.

- [ ] **Step 7: commit**

```bash
git rev-parse --show-toplevel
git add api/app/Support/Entregas/Ifood/ClienteIfood.php api/config/services.php scripts/teste-php/stubs-ifood.php scripts/teste-php/ifood-cliente-acoes.php
git commit -m "iFood etapa 3: ações de logística e verifyDeliveryCode no ClienteIfood

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: `AcoesIfood` (envio em ordem, recusa, troca de motoboy) e o aviso à central

**Files:**
- Create: `api/app/Support/Entregas/Ifood/PedidosIfood.php`
- Create: `api/app/Events/Entregas/IfoodAcaoRecusada.php`
- Create: `api/app/Support/Entregas/Ifood/AcoesIfood.php`
- Modify: `scripts/teste-php/stubs-ifood.php` (`apiError`, `subHours`)
- Modify: `scripts/teste-php/stubs-ifood-fleetbase.php` (Driver, Order, Request, Route, `app()`)
- Test: `scripts/teste-php/ifood-acoes.php`

- [ ] **Step 1: o teste**

Crie `scripts/teste-php/ifood-acoes.php`:

```php
<?php

// Integração iFood (etapa 3): ações de logística (AcoesIfood), o job EnviarAcaoIfood e o ObservadorDosPedidosIfood.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-acoes.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Jobs\Entregas\EnviarAcaoIfood;
use App\Listeners\Entregas\ObservadorDosPedidosIfood;
use App\Support\Entregas\Ifood\AcoesIfood;
use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\ErroIfood;
use App\Support\Entregas\Ifood\VinculosIfood;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\Cache;
use Teste\Banco;
use Teste\Config;
use Teste\Fila;
use Teste\Http;
use Teste\Socket;
use Teste\Trava;

/** Loja A vinculada, motoboys driver-1 e driver-2, o Order order-1 e a linha do pedido iFood; devolve o Order. */
function pedidoIfood(array $order = [], array $linha = []): Order
{
    reiniciarFleetbase();
    reiniciarIfood();
    vinculoDaLojaA();
    Driver::$todos[] = new Driver(['uuid' => 'driver-1', 'public_id' => 'driver_1', 'company_uuid' => 'empresa-1', 'name' => 'Motoboy Ficticio', 'phone' => '+5516999990000']);
    Driver::$todos[] = new Driver(['uuid' => 'driver-2', 'public_id' => 'driver_2', 'company_uuid' => 'empresa-1', 'name' => 'Outro Ficticio', 'phone' => '+5516988880000']);
    $pedido          = new Order($order + ['uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1', 'status' => 'started', 'started' => true, 'driver_assigned_uuid' => 'driver-1']);
    Order::$todos[]  = $pedido;
    Banco::inserir('entregas_ifood_pedidos', $linha + [
        'company_uuid' => 'empresa-1', 'order_uuid' => 'order-1', 'pedido_ifood_id' => 'pedido-real-1', 'numero' => '4821',
        'merchant_id' => 'merchant-1', 'vendor_uuid' => 'vendor-a', 'created_at' => '2026-10-05 14:50:00', 'updated_at' => '2026-10-05 14:50:00',
    ], false);

    return $pedido;
}

function linha(): object
{
    return (new Teste\Consulta('entregas_ifood_pedidos'))->where('order_uuid', 'order-1')->first();
}

function acoes(): AcoesIfood
{
    return new AcoesIfood(new VinculosIfood(new ClienteIfood()), new ClienteIfood());
}

echo '== Aceite do pedido aberto: assignDriver e goingToOrigin, em ordem' . PHP_EOL;
pedidoIfood();
Http::responder(202);
Http::responder(202);
$resultado = acoes()->sincronizar('order-1');
confere(Http::urls() === ['POST /logistics/v1.0/orders/pedido-real-1/assignDriver', 'POST /logistics/v1.0/orders/pedido-real-1/goingToOrigin'], 'as duas ações, na ordem');
confere(Http::$chamadas[0]['dados'] === ['workerName' => 'Motoboy Ficticio', 'workerPhone' => '16999990000', 'workerVehicleType' => 'MOTORCYCLE'], 'assignDriver com nome, telefone sem o 55 e MOTORCYCLE');
confere(Http::$chamadas[1]['dados'] === null && Http::$chamadas[1]['token'] === 'token-a', 'goingToOrigin sem corpo, com o token da loja');
confere(linha()->ultima_acao === 'goingToOrigin' && linha()->motoboy_no_ifood === 'driver-1', 'ultima_acao e motoboy_no_ifood gravados');
confere($resultado === ['enviadas' => ['assignDriver', 'goingToOrigin'], 'recusada' => null], 'resultado com as enviadas');
confere(logou('ação enviada', 'info') && logsSem(['Motoboy Ficticio', '16999990000', '5516999990000']), 'log sem o nome e o telefone do motoboy');
confere(!isset(Trava::$ocupadas['entregas:ifood-acao:order-1']) && (Trava::$validades['entregas:ifood-acao:order-1'] ?? null) === 90, 'com a trava das ações do pedido (90 s), solta no fim');

echo '== Só atribuído pela central: só assignDriver' . PHP_EOL;
pedidoIfood(['status' => 'dispatched', 'started' => false]);
Http::responder(202);
acoes()->sincronizar('order-1');
confere(Http::urls() === ['POST /logistics/v1.0/orders/pedido-real-1/assignDriver'] && linha()->ultima_acao === 'assignDriver', 'só assignDriver');

echo '== "A caminho" sem a chegada pelo GPS: arrivedAtOrigin e dispatch' . PHP_EOL;
pedidoIfood(['status' => 'enroute'], ['ultima_acao' => 'goingToOrigin', 'motoboy_no_ifood' => 'driver-1']);
Http::responder(202);
Http::responder(202);
acoes()->sincronizar('order-1');
confere(Http::urls() === ['POST /logistics/v1.0/orders/pedido-real-1/arrivedAtOrigin', 'POST /logistics/v1.0/orders/pedido-real-1/dispatch'] && linha()->ultima_acao === 'dispatch', 'completa a sequência');

echo '== Chegada pelo GPS (alvo mínimo)' . PHP_EOL;
pedidoIfood([], ['ultima_acao' => 'goingToOrigin', 'motoboy_no_ifood' => 'driver-1']);
Http::responder(202);
acoes()->sincronizar('order-1', 'arrivedAtOrigin');
confere(Http::urls() === ['POST /logistics/v1.0/orders/pedido-real-1/arrivedAtOrigin'] && linha()->ultima_acao === 'arrivedAtOrigin', 'arrivedAtOrigin');

echo '== Nada a fazer' . PHP_EOL;
pedidoIfood(['status' => 'enroute'], ['ultima_acao' => 'dispatch', 'motoboy_no_ifood' => 'driver-1']);
acoes()->sincronizar('order-1');
confere(Http::$chamadas === [], 'já em dia: nenhuma chamada');
pedidoIfood(['driver_assigned_uuid' => null, 'started' => false, 'status' => 'dispatched']);
acoes()->sincronizar('order-1', 'goingToOrigin');
confere(Http::$chamadas === [], 'sem motoboy: nada (nem com alvo mínimo)');
pedidoIfood([], ['cancelado_pelo_ifood_em' => '2026-10-05 14:58:00']);
acoes()->sincronizar('order-1');
confere(Http::$chamadas === [], 'cancelado pelo iFood: nada');

echo '== Concluído pela central: completa até arrivedAtDestination' . PHP_EOL;
pedidoIfood(['status' => 'completed'], ['ultima_acao' => 'dispatch', 'motoboy_no_ifood' => 'driver-1']);
Http::responder(202);
acoes()->sincronizar('order-1');
confere(Http::urls() === ['POST /logistics/v1.0/orders/pedido-real-1/arrivedAtDestination'], 'arrivedAtDestination');

echo '== Concluído pela central num pedido que exigia o código: registrado' . PHP_EOL;
pedidoIfood(['status' => 'completed'], ['ultima_acao' => 'dispatch', 'motoboy_no_ifood' => 'driver-1', 'exige_codigo' => true]);
Http::responder(202);
acoes()->sincronizar('order-1');
confere(linha()->conclusao_sem_codigo === true && logou('pedido concluído sem o código do cliente', 'warning'), 'conclusao_sem_codigo e log');
pedidoIfood(['status' => 'completed'], ['ultima_acao' => 'arrivedAtDestination', 'motoboy_no_ifood' => 'driver-1', 'exige_codigo' => true, 'conclusao_liberada_em' => '2026-10-05 14:59:00']);
acoes()->sincronizar('order-1');
confere(!linha()->conclusao_sem_codigo && !logou('pedido concluído sem o código do cliente'), 'liberado pelo app (código conferido): não marca');

echo '== 409: recusa, sem nova tentativa, com aviso à central' . PHP_EOL;
pedidoIfood();
Http::responder(202);
Http::responder(409, ['errorType' => 'CONFLICT', 'description' => 'Invalid state', 'code' => '409']);
$resultado = acoes()->sincronizar('order-1');
confere(linha()->ultima_acao === 'assignDriver' && linha()->recusa_acao === 'goingToOrigin' && linha()->recusa_status === 409 && linha()->recusa_em === '2026-10-05 15:00:00', 'a aceita fica; a recusa é gravada');
confere($resultado['recusada'] === ['acao' => 'goingToOrigin', 'status' => 409], 'resultado com a recusa');
$recusa = null;
foreach (\Illuminate\Support\Facades\Log::$registros as [$nivel, $mensagem, $contexto]) {
    if ($mensagem === '[entregas] ifood: ação recusada') {
        $recusa = [$nivel, $contexto];
    }
}
confere(($recusa[0] ?? null) === 'warning' && ($recusa[1]['acao'] ?? null) === 'goingToOrigin' && ($recusa[1]['status'] ?? null) === 409 && str_contains($recusa[1]['corpo'] ?? '', 'Invalid state'), 'log com a ação, o status e o corpo');
$aviso = Socket::$transmitidos[0] ?? null;
confere(($aviso['canal'] ?? null) === 'company.empresa-1' && ($aviso['dados']['event'] ?? null) === 'entregas.ifood_acao_recusada', 'aviso no canal da empresa');
confere(($aviso['dados']['data'] ?? null) === ['id' => 'order_1', 'uuid' => 'order-1', 'numero' => '4821', 'acao' => 'goingToOrigin', 'status' => 409], 'aviso com o pedido, o número, a ação e o status');
Http::responder(202);
acoes()->sincronizar('order-1');
confere(linha()->ultima_acao === 'goingToOrigin' && linha()->recusa_acao === null && linha()->recusa_status === null, 'a próxima mudança tenta de novo; aceita, limpa a recusa');

echo '== Socket fora do ar: a recusa fica registrada e o log avisa' . PHP_EOL;
pedidoIfood(['status' => 'dispatched', 'started' => false]);
Socket::$falhar = true;
Http::responder(400, ['errorType' => 'BAD_REQUEST', 'code' => '400']);
acoes()->sincronizar('order-1');
confere(linha()->recusa_acao === 'assignDriver' && logou('aviso de ação recusada não chegou ao socket', 'warning'), 'recusa gravada e log do socket');

echo '== Falha temporária: sobe para quem chamou, guardando o que já foi' . PHP_EOL;
pedidoIfood();
Http::responder(202);
Http::responder(503, 'indisponível');
$erro = excecao(fn () => acoes()->sincronizar('order-1'));
confere($erro instanceof ErroIfood && $erro->status === 503 && $erro->operacao === 'goingToOrigin', '503 sobe como ErroIfood');
confere(linha()->ultima_acao === 'assignDriver' && linha()->recusa_acao === null, 'assignDriver gravado, sem recusa');
pedidoIfood();
Http::falharConexao();
$erro = excecao(fn () => acoes()->sincronizar('order-1'));
confere($erro instanceof ErroIfood && $erro->status === 0 && linha()->ultima_acao === null, 'rede fora: sobe, nada gravado');

echo '== Troca de motoboy pela central' . PHP_EOL;
pedidoIfood(['driver_assigned_uuid' => 'driver-2'], ['ultima_acao' => 'goingToOrigin', 'motoboy_no_ifood' => 'driver-1']);
Http::responder(202);
acoes()->sincronizar('order-1');
confere(Http::urls() === ['POST /logistics/v1.0/orders/pedido-real-1/assignDriver'] && Http::$chamadas[0]['dados']['workerName'] === 'Outro Ficticio', 'assignDriver de novo, com o motoboy novo');
confere(linha()->ultima_acao === 'goingToOrigin' && linha()->motoboy_no_ifood === 'driver-2', 'a etapa não volta; motoboy_no_ifood trocado');
pedidoIfood(['driver_assigned_uuid' => 'driver-2', 'status' => 'enroute'], ['ultima_acao' => 'goingToOrigin', 'motoboy_no_ifood' => 'driver-1']);
Http::responder(409, ['description' => 'Driver already assigned']);
acoes()->sincronizar('order-1');
confere(count(Http::$chamadas) === 1 && linha()->recusa_acao === 'assignDriver' && linha()->motoboy_no_ifood === 'driver-1', 'troca recusada (409): para, sem mandar o dispatch; aviso à central');

echo '== Recusas locais (sem chamar o iFood)' . PHP_EOL;
pedidoIfood();
Driver::$todos[0]->phone = null;
acoes()->sincronizar('order-1');
confere(Http::$chamadas === [] && linha()->recusa_acao === 'assignDriver' && linha()->recusa_status === null, 'motoboy sem telefone: recusa (status 0)');
pedidoIfood();
Banco::$tabelas['entregas_ifood_lojas'][1]['situacao'] = 'vinculo_perdido';
acoes()->sincronizar('order-1');
confere(Http::$chamadas === [] && linha()->recusa_acao === 'assignDriver', 'loja sem vínculo ativo: recusa');

echo '== Trava das ações ocupada' . PHP_EOL;
pedidoIfood();
Trava::$ocupadas['entregas:ifood-acao:order-1'] = true;
$erro = excecao(fn () => acoes()->sincronizar('order-1'));
confere($erro instanceof \Illuminate\Contracts\Cache\LockTimeoutException && Http::$chamadas === [], 'LockTimeoutException, sem chamar o iFood');

echo '== Telefone do motoboy' . PHP_EOL;
confere(AcoesIfood::telefone('+55 (16) 99999-0000') === '16999990000', 'E.164 com máscara: só DDD + número');
confere(AcoesIfood::telefone('1633334444') === '1633334444', 'fixo com DDD');
confere(AcoesIfood::telefone('999990000') === null && AcoesIfood::telefone(null) === null, 'sem DDD ou vazio: null');

resumo();
```

- [ ] **Step 2: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-acoes.php`
Expected: erro fatal `Class "Fleetbase\FleetOps\Models\Driver" not found`.

- [ ] **Step 3: o que o ciclo usa no stub do Laravel**

Em `scripts/teste-php/stubs-ifood.php`, na classe `Carbon`, troque:

```php
        public function addHour(): static { $this->modify('+1 hour'); return $this; }
```

por:

```php
        public function addHour(): static { $this->modify('+1 hour'); return $this; }
        public function subHours($n): static { $this->modify('-' . (int) $n . ' hours'); return $this; }
```

E, na classe `FabricaDeResposta`, troque:

```php
        public function json($dados = [], int $status = 200) { return new RespostaJson($dados, $status); }
```

por:

```php
        public function json($dados = [], int $status = 200) { return new RespostaJson($dados, $status); }
        // o macro apiError do Fleetbase: {"error": "..."} (400 por padrão)
        public function apiError($mensagem, int $status = 400) { return new RespostaJson(['error' => $mensagem], $status); }
```

- [ ] **Step 4: o que o ciclo usa no stub do Fleetbase**

Em `scripts/teste-php/stubs-ifood-fleetbase.php`:

1. Troque o bloco do `Request` (do `namespace Illuminate\Http {` até o fim do método `all()`):

```php
namespace Illuminate\Http {
    class Request
    {
        public function __construct(public array $dados = []) {}

        public function all(): array { return $this->dados; }
```

por:

```php
namespace Illuminate\Routing {
    // a rota que o roteador casou: só a ação (Controller@metodo) e os parâmetros
    class Route
    {
        public function __construct(public string $acao, public array $parametros = []) {}
        public function getActionName(): string { return $this->acao; }
    }
}

namespace Illuminate\Http {
    class Request
    {
        /** Bearer token (MotoboyDaSessao: token de usuário tem "|"). */
        public ?string $token = null;
        /** A rota casada (middlewares que olham a ação), ou null. */
        public ?\Illuminate\Routing\Route $rota = null;

        public function __construct(public array $dados = []) {}

        public function all(): array { return $this->dados; }
        public function bearerToken(): ?string { return $this->token; }
        public function input($chave = null, $padrao = null) { return $chave === null ? $this->dados : ($this->dados[$chave] ?? $padrao); }
        public function array($chave): array { return (array) ($this->dados[$chave] ?? []); }
        public function ip(): string { return '127.0.0.1'; }

        // como o do Laravel: sem parâmetro, a rota; com nome, o parâmetro da rota
        public function route($parametro = null)
        {
            if ($parametro === null) {
                return $this->rota;
            }

            return $this->rota?->parametros[$parametro] ?? null;
        }
```

2. Na `ConsultaDeModelo`, troque:

```php
        public function firstOrFail()
```

por:

```php
        // when($valor, fn): aplica o filtro só com valor (o when do query builder); with(): relações não importam aqui
        public function when($valor, \Closure $filtro)
        {
            if ($valor) {
                $filtro($this, $valor);
            }

            return $this;
        }

        public function with($relacoes) { return $this; }

        public function firstOrFail()
```

3. No `Payload`, troque:

```php
        public function setDropoff($place) { $this->dropoff = $place; return $this; }
```

por:

```php
        public function setDropoff($place) { $this->dropoff = $place; return $this; }
        public function getPickupOrFirstWaypoint() { return $this->pickup; }
        public function getDropoffOrLastWaypoint() { return $this->dropoff; }
```

4. No `Order`, troque:

```php
        public bool $temStatusDespachado = false;
```

por:

```php
        public bool $temStatusDespachado = false;
        /** Campos que mudaram no último save (o wasChanged do Eloquent). */
        public array $alterados = [];
        /** Com true, o cancel() lança (o OrderCanceled falhou). */
        public static bool $falharCancelamento = false;
        /** Em cada cancel(): se a trava do pedido (TravaDoPedido) e a das ações (AcoesIfood) estavam tomadas. */
        public array $travadoNoCancelar = [];
```

e troque:

```php
        public function hasDispatchedStatus(): bool { return $this->temStatusDespachado; }
```

por:

```php
        public function hasDispatchedStatus(): bool { return $this->temStatusDespachado; }

        public function wasChanged($campos = null): bool
        {
            $campos = (array) $campos;

            return $campos === [] ? (bool) $this->alterados : (bool) array_intersect($campos, $this->alterados);
        }

        // o Order::cancel do Fleet-Ops: atividade "canceled", status canceled e OrderCanceled na fila
        public function cancel()
        {
            $this->chamadas[]          = 'cancel';
            $this->travadoNoCancelar[] = [isset(\Teste\Trava::$ocupadas['entregas:pedido:' . $this->uuid]), isset(\Teste\Trava::$ocupadas['entregas:ifood-acao:' . $this->uuid])];
            if (self::$falharCancelamento) {
                throw new \RuntimeException('falha no cancelamento');
            }
            $this->status = 'canceled';

            return true;
        }
```

5. Logo antes do bloco final (a linha `namespace {` seguida do comentário `// o DB::transaction falso desfaz estas listas junto com as tabelas (rollback)`), acrescente:

```php
namespace Fleetbase\FleetOps\Models {
    #[\AllowDynamicProperties]
    class Driver
    {
        use \Teste\ModeloDeTeste;
        public $uuid;
        public $public_id;
        public $company_uuid;
        public $user_uuid;
        public $name;
        public $phone;
        public $location;
        public $online = false;
    }
}

namespace Teste {
    // o container do Laravel (app()): instância registrada pelo teste ou criada com as dependências do construtor
    class Container
    {
        public static array $instancias = [];
    }
}

namespace {
    function app($classe = null)
    {
        if ($classe === null) {
            return null;
        }
        if (isset(\Teste\Container::$instancias[$classe])) {
            return \Teste\Container::$instancias[$classe];
        }
        $reflexao   = new \ReflectionClass($classe);
        $argumentos = [];
        foreach ($reflexao->getConstructor()?->getParameters() ?? [] as $parametro) {
            $tipo = $parametro->getType();
            if ($tipo instanceof \ReflectionNamedType && !$tipo->isBuiltin()) {
                $argumentos[] = app($tipo->getName());
            } else {
                $argumentos[] = $parametro->isDefaultValueAvailable() ? $parametro->getDefaultValue() : null;
            }
        }

        return $reflexao->newInstanceArgs($argumentos);
    }
}

```

6. Em `reiniciarFleetbase()`, troque:

```php
        \Fleetbase\FleetOps\Models\Order::$falharDespacho = false;
```

por:

```php
        \Fleetbase\FleetOps\Models\Order::$falharDespacho = false;
        \Fleetbase\FleetOps\Models\Order::$falharCancelamento = false;
        \Fleetbase\FleetOps\Models\Driver::$todos      = [];
        \Teste\Container::$instancias                  = [];
```

- [ ] **Step 5: `PedidosIfood`**

Crie `api/app/Support/Entregas/Ifood/PedidosIfood.php`:

```php
<?php

namespace App\Support\Entregas\Ifood;

use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: leitura e gravação da entregas_ifood_pedidos pelo uuid do Order, num lugar só (ações de
 * logística, cancelamento, travas, rotas do motoboy e painel do console). Pedido do iFood = Order com linha aqui.
 */
final class PedidosIfood
{
    public const TABELA = 'entregas_ifood_pedidos';

    /** A linha do pedido do iFood deste Order, ou null (não é do iFood). */
    public static function doPedido(?string $orderUuid): ?object
    {
        if ($orderUuid === null || $orderUuid === '') {
            return null;
        }

        return DB::table(static::TABELA)->where('order_uuid', $orderUuid)->first();
    }

    public static function ehDoIfood(?string $orderUuid): bool
    {
        return $orderUuid !== null && $orderUuid !== '' && DB::table(static::TABELA)->where('order_uuid', $orderUuid)->exists();
    }

    /** Grava os valores na linha (com updated_at) e copia para o objeto, para quem chamou seguir com a linha atualizada. */
    public static function atualizar(object $linha, array $valores): void
    {
        $valores['updated_at'] = now()->toDateTimeString();
        DB::table(static::TABELA)->where('id', $linha->id)->update($valores);
        foreach ($valores as $coluna => $valor) {
            $linha->$coluna = $valor;
        }
    }
}
```

- [ ] **Step 6: o evento do aviso à central**

Crie `api/app/Events/Entregas/IfoodAcaoRecusada.php`:

```php
<?php

namespace App\Events\Entregas;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Entregas RestaurantePro: o iFood recusou uma ação de logística de um pedido (409 ou outro 4xx), o vínculo da loja
 * caiu, o motoboy não tem telefone, ou as tentativas acabaram com o iFood fora do ar (AcoesIfood). Vai no canal da
 * empresa para o console avisar a central (serviço ifood-acao-recusada do Fleet-Ops), que confere o pedido no Gestor
 * de Pedidos do iFood. Enviado por TransmissaoNoSocket, que confere o retorno do socket; o ShouldBroadcastNow só marca o
 * evento como imediato, sem fila. Sem dados do cliente.
 */
class IfoodAcaoRecusada implements ShouldBroadcastNow
{
    public const NOME = 'entregas.ifood_acao_recusada';

    public function __construct(
        public string $empresaUuid,
        public string $pedidoUuid,
        public string $pedidoPublicId,
        public ?string $numero,
        public string $acao,
        public int $status,
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
                'id'     => $this->pedidoPublicId,
                'uuid'   => $this->pedidoUuid,
                // número do iFood (internal_id) ou, sem ele, o public_id
                'numero' => $this->numero ?: $this->pedidoPublicId,
                'acao'   => $this->acao,
                // 0 = não chegou a ser uma resposta do iFood (vínculo perdido, motoboy sem telefone, iFood fora do ar)
                'status' => $this->status,
            ],
        ];
    }
}
```

- [ ] **Step 7: `AcoesIfood`**

Crie `api/app/Support/Entregas/Ifood/AcoesIfood.php`:

```php
<?php

namespace App\Support\Entregas\Ifood;

use App\Events\Entregas\IfoodAcaoRecusada;
use App\Support\Entregas\TransmissaoNoSocket;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: leva um pedido iFood, no iFood, até a etapa em que ele está no Fleetbase (spec, seção 3).
 *
 * sincronizar() relê o pedido e a linha da entregas_ifood_pedidos com a trava das ações do pedido (Cache::lock
 * `entregas:ifood-acao:<order uuid>`: dois envios do mesmo pedido nunca correm juntos), calcula a ação alvo
 * (SequenciaIfood::alvoPeloPedido, ou $alvoMinimo quando mais adiante: a chegada pelo GPS e a conclusão do app) e envia,
 * em ordem, as que faltam depois da `ultima_acao` (SequenciaIfood::faltando). Cada ação aceita grava a `ultima_acao` na
 * hora: uma falha no meio guarda o que já foi.
 *
 * - Troca de motoboy: com o iFood já tendo recebido outro motoboy (`motoboy_no_ifood`) e o pedido com um novo,
 *   assignDriver de novo antes de seguir. A documentação não diz se o iFood aceita depois do goingToOrigin; um 409 vira
 *   recusa (abaixo).
 * - Recusa (409 ou outro 4xx do iFood, vínculo perdido ou motoboy sem nome/telefone): não tenta de novo; grava
 *   recusa_acao/recusa_status/recusa_em, registra `[entregas] ifood: ação recusada` (ação, status e o corpo da resposta,
 *   que não traz dados do cliente) e avisa a central no console (IfoodAcaoRecusada). Para nesta ação: as seguintes
 *   ficam para a próxima mudança do pedido. Uma ação aceita depois limpa a recusa.
 * - Falha temporária (429, 408, 5xx, rede): o ErroIfood sobe para quem chamou (o job EnviarAcaoIfood tenta de novo; a
 *   rota do app responde "tente de novo").
 * - Pedido cancelado pelo iFood, sem linha, sem Order ou sem motoboy: nada.
 *
 * Os logs levam só ids, a ação, o status e o número do pedido; o nome e o telefone do motoboy vão só no corpo do
 * assignDriver.
 */
class AcoesIfood
{
    public const TRAVA = 'entregas:ifood-acao:';

    /** Validade da trava, em segundos: maior que o $timeout do job (80 s), para não vencer com o envio no meio. */
    public const VALIDADE_DA_TRAVA = 90;

    /** Espera pela trava, em segundos, antes de desistir com LockTimeoutException. */
    public const ESPERA_DA_TRAVA = 10;

    public const VEICULO = 'MOTORCYCLE';

    public function __construct(protected VinculosIfood $vinculos, protected ClienteIfood $cliente) {}

    /**
     * @return array{enviadas: string[], recusada: ?array{acao: string, status: int}}
     *
     * @throws LockTimeoutException trava das ações do pedido ocupada por mais de ESPERA_DA_TRAVA s
     * @throws ErroIfood            falha temporária do iFood (quem chama tenta de novo)
     */
    public function sincronizar(string $orderUuid, ?string $alvoMinimo = null): array
    {
        return $this->comATrava($orderUuid, fn () => $this->sincronizarComATrava($orderUuid, $alvoMinimo));
    }

    /** Roda $fazer com a trava das ações do pedido (a mesma do sincronizar; a ConclusaoIfood confere o código com ela). */
    public function comATrava(string $orderUuid, \Closure $fazer): mixed
    {
        return Cache::lock(static::TRAVA . $orderUuid, static::VALIDADE_DA_TRAVA)->block(static::ESPERA_DA_TRAVA, $fazer);
    }

    /** O job desistiu (tentativas esgotadas com o iFood fora do ar): a próxima ação que faltava vira recusa, com aviso. */
    public function desistir(string $orderUuid, int $status): void
    {
        $linha  = PedidosIfood::doPedido($orderUuid);
        $pedido = Order::where('uuid', $orderUuid)->first();
        if (!$linha || !$pedido || $linha->cancelado_pelo_ifood_em) {
            return;
        }

        $motoboy = $pedido->driver_assigned_uuid ? (string) $pedido->driver_assigned_uuid : null;
        $alvo    = SequenciaIfood::alvoPeloPedido($pedido->status, (bool) $pedido->started, $motoboy);
        $proxima = SequenciaIfood::faltando($linha->ultima_acao, $alvo)[0] ?? SequenciaIfood::ATRIBUIR;
        $this->recusar($linha, $pedido, $proxima, $status, 'iFood fora do ar: tentativas esgotadas');
    }

    protected function sincronizarComATrava(string $orderUuid, ?string $alvoMinimo): array
    {
        $resultado = ['enviadas' => [], 'recusada' => null];

        $linha  = PedidosIfood::doPedido($orderUuid);
        $pedido = $linha ? Order::where('uuid', $orderUuid)->first() : null;
        if (!$linha || !$pedido || $linha->cancelado_pelo_ifood_em) {
            return $resultado;
        }

        $motoboy = $pedido->driver_assigned_uuid ? (string) $pedido->driver_assigned_uuid : null;
        if ($motoboy === null) {
            // sem motoboy não há o que informar (assignDriver é a primeira); motoboy tirado do pedido não tem ação no iFood
            return $resultado;
        }

        // concluído sem passar pela conclusão do app (pela central no console, ou por APK antigo com a trava desligada) num
        // pedido que exigia o código: o iFood fica com a confirmação pendente e conclui sozinho 4 h depois
        if ($pedido->status === 'completed' && $linha->exige_codigo && !$linha->conclusao_liberada_em && !$linha->conclusao_sem_codigo) {
            PedidosIfood::atualizar($linha, ['conclusao_sem_codigo' => true]);
            Log::warning('[entregas] ifood: pedido concluído sem o código do cliente', ['pedido' => $pedido->public_id, 'numero' => $linha->numero]);
        }

        $alvo = SequenciaIfood::maisAdiante(SequenciaIfood::alvoPeloPedido($pedido->status, (bool) $pedido->started, $motoboy), $alvoMinimo);

        // troca de motoboy: o iFood tem outro (o pedido já passou do assignDriver)
        if ($linha->ultima_acao !== null && $linha->motoboy_no_ifood && $linha->motoboy_no_ifood !== $motoboy) {
            if (!$this->enviar($linha, $pedido, SequenciaIfood::ATRIBUIR, $motoboy, $resultado)) {
                return $resultado;
            }
        }

        foreach (SequenciaIfood::faltando($linha->ultima_acao, $alvo) as $acao) {
            if (!$this->enviar($linha, $pedido, $acao, $motoboy, $resultado)) {
                break;
            }
        }

        return $resultado;
    }

    /** Envia uma ação; true se o iFood aceitou. Recusa grava, registra e avisa (false); falha temporária sobe. */
    protected function enviar(object $linha, Order $pedido, string $acao, string $motoboyUuid, array &$resultado): bool
    {
        $vinculo = $this->vinculos->porMerchant((string) $linha->merchant_id);
        if (!$vinculo) {
            return $this->recusa($linha, $pedido, $acao, 0, 'loja sem vínculo ativo com o iFood', $resultado);
        }

        $corpo = null;
        if ($acao === SequenciaIfood::ATRIBUIR) {
            $corpo = static::corpoDoMotoboy(Driver::where('uuid', $motoboyUuid)->first());
            if ($corpo === null) {
                return $this->recusa($linha, $pedido, $acao, 0, 'motoboy sem nome ou telefone no cadastro', $resultado);
            }
        }

        try {
            $this->vinculos->comToken($vinculo, fn (string $token) => $this->cliente->acaoLogistica($token, (string) $linha->pedido_ifood_id, $acao, $corpo));
        } catch (VinculoPerdido) {
            return $this->recusa($linha, $pedido, $acao, 0, 'vínculo da loja perdido', $resultado);
        } catch (ErroIfood $e) {
            if ($e->temporario()) {
                throw $e;
            }

            return $this->recusa($linha, $pedido, $acao, $e->status, $e->operacao . ': ' . $e->corpo, $resultado);
        }

        $valores = ['recusa_acao' => null, 'recusa_status' => null, 'recusa_em' => null];
        if (SequenciaIfood::posicao($acao) > SequenciaIfood::posicao($linha->ultima_acao)) {
            $valores['ultima_acao'] = $acao;
        }
        if ($acao === SequenciaIfood::ATRIBUIR) {
            $valores['motoboy_no_ifood'] = $motoboyUuid;
        }
        PedidosIfood::atualizar($linha, $valores);

        Log::info('[entregas] ifood: ação enviada', ['acao' => $acao, 'pedido' => $pedido->public_id, 'numero' => $linha->numero]);
        $resultado['enviadas'][] = $acao;

        return true;
    }

    protected function recusa(object $linha, Order $pedido, string $acao, int $status, string $detalhe, array &$resultado): bool
    {
        $this->recusar($linha, $pedido, $acao, $status, $detalhe);
        $resultado['recusada'] = ['acao' => $acao, 'status' => $status];

        return false;
    }

    protected function recusar(object $linha, Order $pedido, string $acao, int $status, string $detalhe): void
    {
        PedidosIfood::atualizar($linha, [
            'recusa_acao'   => $acao,
            'recusa_status' => $status > 0 ? $status : null,
            'recusa_em'     => now()->toDateTimeString(),
        ]);

        Log::warning('[entregas] ifood: ação recusada', [
            'acao'   => $acao,
            'status' => $status,
            'corpo'  => mb_substr($detalhe, 0, 500),
            'pedido' => $pedido->public_id,
            'numero' => $linha->numero,
        ]);

        $erro = TransmissaoNoSocket::enviar(new IfoodAcaoRecusada(
            (string) $pedido->company_uuid,
            (string) $pedido->uuid,
            (string) $pedido->public_id,
            $linha->numero ? (string) $linha->numero : null,
            $acao,
            $status
        ));
        if ($erro !== null) {
            Log::warning('[entregas] ifood: aviso de ação recusada não chegou ao socket', ['pedido' => $pedido->public_id, 'erro' => $erro]);
        }
    }

    /** Corpo do assignDriver: nome e telefone do motoboy (o cadastro dele no Fleetbase) e o veículo; null se faltar um dos dois. */
    public static function corpoDoMotoboy(?Driver $motoboy): ?array
    {
        $nome     = trim((string) ($motoboy?->name ?? ''));
        $telefone = static::telefone($motoboy?->phone);
        if ($nome === '' || $telefone === null) {
            return null;
        }

        return ['workerName' => mb_substr($nome, 0, 100), 'workerPhone' => $telefone, 'workerVehicleType' => static::VEICULO];
    }

    /**
     * Telefone no formato que a sonda viu o iFood aceitar: DDD + número, só dígitos ("16999990000"). O cadastro do
     * motoboy guarda E.164 ("+5516999990000"): o 55 do Brasil sai. Menos de 10 dígitos (sem DDD) ou mais de 11 = null.
     */
    public static function telefone(?string $telefone): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $telefone);
        if (strlen($digitos) >= 12 && str_starts_with($digitos, '55')) {
            $digitos = substr($digitos, 2);
        }

        return strlen($digitos) >= 10 && strlen($digitos) <= 11 ? $digitos : null;
    }
}
```

- [ ] **Step 8: rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-acoes.php`
Expected: `FALHAS: 0`. Rode também todos os `ifood-*.php` (Step 5 da Task 1): nenhum quebra com os stubs novos.

- [ ] **Step 9: commit**

```bash
git rev-parse --show-toplevel
git add api/app/Support/Entregas/Ifood/PedidosIfood.php api/app/Events/Entregas/IfoodAcaoRecusada.php api/app/Support/Entregas/Ifood/AcoesIfood.php scripts/teste-php/stubs-ifood.php scripts/teste-php/stubs-ifood-fleetbase.php scripts/teste-php/ifood-acoes.php
git commit -m "iFood etapa 3: ações de logística em ordem, recusa com aviso à central e troca de motoboy

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: o job `EnviarAcaoIfood` e o gancho nos pedidos

**Files:**
- Create: `api/app/Jobs/Entregas/EnviarAcaoIfood.php`
- Create: `api/app/Listeners/Entregas/ObservadorDosPedidosIfood.php`
- Modify: `api/app/Providers/AppServiceProvider.php`
- Test: `scripts/teste-php/ifood-acoes.php`

- [ ] **Step 1: os testes do job e do observador**

Em `scripts/teste-php/ifood-acoes.php` (as importações do job e do observador já estão no topo desde a Task 4), acrescente logo antes da última linha (`resumo();`):

```php
echo '== Job EnviarAcaoIfood' . PHP_EOL;
pedidoIfood();
confere(EnviarAcaoIfood::enfileirar('order-1') === true && count(Fila::$jobs) === 1, 'enfileira o primeiro');
confere(EnviarAcaoIfood::enfileirar('order-1') === false && count(Fila::$jobs) === 1, 'não enfileira outro enquanto o primeiro espera');
Http::responder(202);
Http::responder(202);
$job = Fila::$jobs[0];
$job->handle(acoes());
confere(linha()->ultima_acao === 'goingToOrigin' && !isset(Cache::$dados['entregas:ifood-acao-pendente:order-1']), 'o job envia e apaga a marca de pendente');
confere(EnviarAcaoIfood::enfileirar('order-1') === true, 'depois dele, uma mudança nova enfileira de novo');
confere($job->afterCommit === true && $job->timeout < 90 && $job->maxExceptions === 5, 'afterCommit, timeout abaixo do retry_after (90 s) e 5 exceções');

pedidoIfood();
Http::responder(202);
Http::responder(429, null, ['Retry-After' => '42']);
$job = new EnviarAcaoIfood('order-1');
$job->handle(acoes());
confere($job->liberadoPor === 42 && linha()->ultima_acao === 'assignDriver', '429: volta para a fila pelo Retry-After');

pedidoIfood();
Trava::$ocupadas['entregas:ifood-acao:order-1'] = true;
$job = new EnviarAcaoIfood('order-1');
$job->handle(acoes());
confere($job->liberadoPor === EnviarAcaoIfood::ESPERA_DA_TRAVA, 'trava ocupada: volta para a fila');

pedidoIfood();
Http::responder(500, 'erro com detalhes');
$job  = new EnviarAcaoIfood('order-1');
$erro = excecao(fn () => $job->handle(acoes()));
confere($erro instanceof ErroIfood && $erro->status === 500 && $erro->corpo === '', '5xx: sobe sem o corpo (nova tentativa pelo $backoff)');

pedidoIfood();
Config::$valores['services.ifood.ativo'] = '';
(new EnviarAcaoIfood('order-1'))->handle(acoes());
confere(Http::$chamadas === [], 'integração desligada: nada');

pedidoIfood();
(new EnviarAcaoIfood('order-1'))->failed(new ErroIfood('goingToOrigin', 503));
confere(logou('ação não enviada; tentativas esgotadas', 'error') && linha()->recusa_acao === 'assignDriver' && linha()->recusa_status === 503, 'tentativas esgotadas: log e a próxima ação vira recusa (aviso)');

echo '== ObservadorDosPedidosIfood' . PHP_EOL;
$pedido            = pedidoIfood();
$pedido->alterados = ['status'];
ObservadorDosPedidosIfood::aoAtualizar($pedido);
confere(count(Fila::$jobs) === 1 && Fila::$jobs[0]->orderUuid === 'order-1', 'status mudou: enfileira');
$pedido            = pedidoIfood();
$pedido->alterados = ['notes', 'updated_at'];
ObservadorDosPedidosIfood::aoAtualizar($pedido);
confere(Fila::$jobs === [], 'outro campo: nada');
$pedido            = pedidoIfood();
$pedido->alterados = ['driver_assigned_uuid'];
Banco::$tabelas['entregas_ifood_pedidos'] = [];
ObservadorDosPedidosIfood::aoAtualizar($pedido);
confere(Fila::$jobs === [], 'pedido que não é do iFood: nada');
$pedido            = pedidoIfood();
$pedido->alterados = ['started'];
Config::$valores['services.ifood.ativo'] = '';
ObservadorDosPedidosIfood::aoAtualizar($pedido);
confere(Fila::$jobs === [], 'integração desligada: nada');
$pedido            = pedidoIfood();
$pedido->alterados = ['started'];
Fila::$falhar      = new RuntimeException('redis fora');
$erro              = excecao(fn () => ObservadorDosPedidosIfood::aoAtualizar($pedido));
confere($erro === null && logou('falha ao enfileirar a ação do pedido', 'warning') && !isset(Cache::$dados['entregas:ifood-acao-pendente:order-1']), 'fila fora: não lança, registra e não deixa a marca de pendente');

$pedido = pedidoIfood();
$evento = new class ($pedido) {
    public function __construct(private $pedido) {}
    public function getModelRecord() { return $this->pedido; }
};
ObservadorDosPedidosIfood::aoAtribuirMotoboy($evento);
confere(count(Fila::$jobs) === 1, 'OrderDriverAssigned (bulk-assign-driver): enfileira');
```

- [ ] **Step 2: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-acoes.php`
Expected: erro fatal `Class "App\Jobs\Entregas\EnviarAcaoIfood" not found`.

- [ ] **Step 3: o job**

Crie `api/app/Jobs/Entregas/EnviarAcaoIfood.php`:

```php
<?php

namespace App\Jobs\Entregas;

use App\Support\Entregas\Ifood\AcoesIfood;
use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\ErroIfood;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: leva um pedido iFood, no iFood, até a etapa em que ele está (AcoesIfood::sincronizar), na
 * fila: nada trava o app nem o console. Enfileirado pelo ObservadorDosPedidosIfood (motoboy definido, iniciado, a
 * caminho, concluído) e pelo entregas:ifood-acompanhar (chegada pelo GPS e reconciliação a cada 30 s).
 *
 * Um job por pedido de cada vez: enfileirar() marca o pedido como "pendente" no cache (PENDENTE) e não enfileira outro
 * enquanto a marca existir. O job apaga a marca antes de ler o pedido, então uma mudança que chega depois da leitura
 * enfileira um job novo, e as que chegam antes são lidas por este (ele lê o estado na hora em que roda). afterCommit:
 * uma mudança gravada dentro de uma transação só é lida depois do commit.
 *
 * Falhas: 429 volta para a fila pelo Retry-After (release, não conta como exceção); trava das ações ocupada volta em
 * ESPERA_DA_TRAVA s; 5xx, 408 e rede sobem e a fila tenta de novo pelo $backoff, até $maxExceptions (5) vezes, dentro de
 * PRAZO_MINUTOS. Esgotadas, failed() registra `[entregas] ifood: ação não enviada` e transforma a próxima ação numa
 * recusa (AcoesIfood::desistir: aviso à central). 409 e outros 4xx são recusas tratadas no AcoesIfood, sem nova
 * tentativa.
 */
class EnviarAcaoIfood implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const PENDENTE = 'entregas:ifood-acao-pendente:';

    /** Validade da marca de pendente, em segundos (se o job se perder, a marca some sozinha). */
    public const VALIDADE_DO_PENDENTE = 600;

    public const ESPERA_DA_TRAVA = 5;

    public const PRAZO_MINUTOS = 30;

    public $afterCommit = true;

    /** Abaixo do retry_after da conexão redis (90 s). */
    public int $timeout = 80;

    public int $maxExceptions = 5;

    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(public string $orderUuid, public ?string $alvoMinimo = null) {}

    /**
     * Enfileira, a não ser que este pedido já tenha um job esperando (devolve false). $alvoMinimo: a chegada pelo GPS
     * (o estado do Fleetbase não a conhece).
     */
    public static function enfileirar(string $orderUuid, ?string $alvoMinimo = null): bool
    {
        if (!Cache::add(static::PENDENTE . $orderUuid, true, static::VALIDADE_DO_PENDENTE)) {
            return false;
        }

        try {
            static::dispatch($orderUuid, $alvoMinimo);
        } catch (\Throwable $e) {
            // fila fora do ar: sem a marca, a próxima mudança (ou o entregas:ifood-acompanhar) tenta de novo
            Cache::forget(static::PENDENTE . $orderUuid);
            throw $e;
        }

        return true;
    }

    /** Tentativas pelo prazo: os release() (429, trava ocupada) contariam como tentativa no Laravel. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(static::PRAZO_MINUTOS);
    }

    public function handle(AcoesIfood $acoes): void
    {
        Cache::forget(static::PENDENTE . $this->orderUuid);
        if (!ClienteIfood::ligada()) {
            return;
        }

        try {
            $acoes->sincronizar($this->orderUuid, $this->alvoMinimo);
        } catch (LockTimeoutException $e) {
            $this->release(static::ESPERA_DA_TRAVA);
        } catch (ErroIfood $e) {
            if ($e->limiteExcedido()) {
                $this->release($e->retryAfter ?? ClienteIfood::ESPERA_PADRAO_429);

                return;
            }
            // temporário (5xx, 408, rede): nova tentativa pelo $backoff, sem o corpo da resposta no failed_jobs
            throw new ErroIfood($e->operacao, $e->status);
        }
    }

    public function failed(\Throwable $erro): void
    {
        $status = $erro instanceof ErroIfood ? $erro->status : 0;
        Log::error('[entregas] ifood: ação não enviada; tentativas esgotadas', [
            'order_uuid' => $this->orderUuid,
            'erro'       => get_class($erro),
            'status'     => $status,
        ]);

        try {
            app(AcoesIfood::class)->desistir($this->orderUuid, $status);
        } catch (\Throwable $e) {
            Log::warning('[entregas] ifood: falha ao registrar a desistência da ação', ['order_uuid' => $this->orderUuid, 'erro' => get_class($e)]);
        }
    }
}
```

- [ ] **Step 4: o observador**

Crie `api/app/Listeners/Entregas/ObservadorDosPedidosIfood.php`:

```php
<?php

namespace App\Listeners\Entregas;

use App\Jobs\Entregas\EnviarAcaoIfood;
use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\PedidosIfood;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: converte mudança de um pedido iFood no Fleetbase em envio de ação ao iFood (EnviarAcaoIfood).
 * Registrado no AppServiceProvider::boot, sobre o código do Composer (nada em packages/fleetops/server chega à produção):
 *
 * - `Order::updated` (evento do Eloquent) com mudança em driver_assigned_uuid, started ou status. Pega o aceite do
 *   pedido aberto (startOrder: assignDriver + started + atividade, todos com save()), a atribuição pela central
 *   (PUT int/v1/orders/{id}, assign-order do motorista), o "A caminho" e a conclusão (updateActivity → setStatus →
 *   save()) e a API v1 (PUT v1/orders/{id}). No Fleet-Ops desta versão, todo status passa por setStatus(…, true) =
 *   save(). Escapam: o bulk-assign-driver (query builder, sem eventos do Eloquent) e o scheduleOrder (saveQuietly);
 * - `OrderDriverAssigned` (evento do Fleet-Ops): cobre o bulk-assign-driver, que dispara o evento pelo job
 *   NotifyBulkAssignedDriver (quando não é silencioso);
 * - o que ainda escapar (scheduleOrder, um job perdido no Redis sem persistência) o entregas:ifood-acompanhar acha em
 *   até 30 s, pela mesma regra (reconciliação).
 *
 * Nunca lança: roda dentro da requisição do app ou do console, e uma falha aqui não pode impedir a gravação do pedido
 * (vai para o log). Só olha a tabela do iFood quando um dos três campos mudou e a integração está ligada.
 */
class ObservadorDosPedidosIfood
{
    /** Campos do Order cuja mudança pode pedir uma ação no iFood. */
    public const CAMPOS = ['driver_assigned_uuid', 'started', 'status'];

    /** Order::updated (Eloquent). */
    public static function aoAtualizar(object $pedido): void
    {
        try {
            if (!ClienteIfood::ligada() || !$pedido->wasChanged(static::CAMPOS)) {
                return;
            }
            static::avisar((string) $pedido->uuid);
        } catch (\Throwable $e) {
            Log::warning('[entregas] ifood: falha ao enfileirar a ação do pedido', ['pedido' => $pedido->public_id ?? null, 'erro' => get_class($e)]);
        }
    }

    /** OrderDriverAssigned (Fleet-Ops): o evento guarda só o uuid; getModelRecord() relê o pedido. */
    public static function aoAtribuirMotoboy(object $evento): void
    {
        try {
            if (!ClienteIfood::ligada()) {
                return;
            }
            $pedido = $evento->getModelRecord();
            if ($pedido) {
                static::avisar((string) $pedido->uuid);
            }
        } catch (\Throwable $e) {
            Log::warning('[entregas] ifood: falha ao enfileirar a ação do pedido', ['erro' => get_class($e)]);
        }
    }

    protected static function avisar(string $orderUuid): void
    {
        if (PedidosIfood::ehDoIfood($orderUuid)) {
            EnviarAcaoIfood::enfileirar($orderUuid);
        }
    }
}
```

- [ ] **Step 5: registrar no `AppServiceProvider`**

Em `api/app/Providers/AppServiceProvider.php`:

1. Troque `use App\Console\Commands\Entregas\ReenviarPedidosAbertos;` por:

```php
use App\Console\Commands\Entregas\ReenviarPedidosAbertos;
use App\Listeners\Entregas\ObservadorDosPedidosIfood;
```

2. Troque `use Fleetbase\FleetOps\Console\Commands\TrackOrderDistanceAndTime;` por:

```php
use Fleetbase\FleetOps\Console\Commands\TrackOrderDistanceAndTime;
use Fleetbase\FleetOps\Events\OrderDriverAssigned;
use Fleetbase\FleetOps\Models\Order;
```

3. Troque o `boot()`:

```php
    public function boot()
    {
        $this->configureOutboundHttpLogging();
        $this->configureTransactionTripwire();
    }
```

por:

```php
    public function boot()
    {
        $this->configureOutboundHttpLogging();
        $this->configureTransactionTripwire();
        $this->acompanharPedidosIfood();
    }

    /**
     * Entregas: mudança de pedido iFood (motoboy definido, iniciado, a caminho, concluído) → ação de logística no iFood,
     * na fila (ver ObservadorDosPedidosIfood). O updated do Eloquent pega o que passa por save() (aceite, atribuição pela
     * central, atividades); o OrderDriverAssigned do Fleet-Ops, a atribuição em lote. O resto, o
     * entregas:ifood-acompanhar reconcilia em até 30 s.
     */
    protected function acompanharPedidosIfood(): void
    {
        Order::updated(fn ($pedido) => ObservadorDosPedidosIfood::aoAtualizar($pedido));
        Event::listen(OrderDriverAssigned::class, fn ($evento) => ObservadorDosPedidosIfood::aoAtribuirMotoboy($evento));
    }
```

(`Event` já é importado no arquivo.)

- [ ] **Step 6: rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-acoes.php && PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/fuso.php && PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Providers/AppServiceProvider.php api/app/Jobs/Entregas/EnviarAcaoIfood.php api/app/Listeners/Entregas/ObservadorDosPedidosIfood.php`
Expected: `FALHAS: 0` nos dois testes e `OK` na sintaxe.

- [ ] **Step 7: commit**

```bash
git rev-parse --show-toplevel
git add api/app/Jobs/Entregas/EnviarAcaoIfood.php api/app/Listeners/Entregas/ObservadorDosPedidosIfood.php api/app/Providers/AppServiceProvider.php scripts/teste-php/ifood-acoes.php
git commit -m "iFood etapa 3: job das ações e gancho no Order::updated e no OrderDriverAssigned

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: `entregas:ifood-acompanhar` (chegada pelo GPS e reconciliação)

**Files:**
- Create: `api/app/Console/Commands/Entregas/AcompanharIfood.php`
- Modify: `api/app/Console/Kernel.php`
- Test: `scripts/teste-php/ifood-acompanhar.php`, `scripts/teste-php/ifood-agendador.php`

- [ ] **Step 1: os testes**

Crie `scripts/teste-php/ifood-acompanhar.php`:

```php
<?php

// Integração iFood (etapa 3): o comando entregas:ifood-acompanhar (chegada pelo GPS e reconciliação).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-acompanhar.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Console\Commands\Entregas\AcompanharIfood;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Teste\Banco;
use Teste\Config;
use Teste\Fila;

/**
 * Pedido iFood order-1 com a loja em (-21.1775, -47.8103) e o cliente ~1 km ao norte; o motoboy driver-1 na posição
 * dada. Devolve o Order.
 */
function pedidoIfood(array $order = [], array $linha = [], ?array $posicao = null): Order
{
    reiniciarFleetbase();
    reiniciarIfood();
    Driver::$todos[]  = new Driver(['uuid' => 'driver-1', 'company_uuid' => 'empresa-1', 'location' => $posicao ? new Point($posicao[0], $posicao[1]) : null]);
    $payload          = new Payload();
    $payload->pickup  = new Place(['location' => new Point(-21.1775, -47.8103)]);
    $payload->dropoff = new Place(['location' => new Point(-21.1685, -47.8103)]);
    $pedido           = new Order($order + ['uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1', 'status' => 'started', 'started' => true, 'driver_assigned_uuid' => 'driver-1', 'payload' => $payload]);
    Order::$todos[]   = $pedido;
    Banco::inserir('entregas_ifood_pedidos', $linha + [
        'company_uuid' => 'empresa-1', 'order_uuid' => 'order-1', 'pedido_ifood_id' => 'pedido-real-1', 'numero' => '4821', 'merchant_id' => 'merchant-1',
        'ultima_acao' => 'goingToOrigin', 'motoboy_no_ifood' => 'driver-1', 'created_at' => '2026-10-05 14:30:00', 'updated_at' => '2026-10-05 14:30:00',
    ], false);

    return $pedido;
}

function rodar(): void
{
    (new AcompanharIfood())->handle();
}

function enfileirados(): array
{
    return array_map(fn ($job) => [$job->orderUuid, $job->alvoMinimo], Fila::$jobs);
}

echo '== Chegada pelo GPS' . PHP_EOL;
pedidoIfood([], [], [-21.1780, -47.8103]);
rodar();
confere(enfileirados() === [['order-1', 'arrivedAtOrigin']] && logou('chegada pelo GPS', 'info'), 'a ~56 m da loja indo para lá: arrivedAtOrigin');
pedidoIfood([], [], [-21.1800, -47.8103]);
rodar();
confere(enfileirados() === [], 'a ~280 m da loja: nada');
pedidoIfood(['status' => 'enroute'], ['ultima_acao' => 'dispatch'], [-21.1690, -47.8103]);
rodar();
confere(enfileirados() === [['order-1', 'arrivedAtDestination']], 'a caminho e a ~56 m do cliente: arrivedAtDestination');
pedidoIfood(['status' => 'enroute'], ['ultima_acao' => 'dispatch'], [-21.1775, -47.8103]);
rodar();
confere(enfileirados() === [], 'a caminho, ainda na loja: nada');
pedidoIfood(['status' => 'started'], ['ultima_acao' => 'assignDriver'], [-21.1775, -47.8103]);
rodar();
confere(enfileirados() === [['order-1', 'arrivedAtOrigin']], 'aceitou já na loja (goingToOrigin ainda não saiu): arrivedAtOrigin (o job manda goingToOrigin antes)');

echo '== Reconciliação (o que o observador não pegou)' . PHP_EOL;
pedidoIfood(['status' => 'enroute'], [], [-21.1730, -47.8103]);
rodar();
confere(enfileirados() === [['order-1', null]], 'estado a caminho, ultima_acao goingToOrigin: enfileira (sem alvo do GPS)');
pedidoIfood(['driver_assigned_uuid' => 'driver-2', 'status' => 'started'], [], null);
rodar();
confere(enfileirados() === [['order-1', null]], 'motoboy trocado: enfileira');
pedidoIfood(['status' => 'completed'], ['ultima_acao' => 'dispatch'], [-21.1690, -47.8103]);
rodar();
confere(enfileirados() === [['order-1', null]], 'concluído sem arrivedAtDestination: enfileira (sem GPS)');

echo '== Fica de fora' . PHP_EOL;
pedidoIfood([], ['recusa_acao' => 'arrivedAtOrigin', 'recusa_status' => 409], [-21.1780, -47.8103]);
rodar();
confere(enfileirados() === [], 'com recusa registrada: não repete sozinho');
pedidoIfood([], ['cancelado_pelo_ifood_em' => '2026-10-05 14:59:00'], [-21.1780, -47.8103]);
rodar();
confere(enfileirados() === [], 'cancelado pelo iFood');
pedidoIfood(['status' => 'canceled'], [], [-21.1780, -47.8103]);
rodar();
confere(enfileirados() === [], 'Order cancelado');
pedidoIfood(['status' => 'completed'], ['ultima_acao' => 'arrivedAtDestination'], null);
rodar();
confere(enfileirados() === [], 'já com arrivedAtDestination');
pedidoIfood([], ['created_at' => '2026-10-04 14:59:59'], [-21.1780, -47.8103]);
rodar();
confere(enfileirados() === [], 'criado há mais de 24 h');
pedidoIfood(['driver_assigned_uuid' => null, 'started' => false, 'status' => 'dispatched'], ['ultima_acao' => null, 'motoboy_no_ifood' => null], null);
rodar();
confere(enfileirados() === [], 'aberto, sem motoboy');
pedidoIfood([], [], [-21.1780, -47.8103]);
\Illuminate\Support\Facades\Cache::$dados['entregas:ifood-acao-pendente:order-1'] = true;
rodar();
confere(enfileirados() === [], 'já há um job do pedido esperando: não enfileira outro');
pedidoIfood([], [], [-21.1780, -47.8103]);
Config::$valores['services.ifood.ativo'] = '';
rodar();
confere(enfileirados() === [], 'integração desligada: nada');

echo '== Um pedido com erro não para a rodada' . PHP_EOL;
pedidoIfood([], [], [-21.1780, -47.8103]);
Order::$todos[0]->payload = new class {
    public function getPickupOrFirstWaypoint() { throw new RuntimeException('falhou'); }
};
rodar();
confere(logou('falha ao acompanhar o pedido', 'warning'), 'registra e segue');

resumo();
```

Em `scripts/teste-php/ifood-agendador.php`, troque:

```php
confere(array_keys($porComando) === ['entregas:ifood-polling', 'entregas:ifood-agendados', 'entregas:ifood-tokens'], 'os três comandos agendados');
confere(isset($porComando['entregas:ifood-polling']['everyThirtySeconds']) && isset($porComando['entregas:ifood-agendados']['everyMinute']) && isset($porComando['entregas:ifood-tokens']['everyThirtyMinutes']), 'a cada 30 s, a cada minuto e a cada 30 min');
```

por:

```php
confere(array_keys($porComando) === ['entregas:ifood-polling', 'entregas:ifood-agendados', 'entregas:ifood-acompanhar', 'entregas:ifood-tokens'], 'os quatro comandos agendados');
confere(isset($porComando['entregas:ifood-polling']['everyThirtySeconds']) && isset($porComando['entregas:ifood-agendados']['everyMinute']) && isset($porComando['entregas:ifood-acompanhar']['everyThirtySeconds']) && isset($porComando['entregas:ifood-tokens']['everyThirtyMinutes']), 'a cada 30 s, a cada minuto, a cada 30 s e a cada 30 min');
```

- [ ] **Step 2: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-acompanhar.php; PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-agendador.php | tail -3`
Expected: erro fatal no primeiro (classe inexistente) e `FALHA os quatro comandos agendados` no segundo.

- [ ] **Step 3: o comando**

Crie `api/app/Console/Commands/Entregas/AcompanharIfood.php`:

```php
<?php

namespace App\Console\Commands\Entregas;

use App\Jobs\Entregas\EnviarAcaoIfood;
use App\Support\Entregas\Ifood\ChegadaPeloGps;
use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\SequenciaIfood;
use App\Support\Entregas\StatusDoPedido;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: a cada 30 s (App\Console\Kernel), acompanha os pedidos iFood em andamento.
 *
 * - Chegada pelo GPS: compara a última posição do motoboy (drivers.location, gravada pelo track do app) com a coleta e
 *   a entrega (ChegadaPeloGps, raio de 100 m) e enfileira o arrivedAtOrigin ou o arrivedAtDestination. Não escuta cada
 *   posição: o DriverLocationChanged do Fleet-Ops sai por broadcast() e nem passa pelos listeners do Laravel, e uma
 *   leitura a cada 30 s não pesa no socket.
 * - Reconciliação: o pedido cujo estado pede uma ação além da `ultima_acao` (ou com motoboy trocado) e que o
 *   ObservadorDosPedidosIfood não pegou (scheduleOrder com saveQuietly, job perdido no Redis sem persistência) é
 *   enfileirado também.
 *
 * Fica de fora: cancelado pelo iFood, com recusa registrada (recusa_acao: uma ação recusada não se repete sozinha; a
 * próxima mudança do pedido tenta de novo), já com o arrivedAtDestination, sem Order, cancelado ou expirado, e o criado
 * há mais de JANELA_HORAS. EnviarAcaoIfood::enfileirar não enfileira um segundo job do mesmo pedido enquanto o primeiro
 * espera.
 */
class AcompanharIfood extends Command
{
    protected $signature = 'entregas:ifood-acompanhar';

    protected $description = 'iFood: chegada pelo GPS e reconciliação das ações de logística dos pedidos em andamento';

    public const PEDIDOS = 'entregas_ifood_pedidos';

    public const POR_RODADA = 300;

    /** Só pedidos criados nas últimas JANELA_HORAS (um agendado é criado até ~1 dia antes). */
    public const JANELA_HORAS = 24;

    public function handle(): int
    {
        if (!ClienteIfood::ligada()) {
            return self::SUCCESS;
        }

        $linhas = DB::table(static::PEDIDOS)
            ->whereNotNull('order_uuid')
            ->whereNull('cancelado_pelo_ifood_em')
            ->whereNull('recusa_acao')
            ->where('created_at', '>=', now()->subHours(static::JANELA_HORAS)->toDateTimeString())
            ->orderBy('id')
            ->limit(static::POR_RODADA)
            ->get()
            ->all();

        foreach ($linhas as $linha) {
            if ($linha->ultima_acao === SequenciaIfood::CHEGOU_NO_CLIENTE) {
                continue;
            }

            try {
                $this->acompanhar($linha);
            } catch (\Throwable $e) {
                Log::warning('[entregas] ifood: falha ao acompanhar o pedido', ['pedido_ifood' => $linha->pedido_ifood_id, 'erro' => get_class($e)]);
            }
        }

        return self::SUCCESS;
    }

    protected function acompanhar(object $linha): void
    {
        $pedido = Order::where('uuid', $linha->order_uuid)->first();
        if (!$pedido || ($pedido->status !== 'completed' && in_array($pedido->status, StatusDoPedido::ENCERRADOS, true))) {
            return;
        }

        $motoboyUuid = $pedido->driver_assigned_uuid ? (string) $pedido->driver_assigned_uuid : null;
        $estado      = SequenciaIfood::alvoPeloPedido($pedido->status, (bool) $pedido->started, $motoboyUuid);
        $etapa       = SequenciaIfood::maisAdiante($linha->ultima_acao, $estado);

        $chegada = null;
        if ($motoboyUuid !== null && $pedido->status !== 'completed') {
            $motoboy = Driver::where('uuid', $motoboyUuid)->first();
            $chegada = ChegadaPeloGps::acao(
                $etapa,
                static::ponto($motoboy?->location),
                static::ponto($pedido->payload?->getPickupOrFirstWaypoint()?->location),
                static::ponto($pedido->payload?->getDropoffOrLastWaypoint()?->location)
            );
        }

        $alvo  = SequenciaIfood::maisAdiante($estado, $chegada);
        $troca = $motoboyUuid !== null && $linha->ultima_acao !== null && $linha->motoboy_no_ifood && $linha->motoboy_no_ifood !== $motoboyUuid;
        if (!SequenciaIfood::faltando($linha->ultima_acao, $alvo) && !$troca) {
            return;
        }

        if (EnviarAcaoIfood::enfileirar((string) $linha->order_uuid, $chegada) && $chegada !== null) {
            Log::info('[entregas] ifood: chegada pelo GPS', ['acao' => $chegada, 'pedido' => $pedido->public_id, 'numero' => $linha->numero]);
        }
    }

    /** [latitude, longitude] de um Point, ou null. */
    protected static function ponto($ponto): ?array
    {
        return is_object($ponto) && method_exists($ponto, 'getLat') ? [(float) $ponto->getLat(), (float) $ponto->getLng()] : null;
    }
}
```

- [ ] **Step 4: agendar**

Em `api/app/Console/Kernel.php`, troque:

```php
        $schedule->command('entregas:ifood-tokens')->everyThirtyMinutes()
```

por:

```php
        // etapa 3: chegada pelo GPS e reconciliação das ações de logística (AcompanharIfood)
        $schedule->command('entregas:ifood-acompanhar')->everyThirtySeconds()->when($ligada)->withoutOverlapping(5)->runInBackground()->appendOutputTo(static::SAIDA_DO_CONTAINER);
        $schedule->command('entregas:ifood-tokens')->everyThirtyMinutes()
```

- [ ] **Step 5: rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-acompanhar.php && PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-agendador.php`
Expected: os dois com `FALHAS: 0`.

- [ ] **Step 6: commit**

```bash
git rev-parse --show-toplevel
git add api/app/Console/Commands/Entregas/AcompanharIfood.php api/app/Console/Kernel.php scripts/teste-php/ifood-acompanhar.php scripts/teste-php/ifood-agendador.php
git commit -m "iFood etapa 3: entregas:ifood-acompanhar (chegada pelo GPS e reconciliação a cada 30 s)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: cancelamento pelo iFood (CAN)

**Files:**
- Create: `api/app/Support/Entregas/Ifood/CancelamentoPeloIfood.php`
- Modify: `api/app/Jobs/Entregas/ProcessarPedidoIfood.php`
- Modify: `api/app/Notifications/Entregas/AvisosDoMotoboy.php`
- Test: `scripts/teste-php/ifood-cancelamento.php`, `scripts/teste-php/ifood-processar.php`

- [ ] **Step 1: o teste do cancelamento**

Crie `scripts/teste-php/ifood-cancelamento.php`:

```php
<?php

// Integração iFood (etapa 3): cancelamento pelo iFood (CancelamentoPeloIfood) e o texto do push ao motoboy.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-cancelamento.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Support\Entregas\Ifood\AcoesIfood;
use App\Support\Entregas\Ifood\CancelamentoPeloIfood;
use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\VinculosIfood;
use Fleetbase\FleetOps\Models\Order;
use Teste\Banco;
use Teste\Trava;

/** O Order order-1 (aceito, com motoboy) e a linha do pedido iFood; devolve o Order. */
function pedidoIfood(array $order = [], array $linha = []): Order
{
    reiniciarFleetbase();
    reiniciarIfood();
    $pedido         = new Order($order + ['uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1', 'status' => 'enroute', 'started' => true, 'dispatched' => true, 'adhoc' => true, 'driver_assigned_uuid' => 'driver-1']);
    Order::$todos[] = $pedido;
    Banco::inserir('entregas_ifood_pedidos', $linha + [
        'company_uuid' => 'empresa-1', 'order_uuid' => 'order-1', 'pedido_ifood_id' => 'pedido-real-1', 'numero' => '4821', 'merchant_id' => 'merchant-1',
        'created_at' => '2026-10-05 14:50:00', 'updated_at' => '2026-10-05 14:50:00',
    ], false);

    return $pedido;
}

function linha(): object
{
    return (new Teste\Consulta('entregas_ifood_pedidos'))->where('order_uuid', 'order-1')->first();
}

function cancelar(string $quando = '2026-10-05 14:58:00'): string
{
    return (new CancelamentoPeloIfood(new AcoesIfood(new VinculosIfood(new ClienteIfood()), new ClienteIfood())))->aplicar(linha(), $quando);
}

echo '== Dispatch já aceito pelo iFood: cancelado e pago mesmo assim' . PHP_EOL;
$pedido = pedidoIfood([], ['ultima_acao' => 'dispatch']);
confere(cancelar() === CancelamentoPeloIfood::CANCELADO_PAGO, 'resultado: cancelado_pago');
confere($pedido->status === 'canceled' && $pedido->chamadas === ['saveQuietly', 'cancel'], 'sai dos abertos (saveQuietly) e cancel()');
confere($pedido->dispatched === false && $pedido->adhoc === false && $pedido->scheduled_at === null, 'fora dos pedidos abertos e do agendamento');
confere($pedido->travadoNoSalvar === [true] && $pedido->travadoNoCancelar === [[true, true]], 'com a trava do pedido e a das ações do iFood');
confere(linha()->cancelado_pelo_ifood_em === '2026-10-05 14:58:00' && linha()->pago_mesmo_cancelado === true, 'cancelado_pelo_ifood_em e pago_mesmo_cancelado gravados');
confere(\Teste\Sessao::$dados['company'] === 'empresa-1', 'a empresa na sessão para o cancel()');
confere(logou('pedido cancelado pelo iFood', 'warning'), 'log do cancelamento');
confere(!isset(Trava::$ocupadas['entregas:pedido:order-1']) && !isset(Trava::$ocupadas['entregas:ifood-acao:order-1']), 'travas soltas no fim');

echo '== Antes do dispatch: cancelado, sem pagamento' . PHP_EOL;
foreach ([null, 'assignDriver', 'goingToOrigin', 'arrivedAtOrigin'] as $ultima) {
    $pedido = pedidoIfood(['status' => 'started'], ['ultima_acao' => $ultima]);
    confere(cancelar() === CancelamentoPeloIfood::CANCELADO && $pedido->status === 'canceled' && linha()->pago_mesmo_cancelado === false, 'última ação ' . ($ultima ?? 'nenhuma') . ': cancelado, sem pagamento');
}

echo '== Pedido já concluído: nada muda' . PHP_EOL;
$pedido = pedidoIfood(['status' => 'completed'], ['ultima_acao' => 'arrivedAtDestination']);
confere(cancelar() === CancelamentoPeloIfood::JA_CONCLUIDO && $pedido->chamadas === [] && $pedido->status === 'completed', 'não mexe no Order');
confere(linha()->cancelado_pelo_ifood_em === '2026-10-05 14:58:00' && !linha()->pago_mesmo_cancelado && logou('CAN de pedido já concluído', 'info'), 'só registra, com log');

echo '== Idempotente: o CAN repetido não cancela de novo nem troca a data' . PHP_EOL;
$pedido = pedidoIfood([], ['ultima_acao' => 'dispatch']);
cancelar('2026-10-05 14:58:00');
confere(cancelar('2026-10-05 15:10:00') === CancelamentoPeloIfood::JA_CANCELADO && $pedido->chamadas === ['saveQuietly', 'cancel'], 'segunda vez: já cancelado, sem outro cancel()');
confere(linha()->cancelado_pelo_ifood_em === '2026-10-05 14:58:00' && linha()->pago_mesmo_cancelado === true, 'mantém a data e o pago');

echo '== cancel() falhou: a próxima tentativa cancela' . PHP_EOL;
$pedido                       = pedidoIfood([], ['ultima_acao' => 'dispatch']);
Order::$falharCancelamento    = true;
$erro                         = excecao(fn () => cancelar());
confere($erro instanceof RuntimeException && $pedido->dispatched === false && linha()->cancelado_pelo_ifood_em !== null, 'o erro sobe (o CAN fica pendente); já saiu dos abertos');
Order::$falharCancelamento = false;
confere(cancelar() === CancelamentoPeloIfood::CANCELADO_PAGO && $pedido->status === 'canceled', 'na tentativa seguinte, cancela');

echo '== Travas ocupadas: nada gravado' . PHP_EOL;
foreach (['entregas:pedido:order-1' => 'do pedido', 'entregas:ifood-acao:order-1' => 'das ações'] as $trava => $nome) {
    $pedido                 = pedidoIfood([], ['ultima_acao' => 'dispatch']);
    Trava::$ocupadas[$trava] = true;
    $erro                    = excecao(fn () => cancelar());
    confere($erro instanceof \Illuminate\Contracts\Cache\LockTimeoutException && $pedido->chamadas === [] && linha()->cancelado_pelo_ifood_em === null, "trava {$nome} ocupada: LockTimeoutException, nada gravado");
}

echo '== Sem Order' . PHP_EOL;
pedidoIfood([], ['order_uuid' => null]);
$semOrder = (new Teste\Consulta('entregas_ifood_pedidos'))->where('pedido_ifood_id', 'pedido-real-1')->first();
confere((new CancelamentoPeloIfood(app(AcoesIfood::class)))->aplicar($semOrder, '2026-10-05 14:58:00') === CancelamentoPeloIfood::SEM_PEDIDO, 'linha sem order_uuid: só registra');
pedidoIfood();
Order::$todos = [];
confere(cancelar() === CancelamentoPeloIfood::SEM_PEDIDO && linha()->cancelado_pelo_ifood_em === '2026-10-05 14:58:00', 'Order sumiu: só registra');

echo '== Texto do push ao motoboy' . PHP_EOL;
confere(CancelamentoPeloIfood::textoDoPush((object) ['numero' => '4821', 'cancelado_pelo_ifood_em' => '2026-10-05 14:58:00', 'pago_mesmo_cancelado' => true]) === ['Pedido #4821 cancelado pelo iFood', 'Você recebe por esta entrega. Combine com a loja a devolução.'], 'pago: título com o número e o aviso do pagamento');
confere(CancelamentoPeloIfood::textoDoPush((object) ['numero' => '4821', 'cancelado_pelo_ifood_em' => '2026-10-05 14:58:00', 'pago_mesmo_cancelado' => false]) === ['Pedido #4821 cancelado pelo iFood', 'Não precisa mais fazer esta entrega.'], 'sem pagamento');
confere(CancelamentoPeloIfood::textoDoPush((object) ['numero' => null, 'cancelado_pelo_ifood_em' => '2026-10-05 14:58:00', 'pago_mesmo_cancelado' => false])[0] === 'Pedido cancelado pelo iFood', 'sem número');
confere(CancelamentoPeloIfood::textoDoPush((object) ['numero' => '4821', 'cancelado_pelo_ifood_em' => null, 'pago_mesmo_cancelado' => false]) === null, 'não cancelado pelo iFood: null (texto comum)');

resumo();
```

- [ ] **Step 2: o comportamento novo no teste do job**

Em `scripts/teste-php/ifood-processar.php` (os casos do CAN eram da etapa 2, que só registrava):

1. Troque `echo '== CAN depois de criado: só registra' . PHP_EOL;` por `echo '== CAN depois de criado: cancela o pedido no Entregas (etapa 3)' . PHP_EOL;`.
2. Troque:

```php
confere(($contextoDoCan['pedido'] ?? null) === 'order_pub1' && ($contextoDoCan['order_uuid'] ?? null) === 'order-1', 'log do CAN com o public_id do Order e o order_uuid');
```

por:

```php
confere(($contextoDoCan['pedido'] ?? null) === 'order_pub1' && ($contextoDoCan['order_uuid'] ?? null) === 'order-1', 'log do CAN com o public_id do Order e o order_uuid');
confere(Order::where('uuid', 'order-1')->first()->status === 'canceled', 'o Order é cancelado');
```

3. Troque `echo '== CAN de agendado ainda não despachado: o Order sai do agendamento (fleetops:dispatch-orders) e do pedido aberto' . PHP_EOL;` por `echo '== CAN de agendado ainda não despachado: cancelado, fora do agendamento (fleetops:dispatch-orders) e do pedido aberto' . PHP_EOL;`.
4. Troque:

```php
confere($agendado->chamadas === ['saveQuietly'] && $agendado->travadoNoSalvar === [true], 'gravado com saveQuietly, uma vez, com a trava do pedido (TravaDoPedido)');
```

por:

```php
confere($agendado->chamadas === ['saveQuietly', 'cancel'] && $agendado->travadoNoSalvar === [true], 'sai dos abertos com saveQuietly, com a trava do pedido (TravaDoPedido), e depois cancel()');
```

5. Troque:

```php
confere(!in_array('cancel', $agendado->chamadas, true) && $agendado->status === 'created', 'o Order não é cancelado (etapa 3)');
```

por:

```php
confere($agendado->status === 'canceled' && $agendado->travadoNoCancelar === [[true, true]], 'Order cancelado com a trava do pedido e a das ações do iFood');
```

6. Troque `echo '== CAN de pedido já despachado ou já aceito: o Order não é mexido' . PHP_EOL;` por `echo '== CAN de pedido já despachado ou já aceito (sem dispatch no iFood): cancelado, sem pagamento' . PHP_EOL;`.
7. Troque:

```php
    confere($pedidoOrder->chamadas === [] && $pedidoOrder->scheduled_at === '2026-10-05 15:40:00' && $pedidoOrder->adhoc === true, "{$caso}: scheduled_at e adhoc intactos, sem saveQuietly");
    confere(linhaDoPedido()->cancelado_pelo_ifood_em === '2026-10-05 15:02:00', "{$caso}: o cancelamento é registrado");
```

por:

```php
    confere($pedidoOrder->chamadas === ['saveQuietly', 'cancel'] && $pedidoOrder->status === 'canceled' && $pedidoOrder->dispatched === false && $pedidoOrder->adhoc === false && $pedidoOrder->scheduled_at === null, "{$caso}: cancelado e fora dos pedidos abertos");
    confere(linhaDoPedido()->cancelado_pelo_ifood_em === '2026-10-05 15:02:00' && !linhaDoPedido()->pago_mesmo_cancelado, "{$caso}: registrado, sem pagamento (o dispatch não tinha saído)");
```

- [ ] **Step 3: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-cancelamento.php; PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-processar.php | grep FALHA`
Expected: erro fatal no primeiro (classe inexistente) e falhas nos casos do CAN no segundo.

- [ ] **Step 4: `CancelamentoPeloIfood`**

Crie `api/app/Support/Entregas/Ifood/CancelamentoPeloIfood.php`:

```php
<?php

namespace App\Support\Entregas\Ifood;

use App\Support\Entregas\StatusDoPedido;
use App\Support\Entregas\TravaDoPedido;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: o iFood cancelou o pedido (evento CAN, processado pelo ProcessarPedidoIfood). Spec, seção 3:
 *
 * - pedido já concluído: nada muda (só o cancelado_pelo_ifood_em e o log);
 * - nos outros casos, cancela como o portal da loja (RegrasPortalLoja::completarCancelamento): sai dos pedidos abertos
 *   sem eventos (dispatched e adhoc falsos, scheduled_at nulo, saveQuietly) e depois Order::cancel(): atividade
 *   "canceled" e OrderCanceled na fila (depois do commit), que o HandleOrderCanceled do Fleet-Ops transforma no push
 *   OrderCanceled ao motoboy atribuído. O texto do push sai do AvisosDoMotoboy: "Pedido #4821 cancelado pelo iFood";
 * - com o dispatch já aceito pelo iFood (ultima_acao dispatch ou depois): pago_mesmo_cancelado. O relatório de
 *   pagamento, a cobrança da loja e os ganhos do motoboy contam o pedido pelo valor congelado da faixa, na data do
 *   cancelamento (CalculoEntregas::pedidosConcluidos), e o push acrescenta "Você recebe por esta entrega…";
 * - pedido já cancelado (por uma tentativa anterior que caiu depois do cancel): só grava o que faltar.
 *
 * Roda com a trava do pedido (TravaDoPedido, a mesma do aceite do motoboy) e, dentro dela, a das ações do iFood
 * (AcoesIfood::comATrava): um aceite ou um dispatch no mesmo instante esperam, e a ultima_acao lida é a definitiva.
 * Grava a linha antes do cancel(): o push do OrderCanceled (fila) lê o cancelado_pelo_ifood_em e o pago_mesmo_cancelado
 * para o texto. Idempotente: o job chama de novo enquanto o CAN estiver pendente.
 */
class CancelamentoPeloIfood
{
    public const CANCELADO      = 'cancelado';
    public const CANCELADO_PAGO = 'cancelado_pago';
    public const JA_CONCLUIDO   = 'ja_concluido';
    public const JA_CANCELADO   = 'ja_cancelado';
    public const SEM_PEDIDO     = 'sem_pedido';

    /** O que o push acrescenta quando o motoboy recebe mesmo com o cancelamento (spec, seção 3). */
    public const TEXTO_PAGO = 'Você recebe por esta entrega. Combine com a loja a devolução.';

    public function __construct(protected AcoesIfood $acoes) {}

    /**
     * @param string $quando a data do CAN (createdAt do evento, já no fuso do app)
     *
     * @return string o que aconteceu (uma das constantes)
     *
     * @throws LockTimeoutException trava do pedido ou das ações ocupada (o CAN fica pendente e o job tenta de novo)
     */
    public function aplicar(object $linha, string $quando): string
    {
        if (!$linha->order_uuid) {
            PedidosIfood::atualizar($linha, ['cancelado_pelo_ifood_em' => $linha->cancelado_pelo_ifood_em ?? $quando]);

            return static::SEM_PEDIDO;
        }

        $orderUuid = (string) $linha->order_uuid;

        return TravaDoPedido::executar($orderUuid, fn () => $this->acoes->comATrava($orderUuid, fn () => $this->aplicarComAsTravas($orderUuid, $quando)));
    }

    /**
     * Título e texto do push ao motoboy (AvisosDoMotoboy, no OrderCanceled) para o pedido cancelado pelo iFood, ou null
     * se o cancelamento não foi do iFood.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function textoDoPush(object $linha): ?array
    {
        if (empty($linha->cancelado_pelo_ifood_em)) {
            return null;
        }

        $numero = trim((string) ($linha->numero ?? ''));
        $titulo = $numero !== '' ? "Pedido #{$numero} cancelado pelo iFood" : 'Pedido cancelado pelo iFood';

        return [$titulo, !empty($linha->pago_mesmo_cancelado) ? static::TEXTO_PAGO : 'Não precisa mais fazer esta entrega.'];
    }

    protected function aplicarComAsTravas(string $orderUuid, string $quando): string
    {
        // relidos com as travas: um aceite ou uma ação que terminou enquanto o job esperava aparece aqui
        $linha  = PedidosIfood::doPedido($orderUuid);
        $pedido = Order::where('uuid', $orderUuid)->first();
        $quando = $linha->cancelado_pelo_ifood_em ?? $quando;

        if (!$pedido) {
            PedidosIfood::atualizar($linha, ['cancelado_pelo_ifood_em' => $quando]);

            return static::SEM_PEDIDO;
        }

        if ($pedido->status === 'completed') {
            PedidosIfood::atualizar($linha, ['cancelado_pelo_ifood_em' => $quando]);
            Log::info('[entregas] ifood: CAN de pedido já concluído; nada muda', ['pedido' => $pedido->public_id, 'numero' => $linha->numero]);

            return static::JA_CONCLUIDO;
        }

        $pago = (bool) $linha->pago_mesmo_cancelado || SequenciaIfood::saiuParaEntrega($linha->ultima_acao);
        PedidosIfood::atualizar($linha, ['cancelado_pelo_ifood_em' => $quando, 'pago_mesmo_cancelado' => $pago]);

        if (in_array($pedido->status, StatusDoPedido::CANCELADOS, true)) {
            return static::JA_CANCELADO;
        }

        // primeiro e sem eventos: sai dos pedidos abertos do app e do agendamento mesmo que o cancel() falhe
        $pedido->dispatched   = false;
        $pedido->adhoc        = false;
        $pedido->scheduled_at = null;
        $pedido->saveQuietly();

        session(['company' => $pedido->company_uuid]);
        // atividade "canceled" + OrderCanceled na fila → HandleOrderCanceled avisa o motoboy atribuído (push)
        $pedido->cancel();

        Log::warning('[entregas] ifood: pedido cancelado pelo iFood', [
            'pedido'               => $pedido->public_id,
            'order_uuid'           => $pedido->uuid,
            'numero'               => $linha->numero,
            'pago_mesmo_cancelado' => $pago,
            'com_motoboy'          => (bool) $pedido->driver_assigned_uuid,
        ]);

        return $pago ? static::CANCELADO_PAGO : static::CANCELADO;
    }
}
```

- [ ] **Step 5: o job chama o cancelamento**

Em `api/app/Jobs/Entregas/ProcessarPedidoIfood.php`:

1. Troque `use App\Support\Entregas\Ifood\ClienteIfood;` por:

```php
use App\Support\Entregas\Ifood\CancelamentoPeloIfood;
use App\Support\Entregas\Ifood\ClienteIfood;
```

2. Apague a linha `use Fleetbase\FleetOps\Models\Order;` (o único uso some no passo 6).
3. No docblock da classe, troque:

```php
 * - DDCR marca exige_codigo; CAN registra cancelado_pelo_ifood_em e, se o pedido ainda não foi despachado nem aceito,
 *   tira o Order do agendamento (CriadorDoPedidoIfood::tirarDoAgendamento: scheduled_at nulo e adhoc desligado, com a
 *   TravaDoPedido; senão o fleetops:dispatch-orders o despacharia aos motoboys na hora marcada) e a linha da fila do
 *   agendador (despachar_em nulo). O Order continua aberto no console: o cancelamento dele no Entregas é da etapa 3. O
 *   pedido já despachado continua aberto aos motoboys até a central cancelar;
```

por:

```php
 * - DDCR marca exige_codigo; CAN cancela o pedido no Entregas (CancelamentoPeloIfood, etapa 3: como o portal, com o
 *   push ao motoboy e o pago_mesmo_cancelado quando o dispatch já tinha saído) e tira a linha da fila do agendador
 *   (despachar_em nulo). Com a trava do pedido ocupada, o LockTimeoutException sobe, o CAN fica pendente e a fila tenta
 *   de novo pelo $backoff; o cancelamento é idempotente;
```

4. Troque:

```php
    public function __construct(public string $pedidoIfoodId)
    {
    }
```

por:

```php
    /** O cancelamento do CAN (injetado no handle; o teste pode trocar). */
    protected ?CancelamentoPeloIfood $cancelamento = null;

    public function __construct(public string $pedidoIfoodId)
    {
    }
```

5. Troque:

```php
    public function handle(VinculosIfood $vinculos, ClienteIfood $cliente, CriadorDoPedidoIfood $criador): void
    {
```

por:

```php
    public function handle(VinculosIfood $vinculos, ClienteIfood $cliente, CriadorDoPedidoIfood $criador, ?CancelamentoPeloIfood $cancelamento = null): void
    {
        $this->cancelamento = $cancelamento ?? app(CancelamentoPeloIfood::class);

```

6. Troque o bloco do CAN no `processar()`:

```php
            if ($acao === EventosIfood::CANCELA && $pedido && !$pedido->cancelado_pelo_ifood_em) {
                // ainda não despachado (agendado ou despacho que falhou): o Order sai do agendamento (scheduled_at nulo,
                // adhoc desligado), senão o fleetops:dispatch-orders o despacharia aos motoboys na hora marcada. Antes
                // de gravar o cancelamento: com a trava do pedido ocupada, o LockTimeoutException sobe, o CAN fica
                // pendente e a fila tenta de novo pelo $backoff
                if ($pedido->order_uuid) {
                    $criador->tirarDoAgendamento($pedido->order_uuid);
                }
                $quando = $evento['createdAt'] ? substr((string) $evento['createdAt'], 0, 19) : now()->toDateTimeString();
                $this->atualizarPedido($pedido, ['cancelado_pelo_ifood_em' => $quando]);
                $pedido->cancelado_pelo_ifood_em = $quando;
                // e a linha sai da fila do entregas:ifood-agendados. Condicional no banco: o agendador pode ter
                // despachado depois de a linha ser lida
                DB::table(static::PEDIDOS)->where('id', $pedido->id)->whereNotNull('despachar_em')->whereNull('despachado_em')
                    ->update(['despachar_em' => null, 'updated_at' => now()->toDateTimeString()]);
                Log::warning('[entregas] ifood: pedido cancelado pelo iFood (o cancelamento no Entregas é da etapa 3)', [
                    'pedido'     => $this->publicIdDoOrder($pedido),
                    'order_uuid' => $pedido->order_uuid,
                    'numero'     => $pedido->numero,
                ]);
            }
```

por:

```php
            if ($acao === EventosIfood::CANCELA && $pedido) {
                // cancela no Entregas (etapa 3), com a trava do pedido: ocupada, o LockTimeoutException sobe, o CAN fica
                // pendente e a fila tenta de novo pelo $backoff. Idempotente (o CAN repetido não cancela duas vezes)
                $quando = $evento['createdAt'] ? substr((string) $evento['createdAt'], 0, 19) : now()->toDateTimeString();
                $this->cancelamento->aplicar($pedido, $quando);
                // e a linha sai da fila do entregas:ifood-agendados. Condicional no banco: o agendador pode ter
                // despachado depois de a linha ser lida
                DB::table(static::PEDIDOS)->where('id', $pedido->id)->whereNotNull('despachar_em')->whereNull('despachado_em')
                    ->update(['despachar_em' => null, 'updated_at' => now()->toDateTimeString()]);
                $pedido = DB::table(static::PEDIDOS)->where('id', $pedido->id)->first();
            }
```

7. Apague o método `publicIdDoOrder()` inteiro (o docblock `/** O public_id do Order (o que a central vê), ou null se o Order sumiu. */` e as 4 linhas do método), que ficou sem uso.

O `CriadorDoPedidoIfood::tirarDoAgendamento` continua: o `entregas:ifood-agendados` o usa como reserva.

- [ ] **Step 6: o texto do push**

Em `api/app/Notifications/Entregas/AvisosDoMotoboy.php`:

1. Troque `use App\Support\Entregas\CartaoDoAlarme;` por:

```php
use App\Support\Entregas\CartaoDoAlarme;
use App\Support\Entregas\Ifood\CancelamentoPeloIfood;
use App\Support\Entregas\Ifood\PedidosIfood;
```

2. Troque a linha do `OrderCanceled` no `match` do `texto()`:

```php
            $notificacao instanceof OrderCanceled        => [static::comCodigo('Pedido %s cancelado', 'Pedido cancelado', $codigo), static::comCodigo('O pedido %s foi cancelado.', 'O pedido foi cancelado.', $codigo)],
```

por:

```php
            $notificacao instanceof OrderCanceled        => static::canceladoPeloIfood($notificacao) ?? [static::comCodigo('Pedido %s cancelado', 'Pedido cancelado', $codigo), static::comCodigo('O pedido %s foi cancelado.', 'O pedido foi cancelado.', $codigo)],
```

3. Troque:

```php
    /** "Coleta a 1,2 km de você. Toque para ver o pedido." (sem distância, só o convite). */
```

por:

```php
    /**
     * Pedido iFood cancelado pelo iFood (CAN): "Pedido #4821 cancelado pelo iFood", e "Você recebe por esta entrega…"
     * quando o dispatch já tinha saído (CancelamentoPeloIfood::textoDoPush). null para os outros cancelamentos, ou se a
     * consulta falhar (sai o texto comum).
     */
    protected static function canceladoPeloIfood(Notification $notificacao): ?array
    {
        try {
            $uuid  = $notificacao->order->uuid ?? null;
            $linha = $uuid ? PedidosIfood::doPedido((string) $uuid) : null;

            return $linha ? CancelamentoPeloIfood::textoDoPush($linha) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** "Coleta a 1,2 km de você. Toque para ver o pedido." (sem distância, só o convite). */
```

- [ ] **Step 7: rodar e ver passar**

Run: `for t in ifood-cancelamento ifood-processar ifood-agendador avisos-push; do PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php | tail -1; done`
Expected: quatro `FALHAS: 0` (o `avisos-push.php` continua com o texto comum: os avisos do teste não têm pedido).

- [ ] **Step 8: commit**

```bash
git rev-parse --show-toplevel
git add api/app/Support/Entregas/Ifood/CancelamentoPeloIfood.php api/app/Jobs/Entregas/ProcessarPedidoIfood.php api/app/Notifications/Entregas/AvisosDoMotoboy.php scripts/teste-php/ifood-cancelamento.php scripts/teste-php/ifood-processar.php
git commit -m "iFood etapa 3: o CAN cancela o pedido (pago mesmo cancelado depois do dispatch, push ao motoboy)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: pago mesmo cancelado no relatório, na cobrança e nos ganhos

**Files:**
- Modify: `api/app/Support/Entregas/CalculoEntregas.php`
- Modify: `api/app/Support/Entregas/GanhosDoMotoboy.php`
- Test: `scripts/teste-php/ganhos-motoboy.php`

- [ ] **Step 1: os testes**

Em `scripts/teste-php/ganhos-motoboy.php`, troque:

```php
confere(array_keys($entregas[0]) === ['loja', 'loja_nome', 'pedido', 'id_interno', 'motoboy', 'motoboy_nome', 'concluido_em', 'origem', 'destino', 'km', 'fonte', 'faixa', 'valor_motoboy', 'valor_loja'], 'o formato do relatório e do extrato não muda');
```

por:

```php
confere(array_keys($entregas[0]) === ['loja', 'loja_nome', 'pedido', 'id_interno', 'motoboy', 'motoboy_nome', 'concluido_em', 'origem', 'destino', 'km', 'fonte', 'faixa', 'valor_motoboy', 'valor_loja', 'cancelado_pago'], 'o formato do relatório e do extrato (cancelado_pago: etapa 3 do iFood)');
confere($entregas[0]['cancelado_pago'] === false, 'pedido concluído: cancelado_pago falso');
```

e troque:

```php
confere(array_keys($linha) === ['pedido', 'concluido_em', 'loja', 'destino', 'km', 'aproximado', 'faixa', 'valor'], 'só os campos do app');
```

por:

```php
confere(array_keys($linha) === ['pedido', 'concluido_em', 'loja', 'destino', 'km', 'aproximado', 'faixa', 'valor', 'cancelado_pago'], 'só os campos do app');
confere($linha['cancelado_pago'] === false && GanhosDoMotoboy::linha(['cancelado_pago' => true] + $entrega)['cancelado_pago'] === true, 'cancelado_pago repassado ao app');
```

- [ ] **Step 2: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ganhos-motoboy.php | grep FALHA`
Expected: `FALHA o formato do relatório…` e `FALHA só os campos do app`.

- [ ] **Step 3: `CalculoEntregas`**

Em `api/app/Support/Entregas/CalculoEntregas.php`:

1. No docblock da classe, troque:

```php
 * O período é filtrado pela data em que o pedido foi concluído (tracking status COMPLETED),
 * no fuso da organização.
```

por:

```php
 * O período é filtrado pela data em que o pedido foi concluído (tracking status COMPLETED),
 * no fuso da organização. O pedido iFood cancelado pelo iFood depois do dispatch (pago_mesmo_cancelado, na
 * entregas_ifood_pedidos) também entra, pela data do cancelamento (CancelamentoPeloIfood): o motoboy recebe e a loja é
 * cobrada pelo valor da faixa, e a linha sai com cancelado_pago = true.
```

2. Troque `    /** Linha reta → rua, usado só quando o OSRM não responde. */` por:

```php
    /**
     * Data da entrega no relatório, na cobrança e nos ganhos: a do COMPLETED (ou o updated_at) para o pedido concluído; a
     * do cancelamento pelo iFood para o pago mesmo cancelado (o único não concluído que entra: ver pedidosConcluidos).
     */
    public const DATA_DA_ENTREGA = "CASE WHEN orders.status = 'completed' THEN COALESCE(conclusoes.concluido_em, orders.updated_at) ELSE entregas_ifood.cancelado_pelo_ifood_em END";

    /** Linha reta → rua, usado só quando o OSRM não responde. */
```

3. Troque o docblock do `pedidosConcluidos`:

```php
    /**
     * Pedidos concluídos no período, com a data de conclusão em `entregas_concluido_em`.
     * $filtro recebe a query para restringir (por motoboy, por loja).
     */
```

por:

```php
    /**
     * Pedidos concluídos no período, com a data de conclusão em `entregas_concluido_em`, e os pedidos iFood pagos mesmo
     * cancelados, pela data do cancelamento (`entregas_cancelado_pago` = 1).
     * $filtro recebe a query para restringir (por motoboy, por loja); use colunas com a tabela (orders.…).
     */
```

4. Troque:

```php
            ->leftJoinSub($conclusoes, 'conclusoes', 'conclusoes.tracking_number_uuid', '=', 'orders.tracking_number_uuid')
            ->where('orders.company_uuid', $companyUuid)
            ->where('orders.status', 'completed')
            ->whereNull('orders.deleted_at')
            ->whereNotNull('orders.driver_assigned_uuid')
            ->whereBetween(DB::raw('COALESCE(conclusoes.concluido_em, orders.updated_at)'), [$inicio, $fim])
```

por:

```php
            ->leftJoinSub($conclusoes, 'conclusoes', 'conclusoes.tracking_number_uuid', '=', 'orders.tracking_number_uuid')
            // a linha do iFood (uma por pedido, order_uuid único): o pago mesmo cancelado
            ->leftJoin('entregas_ifood_pedidos as entregas_ifood', 'entregas_ifood.order_uuid', '=', 'orders.uuid')
            ->where('orders.company_uuid', $companyUuid)
            ->where(fn ($situacao) => $situacao->where('orders.status', 'completed')
                ->orWhere(fn ($pago) => $pago->where('entregas_ifood.pago_mesmo_cancelado', true)->whereNotNull('entregas_ifood.cancelado_pelo_ifood_em')))
            ->whereNull('orders.deleted_at')
            ->whereNotNull('orders.driver_assigned_uuid')
            ->whereBetween(DB::raw(static::DATA_DA_ENTREGA), [$inicio, $fim])
```

5. Troque:

```php
            ->select('orders.*', DB::raw('COALESCE(conclusoes.concluido_em, orders.updated_at) as entregas_concluido_em'))
```

por:

```php
            ->select('orders.*', DB::raw(static::DATA_DA_ENTREGA . ' as entregas_concluido_em'), DB::raw("CASE WHEN orders.status = 'completed' THEN 0 ELSE 1 END as entregas_cancelado_pago"))
```

6. No `entregas()`, troque:

```php
                'valor_loja'    => $valor['loja'] ?? null,
            ];
```

por:

```php
                'valor_loja'    => $valor['loja'] ?? null,
                // pedido iFood cancelado pelo iFood depois do dispatch: conta pela data do cancelamento
                'cancelado_pago' => (bool) ($pedido->entregas_cancelado_pago ?? false),
            ];
```

O `Order::cancel()` do Fleet-Ops não tira o motoboy do pedido, então o `whereNotNull('orders.driver_assigned_uuid')` e o
filtro por motoboy dos ganhos continuam valendo para o pago mesmo cancelado.

- [ ] **Step 4: `GanhosDoMotoboy`**

Em `api/app/Support/Entregas/GanhosDoMotoboy.php`, troque:

```php
            'valor'        => $entrega['valor_motoboy'],
        ];
```

por:

```php
            'valor'        => $entrega['valor_motoboy'],
            // pedido iFood cancelado pelo iFood depois do dispatch: ele recebe (data = a do cancelamento)
            'cancelado_pago' => (bool) ($entrega['cancelado_pago'] ?? false),
        ];
```

- [ ] **Step 5: rodar e ver passar**

Run: `for t in ganhos-motoboy fuso-relatorio; do PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php | tail -1; done; PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/CalculoEntregas.php`
Expected: dois `FALHAS: 0` e `OK`. A consulta SQL em si é conferida na produção (Task 15, passo do pago mesmo cancelado).

- [ ] **Step 6: commit**

```bash
git rev-parse --show-toplevel
git add api/app/Support/Entregas/CalculoEntregas.php api/app/Support/Entregas/GanhosDoMotoboy.php scripts/teste-php/ganhos-motoboy.php
git commit -m "iFood etapa 3: pago mesmo cancelado no relatório, na cobrança e nos ganhos (data do cancelamento)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: cancelamento proibido e a trava "Atualize o app"

**Files:**
- Create: `api/app/Http/Middleware/RegrasDoPedidoIfood.php`
- Modify: `api/app/Http/Middleware/RegrasPortalLoja.php`
- Modify: `api/app/Providers/RouteServiceProvider.php` (middleware)
- Modify: `deploy/docker-stack.yml`, `deploy/stack.env.example`
- Test: `scripts/teste-php/ifood-regras.php`

- [ ] **Step 1: o teste**

Crie `scripts/teste-php/ifood-regras.php`:

```php
<?php

// Integração iFood (etapa 3): regras dos pedidos iFood nas rotas do Fleet-Ops (RegrasDoPedidoIfood): cancelamento
// proibido (API v1 e console) e a trava "Atualize o app" na conclusão comum.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-regras.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Http\Middleware\RegrasDoPedidoIfood;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Teste\Banco;
use Teste\Config;

const API     = 'Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController@';
const CONSOLE = 'Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController@';

/** Dois pedidos: order-1 é do iFood (número 4821), order-2 não. */
function pedidos(array $linha = []): void
{
    reiniciarFleetbase();
    reiniciarIfood();
    session(['company' => 'empresa-1']);
    Order::$todos[] = new Order(['uuid' => 'order-1', 'public_id' => 'order_1', 'internal_id' => '4821', 'company_uuid' => 'empresa-1', 'status' => 'enroute']);
    Order::$todos[] = new Order(['uuid' => 'order-2', 'public_id' => 'order_2', 'company_uuid' => 'empresa-1', 'status' => 'enroute']);
    Banco::inserir('entregas_ifood_pedidos', $linha + ['company_uuid' => 'empresa-1', 'order_uuid' => 'order-1', 'pedido_ifood_id' => 'pedido-real-1', 'numero' => '4821', 'merchant_id' => 'merchant-1'], false);
}

/** Roda o middleware para a ação; devolve a resposta dele ou 'passou'. */
function rodar(string $acao, array $parametros = [], array $dados = [])
{
    $request       = new Request($dados);
    $request->rota = new Route($acao, $parametros);

    return (new RegrasDoPedidoIfood())->handle($request, fn () => 'passou');
}

echo '== Cancelamento proibido' . PHP_EOL;
pedidos();
$resposta = rodar(API . 'cancelOrder', ['id' => 'order_1']);
confere($resposta->status === 400 && $resposta->dados === ['error' => 'Pedido do iFood: o cancelamento é feito no iFood.'], 'API v1 (DELETE v1/orders/{id}/cancel): 400 no formato da v1');
confere(rodar(API . 'cancelOrder', ['id' => '4821'])->status === 400, 'API v1 pelo internal_id: 400');
confere(rodar(API . 'cancelOrder', ['id' => 'order_2']) === 'passou', 'pedido que não é do iFood: passa');
$resposta = rodar(CONSOLE . 'cancel', [], ['order' => 'order-1']);
confere($resposta->status === 400 && $resposta->dados === ['errors' => ['Pedido do iFood: o cancelamento é feito no iFood.']], 'console (PATCH int/v1/orders/cancel): 400 no formato do console');
confere(rodar(CONSOLE . 'cancel', [], ['order' => 'order-2']) === 'passou', 'console, outro pedido: passa');
confere(rodar(CONSOLE . 'bulkCancel', [], ['ids' => ['order-2', 'order-1']])->status === 400, 'cancelamento em lote com um pedido iFood: 400');
confere(rodar(CONSOLE . 'bulkCancel', [], ['ids' => ['order-2']]) === 'passou', 'lote sem pedido iFood: passa');
confere(rodar(CONSOLE . 'updateActivity', ['id' => 'order-1'], ['activity' => ['code' => 'canceled']])->status === 400, 'atividade "canceled" no console (quadro): 400');
confere(rodar(CONSOLE . 'updateActivity', ['id' => 'order-1'], ['activity' => ['code' => 'completed']]) === 'passou', 'conclusão pelo console: passa (decisão da central)');
confere(rodar(API . 'updateActivity', ['id' => 'order_1'], ['activity' => ['code' => 'canceled']])->status === 400, 'atividade "canceled" na API v1: 400');
session(['company' => 'outra-empresa']);
confere(rodar(API . 'cancelOrder', ['id' => 'order_1']) === 'passou', 'pedido de outra empresa: passa (o Fleet-Ops responde 404)');

echo '== Trava "Atualize o app" (desligada por padrão)' . PHP_EOL;
pedidos();
confere(rodar(API . 'updateActivity', ['id' => 'order_1'], ['activity' => ['code' => 'completed', 'complete' => true]]) === 'passou', 'desligada: a conclusão comum passa');
Config::$valores['services.ifood.exige_app_novo'] = '1';
$resposta = rodar(API . 'updateActivity', ['id' => 'order_1'], ['activity' => ['code' => 'completed', 'complete' => true]]);
confere($resposta->status === 400 && $resposta->dados === ['error' => 'Atualize o app para concluir pedidos do iFood.'], 'ligada: conclusão sem a rota concluir-ifood → 400 "Atualize o app"');
confere(rodar(API . 'updateActivity', ['id' => 'order_1'], ['activity' => ['code' => 'entregue', 'complete' => true]])->status === 400, 'atividade que conclui com outro código: 400');
confere(rodar(API . 'completeOrder', ['id' => 'order_1'])->status === 400, 'POST v1/orders/{id}/complete: 400');
confere(rodar(API . 'updateActivity', ['id' => 'order_1'], ['activity' => ['code' => 'enroute']]) === 'passou', 'outras atividades passam');
confere(rodar(API . 'updateActivity', ['id' => 'order_2'], ['activity' => ['code' => 'completed', 'complete' => true]]) === 'passou', 'pedido que não é do iFood passa');
pedidos(['conclusao_liberada_em' => '2026-10-05 14:59:00']);
Config::$valores['services.ifood.exige_app_novo'] = '1';
confere(rodar(API . 'updateActivity', ['id' => 'order_1'], ['activity' => ['code' => 'completed', 'complete' => true]]) === 'passou', 'liberado pela rota concluir-ifood: passa');

echo '== Outras ações' . PHP_EOL;
pedidos();
confere(rodar(API . 'startOrder', ['id' => 'order_1']) === 'passou', 'aceite: passa (fica com o BarrarAceiteDePedidoEncerrado)');
confere((new RegrasDoPedidoIfood())->handle(new Request(), fn () => 'passou') === 'passou', 'requisição sem rota: passa');

resumo();
```

- [ ] **Step 2: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-regras.php`
Expected: erro fatal `Class "App\Http\Middleware\RegrasDoPedidoIfood" not found`.

- [ ] **Step 3: o middleware**

Crie `api/app/Http/Middleware/RegrasDoPedidoIfood.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\PedidosIfood;
use Closure;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController as OrderDaApi;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController as OrderDoConsole;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: regras dos pedidos iFood nas rotas do Fleet-Ops (que vêm do Composer), pela ação que o
 * roteador casou (o caminho, codificado ou não, não importa). Spec, seções 3 e 4.
 *
 * 1. Cancelamento do nosso lado é proibido (400 "Pedido do iFood: o cancelamento é feito no iFood"): o Logistics não
 *    tem ação de cancelar, e para nós vale o CAN do iFood (CancelamentoPeloIfood). Com problema do motoboy, a central
 *    troca o motoboy.
 *    - API v1: DELETE v1/orders/{id}/cancel (cancelOrder) e a atividade "canceled" (update-activity);
 *    - console: PATCH int/v1/orders/cancel (corpo `order` = uuid), PATCH int/v1/orders/bulk-cancel (`ids`) e a
 *      atividade "canceled" (PATCH int/v1/orders/update-activity/{id}, que o quadro usa ao arrastar o card).
 *    - O portal da loja é barrado no RegrasPortalLoja (rota do customer-portal).
 * 2. Trava "Atualize o app" (só com ClienteIfood::exigeAppNovo(), ENTREGAS_IFOOD_EXIGE_APP_NOVO=1): a conclusão comum
 *    pela API v1 (atividade "completed" ou que conclui o pedido no update-activity, e o POST v1/orders/{id}/complete) de
 *    pedido iFood é recusada com 400 até a rota concluir-ifood do APK novo liberar (conclusao_liberada_em: chegada
 *    avisada e, se exigido, código conferido). O APK antigo não conhece a rota e não consegue concluir. A conclusão
 *    pelo console é decisão da central e passa (o AcoesIfood registra conclusao_sem_codigo quando faltou o código).
 *
 * Registrado nos grupos fleetbase.api (v1) e fleetbase.protected (int/v1) pelo RouteServiceProvider, antes do
 * BarrarAceiteDePedidoEncerrado: roda depois da autenticação, com a empresa na sessão (o filtro da empresa é explícito;
 * nenhum model registra o CompanyScope nesta versão). Os erros saem no formato de cada API: {"error": "..."} na v1 (o do
 * Fleet-Ops) e {"errors": ["..."]} no console.
 */
class RegrasDoPedidoIfood
{
    public const CANCELAR_NA_API     = OrderDaApi::class . '@cancelOrder';
    public const ATIVIDADE_NA_API    = OrderDaApi::class . '@updateActivity';
    public const CONCLUIR_NA_API     = OrderDaApi::class . '@completeOrder';
    public const CANCELAR_NO_CONSOLE = OrderDoConsole::class . '@cancel';
    public const CANCELAR_EM_LOTE    = OrderDoConsole::class . '@bulkCancel';
    public const ATIVIDADE_NO_CONSOLE = OrderDoConsole::class . '@updateActivity';

    public const MENSAGEM_CANCELAMENTO = 'Pedido do iFood: o cancelamento é feito no iFood.';
    public const MENSAGEM_APP_ANTIGO   = 'Atualize o app para concluir pedidos do iFood.';

    /** Códigos de atividade de cancelamento. */
    public const ATIVIDADES_DE_CANCELAMENTO = ['canceled', 'cancelled', 'order_canceled'];

    public function handle(Request $request, Closure $next)
    {
        $rota = $request->route();
        $acao = $rota instanceof Route ? ltrim($rota->getActionName(), '\\') : null;

        switch ($acao) {
            case static::CANCELAR_NA_API:
                return $this->ehDoIfood($this->pedidoPeloId($request->route('id'))) ? $this->recusarNaApi($request, static::MENSAGEM_CANCELAMENTO) : $next($request);

            case static::CONCLUIR_NA_API:
                return $this->travaDoAppAntigo($this->pedidoPeloId($request->route('id'))) ? $this->recusarNaApi($request, static::MENSAGEM_APP_ANTIGO) : $next($request);

            case static::ATIVIDADE_NA_API:
                $pedido    = $this->pedidoPeloId($request->route('id'));
                $atividade = $request->array('activity');
                if ($this->cancela($atividade) && $this->ehDoIfood($pedido)) {
                    return $this->recusarNaApi($request, static::MENSAGEM_CANCELAMENTO);
                }
                if ($this->conclui($atividade) && $this->travaDoAppAntigo($pedido)) {
                    return $this->recusarNaApi($request, static::MENSAGEM_APP_ANTIGO);
                }

                return $next($request);

            case static::CANCELAR_NO_CONSOLE:
                return $this->ehDoIfood($this->pedidoPeloId($request->input('order'))) ? $this->recusarNoConsole() : $next($request);

            case static::CANCELAR_EM_LOTE:
                foreach ((array) $request->input('ids', []) as $id) {
                    if ($this->ehDoIfood($this->pedidoPeloId($id))) {
                        return $this->recusarNoConsole('Há pedido do iFood na seleção: o cancelamento dele é feito no iFood. Tire-o da seleção e tente de novo.');
                    }
                }

                return $next($request);

            case static::ATIVIDADE_NO_CONSOLE:
                return $this->cancela($request->array('activity')) && $this->ehDoIfood($this->pedidoPeloId($request->route('id'))) ? $this->recusarNoConsole() : $next($request);
        }

        return $next($request);
    }

    /** O pedido da empresa da sessão pelo uuid, public_id ou internal_id (as rotas aceitam os três), ou null. */
    protected function pedidoPeloId($id): ?Order
    {
        if (!is_string($id) || $id === '') {
            return null;
        }

        return Order::where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id)->orWhere('internal_id', $id))
            ->when(session('company'), fn ($q, $empresa) => $q->where('company_uuid', $empresa))
            ->first();
    }

    protected function ehDoIfood(?Order $pedido): bool
    {
        return $pedido !== null && PedidosIfood::ehDoIfood((string) $pedido->uuid);
    }

    /** Trava ligada, pedido iFood e conclusão ainda não liberada pela rota concluir-ifood. */
    protected function travaDoAppAntigo(?Order $pedido): bool
    {
        if ($pedido === null || !ClienteIfood::exigeAppNovo()) {
            return false;
        }

        $linha = PedidosIfood::doPedido((string) $pedido->uuid);

        return $linha !== null && !$linha->conclusao_liberada_em;
    }

    protected function cancela(array $atividade): bool
    {
        return in_array($atividade['code'] ?? null, static::ATIVIDADES_DE_CANCELAMENTO, true);
    }

    protected function conclui(array $atividade): bool
    {
        return ($atividade['code'] ?? null) === 'completed' || filter_var($atividade['complete'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    protected function recusarNaApi(Request $request, string $mensagem)
    {
        Log::info('[entregas] ifood: ação barrada no pedido do iFood', ['motivo' => $mensagem, 'pedido' => $request->route('id')]);

        return response()->apiError($mensagem, 400);
    }

    protected function recusarNoConsole(string $mensagem = self::MENSAGEM_CANCELAMENTO)
    {
        return response()->json(['errors' => [$mensagem]], 400);
    }
}
```

- [ ] **Step 4: o portal da loja**

Em `api/app/Http/Middleware/RegrasPortalLoja.php`:

1. Troque `use App\Support\Entregas\LojaDoUsuario;` por:

```php
use App\Support\Entregas\Ifood\PedidosIfood;
use App\Support\Entregas\LojaDoUsuario;
```

2. No docblock da classe, troque:

```php
 * - Cancelamento: só antes de um motoboy aceitar, conferido e feito com a trava do pedido (TravaDoPedido), a
 *   mesma do aceite no app; depois do portal, que só grava o status, grava a atividade e o evento do
 *   cancelamento e tira o pedido dos pedidos abertos do app do motoboy.
```

por:

```php
 * - Cancelamento: só antes de um motoboy aceitar, conferido e feito com a trava do pedido (TravaDoPedido), a
 *   mesma do aceite no app; depois do portal, que só grava o status, grava a atividade e o evento do
 *   cancelamento e tira o pedido dos pedidos abertos do app do motoboy. Pedido do iFood nunca: 400 (o cancelamento é
 *   feito no iFood; RegrasDoPedidoIfood barra o mesmo na API v1 e no console).
```

3. No `cancelamento()`, troque:

```php
        // pedido de outra loja (ou inexistente): o próprio portal responde 404
        if (!$pedido) {
            return $next($request);
        }
```

por:

```php
        // pedido de outra loja (ou inexistente): o próprio portal responde 404
        if (!$pedido) {
            return $next($request);
        }

        // pedido do iFood: para nós vale o cancelamento do iFood (CAN), que a loja faz no Gestor de Pedidos
        if (PedidosIfood::ehDoIfood((string) $pedido->uuid)) {
            return $this->erro(400, RegrasDoPedidoIfood::MENSAGEM_CANCELAMENTO);
        }
```

- [ ] **Step 5: registrar o middleware**

Em `api/app/Providers/RouteServiceProvider.php`:

1. Troque `use App\Http\Middleware\BarrarAceiteDePedidoEncerrado;` por:

```php
use App\Http\Middleware\BarrarAceiteDePedidoEncerrado;
use App\Http\Middleware\RegrasDoPedidoIfood;
```

2. Troque:

```php
        $this->app['router']->pushMiddlewareToGroup('fleetbase.api', BarrarAceiteDePedidoEncerrado::class);
```

por:

```php
        // Entregas RestaurantePro: pedido iFood não se cancela do nosso lado (API v1, console) e, com a trava
        // ENTREGAS_IFOOD_EXIGE_APP_NOVO, só se conclui pela rota concluir-ifood do APK novo (ver RegrasDoPedidoIfood). Antes
        // do BarrarAceiteDePedidoEncerrado: o cancelamento recusado nem pega a trava do pedido
        $this->app['router']->pushMiddlewareToGroup('fleetbase.api', RegrasDoPedidoIfood::class);
        $this->app['router']->pushMiddlewareToGroup('fleetbase.protected', RegrasDoPedidoIfood::class);

        $this->app['router']->pushMiddlewareToGroup('fleetbase.api', BarrarAceiteDePedidoEncerrado::class);
```

- [ ] **Step 6: o interruptor no stack**

Em `deploy/docker-stack.yml`, troque:

```yaml
  IFOOD_CLIENT_SECRET: ${IFOOD_CLIENT_SECRET:-}
```

por:

```yaml
  IFOOD_CLIENT_SECRET: ${IFOOD_CLIENT_SECRET:-}
  # trava "Atualize o app" dos pedidos iFood (RegrasDoPedidoIfood): vazio = desligada; 1 só com o APK novo em todos os
  # celulares (CLAUDE.md, "Integração iFood (etapa 3)")
  ENTREGAS_IFOOD_EXIGE_APP_NOVO: ${ENTREGAS_IFOOD_EXIGE_APP_NOVO:-}
```

Em `deploy/stack.env.example`, troque:

```bash
IFOOD_CLIENT_SECRET=
```

por:

```bash
IFOOD_CLIENT_SECRET=

# Trava "Atualize o app" dos pedidos iFood (RegrasDoPedidoIfood): com 1, a conclusão comum de pedido iFood pelo app
# (atividade "completed") só passa depois da rota concluir-ifood do APK novo (código de entrega do cliente). Vazio =
# desligada. Ligue SÓ depois do APK novo instalado em todos os celulares: o APK antigo não consegue concluir pedido iFood
# com ela ligada. Mudou? Update the stack no Portainer ("Re-pull image" desligado).
ENTREGAS_IFOOD_EXIGE_APP_NOVO=
```

(Não abra o `deploy/stack.env`: ele só existe no PC do Edgard.)

- [ ] **Step 7: rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-regras.php && PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Http/Middleware/RegrasDoPedidoIfood.php api/app/Http/Middleware/RegrasPortalLoja.php api/app/Providers/RouteServiceProvider.php`
Expected: `FALHAS: 0` e `OK` nos três.

- [ ] **Step 8: commit**

```bash
git rev-parse --show-toplevel
git add api/app/Http/Middleware/RegrasDoPedidoIfood.php api/app/Http/Middleware/RegrasPortalLoja.php api/app/Providers/RouteServiceProvider.php deploy/docker-stack.yml deploy/stack.env.example scripts/teste-php/ifood-regras.php
git commit -m "iFood etapa 3: cancelamento do nosso lado proibido e trava \"Atualize o app\" (desligada por padrão)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 10: rotas do motoboy (dados iFood, conclusão e código de entrega)

**Files:**
- Create: `api/app/Support/Entregas/Ifood/DadosIfoodDoMotoboy.php`
- Create: `api/app/Support/Entregas/Ifood/ConclusaoIfood.php`
- Modify: `api/app/Http/Controllers/Entregas/MotoboyController.php`
- Modify: `api/app/Providers/RouteServiceProvider.php` (rotas e limitador)
- Test: `scripts/teste-php/ifood-conclusao.php`

- [ ] **Step 1: o teste**

Crie `scripts/teste-php/ifood-conclusao.php`:

```php
<?php

// Integração iFood (etapa 3): rotas do motoboy (dados do pedido, conclusão e código de entrega: MotoboyController,
// DadosIfoodDoMotoboy, ConclusaoIfood) e o painel do console (IfoodPedidosController).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-conclusao.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Http\Controllers\Entregas\IfoodPedidosController;
use App\Http\Controllers\Entregas\MotoboyController;
use App\Support\Entregas\Ifood\AcoesIfood;
use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\ConclusaoIfood;
use App\Support\Entregas\Ifood\DadosIfoodDoMotoboy;
use App\Support\Entregas\Ifood\VinculosIfood;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Http\Request;
use Teste\Banco;
use Teste\Http;
use Teste\Trava;

/** Loja A vinculada, o motoboy driver-1 (usuário user-1) e o Order order-1 já a caminho do cliente, com a linha iFood. */
function pedidoIfood(array $order = [], array $linha = []): Order
{
    reiniciarFleetbase();
    reiniciarIfood();
    vinculoDaLojaA();
    session(['company' => 'empresa-1', 'user' => 'user-1']);
    Driver::$todos[] = new Driver(['uuid' => 'driver-1', 'public_id' => 'driver_1', 'company_uuid' => 'empresa-1', 'user_uuid' => 'user-1', 'name' => 'Motoboy Ficticio', 'phone' => '+5516999990000']);
    $pedido          = new Order($order + ['uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1', 'status' => 'enroute', 'started' => true, 'driver_assigned_uuid' => 'driver-1']);
    Order::$todos[]  = $pedido;
    Banco::inserir('entregas_ifood_pedidos', $linha + [
        'company_uuid' => 'empresa-1', 'order_uuid' => 'order-1', 'pedido_ifood_id' => 'pedido-real-1', 'numero' => '4821', 'merchant_id' => 'merchant-1',
        'ultima_acao' => 'dispatch', 'motoboy_no_ifood' => 'driver-1', 'exige_codigo' => true, 'cobrar_centavos' => 5890, 'forma_pagamento' => 'CASH',
        'troco_para_centavos' => 10000, 'observacoes' => 'Sem cebola', 'complemento' => 'Apto 501', 'referencia' => 'Perto da praça',
        'telefone_0800' => '08007000000', 'localizador' => '12345678', 'telefone_expira_em' => '2026-10-05 18:00:00',
        'created_at' => '2026-10-05 14:50:00', 'updated_at' => '2026-10-05 14:50:00',
    ], false);

    return $pedido;
}

function linha(): object
{
    return (new Teste\Consulta('entregas_ifood_pedidos'))->where('order_uuid', 'order-1')->first();
}

function conclusao(): ConclusaoIfood
{
    return new ConclusaoIfood(new AcoesIfood(new VinculosIfood(new ClienteIfood()), new ClienteIfood()), new VinculosIfood(new ClienteIfood()), new ClienteIfood());
}

function doMotoboy(array $dados = []): Request
{
    $request        = new Request($dados);
    $request->token = '12|token-do-motoboy';

    return $request;
}

echo '== Dados do pedido iFood para o app' . PHP_EOL;
pedidoIfood();
$dados = (new MotoboyController())->ifood(doMotoboy(), 'order_1')->dados;
confere($dados['ifood'] === true && $dados['numero'] === '4821' && $dados['exige_codigo'] === true, 'número e código exigido');
confere($dados['cobranca'] === ['centavos' => 5890, 'forma' => 'dinheiro', 'troco_para_centavos' => 10000, 'texto' => 'Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100'], 'cobrança com o texto');
confere($dados['observacoes'] === 'Sem cebola' && $dados['complemento'] === 'Apto 501' && $dados['referencia'] === 'Perto da praça', 'observações, complemento e referência');
confere($dados['telefone'] === ['numero' => '08007000000', 'localizador' => '12345678', 'expira_em' => '2026-10-05T18:00:00-03:00'], 'motoboy do pedido: 0800 e localizador');
$aberto = new Order(['uuid' => 'order-1', 'status' => 'dispatched', 'driver_assigned_uuid' => null, 'adhoc' => true]);
confere(DadosIfoodDoMotoboy::resposta(linha(), $aberto, 'driver-1', '2026-10-05 15:00:00')['telefone'] === null, 'pedido ainda aberto: sem o 0800');
confere(DadosIfoodDoMotoboy::resposta(linha(), Order::$todos[0], 'driver-1', '2026-10-05 18:00:01')['telefone'] === null, 'localizador expirado: sem o 0800');
confere(DadosIfoodDoMotoboy::resposta(linha(), new Order(['status' => 'completed', 'driver_assigned_uuid' => 'driver-1']), 'driver-1', '2026-10-05 15:00:00')['telefone'] === null, 'pedido encerrado: sem o 0800');
confere(DadosIfoodDoMotoboy::resposta(null, Order::$todos[0], 'driver-1', '2026-10-05 15:00:00') === ['ifood' => false], 'pedido que não é do iFood: ifood falso');
$semMotoboy        = doMotoboy();
$semMotoboy->token = 'flb_live_chave';
confere((new MotoboyController())->ifood($semMotoboy, 'order_1')->status === 403, 'chave de API: 403');
Order::$todos[0]->driver_assigned_uuid = 'outro-motoboy';
confere((new MotoboyController())->ifood(doMotoboy(), 'order_1')->status === 404, 'pedido de outro motoboy: 404');

echo '== Concluir: avisa a chegada e pede o código' . PHP_EOL;
pedidoIfood();
Http::responder(202);
$resposta = (new MotoboyController())->concluirIfood(doMotoboy(), 'order_1', conclusao());
confere(Http::urls() === ['POST /logistics/v1.0/orders/pedido-real-1/arrivedAtDestination'] && linha()->ultima_acao === 'arrivedAtDestination', 'arrivedAtDestination enviado na hora');
confere($resposta->status === 200 && $resposta->dados === ['resultado' => 'precisa_codigo'] && linha()->conclusao_liberada_em === null, 'exige código: precisa_codigo, ainda não liberado');

echo '== Concluir: sem código exigido, libera' . PHP_EOL;
pedidoIfood(['status' => 'started'], ['ultima_acao' => 'goingToOrigin', 'exige_codigo' => false]);
Http::responder(202);
Http::responder(202);
Http::responder(202);
$resposta = (new MotoboyController())->concluirIfood(doMotoboy(), 'order_1', conclusao());
confere(count(Http::$chamadas) === 3 && linha()->ultima_acao === 'arrivedAtDestination', 'completa arrivedAtOrigin, dispatch e arrivedAtDestination');
confere($resposta->dados === ['resultado' => 'pode_concluir'] && linha()->conclusao_liberada_em === '2026-10-05 15:00:00' && !linha()->conclusao_sem_codigo, 'libera a conclusão comum');

echo '== Concluir: pedido de teste segue o exige_codigo (o iFood pediu o código no teste)' . PHP_EOL;
pedidoIfood([], ['teste' => true]);
Http::responder(202);
confere((new MotoboyController())->concluirIfood(doMotoboy(), 'order_1', conclusao())->dados === ['resultado' => 'precisa_codigo'], 'teste com DDCR: precisa_codigo');

echo '== Concluir: iFood fora do ar e recusa' . PHP_EOL;
pedidoIfood();
Http::responder(503, 'indisponível');
$resposta = (new MotoboyController())->concluirIfood(doMotoboy(), 'order_1', conclusao());
confere($resposta->status === 503 && $resposta->dados['resultado'] === 'tente_de_novo' && str_contains($resposta->dados['errors'][0], 'Tente de novo'), '503: tente de novo');
pedidoIfood();
Http::responder(409, ['description' => 'Invalid state']);
$resposta = (new MotoboyController())->concluirIfood(doMotoboy(), 'order_1', conclusao());
confere($resposta->dados === ['resultado' => 'precisa_codigo'] && linha()->recusa_acao === 'arrivedAtDestination', '409 na chegada: não prende o motoboy (segue para o código), com a recusa registrada');

echo '== Concluir: só o pedido iFood dele, iniciado e aberto' . PHP_EOL;
pedidoIfood(['started' => false, 'status' => 'dispatched']);
confere((new MotoboyController())->concluirIfood(doMotoboy(), 'order_1', conclusao())->status === 409, 'não iniciado: 409');
pedidoIfood([], ['cancelado_pelo_ifood_em' => '2026-10-05 14:59:00']);
confere((new MotoboyController())->concluirIfood(doMotoboy(), 'order_1', conclusao())->status === 409, 'cancelado pelo iFood: 409');
pedidoIfood();
Banco::$tabelas['entregas_ifood_pedidos'] = [];
confere((new MotoboyController())->concluirIfood(doMotoboy(), 'order_1', conclusao())->status === 422, 'pedido que não é do iFood: 422');
pedidoIfood(['driver_assigned_uuid' => 'outro-motoboy']);
confere((new MotoboyController())->concluirIfood(doMotoboy(), 'order_1', conclusao())->status === 404, 'pedido de outro motoboy: 404');

echo '== Código de entrega' . PHP_EOL;
pedidoIfood([], ['ultima_acao' => 'arrivedAtDestination']);
Http::responder(200, ['success' => true]);
$resposta = (new MotoboyController())->codigoIfood(doMotoboy(['codigo' => ' 1234 ']), 'order_1', conclusao());
confere(Http::urls() === ['POST /logistics/v1.0/orders/pedido-real-1/verifyDeliveryCode'] && Http::$chamadas[0]['dados'] === ['code' => '1234'], 'verifyDeliveryCode com o código');
confere($resposta->dados === ['resultado' => 'pode_concluir'] && linha()->conclusao_liberada_em !== null && !linha()->conclusao_sem_codigo, 'certo: libera');
confere(logou('código de entrega conferido', 'info') && logsSem(['1234']), 'log sem o código');
$resposta = (new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '1234']), 'order_1', conclusao());
confere($resposta->dados === ['resultado' => 'pode_concluir'] && count(Http::$chamadas) === 1, 'de novo, já liberado: não chama o iFood (o código já conferido daria erro)');

pedidoIfood([], ['ultima_acao' => 'arrivedAtDestination']);
Http::responder(400, ['errorType' => 'NOT_FOUND', 'description' => 'Confirmation code is invalid', 'code' => '400']);
$resposta = (new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '9999']), 'order_1', conclusao());
confere($resposta->status === 422 && $resposta->dados['resultado'] === 'codigo_incorreto' && str_contains($resposta->dados['errors'][0], 'Código incorreto') && linha()->conclusao_liberada_em === null, '400 "Confirmation code is invalid" (sonda): código incorreto');
Http::responder(422, ['message' => 'Verification failed']);
confere((new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '9999']), 'order_1', conclusao())->status === 422, '422 (documentação): código incorreto');
Http::responder(200, ['success' => false]);
confere((new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '9999']), 'order_1', conclusao())->dados['resultado'] === 'codigo_incorreto', '200 com success falso: código incorreto');
Http::responder(412, ['message' => 'Order not eligible']);
confere((new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '9999']), 'order_1', conclusao())->status === 503, '412 (outro erro): tente de novo');
Http::responder(500, 'erro');
confere((new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '9999']), 'order_1', conclusao())->status === 503, '500: tente de novo');
Http::falharConexao();
confere((new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '9999']), 'order_1', conclusao())->status === 503, 'rede fora: tente de novo');
confere((new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '12a4']), 'order_1', conclusao())->status === 422 && count(Http::$respostas) === 0, 'código com letra: 422 sem chamar o iFood');

pedidoIfood();
Http::responder(202);
Http::responder(200, ['success' => true]);
(new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '1234']), 'order_1', conclusao());
confere(Http::urls() === ['POST /logistics/v1.0/orders/pedido-real-1/arrivedAtDestination', 'POST /logistics/v1.0/orders/pedido-real-1/verifyDeliveryCode'], 'código antes da chegada: avisa a chegada antes de conferir');

pedidoIfood([], ['ultima_acao' => 'arrivedAtDestination']);
Trava::$ocupadas['entregas:ifood-acao:order-1'] = true;
confere((new MotoboyController())->codigoIfood(doMotoboy(['codigo' => '1234']), 'order_1', conclusao())->status === 503, 'trava das ações ocupada: tente de novo');

resumo();
```

- [ ] **Step 2: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-conclusao.php`
Expected: erro fatal (classe `ConclusaoIfood` inexistente).

- [ ] **Step 3: `DadosIfoodDoMotoboy`**

Crie `api/app/Support/Entregas/Ifood/DadosIfoodDoMotoboy.php`:

```php
<?php

namespace App\Support\Entregas\Ifood;

use App\Support\Entregas\StatusDoPedido;
use Illuminate\Support\Carbon;

/**
 * Entregas RestaurantePro: o que o app do motoboy recebe de um pedido iFood (GET v1/entregas/motoboy/pedidos/{id}/ifood:
 * card de aceitar e detalhes). Função pura sobre a linha da entregas_ifood_pedidos e o Order.
 *
 * - Pedido que não é do iFood: {"ifood": false} (o app chama para todos e só mostra o bloco quando é).
 * - Número, cobrança (centavos, forma em pt-BR, troco e o texto da CobrancaIfood), observações, complemento,
 *   referência, se exige código, se a conclusão já foi liberada e se o iFood cancelou (com o pago mesmo cancelado).
 * - 0800 e localizador do cliente só para o motoboy do pedido, com o pedido em andamento e antes da expiração do
 *   localizador (telefone_expira_em, texto no fuso do app; sem data = vale). No pedido ainda aberto, não vão.
 */
final class DadosIfoodDoMotoboy
{
    /** @param string $agora 'Y-m-d H:i:s' no fuso do app */
    public static function resposta(?object $linha, object $pedido, string $motoboyUuid, string $agora): array
    {
        if (!$linha) {
            return ['ifood' => false];
        }

        $doMotoboy = (string) $pedido->driver_assigned_uuid === $motoboyUuid;
        $encerrado = in_array($pedido->status, StatusDoPedido::ENCERRADOS, true);
        $expira    = $linha->telefone_expira_em ? (string) $linha->telefone_expira_em : null;
        $telefone  = null;
        if ($doMotoboy && !$encerrado && $linha->telefone_0800 && ($expira === null || substr($expira, 0, 19) > $agora)) {
            $telefone = [
                'numero'      => (string) $linha->telefone_0800,
                'localizador' => $linha->localizador !== null ? (string) $linha->localizador : null,
                'expira_em'   => $expira ? Carbon::parse(substr($expira, 0, 19), date_default_timezone_get())->toIso8601String() : null,
            ];
        }

        $centavos = (int) $linha->cobrar_centavos;
        $troco    = $linha->troco_para_centavos !== null ? (int) $linha->troco_para_centavos : null;

        return [
            'ifood'                => true,
            'numero'               => $linha->numero !== null ? (string) $linha->numero : null,
            'teste'                => (bool) $linha->teste,
            'cobranca'             => [
                'centavos'            => $centavos,
                'forma'               => CobrancaIfood::forma($linha->forma_pagamento),
                'troco_para_centavos' => $troco,
                'texto'               => CobrancaIfood::texto($centavos, $linha->forma_pagamento, $troco),
            ],
            'observacoes'          => $linha->observacoes,
            'complemento'          => $linha->complemento,
            'referencia'           => $linha->referencia,
            'exige_codigo'         => (bool) $linha->exige_codigo,
            'conclusao_liberada'   => (bool) $linha->conclusao_liberada_em,
            'cancelado_pelo_ifood' => (bool) $linha->cancelado_pelo_ifood_em,
            'pago_mesmo_cancelado' => (bool) $linha->pago_mesmo_cancelado,
            'telefone'             => $telefone,
        ];
    }
}
```

- [ ] **Step 4: `ConclusaoIfood`**

Crie `api/app/Support/Entregas/Ifood/ConclusaoIfood.php`:

```php
<?php

namespace App\Support\Entregas\Ifood;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: a conclusão de um pedido iFood pelo app do motoboy (spec, seção 4), fora da fila.
 *
 * 1. concluir() (POST v1/entregas/motoboy/pedidos/{id}/concluir-ifood): garante no iFood tudo até o
 *    arrivedAtDestination (AcoesIfood::sincronizar, na hora). Uma recusa (4xx) dessas ações não prende o motoboy: a
 *    central já foi avisada e a conclusão segue. Com `exige_codigo` (DDCR, que chega logo depois da confirmação),
 *    responde PRECISA_CODIGO; senão libera a conclusão.
 * 2. conferirCodigo() (POST .../codigo-ifood): confere o código com o iFood (verifyDeliveryCode) na hora. Certo →
 *    libera; HTTP 400 "Confirmation code is invalid" (sonda) ou 422 (documentação), ou 2xx com success = false →
 *    CODIGO_INCORRETO; qualquer outra falha → TENTE_DE_NOVO.
 * 3. Liberado (conclusao_liberada_em), o app conclui pelo fluxo de sempre (atividade "completed" da API v1, com a prova
 *    de entrega se a central exigir): a trava "Atualize o app" (RegrasDoPedidoIfood) deixa passar. Assim a conclusão
 *    continua sendo a do Fleet-Ops (atividade, OrderCompleted, prova), sem cópia dela aqui. Uma nova tentativa depois
 *    de liberado não chama o iFood de novo (o código já conferido daria erro).
 *
 * Pedido de teste: segue o `exige_codigo` como qualquer outro (o Gestor de Pedidos do iFood pediu o código ao concluir
 * um pedido de teste). Sem como obter o código, a central libera no console (liberarSemCodigo): fica registrado
 * (conclusao_sem_codigo e o log) e o iFood conclui sozinho 4 h depois.
 */
class ConclusaoIfood
{
    public const PODE_CONCLUIR    = 'pode_concluir';
    public const PRECISA_CODIGO   = 'precisa_codigo';
    public const CODIGO_INCORRETO = 'codigo_incorreto';
    public const TENTE_DE_NOVO    = 'tente_de_novo';

    public function __construct(protected AcoesIfood $acoes, protected VinculosIfood $vinculos, protected ClienteIfood $cliente) {}

    public function concluir(object $linha, Order $pedido): string
    {
        if ($linha->conclusao_liberada_em) {
            return static::PODE_CONCLUIR;
        }

        try {
            $this->acoes->sincronizar((string) $pedido->uuid, SequenciaIfood::CHEGOU_NO_CLIENTE);
        } catch (LockTimeoutException | ErroIfood $e) {
            Log::info('[entregas] ifood: conclusão sem resposta do iFood; o motoboy tenta de novo', ['pedido' => $pedido->public_id, 'status' => $e instanceof ErroIfood ? $e->status : null]);

            return static::TENTE_DE_NOVO;
        }

        $linha = PedidosIfood::doPedido((string) $pedido->uuid) ?? $linha;
        if ($linha->exige_codigo) {
            return static::PRECISA_CODIGO;
        }

        $this->liberar($linha, false);

        return static::PODE_CONCLUIR;
    }

    public function conferirCodigo(object $linha, Order $pedido, #[\SensitiveParameter] string $codigo): string
    {
        $chegada = $this->concluir($linha, $pedido);
        if ($chegada !== static::PRECISA_CODIGO) {
            // já liberado, não exigia o código ou o iFood não respondeu
            return $chegada;
        }

        $linha   = PedidosIfood::doPedido((string) $pedido->uuid) ?? $linha;
        $vinculo = $this->vinculos->porMerchant((string) $linha->merchant_id);
        if (!$vinculo) {
            Log::warning('[entregas] ifood: código de entrega sem vínculo ativo da loja', ['pedido' => $pedido->public_id]);

            return static::TENTE_DE_NOVO;
        }

        try {
            $resposta = $this->acoes->comATrava((string) $pedido->uuid, fn () => $this->vinculos->comToken(
                $vinculo,
                fn (string $token) => $this->cliente->verificarCodigo($token, (string) $linha->pedido_ifood_id, $codigo)
            ));
        } catch (LockTimeoutException | VinculoPerdido $e) {
            return static::TENTE_DE_NOVO;
        } catch (ErroIfood $e) {
            if ($e->status === 400 || $e->status === 422) {
                Log::info('[entregas] ifood: código de entrega incorreto', ['pedido' => $pedido->public_id, 'status' => $e->status]);

                return static::CODIGO_INCORRETO;
            }
            Log::warning('[entregas] ifood: falha ao conferir o código de entrega', ['pedido' => $pedido->public_id, 'status' => $e->status, 'corpo' => mb_substr($e->corpo, 0, 300)]);

            return static::TENTE_DE_NOVO;
        }

        if (($resposta['success'] ?? null) === false) {
            Log::info('[entregas] ifood: código de entrega incorreto', ['pedido' => $pedido->public_id, 'status' => 200]);

            return static::CODIGO_INCORRETO;
        }

        $this->liberar($linha, false);
        Log::info('[entregas] ifood: código de entrega conferido', ['pedido' => $pedido->public_id, 'numero' => $linha->numero]);

        return static::PODE_CONCLUIR;
    }

    /** A central libera a conclusão sem o código (painel iFood do console): registrado no banco e no log. */
    public function liberarSemCodigo(object $linha, Order $pedido, ?string $usuarioUuid): void
    {
        $this->liberar($linha, true);
        Log::warning('[entregas] ifood: conclusão sem código liberada pela central', ['pedido' => $pedido->public_id, 'numero' => $linha->numero, 'usuario' => $usuarioUuid]);
    }

    protected function liberar(object $linha, bool $semCodigo): void
    {
        PedidosIfood::atualizar($linha, [
            'conclusao_liberada_em' => $linha->conclusao_liberada_em ?: now()->toDateTimeString(),
            'conclusao_sem_codigo'  => $semCodigo || (bool) $linha->conclusao_sem_codigo,
        ]);
    }
}
```

- [ ] **Step 5: o controller**

Em `api/app/Http/Controllers/Entregas/MotoboyController.php`:

1. Troque `use App\Support\Entregas\GanhosDoMotoboy;` por:

```php
use App\Support\Entregas\GanhosDoMotoboy;
use App\Support\Entregas\Ifood\ConclusaoIfood;
use App\Support\Entregas\Ifood\DadosIfoodDoMotoboy;
use App\Support\Entregas\Ifood\PedidosIfood;
```

2. Troque `use App\Support\Entregas\SituacaoDoMotoboy;` por:

```php
use App\Support\Entregas\SituacaoDoMotoboy;
use App\Support\Entregas\StatusDoPedido;
```

3. No docblock da classe, troque:

```php
 * - chatComACentral: a conversa dele com a central (APKs anteriores ao chatDoPedido).
```

por:

```php
 * - chatComACentral: a conversa dele com a central (APKs anteriores ao chatDoPedido).
 * - ifood, concluirIfood e codigoIfood: o pedido iFood no app (DadosIfoodDoMotoboy) e a conclusão com o código do
 *   cliente (ConclusaoIfood): o servidor avisa a chegada ao iFood, confere o código na hora e libera a conclusão comum.
```

4. Troque:

```php
    /** Pedido da empresa da sessão (pelo public_id ou uuid) que o motoboy pode ver: dele ou aberto. */
```

por:

```php
    public function ifood(Request $request, string $id)
    {
        $motoboy = MotoboyDaSessao::motoboy($request);
        if (!$motoboy) {
            return $this->soParaMotoboy();
        }

        $pedido = $this->pedidoQuePodeVer($id, $motoboy);
        if (!$pedido) {
            return response()->json(['errors' => ['Pedido não encontrado.']], 404);
        }

        return response()->json(DadosIfoodDoMotoboy::resposta(PedidosIfood::doPedido((string) $pedido->uuid), $pedido, (string) $motoboy->uuid, now()->toDateTimeString()));
    }

    public function concluirIfood(Request $request, string $id, ConclusaoIfood $conclusao)
    {
        [$pedido, $linha, $erro] = $this->pedidoIfoodEmEntrega($request, $id);
        if ($erro) {
            return $erro;
        }

        return $this->respostaDaConclusao($conclusao->concluir($linha, $pedido));
    }

    public function codigoIfood(Request $request, string $id, ConclusaoIfood $conclusao)
    {
        [$pedido, $linha, $erro] = $this->pedidoIfoodEmEntrega($request, $id);
        if ($erro) {
            return $erro;
        }

        // o código que o cliente vê no app do iFood: só números (a documentação fala em 4 a 6 dígitos)
        $codigo = trim((string) $request->input('codigo'));
        if (!preg_match('/^\d{3,10}$/', $codigo)) {
            return response()->json(['errors' => ['Digite só os números do código que o cliente recebeu.']], 422);
        }

        return $this->respostaDaConclusao($conclusao->conferirCodigo($linha, $pedido, $codigo));
    }

    /**
     * O pedido iFood do motoboy em entrega (dele, iniciado, não encerrado nem cancelado pelo iFood): [Order, linha, null],
     * ou [null, null, resposta de erro].
     */
    protected function pedidoIfoodEmEntrega(Request $request, string $id): array
    {
        $motoboy = MotoboyDaSessao::motoboy($request);
        if (!$motoboy) {
            return [null, null, $this->soParaMotoboy()];
        }

        // nenhum model registra o CompanyScope nesta versão: a empresa é filtrada aqui
        $pedido = Order::where('company_uuid', session('company'))
            ->where(fn ($query) => $query->where('public_id', $id)->orWhere('uuid', $id))
            ->first();
        if (!$pedido || (string) $pedido->driver_assigned_uuid !== (string) $motoboy->uuid) {
            return [null, null, response()->json(['errors' => ['Pedido não encontrado.']], 404)];
        }

        $linha = PedidosIfood::doPedido((string) $pedido->uuid);
        if (!$linha) {
            return [null, null, response()->json(['errors' => ['Este pedido não é do iFood.']], 422)];
        }
        if ($linha->cancelado_pelo_ifood_em || in_array($pedido->status, StatusDoPedido::ENCERRADOS, true)) {
            return [null, null, response()->json(['errors' => ['Este pedido já foi encerrado.']], 409)];
        }
        if (!$pedido->started) {
            return [null, null, response()->json(['errors' => ['Inicie o pedido antes de concluir.']], 409)];
        }

        return [$pedido, $linha, null];
    }

    /** O resultado da ConclusaoIfood como resposta: 200 com o resultado, 422 código incorreto, 503 tente de novo. */
    protected function respostaDaConclusao(string $resultado)
    {
        return match ($resultado) {
            ConclusaoIfood::PODE_CONCLUIR, ConclusaoIfood::PRECISA_CODIGO => response()->json(['resultado' => $resultado]),
            ConclusaoIfood::CODIGO_INCORRETO => response()->json(['resultado' => $resultado, 'errors' => ['Código incorreto. Peça o código de novo ao cliente.']], 422),
            default => response()->json(['resultado' => ConclusaoIfood::TENTE_DE_NOVO, 'errors' => ['Não consegui falar com o iFood agora. Tente de novo em alguns segundos.']], 503),
        };
    }

    /** Pedido da empresa da sessão (pelo public_id ou uuid) que o motoboy pode ver: dele ou aberto. */
```

- [ ] **Step 6: rotas e limitador**

Em `api/app/Providers/RouteServiceProvider.php`:

1. Troque `        RateLimiter::for('entregas-ifood-vinculo',` por:

```php
        // Entregas RestaurantePro: código de entrega do iFood digitado pelo motoboy, até 10 tentativas por minuto por motoboy
        // (contra tentar todos os códigos), além do entregas-motoboy
        RateLimiter::for('entregas-ifood-codigo', fn (Request $request) => Limit::perMinute(10)->by('entregas-ifood-codigo:' . (session('user') ?: $request->ip())));

        RateLimiter::for('entregas-ifood-vinculo',
```

2. Troque:

```php
                        Route::post('pedidos/{id}/chat', [MotoboyController::class, 'chatDoPedido']);
```

por:

```php
                        Route::post('pedidos/{id}/chat', [MotoboyController::class, 'chatDoPedido']);
                        // pedido iFood: dados (card e detalhes), conclusão e código de entrega do cliente
                        Route::get('pedidos/{id}/ifood', [MotoboyController::class, 'ifood']);
                        Route::post('pedidos/{id}/concluir-ifood', [MotoboyController::class, 'concluirIfood']);
                        Route::post('pedidos/{id}/codigo-ifood', [MotoboyController::class, 'codigoIfood'])->middleware('throttle:entregas-ifood-codigo');
```

- [ ] **Step 7: rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-conclusao.php && PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Http/Controllers/Entregas/MotoboyController.php api/app/Providers/RouteServiceProvider.php`
Expected: `FALHAS: 0` e `OK`.

- [ ] **Step 8: commit**

```bash
git rev-parse --show-toplevel
git add api/app/Support/Entregas/Ifood/DadosIfoodDoMotoboy.php api/app/Support/Entregas/Ifood/ConclusaoIfood.php api/app/Http/Controllers/Entregas/MotoboyController.php api/app/Providers/RouteServiceProvider.php scripts/teste-php/ifood-conclusao.php
git commit -m "iFood etapa 3: rotas do motoboy (dados iFood, concluir-ifood e codigo-ifood com a conferência na hora)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 11: painel iFood do console e "Liberar sem código"

**Files:**
- Create: `api/app/Http/Controllers/Entregas/IfoodPedidosController.php`
- Modify: `api/app/Providers/RouteServiceProvider.php`
- Test: `scripts/teste-php/ifood-conclusao.php`

- [ ] **Step 1: os testes**

Em `scripts/teste-php/ifood-conclusao.php`, acrescente logo antes da última linha (`resumo();`):

```php
echo '== Painel do console e a liberação sem código' . PHP_EOL;
$admin = new class {
    public function isNotAdmin() { return false; }
};
$naoAdmin = new class {
    public function isNotAdmin() { return true; }
};
pedidoIfood([], ['recusa_acao' => 'arrivedAtDestination', 'recusa_status' => 409, 'recusa_em' => '2026-10-05 14:59:00']);
\Fleetbase\Support\Auth::$usuario = $admin;
$painel                           = (new IfoodPedidosController())->painel(new Request(), 'order_1')->dados;
confere($painel['ifood'] === true && $painel['numero'] === '4821' && $painel['ultima_acao'] === 'dispatch', 'número e última ação');
confere($painel['recusa'] === ['acao' => 'arrivedAtDestination', 'status' => 409, 'em' => '2026-10-05T14:59:00-03:00'], 'a última recusa');
confere($painel['exige_codigo'] === true && $painel['conclusao_liberada_em'] === null && $painel['conclusao_sem_codigo'] === false, 'código exigido, ainda não liberado');
confere(!array_key_exists('telefone_0800', $painel) && !str_contains(json_encode($painel), '0800700'), 'sem o 0800 do cliente');
$resposta = (new IfoodPedidosController())->liberarSemCodigo(new Request(), 'order_1', conclusao());
confere($resposta->dados['conclusao_sem_codigo'] === true && $resposta->dados['conclusao_liberada_em'] === '2026-10-05T15:00:00-03:00', 'liberar sem código: liberado e registrado');
confere(logou('conclusão sem código liberada pela central', 'warning'), 'com log (e o usuário)');
\Fleetbase\Support\Auth::$usuario = $naoAdmin;
confere((new IfoodPedidosController())->painel(new Request(), 'order_1')->status === 403, 'não admin: 403');
\Fleetbase\Support\Auth::$usuario = $admin;
Banco::$tabelas['entregas_ifood_pedidos'] = [];
confere((new IfoodPedidosController())->painel(new Request(), 'order_1')->dados === ['ifood' => false], 'pedido que não é do iFood: ifood falso');
```

(A importação do `IfoodPedidosController` já está no topo do arquivo desde a Task 10.)

- [ ] **Step 2: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-conclusao.php`
Expected: erro fatal `Class "App\Http\Controllers\Entregas\IfoodPedidosController" not found`.

- [ ] **Step 3: o controller**

Crie `api/app/Http/Controllers/Entregas/IfoodPedidosController.php`:

```php
<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\Ifood\ConclusaoIfood;
use App\Support\Entregas\Ifood\PedidosIfood;
use App\Support\Entregas\StatusDoPedido;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Entregas RestaurantePro: o painel iFood no detalhe do pedido do console (só administradores; etapa 4 desenha a tela).
 * - painel (GET int/v1/entregas/pedidos/{id}/ifood): número, última ação aceita pelo iFood, a última recusa, cobrança,
 *   observações, código exigido, conclusão liberada ou sem código, cancelamento pelo iFood. Pedido que não é do iFood:
 *   {"ifood": false}. As ações vão pelo código (assignDriver…): o console traduz (fleet-ops.ui.ifood.acao.*).
 *   Sem o 0800 do cliente (só o motoboy do pedido o recebe, no app).
 * - liberarSemCodigo (POST .../ifood/liberar-sem-codigo): a saída da central quando o motoboy não consegue o código
 *   do cliente (inclusive no pedido de teste, sem app do cliente): libera a conclusão comum no app, registrada no banco
 *   (conclusao_sem_codigo) e no log. O iFood fica com a confirmação pendente e conclui sozinho 4 h depois.
 */
class IfoodPedidosController extends Controller
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

        return response()->json(static::resposta(PedidosIfood::doPedido((string) $pedido->uuid)));
    }

    public function liberarSemCodigo(Request $request, string $id, ConclusaoIfood $conclusao)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }

        $pedido = $this->pedido($id);
        $linha  = $pedido ? PedidosIfood::doPedido((string) $pedido->uuid) : null;
        if (!$linha) {
            return response()->json(['errors' => ['Pedido do iFood não encontrado.']], 404);
        }
        if ($linha->cancelado_pelo_ifood_em || in_array($pedido->status, StatusDoPedido::ENCERRADOS, true)) {
            return response()->json(['errors' => ['Este pedido já foi encerrado.']], 409);
        }

        $conclusao->liberarSemCodigo($linha, $pedido, session('user'));

        return response()->json(static::resposta(PedidosIfood::doPedido((string) $pedido->uuid)));
    }

    /** O que o painel mostra (função pura sobre a linha da entregas_ifood_pedidos). */
    public static function resposta(?object $linha): array
    {
        if (!$linha) {
            return ['ifood' => false];
        }

        $iso = fn ($data) => $data ? Carbon::parse(substr((string) $data, 0, 19), date_default_timezone_get())->toIso8601String() : null;

        return [
            'ifood'                   => true,
            'numero'                  => $linha->numero !== null ? (string) $linha->numero : null,
            'teste'                   => (bool) $linha->teste,
            'agendado'                => (bool) $linha->agendado,
            'ultima_acao'             => $linha->ultima_acao,
            'recusa'                  => $linha->recusa_acao ? [
                'acao'   => $linha->recusa_acao,
                'status' => $linha->recusa_status !== null ? (int) $linha->recusa_status : 0,
                'em'     => $iso($linha->recusa_em),
            ] : null,
            'cobrar_centavos'         => (int) $linha->cobrar_centavos,
            'forma_pagamento'         => $linha->forma_pagamento,
            'troco_para_centavos'     => $linha->troco_para_centavos !== null ? (int) $linha->troco_para_centavos : null,
            'observacoes'             => $linha->observacoes,
            'complemento'             => $linha->complemento,
            'referencia'              => $linha->referencia,
            'exige_codigo'            => (bool) $linha->exige_codigo,
            'conclusao_liberada_em'   => $iso($linha->conclusao_liberada_em),
            'conclusao_sem_codigo'    => (bool) $linha->conclusao_sem_codigo,
            'cancelado_pelo_ifood_em' => $iso($linha->cancelado_pelo_ifood_em),
            'pago_mesmo_cancelado'    => (bool) $linha->pago_mesmo_cancelado,
        ];
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
            return response()->json(['errors' => ['Somente administradores podem ver os dados do iFood do pedido.']], 403);
        }

        return null;
    }
}
```

- [ ] **Step 4: as rotas**

Em `api/app/Providers/RouteServiceProvider.php`:

1. Troque `use App\Http\Controllers\Entregas\IfoodLojasController;` por:

```php
use App\Http\Controllers\Entregas\IfoodLojasController;
use App\Http\Controllers\Entregas\IfoodPedidosController;
```

2. Troque:

```php
                        Route::delete('lojas/{id}/ifood', [IfoodLojasController::class, 'desvincular']);
```

por:

```php
                        Route::delete('lojas/{id}/ifood', [IfoodLojasController::class, 'desvincular']);
                        // painel iFood no detalhe do pedido e a liberação da conclusão sem o código do cliente
                        Route::get('pedidos/{id}/ifood', [IfoodPedidosController::class, 'painel']);
                        Route::post('pedidos/{id}/ifood/liberar-sem-codigo', [IfoodPedidosController::class, 'liberarSemCodigo']);
```

- [ ] **Step 5: rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-conclusao.php && PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Http/Controllers/Entregas/IfoodPedidosController.php api/app/Providers/RouteServiceProvider.php`
Expected: `FALHAS: 0` e `OK`.

- [ ] **Step 6: commit**

```bash
git rev-parse --show-toplevel
git add api/app/Http/Controllers/Entregas/IfoodPedidosController.php api/app/Providers/RouteServiceProvider.php scripts/teste-php/ifood-conclusao.php
git commit -m "iFood etapa 3: painel iFood do console e \"Liberar sem código\" (saída da central)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 12: número e cobrança do iFood no alarme

**Files:**
- Modify: `api/app/Support/Entregas/CartaoDoAlarme.php`
- Test: `scripts/teste-php/cartao-do-alarme.php`

- [ ] **Step 1: os testes**

Em `scripts/teste-php/cartao-do-alarme.php`, troque:

```php
require __DIR__ . '/../../api/app/Support/Entregas/CartaoDoAlarme.php';
```

por:

```php
require __DIR__ . '/../../api/app/Support/Entregas/CartaoDoAlarme.php';
require __DIR__ . '/../../api/app/Support/Entregas/Ifood/CobrancaIfood.php';
```

e troque a última linha `echo PHP_EOL . "FALHAS: {$falhas}" . PHP_EOL;` por:

```php
// pedido iFood (etapa 3): número e cobrança na porta
confere(C::ifood(null) === [], 'pedido que não é do iFood: nada');
confere(C::ifood((object) ['numero' => '4821', 'cobrar_centavos' => 5890, 'forma_pagamento' => 'CASH', 'troco_para_centavos' => 10000]) === ['entregas_ifood' => '4821', 'entregas_cobrar' => 'Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100'], 'número e cobrança');
confere(C::ifood((object) ['numero' => '4821', 'cobrar_centavos' => 0, 'forma_pagamento' => null, 'troco_para_centavos' => null]) === ['entregas_ifood' => '4821'], 'pago online: só o número');

echo PHP_EOL . "FALHAS: {$falhas}" . PHP_EOL;
```

- [ ] **Step 2: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/cartao-do-alarme.php`
Expected: erro fatal `Call to undefined method App\Support\Entregas\CartaoDoAlarme::ifood()`.

- [ ] **Step 3: `CartaoDoAlarme`**

Em `api/app/Support/Entregas/CartaoDoAlarme.php`:

1. Troque:

```php
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vendor;
```

por:

```php
use App\Support\Entregas\Ifood\CobrancaIfood;
use App\Support\Entregas\Ifood\PedidosIfood;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vendor;
use Illuminate\Support\Facades\Log;
```

2. No fim do docblock da classe, troque:

```php
 * como polyline do Google): o push de dados do FCM tem limite de 4 KB.
 */
```

por:

```php
 * como polyline do Google): o push de dados do FCM tem limite de 4 KB.
 *
 * Pedido iFood (etapa 3): entregas_ifood (o número) e entregas_cobrar (o texto da cobrança na porta, CobrancaIfood).
 */
```

3. No fim do `doPedido()`, troque:

```php
            $pedido->status,
            !empty($rota['aproximado'])
        ));
    }
```

por:

```php
            $pedido->status,
            !empty($rota['aproximado'])
        ), static::ifoodDoPedido($pedido));
    }

    /**
     * Pedido iFood: entregas_ifood (o número, "4821") e entregas_cobrar ("Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100",
     * só com valor a cobrar na porta). O cartão do APK da etapa 4 mostra os dois em destaque; o APK antigo ignora.
     */
    public static function ifood(?object $linha): array
    {
        if (!$linha) {
            return [];
        }

        return array_filter([
            'entregas_ifood'  => isset($linha->numero) && $linha->numero !== '' ? (string) $linha->numero : null,
            'entregas_cobrar' => CobrancaIfood::texto((int) ($linha->cobrar_centavos ?? 0), $linha->forma_pagamento ?? null, isset($linha->troco_para_centavos) ? (int) $linha->troco_para_centavos : null),
        ], fn ($valor) => $valor !== null);
    }

    /** Os dados do iFood do pedido; uma falha na consulta não tira o resto do cartão. */
    protected static function ifoodDoPedido(Order $pedido): array
    {
        try {
            return static::ifood(PedidosIfood::doPedido((string) $pedido->uuid));
        } catch (\Throwable $e) {
            Log::warning('[entregas] alarme sem os dados do iFood', ['pedido' => $pedido->public_id, 'erro' => get_class($e)]);

            return [];
        }
    }
```

- [ ] **Step 4: rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/cartao-do-alarme.php && PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/avisos-push.php | tail -1`
Expected: os dois com `FALHAS: 0`.

- [ ] **Step 5: commit**

```bash
git rev-parse --show-toplevel
git add api/app/Support/Entregas/CartaoDoAlarme.php scripts/teste-php/cartao-do-alarme.php
git commit -m "iFood etapa 3: número e cobrança do iFood nos dados do alarme (entregas_ifood, entregas_cobrar)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 13: conferência das ligações e a suíte inteira

**Files:**
- Test: `scripts/teste-php/ifood-ligacoes.php`

- [ ] **Step 1: o teste das ligações**

Crie `scripts/teste-php/ifood-ligacoes.php`:

```php
<?php

// Integração iFood (etapa 3): o que liga as peças no Laravel (providers, rotas, middlewares e stack), conferido no
// texto dos arquivos: os providers não sobem no php-wasm. A ligação de verdade é conferida na produção (última task).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-ligacoes.php

require __DIR__ . '/stubs-ifood.php';

$app    = file_get_contents('/repo/api/app/Providers/AppServiceProvider.php');
$rotas  = file_get_contents('/repo/api/app/Providers/RouteServiceProvider.php');
$portal = file_get_contents('/repo/api/app/Http/Middleware/RegrasPortalLoja.php');
$config = file_get_contents('/repo/api/config/services.php');
$stack  = file_get_contents('/repo/deploy/docker-stack.yml');
$modelo = file_get_contents('/repo/deploy/stack.env.example');

echo '== AppServiceProvider' . PHP_EOL;
confere(str_contains($app, 'Order::updated(fn ($pedido) => ObservadorDosPedidosIfood::aoAtualizar($pedido));'), 'Order::updated → ObservadorDosPedidosIfood');
confere(str_contains($app, 'Event::listen(OrderDriverAssigned::class, fn ($evento) => ObservadorDosPedidosIfood::aoAtribuirMotoboy($evento));'), 'OrderDriverAssigned → ObservadorDosPedidosIfood');
confere(str_contains($app, 'use Fleetbase\FleetOps\Events\OrderDriverAssigned;') && str_contains($app, 'use Fleetbase\FleetOps\Models\Order;'), 'imports do evento e do model');
confere(str_contains($app, '$this->acompanharPedidosIfood();'), 'chamado no boot');

echo '== RouteServiceProvider' . PHP_EOL;
$regrasApi   = strpos($rotas, "pushMiddlewareToGroup('fleetbase.api', RegrasDoPedidoIfood::class)");
$regrasInt   = strpos($rotas, "pushMiddlewareToGroup('fleetbase.protected', RegrasDoPedidoIfood::class)");
$barrar      = strpos($rotas, "pushMiddlewareToGroup('fleetbase.api', BarrarAceiteDePedidoEncerrado::class)");
confere($regrasApi !== false && $regrasInt !== false, 'RegrasDoPedidoIfood na API v1 e no console');
confere($regrasApi !== false && $barrar !== false && $regrasApi < $barrar, 'antes do BarrarAceiteDePedidoEncerrado');
foreach ([
    "Route::get('pedidos/{id}/ifood', [MotoboyController::class, 'ifood']);",
    "Route::post('pedidos/{id}/concluir-ifood', [MotoboyController::class, 'concluirIfood']);",
    "Route::post('pedidos/{id}/codigo-ifood', [MotoboyController::class, 'codigoIfood'])->middleware('throttle:entregas-ifood-codigo');",
    "Route::get('pedidos/{id}/ifood', [IfoodPedidosController::class, 'painel']);",
    "Route::post('pedidos/{id}/ifood/liberar-sem-codigo', [IfoodPedidosController::class, 'liberarSemCodigo']);",
    "RateLimiter::for('entregas-ifood-codigo'",
] as $trecho) {
    confere(str_contains($rotas, $trecho), "rota/limitador: {$trecho}");
}

echo '== Portal, configuração e stack' . PHP_EOL;
$ifood = strpos($portal, 'PedidosIfood::ehDoIfood((string) $pedido->uuid)');
$trava = strpos($portal, 'TravaDoPedido::executar($pedido->uuid, fn () => $this->cancelarComATrava');
confere($ifood !== false && $trava !== false && $ifood < $trava, 'portal: pedido iFood recusado antes da trava do cancelamento');
confere(str_contains($config, "'exige_app_novo' => env('ENTREGAS_IFOOD_EXIGE_APP_NOVO'),"), 'services.ifood.exige_app_novo');
confere(str_contains($stack, 'ENTREGAS_IFOOD_EXIGE_APP_NOVO: ${ENTREGAS_IFOOD_EXIGE_APP_NOVO:-}'), 'variável no x-api-env do stack');
confere(str_contains($modelo, 'ENTREGAS_IFOOD_EXIGE_APP_NOVO='), 'documentada no stack.env.example');

echo '== Relatório, cobrança e ganhos: o pago mesmo cancelado' . PHP_EOL;
$calculo = file_get_contents('/repo/api/app/Support/Entregas/CalculoEntregas.php');
confere(str_contains($calculo, "->leftJoin('entregas_ifood_pedidos as entregas_ifood', 'entregas_ifood.order_uuid', '=', 'orders.uuid')"), 'junta a linha do iFood');
confere(str_contains($calculo, "where('entregas_ifood.pago_mesmo_cancelado', true)->whereNotNull('entregas_ifood.cancelado_pelo_ifood_em')"), 'concluído ou pago mesmo cancelado');
confere(str_contains(\App\Support\Entregas\CalculoEntregas::DATA_DA_ENTREGA, 'ELSE entregas_ifood.cancelado_pelo_ifood_em END'), 'o pago mesmo cancelado conta pela data do cancelamento');

resumo();
```

- [ ] **Step 2: rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-ligacoes.php`
Expected: `FALHAS: 0` (se algum item falhar, a task correspondente ficou pela metade).

- [ ] **Step 3: a suíte inteira e a sintaxe**

Run:

```bash
for t in scripts/teste-php/*.php; do case $t in *stubs*|*fixtures*) continue;; esac; echo "$(basename $t) $(PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs $t 2>&1 | tail -1)"; done
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/Ifood/*.php api/app/Jobs/Entregas/*.php api/app/Listeners/Entregas/*.php api/app/Console/Commands/Entregas/*.php api/app/Console/Kernel.php api/app/Http/Middleware/*.php api/app/Http/Controllers/Entregas/*.php api/app/Providers/*.php api/app/Support/Entregas/*.php api/app/Notifications/Entregas/*.php api/app/Events/Entregas/*.php api/database/migrations/*.php api/config/services.php
```

Expected: todos os testes com `FALHAS: 0` e todos os arquivos com `OK`.

- [ ] **Step 4: commit**

```bash
git rev-parse --show-toplevel
git add scripts/teste-php/ifood-ligacoes.php
git commit -m "iFood etapa 3: teste das ligações (providers, rotas, middleware, stack)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 14: documentação (CLAUDE.md e spec)

**Files:**
- Modify: `CLAUDE.md`
- Modify: `docs/superpowers/specs/2026-10-05-integracao-ifood-logistics-design.md`

- [ ] **Step 1: `CLAUDE.md`**

1. Na seção "Integração iFood (etapa 2: entrada dos pedidos)", troque o item "**Situação:**" e o item "**Não vincule loja real antes da etapa 3.**" (com os dois subitens) por:

```markdown
- **Situação:** etapa 2 em produção; etapa 3 (ações de logística, GPS, cancelamento, rotas do motoboy) implementada em
  2026-10-06 (plano `docs/superpowers/plans/2026-10-06-ifood-etapa-3-ciclo.md`). A etapa 4 (APK, console e portal) é o
  plano `2026-10-06-ifood-etapa-4-app-console.md`.
- **Não vincule loja real antes do APK da etapa 4 em todos os celulares** e da trava "Atualize o app" ligada (ver
  "Integração iFood (etapa 3)"). Até lá, só a loja de teste.
```

2. No item "**Outros eventos:**" do "Fluxo do pedido", troque o subitem que começa com "CAN grava `cancelado_pelo_ifood_em`" por:

```markdown
  - CAN cancela o pedido no Entregas (etapa 3, `CancelamentoPeloIfood`; ver "Integração iFood (etapa 3)");
```

3. Em "Riscos que ficam", apague os itens "**CAN não cancela o pedido até a etapa 3**" e "**Na etapa 3, o CAN precisa da
   `TravaDoPedido`:**" (resolvidos).

4. Logo depois da subseção "### Testes" da seção da etapa 2 (antes de "## Marca Entregas RestaurantePro"), acrescente:

```markdown
## Integração iFood (etapa 3: ciclo da entrega)

Desenho: spec, seções 3 e 4. Plano: `docs/superpowers/plans/2026-10-06-ifood-etapa-3-ciclo.md` (o código é a referência).

### Ações de logística

- O servidor informa ao iFood cada etapa: motoboy definido → `assignDriver` (nome e telefone do cadastro, só dígitos,
  sem o 55, `MOTORCYCLE`); `started` → `goingToOrigin`; a até 100 m da coleta pelo GPS → `arrivedAtOrigin`; `enroute`
  ("A caminho") → `dispatch`; a até 100 m da entrega → `arrivedAtDestination`; `completed` → `arrivedAtDestination`.
- **Gancho:** `Order::updated` (Eloquent) e `OrderDriverAssigned` (atribuição em lote), registrados no
  `AppServiceProvider::acompanharPedidosIfood` → `ObservadorDosPedidosIfood` → job `EnviarAcaoIfood` (um por pedido de
  cada vez, `afterCommit`). O `scheduleOrder` do console (saveQuietly) e jobs perdidos ficam com a reconciliação.
- **`AcoesIfood::sincronizar`** (trava `entregas:ifood-acao:<uuid>`): relê o pedido, calcula o alvo pelo estado
  (`SequenciaIfood`) e envia, em ordem, o que falta depois da `ultima_acao` (a única fonte do estado: o iFood não devolve
  evento das nossas ações). Troca de motoboy (`motoboy_no_ifood` diferente) = `assignDriver` de novo.
- **Falhas:** 429 → volta pelo `Retry-After`; 5xx, 408, rede → até 5 tentativas (10 s a 5 min) em 30 min; 409/4xx,
  vínculo perdido ou motoboy sem telefone → recusa: `recusa_acao`/`recusa_status`/`recusa_em`, log
  `[entregas] ifood: ação recusada` (ação, status, corpo) e aviso no console (`entregas.ifood_acao_recusada`). Uma recusa
  não se repete sozinha; a próxima mudança do pedido tenta de novo. Tentativas esgotadas viram recusa.
- **`entregas:ifood-acompanhar`** (30 s, `withoutOverlapping(5)`, em segundo plano): chegada pelo GPS (`ChegadaPeloGps`,
  `drivers.location` × coleta/entrega) e reconciliação (estado além da `ultima_acao`), só pedidos das últimas 24 h, sem
  recusa e não cancelados.

### Cancelamento

- **CAN** (`CancelamentoPeloIfood`, com a `TravaDoPedido` e a trava das ações): concluído → nada muda; nos outros, sai dos
  abertos (saveQuietly) e `Order::cancel()` (atividade, `OrderCanceled` → push ao motoboy atribuído com o texto
  "Pedido #4821 cancelado pelo iFood"). Com o `dispatch` já aceito: `pago_mesmo_cancelado`, o push acrescenta "Você recebe
  por esta entrega. Combine com a loja a devolução." e o pedido entra no relatório, na cobrança da loja e nos ganhos do
  app pela data do cancelamento (`CalculoEntregas::pedidosConcluidos`, `cancelado_pago` na linha).
- **Cancelamento do nosso lado é proibido** no pedido iFood (400 "Pedido do iFood: o cancelamento é feito no iFood."):
  `RegrasDoPedidoIfood` (API v1 `cancelOrder`, console `cancel`/`bulk-cancel` e a atividade "canceled", inclusive o
  arrastar do quadro) e `RegrasPortalLoja` (portal). Com problema do motoboy, a central troca o motoboy.

### Conclusão e código de entrega

- App (APK da etapa 4): `POST v1/entregas/motoboy/pedidos/{id}/concluir-ifood` garante o `arrivedAtDestination` na hora;
  com `exige_codigo` (DDCR) responde `precisa_codigo` e o app manda `POST .../codigo-ifood` (`verifyDeliveryCode` na hora:
  400 "Confirmation code is invalid" ou 422 = código incorreto; outro erro = tente de novo; limitador
  `entregas-ifood-codigo`, 10 por minuto). Certo (ou sem código exigido) grava `conclusao_liberada_em` e o app conclui
  pelo `update-activity` de sempre.
- **Pedido de teste também pede o código** (o Gestor de Pedidos pediu no teste). Sem o código: a central usa "Liberar sem
  código" no painel iFood do console (`POST int/v1/entregas/pedidos/{id}/ifood/liberar-sem-codigo`, só admin):
  `conclusao_sem_codigo`, log `[entregas] ifood: conclusão sem código liberada pela central`; o iFood conclui sozinho 4 h
  depois. Concluir pelo console um pedido que exigia código também marca `conclusao_sem_codigo` (warning no log).
- **Trava "Atualize o app"** (`ENTREGAS_IFOOD_EXIGE_APP_NOVO=1` no `stack.env`, **desligada por padrão**): a conclusão
  comum de pedido iFood pela API v1 sem `conclusao_liberada_em` responde 400 "Atualize o app para concluir pedidos do
  iFood.". **Ligue só depois do APK da etapa 4 em todos os celulares**; com ela desligada, o APK antigo conclui sem código
  (o iFood conclui sozinho 4 h depois).
- `GET v1/entregas/motoboy/pedidos/{id}/ifood`: número, cobrança (texto da `CobrancaIfood`), observações, complemento,
  referência, código exigido, conclusão liberada, cancelamento; 0800 e localizador só para o motoboy do pedido, em
  andamento e antes de expirar. O alarme leva `entregas_ifood` e `entregas_cobrar`.
- Painel do console: `GET int/v1/entregas/pedidos/{id}/ifood` (só admin; ações pelo código, traduzidas no console).

### Logs da etapa 3

- Fila (`docker service logs entregas_queue 2>&1 | grep '\[entregas\] ifood'`): `ação enviada`, `ação recusada`,
  `ação não enviada; tentativas esgotadas`, `pedido cancelado pelo iFood`, `pedido concluído sem o código do cliente`.
- Scheduler (`entregas_scheduler`): `chegada pelo GPS`, `falha ao acompanhar o pedido`.
- Aplicação (`entregas_application`): `código de entrega conferido`/`incorreto`, `conclusão sem código liberada pela
  central`, `ação barrada no pedido do iFood`.

### Ao atualizar o fleetops-api, confira

- que todo status ainda passa por `setStatus(…, true)` → `save()` e que o `startOrder` grava `started` com `save()`
  (gancho `Order::updated`);
- o `OrderDriverAssigned` e o `NotifyBulkAssignedDriver`;
- os nomes das ações barradas (`Api\v1\OrderController@cancelOrder|updateActivity|completeOrder`,
  `Internal\v1\OrderController@cancel|bulkCancel|updateActivity`) e o corpo (`order`, `ids`, `activity.code`);
- que o `Order::cancel()` ainda não tira o motoboy e que o `HandleOrderCanceled` notifica o atribuído.

### Testes da etapa 3

`scripts/teste-php/ifood-sequencia.php`, `ifood-cliente-acoes.php`, `ifood-acoes.php`, `ifood-acompanhar.php`,
`ifood-cancelamento.php`, `ifood-regras.php`, `ifood-conclusao.php` e `ifood-ligacoes.php` (php-wasm).
```

5. No "Histórico", acrescente o item seguinte ao último:

```markdown
17. Integração iFood, etapa 3 (2026-10-06): ações de logística pelos eventos do Fleetbase e pelo GPS, CAN cancela (pago
    mesmo cancelado), cancelamento do nosso lado proibido, rotas do motoboy (dados, conclusão, código) e "Liberar sem
    código" no console.
```

(Se o número 17 já estiver em uso, use o seguinte livre.)

- [ ] **Step 2: spec**

Em `docs/superpowers/specs/2026-10-05-integracao-ifood-logistics-design.md`:

1. Na linha "Situação" do topo, acrescente ao fim do parágrafo: `Etapa 3 implementada em 2026-10-06 (plano
   2026-10-06-ifood-etapa-3-ciclo.md): pedido de teste também exige o código; saída da central "Liberar sem código";
   cancelamento proibido num middleware só (RegrasDoPedidoIfood).`
2. Na seção 4, troque o item 5 do "Concluir pedido iFood" (o que começa com "**pedido de teste (`isTest`) conclui sem
   código**") por:

```markdown
  5. **pedido de teste segue o `exige_codigo`** como qualquer outro: ao concluir um pedido de teste pelo Gestor de
     Pedidos, o iFood pediu o código. De onde tirar o código de um pedido de teste fica a conferir (página de testes do
     Portal do Desenvolvedor); sem ele, a central usa **"Liberar sem código"** no painel iFood do console (registrado no
     banco e no log; o iFood conclui sozinho 4 h depois).
```

3. Na mesma seção, troque o item "**Trava:**" por:

```markdown
- **Trava:** a conclusão comum (atividade `completed` da API v1) de pedido iFood é recusada com 400 "Atualize o app" até
  a rota concluir-ifood liberar. Fica atrás do interruptor `ENTREGAS_IFOOD_EXIGE_APP_NOVO` (desligado por padrão): **o
  APK novo precisa estar em todos os celulares antes de ligar a trava e de vincular a primeira loja real.**
```

4. Na seção 3, no item "**Cancelamento do nosso lado é proibido**", troque "(no `RegrasPortalLoja`, no
   `BarrarAceiteDePedidoEncerrado` e na rota de cancelar do console)" por "(no `RegrasPortalLoja` e no
   `RegrasDoPedidoIfood`, que cobre a API v1 e o console, inclusive a atividade \"canceled\")".
5. Em "Riscos e pontos em aberto", troque "O código de entrega não pode ser testado com pedido de teste; só na
   homologação." por "O código de entrega do pedido de teste: de onde obtê-lo ainda é a conferir; sem ele, a central
   libera sem código."

- [ ] **Step 3: commit**

```bash
git rev-parse --show-toplevel
git add CLAUDE.md docs/superpowers/specs/2026-10-05-integracao-ifood-logistics-design.md
git commit -m "iFood etapa 3: documentação (CLAUDE.md e spec: teste com código, Liberar sem código, trava)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 15: verificação em produção com a loja de teste (Edgard + Claude)

O Claude não tem SSH: os comandos na VPS são do Edgard. A trava "Atualize o app" fica **desligada** nesta verificação
(o APK ainda é o antigo). Só a loja de teste vinculada (merchant `4173843`).

- [ ] **Step 1: deploy da API**

Depois do merge e do push (decisão do Edgard), na VPS: `cd ~/entregas && bash deploy/atualizar.sh api`. Ele roda a
migration nova no banco principal e no sandbox e o `config:cache`. Confira:

```bash
docker exec $(docker ps -q -f name=entregas_application) php artisan schedule:list | grep ifood
docker exec $(docker ps -q -f name=entregas_application) php artisan route:list --path=entregas | grep -E "ifood|concluir|codigo"
docker exec -it $(docker ps -q -f name=entregas_database) mysql -uroot -p fleetbase -e "SHOW COLUMNS FROM entregas_ifood_pedidos LIKE 'recusa%'"
```

Expected: o `entregas:ifood-acompanhar` na lista (a cada 30 s), as 5 rotas novas, as 3 colunas `recusa_*`.

- [ ] **Step 2: pedido de teste até o despacho**

Gere um pedido de teste no Portal do Desenvolvedor do iFood para a loja de teste. Em até 30 s ele aparece no console com
"[TESTE]" e sem despacho (pedido de teste não vai aos motoboys). Atribua a um motoboy de teste pelo console.
Confira: `docker service logs entregas_queue --since 5m 2>&1 | grep '\[entregas\] ifood'` mostra `ação enviada`
(`assignDriver`). No Gestor de Pedidos do iFood o pedido mostra o entregador.

- [ ] **Step 3: iniciar, chegada e "A caminho"**

No app do motoboy de teste: iniciar o pedido → `ação enviada` com `goingToOrigin`. Perto da loja (100 m), em até 30 s, o
scheduler mostra `chegada pelo GPS` e a fila `arrivedAtOrigin` (`docker service logs entregas_scheduler --since 5m 2>&1 |
grep 'chegada pelo GPS'`). "A caminho" → `dispatch`. No Gestor de Pedidos: "Em rota".

- [ ] **Step 4: conclusão (APK antigo, trava desligada)**

Conclua pelo app. Expected: a fila mostra `arrivedAtDestination` e, como o pedido de teste trouxe o DDCR,
`pedido concluído sem o código do cliente` (warning). No painel (`GET int/v1/entregas/pedidos/<id>/ifood` pelo console
logado) `conclusao_sem_codigo: true`. O iFood fica com a confirmação pendente e conclui sozinho em 4 h.
**A conferir aqui:** se a página de testes do Portal do Desenvolvedor mostra o código do pedido de teste. Se mostrar,
teste com `curl` (token de motoboy) a rota `codigo-ifood` num segundo pedido: código errado → 422 "Código incorreto";
certo → `pode_concluir`. Anote no `CLAUDE.md` de onde o código veio.

- [ ] **Step 5: cancelamento proibido e CAN**

Num terceiro pedido de teste, atribuído e iniciado: tentar cancelar pelo console → 400 "Pedido do iFood: o cancelamento é
feito no iFood." (confirma o `RegrasDoPedidoIfood` no `fleetbase.protected`). Cancele pelo Gestor de Pedidos do iFood
(antes do "A caminho"): em até 30 s o pedido fica cancelado no console, o motoboy recebe "Pedido #N cancelado pelo
iFood", e `pago_mesmo_cancelado = 0`. Repita depois do "A caminho": `pago_mesmo_cancelado = 1` e o pedido aparece em
"Pagamento e cobrança" no dia do cancelamento, com o valor da faixa. Se o iFood não deixar cancelar depois do dispatch,
anote no `CLAUDE.md` e confira só o primeiro caso.

- [ ] **Step 6: troca de motoboy**

Num pedido de teste já com `goingToOrigin`, troque o motoboy pelo console. Expected: `ação enviada` com `assignDriver`
(o iFood aceitou) ou `ação recusada` com 409 e o aviso no console (anote qual no `CLAUDE.md`, "A conferir" resolvido).

- [ ] **Step 7: registrar**

Atualize o `CLAUDE.md` (seção da etapa 3) com o que foi conferido: troca de motoboy, raio de 100 m, origem do código do
pedido de teste, formato do `workerPhone`. Commit só desse arquivo, com o trailer.

## Ajustes das revisões

Aplicados depois da revisão das Tasks 1–12 (commits "iFood etapa 3 (revisão): …"). O que muda o comportamento
descrito acima e o que fica só como texto para a Task 14 (documentação), a Task 15 (produção) e o plano 2.

### Comportamento novo (Task 14: levar ao `CLAUDE.md` e à spec)

- **409 numa ação da sequência = já aceita** (decisão do responsável): reenvio depois de uma resposta ou de uma
  gravação perdida ("operação já concluída" na referência). O `AcoesIfood` avança a `ultima_acao`, registra
  `[entregas] ifood: ação já aceita pelo iFood (409)` (info) e segue, sem recusa nem aviso. Efeito colateral a
  conhecer: um 409 por ordem errada (ex.: `arrivedAtDestination` depois de um `dispatch` recusado) também avança.
- **409 no `assignDriver` de troca de motoboy**: recusa com aviso à central **uma vez** (o motoboy novo fica em
  `motoboy_no_ifood` como marca; o iFood continua com o anterior) e as ações seguintes da sequência continuam (não
  trava o `dispatch` nem o `arrivedAtDestination`). Outra recusa na troca (400, vínculo, cadastro) ainda para.
- **Log da recusa** só com `errorType`/`code`/`description` (nunca o corpo cru); no `assignDriver`, o nome e o
  telefone do motoboy (e sequências de 6+ dígitos) saem mascarados. O corpo do `acaoLogistica` tem
  `#[\SensitiveParameter]`, e corpo `[]` vai sem corpo.
- **Orçamento de 45 s** no laço do `AcoesIfood` (`ORCAMENTO_SEGUNDOS`): o resto volta como `incompleto`; o job volta
  para a fila (1 s) e a rota `concluir-ifood` responde "tente de novo".
- **Marca de pendente do job** vale 120 s, volta junto com o `release()` e com a exceção de nova tentativa (valendo
  mais que a espera) e some no `failed()`. `desistir()` toma a trava com 2 s de espera e sai sem gravar com recusa já
  registrada, sem motoboy ou com nada faltando (usa o alvo mínimo do job).
- **`entregas:ifood-acompanhar`**: filtro no SQL (join com `orders`; sem `arrivedAtDestination`; Order não encerrado,
  exceto o concluído; não apagado) antes do limite de 300; janela de 24 h pelo `despachado_em` ou pelo `created_at`
  (o agendado criado há mais de 24 h entra pelo despacho). Migration nova `2026_10_06_130000_add_indice_acompanhar_*`
  (índice em `created_at`; o `despachado_em` já estava indexado). Posição do motoboy com mais de 5 min
  (`drivers.updated_at`) não vale como chegada.
- **Pago mesmo cancelado** também com o Order `enroute` (o motoboy tocou "A caminho"), além da `ultima_acao` no
  `dispatch` ou depois (decisão do responsável). CAN sem Order: log info. Exceção no cancelamento sobe como
  `RuntimeException` só com a classe e o SQLSTATE (o `failed_jobs` não guarda o SQL); `LockTimeoutException` passa.
- **Cancelamento barrado também no PUT do pedido**: `PUT v1/orders/{id}` (`update`) e `PUT/PATCH int/v1/orders/{id}`
  do console (`updateRecord`) com `status` (ou `order.status`) de cancelamento, em pedido iFood. **Ao atualizar o
  fleetops-api, conferir se as ações `Api\v1\OrderController@update` e `Internal\v1\OrderController@updateRecord`
  ainda existem.**
- **Código de entrega**: com a trava das ações, a linha é relida antes do `verifyDeliveryCode` (dois cliques ou a
  central já liberou → `pode_concluir` sem chamar o iFood). Teto de **10 códigos errados por pedido** (contador no
  cache `entregas:ifood-codigo-erros:<uuid>`, 3 dias; iFood fora do ar não conta): depois dele, **429**
  `{"resultado": "muitas_tentativas", "errors": ["Muitas tentativas: peça à central para liberar."]}` sem chamar o
  iFood. "Liberar sem código" com a conclusão já liberada devolve o estado sem gravar nem registrar.
- **Cobrança no app**: o troco aparece sempre que existe (o `PedidoDoIfood` só o grava do bloco de dinheiro, então
  vale também com "MISTO"); `GIFT_CARD` = "vale-presente", `OTHER` = "outra forma".

### Só documentar (Task 14)

- O `LogApiRequests` do core grava o corpo das chamadas da API v1, inclusive o `POST v1/entregas/motoboy/pedidos/{id}/codigo-ifood`
  (o código de entrega) e o token, em `api_request_logs` (Developers → Logs, só admin). Anotar no `CLAUDE.md`.
- O `internal_id` do pedido pode repetir entre pedidos; o app e as rotas do motoboy usam o `public_id` (a busca do
  `RegrasDoPedidoIfood` aceita os três, sempre filtrada pela empresa).

### Task 15 (produção)

- Conferir uma ação sem corpo (ex.: `goingToOrigin`) indo com `Content-Type: application/json` do `send('POST')`: se o
  iFood responder 400 ou 415, é isso (trocar por um POST sem cabeçalho de corpo).
- No roteiro: uma ação recusada antes (ex.: `dispatch` com 400) impede o `arrivedAtDestination` de ser aceito pelo
  iFood na ordem; o motoboy segue para o código, e a central usa "Liberar sem código" no painel iFood do console.
- Conferir o 409 de troca de motoboy (aviso uma vez só no console, `dispatch` seguindo) e o 409 de uma ação reenviada
  (log info "ação já aceita pelo iFood (409)").

### Plano 2 (`2026-10-06-ifood-etapa-4-app-console.md`)

- Extrato da loja (`PortalLojaController@extrato`, o `array_map` das entregas, ~linhas 86–95) não repassa o
  `cancelado_pago`: acrescentar o campo para o portal mostrar "pago mesmo cancelado".
- App: o `codigo-ifood` pode responder 429 `muitas_tentativas` com o texto do servidor; o `resultadoDaConclusao` já
  trata resultado desconhecido como "tente de novo" e o erro mostra a mensagem do servidor, mas vale acrescentar
  `'muitas_tentativas'` ao tipo e uma mensagem própria no campo do código.
