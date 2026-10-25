// Pedido aberto no console (packages/fleetops/addon/utils/pedido-aberto.js): rota do motoboy em trechos, nome do
// cliente, cupom dos produtos e a origem do "Voltar".
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/pedido-aberto.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    COR_FALTA,
    COR_FEITO,
    nomeEmCaixaNormal,
    dividirNaPosicao,
    trechosDaRota,
    pontosParaEnquadrar,
    situacaoDaComanda,
    cupomDosItens,
    origemDaTransicao,
} from '../../packages/fleetops/addon/utils/pedido-aberto.js';

// rota em L: coleta (0,0) → (0,0.01) → (0.01,0.01) cliente
const LINHA = [
    [0, 0],
    [0, 0.01],
    [0.01, 0.01],
];
const COLETA = { latitude: 0, longitude: 0 };
const ENTREGA = { latitude: 0.01, longitude: 0.01 };

test('nome em caixa normal, com as partículas minúsculas', () => {
    assert.equal(nomeEmCaixaNormal('MARIANA SOUZA'), 'Mariana Souza');
    assert.equal(nomeEmCaixaNormal('  CARLOS HENRIQUE DA SILVA E SOUZA '), 'Carlos Henrique da Silva e Souza');
    assert.equal(nomeEmCaixaNormal('DE OLIVEIRA'), 'De Oliveira');
    assert.equal(nomeEmCaixaNormal(''), null);
    assert.equal(nomeEmCaixaNormal(null), null);
});

test('divide a linha no ponto mais perto do motoboy', () => {
    // motoboy um pouco ao lado do meio do 1º segmento
    const { feito, falta } = dividirNaPosicao(LINHA, [0.0001, 0.005]);
    assert.deepEqual(feito[0], [0, 0]);
    assert.deepEqual(feito.at(-1), [0.0001, 0.005]);
    assert.ok(Math.abs(feito.at(-2)[1] - 0.005) < 1e-9, 'corta no meio do 1º segmento');
    assert.deepEqual(falta[0], [0.0001, 0.005]);
    assert.deepEqual(falta.at(-1), [0.01, 0.01]);
    assert.equal(falta.length, 4, 'motoboy, corte, a curva e o cliente');
});

test('motoboy no 2º segmento: o feito leva a curva', () => {
    const { feito, falta } = dividirNaPosicao(LINHA, [0.006, 0.0101]);
    assert.deepEqual(feito.slice(0, 2), [
        [0, 0],
        [0, 0.01],
    ]);
    assert.deepEqual(falta.at(-1), [0.01, 0.01]);
});

test('sem motoboy ou sem linha, nada é feito', () => {
    assert.deepEqual(dividirNaPosicao(LINHA, null), { feito: [], falta: LINHA });
    assert.deepEqual(dividirNaPosicao([[0, 0]], [0, 0]).feito, []);
});

test('a caminho do cliente: feito em cinza e falta em laranja', () => {
    const trechos = trechosDaRota({ situacao: 'rota', rota: { linha: LINHA }, coleta: COLETA, entrega: ENTREGA, motoboy: { latitude: 0, longitude: 0.005 } });
    assert.deepEqual(
        trechos.map((t) => [t.tipo, t.cor, t.tracejado]),
        [
            ['feito', COR_FEITO, false],
            ['falta', COR_FALTA, false],
        ]
    );
});

test('indo à loja: motoboy → loja tracejado e a entrega em laranja', () => {
    const trechos = trechosDaRota({ situacao: 'loja', rota: { linha: LINHA }, coleta: COLETA, entrega: ENTREGA, motoboy: { latitude: -0.01, longitude: 0 } });
    assert.deepEqual(
        trechos.map((t) => [t.tipo, t.tracejado]),
        [
            ['ate-loja', true],
            ['entrega', false],
        ]
    );
    assert.deepEqual(trechos[0].pontos, [
        [-0.01, 0],
        [0, 0],
    ]);
});

test('sem motoboy: rota prevista tracejada; encerrado: rota feita em cinza', () => {
    assert.deepEqual(
        trechosDaRota({ situacao: 'oferta', rota: { linha: LINHA } }).map((t) => [t.tipo, t.cor, t.tracejado]),
        [['prevista', COR_FEITO, true]]
    );
    assert.deepEqual(
        trechosDaRota({ situacao: 'rota', encerrado: true, rota: { linha: LINHA }, motoboy: { latitude: 0, longitude: 0.005 } }).map((t) => [t.tipo, t.cor]),
        [['feita', COR_FEITO]]
    );
});

test('sem a rota do servidor: linha reta da coleta ao cliente; rota aproximada sai tracejada', () => {
    const reta = trechosDaRota({ situacao: 'rota', rota: null, coleta: COLETA, entrega: ENTREGA, motoboy: null });
    assert.deepEqual(reta, [{ tipo: 'falta', pontos: [[0, 0], [0.01, 0.01]], cor: COR_FALTA, tracejado: false }]);
    const aproximada = trechosDaRota({ situacao: 'rota', rota: { linha: LINHA, aproximado: true }, motoboy: { latitude: 0, longitude: 0.005 } });
    assert.ok(aproximada.every((t) => t.tracejado));
    assert.deepEqual(trechosDaRota({ situacao: 'rota' }), []);
});

test('pontos para enquadrar juntam trechos e paradas', () => {
    const pontos = pontosParaEnquadrar([{ pontos: [[1, 2]] }], [{ latitude: 3, longitude: 4 }, null]);
    assert.deepEqual(pontos, [
        [1, 2],
        [3, 4],
    ]);
});

test('situação da comanda', () => {
    assert.equal(situacaoDaComanda({ encerrado: true, situacao_final: 'concluido' }, 'rota'), 'concluido');
    assert.equal(situacaoDaComanda({ encerrado: true, situacao_final: 'cancelado' }), 'cancelado');
    assert.equal(situacaoDaComanda({ encerrado: false }, 'loja'), 'loja');
    assert.equal(situacaoDaComanda(null, undefined), 'oferta');
});

test('cupom dos produtos', () => {
    const cupom = cupomDosItens([
        { quantidade: 1, nome: 'Pizza grande Calabresa', opcoes: [{ quantidade: 1, nome: 'Borda catupiry' }, { quantidade: 2, nome: 'Bacon' }] },
        { quantidade: 2, nome: 'Coca-Cola 2 L', observacao: 'gelada' },
        { quantidade: 'x', nome: 'Guaraná' },
        { quantidade: 1, nome: '  ' },
    ]);
    assert.equal(cupom.total, 4);
    assert.deepEqual(cupom.itens[0], { quantidade: 1, nome: 'Pizza grande Calabresa', opcoes: 'Borda catupiry, 2× Bacon', observacao: null });
    assert.equal(cupom.itens[1].observacao, 'gelada');
    assert.equal(cupom.itens[2].quantidade, 1);
    assert.equal(cupomDosItens([]), null);
    assert.equal(cupomDosItens(null), null);
});

test('origem do Voltar', () => {
    assert.equal(origemDaTransicao('console.home', undefined), 'inicio');
    assert.equal(origemDaTransicao('console.fleet-ops.operations.orders.index.index', 'table'), 'tabela');
    assert.equal(origemDaTransicao('console.fleet-ops.operations.orders.index', 'kanban'), 'quadro');
    assert.equal(origemDaTransicao('console.fleet-ops.operations.orders.index', 'map'), null);
    assert.equal(origemDaTransicao('console.fleet-ops.financeiro.motoboy', undefined), null);
    assert.equal(origemDaTransicao(undefined, undefined), null);
});
