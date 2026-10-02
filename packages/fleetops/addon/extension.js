import { MenuItem, Widget, ExtensionComponent } from '@fleetbase/ember-core/contracts';

export default {
    setupExtension(app, universe) {
        const menuService = universe.getService('menu');
        const registryService = universe.getService('registry');
        const widgetService = universe.getService('widget');
        const intlService = app.lookup('service:intl');

        // Register header navigation
        menuService.registerHeaderMenuItem('Fleet-Ops', 'console.fleet-ops', {
            icon: 'route',
            priority: 0,
            description: intlService.t('fleet-ops.ui.extension.header.description'),
            shortcuts: [
                {
                    id: 'fleet-ops-sc-orders',
                    title: intlService.t('fleet-ops.ui.extension.shortcut-orders.title'),
                    description: intlService.t('fleet-ops.ui.extension.shortcut-orders.description'),
                    icon: 'boxes-stacked',
                    route: 'console.fleet-ops.operations.orders',
                },
                {
                    id: 'fleet-ops-sc-places',
                    title: intlService.t('fleet-ops.ui.extension.shortcut-places.title'),
                    description: intlService.t('fleet-ops.ui.extension.shortcut-places.description'),
                    icon: 'location-dot',
                    route: 'console.fleet-ops.management.places',
                },
                {
                    id: 'fleet-ops-sc-drivers',
                    title: intlService.t('fleet-ops.ui.extension.shortcut-drivers.title'),
                    description: intlService.t('fleet-ops.ui.extension.shortcut-drivers.description'),
                    icon: 'id-card',
                    route: 'console.fleet-ops.management.drivers',
                },
                {
                    id: 'fleet-ops-sc-driver-payouts',
                    title: intlService.t('fleet-ops.ui.driver-payouts.title'),
                    description: intlService.t('fleet-ops.ui.driver-payouts.menu-description'),
                    icon: 'money-bill-wave',
                    route: 'console.fleet-ops.management.driver-payouts',
                },
            ],
        });

        // Register admin sections
        menuService.registerAdminMenuPanel(
            intlService.t('fleet-ops.ui.extension.admin.panel-title'),
            [
                new MenuItem({
                    id: 'routing',
                    slug: 'routing',
                    title: intlService.t('fleet-ops.ui.extension.admin.routing'),
                    icon: 'route',
                    component: new ExtensionComponent('@fleetbase/fleetops-engine', 'admin/routing-settings'),
                }),
                new MenuItem({
                    id: 'map',
                    slug: 'map',
                    title: intlService.t('fleet-ops.ui.extension.admin.map'),
                    icon: 'map',
                    component: new ExtensionComponent('@fleetbase/fleetops-engine', 'admin/map-settings'),
                }),
                new MenuItem({
                    id: 'navigator-app',
                    slug: 'navigator-app',
                    title: intlService.t('fleet-ops.ui.extension.admin.navigator-app'),
                    icon: 'location-arrow',
                    component: new ExtensionComponent('@fleetbase/fleetops-engine', 'admin/navigator-app'),
                }),
            ],
            {
                slug: 'fleet-ops',
            }
        );

        // Register track order button
        menuService.registerMenuItem(
            'auth:login',
            new MenuItem({
                id: 'track-order',
                view: 'track-order',
                title: intlService.t('fleet-ops.ui.extension.login.track-order'),
                route: 'virtual',
                slug: 'track-order',
                icon: 'barcode',
                type: 'link',
                wrapperClass: 'btn-block py-1 border dark:border-gray-700 border-gray-200 hover:opacity-50',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'order-tracking-lookup'),
                onClick: (menuItem) => {
                    universe.transitionMenuItem('virtual', menuItem);
                },
            })
        );

        // Register widgets
        this.registerWidgets(widgetService);

        // Create registries
        this.createRegistries(registryService);
        registryService.registerRenderableComponent('ai:action-preview:fleet-ops.create_order', new ExtensionComponent('@fleetbase/fleetops-engine', 'ai/create-order-preview'));

        // // Register console home guidance
        // this.registerHomeComponents(registryService);

        // Setup customer portal
        const isCustomerPortalInstalled = universe.extensionManager.isInstalled('@fleetbase/customer-portal-engine');
        if (isCustomerPortalInstalled) {
            universe.whenEngineLoaded('@fleetbase/customer-portal-engine', () => {
                universe.extensionManager.ensureEngineLoaded('@fleetbase/fleetops-engine');
            });
        }
    },

    registerWidgets(widgetService) {
        const widgets = [
            // Legacy monolithic 13-tile widget — kept registered for one release as
            // users have it pinned to existing dashboards. The new KPI tile widgets
            // below supersede it. Scheduled for removal in the next major.
            new Widget({
                id: 'fleet-ops-key-metrics-widget',
                name: 'fleet-ops.ui.widgets.key-metrics.name',
                description: 'fleet-ops.ui.widgets.key-metrics.description',
                icon: 'truck',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/fleet-ops-key-metrics'),
                grid_options: { w: 12, h: 8, minW: 8, minH: 6 },
                category: 'Legacy',
                default: false,
            }),

            // Small KPI tile widgets — S-tier, each wraps <Widget::KpiTile> with a
            // fixed slug pointing at GET /fleet-ops/metrics/{slug}.
            new Widget({
                id: 'fleet-ops-kpi-earnings-widget',
                name: 'fleet-ops.ui.widgets.kpi-earnings.name',
                description: 'fleet-ops.ui.widgets.kpi-earnings.description',
                icon: 'sack-dollar',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/kpi-earnings'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: 'KPI Tiles',
                default: true,
            }),
            new Widget({
                id: 'fleet-ops-kpi-aov-widget',
                name: 'fleet-ops.ui.widgets.kpi-aov.name',
                description: 'fleet-ops.ui.widgets.kpi-aov.description',
                icon: 'receipt',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/kpi-aov'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: 'KPI Tiles',
                default: true,
            }),
            new Widget({
                id: 'fleet-ops-kpi-distance-widget',
                name: 'fleet-ops.ui.widgets.kpi-distance.name',
                description: 'fleet-ops.ui.widgets.kpi-distance.description',
                icon: 'route',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/kpi-distance'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: 'KPI Tiles',
                default: false,
            }),
            new Widget({
                id: 'fleet-ops-kpi-active-orders-widget',
                name: 'fleet-ops.ui.widgets.kpi-active-orders.name',
                description: 'fleet-ops.ui.widgets.kpi-active-orders.description',
                icon: 'bolt',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/kpi-active-orders'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: 'KPI Tiles',
                default: true,
            }),
            new Widget({
                id: 'fleet-ops-kpi-drivers-online-widget',
                name: 'fleet-ops.ui.widgets.kpi-drivers-online.name',
                description: 'fleet-ops.ui.widgets.kpi-drivers-online.description',
                icon: 'id-card',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/kpi-drivers-online'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: 'KPI Tiles',
                default: true,
            }),
            new Widget({
                id: 'fleet-ops-kpi-open-issues-widget',
                name: 'fleet-ops.ui.widgets.kpi-open-issues.name',
                description: 'fleet-ops.ui.widgets.kpi-open-issues.description',
                icon: 'triangle-exclamation',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/kpi-open-issues'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: 'KPI Tiles',
                default: false,
            }),

            // Analytics widgets — composed visualizations powered by /fleet-ops/analytics/*.
            new Widget({
                id: 'fleet-ops-operations-pulse-widget',
                name: 'fleet-ops.ui.widgets.operations-pulse.name',
                description: 'fleet-ops.ui.widgets.operations-pulse.description',
                icon: 'wave-pulse',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/operations-pulse'),
                grid_options: { w: 6, h: 6, minW: 5, minH: 5 },
                category: 'Analytics',
                default: false,
            }),
            new Widget({
                id: 'fleet-ops-live-fleet-widget',
                name: 'fleet-ops.ui.widgets.live-fleet.name',
                description: 'fleet-ops.ui.widgets.live-fleet.description',
                icon: 'map-location-dot',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/live-fleet'),
                grid_options: { w: 8, h: 11, minW: 8, minH: 8 },
                category: 'Maps',
                default: true,
            }),
            new Widget({
                id: 'fleet-ops-revenue-trend-widget',
                name: 'fleet-ops.ui.widgets.revenue-trend.name',
                description: 'fleet-ops.ui.widgets.revenue-trend.description',
                icon: 'chart-line',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/revenue-trend'),
                grid_options: { w: 4, h: 11, minW: 4, minH: 8 },
                category: 'Analytics',
                default: true,
            }),
            new Widget({
                id: 'fleet-ops-orders-by-status-widget',
                name: 'fleet-ops.ui.widgets.orders-by-status.name',
                description: 'fleet-ops.ui.widgets.orders-by-status.description',
                icon: 'chart-column',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/orders-by-status'),
                grid_options: { w: 6, h: 6, minW: 5, minH: 5 },
                category: 'Analytics',
                default: false,
            }),
            new Widget({
                id: 'fleet-ops-on-time-delivery-widget',
                name: 'fleet-ops.ui.widgets.on-time-delivery.name',
                description: 'fleet-ops.ui.widgets.on-time-delivery.description',
                icon: 'clock',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/on-time-delivery'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: 'Analytics',
                default: false,
            }),
            new Widget({
                id: 'fleet-ops-top-drivers-widget',
                name: 'fleet-ops.ui.widgets.top-drivers.name',
                description: 'fleet-ops.ui.widgets.top-drivers.description',
                icon: 'medal',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/top-drivers'),
                grid_options: { w: 6, h: 6, minW: 5, minH: 5 },
                category: 'Analytics',
                default: true,
            }),
            new Widget({
                id: 'fleet-ops-fuel-efficiency-widget',
                name: 'fleet-ops.ui.widgets.fuel-efficiency.name',
                description: 'fleet-ops.ui.widgets.fuel-efficiency.description',
                icon: 'gas-pump',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/fuel-efficiency'),
                grid_options: { w: 6, h: 6, minW: 5, minH: 5 },
                category: 'Analytics',
                default: false,
            }),
            new Widget({
                id: 'fleet-ops-fuel-providers-widget',
                name: 'fleet-ops.ui.widgets.fuel-providers.name',
                description: 'fleet-ops.ui.widgets.fuel-providers.description',
                icon: 'gas-pump',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/fuel-providers'),
                grid_options: { w: 4, h: 5, minW: 4, minH: 5 },
                category: 'Analytics',
                default: false,
            }),
            new Widget({
                id: 'fleet-ops-issues-insights-widget',
                name: 'fleet-ops.ui.widgets.issues-insights.name',
                description: 'fleet-ops.ui.widgets.issues-insights.description',
                icon: 'triangle-exclamation',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/issues-insights'),
                grid_options: { w: 6, h: 6, minW: 5, minH: 5 },
                category: 'Analytics',
                default: false,
            }),
            new Widget({
                id: 'fleet-ops-maintenance-overview-widget',
                name: 'fleet-ops.ui.widgets.maintenance-overview.name',
                description: 'fleet-ops.ui.widgets.maintenance-overview.description',
                icon: 'wrench',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/maintenance-overview'),
                grid_options: { w: 6, h: 6, minW: 5, minH: 5 },
                category: 'Analytics',
                default: true,
            }),
            new Widget({
                id: 'fleet-ops-geofence-violations-widget',
                name: 'fleet-ops.ui.widgets.geofence-violations.name',
                description: 'fleet-ops.ui.widgets.geofence-violations.description',
                icon: 'location-crosshairs',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/geofence-violations'),
                grid_options: { w: 6, h: 6, minW: 5, minH: 5 },
                category: 'Analytics',
                default: false,
            }),
        ];

        const defaultWidgets = widgets
            .filter((widget) => (typeof widget.isDefault === 'function' ? widget.isDefault() : widget.default === true))
            .map((widget) => {
                const definition = typeof widget.toObject === 'function' ? widget.toObject() : widget;

                return {
                    ...definition,
                    widgetId: definition.widgetId ?? definition.id,
                };
            });

        widgetService.registerWidgets('dashboard', widgets);

        if (typeof widgetService.registerDashboard === 'function') {
            widgetService.registerDashboard('fleet-ops');
        }

        widgetService.registerWidgets('fleet-ops', widgets);

        if (typeof widgetService.registerDefaultWidgets === 'function') {
            widgetService.registerDefaultWidgets('fleet-ops', defaultWidgets);
        }
    },

    registerHomeComponents(registryService) {
        registryService.registerRenderableComponent('console:home:before-dashboard', new ExtensionComponent('@fleetbase/fleetops-engine', 'home/getting-started-guidance'));
    },

    createRegistries(registryService) {
        registryService.createRegistries([
            'engine:fleet-ops',
            'fleet-ops:component:map:drawer',
            'fleet-ops:component:vehicle:details',
            'fleet-ops:component:driver:details',
            'fleet-ops:component:order-config-manager',
            'fleet-ops:component:contact:form',
            'fleet-ops:component:contact:form:details',
            'fleet-ops:component:customer:form',
            'fleet-ops:component:customer:form:details',
            'fleet-ops:component:driver:form',
            'fleet-ops:component:driver:form:details',
            'fleet-ops:component:fleet:form',
            'fleet-ops:component:fleet:form:details',
            'fleet-ops:component:place:form',
            'fleet-ops:component:place:form:details',
            'fleet-ops:component:vehicle:form',
            'fleet-ops:component:vehicle:form:details',
            'fleet-ops:component:vehicle:form:after-details',
            'fleet-ops:component:vehicle:form:start',
            'fleet-ops:component:vehicle:form:end',
            'fleet-ops:component:vehicle:card:header:start',
            'fleet-ops:component:vehicle:card:header:end',
            'fleet-ops:component:vehicle:card:footer:start',
            'fleet-ops:component:vehicle:card:footer:end',
            'fleet-ops:component:vendor:form:edit',
            'fleet-ops:component:vendor:form:edit:details',
            'fleet-ops:component:vendor:form:create',
            'fleet-ops:component:vendor:form:create:details',
            'fleet-ops:component:issue:form',
            'fleet-ops:component:issue:form:details',
            'fleet-ops:component:fuel-report:form',
            'fleet-ops:component:fuel-report:form:details',
            'fleet-ops:component:maintenance:form',
            'fleet-ops:component:maintenance:form:details',
            'fleet-ops:component:maintenance:details',
            'fleet-ops:component:work-order:form',
            'fleet-ops:component:work-order:form:details',
            'fleet-ops:component:work-order:details',
            'fleet-ops:component:equipment:form',
            'fleet-ops:component:equipment:form:details',
            'fleet-ops:component:equipment:details',
            'fleet-ops:component:part:form',
            'fleet-ops:component:part:form:details',
            'fleet-ops:component:part:details',
            'fleet-ops:contextmenu:vehicle',
            'fleet-ops:contextmenu:driver',
            'fleet-ops:component:order:details',
            'fleet-ops:component:order:form',
            'fleet-ops:component:order:form:start',
            'fleet-ops:component:order:form:end',
            'fleet-ops:component:order:form:details',
            'fleet-ops:component:order:form:details:start',
            'fleet-ops:component:order:form:details:end',
            'fleet-ops:component:order:form:details:after-details',
            'fleet-ops:component:order:form:payload:entity',
            'fleet-ops:component:order:form:payload:entity:form',
            'fleet-ops:template:settings:routing',
            'fleet-ops:template:settings:orchestrator',
            'fleet-ops:component:admin:routing-settings',
        ]);
    },
};
