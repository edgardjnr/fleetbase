import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

export default class DevelopersCredentialsController extends Controller {
    @service modalsManager;
    @service notifications;
    @service hostRouter;
    @service fetch;
    @service intl;

    columns = [
        {
            label: this.intl.t('registry-bridge.ui.owner'),
            valuePath: 'user.name',
            width: '15%',
        },
        {
            label: this.intl.t('registry-bridge.ui.fleetbase-token'),
            valuePath: 'token',
            cellComponent: 'click-to-copy',
            width: '20%',
        },
        {
            label: this.intl.t('registry-bridge.ui.registry-token'),
            valuePath: 'registry_token',
            cellComponent: 'click-to-reveal',
            cellComponentArgs: {
                clickToCopy: true,
            },
            width: '25%',
        },
        {
            label: this.intl.t('registry-bridge.ui.expiry'),
            valuePath: 'expires_at',
            width: '15%',
        },
        {
            label: this.intl.t('registry-bridge.ui.created'),
            valuePath: 'created_at',
            width: '15%',
        },
        {
            label: '',
            cellComponent: 'table/cell/dropdown',
            ddButtonText: false,
            ddButtonIcon: 'ellipsis-h',
            ddButtonIconPrefix: 'fas',
            ddMenuLabel: this.intl.t('registry-bridge.ui.credential-actions'),
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '10%',
            align: 'right',
            actions: [
                {
                    label: this.intl.t('registry-bridge.ui.delete-credentials'),
                    fn: this.deleteCredentials,
                    className: 'text-red-700 hover:text-red-800',
                },
            ],
        },
    ];

    @action deleteCredentials(credentials) {
        this.modalsManager.confirm({
            title: this.intl.t('registry-bridge.ui.delete-credentials-title'),
            body: this.intl.t('registry-bridge.ui.delete-credentials-body'),
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.delete(`auth/registry-tokens/${credentials.uuid}`, {}, { namespace: '~registry/v1' });
                    this.notifications.success(this.intl.t('registry-bridge.ui.credentials-deleted'));
                    return this.hostRouter.refresh();
                } catch (error) {
                    this.notifications.serverError(error);
                }
            },
        });
    }

    @action createCredentials() {
        this.modalsManager.show('modals/create-registry-credentials', {
            title: this.intl.t('registry-bridge.ui.create-credentials-title'),
            acceptButtonText: this.intl.t('registry-bridge.ui.create'),
            acceptButtonIcon: 'check',
            password: null,
            confirm: async (modal) => {
                modal.startLoading();

                const password = modal.getOption('password');
                if (!password) {
                    this.notifications.warning(this.intl.t('registry-bridge.ui.password-required'));
                    return modal.stopLoading();
                }

                try {
                    await this.fetch.post('auth/registry-tokens', { password }, { namespace: '~registry/v1' });
                    this.notifications.success(this.intl.t('registry-bridge.ui.credentials-created'));
                    return this.hostRouter.refresh();
                } catch (error) {
                    this.notifications.serverError(error);
                }
            },
        });
    }
}
