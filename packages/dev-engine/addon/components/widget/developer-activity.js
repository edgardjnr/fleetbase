import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { localizeDevLabel } from '../../utils/localize-dev-metrics';

export default class WidgetDeveloperActivityComponent extends Component {
    @service fetch;
    @service intl;

    @tracked data = null;
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get items() {
        return (this.data?.items ?? []).map((item) => ({ ...item, typeLabel: localizeDevLabel(this.intl, 'activity-type', item?.type) }));
    }

    iconFor(type) {
        if (type === 'webhook') {
            return 'webhook';
        }

        if (type === 'event') {
            return 'bolt';
        }

        return 'terminal';
    }

    @task *load() {
        try {
            this.data = yield this.fetch.get('metrics/dev/activity', { limit: 14 });
            this.error = null;
        } catch (error) {
            this.error = error?.message ?? this.intl.t('developers.ui.unable-to-load-activity');
        }
    }

    @action refresh() {
        this.load.perform();
    }
}
