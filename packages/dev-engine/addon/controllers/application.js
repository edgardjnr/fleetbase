import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class ApplicationController extends Controller {
    @service intl;
    @service abilities;
    @service fetch;

    get navigationItems() {
        return [
            {
                label: this.intl.t('developers.application.sidebar.items.home'),
                description: this.intl.t('developers.ui.nav-home-description'),
                icon: 'home',
                route: 'console.developers.home',
                keywords: ['dashboard', 'overview', 'metrics'],
            },
            {
                label: this.intl.t('developers.application.sidebar.items.api-keys'),
                description: this.intl.t('developers.ui.nav-api-keys-description'),
                icon: 'key',
                route: 'console.developers.api-keys',
                permission: 'developers list api-key',
                visible: this.can('developers see api-key'),
                keywords: ['credentials', 'access keys', 'sandbox', 'live keys'],
            },
            {
                label: this.intl.t('developers.application.sidebar.items.webhooks'),
                description: this.intl.t('developers.ui.nav-webhooks-description'),
                icon: 'globe-asia',
                route: 'console.developers.webhooks',
                permission: 'developers list webhook',
                visible: this.can('developers see webhook'),
                keywords: ['endpoints', 'callbacks', 'notifications'],
            },
            {
                label: this.intl.t('developers.application.sidebar.items.websockets'),
                description: this.intl.t('developers.ui.nav-websockets-description'),
                icon: 'plug',
                route: 'console.developers.sockets',
                permission: 'developers list socket',
                visible: this.can('developers see socket'),
                keywords: ['sockets', 'channels', 'realtime'],
            },
            {
                label: this.intl.t('developers.application.sidebar.items.logs'),
                description: this.intl.t('developers.ui.nav-logs-description'),
                icon: 'file-lines',
                route: 'console.developers.logs',
                permission: 'developers list log',
                visible: this.can('developers see log'),
                keywords: ['requests', 'traffic', 'debugging'],
            },
            {
                label: this.intl.t('developers.application.sidebar.items.events'),
                description: this.intl.t('developers.ui.nav-events-description'),
                icon: 'calendar-day',
                route: 'console.developers.events',
                permission: 'developers list event',
                visible: this.can('developers see event'),
                keywords: ['event stream', 'activity', 'webhook events'],
            },
        ];
    }

    can(permission) {
        try {
            return this.abilities.can(permission);
        } catch (_) {
            return true;
        }
    }

    @action
    async searchNavigation({ query, limit = 12 }) {
        const trimmedQuery = query?.trim();

        if (!trimmedQuery) {
            return [];
        }

        try {
            const response = await this.fetch.get('developers/search', {
                query: trimmedQuery,
                limit,
            });

            return response.results ?? [];
        } catch (_) {
            return [];
        }
    }
}
