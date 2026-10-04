// Funções puras do chat da loja no portal (packages/customer-portal/addon/utils/conversas.js).
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    MAX_CARACTERES,
    INTERVALO_CONVERSA_MS,
    INTERVALO_LISTA_CONVERSAS_MS,
    mesclarMensagens,
    totalNaoLidas,
    prefixoDoPedido,
    resumo,
    textoValido,
} from '../../packages/customer-portal/addon/utils/conversas.js';

const msg = (id, em, texto = id) => ({ id, em, texto });

test('intervalos e limite iguais aos do servidor e da spec', () => {
    assert.equal(MAX_CARACTERES, 1000);
    assert.equal(INTERVALO_CONVERSA_MS, 5000);
    assert.equal(INTERVALO_LISTA_CONVERSAS_MS, 20000);
});

test('mesclar: sem repetir, da mais antiga para a mais nova, a versão nova vence', () => {
    const atuais = [msg('a', '2026-10-04T10:00:00-03:00'), msg('b', '2026-10-04T10:01:00-03:00')];
    const novas = [msg('b', '2026-10-04T10:01:00-03:00', 'b2'), msg('c', '2026-10-04T10:02:00-03:00')];

    assert.deepEqual(mesclarMensagens(atuais, novas), [
        msg('a', '2026-10-04T10:00:00-03:00'),
        msg('b', '2026-10-04T10:01:00-03:00', 'b2'),
        msg('c', '2026-10-04T10:02:00-03:00'),
    ]);
});

test('mesclar: ordem pelo horário, mesmo com fusos diferentes no texto', () => {
    const lista = mesclarMensagens([msg('x', '2026-10-04T13:05:00+00:00')], [msg('y', '2026-10-04T10:01:00-03:00')]);
    assert.deepEqual(lista.map((m) => m.id), ['y', 'x']);
});

test('mesclar: listas ausentes e mensagem sem id', () => {
    assert.deepEqual(mesclarMensagens(null, undefined), []);
    assert.deepEqual(mesclarMensagens([{ texto: 'sem id' }], []), []);
});

test('total de não lidas', () => {
    assert.equal(totalNaoLidas([{ nao_lidas: 2 }, { nao_lidas: 0 }, { nao_lidas: 3 }]), 5);
    assert.equal(totalNaoLidas([{}, { nao_lidas: null }, { nao_lidas: '2' }]), 2);
    assert.equal(totalNaoLidas(null), 0);
});

test('prefixo do pedido no campo de texto', () => {
    assert.equal(prefixoDoPedido('RP-123'), 'Pedido RP-123: ');
    assert.equal(prefixoDoPedido(''), '');
    assert.equal(prefixoDoPedido(null), '');
});

test('resumo da última mensagem', () => {
    assert.equal(resumo('  oi   tudo\nbem  '), 'oi tudo bem');
    assert.equal(resumo('a'.repeat(70), 60), 'a'.repeat(59) + '…');
    assert.equal(resumo(null), '');
});

test('texto aceito como no servidor', () => {
    assert.equal(textoValido('  oi '), 'oi');
    assert.equal(textoValido('   '), null);
    assert.equal(textoValido(undefined), null);
    assert.equal(textoValido('á'.repeat(1000)), 'á'.repeat(1000));
    assert.equal(textoValido('a'.repeat(1001)), null);
});
