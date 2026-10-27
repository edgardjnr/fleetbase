// Integrações do portal da loja (packages/customer-portal/addon/utils/integracoes-da-loja.js): estado e selo de cada
// cartão, ordem da tela, troca do cartão depois de conectar, iniciais do sistema e o código de conexão do iFood.
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    estadoDoCartao,
    podeConectar,
    seloDoCartao,
    ordenarCartoes,
    trocarCartao,
    totalConectadas,
    iniciaisDoSistema,
    tempoDesde,
    vencimentoDoCodigo,
    segundosRestantes,
    contagem,
    limparCodigo,
    linkSeguro,
} from '../../packages/customer-portal/addon/utils/integracoes-da-loja.js';

const ifood = (campos = {}) => ({ id: 'ifood', nome: 'iFood', fluxo: 'ifood', disponivel: true, estado: 'desconectada', ...campos });
const rp = (campos = {}) => ({ id: 'restaurantepro', nome: 'RestaurantePro', fluxo: 'chave', disponivel: true, estado: 'desconectada', ...campos });

test('estadoDoCartao: o do servidor, ou desconectada quando vem vazio ou desconhecido', () => {
    for (const estado of ['conectada', 'aguardando', 'atencao', 'desconectada']) {
        assert.equal(estadoDoCartao(ifood({ estado })), estado);
    }
    assert.equal(estadoDoCartao(ifood({ estado: 'qualquer' })), 'desconectada');
    assert.equal(estadoDoCartao(null), 'desconectada');
    assert.equal(estadoDoCartao({}), 'desconectada');
});

test('podeConectar: só com a integração ligada e um fluxo que o portal conhece', () => {
    assert.equal(podeConectar(ifood()), true);
    assert.equal(podeConectar(rp()), true);
    assert.equal(podeConectar(ifood({ disponivel: false })), false);
    assert.equal(podeConectar(rp({ fluxo: 'oauth' })), false);
    assert.equal(podeConectar(undefined), false);
});

test('seloDoCartao: indisponível só quando não há conexão a cuidar', () => {
    assert.equal(seloDoCartao(ifood()), 'desconectada');
    assert.equal(seloDoCartao(ifood({ disponivel: false })), 'indisponivel');
    // desligada no servidor, mas conectada ou aguardando: continua aparecendo, para a loja poder desconectar
    assert.equal(seloDoCartao(ifood({ disponivel: false, estado: 'conectada' })), 'conectada');
    assert.equal(seloDoCartao(rp({ disponivel: false, estado: 'aguardando' })), 'aguardando');
    assert.equal(seloDoCartao(ifood({ estado: 'atencao' })), 'atencao');
    assert.equal(seloDoCartao(rp({ fluxo: 'novo' })), 'indisponivel');
});

test('ordenarCartoes: atenção primeiro, depois a ordem do servidor', () => {
    const lista = [ifood(), rp({ estado: 'atencao' }), { id: 'outro', estado: 'conectada' }];
    assert.deepEqual(ordenarCartoes(lista).map((c) => c.id), ['restaurantepro', 'ifood', 'outro']);
    assert.deepEqual(ordenarCartoes([ifood(), rp()]).map((c) => c.id), ['ifood', 'restaurantepro']);
    assert.deepEqual(ordenarCartoes(null), []);
    assert.deepEqual(lista.map((c) => c.id), ['ifood', 'restaurantepro', 'outro'], 'não mexe na lista original');
});

test('trocarCartao: troca pelo id; id novo vai para o fim; sem id não muda', () => {
    const lista = [ifood(), rp()];
    const novo = ifood({ estado: 'conectada', loja_no_ifood: 'Pizzaria Um' });
    assert.deepEqual(trocarCartao(lista, novo), [novo, rp()]);
    assert.deepEqual(trocarCartao(lista, { id: 'saipos' }).map((c) => c.id), ['ifood', 'restaurantepro', 'saipos']);
    assert.equal(trocarCartao(lista, undefined), lista);
    assert.equal(trocarCartao(lista, {}), lista);
    assert.deepEqual(trocarCartao(null, novo), [novo]);
});

test('totalConectadas', () => {
    assert.equal(totalConectadas([ifood({ estado: 'conectada' }), rp({ estado: 'aguardando' })]), 1);
    assert.equal(totalConectadas([]), 0);
    assert.equal(totalConectadas(undefined), 0);
});

test('iniciaisDoSistema', () => {
    assert.equal(iniciaisDoSistema('iFood'), 'iF');
    assert.equal(iniciaisDoSistema('RestaurantePro'), 'RP');
    assert.equal(iniciaisDoSistema('Anota AI'), 'AA');
    assert.equal(iniciaisDoSistema('saipos'), 'sa');
    assert.equal(iniciaisDoSistema(''), '?');
    assert.equal(iniciaisDoSistema(null), '?');
});

test('tempoDesde: agora, minutos, horas e dias; inválido = null; futuro = agora', () => {
    const agora = Date.parse('2026-10-27T12:00:00-03:00');
    assert.deepEqual(tempoDesde('2026-10-27T11:59:30-03:00', agora), { unidade: 'agora', n: 0 });
    assert.deepEqual(tempoDesde('2026-10-27T11:57:00-03:00', agora), { unidade: 'minutos', n: 3 });
    assert.deepEqual(tempoDesde('2026-10-27T09:00:00-03:00', agora), { unidade: 'horas', n: 3 });
    assert.deepEqual(tempoDesde('2026-10-25T12:00:00-03:00', agora), { unidade: 'dias', n: 2 });
    assert.deepEqual(tempoDesde('2026-10-27T12:05:00-03:00', agora), { unidade: 'agora', n: 0 });
    assert.equal(tempoDesde(null, agora), null);
    assert.equal(tempoDesde('ontem', agora), null);
});

test('código de conexão do iFood: validade, contagem e código colado', () => {
    assert.equal(vencimentoDoCodigo(600, 1000), 601000);
    assert.equal(vencimentoDoCodigo(undefined, 0), 600000, 'sem validade: 10 min');
    assert.equal(vencimentoDoCodigo(-5, 0), 600000);
    assert.equal(segundosRestantes(10500, 0), 11);
    assert.equal(segundosRestantes(0, 5000), 0);
    assert.equal(segundosRestantes(undefined, 0), 0);
    assert.equal(contagem(545), '9:05');
    assert.equal(contagem(0), '0:00');
    assert.equal(limparCodigo('  AB CD\nEF '), 'ABCDEF');
    assert.equal(limparCodigo(null), '');
});

test('linkSeguro: só https do iFood, sem usuário, senha nem porta', () => {
    assert.equal(linkSeguro('https://portal.ifood.com.br/apps/code?c=ABCD'), 'https://portal.ifood.com.br/apps/code?c=ABCD');
    assert.equal(linkSeguro('https://ifood.com.br/x'), 'https://ifood.com.br/x');
    assert.equal(linkSeguro('http://portal.ifood.com.br/x'), null);
    assert.equal(linkSeguro('https://evilifood.com.br/x'), null);
    assert.equal(linkSeguro('https://portal.ifood.com.br.evil.com/x'), null);
    assert.equal(linkSeguro('https://x@portal.ifood.com.br/x'), null);
    assert.equal(linkSeguro('https://portal.ifood.com.br:8443/x'), null);
    assert.equal(linkSeguro('javascript:alert(1)'), null);
    assert.equal(linkSeguro(undefined), null);
});
