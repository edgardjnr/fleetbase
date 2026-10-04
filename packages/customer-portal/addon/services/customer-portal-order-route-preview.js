import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { OSRMv1, Control as RoutingControl } from '@fleetbase/leaflet-routing-machine';
import getRoutingHost from '@fleetbase/ember-core/utils/get-routing-host';

const DEFAULT_FIT_PADDING_BOTTOM_RIGHT = [520, 0];
const SINGLE_POINT_PAN_BY = [260, 0];
const SINGLE_POINT_ZOOM = 18;
const TWO_POINT_MAX_ZOOM = 13;
const MULTI_POINT_MAX_ZOOM = 12;

export default class CustomerPortalOrderRoutePreviewService extends Service {
    @service intl;
    @tracked map;
    @tracked route;
    @tracked routePoints = [];
    @tracked selectedOrderRoutePoints = [];
    @tracked routeCoordinates = [];
    @tracked routingError;
    routingControl;
    signature;
    // Entregas: o pedido aberto no detalhe (public_id), o motoboy dele no mapa ({ pedido, coordenadas }, informado pelo
    // mapa a cada consulta de 5 s) e o pedido já enquadrado com o motoboy: coleta, entrega e motoboy entram no
    // enquadramento uma vez por pedido; depois só o capacete anda
    pedidoSelecionado = null;
    motoboyDoPedido = null;
    pedidoEnquadradoComMotoboy = null;

    @action registerMap(eventOrMap) {
        const map = eventOrMap?.target ?? eventOrMap;

        if (!map) {
            return;
        }

        this.map = map;
        requestAnimationFrame(() => map.invalidateSize());
    }

    @action unregisterMap() {
        this.clear();
        this.map = null;
    }

    get centerCoordinates() {
        const center = this.map?.getCenter?.();

        if (!center) {
            return null;
        }

        return {
            latitude: center.lat,
            longitude: center.lng,
        };
    }

    updateDraft(draft) {
        const routePoints = this.routePointsFromDraft(draft);
        const routeCoordinates = routePoints.map((point) => point.coordinates);
        const signature = `draft:${JSON.stringify(routeCoordinates)}`;

        this.routePoints = routePoints;
        this.routeCoordinates = routeCoordinates;

        if (!this.map || signature === this.signature) {
            return;
        }

        this.signature = signature;

        if (routeCoordinates.length === 0) {
            this.clear();
            return;
        }

        this.focusRoute(routeCoordinates);

        if (routeCoordinates.length < 2) {
            this.removeRoutingControl();
            return;
        }

        this.previewRoute(draft, routeCoordinates);
    }

    updateSelectedOrder(order) {
        const routePoints = this.routePointsFromOrder(order);
        const routeCoordinates = routePoints.map((point) => point.coordinates);
        const signature = `order:${order?.uuid ?? order?.public_id ?? order?.id ?? 'none'}:${JSON.stringify(routeCoordinates)}`;

        this.selectedOrderRoutePoints = routePoints;

        // Entregas: outro pedido no detalhe: o enquadramento com o motoboy vale de novo, uma vez para este pedido
        const publicId = order?.public_id ?? null;
        if (publicId !== this.pedidoSelecionado) {
            this.pedidoSelecionado = publicId;
            this.pedidoEnquadradoComMotoboy = null;
        }

        if (!this.map || signature === this.signature) {
            return;
        }

        this.signature = signature;

        if (routeCoordinates.length === 0) {
            this.clearSelectedOrder();
            return;
        }

        this.focusRoute(this.comMotoboyDoPedido(routeCoordinates), { paddingBottomRight: [560, 0] });

        if (routeCoordinates.length < 2) {
            this.removeRoutingControl();
            return;
        }

        this.previewRouteFromOrder(order, routeCoordinates);
    }

    clearSelectedOrder() {
        this.selectedOrderRoutePoints = [];
        // Entregas: sem pedido aberto, sem motoboy do pedido nem enquadramento pendente
        this.pedidoSelecionado = null;
        this.motoboyDoPedido = null;
        this.pedidoEnquadradoComMotoboy = null;

        if (this.signature?.startsWith?.('order:')) {
            this.clear();
        }
    }

    // Entregas: o mapa informa, a cada consulta de 5 s, onde está o motoboy do pedido aberto (coordenadas [lat, lng]) ou
    // null quando o pedido não tem motoboy no mapa. Na primeira vez que ele aparece para o pedido, o mapa enquadra coleta,
    // entrega e motoboy; depois o enquadramento não muda mais
    definirMotoboyDoPedido(publicId, coordenadas) {
        this.motoboyDoPedido = publicId && coordenadas ? { pedido: publicId, coordenadas } : null;

        const pedidoNoMapa = Boolean(publicId) && publicId === this.pedidoSelecionado && this.signature?.startsWith?.('order:');

        if (!this.motoboyDoPedido || !pedidoNoMapa || this.pedidoEnquadradoComMotoboy === publicId || !this.map) {
            return;
        }

        const routeCoordinates = this.selectedOrderRoutePoints.map((point) => point.coordinates);

        this.focusRoute(this.comMotoboyDoPedido(routeCoordinates), { paddingBottomRight: [560, 0] });
    }

    // Entregas: as coordenadas do enquadramento do pedido aberto, com o motoboy dele quando já se sabe onde está; marca o
    // pedido como enquadrado com o motoboy
    comMotoboyDoPedido(routeCoordinates) {
        const motoboy = this.motoboyDoPedido;

        if (!motoboy || motoboy.pedido !== this.pedidoSelecionado) {
            return routeCoordinates;
        }

        this.pedidoEnquadradoComMotoboy = motoboy.pedido;

        return [...routeCoordinates, motoboy.coordenadas];
    }

    routePointsFromDraft(draft) {
        const payload = draft?.payload;

        if (!payload) {
            return [];
        }

        if (draft?.isMultipleDropoffOrder) {
            return (payload.waypoints ?? []).map((waypoint, index) => this.routePointFromPlace(waypoint.place, waypoint.type ?? this.defaultWaypointType(index), index)).filter(Boolean);
        }

        return [this.routePointFromPlace(payload.pickup, 'pickup', 0), this.routePointFromPlace(payload.dropoff, 'dropoff', 1), this.routePointFromPlace(payload.return, 'return', 2)].filter(
            Boolean
        );
    }

    routePointsFromOrder(order) {
        const payload = order?.payload;

        if (!payload) {
            return [];
        }

        const points = [];

        if (payload.pickup) {
            points.push(this.routePointFromPlace(payload.pickup, 'pickup', 0, 'selected'));
        }

        (payload.waypoints ?? []).forEach((waypoint, index) => {
            points.push(this.routePointFromPlace(waypoint.place ?? waypoint, waypoint.type ?? 'waypoint', points.length, 'selected', index + 1));
        });

        if (payload.dropoff) {
            points.push(this.routePointFromPlace(payload.dropoff, 'dropoff', points.length, 'selected'));
        }

        if (payload.return) {
            points.push(this.routePointFromPlace(payload.return, 'return', points.length, 'selected'));
        }

        return points.filter(Boolean);
    }

    routePointFromPlace(place, type, index, source = 'draft', stopNumber = null) {
        const coordinates = this.coordinatesFromPlace(place);

        if (!coordinates) {
            return null;
        }

        return {
            id: `${source}-${type}-${index}`,
            place,
            type,
            label: this.labelForType(type, index, stopNumber),
            index,
            coordinates,
        };
    }

    coordinatesFromPlace(place) {
        const subject = place?.place ?? place;
        const latlng = subject?.latlng;
        const locationCoordinates = subject?.location?.coordinates ?? subject?.coordinates?.coordinates;
        const longitudeFirstCoordinates = Array.isArray(locationCoordinates) && locationCoordinates.length >= 2 ? [locationCoordinates[1], locationCoordinates[0]] : null;
        const latlngCoordinates = Array.isArray(latlng) && latlng.length >= 2 ? [latlng[0], latlng[1]] : null;
        const latitude = Number(subject?.latitude ?? subject?.lat ?? latlngCoordinates?.[0] ?? longitudeFirstCoordinates?.[0]);
        const longitude = Number(subject?.longitude ?? subject?.lng ?? latlngCoordinates?.[1] ?? longitudeFirstCoordinates?.[1]);

        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
            return null;
        }

        return [latitude, longitude];
    }

    focusRoute(routeCoordinates, options = {}) {
        if (!this.map || !routeCoordinates.length) {
            return;
        }

        if (routeCoordinates.length === 1) {
            this.map.flyTo(routeCoordinates[0], SINGLE_POINT_ZOOM, { animate: true, duration: 0.5 });
            this.map.once('moveend', () => this.map?.panBy(options.panBy ?? SINGLE_POINT_PAN_BY));
            return;
        }

        this.map.flyToBounds(routeCoordinates, {
            paddingBottomRight: options.paddingBottomRight ?? DEFAULT_FIT_PADDING_BOTTOM_RIGHT,
            maxZoom: routeCoordinates.length === 2 ? TWO_POINT_MAX_ZOOM : MULTI_POINT_MAX_ZOOM,
            animate: true,
        });
    }

    previewRoute(draft, routeCoordinates) {
        this.removeRoutingControl();

        const waypoints = routeCoordinates.map((coordinates) => this.latLng(coordinates));
        const router = new OSRMv1({
            serviceUrl: `${getRoutingHost(this.routingPayloadFromDraft(draft), this.routingWaypointsFromDraft(draft))}/route/v1`,
            profile: 'driving',
        });

        this.routingControl = new RoutingControl({
            router,
            waypoints,
            alternativeClassName: 'hidden',
            addWaypoints: false,
            routeWhileDragging: false,
            draggableWaypoints: false,
            fitSelectedRoutes: false,
            show: false,
            createMarker: () => null,
            lineOptions: {
                styles: [
                    { color: '#3485e2', opacity: 0.25, weight: 8 },
                    { color: '#3485e2', opacity: 0.9, weight: 4 },
                ],
            },
        }).addTo(this.map);

        this.routingControl.on('routesfound', ({ routes }) => {
            this.route = routes?.[0] ?? null;
            this.routingError = null;
            this.focusRoute(routeCoordinates);
        });

        this.routingControl.on('routingerror', (error) => {
            this.route = null;
            this.routingError = error;
        });
    }

    previewRouteFromOrder(order, routeCoordinates) {
        this.removeRoutingControl();

        const waypoints = routeCoordinates.map((coordinates) => this.latLng(coordinates));
        const router = new OSRMv1({
            serviceUrl: `${getRoutingHost(order?.payload ?? {}, order?.payload?.waypoints ?? [])}/route/v1`,
            profile: 'driving',
        });

        this.routingControl = new RoutingControl({
            router,
            waypoints,
            alternativeClassName: 'hidden',
            addWaypoints: false,
            routeWhileDragging: false,
            draggableWaypoints: false,
            fitSelectedRoutes: false,
            show: false,
            createMarker: () => null,
            lineOptions: {
                styles: [
                    { color: '#1d4ed8', opacity: 0.24, weight: 8 },
                    { color: '#3485e2', opacity: 0.95, weight: 4 },
                ],
            },
        }).addTo(this.map);

        this.routingControl.on('routesfound', ({ routes }) => {
            this.route = routes?.[0] ?? null;
            this.routingError = null;
            this.focusRoute(this.comMotoboyDoPedido(routeCoordinates), { paddingBottomRight: [560, 0] });
        });

        this.routingControl.on('routingerror', (error) => {
            this.route = null;
            this.routingError = error;
        });
    }

    clear() {
        this.removeRoutingControl();
        this.route = null;
        this.routingError = null;
        this.routePoints = [];
        this.selectedOrderRoutePoints = [];
        this.routeCoordinates = [];
        this.signature = null;
        this.pedidoSelecionado = null;
        this.motoboyDoPedido = null;
        this.pedidoEnquadradoComMotoboy = null;
    }

    removeRoutingControl() {
        if (this.routingControl && typeof this.routingControl.remove === 'function') {
            this.routingControl.remove();
        }

        this.routingControl = null;
    }

    labelForType(type, index, stopNumber = null) {
        if (type === 'pickup') {
            return this.intl.t('customer-portal.ui.order.pickup');
        }

        if (type === 'return') {
            return this.intl.t('customer-portal.ui.order.return');
        }

        if (type === 'dropoff' && index <= 1) {
            return this.intl.t('customer-portal.ui.order.dropoff');
        }

        return this.intl.t('customer-portal.ui.order.stop-n', { n: stopNumber ?? index + 1 });
    }

    defaultWaypointType(index) {
        return index === 0 ? 'pickup' : 'dropoff';
    }

    latLng(coordinates) {
        const L = globalThis.L;

        if (L?.latLng) {
            return L.latLng(coordinates[0], coordinates[1]);
        }

        return coordinates;
    }

    routingPayloadFromDraft(draft) {
        const payload = draft?.payload ?? {};

        if (!draft?.isMultipleDropoffOrder) {
            return payload;
        }

        const pickup = this.firstWaypointByType(payload.waypoints, 'pickup')?.place ?? payload.waypoints?.[0]?.place;
        const dropoff = this.firstWaypointByType(payload.waypoints, 'dropoff')?.place ?? payload.waypoints?.[1]?.place;

        return {
            ...payload,
            pickup,
            dropoff,
        };
    }

    routingWaypointsFromDraft(draft) {
        if (!draft?.isMultipleDropoffOrder) {
            return draft?.payload?.waypoints ?? [];
        }

        return (draft?.payload?.waypoints ?? []).map((waypoint) => waypoint.place).filter(Boolean);
    }

    firstWaypointByType(waypoints = [], type) {
        return waypoints.find((waypoint) => waypoint?.type === type && waypoint?.place);
    }
}
