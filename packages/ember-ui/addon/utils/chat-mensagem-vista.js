/**
 * Entregas RestaurantePro: quando uma mensagem do chat conta como lida (gera o recibo de leitura).
 *
 * O Fleetbase pedia a mensagem 100% visível (`threshold: 1.0`). Com alturas fracionárias, a última mensagem da
 * janela fica a fração de pixel da borda (99,66% visível em 2026-10-20) e nunca gerava o recibo: o ícone de não
 * lida ficava aceso com a conversa aberta. Mensagem mais alta que a janela também nunca chegava a 100%.
 *
 * Conta como vista quando aparece quase inteira (90%) ou quando ocupa quase a janela inteira (mensagem longa).
 */

export const PARTE_VISIVEL = 0.9;

/** Limiares do IntersectionObserver: de 5 em 5%, para o callback rodar perto dos 90% em qualquer altura. */
export const LIMIARES = Array.from({ length: 21 }, (_, i) => i / 20);

/**
 * @param {IntersectionObserverEntry|Object} entry
 * @return {Boolean}
 */
export default function mensagemFoiVista(entry) {
    if (!entry?.isIntersecting) {
        return false;
    }

    if ((entry.intersectionRatio ?? 0) >= PARTE_VISIVEL) {
        return true;
    }

    const alturaVisivel = entry.intersectionRect?.height ?? 0;
    const alturaDaJanela = entry.rootBounds?.height ?? 0;

    return alturaDaJanela > 0 && alturaVisivel >= alturaDaJanela * PARTE_VISIVEL;
}
