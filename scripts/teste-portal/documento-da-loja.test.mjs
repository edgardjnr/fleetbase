// CPF/CNPJ e modelo de cobrança da loja na tela Lojas (packages/fleetops/addon/utils/documento-da-loja.js).
// Mesma regra do servidor (App\Support\Entregas\PrePago\PrePago::documento e ::definir).
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/documento-da-loja.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    soDigitos,
    cpfValido,
    cnpjValido,
    tipoDoDocumento,
    documentoValido,
    documentoParaEnviar,
    formatarDocumento,
    mascararDocumento,
    modeloDaLoja,
    errosDaCobranca,
    cobrancaParaEnviar,
} from '../../packages/fleetops/addon/utils/documento-da-loja.js';

test('CPF válido, com e sem pontuação', () => {
    assert.equal(cpfValido('52998224725'), true);
    assert.equal(tipoDoDocumento('529.982.247-25'), 'cpf');
    assert.equal(tipoDoDocumento(' 529 982 247 25 '), 'cpf');
    assert.equal(documentoValido('52998224725'), true);
});

test('CNPJ válido, com e sem pontuação', () => {
    assert.equal(cnpjValido('11222333000181'), true);
    assert.equal(tipoDoDocumento('11.222.333/0001-81'), 'cnpj');
    assert.equal(documentoValido('11222333000181'), true);
});

test('dígito verificador errado não vale', () => {
    assert.equal(documentoValido('529.982.247-24'), false);
    assert.equal(documentoValido('529.982.247-15'), false);
    assert.equal(documentoValido('11.222.333/0001-80'), false);
    assert.equal(documentoValido('11.222.333/0001-91'), false);
});

test('todos os dígitos iguais não valem (passariam no cálculo)', () => {
    assert.equal(documentoValido('000.000.000-00'), false);
    assert.equal(documentoValido('111.111.111-11'), false);
    assert.equal(documentoValido('99999999999'), false);
    assert.equal(documentoValido('00.000.000/0000-00'), false);
    assert.equal(documentoValido('11111111111111'), false);
});

test('tamanho errado não vale', () => {
    assert.equal(documentoValido('5299822472'), false);
    assert.equal(documentoValido('529982247250'), false);
    assert.equal(documentoValido('1122233300018'), false);
    assert.equal(documentoValido('112223330001810'), false);
    assert.equal(cpfValido('11222333000181'), false);
    assert.equal(cnpjValido('52998224725'), false);
});

test('vazio não é documento', () => {
    assert.equal(soDigitos(null), '');
    assert.equal(soDigitos(undefined), '');
    assert.equal(tipoDoDocumento(''), null);
    assert.equal(tipoDoDocumento('  '), null);
    assert.equal(documentoValido(null), false);
    assert.equal(documentoParaEnviar(''), null);
});

test('o que vai ao servidor: só dígitos do documento válido', () => {
    assert.equal(documentoParaEnviar('529.982.247-25'), '52998224725');
    assert.equal(documentoParaEnviar('11.222.333/0001-81'), '11222333000181');
    assert.equal(documentoParaEnviar('529.982.247-24'), null);
});

test('formata CPF e CNPJ pelo tamanho; outro tamanho fica como digitado', () => {
    assert.equal(formatarDocumento('52998224725'), '529.982.247-25');
    assert.equal(formatarDocumento('11222333000181'), '11.222.333/0001-81');
    assert.equal(formatarDocumento('11.222.333/0001-81'), '11.222.333/0001-81');
    assert.equal(formatarDocumento('1234'), '1234');
    assert.equal(formatarDocumento(null), '');
});

test('máscara do card esconde o começo e o fim', () => {
    assert.equal(mascararDocumento('12345678909'), '***.456.789-**');
    assert.equal(mascararDocumento('123.456.789-09'), '***.456.789-**');
    assert.equal(mascararDocumento('12345678000195'), '**.345.678/0001-**');
    assert.equal(mascararDocumento('12.345.678/0001-95'), '**.345.678/0001-**');
    assert.equal(mascararDocumento('123'), '');
    assert.equal(mascararDocumento(null), '');
});

test('modelo da loja: só "pre" é pré-pago; sem o campo, pós-pago', () => {
    assert.equal(modeloDaLoja({ cobranca: { modelo: 'pre', documento: '52998224725' } }), 'pre');
    assert.equal(modeloDaLoja({ cobranca: { modelo: 'pos', documento: null } }), 'pos');
    assert.equal(modeloDaLoja({ cobranca: { modelo: 'outro' } }), 'pos');
    assert.equal(modeloDaLoja({}), 'pos');
    assert.equal(modeloDaLoja(null), 'pos');
});

test('erros da cobrança: pós-pago sem documento pode salvar', () => {
    assert.deepEqual(errosDaCobranca({ modelo: 'pos', documento: '' }), []);
    assert.deepEqual(errosDaCobranca({ modelo: 'pos', documento: '529.982.247-25' }), []);
});

test('erros da cobrança: documento digitado inválido barra nos dois modelos', () => {
    assert.deepEqual(errosDaCobranca({ modelo: 'pos', documento: '529.982.247-24' }), ['documento-invalido']);
    assert.deepEqual(errosDaCobranca({ modelo: 'pre', documento: '111.111.111-11' }, '52998224725'), ['documento-invalido']);
    assert.deepEqual(errosDaCobranca({ modelo: 'pre', documento: '1234' }), ['documento-invalido']);
});

test('erros da cobrança: pré-pago exige o documento digitado ou o já gravado', () => {
    assert.deepEqual(errosDaCobranca({ modelo: 'pre', documento: '' }), ['documento-obrigatorio']);
    assert.deepEqual(errosDaCobranca({ modelo: 'pre', documento: '  ' }, null), ['documento-obrigatorio']);
    assert.deepEqual(errosDaCobranca({ modelo: 'pre', documento: '' }, '11222333000181'), []);
    assert.deepEqual(errosDaCobranca({ modelo: 'pre', documento: '11.222.333/0001-81' }), []);
    assert.deepEqual(errosDaCobranca({ modelo: 'pre', documento: '529.982.247-25' }), []);
});

test('erros da cobrança: modelo fora de pos/pre', () => {
    assert.deepEqual(errosDaCobranca({ modelo: 'misto', documento: '' }), ['modelo-invalido']);
    assert.deepEqual(errosDaCobranca(), ['modelo-invalido']);
});

test('corpo da cobrança: só dígitos, vazio vira null', () => {
    assert.deepEqual(cobrancaParaEnviar({ modelo: 'pre', documento: '11.222.333/0001-81' }), { modelo: 'pre', documento: '11222333000181' });
    assert.deepEqual(cobrancaParaEnviar({ modelo: 'pos', documento: '' }), { modelo: 'pos', documento: null });
    assert.deepEqual(cobrancaParaEnviar({ modelo: 'pos', documento: null }), { modelo: 'pos', documento: null });
    assert.deepEqual(cobrancaParaEnviar({ modelo: 'x', documento: '529.982.247-25' }), { modelo: 'pos', documento: '52998224725' });
});
