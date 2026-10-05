// Alfinetes dos pedidos em andamento no mapa ao vivo do console (packages/fleetops/addon/utils/entregas-pedidos-no-mapa.js).
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ALFINETE, mesmaLista, pedidosValidos, tempoDesde } from '../../packages/fleetops/addon/utils/entregas-pedidos-no-mapa.js';

test('alfinete vermelho em SVG, com a ponta embaixo no meio', () => {
    assert.match(ALFINETE.url, /^data:image\/svg\+xml;charset=UTF-8,/);
    assert.match(decodeURIComponent(ALFINETE.url), /#dc2626/);
    assert.deepEqual(ALFINETE.tamanho, [26, 36]);
    assert.deepEqual(ALFINETE.ponta, [13, 35]);
});

test('pedidos válidos: com id e coordenada no mapa, em número', () => {
    const lista = pedidosValidos([
        { id: 'order_a', latitude: '-21.18', longitude: '-47.81', status: 'started' },
        { id: 'order_zero', latitude: 0, longitude: 0 },
        { id: '', latitude: -21.1, longitude: -47.8 },
        { latitude: -21.1, longitude: -47.8 },
        { id: 'order_fora', latitude: 91, longitude: 10 },
        { id: 'order_nulo', latitude: null, longitude: -47.8 },
        null,
    ]);
    assert.deepEqual(lista, [{ id: 'order_a', latitude: -21.18, longitude: -47.81, status: 'started' }]);
    assert.deepEqual(pedidosValidos(undefined), [], 'resposta de API antiga, sem pedidos');
    assert.deepEqual(pedidosValidos('x'), []);
});

test('mesma lista: compara o conteúdo', () => {
    assert.equal(mesmaLista([{ id: 'a', status: 'started' }], [{ id: 'a', status: 'started' }]), true);
    assert.equal(mesmaLista([{ id: 'a', status: 'started' }], [{ id: 'a', status: 'enroute' }]), false);
    assert.equal(mesmaLista([], []), true);
});

test('tempo desde a criação: agora, minutos e horas', () => {
    const agora = Date.parse('2026-10-05T12:00:00Z');
    assert.deepEqual(tempoDesde('2026-10-05T11:59:40Z', agora), { unidade: 'agora', n: 0 });
    assert.deepEqual(tempoDesde('2026-10-05T11:48:00Z', agora), { unidade: 'min', n: 12 });
    assert.deepEqual(tempoDesde('2026-10-05T11:00:30Z', agora), { unidade: 'min', n: 59 });
    assert.deepEqual(tempoDesde('2026-10-05T09:30:00Z', agora), { unidade: 'h', n: 2 });
    assert.deepEqual(tempoDesde('2026-10-05T12:05:00Z', agora), { unidade: 'agora', n: 0 }, 'relógio adiantado do servidor');
    assert.equal(tempoDesde(null, agora), null);
    assert.equal(tempoDesde('xx', agora), null);
});
