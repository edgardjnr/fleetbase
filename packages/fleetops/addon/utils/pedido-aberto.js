/**
 * Entregas: contas puras do pedido aberto no console (folha à direita do mapa do /fleet-ops, sketch 008 A). A folha
 * mostra a comanda do pedido (GET int/v1/entregas/pedidos/{id}/comanda) e o mapa desenha a rota do motoboy em
 * trechos: o que ele já fez em cinza e o que falta em laranja.
 *
 * Pontos são [latitude, longitude], como no Leaflet. A rota pelas ruas é a do servidor (RotaDoPedido: OSRM da coleta
 * ao cliente, a mesma linha do app do motoboy); sem ela, os trechos saem em linha reta.
 */

export const COR_FALTA = '#ea7a1a';
export const COR_FEITO = '#8a817a';

const MINUSCULAS = ['da', 'de', 'do', 'das', 'dos', 'e'];

/** "MARIANA SOUZA DA SILVA" → "Mariana Souza da Silva" (o iFood manda o nome em maiúsculas). */
export function nomeEmCaixaNormal(nome) {
    if (typeof nome !== 'string' || nome.trim() === '') return null;
    return nome
        .trim()
        .toLowerCase()
        .split(/\s+/)
        .map((parte, i) => (i > 0 && MINUSCULAS.includes(parte) ? parte : parte.charAt(0).toUpperCase() + parte.slice(1)))
        .join(' ');
}

function ponto(p) {
    if (Array.isArray(p) && Number.isFinite(p[0]) && Number.isFinite(p[1])) return [p[0], p[1]];
    if (p && Number.isFinite(p.latitude) && Number.isFinite(p.longitude)) return [p.latitude, p.longitude];
    return null;
}

/** Ponto mais perto de `alvo` sobre o segmento a→b (plano local; basta para trechos de cidade). */
function projetar(a, b, alvo) {
    const k = Math.cos((alvo[0] * Math.PI) / 180);
    const ax = a[1] * k, ay = a[0], bx = b[1] * k, by = b[0], px = alvo[1] * k, py = alvo[0];
    const dx = bx - ax, dy = by - ay;
    const comprimento = dx * dx + dy * dy;
    const t = comprimento === 0 ? 0 : Math.max(0, Math.min(1, ((px - ax) * dx + (py - ay) * dy) / comprimento));
    const x = ax + t * dx, y = ay + t * dy;
    return { ponto: [y, x / k], distancia: (x - px) ** 2 + (y - py) ** 2 };
}

/**
 * Divide a linha da rota na posição do motoboy: `feito` vai do começo até o ponto da linha mais perto dele, `falta` sai
 * desse ponto até o fim. O motoboy entra nas duas pontas para a linha encostar no capacete.
 */
export function dividirNaPosicao(linha, motoboy) {
    const pontos = (linha ?? []).map(ponto).filter(Boolean);
    const alvo = ponto(motoboy);
    if (pontos.length < 2 || !alvo) return { feito: [], falta: pontos };

    let melhor = { indice: 0, ponto: pontos[0], distancia: Infinity };
    for (let i = 0; i < pontos.length - 1; i++) {
        const p = projetar(pontos[i], pontos[i + 1], alvo);
        if (p.distancia < melhor.distancia) melhor = { indice: i, ponto: p.ponto, distancia: p.distancia };
    }

    return {
        feito: [...pontos.slice(0, melhor.indice + 1), melhor.ponto, alvo],
        falta: [alvo, melhor.ponto, ...pontos.slice(melhor.indice + 1)],
    };
}

/**
 * Trechos da rota do pedido aberto, na ordem de desenho (o de cima por último). Cada trecho:
 * { tipo, pontos, cor, tracejado }.
 * - situacao 'rota' (a caminho do cliente): `feito` (coleta → motoboy, cinza) e `falta` (motoboy → cliente, laranja);
 * - 'loja' (indo à loja): `ate-loja` (motoboy → coleta, laranja tracejado, em linha reta) e `entrega` (coleta → cliente);
 * - 'oferta' (sem motoboy): `prevista` (coleta → cliente, cinza tracejado);
 * - encerrado: `feita` (coleta → cliente, cinza).
 * A rota aproximada (o OSRM falhou e o servidor mandou a linha reta) sai tracejada.
 */
export function trechosDaRota({ situacao, encerrado = false, rota = null, coleta = null, entrega = null, motoboy = null } = {}) {
    const aproximada = !!rota?.aproximado;
    let linha = (rota?.linha ?? []).map(ponto).filter(Boolean);
    if (linha.length < 2) linha = [ponto(coleta), ponto(entrega)].filter(Boolean);
    const daMoto = ponto(motoboy);
    const trechos = [];
    const trecho = (tipo, pontos, cor, tracejado = false) => {
        if (pontos.length >= 2) trechos.push({ tipo, pontos, cor, tracejado });
    };

    if (encerrado) {
        trecho('feita', linha, COR_FEITO, aproximada);
    } else if (situacao === 'rota' && daMoto) {
        const { feito, falta } = dividirNaPosicao(linha, daMoto);
        trecho('feito', feito, COR_FEITO, aproximada);
        trecho('falta', falta, COR_FALTA, aproximada);
    } else if (situacao === 'loja' && daMoto) {
        trecho('ate-loja', [daMoto, ponto(coleta) ?? linha[0]].filter(Boolean), COR_FALTA, true);
        trecho('entrega', linha, COR_FALTA, aproximada);
    } else if (situacao === 'oferta') {
        trecho('prevista', linha, COR_FEITO, true);
    } else {
        trecho('falta', linha, COR_FALTA, aproximada);
    }

    return trechos;
}

/** Todos os pontos dos trechos e das paradas, para enquadrar o mapa. */
export function pontosParaEnquadrar(trechos = [], extras = []) {
    return [...trechos.flatMap((t) => t.pontos), ...extras.map(ponto).filter(Boolean)];
}

/** Situação do pedido aberto: a do Ao vivo (oferta, loja, rota) ou a final (concluido, cancelado). */
export function situacaoDaComanda(comanda, situacaoAoVivo) {
    if (comanda?.encerrado) return comanda.situacao_final === 'concluido' ? 'concluido' : 'cancelado';
    return situacaoAoVivo ?? 'oferta';
}

const quantidade = (item) => {
    const n = Number(item?.quantidade);
    return Number.isInteger(n) && n > 0 ? n : 1;
};

/**
 * Os produtos para o cupom (formato do ItensDoPedido: {quantidade, nome, opcoes: [{quantidade, nome}], observacao}):
 * as opções viram uma linha ("2× Bacon extra, Borda catupiry") e o total soma as quantidades. Sem itens, null.
 */
export function cupomDosItens(itens) {
    const lista = (Array.isArray(itens) ? itens : [])
        .filter((item) => typeof item?.nome === 'string' && item.nome.trim() !== '')
        .map((item) => ({
            quantidade: quantidade(item),
            nome: item.nome,
            opcoes:
                (Array.isArray(item.opcoes) ? item.opcoes : [])
                    .filter((opcao) => typeof opcao?.nome === 'string' && opcao.nome.trim() !== '')
                    .map((opcao) => (quantidade(opcao) > 1 ? `${quantidade(opcao)}× ${opcao.nome}` : opcao.nome))
                    .join(', ') || null,
            observacao: typeof item.observacao === 'string' && item.observacao.trim() !== '' ? item.observacao : null,
        }));
    if (!lista.length) return null;
    return { total: lista.reduce((soma, item) => soma + item.quantidade, 0), itens: lista };
}

/** Para onde volta a pílula "Voltar" do pedido aberto (query param `de`). */
export const ORIGENS = ['tabela', 'quadro', 'inicio'];

export function origemDaTransicao(nomeDaRota, layout) {
    if (nomeDaRota === 'console.home') return 'inicio';
    if (typeof nomeDaRota === 'string' && nomeDaRota.startsWith('console.fleet-ops.operations.orders.index')) {
        if (layout === 'table') return 'tabela';
        if (layout === 'kanban') return 'quadro';
    }
    return null;
}
