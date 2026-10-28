// A buzina do portal da loja (packages/customer-portal/addon/utils/buzina.js): quando toca a cada leitura de loja/aceites.
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/buzina.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ARQUIVO_DA_BUZINA, chavesDosAceites, registrarAceites } from '../../packages/customer-portal/addon/utils/buzina.js';

const aceite = (pedido, aceitoEm) => ({ pedido, numero: '1', aceito_em: aceitoEm, motoboy: 'Ana' });

test('chavesDosAceites: pedido + aceito_em, ignorando linha fora do formato', () => {
    assert.deepEqual(chavesDosAceites({ aceites: [aceite('order_a', '2026-10-28T19:00:00-03:00'), { pedido: 'order_b' }, null, aceite('', 'x')] }), ['order_a|2026-10-28T19:00:00-03:00']);
    assert.deepEqual(chavesDosAceites(null), []);
    assert.deepEqual(chavesDosAceites({ aceites: 'x' }), []);
});

test('registrarAceites: a primeira leitura só registra', () => {
    const { vistas, tocar } = registrarAceites(null, { aceites: [aceite('order_a', 't1')] });
    assert.equal(tocar, false);
    assert.deepEqual([...vistas], ['order_a|t1']);
});

test('registrarAceites: aceite novo toca uma vez, mesmo com vários na mesma leitura', () => {
    let estado = registrarAceites(null, { aceites: [aceite('order_a', 't1')] });
    estado = registrarAceites(estado.vistas, { aceites: [aceite('order_a', 't1')] });
    assert.equal(estado.tocar, false, 'nada novo');
    estado = registrarAceites(estado.vistas, { aceites: [aceite('order_b', 't2'), aceite('order_c', 't3'), aceite('order_a', 't1')] });
    assert.equal(estado.tocar, true, 'dois novos: um toque');
    estado = registrarAceites(estado.vistas, { aceites: [aceite('order_b', 't2'), aceite('order_c', 't3'), aceite('order_a', 't1')] });
    assert.equal(estado.tocar, false, 'os mesmos de novo: não toca');
});

test('registrarAceites: a troca aceita (mesmo pedido, outro aceito_em) toca de novo', () => {
    const antes = registrarAceites(null, { aceites: [aceite('order_a', 't1')] });
    const depois = registrarAceites(antes.vistas, { aceites: [aceite('order_a', 't9')] });
    assert.equal(depois.tocar, true);
});

test('registrarAceites: o que saiu da janela de 2 h não fica guardado; lista vazia não toca', () => {
    const antes = registrarAceites(null, { aceites: [aceite('order_a', 't1')] });
    const vazio = registrarAceites(antes.vistas, { aceites: [] });
    assert.equal(vazio.tocar, false);
    assert.equal(vazio.vistas.size, 0);
});

test('o arquivo da buzina fica em /sons (console/public, fora do fingerprint)', () => {
    assert.equal(ARQUIVO_DA_BUZINA, '/sons/buzina.mp3');
});
