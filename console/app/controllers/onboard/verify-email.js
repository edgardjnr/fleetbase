import AuthVerificationController from '../auth/verification';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';

export default class OnboardVerifyEmailController extends AuthVerificationController {
    @service fetch;
    @service intl;
    @service notifications;
    @service session;
    @service currentUser;
    @service router;

    /** props */
    @tracked hello;
    @tracked code;
    @tracked queryParams = ['hello', 'code'];

    @task *verifyCode() {
        try {
            const { status, token } = yield this.fetch.post('onboard/verify-email', { session: this.hello, code: this.code });
            if (status === 'ok') {
                this.notifications.success(this.intl.t('console.ui.verification.email-verified'));

                if (token) {
                    this.notifications.info(this.intl.t('console.ui.onboarding.welcome'));
                    this.session.manuallyAuthenticate(token);

                    return this.router.transitionTo('console');
                }

                return this.router.transitionTo('auth.login');
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
