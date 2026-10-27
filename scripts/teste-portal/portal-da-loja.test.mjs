// Portal da loja no desenho do sketch 009 S (packages/customer-portal/addon/utils/portal-da-loja.js): situação e
// contagens da lista de Pedidos, ordem pelo prazo de entrega e os números do Início.
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { contagens, numerosDoInicio, ordenarPorPrazo, passaNoFiltro, saudacao, situacaoDoPedido } from '../../packages/customer-portal/addon/utils/portal-da-loja.js';

const agora = Date.parse('2026-10-26T20:00:00-03:00');

test('situação do pedido: sem motoboy, indo à loja, a caminho e encerrado', () => {
    assert.deepEqual(situacaoDoPedido({ status: 'created', aceito: false }, agora), { chave: 'sem', atrasado: false });
    assert.deepEqual(situacaoDoPedido({ status: 'dispatched' }, agora), { chave: 'sem', atrasado: false }, 'sem a informação do aceite: sem motoboy');
    assert.deepEqual(situacaoDoPedido({ status: 'started', aceito: true }, agora), { chave: 'loja', atrasado: false });
    assert.deepEqual(situacaoDoPedido({ status: 'enroute', aceito: false }, agora), { chave: 'caminho', atrasado: false }, 'saiu da loja já conta como aceito');
    assert.deepEqual(situacaoDoPedido({ status: 'PICKED_UP' }, agora), { chave: 'caminho', atrasado: false });
    assert.deepEqual(situacaoDoPedido({ status: 'completed', aceito: true, prazoEm: '2026-10-26T19:00:00-03:00' }, agora), { chave: 'fim', atrasado: false });
    assert.deepEqual(situacaoDoPedido(null, agora), { chave: 'sem', atrasado: false });
});

test('situação do pedido: atrasado pelo prazo de entrega; prazo ilegível não atrasa', () => {
    assert.equal(situacaoDoPedido({ status: 'enroute', prazoEm: '2026-10-26T19:55:00-03:00' }, agora).atrasado, true);
    assert.equal(situacaoDoPedido({ status: 'enroute', prazoEm: '2026-10-26T20:10:00-03:00' }, agora).atrasado, false);
    assert.equal(situacaoDoPedido({ status: 'created', prazoEm: 'amanhã' }, agora).atrasado, false);
    assert.equal(situacaoDoPedido({ status: 'created', prazoEm: '' }, agora).atrasado, false);
});

test('filtro e contagens das contagens do mapa', () => {
    const lista = [
        { chave: 'sem', atrasado: false },
        { chave: 'loja', atrasado: true },
        { chave: 'caminho', atrasado: true },
        { chave: 'caminho', atrasado: false },
        { chave: 'fim', atrasado: false },
    ];
    assert.deepEqual(contagens(lista), { sem: 1, atrasado: 2, loja: 1, caminho: 2 });
    assert.deepEqual(contagens([]), { sem: 0, atrasado: 0, loja: 0, caminho: 0 });
    assert.deepEqual(contagens(null), { sem: 0, atrasado: 0, loja: 0, caminho: 0 });
    assert.equal(passaNoFiltro(lista[1], 'atrasado'), true);
    assert.equal(passaNoFiltro(lista[3], 'atrasado'), false);
    assert.equal(passaNoFiltro(lista[0], 'sem'), true);
    assert.equal(passaNoFiltro(lista[0], 'loja'), false);
    assert.equal(passaNoFiltro(lista[0], null), true, 'sem filtro, todos');
});

test('ordem da lista: quem vence o prazo primeiro em cima; sem prazo no fim, o mais antigo antes', () => {
    const pedidos = [
        { id: 'sem-prazo-novo', criado: '2026-10-26T19:30:00-03:00' },
        { id: 'prazo-41', prazo: '2026-10-26T20:41:00-03:00', criado: '2026-10-26T19:31:00-03:00' },
        { id: 'atrasado-4', prazo: '2026-10-26T19:56:00-03:00', criado: '2026-10-26T18:52:00-03:00' },
        { id: 'sem-prazo-velho', criado: '2026-10-26T18:00:00-03:00' },
        { id: 'prazo-12', prazo: '2026-10-26T20:12:00-03:00', criado: '2026-10-26T19:08:00-03:00' },
        { id: 'atrasado-20', prazo: '2026-10-26T19:40:00-03:00', criado: '2026-10-26T18:40:00-03:00' },
    ];
    const ordem = ordenarPorPrazo(pedidos, (p) => p.prazo ?? null, (p) => p.criado ?? null).map((p) => p.id);
    assert.deepEqual(ordem, ['atrasado-20', 'atrasado-4', 'prazo-12', 'prazo-41', 'sem-prazo-velho', 'sem-prazo-novo']);
    assert.equal(pedidos[0].id, 'sem-prazo-novo', 'não muda a lista recebida');
    assert.deepEqual(ordenarPorPrazo(null, () => null, () => null), []);
});

test('saudação pela hora', () => {
    assert.equal(saudacao(5), 'bom-dia');
    assert.equal(saudacao(11), 'bom-dia');
    assert.equal(saudacao(12), 'boa-tarde');
    assert.equal(saudacao(17), 'boa-tarde');
    assert.equal(saudacao(18), 'boa-noite');
    assert.equal(saudacao(2), 'boa-noite');
});

test('números do Início: textos prontos, "–" sem dado', () => {
    const n = numerosDoInicio({ pedidos_hoje: 18, em_andamento: 5, entregues_hoje: 11, no_prazo: 91, tempo_medio_min: 28, motoboys_online: 9 }, { valor: 504, entregas: 42 });
    assert.equal(n.pedidosHoje, '18');
    assert.equal(n.emAndamento, '5');
    assert.equal(n.entregues, '11');
    assert.equal(n.noPrazo, '91%');
    assert.equal(n.tempoMedio, '28 min');
    assert.equal(n.aPagar.replace(/\s/g, ' '), 'R$ 504,00');
    assert.equal(n.entregasDoCiclo, 42);
    assert.equal(n.motoboysOnline, '9');

    const vazio = numerosDoInicio(null, null);
    assert.deepEqual([vazio.pedidosHoje, vazio.noPrazo, vazio.tempoMedio, vazio.aPagar, vazio.entregasDoCiclo], ['–', '–', '–', '–', null]);
    assert.equal(numerosDoInicio({ no_prazo: null, tempo_medio_min: null }, { valor: 0, entregas: 0 }).noPrazo, '–', 'sem entregue com prazo');
    assert.equal(numerosDoInicio({}, { valor: 0, entregas: 0 }).aPagar.replace(/\s/g, ' '), 'R$ 0,00', 'ciclo zerado mostra R$ 0,00');
    assert.deepEqual([vazio.prePaga, vazio.creditoDisponivel, vazio.semCredito], [false, '–', false], 'sem resumo: pós-paga');
});

test('números do Início: a loja pré-paga mostra o crédito disponível; a pós-paga continua no ciclo', () => {
    const pos = numerosDoInicio({ pedidos_hoje: 3, carteira: { modelo: 'pos' } }, { valor: 40, entregas: 4 });
    assert.equal(pos.prePaga, false);
    assert.equal(pos.aPagar.replace(/\s/g, ' '), 'R$ 40,00');
    assert.equal(pos.semCredito, false);

    const pre = numerosDoInicio({ pedidos_hoje: 3, carteira: { modelo: 'pre', credito: 120, reservado: 20, disponivel: '100.50', pode_pedir: true } }, null);
    assert.equal(pre.prePaga, true);
    assert.equal(pre.creditoDisponivel.replace(/\s/g, ' '), 'R$ 100,50');
    assert.equal(pre.semCredito, false);
    assert.equal(pre.pedidosHoje, '3', 'os outros números não mudam');

    const semCredito = numerosDoInicio({ carteira: { modelo: 'pre', credito: -12, reservado: 0, disponivel: -12, pode_pedir: false } }, null);
    assert.equal(semCredito.creditoDisponivel.replace(/\s/g, ' '), '-R$ 12,00');
    assert.equal(semCredito.semCredito, true);
    assert.equal(numerosDoInicio({ carteira: { modelo: 'pre' } }, null).creditoDisponivel, '–', 'pré-paga sem o número');
});
