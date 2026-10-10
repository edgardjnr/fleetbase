import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { getOwner } from '@ember/application';
import { later } from '@ember/runloop';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';
import { OSRMv1, Control as RoutingControl } from '@fleetbase/leaflet-routing-machine';
import getRoutingHost from '@fleetbase/ember-core/utils/get-routing-host';
import engineService from '@fleetbase/ember-core/decorators/engine-service';
import { DEFAULT_LEAFLET_TILE_URL } from '../utils/leaflet-tile-url';

// Entregas: ícones do trajeto (criados só quando o mapa existe, com o Leaflet global já carregado).
const ICONE_DA_LOJA = () => L.icon({ iconUrl: '/images/entregas/loja-pin.png', iconSize: [34, 40], iconAnchor: [17, 40] });
const ICONE_DO_DESTINO = () =>
    L.divIcon({
        className: 'ent-rastreio-destino',
        html: '<svg viewBox="-16 -42 32 44" width="32" height="44" aria-hidden="true"><path d="M0 0C-4 -10 -14 -14 -14 -25A14 14 0 1 1 14 -25C14 -14 4 -10 0 0Z" fill="#d4302e" stroke="#fffcf8" stroke-width="2"/><circle cy="-25" r="5" fill="#fffcf8"/></svg>',
        iconSize: [32, 44],
        iconAnchor: [16, 42],
    });

export default class OrderTrackingLookupComponent extends Component {
    @service urlSearchParams;
    @service fetch;
    @service notifications;
    @service socket;
    @service currentUser;
    @service universe;
    @service intl;
    @engineService('@fleetbase/fleetops-engine') location;
    @engineService('@fleetbase/fleetops-engine') movementTracker;
    @tracked trackingNumber;
    @tracked order;
    @tracked zoom = 14;
    @tracked map;
    @tracked mapReady = false;
    @tracked latitude;
    @tracked longitude;
    @tracked route;
    @tracked tileSourceUrl = DEFAULT_LEAFLET_TILE_URL;

    constructor() {
        super(...arguments);
        this.movementTracker.registerTrackingMarker();
        const trackingNumber = this.urlSearchParams.get('order');
        if (trackingNumber) {
            this.trackingNumber = trackingNumber;
            this.lookupOrder.perform();
        }

        this.location.getUserLocation().then(({ latitude, longitude }) => {
            this.latitude = latitude;
            this.longitude = longitude;
            this.mapReady = true;
        });
    }

    /**
     * Entregas: situação do pedido do ponto de vista do cliente final (mesma regra de cor do capacete do console).
     */
    get situacao() {
        const order = this.order;
        const status = String(order?.status ?? '').toLowerCase();
        if (status === 'completed') return 'entregue';
        if (['canceled', 'cancelled', 'expired', 'failed'].includes(status)) return 'cancelado';
        if (status === 'enroute') return 'a-caminho';
        if (order?.has_driver_assigned || order?.driver_assigned) return 'coleta';
        if (status === 'dispatched') return 'procurando';
        return 'recebido';
    }

    get statusTexto() {
        return this.intl.t(`fleet-ops.ui.component.order-tracking-lookup.situacao.${this.situacao}`);
    }

    get capaceteUrl() {
        const cores = { 'a-caminho': 'vermelho', coleta: 'amarelo', entregue: 'verde' };
        return `/images/entregas/capacete-${cores[this.situacao] ?? 'cinza'}.png`;
    }

    get previsaoSegundos() {
        const segundos = Number(this.order?.tracker_data?.eta?.active_stop_seconds);
        const emAndamento = ['coleta', 'a-caminho'].includes(this.situacao);
        return emAndamento && Number.isFinite(segundos) && segundos > 0 ? segundos : null;
    }

    get previsaoTexto() {
        const segundos = this.previsaoSegundos;
        if (!segundos) return null;
        const minutos = Math.max(1, Math.round(segundos / 60));
        if (minutos < 60) {
            return this.intl.t('fleet-ops.ui.component.order-tracking-lookup.previsao-minutos', { minutos });
        }
        return this.intl.t('fleet-ops.ui.component.order-tracking-lookup.previsao-horas', { horas: Math.floor(minutos / 60), minutos: String(minutos % 60).padStart(2, '0') });
    }

    get criadoEm() {
        return this.formatarHora(this.order?.created_at ?? this.order?.createdAt);
    }

    formatarHora(valor) {
        const data = valor ? new Date(valor) : null;
        if (!data || Number.isNaN(data.getTime())) {
            return null;
        }
        const hoje = new Date().toDateString() === data.toDateString();
        const opcoes = hoje ? { hour: '2-digit', minute: '2-digit' } : { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' };
        return new Intl.DateTimeFormat(this.intl.primaryLocale, opcoes).format(data);
    }

    get conclusaoPrevista() {
        const valor = this.order?.tracker_data?.eta?.completion_at;
        return this.formatarHora(valor) ?? valor ?? null;
    }

    get itens() {
        const entidades = this.order?.payload?.entities;
        return entidades ? entidades.toArray?.() ?? Array.from(entidades) : [];
    }

    @action buscar(event) {
        event?.preventDefault?.();
        this.trackingNumber = this.trackingNumber?.trim();
        if (this.trackingNumber) {
            this.lookupOrder.perform();
        }
    }

    @task *lookupOrder() {
        try {
            this.order = yield this.fetch.get('fleet-ops/lookup', { tracking: this.trackingNumber }, { normalizeToEmberData: true, normalizeModelType: 'order' });
            this.urlSearchParams.addParamToCurrentUrl('order', this.order.tracking);
            const driverCurrentLocation = this.order.get('tracker_data.driver.location');
            if (driverCurrentLocation) {
                this.latitude = driverCurrentLocation.coordinates[1];
                this.longitude = driverCurrentLocation.coordinates[0];
                this.mapReady = true;
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action lookupAnother() {
        this.urlSearchParams.removeParamFromCurrentUrl('order');
        this.trackingNumber = null;
        this.order = null;
    }

    /* eslint-disable ember/no-private-routing-service */
    @action transitionToConsole() {
        const owner = getOwner(this);
        const router = owner.lookup('router:main');

        return router.transitionTo('console');
    }

    @action setupMap({ target }) {
        this.map = target;
        this.map.whenReady(() => {
            this.resetOrderRoute();
        });
    }

    @action startTrackingDriverPosition(event) {
        const { target } = event;
        const driver = this.order.driver_assigned;
        if (driver) {
            driver.set('_layer', target);
            this.movementTracker.track(driver);
        }
    }

    @action locateDriver() {
        const driver = this.order.driver_assigned;
        if (driver) {
            this.map.flyTo(driver.coordinates, 14, {
                maxZoom: 14,
                animate: true,
            });
        }
    }

    @action locateOrderRoute() {
        if (this.order) {
            const waypoints = this.getRouteCoordinatesFromOrder(this.order);
            this.map.flyToBounds(waypoints, {
                maxZoom: waypoints.length === 2 ? 16 : 15,
                animate: true,
            });
        }
    }

    @action displayOrderRoute() {
        const waypoints = this.getRouteCoordinatesFromOrder(this.order);
        const routingHost = getRoutingHost();
        if (this.cannotRouteWaypoints(waypoints)) {
            return;
        }

        // center on first coordinate
        try {
            this.map.stop();
            this.map.flyTo(waypoints.firstObject);
        } catch (error) {
            // unable to stop map
            debug(`Leaflet Map Error: ${error.message}`);
        }

        const router = new OSRMv1({
            serviceUrl: `${routingHost}/route/v1`,
            profile: 'driving',
        });

        this.routeControl = new RoutingControl({
            fitSelectedRoutes: false,
            router,
            waypoints,
            alternativeClassName: 'hidden',
            addWaypoints: false,
            // Entregas: pin da loja na coleta e alfinete vermelho no destino, como no mapa do console.
            createMarker: (indice, waypoint) =>
                L.marker(waypoint.latLng, {
                    draggable: false,
                    keyboard: false,
                    icon: indice === 0 ? ICONE_DA_LOJA() : ICONE_DO_DESTINO(),
                }),
            lineOptions: {
                styles: [
                    { color: '#1b1510', opacity: 0.85, weight: 7 },
                    { color: '#f38f17', opacity: 1, weight: 4 },
                ],
            },
        }).addTo(this.map);

        this.routeControl.on('routingerror', (error) => {
            debug(`Routing Control Error: ${error.error.message}`);
        });

        this.routeControl.on('routesfound', (event) => {
            const { routes } = event;

            this.route = routes.firstObject;

            later(
                this,
                () => {
                    this.map.flyToBounds(waypoints, {
                        maxZoom: waypoints.length === 2 ? 16 : 15,
                        animate: true,
                    });
                },
                100
            );
        });
    }

    @action resetOrderRoute() {
        this.removeRouteControl();
        this.displayOrderRoute();
    }

    cannotRouteWaypoints(waypoints = []) {
        return !this.map || !isArray(waypoints) || waypoints.length < 2;
    }

    getRouteCoordinatesFromOrder(order) {
        const payload = order.payload;
        const waypoints = [];
        const coordinates = [];

        waypoints.pushObjects([payload.pickup, ...payload.waypoints.toArray(), payload.dropoff]);
        waypoints.forEach((place) => {
            if (place && place.get('longitude') && place.get('latitude')) {
                if (place.hasInvalidCoordinates) {
                    return;
                }

                coordinates.pushObject([place.get('latitude'), place.get('longitude')]);
            }
        });

        return coordinates;
    }

    removeRouteControl() {
        if (this.routeControl && this.routeControl instanceof RoutingControl) {
            this.routeControl.remove();
        }
    }
}
