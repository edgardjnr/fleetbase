import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ManagementFuelTransactionsIndexController extends Controller {
    @service intl;
    @service tableContext;
    @service fetch;
    @service notifications;
    @service hostRouter;

    @tracked queryParams = ['page', 'limit', 'sort', 'query', 'provider', 'sync_status', 'vehicle', 'connection', 'transaction_at'];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-transaction_at';
    @tracked query;
    @tracked provider;
    @tracked sync_status;
    @tracked vehicle;
    @tracked connection;
    @tracked transaction_at;
    @tracked table;

    get hasRecords() {
        return Array.from(this.model ?? []).length > 0;
    }

    get emptyStateTitle() {
        if (this.sync_status === 'unmatched') {
            return this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.no-unmatched-fuel-transactions');
        }

        if (this.connection) {
            return this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.no-transactions-imported-for-this-integration');
        }

        return this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.no-fuel-transactions-imported-yet');
    }

    get emptyStateMessage() {
        if (this.sync_status === 'unmatched') {
            return this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.fleet-ops-did-not-find-imported');
        }

        if (this.connection) {
            return this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.run-a-sync-from-the-fuel');
        }

        return this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.connect-petroapp-or-another-fuel-integration');
    }

    get actionButtons() {
        return [
            {
                icon: 'refresh',
                onClick: this.refresh,
                helpText: this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.refresh'),
            },
            {
                icon: 'gas-pump',
                text: this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.fuel-integrations'),
                onClick: () => this.hostRouter.transitionTo('console.fleet-ops.connectivity.fuel-providers.index'),
            },
        ];
    }

    @action refresh() {
        this.target.send('refresh');
    }

    get bulkActions() {
        const selected = this.tableContext.getSelectedRows();

        return [
            {
                label: this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.reprocess-selected', { length: selected.length }),
                fn: () => selected.forEach((transaction) => this.reprocessTransaction.perform(transaction)),
            },
        ];
    }

    get columns() {
        return [
            {
                sticky: true,
                label: this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.transaction'),
                valuePath: 'provider_transaction_id',
                cellComponent: 'click-to-copy',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'query',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.provider'),
                valuePath: 'provider',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.status'),
                valuePath: 'sync_status',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/multi-option',
                filterOptions: ['imported', 'matched', 'unmatched', 'reviewed', 'ignored', 'duplicate', 'error'],
            },
            {
                label: this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.vehicle'),
                valuePath: 'vehicle_name',
                resizable: true,
                sortable: false,
                filterable: true,
                filterParam: 'vehicle',
                filterComponent: 'filter/model',
                model: 'vehicle',
                modelNamePath: 'displayName',
            },
            {
                label: this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.card-internal'),
                valuePath: 'vehicle_card_id',
                resizable: true,
            },
            {
                label: this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.trip'),
                valuePath: 'trip_number',
                resizable: true,
                hidden: true,
            },
            {
                label: this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.station'),
                valuePath: 'station_name',
                resizable: true,
            },
            {
                label: this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.liters'),
                valuePath: 'volume',
                resizable: true,
                sortable: true,
            },
            {
                label: this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.amount'),
                valuePath: 'amount',
                cellComponent: 'table/cell/currency',
                resizable: true,
                sortable: true,
            },
            {
                label: this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.fuel-report'),
                valuePath: 'fuel_report_id',
                cellComponent: 'table/cell/anchor',
                action: this.openFuelReport,
                resizable: true,
            },
            {
                label: this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.date'),
                valuePath: 'transactionAt',
                sortParam: 'transaction_at',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'transaction_at',
                filterComponent: 'filter/date',
            },
            {
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                ddMenuLabel: this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.fuel-transaction-actions'),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                actions: [
                    { label: this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.review-details'), fn: this.openDetails },
                    { label: this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.open-fuel-report'), fn: this.openFuelReport },
                    { separator: true },
                    { label: this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.match-to-vehicle'), fn: this.matchVehicle },
                    { label: this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.match-to-order'), fn: this.matchOrder },
                    { label: this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.reprocess-rematch'), fn: (transaction) => this.reprocessTransaction.perform(transaction) },
                    { label: this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.ignore-transaction'), fn: (transaction) => this.markReviewed.perform(transaction, 'ignored') },
                    { label: this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.mark-reviewed'), fn: (transaction) => this.markReviewed.perform(transaction, 'reviewed') },
                ],
                sortable: false,
                filterable: false,
                resizable: false,
                searchable: false,
            },
        ];
    }

    @action openDetails(transaction) {
        return this.hostRouter.transitionTo('console.fleet-ops.management.fuel-transactions.index.details', transaction);
    }

    @action openFuelReport(transaction) {
        if (!transaction?.fuel_report_id) {
            this.notifications.info(this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.this-transaction-does-not-have-a'));
            return;
        }

        return this.hostRouter.transitionTo('console.fleet-ops.management.fuel-reports.index.details', transaction.fuel_report_id);
    }

    @action matchVehicle(transaction) {
        return this.hostRouter.transitionTo('console.fleet-ops.management.fuel-transactions.index.details', transaction);
    }

    @action matchOrder(transaction) {
        return this.hostRouter.transitionTo('console.fleet-ops.management.fuel-transactions.index.details', transaction);
    }

    @task *reprocessTransaction(transaction) {
        try {
            yield this.fetch.post(`fuel-provider-transactions/${transaction.id}/reprocess`);
            this.notifications.success(this.intl.t('fleet-ops.ui.controller.management-fuel-transactions-index.fuel-transaction-reprocessed'));
            this.target.send('refresh');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *markReviewed(transaction, status) {
        try {
            yield this.fetch.post(`fuel-provider-transactions/${transaction.id}/review`, { status });
            this.notifications.success(status === 'ignored' ? 'Fuel transaction ignored.' : 'Fuel transaction marked reviewed.');
            this.target.send('refresh');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
