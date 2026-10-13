import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/** Entregas RestaurantePro: /financeiro abre o Painel. */
export default class FinanceiroIndexRoute extends Route {
    @service hostRouter;

    beforeModel() {
        return this.hostRouter.transitionTo('console.fleet-ops.financeiro.painel');
    }
}
