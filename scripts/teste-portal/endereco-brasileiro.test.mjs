// Busca de endereço pelo Google (packages/ember-ui/addon/utils/endereco-brasileiro.js): exibição no formato brasileiro e
// a marca das sugestões no campo do pedido.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { MINIMO_DE_CARACTERES, dadosDaSugestao, ehSugestaoDoGoogle, linhaDoBairroECidade, novaSessaoDeBusca, temTextoParaBuscar } from '../../packages/ember-ui/addon/utils/endereco-brasileiro.js';

test('linha do bairro e da cidade', () => {
    assert.equal(linhaDoBairroECidade({ neighborhood: 'Jardim Paulista', city: 'Ribeirão Preto', province: 'SP', postal_code: '14025-150' }), 'Jardim Paulista, Ribeirão Preto - SP, 14025-150');
    assert.equal(linhaDoBairroECidade({ city: 'Ribeirão Preto', postal_code: '14025-150' }), 'Ribeirão Preto, 14025-150');
    assert.equal(linhaDoBairroECidade({ neighborhood: ' ', city: 'Ribeirão Preto', province: 'SP' }), 'Ribeirão Preto - SP');
    assert.equal(linhaDoBairroECidade({ province: 'SP' }), 'SP');
    assert.equal(linhaDoBairroECidade({}), '');
    assert.equal(linhaDoBairroECidade(null), '');
});

test('texto para buscar: mínimo de caracteres sem os espaços', () => {
    assert.equal(MINIMO_DE_CARACTERES, 3);
    assert.equal(temTextoParaBuscar('rua'), true);
    assert.equal(temTextoParaBuscar('  ru  '), false);
    assert.equal(temTextoParaBuscar(undefined), false);
});

test('sugestão do Google marcada em meta.entregas_sugestao', () => {
    const sugestao = { street1: 'Rua Olinda, 45', meta: { entregas_sugestao: { place_id: 'ChIJolinda45', sessao: 's-1', texto: 'rua olinda 45' } } };
    assert.deepEqual(dadosDaSugestao(sugestao), { place_id: 'ChIJolinda45', sessao: 's-1', texto: 'rua olinda 45' });
    assert.equal(ehSugestaoDoGoogle(sugestao), true);
    assert.equal(ehSugestaoDoGoogle({ street1: 'Rua Olinda, 45', meta: {} }), false);
    assert.equal(ehSugestaoDoGoogle({ meta: { entregas_sugestao: { place_id: '' } } }), false);
    assert.equal(ehSugestaoDoGoogle(null), false);
});

test('sessão de busca: texto aceito pelo servidor e nova a cada chamada', () => {
    const primeira = novaSessaoDeBusca();
    assert.match(primeira, /^[A-Za-z0-9_-]{8,36}$/);
    assert.notEqual(novaSessaoDeBusca(), primeira);
});
