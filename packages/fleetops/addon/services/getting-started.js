import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import { localizeGettingStarted } from '../utils/localize-api-payload';

export default class GettingStartedService extends Service {
    @service intl;
    @service fetch;

    @tracked data = null;
    @tracked error = null;

    get isCompleted() {
        return this.data?.is_completed === true;
    }

    /** Server payload with step/recommendation text translated by `key` (falls back to the API text). */
    get localized() {
        return localizeGettingStarted(this.intl, this.data);
    }

    get steps() {
        return this.localized?.steps ?? [];
    }

    get recommendations() {
        return this.localized?.recommendations ?? [];
    }

    get progress() {
        return this.data?.progress ?? { completed: 0, total: 0, percent: 0 };
    }

    get nextStepKey() {
        return this.data?.next_step;
    }

    get nextStep() {
        return this.steps.find((step) => step.key === this.nextStepKey) ?? this.steps.find((step) => !step.completed);
    }

    @task({ drop: true }) *load() {
        try {
            this.data = yield this.fetch.get('fleet-ops/getting-started/status');
            this.error = null;
        } catch (error) {
            this.error = error?.message ?? this.intl.t('fleet-ops.ui.service.getting-started.failed-to-load-getting-started-status');
        }
    }
}
