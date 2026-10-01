import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { later } from '@ember/runloop';
import { isArray } from '@ember/array';
import { task } from 'ember-concurrency';
import window from 'ember-window-mock';

export default class ConsoleAdminOrganizationsDetailsController extends Controller {
    @service router;
    @service fetch;
    @service notifications;
    @service modalsManager;
    @service session;
    @service intl;
    @service('universe/menu-service') menuService;

    get tabs() {
        const coreTabs = [
            { route: 'console.admin.organizations.details.index', label: this.intl.t('common.overview'), icon: 'chart-simple', priority: 0 },
            { route: 'console.admin.organizations.details.users', label: this.intl.t('common.users'), icon: 'users', priority: 10 },
            { route: 'console.admin.organizations.details.extensions', label: this.intl.t('console.ui.admin.org.extensions'), icon: 'puzzle-piece', priority: 20 },
            { route: 'console.admin.organizations.details.activity', label: this.intl.t('common.activity'), icon: 'clock-rotate-left', priority: 30 },
            { route: 'console.admin.organizations.details.settings', label: this.intl.t('common.settings'), icon: 'gear', priority: 40 },
        ];
        const registeredTabs = this.visibleRegisteredTabs.map((tab) => ({
            ...tab,
            route: 'console.admin.organizations.details.extensions-tab',
            model: tab.slug,
            priority: tab.priority ?? 50,
        }));
        const tabs = [...coreTabs, ...registeredTabs].sort((a, b) => a.priority - b.priority);

        return tabs.map((tab) => ({
            ...tab,
            active: this.isTabActive(tab),
        }));
    }

    get visibleRegisteredTabs() {
        const registeredTabs = this.menuService.getMenuItems('console:admin:organization:tabs');

        if (!isArray(registeredTabs)) {
            return [];
        }

        return registeredTabs.filter((tab) => {
            if (!tab?.slug || !tab?.component) {
                return false;
            }

            if (typeof tab.isVisible === 'function') {
                return tab.isVisible(this.extensionContext);
            }

            return true;
        });
    }

    get registeredActions() {
        const actions = this.menuService.getMenuItems('console:admin:organization:actions');

        if (!isArray(actions)) {
            return [];
        }

        return actions
            .filter((action) => {
                if (typeof action.isVisible === 'function') {
                    return action.isVisible(this.extensionContext);
                }

                return true;
            })
            .sort((a, b) => (a.priority ?? 99) - (b.priority ?? 99));
    }

    get actionMenuItems() {
        return [
            {
                label: this.intl.t('console.ui.admin.org.impersonate-owner'),
                icon: 'user-secret',
                perform: this.impersonateOwner,
                disabled: !this.ownerId,
            },
            {
                label: this.intl.t('console.ui.admin.org.copy-public-id'),
                icon: 'copy',
                onClick: () => this.copyId(this.organization?.public_id, this.intl.t('console.ui.admin.org.public-id')),
            },
            {
                label: this.intl.t('console.ui.admin.org.copy-uuid'),
                icon: 'fingerprint',
                onClick: () => this.copyId(this.organization?.uuid, 'UUID'),
            },
            {
                label: this.intl.t('common.refresh'),
                icon: 'refresh',
                onClick: this.refresh,
            },
            {
                label: this.intl.t('console.ui.admin.org.edit-settings'),
                icon: 'edit',
                onClick: this.editOrganization,
            },
            ...this.registeredActions.map((action) => ({
                label: action.label || action.text || action.title,
                icon: action.icon,
                class: action.class,
                onClick: () => this.runRegisteredAction(action),
            })),
        ];
    }

    get organization() {
        return this.model;
    }

    get organizationStatus() {
        return this.organization?.status || 'active';
    }

    get onboardingStatus() {
        return this.organization?.onboarding_completed ? 'complete' : 'incomplete';
    }

    get organizationStatusText() {
        return this.translateState(this.organizationStatus);
    }

    get onboardingStatusText() {
        return this.translateState(this.onboardingStatus);
    }

    translateState(value) {
        const key = `console.ui.admin.org.state.${value}`;
        return this.intl.exists(key) ? this.intl.t(key) : value;
    }

    get billingStatus() {
        return this.organization?.billing_status || 'not configured';
    }

    get owner() {
        return this.resolveBelongsTo(this.organization?.owner);
    }

    get ownerId() {
        return this.owner?.id || this.owner?.uuid || this.organization?.owner_uuid;
    }

    get ownerName() {
        return this.owner?.name;
    }

    get ownerEmail() {
        return this.owner?.email;
    }

    get hasOwner() {
        return Boolean(this.owner || this.organization?.owner_uuid);
    }

    get extensionContext() {
        return {
            organization: this.organization,
            currentUser: this.session?.data?.authenticated?.user,
            capabilities: {},
        };
    }

    resolveBelongsTo(record) {
        if (!record) {
            return null;
        }

        if (record.content) {
            return record.content;
        }

        if (record.isPending || record.isFulfilled === false || typeof record.then === 'function') {
            return null;
        }

        return record;
    }

    isTabActive(tab) {
        const routeName = tab.route;

        if (routeName === 'console.admin.organizations.details.extensions-tab') {
            const currentSlug = this.router.currentRoute?.params?.slug;
            return this.router.currentRouteName === routeName && currentSlug === tab.model;
        }

        return this.router.currentRouteName === routeName || this.router.currentRouteName?.startsWith(`${routeName}.`);
    }

    @action copyId(value, label = 'ID') {
        if (!value) {
            return;
        }

        navigator.clipboard?.writeText(value);
        this.notifications.success(this.intl.t('console.ui.admin.org.copied', { label }));
    }

    @action editOrganization() {
        this.modalsManager.show('modals/edit-organization', {
            title: this.intl.t('console.ui.account.organizations.edit-organization'),
            acceptButtonText: this.intl.t('common.save-changes'),
            acceptButtonIcon: 'save',
            organization: this.organization,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.organization.save();
                    this.notifications.success(this.intl.t('console.ui.admin.org.updated'));
                    return this.router.refresh();
                } catch (error) {
                    this.notifications.serverError(error);
                }
            },
        });
    }

    @action refresh() {
        this.router.refresh();
    }

    @action runRegisteredAction(action) {
        if (typeof action?.onClick === 'function') {
            return action.onClick(this.extensionContext);
        }

        if (action?.route) {
            return this.router.transitionTo(action.route, this.organization);
        }
    }

    @task *impersonateOwner() {
        if (!this.ownerId) {
            return this.notifications.error(this.intl.t('console.ui.admin.org.no-owner-to-impersonate'));
        }

        try {
            const { token } = yield this.fetch.post('auth/impersonate', { user: this.ownerId });
            yield this.router.transitionTo('console');
            this.session.manuallyAuthenticate(token);
            this.notifications.info(this.intl.t('console.ui.admin.org.now-impersonating', { name: this.ownerEmail || this.intl.t('console.ui.admin.org.organization-owner') }));
            later(() => window.location.reload(), 600);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
