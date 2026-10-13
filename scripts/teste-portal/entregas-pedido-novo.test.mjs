// Pedido novo da central: padrões (ad-hoc e despacho ligados) e o que falta para criar (loja e ID interno).
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/entregas-pedido-novo.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { RAIO_ADHOC_PADRAO, padroesDoPedidoNovo, pendenciasDoPedidoNovo } from '../../packages/fleetops/addon/utils/entregas-pedido-novo.js';

test('pedido novo abre com ad-hoc e despacho ligados, com o raio da empresa', () => {
    assert.deepEqual(padroesDoPedidoNovo(3000), { adhoc: true, adhoc_distance: 3000, dispatched: true });
    assert.deepEqual(padroesDoPedidoNovo('8000'), { adhoc: true, adhoc_distance: 8000, dispatched: true });
});

test('sem raio configurado (ou inválido) usa o padrão', () => {
    for (const raio of [undefined, null, '', 'abc', 0, -10]) {
        assert.equal(padroesDoPedidoNovo(raio).adhoc_distance, RAIO_ADHOC_PADRAO, `raio ${raio}`);
    }
});

test('loja escolhida e ID interno preenchido: nada falta', () => {
    assert.deepEqual(pendenciasDoPedidoNovo({ customer: { isVendor: true }, internal_id: '4821' }), []);
    assert.deepEqual(pendenciasDoPedidoNovo({ customer: { customer_type: 'vendor' }, internal_id: ' RP1 ' }), []);
    assert.deepEqual(pendenciasDoPedidoNovo({ customer: { name: 'x' }, customer_type: 'fleet-ops:vendor', internal_id: '1' }), []);
});

test('sem cliente, ou cliente que não é loja: falta a loja', () => {
    assert.deepEqual(pendenciasDoPedidoNovo({ customer: null, internal_id: '1' }), ['loja']);
    assert.deepEqual(pendenciasDoPedidoNovo({ customer: { isVendor: false, customer_type: 'contact' }, internal_id: '1' }), ['loja']);
    // o customer_type do pedido sozinho (cliente vazio) não basta
    assert.deepEqual(pendenciasDoPedidoNovo({ customer: null, customer_type: 'fleet-ops:vendor', internal_id: '1' }), ['loja']);
});

test('ID interno vazio ou só com espaços: falta o ID interno', () => {
    for (const internal_id of [undefined, null, '', '   ']) {
        assert.deepEqual(pendenciasDoPedidoNovo({ customer: { isVendor: true }, internal_id }), ['id-interno'], `id ${internal_id}`);
    }
});

test('faltando os dois, ou pedido ausente', () => {
    assert.deepEqual(pendenciasDoPedidoNovo({}), ['loja', 'id-interno']);
    assert.deepEqual(pendenciasDoPedidoNovo(undefined), ['loja', 'id-interno']);
});
