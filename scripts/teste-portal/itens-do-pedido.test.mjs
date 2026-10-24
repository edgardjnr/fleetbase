// Itens do pedido no portal da loja (packages/customer-portal/addon/utils/itens-do-pedido.js), os mesmos da comanda do app.
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/itens-do-pedido.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { itensDaResposta, observacaoDaResposta, textoDasOpcoes, totalDeItens } from '../../packages/customer-portal/addon/utils/itens-do-pedido.js';

test('itens da resposta: quantidade, nome, opções em texto e a observação do item', () => {
    const resposta = {
        itens: [
            { quantidade: 2, nome: ' Pizza grande ', opcoes: [{ quantidade: 1, nome: 'Borda' }, { quantidade: 2, nome: 'Bacon' }], observacao: 'sem cebola' },
            { nome: 'Refrigerante' },
            { nome: '   ' },
            null,
        ],
    };
    assert.deepEqual(itensDaResposta(resposta), [
        { quantidade: 2, nome: 'Pizza grande', opcoes: 'Borda, 2× Bacon', observacao: 'sem cebola' },
        { quantidade: 1, nome: 'Refrigerante', opcoes: null, observacao: null },
    ]);
});

test('resposta sem itens, nula ou fora do formato: lista vazia', () => {
    assert.deepEqual(itensDaResposta({ itens: null }), []);
    assert.deepEqual(itensDaResposta(null), []);
    assert.deepEqual(itensDaResposta({ itens: 'x' }), []);
});

test('opções: sem nome não entram; nenhuma = null', () => {
    assert.equal(textoDasOpcoes({ opcoes: [{ nome: '' }, { quantidade: 3, nome: 'Queijo' }] }), '3× Queijo');
    assert.equal(textoDasOpcoes({}), null);
});

test('observação do cliente e total de itens', () => {
    assert.equal(observacaoDaResposta({ observacao: '  Tocar a campainha ' }), 'Tocar a campainha');
    assert.equal(observacaoDaResposta({ observacao: '' }), null);
    assert.equal(observacaoDaResposta(null), null);
    assert.equal(totalDeItens([{ quantidade: 2 }, { quantidade: 1 }]), 3);
    assert.equal(totalDeItens([]), 0);
});
