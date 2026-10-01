import { Widget, ExtensionComponent } from '@fleetbase/ember-core/contracts';

function createStorefrontKeyMetricsWidget(t) {
    return new Widget({
        id: 'storefront-key-metrics-widget',
        name: t('storefront.ui.extension.widget-key-metrics-legacy-name', 'Storefront Metrics (Legacy)'),
        description: t('storefront.ui.extension.widget-key-metrics-legacy-desc', 'Legacy grouped Storefront metrics.'),
        icon: 'store',
        component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/storefront-key-metrics'),
        grid_options: { w: 12, h: 7, minW: 8, minH: 7 },
        options: { title: t('storefront.ui.extension.storefront-metrics-title', 'Storefront Metrics') },
        category: 'Legacy',
        default: false,
    });
}

const translator = (intl) => (key, fallback) => {
    try {
        return intl ? intl.t(key) : fallback;
    } catch (_) {
        return fallback;
    }
};

export function registerWidgets(widgetService, intl = null) {
    const t = translator(intl);

    widgetService.registerDashboard('storefront');

    widgetService.registerWidgets('storefront', [
        new Widget({
            id: 'storefront-kpi-revenue-widget',
            name: t('storefront.ui.extension.widget-revenue-name', 'Revenue'),
            description: t('storefront.ui.extension.widget-revenue-desc', 'Storefront revenue for the current period with trend.'),
            icon: 'sack-dollar',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/kpi-revenue'),
            grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
            category: 'KPI Tiles',
            default: true,
        }),
        new Widget({
            id: 'storefront-kpi-orders-widget',
            name: t('storefront.ui.extension.widget-orders-name', 'Orders'),
            description: t('storefront.ui.extension.widget-orders-desc', 'Order volume for the current period with trend.'),
            icon: 'bag-shopping',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/kpi-orders'),
            grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
            category: 'KPI Tiles',
            default: true,
        }),
        new Widget({
            id: 'storefront-kpi-aov-widget',
            name: t('storefront.ui.extension.widget-average-order-value-name', 'Average Order Value'),
            description: t('storefront.ui.extension.widget-average-order-value-desc', 'Average order value for non-canceled Storefront orders.'),
            icon: 'receipt',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/kpi-aov'),
            grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
            category: 'KPI Tiles',
            default: true,
        }),
        new Widget({
            id: 'storefront-kpi-active-orders-widget',
            name: t('storefront.ui.extension.widget-active-orders-name', 'Active Orders'),
            description: t('storefront.ui.extension.widget-active-orders-desc', 'Orders currently moving through fulfillment.'),
            icon: 'bolt',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/kpi-active-orders'),
            grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
            category: 'KPI Tiles',
            default: true,
        }),
        new Widget({
            id: 'storefront-kpi-completed-orders-widget',
            name: t('storefront.ui.extension.widget-completed-orders-name', 'Completed Orders'),
            description: t('storefront.ui.extension.widget-completed-orders-desc', 'Completed orders for the current period.'),
            icon: 'circle-check',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/kpi-completed-orders'),
            grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
            category: 'KPI Tiles',
            default: true,
        }),
        new Widget({
            id: 'storefront-kpi-customers-widget',
            name: t('storefront.ui.extension.widget-customers-name', 'Customers'),
            description: t('storefront.ui.extension.widget-customers-desc', 'Unique customers ordering during the current period.'),
            icon: 'users',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/kpi-customers'),
            grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
            category: 'KPI Tiles',
            default: true,
        }),
        new Widget({
            id: 'storefront-kpi-cart-conversion-widget',
            name: t('storefront.ui.extension.widget-cart-conversion-name', 'Cart Conversion'),
            description: t('storefront.ui.extension.widget-cart-conversion-desc', 'Orders as a percentage of carts created in the current period.'),
            icon: 'cart-shopping',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/kpi-cart-conversion'),
            grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
            category: 'KPI Tiles',
            default: true,
        }),
        new Widget({
            id: 'storefront-kpi-cancellation-rate-widget',
            name: t('storefront.ui.extension.widget-cancellation-rate-name', 'Cancellation Rate'),
            description: t('storefront.ui.extension.widget-cancellation-rate-desc', 'Canceled orders as a percentage of current period order volume.'),
            icon: 'ban',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/kpi-cancellation-rate'),
            grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
            category: 'KPI Tiles',
            default: true,
        }),
        new Widget({
            id: 'storefront-revenue-trend-widget',
            name: t('storefront.ui.extension.widget-revenue-trend-name', 'Revenue Trend'),
            description: t('storefront.ui.extension.widget-revenue-trend-desc', 'Revenue and order volume over time.'),
            icon: 'chart-line',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/revenue-trend'),
            grid_options: { w: 6, h: 10, minW: 5, minH: 9 },
            category: 'Analytics',
            default: true,
        }),
        new Widget({
            id: 'storefront-top-products-widget',
            name: t('storefront.ui.extension.widget-top-products-name', 'Top Products'),
            description: t('storefront.ui.extension.widget-top-products-desc', 'Best-selling products by revenue.'),
            icon: 'ranking-star',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/top-products'),
            grid_options: { w: 6, h: 10, minW: 5, minH: 9 },
            category: 'Analytics',
            default: true,
        }),
        new Widget({
            id: 'storefront-orders-widget',
            name: t('storefront.ui.extension.widget-storefront-orders-name', 'Storefront Orders'),
            description: t('storefront.ui.extension.widget-storefront-orders-desc', 'Recent Storefront orders.'),
            icon: 'bag-shopping',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/orders'),
            grid_options: { w: 12, h: 11, minW: 8, minH: 8 },
            options: { wrapperClass: 'bordered-classic' },
            category: 'Operations',
            default: true,
        }),
        new Widget({
            id: 'storefront-customer-insights-widget',
            name: t('storefront.ui.extension.widget-customer-insights-name', 'Customer Insights'),
            description: t('storefront.ui.extension.widget-customer-insights-desc', 'New and returning customer mix.'),
            icon: 'chart-pie',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/customer-insights'),
            grid_options: { w: 6, h: 9, minW: 5, minH: 8 },
            category: 'Analytics',
            default: true,
        }),
        new Widget({
            id: 'storefront-orders-by-status-widget',
            name: t('storefront.ui.extension.widget-order-status-mix-name', 'Order Status Mix'),
            description: t('storefront.ui.extension.widget-order-status-mix-desc', 'Distribution of Storefront orders by status.'),
            icon: 'chart-column',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/orders-by-status'),
            grid_options: { w: 6, h: 9, minW: 5, minH: 8 },
            category: 'Analytics',
            default: true,
        }),
        new Widget({
            id: 'storefront-metrics-widget',
            name: t('storefront.ui.extension.widget-storefront-metrics-legacy-name', 'Storefront Metrics (Legacy)'),
            description: t('storefront.ui.extension.widget-storefront-metrics-legacy-desc', 'Legacy Storefront order, customer, store, and earnings metrics.'),
            icon: 'chart-line',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/storefront-metrics'),
            grid_options: { w: 12, h: 4, minW: 8, minH: 4 },
            category: 'Legacy',
            default: false,
        }),
        new Widget({
            id: 'storefront-customers-widget',
            name: t('storefront.ui.extension.widget-storefront-customers-name', 'Storefront Customers'),
            description: t('storefront.ui.extension.widget-storefront-customers-desc', 'Recent Storefront customers.'),
            icon: 'users',
            component: new ExtensionComponent('@fleetbase/storefront-engine', 'widget/customers'),
            grid_options: { w: 12, h: 11, minW: 8, minH: 8 },
            options: { wrapperClass: 'bordered-classic' },
            category: 'Operations',
            default: true,
        }),
    ]);

    widgetService.registerWidgets('dashboard', [createStorefrontKeyMetricsWidget(t)]);
}

export default {
    setupExtension(app, universe) {
        const menuService = universe.getService('menu');
        const registryService = universe.getService('registry');
        const widgetService = universe.getService('widget');
        const intl = app.lookup('service:intl');
        const t = translator(intl);

        // Register menu item in header
        menuService.registerHeaderMenuItem('Storefront', 'console.storefront', {
            icon: 'store',
            priority: 1,
            description: t('storefront.ui.extension.header-desc', 'Online store management: products, orders, customers, and promotions.'),
            shortcuts: [
                {
                    title: t('storefront.ui.extension.shortcut-products-title', 'Products'),
                    description: t('storefront.ui.extension.shortcut-products-desc', 'Manage your product catalogue, categories, and inventory.'),
                    icon: 'box-open',
                    route: 'console.storefront.products',
                },
                {
                    title: t('storefront.ui.extension.shortcut-orders-title', 'Orders'),
                    description: t('storefront.ui.extension.shortcut-orders-desc', 'View and fulfil incoming storefront orders.'),
                    icon: 'bag-shopping',
                    route: 'console.storefront.orders',
                },
                {
                    title: t('storefront.ui.extension.shortcut-customers-title', 'Customers'),
                    description: t('storefront.ui.extension.shortcut-customers-desc', 'Browse and manage your storefront customer accounts.'),
                    icon: 'users',
                    route: 'console.storefront.customers',
                },
                {
                    title: t('storefront.ui.extension.shortcut-networks-title', 'Networks'),
                    description: t('storefront.ui.extension.shortcut-networks-desc', 'Connect and manage multi-store networks and marketplaces.'),
                    icon: 'network-wired',
                    route: 'console.storefront.networks',
                },
                {
                    title: t('storefront.ui.extension.shortcut-catalogs-title', 'Catalogs'),
                    description: t('storefront.ui.extension.shortcut-catalogs-desc', 'Organise products into shareable catalogs.'),
                    icon: 'book-open',
                    route: 'console.storefront.catalogs',
                },
                {
                    title: t('storefront.ui.extension.shortcut-promotions-title', 'Promotions'),
                    description: t('storefront.ui.extension.shortcut-promotions-desc', 'Create push notifications and promotional campaigns.'),
                    icon: 'bullhorn',
                    route: 'console.storefront.promotions',
                },
            ],
        });

        registerWidgets(widgetService, intl);

        // register component to views
        registryService.registerRenderableComponent('fleet-ops:component:order:details', new ExtensionComponent('@fleetbase/storefront-engine', 'storefront-order-summary'));
        registryService.registerRenderableComponent(
            'fleet-ops:template:operations:orders:new:entities-input',
            new ExtensionComponent('@fleetbase/storefront-engine', 'add-product-as-entity-button')
        );
    },
};
