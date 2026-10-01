import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class WidgetIamDashboardWidgetComponent extends Component {
    @service fetch;
    @service intl;

    @tracked data = null;
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get params() {
        return this.args.params ?? {};
    }

    @task *load() {
        try {
            this.data = yield this.fetch.get(`metrics/iam/${this.args.endpoint}`, this.params);
            this.error = null;
        } catch (error) {
            this.error = error?.message ?? this.intl.t('iam.ui.widgets.unable-load-widget');
        }
    }
}
