/**
 * Entregas: os alfinetes dos pedidos em andamento no mapa ao vivo do console (resposta.pedidos de
 * GET int/v1/entregas/mapa/motoboys, montada pelo PedidosNoMapa::daCentral). Funções puras, testadas em
 * scripts/teste-portal/entregas-pedidos-no-mapa.test.mjs. O portal da loja tem a cópia dele
 * (customer-portal/addon/utils/pedidos-no-mapa.js): um engine não importa do outro.
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

/** Número finito, dentro da faixa e fora do (0, 0), o "sem GPS" do Fleetbase (o Coordenada do servidor). */
function coordenadaValida(latitude, longitude) {
    if (latitude === null || longitude === null || latitude === '' || longitude === '') {
        return false;
    }

    const lat = Number(latitude);
    const lng = Number(longitude);

    if (!Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) {
        return false;
    }

    return !(Math.abs(lat) <= 0.0001 && Math.abs(lng) <= 0.0001);
}

/** Os pedidos que dá para pôr no mapa (com id e coordenada válida), com as coordenadas em número. */
export function pedidosValidos(pedidos) {
    if (!Array.isArray(pedidos)) {
        return [];
    }

    return pedidos
        .filter((pedido) => pedido && typeof pedido.id === 'string' && pedido.id !== '' && coordenadaValida(pedido.latitude, pedido.longitude))
        .map((pedido) => ({ ...pedido, latitude: Number(pedido.latitude), longitude: Number(pedido.longitude) }));
}

/** Nome fixo embaixo do alfinete: só com o pedido já aceito por um motoboy (atribuído pela central e não aceito: nenhum). */
export function nomeNoAlfinete(pedido) {
    const nome = typeof pedido?.motoboy === 'string' ? pedido.motoboy.trim() : '';

    return pedido?.aceito === true && nome !== '' ? nome : null;
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
