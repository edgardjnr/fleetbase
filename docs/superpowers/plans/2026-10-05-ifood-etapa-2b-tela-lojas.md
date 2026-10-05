# iFood etapa 2B (console): vínculo na tela Lojas

> **Situação (2026-10-05):** executado no ramo `ifood-etapa-2` (Tasks 1 a 4; na Task 5, o build e o teste manual
> ficam para depois do deploy, e a documentação entrou no `CLAUDE.md` junto com a do 2A, seção "Integração iFood"), com
> ajustes de revisão. **O código real é a referência.** Principais divergências:
> - `limparCodigo` tira todo espaço em branco, inclusive o do meio e as quebras de linha;
> - `linkSeguro` recusa também URL com usuário, senha ou porta;
> - `segundosRestantes` trata valor não finito (NaN) como vencido;
> - o modal relê a lista de lojas ao fechar (`onFinish`) e, depois de um erro ao vincular ou escolher, oferece "Gerar
>   outro código" também na escolha e com o código ainda válido;
> - o selo "Vinculada" cai no `merchant_id` quando a loja do iFood não tem nome;
> - o contrato abaixo foi completado com o 422 do pedido de código e os 409/502 do vínculo.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Depende do plano 2A** (`docs/superpowers/plans/2026-10-05-ifood-etapa-2a-servidor.md`): os endpoints
> `int/v1/entregas/lojas/{id}/ifood/*`, o bloco `ifood` em cada loja e o `ifood_ligado` da lista (Task 11 do 2A). As
> Tasks 1 a 4 daqui só precisam do contrato abaixo; o teste manual (Task 5) precisa do 2A implantado na API com
> `ENTREGAS_IFOOD=1`.

**Goal:** na tela Fleet-Ops → Recursos → Lojas (só admin), cada card mostra a situação do iFood ("Vinculada · <loja no
iFood>", "Vínculo perdido" em vermelho ou "—") e, com a integração ligada, os botões "Vincular iFood" (modal em dois
passos: código de vínculo grande + link do Portal do Parceiro + contagem de 10 min; campo do código de autorização;
escolha da loja do iFood quando a conta tem várias) e "Desvincular iFood" (com confirmação).

**Architecture:** funções puras em `packages/fleetops/addon/utils/vinculo-ifood.js` (selo, contagem, link seguro),
testadas com `node --test`; o modal `modals/vincular-ifood` (componente Glimmer com tasks do ember-concurrency, os
botões no corpo e só "Fechar" no rodapé), aberto pelo controller da tela com `modalsManager.show`; o desvínculo por
`modalsManager.confirm`. Textos em `fleet-ops.ui.lojas.ifood.*` (pt-br e en-us).

**Tech Stack:** Ember (engine `packages/fleetops/addon`), ember-concurrency, ember-intl, `node --test`
(`scripts/teste-portal`).

## Contrato do servidor (plano 2A, Task 11)

Todas em `int/v1/entregas` (o `fetch` do console já usa esse namespace com `ENDPOINT = 'entregas/lojas'`), só admin
(403 para quem não é); loja de outra empresa ou Fornecedor que não é loja = 404. Todo erro do `IfoodLojasController`
sai no formato `{"errors": ["…"]}` (inclusive a validação, que não usa o 422 padrão do Laravel), que o
`notifications.serverError` mostra. Código e vínculo têm o limitador `entregas-ifood-vinculo` (20 por minuto por
usuário).

| Rota | Corpo | Resposta |
|---|---|---|
| `GET lojas` | — | `{lojas: [{id, nome, …, ifood: {situacao, nome, merchant_id}}], ifood_ligado: bool}` |
| `POST lojas/{id}/ifood/codigo` | — | `{codigo: "ABCD-EFGH", link: "https://portal.ifood.com.br/apps/code?c=ABCD-EFGH", expira_em_segundos: 600}`; 422 o iFood recusou o pedido do código (400/401/403: credenciais do app erradas ou app sem o fluxo distribuído); 409 desligada; 502 iFood fora (rede, 429, 5xx, resposta sem `userCode`) |
| `POST lojas/{id}/ifood/vincular` | `{authorizationCode}` ou `{merchant_id}` | `{loja}` ou `{escolher: [{id, nome}]}`; 422 com a mensagem (validação, código ausente, vencido ou recusado, lista de lojas recusada, conta sem lojas, merchant em outra loja, escolha vencida ou fora da conta); 409 desligada; 502 iFood fora (rede, 429, 5xx: a troca já feita fica 10 min no cache e repetir não pede código novo) |
| `DELETE lojas/{id}/ifood` | — | `{loja}` (funciona também com a integração desligada) |

`ifood.situacao`: `"vinculada"`, `"vinculo_perdido"` ou `null`.

## Contexto para quem executa

- Leia o `CLAUDE.md` da raiz (seções "Tradução pt-BR" e "Portal da loja" → "Cadastro") antes de começar. Tudo em pt-BR.
- Componentes novos do engine precisam do re-export em `packages/fleetops/app/components/...`.
- Traduções: `packages/fleetops/translations/pt-br.yaml` (filhos de `ui:` com 4 espaços; o bloco `lojas:` está na linha
  157, com os filhos em 6 espaços) e `en-us.yaml` (indentação de 4 em 4: `lojas:` com 8 espaços e os filhos com 12). Nos
  dois, a última chave de `lojas` é `integration-pickup` (linha 206). **Confira com o js-yaml** (Task 2, Step 2).
- Testes de JS: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/<arquivo>.test.mjs`.
- Validação obrigatória antes de commitar (CLAUDE.md): `node scripts/i18n-check.cjs console dev-engine ember-core
  ember-ui fleetops fleetops-data iam-engine customer-portal` com exit 0, e todo JS alterado parseando com o
  `@babel/parser` do `console/node_modules/.pnpm/@babel+parser@7*`.
- Comandos em Git Bash, na raiz do repo. Confirme `git rev-parse --show-toplevel` antes de cada commit. Há mudanças de
  outras sessões no repo: `git add` só dos arquivos da task.
- Commits terminam com `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`. **Não** faça push.
- O código foi escrito e conferido (i18n-check exit 0, parse do Babel, `node --test`) antes de o plano ser publicado.

## Decisões deste plano

- **Botões no corpo do modal**: o aceite do rodapé fica escondido (`hideAcceptButton`); o rodapé só tem "Fechar". O
  modal tem três estados (código, escolha da loja, código vencido) e cada um tem o seu botão.
- **Código vencido** (contagem chega a 0:00): some o campo do código de autorização e aparece "Gerar outro código" (o
  servidor também recusaria, com 422 "venceu").
- **Link só se for https de `*.ifood.com.br`** (`linkSeguro`): o `verificationUrlComplete` vem do iFood pelo servidor,
  mas vira um `href` clicável.
- **"Vínculo perdido" mostra "Vincular de novo"** (botão primário) em vez de "Desvincular".
- **Botões só com `ifood_ligado`**; o selo aparece sempre (a situação existe mesmo com a integração desligada).
- **Desvincular pede confirmação** (`modalsManager.confirm`, botão vermelho): os pedidos da loja param de entrar.

## Arquivos

| Arquivo | Ação | Papel |
|---|---|---|
| `packages/fleetops/addon/utils/vinculo-ifood.js` | Criar | Funções puras: selo, vencimento, contagem, código colado, link seguro |
| `scripts/teste-portal/vinculo-ifood.test.mjs` | Criar | Testes do util |
| `packages/fleetops/translations/pt-br.yaml`, `en-us.yaml` | Modificar | `fleet-ops.ui.lojas.ifood.*` |
| `packages/fleetops/addon/components/modals/vincular-ifood.js` | Criar | Modal "Vincular iFood" |
| `packages/fleetops/addon/components/modals/vincular-ifood.hbs` | Criar | Template do modal |
| `packages/fleetops/app/components/modals/vincular-ifood.js` | Criar | Re-export do modal |
| `packages/fleetops/addon/controllers/management/lojas.js` | Modificar | `ifoodLigado`, selo, abrir o modal, desvincular |
| `packages/fleetops/addon/templates/management/lojas.hbs` | Modificar | Selo e botões no card |
| `CLAUDE.md` | Modificar | Tela do vínculo na seção "Integração iFood" |

---

### Task 1: funções puras do vínculo

**Files:**
- Create: `scripts/teste-portal/vinculo-ifood.test.mjs`
- Create: `packages/fleetops/addon/utils/vinculo-ifood.js`

- [ ] **Step 1: escrever o teste**

Crie `scripts/teste-portal/vinculo-ifood.test.mjs`:

```js
// Vínculo da loja com o iFood na tela Lojas (packages/fleetops/addon/utils/vinculo-ifood.js).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { contagem, limparCodigo, linkSeguro, segundosRestantes, situacaoDoIfood, vencimentoDoCodigo, VALIDADE_PADRAO_SEGUNDOS } from '../../packages/fleetops/addon/utils/vinculo-ifood.js';

test('situação do selo', () => {
    assert.equal(situacaoDoIfood({ situacao: 'vinculada', nome: 'Pizzaria', merchant_id: 'm-1' }), 'vinculada');
    assert.equal(situacaoDoIfood({ situacao: 'vinculo_perdido' }), 'perdido');
    assert.equal(situacaoDoIfood({ situacao: null }), 'nenhum');
    assert.equal(situacaoDoIfood(undefined), 'nenhum');
});

test('vencimento do código pela validade do servidor', () => {
    assert.equal(vencimentoDoCodigo(600, 1000), 601000);
    assert.equal(vencimentoDoCodigo('120', 0), 120000);
    assert.equal(vencimentoDoCodigo(undefined, 0), VALIDADE_PADRAO_SEGUNDOS * 1000);
    assert.equal(vencimentoDoCodigo(-5, 0), VALIDADE_PADRAO_SEGUNDOS * 1000);
});

test('segundos restantes nunca negativos, arredondados para cima', () => {
    assert.equal(segundosRestantes(10000, 0), 10);
    assert.equal(segundosRestantes(10000, 9001), 1);
    assert.equal(segundosRestantes(10000, 10000), 0);
    assert.equal(segundosRestantes(10000, 20000), 0);
});

test('contagem regressiva m:ss', () => {
    assert.equal(contagem(600), '10:00');
    assert.equal(contagem(545), '9:05');
    assert.equal(contagem(59), '0:59');
    assert.equal(contagem(0), '0:00');
    assert.equal(contagem(-3), '0:00');
    assert.equal(contagem('abc'), '0:00');
});

test('código de autorização colado', () => {
    assert.equal(limparCodigo('  ABCD-1234\n'), 'ABCD-1234');
    assert.equal(limparCodigo(null), '');
});

test('só link https do iFood vira botão', () => {
    assert.equal(linkSeguro('https://portal.ifood.com.br/apps/code?c=ABCD-EFGH'), 'https://portal.ifood.com.br/apps/code?c=ABCD-EFGH');
    assert.equal(linkSeguro('http://portal.ifood.com.br/apps/code'), null);
    assert.equal(linkSeguro('https://ifood.com.br.golpe.com/apps'), null);
    assert.equal(linkSeguro('javascript:alert(1)'), null);
    assert.equal(linkSeguro(''), null);
    assert.equal(linkSeguro(undefined), null);
});
```

- [ ] **Step 2: rodar e ver falhar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/vinculo-ifood.test.mjs`
Expected: falha com `ERR_MODULE_NOT_FOUND` (o util não existe).

- [ ] **Step 3: implementar**

Crie `packages/fleetops/addon/utils/vinculo-ifood.js`:

```js
/**
 * Entregas RestaurantePro: funções puras do vínculo da loja com o iFood na tela Lojas (selo do card e modal
 * `modals/vincular-ifood`). O servidor é o api/app/Http/Controllers/Entregas/IfoodLojasController.php.
 */

/** Validade do código de vínculo quando o servidor não diz (o iFood dá 10 min). */
export const VALIDADE_PADRAO_SEGUNDOS = 600;

/** Situação do selo a partir do bloco `ifood` da loja: 'vinculada', 'perdido' ou 'nenhum'. */
export function situacaoDoIfood(ifood) {
    if (ifood?.situacao === 'vinculada') {
        return 'vinculada';
    }
    if (ifood?.situacao === 'vinculo_perdido') {
        return 'perdido';
    }
    return 'nenhum';
}

/** Instante (ms) em que o código vence, a partir da validade que o servidor devolveu e de quando a resposta chegou. */
export function vencimentoDoCodigo(expiraEmSegundos, recebidoEm) {
    const segundos = Number(expiraEmSegundos);
    return recebidoEm + (Number.isFinite(segundos) && segundos > 0 ? segundos : VALIDADE_PADRAO_SEGUNDOS) * 1000;
}

/** Segundos que faltam até o vencimento (nunca negativo; arredonda para cima). */
export function segundosRestantes(vencimento, agora) {
    return Math.max(0, Math.ceil((vencimento - agora) / 1000));
}

/** Contagem regressiva "9:05", "0:59", "0:00". */
export function contagem(segundos) {
    const total = Math.max(0, Math.floor(Number(segundos) || 0));
    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
}

/** O código de autorização como foi colado, sem espaços nas pontas nem quebras de linha. */
export function limparCodigo(texto) {
    return String(texto ?? '')
        .replace(/\s+/g, ' ')
        .trim();
}

/** Só um link https do iFood vira botão (o servidor devolve o verificationUrlComplete do Portal do Parceiro). */
export function linkSeguro(link) {
    try {
        const url = new URL(String(link ?? ''));
        return url.protocol === 'https:' && /(^|\.)ifood\.com\.br$/.test(url.hostname) ? url.href : null;
    } catch {
        return null;
    }
}
```

- [ ] **Step 4: rodar e ver passar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/vinculo-ifood.test.mjs 2>&1 | grep -E "^# (pass|fail)"`
Expected: `# pass 6` e `# fail 0`.

- [ ] **Step 5: commit**

```bash
git rev-parse --show-toplevel
git add packages/fleetops/addon/utils/vinculo-ifood.js scripts/teste-portal/vinculo-ifood.test.mjs
git commit -m "Lojas: funções do vínculo com o iFood (selo, contagem do código e link do Portal do Parceiro)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: textos (pt-br e en-us)

**Files:**
- Modify: `packages/fleetops/translations/pt-br.yaml`
- Modify: `packages/fleetops/translations/en-us.yaml`

- [ ] **Step 1: acrescentar as chaves**

Em `packages/fleetops/translations/pt-br.yaml`, logo depois da linha `      integration-pickup: coleta` (a última de
`fleet-ops.ui.lojas`, 6 espaços), acrescente (o `ifood:` com 6 espaços e os filhos com 8):

```yaml
      ifood:
        label: 'iFood:'
        linked: 'Vinculada · {name}'
        lost: Vínculo perdido
        none: '—'
        link: Vincular iFood
        relink: Vincular de novo
        unlink: Desvincular iFood
        unlink-title: Desvincular do iFood?
        unlink-body: 'Os pedidos do iFood da loja {name} deixam de entrar no Entregas até um novo vínculo.'
        unlinked: Loja desvinculada do iFood.
        linked-success: Loja vinculada ao iFood.
        modal-title: 'Vincular {name} ao iFood'
        generating: Gerando o código no iFood…
        step-code: '1. Passe o código abaixo ao dono da loja. Ele abre o Portal do Parceiro do iFood pelo link (ou digita o código lá) e autoriza o Entregas RestaurantePro.'
        open-portal: Abrir o Portal do Parceiro com o código
        expires-in: 'O código vence em {time}.'
        expired: O código venceu.
        new-code: Gerar outro código
        step-authorization: '2. Depois de autorizar, o iFood mostra ao dono um código de autorização. Cole aqui:'
        authorization-placeholder: Código de autorização
        confirm: Vincular
        choose: 'A conta do iFood tem mais de uma loja. Escolha a que corresponde a {name}:'
        close: Fechar
```

Em `packages/fleetops/translations/en-us.yaml`, logo depois da linha `            integration-pickup: pickup` (12
espaços), acrescente (o `ifood:` com 12 espaços e os filhos com 16):

```yaml
            ifood:
                label: 'iFood:'
                linked: 'Linked · {name}'
                lost: Link lost
                none: '—'
                link: Link iFood
                relink: Link again
                unlink: Unlink iFood
                unlink-title: Unlink from iFood?
                unlink-body: 'iFood orders from {name} stop coming in until it is linked again.'
                unlinked: Store unlinked from iFood.
                linked-success: Store linked to iFood.
                modal-title: 'Link {name} to iFood'
                generating: Requesting the code from iFood…
                step-code: '1. Give the code below to the store owner. They open the iFood Partner Portal through the link (or type the code there) and authorize Entregas RestaurantePro.'
                open-portal: Open the Partner Portal with the code
                expires-in: 'The code expires in {time}.'
                expired: The code has expired.
                new-code: Generate another code
                step-authorization: '2. After authorizing, iFood shows the owner an authorization code. Paste it here:'
                authorization-placeholder: Authorization code
                confirm: Link
                choose: 'The iFood account has more than one store. Choose the one that matches {name}:'
                close: Close
```

- [ ] **Step 2: conferir a posição com o js-yaml**

Run:
```bash
node -e '
const y=require(require("path").resolve(require("fs").readdirSync("console/node_modules/.pnpm").filter(d=>d.startsWith("js-yaml@4")).map(d=>"console/node_modules/.pnpm/"+d+"/node_modules/js-yaml")[0]));
for (const f of ["pt-br","en-us"]) { const d=y.load(require("fs").readFileSync("packages/fleetops/translations/"+f+".yaml","utf8")); const l=d["fleet-ops"].ui.lojas; console.log(f, Object.keys(l.ifood).length, l["integration-pickup"], l.ifood.label); }'
```
Expected: `pt-br 23 coleta iFood:` e `en-us 23 pickup iFood:`.

- [ ] **Step 3: commit**

```bash
git rev-parse --show-toplevel
git add packages/fleetops/translations/pt-br.yaml packages/fleetops/translations/en-us.yaml
git commit -m "Lojas: textos do vínculo com o iFood (pt-br e en-us)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: modal "Vincular iFood"

**Files:**
- Create: `packages/fleetops/addon/components/modals/vincular-ifood.js`
- Create: `packages/fleetops/addon/components/modals/vincular-ifood.hbs`
- Create: `packages/fleetops/app/components/modals/vincular-ifood.js`

O modal recebe em `options` a `loja` (o objeto da lista) e o `onVinculado(loja)`; ao abrir, já pede o código. O relógio
(`setInterval` de 1 s) só atualiza `agora` e é parado no `willDestroy`. Vinculada, avisa a tela e fecha com
`modalsManager.done()` (fecha o modal do topo).

- [ ] **Step 1: o componente**

`packages/fleetops/addon/components/modals/vincular-ifood.js`:

```js
import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { contagem, limparCodigo, linkSeguro, segundosRestantes, vencimentoDoCodigo } from '../../utils/vinculo-ifood';

const ENDPOINT = 'entregas/lojas';

/**
 * Entregas RestaurantePro: modal "Vincular iFood" da tela Lojas, pelo fluxo distribuído do iFood
 * (api/app/Http/Controllers/Entregas/IfoodLojasController.php):
 * 1. ao abrir, pede o código de vínculo (POST lojas/{id}/ifood/codigo) e mostra o código grande, o link do Portal do
 *    Parceiro com o código preenchido e a contagem de 10 min. Vencido, oferece um código novo;
 * 2. a central cola o código de autorização que o dono da loja recebeu no portal e vincula (POST .../ifood/vincular);
 * 3. com várias lojas na conta do iFood, a central escolhe uma (POST .../ifood/vincular com merchant_id).
 * Os botões ficam no corpo (o rodapé só tem Fechar). Vinculada, avisa a tela (options.onVinculado) e fecha.
 */
export default class ModalsVincularIfoodComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;
    @service modalsManager;

    // { codigo, link } do código de vínculo atual; null enquanto gera ou se falhou
    @tracked codigo = null;
    @tracked vencimento = 0;
    @tracked agora = Date.now();
    @tracked autorizacao = '';
    // lojas da conta do iFood para a central escolher (vazio = não está escolhendo)
    @tracked lojasDoIfood = [];

    constructor(owner, { options }) {
        super(...arguments);
        this.options = options;
        this.loja = options.loja;
        this.relogio = setInterval(() => {
            this.agora = Date.now();
        }, 1000);
        this.gerarCodigo.perform();
    }

    willDestroy() {
        super.willDestroy(...arguments);
        clearInterval(this.relogio);
    }

    get segundos() {
        return segundosRestantes(this.vencimento, this.agora);
    }

    get venceu() {
        return Boolean(this.codigo) && this.segundos === 0;
    }

    get tempo() {
        return contagem(this.segundos);
    }

    get escolhendo() {
        return this.lojasDoIfood.length > 0;
    }

    get podeVincular() {
        return Boolean(this.codigo) && !this.venceu && limparCodigo(this.autorizacao) !== '';
    }

    @action setAutorizacao(event) {
        this.autorizacao = event.target.value;
    }

    @task({ drop: true }) *gerarCodigo() {
        this.codigo = null;
        this.autorizacao = '';
        this.lojasDoIfood = [];

        try {
            const resposta = yield this.fetch.post(`${ENDPOINT}/${this.loja.id}/ifood/codigo`);
            this.vencimento = vencimentoDoCodigo(resposta.expira_em_segundos, Date.now());
            this.agora = Date.now();
            this.codigo = { codigo: resposta.codigo, link: linkSeguro(resposta.link) };
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *vincular() {
        if (!this.podeVincular) {
            return;
        }

        try {
            const resposta = yield this.fetch.post(`${ENDPOINT}/${this.loja.id}/ifood/vincular`, { authorizationCode: limparCodigo(this.autorizacao) });
            this.concluir(resposta);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *escolher(lojaDoIfood) {
        try {
            const resposta = yield this.fetch.post(`${ENDPOINT}/${this.loja.id}/ifood/vincular`, { merchant_id: lojaDoIfood.id });
            this.concluir(resposta);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    concluir(resposta) {
        if (Array.isArray(resposta?.escolher)) {
            this.lojasDoIfood = resposta.escolher;
            return;
        }

        if (resposta?.loja && typeof this.options.onVinculado === 'function') {
            this.options.onVinculado(resposta.loja);
        }
        this.notifications.success(this.intl.t('fleet-ops.ui.lojas.ifood.linked-success'));
        this.modalsManager.done();
    }
}
```

- [ ] **Step 2: o template**

`packages/fleetops/addon/components/modals/vincular-ifood.hbs`:

```handlebars
<Modal::Default @modalIsOpened={{@modalIsOpened}} @options={{@options}} @confirm={{@onConfirm}} @decline={{@onDecline}}>
    <div class="px-4 space-y-4 text-gray-900 dark:text-gray-50">
        {{#if this.escolhendo}}
            <p class="text-sm">{{t "fleet-ops.ui.lojas.ifood.choose" name=this.loja.nome}}</p>
            <div class="space-y-2">
                {{#each this.lojasDoIfood key="id" as |lojaDoIfood|}}
                    <Button
                        @size="sm"
                        @icon="store"
                        @wrapperClass="w-full"
                        @text={{lojaDoIfood.nome}}
                        @isLoading={{this.escolher.isRunning}}
                        @disabled={{this.escolher.isRunning}}
                        @onClick={{perform this.escolher lojaDoIfood}}
                    />
                {{/each}}
            </div>
        {{else if this.gerarCodigo.isRunning}}
            <p class="text-sm text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.lojas.ifood.generating"}}</p>
        {{else if this.codigo}}
            <p class="text-sm">{{t "fleet-ops.ui.lojas.ifood.step-code"}}</p>
            <div class="text-center">
                <div class="font-mono text-3xl font-bold tracking-widest select-all">{{this.codigo.codigo}}</div>
                {{#if this.venceu}}
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{t "fleet-ops.ui.lojas.ifood.expired"}}</p>
                {{else}}
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.lojas.ifood.expires-in" time=this.tempo}}</p>
                {{/if}}
                {{#if this.codigo.link}}
                    <a href={{this.codigo.link}} target="_blank" rel="noopener noreferrer" class="mt-2 inline-block text-sm text-blue-600 dark:text-blue-400 hover:underline">{{t
                            "fleet-ops.ui.lojas.ifood.open-portal"
                        }}</a>
                {{/if}}
            </div>
            {{#if this.venceu}}
                <Button @size="sm" @icon="refresh" @text={{t "fleet-ops.ui.lojas.ifood.new-code"}} @onClick={{perform this.gerarCodigo}} />
            {{else}}
                <div class="space-y-2">
                    <label for="vincular-ifood-autorizacao" class="block text-sm">{{t "fleet-ops.ui.lojas.ifood.step-authorization"}}</label>
                    <input
                        id="vincular-ifood-autorizacao"
                        type="text"
                        class="form-input form-input-sm w-full font-mono"
                        autocomplete="off"
                        placeholder={{t "fleet-ops.ui.lojas.ifood.authorization-placeholder"}}
                        value={{this.autorizacao}}
                        {{on "input" this.setAutorizacao}}
                    />
                    <Button
                        @type="primary"
                        @size="sm"
                        @icon="link"
                        @text={{t "fleet-ops.ui.lojas.ifood.confirm"}}
                        @disabled={{not this.podeVincular}}
                        @isLoading={{this.vincular.isRunning}}
                        @onClick={{perform this.vincular}}
                    />
                </div>
            {{/if}}
        {{else}}
            <Button @size="sm" @icon="refresh" @text={{t "fleet-ops.ui.lojas.ifood.new-code"}} @onClick={{perform this.gerarCodigo}} />
        {{/if}}
    </div>
</Modal::Default>
```

- [ ] **Step 3: o re-export**

`packages/fleetops/app/components/modals/vincular-ifood.js`:

```js
export { default } from '@fleetbase/fleetops-engine/components/modals/vincular-ifood';
```

- [ ] **Step 4: validações**

Run:
```bash
P=$(ls -d console/node_modules/.pnpm/@babel+parser@7* | head -1)/node_modules/@babel/parser
for f in packages/fleetops/addon/components/modals/vincular-ifood.js packages/fleetops/app/components/modals/vincular-ifood.js; do node -e "require('./$P').parse(require('fs').readFileSync('$f','utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties','classPrivateMethods']});console.log('ok $f')"; done
```
Expected: `ok` nos dois.

Run: `node scripts/i18n-check.cjs fleetops; echo exit=$?`
Expected: `exit=0`, com `erros de sintaxe: 0` e `sem tradução em en-us/pt-br: 0` no fleetops (o template compila e todas
as chaves `fleet-ops.ui.lojas.ifood.*` existem).

- [ ] **Step 5: commit**

```bash
git rev-parse --show-toplevel
git add packages/fleetops/addon/components/modals/vincular-ifood.js packages/fleetops/addon/components/modals/vincular-ifood.hbs packages/fleetops/app/components/modals/vincular-ifood.js
git commit -m "Lojas: modal Vincular iFood (código de vínculo com contagem, código de autorização e escolha da loja)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: selo e botões no card da loja

**Files:**
- Modify: `packages/fleetops/addon/controllers/management/lojas.js`
- Modify: `packages/fleetops/addon/templates/management/lojas.hbs`

- [ ] **Step 1: o controller**

Em `packages/fleetops/addon/controllers/management/lojas.js`:

1. Troque o import do ember-concurrency e a constante logo abaixo:

```js
import { task } from 'ember-concurrency';

const ENDPOINT = 'entregas/lojas';
```

por:

```js
import { task } from 'ember-concurrency';
import { situacaoDoIfood } from '../../utils/vinculo-ifood';

const ENDPOINT = 'entregas/lojas';
```

2. Troque:

```js
    @service notifications;

    @tracked lojas = [];
    @tracked carregado = false;
```

por:

```js
    @service notifications;
    @service modalsManager;

    @tracked lojas = [];
    @tracked carregado = false;
    // integração iFood ligada no servidor (ENTREGAS_IFOOD): só então aparecem "Vincular iFood" e "Desvincular iFood"
    @tracked ifoodLigado = false;
```

3. No `limpar()`, troque:

```js
        this.lojas = [];
        this.carregado = false;
        this.editando = null;
```

por:

```js
        this.lojas = [];
        this.carregado = false;
        this.ifoodLigado = false;
        this.editando = null;
```

4. No `carregar`, troque:

```js
            this.lojas = resposta.lojas ?? [];
            this.carregado = true;
```

por:

```js
            this.lojas = resposta.lojas ?? [];
            this.ifoodLigado = Boolean(resposta.ifood_ligado);
            this.carregado = true;
```

5. Logo antes do comentário `/** Põe a loja devolvida pela API na lista (nova ou atualizada), na ordem alfabética. */`,
   acrescente:

```js
    /** Selo do iFood no card: 'vinculada', 'perdido' ou 'nenhum'. */
    @action situacaoIfood(loja) {
        return situacaoDoIfood(loja?.ifood);
    }

    /** Modal "Vincular iFood" (código de vínculo, código de autorização e, se preciso, a escolha da loja do iFood). */
    @action vincularIfood(loja) {
        this.modalsManager.show('modals/vincular-ifood', {
            title: this.intl.t('fleet-ops.ui.lojas.ifood.modal-title', { name: loja.nome }),
            loja,
            hideAcceptButton: true,
            declineButtonText: this.intl.t('fleet-ops.ui.lojas.ifood.close'),
            onVinculado: (lojaAtualizada) => this.substituirLoja(lojaAtualizada),
        });
    }

    /** Desvincula do iFood: os pedidos da loja deixam de entrar até um novo vínculo. */
    @action desvincularIfood(loja) {
        this.modalsManager.confirm({
            title: this.intl.t('fleet-ops.ui.lojas.ifood.unlink-title'),
            body: this.intl.t('fleet-ops.ui.lojas.ifood.unlink-body', { name: loja.nome }),
            acceptButtonText: this.intl.t('fleet-ops.ui.lojas.ifood.unlink'),
            acceptButtonScheme: 'danger',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    const resposta = await this.fetch.delete(`${ENDPOINT}/${loja.id}/ifood`);
                    this.substituirLoja(resposta.loja);
                    this.notifications.success(this.intl.t('fleet-ops.ui.lojas.ifood.unlinked'));
                } catch (error) {
                    this.notifications.serverError(error);
                }
            },
        });
    }

```

(O `confirm` do `modalsManager` recebe o próprio serviço; o `startLoading()` sem id vale para o modal do topo, e o modal
fecha sozinho quando a promessa termina: `onClickConfirmWithDone` do ember-ui.)

- [ ] **Step 2: o template**

Em `packages/fleetops/addon/templates/management/lojas.hbs`, troque o fim do bloco dos ids de integração e os botões
do card:

```handlebars
                                {{/if}}
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <Button @size="xs" @icon="pen-to-square" @text={{t "fleet-ops.ui.lojas.edit"}} @onClick={{fn this.editar loja}} />
                            <Button @size="xs" @icon="user-plus" @text={{t "fleet-ops.ui.lojas.add-user"}} @onClick={{fn this.abrirNovoUsuario loja}} />
                        </div>
```

por:

```handlebars
                                {{/if}}
                            </div>
                            {{! Entregas: vínculo com o iFood (IfoodLojasController): "Vinculada · <loja no iFood>", "Vínculo perdido" em vermelho ou "—" }}
                            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                                <span class="text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.lojas.ifood.label"}}</span>
                                {{#let (this.situacaoIfood loja) as |situacao|}}
                                    {{#if (eq situacao "vinculada")}}
                                        <span class="font-medium rounded px-2 py-0.5 bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-100">{{t
                                                "fleet-ops.ui.lojas.ifood.linked"
                                                name=loja.ifood.nome
                                            }}</span>
                                    {{else if (eq situacao "perdido")}}
                                        <span class="font-medium rounded px-2 py-0.5 bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-100">{{t "fleet-ops.ui.lojas.ifood.lost"}}</span>
                                    {{else}}
                                        <span class="text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.lojas.ifood.none"}}</span>
                                    {{/if}}
                                {{/let}}
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <Button @size="xs" @icon="pen-to-square" @text={{t "fleet-ops.ui.lojas.edit"}} @onClick={{fn this.editar loja}} />
                            <Button @size="xs" @icon="user-plus" @text={{t "fleet-ops.ui.lojas.add-user"}} @onClick={{fn this.abrirNovoUsuario loja}} />
                            {{#if this.ifoodLigado}}
                                {{#let (this.situacaoIfood loja) as |situacao|}}
                                    {{#if (eq situacao "vinculada")}}
                                        <Button @size="xs" @icon="link-slash" @text={{t "fleet-ops.ui.lojas.ifood.unlink"}} @onClick={{fn this.desvincularIfood loja}} />
                                    {{else if (eq situacao "perdido")}}
                                        <Button @size="xs" @type="primary" @icon="link" @text={{t "fleet-ops.ui.lojas.ifood.relink"}} @onClick={{fn this.vincularIfood loja}} />
                                    {{else}}
                                        <Button @size="xs" @icon="link" @text={{t "fleet-ops.ui.lojas.ifood.link"}} @onClick={{fn this.vincularIfood loja}} />
                                    {{/if}}
                                {{/let}}
                            {{/if}}
                        </div>
```

(O trecho trocado é o único com `@onClick={{fn this.abrirNovoUsuario loja}} />` seguido de `</div>`; o
`{{this.situacaoIfood loja}}` como helper segue o padrão do `{{this.enderecoDaLoja loja}}` da mesma tela.)

- [ ] **Step 3: validações**

Run:
```bash
P=$(ls -d console/node_modules/.pnpm/@babel+parser@7* | head -1)/node_modules/@babel/parser
f=packages/fleetops/addon/controllers/management/lojas.js; node -e "require('./$P').parse(require('fs').readFileSync('$f','utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties','classPrivateMethods']});console.log('ok $f')"
```
Expected: `ok packages/fleetops/addon/controllers/management/lojas.js`.

Run: `node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo exit=$?`
Expected: `exit=0`; no fleetops, `faltando em pt-br: 0`, `erros de sintaxe: 0`, `sem tradução em en-us/pt-br: 0` e
os textos fixos (heurística) no mesmo número de antes (92 em 2026-10-05: nenhum texto fixo novo).

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs 2>&1 | grep -E "^# (pass|fail)"`
Expected: `# fail 0`.

- [ ] **Step 4: commit**

```bash
git rev-parse --show-toplevel
git add packages/fleetops/addon/controllers/management/lojas.js packages/fleetops/addon/templates/management/lojas.hbs
git commit -m "Lojas: selo do iFood no card e botões para vincular e desvincular

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: build, teste manual e documentação

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: build local do console**

Run: `cd console && DISABLE_RUNTIME_CONFIG=false pnpm build --environment production` (≈10 min; `run_in_background`).
Expected: termina sem erro.

- [ ] **Step 2: teste manual contra a produção**

Precisa do 2A implantado na API com `ENTREGAS_IFOOD=1` (Task 13 do 2A, Step 1). Sem isso, a lista vem sem
`ifood_ligado` e os botões não aparecem (só o selo "—"): confira esse caso e deixe o resto para depois do deploy.

1. Grave `console/dist/fleetbase.config.json` com `API_HOST=https://entregas-api.restaurantepro.com.br` (ver "Build do
   console" no `CLAUDE.md`), sirva com `npx serve -s dist -l 4200` e faça login nessa aba como admin.
2. Fleet-Ops → Recursos → Lojas. Expected: cada card com "iFood: —" (ou o selo da loja já vinculada) e, com a
   integração ligada, o botão "Vincular iFood".
3. Clique em "Vincular iFood" na **Terraço Pizza Bar** (loja só de testes). Expected: título "Vincular Terraço Pizza
   Bar ao iFood", "Gerando o código no iFood…" e, em seguida, o código grande (formato `XXXX-XXXX`), "O código vence em
   9:59" contando, o link "Abrir o Portal do Parceiro com o código" (abre `portal.ifood.com.br` em outra aba) e o campo
   do código de autorização com "Vincular" desabilitado até colar algo.
4. Cole um código qualquer (ex.: `TESTE`) e clique em "Vincular". Expected: notificação de erro "O iFood recusou o
   código…" e o modal continua aberto.
5. Feche o modal pelo "Fechar". Abra de novo: um código novo é pedido. (O vínculo de verdade, com o dono autorizando
   no portal, é a Task 13 do 2A.)
6. Troque o idioma para inglês e confira os textos do card e do modal; volte para português.

- [ ] **Step 3: documentar no `CLAUDE.md`**

Na seção "Integração iFood (etapa 2: entrada dos pedidos)", no fim do item **Vínculo (tela Lojas, só admin)**,
acrescente: " Na tela: selo no card ("Vinculada · <loja no iFood>", "Vínculo perdido" em vermelho, "—"), "Vincular
iFood" (modal `modals/vincular-ifood`: código grande com o link do Portal do Parceiro e contagem de 10 min, campo do
código de autorização e, com várias lojas na conta, a escolha) e "Desvincular iFood" (com confirmação). Os botões só
aparecem com `ifood_ligado`. Funções puras em `packages/fleetops/addon/utils/vinculo-ifood.js` (teste
`scripts/teste-portal/vinculo-ifood.test.mjs`)."

- [ ] **Step 4: commit**

```bash
git rev-parse --show-toplevel
git add CLAUDE.md
git commit -m "CLAUDE.md: tela do vínculo com o iFood

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 5: avisar o Edgard sobre o deploy**

O deploy é dele (sem push automático): `git push` (com a autorização dele) e, na VPS, `cd ~/entregas && bash
deploy/atualizar.sh console` (ou `bash deploy/atualizar.sh` junto com a API do 2A). Depois, Ctrl+Shift+R no console. Em
seguida, a Task 13 do plano 2A (vínculo real da loja de teste e pedido de teste).
