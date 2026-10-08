// Posição do motoboy pelo socket no mapa do console (packages/fleetops/addon/utils/entregas-posicao-do-socket.js): do
// que se acumulou na fila do movement-tracker, só a posição mais recente é desenhada, e nunca uma anterior à última já
// desenhada. Antes, a fila inteira era reproduzida em sequência (0,55 s por evento) e, ao voltar a uma aba que ficou em
// segundo plano, o capacete corria por todo o trajeto perdido.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { instanteDoEvento, paginaOculta, posicaoMaisRecente } from '../../packages/fleetops/addon/utils/entregas-posicao-do-socket.js';

const evento = (criadoEm, lat, lng, extra = {}) => ({
    event: 'driver.location_changed',
    created_at: criadoEm,
    data: { id: 'driver_a', location: { type: 'Point', coordinates: [lng, lat] }, ...extra },
});

test('instante do created_at do servidor ("Y-m-d H:i:s") e de ISO', () => {
    assert.equal(instanteDoEvento(evento('2026-10-07 08:27:01', 0, 0)) - instanteDoEvento(evento('2026-10-07 08:27:00', 0, 0)), 1000);
    assert.equal(instanteDoEvento(evento('2026-10-07T11:27:01.000Z', 0, 0)), Date.UTC(2026, 9, 7, 11, 27, 1));
    assert.ok(Number.isNaN(instanteDoEvento(evento('ontem', 0, 0))));
    assert.ok(Number.isNaN(instanteDoEvento({ data: {} })));
});

test('fila acumulada: desenha só a mais recente', () => {
    const fila = [evento('2026-10-07 08:20:00', -21.1, -47.8), evento('2026-10-07 08:21:00', -21.2, -47.9), evento('2026-10-07 08:22:00', -21.3, -47.7)];
    const escolhida = posicaoMaisRecente(fila);
    assert.deepEqual(escolhida.latLng, [-21.3, -47.7]);
    assert.equal(escolhida.evento, fila[2]);
    assert.equal(escolhida.instante, instanteDoEvento(fila[2]));
});

test('fora de ordem na fila: vale o created_at, não a ordem de chegada', () => {
    const fila = [evento('2026-10-07 08:22:00', -21.3, -47.7), evento('2026-10-07 08:20:00', -21.1, -47.8)];
    assert.deepEqual(posicaoMaisRecente(fila).latLng, [-21.3, -47.7]);
});

test('mesmo segundo: vale a que chegou por último', () => {
    const fila = [evento('2026-10-07 08:22:00', -21.3, -47.7), evento('2026-10-07 08:22:00', -21.31, -47.71)];
    assert.deepEqual(posicaoMaisRecente(fila).latLng, [-21.31, -47.71]);
});

test('anterior à última já desenhada: fica de fora', () => {
    const ultima = instanteDoEvento(evento('2026-10-07 08:22:00', 0, 0));
    assert.equal(posicaoMaisRecente([evento('2026-10-07 08:21:59', -21.1, -47.8)], ultima), null);
    assert.deepEqual(posicaoMaisRecente([evento('2026-10-07 08:22:00', -21.1, -47.8)], ultima).latLng, [-21.1, -47.8]);
    assert.deepEqual(posicaoMaisRecente([evento('2026-10-07 08:21:00', -21.1, -47.8), evento('2026-10-07 08:23:00', -21.2, -47.8)], ultima).latLng, [-21.2, -47.8]);
});

test('sem created_at legível: entra pela ordem de chegada e não é barrada pela última desenhada', () => {
    const ultima = instanteDoEvento(evento('2026-10-07 08:22:00', 0, 0));
    assert.deepEqual(posicaoMaisRecente([evento(undefined, -21.1, -47.8)], ultima).latLng, [-21.1, -47.8]);
    assert.ok(Number.isNaN(posicaoMaisRecente([evento(undefined, -21.1, -47.8)]).instante));
});

test('posição ilegível fica de fora; fila sem nenhuma legível = null', () => {
    const semPosicao = { event: 'driver.location_changed', created_at: '2026-10-07 08:23:00', data: { id: 'driver_a' } };
    const foraDaFaixa = evento('2026-10-07 08:24:00', 95, -47.8);
    const zerada = evento('2026-10-07 08:24:00', 0, 0);
    const texto = { ...evento('2026-10-07 08:24:00', 0, 0), data: { location: { coordinates: ['x', 'y'] } } };
    const boa = evento('2026-10-07 08:22:00', -21.1, -47.8);
    assert.deepEqual(posicaoMaisRecente([boa, semPosicao, foraDaFaixa, zerada, texto]).latLng, [-21.1, -47.8]);
    assert.equal(posicaoMaisRecente([semPosicao, foraDaFaixa]), null);
    assert.equal(posicaoMaisRecente([]), null);
    assert.equal(posicaoMaisRecente(undefined), null);
});

test('página oculta pelo document.hidden', () => {
    const antes = globalThis.document;
    try {
        globalThis.document = { hidden: true };
        assert.equal(paginaOculta(), true);
        globalThis.document = { hidden: false };
        assert.equal(paginaOculta(), false);
        delete globalThis.document;
        assert.equal(paginaOculta(), false);
    } finally {
        if (antes !== undefined) globalThis.document = antes;
    }
});
