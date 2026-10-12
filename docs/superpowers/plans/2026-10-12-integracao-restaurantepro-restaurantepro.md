# Integração RestaurantePro → Entregas: lado do RestaurantePro (plano 3 de 3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fazer o pedido de delivery do RestaurantePro ir sozinho ao Entregas RestaurantePro quando entra em "Preparando", acompanhar a entrega pelos avisos assinados do Entregas (aceito, a caminho, concluído, cancelado) e cancelar lá quando a loja cancela aqui, com a chave da loja guardada no Vault e um provedor de motoboy por vez (Nenhum, Foody ou Entregas).

**Architecture:** No molde da integração Foody. Banco: tabela `entregas_integrations` (só service_role), colunas `entregas_*` em `orders`, o trigger `orders_entregas_enqueue` enfileirando na `message_outbox` (kinds `entregas_dispatch`, `entregas_cancel`, `entregas_tracking_msg`), exclusividade Foody × Entregas por trigger nas duas tabelas de integração, e o aviso genérico de status calado no "A caminho" de pedido do Entregas (o mesmo mecanismo da Foody). Edges: `entregas-delivery` (painel: status, connect, disconnect, retry), `entregas-tick` (cron: despacho, cancelamento e o WhatsApp com o link de rastreio) e `entregas-webhook` (pública: confere o HMAC do corpo cru, deduplica pelo `X-Entregas-Id` e aplica a mudança com cerca otimista). Toda regra pura fica em `_shared/entregas.ts` (testada no Deno); o painel ganha a seção "Entregas por", o editor da chave e o selo no card do pedido.

**Tech Stack:** Supabase (Postgres, pg_cron, Vault, PostgREST), Edge Functions em Deno, React 19 + Vite + TypeScript (front na Vercel), testes Deno (`deno test`) e smoke em Node (`node --experimental-strip-types`).

**Spec:** repo `Delivery`, `docs/superpowers/specs/2026-10-12-integracao-restaurantepro-design.md` (Parte 3, "Testes" e "Implantação"). O contrato da API do Entregas já está em produção; a referência é o código do repo `Delivery` (`api/app/Http/Controllers/Entregas/IntegracaoController.php` e `api/app/Support/Entregas/Integracao/*`).

---

## Antes de começar

- **Repositório:** todas as tarefas rodam em `C:\Users\Edgardjr\Documents\vibe coding\restaurantepro-main` (no Git Bash: `/c/Users/Edgardjr/Documents/vibe coding/restaurantepro-main`), num ramo novo **`integracao-entregas`** criado a partir da `main` (Task 0).
- **Arquivos soltos que não são deste trabalho:** o checkout tem PNGs (`kling_*.png`) e a pasta `terraco/` sem commit. **Nunca use `git add .` nem `git add -A`**: cada commit lista os arquivos dele.
- **Comandos usados em várias tarefas** (sempre na raiz do repo):
  - teste Deno das funções puras: `deno test supabase/functions/_shared/entregas_test.ts supabase/functions/_shared/googleGeocode_test.ts` (o Deno 2 já está instalado em `~/.deno/bin`; se aparecer `Cannot find name 'Deno'`, rode com `--no-config`);
  - checagem de tipos de uma edge: `deno check supabase/functions/<nome>/index.ts`;
  - smoke: `npm run smoke:entregas` (e `npm run smoke:foody`, `npm run smoke:serviceauth`, que não podem quebrar);
  - tipos do front: `npx tsc --noEmit -p tsconfig.json 2>&1 | grep "error TS" | grep -E "entregas|Entregas|OrderCard|DeliverySettingsContainer|DeliveryProviderSection|FoodySettingsEditor|types/products|hooks/utils|useDataSync"`. **A `main` já tem 40 erros de tipo em outros arquivos** (WhatsAppChatPage, MessagingViews…); o critério é esse filtro não imprimir nada (exit 1 do grep).
- **Particularidades do RestaurantePro (vão no deploy, Task 17):**
  - edges: `npx supabase functions deploy <nome> --project-ref rmzvlqynuwcalqrakklp --no-verify-jwt` (manual; o `--no-verify-jwt` é o padrão da casa: a autenticação é feita dentro da função);
  - migrations: o `supabase db push` está quebrado. A migration é aplicada pelo MCP do Supabase (`apply_migration`) e o `.sql` é commitado em `supabase/migrations/`;
  - crons: pg_cron com o cabeçalho `x-cron-secret` (o segredo fica só no `cron.job`, nunca no `.sql`);
  - front: Vercel, deploy automático no push da `main`.
- **Se o `deno.lock` mudar** ao rodar os testes (não deveria: o `std@0.224.0` já está nele), restaure com `git checkout deno.lock` antes do commit.

## Decisões deste plano (precisam do OK do Edgard)

Cada uma está implementada como descrito; mudar é trocar uma linha ou uma regra pura.

1. **Deduplicação do `X-Entregas-Id`:** tabela própria `entregas_webhook_events` (PK no id do aviso), gravada **por último** (se algo falhar antes, o 500 faz o Entregas repetir e o aviso é reprocessado; aplicar duas vezes é inofensivo pela cerca e pelas `dedupe_key` da fila). Limpeza diária dos registros com mais de 30 dias por pg_cron.
2. **Token desconhecido no webhook = 410** (o Entregas para de tentar aquele aviso). Token vigente de integração **desligada continua aceito**: a troca para a Foody desliga sem rotacionar o token, e os pedidos que já estão no Entregas seguem andando no kanban. O **Desconectar rotaciona** o token (como pede a spec): depois dele, os pedidos já enviados deixam de atualizar o kanban sozinhos (a confirmação avisa).
3. **Desconectar mantém a chave cifrada**, para o cancelamento de um pedido já enviado ainda chegar ao Entregas. Conectar de novo pede a chave (sobrescreve).
4. **Pagamento online não confirmado** (`pix_online`/`card_online` sem `payment_status = 'PAID'`, ou estornado): não envia; grava "Pagamento online não confirmado: o pedido não foi enviado ao Entregas." em `entregas_error`.
5. **Troco:** só vai quando é **maior** que o total (igual ao total não é troco); menor ou ausente = sem troco. Total ≤ 0 (cupom de 100%) = `pago`.
6. **Pedido sem forma de pagamento** (`payment_method` nulo, bundle antigo): não envia; erro "Pedido sem forma de pagamento: o Entregas precisa saber o que cobrar na porta." (o motoboy não pode adivinhar).
7. **Pedido que volta a `preparing`** (vai-e-volta no kanban) não reenvia: o trigger só enfileira sem `entregas_id`, e a `dedupe_key` `entregas:<id>` segura. Vale também para o pedido que nasceu `sem_localizacao`: ele fica no Entregas esperando a central atribuir, e o card mostra "aguardando a central".
8. **Anti-WhatsApp duplo:** o mesmo mecanismo da Foody. O `orders_status_notify_enqueue` deixa de enfileirar o `out_for_delivery` de pedido com `entregas_id`; quem avisa o cliente é a `entregas_tracking_msg` (com o link). O WhatsApp "A caminho" sai **sempre** (sem interruptor próprio, como o padrão ligado da Foody).
9. **Terceiro kind na fila:** além de `entregas_dispatch` e `entregas_cancel`, o `entregas_tracking_msg` (WhatsApp fora do webhook, que tem 5 s de prazo do lado do Entregas).
10. **Situação da entrega no vocabulário nosso** (`orders.entregas_status`): `procurando`, `sem_localizacao`, `aceito`, `a_caminho`, `concluido`, `cancelado`, `cancelado_pago` (com CHECK no banco). Não guardamos o status do Fleetbase.
11. **Coordenadas só com ponto preciso:** o Google só manda latitude/longitude quando achou rua **e** número (centro de bairro ou cidade não vale); senão o Entregas geocodifica, e sem ponto a central atribui. A geocodificação virou `_shared/googleGeocode.ts`; a `foody-tick` **não** foi mexida (segue com a cópia inline dela, para não exigir deploy da Foody).
12. **Cidade obrigatória do Entregas:** do Google (`administrative_area_level_2`), senão a cidade da loja (`companies.address_city`), senão o último trecho do endereço digitado. Sem nenhuma = erro no card.
13. **Telefone:** só dígitos, sem o 55 do Brasil (o motoboy disca local), e só se tiver de 8 a 15 dígitos (fora disso o pedido vai sem telefone em vez de ser recusado).
14. **Erros:** 401, 404, 409, 422 (e outros 4xx) = definitivos (marca `entregas_error` e sai da fila); rede, 408, 425, 429 e 5xx (inclusive o 503 da trava) = temporários (voltam com o backoff da fila: 3, 9, 27 e 81 min; na 5ª tentativa o card mostra o erro). O 401 também grava `last_error` na integração.
15. **"Tentar de novo"** só aparece em pedido **sem** `entregas_id` com erro (falha no envio), para quem pode editar pedidos (`orders.edit`); reabre a linha da fila pela RPC `entregas_reenfileirar_despacho`. Erro de cancelamento mostra "Avise a central do Entregas" e não tem botão.
16. **Exclusividade no banco:** ligar o Entregas desliga a Foody e vice-versa por trigger nas duas tabelas de integração, na mesma transação (vale para a edge, o app nativo e SQL de suporte). O `connect` não precisa de um PATCH separado na Foody, e não existe janela com os dois ligados. Além disso, cada trigger de despacho confere que o outro provedor está desligado.
17. **Cancelamento que corre com o envio:** se o pedido for cancelado no kanban enquanto o POST está no ar, a `entregas-tick` (ou o webhook, se ele chegar antes) enfileira o `entregas_cancel`.
18. **Permissões da edge `entregas-delivery`:** `status`/`connect`/`disconnect` espelham a `foody-delivery` (settings.view na porta; settings.edit ou orders.edit para mudar); `retry` exige `orders.edit` (quem arrasta o card).

## Riscos

- **DNS e proxy do `api.restaurantepro.com.br`:** em 12/10/2026 o `nslookup` resolveu para `rmzvlqynuwcalqrakklp.supabase.co` → `104.18.38.10` e `172.64.149.246` (Cloudflare, IPv4 público; sem AAAA). O Entregas exige IPv4 público e prende a conexão no IP conferido. O Cloudflare na frente do Supabase precisa repassar o corpo cru e os cabeçalhos `X-Entregas-*`: a Task 17 prova isso com um aviso de verdade (assinatura conferida = corpo intacto). Se o `connect` responder "webhook_url recusada", troque o secret `PUBLIC_SUPABASE_URL` da edge pelo host que resolver só para IPv4 público.
- **Janela entre o `connect` e o desligamento da Foody:** fechada pelo trigger de exclusividade (mesma transação do upsert). Resta a ordem do `connect`: o Entregas recebe a URL nova antes de o banco gravar; se o upsert falhar, o webhook dá 410 até um novo Conectar (a tela pede para conectar de novo).
- **Pedidos já em `preparing` no momento de ligar** não vão ao Entregas (o trigger só olha a transição). Para mandar um deles: arrastar para "Pendente" e de volta para "Preparando".
- **Pedido movido para "A caminho" no kanban antes de o despacho sair da fila** (menos de 1 min) recebe o aviso genérico e, depois, o do Entregas com o link: dois WhatsApps (a Foody tem o mesmo risco).
- **App nativo (`apps/mobile`)** continua só com a Foody (fora do escopo). Ligar a Foody por ele desliga o Entregas (trigger).
- **A conta do cliente no delivery público** (`customer-account`) mostra o link de rastreio só da Foody; o do Entregas fica para depois.
- **O painel grava o pedido inteiro** (`upsertOrder`): uma edição com o estado defasado pode voltar o `status` que o webhook avançou (mesmo risco da Foody; as colunas `entregas_*` não vão no upsert).
- **Custo do Google:** cada envio faz uma consulta à Geocoding API com a `GOOGLE_MAPS_API_KEY` das edges (a mesma da Foody).

## Divergências entre a spec e o código real do RestaurantePro

- A spec fala em "reaproveitar a função da Foody" para geocodificar: não existe função, a geocodificação está inline na `foody-tick`. Vira `_shared/googleGeocode.ts` (decisão 11).
- O código curto do pedido **já tem espelho no `_shared`**: `shortFoodyId` (`_shared/foody.ts`). O `entregas.ts` o importa; o smoke confere que dá o mesmo que `utils/orderCode.ts`.
- O endereço do pedido é um texto só (`"rua, número, complemento, cidade"`, montado pelo `CartSidebar`): não há bairro, UF nem CEP separados. Vão o texto inteiro em `entrega.endereco` e o que o Google achar nos outros campos.
- O template `pedido_a_caminho_v1` tem só três variáveis (nome, loja, link): no Cloud, o código de rastreio vai **dentro do link** (`…?order=<código>`); na WAHA (texto livre), vai também escrito.
- A spec lista dois kinds novos; o plano usa três (decisão 9).

## Mapa de arquivos

**Novos:**

| Arquivo | Responsabilidade |
|---|---|
| `supabase/functions/_shared/googleGeocode.ts` | Geocodificação do endereço no Google (parser puro + chamada com `fetch` injetável) |
| `supabase/functions/_shared/googleGeocode_test.ts` | Teste Deno do parser e da chamada |
| `supabase/functions/_shared/entregas.ts` | Regras puras: URLs, chave, telefone, cobrança, corpo do pedido, classificação de erros, mensagens, HMAC, leitura do aviso e a mudança no pedido (cerca) |
| `supabase/functions/_shared/entregas_test.ts` | Teste Deno das regras (com vetores de HMAC calculados fora) |
| `supabase/migrations/2026101201_entregas_integracao.sql` | Tabelas, colunas em `orders`, Vault, cifra, exclusividade |
| `supabase/migrations/2026101202_entregas_fila_e_gatilhos.sql` | CHECK da fila, trigger do Entregas, Foody e aviso genérico recriados, RPC do "Tentar de novo" |
| `supabase/migrations/2026101203_entregas_cron.sql` | Cron do `entregas-tick` e limpeza dos ids de aviso |
| `supabase/functions/entregas-delivery/index.ts` | Edge do painel: status, connect, disconnect, retry |
| `supabase/functions/entregas-tick/index.ts` | Edge do cron: despacho, cancelamento, WhatsApp "A caminho" |
| `supabase/functions/entregas-webhook/index.ts` | Edge pública dos avisos do Entregas |
| `scripts/entregas-smoke.mjs` | `npm run smoke:entregas`: espelhos SQL × servidor × painel |
| `utils/entregasStatus.ts` | Selo do card, provedor ativo, texto da troca, chave (espelho) |
| `hooks/useEntregasIntegration.ts` | Estado da integração + `retryEntregasDispatch` |
| `components/EntregasSettingsEditor.tsx` | Editor da chave (conectar, desconectar) |
| `components/DeliveryProviderSection.tsx` | Seção "Entregas por" (Nenhum, Foody, Entregas) |

**Alterados:** `package.json` (script `smoke:entregas`), `scripts/service-auth-smoke.mjs` (CALLERS), `types/products.ts`, `hooks/useDataSync.ts`, `hooks/utils.ts`, `components/FoodySettingsEditor.tsx` (`onChanged`), `components/DeliverySettingsContainer.tsx`, `components/OrderCard.tsx`, `CLAUDE.md`, `.claude/rules/delivery-cardapio.md`.

---

### Task 0: Ramo

- [ ] **Step 1: Criar o ramo a partir da `main` atualizada**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/restaurantepro-main"
git rev-parse --show-toplevel
git checkout main && git pull --ff-only
git checkout -b integracao-entregas
git status --short
```

Expected: o toplevel é `C:/Users/Edgardjr/Documents/vibe coding/restaurantepro-main`; o `git status` mostra só os soltos (`kling_*.png`, `terraco/`), que **não** entram em commit nenhum.

- [ ] **Step 2: Conferir a base dos testes**

```bash
npm run -s smoke:foody && npm run -s smoke:serviceauth
deno test supabase/functions/_shared/asaasFees_test.ts
```

Expected: `smoke:foody OK`, `service-auth-smoke: OK` e `ok | 4 passed | 0 failed`.

---

### Task 1: Geocodificação compartilhada (`_shared/googleGeocode.ts`)

**Files:**
- Create: `supabase/functions/_shared/googleGeocode.ts`
- Test: `supabase/functions/_shared/googleGeocode_test.ts`

- [ ] **Step 1: Escrever o teste**

`supabase/functions/_shared/googleGeocode_test.ts`:

```ts
import { assertEquals } from 'https://deno.land/std@0.224.0/assert/mod.ts';
import { geoDoGoogle, geocodificarEndereco } from './googleGeocode.ts';

const RESPOSTA_OLINDA = {
  status: 'OK',
  results: [{
    geometry: { location: { lat: -21.1931, lng: -47.8122 } },
    address_components: [
      { long_name: '45', short_name: '45', types: ['street_number'] },
      { long_name: 'Rua Olinda', short_name: 'R. Olinda', types: ['route'] },
      { long_name: 'Jardim Paulista', short_name: 'Jardim Paulista', types: ['political', 'sublocality', 'sublocality_level_1'] },
      { long_name: 'Ribeirão Preto', short_name: 'Ribeirão Preto', types: ['administrative_area_level_2', 'political'] },
      { long_name: 'São Paulo', short_name: 'SP', types: ['administrative_area_level_1', 'political'] },
      { long_name: 'Brasil', short_name: 'BR', types: ['country', 'political'] },
      { long_name: '14090-000', short_name: '14090-000', types: ['postal_code'] },
    ],
  }],
};

Deno.test('geoDoGoogle: endereço completo vira ponto preciso com cidade pelo nível 2', () => {
  assertEquals(geoDoGoogle(RESPOSTA_OLINDA), {
    lat: -21.1931,
    lng: -47.8122,
    rua: 'Rua Olinda',
    numero: '45',
    bairro: 'Jardim Paulista',
    cidade: 'Ribeirão Preto',
    uf: 'SP',
    cep: '14090000',
    preciso: true,
  });
});

Deno.test('geoDoGoogle: sem número não é preciso; sem resultado ou sem ponto = null', () => {
  const semNumero = structuredClone(RESPOSTA_OLINDA);
  semNumero.results[0].address_components = semNumero.results[0].address_components.filter(
    (c: any) => !c.types.includes('street_number'),
  );
  assertEquals(geoDoGoogle(semNumero)?.preciso, false);
  assertEquals(geoDoGoogle({ status: 'ZERO_RESULTS', results: [] }), null);
  assertEquals(geoDoGoogle({ status: 'OK', results: [{ geometry: {} }] }), null);
  assertEquals(geoDoGoogle(null), null);
});

Deno.test('geocodificarEndereco: chama o Google com region=br e devolve o ponto', async () => {
  const chamadas: string[] = [];
  const falso = (async (url: string) => {
    chamadas.push(url);
    return new Response(JSON.stringify(RESPOSTA_OLINDA));
  }) as unknown as typeof fetch;
  const geo = await geocodificarEndereco('Rua Olinda, 45, Ribeirão Preto', 'chave-x', falso);
  assertEquals(geo?.cidade, 'Ribeirão Preto');
  assertEquals(chamadas.length, 1);
  assertEquals(
    chamadas[0],
    'https://maps.googleapis.com/maps/api/geocode/json?address=Rua%20Olinda%2C%2045%2C%20Ribeir%C3%A3o%20Preto&key=chave-x&region=br',
  );
});

Deno.test('geocodificarEndereco: sem chave não chama; erro de rede ou HTTP = null', async () => {
  let chamou = false;
  const naoChame = (async () => { chamou = true; return new Response('{}'); }) as unknown as typeof fetch;
  assertEquals(await geocodificarEndereco('Rua A, 1', '', naoChame), null);
  assertEquals(chamou, false);
  const quebra = (async () => { throw new Error('rede'); }) as unknown as typeof fetch;
  assertEquals(await geocodificarEndereco('Rua A, 1', 'k', quebra), null);
  const erro500 = (async () => new Response('x', { status: 500 })) as unknown as typeof fetch;
  assertEquals(await geocodificarEndereco('Rua A, 1', 'k', erro500), null);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `deno test supabase/functions/_shared/googleGeocode_test.ts`
Expected: FAIL com `Module not found ".../_shared/googleGeocode.ts"`.

- [ ] **Step 3: Implementar**

`supabase/functions/_shared/googleGeocode.ts`:

```ts
// Geocodificação do endereço de entrega no Google (Geocoding API), compartilhada pelas edges. A regra veio da
// deliverDispatch da foody-tick (que segue com a cópia inline dela: mudou a regra aqui, conferir lá). Sem Deno.env
// aqui de propósito: a chave vem do chamador, e o teste (googleGeocode_test.ts) injeta um fetch falso.

export interface GeoDaEntrega {
  lat: number;
  lng: number;
  rua: string;
  numero: string;
  bairro: string;
  cidade: string;
  /** 2 letras maiúsculas, ou vazio. */
  uf: string;
  /** 8 dígitos, ou vazio. */
  cep: string;
  /** Achou rua E número: só assim o ponto vale como o da porta (centro de bairro ou de cidade não vale). */
  preciso: boolean;
}

/** O primeiro resultado do Geocoding, no formato da entrega. null sem resultado ou sem ponto. */
export function geoDoGoogle(resposta: any): GeoDaEntrega | null {
  const r0 = resposta?.status === 'OK' && Array.isArray(resposta?.results) ? resposta.results[0] : null;
  const lat = Number(r0?.geometry?.location?.lat);
  const lng = Number(r0?.geometry?.location?.lng);
  if (!r0 || !Number.isFinite(lat) || !Number.isFinite(lng)) return null;
  const comps: any[] = Array.isArray(r0.address_components) ? r0.address_components : [];
  const comp = (tipo: string, curto = false): string => {
    const c = comps.find((x: any) => Array.isArray(x?.types) && x.types.includes(tipo));
    return c ? String((curto ? c.short_name : c.long_name) || '').trim() : '';
  };
  const rua = comp('route');
  const numero = comp('street_number');
  const uf = comp('administrative_area_level_1', true).toUpperCase();
  const cep = comp('postal_code').replace(/\D/g, '');
  return {
    lat,
    lng,
    rua,
    numero,
    bairro: comp('sublocality_level_1') || comp('sublocality') || comp('neighborhood'),
    // No Brasil o Google não devolve a locality em muitos endereços: o município vem no nível 2.
    cidade: comp('administrative_area_level_2') || comp('locality'),
    uf: /^[A-Z]{2}$/.test(uf) ? uf : '',
    cep: cep.length === 8 ? cep : '',
    preciso: Boolean(rua && numero),
  };
}

/** Best-effort: sem chave, sem endereço, erro de rede ou sem resultado = null (quem chama segue sem o ponto). */
export async function geocodificarEndereco(
  endereco: string | null | undefined,
  chave: string | null | undefined,
  buscar: typeof fetch = fetch,
): Promise<GeoDaEntrega | null> {
  const e = String(endereco || '').trim();
  const k = String(chave || '').trim();
  if (!e || !k) return null;
  try {
    const res = await buscar(
      `https://maps.googleapis.com/maps/api/geocode/json?address=${encodeURIComponent(e)}&key=${encodeURIComponent(k)}&region=br`,
    );
    if (!res.ok) return null;
    return geoDoGoogle(await res.json());
  } catch {
    return null;
  }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `deno test supabase/functions/_shared/googleGeocode_test.ts`
Expected: `ok | 4 passed | 0 failed`.

- [ ] **Step 5: Commit**

```bash
git add supabase/functions/_shared/googleGeocode.ts supabase/functions/_shared/googleGeocode_test.ts
git commit -m "feat(entregas): geocodificação do endereço de entrega compartilhada

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Regras puras, parte 1 (URLs, chave, telefone, cobrança e corpo do pedido)

**Files:**
- Create: `supabase/functions/_shared/entregas.ts`
- Test: `supabase/functions/_shared/entregas_test.ts`

- [ ] **Step 1: Escrever o teste**

`supabase/functions/_shared/entregas_test.ts`:

```ts
import { assertEquals } from 'https://deno.land/std@0.224.0/assert/mod.ts';
import {
  chaveValida,
  cobrancaDoPedido,
  corpoDoPedido,
  telefoneDoCliente,
  tokenDoWebhookValido,
  ultimoTrecho,
  urlDaApi,
  urlDoWebhook,
} from './entregas.ts';

const ORDER_ID = 'ord-3f2a8c1e-9b7d-4e5f-a1b2-c3d4e5f6a7b8';
const GEO = {
  lat: -21.1931, lng: -47.8122, rua: 'Rua Olinda', numero: '45', bairro: 'Jardim Paulista',
  cidade: 'Ribeirão Preto', uf: 'SP', cep: '14090000', preciso: true,
};

// ── Parte 1: URLs, chave, telefone, cobrança e corpo ────────────────────

Deno.test('urlDaApi e urlDoWebhook: padrão e base com barra no fim', () => {
  assertEquals(urlDaApi(undefined, 'pedidos'), 'https://entregas-api.restaurantepro.com.br/v1/entregas/integracao/pedidos');
  assertEquals(urlDaApi('https://outra.exemplo.com/', '/conexao'), 'https://outra.exemplo.com/v1/entregas/integracao/conexao');
  assertEquals(urlDoWebhook(null, 'abc'), 'https://api.restaurantepro.com.br/functions/v1/entregas-webhook?t=abc');
  assertEquals(urlDoWebhook('https://api.restaurantepro.com.br/', 'abc'), 'https://api.restaurantepro.com.br/functions/v1/entregas-webhook?t=abc');
});

Deno.test('chaveValida e tokenDoWebhookValido', () => {
  assertEquals(chaveValida('ent_live_' + 'A1b2C3d4E5'.repeat(4)), true);
  assertEquals(chaveValida('  ent_live_' + 'x'.repeat(40) + ' '), true);
  assertEquals(chaveValida('ent_live_' + 'x'.repeat(39)), false);
  assertEquals(chaveValida('ent_test_' + 'x'.repeat(40)), false);
  assertEquals(chaveValida('ent_live_' + 'x'.repeat(39) + '-'), false);
  assertEquals(chaveValida(null), false);
  assertEquals(tokenDoWebhookValido('0123456789abcdef0123456789abcdef'), true);
  assertEquals(tokenDoWebhookValido('0123456789ABCDEF0123456789abcdef'), false);
  assertEquals(tokenDoWebhookValido(''), false);
});

Deno.test('telefoneDoCliente: só dígitos, sem o 55, de 8 a 15', () => {
  assertEquals(telefoneDoCliente('(16) 99999-0000'), '16999990000');
  assertEquals(telefoneDoCliente('5516999990000'), '16999990000');
  assertEquals(telefoneDoCliente('551632654321'), '1632654321');
  assertEquals(telefoneDoCliente('+1 415 555 2671'), '14155552671');
  assertEquals(telefoneDoCliente('1234567'), null);
  assertEquals(telefoneDoCliente('1234567890123456'), null);
  assertEquals(telefoneDoCliente(''), null);
  assertEquals(telefoneDoCliente(null), null);
});

Deno.test('cobrancaDoPedido: formas, troco e online', () => {
  assertEquals(cobrancaDoPedido({ payment_method: 'money', total: 58.9, payment_change_for: 100 }),
    { ok: true, cobranca: { forma: 'dinheiro', valor: 58.9, troco_para: 100 } });
  // Troco igual ou menor que o total não é troco: vai sem.
  assertEquals(cobrancaDoPedido({ payment_method: 'money', total: 58.9, payment_change_for: 58.9 }),
    { ok: true, cobranca: { forma: 'dinheiro', valor: 58.9 } });
  assertEquals(cobrancaDoPedido({ payment_method: 'money', total: 58.9, payment_change_for: 50 }),
    { ok: true, cobranca: { forma: 'dinheiro', valor: 58.9 } });
  assertEquals(cobrancaDoPedido({ payment_method: 'money', total: '58.90', payment_change_for: null }),
    { ok: true, cobranca: { forma: 'dinheiro', valor: 58.9 } });
  assertEquals(cobrancaDoPedido({ payment_method: 'card', total: 30 }), { ok: true, cobranca: { forma: 'cartao', valor: 30 } });
  assertEquals(cobrancaDoPedido({ payment_method: 'pix', total: 30.005 }), { ok: true, cobranca: { forma: 'pix', valor: 30.01 } });
  assertEquals(cobrancaDoPedido({ payment_method: 'pix_online', payment_status: 'PAID', total: 30 }), { ok: true, cobranca: { forma: 'pago' } });
  assertEquals(cobrancaDoPedido({ payment_method: 'card_online', payment_status: 'PAID', total: 30 }), { ok: true, cobranca: { forma: 'pago' } });
  // Cupom de 100%: nada a cobrar.
  assertEquals(cobrancaDoPedido({ payment_method: 'money', total: 0 }), { ok: true, cobranca: { forma: 'pago' } });
});

Deno.test('cobrancaDoPedido: erros que impedem o envio', () => {
  assertEquals(cobrancaDoPedido({ payment_method: 'pix_online', payment_status: 'PENDING', total: 30 }).ok, false);
  assertEquals(cobrancaDoPedido({ payment_method: 'card_online', payment_status: 'PAID', refund_status: 'REFUNDED', total: 30 }).ok, false);
  assertEquals(cobrancaDoPedido({ payment_method: null, total: 30 }),
    { ok: false, erro: 'Pedido sem forma de pagamento: o Entregas precisa saber o que cobrar na porta.' });
  assertEquals(cobrancaDoPedido({ payment_method: 'money', total: 100000.01 }).ok, false);
});

Deno.test('ultimoTrecho: a cidade no fim do endereço montado pelo checkout', () => {
  assertEquals(ultimoTrecho('Rua Olinda, 45, Ap 12, Ribeirão Preto'), 'Ribeirão Preto');
  assertEquals(ultimoTrecho('Rua Olinda, 45'), '');
  assertEquals(ultimoTrecho('Rua Olinda'), '');
  assertEquals(ultimoTrecho(null), '');
});

Deno.test('corpoDoPedido: pedido completo com o ponto preciso do Google', () => {
  const r = corpoDoPedido({
    id: ORDER_ID,
    customer_name: '  Maria   Souza ',
    customer_phone: '(16) 99999-0000',
    delivery_address: 'Rua Olinda, 45, Ap 12, Ribeirão Preto',
    observation: 'Sem cebola',
    total: 58.9,
    payment_method: 'money',
    payment_change_for: 100,
  }, GEO, 'Sertãozinho');
  assertEquals(r, {
    ok: true,
    corpo: {
      pedido_externo_id: ORDER_ID,
      numero: '5678',
      cliente: { nome: 'Maria Souza', telefone: '16999990000' },
      entrega: {
        endereco: 'Rua Olinda, 45, Ap 12, Ribeirão Preto',
        cidade: 'Ribeirão Preto',
        bairro: 'Jardim Paulista',
        uf: 'SP',
        cep: '14090000',
        latitude: -21.1931,
        longitude: -47.8122,
      },
      cobranca: { forma: 'dinheiro', valor: 58.9, troco_para: 100 },
      observacoes: 'Sem cebola',
    },
  });
});

Deno.test('corpoDoPedido: sem Google usa a cidade da loja; ponto impreciso não vai', () => {
  const base = { id: ORDER_ID, customer_name: '', customer_phone: '123', delivery_address: 'Rua A, 10', total: 20, payment_method: 'pix' };
  assertEquals(corpoDoPedido(base, null, 'Ribeirão Preto'), {
    ok: true,
    corpo: {
      pedido_externo_id: ORDER_ID,
      numero: '5678',
      cliente: { nome: 'Cliente' },
      entrega: { endereco: 'Rua A, 10', cidade: 'Ribeirão Preto' },
      cobranca: { forma: 'pix', valor: 20 },
    },
  });
  const impreciso = corpoDoPedido(base, { ...GEO, numero: '', preciso: false }, null);
  assertEquals(impreciso.ok && (impreciso.corpo.entrega as any).latitude, undefined);
  assertEquals(impreciso.ok && (impreciso.corpo.entrega as any).cidade, 'Ribeirão Preto');
});

Deno.test('corpoDoPedido: erros', () => {
  const base = { id: ORDER_ID, delivery_address: 'Rua A, 10', total: 20, payment_method: 'pix' };
  assertEquals(corpoDoPedido({ ...base, delivery_address: '  ' }, null, 'X'), { ok: false, erro: 'Pedido sem endereço de entrega.' });
  assertEquals(corpoDoPedido(base, null, null),
    { ok: false, erro: 'Não foi possível saber a cidade da entrega: confira o endereço do pedido.' });
  assertEquals(corpoDoPedido({ ...base, id: 'ord com espaço' }, null, 'X').ok, false);
  assertEquals(corpoDoPedido({ ...base, payment_method: 'pix_online', payment_status: 'PENDING' }, null, 'X').ok, false);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `deno test supabase/functions/_shared/entregas_test.ts`
Expected: FAIL com `Module not found ".../_shared/entregas.ts"`.

- [ ] **Step 3: Implementar**

`supabase/functions/_shared/entregas.ts`:

```ts
// Integração Entregas RestaurantePro (despacho de motoboys pela API do Entregas) — lógica PURA compartilhada pelas
// edges entregas-delivery, entregas-tick e entregas-webhook. Sem Deno nem fetch aqui de propósito: o teste Deno
// (entregas_test.ts) e o smoke do Node (npm run smoke:entregas) importam este arquivo.
// Contrato da API: repo Delivery, docs/superpowers/specs/2026-10-12-integracao-restaurantepro-design.md.

import { shortFoodyId } from './foody.ts';
import type { GeoDaEntrega } from './googleGeocode.ts';

export const ENTREGAS_API_URL_PADRAO = 'https://entregas-api.restaurantepro.com.br';
/** Host público das edges (Custom Domain). A env SUPABASE_URL das edges segue no <ref>.supabase.co (CLAUDE.md). */
export const ENTREGAS_WEBHOOK_BASE_PADRAO = 'https://api.restaurantepro.com.br';
/** O `sistema` da conexão: o Entregas mostra "RestaurantePro #4821" nas notas, no app e no console. */
export const ENTREGAS_SISTEMA = 'RestaurantePro';
/** ESPELHO do p_max_attempts padrão do message_outbox_complete (20260823): a 5ª tentativa é a última. */
export const ENTREGAS_MAX_TENTATIVAS = 5;

/** Situação da entrega no vocabulário NOSSO (orders.entregas_status), não o status do Fleetbase.
 * ESPELHO SQL: o CHECK orders_entregas_status_check (2026101201) e a lista de terminais do trigger
 * orders_entregas_enqueue (2026101202). Mudou um, mudar os outros (o smoke:entregas confere). */
export const ENTREGAS_STATUS = [
  'procurando', 'sem_localizacao', 'aceito', 'a_caminho', 'concluido', 'cancelado', 'cancelado_pago',
] as const;
export type EntregasStatus = typeof ENTREGAS_STATUS[number];
export const ENTREGAS_STATUS_TERMINAIS: ReadonlyArray<string> = ['concluido', 'cancelado', 'cancelado_pago'];

const ETAPA: Record<string, number> = {
  procurando: 0, sem_localizacao: 0, aceito: 1, a_caminho: 2, concluido: 3, cancelado: 3, cancelado_pago: 3,
};

/** -1 (nenhuma), 0 procurando, 1 aceito, 2 a caminho, 3 encerrada. A cerca nunca volta de etapa. */
export function etapaDoStatus(status?: string | null): number {
  const s = String(status || '');
  return Object.prototype.hasOwnProperty.call(ETAPA, s) ? ETAPA[s] : -1;
}

export function urlDaApi(base: string | null | undefined, caminho: string): string {
  const b = String(base || '').trim().replace(/\/+$/, '') || ENTREGAS_API_URL_PADRAO;
  return `${b}/v1/entregas/integracao/${String(caminho).replace(/^\/+/, '')}`;
}

export function urlDoWebhook(base: string | null | undefined, token: string): string {
  const b = String(base || '').trim().replace(/\/+$/, '') || ENTREGAS_WEBHOOK_BASE_PADRAO;
  return `${b}/functions/v1/entregas-webhook?t=${encodeURIComponent(token)}`;
}

/** Chave da loja gerada no Entregas: ent_live_ + 40 caracteres base62. ESPELHO: utils/entregasStatus.ts. */
export function chaveValida(chave: string | null | undefined): boolean {
  return /^ent_live_[A-Za-z0-9]{40}$/.test(String(chave || '').trim());
}

/** O `t` da URL do webhook: 32 hex (crypto.randomUUID sem hífens, como o da Foody). */
export function tokenDoWebhookValido(token: string | null | undefined): boolean {
  return /^[a-f0-9]{32}$/.test(String(token || ''));
}

/** Telefone do cliente para o motoboy ligar: só dígitos, sem o 55 do Brasil (o app disca local), de 8 a 15
 * dígitos (a regra do Entregas). Fora disso, null: o pedido vai sem telefone em vez de ser recusado (422). */
export function telefoneDoCliente(phone: string | null | undefined): string | null {
  let d = String(phone || '').replace(/\D/g, '');
  if (d.startsWith('55') && (d.length === 12 || d.length === 13)) d = d.slice(2);
  return d.length >= 8 && d.length <= 15 ? d : null;
}

export interface CobrancaEntregas {
  forma: 'dinheiro' | 'cartao' | 'pix' | 'pago';
  valor?: number;
  troco_para?: number;
}

export interface PagamentoDoPedido {
  payment_method?: string | null;
  payment_status?: string | null;
  refund_status?: string | null;
  total?: number | string | null;
  payment_change_for?: number | string | null;
}

const reais = (v: number) => Math.round(v * 100) / 100;

/** O que o motoboy cobra na porta. `money` → dinheiro (troco só se maior que o total), `card` → cartão, `pix` →
 * pix, online confirmado → pago; total zero (cupom de 100%) → pago. Online não confirmado, sem forma ou valor
 * implausível = erro (o pedido não vai). */
export function cobrancaDoPedido(p: PagamentoDoPedido): { ok: true; cobranca: CobrancaEntregas } | { ok: false; erro: string } {
  const metodo = String(p.payment_method || '');
  if (metodo === 'pix_online' || metodo === 'card_online') {
    if (p.payment_status === 'PAID' && p.refund_status !== 'REFUNDED') return { ok: true, cobranca: { forma: 'pago' } };
    return { ok: false, erro: 'Pagamento online não confirmado: o pedido não foi enviado ao Entregas.' };
  }
  const total = reais(Number(p.total) || 0);
  if (total <= 0) return { ok: true, cobranca: { forma: 'pago' } };
  if (total > 100000) return { ok: false, erro: 'Valor do pedido acima de R$ 100 mil: confira o pedido.' };
  if (metodo === 'money') {
    const troco = reais(Number(p.payment_change_for) || 0);
    return {
      ok: true,
      cobranca: troco > total ? { forma: 'dinheiro', valor: total, troco_para: troco } : { forma: 'dinheiro', valor: total },
    };
  }
  if (metodo === 'card') return { ok: true, cobranca: { forma: 'cartao', valor: total } };
  if (metodo === 'pix') return { ok: true, cobranca: { forma: 'pix', valor: total } };
  return { ok: false, erro: 'Pedido sem forma de pagamento: o Entregas precisa saber o que cobrar na porta.' };
}

export interface PedidoParaEnvio extends PagamentoDoPedido {
  id: string;
  customer_name?: string | null;
  customer_phone?: string | null;
  delivery_address?: string | null;
  observation?: string | null;
}

const umaLinha = (v: unknown) => String(v ?? '').replace(/\s+/g, ' ').trim();

/** Último trecho do endereço ("Rua X, 45, Ap 1, Ribeirão Preto" → "Ribeirão Preto"), só se tiver letra. */
export function ultimoTrecho(endereco: string | null | undefined): string {
  const partes = umaLinha(endereco).split(',').map((p) => p.trim()).filter(Boolean);
  const ultimo = partes.length >= 2 ? partes[partes.length - 1] : '';
  return /\p{L}/u.test(ultimo) ? ultimo : '';
}

/** Corpo do POST pedidos. `pedido_externo_id` = orders.id (o reenvio devolve o mesmo pedido); `numero` = o código
 * curto da comanda (shortFoodyId, espelho de utils/orderCode.ts). Coordenadas só com o ponto PRECISO do Google;
 * sem elas o Entregas geocodifica e, sem ponto, a central atribui ("[SEM LOCALIZAÇÃO]"). */
export function corpoDoPedido(
  pedido: PedidoParaEnvio,
  geo: GeoDaEntrega | null,
  cidadeDaLoja: string | null | undefined,
): { ok: true; corpo: Record<string, unknown> } | { ok: false; erro: string } {
  const id = String(pedido.id || '').trim();
  if (!/^[A-Za-z0-9._:-]{1,100}$/.test(id)) return { ok: false, erro: 'Identificador do pedido fora do formato aceito pelo Entregas.' };
  const endereco = umaLinha(pedido.delivery_address).slice(0, 190);
  if (!endereco) return { ok: false, erro: 'Pedido sem endereço de entrega.' };
  const cidade = (umaLinha(geo?.cidade) || umaLinha(cidadeDaLoja) || ultimoTrecho(endereco)).slice(0, 120);
  if (!cidade) return { ok: false, erro: 'Não foi possível saber a cidade da entrega: confira o endereço do pedido.' };
  const cobranca = cobrancaDoPedido(pedido);
  if (!cobranca.ok) return cobranca;

  const entrega: Record<string, unknown> = { endereco, cidade };
  if (geo?.bairro) entrega.bairro = umaLinha(geo.bairro).slice(0, 120);
  if (geo?.uf && /^[A-Z]{2}$/.test(geo.uf)) entrega.uf = geo.uf;
  if (geo?.cep && /^\d{8}$/.test(geo.cep)) entrega.cep = geo.cep;
  if (geo?.preciso && Number.isFinite(geo.lat) && Number.isFinite(geo.lng)) {
    entrega.latitude = geo.lat;
    entrega.longitude = geo.lng;
  }

  const cliente: Record<string, unknown> = { nome: (umaLinha(pedido.customer_name) || 'Cliente').slice(0, 120) };
  const telefone = telefoneDoCliente(pedido.customer_phone);
  if (telefone) cliente.telefone = telefone;

  const corpo: Record<string, unknown> = {
    pedido_externo_id: id,
    numero: shortFoodyId(id),
    cliente,
    entrega,
    cobranca: cobranca.cobranca,
  };
  const observacoes = String(pedido.observation ?? '').trim().slice(0, 1000);
  if (observacoes) corpo.observacoes = observacoes;
  return { ok: true, corpo };
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `deno test supabase/functions/_shared/entregas_test.ts`
Expected: `ok | 9 passed | 0 failed`.

- [ ] **Step 5: Commit**

```bash
git add supabase/functions/_shared/entregas.ts supabase/functions/_shared/entregas_test.ts
git commit -m "feat(entregas): corpo do pedido e cobrança na porta para o Entregas

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Regras puras, parte 2 (respostas da API, criação e mensagem do "A caminho")

**Files:**
- Modify: `supabase/functions/_shared/entregas.ts` (acrescentar no fim)
- Test: `supabase/functions/_shared/entregas_test.ts`

- [ ] **Step 1: Escrever o teste**

Em `supabase/functions/_shared/entregas_test.ts`, troque o bloco `import { ... } from './entregas.ts';` por:

```ts
import {
  chaveValida,
  classificarResposta,
  cobrancaDoPedido,
  corpoDoPedido,
  errosDaResposta,
  linkSeguro,
  mensagemDeErro,
  patchDaCriacao,
  primeiroNome,
  statusDaCriacao,
  telefoneDoCliente,
  textoDoAcaminho,
  tokenDoWebhookValido,
  ultimoTrecho,
  urlDaApi,
  urlDoWebhook,
} from './entregas.ts';
```

e acrescente no fim do arquivo:

```ts
// ── Parte 2: respostas da API ───────────────────────────────────────────

Deno.test('classificarResposta', () => {
  assertEquals(classificarResposta(200), 'ok');
  assertEquals(classificarResposta(201), 'ok');
  for (const s of [0, 408, 425, 429, 500, 502, 503]) assertEquals(classificarResposta(s), 'temporario');
  for (const s of [400, 401, 403, 404, 405, 409, 410, 422]) assertEquals(classificarResposta(s), 'definitivo');
});

Deno.test('errosDaResposta e mensagemDeErro', () => {
  assertEquals(errosDaResposta({ errors: [' Integração desligada. ', 3, ''] }), ['Integração desligada.']);
  assertEquals(errosDaResposta('<html>'), []);
  assertEquals(mensagemDeErro(409, { errors: ['Integração desligada.'] }), 'O Entregas recusou: Integração desligada.');
  assertEquals(mensagemDeErro(422, { errors: ['cliente.nome é obrigatório.', 'entrega.cidade é obrigatório.'] }),
    'O Entregas recusou o pedido: cliente.nome é obrigatório. entrega.cidade é obrigatório.');
  assertEquals(mensagemDeErro(401, null).startsWith('A chave do Entregas foi recusada'), true);
  assertEquals(mensagemDeErro(503, null), 'O Entregas está fora do ar (503).');
  assertEquals(mensagemDeErro(0, null), 'Sem resposta do Entregas.');
  assertEquals(mensagemDeErro(404, { message: 'Not Found' }), 'O Entregas respondeu 404.');
  assertEquals(mensagemDeErro(422, { errors: ['x'.repeat(600)] }).length, 500);
});

Deno.test('linkSeguro: só https', () => {
  const link = 'https://entregas.restaurantepro.com.br/~/track-order?order=RP123';
  assertEquals(linkSeguro(link), link);
  assertEquals(linkSeguro('http://exemplo.com'), null);
  assertEquals(linkSeguro('javascript:alert(1)'), null);
  assertEquals(linkSeguro('https://a.com/"><script>'), null);
  assertEquals(linkSeguro(null), null);
});

Deno.test('statusDaCriacao e patchDaCriacao', () => {
  const link = 'https://entregas.restaurantepro.com.br/~/track-order?order=RP123';
  const novo = { id: 'order_abc', status: 'created', codigo_rastreio: 'RP123', link_rastreio: link, motoboy: null, sem_localizacao: false, cancelamento: null };
  assertEquals(patchDaCriacao(novo), {
    entregas_id: 'order_abc',
    entregas_status: 'procurando',
    entregas_tracking_code: 'RP123',
    entregas_tracking_url: link,
    entregas_driver_name: null,
    entregas_error: null,
  });
  assertEquals(statusDaCriacao({ ...novo, sem_localizacao: true }), 'sem_localizacao');
  assertEquals(statusDaCriacao({ ...novo, motoboy: { nome: 'João', foto_url: null } }), 'aceito');
  assertEquals(statusDaCriacao({ ...novo, status: 'enroute', motoboy: { nome: 'João' } }), 'a_caminho');
  assertEquals(statusDaCriacao({ ...novo, status: 'completed' }), 'concluido');
  assertEquals(statusDaCriacao({ ...novo, cancelamento: { por: 'central', pago: true } }), 'cancelado_pago');
  assertEquals(statusDaCriacao({ ...novo, cancelamento: { por: 'loja', pago: false } }), 'cancelado');
  assertEquals(patchDaCriacao({ ...novo, id: '' }), null);
});

Deno.test('primeiroNome e textoDoAcaminho', () => {
  assertEquals(primeiroNome('  Maria Souza'), 'Maria');
  assertEquals(primeiroNome(''), 'cliente');
  assertEquals(
    textoDoAcaminho('Maria', 'Terraço', 'https://e.com/x?order=RP1', 'RP1'),
    'Olá Maria! 🛵 Seu pedido da Terraço saiu para entrega.\n\nAcompanhe o entregador em tempo real: https://e.com/x?order=RP1\n\nCódigo de rastreio: RP1',
  );
  assertEquals(textoDoAcaminho('Maria', 'Terraço', 'https://e.com/x', null).endsWith('https://e.com/x'), true);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `deno test supabase/functions/_shared/entregas_test.ts`
Expected: FAIL na checagem de tipos: `Module '"./entregas.ts"' has no exported member 'classificarResposta'` (e os outros nomes novos).

- [ ] **Step 3: Implementar**

Acrescente no fim de `supabase/functions/_shared/entregas.ts`:

```ts
// ── Respostas da API ────────────────────────────────────────────────────

export type ClasseDaResposta = 'ok' | 'definitivo' | 'temporario';

/** 2xx = ok; rede (0), 408, 425, 429 e 5xx (inclusive o 503 da trava) = tentar de novo; o resto (401 chave, 404,
 * 409 integração desligada ou loja sem coleta, 422 validação) = definitivo: marca o erro e sai da fila. */
export function classificarResposta(status: number): ClasseDaResposta {
  if (status >= 200 && status < 300) return 'ok';
  if (status === 0 || status === 408 || status === 425 || status === 429 || status >= 500) return 'temporario';
  return 'definitivo';
}

/** As mensagens de `errors` (o Entregas sempre manda {"errors": [...]}; os 404/405/500 do framework podem não ter). */
export function errosDaResposta(corpo: unknown): string[] {
  const e = (corpo as any)?.errors;
  return Array.isArray(e) ? e.filter((x) => typeof x === 'string' && x.trim()).map((x: string) => x.trim()) : [];
}

/** Texto do card (orders.entregas_error), até 500 caracteres. */
export function mensagemDeErro(status: number, corpo: unknown): string {
  const erros = errosDaResposta(corpo);
  let texto: string;
  if (status === 0) texto = 'Sem resposta do Entregas.';
  else if (status === 401) texto = 'A chave do Entregas foi recusada (revogada ou inválida). Peça uma nova à central do Entregas e conecte de novo em Configurações de Delivery.';
  else if (status === 422) texto = `O Entregas recusou o pedido: ${erros.join(' ') || 'dados inválidos.'}`;
  else if (status === 409) texto = `O Entregas recusou: ${erros[0] || 'conflito.'}`;
  else if (status === 429) texto = 'Limite de pedidos por minuto do Entregas atingido.';
  else if (status >= 500) texto = `O Entregas está fora do ar (${status}).`;
  else texto = `O Entregas respondeu ${status}${erros[0] ? `: ${erros[0]}` : '.'}`;
  return texto.slice(0, 500);
}

const textoCurto = (v: unknown, max: number): string => (typeof v === 'string' ? v.trim().slice(0, max) : '');

/** Só https: o link vai para um <a href> no card e para o WhatsApp do cliente. */
export function linkSeguro(v: unknown): string | null {
  const s = typeof v === 'string' ? v.trim() : '';
  return s.length <= 500 && /^https:\/\/[^\s"'<>]+$/i.test(s) ? s : null;
}

/** Situação inicial pela resposta do POST pedidos (o reenvio idempotente devolve o estado ATUAL do pedido). */
export function statusDaCriacao(corpo: any): EntregasStatus {
  if (corpo?.cancelamento) return corpo.cancelamento.pago === true ? 'cancelado_pago' : 'cancelado';
  if (corpo?.status === 'completed') return 'concluido';
  if (corpo?.status === 'enroute') return 'a_caminho';
  if (textoCurto(corpo?.motoboy?.nome, 120)) return 'aceito';
  if (corpo?.sem_localizacao === true) return 'sem_localizacao';
  return 'procurando';
}

/** O que a entregas-tick grava no pedido depois do POST aceito. null sem o id do Entregas. */
export function patchDaCriacao(corpo: any): Record<string, unknown> | null {
  const id = textoCurto(corpo?.id, 100);
  if (!id) return null;
  return {
    entregas_id: id,
    entregas_status: statusDaCriacao(corpo),
    entregas_tracking_code: textoCurto(corpo?.codigo_rastreio, 100) || null,
    entregas_tracking_url: linkSeguro(corpo?.link_rastreio),
    entregas_driver_name: textoCurto(corpo?.motoboy?.nome, 120) || null,
    entregas_error: null,
  };
}

export function primeiroNome(nome: string | null | undefined): string {
  return umaLinha(nome).split(' ')[0] || 'cliente';
}

/** Texto do WhatsApp "A caminho" pela WAHA. No Cloud vai o template pedido_a_caminho_v1 (nome, loja, link): o
 * código fica dentro do link (…?order=<código>), porque o template tem só três variáveis. */
export function textoDoAcaminho(nome: string, loja: string, link: string, codigo?: string | null): string {
  const base = `Olá ${nome}! 🛵 Seu pedido da ${loja} saiu para entrega.\n\nAcompanhe o entregador em tempo real: ${link}`;
  const c = umaLinha(codigo);
  return c ? `${base}\n\nCódigo de rastreio: ${c}` : base;
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `deno test supabase/functions/_shared/entregas_test.ts`
Expected: `ok | 14 passed | 0 failed`.

- [ ] **Step 5: Commit**

```bash
git add supabase/functions/_shared/entregas.ts supabase/functions/_shared/entregas_test.ts
git commit -m "feat(entregas): classificação das respostas e mensagens do Entregas

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Regras puras, parte 3 (assinatura, leitura do aviso e a cerca)

**Files:**
- Modify: `supabase/functions/_shared/entregas.ts` (acrescentar no fim)
- Test: `supabase/functions/_shared/entregas_test.ts`

Os dois vetores de HMAC do teste foram calculados **fora** desta implementação, com `node:crypto` e conferidos com `openssl dgst -sha256 -hmac whsec_teste_123`. Para recalcular:

```bash
node -e "const c=require('crypto');console.log(c.createHmac('sha256','whsec_teste_123').update('{\"evento\":\"pedido.aceito\",\"id_entrega\":\"0123456789abcdef0123456789abcdef\",\"pedido_externo_id\":\"ord-3f2a8c1e-9b7d-4e5f-a1b2-c3d4e5f6a7b8\"}').digest('hex'))"
```

Expected: `d6131e7fc8bc630eda2fbbed8e2c4c318851247e718e2a5b0b53b967df85051b`.

- [ ] **Step 1: Escrever o teste**

Em `supabase/functions/_shared/entregas_test.ts`, troque o bloco `import { ... } from './entregas.ts';` por:

```ts
import {
  ENTREGAS_STATUS,
  ENTREGAS_STATUS_TERMINAIS,
  assinaturaConfere,
  avisoDoCorpo,
  chaveValida,
  classificarResposta,
  cobrancaDoPedido,
  corpoDoPedido,
  errosDaResposta,
  etapaDoStatus,
  hmacHex,
  linkSeguro,
  mensagemDeErro,
  mudancaDoAviso,
  patchDaCriacao,
  primeiroNome,
  statusDaCriacao,
  telefoneDoCliente,
  textoDoAcaminho,
  tokenDoWebhookValido,
  ultimoTrecho,
  urlDaApi,
  urlDoWebhook,
} from './entregas.ts';
import type { AvisoDoEntregas } from './entregas.ts';
```

e acrescente no fim do arquivo:

```ts
// ── Parte 3: assinatura, aviso e a cerca ────────────────────────────────

// Vetores calculados fora (node:crypto e openssl dgst -sha256 -hmac), não por esta implementação.
const SEGREDO = 'whsec_teste_123';
const CORPO_1 = '{"evento":"pedido.aceito","id_entrega":"0123456789abcdef0123456789abcdef","pedido_externo_id":"ord-3f2a8c1e-9b7d-4e5f-a1b2-c3d4e5f6a7b8"}';
const HMAC_1 = 'd6131e7fc8bc630eda2fbbed8e2c4c318851247e718e2a5b0b53b967df85051b';
const CORPO_UTF8 = '{"motoboy":{"nome":"João Ônibus"}}';
const HMAC_UTF8 = '1d83b4f18d8dac757c219f17b9a23831b94c47007f28ec29c15c2065a5cd5e23';

Deno.test('hmacHex bate com os vetores de fora (inclusive UTF-8)', async () => {
  assertEquals(await hmacHex(CORPO_1, SEGREDO), HMAC_1);
  assertEquals(await hmacHex(CORPO_UTF8, SEGREDO), HMAC_UTF8);
});

Deno.test('assinaturaConfere', async () => {
  assertEquals(await assinaturaConfere(CORPO_1, `sha256=${HMAC_1}`, SEGREDO), true);
  assertEquals(await assinaturaConfere(CORPO_1, `sha256=${HMAC_1.toUpperCase()}`, SEGREDO), true);
  assertEquals(await assinaturaConfere(CORPO_1 + ' ', `sha256=${HMAC_1}`, SEGREDO), false);
  assertEquals(await assinaturaConfere(CORPO_1, HMAC_1, SEGREDO), false);
  assertEquals(await assinaturaConfere(CORPO_1, `sha256=${HMAC_1}`, 'outro'), false);
  assertEquals(await assinaturaConfere(CORPO_1, `sha256=${'0'.repeat(64)}`, SEGREDO), false);
  assertEquals(await assinaturaConfere(CORPO_1, null, SEGREDO), false);
  assertEquals(await assinaturaConfere(CORPO_1, `sha256=${HMAC_1}`, ''), false);
});

const ID_AVISO = '0123456789abcdef0123456789abcdef';
const LINK = 'https://entregas.restaurantepro.com.br/~/track-order?order=RP123';

Deno.test('avisoDoCorpo: lê o aviso e prefere os cabeçalhos', () => {
  const corpo = {
    evento: 'pedido.aceito', id_entrega: ID_AVISO, pedido_externo_id: ORDER_ID, id: 'order_abc', status: 'started',
    motoboy: { nome: 'João', foto_url: null }, codigo_rastreio: 'RP123', link_rastreio: LINK, cancelamento: null,
    ocorrido_em: '2026-10-12T15:00:00-03:00',
  };
  assertEquals(avisoDoCorpo(corpo, 'pedido.aceito', ID_AVISO.toUpperCase()), {
    ok: true,
    aviso: {
      evento: 'pedido.aceito', idEntrega: ID_AVISO, pedidoExternoId: ORDER_ID, id: 'order_abc', motoboyNome: 'João',
      codigoRastreio: 'RP123', linkRastreio: LINK, cancelamento: null,
    },
  });
  const cancelado = avisoDoCorpo({ ...corpo, evento: 'pedido.cancelado', cancelamento: { por: 'loja', pago: true } }, null, null);
  assertEquals(cancelado.ok && cancelado.aviso.cancelamento, { por: 'loja', pago: true });
  assertEquals(avisoDoCorpo({ ...corpo, link_rastreio: 'http://x.com' }, null, null).ok && true, true);
  assertEquals((avisoDoCorpo({ ...corpo, link_rastreio: 'http://x.com' }, null, null) as any).aviso.linkRastreio, null);
  assertEquals(avisoDoCorpo({ ...corpo, evento: 'pedido.sumiu' }, null, null).ok, false);
  assertEquals(avisoDoCorpo({ ...corpo, id_entrega: 'curto' }, null, null).ok, false);
  assertEquals(avisoDoCorpo({ ...corpo, pedido_externo_id: '' }, null, null).ok, false);
  assertEquals(avisoDoCorpo([], null, null).ok, false);
});

const aviso = (evento: AvisoDoEntregas['evento'], extra: Partial<AvisoDoEntregas> = {}): AvisoDoEntregas => ({
  evento, idEntrega: ID_AVISO, pedidoExternoId: ORDER_ID, id: 'order_abc', motoboyNome: null,
  codigoRastreio: 'RP123', linkRastreio: LINK, cancelamento: null, ...extra,
});
const noBanco = { entregas_id: 'order_abc', entregas_tracking_code: 'RP123', entregas_tracking_url: LINK, entregas_driver_name: null };

Deno.test('mudancaDoAviso: o ciclo feliz', () => {
  assertEquals(mudancaDoAviso(aviso('pedido.aceito', { motoboyNome: 'João' }), { ...noBanco, status: 'preparing', entregas_status: 'procurando' }),
    { patch: { entregas_driver_name: 'João', entregas_status: 'aceito' }, avisarCliente: false, cancelarNoEntregas: false });
  assertEquals(mudancaDoAviso(aviso('pedido.a_caminho', { motoboyNome: 'João' }), { ...noBanco, status: 'preparing', entregas_status: 'aceito', entregas_driver_name: 'João' }),
    { patch: { entregas_status: 'a_caminho', status: 'out_for_delivery' }, avisarCliente: true, cancelarNoEntregas: false });
  assertEquals(mudancaDoAviso(aviso('pedido.concluido'), { ...noBanco, status: 'out_for_delivery', entregas_status: 'a_caminho' }),
    { patch: { entregas_status: 'concluido', status: 'completed' }, avisarCliente: false, cancelarNoEntregas: false });
});

Deno.test('mudancaDoAviso: aviso perdido sai junto com o seguinte', () => {
  assertEquals(mudancaDoAviso(aviso('pedido.a_caminho', { motoboyNome: 'João' }), { ...noBanco, status: 'preparing', entregas_status: 'procurando' }),
    { patch: { entregas_driver_name: 'João', entregas_status: 'a_caminho', status: 'out_for_delivery' }, avisarCliente: true, cancelarNoEntregas: false });
  assertEquals(mudancaDoAviso(aviso('pedido.concluido'), { ...noBanco, status: 'preparing', entregas_status: 'procurando' }),
    { patch: { entregas_status: 'concluido', status: 'completed' }, avisarCliente: false, cancelarNoEntregas: false });
});

Deno.test('mudancaDoAviso: cancelamento pago e não pago', () => {
  assertEquals(mudancaDoAviso(aviso('pedido.cancelado', { cancelamento: { por: 'loja', pago: true } }), { ...noBanco, status: 'out_for_delivery', entregas_status: 'a_caminho' }),
    { patch: { entregas_status: 'cancelado_pago', status: 'cancelled' }, avisarCliente: false, cancelarNoEntregas: false });
  assertEquals(mudancaDoAviso(aviso('pedido.cancelado', { cancelamento: { por: 'central', pago: false } }), { ...noBanco, status: 'preparing', entregas_status: 'procurando' }),
    { patch: { entregas_status: 'cancelado', status: 'cancelled' }, avisarCliente: false, cancelarNoEntregas: false });
});

Deno.test('mudancaDoAviso: a cerca nunca volta', () => {
  // Concluído no painel: só a situação do Entregas anda; sem WhatsApp.
  assertEquals(mudancaDoAviso(aviso('pedido.a_caminho'), { ...noBanco, status: 'completed', entregas_status: 'aceito' }),
    { patch: { entregas_status: 'a_caminho' }, avisarCliente: false, cancelarNoEntregas: false });
  // Troca de motoboy depois do "A caminho": só o nome muda.
  assertEquals(mudancaDoAviso(aviso('pedido.aceito', { motoboyNome: 'Pedro' }), { ...noBanco, status: 'out_for_delivery', entregas_status: 'a_caminho', entregas_driver_name: 'João' }),
    { patch: { entregas_driver_name: 'Pedro' }, avisarCliente: false, cancelarNoEntregas: false });
  // Entrega já encerrada no Entregas: nada muda, nem o kanban.
  assertEquals(mudancaDoAviso(aviso('pedido.cancelado', { cancelamento: { por: 'central', pago: true } }), { ...noBanco, status: 'completed', entregas_status: 'concluido' }),
    { patch: {}, avisarCliente: false, cancelarNoEntregas: false });
  assertEquals(mudancaDoAviso(aviso('pedido.a_caminho'), { ...noBanco, status: 'preparing', entregas_status: 'cancelado' }),
    { patch: {}, avisarCliente: false, cancelarNoEntregas: false });
  // O mesmo aviso de novo: nada.
  assertEquals(mudancaDoAviso(aviso('pedido.a_caminho'), { ...noBanco, status: 'out_for_delivery', entregas_status: 'a_caminho' }),
    { patch: {}, avisarCliente: false, cancelarNoEntregas: false });
});

Deno.test('mudancaDoAviso: pedido cancelado no painel antes de a tick gravar o id', () => {
  assertEquals(mudancaDoAviso(aviso('pedido.aceito', { motoboyNome: 'João' }), { status: 'cancelled', entregas_id: null, entregas_status: null }),
    {
      patch: { entregas_id: 'order_abc', entregas_tracking_code: 'RP123', entregas_tracking_url: LINK, entregas_driver_name: 'João', entregas_status: 'aceito' },
      avisarCliente: false,
      cancelarNoEntregas: true,
    });
});

Deno.test('etapaDoStatus e as listas', () => {
  assertEquals(etapaDoStatus('constructor'), -1);
  assertEquals(etapaDoStatus(null), -1);
  assertEquals(ENTREGAS_STATUS.filter((s) => etapaDoStatus(s) === 3), [...ENTREGAS_STATUS_TERMINAIS]);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `deno test supabase/functions/_shared/entregas_test.ts`
Expected: FAIL na checagem de tipos: `has no exported member 'assinaturaConfere'` (e os outros nomes novos).

- [ ] **Step 3: Implementar**

Acrescente no fim de `supabase/functions/_shared/entregas.ts`:

```ts
// ── Webhook: assinatura, leitura do aviso e a mudança no pedido ─────────

export async function hmacHex(texto: string, segredo: string): Promise<string> {
  const enc = new TextEncoder();
  const chave = await crypto.subtle.importKey('raw', enc.encode(segredo), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const assinatura = new Uint8Array(await crypto.subtle.sign('HMAC', chave, enc.encode(texto)));
  return Array.from(assinatura, (b) => b.toString(16).padStart(2, '0')).join('');
}

function iguaisEmTempoConstante(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let r = 0;
  for (let i = 0; i < a.length; i++) r |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return r === 0;
}

/** X-Entregas-Assinatura = "sha256=" + hex do HMAC-SHA256 do corpo CRU com o webhook_segredo. */
export async function assinaturaConfere(
  corpoCru: string,
  cabecalho: string | null | undefined,
  segredo: string | null | undefined,
): Promise<boolean> {
  const m = /^sha256=([0-9a-fA-F]{64})$/.exec(String(cabecalho || '').trim());
  if (!m || !segredo) return false;
  return iguaisEmTempoConstante(await hmacHex(corpoCru, segredo), m[1].toLowerCase());
}

export const ENTREGAS_EVENTOS = ['pedido.aceito', 'pedido.a_caminho', 'pedido.concluido', 'pedido.cancelado'] as const;
export type EventoDoEntregas = typeof ENTREGAS_EVENTOS[number];

export interface AvisoDoEntregas {
  evento: EventoDoEntregas;
  /** X-Entregas-Id: estável entre as tentativas do mesmo aviso. */
  idEntrega: string;
  pedidoExternoId: string;
  /** public_id do Order no Entregas (order_…). */
  id: string | null;
  motoboyNome: string | null;
  codigoRastreio: string | null;
  linkRastreio: string | null;
  cancelamento: { por: string; pago: boolean } | null;
}

export function avisoDoCorpo(
  corpo: any,
  eventoDoCabecalho?: string | null,
  idDoCabecalho?: string | null,
): { ok: true; aviso: AvisoDoEntregas } | { ok: false; erro: string } {
  if (!corpo || typeof corpo !== 'object' || Array.isArray(corpo)) return { ok: false, erro: 'corpo não é um objeto JSON' };
  const evento = String(eventoDoCabecalho || corpo.evento || '').trim();
  if (!(ENTREGAS_EVENTOS as ReadonlyArray<string>).includes(evento)) return { ok: false, erro: `evento desconhecido: ${evento.slice(0, 40)}` };
  const idEntrega = String(idDoCabecalho || corpo.id_entrega || '').trim().toLowerCase();
  if (!/^[a-f0-9]{32}$/.test(idEntrega)) return { ok: false, erro: 'X-Entregas-Id ausente ou fora do formato' };
  const pedidoExternoId = textoCurto(corpo.pedido_externo_id, 100);
  if (!pedidoExternoId) return { ok: false, erro: 'pedido_externo_id ausente' };
  const c = corpo.cancelamento;
  return {
    ok: true,
    aviso: {
      evento: evento as EventoDoEntregas,
      idEntrega,
      pedidoExternoId,
      id: textoCurto(corpo.id, 100) || null,
      motoboyNome: textoCurto(corpo.motoboy?.nome, 120) || null,
      codigoRastreio: textoCurto(corpo.codigo_rastreio, 100) || null,
      linkRastreio: linkSeguro(corpo.link_rastreio),
      cancelamento: c && typeof c === 'object' ? { por: textoCurto(c.por, 20) || 'central', pago: c.pago === true } : null,
    },
  };
}

export interface PedidoNoBanco {
  status: string;
  entregas_id?: string | null;
  entregas_status?: string | null;
  entregas_driver_name?: string | null;
  entregas_tracking_code?: string | null;
  entregas_tracking_url?: string | null;
}

export interface MudancaDoAviso {
  /** Colunas de orders a gravar (vazio = nada muda). */
  patch: Record<string, unknown>;
  /** Primeiro "A caminho": enfileirar o WhatsApp com o link de rastreio. */
  avisarCliente: boolean;
  /** O pedido já está cancelado no painel e a entrega segue viva no Entregas: enfileirar o cancelamento. */
  cancelarNoEntregas: boolean;
}

/** A mudança que um aviso faz no pedido, com a cerca: a situação do Entregas nunca volta de etapa, e o status do
 * kanban só avança (pedido concluído ou cancelado no painel não muda; entrega já encerrada no Entregas não mexe
 * no kanban). O mesmo aviso aplicado duas vezes não muda nada na segunda. */
export function mudancaDoAviso(aviso: AvisoDoEntregas, pedido: PedidoNoBanco): MudancaDoAviso {
  const patch: Record<string, unknown> = {};
  const status = String(pedido.status || '');
  const encerradoNoPainel = status === 'completed' || status === 'cancelled';
  const etapa = etapaDoStatus(pedido.entregas_status);
  const encerradoNoEntregas = etapa === 3;

  if (aviso.id && !pedido.entregas_id) patch.entregas_id = aviso.id;
  if (aviso.codigoRastreio && aviso.codigoRastreio !== pedido.entregas_tracking_code) patch.entregas_tracking_code = aviso.codigoRastreio;
  if (aviso.linkRastreio && aviso.linkRastreio !== pedido.entregas_tracking_url) patch.entregas_tracking_url = aviso.linkRastreio;
  if (aviso.motoboyNome && aviso.motoboyNome !== pedido.entregas_driver_name) patch.entregas_driver_name = aviso.motoboyNome;

  let novo: string | null = null;
  let avisarCliente = false;
  const podeMexerNoKanban = !encerradoNoPainel && !encerradoNoEntregas;
  if (aviso.evento === 'pedido.aceito') {
    if (etapa < 1) novo = 'aceito';
  } else if (aviso.evento === 'pedido.a_caminho') {
    if (etapa < 2) {
      novo = 'a_caminho';
      avisarCliente = !encerradoNoPainel;
    }
    if (podeMexerNoKanban && (status === 'pending' || status === 'preparing')) patch.status = 'out_for_delivery';
  } else if (aviso.evento === 'pedido.concluido') {
    if (!encerradoNoEntregas) novo = 'concluido';
    if (podeMexerNoKanban) patch.status = 'completed';
  } else if (aviso.evento === 'pedido.cancelado') {
    if (!encerradoNoEntregas) novo = aviso.cancelamento?.pago ? 'cancelado_pago' : 'cancelado';
    if (podeMexerNoKanban) patch.status = 'cancelled';
  }
  if (novo && novo !== pedido.entregas_status) patch.entregas_status = novo;

  const situacaoDepois = String(patch.entregas_status ?? pedido.entregas_status ?? '');
  const cancelarNoEntregas = status === 'cancelled' && etapaDoStatus(situacaoDepois) !== 3;
  return { patch, avisarCliente, cancelarNoEntregas };
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `deno test supabase/functions/_shared/entregas_test.ts supabase/functions/_shared/googleGeocode_test.ts`
Expected: `ok | 27 passed | 0 failed`.

- [ ] **Step 5: Commit**

```bash
git add supabase/functions/_shared/entregas.ts supabase/functions/_shared/entregas_test.ts
git commit -m "feat(entregas): HMAC dos avisos e a cerca do status do pedido

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Migration da fundação (tabelas, colunas, Vault e exclusividade) + `smoke:entregas`

**Files:**
- Create: `supabase/migrations/2026101201_entregas_integracao.sql`
- Create: `scripts/entregas-smoke.mjs`
- Modify: `package.json` (script `smoke:entregas`)

A migration **não é aplicada nesta task** (só na Task 17). Aqui ela é escrita e o smoke confere o espelho da lista de situações.

- [ ] **Step 1: Escrever o smoke**

`scripts/entregas-smoke.mjs`:

```js
// Integração Entregas RestaurantePro: espelhos entre o SQL, o servidor (_shared/entregas.ts) e o painel
// (utils/entregasStatus.ts). Os testes de comportamento do servidor ficam no Deno:
//   deno test supabase/functions/_shared/entregas_test.ts supabase/functions/_shared/googleGeocode_test.ts
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import {
  ENTREGAS_STATUS,
  ENTREGAS_STATUS_TERMINAIS,
  chaveValida,
  corpoDoPedido,
} from '../supabase/functions/_shared/entregas.ts';

const ROOT = fileURLToPath(new URL('..', import.meta.url));
const ler = (rel) => readFileSync(`${ROOT}${rel}`, 'utf8');
const aspas = (trecho) => [...trecho.matchAll(/'([a-z_]+)'/g)].map((m) => m[1]);
const trecho = (sql, re, nome) => {
  const m = re.exec(sql);
  assert.ok(m, `trecho não encontrado: ${nome}`);
  return m[1];
};

// 1. CHECK de orders.entregas_status = ENTREGAS_STATUS (servidor).
const migA = ler('supabase/migrations/2026101201_entregas_integracao.sql');
assert.deepEqual(
  aspas(trecho(migA, /add constraint orders_entregas_status_check\s+check \(([\s\S]*?)\);/, 'CHECK entregas_status')),
  [...ENTREGAS_STATUS],
);

console.log('smoke:entregas OK');
```

Em `package.json`, logo depois da linha `"smoke:foody": "node --experimental-strip-types scripts/foody-smoke.mjs",`, acrescente:

```json
    "smoke:entregas": "node --experimental-strip-types scripts/entregas-smoke.mjs",
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `npm run -s smoke:entregas`
Expected: FAIL com `ENOENT: no such file or directory` apontando para `supabase/migrations/2026101201_entregas_integracao.sql`.

- [ ] **Step 3: Escrever a migration**

`supabase/migrations/2026101201_entregas_integracao.sql`:

```sql
-- Integração Entregas RestaurantePro (despacho de motoboys pela API do Entregas) — fundação.
-- Spec: repo Delivery, docs/superpowers/specs/2026-10-12-integracao-restaurantepro-design.md (Parte 3).
-- Plano: repo Delivery, docs/superpowers/plans/2026-10-12-integracao-restaurantepro-restaurantepro.md.
--
-- 1) entregas_integrations: service_role-only (padrão foody_integrations). A chave ent_live_ abre a loja no
--    Entregas e o segredo do webhook assina os avisos: nenhum dos dois chega ao navegador (edge entregas-delivery).
-- 2) entregas_webhook_events: o X-Entregas-Id de cada aviso já processado (o Entregas repete o mesmo id nas
--    tentativas). Limpeza diária pelo cron da migration 2026101203.
-- 3) Colunas entregas_* em orders. ESPELHO: a lista do CHECK orders_entregas_status_check é ENTREGAS_STATUS em
--    supabase/functions/_shared/entregas.ts (npm run smoke:entregas confere).
-- 4) Cifra no Vault (entregas_encryption_key, criada aqui com valor aleatório gerado no banco: nada de segredo
--    no .sql) e as funções entregas_encrypt_secret / entregas_decrypt_secret (cópia das da Foody, 20260951).
-- 5) Exclusividade: um provedor de entrega por empresa. Ligar o Entregas desliga a Foody e vice-versa, na mesma
--    transação (vale para qualquer caminho: edge, app nativo, SQL de suporte).

create extension if not exists pgcrypto;

do $$
begin
  if not exists (select 1 from vault.secrets where name = 'entregas_encryption_key') then
    perform vault.create_secret(
      encode(extensions.gen_random_bytes(32), 'hex'),
      'entregas_encryption_key',
      'Cifra da chave e do segredo do webhook da integração Entregas RestaurantePro'
    );
  end if;
end $$;

-- ---------- Integração por empresa ----------
create table if not exists public.entregas_integrations (
  company_id text primary key references public.companies(id) on delete cascade,
  enabled boolean not null default false,
  encrypted_token text,
  encrypted_webhook_secret text,
  webhook_token text not null unique,
  loja_nome text,
  connected_at timestamptz,
  last_error text,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  constraint entregas_integrations_enabled_check
    check (not enabled or (encrypted_token is not null and encrypted_webhook_secret is not null))
);
alter table public.entregas_integrations enable row level security;
drop policy if exists entregas_integrations_service on public.entregas_integrations;
create policy entregas_integrations_service on public.entregas_integrations
  for all to service_role using (true) with check (true);
revoke all on public.entregas_integrations from public, anon, authenticated;

-- ---------- Avisos já processados (deduplicação do X-Entregas-Id) ----------
create table if not exists public.entregas_webhook_events (
  id text primary key,
  company_id text not null references public.companies(id) on delete cascade,
  order_id text,
  evento text not null,
  received_at timestamptz not null default now()
);
create index if not exists idx_entregas_webhook_events_received on public.entregas_webhook_events (received_at);
alter table public.entregas_webhook_events enable row level security;
drop policy if exists entregas_webhook_events_service on public.entregas_webhook_events;
create policy entregas_webhook_events_service on public.entregas_webhook_events
  for all to service_role using (true) with check (true);
revoke all on public.entregas_webhook_events from public, anon, authenticated;

-- ---------- Colunas novas em orders (preenchidas pelo servidor; o painel só lê) ----------
alter table public.orders add column if not exists entregas_id text;
alter table public.orders add column if not exists entregas_status text;
alter table public.orders add column if not exists entregas_tracking_code text;
alter table public.orders add column if not exists entregas_tracking_url text;
alter table public.orders add column if not exists entregas_driver_name text;
alter table public.orders add column if not exists entregas_error text;

alter table public.orders drop constraint if exists orders_entregas_status_check;
alter table public.orders add constraint orders_entregas_status_check
  check (entregas_status is null or entregas_status in ('procurando','sem_localizacao','aceito','a_caminho','concluido','cancelado','cancelado_pago'));

-- O webhook e o cancelamento localizam o pedido pelo id do Entregas (order_…, único no Entregas).
create unique index if not exists idx_orders_entregas_id
  on public.orders (entregas_id) where entregas_id is not null;

-- ---------- Cifra (padrão foody_encrypt/decrypt_secret) ----------
create or replace function public.entregas_encrypt_secret(plain_text text)
returns text
language plpgsql security definer set search_path = public, extensions as $$
declare k text;
begin
  select decrypted_secret into k
  from vault.decrypted_secrets
  where name = 'entregas_encryption_key'
  limit 1;
  if k is null or k = '' then
    raise exception 'secret entregas_encryption_key nao configurado no Vault';
  end if;
  return encode(pgp_sym_encrypt(plain_text, k), 'base64');
end $$;

create or replace function public.entregas_decrypt_secret(encrypted_text text)
returns text
language plpgsql security definer set search_path = public, extensions as $$
declare k text;
begin
  select decrypted_secret into k
  from vault.decrypted_secrets
  where name = 'entregas_encryption_key'
  limit 1;
  if k is null or k = '' then
    return null;
  end if;
  return pgp_sym_decrypt(decode(encrypted_text, 'base64'), k);
exception when others then
  return null;
end $$;

revoke all on function public.entregas_encrypt_secret(text) from public, anon, authenticated;
revoke all on function public.entregas_decrypt_secret(text) from public, anon, authenticated;
grant execute on function public.entregas_encrypt_secret(text) to service_role;
grant execute on function public.entregas_decrypt_secret(text) to service_role;

-- ---------- Exclusividade Foody x Entregas ----------
-- Sem EXCEPTION de propósito: se desligar o outro falhar, o ligar deste também falha (nunca os dois ligados).
create or replace function public.entregas_integrations_desliga_foody()
returns trigger
language plpgsql security definer set search_path = public as $$
begin
  update foody_integrations set enabled = false, updated_at = now()
   where company_id = new.company_id and enabled;
  return new;
end $$;

create or replace function public.foody_integrations_desliga_entregas()
returns trigger
language plpgsql security definer set search_path = public as $$
begin
  update entregas_integrations set enabled = false, updated_at = now()
   where company_id = new.company_id and enabled;
  return new;
end $$;

revoke all on function public.entregas_integrations_desliga_foody() from public, anon, authenticated;
revoke all on function public.foody_integrations_desliga_entregas() from public, anon, authenticated;

drop trigger if exists entregas_integrations_desliga_foody_trg on public.entregas_integrations;
create trigger entregas_integrations_desliga_foody_trg
  after insert or update of enabled on public.entregas_integrations
  for each row when (new.enabled)
  execute function public.entregas_integrations_desliga_foody();

drop trigger if exists foody_integrations_desliga_entregas_trg on public.foody_integrations;
create trigger foody_integrations_desliga_entregas_trg
  after insert or update of enabled on public.foody_integrations
  for each row when (new.enabled)
  execute function public.foody_integrations_desliga_entregas();
```

- [ ] **Step 4: Rodar e ver passar**

Run: `npm run -s smoke:entregas`
Expected: `smoke:entregas OK` (o aviso `ExperimentalWarning` do Node é normal).

- [ ] **Step 5: Commit**

```bash
git add supabase/migrations/2026101201_entregas_integracao.sql scripts/entregas-smoke.mjs package.json
git commit -m "feat(entregas): tabela da integração, colunas do pedido e exclusividade com a Foody

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Migration da fila e dos gatilhos

**Files:**
- Create: `supabase/migrations/2026101202_entregas_fila_e_gatilhos.sql`
- Modify: `scripts/entregas-smoke.mjs`

As funções `orders_foody_enqueue` e `orders_status_notify_enqueue` são recriadas a partir das versões **mais recentes** (`20260951e` e `20260976`, conferidas iguais ao banco em 12/10/2026 pelo `pg_proc.prosrc`). O CHECK da fila foi conferido no banco: `review_request, foody_dispatch, foody_cancel, foody_tracking_msg, order_status`.

- [ ] **Step 1: Escrever o teste no smoke**

Em `scripts/entregas-smoke.mjs`, acrescente logo **antes** da linha final `console.log('smoke:entregas OK');`:

```js
// 2. Fila e gatilhos (2026101202).
const migB = ler('supabase/migrations/2026101202_entregas_fila_e_gatilhos.sql');
// 2a. O CHECK da outbox mantém TODOS os kinds de antes e ganha os três do Entregas.
const kinds = aspas(trecho(migB, /add constraint message_outbox_kind_check\s+check \(kind in \(([\s\S]*?)\)\);/, 'CHECK kinds'));
for (const k of ['review_request', 'foody_dispatch', 'foody_cancel', 'foody_tracking_msg', 'order_status',
  'entregas_dispatch', 'entregas_cancel', 'entregas_tracking_msg']) {
  assert.ok(kinds.includes(k), `kind ausente no CHECK: ${k}`);
}
// 2b. Terminais do trigger = ENTREGAS_STATUS_TERMINAIS.
assert.deepEqual(
  aspas(trecho(migB, /coalesce\(new\.entregas_status, ''\) not in \(([^)]*)\)/, 'terminais do trigger')),
  [...ENTREGAS_STATUS_TERMINAIS],
);
// 2c. Exclusividade nos dois despachos e o aviso genérico calado para pedido no Entregas.
assert.match(migB, /from foody_integrations fi\s+where fi\.company_id = new\.company_id and fi\.enabled\s+\)/);
assert.match(migB, /from entregas_integrations ei\s+where ei\.company_id = new\.company_id and ei\.enabled\s+\)/);
assert.match(migB, /not \(new\.status = 'out_for_delivery' and \(new\.foody_uid is not null or new\.entregas_id is not null\)\)/);
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `npm run -s smoke:entregas`
Expected: FAIL com `ENOENT` apontando para `2026101202_entregas_fila_e_gatilhos.sql`.

- [ ] **Step 3: Escrever a migration**

`supabase/migrations/2026101202_entregas_fila_e_gatilhos.sql`:

```sql
-- Integração Entregas RestaurantePro: fila, gatilhos e o "Tentar de novo".
--
-- 1) Kinds novos na message_outbox: entregas_dispatch, entregas_cancel e entregas_tracking_msg (entregador: a
--    edge entregas-tick). O CHECK é reescrito com a lista INTEIRA (conferida no banco em 12/10/2026:
--    review_request, foody_dispatch, foody_cancel, foody_tracking_msg, order_status) + os três.
-- 2) orders_entregas_enqueue (trigger novo, WHEN status mudou), no molde do orders_foody_enqueue (20260951e):
--    preparing + delivery + sem entregas_id + Entregas ligado + Foody desligada → entregas_dispatch;
--    cancelled + entregas_id + situação não terminal → entregas_cancel.
--    ESPELHO: a lista de terminais é ENTREGAS_STATUS_TERMINAIS em _shared/entregas.ts (smoke:entregas confere).
-- 3) orders_foody_enqueue recriado a partir da versão do 20260951e (igual ao banco em 12/10/2026), mudando SÓ o
--    despacho: não despacha pela Foody pedido que já está no Entregas nem com o Entregas ligado na empresa.
-- 4) orders_status_notify_enqueue recriado a partir do 20260976 (igual ao banco em 12/10/2026), mudando SÓ a
--    exceção do out_for_delivery: pedido no Entregas (entregas_id) também não enfileira o aviso genérico, porque
--    a entregas_tracking_msg manda "saiu para entrega" COM o link de rastreio (o mesmo mecanismo da Foody).
-- 5) entregas_reenfileirar_despacho: o "Tentar de novo" do card (só service_role; quem chama é a edge
--    entregas-delivery, depois de conferir o JWT e a permissão).

alter table public.message_outbox drop constraint if exists message_outbox_kind_check;
alter table public.message_outbox add constraint message_outbox_kind_check
  check (kind in ('review_request','foody_dispatch','foody_cancel','foody_tracking_msg','order_status',
                  'entregas_dispatch','entregas_cancel','entregas_tracking_msg'));

-- ---------- 2) Entregas ----------
create or replace function public.orders_entregas_enqueue()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
  v_today text := to_char((now() at time zone 'America/Sao_Paulo')::date, 'YYYY-MM-DD');
begin
  -- Despacho: aceito (preparing) + delivery + Entregas ligado + Foody desligada + ainda sem entrega.
  if new.status = 'preparing' and old.status is distinct from new.status
     and coalesce(new.order_type, 'delivery') = 'delivery'
     and new.entregas_id is null
     and new.foody_uid is null
     and exists (
       select 1 from entregas_integrations ei
       where ei.company_id = new.company_id and ei.enabled and ei.encrypted_token is not null
     )
     and not exists (
       select 1 from foody_integrations fi
       where fi.company_id = new.company_id and fi.enabled
     ) then
    insert into message_outbox (kind, company_id, target_date, dedupe_key, payload)
    values ('entregas_dispatch', new.company_id, v_today,
            'entregas:' || new.id, jsonb_build_object('orderId', new.id))
    on conflict (dedupe_key) do nothing;
  end if;

  -- Cancelamento: só quando a entrega já existe no Entregas e não está encerrada. Não depende de a integração
  -- estar ligada: pedido enviado antes do Desconectar também precisa ser cancelado lá.
  if new.status = 'cancelled' and old.status is distinct from new.status
     and new.entregas_id is not null
     and coalesce(new.entregas_status, '') not in ('concluido','cancelado','cancelado_pago') then
    insert into message_outbox (kind, company_id, target_date, dedupe_key, payload)
    values ('entregas_cancel', new.company_id, v_today,
            'entregascancel:' || new.id,
            jsonb_build_object('orderId', new.id, 'entregasId', new.entregas_id))
    on conflict (dedupe_key) do nothing;
  end if;

  return new;
exception when others then
  -- Integração nunca bloqueia a atualização do pedido — mas falha aqui significa "motoboy nunca chamado",
  -- então deixa rastro no log.
  raise warning 'orders_entregas_enqueue falhou para pedido %: %', new.id, sqlerrm;
  return new;
end $$;

revoke all on function public.orders_entregas_enqueue() from public, anon, authenticated;

drop trigger if exists orders_entregas_enqueue_trg on public.orders;
create trigger orders_entregas_enqueue_trg
  after update on public.orders
  for each row
  when (old.status is distinct from new.status)
  execute function public.orders_entregas_enqueue();

-- ---------- 3) Foody (20260951e + a exclusividade no despacho) ----------
create or replace function public.orders_foody_enqueue()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
  v_today text := to_char((now() at time zone 'America/Sao_Paulo')::date, 'YYYY-MM-DD');
begin
  -- Despacho: aceito (preparing) + delivery + integracao ligada + ainda sem uid.
  -- (2026101202) + pedido fora do Entregas + Entregas desligado na empresa.
  if new.status = 'preparing' and old.status is distinct from new.status
     and coalesce(new.order_type, 'delivery') = 'delivery'
     and new.foody_uid is null
     and new.entregas_id is null
     and exists (
       select 1 from foody_integrations fi
       where fi.company_id = new.company_id and fi.enabled and fi.encrypted_token is not null
     )
     and not exists (
       select 1 from entregas_integrations ei
       where ei.company_id = new.company_id and ei.enabled
     ) then
    insert into message_outbox (kind, company_id, target_date, dedupe_key, payload)
    values ('foody_dispatch', new.company_id, v_today,
            'foody:' || new.id, jsonb_build_object('orderId', new.id))
    on conflict (dedupe_key) do nothing;
  end if;

  -- Cancelamento: so quando a entrega ja existe na Foody e nao e terminal.
  -- ESPELHO: a lista de terminais e FOODY_TERMINAL_STATUSES em _shared/foody.ts.
  if new.status = 'cancelled' and old.status is distinct from new.status
     and new.foody_uid is not null
     and coalesce(new.foody_status, '') not in ('delivered','closed','cancelled','rejected') then
    insert into message_outbox (kind, company_id, target_date, dedupe_key, payload)
    values ('foody_cancel', new.company_id, v_today,
            'foodycancel:' || new.id,
            jsonb_build_object('orderId', new.id, 'foodyUid', new.foody_uid))
    on conflict (dedupe_key) do nothing;
  end if;

  return new;
exception when others then
  -- Integracao nunca bloqueia atualizacao de pedido — mas falha aqui significa
  -- "motoboy nunca chamado", entao deixa rastro no log.
  raise warning 'orders_foody_enqueue falhou para pedido %: %', new.id, sqlerrm;
  return new;
end $$;

revoke all on function public.orders_foody_enqueue() from public, anon, authenticated;

-- ---------- 4) Aviso genérico de status (20260976 + a exceção do Entregas) ----------
create or replace function public.orders_status_notify_enqueue()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
  v_today text := to_char((now() at time zone 'America/Sao_Paulo')::date, 'YYYY-MM-DD');
begin
  if new.status in ('preparing', 'out_for_delivery', 'cancelled')
     and old.status is distinct from new.status
     and nullif(btrim(coalesce(new.customer_phone, '')), '') is not null
     and not (new.status = 'out_for_delivery' and (new.foody_uid is not null or new.entregas_id is not null))
     and exists (
       select 1 from companies c
       where c.id = new.company_id
         and (c.wa_notification_settings->>'orderStatus') = 'true'
     ) then
    insert into message_outbox (kind, company_id, target_date, dedupe_key, payload)
    values ('order_status', new.company_id, v_today,
            'order_status:' || new.id || ':' || new.status,
            jsonb_build_object('orderId', new.id, 'status', new.status))
    on conflict (dedupe_key) do nothing;
  end if;
  return new;
exception when others then
  -- Aviso nunca bloqueia a atualizacao do pedido — mas deixa rastro no log.
  raise warning 'orders_status_notify_enqueue falhou para pedido %: %', new.id, sqlerrm;
  return new;
end $$;

revoke all on function public.orders_status_notify_enqueue() from public, anon, authenticated;

-- ---------- 5) "Tentar de novo" ----------
-- Reabre a linha do despacho na fila (a dedupe_key é única: a linha antiga, 'sent' ou 'failed', volta a
-- 'pending' com as tentativas zeradas). Reenviar é seguro: o Entregas é idempotente por pedido_externo_id.
create or replace function public.entregas_reenfileirar_despacho(p_company_id text, p_order_id text)
returns text
language plpgsql
security definer
set search_path = public
as $$
declare
  v_today text := to_char((now() at time zone 'America/Sao_Paulo')::date, 'YYYY-MM-DD');
  v_order record;
begin
  select id, status, order_type, entregas_id, foody_uid into v_order
    from orders where id = p_order_id and company_id = p_company_id;
  if not found then return 'pedido_nao_encontrado'; end if;
  if v_order.entregas_id is not null then return 'ja_enviado'; end if;
  if v_order.foody_uid is not null then return 'na_foody'; end if;
  if coalesce(v_order.order_type, 'delivery') <> 'delivery' then return 'retirada'; end if;
  if v_order.status not in ('preparing', 'out_for_delivery') then return 'status_invalido'; end if;
  if not exists (
    select 1 from entregas_integrations ei
    where ei.company_id = p_company_id and ei.enabled and ei.encrypted_token is not null
  ) then
    return 'integracao_desligada';
  end if;

  update orders set entregas_error = null where id = p_order_id;
  insert into message_outbox (kind, company_id, target_date, dedupe_key, payload)
  values ('entregas_dispatch', p_company_id, v_today, 'entregas:' || p_order_id, jsonb_build_object('orderId', p_order_id))
  on conflict (dedupe_key) do update
    set status = 'pending', attempts = 0, claimed_at = null, next_attempt_at = now(),
        last_error = null, sent_at = null, target_date = excluded.target_date;
  return 'reenfileirado';
end $$;

revoke all on function public.entregas_reenfileirar_despacho(text, text) from public, anon, authenticated;
grant execute on function public.entregas_reenfileirar_despacho(text, text) to service_role;
```

- [ ] **Step 4: Rodar e ver passar**

Run: `npm run -s smoke:entregas`
Expected: `smoke:entregas OK`.

- [ ] **Step 5: Conferir que só o despacho da Foody e a exceção do aviso genérico mudaram**

```bash
diff <(sed -n '/create or replace function public.orders_foody_enqueue/,/^end \$\$;/p' supabase/migrations/20260951e_foody_trigger_hardening.sql) <(sed -n '/create or replace function public.orders_foody_enqueue/,/^end \$\$;/p' supabase/migrations/2026101202_entregas_fila_e_gatilhos.sql)
diff <(sed -n '/create or replace function public.orders_status_notify_enqueue/,/^end \$\$;/p' supabase/migrations/20260976_order_status_notify.sql) <(sed -n '/create or replace function public.orders_status_notify_enqueue/,/^end \$\$;/p' supabase/migrations/2026101202_entregas_fila_e_gatilhos.sql)
```

Expected: no primeiro diff, só as linhas acrescentadas do despacho (`(2026101202) + pedido fora do Entregas…`, `and new.entregas_id is null` e o `and not exists (… entregas_integrations …)`); no segundo, só a linha do `not (new.status = 'out_for_delivery' and …)`.

- [ ] **Step 6: Commit**

```bash
git add supabase/migrations/2026101202_entregas_fila_e_gatilhos.sql scripts/entregas-smoke.mjs
git commit -m "feat(entregas): fila do despacho, cancelamento e aviso do A caminho

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Migration dos crons

**Files:**
- Create: `supabase/migrations/2026101203_entregas_cron.sql`
- Modify: `scripts/entregas-smoke.mjs`

Esta migration é aplicada **depois** do deploy da `entregas-tick` (Task 17).

- [ ] **Step 1: Escrever o teste no smoke**

Em `scripts/entregas-smoke.mjs`, acrescente logo antes de `console.log('smoke:entregas OK');`:

```js
// 3. Cron (2026101203): o entregas-tick nasce do comando do foody-tick (o segredo nunca entra no .sql).
const migC = ler('supabase/migrations/2026101203_entregas_cron.sql');
assert.match(migC, /cron\.schedule\('entregas-tick', '\* \* \* \* \*', replace\(j\.command, 'foody-tick', 'entregas-tick'\)\)/);
assert.doesNotMatch(migC, /x-cron-secret/i);
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `npm run -s smoke:entregas`
Expected: FAIL com `ENOENT` apontando para `2026101203_entregas_cron.sql`.

- [ ] **Step 3: Escrever a migration**

`supabase/migrations/2026101203_entregas_cron.sql`:

```sql
-- Integração Entregas RestaurantePro: crons. Aplicar SÓ DEPOIS do deploy da edge entregas-tick (antes disso o
-- cron chamaria uma função inexistente a cada minuto).
--
-- 1) entregas-tick por minuto. O comando é o do foody-tick com o nome trocado (o mesmo truque do
--    order-status-tick, 20260976): o CRON_SECRET fica só no cron.job, nunca no .sql. Sem o job foody-tick a
--    migration FALHA de propósito, em vez de não agendar nada em silêncio.
-- 2) Limpeza diária dos ids de aviso com mais de 30 dias (04:17 em Brasília = 07:17 UTC). SQL puro, sem segredo.

do $$
begin
  if not exists (select 1 from cron.job where jobname = 'foody-tick') then
    raise exception 'cron foody-tick nao encontrado: agende o entregas-tick a mao (ver o plano)';
  end if;
end $$;

select cron.schedule('entregas-tick', '* * * * *', replace(j.command, 'foody-tick', 'entregas-tick'))
from cron.job j
where j.jobname = 'foody-tick';

select cron.schedule(
  'entregas-webhook-events-limpeza',
  '17 7 * * *',
  $$delete from public.entregas_webhook_events where received_at < now() - interval '30 days'$$
);
```

- [ ] **Step 4: Rodar e ver passar**

Run: `npm run -s smoke:entregas`
Expected: `smoke:entregas OK`.

- [ ] **Step 5: Commit**

```bash
git add supabase/migrations/2026101203_entregas_cron.sql scripts/entregas-smoke.mjs
git commit -m "feat(entregas): cron do entregas-tick e limpeza dos avisos

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Edge `entregas-delivery` (painel)

**Files:**
- Create: `supabase/functions/entregas-delivery/index.ts`

As regras desta edge que valem teste (chave, URLs, mensagens) já estão em `_shared/entregas.ts`; aqui o critério é a checagem de tipos e a prova real da Task 17.

- [ ] **Step 1: Escrever a edge**

`supabase/functions/entregas-delivery/index.ts`:

```ts
declare const Deno: any;

import { getCorsHeaders, json as sharedJson } from '../_shared/cors.ts';
import { supaFetch } from '../_shared/supaFetch.ts';
import { bearerToken } from '../_shared/panelAuth.ts';
import { ENTREGAS_SISTEMA, chaveValida, errosDaResposta, urlDaApi, urlDoWebhook } from '../_shared/entregas.ts';

// Painel → integração Entregas RestaurantePro. Ações: status, connect, disconnect (Configurações de Delivery,
// seção "Entregas por") e retry (o "Tentar de novo" do card do pedido).
//
// ESPELHO de foody-delivery (canViewSettings / canEditDeliverySettings): a seção mora na mesma SettingsPage, que
// exige settings.view na porta. Mudou lá, mudar aqui. O retry vem do kanban e exige orders.edit (quem arrasta o
// card), não settings.
// A chave ent_live_ e o segredo do webhook NUNCA saem daqui: o status devolve só campos inócuos.
const ROLES_WITH_SETTINGS = new Set(['master', 'manager']);
// Preset de orders.edit (constants.ts): master, manager, sector_lead e collaborator; o comprador fica de fora.
const ROLES_WITH_ORDERS_EDIT = new Set(['master', 'manager', 'sector_lead', 'collaborator']);
const TIMEOUT_MS = 10_000;

function canViewSettings(profile: any): boolean {
  const v = profile?.permissions?.settings?.view;
  if (typeof v === 'boolean') return v;
  return ROLES_WITH_SETTINGS.has(String(profile?.role || ''));
}

function canEditDeliverySettings(profile: any): boolean {
  const s = profile?.permissions?.settings?.edit;
  const o = profile?.permissions?.orders?.edit;
  if (typeof s === 'boolean' || typeof o === 'boolean') return s === true || o === true;
  return ROLES_WITH_SETTINGS.has(String(profile?.role || ''));
}

function canEditOrders(profile: any): boolean {
  const v = profile?.permissions?.orders?.edit;
  if (typeof v === 'boolean') return v;
  return ROLES_WITH_ORDERS_EDIT.has(String(profile?.role || ''));
}

// Resultado da RPC entregas_reenfileirar_despacho (migration 2026101202) → resposta ao painel.
const RETRY_RECUSAS: Record<string, [number, string]> = {
  pedido_nao_encontrado: [404, 'Pedido não encontrado.'],
  ja_enviado: [409, 'Este pedido já está no Entregas.'],
  na_foody: [409, 'Este pedido foi enviado pela Foody.'],
  retirada: [409, 'Pedido de retirada não vai ao motoboy.'],
  status_invalido: [409, 'Só pedidos em preparo ou a caminho podem ser enviados ao Entregas.'],
  integracao_desligada: [409, 'A integração com o Entregas está desligada nesta empresa.'],
};

Deno.serve(async (req: Request) => {
  const cors = getCorsHeaders(req.headers.get('origin'));
  const j = (s: number, b: unknown) => sharedJson(s, b, cors);
  if (req.method === 'OPTIONS') return new Response('ok', { headers: cors });
  if (req.method !== 'POST') return j(405, { error: 'Method not allowed' });

  const supabaseUrl = (Deno.env.get('SUPABASE_URL') || '').trim();
  const serviceKey = (Deno.env.get('SUPABASE_SERVICE_ROLE_KEY') || '').trim();
  if (!supabaseUrl || !serviceKey) return j(500, { error: 'Supabase env vars missing' });

  let body: any = null;
  try { body = await req.json(); } catch { return j(400, { error: 'Invalid JSON' }); }
  const action = String(body?.action || '');
  const companyId = String(body?.companyId || '').trim();
  if (!companyId) return j(400, { error: 'companyId obrigatório' });

  // ── Auth: JWT do painel. O vínculo usuário↔empresa vem do PERFIL, nunca do body sozinho (padrão panelAuth).
  const token = bearerToken(req.headers.get('authorization'));
  if (!token) return j(401, { error: 'Missing authorization' });
  const userRes = await fetch(`${supabaseUrl}/auth/v1/user`, {
    headers: { Authorization: `Bearer ${token}`, apikey: serviceKey },
  });
  if (!userRes.ok) return j(401, { error: 'Invalid session' });
  const user = await userRes.json();
  if (!user?.id) return j(401, { error: 'Invalid session' });

  const profRes = await supaFetch(supabaseUrl, serviceKey,
    `profiles?id=eq.${encodeURIComponent(user.id)}&select=role,company_ids,permissions`);
  const profiles = await profRes.json();
  const profile = Array.isArray(profiles) ? profiles[0] : null;
  if (!profile) return j(403, { error: 'Profile not found' });
  const isMaster = profile.role === 'master';
  if (!isMaster && !(profile.company_ids || []).includes(companyId)) {
    return j(403, { error: 'Access denied' });
  }
  if (action === 'retry') {
    if (!isMaster && !canEditOrders(profile)) return j(403, { error: 'Sem permissão para editar pedidos' });
  } else {
    if (!isMaster && !canViewSettings(profile)) return j(403, { error: 'Sem permissão para ver as configurações' });
    const isMutatingAction = action === 'connect' || action === 'disconnect';
    if (isMutatingAction && !isMaster && !canEditDeliverySettings(profile)) {
      return j(403, { error: 'Sem permissão para configurar o delivery' });
    }
  }

  const getIntegration = async () => {
    const r = await supaFetch(supabaseUrl, serviceKey,
      `entregas_integrations?company_id=eq.${encodeURIComponent(companyId)}` +
      `&select=company_id,enabled,encrypted_token,loja_nome,connected_at,last_error&limit=1`);
    const rows = await r.json();
    return Array.isArray(rows) && rows.length > 0 ? rows[0] : null;
  };

  const statusBody = (row: any) => (row ? {
    enabled: row.enabled === true,
    hasToken: !!row.encrypted_token,
    lojaNome: row.loja_nome || null,
    connectedAt: row.connected_at || null,
    lastError: row.last_error || null,
  } : null);

  const encrypt = async (plain: string): Promise<string | null> => {
    const r = await supaFetch(supabaseUrl, serviceKey, 'rpc/entregas_encrypt_secret', {
      method: 'POST', body: JSON.stringify({ plain_text: plain }),
    });
    if (!r.ok) return null;
    const v = await r.json().catch(() => null);
    return typeof v === 'string' && v ? v : null;
  };

  if (action === 'status') {
    return j(200, { integration: statusBody(await getIntegration()) });
  }

  if (action === 'connect') {
    const chave = String(body?.chave || '').trim();
    if (!chaveValida(chave)) {
      return j(400, { error: 'Chave inválida: ela começa com ent_live_ e tem 49 caracteres. Copie de novo a chave que a central do Entregas gerou.' });
    }
    // Token novo a cada conexão: a URL antiga (de uma conexão anterior) deixa de valer (410 no webhook).
    const webhookToken = crypto.randomUUID().replace(/-/g, '');
    let res: Response;
    try {
      res = await fetch(urlDaApi(Deno.env.get('ENTREGAS_API_URL'), 'conexao'), {
        method: 'POST',
        headers: { Authorization: `Bearer ${chave}`, 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          sistema: ENTREGAS_SISTEMA,
          webhook_url: urlDoWebhook(Deno.env.get('PUBLIC_SUPABASE_URL'), webhookToken),
        }),
        signal: AbortSignal.timeout(TIMEOUT_MS),
      });
    } catch {
      return j(502, { error: 'Não foi possível falar com o Entregas. Tente de novo.' });
    }
    const corpo = await res.json().catch(() => null);
    if (res.status === 401) return j(400, { error: 'Chave recusada pelo Entregas (revogada ou inválida). Peça uma nova à central do Entregas.' });
    if (res.status === 429) return j(429, { error: 'Muitas tentativas. Espere um minuto e tente de novo.' });
    if (!res.ok) {
      const erro = errosDaResposta(corpo)[0];
      return j(res.status >= 500 ? 502 : 400, { error: erro ? `Entregas: ${erro}` : `O Entregas respondeu ${res.status}. Tente de novo.` });
    }
    const segredo = String(corpo?.webhook_segredo || '');
    if (!segredo) return j(502, { error: 'O Entregas não devolveu o segredo do webhook. Tente de novo.' });

    const [encToken, encSegredo] = await Promise.all([encrypt(chave), encrypt(segredo)]);
    if (!encToken || !encSegredo) return j(500, { error: 'Falha ao guardar a chave com segurança.' });

    // enabled=true dispara o trigger entregas_integrations_desliga_foody (2026101201): a Foody da empresa é
    // desligada NA MESMA transação. Não há janela com os dois ligados.
    const now = new Date().toISOString();
    const up = await supaFetch(supabaseUrl, serviceKey, 'entregas_integrations?on_conflict=company_id', {
      method: 'POST',
      body: JSON.stringify({
        company_id: companyId,
        enabled: true,
        encrypted_token: encToken,
        encrypted_webhook_secret: encSegredo,
        webhook_token: webhookToken,
        loja_nome: String(corpo?.loja?.nome || '').trim().slice(0, 200) || null,
        connected_at: now,
        last_error: null,
        updated_at: now,
      }),
    });
    if (!up.ok) {
      console.error('[entregas-delivery] upsert falhou', companyId, up.status);
      return j(500, { error: 'Falha ao salvar a integração. Conecte de novo.' });
    }
    return j(200, { integration: statusBody(await getIntegration()) });
  }

  if (action === 'disconnect') {
    // Desliga e rotaciona o webhook_token (a URL que o Entregas conhece passa a dar 410). A chave cifrada FICA:
    // um pedido já enviado e cancelado depois no kanban ainda precisa chegar ao Entregas (entregas_cancel).
    const up = await supaFetch(supabaseUrl, serviceKey,
      `entregas_integrations?company_id=eq.${encodeURIComponent(companyId)}`, {
        method: 'PATCH',
        body: JSON.stringify({
          enabled: false,
          webhook_token: crypto.randomUUID().replace(/-/g, ''),
          last_error: null,
          updated_at: new Date().toISOString(),
        }),
      });
    if (!up.ok) return j(500, { error: 'Falha ao desconectar.' });
    return j(200, { integration: statusBody(await getIntegration()) });
  }

  if (action === 'retry') {
    const orderId = String(body?.orderId || '').trim();
    if (!orderId) return j(400, { error: 'orderId obrigatório' });
    const r = await supaFetch(supabaseUrl, serviceKey, 'rpc/entregas_reenfileirar_despacho', {
      method: 'POST', body: JSON.stringify({ p_company_id: companyId, p_order_id: orderId }),
    });
    if (!r.ok) return j(500, { error: 'Falha ao reenviar o pedido ao Entregas.' });
    const resultado = String(await r.json().catch(() => ''));
    if (resultado === 'reenfileirado') return j(200, { resultado });
    const [status, error] = RETRY_RECUSAS[resultado] || [500, 'Resposta inesperada ao reenviar.'];
    return j(status, { error, resultado });
  }

  return j(400, { error: `Ação desconhecida: ${action}` });
});
```

- [ ] **Step 2: Checar os tipos**

Run: `deno check supabase/functions/entregas-delivery/index.ts`
Expected: `Check supabase/functions/entregas-delivery/index.ts` sem erro.

- [ ] **Step 3: Commit**

```bash
git add supabase/functions/entregas-delivery/index.ts
git commit -m "feat(entregas): edge do painel (status, conectar, desconectar e tentar de novo)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Edge `entregas-tick` (cron)

**Files:**
- Create: `supabase/functions/entregas-tick/index.ts`
- Modify: `scripts/service-auth-smoke.mjs` (lista `CALLERS`)

- [ ] **Step 1: Pôr a edge nova no guardrail da service key (teste primeiro)**

Em `scripts/service-auth-smoke.mjs`, na lista `CALLERS`, logo depois de `  ['foody-tick', 'index.ts'],` acrescente:

```js
  ['entregas-tick', 'index.ts'],
```

Run: `npm run -s smoke:serviceauth`
Expected: FAIL com `ENOENT` apontando para `supabase/functions/entregas-tick/index.ts`.

- [ ] **Step 2: Escrever a edge**

`supabase/functions/entregas-tick/index.ts`:

```ts
declare const Deno: any;

import { getCorsHeaders, json as sharedJson } from '../_shared/cors.ts';
import { verifyCronSecret } from '../_shared/cronAuth.ts';
import { supaFetch } from '../_shared/supaFetch.ts';
import { normalizeWaId, sendCustomerWhatsApp } from '../_shared/customerWaSend.ts';
import { geocodificarEndereco } from '../_shared/googleGeocode.ts';
import {
  ENTREGAS_MAX_TENTATIVAS,
  classificarResposta,
  corpoDoPedido,
  mensagemDeErro,
  patchDaCriacao,
  primeiroNome,
  textoDoAcaminho,
  urlDaApi,
} from '../_shared/entregas.ts';

// Entregador da fila message_outbox para o Entregas RestaurantePro (cron de minuto, x-cron-secret), no molde da
// foody-tick. Kinds: entregas_dispatch (POST pedidos), entregas_cancel (POST pedidos/{id}/cancelar) e
// entregas_tracking_msg (WhatsApp "A caminho" com o link de rastreio).
//
// Orçamento de fetches (~30 por invocação no Edge Runtime, mesma conta da foody-tick): overhead 6 (3 claims +
// 1 complete + 2 lookups) + chaves <=3 + despacho 2x5 (pedido + geocode + POST + patch + cancelamento que cai
// depois) + cancelamento 1x2 + mensagem 1x7 (pedido + até 6 do customerWaSend) ~= 28. Sobrou fila: o próximo
// minuto continua.
//
// Reenviar o POST é SEGURO: o Entregas é idempotente por pedido_externo_id (= orders.id) e devolve o mesmo pedido.
// Por isso, ao contrário da foody-tick, falha ao gravar o id no pedido volta para a fila em vez de sair dela.
const DISPATCH_CLAIM = 2;
const CANCEL_CLAIM = 1;
const TRACKING_CLAIM = 1;
const TIMEOUT_MS = 10_000;
const TRACKING_TEMPLATE = 'pedido_a_caminho_v1';

interface OutboxRow { id: string; kind: string; company_id: string; payload: any; attempts: number }
interface DeliverResult { id: string; attempts: number; ok: boolean; error?: string }

Deno.serve(async (req: Request) => {
  const cors = getCorsHeaders(req.headers.get('origin'));
  const json = (s: number, b: unknown) => sharedJson(s, b, cors);
  if (req.method === 'OPTIONS') return new Response('ok', { headers: cors });
  if (req.method !== 'POST') return json(405, { error: 'Method not allowed' });
  if (!verifyCronSecret(req)) return json(401, { error: 'Unauthorized' });

  const supabaseUrl = (Deno.env.get('SUPABASE_URL') || '').trim();
  const serviceKey = (Deno.env.get('SUPABASE_SERVICE_ROLE_KEY') || '').trim();
  if (!supabaseUrl || !serviceKey) return json(500, { error: 'Supabase env vars missing' });
  const entregasBase = Deno.env.get('ENTREGAS_API_URL');
  const googleKey = (Deno.env.get('GOOGLE_MAPS_API_KEY') || '').trim();

  const headers = {
    apikey: serviceKey,
    Authorization: `Bearer ${serviceKey}`,
    'Content-Type': 'application/json',
  };
  const rpc = async (fn: string, body: unknown) => {
    const res = await fetch(`${supabaseUrl}/rest/v1/rpc/${fn}`, {
      method: 'POST', headers, body: JSON.stringify(body),
    });
    if (!res.ok) throw new Error(`${fn} falhou (${res.status}): ${await res.text()}`);
    return res.json();
  };

  let claimed = 0;
  let sent = 0;
  let failed = 0;

  try {
    const [dispatchRows, cancelRows, trackingRows]: [OutboxRow[], OutboxRow[], OutboxRow[]] = await Promise.all([
      rpc('message_outbox_claim', { p_kind: 'entregas_dispatch', p_limit: DISPATCH_CLAIM }),
      rpc('message_outbox_claim', { p_kind: 'entregas_cancel', p_limit: CANCEL_CLAIM }),
      rpc('message_outbox_claim', { p_kind: 'entregas_tracking_msg', p_limit: TRACKING_CLAIM }),
    ]);
    const rows = [
      ...(Array.isArray(dispatchRows) ? dispatchRows : []),
      ...(Array.isArray(cancelRows) ? cancelRows : []),
      ...(Array.isArray(trackingRows) ? trackingRows : []),
    ];
    if (rows.length === 0) return json(200, { ok: true, claimed: 0, sent: 0, failed: 0 });
    claimed = rows.length;

    // Lotes: integrações (ligadas OU NÃO: o cancelamento de um pedido já enviado precisa da chave mesmo depois do
    // Desconectar) + empresas. A chave NUNCA está no payload da fila: é decifrada na hora, uma vez por empresa.
    const companyIds = [...new Set(rows.map((r) => r.company_id))];
    const inList = companyIds.map((id) => encodeURIComponent(id)).join(',');
    const [intRes, compRes] = await Promise.all([
      supaFetch(supabaseUrl, serviceKey,
        `entregas_integrations?company_id=in.(${inList})&select=company_id,enabled,encrypted_token`),
      supaFetch(supabaseUrl, serviceKey,
        `companies?id=in.(${inList})&select=id,company_name,address_city,evolution_instance_name,wa_send_routing`),
    ]);
    const integrations: any[] = intRes.ok ? await intRes.json() : [];
    const companies: any[] = compRes.ok ? await compRes.json() : [];
    const integrationMap = new Map<string, any>(integrations.map((i) => [i.company_id, i]));
    const companyMap = new Map<string, any>(companies.map((c) => [c.id, c]));

    const tokenCache = new Map<string, string | null>();
    const entregasToken = async (companyId: string): Promise<string | null> => {
      if (tokenCache.has(companyId)) return tokenCache.get(companyId) ?? null;
      const integ = integrationMap.get(companyId);
      let tok: string | null = null;
      if (integ?.encrypted_token) {
        const r = await supaFetch(supabaseUrl, serviceKey, 'rpc/entregas_decrypt_secret', {
          method: 'POST', body: JSON.stringify({ encrypted_text: integ.encrypted_token }),
        });
        if (r.ok) {
          const v = await r.json();
          tok = typeof v === 'string' && v ? v : null;
        }
      }
      tokenCache.set(companyId, tok);
      return tok;
    };

    const entregasFetch = (token: string, caminho: string, init: RequestInit) =>
      fetch(urlDaApi(entregasBase, caminho), {
        ...init,
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', Accept: 'application/json' },
        signal: AbortSignal.timeout(TIMEOUT_MS),
      });

    const patchOrder = (orderId: string, patch: Record<string, unknown>) =>
      supaFetch(supabaseUrl, serviceKey, `orders?id=eq.${encodeURIComponent(orderId)}`, {
        method: 'PATCH', headers: { Prefer: 'return=minimal' }, body: JSON.stringify(patch),
      });

    const getOrder = async (orderId: string) => {
      if (!orderId) return null;
      const r = await supaFetch(supabaseUrl, serviceKey,
        `orders?id=eq.${encodeURIComponent(orderId)}` +
        `&select=id,company_id,status,order_type,customer_name,customer_phone,delivery_address,observation,total,` +
        `payment_method,payment_change_for,payment_status,refund_status,foody_uid,entregas_id,entregas_status,` +
        `entregas_tracking_code,entregas_tracking_url&limit=1`);
      const found = await r.json();
      return Array.isArray(found) && found.length > 0 ? found[0] : null;
    };

    const hoje = () => new Date(Date.now() - 3 * 3600 * 1000).toISOString().slice(0, 10);
    const enqueue = async (kind: string, companyId: string, dedupeKey: string, payload: Record<string, unknown>) => {
      const r = await supaFetch(supabaseUrl, serviceKey, 'message_outbox?on_conflict=dedupe_key', {
        method: 'POST',
        headers: { Prefer: 'return=minimal,resolution=ignore-duplicates' },
        body: JSON.stringify({ kind, company_id: companyId, target_date: hoje(), dedupe_key: dedupeKey, payload }),
      });
      if (!r.ok) console.error(`[entregas-tick] enqueue ${kind} falhou`, dedupeKey, r.status);
    };

    // Falha temporária: volta para a fila (backoff do complete: 3, 9, 27, 81 min). Na última tentativa o card
    // mostra o erro (no despacho, com o botão "Tentar de novo"; no cancelamento, o aviso para falar com a central).
    const temporaria = async (
      row: OutboxRow, orderId: string, texto: string, prefixo = '', final = ' Toque em Tentar de novo.',
    ): Promise<DeliverResult> => {
      if (row.attempts >= ENTREGAS_MAX_TENTATIVAS) {
        await patchOrder(orderId, { entregas_error: `${prefixo}${texto}${final}`.slice(0, 500) }).catch(() => {});
      }
      return { id: row.id, attempts: row.attempts, ok: false, error: texto.slice(0, 300) };
    };

    // ── Despacho ─────────────────────────────────────────────────────────
    const deliverDispatch = async (row: OutboxRow): Promise<DeliverResult> => {
      const base = { id: row.id, attempts: row.attempts };
      const order = await getOrder(String(row.payload?.orderId || ''));
      // Sumiu, é de outra empresa, foi cancelado/concluído, virou retirada ou já tem entrega: sair da fila.
      if (!order || order.company_id !== row.company_id) return { ...base, ok: true };
      if (order.status === 'cancelled' || order.status === 'completed' || order.order_type === 'pickup') return { ...base, ok: true };
      if (order.entregas_id || order.foody_uid) return { ...base, ok: true };
      // Dono desligou a integração entre o enqueue e a entrega: respeitar.
      if (!integrationMap.get(row.company_id)?.enabled) return { ...base, ok: true };
      const token = await entregasToken(row.company_id);
      if (!token) {
        await patchOrder(order.id, { entregas_error: 'Chave do Entregas indisponível no cofre. Conecte de novo em Configurações de Delivery.' }).catch(() => {});
        return { ...base, ok: false, error: 'chave do Entregas indisponível (Vault)' };
      }

      // Geocode próprio (Google), best-effort: só o ponto PRECISO (rua e número) vai como coordenada.
      const geo = googleKey ? await geocodificarEndereco(order.delivery_address, googleKey) : null;
      const montagem = corpoDoPedido(order, geo, companyMap.get(row.company_id)?.address_city ?? null);
      if (!montagem.ok) {
        await patchOrder(order.id, { entregas_error: montagem.erro });
        return { ...base, ok: true };
      }

      let res: Response;
      try {
        res = await entregasFetch(token, 'pedidos', { method: 'POST', body: JSON.stringify(montagem.corpo) });
      } catch (err: any) {
        return temporaria(row, order.id, mensagemDeErro(0, null) + ` (${String(err?.name || 'rede')})`);
      }
      const corpo = await res.json().catch(() => null);
      const classe = classificarResposta(res.status);

      if (classe === 'ok') {
        const dados = patchDaCriacao(corpo);
        if (!dados) return temporaria(row, order.id, 'O Entregas não devolveu o número do pedido.');
        // entregas_id=is.null: se o webhook do aceite chegou antes desta gravação, ele já gravou tudo (e a
        // situação dele é mais nova). Nesse caso o PATCH casa 0 linhas e está tudo certo.
        const pr = await supaFetch(supabaseUrl, serviceKey,
          `orders?id=eq.${encodeURIComponent(order.id)}&entregas_id=is.null&select=id,status`, {
            method: 'PATCH', headers: { Prefer: 'return=representation' }, body: JSON.stringify(dados),
          });
        if (!pr.ok) return { ...base, ok: false, error: `pedido não gravado (${pr.status}); reenvio seguro` };
        const gravados = await pr.json().catch(() => []);
        // Cancelado no kanban enquanto o POST estava no ar: o trigger não viu o entregas_id, então o
        // cancelamento é enfileirado aqui.
        if (Array.isArray(gravados) && gravados[0]?.status === 'cancelled') {
          await enqueue('entregas_cancel', row.company_id, `entregascancel:${order.id}`, { orderId: order.id, entregasId: dados.entregas_id });
        }
        return { ...base, ok: true };
      }

      const msg = mensagemDeErro(res.status, corpo);
      if (classe === 'definitivo') {
        await patchOrder(order.id, { entregas_error: msg });
        if (res.status === 401) {
          await supaFetch(supabaseUrl, serviceKey,
            `entregas_integrations?company_id=eq.${encodeURIComponent(row.company_id)}`, {
              method: 'PATCH', headers: { Prefer: 'return=minimal' }, body: JSON.stringify({ last_error: msg }),
            });
        }
        return { ...base, ok: true };
      }
      return temporaria(row, order.id, msg);
    };

    // ── Cancelamento ─────────────────────────────────────────────────────
    const deliverCancel = async (row: OutboxRow): Promise<DeliverResult> => {
      const base = { id: row.id, attempts: row.attempts };
      const orderId = String(row.payload?.orderId || '');
      const entregasId = String(row.payload?.entregasId || '');
      if (!orderId || !entregasId) return { ...base, ok: true };
      const prefixo = 'Cancelamento não chegou ao Entregas: ';
      const token = await entregasToken(row.company_id);
      if (!token) {
        await patchOrder(orderId, { entregas_error: `${prefixo}chave indisponível. Avise a central do Entregas.` }).catch(() => {});
        return { ...base, ok: true };
      }

      let res: Response;
      try {
        res = await entregasFetch(token, `pedidos/${encodeURIComponent(entregasId)}/cancelar`, { method: 'POST', body: '{}' });
      } catch (err: any) {
        return temporaria(row, orderId, mensagemDeErro(0, null) + ` (${String(err?.name || 'rede')})`, prefixo, ' Avise a central do Entregas.');
      }
      const corpo = await res.json().catch(() => null);
      if (res.ok) {
        await patchOrder(orderId, {
          entregas_status: corpo?.resultado === 'cancelado_pago' ? 'cancelado_pago' : 'cancelado',
          entregas_error: null,
        });
        return { ...base, ok: true };
      }
      // O motoboy já entregou: nada a cancelar (o kanban segue como a loja deixou).
      if (res.status === 409 && corpo?.resultado === 'ja_concluido') {
        await patchOrder(orderId, { entregas_status: 'concluido' });
        return { ...base, ok: true };
      }
      if (res.status === 404) return { ...base, ok: true };
      const msg = mensagemDeErro(res.status, corpo);
      if (classificarResposta(res.status) === 'definitivo') {
        await patchOrder(orderId, { entregas_error: `${prefixo}${msg} Avise a central do Entregas.`.slice(0, 500) });
        return { ...base, ok: true };
      }
      return temporaria(row, orderId, msg, prefixo, ' Avise a central do Entregas.');
    };

    // ── WhatsApp "A caminho" com o link de rastreio ──────────────────────
    const deliverTracking = async (row: OutboxRow): Promise<DeliverResult> => {
      const base = { id: row.id, attempts: row.attempts };
      const order = await getOrder(String(row.payload?.orderId || ''));
      if (!order || order.company_id !== row.company_id) return { ...base, ok: true };
      // Já entregue ou cancelado quando a fila andou: "saiu para entrega" não faz mais sentido.
      if (order.status === 'cancelled' || order.status === 'completed') return { ...base, ok: true };
      const to = normalizeWaId(String(order.customer_phone || ''));
      if (!to || to.length < 12) return { ...base, ok: true };
      const link = String(order.entregas_tracking_url || '');
      if (!link) return { ...base, ok: false, error: 'link de rastreio ainda indisponível' };

      const company = companyMap.get(row.company_id);
      const nome = primeiroNome(order.customer_name);
      const loja = String(company?.company_name || '');
      const enviado = await sendCustomerWhatsApp({
        supabaseUrl, serviceKey, companyId: row.company_id, company, to,
        wahaText: textoDoAcaminho(nome, loja, link, order.entregas_tracking_code),
        template: {
          name: TRACKING_TEMPLATE,
          params: [{
            type: 'body',
            parameters: [
              { type: 'text', text: nome },
              { type: 'text', text: loja },
              { type: 'text', text: link },
            ],
          }],
        },
        metadata: { automated: true, entregas_order_id: order.id },
      });
      // Sem canal ou sem template aprovado (skipped): sai da fila em silêncio; o link continua no card.
      if (enviado.outcome === 'failed') return { ...base, ok: false, error: enviado.error || 'envio falhou' };
      return { ...base, ok: true };
    };

    const deliver = (row: OutboxRow): Promise<DeliverResult> => {
      if (row.kind === 'entregas_dispatch') return deliverDispatch(row);
      if (row.kind === 'entregas_cancel') return deliverCancel(row);
      if (row.kind === 'entregas_tracking_msg') return deliverTracking(row);
      // Kind sem entregador FALHA ALTO de propósito (regra da casa).
      return Promise.resolve({ id: row.id, attempts: row.attempts, ok: false, error: `kind sem entregador: ${row.kind}` });
    };

    const results: DeliverResult[] = [];
    for (const row of rows) {
      // Exceção de UMA linha não aborta o lote nem pula o complete (senão a linha fica "claimed" e volta em 5 min).
      try {
        results.push(await deliver(row));
      } catch (err: any) {
        results.push({ id: row.id, attempts: row.attempts, ok: false, error: `excecao: ${String(err?.message || err)}`.slice(0, 300) });
      }
    }
    sent = results.filter((r) => r.ok).length;
    failed = results.filter((r) => !r.ok).length;

    const done = await rpc('message_outbox_complete', { p_results: results });
    const rejected = Array.isArray(done?.rejected) ? done.rejected.length : 0;
    const invalid = Array.isArray(done?.invalid) ? done.invalid.length : 0;
    if (rejected > 0 || invalid > 0) {
      console.error(`[entregas-tick] complete parcial: rejected=${rejected} invalid=${invalid} de ${results.length}`);
    }
  } catch (err: any) {
    // Abortar deixa as linhas reivindicadas: voltam sozinhas em 5 min.
    console.error('[entregas-tick] abortado:', err);
    return json(500, { error: String(err?.message || err), claimed, sent, failed });
  }

  if (claimed > 0 || failed > 0) {
    console.log(`[entregas-tick] claimed=${claimed} sent=${sent} failed=${failed}`);
  }
  return json(200, { ok: true, claimed, sent, failed });
});
```

- [ ] **Step 3: Checar os tipos e o guardrail**

```bash
deno check supabase/functions/entregas-tick/index.ts
npm run -s smoke:serviceauth
```

Expected: `Check supabase/functions/entregas-tick/index.ts` sem erro e `service-auth-smoke: OK`.

- [ ] **Step 4: Commit**

```bash
git add supabase/functions/entregas-tick/index.ts scripts/service-auth-smoke.mjs
git commit -m "feat(entregas): entregador da fila (despacho, cancelamento e WhatsApp do A caminho)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Edge `entregas-webhook` (avisos do Entregas)

**Files:**
- Create: `supabase/functions/entregas-webhook/index.ts`

A conferência da assinatura, a leitura do aviso e a cerca estão testadas na Task 4. Esta edge só costura: corpo cru → token → segredo → HMAC → dedupe → pedido → PATCH com a cerca → fila → registro do id.

- [ ] **Step 1: Escrever a edge**

`supabase/functions/entregas-webhook/index.ts`:

```ts
declare const Deno: any;

import { supaFetch } from '../_shared/supaFetch.ts';
import { assinaturaConfere, avisoDoCorpo, mudancaDoAviso, tokenDoWebhookValido } from '../_shared/entregas.ts';
import type { PedidoNoBanco } from '../_shared/entregas.ts';

// Avisos do Entregas RestaurantePro (pedido.aceito, a_caminho, concluido, cancelado), publicado com
// --no-verify-jwt. O contrato do lado de lá: 2xx = aceito; outro status ou rede = nova tentativa (até 5 em
// 30 min); 410 = o Entregas para de mandar AQUELE aviso. Por isso, ao contrário do foody-webhook (sempre 200):
// - token desconhecido (URL de um vínculo desconectado) = 410;
// - assinatura errada = 401; corpo ilegível = 400;
// - erro nosso de banco = 500 (o Entregas repete; aplicar de novo é inofensivo, a cerca e as dedupe_key seguram);
// - X-Entregas-Id já registrado = 200 sem fazer nada.
// Trabalho pesado (WhatsApp, cancelamento) vai para a fila da entregas-tick: o Entregas espera no máximo 5 s.

const COLUNAS = 'id,status,entregas_id,entregas_status,entregas_driver_name,entregas_tracking_code,entregas_tracking_url';

const resposta = (status: number, corpo: unknown) =>
  new Response(JSON.stringify(corpo), { status, headers: { 'Content-Type': 'application/json' } });

class ErroDoBanco extends Error {}

Deno.serve(async (req: Request) => {
  if (req.method !== 'POST') return resposta(405, { error: 'Method not allowed' });
  // O corpo CRU, antes de qualquer parse: a assinatura é do texto exato que o Entregas mandou.
  let corpoCru: string;
  try {
    corpoCru = await req.text();
  } catch {
    return resposta(400, { error: 'corpo ilegível' });
  }

  const supabaseUrl = (Deno.env.get('SUPABASE_URL') || '').trim();
  const serviceKey = (Deno.env.get('SUPABASE_SERVICE_ROLE_KEY') || '').trim();
  if (!supabaseUrl || !serviceKey) return resposta(500, { error: 'configuração ausente' });

  const token = new URL(req.url).searchParams.get('t') || '';
  if (!tokenDoWebhookValido(token)) return resposta(410, { error: 'integração desconhecida' });

  const exigirOk = async (res: Response) => {
    if (!res.ok) throw new ErroDoBanco(`${res.status}: ${(await res.text().catch(() => '')).slice(0, 200)}`);
    return res;
  };
  const lerJson = async (res: Response) => (await exigirOk(res)).json();

  try {
    const integracoes = await lerJson(await supaFetch(supabaseUrl, serviceKey,
      `entregas_integrations?webhook_token=eq.${encodeURIComponent(token)}&select=company_id,encrypted_webhook_secret&limit=1`));
    const integracao = Array.isArray(integracoes) ? integracoes[0] : null;
    // Só o token vigente vale (o Desconectar rotaciona). Integração DESLIGADA com o token vigente continua
    // aceitando: a troca para a Foody desliga sem rotacionar, e os pedidos que já estão no Entregas precisam
    // seguir andando no kanban. A assinatura abaixo é o que autentica de fato.
    if (!integracao?.encrypted_webhook_secret) return resposta(410, { error: 'integração desconhecida' });
    const companyId = String(integracao.company_id);

    const segredo = await lerJson(await supaFetch(supabaseUrl, serviceKey, 'rpc/entregas_decrypt_secret', {
      method: 'POST', body: JSON.stringify({ encrypted_text: integracao.encrypted_webhook_secret }),
    }));
    if (typeof segredo !== 'string' || !segredo) throw new ErroDoBanco('segredo do webhook indisponível (Vault)');

    if (!(await assinaturaConfere(corpoCru, req.headers.get('x-entregas-assinatura'), segredo))) {
      console.warn('[entregas-webhook] assinatura recusada', companyId);
      return resposta(401, { error: 'assinatura inválida' });
    }

    let corpo: unknown;
    try {
      corpo = JSON.parse(corpoCru);
    } catch {
      return resposta(400, { error: 'JSON inválido' });
    }
    const lido = avisoDoCorpo(corpo, req.headers.get('x-entregas-evento'), req.headers.get('x-entregas-id'));
    if (!lido.ok) return resposta(400, { error: lido.erro });
    const aviso = lido.aviso;

    // Aviso repetido (o Entregas mandou de novo porque não viu a nossa resposta): nada a fazer.
    const repetidos = await lerJson(await supaFetch(supabaseUrl, serviceKey,
      `entregas_webhook_events?id=eq.${encodeURIComponent(aviso.idEntrega)}&select=id&limit=1`));
    if (Array.isArray(repetidos) && repetidos.length > 0) return resposta(200, { ok: true, repetido: true });

    const lerPedido = async (): Promise<(PedidoNoBanco & { id: string }) | null> => {
      const found = await lerJson(await supaFetch(supabaseUrl, serviceKey,
        `orders?id=eq.${encodeURIComponent(aviso.pedidoExternoId)}&company_id=eq.${encodeURIComponent(companyId)}` +
        `&select=${COLUNAS}&limit=1`));
      return Array.isArray(found) && found.length > 0 ? found[0] : null;
    };

    // Aplica a mudança com a cerca otimista (status=eq.<o lido>). true = gravou ou não havia o que gravar.
    const aplicar = async (pedido: PedidoNoBanco & { id: string }) => {
      const mudanca = mudancaDoAviso(aviso, pedido);
      if (Object.keys(mudanca.patch).length === 0) return { gravou: true, mudanca };
      const gravados = await lerJson(await supaFetch(supabaseUrl, serviceKey,
        `orders?id=eq.${encodeURIComponent(pedido.id)}&status=eq.${encodeURIComponent(String(pedido.status || ''))}&select=id`, {
          method: 'PATCH', headers: { Prefer: 'return=representation' }, body: JSON.stringify(mudanca.patch),
        }));
      return { gravou: Array.isArray(gravados) && gravados.length > 0, mudanca };
    };

    let pedido = await lerPedido();
    let mudanca = null as ReturnType<typeof mudancaDoAviso> | null;
    if (pedido) {
      let r = await aplicar(pedido);
      if (!r.gravou) {
        // O painel mudou o status entre a leitura e o PATCH: relê e recalcula uma vez; na segunda, 500 e o
        // Entregas repete o aviso.
        pedido = await lerPedido();
        if (pedido) {
          r = await aplicar(pedido);
          if (!r.gravou) throw new ErroDoBanco('o pedido mudou durante o aviso');
        }
      }
      mudanca = pedido ? r.mudanca : null;
    }

    if (pedido && mudanca) {
      const hoje = new Date(Date.now() - 3 * 3600 * 1000).toISOString().slice(0, 10);
      const enfileirar = async (kind: string, dedupeKey: string, payload: Record<string, unknown>) =>
        exigirOk(await supaFetch(supabaseUrl, serviceKey, 'message_outbox?on_conflict=dedupe_key', {
          method: 'POST',
          headers: { Prefer: 'return=minimal,resolution=ignore-duplicates' },
          body: JSON.stringify({ kind, company_id: companyId, target_date: hoje, dedupe_key: dedupeKey, payload }),
        }));
      if (mudanca.avisarCliente) {
        await enfileirar('entregas_tracking_msg', `entregastrack:${pedido.id}`, { orderId: pedido.id });
      }
      const entregasId = pedido.entregas_id || aviso.id;
      if (mudanca.cancelarNoEntregas && entregasId) {
        await enfileirar('entregas_cancel', `entregascancel:${pedido.id}`, { orderId: pedido.id, entregasId });
      }
    } else {
      console.warn('[entregas-webhook] pedido não encontrado', companyId, aviso.pedidoExternoId);
    }

    // O id do aviso fica registrado POR ÚLTIMO: se algo acima falhou (500), a repetição é reprocessada.
    await exigirOk(await supaFetch(supabaseUrl, serviceKey, 'entregas_webhook_events?on_conflict=id', {
      method: 'POST',
      headers: { Prefer: 'return=minimal,resolution=ignore-duplicates' },
      body: JSON.stringify({ id: aviso.idEntrega, company_id: companyId, order_id: pedido?.id ?? null, evento: aviso.evento }),
    }));
    return resposta(200, { ok: true });
  } catch (err: any) {
    console.error('[entregas-webhook] erro; o Entregas vai repetir:', String(err?.message || err).slice(0, 300));
    return resposta(500, { error: 'erro interno; tente de novo' });
  }
});
```

- [ ] **Step 2: Checar os tipos**

Run: `deno check supabase/functions/entregas-webhook/index.ts`
Expected: `Check supabase/functions/entregas-webhook/index.ts` sem erro.

- [ ] **Step 3: Conferir que o corpo cru é lido antes de qualquer parse**

Run: `grep -n "req.text()\|JSON.parse\|req.json()" supabase/functions/entregas-webhook/index.ts`
Expected: a linha do `await req.text()` vem antes da do `JSON.parse(corpoCru)`, e não há `req.json()`.

- [ ] **Step 4: Commit**

```bash
git add supabase/functions/entregas-webhook/index.ts
git commit -m "feat(entregas): webhook dos avisos com HMAC, dedupe e cerca otimista

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Util do painel (`utils/entregasStatus.ts`)

**Files:**
- Create: `utils/entregasStatus.ts`
- Modify: `scripts/entregas-smoke.mjs`

- [ ] **Step 1: Escrever o teste no smoke**

Em `scripts/entregas-smoke.mjs`, acrescente logo antes de `console.log('smoke:entregas OK');`:

```js
// 4. Painel: selo, provedor e a chave (espelho do servidor).
const { entregasStatusBadge, entregasDica, podeTentarDeNovo, provedorAtivo, textoDaTroca, chaveDeIntegracaoValida } =
  await import('../utils/entregasStatus.ts');
const { orderShortCode } = await import('../utils/orderCode.ts');
for (const s of ENTREGAS_STATUS) assert.ok(entregasStatusBadge(s), `status sem selo no painel: ${s}`);
assert.equal(entregasStatusBadge('procurando').label, 'Entregas · procurando motoboy');
assert.equal(entregasStatusBadge('aceito', 'João').label, 'Entregas · com João');
assert.equal(entregasStatusBadge('aceito', '').label, 'Entregas · motoboy aceitou');
assert.equal(entregasStatusBadge('a_caminho').label, 'Entregas · a caminho');
assert.equal(entregasStatusBadge('concluido').tone, 'green');
assert.equal(entregasStatusBadge('cancelado_pago').label, 'Entregas · cancelado (entrega cobrada)');
assert.equal(entregasStatusBadge('procurando', null, 'falhou').tone, 'red');
assert.equal(entregasStatusBadge(null), null);
assert.equal(entregasDica('sem_localizacao'), 'Endereço sem localização no mapa: a central do Entregas escolhe o motoboy.');
assert.equal(entregasDica('procurando'), null);
assert.equal(podeTentarDeNovo(null, 'erro'), true);
assert.equal(podeTentarDeNovo('order_abc', 'Cancelamento não chegou'), false);
assert.equal(podeTentarDeNovo(null, null), false);
assert.equal(provedorAtivo(false, false), 'nenhum');
assert.equal(provedorAtivo(true, false), 'foody');
assert.equal(provedorAtivo(false, true), 'entregas');
assert.equal(provedorAtivo(true, true), 'entregas');
assert.equal(textoDaTroca('foody', 'entregas'),
  'Trocar para Entregas RestaurantePro desliga Foody. Os pedidos que já estão com Foody continuam com o motoboy de lá.');
assert.ok(textoDaTroca('entregas', 'nenhum').endsWith('mas o kanban deixa de andar sozinho para eles.'));
for (const c of ['ent_live_' + 'a'.repeat(40), 'ent_live_' + 'a'.repeat(39), ' ent_live_' + 'Z9'.repeat(20), 'ent_test_' + 'a'.repeat(40), '']) {
  assert.equal(chaveDeIntegracaoValida(c), chaveValida(c), `chave divergente painel x servidor: ${c}`);
}
// 4b. O número do pedido no Entregas é o "#1234" da comanda (utils/orderCode.ts).
for (const id of ['ord-3f2a8c1e-9b7d-4e5f-a1b2-c3d4e5f6a7b8', 'ord-506c9519-cab2-4c96-9321-a9756324da39']) {
  const r = corpoDoPedido({ id, delivery_address: 'Rua A, 1, Ribeirão Preto', total: 10, payment_method: 'pix' }, null, null);
  assert.ok(r.ok);
  assert.equal(r.corpo.numero, orderShortCode(id));
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `npm run -s smoke:entregas`
Expected: FAIL com `ERR_MODULE_NOT_FOUND` para `utils/entregasStatus.ts`.

- [ ] **Step 3: Implementar**

`utils/entregasStatus.ts`:

```ts
// Selo do Entregas RestaurantePro no card do pedido e a escolha "Entregas por" (Configurações de Delivery).
// Só APRESENTAÇÃO: quem decide a situação é o servidor (supabase/functions/_shared/entregas.ts, ENTREGAS_STATUS).
// Smoke: npm run smoke:entregas

export interface EntregasBadge {
  label: string;
  tone: 'sky' | 'amber' | 'green' | 'red';
}

export function entregasStatusBadge(
  status?: string | null,
  motoboy?: string | null,
  erro?: string | null,
): EntregasBadge | null {
  if (erro) return { label: 'Entregas · problema no envio', tone: 'red' };
  switch (status) {
    case 'procurando':
      return { label: 'Entregas · procurando motoboy', tone: 'amber' };
    case 'sem_localizacao':
      return { label: 'Entregas · aguardando a central', tone: 'amber' };
    case 'aceito': {
      const nome = String(motoboy || '').trim();
      return { label: nome ? `Entregas · com ${nome}` : 'Entregas · motoboy aceitou', tone: 'sky' };
    }
    case 'a_caminho':
      return { label: 'Entregas · a caminho', tone: 'sky' };
    case 'concluido':
      return { label: 'Entregas · entregue', tone: 'green' };
    case 'cancelado':
      return { label: 'Entregas · cancelado', tone: 'red' };
    case 'cancelado_pago':
      return { label: 'Entregas · cancelado (entrega cobrada)', tone: 'red' };
    default:
      return null;
  }
}

/** Linha de apoio embaixo do selo (só quando há o que explicar). */
export function entregasDica(status?: string | null): string | null {
  return status === 'sem_localizacao'
    ? 'Endereço sem localização no mapa: a central do Entregas escolhe o motoboy.'
    : null;
}

/** "Tentar de novo" só faz sentido quando o pedido não chegou ao Entregas (sem id) e há erro. */
export function podeTentarDeNovo(entregasId?: string | null, erro?: string | null): boolean {
  return !entregasId && !!erro;
}

export type ProvedorDeEntrega = 'nenhum' | 'foody' | 'entregas';

export const PROVEDOR_LABELS: Record<ProvedorDeEntrega, string> = {
  nenhum: 'Nenhum',
  foody: 'Foody',
  entregas: 'Entregas RestaurantePro',
};

/** Qual provedor está ligado. Os dois ligados não deveria acontecer (trigger de exclusividade da migration
 * 2026101201); se acontecer, vale o Entregas, que é o que o trigger de despacho dele aceitaria. */
export function provedorAtivo(foodyLigada: boolean, entregasLigada: boolean): ProvedorDeEntrega {
  if (entregasLigada) return 'entregas';
  if (foodyLigada) return 'foody';
  return 'nenhum';
}

/** Texto da confirmação ao trocar de provedor. */
export function textoDaTroca(de: ProvedorDeEntrega, para: ProvedorDeEntrega): string {
  const alvo = para === 'nenhum' ? 'Nenhum (sem chamar motoboy automaticamente)' : PROVEDOR_LABELS[para];
  const aviso = de === 'entregas' ? ', mas o kanban deixa de andar sozinho para eles' : '';
  return `Trocar para ${alvo} desliga ${PROVEDOR_LABELS[de]}. Os pedidos que já estão com ${PROVEDOR_LABELS[de]} continuam com o motoboy de lá${aviso}.`;
}

/** ESPELHO de chaveValida (supabase/functions/_shared/entregas.ts): o servidor confere de novo. */
export function chaveDeIntegracaoValida(chave: string | null | undefined): boolean {
  return /^ent_live_[A-Za-z0-9]{40}$/.test(String(chave || '').trim());
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `npm run -s smoke:entregas && npm run -s smoke:foody`
Expected: `smoke:entregas OK` e `smoke:foody OK`.

- [ ] **Step 5: Commit**

```bash
git add utils/entregasStatus.ts scripts/entregas-smoke.mjs
git commit -m "feat(entregas): selo do card e escolha do provedor (regras do painel)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: Colunas do Entregas no tipo `Order` e na carga dos pedidos

**Files:**
- Modify: `types/products.ts` (interface `Order`)
- Modify: `hooks/useDataSync.ts` (`ORDER_COLUMNS`)
- Modify: `hooks/utils.ts` (`normalizeOrderRow`)

O Realtime de `orders` entrega a linha inteira e passa pelo mesmo `normalizeOrderRow`; o `upsertOrder` do painel **não** grava colunas `entregas_*` (de propósito: são do servidor). **Este front só pode ir ao ar depois da migration 2026101201** (o `select` de coluna inexistente derruba a carga dos pedidos).

- [ ] **Step 1: Tipo**

Em `types/products.ts`, troque:

```ts
    foodyError?: string;
}
```

por:

```ts
    foodyError?: string;
    /** Integração Entregas RestaurantePro (preenchidos pelo servidor; o painel só lê). */
    entregasId?: string;
    entregasStatus?: string;
    entregasTrackingCode?: string;
    entregasTrackingUrl?: string;
    entregasDriverName?: string;
    entregasError?: string;
}
```

- [ ] **Step 2: Colunas carregadas**

Em `hooks/useDataSync.ts`, na constante `ORDER_COLUMNS`, troque o fim `refund_status,refund_error';` por:

```ts
refund_status,refund_error,entregas_id,entregas_status,entregas_tracking_code,entregas_tracking_url,entregas_driver_name,entregas_error';
```

- [ ] **Step 3: Normalização**

Em `hooks/utils.ts`, no fim do `normalizeOrderRow`, troque:

```ts
    foodyError: typeof row?.foody_error === 'string' && row.foody_error ? row.foody_error : undefined,
  };
```

por:

```ts
    foodyError: typeof row?.foody_error === 'string' && row.foody_error ? row.foody_error : undefined,
    entregasId: typeof row?.entregas_id === 'string' && row.entregas_id ? row.entregas_id : undefined,
    entregasStatus: typeof row?.entregas_status === 'string' && row.entregas_status ? row.entregas_status : undefined,
    entregasTrackingCode: typeof row?.entregas_tracking_code === 'string' && row.entregas_tracking_code ? row.entregas_tracking_code : undefined,
    entregasTrackingUrl: typeof row?.entregas_tracking_url === 'string' && row.entregas_tracking_url ? row.entregas_tracking_url : undefined,
    entregasDriverName: typeof row?.entregas_driver_name === 'string' && row.entregas_driver_name ? row.entregas_driver_name : undefined,
    entregasError: typeof row?.entregas_error === 'string' && row.entregas_error ? row.entregas_error : undefined,
  };
```

- [ ] **Step 4: Conferir**

```bash
grep -c "entregas_error" hooks/useDataSync.ts hooks/utils.ts
npx tsc --noEmit -p tsconfig.json 2>&1 | grep "error TS" | grep -E "entregas|Entregas|OrderCard|DeliverySettingsContainer|DeliveryProviderSection|FoodySettingsEditor|types/products|hooks/utils|useDataSync"
```

Expected: `hooks/useDataSync.ts:1` e `hooks/utils.ts:1`; o segundo comando não imprime nada.

- [ ] **Step 5: Commit**

```bash
git add types/products.ts hooks/useDataSync.ts hooks/utils.ts
git commit -m "feat(entregas): colunas do Entregas no pedido do painel

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 13: Hook `useEntregasIntegration` e o editor da chave

**Files:**
- Create: `hooks/useEntregasIntegration.ts`
- Create: `components/EntregasSettingsEditor.tsx`

- [ ] **Step 1: Hook**

`hooks/useEntregasIntegration.ts`:

```ts
// Estado da integração Entregas RestaurantePro. Usado localmente pela seção "Entregas por" nas Configurações de
// Delivery, FORA do useAppState (padrão useFoodyIntegration): nada disso entra no bootstrap nem no Realtime.
// A chave ent_live_ vai ao servidor só no connect e nunca volta (a edge entregas-delivery devolve campos inócuos).
import { useCallback, useEffect, useState } from 'react';
import { supabaseClient, isSupabaseConfigured } from '../supabaseClient';

export interface EntregasIntegrationStatus {
  enabled: boolean;
  hasToken: boolean;
  lojaNome: string | null;
  connectedAt: string | null;
  lastError: string | null;
}

/** ESPELHO de useFoodyIntegration: functions.invoke embrulha não-2xx em FunctionsHttpError; o corpo está em
 * error.context. */
async function unwrapError(error: any, fallback: string): Promise<string> {
  if (error?.context && typeof error.context.json === 'function') {
    try {
      const body = await error.context.json();
      if (body?.error) return body.error;
    } catch {}
  }
  return error?.message || fallback;
}

async function invokeEntregas(action: string, payload: Record<string, unknown> = {}): Promise<any> {
  const { data, error } = await supabaseClient.functions.invoke('entregas-delivery', {
    body: { action, ...payload },
  });
  if (error) throw new Error(await unwrapError(error, 'Falha ao falar com a integração Entregas'));
  return data;
}

/** "Tentar de novo" do card: reenfileira o envio do pedido ao Entregas (não é envio manual). */
export async function retryEntregasDispatch(companyId: string, orderId: string): Promise<void> {
  await invokeEntregas('retry', { companyId, orderId });
}

export function useEntregasIntegration(companyId: string) {
  const enabled = isSupabaseConfigured && !!companyId && companyId !== 'all';
  const [integration, setIntegration] = useState<EntregasIntegrationStatus | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [isSaving, setIsSaving] = useState(false);

  const applyStatus = (data: any) => {
    const i = data?.integration;
    setIntegration(i ? {
      enabled: !!i.enabled,
      hasToken: !!i.hasToken,
      lojaNome: i.lojaNome ?? null,
      connectedAt: i.connectedAt ?? null,
      lastError: i.lastError ?? null,
    } : null);
  };

  const load = useCallback(async () => {
    if (!enabled) { setIntegration(null); return; }
    setIsLoading(true);
    try {
      applyStatus(await invokeEntregas('status', { companyId }));
    } catch {
      // Sem toast no load (padrão da casa): erro transitório do 1º load viraria linha nos Logs de Erros.
    } finally {
      setIsLoading(false);
    }
  }, [enabled, companyId]);

  useEffect(() => { void load(); }, [load]);

  const connect = useCallback(async (chave: string) => {
    setIsSaving(true);
    try {
      applyStatus(await invokeEntregas('connect', { companyId, chave }));
    } finally {
      setIsSaving(false);
    }
  }, [companyId]);

  const disconnect = useCallback(async () => {
    setIsSaving(true);
    try {
      applyStatus(await invokeEntregas('disconnect', { companyId }));
    } finally {
      setIsSaving(false);
    }
  }, [companyId]);

  return { integration, isLoading, isSaving, reload: load, connect, disconnect };
}

export type EntregasIntegrationApi = ReturnType<typeof useEntregasIntegration>;
```

- [ ] **Step 2: Editor**

`components/EntregasSettingsEditor.tsx`:

```tsx
import React, { useState } from 'react';
import { AppNotification, AppNotificationType } from '../types';
import type { EntregasIntegrationApi } from '../hooks/useEntregasIntegration';
import { chaveDeIntegracaoValida } from '../utils/entregasStatus';
import { LoaderCircle } from 'lucide-react';

interface EntregasSettingsEditorProps {
    canEdit: boolean;
    addNotification: (n: Omit<AppNotification, 'id' | 'timestamp' | 'isRead'>) => void;
    /** O hook vem da seção "Entregas por" (uma instância só, para o seletor e o editor verem o mesmo estado). */
    entregas: EntregasIntegrationApi;
    onChanged?: () => void;
}

const EntregasSettingsEditor: React.FC<EntregasSettingsEditorProps> = ({ canEdit, addNotification, entregas, onChanged }) => {
    const { integration, isLoading, isSaving, connect, disconnect } = entregas;
    const [chave, setChave] = useState('');
    const [confirmingDisconnect, setConfirmingDisconnect] = useState(false);
    const chaveOk = chaveDeIntegracaoValida(chave);

    const handleConnect = async () => {
        if (!chaveOk) return;
        try {
            await connect(chave.trim());
            setChave('');
            addNotification({ type: AppNotificationType.SUCCESS, title: 'Entregas conectado', message: 'Os pedidos de delivery vão ao Entregas quando entram em "Preparando".' });
            onChanged?.();
        } catch (e: any) {
            addNotification({ type: AppNotificationType.ERROR, title: 'Erro ao Conectar', message: e?.message || 'Não foi possível conectar ao Entregas.' });
        }
    };

    const handleDisconnect = async () => {
        try {
            await disconnect();
            setConfirmingDisconnect(false);
            addNotification({ type: AppNotificationType.SUCCESS, title: 'Entregas desconectado', message: 'Os pedidos novos não vão mais ao Entregas. Para voltar, cole uma chave de novo.' });
            onChanged?.();
        } catch (e: any) {
            addNotification({ type: AppNotificationType.ERROR, title: 'Erro', message: e?.message || 'Não foi possível desconectar.' });
        }
    };

    if (isLoading && !integration) {
        return (
            <div className="px-6 pb-6">
                <p className="text-sm text-slate-500 dark:text-slate-400 flex items-center gap-2"><LoaderCircle className="w-4 h-4 animate-spin" /> Carregando...</p>
            </div>
        );
    }

    return (
        <div className="px-6 pb-6 space-y-4">
            {integration?.enabled ? (
                <div className="p-3 rounded-lg bg-slate-100 dark:bg-slate-900/50">
                    {confirmingDisconnect ? (
                        <div className="space-y-2">
                            <p className="text-sm text-slate-700 dark:text-slate-200">
                                Desconectar desliga o envio automático. Os pedidos que já estão no Entregas continuam com o motoboy, mas o kanban deixa de andar sozinho para eles.
                            </p>
                            <div className="flex gap-2 justify-end">
                                <button
                                    type="button"
                                    onClick={() => setConfirmingDisconnect(false)}
                                    className="px-3 py-2 text-sm font-medium text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="button"
                                    onClick={handleDisconnect}
                                    disabled={isSaving}
                                    className="inline-flex items-center gap-1.5 bg-red-600 text-white rounded-lg px-4 py-2 text-sm font-medium hover:bg-red-500 transition-colors disabled:opacity-50"
                                >
                                    {isSaving && <LoaderCircle className="w-4 h-4 animate-spin" />} Desconectar
                                </button>
                            </div>
                        </div>
                    ) : (
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    Conectado{integration.lojaNome ? ` à loja ${integration.lojaNome}` : ''} no Entregas
                                </p>
                                <p className="text-xs text-slate-500 dark:text-slate-400">
                                    Chave: ent_live_••••{integration.connectedAt ? ` · desde ${new Date(integration.connectedAt).toLocaleDateString('pt-BR')}` : ''}
                                </p>
                            </div>
                            {canEdit && (
                                <button type="button" onClick={() => setConfirmingDisconnect(true)} className="text-xs text-red-500 hover:text-red-400 underline flex-shrink-0">
                                    Desconectar
                                </button>
                            )}
                        </div>
                    )}
                </div>
            ) : (
                <div className="space-y-2">
                    <p className="text-sm text-slate-600 dark:text-slate-300">
                        Peça à central do Entregas a chave desta loja (no Entregas: Recursos → Lojas → Integração → Gerar chave) e cole abaixo. A chave começa com <code>ent_live_</code> e aparece uma vez só.
                    </p>
                    <div className="flex gap-2">
                        <input
                            type="password"
                            value={chave}
                            onChange={e => setChave(e.target.value)}
                            placeholder="ent_live_..."
                            autoComplete="off"
                            disabled={!canEdit}
                            className="flex-grow p-2 rounded-md bg-white border border-slate-300 text-slate-800 dark:bg-slate-700 dark:border-slate-600 dark:text-white text-sm"
                        />
                        <button
                            type="button"
                            onClick={handleConnect}
                            disabled={!canEdit || isSaving || !chaveOk}
                            className="px-4 py-2 bg-sky-600 hover:bg-sky-500 text-white rounded-md text-sm font-semibold disabled:bg-slate-400 dark:disabled:bg-slate-600 flex items-center gap-2"
                        >
                            {isSaving && <LoaderCircle className="w-4 h-4 animate-spin" />} Conectar
                        </button>
                    </div>
                    {chave.trim() !== '' && !chaveOk && (
                        <p className="text-xs text-amber-700 dark:text-amber-300">A chave tem 49 caracteres e começa com ent_live_.</p>
                    )}
                </div>
            )}

            <p className="text-xs text-slate-500 dark:text-slate-400">
                O pedido de delivery vai ao Entregas quando entra em "Preparando" (impressão da comanda ou kanban). O cliente recebe pelo WhatsApp o link de rastreio quando o motoboy sai com o pedido.
            </p>

            {integration?.lastError && (
                <p className="text-sm text-red-600 dark:text-red-400">Último erro: {integration.lastError}</p>
            )}
        </div>
    );
};

export default EntregasSettingsEditor;
```

- [ ] **Step 3: Checar os tipos**

Run: `npx tsc --noEmit -p tsconfig.json 2>&1 | grep "error TS" | grep -E "entregas|Entregas|OrderCard|DeliverySettingsContainer|DeliveryProviderSection|FoodySettingsEditor|types/products|hooks/utils|useDataSync"`
Expected: nada impresso.

- [ ] **Step 4: Commit**

```bash
git add hooks/useEntregasIntegration.ts components/EntregasSettingsEditor.tsx
git commit -m "feat(entregas): hook da integração e editor da chave da loja

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 14: Seção "Entregas por" nas configurações de delivery

**Files:**
- Create: `components/DeliveryProviderSection.tsx`
- Modify: `components/FoodySettingsEditor.tsx` (prop `onChanged`)
- Modify: `components/DeliverySettingsContainer.tsx` (troca o bloco da Foody pela seção)

- [ ] **Step 1: `onChanged` no editor da Foody**

Em `components/FoodySettingsEditor.tsx`:

1. Na interface, troque

```ts
    statusBadge?: React.ReactNode;
}
```

por

```ts
    statusBadge?: React.ReactNode;
    /** Avisado depois de conectar, desconectar ou mudar um interruptor (a seção "Entregas por" relê o estado). */
    onChanged?: () => void;
}
```

2. Na desestruturação das props, troque

```ts
    statusBadge,
}) => {
```

por

```ts
    statusBadge,
    onChanged,
}) => {
```

3. No `handleConnect`, troque

```ts
            setTokenInput('');
            addNotification
```

por

```ts
            setTokenInput('');
            onChanged?.();
            addNotification
```

4. No `handleDisconnect`, troque

```ts
            setConfirmingDisconnect(false);
            addNotification
```

por

```ts
            setConfirmingDisconnect(false);
            onChanged?.();
            addNotification
```

5. No `handleToggle`, troque

```ts
            await updateSettings(patch);
        } catch
```

por

```ts
            await updateSettings(patch);
            onChanged?.();
        } catch
```

- [ ] **Step 2: A seção**

`components/DeliveryProviderSection.tsx`:

```tsx
import React, { useState } from 'react';
import { AppNotification, AppNotificationType } from '../types';
import { Bike, ChevronDown, LoaderCircle } from 'lucide-react';
import FoodySettingsEditor from './FoodySettingsEditor';
import EntregasSettingsEditor from './EntregasSettingsEditor';
import SettingsStatusBadge from './ui/SettingsStatusBadge';
import { useFoodyIntegration } from '../hooks/useFoodyIntegration';
import { useEntregasIntegration } from '../hooks/useEntregasIntegration';
import { PROVEDOR_LABELS, provedorAtivo, textoDaTroca, type ProvedorDeEntrega } from '../utils/entregasStatus';

interface DeliveryProviderSectionProps {
    companyId: string;
    canEdit: boolean;
    addNotification: (n: Omit<AppNotification, 'id' | 'timestamp' | 'isRead'>) => void;
    isOpen: boolean;
    onToggle: () => void;
}

const OPCOES: ProvedorDeEntrega[] = ['nenhum', 'foody', 'entregas'];

// "Entregas por": um provedor de motoboy por empresa (Nenhum, Foody ou Entregas RestaurantePro). Quem garante a
// exclusividade é o banco (triggers de 2026101201: ligar um desliga o outro); esta seção só pede confirmação e
// desliga o atual antes de mostrar o editor do novo.
const DeliveryProviderSection: React.FC<DeliveryProviderSectionProps> = ({ companyId, canEdit, addNotification, isOpen, onToggle }) => {
    const foody = useFoodyIntegration(companyId);
    const entregas = useEntregasIntegration(companyId);
    const ativo = provedorAtivo(foody.integration?.enabled === true, entregas.integration?.enabled === true);
    const [escolha, setEscolha] = useState<ProvedorDeEntrega | null>(null);
    const [pendente, setPendente] = useState<ProvedorDeEntrega | null>(null);
    const [trocando, setTrocando] = useState(false);
    const visivel = escolha ?? ativo;
    const carregando = foody.isLoading || entregas.isLoading;

    const recarregar = () => {
        void foody.reload();
        void entregas.reload();
    };

    const escolher = (para: ProvedorDeEntrega) => {
        if (para === visivel) return;
        if (ativo !== 'nenhum' && para !== ativo) {
            setPendente(para);
            return;
        }
        setPendente(null);
        setEscolha(para);
    };

    const confirmarTroca = async () => {
        if (!pendente) return;
        setTrocando(true);
        try {
            if (ativo === 'foody') await foody.updateSettings({ enabled: false });
            if (ativo === 'entregas') await entregas.disconnect();
            setEscolha(pendente);
            setPendente(null);
            recarregar();
        } catch (e: any) {
            addNotification({ type: AppNotificationType.ERROR, title: 'Erro ao trocar', message: e?.message || 'Não foi possível desligar o provedor atual.' });
        } finally {
            setTrocando(false);
        }
    };

    return (
        <div className="border-t border-slate-200 dark:border-slate-700/50">
            <button
                type="button"
                onClick={onToggle}
                className="w-full p-6 flex items-center justify-between gap-3 text-left"
                aria-expanded={isOpen}
            >
                <div className="flex items-center gap-2">
                    <Bike className="w-5 h-5 text-slate-500 dark:text-slate-400" />
                    <div>
                        <h3 className="text-lg font-semibold text-slate-900 dark:text-slate-100">Entregas por</h3>
                        <p className="text-sm text-slate-500 dark:text-slate-400">Quem chama o motoboy quando o pedido entra em "Preparando".</p>
                    </div>
                </div>
                <div className="flex items-center gap-2 flex-shrink-0">
                    <SettingsStatusBadge status={ativo === 'nenhum' ? 'optional' : 'ok'} label={PROVEDOR_LABELS[ativo]} />
                    <ChevronDown className={`w-5 h-5 text-slate-400 transition-transform ${isOpen ? 'rotate-180' : ''}`} />
                </div>
            </button>

            {isOpen && (
                <div className="pb-2">
                    <div className="px-6 pb-4 space-y-3">
                        <div role="radiogroup" aria-label="Entregas por" className="flex flex-wrap gap-2">
                            {OPCOES.map(op => (
                                <button
                                    key={op}
                                    type="button"
                                    role="radio"
                                    aria-checked={visivel === op}
                                    onClick={() => escolher(op)}
                                    disabled={!canEdit || carregando || trocando}
                                    className={`px-3 py-2 rounded-lg border text-sm font-semibold transition-colors disabled:opacity-50 ${visivel === op
                                        ? 'bg-sky-600 border-sky-600 text-white'
                                        : 'bg-white border-slate-300 text-slate-700 hover:border-sky-500 dark:bg-slate-800 dark:border-slate-600 dark:text-slate-200'}`}
                                >
                                    {PROVEDOR_LABELS[op]}
                                </button>
                            ))}
                            {carregando && <LoaderCircle className="w-4 h-4 animate-spin text-slate-400 self-center" />}
                        </div>

                        {pendente && (
                            <div className="p-3 rounded-lg bg-amber-50 border border-amber-200 dark:bg-amber-500/10 dark:border-amber-500/30 space-y-2">
                                <p className="text-sm text-slate-700 dark:text-slate-200">{textoDaTroca(ativo, pendente)}</p>
                                <div className="flex gap-2 justify-end">
                                    <button
                                        type="button"
                                        onClick={() => setPendente(null)}
                                        className="px-3 py-2 text-sm font-medium text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white"
                                    >
                                        Cancelar
                                    </button>
                                    <button
                                        type="button"
                                        onClick={confirmarTroca}
                                        disabled={trocando}
                                        className="inline-flex items-center gap-1.5 bg-sky-600 text-white rounded-lg px-4 py-2 text-sm font-medium hover:bg-sky-500 transition-colors disabled:opacity-50"
                                    >
                                        {trocando && <LoaderCircle className="w-4 h-4 animate-spin" />} Trocar
                                    </button>
                                </div>
                            </div>
                        )}

                        {visivel === 'nenhum' && !pendente && (
                            <p className="text-sm text-slate-500 dark:text-slate-400">Nenhum provedor: os pedidos não chamam motoboy automaticamente.</p>
                        )}
                    </div>

                    {visivel === 'foody' && (
                        <FoodySettingsEditor
                            companyId={companyId}
                            canEdit={canEdit}
                            addNotification={addNotification}
                            onChanged={recarregar}
                        />
                    )}
                    {visivel === 'entregas' && (
                        <EntregasSettingsEditor
                            canEdit={canEdit}
                            addNotification={addNotification}
                            entregas={entregas}
                            onChanged={recarregar}
                        />
                    )}
                </div>
            )}
        </div>
    );
};

export default DeliveryProviderSection;
```

- [ ] **Step 3: Montar a seção no lugar do bloco da Foody**

Em `components/DeliverySettingsContainer.tsx`:

1. troque `import FoodySettingsEditor from './FoodySettingsEditor';` por `import DeliveryProviderSection from './DeliveryProviderSection';`;
2. troque `    const [isFoodyExpanded, setIsFoodyExpanded] = useState(false);` por `    const [isProviderExpanded, setIsProviderExpanded] = useState(false);`;
3. troque o bloco

```tsx
            <FoodySettingsEditor
                companyId={company.id}
                canEdit={canEdit}
                addNotification={addNotification}
                collapsible
                isOpen={isFoodyExpanded}
                onToggle={() => setIsFoodyExpanded(v => !v)}
                statusBadge={<SettingsStatusBadge status="optional" />}
            />
```

por

```tsx
            <DeliveryProviderSection
                companyId={company.id}
                canEdit={canEdit}
                addNotification={addNotification}
                isOpen={isProviderExpanded}
                onToggle={() => setIsProviderExpanded(v => !v)}
            />
```

O `SettingsStatusBadge` continua importado (as outras seções usam). O app nativo (`apps/mobile/.../DeliverySections.tsx`) monta o `FoodySettingsEditor` direto e não muda: a prop nova é opcional.

- [ ] **Step 4: Checar os tipos**

Run: `npx tsc --noEmit -p tsconfig.json 2>&1 | grep "error TS" | grep -E "entregas|Entregas|OrderCard|DeliverySettingsContainer|DeliveryProviderSection|FoodySettingsEditor|types/products|hooks/utils|useDataSync"`
Expected: nada impresso.

- [ ] **Step 5: Commit**

```bash
git add components/DeliveryProviderSection.tsx components/FoodySettingsEditor.tsx components/DeliverySettingsContainer.tsx
git commit -m "feat(entregas): seção Entregas por (Nenhum, Foody ou Entregas RestaurantePro)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 15: Selo do Entregas e "Tentar de novo" no card do pedido

**Files:**
- Modify: `components/OrderCard.tsx`

- [ ] **Step 1: Imports**

Em `components/OrderCard.tsx`, troque

```tsx
import { foodyStatusBadge, PAYMENT_METHOD_LABELS } from '../utils/foodyStatus';
```

por

```tsx
import { foodyStatusBadge, PAYMENT_METHOD_LABELS } from '../utils/foodyStatus';
import { entregasDica, entregasStatusBadge, podeTentarDeNovo } from '../utils/entregasStatus';
import { retryEntregasDispatch } from '../hooks/useEntregasIntegration';
```

- [ ] **Step 2: Selo e nota**

Troque

```tsx
    const foodyBadge = !isPickup ? foodyStatusBadge(order.foodyStatus, order.foodyError) : null;
```

por

```tsx
    const foodyBadge = !isPickup ? foodyStatusBadge(order.foodyStatus, order.foodyError) : null;
    const entregasBadge = !isPickup ? entregasStatusBadge(order.entregasStatus, order.entregasDriverName, order.entregasError) : null;
    const entregasNota = !isPickup && !order.entregasError ? entregasDica(order.entregasStatus) : null;
```

- [ ] **Step 3: Estado do "Tentar de novo"**

Troque

```tsx
    const formatPrice = (value: number) => {
```

por

```tsx
    // "Tentar de novo" (Entregas): reenfileira o envio; o resultado chega pelo Realtime do pedido.
    const [reenviando, setReenviando] = useState(false);
    const [reenvioMsg, setReenvioMsg] = useState<string | null>(null);
    const tentarDeNovo = async () => {
        setReenviando(true);
        setReenvioMsg(null);
        try {
            await retryEntregasDispatch(order.companyId, order.id);
            setReenvioMsg('Reenviado: o Entregas recebe em até 1 minuto.');
        } catch (e: any) {
            setReenvioMsg(e?.message || 'Não foi possível reenviar.');
        } finally {
            setReenviando(false);
        }
    };

    const formatPrice = (value: number) => {
```

- [ ] **Step 4: O bloco no cabeçalho do card**

Troque

```tsx
                        <ExternalLink className="w-3 h-3" /> Rastrear entrega
                    </a>
                )}
                {order.paymentMethod && (
```

por

```tsx
                        <ExternalLink className="w-3 h-3" /> Rastrear entrega
                    </a>
                )}
                {entregasBadge && (
                    <span className={`mt-1.5 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold ${badgeTone[entregasBadge.tone]}`}>
                        🛵 {entregasBadge.label}
                    </span>
                )}
                {entregasNota && (
                    <p className="mt-1 text-xs text-amber-700 dark:text-amber-300">{entregasNota}</p>
                )}
                {order.entregasError && (
                    <div className="mt-1 space-y-1">
                        <p className="text-xs text-red-600 dark:text-red-400 break-words">{order.entregasError}</p>
                        {canDrag && podeTentarDeNovo(order.entregasId, order.entregasError) && (
                            <button
                                type="button"
                                draggable={false}
                                onClick={e => { e.stopPropagation(); void tentarDeNovo(); }}
                                disabled={reenviando}
                                className="inline-flex items-center px-2 py-1 rounded-md text-xs font-semibold bg-sky-600 text-white hover:bg-sky-500 disabled:opacity-50"
                            >
                                {reenviando ? 'Reenviando...' : 'Tentar de novo'}
                            </button>
                        )}
                        {reenvioMsg && <p className="text-xs text-slate-500 dark:text-slate-400">{reenvioMsg}</p>}
                    </div>
                )}
                {order.entregasTrackingUrl && (
                    <a
                        href={order.entregasTrackingUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        // Âncora arrastável roubaria o dragstart do card (mesmo cuidado do link da Foody).
                        draggable={false}
                        onClick={e => e.stopPropagation()}
                        className="mt-1 flex items-center gap-1 text-xs font-semibold text-sky-600 dark:text-sky-400 underline w-fit"
                    >
                        <ExternalLink className="w-3 h-3" /> Rastrear entrega{order.entregasTrackingCode ? ` · ${order.entregasTrackingCode}` : ''}
                    </a>
                )}
                {order.paymentMethod && (
```

- [ ] **Step 5: Checar os tipos, o build e os smokes**

```bash
npx tsc --noEmit -p tsconfig.json 2>&1 | grep "error TS" | grep -E "entregas|Entregas|OrderCard|DeliverySettingsContainer|DeliveryProviderSection|FoodySettingsEditor|types/products|hooks/utils|useDataSync"
npm run build 2>&1 | tail -3
npm run -s smoke:entregas && npm run -s smoke:foody && npm run -s smoke:serviceauth
deno test supabase/functions/_shared/entregas_test.ts supabase/functions/_shared/googleGeocode_test.ts
```

Expected: o primeiro não imprime nada; o build termina com `✓ built in …`; os três smokes `OK`; Deno `ok | 27 passed | 0 failed`.

- [ ] **Step 6: Commit**

```bash
git add components/OrderCard.tsx
git commit -m "feat(entregas): selo do Entregas, link de rastreio e Tentar de novo no card

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 16: Documentação (`CLAUDE.md` e a regra de delivery)

**Files:**
- Modify: `CLAUDE.md`
- Modify: `.claude/rules/delivery-cardapio.md`

- [ ] **Step 1: Linha na tabela de integrações do `CLAUDE.md`**

Em `CLAUDE.md`, troque

```md
| Google Maps | Geocoding, Distance Matrix |
```

por

```md
| Google Maps | Geocoding, Distance Matrix |
| Entregas RestaurantePro (`entregas-api.restaurantepro.com.br`) | Despacho de motoboys (alternativa à Foody) — seção "Integração Entregas RestaurantePro" |
```

- [ ] **Step 2: Seção curta antes de `## Development`**

Em `CLAUDE.md`, troque

```md
## Development
```

por

```md
## Integração Entregas RestaurantePro (2026-10-12)

Pedido de delivery em "Preparando" vai sozinho ao Entregas (repo `Delivery`, API `v1/entregas/integracao/*`), no molde da Foody. Plano: repo `Delivery`, `docs/superpowers/plans/2026-10-12-integracao-restaurantepro-restaurantepro.md`.
- **Um provedor por empresa** (Configurações de Delivery → "Entregas por": Nenhum, Foody, Entregas). Quem garante é o banco: ligar um desliga o outro por trigger nas duas tabelas de integração (`2026101201`), e cada trigger de despacho confere que o outro está desligado.
- **Banco:** `entregas_integrations` (service_role-only; chave `ent_live_` e segredo do webhook cifrados no Vault, `entregas_encryption_key`), `entregas_webhook_events` (dedupe do `X-Entregas-Id`, limpeza diária), colunas `orders.entregas_*` (`entregas_status` no vocabulário nosso: `procurando`, `sem_localizacao`, `aceito`, `a_caminho`, `concluido`, `cancelado`, `cancelado_pago`; ESPELHO de `ENTREGAS_STATUS` em `_shared/entregas.ts`, `npm run smoke:entregas` confere). Kinds `entregas_dispatch`, `entregas_cancel`, `entregas_tracking_msg` na `message_outbox`.
- **Edges:** `entregas-delivery` (painel: status, connect, disconnect, retry), `entregas-tick` (cron por minuto: despacho, cancelamento, WhatsApp "A caminho" com o link de rastreio) e `entregas-webhook` (pública: HMAC do corpo cru; token desconhecido = 410, assinatura errada = 401, erro de banco = 500). Regras puras em `_shared/entregas.ts` (`deno test supabase/functions/_shared/entregas_test.ts`).
- **FOOTGUNS:** (1) o aviso genérico de status (`orders_status_notify_enqueue`) não enfileira o `out_for_delivery` de pedido com `foody_uid` **nem** com `entregas_id` — quem avisa é a mensagem com o link do provedor; (2) o Desconectar rotaciona o token do webhook: os pedidos já enviados deixam de atualizar o kanban sozinhos (a chave cifrada fica, para os cancelamentos chegarem); (3) pedido que já estava em "Preparando" ao ligar não vai: arrastar para "Pendente" e de volta; (4) mudou o CHECK da `message_outbox`? Reescreva a lista INTEIRA (Foody, `order_status`, `review_request` e os três do Entregas).
- **Desligar em emergência:** "Entregas por" → Nenhum (ou, no Entregas, `ENTREGAS_INTEGRACAO=` vazio: o RestaurantePro passa a gravar o 409 em `entregas_error`).

## Development
```

- [ ] **Step 3: Footgun do aviso de status na regra de delivery**

Em `.claude/rules/delivery-cardapio.md`, troque

```md
(2) pedido com `foody_uid` NÃO enfileira o `out_for_delivery` (a `foody_tracking_msg` já manda "saiu para entrega" COM link) — `preparing`/`cancelled` continuam avisando;
```

por

```md
(2) pedido com `foody_uid` NÃO enfileira o `out_for_delivery` (a `foody_tracking_msg` já manda "saiu para entrega" COM link), nem pedido com `entregas_id` (a `entregas_tracking_msg` manda o link do Entregas; migration `2026101202`) — `preparing`/`cancelled` continuam avisando;
```

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md .claude/rules/delivery-cardapio.md
git commit -m "docs(entregas): integração Entregas RestaurantePro no CLAUDE.md e na regra de delivery

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 17: Implantação e teste real (checklist)

> Atualizado em 2026-10-12 depois das revisões da execução (commits de correção no ramo `integracao-entregas`).

Ordem obrigatória: **banco (2026101201 e 02) → edges → banco (2026101203) → front → Entregas ligado → teste**. O front lê as colunas novas: no ar antes da migration, a carga dos pedidos do painel quebra.

Pré-requisitos no Entregas (planos 1 e 2 já em produção): API com as rotas `v1/entregas/integracao/*`, console com a tela Lojas → Integração e o APK com a cobrança da integração em todos os celulares.

- [ ] **Step 1: Aplicar as migrations 2026101201 e 2026101202 pelo MCP**

`apply_migration` do MCP do Supabase (projeto `rmzvlqynuwcalqrakklp`) com o conteúdo de `supabase/migrations/2026101201_entregas_integracao.sql` (nome `entregas_integracao`) e depois o de `2026101202_entregas_fila_e_gatilhos.sql` (nome `entregas_fila_e_gatilhos`). As duas são neutras sem integração ligada (nenhuma linha em `entregas_integrations`): a Foody e o aviso genérico se comportam como antes.

- [ ] **Step 2: Verificação do banco (fatos, não intenção)**

`execute_sql`:

```sql
select 'tabela' as k, table_name as v from information_schema.tables
 where table_schema = 'public' and table_name in ('entregas_integrations','entregas_webhook_events')
union all select 'coluna', column_name from information_schema.columns
 where table_schema = 'public' and table_name = 'orders' and column_name like 'entregas%'
union all select 'check', pg_get_constraintdef(oid) from pg_constraint where conname = 'message_outbox_kind_check'
union all select 'trigger', tgname from pg_trigger
 where tgname in ('orders_entregas_enqueue_trg','entregas_integrations_desliga_foody_trg','foody_integrations_desliga_entregas_trg')
union all select 'vault', name from vault.secrets where name = 'entregas_encryption_key'
union all select 'rls', relname || '=' || relrowsecurity from pg_class
 where relname in ('entregas_integrations','entregas_webhook_events')
union all select 'anon_executa', p.proname from pg_proc p
 where p.proname like 'entregas_%' and has_function_privilege('anon', p.oid, 'EXECUTE') and p.prorettype <> 'trigger'::regtype;
```

Expected: 2 tabelas; 6 colunas `entregas_*`; o CHECK com os 8 kinds; os 3 triggers; o segredo `entregas_encryption_key`; `rls` = `true` nas duas tabelas; **nenhuma** linha `anon_executa`.

Confira também a trava de exclusividade nas duas funções dos gatilhos (a mesma chave `pg_advisory_xact_lock` por empresa, para ligar a Foody e o Entregas ao mesmo tempo não deixar os dois ligados):

```sql
select proname from pg_proc
 where proname in ('entregas_integrations_desliga_foody','foody_integrations_desliga_entregas')
   and prosrc like '%pg_advisory_xact_lock%';
```

Expected: **2 linhas**. Menos que isso: a migration 2026101201 não é a versão revisada; pare. Rode também `supabase/checks/rls_guardrails.sql` (padrão da casa): nenhuma linha com `ok = false`.

- [ ] **Step 3: Prova dos gatilhos, desfeita no fim**

`execute_sql` com o bloco abaixo. Ele termina com `raise exception` de propósito: **tudo é desfeito** e o resultado vem na mensagem de erro.

```sql
do $prova$
declare
  v_company text;
  v_a text := 'ord-prova-a-' || replace(gen_random_uuid()::text, '-', '');
  v_b text := 'ord-prova-b-' || replace(gen_random_uuid()::text, '-', '');
  v_n int;
  v_txt text;
  v_tok text;
  v_log text := '';
begin
  select id into v_company from companies order by created_at limit 1;
  -- Toggle do aviso genérico ligado (sem ele o controle do passo 3 daria 0 por outro motivo).
  update companies
     set wa_notification_settings = coalesce(wa_notification_settings, '{}'::jsonb) || '{"orderStatus": true}'::jsonb
   where id = v_company;

  -- 1) Foody ligada; ligar o Entregas desliga a Foody.
  insert into foody_integrations (company_id, enabled, encrypted_token, webhook_token)
  values (v_company, true, 'x', replace(gen_random_uuid()::text, '-', ''))
  on conflict (company_id) do update set enabled = true, encrypted_token = 'x';
  insert into entregas_integrations (company_id, enabled, encrypted_token, encrypted_webhook_secret, webhook_token)
  values (v_company, true, 'x', 'y', replace(gen_random_uuid()::text, '-', ''))
  on conflict (company_id) do update set enabled = true, encrypted_token = 'x', encrypted_webhook_secret = 'y';
  select count(*) into v_n from foody_integrations where company_id = v_company and enabled;
  v_log := v_log || 'foody_ligada_apos_entregas=' || v_n;

  -- 2) pending → preparing enfileira o despacho do Entregas e não o da Foody.
  insert into orders (id, company_id, customer_name, customer_phone, items, status, total, created_at, order_type)
  values (v_a, v_company, 'Prova A', '16999990000', '[]'::jsonb, 'pending', 10, '2026-10-12T15:00:00.000Z', 'delivery'),
         (v_b, v_company, 'Prova B', '16999990000', '[]'::jsonb, 'pending', 10, '2026-10-12T15:00:00.000Z', 'delivery');
  update orders set status = 'preparing' where id in (v_a, v_b);
  select count(*) into v_n from message_outbox where dedupe_key = 'entregas:' || v_a and kind = 'entregas_dispatch';
  v_log := v_log || ' despacho=' || v_n;
  select count(*) into v_n from message_outbox where dedupe_key = 'foody:' || v_a;
  v_log := v_log || ' foody=' || v_n;

  -- 3) out_for_delivery: A (com entregas_id) sem o aviso genérico; B (sem) com o aviso (controle).
  update orders set entregas_id = 'order_prova_' || v_a, entregas_status = 'a_caminho' where id = v_a;
  update orders set status = 'out_for_delivery' where id in (v_a, v_b);
  select count(*) into v_n from message_outbox where dedupe_key = 'order_status:' || v_a || ':out_for_delivery';
  v_log := v_log || ' generico_A=' || v_n;
  select count(*) into v_n from message_outbox where dedupe_key = 'order_status:' || v_b || ':out_for_delivery';
  v_log := v_log || ' generico_B=' || v_n;

  -- 4) Cancelamento: A (entrega viva) enfileira; B com a entrega já concluída não enfileira.
  update orders set status = 'cancelled' where id = v_a;
  select count(*) into v_n from message_outbox where dedupe_key = 'entregascancel:' || v_a;
  v_log := v_log || ' cancel=' || v_n;
  update orders set entregas_id = 'order_prova_' || v_b, entregas_status = 'concluido' where id = v_b;
  update orders set status = 'cancelled' where id = v_b;
  select count(*) into v_n from message_outbox where dedupe_key = 'entregascancel:' || v_b;
  v_log := v_log || ' cancel_terminal=' || v_n;

  -- 5) Tentar de novo: a linha já processada volta a pending com as tentativas zeradas e o erro sai do pedido.
  update orders set status = 'preparing', entregas_id = null, entregas_status = null, entregas_error = 'erro de prova'
   where id = v_a;
  update message_outbox set status = 'sent', attempts = 3 where dedupe_key = 'entregas:' || v_a;
  v_txt := entregas_reenfileirar_despacho(v_company, v_a);
  select v_txt || ':' || status || '/' || attempts into v_txt from message_outbox where dedupe_key = 'entregas:' || v_a;
  v_log := v_log || ' retry=' || v_txt;
  select count(*) into v_n from orders where id = v_a and entregas_error is null;
  v_log := v_log || ' erro_limpo=' || v_n;
  v_log := v_log || ' retry_outra_empresa=' || entregas_reenfileirar_despacho('empresa-que-nao-existe', v_a);

  -- 6) Religar a Foody desliga o Entregas, sem rotacionar o webhook_token (os pedidos já enviados seguem andando).
  select webhook_token into v_tok from entregas_integrations where company_id = v_company;
  update foody_integrations set enabled = true where company_id = v_company;
  select count(*) into v_n from entregas_integrations where company_id = v_company and enabled;
  v_log := v_log || ' entregas_ligado_apos_foody=' || v_n;
  select count(*) into v_n from entregas_integrations where company_id = v_company and webhook_token = v_tok;
  v_log := v_log || ' token_mantido=' || v_n;

  -- 7) Cifra ida e volta.
  v_log := v_log || ' cifra=' || coalesce(entregas_decrypt_secret(entregas_encrypt_secret('segredo-prova')), 'NULL');

  raise exception 'PROVA (tudo desfeito): %', v_log;
end $prova$;
```

Expected: o erro `PROVA (tudo desfeito): foody_ligada_apos_entregas=0 despacho=1 foody=0 generico_A=0 generico_B=1 cancel=1 cancel_terminal=0 retry=reenfileirado:pending/0 erro_limpo=1 retry_outra_empresa=pedido_nao_encontrado entregas_ligado_apos_foody=0 token_mantido=1 cifra=segredo-prova`. O `token_mantido=1` prova que a troca para a Foody não rotaciona o `webhook_token` do Entregas. Qualquer número diferente: pare e investigue antes do deploy das edges.

- [ ] **Step 4: Secrets das edges**

`npx supabase secrets list --project-ref rmzvlqynuwcalqrakklp` e confira que existem `CRON_SECRET`, `GOOGLE_MAPS_API_KEY` e `PUBLIC_SUPABASE_URL` (= `https://api.restaurantepro.com.br`). `ENTREGAS_API_URL` é opcional (padrão `https://entregas-api.restaurantepro.com.br`); só defina para apontar para outro ambiente:

```bash
npx supabase secrets set ENTREGAS_API_URL=https://entregas-api.restaurantepro.com.br --project-ref rmzvlqynuwcalqrakklp
```

- [ ] **Step 5: Deploy das três edges**

```bash
npx supabase functions deploy entregas-delivery --project-ref rmzvlqynuwcalqrakklp --no-verify-jwt
npx supabase functions deploy entregas-tick --project-ref rmzvlqynuwcalqrakklp --no-verify-jwt
npx supabase functions deploy entregas-webhook --project-ref rmzvlqynuwcalqrakklp --no-verify-jwt
```

Expected: `Deployed Functions on project rmzvlqynuwcalqrakklp: <nome>` para cada uma.

Provas rápidas:

```bash
curl -s -o /dev/null -w "%{http_code}\n" -X POST "https://api.restaurantepro.com.br/functions/v1/entregas-webhook?t=00000000000000000000000000000000" -H "Content-Type: application/json" -d '{}'
curl -s -o /dev/null -w "%{http_code}\n" -X POST "https://api.restaurantepro.com.br/functions/v1/entregas-tick" -H "Content-Type: application/json" -d '{}'
```

Expected: `410` (token desconhecido) e `401` (sem `x-cron-secret`).

- [ ] **Step 6: Aplicar a migration 2026101203 (crons)**

`apply_migration` com `supabase/migrations/2026101203_entregas_cron.sql` (nome `entregas_cron`). Conferir:

```sql
select jobname, schedule, position('entregas-tick' in command) > 0 as aponta_entregas
from cron.job where jobname in ('entregas-tick', 'entregas-webhook-events-limpeza', 'foody-tick');
```

Expected: `entregas-tick` com `* * * * *` e `aponta_entregas = true`; a limpeza com `17 7 * * *`; o `foody-tick` intacto. Um minuto depois, `query_logs` (edge) mostra o `entregas-tick` respondendo 200 com `claimed: 0`.

- [ ] **Step 7: Front na Vercel (merge do ramo, só com o OK do Edgard)**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/restaurantepro-main"
git checkout main && git pull --ff-only
git merge --no-ff integracao-entregas -m "Merge do ramo integracao-entregas: pedidos de delivery no Entregas RestaurantePro"
git push origin main
```

O push faz o deploy na Vercel. Depois do deploy: painel com F5, Configurações → Delivery → "Entregas por" aparece com "Foody" marcado nas empresas que já usam a Foody e "Nenhum" nas outras; o kanban carrega os pedidos normalmente. A seção mostra "Carregando..." até o status chegar; "Indisponível" com o botão "Tentar de novo" significa que a edge `entregas-delivery` respondeu com erro: volte ao Step 5 (deploy e secrets) antes de seguir.

- [ ] **Step 8: Ligar a integração no Entregas**

No PC do Edgard: `ENTREGAS_INTEGRACAO=1` no `deploy/stack.env` do repo `Delivery` → Portainer → Stacks → entregas → Editor → **Update the stack**, com "Re-pull image" **desligado**.

- [ ] **Step 9: Conectar a loja de teste**

1. No Entregas (console, admin): Fleet-Ops → Recursos → Lojas → **Terraço Pizza Bar** → Integração → **Gerar chave** (aparece uma vez; copie).
2. No RestaurantePro, numa **empresa de teste** (nunca uma loja real nesta etapa): Configurações → Delivery → "Entregas por" → **Entregas RestaurantePro** → cole a chave → Conectar.
3. Expected: "Conectado à loja Terraço Pizza Bar no Entregas"; no Entregas, o bloco Integração mostra "Conectada a RestaurantePro". Na troca Foody → Entregas, a confirmação diz "Ligar Entregas RestaurantePro desliga Foody…"; a Foody **continua ligada até o Conectar**. Só depois do Conectar, confira `select enabled from foody_integrations where company_id = '<empresa>'` = `false`.
4. Se o connect responder "Entregas: webhook_url recusada: …", pare: é o risco do DNS/proxy (ver "Riscos"). Confira `nslookup api.restaurantepro.com.br` e o secret `PUBLIC_SUPABASE_URL`.

- [ ] **Step 10: O ciclo de um pedido (motoboys avisados antes)**

1. Faça um pedido de delivery no cardápio público da empresa de teste, pagamento em dinheiro com troco, telefone de um celular seu, e mova para **Preparando**.
2. Em até 1 min: o card mostra "🛵 Entregas · procurando motoboy" e "Rastrear entrega · <código>"; no Entregas, o pedido "RestaurantePro #<4 dígitos>" com a faixa amarela da cobrança no app do motoboy.
3. Motoboy aceita → card "Entregas · com <nome>".
4. Motoboy toca "A caminho" → o pedido vai sozinho para a coluna "Saiu para entrega"; **sai um WhatsApp só**, com o link `https://entregas.restaurantepro.com.br/~/track-order?order=<código>` (e o código no texto, se for pela WAHA). Confira na fila: `select kind, status from message_outbox where payload->>'orderId' = '<id>' order by created_at;` → sem `order_status` do `out_for_delivery`, com `entregas_tracking_msg` `sent`.
5. Motoboy conclui → pedido em "Concluído", card "Entregas · entregue".
6. Dedupe: `select evento, count(*) from entregas_webhook_events where order_id = '<id>' group by evento;` → um registro por aviso entregue.

- [ ] **Step 11: Cancelar nas três fases**

Para cada caso, um pedido novo levado a "Preparando":
1. **Antes do aceite:** cancele no kanban → em até 1 min, card "Entregas · cancelado"; no Entregas, cancelado sem pagamento.
2. **Aceito, antes do "A caminho":** cancele → "Entregas · cancelado"; o motoboy recebe "cancelado pela loja".
3. **Depois do "A caminho":** cancele → "Entregas · cancelado (entrega cobrada)" (`entregas_status = 'cancelado_pago'`); no Entregas, "Cancelado pela loja (pago)".

- [ ] **Step 12: Trocar Entregas → Foody com um pedido já aceito no Entregas**

Numa empresa de teste com token da Foody (ou, sem token, simulando com `update entregas_integrations set enabled = false where company_id = '<empresa>'`, **sem mexer no token**): leve um pedido a "Preparando", deixe o motoboy aceitar e então troque "Entregas por" para **Foody** (ou rode o `update` acima).

Expected: o pedido já enviado segue andando. Quando o motoboy toca "A caminho", o card move para "Saiu para entrega" e sai o WhatsApp com o link, como no Step 10. O `webhook_token` não muda: `select webhook_token from entregas_integrations where company_id = '<empresa>';` igual ao de antes da troca. Pedidos novos já não vão ao Entregas.

- [ ] **Step 13: Assinatura errada e token desconhecido**

Leia o token da empresa de teste no MCP (`select webhook_token from entregas_integrations where company_id = '<empresa de teste>';`) e rode, com ele no lugar de `<token>`:

```bash
T=<token>
curl -s -w "\n%{http_code}\n" -X POST "https://api.restaurantepro.com.br/functions/v1/entregas-webhook?t=$T" \
  -H "Content-Type: application/json" -H "X-Entregas-Evento: pedido.aceito" \
  -H "X-Entregas-Id: 0123456789abcdef0123456789abcdef" \
  -H "X-Entregas-Assinatura: sha256=0000000000000000000000000000000000000000000000000000000000000000" \
  -d '{"evento":"pedido.aceito","id_entrega":"0123456789abcdef0123456789abcdef","pedido_externo_id":"ord-teste"}'
```

Expected: `{"error":"assinatura inválida"}` e `401`; no log da edge, `[entregas-webhook] assinatura recusada`. Troque o `t` por 32 zeros: `410`.

A recusa (400) por cabeçalho `X-Entregas-Evento` ou `X-Entregas-Id` diferente do corpo só se prova com o segredo do webhook (que sai apenas na conexão e fica cifrado no banco): não dá para provar aqui, fica coberta só pelos testes Deno.

- [ ] **Step 14: Tentar de novo**

Num pedido de teste, force um erro definitivo: no Entregas, revogue a chave da Terraço; leve um pedido a "Preparando" → card com "A chave do Entregas foi recusada…" e o botão **Tentar de novo**. O botão só existe com o pedido em "Preparando" ou "Saiu para entrega" e com usuário que tenha `orders.edit`; fora disso não há botão, e o card tira o "Toque em Tentar de novo.". Para reconectar depois do 401: Configurações → **Desconectar** → cole a chave nova → Conectar (o editor segue "Conectado" e sem campo de chave até desconectar). Gere uma chave nova no Entregas, reconecte como descrito e toque em **Tentar de novo** → "Reenviado: o Entregas recebe em até 1 minuto." e, em seguida, "Entregas · procurando motoboy".

- [ ] **Step 15: Rollback (se algo der errado)**

- Por empresa:
  - escolher **Foody** desliga o Entregas **sem rotacionar** o token: os pedidos já enviados seguem andando no kanban e o cancelamento continua chegando ao Entregas;
  - **Nenhum** = Desconectar, que rotaciona o token: o kanban para para esses pedidos;
  - voltar ao Entregas pede uma chave nova, e gerar a chave nova no Entregas revoga a anterior.
  - Em qualquer caso, os pedidos novos param de ir.
- Geral: no Entregas, `ENTREGAS_INTEGRACAO=` vazio + Update the stack; o RestaurantePro grava "O Entregas recusou: Integração desligada." em `entregas_error`.
- As migrations ficam (são neutras sem integração ligada). Para parar o cron: `select cron.unschedule('entregas-tick');`.

---

## Autorrevisão (feita ao escrever o plano)

- **Cobertura da spec (Parte 3 + o pedido):**
  - "Entregas por" e a confirmação: Tasks 11 e 14; exclusividade no banco: Task 5 (triggers) e Task 6 (despachos);
  - edge `entregas-delivery` (status, connect com a URL `…/entregas-webhook?t=`, cifra no Vault, desliga a Foody pelo trigger, disconnect rotacionando, retry): Task 8; chave nunca volta ao navegador: `statusBody` só com campos inócuos;
  - `entregas_integrations` com todas as colunas pedidas: Task 5; `ENTREGAS_API_URL` com padrão: Tasks 2 e 8;
  - colunas em `orders` com índice único parcial: Task 5; trigger `orders_entregas_enqueue` (`WHEN` status mudou, `order_type`, sem `entregas_id`, integração ligada; cancelamento não terminal) e CHECK ampliado com a lista inteira: Task 6;
  - `entregas-tick` (claim pela outbox, geocodificação, pagamento, código curto, `pedido_externo_id = orders.id`, grava id/situação/código/link, `entregas_error` com o `errors[0]`, definitivo × temporário, cancelamento com 409 `ja_concluido` e 404 sem erro, telefone de 8 a 15): Tasks 2, 3 e 9;
  - `entregas-webhook` (corpo cru primeiro, `t`, HMAC em tempo constante, 401, 500, dedupe, `orders.id = pedido_externo_id` + `company_id`, mapeamento, cerca, WhatsApp sem duplicar): Tasks 4, 6 e 10;
  - testes Deno das regras (pagamento, corpo, HMAC com vetores de fora, mapeamento com a cerca, classificação, texto): Tasks 2 a 4; util do front com teste: Task 11; `smoke:entregas`: Tasks 5, 6, 7 e 11;
  - selo no card, link, erro e "Tentar de novo": Tasks 12 e 15; tipo `Order` e a query: Task 12;
  - RPC do "Tentar de novo" com a conferência da empresa e do `entregas_id`: Task 6; bloco SQL de verificação: Task 17, Steps 2 e 3;
  - implantação e teste real, rollback e `CLAUDE.md`: Tasks 16 e 17.
- **Caça de placeholders:** nenhum "TBD", "similar à Task N" ou passo sem código. Os únicos `<…>` são valores de produção que só existem na hora (id do pedido, empresa de teste, token).
- **Nomes conferidos entre tasks:**
  - `_shared/entregas.ts`: `urlDaApi`, `urlDoWebhook`, `chaveValida`, `tokenDoWebhookValido`, `telefoneDoCliente`, `cobrancaDoPedido`, `corpoDoPedido`, `ultimoTrecho`, `etapaDoStatus`, `ENTREGAS_STATUS`, `ENTREGAS_STATUS_TERMINAIS`, `ENTREGAS_MAX_TENTATIVAS`, `ENTREGAS_SISTEMA`, `classificarResposta`, `errosDaResposta`, `mensagemDeErro`, `linkSeguro`, `statusDaCriacao`, `patchDaCriacao`, `primeiroNome`, `textoDoAcaminho`, `hmacHex`, `assinaturaConfere`, `avisoDoCorpo`, `mudancaDoAviso` (usados nas Tasks 8, 9, 10 e no smoke);
  - `_shared/googleGeocode.ts`: `GeoDaEntrega`, `geoDoGoogle`, `geocodificarEndereco` (Tasks 2 e 9);
  - SQL: `entregas_encrypt_secret`/`entregas_decrypt_secret` (edges delivery, tick e webhook), `entregas_reenfileirar_despacho(p_company_id, p_order_id)` (edge delivery), kinds `entregas_dispatch`/`entregas_cancel`/`entregas_tracking_msg` e `dedupe_key` `entregas:`/`entregascancel:`/`entregastrack:` (trigger, tick, webhook e RPC);
  - painel: `entregasStatusBadge`, `entregasDica`, `podeTentarDeNovo`, `provedorAtivo`, `PROVEDOR_LABELS`, `textoDaTroca`, `chaveDeIntegracaoValida`, `useEntregasIntegration`, `retryEntregasDispatch`, `EntregasIntegrationApi`.
- **Verificado antes de escrever:** os testes Deno (27), o `smoke:entregas`, o `deno check` das três edges e o `tsc` do front (sem erro novo) rodaram numa cópia de trabalho fora do repo com exatamente o código deste plano.
