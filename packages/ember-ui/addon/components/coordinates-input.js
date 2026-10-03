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
        // Entregas: com @mapCenter, o próximo mapa aberto é outro (o moveend da criação dele também não marca) e, sem
        // ponto marcado, o mapa fica no centro pedido: copiar latitude/longitude vazios deixaria o mapa sem centro
        if (this.initialMapCenter) {
            this.isInitialMoveEnded = false;

            if (isBlank(this.latitude) || isBlank(this.longitude)) {
                return;
            }
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

        // Entregas: com @mapCenter (o portal da loja abre o mapa na loja), o moveend da criação de cada mapa aberto (o
        // setView inicial) e o zoom sem arrastar (o centro continua o pedido) não marcam o ponto nem buscam o endereço:
        // o centro é a loja, e marcar sem o usuário mexer gravaria o destino na loja, com km ~0. Sem @mapCenter, como antes.
        if (this.initialMapCenter) {
            if (!this.isInitialMoveEnded) {
                this.isInitialMoveEnded = true;
                return;
            }

            if (this.isAtInitialMapCenter(lat, lng)) {
                return;
            }
        }

        this.updateCoordinates(lat, lng, { updateMap: false });
        if (typeof onUpdatedFromMap === 'function') {
            onUpdatedFromMap({ latitude: lat, longitude: lng });
        }
    }

    /**
     * Entregas: o centro do mapa ainda é o @mapCenter pedido? O Leaflet devolve o mesmo centro até alguém arrastar o
     * mapa (o zoom pelos botões ou pelo teclado não o tira do lugar); a folga cobre o arredondamento do wrap() da longitude.
     */
    isAtInitialMapCenter(latitude, longitude) {
        const { initialMapCenter } = this;

        return Math.abs(latitude - initialMapCenter.latitude) < 1e-7 && Math.abs(longitude - initialMapCenter.longitude) < 1e-7;
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
