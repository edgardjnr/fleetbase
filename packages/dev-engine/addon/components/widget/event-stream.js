import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { localizeDevLabel } from '../../utils/localize-dev-metrics';

export default class WidgetEventStreamComponent extends Component {
    @service fetch;
    @service intl;

    @tracked data = null;
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get types() {
        return (this.data?.types ?? []).map((item) => ({ ...item, label: localizeDevLabel(this.intl, 'value', item?.label) }));
    }

    get sources() {
        return (this.data?.sources ?? []).map((item) => ({ ...item, label: localizeDevLabel(this.intl, 'value', item?.label) }));
    }

    @task *load() {
        try {
            this.data = yield this.fetch.get('metrics/dev/events', { period: this.args.period ?? '30d' });
            this.error = null;
        } catch (error) {
            this.error = error?.message ?? this.intl.t('developers.ui.unable-to-load-events');
        }
    }

    @action refresh() {
        this.load.perform();
    }
}
