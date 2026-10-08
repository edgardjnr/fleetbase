/**
 * Entregas: qual posição do motoboy, das que chegaram pelo socket (driver.location_changed), o mapa do console desenha.
 *
 * O movement-tracker junta os eventos numa fila e a processa a cada 3 s. Antes, a fila inteira era reproduzida em
 * sequência (0,55 s por evento). Com a aba em segundo plano o navegador segura os timers (uma vez por minuto depois de
 * ~5 min oculta) e para a animação, então a fila crescia e, ao voltar para a aba, o capacete corria por todo o trajeto
 * perdido, indo e voltando. Agora vale só a posição mais recente da fila, e nunca uma anterior à última já desenhada.
 */

/** A página está em segundo plano (aba oculta ou janela minimizada). */
export function paginaOculta() {
    return typeof document !== 'undefined' && document?.hidden === true;
}

/**
 * Instante (ms) do `created_at` do evento: "Y-m-d H:i:s" no fuso do servidor (lido como hora local, o que basta para
 * comparar os eventos entre si) ou ISO. NaN quando falta ou é ilegível.
 */
export function instanteDoEvento(evento) {
    const criadoEm = evento?.created_at;
    if (typeof criadoEm !== 'string' || criadoEm === '') {
        return NaN;
    }

    return Date.parse(criadoEm.includes('T') ? criadoEm : criadoEm.replace(' ', 'T'));
}

/** [lat, lng] do evento (GeoJSON: [lng, lat]); null se faltar, não for número, estiver fora da faixa ou for 0,0. */
function latLngDoEvento(evento) {
    const coordenadas = evento?.data?.location?.coordinates;
    if (!Array.isArray(coordenadas) || coordenadas.length < 2) {
        return null;
    }

    const lng = Number(coordenadas[0]);
    const lat = Number(coordenadas[1]);
    const valida = Number.isFinite(lat) && Number.isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180 && !(lat === 0 && lng === 0);

    return valida ? [lat, lng] : null;
}

/**
 * Das posições acumuladas, a que vale desenhar: a de `created_at` mais recente (no mesmo segundo, a que chegou por
 * último), desde que não seja anterior a `ultimoInstante` (a última já desenhada). Evento sem `created_at` legível entra
 * pela ordem de chegada. Devolve `{ evento, instante, latLng }` ou null.
 */
export function posicaoMaisRecente(eventos, ultimoInstante = -Infinity) {
    let escolhida = null;

    for (const evento of eventos ?? []) {
        const latLng = latLngDoEvento(evento);
        if (!latLng) {
            continue;
        }

        const instante = instanteDoEvento(evento);
        const temInstante = Number.isFinite(instante);

        if (temInstante && instante < ultimoInstante) {
            continue;
        }

        if (escolhida && temInstante && Number.isFinite(escolhida.instante) && instante < escolhida.instante) {
            continue;
        }

        escolhida = { evento, instante, latLng };
    }

    return escolhida;
}
