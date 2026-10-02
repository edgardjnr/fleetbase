import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import isEntregasHiddenRoute from '../../utils/entregas-hidden-routes';

export default class SettingsIndexController extends Controller {
    @service intl;
    @service docsPanel;

    groups = [
        {
            title: this.intl.t('fleet-ops.ui.controller.settings-index.driver-and-map-experience'),
            description: this.intl.t('fleet-ops.ui.controller.settings-index.tune-what-dispatchers-and-drivers-see'),
            links: [
                { label: this.intl.t('fleet-ops.ui.controller.settings-index.navigator-app'), route: 'settings.navigator-app', icon: 'location-arrow', description: this.intl.t('fleet-ops.ui.controller.settings-index.driver-app-behavior-and-workflow-defaults') },
                { label: this.intl.t('fleet-ops.ui.controller.settings-index.map'), route: 'settings.map', icon: 'map', description: this.intl.t('fleet-ops.ui.controller.settings-index.map-providers-defaults-and-display-behavior') },
            ],
        },
        {
            title: this.intl.t('fleet-ops.ui.controller.settings-index.dispatch-automation'),
            description: this.intl.t('fleet-ops.ui.controller.settings-index.shape-route-planning-assignment-and-scheduling'),
            links: [
                { label: this.intl.t('fleet-ops.ui.controller.settings-index.routing'), route: 'settings.routing', icon: 'route', description: this.intl.t('fleet-ops.ui.controller.settings-index.routing-engines-and-routing-defaults') },
                { label: this.intl.t('fleet-ops.ui.controller.settings-index.orchestrator'), route: 'settings.orchestrator', icon: 'circle-nodes', description: this.intl.t('fleet-ops.ui.controller.settings-index.dispatch-orchestration-and-assignment-behavior') },
                { label: this.intl.t('fleet-ops.ui.controller.settings-index.scheduling'), route: 'settings.scheduling', icon: 'calendar-days', description: this.intl.t('fleet-ops.ui.controller.settings-index.schedule-planning-and-operational-timing-rules') },
            ],
        },
        {
            title: this.intl.t('fleet-ops.ui.controller.settings-index.business-and-data'),
            description: this.intl.t('fleet-ops.ui.controller.settings-index.keep-commerce-metadata-and-visual-conventions'),
            links: [
                { label: this.intl.t('fleet-ops.ui.controller.settings-index.payments'), route: 'settings.payments', icon: 'cash-register', description: this.intl.t('fleet-ops.ui.controller.settings-index.payment-setup-for-operational-commerce-workflows') },
                { label: this.intl.t('fleet-ops.ui.controller.settings-index.custom-fields'), route: 'settings.custom-fields', icon: 'pen-to-square', description: this.intl.t('fleet-ops.ui.controller.settings-index.operational-metadata-fields-for-fleet-ops') },
                { label: this.intl.t('fleet-ops.ui.controller.settings-index.avatars'), route: 'settings.avatars', icon: 'icons', description: this.intl.t('fleet-ops.ui.controller.settings-index.visual-assets-for-driver-vehicle-and') },
            ],
        },
        {
            title: this.intl.t('fleet-ops.ui.controller.settings-index.communication'),
            description: this.intl.t('fleet-ops.ui.controller.settings-index.control-operational-alerts-and-notification-beha'),
            links: [{ label: this.intl.t('fleet-ops.ui.controller.settings-index.notifications'), route: 'settings.notifications', icon: 'bell', description: this.intl.t('fleet-ops.ui.controller.settings-index.operational-status-alerts-and-notification-rules') }],
        },
    ];

    actions = [
        {
            label: this.intl.t('fleet-ops.ui.controller.settings-index.start-with-driver-experience'),
            description: this.intl.t('fleet-ops.ui.controller.settings-index.navigator-app-and-map-settings-should'),
            icon: 'location-arrow',
            route: 'settings.navigator-app',
            tone: 'info',
        },
        {
            label: this.intl.t('fleet-ops.ui.controller.settings-index.review-automation-rules'),
            description: this.intl.t('fleet-ops.ui.controller.settings-index.routing-orchestration-and-scheduling-settings-af'),
            icon: 'route',
            route: 'settings.routing',
            tone: 'warning',
        },
        {
            label: this.intl.t('fleet-ops.ui.controller.settings-index.keep-forms-focused'),
            description: this.intl.t('fleet-ops.ui.controller.settings-index.add-custom-fields-after-core-workflows'),
            icon: 'pen-to-square',
            route: 'settings.custom-fields',
            tone: 'success',
        },
    ];

    docs = [
        {
            label: this.intl.t('fleet-ops.ui.controller.settings-index.navigator-app'),
            icon: 'location-arrow',
            slug: 'fleet-ops/settings/navigator-app',
            title: this.intl.t('fleet-ops.ui.controller.settings-index.navigator-app-settings'),
            description: this.intl.t('fleet-ops.ui.controller.settings-index.configure-driver-app-behavior-onboarding-and'),
        },
        {
            label: this.intl.t('fleet-ops.ui.controller.settings-index.map'),
            icon: 'map',
            slug: 'fleet-ops/settings/map',
            title: this.intl.t('fleet-ops.ui.controller.settings-index.map-settings'),
            description: this.intl.t('fleet-ops.ui.controller.settings-index.set-map-providers-display-defaults-and'),
        },
        {
            label: this.intl.t('fleet-ops.ui.controller.settings-index.payments'),
            icon: 'cash-register',
            slug: 'fleet-ops/settings/payments',
            title: this.intl.t('fleet-ops.ui.controller.settings-index.payments-guide'),
            description: this.intl.t('fleet-ops.ui.controller.settings-index.connect-payment-providers-and-payment-behavior'),
        },
        {
            label: this.intl.t('fleet-ops.ui.controller.settings-index.notifications'),
            icon: 'bell',
            slug: 'fleet-ops/settings/notifications',
            title: this.intl.t('fleet-ops.ui.controller.settings-index.notification-settings'),
            description: this.intl.t('fleet-ops.ui.controller.settings-index.tune-operational-alerts-sent-to-dispatchers'),
        },
        {
            label: this.intl.t('fleet-ops.ui.controller.settings-index.routing'),
            icon: 'route',
            slug: 'fleet-ops/settings/routing',
            title: this.intl.t('fleet-ops.ui.controller.settings-index.routing-settings'),
            description: this.intl.t('fleet-ops.ui.controller.settings-index.configure-routing-engines-tracking-defaults-and'),
        },
        {
            label: this.intl.t('fleet-ops.ui.controller.settings-index.orchestrator'),
            icon: 'circle-nodes',
            slug: 'fleet-ops/settings/orchestrator',
            title: this.intl.t('fleet-ops.ui.controller.settings-index.orchestrator-settings'),
            description: this.intl.t('fleet-ops.ui.controller.settings-index.select-dispatch-automation-engines-and-assignmen'),
        },
        {
            label: this.intl.t('fleet-ops.ui.controller.settings-index.scheduling'),
            icon: 'calendar-days',
            slug: 'fleet-ops/settings/scheduling',
            title: this.intl.t('fleet-ops.ui.controller.settings-index.scheduling-settings'),
            description: this.intl.t('fleet-ops.ui.controller.settings-index.manage-schedule-templates-and-timing-rules'),
        },
        {
            label: this.intl.t('fleet-ops.ui.controller.settings-index.custom-fields'),
            icon: 'pen-to-square',
            slug: 'fleet-ops/settings/custom-fields',
            title: this.intl.t('fleet-ops.ui.controller.settings-index.custom-field-settings'),
            description: this.intl.t('fleet-ops.ui.controller.settings-index.add-structured-operational-metadata-to-fleet'),
        },
        {
            label: this.intl.t('fleet-ops.ui.controller.settings-index.avatars'),
            icon: 'icons',
            slug: 'fleet-ops/settings/avatars',
            title: this.intl.t('fleet-ops.ui.controller.settings-index.avatar-settings'),
            description: this.intl.t('fleet-ops.ui.controller.settings-index.manage-visual-markers-used-across-maps'),
        },
    ];

    constructor() {
        super(...arguments);
        // Entregas RestaurantePro: sem cartões, ações e guias de telas ocultas
        const docRoute = (doc) => (doc.slug ?? '').replace(/^fleet-ops\//, '').replace(/\//g, '.');
        this.groups = this.groups.map((group) => ({ ...group, links: group.links.filter((link) => !isEntregasHiddenRoute(link.route)) })).filter((group) => group.links.length);
        this.actions = this.actions.filter((item) => !isEntregasHiddenRoute(item.route));
        this.docs = this.docs.filter((doc) => !isEntregasHiddenRoute(docRoute(doc)));
    }

    @action openDocs(link) {
        if (!link?.slug) {
            return;
        }

        return this.docsPanel.open(link.slug, {
            title: link.title ?? link.label,
            source: 'fleet-ops-settings-hub',
        });
    }
}
