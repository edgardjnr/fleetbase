import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { debug } from '@ember/debug';
import { debounce } from '@ember/runloop';

// Entregas: rota da lista de pedidos, recarregada quando chega pedido novo pelo socket
const ROTA_PEDIDOS = 'console.fleet-ops.operations.orders.index';

// Entregas: espera para juntar a rajada de eventos de uma mesma mudança (status, atividade, waypoint) numa releitura só
const ESPERA_PAINEL_MS = 800;

/**
 * SocketCluster-driven order event stream manager.
 *
 * Usage:
 *  const { stop } = this.orderSocketEvents.start(this.model, async (msg, { reloadable }) => {
 *    if (reloadable) await this.hostRouter.refresh();
 *  }, { debounceMs: 300 });
 *
 *  // later: stop()
 */
export default class OrderSocketEventsService extends Service {
    @service socket;
    @service currentUser;
    @service hostRouter;
    // Entregas: faltava; #findOrderByPublicId usava this.store e o 1º evento derrubava o laço da empresa
    @service store;
    // Entregas: painel de pedidos do mapa, relido a cada evento de pedido
    @service orderListOverlay;
    @tracked subs = new Map(); // channelId -> { channel, order, onEvent, debounceMs, isActive, pumpPromise, debouncer }
    @tracked _company = null;
    reloadableEvents = new Set(['order.created', 'order.completed', 'waypoint.activity', 'entity.activity']);

    start(order, onEvent, { debounceMs = 0 } = {}) {
        if (!order?.public_id) {
            throw new Error('order-socket-events.start: order.public_id is required');
        }
        const channelId = `order.${order.public_id}`;

        // Idempotent: reuse if already running
        let sub = this.subs.get(channelId);
        if (sub?.isActive) return this._handle(channelId);

        const sc = this.socket.instance(); // SocketCluster client
        const channel = sc.subscribe(channelId); // returns a Channel

        sub = {
            channel,
            order,
            onEvent,
            debounceMs,
            isActive: true,
            pumpPromise: null,
            debouncer: null,
            connectConsumer: sc.listener('connect').createConsumer(),
        };
        this.subs.set(channelId, sub);

        // ensure we are subscribed before consuming
        sub.pumpPromise = this.#ensureSubscribedThenPump(channelId).catch((e) => {
            debug(`SC pump error [${channelId}]: ${e?.message ?? e}`);
        });

        // reconnect watcher using the consumer
        this.#watchReconnect(channelId).catch((e) => {
            debug(`SC reconnect watcher error [${channelId}]: ${e?.message ?? e}`);
        });

        return this._handle(channelId);
    }

    _handle(channelId) {
        return { stop: () => this.stop(channelId) };
    }

    /* eslint-disable no-empty */
    async stop(key) {
        const channelId = typeof key === 'string' ? key : `order.${key?.public_id}`;
        const sub = this.subs.get(channelId);
        if (!sub) return;

        sub.isActive = false;

        try {
            await sub.channel.unsubscribe(); // ends the channel async iterator
        } catch (e) {
            debug(`SC unsubscribe failed [${channelId}]: ${e?.message ?? e}`);
        }

        try {
            sub.connectConsumer?.close(); // ✅ stop the connect listener stream
        } catch (_) {}

        if (sub.debouncer) {
            clearTimeout(sub.debouncer);
            sub.debouncer = null;
        }

        this.subs.delete(channelId);
    }

    stopAll() {
        for (const id of Array.from(this.subs.keys())) {
            // fire-and-forget; each stop awaits its own unsubscribe
            this.stop(id);
        }
    }

    /* eslint-disable no-unused-vars */
    async #watchReconnect(channelId) {
        const sub = this.subs.get(channelId);
        if (!sub?.isActive) return;

        // Consume 'connect' events until closed
        for await (const _evt of sub.connectConsumer) {
            const current = this.subs.get(channelId);
            if (!current?.isActive) break;

            // SC usually auto-resubscribes; we just ensure state
            if (current.channel.state !== 'subscribed') {
                try {
                    await current.channel.subscribe();
                } catch (e) {
                    debug(`SC resubscribe failed [${channelId}]: ${e?.message ?? e}`);
                }
            }
        }
    }

    async #ensureSubscribedThenPump(channelId) {
        const sub = this.subs.get(channelId);
        if (!sub?.isActive) return;

        const { channel, order, onEvent, debounceMs } = sub;

        if (channel.state !== 'subscribed') {
            await channel.subscribe(); // throws if ackTimeout/auth error
        }

        for await (const output of channel) {
            const current = this.subs.get(channelId);
            if (!current?.isActive) break;

            const reloadable = this.#isReloadableEvent(output, order);
            debug(`SC Event [${channelId}] ${output?.event} : ${JSON.stringify(output)}`);

            if (typeof onEvent === 'function') {
                if (reloadable && debounceMs > 0) {
                    if (current.debouncer) clearTimeout(current.debouncer);
                    current.debouncer = setTimeout(() => {
                        if (this.subs.get(channelId)?.isActive) {
                            onEvent(output, { order, reloadable });
                        }
                        current.debouncer = null;
                    }, debounceMs);
                } else {
                    await onEvent(output, { order, reloadable });
                }
            }
        }
    }

    #isReloadableEvent(output, order) {
        const event = output?.event;
        const data = output?.data;
        if (!event) return false;

        if (event === 'order.updated' && data && order && data.status !== order.status) {
            return true;
        }
        return this.reloadableEvents.has(event);
    }

    async startCompany() {
        // Already running? no-op
        if (this._company?.isActive) return this._companyHandle();

        // Ensure we have a company id
        const companyId = await this.#ensureCompanyId();
        if (!companyId) {
            debug('[order-socket-events] No companyId; aborting company subscribe.');
            return this._companyHandle(); // no-op handle
        }

        // Subscribe
        const sc = this.socket.instance();
        const channelId = `company.${companyId}`;
        const channel = sc.subscribe(channelId);

        // Entregas: só desliga ao sair das telas de pedidos (antes desligava ao abrir os detalhes e não religava)
        const onRoute = (transition) => {
            if (!String(transition?.to?.name ?? '').startsWith('console.fleet-ops.operations.orders')) {
                this.stopCompany();
            }
        };
        this.hostRouter.on('routeWillChange', onRoute);

        this._company = {
            channel,
            isActive: true,
            routeOff: () => this.hostRouter.off('routeWillChange', onRoute),
        };

        // Consume messages
        (async () => {
            try {
                if (channel.state !== 'subscribed') {
                    await channel.subscribe();
                }

                for await (const msg of channel) {
                    const sub = this._company;
                    if (!sub?.isActive) break;

                    const { event, data } = msg || {};
                    debug(`[order-socket-events] company event "${event}" :: ${JSON.stringify(data)}`);

                    switch (event) {
                        case 'order.driver_assigned': {
                            // data: { id: <order_public_id>, driver_assigned: <driver_public_id> }
                            const order = await this.#findOrderByPublicId(data?.id);
                            const driver = await this.#findDriverByPublicId(data?.driver_assigned);

                            if (order && driver) {
                                // adjust key if your relationship is named differently (e.g. 'driverAssigned')
                                order.set?.('driver_assigned', driver);
                            }
                            // Entregas: o pedido sai de "sem motoboy" e entra nos ativos do painel do mapa
                            debounce(this, this.#recarregarPainelDoMapa, ESPERA_PAINEL_MS);
                            break;
                        }

                        default: {
                            // Entregas: qualquer evento de pedido (status, aceite, conclusão...) atualiza
                            // o pedido na lista; pedido novo ou fora da página recarrega a lista
                            if (/^(order|waypoint|entity)\./.test(String(event ?? ''))) {
                                await this.#atualizarPedidoDoEvento(event, data);
                            }
                        }
                    }
                }
            } catch (e) {
                debug(`[order-socket-events] company channel loop error: ${e?.message ?? e}`);
            }
        })();

        return this._companyHandle();
    }

    async #atualizarPedidoDoEvento(event, data) {
        const publicId = data?.public_id ?? data?.order ?? data?.id;
        const pedido = typeof publicId === 'string' && publicId.startsWith('order_') ? this.store.peekAll('order').find((order) => order.public_id === publicId) : null;
        const statusAntes = pedido?.status;

        if (pedido && !pedido.isDeleted) {
            try {
                await pedido.reload();
            } catch (e) {
                debug(`[order-socket-events] reload ${publicId} falhou: ${e?.message ?? e}`);
            }
        }

        // pedido novo, fora da página ou com status novo (a lista pode estar filtrada por status): relê a lista
        if (!pedido || event === 'order.created' || pedido.status !== statusAntes) {
            debounce(this, this.#recarregarListaDePedidos, 1500);
        }

        // o painel do mapa separa os pedidos por motoboy e situação: relê depois do pedido já relido acima
        debounce(this, this.#recarregarPainelDoMapa, ESPERA_PAINEL_MS);
    }

    #recarregarPainelDoMapa() {
        this.orderListOverlay.recarregar();
    }

    #recarregarListaDePedidos() {
        if (String(this.hostRouter.currentRouteName ?? '').startsWith(ROTA_PEDIDOS)) {
            this.hostRouter.refresh(ROTA_PEDIDOS);
        }
    }

    /** Stop the company-level listener */
    /* eslint-disable no-empty */
    async stopCompany() {
        const sub = this._company;
        if (!sub) return;

        sub.isActive = false;
        try {
            await sub.channel?.unsubscribe?.();
            await sub.channel?.close?.();
        } catch (e) {
            debug(`[order-socket-events] company unsubscribe error: ${e?.message ?? e}`);
        }

        try {
            sub.routeOff?.();
        } catch {}
        this._company = null;
    }

    /** tiny handle for symmetry with your per-order API */
    _companyHandle() {
        return { stop: () => this.stopCompany() };
    }

    async #ensureCompanyId() {
        if (this.currentUser?.companyId) return this.currentUser.companyId;

        // wait once for user.loaded
        await new Promise((resolve) => {
            const handler = () => {
                this.currentUser.off?.('user.loaded', handler);
                resolve();
            };
            this.currentUser.on?.('user.loaded', handler);
        });

        return this.currentUser?.companyId;
    }

    async #findOrderByPublicId(publicId) {
        if (!publicId) return null;

        const hit = this.store.peekAll('order').findBy('public_id', publicId);
        if (hit) return hit;

        try {
            return await this.store.queryRecord('order', { public_id: publicId, single: true });
        } catch (e) {
            debug(`[order-socket-events] queryRecord order:${publicId} failed -> ${e?.message}`);
            return null;
        }
    }

    async #findDriverByPublicId(publicId) {
        if (!publicId) return null;

        const hit = this.store.peekAll('driver').findBy('public_id', publicId);
        if (hit) return hit;

        try {
            return await this.store.queryRecord('driver', { public_id: publicId, single: true });
        } catch (e) {
            debug(`[order-socket-events] queryRecord driver:${publicId} failed -> ${e?.message}`);
            return null;
        }
    }
}
