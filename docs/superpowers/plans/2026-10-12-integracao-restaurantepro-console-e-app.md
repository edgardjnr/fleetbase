# Integração RestaurantePro: console, portal e APK (plano 2 de 3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mostrar nas telas (console, portal da loja e app do motoboy) os pedidos que chegam de outros sistemas pela integração (`v1/entregas/integracao/*`), deixar a central gerar e revogar a chave da loja na tela Lojas e trocar os rótulos "Cancelado pelo iFood" pelo rótulo da origem certa. Este plano precisa estar em produção **antes** de ligar `ENTREGAS_INTEGRACAO=1`.

**Architecture:** A API já está em produção (plano 1; **o código da `api/app` é a referência**) e este plano não muda nada nela. No console e no portal, a origem do pedido sai das notas (`"<Sistema> #<numero>"`, com `internal_id` = número), como o selo do iFood da etapa 4. O painel do detalhe e o bloco da tela Lojas leem as rotas `int/v1/entregas/...` que já existem. No app, um hook no molde do `useDadosIfood` lê `GET v1/entregas/motoboy/pedidos/{id}/integracao`, e um componente próprio mostra o selo, a faixa amarela da cobrança e o "Ligar para o cliente". As regras ficam em funções puras testadas com o `node --test`.

**Tech Stack:** Ember (Glimmer, ember-concurrency, ember-intl 6) nos engines `packages/fleetops` e `packages/customer-portal`; React Native + Tamagui + i18next no `entregas-navigator`; Kotlin (cartão do alarme, só compila no GitHub Actions); `node --test`.

**Spec:** `docs/superpowers/specs/2026-10-12-integracao-restaurantepro-design.md`, Parte 4. Plano 1: `docs/superpowers/plans/2026-10-12-integracao-restaurantepro-api.md`. No `CLAUDE.md`, veja a seção "Integração de pedidos de outros sistemas (RestaurantePro)".

---

## O que a API devolve (conferido no código em 2026-10-12)

Use estes formatos. Não invente campos.

**`GET int/v1/entregas/lojas/{id}/integracao`** (só admin; `IntegracaoLojasController::situacao`), `PainelDaIntegracao::daLoja`:

```json
{ "integracao": {
    "ligada": true, "ativa": true, "chave_final": "a1B2", "sistema": "RestaurantePro", "conectada": true,
    "webhook_host": "api.restaurantepro.com.br", "gerada_em": "2026-10-12T10:00:00-03:00",
    "conectada_em": "2026-10-12T10:05:00-03:00", "ultimo_uso_em": "2026-10-12T11:58:00-03:00",
    "ultima_falha_aviso": null, "ultima_falha_aviso_em": null } }
```

- Sem chave ativa: `ativa: false`, e os outros campos vêm `null` ou `false`. `ligada` vem sempre (é o `ENTREGAS_INTEGRACAO`).
- `ultima_falha_aviso` é apagada no próximo aviso entregue (`ChavesDeIntegracao`, linhas 138 a 141). Se ela existe, é a falha atual.

**`POST .../integracao/chave`**: `{ "chave": "ent_live_…", "integracao": {…daLoja} }`. Revoga a chave anterior. 409 `{"errors": ["Integração desligada."]}` com a integração desligada.

**`DELETE .../integracao`**: `{ "integracao": {…daLoja(null)} }`. Funciona sempre.

**`GET int/v1/entregas/pedidos/{id}/integracao`** (só admin; `PainelDaIntegracao::doPedido`). Pedido que não é da integração: `{"integracao": false}`. Pedido da integração:

```json
{ "integracao": true, "sistema": "RestaurantePro", "pedido_externo_id": "ord-6f1c", "numero": "4821",
  "cobranca": { "forma": "dinheiro", "centavos": 5890, "troco_para_centavos": 10000, "texto": "Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100" },
  "observacoes": "Sem cebola", "sem_localizacao": false, "despachado_em": "…", "ultimo_aviso": "aceito",
  "cancelado_em": null, "cancelado_por": null, "pago_mesmo_cancelado": false,
  "ultima_falha_aviso": null, "ultima_falha_aviso_em": null }
```

- `cobranca.forma`: `"pago"`, `"dinheiro"`, `"cartão (maquininha)"`, `"Pix"` ou `null` (`CobrancaDaIntegracao::forma`). `cobranca.texto` é `null` quando não há nada a cobrar.
- `ultimo_aviso`: `aceito` | `a_caminho` | `concluido` | `cancelado` | `null` (`SequenciaDaIntegracao`).
- `cancelado_por`: `loja` | `central` | `sistema` | `null`.

**`GET v1/entregas/motoboy/pedidos/{id}/integracao`** (app; `DadosDaIntegracaoDoMotoboy::resposta`): `{"integracao": false}` ou

```json
{ "integracao": true, "sistema": "RestaurantePro", "numero": "4821",
  "cobranca": { "forma": "dinheiro", "centavos": 5890, "troco_para_centavos": 10000, "texto": "Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100" },
  "observacoes": "Sem cebola", "complemento": "Ap 12", "referencia": "Portão azul",
  "cancelado": false, "pago_mesmo_cancelado": false, "telefone": "16999990000" }
```

- `telefone` só vem para o motoboy do pedido, com o pedido não encerrado. Nos outros casos vem `null`.

**Notas do Order** (`CriadorDoPedidoDaIntegracao::notas`): `"<Sistema> #<numero>"`, mais `" [SEM LOCALIZAÇÃO]"` quando falta a localização, e as observações numa linha nova. `internal_id` = `numero`. O sistema passa pelo `Integracao::nomeDoSistema`: só letras, números, espaço, ponto, hífen e sublinhado, até 40 caracteres. Vazio ou começando com "ifood" vira "Integração".

**Push do alarme** (`CartaoDoAlarme::integracao`): `entregas_origem` = `"RestaurantePro #4821"` e `entregas_cobrar` = texto da cobrança (ausente quando já está pago).

**`cancelado_origem`** (`ifood` | `integracao` | `null`, junto do `cancelado_pago`): vem em cada entrega de `GET int/v1/entregas/pagamento-motoboys` (`CalculoEntregas`), do extrato do portal (`PortalLojaController::extrato`) e dos ganhos do app (`GanhosDoMotoboy::linha`). Servidor antigo: o campo não vem, e o rótulo continua o do iFood.

---

## Estrutura de arquivos

### Repo `Delivery` (ramo `integracao-console-e-app`)

| Arquivo | Ação | Responsabilidade |
|---|---|---|
| `packages/fleetops/addon/utils/pedido-de-origem.js` | criar | Origem pelas notas (iFood ou sistema), chaves de tradução do aviso, de quem cancelou e do cancelado pago. Sem imports. |
| `packages/fleetops/addon/utils/integracao-da-loja.js` | criar | Situação do bloco "Integração" e o "há X min". Sem imports. |
| `packages/fleetops/addon/components/pedido-de-origem/selo.{js,hbs}` + `app/components/pedido-de-origem/selo.js` | criar | Selo "iFood #N" ou "<Sistema> #N". |
| `packages/fleetops/addon/components/cell/pedido-de-origem.hbs` + `app/components/cell/pedido-de-origem.js` | criar | Célula da coluna "Origem". |
| `packages/fleetops/addon/components/pedido-ifood/selo.{js,hbs}`, `app/components/pedido-ifood/selo.js`, `addon/components/cell/pedido-ifood.hbs`, `app/components/cell/pedido-ifood.js` | apagar | Substituídos pelo selo de origem. |
| `packages/fleetops/addon/components/order/details/integracao.{js,hbs}` + `app/components/order/details/integracao.js` | criar | Painel "Integração" do detalhe (só admin). |
| `packages/fleetops/addon/components/loja/integracao.{js,hbs}` + `app/components/loja/integracao.js` | criar | Bloco "Integração" no card da loja. |
| `packages/fleetops/addon/components/modals/chave-da-integracao.{js,hbs}` + `app/components/modals/chave-da-integracao.js` | criar | Modal da chave, mostrada uma vez, com o copiar. |
| `packages/fleetops/addon/components/{map/order-list-overlay/order,order/kanban-card,order/panel-header,order/details}.hbs` | alterar | Usar o selo de origem e montar o painel. |
| `packages/fleetops/addon/controllers/operations/orders/index.js` | alterar | Coluna "Origem". |
| `packages/fleetops/addon/templates/management/lojas.hbs` | alterar | Montar o bloco "Integração". |
| `packages/fleetops/addon/controllers/management/driver-payouts.js`, `templates/management/driver-payouts.hbs` | alterar | Rótulo do cancelado pago pela origem. |
| `packages/fleetops/translations/{en-us,pt-br}.yaml` | alterar | `fleet-ops.ui.origem.*`, `fleet-ops.ui.integracao.*`, `fleet-ops.ui.lojas.integracao.*` e `fleet-ops.ui.driver-payouts.cancelado-pago-*`. |
| `packages/customer-portal/addon/utils/entregas-pedido.js` | alterar | `origemDoPedido` e `chaveDoCanceladoCobrado`. |
| `packages/customer-portal/addon/components/portal/order/selo-de-origem.{js,hbs}` + `app/.../selo-de-origem.js` | criar | Selo de origem no portal. |
| `packages/customer-portal/addon/components/portal/order/{list-card,panel-header}.{js,hbs}`, `workspace/table.hbs` | alterar | Usar o selo de origem. |
| `packages/customer-portal/addon/helpers/numero-ifood.js` + `app/helpers/numero-ifood.js` | apagar | Só a tabela usava. |
| `packages/customer-portal/addon/controllers/portal/extrato.js`, `templates/portal/extrato.hbs` | alterar | Rótulo do cancelado cobrado pela origem. |
| `packages/customer-portal/translations/{en-us,pt-br}.yaml` | alterar | `customer-portal.ui.entregas.origem-selo`, `canceled-store-charged`, `canceled-charged-column`. |
| `scripts/teste-portal/pedido-de-origem.test.mjs`, `integracao-da-loja.test.mjs` | criar | Testes das funções puras. |
| `CLAUDE.md` | alterar | Documentar o que este plano entregou. |

### Repo `entregas-navigator` (ramo `integracao-restaurantepro`)

| Arquivo | Ação | Responsabilidade |
|---|---|---|
| `src/utils/pedido-de-origem.ts` | criar | Tipos de `GET .../integracao`, `ehIntegracao`, `temCobrancaDaIntegracao`, `origemDosDados`. Sem imports. |
| `src/utils/cartao-do-pedido.ts` | alterar | `origemDasNotas(pedido)`. |
| `src/utils/ganhos.ts` | alterar | `cancelado_origem` no tipo e `chaveDoCanceladoPago`. |
| `src/hooks/use-dados-da-integracao.ts` | criar | Cópia do `use-dados-ifood.ts` para a rota `/integracao`. |
| `src/components/PedidoDaIntegracao.tsx` | criar | `CobrancaDaIntegracao`, `LigarParaOClienteDaIntegracao`, `TextosDaIntegracao`. |
| `src/components/entregas/CartaoDeOferta.tsx`, `CartaoDePedidoAberto.tsx` | alterar | `seloOrigem` no `DadosDoCartao`. |
| `src/components/AdhocOrderCard.tsx`, `src/components/OrderCard.tsx`, `src/screens/OrderScreen.tsx`, `src/screens/MeusGanhosScreen.tsx` | alterar | Usar as peças acima. |
| `android/app/src/main/java/io/fleetbase/navigator/AlarmePedidoActivity.kt` | alterar | Selo do `entregas_origem` no cartão do alarme. |
| `translations/pt.json`, `translations/en.json` | alterar | `PedidoDaIntegracao.*`, `MeusGanhosScreen.canceladoPagoLoja` e `OrderScreen.canceladoPagoSeloLoja`. |
| `scripts/testes/pedido-de-origem.teste.ts` | criar | Testes. |
| `scripts/testes/cartao-do-pedido.teste.ts`, `ganhos.teste.ts` | alterar | Testes novos. |

---

## Comandos usados em várias tarefas

Repo `Delivery`, executados na raiz da worktree (`C:\tmp\icp`, ver Task 0):

```bash
# testes das funções puras do console e do portal
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/pedido-de-origem.test.mjs scripts/teste-portal/integracao-da-loja.test.mjs scripts/teste-portal/pedido-ifood.test.mjs scripts/teste-portal/vinculo-ifood.test.mjs

# traduções (precisa sair com exit 0)
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal

# sintaxe do JS alterado (o mesmo @babel/parser do build; passe os arquivos .js alterados como argumentos)
P=$(ls -d console/node_modules/.pnpm/@babel+parser@7*/node_modules/@babel/parser | head -1)
node -e "const p=require(process.argv[1]);const fs=require('fs');for(const f of process.argv.slice(2)){p.parse(fs.readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties']});console.log('ok',f)}" "$P" <arquivos.js>
```

Repo `entregas-navigator` (raiz do repo):

```bash
node --experimental-strip-types --test scripts/testes/*.teste.ts
```

---

# Parte A: repo `Delivery` (console e portal)

### Task 0: Worktree e ramo

O checkout principal tem `console/app/router.js` modificado (o arquivo é gerado no build) e um PNG solto. Nenhum dos dois faz parte deste trabalho. Por isso o trabalho vai numa worktree.

- [ ] **Step 1: Criar a worktree no ramo novo**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/Delivery"
git rev-parse --show-toplevel   # precisa ser .../vibe coding/Delivery
git worktree add /c/tmp/icp -b integracao-console-e-app main
```

- [ ] **Step 2: Ligar o `console/node_modules` do checkout principal**

O `i18n-check.cjs` e o parser do Babel leem o `console/node_modules/.pnpm`. A worktree não tem essa pasta, então crie uma junção:

```bash
cmd //c mklink /J "C:\tmp\icp\console\node_modules" "C:\Users\Edgardjr\Documents\vibe coding\Delivery\console\node_modules"
cd /c/tmp/icp && git status --short   # precisa sair vazio (node_modules é ignorado)
```

Ao remover a worktree no fim, apague antes **só a junção**, com `cmd //c rmdir "C:\tmp\icp\console\node_modules"` (o `rmdir` sem `/s` não entra na pasta). Depois rode `git worktree remove /c/tmp/icp`.

- [ ] **Step 3: Conferir que os testes de hoje passam**

```bash
cd /c/tmp/icp
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/pedido-ifood.test.mjs scripts/teste-portal/vinculo-ifood.test.mjs
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal
```

Esperado: todos os testes `pass` e o i18n-check com exit 0. Se algo já falhar aqui, pare e avise: o problema é anterior a este plano.

---

### Task 1: Util da origem do pedido (console)

**Files:**
- Create: `packages/fleetops/addon/utils/pedido-de-origem.js`
- Test: `scripts/teste-portal/pedido-de-origem.test.mjs`

- [ ] **Step 1: Escrever o teste que falha**

`scripts/teste-portal/pedido-de-origem.test.mjs`:

```js
// Origem do pedido (iFood ou outro sistema pela integração) no console (packages/fleetops/addon/utils/pedido-de-origem.js).
// Os casos do portal entram na Task 7.
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/pedido-de-origem.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    chaveDoAviso,
    chaveDoCanceladoPago,
    chaveDoCanceladoPor,
    ehPedidoDaIntegracao,
    origemDoModelo,
    origemDoPedido,
} from '../../packages/fleetops/addon/utils/pedido-de-origem.js';
import { numeroIfood } from '../../packages/fleetops/addon/utils/pedido-ifood.js';

test('integração: notas "<Sistema> #<n>" e o internal_id igual', () => {
    assert.deepEqual(origemDoPedido('RestaurantePro #4821', '4821'), { ifood: false, sistema: 'RestaurantePro', numero: '4821' });
    assert.deepEqual(origemDoPedido('RestaurantePro #4821 [SEM LOCALIZAÇÃO]', 4821), { ifood: false, sistema: 'RestaurantePro', numero: '4821' });
    assert.deepEqual(origemDoPedido('RestaurantePro #4821\nSem cebola', '4821'), { ifood: false, sistema: 'RestaurantePro', numero: '4821' });
    assert.deepEqual(origemDoPedido('Anota AI #77', '77'), { ifood: false, sistema: 'Anota AI', numero: '77' });
    assert.deepEqual(origemDoPedido('Integração #9', '9'), { ifood: false, sistema: 'Integração', numero: '9' });
    assert.deepEqual(origemDoPedido('  Sis_tema-2.0 #A1  ', 'A1'), { ifood: false, sistema: 'Sis_tema-2.0', numero: 'A1' });
});

test('iFood: as mesmas notas do selo da etapa 4', () => {
    assert.deepEqual(origemDoPedido('iFood #4821', '4821'), { ifood: true, sistema: 'iFood', numero: '4821' });
    assert.deepEqual(origemDoPedido('iFood #4821 [TESTE]', '4821'), { ifood: true, sistema: 'iFood', numero: '4821' });
});

test('não é pedido de origem', () => {
    assert.equal(origemDoPedido('RestaurantePro #4821', '9999'), null, 'internal_id diferente');
    assert.equal(origemDoPedido('RestaurantePro #4821', null), null);
    assert.equal(origemDoPedido('RestaurantePro #4821', ''), null);
    assert.equal(origemDoPedido(null, '4821'), null);
    assert.equal(origemDoPedido('Entregar no portão', '4821'), null);
    assert.equal(origemDoPedido('Obs: #12', '12'), null, 'dois-pontos não entram no nome do sistema');
    assert.equal(origemDoPedido('IFOOD falso #12', '12'), null, 'o servidor nunca grava sistema começando com ifood');
    assert.equal(origemDoPedido(' #12', '12'), null, 'sem nome');
    assert.equal(origemDoPedido(`${'a'.repeat(41)} #12`, '12'), null, 'nome com mais de 40 caracteres');
});

test('mesma resposta do numeroIfood para o iFood', () => {
    const casos = [
        ['iFood #4821', '4821'],
        ['iFood #4821 [TESTE]', '4821'],
        ['iFood #4821', '1'],
        ['RestaurantePro #4821', '4821'],
        ['Entregar no portão', '4821'],
    ];
    for (const [notas, id] of casos) {
        const origem = origemDoPedido(notas, id);
        assert.equal(origem?.ifood ? origem.numero : null, numeroIfood(notas, id), `${notas} / ${id}`);
    }
});

test('modelo do Ember ou objeto', () => {
    assert.deepEqual(origemDoModelo({ notes: 'RestaurantePro #5', internal_id: '5' }), { ifood: false, sistema: 'RestaurantePro', numero: '5' });
    assert.equal(origemDoModelo(null), null);
    assert.equal(ehPedidoDaIntegracao({ notes: 'RestaurantePro #5', internal_id: '5' }), true);
    assert.equal(ehPedidoDaIntegracao({ notes: 'iFood #5', internal_id: '5' }), false);
    assert.equal(ehPedidoDaIntegracao({ notes: null, internal_id: null }), false);
});

test('chaves de tradução', () => {
    assert.equal(chaveDoAviso('aceito'), 'aceito');
    assert.equal(chaveDoAviso('a_caminho'), 'a_caminho');
    assert.equal(chaveDoAviso('concluido'), 'concluido');
    assert.equal(chaveDoAviso('cancelado'), 'cancelado');
    assert.equal(chaveDoAviso('outro'), 'desconhecido');
    assert.equal(chaveDoCanceladoPor('loja'), 'loja');
    assert.equal(chaveDoCanceladoPor('central'), 'central');
    assert.equal(chaveDoCanceladoPor('sistema'), 'sistema');
    assert.equal(chaveDoCanceladoPor(null), 'desconhecido');
    assert.equal(chaveDoCanceladoPago('integracao'), 'cancelado-pago-loja');
    assert.equal(chaveDoCanceladoPago('ifood'), 'cancelado-pago');
    assert.equal(chaveDoCanceladoPago(null), 'cancelado-pago', 'API antiga: o rótulo de hoje');
    assert.equal(chaveDoCanceladoPago(undefined), 'cancelado-pago');
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/pedido-de-origem.test.mjs`
Expected: FAIL com `ERR_MODULE_NOT_FOUND` (o `pedido-de-origem.js` ainda não existe).

- [ ] **Step 3: Implementar**

`packages/fleetops/addon/utils/pedido-de-origem.js`:

```js
// Entregas: de onde veio o pedido, na tela do console. Serve ao selo "iFood #4821" ou "RestaurantePro #4821" (tabela,
// quadro, mapa e cabeçalho), ao painel "Integração" do detalhe e ao rótulo do cancelado pago no Pagamento e cobrança.
// O CriadorDoPedidoIfood grava as notas "iFood #N" e o CriadorDoPedidoDaIntegracao grava "<Sistema> #N"; nos dois, o
// internal_id = N. Exigir os dois juntos evita confundir um pedido comum cujas notas a central escreveu assim. É só a
// tela: quem decide de verdade é o servidor (RegrasDoPedidoIfood, entregas_integracao_pedidos).
// A regra do iFood é a mesma do numeroIfood (utils/pedido-ifood.js), que continua decidindo o Cancelar escondido.
// Mesma regra no portal (customer-portal/addon/utils/entregas-pedido.js, origemDoPedido) e no app
// (src/utils/cartao-do-pedido.ts, origemDasNotas). Sem imports: testado no Node (scripts/teste-portal/pedido-de-origem.test.mjs).

// O nome do sistema passa pelo Integracao::nomeDoSistema do servidor: letras, números, espaço, ponto, hífen e
// sublinhado, até 40 caracteres.
const NOTAS_DE_ORIGEM = /^([\p{L}\p{N} ._-]{1,40}?) #(\S+)/u;

/** Avisos de volta ao sistema de origem, na ordem (App\Support\Entregas\Integracao\SequenciaDaIntegracao). */
export const AVISOS_DA_INTEGRACAO = ['aceito', 'a_caminho', 'concluido', 'cancelado'];

/** Quem cancelou (entregas_integracao_pedidos.cancelado_por). */
export const CANCELADO_POR = ['loja', 'central', 'sistema'];

/**
 * {ifood, sistema, numero} do pedido, ou null. O iFood volta com ifood = true. Um sistema que começa com "ifood" e não
 * é o próprio iFood volta null: o servidor nunca grava esse nome (vira "Integração").
 */
export function origemDoPedido(notas, idInterno) {
    if (typeof notas !== 'string' || idInterno === null || idInterno === undefined || idInterno === '') {
        return null;
    }
    const achado = NOTAS_DE_ORIGEM.exec(notas.trim());
    if (!achado || achado[2] !== String(idInterno)) {
        return null;
    }
    const sistema = achado[1].trim();
    if (sistema === 'iFood') {
        return { ifood: true, sistema, numero: achado[2] };
    }
    if (sistema === '' || /^ifood/i.test(sistema)) {
        return null;
    }

    return { ifood: false, sistema, numero: achado[2] };
}

/** A origem de um pedido (model do Ember ou objeto com notes e internal_id), ou null. */
export function origemDoModelo(pedido) {
    return pedido ? origemDoPedido(pedido.notes, pedido.internal_id) : null;
}

/** Pedido que veio de outro sistema pela integração (não do iFood). */
export function ehPedidoDaIntegracao(pedido) {
    const origem = origemDoModelo(pedido);
    return origem !== null && origem.ifood === false;
}

/** Chave fleet-ops.ui.integracao.aviso.<chave> do último aviso entregue ao sistema. */
export function chaveDoAviso(aviso) {
    return AVISOS_DA_INTEGRACAO.includes(aviso) ? aviso : 'desconhecido';
}

/** Chave fleet-ops.ui.integracao.cancelado-por.<chave>. */
export function chaveDoCanceladoPor(por) {
    return CANCELADO_POR.includes(por) ? por : 'desconhecido';
}

/**
 * Chave fleet-ops.ui.driver-payouts.<chave> do cancelado pago, pela origem do cancelamento (cancelado_origem do
 * CalculoEntregas). 'integracao' = a loja cancelou pelo sistema dela. 'ifood' ou ausente (API antiga) = o rótulo do iFood.
 */
export function chaveDoCanceladoPago(origem) {
    return origem === 'integracao' ? 'cancelado-pago-loja' : 'cancelado-pago';
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/pedido-de-origem.test.mjs`
Expected: PASS nos 6 testes.

- [ ] **Step 5: Commit**

```bash
git add packages/fleetops/addon/utils/pedido-de-origem.js scripts/teste-portal/pedido-de-origem.test.mjs
git commit -m "Console: origem do pedido pelas notas (iFood ou sistema da integração)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Selo de origem no console (tabela, quadro, mapa e cabeçalho)

**Files:**
- Create: `packages/fleetops/addon/components/pedido-de-origem/selo.js`, `selo.hbs`
- Create: `packages/fleetops/app/components/pedido-de-origem/selo.js`
- Create: `packages/fleetops/addon/components/cell/pedido-de-origem.hbs`, `packages/fleetops/app/components/cell/pedido-de-origem.js`
- Delete: `packages/fleetops/addon/components/pedido-ifood/selo.js`, `selo.hbs`, `packages/fleetops/app/components/pedido-ifood/selo.js`, `packages/fleetops/addon/components/cell/pedido-ifood.hbs`, `packages/fleetops/app/components/cell/pedido-ifood.js`
- Modify: `packages/fleetops/addon/components/map/order-list-overlay/order.hbs:24`, `order/kanban-card.hbs:7`, `order/panel-header.hbs:13`
- Modify: `packages/fleetops/addon/controllers/operations/orders/index.js:146-152`
- Modify: `packages/fleetops/translations/en-us.yaml`, `pt-br.yaml`

O Cancelar continua escondido **só** no pedido do iFood: `order-actions.js`, `kanban.js`, `operations/orders/index.js` (linha 418) e `index/details.js` (linha 135) seguem usando `ehPedidoIfood`. Não mexa neles. A central pode cancelar o pedido da integração.

- [ ] **Step 1: Criar o componente do selo**

`packages/fleetops/addon/components/pedido-de-origem/selo.js`:

```js
import Component from '@glimmer/component';
import { origemDoModelo } from '../../utils/pedido-de-origem';

/**
 * Entregas: selo da origem do pedido: "iFood #4821" (vermelho) ou "RestaurantePro #4821" (laranja escuro, pedido de
 * outro sistema pela integração). Aparece na lista, no quadro, no mapa e no cabeçalho do detalhe. Nos outros pedidos não
 * mostra nada. A regra (notas + internal_id) fica em utils/pedido-de-origem.js.
 */
export default class PedidoDeOrigemSeloComponent extends Component {
    get origem() {
        return origemDoModelo(this.args.order);
    }
}
```

`packages/fleetops/addon/components/pedido-de-origem/selo.hbs`:

```hbs
{{#if this.origem}}
    {{#if this.origem.ifood}}
        <span class="inline-flex items-center whitespace-nowrap rounded px-1.5 py-0.5 text-xs font-semibold text-white" style="background-color: #EA1D2C;" title={{t "fleet-ops.ui.ifood.selo-titulo"}} ...attributes>
            {{t "fleet-ops.ui.ifood.selo" numero=this.origem.numero}}
        </span>
    {{else}}
        <span class="inline-flex items-center whitespace-nowrap rounded px-1.5 py-0.5 text-xs font-semibold text-white" style="background-color: #C2410C;" title={{t "fleet-ops.ui.integracao.selo-titulo" sistema=this.origem.sistema}} ...attributes>
            {{t "fleet-ops.ui.integracao.selo" sistema=this.origem.sistema numero=this.origem.numero}}
        </span>
    {{/if}}
{{/if}}
```

`packages/fleetops/app/components/pedido-de-origem/selo.js`:

```js
export { default } from '@fleetbase/fleetops-engine/components/pedido-de-origem/selo';
```

`packages/fleetops/addon/components/cell/pedido-de-origem.hbs`:

```hbs
<PedidoDeOrigem::Selo @order={{@row}} />
```

`packages/fleetops/app/components/cell/pedido-de-origem.js`:

```js
export { default } from '@fleetbase/fleetops-engine/components/cell/pedido-de-origem';
```

- [ ] **Step 2: Trocar os usos do selo antigo**

Nos três templates abaixo, troque a linha `<PedidoIfood::Selo @order={{X}} />` por `<PedidoDeOrigem::Selo @order={{X}} />`, mantendo o mesmo `X`:

- `packages/fleetops/addon/components/map/order-list-overlay/order.hbs` (linha 24, `@order={{@order}}`);
- `packages/fleetops/addon/components/order/kanban-card.hbs` (linha 7, `@order={{@card}}`);
- `packages/fleetops/addon/components/order/panel-header.hbs` (linha 13, `@order={{@resource}}`).

Em `packages/fleetops/addon/controllers/operations/orders/index.js`, troque o bloco da coluna:

```js
            {
                // Entregas: selo "iFood #4821" do pedido que veio do iFood (vazio nos outros)
                label: this.intl.t('fleet-ops.ui.ifood.coluna'),
                valuePath: 'internal_id',
                cellComponent: 'cell/pedido-ifood',
                resizable: true,
            },
```

por:

```js
            {
                // Entregas: selo da origem ("iFood #4821" ou "RestaurantePro #4821"; vazio nos pedidos da central e do portal)
                label: this.intl.t('fleet-ops.ui.origem.coluna'),
                valuePath: 'internal_id',
                cellComponent: 'cell/pedido-de-origem',
                resizable: true,
            },
```

- [ ] **Step 3: Apagar o selo antigo e conferir que ninguém mais o usa**

```bash
git rm packages/fleetops/addon/components/pedido-ifood/selo.js packages/fleetops/addon/components/pedido-ifood/selo.hbs packages/fleetops/app/components/pedido-ifood/selo.js packages/fleetops/addon/components/cell/pedido-ifood.hbs packages/fleetops/app/components/cell/pedido-ifood.js
grep -rn "PedidoIfood::Selo\|cell/pedido-ifood\|pedido-ifood/selo" packages/fleetops/addon packages/fleetops/app
```

Expected: o `grep` não acha nada.

- [ ] **Step 4: Traduções**

Em `packages/fleetops/translations/pt-br.yaml`, sob `fleet-ops:` → `ui:`, logo **antes** da linha `    distribuicao:` (indentação de 4 espaços na chave do grupo e 6 nas filhas):

```yaml
    origem:
      coluna: Origem
    integracao:
      selo: '{sistema} #{numero}'
      selo-titulo: 'Pedido recebido de {sistema}'
```

Em `packages/fleetops/translations/en-us.yaml`, no mesmo lugar (antes de `        distribuicao:`; este arquivo usa 8 espaços na chave do grupo e 12 nas filhas):

```yaml
        origem:
            coluna: Source
        integracao:
            selo: '{sistema} #{numero}'
            selo-titulo: 'Order received from {sistema}'
```

O grupo `integracao:` ganha mais chaves na Task 3: acrescente-as dentro deste mesmo grupo, sem repetir `integracao:`.

- [ ] **Step 5: Validar**

```bash
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal
P=$(ls -d console/node_modules/.pnpm/@babel+parser@7*/node_modules/@babel/parser | head -1)
node -e "const p=require(process.argv[1]);const fs=require('fs');for(const f of process.argv.slice(2)){p.parse(fs.readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties']});console.log('ok',f)}" "$P" packages/fleetops/addon/components/pedido-de-origem/selo.js packages/fleetops/addon/controllers/operations/orders/index.js
```

Expected: o i18n-check com exit 0 e dois `ok`.

- [ ] **Step 6: Commit**

```bash
git add -A packages/fleetops/addon/components/pedido-de-origem packages/fleetops/app/components/pedido-de-origem packages/fleetops/addon/components/cell packages/fleetops/app/components/cell packages/fleetops/addon/components/pedido-ifood packages/fleetops/app/components/pedido-ifood packages/fleetops/addon/components/map/order-list-overlay/order.hbs packages/fleetops/addon/components/order/kanban-card.hbs packages/fleetops/addon/components/order/panel-header.hbs packages/fleetops/addon/controllers/operations/orders/index.js packages/fleetops/translations/en-us.yaml packages/fleetops/translations/pt-br.yaml
git commit -m "Console: selo de origem (iFood ou sistema da integração) na tabela, no quadro, no mapa e no cabeçalho

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Painel "Integração" no detalhe do pedido

**Files:**
- Create: `packages/fleetops/addon/components/order/details/integracao.js`, `integracao.hbs`
- Create: `packages/fleetops/app/components/order/details/integracao.js`
- Modify: `packages/fleetops/addon/components/order/details.hbs:53-54`
- Modify: `packages/fleetops/translations/en-us.yaml`, `pt-br.yaml` (grupo `integracao` da Task 2)

As regras de texto já estão testadas na Task 1 (`chaveDoAviso`, `chaveDoCanceladoPor`). O componente só monta a tela, no molde do `order/details/ifood`.

- [ ] **Step 1: Criar o componente**

`packages/fleetops/addon/components/order/details/integracao.js`:

```js
import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { chaveDoAviso, chaveDoCanceladoPor, ehPedidoDaIntegracao, origemDoModelo } from '../../../utils/pedido-de-origem';

/**
 * Entregas: painel "Integração" no detalhe do pedido que veio de outro sistema (RestaurantePro…). Lê
 * GET int/v1/entregas/pedidos/{id}/integracao, rota só de administradores: para os demais o painel não aparece nem
 * carrega. Mostra o sistema e o id do pedido lá, a cobrança na porta, as observações, o último aviso entregue ao sistema,
 * a última falha de aviso da loja e o cancelamento. Nunca mostra o telefone do cliente (o servidor nem manda).
 */
export default class OrderDetailsIntegracaoComponent extends Component {
    @service fetch;
    @service currentUser;
    @service intl;
    @tracked painel = null;
    @tracked erro = false;
    // o pedido do último carregamento (não rastreado: só o `recarregar` lê e grava)
    ultimoId = null;

    get mostrar() {
        return ehPedidoDaIntegracao(this.args.resource) && this.currentUser.isAdmin === true;
    }

    get id() {
        return encodeURIComponent(this.args.resource?.public_id ?? this.args.resource?.id ?? '');
    }

    get titulo() {
        const origem = origemDoModelo(this.args.resource);

        return this.intl.t('fleet-ops.ui.integracao.painel-titulo', {
            sistema: this.painel?.sistema ?? origem?.sistema ?? '',
            numero: this.painel?.numero ?? origem?.numero ?? '',
        });
    }

    get cobrancaTexto() {
        const cobranca = this.painel?.cobranca;
        if (cobranca?.texto) {
            return cobranca.texto;
        }

        return cobranca?.forma === 'pago' ? this.intl.t('fleet-ops.ui.integracao.pago') : this.intl.t('fleet-ops.ui.integracao.sem-cobranca');
    }

    get avisoTexto() {
        const aviso = this.painel?.ultimo_aviso;

        return aviso ? this.intl.t(`fleet-ops.ui.integracao.aviso.${chaveDoAviso(aviso)}`) : this.intl.t('fleet-ops.ui.integracao.nenhum-aviso');
    }

    get canceladoTexto() {
        const painel = this.painel;
        if (!painel?.cancelado_em) {
            return null;
        }
        const por = this.intl.t(`fleet-ops.ui.integracao.cancelado-por.${chaveDoCanceladoPor(painel.cancelado_por)}`);

        return this.intl.t(painel.pago_mesmo_cancelado ? 'fleet-ops.ui.integracao.cancelado-pago' : 'fleet-ops.ui.integracao.cancelado', { por });
    }

    get falhaTexto() {
        const painel = this.painel;
        if (!painel?.ultima_falha_aviso) {
            return null;
        }

        return this.intl.t('fleet-ops.ui.integracao.falha', { texto: painel.ultima_falha_aviso, quando: this.dataHora(painel.ultima_falha_aviso_em) });
    }

    dataHora(iso) {
        const data = iso ? new Date(iso) : null;
        if (!data || Number.isNaN(data.getTime())) {
            return '—';
        }

        return data.toLocaleString(this.intl.primaryLocale ?? 'pt-BR', { dateStyle: 'short', timeStyle: 'short' });
    }

    /**
     * Mesmo cuidado do painel iFood: o Glimmer reaproveita o componente quando o @resource muda. Carrega na entrada e a
     * cada mudança de id, status ou atualização do pedido. Se trocou de pedido, zera o painel antes.
     */
    @action recarregar() {
        const id = this.id;
        if (id !== this.ultimoId) {
            this.ultimoId = id;
            this.painel = null;
            this.erro = false;
        }
        if (this.mostrar) {
            this.carregar.perform();
        }
    }

    @task({ restartable: true }) *carregar() {
        try {
            this.painel = yield this.fetch.get(`entregas/pedidos/${this.id}/integracao`);
            this.erro = false;
        } catch (error) {
            this.erro = true;
        }
    }
}
```

`packages/fleetops/addon/components/order/details/integracao.hbs`:

```hbs
{{#if this.mostrar}}
    <div {{did-insert this.recarregar}} {{did-update this.recarregar @resource.id @resource.status @resource.updated_at}}>
        <ContentPanel @title={{this.titulo}} @isLoading={{or @isLoading this.carregar.isRunning}} @open={{true}} @wrapperClass="bordered-top">
            {{#if this.erro}}
                <p class="text-xs text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.integracao.erro"}}</p>
            {{else if this.painel.integracao}}
                {{#if this.canceladoTexto}}
                    <div class="mb-2 rounded px-2 py-1 text-xs font-semibold bg-red-50 text-red-700 dark:bg-red-900 dark:text-red-100">{{this.canceladoTexto}}</div>
                {{/if}}
                {{#if this.painel.sem_localizacao}}
                    <div class="mb-2 rounded px-2 py-1 text-xs font-semibold bg-yellow-50 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-100">{{t "fleet-ops.ui.integracao.sem-localizacao"}}</div>
                {{/if}}
                <div class="field-info-container field-vertical-container dashed-bottom">
                    <div class="field-name">{{t "fleet-ops.ui.integracao.pedido-externo" sistema=this.painel.sistema}}</div>
                    <div class="field-value font-mono break-all">{{this.painel.pedido_externo_id}}</div>
                </div>
                <div class="field-info-container field-vertical-container dashed-bottom">
                    <div class="field-name">{{t "fleet-ops.ui.integracao.cobranca"}}</div>
                    <div class="field-value">{{this.cobrancaTexto}}</div>
                </div>
                {{#if this.painel.observacoes}}
                    <div class="field-info-container field-vertical-container dashed-bottom">
                        <div class="field-name">{{t "fleet-ops.ui.integracao.observacoes"}}</div>
                        <div class="field-value">{{this.painel.observacoes}}</div>
                    </div>
                {{/if}}
                <div class="field-info-container field-vertical-container dashed-bottom">
                    <div class="field-name">{{t "fleet-ops.ui.integracao.ultimo-aviso" sistema=this.painel.sistema}}</div>
                    <div class="field-value">{{this.avisoTexto}}</div>
                </div>
                {{#if this.falhaTexto}}
                    <div class="field-info-container field-vertical-container dashed-bottom">
                        <div class="field-name">{{t "fleet-ops.ui.integracao.ultima-falha"}}</div>
                        <div class="field-value text-red-600 dark:text-red-400">{{this.falhaTexto}}</div>
                    </div>
                {{/if}}
            {{/if}}
        </ContentPanel>
    </div>
{{/if}}
```

`packages/fleetops/app/components/order/details/integracao.js`:

```js
export { default } from '@fleetbase/fleetops-engine/components/order/details/integracao';
```

- [ ] **Step 2: Montar o painel no detalhe**

Em `packages/fleetops/addon/components/order/details.hbs`, logo depois da linha `<Order::Details::Ifood @resource={{@resource}} @isLoading={{@isLoading}} />`, acrescente:

```hbs
        {{!-- Entregas: painel da integração (só pedidos de outro sistema; só administradores recebem os dados) --}}
        <Order::Details::Integracao @resource={{@resource}} @isLoading={{@isLoading}} />
```

- [ ] **Step 3: Traduções**

Em `pt-br.yaml`, dentro do grupo `integracao:` criado na Task 2 (filhas com 6 espaços; netas com 8):

```yaml
      painel-titulo: '{sistema} #{numero}'
      erro: Não foi possível carregar os dados da integração (só administradores veem este painel).
      pedido-externo: 'Id do pedido no {sistema}'
      cobranca: Cobrança na porta
      pago: Já pago (nada a cobrar)
      sem-cobranca: Nada a cobrar
      observacoes: Observações do cliente
      sem-localizacao: 'Sem localização: o endereço chegou sem coordenadas válidas, e a central atribui o motoboy.'
      ultimo-aviso: 'Último aviso entregue ao {sistema}'
      nenhum-aviso: Nenhum ainda
      aviso:
        aceito: Motoboy definido
        a_caminho: A caminho do cliente
        concluido: Concluído
        cancelado: Cancelado
        desconhecido: Desconhecido
      ultima-falha: Última falha de aviso da loja
      falha: '{texto} ({quando})'
      cancelado: 'Cancelado {por}'
      cancelado-pago: 'Cancelado {por} depois da coleta: o motoboy recebe e a loja é cobrada'
      cancelado-por:
        loja: pela loja
        central: pela central
        sistema: pelo sistema
        desconhecido: '(origem desconhecida)'
```

Em `en-us.yaml`, no mesmo grupo (filhas com 12 espaços; netas com 16):

```yaml
            painel-titulo: '{sistema} #{numero}'
            erro: Could not load the integration data (only administrators see this panel).
            pedido-externo: 'Order id in {sistema}'
            cobranca: Charge at the door
            pago: Already paid (nothing to charge)
            sem-cobranca: Nothing to charge
            observacoes: Customer notes
            sem-localizacao: 'No location: the address arrived without valid coordinates, and dispatch assigns the courier.'
            ultimo-aviso: 'Last update delivered to {sistema}'
            nenhum-aviso: None yet
            aviso:
                aceito: Courier assigned
                a_caminho: On the way to the customer
                concluido: Completed
                cancelado: Canceled
                desconhecido: Unknown
            ultima-falha: Store's last update failure
            falha: '{texto} ({quando})'
            cancelado: 'Canceled {por}'
            cancelado-pago: 'Canceled {por} after pickup: the courier is paid and the store is charged'
            cancelado-por:
                loja: by the store
                central: by dispatch
                sistema: by the system
                desconhecido: '(unknown source)'
```

- [ ] **Step 4: Validar**

```bash
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal
P=$(ls -d console/node_modules/.pnpm/@babel+parser@7*/node_modules/@babel/parser | head -1)
node -e "const p=require(process.argv[1]);const fs=require('fs');for(const f of process.argv.slice(2)){p.parse(fs.readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties']});console.log('ok',f)}" "$P" packages/fleetops/addon/components/order/details/integracao.js
```

Expected: exit 0 e `ok`.

- [ ] **Step 5: Commit**

```bash
git add packages/fleetops/addon/components/order/details/integracao.js packages/fleetops/addon/components/order/details/integracao.hbs packages/fleetops/app/components/order/details/integracao.js packages/fleetops/addon/components/order/details.hbs packages/fleetops/translations/en-us.yaml packages/fleetops/translations/pt-br.yaml
git commit -m "Console: painel Integração no detalhe do pedido de outro sistema (só admin)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Util do bloco "Integração" da tela Lojas

**Files:**
- Create: `packages/fleetops/addon/utils/integracao-da-loja.js`
- Test: `scripts/teste-portal/integracao-da-loja.test.mjs`

- [ ] **Step 1: Escrever o teste que falha**

`scripts/teste-portal/integracao-da-loja.test.mjs`:

```js
// Bloco "Integração" do card da loja na tela Lojas (packages/fleetops/addon/utils/integracao-da-loja.js).
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/integracao-da-loja.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { situacaoDaIntegracao, tempoDesde } from '../../packages/fleetops/addon/utils/integracao-da-loja.js';

test('situação do bloco', () => {
    assert.equal(situacaoDaIntegracao(null), 'sem-chave');
    assert.equal(situacaoDaIntegracao({ ligada: true, ativa: false }), 'sem-chave');
    assert.equal(situacaoDaIntegracao({ ligada: true, ativa: true, conectada: false }), 'aguardando');
    assert.equal(situacaoDaIntegracao({ ligada: true, ativa: true, conectada: true }), 'conectada');
    assert.equal(situacaoDaIntegracao({ ligada: false, ativa: true, conectada: true }), 'conectada', 'desligada no servidor: a situação da chave não muda');
});

test('tempo desde', () => {
    const agora = Date.parse('2026-10-12T12:00:00-03:00');
    assert.deepEqual(tempoDesde('2026-10-12T11:59:30-03:00', agora), { unidade: 'agora', n: 0 });
    assert.deepEqual(tempoDesde('2026-10-12T11:57:00-03:00', agora), { unidade: 'minutos', n: 3 });
    assert.deepEqual(tempoDesde('2026-10-12T11:00:01-03:00', agora), { unidade: 'minutos', n: 59 });
    assert.deepEqual(tempoDesde('2026-10-12T09:00:00-03:00', agora), { unidade: 'horas', n: 3 });
    assert.deepEqual(tempoDesde('2026-10-10T11:00:00-03:00', agora), { unidade: 'dias', n: 2 });
    assert.deepEqual(tempoDesde('2026-10-12T12:05:00-03:00', agora), { unidade: 'agora', n: 0 }, 'relógio do PC atrasado: nunca negativo');
    assert.equal(tempoDesde(null, agora), null);
    assert.equal(tempoDesde('', agora), null);
    assert.equal(tempoDesde('não é data', agora), null);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/integracao-da-loja.test.mjs`
Expected: FAIL com `ERR_MODULE_NOT_FOUND`.

- [ ] **Step 3: Implementar**

`packages/fleetops/addon/utils/integracao-da-loja.js`:

```js
/**
 * Entregas RestaurantePro: funções puras do bloco "Integração" do card da loja na tela Lojas (componente
 * loja/integracao). Os dados vêm de GET int/v1/entregas/lojas/{id}/integracao (PainelDaIntegracao::daLoja).
 * Sem imports: testado no Node (scripts/teste-portal/integracao-da-loja.test.mjs).
 */

/** 'sem-chave' (nenhuma chave ativa), 'aguardando' (chave gerada, o sistema ainda não chamou POST conexao) ou 'conectada'. */
export function situacaoDaIntegracao(integracao) {
    if (!integracao?.ativa) {
        return 'sem-chave';
    }

    return integracao.conectada ? 'conectada' : 'aguardando';
}

/**
 * Quanto tempo passou desde o instante ISO, para o "último uso há 3 min": {unidade: 'agora'|'minutos'|'horas'|'dias', n}.
 * Data inválida ou ausente: null. Instante no futuro (relógio do PC atrasado) conta como "agora".
 */
export function tempoDesde(iso, agora) {
    const quando = Date.parse(iso ?? '');
    if (!Number.isFinite(quando)) {
        return null;
    }
    const segundos = Math.max(0, Math.floor((Number(agora) - quando) / 1000));
    if (segundos < 60) {
        return { unidade: 'agora', n: 0 };
    }
    const minutos = Math.floor(segundos / 60);
    if (minutos < 60) {
        return { unidade: 'minutos', n: minutos };
    }
    const horas = Math.floor(minutos / 60);
    if (horas < 24) {
        return { unidade: 'horas', n: horas };
    }

    return { unidade: 'dias', n: Math.floor(horas / 24) };
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/integracao-da-loja.test.mjs`
Expected: PASS nos 2 testes.

- [ ] **Step 5: Commit**

```bash
git add packages/fleetops/addon/utils/integracao-da-loja.js scripts/teste-portal/integracao-da-loja.test.mjs
git commit -m "Console: funções puras do bloco Integração da tela Lojas

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Bloco "Integração" no card da loja e o modal da chave

**Files:**
- Create: `packages/fleetops/addon/components/loja/integracao.js`, `integracao.hbs`, `packages/fleetops/app/components/loja/integracao.js`
- Create: `packages/fleetops/addon/components/modals/chave-da-integracao.js`, `chave-da-integracao.hbs`, `packages/fleetops/app/components/modals/chave-da-integracao.js`
- Modify: `packages/fleetops/addon/templates/management/lojas.hbs` (depois do bloco do iFood, por volta da linha 207)
- Modify: `packages/fleetops/translations/en-us.yaml`, `pt-br.yaml` (grupo `lojas`)

Decisões deste bloco (para o OK do Edgard):

- O bloco é uma linha própria no card, logo abaixo da linha "iFood:", com os botões na mesma linha. Os botões do topo do card não mudam.
- Cada card lê a própria situação (`GET .../integracao`) ao aparecer. A lista de lojas (`GET int/v1/entregas/lojas`) não traz a integração, e o plano não mexe na API.
- **Gerar chave** sem chave ativa vai direto. Com chave ativa, o botão vira **Gerar outra chave** e pede confirmação (botão vermelho), avisando que a chave atual para de funcionar e que o restaurante precisa colar a nova.
- A chave aparece num modal com o copiar, uma vez só. O componente não guarda a chave depois que o modal fecha.
- **Revogar** pede confirmação e funciona mesmo com a integração desligada (a API aceita). **Gerar** só aparece com `ligada: true`. Se a API responder 409 mesmo assim (corrida com o desligamento), o `notifications.serverError` mostra "Integração desligada.".

- [ ] **Step 1: Criar o modal da chave**

`packages/fleetops/addon/components/modals/chave-da-integracao.js`:

```js
import Component from '@glimmer/component';

/**
 * Entregas RestaurantePro: modal que mostra a chave de integração recém-gerada (ent_live_…), uma vez só. O servidor
 * guarda só o hash: perdeu, gera outra. O clique copia (ClickToCopy). Fechar descarta a chave da tela.
 */
export default class ModalsChaveDaIntegracaoComponent extends Component {
    get chave() {
        return this.args.options?.chave ?? '';
    }

    get loja() {
        return this.args.options?.loja ?? {};
    }
}
```

`packages/fleetops/addon/components/modals/chave-da-integracao.hbs`:

```hbs
<Modal::Default @modalIsOpened={{@modalIsOpened}} @options={{@options}} @confirm={{@onConfirm}} @decline={{@onDecline}}>
    <div class="px-4 space-y-4 text-gray-900 dark:text-gray-50">
        <p class="text-sm font-semibold text-red-700 dark:text-red-300">{{t "fleet-ops.ui.lojas.integracao.chave-aviso"}}</p>
        <ClickToCopy @value={{this.chave}} class="block w-full rounded border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 px-3 py-3 text-center font-mono text-base break-all">
            <FaIcon @icon="copy" class="mr-2 text-gray-400" />{{this.chave}}
        </ClickToCopy>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.lojas.integracao.chave-copiar"}}</p>
        <p class="text-sm">{{t "fleet-ops.ui.lojas.integracao.chave-onde"}}</p>
    </div>
</Modal::Default>
```

`packages/fleetops/app/components/modals/chave-da-integracao.js`:

```js
export { default } from '@fleetbase/fleetops-engine/components/modals/chave-da-integracao';
```

- [ ] **Step 2: Criar o componente do bloco**

`packages/fleetops/addon/components/loja/integracao.js`:

```js
import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { next } from '@ember/runloop';
import { task } from 'ember-concurrency';
import { situacaoDaIntegracao, tempoDesde } from '../../utils/integracao-da-loja';

const ENDPOINT = 'entregas/lojas';

/**
 * Entregas RestaurantePro: bloco "Integração" do card da loja (Fleet-Ops → Lojas, só admin). Lê a situação em
 * GET int/v1/entregas/lojas/{id}/integracao (IntegracaoLojasController) e mostra:
 * - sem chave: "—" e Gerar chave (só com a integração ligada no servidor);
 * - chave gerada, sem conexão: "Chave …a1B2 gerada · aguardando o sistema conectar";
 * - conectada: "Conectada a RestaurantePro · último uso há 3 min" e a falha do último aviso, se houver.
 * Gerar chave (POST .../integracao/chave) mostra a chave uma vez, no modal modals/chave-da-integracao. Com uma chave
 * ativa, pede confirmação antes: a anterior para de funcionar. Revogar (DELETE .../integracao) também pede confirmação.
 */
export default class LojaIntegracaoComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;
    @service modalsManager;
    @tracked integracao = null;
    @tracked erro = false;

    get id() {
        return encodeURIComponent(this.args.loja?.id ?? '');
    }

    get situacao() {
        return situacaoDaIntegracao(this.integracao);
    }

    get podeGerar() {
        return this.integracao?.ligada === true;
    }

    get final() {
        return this.integracao?.chave_final ?? '';
    }

    get conectadaTexto() {
        const integracao = this.integracao;
        const sistema = integracao?.sistema ?? '—';
        const uso = this.textoDoTempo(integracao?.ultimo_uso_em);

        return uso ? this.intl.t('fleet-ops.ui.lojas.integracao.conectada-uso', { sistema, uso }) : this.intl.t('fleet-ops.ui.lojas.integracao.conectada', { sistema });
    }

    get falhaTexto() {
        const integracao = this.integracao;
        if (!integracao?.ultima_falha_aviso) {
            return null;
        }

        return this.intl.t('fleet-ops.ui.lojas.integracao.falha', {
            texto: integracao.ultima_falha_aviso,
            quando: this.textoDoTempo(integracao.ultima_falha_aviso_em) ?? '—',
        });
    }

    textoDoTempo(iso) {
        const tempo = tempoDesde(iso, Date.now());

        return tempo ? this.intl.t(`fleet-ops.ui.lojas.integracao.ha.${tempo.unidade}`, { n: tempo.n }) : null;
    }

    @action carregarAoAparecer() {
        this.carregar.perform();
    }

    @task({ restartable: true }) *carregar() {
        try {
            const resposta = yield this.fetch.get(`${ENDPOINT}/${this.id}/integracao`);
            this.integracao = resposta.integracao ?? null;
            this.erro = false;
        } catch (error) {
            this.erro = true;
        }
    }

    /** POST .../integracao/chave: devolve a chave em texto (uma vez) e atualiza o bloco; null se falhou (já avisou). */
    async pedirChave() {
        try {
            const resposta = await this.fetch.post(`${ENDPOINT}/${this.id}/integracao/chave`);
            this.integracao = resposta.integracao ?? null;

            return resposta.chave ?? null;
        } catch (error) {
            this.notifications.serverError(error);

            return null;
        }
    }

    mostrarChave(chave) {
        const loja = this.args.loja;
        this.modalsManager.show('modals/chave-da-integracao', {
            title: this.intl.t('fleet-ops.ui.lojas.integracao.chave-titulo', { name: loja?.nome ?? '' }),
            chave,
            loja,
            acceptButtonText: this.intl.t('fleet-ops.ui.lojas.integracao.ja-copiei'),
            acceptButtonIcon: 'check',
            hideDeclineButton: true,
            confirm: (modal) => modal.done(),
        });
    }

    @action async gerarChave() {
        if (this.integracao?.ativa !== true) {
            const chave = await this.pedirChave();
            if (chave) {
                this.mostrarChave(chave);
            }
            return;
        }

        // Já há chave ativa: confirma antes. A chave nova só abre depois que o modal de confirmação sai da pilha
        // (onFinish + next): o modalsManager fecha o modal do topo quando a promessa do confirm termina, e fecharia o da chave.
        let chaveNova = null;
        this.modalsManager.confirm({
            title: this.intl.t('fleet-ops.ui.lojas.integracao.regerar-titulo'),
            body: this.intl.t('fleet-ops.ui.lojas.integracao.regerar-texto', { name: this.args.loja?.nome ?? '', final: this.final }),
            acceptButtonText: this.intl.t('fleet-ops.ui.lojas.integracao.gerar-de-novo'),
            acceptButtonScheme: 'danger',
            confirm: async (modal) => {
                modal.startLoading();
                chaveNova = await this.pedirChave();
            },
            onFinish: () => {
                if (chaveNova) {
                    const chave = chaveNova;
                    chaveNova = null;
                    next(() => this.mostrarChave(chave));
                }
            },
        });
    }

    @action revogar() {
        this.modalsManager.confirm({
            title: this.intl.t('fleet-ops.ui.lojas.integracao.revogar-titulo'),
            body: this.intl.t('fleet-ops.ui.lojas.integracao.revogar-texto', { name: this.args.loja?.nome ?? '' }),
            acceptButtonText: this.intl.t('fleet-ops.ui.lojas.integracao.revogar'),
            acceptButtonScheme: 'danger',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    const resposta = await this.fetch.delete(`${ENDPOINT}/${this.id}/integracao`);
                    this.integracao = resposta.integracao ?? null;
                    this.notifications.success(this.intl.t('fleet-ops.ui.lojas.integracao.revogada'));
                } catch (error) {
                    this.notifications.serverError(error);
                }
            },
        });
    }
}
```

`packages/fleetops/addon/components/loja/integracao.hbs`:

```hbs
<div class="mt-1 flex flex-wrap items-center gap-2 text-xs" {{did-insert this.carregarAoAparecer}}>
    <span class="text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.lojas.integracao.label"}}</span>
    {{#if this.erro}}
        <span class="text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.lojas.integracao.erro"}}</span>
    {{else if this.carregar.isRunning}}
        <span class="text-gray-400">…</span>
    {{else if (eq this.situacao "conectada")}}
        <span class="font-medium rounded px-2 py-0.5 bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-100">{{this.conectadaTexto}}</span>
        <span class="text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.lojas.integracao.chave-final" final=this.final}}</span>
    {{else if (eq this.situacao "aguardando")}}
        <span class="font-medium rounded px-2 py-0.5 bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-100">{{t "fleet-ops.ui.lojas.integracao.aguardando" final=this.final}}</span>
    {{else}}
        <span class="text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.lojas.integracao.sem-chave"}}</span>
    {{/if}}
    {{#if this.falhaTexto}}
        <span class="font-medium rounded px-2 py-0.5 bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-100">{{this.falhaTexto}}</span>
    {{/if}}
    {{#unless this.erro}}
        {{#if this.podeGerar}}
            <Button @size="xs" @icon="key" @text={{if this.integracao.ativa (t "fleet-ops.ui.lojas.integracao.gerar-de-novo") (t "fleet-ops.ui.lojas.integracao.gerar")}} @onClick={{this.gerarChave}} />
        {{/if}}
        {{#if this.integracao.ativa}}
            <Button @size="xs" @icon="ban" @text={{t "fleet-ops.ui.lojas.integracao.revogar"}} @onClick={{this.revogar}} />
        {{/if}}
        {{#if (and (not this.podeGerar) (not this.integracao.ativa) this.integracao)}}
            <span class="text-gray-400">{{t "fleet-ops.ui.lojas.integracao.desligada"}}</span>
        {{/if}}
    {{/unless}}
</div>
```

`packages/fleetops/app/components/loja/integracao.js`:

```js
export { default } from '@fleetbase/fleetops-engine/components/loja/integracao';
```

- [ ] **Step 3: Montar o bloco no card**

Em `packages/fleetops/addon/templates/management/lojas.hbs`, logo depois do `</div>` que fecha o bloco do iFood (o bloco que começa com o comentário `{{! Entregas: vínculo com o iFood (IfoodLojasController)…}}` e termina depois do `{{/let}}`) e antes do `</div>` que fecha o `<div class="min-w-0">`, acrescente:

```hbs
                            {{! Entregas: integração de pedidos de outros sistemas (RestaurantePro…): chave da loja, situação e falha do último aviso }}
                            <Loja::Integracao @loja={{loja}} />
```

- [ ] **Step 4: Traduções**

Em `pt-br.yaml`, dentro de `lojas:`, logo **antes** da linha `    pedido-sem-motoboy:` (no fim do grupo `lojas`; `integracao:` com 6 espaços, filhas com 8 e netas com 10):

```yaml
      integracao:
        label: 'Integração:'
        sem-chave: '—'
        erro: não foi possível ler a integração
        aguardando: 'Chave …{final} gerada · aguardando o sistema conectar'
        conectada: 'Conectada a {sistema}'
        conectada-uso: 'Conectada a {sistema} · último uso {uso}'
        chave-final: 'chave …{final}'
        falha: 'Falha no último aviso ({quando}): {texto}'
        desligada: 'Integração desligada no servidor'
        ha:
          agora: agora há pouco
          minutos: 'há {n} min'
          horas: 'há {n} h'
          dias: 'há {n, plural, one {# dia} other {# dias}}'
        gerar: Gerar chave
        gerar-de-novo: Gerar outra chave
        revogar: Revogar
        revogada: Integração revogada.
        regerar-titulo: Gerar outra chave?
        regerar-texto: 'A chave atual de {name} (final {final}) para de funcionar agora: os pedidos do sistema deixam de entrar e os avisos param. O restaurante precisa colar a chave nova no sistema dele e conectar de novo.'
        revogar-titulo: Revogar a integração?
        revogar-texto: 'A chave de {name} para de funcionar: os pedidos do sistema deixam de entrar no Entregas e os avisos param. Os pedidos já recebidos continuam.'
        chave-titulo: 'Chave de integração de {name}'
        chave-aviso: 'Copie agora: esta chave não aparece de novo. Se perder, gere outra.'
        chave-copiar: Clique na chave para copiar.
        chave-onde: 'No RestaurantePro: Configurações de delivery → "Entregas por" → Entregas RestaurantePro → cole a chave e conecte.'
        ja-copiei: Já copiei
```

Em `en-us.yaml`, no mesmo lugar (antes de `        pedido-sem-motoboy:`; `integracao:` com 12 espaços, filhas com 16 e netas com 20):

```yaml
            integracao:
                label: 'Integration:'
                sem-chave: '—'
                erro: could not read the integration
                aguardando: 'Key …{final} created · waiting for the system to connect'
                conectada: 'Connected to {sistema}'
                conectada-uso: 'Connected to {sistema} · last used {uso}'
                chave-final: 'key …{final}'
                falha: 'Last update failed ({quando}): {texto}'
                desligada: 'Integration turned off on the server'
                ha:
                    agora: just now
                    minutos: '{n} min ago'
                    horas: '{n} h ago'
                    dias: '{n, plural, one {# day} other {# days}} ago'
                gerar: Create key
                gerar-de-novo: Create another key
                revogar: Revoke
                revogada: Integration revoked.
                regerar-titulo: Create another key?
                regerar-texto: 'The current key of {name} (ending {final}) stops working now: orders from the system stop coming in and updates stop. The restaurant must paste the new key in its system and connect again.'
                revogar-titulo: Revoke the integration?
                revogar-texto: 'The key of {name} stops working: orders from the system stop coming in and updates stop. Orders already received continue.'
                chave-titulo: 'Integration key of {name}'
                chave-aviso: 'Copy it now: this key is not shown again. If you lose it, create another.'
                chave-copiar: Click the key to copy it.
                chave-onde: 'In RestaurantePro: Delivery settings → "Delivered by" → Entregas RestaurantePro → paste the key and connect.'
                ja-copiei: I copied it
```

Antes de colar, confira com `grep -n "pedido-sem-motoboy:" packages/fleetops/translations/*.yaml` que a linha é a do fim do grupo `lojas` (no `pt-br.yaml` é a 232). Os ícones `key` e `ban` são do Font Awesome 6 free, como o `link-slash` já usado na tela.

- [ ] **Step 5: Validar**

```bash
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal
P=$(ls -d console/node_modules/.pnpm/@babel+parser@7*/node_modules/@babel/parser | head -1)
node -e "const p=require(process.argv[1]);const fs=require('fs');for(const f of process.argv.slice(2)){p.parse(fs.readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties']});console.log('ok',f)}" "$P" packages/fleetops/addon/components/loja/integracao.js packages/fleetops/addon/components/modals/chave-da-integracao.js
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/integracao-da-loja.test.mjs
```

Expected: exit 0, dois `ok` e o teste passando.

- [ ] **Step 6: Commit**

```bash
git add packages/fleetops/addon/components/loja packages/fleetops/app/components/loja packages/fleetops/addon/components/modals/chave-da-integracao.js packages/fleetops/addon/components/modals/chave-da-integracao.hbs packages/fleetops/app/components/modals/chave-da-integracao.js packages/fleetops/addon/templates/management/lojas.hbs packages/fleetops/translations/en-us.yaml packages/fleetops/translations/pt-br.yaml
git commit -m "Console: bloco Integração na tela Lojas (gerar chave uma vez, revogar, situação e falha do aviso)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Pagamento e cobrança com o rótulo da origem

**Files:**
- Modify: `packages/fleetops/addon/controllers/management/driver-payouts.js` (import, ação nova e CSV nas linhas 227 e 242)
- Modify: `packages/fleetops/addon/templates/management/driver-payouts.hbs:209-211`
- Modify: `packages/fleetops/translations/en-us.yaml`, `pt-br.yaml` (grupo `driver-payouts`)

A regra (`chaveDoCanceladoPago`) já foi testada na Task 1.

- [ ] **Step 1: Ação no controller**

Em `packages/fleetops/addon/controllers/management/driver-payouts.js`, acrescente depois do último `import`:

```js
import { chaveDoCanceladoPago } from '../../utils/pedido-de-origem';
```

Acrescente na classe, junto das outras `@action` (por exemplo, logo antes de `@action formatar(tipo, valor) {`):

```js
    /** Entregas: "Cancelado pelo iFood (pago)" ou "Cancelado pela loja (pago)" (pedido de outro sistema), pelo cancelado_origem. */
    @action rotuloCanceladoPago(origem) {
        return this.intl.t(`fleet-ops.ui.driver-payouts.${chaveDoCanceladoPago(origem)}`);
    }
```

No CSV, troque o último cabeçalho da linha 227, `t('cancelado-pago')`, por `t('cancelado-pago-coluna')`. Depois troque a linha 242:

```js
                    celula(entrega.cancelado_pago ? t('cancelado-pago') : ''),
```

por:

```js
                    celula(entrega.cancelado_pago ? this.rotuloCanceladoPago(entrega.cancelado_origem) : ''),
```

- [ ] **Step 2: Template**

Em `packages/fleetops/addon/templates/management/driver-payouts.hbs`, troque:

```hbs
                                        <Badge @status="warning" @hideStatusDot={{true}}>{{t "fleet-ops.ui.driver-payouts.cancelado-pago"}}</Badge>
```

por:

```hbs
                                        <Badge @status="warning" @hideStatusDot={{true}}>{{this.rotuloCanceladoPago entrega.cancelado_origem}}</Badge>
```

- [ ] **Step 3: Traduções**

Em `pt-br.yaml`, logo depois da linha `      cancelado-pago: Cancelado pelo iFood (pago)` (linha 139, grupo `driver-payouts`):

```yaml
      cancelado-pago-loja: Cancelado pela loja (pago)
      cancelado-pago-coluna: Cancelado (pago)
```

Em `en-us.yaml`, logo depois de `            cancelado-pago: Canceled by iFood (paid)`:

```yaml
            cancelado-pago-loja: Canceled by the store (paid)
            cancelado-pago-coluna: Canceled (paid)
```

- [ ] **Step 4: Validar**

```bash
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal
P=$(ls -d console/node_modules/.pnpm/@babel+parser@7*/node_modules/@babel/parser | head -1)
node -e "const p=require(process.argv[1]);const fs=require('fs');for(const f of process.argv.slice(2)){p.parse(fs.readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties']});console.log('ok',f)}" "$P" packages/fleetops/addon/controllers/management/driver-payouts.js
grep -n "t('cancelado-pago')" packages/fleetops/addon/controllers/management/driver-payouts.js
```

Expected: exit 0, `ok`, e o `grep` sem resultado.

- [ ] **Step 5: Commit**

```bash
git add packages/fleetops/addon/controllers/management/driver-payouts.js packages/fleetops/addon/templates/management/driver-payouts.hbs packages/fleetops/translations/en-us.yaml packages/fleetops/translations/pt-br.yaml
git commit -m "Pagamento e cobrança: rótulo do cancelado pago pela origem (iFood ou loja)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Util da origem no portal

**Files:**
- Modify: `packages/customer-portal/addon/utils/entregas-pedido.js` (depois de `numeroIfood`, linha 62)
- Test: `scripts/teste-portal/pedido-de-origem.test.mjs` (acrescentar no fim)

- [ ] **Step 1: Acrescentar os testes que falham**

No fim de `scripts/teste-portal/pedido-de-origem.test.mjs`:

```js
// Portal da loja: a mesma regra, cópia própria (o engine do portal não importa do fleetops)
import { chaveDoCanceladoCobrado, origemDoPedido as origemNoPortal } from '../../packages/customer-portal/addon/utils/entregas-pedido.js';

test('portal: mesma origem do console', () => {
    const casos = [
        ['RestaurantePro #4821', '4821'],
        ['RestaurantePro #4821 [SEM LOCALIZAÇÃO]', 4821],
        ['iFood #4821 [TESTE]', '4821'],
        ['Anota AI #77', '77'],
        ['IFOOD falso #12', '12'],
        ['Entregar no portão', '4821'],
        ['RestaurantePro #4821', '1'],
        [null, '1'],
        ['RestaurantePro #4821', null],
    ];
    for (const [notas, id] of casos) {
        assert.deepEqual(origemNoPortal(notas, id), origemDoPedido(notas, id), `${notas} / ${id}`);
    }
});

test('portal: rótulo do cancelado cobrado', () => {
    assert.equal(chaveDoCanceladoCobrado('integracao'), 'canceled-store-charged');
    assert.equal(chaveDoCanceladoCobrado('ifood'), 'canceled-ifood-charged');
    assert.equal(chaveDoCanceladoCobrado(null), 'canceled-ifood-charged', 'API antiga: o rótulo de hoje');
});
```

Os `import` no meio do arquivo são içados pelo ESM: funciona. Se o lint do editor reclamar, mova a linha para o topo, junto dos outros.

- [ ] **Step 2: Rodar e ver falhar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/pedido-de-origem.test.mjs`
Expected: FAIL com `SyntaxError` ("does not provide an export named 'chaveDoCanceladoCobrado'").

- [ ] **Step 3: Implementar**

Em `packages/customer-portal/addon/utils/entregas-pedido.js`, logo depois da função `numeroIfood` (que fecha na linha 62):

```js
// Mesma regra do console (packages/fleetops/addon/utils/pedido-de-origem.js, origemDoPedido): o nome do sistema
// passa pelo Integracao::nomeDoSistema do servidor (letras, números, espaço, ponto, hífen e sublinhado, até 40).
const NOTAS_DE_ORIGEM = /^([\p{L}\p{N} ._-]{1,40}?) #(\S+)/u;

/**
 * Origem do pedido para o selo do portal: {ifood: true, sistema: 'iFood', numero} (notas "iFood #N"),
 * {ifood: false, sistema, numero} (pedido que a loja mandou pelo sistema dela, notas "<Sistema> #N") ou null. Nos dois
 * casos o internal_id precisa ser igual a N. O Cancelar não depende disto: no pedido da integração ele segue a regra do
 * portal (antes do aceite); no do iFood, o numeroIfood esconde.
 */
export function origemDoPedido(notas, idInterno) {
    if (typeof notas !== 'string' || idInterno === null || idInterno === undefined || idInterno === '') {
        return null;
    }
    const achado = NOTAS_DE_ORIGEM.exec(notas.trim());
    if (!achado || achado[2] !== String(idInterno)) {
        return null;
    }
    const sistema = achado[1].trim();
    if (sistema === 'iFood') {
        return { ifood: true, sistema, numero: achado[2] };
    }
    if (sistema === '' || /^ifood/i.test(sistema)) {
        return null;
    }

    return { ifood: false, sistema, numero: achado[2] };
}

/**
 * Chave customer-portal.ui.entregas.<chave> do cancelado cobrado no extrato, pelo cancelado_origem do servidor:
 * 'integracao' = a própria loja cancelou pelo sistema dela depois da coleta; 'ifood' ou ausente (API antiga) = o iFood.
 */
export function chaveDoCanceladoCobrado(origem) {
    return origem === 'integracao' ? 'canceled-store-charged' : 'canceled-ifood-charged';
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/pedido-de-origem.test.mjs scripts/teste-portal/pedido-ifood.test.mjs`
Expected: PASS em todos.

- [ ] **Step 5: Commit**

```bash
git add packages/customer-portal/addon/utils/entregas-pedido.js scripts/teste-portal/pedido-de-origem.test.mjs
git commit -m "Portal: origem do pedido pelas notas e rótulo do cancelado cobrado

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Selo de origem no portal (lista, tabela e cabeçalho)

**Files:**
- Create: `packages/customer-portal/addon/components/portal/order/selo-de-origem.js`, `selo-de-origem.hbs`, `packages/customer-portal/app/components/portal/order/selo-de-origem.js`
- Modify: `packages/customer-portal/addon/components/portal/order/list-card.js:2,20-23`, `list-card.hbs:6-8`
- Modify: `packages/customer-portal/addon/components/portal/order/panel-header.js:4,15-18`, `panel-header.hbs:19-21`
- Modify: `packages/customer-portal/addon/components/portal/order/workspace/table.hbs:21-26`
- Delete: `packages/customer-portal/addon/helpers/numero-ifood.js`, `packages/customer-portal/app/helpers/numero-ifood.js`
- Modify: `packages/customer-portal/translations/en-us.yaml`, `pt-br.yaml`

Não mexa em `portal/order/details.js`: o Cancelar continua escondido só no iFood (`numeroIfood`). No pedido da integração, ele segue a regra do portal (antes do aceite).

- [ ] **Step 1: Criar o componente**

`packages/customer-portal/addon/components/portal/order/selo-de-origem.js`:

```js
import Component from '@glimmer/component';
import { origemDoPedido } from '../../../utils/entregas-pedido';

/**
 * Entregas: selo da origem do pedido no portal da loja: "iFood #4821" (vermelho) ou "RestaurantePro #4821" (laranja
 * escuro, pedido que a loja mandou pelo sistema dela). Recebe @notas e @idInterno. Nos outros pedidos, nada.
 */
export default class PortalOrderSeloDeOrigemComponent extends Component {
    get origem() {
        return origemDoPedido(this.args.notas, this.args.idInterno);
    }
}
```

`packages/customer-portal/addon/components/portal/order/selo-de-origem.hbs` (estilo em linha, como o selo do iFood de hoje, porque as regras do portal não o sobrescrevem):

```hbs
{{#if this.origem}}
    {{#if this.origem.ifood}}
        <span class="inline-flex items-center whitespace-nowrap rounded px-1.5 py-0.5 text-xs font-bold text-white" style="background-color: #EA1D2C;" ...attributes>{{t "customer-portal.ui.entregas.ifood-selo" numero=this.origem.numero}}</span>
    {{else}}
        <span class="inline-flex items-center whitespace-nowrap rounded px-1.5 py-0.5 text-xs font-bold text-white" style="background-color: #C2410C;" ...attributes>{{t "customer-portal.ui.entregas.origem-selo" sistema=this.origem.sistema numero=this.origem.numero}}</span>
    {{/if}}
{{/if}}
```

`packages/customer-portal/app/components/portal/order/selo-de-origem.js`:

```js
export { default } from '@fleetbase/customer-portal-engine/components/portal/order/selo-de-origem';
```

- [ ] **Step 2: Lista (card)**

Em `list-card.hbs`, troque:

```hbs
                {{#if this.numeroIfood}}
                    <span class="inline-flex self-start items-center whitespace-nowrap rounded px-1.5 py-0.5 text-xs font-bold text-white" style="background-color: #EA1D2C;">{{t "customer-portal.ui.entregas.ifood-selo" numero=this.numeroIfood}}</span>
                {{/if}}
```

por:

```hbs
                <Portal::Order::SeloDeOrigem @notas={{this.order.notes}} @idInterno={{this.order.internal_id}} class="self-start" />
```

Em `list-card.js`, apague a linha `import { numeroIfood } from '../../../utils/entregas-pedido';` e o bloco:

```js
    // Entregas: selo do pedido que veio do iFood
    get numeroIfood() {
        return numeroIfood(this.order.notes, this.order.internal_id);
    }
```

- [ ] **Step 3: Cabeçalho do detalhe**

Em `panel-header.hbs`, troque:

```hbs
                    {{#if this.numeroIfood}}
                        <span class="inline-flex items-center whitespace-nowrap rounded px-1.5 py-0.5 text-xs font-bold text-white" style="background-color: #EA1D2C;">{{t "customer-portal.ui.entregas.ifood-selo" numero=this.numeroIfood}}</span>
                    {{/if}}
```

por:

```hbs
                    <Portal::Order::SeloDeOrigem @notas={{this.resource.notes}} @idInterno={{this.resource.internal_id}} />
```

Em `panel-header.js`, apague a linha `import { numeroIfood } from '../../../utils/entregas-pedido';` e o bloco:

```js
    // Entregas: selo do pedido que veio do iFood
    get numeroIfood() {
        return numeroIfood(this.resource.notes, this.resource.internal_id);
    }
```

- [ ] **Step 4: Tabela**

Em `workspace/table.hbs`, troque:

```hbs
                            {{!-- Entregas: selo do pedido do iFood (mesma regra das listas e do detalhe) --}}
                            {{#let (numero-ifood order.notes order.internal_id) as |numeroIfood|}}
                                {{#if numeroIfood}}
                                    <span class="inline-flex items-center whitespace-nowrap rounded px-1.5 py-0.5 text-xs font-bold text-white" style="background-color: #EA1D2C;">{{t "customer-portal.ui.entregas.ifood-selo" numero=numeroIfood}}</span>
                                {{/if}}
                            {{/let}}
```

por:

```hbs
                            {{!-- Entregas: selo da origem (iFood ou o sistema da loja; mesma regra das listas e do detalhe) --}}
                            <Portal::Order::SeloDeOrigem @notas={{order.notes}} @idInterno={{order.internal_id}} />
```

- [ ] **Step 5: Apagar o helper e conferir**

```bash
git rm packages/customer-portal/addon/helpers/numero-ifood.js packages/customer-portal/app/helpers/numero-ifood.js
grep -rn "numero-ifood\|this.numeroIfood" packages/customer-portal/addon packages/customer-portal/app
```

Expected: o único resultado é o de `portal/order/details.hbs` e `details.js` (o Cancelar do iFood), que continuam.

- [ ] **Step 6: Traduções**

Em `packages/customer-portal/translations/pt-br.yaml`, logo depois de `      ifood-selo: "iFood #{numero}"`:

```yaml
      origem-selo: "{sistema} #{numero}"
```

Em `en-us.yaml`, no mesmo lugar:

```yaml
      origem-selo: "{sistema} #{numero}"
```

- [ ] **Step 7: Validar**

```bash
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal
P=$(ls -d console/node_modules/.pnpm/@babel+parser@7*/node_modules/@babel/parser | head -1)
node -e "const p=require(process.argv[1]);const fs=require('fs');for(const f of process.argv.slice(2)){p.parse(fs.readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties']});console.log('ok',f)}" "$P" packages/customer-portal/addon/components/portal/order/selo-de-origem.js packages/customer-portal/addon/components/portal/order/list-card.js packages/customer-portal/addon/components/portal/order/panel-header.js
```

Expected: exit 0 e três `ok`.

- [ ] **Step 8: Commit**

```bash
git add -A packages/customer-portal/addon/components/portal/order packages/customer-portal/app/components/portal/order packages/customer-portal/addon/helpers packages/customer-portal/app/helpers packages/customer-portal/translations
git commit -m "Portal: selo de origem (iFood ou o sistema da loja) na lista, na tabela e no cabeçalho

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Extrato do portal com o rótulo da origem

**Files:**
- Modify: `packages/customer-portal/addon/controllers/portal/extrato.js` (imports, CSV nas linhas 283 e 293)
- Modify: `packages/customer-portal/addon/templates/portal/extrato.hbs:99`
- Modify: `packages/customer-portal/translations/en-us.yaml`, `pt-br.yaml`

- [ ] **Step 1: Controller**

Em `extrato.js`, depois da linha `import dateFnsLocaleOptions from '@fleetbase/ember-core/utils/date-fns-locale';`:

```js
import { chaveDoCanceladoCobrado } from '../../utils/entregas-pedido';
```

Na classe, logo antes de `@action baixarCsv() {`:

```js
    /** Entregas: "Cancelado pelo iFood (cobrado)" ou "Cancelado pela loja (cobrado)" (pedido do sistema da loja), pelo cancelado_origem. */
    @action rotuloDoCancelado(entrega) {
        return this.intl.t(`customer-portal.ui.entregas.${chaveDoCanceladoCobrado(entrega?.cancelado_origem)}`);
    }
```

No `baixarCsv`, troque o último item do `cabecalho`, `this.intl.t('customer-portal.ui.entregas.canceled-ifood-charged'),`, por:

```js
            this.intl.t('customer-portal.ui.entregas.canceled-charged-column'),
```

E troque as duas linhas:

```js
            // pedido do iFood cancelado pelo iFood depois da coleta, cobrado mesmo assim: vazio nas demais
            entrega.cancelado_pago ? this.intl.t('customer-portal.ui.entregas.canceled-ifood-charged') : '',
```

por:

```js
            // cancelado depois da coleta e cobrado mesmo assim (pelo iFood ou pela loja no sistema dela): vazio nas demais
            entrega.cancelado_pago ? this.rotuloDoCancelado(entrega) : '',
```

- [ ] **Step 2: Template**

Em `extrato.hbs`, troque `{{t "customer-portal.ui.entregas.canceled-ifood-charged"}}` (linha 99) por `{{this.rotuloDoCancelado entrega}}`. Ajuste o comentário da linha 98 para:

```hbs
                                        {{! Entregas: estilo em linha, como o selo de origem nas listas: as regras do portal não o sobrescrevem }}
```

- [ ] **Step 3: Traduções**

Em `pt-br.yaml`, logo depois de `      canceled-ifood-charged: "Cancelado pelo iFood (cobrado)"`:

```yaml
      canceled-store-charged: "Cancelado pela loja (cobrado)"
      canceled-charged-column: "Cancelado (cobrado)"
```

Em `en-us.yaml`, logo depois de `      canceled-ifood-charged: "Canceled by iFood (charged)"`:

```yaml
      canceled-store-charged: "Canceled by the store (charged)"
      canceled-charged-column: "Canceled (charged)"
```

- [ ] **Step 4: Validar**

```bash
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal
P=$(ls -d console/node_modules/.pnpm/@babel+parser@7*/node_modules/@babel/parser | head -1)
node -e "const p=require(process.argv[1]);const fs=require('fs');for(const f of process.argv.slice(2)){p.parse(fs.readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties']});console.log('ok',f)}" "$P" packages/customer-portal/addon/controllers/portal/extrato.js
grep -rn "canceled-ifood-charged" packages/customer-portal/addon
```

Expected: exit 0, `ok`, e o `grep` sem resultado (a chave só aparece na util e nas traduções).

- [ ] **Step 5: Commit**

```bash
git add packages/customer-portal/addon/controllers/portal/extrato.js packages/customer-portal/addon/templates/portal/extrato.hbs packages/customer-portal/translations/en-us.yaml packages/customer-portal/translations/pt-br.yaml
git commit -m "Portal: extrato com o rótulo do cancelado cobrado pela origem (iFood ou loja)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Verificação final do console e do portal

- [ ] **Step 1: Todos os testes de front**

```bash
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
```

Expected: PASS em todos (os de antes e os novos).

- [ ] **Step 2: Traduções**

```bash
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal
```

Expected: exit 0.

- [ ] **Step 3: Sintaxe de todo JS alterado no ramo**

```bash
P=$(ls -d console/node_modules/.pnpm/@babel+parser@7*/node_modules/@babel/parser | head -1)
ARQS=$(git diff --name-only --diff-filter=AM main...HEAD -- 'packages/*.js')
node -e "const p=require(process.argv[1]);const fs=require('fs');for(const f of process.argv.slice(2)){p.parse(fs.readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties']});console.log('ok',f)}" "$P" $ARQS
```

Expected: um `ok` por arquivo, sem erro.

- [ ] **Step 4 (opcional, ~10 min): Build do console**

```bash
cd console && DISABLE_RUNTIME_CONFIG=false pnpm build --environment production
```

Expected: build sem erro. Para olhar as telas contra a produção, siga "Build do console" no `CLAUDE.md`: grave o `dist/fleetbase.config.json` com o `API_HOST` de produção, rode `npx serve -s dist -l 4200` e entre nessa aba. Confira:
- tela Lojas: o bloco "Integração" em cada card;
- com `ENTREGAS_INTEGRACAO` ainda vazia, só "—" e a nota "Integração desligada no servidor", sem botão Gerar;
- detalhe de um pedido iFood: o selo continua vermelho e o painel iFood não mudou.

**Não gere chave em produção sem o OK do Edgard.** O console de produção só muda depois do `bash deploy/atualizar.sh console`.

---

### Task 11: Documentação no `CLAUDE.md`

**Files:**
- Modify: `CLAUDE.md` (seção "Integração de pedidos de outros sistemas (RestaurantePro)" e "Histórico")

- [ ] **Step 1: Trocar o aviso dos rótulos**

Na seção "Integração de pedidos de outros sistemas (RestaurantePro)", troque o item que começa com `- **\`cancelado_origem\` (\`ifood\` | \`integracao\`):**` inteiro por:

```markdown
- **`cancelado_origem` (`ifood` | `integracao`):** a linha das entregas do `CalculoEntregas` leva a origem do cancelamento pago, repassada no Pagamento e cobrança, no extrato do portal e nos ganhos do app. Com `integracao`, o rótulo é "Cancelado pela loja (pago)" no console, "Cancelado pela loja (cobrado)" no portal e "Cancelado pela loja: você recebe" no app; sem o campo (API antiga), o rótulo do iFood (plano 2, `-console-e-app.md`).
```

- [ ] **Step 2: Acrescentar o item das telas**

Logo depois desse item, acrescente:

```markdown
- **Telas** (plano 2; ramos `integracao-console-e-app` aqui e `integracao-restaurantepro` no `entregas-navigator`):
  - **Origem pelas notas** `"<Sistema> #N"` + `internal_id` N, como o iFood (`fleetops/addon/utils/pedido-de-origem.js`; no portal, `origemDoPedido` em `customer-portal/addon/utils/entregas-pedido.js`; no app, `origemDasNotas` em `src/utils/cartao-do-pedido.ts`). Selo laranja-escuro (`#C2410C`) "RestaurantePro #4821" na tabela (coluna "Origem"), no quadro, no mapa e no cabeçalho do console, e na lista, na tabela e no cabeçalho do portal. O Cancelar continua escondido só no iFood: a central e a loja (antes do aceite) cancelam o pedido da integração.
  - **Painel "Integração"** no detalhe do pedido (`order/details/integracao`, só admin): sistema, id externo, cobrança, observações, último aviso entregue, falha de aviso da loja e o cancelamento.
  - **Tela Lojas:** bloco "Integração" no card (`components/loja/integracao`, lê a situação por card): "—", "Chave …a1B2 gerada · aguardando o sistema conectar" ou "Conectada a <sistema> · último uso há X", a falha do último aviso, **Gerar chave** (só com `ENTREGAS_INTEGRACAO`; com uma chave ativa, confirmação antes) e **Revogar**. A chave aparece uma vez no modal `modals/chave-da-integracao`.
  - **App:** `useDadosDaIntegracao` (cópia do `useDadosIfood` para `GET .../integracao`, só quando as notas não são do iFood), `PedidoDaIntegracao.tsx` (faixa amarela da cobrança ou "Já pago", "Ligar para o cliente" com o telefone do pedido em andamento, observações e complemento), selo laranja no card de aceitar, na lista e na tela do pedido, e o selo do `entregas_origem` no cartão do alarme (Kotlin).
  - Testes: `scripts/teste-portal/pedido-de-origem.test.mjs` e `integracao-da-loja.test.mjs`; no app, `scripts/testes/pedido-de-origem.teste.ts` e os casos novos de `cartao-do-pedido.teste.ts` e `ganhos.teste.ts`.
```

- [ ] **Step 3: Histórico**

No fim da lista "Histórico", depois do item 30, acrescente:

```markdown
31. Integração de pedidos de outros sistemas, telas (2026-10-12, ramos `integracao-console-e-app` aqui e `integracao-restaurantepro` no `entregas-navigator`): selo de origem no console, no portal e no app, painel "Integração" no detalhe, chave da loja na tela Lojas, rótulos do cancelado pago pela origem, cobrança e telefone do cliente no app e o selo no cartão do alarme.
```

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md
git commit -m "CLAUDE.md: telas da integração RestaurantePro (console, portal e APK)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

# Parte B: repo `entregas-navigator` (APK do motoboy)

Trabalhe em `C:\Users\Edgardjr\Documents\vibe coding\entregas-navigator`. Ramo próprio. **Não faça push na `main` sem o OK do Edgard.** Cada build da `main` vira a versão mínima obrigatória (trava "Atualize o app" ligada), então um APK quebrado trava todos os motoboys.

### Task 12: Ramo do app

- [ ] **Step 1: Criar o ramo e conferir a base**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator"
git status --short          # precisa sair vazio; se não, pare e pergunte
git checkout main && git pull --ff-only
git checkout -b integracao-restaurantepro
node --experimental-strip-types --test scripts/testes/*.teste.ts
```

Expected: todos os testes `pass`.

---

### Task 13: Util dos dados da integração no app

**Files:**
- Create: `src/utils/pedido-de-origem.ts`
- Test: `scripts/testes/pedido-de-origem.teste.ts`

- [ ] **Step 1: Escrever o teste que falha**

`scripts/testes/pedido-de-origem.teste.ts`:

```ts
// Testes das funções puras do pedido de outro sistema (src/utils/pedido-de-origem.ts), com o Node puro:
//   node --experimental-strip-types --test scripts/testes/pedido-de-origem.teste.ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ehIntegracao, origemDosDados, temCobrancaDaIntegracao, textoDaCobrancaDaIntegracao } from '../../src/utils/pedido-de-origem.ts';
import type { DadosDaIntegracao } from '../../src/utils/pedido-de-origem.ts';

const pedido = (extra: Record<string, unknown> = {}): DadosDaIntegracao =>
    ({
        integracao: true,
        sistema: 'RestaurantePro',
        numero: '4821',
        cobranca: { forma: 'dinheiro', centavos: 5890, troco_para_centavos: 10000, texto: 'Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100' },
        observacoes: 'Sem cebola',
        complemento: 'Ap 12',
        referencia: null,
        cancelado: false,
        pago_mesmo_cancelado: false,
        telefone: '16999990000',
        ...extra,
    }) as DadosDaIntegracao;

test('é pedido da integração', () => {
    assert.equal(ehIntegracao(pedido()), true);
    assert.equal(ehIntegracao({ integracao: false }), false);
    assert.equal(ehIntegracao(null), false);
    assert.equal(ehIntegracao(undefined), false);
});

test('cobrança na porta', () => {
    assert.equal(temCobrancaDaIntegracao(pedido()), true);
    assert.equal(textoDaCobrancaDaIntegracao(pedido()), 'Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100');
    const pago = pedido({ cobranca: { forma: 'pago', centavos: 0, troco_para_centavos: null, texto: null } });
    assert.equal(temCobrancaDaIntegracao(pago), false);
    assert.equal(textoDaCobrancaDaIntegracao(pago), null);
    assert.equal(textoDaCobrancaDaIntegracao({ integracao: false }), null);
    assert.equal(textoDaCobrancaDaIntegracao(null), null);
});

test('origem para o selo', () => {
    assert.deepEqual(origemDosDados(pedido()), { sistema: 'RestaurantePro', numero: '4821' });
    assert.deepEqual(origemDosDados(pedido({ sistema: '  ' })), { sistema: 'Integração', numero: '4821' });
    assert.deepEqual(origemDosDados(pedido({ sistema: null, numero: null })), { sistema: 'Integração', numero: null });
    assert.equal(origemDosDados({ integracao: false }), null);
    assert.equal(origemDosDados(null), null);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `node --experimental-strip-types --test scripts/testes/pedido-de-origem.teste.ts`
Expected: FAIL com `ERR_MODULE_NOT_FOUND`.

- [ ] **Step 3: Implementar**

`src/utils/pedido-de-origem.ts`:

```ts
// Entregas: pedido que veio de outro sistema pela integração (RestaurantePro…) no app do motoboy (card de aceitar,
// tela do pedido). Dados da rota v1/entregas/motoboy/pedidos/{id}/integracao
// (App\Support\Entregas\Integracao\DadosDaIntegracaoDoMotoboy). Sem código de entrega: a conclusão é a de sempre.
// Sem imports, para os testes rodarem com o Node puro: node --experimental-strip-types --test scripts/testes/pedido-de-origem.teste.ts

/** forma: "pago", "dinheiro", "cartão (maquininha)", "Pix" ou null (CobrancaDaIntegracao::forma). texto: null quando não há nada a cobrar. */
export type CobrancaDaIntegracao = { forma: string | null; centavos: number; troco_para_centavos: number | null; texto: string | null };

export type PedidoDaIntegracao = {
    integracao: true;
    sistema: string | null;
    numero: string | null;
    cobranca: CobrancaDaIntegracao;
    observacoes: string | null;
    complemento: string | null;
    referencia: string | null;
    cancelado: boolean;
    pago_mesmo_cancelado: boolean;
    /** Só para o motoboy do pedido, com o pedido em andamento (a regra do 0800 do iFood). */
    telefone: string | null;
};

export type DadosDaIntegracao = { integracao: false } | PedidoDaIntegracao;

/** O nome que o servidor usa quando o sistema não mandou um nome válido (Integracao::SISTEMA_PADRAO). */
const SISTEMA_PADRAO = 'Integração';

/** É pedido de outro sistema (a rota responde {"integracao": false} para os outros). */
export const ehIntegracao = (dados: DadosDaIntegracao | null | undefined): dados is PedidoDaIntegracao => !!dados && dados.integracao === true;

/** Mostra a faixa amarela da cobrança na porta. */
export const temCobrancaDaIntegracao = (dados: DadosDaIntegracao | null | undefined): boolean =>
    ehIntegracao(dados) && !!dados.cobranca?.texto && dados.cobranca.centavos > 0;

/** O texto da faixa amarela ("Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100"), ou null. */
export const textoDaCobrancaDaIntegracao = (dados: DadosDaIntegracao | null | undefined): string | null =>
    temCobrancaDaIntegracao(dados) && ehIntegracao(dados) ? dados.cobranca.texto : null;

/** {sistema, numero} para o selo "RestaurantePro #4821", ou null. */
export const origemDosDados = (dados: DadosDaIntegracao | null | undefined): { sistema: string; numero: string | null } | null =>
    ehIntegracao(dados) ? { sistema: (dados.sistema ?? '').trim() || SISTEMA_PADRAO, numero: dados.numero ?? null } : null;
```

- [ ] **Step 4: Rodar e ver passar**

Run: `node --experimental-strip-types --test scripts/testes/pedido-de-origem.teste.ts`
Expected: PASS nos 3 testes.

- [ ] **Step 5: Commit**

```bash
git add src/utils/pedido-de-origem.ts scripts/testes/pedido-de-origem.teste.ts
git commit -m "Integração: tipos e funções puras do pedido de outro sistema

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 14: Origem pelas notas no app

**Files:**
- Modify: `src/utils/cartao-do-pedido.ts` (depois de `numeroIfoodDasNotas`, linha 50)
- Test: `scripts/testes/cartao-do-pedido.teste.ts`

- [ ] **Step 1: Acrescentar o teste que falha**

Em `scripts/testes/cartao-do-pedido.teste.ts`, troque a linha do import:

```ts
import { destinoDoPedido, numeroIfoodDasNotas, situacaoDoAndamento, totalDoAnel } from '../../src/utils/cartao-do-pedido.ts';
```

por:

```ts
import { destinoDoPedido, numeroIfoodDasNotas, origemDasNotas, situacaoDoAndamento, totalDoAnel } from '../../src/utils/cartao-do-pedido.ts';
```

E acrescente no fim do arquivo:

```ts
test('origem pelas notas: pedido de outro sistema', () => {
    assert.deepEqual(origemDasNotas(recurso({ notes: 'RestaurantePro #4821', internal_id: '4821' })), { sistema: 'RestaurantePro', numero: '4821' });
    assert.deepEqual(origemDasNotas(recurso({ notes: 'RestaurantePro #4821 [SEM LOCALIZAÇÃO]\nSem cebola', internal_id: '4821' })), { sistema: 'RestaurantePro', numero: '4821' });
    assert.deepEqual(origemDasNotas(recurso({ notes: 'Anota AI #77', internal_id: 77 })), { sistema: 'Anota AI', numero: '77' });
    assert.equal(origemDasNotas(recurso({ notes: 'iFood #4821', internal_id: '4821' })), null, 'o iFood fica com o numeroIfoodDasNotas');
    assert.equal(origemDasNotas(recurso({ notes: 'IFOOD falso #12', internal_id: '12' })), null);
    assert.equal(origemDasNotas(recurso({ notes: 'RestaurantePro #4821', internal_id: '1' })), null);
    assert.equal(origemDasNotas(recurso({ notes: 'Entregar no portão', internal_id: '4821' })), null);
    assert.equal(origemDasNotas(recurso({ notes: null, internal_id: null })), null);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `node --experimental-strip-types --test scripts/testes/cartao-do-pedido.teste.ts`
Expected: FAIL com `SyntaxError` ("does not provide an export named 'origemDasNotas'").

- [ ] **Step 3: Implementar**

Em `src/utils/cartao-do-pedido.ts`, logo depois da função `numeroIfoodDasNotas`:

```ts
// O nome do sistema passa pelo Integracao::nomeDoSistema do servidor: letras, números, espaço, ponto, hífen e sublinhado.
const NOTAS_DE_ORIGEM = /^([\p{L}\p{N} ._-]{1,40}?) #(\S+)/u;

/**
 * O pedido de outro sistema (integração) pelo próprio pedido: notas "RestaurantePro #4821" (com ou sem marcas e
 * observações depois) e internal_id 4821, a mesma regra do console e do portal. O iFood e os outros pedidos: null.
 */
export function origemDasNotas(pedido: any): { sistema: string; numero: string } | null {
    const notas = texto(ler(pedido, 'notes'));
    const interno = texto(ler(pedido, 'internal_id'));
    if (!notas || !interno) return null;
    const achado = NOTAS_DE_ORIGEM.exec(notas);
    if (!achado || achado[2] !== interno) return null;
    const sistema = achado[1].trim();
    if (sistema === '' || /^ifood/i.test(sistema)) return null;
    return { sistema, numero: interno };
}
```

As funções `texto` e `ler` já existem no arquivo (linhas 15 a 25) e são usadas pelo `numeroIfoodDasNotas`. O `texto()` converte número para string e tira os espaços das pontas: o caso `internal_id: 77` do teste depende disso.

- [ ] **Step 4: Rodar e ver passar**

Run: `node --experimental-strip-types --test scripts/testes/cartao-do-pedido.teste.ts`
Expected: PASS em todos.

- [ ] **Step 5: Commit**

```bash
git add src/utils/cartao-do-pedido.ts scripts/testes/cartao-do-pedido.teste.ts
git commit -m "Integração: origem do pedido pelas notas no app

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 15: "Meus ganhos" com o rótulo por origem

**Files:**
- Modify: `src/utils/ganhos.ts` (tipo `EntregaDoMotoboy`, linhas 23-25, e função nova)
- Modify: `src/screens/MeusGanhosScreen.tsx:624`
- Modify: `translations/pt.json`, `translations/en.json` (`MeusGanhosScreen`)
- Test: `scripts/testes/ganhos.teste.ts`

- [ ] **Step 1: Acrescentar o teste que falha**

No fim de `scripts/testes/ganhos.teste.ts`:

```ts
import { chaveDoCanceladoPago } from '../../src/utils/ganhos.ts';

test('rótulo do cancelado pago pela origem', () => {
    assert.equal(chaveDoCanceladoPago('integracao'), 'MeusGanhosScreen.canceladoPagoLoja');
    assert.equal(chaveDoCanceladoPago('ifood'), 'MeusGanhosScreen.canceladoPago');
    assert.equal(chaveDoCanceladoPago(null), 'MeusGanhosScreen.canceladoPago', 'servidor antigo: o rótulo de hoje');
    assert.equal(chaveDoCanceladoPago(undefined), 'MeusGanhosScreen.canceladoPago');
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `node --experimental-strip-types --test scripts/testes/ganhos.teste.ts`
Expected: FAIL com `SyntaxError` ("does not provide an export named 'chaveDoCanceladoPago'").

- [ ] **Step 3: Implementar**

Em `src/utils/ganhos.ts`, no tipo `EntregaDoMotoboy`, troque:

```ts
    /** Pedido iFood cancelado pelo iFood depois da coleta: ele recebe (a data é a do cancelamento). Ausente no servidor antigo. */
    cancelado_pago?: boolean;
```

por:

```ts
    /** Pedido cancelado depois da coleta (pelo iFood ou pela loja no sistema dela): ele recebe (a data é a do cancelamento). Ausente no servidor antigo. */
    cancelado_pago?: boolean;
    /** Quem cancelou o pago mesmo cancelado: 'ifood' ou 'integracao' (a loja pelo sistema dela). null no concluído; ausente no servidor antigo. */
    cancelado_origem?: 'ifood' | 'integracao' | null;
```

E acrescente no fim do arquivo:

```ts
/** A chave de tradução do selo do cancelado pago: a loja (integração) ou o iFood (também o servidor antigo, sem a origem). */
export function chaveDoCanceladoPago(origem: string | null | undefined): string {
    return origem === 'integracao' ? 'MeusGanhosScreen.canceladoPagoLoja' : 'MeusGanhosScreen.canceladoPago';
}
```

- [ ] **Step 4: Tela e traduções**

Em `src/screens/MeusGanhosScreen.tsx`, troque a linha 624:

```tsx
                                {item.cancelado_pago && <Selo variante='alerta'>{t('MeusGanhosScreen.canceladoPago')}</Selo>}
```

por:

```tsx
                                {item.cancelado_pago && <Selo variante='alerta'>{t(chaveDoCanceladoPago(item.cancelado_origem))}</Selo>}
```

E acrescente `chaveDoCanceladoPago` ao import de `'../utils/ganhos'` que a tela já tem. Confira com `grep -n "from '../utils/ganhos'" src/screens/MeusGanhosScreen.tsx`.

Em `translations/pt.json`, em `MeusGanhosScreen`, logo depois de `"canceladoPago": "Cancelado pelo iFood: você recebe",`:

```json
        "canceladoPagoLoja": "Cancelado pela loja: você recebe",
```

Em `translations/en.json`, logo depois da chave `canceladoPago` de `MeusGanhosScreen`:

```json
        "canceladoPagoLoja": "Canceled by the store: you get paid",
```

- [ ] **Step 5: Rodar e conferir o JSON**

```bash
node --experimental-strip-types --test scripts/testes/ganhos.teste.ts
node -e "JSON.parse(require('fs').readFileSync('translations/pt.json','utf8'));JSON.parse(require('fs').readFileSync('translations/en.json','utf8'));console.log('json ok')"
```

Expected: PASS e `json ok`.

- [ ] **Step 6: Commit**

```bash
git add src/utils/ganhos.ts src/screens/MeusGanhosScreen.tsx translations/pt.json translations/en.json scripts/testes/ganhos.teste.ts
git commit -m "Meus ganhos: rótulo do cancelado pago pela origem (iFood ou loja)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 16: Hook `useDadosDaIntegracao`

**Files:**
- Create: `src/hooks/use-dados-da-integracao.ts`

É uma cópia do `use-dados-ifood.ts` com a rota `/integracao` e o tipo `DadosDaIntegracao`. A cópia é de propósito: o hook do iFood não muda, e os dois só se juntam numa limpeza futura. A lógica é a mesma (cache de 2 min por pedido e estado, uma chamada por vez, só a resposta mais nova vai para o cache). Ela não tem teste próprio: é React, e o `node --test` não roda hooks.

- [ ] **Step 1: Criar o hook**

`src/hooks/use-dados-da-integracao.ts`:

```ts
import { useCallback, useEffect, useRef, useState } from 'react';
import useFleetbase from './use-fleetbase';
import type { DadosDaIntegracao } from '../utils/pedido-de-origem';

// Entregas: dados de um pedido de outro sistema para o motoboy (rota v1/entregas/motoboy/pedidos/{id}/integracao):
// sistema, número, cobrança na porta, observações, complemento e o telefone do cliente (só no pedido dele, em
// andamento). Pedido que não é da integração: {"integracao": false} (a tela não mostra nada). id nulo: não busca
// (quem chama passa null quando as notas já dizem que é do iFood).
// Mesma lógica do use-dados-ifood.ts: cache em memória por pedido (2 min) guardado junto com o `estado` do pedido
// (`${status}|${motoboy}|${started}`; o telefone só vem depois do aceite); estado diferente busca de novo; uma chamada
// por vez para o mesmo pedido e estado; recarregar() ignora o cache e só a resposta da chamada mais recente vai para o
// cache; numa recarga os dados antigos ficam na tela até a resposta, e uma falha mantém os que havia.
const VALIDADE_MS = 2 * 60 * 1000;
const guardados: Record<string, { dados: DadosDaIntegracao; em: number; estado?: string }> = {};
const emAndamento: Record<string, { promessa: Promise<DadosDaIntegracao>; estado?: string }> = {};
const ultimaChamada: Record<string, number> = {};

const guardado = (id?: string | null, estado?: string): DadosDaIntegracao | null => {
    const item = id ? guardados[id] : undefined;
    if (!item || Date.now() - item.em >= VALIDADE_MS) return null;
    return estado === undefined || item.estado === estado ? item.dados : null;
};

export function esquecerDadosDaIntegracao(id?: string | null) {
    if (id) delete guardados[id];
}

export function buscarDadosDaIntegracao(adapter: any, id: string, ignorarCache = false, estado?: string): Promise<DadosDaIntegracao> {
    if (!ignorarCache) {
        const pronto = guardado(id, estado);
        if (pronto) return Promise.resolve(pronto);
        const andando = emAndamento[id];
        if (andando && (estado === undefined || andando.estado === estado)) return andando.promessa;
    }
    const numero = (ultimaChamada[id] ?? 0) + 1;
    ultimaChamada[id] = numero;
    const promessa: Promise<DadosDaIntegracao> = adapter
        .get(`entregas/motoboy/pedidos/${id}/integracao`)
        .then((dados: DadosDaIntegracao) => {
            if (ultimaChamada[id] === numero) guardados[id] = { dados, em: Date.now(), estado };
            return dados;
        })
        .finally(() => {
            if (emAndamento[id]?.promessa === promessa) delete emAndamento[id];
        });
    emAndamento[id] = { promessa, estado };
    return promessa;
}

const useDadosDaIntegracao = (id?: string | null, estado?: string) => {
    const { adapter } = useFleetbase();
    const [dados, setDados] = useState<DadosDaIntegracao | null>(() => guardado(id, estado));
    const [versao, setVersao] = useState(0);
    const idAnterior = useRef(id);
    const versaoLida = useRef(0);

    useEffect(() => {
        if (idAnterior.current !== id) {
            idAnterior.current = id;
            setDados(guardado(id, estado));
        }
        if (!adapter || !id) return;
        let ativo = true;
        const recarga = versaoLida.current !== versao;
        versaoLida.current = versao;
        buscarDadosDaIntegracao(adapter, id, recarga, estado)
            .then((novos) => {
                if (ativo) setDados(novos);
            })
            .catch((falha) => {
                console.warn('[integracao] dados do pedido:', falha);
            });
        return () => {
            ativo = false;
        };
    }, [adapter, id, estado, versao]);

    const recarregar = useCallback(() => {
        esquecerDadosDaIntegracao(id);
        setVersao((atual) => atual + 1);
    }, [id]);

    return { dados, recarregar };
};

export default useDadosDaIntegracao;
```

- [ ] **Step 2: Conferir a sintaxe com o Node**

```bash
node --experimental-strip-types -e "import('./src/hooks/use-dados-da-integracao.ts').catch(e=>{ if(/SyntaxError/.test(String(e))) { console.error(e); process.exit(1) } console.log('sintaxe ok (o import de react falha fora do app, é esperado)') })"
```

Expected: `sintaxe ok …`, ou um erro de módulo não encontrado que **não** seja `SyntaxError`.

- [ ] **Step 3: Commit**

```bash
git add src/hooks/use-dados-da-integracao.ts
git commit -m "Integração: hook dos dados do pedido de outro sistema (molde do useDadosIfood)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 17: Componente `PedidoDaIntegracao` e traduções

**Files:**
- Create: `src/components/PedidoDaIntegracao.tsx`
- Modify: `translations/pt.json`, `translations/en.json` (namespace novo `PedidoDaIntegracao`, mais `OrderScreen.canceladoPagoSeloLoja`)

- [ ] **Step 1: Criar o componente**

`src/components/PedidoDaIntegracao.tsx`:

```tsx
import { Linking } from 'react-native';
import { Button, Separator, Text, XStack, YStack, useTheme } from 'tamagui';
import { FontAwesomeIcon } from '@fortawesome/react-native-fontawesome';
import { faCheck, faMoneyBillWave, faPhone } from '@fortawesome/free-solid-svg-icons';
import { useLanguage } from '../contexts/LanguageContext';
import { toast } from '../utils/toast';
import { urlDoTelefone } from '../utils/pedido-ifood';
import { ehIntegracao, textoDaCobrancaDaIntegracao } from '../utils/pedido-de-origem';
import type { DadosDaIntegracao } from '../utils/pedido-de-origem';

// Entregas: o pedido de outro sistema (RestaurantePro…) na tela do pedido (dados de useDadosDaIntegracao), no mesmo
// visual das peças do iFood (PedidoIfood.tsx): a cobrança na porta (cartão amarelo) ou "Já pago" (verde), "Ligar para o
// cliente" (telefone real, só no pedido dele e em andamento) e as observações, o complemento e a referência sem corte.
// Pedido que não é da integração ou sem os dados: nada.

/** Cobrança na porta (cartão amarelo) ou "Já pago, nada a cobrar" (verde). */
export const CobrancaDaIntegracao = ({ dados }: { dados: DadosDaIntegracao | null }) => {
    const theme = useTheme();
    const { t } = useLanguage();
    if (!ehIntegracao(dados)) return null;
    const cobrar = textoDaCobrancaDaIntegracao(dados);

    if (cobrar) {
        return (
            <XStack px='$3' py='$3' gap='$3' alignItems='center' borderRadius='$6' borderWidth={1.5} bg='$avisoSuave' borderColor='$warningBorder'>
                <YStack width={40} height={40} borderRadius={20} bg='$warning' alignItems='center' justifyContent='center'>
                    <FontAwesomeIcon icon={faMoneyBillWave} color={theme['$warningText'].val} size={18} />
                </YStack>
                <YStack flex={1}>
                    <Text color='$tintaAviso' fontSize={12} fontWeight='800' textTransform='uppercase' letterSpacing={0.5}>
                        {t('PedidoDaIntegracao.cobrarNaPorta')}
                    </Text>
                    <Text color='$textPrimary' fontFamily='$heading' fontWeight='700' fontSize={21} lineHeight={25}>
                        {cobrar}
                    </Text>
                </YStack>
            </XStack>
        );
    }

    if (dados.cobranca?.forma !== 'pago') return null;

    return (
        <XStack px='$3' py='$3' gap='$3' alignItems='center' borderRadius='$6' bg='$sucessoSuave'>
            <YStack width={40} height={40} borderRadius={20} bg='$success' alignItems='center' justifyContent='center'>
                <FontAwesomeIcon icon={faCheck} color={theme['$sobreSucesso'].val} size={18} />
            </YStack>
            <YStack flex={1}>
                <Text color='$textPrimary' fontFamily='$heading' fontWeight='700' fontSize={20}>
                    {t('PedidoDaIntegracao.pago')}
                </Text>
                <Text color='$textSecondary' fontSize={13}>
                    {t('PedidoDaIntegracao.pagoTexto')}
                </Text>
            </YStack>
        </XStack>
    );
};

/** "Ligar para o cliente": só aparece quando o servidor manda o telefone (motoboy do pedido, pedido em andamento). */
export const LigarParaOClienteDaIntegracao = ({ dados }: { dados: DadosDaIntegracao | null }) => {
    const theme = useTheme();
    const { t } = useLanguage();
    if (!ehIntegracao(dados) || !dados.telefone) return null;

    const ligar = async () => {
        if (!dados.telefone) return;
        try {
            await Linking.openURL(urlDoTelefone(dados.telefone));
        } catch (err) {
            console.warn('[integracao] erro ao ligar:', err);
            toast.error(t('PedidoDaIntegracao.erroAoLigar'));
        }
    };

    return (
        <Button onPress={ligar} height={52} borderRadius='$5' bg='$textPrimary' borderWidth={0} pressStyle={{ opacity: 0.85 }}>
            <Button.Icon>
                <FontAwesomeIcon icon={faPhone} color={theme['$background'].val} />
            </Button.Icon>
            <Button.Text color='$background' fontFamily='$heading' fontWeight='700' fontSize={20}>
                {t('PedidoDaIntegracao.ligar')}
            </Button.Text>
        </Button>
    );
};

// Observações e complemento podem ser longos: sem limite de linhas e com seleção.
const TextoLongo = ({ titulo, texto }: { titulo: string; texto: string }) => (
    <YStack px='$3' py='$3' gap='$1'>
        <Text color='$textSecondary' fontSize={13}>
            {titulo}
        </Text>
        <Text color='$textPrimary' fontSize={16} lineHeight={22} fontWeight='600' selectable>
            {texto}
        </Text>
    </YStack>
);

/** Observações do cliente, complemento e referência (sem corte). */
export const TextosDaIntegracao = ({ dados }: { dados: DadosDaIntegracao | null }) => {
    const { t } = useLanguage();
    if (!ehIntegracao(dados)) return null;
    const complemento = [dados.complemento, dados.referencia ? t('PedidoDaIntegracao.referencia', { texto: dados.referencia }) : null].filter(Boolean).join(' · ');
    if (!dados.observacoes && !complemento) return null;

    return (
        <YStack borderRadius='$6' borderWidth={1} borderColor='$borderColor' bg='$cartao' overflow='hidden'>
            {!!dados.observacoes && <TextoLongo titulo={t('PedidoDaIntegracao.observacoes')} texto={dados.observacoes} />}
            {!!dados.observacoes && !!complemento && <Separator borderColor='$borderColor' />}
            {!!complemento && <TextoLongo titulo={t('PedidoDaIntegracao.complemento')} texto={complemento} />}
        </YStack>
    );
};
```

Os tokens (`$avisoSuave`, `$warningBorder`, `$tintaAviso`, `$sucessoSuave`, `$sobreSucesso`, `$cartao`) são os mesmos que o `PedidoIfood.tsx` usa. Se o tema não tiver algum, o erro aparece no build do Actions. Copie o que estiver no `PedidoIfood.tsx` da `main`.

- [ ] **Step 2: Traduções**

Em `translations/pt.json`, logo depois do fecho `},` do objeto `"PedidoIfood"`, acrescente:

```json
    "PedidoDaIntegracao": {
        "selo": "{{sistema}} #{{numero}}",
        "cobrarNaPorta": "Cobrar na porta",
        "pago": "Já pago",
        "pagoTexto": "Pago pelo cardápio. Nada a cobrar na porta.",
        "ligar": "Ligar para o cliente",
        "erroAoLigar": "Não foi possível abrir o discador.",
        "observacoes": "Observações do cliente",
        "complemento": "Complemento e referência",
        "referencia": "Ref.: {{texto}}"
    },
```

Em `translations/en.json`, no mesmo lugar:

```json
    "PedidoDaIntegracao": {
        "selo": "{{sistema}} #{{numero}}",
        "cobrarNaPorta": "Charge at the door",
        "pago": "Already paid",
        "pagoTexto": "Paid through the menu. Nothing to charge at the door.",
        "ligar": "Call the customer",
        "erroAoLigar": "Could not open the dialer.",
        "observacoes": "Customer notes",
        "complemento": "Address details and reference",
        "referencia": "Ref.: {{texto}}"
    },
```

Em `translations/pt.json`, em `OrderScreen`, logo depois de `"canceladoPagoSelo": "Cancelado pelo iFood depois da coleta",`:

```json
        "canceladoPagoSeloLoja": "Cancelado pela loja depois da coleta",
```

Em `translations/en.json`, em `OrderScreen`, logo depois da chave `canceladoPagoSelo`:

```json
        "canceladoPagoSeloLoja": "Canceled by the store after pickup",
```

- [ ] **Step 3: Conferir o JSON e as chaves**

```bash
node -e "const fs=require('fs');for(const l of ['pt','en']){const j=JSON.parse(fs.readFileSync('translations/'+l+'.json','utf8'));if(!j.PedidoDaIntegracao||!j.OrderScreen.canceladoPagoSeloLoja)throw new Error(l);}console.log('json ok')"
```

Expected: `json ok`.

- [ ] **Step 4: Commit**

```bash
git add src/components/PedidoDaIntegracao.tsx translations/pt.json translations/en.json
git commit -m "Integração: cobrança na porta, ligar para o cliente e observações do pedido de outro sistema

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 18: Cartões da aba Pedidos (oferta, pedido aberto e lista)

**Files:**
- Modify: `src/components/entregas/CartaoDeOferta.tsx` (tipo `DadosDoCartao`, linhas 15-25, e a linha 97)
- Modify: `src/components/entregas/CartaoDePedidoAberto.tsx:48`
- Modify: `src/components/AdhocOrderCard.tsx` (linhas 7-8, 18, 35-36 e o `useMemo` das linhas 108-132)
- Modify: `src/components/OrderCard.tsx` (linhas 9, 28 e 39-43)

- [ ] **Step 1: `seloOrigem` no tipo do cartão**

Em `src/components/entregas/CartaoDeOferta.tsx`, no tipo `DadosDoCartao`, troque:

```ts
    seloIfood: string | null; // "iFood #4821"
    numero: string | null; // o código do pedido que não é do iFood
    cobranca: string | null; // cobrança na porta (iFood)
```

por:

```ts
    seloIfood: string | null; // "iFood #4821"
    seloOrigem: string | null; // "RestaurantePro #4821" (pedido de outro sistema pela integração)
    numero: string | null; // o código do pedido que não é do iFood nem de outro sistema
    cobranca: string | null; // cobrança na porta (iFood ou integração)
```

Na linha 97 do mesmo arquivo e na linha 48 de `CartaoDePedidoAberto.tsx`, troque:

```tsx
{dados.seloIfood ? <Selo variante='ifood'>{dados.seloIfood}</Selo> : dados.numero ? <Selo variante='neutro'>{dados.numero}</Selo> : null}
```

por:

```tsx
{dados.seloIfood ? <Selo variante='ifood'>{dados.seloIfood}</Selo> : dados.seloOrigem ? <Selo variante='laranja'>{dados.seloOrigem}</Selo> : dados.numero ? <Selo variante='neutro'>{dados.numero}</Selo> : null}
```

A indentação é a de cada arquivo. Só o trecho entre `{` e `}` muda.

- [ ] **Step 2: `AdhocOrderCard`**

Em `src/components/AdhocOrderCard.tsx`:

1. Troque o import `import { destinoDoPedido, numeroIfoodDasNotas, totalDoAnel } from '../utils/cartao-do-pedido';` por:

```ts
import { destinoDoPedido, numeroIfoodDasNotas, origemDasNotas, totalDoAnel } from '../utils/cartao-do-pedido';
import { origemDosDados, textoDaCobrancaDaIntegracao } from '../utils/pedido-de-origem';
import useDadosDaIntegracao from '../hooks/use-dados-da-integracao';
```

2. Logo depois de `const { dados: dadosIfood } = useDadosIfood(order.id);`:

```ts
    // Entregas: selo e cobrança do pedido de outro sistema (integração). Pedido do iFood pelas notas: nem busca.
    const { dados: dadosIntegracao } = useDadosDaIntegracao(numeroIfoodDasNotas(order) ? null : order.id);
```

3. No `useMemo` do `dados`, troque as linhas:

```ts
        const numeroIfood = ehIfood(dadosIfood) ? dadosIfood.numero : numeroIfoodDasNotas(order);
        const seloIfood = ehIfood(dadosIfood) || numeroIfood ? (numeroIfood ? t('PedidoIfood.selo', { numero: numeroIfood }) : t('PedidoIfood.seloSemNumero')) : null;
```

por:

```ts
        const numeroIfood = ehIfood(dadosIfood) ? dadosIfood.numero : numeroIfoodDasNotas(order);
        const seloIfood = ehIfood(dadosIfood) || numeroIfood ? (numeroIfood ? t('PedidoIfood.selo', { numero: numeroIfood }) : t('PedidoIfood.seloSemNumero')) : null;
        const origem = seloIfood ? null : (origemDosDados(dadosIntegracao) ?? origemDasNotas(order));
        const seloOrigem = origem ? (origem.numero ? t('PedidoDaIntegracao.selo', { sistema: origem.sistema, numero: origem.numero }) : origem.sistema) : null;
```

4. No objeto devolvido, troque:

```ts
            seloIfood,
            numero: seloIfood ? null : numeroDoPedido(order),
            cobranca: temCobranca(dadosIfood) && ehIfood(dadosIfood) ? dadosIfood.cobranca.texto : null,
```

por:

```ts
            seloIfood,
            seloOrigem,
            numero: seloIfood || seloOrigem ? null : numeroDoPedido(order),
            cobranca: temCobranca(dadosIfood) && ehIfood(dadosIfood) ? dadosIfood.cobranca.texto : textoDaCobrancaDaIntegracao(dadosIntegracao),
```

5. Na lista de dependências do `useMemo`, troque `[order, valor, carregandoValor, rota, dadosIfood, distance, t]` por `[order, valor, carregandoValor, rota, dadosIfood, dadosIntegracao, distance, t]`.

- [ ] **Step 3: `OrderCard` (lista "Em andamento")**

Em `src/components/OrderCard.tsx`, troque o import `import { destinoDoPedido, numeroIfoodDasNotas, situacaoDoAndamento } from '../utils/cartao-do-pedido';` por:

```ts
import { destinoDoPedido, numeroIfoodDasNotas, origemDasNotas, situacaoDoAndamento } from '../utils/cartao-do-pedido';
```

Logo depois de `const numeroIfood = numeroIfoodDasNotas(order);`:

```ts
    const origem = numeroIfood ? null : origemDasNotas(order);
```

E troque:

```tsx
                        {numeroIfood ? (
                            <Selo variante='ifood'>{t('PedidoIfood.selo', { numero: numeroIfood })}</Selo>
                        ) : (
                            <Selo variante='neutro'>{numeroDoPedido(order) ?? t('OrderCard.notAvailable')}</Selo>
                        )}
```

por:

```tsx
                        {numeroIfood ? (
                            <Selo variante='ifood'>{t('PedidoIfood.selo', { numero: numeroIfood })}</Selo>
                        ) : origem ? (
                            <Selo variante='laranja'>{t('PedidoDaIntegracao.selo', { sistema: origem.sistema, numero: origem.numero })}</Selo>
                        ) : (
                            <Selo variante='neutro'>{numeroDoPedido(order) ?? t('OrderCard.notAvailable')}</Selo>
                        )}
```

- [ ] **Step 4: Conferir que nenhum `DadosDoCartao` ficou sem `seloOrigem`**

```bash
grep -rn "DadosDoCartao" src | grep -v "import type\|export type"
```

Expected: só o `AdhocOrderCard.tsx` e o `OrderScreen.tsx`. O do `OrderScreen` entra na Task 19. Se aparecer outro arquivo, acrescente `seloOrigem: null` nele.

- [ ] **Step 5: Testes**

Run: `node --experimental-strip-types --test scripts/testes/*.teste.ts`
Expected: PASS em todos.

- [ ] **Step 6: Commit**

```bash
git add src/components/entregas/CartaoDeOferta.tsx src/components/entregas/CartaoDePedidoAberto.tsx src/components/AdhocOrderCard.tsx src/components/OrderCard.tsx
git commit -m "Integração: selo de origem e cobrança no card de aceitar e na lista de pedidos

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 19: Tela do pedido (`OrderScreen`)

**Files:**
- Modify: `src/screens/OrderScreen.tsx`

Os números de linha são os da `main` em 2026-10-12. Ache cada trecho pelo texto.

- [ ] **Step 1: Imports**

Troque `import { destinoDoPedido, numeroIfoodDasNotas, totalDoAnel } from '../utils/cartao-do-pedido';` (linha 21) por:

```ts
import { destinoDoPedido, numeroIfoodDasNotas, origemDasNotas, totalDoAnel } from '../utils/cartao-do-pedido';
```

Depois da linha `import { CobrancaDoIfood, LigarParaOCliente, LocalizadorIfood, TextosDoIfood } from '../components/PedidoIfood';` (linha 48), acrescente:

```ts
import { CobrancaDaIntegracao, LigarParaOClienteDaIntegracao, TextosDaIntegracao } from '../components/PedidoDaIntegracao';
import useDadosDaIntegracao from '../hooks/use-dados-da-integracao';
import { ehIntegracao, origemDosDados, textoDaCobrancaDaIntegracao } from '../utils/pedido-de-origem';
```

- [ ] **Step 2: Dados da integração**

Logo depois de `estadoIfoodRef.current = estadoIfood;` (linha 122):

```ts
    // Entregas: pedido de outro sistema (integração): selo, cobrança, telefone do cliente e observações. Usa o mesmo
    // estado do iFood (o telefone só vem depois do aceite). Pedido do iFood pelas notas: nem busca.
    const { dados: dadosIntegracao } = useDadosDaIntegracao(numeroIfoodDasNotas(order) ? null : order.id, estadoIfood);
```

- [ ] **Step 3: Selo, cartão da tela e dados do cartão**

Troque o bloco (linhas 894-904):

```ts
    const ifood = ehIfood(dadosIfood) ? dadosIfood : null;
    const numeroIfood = ifood ? ifood.numero : numeroIfoodDasNotas(order);
    const seloIfood = ifood || numeroIfood ? (numeroIfood ? t('PedidoIfood.selo', { numero: numeroIfood }) : t('PedidoIfood.seloSemNumero')) : null;
    const numeroComum = seloIfood ? null : numeroDoPedido(order);
    const atribuidoA = order.getAttribute('driver_assigned')?.id ?? null;
    const cartao = cartaoDaTela({
        status,
        canceladoPeloIfood: Boolean(ifood?.cancelado_pelo_ifood),
        pagoMesmoCancelado: Boolean(ifood?.pago_mesmo_cancelado),
```

por:

```ts
    const ifood = ehIfood(dadosIfood) ? dadosIfood : null;
    const numeroIfood = ifood ? ifood.numero : numeroIfoodDasNotas(order);
    const seloIfood = ifood || numeroIfood ? (numeroIfood ? t('PedidoIfood.selo', { numero: numeroIfood }) : t('PedidoIfood.seloSemNumero')) : null;
    const integracao = !seloIfood && ehIntegracao(dadosIntegracao) ? dadosIntegracao : null;
    const origem = seloIfood ? null : (origemDosDados(integracao) ?? origemDasNotas(order));
    const seloOrigem = origem ? (origem.numero ? t('PedidoDaIntegracao.selo', { sistema: origem.sistema, numero: origem.numero }) : origem.sistema) : null;
    const numeroComum = seloIfood || seloOrigem ? null : numeroDoPedido(order);
    const atribuidoA = order.getAttribute('driver_assigned')?.id ?? null;
    const cartao = cartaoDaTela({
        status,
        canceladoPeloIfood: Boolean(ifood?.cancelado_pelo_ifood),
        pagoMesmoCancelado: Boolean(ifood?.pago_mesmo_cancelado || integracao?.pago_mesmo_cancelado),
```

O resto da chamada (`pedidoAberto`, `deOutroMotoboy`) continua igual.

No `dadosDoCartao` (linhas 933-943), troque:

```ts
        seloIfood,
        numero: numeroComum,
        cobranca: ifood && temCobranca(ifood) ? ifood.cobranca.texto : null,
```

por:

```ts
        seloIfood,
        seloOrigem,
        numero: numeroComum,
        cobranca: ifood && temCobranca(ifood) ? ifood.cobranca.texto : textoDaCobrancaDaIntegracao(integracao),
```

- [ ] **Step 4: Ligar da folha e complemento**

No `ligarDaFolha` (linhas 974-987), logo depois do bloco `if (etapa === 'cliente' && ifood?.telefone) { … }` e antes de `return telefoneDaLoja ? ligarParaALoja : undefined;`, acrescente:

```ts
        if (etapa === 'cliente' && integracao?.telefone) {
            return async () => {
                try {
                    await Linking.openURL(urlDoTelefone(integracao.telefone ?? ''));
                } catch (err) {
                    console.warn('[integracao] erro ao ligar:', err);
                    toast.error(t('PedidoDaIntegracao.erroAoLigar'));
                }
            };
        }
```

No `detalheDaFolha` (linha 1010), troque `[destino.bairro, ifood?.complemento]` por `[destino.bairro, ifood?.complemento ?? integracao?.complemento]`.

- [ ] **Step 5: Folha recolhida (a caminho do cliente)**

Troque (linhas 1101-1102):

```tsx
                            {etapa === 'cliente' && ifood ? (
                                <CobrancaDoIfood dados={ifood} />
                            ) : (
```

por:

```tsx
                            {etapa === 'cliente' && ifood ? (
                                <CobrancaDoIfood dados={ifood} />
                            ) : etapa === 'cliente' && integracao && (textoDaCobrancaDaIntegracao(integracao) || integracao.cobranca?.forma === 'pago') ? (
                                <CobrancaDaIntegracao dados={integracao} />
                            ) : (
```

- [ ] **Step 6: Folha expandida**

Troque (linhas 1139-1152):

```tsx
                            {naLoja && seloIfood && (
                                <YStack alignItems='center' bg='$surface' borderRadius='$6' px='$3' py='$3'>
                                    <Text color='$textSecondary' fontSize={12} fontWeight='800' textTransform='uppercase' letterSpacing={0.6}>
                                        {t('OrderScreen.balcao')}
                                    </Text>
                                    <Text color='$textPrimary' fontFamily='$heading' fontWeight='800' fontSize={48} lineHeight={52}>
                                        {seloIfood}
                                    </Text>
                                </YStack>
                            )}
                            {naLoja && <CobrancaDoIfood dados={dadosIfood} />}
                            <LigarParaOCliente dados={dadosIfood} />
                            <LocalizadorIfood dados={dadosIfood} />
                            <TextosDoIfood dados={dadosIfood} />
```

por:

```tsx
                            {naLoja && (seloIfood || seloOrigem) && (
                                <YStack alignItems='center' bg='$surface' borderRadius='$6' px='$3' py='$3'>
                                    <Text color='$textSecondary' fontSize={12} fontWeight='800' textTransform='uppercase' letterSpacing={0.6}>
                                        {t('OrderScreen.balcao')}
                                    </Text>
                                    <Text
                                        color='$textPrimary'
                                        fontFamily='$heading'
                                        fontWeight='800'
                                        fontSize={seloIfood ? 48 : 34}
                                        lineHeight={seloIfood ? 52 : 38}
                                        textAlign='center'
                                        numberOfLines={2}
                                    >
                                        {seloIfood ?? seloOrigem}
                                    </Text>
                                </YStack>
                            )}
                            {naLoja && <CobrancaDoIfood dados={dadosIfood} />}
                            <LigarParaOCliente dados={dadosIfood} />
                            <LocalizadorIfood dados={dadosIfood} />
                            <TextosDoIfood dados={dadosIfood} />
                            {naLoja && <CobrancaDaIntegracao dados={integracao} />}
                            <LigarParaOClienteDaIntegracao dados={integracao} />
                            <TextosDaIntegracao dados={integracao} />
```

- [ ] **Step 7: Cartão do cancelado pago e cabeçalho**

No cartão `canceladoPago` (linha 1281), troque `selo={t('OrderScreen.canceladoPagoSelo')}` por:

```tsx
                    selo={integracao?.pago_mesmo_cancelado ? t('OrderScreen.canceladoPagoSeloLoja') : t('OrderScreen.canceladoPagoSelo')}
```

No cabeçalho (linhas 1439-1443), troque:

```tsx
                        <Text color='$background' fontFamily='$heading' fontWeight='700' fontSize={19} numberOfLines={1}>
                            {numeroComum ?? ''}
                        </Text>
```

por:

```tsx
                        <Text color='$background' fontFamily='$heading' fontWeight='700' fontSize={19} numberOfLines={1}>
                            {seloOrigem ?? numeroComum ?? ''}
                        </Text>
```

No placar (linha 1484), troque `subtitulo={[seloIfood ?? numeroComum, destino.rua]…` por `subtitulo={[seloIfood ?? seloOrigem ?? numeroComum, destino.rua]…`, mantendo o resto da linha.

- [ ] **Step 8: Conferir os usos**

```bash
grep -n "seloOrigem\|integracao\b\|dadosIntegracao" src/screens/OrderScreen.tsx
node --experimental-strip-types --test scripts/testes/*.teste.ts
```

Expected: `seloOrigem`, `integracao` e `dadosIntegracao` aparecem nos trechos acima, e os testes passam. Se o projeto tiver `npx tsc --noEmit` funcionando na `main` (confira antes de mexer), rode-o também e compare os erros com os da `main`: este ramo não pode acrescentar erro novo.

- [ ] **Step 9: Commit**

```bash
git add src/screens/OrderScreen.tsx
git commit -m "Integração: tela do pedido com selo de origem, cobrança, telefone do cliente e cancelado pela loja

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 20: Cartão do alarme (Kotlin)

**Files:**
- Modify: `android/app/src/main/java/io/fleetbase/navigator/AlarmePedidoActivity.kt` (linhas 228-231, 241 e a função nova perto da linha 436)

Mudança mínima: o Kotlin só compila no GitHub Actions. O `entregas_cobrar` já é lido hoje e o servidor já o preenche para a integração. Falta só o selo.

- [ ] **Step 1: Ler o `entregas_origem`**

Troque:

```kotlin
    // Entregas: pedido do iFood (CartaoDoAlarme do servidor): o número e o texto da cobrança na porta
    val numeroIfood = dados.getString("entregas_ifood")
    val cobrar = dados.getString("entregas_cobrar")
    val temCartao = valor != null || km != null || loja != null || destino != null || numeroIfood != null || cobrar != null
```

por:

```kotlin
    // Entregas: pedido do iFood (CartaoDoAlarme do servidor): o número e o texto da cobrança na porta. Pedido de outro
    // sistema (integração): entregas_origem = "RestaurantePro #4821" (texto pronto do servidor) e a mesma cobrança
    val numeroIfood = dados.getString("entregas_ifood")
    val origem = dados.getString("entregas_origem")
    val cobrar = dados.getString("entregas_cobrar")
    val temCartao = valor != null || km != null || loja != null || destino != null || numeroIfood != null || origem != null || cobrar != null
```

- [ ] **Step 2: Mostrar o selo**

Troque:

```kotlin
      numeroIfood?.let { addView(seloIfood(it).comMargem(topo = 10)) }
```

por:

```kotlin
      numeroIfood?.let { addView(seloIfood(it).comMargem(topo = 10)) }
      if (numeroIfood == null) origem?.let { addView(seloDaOrigem(it).comMargem(topo = 10)) }
```

- [ ] **Step 3: Função do selo**

Logo depois da função `seloIfood` (que termina com `layoutParams = LinearLayout.LayoutParams(…)` e `}`), acrescente:

```kotlin
  /** Entregas: selo branco com o texto escuro "RestaurantePro #4821" embaixo do título (pedido de outro sistema). */
  private fun seloDaOrigem(texto: String): TextView =
    rotulo(texto, 16f, Color.parseColor("#211208"), negrito = true).apply {
      background = GradientDrawable().apply {
        setColor(Color.WHITE)
        cornerRadius = dp(8).toFloat()
      }
      setPadding(dp(12), dp(4), dp(12), dp(4))
      layoutParams = LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT)
    }
```

- [ ] **Step 4: Commit**

```bash
git add android/app/src/main/java/io/fleetbase/navigator/AlarmePedidoActivity.kt
git commit -m "Alarme: selo do pedido de outro sistema (entregas_origem) no cartão

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 21: Compilar o APK no ramo (sem publicar)

O workflow `build-apk.yml` aceita `workflow_dispatch`. A publicação no MinIO, que vira a versão mínima obrigatória, só roda na `main` (`if: github.ref == 'refs/heads/main'`). Rodar no ramo compila o Kotlin e o JS sem travar ninguém.

- [ ] **Step 1: Testes de novo**

Run: `node --experimental-strip-types --test scripts/testes/*.teste.ts`
Expected: PASS em todos.

- [ ] **Step 2: Subir o ramo e disparar o build (pedir o OK do Edgard antes do push)**

```bash
git push -u origin integracao-restaurantepro
gh workflow run build-apk.yml --ref integracao-restaurantepro
gh run list --workflow build-apk.yml --branch integracao-restaurantepro --limit 1
```

Acompanhe com `gh run watch <id>`. Expected: build verde e o artifact `entregas-motoboy-<n>` disponível.

- [ ] **Step 3: Teste no celular, com o APK do artifact**

Instale o APK do artifact com `adb install -r` num celular de teste. Esse APK não vira a versão mínima, mas tem número de build maior que o atual. Com a integração ainda desligada, confira:
- que um pedido iFood (loja de teste) continua com o selo vermelho, a faixa e o código;
- que um pedido comum continua com o número neutro;
- que "Meus ganhos" abre.

O pedido da integração só aparece depois do plano 3 e do `ENTREGAS_INTEGRACAO=1`.

- [ ] **Step 4: Merge na `main` (só com o OK do Edgard)**

```bash
git checkout main && git pull --ff-only
git merge --no-ff integracao-restaurantepro -m "Merge do ramo integracao-restaurantepro: pedido de outro sistema no app (selo, cobrança, telefone e alarme)"
git push origin main
```

O push na `main` gera o APK, publica no MinIO e o transforma na versão mínima: **todos os motoboys terão de atualizar**. Se o build da `main` quebrar o app, use a emergência do `CLAUDE.md` ("Trava 'Atualize o app'"): `ENTREGAS_APP_VERSAO_MINIMA=<n do APK bom>` no `stack.env` e Update the stack.

---

# Parte C: implantação

Ordem (o spec, seção "Implantação", passos 2 e 4; a API já está em produção):

1. **Console e portal:** merge do ramo `integracao-console-e-app` na `main` do `Delivery` e push (só com o OK do Edgard). Na VPS: `cd ~/entregas && bash deploy/atualizar.sh console` (~15 min). Depois, Ctrl+Shift+R e uma **janela anônima** para o portal, porque o console guarda o `fleetbase.config.json` e a lista de extensões no localStorage por 1 h.
   - Conferir: tela Lojas com o bloco "Integração" ("—" e sem Gerar, enquanto `ENTREGAS_INTEGRACAO` estiver vazia); Pagamento e cobrança e extrato sem mudança visível nos pedidos iFood.
2. **APK:** Task 21, Step 4 (push na `main` do `entregas-navigator`). A trava "Atualize o app" já está ligada, então o APK novo vira obrigatório sozinho. Espere os motoboys atualizarem, ou confira no log `[entregas] app do motoboy desatualizado` que ninguém ficou para trás.
3. **Plano 3** (RestaurantePro, `restaurantepro-main`).
4. **Só então** `ENTREGAS_INTEGRACAO=1` no `stack.env` + Update the stack ("Re-pull image" desligado). Os rótulos "Cancelado pela loja" precisam estar no ar antes disso.
5. Teste com a Terraço Pizza Bar (spec, "Implantação", passo 7), com os motoboys avisados: Gerar chave na tela Lojas → colar no RestaurantePro → pedido entra com o selo "RestaurantePro #N" no console, no portal e no app → faixa amarela e "Ligar para o cliente" no app → cartão do alarme com o selo → cancelar depois do "A caminho" e conferir "Cancelado pela loja (pago)" no Pagamento e cobrança, "Cancelado pela loja (cobrado)" no extrato e "Cancelado pela loja: você recebe" em Meus ganhos.

Se algo der errado: `ENTREGAS_INTEGRACAO=` vazio e Update the stack. As telas novas não fazem nada sem pedidos da integração.

---

## Autorrevisão (feita ao escrever o plano)

- **Cobertura do pedido:**
  - item 1 (selo de origem): Tasks 1 e 2;
  - item 2 (painel do pedido): Task 3;
  - item 3 (tela Lojas): Tasks 4 e 5;
  - item 4 (rótulos): Tasks 6, 9 e 15;
  - item 5 (portal): Tasks 7 e 8; o Cancelar fica como está;
  - item 6 (APK): Tasks 13 a 21; o rótulo de "Meus ganhos" está na Task 15;
  - item 7 (i18n): em cada task e na Task 10;
  - testes: Tasks 1, 4, 7, 13, 14 e 15;
  - ordem de implantação: Parte C.
- **Spec, Parte 4:** "Sem código de entrega: conclusão de sempre" vale sem mudança, porque o `precisaPassarPeloIfood` só olha o iFood. O `entregas_cobrar` do alarme já vem do servidor.
- **Nomes conferidos entre tasks:**
  - `origemDoPedido`, `origemDoModelo`, `ehPedidoDaIntegracao`, `chaveDoAviso`, `chaveDoCanceladoPor`, `chaveDoCanceladoPago` (console);
  - `origemDoPedido`, `chaveDoCanceladoCobrado` (portal);
  - `situacaoDaIntegracao`, `tempoDesde`;
  - `ehIntegracao`, `temCobrancaDaIntegracao`, `textoDaCobrancaDaIntegracao`, `origemDosDados`, `origemDasNotas`, `chaveDoCanceladoPago` (app; nome igual ao do console, mas em outro repo e com outra chave de retorno);
  - `useDadosDaIntegracao`, `CobrancaDaIntegracao` (componente; o tipo do mesmo nome fica em `utils/pedido-de-origem.ts` e não é importado no componente), `LigarParaOClienteDaIntegracao`, `TextosDaIntegracao`, `seloOrigem`.
