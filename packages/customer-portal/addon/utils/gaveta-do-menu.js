/**
 * Entregas RestaurantePro: funções puras da gaveta do menu do portal da loja (tela Pedidos; sketch 003, variante B).
 * Sem import do Ember (testadas no Node: scripts/teste-portal/gaveta-do-menu.test.mjs).
 */

/** Iniciais da loja para o logo redondo: "Terraço Pizza Bar" → "TP"; uma palavra → as 2 primeiras letras; vazio → "?". */
export function iniciais(nome) {
    const palavras = String(nome ?? '')
        .trim()
        .split(/\s+/)
        .map((palavra) => palavra.replace(/[^\p{L}\p{N}]/gu, ''))
        .filter(Boolean);

    if (palavras.length === 0) {
        return '?';
    }

    if (palavras.length === 1) {
        return palavras[0].slice(0, 2).toUpperCase();
    }

    return (palavras[0][0] + palavras[1][0]).toUpperCase();
}

/** Um número do resumo do dia como texto: inteiro ≥ 0 → ele mesmo; sem resposta ou valor estranho → "–". */
export function numeroDoResumo(valor) {
    const numero = Number(valor);

    return valor !== null && valor !== undefined && valor !== '' && Number.isInteger(numero) && numero >= 0 ? String(numero) : '–';
}

/** A resposta de `entregas/loja/resumo-do-dia` nos três textos da gaveta (sem resposta: os três "–"). */
export function resumoDaGaveta(resposta) {
    return {
        emAndamento: numeroDoResumo(resposta?.em_andamento),
        entreguesHoje: numeroDoResumo(resposta?.entregues_hoje),
        motoboysOnline: numeroDoResumo(resposta?.motoboys_online),
    };
}
