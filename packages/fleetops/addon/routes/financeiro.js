import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/**
 * Entregas RestaurantePro: Financeiro (painel, motoboys, lojas e faturas, tabela de km e relatório, configurações).
 * Só administradores, como a API (int/v1/entregas/financeiro/*, negarSeNaoAdmin). A guarda vale para todas as telas filhas.
 */
export default class FinanceiroRoute extends Route {
    @service notifications;
    @service hostRouter;
    @service currentUser;
    @service intl;

    beforeModel() {
        if (!this.currentUser.isAdmin) {
            this.notifications.warning(this.intl.t('fleet-ops.ui.financeiro.admin-only'));
            return this.hostRouter.transitionTo('console.fleet-ops');
        }
    }
}
