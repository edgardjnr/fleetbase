// Entregas: contas do painel inicial do console (componente home/painel-inicial). Sem imports do Ember, para testar no
// Node (scripts/teste-portal/painel-inicial.test.mjs). As listas vêm de GET int/v1/entregas/painel-inicial/ao-vivo.
import { isoDate } from './financeiro';
import { cronometro } from './entregas-cronometro';

export const PERIODOS = ['hoje', 'semana', 'mes'];
export const FILTROS = ['todos', 'oferta', 'loja', 'rota', 'atrasado'];
// Mesma lista do App\Support\Entregas\SituacaoDoMotoboy::STATUS_EM_ENTREGA (capacete vermelho)
export const STATUS_EM_ENTREGA = ['enroute', 'picked_up', 'dropping_off', 'in_progress'];
// Barra vermelha do Ao vivo: algum atrasado ou tantos pedidos sem motoboy ao mesmo tempo
export const ALERTA_SEM_MOTOBOY = 2;

const DIAS_DO_PERIODO = { hoje: 1, semana: 7, mes: 30 };
const PRIMEIRA_HORA = 10;

/** { inicio, fim } em 'Y-m-d' local (Brasília) do período do seletor. */
export function periodoDoPainel(chave, hoje = new Date()) {
    const dias = DIAS_DO_PERIODO[chave] ?? 1;
    const inicio = new Date(hoje.getFullYear(), hoje.getMonth(), hoje.getDate() - (dias - 1));
    return { inicio: isoDate(inicio), fim: isoDate(hoje) };
}

export function saudacao(hora) {
    if (hora >= 5 && hora < 12) return 'bom-dia';
    if (hora >= 12 && hora < 18) return 'boa-tarde';
    return 'boa-noite';
}

/** oferta = sem motoboy; rota = levando ao cliente; loja = indo buscar (com motoboy, fora da entrega). */
export function situacaoDoPedido(pedido) {
    if (!pedido?.motoboy_id) return 'oferta';
    return STATUS_EM_ENTREGA.includes(String(pedido.status ?? '').toLowerCase()) ? 'rota' : 'loja';
}

export function atrasado(pedido, agora = Date.now()) {
    return situacaoDoPedido(pedido) !== 'oferta' && cronometro(pedido?.prazo_em, agora)?.estado === 'atrasado';
}

export function contagens(pedidos = [], agora = Date.now()) {
    const c = { todos: 0, oferta: 0, loja: 0, rota: 0, atrasado: 0 };
    for (const pedido of pedidos ?? []) {
        c.todos++;
        c[situacaoDoPedido(pedido)]++;
        if (atrasado(pedido, agora)) c.atrasado++;
    }
    return c;
}

/** Minutos até o prazo (negativo = atrasado), ou null sem prazo. */
function minutosAtePrazo(pedido, agora) {
    const prazo = Date.parse(pedido?.prazo_em ?? '');
    return Number.isFinite(prazo) ? (prazo - agora) / 60000 : null;
}

/**
 * Fila filtrada e ordenada: sem motoboy (o mais antigo antes), atrasados (o mais atrasado antes), com prazo (o mais
 * perto antes) e sem prazo no fim.
 */
export function filaDoPainel(pedidos = [], filtro = 'todos', agora = Date.now()) {
    const chave = (pedido) => {
        if (situacaoDoPedido(pedido) === 'oferta') return [0, Date.parse(pedido.criado_em ?? '') || 0];
        const minutos = minutosAtePrazo(pedido, agora);
        if (minutos === null) return [3, 0];
        return [minutos < 0 ? 1 : 2, minutos];
    };
    const passa = (pedido) => filtro === 'todos' || (filtro === 'atrasado' ? atrasado(pedido, agora) : situacaoDoPedido(pedido) === filtro);

    return (pedidos ?? [])
        .filter(passa)
        .map((pedido) => ({ pedido, chave: chave(pedido) }))
        .sort((a, b) => a.chave[0] - b.chave[0] || a.chave[1] - b.chave[1])
        .map(({ pedido }) => pedido);
}

export function resumoDosMotoboys(motoboys = []) {
    const online = (motoboys ?? []).filter((motoboy) => motoboy?.online);
    return { online: online.length, livres: online.filter((motoboy) => motoboy.situacao === 'livre').length };
}

/** Distância em km entre dois pontos { latitude, longitude } (haversine). */
export function distanciaKm(a, b) {
    const rad = (graus) => (graus * Math.PI) / 180;
    const dLat = rad(b.latitude - a.latitude);
    const dLng = rad(b.longitude - a.longitude);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.latitude)) * Math.cos(rad(b.latitude)) * Math.sin(dLng / 2) ** 2;
    return 6371 * 2 * Math.asin(Math.sqrt(h));
}

/** Motoboys livres e online para o Atribuir, do mais perto da loja (coleta) para o mais longe; sem coleta, pelo nome. */
export function motoboysParaAtribuir(motoboys = [], coleta = null) {
    const temColeta = Number.isFinite(coleta?.latitude) && Number.isFinite(coleta?.longitude);
    return (motoboys ?? [])
        .filter((motoboy) => motoboy?.online && motoboy.situacao === 'livre')
        .map((motoboy) => ({ ...motoboy, km: temColeta && Number.isFinite(motoboy.latitude) ? distanciaKm(coleta, motoboy) : null }))
        .sort((a, b) => (a.km ?? Infinity) - (b.km ?? Infinity) || String(a.nome ?? '').localeCompare(String(b.nome ?? ''), 'pt-BR'));
}

/**
 * Barras do gráfico em % da maior (total ou média). Por hora: das 10h (ou da primeira hora com corrida) às 23h, com a
 * hora atual marcada e as horas futuras de hoje sem barra. Por dia: o último dia marcado.
 */
export function barrasDoGrafico(grafico, { hoje = false, horaAtual = 23 } = {}) {
    const porHora = grafico?.tipo === 'hora';
    let barras = (grafico?.barras ?? []).map((barra, indice) => ({ ...barra, hora: porHora ? indice : null }));

    if (porHora) {
        const usadas = barras.filter((barra) => (barra.total ?? 0) > 0 || (barra.media ?? 0) > 0).map((barra) => barra.hora);
        const primeira = Math.min(PRIMEIRA_HORA, ...usadas);
        barras = barras.filter((barra) => barra.hora >= primeira);
    }

    const maior = Math.max(1, ...barras.map((barra) => Math.max(barra.total ?? 0, barra.media ?? 0)));
    return barras.map((barra, indice) => {
        const futura = porHora && hoje && barra.hora > horaAtual;
        const rotulo = String(barra.rotulo ?? '');
        return {
            rotulo,
            rotuloCurto: porHora ? `${barra.hora}h` : `${rotulo.slice(8, 10)}/${rotulo.slice(5, 7)}`,
            total: barra.total ?? 0,
            media: barra.media ?? null,
            altura: futura ? 0 : Math.round(((barra.total ?? 0) / maior) * 100),
            alturaMedia: barra.media === null || barra.media === undefined ? null : Math.round((barra.media / maior) * 100),
            agora: porHora ? hoje && barra.hora === horaAtual : indice === barras.length - 1,
            futura,
            mostrarRotulo: porHora ? barra.hora % 2 === 0 : barras.length <= 7 || indice % 5 === 0,
        };
    });
}

export function mostrarAlerta(contagem) {
    return (contagem?.atrasado ?? 0) > 0 || (contagem?.oferta ?? 0) >= ALERTA_SEM_MOTOBOY;
}

/**
 * O aviso depois do Atribuir ou do Trocar (POST entregas/painel-inicial/pedidos/{id}/motoboy): com `oferta` na resposta,
 * o pedido voltou à fila esperando o aceite do escolhido (TrocaDoMotoboy antes da coleta); sem ela, a troca foi direta.
 * Devolve a chave (dentro de fleet-ops.ui.painel-inicial) e o nome do escolhido.
 */
export function avisoDaAtribuicao(resposta) {
    const oferta = resposta?.oferta;
    if (!oferta) {
        return { chave: 'atribuir.enviado', nome: null };
    }
    const nome = typeof oferta.nome === 'string' ? oferta.nome.trim() : '';

    return nome ? { chave: 'atribuir.oferta-enviada', nome } : { chave: 'atribuir.oferta-sem-nome', nome: null };
}
