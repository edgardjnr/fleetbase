// Contas da tela de pedidos do Fleet-Ops (packages/fleetops/addon/utils/tela-de-pedidos.js).
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/tela-de-pedidos.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    motoboysDaTela,
    filtrarMotoboys,
    passaNoFiltro,
    colunasDoQuadro,
    progressoDoPrazo,
    abaDaTabela,
    horaLocal,
    ABAS_DA_TABELA,
} from '../../packages/fleetops/addon/utils/tela-de-pedidos.js';

const AGORA = Date.parse('2026-10-25T21:14:00-03:00');
const pedidos = [
    { id: 'p1', numero: '4836', motoboy_id: null, status: 'dispatched', criado_em: '2026-10-25T21:10:00-03:00' },
    { id: 'p2', numero: '4834', motoboy_id: 'm-julia', status: 'started', prazo_em: '2026-10-25T21:40:00-03:00' },
    { id: 'p3', numero: '4827', motoboy_id: 'm-carlos', status: 'started', prazo_em: '2026-10-25T21:30:00-03:00' },
    { id: 'p4', numero: '4821', motoboy_id: 'm-carlos', status: 'enroute', prazo_em: '2026-10-25T21:05:00-03:00' },
];
const motoboys = [
    { id: 'm-carlos', nome: 'Carlos', situacao: 'entrega', online: true, corridas_hoje: 14 },
    { id: 'm-julia', nome: 'Juliana', situacao: 'coleta', online: true, corridas_hoje: 12 },
    { id: 'm-diego', nome: 'Diego', situacao: 'livre', online: true, corridas_hoje: 9 },
    { id: 'm-lucas', nome: 'Lucas', situacao: 'livre', online: true, corridas_hoje: 3 },
    { id: 'm-ana', nome: 'Ana', situacao: 'offline', online: false },
];

test('motoboysDaTela: livres (menos corridas antes), indo à loja, em entrega, offline', () => {
    const lista = motoboysDaTela(motoboys, pedidos);
    assert.deepEqual(lista.map((m) => m.nome), ['Lucas', 'Diego', 'Juliana', 'Carlos', 'Ana']);
    assert.equal(lista.find((m) => m.nome === 'Ana').corridas_hoje, 0);
});

test('motoboysDaTela: o pedido atual é o que está a caminho, e conta os outros', () => {
    const carlos = motoboysDaTela(motoboys, pedidos).find((m) => m.nome === 'Carlos');
    assert.equal(carlos.pedido.id, 'p4');
    assert.equal(carlos.outros, 1);
    assert.equal(motoboysDaTela(motoboys, pedidos).find((m) => m.nome === 'Diego').pedido, null);
});

test('filtrarMotoboys', () => {
    const lista = motoboysDaTela(motoboys, pedidos);
    assert.deepEqual(filtrarMotoboys(lista, 'livre').map((m) => m.nome), ['Lucas', 'Diego']);
    assert.deepEqual(filtrarMotoboys(lista, 'ocupado').map((m) => m.nome), ['Juliana', 'Carlos']);
    assert.equal(filtrarMotoboys(lista, 'todos').length, 5);
});

test('passaNoFiltro: situação e atrasado', () => {
    assert.equal(passaNoFiltro(pedidos[0], 'oferta', AGORA), true);
    assert.equal(passaNoFiltro(pedidos[3], 'rota', AGORA), true);
    assert.equal(passaNoFiltro(pedidos[3], 'atrasado', AGORA), true);
    assert.equal(passaNoFiltro(pedidos[2], 'atrasado', AGORA), false);
    assert.equal(passaNoFiltro(pedidos[2], 'todos', AGORA), true);
});

test('colunasDoQuadro: na ordem da fila', () => {
    const c = colunasDoQuadro(pedidos, AGORA);
    assert.deepEqual(c.oferta.map((p) => p.id), ['p1']);
    assert.deepEqual(c.loja.map((p) => p.id), ['p3', 'p2']);
    assert.deepEqual(c.rota.map((p) => p.id), ['p4']);
});

test('progressoDoPrazo', () => {
    const p = { criado_em: '2026-10-25T21:00:00-03:00', prazo_em: '2026-10-25T21:50:00-03:00' };
    assert.equal(progressoDoPrazo(p, AGORA), 28);
    assert.equal(progressoDoPrazo({ ...p, prazo_em: '2026-10-25T21:10:00-03:00' }, AGORA), 100);
    assert.equal(progressoDoPrazo({ criado_em: p.criado_em }, AGORA), null);
});

test('abaDaTabela: query params → aba', () => {
    assert.equal(abaDaTabela({}), 'todos');
    assert.equal(abaDaTabela({ status: 'active' }), 'andamento');
    assert.equal(abaDaTabela({ status: 'completed' }), 'concluidos');
    assert.equal(abaDaTabela({ unassigned: '1' }), 'sem-motoboy');
    assert.equal(abaDaTabela({ status: 'dispatched' }), 'todos');
    assert.deepEqual(ABAS_DA_TABELA.cancelados, { status: 'canceled' });
});

test('horaLocal: HH:MM do navegador, vazio se inválido', () => {
    const iso = '2026-10-25T21:02:00-03:00';
    const d = new Date(iso);
    assert.equal(horaLocal(iso), `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`);
    assert.equal(horaLocal(null), '');
});
