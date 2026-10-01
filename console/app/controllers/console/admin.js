import menuText from '@fleetbase/ember-ui/utils/menu-text';
import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import isMenuItemActive from '@fleetbase/ember-ui/utils/is-menu-item-active';

export default class ConsoleAdminController extends Controller {
    @service('universe/menu-service') menuService;
    @service universe;
    @service intl;

    get navigationItems() {
        return [...this.coreNavigationItems, ...this.registryNavigationItems, ...this.registryPanelItems, this.systemConfigNavigationItem];
    }

    get coreNavigationItems() {
        return [
            {
                label: this.intl.t('console.admin.menu.overview'),
                description: this.intl.t('console.ui.admin.nav.overview'),
                icon: 'rectangle-list',
                route: 'console.admin.index',
                keywords: ['admin', 'overview', 'dashboard'],
            },
            {
                label: this.intl.t('console.admin.menu.organizations'),
                description: this.intl.t('console.ui.admin.nav.organizations'),
                icon: 'building',
                route: 'console.admin.organizations',
                keywords: ['companies', 'organizations', 'tenants'],
            },
            {
                label: this.intl.t('console.admin.menu.branding'),
                description: this.intl.t('console.ui.admin.nav.branding'),
                icon: 'palette',
                route: 'console.admin.branding',
                keywords: ['brand', 'logo', 'theme', 'colors'],
            },
            {
                label: this.intl.t('console.admin.menu.2fa-config'),
                description: this.intl.t('console.ui.admin.nav.two-fa'),
                icon: 'shield-halved',
                route: 'console.admin.two-fa-settings',
                keywords: ['two factor', '2fa', 'security', 'mfa'],
            },
            {
                label: this.intl.t('console.admin.menu.platform-api-token'),
                description: this.intl.t('console.ui.admin.nav.platform-api-token'),
                icon: 'key',
                route: 'console.admin.platform-api-token',
                keywords: ['platform', 'api', 'token', 'security', 'organizations'],
            },
            {
                label: this.intl.t('console.admin.schedule-monitor.schedule-monitor'),
                description: this.intl.t('console.ui.admin.nav.schedule-monitor'),
                icon: 'calendar-check',
                route: 'console.admin.schedule-monitor',
                keywords: ['scheduler', 'cron', 'tasks', 'logs'],
            },
        ];
    }

    get registryNavigationItems() {
        return (this.menuService.adminMenuItems ?? []).map((menuItem) => this.buildRegistryItem(menuItem));
    }

    get registryPanelItems() {
        return (this.menuService.adminMenuPanels ?? []).map((panel) => {
            return {
                id: panel.slug,
                label: menuText(this.intl, panel, 'title'),
                description: menuText(this.intl, panel, 'description') ?? this.intl.t('console.ui.admin.nav.panel-controls', { title: menuText(this.intl, panel, 'title') }),
                icon: panel.icon ?? 'folder',
                keywords: [panel.slug, panel.title, panel.description].filter(Boolean),
                children: (panel.items ?? []).map((menuItem) => this.buildRegistryItem(menuItem, panel)),
            };
        });
    }

    get systemConfigNavigationItem() {
        return {
            label: this.intl.t('console.ui.admin.nav.system-config'),
            description: this.intl.t('console.ui.admin.nav.system-config-description'),
            icon: 'sliders',
            keywords: ['system', 'config', 'configuration', 'services'],
            children: [
                {
                    label: this.intl.t('console.admin.menu.services'),
                    description: this.intl.t('console.ui.admin.nav.services'),
                    icon: 'bell-concierge',
                    route: 'console.admin.config.services',
                    keywords: ['services', 'providers'],
                },
                {
                    label: this.intl.t('console.admin.menu.mail'),
                    description: this.intl.t('console.ui.admin.nav.mail'),
                    icon: 'envelope',
                    route: 'console.admin.config.mail',
                    keywords: ['mail', 'email', 'smtp'],
                },
                {
                    label: this.intl.t('console.admin.menu.filesystem'),
                    description: this.intl.t('console.ui.admin.nav.filesystem'),
                    icon: 'hard-drive',
                    route: 'console.admin.config.filesystem',
                    keywords: ['filesystem', 'files', 'storage'],
                },
                {
                    label: this.intl.t('console.admin.menu.queue'),
                    description: this.intl.t('console.ui.admin.nav.queue'),
                    icon: 'layer-group',
                    route: 'console.admin.config.queue',
                    keywords: ['queue', 'workers', 'jobs'],
                },
                {
                    label: this.intl.t('console.admin.menu.socket'),
                    description: this.intl.t('console.ui.admin.nav.socket'),
                    icon: 'plug',
                    route: 'console.admin.config.socket',
                    keywords: ['socket', 'realtime', 'websocket'],
                },
                {
                    label: this.intl.t('console.admin.menu.push-notifications'),
                    description: this.intl.t('console.ui.admin.nav.notification-channels'),
                    icon: 'tower-broadcast',
                    route: 'console.admin.config.notification-channels',
                    keywords: ['push notifications', 'notifications', 'channels'],
                },
            ],
        };
    }

    buildRegistryItem(menuItem, panel = null) {
        const registryItem = {
            ...menuItem,
            id: `${panel?.slug ?? 'admin'}:${menuItem.slug ?? menuItem.title}:${menuItem.view ?? 'index'}`,
            _virtual: true,
            label: menuText(this.intl, menuItem, 'label', panel ? `${panel.slug}-${menuItem.view}` : null),
            description: menuText(this.intl, menuItem, 'description', panel ? `${panel.slug}-${menuItem.view}` : null),
            icon: menuItem.icon,
            iconPrefix: menuItem.iconPrefix,
            priority: menuItem.priority,
            slug: menuItem.slug,
            view: menuItem.view,
            section: menuItem.section,
            _isPanelItem: menuItem._isPanelItem,
            _panelSlug: menuItem._panelSlug,
            component: menuItem.component,
            componentParams: menuItem.componentParams,
            permission: menuItem.permission,
            visible: menuItem.visible,
            keywords: [menuItem.slug, menuItem.view, menuItem.section, menuItem.title, menuItem.label, menuItem.description, ...(menuItem.tags ?? [])].filter(Boolean),
            activeWhen: () => isMenuItemActive(menuItem.section, menuItem.slug, menuItem.view),
        };

        registryItem.onClick = () => this.universe.transitionMenuItem('console.admin.virtual', registryItem);

        return registryItem;
    }
}
