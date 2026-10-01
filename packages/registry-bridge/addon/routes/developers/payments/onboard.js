import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class DevelopersPaymentsOnboardRoute extends Route {
    @service fetch;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    async beforeModel() {
        if (this.abilities.cannot('registry-bridge onboard extension-payment')) {
            this.notifications.warning(this.intl.t('registry-bridge.ui.unauthorized-access'));
            return this.hostRouter.transitionTo('console');
        }

        try {
            const { hasStripeConnectAccount } = await this.fetch.get('payments/has-stripe-connect-account', {}, { namespace: '~registry/v1' });
            if (hasStripeConnectAccount) {
                this.notifications.info(this.intl.t('registry-bridge.ui.already-onboarded'));
                this.hostRouter.transitionTo('console.extensions.payments');
            }
        } catch (error) {
            this.notifications.serverError(error);
            this.hostRouter.transitionTo('console.extensions.payments');
        }
    }
}
