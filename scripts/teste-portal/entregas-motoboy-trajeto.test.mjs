// Trajeto do motoboy que desce a landing (console/app/utils/entregas-motoboy-trajeto.js).
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/entregas-motoboy-trajeto.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { montarTrajeto, estadoNoScroll, passosVisitados } from '../../console/app/utils/entregas-motoboy-trajeto.js';

// Medidas da landing em 1440 × 900 (build de produção, 2026-10-10).
const desktop = {
    largura: 1440,
    altura: 900,
    tamanho: 202,
    margem: 36,
    maximo: 3354,
    inicio: 0,
    secoes: [
        { nome: 'mapa', topo: 969, base: 1946 },
        { nome: 'passos', topo: 1947, base: 2480 },
        { nome: 'telas', topo: 2480, base: 3099 },
        { nome: 'faixas', topo: 3099, base: 3714 },
    ],
    final: { topo: 3714 },
    alvo: { x: 1100, y: 400 },
};
const quadros = { partida: 27, pilotando: 50, retorno: 29, rampa: 34, entrega: 97 };

test('parte de frente, embaixo à direita, e termina entregando no alvo', () => {
    const trajeto = montarTrajeto(desktop);
    const inicio = estadoNoScroll(trajeto, 0, quadros);
    assert.equal(inicio.visivel, true);
    assert.equal(inicio.clipe, 'partida');
    assert.equal(inicio.quadro, 0);
    assert.equal(inicio.x, 1440 - 202 - 36);

    const fim = estadoNoScroll(trajeto, 3354, quadros);
    assert.equal(fim.clipe, 'entrega');
    assert.equal(fim.quadro, 96);
    assert.equal(Math.round(fim.x), 1100);
    assert.equal(Math.round(fim.y), 400);
});

test('os trechos se emendam, sem buraco, até a rolagem máxima', () => {
    const { trechos } = montarTrajeto(desktop);
    for (let i = 1; i < trechos.length; i++) {
        assert.equal(trechos[i].de, trechos[i - 1].ate, `trecho ${i}`);
        assert.equal(trechos[i].x0, trechos[i - 1].x1, `posição no trecho ${i}`);
    }
    assert.equal(trechos.at(-1).ate, 3354);
});

test('roteiro: rampa no mapa, travessia longa por passos e telas, descida até a entrega', () => {
    const { trechos } = montarTrajeto(desktop);
    assert.deepEqual(
        trechos.map((trecho) => trecho.clipe),
        ['partida', 'rampa', 'pilotando', 'retorno', 'pilotando', 'retorno', 'pilotando', 'pilotando', 'entrega']
    );
    const mapa = trechos.find((trecho) => trecho.secao === 'mapa' && trecho.clipe === 'pilotando');
    const passos = trechos.find((trecho) => trecho.secao === 'passos' && trecho.clipe === 'pilotando');
    assert.ok(mapa.x1 < mapa.x0, 'no mapa ele vai para a esquerda');
    assert.equal(mapa.espelho, true);
    assert.ok(passos.x1 > passos.x0, 'nos passos ele vai para a direita, na ordem 1 → 4');
    assert.equal(passos.espelho, false);
    assert.equal(trechos.at(-1).espelho, true, 'chega virado para a esquerda e entrega assim');
});

test('pilotagem lenta: no máximo 2 px de lado por px rolado, em velocidade constante', () => {
    const trajeto = montarTrajeto(desktop);
    trajeto.trechos
        .filter((trecho) => trecho.clipe === 'pilotando' && trecho.ate > trecho.de)
        .forEach((trecho) => {
            const velocidade = Math.abs(trecho.x1 - trecho.x0) / (trecho.ate - trecho.de);
            assert.ok(velocidade <= 2, `${trecho.secao}: ${velocidade.toFixed(2)} px/px`);
            assert.equal(trecho.curva, 'linear');
            const meio = estadoNoScroll(trajeto, (trecho.de + trecho.ate) / 2, quadros);
            assert.ok(Math.abs(meio.x - (trecho.x0 + trecho.x1) / 2) < 1, 'sem acelerar no meio');
        });
});

test('fica escondido até o painel de login passar da linha dele', () => {
    const trajeto = montarTrajeto({ ...desktop, inicio: 300 });
    assert.equal(estadoNoScroll(trajeto, 120, quadros).visivel, false);
    assert.equal(estadoNoScroll(trajeto, 320, quadros).visivel, true);
});

test('o loop gira com a rolagem e começa no quadro 0 ao fim da rampa', () => {
    const trajeto = montarTrajeto(desktop);
    const loop = trajeto.trechos.find((trecho) => trecho.clipe === 'pilotando');
    assert.equal(estadoNoScroll(trajeto, loop.de, quadros).quadro, 0);
    assert.equal(estadoNoScroll(trajeto, loop.de + 14 * 3, quadros).quadro, 3);
});

test('passos lado a lado acendem na ordem em que ele passa; empilhados, ao cruzar a linha', () => {
    const trajeto = montarTrajeto(desktop);
    const passosTrechos = trajeto.trechos.filter((trecho) => trecho.secao === 'passos');
    const lado = [100, 450, 800, 1150].map((centroX) => ({ centroX, topo: 2005 }));

    const antes = passosTrechos[0].de - 1;
    assert.deepEqual(passosVisitados(lado, estadoNoScroll(trajeto, antes, quadros), antes, trajeto), [false, false, false, false]);

    const meio = passosTrechos[1].de + (passosTrechos[1].ate - passosTrechos[1].de) * 0.3;
    const estado = estadoNoScroll(trajeto, meio, quadros);
    const acesos = passosVisitados(lado, estado, meio, trajeto);
    assert.equal(acesos[0], true);
    assert.equal(acesos[3], false);

    const depois = passosTrechos.at(-1).ate;
    assert.deepEqual(passosVisitados(lado, estadoNoScroll(trajeto, depois, quadros), depois, trajeto), [true, true, true, true]);

    const empilhados = [3000, 3300].map((topo) => ({ centroX: 200, topo }));
    const linha = trajeto.linha;
    assert.deepEqual(passosVisitados(empilhados, estado, 3000 - linha, trajeto), [true, false]);
});

test('celular: cabe na tela e o alvo fica dentro das margens', () => {
    const celular = { ...desktop, largura: 390, altura: 844, tamanho: 112, margem: 12, maximo: 7200, inicio: 900, alvo: { x: 500, y: -50 } };
    celular.secoes = [
        { nome: 'mapa', topo: 1700, base: 3100 },
        { nome: 'passos', topo: 3100, base: 4300 },
        { nome: 'telas', topo: 4300, base: 5600 },
        { nome: 'faixas', topo: 5600, base: 6900 },
    ];
    const trajeto = montarTrajeto(celular);
    trajeto.trechos.forEach((trecho) => {
        assert.ok(trecho.x0 >= 12 && trecho.x1 <= 390 - 112 - 12, `x dentro da tela (${trecho.clipe})`);
        assert.ok(trecho.y1 >= 12, 'y dentro da tela');
    });
});
