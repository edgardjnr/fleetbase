import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';
import fleetOpsOptions from '../../../../utils/fleet-ops-options';

export default class ConnectivityTelematicsDetailsSensorsController extends Controller {
    @service sensorActions;
    @service deviceActions;
    @service hostRouter;
    @service intl;

    @tracked queryParams = ['page', 'limit', 'sort', 'query', 'status', 'type', 'device_uuid', 'last_reading_at'];
    @tracked telematic;
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-updated_at';
    @tracked query;
    @tracked status;
    @tracked type;
    @tracked device_uuid;
    @tracked last_reading_at;

    get sensors() {
        return Array.from(this.model ?? []);
    }

    get activeSensorsCount() {
        return this.sensors.filter((sensor) => sensor.is_active || sensor.status === 'active' || sensor.status === 'online').length;
    }

    get sensorsWithReadingsCount() {
        return this.sensors.filter((sensor) => sensor.last_value || sensor.last_reading_at).length;
    }

    get deviceCount() {
        return new Set(this.sensors.map((sensor) => sensor.device_uuid).filter(Boolean)).size;
    }

    get hasActiveFilters() {
        return Boolean(this.query || this.status || this.type || this.device_uuid || this.last_reading_at);
    }

    get hasSensors() {
        return this.sensors.length > 0;
    }

    get hasSyncedDevices() {
        const meta = this.telematic?.meta ?? {};

        return Number(meta.last_sync_total ?? meta.device_count ?? 0) > 0;
    }

    get hasSyncRun() {
        const meta = this.telematic?.meta ?? {};

        return Boolean(meta.last_sync_started_at || meta.last_sync_completed_at || meta.last_sync_job_id || meta.last_sync_result || meta.last_sync_total);
    }

    get emptyStateVariant() {
        if (this.hasSensors) {
            return null;
        }

        if (this.hasActiveFilters) {
            return 'filtered_empty';
        }

        if (!this.hasSyncedDevices && !this.hasSyncRun) {
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
                    title: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-sensors.no-sensors-match-these-filters'),
                    message: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-sensors.clear-the-current-search-and-filters'),
                    primaryText: 'Clear filters',
                    primaryIcon: 'filter',
                    primaryAction: this.clearFilters,
                };
            case 'not_synced':
                return {
                    tone: 'warning',
                    icon: 'satellite-dish',
                    title: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-sensors.sync-devices-before-reviewing-sensors'),
                    message: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-sensors.sensor-inventory-depends-on-synced-devices'),
                    primaryText: 'Go to Devices',
                    primaryIcon: 'microchip',
                    primaryAction: this.goToDevices,
                };
            case 'empty':
                return {
                    tone: 'info',
                    icon: 'gauge-high',
                    title: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-sensors.no-sensors-reported-yet'),
                    message: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-sensors.devices-are-synced-but-this-provider'),
                    primaryText: 'Refresh',
                    primaryIcon: 'refresh',
                    primaryAction: this.refresh,
                    secondaryText: 'View Devices',
                    secondaryIcon: 'microchip',
                    secondaryAction: this.goToDevices,
                    note: 'Some providers expose sensors only after live telemetry, fuel, or diagnostic events are ingested.',
                };
            default:
                return null;
        }
    }

    get metrics() {
        return [
            { label: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-sensors.sensors'), value: this.model?.meta?.total ?? this.sensors.length, icon: 'gauge-high', accentClass: 'fleetops-connectivity-kpi-accent-blue' },
            { label: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-sensors.active'), value: this.activeSensorsCount, icon: 'signal', accentClass: 'fleetops-connectivity-kpi-accent-green' },
            { label: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-sensors.reporting-values'), value: this.sensorsWithReadingsCount, icon: 'chart-line', accentClass: 'fleetops-connectivity-kpi-accent-amber' },
            { label: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-sensors.parent-devices'), value: this.deviceCount, icon: 'microchip', accentClass: 'fleetops-connectivity-kpi-accent-blue' },
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
                icon: 'microchip',
                text: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-sensors.view-devices'),
                size: 'sm',
                onClick: this.goToDevices,
                wrapperClass: 'fleetops-telematics-action-button',
            },
        ];
    }

    @tracked bulkActions = [];

    get columns() {
        return [
            {
                sticky: true,
                label: this.intl.t('column.name'),
                valuePath: 'name',
                cellComponent: 'table/cell/anchor',
                action: this.sensorActions.transition.view,
                permission: 'fleet-ops view sensor',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'query',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-sensors.type'),
                valuePath: 'type',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'type',
                filterComponent: 'filter/multi-option',
                filterOptions: fleetOpsOptions('sensorTypes', this.intl),
                filterOptionLabel: 'label',
                filterOptionValue: 'value',
            },
            {
                label: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-sensors.device'),
                valuePath: 'device.displayName',
                cellComponent: 'table/cell/anchor',
                action: this.deviceActions.transition.view,
                permission: 'fleet-ops view device',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/model',
                filterComponentPlaceholder: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-sensors.select-device'),
                filterParam: 'device_uuid',
                model: 'device',
                modelNamePath: 'displayName',
                query: { telematic_uuid: this.telematic?.id },
            },
            {
                label: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-sensors.value'),
                valuePath: 'last_value',
                resizable: true,
                sortable: true,
            },
            {
                label: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-sensors.unit'),
                valuePath: 'unit',
                resizable: true,
                sortable: true,
            },
            {
                label: this.intl.t('column.status'),
                valuePath: 'status',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/multi-option',
                filterOptions: fleetOpsOptions('sensorStatuses', this.intl),
                filterOptionLabel: 'label',
                filterOptionValue: 'value',
            },
            {
                label: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-sensors.last-reading'),
                valuePath: 'lastReadingAt',
                sortParam: 'last_reading_at',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'last_reading_at',
                filterComponent: 'filter/date',
            },
            {
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.sensor') }),
                cellClassNames: 'overflow-visible align-middle',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                actions: [
                    {
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.sensor') }),
                        fn: this.sensorActions.transition.view,
                        permission: 'fleet-ops view sensor',
                    },
                    {
                        label: this.intl.t('fleet-ops.ui.controller.connectivity-telematics-details-sensors.open-parent-device'),
                        fn: this.openParentDevice,
                        permission: 'fleet-ops view device',
                    },
                ],
                sortable: false,
                filterable: false,
                resizable: false,
                searchable: false,
            },
        ];
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
        this.type = null;
        this.device_uuid = null;
        this.last_reading_at = null;
        this.page = 1;
    }

    @action goToDevices() {
        return this.hostRouter.transitionTo('console.fleet-ops.connectivity.telematics.details.devices', this.telematic);
    }

    @action openParentDevice(sensor) {
        const device = sensor.device;

        if (device?.id) {
            return this.deviceActions.transition.view(device);
        }

        return this.hostRouter.transitionTo('console.fleet-ops.connectivity.telematics.details.devices', this.telematic, {
            queryParams: { device_id: sensor.device_uuid },
        });
    }

    @task *refreshTask() {
        yield this.hostRouter.refresh();
    }
}
