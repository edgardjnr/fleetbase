import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/** Entregas RestaurantePro: /financeiro abre o Painel; para o admin da organização (sem ser do sistema), Saques Pix. */
export default class FinanceiroIndexRoute extends Route {
    @service hostRouter;
    @service currentUser;

    beforeModel() {
        const destino = this.currentUser.isAdmin ? 'painel' : 'saques';
        return this.hostRouter.transitionTo(`console.fleet-ops.financeiro.${destino}`);
    }
}
