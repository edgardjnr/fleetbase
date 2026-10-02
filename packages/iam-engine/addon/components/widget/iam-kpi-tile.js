import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class WidgetIamKpiTileComponent extends Component {
    @service fetch;
    @service intl;

    @tracked data = null;
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get metric() {
        return this.data?.[this.args.metric] ?? {};
    }

    get value() {
        if (this.metric.format === 'unavailable' || this.metric.available === false) {
            return 'N/A';
        }

        if (this.metric.format === 'percent') {
            return `${this.metric.value ?? 0}%`;
        }

        return Number(this.metric.value ?? 0).toLocaleString();
    }

    get footnote() {
        if (this.metric.format === 'unavailable' || this.metric.available === false) {
            return this.args.unavailableText ?? this.intl.t('iam.ui.widgets.coverage-unavailable');
        }

        if (this.args.footnote) {
            return this.args.footnote;
        }

        // `format` is an API identifier (percent, users, ...); show its translation when one exists.
        const formatKey = `iam.ui.api.metric-format.${this.metric.format}`;
        if (this.metric.format && this.intl.exists(formatKey)) {
            return this.intl.t(formatKey);
        }

        return this.metric.format ?? this.intl.t('iam.ui.widgets.current');
    }

    get accentClass() {
        return `iam-kpi-accent-${this.args.accent ?? 'blue'}`;
    }

    @task *load() {
        try {
            this.data = yield this.fetch.get('metrics/iam/kpis');
            this.error = null;
        } catch (error) {
            this.error = error?.message ?? this.intl.t('iam.ui.widgets.unable-load-kpi');
        }
    }
}
