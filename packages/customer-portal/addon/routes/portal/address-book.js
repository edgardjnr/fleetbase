import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class PortalAddressBookRoute extends Route {
    @service hostRouter;

    beforeModel() {
        // Entregas: tela fora do escopo do portal da loja (a rota só redireciona links antigos)
        return this.hostRouter.transitionTo('customer-portal.portal.orders');
    }
}
