# Distribuição em rodadas (console e app): plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mostrar no painel "Distribuição" do console a volta, a rodada, o raio e a lista aberta da distribuição em rodadas (com o botão "Mostrar a todos agora"), e fazer o Dispensar do app avisar o servidor nos pedidos em distribuição.

**Architecture:** Depende do plano da API das rodadas, feito em paralelo neste mesmo ramo (arquivo irmão em `docs/superpowers/plans/`, `2026-10-07-distribuicao-em-rodadas-api.md`). Esse plano entrega no `GET int/v1/entregas/pedidos/{id}/distribuicao` os campos `volta`, `rodada`, `raio_m`, `lista_aberta_em` e `rodadas`, e em cada item do `historico` os campos `volta`, `rodada` e `raio_m`, com as respostas novas `dispensada` e `aceita_pela_lista`. Também entrega o `POST .../distribuicao/abrir` gravando a `lista_aberta_em`, a marca `entregas_distribuicao: true` na lista do app e o `POST v1/entregas/motoboy/pedidos/{id}/recusar` respondendo `recusada` ou `dispensada`. **Os nomes desse contrato não mudam aqui.** Console: funções puras novas em `utils/distribuicao.js` e o painel decide pelo `rodadas` da resposta (sem ele, nada muda). App (repo `entregas-navigator`): duas funções puras em `src/utils/oferta.ts`, o `secoesDaLista` passa a não esconder a oferta, e o Dispensar do card e da tela do pedido chama a rota de recusa quando o pedido está em distribuição. Spec: `docs/superpowers/specs/2026-10-07-distribuicao-em-rodadas-design.md`, seções 5, 7, 8, 9 e 10.

**Tech Stack:** Ember (Glimmer, ember-concurrency, ember-intl 6) no console; React Native + `@fleetbase/sdk` no app. Testes: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/distribuicao.test.mjs` (console, em `C:/tmp/dr`) e `node --experimental-strip-types --test scripts/testes/oferta.teste.ts scripts/testes/lista-de-pedidos.teste.ts` (app, em `C:/tmp/drapp`).

**Fora do escopo:** o servidor (plano da API) e o resto da seção "Distribuição de pedidos abertos" do `CLAUDE.md`, que o plano da API atualiza. Aqui só mudam as subseções "Painel do console" e "APK".

---

## Convenções

- **Repos e ramos:**
  - console: worktree `C:/tmp/dr`, ramo `distribuicao-rodadas`;
  - app: worktree `C:/tmp/drapp`, ramo `distribuicao-rodadas`.
  - Antes de cada commit, confira `git -C <repo> rev-parse --show-toplevel` e `git -C <repo> branch --show-current`.
- **Commits:** um por tarefa, em português, terminando com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Use sempre `git add <arquivo>` com os caminhos exatos: outro agente commita no mesmo ramo de `C:/tmp/dr` em paralelo. Se o commit falhar por `index.lock`, espere uns segundos e tente de novo. **Nada de push.**
- **Console:**
  - Chaves novas em `fleet-ops.ui.distribuicao.*` nos dois YAML de `packages/fleetops/translations/`. Os blocos ficam assim:
    - pt-br: o bloco `distribuicao:` está na linha ~283, com 4 espaços; as chaves dele têm 6 espaços e as de `fase`/`motivo`/`resposta`, 8;
    - en-us: o bloco também está na linha ~283, com 8 espaços; as chaves dele têm 12 e as de `fase`/`motivo`/`resposta`, 16.
  - ICU: nada de apóstrofo nos textos (o `'` é escape no ICU). Os textos com `{` vão entre aspas simples no YAML, como os que já existem.
  - **O worktree não tem `console/node_modules`.** O `i18n-check` e o parse com o Babel precisam dele. Crie uma junção para o `node_modules` do repo principal (o `.gitignore` do console já ignora; testado: o `git status` não mostra nada):
    ```powershell
    if (-not (Test-Path C:\tmp\dr\console\node_modules)) { cmd /c mklink /J "C:\tmp\dr\console\node_modules" "C:\Users\Edgardjr\Documents\vibe coding\Delivery\console\node_modules" }
    ```
    No fim, remova só a junção com `cmd /c rmdir "C:\tmp\dr\console\node_modules"` (nunca `Remove-Item -Recurse`: apagaria o `node_modules` do repo principal).
  - Validação antes de commitar YAML ou template (em `C:/tmp/dr`): `node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal` precisa sair com exit 0.
- **App:**
  - Utils sem imports, testados com o Node puro.
  - Traduções em `translations/pt.json` e `translations/en.json`.
  - O worktree não tem `node_modules` (sem `tsc`). A sintaxe do TSX é conferida com o `@babel/parser` do console do repo principal (plugins `typescript` e `jsx`), com o comando da Task 7.
  - Parte dos arquivos do app tem fim de linha CRLF (ex.: `src/utils/lista-de-pedidos.ts`). A ferramenta Edit lida com isso. Um script de troca por texto precisa normalizar `\r\n` antes de procurar o trecho.
- **Kotlin:** não muda (ver "Conferido sem mudança").

## Estrutura de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `C:/tmp/dr/packages/fleetops/addon/utils/distribuicao.js` | Funções puras: respostas novas, `emRodadas`, `inteiroPositivo`, `kmTexto`, `horaCurta`, `agruparPorVolta`, `podeAbrir` com a lista e `textosDoBotao`. |
| `C:/tmp/dr/scripts/teste-portal/distribuicao.test.mjs` | Testes dessas funções. |
| `C:/tmp/dr/packages/fleetops/translations/{pt-br,en-us}.yaml` | Textos novos `fleet-ops.ui.distribuicao.*`. |
| `C:/tmp/dr/packages/fleetops/addon/components/order/details/distribuicao.{js,hbs}` | O painel: linha da rodada, lista aberta/fechada, histórico por volta e o botão "Mostrar a todos agora". |
| `C:/tmp/drapp/src/utils/oferta.ts` | `ehDistribuido` e `desfechoDaDispensa`. |
| `C:/tmp/drapp/scripts/testes/oferta.teste.ts` | Testes. |
| `C:/tmp/drapp/src/utils/lista-de-pedidos.ts` | O `secoesDaLista` não esconde a oferta dele, mesmo dispensada localmente. |
| `C:/tmp/drapp/scripts/testes/lista-de-pedidos.teste.ts` | Teste. |
| `C:/tmp/drapp/translations/{pt,en}.json` | `AdhocOrderCard.dismissMessageDistribuido`, `dispensando` e `dispensaFalhou`. |
| `C:/tmp/drapp/src/components/AdhocOrderCard.tsx` | Dispensar de pedido em distribuição → servidor. |
| `C:/tmp/drapp/src/screens/OrderScreen.tsx` | Idem na tela do pedido. |
| `C:/tmp/dr/CLAUDE.md` | Subseções "Painel do console" e "APK" da seção "Distribuição de pedidos abertos". |

Ficam sem mudança, conferidos:

- `DriverOrderManagementScreen.tsx`: o `onDismiss` do card já grava nos dispensados locais;
- `OrderManagerContext.tsx`: o `removerPedidoProximo` e o `setDimissedOrders` já existem;
- `DriverLayout.tsx`: o Recusar do cartão da oferta já chama a rota; com rodadas, a resposta pode ser `dispensada`, e qualquer 2xx vale como recusa;
- o Kotlin.

## Conferido sem mudança: cartão nativo (Kotlin) e `DriverLayout`

- Com as rodadas ligadas, o servidor só manda push de **oferta** (`OfertaDePedido`, `entregas_oferta=1`): sai o `OrderPing` a todos e saem os reenvios. O cartão já:
  - conta o prazo pelos `entregas_oferta_segundos` (20 s vêm do servidor; nenhum 30 fixo no código, só num comentário do `AlarmePedidoActivity.kt`, linha ~103);
  - fecha no vencimento (`setTimeoutAfter`);
  - no Recusar da oferta, abre o app com `entregas_recusar=1`, sem gravar a recusa local de 2 h.
- O `DriverLayout` chama `recusar` e mostra "Oferta recusada." em qualquer 2xx. Se a oferta já tinha passado e a lista está aberta, o servidor devolve `dispensada`, que também tira o pedido da lista desse motoboy na volta. Está certo.
- **Caso de borda aceito, sem mudar o Kotlin:**
  - o `disparar` do `AlarmeDePedido.kt` (linha ~169) não toca um `order_ping` de pedido recusado localmente há menos de 2 h, **inclusive uma oferta**;
  - com as rodadas, a recusa local só existe se o mesmo pedido teve antes um alarme geral: ciclo antigo antes de ligar a chave, ou motivo `falha`. Depois da `falha` a distribuição fica `aberta` e não há mais oferta;
  - mexer nisso exigiria compilar o Kotlin no Actions por um caso que não acontece no fluxo novo.

## Pontos ambíguos resolvidos aqui

1. **Confirmação no Dispensar de pedido em distribuição:** fica a confirmação de sempre, com um texto próprio ("O pedido sai da sua lista por enquanto. Se ninguém aceitar, ele pode voltar para você."). Não há cronômetro correndo: o Recusar direto, sem confirmação, continua só na oferta.
2. **409 no Dispensar:**
   - **Problema:** o contrato diz que o 409 "some sem aviso". Mas, sem rodadas (ou na fase `aberta` por `falha`), o servidor responde 409 e continua mandando o pedido na lista. Tirá-lo só da lista local faria o pedido voltar a cada recarga.
   - **Decisão:** o 409 tira o pedido da lista local **e** o grava nos dispensados locais (`dismissedOrders`, em memória até fechar o app).
   - **Para a oferta de uma volta seguinte não sumir:** o `secoesDaLista` deixa de esconder pedido com `entregas_oferta`.
   - **Custo:** nesse caso raro (lista fechada e sem oferta dele), ele só volta a ver o pedido pela oferta, não pela lista aberta da volta seguinte.
   - No sucesso (`recusada` ou `dispensada`), o pedido **não** vai para os dispensados: o servidor o devolve na volta seguinte.
3. **Como a tela do pedido sabe que o pedido está em distribuição:**
   - aberta pelo card, o `params.order` serializado traz `entregas_distribuicao`;
   - aberta pelo push, o `findRecord` não traz a marca, mas o push de oferta traz o `ofertaPrazo`.
   - Por isso, distribuído = `ehDistribuido(params.order) || Number.isFinite(prazoOferta)`. Sem rodadas e com a oferta vencida, o servidor responde 409 e o resultado é o de hoje: o pedido vai para os dispensados locais e a tela fecha.
4. **"Mostrar a todos agora":**
   - a confirmação diz que é sem alarme e que as ofertas continuam;
   - ícone `list` (o "Abrir a todos agora" segue com `bullhorn`);
   - a aviso de sucesso é "Pedido mostrado a todos os motoboys.";
   - o 409 mostra a mensagem do servidor e relê o painel, como hoje.
5. **Hora da lista aberta:** `HH:MM` no fuso do navegador (o console roda em Brasília), com `hourCycle: 'h23'` também em inglês.
6. **Linha da rodada e lista aberta/fechada só em `ofertas`:** encerrada, o histórico por volta já conta o que houve.
7. **Linhas `dispensada`/`aceita_pela_lista` sem tempo estimado:** saem com "—" no tempo, como qualquer linha sem estimativa.
8. **Rótulos em inglês:** "Pass N · round M" (volta = pass, rodada = round), para não confundir as duas.

---

### Task 1: Console: funções puras das rodadas

**Files:**
- Modify: `C:/tmp/dr/packages/fleetops/addon/utils/distribuicao.js` (arquivo inteiro abaixo)
- Test: `C:/tmp/dr/scripts/teste-portal/distribuicao.test.mjs` (arquivo inteiro abaixo)

- [ ] **Step 1: Escrever o teste (substitui o arquivo inteiro)**

```js
// Distribuição de pedidos abertos no console (packages/fleetops/addon/utils/distribuicao.js): o painel do detalhe do pedido.
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/distribuicao.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    FASES,
    MOTIVOS,
    RESPOSTAS,
    agruparPorVolta,
    chaveDaFase,
    chaveDoMotivo,
    chaveDaResposta,
    emRodadas,
    horaCurta,
    inteiroPositivo,
    kmTexto,
    minutos,
    podeAbrir,
    segundosRestantes,
    textosDoBotao,
} from '../../packages/fleetops/addon/utils/distribuicao.js';

test('chaves de tradução: conhecidas e desconhecidas', () => {
    assert.deepEqual(FASES, ['ofertas', 'aberta', 'encerrada']);
    assert.equal(chaveDaFase('ofertas'), 'ofertas');
    assert.equal(chaveDaFase('outra'), 'desconhecida');
    assert.ok(MOTIVOS.includes('aberta_pela_central') && MOTIVOS.includes('redespachada') && MOTIVOS.includes('falha'));
    assert.equal(chaveDoMotivo('fila_esgotada'), 'fila_esgotada');
    assert.equal(chaveDoMotivo('falha'), 'falha');
    assert.equal(chaveDoMotivo(null), null);
    assert.equal(chaveDoMotivo('x'), 'desconhecido');
    assert.deepEqual(RESPOSTAS, ['pendente', 'aceita', 'recusada', 'vencida', 'cancelada', 'dispensada', 'aceita_pela_lista']);
    assert.equal(chaveDaResposta('vencida'), 'vencida');
    assert.equal(chaveDaResposta('dispensada'), 'dispensada');
    assert.equal(chaveDaResposta('aceita_pela_lista'), 'aceita_pela_lista');
    assert.equal(chaveDaResposta('x'), 'desconhecida');
});

test('segundos restantes da oferta', () => {
    const agora = Date.parse('2026-10-07T13:00:00Z');
    assert.equal(segundosRestantes('2026-10-07T10:00:30-03:00', agora), 30);
    assert.equal(segundosRestantes('2026-10-07T10:00:20-03:00', agora), 20, 'oferta de 20 s das rodadas');
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

test('rodadas: só com rodadas === true na resposta', () => {
    assert.equal(emRodadas({ rodadas: true }), true);
    assert.equal(emRodadas({ rodadas: false }), false);
    assert.equal(emRodadas({ rodadas: 'true' }), false);
    assert.equal(emRodadas({}), false);
    assert.equal(emRodadas(null), false);
});

test('volta e rodada: inteiro positivo ou o padrão', () => {
    assert.equal(inteiroPositivo(2), 2);
    assert.equal(inteiroPositivo('3'), 3);
    assert.equal(inteiroPositivo(null), 1);
    assert.equal(inteiroPositivo(undefined), 1);
    assert.equal(inteiroPositivo(''), 1);
    assert.equal(inteiroPositivo(0), 1);
    assert.equal(inteiroPositivo(-2), 1);
    assert.equal(inteiroPositivo(1.5), 1);
    assert.equal(inteiroPositivo('abc', 7), 7);
});

test('raio em km no idioma ativo', () => {
    assert.equal(kmTexto(6000), '6');
    assert.equal(kmTexto(9000, 'pt-br'), '9');
    assert.equal(kmTexto(7500), '7,5');
    assert.equal(kmTexto(7500, 'en-us'), '7.5');
    assert.equal(kmTexto(12340), '12,3');
    assert.equal(kmTexto('6000'), '6');
    assert.equal(kmTexto(0), null);
    assert.equal(kmTexto(-5), null);
    assert.equal(kmTexto(null), null);
    assert.equal(kmTexto(''), null);
    assert.equal(kmTexto('abc'), null);
});

test('hora curta da lista aberta', () => {
    assert.equal(horaCurta('2026-10-07T05:31:00-03:00', 'pt-BR', 'America/Sao_Paulo'), '05:31');
    assert.equal(horaCurta('2026-10-07T08:31:00Z', 'pt-br', 'America/Sao_Paulo'), '05:31');
    assert.equal(horaCurta('2026-10-07T17:05:00-03:00', 'en-US', 'America/Sao_Paulo'), '17:05', '24 h também em inglês');
    assert.equal(horaCurta(null), null);
    assert.equal(horaCurta(''), null);
    assert.equal(horaCurta('lixo'), null);
});

test('histórico agrupado por volta, na ordem em que as voltas aparecem', () => {
    const a = { posicao: 1, volta: 1 };
    const b = { posicao: 2, volta: 1 };
    const c = { posicao: 3, volta: 2 };
    const semVolta = { posicao: 4 };
    const texto = { posicao: 5, volta: '2' };
    assert.deepEqual(agruparPorVolta([a, b, c, semVolta, texto]), [
        { volta: 1, ofertas: [a, b, semVolta] },
        { volta: 2, ofertas: [c, texto] },
    ]);
    assert.deepEqual(agruparPorVolta([]), []);
    assert.deepEqual(agruparPorVolta(null), []);
});

test('pode abrir a todos: só em ofertas, ligada e com pedido não encerrado', () => {
    assert.equal(podeAbrir({ fase: 'ofertas' }, 'dispatched'), true);
    assert.equal(podeAbrir({ fase: 'ofertas', ligada: true }, 'dispatched'), true);
    assert.equal(podeAbrir({ fase: 'ofertas', ligada: false }, 'dispatched'), false);
    assert.equal(podeAbrir({ fase: 'aberta' }, 'dispatched'), false);
    assert.equal(podeAbrir({ fase: 'ofertas' }, 'canceled'), false);
    assert.equal(podeAbrir(null, 'dispatched'), false);
});

test('mostrar a todos (rodadas): também só com a lista ainda fechada', () => {
    assert.equal(podeAbrir({ fase: 'ofertas', rodadas: true, lista_aberta_em: null }, 'dispatched'), true);
    assert.equal(podeAbrir({ fase: 'ofertas', rodadas: true }, 'dispatched'), true);
    assert.equal(podeAbrir({ fase: 'ofertas', rodadas: true, lista_aberta_em: '2026-10-07T05:31:00-03:00' }, 'dispatched'), false);
    assert.equal(podeAbrir({ fase: 'ofertas', rodadas: true, ligada: false }, 'dispatched'), false);
    assert.equal(podeAbrir({ fase: 'ofertas', rodadas: true }, 'completed'), false);
    assert.equal(podeAbrir({ fase: 'ofertas', rodadas: false, lista_aberta_em: '2026-10-07T05:31:00-03:00' }, 'dispatched'), true, 'sem rodadas a lista não conta');
});

test('textos do botão: mostrar (rodadas) ou abrir', () => {
    assert.deepEqual(textosDoBotao({ rodadas: true }), { botao: 'mostrar', titulo: 'mostrar-titulo', texto: 'mostrar-texto', feito: 'mostrada', icone: 'list' });
    assert.deepEqual(textosDoBotao({ rodadas: false }), { botao: 'abrir', titulo: 'abrir-titulo', texto: 'abrir-texto', feito: 'aberto', icone: 'bullhorn' });
    assert.deepEqual(textosDoBotao(null), { botao: 'abrir', titulo: 'abrir-titulo', texto: 'abrir-texto', feito: 'aberto', icone: 'bullhorn' });
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run (em `C:/tmp/dr`): `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/distribuicao.test.mjs`
Expected: FAIL. O import de `agruparPorVolta`, `emRodadas`, `horaCurta`, `inteiroPositivo`, `kmTexto` e `textosDoBotao` não existe ("does not provide an export named").

- [ ] **Step 3: Implementar (substitui o arquivo inteiro)**

```js
// Entregas: painel "Distribuição" no detalhe do pedido (oferta um a um aos motoboys; ver App\Support\Entregas\Distribuicao
// na API). Só importa outro util relativo (pedido-ifood): testado no Node com o resolver.mjs (scripts/teste-portal/distribuicao.test.mjs).
// Distribuição em rodadas (ENTREGAS_DISTRIBUICAO_RODADAS; a resposta traz `rodadas: true`): volta, rodada, raio, lista
// aberta e o botão "Mostrar a todos agora". Sem a chave, o painel de antes.
import { ENCERRADOS } from './pedido-ifood';

/** Fases da distribuição (entregas_distribuicoes.fase) → chave fleet-ops.ui.distribuicao.fase.<chave>. */
export const FASES = ['ofertas', 'aberta', 'encerrada'];

/** Motivos (entregas_distribuicoes.motivo) → chave fleet-ops.ui.distribuicao.motivo.<chave>. */
export const MOTIVOS = ['aceita', 'atribuida', 'cancelada', 'aberta_pela_central', 'fila_esgotada', 'prazo', 'sem_candidato', 'redespachada', 'falha'];

/**
 * Respostas da oferta (entregas_ofertas.resposta) → chave fleet-ops.ui.distribuicao.resposta.<chave>. `dispensada`
 * (dispensou pela lista aberta) e `aceita_pela_lista` vêm das rodadas.
 */
export const RESPOSTAS = ['pendente', 'aceita', 'recusada', 'vencida', 'cancelada', 'dispensada', 'aceita_pela_lista'];

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

/** Distribuição em rodadas: só com `rodadas: true` na resposta (a chave ENTREGAS_DISTRIBUICAO_RODADAS ligada). */
export function emRodadas(painel) {
    return painel?.rodadas === true;
}

/** Volta e rodada: inteiro ≥ 1; qualquer outra coisa (nulo, texto, 0, fração) vira o padrão. */
export function inteiroPositivo(bruto, padrao = 1) {
    if (bruto === null || bruto === undefined || bruto === '') {
        return padrao;
    }
    const valor = Number(bruto);

    return Number.isInteger(valor) && valor >= 1 ? valor : padrao;
}

/** Metros → km com até 1 casa, no idioma ativo ("6", "7,5"); null se não for um número positivo. */
export function kmTexto(metros, locale = 'pt-BR') {
    if (metros === null || metros === undefined || metros === '') {
        return null;
    }
    const valor = Number(metros);
    if (!Number.isFinite(valor) || valor <= 0) {
        return null;
    }

    return new Intl.NumberFormat(locale, { maximumFractionDigits: 1 }).format(valor / 1000);
}

/** ISO → "HH:MM" em 24 h (fuso do navegador, ou o informado); null se a data não se lê. */
export function horaCurta(iso, locale = 'pt-BR', timeZone = undefined) {
    const instante = Date.parse(iso ?? '');
    if (!Number.isFinite(instante)) {
        return null;
    }
    const opcoes = { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' };
    if (timeZone) {
        opcoes.timeZone = timeZone;
    }

    return new Intl.DateTimeFormat(locale, opcoes).format(new Date(instante));
}

/** Histórico das ofertas agrupado por volta, na ordem em que as voltas aparecem (linha sem volta = volta 1). */
export function agruparPorVolta(historico) {
    const grupos = [];
    for (const oferta of historico ?? []) {
        const volta = inteiroPositivo(oferta?.volta);
        let grupo = grupos.find((existente) => existente.volta === volta);
        if (!grupo) {
            grupo = { volta, ofertas: [] };
            grupos.push(grupo);
        }
        grupo.ofertas.push(oferta);
    }

    return grupos;
}

/**
 * "Abrir a todos agora" / "Mostrar a todos agora": só com a distribuição em ofertas, ligada no servidor (`ligada: false`
 * = ENTREGAS_DISTRIBUICAO vazia: a distribuição parou de avançar e o aceite já é livre) e o pedido não encerrado. Com
 * rodadas, também só com a lista ainda fechada (`lista_aberta_em` nulo): aberta, o servidor responde 409.
 */
export function podeAbrir(painel, statusDoPedido) {
    const emOfertas = painel?.fase === 'ofertas' && painel?.ligada !== false && !ENCERRADOS.includes(statusDoPedido);
    if (!emOfertas) {
        return false;
    }

    return emRodadas(painel) ? !painel?.lista_aberta_em : true;
}

/**
 * Sufixos das chaves fleet-ops.ui.distribuicao.<sufixo> do botão e da confirmação, e o ícone. Com rodadas, "Mostrar a
 * todos agora" (abre a lista, sem alarme; as ofertas continuam); sem elas, "Abrir a todos agora" (alarme a todos no raio).
 */
export function textosDoBotao(painel) {
    if (emRodadas(painel)) {
        return { botao: 'mostrar', titulo: 'mostrar-titulo', texto: 'mostrar-texto', feito: 'mostrada', icone: 'list' };
    }

    return { botao: 'abrir', titulo: 'abrir-titulo', texto: 'abrir-texto', feito: 'aberto', icone: 'bullhorn' };
}
```

- [ ] **Step 4: Rodar e ver passar**

Run (em `C:/tmp/dr`): `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/distribuicao.test.mjs`
Expected: PASS (`# pass 11`, `# fail 0`).

- [ ] **Step 5: Commit**

```bash
cd /c/tmp/dr && git rev-parse --show-toplevel && git branch --show-current
git add packages/fleetops/addon/utils/distribuicao.js scripts/teste-portal/distribuicao.test.mjs
git commit -m "$(cat <<'EOF'
Distribuição em rodadas: funções puras do painel do console (volta, rodada, raio, lista aberta)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Console: textos das rodadas (pt-BR e inglês)

**Files:**
- Modify: `C:/tmp/dr/packages/fleetops/translations/pt-br.yaml` (bloco `distribuicao:`, linha ~283)
- Modify: `C:/tmp/dr/packages/fleetops/translations/en-us.yaml` (bloco `distribuicao:`, linha ~283)

- [ ] **Step 1: Preparar a junção do `node_modules` (uma vez por worktree)**

Run (PowerShell):
```powershell
if (-not (Test-Path C:\tmp\dr\console\node_modules)) { cmd /c mklink /J "C:\tmp\dr\console\node_modules" "C:\Users\Edgardjr\Documents\vibe coding\Delivery\console\node_modules" }
git -C C:\tmp\dr status --short console
```
Expected: "Junção criada…" (ou nada, se já existia) e o `git status` sem linhas.

- [ ] **Step 2: pt-br: chaves novas depois de `aberto:`**

No `pt-br.yaml`, troque:

```yaml
      aberto: Pedido aberto a todos os motoboys.
```

por:

```yaml
      aberto: Pedido aberto a todos os motoboys.
      mostrar: Mostrar a todos agora
      mostrar-titulo: Mostrar o pedido a todos os motoboys?
      mostrar-texto: O pedido passa a aparecer na lista "Novos pedidos" de todos os motoboys disponíveis no raio, sem alarme. As ofertas um a um continuam, e o primeiro que aceitar leva.
      mostrada: Pedido mostrado a todos os motoboys.
      rodada: Rodada atual
      rodada-com-raio: 'Volta {volta} · rodada {rodada} · até {km} km'
      rodada-sem-raio: 'Volta {volta} · rodada {rodada}'
      lista-aberta: 'Lista aberta desde {hora}'
      lista-fechada: Lista só para quem recebe a oferta
      volta: 'Volta {volta}'
      historico-rodada-com-raio: 'rodada {rodada} · até {km} km'
      historico-rodada-sem-raio: 'rodada {rodada}'
```

- [ ] **Step 3: pt-br: respostas novas**

No mesmo arquivo, troque (é a linha de `resposta:`; a de `motivo:` é "pedido encerrado"):

```yaml
        cancelada: cancelada
```

por:

```yaml
        cancelada: cancelada
        dispensada: dispensou pela lista
        aceita_pela_lista: aceitou pela lista
```

- [ ] **Step 4: en-us: chaves novas depois de `aberto:`**

No `en-us.yaml`, troque:

```yaml
            aberto: Order opened to all drivers.
```

por:

```yaml
            aberto: Order opened to all drivers.
            mostrar: Show to everyone now
            mostrar-titulo: Show the order to all drivers?
            mostrar-texto: The order appears in the "New orders" list of every available driver in range, with no alarm. The one-at-a-time offers continue, and the first to accept takes it.
            mostrada: Order shown to all drivers.
            rodada: Current round
            rodada-com-raio: 'Pass {volta} · round {rodada} · up to {km} km'
            rodada-sem-raio: 'Pass {volta} · round {rodada}'
            lista-aberta: 'List open since {hora}'
            lista-fechada: List only for the offered driver
            volta: 'Pass {volta}'
            historico-rodada-com-raio: 'round {rodada} · up to {km} km'
            historico-rodada-sem-raio: 'round {rodada}'
```

- [ ] **Step 5: en-us: respostas novas**

Troque (a linha de `resposta:`; a de `motivo:` é "order closed"):

```yaml
                cancelada: canceled
```

por:

```yaml
                cancelada: canceled
                dispensada: dismissed from the list
                aceita_pela_lista: accepted from the list
```

- [ ] **Step 6: Validar**

Run (em `C:/tmp/dr`): `node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit=$?"`
Expected: `faltando em pt-br: 0` em todos os módulos, `sem tradução em en-us/pt-br: 0` e `exit=0`.

- [ ] **Step 7: Commit**

```bash
cd /c/tmp/dr && git rev-parse --show-toplevel && git branch --show-current
git add packages/fleetops/translations/pt-br.yaml packages/fleetops/translations/en-us.yaml
git commit -m "$(cat <<'EOF'
Distribuição em rodadas: textos do painel (volta, rodada, lista aberta, Mostrar a todos agora)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Console: o painel com as rodadas

**Files:**
- Modify: `C:/tmp/dr/packages/fleetops/addon/components/order/details/distribuicao.js` (arquivo inteiro abaixo)
- Modify: `C:/tmp/dr/packages/fleetops/addon/components/order/details/distribuicao.hbs` (arquivo inteiro abaixo)

Sem teste de componente no repo. A lógica testável já está nas funções puras da Task 1. Aqui a verificação é o parse com o Babel, o `i18n-check` (que compila o template) e os testes da Task 1.

- [ ] **Step 1: Substituir o `distribuicao.js` inteiro**

```js
import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task, timeout } from 'ember-concurrency';
import {
    agruparPorVolta,
    chaveDaFase,
    chaveDaResposta,
    chaveDoMotivo,
    emRodadas,
    horaCurta,
    inteiroPositivo,
    kmTexto,
    minutos,
    podeAbrir,
    segundosRestantes,
    textosDoBotao,
} from '../../../utils/distribuicao';

/**
 * Entregas: painel "Distribuição" no detalhe do pedido (oferta um a um aos motoboys; rota GET
 * int/v1/entregas/pedidos/{id}/distribuicao, só administradores: para os demais o painel nem aparece, nem carrega).
 * Não depende do `adhoc`: a atribuição da central e a troca pelo líder o desligam, e o histórico precisa continuar ali.
 * Carrega para todo pedido e só aparece quando houve distribuição (`distribuicao: true`) ou, no erro, em pedido aberto.
 * Mostra a fase, a oferta atual com o cronômetro, a fila calculada (só em ofertas: tempo até o cliente, encaixe, ≈ quando
 * a estimativa é em linha reta) e o histórico das ofertas.
 * Com rodadas (`rodadas: true`, ENTREGAS_DISTRIBUICAO_RODADAS): em ofertas, "Volta N · rodada M · até X km" e a lista
 * aberta ("Lista aberta desde HH:MM") ou fechada; a fila sem o "no caminho" (não há mais encaixe); o histórico agrupado
 * por volta, com a rodada e o raio em cada linha; e o botão vira "Mostrar a todos agora" (grava a lista_aberta_em, sem
 * alarme; só com a lista ainda fechada). Sem rodadas, "Abrir a todos agora" pula a fila e manda o alarme a todos.
 * Nos dois, POST .../distribuicao/abrir; o 409 mostra a mensagem do servidor e relê o painel.
 * Em fase ofertas, o cronômetro conta de segundo em segundo e o painel é relido a cada 5 s e quando a oferta vence (uma
 * vez por vencimento), menos com a aba oculta: a fila anda sem mudar o pedido. Com a distribuição desligada no servidor
 * (`ligada: false`), não relê nem oferece o botão: a fase fica parada em ofertas. O cronômetro compara o relógio do PC com
 * o `vence_em` do servidor (30 s, ou 20 s com rodadas): é só exibição (quem vence a oferta é o servidor). O laço vive na
 * task `carregar` (restartable), cancelada quando o pedido muda ou o componente sai da tela.
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
    // o pedido do último carregamento (não rastreado: só o `recarregar` lê e grava)
    ultimoId = null;

    // só administrador carrega: a rota do servidor é só de admin e os demais veriam só o aviso de erro
    get ehAdmin() {
        return this.currentUser.isAdmin === true;
    }

    // o painel só aparece com distribuição (inclusive encerrada, pelo histórico) ou, no erro, em pedido aberto
    get mostrar() {
        return this.temDistribuicao || (this.erro && this.args.resource?.adhoc === true);
    }

    get emOfertas() {
        return this.painel?.fase === 'ofertas';
    }

    get emRodadas() {
        return emRodadas(this.painel);
    }

    get locale() {
        return this.intl.primaryLocale ?? 'pt-BR';
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

    // rodadas, em ofertas: "Volta 2 · rodada 1 · até 6 km" (sem o raio, só a volta e a rodada)
    get rodadaTexto() {
        if (!this.emRodadas || !this.emOfertas) {
            return null;
        }
        const dados = { volta: inteiroPositivo(this.painel?.volta), rodada: inteiroPositivo(this.painel?.rodada) };
        const km = kmTexto(this.painel?.raio_m, this.locale);

        return km
            ? this.intl.t('fleet-ops.ui.distribuicao.rodada-com-raio', { ...dados, km })
            : this.intl.t('fleet-ops.ui.distribuicao.rodada-sem-raio', dados);
    }

    // rodadas, em ofertas: a lista "Novos pedidos" aberta a todos (desde quando) ou só para quem recebe a oferta
    get listaTexto() {
        if (!this.emRodadas || !this.emOfertas) {
            return null;
        }
        if (!this.painel?.lista_aberta_em) {
            return this.intl.t('fleet-ops.ui.distribuicao.lista-fechada');
        }

        return this.intl.t('fleet-ops.ui.distribuicao.lista-aberta', { hora: horaCurta(this.painel.lista_aberta_em, this.locale) ?? '—' });
    }

    get segundos() {
        return segundosRestantes(this.painel?.oferta?.vence_em, this.agora);
    }

    get fila() {
        return (this.painel?.fila ?? []).map((item, index) => ({
            posicao: index + 1,
            nome: item.nome || '—',
            tempo: this.tempoTexto(item.tempo_s),
            // com rodadas não há encaixe ("termina tudo e depois vai"): o "no caminho" some
            encaixe: !this.emRodadas && item.encaixe === true,
            aproximado: item.aproximado === true,
            livre: item.livre === true,
        }));
    }

    // sem rodadas: a lista corrida de antes
    get historico() {
        return (this.painel?.historico ?? []).map((oferta) => this.linhaDoHistorico(oferta));
    }

    // com rodadas: "Volta N" e as ofertas dela, cada uma com a rodada e o raio
    get historicoPorVolta() {
        return agruparPorVolta(this.painel?.historico).map((grupo) => ({
            titulo: this.intl.t('fleet-ops.ui.distribuicao.volta', { volta: grupo.volta }),
            ofertas: grupo.ofertas.map((oferta) => this.linhaDoHistorico(oferta)),
        }));
    }

    get podeAbrir() {
        return podeAbrir(this.painel, this.args.resource?.status);
    }

    get textosDoBotao() {
        return textosDoBotao(this.painel);
    }

    get botaoTexto() {
        return this.intl.t(`fleet-ops.ui.distribuicao.${this.textosDoBotao.botao}`);
    }

    abaOculta() {
        return typeof document !== 'undefined' && document.hidden === true;
    }

    tempoTexto(segundos) {
        const valor = minutos(segundos);

        return valor === null ? '—' : this.intl.t('fleet-ops.ui.distribuicao.minutos', { minutos: valor });
    }

    linhaDoHistorico(oferta) {
        return {
            posicao: oferta.posicao,
            motoboy: oferta.motoboy || '—',
            tempo: this.tempoTexto(oferta.tempo_estimado_s),
            aproximado: oferta.aproximado === true,
            resposta: this.intl.t(`fleet-ops.ui.distribuicao.resposta.${chaveDaResposta(oferta.resposta)}`),
            rodada: this.rodadaDaLinha(oferta),
        };
    }

    // rodadas: "rodada 2 · até 9 km" em cada linha do histórico (null sem rodadas)
    rodadaDaLinha(oferta) {
        if (!this.emRodadas) {
            return null;
        }
        const rodada = inteiroPositivo(oferta?.rodada);
        const km = kmTexto(oferta?.raio_m, this.locale);

        return km
            ? this.intl.t('fleet-ops.ui.distribuicao.historico-rodada-com-raio', { rodada, km })
            : this.intl.t('fleet-ops.ui.distribuicao.historico-rodada-sem-raio', { rodada });
    }

    /**
     * O Glimmer reaproveita o componente quando o @resource muda: carrega na entrada e a cada mudança de id, status ou
     * atualização do pedido. Trocou de pedido: zera o painel antes, para não mostrar os dados do anterior.
     */
    @action recarregar() {
        const id = this.id;
        if (id !== this.ultimoId) {
            this.ultimoId = id;
            this.painel = null;
            this.erro = false;
        }
        if (this.ehAdmin) {
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
        this.agora = Date.now();
        // em ofertas: cronômetro a cada segundo; releitura a cada 5 s e no vencimento da oferta, com a aba visível
        let passos = 0;
        // o vence_em da oferta cujo vencimento já provocou uma releitura (uma vez por vencimento)
        let vencimentoRelido = null;
        while (this.painel?.fase === 'ofertas' && this.painel?.ligada !== false) {
            yield timeout(1000);
            this.agora = Date.now();
            passos += 1;
            const venceEm = this.painel?.oferta?.vence_em ?? null;
            const venceu = venceEm !== null && venceEm !== vencimentoRelido && this.segundos === 0;
            if (venceu) {
                vencimentoRelido = venceEm;
            }
            if ((passos >= 5 || venceu) && !this.abaOculta()) {
                passos = 0;
                try {
                    this.painel = yield this.fetch.get(`entregas/pedidos/${this.id}/distribuicao`);
                    this.agora = Date.now();
                } catch (error) {
                    // segue com o que tem; a próxima volta tenta de novo
                }
            }
        }
    }

    // "Abrir a todos agora" (sem rodadas) ou "Mostrar a todos agora" (rodadas): os textos são lidos no clique
    @action abrirATodos() {
        const textos = textosDoBotao(this.painel);
        this.modalsManager.confirm({
            title: this.intl.t(`fleet-ops.ui.distribuicao.${textos.titulo}`),
            body: this.intl.t(`fleet-ops.ui.distribuicao.${textos.texto}`),
            acceptButtonText: this.intl.t(`fleet-ops.ui.distribuicao.${textos.botao}`),
            acceptButtonIcon: textos.icone,
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    const painel = await this.fetch.post(`entregas/pedidos/${this.id}/distribuicao/abrir`);
                    // a releitura em curso (fase ofertas) não pode sobrescrever o painel novo com o antigo
                    this.carregar.cancelAll();
                    this.painel = painel;
                    this.notifications.success(this.intl.t(`fleet-ops.ui.distribuicao.${textos.feito}`));
                    modal.done();
                    // com rodadas a fase continua em ofertas: o laço de releitura volta a correr
                    if (painel?.fase === 'ofertas') {
                        this.carregar.perform();
                    }
                } catch (error) {
                    // 409: a lista já está aberta ou a distribuição saiu de ofertas; mostra o motivo e relê o painel
                    this.notifications.serverError(error);
                    modal.stopLoading();
                    this.carregar.perform();
                }
            },
        });
    }
}
```

O `this.carregar.perform()` depois do sucesso é necessário. Com rodadas, o "Mostrar a todos agora" deixa a distribuição em `ofertas`. O `cancelAll()` mataria o laço de releitura e o cronômetro, e o painel ficaria parado até o pedido mudar. Sem rodadas, a fase vira `aberta` e nada muda.

- [ ] **Step 2: Substituir o `distribuicao.hbs` inteiro**

```hbs
{{#if this.ehAdmin}}
    <div {{did-insert this.recarregar}} {{did-update this.recarregar @resource.id @resource.status @resource.updated_at}}>
        {{#if this.mostrar}}
            <ContentPanel @title={{t "fleet-ops.ui.distribuicao.painel-titulo"}} @isLoading={{or @isLoading (and this.carregar.isRunning (not this.painel))}} @open={{true}} @wrapperClass="bordered-top">
                {{#if this.erro}}
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.distribuicao.erro"}}</p>
                {{else if this.temDistribuicao}}
                    <div class="field-info-container field-vertical-container dashed-bottom">
                        <div class="field-name">{{t "fleet-ops.ui.distribuicao.situacao"}}</div>
                        <div class="field-value">{{this.faseTexto}}</div>
                    </div>
                    {{#if this.rodadaTexto}}
                        <div class="field-info-container field-vertical-container dashed-bottom">
                            <div class="field-name">{{t "fleet-ops.ui.distribuicao.rodada"}}</div>
                            <div class="field-value">{{this.rodadaTexto}}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{this.listaTexto}}</div>
                        </div>
                    {{/if}}
                    {{#if this.painel.oferta}}
                        <div class="field-info-container field-vertical-container dashed-bottom">
                            <div class="field-name">{{t "fleet-ops.ui.distribuicao.oferta-atual"}}</div>
                            <div class="field-value">{{t "fleet-ops.ui.distribuicao.oferta-texto" motoboy=this.painel.oferta.motoboy segundos=this.segundos}}</div>
                        </div>
                    {{/if}}
                    {{#if (and this.emOfertas this.fila.length)}}
                        <div class="field-info-container field-vertical-container dashed-bottom">
                            <div class="field-name">{{t "fleet-ops.ui.distribuicao.fila"}}</div>
                            <div class="field-value">
                                {{#each this.fila as |item|}}
                                    <div class="text-xs">
                                        {{item.posicao}}. {{item.nome}} ·
                                        {{#if item.aproximado}}<span class="text-gray-400 dark:text-gray-500" title={{t "fleet-ops.ui.distribuicao.aproximado"}}>≈</span>{{/if}}{{item.tempo}}
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
                                {{#if this.emRodadas}}
                                    {{#each this.historicoPorVolta as |grupo|}}
                                        <div class="text-xs font-semibold mt-1">{{grupo.titulo}}</div>
                                        {{#each grupo.ofertas as |oferta|}}
                                            <div class="text-xs">
                                                {{oferta.posicao}}. {{oferta.motoboy}} ·
                                                {{#if oferta.aproximado}}<span class="text-gray-400 dark:text-gray-500" title={{t "fleet-ops.ui.distribuicao.aproximado"}}>≈</span>{{/if}}{{oferta.tempo}}
                                                · {{oferta.resposta}} · {{oferta.rodada}}
                                            </div>
                                        {{/each}}
                                    {{/each}}
                                {{else}}
                                    {{#each this.historico as |oferta|}}
                                        <div class="text-xs">
                                            {{oferta.posicao}}. {{oferta.motoboy}} ·
                                            {{#if oferta.aproximado}}<span class="text-gray-400 dark:text-gray-500" title={{t "fleet-ops.ui.distribuicao.aproximado"}}>≈</span>{{/if}}{{oferta.tempo}}
                                            · {{oferta.resposta}}
                                        </div>
                                    {{/each}}
                                {{/if}}
                            </div>
                        </div>
                    {{/if}}
                    {{#if this.podeAbrir}}
                        <div class="mt-2">
                            <Button @type="warning" @size="xs" @icon={{this.textosDoBotao.icone}} @text={{this.botaoTexto}} @onClick={{this.abrirATodos}} @permission="fleet-ops update order" />
                        </div>
                    {{/if}}
                {{/if}}
            </ContentPanel>
        {{/if}}
    </div>
{{/if}}
```

- [ ] **Step 3: Parse do JS com o Babel do console**

Run (em `C:/tmp/dr`, com a junção da Task 2):
```bash
node -e "const fs=require('fs');const d=fs.readdirSync('console/node_modules/.pnpm').find(n=>n.startsWith('@babel+parser@7'));const p=require('./console/node_modules/.pnpm/'+d+'/node_modules/@babel/parser');for(const f of ['packages/fleetops/addon/utils/distribuicao.js','packages/fleetops/addon/components/order/details/distribuicao.js']){p.parse(fs.readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties']});console.log('ok',f)}"
```
Expected: `ok packages/fleetops/addon/utils/distribuicao.js` e `ok packages/fleetops/addon/components/order/details/distribuicao.js`.

- [ ] **Step 4: i18n e testes**

Run (em `C:/tmp/dr`):
```bash
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit=$?"
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/distribuicao.test.mjs
```
Expected:
- `erros de sintaxe: 0` no fleetops e `exit=0`. As chaves literais novas do componente (`rodada`, `rodada-com-raio`, `rodada-sem-raio`, `lista-aberta`, `lista-fechada`, `volta`, `historico-rodada-com-raio`, `historico-rodada-sem-raio`) existem nos dois YAML.
- Testes: PASS.

- [ ] **Step 5: Commit**

```bash
cd /c/tmp/dr && git rev-parse --show-toplevel && git branch --show-current
git add packages/fleetops/addon/components/order/details/distribuicao.js packages/fleetops/addon/components/order/details/distribuicao.hbs
git commit -m "$(cat <<'EOF'
Distribuição em rodadas: painel com volta, rodada, raio, lista aberta e Mostrar a todos agora

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: App: funções puras do Dispensar de pedido em distribuição

**Files:**
- Modify: `C:/tmp/drapp/src/utils/oferta.ts` (acrescentar no fim)
- Test: `C:/tmp/drapp/scripts/testes/oferta.teste.ts` (trocar a linha do import e acrescentar dois testes no fim)

- [ ] **Step 1: Escrever os testes**

No `scripts/testes/oferta.teste.ts`, troque a linha do import:

```ts
import { ofertaDoPedido, ehOferta, segundosRestantes, comOfertaPrimeiro, recusarPeloAlarme, prazoLocal, segundosAte, recusaSemOferta, prazoDoPush, prazoDaTela } from '../../src/utils/oferta.ts';
```

por:

```ts
import { ofertaDoPedido, ehOferta, segundosRestantes, comOfertaPrimeiro, recusarPeloAlarme, prazoLocal, segundosAte, recusaSemOferta, prazoDoPush, prazoDaTela, ehDistribuido, desfechoDaDispensa } from '../../src/utils/oferta.ts';
```

e acrescente no fim do arquivo:

```ts
test('pedido em distribuição: a marca da lista ou a oferta dele', () => {
    assert.equal(ehDistribuido(recurso({ id: 'order_1', entregas_distribuicao: true })), true);
    assert.equal(ehDistribuido({ id: 'order_1', entregas_distribuicao: true }), true);
    assert.equal(ehDistribuido({ id: 'order_1', entregas_oferta: { vence_em: '2026-10-07T10:00:20-03:00', segundos_restantes: 20 } }), true, 'a oferta também é distribuição');
    assert.equal(ehDistribuido({ id: 'order_1', entregas_distribuicao: false }), false);
    assert.equal(ehDistribuido({ id: 'order_1', entregas_distribuicao: 'true' }), false, 'só o booleano do servidor');
    assert.equal(ehDistribuido(recurso({ id: 'order_1' })), false);
    assert.equal(ehDistribuido({ id: 'order_1' }), false);
    assert.equal(ehDistribuido(null), false);
    assert.equal(ehDistribuido(undefined), false);
});

test('desfecho do Dispensar no servidor: dispensado, sumiu (409) ou falhou', () => {
    assert.equal(desfechoDaDispensa(null), 'dispensado', 'sem erro: 200 recusada ou dispensada');
    assert.equal(desfechoDaDispensa(undefined), 'dispensado');
    assert.equal(desfechoDaDispensa({ status: 409 }), 'sumiu');
    assert.equal(desfechoDaDispensa({ response: { status: 409 } }), 'sumiu');
    assert.equal(desfechoDaDispensa(new Error('Esta oferta não está mais com você.')), 'sumiu', 'o SDK só repassa a mensagem');
    assert.equal(desfechoDaDispensa({ status: 503, message: 'O pedido está sendo atualizado. Tente de novo.' }), 'falhou');
    assert.equal(desfechoDaDispensa(new Error('Network request failed')), 'falhou');
    assert.equal(desfechoDaDispensa({}), 'falhou');
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run (em `C:/tmp/drapp`): `node --experimental-strip-types --test scripts/testes/oferta.teste.ts`
Expected: FAIL. O módulo não exporta `ehDistribuido` nem `desfechoDaDispensa` ("does not provide an export named").

- [ ] **Step 3: Implementar (acrescentar no fim de `src/utils/oferta.ts`)**

```ts
/**
 * Distribuição em rodadas: o pedido está em distribuição no servidor. A lista "Novos pedidos" marca todo pedido em
 * distribuição com `entregas_distribuicao: true` (só com ENTREGAS_DISTRIBUICAO_RODADAS ligada); a oferta dele também
 * conta. O Dispensar desses pedidos avisa o servidor (POST v1/entregas/motoboy/pedidos/{id}/recusar), que tira o pedido
 * da lista dele e não o oferece a ele até a volta seguinte.
 */
export function ehDistribuido(pedido: any): boolean {
    return ler(pedido, 'entregas_distribuicao') === true || ehOferta(pedido);
}

export type DesfechoDaDispensa = 'dispensado' | 'sumiu' | 'falhou';

/**
 * O que o app faz depois do Dispensar no servidor, pelo erro da chamada (null/undefined = 200 `recusada` ou
 * `dispensada`):
 * - dispensado: o pedido sai da lista local, sem ir para os dispensados (o servidor o devolve na volta seguinte);
 * - sumiu: 409 ("Esta oferta não está mais com você.": lista fechada, fora de ofertas, já com motoboy). Some sem aviso
 *   e vai para os dispensados locais, para não voltar a cada recarga;
 * - falhou: rede, 503 e o resto. Avisa e o pedido fica.
 */
export function desfechoDaDispensa(erro?: any): DesfechoDaDispensa {
    if (erro === null || erro === undefined) return 'dispensado';
    return recusaSemOferta(erro) ? 'sumiu' : 'falhou';
}
```

- [ ] **Step 4: Rodar e ver passar**

Run (em `C:/tmp/drapp`): `node --experimental-strip-types --test scripts/testes/oferta.teste.ts`
Expected: PASS, `# fail 0`.

- [ ] **Step 5: Commit**

```bash
cd /c/tmp/drapp && git rev-parse --show-toplevel && git branch --show-current
git add src/utils/oferta.ts scripts/testes/oferta.teste.ts
git commit -m "$(cat <<'EOF'
Distribuição em rodadas: funções do Dispensar de pedido em distribuição (marca da lista e desfecho no servidor)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: App: a oferta dele aparece mesmo dispensada localmente

**Files:**
- Modify: `C:/tmp/drapp/src/utils/lista-de-pedidos.ts` (função `secoesDaLista`)
- Test: `C:/tmp/drapp/scripts/testes/lista-de-pedidos.teste.ts` (acrescentar um teste no fim)

- [ ] **Step 1: Escrever o teste (acrescentar no fim do arquivo)**

```ts
test('seções: o dispensado localmente não esconde a oferta dele (volta seguinte da distribuição)', () => {
    const aberto = { id: 'order_a', adhoc: true, driver_assigned: null, entregas_distribuicao: true };
    const oferta = { id: 'order_b', adhoc: true, driver_assigned: null, entregas_distribuicao: true, entregas_oferta: { vence_em: '2026-10-07T10:00:20-03:00' } };
    assert.deepEqual(secoesDaLista([aberto, oferta], [], ['order_a', 'order_b']), [{ chave: 'novos', data: [oferta] }]);
    const ofertaRecurso = recurso({ id: 'order_c', adhoc: true, driver_assigned: null, entregas_oferta: { vence_em: '2026-10-07T10:00:20-03:00' } });
    assert.deepEqual(secoesDaLista([ofertaRecurso], [], ['order_c']), [{ chave: 'novos', data: [ofertaRecurso] }]);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run (em `C:/tmp/drapp`): `node --experimental-strip-types --test scripts/testes/lista-de-pedidos.teste.ts`
Expected: FAIL no teste novo. A seção vem vazia (`[]`) porque o `order_b` está nos dispensados.

- [ ] **Step 3: Implementar**

Em `src/utils/lista-de-pedidos.ts`, troque:

```ts
/**
 * As seções da aba Pedidos: "novos" (abertos por perto, menos os dispensados e os que já são dele; a oferta dele no topo) e "andamento" (os dele,
 * não encerrados). Seção vazia não entra; as duas vazias = lista vazia (a tela mostra "Nenhum pedido no momento").
 */
export function secoesDaLista<T>(proximos: T[], ativos: T[], dispensados: string[] = []): SecaoDaLista<T>[] {
    const emAndamento = (ativos ?? []).filter((pedido) => !pedidoEncerrado(pedido));
    const meus = new Set(emAndamento.map(idDe));
    const abertos = (proximos ?? []).filter((pedido) => ehPedidoAberto(pedido) && !dispensados.includes(idDe(pedido)) && !meus.has(idDe(pedido)));
```

por:

```ts
/**
 * As seções da aba Pedidos: "novos" (abertos por perto, menos os dispensados e os que já são dele; a oferta dele no topo) e "andamento" (os dele,
 * não encerrados). Seção vazia não entra; as duas vazias = lista vazia (a tela mostra "Nenhum pedido no momento").
 * A oferta dele aparece mesmo dispensada localmente: o Dispensar de um pedido em distribuição que voltou 409 grava o
 * pedido nos dispensados locais, e a distribuição em rodadas pode oferecê-lo a ele de novo numa volta seguinte.
 */
export function secoesDaLista<T>(proximos: T[], ativos: T[], dispensados: string[] = []): SecaoDaLista<T>[] {
    const emAndamento = (ativos ?? []).filter((pedido) => !pedidoEncerrado(pedido));
    const meus = new Set(emAndamento.map(idDe));
    const abertos = (proximos ?? []).filter(
        (pedido) => ehPedidoAberto(pedido) && (temOferta(pedido) || !dispensados.includes(idDe(pedido))) && !meus.has(idDe(pedido))
    );
```

O resto da função fica igual.

- [ ] **Step 4: Rodar e ver passar**

Run (em `C:/tmp/drapp`): `node --experimental-strip-types --test scripts/testes/oferta.teste.ts scripts/testes/lista-de-pedidos.teste.ts`
Expected: PASS, `# fail 0`. O teste antigo `secoesDaLista([dispensado], [concluido], ['order_b'])` continua dando `[]`, porque o dispensado não tem oferta.

- [ ] **Step 5: Commit**

```bash
cd /c/tmp/drapp && git rev-parse --show-toplevel && git branch --show-current
git add src/utils/lista-de-pedidos.ts scripts/testes/lista-de-pedidos.teste.ts
git commit -m "$(cat <<'EOF'
Distribuição em rodadas: a oferta aparece em Novos pedidos mesmo com o pedido dispensado no celular

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: App: textos do Dispensar de pedido em distribuição

**Files:**
- Modify: `C:/tmp/drapp/translations/pt.json` (bloco `AdhocOrderCard`, linha ~277)
- Modify: `C:/tmp/drapp/translations/en.json` (bloco `AdhocOrderCard`, linha ~277)

- [ ] **Step 1: pt.json**

Troque:

```json
        "recusaFalhou": "Não foi possível recusar a oferta."
```

por:

```json
        "recusaFalhou": "Não foi possível recusar a oferta.",
        "dismissMessageDistribuido": "O pedido sai da sua lista por enquanto. Se ninguém aceitar, ele pode voltar para você.",
        "dispensando": "Dispensando o pedido...",
        "dispensaFalhou": "Não foi possível dispensar o pedido."
```

- [ ] **Step 2: en.json**

Troque:

```json
        "recusaFalhou": "Could not decline the offer."
```

por:

```json
        "recusaFalhou": "Could not decline the offer.",
        "dismissMessageDistribuido": "The order leaves your list for now. If nobody takes it, it may come back to you.",
        "dispensando": "Dismissing the order...",
        "dispensaFalhou": "Could not dismiss the order."
```

- [ ] **Step 3: Validar o JSON**

Run (em `C:/tmp/drapp`):
```bash
node -e "for (const f of ['translations/pt.json','translations/en.json']) { const j = JSON.parse(require('fs').readFileSync(f,'utf8')); for (const k of ['dismissMessageDistribuido','dispensando','dispensaFalhou']) if (!j.AdhocOrderCard[k]) throw new Error(f+' sem '+k); console.log('ok', f); }"
```
Expected: `ok translations/pt.json` e `ok translations/en.json`.

- [ ] **Step 4: Commit**

```bash
cd /c/tmp/drapp && git rev-parse --show-toplevel && git branch --show-current
git add translations/pt.json translations/en.json
git commit -m "$(cat <<'EOF'
Distribuição em rodadas: textos do Dispensar de pedido em distribuição

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: App: Dispensar do card avisa o servidor

**Files:**
- Modify: `C:/tmp/drapp/src/components/AdhocOrderCard.tsx`

A lógica que dá para testar está nas Tasks 4 e 5. Aqui a verificação é o parse do TSX e os testes.

- [ ] **Step 1: Import**

Troque:

```tsx
import { ofertaDoPedido, prazoLocal, segundosAte, recusaSemOferta } from '../utils/oferta';
```

por:

```tsx
import { ofertaDoPedido, prazoLocal, segundosAte, recusaSemOferta, ehDistribuido, desfechoDaDispensa } from '../utils/oferta';
```

- [ ] **Step 2: Estado e marca**

Troque:

```tsx
    const [isRefusing, setIsRefusing] = useState(false);
    const ofertaVencida = Boolean(oferta) && segundos <= 0;
```

por:

```tsx
    const [isRefusing, setIsRefusing] = useState(false);
    // Entregas: distribuição em rodadas. Pedido em distribuição (entregas_distribuicao da lista, ou a oferta dele): o
    // Dispensar avisa o servidor (dispensarNoServidor)
    const [isDispensing, setIsDispensing] = useState(false);
    const distribuido = ehDistribuido(order);
    const ofertaVencida = Boolean(oferta) && segundos <= 0;
```

- [ ] **Step 3: `dispensarNoServidor` e o novo `handleDismiss`**

Troque o bloco inteiro do `handleDismiss` de hoje:

```tsx
    const handleDismiss = useCallback(() => {
        if (oferta) {
            if (ofertaVencida || aceitandoRef.current || recusandoRef.current) return;
            recusarOferta();
            return;
        }
        Alert.alert(t('AdhocOrderCard.dismissTitle'), t('AdhocOrderCard.dismissMessage'), [
            {
                text: t('common.cancel'),
                style: 'cancel',
            },
            {
                text: t('common.ok'),
                onPress: () => {
                    if (typeof onDismiss === 'function') {
                        onDismiss(order);
                    }
                },
            },
        ]);
    }, [order, oferta, ofertaVencida, recusarOferta, onDismiss, t]);
```

por:

```tsx
    // Entregas: distribuição em rodadas. Dispensar um pedido em distribuição avisa o servidor pela rota da recusa (POST
    // v1/entregas/motoboy/pedidos/{id}/recusar; 200 recusada ou dispensada): ele some da lista deste motoboy e não lhe é
    // oferecido até a volta seguinte. No sucesso, o pedido sai só da lista local, sem ir para os dispensados (o servidor o
    // devolve na volta seguinte). 409 (lista fechada, fora de ofertas, já com motoboy): some sem aviso e vai para os
    // dispensados locais (onDismiss), para não voltar a cada recarga; a oferta dele continua aparecendo (secoesDaLista).
    // Rede e 503: avisa e o pedido fica. A guarda de toque duplo é a mesma do Recusar (recusandoRef).
    const dispensarNoServidor = useCallback(async () => {
        if (recusandoRef.current || aceitandoRef.current) return;
        recusandoRef.current = true;
        setIsDispensing(true);
        let erro = null;
        try {
            await adapter.post(`entregas/motoboy/pedidos/${order.id}/recusar`);
        } catch (err) {
            console.warn('[distribuição] dispensar:', err);
            erro = err;
        }
        recusandoRef.current = false;
        setIsDispensing(false);
        const desfecho = desfechoDaDispensa(erro);
        if (desfecho === 'falhou') {
            toast.error(erro?.message || t('AdhocOrderCard.dispensaFalhou'));
            return;
        }
        if (desfecho === 'sumiu' && typeof onDismiss === 'function') {
            onDismiss(order);
        }
        removerPedidoProximo?.(order.id);
        reloadNearbyOrders({}, { setLoadingFlag: false });
    }, [adapter, order, onDismiss, removerPedidoProximo, reloadNearbyOrders, t]);

    const handleDismiss = useCallback(() => {
        if (oferta) {
            if (ofertaVencida || aceitandoRef.current || recusandoRef.current) return;
            recusarOferta();
            return;
        }
        if (distribuido && (aceitandoRef.current || recusandoRef.current)) return;
        Alert.alert(t('AdhocOrderCard.dismissTitle'), distribuido ? t('AdhocOrderCard.dismissMessageDistribuido') : t('AdhocOrderCard.dismissMessage'), [
            {
                text: t('common.cancel'),
                style: 'cancel',
            },
            {
                text: t('common.ok'),
                onPress: () => {
                    if (distribuido) {
                        dispensarNoServidor();
                        return;
                    }
                    if (typeof onDismiss === 'function') {
                        onDismiss(order);
                    }
                },
            },
        ]);
    }, [order, oferta, ofertaVencida, distribuido, recusarOferta, dispensarNoServidor, onDismiss, t]);
```

- [ ] **Step 4: Overlay e botões**

Troque:

```tsx
            <LoadingOverlay isVisible={isAccepting || isRefusing} text={isRefusing ? t('AdhocOrderCard.recusando') : t('AdhocOrderCard.accepting')} />
```

por:

```tsx
            <LoadingOverlay
                isVisible={isAccepting || isRefusing || isDispensing}
                text={isRefusing ? t('AdhocOrderCard.recusando') : isDispensing ? t('AdhocOrderCard.dispensando') : t('AdhocOrderCard.accepting')}
            />
```

Nos **dois** botões (Aceitar e Dispensar), troque:

```tsx
                                disabled={isAccepting || isRefusing || ofertaVencida}
```

por:

```tsx
                                disabled={isAccepting || isRefusing || isDispensing || ofertaVencida}
```

São duas ocorrências iguais: use o Edit com `replace_all: true`.

No botão Dispensar, troque:

```tsx
                                <Button.Icon>{isRefusing ? <Spinner color='$errorText' /> : <FontAwesomeIcon icon={faBan} color={theme['$errorText'].val} />}</Button.Icon>
```

por:

```tsx
                                <Button.Icon>{isRefusing || isDispensing ? <Spinner color='$errorText' /> : <FontAwesomeIcon icon={faBan} color={theme['$errorText'].val} />}</Button.Icon>
```

- [ ] **Step 5: Parse do TSX e testes**

Run (em `C:/tmp/drapp`):
```bash
node -e "const fs=require('fs');const base='C:/Users/Edgardjr/Documents/vibe coding/Delivery/console/node_modules/.pnpm/';const d=fs.readdirSync(base).find(n=>n.startsWith('@babel+parser@7'));const p=require(base+d+'/node_modules/@babel/parser');for(const f of ['src/components/AdhocOrderCard.tsx','src/utils/oferta.ts','src/utils/lista-de-pedidos.ts']){p.parse(fs.readFileSync(f,'utf8'),{sourceType:'module',plugins:['typescript','jsx']});console.log('ok',f)}"
node --experimental-strip-types --test scripts/testes/oferta.teste.ts scripts/testes/lista-de-pedidos.teste.ts
```
Expected: três `ok` e os testes com `# fail 0`.

- [ ] **Step 6: Commit**

```bash
cd /c/tmp/drapp && git rev-parse --show-toplevel && git branch --show-current
git add src/components/AdhocOrderCard.tsx
git commit -m "$(cat <<'EOF'
Distribuição em rodadas: Dispensar do card avisa o servidor nos pedidos em distribuição

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: App: Dispensar da tela do pedido avisa o servidor

**Files:**
- Modify: `C:/tmp/drapp/src/screens/OrderScreen.tsx`

- [ ] **Step 1: Import**

Troque:

```tsx
import { prazoDaTela, segundosAte, recusaSemOferta } from '../utils/oferta';
```

por:

```tsx
import { prazoDaTela, segundosAte, recusaSemOferta, ehDistribuido, desfechoDaDispensa } from '../utils/oferta';
```

- [ ] **Step 2: A marca de distribuição da tela**

Troque:

```tsx
    const modoOferta = emOferta && !ofertaVencida;
```

por:

```tsx
    const modoOferta = emOferta && !ofertaVencida;
    // Entregas: distribuição em rodadas. Pedido em distribuição no servidor: a marca da lista (entregas_distribuicao, no
    // pedido serializado pelo card) ou uma oferta nesta tela (do push, cujo findRecord não traz a marca, ou do card). O
    // Dispensar dele avisa o servidor (dispensarNoServidor). Sem rodadas, com a oferta vencida, o servidor responde 409 e
    // o resultado é o de antes (dispensado no celular e a tela fecha)
    const distribuido = ehDistribuido(params.order) || Number.isFinite(prazoOferta);
```

- [ ] **Step 3: `dispensarNoServidor` e o novo `handleAdhocDismissal`**

Troque o bloco inteiro do `handleAdhocDismissal` de hoje:

```tsx
    const handleAdhocDismissal = useCallback(() => {
        if (modoOferta) {
            if (aceitandoRef.current || recusandoRef.current) {
                return;
            }
            recusarOferta();
            return;
        }
        Alert.alert(t('OrderScreen.dismissAdhocTitle'), t('OrderScreen.dismissAdhocMessage'), [
            {
                text: t('common.cancel'),
                style: 'cancel',
            },
            {
                text: t('common.ok'),
                onPress: () => {
                    setDimissedOrders((prevDismissedOrders) => [...prevDismissedOrders, order.id]);
                    navigation.goBack();
                },
            },
        ]);
    }, [order, setDimissedOrders, modoOferta, recusarOferta]);
```

por:

```tsx
    // Entregas: distribuição em rodadas. Dispensar um pedido em distribuição avisa o servidor pela rota da recusa (200
    // recusada ou dispensada): ele some da lista deste motoboy e não lhe é oferecido até a volta seguinte. No sucesso, sai
    // só da lista local (sem ir para os dispensados: o servidor o devolve na volta seguinte) e a tela fecha. 409: some sem
    // aviso, vai para os dispensados locais (não volta a cada recarga; a oferta dele continua aparecendo) e a tela fecha.
    // Rede e 503: avisa e fica na tela, para tentar de novo. Usa o mesmo estado e a mesma guarda do Recusar
    const dispensarNoServidor = useCallback(async () => {
        if (recusandoRef.current || aceitandoRef.current) {
            return;
        }
        recusandoRef.current = true;
        setIsRefusing(true);
        let erro = null;
        try {
            await adapter.post(`entregas/motoboy/pedidos/${order.id}/recusar`);
        } catch (err) {
            console.warn('[distribuição] dispensar:', err);
            erro = err;
        }
        recusandoRef.current = false;
        setIsRefusing(false);
        const desfecho = desfechoDaDispensa(erro);
        if (desfecho === 'falhou') {
            toast.error(erro?.message || t('AdhocOrderCard.dispensaFalhou'));
            return;
        }
        if (desfecho === 'sumiu') {
            setDimissedOrders((prevDismissedOrders) => [...prevDismissedOrders, order.id]);
        }
        removerPedidoProximo?.(order.id);
        reloadNearbyOrders({}, { setLoadingFlag: false });
        // a tela fecha: o alarme dela para na desmontagem
        navigation.goBack();
    }, [adapter, order, setDimissedOrders, removerPedidoProximo, reloadNearbyOrders, navigation, t]);

    const handleAdhocDismissal = useCallback(() => {
        if (modoOferta) {
            if (aceitandoRef.current || recusandoRef.current) {
                return;
            }
            recusarOferta();
            return;
        }
        if (distribuido && (aceitandoRef.current || recusandoRef.current)) {
            return;
        }
        Alert.alert(t('OrderScreen.dismissAdhocTitle'), distribuido ? t('AdhocOrderCard.dismissMessageDistribuido') : t('OrderScreen.dismissAdhocMessage'), [
            {
                text: t('common.cancel'),
                style: 'cancel',
            },
            {
                text: t('common.ok'),
                onPress: () => {
                    if (distribuido) {
                        dispensarNoServidor();
                        return;
                    }
                    setDimissedOrders((prevDismissedOrders) => [...prevDismissedOrders, order.id]);
                    navigation.goBack();
                },
            },
        ]);
    }, [order, setDimissedOrders, modoOferta, recusarOferta, distribuido, dispensarNoServidor, navigation, t]);
```

O botão Dispensar já fica desabilitado e com o spinner pelo `isRefusing` (linhas ~811 a 824). Nada muda no render.

- [ ] **Step 4: Parse do TSX e testes**

Run (em `C:/tmp/drapp`):
```bash
node -e "const fs=require('fs');const base='C:/Users/Edgardjr/Documents/vibe coding/Delivery/console/node_modules/.pnpm/';const d=fs.readdirSync(base).find(n=>n.startsWith('@babel+parser@7'));const p=require(base+d+'/node_modules/@babel/parser');for(const f of ['src/screens/OrderScreen.tsx','src/components/AdhocOrderCard.tsx']){p.parse(fs.readFileSync(f,'utf8'),{sourceType:'module',plugins:['typescript','jsx']});console.log('ok',f)}"
node --experimental-strip-types --test scripts/testes/oferta.teste.ts scripts/testes/lista-de-pedidos.teste.ts
```
Expected: dois `ok` e os testes com `# fail 0`.

- [ ] **Step 5: Conferir o diff contra o contrato**

Run (em `C:/tmp/drapp`): `git diff --stat main...HEAD; git diff HEAD -- src/screens/OrderScreen.tsx | grep -n "recusar\|dispens"`

Confira:
- a única rota nova chamada é `entregas/motoboy/pedidos/${order.id}/recusar`;
- no sucesso, nada é gravado em `setDimissedOrders`;
- o Recusar da oferta (`recusarOferta`) não mudou.

- [ ] **Step 6: Commit**

```bash
cd /c/tmp/drapp && git rev-parse --show-toplevel && git branch --show-current
git add src/screens/OrderScreen.tsx
git commit -m "$(cat <<'EOF'
Distribuição em rodadas: Dispensar da tela do pedido avisa o servidor nos pedidos em distribuição

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: Documentação: "Painel do console" e "APK" no CLAUDE.md

**Files:**
- Modify: `C:/tmp/dr/CLAUDE.md`. **Só** as subseções `### Painel do console` e `### APK` da seção "Distribuição de pedidos abertos (oferta um a um)". O plano da API mexe no resto da seção, em paralelo.

Faça esta tarefa por último. Use o Edit com os trechos exatos abaixo, nunca reescreva o arquivo: o outro agente pode ter commitado mudanças no mesmo arquivo. Se algum `old_string` não bater (o outro plano mexeu ali), releia só a subseção e aplique a mesma mudança no texto atual.

- [ ] **Step 1: "Painel do console": conteúdo e rodadas**

Troque:

```markdown
- **Conteúdo:** fase e motivo, oferta atual com o cronômetro, a fila calculada (**só em ofertas**: tempo até o cliente, "no caminho", "já leva pedido", "≈" na linha reta) e o histórico das ofertas.
```

por:

```markdown
- **Conteúdo:** fase e motivo, oferta atual com o cronômetro, a fila calculada (**só em ofertas**: tempo até o cliente, "no caminho" (só sem rodadas), "já leva pedido", "≈" na linha reta) e o histórico das ofertas.
- **Com rodadas** (`rodadas: true` na resposta, `ENTREGAS_DISTRIBUICAO_RODADAS=1`):
  - em ofertas, a linha "Volta N · rodada M · até X km" e, embaixo, "Lista aberta desde HH:MM" (hora do navegador, 24 h) ou "Lista só para quem recebe a oferta";
  - a oferta conta os 20 s pelo `vence_em`;
  - a fila sai sem "no caminho";
  - o histórico vem agrupado por volta ("Volta N"), com "rodada M · até X km" em cada linha e as respostas novas "dispensou pela lista" e "aceitou pela lista".
  - Funções puras: `emRodadas`, `inteiroPositivo`, `kmTexto`, `horaCurta`, `agruparPorVolta` e `textosDoBotao`.
  - Sem `rodadas` (chave desligada ou API antiga), o painel é o de antes.
```

- [ ] **Step 2: "Painel do console": o botão**

Troque:

```markdown
- **"Abrir a todos agora"** (`POST .../distribuicao/abrir`, com confirmação): só em ofertas, com a distribuição ligada e o pedido não encerrado (`podeAbrir`). O 409 (a distribuição já saiu de ofertas) mostra a mensagem do servidor e relê o painel.
```

por:

```markdown
- **O botão** (`POST .../distribuicao/abrir`, com confirmação): só em ofertas, com a distribuição ligada e o pedido não encerrado (`podeAbrir`).
  - Sem rodadas: **"Abrir a todos agora"** (alarme a todos no raio).
  - Com rodadas: **"Mostrar a todos agora"**. Grava a `lista_aberta_em`: o pedido aparece na lista de todos até 2R, sem alarme, e as ofertas continuam. Só aparece com a lista ainda fechada. Depois do sucesso, o painel volta a reler, porque a fase continua em ofertas.
  - O 409 (a lista já aberta, ou a distribuição saiu de ofertas) mostra a mensagem do servidor e relê o painel.
```

- [ ] **Step 3: "APK": ramo**

Troque:

```markdown
Ramo `distribuicao-de-pedidos` do `entregas-navigator` (APK do push na `main` depois do merge). Funções puras em `src/utils/oferta.ts`.
```

por:

```markdown
Ramos `distribuicao-de-pedidos` e `distribuicao-rodadas` do `entregas-navigator` (APK do push na `main` depois do merge; as rodadas pedem o APK 30). Funções puras em `src/utils/oferta.ts`.
```

- [ ] **Step 4: "APK": Dispensar de pedido em distribuição**

Troque:

```markdown
  - Vencida (inclusive a que já chega vencida): botões desabilitados e a lista recarrega.
```

por:

```markdown
  - Vencida (inclusive a que já chega vencida): botões desabilitados e a lista recarrega.
  - Os 20 s das rodadas vêm do servidor (`segundos_restantes`, `entregas_oferta_segundos`): o app não tem prazo fixo.
- **Dispensar de pedido em distribuição** (rodadas): vale para todo pedido que a lista marca com `entregas_distribuicao: true`; na tela do pedido, conta também a oferta vinda do push ou do card (`ehDistribuido`).
  - No card e na tela do pedido, a confirmação de sempre, com texto próprio, e depois `POST .../recusar` (200 `recusada` ou `dispensada`).
  - Sucesso: o pedido sai da lista local, **sem ir para os dispensados**, e a lista recarrega (o servidor o devolve na volta seguinte).
  - 409: some sem aviso e vai para os dispensados locais, para não voltar a cada recarga. A oferta dele continua aparecendo: o `secoesDaLista` não esconde pedido com `entregas_oferta`.
  - Rede e 503: avisa e o pedido fica.
  - O desfecho sai do `desfechoDaDispensa`. A recusa local de 2 h do cartão nativo não vale para esses pedidos.
```

- [ ] **Step 5: "APK": cartão nativo e APK anterior**

Troque:

```markdown
  - **Recusar** abre o app com `entregas_recusar=1` (com o celular bloqueado, pede o desbloqueio, como o Aceitar), e o `DriverLayout` chama a rota de recusa. O Kotlin não tem o token nem cliente HTTP. Sem desbloquear, a oferta vence sozinha em 30 s.
```

por:

```markdown
  - **Recusar** abre o app com `entregas_recusar=1` (com o celular bloqueado, pede o desbloqueio, como o Aceitar), e o `DriverLayout` chama a rota de recusa (qualquer 2xx, `recusada` ou `dispensada`, vale como recusa). O Kotlin não tem o token nem cliente HTTP. Sem desbloquear, a oferta vence sozinha no prazo dela (30 s; 20 s com rodadas).
  - Com rodadas, o Kotlin não mudou: só chegam pushes de oferta, porque sai o `OrderPing` a todos. Caso de borda: um pedido recusado localmente no cartão de pedido aberto comum (ciclo antigo ou motivo `falha`) não toca por 2 h, nem como oferta.
```

E troque:

```markdown
  - por isso: **ligue primeiro num teste controlado** (loja de teste, motoboys avisados) e de vez só com o APK novo em todos os celulares.
```

por:

```markdown
  - por isso: **ligue primeiro num teste controlado** (loja de teste, motoboys avisados) e de vez só com o APK novo em todos os celulares.
- **APK anterior com as rodadas ligadas:** o cartão conta 3 min e segue tocando depois que a oferta de 20 s passou ao próximo (vários celulares tocam juntos). O Dispensar só esconde o pedido no celular: o servidor não fica sabendo e pode oferecê-lo de novo na mesma volta. Por isso, a chave das rodadas só liga com o APK 30 em todos.
```

- [ ] **Step 6: Conferir que só essas subseções mudaram**

Run (em `C:/tmp/dr`): `git diff -U0 CLAUDE.md | grep '^@@'`
Expected: todos os trechos dentro de `### Painel do console` e `### APK`, entre as linhas `### Painel do console` e `### Logs` da seção "Distribuição de pedidos abertos".

- [ ] **Step 7: Remover a junção do `node_modules` (se foi criada)**

Run (PowerShell): `if (Test-Path C:\tmp\dr\console\node_modules) { cmd /c rmdir "C:\tmp\dr\console\node_modules" }`
Expected: nada. **Nunca** use `Remove-Item -Recurse` aqui.

- [ ] **Step 8: Commit**

```bash
cd /c/tmp/dr && git rev-parse --show-toplevel && git branch --show-current
git add CLAUDE.md
git commit -m "$(cat <<'EOF'
Documentação: painel do console e APK da distribuição em rodadas

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

## A conferir no teste real (depois do deploy da API e do console e do APK 30)

- Painel:
  - "Volta 1 · rodada 1 · até 6 km" e "Lista só para quem recebe a oferta" logo no despacho;
  - na rodada 2, "Lista aberta desde HH:MM";
  - o cronômetro em 20 s;
  - o histórico com "Volta 1"/"Volta 2" e as linhas "dispensou pela lista"/"aceitou pela lista".
- "Mostrar a todos agora":
  - abre a lista sem alarme;
  - o botão some (a lista já está aberta);
  - o cronômetro segue correndo.
- App:
  - com a lista aberta, o Dispensar de um pedido sem oferta some da lista e não volta na mesma volta;
  - na volta seguinte, ele volta para quem dispensou;
  - sem rede, o aviso aparece e o pedido fica.
- Se o `@fleetbase/sdk` mantém o atributo `entregas_distribuicao` no recurso Order (o mesmo ponto em aberto do `entregas_oferta`). Se não mantiver, o Dispensar da lista volta ao comportamento antigo (só local), e o card precisa ler a marca do JSON cru da lista.

## Auto-revisão do plano (feita ao escrever)

- **Cobertura da spec e do contrato:**

  | Item | Onde |
  |---|---|
  | Seção 8, cabeçalho volta · rodada · raio | Tasks 1 a 3 |
  | Seção 8, lista aberta/fechada | Tasks 1 a 3 |
  | Seção 8, oferta de 20 s (pelo `vence_em`, sem mudança de código) | Task 3, comentário e teste do `segundosRestantes` |
  | Seção 8, fila sem "no caminho" | Task 3, `encaixe` |
  | Seção 8, histórico por volta com rodada e raio | Tasks 1 e 3 |
  | Seção 8, respostas novas | Tasks 1 e 2 |
  | Seção 8, "Mostrar a todos agora" e `podeAbrir` com a lista | Tasks 1 a 3 |
  | Seção 8, campos novos do GET | Task 3 lê `volta`, `rodada`, `raio_m`, `lista_aberta_em` e `rodadas` |
  | Seções 5 e 9, Dispensar → `recusar`, 409 sem aviso, rede/503 avisam | Tasks 4, 7 e 8 |
  | Seções 5 e 9, sem a recusa local de 2 h | O JS nunca grava a recusa local; o Kotlin não muda |
  | Seções 5 e 9, os 20 s do servidor | Sem mudança, conferido |
  | Seção 9, o Recusar do cartão nativo | "Conferido sem mudança" |
  | Seção 10, chave desligada = painel e app como hoje | `emRodadas` falso; sem `entregas_distribuicao` |
  | CLAUDE.md, só "Painel do console" e "APK" | Task 9 |

- **Placeholders:** nenhum "TBD"/"TODO". Todo passo de código traz o código, e todo comando traz a saída esperada.
- **Consistência de nomes:**
  - `emRodadas`, `inteiroPositivo`, `kmTexto`, `horaCurta`, `agruparPorVolta` e `textosDoBotao` (Task 1) são os mesmos importados na Task 3;
  - os sufixos de `textosDoBotao` (`mostrar`, `mostrar-titulo`, `mostrar-texto`, `mostrada`, `abrir`, `abrir-titulo`, `abrir-texto`, `aberto`) existem no YAML (Task 2 e chaves de antes);
  - `ehDistribuido` e `desfechoDaDispensa` (Task 4) são os usados nas Tasks 7 e 8;
  - as chaves `AdhocOrderCard.dismissMessageDistribuido`, `dispensando` e `dispensaFalhou` (Task 6) são as usadas nas Tasks 7 e 8.
- **Contagem do teste da Task 1:** 11 testes (chaves, segundos, minutos, rodadas, inteiro, km, hora, agrupar, podeAbrir, mostrar, textos).
