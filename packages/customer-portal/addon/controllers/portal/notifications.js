import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

const DEFAULT_PREFERENCES = {
    order_created: true,
    order_dispatched: true,
    order_delayed: true,
    driver_nearby: true,
    order_delivered: true,
    invoice_due: true,
    invoice_paid: true,
    ticket_updated: true,
    document_available: true,
};

const PREFERENCE_GROUPS = [
    {
        key: 'orders',
        keys: ['order_created', 'order_dispatched', 'order_delayed', 'driver_nearby', 'order_delivered'],
    },
    {
        key: 'billing',
        keys: ['invoice_due', 'invoice_paid'],
    },
    {
        key: 'support',
        keys: ['ticket_updated'],
    },
    {
        key: 'documents',
        keys: ['document_available'],
    },
];

export default class PortalNotificationsController extends Controller {
    @service fetch;
    @service notifications;
    @service intl;

    @tracked preferences = { ...DEFAULT_PREFERENCES };

    constructor() {
        super(...arguments);
        this.preferences = { ...DEFAULT_PREFERENCES, ...(this.model?.preferences ?? {}) };
    }

    get preferenceGroups() {
        return PREFERENCE_GROUPS.map((group) => {
            return {
                ...group,
                title: this.intl.t(`customer-portal.ui.notifications.groups.${group.key}.title`),
                description: this.intl.t(`customer-portal.ui.notifications.groups.${group.key}.description`),
                items: group.keys.map((key) => {
                    return {
                        key,
                        label: this.preferenceLabel(key),
                        enabled: this.preferences[key],
                    };
                }),
            };
        });
    }

    preferenceLabel(key) {
        const translationKey = `customer-portal.ui.notifications.preferences.${key}`;
        if (this.intl.exists(translationKey)) {
            return this.intl.t(translationKey);
        }

        return key
            .split('_')
            .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
            .join(' ');
    }

    @action setPreference(key, event) {
        this.preferences = { ...this.preferences, [key]: event.target.checked };
    }

    @task *savePreferences() {
        try {
            const response = yield this.fetch.post('notification-preferences', { preferences: this.preferences }, { namespace: 'customer-portal/int/v1' });
            this.preferences = response.preferences ?? this.preferences;
            this.notifications.success(this.intl.t('customer-portal.ui.notifications.saved'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
