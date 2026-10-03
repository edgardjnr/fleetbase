import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class PortalSettingsIndexRoute extends Route {
    @service hostRouter;

    beforeModel() {
        // Route#transitionTo não existe no Ember 5: usa o hostRouter, como o resto do portal
        return this.hostRouter.transitionTo('customer-portal.portal.settings.account');
    }
}
