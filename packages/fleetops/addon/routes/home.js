import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class HomeRoute extends Route {
    @service intl;
    /**
     * Inject the `loader` service.
     *
     * @var {Service}
     */
    @service loader;

    @action loading(transition) {
        const loader = this.loader.show({ loadingMessage: this.intl.t('fleet-ops.ui.route-js.home.loading-fleet-ops') });

        transition.finally(() => {
            this.loader.removeLoader(loader);
        });
    }
}
