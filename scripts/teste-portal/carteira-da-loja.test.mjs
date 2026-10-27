// "Minha carteira" da loja pré-paga no portal (packages/customer-portal/addon/utils/carteira-da-loja.js): modelo da loja,
// números da carteira, valor digitado e limites, selo e acompanhamento da recarga, tempo restante do Pix e imagem do QR.
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    ATALHOS,
    ehPrePaga,
    resumoDaCarteira,
    valorDevido,
    valorDigitado,
    problemaDoValor,
    deveAcompanhar,
    seloDaRecarga,
    tempoRestante,
    imagemDoQr,
} from '../../packages/customer-portal/addon/utils/carteira-da-loja.js';

const carteiraPre = (campos = {}) => ({
    modelo: 'pre',
    credito: 150,
    reservado: 30,
    disponivel: 120,
    minimo_para_pedir: 8,
    pode_pedir: true,
    recarga: { ligada: true, minimo: 10, maximo: 5000 },
    ...campos,
});

test('modelo da loja: pela resposta do resumo do dia ou pela própria carteira', () => {
    assert.equal(ehPrePaga({ carteira: { modelo: 'pre' } }), true);
    assert.equal(ehPrePaga({ modelo: 'pre' }), true);
    assert.equal(ehPrePaga({ carteira: { modelo: 'pos' } }), false);
    assert.equal(ehPrePaga({ em_andamento: 2 }), false, 'resumo antigo, sem carteira: pós-paga');
    assert.equal(ehPrePaga(null), false);
    assert.equal(ehPrePaga(undefined), false);
});

test('números da carteira: decimais em texto viram número; pós-paga é null', () => {
    assert.equal(resumoDaCarteira({ modelo: 'pos' }), null);
    assert.equal(resumoDaCarteira(null), null);

    assert.deepEqual(resumoDaCarteira(carteiraPre({ credito: '150.00', reservado: '30.5', disponivel: '119.50' })), {
        credito: 150,
        reservado: 30.5,
        disponivel: 119.5,
        minimoParaPedir: 8,
        podePedir: true,
        recarga: { ligada: true, minimo: 10, maximo: 5000 },
    });
});

test('números da carteira: pode pedir só fica false quando o servidor diz; limites ausentes usam o padrão', () => {
    assert.equal(resumoDaCarteira(carteiraPre({ pode_pedir: false })).podePedir, false);
    assert.equal(resumoDaCarteira(carteiraPre({ pode_pedir: undefined })).podePedir, true);

    const semRecarga = resumoDaCarteira({ modelo: 'pre', credito: 0 });
    assert.deepEqual(semRecarga.recarga, { ligada: false, minimo: 10, maximo: 5000 }, 'sem a informação: recarga desligada');
    assert.equal(semRecarga.disponivel, null);
    assert.equal(resumoDaCarteira(carteiraPre({ recarga: { ligada: 'sim', minimo: 20, maximo: 1000 } })).recarga.ligada, false, 'só true liga');
});

test('valor devido: só com o crédito negativo', () => {
    assert.equal(valorDevido(carteiraPre({ credito: -12.3 })), 12.3);
    assert.equal(valorDevido(carteiraPre({ credito: '-7.10' })), 7.1);
    assert.equal(valorDevido(carteiraPre({ credito: 0 })), null);
    assert.equal(valorDevido(carteiraPre({ credito: 10 })), null);
    assert.equal(valorDevido(null), null);
});

test('valor digitado: inteiro, vírgula, ponto, R$ e milhar', () => {
    assert.equal(valorDigitado('150'), 150);
    assert.equal(valorDigitado('150,50'), 150.5);
    assert.equal(valorDigitado('150,5'), 150.5);
    assert.equal(valorDigitado('150.50'), 150.5);
    assert.equal(valorDigitado(' R$ 100,00 '), 100);
    assert.equal(valorDigitado('R$1.500,00'), 1500);
    assert.equal(valorDigitado('1.500'), 1500, 'ponto com 3 casas é milhar');
    assert.equal(valorDigitado('1,500.25'), 1500.25);
    assert.equal(valorDigitado('1.000.000'), 1000000);
    assert.equal(valorDigitado('0,10'), 0.1);
    assert.equal(valorDigitado(200), 200, 'número também serve (atalho)');
});

test('valor digitado: o que não dá para ler é null', () => {
    for (const texto of ['', '   ', 'abc', '10a', '-50', '0', '0,00', '150,505', '1,2,3', '1.50.0', '150,', ',50', '1.5,00', null, undefined, {}]) {
        assert.equal(valorDigitado(texto), null, JSON.stringify(texto));
    }
});

test('limites da recarga: mínimo e máximo da carteira, com o padrão sem eles', () => {
    const recarga = { ligada: true, minimo: 10, maximo: 5000 };
    assert.equal(problemaDoValor(10, recarga), null);
    assert.equal(problemaDoValor(5000, recarga), null);
    assert.deepEqual(problemaDoValor(9.99, recarga), { chave: 'minimo', limite: 10 });
    assert.deepEqual(problemaDoValor(5000.01, recarga), { chave: 'maximo', limite: 5000 });
    assert.deepEqual(problemaDoValor(null, recarga), { chave: 'invalido' });
    assert.deepEqual(problemaDoValor(NaN, recarga), { chave: 'invalido' });
    assert.deepEqual(problemaDoValor(30, { minimo: 50, maximo: 100 }), { chave: 'minimo', limite: 50 });
    assert.deepEqual(problemaDoValor(6000, undefined), { chave: 'maximo', limite: 5000 }, 'sem limites: 10 a 5000');
    assert.ok(ATALHOS.every((valor) => problemaDoValor(valor, recarga) === null), 'os atalhos cabem nos limites padrão');
});

test('acompanhamento: pergunta enquanto gera o Pix ou aguarda o pagamento', () => {
    assert.equal(deveAcompanhar('criando'), true);
    assert.equal(deveAcompanhar('aguardando'), true);
    for (const situacao of ['paga', 'expirada', 'falhou', undefined, null, '']) {
        assert.equal(deveAcompanhar(situacao), false, String(situacao));
    }
});

test('selo da recarga: a expirada pela loja vira Cancelada', () => {
    const chave = (recarga) => seloDaRecarga(recarga).chave;
    assert.equal(chave({ situacao: 'paga' }), 'paga');
    assert.equal(chave({ situacao: 'aguardando' }), 'aguardando');
    assert.equal(chave({ situacao: 'criando' }), 'gerando');
    assert.equal(chave({ situacao: 'falhou', motivo: 'Não foi possível gerar o Pix agora.' }), 'falhou');
    assert.equal(chave({ situacao: 'expirada', motivo: 'O Pix expirou sem pagamento.' }), 'expirada');
    assert.equal(chave({ situacao: 'expirada', motivo: 'Pix cancelado.' }), 'cancelada');
    assert.equal(chave({ situacao: 'expirada', motivo: null }), 'expirada');
    assert.equal(chave({ situacao: 'estranha' }), 'desconhecida');
    assert.equal(chave(null), 'desconhecida');

    assert.deepEqual(seloDaRecarga({ situacao: 'paga' }), {
        chave: 'paga',
        traducao: 'customer-portal.ui.entregas.carteira.situacao.paga',
        classe: 'entregas-recarga-selo entregas-recarga-selo-paga',
    });
});

test('tempo restante do Pix: mm:ss, h:mm:ss e expirado', () => {
    const agora = Date.parse('2026-10-27T20:00:00-03:00');
    assert.deepEqual(tempoRestante('2026-10-27T20:30:00-03:00', agora), { segundos: 1800, texto: '30:00', expirou: false });
    assert.deepEqual(tempoRestante('2026-10-27T20:01:05.400-03:00', agora), { segundos: 66, texto: '01:06', expirou: false }, 'arredonda para cima');
    assert.deepEqual(tempoRestante('2026-10-27T23:00:00Z', agora), { segundos: 0, texto: '00:00', expirou: true }, 'outro fuso, mesma hora');
    assert.deepEqual(tempoRestante('2026-10-27T21:05:09-03:00', agora), { segundos: 3909, texto: '1:05:09', expirou: false });
    assert.deepEqual(tempoRestante('2026-10-27T19:00:00-03:00', agora), { segundos: 0, texto: '00:00', expirou: true });
    assert.equal(tempoRestante(null, agora), null);
    assert.equal(tempoRestante('', agora), null);
    assert.equal(tempoRestante('amanhã', agora), null);
});

test('imagem do QR: só base64 limpa vira data URI', () => {
    assert.equal(imagemDoQr('iVBORw0KGgo='), 'data:image/png;base64,iVBORw0KGgo=');
    assert.equal(imagemDoQr('abc+/9=='), 'data:image/png;base64,abc+/9==');
    for (const ruim of [null, undefined, '', 'abc def', 'abc\n', '"><script>', 'data:image/png;base64,abc', 'abc===', 'abc=d', 123]) {
        assert.equal(imagemDoQr(ruim), null, JSON.stringify(ruim));
    }
});
