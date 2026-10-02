import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/**
 * Entregas RestaurantePro: pagamento dos motoboys por km (rota loja → cliente).
 * Dados de `int/v1/entregas/pagamento-motoboys` (api/app/Http/Controllers/Entregas).
 */
export default class ManagementDriverPayoutsRoute extends Route {
    @service notifications;
    @service hostRouter;
    @service currentUser;
    @service intl;

    beforeModel() {
        if (!this.currentUser.isAdmin) {
            this.notifications.warning(this.intl.t('fleet-ops.ui.driver-payouts.admin-only'));
            return this.hostRouter.transitionTo('console.fleet-ops');
        }
    }

    setupController(controller) {
        super.setupController(...arguments);
        controller.load.perform();
    }
}
