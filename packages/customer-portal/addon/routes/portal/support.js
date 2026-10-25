import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class PortalSupportRoute extends Route {
    @service hostRouter;

    beforeModel() {
        // Entregas: tela fora do escopo do portal da loja (a rota só redireciona links antigos; as filhas não têm arquivo)
        return this.hostRouter.transitionTo('customer-portal.portal.orders');
    }
}
