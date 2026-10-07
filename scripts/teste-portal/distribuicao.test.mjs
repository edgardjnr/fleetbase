// Distribuição de pedidos abertos no console (packages/fleetops/addon/utils/distribuicao.js): o painel do detalhe do pedido.
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/distribuicao.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { FASES, MOTIVOS, RESPOSTAS, chaveDaFase, chaveDoMotivo, chaveDaResposta, minutos, podeAbrir, segundosRestantes } from '../../packages/fleetops/addon/utils/distribuicao.js';

test('chaves de tradução: conhecidas e desconhecidas', () => {
    assert.deepEqual(FASES, ['ofertas', 'aberta', 'encerrada']);
    assert.equal(chaveDaFase('ofertas'), 'ofertas');
    assert.equal(chaveDaFase('outra'), 'desconhecida');
    assert.ok(MOTIVOS.includes('aberta_pela_central') && MOTIVOS.includes('redespachada') && MOTIVOS.includes('falha'));
    assert.equal(chaveDoMotivo('fila_esgotada'), 'fila_esgotada');
    assert.equal(chaveDoMotivo('falha'), 'falha');
    assert.equal(chaveDoMotivo(null), null);
    assert.equal(chaveDoMotivo('x'), 'desconhecido');
    assert.deepEqual(RESPOSTAS, ['pendente', 'aceita', 'recusada', 'vencida', 'cancelada']);
    assert.equal(chaveDaResposta('vencida'), 'vencida');
    assert.equal(chaveDaResposta('x'), 'desconhecida');
});

test('segundos restantes da oferta', () => {
    const agora = Date.parse('2026-10-07T13:00:00Z');
    assert.equal(segundosRestantes('2026-10-07T10:00:30-03:00', agora), 30);
    assert.equal(segundosRestantes('2026-10-07T10:00:00.400-03:00', agora), 1, 'arredonda para cima');
    assert.equal(segundosRestantes('2026-10-07T09:59:00-03:00', agora), 0, 'venceu: 0');
    assert.equal(segundosRestantes(null, agora), 0);
    assert.equal(segundosRestantes('lixo', agora), 0);
});

test('minutos do tempo estimado', () => {
    assert.equal(minutos(0), 0);
    assert.equal(minutos(59), 1);
    assert.equal(minutos(600), 10);
    assert.equal(minutos(null), null);
});

test('pode abrir a todos: só em ofertas e com pedido não encerrado', () => {
    assert.equal(podeAbrir({ fase: 'ofertas' }, 'dispatched'), true);
    assert.equal(podeAbrir({ fase: 'aberta' }, 'dispatched'), false);
    assert.equal(podeAbrir({ fase: 'ofertas' }, 'canceled'), false);
    assert.equal(podeAbrir(null, 'dispatched'), false);
});
