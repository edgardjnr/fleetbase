// Origem do pedido (iFood ou outro sistema pela integração) no console (packages/fleetops/addon/utils/pedido-de-origem.js).
// Os casos do portal entram na Task 7.
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/pedido-de-origem.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    chaveDoAviso,
    chaveDoCanceladoPago,
    chaveDoCanceladoPor,
    ehPedidoDaIntegracao,
    origemDoModelo,
    origemDoPedido,
} from '../../packages/fleetops/addon/utils/pedido-de-origem.js';
import { numeroIfood } from '../../packages/fleetops/addon/utils/pedido-ifood.js';

test('integração: notas "<Sistema> #<n>" e o internal_id igual', () => {
    assert.deepEqual(origemDoPedido('RestaurantePro #4821', '4821'), { ifood: false, sistema: 'RestaurantePro', numero: '4821' });
    assert.deepEqual(origemDoPedido('RestaurantePro #4821 [SEM LOCALIZAÇÃO]', 4821), { ifood: false, sistema: 'RestaurantePro', numero: '4821' });
    assert.deepEqual(origemDoPedido('RestaurantePro #4821\nSem cebola', '4821'), { ifood: false, sistema: 'RestaurantePro', numero: '4821' });
    assert.deepEqual(origemDoPedido('Anota AI #77', '77'), { ifood: false, sistema: 'Anota AI', numero: '77' });
    assert.deepEqual(origemDoPedido('Integração #9', '9'), { ifood: false, sistema: 'Integração', numero: '9' });
    assert.deepEqual(origemDoPedido('  Sis_tema-2.0 #A1  ', 'A1'), { ifood: false, sistema: 'Sis_tema-2.0', numero: 'A1' });
});

test('iFood: as mesmas notas do selo da etapa 4', () => {
    assert.deepEqual(origemDoPedido('iFood #4821', '4821'), { ifood: true, sistema: 'iFood', numero: '4821' });
    assert.deepEqual(origemDoPedido('iFood #4821 [TESTE]', '4821'), { ifood: true, sistema: 'iFood', numero: '4821' });
});

test('não é pedido de origem', () => {
    assert.equal(origemDoPedido('RestaurantePro #4821', '9999'), null, 'internal_id diferente');
    assert.equal(origemDoPedido('RestaurantePro #4821', null), null);
    assert.equal(origemDoPedido('RestaurantePro #4821', ''), null);
    assert.equal(origemDoPedido(null, '4821'), null);
    assert.equal(origemDoPedido('Entregar no portão', '4821'), null);
    assert.equal(origemDoPedido('Obs: #12', '12'), null, 'dois-pontos não entram no nome do sistema');
    assert.equal(origemDoPedido('IFOOD falso #12', '12'), null, 'o servidor nunca grava sistema começando com ifood');
    assert.equal(origemDoPedido(' #12', '12'), null, 'sem nome');
    assert.equal(origemDoPedido(`${'a'.repeat(41)} #12`, '12'), null, 'nome com mais de 40 caracteres');
});

test('mesma resposta do numeroIfood para o iFood', () => {
    const casos = [
        ['iFood #4821', '4821'],
        ['iFood #4821 [TESTE]', '4821'],
        ['iFood #4821', '1'],
        ['RestaurantePro #4821', '4821'],
        ['Entregar no portão', '4821'],
    ];
    for (const [notas, id] of casos) {
        const origem = origemDoPedido(notas, id);
        assert.equal(origem?.ifood ? origem.numero : null, numeroIfood(notas, id), `${notas} / ${id}`);
    }
});

test('modelo do Ember ou objeto', () => {
    assert.deepEqual(origemDoModelo({ notes: 'RestaurantePro #5', internal_id: '5' }), { ifood: false, sistema: 'RestaurantePro', numero: '5' });
    assert.equal(origemDoModelo(null), null);
    assert.equal(ehPedidoDaIntegracao({ notes: 'RestaurantePro #5', internal_id: '5' }), true);
    assert.equal(ehPedidoDaIntegracao({ notes: 'iFood #5', internal_id: '5' }), false);
    assert.equal(ehPedidoDaIntegracao({ notes: null, internal_id: null }), false);
});

test('chaves de tradução', () => {
    assert.equal(chaveDoAviso('aceito'), 'aceito');
    assert.equal(chaveDoAviso('a_caminho'), 'a_caminho');
    assert.equal(chaveDoAviso('concluido'), 'concluido');
    assert.equal(chaveDoAviso('cancelado'), 'cancelado');
    assert.equal(chaveDoAviso('outro'), 'desconhecido');
    assert.equal(chaveDoCanceladoPor('loja'), 'loja');
    assert.equal(chaveDoCanceladoPor('central'), 'central');
    assert.equal(chaveDoCanceladoPor('sistema'), 'sistema');
    assert.equal(chaveDoCanceladoPor(null), 'desconhecido');
    assert.equal(chaveDoCanceladoPago('integracao'), 'cancelado-pago-loja');
    assert.equal(chaveDoCanceladoPago('ifood'), 'cancelado-pago');
    assert.equal(chaveDoCanceladoPago(null), 'cancelado-pago', 'API antiga: o rótulo de hoje');
    assert.equal(chaveDoCanceladoPago(undefined), 'cancelado-pago');
});

// Portal da loja: a mesma regra, cópia própria (o engine do portal não importa do fleetops)
import { chaveDoCanceladoCobrado, origemDoPedido as origemNoPortal } from '../../packages/customer-portal/addon/utils/entregas-pedido.js';

test('portal: mesma origem do console', () => {
    const casos = [
        ['RestaurantePro #4821', '4821'],
        ['RestaurantePro #4821 [SEM LOCALIZAÇÃO]', 4821],
        ['iFood #4821 [TESTE]', '4821'],
        ['Anota AI #77', '77'],
        ['IFOOD falso #12', '12'],
        ['Entregar no portão', '4821'],
        ['RestaurantePro #4821', '1'],
        [null, '1'],
        ['RestaurantePro #4821', null],
    ];
    for (const [notas, id] of casos) {
        assert.deepEqual(origemNoPortal(notas, id), origemDoPedido(notas, id), `${notas} / ${id}`);
    }
});

test('portal: rótulo do cancelado cobrado', () => {
    assert.equal(chaveDoCanceladoCobrado('integracao'), 'canceled-store-charged');
    assert.equal(chaveDoCanceladoCobrado('ifood'), 'canceled-ifood-charged');
    assert.equal(chaveDoCanceladoCobrado(null), 'canceled-ifood-charged', 'API antiga: o rótulo de hoje');
});
