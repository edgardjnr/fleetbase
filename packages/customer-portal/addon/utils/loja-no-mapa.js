// Entregas: funções puras do pin da loja no mapa de pedidos do portal. Sem Ember e sem imports: os testes rodam no Node
// (scripts/teste-portal/loja-no-mapa.test.mjs).

/**
 * O pin laranja com a lojinha, o mesmo dos mapas do console (utils/entregas-icone-da-loja.js do Fleet-Ops, que publica o
 * PNG em /engines-dist/images). Caminho literal e completo: o build troca pelo nome com hash (fingerprint); montado por
 * partes, não troca.
 */
export const PIN_DA_LOJA = {
    url: '/engines-dist/images/loja-pin.png',
    tamanho: [34, 40],
    ponta: [17, 40],
    popup: [0, -36],
    dica: [0, -38],
};

/**
 * A loja no mapa a partir da resposta de `entregas/loja/minha-loja`: nome, endereço e a coordenada do Local dela (a
 * coleta). Sem coleta ou com coordenada fora do mapa (inclusive 0,0): null, e o pin não aparece.
 *
 * @returns {{nome: string, endereco: string|null, latitude: number, longitude: number}|null}
 */
export function lojaNoMapa(resposta) {
    const loja = resposta?.loja;
    const coleta = loja?.coleta;
    // Number(null) e Number('') dão 0: sem a coordenada, nada de pin no meio do oceano
    if (coleta?.latitude == null || coleta?.longitude == null || coleta.latitude === '' || coleta.longitude === '') return null;

    const latitude = Number(coleta.latitude);
    const longitude = Number(coleta.longitude);
    const valida = Number.isFinite(latitude) && Number.isFinite(longitude) && Math.abs(latitude) <= 90 && Math.abs(longitude) <= 180 && !(latitude === 0 && longitude === 0);
    if (!valida) return null;

    return { nome: loja.nome || coleta.name || '', endereco: loja.endereco || null, latitude, longitude };
}

/**
 * O pin da loja some quando o mapa já mostra a coleta pelo "P" (pedido aberto no detalhe ou novo pedido): os dois ficariam
 * no mesmo ponto, um por cima do outro.
 */
export function mostrarPinDaLoja(loja, marcadores) {
    return Boolean(loja) && !(marcadores ?? []).some((marcador) => marcador?.type === 'pickup');
}
