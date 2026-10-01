import { inject as service } from '@ember/service';
import Component from '@glimmer/component';

export default class TelematicDetailsComponent extends Component {
    @service intl;
    get webhookUrl() {
        const url = this.args.resource?.provider_descriptor?.webhook_url;
        const id = this.args.resource?.public_id;

        if (!url || !id) {
            return null;
        }

        const separator = url.includes('?') ? '&' : '?';
        return `${url}${separator}telematic=${id}`;
    }

    get hasWebhookUrl() {
        return Boolean(this.webhookUrl);
    }

    get lastTestStatus() {
        const result = this.args.resource?.meta?.last_test_result;

        if (result === 'success') {
            return {
                status: 'success',
                label: this.intl.t('fleet-ops.ui.telematic.details.verified'),
            };
        }

        if (result === 'failed') {
            return {
                status: 'danger',
                label: this.intl.t('fleet-ops.ui.telematic.details.failed'),
            };
        }

        return null;
    }

    get lastSyncStatus() {
        if (this.args.resource?.status === 'synchronizing') {
            return {
                status: 'info',
                label: this.intl.t('fleet-ops.ui.telematic.details.syncing'),
            };
        }

        const result = this.args.resource?.meta?.last_sync_result;

        if (result === 'success') {
            return {
                status: 'success',
                label: this.intl.t('fleet-ops.ui.telematic.details.synced'),
            };
        }

        if (result === 'failed') {
            return {
                status: 'danger',
                label: this.intl.t('fleet-ops.ui.telematic.details.failed'),
            };
        }

        return null;
    }

    get connectionTestValue() {
        return this.lastTestStatus?.label ?? this.intl.t('fleet-ops.ui.telematic.details.not-tested');
    }

    get deviceSyncValue() {
        if (this.args.resource?.status === 'synchronizing') {
            return this.intl.t('fleet-ops.ui.telematic.details.syncing-provider-devices');
        }

        if (this.args.resource?.meta?.last_sync_result === 'success') {
            return this.intl.t('fleet-ops.ui.telematic.details.synced');
        }

        if (this.args.resource?.meta?.last_sync_result === 'failed') {
            return this.intl.t('fleet-ops.ui.telematic.details.failed');
        }

        return this.intl.t('fleet-ops.ui.telematic.details.not-synced');
    }

    get deviceSyncDetail() {
        if (this.args.resource?.status === 'synchronizing') {
            return this.args.resource?.meta?.last_sync_started_at;
        }

        return this.args.resource?.meta?.last_sync_completed_at;
    }

    get connectionTestAccentClass() {
        if (this.lastTestStatus?.status === 'danger') {
            return 'fleetops-connectivity-kpi-accent-rose';
        }

        if (this.lastTestStatus?.status === 'success') {
            return 'fleetops-connectivity-kpi-accent-green';
        }

        return 'fleetops-connectivity-kpi-accent-blue';
    }

    get deviceSyncAccentClass() {
        if (this.lastSyncStatus?.status === 'danger') {
            return 'fleetops-connectivity-kpi-accent-rose';
        }

        if (this.lastSyncStatus?.status === 'success') {
            return 'fleetops-connectivity-kpi-accent-green';
        }

        if (this.args.resource?.status === 'synchronizing') {
            return 'fleetops-connectivity-kpi-accent-blue';
        }

        return 'fleetops-connectivity-kpi-accent-amber';
    }

    get devicesSyncedAccentClass() {
        return this.args.resource?.meta?.last_sync_total ? 'fleetops-connectivity-kpi-accent-green' : 'fleetops-connectivity-kpi-accent-blue';
    }

    get healthCards() {
        const resource = this.args.resource;

        return [
            {
                icon: 'plug',
                label: this.intl.t('fleet-ops.ui.telematic.details.connection-test'),
                value: this.connectionTestValue,
                help: this.intl.t('fleet-ops.ui.telematic.details.provider-credentials'),
                detailLabel: this.intl.t('fleet-ops.ui.telematic.details.last-test'),
                detail: resource?.meta?.last_connection_test,
                detailIsDate: true,
                status: this.lastTestStatus?.status,
                statusLabel: this.lastTestStatus?.label,
                accentClass: this.connectionTestAccentClass,
            },
            {
                icon: 'satellite-dish',
                label: this.intl.t('fleet-ops.ui.telematic.details.device-sync'),
                value: this.deviceSyncValue,
                help: this.intl.t('fleet-ops.ui.telematic.details.provider-device-discovery'),
                detailLabel: this.args.resource?.status === 'synchronizing' ? 'Started' : 'Last sync',
                detail: this.deviceSyncDetail,
                detailIsDate: true,
                status: this.lastSyncStatus?.status,
                statusLabel: this.lastSyncStatus?.label,
                accentClass: this.deviceSyncAccentClass,
            },
            {
                icon: 'microchip',
                label: this.intl.t('fleet-ops.ui.telematic.details.devices-synced'),
                value: resource?.meta?.last_sync_total ?? 0,
                help: this.intl.t('fleet-ops.ui.telematic.details.devices-from-provider'),
                detailLabel: this.intl.t('fleet-ops.ui.telematic.details.sync-job'),
                detail: resource?.meta?.last_sync_job_id,
                detailIsDate: false,
                status: resource?.meta?.last_sync_total ? 'success' : null,
                statusLabel: resource?.meta?.last_sync_total ? 'Available' : null,
                accentClass: this.devicesSyncedAccentClass,
            },
        ];
    }

    get attentionItems() {
        const resource = this.args.resource;
        const items = [];

        if (resource?.meta?.last_error) {
            items.push({
                icon: 'triangle-exclamation',
                title: this.intl.t('fleet-ops.ui.telematic.details.connection-issue'),
                description: this.userFacingIssueMessage(resource.meta.last_error, 'Connection test failed. Review the provider credentials and try again.'),
                status: 'warning',
            });
        }

        if (resource?.meta?.last_sync_error) {
            items.push({
                icon: 'circle-exclamation',
                title: this.intl.t('fleet-ops.ui.telematic.details.sync-issue'),
                description: this.userFacingIssueMessage(resource.meta.last_sync_error, 'Device sync failed. Review the provider connection and server logs, then try again.'),
                status: 'warning',
            });
        }

        if (resource?.meta?.unattached_devices_count > 0) {
            items.push({
                icon: 'truck',
                title: this.intl.t('fleet-ops.ui.telematic.details.devices-need-vehicles'),
                description: this.intl.t('fleet-ops.ui.telematic.details.synced-devices-are-waiting-to-be', { unattached_devices_count: resource.meta.unattached_devices_count }),
                status: 'warning',
            });
        }

        return items;
    }

    userFacingIssueMessage(message, fallback) {
        if (!message || this.isSensitiveIssueMessage(message)) {
            return fallback;
        }

        return String(message);
    }

    isSensitiveIssueMessage(message) {
        const value = String(message).toLowerCase();

        return ['sqlstate', 'insert into', 'update `', 'select ', 'schema', 'stack trace', 'connection:', 'pdoexception'].some((fragment) => value.includes(fragment));
    }

    get hardwareFields() {
        const resource = this.args.resource;

        return [
            { label: this.intl.t('fleet-ops.ui.telematic.details.model'), value: resource?.model },
            { label: this.intl.t('fleet-ops.ui.telematic.details.serial-number'), value: resource?.serial_number },
            { label: this.intl.t('fleet-ops.ui.telematic.details.firmware-version'), value: resource?.firmware_version },
            { label: 'IMEI', value: resource?.imei },
            { label: 'ICCID', value: resource?.iccid },
            { label: 'IMSI', value: resource?.imsi },
            { label: 'MSISDN', value: resource?.msisdn },
            { label: this.intl.t('fleet-ops.ui.telematic.details.signal-strength'), value: resource?.signal_strength },
        ];
    }
}
