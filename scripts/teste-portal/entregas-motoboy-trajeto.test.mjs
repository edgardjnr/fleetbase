// Trajeto do motoboy que desce a landing (console/app/utils/entregas-motoboy-trajeto.js).
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/entregas-motoboy-trajeto.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { montarTrajeto, estadoNoScroll, passosVisitados, atualizarSentido, girar, quadroDoGiro } from '../../console/app/utils/entregas-motoboy-trajeto.js';

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

test('aparece com a página carregada, mesmo com o painel de login no canto dele', () => {
    // Celular: começa em cima do painel e chega à linha dele quando o painel passa.
    const trajeto = montarTrajeto({ ...desktop, inicio: 400, partida: { x: 1202, y: 300 } });
    const inicio = estadoNoScroll(trajeto, 0, quadros);
    assert.equal(inicio.visivel, true);
    assert.equal(inicio.clipe, 'partida');
    assert.equal(Math.round(inicio.y), 300);
    const naLinha = estadoNoScroll(trajeto, 400, quadros);
    assert.equal(Math.round(naLinha.y), trajeto.yBase);
    assert.equal(trajeto.trechos[0].ate, 400);

    // Tela baixa: ao lado do painel, já na linha.
    const aoLado = estadoNoScroll(montarTrajeto({ ...desktop, partida: { x: 675, y: 900 } }), 0, quadros);
    assert.equal(aoLado.visivel, true);
    assert.equal(Math.round(aoLado.x), 675);
    assert.equal(Math.round(aoLado.y), montarTrajeto(desktop).yBase);
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

test('na volta ele anda virado para onde vai, com as rodas girando para a frente', () => {
    const trajeto = montarTrajeto(desktop);
    trajeto.trechos
        .filter((trecho) => trecho.clipe === 'pilotando' && trecho.x1 !== trecho.x0)
        .forEach((trecho) => {
            const meio = (trecho.de + trecho.ate) / 2;
            const ida = estadoNoScroll(trajeto, meio, quadros);
            const volta = estadoNoScroll(trajeto, meio, quadros, 14, true);
            assert.equal(volta.x, ida.x, 'mesmo caminho');
            assert.equal(volta.espelho, !ida.espelho, `${trecho.secao}: virado para o outro lado`);
            // Subindo, a moto vai para onde a rolagem leva: x diminuindo = virado para a esquerda.
            const depois = estadoNoScroll(trajeto, meio - 10, quadros, 14, true);
            assert.equal(volta.espelho, depois.x < volta.x, `${trecho.secao}: de frente, não de ré`);
            const quadroAntes = estadoNoScroll(trajeto, meio - 14 * 2, quadros, 14, true).quadro;
            assert.equal((quadroAntes - volta.quadro + 50) % 50, 2, 'o loop avança enquanto a página sobe');
        });
});

test('na volta, a rampa vira pilotagem e a partida termina de frente, virada para a direita', () => {
    const trajeto = montarTrajeto(desktop);
    const rampa = trajeto.trechos.find((trecho) => trecho.clipe === 'rampa');
    const subindo = estadoNoScroll(trajeto, (rampa.de + rampa.ate) / 2, quadros, 14, true);
    assert.equal(subindo.clipe, 'pilotando');
    assert.equal(subindo.espelho, false);

    const partida = estadoNoScroll(trajeto, trajeto.trechos[0].ate - 1, quadros, 14, true);
    assert.equal(partida.clipe, 'partida');
    assert.equal(partida.espelho, false);
    assert.equal(partida.deLado, true);
    assert.equal(estadoNoScroll(trajeto, 0, quadros, 14, true).deLado, false, 'de frente no topo: vira sem meia-volta');
});

test('na volta, a meia-volta toca na ordem do vídeo, do lado de volta de um trecho ao do outro', () => {
    const trajeto = montarTrajeto(desktop);
    trajeto.trechos
        .filter((trecho) => trecho.clipe === 'retorno')
        .forEach((trecho) => {
            const fim = estadoNoScroll(trajeto, trecho.ate - 0.01, quadros, 14, true);
            const comeco = estadoNoScroll(trajeto, trecho.de, quadros, 14, true);
            assert.equal(fim.quadro, 0, 'subindo, começa no primeiro quadro');
            assert.equal(comeco.quadro, 28, 'e termina no último');
            assert.equal(fim.espelho, trecho.espelho);
        });
});

test('o lado só salta onde há meia-volta pelo tempo: ida sem nenhum; volta só ao sair da entrega', () => {
    const trajeto = montarTrajeto(desktop);
    const saltos = (voltando) =>
        trajeto.trechos.slice(1).flatMap((trecho, i) => {
            const antes = estadoNoScroll(trajeto, trecho.de - 0.01, quadros, 14, voltando);
            const depois = estadoNoScroll(trajeto, trecho.de, quadros, 14, voltando);
            return Math.abs(antes.lado - depois.lado) > 0.05 ? [trajeto.trechos[i].clipe + '→' + trecho.clipe] : [];
        });
    assert.deepEqual(saltos(false), []);
    assert.deepEqual(saltos(true), ['pilotando→entrega']);
});

test('sentido da rolagem: só vira depois de 6 px no sentido novo', () => {
    let sentido = { voltando: false, acumulado: 0 };
    sentido = atualizarSentido(sentido, -4);
    assert.equal(sentido.voltando, false, 'um tremor não vira');
    sentido = atualizarSentido(sentido, 3);
    assert.equal(sentido.acumulado, 0, 'voltou a descer: zera');
    sentido = atualizarSentido(atualizarSentido(sentido, -4), -3);
    assert.equal(sentido.voltando, true);
    assert.equal(atualizarSentido(sentido, 0), sentido);
    assert.equal(atualizarSentido(atualizarSentido(sentido, 5), 5).voltando, false);
});

test('meia-volta pelo tempo: anda até o lado pedido e usa o vídeo espelhado para virar à direita', () => {
    assert.ok(Math.abs(girar(1, 0, 0.1) - 0.82) < 1e-9);
    assert.equal(girar(0.1, 0, 0.1), 0);
    assert.equal(girar(0, 1, 0), 0);
    assert.deepEqual(quadroDoGiro(0.25, true, 29), { quadro: 7, espelho: false });
    assert.deepEqual(quadroDoGiro(0.25, false, 29), { quadro: 21, espelho: true });
    assert.deepEqual(quadroDoGiro(1, false, 29), { quadro: 0, espelho: true }, 'virado para a esquerda, começo do vídeo espelhado');
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
