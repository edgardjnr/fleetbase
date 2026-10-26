/**
 * Entregas RestaurantePro: funções puras do portal da loja no desenho do sketch 009 S (moldura com abas no topo, mapa com
 * a lista de Pedidos à direita, Início com os números de hoje). Sem import do Ember: testadas no Node
 * (scripts/teste-portal/portal-da-loja.test.mjs).
 */
import { numeroDoResumo } from './gaveta-do-menu';

/** Status do pedido que já saiu da loja: o SituacaoDoMotoboy::STATUS_EM_ENTREGA do servidor (o capacete vermelho). */
export const STATUS_EM_ENTREGA = ['enroute', 'picked_up', 'dropping_off', 'in_progress'];

/** Encerrados: os mesmos do servidor (StatusDoPedido::ENCERRADOS) e do cronômetro. */
export const ENCERRADOS = ['completed', 'done', 'canceled', 'cancelled', 'order_canceled', 'expired'];

/** Os filtros das contagens do mapa, na ordem em que aparecem. */
export const FILTROS = ['sem', 'atrasado', 'loja', 'caminho'];

function instante(iso) {
    const ms = typeof iso === 'string' && iso.trim() !== '' ? Date.parse(iso) : NaN;

    return Number.isFinite(ms) ? ms : null;
}

/**
 * A situação do pedido na lista e no mapa: `sem` (ainda sem motoboy que aceitou), `loja` (aceito, indo à loja),
 * `caminho` (já saiu da loja) ou `fim` (encerrado); e se passou do prazo de entrega. `aceito` vem do motoboy do pedido
 * no mapa (o alfinete ou o card); o status em entrega já conta como aceito.
 *
 * @param {{status?: string|null, aceito?: boolean, prazoEm?: string|null}} pedido
 * @returns {{chave: 'sem'|'loja'|'caminho'|'fim', atrasado: boolean}}
 */
export function situacaoDoPedido(pedido, agora = Date.now()) {
    const status = String(pedido?.status ?? '').toLowerCase();

    if (ENCERRADOS.includes(status)) {
        return { chave: 'fim', atrasado: false };
    }

    const prazo = instante(pedido?.prazoEm);
    const atrasado = prazo !== null && prazo < agora;

    if (STATUS_EM_ENTREGA.includes(status)) {
        return { chave: 'caminho', atrasado };
    }

    return { chave: pedido?.aceito === true ? 'loja' : 'sem', atrasado };
}

/** O pedido passa no filtro das contagens? Sem filtro, todos. */
export function passaNoFiltro(situacao, filtro) {
    if (!filtro) {
        return true;
    }

    return filtro === 'atrasado' ? situacao.atrasado === true : situacao.chave === filtro;
}

/** As contagens do topo do mapa (sem motoboy, atrasados, indo à loja, a caminho). Os encerrados não contam. */
export function contagens(situacoes) {
    const total = { sem: 0, atrasado: 0, loja: 0, caminho: 0 };

    for (const situacao of situacoes ?? []) {
        if (!situacao || situacao.chave === 'fim') {
            continue;
        }
        if (situacao.chave in total) {
            total[situacao.chave]++;
        }
        if (situacao.atrasado) {
            total.atrasado++;
        }
    }

    return total;
}

/**
 * A ordem da lista de Pedidos (decisão do Edgard, 2026-10-26, "pra facilitar ver os atrasados"): quem vence o prazo de
 * entrega primeiro fica em cima, então os atrasados vão para o topo (o mais atrasado antes). Sem prazo vai para o fim, o
 * mais antigo antes. Estável; não muda a lista recebida.
 *
 * @param {Array} itens
 * @param {(item) => string|null} prazoDe
 * @param {(item) => string|null} criadoDe
 */
export function ordenarPorPrazo(itens, prazoDe, criadoDe) {
    return Array.from(itens ?? [])
        .map((item, posicao) => ({ item, posicao, prazo: instante(prazoDe(item)), criado: instante(criadoDe(item)) }))
        .sort((a, b) => {
            if ((a.prazo === null) !== (b.prazo === null)) {
                return a.prazo === null ? 1 : -1;
            }
            if (a.prazo !== null && a.prazo !== b.prazo) {
                return a.prazo - b.prazo;
            }
            if (a.prazo === null && a.criado !== b.criado) {
                return (a.criado ?? Infinity) - (b.criado ?? Infinity);
            }

            return a.posicao - b.posicao;
        })
        .map(({ item }) => item);
}

/** A saudação do Início pela hora do relógio: bom dia (5 h às 11 h), boa tarde (12 h às 17 h) e boa noite. */
export function saudacao(hora) {
    if (hora >= 5 && hora < 12) {
        return 'bom-dia';
    }

    return hora >= 12 && hora < 18 ? 'boa-tarde' : 'boa-noite';
}

const REAIS = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

/** Inteiro ≥ 0 ou null (sem resposta, nulo ou estranho). */
function inteiro(valor) {
    const numero = Number(valor);

    return valor !== null && valor !== undefined && valor !== '' && Number.isInteger(numero) && numero >= 0 ? numero : null;
}

/**
 * Os seis números do Início (resposta de `entregas/loja/resumo-do-dia` e o `ciclo_atual` de `entregas/loja/financeiro`),
 * já como texto. Sem dado: "–". O `detalhe` de cada um é a chave de tradução e os parâmetros, montados no componente.
 */
export function numerosDoInicio(resumo, ciclo) {
    const noPrazo = inteiro(resumo?.no_prazo);
    const tempo = inteiro(resumo?.tempo_medio_min);
    const valor = Number(ciclo?.valor);
    const entregasDoCiclo = inteiro(ciclo?.entregas);

    return {
        pedidosHoje: numeroDoResumo(resumo?.pedidos_hoje),
        emAndamento: numeroDoResumo(resumo?.em_andamento),
        entregues: numeroDoResumo(resumo?.entregues_hoje),
        noPrazo: noPrazo === null ? '–' : `${noPrazo}%`,
        tempoMedio: tempo === null ? '–' : `${tempo} min`,
        aPagar: ciclo && Number.isFinite(valor) ? REAIS.format(valor) : '–',
        entregasDoCiclo,
        motoboysOnline: numeroDoResumo(resumo?.motoboys_online),
    };
}
