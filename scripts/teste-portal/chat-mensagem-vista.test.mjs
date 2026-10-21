// Quando a mensagem do chat do console conta como lida (packages/ember-ui/addon/utils/chat-mensagem-vista.js).
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import mensagemFoiVista, { LIMIARES, PARTE_VISIVEL } from '../../packages/ember-ui/addon/utils/chat-mensagem-vista.js';

const entrada = (ratio, visivel, janela = 228, isIntersecting = ratio > 0) => ({
    isIntersecting,
    intersectionRatio: ratio,
    intersectionRect: { height: visivel },
    rootBounds: { height: janela },
});

test('a última mensagem a fração de pixel da borda conta como lida (caso real de 2026-10-20)', () => {
    assert.equal(mensagemFoiVista(entrada(0.9966449737548828, 78.9)), true);
});

test('mensagem inteira na janela conta como lida', () => {
    assert.equal(mensagemFoiVista(entrada(1, 79)), true);
});

test('mensagem só pela metade na janela não conta', () => {
    assert.equal(mensagemFoiVista(entrada(0.5, 40)), false);
});

test('mensagem fora da janela não conta', () => {
    assert.equal(mensagemFoiVista(entrada(0, 0, 228, false)), false);
    assert.equal(mensagemFoiVista(null), false);
    assert.equal(mensagemFoiVista(undefined), false);
});

test('mensagem mais alta que a janela conta quando ocupa quase a janela inteira', () => {
    // 600 px de mensagem numa janela de 228: no máximo 38% dela aparece
    assert.equal(mensagemFoiVista(entrada(0.38, 228)), true);
    assert.equal(mensagemFoiVista(entrada(0.2, 120)), false);
});

test('sem rootBounds (navegador sem a informação) só vale a parte visível da mensagem', () => {
    assert.equal(mensagemFoiVista({ isIntersecting: true, intersectionRatio: 0.38, intersectionRect: { height: 228 }, rootBounds: null }), false);
    assert.equal(mensagemFoiVista({ isIntersecting: true, intersectionRatio: 0.95 }), true);
});

test('os limiares vão de 0 a 1 de 5 em 5% e passam pelos 90%', () => {
    assert.equal(LIMIARES.length, 21);
    assert.equal(LIMIARES[0], 0);
    assert.equal(LIMIARES[20], 1);
    assert.ok(LIMIARES.includes(PARTE_VISIVEL));
});
