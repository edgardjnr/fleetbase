import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/**
 * Entregas RestaurantePro: Financeiro → fatura de uma loja (`:id` = uuid da fatura). Rascunho: conferir, não cobrar,
 * ajustar, mudar o vencimento e emitir. Emitida: recebimento, reenviar e-mail, imprimir e cancelar.
 * Dados de `int/v1/entregas/financeiro/faturas/{id}` (FinanceiroLojasController).
 */
export default class FinanceiroFaturaRoute extends Route {
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
