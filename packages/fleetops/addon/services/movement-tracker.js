/**
 * MovementTrackerService
 *
 * Handles real-time marker movement via SocketCluster events.
 * Refactored to use the provider-agnostic MapManagerService instead of
 * accessing Leaflet marker objects directly.
 *
 * The EventBuffer is preserved, but marker manipulation now goes through
 * `mapManager.updateMarkerPosition()` and `mapManager.setMarkerRotation()`
 * so it works with any map provider.
 *
 * Backward-compatible: the `model.leafletLayer` path is still tried as
 * a last resort so that existing code continues to work during migration.
 */
import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { getOwner } from '@ember/application';
import { task, timeout } from 'ember-concurrency';
import { debug } from '@ember/debug';
import getModelName from '@fleetbase/ember-core/utils/get-model-name';
import LeafletTrackingMarkerComponent from '../components/leaflet-tracking-marker';
import { paginaOculta, posicaoMaisRecente } from '../utils/entregas-posicao-do-socket';

export class EventBuffer {
    @tracked events = [];
    @tracked waitTime = 1000 * 3;
    @tracked callback;
    @tracked intervalId;
    @tracked model;

    /** @type {import('./map-manager').default|null} */
    mapManager = null;

    /** Entregas: instante (ms) do created_at da última posição desenhada; uma mais antiga não volta ao mapa. */
    ultimoInstante = -Infinity;

    constructor(model, { callback = null, waitTime = 1000 * 3, mapManager = null }) {
        this.model = model;
        this.callback = callback;
        this.waitTime = waitTime;
        this.mapManager = mapManager;
    }

    /**
     * Resolve the marker id for the tracked model.
     * @returns {string|null}
     */
    #getMarkerId() {
        return this.model?.id ?? null;
    }

    /**
     * Calculate distance in metres between two positions.
     * Uses the adapter when available, falls back to Haversine.
     *
     * @param {{ lat: number, lng: number }} prev
     * @param {number[]} nextLatLng - [lat, lng]
     * @returns {number}
     */
    #calcDistance(prev, nextLatLng) {
        if (this.mapManager) {
            return this.mapManager.distanceBetween(prev.lat, prev.lng, nextLatLng[0], nextLatLng[1]);
        }
        const R = 6371000;
        const dLat = ((nextLatLng[0] - prev.lat) * Math.PI) / 180;
        const dLng = ((nextLatLng[1] - prev.lng) * Math.PI) / 180;
        const a = Math.sin(dLat / 2) ** 2 + Math.cos((prev.lat * Math.PI) / 180) * Math.cos((nextLatLng[0] * Math.PI) / 180) * Math.sin(dLng / 2) ** 2;
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    start() {
        this.intervalId = setInterval(() => this.#processarSePronto(), this.waitTime);

        // Entregas: ao voltar para a aba, desenha na hora a posição que chegou enquanto ela estava oculta
        this._aoMudarVisibilidade = () => {
            if (!paginaOculta()) this.#processarSePronto();
        };
        document.addEventListener('visibilitychange', this._aoMudarVisibilidade);
    }

    stop() {
        clearInterval(this.intervalId);
        if (this._aoMudarVisibilidade) {
            document.removeEventListener('visibilitychange', this._aoMudarVisibilidade);
            this._aoMudarVisibilidade = null;
        }
    }

    #processarSePronto() {
        if (this.process.isIdle && this.events.length > 0) {
            this.process.perform();
        }
    }

    clear() {
        this.events = [];
    }

    add(event) {
        this.events = [...this.events, event];
    }

    removeByIndex(index) {
        this.events = this.events.filter((_, i) => i !== index);
    }

    remove(event) {
        this.events = this.events.filter((e) => e !== event);
    }

    /**
     * Entregas: desenha só a posição mais recente da fila (utils/entregas-posicao-do-socket.js). Antes, cada evento
     * acumulado era animado em sequência, 0,55 s por evento: depois de um tempo com a aba em segundo plano, o capacete
     * reproduzia o trajeto perdido, indo e voltando. Com a aba oculta, a posição vai direto, sem animação (o navegador
     * não roda a animação em segundo plano e o marcador ficaria parado onde estava).
     */
    @task *process() {
        debug('Processing movement tracker event buffer.');

        const eventsToProcess = [...this.events];
        this.events = [];

        const maisRecente = posicaoMaisRecente(eventsToProcess, this.ultimoInstante);
        debug(`[MovementTracker EventBuffer ${eventsToProcess.length} events, drawing ${maisRecente ? 'the latest' : 'none'}]`);
        if (!maisRecente) {
            return;
        }

        const { evento: output, instante, latLng: nextLatLng } = maisRecente;
        const { event, data } = output;
        const [lat, lng] = nextLatLng;

        // Resolve marker via adapter (provider-agnostic) or Leaflet fallback
        const markerId = this.#getMarkerId();
        const hasAdapterMarker = markerId && this.mapManager?.hasMarker(markerId);
        const leafletMarker = !hasAdapterMarker ? this.model?.leafletLayer || this.model?._layer || this.model?._marker : null;

        if (!hasAdapterMarker && (!leafletMarker || !leafletMarker._map)) {
            debug('No marker or marker not on map yet');
            return;
        }

        debug(`${event} - ${data.id} (${output.created_at}) [ ${lng} ${lat} ]`);

        if (Number.isFinite(instante)) {
            this.ultimoInstante = instante;
        }

        const animar = !paginaOculta();

        // Calculate distance for animation duration
        let meters = 0;
        if (hasAdapterMarker) {
            const adapterCenter = this.mapManager.getCenter?.();
            if (adapterCenter) meters = this.#calcDistance(adapterCenter, nextLatLng);
        } else if (leafletMarker) {
            const map = leafletMarker._map;
            const prev = leafletMarker.getLatLng();
            meters = map ? map.distance(prev, nextLatLng) : prev.distanceTo(nextLatLng);
        }

        let mps = Number.isFinite(data.speed) && data.speed > 0 ? data.speed : null;
        const durationMs = mps ? Math.max(100, Math.min((meters / mps) * 1000, 500)) : 500;

        try {
            if (hasAdapterMarker) {
                // ── Provider-agnostic path ─────────────────────────────
                if (Number.isFinite(data.heading) && data.heading !== -1) {
                    this.mapManager.setMarkerRotation(markerId, data.heading);
                }
                this.mapManager.updateMarkerPosition(markerId, lat, lng, animar, durationMs);
            } else if (leafletMarker) {
                // ── Leaflet backward-compat path ───────────────────────
                if (typeof leafletMarker.setRotationAngle === 'function' && Number.isFinite(data.heading) && data.heading !== -1) {
                    leafletMarker.setRotationAngle(data.heading);
                }
                // setLatLng também cancela um deslize em andamento (evento move → slideCancel)
                if (animar && typeof leafletMarker.slideTo === 'function') {
                    leafletMarker.slideTo(nextLatLng, { duration: durationMs });
                } else {
                    leafletMarker.setLatLng(nextLatLng);
                }
            }

            if (typeof this.callback === 'function') {
                this.callback(output, { nextLatLng, duration: durationMs, mps });
            }

            if (animar) {
                yield timeout(durationMs + 50);
            }
        } catch (err) {
            debug('MovementTracker EventBuffer error: ' + err.message);
        }
    }
}

export default class MovementTrackerService extends Service {
    @service socket;
    @service universe;
    @service mapManager;

    @tracked channels = [];
    @tracked buffers = new Map();

    constructor() {
        super(...arguments);
        this.registerTrackingMarker();
    }

    #getOwner(owner = null) {
        return owner ?? this.universe.getApplicationInstance() ?? getOwner(this);
    }

    #getBuffer(key, model, opts = {}) {
        let buf = this.buffers.get(key);
        if (!buf) {
            buf = new EventBuffer(model, { ...opts, mapManager: this.mapManager });
            buf.start();
            this.buffers.set(key, buf);
        }
        return buf;
    }

    registerTrackingMarker(_owner = null) {
        const owner = this.#getOwner(_owner);
        const emberLeafletService = owner.lookup('service:ember-leaflet');

        if (emberLeafletService) {
            const alreadyRegistered = emberLeafletService.components.find((registeredComponent) => registeredComponent.name === 'leaflet-tracking-marker');
            if (alreadyRegistered) return;

            // we then invoke the `registerComponent` method
            emberLeafletService.registerComponent('leaflet-tracking-marker', {
                as: 'tracking-marker',
                component: LeafletTrackingMarkerComponent,
            });
        }
    }

    closeChannels() {
        this.channels.forEach((channel) => {
            channel.close();
        });
    }

    watch(models = []) {
        models.forEach((model) => {
            this.track(model);
        });
    }

    async track(model, options = {}) {
        // Create socket instance
        const socket = this.socket.instance();

        // Get model type and identifier
        const type = getModelName(model);
        const identifier = model.id;

        // Location events to listen for
        const locationEvents = [`${type}.location_changed`, `${type}.simulated_location_changed`, 'position.changed', 'position.simulated'];

        // Listen on the specific channel
        const channelId = options?.channelId ?? `${type}.${identifier}`;
        const channel = socket.subscribe(channelId);

        // Debug output
        debug(`Tracking movement started for ${type} with id ${identifier}${options?.channelId ? ' on channel ' + channelId : ''}`, model);

        // Track the channel
        this.channels = [...this.channels, channel];

        // Listen to the channel for events
        await channel.listener('subscribe').once();

        // Create event buffer for tracking model
        const eventBuffer = this.#getBuffer(channelId, model, options);

        // Get incoming data and console out
        (async () => {
            for await (let output of channel) {
                const { event } = output;

                if (locationEvents.includes(event)) {
                    eventBuffer.add(output);
                    debug(`Socket Event : ${event} : Added to EventBuffer : ${JSON.stringify(output)}`);
                }
            }
        })();
    }
}
