/**
 * Texto de uma atividade do pedido (tracking status) no idioma ativo.
 *
 * O Fleet-Ops grava o `status` e o `details` de cada atividade como texto em inglês, copiado do fluxo do pedido
 * ("Order Created" / "New order was created.") ou fixo no PHP (cancelamento, conclusão e início sem atividade no fluxo,
 * criação do número de rastreio e a cerca do destino). O banco fica como está: só o texto exibido é traduzido, pelos
 * textos padrão conhecidos, em `ember-ui.tracking-status.status.*` e `ember-ui.tracking-status.details.*`. Texto
 * digitado pela organização (atividade personalizada, fluxo editado) aparece como foi gravado.
 *
 * Origem dos textos (fleetops-api): Support/FleetOps.php (fluxo transport), Models/OrderConfig.php
 * (get*Activity), Observers/TrackingNumberObserver.php e Models/TrackingNumber.php ("Order Created" /
 * "New order created."), Listeners/HandleGeofenceEntered.php ("arrived"). Ao atualizar o fleetops-api, confira.
 */

const STATUS = {
    'order created': 'order-created',
    'order dispatched': 'order-dispatched',
    'order started': 'order-started',
    'driver enroute': 'driver-enroute',
    'driver en-route': 'driver-enroute',
    'order completed': 'order-completed',
    'order canceled': 'order-canceled',
    'order cancelled': 'order-canceled',
    arrived: 'arrived',
};

const DETAILS = {
    'new order was created': 'order-created',
    'new order created': 'order-created',
    'order has been dispatched': 'order-dispatched',
    'order has been started': 'order-started',
    'order has started': 'order-started',
    'driver is en-route': 'driver-enroute',
    'driver is enroute': 'driver-enroute',
    'order has been completed': 'order-completed',
    'order was completed': 'order-completed',
    'order was canceled': 'order-canceled',
    'order was cancelled': 'order-canceled',
};

/** Listeners/HandleGeofenceEntered.php: Driver entered destination geofence "<nome>". */
const CERCA_DO_DESTINO = /^driver entered destination geofence "(.*)"\.?$/is;

const normalizar = (texto) =>
    texto
        .trim()
        .replace(/\s+/g, ' ')
        .replace(/\.+$/, '')
        .toLowerCase();

/**
 * A tradução do texto padrão, ou null quando o texto não é um dos conhecidos (ou não há tradução no idioma ativo).
 *
 * @param {Object} intl  serviço intl
 * @param {String} texto o `status` ou o `details` gravado
 * @param {String} campo "status" | "details"
 * @return {String|null}
 */
export function traducaoDaAtividade(intl, texto, campo = 'status') {
    if (!intl || typeof texto !== 'string' || !texto.trim()) {
        return null;
    }

    let chave = null;
    let variaveis = {};

    if (campo === 'details') {
        const cerca = texto.trim().match(CERCA_DO_DESTINO);

        if (cerca) {
            chave = 'ember-ui.tracking-status.details.arrived-geofence';
            variaveis = { name: cerca[1] };
        } else if (DETAILS[normalizar(texto)]) {
            chave = `ember-ui.tracking-status.details.${DETAILS[normalizar(texto)]}`;
        }
    } else if (STATUS[normalizar(texto)]) {
        chave = `ember-ui.tracking-status.status.${STATUS[normalizar(texto)]}`;
    }

    try {
        return chave && intl.exists(chave) ? intl.t(chave, variaveis) : null;
    } catch {
        return null;
    }
}

/** O texto exibido: a tradução, quando há, ou o texto gravado. */
export default function trackingStatusText(intl, texto, campo = 'status') {
    return traducaoDaAtividade(intl, texto, campo) ?? texto;
}
