// Entregas: funções puras do mapa de motoboys do portal da loja (capacete por situação, coordenada válida, distância,
// salto ou deslize, interpolação e motoboy do pedido aberto). Sem Ember e sem imports: os testes rodam no Node
// (scripts/teste-portal/motoboys-no-mapa.test.mjs).

/**
 * Capacete de cada situação: a mesma regra e as mesmas imagens do mapa do console (utils/entregas-capacete.js do
 * Fleet-Ops, que publica os PNGs em /engines-dist/images). Offline não aparece no portal. Caminhos literais e completos:
 * o build troca cada um pelo nome com hash (fingerprint); montado por partes, não troca.
 */
export const CAPACETES = {
    livre: '/engines-dist/images/capacete-verde.png',
    coleta: '/engines-dist/images/capacete-amarelo.png',
    entrega: '/engines-dist/images/capacete-vermelho.png',
};

/** Acima disto (em metros) o capacete salta em vez de deslizar: GPS voltando depois de um tempo sem sinal. */
export const SALTO_METROS = 1000;

/** Duração do deslize: um pouco menor que o intervalo da consulta (5 s), para o capacete chegar antes da próxima posição. */
export const DESLIZE_MS = 4500;

export function capaceteDaSituacao(situacao) {
    return CAPACETES[situacao] ?? null;
}

function vazio(valor) {
    return valor === null || valor === undefined || valor === '';
}

/** Coordenada que dá para pôr no mapa: números finitos, dentro da faixa e fora do (0, 0), que é o "sem GPS" do Fleetbase. */
export function coordenadaValida(latitude, longitude) {
    if (vazio(latitude) || vazio(longitude)) {
        return false;
    }

    const lat = Number(latitude);
    const lng = Number(longitude);

    if (!Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) {
        return false;
    }

    return !(Math.abs(lat) <= 0.0001 && Math.abs(lng) <= 0.0001);
}

/** Distância em metros entre dois pontos [lat, lng] (haversine). */
export function distanciaEmMetros([lat1, lng1], [lat2, lng2]) {
    const raio = 6371000;
    const radianos = (graus) => (graus * Math.PI) / 180;
    const dLat = radianos(lat2 - lat1);
    const dLng = radianos(lng2 - lng1);
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(radianos(lat1)) * Math.cos(radianos(lat2)) * Math.sin(dLng / 2) ** 2;

    return 2 * raio * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

/** Salta (sem deslizar) na primeira posição, em pulos de mais de SALTO_METROS e quando o sistema pede menos animação. */
export function deveSaltar(de, para, { reduzirMovimento = false } = {}) {
    if (!de || reduzirMovimento) {
        return true;
    }

    return distanciaEmMetros(de, para) > SALTO_METROS;
}

/** Ponto entre `de` e `para` na fração do deslize (limitada a 0..1), em linha reta. */
export function interpolar(de, para, fracao) {
    const f = Math.min(1, Math.max(0, fracao));

    return [de[0] + (para[0] - de[0]) * f, de[1] + (para[1] - de[1]) * f];
}

/** Motoboys da resposta da API que dá para desenhar: com id, situação conhecida e coordenada válida. */
export function motoboysValidos(lista) {
    return (Array.isArray(lista) ? lista : []).filter(
        (motoboy) => motoboy && typeof motoboy.id === 'string' && motoboy.id !== '' && capaceteDaSituacao(motoboy.situacao) && coordenadaValida(motoboy.latitude, motoboy.longitude)
    );
}

/** O motoboy que leva o pedido aberto (pelo public_id em `pedidos`), ou null. */
export function motoboyDoPedido(motoboys, publicId) {
    if (!publicId) {
        return null;
    }

    return (Array.isArray(motoboys) ? motoboys : []).find((motoboy) => Array.isArray(motoboy?.pedidos) && motoboy.pedidos.includes(publicId)) ?? null;
}
