import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';
import buildDeviceTableColumns from '../../../../utils/device-table-columns';
import fleetOpsOptions from '../../../../utils/fleet-ops-options';

export default class ConnectivityTelematicsDetailsDevicesController extends Controller {
    @service deviceActions;
    @service fetch;
    @service hostRouter;
    @service intl;
    @service mapManager;
    @service modalsManager;
    @service notifications;
    @service store;
    @service vehicleActions;
    @service trailerActions;

    @tracked queryParams = [
        'page',
        'limit',
        'sort',
        'query',
        'status',
        'provider',
        'attachment_state',
        'vehicle',
        'connection_status',
        'device_id',
        'type',
        'serial_number',
        'last_online_at',
        'updated_at',
    ];
    @tracked telematic;
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-updated_at';
    @tracked query;
    @tracked status;
    @tracked provider;
    @tracked attachment_state;
    @tracked vehicle;
    @tracked connection_status;
    @tracked device_id;
    @tracked type;
    @tracked serial_number;
    @tracked last_online_at;
    @tracked updated_at;

    get devices() {
        return Array.from(this.model ?? []);
    }

    get totalDevices() {
        return this.model?.meta?.total ?? this.devices.length;
    }

    get onlineDevicesCount() {
        return this.devices.filter((device) => device.is_online || device.connection_status === 'online' || device.status === 'online' || device.status === 'active').length;
    }

    get unattachedDevicesCount() {
        return this.devices.filter((device) => !device.attachable_uuid).length;
    }

    get offlineDevicesCount() {
        return Math.max(this.devices.length - this.onlineDevicesCount, 0);
    }

    get hasActiveFilters() {
        return Boolean(
            this.query ||
            this.status ||
            this.provider ||
            this.attachment_state ||
            this.vehicle ||
            this.connection_status ||
            this.device_id ||
            this.type ||
            this.serial_number ||
            this.last_online_at ||
            this.updated_at
        );
    }

    get hasDevices() {
        return this.devices.length > 0;
    }

    get isSyncing() {
        return this.telematic?.status === 'synchronizing' || this.telematic?.meta?.last_sync_result === 'queued';
    }

    get hasSyncRun() {
        const meta = this.telematic?.meta ?? {};

        return Boolean(meta.last_sync_started_at || meta.last_sync_completed_at || meta.last_sync_job_id || meta.last_sync_result || meta.last_sync_total);
    }

    get emptyStateVariant() {
        if (this.hasDevices) {
            return null;
        }

        if (this.hasActiveFilters) {
            return 'filtered_empty';
        }

        if (this.isSyncing) {
            return 'syncing';
        }

        if (!this.hasSyncRun) {
            return 'not_synced';
        }

        return 'empty';
    }

    get emptyStateContent() {
        switch (this.emptyStateVariant) {
            case 'filtered_empty':
                return {
                    tone: 'info',
                    icon: 'filter',
                    title: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-devices.no-devices-match-these-filters'),
                    message: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-devices.clear-the-current-search-and-filters'),
                    primaryText: 'Clear filters',
                    primaryIcon: 'filter',
                    primaryAction: this.clearFilters,
                };
            case 'syncing':
                return {
                    tone: 'info',
                    icon: 'rotate',
                    title: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-devices.device-sync-is-running'),
                    message: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-devices.devices-are-being-fetched-from-the'),
                    primaryText: 'Refresh',
                    primaryIcon: 'refresh',
                    primaryAction: this.refresh,
                };
            case 'not_synced':
                return {
                    tone: 'warning',
                    icon: 'satellite-dish',
                    title: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-devices.no-device-sync-has-run-yet'),
                    message: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-devices.sync-devices-from-this-provider-connection'),
                    primaryText: 'Sync Devices',
                    primaryIcon: 'satellite-dish',
                    primaryAction: this.startDeviceSync,
                    secondaryText: 'Test Connection',
                    secondaryIcon: 'plug',
                    secondaryAction: this.openConnectionTestDialog,
                };
            case 'empty':
                return {
                    tone: 'info',
                    icon: 'microchip',
                    title: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-devices.provider-returned-no-devices'),
                    message: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-devices.the-connection-synced-successfully-but-the'),
                    primaryText: 'Sync Again',
                    primaryIcon: 'refresh',
                    primaryAction: this.startDeviceSync,
                    secondaryText: 'Test Connection',
                    secondaryIcon: 'plug',
                    secondaryAction: this.openConnectionTestDialog,
                    note: 'Check provider permissions and account scope if devices exist in the provider dashboard.',
                };
            default:
                return null;
        }
    }

    get metrics() {
        return [
            { label: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-devices.synced-devices'), value: this.totalDevices, icon: 'microchip', accentClass: 'fleetops-connectivity-kpi-accent-blue' },
            { label: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-devices.online'), value: this.onlineDevicesCount, icon: 'signal', accentClass: 'fleetops-connectivity-kpi-accent-green' },
            { label: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-devices.unattached'), value: this.unattachedDevicesCount, icon: 'link-slash', accentClass: 'fleetops-connectivity-kpi-accent-amber' },
            { label: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-devices.offline-unknown'), value: this.offlineDevicesCount, icon: 'power-off', accentClass: 'fleetops-connectivity-kpi-accent-rose' },
        ];
    }

    get actionButtons() {
        return [
            {
                icon: 'refresh',
                size: 'sm',
                onClick: this.refresh,
                helpText: this.intl.t('common.refresh'),
                wrapperClass: 'fleetops-telematics-action-button',
                isLoading: this.refreshTask.isRunning,
                disabled: this.refreshTask.isRunning,
            },
            {
                icon: 'ellipsis-h',
                prefix: 'fas',
                helpText: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-devices.actions'),
                type: 'primary',
                size: 'sm',
                triggerClass: 'fleetops-telematics-action-button',
                items: [
                    {
                        icon: 'satellite-dish',
                        text: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-devices.sync-devices'),
                        onClick: this.startDeviceSync,
                    },
                    {
                        icon: 'link',
                        text: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-devices.attach-unassigned'),
                        onClick: this.openAttachmentsForUnassigned,
                    },
                ],
            },
        ];
    }

    @tracked bulkActions = [];

    get deviceTypeOptions() {
        return fleetOpsOptions('deviceTypes', this.intl);
    }

    get deviceStatusOptions() {
        return fleetOpsOptions('deviceStatuses', this.intl);
    }

    get columns() {
        return buildDeviceTableColumns(this, { showProvider: false, deviceActionMode: 'panel', showDeviceStatus: false });
    }

    @action refresh() {
        if (this.refreshTask.isRunning) {
            return;
        }

        return this.refreshTask.perform();
    }

    @action clearFilters() {
        this.query = null;
        this.status = null;
        this.provider = null;
        this.attachment_state = null;
        this.vehicle = null;
        this.connection_status = null;
        this.device_id = null;
        this.type = null;
        this.serial_number = null;
        this.last_online_at = null;
        this.updated_at = null;
        this.page = 1;
    }

    @action openConnectionTestDialog() {
        this.modalsManager.show('modals/telematic-connection-diagnostics', {
            title: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-devices.test-connection'),
            acceptButtonText: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-devices.run-test'),
            acceptButtonIcon: 'plug',
            declineButtonText: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-devices.close'),
            telematic: this.telematic,
            onTested: () => this.hostRouter.refresh(),
        });
    }

    @action openAttachmentsForUnassigned() {
        return this.hostRouter.transitionTo('console.fleet-ops.connectivity.telematics.details.attachments', this.telematic, {
            queryParams: { attachment_state: 'unattached' },
        });
    }

    @action hasAttachedVehicle(device) {
        return Boolean(device?.attachable_uuid && this.isVehicleAttachment(device));
    }

    @action async viewAttachedVehicle(device) {
        const vehicle = await this.resolveAttachedVehicle(device);

        if (!vehicle) {
            return;
        }

        return this.vehicleActions.panel.view(vehicle);
    }

    @action async locateAttachedVehicle(device) {
        const vehicle = await this.resolveAttachedVehicle(device);

        if (!vehicle) {
            return;
        }

        await this.transitionToLiveMap();
        await this.mapManager.waitForMap({ timeoutMs: 8000 });

        this.mapManager.focusResource(vehicle, 16, {
            paddingBottomRight: [300, 200],
            moveend: () => {
                this.vehicleActions.panel.view(vehicle, { closeOnTransition: true });
            },
        });
    }

    async transitionToLiveMap() {
        try {
            await this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index', { queryParams: { layout: 'map' } });
        } catch (_) {
            // Keep locate actions usable even if the current transition is already in-flight.
        }
    }

    isVehicleAttachment(device) {
        const attachableType = `${device?.attachable_type ?? ''}`.toLowerCase();

        return !attachableType || attachableType.includes('vehicle');
    }

    async resolveAttachedVehicle(device) {
        if (!device?.attachable_uuid) {
            return null;
        }

        if (!this.isVehicleAttachment(device)) {
            return null;
        }

        const attachable = device.attachable;

        if (attachable && typeof attachable.then !== 'function') {
            return attachable;
        }

        if (attachable && typeof attachable.then === 'function') {
            return await attachable;
        }

        const cachedVehicle = this.store.peekRecord('vehicle', device.attachable_uuid);

        if (cachedVehicle) {
            return cachedVehicle;
        }

        try {
            return await this.store.findRecord('vehicle', device.attachable_uuid);
        } catch (error) {
            this.notifications.serverError(error);
        }

        return null;
    }

    @action openDeviceEvents(device) {
        return this.hostRouter.transitionTo('console.fleet-ops.connectivity.telematics.details.events', this.telematic, {
            queryParams: { device_uuid: device.id },
        });
    }

    @action openAttachDeviceModal(device) {
        this.modalsManager.show('modals/attach-telematic-device', {
            title: this.intl.t('device.prompts.attach-device-to-vehicle-title', { deviceName: device.displayName ?? device.name ?? device.device_id ?? this.intl.t('resource.device') }),
            acceptButtonText: this.intl.t('device.actions.attach-to-vehicle'),
            device,
            selectedVehicle: null,
            confirm: async (modal) => {
                const selectedVehicle = modal.getOption('selectedVehicle');
                if (!selectedVehicle) {
                    return;
                }

                device.setProperties({
                    attachable_uuid: selectedVehicle.id,
                    attachable_type: 'fleet-ops:vehicle',
                });

                modal.startLoading();

                try {
                    await device.save();
                    this.notifications.success(this.intl.t('device.prompts.attach-to-vehicle-success'));
                    await this.hostRouter.refresh();
                    modal.done();
                } catch (error) {
                    device.rollbackAttributes();
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    @action startDeviceSync() {
        return this.syncDevices.perform();
    }

    @task *refreshTask() {
        yield this.hostRouter.refresh();
    }

    @task *syncDevices() {
        try {
            yield this.fetch.post(`telematics/${this.telematic.id}/discover`);
            this.notifications.success(this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-devices.device-sync-queued'));
            yield this.hostRouter.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
