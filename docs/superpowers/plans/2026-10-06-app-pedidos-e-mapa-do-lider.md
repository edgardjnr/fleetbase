# App do motoboy: aba Pedidos enxuta e aba Mapa do líder

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** implementar a spec `docs/superpowers/specs/2026-10-06-app-pedidos-e-mapa-do-lider-design.md`: (A) a aba Pedidos do
app mostra só os pedidos novos para aceitar e os em andamento, com o card enxuto (número do pedido e loja da coleta); (B) a
aba Relatórios sai e, só para o líder dos motoboys (papel do IAM com `fleet-ops assign-driver-for order`) ou administrador,
entra a aba Mapa, com os pedidos em andamento e os motoboys no mapa e a troca do motoboy de um pedido.

**Architecture:** o app (repo separado `entregas-navigator`, React Native) ganha duas funções puras testadas com o Node
(`lista-de-pedidos.ts`, `mapa-do-lider.ts`), a tela `MapaDoLiderScreen`, o hook `use-lider` (acesso em memória, lido pelo
`if` da aba na configuração estática do React Navigation) e a aba `DriverMapaTab` no lugar da `DriverReportTab`. O servidor
(`api/app`) ganha três rotas na API v1 com o token do motoboy (`v1/entregas/lider/*`, `LiderController`), a regra de quem é
líder (`LiderDosMotoboys`, pelas permissões do `CompanyUser` da empresa da sessão), o mapa (`MapaDoLider`, sobre o
`PedidosNoMapa` e o `MotoboysNoMapaDaLoja` que já existem), a troca (`TrocaDoMotoboy`, com a `TravaDoPedido` e o
`assignDriver` do Fleet-Ops) e o push ao motoboy anterior (`PedidoPassadoParaOutro`, canal `avisos`).

```
App (líder)                                   Servidor (api/app)
use-lider ── GET v1/entregas/lider/acesso ──> LiderController@acesso ─> LiderDosMotoboys::daSessao
  └─ if: useEhLider mostra a aba Mapa
MapaDoLiderScreen (a cada 10 s) ─ GET .../mapa ─> @mapa ─> MapaDoLider: PedidosNoMapa::doLider + MotoboysNoMapaDaLoja::noMapa
  └─ Trocar motoboy ─ POST .../pedidos/{id}/motoboy ─> @trocarMotoboy ─> TrocaDoMotoboy
                                                        TravaDoPedido: adhoc=false + assignDriver($novo, true)
                                                        └─ save → OrderObserver → OrderDriverAssigned → push "Novo pedido para você" ao novo
                                                                                                   → ObservadorDosPedidosIfood (assignDriver no iFood)
                                                        └─ PedidoPassadoParaOutro ao anterior (canal avisos, tipo entregas_pedido_trocado)
App (motoboy anterior): DriverLayout recebe entregas_pedido_trocado → recarrega a lista
```

**Tech Stack:** React Native 0.86 + Tamagui + React Navigation 7 (configuração estática) + react-native-maps +
@gorhom/bottom-sheet 5 + `@fleetbase/sdk` (app); Laravel 10 + Fleet-Ops 0.6.65 + core-api 1.6.61 (servidor); `node:test` e
php-wasm (PHP 8.2) nos testes.

## Contexto para quem executa

- Leia no `CLAUDE.md` deste repo as seções "App do motoboy", "Mapa ao vivo", "Portal da loja" → "Mapa de motoboys" e
  "Integração iFood (etapa 3: ciclo da entrega)" (com a "Etapa 4"), e a spec inteira.
- **Ordem:** Parte A (app, Tasks 1 a 3) → servidor da Parte B (Tasks 4 a 8) → app da Parte B (Tasks 9 a 11) → documentação
  (Task 12) → verificação em produção (Task 13, feita pelo Edgard).
- **Ramos novos** (criados nas Tasks 1 e 4), os dois a partir do `ifood-etapa-4`, que já mexeu no `OrderScreen`, no
  `AdhocOrderCard` e no `PedidoIfood`:
  - app: `app-pedidos-e-mapa-do-lider` no `entregas-navigator`;
  - servidor: `app-pedidos-e-mapa-do-lider` neste repo (Delivery).
- **App (`C:\Users\Edgardjr\Documents\vibe coding\entregas-navigator`, Git Bash `/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator`):**
  - funções puras em `src/utils/*.ts` sem imports, testadas com `node --experimental-strip-types --test scripts/testes/<nome>.teste.ts`
    (no teste o import leva a extensão `.ts`; no app, não);
  - chamadas à API com `adapter.get/post('entregas/...')` (o adapter já põe o `v1/`); erros com `toast.error(err?.message || t('...'))`;
  - textos em `translations/pt.json` **e** `translations/en.json` (`t('Bloco.chave', { variavel })`, interpolação
    `{{variavel}}`). Os dois arquivos estão no formato do `JSON.stringify(…, null, 4)`: os passos de tradução usam um script
    do Node que junta as chaves novas e regrava o arquivo (o git normaliza o fim de linha);
  - não há `node_modules` no PC: TypeScript e JSX só são conferidos no build do GitHub Actions. Confira a sintaxe dos
    `.ts/.tsx/.js` alterados com o `@babel/parser` do console (comando em cada task);
  - commit no repo do app é separado deste repo. **Não faça push** (o push na `main` dele gera o APK).
- **Servidor (este repo, `api/app`):** o PHP não roda no PC; os testes usam o php-wasm já instalado em `C:/tmp/php-wasm`:
  `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/<arquivo>.php` (termina com
  `FALHAS: 0`) e `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/sintaxe.mjs <arquivos.php>` (`OK` por arquivo).
  Mudanças em `packages/*/server` não chegam à produção; as cópias em `packages/fleetops/server` e `packages/core-api` são a
  referência do código publicado (mesmas versões do `api/composer.lock`).
- **Os comandos de cada task rodam na raiz do repo dela** (tasks do app: `/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator`;
  tasks do servidor e da documentação: `/c/Users/Edgardjr/Documents/vibe coding/Delivery`). Num shell que volta ao diretório
  inicial a cada chamada, comece cada bloco com o `cd` para essa raiz.
- Confirme `git rev-parse --show-toplevel` antes de cada commit; `git add` só dos arquivos da task; trailer
  `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`. Não faça push.
- Todo o código, os testes PHP e os testes node deste plano foram escritos e conferidos numa cópia de rascunho antes da
  publicação: suíte PHP inteira com `FALHAS: 0`, 44 testes node passando e o parse do Babel nos arquivos do app.

## O que a spec deixou "a conferir" (resolvido lendo o código)

1. **Permissão do líder numa chamada da API v1 com o token do motoboy.** O `fleetbase.api` autentica o token Sanctum
   (`AuthenticateOnceWithBasicAuth::authenticateSanctumToken`) e grava na sessão `company` = `users.company_uuid` e
   `user` = uuid do usuário (`Auth::setSession`). O `Fleetbase\Support\Auth::can($permissao)` busca as permissões
   `[perm, "fleet-ops * order", "fleet-ops *"]` e chama `$user->hasPermissionTo()`, que o `User` repassa ao
   `companyUser` (`ProxiesAuthorizationMethods::__call`; a relação `CompanyUserRelation` filtra pelo `company_uuid` do
   usuário). Funcionaria, mas o `hasPermissionTo` é o do Spatie: vê as permissões diretas e as dos papéis, **não as das
   políticas** (o `HasPolicies` é do Fleetbase), e o formulário de papel do IAM deixa anexar políticas. O console usa outra
   regra: o `AuthorizationGuard` chama `CompanyUser::hasPermissions` → `getAllPermissions()` (diretas, papéis, políticas e
   políticas dos papéis). **Decisão:** `LiderDosMotoboys::ehLider(User, empresa)` lê o `CompanyUser` do usuário na empresa
   da sessão e cruza os nomes do `getAllPermissions()` com as três permissões (a mesma regra do console); administrador
   (`isAdmin()`) é líder; vínculo `inactive` (IAM → Desativar) não é. E `daSessao()` exige antes o
   `MotoboyDaSessao::motoboy()` (token `id|token` e cadastro de motoboy): sem isso, a chave `flb_live_` do APK, que autentica
   como o admin que a criou, viraria líder.
2. **A tela de usuários do IAM e os motoboys.** Lista, sim: IAM → Usuários → aba **Motoristas** (`iam-engine`, rota
   `users.drivers`, consulta `is_driver: 1`) usa o mesmo controller da lista principal, com "Editar usuário" (formulário com
   **Papel**, **Anexar Políticas** e **Selecionar Permissões**). Não precisa de comando artisan. Cuidado: o papel é único
   (`assignSingleRole`) e o motoboy tem hoje o papel **Motorista** (política "Operações do motorista", `DriverOperations`);
   o papel "Líder de motoboys" o substitui. Por isso o papel do líder leva a política "Operações do motorista" **e** a
   permissão `fleet-ops assign-driver-for order`. Alternativa sem trocar o papel: manter "Motorista" e marcar só a
   permissão em "Selecionar Permissões" do usuário (vale igual, porque o `getAllPermissions` inclui as diretas). O comando
   `fleetops:assign-driver-roles` do Fleet-Ops (registrado, **não agendado**) devolveria o papel "Motorista" a todos.
3. **`Order::assignDriver` e a troca do console.** `assignDriver($driver, $silent = false)` (Fleet-Ops 0.6.65,
   `Models/Order.php`): mesmo motoboy → nada; grava o `driver_assigned_uuid`, põe a relação, **se não for silencioso
   dispara o `OrderDriverAssigned` antes do save**, e salva. Não grava atividade. O save passa pelo `OrderObserver::updated`,
   que dispara o `OrderDriverAssigned` de novo sempre que o `driver_assigned_uuid` muda: sem o silencioso, o evento sai duas
   vezes (dois pushes ao novo). O `HandleOrderDriverAssigned` (na fila) relê o pedido e só manda o `OrderAssigned` ("Novo
   pedido para você") se `$order->adhoc === false`. O console troca pelo modal `order-assign-driver` → `order.save()` →
   `PUT int/v1/orders/{id}` (`updateRecord`) → save → observer (um evento) e não mexe no `adhoc`. **Decisão:**
   `adhoc = false` e `assignDriver($novo, true)`, dentro da `TravaDoPedido`.
4. **Reaproveitamento no servidor:**
   - `PedidosNoMapa`: ganha `doLider()` (a consulta do `daCentral` com número, `motoboy_id`, coleta e `atualizado_em`) e
     `umDoLider()` (resposta da troca), sobre os `consulta()`, `itens()`, `nomesDasLojas()` e `nomeDaLoja()` que já existem;
   - `MotoboysNoMapaDaLoja`: a regra de quem aparece sai do `listar()` para um `noMapa()` público, que o `listar()` e o
     novo `MapaDoLider::motoboys()` usam (os mesmos motoboys do mapa do portal);
   - `SituacaoDoMotoboy` (situação), `StatusDoPedido::ENCERRADOS` (409), `TravaDoPedido` (troca × aceite × cancelamento),
     `MotoboyDaSessao` (token de motoboy) e `Coordenada`, sem mudança;
   - push: o `CanalFcmEntregas` passa todo push pelo `AvisosDoMotoboy::adaptar`; a classe nova entra no `texto()` e o tipo
     `entregas_pedido_trocado` no `CANAIS` (canal `avisos`). Os dados **não levam `id`**: o `DriverLayout` do app abre a tela
     do pedido (e toca o alarme) em todo push com `id` "order_…", e o pedido já não é dele;
   - rotas e limitadores no padrão do `RouteServiceProvider` (`RateLimiter::for` + grupo `v1/entregas/...` com
     `fleetbase.api`), como o `MotoboyController`.
5. **App:**
   - abas: `createBottomTabNavigator` estático (`DriverNavigator.tsx`), lista de abas em
     `navigatorConfig('driverNavigator.tabs')` com o padrão em `config/default.js` (o CI não define `DRIVER_NAVIGATOR_TABS`)
     e repetido no `DriverNavigator`. Aba condicional = `if: <hook>` na configuração da tela, como o
     `DriverNavigator: { if: useIsAuthenticated }` do `AppNavigator`;
   - `OrderManagerContext` fica igual: `allActiveOrders` (`active: true`, motoboy dele, sem `created/completed/canceled…`),
     `nearbyOrders`, `reloadActiveOrders`, `reloadNearbyOrders`, `isFetchingActiveOrders`, `isFetchingNearbyOrders`,
     `dismissedOrders`;
   - mapa: `react-native-maps` (`MapView`, `Marker`), como o `LiveOrderRoute`; capacete = `MarcadorCapacete` (mesmas
     situações `livre/coleta/entrega/offline`); alfinete = `Marker` com `pinColor='red'`;
   - bottom sheet: `@gorhom/bottom-sheet` 5 (já usado no `BottomSheetSelect` e no `CurrentDestinationSelect`; o `App.tsx`
     tem o `GestureHandlerRootView` e o `BottomSheetModalProvider`);
   - `use-fleetbase`: `adapter.get/post`. O erro do adapter só expõe a `message` no app (nenhum código lê o status HTTP):
     para a aba sumir no 403, a tela relê o `lider/acesso` (que nunca dá 403) quando o mapa falha;
   - `payload.pickup.name` existe no recurso v1 (`Payload` → `Place`, campo `name`); `customer` só vem quando carregado
     (`whenLoaded`); `internal_id` está no recurso v1 do `Order`.

## Decisões deste plano (além da spec)

- **Teste da Parte A** em `scripts/testes/lista-de-pedidos.teste.ts` (a spec permite juntar o `numero-do-pedido` a outro):
  `numeroDoPedido`, `lojaDaColeta`, `ehPedidoAberto` e `secoesDaLista` ficam em `src/utils/lista-de-pedidos.ts`.
- **Número no card:** `internal_id` → código de rastreio → `id` (public_id). No mapa do líder, o servidor manda o mesmo
  (`numero`), e o app mostra "#4821" só quando é número (iFood).
- **A seção Em andamento** também tira o pedido encerrado do cache local (`pedidoEncerrado`), além do filtro do servidor.
- **Líder exige cadastro de motoboy** (`MotoboyDaSessao`): admin sem motoboy recebe `{"lider": false}`; a chave do APK nunca
  é líder.
- **Erros da troca:** 404 "Pedido não encontrado.", 409 "Este pedido já foi encerrado.", 422 "Escolha um motoboy da lista.",
  409 "Outra pessoa está mexendo neste pedido. Tente de novo." (trava ocupada), 403 "Disponível só para o líder dos
  motoboys.".
- **Lista da troca:** os online e o motoboy atual (marcado "(atual)", não escolhível, mesmo offline); ordem pela distância
  em linha reta até a coleta; pedido sem coleta com coordenada: sem distância, pela ordem do nome.
- **Push ao motoboy anterior** (`PedidoPassadoParaOutro`): "Pedido passado para outro motoboy" / "Pedido #4821 passou para
  outro motoboy."; só FCM, na fila; falha ao enfileirar não desfaz a troca (warning no log). No app, o `DriverLayout`
  recarrega os pedidos ao receber o tipo `entregas_pedido_trocado`; o APK antigo só mostra a notificação.
- **A aba Relatórios sai só da lista padrão** (`config/default.js` e o padrão do `DriverNavigator`): o código das telas de
  relatório fica.

## A conferir (na Task 13)

- Se a aba Mapa aparece e some sem reabrir o app (o `if` estático lido do `useSyncExternalStore`), e o que acontece quando
  ela some com o líder nela (o React Navigation deve ir para outra aba).
- Se o formulário do IAM salva o papel de um usuário motorista sem erro (motoboy sem e-mail: a validação do
  `UpdateUserRequest` só pede nome e telefone válido).
- Se o toast de erro da troca mostra a mensagem do servidor (`err?.message` do SDK), como nos outros erros do Entregas.
- O bottom sheet por cima do mapa numa aba (gestos do mapa × arrastar da folha).
- Troca de motoboy de pedido iFood depois do `goingToOrigin` (já na lista "a conferir" da etapa 3: o 409 vira aviso à
  central).

## Arquivos

| Arquivo | Ação | Papel |
|---|---|---|
| app `src/utils/lista-de-pedidos.ts` | Criar | Número, loja da coleta e seções da aba Pedidos |
| app `scripts/testes/lista-de-pedidos.teste.ts` | Criar | Testes delas |
| app `src/components/OrderCard.tsx` | Modificar | Card enxuto: número e loja da coleta |
| app `src/screens/DriverOrderManagementScreen.tsx` | Modificar | Duas seções, sem calendário |
| app `src/utils/mapa-do-lider.ts` | Criar | Funções puras do mapa do líder |
| app `scripts/testes/mapa-do-lider.teste.ts` | Criar | Testes delas |
| app `src/screens/MapaDoLiderScreen.tsx` | Criar | Tela da aba Mapa |
| app `src/hooks/use-lider.ts` | Criar | Acesso do líder (em memória) |
| app `src/navigation/DriverNavigator.tsx`, `config/default.js` | Modificar | Aba Mapa no lugar de Relatórios |
| app `src/layouts/DriverLayout.tsx` | Modificar | Acompanha o acesso; push do pedido trocado |
| app `translations/pt.json`, `translations/en.json` | Modificar | Textos |
| `api/app/Support/Entregas/LiderDosMotoboys.php` | Criar | Quem é líder |
| `api/app/Support/Entregas/PedidosNoMapa.php` | Modificar | `doLider`, `umDoLider` |
| `api/app/Support/Entregas/MotoboysNoMapaDaLoja.php` | Modificar | `noMapa` (regra compartilhada) |
| `api/app/Support/Entregas/MapaDoLider.php` | Criar | Resposta do mapa do líder |
| `api/app/Notifications/Entregas/PedidoPassadoParaOutro.php` | Criar | Push ao motoboy anterior |
| `api/app/Notifications/Entregas/AvisosDoMotoboy.php` | Modificar | Texto e canal do push novo |
| `api/app/Support/Entregas/TrocaDoMotoboy.php` | Criar | A troca do motoboy |
| `api/app/Http/Controllers/Entregas/LiderController.php` | Criar | Rotas `v1/entregas/lider/*` |
| `api/app/Providers/RouteServiceProvider.php` | Modificar | Rotas e limitador `entregas-lider` |
| `scripts/teste-php/lider.php` | Criar | Testes do líder, da troca e das rotas |
| `scripts/teste-php/pedidos-no-mapa.php`, `mapa-da-loja.php`, `avisos-push.php` | Modificar | Testes das partes reaproveitadas |
| `CLAUDE.md` | Modificar | Documentação |

---

# Parte A: aba Pedidos (app)

### Task 1: app, ramo novo e as funções puras da aba Pedidos

**Files (repo `entregas-navigator`):**
- Create: `src/utils/lista-de-pedidos.ts`
- Create: `scripts/testes/lista-de-pedidos.teste.ts`

- [ ] **Step 1: o ramo**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && git status --short && git checkout -b app-pedidos-e-mapa-do-lider ifood-etapa-4
```

Expected: `git status` vazio e `Switched to a new branch 'app-pedidos-e-mapa-do-lider'`.

- [ ] **Step 2: o teste**

Crie `scripts/testes/lista-de-pedidos.teste.ts`:

```ts
// Testes das funções puras da aba Pedidos (src/utils/lista-de-pedidos.ts), com o Node puro:
//   node --experimental-strip-types --test scripts/testes/lista-de-pedidos.teste.ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ehPedidoAberto, lojaDaColeta, numeroDoPedido, pedidoEncerrado, secoesDaLista } from '../../src/utils/lista-de-pedidos.ts';

// como o recurso Order do SDK: getAttribute com caminho de pontos
const recurso = (atributos: Record<string, any>) => ({
    id: atributos.id,
    getAttribute: (caminho: string) => caminho.split('.').reduce((atual: any, chave) => (atual == null ? undefined : atual[chave]), atributos),
});

test('número do pedido: iFood, rastreio ou id', () => {
    assert.equal(numeroDoPedido(recurso({ id: 'order_1', internal_id: '4821', tracking_number: { tracking_number: 'RP1' } })), '4821');
    assert.equal(numeroDoPedido(recurso({ id: 'order_1', internal_id: null, tracking_number: { tracking_number: 'RP1' } })), 'RP1');
    assert.equal(numeroDoPedido({ id: 'order_1', internal_id: '  ', tracking_number: null }), 'order_1');
    assert.equal(numeroDoPedido(null), null);
});

test('loja da coleta: o Local da loja, o cliente ou nada', () => {
    assert.equal(lojaDaColeta(recurso({ payload: { pickup: { name: 'Pizzaria Boa' } }, customer: { name: 'Fornecedor' } })), 'Pizzaria Boa');
    assert.equal(lojaDaColeta({ payload: { pickup: { name: '' } }, customer: { name: 'Pizzaria Boa' } }), 'Pizzaria Boa');
    assert.equal(lojaDaColeta({ payload: { pickup: null }, customer: null }), null);
    assert.equal(lojaDaColeta({}), null);
});

test('pedido aberto e encerrado', () => {
    assert.equal(ehPedidoAberto({ adhoc: true, driver_assigned: null }), true);
    assert.equal(ehPedidoAberto({ adhoc: true }), true);
    assert.equal(ehPedidoAberto({ adhoc: true, driver_assigned: { id: 'driver_1' } }), false);
    assert.equal(ehPedidoAberto({ adhoc: false, driver_assigned: null }), false);
    assert.equal(pedidoEncerrado({ status: 'completed' }), true);
    assert.equal(pedidoEncerrado({ status: 'CANCELED' }), true);
    assert.equal(pedidoEncerrado({ status: 'started' }), false);
});

test('seções: novos e em andamento, sem repetir e sem vazias', () => {
    const aberto = { id: 'order_a', adhoc: true, driver_assigned: null };
    const dispensado = { id: 'order_b', adhoc: true, driver_assigned: null };
    const jaMeu = { id: 'order_c', adhoc: true, driver_assigned: null };
    const meu = { id: 'order_c', adhoc: false, driver_assigned: { id: 'driver_1' }, status: 'started' };
    const concluido = { id: 'order_d', status: 'completed' };
    const secoes = secoesDaLista([aberto, dispensado, jaMeu], [meu, concluido], ['order_b']);
    assert.deepEqual(secoes, [
        { chave: 'novos', data: [aberto] },
        { chave: 'andamento', data: [meu] },
    ]);
    assert.deepEqual(secoesDaLista([], [meu]), [{ chave: 'andamento', data: [meu] }]);
    assert.deepEqual(secoesDaLista([aberto], []), [{ chave: 'novos', data: [aberto] }]);
    assert.deepEqual(secoesDaLista([], []), []);
    assert.deepEqual(secoesDaLista([dispensado], [concluido], ['order_b']), []);
});
```

- [ ] **Step 3: rodar e ver falhar**

Run: `node --experimental-strip-types --test scripts/testes/lista-de-pedidos.teste.ts`
Expected: falha com `ERR_MODULE_NOT_FOUND` (`.../src/utils/lista-de-pedidos.ts`).

- [ ] **Step 4: as funções**

Crie `src/utils/lista-de-pedidos.ts`:

```ts
// Entregas: a aba Pedidos do app (DriverOrderManagementScreen e OrderCard). A lista tem duas seções: os pedidos novos
// para aceitar (abertos por perto) e os pedidos dele em andamento, de qualquer dia (o histórico fica no Início, Meus
// ganhos). O card mostra o número do pedido e a loja da coleta.
// Os pedidos chegam como recurso do SDK (getAttribute) ou serializados (objeto comum): as funções leem os dois.
// Sem imports, para os testes rodarem com o Node puro: node --experimental-strip-types --test scripts/testes/lista-de-pedidos.teste.ts

export type SecaoDaLista<T> = { chave: 'novos' | 'andamento'; data: T[] };

/** Status em que o pedido já acabou (a lista de em andamento do servidor já os tira; aqui é a garantia do cache local). */
export const ENCERRADOS = ['completed', 'done', 'canceled', 'cancelled', 'order_canceled', 'expired'];

const ler = (pedido: any, caminho: string): any => {
    if (!pedido) return undefined;
    if (typeof pedido.getAttribute === 'function') return pedido.getAttribute(caminho);
    return caminho.split('.').reduce((atual: any, chave) => (atual === null || atual === undefined ? undefined : atual[chave]), pedido);
};

const texto = (valor: unknown): string | null => {
    if (valor === null || valor === undefined) return null;
    const limpo = String(valor).trim();
    return limpo === '' ? null : limpo;
};

const idDe = (pedido: any): string => texto(pedido?.id) ?? texto(ler(pedido, 'id')) ?? '';

/** O número do pedido no card: o do iFood (internal_id); sem ele (pedido da central ou do portal), o código de rastreio. */
export function numeroDoPedido(pedido: any): string | null {
    return texto(ler(pedido, 'internal_id')) ?? texto(ler(pedido, 'tracking_number.tracking_number')) ?? texto(idDe(pedido));
}

/** A loja da coleta: o nome do Local de coleta (o Local da loja); sem ele, o cliente do pedido (o Fornecedor da loja). */
export function lojaDaColeta(pedido: any): string | null {
    return texto(ler(pedido, 'payload.pickup.name')) ?? texto(ler(pedido, 'customer.name'));
}

/** Pedido aberto (ad hoc) ainda sem motoboy: o card de aceitar. */
export function ehPedidoAberto(pedido: any): boolean {
    const motoboy = ler(pedido, 'driver_assigned');
    return ler(pedido, 'adhoc') === true && (motoboy === null || motoboy === undefined);
}

export function pedidoEncerrado(pedido: any): boolean {
    return ENCERRADOS.includes(String(ler(pedido, 'status') ?? '').toLowerCase());
}

/**
 * As seções da aba Pedidos: "novos" (abertos por perto, menos os dispensados e os que já são dele) e "andamento" (os dele,
 * não encerrados). Seção vazia não entra; as duas vazias = lista vazia (a tela mostra "Nenhum pedido no momento").
 */
export function secoesDaLista<T>(proximos: T[], ativos: T[], dispensados: string[] = []): SecaoDaLista<T>[] {
    const emAndamento = (ativos ?? []).filter((pedido) => !pedidoEncerrado(pedido));
    const meus = new Set(emAndamento.map(idDe));
    const novos = (proximos ?? []).filter((pedido) => ehPedidoAberto(pedido) && !dispensados.includes(idDe(pedido)) && !meus.has(idDe(pedido)));

    const secoes: SecaoDaLista<T>[] = [];
    if (novos.length) secoes.push({ chave: 'novos', data: novos });
    if (emAndamento.length) secoes.push({ chave: 'andamento', data: emAndamento });
    return secoes;
}
```

- [ ] **Step 5: rodar e ver passar**

Run: `node --experimental-strip-types --test scripts/testes/lista-de-pedidos.teste.ts && node --experimental-strip-types --test scripts/testes/*.teste.ts`
Expected: `# fail 0` nos dois (o segundo roda todos os testes do app).

- [ ] **Step 6: commit (repo do app)**

```bash
git rev-parse --show-toplevel
git add src/utils/lista-de-pedidos.ts scripts/testes/lista-de-pedidos.teste.ts
git commit -m "Aba Pedidos: funções puras (número do pedido, loja da coleta e as seções novos/em andamento)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: app, card do pedido enxuto

**Files (repo `entregas-navigator`):**
- Modify: `src/components/OrderCard.tsx` (arquivo inteiro)
- Modify: `translations/pt.json`, `translations/en.json`

- [ ] **Step 1: o card**

Substitua o conteúdo de `src/components/OrderCard.tsx` por (saem os campos Cliente, Comprovação, Tempo estimado, Agendado
para, Despachado em e Previsão de conclusão, e os imports que só eles usavam; entra a loja da coleta):

```tsx
import React from 'react';
import { Pressable } from 'react-native';
import { YStack, XStack, Text, useTheme } from 'tamagui';
import { FontAwesomeIcon } from '@fortawesome/react-native-fontawesome';
import { faBox } from '@fortawesome/free-solid-svg-icons';
import { dateFnsLocaleOptions } from '../utils/localize';
import { useLanguage } from '../contexts/LanguageContext';
import { format as formatDate } from 'date-fns';
import { lojaDaColeta, numeroDoPedido } from '../utils/lista-de-pedidos';
import useOrderResource from '../hooks/use-order-resource';
import useAppTheme from '../hooks/use-app-theme';
import OrderProgressBar from './OrderProgressBar';
import LiveOrderRoute from './LiveOrderRoute';
import OrderWaypointList from './OrderWaypointList';
import Badge from './Badge';

// Entregas: card do pedido em andamento na aba Pedidos. Título = número do pedido (o do iFood; sem ele, o código de
// rastreio) e, no lugar dos campos do Fleetbase (cliente, comprovação, tempos e datas), a loja da coleta.
export const OrderCard = ({ order, onPress }) => {
    const theme = useTheme();
    const { isDarkMode } = useAppTheme();
    const { t } = useLanguage();
    const { trackerData } = useOrderResource(order, { loadEta: false });
    const loja = lojaDaColeta(order);

    return (
        <Pressable onPress={onPress}>
            <YStack bg='$background' borderRadius='$4' borderWidth={1} borderColor='$borderColor' gap='$3'>
                <XStack justifyContent='space-between' px='$3' py='$3' bg='$background' borderTopLeftRadius='$4' borderTopRightRadius='$4' borderBottomWidth={1} borderColor='$borderColor'>
                    <XStack flex={1} gap='$2'>
                        <XStack borderRadius='$4' width={32} height={32} bg={isDarkMode ? '$info' : '$blue-600'} alignItems='center' justifyContent='center'>
                            <FontAwesomeIcon icon={faBox} color={isDarkMode ? theme.textPrimary.val : theme.surface.val} size={14} />
                        </XStack>
                        <YStack flex={1}>
                            <Text color='$textPrimary' fontSize={16} fontWeight='bold'>
                                {numeroDoPedido(order) ?? t('OrderCard.notAvailable')}
                            </Text>
                            <Text color='$textPrimary' fontSize={12}>
                                {formatDate(new Date(order.getAttribute('created_at')), t('OrderCard.dateTimeFormat'), dateFnsLocaleOptions())}
                            </Text>
                        </YStack>
                    </XStack>
                    <XStack>
                        <Badge status={order.getAttribute('status')} />
                    </XStack>
                </XStack>
                <YStack px='$3' pb='$3' gap='$3'>
                    <YStack>
                        <LiveOrderRoute
                            order={order}
                            zoom={7}
                            height={150}
                            edgePaddingTop={70}
                            edgePaddingBottom={30}
                            edgePaddingLeft={30}
                            edgePaddingRight={30}
                            width='100%'
                            borderRadius='$4'
                            scrollEnabled={false}
                        />
                    </YStack>
                    <YStack>
                        <OrderWaypointList order={order} />
                    </YStack>
                    <OrderProgressBar
                        order={order}
                        progress={trackerData.progress_percentage}
                        firstWaypointCompleted={trackerData.first_waypoint_completed}
                        lastWaypointCompleted={trackerData.last_waypoint_completed}
                    />
                    <YStack gap='$1'>
                        <Text color='$textPrimary' fontSize={12}>
                            {t('OrderCard.coleta')}
                        </Text>
                        <Text color='$textSecondary' fontSize={14} fontWeight='bold' numberOfLines={1}>
                            {loja ?? '—'}
                        </Text>
                    </YStack>
                </YStack>
            </YStack>
        </Pressable>
    );
};

export default OrderCard;
```

- [ ] **Step 2: o texto "Coleta"**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && node - <<'EOF'
const fs = require('fs');
const textos = {
    'translations/pt.json': { OrderCard: { coleta: 'Coleta' } },
    'translations/en.json': { OrderCard: { coleta: 'Pickup' } },
};
const juntar = (alvo, novo) => {
    for (const [chave, valor] of Object.entries(novo)) {
        alvo[chave] = valor && typeof valor === 'object' && !Array.isArray(valor) ? juntar(alvo[chave] ?? {}, valor) : valor;
    }
    return alvo;
};
for (const [arquivo, novo] of Object.entries(textos)) {
    const json = JSON.parse(fs.readFileSync(arquivo, 'utf8'));
    fs.writeFileSync(arquivo, JSON.stringify(juntar(json, novo), null, 4) + '\n');
    console.log('OK', arquivo);
}
EOF
git diff --stat translations/
```

Expected: `OK` nos dois e `2 files changed, 4 insertions(+), 2 deletions(-)` (em cada arquivo, a linha nova e a vírgula na linha de antes; as chaves antigas do card ficam).

- [ ] **Step 3: conferir**

```bash
B=$(ls -d "/c/Users/Edgardjr/Documents/vibe coding/Delivery/console/node_modules/.pnpm"/@babel+parser@7*/node_modules/@babel/parser | head -1)
node -e "const p=require(require('path').resolve(process.argv[1]));for(const f of process.argv.slice(2)){p.parse(require('fs').readFileSync(f,'utf8'),{sourceType:'module',plugins:['typescript','jsx']});console.log('OK',f)}" "$B" src/components/OrderCard.tsx
```

Expected: `OK src/components/OrderCard.tsx`.

- [ ] **Step 4: commit (repo do app)**

```bash
git rev-parse --show-toplevel
git add src/components/OrderCard.tsx translations/pt.json translations/en.json
git commit -m "Aba Pedidos: card com o número do pedido e a loja da coleta no lugar dos campos do Fleetbase

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: app, aba Pedidos com as duas seções

**Files (repo `entregas-navigator`):**
- Modify: `src/screens/DriverOrderManagementScreen.tsx` (arquivo inteiro)
- Modify: `translations/pt.json`, `translations/en.json`

- [ ] **Step 1: a tela**

Substitua o conteúdo de `src/screens/DriverOrderManagementScreen.tsx` por (saem o `CalendarStrip`, o cabeçalho do dia, o
`currentDate`, o `activeOrderMarkedDates` e o `PastOrderCard`; a lista vira um `SectionList` com as seções da Task 1; os
intervalos, o push e o socket recarregam o `allActiveOrders` no lugar do `currentOrders`):

```tsx
import { useRef, useEffect, useCallback } from 'react';
import { useNavigation, useFocusEffect } from '@react-navigation/native';
import { SectionList, RefreshControl } from 'react-native';
import { Text, YStack, XStack, useTheme } from 'tamagui';
import { FontAwesomeIcon } from '@fortawesome/react-native-fontawesome';
import { faInfoCircle } from '@fortawesome/free-solid-svg-icons';
import { useOrderManager } from '../contexts/OrderManagerContext';
import { useNotification } from '../contexts/NotificationContext';
import { useAuth } from '../contexts/AuthContext';
import { useLanguage } from '../contexts/LanguageContext';
import { secoesDaLista } from '../utils/lista-de-pedidos';
import useSocketClusterClient from '../hooks/use-socket-cluster-client';
import OrderCard from '../components/OrderCard';
import AdhocOrderCard from '../components/AdhocOrderCard';
import Spacer from '../components/Spacer';

// Entregas: a aba Pedidos mostra só o que o motoboy tem para fazer agora, em duas seções: os pedidos novos para aceitar
// (abertos por perto) e os dele em andamento, de qualquer dia. O histórico (o seletor de data e o resumo do dia) saiu: as
// corridas feitas ficam no Início (Meus ganhos).
const REFRESH_NEARBY_ORDERS_MS = 6000 * 5; // 30 s
const REFRESH_ORDERS_MS = 6000 * 15; // 90 s
const DriverOrderManagementScreen = () => {
    const theme = useTheme();
    const navigation = useNavigation();
    const listenerRef = useRef();
    const { driver } = useAuth();
    const { t } = useLanguage();
    const {
        allActiveOrders,
        reloadActiveOrders,
        isFetchingActiveOrders,
        nearbyOrders,
        isFetchingNearbyOrders,
        reloadNearbyOrders,
        removerPedidoProximo,
        dismissedOrders,
        setDimissedOrders,
    } = useOrderManager();
    const { listen } = useSocketClusterClient();
    const { addNotificationListener, removeNotificationListener } = useNotification();

    const recarregarTudo = useCallback(() => {
        reloadNearbyOrders();
        reloadActiveOrders();
    }, [reloadNearbyOrders, reloadActiveOrders]);

    useEffect(() => {
        const handlePushNotification = async (notification, action) => {
            const { payload } = notification;
            const id = payload.id;

            // If any order related push notification comes just reload the orders in progress
            if (typeof id === 'string' && id.startsWith('order_')) {
                reloadActiveOrders();
            }
        };

        addNotificationListener(handlePushNotification);

        return () => {
            removeNotificationListener(handlePushNotification);
        };
    }, [addNotificationListener, removeNotificationListener, reloadActiveOrders]);

    useFocusEffect(
        useCallback(() => {
            const handleReloadNearbyOrders = () => {
                reloadNearbyOrders({}, { setLoadingFlag: false });
            };

            const interval = setInterval(handleReloadNearbyOrders, REFRESH_NEARBY_ORDERS_MS);
            return () => clearInterval(interval);
        }, [])
    );

    useFocusEffect(
        useCallback(() => {
            const handleReloadActiveOrders = () => {
                reloadActiveOrders({}, { setLoadingFlag: false });
            };
            handleReloadActiveOrders();

            const interval = setInterval(handleReloadActiveOrders, REFRESH_ORDERS_MS);
            return () => clearInterval(interval);
        }, [])
    );

    useFocusEffect(
        useCallback(() => {
            const listenForOrderUpdates = async () => {
                const listener = await listen(`driver.${driver.id}`, ({ event }) => {
                    if (typeof event === 'string' && event === 'order.ready') {
                        reloadActiveOrders();
                    }
                    if (typeof event === 'string' && event === 'order.ping') {
                        reloadNearbyOrders();
                    }
                });
                if (listener) {
                    listenerRef.current = listener;
                }
            };

            listenForOrderUpdates();

            return () => {
                if (listenerRef.current) {
                    listenerRef.current.stop();
                }
            };
        }, [listen, driver.id])
    );

    const handleAdhocDismissal = useCallback(
        (order) => {
            setDimissedOrders((prevDismissedOrders) => [...prevDismissedOrders, order.id]);
        },
        [setDimissedOrders]
    );

    const handleAdhocAccept = useCallback(
        (order) => {
            removerPedidoProximo(order?.id);
            reloadNearbyOrders();
            reloadActiveOrders();
        },
        [removerPedidoProximo, reloadNearbyOrders, reloadActiveOrders]
    );

    // Entregas: novos (abertos por perto, menos os dispensados e os que já são dele) e em andamento; seção vazia não aparece
    const secoes = secoesDaLista(nearbyOrders, allActiveOrders, dismissedOrders);

    const renderOrder = ({ item: order, section }) => {
        if (section.chave === 'novos') {
            return (
                <YStack px='$2' py='$3'>
                    <AdhocOrderCard
                        order={order}
                        onPress={() => navigation.navigate('OrderModal', { order: order.serialize() })}
                        onDismiss={handleAdhocDismissal}
                        onAccept={handleAdhocAccept}
                    />
                </YStack>
            );
        }

        return (
            <YStack px='$2' py='$3'>
                <OrderCard order={order} onPress={() => navigation.navigate('Order', { order: order.serialize() })} />
            </YStack>
        );
    };

    const renderSectionHeader = ({ section }) => (
        <YStack px='$3' pt='$4' pb='$1' bg='$surface'>
            <Text color='$textPrimary' fontSize={18} fontWeight='bold'>
                {section.chave === 'novos'
                    ? t('DriverOrderManagementScreen.novosPedidos', { count: section.data.length })
                    : t('DriverOrderManagementScreen.emAndamento', { count: section.data.length })}
            </Text>
        </YStack>
    );

    const NoOrders = () => {
        return (
            <YStack py='$5' px='$3' flex={1} height='100%'>
                <XStack alignItems='center' bg='$info' borderWidth={1} borderColor='$infoBorder' space='$2' px='$3' py='$2' borderRadius='$5' width='100%' flexWrap='wrap'>
                    <FontAwesomeIcon icon={faInfoCircle} color={theme['$infoText'].val} />
                    <Text color='$infoText' fontSize={16}>
                        {t('DriverOrderManagementScreen.nenhumPedido')}
                    </Text>
                </XStack>
            </YStack>
        );
    };

    return (
        <YStack flex={1} bg='$surface'>
            <SectionList
                sections={secoes}
                keyExtractor={(order, index) => order.id.toString() + '_' + index}
                renderItem={renderOrder}
                renderSectionHeader={renderSectionHeader}
                stickySectionHeadersEnabled={false}
                refreshControl={<RefreshControl refreshing={isFetchingActiveOrders || isFetchingNearbyOrders} onRefresh={recarregarTudo} tintColor={theme['$blue-500'].val} />}
                showsVerticalScrollIndicator={false}
                showsHorizontalScrollIndicator={false}
                ListFooterComponent={<Spacer height={200} />}
                ListEmptyComponent={<NoOrders />}
            />
        </YStack>
    );
};

export default DriverOrderManagementScreen;
```

- [ ] **Step 2: os textos**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && node - <<'EOF'
const fs = require('fs');
const textos = {
    'translations/pt.json': {
        DriverOrderManagementScreen: { novosPedidos: 'Novos pedidos ({{count}})', emAndamento: 'Em andamento ({{count}})', nenhumPedido: 'Nenhum pedido no momento' },
    },
    'translations/en.json': {
        DriverOrderManagementScreen: { novosPedidos: 'New orders ({{count}})', emAndamento: 'In progress ({{count}})', nenhumPedido: 'No orders right now' },
    },
};
const juntar = (alvo, novo) => {
    for (const [chave, valor] of Object.entries(novo)) {
        alvo[chave] = valor && typeof valor === 'object' && !Array.isArray(valor) ? juntar(alvo[chave] ?? {}, valor) : valor;
    }
    return alvo;
};
for (const [arquivo, novo] of Object.entries(textos)) {
    const json = JSON.parse(fs.readFileSync(arquivo, 'utf8'));
    fs.writeFileSync(arquivo, JSON.stringify(juntar(json, novo), null, 4) + '\n');
    console.log('OK', arquivo);
}
EOF
```

Expected: `OK` nos dois.

- [ ] **Step 3: conferir**

```bash
B=$(ls -d "/c/Users/Edgardjr/Documents/vibe coding/Delivery/console/node_modules/.pnpm"/@babel+parser@7*/node_modules/@babel/parser | head -1)
node -e "const p=require(require('path').resolve(process.argv[1]));for(const f of process.argv.slice(2)){p.parse(require('fs').readFileSync(f,'utf8'),{sourceType:'module',plugins:['typescript','jsx']});console.log('OK',f)}" "$B" src/screens/DriverOrderManagementScreen.tsx
grep -c "CalendarStrip\|PastOrderCard\|currentOrders" src/screens/DriverOrderManagementScreen.tsx
node --experimental-strip-types --test scripts/testes/*.teste.ts
```

Expected: `OK ...`, `0` e `# fail 0`.

- [ ] **Step 4: commit (repo do app)**

```bash
git rev-parse --show-toplevel
git add src/screens/DriverOrderManagementScreen.tsx translations/pt.json translations/en.json
git commit -m "Aba Pedidos: só novos pedidos e em andamento (sem o seletor de data e o resumo do dia)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

# Parte B: aba Mapa do líder, servidor (`api/app`)

### Task 4: servidor, ramo novo e quem é líder (`LiderDosMotoboys`)

**Files:**
- Create: `api/app/Support/Entregas/LiderDosMotoboys.php`
- Create: `scripts/teste-php/lider.php` (os stubs de todo o arquivo e a primeira seção; a Task 8 completa)

- [ ] **Step 1: o ramo**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/Delivery" && git rev-parse --show-toplevel && git status --short && git checkout -b app-pedidos-e-mapa-do-lider ifood-etapa-4
```

Expected: a raiz do Delivery, `git status` vazio e `Switched to a new branch 'app-pedidos-e-mapa-do-lider'`.

- [ ] **Step 2: o teste**

Crie `scripts/teste-php/lider.php` (os stubs já servem às Tasks 7 e 8: pedido, motoboy, trava, log e notificação):

```php
<?php

// Aba Mapa do líder dos motoboys no app: quem é líder (LiderDosMotoboys), as rotas do LiderController (acesso, mapa e troca
// do motoboy), a troca (TrocaDoMotoboy) com o aviso ao motoboy anterior (PedidoPassadoParaOutro) e a ligação das rotas no
// RouteServiceProvider. O PedidosNoMapa::doLider e o MapaDoLider têm teste próprio (pedidos-no-mapa.php e mapa-da-loja.php).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/lider.php

namespace Illuminate\Http {
    class Request
    {
        public function __construct(private ?string $token = null, private array $entrada = []) {}
        public function bearerToken(): ?string { return $this->token; }
        public function input(string $chave, $padrao = null) { return $this->entrada[$chave] ?? $padrao; }
    }
}

namespace Illuminate\Contracts\Cache {
    class LockTimeoutException extends \Exception {}
}

namespace Illuminate\Support\Facades {
    class Cache
    {
        public static bool $ocupada = false;
        public static array $travas = [];

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

                    return $fazer();
                }
            };
        }
    }

    class Log
    {
        public static array $linhas = [];
        public static function info(string $mensagem, array $contexto = []): void { static::$linhas[] = ['info', $mensagem, $contexto]; }
        public static function warning(string $mensagem, array $contexto = []): void { static::$linhas[] = ['warning', $mensagem, $contexto]; }
    }
}

namespace Illuminate\Notifications { class Notification {} }
namespace Illuminate\Contracts\Queue { interface ShouldQueue {} }
namespace Illuminate\Bus { trait Queueable {} }

namespace Teste {
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

    /** Consulta em memória: devolve os próprios objetos (como o mesmo registro relido do banco). */
    class Consulta
    {
        public static array $registro = [];

        public function __construct(private string $modelo, private array $linhas) {}

        public function where($coluna, $valor = null): static
        {
            if ($coluna instanceof \Closure) {
                $grupo = new Grupo();
                $coluna($grupo);
                $this->linhas = array_filter($this->linhas, fn ($linha) => $grupo->aceita($linha));
                static::$registro[$this->modelo][] = 'where (grupo)';

                return $this;
            }
            static::$registro[$this->modelo][] = "where {$coluna}";
            $this->linhas = array_filter($this->linhas, fn ($linha) => ($linha->$coluna ?? null) === $valor);

            return $this;
        }

        public function with($relacoes): static
        {
            static::$registro[$this->modelo][] = 'with ' . implode(',', (array) $relacoes);

            return $this;
        }

        public function first()
        {
            $linha = reset($this->linhas);

            return $linha === false ? null : $linha;
        }
    }

    abstract class Modelo
    {
        public static function where($coluna, $valor = null)
        {
            return (new Consulta(static::class, static::$todos))->where($coluna, $valor);
        }
    }

    class Resposta
    {
        public function __construct(public $dados, public int $status) {}
    }

    class Fabrica
    {
        public function json($dados, int $status = 200) { return new Resposta(json_decode(json_encode($dados), true), $status); }
    }
}

namespace Fleetbase\Models {
    class User extends \Teste\Modelo
    {
        public static array $todos = [];
        public $uuid;
        public $type = 'user';
        public function isAdmin(): bool { return $this->type === 'admin'; }
    }

    class CompanyUser extends \Teste\Modelo
    {
        public static array $todos = [];
        public $user_uuid;
        public $company_uuid;
        public $status = 'active';
        public array $permissoes = [];
        // como o CompanyUser do core: diretas, dos papéis, das políticas e das políticas dos papéis
        public function getAllPermissions() { return array_map(fn ($nome) => (object) ['name' => $nome], $this->permissoes); }
    }
}

namespace Fleetbase\FleetOps\Models {
    class Driver extends \Teste\Modelo
    {
        public static array $todos = [];
        public $uuid;
        public $public_id;
        public $user_uuid;
        public $company_uuid;
        public $name;
        public array $avisos = [];
        public function notify($aviso): void
        {
            if (\Teste\Falhas::$aviso) {
                throw new \RuntimeException('fila fora');
            }
            $this->avisos[] = $aviso;
        }
    }

    class Order extends \Teste\Modelo
    {
        public static array $todos = [];
        public $uuid;
        public $public_id;
        public $company_uuid;
        public $status = 'started';
        public $adhoc = true;
        public $driver_assigned_uuid = null;
        public $internal_id = null;
        public $trackingNumber = null;
        public array $atribuicoes = [];

        // como o assignDriver do Fleet-Ops: grava o motoboy e salva (o save dispara o OrderObserver)
        public function assignDriver($motoboy, $silencioso = false)
        {
            $this->atribuicoes[] = ['motoboy' => $motoboy->uuid, 'silencioso' => $silencioso, 'adhoc_no_save' => $this->adhoc];
            $this->driver_assigned_uuid = $motoboy->uuid;

            return $this;
        }
    }
}

namespace Teste {
    class Falhas
    {
        public static bool $aviso = false;
    }
}

namespace App\Http\Controllers {
    class Controller {}
}

namespace App\Support\Entregas {
    // dublês: os dois têm teste próprio (pedidos-no-mapa.php e mapa-da-loja.php)
    class PedidosNoMapa
    {
        public const RELACOES_DO_LIDER = ['payload.pickup', 'payload.dropoff', 'driverAssigned.user', 'trackingNumber'];
        public static function umDoLider($pedido): ?array { return ['id' => $pedido->public_id, 'motoboy_uuid' => $pedido->driver_assigned_uuid]; }
    }

    class MapaDoLider
    {
        public static array $chamadas = [];
        public static function mapa(string $empresa): array { static::$chamadas[] = $empresa; return ['pedidos' => [['id' => 'order_1']], 'motoboys' => [['id' => 'driver_b']]]; }
    }
}

namespace {
    use App\Http\Controllers\Entregas\LiderController;
    use App\Notifications\Entregas\PedidoPassadoParaOutro;
    use App\Support\Entregas\LiderDosMotoboys;
    use Fleetbase\FleetOps\Models\Driver;
    use Fleetbase\FleetOps\Models\Order;
    use Fleetbase\Models\CompanyUser;
    use Fleetbase\Models\User;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\Log;

    require '/repo/api/app/Support/Entregas/MotoboyDaSessao.php';
    require '/repo/api/app/Support/Entregas/LiderDosMotoboys.php';

    const EMPRESA = 'empresa-a';
    $sessao = ['company' => EMPRESA, 'user' => 'u-lider'];

    function session($chave) { global $sessao; return $sessao[$chave] ?? null; }
    function response() { return new \Teste\Fabrica(); }

    $falhas = 0;
    function confere(bool $ok, string $caso): void
    {
        global $falhas;
        if (!$ok) {
            $falhas++;
        }
        echo ($ok ? 'PASSA ' : 'FALHA ') . $caso . PHP_EOL;
    }

    function usuario(string $uuid, string $tipo = 'user'): User
    {
        $u       = new User();
        $u->uuid = $uuid;
        $u->type = $tipo;

        return $u;
    }

    function vinculo(string $usuario, array $permissoes, string $status = 'active', string $empresa = EMPRESA): CompanyUser
    {
        $v               = new CompanyUser();
        $v->user_uuid    = $usuario;
        $v->company_uuid = $empresa;
        $v->status       = $status;
        $v->permissoes   = $permissoes;

        return $v;
    }

    function motoboy(string $uuid, string $usuario, string $nome, string $empresa = EMPRESA): Driver
    {
        $m               = new Driver();
        $m->uuid         = $uuid;
        $m->public_id    = 'driver_' . substr($uuid, 2);
        $m->user_uuid    = $usuario;
        $m->company_uuid = $empresa;
        $m->name         = $nome;

        return $m;
    }

    function pedido(string $uuid, string $status, ?string $motoboy, array $extra = []): Order
    {
        $p                       = new Order();
        $p->uuid                 = $uuid;
        $p->public_id            = 'order_' . substr($uuid, 2);
        $p->company_uuid         = $extra['company_uuid'] ?? EMPRESA;
        $p->status               = $status;
        $p->driver_assigned_uuid = $motoboy;
        $p->adhoc                = $extra['adhoc'] ?? false;
        $p->internal_id          = $extra['internal_id'] ?? null;
        $p->trackingNumber       = isset($extra['rastreio']) ? (object) ['tracking_number' => $extra['rastreio']] : null;

        return $p;
    }

    $token = new Request('12|token-do-motoboy');

    echo '== LiderDosMotoboys::ehLider' . PHP_EOL;
    CompanyUser::$todos = [
        vinculo('u-lider', ['fleet-ops see order', 'fleet-ops assign-driver-for order']),
        vinculo('u-curinga-recurso', ['fleet-ops * order']),
        vinculo('u-curinga-servico', ['fleet-ops *']),
        vinculo('u-comum', ['fleet-ops see order', 'fleet-ops list order', 'fleet-ops assign-vehicle-for order']),
        vinculo('u-desativado', ['fleet-ops assign-driver-for order'], 'inactive'),
        vinculo('u-admin', []),
        vinculo('u-admin-desativado', [], 'inactive'),
        vinculo('u-outra-empresa', ['fleet-ops assign-driver-for order'], 'active', 'empresa-b'),
    ];
    confere(LiderDosMotoboys::ehLider(usuario('u-lider'), EMPRESA), 'com a permissão "fleet-ops assign-driver-for order" (papel, política ou direta): líder');
    confere(LiderDosMotoboys::ehLider(usuario('u-curinga-recurso'), EMPRESA) && LiderDosMotoboys::ehLider(usuario('u-curinga-servico'), EMPRESA), 'curingas "fleet-ops * order" e "fleet-ops *": líder');
    confere(!LiderDosMotoboys::ehLider(usuario('u-comum'), EMPRESA), 'motoboy comum (permissões do papel Driver): não é líder');
    confere(LiderDosMotoboys::ehLider(usuario('u-admin', 'admin'), EMPRESA), 'administrador: líder');
    confere(!LiderDosMotoboys::ehLider(usuario('u-desativado'), EMPRESA) && !LiderDosMotoboys::ehLider(usuario('u-admin-desativado', 'admin'), EMPRESA), 'vínculo desativado no IAM: não é líder (nem o admin)');
    confere(!LiderDosMotoboys::ehLider(usuario('u-outra-empresa'), EMPRESA), 'permissão só em outra empresa: não é líder na da sessão');
    confere(!LiderDosMotoboys::ehLider(usuario('u-sem-vinculo'), EMPRESA) && !LiderDosMotoboys::ehLider(null, EMPRESA) && !LiderDosMotoboys::ehLider(usuario('u-lider'), ''), 'sem vínculo, sem usuário ou sem empresa: não é líder');

    echo PHP_EOL . "FALHAS: {$falhas}" . PHP_EOL;
}
```

- [ ] **Step 3: rodar e ver falhar**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/lider.php`
Expected: erro fatal `Failed opening required '/repo/api/app/Support/Entregas/LiderDosMotoboys.php'` (sai com 1).

- [ ] **Step 4: a regra**

Crie `api/app/Support/Entregas/LiderDosMotoboys.php`:

```php
<?php

namespace App\Support\Entregas;

use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: quem é o líder dos motoboys, que vê a aba Mapa do app (pedidos em andamento e motoboys no mapa)
 * e passa um pedido para outro motoboy (LiderController; desenho:
 * docs/superpowers/specs/2026-10-06-app-pedidos-e-mapa-do-lider-design.md).
 *
 * Líder = o motoboy da sessão (token do app, MotoboyDaSessao; nunca a chave flb_live_ do APK, que autentica como o admin
 * que a criou) que é administrador ou tem, na empresa da sessão, a permissão do Fleet-Ops "fleet-ops assign-driver-for
 * order" ou um curinga dela ("fleet-ops * order", "fleet-ops *"). A central dá a permissão em Admin → IAM → Usuários →
 * Motoristas, pelo papel "Líder de motoboys" (ver CLAUDE.md, "App do motoboy").
 *
 * Por que não o Fleetbase\Support\Auth::can: ele chama o hasPermissionTo do Spatie, que só vê as permissões diretas e as
 * dos papéis; a permissão que chega por uma política (do usuário ou do papel) fica de fora. Aqui vale a regra do
 * AuthorizationGuard do console: CompanyUser::getAllPermissions (diretas, papéis, políticas e políticas dos papéis), do
 * vínculo do usuário com a empresa da sessão. Vínculo desativado (IAM → Desativar usuário) não é líder.
 */
class LiderDosMotoboys
{
    public const PERMISSOES = ['fleet-ops assign-driver-for order', 'fleet-ops * order', 'fleet-ops *'];

    public static function daSessao(Request $request): bool
    {
        if (!MotoboyDaSessao::motoboy($request)) {
            return false;
        }

        $usuario = User::where('uuid', (string) session('user'))->first();

        return static::ehLider($usuario, (string) session('company'));
    }

    public static function ehLider(?User $usuario, string $empresaUuid): bool
    {
        if (!$usuario || $empresaUuid === '') {
            return false;
        }

        $vinculo = CompanyUser::where('user_uuid', $usuario->uuid)->where('company_uuid', $empresaUuid)->first();
        if ($vinculo && $vinculo->status === 'inactive') {
            return false;
        }

        if ($usuario->isAdmin()) {
            return true;
        }

        if (!$vinculo) {
            return false;
        }

        $nomes = [];
        foreach ($vinculo->getAllPermissions() as $permissao) {
            $nomes[] = (string) $permissao->name;
        }

        return array_intersect(static::PERMISSOES, $nomes) !== [];
    }
}
```

- [ ] **Step 5: rodar e ver passar**

```bash
PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/lider.php
PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/LiderDosMotoboys.php
```

Expected: 7 `PASSA` e `FALHAS: 0`; `OK   api/app/Support/Entregas/LiderDosMotoboys.php`.

- [ ] **Step 6: commit**

```bash
git rev-parse --show-toplevel
git add api/app/Support/Entregas/LiderDosMotoboys.php scripts/teste-php/lider.php
git commit -m "Líder dos motoboys: regra de acesso (admin ou permissão fleet-ops assign-driver-for order no CompanyUser da sessão)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: servidor, pedidos do mapa do líder (`PedidosNoMapa::doLider`)

**Files:**
- Modify: `api/app/Support/Entregas/PedidosNoMapa.php`
- Modify: `scripts/teste-php/pedidos-no-mapa.php`

- [ ] **Step 1: o teste**

Em `scripts/teste-php/pedidos-no-mapa.php`:

1. No helper `pedido()`, troque

```php
            'driverAssigned' => null, 'trackingNumber' => null, 'started' => false,
```

por

```php
            'driverAssigned' => null, 'trackingNumber' => null, 'started' => false, 'internal_id' => null,
```

2. No pedido `order_sem_loja`, a coleta ganha `'location' => null` (o `doLider` lê a coordenada da coleta; o Place de
   verdade sempre tem o campo). Troque

```php
'pickup' => (object) ['name' => 'Pizzaria Sem Cadastro']
```

por

```php
'pickup' => (object) ['name' => 'Pizzaria Sem Cadastro', 'location' => null]
```

3. Antes da linha `    echo '== limite' . PHP_EOL;`, acrescente:

```php
    echo '== PedidosNoMapa::doLider e umDoLider (aba Mapa do líder dos motoboys no app)' . PHP_EOL;
    $lojaA = lugar(-21.17, -47.80, 'Av. da Loja, 1', 'Loja A');
    Order::$todos[] = pedido('order_ifood', 'started', 'vendor-a', $recente, '2026-10-05 08:25:00', $rua, [
        'internal_id' => '4821', 'trackingNumber' => (object) ['tracking_number' => 'RP999'], 'driverAssigned' => $motoboy, 'started' => true,
        'payload' => (object) ['dropoff' => $rua, 'pickup' => $lojaA],
    ]);
    \Teste\Consulta::$registro = [];
    $lider    = PedidosNoMapa::doLider(EMPRESA);
    $doLider  = array_column($lider, null, 'id');
    confere(array_column($lider, 'id') === ['order_ifood', 'order_entrega', 'order_coleta', 'order_sem_motoboy', 'order_sem_loja'],
        'os mesmos pedidos da central (em andamento, com ou sem motoboy), dos mais novos para os mais antigos');
    confere(array_keys($lider[0] ?? []) === ['id', 'numero', 'latitude', 'longitude', 'endereco', 'status', 'motoboy', 'aceito', 'criado_em', 'loja', 'motoboy_id', 'atualizado_em', 'coleta'],
        'campos da central, mais motoboy_id, atualizado_em e coleta');
    confere(($doLider['order_ifood']['numero'] ?? null) === '4821' && ($doLider['order_sem_motoboy']['numero'] ?? null) === 'RP123' && ($doLider['order_coleta']['numero'] ?? null) === 'order_coleta',
        'número = internal_id (iFood); sem ele, o de rastreio; sem os dois, o public_id');
    confere(($doLider['order_ifood']['motoboy_id'] ?? null) === 'driver_m1' && array_key_exists('motoboy_id', $doLider['order_sem_motoboy'] ?? []) && $doLider['order_sem_motoboy']['motoboy_id'] === null,
        'motoboy_id = public_id do motoboy (para a troca); sem motoboy, null');
    confere(($doLider['order_ifood']['coleta'] ?? null) === ['latitude' => -21.17, 'longitude' => -47.8] && array_key_exists('coleta', $doLider['order_coleta'] ?? []) && $doLider['order_coleta']['coleta'] === null,
        'coleta com as coordenadas do Local da loja; sem coordenada, null');
    confere(($doLider['order_ifood']['atualizado_em'] ?? null) === '2026-10-05T08:30:00-03:00', 'atualizado_em em ISO 8601, no fuso do app (o "há X min" do cartão)');
    confere(($doLider['order_ifood']['loja'] ?? null) === 'Loja A' && ($doLider['order_sem_loja']['loja'] ?? null) === 'Pizzaria Sem Cadastro', 'loja como na central');
    $registro = \Teste\Consulta::$registro[Order::class] ?? [];
    confere(in_array('where company_uuid =', $registro, true) && in_array('permissao fleet-ops list order', $registro, true) && in_array('limit 300', $registro, true),
        'mesma consulta da central: empresa, permissão do Fleet-Ops e limite de 300');
    confere(in_array('with ' . implode(',', PedidosNoMapa::RELACOES_DO_LIDER), $registro, true), 'carrega coleta, destino, motoboy e rastreio juntos');
    $um = PedidosNoMapa::umDoLider(end(Order::$todos));
    confere($um === ($doLider['order_ifood'] ?? false), 'umDoLider: o mesmo item do doLider');
    confere(PedidosNoMapa::umDoLider(pedido('order_sem_destino_2', 'started', 'vendor-a', $recente, '2026-10-05 08:00:00', null)) === null, 'umDoLider sem destino com coordenada: null');
```

- [ ] **Step 2: rodar e ver falhar**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/pedidos-no-mapa.php`
Expected: erro fatal `Call to undefined method App\Support\Entregas\PedidosNoMapa::doLider()`.

- [ ] **Step 3: o código**

Em `api/app/Support/Entregas/PedidosNoMapa.php`:

1. No docblock da classe, troque

```php
 * - daLoja: só os pedidos dos donos informados (a loja da sessão e o contato do usuário), sem o nome da loja
 *   (PortalLojaController@motoboysNoMapa). A loja nunca recebe pedido, endereço ou cliente de outra loja (LGPD).
```

por

```php
 * - daLoja: só os pedidos dos donos informados (a loja da sessão e o contato do usuário), sem o nome da loja
 *   (PortalLojaController@motoboysNoMapa). A loja nunca recebe pedido, endereço ou cliente de outra loja (LGPD).
 * - doLider: os pedidos de daCentral para a aba Mapa do líder dos motoboys no app (LiderController), com o número do
 *   pedido (internal_id, o número do iFood), o public_id do motoboy (para a troca), a coleta e a hora da última
 *   atualização. É a exceção à regra do id do motoboy abaixo: o líder faz o papel da central (LiderDosMotoboys).
 *   umDoLider: um pedido nesse formato (resposta da troca do motoboy).
```

2. Logo depois de `    public const LIMITE = 300;`, acrescente:

```php

    /** Relações que o doLider e o umDoLider leem (coleta, destino, motoboy com o nome e o número de rastreio). */
    public const RELACOES_DO_LIDER = ['payload.pickup', 'payload.dropoff', 'driverAssigned.user', 'trackingNumber'];
```

3. Antes de `    protected static function consulta(string $companyUuid)`, acrescente:

```php
    /**
     * @return array<int, array{id: string, numero: string, latitude: float, longitude: float, endereco: ?string, status: ?string, motoboy: ?string, aceito: bool, criado_em: ?string, loja: ?string, motoboy_id: ?string, atualizado_em: ?string, coleta: ?array{latitude: float, longitude: float}}>
     */
    public static function doLider(string $companyUuid): array
    {
        $pedidos = static::consulta($companyUuid)
            ->applyDirectivesForPermissions('fleet-ops list order')
            ->with(static::RELACOES_DO_LIDER)
            ->get();
        $lojas = static::nomesDasLojas($pedidos);

        return static::itens($pedidos, fn ($pedido) => static::doLiderExtra($pedido, $lojas));
    }

    /** Um pedido no formato do doLider (o pedido já vem com as RELACOES_DO_LIDER); null se o destino não tem coordenada. */
    public static function umDoLider($pedido): ?array
    {
        $lojas = static::nomesDasLojas([$pedido]);

        return static::itens([$pedido], fn ($pedido) => static::doLiderExtra($pedido, $lojas))[0] ?? null;
    }

    protected static function doLiderExtra($pedido, array $lojas): array
    {
        $coleta    = $pedido->payload?->pickup;
        $latitude  = $coleta?->location?->getLat();
        $longitude = $coleta?->location?->getLng();

        return [
            // o número que o motoboy e a loja conhecem: o do iFood (internal_id); sem ele, o de rastreio
            'numero'        => $pedido->internal_id ?: ($pedido->trackingNumber?->tracking_number ?: $pedido->public_id),
            'loja'          => static::nomeDaLoja($pedido, $lojas),
            'motoboy_id'    => $pedido->driverAssigned?->public_id,
            'atualizado_em' => $pedido->updated_at?->toIso8601String(),
            'coleta'        => Coordenada::valida($latitude, $longitude) ? ['latitude' => (float) $latitude, 'longitude' => (float) $longitude] : null,
        ];
    }
```

- [ ] **Step 4: rodar e ver passar**

```bash
PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/pedidos-no-mapa.php
PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/mapa.php
PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/PedidosNoMapa.php
```

Expected: `FALHAS: 0` nos dois (34 `PASSA` no primeiro) e `OK`.

- [ ] **Step 5: commit**

```bash
git rev-parse --show-toplevel
git add api/app/Support/Entregas/PedidosNoMapa.php scripts/teste-php/pedidos-no-mapa.php
git commit -m "Líder dos motoboys: pedidos do mapa (PedidosNoMapa::doLider e umDoLider, com número, motoboy e coleta)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: servidor, motoboys do mapa do líder (`MotoboysNoMapaDaLoja::noMapa` e `MapaDoLider`)

**Files:**
- Modify: `api/app/Support/Entregas/MotoboysNoMapaDaLoja.php`
- Create: `api/app/Support/Entregas/MapaDoLider.php`
- Modify: `scripts/teste-php/mapa-da-loja.php`

- [ ] **Step 1: o teste**

Em `scripts/teste-php/mapa-da-loja.php`:

1. No dublê `PedidosNoMapa`, depois da linha do `daLoja`, acrescente:

```php
        public static function doLider(string $empresa): array { static::$chamadas[] = ['doLider', $empresa]; return [['id' => 'order_lider']]; }
```

2. Antes da linha `    echo '== PortalLojaController (rota loja/motoboys e motoboy do pedido sem posição)' . PHP_EOL;`, acrescente:

```php
    echo '== MapaDoLider (aba Mapa do líder dos motoboys no app)' . PHP_EOL;
    require '/repo/api/app/Support/Entregas/MapaDoLider.php';

    $doLider  = \App\Support\Entregas\MapaDoLider::motoboys(EMPRESA);
    $liderPor = array_column($doLider, null, 'nome');
    confere(array_keys($liderPor) === array_keys($porNome), 'os mesmos motoboys do mapa do portal (online e offline com pedido aceito)');
    confere(array_keys($doLider[0] ?? []) === ['id', 'nome', 'latitude', 'longitude', 'situacao', 'online'], 'só id, nome, latitude, longitude, situação e online');
    confere(($liderPor['Coleta']['id'] ?? null) === 'driver_coleta' && ($liderPor['Coleta']['situacao'] ?? null) === 'coleta', 'id = public_id do motoboy (a troca usa) e a situação do capacete');
    confere(($liderPor['Offline com pedido aceito']['online'] ?? null) === false && ($liderPor['Livre']['online'] ?? null) === true, 'online em booleano');
    $jsonLider = json_encode($doLider);
    confere(!str_contains($jsonLider, '+55169') && !str_contains($jsonLider, '@teste.com') && !str_contains($jsonLider, '"m-livre"'), 'sem telefone, e-mail nem uuid do motoboy');
    $mapaLider = \App\Support\Entregas\MapaDoLider::mapa(EMPRESA);
    confere(array_keys($mapaLider) === ['pedidos', 'motoboys'] && $mapaLider['pedidos'] === [['id' => 'order_lider']] && end(\App\Support\Entregas\PedidosNoMapa::$chamadas) === ['doLider', EMPRESA],
        'mapa = { pedidos: PedidosNoMapa::doLider, motoboys }');
```

- [ ] **Step 2: rodar e ver falhar**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/mapa-da-loja.php`
Expected: erro fatal `Failed opening required '/repo/api/app/Support/Entregas/MapaDoLider.php'`.

- [ ] **Step 3: a regra de quem aparece num método só**

Em `api/app/Support/Entregas/MotoboysNoMapaDaLoja.php`, substitua o método `listar` inteiro (do docblock com
`@param array<int, string> $donos` até o `}` que fecha o método, antes do docblock do `motoboyDoIdOpaco`) por:

```php
    /**
     * @param array<int, string> $donos customer_uuid dos pedidos da loja: o Vendor e o contato do usuário
     *
     * @return array<int, array{id: string, nome: ?string, latitude: float, longitude: float, situacao: string, pedidos: array<int, string>}>
     */
    public static function listar(string $companyUuid, array $donos): array
    {
        $lista = [];
        foreach (static::noMapa($companyUuid) as $linha) {
            $daLoja = array_filter($linha['pedidos'], fn ($pedido) => in_array($pedido->customer_uuid, $donos, true));

            $lista[] = [
                'id'        => static::idOpaco($linha['motoboy']->uuid),
                'nome'      => $linha['motoboy']->name,
                'latitude'  => $linha['latitude'],
                'longitude' => $linha['longitude'],
                'situacao'  => $linha['situacao'],
                'pedidos'   => array_values(array_map(fn ($pedido) => $pedido->public_id, $daLoja)),
            ];
        }

        return $lista;
    }

    /**
     * Os motoboys que aparecem no mapa (a regra acima), com os pedidos em andamento de cada um, a situação e a posição.
     * Base da lista do portal (listar) e da aba Mapa do líder dos motoboys no app (MapaDoLider): os dois mapas mostram
     * os mesmos motoboys.
     *
     * @return array<int, array{motoboy: Driver, pedidos: array<int, object>, situacao: string, latitude: float, longitude: float}>
     */
    public static function noMapa(string $companyUuid): array
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

            $lista[] = [
                'motoboy'   => $motoboy,
                'pedidos'   => $pedidos,
                'situacao'  => $situacao,
                'latitude'  => (float) $latitude,
                'longitude' => (float) $longitude,
            ];
        }

        return $lista;
    }
```

(O corpo do `noMapa` é o laço que estava no `listar`, sem mudança de regra; o `listar` só monta a resposta do portal.)

- [ ] **Step 4: o mapa do líder**

Crie `api/app/Support/Entregas/MapaDoLider.php`:

```php
<?php

namespace App\Support\Entregas;

/**
 * Entregas RestaurantePro: a aba Mapa do líder dos motoboys no app (LiderController@mapa, relida a cada 10 s com a tela
 * aberta): os pedidos em andamento de todas as lojas (PedidosNoMapa::doLider, os mesmos do mapa do console) e os motoboys
 * do mapa (MotoboysNoMapaDaLoja::noMapa: os online e os offline com pedido aceito em andamento, os mesmos do mapa do
 * portal), com o public_id (a troca do motoboy usa), o nome, a posição, a situação (cor do capacete) e o online. Sem
 * telefone nem e-mail: o líder fala com os motoboys pelo chat de sempre.
 */
class MapaDoLider
{
    /**
     * @return array{pedidos: array<int, array>, motoboys: array<int, array{id: string, nome: ?string, latitude: float, longitude: float, situacao: string, online: bool}>}
     */
    public static function mapa(string $companyUuid): array
    {
        return ['pedidos' => PedidosNoMapa::doLider($companyUuid), 'motoboys' => static::motoboys($companyUuid)];
    }

    /**
     * @return array<int, array{id: string, nome: ?string, latitude: float, longitude: float, situacao: string, online: bool}>
     */
    public static function motoboys(string $companyUuid): array
    {
        return array_map(fn (array $linha) => [
            'id'        => $linha['motoboy']->public_id,
            'nome'      => $linha['motoboy']->name,
            'latitude'  => $linha['latitude'],
            'longitude' => $linha['longitude'],
            'situacao'  => $linha['situacao'],
            'online'    => (bool) $linha['motoboy']->online,
        ], MotoboysNoMapaDaLoja::noMapa($companyUuid));
    }
}
```

- [ ] **Step 5: rodar e ver passar**

```bash
PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/mapa-da-loja.php
PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/conversas-da-loja.php
PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/MotoboysNoMapaDaLoja.php api/app/Support/Entregas/MapaDoLider.php
```

Expected: `FALHAS: 0` nos dois (o portal continua igual) e `OK` nos dois arquivos.

- [ ] **Step 6: commit**

```bash
git rev-parse --show-toplevel
git add api/app/Support/Entregas/MotoboysNoMapaDaLoja.php api/app/Support/Entregas/MapaDoLider.php scripts/teste-php/mapa-da-loja.php
git commit -m "Líder dos motoboys: motoboys do mapa (a regra do portal em MotoboysNoMapaDaLoja::noMapa) e MapaDoLider

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: servidor, aviso ao motoboy anterior (`PedidoPassadoParaOutro`)

**Files:**
- Create: `api/app/Notifications/Entregas/PedidoPassadoParaOutro.php`
- Modify: `api/app/Notifications/Entregas/AvisosDoMotoboy.php`
- Modify: `scripts/teste-php/avisos-push.php`

- [ ] **Step 1: o teste**

Em `scripts/teste-php/avisos-push.php`:

1. Depois da linha `confere(AvisosDoMotoboy::texto(new Illuminate\Notifications\Notification()) === null, 'classe sem tradução devolve null');`, acrescente:

```php
$trocado = new App\Notifications\Entregas\PedidoPassadoParaOutro('4821', 'order_abc');
confere(AvisosDoMotoboy::texto($trocado) === ['Pedido passado para outro motoboy', 'Pedido #4821 passou para outro motoboy.'], 'pedido passado para outro motoboy (líder dos motoboys): texto da própria notificação');
```

2. Depois da linha `confere(isset($chat['android']['fcm_options'], $chat['apns']), 'fcm_options e apns do push do Fleet-Ops ficam no push comum');`, acrescente:

```php
$trocadoEnviado = enviado($trocado);
confere(($trocadoEnviado['android']['notification']['channel_id'] ?? null) === 'avisos' && !isset($trocadoEnviado['android']['ttl']), 'pedido passado para outro motoboy: canal "avisos", sem validade curta');
confere(($trocadoEnviado['data'] ?? null) === ['type' => 'entregas_pedido_trocado', 'pedido' => 'order_abc'], 'pedido passado para outro motoboy: dados sem "id" (o app não abre o pedido)');
```

3. Depois da linha `confere(isset($ping['android']['fcm_options'], $ping['apns']), 'o resto do push (fcm_options, apns) fica');`, acrescente:

```php
confere((enviado($trocado)['notification']['title'] ?? null) === 'Pedido passado para outro motoboy', 'pedido passado para outro motoboy: push comum também com o alarme por dados ligado (não é alarme)');
```

- [ ] **Step 2: rodar e ver falhar**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/avisos-push.php`
Expected: erro fatal `Class "App\Notifications\Entregas\PedidoPassadoParaOutro" not found`.

- [ ] **Step 3: a notificação**

Crie `api/app/Notifications/Entregas/PedidoPassadoParaOutro.php`:

```php
<?php

namespace App\Notifications\Entregas;

use Fleetbase\Support\PushNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;

/**
 * Entregas RestaurantePro: aviso ao motoboy de que o líder dos motoboys passou o pedido dele para outro motoboy
 * (TrocaDoMotoboy). Só push (sai pelo CanalFcmEntregas, no canal "avisos" do app; o AvisosDoMotoboy conhece a classe).
 *
 * Os dados não levam `id`: o app abre a tela do pedido (e toca o alarme) em todo push com id "order_…", e este pedido já
 * não é dele. O app novo recarrega a lista de pedidos pelo tipo (TIPO); o APK antigo só mostra a notificação.
 * Guarda só textos (número e public_id), sem o Order: a notificação vai para a fila.
 */
class PedidoPassadoParaOutro extends Notification implements ShouldQueue
{
    use Queueable;

    public const TIPO = 'entregas_pedido_trocado';

    public string $title;

    public string $message;

    public array $data;

    public function __construct(public string $numero, public string $pedido)
    {
        $this->title   = 'Pedido passado para outro motoboy';
        $this->message = 'Pedido ' . static::rotulo($numero) . ' passou para outro motoboy.';
        $this->data    = ['type' => self::TIPO, 'pedido' => $pedido];
    }

    /** "#4821" para o número do iFood (só dígitos); o código de rastreio fica como está. */
    public static function rotulo(string $numero): string
    {
        return ctype_digit($numero) ? '#' . $numero : $numero;
    }

    public function via($notifiable)
    {
        return [FcmChannel::class];
    }

    public function toFcm($notifiable)
    {
        return PushNotification::createFcmMessage($this->title, $this->message, $this->data);
    }
}
```

- [ ] **Step 4: texto e canal**

Em `api/app/Notifications/Entregas/AvisosDoMotoboy.php`:

1. No `CANAIS`, troque

```php
        'test'                  => 'avisos',
    ];
```

por

```php
        'test'                  => 'avisos',
        // o líder dos motoboys passou o pedido para outro motoboy (PedidoPassadoParaOutro)
        'entregas_pedido_trocado' => 'avisos',
    ];
```

2. No `texto()`, troque

```php
            $notificacao instanceof LembretePedidoAberto => [$notificacao->title, $notificacao->message],
```

por

```php
            $notificacao instanceof LembretePedidoAberto => [$notificacao->title, $notificacao->message],
            $notificacao instanceof PedidoPassadoParaOutro => [$notificacao->title, $notificacao->message],
```

- [ ] **Step 5: rodar e ver passar**

```bash
PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/avisos-push.php
PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/cartao-do-alarme.php
PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Notifications/Entregas/PedidoPassadoParaOutro.php api/app/Notifications/Entregas/AvisosDoMotoboy.php
```

Expected: `FALHAS: 0` nos dois e `OK` nos dois arquivos.

- [ ] **Step 6: commit**

```bash
git rev-parse --show-toplevel
git add api/app/Notifications/Entregas/PedidoPassadoParaOutro.php api/app/Notifications/Entregas/AvisosDoMotoboy.php scripts/teste-php/avisos-push.php
git commit -m "Líder dos motoboys: push \"Pedido #4821 passou para outro motoboy.\" ao motoboy anterior (canal avisos)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: servidor, a troca do motoboy e as rotas

**Files:**
- Create: `api/app/Support/Entregas/TrocaDoMotoboy.php`
- Create: `api/app/Http/Controllers/Entregas/LiderController.php`
- Modify: `api/app/Providers/RouteServiceProvider.php`
- Modify: `scripts/teste-php/lider.php`

- [ ] **Step 1: o teste**

Em `scripts/teste-php/lider.php`:

1. Troque

```php
    require '/repo/api/app/Support/Entregas/MotoboyDaSessao.php';
    require '/repo/api/app/Support/Entregas/LiderDosMotoboys.php';
```

por

```php
    require '/repo/api/app/Support/Entregas/StatusDoPedido.php';
    require '/repo/api/app/Support/Entregas/TravaDoPedido.php';
    require '/repo/api/app/Support/Entregas/MotoboyDaSessao.php';
    require '/repo/api/app/Support/Entregas/LiderDosMotoboys.php';
    require '/repo/api/app/Support/Entregas/TrocaDoMotoboy.php';
    require '/repo/api/app/Notifications/Entregas/PedidoPassadoParaOutro.php';
    require '/repo/api/app/Http/Controllers/Entregas/LiderController.php';
```

2. Antes da última linha útil (`    echo PHP_EOL . "FALHAS: {$falhas}" . PHP_EOL;`), acrescente:

```php
    echo '== LiderController@acesso e @mapa' . PHP_EOL;
    User::$todos   = [usuario('u-lider'), usuario('u-comum'), usuario('u-admin', 'admin')];
    Driver::$todos = [
        motoboy('m-lider', 'u-lider', 'Líder'),
        motoboy('m-comum', 'u-comum', 'Comum'),
        motoboy('m-novo', 'u-novo', 'Novo'),
        motoboy('m-outra', 'u-outra', 'Outra empresa', 'empresa-b'),
    ];
    $controller = new LiderController();

    $acesso = $controller->acesso($token);
    confere($acesso->status === 200 && $acesso->dados === ['lider' => true], 'líder: {"lider": true}');
    $sessao = ['company' => EMPRESA, 'user' => 'u-comum'];
    $acesso = $controller->acesso($token);
    confere($acesso->status === 200 && $acesso->dados === ['lider' => false], 'motoboy comum: {"lider": false} com 200 (nunca 403)');
    $sessao = ['company' => EMPRESA, 'user' => 'u-admin'];
    confere($controller->acesso($token)->dados === ['lider' => false], 'admin sem cadastro de motoboy (não é o app): não é líder');
    $sessao = ['company' => EMPRESA, 'user' => 'u-lider'];
    confere($controller->acesso(new Request('flb_live_chave-do-apk'))->dados === ['lider' => false], 'chave de API (flb_live_ do APK, autentica como admin): não é líder');

    $mapa = $controller->mapa($token);
    confere($mapa->status === 200 && $mapa->dados === ['pedidos' => [['id' => 'order_1']], 'motoboys' => [['id' => 'driver_b']]] && \App\Support\Entregas\MapaDoLider::$chamadas === [EMPRESA],
        'mapa do líder: MapaDoLider::mapa com a empresa da sessão');
    $sessao = ['company' => EMPRESA, 'user' => 'u-comum'];
    $negado = $controller->mapa($token);
    confere($negado->status === 403 && $negado->dados === ['errors' => ['Disponível só para o líder dos motoboys.']], 'mapa de quem não é líder: 403');

    echo '== LiderController@trocarMotoboy (TrocaDoMotoboy)' . PHP_EOL;
    $troca = fn (string $pedido, ?string $motoboy) => $controller->trocarMotoboy(new Request('12|token-do-motoboy', $motoboy === null ? [] : ['motoboy' => $motoboy]), $pedido);
    Order::$todos = [
        pedido('o-ifood', 'started', 'm-comum', ['internal_id' => '4821', 'rastreio' => 'RP1']),
        pedido('o-aberto', 'dispatched', null, ['adhoc' => true, 'rastreio' => 'RP2']),
        pedido('o-rastreio', 'started', 'm-comum', ['rastreio' => 'RP3']),
        pedido('o-concluido', 'completed', 'm-comum'),
        pedido('o-cancelado', 'canceled', 'm-comum'),
        pedido('o-outra', 'started', 'm-comum', ['company_uuid' => 'empresa-b']),
    ];

    confere($troca('order_ifood', 'driver_novo')->status === 403, 'quem não é líder não troca: 403');
    confere(Order::$todos[0]->atribuicoes === [], 'nada muda sem ser líder');

    $sessao = ['company' => EMPRESA, 'user' => 'u-lider'];
    $resposta = $troca('order_inexistente', 'driver_novo');
    confere($resposta->status === 404 && $resposta->dados === ['errors' => ['Pedido não encontrado.']], 'pedido que não existe: 404');
    confere($troca('order_outra', 'driver_novo')->status === 404, 'pedido de outra empresa: 404');
    $resposta = $troca('order_concluido', 'driver_novo');
    confere($resposta->status === 409 && $resposta->dados === ['errors' => ['Este pedido já foi encerrado.']], 'pedido concluído: 409 "Este pedido já foi encerrado."');
    confere($troca('order_cancelado', 'driver_novo')->status === 409, 'pedido cancelado: 409');
    $resposta = $troca('order_ifood', 'driver_outra');
    confere($resposta->status === 422 && $resposta->dados === ['errors' => ['Escolha um motoboy da lista.']], 'motoboy de outra empresa: 422');
    confere($troca('order_ifood', 'driver_inexistente')->status === 422 && $troca('order_ifood', null)->status === 422 && $troca('order_ifood', '  ')->status === 422,
        'motoboy que não existe, sem motoboy ou em branco: 422');
    confere(Order::$todos[0]->atribuicoes === [], 'nenhuma recusa mexeu no pedido');

    Cache::$travas = [];
    Log::$linhas   = [];
    $resposta      = $troca('order_ifood', 'driver_comum');
    confere($resposta->status === 200 && $resposta->dados === ['pedido' => ['id' => 'order_ifood', 'motoboy_uuid' => 'm-comum']], 'o mesmo motoboy de agora: 200 sem mudança, com o pedido');
    confere(Order::$todos[0]->atribuicoes === [] && Log::$linhas === [] && Driver::$todos[1]->avisos === [], 'mesmo motoboy: sem atribuição, sem aviso e sem log');

    Cache::$travas = [];
    $resposta      = $troca('o-ifood', 'driver_novo');
    $pedido        = Order::$todos[0];
    confere($resposta->status === 200 && $resposta->dados === ['pedido' => ['id' => 'order_ifood', 'motoboy_uuid' => 'm-novo']], 'troca: 200 com o pedido no formato do mapa (pelo uuid também)');
    confere(Cache::$travas === ['entregas:pedido:o-ifood'], 'com a TravaDoPedido (a mesma do aceite e do cancelamento)');
    confere($pedido->atribuicoes === [['motoboy' => 'm-novo', 'silencioso' => true, 'adhoc_no_save' => false]],
        'assignDriver do Fleet-Ops em modo silencioso (o OrderObserver avisa o motoboy novo uma vez), já com o pedido aberto desligado');
    confere($pedido->status === 'started', 'o pedido fica com o status que tinha');
    $aviso = Driver::$todos[1]->avisos[0] ?? null;
    confere($aviso instanceof PedidoPassadoParaOutro && $aviso->title === 'Pedido passado para outro motoboy' && $aviso->message === 'Pedido #4821 passou para outro motoboy.',
        'o anterior recebe "Pedido #4821 passou para outro motoboy." (número do iFood)');
    confere(($aviso->data ?? null) === ['type' => 'entregas_pedido_trocado', 'pedido' => 'order_ifood'], 'dados do push: o tipo e o pedido, sem "id" (o app não abre o pedido que não é mais dele)');
    confere(Driver::$todos[2]->avisos === [], 'o novo não recebe este aviso (recebe o "Novo pedido para você" do Fleet-Ops)');
    confere(Log::$linhas === [['info', '[entregas] líder trocou o motoboy', ['pedido' => 'order_ifood', 'anterior' => 'driver_comum', 'novo' => 'driver_novo', 'lider' => 'u-lider']]],
        'log "[entregas] líder trocou o motoboy" só com ids');

    Log::$linhas = [];
    $resposta    = $troca('order_aberto', 'driver_comum');
    $aberto      = Order::$todos[1];
    confere($resposta->status === 200 && $aberto->adhoc === false && $aberto->driver_assigned_uuid === 'm-comum', 'pedido aberto sem motoboy: passa a ser do escolhido, com o adhoc desligado');
    confere(($aberto->atribuicoes[0]['adhoc_no_save'] ?? null) === false, 'adhoc desligado antes do save (o HandleOrderDriverAssigned só avisa pedido não aberto)');
    confere(array_key_exists('anterior', Log::$linhas[0][2] ?? []) && Log::$linhas[0][2]['anterior'] === null && count(Driver::$todos[1]->avisos) === 1, 'sem motoboy anterior: ninguém é avisado e o log fica com anterior nulo');

    Driver::$todos[1]->avisos = [];
    $troca('order_rastreio', 'driver_novo');
    confere((Driver::$todos[1]->avisos[0]->message ?? null) === 'Pedido RP3 passou para outro motoboy.', 'sem o número do iFood: o de rastreio, sem "#"');

    \Teste\Falhas::$aviso = true;
    Log::$linhas          = [];
    $resposta             = $troca('order_rastreio', 'driver_comum');
    \Teste\Falhas::$aviso = false;
    confere($resposta->status === 200 && Order::$todos[2]->driver_assigned_uuid === 'm-comum', 'aviso ao anterior falhou: a troca vale assim mesmo');
    confere(array_column(Log::$linhas, 1) === ['[entregas] líder trocou o motoboy, mas o aviso ao anterior falhou', '[entregas] líder trocou o motoboy'], 'a falha do aviso fica no log');

    Cache::$ocupada = true;
    $resposta       = $troca('order_ifood', 'driver_comum');
    Cache::$ocupada = false;
    confere($resposta->status === 409 && $resposta->dados === ['errors' => ['Outra pessoa está mexendo neste pedido. Tente de novo.']] && Order::$todos[0]->driver_assigned_uuid === 'm-novo',
        'trava ocupada (outro líder, aceite ou cancelamento): 409 e nada muda');

    echo '== PedidoPassadoParaOutro' . PHP_EOL;
    confere(PedidoPassadoParaOutro::rotulo('4821') === '#4821' && PedidoPassadoParaOutro::rotulo('RP-1') === 'RP-1', 'rótulo: "#" só no número do iFood');
    confere((new PedidoPassadoParaOutro('1', 'order_x'))->via(null) === ['NotificationChannels\Fcm\FcmChannel'], 'só push (FcmChannel, trocado pelo CanalFcmEntregas)');
    confere(in_array('Illuminate\Contracts\Queue\ShouldQueue', class_implements(PedidoPassadoParaOutro::class), true), 'vai para a fila');

    echo '== Rotas (RouteServiceProvider)' . PHP_EOL;
    $rotas = file_get_contents('/repo/api/app/Providers/RouteServiceProvider.php');
    foreach ([
        'use App\Http\Controllers\Entregas\LiderController;',
        "RateLimiter::for('entregas-lider', fn (Request \$request) => Limit::perMinute(120)->by('entregas-lider:' . (session('user') ?: \$request->ip())));",
        "Route::prefix('v1/entregas/lider')",
        "->middleware(['fleetbase.api', 'throttle:entregas-lider'])",
        "Route::get('acesso', [LiderController::class, 'acesso']);",
        "Route::get('mapa', [LiderController::class, 'mapa']);",
        "Route::post('pedidos/{id}/motoboy', [LiderController::class, 'trocarMotoboy']);",
    ] as $trecho) {
        confere(str_contains($rotas, $trecho), "rota/limitador: {$trecho}");
    }

    echo '== Fleet-Ops: o que a troca usa (cópia em packages/, a versão da produção)' . PHP_EOL;
    $order    = file_get_contents('/repo/packages/fleetops/server/src/Models/Order.php');
    $observer = file_get_contents('/repo/packages/fleetops/server/src/Observers/OrderObserver.php');
    $ouvinte  = file_get_contents('/repo/packages/fleetops/server/src/Listeners/HandleOrderDriverAssigned.php');
    confere(str_contains($order, 'public function assignDriver($driver, $silent = false)'), 'Order::assignDriver($driver, $silent) existe');
    confere(str_contains($observer, "if (\$order->wasChanged('driver_assigned_uuid')) {") && str_contains($observer, '$order->notifyDriverAssigned();'),
        'o OrderObserver dispara o OrderDriverAssigned quando o motoboy muda (por isso o assignDriver silencioso)');
    confere(str_contains($ouvinte, '$order->adhoc === false'), 'o HandleOrderDriverAssigned só avisa pedido não aberto (por isso o adhoc falso)');
```

- [ ] **Step 2: rodar e ver falhar**

Run: `PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/lider.php`
Expected: erro fatal `Failed opening required '/repo/api/app/Support/Entregas/TrocaDoMotoboy.php'`.

- [ ] **Step 3: a troca**

Crie `api/app/Support/Entregas/TrocaDoMotoboy.php`:

```php
<?php

namespace App\Support\Entregas;

use App\Notifications\Entregas\PedidoPassadoParaOutro;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: o líder dos motoboys passa um pedido para outro motoboy pela aba Mapa do app
 * (LiderController@trocarMotoboy).
 *
 * Segue a troca do console (Fleet-Ops → pedido → Trocar motoboy grava o driver_assigned_uuid com o save do Order): o
 * pedido fica com o status que tinha. Com a TravaDoPedido (a mesma do aceite e do cancelamento), relê o pedido, desliga o
 * pedido aberto (adhoc falso: com ele ligado, o HandleOrderDriverAssigned do Fleet-Ops não avisa o motoboy novo e o
 * reenvio do pedido aberto continuaria) e chama o assignDriver do Order em modo silencioso. O save dele dispara o
 * OrderObserver do Fleet-Ops, que dispara o OrderDriverAssigned uma vez só (o assignDriver sem o silencioso dispara o
 * evento antes do save, e o observer de novo depois: o motoboy novo receberia dois avisos). O OrderDriverAssigned leva o
 * push "Novo pedido para você" ao motoboy novo (AvisosDoMotoboy) e, no pedido iFood, o ObservadorDosPedidosIfood manda o
 * assignDriver ao iFood. O motoboy anterior recebe o PedidoPassadoParaOutro.
 */
class TrocaDoMotoboy
{
    /**
     * @return array{0: int, 1: array} status HTTP e corpo da resposta ({"pedido": …} ou {"errors": […]})
     */
    public static function trocar(string $empresaUuid, string $pedidoId, string $motoboyId, string $liderUuid): array
    {
        $pedido = static::pedido($empresaUuid, $pedidoId);
        if (!$pedido) {
            return [404, ['errors' => ['Pedido não encontrado.']]];
        }
        if (static::encerrado($pedido)) {
            return [409, ['errors' => ['Este pedido já foi encerrado.']]];
        }

        // nenhum model registra o CompanyScope nesta versão: a empresa é filtrada aqui
        $novo = $motoboyId === '' ? null : Driver::where('company_uuid', $empresaUuid)->where('public_id', $motoboyId)->first();
        if (!$novo) {
            return [422, ['errors' => ['Escolha um motoboy da lista.']]];
        }

        try {
            [$resultado, $anteriorUuid] = TravaDoPedido::executar((string) $pedido->uuid, fn () => static::trocarComATrava($empresaUuid, (string) $pedido->uuid, $novo));
        } catch (LockTimeoutException) {
            return [409, ['errors' => ['Outra pessoa está mexendo neste pedido. Tente de novo.']]];
        }

        if ($resultado === 'sumiu') {
            return [404, ['errors' => ['Pedido não encontrado.']]];
        }
        if ($resultado === 'encerrado') {
            return [409, ['errors' => ['Este pedido já foi encerrado.']]];
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

        return [200, ['pedido' => PedidosNoMapa::umDoLider($atualizado)]];
    }

    /**
     * Dentro da trava: relê o pedido (outro líder, o aceite ou o cancelamento podem ter mudado) e troca.
     *
     * @return array{0: string, 1: ?string} resultado (sumiu, encerrado, igual, trocado) e o uuid do motoboy anterior
     */
    protected static function trocarComATrava(string $empresaUuid, string $pedidoUuid, Driver $novo): array
    {
        $pedido = static::pedido($empresaUuid, $pedidoUuid);
        if (!$pedido) {
            return ['sumiu', null];
        }
        if (static::encerrado($pedido)) {
            return ['encerrado', null];
        }
        if ((string) $pedido->driver_assigned_uuid === (string) $novo->uuid) {
            return ['igual', null];
        }

        $anterior      = $pedido->driver_assigned_uuid ?: null;
        $pedido->adhoc = false;
        $pedido->assignDriver($novo, true);

        return ['trocado', $anterior];
    }

    /** O push ao motoboy anterior; uma falha aqui não desfaz a troca (fica no log). Devolve o public_id dele. */
    protected static function avisarAnterior(string $empresaUuid, ?string $anteriorUuid, $pedido): ?string
    {
        if (!$anteriorUuid) {
            return null;
        }

        $anterior = Driver::where('company_uuid', $empresaUuid)->where('uuid', $anteriorUuid)->first();
        if (!$anterior) {
            return null;
        }

        try {
            $anterior->notify(new PedidoPassadoParaOutro(static::numero($pedido), (string) $pedido->public_id));
        } catch (\Throwable $erro) {
            Log::warning('[entregas] líder trocou o motoboy, mas o aviso ao anterior falhou', ['pedido' => $pedido->public_id, 'erro' => get_class($erro)]);
        }

        return $anterior->public_id;
    }

    /** O número do pedido no aviso: o do iFood (internal_id); sem ele, o de rastreio; sem os dois, o public_id. */
    protected static function numero($pedido): string
    {
        return (string) ($pedido->internal_id ?: ($pedido->trackingNumber?->tracking_number ?: $pedido->public_id));
    }

    /** Pedido da empresa pelo public_id ou pelo uuid. */
    protected static function pedido(string $empresaUuid, string $id, array $relacoes = [])
    {
        // nenhum model registra o CompanyScope nesta versão: a empresa é filtrada aqui
        return Order::where('company_uuid', $empresaUuid)
            ->where(fn ($query) => $query->where('public_id', $id)->orWhere('uuid', $id))
            ->with($relacoes)
            ->first();
    }

    protected static function encerrado($pedido): bool
    {
        return in_array($pedido->status, StatusDoPedido::ENCERRADOS, true);
    }
}
```

- [ ] **Step 4: o controller**

Crie `api/app/Http/Controllers/Entregas/LiderController.php`:

```php
<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\LiderDosMotoboys;
use App\Support\Entregas\MapaDoLider;
use App\Support\Entregas\TrocaDoMotoboy;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: a aba Mapa do líder dos motoboys no app (Navigator), na API v1, com o token dele.
 * - acesso: {"lider": true|false}, nunca 403 (o app mostra ou esconde a aba);
 * - mapa: os pedidos em andamento de todas as lojas e os motoboys no mapa (MapaDoLider), só para o líder;
 * - trocarMotoboy: passa o pedido para outro motoboy (TrocaDoMotoboy), só para o líder.
 * Líder = LiderDosMotoboys (o motoboy da sessão que é admin ou tem a permissão "fleet-ops assign-driver-for order").
 * Usuário de loja nem chega aqui: o ProtegerPortalLoja nega a API v1 a ele.
 */
class LiderController extends Controller
{
    public function acesso(Request $request)
    {
        return response()->json(['lider' => LiderDosMotoboys::daSessao($request)]);
    }

    public function mapa(Request $request)
    {
        if (!LiderDosMotoboys::daSessao($request)) {
            return $this->soParaLider();
        }

        return response()->json(MapaDoLider::mapa((string) session('company')));
    }

    public function trocarMotoboy(Request $request, string $id)
    {
        if (!LiderDosMotoboys::daSessao($request)) {
            return $this->soParaLider();
        }

        [$status, $corpo] = TrocaDoMotoboy::trocar((string) session('company'), $id, trim((string) $request->input('motoboy', '')), (string) session('user'));

        return response()->json($corpo, $status);
    }

    protected function soParaLider()
    {
        return response()->json(['errors' => ['Disponível só para o líder dos motoboys.']], 403);
    }
}
```

- [ ] **Step 5: as rotas**

Em `api/app/Providers/RouteServiceProvider.php`:

1. Nos imports, troque

```php
use App\Http\Controllers\Entregas\LojasController;
```

por

```php
use App\Http\Controllers\Entregas\LiderController;
use App\Http\Controllers\Entregas\LojasController;
```

2. Antes de `        $this->routes(`, acrescente:

```php
        // Entregas RestaurantePro: aba Mapa do líder dos motoboys no app (o mapa relido a cada 10 s com a tela aberta, o acesso
        // ao abrir o app e ao voltar para ele, e a troca do motoboy), até 120 chamadas por minuto por usuário, num balde só dele
        RateLimiter::for('entregas-lider', fn (Request $request) => Limit::perMinute(120)->by('entregas-lider:' . (session('user') ?: $request->ip())));

```

3. Antes do comentário `                // Entregas RestaurantePro: dados do pedido iFood no app (card de aceitar e detalhes; MotoboyController@ifood)`, acrescente:

```php
                // Entregas RestaurantePro: aba Mapa do líder dos motoboys no app (LiderController; o líder é o LiderDosMotoboys)
                Route::prefix('v1/entregas/lider')
                    ->middleware(['fleetbase.api', 'throttle:entregas-lider'])
                    ->group(function () {
                        Route::get('acesso', [LiderController::class, 'acesso']);
                        Route::get('mapa', [LiderController::class, 'mapa']);
                        Route::post('pedidos/{id}/motoboy', [LiderController::class, 'trocarMotoboy']);
                    });

```

- [ ] **Step 6: rodar e ver passar**

```bash
PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/lider.php
PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-ligacoes.php
PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/TrocaDoMotoboy.php api/app/Http/Controllers/Entregas/LiderController.php api/app/Providers/RouteServiceProvider.php
```

Expected: `FALHAS: 0` nos dois (o `lider.php` com 52 `PASSA`) e `OK` nos três arquivos.

- [ ] **Step 7: a suíte do servidor**

```bash
for t in scripts/teste-php/*.php; do case $t in *stubs*|*fixtures*|*stub-banco*) continue;; esac; echo "$t: $(PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs $t 2>&1 | tail -1)"; done
```

Expected: `FALHAS: 0` em todos.

- [ ] **Step 8: commit**

```bash
git rev-parse --show-toplevel
git add api/app/Support/Entregas/TrocaDoMotoboy.php api/app/Http/Controllers/Entregas/LiderController.php api/app/Providers/RouteServiceProvider.php scripts/teste-php/lider.php
git commit -m "Líder dos motoboys: rotas v1/entregas/lider (acesso, mapa e troca do motoboy com a trava do pedido)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

# Parte B: aba Mapa do líder, app

### Task 9: app, funções puras do mapa do líder

**Files (repo `entregas-navigator`, ramo `app-pedidos-e-mapa-do-lider`):**
- Create: `src/utils/mapa-do-lider.ts`
- Create: `scripts/testes/mapa-do-lider.teste.ts`

- [ ] **Step 1: o teste**

Crie `scripts/testes/mapa-do-lider.teste.ts`:

```ts
// Testes das funções puras da aba Mapa do líder dos motoboys (src/utils/mapa-do-lider.ts), com o Node puro:
//   node --experimental-strip-types --test scripts/testes/mapa-do-lider.teste.ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    ESPERA_MAXIMA_MS,
    INTERVALO_DO_MAPA_MS,
    acessoDoLider,
    esperaDoMapa,
    kmEntre,
    lerMapa,
    motoboysParaTroca,
    pontosDoMapa,
    rotuloDoPedido,
    tempoDesde,
    textoDaDistancia,
} from '../../src/utils/mapa-do-lider.ts';
import type { MotoboyDoLider, PedidoDoLider } from '../../src/utils/mapa-do-lider.ts';

const LOJA = { latitude: -21.17, longitude: -47.8 };

const pedido = (extra: Partial<PedidoDoLider> = {}): PedidoDoLider => ({
    id: 'order_1',
    numero: '4821',
    latitude: -21.18,
    longitude: -47.81,
    endereco: 'Rua A, 10',
    status: 'started',
    motoboy: 'Ana',
    aceito: true,
    criado_em: '2026-10-06T08:00:00-03:00',
    loja: 'Pizzaria Boa',
    motoboy_id: 'driver_ana',
    atualizado_em: '2026-10-06T08:30:00-03:00',
    coleta: LOJA,
    ...extra,
});

const motoboy = (id: string, nome: string, km: number, extra: Partial<MotoboyDoLider> = {}): MotoboyDoLider => ({
    id,
    nome,
    // ~km ao norte da loja (1 grau de latitude ~ 111,2 km)
    latitude: LOJA.latitude + km / 111.2,
    longitude: LOJA.longitude,
    situacao: 'livre',
    online: true,
    ...extra,
});

test('acesso: só {"lider": true} libera a aba', () => {
    assert.equal(acessoDoLider({ lider: true }), true);
    assert.equal(acessoDoLider({ lider: false }), false);
    assert.equal(acessoDoLider({ lider: 'true' }), false);
    assert.equal(acessoDoLider(null), false);
});

test('lerMapa: só o que dá para pôr no mapa', () => {
    const mapa = lerMapa({
        pedidos: [
            { ...pedido(), coleta: { latitude: -21.17, longitude: -47.8 } },
            { ...pedido({ id: 'order_2', numero: '' }), coleta: null, latitude: '-21.2', longitude: '-47.83' },
            { ...pedido({ id: 'order_zero' }), latitude: 0, longitude: 0 },
            { ...pedido({ id: '' }) },
        ],
        motoboys: [
            { id: 'driver_ana', nome: 'Ana', latitude: -21.1, longitude: -47.8, situacao: 'entrega', online: true },
            { id: 'driver_bia', nome: null, latitude: -21.1, longitude: -47.8, situacao: 'qualquer', online: 1 },
            { id: 'driver_sem_gps', nome: 'Sem GPS', latitude: null, longitude: null, situacao: 'livre', online: true },
        ],
    });
    assert.deepEqual(mapa.pedidos.map((p) => p.id), ['order_1', 'order_2']);
    assert.equal(mapa.pedidos[1].numero, 'order_2');
    assert.equal(mapa.pedidos[1].latitude, -21.2);
    assert.equal(mapa.pedidos[1].coleta, null);
    assert.deepEqual(mapa.pedidos[0].coleta, LOJA);
    assert.deepEqual(mapa.motoboys.map((m) => [m.id, m.situacao, m.online]), [
        ['driver_ana', 'entrega', true],
        ['driver_bia', 'livre', false],
    ]);
    assert.deepEqual(lerMapa(null), { pedidos: [], motoboys: [] });
    assert.deepEqual(lerMapa({ pedidos: 'x' }), { pedidos: [], motoboys: [] });
});

test('rótulo do pedido e distância', () => {
    assert.equal(rotuloDoPedido('4821'), '#4821');
    assert.equal(rotuloDoPedido('RP-123'), 'RP-123');
    assert.equal(rotuloDoPedido(null), '');
    assert.equal(textoDaDistancia(0.349), '349 m');
    assert.equal(textoDaDistancia(2.44), '2,4 km');
    assert.equal(textoDaDistancia(null), null);
    assert.ok(Math.abs(kmEntre(LOJA, { latitude: LOJA.latitude + 1 / 111.2, longitude: LOJA.longitude }) - 1) < 0.01);
});

test('há quanto tempo', () => {
    const agora = new Date('2026-10-06T09:00:00-03:00');
    assert.deepEqual(tempoDesde('2026-10-06T08:59:30-03:00', agora), { unidade: 'agora', valor: 0 });
    assert.deepEqual(tempoDesde('2026-10-06T08:30:00-03:00', agora), { unidade: 'min', valor: 30 });
    assert.deepEqual(tempoDesde('2026-10-06T06:50:00-03:00', agora), { unidade: 'h', valor: 2 });
    assert.deepEqual(tempoDesde('2026-10-06T09:05:00-03:00', agora), { unidade: 'agora', valor: 0 });
    assert.equal(tempoDesde(null, agora), null);
    assert.equal(tempoDesde('ontem', agora), null);
});

test('motoboys para a troca: online e o atual, mais perto primeiro', () => {
    const lista = motoboysParaTroca(
        [
            motoboy('driver_longe', 'Carlos', 5),
            motoboy('driver_perto', 'Bia', 0.5, { situacao: 'coleta' }),
            motoboy('driver_ana', 'Ana', 2, { online: false, situacao: 'entrega' }),
            motoboy('driver_off', 'Davi', 0.1, { online: false }),
        ],
        pedido()
    );
    assert.deepEqual(lista.map((o) => [o.id, o.atual, o.situacao]), [
        ['driver_perto', false, 'coleta'],
        ['driver_ana', true, 'entrega'],
        ['driver_longe', false, 'livre'],
    ]);
    assert.ok(Math.abs((lista[0].km ?? 0) - 0.5) < 0.01);

    const semColeta = motoboysParaTroca([motoboy('driver_z', 'Zé', 1), motoboy('driver_a', 'Ana', 3)], pedido({ coleta: null, motoboy_id: null }));
    assert.deepEqual(semColeta.map((o) => [o.nome, o.km]), [
        ['Ana', null],
        ['Zé', null],
    ]);
    assert.deepEqual(motoboysParaTroca([motoboy('driver_z', 'Zé', 1)], null), []);
});

test('espera entre leituras e pontos do mapa', () => {
    assert.equal(esperaDoMapa(0), INTERVALO_DO_MAPA_MS);
    assert.equal(esperaDoMapa(1), 20000);
    assert.equal(esperaDoMapa(2), 40000);
    assert.equal(esperaDoMapa(5), ESPERA_MAXIMA_MS);
    assert.deepEqual(pontosDoMapa({ pedidos: [pedido()], motoboys: [motoboy('driver_x', 'X', 0)] }), [
        { latitude: -21.18, longitude: -47.81 },
        { latitude: LOJA.latitude, longitude: LOJA.longitude },
    ]);
});
```

- [ ] **Step 2: rodar e ver falhar**

Run: `cd "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && git branch --show-current && node --experimental-strip-types --test scripts/testes/mapa-do-lider.teste.ts`
Expected: `app-pedidos-e-mapa-do-lider` e falha com `ERR_MODULE_NOT_FOUND` (`.../src/utils/mapa-do-lider.ts`).

- [ ] **Step 3: as funções**

Crie `src/utils/mapa-do-lider.ts`:

```ts
// Entregas: a aba Mapa do líder dos motoboys (MapaDoLiderScreen): os pedidos em andamento de todas as lojas (alfinete
// vermelho no endereço de entrega) e os motoboys (capacete na cor da situação), da rota v1/entregas/lider/mapa
// (App\Support\Entregas\MapaDoLider), e a troca do motoboy de um pedido (POST v1/entregas/lider/pedidos/{id}/motoboy).
// Quem é líder vem de v1/entregas/lider/acesso (App\Support\Entregas\LiderDosMotoboys).
// Sem imports, para os testes rodarem com o Node puro: node --experimental-strip-types --test scripts/testes/mapa-do-lider.teste.ts

export type SituacaoNoMapa = 'livre' | 'coleta' | 'entrega' | 'offline';

export type Ponto = { latitude: number; longitude: number };

export type PedidoDoLider = {
    id: string;
    numero: string;
    latitude: number;
    longitude: number;
    endereco: string | null;
    status: string | null;
    motoboy: string | null;
    aceito: boolean;
    criado_em: string | null;
    loja: string | null;
    motoboy_id: string | null;
    atualizado_em: string | null;
    coleta: Ponto | null;
};

export type MotoboyDoLider = { id: string; nome: string | null; latitude: number; longitude: number; situacao: SituacaoNoMapa; online: boolean };

export type MapaDoLider = { pedidos: PedidoDoLider[]; motoboys: MotoboyDoLider[] };

export type OpcaoDeMotoboy = { id: string; nome: string; situacao: SituacaoNoMapa; km: number | null; atual: boolean };

export type TempoDesde = { unidade: 'agora' | 'min' | 'h'; valor: number };

/** De quanto em quanto tempo o mapa é relido com a tela aberta. */
export const INTERVALO_DO_MAPA_MS = 10 * 1000;

/** A espera máxima entre leituras depois de erros seguidos. */
export const ESPERA_MAXIMA_MS = 60 * 1000;

const SITUACOES: SituacaoNoMapa[] = ['livre', 'coleta', 'entrega', 'offline'];

const numero = (valor: unknown): number | null => {
    const n = typeof valor === 'number' ? valor : typeof valor === 'string' && valor.trim() !== '' ? Number(valor) : NaN;
    return Number.isFinite(n) ? n : null;
};

const pontoValido = (latitude: unknown, longitude: unknown): Ponto | null => {
    const lat = numero(latitude);
    const lng = numero(longitude);
    if (lat === null || lng === null || Math.abs(lat) > 90 || Math.abs(lng) > 180) return null;
    if (Math.abs(lat) <= 0.0001 && Math.abs(lng) <= 0.0001) return null;
    return { latitude: lat, longitude: lng };
};

const textoOuNulo = (valor: unknown): string | null => (typeof valor === 'string' && valor.trim() !== '' ? valor : null);

/** A resposta de lider/acesso: só {"lider": true} libera a aba. */
export function acessoDoLider(resposta: unknown): boolean {
    return (resposta as { lider?: unknown } | null)?.lider === true;
}

/** A resposta de lider/mapa, só com o que dá para pôr no mapa (coordenadas válidas). Resposta estranha = mapa vazio. */
export function lerMapa(resposta: unknown): MapaDoLider {
    const bruto = (resposta ?? {}) as { pedidos?: unknown; motoboys?: unknown };
    const pedidos: PedidoDoLider[] = [];
    for (const item of Array.isArray(bruto.pedidos) ? bruto.pedidos : []) {
        const ponto = pontoValido(item?.latitude, item?.longitude);
        if (!ponto || !textoOuNulo(item?.id)) continue;
        pedidos.push({
            id: item.id,
            numero: textoOuNulo(item.numero) ?? item.id,
            ...ponto,
            endereco: textoOuNulo(item.endereco),
            status: textoOuNulo(item.status),
            motoboy: textoOuNulo(item.motoboy),
            aceito: item.aceito === true,
            criado_em: textoOuNulo(item.criado_em),
            loja: textoOuNulo(item.loja),
            motoboy_id: textoOuNulo(item.motoboy_id),
            atualizado_em: textoOuNulo(item.atualizado_em),
            coleta: item.coleta ? pontoValido(item.coleta.latitude, item.coleta.longitude) : null,
        });
    }
    const motoboys: MotoboyDoLider[] = [];
    for (const item of Array.isArray(bruto.motoboys) ? bruto.motoboys : []) {
        const ponto = pontoValido(item?.latitude, item?.longitude);
        if (!ponto || !textoOuNulo(item?.id)) continue;
        motoboys.push({
            id: item.id,
            nome: textoOuNulo(item.nome),
            ...ponto,
            situacao: SITUACOES.includes(item.situacao) ? item.situacao : 'livre',
            online: item.online === true,
        });
    }
    return { pedidos, motoboys };
}

/** "#4821" para o número do iFood (só dígitos); o código de rastreio fica como está. */
export function rotuloDoPedido(numeroDoPedido: string | null | undefined): string {
    const limpo = (numeroDoPedido ?? '').trim();
    return /^\d+$/.test(limpo) ? `#${limpo}` : limpo;
}

/** Distância em km em linha reta (haversine), a mesma conta do mapa-da-entrega. */
export function kmEntre(a: Ponto, b: Ponto): number {
    const rad = (graus: number) => (graus * Math.PI) / 180;
    const dLat = rad(b.latitude - a.latitude);
    const dLng = rad(b.longitude - a.longitude);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.latitude)) * Math.cos(rad(b.latitude)) * Math.sin(dLng / 2) ** 2;
    return 2 * 6371 * Math.asin(Math.min(1, Math.sqrt(h)));
}

/** "350 m" abaixo de 1 km, "2,4 km" acima; sem distância, null. */
export function textoDaDistancia(km: number | null): string | null {
    if (km === null || !Number.isFinite(km)) return null;
    if (km < 1) return `${Math.round(km * 1000)} m`;
    return `${km.toFixed(1).replace('.', ',')} km`;
}

/** Há quanto tempo o pedido mudou (o "há X min" do cartão); data ilegível = null. */
export function tempoDesde(iso: string | null | undefined, agora: Date): TempoDesde | null {
    const quando = iso ? Date.parse(iso) : NaN;
    if (!Number.isFinite(quando)) return null;
    const minutos = Math.max(0, Math.floor((agora.getTime() - quando) / 60000));
    if (minutos < 1) return { unidade: 'agora', valor: 0 };
    if (minutos < 60) return { unidade: 'min', valor: minutos };
    return { unidade: 'h', valor: Math.floor(minutos / 60) };
}

/**
 * Os motoboys para a troca: os online do mapa e o motoboy atual do pedido (marcado, não escolhível), com a distância em
 * linha reta até a coleta do pedido. Mais perto primeiro; sem distância (pedido sem coleta) no fim, pelo nome.
 */
export function motoboysParaTroca(motoboys: MotoboyDoLider[], pedido: PedidoDoLider | null): OpcaoDeMotoboy[] {
    if (!pedido) return [];
    const opcoes = motoboys
        .filter((motoboy) => motoboy.online || motoboy.id === pedido.motoboy_id)
        .map((motoboy) => ({
            id: motoboy.id,
            nome: motoboy.nome ?? motoboy.id,
            situacao: motoboy.situacao,
            km: pedido.coleta ? kmEntre(motoboy, pedido.coleta) : null,
            atual: motoboy.id === pedido.motoboy_id,
        }));
    return opcoes.sort((a, b) => {
        if (a.km !== null && b.km !== null && a.km !== b.km) return a.km - b.km;
        if (a.km === null && b.km !== null) return 1;
        if (a.km !== null && b.km === null) return -1;
        return a.nome.localeCompare(b.nome, 'pt-BR');
    });
}

/** Espera até a próxima leitura: o intervalo normal; com erros seguidos, dobra a cada erro até ESPERA_MAXIMA_MS. */
export function esperaDoMapa(errosSeguidos: number): number {
    if (errosSeguidos <= 0) return INTERVALO_DO_MAPA_MS;
    return Math.min(INTERVALO_DO_MAPA_MS * 2 ** errosSeguidos, ESPERA_MAXIMA_MS);
}

/** Todos os pontos do mapa (alfinetes e capacetes), para o primeiro enquadramento. */
export function pontosDoMapa(mapa: MapaDoLider): Ponto[] {
    return [...mapa.pedidos, ...mapa.motoboys].map(({ latitude, longitude }) => ({ latitude, longitude }));
}
```

- [ ] **Step 4: rodar e ver passar**

Run: `node --experimental-strip-types --test scripts/testes/mapa-do-lider.teste.ts && node --experimental-strip-types --test scripts/testes/*.teste.ts`
Expected: `# fail 0` nos dois (44 testes no segundo).

- [ ] **Step 5: commit (repo do app)**

```bash
git rev-parse --show-toplevel
git add src/utils/mapa-do-lider.ts scripts/testes/mapa-do-lider.teste.ts
git commit -m "Mapa do líder: funções puras (leitura do mapa, motoboys para a troca, distância, tempo e espera)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 10: app, a tela do mapa do líder

**Files (repo `entregas-navigator`):**
- Create: `src/hooks/use-lider.ts`
- Create: `src/screens/MapaDoLiderScreen.tsx`
- Modify: `translations/pt.json`, `translations/en.json`

- [ ] **Step 1: o acesso do líder**

Crie `src/hooks/use-lider.ts` (a tela usa o `conferirAcessoDoLider`; a Task 11 liga o resto):

```ts
import { useEffect, useSyncExternalStore } from 'react';
import { AppState } from 'react-native';
import useFleetbase from './use-fleetbase';
import { useAuth } from '../contexts/AuthContext';
import { acessoDoLider } from '../utils/mapa-do-lider';

// Entregas: se o motoboy é o líder dos motoboys (rota v1/entregas/lider/acesso, App\Support\Entregas\LiderDosMotoboys).
// O valor fica em memória, fora do React, porque quem lê é o `if` da aba Mapa na configuração estática do navegador
// (DriverNavigator), que é um hook sem acesso aos contextos do app. Começa sem aba; erro na leitura mantém o último
// valor. useAcompanharAcessoDoLider (no DriverLayout) lê ao entrar no app, ao trocar de motoboy e quando o app volta para
// a frente; a tela do mapa relê quando o mapa falha (o líder pode ter perdido o papel: aí a aba some).
let lider = false;
const ouvintes = new Set<() => void>();

const avisar = () => ouvintes.forEach((ouvinte) => ouvinte());

export function definirLider(valor: boolean) {
    if (lider === valor) return;
    lider = valor;
    avisar();
}

const assinar = (ouvinte: () => void) => {
    ouvintes.add(ouvinte);
    return () => {
        ouvintes.delete(ouvinte);
    };
};

/** true quando a aba Mapa do líder deve aparecer. */
export const useEhLider = (): boolean => useSyncExternalStore(assinar, () => lider);

let emAndamento: Promise<boolean> | null = null;
// o motoboy do último acesso lido: outro motoboy no mesmo celular começa sem a aba
let dono: string | null = null;

/** Lê o acesso no servidor e atualiza a aba; erro (sem rede, servidor fora) mantém o último valor. */
export function conferirAcessoDoLider(adapter: any): Promise<boolean> {
    if (!adapter) return Promise.resolve(lider);
    if (!emAndamento) {
        emAndamento = adapter
            .get('entregas/lider/acesso')
            .then((resposta: unknown) => {
                definirLider(acessoDoLider(resposta));
                return lider;
            })
            .catch((falha: unknown) => {
                console.warn('[lider] acesso:', falha);
                return lider;
            })
            .finally(() => {
                emAndamento = null;
            });
    }
    return emAndamento as Promise<boolean>;
}

/** Mantém o acesso em dia: ao entrar, ao trocar de motoboy (sem motoboy = sem aba) e quando o app volta para a frente. */
export const useAcompanharAcessoDoLider = () => {
    const { adapter } = useFleetbase();
    const { driver } = useAuth();
    const motoboy = driver?.id ?? null;

    useEffect(() => {
        if (dono !== motoboy) {
            dono = motoboy;
            definirLider(false);
        }
        if (!motoboy || !adapter) return;

        conferirAcessoDoLider(adapter);
        const assinatura = AppState.addEventListener('change', (estado) => {
            if (estado === 'active') conferirAcessoDoLider(adapter);
        });

        return () => assinatura.remove();
    }, [adapter, motoboy]);
};
```

- [ ] **Step 2: a tela**

Crie `src/screens/MapaDoLiderScreen.tsx`:

```tsx
import { useCallback, useMemo, useRef, useState } from 'react';
import { Alert, AppState, Pressable, StyleSheet } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import MapView, { Marker } from 'react-native-maps';
import BottomSheet, { BottomSheetScrollView } from '@gorhom/bottom-sheet';
import { Button, Separator, Spinner, Text, XStack, YStack, useTheme } from 'tamagui';
import { useLanguage } from '../contexts/LanguageContext';
import useFleetbase from '../hooks/use-fleetbase';
import { conferirAcessoDoLider } from '../hooks/use-lider';
import { getDefaultCoordinates } from '../utils/location';
import { toast } from '../utils/toast';
import { esperaDoMapa, lerMapa, motoboysParaTroca, pontosDoMapa, rotuloDoPedido, tempoDesde, textoDaDistancia } from '../utils/mapa-do-lider';
import type { MapaDoLider, OpcaoDeMotoboy, PedidoDoLider, TempoDesde } from '../utils/mapa-do-lider';
import MarcadorCapacete from '../components/MarcadorCapacete';
import Badge from '../components/Badge';

// Entregas: a aba Mapa do líder dos motoboys (só aparece para o líder: useEhLider). Mostra os pedidos em andamento de todas
// as lojas (alfinete vermelho no endereço de entrega) e os motoboys (capacete na cor da situação, com o nome), relidos a
// cada 10 s com a tela aberta e o app na frente (espera crescente em erro). Tocar num alfinete abre o cartão do pedido; o
// botão "Trocar motoboy" lista os motoboys online (mais perto da coleta primeiro) e passa o pedido para o escolhido
// (POST v1/entregas/lider/pedidos/{id}/motoboy). Se o mapa falhar, o acesso é relido: quem perdeu o papel perde a aba.
const SNAP_POINTS = ['45%', '85%'];

const MapaDoLiderScreen = () => {
    const theme = useTheme();
    const { t } = useLanguage();
    const { adapter } = useFleetbase();
    const mapRef = useRef<MapView>(null);
    const folhaRef = useRef<BottomSheet>(null);
    const enquadrouRef = useRef(false);
    const lendoRef = useRef(false);
    const errosRef = useRef(0);
    const [mapa, setMapa] = useState<MapaDoLider | null>(null);
    const [selecionado, setSelecionado] = useState<string | null>(null);
    const [modo, setModo] = useState<'cartao' | 'troca'>('cartao');
    const [trocando, setTrocando] = useState<string | null>(null);

    const inicial = useMemo(() => {
        const padrao = getDefaultCoordinates();
        return { latitude: Number(padrao.latitude), longitude: Number(padrao.longitude), latitudeDelta: 0.12, longitudeDelta: 0.12 };
    }, []);

    const enquadrar = useCallback((novo: MapaDoLider) => {
        if (enquadrouRef.current || !mapRef.current) return;
        const pontos = pontosDoMapa(novo);
        if (pontos.length === 0) return;
        enquadrouRef.current = true;
        mapRef.current.fitToCoordinates(pontos, { edgePadding: { top: 60, right: 40, bottom: 60, left: 40 }, animated: true });
    }, []);

    // uma leitura por vez; devolve false quando falhou (o ciclo espera mais)
    const ler = useCallback(async (): Promise<boolean> => {
        if (!adapter || lendoRef.current) return true;
        lendoRef.current = true;
        try {
            const novo = lerMapa(await adapter.get('entregas/lider/mapa'));
            setMapa(novo);
            enquadrar(novo);
            return true;
        } catch (falha) {
            console.warn('[lider] mapa:', falha);
            // sem o papel (403) a aba some; outro erro (rede) só espera mais
            conferirAcessoDoLider(adapter);
            return false;
        } finally {
            lendoRef.current = false;
        }
    }, [adapter, enquadrar]);

    useFocusEffect(
        useCallback(() => {
            let ativo = true;
            let proxima: ReturnType<typeof setTimeout> | null = null;

            const ciclo = async () => {
                if (!ativo) return;
                if (AppState.currentState === 'active') {
                    const deuCerto = await ler();
                    errosRef.current = deuCerto ? 0 : errosRef.current + 1;
                }
                if (ativo) proxima = setTimeout(ciclo, esperaDoMapa(errosRef.current));
            };

            ciclo();
            const assinatura = AppState.addEventListener('change', (estado) => {
                if (estado !== 'active' || !ativo) return;
                if (proxima) clearTimeout(proxima);
                ciclo();
            });

            return () => {
                ativo = false;
                if (proxima) clearTimeout(proxima);
                assinatura.remove();
            };
        }, [ler])
    );

    const pedido: PedidoDoLider | null = useMemo(() => mapa?.pedidos.find((item) => item.id === selecionado) ?? null, [mapa, selecionado]);
    const opcoes = useMemo(() => motoboysParaTroca(mapa?.motoboys ?? [], pedido), [mapa, pedido]);

    const abrirPedido = useCallback((id: string) => {
        setSelecionado(id);
        setModo('cartao');
        folhaRef.current?.snapToIndex(0);
    }, []);

    const fecharCartao = useCallback(() => {
        setSelecionado(null);
        setModo('cartao');
    }, []);

    const textoDoTempo = (tempo: TempoDesde | null) => {
        if (!tempo) return null;
        if (tempo.unidade === 'agora') return t('MapaDoLider.haPouco');
        if (tempo.unidade === 'min') return t('MapaDoLider.haMinutos', { count: tempo.valor });
        return t('MapaDoLider.haHoras', { count: tempo.valor });
    };

    const trocar = useCallback(
        (opcao: OpcaoDeMotoboy) => {
            if (!pedido || opcao.atual || trocando) return;
            const rotulo = rotuloDoPedido(pedido.numero);
            Alert.alert(t('MapaDoLider.confirmarTitulo'), t('MapaDoLider.confirmarTexto', { pedido: rotulo, nome: opcao.nome }), [
                { text: t('common.cancel'), style: 'cancel' },
                {
                    text: t('MapaDoLider.confirmarBotao'),
                    onPress: async () => {
                        setTrocando(opcao.id);
                        try {
                            await adapter.post(`entregas/lider/pedidos/${pedido.id}/motoboy`, { motoboy: opcao.id });
                            toast.success(t('MapaDoLider.trocado', { pedido: rotulo, nome: opcao.nome }));
                            setModo('cartao');
                        } catch (falha: any) {
                            toast.error(falha?.message || t('MapaDoLider.erroAoTrocar'));
                        } finally {
                            setTrocando(null);
                            ler();
                        }
                    },
                },
            ]);
        },
        [pedido, trocando, adapter, ler, t]
    );

    const Cartao = () => {
        if (!pedido) {
            return (
                <YStack p='$4'>
                    <Text color='$textSecondary'>{t('MapaDoLider.pedidoSaiu')}</Text>
                </YStack>
            );
        }
        const tempo = textoDoTempo(tempoDesde(pedido.atualizado_em, new Date()));
        return (
            <YStack px='$4' pb='$4' gap='$3'>
                <XStack justifyContent='space-between' alignItems='center' gap='$2'>
                    <Text color='$textPrimary' fontSize={20} fontWeight='bold' flex={1} numberOfLines={1}>
                        {t('MapaDoLider.pedido', { pedido: rotuloDoPedido(pedido.numero) })}
                    </Text>
                    {pedido.status && <Badge status={pedido.status} />}
                </XStack>
                {!!pedido.loja && (
                    <Text color='$textPrimary' fontSize={16}>
                        {pedido.loja}
                    </Text>
                )}
                <YStack gap='$1'>
                    <Text color='$textSecondary' fontSize={12}>
                        {t('MapaDoLider.motoboy')}
                    </Text>
                    <Text color='$textPrimary' fontSize={15} fontWeight='bold'>
                        {pedido.motoboy ?? t('MapaDoLider.semMotoboy')}
                    </Text>
                </YStack>
                {!!tempo && (
                    <Text color='$textSecondary' fontSize={13}>
                        {t('MapaDoLider.atualizado', { tempo })}
                    </Text>
                )}
                {!!pedido.endereco && (
                    <YStack gap='$1'>
                        <Text color='$textSecondary' fontSize={12}>
                            {t('MapaDoLider.entrega')}
                        </Text>
                        <Text color='$textPrimary' fontSize={14}>
                            {pedido.endereco}
                        </Text>
                    </YStack>
                )}
                <Button onPress={() => setModo('troca')} bg='$info' borderWidth={1} borderColor='$infoBorder'>
                    <Button.Text color='$infoText'>{t('MapaDoLider.trocarMotoboy')}</Button.Text>
                </Button>
            </YStack>
        );
    };

    const Troca = () => (
        <YStack px='$4' pb='$4' gap='$2'>
            <XStack justifyContent='space-between' alignItems='center'>
                <Text color='$textPrimary' fontSize={18} fontWeight='bold' flex={1} numberOfLines={1}>
                    {t('MapaDoLider.escolhaOMotoboy', { pedido: rotuloDoPedido(pedido?.numero) })}
                </Text>
                <Button size='$3' onPress={() => setModo('cartao')} bg='$surface' borderWidth={1} borderColor='$borderColor'>
                    <Button.Text color='$textPrimary'>{t('MapaDoLider.voltar')}</Button.Text>
                </Button>
            </XStack>
            {opcoes.length === 0 && <Text color='$textSecondary'>{t('MapaDoLider.ninguemOnline')}</Text>}
            {opcoes.map((opcao, indice) => {
                const distancia = textoDaDistancia(opcao.km);
                return (
                    <YStack key={opcao.id}>
                        {indice > 0 && <Separator />}
                        <Pressable onPress={() => trocar(opcao)} disabled={opcao.atual || !!trocando}>
                            <XStack py='$3' alignItems='center' gap='$3' opacity={opcao.atual ? 0.6 : 1}>
                                <YStack flex={1}>
                                    <Text color='$textPrimary' fontSize={16} fontWeight='bold' numberOfLines={1}>
                                        {opcao.atual ? t('MapaDoLider.atual', { nome: opcao.nome }) : opcao.nome}
                                    </Text>
                                    <Text color='$textSecondary' fontSize={13}>
                                        {[t(`MapaDoLider.situacao.${opcao.situacao}`), distancia ? t('MapaDoLider.daColeta', { distancia }) : null].filter(Boolean).join(' · ')}
                                    </Text>
                                </YStack>
                                {trocando === opcao.id && <Spinner color='$textPrimary' />}
                            </XStack>
                        </Pressable>
                    </YStack>
                );
            })}
        </YStack>
    );

    return (
        <YStack flex={1} bg='$background'>
            <MapView ref={mapRef} style={StyleSheet.absoluteFillObject} initialRegion={inicial} toolbarEnabled={false} onMapReady={() => mapa && enquadrar(mapa)}>
                {(mapa?.pedidos ?? []).map((item) => (
                    <Marker
                        key={`pedido-${item.id}`}
                        coordinate={{ latitude: item.latitude, longitude: item.longitude }}
                        pinColor='red'
                        tracksViewChanges={false}
                        onPress={() => abrirPedido(item.id)}
                        zIndex={selecionado === item.id ? 20 : 5}
                    />
                ))}
                {(mapa?.motoboys ?? []).map((motoboy) => (
                    <MarcadorCapacete key={`motoboy-${motoboy.id}`} coordenada={{ latitude: motoboy.latitude, longitude: motoboy.longitude }} situacao={motoboy.situacao} nome={motoboy.nome} />
                ))}
            </MapView>

            {mapa === null && (
                <YStack position='absolute' top={12} alignSelf='center' bg='$background' px='$3' py='$2' borderRadius='$4' borderWidth={1} borderColor='$borderColor'>
                    <Spinner color='$textPrimary' />
                </YStack>
            )}
            {mapa !== null && mapa.pedidos.length === 0 && (
                <YStack position='absolute' top={12} alignSelf='center' bg='$background' px='$3' py='$2' borderRadius='$4' borderWidth={1} borderColor='$borderColor'>
                    <Text color='$textSecondary'>{t('MapaDoLider.nenhumPedido')}</Text>
                </YStack>
            )}

            <BottomSheet
                ref={folhaRef}
                index={-1}
                snapPoints={SNAP_POINTS}
                enablePanDownToClose
                enableDynamicSizing={false}
                onClose={fecharCartao}
                backgroundStyle={{ backgroundColor: theme.background.val, borderWidth: 1, borderColor: theme.borderColorWithShadow.val }}
                handleIndicatorStyle={{ backgroundColor: theme.secondary.val }}
            >
                <BottomSheetScrollView>{modo === 'troca' && pedido ? <Troca /> : <Cartao />}</BottomSheetScrollView>
            </BottomSheet>
        </YStack>
    );
};

export default MapaDoLiderScreen;
```

- [ ] **Step 3: os textos**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && node - <<'EOF'
const fs = require('fs');
const textos = {
    'translations/pt.json': {
        MapaDoLider: {
            pedido: 'Pedido {{pedido}}',
            motoboy: 'Motoboy',
            semMotoboy: 'Sem motoboy',
            entrega: 'Entrega',
            atualizado: 'Atualizado {{tempo}}',
            haPouco: 'agora há pouco',
            haMinutos: 'há {{count}} min',
            haHoras: 'há {{count}} h',
            trocarMotoboy: 'Trocar motoboy',
            escolhaOMotoboy: 'Passar o pedido {{pedido}} para',
            voltar: 'Voltar',
            atual: '{{nome}} (atual)',
            daColeta: '{{distancia}} da coleta',
            ninguemOnline: 'Nenhum motoboy online agora.',
            confirmarTitulo: 'Trocar motoboy',
            confirmarTexto: 'Passar o pedido {{pedido}} para {{nome}}?',
            confirmarBotao: 'Passar',
            trocado: 'Pedido {{pedido}} passou para {{nome}}.',
            erroAoTrocar: 'Não foi possível trocar o motoboy. Tente de novo.',
            pedidoSaiu: 'Este pedido não está mais em andamento.',
            nenhumPedido: 'Nenhum pedido em andamento',
            situacao: { livre: 'Livre', coleta: 'Indo à loja', entrega: 'Em entrega', offline: 'Offline' },
        },
    },
    'translations/en.json': {
        MapaDoLider: {
            pedido: 'Order {{pedido}}',
            motoboy: 'Courier',
            semMotoboy: 'No courier',
            entrega: 'Drop-off',
            atualizado: 'Updated {{tempo}}',
            haPouco: 'just now',
            haMinutos: '{{count}} min ago',
            haHoras: '{{count}} h ago',
            trocarMotoboy: 'Change courier',
            escolhaOMotoboy: 'Give order {{pedido}} to',
            voltar: 'Back',
            atual: '{{nome}} (current)',
            daColeta: '{{distancia}} from pickup',
            ninguemOnline: 'No courier online right now.',
            confirmarTitulo: 'Change courier',
            confirmarTexto: 'Give order {{pedido}} to {{nome}}?',
            confirmarBotao: 'Give',
            trocado: 'Order {{pedido}} now belongs to {{nome}}.',
            erroAoTrocar: 'Could not change the courier. Try again.',
            pedidoSaiu: 'This order is no longer in progress.',
            nenhumPedido: 'No orders in progress',
            situacao: { livre: 'Available', coleta: 'Going to the store', entrega: 'Delivering', offline: 'Offline' },
        },
    },
};
const juntar = (alvo, novo) => {
    for (const [chave, valor] of Object.entries(novo)) {
        alvo[chave] = valor && typeof valor === 'object' && !Array.isArray(valor) ? juntar(alvo[chave] ?? {}, valor) : valor;
    }
    return alvo;
};
for (const [arquivo, novo] of Object.entries(textos)) {
    const json = JSON.parse(fs.readFileSync(arquivo, 'utf8'));
    fs.writeFileSync(arquivo, JSON.stringify(juntar(json, novo), null, 4) + '\n');
    console.log('OK', arquivo);
}
EOF
```

Expected: `OK` nos dois.

- [ ] **Step 4: conferir**

```bash
B=$(ls -d "/c/Users/Edgardjr/Documents/vibe coding/Delivery/console/node_modules/.pnpm"/@babel+parser@7*/node_modules/@babel/parser | head -1)
node -e "const p=require(require('path').resolve(process.argv[1]));for(const f of process.argv.slice(2)){p.parse(require('fs').readFileSync(f,'utf8'),{sourceType:'module',plugins:['typescript','jsx']});console.log('OK',f)}" "$B" src/hooks/use-lider.ts src/screens/MapaDoLiderScreen.tsx
# toda chave t('MapaDoLider.…') da tela existe nos dois idiomas
for k in $(grep -oE "t\('MapaDoLider\.[a-zA-Z.]+'" src/screens/MapaDoLiderScreen.tsx | sed "s/t('//;s/'//" | sort -u) MapaDoLider.situacao.livre MapaDoLider.situacao.coleta MapaDoLider.situacao.entrega MapaDoLider.situacao.offline; do node -e "const g=(o,k)=>k.split('.').reduce((a,c)=>a?.[c],o);for(const f of ['pt','en']){if(typeof g(require('./translations/'+f+'.json'),'$k')!=='string')console.log('FALTA',f,'$k')}"; done; echo conferido
```

Expected: `OK` nos dois arquivos e só `conferido` (nenhum `FALTA`).

- [ ] **Step 5: commit (repo do app)**

```bash
git rev-parse --show-toplevel
git add src/hooks/use-lider.ts src/screens/MapaDoLiderScreen.tsx translations/pt.json translations/en.json
git commit -m "Mapa do líder: tela com pedidos e motoboys, cartão do pedido e troca do motoboy

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 11: app, aba Mapa no lugar de Relatórios e o push do pedido trocado

**Files (repo `entregas-navigator`):**
- Modify: `src/navigation/DriverNavigator.tsx`
- Modify: `config/default.js`
- Modify: `src/layouts/DriverLayout.tsx`
- Modify: `translations/pt.json`, `translations/en.json`

- [ ] **Step 1: a aba no navegador**

Em `src/navigation/DriverNavigator.tsx`:

1. No import dos ícones, troque

```tsx
    faFlag,
    faTimes,
} from '@fortawesome/free-solid-svg-icons';
```

por

```tsx
    faFlag,
    faTimes,
    faMap,
} from '@fortawesome/free-solid-svg-icons';
```

2. Depois de `import DriverAccountScreen from '../screens/DriverAccountScreen';`, acrescente:

```tsx
import MapaDoLiderScreen from '../screens/MapaDoLiderScreen';
import { useEhLider } from '../hooks/use-lider';
```

3. No `importedIconsMap`, troque

```tsx
    faTriangleExclamation,
    faFlag,
};
```

por

```tsx
    faTriangleExclamation,
    faFlag,
    faMap,
};
```

4. No `createTabScreens`, troque

```tsx
    const tabs = toArray(navigatorConfig('driverNavigator.tabs', 'DriverDashboardTab,DriverTaskTab,DriverReportTab,DriverChatTab,DriverAccountTab'));
```

por

```tsx
    // Entregas: a aba Relatórios saiu; a aba Mapa só aparece para o líder dos motoboys (`if: useEhLider`)
    const tabs = toArray(navigatorConfig('driverNavigator.tabs', 'DriverDashboardTab,DriverTaskTab,DriverMapaTab,DriverChatTab,DriverAccountTab'));
```

5. Ainda no `createTabScreens`, antes de `        DriverChatTab: {`, acrescente:

```tsx
        DriverMapaTab: {
            if: useEhLider,
            screen: DriverMapaTab,
            options: () => {
                return {
                    tabBarLabel: config('DRIVER_MAPA_TAB_LABEL') || translate('DriverNavigator.tabs.mapa'),
                };
            },
        },
```

6. No `getDefaultTabIcon`, troque

```tsx
        case 'DriverReportTab':
            icon = faFlag;
            break;
```

por

```tsx
        case 'DriverReportTab':
            icon = faFlag;
            break;
        case 'DriverMapaTab':
            icon = faMap;
            break;
```

7. Antes de `const DriverChatTab = createNativeStackNavigator({`, acrescente:

```tsx
// Entregas: aba Mapa do líder dos motoboys (pedidos em andamento, motoboys e a troca do motoboy de um pedido)
const DriverMapaTab = createNativeStackNavigator({
    initialRouteName: 'MapaDoLider',
    screens: {
        MapaDoLider: {
            screen: MapaDoLiderScreen,
            options: ({ route, navigation }) => {
                return {
                    headerShown: false,
                };
            },
        },
    },
});

```

- [ ] **Step 2: a lista padrão de abas**

Em `config/default.js`, troque

```js
        tabs: toArray(config('DRIVER_NAVIGATOR_TABS', 'DriverDashboardTab,DriverTaskTab,DriverReportTab,DriverChatTab,DriverAccountTab')),
```

por

```js
        // Entregas: sem a aba Relatórios; a aba Mapa (DriverMapaTab) só aparece para o líder dos motoboys
        tabs: toArray(config('DRIVER_NAVIGATOR_TABS', 'DriverDashboardTab,DriverTaskTab,DriverMapaTab,DriverChatTab,DriverAccountTab')),
```

- [ ] **Step 3: o acesso e o push no layout**

Em `src/layouts/DriverLayout.tsx`:

1. Depois de `import useVerificacaoDoAlarme from '../hooks/use-verificacao-do-alarme';`, acrescente:

```tsx
import { useAcompanharAcessoDoLider } from '../hooks/use-lider';
```

2. Troque

```tsx
    useVerificacaoDoAlarme();
```

por

```tsx
    useVerificacaoDoAlarme();
    // Entregas: mostra ou esconde a aba Mapa do líder dos motoboys (lido ao entrar e ao voltar para o app)
    useAcompanharAcessoDoLider();
```

3. No `handlePushNotification`, troque

```tsx
            if (typeof id === 'string' && id.startsWith('order_')) {
                // Reload active orders
```

por

```tsx
            // Entregas: o líder passou um pedido deste motoboy para outro (PedidoPassadoParaOutro, sem "id": não abre o pedido)
            if (type === 'entregas_pedido_trocado') {
                reloadActiveOrders();
                reloadNearbyOrders();
                return;
            }

            if (typeof id === 'string' && id.startsWith('order_')) {
                // Reload active orders
```

- [ ] **Step 4: o rótulo da aba**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && node - <<'EOF'
const fs = require('fs');
const textos = {
    'translations/pt.json': { DriverNavigator: { tabs: { mapa: 'Mapa' } } },
    'translations/en.json': { DriverNavigator: { tabs: { mapa: 'Map' } } },
};
const juntar = (alvo, novo) => {
    for (const [chave, valor] of Object.entries(novo)) {
        alvo[chave] = valor && typeof valor === 'object' && !Array.isArray(valor) ? juntar(alvo[chave] ?? {}, valor) : valor;
    }
    return alvo;
};
for (const [arquivo, novo] of Object.entries(textos)) {
    const json = JSON.parse(fs.readFileSync(arquivo, 'utf8'));
    fs.writeFileSync(arquivo, JSON.stringify(juntar(json, novo), null, 4) + '\n');
    console.log('OK', arquivo);
}
EOF
```

Expected: `OK` nos dois.

- [ ] **Step 5: conferir**

```bash
B=$(ls -d "/c/Users/Edgardjr/Documents/vibe coding/Delivery/console/node_modules/.pnpm"/@babel+parser@7*/node_modules/@babel/parser | head -1)
node -e "const p=require(require('path').resolve(process.argv[1]));for(const f of process.argv.slice(2)){p.parse(require('fs').readFileSync(f,'utf8'),{sourceType:'module',plugins:['typescript','jsx']});console.log('OK',f)}" "$B" src/navigation/DriverNavigator.tsx src/layouts/DriverLayout.tsx config/default.js src/screens/MapaDoLiderScreen.tsx src/hooks/use-lider.ts src/screens/DriverOrderManagementScreen.tsx src/components/OrderCard.tsx
grep -c "DriverReportTab,\|,DriverReportTab" config/default.js src/navigation/DriverNavigator.tsx
node --experimental-strip-types --test scripts/testes/*.teste.ts
```

Expected: `OK` nos sete; `0` nos dois arquivos do `grep` (a aba Relatórios saiu das listas); `# fail 0`.

- [ ] **Step 6: commit (repo do app)**

```bash
git rev-parse --show-toplevel
git add src/navigation/DriverNavigator.tsx config/default.js src/layouts/DriverLayout.tsx translations/pt.json translations/en.json
git commit -m "Mapa do líder: aba Mapa (só para o líder) no lugar de Relatórios; lista recarrega quando o pedido passa para outro

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

# Documentação e verificação

### Task 12: documentação

**Files:**
- Modify: `CLAUDE.md` (repo Delivery, ramo `app-pedidos-e-mapa-do-lider`)

- [ ] **Step 1: seção "App do motoboy"**

Em `CLAUDE.md`, na seção "App do motoboy (Navigator próprio)", logo antes do item `- **Detalhes do pedido no app**`,
acrescente:

```markdown
- **Aba Pedidos** (`src/screens/DriverOrderManagementScreen.tsx`, decisão de 2026-10-06; desenho: `docs/superpowers/specs/2026-10-06-app-pedidos-e-mapa-do-lider-design.md`): só o que o motoboy tem para fazer agora, em duas seções: **Novos pedidos** (os abertos por perto, com o card de aceitar) e **Em andamento** (os dele não encerrados, de qualquer dia: `allActiveOrders`). Sem o seletor de data e sem o resumo do dia (o histórico fica no Início). O card (`OrderCard`) mostra o número do pedido (o do iFood; sem ele, o código de rastreio) e a loja da coleta no lugar dos campos do Fleetbase. Funções puras em `src/utils/lista-de-pedidos.ts` (`scripts/testes/lista-de-pedidos.teste.ts`).
- **Aba Mapa do líder dos motoboys** (no lugar da aba Relatórios, que saiu; mesma decisão e desenho): só para o **líder** (ou administrador com cadastro de motoboy). Mostra os pedidos em andamento de todas as lojas (alfinete vermelho) e os motoboys (capacete na cor da situação, com o nome), e deixa passar um pedido para outro motoboy.
  - **Quem é líder** (`App\Support\Entregas\LiderDosMotoboys`): o motoboy da sessão (token do app; a chave `flb_live_` do APK nunca é líder) que é administrador ou tem, na empresa, a permissão `fleet-ops assign-driver-for order` (ou `fleet-ops * order`, `fleet-ops *`), lida pelo `CompanyUser::getAllPermissions` (diretas, papéis e políticas, como o `AuthorizationGuard` do console; o `Auth::can` não vê as políticas). Vínculo desativado no IAM não é líder.
  - **Dar o papel:** Admin → IAM → Papéis → papel **"Líder de motoboys"** com a política "Operações do motorista" (`DriverOperations`, a do papel Motorista) e a permissão `fleet-ops assign-driver-for order`; depois IAM → Usuários → aba **Motoristas** → editar o motoboy → Papel. O papel substitui o "Motorista" (um papel por usuário), por isso a política vai junto. Alternativa: manter "Motorista" e marcar só a permissão em "Selecionar Permissões" do usuário. O `fleetops:assign-driver-roles` do Fleet-Ops (não agendado) devolveria o papel "Motorista" a todos.
  - **API** (`Entregas/LiderController.php`, limitador `entregas-lider`, 120 por minuto por usuário): `GET v1/entregas/lider/acesso` → `{"lider": bool}` (nunca 403); `GET v1/entregas/lider/mapa` (só líder; `MapaDoLider`): `pedidos` = `PedidosNoMapa::doLider` (os do mapa do console, com o número do iFood, `motoboy_id`, coleta e `atualizado_em`) e `motoboys` = os do mapa do portal (`MotoboysNoMapaDaLoja::noMapa`) com o public_id e o online, sem telefone; `POST v1/entregas/lider/pedidos/{id}/motoboy` com `{"motoboy": "driver_…"}` (`TrocaDoMotoboy`): 404 pedido, 409 encerrado ou trava ocupada, 422 motoboy, 200 com o pedido (mesmo motoboy: sem mudança).
  - **A troca** segue a do console: com a `TravaDoPedido`, desliga o pedido aberto (`adhoc` falso) e chama o `assignDriver($motoboy, true)` do Order (silencioso: o save → `OrderObserver` → um `OrderDriverAssigned`, com o push "Novo pedido para você" ao novo e, no pedido iFood, o `assignDriver` ao iFood). O pedido fica com o status que tinha. O anterior recebe o `PedidoPassadoParaOutro` ("Pedido #4821 passou para outro motoboy.", canal `avisos`, tipo `entregas_pedido_trocado`, **sem `id`** nos dados: o app só recarrega a lista, não abre o pedido). Log `[entregas] líder trocou o motoboy` (só ids) no `docker service logs entregas_application`.
  - **App:** aba `DriverMapaTab` com `if: useEhLider` (`src/hooks/use-lider.ts`: acesso em memória, lido ao entrar e ao voltar para o app); `MapaDoLiderScreen` relê o mapa a cada 10 s com a tela aberta (espera crescente em erro; no erro, relê o acesso e, sem o papel, a aba some); cartão do pedido (bottom sheet) com "Trocar motoboy": os online, mais perto da coleta primeiro, e o atual marcado. Funções puras em `src/utils/mapa-do-lider.ts`.
  - **Riscos aceitos:** o líder vê os endereços e os pedidos de todas as lojas, como a central; o papel também vale no console se esse usuário entrar nele.
  - **Ao atualizar o fleetops-api, confira** o `Order::assignDriver($driver, $silent)`, o `OrderObserver::updated` (dispara o `OrderDriverAssigned`) e o `$order->adhoc === false` do `HandleOrderDriverAssigned`: o `scripts/teste-php/lider.php` confere os três na cópia de `packages/`.
  - Testes: `scripts/teste-php/lider.php`, `pedidos-no-mapa.php`, `mapa-da-loja.php` e `avisos-push.php`; no app, `scripts/testes/mapa-do-lider.teste.ts`.
```

- [ ] **Step 2: Histórico**

No fim da seção "Histórico (2026-10-01)", depois do item `19. Integração iFood, etapa 4 …`, acrescente:

```markdown
20. App do motoboy: aba Pedidos enxuta (novos e em andamento, card com o número e a loja da coleta) e aba Mapa do líder dos motoboys no lugar de Relatórios, com a troca do motoboy de um pedido (2026-10-06, ramos `app-pedidos-e-mapa-do-lider` aqui e no `entregas-navigator`).
```

- [ ] **Step 3: commit**

```bash
git rev-parse --show-toplevel
git add CLAUDE.md
git commit -m "Documentação: aba Pedidos enxuta e aba Mapa do líder dos motoboys

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 13: verificação em produção (Edgard + Claude)

O Claude não tem SSH: os comandos na VPS e no Portainer são do Edgard. Use a loja de teste (Terraço Pizza Bar) e avise os
motoboys antes de gerar pedidos.

- [ ] **Step 1: deploy da API**

1. O Edgard decide como juntar os ramos (`app-pedidos-e-mapa-do-lider` sobre o `ifood-etapa-4`) e faz o push.
2. Na VPS: `cd ~/entregas && bash deploy/atualizar.sh api` (atualiza a API, a fila e o scheduler; não há migration).
3. Conferir que as rotas respondem: no app antigo nada muda; `docker service logs entregas_application 2>&1 | grep 'entregas/lider'`
   depois do Step 3 mostra as chamadas.

- [ ] **Step 2: APK**

1. Push do ramo do app na `main` do `entregas-navigator` (decisão do Edgard) → o GitHub Actions gera `entregas-motoboy-<n>`.
   Se o build falhar no TypeScript ou no bundle, corrija pelo log do Actions antes de seguir.
2. Instale em dois celulares: o do futuro líder e o de um motoboy comum.
3. Expected nos dois: abas Início, Pedidos, Conversas e Conta (sem Relatórios e, por enquanto, sem Mapa). A aba Pedidos sem
   o calendário, com "Nenhum pedido no momento" ou as seções "Novos pedidos (N)" e "Em andamento (N)"; o card em andamento
   com o número (iFood ou rastreio) e "Coleta: <loja>".

- [ ] **Step 3: o papel no IAM**

1. Console → Admin → IAM → Papéis → Novo papel "Líder de motoboys": anexar a política "Operações do motorista" e marcar a
   permissão `fleet-ops assign-driver-for order`. Salvar.
2. IAM → Usuários → aba Motoristas → editar o motoboy líder → Papel = "Líder de motoboys" → Salvar. **A conferir:** salva
   sem erro (motoboy sem e-mail).
3. No celular do líder: tirar o app da frente e voltar (ou reabrir). Expected: a aba **Mapa** aparece. No celular do motoboy
   comum, não. **A conferir:** se a aba aparece sem reabrir o app.

- [ ] **Step 4: o mapa e a troca**

1. Crie um pedido pelo portal da loja de teste e aceite com o motoboy comum (A). Expected no Mapa do líder: o alfinete
   vermelho no destino e os capacetes (A amarelo), relidos a cada ~10 s.
2. Toque no alfinete: cartão com número, loja, status, "A", "Atualizado há X min" e o endereço. "Trocar motoboy": lista dos
   online com a situação e a distância até a coleta, A marcado "(atual)".
3. Escolha outro motoboy (B; pode ser o próprio líder) → confirmação "Passar o pedido … para B?" → "Passar". Expected:
   toast "Pedido … passou para B."; no celular de B, o push "Novo pedido para você" (alarme); no de A, "Pedido … passou
   para outro motoboy." (sem abrir o pedido) e o pedido some da aba Pedidos; no console, o pedido com B e o mesmo status;
   `docker service logs entregas_application 2>&1 | grep 'líder trocou o motoboy'` com os ids.
4. Erros: trocar para o motoboy atual (não escolhível); concluir o pedido e, com o cartão aberto, tentar trocar → toast
   "Este pedido já foi encerrado." (**a conferir:** a mensagem do servidor chega no toast).
5. Pedido iFood de teste (se houver): trocar o motoboy depois do "Iniciado" e ver no painel iFood do console se o iFood
   aceitou o novo `assignDriver` ou recusou (aviso de recusa).

- [ ] **Step 5: tirar o papel**

Tire o papel do líder no IAM (volte para "Motorista"). Expected: na próxima leitura do mapa (até ~10 s com a aba aberta)
ou ao voltar para o app, a aba Mapa some. **A conferir:** o app vai para outra aba sem erro.

- [ ] **Step 6: registrar**

Atualize o `CLAUDE.md` (itens da aba Mapa do líder) com o que foi conferido (aba sem reabrir o app, formulário do IAM,
mensagem do toast, gestos do bottom sheet, troca no iFood). Commit só desse arquivo, com o trailer.
