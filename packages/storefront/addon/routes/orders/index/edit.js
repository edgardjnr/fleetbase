import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class OrdersIndexEditRoute extends Route {
    @service intl;
    @service abilities;
    @service hostRouter;
    @service notifications;

    beforeModel() {
        if (this.abilities.cannot('storefront update order')) {
            this.notifications.warning(this.intl.t('storefront.ui.unauthorized-access'));
            return this.hostRouter.transitionTo('console.storefront');
        }
    }
}
