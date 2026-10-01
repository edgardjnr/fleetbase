import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { getOwner } from '@ember/application';

export default class UsersController extends Controller {
    @service hostRouter;
    @service intl;

    get tabs() {
        return [
            {
                route: 'users.index',
                label: this.intl.t('iam.ui.nav.users'),
            },
            {
                route: 'users.drivers',
                label: this.intl.t('iam.ui.nav.drivers'),
            },
            {
                route: 'users.customers',
                label: this.intl.t('iam.ui.nav.customers'),
            },
        ];
    }

    get childController() {
        const owner = getOwner(this);
        const fullRouteName = this.hostRouter.currentRouteName;

        // strip engine mount prefix once
        const mount = owner.mountPoint;
        let local = fullRouteName;
        if (mount && local.startsWith(mount + '.')) {
            local = local.slice(mount.length + 1);
        }

        const childController = owner.lookup(`controller:${local}`);
        return childController;
    }
}
