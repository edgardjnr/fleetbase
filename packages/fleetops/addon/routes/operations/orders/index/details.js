import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { origemDaTransicao } from '../../../../utils/pedido-aberto';

export default class OperationsOrdersIndexDetailsRoute extends Route {
    @service notifications;
    @service store;
    @service hostRouter;
    @service orderSocketEvents;
    @service mapManager;
    @service abilities;
    @service intl;
    @service sidebar;

    @action willTransition(transition) {
        const fromName = transition.from?.name;
        const toName = transition.to?.name;
        const orderDetailsRoute = 'console.fleet-ops.operations.orders.index.details';
        const isRefreshWithinSameDetailsRoute = fromName?.startsWith(orderDetailsRoute) && toName?.startsWith(orderDetailsRoute) && fromName === toName;

        if (isRefreshWithinSameDetailsRoute) {
            return true;
        }

        // only cleanup when leaving the order details route tree entirely
        const isLeavingOrderDetails = fromName?.startsWith(orderDetailsRoute) && !toName?.startsWith(orderDetailsRoute);
        if (isLeavingOrderDetails) {
            const controller = this.controllerFor('operations.orders.index.details');
            controller.teardownRealtime();
            controller.teardownRoutingControls();
        }

        return true;
    }

    @action error(error) {
        this.notifications.serverError(error);
        return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index');
    }

    activate() {
        this.sidebar.hide();
    }

    deactivate() {
        this.sidebar.show();
    }

    beforeModel(transition) {
        if (this.abilities.cannot('fleet-ops view order')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index');
        }

        // Entregas: o pedido abre no mapa do /fleet-ops, com a folha da comanda à direita (sketch 008 A), e não mais
        // neste painel do Fleetbase. Vale para todo lugar que abre o pedido (Tabela, Quadro, Início, avisos, Financeiro,
        // pedido novo); o `de` diz para onde volta a pílula "Voltar".
        const publicId = pedidoDaTransicao(transition);
        if (publicId) {
            const de = origemDaTransicao(transition.from?.name, transition.from?.queryParams?.layout);
            return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index', { queryParams: { layout: 'map', pedido: publicId, de } });
        }
    }

    model({ public_id }) {
        return this.store.queryRecord('order', {
            public_id,
            single: true,
            with: [
                'payload',
                'driverAssigned',
                'orderConfig',
                'customer',
                'facilitator',
                'trackingStatuses',
                'trackingNumber',
                'purchaseRate',
                'purchaseRate.serviceQuote',
                'purchaseRate.serviceQuote.items',
                'comments',
                'files',
            ],
        });
    }

    async setupController(controller, model) {
        super.setupController(...arguments);

        // Change layout to map
        controller.index?.changeLayout?.('map');

        // load activity and reload order if lightweight payload
        await model.loadTrackingActivity();
        if (model.meta?._index_resource) {
            await model.reload();
        }

        await controller.setupDetailsSession();
    }
}

/** O public_id do pedido da transição: o parâmetro da rota ou o modelo passado no transitionTo. */
function pedidoDaTransicao(transition) {
    let rota = transition?.to;
    while (rota && !rota.params?.public_id) rota = rota.parent;
    if (rota?.params?.public_id) return rota.params.public_id;
    const contexto = transition?.intent?.contexts?.[0];
    if (typeof contexto === 'string') return contexto;
    return contexto?.public_id ?? contexto?.id ?? null;
}
