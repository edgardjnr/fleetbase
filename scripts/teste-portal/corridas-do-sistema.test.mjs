// Corridas do sistema no console (packages/fleetops/addon/utils/corridas-do-sistema.js): preço da central no período
// ("variado"), data e hora do ISO, origem, CSV, formulário e corpo do preço, extrato da central e reais().
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/corridas-do-sistema.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    chaveDaOrigem,
    corpoDoPreco,
    dataHoraDoIso,
    extratoDaCentral,
    linhasCsvDasCorridas,
    nomeDoCsv,
    precoDaCentral,
    reais,
    validarPreco,
} from '../../packages/fleetops/addon/utils/corridas-do-sistema.js';

const PREFIXO = 'fleet-ops.ui.financeiro.corridas-do-sistema';

test('preço da central no período: nenhum, um só ou variado', () => {
    assert.equal(precoDaCentral([]), null);
    assert.equal(precoDaCentral(undefined), null);
    assert.equal(precoDaCentral([null]), null);
    assert.equal(precoDaCentral([2]), 2);
    assert.equal(precoDaCentral([2.5, '2.50']), 2.5, 'o mesmo valor escrito de dois jeitos é um preço só');
    assert.equal(precoDaCentral([2, 2.5]), 'variado');
});

test('data e hora pela hora escrita no ISO (Brasília), sem converter fuso', () => {
    assert.equal(dataHoraDoIso('2026-10-22T10:05:00-03:00'), '22/10/2026 10:05');
    assert.equal(dataHoraDoIso('2026-10-09T23:59:59-03:00'), '09/10/2026 23:59');
    assert.equal(dataHoraDoIso(''), '');
    assert.equal(dataHoraDoIso(null), '');
    assert.equal(dataHoraDoIso('lixo'), '');
});

test('origem da linha', () => {
    assert.equal(chaveDaOrigem('conclusao'), `${PREFIXO}.origens.conclusao`);
    assert.equal(chaveDaOrigem('conferencia'), `${PREFIXO}.origens.conferencia`);
    assert.equal(chaveDaOrigem('historico'), `${PREFIXO}.origens.historico`);
    assert.equal(chaveDaOrigem('xyz'), `${PREFIXO}.origens.outra`);
});

const relatorio = {
    inicio: '2026-10-01',
    fim: '2026-10-31',
    total: { corridas: 5, a_cobrar: 10, sem_preco: 1 },
    centrais: [
        { company: 'a', nome: 'Central Norte', corridas: 3, a_cobrar: 7, sem_preco: 0, precos: [2, 2.5] },
        { company: 'b', nome: 'Central Sul', corridas: 2, a_cobrar: 3, sem_preco: 1, precos: [3] },
    ],
    corridas: [
        { finalizada_em: '2026-10-05T09:31:00-03:00', company: 'a', central: 'Central Norte', pedido: 'order_a1', numero: '4821', loja_nome: '=Pizzaria', motoboy_nome: 'Carlos "Ligeiro"', preco: 2, origem: 'conclusao' },
        { finalizada_em: '2026-10-09T23:59:59-03:00', company: 'b', central: 'Central Sul', pedido: 'order_b1', numero: null, loja_nome: null, motoboy_nome: null, preco: null, origem: 'historico' },
    ],
    lista_cortada: false,
};

test('CSV: por central com o total, linha em branco e as corridas (pt-BR sem tradutor)', () => {
    assert.deepEqual(linhasCsvDasCorridas(relatorio), [
        '"Central";"Corridas";"Preço";"A cobrar";"Sem preço"',
        '"Central Norte";3;"variado";7,00;0',
        '"Central Sul";2;3,00;3,00;1',
        '"Total";5;;10,00;1',
        '',
        '"Data e hora";"Central";"Pedido";"Nº";"Loja";"Motoboy";"Preço";"Origem"',
        `"05/10/2026 09:31";"Central Norte";"order_a1";"4821";"'=Pizzaria";"Carlos ""Ligeiro""";2,00;"Na conclusão"`,
        '"09/10/2026 23:59";"Central Sul";"order_b1";"";"";"";;"Histórico"',
    ]);
});

test('CSV com tradutor: as chaves de fleet-ops.ui.financeiro.corridas-do-sistema', () => {
    const linhas = linhasCsvDasCorridas(relatorio, (chave) => chave);
    assert.equal(linhas[0].split(';')[0], `"${PREFIXO}.central"`);
    assert.equal(linhas[1].split(';')[2], `"${PREFIXO}.variado"`);
    assert.equal(linhas[0].split(';').at(-1), `"${PREFIXO}.csv-sem-preco"`);
    assert.equal(linhas[6].split(';').at(-1), `"${PREFIXO}.origens.conclusao"`);
});

test('CSV com a lista cortada: linha final com o aviso (o arquivo não parece completo)', () => {
    const cortado = { ...relatorio, lista_cortada: true };
    const linhas = linhasCsvDasCorridas(cortado);
    assert.equal(linhas.length, 10);
    assert.equal(linhas.at(-2), '');
    assert.equal(linhas.at(-1), '"A lista mostra as primeiras 2 corridas; os totais contam todas. Diminua o período para ver o resto."');

    const chamadas = [];
    const traduzidas = linhasCsvDasCorridas(cortado, (chave, opcoes) => {
        chamadas.push([chave, opcoes]);
        return chave;
    });
    assert.equal(traduzidas.at(-1), `"${PREFIXO}.lista-cortada"`);
    assert.deepEqual(chamadas.at(-1), [`${PREFIXO}.lista-cortada`, { count: 2 }]);

    // sem lista_cortada (ou com outro valor que não true): sem a linha
    assert.equal(linhasCsvDasCorridas(relatorio).length, 8);
    assert.equal(linhasCsvDasCorridas({ ...relatorio, lista_cortada: 'sim' }).length, 8);
});

test('CSV sem relatório: cabeçalhos e o total zerado', () => {
    assert.deepEqual(linhasCsvDasCorridas(null), [
        '"Central";"Corridas";"Preço";"A cobrar";"Sem preço"',
        '"Total";0;;0,00;0',
        '',
        '"Data e hora";"Central";"Pedido";"Nº";"Loja";"Motoboy";"Preço";"Origem"',
    ]);
});

test('nome do arquivo', () => {
    assert.equal(nomeDoCsv('2026-10-01', '2026-10-31'), 'corridas-do-sistema_2026-10-01_2026-10-31.csv');
});

test('formulário do preço: central, valor de 0 a 1000 e data', () => {
    assert.deepEqual(validarPreco({ company: 'central-a', valor: '2,50', data: '2026-10-01' }), []);
    assert.deepEqual(validarPreco({}), ['central', 'valor', 'data']);
    assert.deepEqual(validarPreco({ company: 'a', valor: '0', data: '2026-10-01' }), [], 'zero vale');
    assert.deepEqual(validarPreco({ company: 'a', valor: '1.000', data: '2026-10-01' }), [], '"1.000" = mil, o máximo');
    assert.deepEqual(validarPreco({ company: 'a', valor: '1000,01', data: '2026-10-01' }), ['valor']);
    assert.deepEqual(validarPreco({ company: 'a', valor: '-1', data: '2026-10-01' }), ['valor']);
    assert.deepEqual(validarPreco({ company: 'a', valor: '2,505', data: '2026-10-01' }), ['valor'], 'mais de 2 casas');
    assert.deepEqual(validarPreco({ company: 'a', valor: '2', data: '2026-02-30' }), ['data']);
    assert.deepEqual(corpoDoPreco({ company: 'central-a', valor: '2,5', data: '2026-10-01' }), { company: 'central-a', valor: '2.50', vale_a_partir_de: '2026-10-01' });
});

test('data do preço: de 2020 a 2100, como o servidor (PrecosDasCentrais::PRIMEIRO_ANO e ULTIMO_ANO)', () => {
    assert.deepEqual(validarPreco({ company: 'a', valor: '2', data: '2020-01-01' }), []);
    assert.deepEqual(validarPreco({ company: 'a', valor: '2', data: '2100-12-31' }), []);
    assert.deepEqual(validarPreco({ company: 'a', valor: '2', data: '2019-12-31' }), ['data']);
    assert.deepEqual(validarPreco({ company: 'a', valor: '2', data: '2101-01-01' }), ['data']);
});

// Extrato da central (PDF para o cliente): docs/superpowers/specs/2026-10-22-extrato-da-central-design.md
const relatorioDoExtrato = {
    inicio: '2026-10-01',
    fim: '2026-10-22',
    total: { corridas: 7, a_cobrar: 3.6, sem_preco: 1 },
    centrais: [
        { company: 'a', nome: 'Central Norte', corridas: 5, a_cobrar: 2.6, sem_preco: 1, precos: [0.6, 0.5] },
        { company: 'b', nome: 'Central Sul', corridas: 2, a_cobrar: 1, sem_preco: 0, precos: [0.5] },
    ],
    corridas: [
        { finalizada_em: '2026-10-05T09:31:00-03:00', company: 'a', central: 'Central Norte', pedido: 'order_a1', numero: '4821', loja_nome: 'Pizzaria', motoboy_nome: 'Carlos', preco: 0.6, origem: 'conclusao' },
        { finalizada_em: '2026-10-06T12:00:00-03:00', company: 'b', central: 'Central Sul', pedido: 'order_b1', numero: '10', loja_nome: 'Lanches', motoboy_nome: 'Ana', preco: 0.5, origem: 'conclusao' },
        { finalizada_em: '2026-10-22T01:08:00-03:00', company: 'a', central: 'Central Norte', pedido: 'order_a2', numero: null, loja_nome: null, motoboy_nome: null, preco: '0.50', origem: 'historico' },
        { finalizada_em: '2026-10-21T23:59:00-03:00', company: 'a', central: 'Central Norte', pedido: 'order_a3', numero: '', loja_nome: 'Pizzaria', motoboy_nome: 'Carlos', preco: null, origem: 'conferencia' },
        { finalizada_em: '2026-10-20T18:45:00-03:00', company: 'a', central: 'Central Norte', pedido: 'order_a4', numero: '4900', loja_nome: 'Pizzaria', motoboy_nome: 'Bia', preco: 0.6, origem: 'conclusao' },
        { finalizada_em: '2026-10-19T20:10:00-03:00', company: 'a', central: 'Central Norte', pedido: 'order_a5', numero: '4950', loja_nome: 'Pizzaria', motoboy_nome: 'Bia', preco: 0.5, origem: 'conclusao' },
    ],
    lista_cortada: false,
};

test('extrato: central que não está no relatório devolve null', () => {
    assert.equal(extratoDaCentral(relatorioDoExtrato, 'zzz'), null);
    assert.equal(extratoDaCentral(null, 'a'), null);
    assert.equal(extratoDaCentral({}, 'a'), null);
});

test('extrato: só as corridas da central, na ordem recebida, com o resumo da linha da central', () => {
    assert.deepEqual(extratoDaCentral(relatorioDoExtrato, 'a'), {
        central: 'Central Norte',
        inicio: '01/10/2026',
        fim: '22/10/2026',
        corridas: 5,
        total: 2.6,
        semPreco: 1,
        listaCortada: false,
        precoVariou: true,
        precos: [
            { valor: 0.5, quantidade: 2 },
            { valor: 0.6, quantidade: 2 },
        ],
        linhas: [
            { data: '05/10/2026', hora: '09:31', numero: '4821', loja: 'Pizzaria', motoboy: 'Carlos', valor: 0.6 },
            { data: '22/10/2026', hora: '01:08', numero: 'order_a2', loja: '', motoboy: '', valor: 0.5 },
            { data: '21/10/2026', hora: '23:59', numero: 'order_a3', loja: 'Pizzaria', motoboy: 'Carlos', valor: null },
            { data: '20/10/2026', hora: '18:45', numero: '4900', loja: 'Pizzaria', motoboy: 'Bia', valor: 0.6 },
            { data: '19/10/2026', hora: '20:10', numero: '4950', loja: 'Pizzaria', motoboy: 'Bia', valor: 0.5 },
        ],
    });
});

test('extrato: data e hora pela hora escrita no ISO, sem converter fuso (22/10 01:08 -03:00 continua 22/10 01:08)', () => {
    const linha = extratoDaCentral(relatorioDoExtrato, 'a').linhas[1];
    assert.equal(linha.data, '22/10/2026');
    assert.equal(linha.hora, '01:08');
});

test('extrato: número cai no pedido quando falta', () => {
    const numeros = extratoDaCentral(relatorioDoExtrato, 'a').linhas.map((linha) => linha.numero);
    assert.deepEqual(numeros, ['4821', 'order_a2', 'order_a3', '4900', '4950']);
});

test('extrato: preço escrito de dois jeitos (2.5 e "2.50") não conta como variação; sem preço fica fora de precos', () => {
    const corrida = (pedido, preco) => ({ finalizada_em: '2026-10-05T09:31:00-03:00', company: 'c', central: 'Central Leste', pedido, numero: pedido, loja_nome: 'L', motoboy_nome: 'M', preco });
    const extrato = extratoDaCentral(
        {
            ...relatorioDoExtrato,
            centrais: [{ company: 'c', nome: 'Central Leste', corridas: 3, a_cobrar: 5, sem_preco: 1, precos: [2.5] }],
            corridas: [corrida('1', 2.5), corrida('2', '2.50'), corrida('3', null)],
        },
        'c'
    );
    assert.equal(extrato.precoVariou, false);
    assert.deepEqual(extrato.precos, [{ valor: 2.5, quantidade: 2 }]);
    assert.deepEqual(
        extrato.linhas.map((linha) => linha.valor),
        [2.5, 2.5, null]
    );
    assert.equal(extrato.semPreco, 1);
});

test('extrato: preços agrupados por valor e em ordem crescente', () => {
    const corridas = [1, 0.7, 1, 0.7, 0.7].map((preco, i) => ({ finalizada_em: '2026-10-05T09:31:00-03:00', company: 'c', central: 'Central Leste', pedido: `o${i}`, numero: String(i), preco }));
    const extrato = extratoDaCentral({ ...relatorioDoExtrato, centrais: [{ company: 'c', nome: 'Central Leste', corridas: 5, a_cobrar: 4.1, sem_preco: 0 }], corridas }, 'c');
    assert.deepEqual(extrato.precos, [
        { valor: 0.7, quantidade: 3 },
        { valor: 1, quantidade: 2 },
    ]);
    assert.equal(extrato.precoVariou, true);
});

test('extrato: lista cortada, total em 2 casas e nome da central pela corrida quando a linha não tem nome', () => {
    const extrato = extratoDaCentral(
        { ...relatorioDoExtrato, lista_cortada: true, centrais: [{ company: 'b', nome: null, corridas: '2', a_cobrar: '1.006', sem_preco: null, precos: [0.5] }] },
        'b'
    );
    assert.equal(extrato.listaCortada, true);
    assert.equal(extrato.central, 'Central Sul');
    assert.equal(extrato.corridas, 2);
    assert.equal(extrato.total, 1.01);
    assert.equal(extrato.semPreco, 0);
    assert.equal(extrato.precoVariou, false);
    assert.equal(extratoDaCentral({ ...relatorioDoExtrato, lista_cortada: 'sim' }, 'b').listaCortada, false);
});

test('reais: R$ com 2 casas e espaço comum', () => {
    assert.equal(reais(1234.5), 'R$ 1.234,50');
    assert.equal(reais(1234.56), 'R$ 1.234,56');
    assert.equal(reais(0.07 * 100), 'R$ 7,00');
    assert.equal(reais(0.5), 'R$ 0,50');
    assert.equal(reais(0), 'R$ 0,00');
    assert.equal(reais(null), 'R$ 0,00');
});
