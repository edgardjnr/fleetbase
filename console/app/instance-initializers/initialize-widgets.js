import { Widget } from '@fleetbase/ember-core/contracts';
import { faGithub } from '@fortawesome/free-brands-svg-icons';
import { debug } from '@ember/debug';

/**
 * Register dashboard and widgets for FleetbaseConsole
 * Runs after extensions are loaded
 */
export function initialize(appInstance) {
    const universe = appInstance.lookup('service:universe');
    const widgetService = universe.getService('widget');
    const menuService = appInstance.lookup('service:universe/menu-service');

    debug('[Initializing Widgets] Registering console dashboard and widgets...');

    // Register the console dashboard
    widgetService.registerDashboard('dashboard');
    widgetService.registerDashboard('admin');
    widgetService.registerDashboardForSlot('console.home', 'dashboard', {
        name: 'Default Dashboard',
        extension: 'core',
        priority: 0,
    });

    // Wait for all extension to boot
    universe.onBoot(() => {
        // Create widget definitions
        const widgets = [
            new Widget({
                id: 'fleetbase-blog',
                name: 'console.ui.widgets.fleetbase-blog.name',
                description: 'console.ui.widgets.fleetbase-blog.description',
                icon: 'newspaper',
                component: 'fleetbase-blog',
                grid_options: { w: 7, h: 9, minW: 7, minH: 9 },
                default: true,
            }),
            new Widget({
                id: 'fleetbase-github-card',
                name: 'console.ui.widgets.github-card.name',
                description: 'console.ui.widgets.github-card.description',
                icon: faGithub,
                component: 'github-card',
                grid_options: { w: 5, h: 9, minW: 5, minH: 9 },
                default: true,
            }),
        ];

        const adminKpiTile = (id, name, description, icon, slug, options = {}) =>
            new Widget({
                id,
                // name/description are translation keys (resolved by the widget panel); the English
                // strings passed in stay as the fallback title stored in the widget options.
                name: `console.ui.widgets.admin.${slug}.title`,
                description: `console.ui.widgets.admin.${slug}.description`,
                icon,
                component: 'admin/widget/kpi-tile',
                category: 'KPI Tiles',
                options: { title: name, icon, slug, ...options },
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                default: true,
            });

        const registeredAdminWidgets = menuService.getMenuItems('console:admin:dashboard:widgets') || [];
        const normalizeAdminWidget = (widget) => {
            if (widget instanceof Widget) {
                return widget;
            }

            return new Widget({
                category: 'Extension Widgets',
                default: false,
                ...widget,
            });
        };

        const adminWidgets = [
            adminKpiTile('admin-kpi-users-total', 'Users', 'Total platform users with 30-day trend.', 'users', 'users-total'),
            adminKpiTile('admin-kpi-organizations-total', 'Organizations', 'Total organizations with 30-day trend.', 'building', 'organizations-total'),
            adminKpiTile('admin-kpi-active-admins', 'Active Admins', 'Active system administrators.', 'user-shield', 'active-admins', { footnote: 'admin access' }),
            adminKpiTile(
                'admin-kpi-organizations-attention',
                'Pending Attention',
                'Organizations with ownership, onboarding, or status concerns.',
                'building-circle-exclamation',
                'organizations-attention',
                {
                    footnote: 'needs review',
                }
            ),
            adminKpiTile('admin-kpi-new-users', 'New Users', 'Users created in the last 30 days.', 'user-plus', 'new-users'),
            adminKpiTile('admin-kpi-new-organizations', 'New Organizations', 'Organizations created in the last 30 days.', 'building-circle-check', 'new-organizations'),
            adminKpiTile('admin-kpi-failed-jobs', 'Failed Jobs', 'Failed queue jobs waiting for review.', 'triangle-exclamation', 'failed-jobs', { footnote: 'queue health' }),
            adminKpiTile('admin-kpi-suspicious-activity', 'Suspicious Activity', 'Recent sensitive admin or auth activity.', 'shield-halved', 'suspicious-activity', {
                footnote: 'last 30d',
            }),
            new Widget({
                id: 'admin-system-diagnostics',
                name: 'console.ui.widgets.admin.system-diagnostics.title',
                description: 'console.ui.widgets.admin.system-diagnostics.description',
                icon: 'heart-pulse',
                component: 'admin/widget/list-panel',
                category: 'Diagnostics',
                options: { title: 'System Diagnostics', icon: 'heart-pulse', slug: 'system-diagnostics' },
                grid_options: { w: 6, h: 8, minW: 5, minH: 7 },
                default: true,
            }),
            new Widget({
                id: 'admin-activity',
                name: 'console.ui.widgets.admin.admin-activity.title',
                description: 'console.ui.widgets.admin.admin-activity.description',
                icon: 'clock-rotate-left',
                component: 'admin/widget/list-panel',
                category: 'Security',
                options: { title: 'Admin Activity', icon: 'clock-rotate-left', slug: 'admin-activity' },
                grid_options: { w: 6, h: 8, minW: 5, minH: 7 },
                default: true,
            }),
            new Widget({
                id: 'admin-organization-risk-queue',
                name: 'console.ui.widgets.admin.organization-risk-queue.title',
                description: 'console.ui.widgets.admin.organization-risk-queue.description',
                icon: 'building-shield',
                component: 'admin/widget/list-panel',
                category: 'Operations',
                options: { title: 'Organization Risk Queue', icon: 'building-shield', slug: 'organization-risk-queue' },
                grid_options: { w: 6, h: 8, minW: 5, minH: 7 },
                default: true,
            }),
            new Widget({
                id: 'admin-configuration-gaps',
                name: 'console.ui.widgets.admin.configuration-gaps.title',
                description: 'console.ui.widgets.admin.configuration-gaps.description',
                icon: 'screwdriver-wrench',
                component: 'admin/widget/list-panel',
                category: 'Diagnostics',
                options: { title: 'Configuration Gaps', icon: 'screwdriver-wrench', slug: 'configuration-gaps' },
                grid_options: { w: 6, h: 8, minW: 5, minH: 7 },
                default: true,
            }),
            new Widget({
                id: 'admin-platform-growth-chart',
                name: 'console.ui.widgets.admin.platform-growth.title',
                description: 'console.ui.widgets.admin.platform-growth.description',
                icon: 'chart-line',
                component: 'admin/widget/chart-panel',
                category: 'Operations',
                options: { title: 'Platform Growth Trend', icon: 'chart-line', slug: 'platform-growth' },
                grid_options: { w: 6, h: 9, minW: 5, minH: 8 },
                default: false,
            }),
            ...registeredAdminWidgets.map(normalizeAdminWidget),
        ];

        // Register widgets
        widgetService.registerWidgets('dashboard', widgets);
        widgetService.registerWidgets('admin', adminWidgets);
    });
}

export default {
    name: 'initialize-widgets',
    after: 'load-extensions',
    initialize,
};
