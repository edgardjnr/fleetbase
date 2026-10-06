// Vínculo da loja com o iFood na tela Lojas (packages/fleetops/addon/utils/vinculo-ifood.js).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { contagem, limparCodigo, linkSeguro, segundosRestantes, situacaoDoIfood, vencimentoDoCodigo, VALIDADE_PADRAO_SEGUNDOS } from '../../packages/fleetops/addon/utils/vinculo-ifood.js';

test('situação do selo', () => {
    assert.equal(situacaoDoIfood({ situacao: 'vinculada', nome: 'Pizzaria', merchant_id: 'm-1' }), 'vinculada');
    assert.equal(situacaoDoIfood({ situacao: 'vinculo_perdido' }), 'perdido');
    assert.equal(situacaoDoIfood({ situacao: null }), 'nenhum');
    assert.equal(situacaoDoIfood(undefined), 'nenhum');
});

test('vencimento do código pela validade do servidor', () => {
    assert.equal(vencimentoDoCodigo(600, 1000), 601000);
    assert.equal(vencimentoDoCodigo('120', 0), 120000);
    assert.equal(vencimentoDoCodigo(undefined, 0), VALIDADE_PADRAO_SEGUNDOS * 1000);
    assert.equal(vencimentoDoCodigo(-5, 0), VALIDADE_PADRAO_SEGUNDOS * 1000);
});

test('segundos restantes nunca negativos, arredondados para cima', () => {
    assert.equal(segundosRestantes(10000, 0), 10);
    assert.equal(segundosRestantes(10000, 9001), 1);
    assert.equal(segundosRestantes(10000, 10000), 0);
    assert.equal(segundosRestantes(10000, 20000), 0);
});

test('segundos restantes: valor não finito conta como vencido', () => {
    assert.equal(segundosRestantes(NaN, 0), 0);
    assert.equal(segundosRestantes(undefined, 0), 0);
    assert.equal(segundosRestantes(10000, undefined), 0);
    assert.equal(segundosRestantes(Infinity, 0), 0);
    assert.equal(segundosRestantes(null, 5000), 0);
});

test('contagem regressiva m:ss', () => {
    assert.equal(contagem(600), '10:00');
    assert.equal(contagem(545), '9:05');
    assert.equal(contagem(59), '0:59');
    assert.equal(contagem(0), '0:00');
    assert.equal(contagem(-3), '0:00');
    assert.equal(contagem('abc'), '0:00');
});

test('código de autorização colado', () => {
    assert.equal(limparCodigo('  ABCD-1234\n'), 'ABCD-1234');
    assert.equal(limparCodigo(null), '');
    assert.equal(limparCodigo('ABCD 12 34\r\nEF'), 'ABCD1234EF');
    assert.equal(limparCodigo('\t ABCD 1234 '), 'ABCD1234');
});

test('só link https do iFood vira botão', () => {
    assert.equal(linkSeguro('https://portal.ifood.com.br/apps/code?c=ABCD-EFGH'), 'https://portal.ifood.com.br/apps/code?c=ABCD-EFGH');
    assert.equal(linkSeguro('http://portal.ifood.com.br/apps/code'), null);
    assert.equal(linkSeguro('https://ifood.com.br.golpe.com/apps'), null);
    assert.equal(linkSeguro('javascript:alert(1)'), null);
    assert.equal(linkSeguro('https://evilifood.com.br'), null);
    assert.equal(linkSeguro('https://x@evil.com'), null);
    assert.equal(linkSeguro('https://evil.com@portal.ifood.com.br/a'), null);
    assert.equal(linkSeguro('https://user:senha@portal.ifood.com.br/a'), null);
    assert.equal(linkSeguro('https://portal.ifood.com.br:8443/a'), null);
    assert.equal(linkSeguro('HTTPS://PORTAL.IFOOD.COM.BR/x'), 'https://portal.ifood.com.br/x');
    assert.equal(linkSeguro(''), null);
    assert.equal(linkSeguro(undefined), null);
});
