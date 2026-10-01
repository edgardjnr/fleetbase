import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class PaymentsWalletsIndexDetailsController extends Controller {
    @service notifications;
    @service modalsManager;
    @service fetch;
    @service intl;

    get tabs() {
        return [
            { label: this.intl.t('ledger.ui.payments.field.overview'), route: 'payments.wallets.index.details.index' },
            { label: this.intl.t('ledger.ui.payments.field.transactions'), route: 'payments.wallets.index.details.transactions' },
        ];
    }

    get actionButtons() {
        const wallet = this.model;
        const frozen = wallet?.is_frozen;

        return [
            { label: this.intl.t('ledger.ui.wallet.add-funds'), icon: 'plus-circle', type: 'primary', helpText: this.intl.t('ledger.ui.wallet.add-funds-help'), onClick: this.topUpWallet },
            { label: this.intl.t('ledger.ui.wallet.transfer'), icon: 'exchange-alt', helpText: this.intl.t('ledger.ui.wallet.transfer-help'), onClick: this.transferFunds },
            {
                label: frozen ? this.intl.t('ledger.ui.wallet.unfreeze') : this.intl.t('ledger.ui.wallet.freeze'),
                icon: frozen ? 'unlock' : 'lock',
                type: frozen ? 'default' : 'danger',
                helpText: frozen ? this.intl.t('ledger.ui.wallet.unfreeze-help') : this.intl.t('ledger.ui.wallet.freeze-help'),
                onClick: frozen ? this.unfreezeWallet : this.freezeWallet,
            },
        ];
    }

    @action async freezeWallet() {
        const wallet = this.model;

        this.modalsManager.confirm({
            title: this.intl.t('ledger.ui.wallet.freeze-confirm-title', { name: wallet.name }),
            body: this.intl.t('ledger.ui.wallet.freeze-confirm-body'),
            acceptButtonText: this.intl.t('ledger.ui.wallet.freeze-wallet'),
            acceptButtonIcon: 'lock',
            acceptButtonScheme: 'danger',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await this.fetch.post(`wallets/${wallet.id}/freeze`, {}, { namespace: 'ledger/int/v1' });
                    this.notifications.success(this.intl.t('ledger.ui.wallet.frozen-success', { name: wallet.name }));
                    await wallet.reload();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    @action async unfreezeWallet() {
        const wallet = this.model;

        this.modalsManager.confirm({
            title: this.intl.t('ledger.ui.wallet.unfreeze-confirm-title', { name: wallet.name }),
            body: this.intl.t('ledger.ui.wallet.unfreeze-confirm-body'),
            acceptButtonText: this.intl.t('ledger.ui.wallet.unfreeze-wallet'),
            acceptButtonIcon: 'unlock',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await this.fetch.post(`wallets/${wallet.id}/unfreeze`, {}, { namespace: 'ledger/int/v1' });
                    this.notifications.success(this.intl.t('ledger.ui.wallet.unfrozen-success', { name: wallet.name }));
                    await wallet.reload();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    @action async topUpWallet() {
        const wallet = this.model;

        const options = {
            title: this.intl.t('ledger.ui.wallet.add-funds-title', { name: wallet.name }),
            acceptButtonText: this.intl.t('ledger.ui.wallet.add-funds'),
            acceptButtonIcon: 'plus-circle',
            wallet,
            amount: 0,
            description: '',
            setAmount: (centsValue) => {
                options.amount = centsValue;
            },
            setDescription: (event) => {
                options.description = event.target.value;
            },
            confirm: async (modal) => {
                if (!options.amount || options.amount <= 0) {
                    this.notifications.warning(this.intl.t('ledger.ui.wallet.invalid-amount'));
                    return;
                }
                modal.startLoading();
                try {
                    await this.fetch.post(`wallets/${wallet.id}/credit`, { amount: options.amount, description: options.description || 'Manual top-up' }, { namespace: 'ledger/int/v1' });
                    this.notifications.success(this.intl.t('ledger.ui.wallet.funds-added', { name: wallet.name }));
                    await wallet.reload();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        };

        this.modalsManager.show('modals/wallet-top-up', options);
    }

    @action async transferFunds() {
        const wallet = this.model;

        const options = {
            title: this.intl.t('ledger.ui.wallet.transfer-title', { name: wallet.name }),
            acceptButtonText: this.intl.t('ledger.ui.wallet.transfer'),
            acceptButtonIcon: 'exchange-alt',
            wallet,
            toWallet: null,
            amount: 0,
            description: '',
            setToWallet: (selectedWallet) => {
                options.toWallet = selectedWallet;
            },
            setAmount: (centsValue) => {
                options.amount = centsValue;
            },
            setDescription: (event) => {
                options.description = event.target.value;
            },
            confirm: async (modal) => {
                if (!options.toWallet) {
                    this.notifications.warning(this.intl.t('ledger.ui.wallet.select-destination'));
                    return;
                }
                if (!options.amount || options.amount <= 0) {
                    this.notifications.warning(this.intl.t('ledger.ui.wallet.invalid-amount'));
                    return;
                }
                if (options.toWallet.id === wallet.id) {
                    this.notifications.warning(this.intl.t('ledger.ui.wallet.same-wallet'));
                    return;
                }
                modal.startLoading();
                try {
                    await this.fetch.post(
                        `wallets/${wallet.id}/transfer`,
                        {
                            to_wallet_uuid: options.toWallet.id,
                            amount: options.amount,
                            description: options.description || 'Internal transfer',
                        },
                        { namespace: 'ledger/int/v1' }
                    );
                    this.notifications.success(this.intl.t('ledger.ui.wallet.funds-transferred', { name: options.toWallet.name }));
                    await wallet.reload();
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        };

        this.modalsManager.show('modals/wallet-transfer', options);
    }
}
