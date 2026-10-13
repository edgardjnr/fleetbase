import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/**
 * Entregas RestaurantePro: Financeiro → Lojas e faturas (abas Faturas, Lojas e Ciclo em andamento).
 * Dados de `int/v1/entregas/financeiro/{faturas,lojas}` (api/app/Http/Controllers/Entregas/FinanceiroLojasController.php).
 */
export default class FinanceiroLojasRoute extends Route {
    @service notifications;
    @service hostRouter;
    @service currentUser;
    @service intl;

    beforeModel() {
        if (!this.currentUser.isAdmin) {
            this.notifications.warning(this.intl.t('fleet-ops.ui.financeiro-lojas.admin-only'));
            return this.hostRouter.transitionTo('console.fleet-ops');
        }
    }

    setupController(controller) {
        super.setupController(...arguments);
        controller.carregar.perform();
    }

    resetController(controller, isExiting) {
        super.resetController(...arguments);
        if (isExiting) {
            controller.limpar();
        }
    }
}
