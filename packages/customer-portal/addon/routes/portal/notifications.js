import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class PortalNotificationsRoute extends Route {
    @service fetch;
    @service hostRouter;

    beforeModel() {
        // Entregas: tela fora do escopo do portal da loja
        return this.hostRouter.transitionTo('customer-portal.portal.orders');
    }

    async model() {
        try {
            return await this.fetch.get('notification-preferences', {}, { namespace: 'customer-portal/int/v1' });
        } catch {
            return { preferences: {} };
        }
    }

    setupController(controller, model) {
        super.setupController(controller, model);
        controller.preferences = { ...controller.preferences, ...(model.preferences ?? {}) };
    }
}
