// Lista de pedidos ao lado do mapa do portal (pedidosEmAndamento, packages/customer-portal/addon/utils/entregas-pedido.js).
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { pedidosEmAndamento } from '../../packages/customer-portal/addon/utils/entregas-pedido.js';

const pedido = (id, status) => ({ id, status });

test('tira concluídos, cancelados e expirados e mantém a ordem dos demais', () => {
    const pedidos = [
        pedido(1, 'created'),
        pedido(2, 'completed'),
        pedido(3, 'dispatched'),
        pedido(4, 'canceled'),
        pedido(5, 'cancelled'),
        pedido(6, 'order_canceled'),
        pedido(7, 'enroute'),
        pedido(8, 'done'),
        pedido(9, 'expired'),
        pedido(10, 'started'),
    ];

    assert.deepEqual(
        pedidosEmAndamento(pedidos).map((p) => p.id),
        [1, 3, 7, 10]
    );
});

test('lista vazia ou ausente vira lista vazia', () => {
    assert.deepEqual(pedidosEmAndamento(undefined), []);
    assert.deepEqual(pedidosEmAndamento(null), []);
    assert.deepEqual(pedidosEmAndamento([]), []);
});

test('lê o status pela função informada (model do Ember Data)', () => {
    const model = (id, status) => ({ id, get: (campo) => (campo === 'status' ? status : undefined) });
    const pedidos = [model(1, 'completed'), model(2, 'created')];

    assert.deepEqual(
        pedidosEmAndamento(pedidos, (p) => p.get('status')).map((p) => p.id),
        [2]
    );
});
