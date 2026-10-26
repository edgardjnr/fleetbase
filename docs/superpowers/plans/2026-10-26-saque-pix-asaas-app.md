# Saque Pix pelo Asaas — Plano 3: app do motoboy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** no app do motoboy (`entregas-navigator`), a "Minha carteira" ganha o botão "Sacar via Pix", que leva à tela Sacar (valor, "Tudo", o que cai no Pix, a taxa, a chave Pix escondida e o desbloqueio do aparelho) e ao acompanhamento do saque até o dinheiro cair, com os pushes do saque e o "Saque Pix" no extrato e no recibo.

**Architecture:** regras puras novas em `src/utils/saque.ts` (sem imports, testadas com o Node puro), duas telas novas na pilha do Início (`SacarScreen` e `AcompanharSaqueScreen`), um módulo nativo Kotlin pequeno (`DesbloqueioDoAparelho`, `BiometricPrompt` do `androidx.biometric` com digital, rosto ou a senha de tela) e mudanças pontuais em `FolhaDaCarteira`, `MeusGanhosScreen`, `ReciboScreen`, `DriverLayout`, `DriverNavigator` e `extrato.ts`. O app só consome o contrato do Plano 1; o servidor confere tudo de novo.

**Tech Stack:** React Native 0.86 + Tamagui + React Navigation 7, `@fleetbase/sdk` (adapter `get`/`post`), `react-native-get-random-values` (já carregado no `index.native.tsx`), `react-native-event-listeners`, Kotlin + `androidx.biometric:biometric:1.1.0`, testes com `node --experimental-strip-types --test`.

**Spec:** `docs/superpowers/specs/2026-10-26-saque-pix-asaas-design.md` (seção "Telas → App"). **Contrato:** `docs/superpowers/plans/2026-10-26-saque-pix-asaas-api.md` (Plano 1: Tasks 5, 7, 11 e 12). O Plano 2 é o console.

---

## Onde trabalhar

O app está em `C:\Users\Edgardjr\Documents\vibe coding\entregas-navigator` (`N:` abaixo), na `main`, com um `CLAUDE.md`
**não rastreado** do Edgard: não o commite nem o apague. O Delivery (`D:`) é `C:\Users\Edgardjr\Documents\vibe coding\Delivery`.
**Não toque na worktree `C:\tmp\saque`**: é a do Plano 1 (outro agente).

- **Código:** worktree `/c/tmp/saque-app`, ramo `saque-pix`, criada na Tarefa 0 a partir da `main` do app. Os comandos
  usam o Git Bash; os da junção, o PowerShell.
- **Testes do app:** `node --experimental-strip-types --test scripts/testes/<arquivo>.teste.ts` (na raiz da worktree). O
  aviso `ExperimentalWarning: Type Stripping is an experimental feature` é normal; o resultado sai em `# pass N` e `# fail N`.
- **Funções em `src/utils/*.ts` testadas pelo Node não importam valor nenhum** (nem umas das outras): o Node exige o `.ts`
  no import e o `tsc` recusa. Por isso `saque.ts` recebe o `formatarReais` por parâmetro, como o `extrato.ts`. As telas
  importam os utils sem extensão.
- **`tsc`:** o `tsconfig.json` do app não liga o JSX; o `npx tsc --noEmit -p .` puro despeja milhares de erros falsos. Use
  sempre `/c/tmp/sa-tsc.sh` (Tarefa 0), que compara com a base tirada antes de mexer: **nenhum erro novo**.
- **Fim de linha:** o repo tem `core.autocrlf=true`. A ferramenta Edit lida com isso; num script, trate `\r\n`.
- **Kotlin:** só compila no GitHub Actions (não há SDK do Android no PC). A Tarefa 14 dispara o build do ramo
  (`workflow_dispatch`), que **não** publica no MinIO nem mexe na versão mínima.
- **Commits:** um por tarefa, na worktree, terminando com a linha `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
  Antes de cada commit, `git rev-parse --show-toplevel` tem de dar `C:/tmp/saque-app` (a home `C:\Users\Edgardjr` também é
  um repo git).
- **Merge na `main` do app e push: só com o OK do Edgard e depois do deploy da API** (Tarefa 15). Cada build da `main` vira
  versão mínima (trava "Atualize o app"): todos os motoboys ficam travados até instalar o APK novo.

## Mapa dos arquivos

| Arquivo (no `entregas-navigator`) | O que muda |
|---|---|
| `src/utils/saque.ts` (novo) | Tipos do saque e do bloco da carteira; leitura (`lerSaque`, `lerBlocoDoSaque`); botão da carteira; campo do valor, conferência, resumo; chave escondida; uuid do envio; mensagem da falha; resultado do desbloqueio; acompanhamento; situação; link do comprovante; pushes |
| `scripts/testes/saque.teste.ts` (novo) | Testes do `saque.ts` (17) |
| `src/utils/extrato.ts` | `MarcaDoSaque` no pagamento e no recibo, `saque` na `Carteira`, "Saque Pix" na `descricao`, taxa do saque fora do `ganhosDoPeriodo` |
| `scripts/testes/extrato.teste.ts` | Dois testes novos |
| `translations/pt.json`, `translations/en.json` | Grupo `Saque`, `Extrato.tipos.saquePix` e chaves novas em `Recibo` |
| `android/app/src/main/java/io/fleetbase/navigator/DesbloqueioDoAparelhoModule.kt` (novo) | `estaProtegido` e `pedir` (BiometricPrompt) |
| `android/app/src/main/java/io/fleetbase/navigator/AlertaPedidoPackage.kt` | Registra o módulo novo |
| `android/app/build.gradle` | `androidx.biometric:biometric:1.1.0` |
| `src/utils/desbloqueio-do-aparelho.ts` (novo) | Ponte JS do módulo nativo e "Abrir configurações" |
| `src/screens/SacarScreen.tsx` (novo) | Tela Sacar |
| `src/screens/AcompanharSaqueScreen.tsx` (novo) | Tela de acompanhamento |
| `src/navigation/DriverNavigator.tsx` | Rotas `Sacar` e `AcompanharSaque` na pilha do Início, sem cabeçalho; Sacar sem a barra de abas |
| `src/components/entregas/FolhaDaCarteira.tsx` | Botão "Sacar via Pix" / "Ver saque em andamento" / apagado com o motivo |
| `src/screens/MeusGanhosScreen.tsx` | Abre as telas do saque pela folha; abre a carteira pelo push da chave Pix; selo e ícone do saque no extrato |
| `src/layouts/DriverLayout.tsx` | Pushes `entregas_saque` e `entregas_pix_alterado` |
| `src/screens/ReciboScreen.tsx` | Recibo do saque: título, forma, situação e comprovante do Asaas |

Documentação (Tarefa 13): `N:/CLAUDE.md` (não rastreado, sem commit) e, no Delivery, `api/app/Http/Controllers/Entregas/CLAUDE.md`
(a mesma seção "App do motoboy"), `api/app/Support/Entregas/Saques/CLAUDE.md` (criado pelo Plano 1) e `docs/historico.md`.

## Decisões que o spec não dizia

- **Biblioteca do desbloqueio: módulo nativo próprio com `androidx.biometric`, não o `react-native-keychain`.** O
  `react-native-keychain` 10.0.0 instalado (nenhum código do app o usa hoje) só aceita o PIN/padrão/senha do celular a partir
  do **Android 11**: o `isPasscodeAuthAvailable` dele é `canAuthenticate(DEVICE_CREDENTIAL)`, que devolve falso abaixo da
  API 30, e sem ele o `getPromptInfo` pede só biometria forte (`DeviceAvailability.kt` e `KeychainModule.kt`). O app tem
  `minSdkVersion 24` (Android 7): num Android 8–10 com só PIN, o motoboy levaria um "Ative a senha de tela" errado. Além
  disso, ele só aceita biometria **forte** (o desbloqueio por rosto da maioria dos Android é classe fraca e não apareceria)
  e amarra a confirmação a uma chave do Keystore com validade de 5 s (`setUserAuthenticationParameters(5, …)`): um
  desbloqueio da tela nos últimos 5 s autoriza sem perguntar nada, e trocar a digital invalida a chave. O saque precisa só
  de "o dono está com o celular" (o servidor não confere nada disso; ameaça residual aceita no spec). O módulo novo usa
  `KeyguardManager.isDeviceSecure` (API 23+) para "tem bloqueio de tela?" e o `BiometricPrompt` com
  `BIOMETRIC_WEAK | DEVICE_CREDENTIAL`, combinação que o `androidx.biometric` 1.1.0 aceita em todas as versões (antes do
  Android 11 ele cai na confirmação de credencial do sistema). A dependência é a mesma 1.1.0 que o `react-native-keychain`
  já põe no APK; só é declarada no app porque a dele não é transitiva. Custo: ~70 linhas de Kotlin, compiladas só no
  Actions (o build do ramo, Tarefa 14, confere antes do merge).
- **Saque em andamento:** o botão vira "Ver saque em andamento" e abre o acompanhamento (o spec só fala em "apagado com o
  motivo"; aqui o motivo seria "Saque em andamento.", e o atalho é mais útil). Os outros motivos do servidor deixam o botão
  apagado com o texto embaixo.
- **A tela Sacar relê a carteira ao abrir** (o disponível muda a cada corrida e a carteira pode estar aberta há minutos). Se
  já houver saque em andamento, ela se troca pelo acompanhamento.
- **Campo do valor como nos apps de banco:** só os dígitos contam, da direita para a esquerda (digitar 5, 0, 0, 0 dá
  R$ 50,00); "Tudo" = o `disponivel` do servidor (que já desconta a taxa e o limite do dia).
- **Reaproveitamento do uuid:** o uuid do envio fica guardado na tela até o servidor responder com o saque; **qualquer**
  falha (sem rede, 5xx, 422) mantém o mesmo uuid para o próximo "Confirmar". Depois de um 422 isso é inofensivo (o servidor
  não gravou nada e confere tudo de novo); depois de uma queda de rede é o que impede o saque em dobro (o servidor devolve
  o saque já criado, 200). Se o motoboy mudar o valor depois de uma queda e o primeiro pedido tiver chegado, o servidor
  devolve o primeiro saque e a tela de acompanhamento mostra o valor dele. Sair da tela esquece o uuid (o servidor ainda
  segura: um saque em andamento por vez).
- **Mensagem de falha:** o SDK só repassa `errors[0]` (sem o status HTTP). Mensagens de transporte (sem rede, resposta que
  não é JSON, 429/5xx com o texto padrão) viram "O servidor não respondeu. Toque em Confirmar de novo: o pedido é o mesmo e o
  saque não sai duas vezes."; o resto é a mensagem pt-BR do servidor.
- **Taxa do saque fora dos "Ganhos · período":** o ajuste "Taxa do saque Pix" e a devolução dela ("Taxa do saque
  devolvida") são do recebimento, não do trabalho; ficam de fora como os pagamentos (`ganhosDoPeriodo`).
- **Push `entregas_pix_alterado` tocado:** abre o Início com a Minha carteira aberta (a chave nova aparece no cartão).
  `entregas_saque` tocado abre o acompanhamento daquele saque; com o app na frente, a tela de acompanhamento aberta relê na
  hora (evento `entregas.saque`).
- **Link do comprovante do Asaas:** só `https://` abre (`linkDoComprovante`); outro esquema vindo do servidor é ignorado.
- **A lista "Últimos pagamentos" da carteira mostra o saque como "Pix":** a rota `carteira` do Plano 1 não marca o saque
  nessa lista (só o extrato e o recibo). O toque abre o recibo, que mostra "Recibo do saque".

---

### Tarefa 0: worktree, junção do `node_modules` e base do `tsc` e dos testes

**Arquivos:** nenhum no repositório. Cria `/c/tmp/saque-app` (worktree) e `/c/tmp/sa-tsc.sh` (fora do repositório).

- [ ] **Passo 1: conferir a pasta principal**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator"
git rev-parse --show-toplevel
git status --short
git log --oneline -1
ls /c/tmp/saque-app 2>&1 | head -1
ls /c/tmp/sa-tsc.sh 2>&1 | head -1
```

Esperado: `C:/Users/Edgardjr/Documents/vibe coding/entregas-navigator`, só `?? CLAUDE.md` no status, a `main` em
`48c29d6 Mapa: nome da loja na segunda linha do rótulo do alfinete` (ou mais nova), e
`ls: cannot access '/c/tmp/saque-app'` e `ls: cannot access '/c/tmp/sa-tsc.sh'`. Se `/c/tmp/saque-app` existir, pare e
pergunte.

- [ ] **Passo 2: criar a worktree**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator"
git worktree add /c/tmp/saque-app -b saque-pix main
```

Esperado: `Preparing worktree (new branch 'saque-pix')`.

- [ ] **Passo 3: junção do `node_modules`** (PowerShell)

```powershell
New-Item -ItemType Junction -Path "C:\tmp\saque-app\node_modules" -Target "C:\Users\Edgardjr\Documents\vibe coding\entregas-navigator\node_modules" | Select-Object -ExpandProperty FullName
```

Esperado: `C:\tmp\saque-app\node_modules`. O `node_modules/` está no `.gitignore`. **Antes de remover a worktree**
(Tarefa 15), apague a junção com `cmd //c rmdir 'C:\tmp\saque-app\node_modules'`: remover a pasta com a junção dentro pode
apagar o `node_modules` da pasta principal.

- [ ] **Passo 4: o script do `tsc` e a base**

```bash
cat > /c/tmp/sa-tsc.sh <<'FIM'
#!/usr/bin/env bash
# Compara os erros do tsc (sem números de linha) com a base. Uso: bash /c/tmp/sa-tsc.sh antes | bash /c/tmp/sa-tsc.sh
cd /c/tmp/saque-app || exit 1
npx tsc --noEmit -p . --jsx react-native --esModuleInterop --allowImportingTsExtensions --skipLibCheck 2>&1 | grep "error TS" | sed -E 's/\([0-9]+,[0-9]+\)//' | sort -u > /c/tmp/sa-tsc-agora.txt
if [ "$1" = "antes" ]; then
    cp /c/tmp/sa-tsc-agora.txt /c/tmp/sa-tsc-antes.txt
    echo "base: $(wc -l < /c/tmp/sa-tsc-antes.txt) erros distintos"
    exit 0
fi
NOVOS=$(comm -13 /c/tmp/sa-tsc-antes.txt /c/tmp/sa-tsc-agora.txt)
if [ -z "$NOVOS" ]; then
    echo "tsc: nenhum erro novo"
else
    echo "tsc: ERROS NOVOS:"
    echo "$NOVOS"
    exit 1
fi
FIM
bash /c/tmp/sa-tsc.sh antes
```

Esperado: `base: N erros distintos` (algumas centenas; o que vale é a comparação depois). Leva de 5 s a 1 min.

- [ ] **Passo 5: os testes de hoje passam**

```bash
cd /c/tmp/saque-app
node --experimental-strip-types --test scripts/testes/*.teste.ts 2>&1 | grep -E "^# (tests|pass|fail)"
```

Esperado: `# tests 185`, `# pass 185` e `# fail 0`.

---

### Tarefa 1: regras do saque — leitura e botão da carteira

**Arquivos:**
- Criar: `src/utils/saque.ts`
- Criar: `scripts/testes/saque.teste.ts`

- [ ] **Passo 1: escrever o teste que falha**

Crie `scripts/testes/saque.teste.ts`:

```ts
// Testes das regras do saque Pix pelo app (src/utils/saque.ts), com o Node puro:
//   node --experimental-strip-types --test scripts/testes/saque.teste.ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { botaoDoSaque, destinoDoBotaoDoSaque, lerBlocoDoSaque, lerSaque } from '../../src/utils/saque.ts';
import type { BlocoDoSaque, SaqueDoApp } from '../../src/utils/saque.ts';

const U1 = 'aaaaaaaa-0000-4000-8000-000000000001';

// saque no formato de Saques::publico (a chave Pix já vem escondida pelo servidor)
const saque = (campos: Partial<SaqueDoApp> = {}): SaqueDoApp => ({
    id: U1,
    valor: 50,
    taxa: 0,
    situacao: 'processando',
    motivo: null,
    chave: '*******4321',
    tipo_chave: 'telefone',
    comprovante_url: null,
    criado_em: '2026-10-27T10:00:00-03:00',
    concluido_em: null,
    ...campos,
});

// bloco `saque` da carteira (Saques::situacaoDoMotoboy), com os padrões da central
const bloco = (campos: Partial<BlocoDoSaque> = {}): BlocoDoSaque => ({
    disponivel: 100,
    minimo: 10,
    maximo_hoje: 500,
    taxa: 0,
    em_andamento: null,
    indisponivel_motivo: null,
    ...campos,
});

test('lê o saque da API e recusa o que está fora do formato', () => {
    assert.deepEqual(lerSaque(saque()), saque());
    assert.deepEqual(lerSaque({ ...saque(), motivo: '', comprovante_url: '  ' }), saque(), 'texto vazio vira null');
    assert.deepEqual(lerSaque({ id: U1, valor: 50, situacao: 'enviando' }), saque({ situacao: 'enviando', chave: '', tipo_chave: '', criado_em: null }), 'sem taxa: 0; sem chave: vazia');
    assert.equal(lerSaque({ ...saque(), valor: '50' }), null, 'valor em texto: fora do formato');
    assert.equal(lerSaque({ ...saque(), id: '' }), null);
    assert.equal(lerSaque({ ...saque(), situacao: null }), null);
    assert.equal(lerSaque(null), null);
    assert.equal(lerSaque('saque'), null);
});

test('lê o bloco do saque da carteira', () => {
    assert.deepEqual(lerBlocoDoSaque(bloco()), bloco());
    const comAndamento = bloco({ em_andamento: saque(), indisponivel_motivo: 'Saque em andamento.' });
    assert.deepEqual(lerBlocoDoSaque(comAndamento), comAndamento);
    assert.deepEqual(lerBlocoDoSaque({ ...bloco(), em_andamento: { id: U1 } }), bloco(), 'saque em andamento fora do formato: null');
    assert.deepEqual(lerBlocoDoSaque({ ...bloco(), indisponivel_motivo: '' }), bloco());
    assert.equal(lerBlocoDoSaque(undefined), null, 'sem o bloco: central sem saque ligado ou API antiga');
    assert.equal(lerBlocoDoSaque({ ...bloco(), disponivel: '100' }), null, 'dinheiro em texto: fora do formato');
    const semTaxa: Record<string, unknown> = { ...bloco() };
    delete semTaxa.taxa;
    assert.equal(lerBlocoDoSaque(semTaxa), null);
});

test('botão da Minha carteira', () => {
    assert.deepEqual(botaoDoSaque(null), { modo: 'escondido' });
    assert.deepEqual(botaoDoSaque(bloco()), { modo: 'sacar' });
    assert.deepEqual(botaoDoSaque(bloco({ disponivel: 10 })), { modo: 'sacar' }, 'igual ao mínimo pode');
    assert.deepEqual(botaoDoSaque(bloco({ em_andamento: saque(), indisponivel_motivo: 'Saque em andamento.' })), { modo: 'acompanhar', id: U1 });
    assert.deepEqual(botaoDoSaque(bloco({ indisponivel_motivo: 'Limite de saques de hoje atingido.' })), { modo: 'indisponivel', motivo: 'Limite de saques de hoje atingido.' });
    assert.deepEqual(botaoDoSaque(bloco({ disponivel: 9.99 })), { modo: 'indisponivel', motivo: null }, 'abaixo do mínimo sem motivo do servidor');
    assert.deepEqual(botaoDoSaque(bloco({ disponivel: 0, minimo: 0 })), { modo: 'indisponivel', motivo: null }, 'nada para sacar');
});

test('destino do botão da carteira', () => {
    assert.deepEqual(destinoDoBotaoDoSaque({ modo: 'sacar' }), { tela: 'Sacar' });
    assert.deepEqual(destinoDoBotaoDoSaque({ modo: 'acompanhar', id: U1 }), { tela: 'AcompanharSaque', id: U1 });
    assert.equal(destinoDoBotaoDoSaque({ modo: 'indisponivel', motivo: null }), null);
    assert.equal(destinoDoBotaoDoSaque({ modo: 'escondido' }), null);
});
```

- [ ] **Passo 2: rodar e ver falhar**

```bash
cd /c/tmp/saque-app && node --experimental-strip-types --test scripts/testes/saque.teste.ts 2>&1 | tail -5
```

Esperado: erro `Cannot find module '.../src/utils/saque.ts'`.

- [ ] **Passo 3: implementar**

Crie `src/utils/saque.ts`:

```ts
// Entregas: regras puras do saque Pix do motoboy pelo app, pela conta Asaas da central (spec
// docs/superpowers/specs/2026-10-26-saque-pix-asaas-design.md, no Delivery). Formatos do servidor: o bloco `saque` da
// GET v1/entregas/motoboy/carteira (Saques::situacaoDoMotoboy) e o saque de POST v1/entregas/motoboy/saques e
// GET v1/entregas/motoboy/saques/{uuid} (Saques::publico, com a chave Pix já escondida).
// Sem imports, para os testes rodarem com o Node puro: node --experimental-strip-types --test scripts/testes/saque.teste.ts
// A formatação de reais é a de src/utils/ganhos.ts: quem chama passa a função (formatarReais).

export type SituacaoDoSaque = 'enviando' | 'processando' | 'concluido' | 'falhou';

/** O saque como a API manda (Saques::publico). `chave` já vem escondida ("*******4321"). */
export type SaqueDoApp = {
    id: string;
    valor: number;
    taxa: number;
    situacao: SituacaoDoSaque | string;
    motivo: string | null;
    chave: string;
    tipo_chave: string;
    comprovante_url: string | null;
    criado_em: string | null;
    concluido_em: string | null;
};

/** O bloco `saque` da carteira: só vem quando a central liberou o saque (sem ele, o app esconde o botão). */
export type BlocoDoSaque = {
    /** Já desconta a taxa e o que resta do limite do dia. */
    disponivel: number;
    minimo: number;
    maximo_hoje: number;
    taxa: number;
    em_andamento: SaqueDoApp | null;
    indisponivel_motivo: string | null;
};

export type BotaoDoSaque = { modo: 'escondido' } | { modo: 'sacar' } | { modo: 'acompanhar'; id: string } | { modo: 'indisponivel'; motivo: string | null };

/** Para onde o botão da carteira leva (as rotas da pilha do Início). */
export type DestinoDoSaque = { tela: 'Sacar' } | { tela: 'AcompanharSaque'; id: string };

const numero = (valor: unknown): number | null => (typeof valor === 'number' && Number.isFinite(valor) ? valor : null);
const textoOuNulo = (valor: unknown): string | null => (typeof valor === 'string' && valor.trim() !== '' ? valor.trim() : null);

/** Reais em centavos inteiros (as comparações de dinheiro são sempre em centavos). */
export const centavos = (valor: number): number => Math.round(Number(valor) * 100) || 0;

/** O saque da resposta da API, ou null fora do formato (dinheiro: melhor um erro que um valor errado na tela). */
export function lerSaque(dados: unknown): SaqueDoApp | null {
    if (!dados || typeof dados !== 'object') return null;
    const s = dados as Record<string, unknown>;
    const id = textoOuNulo(s.id);
    const valor = numero(s.valor);
    const situacao = textoOuNulo(s.situacao);
    if (!id || valor === null || !situacao) return null;
    return {
        id,
        valor,
        taxa: numero(s.taxa) ?? 0,
        situacao,
        motivo: textoOuNulo(s.motivo),
        chave: typeof s.chave === 'string' ? s.chave : '',
        tipo_chave: typeof s.tipo_chave === 'string' ? s.tipo_chave : '',
        comprovante_url: textoOuNulo(s.comprovante_url),
        criado_em: textoOuNulo(s.criado_em),
        concluido_em: textoOuNulo(s.concluido_em),
    };
}

/** O bloco `saque` da carteira, ou null (ausente = central sem saque ligado ou API antiga; fora do formato = sem botão). */
export function lerBlocoDoSaque(dados: unknown): BlocoDoSaque | null {
    if (!dados || typeof dados !== 'object') return null;
    const b = dados as Record<string, unknown>;
    const disponivel = numero(b.disponivel);
    const minimo = numero(b.minimo);
    const maximoHoje = numero(b.maximo_hoje);
    const taxa = numero(b.taxa);
    if (disponivel === null || minimo === null || maximoHoje === null || taxa === null) return null;
    return {
        disponivel,
        minimo,
        maximo_hoje: maximoHoje,
        taxa,
        em_andamento: b.em_andamento ? lerSaque(b.em_andamento) : null,
        indisponivel_motivo: textoOuNulo(b.indisponivel_motivo),
    };
}

/**
 * O botão "Sacar via Pix" da Minha carteira: escondido sem o bloco; com um saque em andamento, "Ver saque em andamento";
 * com o motivo do servidor (ou nada a sacar, ou abaixo do mínimo), apagado com o motivo; senão, Sacar.
 */
export function botaoDoSaque(bloco: BlocoDoSaque | null): BotaoDoSaque {
    if (!bloco) return { modo: 'escondido' };
    if (bloco.em_andamento) return { modo: 'acompanhar', id: bloco.em_andamento.id };
    if (bloco.indisponivel_motivo) return { modo: 'indisponivel', motivo: bloco.indisponivel_motivo };
    const disponivel = centavos(bloco.disponivel);
    if (disponivel <= 0 || disponivel < centavos(bloco.minimo)) return { modo: 'indisponivel', motivo: null };
    return { modo: 'sacar' };
}

/** A tela que o botão abre, ou null (botão escondido ou apagado). */
export function destinoDoBotaoDoSaque(botao: BotaoDoSaque): DestinoDoSaque | null {
    if (botao.modo === 'sacar') return { tela: 'Sacar' };
    if (botao.modo === 'acompanhar') return { tela: 'AcompanharSaque', id: botao.id };
    return null;
}
```

- [ ] **Passo 4: rodar e ver passar**

```bash
cd /c/tmp/saque-app && node --experimental-strip-types --test scripts/testes/saque.teste.ts 2>&1 | grep -E "^# (tests|pass|fail)"
```

Esperado: `# tests 4`, `# pass 4`, `# fail 0`.

- [ ] **Passo 5: commit**

```bash
cd /c/tmp/saque-app && git rev-parse --show-toplevel && git add src/utils/saque.ts scripts/testes/saque.teste.ts && git commit -m "Saque Pix: leitura do saque e do bloco da carteira e o botão da Minha carteira

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 2: regras do saque — valor, resumo e chave Pix

**Arquivos:**
- Modificar: `src/utils/saque.ts` (acrescentar no fim)
- Modificar: `scripts/testes/saque.teste.ts` (acrescentar no fim)

- [ ] **Passo 1: escrever os testes que falham**

Acrescente no fim de `scripts/testes/saque.teste.ts`:

```ts
import { centavosDoCampo, chaveEscondida, conferirValor, resumoDoSaque, rotuloDoTipoDaChave, textoDoCampo } from '../../src/utils/saque.ts';
import { formatarReais } from '../../src/utils/ganhos.ts';

test('campo do valor: só os dígitos, da direita para a esquerda', () => {
    assert.equal(centavosDoCampo(''), 0);
    assert.equal(centavosDoCampo('5'), 5);
    assert.equal(centavosDoCampo('6050'), 6050);
    assert.equal(centavosDoCampo('R$ 60,50'), 6050, 'o texto formatado volta aos mesmos centavos');
    assert.equal(centavosDoCampo('R$ 60,5'), 605, 'apagar o último dígito divide por 10');
    assert.equal(centavosDoCampo('R$ 0,005'), 5);
    assert.equal(centavosDoCampo('R$ 123.456,789'), 12345678, 'no máximo 8 dígitos: o nono é ignorado');
    assert.equal(centavosDoCampo('abc'), 0);
    assert.equal(centavosDoCampo(null), 0);
    assert.equal(textoDoCampo(0, formatarReais), '', 'zero: vazio (aparece o placeholder)');
    assert.equal(textoDoCampo(6050, formatarReais), 'R$ 60,50');
    assert.equal(textoDoCampo(123456, formatarReais), 'R$ 1.234,56');
});

test('conferência do valor antes do desbloqueio (o servidor confere de novo)', () => {
    assert.deepEqual(conferirValor(0, bloco(), formatarReais), { chave: 'Saque.erros.informeValor' });
    assert.deepEqual(conferirValor(999, bloco(), formatarReais), { chave: 'Saque.erros.abaixoDoMinimo', valores: { valor: 'R$ 10,00' } });
    assert.deepEqual(conferirValor(10001, bloco(), formatarReais), { chave: 'Saque.erros.acimaDoDisponivel', valores: { valor: 'R$ 100,00' } });
    assert.equal(conferirValor(1000, bloco(), formatarReais), null, 'o mínimo');
    assert.equal(conferirValor(10000, bloco(), formatarReais), null, 'tudo');
    assert.equal(conferirValor(9800, bloco({ disponivel: 98, taxa: 2 }), formatarReais), null, 'o disponível já desconta a taxa');
});

test('resumo: cai no Pix o valor; a taxa sai do saldo à parte', () => {
    assert.deepEqual(resumoDoSaque(5000, 0), { caiNoPix: 50, taxa: 0, saiDoSaldo: 50 });
    assert.deepEqual(resumoDoSaque(5000, 2), { caiNoPix: 50, taxa: 2, saiDoSaldo: 52 });
    assert.deepEqual(resumoDoSaque(1, 0.1 + 0.2), { caiNoPix: 0.01, taxa: 0.3, saiDoSaldo: 0.31 }, 'em centavos, sem erro de vírgula flutuante');
    assert.deepEqual(resumoDoSaque(0, 2), { caiNoPix: 0, taxa: 2, saiDoSaldo: 2 });
});

test('chave Pix escondida como no servidor (Saque::chaveEscondida)', () => {
    assert.equal(chaveEscondida('11999994321', 'telefone'), '*******4321');
    assert.equal(chaveEscondida('12345678901', 'cpf'), '*******8901');
    assert.equal(chaveEscondida('12345678000199', 'cnpj'), '**********0199');
    assert.equal(chaveEscondida('joao.silva@gmail.com', 'email'), 'jo***@gmail.com');
    assert.equal(chaveEscondida('123e4567-e89b-12d3-a456-426614174000', 'aleatoria'), '123e…4000');
    assert.equal(chaveEscondida('123', 'cpf'), '123', 'curta: sem asteriscos');
    assert.equal(chaveEscondida('', 'cpf'), '');
    assert.equal(chaveEscondida(null, 'cpf'), '');
});

test('rótulo do tipo da chave', () => {
    assert.equal(rotuloDoTipoDaChave('cpf'), 'Saque.tiposDeChave.cpf');
    assert.equal(rotuloDoTipoDaChave('telefone'), 'Saque.tiposDeChave.telefone');
    assert.equal(rotuloDoTipoDaChave('aleatoria'), 'Saque.tiposDeChave.aleatoria');
    assert.equal(rotuloDoTipoDaChave('evp'), 'Saque.tiposDeChave.outra', 'tipo novo do servidor não quebra a tela');
    assert.equal(rotuloDoTipoDaChave(null), 'Saque.tiposDeChave.outra');
});
```

- [ ] **Passo 2: rodar e ver falhar**

```bash
cd /c/tmp/saque-app && node --experimental-strip-types --test scripts/testes/saque.teste.ts 2>&1 | tail -5
```

Esperado: erro `The requested module '../../src/utils/saque.ts' does not provide an export named 'centavosDoCampo'`.

- [ ] **Passo 3: implementar**

Acrescente no fim de `src/utils/saque.ts`:

```ts
/** Máximo de dígitos do campo de valor: R$ 999.999,99. */
export const DIGITOS_DO_VALOR = 8;

/** Texto para a tela: uma chave de tradução (com valores) ou um texto pronto do servidor. */
export type TextoDoSaque = { chave: string; valores?: Record<string, string> } | { texto: string };

/**
 * O campo de valor como nos apps de banco: só os dígitos contam, da direita para a esquerda ("6050" = R$ 60,50). Apagar
 * o último caractere do texto formatado tira o último dígito; o nono dígito é ignorado.
 */
export function centavosDoCampo(texto: string | null | undefined): number {
    const digitos = String(texto ?? '')
        .replace(/\D/g, '')
        .replace(/^0+/, '')
        .slice(0, DIGITOS_DO_VALOR);
    return digitos ? Number(digitos) : 0;
}

/** O texto do campo: vazio no zero (aparece o placeholder), senão "R$ 60,50". */
export function textoDoCampo(valorEmCentavos: number, formatarReais: (valor: number) => string): string {
    return valorEmCentavos > 0 ? formatarReais(valorEmCentavos / 100) : '';
}

/** Conferência antes de pedir o desbloqueio (o servidor confere tudo de novo): null = pode confirmar. */
export function conferirValor(valorEmCentavos: number, bloco: BlocoDoSaque, formatarReais: (valor: number) => string): TextoDoSaque | null {
    if (!(valorEmCentavos > 0)) return { chave: 'Saque.erros.informeValor' };
    if (valorEmCentavos < centavos(bloco.minimo)) return { chave: 'Saque.erros.abaixoDoMinimo', valores: { valor: formatarReais(bloco.minimo) } };
    if (valorEmCentavos > centavos(bloco.disponivel)) return { chave: 'Saque.erros.acimaDoDisponivel', valores: { valor: formatarReais(bloco.disponivel) } };
    return null;
}

/** O que a tela mostra, em reais: cai no Pix (o valor pedido), a taxa (sai do saldo, à parte) e o total que sai do saldo. */
export function resumoDoSaque(valorEmCentavos: number, taxa: number): { caiNoPix: number; taxa: number; saiDoSaldo: number } {
    const valor = Math.max(0, Math.round(valorEmCentavos) || 0);
    const taxaEmCentavos = Math.max(0, centavos(taxa));
    return { caiNoPix: valor / 100, taxa: taxaEmCentavos / 100, saiDoSaldo: (valor + taxaEmCentavos) / 100 };
}

const TIPOS_DE_CHAVE: string[] = ['cpf', 'cnpj', 'telefone', 'email', 'aleatoria'];

/** Chave de tradução do tipo da chave Pix (tipo desconhecido: "Chave Pix"). */
export function rotuloDoTipoDaChave(tipo: string | null | undefined): string {
    return tipo && TIPOS_DE_CHAVE.includes(tipo) ? `Saque.tiposDeChave.${tipo}` : 'Saque.tiposDeChave.outra';
}

/**
 * A chave Pix com o meio escondido, igual ao servidor (Saque::chaveEscondida): e-mail com 2 letras e o domínio, aleatória
 * com o começo e o fim, o resto (telefone, CPF, CNPJ) só com os 4 últimos.
 */
export function chaveEscondida(chave: string | null | undefined, tipo: string | null | undefined): string {
    const texto = String(chave ?? '').trim();
    if (!texto) return '';
    if (tipo === 'email') {
        const arroba = texto.indexOf('@');
        const nome = arroba >= 0 ? texto.slice(0, arroba) : texto;
        const dominio = arroba >= 0 ? texto.slice(arroba + 1) : '';
        return `${Array.from(nome).slice(0, 2).join('')}***@${dominio}`;
    }
    const letras = Array.from(texto);
    if (tipo === 'aleatoria') return `${letras.slice(0, 4).join('')}…${letras.slice(-4).join('')}`;
    return '*'.repeat(Math.max(0, letras.length - 4)) + letras.slice(-4).join('');
}
```

- [ ] **Passo 4: rodar e ver passar**

```bash
cd /c/tmp/saque-app && node --experimental-strip-types --test scripts/testes/saque.teste.ts 2>&1 | grep -E "^# (tests|pass|fail)"
```

Esperado: `# tests 9`, `# pass 9`, `# fail 0`.

- [ ] **Passo 5: commit**

```bash
cd /c/tmp/saque-app && git rev-parse --show-toplevel && git add src/utils/saque.ts scripts/testes/saque.teste.ts && git commit -m "Saque Pix: campo do valor, conferência, resumo e chave Pix escondida

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 3: regras do saque — uuid do envio, falhas e desbloqueio

**Arquivos:**
- Modificar: `src/utils/saque.ts` (acrescentar no fim)
- Modificar: `scripts/testes/saque.teste.ts` (acrescentar no fim)

- [ ] **Passo 1: escrever os testes que falham**

Acrescente no fim de `scripts/testes/saque.teste.ts`:

```ts
import { mensagemDaFalha, novoUuid, resultadoDoDesbloqueio, uuidDoEnvio } from '../../src/utils/saque.ts';

test('uuid v4 em minúsculas a partir de 16 bytes (o formato que o servidor aceita)', () => {
    assert.equal(novoUuid(Uint8Array.from({ length: 16 }, (_, i) => i)), '00010203-0405-4607-8809-0a0b0c0d0e0f');
    assert.equal(novoUuid(new Uint8Array(16).fill(255)), 'ffffffff-ffff-4fff-bfff-ffffffffffff');
    const formatoDoServidor = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
    for (let i = 0; i < 20; i++) assert.match(novoUuid(globalThis.crypto.getRandomValues(new Uint8Array(16))), formatoDoServidor);
    assert.throws(() => novoUuid(new Uint8Array(15)));
});

test('envio repetido reaproveita o uuid até o servidor devolver o saque', () => {
    let gerados = 0;
    const gerar = () => `uuid-${++gerados}`;
    const primeiro = uuidDoEnvio(null, gerar);
    assert.equal(primeiro, 'uuid-1');
    assert.equal(uuidDoEnvio(primeiro, gerar), 'uuid-1', 'a rede caiu: Confirmar de novo manda o mesmo');
    assert.equal(gerados, 1);
    assert.equal(uuidDoEnvio(null, gerar), 'uuid-2', 'depois do saque criado (pendente limpo): outro saque, outro uuid');
});

test('mensagem da falha: a do servidor ou "sem resposta"', () => {
    assert.deepEqual(mensagemDaFalha(new Error('Já há um saque em andamento.')), { texto: 'Já há um saque em andamento.' });
    assert.deepEqual(mensagemDaFalha(new Error('Chave Pix alterada; saque liberado às 10:00 de 28/10.')), { texto: 'Chave Pix alterada; saque liberado às 10:00 de 28/10.' });
    assert.deepEqual(mensagemDaFalha(new Error('Atualize o app para continuar.')), { texto: 'Atualize o app para continuar.' });
    for (const semResposta of ['Network request failed', 'Oops! Something went wrong when handling your request.', 'Internal Server Error', 'Bad Gateway', 'Service Unavailable', 'Gateway Timeout', 'Too Many Requests', 'Aborted', '']) {
        assert.deepEqual(mensagemDaFalha(new Error(semResposta)), { chave: 'Saque.semResposta' }, semResposta || '(vazia)');
    }
    assert.deepEqual(mensagemDaFalha(new TypeError('Network request failed')), { chave: 'Saque.semResposta' });
    assert.deepEqual(mensagemDaFalha(null), { chave: 'Saque.semResposta' });
    assert.deepEqual(mensagemDaFalha('texto solto'), { chave: 'Saque.semResposta' });
});

test('resultado do desbloqueio do aparelho', () => {
    assert.equal(resultadoDoDesbloqueio('ok'), 'ok');
    assert.equal(resultadoDoDesbloqueio('cancelado'), 'cancelado');
    assert.equal(resultadoDoDesbloqueio('sem_bloqueio'), 'sem_bloqueio');
    assert.equal(resultadoDoDesbloqueio('erro'), 'erro');
    assert.equal(resultadoDoDesbloqueio('outra coisa'), 'erro');
    assert.equal(resultadoDoDesbloqueio(undefined), 'erro', 'APK sem o módulo nativo');
    assert.equal(resultadoDoDesbloqueio(true), 'erro');
});
```

- [ ] **Passo 2: rodar e ver falhar**

```bash
cd /c/tmp/saque-app && node --experimental-strip-types --test scripts/testes/saque.teste.ts 2>&1 | tail -5
```

Esperado: erro `does not provide an export named 'mensagemDaFalha'`.

- [ ] **Passo 3: implementar**

Acrescente no fim de `src/utils/saque.ts`:

```ts
/**
 * Um uuid v4 em minúsculas (o servidor exige 8-4-4-4-12 em hexadecimal: é a chave de idempotência do saque) a partir de
 * 16 bytes aleatórios. No app: novoUuid(globalThis.crypto.getRandomValues(new Uint8Array(16))), do
 * react-native-get-random-values (carregado no index.native.tsx).
 */
export function novoUuid(bytes: Uint8Array): string {
    if (!bytes || bytes.length < 16) throw new Error('uuid: são precisos 16 bytes aleatórios');
    const b = Array.from(bytes.slice(0, 16));
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const hex = b.map((byte) => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20, 32)}`;
}

/**
 * O uuid do envio: o do envio anterior que ainda não voltou com o saque, se houver; senão um novo. Quem chama guarda o
 * devolvido e só o esquece quando o servidor responde com o saque. Assim, "Confirmar" de novo depois de a rede cair manda
 * o mesmo uuid, e o servidor devolve o saque já criado (200) em vez de criar outro.
 */
export function uuidDoEnvio(pendente: string | null, gerar: () => string): string {
    return pendente ?? gerar();
}

// textos que o SDK repassa quando o servidor não respondeu com {"errors": [...]}: sem rede, resposta que não é JSON,
// tempo esgotado e o texto padrão do HTTP no 429 e nos 5xx
const FALHAS_SEM_RESPOSTA: RegExp[] = [/network request failed/i, /something went wrong/i, /abort/i, /time ?out/i, /too many/i, /server error/i, /bad gateway/i, /unavailable/i];

/** O que mostrar quando o pedido falha: a mensagem do servidor (pt-BR, ex.: "Já há um saque em andamento.") ou "sem resposta". */
export function mensagemDaFalha(erro: unknown): TextoDoSaque {
    const mensagem = erro && typeof erro === 'object' ? textoOuNulo((erro as { message?: unknown }).message) : null;
    if (!mensagem || FALHAS_SEM_RESPOSTA.some((padrao) => padrao.test(mensagem))) return { chave: 'Saque.semResposta' };
    return { texto: mensagem };
}

export type ResultadoDoDesbloqueio = 'ok' | 'cancelado' | 'sem_bloqueio' | 'erro';

/** A resposta do módulo nativo DesbloqueioDoAparelho; qualquer outra coisa (APK sem o módulo, exceção) é erro. */
export function resultadoDoDesbloqueio(valor: unknown): ResultadoDoDesbloqueio {
    return valor === 'ok' || valor === 'cancelado' || valor === 'sem_bloqueio' ? valor : 'erro';
}
```

- [ ] **Passo 4: rodar e ver passar**

```bash
cd /c/tmp/saque-app && node --experimental-strip-types --test scripts/testes/saque.teste.ts 2>&1 | grep -E "^# (tests|pass|fail)"
```

Esperado: `# tests 13`, `# pass 13`, `# fail 0`.

- [ ] **Passo 5: commit**

```bash
cd /c/tmp/saque-app && git rev-parse --show-toplevel && git add src/utils/saque.ts scripts/testes/saque.teste.ts && git commit -m "Saque Pix: uuid do envio reaproveitado, mensagem da falha e resultado do desbloqueio

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 4: regras do saque — acompanhamento, situação, comprovante e pushes

**Arquivos:**
- Modificar: `src/utils/saque.ts` (acrescentar no fim)
- Modificar: `scripts/testes/saque.teste.ts` (acrescentar no fim)

- [ ] **Passo 1: escrever os testes que falham**

Acrescente no fim de `scripts/testes/saque.teste.ts`:

```ts
import {
    EVENTO_DO_SAQUE,
    INTERVALO_DA_CONSULTA_MS,
    LIMITE_DA_CONSULTA_MS,
    consultaDeNovo,
    destinoDoPushDoSaque,
    estadoDoAcompanhamento,
    linkDoComprovante,
    rotuloDaSituacaoDoSaque,
    saqueDoAviso,
} from '../../src/utils/saque.ts';

test('acompanhamento: a cada 3 s por até 1 min; depois, o push avisa', () => {
    assert.equal(INTERVALO_DA_CONSULTA_MS, 3000);
    assert.equal(LIMITE_DA_CONSULTA_MS, 60000);
    const t0 = 1_000_000;
    assert.equal(estadoDoAcompanhamento(null, t0, t0), 'carregando', 'aberto pelo push: ainda sem o saque');
    assert.equal(estadoDoAcompanhamento(null, t0, t0 + 60_000), 'demorando');
    assert.equal(estadoDoAcompanhamento(saque({ situacao: 'enviando' }), t0, t0 + 3_000), 'enviando');
    assert.equal(estadoDoAcompanhamento(saque({ situacao: 'processando' }), t0, t0 + 59_999), 'enviando');
    assert.equal(estadoDoAcompanhamento(saque({ situacao: 'processando' }), t0, t0 + 60_000), 'demorando');
    assert.equal(estadoDoAcompanhamento(saque({ situacao: 'situacao_nova' }), t0, t0), 'enviando', 'situação nova do servidor: segue esperando');
    assert.equal(estadoDoAcompanhamento(saque({ situacao: 'concluido' }), t0, t0 + 120_000), 'concluido', 'concluído vale mesmo depois de 1 min');
    assert.equal(estadoDoAcompanhamento(saque({ situacao: 'falhou' }), t0, t0), 'falhou');
    assert.equal(consultaDeNovo('carregando'), true);
    assert.equal(consultaDeNovo('enviando'), true);
    assert.equal(consultaDeNovo('demorando'), false);
    assert.equal(consultaDeNovo('concluido'), false);
    assert.equal(consultaDeNovo('falhou'), false);
});

test('situação do saque no extrato e no recibo', () => {
    assert.deepEqual(rotuloDaSituacaoDoSaque('concluido'), { chave: 'Saque.situacao.concluido', variante: 'ok' });
    assert.deepEqual(rotuloDaSituacaoDoSaque('falhou'), { chave: 'Saque.situacao.falhou', variante: 'perigo' });
    for (const situacao of ['enviando', 'processando', 'situacao_nova', null]) {
        assert.deepEqual(rotuloDaSituacaoDoSaque(situacao), { chave: 'Saque.situacao.emAndamento', variante: 'alerta' }, String(situacao));
    }
});

test('link do comprovante do Asaas: só https', () => {
    assert.equal(linkDoComprovante('https://www.asaas.com/comprovantes/abc'), 'https://www.asaas.com/comprovantes/abc');
    assert.equal(linkDoComprovante('  https://sandbox.asaas.com/c/1  '), 'https://sandbox.asaas.com/c/1');
    assert.equal(linkDoComprovante('http://www.asaas.com/comprovantes/abc'), null);
    assert.equal(linkDoComprovante('javascript:alert(1)'), null);
    assert.equal(linkDoComprovante('intent://x#Intent;end'), null);
    assert.equal(linkDoComprovante(''), null);
    assert.equal(linkDoComprovante(null), null);
});

test('push do saque: tocar abre o acompanhamento; a troca da chave abre a carteira', () => {
    assert.deepEqual(destinoDoPushDoSaque({ type: 'entregas_saque', saque: U1 }, 'opened'), { tela: 'AcompanharSaque', id: U1 });
    assert.deepEqual(destinoDoPushDoSaque({ type: 'entregas_saque', saque: U1.toUpperCase() }, 'opened'), { tela: 'AcompanharSaque', id: U1 }, 'uuid em minúsculas');
    assert.equal(destinoDoPushDoSaque({ type: 'entregas_saque', saque: U1 }, 'received'), null, 'com o app na frente, o aviso só aparece');
    assert.deepEqual(destinoDoPushDoSaque({ type: 'entregas_saque' }, 'opened'), { tela: 'Carteira' }, 'sem o uuid: a carteira');
    assert.deepEqual(destinoDoPushDoSaque({ type: 'entregas_pix_alterado' }, 'opened'), { tela: 'Carteira' });
    assert.equal(destinoDoPushDoSaque({ type: 'entregas_pagamento', pagamento: 'p1' }, 'opened'), null);
    assert.equal(destinoDoPushDoSaque(null, 'opened'), null);
    assert.equal(saqueDoAviso({ type: 'entregas_saque', saque: ` ${U1.toUpperCase()} ` }), U1, 'o uuid do aviso, em minúsculas');
    assert.equal(saqueDoAviso({ type: 'entregas_pix_alterado' }), null);
    assert.equal(saqueDoAviso(null), null);
    assert.equal(EVENTO_DO_SAQUE, 'entregas.saque');
});
```

- [ ] **Passo 2: rodar e ver falhar**

```bash
cd /c/tmp/saque-app && node --experimental-strip-types --test scripts/testes/saque.teste.ts 2>&1 | tail -5
```

Esperado: erro `does not provide an export named 'EVENTO_DO_SAQUE'`.

- [ ] **Passo 3: implementar**

Acrescente no fim de `src/utils/saque.ts`:

```ts
/** A tela de acompanhamento consulta o saque a cada 3 s, por até 1 min; depois disso, o push avisa. */
export const INTERVALO_DA_CONSULTA_MS = 3_000;
export const LIMITE_DA_CONSULTA_MS = 60_000;

/** Evento (react-native-event-listeners) do push do saque recebido com o app na frente: a tela aberta relê na hora. */
export const EVENTO_DO_SAQUE = 'entregas.saque';

export type EstadoDoAcompanhamento = 'carregando' | 'enviando' | 'demorando' | 'concluido' | 'falhou';

/**
 * O que a tela de acompanhamento mostra: concluído e falhou valem sempre; senão "enviando" (ou "carregando", sem o saque
 * ainda) até 1 min desde `desde`, e depois "demorando" (para de consultar). Situação nova do servidor: segue esperando.
 */
export function estadoDoAcompanhamento(saque: SaqueDoApp | null, desde: number, agora: number): EstadoDoAcompanhamento {
    if (saque?.situacao === 'concluido') return 'concluido';
    if (saque?.situacao === 'falhou') return 'falhou';
    if (agora - desde >= LIMITE_DA_CONSULTA_MS) return 'demorando';
    return saque ? 'enviando' : 'carregando';
}

/** A tela segue consultando o servidor neste estado? */
export function consultaDeNovo(estado: EstadoDoAcompanhamento): boolean {
    return estado === 'carregando' || estado === 'enviando';
}

/** Selo da situação do saque (extrato e recibo): em andamento amarelo, caiu no Pix verde, não concluído vermelho. */
export function rotuloDaSituacaoDoSaque(situacao: string | null | undefined): { chave: string; variante: 'ok' | 'alerta' | 'perigo' } {
    if (situacao === 'concluido') return { chave: 'Saque.situacao.concluido', variante: 'ok' };
    if (situacao === 'falhou') return { chave: 'Saque.situacao.falhou', variante: 'perigo' };
    return { chave: 'Saque.situacao.emAndamento', variante: 'alerta' };
}

/** O comprovante do Asaas (transactionReceiptUrl) só abre se for https; outro esquema vindo do servidor é ignorado. */
export function linkDoComprovante(url: unknown): string | null {
    const texto = textoOuNulo(url);
    return texto && /^https:\/\//i.test(texto) ? texto : null;
}

const uuidDoPush = (valor: unknown): string | null => textoOuNulo(valor)?.toLowerCase() ?? null;

/**
 * Push do saque tocado (SaqueConcluido e SaqueNaoConcluido: type 'entregas_saque', `saque` = uuid; PixDeSaqueAlterado:
 * type 'entregas_pix_alterado', sem dados): o acompanhamento daquele saque, ou a Minha carteira. Com o app na frente
 * ('received'), nada: o aviso do sistema basta.
 */
export function destinoDoPushDoSaque(payload: Record<string, unknown> | null | undefined, acao: string): { tela: 'AcompanharSaque'; id: string } | { tela: 'Carteira' } | null {
    if (!payload || acao !== 'opened') return null;
    if (payload.type === 'entregas_saque') {
        const id = uuidDoPush(payload.saque);
        return id ? { tela: 'AcompanharSaque', id } : { tela: 'Carteira' };
    }
    if (payload.type === 'entregas_pix_alterado') return { tela: 'Carteira' };
    return null;
}

/** O uuid do saque de um push `entregas_saque` (recebido ou tocado), para a tela de acompanhamento aberta reler. */
export function saqueDoAviso(payload: Record<string, unknown> | null | undefined): string | null {
    if (!payload || payload.type !== 'entregas_saque') return null;
    return uuidDoPush(payload.saque);
}
```

- [ ] **Passo 4: rodar e ver passar**

```bash
cd /c/tmp/saque-app && node --experimental-strip-types --test scripts/testes/saque.teste.ts 2>&1 | grep -E "^# (tests|pass|fail)"
```

Esperado: `# tests 17`, `# pass 17`, `# fail 0`.

- [ ] **Passo 5: commit**

```bash
cd /c/tmp/saque-app && git rev-parse --show-toplevel && git add src/utils/saque.ts scripts/testes/saque.teste.ts && git commit -m "Saque Pix: acompanhamento, situação, link do comprovante e pushes

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 5: extrato — "Saque Pix" e a taxa fora dos ganhos

**Arquivos:**
- Modificar: `src/utils/extrato.ts`
- Modificar: `scripts/testes/extrato.teste.ts` (acrescentar no fim)

- [ ] **Passo 1: escrever os testes que falham**

Acrescente no fim de `scripts/testes/extrato.teste.ts` (o `pagoCom`, o `lancamento`, o `descricao`, o `destinoDoToque`, o
`ganhosDoPeriodo` e o `formatarKm` já estão importados/definidos acima no arquivo):

```ts
test('descrição do pagamento de saque Pix: "Saque Pix", sem a forma', () => {
    const pagamentoDeSaque = { id: 'p9', forma: 'pix', tem_comprovante: false, estornado: false, saque: { situacao: 'concluido', comprovante_url: 'https://asaas.teste/c/1' } };
    assert.deepEqual(descricao(lancamento({ tipo: 'pagamento', valor: -50, pagamento: pagamentoDeSaque }), formatarKm), { titulo: { chave: 'Extrato.tipos.saquePix' }, subtitulo: null });
    assert.deepEqual(destinoDoToque(lancamento({ tipo: 'pagamento', valor: -50, pagamento: pagamentoDeSaque })), { tela: 'Recibo', id: 'p9' }, 'o toque abre o recibo, como no pagamento comum');
    // pagamento comum continua igual (a API antiga nem manda a chave saque)
    assert.deepEqual(descricao(lancamento({ tipo: 'pagamento', valor: -150, pagamento: { ...pagoCom('p1'), saque: null } }), formatarKm), {
        titulo: { chave: 'Extrato.tipos.pagamento' },
        subtitulo: { chave: 'Extrato.formas.pix' },
    });
});

test('ganhos do período: a taxa do saque Pix e a devolução dela ficam de fora', () => {
    assert.equal(
        ganhosDoPeriodo([
            lancamento({ id: 1, tipo: 'corrida', valor: 20 }),
            lancamento({ id: 2, tipo: 'pagamento', valor: -50, pagamento: { ...pagoCom('p1'), saque: { situacao: 'processando', comprovante_url: null } } }),
            lancamento({ id: 3, tipo: 'ajuste', valor: -2, motivo: 'Taxa do saque Pix' }),
        ]),
        20,
    );
    // saque que falhou: estorno do pagamento ("Pagamento estornado") e devolução da taxa ("Taxa do saque devolvida")
    assert.equal(
        ganhosDoPeriodo([
            lancamento({ id: 1, tipo: 'corrida', valor: 20 }),
            lancamento({ id: 2, tipo: 'ajuste', valor: -2, motivo: 'Taxa do saque Pix', estornado: true }),
            lancamento({ id: 3, tipo: 'estorno', valor: 2, estorno_de: 2, motivo: 'Taxa do saque devolvida' }),
            lancamento({ id: 4, tipo: 'estorno', valor: 50, estorno_de: 9, motivo: 'Pagamento estornado' }),
        ]),
        20,
    );
    // devolução da taxa com o original fora do período: reconhecida pelo motivo
    assert.equal(ganhosDoPeriodo([lancamento({ id: 5, tipo: 'estorno', valor: 2, estorno_de: 1, motivo: 'Taxa do saque devolvida' })]), 0);
    // um ajuste negativo qualquer continua descontando (o "Não pagar")
    assert.equal(ganhosDoPeriodo([lancamento({ id: 1, tipo: 'corrida', valor: 20 }), lancamento({ id: 2, tipo: 'ajuste', valor: -2, motivo: 'Taxa da maquininha' })]), 18);
});
```

- [ ] **Passo 2: rodar e ver falhar**

```bash
cd /c/tmp/saque-app && node --experimental-strip-types --test scripts/testes/extrato.teste.ts 2>&1 | grep -E "^# (tests|pass|fail)|not ok"
```

Esperado: `# tests 20`, `# fail 2` (`not ok … descrição do pagamento de saque Pix` e `not ok … a taxa do saque Pix`).

- [ ] **Passo 3: implementar em `src/utils/extrato.ts`**

1. Troque:

```ts
export type FormaDePagamento = 'pix' | 'dinheiro' | 'transferencia' | 'outro';
```

por:

```ts
export type FormaDePagamento = 'pix' | 'dinheiro' | 'transferencia' | 'outro';

/** Pagamento de um saque Pix pelo app (o servidor marca só esses): a situação do saque e o comprovante do Asaas. */
export type MarcaDoSaque = { situacao: string; comprovante_url: string | null };
```

2. No tipo `Lancamento`, troque:

```ts
    pagamento: { id: string; forma: FormaDePagamento | string; tem_comprovante: boolean; estornado: boolean } | null;
```

por:

```ts
    pagamento: { id: string; forma: FormaDePagamento | string; tem_comprovante: boolean; estornado: boolean; saque?: MarcaDoSaque | null } | null;
```

3. No tipo `Pagamento`, troque:

```ts
    estornado: boolean;
    comprovante: Comprovante | null;
};
```

por:

```ts
    estornado: boolean;
    comprovante: Comprovante | null;
    /** Só no pagamento de saque Pix pelo app (o comprovante dele é o do Asaas, não o anexado pela central). */
    saque?: MarcaDoSaque | null;
};
```

4. No tipo `Carteira`, troque:

```ts
    pix: { chave: string; tipo: string } | null;
};

/** Um dia da lista: `data` é a lista que a SectionList/FlatList lê (como no DiaDeGanhos); `itens` é a quantidade. */
```

por:

```ts
    pix: { chave: string; tipo: string } | null;
    /** Bloco do saque Pix (só quando a central liberou o saque); lido por lerBlocoDoSaque (src/utils/saque.ts). */
    saque?: unknown;
};

/** Um dia da lista: `data` é a lista que a SectionList/FlatList lê (como no DiaDeGanhos); `itens` é a quantidade. */
```

5. Na `descricao`, troque:

```ts
    if (lancamento.tipo === 'pagamento' || lancamento.tipo === 'recebimento') {
        return { titulo: tituloDoTipo, subtitulo: lancamento.pagamento ? { chave: rotuloDaForma(lancamento.pagamento.forma) } : null };
    }
```

por:

```ts
    if (lancamento.tipo === 'pagamento' || lancamento.tipo === 'recebimento') {
        // saque Pix pelo app: "Saque Pix" (a situação vai no selo da linha)
        if (lancamento.pagamento?.saque) return { titulo: { chave: 'Extrato.tipos.saquePix' }, subtitulo: null };
        return { titulo: tituloDoTipo, subtitulo: lancamento.pagamento ? { chave: rotuloDaForma(lancamento.pagamento.forma) } : null };
    }
```

6. Troque:

```ts
// motivo que o servidor grava no estorno do lançamento de um pagamento (Pagamentos::estornar)
const MOTIVO_DO_PAGAMENTO_ESTORNADO = 'Pagamento estornado';
```

por:

```ts
// motivo que o servidor grava no estorno do lançamento de um pagamento (Pagamentos::estornar)
const MOTIVO_DO_PAGAMENTO_ESTORNADO = 'Pagamento estornado';

// motivos que o servidor grava na taxa do saque Pix e na devolução dela (Saque::MOTIVO_DA_TAXA e MOTIVO_TAXA_DEVOLVIDA)
const MOTIVO_DA_TAXA_DO_SAQUE = 'Taxa do saque Pix';
const MOTIVO_DA_TAXA_DEVOLVIDA = 'Taxa do saque devolvida';
```

7. Logo depois do fim da função `oQueOEstornoDesfaz`, ou seja, troque:

```ts
    if (valor > 0 && !estorno.pedido && estorno.motivo === MOTIVO_DO_PAGAMENTO_ESTORNADO) return 'pagamento';
    return null;
}
```

por:

```ts
    if (valor > 0 && !estorno.pedido && estorno.motivo === MOTIVO_DO_PAGAMENTO_ESTORNADO) return 'pagamento';
    return null;
}

/**
 * A taxa do saque Pix (ajuste negativo "Taxa do saque Pix", sem pedido) ou a devolução dela (estorno "Taxa do saque
 * devolvida", ou estorno cujo original na lista é a taxa). Fica fora dos ganhos: é do recebimento, como o pagamento.
 */
function ehTaxaDoSaque(lancamento: Lancamento, porId: Map<number, Lancamento>): boolean {
    if (lancamento.pedido) return false;
    if (lancamento.tipo === 'ajuste') return lancamento.motivo === MOTIVO_DA_TAXA_DO_SAQUE;
    if (lancamento.tipo !== 'estorno') return false;
    if (lancamento.motivo === MOTIVO_DA_TAXA_DEVOLVIDA) return true;
    const original = lancamento.estorno_de !== null ? porId.get(lancamento.estorno_de) : undefined;
    return !!original && original.tipo === 'ajuste' && !original.pedido && original.motivo === MOTIVO_DA_TAXA_DO_SAQUE;
}
```

8. No `ganhosDoPeriodo`, troque:

```ts
        if (lancamento.tipo === 'estorno' && oQueOEstornoDesfaz(lancamento, porId) === 'pagamento') continue;
```

por:

```ts
        if (lancamento.tipo === 'estorno' && oQueOEstornoDesfaz(lancamento, porId) === 'pagamento') continue;
        // a taxa do saque Pix e a devolução dela são do recebimento, não do trabalho (como o pagamento)
        if (ehTaxaDoSaque(lancamento, porId)) continue;
```

9. No comentário do `ganhosDoPeriodo`, troque a linha:

```ts
 * ele recebeu ficam na "Minha carteira" (GET v1/entregas/motoboy/carteira).
```

por:

```ts
 * ele recebeu ficam na "Minha carteira" (GET v1/entregas/motoboy/carteira). A taxa do saque Pix e a devolução dela
 * também ficam de fora (ehTaxaDoSaque).
```

- [ ] **Passo 4: rodar e ver passar**

```bash
cd /c/tmp/saque-app && node --experimental-strip-types --test scripts/testes/extrato.teste.ts 2>&1 | grep -E "^# (tests|pass|fail)"
```

Esperado: `# tests 20`, `# pass 20`, `# fail 0`.

- [ ] **Passo 5: `tsc` sem erro novo**

```bash
bash /c/tmp/sa-tsc.sh
```

Esperado: `tsc: nenhum erro novo`.

- [ ] **Passo 6: commit**

```bash
cd /c/tmp/saque-app && git rev-parse --show-toplevel && git add src/utils/extrato.ts scripts/testes/extrato.teste.ts && git commit -m "Extrato: pagamento de saque como Saque Pix e a taxa do saque fora dos ganhos

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 6: textos (pt e en)

**Arquivos:**
- Modificar: `translations/pt.json`, `translations/en.json`

- [ ] **Passo 1: acrescentar as chaves**

Os dois arquivos fazem ida e volta exata com `JSON.stringify(…, null, 4)` (conferido em 2026-10-26); o script mantém o fim
de linha do arquivo e para se o grupo `Saque` já existir.

```bash
cd /c/tmp/saque-app && node - <<'FIM'
const fs = require('fs');
const textos = {
    pt: {
        saquePix: 'Saque Pix',
        Recibo: {
            tituloDoSaque: 'Recibo do saque',
            situacao: 'Situação',
            formaDoSaque: 'Saque Pix pelo app',
            saqueNaoConcluido: 'Saque não concluído: o valor voltou para o seu saldo.',
            abrirComprovanteDoPix: 'Abrir comprovante do Pix',
            comprovanteDoSaqueEmAndamento: 'O comprovante aparece quando o Pix cair.',
            semComprovanteDoSaque: 'Sem comprovante: o saque não foi concluído.',
        },
        Saque: {
            botao: 'Sacar via Pix',
            acompanhar: 'Ver saque em andamento',
            indisponivel: 'Saque Pix indisponível no momento.',
            titulo: 'Sacar via Pix',
            voltar: 'Voltar',
            disponivel: 'Disponível para sacar',
            minimo: 'Mínimo de {{valor}} por saque',
            valor: 'Quanto você quer sacar?',
            tudo: 'Tudo',
            caiNoPix: 'Cai no seu Pix',
            taxa: 'Taxa do saque (sai do saldo)',
            semTaxa: 'Sem taxa',
            saiDoSaldo: 'Sai do seu saldo',
            para: 'Para a chave Pix',
            chaveDica: 'É a chave cadastrada pela central. Para trocar, fale com a central.',
            confirmar: 'Confirmar saque',
            enviando: 'Enviando pedido…',
            desbloqueioTitulo: 'Confirme o saque',
            desbloqueioSubtitulo: '{{valor}} para o seu Pix',
            semBloqueio: 'Ative a senha de tela do celular para sacar.',
            abrirConfiguracoes: 'Abrir configurações',
            desbloqueioFalhou: 'Não foi possível confirmar o desbloqueio do celular. Tente de novo.',
            semResposta: 'O servidor não respondeu. Toque em Confirmar de novo: o pedido é o mesmo e o saque não sai duas vezes.',
            carregando: 'Carregando…',
            erroAoCarregar: 'Não foi possível carregar o saque.',
            tentarDeNovo: 'Tentar de novo',
            tiposDeChave: { cpf: 'CPF', cnpj: 'CNPJ', telefone: 'Telefone', email: 'E-mail', aleatoria: 'Chave aleatória', outra: 'Chave Pix' },
            erros: {
                informeValor: 'Informe o valor do saque.',
                abaixoDoMinimo: 'O valor mínimo do saque é {{valor}}.',
                acimaDoDisponivel: 'Valor acima do disponível para saque ({{valor}}).',
            },
            situacao: { emAndamento: 'Em andamento', concluido: 'Caiu no Pix', falhou: 'Não concluído' },
            acompanhamento: {
                titulo: 'Saque Pix',
                enviando: 'Enviando…',
                enviandoDica: 'Seu pedido foi aceito. O Pix costuma cair em poucos segundos.',
                demorando: 'Ainda processando',
                demorandoDica: 'O banco ainda não confirmou. Você recebe uma notificação quando o Pix cair.',
                consultarDeNovo: 'Consultar de novo',
                concluido: 'Caiu no seu Pix ✓',
                concluidoDica: 'Confira no app do seu banco.',
                falhou: 'Não concluído; o valor voltou ao saldo',
                motivo: 'Motivo: {{motivo}}',
                taxa: 'Taxa de {{valor}} descontada do saldo',
                verComprovante: 'Ver comprovante',
                erroAoAbrirComprovante: 'Não foi possível abrir o comprovante.',
                pronto: 'Pronto',
                voltarAoInicio: 'Voltar ao Início',
            },
        },
    },
    en: {
        saquePix: 'Pix withdrawal',
        Recibo: {
            tituloDoSaque: 'Withdrawal receipt',
            situacao: 'Status',
            formaDoSaque: 'Pix withdrawal in the app',
            saqueNaoConcluido: 'Withdrawal not completed: the amount went back to your balance.',
            abrirComprovanteDoPix: 'Open Pix receipt',
            comprovanteDoSaqueEmAndamento: 'The receipt shows up when the Pix arrives.',
            semComprovanteDoSaque: 'No receipt: the withdrawal was not completed.',
        },
        Saque: {
            botao: 'Withdraw via Pix',
            acompanhar: 'See withdrawal in progress',
            indisponivel: 'Pix withdrawal unavailable right now.',
            titulo: 'Withdraw via Pix',
            voltar: 'Back',
            disponivel: 'Available to withdraw',
            minimo: 'Minimum of {{valor}} per withdrawal',
            valor: 'How much do you want to withdraw?',
            tudo: 'All',
            caiNoPix: 'Goes to your Pix',
            taxa: 'Withdrawal fee (from your balance)',
            semTaxa: 'No fee',
            saiDoSaldo: 'Taken from your balance',
            para: 'To the Pix key',
            chaveDica: 'This is the key registered by the dispatch. To change it, talk to the dispatch.',
            confirmar: 'Confirm withdrawal',
            enviando: 'Sending request…',
            desbloqueioTitulo: 'Confirm the withdrawal',
            desbloqueioSubtitulo: '{{valor}} to your Pix',
            semBloqueio: 'Turn on your phone screen lock to withdraw.',
            abrirConfiguracoes: 'Open settings',
            desbloqueioFalhou: 'Could not confirm the phone unlock. Try again.',
            semResposta: 'The server did not answer. Tap Confirm again: it is the same request and the money does not go out twice.',
            carregando: 'Loading…',
            erroAoCarregar: 'Could not load the withdrawal.',
            tentarDeNovo: 'Try again',
            tiposDeChave: { cpf: 'CPF', cnpj: 'CNPJ', telefone: 'Phone', email: 'E-mail', aleatoria: 'Random key', outra: 'Pix key' },
            erros: {
                informeValor: 'Enter the withdrawal amount.',
                abaixoDoMinimo: 'The minimum withdrawal is {{valor}}.',
                acimaDoDisponivel: 'Amount above what is available to withdraw ({{valor}}).',
            },
            situacao: { emAndamento: 'In progress', concluido: 'Sent to Pix', falhou: 'Not completed' },
            acompanhamento: {
                titulo: 'Pix withdrawal',
                enviando: 'Sending…',
                enviandoDica: 'Your request was accepted. The Pix usually arrives in a few seconds.',
                demorando: 'Still processing',
                demorandoDica: 'The bank has not confirmed yet. You will get a notification when the Pix arrives.',
                consultarDeNovo: 'Check again',
                concluido: 'Sent to your Pix ✓',
                concluidoDica: 'Check your bank app.',
                falhou: 'Not completed; the amount went back to your balance',
                motivo: 'Reason: {{motivo}}',
                taxa: 'Fee of {{valor}} taken from your balance',
                verComprovante: 'See receipt',
                erroAoAbrirComprovante: 'Could not open the receipt.',
                pronto: 'Done',
                voltarAoInicio: 'Back to Home',
            },
        },
    },
};
for (const lingua of ['pt', 'en']) {
    const arquivo = `translations/${lingua}.json`;
    const bruto = fs.readFileSync(arquivo, 'utf8');
    const fimDeLinha = bruto.includes('\r\n') ? '\r\n' : '\n';
    const json = JSON.parse(bruto);
    if (json.Saque) throw new Error(`${arquivo}: o grupo Saque já existe`);
    const novos = textos[lingua];
    json.Saque = novos.Saque;
    json.Extrato.tipos.saquePix = novos.saquePix;
    for (const [chave, texto] of Object.entries(novos.Recibo)) {
        if (json.Recibo[chave] !== undefined) throw new Error(`${arquivo}: Recibo.${chave} já existe`);
        json.Recibo[chave] = texto;
    }
    fs.writeFileSync(arquivo, (JSON.stringify(json, null, 4) + '\n').replace(/\n/g, fimDeLinha));
    console.log(arquivo, 'ok');
}
FIM
```

Esperado: `translations/pt.json ok` e `translations/en.json ok`.

- [ ] **Passo 2: só acréscimos**

```bash
cd /c/tmp/saque-app && git diff --numstat translations/
```

Esperado: nas duas linhas, a segunda coluna (linhas removidas) em `2` (no máximo `3`): só as linhas que ganham vírgula (o
`"outro"` de `Extrato.tipos`, o `"fechar"` do `Recibo` e, conforme o alinhamento do diff, o `}` que fecha o `Carteira`).
Se aparecer mais remoção, o arquivo foi reformatado: `git checkout translations/` e confira o fim de linha. (Conferido
num ensaio em 2026-10-26: 2 remoções em cada arquivo.)

- [ ] **Passo 3: commit**

```bash
cd /c/tmp/saque-app && git rev-parse --show-toplevel && git add translations/pt.json translations/en.json && git commit -m "Saque Pix: textos do saque, do acompanhamento, do extrato e do recibo (pt e en)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 7: módulo nativo do desbloqueio do aparelho

**Arquivos:**
- Criar: `android/app/src/main/java/io/fleetbase/navigator/DesbloqueioDoAparelhoModule.kt`
- Modificar: `android/app/src/main/java/io/fleetbase/navigator/AlertaPedidoPackage.kt`
- Modificar: `android/app/build.gradle`
- Criar: `src/utils/desbloqueio-do-aparelho.ts`

O Kotlin só compila no Actions (Tarefa 14). A regra que dá para testar (`resultadoDoDesbloqueio`) já está na Tarefa 3.

- [ ] **Passo 1: o módulo**

Crie `android/app/src/main/java/io/fleetbase/navigator/DesbloqueioDoAparelhoModule.kt`:

```kotlin
package io.fleetbase.navigator

import android.app.KeyguardManager
import android.content.Context
import android.os.Handler
import android.os.Looper
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import com.facebook.react.bridge.Promise
import com.facebook.react.bridge.ReactApplicationContext
import com.facebook.react.bridge.ReactContextBaseJavaModule
import com.facebook.react.bridge.ReactMethod
import java.util.concurrent.atomic.AtomicBoolean

/**
 * Entregas: desbloqueio do aparelho antes do saque Pix (spec 2026-10-26, no Delivery).
 * - estaProtegido: o celular tem bloqueio de tela (PIN, padrão ou senha)? KeyguardManager.isDeviceSecure. Sem bloqueio,
 *   o app não deixa sacar ("Ative a senha de tela do celular para sacar.").
 * - pedir: o BiometricPrompt do androidx com BIOMETRIC_WEAK | DEVICE_CREDENTIAL (digital ou rosto e, sempre, o PIN, o
 *   padrão ou a senha do celular). A combinação vale do Android 7 (minSdk 24) em diante; antes do Android 11 o androidx
 *   usa a tela de confirmação de credencial do próprio sistema. Responde uma vez: "ok", "cancelado" (o motoboy fechou),
 *   "sem_bloqueio" ou "erro".
 * Não usa o react-native-keychain: ele só aceita o PIN a partir do Android 11 e só biometria forte (ver o plano
 * docs/superpowers/plans/2026-10-26-saque-pix-asaas-app.md, no Delivery). A digital que não confere não responde nada:
 * o próprio diálogo deixa tentar de novo.
 */
class DesbloqueioDoAparelhoModule(private val contexto: ReactApplicationContext) : ReactContextBaseJavaModule(contexto) {

  override fun getName(): String = "DesbloqueioDoAparelho"

  private fun protegido(): Boolean {
    val teclado = contexto.getSystemService(Context.KEYGUARD_SERVICE) as? KeyguardManager ?: return false
    return teclado.isDeviceSecure
  }

  @ReactMethod
  fun estaProtegido(promise: Promise) {
    promise.resolve(protegido())
  }

  @ReactMethod
  fun pedir(titulo: String, subtitulo: String, promise: Promise) {
    if (!protegido()) {
      promise.resolve("sem_bloqueio")
      return
    }
    val atividade = contexto.currentActivity as? FragmentActivity
    if (atividade == null || atividade.isFinishing) {
      promise.resolve("erro")
      return
    }
    val respondeu = AtomicBoolean(false)
    fun responder(resultado: String) {
      if (respondeu.compareAndSet(false, true)) promise.resolve(resultado)
    }
    Handler(Looper.getMainLooper()).post {
      try {
        val retorno = object : BiometricPrompt.AuthenticationCallback() {
          override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) {
            responder("ok")
          }

          override fun onAuthenticationError(codigo: Int, mensagem: CharSequence) {
            responder(
              when (codigo) {
                BiometricPrompt.ERROR_USER_CANCELED,
                BiometricPrompt.ERROR_NEGATIVE_BUTTON,
                BiometricPrompt.ERROR_CANCELED -> "cancelado"
                BiometricPrompt.ERROR_NO_DEVICE_CREDENTIAL -> "sem_bloqueio"
                else -> "erro"
              }
            )
          }
        }
        val dialogo = BiometricPrompt.PromptInfo.Builder()
          .setTitle(titulo)
          .setSubtitle(subtitulo)
          .setAllowedAuthenticators(BiometricManager.Authenticators.BIOMETRIC_WEAK or BiometricManager.Authenticators.DEVICE_CREDENTIAL)
          .setConfirmationRequired(false)
          .build()
        BiometricPrompt(atividade, ContextCompat.getMainExecutor(atividade), retorno).authenticate(dialogo)
      } catch (erro: Exception) {
        responder("erro")
      }
    }
  }
}
```

- [ ] **Passo 2: registrar no pacote**

Em `android/app/src/main/java/io/fleetbase/navigator/AlertaPedidoPackage.kt`, troque:

```kotlin
  override fun createNativeModules(contexto: ReactApplicationContext): List<NativeModule> = listOf(AlertaPedidoModule(contexto))
```

por:

```kotlin
  // Entregas: o alarme de pedido e o desbloqueio do aparelho do saque Pix (o pacote já é registrado no MainApplication)
  override fun createNativeModules(contexto: ReactApplicationContext): List<NativeModule> =
    listOf(AlertaPedidoModule(contexto), DesbloqueioDoAparelhoModule(contexto))
```

- [ ] **Passo 3: a dependência**

Em `android/app/build.gradle`, troque:

```gradle
    implementation 'com.google.android.gms:play-services-maps:18.2.0'
```

por:

```gradle
    implementation 'com.google.android.gms:play-services-maps:18.2.0'

    // Entregas: desbloqueio do aparelho antes do saque Pix (DesbloqueioDoAparelhoModule: BiometricPrompt com digital,
    // rosto ou a senha de tela). A mesma versão que o react-native-keychain já põe no APK; a dele não chega ao código do app
    implementation 'androidx.biometric:biometric:1.1.0'
```

(O `androidx.biometric` declara as permissões `USE_BIOMETRIC` e `USE_FINGERPRINT` no manifesto dele; não precisa mexer no
`AndroidManifest.xml` do app.)

- [ ] **Passo 4: a ponte JS**

Crie `src/utils/desbloqueio-do-aparelho.ts`:

```ts
import { Linking, NativeModules, Platform } from 'react-native';
import { resultadoDoDesbloqueio } from './saque';
import type { ResultadoDoDesbloqueio } from './saque';

// Entregas: o desbloqueio do aparelho antes do saque Pix (digital, rosto, PIN, padrão ou senha), pelo módulo nativo
// DesbloqueioDoAparelho (Android: KeyguardManager e BiometricPrompt do androidx). Fora do Android ou sem o módulo, o
// celular conta como sem bloqueio e o pedido dá erro: o saque não sai.
const modulo = Platform.OS === 'android' ? NativeModules.DesbloqueioDoAparelho : null;

/** O celular tem bloqueio de tela (PIN, padrão ou senha)? Sem ele, o app não deixa sacar. */
export const aparelhoProtegido = async (): Promise<boolean> => {
    try {
        return modulo?.estaProtegido ? (await modulo.estaProtegido()) === true : false;
    } catch (erro) {
        console.warn('[saque] bloqueio de tela não conferido:', erro);
        return false;
    }
};

/** Pede o desbloqueio (o diálogo do sistema): "ok", "cancelado", "sem_bloqueio" ou "erro". */
export const pedirDesbloqueio = async (titulo: string, subtitulo: string): Promise<ResultadoDoDesbloqueio> => {
    if (!modulo?.pedir) return 'erro';
    try {
        return resultadoDoDesbloqueio(await modulo.pedir(titulo, subtitulo));
    } catch (erro) {
        console.warn('[saque] desbloqueio falhou:', erro);
        return 'erro';
    }
};

/** Abre a tela de segurança do Android (bloqueio de tela); se o aparelho não a tiver, as configurações do app. */
export const abrirConfiguracaoDeSeguranca = async (): Promise<void> => {
    try {
        await Linking.sendIntent('android.settings.SECURITY_SETTINGS');
    } catch {
        await Linking.openSettings();
    }
};
```

- [ ] **Passo 5: `tsc` sem erro novo**

```bash
bash /c/tmp/sa-tsc.sh
```

Esperado: `tsc: nenhum erro novo`.

- [ ] **Passo 6: commit**

```bash
cd /c/tmp/saque-app && git rev-parse --show-toplevel && git add android/app/src/main/java/io/fleetbase/navigator/DesbloqueioDoAparelhoModule.kt android/app/src/main/java/io/fleetbase/navigator/AlertaPedidoPackage.kt android/app/build.gradle src/utils/desbloqueio-do-aparelho.ts && git commit -m "Saque Pix: módulo nativo do desbloqueio do aparelho (digital, rosto ou senha de tela)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 8: tela Sacar e a rota

**Arquivos:**
- Criar: `src/screens/SacarScreen.tsx`
- Modificar: `src/navigation/DriverNavigator.tsx`

- [ ] **Passo 1: a tela**

Crie `src/screens/SacarScreen.tsx`:

```tsx
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Pressable, ScrollView, TextInput } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Text, XStack, YStack, useTheme } from 'tamagui';
import { FontAwesomeIcon } from '@fortawesome/react-native-fontawesome';
import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { faArrowLeft, faGear, faLock, faRotateRight, faTriangleExclamation } from '@fortawesome/free-solid-svg-icons';
import { faPix } from '@fortawesome/free-brands-svg-icons';
import { useLanguage } from '../contexts/LanguageContext';
import useFleetbase from '../hooks/use-fleetbase';
import useEspacoDoTeclado from '../hooks/use-espaco-do-teclado';
import { formatarReais } from '../utils/ganhos';
import {
    botaoDoSaque,
    centavos,
    centavosDoCampo,
    chaveEscondida,
    conferirValor,
    lerBlocoDoSaque,
    lerSaque,
    mensagemDaFalha,
    novoUuid,
    resumoDoSaque,
    rotuloDoTipoDaChave,
    textoDoCampo,
    uuidDoEnvio,
} from '../utils/saque';
import type { BlocoDoSaque, TextoDoSaque } from '../utils/saque';
import { abrirConfiguracaoDeSeguranca, aparelhoProtegido, pedirDesbloqueio } from '../utils/desbloqueio-do-aparelho';
import SafeSpinner from '../components/SafeSpinner';

// Entregas: tela Sacar do saque Pix pelo app (spec docs/superpowers/specs/2026-10-26-saque-pix-asaas-design.md, no
// Delivery). Aberta pelo botão "Sacar via Pix" da Minha carteira (rota `Sacar` na pilha do Início, sem a barra de abas).
// Relê a carteira ao abrir (o disponível muda a cada corrida): sem o bloco `saque`, ou com o motivo do servidor,
// indisponível; com um saque em andamento, troca pela tela de acompanhamento. O motoboy digita o valor (ou "Tudo") e vê o
// que cai no Pix, a taxa (sai do saldo, à parte) e a chave Pix escondida com o tipo. "Confirmar saque" confere o valor,
// exige bloqueio de tela, pede o desbloqueio (digital, rosto ou PIN; DesbloqueioDoAparelho) e manda
// POST v1/entregas/motoboy/saques {uuid, valor}. O uuid é gerado aqui e reaproveitado até o servidor responder com o
// saque: Confirmar de novo depois de a rede cair não cria outro saque.

type Dados = { bloco: BlocoDoSaque | null; pix: { chave: string; tipo: string } | null };

const SacarScreen = () => {
    const theme = useTheme();
    const navigation = useNavigation<any>();
    const insets = useSafeAreaInsets();
    const { t } = useLanguage();
    const { adapter } = useFleetbase();
    const adapterRef = useRef(adapter);
    adapterRef.current = adapter;
    const { ref: areaRef, espaco } = useEspacoDoTeclado();
    const [dados, setDados] = useState<Dados | null>(null);
    const [erro, setErro] = useState(false);
    const [valor, setValor] = useState(0);
    const [aviso, setAviso] = useState<TextoDoSaque | null>(null);
    const [semBloqueio, setSemBloqueio] = useState(false);
    const [enviando, setEnviando] = useState(false);
    // uuid do envio que ainda não voltou com o saque (a chave de idempotência do servidor)
    const pendenteRef = useRef<string | null>(null);
    // trava contra dois toques seguidos em Confirmar (o desbloqueio leva alguns segundos)
    const ocupadoRef = useRef(false);
    const carregouRef = useRef(false);

    const carregar = useCallback(async () => {
        const api = adapterRef.current;
        if (!api) return;
        setErro(false);
        setDados(null);
        try {
            const resposta = await api.get('entregas/motoboy/carteira');
            if (!resposta || typeof resposta.a_receber !== 'number') throw new Error('resposta da carteira fora do formato');
            const bloco = lerBlocoDoSaque(resposta.saque);
            if (bloco?.em_andamento) {
                navigation.replace('AcompanharSaque', { id: bloco.em_andamento.id, saque: bloco.em_andamento });
                return;
            }
            const pix = resposta.pix && typeof resposta.pix.chave === 'string' ? { chave: resposta.pix.chave, tipo: String(resposta.pix.tipo ?? '') } : null;
            setDados({ bloco, pix });
        } catch (falha) {
            console.warn('[saque] erro ao carregar a carteira:', falha);
            setErro(true);
        }
    }, [navigation]);

    // o adapter do useFleetbase chega (e troca de instância) logo depois de montar: carrega uma vez, quando ele existir
    useEffect(() => {
        if (!adapter || carregouRef.current) return;
        carregouRef.current = true;
        carregar();
    }, [adapter, carregar]);

    const voltar = () => {
        if (navigation.canGoBack()) navigation.goBack();
        else navigation.navigate('DriverDashboard');
    };

    const bloco = dados?.bloco ?? null;
    const podeSacar = botaoDoSaque(bloco).modo === 'sacar';
    const resumo = resumoDoSaque(valor, bloco?.taxa ?? 0);
    const textoDe = (texto: TextoDoSaque) => ('chave' in texto ? t(texto.chave, texto.valores) : texto.texto);

    const mudarValor = (texto: string) => {
        setValor(centavosDoCampo(texto));
        setAviso(null);
    };

    const usarTudo = () => {
        if (!bloco) return;
        setValor(centavos(bloco.disponivel));
        setAviso(null);
    };

    const pedirSaque = async () => {
        if (!bloco) return;
        const problema = conferirValor(valor, bloco, formatarReais);
        if (problema) {
            setAviso(problema);
            return;
        }
        setAviso(null);
        // celular sem bloqueio de tela não saca (spec): quem pega o celular destravado não tira o dinheiro
        if (!(await aparelhoProtegido())) {
            setSemBloqueio(true);
            return;
        }
        setSemBloqueio(false);
        const desbloqueio = await pedirDesbloqueio(t('Saque.desbloqueioTitulo'), t('Saque.desbloqueioSubtitulo', { valor: formatarReais(valor / 100) }));
        if (desbloqueio === 'cancelado') return;
        if (desbloqueio === 'sem_bloqueio') {
            setSemBloqueio(true);
            return;
        }
        if (desbloqueio !== 'ok') {
            setAviso({ chave: 'Saque.desbloqueioFalhou' });
            return;
        }
        const api = adapterRef.current;
        if (!api) {
            setAviso({ chave: 'Saque.semResposta' });
            return;
        }
        const uuid = uuidDoEnvio(pendenteRef.current, () => novoUuid(globalThis.crypto.getRandomValues(new Uint8Array(16))));
        pendenteRef.current = uuid;
        setEnviando(true);
        try {
            const resposta = await api.post('entregas/motoboy/saques', { uuid, valor: valor / 100 });
            const saque = lerSaque(resposta?.saque);
            if (!saque) {
                // 2xx sem o saque no formato: não dá para saber se saiu; o próximo Confirmar manda o mesmo uuid
                setAviso({ chave: 'Saque.semResposta' });
                return;
            }
            pendenteRef.current = null;
            navigation.replace('AcompanharSaque', { id: saque.id, saque });
        } catch (falha) {
            // qualquer falha mantém o uuid: depois de um 422 o servidor não gravou nada; depois de uma queda, devolve o saque
            console.warn('[saque] pedido sem confirmação:', falha);
            setAviso(mensagemDaFalha(falha));
        } finally {
            setEnviando(false);
        }
    };

    const confirmar = async () => {
        if (ocupadoRef.current) return;
        ocupadoRef.current = true;
        try {
            await pedirSaque();
        } finally {
            ocupadoRef.current = false;
        }
    };

    const botaoContorno = (texto: string, aoTocar: () => void, icone: IconDefinition = faRotateRight) => (
        <Pressable onPress={aoTocar} accessibilityRole='button'>
            {({ pressed }) => (
                <XStack height={48} px='$4' borderRadius={12} borderWidth={1.5} borderColor='$borderColor' alignItems='center' justifyContent='center' gap='$2' bg={pressed ? '$surface' : '$cartao'}>
                    <FontAwesomeIcon icon={icone} color={theme['$textPrimary'].val} size={14} />
                    <Text color='$textPrimary' fontWeight='700' fontSize={15}>
                        {texto}
                    </Text>
                </XStack>
            )}
        </Pressable>
    );

    const linhaDoResumo = (rotulo: string, valorDaLinha: string, forte = false) => (
        <XStack justifyContent='space-between' alignItems='center' py='$2.5' gap='$3'>
            <Text color={forte ? '$textPrimary' : '$textSecondary'} fontSize={14} fontWeight={forte ? '700' : '400'} flexShrink={1}>
                {rotulo}
            </Text>
            <Text color='$textPrimary' fontFamily='$heading' fontWeight='700' fontSize={forte ? 20 : 16}>
                {valorDaLinha}
            </Text>
        </XStack>
    );

    const topo = (
        <YStack bg='$primary' pt={insets.top + 8} pb='$4' px='$4'>
            <XStack alignItems='center' gap='$2'>
                <Pressable onPress={voltar} hitSlop={10} accessibilityRole='button' accessibilityLabel={t('Saque.voltar')}>
                    <YStack width={44} height={44} borderRadius={22} alignItems='center' justifyContent='center'>
                        <FontAwesomeIcon icon={faArrowLeft} color={theme['$sobrePrimario'].val} size={20} />
                    </YStack>
                </Pressable>
                <Text color='$sobrePrimario' fontFamily='$heading' fontWeight='800' fontSize={24} numberOfLines={1} flexShrink={1}>
                    {t('Saque.titulo')}
                </Text>
            </XStack>
        </YStack>
    );

    const centro = (conteudo: ReactNode) => (
        <YStack flex={1} bg='$background'>
            {topo}
            <YStack flex={1} alignItems='center' justifyContent='center' gap='$3' px='$6'>
                {conteudo}
            </YStack>
        </YStack>
    );

    if (erro) {
        return centro(
            <>
                <FontAwesomeIcon icon={faTriangleExclamation} color={theme['$error'].val} size={28} />
                <Text color='$textPrimary' fontFamily='$heading' fontWeight='700' fontSize={20} textAlign='center'>
                    {t('Saque.erroAoCarregar')}
                </Text>
                <XStack gap='$2'>
                    {botaoContorno(t('Saque.tentarDeNovo'), carregar)}
                    {botaoContorno(t('Saque.voltar'), voltar, faArrowLeft)}
                </XStack>
            </>
        );
    }

    if (!dados) {
        return centro(
            <>
                <SafeSpinner color={theme['$primary'].val} />
                <Text color='$textSecondary' fontSize={14}>
                    {t('Saque.carregando')}
                </Text>
            </>
        );
    }

    if (!bloco || !podeSacar) {
        return centro(
            <>
                <FontAwesomeIcon icon={faPix} color={theme['$textSecondary'].val} size={32} />
                <Text color='$textPrimary' fontFamily='$heading' fontWeight='700' fontSize={20} textAlign='center'>
                    {bloco?.indisponivel_motivo ?? t('Saque.indisponivel')}
                </Text>
                {botaoContorno(t('Saque.voltar'), voltar, faArrowLeft)}
            </>
        );
    }

    const chave = dados.pix ? chaveEscondida(dados.pix.chave, dados.pix.tipo) : '';
    const semValor = valor <= 0;

    return (
        <YStack ref={areaRef} flex={1} bg='$background' pb={Math.max(espaco, insets.bottom) + 12}>
            {topo}
            <ScrollView keyboardShouldPersistTaps='handled' contentContainerStyle={{ padding: 16, gap: 16 }}>
                <YStack bg='$cartao' borderRadius={16} borderWidth={1} borderColor='$borderColor' p='$4' gap='$1'>
                    <Text color='$textSecondary' fontSize={12} fontWeight='700' letterSpacing={0.6} textTransform='uppercase'>
                        {t('Saque.disponivel')}
                    </Text>
                    <Text color='$textPrimary' fontFamily='$heading' fontWeight='800' fontSize={40} lineHeight={46}>
                        {formatarReais(bloco.disponivel)}
                    </Text>
                    <Text color='$textSecondary' fontSize={13}>
                        {t('Saque.minimo', { valor: formatarReais(bloco.minimo) })}
                    </Text>
                </YStack>

                <YStack gap='$2'>
                    <Text color='$textPrimary' fontFamily='$heading' fontWeight='700' fontSize={18}>
                        {t('Saque.valor')}
                    </Text>
                    <XStack alignItems='center' gap='$2'>
                        <TextInput
                            value={textoDoCampo(valor, formatarReais)}
                            onChangeText={mudarValor}
                            keyboardType='number-pad'
                            returnKeyType='done'
                            editable={!enviando}
                            placeholder={formatarReais(0)}
                            placeholderTextColor={theme['$textPlaceholder'].val}
                            accessibilityLabel={t('Saque.valor')}
                            style={{
                                flex: 1,
                                fontSize: 32,
                                fontWeight: '800',
                                color: theme['$textPrimary'].val,
                                backgroundColor: theme['$cartao'].val,
                                borderWidth: 2,
                                borderColor: aviso ? theme['$error'].val : theme['$borderColor'].val,
                                borderRadius: 16,
                                paddingVertical: 12,
                                paddingHorizontal: 16,
                            }}
                        />
                        <Pressable onPress={usarTudo} disabled={enviando} accessibilityRole='button'>
                            {({ pressed }) => (
                                <YStack height={64} px='$4' borderRadius={16} borderWidth={1.5} borderColor='$primary' alignItems='center' justifyContent='center' bg={pressed ? '$primarioSuave' : '$cartao'}>
                                    <Text color='$tintaPrimaria' fontWeight='800' fontSize={16}>
                                        {t('Saque.tudo')}
                                    </Text>
                                </YStack>
                            )}
                        </Pressable>
                    </XStack>
                </YStack>

                <YStack bg='$cartao' borderRadius={16} borderWidth={1} borderColor='$borderColor' px='$4' py='$2'>
                    {linhaDoResumo(t('Saque.caiNoPix'), formatarReais(resumo.caiNoPix))}
                    {linhaDoResumo(t('Saque.taxa'), resumo.taxa > 0 ? formatarReais(resumo.taxa) : t('Saque.semTaxa'))}
                    <YStack height={1} bg='$borderColor' />
                    {linhaDoResumo(t('Saque.saiDoSaldo'), formatarReais(resumo.saiDoSaldo), true)}
                </YStack>

                <XStack bg='$cartao' borderRadius={16} borderWidth={1} borderColor='$borderColor' p='$4' gap='$3' alignItems='center'>
                    <YStack width={44} height={44} borderRadius={12} bg='$primarioSuave' alignItems='center' justifyContent='center'>
                        <FontAwesomeIcon icon={faPix} color={theme['$primary'].val} size={20} />
                    </YStack>
                    <YStack flex={1} minWidth={0} gap={2}>
                        <Text color='$textSecondary' fontSize={12}>
                            {t('Saque.para')}
                        </Text>
                        <Text color='$textPrimary' fontSize={16} fontWeight='700' numberOfLines={1}>
                            {`${t(rotuloDoTipoDaChave(dados.pix?.tipo))} · ${chave || '—'}`}
                        </Text>
                        <Text color='$textSecondary' fontSize={12}>
                            {t('Saque.chaveDica')}
                        </Text>
                    </YStack>
                </XStack>

                {!!aviso && (
                    <XStack bg='$perigoSuave' borderRadius='$5' px='$3' py='$3' gap='$2' alignItems='flex-start'>
                        <FontAwesomeIcon icon={faTriangleExclamation} color={theme['$error'].val} size={16} style={{ marginTop: 2 }} />
                        <Text color='$error' fontWeight='700' fontSize={15} lineHeight={21} flex={1}>
                            {textoDe(aviso)}
                        </Text>
                    </XStack>
                )}
                {semBloqueio && (
                    <YStack bg='$avisoSuave' borderRadius='$5' px='$3' py='$3' gap='$3'>
                        <XStack gap='$2' alignItems='flex-start'>
                            <FontAwesomeIcon icon={faLock} color={theme['$tintaAviso'].val} size={16} style={{ marginTop: 2 }} />
                            <Text color='$tintaAviso' fontWeight='700' fontSize={15} lineHeight={21} flex={1}>
                                {t('Saque.semBloqueio')}
                            </Text>
                        </XStack>
                        {botaoContorno(t('Saque.abrirConfiguracoes'), abrirConfiguracaoDeSeguranca, faGear)}
                    </YStack>
                )}
            </ScrollView>

            <YStack px='$4' pt='$2'>
                <Pressable onPress={confirmar} disabled={enviando || semValor} accessibilityRole='button' accessibilityState={{ disabled: enviando || semValor }}>
                    {({ pressed }) => (
                        <XStack height={60} borderRadius={16} bg='$primary' alignItems='center' justifyContent='center' gap='$2' opacity={semValor ? 0.4 : pressed ? 0.85 : 1}>
                            {enviando ? <SafeSpinner color={theme['$sobrePrimario'].val} /> : <FontAwesomeIcon icon={faLock} color={theme['$sobrePrimario'].val} size={16} />}
                            <Text color='$sobrePrimario' fontFamily='$heading' fontWeight='700' fontSize={20}>
                                {t(enviando ? 'Saque.enviando' : 'Saque.confirmar')}
                            </Text>
                        </XStack>
                    )}
                </Pressable>
            </YStack>
        </YStack>
    );
};

export default SacarScreen;
```

- [ ] **Passo 2: a rota**

Em `src/navigation/DriverNavigator.tsx`:

1. Troque:

```tsx
import ReciboScreen from '../screens/ReciboScreen';
```

por:

```tsx
import ReciboScreen from '../screens/ReciboScreen';
import SacarScreen from '../screens/SacarScreen';
```

2. Nas `options` da `DriverDashboardTab` (dentro de `createTabScreens`), troque:

```tsx
                // Entregas: o recibo do pagamento (Financeiro) desenha o próprio topo laranja, com o voltar
                const recibo = foco === 'Recibo';
                return {
                    tabBarLabel: rotuloDaAba('DriverDashboardTab'),
                    headerShown: foco !== 'DriverDashboard' && !telaDoPedido && !recibo,
                    ...(telaDoPedido ? { tabBarStyle: { display: 'none' as const } } : {}),
                };
```

por:

```tsx
                // Entregas: o recibo do pagamento (Financeiro) e as telas do saque Pix desenham o próprio topo laranja,
                // com o voltar
                const topoProprio = foco === 'Recibo' || foco === 'Sacar' || foco === 'AcompanharSaque';
                // a tela Sacar tem o Confirmar embaixo, junto do teclado: sem a barra de abas
                const semAbas = telaDoPedido || foco === 'Sacar';
                return {
                    tabBarLabel: rotuloDaAba('DriverDashboardTab'),
                    headerShown: foco !== 'DriverDashboard' && !telaDoPedido && !topoProprio,
                    ...(semAbas ? { tabBarStyle: { display: 'none' as const } } : {}),
                };
```

3. Na pilha `DriverDashboardTab`, troque:

```tsx
        // Entregas: recibo de um pagamento (toque no extrato ou push "Pagamento registrado"); param `id` = uuid do pagamento
        Recibo: {
            screen: ReciboScreen,
            options: ({ route, navigation }) => {
                return {
                    headerShown: false,
                };
            },
        },
    },
});
```

por:

```tsx
        // Entregas: recibo de um pagamento (toque no extrato ou push "Pagamento registrado"); param `id` = uuid do pagamento
        Recibo: {
            screen: ReciboScreen,
            options: ({ route, navigation }) => {
                return {
                    headerShown: false,
                };
            },
        },
        // Entregas: saque Pix pelo app (botão "Sacar via Pix" da Minha carteira)
        Sacar: {
            screen: SacarScreen,
            options: ({ route, navigation }) => {
                return {
                    headerShown: false,
                };
            },
        },
    },
});
```

- [ ] **Passo 3: `tsc` sem erro novo**

```bash
bash /c/tmp/sa-tsc.sh
```

Esperado: `tsc: nenhum erro novo`. Se aparecer erro novo de tipo do Tamagui (ex.: `fontWeight` com variável), troque a
prop por dois `<Text>` fixos em vez de mexer no tipo, e rode de novo.

- [ ] **Passo 4: commit**

```bash
cd /c/tmp/saque-app && git rev-parse --show-toplevel && git add src/screens/SacarScreen.tsx src/navigation/DriverNavigator.tsx && git commit -m "Saque Pix: tela Sacar (valor, Tudo, resumo, chave escondida e desbloqueio do aparelho)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 9: tela de acompanhamento e a rota

**Arquivos:**
- Criar: `src/screens/AcompanharSaqueScreen.tsx`
- Modificar: `src/navigation/DriverNavigator.tsx`

- [ ] **Passo 1: a tela**

Crie `src/screens/AcompanharSaqueScreen.tsx`:

```tsx
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Linking, Pressable, ScrollView } from 'react-native';
import { useNavigation, useRoute } from '@react-navigation/native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { EventRegister } from 'react-native-event-listeners';
import { Text, XStack, YStack, useTheme } from 'tamagui';
import { FontAwesomeIcon } from '@fortawesome/react-native-fontawesome';
import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { faArrowLeft, faCircleCheck, faCircleXmark, faClock, faRotateRight, faTriangleExclamation, faUpRightFromSquare } from '@fortawesome/free-solid-svg-icons';
import { faPix } from '@fortawesome/free-brands-svg-icons';
import { useLanguage } from '../contexts/LanguageContext';
import useFleetbase from '../hooks/use-fleetbase';
import useRessincronizar from '../hooks/use-ressincronizar';
import { toast } from '../utils/toast';
import { formatarReais } from '../utils/ganhos';
import { EVENTO_DO_SAQUE, INTERVALO_DA_CONSULTA_MS, consultaDeNovo, estadoDoAcompanhamento, lerSaque, linkDoComprovante, rotuloDoTipoDaChave } from '../utils/saque';
import type { SaqueDoApp } from '../utils/saque';
import SafeSpinner from '../components/SafeSpinner';

// Entregas: acompanhamento do saque Pix (rota `AcompanharSaque` na pilha do Início; params `id` = uuid e, vindo da tela
// Sacar, `saque` = a resposta do pedido). Consulta GET v1/entregas/motoboy/saques/{uuid} a cada 3 s por até 1 min:
// "Enviando…" → "Caiu no seu Pix ✓" (com o comprovante do Asaas) ou "Não concluído; o valor voltou ao saldo" (com o
// motivo). Depois de 1 min, "Ainda processando" com "Consultar de novo": o push do saque avisa quando terminar. Relê ao
// voltar para a frente e quando o push do saque chega com o app aberto (EVENTO_DO_SAQUE, emitido pelo DriverLayout).

// a resposta só vale se for do saque desta tela (um push pode trocar o `id` com a tela aberta)
const doMesmoSaque = (saque: SaqueDoApp | null, id: string | null): SaqueDoApp | null => (saque && id && saque.id.toLowerCase() === id ? saque : null);

const AcompanharSaqueScreen = () => {
    const theme = useTheme();
    const navigation = useNavigation<any>();
    const route = useRoute();
    const insets = useSafeAreaInsets();
    const { t } = useLanguage();
    const { adapter } = useFleetbase();
    const adapterRef = useRef(adapter);
    adapterRef.current = adapter;
    const params = route.params as { id?: string; saque?: unknown } | undefined;
    const id = typeof params?.id === 'string' && params.id.trim() !== '' ? params.id.trim().toLowerCase() : null;
    const idRef = useRef(id);
    idRef.current = id;
    const [saque, setSaque] = useState<SaqueDoApp | null>(() => doMesmoSaque(lerSaque(params?.saque), id));
    const [desde, setDesde] = useState(() => Date.now());
    const [agora, setAgora] = useState(() => Date.now());
    const consultouRef = useRef(false);
    const estado = estadoDoAcompanhamento(saque, desde, agora);

    const consultar = useCallback(async () => {
        const pedido = idRef.current;
        consultouRef.current = true;
        try {
            const api = adapterRef.current;
            if (!api || !pedido) throw new Error('sem sessão ou sem o saque');
            const resposta = await api.get(`entregas/motoboy/saques/${pedido}`);
            const lido = doMesmoSaque(lerSaque(resposta?.saque), idRef.current);
            if (lido) setSaque(lido);
        } catch (falha) {
            console.warn('[saque] erro ao consultar:', falha);
        } finally {
            setAgora(Date.now());
        }
    }, []);

    // outro saque na mesma tela (push tocado com o acompanhamento aberto): recomeça do zero
    const idDaTelaRef = useRef(id);
    useEffect(() => {
        if (idDaTelaRef.current === id) return;
        idDaTelaRef.current = id;
        consultouRef.current = false;
        setSaque(null);
        const inicio = Date.now();
        setDesde(inicio);
        setAgora(inicio);
    }, [id]);

    // a cada 3 s enquanto está enviando, por até 1 min; aberto pelo push (sem o saque), a primeira consulta sai na hora
    useEffect(() => {
        if (!adapter || !consultaDeNovo(estado)) return;
        const espera = setTimeout(consultar, consultouRef.current || saque ? INTERVALO_DA_CONSULTA_MS : 0);
        return () => clearTimeout(espera);
    }, [adapter, estado, agora, saque, consultar]);

    const recomecar = useCallback(() => {
        const inicio = Date.now();
        setDesde(inicio);
        setAgora(inicio);
        consultar();
    }, [consultar]);

    // o push do saque chegou com o app na frente (DriverLayout): relê na hora
    useEffect(() => {
        const ouvinte = EventRegister.addEventListener(EVENTO_DO_SAQUE, (uuid: unknown) => {
            if (typeof uuid === 'string' && uuid.toLowerCase() === idRef.current) recomecar();
        });
        return () => {
            if (typeof ouvinte === 'string') EventRegister.removeEventListener(ouvinte);
        };
    }, [recomecar]);

    // de volta para a frente (o Android pausa o app em segundo plano): relê
    useRessincronizar(recomecar);

    const sair = () => navigation.popToTop();

    const abrirComprovante = async () => {
        const url = linkDoComprovante(saque?.comprovante_url);
        if (!url) return;
        try {
            await Linking.openURL(url);
        } catch (falha) {
            console.warn('[saque] erro ao abrir o comprovante:', falha);
            toast.error(t('Saque.acompanhamento.erroAoAbrirComprovante'));
        }
    };

    const botaoCheio = (texto: string, aoTocar: () => void, icone?: IconDefinition) => (
        <Pressable onPress={aoTocar} accessibilityRole='button'>
            {({ pressed }) => (
                <XStack height={56} borderRadius={16} bg='$primary' alignItems='center' justifyContent='center' gap='$2' opacity={pressed ? 0.85 : 1}>
                    {icone && <FontAwesomeIcon icon={icone} color={theme['$sobrePrimario'].val} size={16} />}
                    <Text color='$sobrePrimario' fontFamily='$heading' fontWeight='700' fontSize={19}>
                        {texto}
                    </Text>
                </XStack>
            )}
        </Pressable>
    );

    const botaoContorno = (texto: string, aoTocar: () => void, icone: IconDefinition = faArrowLeft) => (
        <Pressable onPress={aoTocar} accessibilityRole='button'>
            {({ pressed }) => (
                <XStack height={52} px='$4' borderRadius={14} borderWidth={1.5} borderColor='$borderColor' alignItems='center' justifyContent='center' gap='$2' bg={pressed ? '$surface' : '$cartao'}>
                    <FontAwesomeIcon icon={icone} color={theme['$textPrimary'].val} size={14} />
                    <Text color='$textPrimary' fontWeight='700' fontSize={15}>
                        {texto}
                    </Text>
                </XStack>
            )}
        </Pressable>
    );

    let conteudo: ReactNode;
    let rodape: ReactNode;
    if (!saque && estado !== 'carregando') {
        // 1 min sem conseguir ler (sem rede, ou saque que não é dele)
        conteudo = (
            <YStack flex={1} alignItems='center' justifyContent='center' gap='$3' px='$6'>
                <FontAwesomeIcon icon={faTriangleExclamation} color={theme['$error'].val} size={28} />
                <Text color='$textPrimary' fontFamily='$heading' fontWeight='700' fontSize={20} textAlign='center'>
                    {t('Saque.erroAoCarregar')}
                </Text>
            </YStack>
        );
        rodape = (
            <>
                {botaoCheio(t('Saque.tentarDeNovo'), recomecar, faRotateRight)}
                {botaoContorno(t('Saque.acompanhamento.voltarAoInicio'), sair)}
            </>
        );
    } else if (!saque) {
        conteudo = (
            <YStack flex={1} alignItems='center' justifyContent='center' gap='$3' px='$6'>
                <SafeSpinner color={theme['$primary'].val} />
                <Text color='$textSecondary' fontSize={14}>
                    {t('Saque.carregando')}
                </Text>
            </YStack>
        );
        rodape = botaoContorno(t('Saque.acompanhamento.voltarAoInicio'), sair);
    } else {
        const visuais: Record<string, { icone: IconDefinition | null; cor: string; titulo: string; dica: string | null }> = {
            carregando: { icone: null, cor: theme['$primary'].val, titulo: 'Saque.acompanhamento.enviando', dica: 'Saque.acompanhamento.enviandoDica' },
            enviando: { icone: null, cor: theme['$primary'].val, titulo: 'Saque.acompanhamento.enviando', dica: 'Saque.acompanhamento.enviandoDica' },
            demorando: { icone: faClock, cor: theme['$tintaAviso'].val, titulo: 'Saque.acompanhamento.demorando', dica: 'Saque.acompanhamento.demorandoDica' },
            concluido: { icone: faCircleCheck, cor: theme['$success'].val, titulo: 'Saque.acompanhamento.concluido', dica: 'Saque.acompanhamento.concluidoDica' },
            falhou: { icone: faCircleXmark, cor: theme['$error'].val, titulo: 'Saque.acompanhamento.falhou', dica: null },
        };
        const visual = visuais[estado];
        const destino = `${t(rotuloDoTipoDaChave(saque.tipo_chave))} · ${saque.chave || '—'}`;
        conteudo = (
            <YStack flex={1} alignItems='center' justifyContent='center' gap='$3' px='$6'>
                <YStack width={88} height={88} borderRadius={44} alignItems='center' justifyContent='center' bg='$surface'>
                    {visual.icone ? <FontAwesomeIcon icon={visual.icone} color={visual.cor} size={48} /> : <SafeSpinner color={visual.cor} />}
                </YStack>
                <Text color='$textPrimary' fontFamily='$heading' fontWeight='800' fontSize={28} textAlign='center'>
                    {t(visual.titulo)}
                </Text>
                <Text color='$textPrimary' fontFamily='$heading' fontWeight='800' fontSize={44} lineHeight={50} textDecorationLine={estado === 'falhou' ? 'line-through' : 'none'}>
                    {formatarReais(saque.valor)}
                </Text>
                <XStack alignItems='center' gap='$2'>
                    <FontAwesomeIcon icon={faPix} color={theme['$textSecondary'].val} size={14} />
                    <Text color='$textSecondary' fontSize={14} numberOfLines={1}>
                        {destino}
                    </Text>
                </XStack>
                {saque.taxa > 0 && estado !== 'falhou' && (
                    <Text color='$textSecondary' fontSize={13}>
                        {t('Saque.acompanhamento.taxa', { valor: formatarReais(saque.taxa) })}
                    </Text>
                )}
                {!!visual.dica && (
                    <Text color='$textSecondary' fontSize={15} lineHeight={21} textAlign='center'>
                        {t(visual.dica)}
                    </Text>
                )}
                {estado === 'falhou' && !!saque.motivo && (
                    <Text color='$error' fontSize={15} fontWeight='700' lineHeight={21} textAlign='center'>
                        {t('Saque.acompanhamento.motivo', { motivo: saque.motivo })}
                    </Text>
                )}
            </YStack>
        );
        if (estado === 'concluido') {
            rodape = (
                <>
                    {!!linkDoComprovante(saque.comprovante_url) && botaoContorno(t('Saque.acompanhamento.verComprovante'), abrirComprovante, faUpRightFromSquare)}
                    {botaoCheio(t('Saque.acompanhamento.pronto'), sair)}
                </>
            );
        } else if (estado === 'falhou') {
            rodape = botaoCheio(t('Saque.acompanhamento.voltarAoInicio'), sair);
        } else if (estado === 'demorando') {
            rodape = (
                <>
                    {botaoCheio(t('Saque.acompanhamento.consultarDeNovo'), recomecar, faRotateRight)}
                    {botaoContorno(t('Saque.acompanhamento.voltarAoInicio'), sair)}
                </>
            );
        } else {
            rodape = botaoContorno(t('Saque.acompanhamento.voltarAoInicio'), sair);
        }
    }

    return (
        <YStack flex={1} bg='$background'>
            <YStack bg='$primary' pt={insets.top + 8} pb='$4' px='$4'>
                <XStack alignItems='center' gap='$2'>
                    <Pressable onPress={sair} hitSlop={10} accessibilityRole='button' accessibilityLabel={t('Saque.acompanhamento.voltarAoInicio')}>
                        <YStack width={44} height={44} borderRadius={22} alignItems='center' justifyContent='center'>
                            <FontAwesomeIcon icon={faArrowLeft} color={theme['$sobrePrimario'].val} size={20} />
                        </YStack>
                    </Pressable>
                    <Text color='$sobrePrimario' fontFamily='$heading' fontWeight='800' fontSize={24} numberOfLines={1} flexShrink={1}>
                        {t('Saque.acompanhamento.titulo')}
                    </Text>
                </XStack>
            </YStack>
            <ScrollView contentContainerStyle={{ flexGrow: 1, paddingVertical: 24 }}>{conteudo}</ScrollView>
            <YStack px='$4' pt='$2' pb='$3' gap='$2'>
                {rodape}
            </YStack>
        </YStack>
    );
};

export default AcompanharSaqueScreen;
```

- [ ] **Passo 2: a rota**

Em `src/navigation/DriverNavigator.tsx`:

1. Troque:

```tsx
import SacarScreen from '../screens/SacarScreen';
```

por:

```tsx
import SacarScreen from '../screens/SacarScreen';
import AcompanharSaqueScreen from '../screens/AcompanharSaqueScreen';
```

2. Troque:

```tsx
        // Entregas: saque Pix pelo app (botão "Sacar via Pix" da Minha carteira)
        Sacar: {
            screen: SacarScreen,
            options: ({ route, navigation }) => {
                return {
                    headerShown: false,
                };
            },
        },
```

por:

```tsx
        // Entregas: saque Pix pelo app (botão "Sacar via Pix" da Minha carteira)
        Sacar: {
            screen: SacarScreen,
            options: ({ route, navigation }) => {
                return {
                    headerShown: false,
                };
            },
        },
        // Entregas: acompanhamento do saque Pix (depois do Sacar, pelo botão da carteira ou pelo push); params `id` e `saque`
        AcompanharSaque: {
            screen: AcompanharSaqueScreen,
            options: ({ route, navigation }) => {
                return {
                    headerShown: false,
                };
            },
        },
```

- [ ] **Passo 3: `tsc` sem erro novo**

```bash
bash /c/tmp/sa-tsc.sh
```

Esperado: `tsc: nenhum erro novo`.

- [ ] **Passo 4: commit**

```bash
cd /c/tmp/saque-app && git rev-parse --show-toplevel && git add src/screens/AcompanharSaqueScreen.tsx src/navigation/DriverNavigator.tsx && git commit -m "Saque Pix: tela de acompanhamento (consulta a cada 3 s por até 1 min)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 10: botão "Sacar via Pix" na Minha carteira

**Arquivos:**
- Modificar: `src/components/entregas/FolhaDaCarteira.tsx`
- Modificar: `src/screens/MeusGanhosScreen.tsx`

- [ ] **Passo 1: a folha**

Em `src/components/entregas/FolhaDaCarteira.tsx`:

1. Troque:

```tsx
import { faChevronRight, faRotateRight, faTimes, faTriangleExclamation, faWallet } from '@fortawesome/free-solid-svg-icons';
```

por:

```tsx
import { faChevronRight, faRotateRight, faTimes, faTriangleExclamation, faWallet } from '@fortawesome/free-solid-svg-icons';
import { faPix } from '@fortawesome/free-brands-svg-icons';
```

2. Troque:

```tsx
import type { Carteira } from '../../utils/extrato';
```

por:

```tsx
import type { Carteira } from '../../utils/extrato';
import { botaoDoSaque, destinoDoBotaoDoSaque, lerBlocoDoSaque } from '../../utils/saque';
import type { DestinoDoSaque } from '../../utils/saque';
```

3. Troque:

```tsx
// mostra "Não foi possível carregar a carteira" com "Tentar de novo".

type Props = { aoFechar: () => void; aoAbrirRecibo: (id: string) => void };

const FolhaDaCarteira = ({ aoFechar, aoAbrirRecibo }: Props) => {
```

por:

```tsx
// mostra "Não foi possível carregar a carteira" com "Tentar de novo".
// Saque Pix (spec 2026-10-26): com o bloco `saque` da carteira, o botão "Sacar via Pix" embaixo do cartão (tela Sacar);
// com um saque em andamento, "Ver saque em andamento" (acompanhamento); indisponível, apagado com o motivo do servidor.

type Props = { aoFechar: () => void; aoAbrirRecibo: (id: string) => void; aoAbrirSaque: (destino: DestinoDoSaque) => void };

const FolhaDaCarteira = ({ aoFechar, aoAbrirRecibo, aoAbrirSaque }: Props) => {
```

4. Troque:

```tsx
    // o recibo só abre depois que a folha fecha (a folha fica no Portal, por cima de qualquer tela)
    const reciboRef = useRef<string | null>(null);
```

por:

```tsx
    // o recibo e as telas do saque só abrem depois que a folha fecha (a folha fica no Portal, por cima de qualquer tela)
    const depoisDeFecharRef = useRef<(() => void) | null>(null);
```

5. Troque:

```tsx
    const aoFecharAFolha = useCallback(() => {
        const recibo = reciboRef.current;
        reciboRef.current = null;
        aoFechar();
        if (recibo) aoAbrirRecibo(recibo);
    }, [aoFechar, aoAbrirRecibo]);
    const abrirRecibo = (id: string) => {
        reciboRef.current = id;
        fechar();
    };
```

por:

```tsx
    const aoFecharAFolha = useCallback(() => {
        const depois = depoisDeFecharRef.current;
        depoisDeFecharRef.current = null;
        aoFechar();
        depois?.();
    }, [aoFechar]);
    const abrirRecibo = (id: string) => {
        depoisDeFecharRef.current = () => aoAbrirRecibo(id);
        fechar();
    };
    const abrirSaque = (destino: DestinoDoSaque) => {
        depoisDeFecharRef.current = () => aoAbrirSaque(destino);
        fechar();
    };
```

6. Troque:

```tsx
    const pagamentos = carteira?.pagamentos ?? [];
```

por:

```tsx
    const pagamentos = carteira?.pagamentos ?? [];
    // sem a carteira (carregando ou erro) ou sem o bloco `saque`: botão escondido
    const botaoSaque = botaoDoSaque(lerBlocoDoSaque(carteira?.saque));
    const destinoDoSaque = destinoDoBotaoDoSaque(botaoSaque);
```

7. Troque:

```tsx
    const lista = (
        <YStack>
            {rotulo(t('Carteira.ultimosPagamentos'))}
```

por:

```tsx
    const saque =
        botaoSaque.modo === 'escondido' ? null : (
            <YStack mb='$4' gap='$2'>
                <Pressable
                    onPress={() => destinoDoSaque && abrirSaque(destinoDoSaque)}
                    disabled={!destinoDoSaque}
                    accessibilityRole='button'
                    accessibilityState={{ disabled: !destinoDoSaque }}
                >
                    {({ pressed }) => (
                        <XStack height={56} borderRadius={16} bg='$primary' alignItems='center' justifyContent='center' gap='$2' opacity={!destinoDoSaque ? 0.4 : pressed ? 0.85 : 1}>
                            <FontAwesomeIcon icon={faPix} color={theme['$sobrePrimario'].val} size={18} />
                            <Text color='$sobrePrimario' fontFamily='$heading' fontWeight='700' fontSize={19}>
                                {t(botaoSaque.modo === 'acompanhar' ? 'Saque.acompanhar' : 'Saque.botao')}
                            </Text>
                        </XStack>
                    )}
                </Pressable>
                {botaoSaque.modo === 'indisponivel' && (
                    <Text color='$textSecondary' fontSize={13} textAlign='center'>
                        {botaoSaque.motivo ?? t('Saque.indisponivel')}
                    </Text>
                )}
            </YStack>
        );

    const lista = (
        <YStack>
            {rotulo(t('Carteira.ultimosPagamentos'))}
```

8. Troque:

```tsx
                        <>
                            {cartao}
                            {lista}
                        </>
```

por:

```tsx
                        <>
                            {cartao}
                            {saque}
                            {lista}
                        </>
```

- [ ] **Passo 2: o Início abre as telas do saque e a carteira pelo push da chave Pix**

Em `src/screens/MeusGanhosScreen.tsx`:

1. Troque:

```tsx
import { useFocusEffect, useNavigation } from '@react-navigation/native';
```

por:

```tsx
import { useFocusEffect, useNavigation, useRoute } from '@react-navigation/native';
```

2. Na `MeusGanhosScreen` (não na `FolhaDoPeriodo`), troque:

```tsx
    const theme = useTheme();
    const navigation = useNavigation();
    const insets = useSafeAreaInsets();
```

por:

```tsx
    const theme = useTheme();
    const navigation = useNavigation();
    const route = useRoute();
    const insets = useSafeAreaInsets();
```

3. Troque:

```tsx
    useFocusEffect(
        useCallback(() => {
            return () => setCarteiraAberta(false);
        }, [])
    );
```

por:

```tsx
    useFocusEffect(
        useCallback(() => {
            return () => setCarteiraAberta(false);
        }, [])
    );

    // push "Chave Pix alterada" (entregas_pix_alterado, DriverLayout): abre a Minha carteira, com a chave nova no cartão
    const pedidoDaCarteira = (route.params as { carteira?: number } | undefined)?.carteira;
    useEffect(() => {
        if (!pedidoDaCarteira) return;
        setCarteiraAberta(true);
        (navigation as any).setParams({ carteira: undefined });
    }, [pedidoDaCarteira, navigation]);
```

4. Troque:

```tsx
            {carteiraAberta && <FolhaDaCarteira aoFechar={() => setCarteiraAberta(false)} aoAbrirRecibo={(id) => navigation.navigate('Recibo', { id })} />}
```

por:

```tsx
            {carteiraAberta && (
                <FolhaDaCarteira
                    aoFechar={() => setCarteiraAberta(false)}
                    aoAbrirRecibo={(id) => navigation.navigate('Recibo', { id })}
                    aoAbrirSaque={(destino) => (destino.tela === 'Sacar' ? navigation.navigate('Sacar') : navigation.navigate('AcompanharSaque', { id: destino.id }))}
                />
            )}
```

- [ ] **Passo 3: `tsc` sem erro novo**

```bash
bash /c/tmp/sa-tsc.sh
```

Esperado: `tsc: nenhum erro novo`. (As chamadas `navigation.navigate(...)` do Início já existiam sem tipo; se o `tsc`
acusar a mensagem de `navigate` como nova, troque `useNavigation()` por `useNavigation<any>()` só na `MeusGanhosScreen` e
rode de novo.)

- [ ] **Passo 4: commit**

```bash
cd /c/tmp/saque-app && git rev-parse --show-toplevel && git add src/components/entregas/FolhaDaCarteira.tsx src/screens/MeusGanhosScreen.tsx && git commit -m "Minha carteira: botão Sacar via Pix (ou Ver saque em andamento, ou apagado com o motivo)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 11: pushes do saque

**Arquivos:**
- Modificar: `src/layouts/DriverLayout.tsx`

- [ ] **Passo 1: imports**

Troque:

```tsx
import { reciboDoPush } from '../utils/extrato';
```

por:

```tsx
import { reciboDoPush } from '../utils/extrato';
import { EVENTO_DO_SAQUE, destinoDoPushDoSaque, saqueDoAviso } from '../utils/saque';
import { EventRegister } from 'react-native-event-listeners';
```

- [ ] **Passo 2: o tratamento**

No `handlePushNotification`, troque:

```tsx
            if (typeof id === 'string' && id.startsWith('order_')) {
```

por:

```tsx
            // Entregas: saque Pix pelo app (SaqueConcluido e SaqueNaoConcluido: type entregas_saque, `saque` = uuid, sem "id";
            // PixDeSaqueAlterado: entregas_pix_alterado, sem dados; canal avisos). Recebido ou tocado, a tela de
            // acompanhamento aberta relê na hora. Tocado: o acompanhamento daquele saque, em cima do Início; na troca da
            // chave Pix, o Início com a Minha carteira aberta.
            if (type === 'entregas_saque' || type === 'entregas_pix_alterado') {
                const saqueRecebido = saqueDoAviso(payload);
                if (saqueRecebido) EventRegister.emit(EVENTO_DO_SAQUE, saqueRecebido);
                const destino = destinoDoPushDoSaque(payload, action);
                if (destino?.tela === 'AcompanharSaque') {
                    const { tabName, screenName, screenParams } = getCurrentScreen(tabNavigation);
                    const jaAberto = tabName === 'DriverDashboardTab' && screenName === 'AcompanharSaque' && screenParams?.id === destino.id;
                    if (!jaAberto) {
                        tabNavigation.navigate('DriverDashboardTab', { screen: 'AcompanharSaque', params: { id: destino.id }, initial: false });
                    }
                } else if (destino?.tela === 'Carteira') {
                    tabNavigation.navigate('DriverDashboardTab', { screen: 'DriverDashboard', params: { carteira: Date.now() } });
                }
                return;
            }

            if (typeof id === 'string' && id.startsWith('order_')) {
```

- [ ] **Passo 3: `tsc` sem erro novo**

```bash
bash /c/tmp/sa-tsc.sh
```

Esperado: `tsc: nenhum erro novo`.

- [ ] **Passo 4: commit**

```bash
cd /c/tmp/saque-app && git rev-parse --show-toplevel && git add src/layouts/DriverLayout.tsx && git commit -m "Pushes do saque Pix: abrir o acompanhamento e a carteira (chave Pix alterada)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 12: "Saque Pix" no extrato e no recibo

**Arquivos:**
- Modificar: `src/screens/MeusGanhosScreen.tsx`
- Modificar: `src/screens/ReciboScreen.tsx`

- [ ] **Passo 1: a linha do extrato**

Em `src/screens/MeusGanhosScreen.tsx`:

1. Troque:

```tsx
import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
```

por:

```tsx
import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { faPix } from '@fortawesome/free-brands-svg-icons';
```

2. Troque:

```tsx
import type { Selecao } from '../utils/inicio-do-motoboy';
```

por:

```tsx
import type { Selecao } from '../utils/inicio-do-motoboy';
import { rotuloDaSituacaoDoSaque } from '../utils/saque';
```

3. No `renderizarLancamento`, troque:

```tsx
        const corDoValor = anulado ? '$textSecondary' : credito ? '$success' : '$textPrimary';
```

por:

```tsx
        const corDoValor = anulado ? '$textSecondary' : credito ? '$success' : '$textPrimary';
        // pagamento de saque Pix pelo app: ícone do Pix e o selo da situação do saque no lugar do "Estornado"
        const marcaDoSaque = item.pagamento?.saque ? rotuloDaSituacaoDoSaque(item.pagamento.saque.situacao) : null;
```

4. Troque:

```tsx
                    <FontAwesomeIcon icon={ICONES_DOS_TIPOS[item.tipo] ?? faReceipt} color={theme.textSecondary.val} size={16} />
```

por:

```tsx
                    <FontAwesomeIcon icon={marcaDoSaque ? faPix : (ICONES_DOS_TIPOS[item.tipo] ?? faReceipt)} color={theme.textSecondary.val} size={16} />
```

5. Troque:

```tsx
                        {item.pagamento?.estornado && <Selo variante='neutro'>{t('Extrato.situacao.estornado')}</Selo>}
```

por:

```tsx
                        {marcaDoSaque && <Selo variante={marcaDoSaque.variante}>{t(marcaDoSaque.chave)}</Selo>}
                        {item.pagamento?.estornado && !marcaDoSaque && <Selo variante='neutro'>{t('Extrato.situacao.estornado')}</Selo>}
```

- [ ] **Passo 2: o recibo**

Em `src/screens/ReciboScreen.tsx`:

1. Troque:

```tsx
import { rotuloDaForma, tipoDoComprovante } from '../utils/extrato';
```

por:

```tsx
import { rotuloDaForma, tipoDoComprovante } from '../utils/extrato';
import { linkDoComprovante, rotuloDaSituacaoDoSaque } from '../utils/saque';
```

2. No comentário do topo, troque:

```tsx
// navegador do celular. Erro na leitura: a mensagem e o botão de voltar.
```

por:

```tsx
// navegador do celular. Erro na leitura: a mensagem e o botão de voltar.
// Pagamento de saque Pix pelo app (`saque` no recibo): "Recibo do saque", forma "Saque Pix pelo app", a situação do
// saque e o comprovante do Asaas (link https, `comprovante_url`) no lugar do anexado pela central.
```

3. Troque:

```tsx
    const tipo = tipoDoComprovante(pagamento?.comprovante);
```

por:

```tsx
    const tipo = tipoDoComprovante(pagamento?.comprovante);
    const saque = pagamento?.saque ?? null;
    const linkDoSaque = linkDoComprovante(saque?.comprovante_url);

    const abrirComprovanteDoSaque = async () => {
        if (!linkDoSaque) return;
        try {
            await Linking.openURL(linkDoSaque);
        } catch (falha) {
            console.warn('[recibo] erro ao abrir o comprovante do saque:', falha);
            toast.error(t('Recibo.erroAoAbrirComprovante'));
        }
    };
```

4. Na função `comprovante`, troque:

```tsx
    const comprovante = () => {
        if (!pagamento) return null;
        if (tipo === null) {
```

por:

```tsx
    const comprovante = () => {
        if (!pagamento) return null;
        if (saque) {
            if (linkDoSaque) return botao(t('Recibo.abrirComprovanteDoPix'), abrirComprovanteDoSaque);
            return (
                <Text color='$textSecondary' fontSize={14}>
                    {t(saque.situacao === 'falhou' ? 'Recibo.semComprovanteDoSaque' : 'Recibo.comprovanteDoSaqueEmAndamento')}
                </Text>
            );
        }
        if (tipo === null) {
```

5. Troque:

```tsx
                        {t('Recibo.titulo')}
```

por:

```tsx
                        {t(saque ? 'Recibo.tituloDoSaque' : 'Recibo.titulo')}
```

6. Troque:

```tsx
                                {t('Recibo.estornadoDica')}
```

por:

```tsx
                                {t(saque ? 'Recibo.saqueNaoConcluido' : 'Recibo.estornadoDica')}
```

7. Troque:

```tsx
                        {linha(t('Recibo.forma'), t(rotuloDaForma(pagamento.forma)))}
```

por:

```tsx
                        {linha(t('Recibo.forma'), saque ? t('Recibo.formaDoSaque') : t(rotuloDaForma(pagamento.forma)))}
                        {saque && linha(t('Recibo.situacao'), t(rotuloDaSituacaoDoSaque(saque.situacao).chave))}
```

- [ ] **Passo 3: `tsc` sem erro novo e testes**

```bash
bash /c/tmp/sa-tsc.sh
cd /c/tmp/saque-app && node --experimental-strip-types --test scripts/testes/*.teste.ts 2>&1 | grep -E "^# (tests|pass|fail)"
```

Esperado: `tsc: nenhum erro novo`; `# tests 204`, `# pass 204`, `# fail 0` (185 + 17 do `saque.teste.ts` + 2 do `extrato`).

- [ ] **Passo 4: commit**

```bash
cd /c/tmp/saque-app && git rev-parse --show-toplevel && git add src/screens/MeusGanhosScreen.tsx src/screens/ReciboScreen.tsx && git commit -m "Extrato e recibo: pagamento de saque como Saque Pix, com a situação e o comprovante do Asaas

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 13: documentação

**Arquivos:**
- Modificar (sem commit, não rastreado): `N:/CLAUDE.md`
- Modificar (Delivery, com commit): `api/app/Http/Controllers/Entregas/CLAUDE.md`, `api/app/Support/Entregas/Saques/CLAUDE.md`
  (criado pelo Plano 1, Task 14) e `docs/historico.md`

As duas seções "App do motoboy" (`N:/CLAUDE.md` e `D:/api/app/Http/Controllers/Entregas/CLAUDE.md`) precisam ficar iguais.

- [ ] **Passo 1: o item do saque nas duas cópias**

Nos dois arquivos, **apague** o item que o Plano 1 pode ter posto (começa com `` - **Saque Pix** (`SaquesController` ``; se
não existir, siga) e, logo **antes** da linha que começa com `- **Aba Pedidos**`, acrescente:

```markdown
  - **Saque Pix pelo app** (decisão de 2026-10-26; spec `docs/superpowers/specs/2026-10-26-saque-pix-asaas-design.md`, planos `2026-10-26-saque-pix-asaas-api.md` e `-app.md`, no Delivery; servidor em `api/app/Support/Entregas/Saques/CLAUDE.md`). A carteira traz o bloco `saque` `{disponivel, minimo, maximo_hoje, taxa, em_andamento, indisponivel_motivo}` só quando a central liberou o saque; sem ele (ou com a API antiga), nada muda no app. Funções puras em `src/utils/saque.ts` (`scripts/testes/saque.teste.ts`).
    - **Minha carteira:** embaixo do cartão, "Sacar via Pix" → tela `Sacar`; com saque em andamento, "Ver saque em andamento" → `AcompanharSaque`; com o `indisponivel_motivo` (ou nada a sacar), o botão apagado com o motivo (`botaoDoSaque`). A lista "Últimos pagamentos" mostra o saque como "Pix" (a rota `carteira` não o marca).
    - **Sacar** (`src/screens/SacarScreen.tsx`, pilha do Início, sem a barra de abas): relê a carteira ao abrir (saque em andamento → troca pelo acompanhamento); campo do valor como nos apps de banco (só os dígitos, `centavosDoCampo`), "Tudo" = `disponivel` (já sem a taxa e com o limite do dia); "Cai no seu Pix" (o valor), "Taxa do saque (sai do saldo)" e "Sai do seu saldo"; a chave Pix da carteira escondida como no servidor (`chaveEscondida`) com o tipo. "Confirmar saque": confere mínimo e disponível (`conferirValor`; o servidor confere de novo), exige bloqueio de tela e pede o **desbloqueio do aparelho** pelo módulo nativo `DesbloqueioDoAparelho` (`DesbloqueioDoAparelhoModule.kt`, registrado no `AlertaPedidoPackage`; `androidx.biometric:biometric:1.1.0` declarado no `android/app/build.gradle`): `KeyguardManager.isDeviceSecure` falso → "Ative a senha de tela do celular para sacar." + "Abrir configurações"; senão o `BiometricPrompt` com `BIOMETRIC_WEAK | DEVICE_CREDENTIAL` (digital, rosto, PIN, padrão ou senha; do Android 7 em diante). **Não use o `react-native-keychain` para isso:** a versão 10 só aceita o PIN a partir do Android 11 e só biometria forte. O desbloqueio é só no celular (o servidor não o confere; ameaça residual aceita no spec).
    - **Pedido:** `POST v1/entregas/motoboy/saques {uuid, valor}`. O uuid v4 é gerado no app (`novoUuid` com `crypto.getRandomValues`, do `react-native-get-random-values`) e **reaproveitado até o servidor devolver o saque** (`uuidDoEnvio`): rede caiu → Confirmar de novo manda o mesmo uuid e o servidor devolve o saque já criado (200), sem outro saque. Recusa (422): a mensagem do servidor; sem resposta (rede, 5xx, 429): "O servidor não respondeu…" (`mensagemDaFalha`).
    - **AcompanharSaque** (`src/screens/AcompanharSaqueScreen.tsx`, params `id` e, vindo do Sacar, `saque`): `GET v1/entregas/motoboy/saques/{uuid}` a cada 3 s por até 1 min (`estadoDoAcompanhamento`): "Enviando…" → "Caiu no seu Pix ✓" ("Ver comprovante": o `comprovante_url` do Asaas, só https, `linkDoComprovante`) ou "Não concluído; o valor voltou ao saldo" com o motivo; depois de 1 min, "Ainda processando" + "Consultar de novo" (o push avisa). Relê ao voltar para a frente e quando o push do saque chega com a tela aberta (evento `entregas.saque` do `react-native-event-listeners`, emitido pelo `DriverLayout`).
    - **Pushes** (canal `avisos`): `entregas_saque` (`saque` = uuid; `SaqueConcluido` e `SaqueNaoConcluido`) tocado → `AcompanharSaque` na pilha do Início (`initial: false`); `entregas_pix_alterado` (`PixDeSaqueAlterado`) tocado → Início com a Minha carteira aberta (param `carteira`). `destinoDoPushDoSaque` e `saqueDoAviso`.
    - **Extrato e recibo:** pagamento com `pagamento.saque` `{situacao, comprovante_url}` = "Saque Pix", ícone do Pix e selo da situação (Em andamento, Caiu no Pix, Não concluído; o "Estornado" não repete); o Recibo vira "Recibo do saque" (forma "Saque Pix pelo app", situação e "Abrir comprovante do Pix"). A taxa do saque ("Taxa do saque Pix") e a devolução dela ("Taxa do saque devolvida") ficam **fora dos "Ganhos · período"** (`ganhosDoPeriodo`), como os pagamentos; o estorno do saque que falhou usa o motivo "Pagamento estornado", que o app já reconhece.
    - **Ordem de implantação:** a API do Plano 1 no ar (e o saque ligado na central) antes do merge do app na `main`; com a API antiga, o APK novo só não mostra o botão.
```

- [ ] **Passo 2: o índice do app**

Em `N:/CLAUDE.md`, na tabela "Partes do app documentadas no servidor", acrescente a linha:

```markdown
| Saque Pix (Asaas da central): segredos, camadas, validação de saque, rotas e pushes do lado do servidor | `api/app/Support/Entregas/Saques/CLAUDE.md` |
```

- [ ] **Passo 3: o `CLAUDE.md` do assunto (Delivery)**

Em `D:/api/app/Support/Entregas/Saques/CLAUDE.md`, logo antes da seção `## Testes`, acrescente:

```markdown
## App do motoboy

Telas, desbloqueio do aparelho, uuid do envio, acompanhamento e pushes: item "Saque Pix pelo app" em
`api/app/Http/Controllers/Entregas/CLAUDE.md` (cópia no `CLAUDE.md` do `entregas-navigator`). Plano:
`docs/superpowers/plans/2026-10-26-saque-pix-asaas-app.md`. O app nunca recebe a chave Pix inteira do saque (o
`Saques::publico` já a esconde); a da carteira (`pix`) é a do cadastro, que ele já via.
```

- [ ] **Passo 4: histórico (Delivery)**

Em `D:/docs/historico.md`, acrescente o item seguinte da lista (mesmo formato dos outros):

```markdown
N. Saque Pix do motoboy pelo app, plano 3 (2026-10-26, ramo `saque-pix` do `entregas-navigator`): botão "Sacar via Pix" na Minha carteira, tela Sacar com desbloqueio do aparelho (módulo nativo com `androidx.biometric`), acompanhamento por 1 min, pushes do saque e "Saque Pix" no extrato e no recibo.
```

(troque `N` pelo número seguinte ao último item do arquivo).

- [ ] **Passo 5: as duas cópias iguais**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding" && diff <(sed -n '/Início = extrato do Financeiro/,/^- \*\*Aba Pedidos/p' Delivery/api/app/Http/Controllers/Entregas/CLAUDE.md) <(sed -n '/Início = extrato do Financeiro/,/^- \*\*Aba Pedidos/p' entregas-navigator/CLAUDE.md) && echo iguais
```

Esperado: `iguais`. (Se a seção "App do motoboy" do app tiver outras diferenças antigas, elas aparecem aqui: corrija só o
trecho do saque.)

- [ ] **Passo 6: commit no Delivery**

Use a worktree do Plano 1 (`/c/tmp/saque`, ramo `saque-pix`) se ela ainda existir e o Plano 1 não tiver sido mesclado;
senão, uma worktree nova a partir da `main`:

```bash
ls /c/tmp/saque >/dev/null 2>&1 && echo "use /c/tmp/saque" || git -C "/c/Users/Edgardjr/Documents/vibe coding/Delivery" worktree add /c/tmp/saque-docs -b saque-pix-app-docs main
```

Faça as edições dos Passos 1, 3 e 4 **nessa** worktree (os arquivos do Delivery são rastreados) e, nela:

```bash
git rev-parse --show-toplevel
git add api/app/Http/Controllers/Entregas/CLAUDE.md api/app/Support/Entregas/Saques/CLAUDE.md docs/historico.md
git commit -m "Saque Pix: documentação do app do motoboy (plano 3)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

O `N:/CLAUDE.md` **não** entra em commit (não rastreado).

---

### Tarefa 14: verificação final e build de teste do ramo

**Arquivos:** nenhum.

- [ ] **Passo 1: todos os testes do app**

```bash
cd /c/tmp/saque-app && node --experimental-strip-types --test scripts/testes/*.teste.ts 2>&1 | grep -E "^# (tests|pass|fail)"
```

Esperado: `# tests 204`, `# pass 204`, `# fail 0`.

- [ ] **Passo 2: `tsc` e eslint**

```bash
bash /c/tmp/sa-tsc.sh
cd /c/tmp/saque-app && npx eslint src/utils/saque.ts src/utils/desbloqueio-do-aparelho.ts src/utils/extrato.ts src/screens/SacarScreen.tsx src/screens/AcompanharSaqueScreen.tsx src/screens/ReciboScreen.tsx src/screens/MeusGanhosScreen.tsx src/components/entregas/FolhaDaCarteira.tsx src/layouts/DriverLayout.tsx src/navigation/DriverNavigator.tsx 2>&1 | grep -E "error" | grep -v "0 errors" ; echo "eslint fim"
```

Esperado: `tsc: nenhum erro novo` e nada antes de `eslint fim` (avisos podem aparecer; erros, não).

- [ ] **Passo 3: toda chave de texto usada existe nas duas línguas**

```bash
cd /c/tmp/saque-app && node - <<'FIM'
const fs = require('fs');
const pt = JSON.parse(fs.readFileSync('translations/pt.json', 'utf8'));
const en = JSON.parse(fs.readFileSync('translations/en.json', 'utf8'));
const arquivos = ['src/utils/saque.ts', 'src/utils/extrato.ts', 'src/screens/SacarScreen.tsx', 'src/screens/AcompanharSaqueScreen.tsx', 'src/screens/ReciboScreen.tsx', 'src/screens/MeusGanhosScreen.tsx', 'src/components/entregas/FolhaDaCarteira.tsx'];
// montadas em rotuloDoTipoDaChave (`Saque.tiposDeChave.${tipo}`)
const usadas = new Set(['cpf', 'cnpj', 'telefone', 'email', 'aleatoria'].map((tipo) => `Saque.tiposDeChave.${tipo}`));
for (const arquivo of arquivos) {
    for (const [, chave] of fs.readFileSync(arquivo, 'utf8').matchAll(/'((?:Saque|Recibo|Extrato|Carteira)\.[A-Za-z.]+)'/g)) usadas.add(chave);
}
const ler = (json, chave) => chave.split('.').reduce((atual, parte) => (atual == null ? undefined : atual[parte]), json);
const faltando = [];
for (const chave of usadas) for (const [lingua, json] of [['pt', pt], ['en', en]]) if (typeof ler(json, chave) !== 'string') faltando.push(`${lingua}: ${chave}`);
const folhas = (objeto, prefixo) => Object.entries(objeto).flatMap(([k, v]) => (typeof v === 'string' ? [`${prefixo}.${k}`] : folhas(v, `${prefixo}.${k}`)));
const semUso = folhas(pt.Saque, 'Saque').filter((chave) => !usadas.has(chave));
const soEmPt = folhas(pt.Saque, 'Saque').filter((chave) => typeof ler(en, chave) !== 'string');
console.log(faltando.length ? 'FALTAM: ' + faltando.join(', ') : 'ok: nenhuma chave faltando', '| sem uso:', semUso.join(', ') || 'nenhuma', '| só em pt:', soEmPt.join(', ') || 'nenhuma');
FIM
```

Esperado: `ok: nenhuma chave faltando | sem uso: nenhuma | só em pt: nenhuma`.

- [ ] **Passo 4: o ramo**

```bash
cd /c/tmp/saque-app && git status --short && git log --oneline main..HEAD
```

Esperado: worktree limpa e 12 commits (Tarefas 1 a 12).

- [ ] **Passo 5: subir o ramo e disparar o build de teste (com o OK do Edgard)**

Push de ramo **não** dispara build (o `push` do `build-apk.yml` é só na `main`), e o build de ramo **não** publica no MinIO
(`if: github.ref == 'refs/heads/main' && env.SUFIXO == ''`): a versão mínima não muda e nenhum motoboy é travado.

```bash
cd /c/tmp/saque-app
git push -u origin saque-pix
gh workflow run build-apk.yml --ref saque-pix
gh run list --workflow build-apk.yml --branch saque-pix --limit 1
```

Esperado: o run em `queued` ou `in_progress`. Anote o id e acompanhe com `gh run watch <id> --exit-status` em segundo plano
(30 a 60 min). **Falhou no Kotlin** (`DesbloqueioDoAparelhoModule.kt`): leia o log (`gh run view <id> --log-failed`),
corrija na worktree, commit e dispare de novo. **Falhou no upload do artefato** (a cota de artefatos do GitHub já encheu
uma vez): liste com `gh api repos/edgardjnr/entregas-navigator/actions/artifacts --paginate -q '.artifacts[] | "\(.id) \(.name) \(.created_at)"'`
e, com o OK do Edgard, apague os mais antigos com `gh api -X DELETE repos/edgardjnr/entregas-navigator/actions/artifacts/<id>`.

- [ ] **Passo 6: baixar o APK**

```bash
gh run download <id> --dir /c/tmp/apk-saque
ls /c/tmp/apk-saque/*
```

Esperado: `entregas-motoboy-<n>.apk` em `/c/tmp/apk-saque/entregas-motoboy-<n>/`. Com o sufixo `-sem-push`, o secret
`GOOGLE_SERVICES_JSON` faltou: o APK serve para olhar as telas, mas não recebe push.

- [ ] **Passo 7: teste no celular (Edgard, por adb)**

```bash
adb devices
adb install -r /c/tmp/apk-saque/entregas-motoboy-<n>/entregas-motoboy-<n>.apk
```

**Com a API de hoje** (sem o Plano 1): a Minha carteira igual a antes (sem botão), o extrato e o recibo iguais, o Início
igual. Nada quebrou.

**Com a API do Plano 1 no ar e o saque ligado no sandbox** (Plano 1, Task 16, passos 1 a 4), com um motoboy de teste
(nunca da Terraço, que é loja real) e saldo de teste:
- carteira: "Sacar via Pix"; tela Sacar com o disponível, o mínimo, "Tudo", a taxa (configure R$ 1 na central para ver a
  linha) e "Telefone · *******4321";
- valor abaixo do mínimo e acima do disponível: o aviso em vermelho, sem abrir o desbloqueio;
- celular **sem** bloqueio de tela: "Ative a senha de tela do celular para sacar." e "Abrir configurações" abre a tela de
  segurança; com PIN só (sem digital): o diálogo pede o PIN; com digital: a digital (e "Usar PIN"); cancelar o diálogo:
  nada acontece;
- saque de R$ 1 a R$ 5: "Enviando…" → "Caiu no seu Pix ✓" e "Ver comprovante" abre o comprovante do Asaas; o extrato
  mostra "Saque Pix" com "Caiu no Pix" e o recibo, "Recibo do saque";
- **rede caindo:** ligue o modo avião logo depois do desbloqueio (antes da resposta), veja "O servidor não respondeu…",
  desligue o modo avião e toque em Confirmar de novo: um saque só no console (Financeiro → Saques) e a tela de
  acompanhamento;
- saque recusado pelo Asaas (sem saldo no sandbox): "Não concluído; o valor voltou ao saldo" com o motivo e o push "Saque
  não concluído"; o extrato com "Não concluído" e o estorno; os "Ganhos · Hoje" sem a taxa;
- com o app fechado: o push "Saque concluído" tocado abre o acompanhamento; a troca da chave Pix no console manda "Chave
  Pix alterada", que abre a carteira; a carteira fica com o botão apagado e o motivo da carência;
- segundo saque com um em andamento: "Ver saque em andamento".

Anote o que divergir e corrija antes da Tarefa 15.

---

### Tarefa 15: publicação do APK (merge na `main` do app)

**Arquivos:** nenhum novo.

**Só com o OK do Edgard e só depois** de: (1) Plano 1 em produção (`bash deploy/atualizar.sh api`, migration rodada,
secret e `ENTREGAS_SAQUE=1` no stack); (2) Plano 2 (console) em produção; (3) o teste da Tarefa 14 aprovado no sandbox.
O merge na `main` gera o APK que vira **versão mínima**: todos os motoboys ficam travados ("Atualize o app") até instalar.
Combine um horário fora do pico de pedidos.

- [ ] **Passo 1: merge local**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator"
git rev-parse --show-toplevel
git status --short
git checkout main && git pull --ff-only
git merge --no-ff saque-pix -m "Merge branch 'saque-pix': saque Pix pelo app (Asaas da central)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
node --experimental-strip-types --test scripts/testes/*.teste.ts 2>&1 | grep -E "^# (tests|pass|fail)"
```

Esperado: só `?? CLAUDE.md` no status antes do merge; depois, `# fail 0`.

- [ ] **Passo 2: push da `main` (dispara o build e a publicação)**

```bash
git push origin main
gh run list --workflow build-apk.yml --branch main --limit 1
```

Acompanhe com `gh run watch <id> --exit-status`. No fim, a versão mínima publicada:

```bash
curl -s https://s3arquivos.restaurantepro.com.br/entregas/APK/entregas-motoboy.json
```

Esperado: `{"versao": <n>, "url": ".../entregas-motoboy-<n>.apk"}` com o `<n>` do run.

- [ ] **Passo 3: depois da publicação**

- Avise os motoboys (o app mostra "Atualize o app"; "Baixar atualização" instala por cima).
- Primeiro saque real: um motoboy avisado, valor pequeno (Plano 1, Task 16, passo 7).
- Na seção do saque das duas cópias do `CLAUDE.md` (Tarefa 13), troque "Ordem de implantação: …" por "Em produção desde
  <data> (APK <n> = versão mínima)." e acrescente a linha no `docs/historico.md` com o hash do merge.

- [ ] **Passo 4: limpar a worktree**

```bash
cmd //c rmdir 'C:\tmp\saque-app\node_modules'
cd "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && git worktree remove /c/tmp/saque-app && git branch -d saque-pix
rm -f /c/tmp/sa-tsc.sh /c/tmp/sa-tsc-antes.txt /c/tmp/sa-tsc-agora.txt
```

Esperado: a junção sai primeiro (senão a remoção da worktree pode apagar o `node_modules` da pasta principal); o ramo sai
porque já está na `main`.
