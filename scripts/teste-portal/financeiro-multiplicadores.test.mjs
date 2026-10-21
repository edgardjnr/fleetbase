// Entrega junto e multiplicadores no console (packages/fleetops/addon/utils/financeiro-multiplicadores.js): faixas das duas
// tabelas, fator digitado, campos de data, validação do período, situação e selos.
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/financeiro-multiplicadores.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    campoDeData,
    campoDoInstante,
    chaveDaSituacao,
    comNovaFaixa,
    corpoDoPeriodo,
    faixaParaEdicao,
    faixasParaEnviar,
    faixasValidas,
    fatorDigitado,
    linhasDeFaixas,
    selosDaCorrida,
    selosDoRelatorio,
    separarPeriodos,
    somarHoras,
    textoDoFator,
    validarPeriodo,
} from '../../packages/fleetops/addon/utils/financeiro-multiplicadores.js';

test('faixas: edição, "de km", validação, nova faixa e envio', () => {
    const faixas = [faixaParaEdicao({ ate_km: 3, motoboy: 4, loja: 6 }), faixaParaEdicao({ ate_km: 6, motoboy: 6, loja: 9 })];
    assert.deepEqual(faixas[0], { ate_km: '3', motoboy: '4', loja: '6' });
    assert.deepEqual(linhasDeFaixas(faixas).map((l) => [l.indice, l.de_km]), [[0, '0'], [1, '3']]);
    assert.equal(faixasValidas(faixas), true);
    assert.equal(faixasValidas([]), true, 'vazia vale (segunda tabela desligada)');
    assert.equal(faixasValidas([{ ate_km: '3', motoboy: '4', loja: '6' }, { ate_km: '2', motoboy: '1', loja: '1' }]), false, '"até" não crescente');
    assert.equal(faixasValidas([{ ate_km: '3', motoboy: '-1', loja: '6' }]), false, 'valor negativo');
    assert.deepEqual(comNovaFaixa(faixas).at(-1), { ate_km: '7', motoboy: '6', loja: '9' }, 'nova faixa: +1 km, copia os valores da última');
    assert.deepEqual(comNovaFaixa([]), [{ ate_km: '1', motoboy: '', loja: '' }]);
    assert.deepEqual(faixasParaEnviar([{ ate_km: '2,5', motoboy: '6', loja: '9,5' }]), [{ ate_km: 2.5, motoboy: 6, loja: 9.5 }]);
});

test('fator digitado: 1 a 5, até duas casas', () => {
    assert.equal(fatorDigitado('1,2'), 1.2);
    assert.equal(fatorDigitado('1.5'), 1.5);
    assert.equal(fatorDigitado(2), 2);
    assert.equal(fatorDigitado('1'), 1);
    assert.ok(Number.isNaN(fatorDigitado('0,9')));
    assert.ok(Number.isNaN(fatorDigitado('5,01')));
    assert.ok(Number.isNaN(fatorDigitado('1,234')));
    assert.ok(Number.isNaN(fatorDigitado('abc')));
    assert.ok(Number.isNaN(fatorDigitado('')));
});

test('texto do fator no idioma do console', () => {
    assert.equal(textoDoFator(1.2), '×1,2');
    assert.equal(textoDoFator(1.25), '×1,25');
    assert.equal(textoDoFator(2), '×2');
    assert.equal(textoDoFator(1.5, 'en-US'), '×1.5');
    assert.equal(textoDoFator(null), '');
});

test('campos de data: ISO do servidor, Date local e soma de horas', () => {
    assert.equal(campoDoInstante('2026-10-21T18:00:00-03:00'), '2026-10-21T18:00', 'a hora de Brasília escrita no ISO');
    assert.equal(campoDoInstante(null), '');
    assert.equal(campoDeData(new Date(2026, 9, 21, 7, 5)), '2026-10-21T07:05');
    assert.equal(somarHoras('2026-10-21T22:30', 3), '2026-10-22T01:30', 'vira o dia');
    assert.equal(somarHoras('lixo', 3), '');
});

test('validação do período: as regras do servidor', () => {
    const agora = '2026-10-21T17:00';
    const ok = { nome: 'Chuva', multMotoboy: '1,2', multLoja: '1', inicio: '2026-10-21T18:00', fim: '2026-10-21T22:00' };
    assert.deepEqual(validarPeriodo(ok, agora), []);
    assert.deepEqual(validarPeriodo({ ...ok, nome: ' ' }, agora), ['nome']);
    assert.deepEqual(validarPeriodo({ ...ok, nome: 'a'.repeat(41) }, agora), ['nome-longo']);
    assert.deepEqual(validarPeriodo({ ...ok, multLoja: '0,5' }, agora), ['fator']);
    assert.deepEqual(validarPeriodo({ ...ok, fim: '' }, agora), ['datas']);
    assert.deepEqual(validarPeriodo({ ...ok, fim: '2026-10-21T17:30' }, agora), ['fim'], 'fim antes do início');
    assert.deepEqual(validarPeriodo({ ...ok, inicio: '2026-10-21T10:00', fim: '2026-10-21T16:00' }, agora), ['fim'], 'fim no passado');
    assert.deepEqual(validarPeriodo({ ...ok, fim: '2026-10-28T18:01' }, agora), ['duracao']);
    assert.deepEqual(validarPeriodo({ ...ok, inicio: '2026-10-21T16:00', fim: '2026-10-28T17:00' }, agora), [], 'início no passado vira agora: 7 dias contam de agora');
    assert.deepEqual(validarPeriodo({ soFim: true, inicio: '2026-10-21T16:00', fim: '2026-10-21T20:00' }, agora), [], 'em andamento: só o fim, sem nome nem fator');
});

test('corpo enviado ao servidor', () => {
    assert.deepEqual(corpoDoPeriodo({ nome: ' Chuva ', multMotoboy: '1,2', multLoja: '1', inicio: '2026-10-21T18:00', fim: '2026-10-21T22:00' }), {
        nome: 'Chuva',
        mult_motoboy: '1.20',
        mult_loja: '1.00',
        inicio: '2026-10-21T18:00',
        fim: '2026-10-21T22:00',
    });
    assert.deepEqual(corpoDoPeriodo({ soFim: true, nome: 'x', fim: '2026-10-21T23:00' }), { fim: '2026-10-21T23:00' });
});

test('situação e separação da lista', () => {
    assert.equal(chaveDaSituacao('em_andamento'), 'fleet-ops.ui.financeiro.multiplicadores.situacao.em_andamento');
    assert.equal(chaveDaSituacao('outra'), 'fleet-ops.ui.financeiro.multiplicadores.situacao.encerrado');
    const lista = [
        { id: 'c', situacao: 'agendado', inicio: '2026-10-23T18:00:00-03:00' },
        { id: 'b', situacao: 'agendado', inicio: '2026-10-22T18:00:00-03:00' },
        { id: 'a', situacao: 'em_andamento', inicio: '2026-10-21T18:00:00-03:00' },
        { id: 'z', situacao: 'encerrado', inicio: '2026-10-20T18:00:00-03:00' },
    ];
    const { emAndamento, agendados, encerrados } = separarPeriodos(lista);
    assert.equal(emAndamento.id, 'a');
    assert.deepEqual(agendados.map((p) => p.id), ['b', 'c'], 'o próximo primeiro');
    assert.deepEqual(encerrados.map((p) => p.id), ['z']);
    assert.deepEqual(separarPeriodos(null), { emAndamento: null, agendados: [], encerrados: [] });
});

test('selos da corrida (extrato e fatura) e do relatório', () => {
    assert.deepEqual(selosDaCorrida({ junto: 2, multiplicador: { nome: 'Chuva', fator: 1.2 } }), [
        { chave: 'junto', params: { posicao: 2 } },
        { chave: 'multiplicador', params: { nome: 'Chuva', fator: '×1,2' } },
    ]);
    assert.deepEqual(selosDaCorrida({ junto: null, multiplicador: null }), []);
    assert.deepEqual(selosDaCorrida(null), []);
    assert.deepEqual(selosDoRelatorio({ tabela: 'junto', posicao: 3, multiplicador: { nome: 'Chuva', motoboy: 1.2, loja: 1 } }), [
        { chave: 'junto', params: { posicao: 3 } },
        { chave: 'multiplicador-relatorio', params: { nome: 'Chuva', motoboy: '×1,2', loja: '×1' } },
    ]);
    assert.deepEqual(selosDoRelatorio({ tabela: 'normal', posicao: 2, multiplicador: null }), [], 'posição 2 pela tabela normal: sem selo');
});
