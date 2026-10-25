// Entregas: contas da tela de pedidos do Fleet-Ops (/fleet-ops: Mapa e Quadro; sketch 005, variante A invertida). Sem
// imports do Ember, para testar no Node (scripts/teste-portal/tela-de-pedidos.test.mjs). As listas vêm de
// GET int/v1/entregas/painel-inicial/ao-vivo (a mesma resposta do Ao vivo do painel inicial).
import { situacaoDoPedido, filaDoPainel, atrasado } from './painel-inicial';

export const FILTROS_DOS_MOTOBOYS = ['todos', 'livre', 'ocupado'];
// Abas da Tabela → query params da rota (OrderFilter do Fleet-Ops: status 'active' é o alias de "em andamento")
export const ABAS_DA_TABELA = {
    todos: {},
    andamento: { status: 'active' },
    'sem-motoboy': { unassigned: 1 },
    concluidos: { status: 'completed' },
    cancelados: { status: 'canceled' },
};

const ORDEM_DA_SITUACAO = { livre: 0, coleta: 1, entrega: 2, offline: 3 };

/**
 * Motoboys do painel com o pedido atual (o que está levando ao cliente, senão o primeiro) e quantos mais estão com
 * ele. Ordem: livres (menos corridas hoje antes, como o desempate da distribuição), indo à loja, em entrega, offline;
 * nome no desempate.
 */
export function motoboysDaTela(motoboys = [], pedidos = []) {
    const porMotoboy = {};
    for (const pedido of pedidos ?? []) {
        if (pedido?.motoboy_id) (porMotoboy[pedido.motoboy_id] ??= []).push(pedido);
    }
    return (motoboys ?? [])
        .map((motoboy) => {
            const seus = porMotoboy[motoboy.id] ?? [];
            const pedido = seus.find((p) => situacaoDoPedido(p) === 'rota') ?? seus[0] ?? null;
            return { ...motoboy, corridas_hoje: motoboy.corridas_hoje ?? 0, pedido, outros: Math.max(0, seus.length - 1) };
        })
        .sort(
            (a, b) =>
                (ORDEM_DA_SITUACAO[a.situacao] ?? 9) - (ORDEM_DA_SITUACAO[b.situacao] ?? 9) ||
                (a.situacao === 'livre' && b.situacao === 'livre' ? a.corridas_hoje - b.corridas_hoje : 0) ||
                String(a.nome ?? '').localeCompare(String(b.nome ?? ''), 'pt-BR')
        );
}

/** livre = online e livre; ocupado = indo à loja ou em entrega. */
export function filtrarMotoboys(motoboys = [], filtro = 'todos') {
    if (filtro === 'livre') return (motoboys ?? []).filter((m) => m.online && m.situacao === 'livre');
    if (filtro === 'ocupado') return (motoboys ?? []).filter((m) => m.situacao === 'coleta' || m.situacao === 'entrega');
    return motoboys ?? [];
}

/** O pedido passa no filtro da fila (o mesmo do painel inicial). */
export function passaNoFiltro(pedido, filtro = 'todos', agora = Date.now()) {
    if (filtro === 'todos') return true;
    if (filtro === 'atrasado') return atrasado(pedido, agora);
    return situacaoDoPedido(pedido) === filtro;
}

/** Colunas do Quadro na ordem da fila: sem motoboy, indo à loja e a caminho. */
export function colunasDoQuadro(pedidos = [], agora = Date.now()) {
    const fila = filaDoPainel(pedidos, 'todos', agora);
    return {
        oferta: fila.filter((p) => situacaoDoPedido(p) === 'oferta'),
        loja: fila.filter((p) => situacaoDoPedido(p) === 'loja'),
        rota: fila.filter((p) => situacaoDoPedido(p) === 'rota'),
    };
}

/** % do tempo entre a criação e o prazo que já passou (0 a 100), ou null sem as duas datas. */
export function progressoDoPrazo(pedido, agora = Date.now()) {
    const criado = Date.parse(pedido?.criado_em ?? '');
    const prazo = Date.parse(pedido?.prazo_em ?? '');
    if (!Number.isFinite(criado) || !Number.isFinite(prazo) || prazo <= criado) return null;
    return Math.max(0, Math.min(100, Math.round(((agora - criado) / (prazo - criado)) * 100)));
}

/** A aba da Tabela que bate com os query params atuais ('todos' se nenhuma bater). */
export function abaDaTabela({ status, unassigned } = {}) {
    if (String(unassigned ?? '') === '1' || unassigned === true) return 'sem-motoboy';
    const achada = Object.entries(ABAS_DA_TABELA).find(([, params]) => params.status && params.status === status);
    return achada ? achada[0] : 'todos';
}

/** 'HH:MM' local (Brasília no navegador) de um ISO; '' se inválido. Não usa o Intl (o polyfill do console é UTC). */
export function horaLocal(iso) {
    const instante = new Date(Date.parse(iso ?? ''));
    if (Number.isNaN(instante.getTime())) return '';
    return `${String(instante.getHours()).padStart(2, '0')}:${String(instante.getMinutes()).padStart(2, '0')}`;
}
