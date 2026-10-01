import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class ModalsValidatePasswordComponent extends Component {
    @service fetch;
    @service notifications;
    @service intl;
    // The constructor assigns the modal's options straight away, so this initializer
    // never runs.
    /* istanbul ignore next -- always assigned before first read */
    @tracked options = {};
    @tracked password;
    @tracked confirmPassword;

    constructor(owner, { options }) {
        super(...arguments);
        this.options = options;
        this.setupOptions();
    }

    setupOptions() {
        this.options.title = this.intl.t('console.ui.modals.validate-password.title');
        this.options.acceptButtonText = this.intl.t('console.ui.modals.validate-password.accept');
        this.options.declineButtonHidden = true;
        this.options.confirm = (modal) => {
            modal.startLoading();
            return this.validatePassword.perform();
        };
    }

    @task *validatePassword() {
        let isPasswordValid = false;

        try {
            yield this.fetch.post('users/validate-password', {
                password: this.password,
                password_confirmation: this.confirmPassword,
            });

            isPasswordValid = true;
        } catch (error) {
            this.notifications.serverError(error, this.intl.t('console.ui.modals.validate-password.invalid'));
        }

        if (typeof this.options.onValidated === 'function') {
            this.options.onValidated(isPasswordValid);
        }

        return isPasswordValid;
    }
}
