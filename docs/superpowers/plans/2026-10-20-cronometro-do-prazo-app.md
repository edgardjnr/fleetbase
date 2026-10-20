# Cronômetro do prazo de entrega (app do motoboy): plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Objetivo:** o app do motoboy mostra quanto falta para o prazo de entrega ("32 min" em verde, "12 min" em amarelo,
"Atrasado há 7 min" em vermelho) no card de "Em andamento" da aba Pedidos e na linha da etapa da folha do pedido, e o
rótulo com o nome do motoboy no alfinete do Mapa do líder ganha o fundo na cor do prazo.

**Arquitetura:** a regra fica numa função pura sem imports (`src/utils/prazo.ts`: `prazoDoPedido`, `cronometro`,
`chaveDoTexto`, `coresDoPrazo`), testada com o Node puro. O prazo vem do servidor (outro plano) em
`meta.entregas_prazo_em` no recurso do pedido da API v1 e em `prazo_em` em cada pedido do `GET v1/entregas/lider/mapa`
(lido por `lerMapa`). Um componente pequeno (`CronometroDoPrazo`, com `memo`) chama o hook `useAgora(30000)` por dentro:
só o chip se redesenha a cada 30 s, nunca a folha nem a lista. O Mapa do líder já se redesenha a cada leitura (10 s) e
calcula a cor na hora de desenhar, sem relógio próprio. Sem a chave (servidor de hoje ou pedido de antes do deploy), nada
aparece: o app funciona com o servidor antigo.

**Tecnologia:** React Native 0.86 + Tamagui (temas `lightEntregas`/`darkEntregas`), react-native-i18n
(`translations/pt.json` e `en.json`), react-native-maps, testes com `node --experimental-strip-types --test`, build do
APK no GitHub Actions (`build-apk.yml`).

**Spec:** `docs/superpowers/specs/2026-10-20-cronometro-do-prazo-design.md` (no Delivery). Este plano cobre só o app
(`entregas-navigator`). O servidor (`PrazoDaEntrega`, o hook `Order::creating`, o `prazo_em` do `PedidosNoMapa`), o
console e o portal ficam no plano do Delivery.

---

## Onde trabalhar

O app (`entregas-navigator`) está em `C:\Users\Edgardjr\Documents\vibe coding\entregas-navigator`, na `main`, com um
`CLAUDE.md` **não rastreado** do Edgard: não o commite nem o apague. Todo o código vai na worktree `/c/tmp/cra`, ramo
`cronometro-do-prazo`, criada na Tarefa 0 a partir da `main` (hoje em `6778b18`, o merge da Comanda).

- **Testes do app:** `node --experimental-strip-types --test scripts/testes/<arquivo>.teste.ts` (na raiz da worktree). O
  Node avisa `ExperimentalWarning: Type Stripping is an experimental feature`: é normal. O resultado sai no fim, em
  `# pass N` e `# fail N`.
- **Funções em `src/utils/*.ts` não importam nada** (nem umas das outras): o Node exige o `.ts` no import e o `tsc`
  recusa. Por isso o `prazo.ts` tem o próprio `ler` (como `comanda.ts`, `lista-de-pedidos.ts` e `cartao-do-pedido.ts`) e
  o `mapa-do-lider.ts` não importa o `prazo.ts`: quem junta os dois é a tela. Os componentes importam os utils sem
  extensão, como sempre.
- **Fim de linha:** o repo tem `core.autocrlf=true` e os arquivos estão com CRLF na pasta. A ferramenta Edit lida com
  isso; num script, troque `\n` por `\r\n` antes de procurar o trecho.
- **`tsc`:** o `tsconfig.json` do app não liga o JSX, então o `npx tsc --noEmit -p .` puro despeja milhares de erros
  falsos. Use sempre o script da Tarefa 0 (`/c/tmp/cr-tsc.sh`), que passa
  `--jsx react-native --esModuleInterop --allowImportingTsExtensions --skipLibCheck` e compara com a base tirada antes de
  mexer: **nenhum erro novo**. Os testes em `scripts/testes/` também passam pelo `tsc` (o `tsconfig.json` não tem
  `include`).
- **`node_modules`:** a worktree não tem; a Tarefa 0 cria uma junção para o da pasta principal (não instala nada).
  **Antes de remover a worktree** (`git worktree remove`), apague a junção com `cmd //c rmdir 'C:\tmp\cra\node_modules'`:
  remover a pasta com a junção dentro pode apagar o `node_modules` da pasta principal.
- **Bundle:** o app não tem script de bundle. A verificação do bundle é o build do GitHub Actions do ramo (Tarefa 9).
- **Merge na `main`: não.** Cada build da `main` vira versão mínima (trava "Atualize o app", ver
  `Delivery/api/app/Http/Controllers/Entregas/CLAUDE.md`). O merge é só com o Edgard, depois do build de teste do ramo e
  do teste no celular.

## Arquivos

| Arquivo (no `entregas-navigator`) | O que muda |
|---|---|
| `src/utils/prazo.ts` (novo) | `prazoDoPedido`, `cronometro`, `chaveDoTexto`, `coresDoPrazo`, `MINUTOS_PERTO`, `INTERVALO_DO_CRONOMETRO_MS` |
| `scripts/testes/prazo.teste.ts` (novo) | testes do `prazo.ts` |
| `src/utils/mapa-do-lider.ts` | `prazo_em` no tipo `PedidoDoLider` e no `lerMapa` |
| `scripts/testes/mapa-do-lider.teste.ts` | `prazo_em: null` na fábrica `pedido()` e um teste do `lerMapa` |
| `translations/pt.json`, `translations/en.json` | grupo novo `Prazo` e `MapaDoLider.legendaItens.prazo` |
| `src/hooks/use-agora.ts` (novo) | o relógio do celular refeito a cada N ms e na volta do app para a frente |
| `src/components/entregas/CronometroDoPrazo.tsx` (novo) | o chip do cronômetro (relógio, texto, fundo na cor do estado) |
| `src/components/OrderCard.tsx` | o cronômetro na linha dos selos do card de Em andamento |
| `src/screens/OrderScreen.tsx` | o cronômetro na linha da etapa da folha (só aguardando, indo à loja e a caminho do cliente) |
| `src/screens/MapaDoLiderScreen.tsx` | o rótulo do alfinete na cor do prazo e uma linha na legenda |
| `src/components/entregas/README.md` | o componente novo |

Documentação fora da worktree (arquivos **não rastreados**, editados na pasta principal e **não commitados**, Tarefa 8):
`entregas-navigator/CLAUDE.md` e `Delivery/api/app/Http/Controllers/Entregas/CLAUDE.md` (as duas cópias da seção "App do
motoboy").

## Como o plano lê a spec (pontos de interpretação)

- **O texto do cronômetro:** a spec descreve `cronometro(prazoIso, agora) → { minutos, estado, texto }`. No app o texto
  passa pelas traduções (pt e en): a função pura devolve `{ minutos, estado }`, `chaveDoTexto` diz qual texto usar
  (`Prazo.faltam` = "{{minutos}} min" ou `Prazo.atrasado` = "Atrasado há {{minutos}} min") e o componente chama o `t()`.
  `agora` é um número em ms (`Date.now()`).
- **Arredondamento e limites** (iguais aos da spec): falta mais de 15 min → `no_prazo`; de 15 min exatos até 1 s →
  `perto`; 0 ou menos → `atrasado`. Enquanto falta, minutos para cima (59 s = 1 min; 15 min e 1 s = 16 min, verde); depois
  do prazo, para baixo, com mínimo 1 (no instante do prazo e no primeiro minuto de atraso: "Atrasado há 1 min").
- **Cores:** fundo cheio com os tokens que já existem nos dois temas, `successBorder`/`warningBorder`/`errorBorder` com o
  texto em `successText`/`warningText`/`errorText`. As variantes "Border" são as mais escuras no tema claro; com elas o
  contraste do texto fica acima de 4,5:1 nos dois temas (o selo suave `sucessoSuave` + `success` dá ~3:1 no claro).
- **Onde aparece no app:** no `OrderCard` (só a seção "Em andamento" usa esse card) e na folha da corrida da
  `OrderScreen` (só existe no pedido dele: o pedido aberto e a oferta mostram o cartão da oferta, sem folha). Na folha, só
  nas etapas `aguardando`, `loja` e `cliente`: some no concluído (`concluida`) e o cancelado nem tem folha. O pedido
  atribuído pela central e ainda não iniciado (`aguardando`) também mostra: ele já está em "Em andamento".
- **Mapa do líder:** o rótulo do alfinete do app mostra só o nome do motoboy ("Carlos"; o "#4821 · Carlos" é o do console
  e do portal). O plano muda só o fundo, não o texto (ver "Pontos para o Edgard"). Pedido sem prazo (de antes do deploy):
  escuro neutro (`$textPrimary` com texto `$background`), para não parecer atrasado; hoje o rótulo é sempre vermelho
  escuro. Pedido sem motoboy aceito continua sem rótulo. A cor é refeita a cada leitura do mapa (10 s; até 60 s quando a
  leitura falha seguidas vezes).
- **Legenda do Mapa do líder:** ganha uma linha ("Nome no pedido: no prazo, perto, atrasado") com as três cores, porque
  verde, amarelo e vermelho também são as cores dos capacetes. Não está na spec; é uma linha (ver "Pontos para o Edgard").

---

### Tarefa 0: worktree, junção do `node_modules` e base do `tsc`

**Arquivos:** nenhum no repositório. Cria `/c/tmp/cra` (worktree) e `/c/tmp/cr-tsc.sh` (fora do repositório).

- [ ] **Passo 1: conferir a pasta principal**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator"
git rev-parse --show-toplevel
git status --short
git log --oneline -1
ls /c/tmp/cra 2>&1 | head -1
ls /c/tmp/cr-tsc.sh 2>&1 | head -1
```

Esperado: `C:/Users/Edgardjr/Documents/vibe coding/entregas-navigator`, só `?? CLAUDE.md` no status, a `main` em
`6778b18 Merge branch 'comanda-do-pedido'` (ou mais nova), `ls: cannot access '/c/tmp/cra'` e
`ls: cannot access '/c/tmp/cr-tsc.sh'`. Se `/c/tmp/cra` existir, pare e pergunte.

- [ ] **Passo 2: criar a worktree**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator"
git worktree add /c/tmp/cra -b cronometro-do-prazo main
```

Esperado: `Preparing worktree (new branch 'cronometro-do-prazo')`.

- [ ] **Passo 3: junção do `node_modules`** (PowerShell)

```powershell
New-Item -ItemType Junction -Path "C:\tmp\cra\node_modules" -Target "C:\Users\Edgardjr\Documents\vibe coding\entregas-navigator\node_modules" | Select-Object -ExpandProperty FullName
```

Esperado: `C:\tmp\cra\node_modules`. O `node_modules/` está no `.gitignore`: não entra em commit.

- [ ] **Passo 4: o script do `tsc` e a base**

```bash
cat > /c/tmp/cr-tsc.sh <<'FIM'
#!/usr/bin/env bash
# Compara os erros do tsc (sem números de linha) com a base. Uso: bash /c/tmp/cr-tsc.sh antes | bash /c/tmp/cr-tsc.sh
cd /c/tmp/cra || exit 1
npx tsc --noEmit -p . --jsx react-native --esModuleInterop --allowImportingTsExtensions --skipLibCheck 2>&1 | grep "error TS" | sed -E 's/\([0-9]+,[0-9]+\)//' | sort -u > /c/tmp/cr-tsc-agora.txt
if [ "$1" = "antes" ]; then
    cp /c/tmp/cr-tsc-agora.txt /c/tmp/cr-tsc-antes.txt
    echo "base: $(wc -l < /c/tmp/cr-tsc-antes.txt) erros distintos"
    exit 0
fi
NOVOS=$(comm -13 /c/tmp/cr-tsc-antes.txt /c/tmp/cr-tsc-agora.txt)
if [ -z "$NOVOS" ]; then
    echo "tsc: nenhum erro novo"
else
    echo "tsc: ERROS NOVOS:"
    echo "$NOVOS"
    exit 1
fi
FIM
bash /c/tmp/cr-tsc.sh antes
```

Esperado: `base: N erros distintos`, com N perto de 400 (em 2026-10-20 a `main` dava 460 linhas de erro antes de tirar
os números de linha; o que vale é a comparação depois). Leva de 5 s a 1 min.

- [ ] **Passo 5: os testes de hoje passam**

```bash
cd /c/tmp/cra
node --experimental-strip-types --test scripts/testes/*.teste.ts 2>&1 | grep -E "^# (tests|pass|fail)"
```

Esperado: `# tests 161`, `# pass 161` e `# fail 0`.

---

### Tarefa 1: a regra do cronômetro (`src/utils/prazo.ts`)

**Arquivos:**
- Criar: `src/utils/prazo.ts`
- Teste: `scripts/testes/prazo.teste.ts` (novo)

- [ ] **Passo 1: escrever o teste que falha**

Crie `scripts/testes/prazo.teste.ts`:

```ts
// Testes das funções puras do cronômetro do prazo de entrega (src/utils/prazo.ts), com o Node puro:
//   node --experimental-strip-types --test scripts/testes/prazo.teste.ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { INTERVALO_DO_CRONOMETRO_MS, MINUTOS_PERTO, chaveDoTexto, coresDoPrazo, cronometro, prazoDoPedido } from '../../src/utils/prazo.ts';

// como o recurso Order do SDK: getAttribute com caminho de pontos
const recurso = (atributos: Record<string, any>) => ({
    id: atributos.id,
    getAttribute: (caminho: string) => caminho.split('.').reduce((atual: any, chave) => (atual == null ? undefined : atual[chave]), atributos),
});

const PRAZO = '2026-10-20T19:57:51-03:00';
const em = (iso: string) => Date.parse(iso);

test('constantes: 15 min para o amarelo e 30 s entre atualizações', () => {
    assert.equal(MINUTOS_PERTO, 15);
    assert.equal(INTERVALO_DO_CRONOMETRO_MS, 30000);
});

test('no prazo: mais de 15 min, minutos arredondados para cima', () => {
    assert.deepEqual(cronometro(PRAZO, em('2026-10-20T19:25:51-03:00')), { minutos: 32, estado: 'no_prazo' });
    assert.deepEqual(cronometro(PRAZO, em('2026-10-20T19:25:52-03:00')), { minutos: 32, estado: 'no_prazo' });
    assert.deepEqual(cronometro(PRAZO, em('2026-10-20T19:42:50-03:00')), { minutos: 16, estado: 'no_prazo' });
});

test('perto: de 15 min exatos até 1 s, 59 s = 1 min', () => {
    assert.deepEqual(cronometro(PRAZO, em('2026-10-20T19:42:51-03:00')), { minutos: 15, estado: 'perto' });
    assert.deepEqual(cronometro(PRAZO, em('2026-10-20T19:45:51-03:00')), { minutos: 12, estado: 'perto' });
    assert.deepEqual(cronometro(PRAZO, em('2026-10-20T19:56:52-03:00')), { minutos: 1, estado: 'perto' });
    assert.deepEqual(cronometro(PRAZO, em('2026-10-20T19:57:50-03:00')), { minutos: 1, estado: 'perto' });
});

test('atrasado: no instante do prazo e depois, minutos para baixo, nunca 0', () => {
    assert.deepEqual(cronometro(PRAZO, em(PRAZO)), { minutos: 1, estado: 'atrasado' });
    assert.deepEqual(cronometro(PRAZO, em('2026-10-20T19:58:50-03:00')), { minutos: 1, estado: 'atrasado' });
    assert.deepEqual(cronometro(PRAZO, em('2026-10-20T19:59:50-03:00')), { minutos: 1, estado: 'atrasado' });
    assert.deepEqual(cronometro(PRAZO, em('2026-10-20T19:59:51-03:00')), { minutos: 2, estado: 'atrasado' });
    assert.deepEqual(cronometro(PRAZO, em('2026-10-20T20:04:51-03:00')), { minutos: 7, estado: 'atrasado' });
    assert.deepEqual(cronometro(PRAZO, em('2026-10-20T21:57:51-03:00')), { minutos: 120, estado: 'atrasado' });
});

test('fuso: o mesmo instante com Z ou com -03:00, e espaços em volta', () => {
    assert.deepEqual(cronometro('2026-10-20T22:57:51Z', em('2026-10-20T19:25:51-03:00')), { minutos: 32, estado: 'no_prazo' });
    assert.deepEqual(cronometro(PRAZO, em('2026-10-20T22:45:51Z')), { minutos: 12, estado: 'perto' });
    assert.deepEqual(cronometro(` ${PRAZO} `, em('2026-10-20T19:45:51-03:00')), { minutos: 12, estado: 'perto' });
});

test('sem prazo: ausente, vazio, não texto ou ilegível', () => {
    const agora = em('2026-10-20T19:25:51-03:00');
    for (const valor of [undefined, null, '', '   ', 'amanhã', '2026-13-45T99:99:99-03:00', 1792537071000, {}, []]) {
        assert.equal(cronometro(valor, agora), null, `valor: ${JSON.stringify(valor)}`);
    }
    assert.equal(cronometro(PRAZO, NaN), null);
});

test('prazo do pedido: meta.entregas_prazo_em no recurso do SDK ou serializado', () => {
    assert.equal(prazoDoPedido({ meta: { entregas_prazo_em: PRAZO } }), PRAZO);
    assert.equal(prazoDoPedido(recurso({ id: 'order_1', meta: { entregas_prazo_em: ` ${PRAZO} ` } })), PRAZO);
    assert.equal(prazoDoPedido({ meta: { currency: 'BRL' } }), null);
    assert.equal(prazoDoPedido({ meta: [] }), null);
    assert.equal(prazoDoPedido({ meta: null }), null);
    assert.equal(prazoDoPedido({ meta: { entregas_prazo_em: '  ' } }), null);
    assert.equal(prazoDoPedido({ meta: { entregas_prazo_em: 1792537071 } }), null);
    assert.equal(prazoDoPedido({}), null);
    assert.equal(prazoDoPedido(null), null);
});

test('texto e cores pelo estado; sem prazo, escuro neutro', () => {
    assert.equal(chaveDoTexto({ minutos: 32, estado: 'no_prazo' }), 'Prazo.faltam');
    assert.equal(chaveDoTexto({ minutos: 12, estado: 'perto' }), 'Prazo.faltam');
    assert.equal(chaveDoTexto({ minutos: 7, estado: 'atrasado' }), 'Prazo.atrasado');
    assert.deepEqual(coresDoPrazo('no_prazo'), { fundo: '$successBorder', texto: '$successText' });
    assert.deepEqual(coresDoPrazo('perto'), { fundo: '$warningBorder', texto: '$warningText' });
    assert.deepEqual(coresDoPrazo('atrasado'), { fundo: '$errorBorder', texto: '$errorText' });
    assert.deepEqual(coresDoPrazo(null), { fundo: '$textPrimary', texto: '$background' });
});
```

O `{ meta: [] }` é o `meta` vazio que o PHP serializa como lista.

- [ ] **Passo 2: rodar e ver falhar**

```bash
cd /c/tmp/cra
node --experimental-strip-types --test scripts/testes/prazo.teste.ts 2>&1 | grep -E "ERR_MODULE_NOT_FOUND|^# (pass|fail)"
```

Esperado: `ERR_MODULE_NOT_FOUND` (não existe `src/utils/prazo.ts`) e `# fail 1`.

- [ ] **Passo 3: implementar**

Crie `src/utils/prazo.ts`:

```ts
// Entregas: o cronômetro do prazo de entrega (decisão de 2026-10-20; spec `docs/superpowers/specs/2026-10-20-cronometro-do-prazo-design.md`,
// no Delivery). O servidor grava o prazo no pedido ao criá-lo (meta.entregas_prazo_em, ISO com -03:00): no iFood, a hora
// prometida ao cliente; nos outros, 50 min depois da criação (ou do horário agendado). O Mapa do líder recebe o mesmo
// prazo em `prazo_em` (PedidosNoMapa::doLider). A tela mostra quanto falta: verde com mais de 15 min, amarelo com 15 min
// ou menos e vermelho depois do prazo ("Atrasado há 7 min"), pelo relógio do celular, refeito a cada 30 s. Pedido sem a
// chave (criado antes do deploy) ou com data ilegível: sem cronômetro. A mesma regra do console e do portal da loja.
// Os pedidos chegam como recurso do SDK (getAttribute) ou serializados (objeto comum): as funções leem os dois.
// Sem imports, para os testes rodarem com o Node puro: node --experimental-strip-types --test scripts/testes/prazo.teste.ts

export type EstadoDoPrazo = 'no_prazo' | 'perto' | 'atrasado';

export type Cronometro = { minutos: number; estado: EstadoDoPrazo };

/** Com 15 min ou menos para o prazo, o cronômetro fica amarelo (perto). */
export const MINUTOS_PERTO = 15;

/** De quanto em quanto tempo o cronômetro é refeito na tela (useAgora). */
export const INTERVALO_DO_CRONOMETRO_MS = 30 * 1000;

const MINUTO_MS = 60 * 1000;

const ler = (pedido: any, caminho: string): any => {
    if (!pedido) return undefined;
    if (typeof pedido.getAttribute === 'function') return pedido.getAttribute(caminho);
    return caminho.split('.').reduce((atual: any, chave) => (atual === null || atual === undefined ? undefined : atual[chave]), pedido);
};

/** O prazo gravado no pedido (meta.entregas_prazo_em). Sem a chave, vazio ou não texto: null. */
export function prazoDoPedido(pedido: any): string | null {
    const bruto = ler(pedido, 'meta.entregas_prazo_em');
    return typeof bruto === 'string' && bruto.trim() !== '' ? bruto.trim() : null;
}

/**
 * Quanto falta para o prazo, em minutos, e o estado. Enquanto falta, os minutos arredondam para cima (59 s = "1 min");
 * depois do prazo, para baixo, mas o primeiro minuto de atraso já é 1 ("Atrasado há 0 min" não existe). Mais de 15 min:
 * no_prazo; de 15 min a 1 s: perto; 0 ou menos: atrasado. Prazo ausente, não texto ou ilegível (ou agora inválido): null.
 */
export function cronometro(prazoIso: unknown, agora: number): Cronometro | null {
    if (typeof prazoIso !== 'string' || prazoIso.trim() === '') return null;
    const prazo = Date.parse(prazoIso.trim());
    if (!Number.isFinite(prazo) || !Number.isFinite(agora)) return null;
    const restante = prazo - agora;
    if (restante <= 0) return { minutos: Math.max(1, Math.floor(-restante / MINUTO_MS)), estado: 'atrasado' };
    return { minutos: Math.ceil(restante / MINUTO_MS), estado: restante > MINUTOS_PERTO * MINUTO_MS ? 'no_prazo' : 'perto' };
}

/** A chave do texto do cronômetro (translations, grupo Prazo, com {{minutos}}): "32 min" ou "Atrasado há 7 min". */
export function chaveDoTexto(atual: Cronometro): 'Prazo.faltam' | 'Prazo.atrasado' {
    return atual.estado === 'atrasado' ? 'Prazo.atrasado' : 'Prazo.faltam';
}

/**
 * As cores do cronômetro e do rótulo do alfinete (tokens do tema, fundo cheio e texto em contraste, legíveis no claro e no
 * escuro): verde, amarelo e vermelho (as variantes "Border", mais escuras no tema claro, para o texto branco ler bem).
 * Sem prazo (null): escuro neutro, para o pedido antigo não parecer atrasado.
 */
export function coresDoPrazo(estado: EstadoDoPrazo | null): { fundo: string; texto: string } {
    if (estado === 'no_prazo') return { fundo: '$successBorder', texto: '$successText' };
    if (estado === 'perto') return { fundo: '$warningBorder', texto: '$warningText' };
    if (estado === 'atrasado') return { fundo: '$errorBorder', texto: '$errorText' };
    return { fundo: '$textPrimary', texto: '$background' };
}
```

O `Date.parse` de ISO com fuso (`-03:00` ou `Z`) é o mesmo que o `oferta.ts` já usa no Hermes (`vence_em`).

- [ ] **Passo 4: rodar e ver passar**

```bash
cd /c/tmp/cra
node --experimental-strip-types --test scripts/testes/prazo.teste.ts 2>&1 | grep -E "^# (pass|fail)"
bash /c/tmp/cr-tsc.sh
```

Esperado: `# pass 8`, `# fail 0` e `tsc: nenhum erro novo`.

- [ ] **Passo 5: commit**

```bash
cd /c/tmp/cra
git add src/utils/prazo.ts scripts/testes/prazo.teste.ts
git commit -m "Prazo: regra do cronômetro (minutos, estado, texto e cores) pelo meta.entregas_prazo_em

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 2: o prazo de cada pedido no Mapa do líder (`lerMapa`)

**Arquivos:**
- Modificar: `src/utils/mapa-do-lider.ts:11-25` (tipo `PedidoDoLider`) e `:82-83` (`lerMapa`)
- Teste: `scripts/testes/mapa-do-lider.teste.ts:28-43` (fábrica `pedido()`) e um teste novo no fim

- [ ] **Passo 1: escrever o teste que falha**

Em `scripts/testes/mapa-do-lider.teste.ts`, troque (linhas 41 e 42):

```ts
    coleta: LOJA,
    ...extra,
```

por:

```ts
    coleta: LOJA,
    prazo_em: null,
    ...extra,
```

E acrescente no fim do arquivo:

```ts

test('lerMapa: o prazo de entrega de cada pedido (prazo_em), texto ou null', () => {
    const mapa = lerMapa({
        pedidos: [
            { ...pedido(), prazo_em: '2026-10-06T09:20:00-03:00' },
            { ...pedido({ id: 'order_2' }), prazo_em: '   ' },
            { ...pedido({ id: 'order_3' }), prazo_em: 1792537071 },
            { id: 'order_4', numero: '4822', latitude: -21.18, longitude: -47.81 },
        ],
        motoboys: [],
    });
    assert.deepEqual(
        mapa.pedidos.map((p) => [p.id, p.prazo_em]),
        [
            ['order_1', '2026-10-06T09:20:00-03:00'],
            ['order_2', null],
            ['order_3', null],
            ['order_4', null],
        ]
    );
});
```

O `order_4` é a resposta do servidor de hoje (sem a chave `prazo_em`).

- [ ] **Passo 2: rodar e ver falhar**

```bash
cd /c/tmp/cra
node --experimental-strip-types --test scripts/testes/mapa-do-lider.teste.ts 2>&1 | grep -E "^not ok|^# (pass|fail)"
```

Esperado: `not ok 11 - lerMapa: o prazo de entrega de cada pedido (prazo_em), texto ou null`, `# pass 10` e `# fail 1`
(o `lerMapa` ainda não lê a chave: sai `undefined`).

- [ ] **Passo 3: implementar**

Em `src/utils/mapa-do-lider.ts`, no tipo `PedidoDoLider`, troque (linhas 24 e 25):

```ts
    coleta: Ponto | null;
};
```

por:

```ts
    coleta: Ponto | null;
    /** O prazo de entrega (ISO com -03:00, do meta.entregas_prazo_em); sem prazo (pedido de antes do deploy): null. */
    prazo_em: string | null;
};
```

E no `lerMapa`, troque (linhas 82 e 83):

```ts
            coleta: item.coleta ? pontoValido(item.coleta.latitude, item.coleta.longitude) : null,
        });
```

por:

```ts
            coleta: item.coleta ? pontoValido(item.coleta.latitude, item.coleta.longitude) : null,
            prazo_em: textoOuNulo(item.prazo_em),
        });
```

O `textoOuNulo` já existe no arquivo (linha 56). A troca de motoboy (`executarTroca` na `MapaDoLiderScreen`) também passa
a resposta por `lerMapa`, então o cartão atualizado mantém o prazo sem mudança na tela.

- [ ] **Passo 4: rodar e ver passar**

```bash
cd /c/tmp/cra
node --experimental-strip-types --test scripts/testes/mapa-do-lider.teste.ts 2>&1 | grep -E "^# (pass|fail)"
bash /c/tmp/cr-tsc.sh
```

Esperado: `# pass 11`, `# fail 0` e `tsc: nenhum erro novo`.

- [ ] **Passo 5: commit**

```bash
cd /c/tmp/cra
git add src/utils/mapa-do-lider.ts scripts/testes/mapa-do-lider.teste.ts
git commit -m "Mapa do líder: lê o prazo de entrega de cada pedido (prazo_em)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 3: textos (grupo `Prazo` e a linha da legenda)

**Arquivos:**
- Modificar: `translations/pt.json` e `translations/en.json` (grupo novo `Prazo`, no fim; chave nova
  `MapaDoLider.legendaItens.prazo`)

Os textos entram antes das telas: as Tarefas 4 a 7 já os encontram.

- [ ] **Passo 1: acrescentar os textos**

O script reescreve os JSON com 4 espaços e CRLF, o mesmo formato de hoje (conferido em 2026-10-20:
`JSON.stringify(…, null, 4)` com CRLF reproduz os dois arquivos byte a byte).

```bash
cd /c/tmp/cra
node - <<'FIM'
const fs = require('fs');
const grupos = {
    pt: { rotulo: 'Prazo', faltam: '{{minutos}} min', atrasado: 'Atrasado há {{minutos}} min' },
    en: { rotulo: 'Deadline', faltam: '{{minutos}} min', atrasado: 'Late by {{minutos}} min' },
};
const legenda = {
    pt: 'Nome no pedido: no prazo, perto, atrasado',
    en: 'Name on the order: on time, close, late',
};
for (const [lingua, grupo] of Object.entries(grupos)) {
    const arquivo = `translations/${lingua}.json`;
    const json = JSON.parse(fs.readFileSync(arquivo, 'utf8'));
    if (json.Prazo) throw new Error(`${arquivo}: o grupo Prazo já existe`);
    if (!json.MapaDoLider || !json.MapaDoLider.legendaItens) throw new Error(`${arquivo}: sem MapaDoLider.legendaItens`);
    json.MapaDoLider.legendaItens.prazo = legenda[lingua];
    json.Prazo = grupo;
    fs.writeFileSync(arquivo, JSON.stringify(json, null, 4).replace(/\n/g, '\r\n') + '\r\n');
}
console.log('ok');
FIM
git diff --stat translations
```

Esperado: `ok`, `translations/en.json | 8 +++++++-` e `translations/pt.json | 8 +++++++-` (as duas linhas apagadas são
só a vírgula que entra no fim de `"pedido": "Entrega em andamento"` e do grupo `Comanda`). Se o `git diff` mostrar mais
linhas apagadas, o formato mudou: `git checkout translations` e acrescente à mão, antes do último `}` de cada arquivo e
no fim de `legendaItens`.

O texto usa `{{minutos}}` (e não `{{count}}`) para o react-native-i18n só interpolar, sem procurar formas de plural.

- [ ] **Passo 2: conferir o JSON**

```bash
cd /c/tmp/cra
node -e "for (const l of ['pt','en']) { const j = require('./translations/' + l + '.json'); console.log(l, JSON.stringify(j.Prazo), j.MapaDoLider.legendaItens.prazo); }"
```

Esperado:

```
pt {"rotulo":"Prazo","faltam":"{{minutos}} min","atrasado":"Atrasado há {{minutos}} min"} Nome no pedido: no prazo, perto, atrasado
en {"rotulo":"Deadline","faltam":"{{minutos}} min","atrasado":"Late by {{minutos}} min"} Name on the order: on time, close, late
```

- [ ] **Passo 3: commit**

```bash
cd /c/tmp/cra
git add translations/pt.json translations/en.json
git commit -m "Prazo: textos do cronômetro e da legenda do Mapa do líder em pt e en

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 4: `useAgora` e `CronometroDoPrazo`

**Arquivos:**
- Criar: `src/hooks/use-agora.ts`
- Criar: `src/components/entregas/CronometroDoPrazo.tsx`

O hook e o componente não têm teste automático (o app não tem ambiente de teste de React Native); a regra está toda no
`prazo.ts` (Tarefa 1) e a conferência visual é a da Tarefa 9.

- [ ] **Passo 1: o hook `useAgora`**

Crie `src/hooks/use-agora.ts`:

```ts
import { useEffect, useState } from 'react';
import { AppState } from 'react-native';

// Entregas: o relógio do celular (ms), refeito a cada `intervaloMs` e quando o app volta para a frente (em segundo plano o
// setInterval para, e o cronômetro voltaria atrasado até a próxima volta do relógio). Só o componente que chama se
// redesenha: o CronometroDoPrazo o usa por dentro, para a folha e a lista não se refazerem a cada 30 s. Com
// `ligado` = false (pedido sem prazo), não liga relógio nenhum.
export default function useAgora(intervaloMs: number, ligado: boolean = true): number {
    const [agora, setAgora] = useState(() => Date.now());

    useEffect(() => {
        if (!ligado) return;
        // ao ligar depois da montagem (o prazo chegou com a releitura do pedido), começa da hora certa
        setAgora(Date.now());
        const relogio = setInterval(() => setAgora(Date.now()), intervaloMs);
        const assinatura = AppState.addEventListener('change', (estado) => {
            if (estado === 'active') setAgora(Date.now());
        });
        return () => {
            clearInterval(relogio);
            assinatura.remove();
        };
    }, [intervaloMs, ligado]);

    return agora;
}
```

- [ ] **Passo 2: o componente `CronometroDoPrazo`**

Crie `src/components/entregas/CronometroDoPrazo.tsx`:

```tsx
import { memo } from 'react';
import { Text, XStack, useTheme } from 'tamagui';
import { FontAwesomeIcon } from '@fortawesome/react-native-fontawesome';
import { faClock } from '@fortawesome/free-solid-svg-icons';
import { useLanguage } from '../../contexts/LanguageContext';
import useAgora from '../../hooks/use-agora';
import { INTERVALO_DO_CRONOMETRO_MS, chaveDoTexto, coresDoPrazo, cronometro } from '../../utils/prazo';

// Entregas: o cronômetro do prazo de entrega (spec 2026-10-20): o relógio e "32 min" (verde, mais de 15 min), "12 min"
// (amarelo, 15 min ou menos) ou "Atrasado há 7 min" (vermelho), com o fundo cheio na cor do estado e o texto em
// contraste nos dois temas (coresDoPrazo). Ele mesmo se refaz a cada 30 s e na volta do app para a frente (useAgora):
// só este pedaço se redesenha, não a tela. Sem prazo (servidor antigo, pedido de antes do deploy, data ilegível): nada.
// Quem decide se aparece (só no pedido dele em curso, nunca no aberto, na oferta, no concluído ou no cancelado) é a tela.
type Props = {
    prazo: string | null; // meta.entregas_prazo_em (prazoDoPedido)
    tamanho?: 'pequeno' | 'medio';
};

const CronometroDoPrazo = ({ prazo, tamanho = 'pequeno' }: Props) => {
    const theme = useTheme();
    const { t } = useLanguage();
    const agora = useAgora(INTERVALO_DO_CRONOMETRO_MS, prazo !== null);
    const atual = cronometro(prazo, agora);
    if (!atual) {
        return null;
    }
    const cores = coresDoPrazo(atual.estado);
    const texto = t(chaveDoTexto(atual), { minutos: atual.minutos });
    const grande = tamanho === 'medio';

    return (
        <XStack
            bg={cores.fundo}
            borderRadius='$2'
            px={grande ? '$2.5' : '$2'}
            py={grande ? 4 : 2}
            gap='$1'
            alignItems='center'
            flexShrink={0}
            accessible
            accessibilityLabel={`${t('Prazo.rotulo')}: ${texto}`}
        >
            <FontAwesomeIcon icon={faClock} size={grande ? 13 : 11} color={theme[cores.texto]?.val} />
            <Text color={cores.texto} fontSize={grande ? 14 : 12} fontWeight='800' letterSpacing={0.2} numberOfLines={1}>
                {texto}
            </Text>
        </XStack>
    );
};

export default memo(CronometroDoPrazo);
```

O `memo` evita refazer o chip quando a tela de fora se redesenha com o mesmo prazo; o relógio de dentro (`useAgora`)
continua valendo. Com `prazo` nulo, o hook não liga o relógio e o componente devolve `null`.

- [ ] **Passo 3: conferir**

```bash
bash /c/tmp/cr-tsc.sh
```

Esperado: `tsc: nenhum erro novo`.

- [ ] **Passo 4: commit**

```bash
cd /c/tmp/cra
git add src/hooks/use-agora.ts src/components/entregas/CronometroDoPrazo.tsx
git commit -m "Prazo: chip do cronômetro que se refaz sozinho a cada 30 s (useAgora)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 5: o cronômetro no card de "Em andamento" (`OrderCard`)

**Arquivos:**
- Modificar: `src/components/OrderCard.tsx:12-16` (imports e comentário) e `:47` (linha dos selos)

O `OrderCard` só aparece na seção "Em andamento" da aba Pedidos (`DriverOrderManagementScreen`, linha 155), que já tira
os pedidos encerrados (`secoesDaLista` → `pedidoEncerrado`). Os pedidos abertos e a oferta usam o `AdhocOrderCard`, que não
muda.

- [ ] **Passo 1: imports e comentário**

Em `src/components/OrderCard.tsx`, troque (linhas 12 a 16):

```tsx
import type { VarianteDoSelo } from './entregas/Selo';

// Entregas: o pedido dele em andamento na aba Pedidos (redesenho, sketch 002 B): uma linha compacta com o número (selo
// do iFood pelas notas; sem ele, o código de rastreio), a situação em palavras (a mesma regra do capacete do mapa), a
// loja da coleta e o destino. O toque abre a tela do pedido.
```

por:

```tsx
import type { VarianteDoSelo } from './entregas/Selo';
import CronometroDoPrazo from './entregas/CronometroDoPrazo';
import { prazoDoPedido } from '../utils/prazo';

// Entregas: o pedido dele em andamento na aba Pedidos (redesenho, sketch 002 B): uma linha compacta com o número (selo
// do iFood pelas notas; sem ele, o código de rastreio), a situação em palavras (a mesma regra do capacete do mapa), o
// cronômetro do prazo de entrega (spec 2026-10-20; some sem prazo), a loja da coleta e o destino. O toque abre a tela do
// pedido.
```

- [ ] **Passo 2: o cronômetro na linha dos selos**

Troque (linha 47):

```tsx
                        <Selo variante={SELO_DA_SITUACAO[situacao]}>{t(`OrderCard.situacao.${situacao}`)}</Selo>
```

por:

```tsx
                        <Selo variante={SELO_DA_SITUACAO[situacao]}>{t(`OrderCard.situacao.${situacao}`)}</Selo>
                        <CronometroDoPrazo prazo={prazoDoPedido(order)} />
```

A linha dos selos já tem `flexWrap='wrap'`: em tela estreita, "Atrasado há 12 min" desce para a linha de baixo em vez de
cortar.

- [ ] **Passo 3: conferir**

```bash
bash /c/tmp/cr-tsc.sh
```

Esperado: `tsc: nenhum erro novo`.

- [ ] **Passo 4: commit**

```bash
cd /c/tmp/cra
git add src/components/OrderCard.tsx
git commit -m "Pedidos: cronômetro do prazo no card de Em andamento

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 6: o cronômetro na linha da etapa da folha (`OrderScreen`)

**Arquivos:**
- Modificar: `src/screens/OrderScreen.tsx:63-64` (imports), `:90-91` (comentário), `:1051-1052` (quando mostrar) e
  `:1140-1142` (a linha da etapa)

A folha (`renderFolha`) só existe com `cartao === 'corrida'`: o pedido dele. O pedido aberto e a oferta (`cartao ===
'oferta'`), o cancelado e o passado a outro motoboy mostram outros cartões. O `order` da tela é o recurso do SDK
(`new Order(params.order, adapter)`), que o `prazoDoPedido` lê pelo `getAttribute`.

- [ ] **Passo 1: imports**

Em `src/screens/OrderScreen.tsx`, troque (linhas 63 e 64):

```tsx
import CobrancaEmUmaLinha from '../components/entregas/CobrancaEmUmaLinha';
import { contarItens, lerItens, nomeDoClienteDoPedido } from '../utils/comanda';
```

por:

```tsx
import CobrancaEmUmaLinha from '../components/entregas/CobrancaEmUmaLinha';
import CronometroDoPrazo from '../components/entregas/CronometroDoPrazo';
import { contarItens, lerItens, nomeDoClienteDoPedido } from '../utils/comanda';
import { prazoDoPedido } from '../utils/prazo';
```

- [ ] **Passo 2: o comentário do topo**

Troque (as linhas 90 e 91 de antes, 92 e 93 depois do passo anterior):

```tsx
// - pedido dele: a folha da corrida (etapa Loja → Pedido → Cliente, destino grande, Navegar/Ligar/Chat/Detalhes e o
//   "deslize para confirmar" com a próxima atividade do servidor). Aberta, a folha vira a Comanda (sketch 007 B): na
```

por:

```tsx
// - pedido dele: a folha da corrida (etapa Loja → Pedido → Cliente, o cronômetro do prazo na linha da etapa, destino
//   grande, Navegar/Ligar/Chat/Detalhes e o "deslize para confirmar" com a próxima atividade do servidor). Aberta, a
//   folha vira a Comanda (sketch 007 B): na
```

- [ ] **Passo 3: quando mostrar**

Troque:

```tsx
    const naLoja = etapa === 'loja' || etapa === 'aguardando';
    const naPorta = etapa === 'cliente';
```

por:

```tsx
    const naLoja = etapa === 'loja' || etapa === 'aguardando';
    const naPorta = etapa === 'cliente';
    // Cronômetro do prazo (spec 2026-10-20): na linha da etapa, só com a corrida em curso (aguardando, indo à loja, a
    // caminho do cliente); some no concluído. Sem a chave no meta (servidor antigo, pedido de antes do deploy): nada
    const prazoNaFolha = naLoja || naPorta ? prazoDoPedido(order) : null;
```

- [ ] **Passo 4: a linha da etapa**

Troque:

```tsx
                                <Text color='$textSecondary' fontSize={12} fontWeight='800' textTransform='uppercase' letterSpacing={0.6}>
                                    {rotuloDaEtapa}
                                </Text>
```

por:

```tsx
                                {/* a linha da etapa: o rótulo e, do lado, o cronômetro do prazo (o chip se refaz sozinho a cada 30 s) */}
                                <XStack alignItems='center' justifyContent='space-between' gap='$2'>
                                    <Text flexShrink={1} color='$textSecondary' fontSize={12} fontWeight='800' textTransform='uppercase' letterSpacing={0.6} numberOfLines={1}>
                                        {rotuloDaEtapa}
                                    </Text>
                                    <CronometroDoPrazo prazo={prazoNaFolha} tamanho='medio' />
                                </XStack>
```

Esse trecho fica dentro do `YStack` medido pelo `onLayout` da parte recolhida: com o chip, a linha cresce uns 10 px e a
altura recolhida da folha acompanha sozinha (`alturaDoTopoDaFolha`). Sem prazo, o `XStack` tem só o rótulo e a altura
não muda. O `{rotuloDaEtapa}` só aparece uma vez no arquivo; se a ferramenta Edit achar o trecho repetido, confira com
`grep -n "{rotuloDaEtapa}" src/screens/OrderScreen.tsx` (esperado: uma linha).

- [ ] **Passo 5: conferir**

```bash
cd /c/tmp/cra
grep -n "prazoNaFolha\|CronometroDoPrazo\|prazoDoPedido" src/screens/OrderScreen.tsx
bash /c/tmp/cr-tsc.sh
```

Esperado: 4 linhas do `grep` (o import do componente, o import da função, a declaração
`const prazoNaFolha = naLoja || naPorta ? prazoDoPedido(order) : null;` e o `<CronometroDoPrazo prazo={prazoNaFolha} tamanho='medio' />`)
e `tsc: nenhum erro novo`.

- [ ] **Passo 6: commit**

```bash
cd /c/tmp/cra
git add src/screens/OrderScreen.tsx
git commit -m "Pedido: cronômetro do prazo na linha da etapa da folha

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 7: o rótulo do alfinete na cor do prazo (`MapaDoLiderScreen`)

**Arquivos:**
- Modificar: `src/screens/MapaDoLiderScreen.tsx`: imports (`:28-44`), comentário do topo (`:54-56`), cores do rótulo
  (`:168-171`), legenda (`:566-571`), `agora` antes do `return` (`:630`) e os alfinetes (`:684-686`)

A tela relê o mapa a cada 10 s (`INTERVALO_DO_MAPA_MS`) e cada leitura cria um objeto novo (`setMapa`), então a tela se
redesenha e a cor é refeita com `Date.now()` na hora de desenhar, sem relógio próprio. O `RotuloNoAlfinete` já liga o
`tracksViewChanges` por um instante quando `cores.rotulo` ou `cores.textoDoRotulo` mudam (linhas 101 a 106): a cor nova
aparece no Android.

- [ ] **Passo 1: import**

Em `src/screens/MapaDoLiderScreen.tsx`, troque (linha 44):

```tsx
import type { FiltroDoMapa, MapaDoLider, OpcaoDeMotoboy, PedidoDoLider, SituacaoNoMapa, TempoDesde } from '../utils/mapa-do-lider';
```

por:

```tsx
import type { FiltroDoMapa, MapaDoLider, OpcaoDeMotoboy, PedidoDoLider, SituacaoNoMapa, TempoDesde } from '../utils/mapa-do-lider';
import { coresDoPrazo, cronometro } from '../utils/prazo';
```

- [ ] **Passo 2: o comentário do topo**

Troque (as linhas 55 e 56 de antes, 56 e 57 depois do passo anterior):

```tsx
// legenda que recolhe, nome do motoboy num rótulo vermelho-escuro no alfinete do pedido aceito e o erro da troca dentro
// da folha (além do toast), com "Tentar de novo" quando repetir pode dar certo.
```

por:

```tsx
// legenda que recolhe, nome do motoboy num rótulo no alfinete do pedido aceito e o erro da troca dentro da folha (além do
// toast), com "Tentar de novo" quando repetir pode dar certo. O rótulo tem o fundo na cor do prazo de entrega (spec
// 2026-10-20, `prazo_em`): verde no prazo, amarelo com 15 min ou menos, vermelho atrasado; sem prazo, escuro neutro.
```

- [ ] **Passo 3: as cores do rótulo por pedido**

Troque:

```tsx
    const coresDoAlfinete: CoresDoAlfinete = {
        rotulo: cor('errorBorder', 'error'),
        textoDoRotulo: cor('errorText', 'background'),
    };
```

por:

```tsx
    // o rótulo do alfinete na cor do prazo (prazo_em do pedido), pelo relógio do celular na hora de desenhar: a tela se
    // redesenha a cada leitura do mapa (10 s). Sem prazo (pedido de antes do deploy): escuro neutro, não vermelho
    const coresDoAlfinete = (item: PedidoDoLider, agora: number): CoresDoAlfinete => {
        const tokens = coresDoPrazo(cronometro(item.prazo_em, agora)?.estado ?? null);
        return { rotulo: cor(tokens.fundo, 'errorBorder'), textoDoRotulo: cor(tokens.texto, 'errorText') };
    };
```

O `cor` (linha 166) lê `theme[chave]?.val`; o tema aceita a chave com `$` (a `OrderScreen` usa `theme['$cartao']`). As
chaves `successBorder`, `warningBorder`, `errorBorder`, `successText`, `warningText`, `errorText`, `textPrimary` e
`background` existem em todos os temas (`lightBase`, `darkBase` e os do Entregas, `tamagui.config.ts`).

- [ ] **Passo 4: a linha da legenda**

Troque:

```tsx
                            {linha(
                                <YStack width={22} alignItems='center'>
                                    <YStack width={13} height={13} borderRadius={7} bg='$error' borderWidth={2} borderColor='$cartao' />
                                </YStack>,
                                t('MapaDoLider.legendaItens.pedido')
                            )}
```

por:

```tsx
                            {linha(
                                <YStack width={22} alignItems='center'>
                                    <YStack width={13} height={13} borderRadius={7} bg='$error' borderWidth={2} borderColor='$cartao' />
                                </YStack>,
                                t('MapaDoLider.legendaItens.pedido')
                            )}
                            {linha(
                                <XStack width={22} justifyContent='center' gap={2}>
                                    {['successBorder', 'warningBorder', 'errorBorder'].map((chave) => (
                                        <YStack key={chave} width={6} height={12} borderRadius={3} bg={`$${chave}`} />
                                    ))}
                                </XStack>,
                                t('MapaDoLider.legendaItens.prazo')
                            )}
```

- [ ] **Passo 5: o relógio da hora de desenhar**

Troque:

```tsx
    return (
        <YStack flex={1} bg='$background'>
            <XStack bg='$primary' alignItems='center' justifyContent='space-between' px='$4' minHeight={56 + insets.top} pb='$2' pt={insets.top + 8} gap='$2'>
```

por:

```tsx
    // o relógio do celular para a cor dos rótulos (a tela se redesenha a cada leitura do mapa)
    const agora = Date.now();

    return (
        <YStack flex={1} bg='$background'>
            <XStack bg='$primary' alignItems='center' justifyContent='space-between' px='$4' minHeight={56 + insets.top} pb='$2' pt={insets.top + 8} gap='$2'>
```

- [ ] **Passo 6: cada alfinete com a sua cor**

Troque:

```tsx
                        <AlfineteDoPedido key={`pedido-${item.id}`} pedido={item} selecionado={selecionado === item.id} cores={coresDoAlfinete} onPress={() => abrirPedido(item.id)} />
```

por:

```tsx
                        <AlfineteDoPedido key={`pedido-${item.id}`} pedido={item} selecionado={selecionado === item.id} cores={coresDoAlfinete(item, agora)} onPress={() => abrirPedido(item.id)} />
```

O `AlfineteDoPedido` e o `RotuloNoAlfinete` não mudam: continuam recebendo `cores` com `rotulo` e `textoDoRotulo`. O
alfinete sem rótulo (pedido sem motoboy aceito) também recebe as cores, mas não as usa.

- [ ] **Passo 7: conferir**

```bash
cd /c/tmp/cra
grep -n "coresDoAlfinete\|coresDoPrazo\|const agora\|legendaItens.prazo" src/screens/MapaDoLiderScreen.tsx
bash /c/tmp/cr-tsc.sh
```

Esperado: as linhas do import (`coresDoPrazo`), da função `coresDoAlfinete` e da chamada `coresDoPrazo(` dentro dela, da
legenda (`legendaItens.prazo`), do `const agora = Date.now();` e do alfinete (`coresDoAlfinete(item, agora)`); e
`tsc: nenhum erro novo` (o erro de sempre do `absoluteFillObject` continua, só muda de linha).

- [ ] **Passo 8: commit**

```bash
cd /c/tmp/cra
git add src/screens/MapaDoLiderScreen.tsx
git commit -m "Mapa do líder: rótulo do alfinete na cor do prazo de entrega e a linha da legenda

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 8: documentação

**Arquivos:**
- Modificar (na worktree, commitado): `src/components/entregas/README.md`
- Modificar (na pasta principal, **não commitar**: arquivos não rastreados):
  `C:\Users\Edgardjr\Documents\vibe coding\entregas-navigator\CLAUDE.md` e
  `C:\Users\Edgardjr\Documents\vibe coding\Delivery\api\app\Http\Controllers\Entregas\CLAUDE.md`

- [ ] **Passo 1: README dos componentes**

Em `/c/tmp/cra/src/components/entregas/README.md`, acrescente no fim do arquivo (depois da linha que começa com
"- Comanda do pedido (sketch 007 B"):

```markdown
- `CronometroDoPrazo`: o cronômetro do prazo de entrega (spec 2026-10-20): relógio e "32 min" (verde), "12 min" (amarelo, 15 min ou menos) ou "Atrasado há 7 min" (vermelho), com fundo cheio na cor do estado. Ele mesmo se refaz a cada 30 s e na volta do app para a frente (`useAgora`, em `src/hooks/use-agora.ts`); sem prazo, não mostra nada. No card de Em andamento (`OrderCard`) e na linha da etapa da folha da `OrderScreen`. A regra (minutos, estado, texto e cores) fica em `src/utils/prazo.ts`.
```

Commit:

```bash
cd /c/tmp/cra
git add src/components/entregas/README.md
git commit -m "Componentes: README com o CronometroDoPrazo

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Passo 2: "App do motoboy" nas duas cópias**

As duas cópias têm o mesmo texto: `C:\Users\Edgardjr\Documents\vibe coding\entregas-navigator\CLAUDE.md` e
`C:\Users\Edgardjr\Documents\vibe coding\Delivery\api\app\Http\Controllers\Entregas\CLAUDE.md`. Faça a mesma troca nas
duas.

Troque o começo do item da trava (a linha que começa assim, nas duas cópias):

```markdown
- **Trava "Atualize o app"** (decisão de 2026-10-08: **cada build da `main` do app vira obrigatório**, sem escolha manual):
```

por:

```markdown
- **Cronômetro do prazo de entrega** (decisão de 2026-10-20; spec `docs/superpowers/specs/2026-10-20-cronometro-do-prazo-design.md`, no Delivery; ramo `cronometro-do-prazo` do app até o merge): o prazo vem do servidor no `meta.entregas_prazo_em` do pedido (ISO com -03:00, gravado na criação: iFood pela hora prometida ao cliente, os outros 50 min depois da criação ou do agendamento) e, no Mapa do líder, em `prazo_em` de cada pedido. O `CronometroDoPrazo` mostra "32 min" (verde, mais de 15 min), "12 min" (amarelo, 15 min ou menos) ou "Atrasado há 7 min" (vermelho), com fundo cheio e texto em contraste (tokens `successBorder`/`warningBorder`/`errorBorder` com `*Text`), pelo relógio do celular, refeito a cada 30 s e na volta do app para a frente (`useAgora`: só o chip redesenha). Aparece no card de Em andamento (aba Pedidos) e na linha da etapa da folha do pedido (aguardando, indo à loja, a caminho do cliente); nunca no pedido aberto nem na oferta, e some no concluído e no cancelado. No Mapa do líder, o rótulo com o nome do motoboy no alfinete ganha o fundo na cor do prazo (sem prazo: escuro neutro), refeito a cada leitura do mapa (10 s), e a legenda explica as cores. Pedido sem a chave (criado antes do deploy do servidor) ou com data ilegível: sem cronômetro. Funções puras em `src/utils/prazo.ts` (`scripts/testes/prazo.teste.ts`); o `prazo_em` no `lerMapa` (`mapa-do-lider.teste.ts`).
- **Trava "Atualize o app"** (decisão de 2026-10-08: **cada build da `main` do app vira obrigatório**, sem escolha manual):
```

- [ ] **Passo 3: conferir que nada disso foi para a worktree**

```bash
cd /c/tmp/cra && git status --short
cd "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && git status --short
grep -c "Cronômetro do prazo de entrega" "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator/CLAUDE.md" "/c/Users/Edgardjr/Documents/vibe coding/Delivery/api/app/Http/Controllers/Entregas/CLAUDE.md"
```

Esperado: a worktree limpa; na pasta principal do app, só `?? CLAUDE.md`; e `:1` para cada um dos dois arquivos.

---

### Tarefa 9: verificação final e build de teste do ramo

**Arquivos:** nenhum.

- [ ] **Passo 1: todos os testes do app**

```bash
cd /c/tmp/cra
node --experimental-strip-types --test scripts/testes/*.teste.ts 2>&1 | grep -E "^# (tests|pass|fail)"
```

Esperado: `# tests 170`, `# pass 170` e `# fail 0` (161 de antes, 8 do `prazo.teste.ts` e 1 do `mapa-do-lider`).

- [ ] **Passo 2: `tsc` sem erro novo**

```bash
bash /c/tmp/cr-tsc.sh
```

Esperado: `tsc: nenhum erro novo`.

- [ ] **Passo 3: toda chave de texto usada existe nas duas línguas**

```bash
cd /c/tmp/cra
node - <<'FIM'
const fs = require('fs');
const pt = JSON.parse(fs.readFileSync('translations/pt.json', 'utf8'));
const en = JSON.parse(fs.readFileSync('translations/en.json', 'utf8'));
const arquivos = ['src/utils/prazo.ts', 'src/components/entregas/CronometroDoPrazo.tsx', 'src/screens/MapaDoLiderScreen.tsx'];
const chaves = new Set();
for (const arquivo of arquivos) {
    for (const [, chave] of fs.readFileSync(arquivo, 'utf8').matchAll(/'(Prazo\.[A-Za-z]+|MapaDoLider\.legendaItens\.prazo)'/g)) chaves.add(chave);
}
const faltando = [];
for (const chave of chaves) {
    for (const [lingua, json] of [['pt', pt], ['en', en]]) {
        const valor = chave.split('.').reduce((atual, parte) => (atual == null ? undefined : atual[parte]), json);
        if (typeof valor !== 'string') faltando.push(`${lingua}: ${chave}`);
    }
}
const semUso = Object.keys(pt.Prazo).filter((chave) => !chaves.has(`Prazo.${chave}`));
console.log(faltando.length ? 'FALTAM: ' + faltando.join(', ') : 'ok: ' + [...chaves].sort().join(', '), '| sem uso:', semUso.join(', ') || 'nenhuma');
FIM
```

Esperado: `ok: MapaDoLider.legendaItens.prazo, Prazo.atrasado, Prazo.faltam, Prazo.rotulo | sem uso: nenhuma`.

- [ ] **Passo 4: o ramo**

```bash
cd /c/tmp/cra
git status --short
git log --oneline main..HEAD
```

Esperado: worktree limpa e 8 commits no ramo (Tarefas 1 a 8, um cada).

- [ ] **Passo 5: subir o ramo e disparar o build de teste**

O `workflow_dispatch` precisa do ramo no GitHub. Push de ramo **não** dispara build (o `push` do `build-apk.yml` é só na
`main`) e o build de ramo **não** publica no MinIO (`if: github.ref == 'refs/heads/main' && env.SUFIXO == ''`, linha
139): a versão mínima não muda e nenhum motoboy é travado.

```bash
cd /c/tmp/cra
git push -u origin cronometro-do-prazo
gh workflow run build-apk.yml --ref cronometro-do-prazo
gh run list --workflow build-apk.yml --branch cronometro-do-prazo --limit 1
```

Esperado: o run aparece como `queued` ou `in_progress`. Anote o id (a coluna numérica longa) e o número do run (o
`versionCode` do APK é o `github.run_number`). O build leva de 30 a 60 min; acompanhe com
`gh run watch <id> --exit-status` em segundo plano.

- [ ] **Passo 6: baixar o APK**

```bash
gh run download <id> --dir /c/tmp/apk-cronometro
ls /c/tmp/apk-cronometro/*
```

Esperado: `entregas-motoboy-<n>.apk` dentro de `/c/tmp/apk-cronometro/entregas-motoboy-<n>/`. Se o nome vier com
`-sem-push`, o secret `GOOGLE_SERVICES_JSON` faltou no build: o APK serve para olhar a tela, mas não recebe push.

- [ ] **Passo 7: teste no celular (Edgard, por adb)**

```bash
adb devices
adb install -r /c/tmp/apk-cronometro/entregas-motoboy-<n>/entregas-motoboy-<n>.apk
```

**Com o servidor de hoje** (sem `entregas_prazo_em` e sem `prazo_em`), conferir que nada quebrou:
- aba Pedidos, "Em andamento": os cards como antes, sem cronômetro;
- folha do pedido: a linha da etapa como antes, sem cronômetro; a altura recolhida não mudou;
- Mapa do líder (com um líder): os rótulos dos alfinetes agora em escuro neutro (antes vermelho escuro) e a linha nova na
  legenda.

**Depois do deploy do servidor** (plano do Delivery, `bash deploy/atualizar.sh api`), com um pedido de teste na Terraço
Pizza Bar (loja de testes; o pedido não precisa ser cancelado depois):
- logo depois de aceitar: "Em andamento" e a folha com o chip verde ("50 min" ou o prazo do iFood), nos dois temas (Rua
  clara e Rua à noite);
- com 15 min ou menos: amarelo; depois do prazo: vermelho, "Atrasado há 1 min" no primeiro minuto;
- mandar o app para segundo plano por alguns minutos e voltar: o chip já volta com o tempo certo;
- pedido aberto e oferta (antes de aceitar): sem cronômetro; pedido concluído: some;
- Mapa do líder: o rótulo do motoboy na cor do prazo, mudando de cor em até 10 s depois de passar dos 15 min e do prazo.

Para ver as três cores sem esperar 35 min: o hook do servidor não sobrescreve um `entregas_prazo_em` que já venha no
`meta` (spec, "Gravação"). Se o plano do servidor confirmar que o `POST v1/orders` grava o `meta` enviado, um pedido de
teste criado pela API com `meta.entregas_prazo_em` = agora + 16 min passa por verde, amarelo e vermelho em ~17 min.

- [ ] **Passo 8: parar aqui**

**Não faça merge na `main`.** Cada build da `main` vira versão mínima e trava os motoboys até instalarem o APK novo. O
merge (`git checkout main && git merge --no-ff cronometro-do-prazo` na pasta principal, depois `git push`) é só com o
Edgard, depois do teste no celular. A ordem entre o servidor e o app não importa (sem a chave, o app não mostra o
cronômetro). Para remover a worktree depois do merge, **primeiro** a junção:
`cmd //c rmdir 'C:\tmp\cra\node_modules'`, e só então `git worktree remove /c/tmp/cra`.

---

## Pontos para o Edgard

1. **Texto do rótulo no Mapa do líder:** no app o rótulo mostra só o nome ("Carlos"); o "#4821 · Carlos" da spec é o do
   console e do portal. O plano muda só o fundo. Para igualar ao console, é trocar o retorno de `rotuloNoAlfinete`
   (`src/utils/mapa-do-lider.ts`) para `` `${rotuloDoPedido(pedido.numero)} · ${pedido.motoboy}` `` e o teste dele (o
   `maxWidth: 150` do rótulo corta nomes longos com "…").
2. **Rótulo sem prazo:** hoje todo rótulo é vermelho escuro. Com o plano, o pedido sem prazo (de antes do deploy do
   servidor) fica escuro neutro, para não parecer atrasado. Se preferir manter o vermelho escuro, é a última linha de
   `coresDoPrazo` (mas aí o chip nunca a usa, só o mapa).
3. **Cores cheias:** o chip e o rótulo usam fundo cheio (`*Border` + `*Text`), não o selo suave dos outros selos do app
   (`sucessoSuave` + `success`), que no tema claro fica com contraste baixo (~3:1) para texto de 12 px.
4. **Legenda nova no Mapa do líder** ("Nome no pedido: no prazo, perto, atrasado"): fora da spec, para o líder não
   confundir as cores do rótulo com as dos capacetes. Se não quiser, sai o Passo 4 da Tarefa 7 e a chave
   `MapaDoLider.legendaItens.prazo`.
5. **Pedido atribuído pela central e ainda não iniciado** (etapa "Aguardando início"): o plano mostra o cronômetro,
   porque o pedido já está em "Em andamento" e a spec só tira o pedido aberto e a oferta.
6. **Cartão do pedido no Mapa do líder** (a folha que abre no toque do alfinete): sem cronômetro, porque a spec não pede.
   Se quiser, é um `<CronometroDoPrazo prazo={pedido.prazo_em} />` ao lado do selo do status em `renderCartao`.
```
