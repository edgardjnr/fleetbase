import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class PortalBillingRoute extends Route {
    @service hostRouter;

    beforeModel() {
        // Entregas: tela fora do escopo do portal da loja
        return this.hostRouter.transitionTo('customer-portal.portal.orders');
    }
}
