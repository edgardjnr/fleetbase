import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class SettingsGatewaysIndexDetailsController extends Controller {
    @service notifications;
    @service modalsManager;
    @service hostRouter;
    @service fetch;
    @service intl;

    get tabs() {
        return [
            { label: this.intl.t('ledger.ui.settings.gateways.tab.configuration'), route: 'console.ledger.settings.gateways.index.details.index' },
            { label: this.intl.t('ledger.ui.settings.gateways.tab.webhook-events'), route: 'console.ledger.settings.gateways.index.details.webhooks' },
        ];
    }

    get actionButtons() {
        return [
            { label: this.intl.t('ledger.ui.gateway.page.edit'), icon: 'pencil', type: 'default', onClick: this.editGateway },
            { label: this.intl.t('ledger.ui.settings.gateways.remove'), icon: 'trash', type: 'danger', onClick: this.deleteGateway },
        ];
    }

    @action editGateway() {
        this.hostRouter.transitionTo('console.ledger.payments.gateways.edit', this.model);
    }

    @action async deleteGateway() {
        this.modalsManager.confirm({
            title: this.intl.t('ledger.ui.settings.gateways.remove-confirm-title'),
            body: this.intl.t('ledger.ui.settings.gateways.remove-confirm-body', { name: this.model.name }),
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await this.model.destroyRecord();
                    this.notifications.success(this.intl.t('ledger.ui.settings.gateways.removed'));
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
