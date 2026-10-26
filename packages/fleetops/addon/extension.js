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
                // Entregas: sem o atalho Lugares (tela oculta; os locais se abrem pelos modais)
                {
                    id: 'fleet-ops-sc-drivers',
                    title: intlService.t('fleet-ops.ui.extension.shortcut-drivers.title'),
                    description: intlService.t('fleet-ops.ui.extension.shortcut-drivers.description'),
                    icon: 'id-card',
                    route: 'console.fleet-ops.management.drivers',
                },
                {
                    // Entregas: o id fica (atalhos salvos); a "Pagamento e cobrança" virou o Financeiro. A rota é a raiz do
                    // Financeiro: o atalho é registrado antes de o usuário carregar, e routes/financeiro/index.js decide na
                    // hora do clique (admin do sistema → Painel; admin da organização → Saques Pix; os demais a guarda de
                    // routes/financeiro.js recusa, como antes)
                    id: 'fleet-ops-sc-driver-payouts',
                    title: intlService.t('fleet-ops.ui.financeiro.menu.titulo'),
                    description: intlService.t('fleet-ops.ui.financeiro.menu.descricao'),
                    icon: 'sack-dollar',
                    route: 'console.fleet-ops.financeiro',
                },
            ],
        });

        // Register admin sections
        menuService.registerAdminMenuPanel(
            intlService.t('fleet-ops.ui.extension.admin.panel-title'),
            [
                // Entregas: sem "Roteamento" (admin/routing-settings); o km da cobrança usa o OSRM do servidor
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

        // Entregas: o painel inicial do console (home/painel-inicial) no lugar dos widgets do Fleetbase
        this.registerHomeComponents(registryService);

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
            // Entregas: sem os widgets fora do escopo (legado de 13 números, receita, ticket médio, ocorrências,
            // combustível, manutenção e geocercas). Nada mais exibe widgets do Fleet-Ops: o início do console é o
            // painel inicial e Análises está oculto (utils/entregas-hidden-routes).

            // Small KPI tile widgets — S-tier, each wraps <Widget::KpiTile> with a
            // fixed slug pointing at GET /fleet-ops/metrics/{slug}.
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
        registryService.registerRenderableComponent('console:home:before-dashboard', new ExtensionComponent('@fleetbase/fleetops-engine', 'home/painel-inicial'));
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
