import { inject as service } from '@ember/service';
import Component from '@glimmer/component';

export default class CellTelematicStatusComponent extends Component {
    @service intl;
    get status() {
        return this.args.row?.status;
    }

    get badgeStatus() {
        switch (this.status) {
            case 'active':
            case 'connected':
                return 'success';
            case 'synchronizing':
                return 'info';
            case 'error':
            case 'degraded':
            case 'disconnected':
                return 'warning';
            default:
                return this.status ?? 'default';
        }
    }

    get label() {
        switch (this.status) {
            case 'active':
            case 'connected':
                return this.intl.t('fleet-ops.ui.cell.telematic-status.connected');
            case 'synchronizing':
                return this.intl.t('fleet-ops.ui.cell.telematic-status.syncing');
            case 'initialized':
                return this.intl.t('fleet-ops.ui.cell.telematic-status.not-tested');
            case 'error':
                return this.intl.t('fleet-ops.ui.cell.telematic-status.needs-attention');
            default:
                return this.status ?? this.intl.t('fleet-ops.ui.cell.telematic-status.unknown');
        }
    }
}
