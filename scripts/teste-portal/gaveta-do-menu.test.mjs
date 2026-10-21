// Gaveta do menu do portal da loja (packages/customer-portal/addon/utils/gaveta-do-menu.js): iniciais do logo e o resumo do dia.
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { iniciais, numeroDoResumo, resumoDaGaveta } from '../../packages/customer-portal/addon/utils/gaveta-do-menu.js';

test('iniciais: duas primeiras palavras, em maiúsculas e com acento', () => {
    assert.equal(iniciais('Terraço Pizza Bar'), 'TP');
    assert.equal(iniciais('  água   de coco '), 'ÁD');
    assert.equal(iniciais('Spunto'), 'SP');
    assert.equal(iniciais('X'), 'X');
});

test('iniciais: ignora símbolos e cai em "?" sem nome', () => {
    assert.equal(iniciais('& Cia. Lanches'), 'CL');
    assert.equal(iniciais('Mousse Cake - Fiusa'), 'MC');
    assert.equal(iniciais(''), '?');
    assert.equal(iniciais(null), '?');
    assert.equal(iniciais(undefined), '?');
    assert.equal(iniciais('---'), '?');
});

test('numeroDoResumo: inteiro não negativo vira texto; o resto vira "–"', () => {
    assert.equal(numeroDoResumo(0), '0');
    assert.equal(numeroDoResumo(23), '23');
    assert.equal(numeroDoResumo('5'), '5');
    assert.equal(numeroDoResumo(null), '–');
    assert.equal(numeroDoResumo(undefined), '–');
    assert.equal(numeroDoResumo(''), '–');
    assert.equal(numeroDoResumo(-1), '–');
    assert.equal(numeroDoResumo(2.5), '–');
    assert.equal(numeroDoResumo('abc'), '–');
});

test('resumoDaGaveta: lê a resposta da rota e aguenta a falta dela', () => {
    assert.deepEqual(resumoDaGaveta({ em_andamento: 5, entregues_hoje: 23, motoboys_online: 8 }), { emAndamento: '5', entreguesHoje: '23', motoboysOnline: '8' });
    assert.deepEqual(resumoDaGaveta(null), { emAndamento: '–', entreguesHoje: '–', motoboysOnline: '–' });
    assert.deepEqual(resumoDaGaveta({ em_andamento: 0 }), { emAndamento: '0', entreguesHoje: '–', motoboysOnline: '–' });
});
