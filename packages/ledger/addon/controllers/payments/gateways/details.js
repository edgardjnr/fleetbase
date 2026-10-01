import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';

export default class PaymentsGatewaysDetailsController extends Controller {
    @service notifications;
    @service modalsManager;
    @service hostRouter;
    @service intl;

    @tracked overlay = null;

    get tabs() {
        const currentRouteName = this.hostRouter.currentRouteName;

        return [
            { label: this.intl.t('ledger.ui.gateway.page.tab.overview'), route: 'payments.gateways.details.index' },
            { label: this.intl.t('ledger.ui.gateway.page.tab.setup'), route: 'payments.gateways.details.setup' },
            { label: this.intl.t('ledger.ui.gateway.page.tab.diagnostics'), route: 'payments.gateways.details.diagnostics' },
            { label: this.intl.t('ledger.ui.gateway.page.tab.transactions'), route: 'payments.gateways.details.webhooks' },
        ].map((tab) => ({
            ...tab,
            active: currentRouteName === tab.route || currentRouteName?.endsWith(`.${tab.route}`),
        }));
    }

    get isTransactionsTab() {
        const currentRouteName = this.hostRouter.currentRouteName;

        return currentRouteName === 'payments.gateways.details.webhooks' || currentRouteName?.endsWith('.payments.gateways.details.webhooks');
    }

    get actionButtons() {
        return [
            { label: this.intl.t('ledger.ui.gateway.page.edit'), icon: 'pencil', helpText: this.intl.t('ledger.ui.gateway.page.edit-help'), onClick: this.editGateway },
            { label: this.intl.t('ledger.ui.gateway.page.delete'), icon: 'trash', type: 'danger', helpText: this.intl.t('ledger.ui.gateway.page.delete-help'), onClick: this.deleteGateway },
        ];
    }

    get driverIcon() {
        return (
            {
                taler: 'wallet',
                stripe: 'credit-card',
                cash: 'money-bill-wave',
                qpay: 'qrcode',
            }[this.model?.driver] ?? 'plug'
        );
    }

    get statusLabel() {
        return this.model?.status === 'active' ? this.intl.t('ledger.ui.gateway.form.option.active') : this.intl.t('ledger.ui.gateway.form.option.inactive');
    }

    @action editGateway() {
        const gateway = this.model;
        this.hostRouter.transitionTo('console.ledger.payments.gateways.edit', gateway);
    }

    @action async deleteGateway() {
        const gateway = this.model;
        this.modalsManager.confirm({
            title: this.intl.t('ledger.ui.gateway.page.delete-confirm-title', { name: gateway.name }),
            body: this.intl.t('ledger.ui.gateway.page.delete-confirm-body'),
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await gateway.destroyRecord();
                    this.notifications.success(this.intl.t('ledger.ui.gateway.page.deleted'));
                    this.hostRouter.transitionTo('console.ledger.payments.gateways.index');
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }
}
