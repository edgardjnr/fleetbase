import Service from '@ember/service';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class IamService extends Service {
    @service fetch;
    @service notifications;
    @service intl;

    get schemeTypes() {
        return [
            {
                name: this.intl.t('iam.ui.scheme-types.flb-managed'),
                id: 'flb-managed',
            },
            {
                name: this.intl.t('iam.ui.scheme-types.org-managed'),
                id: 'org-managed',
            },
        ];
    }

    @task *getServices(options = {}) {
        try {
            const services = yield this.fetch.get('auth/services');
            if (typeof options.onSuccess === 'function') {
                options.onSuccess(services);
            }
            return services;
        } catch (error) {
            this.notifications.serverError(error);
            if (typeof options.onError === 'function') {
                options.onError(error);
            }
        }
    }
}
