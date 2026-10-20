// Card do pedido na lista do portal: nome do cliente (nomeDoCliente, utils/entregas-pedido.js) e motoboy de cada pedido
// (motoboysDosPedidos, utils/pedidos-no-mapa.js).
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/card-do-pedido.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { nomeDoCliente, textoDoEta } from '../../packages/customer-portal/addon/utils/entregas-pedido.js';
import { motoboysDosPedidos } from '../../packages/customer-portal/addon/utils/pedidos-no-mapa.js';

test('nome do cliente: o nome do Local de entrega (iFood, RestaurantePro ou digitado no portal)', () => {
    assert.equal(nomeDoCliente({ name: 'Mariana S.', street1: 'R. Haddock Lobo, 595' }), 'Mariana S.');
    assert.equal(nomeDoCliente({ name: '  JOÃO PEREIRA ', street1: 'Al. Santos, 1800' }), 'JOÃO PEREIRA');
    assert.equal(nomeDoCliente({ name: 'Cliente iFood' }), 'Cliente iFood');
});

test('nome do cliente: sem nome, ou com o próprio endereço no lugar do nome, nada', () => {
    assert.equal(nomeDoCliente({ name: null, street1: 'R. Augusta, 10' }), null);
    assert.equal(nomeDoCliente({ name: '   ', street1: 'R. Augusta, 10' }), null);
    assert.equal(nomeDoCliente({ name: 'r. augusta, 10', street1: 'R. Augusta, 10' }), null);
    assert.equal(nomeDoCliente({ name: 'R. Augusta, 10 - Consolação', address: 'R. Augusta, 10 - Consolação, São Paulo' }), null);
    assert.equal(nomeDoCliente({ name: 'Rua X', street1: 'Rua X', address: 'Rua X, Centro' }), null);
    assert.equal(nomeDoCliente(null), null);
    assert.equal(nomeDoCliente(undefined), null);
    assert.equal(nomeDoCliente({ name: 42 }), null);
});

const motoboys = [
    { id: 'm1', nome: 'Carlos', situacao: 'entrega', pedidos: ['order_a'] },
    { id: 'm2', nome: 'Juliana', situacao: 'coleta', pedidos: ['order_b', 'order_c'] },
    { id: 'm3', nome: 'Marcos', situacao: 'livre', pedidos: [] },
];

test('motoboy de cada pedido: só o aceito, com a situação do capacete', () => {
    const pedidos = [
        { id: 'order_a', motoboy: 'Carlos', aceito: true },
        { id: 'order_b', motoboy: 'Juliana', aceito: true },
        { id: 'order_d', motoboy: 'Pedro', aceito: false },
        { id: 'order_e', motoboy: null, aceito: false },
    ];
    assert.deepEqual(motoboysDosPedidos(pedidos, motoboys), {
        order_a: { nome: 'Carlos', situacao: 'entrega' },
        order_b: { nome: 'Juliana', situacao: 'coleta' },
    });
});

test('motoboy de cada pedido: aceito mas fora da lista de motoboys fica sem situação', () => {
    assert.deepEqual(motoboysDosPedidos([{ id: 'order_x', motoboy: 'Ana', aceito: true }], motoboys), { order_x: { nome: 'Ana', situacao: null } });
});

test('motoboy de cada pedido: resposta vazia ou estranha', () => {
    assert.deepEqual(motoboysDosPedidos(undefined, undefined), {});
    assert.deepEqual(motoboysDosPedidos([null, { motoboy: 'Sem id', aceito: true }, { id: '', motoboy: 'X', aceito: true }], motoboys), {});
    assert.deepEqual(motoboysDosPedidos([{ id: 'order_a', motoboy: '  ', aceito: true }], motoboys), {});
});

test('tempo até a entrega no card: o eta do Fleet-Ops é um objeto (segundos), nunca "[object Object]"', () => {
    // o recurso v1 do pedido traz o eta do TrackingIntelligenceService: { completion_seconds, active_stop_seconds, ... }
    assert.equal(textoDoEta([{ completion_seconds: 1500, active_stop_seconds: 300 }]), '25 min');
    assert.equal(textoDoEta([{ completion_seconds: null, active_stop_seconds: 90 }]), '2 min');
    assert.equal(textoDoEta([{ completion_seconds: 20 }]), '1 min');
    assert.equal(textoDoEta([{ completion_seconds: null, active_stop_seconds: null, provider: 'osrm' }]), null);
    assert.equal(textoDoEta([{ completion_seconds: 0 }]), null);
    // o primeiro valor útil da lista vale; objeto sem segundos passa ao próximo
    assert.equal(textoDoEta([{ provider: 'osrm' }, undefined, { completion_seconds: 600 }]), '10 min');
    // número = segundos; texto livre fica como veio; vazio, NaN e negativos não aparecem
    assert.equal(textoDoEta([480]), '8 min');
    assert.equal(textoDoEta(['12 min']), '12 min');
    // data ISO (eta_at, estimated_arrival_at): vira a hora de chegada, HH:mm no fuso do navegador
    assert.match(textoDoEta(['2026-10-20T05:10:00-03:00']), /^\d{2}:\d{2}$/);
    assert.equal(textoDoEta(['', NaN, -5, null, undefined, [], {}]), null);
    assert.equal(textoDoEta([]), null);
    assert.equal(textoDoEta(undefined), null);
});
