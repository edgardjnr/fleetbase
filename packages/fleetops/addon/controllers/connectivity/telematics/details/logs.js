import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class ConnectivityTelematicsDetailsLogsController extends Controller {
    @service intl;
    @service hostRouter;

    @tracked telematic;

    get logs() {
        return Array.from(this.model?.logs ?? []);
    }

    get hasLogs() {
        return this.logs.length > 0;
    }

    get warningCount() {
        return this.logs.filter((log) => ['warning', 'danger', 'error'].includes(String(log.status ?? '').toLowerCase())).length;
    }

    get syncCount() {
        return this.logs.filter((log) => String(log.type ?? '').startsWith('sync')).length;
    }

    get testCount() {
        return this.logs.filter((log) => String(log.type ?? '').startsWith('connection_test')).length;
    }

    get metrics() {
        return [
            { label: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-logs.log-entries'), value: this.logs.length, icon: 'list', accentClass: 'fleetops-connectivity-kpi-accent-blue' },
            { label: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-logs.warnings'), value: this.warningCount, icon: 'triangle-exclamation', accentClass: 'fleetops-connectivity-kpi-accent-amber' },
            { label: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-logs.sync-records'), value: this.syncCount, icon: 'satellite-dish', accentClass: 'fleetops-connectivity-kpi-accent-green' },
            { label: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-logs.connection-tests'), value: this.testCount, icon: 'plug', accentClass: 'fleetops-connectivity-kpi-accent-rose' },
        ];
    }

    @action refresh() {
        return this.hostRouter.refresh();
    }
}
