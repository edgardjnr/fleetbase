import { inject as service } from '@ember/service';
import ResourceActionService from '@fleetbase/ember-core/services/resource-action';

export default class DeviceEventActionsService extends ResourceActionService {
    @service intl;
    @service fetch;
    @service notifications;

    constructor() {
        super(...arguments);
        this.initialize('device-event');
    }

    transition = {
        view: (deviceEvent) => this.transitionTo('connectivity.events.details', deviceEvent),
    };

    panel = {
        view: (deviceEvent) => {
            return this.resourceContextPanel.open({
                deviceEvent,
                tabs: [
                    {
                        label: this.intl.t('common.overview'),
                        component: 'device-event/details',
                    },
                ],
            });
        },
    };

    modal = {
        view: (deviceEvent, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: deviceEvent,
                title: deviceEvent.name,
                component: 'device-event/details',
                ...options,
            });
        },
    };

    async markProcessed(deviceEvent) {
        const response = await this.fetch.post(`device-events/${deviceEvent.id}/mark-processed`);
        // The API message is English; show the translated text for its two known variants.
        const alreadyProcessed = response?.message === 'Event was already processed.';
        this.notifications.success(this.intl.t(alreadyProcessed ? 'fleet-ops.ui.api-messages.event-already-processed' : 'fleet-ops.ui.service.device-event-actions.event-marked-processed'));

        return response;
    }
}
