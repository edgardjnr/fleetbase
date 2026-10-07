/**
 * Entregas RestaurantePro: ícone dos locais no mapa = pin laranja com a lojinha (no lugar do predinho preto).
 *
 * O predinho é o avatar padrão do Fleetbase (`static/place-icons/basic-building.png`, que o Place da API devolve
 * em `avatar_url` quando o local não tem avatar). Local com avatar próprio (enviado em Locais) continua com ele.
 * Funções puras, testadas em scripts/teste-portal/entregas-icone-da-loja.test.mjs.
 */

// Caminho literal e completo: o build troca pelo nome com hash (fingerprint); montado por partes, não troca.
const URL_DO_PIN = '/engines-dist/images/loja-pin.png';

/** O pin: a imagem (102×120, exibida em 34×40), o tamanho e a ponta (o ponto exato do local). */
export const ICONE_DA_LOJA = {
    url: URL_DO_PIN,
    tamanho: [34, 40],
    ponta: [17, 40],
    popup: [0, -36],
};

/** Avatar padrão do Fleetbase (ou nenhum): troca pelo pin. */
export function avatarPadrao(avatarUrl) {
    if (!avatarUrl || typeof avatarUrl !== 'string') return true;
    return avatarUrl.includes('place-icons/basic-building') || avatarUrl.includes('building-marker');
}

/**
 * O ícone do local no mapa: o pin da loja ou, com avatar próprio, o avatar no tamanho de antes (quadrado e
 * centrado no ponto, como no Fleetbase). Sempre com ponta e popup: o L.icon com `popupAnchor` indefinido
 * apaga o padrão [0, 0] e o popup quebra ao abrir.
 *
 * @param {{avatar_url?: string}} place
 * @param {number} ladoDoAvatar lado do avatar próprio em px (16 no mapa ao vivo, 40 nos mapas de um local só)
 * @returns {{url: string, tamanho: number[], ponta: number[], popup: number[]}}
 */
export default function iconeDoLocal(place, ladoDoAvatar = 16) {
    const avatarUrl = place?.avatar_url;
    if (avatarPadrao(avatarUrl)) return ICONE_DA_LOJA;
    return { url: avatarUrl, tamanho: [ladoDoAvatar, ladoDoAvatar], ponta: [ladoDoAvatar / 2, ladoDoAvatar / 2], popup: [0, 0] };
}
