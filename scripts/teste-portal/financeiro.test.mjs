// Funções puras do Financeiro no console (packages/fleetops/addon/utils/financeiro.js).
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/financeiro.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    agruparPorDia,
    avisoDeAdiantamento,
    corDaSituacao,
    dataDoDia,
    diasDeFechamento,
    formatadores,
    isoDate,
    itemDeAtencao,
    linhasCsvDoExtrato,
    numeroDigitado,
    periodoDoAtalho,
    podeEstornar,
    rotuloDoTipo,
    tiposDaConta,
    validarLancamento,
} from '../../packages/fleetops/addon/utils/financeiro.js';

test('número digitado: vírgula, ponto, sinal e milhar', () => {
    assert.equal(numeroDigitado('30'), 30);
    assert.equal(numeroDigitado('30,5'), 30.5);
    assert.equal(numeroDigitado(' -2,75 '), -2.75);
    assert.equal(numeroDigitado('1.234,56'), 1234.56);
    assert.equal(numeroDigitado('12.5'), 12.5);
    assert.equal(numeroDigitado(8), 8);
    assert.ok(Number.isNaN(numeroDigitado('')), 'vazio não vira 0');
    assert.ok(Number.isNaN(numeroDigitado(null)));
    assert.ok(Number.isNaN(numeroDigitado('abc')));
    assert.ok(Number.isNaN(numeroDigitado('1,2,3')));
});

test('rótulo do tipo', () => {
    assert.equal(rotuloDoTipo('corrida'), 'fleet-ops.ui.financeiro.tipos.corrida');
    assert.equal(rotuloDoTipo('saldo_inicial'), 'fleet-ops.ui.financeiro.tipos.saldo_inicial');
    assert.equal(rotuloDoTipo('estorno'), 'fleet-ops.ui.financeiro.tipos.estorno');
    assert.equal(rotuloDoTipo('outra-coisa'), 'fleet-ops.ui.financeiro.tipos.desconhecido');
    assert.equal(rotuloDoTipo(undefined), 'fleet-ops.ui.financeiro.tipos.desconhecido');
});

test('cor da situação', () => {
    assert.match(corDaSituacao('pago'), /green/);
    assert.match(corDaSituacao('parcial'), /yellow/);
    assert.match(corDaSituacao('aberto'), /gray/);
    assert.match(corDaSituacao('anulado'), /line-through/);
    assert.match(corDaSituacao('anulado'), /gray/);
    assert.match(corDaSituacao('vencida'), /red/);
    assert.match(corDaSituacao(null), /gray/, 'sem situação: neutro');
    assert.doesNotMatch(corDaSituacao('aberto'), /line-through/);
});

test('agrupar por dia mantém a ordem', () => {
    assert.deepEqual(agruparPorDia([]), []);
    assert.deepEqual(agruparPorDia(null), []);
    const a = { id: 3, data: '2026-10-12' };
    const b = { id: 2, data: '2026-10-12' };
    const c = { id: 1, data: '2026-10-10' };
    assert.deepEqual(agruparPorDia([a, b, c]), [
        { data: '2026-10-12', lancamentos: [a, b] },
        { data: '2026-10-10', lancamentos: [c] },
    ]);
    const d = { id: 9, data: '2026-10-12' };
    assert.deepEqual(
        agruparPorDia([a, c, d]).map((g) => [g.data, g.lancamentos.map((l) => l.id)]),
        [
            ['2026-10-12', [3, 9]],
            ['2026-10-10', [1]],
        ],
        'dia repetido fora de sequência vai para o grupo que já existe'
    );
});

test('pode estornar', () => {
    assert.equal(podeEstornar({ tipo: 'corrida', estornado: false, fatura: null }), true, 'corrida sem fatura pode');
    assert.equal(podeEstornar({ tipo: 'diaria', estornado: false }), true);
    assert.equal(podeEstornar({ tipo: 'estorno', estornado: false }), false, 'estorno não se estorna');
    assert.equal(podeEstornar({ tipo: 'bonus', estornado: true }), false, 'já estornado');
    assert.equal(podeEstornar({ tipo: 'corrida', estornado: false, fatura: { id: 'f1', numero: 'FAT-0001' } }), false, 'na fatura emitida: só cancelando a fatura');
    assert.equal(podeEstornar({ tipo: 'corrida', estornado: false, fatura: { id: 'f1', numero: null } }), true, 'no rascunho pode');
    assert.equal(podeEstornar({ tipo: 'pagamento', estornado: false, pagamento: { id: 'p1', estornado: false } }), true);
    assert.equal(podeEstornar({ tipo: 'pagamento', estornado: false, pagamento: { id: 'p1', estornado: true } }), false, 'pagamento já estornado');
    assert.equal(podeEstornar({ tipo: 'pagamento', estornado: false, pagamento: null }), false, 'pagamento sem o registro');
    assert.equal(podeEstornar(null), false);
});

test('tipos da conta', () => {
    assert.deepEqual(tiposDaConta('motoboy'), ['diaria', 'bonus', 'ajuste', 'saldo_inicial']);
    assert.deepEqual(tiposDaConta('loja'), ['ajuste', 'saldo_inicial']);
    assert.deepEqual(tiposDaConta('x'), []);
});

test('validar lançamento', () => {
    const ok = { contaTipo: 'motoboy', tipo: 'diaria', valor: '30', contas: ['driver_a'], pedido: '' };
    assert.deepEqual(validarLancamento(ok), []);
    assert.deepEqual(validarLancamento({ ...ok, valor: '30,50' }), [], 'vírgula decimal');
    assert.deepEqual(validarLancamento({ ...ok, valor: '0' }), ['valor-zero']);
    assert.deepEqual(validarLancamento({ ...ok, valor: '0,00' }), ['valor-zero']);
    assert.deepEqual(validarLancamento({ ...ok, valor: '' }), ['valor-invalido']);
    assert.deepEqual(validarLancamento({ ...ok, valor: '-30' }), ['valor-negativo'], 'diária não pode ser negativa');
    assert.deepEqual(validarLancamento({ ...ok, tipo: 'bonus', valor: '-5' }), ['valor-negativo']);
    assert.deepEqual(validarLancamento({ ...ok, tipo: 'ajuste', valor: '-2,5' }), [], 'ajuste aceita negativo');
    assert.deepEqual(validarLancamento({ ...ok, tipo: 'saldo_inicial', valor: '-120' }), [], 'saldo inicial aceita negativo');
    assert.deepEqual(validarLancamento({ ...ok, tipo: 'ajuste', valor: '-2', pedido: 'RP5706' }), [], 'pedido em ajuste');
    assert.deepEqual(validarLancamento({ ...ok, pedido: 'RP5706' }), ['pedido-so-em-ajuste']);
    assert.deepEqual(validarLancamento({ ...ok, pedido: '   ' }), [], 'pedido em branco não conta');
    assert.deepEqual(validarLancamento({ ...ok, contas: [] }), ['sem-conta']);
    assert.deepEqual(validarLancamento({ ...ok, contas: undefined }), ['sem-conta']);
    assert.deepEqual(validarLancamento({ ...ok, contas: Array.from({ length: 101 }, (_, i) => `d${i}`) }), ['contas-demais']);
    assert.deepEqual(validarLancamento({ ...ok, tipo: 'corrida' }), ['tipo-invalido']);
    assert.deepEqual(validarLancamento({ ...ok, contaTipo: 'loja', tipo: 'diaria' }), ['tipo-invalido'], 'loja não tem diária');
    assert.deepEqual(validarLancamento({ ...ok, contaTipo: 'loja', tipo: 'ajuste', valor: '10' }), []);
    assert.deepEqual(validarLancamento({ ...ok, valor: '0', contas: [], pedido: 'RP1' }), ['valor-zero', 'pedido-so-em-ajuste', 'sem-conta'], 'vários erros juntos');
});

test('aviso de adiantamento', () => {
    assert.equal(avisoDeAdiantamento(100, 150), 0);
    assert.equal(avisoDeAdiantamento(150, 150), 0);
    assert.equal(avisoDeAdiantamento('200', 150), 50);
    assert.equal(avisoDeAdiantamento('150,10', 150), 0.1, 'arredonda em centavos');
    assert.equal(avisoDeAdiantamento(30, -20), 30, 'saldo negativo: tudo é adiantamento');
    assert.equal(avisoDeAdiantamento(30, 0), 30);
    assert.equal(avisoDeAdiantamento('', 100), 0, 'valor inválido não avisa');
    assert.equal(avisoDeAdiantamento(-5, 100), 0);
});

test('datas locais e atalhos de período', () => {
    assert.equal(isoDate(new Date(2026, 9, 2)), '2026-10-02');
    const hoje = new Date(2026, 9, 12, 23, 30);
    assert.deepEqual(periodoDoAtalho('this-month', hoje), { inicio: '2026-10-01', fim: '2026-10-12' });
    assert.deepEqual(periodoDoAtalho('last-month', hoje), { inicio: '2026-09-01', fim: '2026-09-30' });
    assert.deepEqual(periodoDoAtalho('last-7-days', hoje), { inicio: '2026-10-06', fim: '2026-10-12' });
    assert.deepEqual(periodoDoAtalho('last-month', new Date(2026, 0, 15)), { inicio: '2025-12-01', fim: '2025-12-31' }, 'virada de ano');
    assert.equal(periodoDoAtalho('outro', hoje), null);
});

test('dias de fechamento do ciclo', () => {
    assert.deepEqual(
        diasDeFechamento('semanal').map((d) => d.valor),
        [0, 1, 2, 3, 4, 5, 6]
    );
    assert.equal(diasDeFechamento('semanal')[0].chave, 'fleet-ops.ui.financeiro.dias-da-semana.0');
    const mensal = diasDeFechamento('mensal');
    assert.equal(mensal.length, 29);
    assert.deepEqual(mensal[0], { valor: 1, chave: 'fleet-ops.ui.financeiro.configuracoes.dia-do-mes', params: { dia: 1 } });
    assert.deepEqual(mensal[28], { valor: 0, chave: 'fleet-ops.ui.financeiro.configuracoes.ultimo-dia', params: {} });
    assert.deepEqual(diasDeFechamento('quinzenal'), []);
    assert.deepEqual(diasDeFechamento(undefined), []);
});

test('dia do servidor vira data local (sem UTC)', () => {
    const dia = dataDoDia('2026-10-12');
    assert.equal(dia.getFullYear(), 2026);
    assert.equal(dia.getMonth(), 9);
    assert.equal(dia.getDate(), 12, 'new Date("2026-10-12") cairia no dia 11 em Brasília');
    assert.equal(dataDoDia(''), null);
    assert.equal(dataDoDia(null), null);
    assert.equal(dataDoDia('12/10/2026'), null);
});

test('formatadores', () => {
    const f = formatadores('pt-BR');
    assert.match(f.valor(8.5), /^R\$\s8,50$/);
    assert.match(f.valor(-150), /^-R\$\s150,00$/);
    assert.match(f.valor('1234.5'), /^R\$\s1\.234,50$/);
    assert.match(f.valor(null), /^R\$\s0,00$/);
    assert.equal(f.dia('2026-10-02'), '02/10/2026');
    assert.equal(f.dia(null), '');
    assert.equal(f.diaCurto('2026-10-02'), '02/10');
    assert.match(f.diaComSemana('2026-10-12'), /seg/i, '12/10/2026 é segunda-feira');
    assert.equal(f.km(3.214), '3,21');
    assert.equal(f.km(null), '');
    assert.equal(formatadores(undefined).dia('2026-10-02'), '02/10/2026', 'sem idioma: pt-BR');
});

test('itens da lista Atenção do painel', () => {
    const f = formatadores('pt-BR');
    const vencida = itemDeAtencao({ tipo: 'fatura_vencida', fatura: 'uuid-1', numero: 'FAT-0031', loja: 'Pizzaria do Zé', valor: 620, vencimento: '2026-10-08' }, f);
    assert.equal(vencida.rota, 'financeiro.fatura');
    assert.equal(vencida.modelo, 'uuid-1');
    assert.equal(vencida.chave, 'fleet-ops.ui.financeiro.painel.atencao.fatura_vencida');
    assert.equal(vencida.params.numero, 'FAT-0031');
    assert.equal(vencida.params.vencimento, '08/10/2026');
    assert.match(vencida.params.valor, /620,00/);
    assert.match(vencida.cor, /red/);

    const rascunho = itemDeAtencao({ tipo: 'rascunho', fatura: 'uuid-2', loja: 'Terraço', valor: 412 }, f);
    assert.equal(rascunho.modelo, 'uuid-2');
    assert.equal(rascunho.params.numero, '', 'rascunho não tem número');
    assert.equal(rascunho.params.vencimento, '');

    const semLoja = itemDeAtencao({ tipo: 'sem_loja', pedido: 'order_x', numero: 'RP5702' }, f);
    assert.equal(semLoja.rota, 'operations.orders.index.details');
    assert.equal(semLoja.modelo, 'order_x');
    assert.deepEqual(semLoja.params, { numero: 'RP5702' });

    const semValor = itemDeAtencao({ tipo: 'sem_valor', pedido: 'order_y', numero: null }, f);
    assert.deepEqual(semValor.params, { numero: 'order_y' }, 'sem número: o id do pedido');

    assert.equal(itemDeAtencao({ tipo: 'email_nao_enviado', fatura: 'u3', numero: 'FAT-0033', loja: 'Burger Hub' }, f).icone, 'envelope');
    assert.equal(itemDeAtencao({ tipo: 'novo-tipo' }, f), null);
    assert.equal(itemDeAtencao(null, f), null);
});

test('linhas do CSV do extrato', () => {
    assert.deepEqual(linhasCsvDoExtrato(null), ['"Data";"Tipo";"Pedido";"Motivo";"Valor";"Situação"']);
    const extrato = {
        lancamentos: [
            { id: 3, tipo: 'pagamento', valor: -150, data: '2026-10-12', pedido: null, motivo: null, situacao: null },
            { id: 2, tipo: 'ajuste', valor: -2.5, data: '2026-10-11', pedido: { id: 'order_x', numero: 'RP5706' }, motivo: 'Cliente "ausente"', situacao: null },
            { id: 1, tipo: 'corrida', valor: 8.5, data: '2026-10-10', pedido: { id: 'order_y', numero: null }, motivo: null, situacao: 'parcial' },
        ],
    };
    const linhas = linhasCsvDoExtrato(extrato);
    assert.equal(linhas.length, 4);
    assert.equal(linhas[0], '"Data";"Tipo";"Pedido";"Motivo";"Valor";"Situação"');
    assert.equal(linhas[1], '"12/10/2026";"Pagamento";"";"";-150,00;""');
    assert.equal(linhas[2], '"11/10/2026";"Ajuste";"RP5706";"Cliente ""ausente""";-2,50;""', 'aspas escapadas');
    assert.equal(linhas[3], '"10/10/2026";"Corrida";"order_y";"";8,50;"Pago em parte"', 'pedido sem número usa o id');

    const t = (chave) => `[${chave.split('.').pop()}]`;
    const traduzidas = linhasCsvDoExtrato(extrato, t);
    assert.equal(traduzidas[0], '"[data]";"[tipo]";"[pedido]";"[motivo]";"[valor]";"[situacao]"');
    assert.equal(traduzidas[3], '"10/10/2026";"[corrida]";"order_y";"";8,50;"[parcial]"');
});
