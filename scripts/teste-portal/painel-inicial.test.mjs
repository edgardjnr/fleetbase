// Contas do painel inicial do console (packages/fleetops/addon/utils/painel-inicial.js).
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/painel-inicial.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    periodoDoPainel,
    saudacao,
    situacaoDoPedido,
    atrasado,
    contagens,
    filaDoPainel,
    resumoDosMotoboys,
    motoboysParaAtribuir,
    barrasDoGrafico,
    mostrarAlerta,
} from '../../packages/fleetops/addon/utils/painel-inicial.js';

const AGORA = Date.parse('2026-10-24T21:14:00-03:00');
const prazo = (min) => new Date(AGORA + min * 60000).toISOString();

test('periodoDoPainel: hoje, 7 e 30 dias em datas locais', () => {
    const hoje = new Date(2026, 9, 24, 23, 30);
    assert.deepEqual(periodoDoPainel('hoje', hoje), { inicio: '2026-10-24', fim: '2026-10-24' });
    assert.deepEqual(periodoDoPainel('semana', hoje), { inicio: '2026-10-18', fim: '2026-10-24' });
    assert.deepEqual(periodoDoPainel('mes', hoje), { inicio: '2026-09-25', fim: '2026-10-24' });
    assert.deepEqual(periodoDoPainel('qualquer', hoje), { inicio: '2026-10-24', fim: '2026-10-24' });
});

test('saudacao pela hora', () => {
    assert.equal(saudacao(3), 'boa-noite');
    assert.equal(saudacao(8), 'bom-dia');
    assert.equal(saudacao(13), 'boa-tarde');
    assert.equal(saudacao(21), 'boa-noite');
});

test('situacaoDoPedido', () => {
    assert.equal(situacaoDoPedido({ motoboy_id: null, status: 'dispatched' }), 'oferta');
    assert.equal(situacaoDoPedido({ motoboy_id: 'driver_a', status: 'started' }), 'loja');
    assert.equal(situacaoDoPedido({ motoboy_id: 'driver_a', status: 'ENROUTE' }), 'rota');
});

test('atrasado só com motoboy e prazo vencido', () => {
    assert.equal(atrasado({ motoboy_id: 'd', status: 'enroute', prazo_em: prazo(-3) }, AGORA), true);
    assert.equal(atrasado({ motoboy_id: 'd', status: 'enroute', prazo_em: prazo(10) }, AGORA), false);
    assert.equal(atrasado({ motoboy_id: null, status: 'dispatched', prazo_em: prazo(-3) }, AGORA), false);
    assert.equal(atrasado({ motoboy_id: 'd', status: 'enroute', prazo_em: null }, AGORA), false);
});

const PEDIDOS = [
    { id: 'a', motoboy_id: 'd1', status: 'enroute', prazo_em: prazo(20) },
    { id: 'b', motoboy_id: null, status: 'dispatched', criado_em: '2026-10-24T21:10:00-03:00' },
    { id: 'c', motoboy_id: 'd2', status: 'started', prazo_em: prazo(-8) },
    { id: 'd', motoboy_id: null, status: 'dispatched', criado_em: '2026-10-24T21:05:00-03:00' },
    { id: 'e', motoboy_id: 'd3', status: 'enroute', prazo_em: prazo(-2) },
    { id: 'f', motoboy_id: 'd4', status: 'started', prazo_em: null },
    { id: 'g', motoboy_id: 'd5', status: 'enroute', prazo_em: prazo(5) },
];

test('contagens', () => {
    assert.deepEqual(contagens(PEDIDOS, AGORA), { todos: 7, oferta: 2, loja: 2, rota: 3, atrasado: 2 });
});

test('filaDoPainel: ofertas (mais antiga antes), atrasados (mais atrasado antes), prazo, sem prazo', () => {
    assert.deepEqual(filaDoPainel(PEDIDOS, 'todos', AGORA).map((p) => p.id), ['d', 'b', 'c', 'e', 'g', 'a', 'f']);
    assert.deepEqual(filaDoPainel(PEDIDOS, 'atrasado', AGORA).map((p) => p.id), ['c', 'e']);
    assert.deepEqual(filaDoPainel(PEDIDOS, 'loja', AGORA).map((p) => p.id), ['c', 'f']);
});

test('resumoDosMotoboys', () => {
    const motoboys = [
        { online: true, situacao: 'livre' },
        { online: true, situacao: 'entrega' },
        { online: false, situacao: 'entrega' },
        { online: true, situacao: 'livre' },
    ];
    assert.deepEqual(resumoDosMotoboys(motoboys), { online: 3, livres: 2 });
});

test('motoboysParaAtribuir: só livres online, do mais perto da loja', () => {
    const coleta = { latitude: -21.17, longitude: -47.81 };
    const motoboys = [
        { id: 'longe', nome: 'Ana', online: true, situacao: 'livre', latitude: -21.25, longitude: -47.81 },
        { id: 'perto', nome: 'Bia', online: true, situacao: 'livre', latitude: -21.171, longitude: -47.81 },
        { id: 'ocupado', nome: 'Cid', online: true, situacao: 'coleta', latitude: -21.17, longitude: -47.81 },
        { id: 'off', nome: 'Duda', online: false, situacao: 'livre', latitude: -21.17, longitude: -47.81 },
    ];
    const lista = motoboysParaAtribuir(motoboys, coleta);
    assert.deepEqual(lista.map((m) => m.id), ['perto', 'longe']);
    assert.ok(lista[0].km < 0.2 && lista[1].km > 8);
    assert.deepEqual(motoboysParaAtribuir(motoboys, null).map((m) => m.id), ['longe', 'perto'], 'sem coleta: pelo nome');
});

test('barrasDoGrafico por hora: começa às 10h (ou antes, se houver), futuro sem barra', () => {
    const barras = Array.from({ length: 24 }, (_, h) => ({ rotulo: String(h).padStart(2, '0'), total: h === 9 ? 1 : h === 19 ? 4 : 0, media: h === 19 ? 2 : 0 }));
    const r = barrasDoGrafico({ tipo: 'hora', barras }, { hoje: true, horaAtual: 21 });
    assert.equal(r[0].rotuloCurto, '9h');
    assert.equal(r.length, 15);
    const b19 = r.find((b) => b.rotuloCurto === '19h');
    assert.equal(b19.altura, 100);
    assert.equal(b19.alturaMedia, 50);
    assert.equal(r.find((b) => b.rotuloCurto === '21h').agora, true);
    assert.equal(r.find((b) => b.rotuloCurto === '22h').futura, true);
});

test('barrasDoGrafico por dia', () => {
    const r = barrasDoGrafico({ tipo: 'dia', barras: [{ rotulo: '2026-10-23', total: 2, media: null }, { rotulo: '2026-10-24', total: 4, media: null }] });
    assert.deepEqual(r.map((b) => [b.rotuloCurto, b.altura, b.agora]), [['23/10', 50, false], ['24/10', 100, true]]);
    assert.deepEqual(barrasDoGrafico(null), []);
});

test('mostrarAlerta: atrasado ou 2+ sem motoboy', () => {
    assert.equal(mostrarAlerta({ oferta: 1, atrasado: 0 }), false);
    assert.equal(mostrarAlerta({ oferta: 2, atrasado: 0 }), true);
    assert.equal(mostrarAlerta({ oferta: 0, atrasado: 1 }), true);
});
