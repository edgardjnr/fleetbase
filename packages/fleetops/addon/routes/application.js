import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import getResourceNameFromTransition from '@fleetbase/ember-core/utils/get-resource-name-from-transition';
import isEntregasHiddenRoute from '../utils/entregas-hidden-routes';

export default class ApplicationRoute extends Route {
    @service loader;
    @service intl;
    @service location;
    @service abilities;
    @service hostRouter;
    @service notifications;
    @service fetch;
    @service currentUser;
    @service mapSettings;
    @service pedidoSemMotoboy;
    @service ifoodAcaoRecusada;

    constructor() {
        super(...arguments);
        // Entregas RestaurantePro: telas ocultas (utils/entregas-hidden-routes) caem em Pedidos
        this.hostRouter.on('routeWillChange', (transition) => {
            if (isEntregasHiddenRoute(transition.to?.name) && !transition.isAborted) {
                transition.abort();
                this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index');
            }
        });
    }

    @action loading(transition) {
        // route segment (e.g. "orders") -> translated resource name when a resource.<name> key exists
        const resourceSlug = getResourceNameFromTransition(transition);
        const resourceKey = typeof resourceSlug === 'string' ? `resource.${resourceSlug.replace(/_/g, '-')}` : null;
        const resource = resourceKey && this.intl.exists(resourceKey) ? this.intl.t(resourceKey) : getResourceNameFromTransition(transition, { humanize: true });
        this.loader.showOnInitialTransition(transition, 'section.next-view-section', {
            loadingMessage: (resource ? this.intl.t('common.loading-resource', { resource }) : this.intl.t('common.loading')) + '...',
        });
    }

    async beforeModel(transition) {
        if (this.abilities.cannot('fleet-ops see extension')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console');
        }

        // Entregas: aviso de pedido aberto sem motoboy (serviço pedido-sem-motoboy); ativo daí em diante
        this.pedidoSemMotoboy.iniciar();
        // Entregas: aviso de ação de logística recusada pelo iFood (serviço ifood-acao-recusada)
        this.ifoodAcaoRecusada.iniciar();

        // primeira entrada no engine já direto numa tela oculta (o listener do constructor ainda não existia)
        if (isEntregasHiddenRoute(transition?.to?.name)) {
            return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index');
        }

        await this.location.getUserLocation();
        await this.#loadRoutingSettings();
        await this.#loadTrackingSettings();
        await this.#loadMapSettings();
    }

    async #loadRoutingSettings() {
        const routingSetting = await this.fetch.get('fleet-ops/settings/routing-settings');
        this.currentUser.setOption('routing', routingSetting);
    }

    async #loadTrackingSettings() {
        const trackingSettings = await this.fetch.get('fleet-ops/settings/tracking-settings');
        this.currentUser.setOption('tracking', trackingSettings);
    }

    async #loadMapSettings() {
        await this.mapSettings.load();
    }
}
