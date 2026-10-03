import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/**
 * Entregas RestaurantePro: cadastro das lojas (restaurantes atendidos) e dos logins do portal da loja.
 * Dados de `int/v1/entregas/lojas` (api/app/Http/Controllers/Entregas/LojasController.php).
 */
export default class ManagementLojasRoute extends Route {
    @service notifications;
    @service hostRouter;
    @service currentUser;
    @service intl;

    beforeModel() {
        if (!this.currentUser.isAdmin) {
            this.notifications.warning(this.intl.t('fleet-ops.ui.lojas.admin-only'));
            return this.hostRouter.transitionTo('console.fleet-ops');
        }
    }

    setupController(controller) {
        super.setupController(...arguments);
        controller.carregar.perform();
    }

    resetController(controller, isExiting) {
        super.resetController(...arguments);
        // ao sair da tela, nada fica para trás: nem rascunhos nem senhas digitadas
        if (isExiting) {
            controller.limpar();
        }
    }
}
