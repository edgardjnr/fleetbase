// Funções puras do mapa de motoboys do portal (packages/customer-portal/addon/utils/motoboys-no-mapa.js).
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    CAPACETES,
    DESLIZE_MS,
    SALTO_METROS,
    capaceteDaSituacao,
    coordenadaValida,
    distanciaEmMetros,
    deveSaltar,
    interpolar,
    motoboysValidos,
    motoboyDoPedido,
} from '../../packages/customer-portal/addon/utils/motoboys-no-mapa.js';
import { INTERVALO_MAPA_MS } from '../../packages/customer-portal/addon/utils/entregas-pedido.js';

test('capacete de cada situação, com as imagens do mapa do console', () => {
    assert.equal(capaceteDaSituacao('livre'), '/engines-dist/images/capacete-verde.png');
    assert.equal(capaceteDaSituacao('coleta'), '/engines-dist/images/capacete-amarelo.png');
    assert.equal(capaceteDaSituacao('entrega'), '/engines-dist/images/capacete-vermelho.png');
    assert.equal(capaceteDaSituacao('offline'), null, 'offline não aparece no portal');
    assert.equal(capaceteDaSituacao(undefined), null);
    assert.deepEqual(Object.keys(CAPACETES), ['livre', 'coleta', 'entrega']);
});

test('coordenada válida: número finito, dentro da faixa e fora do (0, 0)', () => {
    assert.equal(coordenadaValida(-21.17, -47.81), true);
    assert.equal(coordenadaValida('-21.17', '-47.81'), true, 'texto numérico vale');
    assert.equal(coordenadaValida(0, 0), false, '(0, 0) é o sem GPS do Fleetbase');
    assert.equal(coordenadaValida(0.00005, -0.00005), false);
    assert.equal(coordenadaValida(null, -47.81), false);
    assert.equal(coordenadaValida('', -47.81), false);
    assert.equal(coordenadaValida(-21.17, undefined), false);
    assert.equal(coordenadaValida(91, 10), false);
    assert.equal(coordenadaValida(10, 181), false);
    assert.equal(coordenadaValida(Number.NaN, 10), false);
    assert.equal(coordenadaValida('abc', 10), false);
});

test('distância em metros (haversine)', () => {
    assert.equal(distanciaEmMetros([-21.17, -47.81], [-21.17, -47.81]), 0);
    // 0,01° de latitude ≈ 1.112 m
    const metros = distanciaEmMetros([-21.17, -47.81], [-21.18, -47.81]);
    assert.ok(metros > 1100 && metros < 1125, `esperava ~1.112 m, veio ${metros}`);
});

test('salto ou deslize', () => {
    const aqui = [-21.17, -47.81];
    const perto = [-21.1705, -47.81]; // ~56 m
    const longe = [-21.18, -47.81]; // ~1,1 km
    assert.equal(deveSaltar(null, aqui), true, 'primeira posição salta');
    assert.equal(deveSaltar(aqui, perto), false, 'perto desliza');
    assert.equal(deveSaltar(aqui, longe), true, `mais de ${SALTO_METROS} m salta`);
    assert.equal(deveSaltar(aqui, perto, { reduzirMovimento: true }), true, 'menos animação: salta');
});

test('interpolação em linha reta, com a fração limitada a 0..1', () => {
    assert.deepEqual(interpolar([0, 0], [10, 20], 0), [0, 0]);
    assert.deepEqual(interpolar([0, 0], [10, 20], 0.5), [5, 10]);
    assert.deepEqual(interpolar([0, 0], [10, 20], 1), [10, 20]);
    assert.deepEqual(interpolar([0, 0], [10, 20], 1.7), [10, 20]);
    assert.deepEqual(interpolar([0, 0], [10, 20], -1), [0, 0]);
});

test('motoboys válidos: com id, situação conhecida e coordenada válida', () => {
    const lista = [
        { id: 'a1', nome: 'Ana', latitude: -21.17, longitude: -47.81, situacao: 'livre', pedidos: [] },
        { id: 'b2', nome: 'Bia', latitude: 0, longitude: 0, situacao: 'livre', pedidos: [] },
        { id: 'c3', nome: 'Caio', latitude: -21.18, longitude: -47.82, situacao: 'offline', pedidos: [] },
        { id: '', nome: 'Sem id', latitude: -21.18, longitude: -47.82, situacao: 'coleta', pedidos: [] },
        null,
        { id: 'd4', nome: 'Davi', latitude: '-21.19', longitude: '-47.83', situacao: 'entrega', pedidos: ['order_x'] },
    ];
    assert.deepEqual(
        motoboysValidos(lista).map((motoboy) => motoboy.id),
        ['a1', 'd4']
    );
    assert.deepEqual(motoboysValidos(undefined), []);
    assert.deepEqual(motoboysValidos({ motoboys: [] }), []);
});

test('motoboy do pedido aberto, pelo public_id em pedidos', () => {
    const lista = [{ id: 'a1', pedidos: [] }, { id: 'b2', pedidos: ['order_x', 'order_y'] }, { id: 'c3' }];
    assert.equal(motoboyDoPedido(lista, 'order_y')?.id, 'b2');
    assert.equal(motoboyDoPedido(lista, 'order_z'), null);
    assert.equal(motoboyDoPedido(lista, null), null);
    assert.equal(motoboyDoPedido(undefined, 'order_x'), null);
});

test('intervalos: consulta de 5 s e deslize um pouco menor', () => {
    assert.equal(INTERVALO_MAPA_MS, 5000);
    assert.ok(DESLIZE_MS < INTERVALO_MAPA_MS);
});
