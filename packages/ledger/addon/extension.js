import { MenuItem, Widget, ExtensionComponent } from '@fleetbase/ember-core/contracts';

export default {
    setupExtension(app, universe) {
        const menuService = universe.getService('universe/menu-service');
        const widgetService = universe.getService('universe/widget-service');
        const intl = app.lookup('service:intl');

        // Register Ledger in the console header navigation
        menuService.registerHeaderMenuItem('Ledger', 'console.ledger', {
            icon: 'calculator',
            priority: 4,
            description: intl.t('ledger.ui.extension.header-description'),
            shortcuts: [
                {
                    title: intl.t('ledger.ui.extension.shortcuts.invoices'),
                    description: intl.t('ledger.ui.extension.shortcuts.invoices-description'),
                    icon: 'file-invoice-dollar',
                    route: 'console.ledger.billing.invoices',
                },
                {
                    title: intl.t('ledger.ui.extension.shortcuts.wallets'),
                    description: intl.t('ledger.ui.extension.shortcuts.wallets-description'),
                    icon: 'wallet',
                    route: 'console.ledger.payments.wallets',
                },
                {
                    title: intl.t('ledger.ui.extension.shortcuts.transactions'),
                    description: intl.t('ledger.ui.extension.shortcuts.transactions-description'),
                    icon: 'money-bill-transfer',
                    route: 'console.ledger.payments.transactions',
                },
                {
                    title: intl.t('ledger.ui.extension.shortcuts.payment-gateways'),
                    description: intl.t('ledger.ui.extension.shortcuts.payment-gateways-description'),
                    icon: 'credit-card',
                    route: 'console.ledger.payments.gateways',
                },
                {
                    title: intl.t('ledger.ui.extension.shortcuts.chart-of-accounts'),
                    description: intl.t('ledger.ui.extension.shortcuts.chart-of-accounts-description'),
                    icon: 'sitemap',
                    route: 'console.ledger.accounting.accounts',
                },
                {
                    title: intl.t('ledger.ui.extension.shortcuts.journal-entries'),
                    description: intl.t('ledger.ui.extension.shortcuts.journal-entries-description'),
                    icon: 'book',
                    route: 'console.ledger.accounting.journal',
                },
                {
                    title: intl.t('ledger.ui.extension.shortcuts.general-ledger'),
                    description: intl.t('ledger.ui.extension.shortcuts.general-ledger-description'),
                    icon: 'book-open',
                    route: 'console.ledger.accounting.general-ledger',
                },
            ],
        });

        // ── Public customer invoice view ───────────────────────────────────────
        // Registers the customer-facing invoice view to the 'engine:ledger'
        // registry so it is accessible at /ledger/invoice/<public_id> without
        // requiring the customer to be authenticated in the console.
        //
        // URL pattern: /ledger/invoice/<invoice-public_id-or-uuid>
        //
        // The customer-invoice component reads @slug from the route model and
        // fetches the invoice from the public API endpoint:
        //   GET /ledger/public/invoices/<public_id>
        //
        // wrapperClass: 'hidden' keeps this item invisible in all navigation
        // menus while still making it resolvable via the virtual route.
        menuService.registerMenuItem(
            'auth:login',
            new MenuItem({
                title: intl.t('ledger.ui.extension.menu.invoice'),
                slug: 'invoice',
                route: 'virtual',
                type: 'link',
                wrapperClass: 'hidden',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'customer-invoice'),
                onClick: (menuItem) => {
                    universe.transitionMenuItem('virtual', menuItem);
                },
            })
        );

        // ── Public GNU Taler refund handoff ──────────────────────────────────
        // URL pattern: /~/taler-refund?id=<refund-public_id-or-uuid>
        menuService.registerMenuItem(
            'auth:login',
            new MenuItem({
                title: intl.t('ledger.ui.extension.menu.taler-refund'),
                slug: 'taler-refund',
                route: 'virtual',
                type: 'link',
                wrapperClass: 'hidden',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'customer-taler-refund'),
                onClick: (menuItem) => {
                    universe.transitionMenuItem('virtual', menuItem);
                },
            })
        );

        // ── Fleet-Ops order details tab: Invoice ──────────────────────────────
        // Injects an "Invoice" tab into the Fleet-Ops order details panel.
        // The tab renders the order-invoice component which fetches and displays
        // the Ledger invoice associated with the order, including line items and
        // payment summary.
        menuService.registerMenuItem(
            'fleet-ops:component:order:details',
            new MenuItem({
                title: intl.t('ledger.ui.extension.menu.invoice'),
                route: 'operations.orders.index.details.virtual',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'order-invoice'),
                icon: 'file-invoice-dollar',
                slug: 'invoice',
            })
        );

        // ── Storefront order details tab: Invoice ────────────────────────────
        // Reuses the same order-invoice component inside the Storefront order
        // details panel so commerce orders expose their generated invoice.
        menuService.registerMenuItem(
            'storefront:component:order:details',
            new MenuItem({
                title: intl.t('ledger.ui.extension.menu.invoice'),
                route: 'orders.index.view.virtual',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'order-invoice'),
                icon: 'file-invoice-dollar',
                slug: 'invoice',
            })
        );

        // Register dashboard and widgets
        this.registerWidgets(widgetService, intl);
    },

    registerWidgets(widgetService, intl) {
        const widgets = [
            new Widget({
                id: 'ledger-kpi-revenue',
                name: intl.t('ledger.ui.extension.widgets.revenue'),
                description: intl.t('ledger.ui.extension.widgets.revenue-description'),
                icon: 'sack-dollar',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/kpi-revenue'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: intl.t('ledger.ui.extension.categories.kpi-tiles'),
                default: true,
            }),
            new Widget({
                id: 'ledger-kpi-expenses',
                name: intl.t('ledger.ui.extension.widgets.expenses'),
                description: intl.t('ledger.ui.extension.widgets.expenses-description'),
                icon: 'receipt',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/kpi-expenses'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: intl.t('ledger.ui.extension.categories.kpi-tiles'),
                default: true,
            }),
            new Widget({
                id: 'ledger-kpi-net-income',
                name: intl.t('ledger.ui.extension.widgets.net-income'),
                description: intl.t('ledger.ui.extension.widgets.net-income-description'),
                icon: 'chart-line',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/kpi-net-income'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: intl.t('ledger.ui.extension.categories.kpi-tiles'),
                default: true,
            }),
            new Widget({
                id: 'ledger-kpi-outstanding-ar',
                name: intl.t('ledger.ui.extension.widgets.outstanding-ar'),
                description: intl.t('ledger.ui.extension.widgets.outstanding-ar-description'),
                icon: 'file-invoice-dollar',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/kpi-outstanding-ar'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: intl.t('ledger.ui.extension.categories.kpi-tiles'),
                default: true,
            }),
            new Widget({
                id: 'ledger-kpi-overdue-ar',
                name: intl.t('ledger.ui.extension.widgets.overdue-ar'),
                description: intl.t('ledger.ui.extension.widgets.overdue-ar-description'),
                icon: 'clock',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/kpi-overdue-ar'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: intl.t('ledger.ui.extension.categories.kpi-tiles'),
                default: true,
            }),
            new Widget({
                id: 'ledger-kpi-open-invoices',
                name: intl.t('ledger.ui.extension.widgets.open-invoices'),
                description: intl.t('ledger.ui.extension.widgets.open-invoices-description'),
                icon: 'file-circle-exclamation',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/kpi-open-invoices'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: intl.t('ledger.ui.extension.categories.kpi-tiles'),
                default: true,
            }),
            new Widget({
                id: 'ledger-kpi-wallet-balance',
                name: intl.t('ledger.ui.extension.widgets.wallet-balance'),
                description: intl.t('ledger.ui.extension.widgets.wallet-balance-description'),
                icon: 'wallet',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/kpi-wallet-balance'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: intl.t('ledger.ui.extension.categories.kpi-tiles'),
                default: true,
            }),
            new Widget({
                id: 'ledger-kpi-active-wallets',
                name: intl.t('ledger.ui.extension.widgets.active-wallets'),
                description: intl.t('ledger.ui.extension.widgets.active-wallets-description'),
                icon: 'wallet',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/kpi-active-wallets'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: intl.t('ledger.ui.extension.categories.kpi-tiles'),
                default: true,
            }),

            new Widget({
                id: 'ledger-revenue-trend',
                name: intl.t('ledger.ui.extension.widgets.revenue-trend'),
                description: intl.t('ledger.ui.extension.widgets.revenue-trend-description'),
                icon: 'chart-line',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/revenue-trend'),
                grid_options: { w: 6, h: 9, minW: 5, minH: 8 },
                category: intl.t('ledger.ui.extension.categories.analytics'),
                default: true,
            }),
            new Widget({
                id: 'ledger-cash-flow-summary',
                name: intl.t('ledger.ui.extension.widgets.cash-flow-summary'),
                description: intl.t('ledger.ui.extension.widgets.cash-flow-summary-description'),
                icon: 'money-bill-transfer',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/cash-flow-summary'),
                grid_options: { w: 6, h: 9, minW: 5, minH: 8 },
                category: intl.t('ledger.ui.extension.categories.analytics'),
                default: true,
            }),
            new Widget({
                id: 'ledger-invoice-status',
                name: intl.t('ledger.ui.extension.widgets.invoice-pipeline'),
                description: intl.t('ledger.ui.extension.widgets.invoice-pipeline-description'),
                icon: 'file-invoice-dollar',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/invoice-status'),
                grid_options: { w: 4, h: 8, minW: 4, minH: 7 },
                category: intl.t('ledger.ui.extension.categories.operations'),
                default: true,
            }),
            new Widget({
                id: 'ledger-ar-aging-summary',
                name: intl.t('ledger.ui.extension.widgets.ar-aging-risk'),
                description: intl.t('ledger.ui.extension.widgets.ar-aging-risk-description'),
                icon: 'clock',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/ar-aging-summary'),
                grid_options: { w: 4, h: 8, minW: 4, minH: 7 },
                category: intl.t('ledger.ui.extension.categories.operations'),
                default: true,
            }),
            new Widget({
                id: 'ledger-wallet-balances',
                name: intl.t('ledger.ui.extension.widgets.wallet-balances'),
                description: intl.t('ledger.ui.extension.widgets.wallet-balances-description'),
                icon: 'wallet',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/wallet-balances'),
                grid_options: { w: 4, h: 8, minW: 4, minH: 7 },
                category: intl.t('ledger.ui.extension.categories.operations'),
                default: true,
            }),
            new Widget({
                id: 'ledger-activity-feed',
                name: intl.t('ledger.ui.extension.widgets.recent-activity'),
                description: intl.t('ledger.ui.extension.widgets.recent-activity-description'),
                icon: 'book',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/activity-feed'),
                grid_options: { w: 8, h: 10, minW: 6, minH: 8 },
                category: intl.t('ledger.ui.extension.categories.operations'),
                default: true,
            }),
            new Widget({
                id: 'ledger-report-shortcuts',
                name: intl.t('ledger.ui.extension.widgets.financial-reports'),
                description: intl.t('ledger.ui.extension.widgets.financial-reports-description'),
                icon: 'file-lines',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/report-shortcuts'),
                grid_options: { w: 4, h: 10, minW: 4, minH: 7 },
                category: intl.t('ledger.ui.extension.categories.reports'),
                default: true,
            }),

            new Widget({
                id: 'ledger-overview',
                name: intl.t('ledger.ui.extension.widgets.overview-legacy'),
                description: intl.t('ledger.ui.extension.widgets.overview-legacy-description'),
                icon: 'gauge-high',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/overview'),
                grid_options: { w: 12, h: 4, minW: 8, minH: 4 },
                options: { title: intl.t('ledger.ui.widget.financial-overview') },
                category: intl.t('ledger.ui.extension.categories.legacy'),
                default: false,
            }),
            new Widget({
                id: 'ledger-revenue-chart',
                name: intl.t('ledger.ui.extension.widgets.revenue-chart-legacy'),
                description: intl.t('ledger.ui.extension.widgets.revenue-chart-legacy-description'),
                icon: 'chart-line',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/revenue-chart'),
                grid_options: { w: 8, h: 6, minW: 6, minH: 6 },
                options: { title: intl.t('ledger.ui.widget.revenue-chart') },
                category: intl.t('ledger.ui.extension.categories.legacy'),
                default: false,
            }),
            new Widget({
                id: 'ledger-invoice-summary',
                name: intl.t('ledger.ui.extension.widgets.invoice-summary-legacy'),
                description: intl.t('ledger.ui.extension.widgets.invoice-summary-legacy-description'),
                icon: 'file-invoice-dollar',
                component: new ExtensionComponent('@fleetbase/ledger-engine', 'widget/invoice-summary'),
                grid_options: { w: 4, h: 6, minW: 3, minH: 5 },
                options: { title: intl.t('ledger.ui.widget.invoice-summary') },
                category: intl.t('ledger.ui.extension.categories.legacy'),
                default: false,
            }),
        ];

        const getWidgetById = (id = null, mutate = null) => {
            if (!id) return null;
            const widget = widgets.find((w) => w.id === id);
            if (typeof mutate === 'function') {
                mutate(widget);
            }
            return widget;
        };

        widgetService.registerDashboard('ledger');
        widgetService.registerWidgets('ledger', widgets);
        widgetService.registerWidgets('dashboard', [
            getWidgetById('ledger-activity-feed', (widget) => {
                widget.withGridOptions({ w: 6, minW: 6, h: 8, minH: 8 });
            }),
            getWidgetById('ledger-kpi-revenue'),
            getWidgetById('ledger-kpi-net-income'),
            getWidgetById('ledger-kpi-outstanding-ar'),
            getWidgetById('ledger-kpi-expenses'),
        ]);
    },
};
