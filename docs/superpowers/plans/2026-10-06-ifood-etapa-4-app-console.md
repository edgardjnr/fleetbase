# iFood etapa 4: APK do motoboy, console e portal da loja

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** etapa 4 da integração iFood (spec `docs/superpowers/specs/2026-10-05-integracao-ifood-logistics-design.md`, seção
4): no app do motoboy, os dados do iFood no card e nos detalhes (selo, faixa amarela da cobrança, "Ligar para o cliente"
com o 0800 e o localizador, observações, complemento e referência), a conclusão com o campo do código de entrega e o
número e a cobrança no cartão do alarme; no console, o selo "iFood #4821", o painel iFood no detalhe (com "Liberar sem
código"), o aviso de ação recusada e o Cancelar escondido; no portal, o selo e o Cancelar escondido.

**Architecture:** **depende do plano 1** (`docs/superpowers/plans/2026-10-06-ifood-etapa-3-ciclo.md`) já em produção: as
rotas `GET v1/entregas/motoboy/pedidos/{id}/ifood`, `POST .../concluir-ifood` e `POST .../codigo-ifood` (Task 10), o
painel `GET int/v1/entregas/pedidos/{id}/ifood` e o `POST .../ifood/liberar-sem-codigo` (Task 11), o evento
`entregas.ifood_acao_recusada` (Task 4), os dados `entregas_ifood`/`entregas_cobrar` do alarme (Task 12) e o
`cancelado_pago` dos ganhos (Task 8). O app fica no repo separado `entregas-navigator` (React Native; o APK sai do GitHub
Actions a cada push na `main`); console e portal ficam neste repo (`packages/fleetops`, `packages/customer-portal`). O
pedido iFood é reconhecido na tela pelas notas "iFood #4821" + `internal_id` igual (gravados pelo
`CriadorDoPedidoIfood`), sem rota nova; o servidor recusa o cancelamento de qualquer jeito.

```
App: card de aceitar ─ GET .../ifood ─> selo + faixa amarela        Alarme (Kotlin): entregas_ifood + entregas_cobrar
App: detalhes ─ GET .../ifood ─> cobrança, Ligar (0800 + localizador), observações
App: atividade que conclui ─ POST concluir-ifood ─> pode_concluir ─> update-activity de sempre
                                                └─> precisa_codigo ─> campo do código ─ POST codigo-ifood ─> pode_concluir ─> update-activity
Console: selo (tabela, quadro, mapa, cabeçalho) · painel iFood (GET/POST) · aviso entregas.ifood_acao_recusada · sem Cancelar
Portal: selo (lista, tabela, cabeçalho) · sem Cancelar (aviso "o cancelamento é feito no iFood")
```

**Tech Stack:** React Native 0.86 + Tamagui + `@fleetbase/sdk` (app), Kotlin (alarme nativo), Ember/Glimmer (console e
portal), `node:test` (funções puras).

## Contexto para quem executa

- Leia o `CLAUDE.md` deste repo (seções "App do motoboy", "Portal da loja", "Tradução pt-BR (convenções)" e "Integração
  iFood (etapa 3)") e a seção 4 da spec.
- **O plano 1 precisa estar em produção** antes do teste real (Task 12). O código daqui pode ser feito antes.
- **App (`C:\Users\Edgardjr\Documents\vibe coding\entregas-navigator`, Git Bash `/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator`):**
  - funções puras em `src/utils/*.ts` sem imports, testadas com `node --experimental-strip-types --test scripts/testes/<nome>.teste.ts`
    (import com extensão `.ts` no teste, sem extensão no app);
  - chamadas à API com `adapter.get/post('entregas/...')` (o adapter já põe o `v1/`); erros com `toast.error(err?.message || t('...'))`;
  - textos em `translations/pt.json` **e** `translations/en.json` (`t('Componente.chave', { variavel })`, interpolação `{{variavel}}`);
  - não há `node_modules` no PC: o TypeScript/JSX só é conferido no build do GitHub Actions; o Kotlin também só compila lá.
    Confira a sintaxe dos `.ts/.tsx` alterados com o `@babel/parser` do console (Task 1, Step 5);
  - não há biblioteca de área de transferência: o localizador sai em letras grandes com `selectable` (toque longo copia).
  - commit e push no repo do app são separados deste repo (o push na `main` dele gera o APK `entregas-motoboy-<n>`).
    **Não faça push sem o Edgard pedir.**
- **Console e portal (este repo):** traduções em `packages/<módulo>/translations/pt-br.yaml` (2 espaços por nível) e
  `en-us.yaml` (4 espaços por nível no fleetops; 2 no customer-portal); chaves com o prefixo do módulo
  (`fleet-ops.ui.ifood.*`, `customer-portal.ui.entregas.*`). Componente e serviço novos do engine precisam do re-export em
  `packages/fleetops/app/...`. Funções puras testadas com
  `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/<nome>.test.mjs`.
- Validação obrigatória antes de commitar no repo principal:
  `node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal`
  (precisa de `console/node_modules`; numa worktree sem ele, rode `cd console && pnpm install --frozen-lockfile` ou valide
  no repo principal) e o parse de todo JS alterado com o `@babel/parser` de `console/node_modules/.pnpm/@babel+parser@7*`.
- Confirme `git rev-parse --show-toplevel` antes de cada commit; `git add` só dos arquivos da task; trailer
  `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`. Não faça push.
- As funções puras, os testes node e os componentes novos (`.js/.hbs/.ts/.tsx`) deste plano foram escritos e conferidos
  (testes node passando; parse do Glimmer e do Babel) numa cópia de rascunho antes da publicação.

## Decisões deste plano (além da spec)

- **Pedido iFood na tela = notas "iFood #N" + `internal_id` = N** (`numeroIfood`), sem rota nem campo novo no `meta`
  (o `meta` sai na API v1 e no socket). A central que apagar as notas tira o selo, mas o servidor continua recusando o
  cancelamento (`RegrasDoPedidoIfood`, `RegrasPortalLoja`).
- **Conclusão no app:** a interceptação fica no `sendOrderActivityUpdate` do `OrderScreen`, antes da prova de entrega:
  atividade que conclui (`completed` ou `complete: true`) em pedido iFood ainda não liberado chama `concluir-ifood`; com
  `precisa_codigo` abre o campo do código (modal); certo, segue o fluxo de sempre (que chama `concluir-ifood` de novo e
  recebe `pode_concluir`).
- **Pedido de teste também pede o código** (o iFood pediu no Gestor de Pedidos). Sem o código, o motoboy fala com a central,
  que usa "Liberar sem código" no painel iFood do console.
- **Localizador sem botão copiar:** o app não tem biblioteca de área de transferência (seria uma dependência nativa nova);
  o número sai grande, em grupos de 4, com `selectable`. Ligar disca só o 0800 (`tel:`).
- **Erro do `update-activity` aparece ao motoboy** (`toast.error` no `catch`, antes só `console.warn`): sem isso, a trava
  "Atualize o app" e qualquer recusa do servidor ficavam mudas.
- **Console:** coluna "iFood" na tabela (selo), selo no quadro, no mapa e no cabeçalho do detalhe; painel `Order::Details::Ifood`
  logo depois do bloco de detalhes; Cancelar some do menu do detalhe e da linha da tabela; o cancelamento em lote e o
  arrastar para "cancelado" no quadro avisam e param; serviço `ifood-acao-recusada` (som + notificação fixa), iniciado
  como o `pedido-sem-motoboy`; "Pagamento e cobrança" marca a linha `cancelado_pago`.
- **Portal:** selo na lista, na tabela e no cabeçalho; o Cancelar fica desabilitado e o aviso troca para "o cancelamento é
  feito no iFood".
- **Ganhos do app:** a corrida `cancelado_pago` mostra "Cancelado pelo iFood: você recebe".

## A conferir

- **Mensagem de erro do SDK:** o app usa `err?.message` como nos outros erros do Entregas (ex.: `OrderCustomerCard`); conferir
  na Task 12 que o 422 do código incorreto e o 400 "Atualize o app" aparecem com o texto do servidor.
- **O portal recebe `notes` e `internal_id` do pedido** (o selo e o Cancelar escondido dependem deles): conferir na Task 12;
  se não vierem, o servidor ainda recusa o cancelamento (400 com a mensagem).
- **De onde vem o código de entrega de um pedido de teste** (página de testes do Portal do Desenvolvedor?): Task 12.
- **`tel:` com o localizador:** o 0800 do iFood pede o localizador depois de atender; não discamos os dígitos juntos.

## Arquivos

| Arquivo | Ação | Papel |
|---|---|---|
| app `src/utils/pedido-ifood.ts` | Criar | Funções puras do pedido iFood |
| app `scripts/testes/pedido-ifood.teste.ts` | Criar | Testes delas |
| app `src/hooks/use-dados-ifood.ts` | Criar | Busca e cache dos dados iFood |
| app `src/components/PedidoIfood.tsx` | Criar | Selo, cobrança, ligar, observações |
| app `src/components/CodigoDeEntregaIfood.tsx` | Criar | Campo do código de entrega |
| app `src/screens/OrderScreen.tsx` | Modificar | Detalhes e conclusão com código |
| app `src/components/AdhocOrderCard.tsx` | Modificar | Selo e cobrança no card de aceitar |
| app `src/utils/ganhos.ts`, `src/screens/MeusGanhosScreen.tsx` | Modificar | "Cancelado pelo iFood: você recebe" |
| app `translations/pt.json`, `translations/en.json` | Modificar | Textos |
| app `android/.../AlarmePedidoActivity.kt`, `res/values/entregas_strings.xml` | Modificar | Selo e cobrança no alarme |
| `packages/fleetops/addon/utils/pedido-ifood.js` | Criar | Funções puras do console |
| `packages/fleetops/addon/components/pedido-ifood/selo.{js,hbs}` | Criar | Selo "iFood #4821" |
| `packages/fleetops/addon/components/cell/pedido-ifood.{js,hbs}` | Criar | Célula da tabela |
| `packages/fleetops/addon/components/order/details/ifood.{js,hbs}` | Criar | Painel iFood |
| `packages/fleetops/addon/services/ifood-acao-recusada.js` | Criar | Aviso de ação recusada |
| `packages/fleetops/app/...` (4 re-exports) | Criar | Re-exports do engine |
| `packages/fleetops/addon/controllers/operations/orders/index.js`, `.../index/details.js` | Modificar | Coluna, Cancelar escondido |
| `packages/fleetops/addon/services/order-actions.js`, `components/order/kanban.js` | Modificar | Cancelamento barrado na tela |
| `packages/fleetops/addon/components/order/{kanban-card,panel-header,details}.hbs`, `map/order-list-overlay/order.hbs` | Modificar | Selo e painel |
| `packages/fleetops/addon/routes/application.js` | Modificar | Inicia o aviso |
| `packages/fleetops/addon/templates/management/driver-payouts.hbs` | Modificar | Marca do pago mesmo cancelado |
| `packages/fleetops/translations/{pt-br,en-us}.yaml` | Modificar | Textos |
| `packages/customer-portal/addon/utils/entregas-pedido.js` | Modificar | `numeroIfood` |
| `packages/customer-portal/addon/components/portal/order/{details,list-card,panel-header}.{js,hbs}`, `workspace/table.hbs` | Modificar | Selo e Cancelar |
| `packages/customer-portal/translations/{pt-br,en-us}.yaml` | Modificar | Textos |
| `scripts/teste-portal/pedido-ifood.test.mjs` | Criar | Testes do console e do portal |
| `CLAUDE.md` | Modificar | Documentação |

---

### Task 1: app, funções puras e o hook dos dados iFood

**Files (repo `entregas-navigator`):**
- Create: `src/utils/pedido-ifood.ts`
- Create: `scripts/testes/pedido-ifood.teste.ts`
- Create: `src/hooks/use-dados-ifood.ts`

- [ ] **Step 1: o teste**

Crie `scripts/testes/pedido-ifood.teste.ts`:

```ts
// Testes das funções puras do pedido iFood no app (src/utils/pedido-ifood.ts), com o Node puro:
//   node --experimental-strip-types --test scripts/testes/pedido-ifood.teste.ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    codigoValido,
    concluiOPedido,
    ehIfood,
    formatarLocalizador,
    limparCodigo,
    precisaPassarPeloIfood,
    resultadoDaConclusao,
    temCobranca,
    urlDoTelefone,
} from '../../src/utils/pedido-ifood.ts';
import type { PedidoIfood } from '../../src/utils/pedido-ifood.ts';

const pedido = (extra: Partial<PedidoIfood> = {}): PedidoIfood => ({
    ifood: true,
    numero: '4821',
    teste: false,
    cobranca: { centavos: 5890, forma: 'dinheiro', troco_para_centavos: 10000, texto: 'Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100' },
    observacoes: null,
    complemento: null,
    referencia: null,
    exige_codigo: true,
    conclusao_liberada: false,
    cancelado_pelo_ifood: false,
    pago_mesmo_cancelado: false,
    telefone: null,
    ...extra,
});

test('é do iFood', () => {
    assert.equal(ehIfood(pedido()), true);
    assert.equal(ehIfood({ ifood: false }), false);
    assert.equal(ehIfood(null), false);
});

test('atividade que conclui', () => {
    assert.equal(concluiOPedido({ code: 'completed' }), true);
    assert.equal(concluiOPedido({ code: 'entregue', complete: true }), true);
    assert.equal(concluiOPedido({ code: 'enroute' }), false);
    assert.equal(concluiOPedido(null), false);
});

test('código do cliente', () => {
    assert.equal(limparCodigo(' 12-34 '), '1234');
    assert.equal(codigoValido('1234'), true);
    assert.equal(codigoValido(' 12 34 '), true);
    assert.equal(codigoValido('12'), false);
    assert.equal(codigoValido(''), false);
});

test('resultado das rotas de conclusão', () => {
    assert.equal(resultadoDaConclusao({ resultado: 'pode_concluir' }), 'pode_concluir');
    assert.equal(resultadoDaConclusao({ resultado: 'precisa_codigo' }), 'precisa_codigo');
    assert.equal(resultadoDaConclusao({ resultado: 'outra' }), 'tente_de_novo');
    assert.equal(resultadoDaConclusao(null), 'tente_de_novo');
});

test('telefone e localizador', () => {
    assert.equal(urlDoTelefone('0800 700 0000'), 'tel:08007000000');
    assert.equal(formatarLocalizador('12345678'), '1234 5678');
    assert.equal(formatarLocalizador('123456789'), '1234 5678 9');
    assert.equal(formatarLocalizador(null), null);
});

test('cobrança e quando passar pelo iFood', () => {
    assert.equal(temCobranca(pedido()), true);
    assert.equal(temCobranca(pedido({ cobranca: { centavos: 0, forma: null, troco_para_centavos: null, texto: null } })), false);
    assert.equal(temCobranca({ ifood: false }), false);
    assert.equal(precisaPassarPeloIfood(pedido(), { code: 'completed' }), true);
    assert.equal(precisaPassarPeloIfood(pedido({ conclusao_liberada: true }), { code: 'completed' }), false);
    assert.equal(precisaPassarPeloIfood(pedido(), { code: 'enroute' }), false);
    assert.equal(precisaPassarPeloIfood({ ifood: false }, { code: 'completed' }), false);
});
```

- [ ] **Step 2: rodar e ver falhar**

Run: `cd "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && node --experimental-strip-types --test scripts/testes/pedido-ifood.teste.ts`
Expected: falha `Cannot find module '.../src/utils/pedido-ifood.ts'`.

- [ ] **Step 3: as funções**

Crie `src/utils/pedido-ifood.ts`:

```ts
// Entregas: pedido do iFood no app do motoboy (card de aceitar, detalhes e a conclusão com o código do cliente). Dados da
// rota v1/entregas/motoboy/pedidos/{id}/ifood (App\Support\Entregas\Ifood\DadosIfoodDoMotoboy) e resultados das rotas
// concluir-ifood e codigo-ifood (App\Support\Entregas\Ifood\ConclusaoIfood).
// Sem imports, para os testes rodarem com o Node puro: node --experimental-strip-types --test scripts/testes/pedido-ifood.teste.ts

export type CobrancaIfood = { centavos: number; forma: string | null; troco_para_centavos: number | null; texto: string | null };

export type TelefoneIfood = { numero: string; localizador: string | null; expira_em: string | null };

export type PedidoIfood = {
    ifood: true;
    numero: string | null;
    teste: boolean;
    cobranca: CobrancaIfood;
    observacoes: string | null;
    complemento: string | null;
    referencia: string | null;
    exige_codigo: boolean;
    conclusao_liberada: boolean;
    cancelado_pelo_ifood: boolean;
    pago_mesmo_cancelado: boolean;
    telefone: TelefoneIfood | null;
};

export type DadosIfood = { ifood: false } | PedidoIfood;

export type ResultadoDaConclusao = 'pode_concluir' | 'precisa_codigo' | 'codigo_incorreto' | 'tente_de_novo';

const RESULTADOS: ResultadoDaConclusao[] = ['pode_concluir', 'precisa_codigo', 'codigo_incorreto', 'tente_de_novo'];

/** É pedido do iFood (a rota responde {"ifood": false} para os outros). */
export const ehIfood = (dados: DadosIfood | null | undefined): dados is PedidoIfood => !!dados && dados.ifood === true;

/** A atividade conclui o pedido (fluxo transport: "completed"; ou outra marcada com complete). */
export const concluiOPedido = (atividade: { code?: string; complete?: boolean } | null | undefined): boolean =>
    !!atividade && (atividade.code === 'completed' || atividade.complete === true);

/** O texto digitado, só com os números (o teclado numérico ainda deixa colar espaço e traço). */
export const limparCodigo = (texto: string): string => (texto ?? '').replace(/\D/g, '');

/** O código que o servidor aceita conferir: só números, de 3 a 10 dígitos (a documentação do iFood fala em 4 a 6). */
export const codigoValido = (texto: string): boolean => /^\d{3,10}$/.test(limparCodigo(texto));

/** O resultado das rotas concluir-ifood e codigo-ifood; resposta estranha vale como "tente de novo". */
export const resultadoDaConclusao = (resposta: unknown): ResultadoDaConclusao => {
    const resultado = (resposta as { resultado?: unknown } | null)?.resultado;

    return RESULTADOS.includes(resultado as ResultadoDaConclusao) ? (resultado as ResultadoDaConclusao) : 'tente_de_novo';
};

/** "tel:08007000000" para o discador. */
export const urlDoTelefone = (numero: string): string => `tel:${(numero ?? '').replace(/[^\d+]/g, '')}`;

/** O localizador em grupos de 4 para ler ao telefone ("1234 5678"). */
export const formatarLocalizador = (localizador: string | null | undefined): string | null =>
    localizador ? localizador.replace(/\s+/g, '').replace(/(.{4})(?=.)/g, '$1 ') : null;

/** Mostra o bloco de cobrança na porta (faixa amarela). */
export const temCobranca = (dados: DadosIfood | null | undefined): boolean => ehIfood(dados) && !!dados.cobranca?.texto && dados.cobranca.centavos > 0;

/** O pedido iFood ainda depende da conclusão pelo servidor (a conclusão comum só vai depois de liberada). */
export const precisaPassarPeloIfood = (dados: DadosIfood | null | undefined, atividade: { code?: string; complete?: boolean } | null | undefined): boolean =>
    ehIfood(dados) && !dados.conclusao_liberada && concluiOPedido(atividade);
```

- [ ] **Step 4: rodar e ver passar**

Run: `node --experimental-strip-types --test scripts/testes/pedido-ifood.teste.ts && node --experimental-strip-types --test scripts/testes/*.teste.ts`
Expected: `# fail 0` nos dois (o segundo roda todos os testes do app).

- [ ] **Step 5: o hook**

Crie `src/hooks/use-dados-ifood.ts`:

```ts
import { useCallback, useEffect, useState } from 'react';
import useFleetbase from './use-fleetbase';
import type { DadosIfood } from '../utils/pedido-ifood';

// Entregas: dados do iFood de um pedido para o motoboy (rota v1/entregas/motoboy/pedidos/{id}/ifood): número, cobrança na
// porta, observações, 0800 do cliente (só no pedido dele) e se a conclusão já foi liberada. Cache em memória por pedido
// (2 min) e uma chamada por vez para o mesmo pedido, como o use-valor-da-entrega. recarregar() ignora o cache (depois da
// conclusão liberada ou de um erro). Pedido que não é do iFood: {"ifood": false} (a tela não mostra nada).
const VALIDADE_MS = 2 * 60 * 1000;
const guardados: Record<string, { dados: DadosIfood; em: number }> = {};
const emAndamento: Record<string, Promise<DadosIfood>> = {};

const guardado = (id?: string | null): DadosIfood | null => {
    const item = id ? guardados[id] : undefined;
    return item && Date.now() - item.em < VALIDADE_MS ? item.dados : null;
};

export function esquecerDadosIfood(id?: string | null) {
    if (id) delete guardados[id];
}

export function buscarDadosIfood(adapter: any, id: string, ignorarCache = false): Promise<DadosIfood> {
    const pronto = ignorarCache ? null : guardado(id);
    if (pronto) return Promise.resolve(pronto);
    if (!emAndamento[id]) {
        emAndamento[id] = adapter
            .get(`entregas/motoboy/pedidos/${id}/ifood`)
            .then((dados: DadosIfood) => {
                guardados[id] = { dados, em: Date.now() };
                return dados;
            })
            .finally(() => {
                delete emAndamento[id];
            });
    }
    return emAndamento[id];
}

const useDadosIfood = (id?: string | null) => {
    const { adapter } = useFleetbase();
    const [dados, setDados] = useState<DadosIfood | null>(() => guardado(id));
    const [versao, setVersao] = useState(0);

    useEffect(() => {
        if (!adapter || !id) return;
        let ativo = true;
        setDados(guardado(id));
        buscarDadosIfood(adapter, id, versao > 0)
            .then((novos) => {
                if (ativo) setDados(novos);
            })
            .catch((falha) => {
                console.warn('[ifood] dados do pedido:', falha);
            });
        return () => {
            ativo = false;
        };
    }, [adapter, id, versao]);

    const recarregar = useCallback(() => {
        esquecerDadosIfood(id);
        setVersao((atual) => atual + 1);
    }, [id]);

    return { dados, recarregar };
};

export default useDadosIfood;
```

Confira a sintaxe (TypeScript) com o parser do console:

```bash
B=$(ls -d "/c/Users/Edgardjr/Documents/vibe coding/Delivery/console/node_modules/.pnpm"/@babel+parser@7*/node_modules/@babel/parser | head -1)
node -e "const p=require(require('path').resolve(process.argv[1]));for(const f of process.argv.slice(2)){p.parse(require('fs').readFileSync(f,'utf8'),{sourceType:'module',plugins:['typescript','jsx']});console.log('OK',f)}" "$B" src/hooks/use-dados-ifood.ts src/utils/pedido-ifood.ts
```

Expected: `OK` nos dois.

- [ ] **Step 6: commit (repo do app)**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && git rev-parse --show-toplevel
git add src/utils/pedido-ifood.ts scripts/testes/pedido-ifood.teste.ts src/hooks/use-dados-ifood.ts
git commit -m "iFood: funções puras e hook dos dados do pedido (rota v1/entregas/motoboy/pedidos/{id}/ifood)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: app, componentes do pedido iFood e do código de entrega

**Files (repo `entregas-navigator`):**
- Create: `src/components/PedidoIfood.tsx`
- Create: `src/components/CodigoDeEntregaIfood.tsx`
- Modify: `translations/pt.json`, `translations/en.json`

- [ ] **Step 1: `PedidoIfood`**

Crie `src/components/PedidoIfood.tsx`:

```tsx
import { Linking } from 'react-native';
import { Button, Separator, Text, XStack, YStack, useTheme } from 'tamagui';
import { FontAwesomeIcon } from '@fortawesome/react-native-fontawesome';
import { faMoneyBillWave, faPhone } from '@fortawesome/free-solid-svg-icons';
import { useLanguage } from '../contexts/LanguageContext';
import { toast } from '../utils/toast';
import { ehIfood, formatarLocalizador, temCobranca, urlDoTelefone } from '../utils/pedido-ifood';
import type { DadosIfood } from '../utils/pedido-ifood';
import { SectionHeader, SectionInfoLine } from './Content';

// Entregas: o pedido do iFood no app (dados de useDadosIfood).
// - compacto (card de aceitar, AdhocOrderCard): selo "iFood #4821" e a faixa amarela da cobrança na porta;
// - detalhado (detalhes do pedido, OrderScreen): faixa amarela da cobrança, "Ligar para o cliente" (0800 do iFood e o
//   localizador, só no pedido dele e antes de expirar), observações, complemento e referência, e o aviso do cancelamento
//   pelo iFood. O localizador sai em letras grandes e pode ser copiado com toque longo (selectable): o app ainda não tem
//   biblioteca de área de transferência.
// Pedido que não é do iFood ou sem os dados: nada.
type Props = { dados: DadosIfood | null; variante: 'compacto' | 'detalhado' };

const IFOOD_VERMELHO = '#EA1D2C';

const Selo = ({ numero }: { numero: string | null }) => {
    const { t } = useLanguage();
    return (
        <XStack alignSelf='flex-start' bg={IFOOD_VERMELHO} borderRadius='$2' px='$2' py='$1'>
            <Text color='white' fontSize={13} fontWeight='bold'>
                {numero ? t('PedidoIfood.selo', { numero }) : t('PedidoIfood.seloSemNumero')}
            </Text>
        </XStack>
    );
};

const FaixaDaCobranca = ({ texto }: { texto: string }) => {
    const theme = useTheme();
    return (
        <XStack px='$3' py='$2' gap='$2' alignItems='center' borderRadius='$4' borderWidth={1} bg='$warning' borderColor='$warningBorder'>
            <FontAwesomeIcon icon={faMoneyBillWave} color={theme['$warningText'].val} />
            <Text color='$warningText' fontSize={16} fontWeight='bold' flex={1}>
                {texto}
            </Text>
        </XStack>
    );
};

const PedidoIfood = ({ dados, variante }: Props) => {
    const theme = useTheme();
    const { t } = useLanguage();

    if (!ehIfood(dados)) return null;

    const cobranca = temCobranca(dados) ? dados.cobranca.texto : null;

    if (variante === 'compacto') {
        return (
            <YStack mx='$3' mb='$3' gap='$2'>
                <Selo numero={dados.numero} />
                {cobranca && <FaixaDaCobranca texto={cobranca} />}
            </YStack>
        );
    }

    const ligar = async () => {
        if (!dados.telefone) return;
        try {
            await Linking.openURL(urlDoTelefone(dados.telefone.numero));
        } catch (err) {
            console.warn('[ifood] erro ao ligar:', err);
            toast.error(t('PedidoIfood.erroAoLigar'));
        }
    };

    const localizador = formatarLocalizador(dados.telefone?.localizador);
    const complemento = [dados.complemento, dados.referencia ? t('PedidoIfood.referencia', { texto: dados.referencia }) : null].filter(Boolean).join(' · ');

    return (
        <YStack>
            <SectionHeader title={t('PedidoIfood.titulo')} />
            <YStack px='$3' py='$3' gap='$3'>
                <Selo numero={dados.numero} />
                {dados.cancelado_pelo_ifood && (
                    <XStack px='$3' py='$2' borderRadius='$4' borderWidth={1} bg='$error' borderColor='$errorBorder'>
                        <Text color='$errorText' fontWeight='bold' flex={1}>
                            {dados.pago_mesmo_cancelado ? t('PedidoIfood.canceladoPago') : t('PedidoIfood.cancelado')}
                        </Text>
                    </XStack>
                )}
                {cobranca && <FaixaDaCobranca texto={cobranca} />}
                {dados.telefone && (
                    <YStack gap='$2'>
                        <Button onPress={ligar} bg='$info' borderWidth={1} borderColor='$infoBorder'>
                            <Button.Icon>
                                <FontAwesomeIcon icon={faPhone} color={theme['$infoText'].val} />
                            </Button.Icon>
                            <Button.Text color='$infoText'>{t('PedidoIfood.ligar')}</Button.Text>
                        </Button>
                        {localizador && (
                            <YStack alignItems='center'>
                                <Text color='$textSecondary' fontSize={13}>
                                    {t('PedidoIfood.localizador')}
                                </Text>
                                <Text color='$textPrimary' fontSize={28} fontWeight='bold' letterSpacing={2} selectable>
                                    {localizador}
                                </Text>
                                <Text color='$textSecondary' fontSize={12}>
                                    {t('PedidoIfood.copiarLocalizador')}
                                </Text>
                            </YStack>
                        )}
                    </YStack>
                )}
            </YStack>
            {(!!dados.observacoes || !!complemento) && (
                <YStack py='$2'>
                    {!!dados.observacoes && <SectionInfoLine title={t('PedidoIfood.observacoes')} value={dados.observacoes} />}
                    {!!dados.observacoes && !!complemento && <Separator />}
                    {!!complemento && <SectionInfoLine title={t('PedidoIfood.complemento')} value={complemento} />}
                </YStack>
            )}
        </YStack>
    );
};

export default PedidoIfood;
```

- [ ] **Step 2: `CodigoDeEntregaIfood`**

Crie `src/components/CodigoDeEntregaIfood.tsx`:

```tsx
import { useEffect, useState } from 'react';
import { KeyboardAvoidingView, Modal, Platform, TextInput } from 'react-native';
import { Button, Text, XStack, YStack, useTheme } from 'tamagui';
import { useLanguage } from '../contexts/LanguageContext';
import { codigoValido, limparCodigo } from '../utils/pedido-ifood';

// Entregas: campo do código de entrega do pedido iFood (o código que o cliente vê no app do iFood). onConfirmar confere com
// o servidor (rota codigo-ifood) e devolve null quando deu certo, ou a mensagem de erro para mostrar aqui mesmo (código
// incorreto, iFood fora do ar): o motoboy digita de novo sem fechar. Sem o código, ele fala com a central, que pode
// liberar a conclusão no console (painel iFood → "Liberar sem código").
type Props = {
    visivel: boolean;
    numero: string | null;
    onConfirmar: (codigo: string) => Promise<string | null>;
    onCancelar: () => void;
};

const CodigoDeEntregaIfood = ({ visivel, numero, onConfirmar, onCancelar }: Props) => {
    const theme = useTheme();
    const { t } = useLanguage();
    const [codigo, setCodigo] = useState('');
    const [erro, setErro] = useState<string | null>(null);
    const [conferindo, setConferindo] = useState(false);

    useEffect(() => {
        if (visivel) {
            setCodigo('');
            setErro(null);
            setConferindo(false);
        }
    }, [visivel]);

    const confirmar = async () => {
        if (!codigoValido(codigo) || conferindo) return;
        setConferindo(true);
        setErro(null);
        try {
            const mensagem = await onConfirmar(limparCodigo(codigo));
            if (mensagem) setErro(mensagem);
        } finally {
            setConferindo(false);
        }
    };

    return (
        <Modal visible={visivel} transparent animationType='fade' onRequestClose={onCancelar}>
            <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1 }}>
                <YStack flex={1} justifyContent='center' px='$4' bg='rgba(0,0,0,0.6)'>
                    <YStack bg='$background' borderRadius='$6' p='$4' gap='$3'>
                        <Text color='$textPrimary' fontSize={20} fontWeight='bold'>
                            {numero ? t('PedidoIfood.codigoTitulo', { numero }) : t('PedidoIfood.codigoTituloSemNumero')}
                        </Text>
                        <Text color='$textSecondary'>{t('PedidoIfood.codigoExplicacao')}</Text>
                        <TextInput
                            value={codigo}
                            onChangeText={(texto) => setCodigo(limparCodigo(texto))}
                            keyboardType='number-pad'
                            maxLength={10}
                            autoFocus
                            placeholder={t('PedidoIfood.codigoPlaceholder')}
                            placeholderTextColor={theme['$textSecondary'].val}
                            style={{
                                fontSize: 28,
                                letterSpacing: 6,
                                textAlign: 'center',
                                color: theme['$textPrimary'].val,
                                borderWidth: 1,
                                borderColor: theme['$borderColor'].val,
                                borderRadius: 12,
                                paddingVertical: 10,
                            }}
                        />
                        {!!erro && (
                            <Text color='$errorText' fontWeight='bold'>
                                {erro}
                            </Text>
                        )}
                        <XStack gap='$2'>
                            <Button flex={1} onPress={onCancelar} disabled={conferindo}>
                                <Button.Text>{t('PedidoIfood.codigoCancelar')}</Button.Text>
                            </Button>
                            <Button flex={1} bg='$success' borderColor='$successBorder' borderWidth={1} onPress={confirmar} disabled={!codigoValido(codigo) || conferindo}>
                                <Button.Text color='$successText'>{conferindo ? t('PedidoIfood.codigoConferindo') : t('PedidoIfood.codigoConfirmar')}</Button.Text>
                            </Button>
                        </XStack>
                    </YStack>
                </YStack>
            </KeyboardAvoidingView>
        </Modal>
    );
};

export default CodigoDeEntregaIfood;
```

- [ ] **Step 3: textos**

Em `translations/pt.json`, troque o fim do arquivo:

```json
    "MapaDaEntrega": {
        "coleta": "Coleta",
        "entrega": "Entrega",
        "parada": "Parada {{numero}}"
    }
}
```

por:

```json
    "MapaDaEntrega": {
        "coleta": "Coleta",
        "entrega": "Entrega",
        "parada": "Parada {{numero}}"
    },
    "PedidoIfood": {
        "titulo": "Pedido do iFood",
        "selo": "iFood #{{numero}}",
        "seloSemNumero": "iFood",
        "ligar": "Ligar para o cliente",
        "localizador": "Localizador (digite quando o 0800 pedir)",
        "copiarLocalizador": "Toque e segure no número para copiar",
        "erroAoLigar": "Não foi possível abrir o discador.",
        "observacoes": "Observações do cliente",
        "complemento": "Complemento e referência",
        "referencia": "Ref.: {{texto}}",
        "cancelado": "Cancelado pelo iFood. Não precisa mais fazer esta entrega.",
        "canceladoPago": "Cancelado pelo iFood depois da coleta. Você recebe por esta entrega. Combine com a loja a devolução.",
        "codigoTitulo": "Código de entrega do pedido iFood #{{numero}}",
        "codigoTituloSemNumero": "Código de entrega do iFood",
        "codigoExplicacao": "Peça ao cliente o código que aparece no app do iFood. Sem o código, fale com a central.",
        "codigoPlaceholder": "Código",
        "codigoConfirmar": "Confirmar",
        "codigoConferindo": "Conferindo…",
        "codigoCancelar": "Voltar",
        "tenteDeNovo": "Não consegui falar com o iFood agora. Tente de novo em alguns segundos.",
        "falhaAoAtualizar": "Não foi possível atualizar o pedido."
    }
}
```

Em `translations/en.json`, troque o fim do arquivo:

```json
    "MapaDaEntrega": {
        "coleta": "Pickup",
        "entrega": "Dropoff",
        "parada": "Stop {{numero}}"
    }
}
```

por:

```json
    "MapaDaEntrega": {
        "coleta": "Pickup",
        "entrega": "Dropoff",
        "parada": "Stop {{numero}}"
    },
    "PedidoIfood": {
        "titulo": "iFood order",
        "selo": "iFood #{{numero}}",
        "seloSemNumero": "iFood",
        "ligar": "Call the customer",
        "localizador": "Locator (type it when the 0800 asks)",
        "copiarLocalizador": "Touch and hold the number to copy",
        "erroAoLigar": "Could not open the dialer.",
        "observacoes": "Customer notes",
        "complemento": "Complement and reference",
        "referencia": "Ref.: {{texto}}",
        "cancelado": "Canceled by iFood. You don't need to deliver it anymore.",
        "canceladoPago": "Canceled by iFood after pickup. You get paid for this delivery. Arrange the return with the store.",
        "codigoTitulo": "Delivery code for iFood order #{{numero}}",
        "codigoTituloSemNumero": "iFood delivery code",
        "codigoExplicacao": "Ask the customer for the code shown in the iFood app. Without the code, talk to the dispatcher.",
        "codigoPlaceholder": "Code",
        "codigoConfirmar": "Confirm",
        "codigoConferindo": "Checking…",
        "codigoCancelar": "Back",
        "tenteDeNovo": "Could not reach iFood right now. Try again in a few seconds.",
        "falhaAoAtualizar": "Could not update the order."
    }
}
```

- [ ] **Step 4: conferir**

Run (na raiz do app):

```bash
node -e "for (const f of ['translations/pt.json','translations/en.json']) { const j = JSON.parse(require('fs').readFileSync(f,'utf8')); console.log(f, Object.keys(j.PedidoIfood).length); }"
B=$(ls -d "/c/Users/Edgardjr/Documents/vibe coding/Delivery/console/node_modules/.pnpm"/@babel+parser@7*/node_modules/@babel/parser | head -1)
node -e "const p=require(require('path').resolve(process.argv[1]));for(const f of process.argv.slice(2)){p.parse(require('fs').readFileSync(f,'utf8'),{sourceType:'module',plugins:['typescript','jsx']});console.log('OK',f)}" "$B" src/components/PedidoIfood.tsx src/components/CodigoDeEntregaIfood.tsx
```

Expected: `translations/pt.json 21`, `translations/en.json 21` e `OK` nos dois componentes.

- [ ] **Step 5: commit (repo do app)**

```bash
git rev-parse --show-toplevel
git add src/components/PedidoIfood.tsx src/components/CodigoDeEntregaIfood.tsx translations/pt.json translations/en.json
git commit -m "iFood: componentes do pedido (selo, cobrança, ligar) e do código de entrega

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: app, detalhes do pedido e a conclusão com o código

**Files (repo `entregas-navigator`):**
- Modify: `src/screens/OrderScreen.tsx`
- Modify: `src/components/AdhocOrderCard.tsx`

- [ ] **Step 1: importações do `OrderScreen`**

Em `src/screens/OrderScreen.tsx`, troque:

```tsx
import ValorDaEntrega from '../components/ValorDaEntrega';
```

por:

```tsx
import ValorDaEntrega from '../components/ValorDaEntrega';
import PedidoIfood from '../components/PedidoIfood';
import CodigoDeEntregaIfood from '../components/CodigoDeEntregaIfood';
import useDadosIfood from '../hooks/use-dados-ifood';
import { precisaPassarPeloIfood, resultadoDaConclusao } from '../utils/pedido-ifood';
```

- [ ] **Step 2: estado do pedido iFood**

Troque:

```tsx
    const [isAccepting, setIsAccepting] = useState(false);
    const distanceLoadedRef = useRef(false);
```

por:

```tsx
    const [isAccepting, setIsAccepting] = useState(false);
    // Entregas: pedido do iFood (dados e conclusão com o código do cliente). O ref deixa o sendOrderActivityUpdate
    // (memorizado com [order]) ler os dados mais novos
    const { dados: dadosIfood, recarregar: recarregarIfood } = useDadosIfood(order.id);
    const dadosIfoodRef = useRef(dadosIfood);
    dadosIfoodRef.current = dadosIfood;
    const [codigoIfood, setCodigoIfood] = useState(null);
    const distanceLoadedRef = useRef(false);
```

- [ ] **Step 3: a conclusão passa pelo iFood**

Troque:

```tsx
    const sendOrderActivityUpdate = useCallback(
        async (activity, proof) => {
            setActivityLoading(activity.code);
```

por:

```tsx
    // Entregas: rota concluir-ifood (o servidor avisa a chegada ao iFood). true = pode concluir pelo fluxo de sempre;
    // false = abriu o campo do código ou deu erro (toast)
    const liberarConclusaoIfood = async (activity, proof) => {
        try {
            const resposta = await adapter.post(`entregas/motoboy/pedidos/${order.id}/concluir-ifood`);
            const resultado = resultadoDaConclusao(resposta);
            if (resultado === 'pode_concluir') {
                recarregarIfood();
                return true;
            }
            if (resultado === 'precisa_codigo') {
                activitySheetRef.current?.closeBottomSheet();
                setCodigoIfood({ activity, proof });
                return false;
            }
            toast.error(t('PedidoIfood.tenteDeNovo'));
        } catch (err) {
            console.warn('[ifood] concluir:', err);
            toast.error(err?.message || t('PedidoIfood.tenteDeNovo'));
        }
        return false;
    };

    // Entregas: o código digitado (rota codigo-ifood, conferida com o iFood na hora). null = certo (segue a conclusão);
    // texto = o erro para o campo mostrar (código incorreto, iFood fora do ar)
    const confirmarCodigoIfood = async (codigo) => {
        try {
            const resposta = await adapter.post(`entregas/motoboy/pedidos/${order.id}/codigo-ifood`, { codigo });
            if (resultadoDaConclusao(resposta) !== 'pode_concluir') {
                return t('PedidoIfood.tenteDeNovo');
            }
        } catch (err) {
            console.warn('[ifood] código:', err);
            return err?.message || t('PedidoIfood.tenteDeNovo');
        }
        const pendente = codigoIfood;
        setCodigoIfood(null);
        recarregarIfood();
        if (pendente) {
            sendOrderActivityUpdate(pendente.activity, pendente.proof);
        }
        return null;
    };

    const sendOrderActivityUpdate = useCallback(
        async (activity, proof) => {
            // Entregas: pedido do iFood avisa o iFood e confere o código antes da conclusão comum (a trava "Atualize o
            // app" do servidor só deixa concluir depois disso). Antes da prova de entrega: o motoboy não fotografa à toa
            if (precisaPassarPeloIfood(dadosIfoodRef.current, activity) && !(await liberarConclusaoIfood(activity, proof))) {
                return;
            }

            setActivityLoading(activity.code);
```

- [ ] **Step 4: erro do servidor aparece ao motoboy**

Troque:

```tsx
                    setShowDestAlert(true);
                }
            } catch (err) {
                console.warn('Error updating order activity:', err);
            } finally {
```

por:

```tsx
                    setShowDestAlert(true);
                }
            } catch (err) {
                console.warn('Error updating order activity:', err);
                // Entregas: a recusa do servidor (ex.: "Atualize o app para concluir pedidos do iFood.") aparece ao motoboy
                toast.error(err?.message || t('PedidoIfood.falhaAoAtualizar'));
            } finally {
```

- [ ] **Step 5: o bloco do iFood nos detalhes e o campo do código**

Troque:

```tsx
                </ActionContainer>
                {/* Entregas: km da entrega, faixa e o valor que o motoboy recebe */}
```

por:

```tsx
                </ActionContainer>
                {/* Entregas: pedido do iFood (selo, cobrança na porta, ligar para o cliente, observações) */}
                <PedidoIfood dados={dadosIfood} variante='detalhado' />
                {/* Entregas: km da entrega, faixa e o valor que o motoboy recebe */}
```

e troque:

```tsx
            <PortalHost name='OrderScreenPortal' />
        </YStack>
```

por:

```tsx
            <PortalHost name='OrderScreenPortal' />
            {/* Entregas: código de entrega do pedido iFood */}
            <CodigoDeEntregaIfood
                visivel={codigoIfood !== null}
                numero={dadosIfood?.ifood ? dadosIfood.numero : null}
                onConfirmar={confirmarCodigoIfood}
                onCancelar={() => setCodigoIfood(null)}
            />
        </YStack>
```

- [ ] **Step 6: o card de aceitar**

Em `src/components/AdhocOrderCard.tsx`, troque:

```tsx
import ValorDaEntrega from './ValorDaEntrega';
```

por:

```tsx
import ValorDaEntrega from './ValorDaEntrega';
import PedidoIfood from './PedidoIfood';
import useDadosIfood from '../hooks/use-dados-ifood';
```

troque:

```tsx
    const { t } = useLanguage();
    const [isAccepting, setIsAccepting] = useState(false);
```

por:

```tsx
    const { t } = useLanguage();
    const [isAccepting, setIsAccepting] = useState(false);
    // Entregas: selo e cobrança do pedido iFood no card
    const { dados: dadosIfood } = useDadosIfood(order.id);
```

e troque:

```tsx
                    <ValorDaEntrega pedido={order.id} variante='compacto' />
```

por:

```tsx
                    <ValorDaEntrega pedido={order.id} variante='compacto' />
                    {/* Entregas: pedido do iFood (selo e cobrança na porta) */}
                    <PedidoIfood dados={dadosIfood} variante='compacto' />
```

- [ ] **Step 7: conferir**

Run (na raiz do app):

```bash
B=$(ls -d "/c/Users/Edgardjr/Documents/vibe coding/Delivery/console/node_modules/.pnpm"/@babel+parser@7*/node_modules/@babel/parser | head -1)
node -e "const p=require(require('path').resolve(process.argv[1]));for(const f of process.argv.slice(2)){p.parse(require('fs').readFileSync(f,'utf8'),{sourceType:'module',plugins:['typescript','jsx']});console.log('OK',f)}" "$B" src/screens/OrderScreen.tsx src/components/AdhocOrderCard.tsx
node --experimental-strip-types --test scripts/testes/*.teste.ts
```

Expected: `OK` nos dois e `# fail 0`.

- [ ] **Step 8: commit (repo do app)**

```bash
git rev-parse --show-toplevel
git add src/screens/OrderScreen.tsx src/components/AdhocOrderCard.tsx
git commit -m "iFood: detalhes e card com os dados do iFood; conclusão com o código de entrega

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: app, alarme e ganhos

**Files (repo `entregas-navigator`):**
- Modify: `android/app/src/main/java/io/fleetbase/navigator/AlarmePedidoActivity.kt`
- Modify: `android/app/src/main/res/values/entregas_strings.xml`
- Modify: `src/utils/ganhos.ts`, `src/screens/MeusGanhosScreen.tsx`, `translations/pt.json`, `translations/en.json`

- [ ] **Step 1: o cartão do alarme lê os dados do iFood**

Em `AlarmePedidoActivity.kt`, no `montarCartao()`, troque:

```kotlin
    val destino = dados.getString("entregas_destino")
    val temCartao = valor != null || km != null || loja != null || destino != null
```

por:

```kotlin
    val destino = dados.getString("entregas_destino")
    // Entregas: pedido do iFood (CartaoDoAlarme do servidor): o número e o texto da cobrança na porta
    val numeroIfood = dados.getString("entregas_ifood")
    val cobrar = dados.getString("entregas_cobrar")
    val temCartao = valor != null || km != null || loja != null || destino != null || numeroIfood != null || cobrar != null
```

e troque:

```kotlin
      addView(rotulo(titulo, 26f, Color.WHITE, negrito = true))

      if (temCartao) {
        valor?.let { addView(rotulo(it, 44f, Color.parseColor("#4ADE80"), negrito = true).comMargem(topo = 16)) }
```

por:

```kotlin
      addView(rotulo(titulo, 26f, Color.WHITE, negrito = true))
      numeroIfood?.let { addView(seloIfood(it).comMargem(topo = 10)) }

      if (temCartao) {
        valor?.let { addView(rotulo(it, 44f, Color.parseColor("#4ADE80"), negrito = true).comMargem(topo = 16)) }
        cobrar?.let { addView(faixaDaCobranca(it).comMargem(topo = 14)) }
```

- [ ] **Step 2: o selo e a faixa**

Ainda em `AlarmePedidoActivity.kt`, troque:

```kotlin
  /** "3,2 km · 9 min" em destaque e, embaixo, de onde até onde: o km que o motoboy roda e pelo qual é pago. */
```

por:

```kotlin
  /** Entregas: selo vermelho "iFood #4821" embaixo do título (pedido do iFood). */
  private fun seloIfood(numero: String): TextView =
    rotulo(getString(R.string.alarme_ifood, numero), 16f, Color.WHITE, negrito = true).apply {
      background = GradientDrawable().apply {
        setColor(Color.parseColor("#EA1D2C"))
        cornerRadius = dp(8).toFloat()
      }
      setPadding(dp(12), dp(4), dp(12), dp(4))
      layoutParams = LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.WRAP_CONTENT)
    }

  /** Entregas: faixa amarela da cobrança na porta ("Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100"). */
  private fun faixaDaCobranca(texto: String): TextView =
    rotulo(texto, 20f, Color.parseColor("#422006"), negrito = true).apply {
      background = GradientDrawable().apply {
        setColor(Color.parseColor("#FACC15"))
        cornerRadius = dp(14).toFloat()
      }
      setPadding(dp(18), dp(12), dp(18), dp(12))
    }

  /** "3,2 km · 9 min" em destaque e, embaixo, de onde até onde: o km que o motoboy roda e pelo qual é pago. */
```

(O `rotulo()` já devolve um `TextView` com largura total e texto centralizado; `GradientDrawable`, `LinearLayout`,
`ViewGroup` e `TextView` já são importados no arquivo.)

- [ ] **Step 3: o texto nativo**

Em `android/app/src/main/res/values/entregas_strings.xml`, troque:

```xml
    <string name="alarme_restante">Fecha em %1$d:%2$02d</string>
</resources>
```

por:

```xml
    <string name="alarme_restante">Fecha em %1$d:%2$02d</string>
    <!-- selo do pedido do iFood no cartão do alarme -->
    <string name="alarme_ifood">iFood #%1$s</string>
</resources>
```

- [ ] **Step 4: ganhos com o pago mesmo cancelado**

Em `src/utils/ganhos.ts`, troque:

```ts
    faixa: Faixa | null;
    valor: number | null;
};
```

(o fim do tipo `EntregaDoMotoboy`) por:

```ts
    faixa: Faixa | null;
    valor: number | null;
    /** Pedido iFood cancelado pelo iFood depois da coleta: ele recebe (a data é a do cancelamento). Ausente no servidor antigo. */
    cancelado_pago?: boolean;
};
```

Em `src/screens/MeusGanhosScreen.tsx`, troque:

```tsx
                        {!!item.destino && (
                            <Text color='$textSecondary' fontSize={13} numberOfLines={1}>
                                {item.destino}
                            </Text>
                        )}
```

por:

```tsx
                        {!!item.destino && (
                            <Text color='$textSecondary' fontSize={13} numberOfLines={1}>
                                {item.destino}
                            </Text>
                        )}
                        {item.cancelado_pago && (
                            <Text color='$warningText' fontSize={12} fontWeight='600'>
                                {t('MeusGanhosScreen.canceladoPago')}
                            </Text>
                        )}
```

Em `translations/pt.json`, troque `        "semLoja": "Loja não identificada",` por:

```json
        "semLoja": "Loja não identificada",
        "canceladoPago": "Cancelado pelo iFood: você recebe",
```

Em `translations/en.json`, troque `        "semLoja": "Unknown store",` por:

```json
        "semLoja": "Unknown store",
        "canceladoPago": "Canceled by iFood: you get paid",
```

(Confira antes que só existe um `"semLoja"` em cada arquivo: `grep -c '"semLoja"' translations/pt.json` → 1.)

- [ ] **Step 5: conferir**

Run (na raiz do app):

```bash
node -e "for (const f of ['translations/pt.json','translations/en.json']) { const j = JSON.parse(require('fs').readFileSync(f,'utf8')); console.log(f, j.MeusGanhosScreen.canceladoPago); }"
B=$(ls -d "/c/Users/Edgardjr/Documents/vibe coding/Delivery/console/node_modules/.pnpm"/@babel+parser@7*/node_modules/@babel/parser | head -1)
node -e "const p=require(require('path').resolve(process.argv[1]));for(const f of process.argv.slice(2)){p.parse(require('fs').readFileSync(f,'utf8'),{sourceType:'module',plugins:['typescript','jsx']});console.log('OK',f)}" "$B" src/utils/ganhos.ts src/screens/MeusGanhosScreen.tsx
node --experimental-strip-types --test scripts/testes/*.teste.ts
```

Expected: os dois textos, `OK` e `# fail 0`. O Kotlin compila no GitHub Actions (Task 12).

- [ ] **Step 6: commit (repo do app)**

```bash
git rev-parse --show-toplevel
git add android/app/src/main/java/io/fleetbase/navigator/AlarmePedidoActivity.kt android/app/src/main/res/values/entregas_strings.xml src/utils/ganhos.ts src/screens/MeusGanhosScreen.tsx translations/pt.json translations/en.json
git commit -m "iFood: selo e cobrança no cartão do alarme; ganhos marcam o cancelado pelo iFood (pago)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: console, funções puras do pedido iFood (e o número no portal)

**Files:**
- Create: `packages/fleetops/addon/utils/pedido-ifood.js`
- Modify: `packages/customer-portal/addon/utils/entregas-pedido.js`
- Test: `scripts/teste-portal/pedido-ifood.test.mjs`

- [ ] **Step 1: o teste**

Crie `scripts/teste-portal/pedido-ifood.test.mjs`:

```js
// Pedido do iFood no console (packages/fleetops/addon/utils/pedido-ifood.js) e no portal da loja
// (packages/customer-portal/addon/utils/entregas-pedido.js, numeroIfood).
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/pedido-ifood.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { algumPedidoIfood, avisoAcaoRecusada, ehPedidoIfood, mostraTroco, numeroIfood, numeroIfoodDoPedido, partesDaForma, reais } from '../../packages/fleetops/addon/utils/pedido-ifood.js';
import { numeroIfood as numeroIfoodNoPortal } from '../../packages/customer-portal/addon/utils/entregas-pedido.js';

test('número do iFood: notas "iFood #<n>" e o internal_id igual', () => {
    assert.equal(numeroIfood('iFood #4821', '4821'), '4821');
    assert.equal(numeroIfood('iFood #4821 [TESTE]', '4821'), '4821');
    assert.equal(numeroIfood('  iFood #4821 [SEM LOCALIZAÇÃO]', 4821), '4821');
    assert.equal(numeroIfood('iFood #4821', '9999'), null, 'internal_id diferente: não é');
    assert.equal(numeroIfood('Entregar no portão', '4821'), null);
    assert.equal(numeroIfood('iFood #4821', null), null);
    assert.equal(numeroIfood(null, '4821'), null);
});

test('pedido (model ou objeto)', () => {
    assert.equal(numeroIfoodDoPedido({ notes: 'iFood #77', internal_id: '77' }), '77');
    assert.equal(ehPedidoIfood({ notes: 'iFood #77', internal_id: '77' }), true);
    assert.equal(ehPedidoIfood({ notes: null, internal_id: null }), false);
    assert.equal(ehPedidoIfood(null), false);
    assert.equal(algumPedidoIfood([{ notes: '', internal_id: null }, { notes: 'iFood #1', internal_id: '1' }]), true);
    assert.equal(algumPedidoIfood([{ notes: '', internal_id: null }]), false);
    assert.equal(algumPedidoIfood(undefined), false);
});

test('aviso de ação recusada', () => {
    assert.deepEqual(avisoAcaoRecusada({ event: 'entregas.ifood_acao_recusada', data: { id: 'order_a', uuid: 'u-a', numero: '4821', acao: 'dispatch', status: 409 } }), {
        id: 'order_a',
        uuid: 'u-a',
        numero: '4821',
        acao: 'dispatch',
        status: 409,
    });
    assert.deepEqual(avisoAcaoRecusada({ event: 'entregas.ifood_acao_recusada', data: { id: 'order_a', acao: 'outra', status: 'x' } }), {
        id: 'order_a',
        uuid: null,
        numero: 'order_a',
        acao: 'desconhecida',
        status: 0,
    });
    assert.equal(avisoAcaoRecusada({ event: 'order.updated', data: { id: 'order_a' } }), null);
    assert.equal(avisoAcaoRecusada({ event: 'entregas.ifood_acao_recusada', data: {} }), null);
    assert.equal(avisoAcaoRecusada(null), null);
});

test('cobrança: reais, formas e troco', () => {
    assert.equal(reais(5890), 'R$ 58,90');
    assert.equal(reais(123456), 'R$ 1.234,56');
    assert.equal(reais(10000, true), 'R$ 100');
    assert.equal(reais(10050, true), 'R$ 100,50');
    assert.deepEqual(partesDaForma('CASH+CREDIT'), [{ chave: 'dinheiro' }, { chave: 'credito' }]);
    assert.deepEqual(partesDaForma('BOLETO_X'), [{ texto: 'boleto_x' }]);
    assert.deepEqual(partesDaForma(null), []);
    assert.equal(mostraTroco(5890, 'CASH', 10000), true);
    assert.equal(mostraTroco(5890, 'CASH', 5000), false);
    assert.equal(mostraTroco(5890, 'CREDIT', 10000), false);
    assert.equal(mostraTroco(5890, 'CASH', null), false);
});

test('portal: o mesmo número do iFood', () => {
    assert.equal(numeroIfoodNoPortal('iFood #4821 [TESTE]', '4821'), '4821');
    assert.equal(numeroIfoodNoPortal('iFood #4821', '1'), null);
    assert.equal(numeroIfoodNoPortal(undefined, undefined), null);
});
```

- [ ] **Step 2: rodar e ver falhar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/pedido-ifood.test.mjs`
Expected: falha `Cannot find module .../packages/fleetops/addon/utils/pedido-ifood.js`.

- [ ] **Step 3: as funções do console**

Crie `packages/fleetops/addon/utils/pedido-ifood.js`:

```js
// Entregas: pedido do iFood no console (selo "iFood #4821", painel iFood do detalhe, cancelamento escondido e o aviso
// de ação recusada pelo iFood). Sem imports: testado no Node (scripts/teste-portal/pedido-ifood.test.mjs).

/** Evento no canal da empresa quando o iFood recusa uma ação de logística (App\Events\Entregas\IfoodAcaoRecusada). */
export const EVENTO_ACAO_RECUSADA = 'entregas.ifood_acao_recusada';

/** Ações de logística, na ordem do iFood (App\Support\Entregas\Ifood\SequenciaIfood::ACOES). */
export const ACOES_IFOOD = ['assignDriver', 'goingToOrigin', 'arrivedAtOrigin', 'dispatch', 'arrivedAtDestination'];

/** Formas de pagamento do iFood → chave de tradução (fleet-ops.ui.ifood.forma.<chave>). */
export const FORMAS = {
    CASH: 'dinheiro',
    CREDIT: 'credito',
    DEBIT: 'debito',
    MEAL_VOUCHER: 'vale-refeicao',
    FOOD_VOUCHER: 'vale-alimentacao',
    PIX: 'pix',
    DIGITAL_WALLET: 'carteira-digital',
    MISTO: 'misto',
};

/**
 * Número do iFood do pedido, ou null. O CriadorDoPedidoIfood grava as notas "iFood #4821" (às vezes com marcas depois:
 * " [TESTE]") e o internal_id = 4821: os dois juntos evitam confundir um pedido comum cujas notas a central escreveu
 * assim. O servidor confere de verdade (RegrasDoPedidoIfood); aqui é só a tela.
 */
export function numeroIfood(notas, idInterno) {
    if (typeof notas !== 'string' || idInterno === null || idInterno === undefined || idInterno === '') {
        return null;
    }
    const achado = /^iFood #(\S+)/.exec(notas.trim());

    return achado && achado[1] === String(idInterno) ? achado[1] : null;
}

/** O número do iFood de um pedido (model do Ember ou objeto com notes e internal_id), ou null. */
export function numeroIfoodDoPedido(pedido) {
    return pedido ? numeroIfood(pedido.notes, pedido.internal_id) : null;
}

export function ehPedidoIfood(pedido) {
    return numeroIfoodDoPedido(pedido) !== null;
}

/** Algum pedido do iFood na lista (cancelamento em lote). */
export function algumPedidoIfood(pedidos) {
    return Array.from(pedidos ?? []).some((pedido) => ehPedidoIfood(pedido));
}

/** {id, uuid, numero, acao, status} do evento de ação recusada, ou null para outra mensagem. */
export function avisoAcaoRecusada(mensagem) {
    if (mensagem?.event !== EVENTO_ACAO_RECUSADA) {
        return null;
    }
    const dados = mensagem.data ?? {};
    if (typeof dados.id !== 'string' || dados.id === '') {
        return null;
    }
    const status = Number(dados.status);

    return {
        id: dados.id,
        uuid: typeof dados.uuid === 'string' && dados.uuid !== '' ? dados.uuid : null,
        numero: String(dados.numero ?? dados.id),
        acao: ACOES_IFOOD.includes(dados.acao) ? dados.acao : 'desconhecida',
        status: Number.isFinite(status) ? status : 0,
    };
}

/** "R$ 58,90"; com semCentavosSeInteiro, "R$ 100" em vez de "R$ 100,00" (como o CobrancaIfood do servidor). */
export function reais(centavos, semCentavosSeInteiro = false) {
    const valor = Math.round(Number(centavos) || 0);
    const inteiro = Math.floor(Math.abs(valor) / 100);
    const resto = Math.abs(valor) % 100;
    const milhar = String(inteiro).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    const sinal = valor < 0 ? '-' : '';

    return semCentavosSeInteiro && resto === 0 ? `R$ ${sinal}${milhar}` : `R$ ${sinal}${milhar},${String(resto).padStart(2, '0')}`;
}

/** As partes da forma de pagamento: [{chave}] para as conhecidas e [{texto}] para as outras ("CASH+CREDIT" → duas). */
export function partesDaForma(forma) {
    if (typeof forma !== 'string' || forma.trim() === '') {
        return [];
    }

    return forma
        .toUpperCase()
        .split('+')
        .map((parte) => parte.trim())
        .filter(Boolean)
        .map((parte) => (FORMAS[parte] ? { chave: FORMAS[parte] } : { texto: parte.toLowerCase() }));
}

/** O troco aparece só com dinheiro entre as formas e maior que o valor a cobrar (mesma regra do servidor). */
export function mostraTroco(centavos, forma, trocoPara) {
    return trocoPara !== null && trocoPara !== undefined && Number(trocoPara) > Number(centavos) && partesDaForma(forma).some((parte) => parte.chave === 'dinheiro');
}
```

- [ ] **Step 4: o número no portal**

No fim de `packages/customer-portal/addon/utils/entregas-pedido.js`, acrescente:

```js
/**
 * Número do iFood do pedido, ou null: as notas começam com "iFood #<número>" e o internal_id é o mesmo número
 * (CriadorDoPedidoIfood). O portal mostra o selo e esconde o Cancelar; o servidor recusa o cancelamento de qualquer
 * jeito (RegrasPortalLoja). Mesma regra do console (packages/fleetops/addon/utils/pedido-ifood.js).
 */
export function numeroIfood(notas, idInterno) {
    if (typeof notas !== 'string' || idInterno === null || idInterno === undefined || idInterno === '') {
        return null;
    }
    const achado = /^iFood #(\S+)/.exec(notas.trim());

    return achado && achado[1] === String(idInterno) ? achado[1] : null;
}
```

- [ ] **Step 5: rodar e ver passar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs`
Expected: `# fail 0`.

- [ ] **Step 6: commit**

```bash
git rev-parse --show-toplevel
git add packages/fleetops/addon/utils/pedido-ifood.js packages/customer-portal/addon/utils/entregas-pedido.js scripts/teste-portal/pedido-ifood.test.mjs
git commit -m "iFood etapa 4: funções puras do pedido iFood no console e no portal

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: console, selo "iFood #4821"

**Files:**
- Create: `packages/fleetops/addon/components/pedido-ifood/selo.js`, `selo.hbs`, `packages/fleetops/app/components/pedido-ifood/selo.js`
- Create: `packages/fleetops/addon/components/cell/pedido-ifood.js`, `pedido-ifood.hbs`, `packages/fleetops/app/components/cell/pedido-ifood.js`
- Modify: `packages/fleetops/addon/controllers/operations/orders/index.js`
- Modify: `packages/fleetops/addon/components/order/kanban-card.hbs`, `order/panel-header.hbs`, `map/order-list-overlay/order.hbs`
- Modify: `packages/fleetops/translations/pt-br.yaml`, `en-us.yaml` (todas as chaves `fleet-ops.ui.ifood.*` deste plano)

- [ ] **Step 1: o selo**

Crie `packages/fleetops/addon/components/pedido-ifood/selo.js`:

```js
import Component from '@glimmer/component';
import { numeroIfoodDoPedido } from '../../utils/pedido-ifood';

/**
 * Entregas: selo "iFood #4821" do pedido que veio do iFood (lista, quadro, mapa e cabeçalho do detalhe). Não mostra nada
 * nos outros pedidos. A regra (notas + internal_id) fica em utils/pedido-ifood.js.
 */
export default class PedidoIfoodSeloComponent extends Component {
    get numero() {
        return numeroIfoodDoPedido(this.args.order);
    }
}
```

Crie `packages/fleetops/addon/components/pedido-ifood/selo.hbs`:

```hbs
{{#if this.numero}}
    <span class="inline-flex items-center whitespace-nowrap rounded px-1.5 py-0.5 text-xs font-semibold text-white" style="background-color: #EA1D2C;" title={{t "fleet-ops.ui.ifood.selo-titulo"}} ...attributes>
        {{t "fleet-ops.ui.ifood.selo" numero=this.numero}}
    </span>
{{/if}}
```

Crie `packages/fleetops/app/components/pedido-ifood/selo.js`:

```js
export { default } from '@fleetbase/fleetops-engine/components/pedido-ifood/selo';
```

- [ ] **Step 2: a célula da tabela**

Crie `packages/fleetops/addon/components/cell/pedido-ifood.js`:

```js
import Component from '@glimmer/component';

/** Entregas: célula da coluna "iFood" da tabela de pedidos (o selo, ou vazio). */
export default class CellPedidoIfoodComponent extends Component {}
```

Crie `packages/fleetops/addon/components/cell/pedido-ifood.hbs`:

```hbs
<PedidoIfood::Selo @order={{@row}} />
```

Crie `packages/fleetops/app/components/cell/pedido-ifood.js`:

```js
export { default } from '@fleetbase/fleetops-engine/components/cell/pedido-ifood';
```

- [ ] **Step 3: a coluna "iFood" na tabela de pedidos**

Em `packages/fleetops/addon/controllers/operations/orders/index.js`, troque:

```js
            {
                label: this.intl.t('column.route-type'),
```

por:

```js
            {
                // Entregas: selo "iFood #4821" do pedido que veio do iFood (vazio nos outros)
                label: this.intl.t('fleet-ops.ui.ifood.coluna'),
                valuePath: 'internal_id',
                cellComponent: 'cell/pedido-ifood',
                resizable: true,
            },
            {
                label: this.intl.t('column.route-type'),
```

- [ ] **Step 4: o selo no quadro, no mapa e no cabeçalho do detalhe**

Em `packages/fleetops/addon/components/order/kanban-card.hbs`, troque:

```hbs
            <h4 class="kanban-card-title">{{@card.tracking}}</h4>
            <Badge @status={{@card.status}} />
```

por:

```hbs
            <h4 class="kanban-card-title">{{@card.tracking}}</h4>
            <div class="flex flex-row flex-wrap items-center gap-1">
                <Badge @status={{@card.status}} />
                <PedidoIfood::Selo @order={{@card}} />
            </div>
```

Em `packages/fleetops/addon/components/order/panel-header.hbs`, troque:

```hbs
                <div class="flex flex-row space-x-1">
                    <Badge @status={{@resource.status}} />
```

por:

```hbs
                <div class="flex flex-row space-x-1">
                    <Badge @status={{@resource.status}} />
                    <PedidoIfood::Selo @order={{@resource}} />
```

Em `packages/fleetops/addon/components/map/order-list-overlay/order.hbs`, troque:

```hbs
                <div class="order-listing-row-header-title truncate">{{@order.tracking}}</div>
                <Badge @status={{@order.status}} />
```

por:

```hbs
                <div class="order-listing-row-header-title truncate">{{@order.tracking}}</div>
                <Badge @status={{@order.status}} />
                <PedidoIfood::Selo @order={{@order}} />
```

- [ ] **Step 5: as traduções (todas as do plano)**

Em `packages/fleetops/translations/pt-br.yaml`, troque:

```yaml
    pedido-sem-motoboy:
      aviso: 'Pedido {numero} está há {minutos} min sem motoboy. Clique para abrir e atribuir.'
```

por:

```yaml
    pedido-sem-motoboy:
      aviso: 'Pedido {numero} está há {minutos} min sem motoboy. Clique para abrir e atribuir.'
    ifood:
      coluna: iFood
      selo: 'iFood #{numero}'
      selo-titulo: Pedido do iFood
      painel-titulo: 'iFood #{numero}'
      erro: Não foi possível carregar os dados do iFood (só administradores veem este painel).
      ultima-acao: Última etapa informada ao iFood
      nenhuma-acao: Nenhuma ainda
      ultima-recusa: Última recusa do iFood
      recusa: '{acao} (erro {status})'
      cobranca: Cobrança na porta
      pago-online: Pago online (nada a cobrar)
      troco: 'troco p/ {valor}'
      codigo: Código de entrega
      codigo-nao-exigido: Não exigido
      codigo-pendente: Exigido, ainda não conferido
      codigo-conferido: Conferido pelo motoboy
      codigo-dispensado: Concluído sem o código (liberado pela central ou concluído no console)
      observacoes: Observações do cliente
      complemento: Complemento e referência
      referencia: 'Ref.: {texto}'
      cancelado: Cancelado pelo iFood
      cancelado-pago: Cancelado pelo iFood depois da coleta (o motoboy recebe e a loja é cobrada)
      liberar: Liberar sem código
      liberar-titulo: 'Liberar o pedido iFood #{numero} sem o código?'
      liberar-texto: O motoboy poderá concluir sem o código do cliente. O iFood fica com a confirmação pendente e conclui sozinho em 4 horas. Use só quando o cliente não tiver o código.
      liberado: Conclusão liberada sem o código.
      nao-cancela: 'Pedido do iFood: o cancelamento é feito no iFood (Gestor de Pedidos).'
      cancelar-em-lote: Há pedido do iFood na seleção. O cancelamento dele é feito no iFood; tire-o da seleção.
      aviso-recusa: 'O iFood recusou "{acao}" no pedido {numero} (erro {status}). Clique para abrir e conferir no Gestor de Pedidos.'
      acao:
        assignDriver: Motoboy informado
        goingToOrigin: A caminho da loja
        arrivedAtOrigin: Chegou na loja
        dispatch: Saiu para entrega
        arrivedAtDestination: Chegou no cliente
        desconhecida: Etapa desconhecida
      forma:
        dinheiro: dinheiro
        credito: cartão de crédito
        debito: cartão de débito
        vale-refeicao: vale-refeição
        vale-alimentacao: vale-alimentação
        pix: Pix
        carteira-digital: carteira digital
        misto: formas variadas
```

e, no bloco `driver-payouts`, troque `      without-store: Sem local de coleta` por:

```yaml
      without-store: Sem local de coleta
      cancelado-pago: Cancelado pelo iFood (pago)
```

Em `packages/fleetops/translations/en-us.yaml` (4 espaços por nível), troque:

```yaml
        pedido-sem-motoboy:
            aviso: 'Order {numero} has had no driver for {minutos} min. Click to open and assign.'
```

por:

```yaml
        pedido-sem-motoboy:
            aviso: 'Order {numero} has had no driver for {minutos} min. Click to open and assign.'
        ifood:
            coluna: iFood
            selo: 'iFood #{numero}'
            selo-titulo: iFood order
            painel-titulo: 'iFood #{numero}'
            erro: Could not load the iFood data (only administrators see this panel).
            ultima-acao: Last step sent to iFood
            nenhuma-acao: None yet
            ultima-recusa: Last iFood refusal
            recusa: '{acao} (error {status})'
            cobranca: Collect at the door
            pago-online: Paid online (nothing to collect)
            troco: 'change for {valor}'
            codigo: Delivery code
            codigo-nao-exigido: Not required
            codigo-pendente: Required, not checked yet
            codigo-conferido: Checked by the courier
            codigo-dispensado: Completed without the code (released by the dispatcher or completed in the console)
            observacoes: Customer notes
            complemento: Complement and reference
            referencia: 'Ref.: {texto}'
            cancelado: Canceled by iFood
            cancelado-pago: Canceled by iFood after pickup (the courier is paid and the store is charged)
            liberar: Release without code
            liberar-titulo: 'Release iFood order #{numero} without the code?'
            liberar-texto: The courier will be able to complete without the customer's code. iFood keeps the confirmation pending and completes it on its own after 4 hours. Use it only when the customer does not have the code.
            liberado: Completion released without the code.
            nao-cancela: 'iFood order: cancel it in iFood (order manager).'
            cancelar-em-lote: There is an iFood order in the selection. Cancel it in iFood; remove it from the selection.
            aviso-recusa: 'iFood refused "{acao}" for order {numero} (error {status}). Click to open and check the iFood order manager.'
            acao:
                assignDriver: Courier assigned
                goingToOrigin: Going to the store
                arrivedAtOrigin: Arrived at the store
                dispatch: Out for delivery
                arrivedAtDestination: Arrived at the customer
                desconhecida: Unknown step
            forma:
                dinheiro: cash
                credito: credit card
                debito: debit card
                vale-refeicao: meal voucher
                vale-alimentacao: food voucher
                pix: Pix
                carteira-digital: digital wallet
                misto: mixed methods
```

e troque `            without-store: No pickup place` por:

```yaml
            without-store: No pickup place
            cancelado-pago: Canceled by iFood (paid)
```

(O `liberar-texto` em inglês tem apóstrofo dentro de texto sem aspas: o YAML aceita. O ICU do ember-intl só trata `'`
como escape antes de `{`; aqui não há.)

- [ ] **Step 6: conferir**

Run (na raiz do repo):

```bash
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal
B=$(ls -d console/node_modules/.pnpm/@babel+parser@7*/node_modules/@babel/parser | head -1)
node -e "const p=require(require('path').resolve(process.argv[1]));for(const f of process.argv.slice(2)){p.parse(require('fs').readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties','classPrivateMethods']});console.log('OK',f)}" "$B" packages/fleetops/addon/components/pedido-ifood/selo.js packages/fleetops/addon/components/cell/pedido-ifood.js packages/fleetops/addon/controllers/operations/orders/index.js
```

Expected: o i18n-check sai com código 0 (as chaves usadas nas Tasks 7 a 9 já existem) e `OK` nos três.

- [ ] **Step 7: commit**

```bash
git rev-parse --show-toplevel
git add packages/fleetops/addon/components/pedido-ifood packages/fleetops/app/components/pedido-ifood packages/fleetops/addon/components/cell/pedido-ifood.js packages/fleetops/addon/components/cell/pedido-ifood.hbs packages/fleetops/app/components/cell/pedido-ifood.js packages/fleetops/addon/controllers/operations/orders/index.js packages/fleetops/addon/components/order/kanban-card.hbs packages/fleetops/addon/components/order/panel-header.hbs packages/fleetops/addon/components/map/order-list-overlay/order.hbs packages/fleetops/translations/pt-br.yaml packages/fleetops/translations/en-us.yaml
git commit -m "iFood etapa 4: selo \"iFood #4821\" no console (tabela, quadro, mapa e cabeçalho)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: console, painel iFood no detalhe do pedido

**Files:**
- Create: `packages/fleetops/addon/components/order/details/ifood.js`, `ifood.hbs`, `packages/fleetops/app/components/order/details/ifood.js`
- Modify: `packages/fleetops/addon/components/order/details.hbs`

- [ ] **Step 1: o componente**

Crie `packages/fleetops/addon/components/order/details/ifood.js`:

```js
import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { ACOES_IFOOD, ehPedidoIfood, mostraTroco, numeroIfoodDoPedido, partesDaForma, reais } from '../../../utils/pedido-ifood';

/**
 * Entregas: painel "iFood" no detalhe do pedido (só pedidos do iFood; rota GET int/v1/entregas/pedidos/{id}/ifood, só
 * administradores). Mostra a última ação aceita pelo iFood, a última recusa, a cobrança na porta, o código de entrega,
 * observações, complemento e referência, e o cancelamento pelo iFood. "Liberar sem código" é a saída da central quando o
 * motoboy não consegue o código do cliente (POST .../ifood/liberar-sem-codigo): o app conclui pelo fluxo comum e o
 * iFood conclui sozinho 4 h depois. As ações chegam pelo código (assignDriver…) e são traduzidas aqui.
 */
export default class OrderDetailsIfoodComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;
    @service modalsManager;
    @tracked painel = null;
    @tracked erro = false;

    constructor() {
        super(...arguments);
        if (this.ehIfood) {
            this.carregar.perform();
        }
    }

    get ehIfood() {
        return ehPedidoIfood(this.args.resource);
    }

    get numero() {
        return numeroIfoodDoPedido(this.args.resource);
    }

    get id() {
        return encodeURIComponent(this.args.resource?.public_id ?? this.args.resource?.id ?? '');
    }

    get acaoTexto() {
        const acao = this.painel?.ultima_acao;

        return acao ? this.textoDaAcao(acao) : this.intl.t('fleet-ops.ui.ifood.nenhuma-acao');
    }

    get recusaTexto() {
        const recusa = this.painel?.recusa;
        if (!recusa) {
            return null;
        }

        return this.intl.t('fleet-ops.ui.ifood.recusa', { acao: this.textoDaAcao(recusa.acao), status: recusa.status || '—' });
    }

    get cobrancaTexto() {
        const painel = this.painel;
        if (!painel?.cobrar_centavos) {
            return this.intl.t('fleet-ops.ui.ifood.pago-online');
        }
        const partes = [reais(painel.cobrar_centavos)];
        const formas = partesDaForma(painel.forma_pagamento).map((parte) => (parte.chave ? this.intl.t(`fleet-ops.ui.ifood.forma.${parte.chave}`) : parte.texto));
        if (formas.length) {
            partes.push(formas.join(' + '));
        }
        if (mostraTroco(painel.cobrar_centavos, painel.forma_pagamento, painel.troco_para_centavos)) {
            partes.push(this.intl.t('fleet-ops.ui.ifood.troco', { valor: reais(painel.troco_para_centavos, true) }));
        }

        return partes.join(' · ');
    }

    get codigoTexto() {
        const painel = this.painel;
        if (!painel?.exige_codigo) {
            return this.intl.t('fleet-ops.ui.ifood.codigo-nao-exigido');
        }
        if (painel.conclusao_sem_codigo) {
            return this.intl.t('fleet-ops.ui.ifood.codigo-dispensado');
        }

        return painel.conclusao_liberada_em ? this.intl.t('fleet-ops.ui.ifood.codigo-conferido') : this.intl.t('fleet-ops.ui.ifood.codigo-pendente');
    }

    get podeLiberar() {
        const painel = this.painel;

        return Boolean(painel?.exige_codigo && !painel.conclusao_liberada_em && !painel.cancelado_pelo_ifood_em && !['completed', 'canceled'].includes(this.args.resource?.status));
    }

    textoDaAcao(acao) {
        return this.intl.t(`fleet-ops.ui.ifood.acao.${ACOES_IFOOD.includes(acao) ? acao : 'desconhecida'}`);
    }

    @task *carregar() {
        try {
            this.painel = yield this.fetch.get(`entregas/pedidos/${this.id}/ifood`);
            this.erro = false;
        } catch (error) {
            this.erro = true;
        }
    }

    @action liberarSemCodigo() {
        this.modalsManager.confirm({
            title: this.intl.t('fleet-ops.ui.ifood.liberar-titulo', { numero: this.numero }),
            body: this.intl.t('fleet-ops.ui.ifood.liberar-texto'),
            acceptButtonText: this.intl.t('fleet-ops.ui.ifood.liberar'),
            acceptButtonIcon: 'unlock',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    this.painel = await this.fetch.post(`entregas/pedidos/${this.id}/ifood/liberar-sem-codigo`);
                    this.notifications.success(this.intl.t('fleet-ops.ui.ifood.liberado'));
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

Crie `packages/fleetops/addon/components/order/details/ifood.hbs`:

```hbs
{{#if this.ehIfood}}
    <ContentPanel @title={{t "fleet-ops.ui.ifood.painel-titulo" numero=this.numero}} @isLoading={{or @isLoading this.carregar.isRunning}} @open={{true}} @wrapperClass="bordered-top">
        {{#if this.erro}}
            <p class="text-xs text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.ifood.erro"}}</p>
        {{else if this.painel.ifood}}
            {{#if this.painel.cancelado_pelo_ifood_em}}
                <div class="mb-2 rounded px-2 py-1 text-xs font-semibold bg-red-50 text-red-700 dark:bg-red-900 dark:text-red-100">
                    {{if this.painel.pago_mesmo_cancelado (t "fleet-ops.ui.ifood.cancelado-pago") (t "fleet-ops.ui.ifood.cancelado")}}
                </div>
            {{/if}}
            <div class="field-info-container field-vertical-container dashed-bottom">
                <div class="field-name">{{t "fleet-ops.ui.ifood.ultima-acao"}}</div>
                <div class="field-value">{{this.acaoTexto}}</div>
            </div>
            {{#if this.recusaTexto}}
                <div class="field-info-container field-vertical-container dashed-bottom">
                    <div class="field-name">{{t "fleet-ops.ui.ifood.ultima-recusa"}}</div>
                    <div class="field-value text-red-600 dark:text-red-400">{{this.recusaTexto}}</div>
                </div>
            {{/if}}
            <div class="field-info-container field-vertical-container dashed-bottom">
                <div class="field-name">{{t "fleet-ops.ui.ifood.cobranca"}}</div>
                <div class="field-value">{{this.cobrancaTexto}}</div>
            </div>
            <div class="field-info-container field-vertical-container dashed-bottom">
                <div class="field-name">{{t "fleet-ops.ui.ifood.codigo"}}</div>
                <div class="field-value">{{this.codigoTexto}}</div>
            </div>
            {{#if this.painel.observacoes}}
                <div class="field-info-container field-vertical-container dashed-bottom">
                    <div class="field-name">{{t "fleet-ops.ui.ifood.observacoes"}}</div>
                    <div class="field-value">{{this.painel.observacoes}}</div>
                </div>
            {{/if}}
            {{#if (or this.painel.complemento this.painel.referencia)}}
                <div class="field-info-container field-vertical-container dashed-bottom">
                    <div class="field-name">{{t "fleet-ops.ui.ifood.complemento"}}</div>
                    <div class="field-value">
                        {{this.painel.complemento}}
                        {{#if this.painel.referencia}}
                            {{t "fleet-ops.ui.ifood.referencia" texto=this.painel.referencia}}
                        {{/if}}
                    </div>
                </div>
            {{/if}}
            {{#if this.podeLiberar}}
                <div class="mt-2">
                    <Button @type="warning" @size="xs" @icon="unlock" @text={{t "fleet-ops.ui.ifood.liberar"}} @onClick={{this.liberarSemCodigo}} @permission="fleet-ops update order" />
                </div>
            {{/if}}
        {{/if}}
    </ContentPanel>
{{/if}}
```

Crie `packages/fleetops/app/components/order/details/ifood.js`:

```js
export { default } from '@fleetbase/fleetops-engine/components/order/details/ifood';
```

- [ ] **Step 2: no detalhe**

Em `packages/fleetops/addon/components/order/details.hbs`, troque:

```hbs
        <Order::Details::Detail @resource={{@resource}} @onChange={{@onDetailsChanged}} @isLoading={{@isLoading}} />
```

por:

```hbs
        <Order::Details::Detail @resource={{@resource}} @onChange={{@onDetailsChanged}} @isLoading={{@isLoading}} />
        {{!-- Entregas: painel iFood (só pedidos do iFood; só administradores recebem os dados) --}}
        <Order::Details::Ifood @resource={{@resource}} @isLoading={{@isLoading}} />
```

- [ ] **Step 3: conferir**

Run: `node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal` e o parse do Babel (comando da Task 6, Step 6) em `packages/fleetops/addon/components/order/details/ifood.js`.
Expected: código 0 e `OK`.

- [ ] **Step 4: commit**

```bash
git rev-parse --show-toplevel
git add packages/fleetops/addon/components/order/details/ifood.js packages/fleetops/addon/components/order/details/ifood.hbs packages/fleetops/app/components/order/details/ifood.js packages/fleetops/addon/components/order/details.hbs
git commit -m "iFood etapa 4: painel iFood no detalhe do pedido (etapa no iFood, recusa, cobrança, código, Liberar sem código)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: console, Cancelar escondido e barrado na tela

**Files:**
- Modify: `packages/fleetops/addon/controllers/operations/orders/index/details.js`
- Modify: `packages/fleetops/addon/controllers/operations/orders/index.js`
- Modify: `packages/fleetops/addon/services/order-actions.js`
- Modify: `packages/fleetops/addon/components/order/kanban.js`

- [ ] **Step 1: menu do detalhe**

Em `packages/fleetops/addon/controllers/operations/orders/index/details.js`, troque:

```js
import { buildRoutePointMarkerPresentation, buildRoutePointsFromPayload } from '../../../../utils/route-visualization';
```

por:

```js
import { buildRoutePointMarkerPresentation, buildRoutePointsFromPayload } from '../../../../utils/route-visualization';
import { ehPedidoIfood } from '../../../../utils/pedido-ifood';
```

e troque:

```js
                    {
                        text: this.intl.t('fleet-ops.ui.controller.operations-orders-index-details.cancel-order'),
                        icon: 'ban',
                        class: 'text-danger',
                        disabled: this.model.status === 'canceled',
                        fn: () => this.orderActions.cancel(this.model),
                    },
```

por:

```js
                    // Entregas: pedido do iFood não se cancela pelo console (o cancelamento é no iFood; o servidor recusa)
                    ...(ehPedidoIfood(this.model)
                        ? []
                        : [
                              {
                                  text: this.intl.t('fleet-ops.ui.controller.operations-orders-index-details.cancel-order'),
                                  icon: 'ban',
                                  class: 'text-danger',
                                  disabled: this.model.status === 'canceled',
                                  fn: () => this.orderActions.cancel(this.model),
                              },
                          ]),
```

- [ ] **Step 2: menu da linha da tabela**

Em `packages/fleetops/addon/controllers/operations/orders/index.js`, troque:

```js
import { action } from '@ember/object';
```

por:

```js
import { action } from '@ember/object';
import { ehPedidoIfood } from '../../../utils/pedido-ifood';
```

e troque:

```js
                        label: this.intl.t('common.cancel-resource', { resource: this.intl.t('resource.order') }),
                        icon: 'ban',
                        fn: this.orderActions.cancel,
                        permission: 'fleet-ops cancel order',
                    },
```

por:

```js
                        label: this.intl.t('common.cancel-resource', { resource: this.intl.t('resource.order') }),
                        icon: 'ban',
                        fn: this.orderActions.cancel,
                        permission: 'fleet-ops cancel order',
                        // Entregas: pedido do iFood não se cancela pelo console
                        isVisible: (order) => !ehPedidoIfood(order),
                    },
```

- [ ] **Step 3: as ações (cancelar, em lote, mapa)**

Em `packages/fleetops/addon/services/order-actions.js`, troque:

```js
import { task } from 'ember-concurrency';
```

por:

```js
import { task } from 'ember-concurrency';
import { algumPedidoIfood, ehPedidoIfood } from '../utils/pedido-ifood';
```

troque:

```js
    @action cancel(order, options = {}) {
        this.modalsManager.confirm({
```

por:

```js
    @action cancel(order, options = {}) {
        // Entregas: pedido do iFood se cancela no iFood (o servidor recusa com 400)
        if (ehPedidoIfood(order)) {
            this.notifications.warning(this.intl.t('fleet-ops.ui.ifood.nao-cancela'));
            return;
        }

        this.modalsManager.confirm({
```

e troque:

```js
    @action bulkCancel(selected = []) {
        selected = [...(isArray(selected) ? selected : []), ...this.tableContext.getSelectedRows()];
        if (!selected) return;
```

por:

```js
    @action bulkCancel(selected = []) {
        selected = [...(isArray(selected) ? selected : []), ...this.tableContext.getSelectedRows()];
        if (!selected) return;
        // Entregas: com pedido do iFood na seleção, nada é cancelado (o servidor recusaria o lote inteiro)
        if (algumPedidoIfood(selected)) {
            this.notifications.warning(this.intl.t('fleet-ops.ui.ifood.cancelar-em-lote'));
            return;
        }
```

(O `bulkCancel` também serve o menu de pedidos selecionados do mapa, `map/order-list-overlay.hbs`.)

- [ ] **Step 4: arrastar para "cancelado" no quadro**

Em `packages/fleetops/addon/components/order/kanban.js`, troque:

```js
import isUuid from '@fleetbase/ember-core/utils/is-uuid';
```

por:

```js
import isUuid from '@fleetbase/ember-core/utils/is-uuid';
import { ehPedidoIfood } from '../../utils/pedido-ifood';
```

e troque:

```js
        const prevStatus = order.status;
```

por:

```js
        const prevStatus = order.status;

        // Entregas: pedido do iFood não vai para "cancelado" pelo quadro (o cancelamento é no iFood)
        if (['canceled', 'cancelled'].includes(targetColumnId) && ehPedidoIfood(order)) {
            this.notifications.warning(this.intl.t('fleet-ops.ui.ifood.nao-cancela'));
            this.orders = this.orders.slice();
            return;
        }
```

- [ ] **Step 5: conferir**

Run: o parse do Babel (comando da Task 6, Step 6) nos quatro arquivos e o i18n-check.
Expected: `OK` nos quatro e código 0. (Confirme que `prevStatus` continua usado mais abaixo no `handleCardMove`; se o
lint reclamar de variável sem uso, não é deste plano.)

- [ ] **Step 6: commit**

```bash
git rev-parse --show-toplevel
git add packages/fleetops/addon/controllers/operations/orders/index/details.js packages/fleetops/addon/controllers/operations/orders/index.js packages/fleetops/addon/services/order-actions.js packages/fleetops/addon/components/order/kanban.js
git commit -m "iFood etapa 4: Cancelar escondido nos pedidos do iFood (detalhe, tabela, lote, mapa e quadro)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: console, aviso de ação recusada e o pago mesmo cancelado no relatório

**Files:**
- Create: `packages/fleetops/addon/services/ifood-acao-recusada.js`, `packages/fleetops/app/services/ifood-acao-recusada.js`
- Modify: `packages/fleetops/addon/routes/application.js`
- Modify: `packages/fleetops/addon/templates/management/driver-payouts.hbs`

- [ ] **Step 1: o serviço**

Crie `packages/fleetops/addon/services/ifood-acao-recusada.js`:

```js
import Service, { inject as service } from '@ember/service';
import { debug } from '@ember/debug';
import escutarCanalDaEmpresa from '../utils/escutar-canal-da-empresa';
import { avisoAcaoRecusada } from '../utils/pedido-ifood';
import { tocarSomDeAlerta } from '../utils/som-de-alerta';

const ROTA_DO_PEDIDO = 'console.fleet-ops.operations.orders.index.details';

/**
 * Entregas: aviso à central quando o iFood recusa uma ação de logística de um pedido (evento
 * entregas.ifood_acao_recusada no canal company.<uuid>, App\Events\Entregas\IfoodAcaoRecusada). Toca o som de alerta e
 * mostra uma notificação fixa que abre o pedido no clique; a central confere o pedido no Gestor de Pedidos do iFood (e,
 * se for o código, usa "Liberar sem código" no painel iFood). Iniciado na rota raiz do engine (routes/application.js),
 * como o pedido-sem-motoboy, com consumidor próprio do canal (utils/escutar-canal-da-empresa.js). O som é liberado pelo
 * pedido-sem-motoboy (primeiro clique ou tecla na página).
 */
export default class IfoodAcaoRecusadaService extends Service {
    @service socket;
    @service currentUser;
    @service notifications;
    @service intl;
    @service hostRouter;

    canal = null;

    iniciar() {
        if (this.canal) {
            return;
        }
        this.canal = escutarCanalDaEmpresa({
            socket: this.socket,
            currentUser: this.currentUser,
            aoReceber: (mensagem) => this.#receber(mensagem),
            aoFalhar: (err) => debug('iFood (ação recusada): ' + err?.message),
        });
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this.canal?.parar();
        this.canal = null;
    }

    #receber(mensagem) {
        const aviso = avisoAcaoRecusada(mensagem);
        if (!aviso) {
            return;
        }
        tocarSomDeAlerta();
        const acao = this.intl.t(`fleet-ops.ui.ifood.acao.${aviso.acao}`);
        this.notifications.warning(this.intl.t('fleet-ops.ui.ifood.aviso-recusa', { numero: aviso.numero, acao, status: aviso.status || '—' }), {
            autoClear: false,
            onClick: () => this.hostRouter.transitionTo(ROTA_DO_PEDIDO, aviso.id),
        });
    }
}
```

Crie `packages/fleetops/app/services/ifood-acao-recusada.js`:

```js
export { default } from '@fleetbase/fleetops-engine/services/ifood-acao-recusada';
```

- [ ] **Step 2: iniciar na rota do engine**

Em `packages/fleetops/addon/routes/application.js`, troque `    @service pedidoSemMotoboy;` por:

```js
    @service pedidoSemMotoboy;
    @service ifoodAcaoRecusada;
```

e troque:

```js
        this.pedidoSemMotoboy.iniciar();
```

por:

```js
        this.pedidoSemMotoboy.iniciar();
        // Entregas: aviso de ação de logística recusada pelo iFood (serviço ifood-acao-recusada)
        this.ifoodAcaoRecusada.iniciar();
```

- [ ] **Step 3: "Pagamento e cobrança" marca o pago mesmo cancelado**

Em `packages/fleetops/addon/templates/management/driver-payouts.hbs`, troque:

```hbs
                                    <LinkTo @route="operations.orders.index.details" @model={{entrega.pedido}} class="text-blue-600 dark:text-blue-400 hover:underline">
                                        {{or entrega.id_interno entrega.pedido}}
                                    </LinkTo>
```

por:

```hbs
                                    <LinkTo @route="operations.orders.index.details" @model={{entrega.pedido}} class="text-blue-600 dark:text-blue-400 hover:underline">
                                        {{or entrega.id_interno entrega.pedido}}
                                    </LinkTo>
                                    {{#if entrega.cancelado_pago}}
                                        <Badge @status="warning" @hideStatusDot={{true}}>{{t "fleet-ops.ui.driver-payouts.cancelado-pago"}}</Badge>
                                    {{/if}}
```

- [ ] **Step 4: conferir**

Run: o i18n-check e o parse do Babel (Task 6, Step 6) em `packages/fleetops/addon/services/ifood-acao-recusada.js` e
`packages/fleetops/addon/routes/application.js`.
Expected: código 0 e `OK`.

- [ ] **Step 5: commit**

```bash
git rev-parse --show-toplevel
git add packages/fleetops/addon/services/ifood-acao-recusada.js packages/fleetops/app/services/ifood-acao-recusada.js packages/fleetops/addon/routes/application.js packages/fleetops/addon/templates/management/driver-payouts.hbs
git commit -m "iFood etapa 4: aviso de ação recusada pelo iFood e marca do pago mesmo cancelado no relatório

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 10: portal da loja, selo e Cancelar escondido

**Files:**
- Modify: `packages/customer-portal/addon/components/portal/order/details.js`, `details.hbs`
- Modify: `packages/customer-portal/addon/components/portal/order/list-card.js`, `list-card.hbs`
- Modify: `packages/customer-portal/addon/components/portal/order/panel-header.js`, `panel-header.hbs`
- Modify: `packages/customer-portal/addon/components/portal/order/workspace/table.hbs`
- Modify: `packages/customer-portal/translations/pt-br.yaml`, `en-us.yaml`

- [ ] **Step 1: detalhe (Cancelar e aviso)**

Em `packages/customer-portal/addon/components/portal/order/details.js`, troque:

```js
import { CANCELAVEIS, ENCERRADOS, INTERVALO_MS, VOLTAS_DETALHE, espera, situacao } from '../../../utils/entregas-pedido';
```

por:

```js
import { CANCELAVEIS, ENCERRADOS, INTERVALO_MS, VOLTAS_DETALHE, espera, numeroIfood, situacao } from '../../../utils/entregas-pedido';
```

e troque:

```js
    // Entregas: cancelamento só antes do aceite (depois dele o servidor recusa com 422)
    get canCancel() {
        return Boolean(this.order) && !valueFor(this.order, 'started') && CANCELAVEIS.includes(valueFor(this.order, 'status'));
    }

    // Entregas: pedido aberto que a loja já não cancela; o aviso fica no topo do painel, logo abaixo das ações
    get cancelLocked() {
        return this.isOpen && !this.canCancel;
    }
```

por:

```js
    // Entregas: pedido do iFood (notas "iFood #N" + internal_id N): a loja cancela no Gestor de Pedidos do iFood
    get numeroIfood() {
        return this.order ? numeroIfood(valueFor(this.order, 'notes'), valueFor(this.order, 'internal_id')) : null;
    }

    // Entregas: cancelamento só antes do aceite (depois dele o servidor recusa com 422); pedido do iFood nunca (400)
    get canCancel() {
        return Boolean(this.order) && !this.numeroIfood && !valueFor(this.order, 'started') && CANCELAVEIS.includes(valueFor(this.order, 'status'));
    }

    // Entregas: pedido aberto que a loja já não cancela; o aviso fica no topo do painel, logo abaixo das ações
    get cancelLocked() {
        return this.isOpen && !this.canCancel;
    }
```

Em `packages/customer-portal/addon/components/portal/order/details.hbs`, troque:

```hbs
                <span>{{t "customer-portal.ui.entregas.cancel-locked"}}</span>
```

por:

```hbs
                <span>{{if this.numeroIfood (t "customer-portal.ui.entregas.cancel-ifood") (t "customer-portal.ui.entregas.cancel-locked")}}</span>
```

- [ ] **Step 2: o selo na lista, na tabela e no cabeçalho**

Em `packages/customer-portal/addon/components/portal/order/list-card.js`, troque:

```js
import Component from '@glimmer/component';
```

por:

```js
import Component from '@glimmer/component';
import { numeroIfood } from '../../../utils/entregas-pedido';
```

e troque:

```js
    get trackingNumber() {
        return this.order.tracking_number?.tracking_number ?? this.order.tracking_number ?? this.order.public_id ?? this.order.id;
    }
```

por:

```js
    get trackingNumber() {
        return this.order.tracking_number?.tracking_number ?? this.order.tracking_number ?? this.order.public_id ?? this.order.id;
    }

    // Entregas: selo do pedido que veio do iFood
    get numeroIfood() {
        return numeroIfood(this.order.notes, this.order.internal_id);
    }
```

Em `packages/customer-portal/addon/components/portal/order/list-card.hbs`, troque:

```hbs
            <div class="font-semibold truncate">{{this.trackingNumber}}</div>
```

por:

```hbs
            <div class="font-semibold truncate">{{this.trackingNumber}}</div>
            {{#if this.numeroIfood}}
                <span class="inline-flex self-start items-center whitespace-nowrap rounded px-1.5 py-0.5 text-xs font-semibold text-white" style="background-color: #EA1D2C;">{{t "customer-portal.ui.entregas.ifood-selo" numero=this.numeroIfood}}</span>
            {{/if}}
```

Em `packages/customer-portal/addon/components/portal/order/panel-header.js`, troque:

```js
import dateFnsLocaleOptions from '@fleetbase/ember-core/utils/date-fns-locale';
```

por:

```js
import dateFnsLocaleOptions from '@fleetbase/ember-core/utils/date-fns-locale';
import { numeroIfood } from '../../../utils/entregas-pedido';
```

e troque:

```js
    get qrCode() {
```

por:

```js
    // Entregas: selo do pedido que veio do iFood
    get numeroIfood() {
        return numeroIfood(this.resource.notes, this.resource.internal_id);
    }

    get qrCode() {
```

Em `packages/customer-portal/addon/components/portal/order/panel-header.hbs`, troque:

```hbs
                <div class="flex flex-row flex-wrap gap-1">
                    <Badge @status={{@resource.status}} />
```

por:

```hbs
                <div class="flex flex-row flex-wrap gap-1">
                    <Badge @status={{@resource.status}} />
                    {{#if this.numeroIfood}}
                        <span class="inline-flex items-center whitespace-nowrap rounded px-1.5 py-0.5 text-xs font-semibold text-white" style="background-color: #EA1D2C;">{{t "customer-portal.ui.entregas.ifood-selo" numero=this.numeroIfood}}</span>
                    {{/if}}
```

Em `packages/customer-portal/addon/components/portal/order/workspace/table.hbs`, troque:

```hbs
                            <div class="font-semibold truncate">{{or order.tracking_number.tracking_number order.public_id order.id}}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 truncate">{{n-a (humanize order.type)}}</div>
```

por:

```hbs
                            <div class="font-semibold truncate">{{or order.tracking_number.tracking_number order.public_id order.id}}</div>
                            {{!-- Entregas: selo do pedido do iFood (notas exatamente "iFood #N"; o de teste, com marcas, fica sem selo aqui) --}}
                            {{#if (and order.internal_id (eq order.notes (concat "iFood #" order.internal_id)))}}
                                <span class="inline-flex items-center whitespace-nowrap rounded px-1.5 py-0.5 text-xs font-semibold text-white" style="background-color: #EA1D2C;">{{t "customer-portal.ui.entregas.ifood-selo" numero=order.internal_id}}</span>
                            {{/if}}
                            <div class="text-xs text-gray-500 dark:text-gray-400 truncate">{{n-a (humanize order.type)}}</div>
```

(A tabela não tem classe JS por linha; os helpers `and`, `eq` e `concat` já são usados em outros templates do portal.)

- [ ] **Step 3: traduções**

Em `packages/customer-portal/translations/pt-br.yaml`, troque:

```yaml
      cancel-locked: O motoboy já aceitou. Para cancelar, fale com a central.
```

por:

```yaml
      cancel-locked: O motoboy já aceitou. Para cancelar, fale com a central.
      cancel-ifood: "Pedido do iFood: o cancelamento é feito no Gestor de Pedidos do iFood."
      ifood-selo: "iFood #{numero}"
```

Em `packages/customer-portal/translations/en-us.yaml`, troque:

```yaml
      cancel-locked: The courier already accepted. To cancel, contact the dispatcher.
```

por:

```yaml
      cancel-locked: The courier already accepted. To cancel, contact the dispatcher.
      cancel-ifood: "iFood order: cancel it in the iFood order manager."
      ifood-selo: "iFood #{numero}"
```

- [ ] **Step 4: conferir**

Run (na raiz do repo):

```bash
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal
B=$(ls -d console/node_modules/.pnpm/@babel+parser@7*/node_modules/@babel/parser | head -1)
node -e "const p=require(require('path').resolve(process.argv[1]));for(const f of process.argv.slice(2)){p.parse(require('fs').readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties','classPrivateMethods']});console.log('OK',f)}" "$B" packages/customer-portal/addon/components/portal/order/details.js packages/customer-portal/addon/components/portal/order/list-card.js packages/customer-portal/addon/components/portal/order/panel-header.js
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
```

Expected: código 0, `OK` nos três e `# fail 0`.

- [ ] **Step 5: commit**

```bash
git rev-parse --show-toplevel
git add packages/customer-portal/addon/components/portal/order/details.js packages/customer-portal/addon/components/portal/order/details.hbs packages/customer-portal/addon/components/portal/order/list-card.js packages/customer-portal/addon/components/portal/order/list-card.hbs packages/customer-portal/addon/components/portal/order/panel-header.js packages/customer-portal/addon/components/portal/order/panel-header.hbs packages/customer-portal/addon/components/portal/order/workspace/table.hbs packages/customer-portal/translations/pt-br.yaml packages/customer-portal/translations/en-us.yaml
git commit -m "iFood etapa 4: portal da loja com o selo do iFood e sem o Cancelar no pedido do iFood

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 11: documentação

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: `CLAUDE.md`**

1. Na seção "Integração iFood (etapa 3: ciclo da entrega)" (criada pelo plano 1), acrescente no fim:

```markdown
### Etapa 4: APK, console e portal

- **App** (APK `entregas-motoboy-<n>` do push na `main` do `entregas-navigator`): card de aceitar e detalhes com o selo
  "iFood #4821" e a faixa amarela da cobrança (`PedidoIfood`, dados de `useDadosIfood`, cache de 2 min); detalhes com
  "Ligar para o cliente" (0800 do iFood) e o localizador em letras grandes (toque longo copia), observações, complemento e
  referência; atividade que conclui um pedido iFood passa antes por `concluir-ifood` e, com código exigido, abre o campo do
  código (`CodigoDeEntregaIfood` → `codigo-ifood`); o erro do servidor no `update-activity` aparece em toast. O cartão do
  alarme mostra o selo e a cobrança (`entregas_ifood`, `entregas_cobrar`). Meus ganhos marca "Cancelado pelo iFood: você
  recebe" (`cancelado_pago`).
- **Console:** coluna "iFood" na tabela e selo no quadro, no mapa e no cabeçalho (`pedido-ifood/selo`); painel iFood no
  detalhe (`order/details/ifood`, com "Liberar sem código"); aviso de ação recusada (serviço `ifood-acao-recusada`, som e
  notificação fixa; iniciado na rota raiz do engine); Cancelar escondido (detalhe e linha da tabela) e barrado na tela (lote,
  mapa, quadro); "Pagamento e cobrança" marca o cancelado pelo iFood (pago).
- **Portal:** selo na lista, na tabela (só "iFood #N" sem marcas) e no cabeçalho; Cancelar desabilitado com o aviso "o
  cancelamento é feito no Gestor de Pedidos do iFood".
- **Pedido iFood na tela = notas "iFood #N" + `internal_id` N** (`utils/pedido-ifood.js` no console,
  `numeroIfood` em `customer-portal/addon/utils/entregas-pedido.js`). O servidor confere de verdade.
- **Ordem de implantação:** API do plano 1 → APK novo em todos os celulares → `ENTREGAS_IFOOD_EXIGE_APP_NOVO=1` → só então
  vincular loja real.
- Testes: `scripts/teste-portal/pedido-ifood.test.mjs` e, no app, `scripts/testes/pedido-ifood.teste.ts`.
```

2. Na seção "App do motoboy", no fim do item "**Detalhes do pedido no app**", acrescente: `Pedido do iFood: bloco
   "Pedido do iFood" (selo, cobrança, ligar, localizador) e a conclusão com o código de entrega (ver "Integração iFood
   (etapa 3)", "Etapa 4").`
3. No "Histórico", acrescente o item seguinte ao último: `Integração iFood, etapa 4 (2026-10-06): APK com cobrança, 0800 e
   código de entrega; selo, painel iFood e aviso de recusa no console; portal com o selo e sem o Cancelar.`

- [ ] **Step 2: commit**

```bash
git rev-parse --show-toplevel
git add CLAUDE.md
git commit -m "iFood etapa 4: documentação (APK, console e portal)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 12: verificação em produção com a loja de teste (Edgard + Claude)

Pré-requisito: o plano 1 em produção (Task 15 dele feita). O Claude não tem SSH: os comandos na VPS são do Edgard.

- [ ] **Step 1: APK e console**

1. Push na `main` do `entregas-navigator` (decisão do Edgard) → o GitHub Actions gera `entregas-motoboy-<n>`. Se o build
   falhar no Kotlin ou no bundle, corrija pelo log do Actions antes de seguir.
2. Instale o APK no celular do motoboy de teste.
3. Deploy do console: na VPS, `bash deploy/atualizar.sh console` (~15 min). Abra o console com Ctrl+Shift+R e, para o
   portal, use janela anônima (o console guarda a lista de extensões no localStorage por 1 h).

- [ ] **Step 2: pedido de teste de ponta a ponta**

1. Gere um pedido de teste no Portal do Desenvolvedor do iFood para a loja de teste. Expected no console: o selo
   "iFood #N" na tabela, no quadro e no detalhe; o painel iFood com "Nenhuma ainda"; o menu do detalhe sem "Cancelar".
   No portal (usuário da loja de teste): o selo na lista e no cabeçalho, e o aviso "o cancelamento é feito no Gestor de
   Pedidos do iFood". **A conferir aqui:** se o portal recebe `notes` e `internal_id` (sem selo = não recebe; anote).
2. Atribua ao motoboy de teste. Expected no app: o card/detalhe com o selo; sem cobrança (pedido de teste é pago online);
   "Ligar para o cliente" com o 0800 e o localizador (o pedido de teste traz `customer.phone`?; anote se não vier).
3. Iniciar → perto da loja → "A caminho": o painel iFood mostra "Saiu para entrega".
4. Concluir pelo app: abre o campo do código (o pedido de teste traz o DDCR). Digite um código errado → "Código
   incorreto. Peça o código de novo ao cliente." no campo (confirma o `err?.message` do SDK). **A conferir:** de onde vem o
   código do pedido de teste (página de testes do Portal do Desenvolvedor); se achar, digite o certo → o pedido conclui e
   o painel mostra "Conferido pelo motoboy". Se não achar: no console, painel iFood → "Liberar sem código" → no app,
   "Voltar" e concluir de novo → conclui; o painel mostra "Concluído sem o código".

- [ ] **Step 3: alarme, recusa e cancelamento**

1. Num segundo pedido de teste (ele nasce sem despacho), atribua pelo console ao motoboy de teste com o app dele fechado.
   Expected: o cartão do alarme com o selo "iFood #N" (pedido de teste é pago online: sem a faixa da cobrança).
2. Troque o motoboy depois do "iniciado": se o iFood recusar (409), o console toca o aviso "O iFood recusou "Motoboy
   informado"…" e o painel mostra a recusa.
3. Cancele pelo Gestor de Pedidos do iFood: o app mostra "Cancelado pelo iFood…" nos detalhes e o push chega com o texto
   novo.

- [ ] **Step 4: ligar a trava**

Com o APK novo em **todos** os celulares dos motoboys, o Edgard põe `ENTREGAS_IFOOD_EXIGE_APP_NOVO=1` no `stack.env` e
faz Stacks → entregas → Editor → Update the stack ("Re-pull image" desligado). Teste com um APK antigo (se houver um
celular de teste com ele): concluir um pedido iFood → toast "Atualize o app para concluir pedidos do iFood.".

- [ ] **Step 5: registrar**

Atualize o `CLAUDE.md` (seção da etapa 4) com o que foi conferido (origem do código de teste, `notes` no portal, 0800 no
pedido de teste, mensagens do SDK). Commit só desse arquivo, com o trailer.
