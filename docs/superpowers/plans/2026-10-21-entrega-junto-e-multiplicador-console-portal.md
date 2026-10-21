# Entrega junto e multiplicador: console e portal — plano de implementação

> **Para agentes:** SUB-SKILL OBRIGATÓRIA: use superpowers:subagent-driven-development (recomendado) ou superpowers:executing-plans para executar este plano tarefa por tarefa. Os passos usam caixas (`- [ ]`) para acompanhar.

**Objetivo:** no console, a empresa edita a segunda tabela de faixas ("a partir da 2ª entrega junto") e cria, prorroga, encerra e apaga períodos de multiplicador (chuva). O relatório, o extrato e a fatura mostram os selos "2ª entrega" e "Chuva ×1,2". O portal da loja mostra os selos quando o valor da loja mudou.

**Arquitetura:** funções puras novas em `packages/fleetops/addon/utils/financeiro-multiplicadores.js` (faixas, fatores, datas, selos), testadas no Node. Tela nova `financeiro.multiplicadores` com um modal (`modals/periodo-multiplicador`). A tela do relatório passa a editar as duas tabelas com as mesmas funções. O portal ganha `selosDaCorrida` no `entregas-financeiro.js` dele (os pacotes não importam um do outro).

**Stack:** Ember (engine `@fleetbase/fleetops-engine` e `customer-portal`), ember-concurrency, testes `node --test`.

**Pré-requisito:** o plano do servidor (`2026-10-21-entrega-junto-e-multiplicador-servidor.md`) já implementado no mesmo ramo: rotas `financeiro/multiplicadores*`, `pagamento-motoboys/faixas-juntas`, e os campos `faixas_juntas`, `posicao`, `tabela`, `multiplicador`, `detalhes.junto` e `detalhes.multiplicador`.

**Spec:** `docs/superpowers/specs/2026-10-21-entrega-junto-e-multiplicador-design.md`.

---

## Antes de começar

- Leia `packages/CLAUDE.md` (tradução) e `packages/fleetops/CLAUDE.md` (Financeiro: armadilha do `modals-manager`, valor digitado, datas).
- Mesma worktree e ramo do plano do servidor (`entrega-junto`).
- Testes do front, na raiz do repo: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/<arquivo>.test.mjs`.
- Tradução: `node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal` precisa sair com exit 0 antes de cada commit que mexe em texto.
- YAML: `packages/fleetops/translations/pt-br.yaml` usa **2 espaços** por nível e `en-us.yaml` usa **4**, os dois com CRLF. Os blocos ficam na mesma linha nos dois arquivos: mantenha assim (insira no mesmo ponto nos dois). O `customer-portal/translations/*.yaml` usa 2 espaços e LF.
- Rotas, controllers e templates do Financeiro **não** têm re-export em `packages/fleetops/app/`. Componentes de modal **têm**: `app/components/modals/<nome>.js` com uma linha.
- Datas: o campo `datetime-local` dá `'YYYY-MM-DDTHH:MM'` no relógio de quem usa o console (Brasília). O servidor devolve ISO com `-03:00`; para preencher o campo, use a hora escrita no ISO (`campoDoInstante`), nunca `new Date(iso)` + `toISOString()`.

## Mapa dos arquivos

| Arquivo | Papel |
|---|---|
| `packages/fleetops/addon/utils/financeiro-multiplicadores.js` (novo) | funções puras |
| `scripts/teste-portal/financeiro-multiplicadores.test.mjs` (novo) | testes delas |
| `packages/fleetops/addon/controllers/financeiro/relatorio.js`, `templates/financeiro/relatorio.hbs` | segunda tabela, selos, CSV |
| `packages/fleetops/addon/routes.js`, `addon/routes/financeiro/multiplicadores.js` (novo), `addon/controllers/financeiro/multiplicadores.js` (novo), `addon/templates/financeiro/multiplicadores.hbs` (novo) | tela dos períodos |
| `packages/fleetops/addon/components/modals/periodo-multiplicador.js` e `.hbs` (novos), `packages/fleetops/app/components/modals/periodo-multiplicador.js` (novo) | modal do período |
| `packages/fleetops/addon/components/layout/fleet-ops-sidebar.js` | item do menu |
| `packages/fleetops/addon/controllers/financeiro/{motoboy,loja,fatura}.js` e templates | selos no extrato e na fatura |
| `packages/fleetops/translations/{pt-br,en-us}.yaml` | textos |
| `packages/customer-portal/addon/utils/entregas-financeiro.js`, `controllers/portal/{extrato,fatura}.js`, `templates/portal/{extrato,fatura}.hbs`, `translations/*.yaml` | selos no portal |
| `scripts/teste-portal/entregas-financeiro.test.mjs` | teste do portal |

---

### Tarefa 1: Funções puras do console

**Arquivos:**
- Criar: `packages/fleetops/addon/utils/financeiro-multiplicadores.js`
- Criar: `scripts/teste-portal/financeiro-multiplicadores.test.mjs`

- [ ] **Passo 1: Teste**

```js
// Entrega junto e multiplicadores no console (packages/fleetops/addon/utils/financeiro-multiplicadores.js): faixas das duas
// tabelas, fator digitado, campos de data, validação do período, situação e selos.
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/financeiro-multiplicadores.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    campoDeData,
    campoDoInstante,
    chaveDaSituacao,
    comNovaFaixa,
    corpoDoPeriodo,
    faixaParaEdicao,
    faixasParaEnviar,
    faixasValidas,
    fatorDigitado,
    linhasDeFaixas,
    selosDaCorrida,
    selosDoRelatorio,
    separarPeriodos,
    somarHoras,
    textoDoFator,
    validarPeriodo,
} from '../../packages/fleetops/addon/utils/financeiro-multiplicadores.js';

test('faixas: edição, "de km", validação, nova faixa e envio', () => {
    const faixas = [faixaParaEdicao({ ate_km: 3, motoboy: 4, loja: 6 }), faixaParaEdicao({ ate_km: 6, motoboy: 6, loja: 9 })];
    assert.deepEqual(faixas[0], { ate_km: '3', motoboy: '4', loja: '6' });
    assert.deepEqual(linhasDeFaixas(faixas).map((l) => [l.indice, l.de_km]), [[0, '0'], [1, '3']]);
    assert.equal(faixasValidas(faixas), true);
    assert.equal(faixasValidas([]), true, 'vazia vale (segunda tabela desligada)');
    assert.equal(faixasValidas([{ ate_km: '3', motoboy: '4', loja: '6' }, { ate_km: '2', motoboy: '1', loja: '1' }]), false, '"até" não crescente');
    assert.equal(faixasValidas([{ ate_km: '3', motoboy: '-1', loja: '6' }]), false, 'valor negativo');
    assert.deepEqual(comNovaFaixa(faixas).at(-1), { ate_km: '7', motoboy: '6', loja: '9' }, 'nova faixa: +1 km, copia os valores da última');
    assert.deepEqual(comNovaFaixa([]), [{ ate_km: '1', motoboy: '', loja: '' }]);
    assert.deepEqual(faixasParaEnviar([{ ate_km: '2,5', motoboy: '6', loja: '9,5' }]), [{ ate_km: 2.5, motoboy: 6, loja: 9.5 }]);
});

test('fator digitado: 1 a 5, até duas casas', () => {
    assert.equal(fatorDigitado('1,2'), 1.2);
    assert.equal(fatorDigitado('1.5'), 1.5);
    assert.equal(fatorDigitado(2), 2);
    assert.equal(fatorDigitado('1'), 1);
    assert.ok(Number.isNaN(fatorDigitado('0,9')));
    assert.ok(Number.isNaN(fatorDigitado('5,01')));
    assert.ok(Number.isNaN(fatorDigitado('1,234')));
    assert.ok(Number.isNaN(fatorDigitado('abc')));
    assert.ok(Number.isNaN(fatorDigitado('')));
});

test('texto do fator no idioma do console', () => {
    assert.equal(textoDoFator(1.2), '×1,2');
    assert.equal(textoDoFator(1.25), '×1,25');
    assert.equal(textoDoFator(2), '×2');
    assert.equal(textoDoFator(1.5, 'en-US'), '×1.5');
    assert.equal(textoDoFator(null), '');
});

test('campos de data: ISO do servidor, Date local e soma de horas', () => {
    assert.equal(campoDoInstante('2026-10-21T18:00:00-03:00'), '2026-10-21T18:00', 'a hora de Brasília escrita no ISO');
    assert.equal(campoDoInstante(null), '');
    assert.equal(campoDeData(new Date(2026, 9, 21, 7, 5)), '2026-10-21T07:05');
    assert.equal(somarHoras('2026-10-21T22:30', 3), '2026-10-22T01:30', 'vira o dia');
    assert.equal(somarHoras('lixo', 3), '');
});

test('validação do período: as regras do servidor', () => {
    const agora = '2026-10-21T17:00';
    const ok = { nome: 'Chuva', multMotoboy: '1,2', multLoja: '1', inicio: '2026-10-21T18:00', fim: '2026-10-21T22:00' };
    assert.deepEqual(validarPeriodo(ok, agora), []);
    assert.deepEqual(validarPeriodo({ ...ok, nome: ' ' }, agora), ['nome']);
    assert.deepEqual(validarPeriodo({ ...ok, nome: 'a'.repeat(41) }, agora), ['nome-longo']);
    assert.deepEqual(validarPeriodo({ ...ok, multLoja: '0,5' }, agora), ['fator']);
    assert.deepEqual(validarPeriodo({ ...ok, fim: '' }, agora), ['datas']);
    assert.deepEqual(validarPeriodo({ ...ok, fim: '2026-10-21T17:30' }, agora), ['fim'], 'fim antes do início');
    assert.deepEqual(validarPeriodo({ ...ok, inicio: '2026-10-21T10:00', fim: '2026-10-21T16:00' }, agora), ['fim'], 'fim no passado');
    assert.deepEqual(validarPeriodo({ ...ok, fim: '2026-10-28T18:01' }, agora), ['duracao']);
    assert.deepEqual(validarPeriodo({ ...ok, inicio: '2026-10-21T16:00', fim: '2026-10-28T17:00' }, agora), [], 'início no passado vira agora: 7 dias contam de agora');
    assert.deepEqual(validarPeriodo({ soFim: true, inicio: '2026-10-21T16:00', fim: '2026-10-21T20:00' }, agora), [], 'em andamento: só o fim, sem nome nem fator');
});

test('corpo enviado ao servidor', () => {
    assert.deepEqual(corpoDoPeriodo({ nome: ' Chuva ', multMotoboy: '1,2', multLoja: '1', inicio: '2026-10-21T18:00', fim: '2026-10-21T22:00' }), {
        nome: 'Chuva',
        mult_motoboy: '1.20',
        mult_loja: '1.00',
        inicio: '2026-10-21T18:00',
        fim: '2026-10-21T22:00',
    });
    assert.deepEqual(corpoDoPeriodo({ soFim: true, nome: 'x', fim: '2026-10-21T23:00' }), { fim: '2026-10-21T23:00' });
});

test('situação e separação da lista', () => {
    assert.equal(chaveDaSituacao('em_andamento'), 'fleet-ops.ui.financeiro.multiplicadores.situacao.em_andamento');
    assert.equal(chaveDaSituacao('outra'), 'fleet-ops.ui.financeiro.multiplicadores.situacao.encerrado');
    const lista = [
        { id: 'c', situacao: 'agendado', inicio: '2026-10-23T18:00:00-03:00' },
        { id: 'b', situacao: 'agendado', inicio: '2026-10-22T18:00:00-03:00' },
        { id: 'a', situacao: 'em_andamento', inicio: '2026-10-21T18:00:00-03:00' },
        { id: 'z', situacao: 'encerrado', inicio: '2026-10-20T18:00:00-03:00' },
    ];
    const { emAndamento, agendados, encerrados } = separarPeriodos(lista);
    assert.equal(emAndamento.id, 'a');
    assert.deepEqual(agendados.map((p) => p.id), ['b', 'c'], 'o próximo primeiro');
    assert.deepEqual(encerrados.map((p) => p.id), ['z']);
    assert.deepEqual(separarPeriodos(null), { emAndamento: null, agendados: [], encerrados: [] });
});

test('selos da corrida (extrato e fatura) e do relatório', () => {
    assert.deepEqual(selosDaCorrida({ junto: 2, multiplicador: { nome: 'Chuva', fator: 1.2 } }), [
        { chave: 'junto', params: { posicao: 2 } },
        { chave: 'multiplicador', params: { nome: 'Chuva', fator: '×1,2' } },
    ]);
    assert.deepEqual(selosDaCorrida({ junto: null, multiplicador: null }), []);
    assert.deepEqual(selosDaCorrida(null), []);
    assert.deepEqual(selosDoRelatorio({ tabela: 'junto', posicao: 3, multiplicador: { nome: 'Chuva', motoboy: 1.2, loja: 1 } }), [
        { chave: 'junto', params: { posicao: 3 } },
        { chave: 'multiplicador-relatorio', params: { nome: 'Chuva', motoboy: '×1,2', loja: '×1' } },
    ]);
    assert.deepEqual(selosDoRelatorio({ tabela: 'normal', posicao: 2, multiplicador: null }), [], 'posição 2 pela tabela normal: sem selo');
});
```

- [ ] **Passo 2: Rode e veja falhar**

`node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/financeiro-multiplicadores.test.mjs`
Esperado: erro de módulo não encontrado.

- [ ] **Passo 3: Implemente**

`packages/fleetops/addon/utils/financeiro-multiplicadores.js`:

```js
/**
 * Entregas RestaurantePro: funções puras da entrega junto e dos multiplicadores no console (Financeiro → Tabela de km e
 * relatório, Financeiro → Multiplicadores, selos no extrato e na fatura). Regras iguais às do servidor
 * (App\Support\Entregas\Financeiro\Multiplicadores). Sem imports do Ember: testado no Node
 * (scripts/teste-portal/financeiro-multiplicadores.test.mjs).
 * Spec: docs/superpowers/specs/2026-10-21-entrega-junto-e-multiplicador-design.md.
 */
import { numeroDigitado } from './financeiro';

export const SITUACOES = ['em_andamento', 'agendado', 'encerrado'];
export const FATOR_MINIMO = 1;
export const FATOR_MAXIMO = 5;
export const MAX_DIAS = 7;
export const MAX_NOME = 40;

const PREFIXO = 'fleet-ops.ui.financeiro.multiplicadores';
const CAMPO = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/;
const pad = (n) => String(n).padStart(2, '0');
// o "até km" e os valores das faixas: vírgula ou ponto decimal (a regra da tabela de sempre)
const numero = (valor) => Number(String(valor ?? '').trim().replace(',', '.'));

/* ---------------------------------------------------------------- faixas (as duas tabelas) */

export function faixaParaEdicao(faixa) {
    return { ate_km: String(faixa.ate_km), motoboy: String(faixa.motoboy), loja: String(faixa.loja) };
}

/** Faixas com o "de km" (limite da anterior) e o índice, para exibir. */
export function linhasDeFaixas(faixas) {
    return (faixas ?? []).map((faixa, indice) => ({ ...faixa, indice, de_km: indice === 0 ? '0' : faixas[indice - 1].ate_km || '?' }));
}

/** "Até" maior que zero e crescente, valores ≥ 0. A lista vazia vale (a segunda tabela vazia desliga o recurso). */
export function faixasValidas(faixas) {
    let anterior = 0;
    for (const faixa of faixas ?? []) {
        const ate = numero(faixa.ate_km);
        if (!(ate > anterior) || !(numero(faixa.motoboy) >= 0) || !(numero(faixa.loja) >= 0)) {
            return false;
        }
        anterior = ate;
    }

    return true;
}

/** A lista com uma faixa nova no fim: 1 km depois da última, com os valores dela. */
export function comNovaFaixa(faixas) {
    const ultima = (faixas ?? []).at(-1);
    const proximoAte = ultima ? (numero(ultima.ate_km) || 0) + 1 : 1;

    return [...(faixas ?? []), { ate_km: String(proximoAte), motoboy: ultima?.motoboy ?? '', loja: ultima?.loja ?? '' }];
}

export function faixasParaEnviar(faixas) {
    return (faixas ?? []).map((faixa) => ({ ate_km: numero(faixa.ate_km), motoboy: numero(faixa.motoboy), loja: numero(faixa.loja) }));
}

/* ---------------------------------------------------------------- fator e datas */

/** Fator digitado ("1,2", "1.5", 2) de 1 a 5 com até duas casas, ou NaN. */
export function fatorDigitado(valor) {
    const fator = numeroDigitado(valor);

    return Number.isFinite(fator) && fator >= FATOR_MINIMO && fator <= FATOR_MAXIMO ? fator : NaN;
}

/** "×1,2" no idioma do console; vazio sem número. */
export function textoDoFator(fator, locale = 'pt-BR') {
    if (fator === null || fator === undefined || fator === '') {
        return '';
    }
    const valor = Number(fator);

    return Number.isFinite(valor) ? `×${new Intl.NumberFormat(locale, { maximumFractionDigits: 2 }).format(valor)}` : '';
}

/** Campo datetime-local ('YYYY-MM-DDTHH:MM') a partir do ISO do servidor, com a hora de Brasília escrita nele. */
export function campoDoInstante(iso) {
    const partes = /^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/.exec(String(iso ?? ''));

    return partes ? `${partes[1]}T${partes[2]}` : '';
}

/** Campo datetime-local de um Date local (o relógio de quem usa o console). */
export function campoDeData(data) {
    return `${data.getFullYear()}-${pad(data.getMonth() + 1)}-${pad(data.getDate())}T${pad(data.getHours())}:${pad(data.getMinutes())}`;
}

/** O campo + N horas (o "Começar agora" sugere o fim em 3 h); campo inválido: vazio. */
export function somarHoras(campo, horas) {
    const partes = CAMPO.exec(String(campo ?? ''));
    if (!partes) {
        return '';
    }
    const data = new Date(Number(partes[1]), Number(partes[2]) - 1, Number(partes[3]), Number(partes[4]), Number(partes[5]));
    data.setHours(data.getHours() + horas);

    return campoDeData(data);
}

/* ---------------------------------------------------------------- formulário do período */

/**
 * Erros do formulário (códigos de fleet-ops.ui.financeiro.multiplicadores.erros.*), com as regras do servidor
 * (Multiplicadores::conferir). `soFim`: período em andamento, em que só o fim muda. `agora`: campo 'YYYY-MM-DDTHH:MM'.
 */
export function validarPeriodo({ nome, multMotoboy, multLoja, inicio, fim, soFim = false } = {}, agora) {
    const erros = [];
    if (!soFim) {
        const texto = String(nome ?? '').trim();
        if (!texto) {
            erros.push('nome');
        } else if (texto.length > MAX_NOME) {
            erros.push('nome-longo');
        }
        if (Number.isNaN(fatorDigitado(multMotoboy)) || Number.isNaN(fatorDigitado(multLoja))) {
            erros.push('fator');
        }
    }

    if (!CAMPO.test(String(inicio ?? '')) || !CAMPO.test(String(fim ?? ''))) {
        erros.push('datas');
        return erros;
    }

    // o servidor troca o início no passado por agora ("Começar agora"); em andamento, o início não muda
    const comeco = !soFim && inicio < agora ? agora : inicio;
    if (fim <= comeco || fim <= agora) {
        erros.push('fim');
    } else if (fim > somarHoras(comeco, MAX_DIAS * 24)) {
        erros.push('duracao');
    }

    return erros;
}

/** O corpo do POST/PUT: fatores em texto com duas casas ("1.20"), datas como o campo. Em andamento, só o fim. */
export function corpoDoPeriodo({ nome, multMotoboy, multLoja, inicio, fim, soFim = false } = {}) {
    if (soFim) {
        return { fim };
    }

    return { nome: String(nome ?? '').trim(), mult_motoboy: fatorDigitado(multMotoboy).toFixed(2), mult_loja: fatorDigitado(multLoja).toFixed(2), inicio, fim };
}

/* ---------------------------------------------------------------- lista */

export function chaveDaSituacao(situacao) {
    return `${PREFIXO}.situacao.${SITUACOES.includes(situacao) ? situacao : 'encerrado'}`;
}

const CORES = {
    em_andamento: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-100',
    agendado: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-100',
    encerrado: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
};

export function corDaSituacao(situacao) {
    return CORES[situacao] ?? CORES.encerrado;
}

/** O período em andamento, os agendados (o próximo primeiro) e os encerrados (como vieram: o mais recente primeiro). */
export function separarPeriodos(lista) {
    const periodos = Array.from(lista ?? []);

    return {
        emAndamento: periodos.find((p) => p.situacao === 'em_andamento') ?? null,
        agendados: periodos.filter((p) => p.situacao === 'agendado').sort((a, b) => String(a.inicio).localeCompare(String(b.inicio))),
        encerrados: periodos.filter((p) => p.situacao === 'encerrado'),
    };
}

/* ---------------------------------------------------------------- selos */

/**
 * Selos de uma corrida no extrato e na fatura (os `detalhes` do lançamento: `junto` = posição, `multiplicador` =
 * {nome, fator} da própria conta): [{chave, params}] em fleet-ops.ui.financeiro.selos.*.
 */
export function selosDaCorrida(detalhes, locale = 'pt-BR') {
    const selos = [];
    if (Number(detalhes?.junto) >= 2) {
        selos.push({ chave: 'junto', params: { posicao: Number(detalhes.junto) } });
    }
    const multiplicador = detalhes?.multiplicador;
    if (multiplicador && textoDoFator(multiplicador.fator, locale)) {
        selos.push({ chave: 'multiplicador', params: { nome: multiplicador.nome || '', fator: textoDoFator(multiplicador.fator, locale) } });
    }

    return selos;
}

/** Selos da linha do relatório (só admin): a tabela usada e o multiplicador com os dois fatores. */
export function selosDoRelatorio(entrega, locale = 'pt-BR') {
    const selos = [];
    if (entrega?.tabela === 'junto' && Number(entrega.posicao) >= 2) {
        selos.push({ chave: 'junto', params: { posicao: Number(entrega.posicao) } });
    }
    const multiplicador = entrega?.multiplicador;
    if (multiplicador) {
        selos.push({ chave: 'multiplicador-relatorio', params: { nome: multiplicador.nome || '', motoboy: textoDoFator(multiplicador.motoboy, locale), loja: textoDoFator(multiplicador.loja, locale) } });
    }

    return selos;
}
```

- [ ] **Passo 4: Rode e veja passar**

`node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/financeiro-multiplicadores.test.mjs`
Esperado: todos os testes passam. Confira também `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/financeiro.test.mjs` (nada mudou nele).

- [ ] **Passo 5: Commit**

```bash
git add packages/fleetops/addon/utils/financeiro-multiplicadores.js scripts/teste-portal/financeiro-multiplicadores.test.mjs
git commit -m "Console: funções puras da entrega junto e dos multiplicadores (faixas, fator, datas, selos)"
```

---

### Tarefa 2: Traduções do console

**Arquivos:**
- Modificar: `packages/fleetops/translations/pt-br.yaml`
- Modificar: `packages/fleetops/translations/en-us.yaml`

- [ ] **Passo 1: `driver-payouts` (Tabela de km e relatório)**

No pt-br, logo depois de `      store-amount: Loja` (fim do bloco `driver-payouts:`, linha ~159), acrescente (6 espaços):

```yaml
      bands-junto-title: A partir da 2ª entrega junto
      bands-junto-help: 'Vale quando o motoboy pega o pedido enquanto ainda leva outro (2ª, 3ª, 4ª entrega em diante, todas pela mesma tabela). Deixe vazia para pagar todas as entregas pela tabela acima. Mudanças valem para as entregas calculadas a partir de agora.'
      no-bands-junto: 'Sem segunda tabela: todas as entregas pagam pela tabela acima.'
      save-bands-junto: Salvar segunda tabela
      clear-bands-junto: Desligar (apagar a tabela)
      bands-junto-saved: Segunda tabela salva.
      bands-junto-cleared: Segunda tabela apagada. Todas as entregas voltam para a tabela acima.
      junto-column: Entrega junto
      multiplier-column: Multiplicador
```

No en-us, no mesmo ponto (12 espaços):

```yaml
            bands-junto-title: From the 2nd delivery carried together
            bands-junto-help: 'Applies when the driver takes an order while still carrying another (2nd, 3rd, 4th delivery onward, all by the same table). Leave empty to pay every delivery by the table above. Changes apply to deliveries calculated from now on.'
            no-bands-junto: 'No second table: every delivery is paid by the table above.'
            save-bands-junto: Save second table
            clear-bands-junto: Turn off (clear the table)
            bands-junto-saved: Second table saved.
            bands-junto-cleared: Second table cleared. Every delivery goes back to the table above.
            junto-column: Carried together
            multiplier-column: Multiplier
```

- [ ] **Passo 2: `financeiro.menu`, `financeiro.selos` e `financeiro.multiplicadores`**

No pt-br, no bloco `      menu:` do `financeiro:` (linha ~588), depois de `        relatorio: Tabela de km e relatório` acrescente `        multiplicadores: Multiplicadores`. No en-us, no mesmo ponto: `                multiplicadores: Multipliers`.

No pt-br, imediatamente antes de `      ciclos:` (dentro de `financeiro:`, linha ~789), acrescente:

```yaml
      selos:
        junto: '{posicao}ª entrega'
        multiplicador: '{nome} {fator}'
        multiplicador-relatorio: '{nome} {motoboy} / {loja}'
        multiplicador-relatorio-ajuda: 'Motoboy {motoboy}, loja {loja}'
      multiplicadores:
        titulo: Multiplicadores
        ajuda: 'Em dia de chuva ou outra intempérie, crie um período: as entregas aceitas dentro dele valem o valor da faixa vezes o multiplicador. Um multiplicador para o motoboy e outro para a loja (1 = a loja paga o normal). Vale a hora em que o motoboy pega o pedido.'
        novo: Novo período
        comecar-agora: Começar agora
        encerrar-agora: Encerrar agora
        prorrogar: Mudar o fim
        editar: Editar
        apagar: Apagar
        em-andamento: Em andamento agora
        nenhum-em-andamento: Nenhum multiplicador valendo agora.
        agendados: Agendados
        encerrados: Encerrados
        vazio: Nenhum período ainda.
        nome: Nome
        nome-exemplo: Chuva
        mult-motoboy: Motoboy (×)
        mult-loja: Loja (×)
        inicio: Início
        fim: Fim
        ate: 'até {fim}'
        situacao:
          em_andamento: Em andamento
          agendado: Agendado
          encerrado: Encerrado
        modal-novo: Novo período de multiplicador
        modal-editar: 'Editar "{nome}"'
        modal-so-fim: 'Em andamento: só dá para mudar o fim. Para mudar o multiplicador, encerre e crie outro.'
        modal-ajuda-fator: 'De 1 a 5, com até duas casas (ex.: 1,2 = 20% a mais).'
        salvar: Salvar
        cancelar: Cancelar
        salvo: Período salvo.
        encerrar-titulo: 'Encerrar "{nome}" agora?'
        encerrar-texto: As entregas aceitas a partir de agora voltam ao valor normal. As já aceitas mantêm o multiplicador.
        encerrado-ok: Período encerrado.
        apagar-titulo: 'Apagar "{nome}"?'
        apagar-texto: O período ainda não começou e nenhuma entrega foi afetada.
        apagado-ok: Período apagado.
        erros:
          nome: Dê um nome ao período (ex.: Chuva).
          nome-longo: O nome pode ter até 40 caracteres.
          fator: O multiplicador vai de 1 a 5, com até duas casas (ex.: 1,2).
          datas: Informe o início e o fim.
          fim: O fim precisa ser depois do início e de agora.
          duracao: O período pode durar até 7 dias.
```

No en-us, no mesmo ponto (12 espaços para `selos:`/`multiplicadores:`, +4 por nível):

```yaml
            selos:
                junto: 'Delivery #{posicao} together'
                multiplicador: '{nome} {fator}'
                multiplicador-relatorio: '{nome} {motoboy} / {loja}'
                multiplicador-relatorio-ajuda: 'Driver {motoboy}, store {loja}'
            multiplicadores:
                titulo: Multipliers
                ajuda: 'On rainy days or other bad weather, create a period: deliveries accepted within it are worth the band amount times the multiplier. One multiplier for the driver and another for the store (1 = the store pays the usual). What counts is when the driver takes the order.'
                novo: New period
                comecar-agora: Start now
                encerrar-agora: End now
                prorrogar: Change the end
                editar: Edit
                apagar: Delete
                em-andamento: Running now
                nenhum-em-andamento: No multiplier running now.
                agendados: Scheduled
                encerrados: Ended
                vazio: No periods yet.
                nome: Name
                nome-exemplo: Rain
                mult-motoboy: Driver (×)
                mult-loja: Store (×)
                inicio: Start
                fim: End
                ate: 'until {fim}'
                situacao:
                    em_andamento: Running
                    agendado: Scheduled
                    encerrado: Ended
                modal-novo: New multiplier period
                modal-editar: 'Edit "{nome}"'
                modal-so-fim: 'Running: only the end can change. To change the multiplier, end it and create another.'
                modal-ajuda-fator: 'From 1 to 5, with up to two decimals (e.g. 1.2 = 20% more).'
                salvar: Save
                cancelar: Cancel
                salvo: Period saved.
                encerrar-titulo: 'End "{nome}" now?'
                encerrar-texto: Deliveries accepted from now on go back to the usual amount. Those already accepted keep the multiplier.
                encerrado-ok: Period ended.
                apagar-titulo: 'Delete "{nome}"?'
                apagar-texto: The period has not started and no delivery was affected.
                apagado-ok: Period deleted.
                erros:
                    nome: Name the period (e.g. Rain).
                    nome-longo: The name can have up to 40 characters.
                    fator: The multiplier goes from 1 to 5, with up to two decimals (e.g. 1.2).
                    datas: Fill in the start and the end.
                    fim: The end must be after the start and after now.
                    duracao: The period can last up to 7 days.
```

- [ ] **Passo 3: Confira**

```bash
node -e "const y=require('js-yaml');for(const f of ['pt-br','en-us'])y.load(require('fs').readFileSync('packages/fleetops/translations/'+f+'.yaml','utf8'));console.log('ok')"
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit $?"
```
Se o `js-yaml` não resolver na raiz, use o do `console/node_modules` (`require('./console/node_modules/js-yaml')`). Esperado: `ok` e exit 0. Confira com `git diff --stat` que os dois YAML mantiveram CRLF (o diff não pode marcar o arquivo inteiro como alterado).

- [ ] **Passo 4: Commit**

```bash
git add packages/fleetops/translations/pt-br.yaml packages/fleetops/translations/en-us.yaml
git commit -m "Console: textos da segunda tabela de faixas, dos multiplicadores e dos selos (pt-BR e en)"
```

---

### Tarefa 3: Tabela de km e relatório: segunda tabela, selos e CSV

**Arquivos:**
- Modificar: `packages/fleetops/addon/controllers/financeiro/relatorio.js`
- Modificar: `packages/fleetops/addon/templates/financeiro/relatorio.hbs`

- [ ] **Passo 1: Controller**

1.1. Imports e constantes do topo: troque as linhas de `const numero = …` e `const faixaParaEdicao = …` pelos imports das funções puras (o `numero` e o `faixaParaEdicao` saem daqui):

```js
import { comNovaFaixa, faixaParaEdicao, faixasParaEnviar, faixasValidas, linhasDeFaixas, selosDoRelatorio, textoDoFator } from '../../utils/financeiro-multiplicadores';
```

e, depois de `const isoDate = …`:

```js
// as duas tabelas da tela: a normal e a segunda ("a partir da 2ª entrega junto"), cada uma com a lista em edição e a marca de alterada
const TABELAS = {
    normal: { lista: 'faixas', alterada: 'faixasAlteradas', caminho: 'faixas', salvo: 'bands-saved' },
    juntas: { lista: 'faixasJuntas', alterada: 'faixasJuntasAlteradas', caminho: 'faixas-juntas', salvo: 'bands-junto-saved' },
};
```

1.2. Propriedades: depois de `@tracked faixasAlteradas = false;`:

```js
    // segunda tabela ("a partir da 2ª entrega junto"); vazia = todas as entregas pela tabela normal
    @tracked faixasJuntas = [];
    @tracked faixasJuntasAlteradas = false;
```

1.3. Troque `linhasFaixas`, `faixasValidas`, `setFaixa`, `adicionarFaixa` e `removerFaixa` por:

```js
    /** Faixas com o "de km" (limite da faixa anterior) para exibir. */
    get linhasFaixas() {
        return linhasDeFaixas(this.faixas);
    }

    get linhasFaixasJuntas() {
        return linhasDeFaixas(this.faixasJuntas);
    }

    @action setFaixa(qual, indice, campo, event) {
        const { lista, alterada } = TABELAS[qual];
        this[lista] = this[lista].map((faixa, i) => (i === indice ? { ...faixa, [campo]: event.target.value } : faixa));
        this[alterada] = true;
    }

    @action adicionarFaixa(qual) {
        const { lista, alterada } = TABELAS[qual];
        this[lista] = comNovaFaixa(this[lista]);
        this[alterada] = true;
    }

    @action removerFaixa(qual, indice) {
        const { lista, alterada } = TABELAS[qual];
        this[lista] = this[lista].filter((_, i) => i !== indice);
        this[alterada] = true;
    }
```

1.4. No `load`, depois do bloco `if (!this.faixasAlteradas) { … }`:

```js
                if (!this.faixasJuntasAlteradas) {
                    this.faixasJuntas = (relatorio.faixas_juntas ?? []).map(faixaParaEdicao);
                }
```

1.5. Troque a task `salvarFaixas` inteira por:

```js
    /** Salva uma das tabelas (normal ou juntas). `apagar`: grava a segunda tabela vazia (desliga o recurso). */
    @task *salvarFaixas(qual = 'normal', apagar = false) {
        const { lista, alterada, caminho, salvo } = TABELAS[qual];
        const faixas = apagar ? [] : this[lista];
        if (!faixasValidas(faixas)) {
            this.notifications.warning(this.intl.t('fleet-ops.ui.driver-payouts.invalid-bands'));
            return;
        }

        try {
            const resposta = yield this.fetch.put(`${ENDPOINT}/${caminho}`, { faixas: faixasParaEnviar(faixas) });
            this[lista] = (resposta.faixas ?? []).map(faixaParaEdicao);
            this[alterada] = false;
            this.notifications.success(this.intl.t(`fleet-ops.ui.driver-payouts.${apagar ? 'bands-junto-cleared' : salvo}`));
            yield this.load.perform();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
```

1.6. Selos: depois da action `rotuloCanceladoPago`:

```js
    /** Selos da linha: "2ª entrega" (segunda tabela) e "Chuva ×1,2 / ×1,1" (motoboy / loja). */
    @action selos(entrega) {
        return selosDoRelatorio(entrega, this.intl.primaryLocale);
    }
```

1.7. CSV: no cabeçalho do detalhe (a linha com `t('cancelado-pago-coluna')`), acrescente `t('junto-column'), t('multiplier-column')` no fim da lista. Em cada linha, depois de `celula(entrega.cancelado_pago ? … : ''),` acrescente:

```js
                    celula(entrega.tabela === 'junto' && entrega.posicao ? `${entrega.posicao}ª` : ''),
                    celula(entrega.multiplicador ? `${entrega.multiplicador.nome} (${t('driver')} ${textoDoFator(entrega.multiplicador.motoboy, this.intl.primaryLocale)}; ${t('store')} ${textoDoFator(entrega.multiplicador.loja, this.intl.primaryLocale)})` : ''),
```

E troque o BOM literal invisível do `new Blob([...])` por `'﻿'` (a linha fica `new Blob(['﻿' + linhas.join('\r\n')], …)`).

- [ ] **Passo 2: Template**

2.1. Na tabela de faixas existente, troque os três `{{on "input" (fn this.setFaixa faixa.indice "…")}}` por `{{on "input" (fn this.setFaixa "normal" faixa.indice "…")}}`, o `{{fn this.removerFaixa faixa.indice}}` por `{{fn this.removerFaixa "normal" faixa.indice}}`, o `@onClick={{this.adicionarFaixa}}` por `@onClick={{fn this.adicionarFaixa "normal"}}` e o `@onClick={{perform this.salvarFaixas}}` por `@onClick={{perform this.salvarFaixas "normal"}}`.

2.2. Logo depois do `</ContentPanel>` das faixas (antes do `{{#if this.relatorio.pendentes}}`), acrescente:

```hbs
        <ContentPanel @title={{t "fleet-ops.ui.driver-payouts.bands-junto-title"}} @open={{true}} @wrapperClass="bordered-classic">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">{{t "fleet-ops.ui.driver-payouts.bands-junto-help"}}</p>
            {{#if this.faixasJuntas.length}}
                <table class="table w-full text-sm max-w-2xl">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="py-2">{{t "fleet-ops.ui.driver-payouts.band-from"}}</th>
                            <th class="py-2">{{t "fleet-ops.ui.driver-payouts.band-to"}}</th>
                            <th class="py-2">{{t "fleet-ops.ui.driver-payouts.band-driver"}}</th>
                            <th class="py-2">{{t "fleet-ops.ui.driver-payouts.band-store"}}</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {{#each this.linhasFaixasJuntas key="@index" as |faixa|}}
                            <tr class="border-t border-gray-100 dark:border-gray-800 dark:text-gray-100">
                                <td class="py-1 whitespace-nowrap">{{faixa.de_km}} km</td>
                                <td class="py-1"><input type="text" inputmode="decimal" class="form-input form-input-sm w-24" value={{faixa.ate_km}} {{on "input" (fn this.setFaixa "juntas" faixa.indice "ate_km")}} /></td>
                                <td class="py-1"><input type="text" inputmode="decimal" class="form-input form-input-sm w-28" value={{faixa.motoboy}} {{on "input" (fn this.setFaixa "juntas" faixa.indice "motoboy")}} /></td>
                                <td class="py-1"><input type="text" inputmode="decimal" class="form-input form-input-sm w-28" value={{faixa.loja}} {{on "input" (fn this.setFaixa "juntas" faixa.indice "loja")}} /></td>
                                <td class="py-1 text-right"><Button @size="xs" @icon="trash" @helpText={{t "fleet-ops.ui.driver-payouts.remove-band"}} @onClick={{fn this.removerFaixa "juntas" faixa.indice}} /></td>
                            </tr>
                        {{/each}}
                    </tbody>
                </table>
            {{else}}
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">{{t "fleet-ops.ui.driver-payouts.no-bands-junto"}}</p>
            {{/if}}
            <div class="flex items-center gap-2 mt-3">
                <Button @size="sm" @icon="plus" @text={{t "fleet-ops.ui.driver-payouts.add-band"}} @onClick={{fn this.adicionarFaixa "juntas"}} />
                <Button @size="sm" @type="primary" @icon="save" @text={{t "fleet-ops.ui.driver-payouts.save-bands-junto"}} @isLoading={{this.salvarFaixas.isRunning}} @onClick={{perform this.salvarFaixas "juntas"}} />
                {{#if this.relatorio.faixas_juntas.length}}
                    <Button @size="sm" @icon="power-off" @text={{t "fleet-ops.ui.driver-payouts.clear-bands-junto"}} @isLoading={{this.salvarFaixas.isRunning}} @onClick={{perform this.salvarFaixas "juntas" true}} />
                {{/if}}
            </div>
        </ContentPanel>
```

2.3. Na tabela de entregas, dentro do `<td>` do pedido, depois do bloco `{{#if entrega.cancelado_pago}}…{{/if}}`:

```hbs
                                    {{#each (this.selos entrega) as |selo|}}
                                        <Badge @status="info" @hideStatusDot={{true}} title={{if selo.params.loja (t "fleet-ops.ui.financeiro.selos.multiplicador-relatorio-ajuda" motoboy=selo.params.motoboy loja=selo.params.loja)}}>{{t (concat "fleet-ops.ui.financeiro.selos." selo.chave) posicao=selo.params.posicao nome=selo.params.nome motoboy=selo.params.motoboy loja=selo.params.loja}}</Badge>
                                    {{/each}}
```

Se o `<Badge>` do ember-ui não repassar `title` (atributo), envolva-o num `<span title=…>`.

2.4. Atualize o texto `how-it-works` do `driver-payouts` (pt-br e en-us) para citar a segunda tabela e o multiplicador. Acrescente ao fim do texto pt-br: `' Quem pega um pedido enquanto leva outro recebe pela segunda tabela (se houver), e em períodos de multiplicador o valor é multiplicado (Financeiro → Multiplicadores).'`, e o equivalente em inglês: `' A driver who takes an order while carrying another is paid by the second table (if any), and during multiplier periods the amount is multiplied (Finances → Multipliers).'`

- [ ] **Passo 3: Confira**

```bash
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit $?"
node -e "require('./console/node_modules/@babel/parser').parse(require('fs').readFileSync('packages/fleetops/addon/controllers/financeiro/relatorio.js','utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties']});console.log('ok')"
grep -nP '\x{FEFF}' packages/fleetops/addon/controllers/financeiro/relatorio.js || echo "sem BOM literal"
```
Esperado: exit 0, `ok` e "sem BOM literal". Se o `@babel/parser` estiver noutro lugar, procure com `ls console/node_modules/@babel`.

- [ ] **Passo 4: Commit**

```bash
git add packages/fleetops/addon/controllers/financeiro/relatorio.js packages/fleetops/addon/templates/financeiro/relatorio.hbs packages/fleetops/translations/pt-br.yaml packages/fleetops/translations/en-us.yaml
git commit -m "Console: segunda tabela de faixas na Tabela de km, selos e colunas de entrega junto e multiplicador no relatório"
```

---

### Tarefa 4: Tela Multiplicadores e modal do período

**Arquivos:**
- Modificar: `packages/fleetops/addon/routes.js`
- Criar: `packages/fleetops/addon/routes/financeiro/multiplicadores.js`
- Criar: `packages/fleetops/addon/controllers/financeiro/multiplicadores.js`
- Criar: `packages/fleetops/addon/templates/financeiro/multiplicadores.hbs`
- Criar: `packages/fleetops/addon/components/modals/periodo-multiplicador.js` e `.hbs`
- Criar: `packages/fleetops/app/components/modals/periodo-multiplicador.js`
- Modificar: `packages/fleetops/addon/components/layout/fleet-ops-sidebar.js`

- [ ] **Passo 1: Rota**

Em `addon/routes.js`, dentro de `this.route('financeiro', function () { … })`, depois de `this.route('relatorio');`: `this.route('multiplicadores');`.

`addon/routes/financeiro/multiplicadores.js`:

```js
import Route from '@ember/routing/route';

/**
 * Entregas RestaurantePro: Financeiro → Multiplicadores (períodos de chuva e afins). Dados de
 * `int/v1/entregas/financeiro/multiplicadores` (api/app/Http/Controllers/Entregas/MultiplicadoresController.php).
 */
export default class FinanceiroMultiplicadoresRoute extends Route {
    setupController(controller) {
        super.setupController(...arguments);
        controller.carregar.perform();
    }

    resetController(controller, isExiting) {
        super.resetController(...arguments);
        if (isExiting) {
            controller.limpar();
        }
    }
}
```

- [ ] **Passo 2: Controller**

`addon/controllers/financeiro/multiplicadores.js`:

```js
import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { campoDeData, chaveDaSituacao, corDaSituacao, separarPeriodos, somarHoras, textoDoFator } from '../../utils/financeiro-multiplicadores';
import { formatadores } from '../../utils/financeiro';

const ENDPOINT = 'entregas/financeiro/multiplicadores';
const PREFIXO = 'fleet-ops.ui.financeiro.multiplicadores';

/**
 * Entregas RestaurantePro: Financeiro → Multiplicadores. A empresa cria um período (nome, ×motoboy, ×loja, início e fim)
 * e as entregas atribuídas dentro dele valem a faixa × o fator. Regras no servidor (Multiplicadores); o modal
 * periodo-multiplicador confere as mesmas antes de enviar. Agendado: editar ou apagar; em andamento: mudar o fim ou
 * encerrar agora; encerrado: só consulta.
 */
export default class FinanceiroMultiplicadoresController extends Controller {
    @service fetch;
    @service intl;
    @service notifications;
    @service modalsManager;

    @tracked periodos = [];
    @tracked carregado = false;

    get separados() {
        return separarPeriodos(this.periodos);
    }

    limpar() {
        this.carregar.cancelAll();
        this.carregado = false;
    }

    @action fator(valor) {
        return textoDoFator(valor, this.intl.primaryLocale);
    }

    @action instante(iso) {
        return formatadores(this.intl.primaryLocale).instante(iso);
    }

    @action situacao(periodo) {
        return this.intl.t(chaveDaSituacao(periodo?.situacao));
    }

    @action corDaSituacao(periodo) {
        return corDaSituacao(periodo?.situacao);
    }

    @task({ restartable: true }) *carregar() {
        try {
            const resposta = yield this.fetch.get(ENDPOINT);
            this.periodos = resposta?.multiplicadores ?? [];
            this.carregado = true;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action novo() {
        this.abrirModal({ periodo: null });
    }

    /** "Começar agora": início = agora, fim sugerido em 3 h (editável). */
    @action comecarAgora() {
        const agora = campoDeData(new Date());
        this.abrirModal({ periodo: null, inicio: agora, fim: somarHoras(agora, 3) });
    }

    @action editar(periodo) {
        this.abrirModal({ periodo });
    }

    abrirModal(opcoes) {
        this.modalsManager.show('modals/periodo-multiplicador', { ...opcoes, onSalvo: () => this.carregar.perform() });
    }

    @action encerrar(periodo) {
        this.modalsManager.confirm({
            title: this.intl.t(`${PREFIXO}.encerrar-titulo`, { nome: periodo.nome }),
            body: this.intl.t(`${PREFIXO}.encerrar-texto`),
            acceptButtonText: this.intl.t(`${PREFIXO}.encerrar-agora`),
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await this.fetch.post(`${ENDPOINT}/${periodo.id}/encerrar`);
                    this.notifications.success(this.intl.t(`${PREFIXO}.encerrado-ok`));
                    modal.done();
                    this.carregar.perform();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    @action apagar(periodo) {
        this.modalsManager.confirm({
            title: this.intl.t(`${PREFIXO}.apagar-titulo`, { nome: periodo.nome }),
            body: this.intl.t(`${PREFIXO}.apagar-texto`),
            acceptButtonText: this.intl.t(`${PREFIXO}.apagar`),
            acceptButtonScheme: 'danger',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await this.fetch.delete(`${ENDPOINT}/${periodo.id}`);
                    this.notifications.success(this.intl.t(`${PREFIXO}.apagado-ok`));
                    modal.done();
                    this.carregar.perform();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }
}
```

O `fetch.delete` é o do ember-core (`addon/services/fetch.js`). Confira o nome com `grep -n "delete(" packages/ember-core/addon/services/fetch.js`. Se for outro (ex.: `del`), use o de lá.

- [ ] **Passo 3: Template**

`addon/templates/financeiro/multiplicadores.hbs`:

```hbs
<Layout::Section::Header @title={{t "fleet-ops.ui.financeiro.multiplicadores.titulo"}}>
    <div class="flex items-center space-x-2">
        <Button @icon="refresh" @size="sm" @helpText={{t "common.reload"}} @isLoading={{this.carregar.isRunning}} @onClick={{perform this.carregar}} />
        <Button @icon="bolt" @size="sm" @text={{t "fleet-ops.ui.financeiro.multiplicadores.comecar-agora"}} @disabled={{this.separados.emAndamento}} @onClick={{this.comecarAgora}} />
        <Button @type="primary" @icon="plus" @size="sm" @text={{t "fleet-ops.ui.financeiro.multiplicadores.novo"}} @onClick={{this.novo}} />
    </div>
</Layout::Section::Header>

<Layout::Section::Body class="overflow-y-scroll-i h-full">
    <div class="max-w-4xl mx-auto px-4 py-6 space-y-5">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.multiplicadores.ajuda"}}</p>

        {{#if this.carregado}}
            <ContentPanel @title={{t "fleet-ops.ui.financeiro.multiplicadores.em-andamento"}} @open={{true}} @wrapperClass="bordered-classic">
                {{#let this.separados.emAndamento as |periodo|}}
                    {{#if periodo}}
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <div class="text-lg font-semibold dark:text-white">{{periodo.nome}}</div>
                                <div class="text-sm text-gray-600 dark:text-gray-300">
                                    {{t "fleet-ops.ui.financeiro.multiplicadores.mult-motoboy"}} {{this.fator periodo.mult_motoboy}} ·
                                    {{t "fleet-ops.ui.financeiro.multiplicadores.mult-loja"}} {{this.fator periodo.mult_loja}} ·
                                    {{t "fleet-ops.ui.financeiro.multiplicadores.ate" fim=(this.instante periodo.fim)}}
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <Button @size="sm" @icon="clock" @text={{t "fleet-ops.ui.financeiro.multiplicadores.prorrogar"}} @onClick={{fn this.editar periodo}} />
                                <Button @size="sm" @type="danger" @icon="stop" @text={{t "fleet-ops.ui.financeiro.multiplicadores.encerrar-agora"}} @onClick={{fn this.encerrar periodo}} />
                            </div>
                        </div>
                    {{else}}
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.multiplicadores.nenhum-em-andamento"}}</p>
                    {{/if}}
                {{/let}}
            </ContentPanel>

            <ContentPanel @title={{t "fleet-ops.ui.financeiro.multiplicadores.agendados"}} @open={{true}} @wrapperClass="bordered-classic">
                {{#if this.separados.agendados.length}}
                    <table class="table w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="py-2">{{t "fleet-ops.ui.financeiro.multiplicadores.nome"}}</th>
                                <th class="py-2 text-right">{{t "fleet-ops.ui.financeiro.multiplicadores.mult-motoboy"}}</th>
                                <th class="py-2 text-right">{{t "fleet-ops.ui.financeiro.multiplicadores.mult-loja"}}</th>
                                <th class="py-2">{{t "fleet-ops.ui.financeiro.multiplicadores.inicio"}}</th>
                                <th class="py-2">{{t "fleet-ops.ui.financeiro.multiplicadores.fim"}}</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {{#each this.separados.agendados key="id" as |periodo|}}
                                <tr class="border-t border-gray-100 dark:border-gray-800 dark:text-gray-100">
                                    <td class="py-2 font-medium">{{periodo.nome}}</td>
                                    <td class="py-2 text-right">{{this.fator periodo.mult_motoboy}}</td>
                                    <td class="py-2 text-right">{{this.fator periodo.mult_loja}}</td>
                                    <td class="py-2 whitespace-nowrap">{{this.instante periodo.inicio}}</td>
                                    <td class="py-2 whitespace-nowrap">{{this.instante periodo.fim}}</td>
                                    <td class="py-2 text-right whitespace-nowrap">
                                        <Button @size="xs" @icon="pencil" @text={{t "fleet-ops.ui.financeiro.multiplicadores.editar"}} @onClick={{fn this.editar periodo}} />
                                        <Button @size="xs" @icon="trash" @text={{t "fleet-ops.ui.financeiro.multiplicadores.apagar"}} @onClick={{fn this.apagar periodo}} />
                                    </td>
                                </tr>
                            {{/each}}
                        </tbody>
                    </table>
                {{else}}
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.multiplicadores.vazio"}}</p>
                {{/if}}
            </ContentPanel>

            {{#if this.separados.encerrados.length}}
                <ContentPanel @title={{t "fleet-ops.ui.financeiro.multiplicadores.encerrados"}} @open={{false}} @wrapperClass="bordered-classic">
                    <table class="table w-full text-sm">
                        <tbody>
                            {{#each this.separados.encerrados key="id" as |periodo|}}
                                <tr class="border-t border-gray-100 dark:border-gray-800 text-gray-500 dark:text-gray-400">
                                    <td class="py-2">{{periodo.nome}}</td>
                                    <td class="py-2 text-right">{{this.fator periodo.mult_motoboy}} / {{this.fator periodo.mult_loja}}</td>
                                    <td class="py-2 whitespace-nowrap">{{this.instante periodo.inicio}} – {{this.instante periodo.fim}}</td>
                                    <td class="py-2"><span class="text-xs font-medium rounded px-2 py-0.5 {{this.corDaSituacao periodo}}">{{this.situacao periodo}}</span></td>
                                </tr>
                            {{/each}}
                        </tbody>
                    </table>
                </ContentPanel>
            {{/if}}
        {{else if this.carregar.isRunning}}
            <Spinner />
        {{/if}}
    </div>
    <Spacer @height="300px" />
</Layout::Section::Body>
```

Se algum ícone (`bolt`, `stop`, `clock`, `power-off`) não existir no conjunto do console, troque por um existente (`grep -rn '@icon="' packages/fleetops/addon/templates | grep -o '@icon="[^"]*"' | sort -u` mostra os usados).

- [ ] **Passo 4: Modal**

`addon/components/modals/periodo-multiplicador.js`:

```js
import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { campoDeData, campoDoInstante, corpoDoPeriodo, fatorDigitado, textoDoFator, validarPeriodo } from '../../utils/financeiro-multiplicadores';

const PREFIXO = 'fleet-ops.ui.financeiro.multiplicadores';
const ENDPOINT = 'entregas/financeiro/multiplicadores';

/**
 * Entregas RestaurantePro: modal do período de multiplicador (Financeiro → Multiplicadores). Novo (POST) ou edição (PUT
 * .../{id}); em andamento só o fim é editável (o servidor ignora o resto). Opções: `periodo` (null = novo), `inicio` e
 * `fim` sugeridos (campos 'YYYY-MM-DDTHH:MM', "Começar agora"), `onSalvo()`. Só fecha quando grava (keepOpen).
 */
export default class ModalsPeriodoMultiplicadorComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;

    @tracked nome = '';
    @tracked multMotoboy = '1,2';
    @tracked multLoja = '1';
    @tracked inicio = '';
    @tracked fim = '';

    constructor(owner, { options }) {
        super(...arguments);
        this.options = options;
        const periodo = options.periodo;
        if (periodo) {
            this.nome = periodo.nome;
            this.multMotoboy = String(periodo.mult_motoboy).replace('.', ',');
            this.multLoja = String(periodo.mult_loja).replace('.', ',');
            this.inicio = campoDoInstante(periodo.inicio);
            this.fim = campoDoInstante(periodo.fim);
        } else {
            this.inicio = options.inicio ?? '';
            this.fim = options.fim ?? '';
        }
        this.prepararOpcoes();
    }

    get soFim() {
        return this.options.periodo?.situacao === 'em_andamento';
    }

    get erros() {
        const dados = { nome: this.nome, multMotoboy: this.multMotoboy, multLoja: this.multLoja, inicio: this.inicio, fim: this.fim, soFim: this.soFim };

        return validarPeriodo(dados, campoDeData(new Date())).map((erro) => this.intl.t(`${PREFIXO}.erros.${erro}`));
    }

    /** "= ×1,2" ao lado do campo, como o "valor interpretado" dos outros modais. */
    get fatorMotoboy() {
        return textoDoFator(fatorDigitado(this.multMotoboy), this.intl.primaryLocale);
    }

    get fatorLoja() {
        return textoDoFator(fatorDigitado(this.multLoja), this.intl.primaryLocale);
    }

    prepararOpcoes() {
        const periodo = this.options.periodo;
        this.options.title = periodo ? this.intl.t(`${PREFIXO}.modal-editar`, { nome: periodo.nome }) : this.intl.t(`${PREFIXO}.modal-novo`);
        this.options.acceptButtonText = this.intl.t(`${PREFIXO}.salvar`);
        this.options.acceptButtonIcon = 'save';
        this.options.declineButtonText = this.intl.t(`${PREFIXO}.cancelar`);
        // só fecha quando grava (erro ou validação: fica aberto com o que foi digitado). Sem `return` no confirm: ver a
        // armadilha do modals-manager no packages/fleetops/CLAUDE.md
        this.options.keepOpen = true;
        this.options.confirm = async (modal, done) => {
            if (this.erros.length) {
                this.notifications.warning(this.erros[0]);
                return;
            }

            modal.startLoading();
            try {
                const corpo = corpoDoPeriodo({ nome: this.nome, multMotoboy: this.multMotoboy, multLoja: this.multLoja, inicio: this.inicio, fim: this.fim, soFim: this.soFim });
                if (periodo) {
                    await this.fetch.put(`${ENDPOINT}/${periodo.id}`, corpo);
                } else {
                    await this.fetch.post(ENDPOINT, corpo);
                }
                this.notifications.success(this.intl.t(`${PREFIXO}.salvo`));
                done();
                this.options.onSalvo?.();
            } catch (error) {
                this.notifications.serverError(error);
                modal.stopLoading();
            }
        };
        this.options.decline = (modal, done) => done();
    }

    @action setCampo(campo, event) {
        this[campo] = event.target.value;
    }
}
```

`addon/components/modals/periodo-multiplicador.hbs`:

```hbs
<Modal::Default @modalIsOpened={{@modalIsOpened}} @options={{@options}} @confirm={{@onConfirm}} @decline={{@onDecline}}>
    <div class="px-4 space-y-4 text-gray-900 dark:text-gray-50">
        {{#if this.soFim}}
            <p class="text-sm rounded-md px-3 py-2 bg-blue-50 text-blue-800 dark:bg-blue-900 dark:text-blue-100">{{t "fleet-ops.ui.financeiro.multiplicadores.modal-so-fim"}}</p>
        {{/if}}
        <InputGroup @name={{t "fleet-ops.ui.financeiro.multiplicadores.nome"}} @required={{true}} @wrapperClass="mb-0" as |id|>
            <input id={{id}} type="text" maxlength="40" class="form-input w-full" placeholder={{t "fleet-ops.ui.financeiro.multiplicadores.nome-exemplo"}} value={{this.nome}} disabled={{this.soFim}} {{on "input" (fn this.setCampo "nome")}} />
        </InputGroup>
        <div class="grid grid-cols-2 gap-4">
            <InputGroup @name={{t "fleet-ops.ui.financeiro.multiplicadores.mult-motoboy"}} @required={{true}} @wrapperClass="mb-0" as |id|>
                <input id={{id}} type="text" inputmode="decimal" class="form-input w-full" value={{this.multMotoboy}} disabled={{this.soFim}} {{on "input" (fn this.setCampo "multMotoboy")}} />
                <span class="text-xs text-gray-500 dark:text-gray-400">{{this.fatorMotoboy}}</span>
            </InputGroup>
            <InputGroup @name={{t "fleet-ops.ui.financeiro.multiplicadores.mult-loja"}} @required={{true}} @wrapperClass="mb-0" as |id|>
                <input id={{id}} type="text" inputmode="decimal" class="form-input w-full" value={{this.multLoja}} disabled={{this.soFim}} {{on "input" (fn this.setCampo "multLoja")}} />
                <span class="text-xs text-gray-500 dark:text-gray-400">{{this.fatorLoja}}</span>
            </InputGroup>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.multiplicadores.modal-ajuda-fator"}}</p>
        <div class="grid grid-cols-2 gap-4">
            <InputGroup @name={{t "fleet-ops.ui.financeiro.multiplicadores.inicio"}} @required={{true}} @wrapperClass="mb-0" as |id|>
                <input id={{id}} type="datetime-local" class="form-input w-full" value={{this.inicio}} disabled={{this.soFim}} {{on "change" (fn this.setCampo "inicio")}} />
            </InputGroup>
            <InputGroup @name={{t "fleet-ops.ui.financeiro.multiplicadores.fim"}} @required={{true}} @wrapperClass="mb-0" as |id|>
                <input id={{id}} type="datetime-local" class="form-input w-full" value={{this.fim}} min={{this.inicio}} {{on "change" (fn this.setCampo "fim")}} />
            </InputGroup>
        </div>
        {{#each this.erros as |erro|}}
            <p class="text-xs text-yellow-700 dark:text-yellow-300">{{erro}}</p>
        {{/each}}
    </div>
</Modal::Default>
```

`app/components/modals/periodo-multiplicador.js`:

```js
export { default } from '@fleetbase/fleetops-engine/components/modals/periodo-multiplicador';
```

- [ ] **Passo 5: Menu**

Em `addon/components/layout/fleet-ops-sidebar.js`, no `financeiroItems`, depois do item do relatório:

```js
            item('fleet-ops.ui.financeiro.menu.multiplicadores', 'cloud-rain', 'financeiro.multiplicadores', ['chuva', 'multiplicador', 'intempérie', 'adicional']),
```

E no `defaultPriorityForRoute`, troque `'financeiro.configuracoes': 4,` por:

```js
            'financeiro.multiplicadores': 4,
            'financeiro.configuracoes': 5,
```

Se o ícone `cloud-rain` não existir no Font Awesome do console, use `cloud`.

- [ ] **Passo 6: Confira**

```bash
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit $?"
for f in packages/fleetops/addon/routes/financeiro/multiplicadores.js packages/fleetops/addon/controllers/financeiro/multiplicadores.js packages/fleetops/addon/components/modals/periodo-multiplicador.js packages/fleetops/addon/components/layout/fleet-ops-sidebar.js packages/fleetops/addon/routes.js; do node -e "require('./console/node_modules/@babel/parser').parse(require('fs').readFileSync('$f','utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties']});console.log('ok $f')"; done
```
Esperado: exit 0 e `ok` em todos.

- [ ] **Passo 7: Commit**

```bash
git add packages/fleetops/addon/routes.js packages/fleetops/addon/routes/financeiro/multiplicadores.js packages/fleetops/addon/controllers/financeiro/multiplicadores.js packages/fleetops/addon/templates/financeiro/multiplicadores.hbs packages/fleetops/addon/components/modals/periodo-multiplicador.* packages/fleetops/app/components/modals/periodo-multiplicador.js packages/fleetops/addon/components/layout/fleet-ops-sidebar.js
git commit -m "Console: Financeiro → Multiplicadores (períodos de chuva: novo, começar agora, mudar o fim, encerrar, apagar)"
```

---

### Tarefa 5: Selos no extrato do motoboy, no extrato da loja e na fatura (console)

**Arquivos:**
- Modificar: `packages/fleetops/addon/controllers/financeiro/motoboy.js`, `loja.js`, `fatura.js`
- Modificar: `packages/fleetops/addon/templates/financeiro/motoboy.hbs`, `loja.hbs`, `fatura.hbs`

- [ ] **Passo 1: A action nos três controllers**

Em cada um, acrescente ao import: `import { selosDaCorrida } from '../../utils/financeiro-multiplicadores';` e a action:

```js
    /** Selos da corrida: "2ª entrega" e "Chuva ×1,2" (o fator desta conta, dos detalhes do lançamento). */
    @action selos(detalhes) {
        return selosDaCorrida(detalhes, this.intl.primaryLocale);
    }
```

- [ ] **Passo 2: Templates**

O mesmo trecho nos três, com a variável de cada um (`lancamento` no motoboy e na loja, `item` na fatura):

```hbs
{{#each (this.selos lancamento.detalhes) as |selo|}}
    <Badge @status="info" @hideStatusDot={{true}}>{{t (concat "fleet-ops.ui.financeiro.selos." selo.chave) posicao=selo.params.posicao nome=selo.params.nome fator=selo.params.fator}}</Badge>
{{/each}}
```

- `motoboy.hbs`: logo depois do `{{#if lancamento.detalhes.cancelado_pago}}…{{/if}}` (linha ~157–159), dentro do mesmo `div.flex`.
- `loja.hbs`: depois do bloco `{{#if lancamento.detalhes.km}} · {{this.km …}}{{/if}}` (linha ~95–97), antes do destino.
- `fatura.hbs`: dentro do `{{#if (eq item.tipo "corrida")}}` da coluna do motivo (linha ~123), depois do selo "Não cobrada", trocando `lancamento` por `item`.

- [ ] **Passo 3: Confira e commit**

```bash
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit $?"
for f in motoboy loja fatura; do node -e "require('./console/node_modules/@babel/parser').parse(require('fs').readFileSync('packages/fleetops/addon/controllers/financeiro/$f.js','utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties']});console.log('ok $f')"; done
git add packages/fleetops/addon/controllers/financeiro/motoboy.js packages/fleetops/addon/controllers/financeiro/loja.js packages/fleetops/addon/controllers/financeiro/fatura.js packages/fleetops/addon/templates/financeiro/motoboy.hbs packages/fleetops/addon/templates/financeiro/loja.hbs packages/fleetops/addon/templates/financeiro/fatura.hbs
git commit -m "Console: selos de entrega junto e multiplicador no extrato do motoboy, da loja e na fatura"
```

---

### Tarefa 6: Portal da loja

**Arquivos:**
- Modificar: `packages/customer-portal/addon/utils/entregas-financeiro.js`
- Modificar: `scripts/teste-portal/entregas-financeiro.test.mjs`
- Modificar: `packages/customer-portal/addon/controllers/portal/extrato.js`, `fatura.js`
- Modificar: `packages/customer-portal/addon/templates/portal/extrato.hbs`, `fatura.hbs`
- Modificar: `packages/customer-portal/translations/pt-br.yaml`, `en-us.yaml`

- [ ] **Passo 1: Teste**

No `scripts/teste-portal/entregas-financeiro.test.mjs`, acrescente `selosDaCorrida` ao import e:

```js
test('selos da corrida no portal: 2ª entrega e o multiplicador da loja', () => {
    assert.deepEqual(selosDaCorrida({ junto: 2, multiplicador: { nome: 'Chuva', fator: 1.1 } }), [
        { chave: 'junto', params: { posicao: 2 } },
        { chave: 'multiplicador', params: { nome: 'Chuva', fator: '×1,1' } },
    ]);
    assert.deepEqual(selosDaCorrida({ junto: null, multiplicador: null }), []);
    assert.deepEqual(selosDaCorrida(undefined), []);
});
```

Rode: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/entregas-financeiro.test.mjs`. Esperado: falha (`selosDaCorrida` não existe).

- [ ] **Passo 2: Implemente no `entregas-financeiro.js` do portal** (no fim do arquivo):

```js
/**
 * Selos da corrida (extrato: a própria entrega; fatura: item.detalhes): "2ª entrega" (entrega junto) e "Chuva ×1,1" (o
 * multiplicador da loja; a API só manda quando o valor da loja mudou). [{chave, params}] em customer-portal.ui.entregas.selos.*.
 * Cópia da regra do console (packages/fleetops/addon/utils/financeiro-multiplicadores.js): os pacotes não importam um do outro.
 */
export function selosDaCorrida(dados, locale = 'pt-BR') {
    const selos = [];
    if (Number(dados?.junto) >= 2) {
        selos.push({ chave: 'junto', params: { posicao: Number(dados.junto) } });
    }
    const fator = Number(dados?.multiplicador?.fator);
    if (dados?.multiplicador && Number.isFinite(fator)) {
        selos.push({ chave: 'multiplicador', params: { nome: dados.multiplicador.nome || '', fator: `×${new Intl.NumberFormat(locale, { maximumFractionDigits: 2 }).format(fator)}` } });
    }

    return selos;
}
```

Rode o teste de novo: passa.

- [ ] **Passo 3: Controllers e templates**

Nos dois controllers (`extrato.js`, `fatura.js`), acrescente `selosDaCorrida` ao import de `'../../utils/entregas-financeiro'` (no `extrato.js` é um import novo) e:

```js
    /** Entregas: selos "2ª entrega" e "Chuva ×1,1" (só quando o valor da loja mudou). */
    @action selos(dados) {
        return selosDaCorrida(dados, this.intl.primaryLocale);
    }
```

O selo no estilo em linha do portal (as regras do portal sobrescrevem as classes; o selo do cancelado já faz assim):

`extrato.hbs`, na última coluna, depois do `{{/if}}` do cancelado (linha ~100):

```hbs
                                    {{#each (this.selos entrega) as |selo|}}
                                        <span class="inline-flex items-center whitespace-nowrap rounded px-1.5 py-0.5 text-xs font-bold" style="background-color: #DBEAFE; color: #1E40AF;">{{t (concat "customer-portal.ui.entregas.selos." selo.chave) posicao=selo.params.posicao nome=selo.params.nome fator=selo.params.fator}}</span>
                                    {{/each}}
```

`fatura.hbs`, na coluna da descrição (linha ~71), troque `<td><div class="max-w-sm">{{this.descricao item}}</div></td>` por:

```hbs
                                <td>
                                    <div class="max-w-sm">
                                        {{this.descricao item}}
                                        {{#each (this.selos item.detalhes) as |selo|}}
                                            <span class="inline-flex items-center whitespace-nowrap rounded px-1.5 py-0.5 text-xs font-bold" style="background-color: #DBEAFE; color: #1E40AF;">{{t (concat "customer-portal.ui.entregas.selos." selo.chave) posicao=selo.params.posicao nome=selo.params.nome fator=selo.params.fator}}</span>
                                        {{/each}}
                                    </div>
                                </td>
```

- [ ] **Passo 4: Traduções do portal**

No `customer-portal/translations/pt-br.yaml`, depois de `      canceled-charged-column: "Cancelado (cobrado)"` (linha ~732):

```yaml
      selos:
        junto: "{posicao}ª entrega"
        multiplicador: "{nome} {fator}"
```

No `en-us.yaml`, no mesmo ponto:

```yaml
      selos:
        junto: "Delivery #{posicao} together"
        multiplicador: "{nome} {fator}"
```

- [ ] **Passo 5: Confira e commit**

```bash
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit $?"
git add packages/customer-portal scripts/teste-portal/entregas-financeiro.test.mjs
git commit -m "Portal da loja: selos de entrega junto e multiplicador no extrato e na fatura"
```

---

### Tarefa 7: Documentação e conferência final

- [ ] **Passo 1: `packages/fleetops/CLAUDE.md`**, no bloco "Tabela de km e relatório", acrescente:

```markdown
  - **Segunda tabela ("A partir da 2ª entrega junto")**: mesmo editor, `PUT .../faixas-juntas`; vazia = desligada (botão "Desligar"). Selos e colunas "Entrega junto" e "Multiplicador" no relatório e no CSV. Regras no servidor: "Entrega junto e multiplicador" em `api/app/Support/Entregas/Financeiro/CLAUDE.md`.
```

E, na lista do Financeiro, depois de "Configurações":

```markdown
  - **Multiplicadores** (`financeiro.multiplicadores`): período em andamento em destaque (mudar o fim, encerrar agora), agendados (editar, apagar), encerrados recolhidos; modal `periodo-multiplicador` (em andamento: só o fim). Funções puras em `addon/utils/financeiro-multiplicadores.js` (teste `scripts/teste-portal/financeiro-multiplicadores.test.mjs`). Datas: `datetime-local` em Brasília; para preencher a partir do servidor, `campoDoInstante` (a hora escrita no ISO).
```

Em `packages/customer-portal/CLAUDE.md`, na seção do Financeiro da loja: "Selos "2ª entrega" e "<nome> ×fator" (`selosDaCorrida`) no extrato e na fatura, só quando o valor da loja mudou (a API filtra)."

Os `CLAUDE.md` por pasta podem estar fora do git nesta máquina (`git status` mostra `??`): edite e só inclua no commit os que já estão versionados.

- [ ] **Passo 2: Bateria completa**

```bash
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit $?"
```
Esperado: tudo passa, exit 0.

- [ ] **Passo 3: Conferência visual (opcional, com o console local se der para subir)**

Sem build local, a conferência é em produção depois do deploy:
1. Financeiro → Tabela de km: a segunda tabela aparece vazia; cadastre uma faixa e salve; recarregue (Ctrl+Shift+R) e ela continua.
2. Financeiro → Multiplicadores: "Começar agora" com ×1,01 e fim em 10 min; o período aparece em andamento; "Encerrar agora".
3. Não faça pedido de teste no Terraço (loja real) sem combinar com o Edgard.

- [ ] **Passo 4: Commit da documentação**

```bash
git status --short
git add <os CLAUDE.md versionados que mudaram>
git commit -m "docs: telas da entrega junto e dos multiplicadores no console e no portal"
```

## Implantação (Edgard, na VPS)

Depois do deploy da API (plano do servidor): `cd ~/entregas && bash deploy/atualizar.sh console` (~15 min). Depois, Ctrl+Shift+R no console.
