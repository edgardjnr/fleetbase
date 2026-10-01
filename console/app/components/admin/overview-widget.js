import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class AdminOverviewWidgetComponent extends Component {
    @service fetch;
    @service intl;

    @tracked metrics = {};
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get title() {
        return this.intl.t('console.ui.admin.overview-widget.title');
    }

    get items() {
        return [
            { label: this.intl.t('common.users'), value: this.metrics.usersTotal?.value ?? 0 },
            { label: this.intl.t('console.ui.account.organizations.title'), value: this.metrics.organizationsTotal?.value ?? 0 },
            { label: this.intl.t('console.ui.admin.overview-widget.active-admins'), value: this.metrics.activeAdmins?.value ?? 0 },
            { label: this.intl.t('console.ui.admin.overview-widget.needs-attention'), value: this.metrics.organizationsAttention?.value ?? 0 },
        ];
    }

    @task *load() {
        try {
            const [usersTotal, organizationsTotal, activeAdmins, organizationsAttention] = yield Promise.all([
                this.fetch.get('metrics/admin/kpis/users-total'),
                this.fetch.get('metrics/admin/kpis/organizations-total'),
                this.fetch.get('metrics/admin/kpis/active-admins'),
                this.fetch.get('metrics/admin/kpis/organizations-attention'),
            ]);
            this.metrics = {
                usersTotal,
                organizationsTotal,
                activeAdmins,
                organizationsAttention,
            };
            this.error = null;
        } catch (error) {
            this.error = error?.message ?? this.intl.t('console.ui.admin.overview-widget.load-error');
        }
    }
}
