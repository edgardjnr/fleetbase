import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class ConnectivityFuelProvidersIndexDetailsTransactionsController extends Controller {
    @service intl;
    @service hostRouter;

    get columns() {
        return [
            { sticky: true, label: this.intl.t('fleet-ops.ui.controller.connectivity-fuel-providers-details-transactions.transaction'), valuePath: 'provider_transaction_id', cellComponent: 'click-to-copy', resizable: true },
            { label: this.intl.t('fleet-ops.ui.controller.connectivity-fuel-providers-details-transactions.status'), valuePath: 'sync_status', cellComponent: 'table/cell/status', resizable: true },
            { label: this.intl.t('fleet-ops.ui.controller.connectivity-fuel-providers-details-transactions.vehicle'), valuePath: 'vehicle_name', resizable: true },
            { label: this.intl.t('fleet-ops.ui.controller.connectivity-fuel-providers-details-transactions.station'), valuePath: 'station_name', resizable: true },
            { label: this.intl.t('fleet-ops.ui.controller.connectivity-fuel-providers-details-transactions.liters'), valuePath: 'volume', resizable: true },
            { label: this.intl.t('fleet-ops.ui.controller.connectivity-fuel-providers-details-transactions.amount'), valuePath: 'amount', cellComponent: 'table/cell/currency', resizable: true },
            { label: this.intl.t('fleet-ops.ui.controller.connectivity-fuel-providers-details-transactions.fuel-report'), valuePath: 'fuel_report_id', cellComponent: 'click-to-copy', resizable: true },
            { label: this.intl.t('fleet-ops.ui.controller.connectivity-fuel-providers-details-transactions.date'), valuePath: 'transaction_at', resizable: true },
        ];
    }

    @action refresh() {
        return this.hostRouter.refresh();
    }
}
