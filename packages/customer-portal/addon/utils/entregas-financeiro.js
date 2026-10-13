// Entregas: funções puras do Financeiro do portal da loja (telas portal/financeiro e portal/fatura). Sem imports do Ember,
// testadas em scripts/teste-portal/entregas-financeiro.test.mjs. Os formatos vêm do contrato do Financeiro
// (docs/superpowers/plans/2026-10-12-financeiro.md, 1.5): Fatura pública e Lancamento público.
import { chaveDoCanceladoCobrado } from './entregas-pedido';

/** Situações que o portal mostra. Rascunho nunca chega ao portal (o servidor responde 404). */
export const SITUACOES = ['cancelada', 'vencida', 'paga', 'parcial', 'aberta'];

/**
 * Situação exibida da fatura, nesta precedência: cancelada, vencida, paga, parcial, aberta (a mesma do console, sem o
 * rascunho). `vencida` só vem verdadeira com valor em aberto, então não briga com `paga`.
 */
export function situacaoDaFatura(fatura) {
    if (fatura?.situacao === 'cancelada') {
        return 'cancelada';
    }

    if (fatura?.vencida === true) {
        return 'vencida';
    }

    if (fatura?.pagamento === 'paga') {
        return 'paga';
    }

    if (fatura?.pagamento === 'parcial') {
        return 'parcial';
    }

    return 'aberta';
}

const situacaoConhecida = (situacao) => (SITUACOES.includes(situacao) ? situacao : 'aberta');

/** Chave de tradução do selo da situação. */
export function chaveDaSituacao(situacao) {
    return `customer-portal.ui.entregas.financeiro.situacao.${situacaoConhecida(situacao)}`;
}

/** Classes do selo (cores em customer-portal-engine.css: aberta cinza, parcial amarela, paga verde, vencida vermelha, cancelada riscada). */
export function classeDoSelo(situacao) {
    return `entregas-fatura-selo entregas-fatura-selo-${situacaoConhecida(situacao)}`;
}

const numero = (valor) => {
    const convertido = Number(valor);

    return valor === null || valor === undefined || valor === '' || !Number.isFinite(convertido) ? 0 : convertido;
};

/**
 * O ciclo em andamento (`ciclo_atual` de loja/financeiro) pronto para formatar: `{ desde, ate, entregas, valor }`, com as
 * datas 'AAAA-MM-DD' como vieram e os números convertidos (o MySQL manda decimal como texto). null sem ciclo.
 */
export function textoDoCiclo(cicloAtual) {
    if (!cicloAtual?.inicio) {
        return null;
    }

    return {
        desde: cicloAtual.inicio,
        ate: cicloAtual.fim ?? null,
        entregas: numero(cicloAtual.entregas),
        valor: numero(cicloAtual.valor),
    };
}

const textoLimpo = (texto) => {
    const limpo = typeof texto === 'string' ? texto.trim() : '';

    return limpo === '' ? null : limpo;
};

/**
 * Descrição de um item da fatura: `chave` é o sufixo da tradução em customer-portal.ui.entregas (corrida comum: null;
 * corrida cancelada e cobrada: o rótulo do cancelamento; demais tipos: financeiro.tipos.<tipo>) e `motivo` é o texto
 * visível que a central escreveu (null sem texto).
 */
export function descricaoDoItem(item) {
    const motivo = textoLimpo(item?.motivo);

    if (!item?.tipo || item.tipo === 'corrida') {
        const chave = item?.detalhes?.cancelado_pago ? chaveDoCanceladoCobrado(item.detalhes.cancelado_origem) : null;

        return { chave, motivo };
    }

    return { chave: `financeiro.tipos.${item.tipo}`, motivo };
}

/** Número do pedido do item (o exibido, senão o public_id), ou null para lançamento sem pedido. */
export function numeroDoPedido(item) {
    return item?.pedido?.numero || item?.pedido?.id || null;
}

/** Número com vírgula decimal e duas casas, como o Excel brasileiro lê; vazio sem valor. */
export function decimalCsv(valor) {
    if (valor === null || valor === undefined || valor === '') {
        return '';
    }

    const convertido = Number(valor);

    return Number.isFinite(convertido) ? convertido.toFixed(2).replace('.', ',') : '';
}

const CABECALHO_PADRAO = ['Data', 'Pedido', 'Km', 'Destino', 'Descrição', 'Valor'];

/**
 * Linhas do CSV da fatura (listas de células, ainda sem aspas): cabeçalho, um item por linha (data, pedido, km, destino,
 * descrição, valor), uma linha vazia e os totais (total, recebido, em aberto). `textos` traz os rótulos traduzidos e as
 * funções `data(valor)` e `descricao(item)`; sem eles saem os padrões em português.
 */
export function linhasCsvDaFatura(fatura, itens, textos = {}) {
    const {
        cabecalho = CABECALHO_PADRAO,
        total = 'Total',
        recebido = 'Recebido',
        emAberto = 'Em aberto',
        data = (valor) => valor ?? '',
        descricao = (item) => item?.motivo ?? '',
    } = textos;
    const vazias = Array(Math.max(cabecalho.length - 2, 0)).fill('');
    const linhaDeTotal = (rotulo, valor) => [rotulo, ...vazias, decimalCsv(valor)];

    const linhas = Array.from(itens ?? []).map((item) => [
        data(item?.data),
        numeroDoPedido(item) ?? '',
        decimalCsv(item?.detalhes?.km),
        item?.detalhes?.destino ?? '',
        descricao(item) ?? '',
        decimalCsv(item?.valor),
    ]);

    return [
        cabecalho,
        ...linhas,
        [],
        linhaDeTotal(total, fatura?.total),
        linhaDeTotal(recebido, fatura?.recebido),
        linhaDeTotal(emAberto, fatura?.em_aberto),
    ];
}

const BOM_UTF8 = '﻿';

// O Excel executa como fórmula a célula de texto que começa com = + - @ (o destino vem de endereço digitado por terceiros).
// Número já formatado (com sinal e vírgula) passa como está, para o "-2,00" continuar sendo número
const INICIO_DE_FORMULA = /^[=+\-@\t\r]/;
const NUMERO_CSV = /^-?\d+(,\d+)?$/;

const celulaCsv = (valor) => {
    let texto = String(valor ?? '');

    if (INICIO_DE_FORMULA.test(texto) && !NUMERO_CSV.test(texto)) {
        texto = `'${texto}`;
    }

    return `"${texto.replace(/"/g, '""')}"`;
};

/** CSV para o Excel brasileiro: BOM do UTF-8 (sem ele os acentos saem errados), separador ";" e quebra \r\n. */
export function csvDeLinhas(linhas) {
    return BOM_UTF8 + Array.from(linhas ?? []).map((linha) => linha.map(celulaCsv).join(';')).join('\r\n');
}
