// Financeiro do portal da loja (packages/customer-portal/addon/utils/entregas-financeiro.js): situação e selo da fatura,
// ciclo em andamento, descrição dos itens e CSV da fatura.
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    situacaoDaFatura,
    chaveDaSituacao,
    classeDoSelo,
    textoDoCiclo,
    descricaoDoItem,
    numeroDoPedido,
    decimalCsv,
    linhasCsvDaFatura,
    csvDeLinhas,
} from '../../packages/customer-portal/addon/utils/entregas-financeiro.js';

const fatura = (campos = {}) => ({ situacao: 'emitida', pagamento: 'aberta', vencida: false, ...campos });

test('situação da fatura: cancelada, vencida, paga, parcial e aberta, nessa precedência', () => {
    assert.equal(situacaoDaFatura(fatura({ situacao: 'cancelada', vencida: true, pagamento: 'parcial' })), 'cancelada');
    assert.equal(situacaoDaFatura(fatura({ vencida: true, pagamento: 'parcial' })), 'vencida');
    assert.equal(situacaoDaFatura(fatura({ pagamento: 'paga' })), 'paga');
    assert.equal(situacaoDaFatura(fatura({ pagamento: 'parcial' })), 'parcial');
    assert.equal(situacaoDaFatura(fatura({ pagamento: 'aberta' })), 'aberta');
});

test('situação da fatura: sem pagamento calculado, vazia ou ausente fica em aberto', () => {
    assert.equal(situacaoDaFatura(fatura({ pagamento: null })), 'aberta');
    assert.equal(situacaoDaFatura({}), 'aberta');
    assert.equal(situacaoDaFatura(null), 'aberta');
    assert.equal(situacaoDaFatura(undefined), 'aberta');
});

test('chave de tradução da situação, com situação desconhecida como em aberto', () => {
    assert.equal(chaveDaSituacao('paga'), 'customer-portal.ui.entregas.financeiro.situacao.paga');
    assert.equal(chaveDaSituacao('vencida'), 'customer-portal.ui.entregas.financeiro.situacao.vencida');
    assert.equal(chaveDaSituacao('rascunho'), 'customer-portal.ui.entregas.financeiro.situacao.aberta');
    assert.equal(chaveDaSituacao(undefined), 'customer-portal.ui.entregas.financeiro.situacao.aberta');
});

test('classe do selo por situação (a cancelada é riscada pelo CSS)', () => {
    assert.equal(classeDoSelo('paga'), 'entregas-fatura-selo entregas-fatura-selo-paga');
    assert.equal(classeDoSelo('vencida'), 'entregas-fatura-selo entregas-fatura-selo-vencida');
    assert.equal(classeDoSelo('cancelada'), 'entregas-fatura-selo entregas-fatura-selo-cancelada');
    assert.equal(classeDoSelo('qualquer'), 'entregas-fatura-selo entregas-fatura-selo-aberta');
});

test('ciclo em andamento: datas e números prontos para formatar', () => {
    assert.deepEqual(textoDoCiclo({ inicio: '2026-10-06', fim: '2026-10-12', entregas: 42, valor: 315 }), {
        desde: '2026-10-06',
        ate: '2026-10-12',
        entregas: 42,
        valor: 315,
    });
    // decimais do MySQL chegam como texto
    assert.deepEqual(textoDoCiclo({ inicio: '2026-10-06', fim: '2026-10-12', entregas: '3', valor: '12.50' }), {
        desde: '2026-10-06',
        ate: '2026-10-12',
        entregas: 3,
        valor: 12.5,
    });
});

test('ciclo em andamento: ausente ou sem início vira null; número inválido vira zero', () => {
    assert.equal(textoDoCiclo(null), null);
    assert.equal(textoDoCiclo(undefined), null);
    assert.equal(textoDoCiclo({ entregas: 2, valor: 10 }), null);
    assert.deepEqual(textoDoCiclo({ inicio: '2026-10-06', entregas: null, valor: 'x' }), { desde: '2026-10-06', ate: null, entregas: 0, valor: 0 });
});

test('descrição do item: corrida comum sem texto, cancelada cobrada com a origem, ajuste com tipo e motivo', () => {
    assert.deepEqual(descricaoDoItem({ tipo: 'corrida', detalhes: { cancelado_pago: false } }), { chave: null, motivo: null });
    assert.deepEqual(descricaoDoItem({ tipo: 'corrida', detalhes: { cancelado_pago: true, cancelado_origem: 'ifood' } }), {
        chave: 'canceled-ifood-charged',
        motivo: null,
    });
    assert.deepEqual(descricaoDoItem({ tipo: 'corrida', detalhes: { cancelado_pago: true, cancelado_origem: 'integracao' } }), {
        chave: 'canceled-store-charged',
        motivo: null,
    });
    assert.deepEqual(descricaoDoItem({ tipo: 'ajuste', motivo: '  Desconto da semana ' }), { chave: 'financeiro.tipos.ajuste', motivo: 'Desconto da semana' });
    assert.deepEqual(descricaoDoItem({ tipo: 'saldo_inicial', motivo: '' }), { chave: 'financeiro.tipos.saldo_inicial', motivo: null });
    assert.deepEqual(descricaoDoItem({ tipo: 'estorno', motivo: 'Pedido cancelado após a conclusão' }), {
        chave: 'financeiro.tipos.estorno',
        motivo: 'Pedido cancelado após a conclusão',
    });
});

test('descrição do item: corrida com motivo e item vazio', () => {
    assert.deepEqual(descricaoDoItem({ tipo: 'corrida', motivo: 'Entrega dupla' }), { chave: null, motivo: 'Entrega dupla' });
    assert.deepEqual(descricaoDoItem(null), { chave: null, motivo: null });
});

test('número do pedido: o número exibido, senão o public_id, senão null', () => {
    assert.equal(numeroDoPedido({ pedido: { id: 'order_ab12', numero: 'RP5706' } }), 'RP5706');
    assert.equal(numeroDoPedido({ pedido: { id: 'order_ab12', numero: null } }), 'order_ab12');
    assert.equal(numeroDoPedido({ pedido: null }), null);
    assert.equal(numeroDoPedido(undefined), null);
});

test('decimal do CSV: vírgula e duas casas, vazio sem valor', () => {
    assert.equal(decimalCsv(8.5), '8,50');
    assert.equal(decimalCsv('3.214'), '3,21');
    assert.equal(decimalCsv(-2), '-2,00');
    assert.equal(decimalCsv(0.1 + 0.2), '0,30');
    assert.equal(decimalCsv(null), '');
    assert.equal(decimalCsv(undefined), '');
    assert.equal(decimalCsv('abc'), '');
});

test('linhas do CSV da fatura: cabeçalho, um item por linha e os totais no fim', () => {
    const itens = [
        { tipo: 'corrida', valor: 8.5, data: '2026-10-06', pedido: { id: 'order_1', numero: 'RP1' }, detalhes: { km: 3.21, destino: 'Rua X, 10' } },
        { tipo: 'ajuste', valor: -2, data: '2026-10-07', pedido: null, detalhes: null, motivo: 'Desconto' },
    ];
    const textos = {
        cabecalho: ['Data', 'Pedido', 'Km', 'Destino', 'Descrição', 'Valor'],
        total: 'Total',
        recebido: 'Recebido',
        emAberto: 'Em aberto',
        data: (valor) => valor.split('-').reverse().join('/'),
        descricao: (item) => (item.tipo === 'ajuste' ? `Ajuste: ${item.motivo}` : ''),
    };

    assert.deepEqual(linhasCsvDaFatura({ total: 6.5, recebido: 0, em_aberto: 6.5 }, itens, textos), [
        ['Data', 'Pedido', 'Km', 'Destino', 'Descrição', 'Valor'],
        ['06/10/2026', 'RP1', '3,21', 'Rua X, 10', '', '8,50'],
        ['07/10/2026', '', '', '', 'Ajuste: Desconto', '-2,00'],
        [],
        ['Total', '', '', '', '', '6,50'],
        ['Recebido', '', '', '', '', '0,00'],
        ['Em aberto', '', '', '', '', '6,50'],
    ]);
});

test('linhas do CSV da fatura: sem itens e sem textos usa os padrões e não quebra', () => {
    const linhas = linhasCsvDaFatura(null, undefined);

    assert.equal(linhas.length, 1 + 1 + 3);
    assert.equal(linhas[0].length, 6);
    assert.deepEqual(linhas[1], []);
    assert.deepEqual(linhas[2].at(-1), '');
});

test('CSV: BOM, ponto e vírgula, aspas dobradas e proteção contra fórmula só em texto', () => {
    const csv = csvDeLinhas([
        ['Destino', 'Valor'],
        ['Rua "A", 1', '-2,00'],
        ['=HYPERLINK("x")', '8,50'],
        ['-cuidado', '+1'],
        [],
    ]);

    assert.ok(csv.startsWith('﻿'));
    assert.deepEqual(csv.slice(1).split('\r\n'), [
        '"Destino";"Valor"',
        '"Rua ""A"", 1";"-2,00"',
        `"'=HYPERLINK(""x"")";"8,50"`,
        `"'-cuidado";"'+1"`,
        '',
    ]);
});
