import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';
import { task } from 'ember-concurrency';

export default class ReportsArAgingController extends Controller {
    @service fetch;
    @service currentUser;
    @service intl;

    @tracked data = null;
    @tracked as_of = null;

    get companyCurrency() {
        return this.currentUser.getCompany()?.currency ?? 'USD';
    }

    get bucketList() {
        if (!this.data?.buckets) return [];
        return Object.values(this.data.buckets);
    }

    bucketLabel(key, fallback) {
        const keyMap = { current: 'current', '1_30': 'days-1-30', '31_60': 'days-31-60', '61_90': 'days-61-90', over_90: 'days-over-90' };
        return keyMap[key] ? this.intl.t(`ledger.ui.reports.buckets.${keyMap[key]}`) : fallback;
    }

    get bucketSummary() {
        if (!this.data?.buckets) return [];
        return Object.entries(this.data.buckets).map(([key, b]) => ({
            label: this.bucketLabel(key, b.label),
            total: b.total ?? 0,
        }));
    }

    get allInvoices() {
        if (!this.data?.buckets) return [];
        return Object.entries(this.data.buckets).flatMap(([key, b]) =>
            (b.invoices ?? []).map((inv) => ({ ...inv, bucketLabel: this.bucketLabel(key, b.label), daysRange: b.days_range }))
        );
    }

    @task *loadReport() {
        try {
            const params = {};
            if (this.as_of) params.as_of_date = this.as_of;
            const result = yield this.fetch.get('reports/ar-aging', params, { namespace: 'ledger/int/v1' });
            this.data = result?.data ?? null;
        } catch {
            this.data = null;
        }
    }

    @action reload() {
        this.loadReport.perform();
    }

    @action onDateChanged({ formattedDate }) {
        if (isArray(formattedDate) && formattedDate.length > 0) {
            this.as_of = formattedDate[0];
        } else {
            this.as_of = null;
        }
        this.loadReport.perform();
    }
}
