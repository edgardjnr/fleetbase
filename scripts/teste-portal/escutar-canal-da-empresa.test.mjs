// Consumidor próprio do canal company.<uuid> (packages/fleetops/addon/utils/escutar-canal-da-empresa.js).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import escutarCanalDaEmpresa from '../../packages/fleetops/addon/utils/escutar-canal-da-empresa.js';

const esperar = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// canal do SocketCluster em memória: o consumidor é um iterador assíncrono com return()
function canalFalso(nome) {
    const fila = [];
    let acordar = null;
    let fechado = false;
    const consumidor = {
        [Symbol.asyncIterator]() {
            return this;
        },
        async next() {
            while (!fila.length && !fechado) {
                await new Promise((resolve) => (acordar = resolve));
            }
            if (!fila.length) return { done: true, value: undefined };
            return { done: false, value: fila.shift() };
        },
        async return() {
            fechado = true;
            acordar?.();
            return { done: true, value: undefined };
        },
    };
    return {
        nome,
        consumidor,
        emitir(mensagem) {
            fila.push(mensagem);
            acordar?.();
        },
        fechar() {
            fechado = true;
            acordar?.();
        },
    };
}

function socketFalso() {
    const canais = [];
    return {
        canais,
        instance() {
            return {
                subscribe(nome) {
                    const canal = canalFalso(nome);
                    canais.push(canal);
                    return { createConsumer: () => canal.consumidor };
                },
            };
        },
    };
}

test('entrega as mensagens do canal da empresa', async () => {
    const socket = socketFalso();
    const recebidas = [];
    const escuta = escutarCanalDaEmpresa({ socket, currentUser: { companyId: 'emp-1' }, aoReceber: (m) => recebidas.push(m), esperaMs: 10 });
    await esperar(5);
    assert.equal(socket.canais[0].nome, 'company.emp-1');
    socket.canais[0].emitir({ event: 'order.updated' });
    await esperar(5);
    assert.deepEqual(recebidas, [{ event: 'order.updated' }]);
    escuta.parar();
});

test('canal fechado por outra tela: inscreve de novo depois da espera', async () => {
    const socket = socketFalso();
    const recebidas = [];
    const escuta = escutarCanalDaEmpresa({ socket, currentUser: { companyId: 'emp-1' }, aoReceber: (m) => recebidas.push(m.event), esperaMs: 10 });
    await esperar(5);
    socket.canais[0].fechar();
    await esperar(30);
    assert.equal(socket.canais.length, 2);
    socket.canais[1].emitir({ event: 'entregas.motoboy_online' });
    await esperar(5);
    assert.deepEqual(recebidas, ['entregas.motoboy_online']);
    escuta.parar();
});

test('sem empresa ainda: espera e tenta de novo', async () => {
    const socket = socketFalso();
    const usuario = { companyId: null };
    const escuta = escutarCanalDaEmpresa({ socket, currentUser: usuario, aoReceber: () => {}, esperaMs: 10 });
    await esperar(5);
    assert.equal(socket.canais.length, 0);
    usuario.companyId = 'emp-2';
    await esperar(30);
    assert.equal(socket.canais[0]?.nome, 'company.emp-2');
    escuta.parar();
});

test('parar: fecha o consumidor e não entrega mais nada', async () => {
    const socket = socketFalso();
    const recebidas = [];
    const escuta = escutarCanalDaEmpresa({ socket, currentUser: { companyId: 'emp-1' }, aoReceber: (m) => recebidas.push(m), esperaMs: 10 });
    await esperar(5);
    escuta.parar();
    socket.canais[0].emitir({ event: 'order.updated' });
    await esperar(30);
    assert.deepEqual(recebidas, []);
    assert.equal(socket.canais.length, 1);
});

test('erro no aoReceber não derruba a escuta', async () => {
    const socket = socketFalso();
    const recebidas = [];
    const erros = [];
    const escuta = escutarCanalDaEmpresa({
        socket,
        currentUser: { companyId: 'emp-1' },
        aoReceber: (m) => {
            if (m.event === 'quebra') throw new Error('falhou');
            recebidas.push(m.event);
        },
        aoFalhar: (erro) => erros.push(erro.message),
        esperaMs: 10,
    });
    await esperar(5);
    socket.canais[0].emitir({ event: 'quebra' });
    socket.canais[0].emitir({ event: 'order.updated' });
    await esperar(5);
    assert.deepEqual(recebidas, ['order.updated']);
    assert.deepEqual(erros, ['falhou']);
    escuta.parar();
});
