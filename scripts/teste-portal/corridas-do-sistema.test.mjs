// Corridas do sistema no console (packages/fleetops/addon/utils/corridas-do-sistema.js): preço da central no período
// ("variado"), data e hora do ISO, origem, CSV, formulário e corpo do preço.
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/corridas-do-sistema.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chaveDaOrigem, corpoDoPreco, dataHoraDoIso, linhasCsvDasCorridas, nomeDoCsv, precoDaCentral, validarPreco } from '../../packages/fleetops/addon/utils/corridas-do-sistema.js';

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
    assert.equal(linhas[6].split(';').at(-1), `"${PREFIXO}.origens.conclusao"`);
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
