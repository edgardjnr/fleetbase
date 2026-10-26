// Saque Pix no console (packages/fleetops/addon/utils/financeiro-saque-pix.js): quem configura, rotas liberadas ao admin
// da organização, etapas do card, teste da validação, limites, situação dos saques, carência e links do comprovante.
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/financeiro-saque-pix.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    API_DO_SAQUE_PIX,
    ESPERA_DO_TESTE_MS,
    INTERVALO_DO_TESTE_MS,
    SITUACOES_DO_SAQUE,
    avisoDeCarencia,
    chaveDaSituacaoDoSaque,
    consultaDosSaques,
    ehAdminDaCentral,
    etapasDoSaque,
    limitesParaEdicao,
    linhaDoMotoboyComPix,
    linhaDoSaque,
    linkSeguro,
    resultadoDoTeste,
    rotaDoAdminDaCentral,
    segundosRestantes,
    seloDoSaque,
    validarLimites,
} from '../../packages/fleetops/addon/utils/financeiro-saque-pix.js';

const S = 'fleet-ops.ui.financeiro.saque-pix';

test('rota da API', () => {
    assert.equal(API_DO_SAQUE_PIX, 'entregas/financeiro/saque-pix');
});

test('quem configura: admin do sistema ou papel Administrator na organização', () => {
    assert.equal(ehAdminDaCentral({ isAdmin: true, roleName: 'Operador' }), true);
    assert.equal(ehAdminDaCentral({ isAdmin: false, roleName: 'Administrator' }), true);
    assert.equal(ehAdminDaCentral({ isAdmin: false, roleName: 'Administrador' }), false, 'vale o nome gravado do papel (em inglês)');
    assert.equal(ehAdminDaCentral({ isAdmin: undefined, roleName: undefined }), false);
    assert.equal(ehAdminDaCentral(null), false);
});

test('rotas liberadas ao admin da organização: Saques Pix e Configurações', () => {
    for (const nome of ['console.fleet-ops.financeiro.saques', 'console.fleet-ops.financeiro.configuracoes', 'console.fleet-ops.financeiro.index', 'console.fleet-ops.financeiro', 'financeiro.saques']) {
        assert.equal(rotaDoAdminDaCentral(nome), true, nome);
    }
    for (const nome of ['console.fleet-ops.financeiro.painel', 'console.fleet-ops.financeiro.motoboy', 'console.fleet-ops.financeiro.corridas-do-sistema', 'console.fleet-ops.operations.orders.index', '', null]) {
        assert.equal(rotaDoAdminDaCentral(nome), false, String(nome));
    }
});

test('etapas do card: conectar, token, validação e ligar', () => {
    assert.deepEqual(etapasDoSaque(null), { conectada: false, tokenGerado: false, validada: false, ligado: false, podeConectar: true, podeTestar: false, podeLigar: false });
    assert.equal(etapasDoSaque({ conectada: false, cofre: false }).podeConectar, false, 'sem o secret no servidor: não conecta');
    const conectada = { conectada: true, cofre: true, token_gerado: false, validacao_confirmada_em: null, saque_ligado: false };
    assert.deepEqual(etapasDoSaque(conectada), { conectada: true, tokenGerado: false, validada: false, ligado: false, podeConectar: true, podeTestar: false, podeLigar: false });
    assert.equal(etapasDoSaque({ ...conectada, token_gerado: true }).podeTestar, true);
    const validada = { ...conectada, token_gerado: true, validacao_confirmada_em: '2026-10-27T10:00:05-03:00' };
    assert.equal(etapasDoSaque(validada).podeLigar, true);
    assert.equal(etapasDoSaque({ ...validada, saque_ligado: true }).ligado, true);
    assert.equal(etapasDoSaque({ ...conectada, saque_ligado: true }).ligado, false, 'ligado sem validação não conta');
});

test('teste da validação: confirmada, esperando até 60 s e sem resposta', () => {
    const t0 = 1000000;
    assert.equal(ESPERA_DO_TESTE_MS, 60000);
    assert.equal(INTERVALO_DO_TESTE_MS, 3000);
    assert.equal(resultadoDoTeste({ validacao_confirmada_em: null }, null, t0), null, 'nenhum teste pedido nesta tela');
    assert.equal(resultadoDoTeste({ validacao_confirmada_em: null }, t0, t0 + 3000), 'esperando');
    assert.equal(resultadoDoTeste({ validacao_confirmada_em: null }, t0, t0 + ESPERA_DO_TESTE_MS - 1), 'esperando');
    assert.equal(resultadoDoTeste({ validacao_confirmada_em: null }, t0, t0 + ESPERA_DO_TESTE_MS), 'sem-resposta');
    assert.equal(resultadoDoTeste({ validacao_confirmada_em: '2026-10-27T10:00:05-03:00' }, t0, t0 + 999999), 'confirmada');
    assert.equal(resultadoDoTeste({ validacao_confirmada_em: '2026-10-27T10:00:05-03:00' }, null, t0), 'confirmada', 'já confirmada antes de abrir a tela');
    assert.equal(segundosRestantes(t0, t0), 60);
    assert.equal(segundosRestantes(t0, t0 + 59001), 1);
    assert.equal(segundosRestantes(t0, t0 + 70000), 0);
});

test('limites: edição e validação iguais às do servidor', () => {
    assert.deepEqual(limitesParaEdicao({ minimo: 10, maximo_dia: 500, saques_dia: 3, taxa: 0 }), { minimo: '10,00', maximo_dia: '500,00', saques_dia: '3', taxa: '0,00' });
    assert.deepEqual(limitesParaEdicao(undefined), { minimo: '10,00', maximo_dia: '500,00', saques_dia: '3', taxa: '0,00' }, 'sem conexão: os padrões');
    assert.deepEqual(validarLimites({ minimo: '20', maximo_dia: '1.500', saques_dia: '2', taxa: '1,5' }), { erro: null, corpo: { minimo: '20.00', maximo_dia: '1500.00', saques_dia: 2, taxa: '1.50' } });
    const erro = (campos) => validarLimites({ minimo: '10', maximo_dia: '500', saques_dia: '3', taxa: '0', ...campos }).erro;
    assert.equal(erro({}), null);
    assert.equal(erro({ minimo: 'abc' }), 'minimo-invalido');
    assert.equal(erro({ minimo: '0,50' }), 'minimo-baixo');
    assert.equal(erro({ minimo: '1,99' }), 'minimo-baixo', 'pelo menos R$ 2,00: não se confunde com o teste de R$ 1,00');
    assert.equal(erro({ minimo: '2', maximo_dia: '2' }), null, 'R$ 2,00 vale');
    assert.equal(erro({ minimo: '10.000.000' }), 'minimo-invalido', 'acima de R$ 9.999.999,99 o servidor recusa');
    assert.equal(erro({ maximo_dia: '' }), 'maximo-invalido');
    assert.equal(erro({ maximo_dia: '10.000.000' }), 'maximo-invalido', 'acima de R$ 9.999.999,99');
    assert.equal(erro({ maximo_dia: '9.999.999,99' }), null, 'o maior valor vale');
    assert.equal(erro({ maximo_dia: '9,99' }), 'maximo-menor');
    assert.equal(erro({ maximo_dia: '10' }), null, 'máximo igual ao mínimo vale');
    assert.equal(erro({ saques_dia: '0' }), 'saques-dia');
    assert.equal(erro({ saques_dia: '21' }), 'saques-dia');
    assert.equal(erro({ saques_dia: '2,5' }), 'saques-dia');
    assert.equal(erro({ saques_dia: 4 }), null, 'número também vale');
    assert.equal(erro({ taxa: 'x' }), 'taxa-invalida');
    assert.equal(erro({ taxa: '-1' }), 'taxa-faixa');
    assert.equal(erro({ taxa: '10,01', minimo: '20' }), 'taxa-faixa');
    assert.equal(erro({ taxa: '10' }), 'taxa-maior', 'taxa igual ao mínimo');
    assert.equal(erro({ taxa: '9,99' }), null);
    assert.equal(validarLimites({ minimo: 'x' }).corpo, null, 'com erro, nada para mandar');
});

test('situação do saque: chave, selo e linha da tabela', () => {
    assert.deepEqual(SITUACOES_DO_SAQUE, ['enviando', 'processando', 'concluido', 'falhou']);
    assert.equal(chaveDaSituacaoDoSaque('concluido'), `${S}.situacoes.concluido`);
    assert.equal(chaveDaSituacaoDoSaque('xyz'), `${S}.situacoes.desconhecida`);
    assert.deepEqual(seloDoSaque({ situacao: 'enviando' }), { status: 'warning', chave: `${S}.situacoes.enviando` });
    assert.deepEqual(seloDoSaque({ situacao: 'processando' }), { status: 'warning', chave: `${S}.situacoes.processando` });
    assert.deepEqual(seloDoSaque({ situacao: 'concluido' }), { status: 'success', chave: `${S}.situacoes.concluido` });
    assert.deepEqual(seloDoSaque({ situacao: 'falhou' }), { status: 'error', chave: `${S}.situacoes.falhou` });
    assert.deepEqual(seloDoSaque(null), { status: 'info', chave: `${S}.situacoes.desconhecida` });

    const linha = linhaDoSaque({
        id: 'u1', valor: 50, taxa: 1.5, situacao: 'falhou', motivo: 'Saldo insuficiente.', comprovante_url: null,
        criado_em: '2026-10-27T10:00:00-03:00', motoboy: { id: 'driver_1', nome: 'João Silva' },
    });
    assert.deepEqual(linha, {
        id: 'u1', quando: '27/10 10:00', motoboyId: 'driver_1', motoboy: 'João Silva', valor: 50, taxa: 1.5,
        status: 'error', chave: `${S}.situacoes.falhou`, motivo: 'Saldo insuficiente.', comprovante: null,
    });
    assert.equal(linhaDoSaque({ situacao: 'concluido', motivo: 'x' }).motivo, '', 'motivo só no que falhou');
    assert.equal(linhaDoSaque({ situacao: 'concluido', comprovante_url: 'https://www.asaas.com/comprovantes/1' }).comprovante, 'https://www.asaas.com/comprovantes/1');
    assert.equal(linhaDoSaque({ motoboy: null }).motoboyId, null);
    assert.equal(linhaDoSaque({ criado_em: null }).quando, '');
});

test('link do comprovante: só https', () => {
    assert.equal(linkSeguro('https://www.asaas.com/c/1'), 'https://www.asaas.com/c/1');
    assert.equal(linkSeguro('  https://x.y/z '), 'https://x.y/z');
    assert.equal(linkSeguro('javascript:alert(1)'), null);
    assert.equal(linkSeguro('http://x.y'), null);
    assert.equal(linkSeguro(null), null);
});

test('filtro da tela Saques Pix', () => {
    assert.deepEqual(consultaDosSaques('falhou'), { situacao: 'falhou' });
    assert.deepEqual(consultaDosSaques(''), {});
    assert.deepEqual(consultaDosSaques('lixo'), {});
});

test('carência depois da troca da chave Pix', () => {
    assert.equal(avisoDeCarencia(null), null);
    assert.equal(avisoDeCarencia({ bloqueado: false, liberado_em: null, alterada_por: null }), null);
    assert.deepEqual(avisoDeCarencia({ liberado_em: '2026-10-27T14:30:00-03:00', alterada_por: 'Fulano' }), { chave: `${S}.carencia-por`, params: { hora: '14:30', dia: '27/10', nome: 'Fulano' } });
    assert.deepEqual(avisoDeCarencia({ liberado_em: '2026-10-27T14:30:00-03:00', alterada_por: null }), { chave: `${S}.carencia`, params: { hora: '14:30', dia: '27/10', nome: '' } });
});

test('motoboy com chave Pix na tela Saques Pix', () => {
    assert.deepEqual(
        linhaDoMotoboyComPix({ id: 'driver_1', nome: 'João', chave: '*******4321', tipo: 'telefone', bloqueado: true, liberado_em: '2026-10-28T11:00:00-03:00' }),
        { id: 'driver_1', nome: 'João', chave: '*******4321', chaveDoTipo: 'fleet-ops.ui.financeiro.pix.tipos.telefone', bloqueado: true, carencia: { hora: '11:00', dia: '28/10' } }
    );
    assert.equal(linhaDoMotoboyComPix({ id: 'd', bloqueado: false, liberado_em: null }).carencia, null);
    assert.equal(linhaDoMotoboyComPix({ id: 'd', tipo: 'banco' }).chaveDoTipo, null, 'tipo desconhecido: sem rótulo');
    assert.equal(linhaDoMotoboyComPix({ id: 'd' }).bloqueado, false);
});
