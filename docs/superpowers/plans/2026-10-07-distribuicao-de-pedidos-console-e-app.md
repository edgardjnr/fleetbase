# Distribuição de pedidos abertos (console e app): plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mostrar a distribuição no console (painel "Distribuição" no detalhe do pedido, com "Abrir a todos agora") e, no APK, a oferta com cronômetro de 30 s e o Recusar que avisa o servidor, no cartão do alarme e na aba Pedidos.

**Architecture:** Depende do plano da API (`docs/superpowers/plans/2026-10-07-distribuicao-de-pedidos-api.md`), que já entrega as rotas `GET int/v1/entregas/pedidos/{id}/distribuicao`, `POST .../distribuicao/abrir`, `POST v1/entregas/motoboy/pedidos/{id}/recusar`, o campo `entregas_oferta` na lista do app e os dados `entregas_oferta`/`entregas_oferta_vence_em` no push. Console: um componente irmão do painel iFood (`order/details/distribuicao`) e funções puras em `utils/distribuicao.js`. App (repo `entregas-navigator`): funções puras em `src/utils/oferta.ts`, o card de aceitar com cronômetro e Recusar, e o cartão nativo (Kotlin) com o prazo da oferta; o Recusar do cartão vira "abrir o app e recusar" (o JS chama o servidor), porque o Kotlin não tem cliente HTTP nem o token do motoboy. Spec: `docs/superpowers/specs/2026-10-07-distribuicao-de-pedidos-design.md`, seções 6 e 7.

**Tech Stack:** Ember (Glimmer, ember-concurrency, ember-intl) no console; React Native 0.86 + `@fleetbase/sdk` e Kotlin no app. Testes: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/distribuicao.test.mjs` (console) e `node --experimental-strip-types --test scripts/testes/oferta.teste.ts` (app).

**Desvio do spec, decidido aqui:** no cartão nativo, Recusar com oferta abre o app no pedido com `entregas_recusar=1` e o JS chama a rota de recusa (com o celular bloqueado, pede o desbloqueio como o Aceitar). Sem isso, o Kotlin precisaria do token do motoboy e de um cliente HTTP. A oferta vence sozinha em 30 s se o motoboy não desbloquear.

**Fora do escopo:** as outras melhorias do APK que o Edgard tem em andamento.

---

## Convenções

- **Console:** chaves novas em `fleet-ops.ui.distribuicao.*` nos dois YAML de `packages/fleetops/translations/` (pt-br com 2 espaços de indentação por nível, en-us com 4; o bloco `ifood:` fica na linha ~234 dos dois, dentro de `ui:`). Validação antes de commitar: `node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal` (exit 0) e todo JS alterado parseando com `@babel/parser` de `console/node_modules/.pnpm/@babel+parser@7*`.
- **App:** traduções em `translations/pt.json` e `translations/en.json`. Utils sem imports. Repo separado: `C:\Users\Edgardjr\Documents\vibe coding\entregas-navigator`, ramo `distribuicao-de-pedidos` a partir da `main`. O Kotlin só compila no GitHub Actions.
- **Commits:** em português, terminando com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Confirme `git rev-parse --show-toplevel` antes.

## Estrutura de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `packages/fleetops/addon/utils/distribuicao.js` | Funções puras do painel: rótulos, segundos restantes, minutos. |
| `packages/fleetops/addon/components/order/details/distribuicao.js` / `.hbs` | O painel. |
| `packages/fleetops/app/components/order/details/distribuicao.js` | Re-export do componente. |
| `packages/fleetops/addon/components/order/details.hbs` | Usa o painel ao lado do iFood. |
| `packages/fleetops/translations/{pt-br,en-us}.yaml` | `fleet-ops.ui.distribuicao.*`. |
| `scripts/teste-portal/distribuicao.test.mjs` | Testes do util do console. |
| `entregas-navigator/src/utils/oferta.ts` | Funções puras: segundos restantes, se é oferta, ordem da lista, recusar pelo alarme. |
| `entregas-navigator/scripts/testes/oferta.teste.ts` | Testes. |
| `entregas-navigator/src/utils/lista-de-pedidos.ts` | A oferta no topo de "Novos pedidos". |
| `entregas-navigator/src/components/AdhocOrderCard.tsx` | Cronômetro e Recusar pelo servidor. |
| `entregas-navigator/src/layouts/DriverLayout.tsx` | `entregas_recusar=1` do cartão → recusa no servidor. |
| `entregas-navigator/android/.../AlarmeDePedido.kt`, `AlarmePedidoActivity.kt` | Prazo da oferta no cartão e na notificação; Recusar abre o app. |
| `entregas-navigator/android/.../res/values/entregas_strings.xml` | `alarme_oferta_titulo`. |
| `entregas-navigator/translations/{pt,en}.json` | Textos novos. |

---

### Task 1: Console: funções puras do painel

**Files:**
- Create: `packages/fleetops/addon/utils/distribuicao.js`
- Test: `scripts/teste-portal/distribuicao.test.mjs`

- [ ] **Step 1: Teste**

```js
// Distribuição de pedidos abertos no console (packages/fleetops/addon/utils/distribuicao.js): o painel do detalhe do pedido.
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/distribuicao.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FASES, MOTIVOS, RESPOSTAS, chaveDaFase, chaveDoMotivo, chaveDaResposta, minutos, podeAbrir, segundosRestantes } from '../../packages/fleetops/addon/utils/distribuicao.js';

test('chaves de tradução: conhecidas e desconhecidas', () => {
    assert.deepEqual(FASES, ['ofertas', 'aberta', 'encerrada']);
    assert.equal(chaveDaFase('ofertas'), 'ofertas');
    assert.equal(chaveDaFase('outra'), 'desconhecida');
    assert.ok(MOTIVOS.includes('aberta_pela_central') && MOTIVOS.includes('redespachada'));
    assert.equal(chaveDoMotivo('fila_esgotada'), 'fila_esgotada');
    assert.equal(chaveDoMotivo(null), null);
    assert.equal(chaveDoMotivo('x'), 'desconhecido');
    assert.deepEqual(RESPOSTAS, ['pendente', 'aceita', 'recusada', 'vencida', 'cancelada']);
    assert.equal(chaveDaResposta('vencida'), 'vencida');
    assert.equal(chaveDaResposta('x'), 'desconhecida');
});

test('segundos restantes da oferta', () => {
    const agora = Date.parse('2026-10-07T13:00:00Z');
    assert.equal(segundosRestantes('2026-10-07T10:00:30-03:00', agora), 30);
    assert.equal(segundosRestantes('2026-10-07T10:00:00.400-03:00', agora), 1, 'arredonda para cima');
    assert.equal(segundosRestantes('2026-10-07T09:59:00-03:00', agora), 0, 'venceu: 0');
    assert.equal(segundosRestantes(null, agora), 0);
    assert.equal(segundosRestantes('lixo', agora), 0);
});

test('minutos do tempo estimado', () => {
    assert.equal(minutos(0), 0);
    assert.equal(minutos(59), 1);
    assert.equal(minutos(600), 10);
    assert.equal(minutos(null), null);
});

test('pode abrir a todos: só em ofertas e com pedido não encerrado', () => {
    assert.equal(podeAbrir({ fase: 'ofertas' }, 'dispatched'), true);
    assert.equal(podeAbrir({ fase: 'aberta' }, 'dispatched'), false);
    assert.equal(podeAbrir({ fase: 'ofertas' }, 'canceled'), false);
    assert.equal(podeAbrir(null, 'dispatched'), false);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/distribuicao.test.mjs`
Expected: FAIL (módulo não encontrado).

- [ ] **Step 3: Implementar**

```js
// Entregas: painel "Distribuição" no detalhe do pedido (oferta um a um aos motoboys; ver App\Support\Entregas\Distribuicao
// na API). Só importa outro util relativo (pedido-ifood): testado no Node com o resolver.mjs (scripts/teste-portal/distribuicao.test.mjs).
import { ENCERRADOS } from './pedido-ifood';

/** Fases da distribuição (entregas_distribuicoes.fase) → chave fleet-ops.ui.distribuicao.fase.<chave>. */
export const FASES = ['ofertas', 'aberta', 'encerrada'];

/** Motivos (entregas_distribuicoes.motivo) → chave fleet-ops.ui.distribuicao.motivo.<chave>. */
export const MOTIVOS = ['aceita', 'atribuida', 'cancelada', 'aberta_pela_central', 'fila_esgotada', 'prazo', 'sem_candidato', 'redespachada'];

/** Respostas da oferta (entregas_ofertas.resposta) → chave fleet-ops.ui.distribuicao.resposta.<chave>. */
export const RESPOSTAS = ['pendente', 'aceita', 'recusada', 'vencida', 'cancelada'];

export function chaveDaFase(fase) {
    return FASES.includes(fase) ? fase : 'desconhecida';
}

export function chaveDoMotivo(motivo) {
    if (motivo === null || motivo === undefined || motivo === '') {
        return null;
    }

    return MOTIVOS.includes(motivo) ? motivo : 'desconhecido';
}

export function chaveDaResposta(resposta) {
    return RESPOSTAS.includes(resposta) ? resposta : 'desconhecida';
}

/** Segundos até vencer (arredonda para cima; 0 se venceu ou data inválida). */
export function segundosRestantes(venceEm, agora = Date.now()) {
    const fim = Date.parse(venceEm ?? '');
    if (!Number.isFinite(fim)) {
        return 0;
    }

    return Math.max(0, Math.ceil((fim - agora) / 1000));
}

/** Segundos → minutos inteiros arredondados para cima (null fica null). */
export function minutos(segundos) {
    if (segundos === null || segundos === undefined || !Number.isFinite(Number(segundos))) {
        return null;
    }

    return Math.ceil(Number(segundos) / 60);
}

/** "Abrir a todos agora": só com a distribuição em ofertas e o pedido não encerrado. */
export function podeAbrir(painel, statusDoPedido) {
    return painel?.fase === 'ofertas' && !ENCERRADOS.includes(statusDoPedido);
}
```

O import relativo de `./pedido-ifood` funciona no Node com o `resolver.mjs` (completa o `.js`) e no Ember.

- [ ] **Step 4: Rodar e ver passar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/distribuicao.test.mjs`
Expected: PASS (4 testes).

- [ ] **Step 5: Commit**

```bash
git add packages/fleetops/addon/utils/distribuicao.js scripts/teste-portal/distribuicao.test.mjs
git commit -m "Distribuição (console): funções puras do painel"
```

---

### Task 2: Console: o painel "Distribuição"

**Files:**
- Create: `packages/fleetops/addon/components/order/details/distribuicao.js`
- Create: `packages/fleetops/addon/components/order/details/distribuicao.hbs`
- Create: `packages/fleetops/app/components/order/details/distribuicao.js`
- Modify: `packages/fleetops/addon/components/order/details.hbs` (ao lado do `<Order::Details::Ifood .../>`, linha ~54)
- Modify: `packages/fleetops/translations/pt-br.yaml`, `en-us.yaml`

O painel aparece só para administradores e só em pedido aberto (`adhoc`). Carrega no `did-insert` e no `did-update` de id, status e `updated_at` (como o do iFood). Com a fase `ofertas`, relê a cada 5 s (a fila anda sem mudar o pedido) e o cronômetro conta de segundo em segundo.

- [ ] **Step 1: Componente**

```js
import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task, timeout } from 'ember-concurrency';
import { chaveDaFase, chaveDaResposta, chaveDoMotivo, minutos, podeAbrir, segundosRestantes } from '../../../utils/distribuicao';

/**
 * Entregas: painel "Distribuição" no detalhe do pedido aberto (oferta um a um aos motoboys; rota GET
 * int/v1/entregas/pedidos/{id}/distribuicao, só administradores: para os demais o painel nem aparece, nem carrega).
 * Mostra a fase, a oferta atual com o cronômetro, a fila calculada (tempo até o cliente, encaixe, ≈ quando a estimativa
 * é em linha reta) e o histórico das ofertas. "Abrir a todos agora" (POST .../distribuicao/abrir) pula a fila.
 * Em fase ofertas, relê a cada 5 s: a fila anda sem mudar o pedido.
 */
export default class OrderDetailsDistribuicaoComponent extends Component {
    @service fetch;
    @service currentUser;
    @service intl;
    @service notifications;
    @service modalsManager;
    @tracked painel = null;
    @tracked erro = false;
    @tracked agora = Date.now();
    ultimoId = null;

    get mostrar() {
        return this.args.resource?.adhoc === true && this.currentUser.isAdmin === true;
    }

    get id() {
        return encodeURIComponent(this.args.resource?.public_id ?? this.args.resource?.id ?? '');
    }

    get temDistribuicao() {
        return this.painel?.distribuicao === true;
    }

    get faseTexto() {
        const fase = this.intl.t(`fleet-ops.ui.distribuicao.fase.${chaveDaFase(this.painel?.fase)}`);
        const motivo = chaveDoMotivo(this.painel?.motivo);

        return motivo ? `${fase} · ${this.intl.t(`fleet-ops.ui.distribuicao.motivo.${motivo}`)}` : fase;
    }

    get segundos() {
        return segundosRestantes(this.painel?.oferta?.vence_em, this.agora);
    }

    get fila() {
        return (this.painel?.fila ?? []).map((item) => ({
            nome: item.nome,
            minutos: minutos(item.tempo_s),
            encaixe: item.encaixe === true,
            aproximado: item.aproximado === true,
            livre: item.livre === true,
        }));
    }

    get historico() {
        return (this.painel?.historico ?? []).map((oferta) => ({
            posicao: oferta.posicao,
            motoboy: oferta.motoboy,
            minutos: minutos(oferta.tempo_estimado_s),
            resposta: this.intl.t(`fleet-ops.ui.distribuicao.resposta.${chaveDaResposta(oferta.resposta)}`),
        }));
    }

    get podeAbrir() {
        return podeAbrir(this.painel, this.args.resource?.status);
    }

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
            this.painel = yield this.fetch.get(`entregas/pedidos/${this.id}/distribuicao`);
            this.erro = false;
        } catch (error) {
            this.erro = true;
            return;
        }
        // em ofertas: cronômetro a cada segundo e releitura a cada 5 s, enquanto o painel estiver na tela
        let passos = 0;
        while (this.painel?.fase === 'ofertas') {
            yield timeout(1000);
            this.agora = Date.now();
            passos += 1;
            if (passos % 5 === 0) {
                try {
                    this.painel = yield this.fetch.get(`entregas/pedidos/${this.id}/distribuicao`);
                } catch (error) {
                    // segue com o que tem; a próxima volta tenta de novo
                }
            }
        }
    }

    @action abrirATodos() {
        this.modalsManager.confirm({
            title: this.intl.t('fleet-ops.ui.distribuicao.abrir-titulo'),
            body: this.intl.t('fleet-ops.ui.distribuicao.abrir-texto'),
            acceptButtonText: this.intl.t('fleet-ops.ui.distribuicao.abrir'),
            acceptButtonIcon: 'bullhorn',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    this.painel = await this.fetch.post(`entregas/pedidos/${this.id}/distribuicao/abrir`);
                    this.notifications.success(this.intl.t('fleet-ops.ui.distribuicao.aberto'));
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }
}
```

O `restartable` cancela o laço anterior quando o pedido muda ou o componente sai da tela (o ember-concurrency cancela as tasks no `willDestroy`).

- [ ] **Step 2: Template**

```hbs
{{#if this.mostrar}}
    <div {{did-insert this.recarregar}} {{did-update this.recarregar @resource.id @resource.status @resource.updated_at}}>
        <ContentPanel @title={{t "fleet-ops.ui.distribuicao.painel-titulo"}} @isLoading={{or @isLoading (and this.carregar.isRunning (not this.painel))}} @open={{true}} @wrapperClass="bordered-top">
            {{#if this.erro}}
                <p class="text-xs text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.distribuicao.erro"}}</p>
            {{else if this.temDistribuicao}}
                <div class="field-info-container field-vertical-container dashed-bottom">
                    <div class="field-name">{{t "fleet-ops.ui.distribuicao.situacao"}}</div>
                    <div class="field-value">{{this.faseTexto}}</div>
                </div>
                {{#if this.painel.oferta}}
                    <div class="field-info-container field-vertical-container dashed-bottom">
                        <div class="field-name">{{t "fleet-ops.ui.distribuicao.oferta-atual"}}</div>
                        <div class="field-value">{{t "fleet-ops.ui.distribuicao.oferta-texto" motoboy=this.painel.oferta.motoboy segundos=this.segundos}}</div>
                    </div>
                {{/if}}
                {{#if this.fila.length}}
                    <div class="field-info-container field-vertical-container dashed-bottom">
                        <div class="field-name">{{t "fleet-ops.ui.distribuicao.fila"}}</div>
                        <div class="field-value">
                            {{#each this.fila as |item index|}}
                                <div class="text-xs">
                                    {{add index 1}}. {{item.nome}} · {{if item.aproximado "≈"}}{{t "fleet-ops.ui.distribuicao.minutos" minutos=item.minutos}}
                                    {{#if item.encaixe}}· {{t "fleet-ops.ui.distribuicao.encaixe"}}{{/if}}
                                    {{#unless item.livre}}· {{t "fleet-ops.ui.distribuicao.ocupado"}}{{/unless}}
                                </div>
                            {{/each}}
                        </div>
                    </div>
                {{/if}}
                {{#if this.historico.length}}
                    <div class="field-info-container field-vertical-container dashed-bottom">
                        <div class="field-name">{{t "fleet-ops.ui.distribuicao.historico"}}</div>
                        <div class="field-value">
                            {{#each this.historico as |oferta|}}
                                <div class="text-xs">{{oferta.posicao}}. {{oferta.motoboy}} · {{t "fleet-ops.ui.distribuicao.minutos" minutos=oferta.minutos}} · {{oferta.resposta}}</div>
                            {{/each}}
                        </div>
                    </div>
                {{/if}}
                {{#if this.podeAbrir}}
                    <div class="mt-2">
                        <Button @type="warning" @size="xs" @icon="bullhorn" @text={{t "fleet-ops.ui.distribuicao.abrir"}} @onClick={{this.abrirATodos}} @permission="fleet-ops update order" />
                    </div>
                {{/if}}
            {{else if this.painel}}
                <p class="text-xs text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.distribuicao.sem-distribuicao"}}</p>
            {{/if}}
        </ContentPanel>
    </div>
{{/if}}
```

Confira se os helpers `add`, `and` e `not` existem no console (`ember-math-helpers`/`ember-truth-helpers`): `grep -rn "{{add \|(not " packages/fleetops/addon/components | head`. Se `add` não existir, mostre `oferta.posicao` na fila também (acrescente `posicao: index + 1` no getter `fila`).

- [ ] **Step 3: Re-export e uso**

`packages/fleetops/app/components/order/details/distribuicao.js`:

```js
export { default } from '@fleetbase/fleetops-engine/components/order/details/distribuicao';
```

Em `packages/fleetops/addon/components/order/details.hbs`, logo depois da linha `<Order::Details::Ifood @resource={{@resource}} @isLoading={{@isLoading}} />`:

```hbs
        {{!-- Entregas: painel da distribuição (oferta um a um; só pedidos abertos e administradores) --}}
        <Order::Details::Distribuicao @resource={{@resource}} @isLoading={{@isLoading}} />
```

- [ ] **Step 4: Traduções**

Em `packages/fleetops/translations/pt-br.yaml`, dentro de `ui:` (no mesmo nível de `ifood:`, 4 espaços antes da chave):

```yaml
    distribuicao:
      painel-titulo: Distribuição
      erro: Não foi possível carregar a distribuição (só administradores veem este painel).
      sem-distribuicao: Este pedido não passou pela oferta um a um.
      situacao: Situação
      oferta-atual: Oferta atual
      oferta-texto: '{motoboy} · fecha em {segundos} s'
      fila: Fila (tempo até o cliente)
      historico: Ofertas feitas
      minutos: '{minutos} min'
      encaixe: no caminho
      ocupado: já leva pedido
      abrir: Abrir a todos agora
      abrir-titulo: Abrir o pedido a todos os motoboys?
      abrir-texto: O pedido deixa de ser oferecido um a um e o alarme vai a todos os motoboys online perto da loja. O primeiro que aceitar leva.
      aberto: Pedido aberto a todos os motoboys.
      fase:
        ofertas: Oferecendo um a um
        aberta: Aberto a todos
        encerrada: Encerrada
        desconhecida: Situação desconhecida
      motivo:
        aceita: aceito pelo motoboy da oferta
        atribuida: motoboy definido
        cancelada: pedido encerrado
        aberta_pela_central: aberto pela central
        fila_esgotada: nenhum motoboy aceitou a oferta
        prazo: passaram 3 min sem aceite
        sem_candidato: nenhum motoboy disponível no raio
        redespachada: pedido despachado de novo
        desconhecido: motivo desconhecido
      resposta:
        pendente: aguardando
        aceita: aceitou
        recusada: recusou
        vencida: não respondeu
        cancelada: cancelada
        desconhecida: desconhecida
```

Em `en-us.yaml`, no mesmo lugar (8 espaços antes da chave):

```yaml
        distribuicao:
            painel-titulo: Dispatch
            erro: Could not load the dispatch (only administrators see this panel).
            sem-distribuicao: This order was not offered one driver at a time.
            situacao: Status
            oferta-atual: Current offer
            oferta-texto: '{motoboy} · closes in {segundos} s'
            fila: Queue (time to customer)
            historico: Offers made
            minutos: '{minutos} min'
            encaixe: on the way
            ocupado: already carrying an order
            abrir: Open to everyone now
            abrir-titulo: Open the order to all drivers?
            abrir-texto: The order stops being offered one at a time and the alarm goes to every online driver near the store. The first to accept takes it.
            aberto: Order opened to all drivers.
            fase:
                ofertas: Offering one at a time
                aberta: Open to everyone
                encerrada: Closed
                desconhecida: Unknown status
            motivo:
                aceita: accepted by the offered driver
                atribuida: driver assigned
                cancelada: order closed
                aberta_pela_central: opened by dispatch
                fila_esgotada: no driver accepted the offer
                prazo: 3 min without acceptance
                sem_candidato: no driver available in range
                redespachada: order dispatched again
                desconhecido: unknown reason
            resposta:
                pendente: waiting
                aceita: accepted
                recusada: declined
                vencida: no answer
                cancelada: canceled
                desconhecida: unknown
```

- [ ] **Step 5: Validação**

Run: `node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal` → exit 0.
Run (parse do JS novo, com o `@babel/parser` do console):

```bash
node -e "const fs=require('fs');const d=fs.readdirSync('console/node_modules/.pnpm').find(n=>n.startsWith('@babel+parser@7'));const p=require('./console/node_modules/.pnpm/'+d+'/node_modules/@babel/parser');for(const f of ['packages/fleetops/addon/utils/distribuicao.js','packages/fleetops/addon/components/order/details/distribuicao.js']){p.parse(fs.readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties']});console.log('ok',f)}"
```

Expected: `ok` para os dois.

- [ ] **Step 6: Conferir no navegador (build local)**

Siga "Build do console" do CLAUDE.md (build local contra a API de produção, já com o plano da API em produção e a chave ligada): abra um pedido aberto da loja de teste e confira o painel em fase ofertas (cronômetro andando, fila), o "Abrir a todos agora" e o painel depois do aceite (encerrada · aceito pelo motoboy da oferta, histórico). Se a API ainda não estiver em produção, pule este passo e anote no relatório final.

- [ ] **Step 7: Commit**

```bash
git add packages/fleetops/addon/components/order/details/distribuicao.js packages/fleetops/addon/components/order/details/distribuicao.hbs packages/fleetops/app/components/order/details/distribuicao.js packages/fleetops/addon/components/order/details.hbs packages/fleetops/translations/pt-br.yaml packages/fleetops/translations/en-us.yaml
git commit -m "Distribuição (console): painel no detalhe do pedido com Abrir a todos agora"
```

---

### Task 3: App: funções puras da oferta

Repo: `C:\Users\Edgardjr\Documents\vibe coding\entregas-navigator`. Antes: `git checkout main && git pull && git checkout -b distribuicao-de-pedidos`.

**Files:**
- Create: `src/utils/oferta.ts`
- Test: `scripts/testes/oferta.teste.ts`

A lista vem do `GET v1/orders?nearby…&adhoc=1&unassigned=1`; com a distribuição ligada, o pedido oferecido ao motoboy traz `entregas_oferta: {vence_em, tempo_estimado_s}`. Os pedidos chegam como recurso do SDK (`getAttribute`) ou serializados (objeto comum), como em `lista-de-pedidos.ts`.

- [ ] **Step 1: Teste**

```ts
// Testes das funções puras da oferta um a um (src/utils/oferta.ts), com o Node puro:
//   node --experimental-strip-types --test scripts/testes/oferta.teste.ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ofertaDoPedido, ehOferta, segundosRestantes, comOfertaPrimeiro, recusarPeloAlarme } from '../../src/utils/oferta.ts';

const recurso = (atributos: Record<string, any>) => ({
    id: atributos.id,
    getAttribute: (caminho: string) => caminho.split('.').reduce((atual: any, chave) => (atual == null ? undefined : atual[chave]), atributos),
});

test('oferta do pedido: recurso do SDK ou objeto', () => {
    assert.deepEqual(ofertaDoPedido(recurso({ id: 'order_1', entregas_oferta: { vence_em: '2026-10-07T10:00:30-03:00', tempo_estimado_s: 400 } })), { venceEm: '2026-10-07T10:00:30-03:00', tempoEstimadoS: 400 });
    assert.deepEqual(ofertaDoPedido({ id: 'order_1', entregas_oferta: { vence_em: '2026-10-07T10:00:30-03:00' } }), { venceEm: '2026-10-07T10:00:30-03:00', tempoEstimadoS: null });
    assert.equal(ofertaDoPedido({ id: 'order_1' }), null);
    assert.equal(ofertaDoPedido({ id: 'order_1', entregas_oferta: { vence_em: '' } }), null);
    assert.equal(ofertaDoPedido(null), null);
    assert.equal(ehOferta({ entregas_oferta: { vence_em: '2026-10-07T10:00:30-03:00' } }), true);
    assert.equal(ehOferta({}), false);
});

test('segundos restantes', () => {
    const agora = Date.parse('2026-10-07T13:00:00Z');
    assert.equal(segundosRestantes('2026-10-07T10:00:30-03:00', agora), 30);
    assert.equal(segundosRestantes('2026-10-07T10:00:00.200-03:00', agora), 1);
    assert.equal(segundosRestantes('2026-10-07T09:00:00-03:00', agora), 0);
    assert.equal(segundosRestantes('lixo', agora), 0);
    assert.equal(segundosRestantes(null, agora), 0);
});

test('a oferta vai para o topo de "Novos pedidos", o resto na ordem', () => {
    const a = { id: 'a' };
    const b = { id: 'b', entregas_oferta: { vence_em: '2026-10-07T10:00:30-03:00' } };
    const c = { id: 'c' };
    assert.deepEqual(comOfertaPrimeiro([a, b, c]).map((p) => p.id), ['b', 'a', 'c']);
    assert.deepEqual(comOfertaPrimeiro([a, c]).map((p) => p.id), ['a', 'c']);
    assert.deepEqual(comOfertaPrimeiro([]), []);
});

test('recusar pelo alarme: só pedido aberto com o Recusar do cartão de oferta', () => {
    assert.equal(recusarPeloAlarme({ type: 'order_ping', id: 'order_1', entregas_recusar: '1' }), true);
    assert.equal(recusarPeloAlarme({ type: 'order_ping', id: 'order_1' }), false);
    assert.equal(recusarPeloAlarme({ type: 'order_assigned', id: 'order_1', entregas_recusar: '1' }), false);
    assert.equal(recusarPeloAlarme(null), false);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `node --experimental-strip-types --test scripts/testes/oferta.teste.ts`
Expected: FAIL (módulo não encontrado).

- [ ] **Step 3: Implementar**

```ts
// Entregas: a oferta um a um (distribuição de pedidos abertos no servidor). O pedido oferecido ao motoboy chega na lista
// "Novos pedidos" com `entregas_oferta: {vence_em, tempo_estimado_s}` e, no push, com entregas_oferta=1 e
// entregas_oferta_vence_em. Sem imports, para os testes rodarem com o Node puro:
//   node --experimental-strip-types --test scripts/testes/oferta.teste.ts

export type Oferta = { venceEm: string; tempoEstimadoS: number | null };

const ler = (pedido: any, caminho: string): any => {
    if (!pedido) return undefined;
    if (typeof pedido.getAttribute === 'function') return pedido.getAttribute(caminho);
    return caminho.split('.').reduce((atual: any, chave) => (atual === null || atual === undefined ? undefined : atual[chave]), pedido);
};

/** A oferta do pedido (o servidor só manda para o motoboy que a tem), ou null. */
export function ofertaDoPedido(pedido: any): Oferta | null {
    const venceEm = ler(pedido, 'entregas_oferta.vence_em');
    if (typeof venceEm !== 'string' || venceEm.trim() === '') return null;
    const tempo = Number(ler(pedido, 'entregas_oferta.tempo_estimado_s'));
    return { venceEm, tempoEstimadoS: Number.isFinite(tempo) && ler(pedido, 'entregas_oferta.tempo_estimado_s') != null ? tempo : null };
}

export const ehOferta = (pedido: any): boolean => ofertaDoPedido(pedido) !== null;

/** Segundos até a oferta vencer (arredonda para cima; 0 se venceu ou data inválida). */
export function segundosRestantes(venceEm: string | null | undefined, agora: number = Date.now()): number {
    const fim = Date.parse(venceEm ?? '');
    if (!Number.isFinite(fim)) return 0;
    return Math.max(0, Math.ceil((fim - agora) / 1000));
}

/** "Novos pedidos" com a oferta dele no topo; os outros (abertos a todos) na ordem que vieram. */
export function comOfertaPrimeiro<T>(pedidos: T[]): T[] {
    const lista = pedidos ?? [];
    return [...lista.filter((p) => ehOferta(p)), ...lista.filter((p) => !ehOferta(p))];
}

/** O motoboy tocou em Recusar no cartão da oferta (AlarmePedidoActivity põe entregas_recusar=1 nos dados que abrem o app). */
export const recusarPeloAlarme = (dados: Record<string, unknown> | null | undefined): boolean =>
    dados?.type === 'order_ping' && dados?.entregas_recusar === '1';
```

- [ ] **Step 4: Rodar e ver passar**

Run: `node --experimental-strip-types --test scripts/testes/oferta.teste.ts`
Expected: PASS (4 testes).

- [ ] **Step 5: Commit**

```bash
git add src/utils/oferta.ts scripts/testes/oferta.teste.ts
git commit -m "Oferta um a um: funções puras (prazo, ordem da lista, recusa pelo alarme)"
```

---

### Task 4: App: a lista com a oferta no topo e o card com cronômetro e Recusar

**Files:**
- Modify: `src/utils/lista-de-pedidos.ts` (`secoesDaLista`)
- Modify: `scripts/testes/lista-de-pedidos.teste.ts`
- Modify: `src/components/AdhocOrderCard.tsx`
- Modify: `src/screens/DriverOrderManagementScreen.tsx`
- Modify: `translations/pt.json`, `translations/en.json`

- [ ] **Step 1: Teste da lista (acrescente a `lista-de-pedidos.teste.ts`)**

```ts
test('seções: a oferta dele vem primeiro em "novos"', () => {
    const aberto = { id: 'order_a', adhoc: true, driver_assigned: null };
    const oferta = { id: 'order_b', adhoc: true, driver_assigned: null, entregas_oferta: { vence_em: '2026-10-07T10:00:30-03:00' } };
    assert.deepEqual(secoesDaLista([aberto, oferta], []), [{ chave: 'novos', data: [oferta, aberto] }]);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `node --experimental-strip-types --test scripts/testes/lista-de-pedidos.teste.ts`
Expected: FAIL no caso novo.

- [ ] **Step 3: `secoesDaLista`**

Em `src/utils/lista-de-pedidos.ts`, como o arquivo não pode ter imports, copie a regra (curta) em vez de importar de `oferta.ts`:

```ts
/** A oferta um a um (ver oferta.ts): o servidor põe entregas_oferta só no pedido oferecido a este motoboy. */
const temOferta = (pedido: any): boolean => {
    const venceEm = ler(pedido, 'entregas_oferta.vence_em');
    return typeof venceEm === 'string' && venceEm.trim() !== '';
};
```

e, em `secoesDaLista`, troque a linha do `novos` por:

```ts
    const abertos = (proximos ?? []).filter((pedido) => ehPedidoAberto(pedido) && !dispensados.includes(idDe(pedido)) && !meus.has(idDe(pedido)));
    const novos = [...abertos.filter(temOferta), ...abertos.filter((pedido) => !temOferta(pedido))];
```

Atualize o comentário do topo do arquivo ("a oferta dele, com cronômetro, vem primeiro").

- [ ] **Step 4: Rodar e ver passar**

Run: `node --experimental-strip-types --test scripts/testes/lista-de-pedidos.teste.ts` → PASS.

- [ ] **Step 5: `AdhocOrderCard`: cronômetro e Recusar pelo servidor**

No topo:

```tsx
import { ofertaDoPedido, segundosRestantes } from '../utils/oferta';
```

No corpo do componente, depois dos hooks atuais:

```tsx
    // oferta um a um: o servidor manda entregas_oferta só no pedido oferecido a este motoboy
    const oferta = ofertaDoPedido(order);
    const [segundos, setSegundos] = useState(() => (oferta ? segundosRestantes(oferta.venceEm) : 0));
    const [isRefusing, setIsRefusing] = useState(false);
    useEffect(() => {
        if (!oferta) return;
        setSegundos(segundosRestantes(oferta.venceEm));
        const intervalo = setInterval(() => {
            const restante = segundosRestantes(oferta.venceEm);
            setSegundos(restante);
            if (restante <= 0) {
                clearInterval(intervalo);
                // venceu: o servidor já passou ao próximo; a lista recarrega e o pedido some
                reloadNearbyOrders({}, { setLoadingFlag: false });
            }
        }, 1000);
        return () => clearInterval(intervalo);
    }, [oferta?.venceEm]);

    const recusarOferta = async () => {
        setIsRefusing(true);
        try {
            await adapter.post(`entregas/motoboy/pedidos/${order.id}/recusar`);
        } catch (err) {
            // 409 (a oferta já não estava com ele) também tira o card: a lista recarrega
            console.warn('[oferta] recusar:', err);
        } finally {
            setIsRefusing(false);
            onDismiss?.(order);
            reloadNearbyOrders({}, { setLoadingFlag: false });
        }
    };
```

Troque o `handleDismiss` para, com oferta, recusar direto (sem o Alert, porque o tempo corre):

```tsx
    const handleDismiss = () => {
        if (oferta) {
            recusarOferta();
            return;
        }
        Alert.alert(t('AdhocOrderCard.dismissTitle'), t('AdhocOrderCard.dismissMessage'), [
            { text: t('common.cancel'), style: 'cancel' },
            { text: t('common.ok'), onPress: () => onDismiss?.(order) },
        ]);
    };
```

(mantenha o texto e os botões do Alert que já existem; o trecho acima só mostra a ordem). No `handleAccept`, com oferta, também pule o `Alert.alert` de confirmação e chame o aceite direto.

No JSX, no cabeçalho azul (`XStack bg='$blue-800'`), troque o texto por:

```tsx
<Text color='white' fontWeight='bold'>
    {oferta ? t('AdhocOrderCard.ofertaParaVoce', { segundos }) : t('AdhocOrderCard.availableNearby', { distance: formatMeters(distance) })}
</Text>
```

(preserve o componente de texto e as props que o cabeçalho usa hoje; só troque o conteúdo). No botão de dispensar, com oferta, o rótulo vira `t('AdhocOrderCard.recusarOferta')` e `disabled={isAccepting || isRefusing}`. O `LoadingOverlay` passa a `isVisible={isAccepting || isRefusing}`.

- [ ] **Step 6: Traduções do app**

`translations/pt.json`, dentro de `"AdhocOrderCard"`:

```json
        "ofertaParaVoce": "Oferta para você · fecha em {{segundos}} s",
        "recusarOferta": "Recusar"
```

`translations/en.json`, no mesmo lugar:

```json
        "ofertaParaVoce": "Offer for you · closes in {{segundos}} s",
        "recusarOferta": "Decline"
```

(cuide das vírgulas do JSON: a chave anterior, `dismissOrder`, ganha vírgula).

- [ ] **Step 7: `DriverOrderManagementScreen`: recarregar mais rápido com oferta na tela**

A lista recarrega a cada 30 s (`REFRESH_NEARBY_ORDERS_MS`). Com a oferta, a lista também recarrega quando chega o push `order.ping` no socket (já existe) e quando o cronômetro do card vence (passo 5). Nada a mudar aqui além de conferir que o `handleAdhocDismissal` recebe o pedido recusado (o card chama `onDismiss`). Se a seção "novos" usar o título `novosPedidos`, mantenha.

- [ ] **Step 8: Testes e tipos**

Run: `node --experimental-strip-types --test scripts/testes/oferta.teste.ts scripts/testes/lista-de-pedidos.teste.ts` → PASS.
Run: `npx tsc --noEmit -p .` (se o projeto tiver `tsconfig.json`; se a verificação de tipos do repo já falha antes desta mudança, compare a contagem de erros antes e depois: não pode aumentar).

- [ ] **Step 9: Commit**

```bash
git add src/utils/lista-de-pedidos.ts scripts/testes/lista-de-pedidos.teste.ts src/components/AdhocOrderCard.tsx translations/pt.json translations/en.json
git commit -m "Oferta um a um: a oferta no topo da lista, com cronômetro e Recusar pelo servidor"
```

---

### Task 5: App: Recusar no cartão do alarme (Kotlin + JS)

**Files:**
- Modify: `android/app/src/main/java/io/fleetbase/navigator/AlarmeDePedido.kt`
- Modify: `android/app/src/main/java/io/fleetbase/navigator/AlarmePedidoActivity.kt`
- Modify: `android/app/src/main/res/values/entregas_strings.xml`
- Modify: `src/layouts/DriverLayout.tsx` (`handlePushNotification`)

O push da oferta traz `entregas_oferta=1` e `entregas_oferta_vence_em` (ISO com fuso, ex. `2026-10-07T10:00:30-03:00`). No cartão: o cronômetro conta até `vence_em` (não 3 min), a notificação some no mesmo prazo, o título vira "Oferta para você", e Recusar abre o app com `entregas_recusar=1` (o JS chama o servidor) além de gravar a recusa local de 2 h.

- [ ] **Step 1: `AlarmeDePedido.kt`**

Acrescente a constante e duas funções:

```kotlin
  const val EXTRA_RECUSAR = "entregas_recusar"

  /** Oferta um a um (distribuição no servidor): o push traz entregas_oferta=1 e entregas_oferta_vence_em. */
  fun ehOferta(dados: Bundle): Boolean = dados.getString("entregas_oferta") == "1"

  /**
   * Milissegundos até a oferta vencer (entregas_oferta_vence_em, ISO com fuso), ou DURACAO_MS se não é oferta ou a data
   * não se lê. Nunca menos de 1 s (o Android ignora timeout 0).
   */
  fun duracaoMs(dados: Bundle): Long {
    if (!ehOferta(dados)) return DURACAO_MS
    val texto = dados.getString("entregas_oferta_vence_em") ?: return DURACAO_MS
    return try {
      val formato = java.text.SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ssXXX", java.util.Locale.US)
      val fim = formato.parse(texto)?.time ?: return DURACAO_MS
      maxOf(1000L, fim - System.currentTimeMillis())
    } catch (e: Exception) {
      DURACAO_MS
    }
  }
```

Em `disparar`, troque `.setTimeoutAfter(DURACAO_MS)` por `.setTimeoutAfter(duracaoMs(dados))`. Em `consumirPendente` (pendente de até `DURACAO_MS`), a regra fica como está: uma oferta vencida que voltar ao abrir o app só leva ao pedido, e o servidor responde 409 se o motoboy tentar aceitar.

- [ ] **Step 2: `AlarmePedidoActivity.kt`**

Em `mostrarAlarme()`:

```kotlin
    fimDoAlarme = SystemClock.elapsedRealtime() + AlarmeDePedido.duracaoMs(dados())
```

Em `montarCartao()`, o título:

```kotlin
    val oferta = AlarmeDePedido.ehOferta(dados)
    val titulo = if (oferta) getString(R.string.alarme_oferta_titulo) else (intent.getStringExtra(AlarmeDePedido.EXTRA_TITULO) ?: getString(R.string.alarme_titulo_padrao))
```

`abrirPedido` ganha um parâmetro para a recusa:

```kotlin
  private fun abrirPedido(aceitar: Boolean, recusar: Boolean = false) {
    val abrir = Runnable {
      val dados = Bundle(dados())
      if (aceitar) dados.putString(AlarmeDePedido.EXTRA_ACEITAR, "1")
      if (recusar) dados.putString(AlarmeDePedido.EXTRA_RECUSAR, "1")
      AlarmeDePedido.cancelarTodos(this)
      AlarmeDePedido.intentDoPedido(this, dados)?.let { startActivity(it) }
      finish()
    }
    // (o resto, com o requestDismissKeyguard, fica como está)
  }
```

e `recusar()`:

```kotlin
  private fun recusar() {
    AlarmeDePedido.recusar(this, dados().getString("id"))
    // oferta um a um: o servidor precisa saber para passar ao próximo motoboy agora (senão espera os 30 s)
    if (AlarmeDePedido.ehOferta(dados())) abrirPedido(aceitar = false, recusar = true) else silenciar()
  }
```

- [ ] **Step 3: String**

Em `res/values/entregas_strings.xml`:

```xml
    <string name="alarme_oferta_titulo">Oferta para você</string>
```

- [ ] **Step 4: JS: `DriverLayout.handlePushNotification`**

No bloco `if (typeof id === 'string' && id.startsWith('order_'))`, antes de buscar o pedido e navegar para o `OrderModal`:

```tsx
            // oferta um a um: Recusar no cartão do alarme abre o app com entregas_recusar=1; avisa o servidor e não abre o pedido
            if (action === 'opened' && recusarPeloAlarme(payload)) {
                try {
                    await adapter.post(`entregas/motoboy/pedidos/${id}/recusar`);
                    toast.success(t('Oferta.recusada'));
                } catch (err) {
                    // 409: a oferta já tinha passado (venceu ou outro foi oferecido); nada a fazer
                    console.warn('[oferta] recusar pelo alarme:', err);
                }
                reloadNearbyOrders({}, { setLoadingFlag: false });
                return;
            }
```

com `import { recusarPeloAlarme } from '../utils/oferta';` e o `adapter` de `useFleetbase()` (se o `DriverLayout` ainda não o tiver, `const { adapter } = useFleetbase();`). Use o mesmo `toast` e `t` que o arquivo já usa.

`translations/pt.json` (bloco novo no fim do objeto raiz, com vírgula no bloco anterior):

```json
    "Oferta": {
        "recusada": "Oferta recusada."
    }
```

`translations/en.json`:

```json
    "Oferta": {
        "recusada": "Offer declined."
    }
```

- [ ] **Step 5: Testes JS e commit**

Run: `node --experimental-strip-types --test scripts/testes/oferta.teste.ts scripts/testes/alarme-do-pedido.teste.ts` → PASS.

```bash
git add android/app/src/main/java/io/fleetbase/navigator/AlarmeDePedido.kt android/app/src/main/java/io/fleetbase/navigator/AlarmePedidoActivity.kt android/app/src/main/res/values/entregas_strings.xml src/layouts/DriverLayout.tsx translations/pt.json translations/en.json
git commit -m "Oferta um a um: cartão do alarme com o prazo da oferta e Recusar que avisa o servidor"
```

- [ ] **Step 6: Build no Actions**

O Kotlin só compila no GitHub Actions. **Não faça push sem o Edgard pedir** (o push na `main` gera o APK; num ramo, confira se o workflow roda em ramos). Peça a ele para subir o ramo e conferir o artifact `entregas-motoboy-<n>`.

---

### Task 6: Documentação e teste real

**Files:**
- Modify: `CLAUDE.md` (Delivery), na seção "Distribuição de pedidos abertos" criada pelo plano da API
- Modify: `C:\Users\Edgardjr\.claude\projects\C--Users-Edgardjr-Documents-vibe-coding-Delivery\memory\` (memória do projeto)

- [ ] **Step 1: CLAUDE.md**

Acrescente à seção "Distribuição de pedidos abertos":

```markdown
- **Console:** painel "Distribuição" no detalhe do pedido aberto (`order/details/distribuicao`, só admin; funções puras em `utils/distribuicao.js`, teste `scripts/teste-portal/distribuicao.test.mjs`): fase e motivo, oferta atual com cronômetro, fila (tempo até o cliente, "no caminho", "já leva pedido", "≈" na linha reta), histórico e "Abrir a todos agora". Em fase ofertas relê a cada 5 s.
- **App** (APK do ramo `distribuicao-de-pedidos` do `entregas-navigator`): o card de aceitar mostra "Oferta para você · fecha em N s" e Recusar sem confirmação (`POST .../recusar`); a oferta vem no topo de "Novos pedidos" (`src/utils/oferta.ts`, teste `scripts/testes/oferta.teste.ts`). Cartão do alarme: prazo de `entregas_oferta_vence_em` no cronômetro e na notificação, título "Oferta para você"; Recusar grava a recusa local e abre o app com `entregas_recusar=1`, e o `DriverLayout` chama a rota de recusa (o Kotlin não tem o token nem cliente HTTP). Com o celular bloqueado, Recusar pede o desbloqueio; sem desbloquear, a oferta vence em 30 s. APK antigo: o card mostra 3 min e o Recusar só cala; a oferta vence sozinha.
```

- [ ] **Step 2: Teste real (com o Edgard, depois do deploy da API com a chave ligada e do APK novo em dois celulares)**

1. Dois motoboys online perto da loja de teste (Terraço Pizza Bar), um mais perto.
2. Pedido pelo portal da loja de teste. Conferir: só o mais perto recebe o alarme, com "Oferta para você" e 30 s; o outro não vê o pedido em "Novos pedidos".
3. Recusar no cartão (celular desbloqueado): o app abre, mostra "Oferta recusada." e, em segundos, o segundo motoboy recebe a oferta.
4. Deixar vencer no segundo: o pedido abre a todos (os dois recebem o alarme comum).
5. No console, o painel mostra a fila, o histórico (recusou, não respondeu) e "Aberto a todos · nenhum motoboy aceitou a oferta".
6. Repetir com aceite no primeiro: painel "Encerrada · aceito pelo motoboy da oferta".
7. Com um motoboy já levando um pedido para perto da loja: conferir na fila do painel "no caminho" / "já leva pedido" e se a ordem faz sentido.

- [ ] **Step 3: Commit (Delivery)**

```bash
git add CLAUDE.md
git commit -m "Distribuição: documentação do painel do console e do APK"
```

---

## Auto-revisão do plano (feita ao escrever)

- **Cobertura do spec:** seção 6 (app) → Tasks 3, 4, 5; seção 7 (console) → Tasks 1, 2; teste real → Task 6.
- **Nomes consistentes com o plano da API:** rotas `entregas/pedidos/{id}/distribuicao`, `.../distribuicao/abrir`, `entregas/motoboy/pedidos/{id}/recusar`; campos do painel `distribuicao`, `fase`, `motivo`, `oferta.{motoboy, vence_em}`, `fila[].{nome, tempo_s, encaixe, aproximado, livre}`, `historico[].{posicao, motoboy, tempo_estimado_s, resposta}`; campo da lista `entregas_oferta.{vence_em, tempo_estimado_s}`; dados do push `entregas_oferta`, `entregas_oferta_vence_em`.
- **Desvio do spec:** o Recusar do cartão nativo abre o app (o JS chama o servidor), registrado no topo.
