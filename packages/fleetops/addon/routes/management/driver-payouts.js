import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/**
 * Entregas RestaurantePro: a antiga "Pagamento e cobrança" (Recursos) virou Financeiro → Tabela de km e relatório.
 * A rota fica só para os links e favoritos antigos.
 */
export default class ManagementDriverPayoutsRoute extends Route {
    @service hostRouter;

    beforeModel() {
        return this.hostRouter.transitionTo('console.fleet-ops.financeiro.relatorio');
    }
}
