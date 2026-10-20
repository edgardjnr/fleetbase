# Comanda do pedido e lista com o mais antigo em cima (app do motoboy): plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Objetivo:** a folha da `OrderScreen` vira a "Comanda" da variante B do sketch 007 (nome do cliente, produtos com
caixinhas de conferir, cartões curtos na porta e o apoio dentro de um "Mais"), os cartões de pedido aberto e de oferta
ganham a linha dos produtos, e a aba Pedidos mostra o pedido mais antigo em cima.

**Arquitetura:** as regras ficam em funções puras sem imports (`src/utils/comanda.ts`, novo, e
`src/utils/lista-de-pedidos.ts`), testadas com o Node puro. Os produtos chegam no campo novo `itens` das respostas que o
app já lê (`useDadosIfood` e `useDadosDaIntegracao`) e passam sempre por `lerItens`, que devolve `[]` sem o campo, com
`null` ou com lixo: sem produtos, cada bloco de produtos some sozinho, e o app funciona com o servidor de hoje. A tela
ganha componentes pequenos em `src/components/entregas/` e a `OrderScreen` só monta as peças por etapa.

**Tecnologia:** React Native + Tamagui (temas `lightEntregas`/`darkEntregas`), i18n-js (`translations/pt.json` e
`en.json`), testes com `node --experimental-strip-types --test`, build do APK no GitHub Actions (`build-apk.yml`).

**Spec:** `docs/superpowers/specs/2026-10-19-detalhe-do-pedido-app-design.md` (no Delivery). **Sketch:**
`entregas-navigator/.planning/sketches/007-detalhe-do-pedido/` (`README.md` e `index.html`, variante B, telas `aberto`,
`loja`, `balcao`, `cliente` e `porta`).

---

## Onde trabalhar

O app (`entregas-navigator`) está em `C:\Users\Edgardjr\Documents\vibe coding\entregas-navigator`, na `main`, com um
`CLAUDE.md` **não rastreado** do Edgard: não o commite nem o apague. Todo o código vai na worktree `/c/tmp/cp`, ramo
`comanda-do-pedido`, criada na Tarefa 0.

- **Testes do app:** `node --experimental-strip-types --test scripts/testes/<arquivo>.teste.ts` (na raiz da worktree). O
  Node avisa `ExperimentalWarning: Type Stripping is an experimental feature`: é normal. O resultado sai no fim, em
  `# pass N` e `# fail N`.
- **Funções em `src/utils/*.ts` não importam nada** (nem umas das outras): o Node exige o `.ts` no import e o `tsc`
  recusa. Os componentes importam os utils sem extensão, como sempre.
- **`tsc`:** o `tsconfig.json` do app não liga o JSX, então o `npx tsc --noEmit -p .` puro despeja milhares de erros
  falsos. Use sempre as opções `--jsx react-native --esModuleInterop --allowImportingTsExtensions --skipLibCheck` (o
  script da Tarefa 0 já faz isso) e compare com a base tirada antes de mexer: **nenhum erro novo**.
- **`node_modules`:** a worktree não tem; a Tarefa 0 cria uma junção para o da pasta principal (não instala nada). **Antes
  de remover a worktree** (`git worktree remove`), apague a junção com `cmd //c rmdir 'C:\tmp\cp\node_modules'`: remover a
  pasta com a junção dentro pode apagar o `node_modules` da pasta principal.
- **Bundle:** o app não tem script de bundle (`package.json` só tem `start`, `android`, `test`, `lint`…). A verificação do
  bundle é o build do GitHub Actions do ramo (Tarefa 15).
- **Merge na `main`: não.** Cada build da `main` vira versão mínima (trava "Atualize o app"). O merge é só com o Edgard,
  depois do build de teste do ramo e do teste no celular.

## Arquivos

| Arquivo (no `entregas-navigator`) | O que muda |
|---|---|
| `src/utils/lista-de-pedidos.ts` | `doMaisAntigo` e `semRepetir`; `secoesDaLista` ordena as duas seções do mais antigo ao mais novo |
| `src/utils/oferta.ts` | sai `comOfertaPrimeiro` (ninguém usa) |
| `src/utils/comanda.ts` (novo) | `nomeDoCliente`, `nomeDoClienteDoPedido`, `emCaixaNormal`, `lerItens`, `contarItens`, `linhaDosItens`, `textoDasOpcoes`, `alternarConferido`, `quantosConferidos`, `tudoConferido` |
| `src/utils/pedido-ifood.ts`, `src/utils/pedido-de-origem.ts` | campo opcional `itens?: unknown` nos tipos das respostas |
| `src/components/entregas/LinhaTracejada.tsx` (novo) | o tracejado do cupom (que funciona no Android) |
| `src/components/entregas/ListaDeItens.tsx` (novo) | as linhas dos produtos (`conferir` ou `resumo`) |
| `src/components/entregas/ComandaDoPedido.tsx` (novo) | o cupom da loja com as caixinhas |
| `src/components/entregas/ResumoDosItens.tsx` (novo) | a comandinha dos cartões (`linha`) e os produtos na porta (`cupom`) |
| `src/components/entregas/CobrancaEmUmaLinha.tsx` (novo) | a cobrança numa linha |
| `src/components/entregas/AvisosDaPorta.tsx` (novo) | observações do cliente (amarelo), complemento e referência |
| `src/components/entregas/MaisDoPedido.tsx` (novo) | o "Mais" recolhível |
| `src/components/PedidoIfood.tsx` | `LigarComLocalizador` (Ligar 0800 ao lado do localizador) |
| `src/components/entregas/CartaoDeOferta.tsx`, `CartaoDePedidoAberto.tsx`, `src/components/AdhocOrderCard.tsx` | `produtos` no `DadosDoCartao` e a comandinha |
| `src/screens/OrderScreen.tsx` | a folha "Comanda" (recolhida e aberta), o "Mais" e a saída dos blocos do Fleetbase |
| `src/screens/DriverOrderManagementScreen.tsx` | só o comentário da ordem |
| `translations/pt.json`, `translations/en.json` | grupo novo `Comanda` |
| `scripts/testes/lista-de-pedidos.teste.ts`, `scripts/testes/oferta.teste.ts`, `scripts/testes/comanda.teste.ts` (novo) | testes |
| `src/components/entregas/README.md` | os componentes novos |

Documentação fora da worktree (arquivos **não rastreados**, editados na pasta principal e **não commitados**, Tarefa 14):
`entregas-navigator/CLAUDE.md`, `Delivery/api/app/Http/Controllers/Entregas/CLAUDE.md` e
`Delivery/api/app/Support/Entregas/Ifood/CLAUDE.md`.

## Como o plano lê a spec (pontos de interpretação)

- **Produtos (`itens`):** a `OrderScreen` lê `(ifood ?? integracao)?.itens` (os dados de `useDadosIfood` e
  `useDadosDaIntegracao`, que já estão na tela, linhas 121 e 128) e o `AdhocOrderCard` lê o mesmo dos hooks dele (linhas 38
  e 40). Tudo passa por `lerItens`: sem a chave (servidor de hoje), `null`, lista vazia ou malformada → `[]`, e cada bloco
  testa `itens.length` (o `ResumoDosItens` devolve `null`; o `ComandaDoPedido` mostra só o número e o nome). Nada mostra
  "0 itens".
- **Sem nome do cliente:** o título a caminho do cliente volta a ser a rua, e o rótulo em maiúsculas de cima continua
  sendo o da etapa ("A caminho do cliente"). A spec fala em "rótulo 'Cliente'"; o plano entende que é esse rótulo da
  etapa (ver "Pontos para o Edgard" no fim).
- **Pedido aberto e oferta:** a comandinha entra embaixo do trajeto P → D. O D continua com a rua e o bairro (o bairro já
  está lá, na linha de baixo); o nome nunca aparece antes do aceite.
- **Linha resumida dos produtos:** até 2 linhas na tela (`numberOfLines={2}`, o Android põe o "…"); a função ainda corta
  em 160 caracteres, por segurança.
- **0800 e localizador:** só na etapa `cliente` (a folha aberta da coleta não os mostra mais). O botão redondo "Ligar" da
  folha já seguia essa regra (`ligarDaFolha`).
- **Cobrança:** a caminho do cliente, na parte recolhida, numa linha; na loja, aberta, embaixo do rótulo "Na porta", também
  numa linha (como no sketch). Os cartões grandes `CobrancaDoIfood`/`CobrancaDaIntegracao` saem da `OrderScreen` (seguem
  exportados).
- **"Mostre no balcão"** só aparece quando há número (iFood ou sistema de origem). Pedido da central sem número mostra só
  "para <nome>" e os produtos.

---

### Tarefa 0: worktree, junção do `node_modules` e base do `tsc`

**Arquivos:** nenhum no repositório. Cria `/c/tmp/cp` (worktree) e `/c/tmp/cp-tsc.sh` (fora do repositório).

- [ ] **Passo 1: conferir a pasta principal**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator"
git rev-parse --show-toplevel
git status --short
git log --oneline -1
ls /c/tmp/cp 2>&1 | head -1
```

Esperado: `C:/Users/Edgardjr/Documents/vibe coding/entregas-navigator`, só `?? CLAUDE.md` no status, a `main` em
`7a6fea8` (ou mais nova) e `ls: cannot access '/c/tmp/cp'`. Se `/c/tmp/cp` existir, pare e pergunte.

- [ ] **Passo 2: criar a worktree**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator"
git worktree add /c/tmp/cp -b comanda-do-pedido main
```

Esperado: `Preparing worktree (new branch 'comanda-do-pedido')`.

- [ ] **Passo 3: junção do `node_modules`** (PowerShell)

```powershell
New-Item -ItemType Junction -Path "C:\tmp\cp\node_modules" -Target "C:\Users\Edgardjr\Documents\vibe coding\entregas-navigator\node_modules" | Select-Object -ExpandProperty FullName
```

Esperado: `C:\tmp\cp\node_modules`. O `node_modules/` está no `.gitignore`: não entra em commit.

- [ ] **Passo 4: o script do `tsc` e a base**

```bash
cat > /c/tmp/cp-tsc.sh <<'FIM'
#!/usr/bin/env bash
# Compara os erros do tsc (sem números de linha) com a base. Uso: bash /c/tmp/cp-tsc.sh antes | bash /c/tmp/cp-tsc.sh
cd /c/tmp/cp || exit 1
npx tsc --noEmit -p . --jsx react-native --esModuleInterop --allowImportingTsExtensions --skipLibCheck 2>&1 | grep "error TS" | sed -E 's/\([0-9]+,[0-9]+\)//' | sort -u > /c/tmp/cp-tsc-agora.txt
if [ "$1" = "antes" ]; then
    cp /c/tmp/cp-tsc-agora.txt /c/tmp/cp-tsc-antes.txt
    echo "base: $(wc -l < /c/tmp/cp-tsc-antes.txt) erros distintos"
    exit 0
fi
NOVOS=$(comm -13 /c/tmp/cp-tsc-antes.txt /c/tmp/cp-tsc-agora.txt)
if [ -z "$NOVOS" ]; then
    echo "tsc: nenhum erro novo"
else
    echo "tsc: ERROS NOVOS:"
    echo "$NOVOS"
    exit 1
fi
FIM
bash /c/tmp/cp-tsc.sh antes
```

Esperado: `base: 383 erros distintos` (o número exato pode variar um pouco; o que vale é a comparação depois). Leva
uns 5 a 20 s.

- [ ] **Passo 5: os testes de hoje passam**

```bash
cd /c/tmp/cp
node --experimental-strip-types --test scripts/testes/lista-de-pedidos.teste.ts scripts/testes/oferta.teste.ts 2>&1 | grep -E "^# (pass|fail)"
```

Esperado: `# pass 20` e `# fail 0`.

---

### Tarefa 1: a lista com o mais antigo em cima (e sai o `comOfertaPrimeiro`)

**Arquivos:**
- Modificar: `src/utils/lista-de-pedidos.ts:1-4` (comentário) e `:58-76` (`secoesDaLista`)
- Modificar: `src/utils/oferta.ts:65-69` (sai `comOfertaPrimeiro`)
- Modificar: `src/screens/DriverOrderManagementScreen.tsx:132` (comentário)
- Testes: `scripts/testes/lista-de-pedidos.teste.ts`, `scripts/testes/oferta.teste.ts:5` e `:33-40`

- [ ] **Passo 1: escrever os testes que falham**

Em `scripts/testes/lista-de-pedidos.teste.ts`, troque a linha 5:

```ts
import { ehPedidoAberto, lojaDaColeta, numeroDoPedido, pedidoConcluido, pedidoEncerrado, secoesDaLista } from '../../src/utils/lista-de-pedidos.ts';
```

por:

```ts
import { doMaisAntigo, ehPedidoAberto, lojaDaColeta, numeroDoPedido, pedidoConcluido, pedidoEncerrado, secoesDaLista, semRepetir } from '../../src/utils/lista-de-pedidos.ts';
```

E acrescente no fim do arquivo:

```ts

test('do mais antigo ao mais novo: estável no empate, sem created_at (ou inválido) no fim, recurso do SDK e Date', () => {
    const a = { id: 'a', created_at: '2026-10-19T13:00:00Z' };
    const b = { id: 'b', created_at: '2026-10-19T13:00:00Z' };
    const semData = { id: 'c' };
    const invalida = { id: 'd', created_at: 'lixo' };
    const antes = recurso({ id: 'e', created_at: '2026-10-19T09:59:00-03:00' });
    const comDate = { id: 'f', created_at: new Date('2026-10-19T12:00:00Z') };
    assert.deepEqual(
        doMaisAntigo<any>([semData, b, invalida, a, antes, comDate]).map((pedido) => pedido.id),
        ['f', 'e', 'b', 'a', 'c', 'd']
    );
    assert.deepEqual(doMaisAntigo([]), []);
});

test('seções: o mais antigo em cima em "novos" e em "andamento"; a oferta continua no topo', () => {
    const velho = { id: 'order_a', adhoc: true, driver_assigned: null, created_at: '2026-10-19T13:00:00Z' };
    const novo = { id: 'order_b', adhoc: true, driver_assigned: null, created_at: '2026-10-19T13:10:00Z' };
    const oferta = { id: 'order_c', adhoc: true, driver_assigned: null, created_at: '2026-10-19T13:20:00Z', entregas_oferta: { vence_em: '2026-10-19T10:21:00-03:00' } };
    const meuNovo = { id: 'order_d', status: 'started', created_at: '2026-10-19T12:30:00Z' };
    const meuVelho = { id: 'order_e', status: 'created', created_at: '2026-10-19T11:00:00Z' };
    assert.deepEqual(secoesDaLista<any>([novo, oferta, velho], [meuNovo, meuVelho]), [
        { chave: 'novos', data: [oferta, velho, novo] },
        { chave: 'andamento', data: [meuVelho, meuNovo] },
    ]);
});

test('em andamento: as duas consultas juntas, sem repetir o mesmo pedido', () => {
    const ativo = { id: 'order_a', status: 'started', created_at: '2026-10-19T13:00:00Z' };
    const repetido = { id: 'order_a', status: 'started', created_at: '2026-10-19T13:00:00Z' };
    const atribuido = { id: 'order_b', status: 'created', created_at: '2026-10-19T12:00:00Z' };
    const secoes = secoesDaLista<any>([], [ativo, atribuido, repetido]);
    assert.equal(secoes.length, 1);
    assert.equal(secoes[0].chave, 'andamento');
    assert.equal(secoes[0].data.length, 2);
    assert.equal(secoes[0].data[0], atribuido);
    assert.equal(secoes[0].data[1], ativo);
    assert.equal(semRepetir<any>([ativo, repetido, { status: 'x' }, { status: 'y' }]).length, 3);
});
```

- [ ] **Passo 2: rodar e ver falhar**

```bash
cd /c/tmp/cp
node --experimental-strip-types --test scripts/testes/lista-de-pedidos.teste.ts 2>&1 | grep -E "does not provide an export|^# (pass|fail)"
```

Esperado: `SyntaxError: The requested module '../../src/utils/lista-de-pedidos.ts' does not provide an export named 'doMaisAntigo'` e `# fail 1`.

- [ ] **Passo 3: implementar**

Em `src/utils/lista-de-pedidos.ts`, troque o comentário das linhas 1 a 4:

```ts
// Entregas: a aba Pedidos do app (DriverOrderManagementScreen e OrderCard). A lista tem duas seções: os pedidos novos
// para aceitar (abertos por perto) e os pedidos dele em andamento, de qualquer dia (o histórico fica no Início, Meus
// ganhos). Em "Novos pedidos", a oferta dele (oferta um a um, com cronômetro) vem primeiro. O card mostra o número do
// pedido e a loja da coleta.
```

por:

```ts
// Entregas: a aba Pedidos do app (DriverOrderManagementScreen e OrderCard). A lista tem duas seções: os pedidos novos
// para aceitar (abertos por perto) e os pedidos dele em andamento, de qualquer dia (o histórico fica no Início, Meus
// ganhos). Nas duas, o mais antigo em cima (decisão de 2026-10-19); em "Novos pedidos", a oferta dele (oferta um a um,
// com cronômetro) vem primeiro. O card mostra o número do pedido e a loja da coleta.
```

E troque o bloco inteiro de `secoesDaLista` (do `/**` da linha 58 até o fim do arquivo):

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
    const novos = [...abertos.filter(temOferta), ...abertos.filter((pedido) => !temOferta(pedido))];

    const secoes: SecaoDaLista<T>[] = [];
    if (novos.length) secoes.push({ chave: 'novos', data: novos });
    if (emAndamento.length) secoes.push({ chave: 'andamento', data: emAndamento });
    return secoes;
}
```

por:

```ts
/** O instante de criação (ms) para ordenar. Sem created_at ou com data inválida: Infinity (vai para o fim). */
const criadoEm = (pedido: any): number => {
    const bruto = ler(pedido, 'created_at');
    const ms = bruto instanceof Date ? bruto.getTime() : typeof bruto === 'string' ? Date.parse(bruto) : typeof bruto === 'number' ? bruto : NaN;
    return Number.isFinite(ms) ? ms : Infinity;
};

/**
 * Do mais antigo ao mais novo por created_at. Estável: com o mesmo instante, vale a ordem de chegada. Sem created_at:
 * no fim. Não depende do sort da API (o filtro da lista de próximos acrescenta no fim os pedidos entre R e 2R).
 */
export function doMaisAntigo<T>(pedidos: T[]): T[] {
    return (pedidos ?? [])
        .map((pedido, posicao) => ({ pedido, posicao, quando: criadoEm(pedido) }))
        .sort((a, b) => (a.quando === b.quando ? a.posicao - b.posicao : a.quando < b.quando ? -1 : 1))
        .map(({ pedido }) => pedido);
}

/** O mesmo pedido uma vez só (fica o primeiro). Pedido sem id não é juntado com nenhum. */
export function semRepetir<T>(pedidos: T[]): T[] {
    const vistos = new Set<string>();
    return (pedidos ?? []).filter((pedido) => {
        const id = idDe(pedido);
        if (id === '') return true;
        if (vistos.has(id)) return false;
        vistos.add(id);
        return true;
    });
}

/**
 * As seções da aba Pedidos: "novos" (abertos por perto, menos os dispensados e os que já são dele; a oferta dele no topo) e "andamento" (os dele,
 * não encerrados, sem repetir: a lista junta duas consultas). Nas duas, do mais antigo ao mais novo (doMaisAntigo); em "novos", as ofertas
 * vêm antes, também do mais antigo ao mais novo. Seção vazia não entra; as duas vazias = lista vazia (a tela mostra "Nenhum pedido no momento").
 * A oferta dele aparece mesmo dispensada localmente: o Dispensar de um pedido em distribuição que voltou 409 grava o
 * pedido nos dispensados locais, e a distribuição em rodadas pode oferecê-lo a ele de novo numa volta seguinte.
 */
export function secoesDaLista<T>(proximos: T[], ativos: T[], dispensados: string[] = []): SecaoDaLista<T>[] {
    const emAndamento = doMaisAntigo(semRepetir((ativos ?? []).filter((pedido) => !pedidoEncerrado(pedido))));
    const meus = new Set(emAndamento.map(idDe));
    const abertos = (proximos ?? []).filter(
        (pedido) => ehPedidoAberto(pedido) && (temOferta(pedido) || !dispensados.includes(idDe(pedido))) && !meus.has(idDe(pedido))
    );
    const novos = [...doMaisAntigo(abertos.filter(temOferta)), ...doMaisAntigo(abertos.filter((pedido) => !temOferta(pedido)))];

    const secoes: SecaoDaLista<T>[] = [];
    if (novos.length) secoes.push({ chave: 'novos', data: novos });
    if (emAndamento.length) secoes.push({ chave: 'andamento', data: emAndamento });
    return secoes;
}
```

O `ler` e o `idDe` já existem no arquivo (linhas 13 e 31). A ordenação é feita com a posição como desempate para ser
estável em qualquer motor de JS (Hermes incluído).

- [ ] **Passo 4: rodar e ver passar**

```bash
cd /c/tmp/cp
node --experimental-strip-types --test scripts/testes/lista-de-pedidos.teste.ts 2>&1 | grep -E "^# (pass|fail)"
```

Esperado: `# pass 11` e `# fail 0` (os 8 de antes continuam passando: sem `created_at`, a ordem de chegada fica).

- [ ] **Passo 5: tirar o `comOfertaPrimeiro` e o teste dele**

Em `src/utils/oferta.ts`, apague (linhas 65 a 70, com a linha em branco depois):

```ts
/** "Novos pedidos" com a oferta dele no topo; os outros (abertos a todos) na ordem que vieram. */
export function comOfertaPrimeiro<T>(pedidos: T[]): T[] {
    const lista = pedidos ?? [];
    return [...lista.filter((p) => ehOferta(p)), ...lista.filter((p) => !ehOferta(p))];
}

```

Em `scripts/testes/oferta.teste.ts`, troque a linha 5:

```ts
import { ofertaDoPedido, ehOferta, segundosRestantes, comOfertaPrimeiro, recusarPeloAlarme, prazoLocal, segundosAte, recusaSemOferta, prazoDoPush, prazoDaTela, ehDistribuido, desfechoDaDispensa } from '../../src/utils/oferta.ts';
```

por:

```ts
import { ofertaDoPedido, ehOferta, segundosRestantes, recusarPeloAlarme, prazoLocal, segundosAte, recusaSemOferta, prazoDoPush, prazoDaTela, ehDistribuido, desfechoDaDispensa } from '../../src/utils/oferta.ts';
```

E apague o teste das linhas 33 a 41 (com a linha em branco depois):

```ts
test('a oferta vai para o topo de "Novos pedidos", o resto na ordem', () => {
    const a = { id: 'a' };
    const b = { id: 'b', entregas_oferta: { vence_em: '2026-10-07T10:00:30-03:00' } };
    const c = { id: 'c' };
    assert.deepEqual(comOfertaPrimeiro([a, b, c]).map((p) => p.id), ['b', 'a', 'c']);
    assert.deepEqual(comOfertaPrimeiro([a, c]).map((p) => p.id), ['a', 'c']);
    assert.deepEqual(comOfertaPrimeiro([]), []);
});

```

- [ ] **Passo 6: o comentário da aba Pedidos**

Em `src/screens/DriverOrderManagementScreen.tsx`, troque a linha 132:

```tsx
    // Entregas: novos (abertos por perto, menos os dispensados e os que já são dele) e em andamento; seção vazia não aparece
```

por:

```tsx
    // Entregas: novos (abertos por perto, menos os dispensados e os que já são dele) e em andamento; seção vazia não
    // aparece. Nas duas, o mais antigo em cima; a oferta dele continua no topo dos novos (secoesDaLista)
```

- [ ] **Passo 7: conferir**

```bash
cd /c/tmp/cp
node --experimental-strip-types --test scripts/testes/lista-de-pedidos.teste.ts scripts/testes/oferta.teste.ts 2>&1 | grep -E "^# (pass|fail)"
grep -rn "comOfertaPrimeiro" src scripts
bash /c/tmp/cp-tsc.sh
```

Esperado: `# pass 22`, `# fail 0`, nenhuma linha do `grep` e `tsc: nenhum erro novo`.

- [ ] **Passo 8: commit**

```bash
cd /c/tmp/cp
git add src/utils/lista-de-pedidos.ts src/utils/oferta.ts src/screens/DriverOrderManagementScreen.tsx scripts/testes/lista-de-pedidos.teste.ts scripts/testes/oferta.teste.ts
git commit -m "Pedidos: o mais antigo em cima nas duas seções, sem repetir; sai o comOfertaPrimeiro

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 2: funções puras da Comanda (nome do cliente, produtos, caixinhas)

**Arquivos:**
- Criar: `src/utils/comanda.ts`
- Modificar: `src/utils/pedido-ifood.ts:22-23` e `src/utils/pedido-de-origem.ts:20-21` (campo `itens`)
- Teste: `scripts/testes/comanda.teste.ts` (novo)

- [ ] **Passo 1: escrever o teste que falha**

Crie `scripts/testes/comanda.teste.ts`:

```ts
// Testes das funções puras da Comanda do pedido (src/utils/comanda.ts), com o Node puro:
//   node --experimental-strip-types --test scripts/testes/comanda.teste.ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    alternarConferido,
    contarItens,
    lerItens,
    linhaDosItens,
    nomeDoCliente,
    nomeDoClienteDoPedido,
    quantosConferidos,
    textoDasOpcoes,
    tudoConferido,
} from '../../src/utils/comanda.ts';

// como o recurso Order do SDK: getAttribute com caminho de pontos
const recurso = (atributos: Record<string, any>) => ({
    id: atributos.id,
    getAttribute: (caminho: string) => caminho.split('.').reduce((atual: any, chave) => (atual == null ? undefined : atual[chave]), atributos),
});

const PIZZA = { quantidade: 1, nome: 'Pizza grande Calabresa', opcoes: [{ quantidade: 1, nome: 'Borda de catupiry' }], observacao: 'sem cebola' };
const COCA = { quantidade: 2, nome: 'Coca-Cola lata 350 ml' };
const BROWNIE = { quantidade: 1, nome: 'Brownie de chocolate' };

test('nome do cliente: caixa normal, preposições minúsculas no meio', () => {
    assert.equal(nomeDoCliente('MARIANA SOUZA'), 'Mariana Souza');
    assert.equal(nomeDoCliente('CARLOS HENRIQUE DA SILVA'), 'Carlos Henrique da Silva');
    assert.equal(nomeDoCliente('  JOÃO  DOS SANTOS E SOUZA '), 'João dos Santos e Souza');
    assert.equal(nomeDoCliente('MARIA DAS DORES DO CARMO'), 'Maria das Dores do Carmo');
    assert.equal(nomeDoCliente('ANA-LUÍSA DE ÁVILA'), 'Ana-Luísa de Ávila');
    assert.equal(nomeDoCliente('das dores'), 'Das Dores');
});

test('nome do cliente: genérico, vazio ou igual à rua não é nome', () => {
    assert.equal(nomeDoCliente('Cliente iFood'), null);
    assert.equal(nomeDoCliente('  CLIENTE   IFOOD '), null);
    assert.equal(nomeDoCliente('   '), null);
    assert.equal(nomeDoCliente(''), null);
    assert.equal(nomeDoCliente(null), null);
    assert.equal(nomeDoCliente(undefined), null);
    assert.equal(nomeDoCliente(42), null);
    assert.equal(nomeDoCliente('RUA FLORÊNCIO DE ABREU, 845', ['Rua Florêncio de Abreu, 845']), null);
    assert.equal(nomeDoCliente('Rua Olinda, 45 - Centro', [null, 'Rua Olinda,  45 - Centro']), null);
    assert.equal(nomeDoCliente('MARIANA SOUZA', ['Rua Olinda, 45', null, '']), 'Mariana Souza');
});

test('nome do cliente pelo pedido (payload.dropoff), objeto ou recurso do SDK', () => {
    assert.equal(nomeDoClienteDoPedido({ payload: { dropoff: { name: 'MARIANA SOUZA', street1: 'Rua Olinda, 45' } } }), 'Mariana Souza');
    assert.equal(nomeDoClienteDoPedido(recurso({ payload: { dropoff: { name: 'Rua Olinda, 45', street1: 'Rua Olinda, 45' } } })), null);
    assert.equal(nomeDoClienteDoPedido(recurso({ payload: { dropoff: { name: 'Rua Olinda, 45 - Centro', street1: 'Rua Olinda, 45', address: 'Rua Olinda, 45 - Centro' } } })), null);
    assert.equal(nomeDoClienteDoPedido({ payload: { dropoff: { name: 'Cliente iFood', street1: 'Rua Olinda, 45' } } }), null);
    assert.equal(nomeDoClienteDoPedido({ payload: { dropoff: null } }), null);
    assert.equal(nomeDoClienteDoPedido({}), null);
    assert.equal(nomeDoClienteDoPedido(null), null);
});

test('produtos: sem a chave, null, vazio ou não lista = nenhum produto', () => {
    assert.deepEqual(lerItens(undefined), []);
    assert.deepEqual(lerItens(null), []);
    assert.deepEqual(lerItens([]), []);
    assert.deepEqual(lerItens('lixo'), []);
    assert.deepEqual(lerItens({ nome: 'Pizza' }), []);
});

test('produtos: lê o formato do servidor', () => {
    assert.deepEqual(lerItens([PIZZA, COCA]), [
        { quantidade: 1, nome: 'Pizza grande Calabresa', opcoes: [{ quantidade: 1, nome: 'Borda de catupiry' }], observacao: 'sem cebola' },
        { quantidade: 2, nome: 'Coca-Cola lata 350 ml', opcoes: [], observacao: null },
    ]);
});

test('produtos malformados: item sem nome sai, o resto cai para o padrão', () => {
    assert.deepEqual(
        lerItens([
            null,
            7,
            'Pizza',
            { quantidade: 3 },
            { nome: '   ' },
            { quantidade: 0, nome: '  Brownie   de chocolate ', opcoes: 'x', observacao: '  ' },
            { quantidade: '2', nome: 'Guaraná 2 L', opcoes: [null, { nome: '' }, { quantidade: 'abc', nome: 'Gelo' }, { quantidade: 3, nome: 'Limão' }], observacao: 5 },
            { quantidade: 1.5, nome: 'Suco' },
        ]),
        [
            { quantidade: 1, nome: 'Brownie de chocolate', opcoes: [], observacao: null },
            { quantidade: 2, nome: 'Guaraná 2 L', opcoes: [{ quantidade: 1, nome: 'Gelo' }, { quantidade: 3, nome: 'Limão' }], observacao: null },
            { quantidade: 1, nome: 'Suco', opcoes: [], observacao: null },
        ]
    );
    assert.equal(lerItens(Array.from({ length: 60 }, (_, i) => ({ quantidade: 1, nome: `Item ${i}` }))).length, 50);
});

test('contagem: a soma das quantidades', () => {
    assert.equal(contarItens(lerItens([PIZZA, COCA, BROWNIE])), 4);
    assert.equal(contarItens(lerItens([{ quantidade: 1, nome: 'Pizza' }])), 1);
    assert.equal(contarItens([]), 0);
});

test('linha resumida: quantidade × nome separados por " · ", cortada com "…"', () => {
    const itens = lerItens([PIZZA, COCA, BROWNIE]);
    assert.equal(linhaDosItens(itens), '1× Pizza grande Calabresa · 2× Coca-Cola lata 350 ml · 1× Brownie de chocolate');
    assert.equal(linhaDosItens(itens, 30), '1× Pizza grande Calabresa · 2…');
    assert.equal(linhaDosItens(itens, 28), '1× Pizza grande Calabresa ·…');
    assert.equal(linhaDosItens([]), '');
});

test('opções do produto numa linha', () => {
    const [pizza] = lerItens([{ ...PIZZA, opcoes: [{ quantidade: 1, nome: 'Borda de catupiry' }, { quantidade: 2, nome: 'Bacon' }] }]);
    assert.equal(textoDasOpcoes(pizza), 'Borda de catupiry, 2× Bacon');
    assert.equal(textoDasOpcoes(lerItens([BROWNIE])[0]), null);
});

test('caixinhas: marcar, desmarcar e "tudo conferido"', () => {
    let marcados: number[] = [];
    marcados = alternarConferido(marcados, 0);
    marcados = alternarConferido(marcados, 2);
    assert.deepEqual(marcados, [0, 2]);
    assert.equal(quantosConferidos(3, marcados), 2);
    assert.equal(tudoConferido(3, marcados), false);
    marcados = alternarConferido(marcados, 1);
    assert.equal(tudoConferido(3, marcados), true);
    marcados = alternarConferido(marcados, 0);
    assert.deepEqual(marcados, [2, 1]);
    assert.equal(tudoConferido(3, marcados), false);
    assert.equal(tudoConferido(0, []), false);
    assert.equal(quantosConferidos(2, [0, 1, 5, -1, 1]), 2);
    assert.equal(tudoConferido(2, [0, 1, 7]), true);
});
```

- [ ] **Passo 2: rodar e ver falhar**

```bash
cd /c/tmp/cp
node --experimental-strip-types --test scripts/testes/comanda.teste.ts 2>&1 | grep -E "ERR_MODULE_NOT_FOUND|^# (pass|fail)"
```

Esperado: `ERR_MODULE_NOT_FOUND` (não existe `src/utils/comanda.ts`) e `# fail 1`.

- [ ] **Passo 3: implementar**

Crie `src/utils/comanda.ts`:

```ts
// Entregas: a "Comanda" do pedido no app (sketch 007 B). Na folha do pedido: o nome do cliente em caixa normal e os
// produtos do pedido (campo `itens` das rotas v1/entregas/motoboy/pedidos/{id}/ifood e .../integracao), com as caixinhas
// de conferir na loja. Sem a chave `itens`, com null, lista vazia ou malformada: nenhum produto, e os blocos de produtos
// somem (nunca "0 itens"). O nome do cliente só aparece depois do aceite (regra da tela, não do servidor).
// Os pedidos chegam como recurso do SDK (getAttribute) ou serializados (objeto comum): as funções leem os dois.
// Sem imports, para os testes rodarem com o Node puro: node --experimental-strip-types --test scripts/testes/comanda.teste.ts

export type OpcaoDoItem = { quantidade: number; nome: string };

export type ItemDoPedido = { quantidade: number; nome: string; opcoes: OpcaoDoItem[]; observacao: string | null };

/** No meio do nome, ficam minúsculas. */
const PEQUENAS = ['da', 'de', 'do', 'das', 'dos', 'e'];

/** O nome que o servidor grava quando o iFood não manda o nome do cliente (comparado sem caixa). */
const GENERICOS = ['cliente ifood'];

/** Os mesmos limites do servidor (spec dos produtos no servidor): mais que isso é lixo. */
const MAX_ITENS = 50;
const MAX_OPCOES = 20;

const ler = (pedido: any, caminho: string): any => {
    if (!pedido) return undefined;
    if (typeof pedido.getAttribute === 'function') return pedido.getAttribute(caminho);
    return caminho.split('.').reduce((atual: any, chave) => (atual === null || atual === undefined ? undefined : atual[chave]), pedido);
};

/** Texto limpo (espaços repetidos viram um) ou null. Só aceita string. */
const texto = (valor: unknown): string | null => {
    if (typeof valor !== 'string') return null;
    const limpo = valor.replace(/\s+/g, ' ').trim();
    return limpo === '' ? null : limpo;
};

const comparavel = (valor: string): string => valor.toLowerCase().replace(/\s+/g, ' ').trim();

const maiuscula = (parte: string): string => (parte ? parte.charAt(0).toUpperCase() + parte.slice(1) : parte);

/** "MARIANA SOUZA" → "Mariana Souza"; "CARLOS HENRIQUE DA SILVA" → "Carlos Henrique da Silva"; "ANA-LUÍSA" → "Ana-Luísa". */
export function emCaixaNormal(nome: string): string {
    return nome
        .toLowerCase()
        .split(/\s+/)
        .filter(Boolean)
        .map((palavra, posicao) => (posicao > 0 && PEQUENAS.includes(palavra) ? palavra : palavra.split('-').map(maiuscula).join('-')))
        .join(' ');
}

/**
 * O nome do cliente para a tela, em caixa normal, ou null quando não há nome de verdade: vazio, "Cliente iFood" ou igual
 * a um dos endereços do lugar (a rua ou o endereço montado, que o Fleetbase usa como nome do lugar sem nome).
 */
export function nomeDoCliente(nome: unknown, enderecos: Array<string | null | undefined> = []): string | null {
    const limpo = texto(nome);
    if (!limpo) return null;
    const chave = comparavel(limpo);
    if (GENERICOS.includes(chave)) return null;
    if (enderecos.some((endereco) => typeof endereco === 'string' && endereco.trim() !== '' && comparavel(endereco) === chave)) return null;
    return emCaixaNormal(limpo);
}

/** O nome do cliente pelo pedido (payload.dropoff.name), comparado com a rua e o endereço do mesmo lugar. */
export function nomeDoClienteDoPedido(pedido: any): string | null {
    const lugar = ler(pedido, 'payload.dropoff');
    if (!lugar || typeof lugar !== 'object') return null;
    const campo = (nome: string): unknown => (typeof lugar.getAttribute === 'function' ? lugar.getAttribute(nome) : lugar[nome]);
    return nomeDoCliente(campo('name'), [campo('street1') as string | null, campo('address') as string | null]);
}

/** Inteiro de 1 a 999 (número ou texto com número), ou null. */
const quantidadeValida = (bruto: unknown): number | null => {
    const numero = typeof bruto === 'number' ? bruto : typeof bruto === 'string' && bruto.trim() !== '' ? Number(bruto) : NaN;
    return Number.isInteger(numero) && numero >= 1 && numero <= 999 ? numero : null;
};

const lerOpcao = (bruto: unknown): OpcaoDoItem | null => {
    if (!bruto || typeof bruto !== 'object') return null;
    const nome = texto((bruto as any).nome);
    if (!nome) return null;
    return { quantidade: quantidadeValida((bruto as any).quantidade) ?? 1, nome };
};

/**
 * Os produtos do campo `itens` ({quantidade, nome, opcoes?, observacao?}). Ausente, null, não lista ou sem item válido: [].
 * Item sem nome não entra; quantidade inválida vira 1; opção sem nome e observação vazia caem. No máximo 50 produtos e
 * 20 opções por produto.
 */
export function lerItens(bruto: unknown): ItemDoPedido[] {
    if (!Array.isArray(bruto)) return [];
    const itens: ItemDoPedido[] = [];
    for (const cru of bruto) {
        if (itens.length >= MAX_ITENS) break;
        if (!cru || typeof cru !== 'object') continue;
        const nome = texto((cru as any).nome);
        if (!nome) continue;
        const opcoesBrutas = Array.isArray((cru as any).opcoes) ? (cru as any).opcoes : [];
        const opcoes: OpcaoDoItem[] = [];
        for (const opcaoBruta of opcoesBrutas) {
            if (opcoes.length >= MAX_OPCOES) break;
            const opcao = lerOpcao(opcaoBruta);
            if (opcao) opcoes.push(opcao);
        }
        itens.push({ quantidade: quantidadeValida((cru as any).quantidade) ?? 1, nome, opcoes, observacao: texto((cru as any).observacao) });
    }
    return itens;
}

/** Quantos produtos: a soma das quantidades ("4 itens"). */
export function contarItens(itens: ItemDoPedido[]): number {
    return (itens ?? []).reduce((soma, item) => soma + item.quantidade, 0);
}

/**
 * A linha resumida: "1× Pizza grande Calabresa · 2× Coca-Cola lata 350 ml · 1× Brownie de chocolate". Acima de `limite`
 * caracteres, cortada com "…" (a tela ainda corta pelo número de linhas).
 */
export function linhaDosItens(itens: ItemDoPedido[], limite: number = 160): string {
    const linha = (itens ?? []).map((item) => `${item.quantidade}× ${item.nome}`).join(' · ');
    return linha.length > limite ? linha.slice(0, limite - 1).trimEnd() + '…' : linha;
}

/** As opções de um produto numa linha: "Borda de catupiry, 2× Bacon". Sem opções: null. */
export function textoDasOpcoes(item: ItemDoPedido): string | null {
    const partes = (item?.opcoes ?? []).map((opcao) => (opcao.quantidade > 1 ? `${opcao.quantidade}× ${opcao.nome}` : opcao.nome));
    return partes.length ? partes.join(', ') : null;
}

/** Caixinha de conferir (só no celular): marca ou desmarca o produto da posição `indice`. */
export function alternarConferido(marcados: number[], indice: number): number[] {
    return marcados.includes(indice) ? marcados.filter((posicao) => posicao !== indice) : [...marcados, indice];
}

/** Quantos produtos da lista de `total` estão marcados (posições repetidas ou fora da lista não contam). */
export function quantosConferidos(total: number, marcados: number[]): number {
    return new Set((marcados ?? []).filter((posicao) => Number.isInteger(posicao) && posicao >= 0 && posicao < total)).size;
}

/** "Tudo conferido": todos os produtos marcados. Lista vazia nunca está conferida. */
export function tudoConferido(total: number, marcados: number[]): boolean {
    return total > 0 && quantosConferidos(total, marcados) === total;
}
```

Usa `toLowerCase`/`toUpperCase` sem locale (funcionam com acentos no Hermes e no Node, sem depender do `Intl`).

- [ ] **Passo 4: rodar e ver passar**

```bash
cd /c/tmp/cp
node --experimental-strip-types --test scripts/testes/comanda.teste.ts 2>&1 | grep -E "^# (pass|fail)"
```

Esperado: `# pass 10` e `# fail 0`.

- [ ] **Passo 5: o campo `itens` nos tipos das respostas**

Em `src/utils/pedido-ifood.ts`, troque (linhas 22 e 23):

```ts
    telefone: TelefoneIfood | null;
};
```

por:

```ts
    telefone: TelefoneIfood | null;
    /** Produtos do pedido (Comanda): lista crua do servidor, lida por lerItens (src/utils/comanda.ts). Ausente nos servidores antigos. */
    itens?: unknown;
};
```

Em `src/utils/pedido-de-origem.ts`, troque (linhas 20 e 21):

```ts
    telefone: string | null;
};
```

por:

```ts
    telefone: string | null;
    /** Produtos do pedido (Comanda): lista crua do servidor, lida por lerItens (src/utils/comanda.ts). Ausente nos servidores antigos. */
    itens?: unknown;
};
```

Os hooks `useDadosIfood` e `useDadosDaIntegracao` não mudam: eles já devolvem a resposta inteira da rota, então o `itens`
do servidor novo chega sozinho em `dados.itens`.

- [ ] **Passo 6: conferir**

```bash
cd /c/tmp/cp
node --experimental-strip-types --test scripts/testes/comanda.teste.ts scripts/testes/pedido-ifood.teste.ts scripts/testes/pedido-de-origem.teste.ts 2>&1 | grep -E "^# (pass|fail)"
bash /c/tmp/cp-tsc.sh
```

Esperado: `# fail 0` e `tsc: nenhum erro novo`.

- [ ] **Passo 7: commit**

```bash
cd /c/tmp/cp
git add src/utils/comanda.ts src/utils/pedido-ifood.ts src/utils/pedido-de-origem.ts scripts/testes/comanda.teste.ts
git commit -m "Comanda: nome do cliente em caixa normal, leitura dos produtos (itens) e caixinhas de conferir

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 3: `LinhaTracejada` e `ListaDeItens`

**Arquivos:**
- Criar: `src/components/entregas/LinhaTracejada.tsx`
- Criar: `src/components/entregas/ListaDeItens.tsx`

Os textos `Comanda.*` entram na Tarefa 13; até lá, o i18n mostra `[missing "pt.Comanda…" translation]` (não quebra).

- [ ] **Passo 1: `LinhaTracejada`**

Crie `src/components/entregas/LinhaTracejada.tsx`:

```tsx
import { YStack } from 'tamagui';

// Entregas: linha tracejada horizontal (cupom da Comanda do pedido). O Android só desenha o tracejado com a borda
// inteira: a caixa de 2 px fica dentro de uma de 1 px que esconde o resto, e só a borda de cima aparece.
const LinhaTracejada = () => (
    <YStack height={1} overflow='hidden' my='$2.5'>
        <YStack height={2} borderWidth={1} borderStyle='dashed' borderColor='$borderColor' />
    </YStack>
);

export default LinhaTracejada;
```

- [ ] **Passo 2: `ListaDeItens`**

Crie `src/components/entregas/ListaDeItens.tsx`:

```tsx
import { Pressable } from 'react-native';
import { Text, XStack, YStack, useTheme } from 'tamagui';
import { FontAwesomeIcon } from '@fortawesome/react-native-fontawesome';
import { faCheck } from '@fortawesome/free-solid-svg-icons';
import { useLanguage } from '../../contexts/LanguageContext';
import { textoDasOpcoes } from '../../utils/comanda';
import type { ItemDoPedido } from '../../utils/comanda';

// Entregas: as linhas dos produtos do pedido (Comanda, sketch 007 B).
// - conferir (na loja): quantidade, nome, opções, a observação do item em amarelo e a caixinha de conferir; o toque na
//   linha marca e desmarca (quem guarda as marcas é a ComandaDoPedido);
// - resumo (na porta): só quantidade e nome.
type Props = {
    itens: ItemDoPedido[];
    modo: 'conferir' | 'resumo';
    marcados?: number[];
    onAlternar?: (indice: number) => void;
};

const Caixinha = ({ marcada }: { marcada: boolean }) => {
    const theme = useTheme();
    return (
        <YStack
            width={38}
            height={38}
            borderRadius={10}
            borderWidth={2}
            borderColor={marcada ? '$success' : '$borderColor'}
            bg={marcada ? '$success' : '$cartao'}
            alignItems='center'
            justifyContent='center'
        >
            {marcada && <FontAwesomeIcon icon={faCheck} color={theme['$sobreSucesso'].val} size={18} />}
        </YStack>
    );
};

const ListaDeItens = ({ itens, modo, marcados = [], onAlternar }: Props) => {
    const { t } = useLanguage();
    const conferir = modo === 'conferir';

    return (
        <YStack>
            {itens.map((item, indice) => {
                const marcada = conferir && marcados.includes(indice);
                const opcoes = conferir ? textoDasOpcoes(item) : null;
                const ultima = indice === itens.length - 1;
                const linha = (
                    <XStack
                        gap='$2.5'
                        alignItems={conferir ? 'flex-start' : 'baseline'}
                        py={conferir ? 9 : 4}
                        borderBottomWidth={conferir && !ultima ? 1 : 0}
                        borderColor='$borderColor'
                    >
                        <Text color='$tintaPrimaria' fontFamily='$heading' fontWeight='800' fontSize={conferir ? 21 : 18} minWidth={30}>
                            {`${item.quantidade}×`}
                        </Text>
                        <YStack flex={1} minWidth={0} gap={2}>
                            <Text color={marcada ? '$textSecondary' : '$textPrimary'} fontSize={conferir ? 16 : 15} fontWeight={conferir ? '700' : '600'}>
                                {item.nome}
                            </Text>
                            {!!opcoes && (
                                <Text color='$textSecondary' fontSize={13}>
                                    {opcoes}
                                </Text>
                            )}
                            {conferir && !!item.observacao && (
                                <XStack alignSelf='flex-start' bg='$avisoSuave' borderRadius={6} px={7} py={1}>
                                    <Text color='$tintaAviso' fontSize={13} fontWeight='700'>
                                        {t('Comanda.obsDoItem', { texto: item.observacao })}
                                    </Text>
                                </XStack>
                            )}
                        </YStack>
                        {conferir && <Caixinha marcada={marcada} />}
                    </XStack>
                );

                if (!conferir) {
                    return <YStack key={indice}>{linha}</YStack>;
                }
                return (
                    <Pressable
                        key={indice}
                        onPress={() => onAlternar?.(indice)}
                        accessibilityRole='checkbox'
                        accessibilityState={{ checked: marcada }}
                        accessibilityLabel={t('Comanda.marcarConferido', { nome: item.nome })}
                    >
                        {linha}
                    </Pressable>
                );
            })}
        </YStack>
    );
};

export default ListaDeItens;
```

O `Pressable` do React Native dentro da `BottomSheetScrollView` já funciona na mesma folha (é o que o `BotaoRedondo` usa).

- [ ] **Passo 3: conferir**

```bash
bash /c/tmp/cp-tsc.sh
```

Esperado: `tsc: nenhum erro novo`.

- [ ] **Passo 4: commit**

```bash
cd /c/tmp/cp
git add src/components/entregas/LinhaTracejada.tsx src/components/entregas/ListaDeItens.tsx
git commit -m "Comanda: linhas dos produtos (conferir e resumo) e o tracejado do cupom

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 4: `ComandaDoPedido` (o cupom da loja com as caixinhas)

**Arquivos:**
- Criar: `src/components/entregas/ComandaDoPedido.tsx`

- [ ] **Passo 1: criar o componente**

Crie `src/components/entregas/ComandaDoPedido.tsx`:

```tsx
import { useEffect, useState } from 'react';
import { Text, XStack, YStack, useTheme } from 'tamagui';
import { FontAwesomeIcon } from '@fortawesome/react-native-fontawesome';
import { faCheck } from '@fortawesome/free-solid-svg-icons';
import { useLanguage } from '../../contexts/LanguageContext';
import { alternarConferido, contarItens, quantosConferidos, tudoConferido } from '../../utils/comanda';
import type { ItemDoPedido } from '../../utils/comanda';
import Selo from './Selo';
import ListaDeItens from './ListaDeItens';
import LinhaTracejada from './LinhaTracejada';

// Entregas: a comanda do pedido na loja (sketch 007 B, tela "Na loja: conferir"), como um cupom: "Mostre no balcão", o
// selo e o número grande, "para Mariana Souza", os produtos com as caixinhas de conferir e a observação do cliente.
// As caixinhas só ajudam o motoboy: não travam o deslizar, não vão ao servidor e zeram ao fechar a tela (ou quando os
// produtos mudam). Com todas marcadas, "Tudo conferido". Sem número, sem nome, sem produtos e sem observação: nada.
type Props = {
    selo: { variante: 'ifood' | 'laranja'; texto: string } | null;
    numero: string | null; // "#4821"
    cliente: string | null; // "Mariana Souza" (nunca o nome genérico)
    itens: ItemDoPedido[];
    observacao: string | null;
};

const ComandaDoPedido = ({ selo, numero, cliente, itens, observacao }: Props) => {
    const theme = useTheme();
    const { t } = useLanguage();
    const [marcados, setMarcados] = useState<number[]>([]);
    const chaveDosItens = itens.map((item) => `${item.quantidade}×${item.nome}`).join('|');

    useEffect(() => {
        setMarcados([]);
    }, [chaveDosItens]);

    // "Mostre no balcão" só com o número (pedido da central não tem número para o balcão)
    const temNumero = !!selo || !!numero;
    const temTopo = temNumero || !!cliente;
    const temItens = itens.length > 0;
    if (!temTopo && !temItens && !observacao) {
        return null;
    }
    const conferidos = quantosConferidos(itens.length, marcados);
    const tudo = tudoConferido(itens.length, marcados);

    return (
        <YStack bg='$surface' borderRadius='$6' px='$3.5' py='$3'>
            {temTopo && (
                <YStack alignItems='center' gap='$1'>
                    {temNumero && (
                        <Text color='$textSecondary' fontSize={12} fontWeight='800' textTransform='uppercase' letterSpacing={0.6}>
                            {t('Comanda.mostreNoBalcao')}
                        </Text>
                    )}
                    {selo && (
                        <XStack justifyContent='center'>
                            <Selo variante={selo.variante} tamanho='medio'>
                                {selo.texto}
                            </Selo>
                        </XStack>
                    )}
                    {!!numero && (
                        <Text color='$textPrimary' fontFamily='$heading' fontWeight='800' fontSize={60} lineHeight={62} textAlign='center' numberOfLines={1}>
                            {numero}
                        </Text>
                    )}
                    {!!cliente && (
                        <Text color='$textPrimary' fontSize={15} textAlign='center'>
                            {t('Comanda.para')}{' '}
                            <Text color='$textPrimary' fontSize={15} fontWeight='800'>
                                {cliente}
                            </Text>
                        </Text>
                    )}
                </YStack>
            )}

            {temItens && (
                <>
                    {temTopo && <LinhaTracejada />}
                    <ListaDeItens itens={itens} modo='conferir' marcados={marcados} onAlternar={(indice) => setMarcados((atuais) => alternarConferido(atuais, indice))} />
                    <XStack alignItems='center' justifyContent='space-between' gap='$2' pt='$2'>
                        {tudo ? (
                            <XStack alignItems='center' gap='$1.5'>
                                <FontAwesomeIcon icon={faCheck} color={theme['$success'].val} size={14} />
                                <Text color='$success' fontSize={14} fontWeight='700'>
                                    {t('Comanda.tudoConferido')}
                                </Text>
                            </XStack>
                        ) : (
                            <Text color='$textSecondary' fontSize={14} fontWeight='600'>
                                {t('Comanda.conferidos', { marcados: conferidos, total: itens.length })}
                            </Text>
                        )}
                        <Text color='$textSecondary' fontSize={14} fontWeight='600'>
                            {t('Comanda.itens', { count: contarItens(itens) })}
                        </Text>
                    </XStack>
                </>
            )}

            {!!observacao && (
                <>
                    {(temTopo || temItens) && <LinhaTracejada />}
                    <Text color='$textPrimary' fontSize={15} lineHeight={21} selectable>
                        <Text color='$textPrimary' fontSize={15} fontWeight='800'>
                            {t('Comanda.obsDoCliente')}
                        </Text>{' '}
                        {observacao}
                    </Text>
                </>
            )}
        </YStack>
    );
};

export default ComandaDoPedido;
```

O estado das caixinhas fica no componente: a folha (BottomSheet) continua montada enquanto a tela está aberta, então as
marcas sobrevivem a recolher e abrir a folha, e somem ao fechar a tela ou ao passar para a etapa do cliente.

- [ ] **Passo 2: conferir**

```bash
bash /c/tmp/cp-tsc.sh
```

Esperado: `tsc: nenhum erro novo`.

- [ ] **Passo 3: commit**

```bash
cd /c/tmp/cp
git add src/components/entregas/ComandaDoPedido.tsx
git commit -m "Comanda: cupom da loja com número do balcão, nome do cliente e caixinhas de conferir

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 5: `ResumoDosItens` (comandinha dos cartões e produtos na porta)

**Arquivos:**
- Criar: `src/components/entregas/ResumoDosItens.tsx`

- [ ] **Passo 1: criar o componente**

Crie `src/components/entregas/ResumoDosItens.tsx`:

```tsx
import { Text, XStack, YStack, useTheme } from 'tamagui';
import { FontAwesomeIcon } from '@fortawesome/react-native-fontawesome';
import { faBagShopping } from '@fortawesome/free-solid-svg-icons';
import { useLanguage } from '../../contexts/LanguageContext';
import { contarItens, linhaDosItens } from '../../utils/comanda';
import type { ItemDoPedido } from '../../utils/comanda';
import ListaDeItens from './ListaDeItens';
import LinhaTracejada from './LinhaTracejada';

// Entregas: os produtos do pedido resumidos (Comanda, sketch 007 B).
// - linha: a "comandinha" tracejada dos cartões de pedido aberto e de oferta: "4 itens · o que vai na bag" e os produtos
//   numa linha ("1× Pizza grande Calabresa · 2× Coca-Cola…"), para o motoboy ver o volume antes de aceitar;
// - cupom: na porta, "Produtos · iFood #4821 · 4 itens" e a lista com quantidade e nome.
// Sem produtos: nada (nunca "0 itens").
type Props = {
    itens: ItemDoPedido[];
    variante: 'linha' | 'cupom';
    origem?: string | null; // "iFood #4821" no cabeçalho do cupom
};

const ResumoDosItens = ({ itens, variante, origem = null }: Props) => {
    const theme = useTheme();
    const { t } = useLanguage();
    if (!itens || itens.length === 0) {
        return null;
    }
    const quantos = t('Comanda.itens', { count: contarItens(itens) });

    if (variante === 'linha') {
        return (
            <YStack bg='$surface' borderRadius='$4' borderWidth={1.5} borderStyle='dashed' borderColor='$borderColor' px='$3' py='$2.5' gap={2}>
                <XStack alignItems='center' gap='$1.5'>
                    <FontAwesomeIcon icon={faBagShopping} color={theme['$textPrimary'].val} size={13} />
                    <Text color='$textPrimary' fontSize={15} fontWeight='800'>
                        {quantos}
                    </Text>
                    <Text color='$textSecondary' fontSize={14} flexShrink={1} numberOfLines={1}>
                        · {t('Comanda.oQueVaiNaBag')}
                    </Text>
                </XStack>
                <Text color='$textPrimary' fontSize={14} lineHeight={20} numberOfLines={2}>
                    {linhaDosItens(itens)}
                </Text>
            </YStack>
        );
    }

    return (
        <YStack bg='$surface' borderRadius='$6' px='$3.5' py='$3'>
            <XStack alignItems='center' gap='$2'>
                <FontAwesomeIcon icon={faBagShopping} color={theme['$textPrimary'].val} size={14} />
                <Text color='$textPrimary' fontFamily='$heading' fontWeight='700' fontSize={19}>
                    {t('Comanda.produtos')}
                </Text>
                <Text color='$textSecondary' fontSize={14} flexShrink={1} numberOfLines={1}>
                    · {[origem, quantos].filter(Boolean).join(' · ')}
                </Text>
            </XStack>
            <LinhaTracejada />
            <ListaDeItens itens={itens} modo='resumo' />
        </YStack>
    );
};

export default ResumoDosItens;
```

- [ ] **Passo 2: conferir**

```bash
bash /c/tmp/cp-tsc.sh
```

Esperado: `tsc: nenhum erro novo`.

- [ ] **Passo 3: commit**

```bash
cd /c/tmp/cp
git add src/components/entregas/ResumoDosItens.tsx
git commit -m "Comanda: produtos resumidos (comandinha dos cartões e cupom da porta)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 6: `CobrancaEmUmaLinha`, `AvisosDaPorta` e `MaisDoPedido`

**Arquivos:**
- Criar: `src/components/entregas/CobrancaEmUmaLinha.tsx`
- Criar: `src/components/entregas/AvisosDaPorta.tsx`
- Criar: `src/components/entregas/MaisDoPedido.tsx`

- [ ] **Passo 1: `CobrancaEmUmaLinha`**

Crie `src/components/entregas/CobrancaEmUmaLinha.tsx`:

```tsx
import { Text, XStack, useTheme } from 'tamagui';
import { FontAwesomeIcon } from '@fortawesome/react-native-fontawesome';
import { faCheck } from '@fortawesome/free-solid-svg-icons';
import CobrancaNaPorta from './CobrancaNaPorta';

// Entregas: a cobrança na porta numa linha só (Comanda, sketch 007 B). Com valor a cobrar, a faixa amarela de sempre
// (CobrancaNaPorta: "Cobrar R$ 87,50 · dinheiro, troco p/ R$ 100", texto pronto do servidor); sem valor, a linha verde
// de pago ("Pago online, nada a cobrar" no iFood, "Já pago" na integração), que vem pronta de quem chama.
type Props = { texto: string | null; textoPago: string };

const CobrancaEmUmaLinha = ({ texto, textoPago }: Props) => {
    const theme = useTheme();
    if (texto) {
        return <CobrancaNaPorta texto={texto} />;
    }

    return (
        <XStack px='$3' py='$2.5' gap='$2' alignItems='center' borderRadius='$4' bg='$sucessoSuave'>
            <FontAwesomeIcon icon={faCheck} color={theme['$success'].val} />
            <Text color='$textPrimary' fontSize={15} fontWeight='700' flex={1}>
                {textoPago}
            </Text>
        </XStack>
    );
};

export default CobrancaEmUmaLinha;
```

- [ ] **Passo 2: `AvisosDaPorta`**

Crie `src/components/entregas/AvisosDaPorta.tsx`:

```tsx
import { Text, XStack, YStack, useTheme } from 'tamagui';
import { FontAwesomeIcon } from '@fortawesome/react-native-fontawesome';
import { faCircleInfo, faLocationDot } from '@fortawesome/free-solid-svg-icons';
import { useLanguage } from '../../contexts/LanguageContext';

// Entregas: na porta (Comanda, sketch 007 B), as observações do cliente numa faixa amarela e o complemento com a
// referência num cartão curto. Textos sem corte e selecionáveis. Sem os dois: nada.
type Props = {
    observacoes: string | null;
    complemento: string | null; // "Ap 32, bloco B · Ref.: portão cinza"
};

const AvisosDaPorta = ({ observacoes, complemento }: Props) => {
    const theme = useTheme();
    const { t } = useLanguage();
    if (!observacoes && !complemento) {
        return null;
    }

    return (
        <YStack gap='$2'>
            {!!observacoes && (
                <XStack bg='$avisoSuave' borderRadius='$4' px='$3' py='$2.5' gap='$2' alignItems='flex-start'>
                    <YStack pt={3}>
                        <FontAwesomeIcon icon={faCircleInfo} color={theme['$tintaAviso'].val} size={15} />
                    </YStack>
                    <Text flex={1} color='$textPrimary' fontSize={15} lineHeight={21} selectable>
                        <Text color='$textPrimary' fontSize={15} fontWeight='800'>
                            {t('Comanda.obsDoCliente')}
                        </Text>{' '}
                        {observacoes}
                    </Text>
                </XStack>
            )}
            {!!complemento && (
                <XStack bg='$surface' borderRadius='$4' px='$3' py='$2.5' gap='$2' alignItems='flex-start'>
                    <YStack pt={3}>
                        <FontAwesomeIcon icon={faLocationDot} color={theme['$textSecondary'].val} size={15} />
                    </YStack>
                    <Text flex={1} color='$textPrimary' fontSize={15} lineHeight={21} fontWeight='600' selectable>
                        {complemento}
                    </Text>
                </XStack>
            )}
        </YStack>
    );
};

export default AvisosDaPorta;
```

- [ ] **Passo 3: `MaisDoPedido`**

Crie `src/components/entregas/MaisDoPedido.tsx`:

```tsx
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Pressable } from 'react-native';
import { Text, XStack, YStack, useTheme } from 'tamagui';
import { FontAwesomeIcon } from '@fortawesome/react-native-fontawesome';
import { faChevronDown, faChevronUp, faEllipsis } from '@fortawesome/free-solid-svg-icons';
import { useLanguage } from '../../contexts/LanguageContext';

// Entregas: o "Mais" do fim da folha do pedido (Comanda, sketch 007 B). O que é de apoio (rota e paradas, o destino
// atual, a loja, o valor detalhado e todos os status) fica recolhido; o toque no cabeçalho abre e fecha. Começa fechado
// a cada abertura da tela. Fechado, o conteúdo nem é montado.
const MaisDoPedido = ({ children }: { children: ReactNode }) => {
    const theme = useTheme();
    const { t } = useLanguage();
    const [aberto, setAberto] = useState(false);

    return (
        <YStack borderWidth={1} borderColor='$borderColor' borderRadius='$6'>
            <Pressable onPress={() => setAberto((atual) => !atual)} accessibilityRole='button' accessibilityState={{ expanded: aberto }}>
                <XStack alignItems='center' gap='$2.5' px='$3' py='$3'>
                    <FontAwesomeIcon icon={faEllipsis} color={theme['$textPrimary'].val} />
                    <YStack flex={1} minWidth={0}>
                        <Text color='$textPrimary' fontFamily='$heading' fontWeight='700' fontSize={19}>
                            {t('Comanda.mais')}
                        </Text>
                        <Text color='$textSecondary' fontSize={12} numberOfLines={1}>
                            {t('Comanda.maisTexto')}
                        </Text>
                    </YStack>
                    <FontAwesomeIcon icon={aberto ? faChevronUp : faChevronDown} color={theme['$textSecondary'].val} />
                </XStack>
            </Pressable>
            {aberto && (
                <YStack px='$3' pb='$3' gap='$3'>
                    {children}
                </YStack>
            )}
        </YStack>
    );
};

export default MaisDoPedido;
```

- [ ] **Passo 4: conferir**

```bash
bash /c/tmp/cp-tsc.sh
```

Esperado: `tsc: nenhum erro novo`.

- [ ] **Passo 5: commit**

```bash
cd /c/tmp/cp
git add src/components/entregas/CobrancaEmUmaLinha.tsx src/components/entregas/AvisosDaPorta.tsx src/components/entregas/MaisDoPedido.tsx
git commit -m "Comanda: cobrança numa linha, avisos da porta e o Mais recolhível

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 7: `LigarComLocalizador` (Ligar 0800 ao lado do localizador)

**Arquivos:**
- Modificar: `src/components/PedidoIfood.tsx` (depois do `LocalizadorIfood`, antes do `TextoLongo`, linha 176)

- [ ] **Passo 1: acrescentar o componente**

Em `src/components/PedidoIfood.tsx`, troque:

```tsx
// Observações e complemento podem ser longos: sem limite de linhas e com seleção.
const TextoLongo = ({ titulo, texto }: { titulo: string; texto: string }) => (
```

por:

```tsx
/**
 * Entregas (Comanda, sketch 007 B): na porta, "Ligar (0800)" ao lado do localizador (o toque longo copia). Só no pedido
 * dele, em andamento e antes de expirar (o servidor manda o telefone). Sem localizador: só o botão, da largura toda.
 */
export const LigarComLocalizador = ({ dados }: { dados: DadosIfood | null }) => {
    const theme = useTheme();
    const { t } = useLanguage();
    const [segurando, setSegurando] = useState(false);
    if (!ehIfood(dados) || !dados.telefone) return null;
    const telefone = dados.telefone;
    const localizador = formatarLocalizador(telefone.localizador);

    const ligar = async () => {
        try {
            await Linking.openURL(urlDoTelefone(telefone.numero));
        } catch (err) {
            console.warn('[ifood] erro ao ligar:', err);
            toast.error(t('PedidoIfood.erroAoLigar'));
        }
    };

    const aoSegurar = () => {
        if (!localizador) return;
        if (copiar(localizador.replace(/\s+/g, ''))) {
            toast.success(t('PedidoIfood.localizadorCopiado'));
        } else {
            toast.error(t('PedidoIfood.copiarFalhou'));
        }
    };

    return (
        <XStack gap='$2'>
            <Button flex={1} onPress={ligar} height={60} borderRadius='$5' bg='$textPrimary' borderWidth={0} pressStyle={{ opacity: 0.85 }}>
                <Button.Icon>
                    <FontAwesomeIcon icon={faPhone} color={theme['$background'].val} />
                </Button.Icon>
                <Button.Text color='$background' fontFamily='$heading' fontWeight='700' fontSize={19} numberOfLines={1}>
                    {localizador ? t('Comanda.ligar0800') : t('PedidoIfood.ligar')}
                </Button.Text>
            </Button>
            {!!localizador && (
                <Pressable
                    style={{ flex: 1 }}
                    onLongPress={aoSegurar}
                    onPressIn={() => setSegurando(true)}
                    onPressOut={() => setSegurando(false)}
                    delayLongPress={450}
                    accessibilityHint={t('PedidoIfood.copiarLocalizador')}
                >
                    <YStack
                        flex={1}
                        minHeight={60}
                        alignItems='center'
                        justifyContent='center'
                        px='$2'
                        borderRadius='$5'
                        borderWidth={1.5}
                        borderStyle='dashed'
                        borderColor={segurando ? '$primary' : '$borderColor'}
                        bg={segurando ? '$primarioSuave' : 'transparent'}
                    >
                        <Text color='$textSecondary' fontSize={11}>
                            {t('Comanda.localizador')}
                        </Text>
                        <Text color='$textPrimary' fontFamily='$heading' fontWeight='800' fontSize={24} lineHeight={28} letterSpacing={1.5} numberOfLines={1}>
                            {localizador}
                        </Text>
                    </YStack>
                </Pressable>
            )}
        </XStack>
    );
};

// Observações e complemento podem ser longos: sem limite de linhas e com seleção.
const TextoLongo = ({ titulo, texto }: { titulo: string; texto: string }) => (
```

(O `TextoLongo` e o resto do arquivo não mudam.) Tudo o que
o componente usa já está importado no arquivo: `useState`, `Linking`, `Pressable`, `Button`, `Text`, `XStack`,
`YStack`, `useTheme`, `FontAwesomeIcon`, `faPhone`, `useLanguage`, `toast`, `ehIfood`, `formatarLocalizador`,
`urlDoTelefone`, `copiar` (linhas 1 a 57). O `useState` vem antes do `return null`, como pede a regra dos hooks.

- [ ] **Passo 2: conferir**

```bash
bash /c/tmp/cp-tsc.sh
```

Esperado: `tsc: nenhum erro novo`.

- [ ] **Passo 3: commit**

```bash
cd /c/tmp/cp
git add src/components/PedidoIfood.tsx
git commit -m "iFood: Ligar (0800) ao lado do localizador para a porta (Comanda)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 8: `OrderScreen`, folha recolhida (loja e cliente)

**Arquivos:**
- Modificar: `src/screens/OrderScreen.tsx:7` (ícone), `:62` (imports), `:918` (dados da Comanda), `:1026-1030` (título) e
  `:1108-1124` (topo da folha)

Os números de linha são os da `main` em `7a6fea8`; confira pelo texto.

- [ ] **Passo 1: imports**

Troque (linha 7):

```tsx
import { faCrosshairs, faTimes, faPaperPlane, faPhone, faMessage, faListUl, faChevronDown, faList, faClock } from '@fortawesome/free-solid-svg-icons';
```

por:

```tsx
import { faCrosshairs, faTimes, faPaperPlane, faPhone, faMessage, faListUl, faChevronDown, faList, faClock, faBagShopping } from '@fortawesome/free-solid-svg-icons';
```

E troque (linha 62):

```tsx
import type { DadosDoCartao } from '../components/entregas/CartaoDeOferta';
```

por:

```tsx
import type { DadosDoCartao } from '../components/entregas/CartaoDeOferta';
import CobrancaEmUmaLinha from '../components/entregas/CobrancaEmUmaLinha';
import { contarItens, lerItens, nomeDoClienteDoPedido } from '../utils/comanda';
```

- [ ] **Passo 2: os dados da Comanda**

Troque (linha 918):

```tsx
    const destino = destinoDoPedido(order);
```

por:

```tsx
    const destino = destinoDoPedido(order);

    // ===== Entregas: Comanda do pedido (sketch 007 B) =====
    // Os produtos vêm no campo `itens` das rotas do iFood e da integração (servidor de 2026-10-19). Sem o campo, null ou
    // lista malformada: lista vazia, e os blocos de produtos somem (o app funciona com o servidor antigo).
    const itens = useMemo(() => lerItens((ifood ?? integracao)?.itens), [ifood, integracao]);
    // O nome do cliente (payload.dropoff.name em caixa normal; genérico ou igual à rua = null). Só na folha da corrida:
    // nunca antes do aceite (a oferta e o pedido aberto mostram só o bairro)
    const nomeDoCliente = nomeDoClienteDoPedido(order);
    const observacoesDoCliente = ifood?.observacoes ?? integracao?.observacoes ?? null;
    const referenciaDoCliente = ifood?.referencia ?? integracao?.referencia ?? null;
    const complementoDoCliente =
        [ifood?.complemento ?? integracao?.complemento ?? null, referenciaDoCliente ? t('PedidoIfood.referencia', { texto: referenciaDoCliente }) : null].filter(Boolean).join(' · ') ||
        null;
    // a cobrança na porta numa linha: o texto do servidor ou, sem nada a cobrar, "Pago online" (iFood) / "Já pago" (integração)
    const textoDaCobranca = ifood ? (temCobranca(ifood) ? ifood.cobranca.texto : null) : textoDaCobrancaDaIntegracao(integracao);
    const cobrancaDaPorta = ifood
        ? { texto: textoDaCobranca, textoPago: t('PedidoIfood.pagoOnline') }
        : integracao && (textoDaCobranca || integracao.cobranca?.forma === 'pago')
          ? { texto: textoDaCobranca, textoPago: t('PedidoDaIntegracao.pago') }
          : null;
    // o número do balcão no cupom: o selo (iFood ou o sistema de origem) e "#4821"
    const seloDoBalcao = seloIfood ? { variante: 'ifood' as const, texto: t('PedidoIfood.seloSemNumero') } : origem ? { variante: 'laranja' as const, texto: origem.sistema } : null;
    const numeroDoBalcao = seloIfood ? (numeroIfood ? `#${numeroIfood}` : null) : origem?.numero ? `#${origem.numero}` : null;
```

O `useMemo` fica junto dos outros (`faixaDaEntrega`, logo abaixo), sem `return` antes: a ordem dos hooks não muda. O
`ifood` e o `integracao` (linhas 900 e 903) são o próprio estado dos hooks ou `null`, então o `useMemo` só recalcula
quando a resposta muda. `observacoesDoCliente`, `complementoDoCliente`, `seloDoBalcao` e `numeroDoBalcao` passam a ser
usados nas Tarefas 9 e 10.

- [ ] **Passo 3: o título da folha**

Troque (linhas 1026 a 1028):

```tsx
    const naLoja = etapa === 'loja' || etapa === 'aguardando';
    const tituloDaFolha = naLoja ? (lojaDaColeta(order) ?? linhaDoEndereco(pickup)) : (destino.rua ?? linhaDoEndereco(order.getAttribute('payload.dropoff')));
    const detalheDaFolha = naLoja
```

por:

```tsx
    const naLoja = etapa === 'loja' || etapa === 'aguardando';
    const naPorta = etapa === 'cliente';
    const ruaDaEntrega = destino.rua ?? linhaDoEndereco(order.getAttribute('payload.dropoff'));
    // Comanda: a caminho do cliente, o título é o nome dele (sem nome, a rua, como antes) e a rua desce para a linha de baixo
    const tituloDaFolha = naLoja ? (lojaDaColeta(order) ?? linhaDoEndereco(pickup)) : (nomeDoCliente ?? ruaDaEntrega);
    const ruaDoCliente = !naLoja && nomeDoCliente ? ruaDaEntrega : null;
    // Comanda: na coleta, embaixo do nome da loja, "4 itens · iFood #4821" (sem produtos, só o selo)
    const origemNaColeta = seloIfood ?? seloOrigem ?? numeroComum;
    const detalheDaFolha = naLoja
```

(As duas linhas seguintes do `detalheDaFolha` não mudam.)

- [ ] **Passo 4: o topo da folha (recolhida)**

Troque (linhas 1108 a 1125):

```tsx
                            <YStack>
                                <Text color='$textSecondary' fontSize={12} fontWeight='800' textTransform='uppercase' letterSpacing={0.6}>
                                    {rotuloDaEtapa}
                                </Text>
                                <Text color='$textPrimary' fontFamily='$heading' fontWeight='800' fontSize={32} lineHeight={35} numberOfLines={2}>
                                    {tituloDaFolha ?? '—'}
                                </Text>
                                {!!detalheDaFolha && (
                                    <Text color='$textSecondary' fontSize={14} numberOfLines={2}>
                                        {detalheDaFolha}
                                    </Text>
                                )}
                            </YStack>
                            {etapa === 'cliente' && ifood ? (
                                <CobrancaDoIfood dados={ifood} />
                            ) : etapa === 'cliente' && integracao && (textoDaCobrancaDaIntegracao(integracao) || integracao.cobranca?.forma === 'pago') ? (
                                <CobrancaDaIntegracao dados={integracao} />
                            ) : (
```

por:

```tsx
                            <YStack>
                                <Text color='$textSecondary' fontSize={12} fontWeight='800' textTransform='uppercase' letterSpacing={0.6}>
                                    {rotuloDaEtapa}
                                </Text>
                                <Text color='$textPrimary' fontFamily='$heading' fontWeight='800' fontSize={32} lineHeight={35} numberOfLines={2}>
                                    {tituloDaFolha ?? '—'}
                                </Text>
                                {naLoja && (itens.length > 0 || !!origemNaColeta) && (
                                    <XStack alignItems='center' gap='$2' flexWrap='wrap' mt='$1'>
                                        {itens.length > 0 && (
                                            <XStack bg='$primarioSuave' borderRadius={9999} px='$2.5' py={3} gap='$1.5' alignItems='center'>
                                                <FontAwesomeIcon icon={faBagShopping} color={theme['$tintaPrimaria'].val} size={13} />
                                                <Text color='$tintaPrimaria' fontSize={14} fontWeight='700'>
                                                    {t('Comanda.itens', { count: contarItens(itens) })}
                                                </Text>
                                            </XStack>
                                        )}
                                        {!!origemNaColeta && (
                                            <Text color='$textPrimary' fontSize={14} fontWeight='700' numberOfLines={1}>
                                                {origemNaColeta}
                                            </Text>
                                        )}
                                    </XStack>
                                )}
                                {!!ruaDoCliente && (
                                    <Text color='$textPrimary' fontSize={15} fontWeight='700' numberOfLines={1}>
                                        {ruaDoCliente}
                                    </Text>
                                )}
                                {!!detalheDaFolha && (
                                    <Text color='$textSecondary' fontSize={14} numberOfLines={2}>
                                        {detalheDaFolha}
                                    </Text>
                                )}
                            </YStack>
                            {etapa === 'cliente' && cobrancaDaPorta ? (
                                <CobrancaEmUmaLinha texto={cobrancaDaPorta.texto} textoPago={cobrancaDaPorta.textoPago} />
                            ) : (
```

O resto do ternário (a caixa "Você recebe" com o valor, o km e a faixa) não muda. Na coleta, o endereço da loja
(`detalheDaFolha`) continua embaixo da linha "4 itens · iFood #4821". A altura recolhida já é medida pelo `onLayout`
desse bloco (linha 1106): as linhas novas entram na conta sozinhas.

- [ ] **Passo 5: conferir**

```bash
bash /c/tmp/cp-tsc.sh
grep -n "CobrancaDoIfood\|CobrancaDaIntegracao" /c/tmp/cp/src/screens/OrderScreen.tsx
```

Esperado: `tsc: nenhum erro novo`. O `grep` ainda mostra os imports (linhas 48 e 49) e os usos da folha aberta
(`{naLoja && <CobrancaDoIfood …` e `{naLoja && <CobrancaDaIntegracao …`), que saem na Tarefa 9.

- [ ] **Passo 6: commit**

```bash
cd /c/tmp/cp
git add src/screens/OrderScreen.tsx
git commit -m "Pedido: folha recolhida da Comanda (itens e selo na coleta, nome do cliente e cobrança numa linha a caminho)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 9: `OrderScreen`, folha aberta na loja (cupom)

**Arquivos:**
- Modificar: `src/screens/OrderScreen.tsx` (imports do `PedidoIfood`, do `PedidoDaIntegracao` e da `CartaoDeOferta`; o
  balcão e as cobranças da folha aberta)

- [ ] **Passo 1: imports**

Troque:

```tsx
import { CobrancaDoIfood, LigarParaOCliente, LocalizadorIfood, TextosDoIfood } from '../components/PedidoIfood';
import { CobrancaDaIntegracao, LigarParaOClienteDaIntegracao, TextosDaIntegracao } from '../components/PedidoDaIntegracao';
```

por:

```tsx
import { LigarParaOCliente, LocalizadorIfood, TextosDoIfood } from '../components/PedidoIfood';
import { LigarParaOClienteDaIntegracao, TextosDaIntegracao } from '../components/PedidoDaIntegracao';
```

E troque:

```tsx
import type { DadosDoCartao } from '../components/entregas/CartaoDeOferta';
```

por:

```tsx
import type { DadosDoCartao } from '../components/entregas/CartaoDeOferta';
import ComandaDoPedido from '../components/entregas/ComandaDoPedido';
```

- [ ] **Passo 2: o cupom no lugar do balcão**

Troque:

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
```

por:

```tsx
                            {naLoja && <ComandaDoPedido selo={seloDoBalcao} numero={numeroDoBalcao} cliente={nomeDoCliente} itens={itens} observacao={observacoesDoCliente} />}
                            {naLoja && cobrancaDaPorta && (
                                <YStack gap='$1.5'>
                                    <Text color='$textSecondary' fontSize={12} fontWeight='800' textTransform='uppercase' letterSpacing={0.6}>
                                        {t('Comanda.naPorta')}
                                    </Text>
                                    <CobrancaEmUmaLinha texto={cobrancaDaPorta.texto} textoPago={cobrancaDaPorta.textoPago} />
                                </YStack>
                            )}
```

E apague a linha (a cobrança da integração já está no bloco acima):

```tsx
                            {naLoja && <CobrancaDaIntegracao dados={integracao} />}
```

- [ ] **Passo 3: conferir**

```bash
bash /c/tmp/cp-tsc.sh
grep -n "CobrancaDoIfood\|CobrancaDaIntegracao\|OrderScreen.balcao" /c/tmp/cp/src/screens/OrderScreen.tsx
```

Esperado: `tsc: nenhum erro novo` e nenhuma linha do `grep`.

- [ ] **Passo 4: commit**

```bash
cd /c/tmp/cp
git add src/screens/OrderScreen.tsx
git commit -m "Pedido: na loja, a comanda com o número do balcão, o nome do cliente e os produtos para conferir

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 10: `OrderScreen`, folha aberta na porta (cartões curtos)

**Arquivos:**
- Modificar: `src/screens/OrderScreen.tsx` (imports; 0800, localizador e textos da folha aberta)

- [ ] **Passo 1: imports**

Troque:

```tsx
import { LigarParaOCliente, LocalizadorIfood, TextosDoIfood } from '../components/PedidoIfood';
import { LigarParaOClienteDaIntegracao, TextosDaIntegracao } from '../components/PedidoDaIntegracao';
```

por:

```tsx
import { LigarComLocalizador } from '../components/PedidoIfood';
import { LigarParaOClienteDaIntegracao } from '../components/PedidoDaIntegracao';
```

E troque:

```tsx
import ComandaDoPedido from '../components/entregas/ComandaDoPedido';
```

por:

```tsx
import ComandaDoPedido from '../components/entregas/ComandaDoPedido';
import ResumoDosItens from '../components/entregas/ResumoDosItens';
import AvisosDaPorta from '../components/entregas/AvisosDaPorta';
```

- [ ] **Passo 2: os cartões da porta**

Troque:

```tsx
                            <LigarParaOCliente dados={dadosIfood} />
                            <LocalizadorIfood dados={dadosIfood} />
                            <TextosDoIfood dados={dadosIfood} />
                            <LigarParaOClienteDaIntegracao dados={integracao} />
                            <TextosDaIntegracao dados={integracao} />
```

por:

```tsx
                            {naPorta && (ifood ? <LigarComLocalizador dados={ifood} /> : <LigarParaOClienteDaIntegracao dados={integracao} />)}
                            {naPorta && <AvisosDaPorta observacoes={observacoesDoCliente} complemento={complementoDoCliente} />}
                            {!naLoja && <ResumoDosItens variante='cupom' itens={itens} origem={origemNaColeta} />}
```

A ordem da porta fica: cobrança (na parte recolhida, Tarefa 8), Ligar com o localizador, observações em amarelo,
complemento e referência, produtos resumidos e, depois, o "Mais" (Tarefa 11). O `LigarComLocalizador` e o
`LigarParaOClienteDaIntegracao` somem sozinhos sem o telefone (o servidor só manda no pedido dele, em andamento). Na loja
(`naLoja`), nada disso aparece: o 0800 e o localizador saem da folha aberta da coleta.

- [ ] **Passo 3: conferir**

```bash
bash /c/tmp/cp-tsc.sh
grep -n "LocalizadorIfood\|TextosDoIfood\|TextosDaIntegracao\|<LigarParaOCliente " /c/tmp/cp/src/screens/OrderScreen.tsx
```

Esperado: `tsc: nenhum erro novo` e nenhuma linha do `grep`.

- [ ] **Passo 4: commit**

```bash
cd /c/tmp/cp
git add src/screens/OrderScreen.tsx
git commit -m "Pedido: na porta, Ligar (0800) com o localizador, observações, complemento e produtos resumidos

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 11: `OrderScreen`, o "Mais" e a saída dos blocos do Fleetbase

**Arquivos:**
- Modificar: `src/screens/OrderScreen.tsx` (imports, `customFieldKeys`, comentários, o fim da folha aberta)

- [ ] **Passo 1: imports que entram e saem**

Troque:

```tsx
import AvisosDaPorta from '../components/entregas/AvisosDaPorta';
```

por:

```tsx
import AvisosDaPorta from '../components/entregas/AvisosDaPorta';
import MaisDoPedido from '../components/entregas/MaisDoPedido';
```

Troque `import { Separator, Button, Text, YStack, XStack, useTheme } from 'tamagui';` por:

```tsx
import { Button, Text, YStack, XStack, useTheme } from 'tamagui';
```

Troque `import { formatMeters, formatWhatsAppTimestamp, smartHumanize } from '../utils/format';` por:

```tsx
import { formatMeters, formatWhatsAppTimestamp } from '../utils/format';
```

Troque `import { translateStatus, dateFnsLocaleOptions } from '../utils/localize';` por:

```tsx
import { translateStatus } from '../utils/localize';
```

E apague estas linhas inteiras:

```tsx
import { format as formatDate } from 'date-fns';
```

```tsx
import OrderPayloadEntities from '../components/OrderPayloadEntities';
```

```tsx
import OrderCommentThread from '../components/OrderCommentThread';
import OrderProofOfDelivery from '../components/OrderProofOfDelivery';
```

```tsx
import { SectionHeader, SectionInfoLine } from '../components/Content';
```

- [ ] **Passo 2: `customFieldKeys` sai**

Apague a linha (era a 144):

```tsx
    const customFieldKeys = order.getAttribute('custom_fields', []) ?? [];
```

- [ ] **Passo 3: o "Mais" no lugar do apoio e dos detalhes do Fleetbase**

Troque o comentário:

```tsx
                        {/* expandida: o que importa na porta, o valor, a loja, todos os status e os detalhes do Fleetbase */}
```

por:

```tsx
                        {/* expandida (Comanda, sketch 007 B): na loja, o cupom do balcão com os produtos para conferir e a
                            cobrança na porta; a caminho do cliente, cartões curtos (Ligar com o localizador, observações,
                            complemento e referência, produtos resumidos); no fim, o "Mais" recolhido (rota e paradas, destino
                            atual, loja, valor detalhado e todos os status). 0800 e localizador só nas etapas do cliente */}
```

Depois troque o trecho inteiro que vai do `<ValorDaEntrega …>` até o `</BottomSheetScrollView>` (inclui o "Mais detalhes
do pedido", a rota, as notas cruas, a comprovação, os itens do Fleetbase e os comentários):

```tsx
                            <ValorDaEntrega pedido={order.id} variante='detalhado' />
                            {customer && <OrderCustomerCard customer={customer} orderId={order.id} />}
                            {order.isInProgress && (
                                <Button height={50} borderRadius='$5' bg='transparent' borderWidth={1.5} borderColor='$borderColor' onPress={() => updateOrderActivity()} pressStyle={{ bg: '$surface' }}>
                                    <Button.Icon>
                                        <FontAwesomeIcon icon={faListUl} color={theme['$textPrimary'].val} />
                                    </Button.Icon>
                                    <Button.Text color='$textPrimary' fontWeight='700' fontSize={16}>
                                        {t('OrderScreen.verTodosOsStatus')}
                                    </Button.Text>
                                </Button>
                            )}
                            <CurrentDestinationSelect
                                destination={destination}
                                waypoints={waypointsInProgress}
                                onChange={setOrderDestination}
                                isLoading={isLoading('setOrderDestination')}
                                snapTo='80%'
                            />
                        </YStack>

                        <YStack mt='$4' mx={-16}>
                            <SectionHeader title={t('OrderScreen.maisDetalhes')} />
                            <YStack py='$2'>
                                <SectionInfoLine title={t('OrderScreen.trackingNumber')} value={order.getAttribute('tracking_number.tracking_number')} />
                                <Separator />
                                <SectionInfoLine title={t('OrderScreen.internalId')} value={order.getAttribute('internal_id')} />
                                <Separator />
                                <SectionInfoLine title={t('OrderScreen.id')} value={order.id} />
                                <Separator />
                                <SectionInfoLine
                                    title={t('OrderScreen.proofOfDelivery')}
                                    value={order.getAttribute('pod_required') ? translateStatus(order.getAttribute('pod_method')) : t('OrderScreen.notAvailable')}
                                />
                                <Separator />
                                <SectionInfoLine title={t('OrderScreen.type')} value={translateStatus(order.getAttribute('type'))} />
                                <Separator />
                                <SectionInfoLine
                                    title={t('OrderScreen.dateCreated')}
                                    value={formatDate(new Date(order.getAttribute('created_at')), t('OrderScreen.dateTimeFormat'), dateFnsLocaleOptions())}
                                />
                                <Separator />
                                <SectionInfoLine
                                    title={t('OrderScreen.dateScheduled')}
                                    value={order.getAttribute('scheduled_at') ? formatDate(new Date(order.getAttribute('scheduled_at')), t('OrderScreen.dateTimeFormat'), dateFnsLocaleOptions()) : '-'}
                                />
                                <Separator />
                                <SectionInfoLine
                                    title={t('OrderScreen.dateDispatched')}
                                    value={order.getAttribute('dispatched_at') ? formatDate(new Date(order.getAttribute('dispatched_at')), t('OrderScreen.dateTimeFormat'), dateFnsLocaleOptions()) : '-'}
                                />
                                {customFieldKeys.map((key, index) => (
                                    <YStack key={index}>
                                        <Separator />
                                        <SectionInfoLine title={smartHumanize(key)} value={order.getAttribute(key)} />
                                    </YStack>
                                ))}
                            </YStack>
                            <SectionHeader title={t('OrderScreen.orderRoute')} />
                            <YStack px='$3' py='$4'>
                                <OrderWaypointList order={order} />
                            </YStack>
                            <SectionHeader title={t('OrderScreen.orderNotes')} />
                            <YStack px='$3' py='$4'>
                                <Text color='$textPrimary'>{order.getAttribute('notes') || t('OrderScreen.notAvailable')}</Text>
                            </YStack>
                            <SectionHeader title={t('OrderScreen.orderProof')} />
                            <YStack>
                                <OrderProofOfDelivery order={order} />
                            </YStack>
                            <SectionHeader title={t('OrderScreen.orderPayload')} />
                            <YStack>
                                <OrderPayloadEntities order={order} onPress={({ entity, waypoint }) => navigation.navigate('Entity', { entity, waypoint })} />
                            </YStack>
                            <SectionHeader title={t('OrderScreen.orderComments')} />
                            <YStack px='$2' py='$4'>
                                <OrderCommentThread order={order} />
                            </YStack>
                        </YStack>
                    </BottomSheetScrollView>
```

por:

```tsx
                            <MaisDoPedido>
                                <OrderWaypointList order={order} />
                                <CurrentDestinationSelect
                                    destination={destination}
                                    waypoints={waypointsInProgress}
                                    onChange={setOrderDestination}
                                    isLoading={isLoading('setOrderDestination')}
                                    snapTo='80%'
                                />
                                {customer && <OrderCustomerCard customer={customer} orderId={order.id} />}
                                <ValorDaEntrega pedido={order.id} variante='detalhado' />
                                {order.isInProgress && (
                                    <Button
                                        height={50}
                                        borderRadius='$5'
                                        bg='transparent'
                                        borderWidth={1.5}
                                        borderColor='$borderColor'
                                        onPress={() => updateOrderActivity()}
                                        pressStyle={{ bg: '$surface' }}
                                    >
                                        <Button.Icon>
                                            <FontAwesomeIcon icon={faListUl} color={theme['$textPrimary'].val} />
                                        </Button.Icon>
                                        <Button.Text color='$textPrimary' fontWeight='700' fontSize={16}>
                                            {t('OrderScreen.verTodosOsStatus')}
                                        </Button.Text>
                                    </Button>
                                )}
                            </MaisDoPedido>
                        </YStack>
                    </BottomSheetScrollView>
```

A prova de entrega continua no fluxo (`require_pod` → `navigation.navigate('ProofOfDelivery', …)` no
`sendOrderActivityUpdate`, linha 604): só o bloco da folha sai. O `entitiesByDestination` (linha 278) já não era usado e
fica como está.

- [ ] **Passo 4: o comentário do topo da tela**

Troque (linhas 90 e 91):

```tsx
//   "deslize para confirmar" com a próxima atividade do servidor). A folha sobe com o resto (cobrança, 0800, localizador,
//   observações, valor, loja, todos os status e os detalhes do Fleetbase);
```

por:

```tsx
//   "deslize para confirmar" com a próxima atividade do servidor). Aberta, a folha vira a Comanda (sketch 007 B): na
//   loja, o cupom do balcão com o nome do cliente e os produtos para conferir; a caminho do cliente, o nome dele no título
//   e cartões curtos (Ligar com o localizador, observações, complemento, produtos); o apoio fica no "Mais" recolhido;
```

- [ ] **Passo 5: conferir**

```bash
bash /c/tmp/cp-tsc.sh
grep -nE "SectionHeader|SectionInfoLine|Separator|formatDate|smartHumanize|dateFnsLocaleOptions|OrderPayloadEntities|OrderCommentThread|OrderProofOfDelivery|customFieldKeys|maisDetalhes|orderNotes" /c/tmp/cp/src/screens/OrderScreen.tsx
```

Esperado: `tsc: nenhum erro novo` (e, no `/c/tmp/cp-tsc-agora.txt`, dois erros antigos a menos: o `children` do
`SectionHeader` e o `subject` do `OrderProofOfDelivery`). O `grep` não mostra nada.

- [ ] **Passo 6: commit**

```bash
cd /c/tmp/cp
git add src/screens/OrderScreen.tsx
git commit -m "Pedido: rota, destino, loja, valor e status no Mais; saem os detalhes, notas, comprovação, itens e comentários do Fleetbase

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 12: pedido aberto e oferta (comandinha com os produtos, sem o nome)

**Arquivos:**
- Modificar: `src/components/entregas/CartaoDeOferta.tsx:12`, `:23-26` (tipo) e `:140-141`
- Modificar: `src/components/entregas/CartaoDePedidoAberto.tsx:10` e `:73-74`
- Modificar: `src/components/AdhocOrderCard.tsx:8` e `:137-139`
- Modificar: `src/screens/OrderScreen.tsx` (`dadosDoCartao`, antes linha 952)

- [ ] **Passo 1: `DadosDoCartao` e o `CartaoDeOferta`**

Em `src/components/entregas/CartaoDeOferta.tsx`, troque:

```tsx
import CarregandoNoCartao from './CarregandoNoCartao';
```

por:

```tsx
import CarregandoNoCartao from './CarregandoNoCartao';
import ResumoDosItens from './ResumoDosItens';
import type { ItemDoPedido } from '../../utils/comanda';
```

Troque:

```tsx
    entrega: ParadaDoTrajeto;
};
```

por:

```tsx
    entrega: ParadaDoTrajeto;
    produtos: ItemDoPedido[]; // os produtos do pedido (Comanda); vazio = sem a comandinha
};
```

E troque:

```tsx
                <TrajetoDoPedido coleta={dados.coleta} entrega={dados.entrega} />
                {!!dados.cobranca && <CobrancaNaPorta texto={dados.cobranca} />}
```

por:

```tsx
                <TrajetoDoPedido coleta={dados.coleta} entrega={dados.entrega} />
                <ResumoDosItens variante='linha' itens={dados.produtos} />
                {!!dados.cobranca && <CobrancaNaPorta texto={dados.cobranca} />}
```

- [ ] **Passo 2: `CartaoDePedidoAberto`**

Em `src/components/entregas/CartaoDePedidoAberto.tsx`, troque:

```tsx
import CarregandoNoCartao from './CarregandoNoCartao';
```

por:

```tsx
import CarregandoNoCartao from './CarregandoNoCartao';
import ResumoDosItens from './ResumoDosItens';
```

E troque:

```tsx
                    <TrajetoDoPedido coleta={dados.coleta} entrega={dados.entrega} />
                    {!!dados.cobranca && <CobrancaNaPorta texto={dados.cobranca} />}
```

por:

```tsx
                    <TrajetoDoPedido coleta={dados.coleta} entrega={dados.entrega} />
                    <ResumoDosItens variante='linha' itens={dados.produtos} />
                    {!!dados.cobranca && <CobrancaNaPorta texto={dados.cobranca} />}
```

- [ ] **Passo 3: `AdhocOrderCard` (aba Pedidos)**

Em `src/components/AdhocOrderCard.tsx`, troque:

```tsx
import { origemDosDados, textoDaCobrancaDaIntegracao } from '../utils/pedido-de-origem';
```

por:

```tsx
import { ehIntegracao, origemDosDados, textoDaCobrancaDaIntegracao } from '../utils/pedido-de-origem';
import { lerItens } from '../utils/comanda';
```

E troque:

```tsx
            entrega: { titulo: destino.rua, detalhe: destino.bairro },
        };
    }, [order, valor,
```

por:

```tsx
            entrega: { titulo: destino.rua, detalhe: destino.bairro },
            // os produtos (Comanda): o servidor manda `itens` também no pedido aberto; sem eles, a comandinha some
            produtos: lerItens((ehIfood(dadosIfood) ? dadosIfood : ehIntegracao(dadosIntegracao) ? dadosIntegracao : null)?.itens),
        };
    }, [order, valor,
```

(O resto da linha das dependências do `useMemo` não muda: ela já tem `dadosIfood` e `dadosIntegracao`.)

- [ ] **Passo 4: a tela do pedido aberto (`OrderScreen`)**

Em `src/screens/OrderScreen.tsx`, troque:

```tsx
        entrega: { titulo: destino.rua, detalhe: destino.bairro },
    };
```

por:

```tsx
        entrega: { titulo: destino.rua, detalhe: destino.bairro },
        produtos: itens,
    };
```

O nome do cliente não entra nos cartões: o `DadosDoCartao` não tem campo para ele, e o D continua com a rua e o bairro.

- [ ] **Passo 5: conferir**

```bash
bash /c/tmp/cp-tsc.sh
grep -rn "DadosDoCartao = " /c/tmp/cp/src
```

Esperado: `tsc: nenhum erro novo` (se faltasse `produtos` em algum `DadosDoCartao`, o `tsc` acusaria `Property
'produtos' is missing`). O `grep` mostra os dois montadores (`AdhocOrderCard.tsx` e `OrderScreen.tsx`).

- [ ] **Passo 6: commit**

```bash
cd /c/tmp/cp
git add src/components/entregas/CartaoDeOferta.tsx src/components/entregas/CartaoDePedidoAberto.tsx src/components/AdhocOrderCard.tsx src/screens/OrderScreen.tsx
git commit -m "Pedido aberto e oferta: a comandinha com os produtos numa linha (sem o nome do cliente)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 13: traduções (grupo `Comanda`)

**Arquivos:**
- Modificar: `translations/pt.json` e `translations/en.json` (grupo novo `Comanda`, no fim)

Os textos que saíram da tela (`OrderScreen.maisDetalhes`, `orderNotes`, `orderProof`, `orderPayload`, `orderComments`,
`balcao`…) ficam nos arquivos: nada os usa mais, e nenhum teste exige apagá-los.

- [ ] **Passo 1: acrescentar o grupo**

O script reescreve os JSON com 4 espaços e CRLF, o mesmo formato de hoje (conferido: `JSON.stringify(…, null, 4)`
reproduz os arquivos exatamente).

```bash
cd /c/tmp/cp
node - <<'FIM'
const fs = require('fs');
const grupos = {
    pt: {
        mostreNoBalcao: 'Mostre no balcão',
        para: 'para',
        itens: { one: '{{count}} item', other: '{{count}} itens' },
        oQueVaiNaBag: 'o que vai na bag',
        conferidos: '{{marcados}} de {{total}} conferidos',
        tudoConferido: 'Tudo conferido',
        marcarConferido: 'Marcar {{nome}} como conferido',
        obsDoItem: 'Obs.: {{texto}}',
        obsDoCliente: 'Obs. do cliente:',
        naPorta: 'Na porta',
        produtos: 'Produtos',
        mais: 'Mais',
        maisTexto: 'Rota e paradas, loja, valor da entrega, status',
        ligar0800: 'Ligar (0800)',
        localizador: 'Localizador',
    },
    en: {
        mostreNoBalcao: 'Show at the counter',
        para: 'for',
        itens: { one: '{{count}} item', other: '{{count}} items' },
        oQueVaiNaBag: 'what goes in the bag',
        conferidos: '{{marcados}} of {{total}} checked',
        tudoConferido: 'All checked',
        marcarConferido: 'Mark {{nome}} as checked',
        obsDoItem: 'Note: {{texto}}',
        obsDoCliente: 'Customer note:',
        naPorta: 'At the door',
        produtos: 'Items',
        mais: 'More',
        maisTexto: 'Route and stops, store, delivery fee, status',
        ligar0800: 'Call (0800)',
        localizador: 'Locator',
    },
};
for (const [lingua, grupo] of Object.entries(grupos)) {
    const arquivo = `translations/${lingua}.json`;
    const json = JSON.parse(fs.readFileSync(arquivo, 'utf8'));
    json.Comanda = grupo;
    fs.writeFileSync(arquivo, JSON.stringify(json, null, 4).replace(/\n/g, '\r\n') + '\r\n');
}
console.log('ok');
FIM
git diff --stat translations
```

Esperado: `ok` e `translations/en.json | 20 ++++…` e `translations/pt.json | 20 ++++…` (só linhas acrescentadas). Se o
`git diff` mostrar linhas apagadas, o formato mudou: `git checkout translations` e acrescente o grupo à mão, antes do
último `}` de cada arquivo.

- [ ] **Passo 2: toda chave `Comanda.*` usada existe nas duas línguas**

```bash
cd /c/tmp/cp
node - <<'FIM'
const fs = require('fs');
const pt = JSON.parse(fs.readFileSync('translations/pt.json', 'utf8'));
const en = JSON.parse(fs.readFileSync('translations/en.json', 'utf8'));
const arquivos = ['src/screens/OrderScreen.tsx', 'src/components/PedidoIfood.tsx', ...fs.readdirSync('src/components/entregas').map((nome) => 'src/components/entregas/' + nome)];
const faltando = [];
let usos = 0;
for (const arquivo of arquivos) {
    const codigo = fs.readFileSync(arquivo, 'utf8');
    for (const [, chave] of codigo.matchAll(/t\('(Comanda\.[A-Za-z0-9]+)'/g)) {
        usos++;
        for (const [lingua, json] of [['pt', pt], ['en', en]]) {
            const valor = chave.split('.').reduce((atual, parte) => (atual == null ? undefined : atual[parte]), json);
            if (valor === undefined) faltando.push(`${lingua}: ${chave} (${arquivo})`);
        }
    }
}
const semUso = Object.keys(pt.Comanda).filter((chave) => !arquivos.some((arquivo) => fs.readFileSync(arquivo, 'utf8').includes(`'Comanda.${chave}'`)));
console.log(faltando.length ? 'FALTAM:\n' + faltando.join('\n') : `ok: ${usos} usos`, '| sem uso:', semUso.join(', ') || 'nenhuma');
FIM
```

Esperado: `ok: 18 usos | sem uso: nenhuma`.

- [ ] **Passo 3: commit**

```bash
cd /c/tmp/cp
git add translations/pt.json translations/en.json
git commit -m "Comanda: textos em pt e en

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 14: documentação

**Arquivos:**
- Modificar (na worktree, commitado): `src/components/entregas/README.md`
- Modificar (na pasta principal, **não commitar**: arquivos não rastreados): `entregas-navigator/CLAUDE.md`,
  `Delivery/api/app/Http/Controllers/Entregas/CLAUDE.md` e `Delivery/api/app/Support/Entregas/Ifood/CLAUDE.md`

- [ ] **Passo 1: README dos componentes**

Em `/c/tmp/cp/src/components/entregas/README.md`, troque:

```markdown
- `DesenhosDoSobre`: os desenhos do story "Como funciona" (central, oferta de 30 s, rodadas, voltas, encaixe, régua dos 5 min, recusa e checklist).
```

por:

```markdown
- `DesenhosDoSobre`: os desenhos do story "Como funciona" (central, oferta de 30 s, rodadas, voltas, encaixe, régua dos 5 min, recusa e checklist).
- Comanda do pedido (sketch 007 B, folha da `OrderScreen`): `ComandaDoPedido` (o cupom da loja: número do balcão, "para <cliente>" e os produtos com caixinhas de conferir, que só ficam no celular), `ListaDeItens` (linhas dos produtos, `conferir` ou `resumo`), `ResumoDosItens` (`linha`: a comandinha dos cartões de pedido aberto e de oferta; `cupom`: os produtos na porta), `CobrancaEmUmaLinha`, `AvisosDaPorta` (observações em amarelo, complemento e referência), `MaisDoPedido` (o apoio recolhido) e `LinhaTracejada`. Os produtos passam por `lerItens` (`src/utils/comanda.ts`); sem produtos, os blocos somem.
```

Commit:

```bash
cd /c/tmp/cp
git add src/components/entregas/README.md
git commit -m "Componentes: README com as peças da Comanda

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Passo 2: "App do motoboy" nas duas cópias**

As duas cópias têm o mesmo texto: `C:\Users\Edgardjr\Documents\vibe coding\entregas-navigator\CLAUDE.md` e
`C:\Users\Edgardjr\Documents\vibe coding\Delivery\api\app\Http\Controllers\Entregas\CLAUDE.md`. Faça as duas trocas nas
duas.

No item "**Aba Pedidos**", troque:

```markdown
Sem o seletor de data e sem o resumo do dia (o histórico fica no Início).
```

por:

```markdown
Sem o seletor de data e sem o resumo do dia (o histórico fica no Início). Nas duas seções, o mais antigo em cima (`created_at`, ordenado no app por `doMaisAntigo`, estável e sem data no fim; decisão de 2026-10-19); em Novos pedidos, a oferta dele continua no topo.
```

No item "**Detalhes do pedido no app**", troque o começo do subitem do iFood:

```markdown
  - Pedido do iFood: bloco "Pedido do iFood" (selo, cobrança, ligar, localizador) e a conclusão com o código de entrega
```

por:

```markdown
  - **Comanda** (decisão de 2026-10-19, sketch 007 B; spec `docs/superpowers/specs/2026-10-19-detalhe-do-pedido-app-design.md`, no Delivery; ramo `comanda-do-pedido` do app até o merge): recolhida, na coleta, "4 itens · iFood #4821" embaixo da loja; a caminho do cliente, o nome dele como título (`nomeDoClienteDoPedido`: `payload.dropoff.name` em caixa normal; "Cliente iFood", vazio ou igual à rua = sem nome, e o título volta a ser a rua) e a cobrança numa linha. Aberta: na loja, o cupom (`ComandaDoPedido`: número grande, "para Mariana Souza", produtos com caixinhas de conferir que não travam o deslizar, não vão ao servidor e zeram ao fechar a tela) e a cobrança; a caminho do cliente, "Ligar (0800)" ao lado do localizador, observações em amarelo, complemento e os produtos resumidos. 0800 e localizador só nas etapas do cliente. Rota e paradas, destino atual, loja, valor detalhado e "Ver todos os status" ficam no "Mais" recolhido. Saíram "Mais detalhes do pedido", "Observações do pedido" (notas cruas), "Comprovação do pedido", "Itens do pedido" e "Comentários do pedido" (a prova de entrega continua no fluxo). Pedido aberto e oferta: a comandinha com os produtos numa linha, sem o nome do cliente.
  - Produtos: campo `itens` das rotas `.../ifood` e `.../integracao` (`lerItens` em `src/utils/comanda.ts`; sem o campo, `null` ou malformado, os blocos somem e nada mostra "0 itens"). Testes: `scripts/testes/comanda.teste.ts` e `lista-de-pedidos.teste.ts`.
  - Pedido do iFood: selo, cobrança, 0800 e localizador nos lugares da Comanda, e a conclusão com o código de entrega
```

(O fim do subitem do iFood, a partir de ` (ver "Integração iFood (etapa 3: ciclo da entrega)"…`, não muda.)

- [ ] **Passo 3: a seção do app no `Ifood/CLAUDE.md`**

Em `C:\Users\Edgardjr\Documents\vibe coding\Delivery\api\app\Support\Entregas\Ifood\CLAUDE.md`, na seção "**App**",
troque:

```markdown
  - detalhes com "Ligar para o cliente" (0800 do iFood, só no pedido dele) e o localizador em letras grandes (toque longo copia), observações, complemento e referência sem corte, e o aviso do cancelamento pelo iFood;
```

por:

```markdown
  - detalhes na folha "Comanda" (decisão de 2026-10-19): a caminho do cliente, "Ligar (0800)" (só no pedido dele) ao lado do localizador (toque longo copia), observações em amarelo, complemento e referência sem corte; na coleta, o cupom do balcão com os produtos (`itens`) e a cobrança numa linha (sem 0800 nem localizador); o aviso do cancelamento pelo iFood continua;
```

- [ ] **Passo 4: conferir que nada disso foi para a worktree**

```bash
cd /c/tmp/cp && git status --short
cd "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && git status --short
```

Esperado: a worktree limpa; na pasta principal do app, só `?? CLAUDE.md`.

---

### Tarefa 15: verificação final e build de teste do ramo

**Arquivos:** nenhum.

- [ ] **Passo 1: todos os testes do app**

```bash
cd /c/tmp/cp
node --experimental-strip-types --test scripts/testes/*.teste.ts 2>&1 | grep -E "^# (tests|pass|fail)"
```

Esperado: `# fail 0`.

- [ ] **Passo 2: `tsc` sem erro novo**

```bash
bash /c/tmp/cp-tsc.sh
comm -23 /c/tmp/cp-tsc-antes.txt /c/tmp/cp-tsc-agora.txt
```

Esperado: `tsc: nenhum erro novo`; o `comm -23` lista só os erros que sumiram (os do `SectionHeader` e do
`OrderProofOfDelivery`).

- [ ] **Passo 3: o que saiu, saiu**

```bash
cd /c/tmp/cp
grep -rnE "comOfertaPrimeiro|OrderPayloadEntities|OrderCommentThread|OrderProofOfDelivery|SectionInfoLine|OrderScreen\.(maisDetalhes|orderNotes|orderProof|orderPayload|orderComments|balcao)" src/screens/OrderScreen.tsx src/utils scripts/testes
git status --short
git log --oneline main..HEAD
```

Esperado: nenhuma linha do `grep`, worktree limpa e 14 commits no ramo (um por tarefa, da 1 à 14).

- [ ] **Passo 4: subir o ramo e disparar o build de teste**

O `workflow_dispatch` precisa do ramo no GitHub. Push de ramo **não** dispara build (o `push` do `build-apk.yml` é só na
`main`) e o build de ramo **não** publica no MinIO (`if: github.ref == 'refs/heads/main'`, linha 139): a versão mínima
não muda.

```bash
cd /c/tmp/cp
git push -u origin comanda-do-pedido
gh workflow run build-apk.yml --ref comanda-do-pedido
gh run list --workflow build-apk.yml --branch comanda-do-pedido --limit 1
```

Esperado: o run aparece como `queued` ou `in_progress`. Anote o id (primeira coluna numérica) e o número do run (o
`versionCode` do APK é o `github.run_number`). O build leva de 30 a 60 min; acompanhe com
`gh run watch <id> --exit-status` em segundo plano.

- [ ] **Passo 5: baixar o APK**

```bash
gh run download <id> --dir /c/tmp/apk-comanda
ls /c/tmp/apk-comanda/*
```

Esperado: `entregas-motoboy-<n>.apk` dentro de `/c/tmp/apk-comanda/entregas-motoboy-<n>/`.

- [ ] **Passo 6: teste no celular (Edgard, por adb)**

```bash
adb devices
adb install -r /c/tmp/apk-comanda/entregas-motoboy-<n>/entregas-motoboy-<n>.apk
```

Com o servidor de hoje (sem `itens`), conferir:
- aba Pedidos: em "Novos pedidos" e em "Em andamento", o mais antigo em cima; a oferta que toca continua no topo;
- pedido aberto e oferta: sem a comandinha (não há produtos), sem nome, sem "0 itens";
- indo à loja (recolhida): o selo ("iFood #4821" ou "RestaurantePro #5530") embaixo da loja; aberta: "Mostre no balcão",
  o número grande e "para <nome>" (pedido com nome), a cobrança numa linha embaixo de "Na porta", **sem 0800 e sem
  localizador**, e o "Mais" fechado;
- a caminho do cliente (recolhida): o nome como título, a rua e o bairro embaixo, a cobrança numa linha; sem nome
  ("Cliente iFood"), a rua como título; aberta: "Ligar (0800)" com o localizador (toque longo copia), observação em
  amarelo, complemento; o "Mais" abre rota, destino atual, loja, valor e "Ver todos os status";
- os dois temas (Rua clara e Rua à noite).

Depois do servidor dos produtos (`2026-10-19-produtos-do-pedido-servidor-design.md`) no ar, repetir com um pedido de
teste do Terraço com produtos: a comandinha nos cartões, "4 itens" na coleta, as caixinhas (marcar, "Tudo conferido",
fechar e abrir a tela zera) e o cupom resumido na porta.

- [ ] **Passo 7: parar aqui**

**Não faça merge na `main`.** Cada build da `main` vira versão mínima e trava os motoboys até instalarem o APK novo. O
merge (`git checkout main && git merge --no-ff comanda-do-pedido` na pasta principal, depois `git push`) é só com o
Edgard, depois do teste no celular. Para remover a worktree depois do merge, **primeiro** a junção:
`cmd //c rmdir 'C:\tmp\cp\node_modules'`, e só então `git worktree remove /c/tmp/cp`.

---

## Pontos para o Edgard

1. **"rótulo 'Cliente'" sem nome:** o plano mantém o rótulo da etapa ("A caminho do cliente") e põe a rua no título,
   como hoje. Se a ideia era um rótulo escrito "Cliente" (acima da rua, no lugar do da etapa), é uma troca de uma linha no
   `rotuloDaEtapa` da `OrderScreen` e uma chave nova.
2. **Rua antes do aceite:** o sketch B mostra só o bairro no D do pedido aberto ("o endereço completo aparece depois de
   aceitar"); a spec só pede para acrescentar o bairro, que já está lá. O plano mantém a rua. Esconder a rua antes do
   aceite é uma troca no `entrega` do `DadosDoCartao` (`AdhocOrderCard` e `OrderScreen`).
3. **Nomes genéricos:** só "Cliente iFood", vazio e igual à rua/endereço. Se o RestaurantePro mandar outro texto quando o
   cliente não tem nome (ex.: "Cliente"), basta incluí-lo em `GENERICOS` (`src/utils/comanda.ts`).
4. **Linha dos produtos no cartão:** até 2 linhas (a spec diz "numa linha"; uma linha só cortaria logo no segundo
   produto num celular comum).
