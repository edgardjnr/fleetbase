import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { isBlank } from '@ember/utils';
import { isArray } from '@ember/array';
import { later } from '@ember/runloop';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';
import getWithDefault from '@fleetbase/ember-core/utils/get-with-default';

// Entregas: sem centro informado nem localização do usuário, o mapa abre em Ribeirão Preto (e não em Singapura)
const DEFAULT_LATITUDE = -21.1775;
const DEFAULT_LONGITUDE = -47.8103;

/**
 * Entregas: o `@mapCenter` ({ latitude, longitude }) quando os dois são números finitos, dentro dos limites e
 * fora do (0, 0), que o Fleetbase usa como "sem posição"; senão, null.
 */
function validMapCenter(center) {
    const latitude = center?.latitude;
    const longitude = center?.longitude;

    if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || Math.abs(latitude) > 90 || Math.abs(longitude) > 180) {
        return null;
    }

    return Math.abs(latitude) > 0.0001 || Math.abs(longitude) > 0.0001 ? { latitude, longitude } : null;
}

/** Entregas: número de um campo de coordenada; texto só com ponto decimal ("-21.5"). Vírgula, vazio e o resto viram NaN. */
function toCoordinate(value) {
    if (typeof value === 'number') {
        return value;
    }

    return typeof value === 'string' && value.trim() !== '' ? Number(value) : NaN;
}

export default class CoordinatesInputComponent extends Component {
    @service fetch;
    @service currentUser;
    @tracked zoom;
    @tracked zoomControl;
    @tracked leafletMap;
    @tracked latitude;
    @tracked longitude;
    @tracked mapLat;
    @tracked mapLng;
    @tracked lookupQuery;
    @tracked isLoading = false;
    @tracked isReady = false;
    @tracked isInitialMoveEnded = false;
    @tracked tileSourceUrl = 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png';
    @tracked mapTheme = 'light';
    @tracked disabled = false;

    // Entregas: o @mapCenter válido recebido na criação; null = sem @mapCenter (o console), comportamento original
    initialMapCenter = null;

    // Entregas: com @mapCenter, o usuário já arrastou o mapa aberto agora? Zera a cada mapa novo (onClose e onMapLoaded)
    isMapDragged = false;

    // Entregas: ouvinte do dragstart do mapa, que o ember-leaflet não expõe no LeafletMap
    onMapDragStart = () => {
        this.isMapDragged = true;
    };

    /**
     * Constructor for CoordinatesInputComponent. Sets initial map coordinates and values.
     * @memberof CoordinatesInputComponent
     */
    constructor(owner, { onInit, value, darkMode = false, zoom = 9, zoomControl = false, disabled = false }) {
        super(...arguments);
        this.setInitialMapCoordinates();
        this.setInitialValueFromPoint(value);
        this.changeTileSource(darkMode ? 'dark' : 'light');
        this.zoom = zoom;
        this.zoomControl = zoomControl;
        this.disabled = disabled;

        if (typeof onInit === 'function') {
            onInit(this);
        }
    }

    willDestroy() {
        super.willDestroy(...arguments);

        // Entregas: solta o ouvinte do arrasto (só ligado com @mapCenter)
        if (this.initialMapCenter) {
            this.leafletMap?.off('dragstart', this.onMapDragStart);
        }
    }

    changeTileSource(sourceUrl = null) {
        if (sourceUrl === 'dark') {
            this.mapTheme = 'dark';
            this.tileSourceUrl = 'https://tiles.stadiamaps.com/tiles/alidade_smooth_dark/{z}/{x}/{y}{r}.png';
        } else if (sourceUrl === 'dark_all') {
            this.mapTheme = 'dark_all';
            this.tileSourceUrl = 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png';
        } else if (sourceUrl === 'light') {
            this.mapTheme = 'light';
            this.tileSourceUrl = 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png';
        } else if (typeof sourceUrl === 'string' && sourceUrl.startsWith('https://')) {
            this.mapTheme = 'custom';
            this.tileSourceUrl = sourceUrl;
        } else {
            this.mapTheme = 'light';
            this.tileSourceUrl = 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png';
        }
    }

    /**
     * Checks if the provided object is a geographical point.
     * @param {Object} point - Object to check.
     * @returns {boolean} True if the object is a geographical point, false otherwise.
     * @memberof CoordinatesInputComponent
     */
    isPoint(point) {
        return typeof point === 'object' && !isBlank(point.type) && point.type === 'Point' && isArray(point.coordinates);
    }

    /**
     * Sets the initial value of the map's coordinates from a geographical point.
     * @param {Object} point - Geographical point to set the initial value from.
     * @memberof CoordinatesInputComponent
     */
    setInitialValueFromPoint(point) {
        if (this.isPoint(point)) {
            const [longitude, latitude] = point.coordinates;

            if (longitude === 0 && latitude === 0) {
                return;
            }

            this.updateCoordinates(latitude, longitude, { fireCallback: false });
        }
    }

    /**
     * Sets the initial map coordinates based on the current user's location.
     * @memberof CoordinatesInputComponent
     */
    setInitialMapCoordinates() {
        // Entregas: o centro pedido por quem usa o componente (ex.: a loja, no portal) vem antes do whois
        const mapCenter = validMapCenter(this.args.mapCenter);
        this.initialMapCenter = mapCenter;
        if (mapCenter) {
            this.mapLat = mapCenter.latitude;
            this.mapLng = mapCenter.longitude;
            return;
        }

        const whois = this.currentUser.getOption('whois', {});

        this.mapLat = getWithDefault(whois, 'latitude', DEFAULT_LATITUDE);
        this.mapLng = getWithDefault(whois, 'longitude', DEFAULT_LONGITUDE);
    }

    /**
     * Updates the coordinates of the map.
     * @param {number|Object} lat - Latitude or object with coordinates.
     * @param {number} [lng] - Longitude.
     * @param {Object} [options={}] - Additional options.
     * @memberof CoordinatesInputComponent
     */
    updateCoordinates(lat, lng, options = {}) {
        if (this.isPoint(lat)) {
            const [longitude, latitude] = lat.coordinates;

            return this.updateCoordinates(latitude, longitude);
        }

        const { onChange } = this.args;
        const fireCallback = getWithDefault(options, 'fireCallback', true);
        const updateMap = getWithDefault(options, 'updateMap', true);

        this.latitude = lat;
        this.longitude = lng;

        if (updateMap === true) {
            this.mapLat = lat;
            this.mapLng = lng;
        }

        if (fireCallback === true && typeof onChange === 'function') {
            onChange({ latitude: lat, longitude: lng });
        }
    }

    /**
     * Leaflet event triggered when the map has loaded. Sets the leafletMap property.
     * @param {Object} event - The event object containing the map target.
     * @memberof CoordinatesInputComponent
     */
    @action onMapLoaded({ target }) {
        this.leafletMap = target;

        // Entregas: com @mapCenter, cada mapa aberto começa sem arrasto e fica sabendo quando o usuário arrasta
        if (this.initialMapCenter) {
            this.isMapDragged = false;
            target.on('dragstart', this.onMapDragStart);
        }

        later(
            this,
            () => {
                this.isReady = true;
            },
            300
        );
    }

    /**
     * Ember action to zoom in on the map.
     * @memberof CoordinatesInputComponent
     */
    @action onZoomIn() {
        if (this.leafletMap) {
            this.leafletMap.zoomIn();
        }
    }

    /**
     * Ember action to zoom out on the map.
     * @memberof CoordinatesInputComponent
     */
    @action onZoomOut() {
        if (this.leafletMap) {
            this.leafletMap.zoomOut();
        }
    }

    /**
     * Ember action to handle closing the map or the component. Resets the map coordinates to the current latitude and longitude.
     * @memberof CoordinatesInputComponent
     */
    @action onClose() {
        // Entregas: com @mapCenter, o próximo mapa aberto é outro e começa sem arrasto (o moveend da criação dele, que
        // vem antes do onMapLoaded, também não marca); o ouvinte sai com o mapa que fecha
        if (this.initialMapCenter) {
            this.isMapDragged = false;
            this.leafletMap?.off('dragstart', this.onMapDragStart);
        }

        // Entregas: só uma posição válida (o critério do @mapCenter) vira o centro do mapa. Vazia (nada marcado), o mapa
        // reabriria sem centro; digitada com vírgula ("-21,5"), o Leaflet lançaria "Invalid LatLng". Aí o mapa fica onde está
        if (!validMapCenter({ latitude: toCoordinate(this.latitude), longitude: toCoordinate(this.longitude) })) {
            return;
        }

        this.mapLat = this.latitude;
        this.mapLng = this.longitude;
    }

    /**
     * Ember action to set coordinates based on the map's current position.
     * @param {Object} event - The event object containing map details.
     * @memberof CoordinatesInputComponent
     */
    @action setCoordinatesFromMap(event) {
        const { onUpdatedFromMap } = this.args;
        const { target } = event;
        const center = target.getCenter();
        const geographicalCenter = typeof center.wrap === 'function' ? center.wrap() : center;
        const { lat, lng } = geographicalCenter;

        // Entregas: com @mapCenter (o portal da loja abre o mapa na loja), o moveend só marca o ponto e busca o endereço
        // depois que o usuário arrastou o mapa aberto (o dragstart do Leaflet só sai depois de alguns pixels de arrasto).
        // A criação do mapa, o zoom (botões, roda do mouse, pinça) e o resize não marcam: o zoom longe do marcador tirava
        // o centro do lugar e marcava a dezenas ou centenas de metros da loja. No portal, marcam só o arrasto, o
        // autocomplete e o Localizar (esses dois pelo updateCoordinates); a distância mínima de 30 m da loja, no portal
        // (savePlace) e no servidor (RegrasPortalLoja), continua sendo a garantia final. Sem @mapCenter, como antes.
        if (this.initialMapCenter && !this.isMapDragged) {
            return;
        }

        this.updateCoordinates(lat, lng, { updateMap: false });
        if (typeof onUpdatedFromMap === 'function') {
            onUpdatedFromMap({ latitude: lat, longitude: lng });
        }
    }

    /**
     * Task which performs a reverse geolocation lookup. Updates the coordinates based on the lookup query result.
     *
     * @return {AsyncGenerator<Promise>}
     * @memberof CoordinatesInputComponent
     */
    @task *reverseLookup() {
        if (isBlank(this.lookupQuery)) {
            return;
        }

        try {
            const place = yield this.fetch.get('geocoder/query', { query: this.lookupQuery, single: true });
            if (place) {
                const [longitude, latitude] = place.location.coordinates;
                this.updateCoordinates(latitude, longitude);

                if (typeof this.args.onGeocode === 'function') {
                    this.args.onGeocode(place);
                }
            }

            return place;
        } catch (error) {
            debug('Coordinates input reverse lookup query failed:', error);
            if (typeof this.args.onGeocodeError === 'function') {
                this.args.onGeocodeError(error);
            }
        }
    }
}
