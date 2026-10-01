import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class WidgetArAgingSummaryComponent extends Component {
    @service fetch;
    @service intl;
    @service ledgerDashboard;

    @tracked data = null;
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.unsubscribeDashboard = this.ledgerDashboard.subscribe(() => this.load.perform());
        this.load.perform();
    }

    get buckets() {
        return this.data?.buckets ?? [];
    }

    get bucketRows() {
        return this.buckets.map((bucket) => ({
            ...bucket,
            label: this.bucketLabel(bucket),
            agingClass: `ledger-aging-${bucket.key?.replace(/_/g, '-')}`,
            pct: Math.round(((bucket.total ?? 0) / this.maxTotal) * 100),
        }));
    }

    bucketLabel(bucket) {
        const keyMap = { current: 'current', '1_30': 'days-1-30', '31_60': 'days-31-60', '61_90': 'days-61-90', over_90: 'days-over-90' };
        const key = keyMap[bucket.key];
        return key ? this.intl.t(`ledger.ui.reports.buckets.${key}`) : bucket.label;
    }

    get maxTotal() {
        return Math.max(...this.buckets.map((bucket) => bucket.total), 1);
    }

    @task *load() {
        try {
            const response = yield this.fetch.get('reports/dashboard/ar-aging-summary', this.ledgerDashboard.asOfParams, { namespace: 'ledger/int/v1' });
            this.data = response?.data ?? response;
            this.error = null;
        } catch (error) {
            this.error = error?.message ?? this.intl.t('ledger.ui.widget.load-ar-aging-failed');
        }
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this.unsubscribeDashboard?.();
    }
}
