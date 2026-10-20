import { coordenadaValida, motoboyDoPedido } from './motoboys-no-mapa';

/**
 * Entregas: os alfinetes dos pedidos em andamento da loja no mapa do portal (resposta.pedidos de
 * GET int/v1/entregas/loja/motoboys, montada pelo PedidosNoMapa::daLoja: só os pedidos da loja). Funções puras, testadas
 * em scripts/teste-portal/pedidos-no-mapa.test.mjs. Cópia das do console (fleetops/addon/utils/entregas-pedidos-no-mapa.js):
 * um engine não importa do outro, e o teste confere que o alfinete é o mesmo.
 */

const SVG_DO_ALFINETE =
    '<svg xmlns="http://www.w3.org/2000/svg" width="26" height="36" viewBox="0 0 26 36">' +
    '<path d="M13 1C6.4 1 1 6.3 1 12.9 1 21.8 13 35 13 35s12-13.2 12-22.1C25 6.3 19.6 1 13 1z" fill="#dc2626" stroke="#7f1d1d" stroke-width="1.5"/>' +
    '<circle cx="13" cy="13" r="4.5" fill="#fff"/></svg>';

/** Alfinete vermelho: a imagem, o tamanho e a ponta (o ponto exato do endereço de entrega). */
export const ALFINETE = {
    url: `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(SVG_DO_ALFINETE)}`,
    tamanho: [26, 36],
    ponta: [13, 35],
    popup: [0, -32],
};

/** Os pedidos que dá para pôr no mapa (com id e coordenada válida), com as coordenadas em número. */
export function pedidosValidos(pedidos) {
    if (!Array.isArray(pedidos)) {
        return [];
    }

    return pedidos
        .filter((pedido) => pedido && typeof pedido.id === 'string' && pedido.id !== '' && coordenadaValida(pedido.latitude, pedido.longitude))
        .map((pedido) => ({ ...pedido, latitude: Number(pedido.latitude), longitude: Number(pedido.longitude) }));
}

/** Sem o pedido aberto no detalhe: a rota dele já mostra P e D no mapa. */
export function semOPedidoAberto(pedidos, publicId) {
    return publicId ? pedidos.filter((pedido) => pedido.id !== publicId) : pedidos;
}

/** Nome fixo embaixo do alfinete: só com o pedido já aceito por um motoboy (atribuído pela central e não aceito: nenhum). */
export function nomeNoAlfinete(pedido) {
    const nome = typeof pedido?.motoboy === 'string' ? pedido.motoboy.trim() : '';

    return pedido?.aceito === true && nome !== '' ? nome : null;
}

/**
 * Rótulo fixo embaixo do alfinete do pedido aceito: o número do pedido e o nome do motoboy ("#4821 · Carlos"). O número
 * é o do iFood ou do sistema da loja (com #); sem ele, o código de rastreio (sem #). API antiga, sem número: só o nome.
 * Sem aceite, null (a mesma regra do nomeNoAlfinete).
 */
export function rotuloNoAlfinete(pedido) {
    const nome = nomeNoAlfinete(pedido);

    if (!nome) {
        return null;
    }

    const numero = typeof pedido.numero === 'number' || typeof pedido.numero === 'string' ? String(pedido.numero).trim() : '';

    if (numero === '') {
        return nome;
    }

    return `${/^\d+$/.test(numero) ? '#' : ''}${numero} · ${nome}`;
}

/** Mesma lista? Só troca (e redesenha os alfinetes) quando algo mudou. */
export function mesmaLista(a, b) {
    return JSON.stringify(a ?? []) === JSON.stringify(b ?? []);
}

/** Tempo desde a criação do pedido: { unidade: 'agora' | 'min' | 'h', n }, ou null sem data. */
export function tempoDesde(criadoEm, agora = Date.now()) {
    const inicio = Date.parse(criadoEm ?? '');

    if (!Number.isFinite(inicio)) {
        return null;
    }

    const minutos = Math.max(0, Math.floor((agora - inicio) / 60000));

    if (minutos < 1) {
        return { unidade: 'agora', n: 0 };
    }

    return minutos < 60 ? { unidade: 'min', n: minutos } : { unidade: 'h', n: Math.floor(minutos / 60) };
}

/**
 * O motoboy de cada pedido da loja, para o card da lista: { <public_id>: { nome, situacao } }, só com o pedido aceito
 * (a mesma regra do nome no alfinete). A situação (livre, coleta ou entrega) é a do capacete no mapa, ou null quando o
 * motoboy não está na lista de motoboys. Lê a mesma resposta de loja/motoboys do mapa, sem consulta a mais.
 */
export function motoboysDosPedidos(pedidos, motoboys) {
    const resultado = {};

    (Array.isArray(pedidos) ? pedidos : []).forEach((pedido) => {
        const nome = nomeNoAlfinete(pedido);

        if (!nome || typeof pedido.id !== 'string' || pedido.id === '') {
            return;
        }

        resultado[pedido.id] = { nome, situacao: motoboyDoPedido(motoboys, pedido.id)?.situacao ?? null };
    });

    return resultado;
}
