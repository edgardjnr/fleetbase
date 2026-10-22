import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/**
 * Entregas RestaurantePro: Financeiro → Corridas do sistema (relatório do dono: todas as corridas finalizadas de todas as
 * organizações, por central, com o preço por corrida). Só o administrador do sistema (`currentUser.isAdmin`), com guarda
 * própria: não depende da guarda do Financeiro (routes/financeiro.js), que vai passar a ser o administrador da
 * organização. Dados de `int/v1/entregas/sistema/*` (api/app/Http/Controllers/Entregas/CorridasDoSistemaController.php).
 */
export default class FinanceiroCorridasDoSistemaRoute extends Route {
    @service currentUser;
    @service notifications;
    @service hostRouter;
    @service intl;

    beforeModel() {
        if (this.currentUser.isAdmin !== true) {
            this.notifications.warning(this.intl.t('fleet-ops.ui.financeiro.corridas-do-sistema.so-admin'));
            return this.hostRouter.transitionTo('console.fleet-ops');
        }
    }

    setupController(controller) {
        super.setupController(...arguments);
        controller.atualizarPeriodo();
        controller.carregar.perform();
        controller.carregarPrecos.perform();
    }

    resetController(controller, isExiting) {
        super.resetController(...arguments);
        if (isExiting) {
            controller.limpar();
        }
    }
}
