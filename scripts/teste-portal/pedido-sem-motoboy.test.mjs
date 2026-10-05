// Aviso "sem motoboy" do console (packages/fleetops/addon/utils/pedido-sem-motoboy.js).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { avisoSemMotoboy, pedidosResolvidos, EVENTO_PEDIDO_SEM_MOTOBOY } from '../../packages/fleetops/addon/utils/pedido-sem-motoboy.js';

test('lê o aviso do evento entregas.pedido_sem_motoboy', () => {
    assert.equal(EVENTO_PEDIDO_SEM_MOTOBOY, 'entregas.pedido_sem_motoboy');
    assert.deepEqual(avisoSemMotoboy({ event: 'entregas.pedido_sem_motoboy', data: { id: 'order_a', uuid: 'u-a', numero: '4821', minutos: 12 } }), {
        id: 'order_a',
        uuid: 'u-a',
        numero: '4821',
        minutos: 12,
    });
});

test('aviso sem número ou minutos: usa o id e 0', () => {
    assert.deepEqual(avisoSemMotoboy({ event: 'entregas.pedido_sem_motoboy', data: { id: 'order_a' } }), { id: 'order_a', uuid: null, numero: 'order_a', minutos: 0 });
});

test('outros eventos ou aviso sem id não são aviso', () => {
    assert.equal(avisoSemMotoboy({ event: 'order.updated', data: { id: 'order_a' } }), null);
    assert.equal(avisoSemMotoboy({ event: 'entregas.pedido_sem_motoboy', data: {} }), null);
    assert.equal(avisoSemMotoboy(null), null);
});

test('eventos que resolvem o pedido devolvem os ids dele', () => {
    for (const event of ['order.driver_assigned', 'order.started', 'order.canceled', 'order.completed', 'order.failed']) {
        assert.deepEqual(pedidosResolvidos({ event, data: { id: 'order_a' } }), ['order_a'], event);
    }
    assert.deepEqual(pedidosResolvidos({ event: 'order.canceled', data: { id: 'u-a', public_id: 'order_a', uuid: 'u-a' } }), ['u-a', 'order_a']);
});

test('outros eventos não resolvem nada', () => {
    assert.deepEqual(pedidosResolvidos({ event: 'order.updated', data: { id: 'order_a' } }), []);
    assert.deepEqual(pedidosResolvidos({ event: 'order.started', data: {} }), []);
    assert.deepEqual(pedidosResolvidos(undefined), []);
});
