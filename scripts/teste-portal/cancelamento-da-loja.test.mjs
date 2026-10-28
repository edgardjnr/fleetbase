// Cancelar do portal da loja (lojaPodeCancelar, packages/customer-portal/addon/utils/entregas-pedido.js): a loja cancela até o
// motoboy sair para a entrega (decisão de 2026-10-28). Mesma regra do servidor (RegrasPortalLoja::lojaPodeCancelar).
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ENCERRADOS, ESPERANDO_MOTOBOY, STATUS_EM_ENTREGA, lojaPodeCancelar } from '../../packages/customer-portal/addon/utils/entregas-pedido.js';

test('STATUS_EM_ENTREGA é a cópia de SituacaoDoMotoboy::STATUS_EM_ENTREGA', () => {
    assert.deepEqual(STATUS_EM_ENTREGA, ['enroute', 'picked_up', 'dropping_off', 'in_progress']);
});

test('criado, despachado e aceito (indo à loja): pode cancelar', () => {
    for (const status of ['created', 'dispatched', 'started']) {
        assert.equal(lojaPodeCancelar({ status }), true, status);
    }
    assert.equal(lojaPodeCancelar({ status: 'started', started: true }), true, 'aceito com o started ligado');
});

test('a caminho do cliente: não pode', () => {
    for (const status of STATUS_EM_ENTREGA) {
        assert.equal(lojaPodeCancelar({ status }), false, status);
    }
});

test('encerrado: não pode', () => {
    for (const status of ENCERRADOS) {
        assert.equal(lojaPodeCancelar({ status }), false, status);
    }
});

test('status comparado em minúsculas', () => {
    assert.equal(lojaPodeCancelar({ status: 'STARTED' }), true);
    assert.equal(lojaPodeCancelar({ status: 'Enroute' }), false);
    assert.equal(lojaPodeCancelar({ status: 'COMPLETED' }), false);
});

test('sem pedido ou sem status: não pode', () => {
    assert.equal(lojaPodeCancelar(null), false);
    assert.equal(lojaPodeCancelar(undefined), false);
    assert.equal(lojaPodeCancelar({}), false);
    assert.equal(lojaPodeCancelar({ status: '' }), false);
});

test('ESPERANDO_MOTOBOY (o "procurando motoboy" do card) continua criado e despachado', () => {
    assert.deepEqual(ESPERANDO_MOTOBOY, ['created', 'dispatched']);
});
