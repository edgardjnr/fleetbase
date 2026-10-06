// Pedido do iFood no console (packages/fleetops/addon/utils/pedido-ifood.js) e no portal da loja
// (packages/customer-portal/addon/utils/entregas-pedido.js, numeroIfood).
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/pedido-ifood.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { algumPedidoIfood, avisoAcaoRecusada, ehPedidoIfood, mostraTroco, numeroIfood, numeroIfoodDoPedido, partesDaForma, reais } from '../../packages/fleetops/addon/utils/pedido-ifood.js';
import { numeroIfood as numeroIfoodNoPortal } from '../../packages/customer-portal/addon/utils/entregas-pedido.js';

test('número do iFood: notas "iFood #<n>" e o internal_id igual', () => {
    assert.equal(numeroIfood('iFood #4821', '4821'), '4821');
    assert.equal(numeroIfood('iFood #4821 [TESTE]', '4821'), '4821');
    assert.equal(numeroIfood('  iFood #4821 [SEM LOCALIZAÇÃO]', 4821), '4821');
    assert.equal(numeroIfood('iFood #4821', '9999'), null, 'internal_id diferente: não é');
    assert.equal(numeroIfood('Entregar no portão', '4821'), null);
    assert.equal(numeroIfood('iFood #4821', null), null);
    assert.equal(numeroIfood(null, '4821'), null);
});

test('pedido (model ou objeto)', () => {
    assert.equal(numeroIfoodDoPedido({ notes: 'iFood #77', internal_id: '77' }), '77');
    assert.equal(ehPedidoIfood({ notes: 'iFood #77', internal_id: '77' }), true);
    assert.equal(ehPedidoIfood({ notes: null, internal_id: null }), false);
    assert.equal(ehPedidoIfood(null), false);
    assert.equal(algumPedidoIfood([{ notes: '', internal_id: null }, { notes: 'iFood #1', internal_id: '1' }]), true);
    assert.equal(algumPedidoIfood([{ notes: '', internal_id: null }]), false);
    assert.equal(algumPedidoIfood(undefined), false);
});

test('aviso de ação recusada', () => {
    assert.deepEqual(avisoAcaoRecusada({ event: 'entregas.ifood_acao_recusada', data: { id: 'order_a', uuid: 'u-a', numero: '4821', acao: 'dispatch', status: 409 } }), {
        id: 'order_a',
        uuid: 'u-a',
        numero: '4821',
        acao: 'dispatch',
        status: 409,
    });
    assert.deepEqual(avisoAcaoRecusada({ event: 'entregas.ifood_acao_recusada', data: { id: 'order_a', acao: 'outra', status: 'x' } }), {
        id: 'order_a',
        uuid: null,
        numero: 'order_a',
        acao: 'desconhecida',
        status: 0,
    });
    assert.equal(avisoAcaoRecusada({ event: 'order.updated', data: { id: 'order_a' } }), null);
    assert.equal(avisoAcaoRecusada({ event: 'entregas.ifood_acao_recusada', data: {} }), null);
    assert.equal(avisoAcaoRecusada(null), null);
});

test('cobrança: reais, formas e troco', () => {
    assert.equal(reais(5890), 'R$ 58,90');
    assert.equal(reais(123456), 'R$ 1.234,56');
    assert.equal(reais(10000, true), 'R$ 100');
    assert.equal(reais(10050, true), 'R$ 100,50');
    assert.deepEqual(partesDaForma('CASH+CREDIT'), [{ chave: 'dinheiro' }, { chave: 'credito' }]);
    assert.deepEqual(partesDaForma('GIFT_CARD+OTHER'), [{ chave: 'vale-presente' }, { chave: 'outra-forma' }]);
    assert.deepEqual(partesDaForma('BOLETO_X'), [{ texto: 'boleto_x' }]);
    assert.deepEqual(partesDaForma(null), []);
    assert.equal(mostraTroco(5890, 'CASH', 10000), true);
    assert.equal(mostraTroco(5890, 'CASH', 5000), false);
    assert.equal(mostraTroco(5890, 'MISTO', 10000), true, 'o troco aparece sempre que existe (o servidor só o grava vindo do dinheiro)');
    assert.equal(mostraTroco(5890, 'CASH', 5890), false);
    assert.equal(mostraTroco(5890, 'CASH', null), false);
});

test('portal: o mesmo número do iFood', () => {
    assert.equal(numeroIfoodNoPortal('iFood #4821 [TESTE]', '4821'), '4821');
    assert.equal(numeroIfoodNoPortal('iFood #4821', '1'), null);
    assert.equal(numeroIfoodNoPortal(undefined, undefined), null);
});
