// Painel "Chamar motoboy" do portal da loja (packages/customer-portal/addon/utils/chamar-motoboy.js): telefone (a regra
// do servidor, ChamadoDaLoja::telefone), valor em centavos, troco, problema de cada passo, corpo da requisição e endereço.
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/chamar-motoboy.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    LIMITES,
    VALOR_MAXIMO_CENTAVOS,
    digitosDoTelefone,
    telefoneValido,
    formatarTelefone,
    centavosDoTexto,
    formatarReais,
    trocoDoMotoboy,
    semNumero,
    pontoValido,
    pontoDoEndereco,
    problemaDoPasso,
    corpoDoChamado,
    linhaDoEndereco,
    enderecoDaSugestao,
    enderecoDoCliente,
    novaChave,
} from '../../packages/customer-portal/addon/utils/chamar-motoboy.js';

const estado = (campos = {}) => ({
    passo: 'pagamento',
    chave: '6f1c0e8a-2b1d-4c3e-9a7f-0d5b8c2e4f10',
    telefone: '(16) 99999-0000',
    nome: ' Mariana Souza ',
    endereco: { rua: 'Rua Olinda, 45', bairro: 'Centro', cidade: 'Ribeirão Preto', uf: 'SP', cep: '14010-000', latitude: -21.18, longitude: -47.81 },
    complemento: 'Ap 12',
    referencia: '',
    pago: false,
    valor: '58,90',
    trocoPara: '100',
    forma: 'dinheiro',
    observacao: ' Tocar o interfone ',
    ...campos,
});

test('limites iguais aos do servidor (ChamadoDaLoja::LIMITES e VALOR_MAXIMO_CENTAVOS)', () => {
    assert.deepEqual(LIMITES, { nome: 120, rua: 190, bairro: 120, cidade: 120, complemento: 190, referencia: 190, observacao: 500 });
    assert.equal(VALOR_MAXIMO_CENTAVOS, 10000000);
});

test('telefone: a mesma regra do servidor', () => {
    assert.equal(digitosDoTelefone('+55 (16) 99999-0000'), '16999990000');
    assert.equal(digitosDoTelefone('016 99999-0000'), '16999990000');
    assert.equal(digitosDoTelefone('55999990000'), '55999990000');
    assert.equal(telefoneValido('(16) 3333-0000'), true);
    assert.equal(telefoneValido('99999-0000'), false);
    assert.equal(telefoneValido('0999990000'), false);
    assert.equal(formatarTelefone('16999990000'), '(16) 99999-0000');
    assert.equal(formatarTelefone('1633330000'), '(16) 3333-0000');
    assert.equal(formatarTelefone('123'), '123');
});

test('valor em centavos e em reais', () => {
    assert.equal(centavosDoTexto('58,90'), 5890);
    assert.equal(centavosDoTexto('R$ 1.234,56'), 123456);
    assert.equal(centavosDoTexto('58.9'), 5890);
    assert.equal(centavosDoTexto('100'), 10000);
    // sem vírgula, "1.500" e "12.345.678" são milhar; "58.9" e "58.90" seguem decimais
    assert.equal(centavosDoTexto('1.500'), 150000);
    assert.equal(centavosDoTexto('R$ 12.500'), 1250000);
    assert.equal(centavosDoTexto('58.90'), 5890);
    assert.equal(centavosDoTexto('1.5'), 150);
    assert.equal(centavosDoTexto('1.5000'), 150);
    assert.equal(centavosDoTexto('1.000.000'), null);
    assert.equal(centavosDoTexto(''), null);
    assert.equal(centavosDoTexto('abc'), null);
    assert.equal(centavosDoTexto('-5'), null);
    assert.equal(centavosDoTexto('100.000,00'), 10000000);
    assert.equal(centavosDoTexto('100.000,01'), null);
    assert.equal(formatarReais(4110), 'R$ 41,10');
    assert.equal(formatarReais(123456), 'R$ 1.234,56');
});

test('troco do motoboy só quando o troco é maior que o valor', () => {
    assert.equal(trocoDoMotoboy(5890, 10000), 4110);
    assert.equal(trocoDoMotoboy(5890, 5890), null);
    assert.equal(trocoDoMotoboy(5890, 5000), null);
    assert.equal(trocoDoMotoboy(null, 10000), null);
});

test('rua sem número e ponto', () => {
    assert.equal(semNumero('Rua Olinda'), true);
    assert.equal(semNumero('Rua Olinda, 45'), false);
    assert.equal(semNumero(''), false);
    assert.equal(pontoValido(-21.18, -47.81), true);
    assert.equal(pontoValido(0, 0), false);
    assert.equal(pontoValido(null, null), false);
    assert.deepEqual(pontoDoEndereco({ location: { type: 'Point', coordinates: [-47.81, -21.18] } }), { latitude: -21.18, longitude: -47.81 });
    assert.deepEqual(pontoDoEndereco({ latitude: '-21.18', longitude: '-47.81' }), { latitude: -21.18, longitude: -47.81 });
    assert.equal(pontoDoEndereco({}), null);
});

test('problema de cada passo', () => {
    assert.equal(problemaDoPasso('cliente', estado({ telefone: '9999' })), 'telefone');
    assert.equal(problemaDoPasso('cliente', estado({ nome: '  ' })), 'nome');
    assert.equal(problemaDoPasso('cliente', estado()), null);
    assert.equal(problemaDoPasso('endereco', estado({ endereco: { rua: '', latitude: -21.18, longitude: -47.81 } })), 'rua');
    assert.equal(problemaDoPasso('endereco', estado({ endereco: { rua: 'Rua A, 1', latitude: null, longitude: null } })), 'ponto');
    assert.equal(problemaDoPasso('endereco', estado()), null);
    assert.equal(problemaDoPasso('pagamento', estado({ pago: null })), 'pago');
    assert.equal(problemaDoPasso('pagamento', estado({ pago: true, valor: '' })), null);
    assert.equal(problemaDoPasso('pagamento', estado({ valor: '0' })), 'valor');
    assert.equal(problemaDoPasso('pagamento', estado({ valor: '100.001,00', trocoPara: '' })), 'valor');
    assert.equal(problemaDoPasso('pagamento', estado({ trocoPara: '50' })), 'troco');
    assert.equal(problemaDoPasso('pagamento', estado({ trocoPara: 'abc' })), 'troco');
    assert.equal(problemaDoPasso('pagamento', estado({ trocoPara: '58,90' })), null);
    assert.equal(problemaDoPasso('pagamento', estado({ trocoPara: '0' })), null);
    assert.equal(problemaDoPasso('pagamento', estado({ forma: 'pix', trocoPara: '50' })), null);
    assert.equal(problemaDoPasso('pagamento', estado({ trocoPara: '' })), null);
    assert.equal(problemaDoPasso('pagamento', estado()), null);
});

test('corpo da requisição', () => {
    assert.deepEqual(corpoDoChamado(estado()), {
        chave: '6f1c0e8a-2b1d-4c3e-9a7f-0d5b8c2e4f10',
        cliente: { nome: 'Mariana Souza', telefone: '16999990000' },
        entrega: { rua: 'Rua Olinda, 45', bairro: 'Centro', cidade: 'Ribeirão Preto', uf: 'SP', cep: '14010-000', latitude: -21.18, longitude: -47.81, complemento: 'Ap 12', referencia: null },
        cobranca: { forma: 'dinheiro', valor: '58,90', troco_para: '100' },
        observacao: 'Tocar o interfone',
    });
    assert.deepEqual(corpoDoChamado(estado({ pago: true })).cobranca, { forma: 'pago' });
    assert.deepEqual(corpoDoChamado(estado({ forma: 'cartao' })).cobranca, { forma: 'cartao', valor: '58,90' });
    assert.equal(corpoDoChamado(estado({ observacao: '' })).observacao, null);
});

test('endereço: linha, sugestão do Google e cliente que já pediu', () => {
    assert.equal(linhaDoEndereco({ rua: 'Rua Olinda, 45', bairro: 'Centro' }, 'Ap 12'), 'Rua Olinda, 45 · Ap 12 · Centro');
    assert.equal(linhaDoEndereco({ rua: 'Rua Olinda, 45' }, ''), 'Rua Olinda, 45');
    const sugestao = { street1: 'Rua Olinda, 45', neighborhood: 'Centro', city: 'Ribeirão Preto', province: 'SP', postal_code: '14010-000', location: { type: 'Point', coordinates: [-47.81, -21.18] } };
    assert.deepEqual(enderecoDaSugestao(sugestao, {}), { rua: 'Rua Olinda, 45', bairro: 'Centro', cidade: 'Ribeirão Preto', uf: 'SP', cep: '14010-000', latitude: -21.18, longitude: -47.81 });
    assert.deepEqual(enderecoDaSugestao({ street1: 'Rua B' }, { latitude: -21.2, longitude: -47.8 }), { rua: 'Rua B', bairro: '', cidade: '', uf: '', cep: '', latitude: -21.2, longitude: -47.8 });
    // a resposta de GET loja/clientes (PedidosDoPortal::clienteDoTelefone): campos do Local podem vir null
    const cliente = { nome: 'Mariana', telefone: '16999990000', pedidos: 3, endereco: { rua: 'RUA OLINDA, 45', bairro: 'CENTRO', cidade: 'RIBEIRÃO PRETO', uf: 'SP', cep: '14010-000', complemento: 'Ap 12', referencia: null, latitude: -21.18, longitude: -47.81 } };
    assert.deepEqual(enderecoDoCliente(cliente), {
        endereco: { rua: 'RUA OLINDA, 45', bairro: 'CENTRO', cidade: 'RIBEIRÃO PRETO', uf: 'SP', cep: '14010-000', latitude: -21.18, longitude: -47.81 },
        complemento: 'Ap 12',
        referencia: '',
    });
    assert.deepEqual(enderecoDoCliente({ endereco: { rua: 'Rua C, 9', bairro: null, cidade: null, uf: null, cep: null, complemento: null, referencia: null, latitude: -21.18, longitude: -47.81 } }), {
        endereco: { rua: 'Rua C, 9', bairro: '', cidade: '', uf: '', cep: '', latitude: -21.18, longitude: -47.81 },
        complemento: '',
        referencia: '',
    });
    assert.equal(enderecoDoCliente({ nome: 'X', endereco: null }), null);
    assert.equal(enderecoDoCliente(null), null);
});

test('chave do chamado: uuid v4 (aceita pelo ChamadoDaLoja::PADRAO_DA_CHAVE)', () => {
    const chave = novaChave();
    assert.match(chave, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    assert.notEqual(novaChave(), chave);
});

test('erro sem resposta do servidor (a rede caiu): o chamado pode ser repetido', async () => {
    const { erroSemResposta } = await import('../../packages/customer-portal/addon/utils/chamar-motoboy.js');
    assert.equal(erroSemResposta(new TypeError('Failed to fetch')), true);
    assert.equal(erroSemResposta(new Error('Error processing response: Unexpected token <')), true);
    assert.equal(erroSemResposta({ message: 'Error processing response' }), true);
    assert.equal(erroSemResposta(new Error('Crédito insuficiente para o pedido.')), false);
    assert.equal(erroSemResposta({ errors: ['Ponto a menos de 30 m da loja.'] }), false);
    assert.equal(erroSemResposta(null), false);
});
