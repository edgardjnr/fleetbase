import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';

export default class WidgetPendingActionsComponent extends Component {
    @service fetch;
    @service intl;
    @tracked actions = [];
    @tracked error;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    @task *load() {
        try {
            const response = yield this.fetch.get('pending-actions', {}, { namespace: 'customer-portal/int/v1' });
            this.actions = response.actions ?? [];
            this.error = null;
        } catch {
            this.actions = [];
            this.error = this.intl.t('customer-portal.ui.widget.load-pending-failed');
        }
    }

    get rows() {
        return this.actions.map((action) => ({
            ...action,
            ...this.localizedText(action),
            icon: action.icon ?? 'circle-exclamation',
            route: action.route ?? this.routeForType(action.type),
            model: action.model ?? action.model_id ?? action.id,
        }));
    }

    /**
     * The API builds title/description in English ("3 unpaid invoices"); translate them by the
     * action `type` with the count taken from the leading number, falling back to the API text.
     */
    localizedText(action) {
        const base = `customer-portal.ui.dashboard.pending-action.${action.type}`;
        const count = parseInt(String(action.title ?? '').match(/^\s*(\d+)/)?.[1] ?? '', 10);
        const tr = (key, fallback, params = {}) => (action.type && this.intl.exists(key) ? this.intl.t(key, params) : fallback);

        return {
            title: Number.isNaN(count) ? action.title : tr(`${base}.title`, action.title, { count }),
            description: tr(`${base}.description`, action.description),
        };
    }

    routeForType(type) {
        switch (type) {
            case 'support':
            case 'support_tickets':
                return 'portal.support';
            case 'billing':
            case 'invoice':
            case 'invoices':
                return 'portal.billing';
            case 'order':
            case 'orders':
                return 'portal.orders';
            default:
                return null;
        }
    }
}
