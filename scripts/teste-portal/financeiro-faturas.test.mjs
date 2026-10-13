// Lojas e faturas do Financeiro no console (packages/fleetops/addon/utils/financeiro-faturas.js).
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/financeiro-faturas.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    agruparLancamentosPorDia,
    chaveDoNaoEnvio,
    corDaFatura,
    corDaSituacao,
    dataBr,
    filtrarFaturas,
    jaNaoCobrado,
    lerValor,
    linhasCsvDaFatura,
    linhasCsvDoExtratoDaLoja,
    numeroOuRascunho,
    opcoesDeDia,
    podeEstornarNaLoja,
    podeNaoCobrar,
    rotuloDaFatura,
    textoDoCiclo,
    validarAjuste,
    validarCiclo,
    validarGerarFatura,
} from '../../packages/fleetops/addon/utils/financeiro-faturas.js';

const emitida = (extra = {}) => ({ id: 'f1', numero: 'FAT-0031', situacao: 'emitida', pagamento: 'aberta', vencida: false, loja: { id: 'vendor_a', nome: 'Pizzaria' }, ...extra });

test('rótulo da fatura, na precedência rascunho > cancelada > vencida > paga > parcial > aberta', () => {
    assert.equal(rotuloDaFatura({ situacao: 'rascunho', pagamento: null }), 'rascunho');
    assert.equal(rotuloDaFatura({ situacao: 'cancelada', pagamento: 'aberta', vencida: true }), 'cancelada', 'cancelada nunca aparece como vencida');
    assert.equal(rotuloDaFatura(emitida({ vencida: true, pagamento: 'parcial' })), 'vencida');
    assert.equal(rotuloDaFatura(emitida({ pagamento: 'paga' })), 'paga');
    assert.equal(rotuloDaFatura(emitida({ pagamento: 'parcial' })), 'parcial');
    assert.equal(rotuloDaFatura(emitida()), 'aberta');
    assert.equal(rotuloDaFatura(null), 'aberta', 'sem dados: aberta (nunca quebra a tela)');
});

test('cor da fatura e da situação do lançamento', () => {
    assert.match(corDaFatura(emitida({ vencida: true })), /red/);
    assert.match(corDaFatura(emitida({ pagamento: 'paga' })), /green/);
    assert.match(corDaFatura(emitida({ pagamento: 'parcial' })), /yellow/);
    assert.match(corDaFatura({ situacao: 'rascunho' }), /blue/);
    assert.match(corDaFatura({ situacao: 'cancelada' }), /line-through/);
    assert.match(corDaFatura(emitida()), /gray/);

    assert.match(corDaSituacao('pago'), /green/);
    assert.match(corDaSituacao('parcial'), /yellow/);
    assert.match(corDaSituacao('anulado'), /line-through/);
    assert.match(corDaSituacao('aberto'), /gray/);
    assert.equal(corDaSituacao(null), '');
});

test('filtrar faturas por situação e loja', () => {
    const faturas = [
        { id: 'r', situacao: 'rascunho', pagamento: null, loja: { id: 'vendor_a' } },
        emitida({ id: 'a' }),
        emitida({ id: 'p', pagamento: 'parcial', loja: { id: 'vendor_b' } }),
        emitida({ id: 'v', vencida: true }),
        emitida({ id: 'g', pagamento: 'paga', loja: { id: 'vendor_b' } }),
        { id: 'c', situacao: 'cancelada', pagamento: 'aberta', loja: { id: 'vendor_a' } },
    ];
    const ids = (lista) => lista.map((fatura) => fatura.id);

    assert.deepEqual(ids(filtrarFaturas(faturas, 'todas')), ['r', 'a', 'p', 'v', 'g', 'c']);
    assert.deepEqual(ids(filtrarFaturas(faturas, {})), ['r', 'a', 'p', 'v', 'g', 'c'], 'sem filtro: todas');
    assert.deepEqual(ids(filtrarFaturas(faturas, 'rascunho')), ['r']);
    assert.deepEqual(ids(filtrarFaturas(faturas, 'aberta')), ['a', 'p', 'v'], 'em aberto = emitida e não paga (inclui parcial e vencida)');
    assert.deepEqual(ids(filtrarFaturas(faturas, 'vencida')), ['v']);
    assert.deepEqual(ids(filtrarFaturas(faturas, 'paga')), ['g']);
    assert.deepEqual(ids(filtrarFaturas(faturas, 'cancelada')), ['c']);
    assert.deepEqual(ids(filtrarFaturas(faturas, { situacao: 'todas', loja: 'vendor_b' })), ['p', 'g']);
    assert.deepEqual(ids(filtrarFaturas(faturas, { situacao: 'aberta', loja: 'vendor_b' })), ['p']);
    assert.deepEqual(filtrarFaturas(null, 'todas'), []);
    assert.deepEqual(filtrarFaturas([], 'aberta'), []);
});

test('texto do ciclo', () => {
    assert.deepEqual(textoDoCiclo({ ciclo: 'semanal', dia_fechamento: 0, prazo_dias: 3 }), {
        chave: 'ciclo-texto.semanal',
        params: { prazo: 3 },
        chaveDoDia: 'dias-semana.0',
    });
    assert.deepEqual(textoDoCiclo({ ciclo: 'quinzenal', dia_fechamento: 0, prazo_dias: 5 }), { chave: 'ciclo-texto.quinzenal', params: { prazo: 5 }, chaveDoDia: null });
    assert.deepEqual(textoDoCiclo({ ciclo: 'mensal', dia_fechamento: 10, prazo_dias: 0 }), { chave: 'ciclo-texto.mensal', params: { prazo: 0, dia: 10 }, chaveDoDia: null });
    assert.deepEqual(textoDoCiclo({ ciclo: 'mensal', dia_fechamento: 0, prazo_dias: 7 }), { chave: 'ciclo-texto.mensal-ultimo-dia', params: { prazo: 7 }, chaveDoDia: null });
    assert.deepEqual(textoDoCiclo({ ciclo: 'semanal', dia_fechamento: '6', prazo_dias: '2' }).chaveDoDia, 'dias-semana.6', 'números como texto');
    assert.deepEqual(textoDoCiclo(null), { chave: 'ciclo-texto.desconhecido', params: {}, chaveDoDia: null });
    assert.deepEqual(textoDoCiclo({ ciclo: 'anual' }), { chave: 'ciclo-texto.desconhecido', params: {}, chaveDoDia: null });
});

test('opções do dia de fechamento', () => {
    const semanal = opcoesDeDia('semanal');
    assert.equal(semanal.length, 7);
    assert.deepEqual(semanal[0], { valor: 0, chave: 'dias-semana.0', params: {} });
    assert.deepEqual(semanal[6], { valor: 6, chave: 'dias-semana.6', params: {} });

    const mensal = opcoesDeDia({ ciclo: 'mensal' });
    assert.equal(mensal.length, 29, 'último dia + 1..28');
    assert.deepEqual(mensal[0], { valor: 0, chave: 'ultimo-dia', params: {} });
    assert.deepEqual(mensal[1], { valor: 1, chave: 'dia-do-mes', params: { dia: 1 } });
    assert.deepEqual(mensal[28], { valor: 28, chave: 'dia-do-mes', params: { dia: 28 } });

    assert.deepEqual(opcoesDeDia('quinzenal'), [], 'quinzenal não tem dia');
    assert.deepEqual(opcoesDeDia(null), []);
});

test('validar ciclo (mesmas regras do servidor)', () => {
    assert.deepEqual(validarCiclo({ ciclo: 'semanal', dia_fechamento: 0, prazo_dias: 3 }), []);
    assert.deepEqual(validarCiclo({ ciclo: 'quinzenal', dia_fechamento: 0, prazo_dias: '10' }), []);
    assert.deepEqual(validarCiclo({ ciclo: 'mensal', dia_fechamento: 28, prazo_dias: 60 }), []);
    assert.deepEqual(validarCiclo({ ciclo: 'anual', dia_fechamento: 0, prazo_dias: 3 }), ['ciclo-invalido']);
    assert.deepEqual(validarCiclo({ ciclo: 'semanal', dia_fechamento: 7, prazo_dias: 3 }), ['dia-invalido']);
    assert.deepEqual(validarCiclo({ ciclo: 'mensal', dia_fechamento: 29, prazo_dias: 3 }), ['dia-invalido']);
    assert.deepEqual(validarCiclo({ ciclo: 'semanal', dia_fechamento: 1, prazo_dias: 61 }), ['prazo-invalido']);
    assert.deepEqual(validarCiclo({ ciclo: 'semanal', dia_fechamento: 1, prazo_dias: '' }), ['prazo-invalido']);
    assert.deepEqual(validarCiclo({ ciclo: 'semanal', dia_fechamento: 1, prazo_dias: '2,5' }), ['prazo-invalido']);
});

test('número ou rascunho', () => {
    assert.equal(numeroOuRascunho(emitida(), 'Rascunho'), 'FAT-0031');
    assert.equal(numeroOuRascunho({ numero: null }, 'Rascunho'), 'Rascunho');
    assert.equal(numeroOuRascunho(null), 'Rascunho');
});

test('ler valor digitado', () => {
    assert.equal(lerValor('12,50'), 12.5);
    assert.equal(lerValor('-12,5'), -12.5);
    assert.equal(lerValor('1.234,56'), 1234.56);
    assert.equal(lerValor('12.5'), 12.5);
    assert.equal(lerValor(' 30 '), 30);
    assert.equal(lerValor('1.500'), 1500, 'milhar com ponto, não 1,50');
    assert.equal(lerValor('-1.500,00'), -1500);
    assert.ok(Number.isNaN(lerValor('1,505')));
    assert.ok(Number.isNaN(lerValor('')));
    assert.ok(Number.isNaN(lerValor('abc')));
    assert.ok(Number.isNaN(lerValor('-')));
    assert.ok(Number.isNaN(lerValor(null)));
});

test('validar ajuste e geração de fatura', () => {
    assert.deepEqual(validarAjuste({ valor: '-10,00', motivo: 'Desconto' }), []);
    assert.deepEqual(validarAjuste({ valor: '0', motivo: 'x' }), ['valor-invalido']);
    assert.deepEqual(validarAjuste({ valor: '', motivo: '  ' }), ['valor-invalido', 'motivo-obrigatorio']);

    assert.deepEqual(validarGerarFatura({ loja: 'vendor_a', inicio: '2026-10-01', fim: '2026-10-07' }), []);
    assert.deepEqual(validarGerarFatura({ loja: 'vendor_a', inicio: '2026-10-07', fim: '2026-10-07' }), [], 'um dia só vale');
    assert.deepEqual(validarGerarFatura({ loja: '', inicio: '2026-10-01', fim: '2026-10-07' }), ['loja-obrigatoria']);
    assert.deepEqual(validarGerarFatura({ loja: 'vendor_a', inicio: '', fim: '2026-10-07' }), ['periodo-obrigatorio']);
    assert.deepEqual(validarGerarFatura({ loja: 'vendor_a', inicio: '2026-10-08', fim: '2026-10-07' }), ['periodo-invertido']);
});

test('não cobrar: só corrida de rascunho ainda não marcada', () => {
    const rascunho = { situacao: 'rascunho' };
    const corrida = { id: 1, tipo: 'corrida', valor: 8.5, pedido: { id: 'order_a', numero: 'RP1' } };
    const outra = { id: 2, tipo: 'corrida', valor: 9, pedido: { id: 'order_b', numero: 'RP2' } };
    const naoCobrar = { id: 3, tipo: 'ajuste', valor: -8.5, pedido: { id: 'order_a', numero: 'RP1' } };
    const itens = [corrida, outra, naoCobrar];

    assert.equal(jaNaoCobrado(corrida, itens), true);
    assert.equal(jaNaoCobrado(outra, itens), false);
    assert.equal(jaNaoCobrado(outra, []), false);
    assert.equal(podeNaoCobrar(outra, rascunho, itens), true);
    assert.equal(podeNaoCobrar(corrida, rascunho, itens), false, 'já marcada');
    assert.equal(podeNaoCobrar(naoCobrar, rascunho, itens), false, 'ajuste não');
    assert.equal(podeNaoCobrar(outra, { situacao: 'emitida' }, itens), false, 'só no rascunho');
    assert.equal(podeNaoCobrar({ ...outra, pedido: null }, rascunho, itens), false, 'sem pedido');
});

test('pode estornar na conta da loja', () => {
    assert.equal(podeEstornarNaLoja({ tipo: 'ajuste', estornado: false, estorno_de: null, fatura: null }), true);
    assert.equal(podeEstornarNaLoja({ tipo: 'corrida', estornado: false, fatura: null }), true);
    assert.equal(podeEstornarNaLoja({ tipo: 'corrida', estornado: false, fatura: { id: 'f', numero: 'FAT-0001' } }), false, 'já faturado: cancele a fatura antes');
    assert.equal(podeEstornarNaLoja({ tipo: 'estorno', estornado: false, estorno_de: 9 }), false);
    assert.equal(podeEstornarNaLoja({ tipo: 'ajuste', estornado: true }), false);
    assert.equal(podeEstornarNaLoja({ tipo: 'recebimento', pagamento: { id: 'p', estornado: false } }), true);
    assert.equal(podeEstornarNaLoja({ tipo: 'recebimento', pagamento: { id: 'p', estornado: true } }), false);
    assert.equal(podeEstornarNaLoja({ tipo: 'recebimento', pagamento: null }), false);
    assert.equal(podeEstornarNaLoja(null), false);
});

test('data em dd/mm/aaaa sem passar por Date (sem fuso)', () => {
    assert.equal(dataBr('2026-10-04'), '04/10/2026');
    assert.equal(dataBr('2026-10-04T10:00:00-03:00'), '04/10/2026');
    assert.equal(dataBr(null), '');
    assert.equal(dataBr('ontem'), '');
});

test('CSV da fatura', () => {
    const fatura = emitida({ periodo_inicio: '2026-09-28', periodo_fim: '2026-10-04', vencimento: '2026-10-08', total: 620, recebido: 100, em_aberto: 520 });
    const itens = [
        { id: 1, tipo: 'corrida', valor: 8.5, data: '2026-09-28', pedido: { id: 'order_a', numero: 'RP1' }, detalhes: { km: 3.21, destino: 'Rua "X", 10' }, motivo: null },
        { id: 2, tipo: 'ajuste', valor: -8.5, data: '2026-09-29', pedido: { id: 'order_a', numero: null }, detalhes: null, motivo: 'Não cobrar' },
        { id: 3, tipo: 'ajuste', valor: 50, data: '2026-10-04', pedido: null, detalhes: null, motivo: null },
    ];
    const rotulos = {
        fatura: 'Fatura',
        loja: 'Loja',
        periodo: 'Período',
        vencimento: 'Vencimento',
        data: 'Data',
        pedido: 'Pedido',
        km: 'Km',
        destino: 'Destino',
        motivo: 'Motivo',
        valor: 'Valor',
        total: 'Total',
        recebido: 'Recebido',
        emAberto: 'Em aberto',
        rascunho: 'Rascunho',
        tipos: { ajuste: 'Ajuste' },
    };
    const linhas = linhasCsvDaFatura(fatura, itens, rotulos);

    assert.deepEqual(linhas, [
        '"Fatura";"FAT-0031"',
        '"Loja";"Pizzaria"',
        '"Período";"28/09/2026 a 04/10/2026"',
        '"Vencimento";"08/10/2026"',
        '',
        '"Data";"Pedido";"Km";"Destino";"Motivo";"Valor"',
        '"28/09/2026";"RP1";3,21;"Rua ""X"", 10";"";8,50',
        '"29/09/2026";"order_a";;"";"Não cobrar";-8,50',
        '"04/10/2026";"";;"";"Ajuste";50,00',
        '',
        '"Total";620,00',
        '"Recebido";100,00',
        '"Em aberto";520,00',
    ]);

    const rascunho = linhasCsvDaFatura({ situacao: 'rascunho', numero: null, loja: null, total: 41.5, recebido: 0, em_aberto: 41.5 }, [], rotulos);
    assert.equal(rascunho[0], '"Fatura";"Rascunho"');
    assert.equal(rascunho[1], '"Loja";""');
    assert.equal(rascunho[5], '"Data";"Pedido";"Km";"Destino";"Motivo";"Valor"');
    assert.equal(rascunho[7], '"Total";41,50');
});

test('agrupar lançamentos por dia, mantendo a ordem (mais novo primeiro)', () => {
    const lancamentos = [
        { id: 5, data: '2026-10-12' },
        { id: 4, data: '2026-10-12' },
        { id: 3, data: '2026-10-10' },
        { id: 2, data: '2026-10-12' },
    ];
    assert.deepEqual(agruparLancamentosPorDia(lancamentos), [
        { data: '2026-10-12', lancamentos: [lancamentos[0], lancamentos[1]] },
        { data: '2026-10-10', lancamentos: [lancamentos[2]] },
        { data: '2026-10-12', lancamentos: [lancamentos[3]] },
    ], 'só junta dias seguidos (a ordem do servidor manda)');
    assert.deepEqual(agruparLancamentosPorDia([]), []);
    assert.deepEqual(agruparLancamentosPorDia(null), []);
});

test('CSV do extrato da loja', () => {
    const extrato = {
        conta: { tipo: 'loja', id: 'vendor_a', nome: 'Pizzaria' },
        inicio: '2026-10-01',
        fim: '2026-10-12',
        lancamentos: [
            { id: 9, tipo: 'recebimento', valor: -100, data: '2026-10-12', pedido: null, motivo: null, fatura: null, situacao: null },
            { id: 8, tipo: 'corrida', valor: 8.5, data: '2026-10-11', pedido: { id: 'order_a', numero: 'RP1' }, motivo: null, fatura: { id: 'f', numero: 'FAT-0002' }, situacao: 'pago' },
            { id: 7, tipo: 'ajuste', valor: -2, data: '2026-10-10', pedido: null, motivo: 'Desconto "amigo"', fatura: null, situacao: null },
        ],
    };
    const rotulos = {
        data: 'Data',
        tipo: 'Tipo',
        pedido: 'Pedido',
        motivo: 'Motivo',
        fatura: 'Fatura',
        valor: 'Valor',
        situacao: 'Situação',
        tipos: { recebimento: 'Recebimento', corrida: 'Corrida', ajuste: 'Ajuste' },
        situacoes: { pago: 'Pago' },
    };
    assert.deepEqual(linhasCsvDoExtratoDaLoja(extrato, rotulos), [
        '"Data";"Tipo";"Pedido";"Motivo";"Fatura";"Valor";"Situação"',
        '"12/10/2026";"Recebimento";"";"";"";-100,00;""',
        '"11/10/2026";"Corrida";"RP1";"";"FAT-0002";8,50;"Pago"',
        '"10/10/2026";"Ajuste";"";"Desconto ""amigo""";"";-2,00;""',
    ]);
    assert.deepEqual(linhasCsvDoExtratoDaLoja({ lancamentos: [] }, rotulos), ['"Data";"Tipo";"Pedido";"Motivo";"Fatura";"Valor";"Situação"']);
});

test('aviso do reenviar sem envio segue o motivo do servidor', () => {
    assert.equal(chaveDoNaoEnvio('sem_email'), 'email-nao-enviado');
    assert.equal(chaveDoNaoEnvio('desligado'), 'email-nao-enviado-desligado');
    assert.equal(chaveDoNaoEnvio('falha'), 'email-nao-enviado-falha');
    assert.equal(chaveDoNaoEnvio(undefined), 'email-nao-enviado-falha', 'servidor antigo, sem motivo: o aviso genérico (não culpa a loja)');
});
