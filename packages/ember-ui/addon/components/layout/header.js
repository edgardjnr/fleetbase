import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { getOwner } from '@ember/application';
import { localizeIamName } from '../../utils/localize-iam-name';

/**
 * Layout header component.
 *
 * @export
 * @class LayoutHeaderComponent
 * @extends {Component}
 */
export default class LayoutHeaderComponent extends Component {
    @service store;
    @service router;
    @service hostRouter;
    @service universe;
    @service currentUser;
    @service abilities;
    @service fetch;
    @service docsPanel;
    @service intl;
    @tracked company;
    @tracked organizationMenuItems = [];
    @tracked userMenuItems = [];
    @tracked extensions = [];

    constructor(owner, { organizationMenuItems = [], userMenuItems = [] }) {
        super(...arguments);
        this.extensions = getOwner(this).application.extensions ?? [];
        this.company = this.currentUser.getCompany();
        this.organizationMenuItems = this.mergeOrganizationMenuItems(organizationMenuItems);
        this.userMenuItems = this.mergeUserMenuItems(userMenuItems);
    }

    mergeOrganizationMenuItems(organizationMenuItems = []) {
        // Prepare menuItems
        const menuItems = [
            {
                text: [
                    this.currentUser.companyName,
                    this.currentUser.email,
                    { component: 'badge', disableHumanize: true, text: localizeIamName(this.intl, this.currentUser.roleName, 'role'), status: 'info', hideStatusDot: false, wrapperClass: 'mt-1' },
                ],
                class: 'flex flex-row items-center px-3 rounded-md text-gray-800 text-sm dark:text-gray-300 leading-1',
                wrapperClass: 'next-dd-session-user-wrapper',
            },
        ];

        // List available organizations for session switching
        const organizations = this.currentUser.organizations;
        if (organizations.length) {
            menuItems.pushObject({ seperator: true });
        }
        for (let i = 0; i < organizations.length; i++) {
            const organization = organizations.objectAt(i);
            const organizationMenuItem = {
                href: 'javascript:;',
                text: organization.name,
                action: 'switchOrganization',
                params: [organization],
            };

            // If current organization
            if (this.currentUser.companyId === organization.id) {
                organizationMenuItem.icon = 'check';
                organizationMenuItem.disabled = true;
                organizationMenuItem.action = undefined;
            }

            menuItems.pushObject(organizationMenuItem);
        }

        // Push static menu items
        const staticMenuItems = [
            {
                seperator: true,
            },
            {
                id: 'console-home',
                route: 'console.home',
                text: this.intl.t('ember-ui.layout.header.home'),
                icon: 'house',
            },
            {
                id: 'organization-settings',
                route: 'console.settings.index',
                text: this.intl.t('ember-ui.layout.header.organization-settings'),
                icon: 'gear',
            },
            {
                id: 'create-or-join-organizations',
                href: 'javascript:;',
                text: this.intl.t('ember-ui.layout.header.create-or-join-organizations'),
                action: 'createOrJoinOrg',
                icon: 'building',
            },
        ];

        // If registry bridge is booted add to static items
        if (this.hasExtension('@fleetbase/registry-bridge-engine')) {
            staticMenuItems.pushObject({
                id: 'explore-extensions',
                route: 'console.extensions',
                text: this.intl.t('ember-ui.layout.header.explore-extensions'),
                icon: 'puzzle-piece',
            });
        }

        // Push static items
        menuItems.pushObjects(staticMenuItems);

        // Merge provided menu items
        menuItems.pushObjects(organizationMenuItems);

        // Push items from universe registry
        const universeOrganizationItems = this.universe.organizationMenuItems;
        if (isArray(universeOrganizationItems) && universeOrganizationItems.length) {
            menuItems.pushObjects([
                {
                    seperator: true,
                },
                ...universeOrganizationItems,
                {
                    seperator: true,
                },
            ]);
        }

        // Entregas RestaurantePro: sem a versão da Fleetbase no menu

        // Merge admin link
        if (this.currentUser.isAdmin) {
            menuItems.pushObjects([
                {
                    seperator: true,
                },
                {
                    route: 'console.admin',
                    text: this.intl.t('ember-ui.layout.header.admin'),
                    icon: 'toolbox',
                },
            ]);
        }

        // Merge logout link
        menuItems.pushObjects([
            {
                seperator: true,
            },
            {
                href: 'javascript:;',
                text: this.intl.t('ember-ui.layout.header.logout'),
                action: 'invalidateSession',
                icon: 'person-running',
            },
        ]);

        // Callback to allow mutation of menu items
        if (typeof this.args.mutateOrganizationMenuItems === 'function') {
            this.args.mutateOrganizationMenuItems(menuItems);
        }

        return menuItems;
    }

    mergeUserMenuItems(userMenuItems = []) {
        // Prepare menu items
        const menuItems = [
            {
                text: [this.currentUser.name, { component: 'badge', disableHumanize: true, text: localizeIamName(this.intl, this.currentUser.roleName, 'role'), status: 'info', hideStatusDot: false, wrapperClass: 'mt-1' }],
                class: 'flex flex-row items-center px-3 rounded-md text-gray-800 text-sm dark:text-gray-300 leading-1',
                wrapperClass: 'next-dd-session-user-wrapper',
            },
            {
                seperator: true,
            },
            {
                id: 'view-profile-user-nav-item',
                wrapperClass: 'view-profile-user-nav-item',
                route: 'console.account.index',
                text: this.intl.t('ember-ui.layout.header.view-profile'),
            },
            {
                id: 'show-keyboard-shortcuts-user-nav-item',
                wrapperClass: 'show-keyboard-shortcuts-user-nav-item',
                href: 'javascript:;',
                text: this.intl.t('ember-ui.layout.header.show-keyboard-shortcuts'),
                disabled: true,
                action: 'showKeyboardShortcuts',
            },
            {
                seperator: true,
            },
            // Entregas RestaurantePro: sem "Novidades" (lista as versões da Fleetbase)
        ];

        // Add developer menu item if booted
        if (this.hasExtension('@fleetbase/dev-engine')) {
            menuItems.pushObject({
                id: 'developers-user-nav-item',
                wrapperClass: 'developers-user-nav-item',
                route: 'console.developers',
                text: this.intl.t('ember-ui.layout.header.developers'),
            });
        }

        // Entregas RestaurantePro: sem Discord, "Ajuda e suporte" (GitHub) e "Documentação" da Fleetbase

        // Push items from universe registry
        const universeUserMenuItems = this.universe.userMenuItems;
        if (isArray(universeUserMenuItems) && universeUserMenuItems.length) {
            menuItems.pushObjects([
                {
                    seperator: true,
                },
                ...universeUserMenuItems,
                {
                    seperator: true,
                },
            ]);
        }

        // Push provided menu items
        menuItems.pushObjects(userMenuItems);

        // Create immutable static menu items
        menuItems.pushObjects([
            {
                component: 'layout/header/dark-mode-toggle',
            },
            {
                seperator: true,
            },
            {
                href: 'javascript:;',
                text: this.intl.t('ember-ui.layout.header.logout'),
                action: 'invalidateSession',
                icon: 'person-running',
            },
        ]);

        // Callback to allow mutation of menu items
        if (typeof this.args.mutateUserMenuItems === 'function') {
            this.args.mutateUserMenuItems(menuItems);
        }

        return menuItems;
    }

    @action routeTo(route) {
        const router = this.router ?? this.hostRouter;

        return router.transitionTo(route);
    }

    hasExtension(extensionName) {
        return this.extensions.find(({ name }) => name === extensionName) !== undefined;
    }
}
