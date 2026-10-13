import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/**
 * Entregas RestaurantePro: Financeiro → extrato da loja (`:id` = public_id do Vendor). Saldo, lançamentos com a fatura
 * em que entraram, Registrar recebimento e Lançar ajuste / saldo inicial.
 * Dados de `int/v1/entregas/financeiro/lojas/{id}/extrato` (FinanceiroLojasController).
 */
export default class FinanceiroLojaRoute extends Route {
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

    model({ id }) {
        return { id };
    }

    setupController(controller, model) {
        super.setupController(...arguments);
        controller.abrir(model.id);
    }

    resetController(controller, isExiting) {
        super.resetController(...arguments);
        if (isExiting) {
            controller.limpar();
        }
    }
}
