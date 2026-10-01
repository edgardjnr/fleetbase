import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

const WARNING_STATUSES = ['error', 'disabled'];

export default class FuelIntegrationHubComponent extends Component {
    @service intl;
    @service fuelIntegrationActions;
    @service currentUser;
    @tracked table;
    @tracked columns = this.args.columns ?? [];

    get connections() {
        return Array.from(this.args.connections ?? []);
    }

    get providers() {
        return Array.from(this.args.providers ?? []);
    }

    get hasConnections() {
        return this.connections.length > 0;
    }

    get connectedProviderKeys() {
        return new Set(this.connections.map((connection) => connection.provider).filter(Boolean));
    }

    get providerCards() {
        return this.providers.map((provider) => {
            const connection = this.connections.find((record) => record.provider === provider.key);

            return {
                ...provider,
                connection,
                connected: Boolean(connection),
                status: connection?.status ?? 'available',
                categoryLabel: provider.category ?? provider.metadata?.category ?? this.intl.t('fleet-ops.ui.fuel-integration.hub.fuel-card-integration'),
            };
        });
    }

    get activeConnectionCount() {
        return this.connections.filter((connection) => !['disabled', 'archived'].includes(connection.status)).length;
    }

    get unmatchedTransactionsCount() {
        return this.connections.reduce((total, connection) => total + Number(connection.last_sync_state?.summary?.unmatched ?? 0), 0);
    }

    get syncErrorCount() {
        return this.connections.filter((connection) => WARNING_STATUSES.includes(connection.status) || connection.last_error).length;
    }

    get totalLiters() {
        return this.connections.reduce((total, connection) => total + Number(connection.last_sync_state?.summary?.liters ?? 0), 0);
    }

    get totalSpend() {
        return this.connections.reduce((total, connection) => total + Number(connection.last_sync_state?.summary?.amount ?? 0), 0);
    }

    get lastSyncLabel() {
        const syncedAt = this.connections
            .map((connection) => connection.last_synced_at)
            .filter(Boolean)
            .sort()
            .pop();

        return syncedAt ? new Date(syncedAt).toLocaleString() : this.intl.t('fleet-ops.ui.fuel-integration.hub.not-synced');
    }

    get kpiWidgets() {
        return [
            {
                icon: 'gas-pump',
                label: this.intl.t('fleet-ops.ui.fuel-integration.hub.connected-integrations'),
                value: this.activeConnectionCount,
                help: this.intl.t('fleet-ops.ui.fuel-integration.hub.fuel-card-and-fuel-management-accounts'),
                action: this.intl.t('fleet-ops.ui.fuel-integration.hub.connect-integration-2'),
                actionType: 'create',
                accentClass: 'fleetops-connectivity-kpi-accent-blue',
            },
            {
                icon: 'clock-rotate-left',
                label: this.intl.t('fleet-ops.ui.fuel-integration.hub.last-sync'),
                value: this.lastSyncLabel,
                help: this.intl.t('fleet-ops.ui.fuel-integration.hub.most-recent-completed-import'),
                action: this.intl.t('fleet-ops.ui.fuel-integration.hub.review-sync'),
                actionType: 'review',
                accentClass: 'fleetops-connectivity-kpi-accent-green',
            },
            {
                icon: 'triangle-exclamation',
                label: this.intl.t('fleet-ops.ui.fuel-integration.hub.unmatched-transactions'),
                value: this.unmatchedTransactionsCount,
                help: this.unmatchedTransactionsCount > 0 ? this.intl.t('fleet-ops.ui.fuel-integration.hub.need-vehicle-or-trip-matching') : this.intl.t('fleet-ops.ui.fuel-integration.hub.no-unmatched-count'),
                action: this.intl.t('fleet-ops.ui.fuel-integration.hub.review-matches'),
                actionType: 'transactions',
                accentClass: 'fleetops-connectivity-kpi-accent-amber',
            },
            {
                icon: 'receipt',
                label: this.intl.t('fleet-ops.ui.fuel-integration.hub.imported-spend'),
                value: this.formatSpend,
                help: `${this.formatLiters} imported`,
                action: this.intl.t('fleet-ops.ui.fuel-integration.hub.open-ledger'),
                actionType: 'transactions',
                accentClass: 'fleetops-connectivity-kpi-accent-rose',
            },
        ];
    }

    get formatSpend() {
        const currency = this.currentUser?.company?.currency || this.currentUser?.currency || 'USD';

        if (!this.totalSpend) {
            return `${currency} 0`;
        }

        return `${currency} ${(this.totalSpend / 100).toLocaleString(undefined, { maximumFractionDigits: 2 })}`;
    }

    get formatLiters() {
        if (!this.totalLiters) {
            return '0 L';
        }

        return `${Number(this.totalLiters).toLocaleString(undefined, { maximumFractionDigits: 1 })} L`;
    }

    @action openProvider(provider) {
        if (provider.connection) {
            return this.fuelIntegrationActions.transition.view(provider.connection);
        }

        return this.fuelIntegrationActions.transition.create(provider.key);
    }

    @action createIntegration() {
        return this.fuelIntegrationActions.transition.create();
    }

    @action runKpiAction(widget) {
        if (widget.actionType === 'create') {
            return this.fuelIntegrationActions.transition.create();
        }

        if (widget.actionType === 'transactions') {
            return this.fuelIntegrationActions.transitionTo('management.fuel-transactions.index');
        }

        const connection = this.connections[0];
        if (connection) {
            return this.fuelIntegrationActions.transition.view(connection);
        }

        return this.fuelIntegrationActions.transition.create();
    }

    @action setupTable(table) {
        this.table = table;
    }
}
