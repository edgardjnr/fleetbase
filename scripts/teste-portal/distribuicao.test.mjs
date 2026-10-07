// Distribuição de pedidos abertos no console (packages/fleetops/addon/utils/distribuicao.js): o painel do detalhe do pedido.
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/distribuicao.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    FASES,
    MOTIVOS,
    RESPOSTAS,
    agruparPorVolta,
    chaveDaFase,
    chaveDoMotivo,
    chaveDaResposta,
    emRodadas,
    horaCurta,
    inteiroPositivo,
    kmTexto,
    minutos,
    podeAbrir,
    segundosRestantes,
    textosDoBotao,
} from '../../packages/fleetops/addon/utils/distribuicao.js';

test('chaves de tradução: conhecidas e desconhecidas', () => {
    assert.deepEqual(FASES, ['ofertas', 'aberta', 'encerrada']);
    assert.equal(chaveDaFase('ofertas'), 'ofertas');
    assert.equal(chaveDaFase('outra'), 'desconhecida');
    assert.ok(MOTIVOS.includes('aberta_pela_central') && MOTIVOS.includes('redespachada') && MOTIVOS.includes('falha'));
    assert.equal(chaveDoMotivo('fila_esgotada'), 'fila_esgotada');
    assert.equal(chaveDoMotivo('falha'), 'falha');
    assert.equal(chaveDoMotivo(null), null);
    assert.equal(chaveDoMotivo('x'), 'desconhecido');
    assert.deepEqual(RESPOSTAS, ['pendente', 'aceita', 'recusada', 'vencida', 'cancelada', 'dispensada', 'aceita_pela_lista']);
    assert.equal(chaveDaResposta('vencida'), 'vencida');
    assert.equal(chaveDaResposta('dispensada'), 'dispensada');
    assert.equal(chaveDaResposta('aceita_pela_lista'), 'aceita_pela_lista');
    assert.equal(chaveDaResposta('x'), 'desconhecida');
});

test('segundos restantes da oferta', () => {
    const agora = Date.parse('2026-10-07T13:00:00Z');
    assert.equal(segundosRestantes('2026-10-07T10:00:30-03:00', agora), 30);
    assert.equal(segundosRestantes('2026-10-07T10:00:20-03:00', agora), 20, 'oferta de 20 s das rodadas');
    assert.equal(segundosRestantes('2026-10-07T10:00:00.400-03:00', agora), 1, 'arredonda para cima');
    assert.equal(segundosRestantes('2026-10-07T09:59:00-03:00', agora), 0, 'venceu: 0');
    assert.equal(segundosRestantes(null, agora), 0);
    assert.equal(segundosRestantes('lixo', agora), 0);
});

test('minutos do tempo estimado', () => {
    assert.equal(minutos(0), 0);
    assert.equal(minutos(59), 1);
    assert.equal(minutos(600), 10);
    assert.equal(minutos(null), null);
});

test('rodadas: só com rodadas === true na resposta', () => {
    assert.equal(emRodadas({ rodadas: true }), true);
    assert.equal(emRodadas({ rodadas: false }), false);
    assert.equal(emRodadas({ rodadas: 'true' }), false);
    assert.equal(emRodadas({}), false);
    assert.equal(emRodadas(null), false);
});

test('volta e rodada: inteiro positivo ou o padrão', () => {
    assert.equal(inteiroPositivo(2), 2);
    assert.equal(inteiroPositivo('3'), 3);
    assert.equal(inteiroPositivo(null), 1);
    assert.equal(inteiroPositivo(undefined), 1);
    assert.equal(inteiroPositivo(''), 1);
    assert.equal(inteiroPositivo(0), 1);
    assert.equal(inteiroPositivo(-2), 1);
    assert.equal(inteiroPositivo(1.5), 1);
    assert.equal(inteiroPositivo('abc', 7), 7);
});

test('raio em km no idioma ativo', () => {
    assert.equal(kmTexto(6000), '6');
    assert.equal(kmTexto(9000, 'pt-br'), '9');
    assert.equal(kmTexto(7500), '7,5');
    assert.equal(kmTexto(7500, 'en-us'), '7.5');
    assert.equal(kmTexto(12340), '12,3');
    assert.equal(kmTexto('6000'), '6');
    assert.equal(kmTexto(0), null);
    assert.equal(kmTexto(-5), null);
    assert.equal(kmTexto(null), null);
    assert.equal(kmTexto(''), null);
    assert.equal(kmTexto('abc'), null);
});

test('hora curta da lista aberta', () => {
    assert.equal(horaCurta('2026-10-07T05:31:00-03:00', 'pt-BR', 'America/Sao_Paulo'), '05:31');
    assert.equal(horaCurta('2026-10-07T08:31:00Z', 'pt-br', 'America/Sao_Paulo'), '05:31');
    assert.equal(horaCurta('2026-10-07T17:05:00-03:00', 'en-US', 'America/Sao_Paulo'), '17:05', '24 h também em inglês');
    assert.equal(horaCurta(null), null);
    assert.equal(horaCurta(''), null);
    assert.equal(horaCurta('lixo'), null);
});

test('histórico agrupado por volta, na ordem em que as voltas aparecem', () => {
    const a = { posicao: 1, volta: 1 };
    const b = { posicao: 2, volta: 1 };
    const c = { posicao: 3, volta: 2 };
    const semVolta = { posicao: 4 };
    const texto = { posicao: 5, volta: '2' };
    assert.deepEqual(agruparPorVolta([a, b, c, semVolta, texto]), [
        { volta: 1, ofertas: [a, b, semVolta] },
        { volta: 2, ofertas: [c, texto] },
    ]);
    assert.deepEqual(agruparPorVolta([]), []);
    assert.deepEqual(agruparPorVolta(null), []);
});

test('pode abrir a todos: só em ofertas, ligada e com pedido não encerrado', () => {
    assert.equal(podeAbrir({ fase: 'ofertas' }, 'dispatched'), true);
    assert.equal(podeAbrir({ fase: 'ofertas', ligada: true }, 'dispatched'), true);
    assert.equal(podeAbrir({ fase: 'ofertas', ligada: false }, 'dispatched'), false);
    assert.equal(podeAbrir({ fase: 'aberta' }, 'dispatched'), false);
    assert.equal(podeAbrir({ fase: 'ofertas' }, 'canceled'), false);
    assert.equal(podeAbrir(null, 'dispatched'), false);
});

test('mostrar a todos (rodadas): também só com a lista ainda fechada', () => {
    assert.equal(podeAbrir({ fase: 'ofertas', rodadas: true, lista_aberta_em: null }, 'dispatched'), true);
    assert.equal(podeAbrir({ fase: 'ofertas', rodadas: true }, 'dispatched'), true);
    assert.equal(podeAbrir({ fase: 'ofertas', rodadas: true, lista_aberta_em: '2026-10-07T05:31:00-03:00' }, 'dispatched'), false);
    assert.equal(podeAbrir({ fase: 'ofertas', rodadas: true, ligada: false }, 'dispatched'), false);
    assert.equal(podeAbrir({ fase: 'ofertas', rodadas: true }, 'completed'), false);
    assert.equal(podeAbrir({ fase: 'ofertas', rodadas: false, lista_aberta_em: '2026-10-07T05:31:00-03:00' }, 'dispatched'), true, 'sem rodadas a lista não conta');
});

test('textos do botão: mostrar (rodadas) ou abrir', () => {
    assert.deepEqual(textosDoBotao({ rodadas: true }), { botao: 'mostrar', titulo: 'mostrar-titulo', texto: 'mostrar-texto', feito: 'mostrada', icone: 'list' });
    assert.deepEqual(textosDoBotao({ rodadas: false }), { botao: 'abrir', titulo: 'abrir-titulo', texto: 'abrir-texto', feito: 'aberto', icone: 'bullhorn' });
    assert.deepEqual(textosDoBotao(null), { botao: 'abrir', titulo: 'abrir-titulo', texto: 'abrir-texto', feito: 'aberto', icone: 'bullhorn' });
});
