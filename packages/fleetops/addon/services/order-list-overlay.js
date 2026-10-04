import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { debug } from '@ember/debug';
import { task, timeout } from 'ember-concurrency';

export default class OrderListOverlayService extends Service {
    @service store;
    @service fetch;
    @service appCache;
    @tracked overlay = null;
    @tracked isOpen = false;
    @tracked width = 400;
    @tracked fleets = [];
    @tracked selectedOrders = [];
    @tracked activeOrders = [];
    @tracked unassignedOrders = [];
    @tracked searchQuery = '';
    @tracked loaded = false;

    constructor() {
        super(...arguments);
        // Initialize from cache only once (avoids service access during field init)
        this.isOpen = this.appCache.get('fleetops:component:map:order-list:open', false);
    }

    get orderGroups() {
        return {
            activeOrders: this.activeOrders,
            unassignedOrders: this.unassignedOrders,
        };
    }

    get hasSelection() {
        return this.selectedOrders.length > 0;
    }

    get activeOrdersCount() {
        return this.activeOrders.length;
    }

    @action handleLoad(overlay) {
        this.setOverlay(overlay);
        this.load.perform();
    }

    @action open() {
        this.setOpen(true);
    }

    @action close() {
        this.setOpen(false);
    }

    @action toggle() {
        this.setOpen(!this.isOpen);
    }

    @action toggleSelectOrder(order) {
        if (!order) return;

        const id = order.public_id ?? order.id;
        const index = this.selectedOrders.findIndex((o) => (o.public_id ?? o.id) === id);

        if (index === -1) {
            this.selectedOrders = [...this.selectedOrders, order];
        } else {
            this.selectedOrders = [...this.selectedOrders.slice(0, index), ...this.selectedOrders.slice(index + 1)];
        }
    }

    @action clearSelection() {
        if (this.selectedOrders.length === 0) return;
        this.selectedOrders = [];
    }

    @action setSearch(query) {
        this.searchTask.perform(query ?? '');
    }

    // Entregas: restartable, porque o socket também recarrega o painel (ver recarregar)
    @task({ restartable: true }) *load() {
        const loadedOrders = this.store.peekAll('order');
        const excludeIds = loadedOrders.map((o) => o.public_id);

        try {
            // Run requests in sequence for clarity; convert to Promise.all if backend allows
            yield this.loadFleets(excludeIds);
            yield this.loadUnassignedOrders(excludeIds);
            yield this.loadActiveOrders(excludeIds);
            this.loaded = true;
        } catch (err) {
            debug(`OrderListOverlayService.load: ${err?.message ?? err}`);
        }
    }

    /**
     * Entregas: relê as listas do painel depois de um evento de pedido no socket (aceite, status, conclusão,
     * cancelamento, pedido novo). Sem isso o painel ficava com as listas da hora em que abriu. Antes do painel
     * carregar pela primeira vez não faz nada: o handleLoad carrega.
     */
    recarregar() {
        if (this.loaded) {
            this.load.perform();
        }
    }

    @task *searchTask(query) {
        yield timeout(300);
        this.searchQuery = String(query ?? '').trim();
    }

    async loadFleets(excludeOrderIds) {
        try {
            const fleets = await this.store.query('fleet', {
                // Keep param naming as-is to avoid breaking API expectations
                excludeDriver: excludeOrderIds,
                with: ['serviceArea', 'drivers.jobs', 'drivers.currentJob'],
                without: ['drivers.fleets'],
            });

            // For each driver, expose a consolidated list of active jobs the panel cares about
            const mapped = fleets.map((fleet) => {
                fleet.drivers = fleet.drivers.map((driver) => {
                    driver.set('_panelActiveJobs', this.#semRepetidos([...driver.activeJobs, ...this.#peekDriverActiveOrders(driver)]));
                    return driver;
                });
                return fleet;
            });

            this.fleets = mapped;
        } catch (err) {
            debug(`OrderListOverlayService.#loadFleets: ${err?.message ?? err}`);
        }
    }

    async loadUnassignedOrders(excludeOrderIds) {
        try {
            const unassigned = await this.fetch.get(
                'fleet-ops/live/orders',
                {
                    unassigned: 1,
                    exclude: excludeOrderIds,
                },
                {
                    normalizeToEmberData: true,
                    normalizeModelType: 'order',
                    expirationInterval: 5,
                    expirationIntervalUnit: 'minute',
                }
            );

            this.unassignedOrders = this.#semRepetidos([...unassigned, ...this.#peekUnassignedOrders()]);
        } catch (err) {
            debug(`OrderListOverlayService.#loadUnassignedOrders: ${err?.message ?? err}`);
        }
    }

    async loadActiveOrders(excludeOrderIds) {
        try {
            const active = await this.fetch.get(
                'fleet-ops/live/orders',
                {
                    active: 1,
                    exclude: excludeOrderIds,
                },
                {
                    normalizeToEmberData: true,
                    normalizeModelType: 'order',
                    expirationInterval: 5,
                    expirationIntervalUnit: 'minute',
                }
            );

            this.activeOrders = this.#semRepetidos([...active, ...this.#peekActiveOrders()]);

            // If needed later: lazily ensure tracker data exists
            // for (const order of this.activeOrders) {
            //   if (!order.tracker_data && typeof order.loadTrackerData === 'function') {
            //     yield order.loadTrackerData();
            //   }
            // }
        } catch (err) {
            debug(`OrderListOverlayService.#loadActiveOrders: ${err?.message ?? err}`);
        }
    }

    /**
     * Entregas: o fetch com normalizeToEmberData põe no store o pedido que ainda não estava lá, e a leitura do store
     * logo depois o devolve de novo. Sem isto, todo pedido novo que chega pelo socket aparecia duas vezes no painel.
     */
    #semRepetidos(pedidos) {
        const vistos = new Set();
        return pedidos.filter((pedido) => {
            const id = pedido?.public_id ?? pedido?.id;
            if (!id || vistos.has(id)) return false;
            vistos.add(id);
            return true;
        });
    }

    #peekOrders(filterFn) {
        const records = this.store.peekAll('order');
        const predicate = typeof filterFn === 'function' ? filterFn : () => true;
        return records.filter(predicate);
    }

    #peekUnassignedOrders() {
        // Entregas: como o servidor (LiveOrderQuery): pedido cancelado, concluído ou expirado não está "sem motoboy"
        const ENCERRADOS = ['completed', 'canceled', 'expired', 'order_canceled'];
        return this.#peekOrders((order) => !order.driver_assigned_uuid && !ENCERRADOS.includes(order.status));
    }

    #peekActiveOrders() {
        // Consider an order "active" if it has an assigned driver and is not terminal
        const TERMINAL = ['created', 'completed', 'canceled', 'expired', 'order_canceled', 'pending'];
        return this.#peekOrders((order) => !!order.driver_assigned && !TERMINAL.includes(order.status));
    }

    #peekDriverActiveOrders(driver) {
        const TERMINAL = ['created', 'completed', 'canceled', 'expired', 'order_canceled', 'pending'];
        return this.#peekOrders((order) => !!order.driver_assigned && order.driver_assigned?.id === driver.id && !TERMINAL.includes(order.status));
    }

    setOverlay(overlay) {
        this.overlay = overlay;
    }

    setOpen(wantsOpen) {
        this.isOpen = !!wantsOpen;
        this.appCache.set('fleetops:component:map:order-list:open', this.isOpen);
    }
}
