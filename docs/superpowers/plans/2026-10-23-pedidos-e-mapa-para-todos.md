# Pedidos "Meus / Todos" para o líder e Mapa para todos — plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** o líder ganha as abas "Meus pedidos | Todos os pedidos" na aba Pedidos, e a aba Mapa passa a aparecer para todos (o motoboy comum vê só os pedidos dele).

**Architecture:** só o app (`entregas-navigator`). "Todos os pedidos" lê o `GET entregas/lider/mapa` que já existe; o mapa do motoboy comum é montado no app a partir do `allActiveOrders` (OrderManagerContext) e da posição do celular (LocationContext), por uma função pura nova (`mapaDoMotoboy`) que devolve o mesmo formato `MapaDoLider`.

**Tech Stack:** React Native + Tamagui + react-native-maps + @gorhom/bottom-sheet; testes das funções puras com `node --experimental-strip-types --test`.

Spec: `docs/superpowers/specs/2026-10-23-pedidos-e-mapa-para-todos-design.md` (no Delivery).

Repo do código: `C:\Users\Edgardjr\Documents\vibe coding\entregas-navigator` (branch `main`). Convenção das funções puras de
`src/utils/*.ts`: **sem imports** (os testes rodam com o Node puro); o `ler`/`texto` locais são repetidos em cada arquivo,
como em `prazo.ts` e `lista-de-pedidos.ts`. Comentários e textos em pt-BR, no estilo dos arquivos vizinhos.

**Commits:** os agentes NÃO commitam (trabalham em paralelo na mesma pasta); o coordenador revisa e commita cada task.

## Arquivos

| Arquivo | Task | O quê |
|---|---|---|
| `src/utils/mapa-do-motoboy.ts` (novo) | 1 | `situacaoDoMotoboy`, `mapaDoMotoboy` |
| `scripts/testes/mapa-do-motoboy.teste.ts` (novo) | 1 | testes |
| `src/utils/mapa-do-lider.ts` | 1 | `todosOsPedidos`, `situacaoDoPedido` |
| `scripts/testes/mapa-do-lider.teste.ts` | 1 | testes novos |
| `translations/pt.json`, `translations/en.json` | 1 | todos os textos novos (das tasks 2 e 3 também) |
| `src/hooks/use-todos-os-pedidos.ts` (novo) | 2 | leitura de `lider/mapa` a cada 30 s |
| `src/components/CardDeTodosOsPedidos.tsx` (novo) | 2 | o card |
| `src/screens/DriverOrderManagementScreen.tsx` | 2 | seletor + lista Todos |
| `src/screens/MapaDoLiderScreen.tsx` | 3 | modo comum + parâmetro `pedido` |
| `src/navigation/DriverNavigator.tsx` | 3 | botão da aba Mapa para todos + tela `Order` na pilha do Mapa |
| `CLAUDE.md` do app e `api/app/Http/Controllers/Entregas/CLAUDE.md` (Delivery) | 4 | documentação |

Ordem: Task 1 primeiro; Tasks 2 e 3 em paralelo (arquivos distintos); Task 4 no fim.

---

### Task 1: funções puras e textos

**Files:**
- Create: `src/utils/mapa-do-motoboy.ts`, `scripts/testes/mapa-do-motoboy.teste.ts`
- Modify: `src/utils/mapa-do-lider.ts` (fim do arquivo), `scripts/testes/mapa-do-lider.teste.ts` (fim), `translations/pt.json`, `translations/en.json`

- [ ] **Step 1: testes do `mapa-do-motoboy`** — criar `scripts/testes/mapa-do-motoboy.teste.ts`:

```ts
// Testes do mapa do motoboy comum (src/utils/mapa-do-motoboy.ts), com o Node puro:
//   node --experimental-strip-types --test scripts/testes/mapa-do-motoboy.teste.ts
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mapaDoMotoboy, situacaoDoMotoboy } from '../../src/utils/mapa-do-motoboy.ts';

const pedido = (extra: Record<string, any> = {}) => ({
    id: 'order_1',
    internal_id: '4821',
    status: 'started',
    started: true,
    created_at: '2026-10-23T08:00:00-03:00',
    updated_at: '2026-10-23T08:10:00-03:00',
    meta: { entregas_prazo_em: '2026-10-23T08:50:00-03:00' },
    payload: {
        pickup: { name: 'Pizzaria Boa', location: { type: 'Point', coordinates: [-47.8, -21.17] } },
        dropoff: { name: 'Cliente', address: 'Rua A, 10', location: { type: 'Point', coordinates: [-47.81, -21.18] } },
    },
    ...extra,
});

// recurso do SDK: só lê pelo getAttribute (o id fica na propriedade, como no Resource do Fleetbase)
const recurso = (dados: Record<string, any>) => ({
    id: dados.id,
    getAttribute: (caminho: string) => caminho.split('.').reduce((atual: any, chave) => (atual == null ? undefined : atual[chave]), dados),
});

const AQUI = { latitude: -21.175, longitude: -47.805 };

test('situação: sem pedido segue o online; a caminho vale mais que coleta', () => {
    assert.equal(situacaoDoMotoboy(true, []), 'livre');
    assert.equal(situacaoDoMotoboy(false, []), 'offline');
    assert.equal(situacaoDoMotoboy(false, ['started']), 'coleta');
    assert.equal(situacaoDoMotoboy(true, ['dispatched', 'ENROUTE']), 'entrega');
    for (const status of ['picked_up', 'dropping_off', 'in_progress']) assert.equal(situacaoDoMotoboy(true, [status]), 'entrega');
    assert.equal(situacaoDoMotoboy(true, ['completed', 'canceled']), 'livre');
});

test('mapa: o pedido dele no formato do mapa do líder', () => {
    const mapa = mapaDoMotoboy([pedido()], AQUI, true, 'Ana');
    assert.deepEqual(mapa.pedidos, [
        {
            id: 'order_1',
            numero: '4821',
            latitude: -21.18,
            longitude: -47.81,
            endereco: 'Rua A, 10',
            status: 'started',
            motoboy: 'Ana',
            aceito: true,
            criado_em: '2026-10-23T08:00:00-03:00',
            loja: 'Pizzaria Boa',
            motoboy_id: null,
            atualizado_em: '2026-10-23T08:10:00-03:00',
            coleta: { latitude: -21.17, longitude: -47.8 },
            prazo_em: '2026-10-23T08:50:00-03:00',
        },
    ]);
    assert.deepEqual(mapa.motoboys, [{ id: 'eu', nome: 'Ana', latitude: -21.175, longitude: -47.805, situacao: 'coleta', online: true }]);
});

test('mapa: lê o recurso do SDK (getAttribute) igual ao serializado', () => {
    assert.deepEqual(mapaDoMotoboy([recurso(pedido())], AQUI, true, 'Ana'), mapaDoMotoboy([pedido()], AQUI, true, 'Ana'));
});

test('mapa: tira encerrado, sem destino válido e repetido', () => {
    const semDestino = pedido({ id: 'order_2', payload: { dropoff: { location: { coordinates: [0, 0] } } } });
    const encerrado = pedido({ id: 'order_3', status: 'completed' });
    const mapa = mapaDoMotoboy([pedido(), pedido(), semDestino, encerrado], AQUI, true, 'Ana');
    assert.deepEqual(mapa.pedidos.map((item) => item.id), ['order_1']);
});

test('mapa: número sem internal_id cai no rastreio; sem loja no pickup cai no cliente; sem prazo = null', () => {
    const outro = pedido({ internal_id: null, tracking_number: { tracking_number: 'RP570671' }, meta: {}, payload: { ...pedido().payload, pickup: { location: null } }, customer: { name: 'Loja X' } });
    const [item] = mapaDoMotoboy([outro], AQUI, true, 'Ana').pedidos;
    assert.equal(item.numero, 'RP570671');
    assert.equal(item.loja, 'Loja X');
    assert.equal(item.coleta, null);
    assert.equal(item.prazo_em, null);
});

test('mapa: sem posição não tem capacete; a situação conta os pedidos dele', () => {
    assert.deepEqual(mapaDoMotoboy([pedido()], null, true, 'Ana').motoboys, []);
    assert.equal(mapaDoMotoboy([pedido({ status: 'enroute' })], AQUI, true, 'Ana').motoboys[0].situacao, 'entrega');
    assert.equal(mapaDoMotoboy([], AQUI, false, 'Ana').motoboys[0].situacao, 'offline');
    assert.deepEqual(mapaDoMotoboy([], { latitude: 0, longitude: 0 }, true, 'Ana').motoboys, []);
});

test('mapa: aceito só com started verdadeiro; entrada estranha = mapa vazio', () => {
    assert.equal(mapaDoMotoboy([pedido({ started: false, status: 'dispatched' })], AQUI, true, 'Ana').pedidos[0].aceito, false);
    assert.deepEqual(mapaDoMotoboy(null as any, null, true, null), { pedidos: [], motoboys: [] });
});
```

- [ ] **Step 2: rodar e ver falhar** — `cd "C:/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && node --experimental-strip-types --test scripts/testes/mapa-do-motoboy.teste.ts` → FAIL (módulo não existe).

- [ ] **Step 3: implementar** — criar `src/utils/mapa-do-motoboy.ts`:

```ts
// Entregas: a aba Mapa do motoboy comum (MapaDoLiderScreen, quando não é líder; spec 2026-10-23 no Delivery). Ele vê só os
// pedidos dele em andamento (os mesmos da aba Pedidos: allActiveOrders) e o próprio capacete, na posição do celular. Monta
// o mesmo formato do mapa do líder (MapaDoLider, de mapa-do-lider.ts), sem chamada nova ao servidor. A situação do
// capacete segue a regra do servidor (App\Support\Entregas\SituacaoDoMotoboy): a caminho do cliente = entrega, outro
// pedido em andamento = coleta, sem pedido = livre ou offline pelo online.
// Os pedidos chegam como recurso do SDK (getAttribute) ou serializados (objeto comum): as funções leem os dois.
// Sem imports, para os testes rodarem com o Node puro: node --experimental-strip-types --test scripts/testes/mapa-do-motoboy.teste.ts

type Ponto = { latitude: number; longitude: number };
type Situacao = 'livre' | 'coleta' | 'entrega' | 'offline';

// os tipos do mapa-do-lider.ts (PedidoDoLider, MotoboyDoLider, MapaDoLider), repetidos para não ter import
type PedidoNoMapa = {
    id: string;
    numero: string;
    latitude: number;
    longitude: number;
    endereco: string | null;
    status: string | null;
    motoboy: string | null;
    aceito: boolean;
    criado_em: string | null;
    loja: string | null;
    motoboy_id: string | null;
    atualizado_em: string | null;
    coleta: Ponto | null;
    prazo_em: string | null;
};
type MotoboyNoMapa = { id: string; nome: string | null; latitude: number; longitude: number; situacao: Situacao; online: boolean };

/** O id do capacete dele no mapa (só um motoboy: ele). */
export const ID_DO_MEU_CAPACETE = 'eu';

const ENCERRADOS = ['completed', 'done', 'canceled', 'cancelled', 'order_canceled', 'expired'];

/** Status de pedido que já saiu da loja (o mesmo STATUS_EM_ENTREGA do servidor e do cartao-do-pedido). */
const STATUS_EM_ENTREGA = ['enroute', 'picked_up', 'dropping_off', 'in_progress'];

const ler = (pedido: any, caminho: string): any => {
    if (!pedido) return undefined;
    if (typeof pedido.getAttribute === 'function') return pedido.getAttribute(caminho);
    return caminho.split('.').reduce((atual: any, chave) => (atual === null || atual === undefined ? undefined : atual[chave]), pedido);
};

const texto = (valor: unknown): string | null => {
    if (valor === null || valor === undefined) return null;
    const limpo = String(valor).trim();
    return limpo === '' ? null : limpo;
};

const pontoValido = (latitude: unknown, longitude: unknown): Ponto | null => {
    const lat = Number(latitude);
    const lng = Number(longitude);
    if (latitude === null || longitude === null || !Number.isFinite(lat) || !Number.isFinite(lng)) return null;
    if (Math.abs(lat) > 90 || Math.abs(lng) > 180) return null;
    if (Math.abs(lat) <= 0.0001 && Math.abs(lng) <= 0.0001) return null;
    return { latitude: lat, longitude: lng };
};

/** Posição de um lugar do pedido (location.coordinates = [lng, lat]); null sem posição válida. */
const pontoDoLugar = (pedido: any, caminho: string): Ponto | null => {
    const pontos = ler(pedido, `${caminho}.location.coordinates`);
    return Array.isArray(pontos) && pontos.length >= 2 ? pontoValido(pontos[1], pontos[0]) : null;
};

const idDe = (pedido: any): string | null => texto(pedido?.id) ?? texto(ler(pedido, 'id'));

const dataIso = (valor: unknown): string | null => (valor instanceof Date ? valor.toISOString() : texto(valor));

/** A cor do capacete dele pelos status dos pedidos dele (a regra do SituacaoDoMotoboy::classificar do servidor). */
export function situacaoDoMotoboy(online: boolean, statusDosPedidos: Array<string | null | undefined>): Situacao {
    let situacao: Situacao = online ? 'livre' : 'offline';
    for (const bruto of statusDosPedidos ?? []) {
        const status = String(bruto ?? '').toLowerCase();
        if (ENCERRADOS.includes(status)) continue;
        if (STATUS_EM_ENTREGA.includes(status)) return 'entrega';
        situacao = 'coleta';
    }
    return situacao;
}

/**
 * O mapa do motoboy comum: os pedidos dele não encerrados com destino válido (sem repetir, na ordem que chegaram) e o
 * capacete dele na posição do celular (sem posição válida: sem capacete). Mesmo formato do lerMapa do líder.
 */
export function mapaDoMotoboy(pedidos: any[], posicao: Ponto | null, online: boolean, nome: string | null): { pedidos: PedidoNoMapa[]; motoboys: MotoboyNoMapa[] } {
    const lista: PedidoNoMapa[] = [];
    const vistos = new Set<string>();
    for (const pedido of Array.isArray(pedidos) ? pedidos : []) {
        const id = idDe(pedido);
        const status = texto(ler(pedido, 'status'));
        if (!id || vistos.has(id) || ENCERRADOS.includes((status ?? '').toLowerCase())) continue;
        const destino = pontoDoLugar(pedido, 'payload.dropoff');
        if (!destino) continue;
        vistos.add(id);
        const prazo = ler(pedido, 'meta.entregas_prazo_em');
        lista.push({
            id,
            numero: texto(ler(pedido, 'internal_id')) ?? texto(ler(pedido, 'tracking_number.tracking_number')) ?? id,
            ...destino,
            endereco: texto(ler(pedido, 'payload.dropoff.address')) ?? texto(ler(pedido, 'payload.dropoff.name')),
            status,
            motoboy: texto(nome),
            aceito: ler(pedido, 'started') === true,
            criado_em: dataIso(ler(pedido, 'created_at')),
            loja: texto(ler(pedido, 'payload.pickup.name')) ?? texto(ler(pedido, 'customer.name')),
            motoboy_id: null,
            atualizado_em: dataIso(ler(pedido, 'updated_at')),
            coleta: pontoDoLugar(pedido, 'payload.pickup'),
            prazo_em: typeof prazo === 'string' && prazo.trim() !== '' ? prazo.trim() : null,
        });
    }

    const ponto = posicao ? pontoValido(posicao.latitude, posicao.longitude) : null;
    const motoboys: MotoboyNoMapa[] = ponto
        ? [{ id: ID_DO_MEU_CAPACETE, nome: texto(nome), ...ponto, situacao: situacaoDoMotoboy(online, lista.map((item) => item.status)), online }]
        : [];

    return { pedidos: lista, motoboys };
}
```

- [ ] **Step 4: rodar e ver passar** — o mesmo comando do Step 2 → PASS.

- [ ] **Step 5: testes de `todosOsPedidos` e `situacaoDoPedido`** — no fim de `scripts/testes/mapa-do-lider.teste.ts`, acrescentar `situacaoDoPedido, todosOsPedidos` ao import de `mapa-do-lider.ts` e:

```ts
test('todos os pedidos: do mais antigo ao mais novo, estável, sem data no fim', () => {
    const lista = todosOsPedidos([
        pedido({ id: 'c', criado_em: '2026-10-06T09:00:00-03:00' }),
        pedido({ id: 'sem', criado_em: null }),
        pedido({ id: 'a', criado_em: '2026-10-06T08:00:00-03:00' }),
        pedido({ id: 'b', criado_em: '2026-10-06T08:00:00-03:00' }),
        pedido({ id: 'ruim', criado_em: 'ontem' }),
    ]);
    assert.deepEqual(lista.map((item) => item.id), ['a', 'b', 'c', 'sem', 'ruim']);
});

test('todos os pedidos: não muda a lista recebida', () => {
    const original = [pedido({ id: 'b', criado_em: '2026-10-06T09:00:00-03:00' }), pedido({ id: 'a', criado_em: '2026-10-06T08:00:00-03:00' })];
    todosOsPedidos(original);
    assert.deepEqual(original.map((item) => item.id), ['b', 'a']);
    assert.deepEqual(todosOsPedidos(null as any), []);
});

test('situação do pedido: sem motoboy, a caminho, indo à loja ou com a central', () => {
    assert.equal(situacaoDoPedido(pedido({ motoboy: null, motoboy_id: null })), 'sem');
    assert.equal(situacaoDoPedido(pedido({ status: 'enroute' })), 'entrega');
    assert.equal(situacaoDoPedido(pedido({ status: 'picked_up' })), 'entrega');
    assert.equal(situacaoDoPedido(pedido({ status: 'started', aceito: true })), 'coleta');
    assert.equal(situacaoDoPedido(pedido({ status: 'driver_enroute', aceito: false })), 'coleta');
    assert.equal(situacaoDoPedido(pedido({ status: 'dispatched', aceito: false })), 'central');
});
```

- [ ] **Step 6: rodar e ver falhar** — `node --experimental-strip-types --test scripts/testes/mapa-do-lider.teste.ts` → FAIL (`todosOsPedidos` não existe).

- [ ] **Step 7: implementar** — no fim de `src/utils/mapa-do-lider.ts`:

```ts
// Entregas: a aba "Todos os pedidos" da aba Pedidos do líder (spec 2026-10-23 no Delivery): os mesmos pedidos do mapa
// (lider/mapa), em lista.

const criadoEmMs = (pedido: PedidoDoLider): number => {
    const ms = pedido.criado_em ? Date.parse(pedido.criado_em) : NaN;
    return Number.isFinite(ms) ? ms : Infinity;
};

/** A lista "Todos os pedidos": do mais antigo ao mais novo (criado_em), estável; sem data ou com data ilegível, no fim. */
export function todosOsPedidos(pedidos: PedidoDoLider[]): PedidoDoLider[] {
    return (Array.isArray(pedidos) ? pedidos : [])
        .map((pedido, posicao) => ({ pedido, posicao, quando: criadoEmMs(pedido) }))
        .sort((a, b) => (a.quando === b.quando ? a.posicao - b.posicao : a.quando < b.quando ? -1 : 1))
        .map(({ pedido }) => pedido);
}

/** A situação no card de "Todos os pedidos": sem motoboy; ou a mesma regra do card de Em andamento (situacaoDoAndamento). */
export function situacaoDoPedido(pedido: PedidoDoLider): 'sem' | 'entrega' | 'coleta' | 'central' {
    if (semMotoboy(pedido)) return 'sem';
    const status = (pedido.status ?? '').toLowerCase();
    if (['enroute', 'picked_up', 'dropping_off', 'in_progress'].includes(status)) return 'entrega';
    if (pedido.aceito || status === 'started' || status === 'driver_enroute') return 'coleta';
    return 'central';
}
```

- [ ] **Step 8: rodar e ver passar** — o mesmo comando do Step 6 → PASS. Depois rodar todos: `node --experimental-strip-types --test scripts/testes/*.teste.ts` → todos PASS.

- [ ] **Step 9: textos** — em `translations/pt.json`, dentro de `"DriverOrderManagementScreen"` (depois de `"nenhumPedidoDica"`), acrescentar:

```json
        "meusPedidos": "Meus pedidos",
        "todosOsPedidos": "Todos os pedidos",
        "nenhumEmAndamento": "Nenhum pedido em andamento",
        "nenhumEmAndamentoDica": "Os pedidos de todas as lojas aparecem aqui.",
        "erroTodos": "Não foi possível atualizar a lista. Puxe para tentar de novo."
```

e dentro de `"MapaDoLider"` (depois de `"ifood"`):

```json
        "tituloMotoboy": "Mapa",
        "verPedido": "Ver pedido",
        "meuPedido": "Pedido em andamento"
```

Em `translations/en.json`, as mesmas chaves nos mesmos grupos: `"meusPedidos": "My orders"`, `"todosOsPedidos": "All orders"`, `"nenhumEmAndamento": "No orders in progress"`, `"nenhumEmAndamentoDica": "Orders from every store show up here."`, `"erroTodos": "Could not refresh the list. Pull to try again."`; `"tituloMotoboy": "Map"`, `"verPedido": "View order"`, `"meuPedido": "Order in progress"`. Conferir JSON válido: `node -e "JSON.parse(require('fs').readFileSync('translations/pt.json','utf8'));JSON.parse(require('fs').readFileSync('translations/en.json','utf8'))"`.

- [ ] **Step 10:** não commitar (o coordenador commita).

---

### Task 2: aba Pedidos com "Meus pedidos | Todos os pedidos" (depende da Task 1)

**Files:**
- Create: `src/hooks/use-todos-os-pedidos.ts`, `src/components/CardDeTodosOsPedidos.tsx`
- Modify: `src/screens/DriverOrderManagementScreen.tsx`

Não mexer em `MapaDoLiderScreen.tsx`, `DriverNavigator.tsx` nem nas traduções (outras tasks).

- [ ] **Step 1: o hook** — `src/hooks/use-todos-os-pedidos.ts`. Lê `entregas/lider/mapa` só quando `ativo` (líder com a aba Todos escolhida), com a tela em foco e o app na frente: ao ativar, a cada 30 s e no `recarregar()` (puxar para atualizar). Uma leitura por vez (ref). Erro: mantém a última lista, marca `erro = true` e chama `conferirAcessoDoLider(adapter)` (perdeu o papel → `useEhLider` falso → a tela volta para Meus pedidos). Sucesso limpa o erro.

```ts
import { useCallback, useEffect, useRef, useState } from 'react';
import { AppState } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import useFleetbase from './use-fleetbase';
import { conferirAcessoDoLider } from './use-lider';
import { lerMapa, todosOsPedidos } from '../utils/mapa-do-lider';
import type { PedidoDoLider } from '../utils/mapa-do-lider';

// Entregas: a aba "Todos os pedidos" da aba Pedidos do líder (spec 2026-10-23 no Delivery): os pedidos em andamento de
// todas as lojas, da mesma rota do Mapa do líder (v1/entregas/lider/mapa), relidos a cada 30 s com a aba escolhida, a tela
// em foco e o app na frente. Erro mantém a última lista; no erro o acesso é relido (sem o papel, a aba some).
export const INTERVALO_DE_TODOS_MS = 30 * 1000;

export default function useTodosOsPedidos(ativo: boolean) {
    const { adapter } = useFleetbase();
    const [pedidos, setPedidos] = useState<PedidoDoLider[] | null>(null);
    const [carregando, setCarregando] = useState(false);
    const [erro, setErro] = useState(false);
    const lendoRef = useRef(false);

    const recarregar = useCallback(async () => {
        if (!adapter || lendoRef.current) return;
        lendoRef.current = true;
        setCarregando(true);
        try {
            setPedidos(todosOsPedidos(lerMapa(await adapter.get('entregas/lider/mapa')).pedidos));
            setErro(false);
        } catch (falha) {
            console.warn('[lider] todos os pedidos:', falha);
            setErro(true);
            conferirAcessoDoLider(adapter);
        } finally {
            lendoRef.current = false;
            setCarregando(false);
        }
    }, [adapter]);

    useFocusEffect(
        useCallback(() => {
            if (!ativo) return;
            recarregar();
            const intervalo = setInterval(() => {
                if (AppState.currentState === 'active') recarregar();
            }, INTERVALO_DE_TODOS_MS);
            const assinatura = AppState.addEventListener('change', (estado) => {
                if (estado === 'active') recarregar();
            });
            return () => {
                clearInterval(intervalo);
                assinatura.remove();
            };
        }, [ativo, recarregar])
    );

    // outro motoboy no mesmo celular (adapter novo) começa sem a lista antiga
    useEffect(() => {
        setPedidos(null);
        setErro(false);
    }, [adapter]);

    return { pedidos, carregando, erro, recarregar };
}
```

- [ ] **Step 2: o card** — `src/components/CardDeTodosOsPedidos.tsx`, com o visual do `OrderCard` (ler `src/components/OrderCard.tsx`): linha 1 com o selo do número (`ehNumeroDoIfood(p.numero)` → `<Selo variante='ifood'>{t('MapaDoLider.ifood', { pedido: rotuloDoPedido(p.numero) })}</Selo>`, senão `<Selo variante='laranja'>{t('MapaDoLider.pedido', { pedido: rotuloDoPedido(p.numero) })}</Selo>`), o selo da situação (`situacaoDoPedido(p)`: `'sem'` → `<Selo variante='perigo'>{t('MapaDoLider.semMotoboy')}</Selo>`; os outros → `t('OrderCard.situacao.<situacao>')` com as variantes do `OrderCard`: entrega `perigo`, coleta `alerta`, central `laranja`) e `<CronometroDoPrazo prazo={p.prazo_em} />`; linha 2: a loja (`p.loja ?? '—'`, 16/700); linha 3 (`$textSecondary`, 13): o nome do motoboy (`p.motoboy`; sem motoboy a linha mostra só o endereço) e `→ ${p.endereco}` juntos por ` · `. Chevron à direita. Props: `{ pedido: PedidoDoLider; onPress: () => void }`. Comentário de cabeçalho no estilo do `OrderCard`.

- [ ] **Step 3: a tela** — em `src/screens/DriverOrderManagementScreen.tsx`:
  - `const ehLider = useEhLider();` e `const [aba, setAba] = useState<'meus' | 'todos'>('meus');`. Efeito: `if (!ehLider) setAba('meus')`.
  - `const todos = useTodosOsPedidos(ehLider && aba === 'todos');`
  - Seletor (só com `ehLider`), acima da lista, no estilo dos chips do Mapa do líder (`MapaDoLiderScreen`, chips de filtro: altura 40, `borderRadius 9999`, ativo com fundo `$textPrimary` e texto `$background`), dois chips: `t('DriverOrderManagementScreen.meusPedidos')` e `t('DriverOrderManagementScreen.todosOsPedidos')` + o número (`todos.pedidos?.length`, ou `–` antes da primeira leitura), `accessibilityRole='button'`, `accessibilityState={{ selected }}`, área de toque ≥ 48 px de altura (padding vertical).
  - Com `aba === 'meus'`: a `SectionList` de hoje, sem mudança.
  - Com `aba === 'todos'`: uma `FlatList` com `todos.pedidos ?? []`, `renderItem` com `CardDeTodosOsPedidos` (`px='$3' py='$1'`), `RefreshControl` (o mesmo de hoje, com `refreshing={todos.carregando}` e `onRefresh={todos.recarregar}`), `ListHeaderComponent` com o aviso `t('DriverOrderManagementScreen.erroTodos')` quando `todos.erro` (faixa `$perigoSuave`, raio 12, texto 14), `ListEmptyComponent`: `Spinner` enquanto `todos.pedidos === null`, senão o mesmo `NoOrders` com `nenhumEmAndamento`/`nenhumEmAndamentoDica`, e o mesmo `ListFooterComponent`.
  - Toque no card: `navigation.navigate('DriverMapaTab', { screen: 'MapaDoLider', params: { pedido: p.id } })`.
  - Os efeitos de hoje (push, socket, intervalos de próximos e ativos) continuam como estão.

- [ ] **Step 4: conferir tipos** — `npx tsc --noEmit -p . 2>&1 | grep -E "DriverOrderManagementScreen|CardDeTodosOsPedidos|use-todos-os-pedidos"` → nenhuma linha (o projeto pode ter erros antigos em outros arquivos; só os destes arquivos contam). Rodar `node --experimental-strip-types --test scripts/testes/*.teste.ts` → PASS.

- [ ] **Step 5:** não commitar.

---

### Task 3: aba Mapa para todos (depende da Task 1)

**Files:**
- Modify: `src/screens/MapaDoLiderScreen.tsx`, `src/navigation/DriverNavigator.tsx`

Não mexer em `DriverOrderManagementScreen.tsx`, nos componentes/hooks novos da Task 2 nem nas traduções.

- [ ] **Step 1: navegador** — em `src/navigation/DriverNavigator.tsx`:
  - `DriverMapaTab.options`: tirar o `useEhLider`, o `tabBarButton` e o `tabBarItemStyle`; fica `{ tabBarLabel: rotuloDaAba('DriverMapaTab'), headerShown: false }`. Atualizar o comentário: a aba aparece para todos; o aviso de "não use `if` aqui" continua (vale para qualquer aba). Ajustar o comentário da linha ~108 ("o botão da aba Mapa só aparece para o líder") e o de `src/components/TabBar.tsx` se citar o botão escondido. Tirar o import de `useEhLider` se ficar sem uso.
  - Na pilha `DriverMapaTab`, acrescentar a tela `Order` (a mesma `OrderScreen`, `headerShown: false`), para o "Ver pedido" do motoboy comum. Conferir as linhas ~115-140: se a barra de abas some nas telas `Order`/`OrderModal` de outras abas, aplicar o mesmo na aba Mapa (`getFocusedRouteNameFromRoute(route) === 'Order'` → `tabBarStyle: { display: 'none' }`), sem `if` que mude hooks.

- [ ] **Step 2: modo pela `useEhLider`** — em `MapaDoLiderScreen.tsx`:
  - Tirar o efeito que navega para `DriverDashboardTab` quando `!ehLider`.
  - Dados do modo comum: `const { allActiveOrders } = useOrderManager();`, `const { location } = useLocation();` (de `../contexts/LocationContext`; posição em `location?.coords?.latitude/longitude`), `const { driver } = useAuth();` (`driver?.getAttribute('online')`, `driver?.getAttribute('name')`). `const mapaComum = useMemo(() => mapaDoMotoboy(allActiveOrders ?? [], posicao, online, nome), [...])`.
  - `const mapaAtual = ehLider ? mapa : mapaComum;` — todo o desenho (alfinetes, capacetes, pílula, `pedido` selecionado, enquadrar) passa a usar `mapaAtual`. O ciclo de leitura (`useFocusEffect` com `ler`) continua só com `ehLider` (já tem o `if (!ehLider) return`).
  - Troca de modo: efeito em `ehLider` que limpa `selecionado`, `modo`, `erroNaFolha`, fecha a folha (`folhaRef.current?.close()`), zera `enquadrouRef.current = false`, e no modo comum põe `setMapa(null)` / `mapaRef.current = null` (o mapa do líder antigo não volta ao trocar de motoboy).
  - Enquadrar no modo comum: efeito em `[ehLider, mapaComum]` que, sem `ehLider`, grava `mapaRef.current = mapaComum` e chama `enquadrar(mapaComum)` (só enquadra a primeira vez por modo: o `enquadrouRef`). O botão de enquadrar continua usando `mapaRef.current`. Sem pedido e sem posição, fica a região inicial.
  - Pedido selecionado que saiu do `mapaAtual` (concluiu): mostra o `pedidoSaiu` de hoje.

- [ ] **Step 3: visual do modo comum** —
  - Topo: título `ehLider ? t('MapaDoLider.titulo') : t('MapaDoLider.tituloMotoboy')`; o selo Líder só com `ehLider`.
  - Chips de filtro: só com `ehLider` (no modo comum os alfinetes são todos os pedidos dele: `pedidosNoMapa = ehLider ? filtrarPedidos(...) : mapaAtual.pedidos`).
  - Legenda no modo comum: só a linha do alfinete (`t('MapaDoLider.meuPedido')`) e a das cores do prazo; no modo líder, como hoje.
  - Pílula: com `mapaAtual` vazio de pedidos, `nenhumPedido`; `carregando` só no modo líder (o modo comum nunca está carregando).
  - Folha no modo comum (`renderCartaoDoMotoboy`): selo do número (o `seloDoNumero` de hoje), o selo do status (`translateStatus`) e o `CronometroDoPrazo` (`import CronometroDoPrazo from '../components/entregas/CronometroDoPrazo'`, `prazo={pedido.prazo_em}`); título com a loja; as linhas "Entrega" (endereço). Sem a linha do motoboy e sem "Atualizado". Botão principal **Ver pedido** (mesmo estilo do "Trocar motoboy", ícone `faArrowRight` ou `faReceipt`): acha o pedido em `allActiveOrders` pelo id (`(o) => o.id === pedido.id`) e faz `navigation.navigate('Order', { order: encontrado.serialize() })`; sem achar, fica desabilitado.
  - O modo `troca` só existe com `ehLider` (`modo === 'troca' && pedido && ehLider`).

- [ ] **Step 4: parâmetro `pedido` (modo líder)** — `const route = useRoute(); const pedidoPedido = (route.params as any)?.pedido;`. Efeito em `[ehLider, mapa, pedidoPedido]`: com `ehLider`, `mapa` lido e `pedidoPedido`: se `mapa.pedidos` tem o id, `abrirPedido(id)`, `enquadrouRef.current = true` e `mapRef.current?.animateToRegion({ latitude, longitude, latitudeDelta: 0.02, longitudeDelta: 0.02 }, 500)`; com ou sem achar, `navigation.setParams({ pedido: undefined })`. Se o mapa ainda não foi lido, espera a leitura (o efeito roda de novo quando `mapa` muda). Se o mapa ainda não estiver pronto (`prontoRef`), o `animateToRegion` pode falhar em silêncio: tudo bem, o cartão abre do mesmo jeito.
  - Atualizar o comentário de cabeçalho da tela: a aba é de todos; o líder vê tudo, o motoboy comum só os pedidos dele (`mapaDoMotoboy`).

- [ ] **Step 5: conferir tipos** — `npx tsc --noEmit -p . 2>&1 | grep -E "MapaDoLiderScreen|DriverNavigator|TabBar"` → nenhuma linha nova em relação ao `git stash`-free baseline (rodar antes de mexer para saber os erros antigos desses arquivos, se houver). Rodar `node --experimental-strip-types --test scripts/testes/*.teste.ts` → PASS.

- [ ] **Step 6:** não commitar.

---

### Task 4: documentação (depois das Tasks 2 e 3)

**Files:**
- Modify: `C:\Users\Edgardjr\Documents\vibe coding\entregas-navigator\CLAUDE.md` e `C:\Users\Edgardjr\Documents\vibe coding\Delivery\api\app\Http\Controllers\Entregas\CLAUDE.md` (a mesma seção copiada nos dois; não é rastreado no app: só editar)

- [ ] **Step 1:** na seção **Aba Pedidos**: o líder (ou admin) vê "Meus pedidos | Todos os pedidos"; Todos lê `lider/mapa` a cada 30 s (`use-todos-os-pedidos`), card `CardDeTodosOsPedidos` (número, situação ou "Sem motoboy", prazo, loja, motoboy, endereço), mais antigo em cima (`todosOsPedidos`), toque abre o Mapa com o cartão (`params.pedido`); perdeu o papel → volta para Meus pedidos. Decisão de 2026-10-23, spec `docs/superpowers/specs/2026-10-23-pedidos-e-mapa-para-todos-design.md`.
- [ ] **Step 2:** a seção **Aba Mapa do líder** vira **Aba Mapa**: o botão aparece para todos (sem `if` nas `options`); o líder vê o de sempre; o motoboy comum vê só os pedidos dele (`allActiveOrders`) e o próprio capacete pela posição do celular (`mapaDoMotoboy` em `src/utils/mapa-do-motoboy.ts`, situação pela regra do `SituacaoDoMotoboy`), sem filtros nem troca, cartão com "Ver pedido" (tela `Order` na pilha do Mapa); a API não mudou (`lider/mapa` segue 403 para ele). Testes: `scripts/testes/mapa-do-motoboy.teste.ts`. Trocar o trecho "`useEhLider` nas `options`: `tabBarButton` nulo e `display: 'none'`" pelo comportamento novo, mantendo o aviso dos hooks.
- [ ] **Step 3:** não commitar.

---

## Verificação final (coordenador)

- `node --experimental-strip-types --test scripts/testes/*.teste.ts` → tudo PASS.
- `npx tsc --noEmit -p .` sem erro novo nos arquivos tocados.
- Revisão do diff; commits por task no `main` do app e no Delivery (doc).
- Push do app = APK novo no GitHub Actions (vira a versão mínima): confirmar com o Edgard antes.
