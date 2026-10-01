import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class WalletsIndexDetailsController extends Controller {
    @service notifications;
    @service modalsManager;
    @service fetch;
    @service hostRouter;
    @service intl;

    get tabs() {
        return [
            { label: this.intl.t('ledger.ui.payments.field.details'), route: 'console.ledger.wallets.index.details.index' },
            { label: this.intl.t('ledger.ui.payments.field.transactions'), route: 'console.ledger.wallets.index.details.transactions' },
        ];
    }

    get actionButtons() {
        const wallet = this.model;
        const buttons = [
            { label: this.intl.t('ledger.ui.wallet.deposit'), icon: 'arrow-down', type: 'success', onClick: this.depositFunds },
            { label: this.intl.t('ledger.ui.wallet.withdraw'), icon: 'arrow-up', type: 'default', onClick: this.withdrawFunds },
        ];
        if (wallet?.is_frozen) {
            buttons.push({ label: this.intl.t('ledger.ui.wallet.unfreeze'), icon: 'unlock', type: 'primary', onClick: this.unfreezeWallet });
        } else {
            buttons.push({ label: this.intl.t('ledger.ui.wallet.freeze'), icon: 'lock', type: 'danger', onClick: this.freezeWallet });
        }
        return buttons;
    }

    @action async depositFunds() {
        this.modalsManager.show('modals/wallet-deposit', { wallet: this.model });
    }

    @action async withdrawFunds() {
        this.modalsManager.show('modals/wallet-withdraw', { wallet: this.model });
    }

    @action async freezeWallet() {
        try {
            await this.fetch.post(`wallets/${this.model.id}/freeze`, {}, { namespace: 'ledger/int/v1' });
            this.notifications.success(this.intl.t('ledger.ui.wallet.wallet-frozen'));
            this.hostRouter.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action async unfreezeWallet() {
        try {
            await this.fetch.post(`wallets/${this.model.id}/unfreeze`, {}, { namespace: 'ledger/int/v1' });
            this.notifications.success(this.intl.t('ledger.ui.wallet.wallet-unfrozen'));
            this.hostRouter.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
