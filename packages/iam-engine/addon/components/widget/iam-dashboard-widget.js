import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import localizeIamMetrics from '../../utils/localize-iam-metrics';

export default class WidgetIamDashboardWidgetComponent extends Component {
    @service fetch;
    @service intl;

    @tracked data = null;
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    /** Payload with the API's fixed English labels translated for display. */
    get localizedData() {
        return localizeIamMetrics(this.intl, this.args.endpoint, this.data);
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
