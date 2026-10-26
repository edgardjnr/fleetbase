// Card do pedido na lista do portal: nome do cliente (nomeDoCliente, utils/entregas-pedido.js) e motoboy de cada pedido
// (motoboysDosPedidos, utils/pedidos-no-mapa.js).
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/card-do-pedido.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { nomeDoCliente } from '../../packages/customer-portal/addon/utils/entregas-pedido.js';
import { motoboysDosPedidos } from '../../packages/customer-portal/addon/utils/pedidos-no-mapa.js';

test('nome do cliente: o nome do Local de entrega (iFood, RestaurantePro ou digitado no portal)', () => {
    assert.equal(nomeDoCliente({ name: 'Mariana S.', street1: 'R. Haddock Lobo, 595' }), 'Mariana S.');
    assert.equal(nomeDoCliente({ name: '  JOÃO PEREIRA ', street1: 'Al. Santos, 1800' }), 'JOÃO PEREIRA');
    assert.equal(nomeDoCliente({ name: 'Cliente iFood' }), 'Cliente iFood');
    // o Fleetbase monta o address como "NOME - RUA, ..." (pedido do iFood #5138)
    assert.equal(nomeDoCliente({ name: 'CAIO DINIZ SALLES', street1: 'R. RUBEM UBIDA, 150', address: 'CAIO DINIZ SALLES - R. RUBEM UBIDA, 150, APTO 72, RIBEIRÃO PRETO, SP, 14021575, BRAZIL' }), 'CAIO DINIZ SALLES');
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
