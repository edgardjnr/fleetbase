import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class PortalAccountRoute extends Route {
    @service hostRouter;

    beforeModel() {
        // Entregas: Route#transitionTo não existe no Ember 5; redireciona pelo hostRouter, como o resto do portal
        return this.hostRouter.transitionTo('customer-portal.portal.settings.account');
    }
}
