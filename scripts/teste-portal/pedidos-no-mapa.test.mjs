// Alfinetes dos pedidos da loja no mapa do portal (packages/customer-portal/addon/utils/pedidos-no-mapa.js).
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ALFINETE, nomeNoAlfinete, mesmaLista, pedidosValidos, semOPedidoAberto, tempoDesde } from '../../packages/customer-portal/addon/utils/pedidos-no-mapa.js';
import { ALFINETE as ALFINETE_DO_CONSOLE } from '../../packages/fleetops/addon/utils/entregas-pedidos-no-mapa.js';

test('o mesmo alfinete do console', () => {
    assert.deepEqual(ALFINETE, ALFINETE_DO_CONSOLE);
});

test('pedidos válidos: com id e coordenada no mapa, em número', () => {
    const lista = pedidosValidos([
        { id: 'order_a', latitude: '-21.18', longitude: '-47.81' },
        { id: 'order_zero', latitude: 0, longitude: 0 },
        { latitude: -21.1, longitude: -47.8 },
        null,
    ]);
    assert.deepEqual(lista, [{ id: 'order_a', latitude: -21.18, longitude: -47.81 }]);
    assert.deepEqual(pedidosValidos(undefined), []);
});

test('o pedido aberto no detalhe sai (a rota já mostra P e D)', () => {
    const pedidos = [{ id: 'order_a' }, { id: 'order_b' }];
    assert.deepEqual(semOPedidoAberto(pedidos, 'order_a'), [{ id: 'order_b' }]);
    assert.equal(semOPedidoAberto(pedidos, null), pedidos);
    assert.equal(semOPedidoAberto(pedidos, undefined), pedidos);
});

test('mesma lista e tempo desde a criação', () => {
    assert.equal(mesmaLista([{ id: 'a' }], [{ id: 'a' }]), true);
    assert.equal(mesmaLista([{ id: 'a' }], [{ id: 'b' }]), false);
    const agora = Date.parse('2026-10-05T12:00:00Z');
    assert.deepEqual(tempoDesde('2026-10-05T11:48:00Z', agora), { unidade: 'min', n: 12 });
    assert.deepEqual(tempoDesde('2026-10-05T09:30:00Z', agora), { unidade: 'h', n: 2 });
    assert.deepEqual(tempoDesde('2026-10-05T11:59:40Z', agora), { unidade: 'agora', n: 0 });
    assert.equal(tempoDesde(undefined, agora), null);
});

test('nome fixo no alfinete: só com o pedido aceito por um motoboy', () => {
    assert.equal(nomeNoAlfinete({ motoboy: 'João', aceito: true }), 'João');
    assert.equal(nomeNoAlfinete({ motoboy: '  João  ', aceito: true }), 'João');
    assert.equal(nomeNoAlfinete({ motoboy: 'João', aceito: false }), null, 'atribuído pela central, ainda não aceito');
    assert.equal(nomeNoAlfinete({ motoboy: 'João' }), null, 'API sem o campo aceito');
    assert.equal(nomeNoAlfinete({ motoboy: null, aceito: true }), null);
    assert.equal(nomeNoAlfinete({ motoboy: '   ', aceito: true }), null);
    assert.equal(nomeNoAlfinete(null), null);
});
