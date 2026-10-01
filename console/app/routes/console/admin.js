import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ConsoleAdminRoute extends Route {
    @service currentUser;
    @service notifications;
    @service router;
    @service intl;

    beforeModel() {
        if (!this.currentUser.isAdmin) {
            return this.router.transitionTo('console').then(() => {
                this.notifications.error(this.intl.t('console.ui.admin.unauthorized'));
            });
        }
    }
}
