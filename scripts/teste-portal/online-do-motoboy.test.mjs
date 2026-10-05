// Online do motoboy na lista "Operações ao vivo" do console (packages/fleetops/addon/utils/entregas-online-do-motoboy.js).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import aplicarOnlineDoMotoboy from '../../packages/fleetops/addon/utils/entregas-online-do-motoboy.js';

function storeCom(motoboys) {
    const pushes = [];
    return {
        pushes,
        peekRecord: (tipo, id) => (tipo === 'driver' ? (motoboys.find((m) => m.id === id) ?? null) : null),
        peekAll: (tipo) => (tipo === 'driver' ? motoboys : []),
        push(payload) {
            pushes.push(payload);
            const motoboy = motoboys.find((m) => m.id === payload.data.id);
            Object.assign(motoboy, payload.data.attributes);
        },
    };
}

test('evento do socket liga o online pelo uuid', () => {
    const motoboy = { id: 'uuid-1', public_id: 'driver_a', online: false };
    const store = storeCom([motoboy]);
    assert.equal(aplicarOnlineDoMotoboy(store, { id: 'driver_a', uuid: 'uuid-1', online: true }), true);
    assert.equal(motoboy.online, true);
    assert.deepEqual(store.pushes, [{ data: { id: 'uuid-1', type: 'driver', attributes: { online: true } } }]);
});

test('acha pelo public_id quando o uuid não está no store', () => {
    const motoboy = { id: 'uuid-1', public_id: 'driver_a', online: true };
    const store = storeCom([motoboy]);
    assert.equal(aplicarOnlineDoMotoboy(store, { public_id: 'driver_a', online: false }), true);
    assert.equal(motoboy.online, false);
});

test('sem mudança, motoboy fora do store ou sem online: não grava', () => {
    const motoboy = { id: 'uuid-1', public_id: 'driver_a', online: true };
    const store = storeCom([motoboy]);
    assert.equal(aplicarOnlineDoMotoboy(store, { uuid: 'uuid-1', online: true }), false);
    assert.equal(aplicarOnlineDoMotoboy(store, { uuid: 'uuid-2', id: 'driver_b', online: false }), false);
    assert.equal(aplicarOnlineDoMotoboy(store, { uuid: 'uuid-1' }), false);
    assert.equal(aplicarOnlineDoMotoboy(null, { uuid: 'uuid-1', online: false }), false);
    assert.equal(store.pushes.length, 0);
});

test('online nulo no registro conta como offline', () => {
    const motoboy = { id: 'uuid-1', public_id: 'driver_a', online: null };
    const store = storeCom([motoboy]);
    assert.equal(aplicarOnlineDoMotoboy(store, { uuid: 'uuid-1', online: false }), false);
    assert.equal(aplicarOnlineDoMotoboy(store, { uuid: 'uuid-1', online: true }), true);
});
