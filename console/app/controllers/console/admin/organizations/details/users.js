import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { later } from '@ember/runloop';
import window from 'ember-window-mock';

export default class ConsoleAdminOrganizationsDetailsUsersController extends Controller {
    @service intl;
    @service router;
    @service fetch;
    @service notifications;
    @service modalsManager;
    @service session;

    @tracked nestedPage = 1;
    @tracked nestedLimit = 20;
    @tracked nestedSort = '-created_at';
    @tracked nestedQuery = '';
    @tracked company;
    @tracked table;

    queryParams = ['nestedPage', 'nestedLimit', 'nestedSort', 'nestedQuery'];
    actionButtons = [];
    bulkActions = [];

    get sort() {
        return this.nestedSort;
    }

    set sort(value) {
        this.nestedSort = value;
    }

    columns = [
        {
            label: this.intl.t('common.name'),
            valuePath: 'name',
            sticky: true,
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'nestedQuery',
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('common.role'),
            valuePath: 'localizedRoleName',
            sortParam: 'roleName',
            resizable: true,
            sortable: true,
        },
        {
            label: this.intl.t('common.phone'),
            valuePath: 'phone',
            resizable: true,
        },
        {
            label: this.intl.t('common.email'),
            valuePath: 'email',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'nestedQuery',
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('common.status'),
            valuePath: 'status',
            cellComponent: 'table/cell/status',
            resizable: true,
            sortable: true,
        },
        {
            label: '',
            cellComponent: 'table/cell/dropdown',
            ddButtonText: false,
            ddButtonIcon: 'ellipsis-h',
            ddButtonIconPrefix: 'fas',
            ddMenuLabel: this.intl.t('console.ui.admin.org.users.user-actions'),
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '9%',
            actions: [
                {
                    label: this.intl.t('console.ui.admin.org.users.impersonate'),
                    icon: 'user-secret',
                    fn: this.impersonateUser,
                },
                {
                    label: this.intl.t('common.change-password'),
                    icon: 'lock-open',
                    fn: this.changeUserPassword,
                },
                {
                    separator: true,
                },
                {
                    label: this.intl.t('console.ui.admin.org.users.verify'),
                    icon: 'circle-check',
                    fn: this.verifyUser,
                },
                {
                    label: this.intl.t('console.ui.admin.org.activate'),
                    icon: 'user-check',
                    fn: this.activateUser,
                },
                {
                    label: this.intl.t('console.ui.admin.org.users.deactivate'),
                    icon: 'user-slash',
                    fn: this.deactivateUser,
                },
                {
                    label: this.intl.t('console.ui.admin.org.users.transfer-ownership'),
                    icon: 'crown',
                    fn: this.transferOwnership,
                },
                {
                    label: this.intl.t('console.ui.admin.org.users.remove-from-organization'),
                    icon: 'user-xmark',
                    class: 'text-red-600',
                    fn: this.removeUser,
                },
            ],
            sortable: false,
            filterable: false,
            resizable: false,
            searchable: false,
            sticky: 'right',
        },
    ];

    @action async impersonateUser(user) {
        try {
            const { token } = await this.fetch.post('auth/impersonate', { user: user.id });
            await this.router.transitionTo('console');
            this.session.manuallyAuthenticate(token);
            this.notifications.info(this.intl.t('console.ui.admin.org.now-impersonating', { name: user.email }));
            later(() => window.location.reload(), 600);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action changeUserPassword(user) {
        this.modalsManager.show('modals/change-user-password', {
            keepOpen: true,
            user,
        });
    }

    @action async verifyUser(user) {
        return this.runUserLifecycleAction(user, 'verify', this.intl.t('console.ui.admin.org.users.verified'));
    }

    @action async activateUser(user) {
        return this.runUserLifecycleAction(user, 'activate', this.intl.t('console.ui.admin.org.users.activated'));
    }

    @action async deactivateUser(user) {
        return this.confirmUserLifecycleAction(
            user,
            this.intl.t('console.ui.admin.org.users.deactivate-user'),
            this.intl.t('console.ui.admin.org.users.deactivate-body', { name: user.name || user.email }),
            'deactivate',
            this.intl.t('console.ui.admin.org.users.deactivated')
        );
    }

    @action transferOwnership(user) {
        this.modalsManager.confirm({
            title: this.intl.t('console.ui.admin.org.users.transfer-ownership'),
            body: this.intl.t('console.ui.admin.org.users.transfer-ownership-body', { name: user.name || user.email }),
            acceptButtonText: this.intl.t('console.ui.admin.org.users.transfer-ownership'),
            acceptButtonIcon: 'crown',
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.post(`companies/${this.company.uuid}/transfer-ownership`, { newOwner: this.userIdentifier(user) });
                    this.notifications.success(this.intl.t('console.ui.admin.org.users.ownership-transferred'));
                    return this.router.refresh();
                } catch (error) {
                    this.notifications.serverError(error);
                }
            },
        });
    }

    @action removeUser(user) {
        return this.confirmUserLifecycleAction(
            user,
            this.intl.t('console.ui.admin.org.users.remove-user'),
            this.intl.t('console.ui.admin.org.users.remove-body', { name: user.name || user.email }),
            null,
            this.intl.t('console.ui.admin.org.users.removed'),
            async () => {
                await this.fetch.delete(`companies/${this.company.uuid}/users/${this.userIdentifier(user)}`);
            }
        );
    }

    @action search(event) {
        this.nestedQuery = event.target.value ?? '';
        this.nestedPage = 1;
    }

    @action refresh() {
        return this.router.refresh();
    }

    async runUserLifecycleAction(user, action, successMessage) {
        try {
            await this.fetch.patch(`companies/${this.company.uuid}/users/${this.userIdentifier(user)}/${action}`);
            this.notifications.success(successMessage);
            return this.router.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    confirmUserLifecycleAction(user, title, body, action, successMessage, callback = null) {
        this.modalsManager.confirm({
            title,
            body,
            acceptButtonText: title,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    if (typeof callback === 'function') {
                        await callback();
                        this.notifications.success(successMessage);
                        return this.router.refresh();
                    }

                    return this.runUserLifecycleAction(user, action, successMessage);
                } catch (error) {
                    this.notifications.serverError(error);
                }
            },
        });
    }

    userIdentifier(user) {
        return user?.uuid || user?.id;
    }
}
