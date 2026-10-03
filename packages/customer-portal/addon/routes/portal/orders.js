import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class PortalOrdersRoute extends Route {
    @service customerPortalOrderActions;

    queryParams = {
        query: { refreshModel: true },
    };

    async model(params) {
        const orders = await this.customerPortalOrderActions.loadOrders.perform({ query: params.query });
        return { orders };
    }

    // Entregas: a lista se atualiza sozinha enquanto a página de pedidos está aberta. Uma busca nova recarrega o model e
    // passa pelo resetController e pelo setupController de novo, e o ciclo recomeça do zero
    setupController(controller) {
        super.setupController(...arguments);
        controller.atualizarLista.perform();
    }

    resetController(controller) {
        super.resetController(...arguments);
        controller.atualizarLista.cancelAll();
    }
}
