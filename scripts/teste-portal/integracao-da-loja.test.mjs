// Bloco "Integração" do card da loja na tela Lojas (packages/fleetops/addon/utils/integracao-da-loja.js).
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/integracao-da-loja.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { situacaoDaIntegracao, tempoDesde } from '../../packages/fleetops/addon/utils/integracao-da-loja.js';

test('situação do bloco', () => {
    assert.equal(situacaoDaIntegracao(null), 'sem-chave');
    assert.equal(situacaoDaIntegracao({ ligada: true, ativa: false }), 'sem-chave');
    assert.equal(situacaoDaIntegracao({ ligada: true, ativa: true, conectada: false }), 'aguardando');
    assert.equal(situacaoDaIntegracao({ ligada: true, ativa: true, conectada: true }), 'conectada');
    assert.equal(situacaoDaIntegracao({ ligada: false, ativa: true, conectada: true }), 'conectada', 'desligada no servidor: a situação da chave não muda');
});

test('tempo desde', () => {
    const agora = Date.parse('2026-10-12T12:00:00-03:00');
    assert.deepEqual(tempoDesde('2026-10-12T11:59:30-03:00', agora), { unidade: 'agora', n: 0 });
    assert.deepEqual(tempoDesde('2026-10-12T11:57:00-03:00', agora), { unidade: 'minutos', n: 3 });
    assert.deepEqual(tempoDesde('2026-10-12T11:00:01-03:00', agora), { unidade: 'minutos', n: 59 });
    assert.deepEqual(tempoDesde('2026-10-12T09:00:00-03:00', agora), { unidade: 'horas', n: 3 });
    assert.deepEqual(tempoDesde('2026-10-10T11:00:00-03:00', agora), { unidade: 'dias', n: 2 });
    assert.deepEqual(tempoDesde('2026-10-12T12:05:00-03:00', agora), { unidade: 'agora', n: 0 }, 'relógio do PC atrasado: nunca negativo');
    assert.equal(tempoDesde(null, agora), null);
    assert.equal(tempoDesde('', agora), null);
    assert.equal(tempoDesde('não é data', agora), null);
});
