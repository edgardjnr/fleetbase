import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class ConnectivityFuelProvidersIndexDetailsController extends Controller {
    @service intl;
    @service hostRouter;
    @service fetch;
    @service notifications;

    get tabs() {
        return [
            { route: 'connectivity.fuel-providers.details.index', label: this.intl.t('fleet-ops.ui.controller.connectivity-fuel-providers-details.overview') },
            { route: 'connectivity.fuel-providers.details.sync', label: this.intl.t('fleet-ops.ui.controller.connectivity-fuel-providers-details.sync') },
            { route: 'connectivity.fuel-providers.details.matching', label: this.intl.t('fleet-ops.ui.controller.connectivity-fuel-providers-details.matching') },
            { route: 'connectivity.fuel-providers.details.transactions', label: this.intl.t('fleet-ops.ui.controller.connectivity-fuel-providers-details.transactions') },
            { route: 'connectivity.fuel-providers.details.settings', label: this.intl.t('fleet-ops.ui.controller.connectivity-fuel-providers-details.settings') },
        ].map((tab) => ({
            ...tab,
            active: this.hostRouter.currentRouteName?.endsWith(tab.route),
        }));
    }

    get statusLabel() {
        switch (this.model?.status) {
            case 'draft':
                return this.intl.t('fleet-ops.ui.controller.connectivity-fuel-providers-details.draft');
            case 'configured':
                return this.intl.t('fleet-ops.ui.controller.connectivity-fuel-providers-details.configured');
            case 'connected':
                return this.intl.t('fleet-ops.ui.controller.connectivity-fuel-providers-details.connected');
            case 'active':
                return this.intl.t('fleet-ops.ui.controller.connectivity-fuel-providers-details.active');
            case 'error':
                return this.intl.t('fleet-ops.ui.controller.connectivity-fuel-providers-details.needs-attention');
            case 'disabled':
                return this.intl.t('fleet-ops.ui.controller.connectivity-fuel-providers-details.disabled');
            default:
                return this.intl.t('fleet-ops.ui.controller.connectivity-fuel-providers-details.unknown');
        }
    }

    get healthStatus() {
        return ['connected', 'active'].includes(this.model?.status) ? 'success' : this.model?.status === 'error' ? 'warning' : 'default';
    }

    get lastSummary() {
        return this.model?.last_sync_state?.summary ?? {};
    }

    @task *testConnection() {
        try {
            const result = yield this.fetch.post(`fuel-provider-connections/${this.model.id}/test-connection`);
            this.notifications.success(result.message ?? this.intl.t('fleet-ops.ui.controller.connectivity-fuel-providers-details.fuel-integration-connection-tested'));
            yield this.hostRouter.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *syncTransactions(options = {}) {
        try {
            yield this.fetch.post(`fuel-provider-connections/${this.model.id}/sync`, { async: true, ...options });
            this.notifications.success(this.intl.t('fleet-ops.ui.controller.connectivity-fuel-providers-details.fuel-transaction-sync-queued'));
            yield this.hostRouter.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
