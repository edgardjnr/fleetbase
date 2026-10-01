import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { isEmpty } from '@ember/utils';
import { task } from 'ember-concurrency';
import config from 'ember-get-config';
import { action } from '@ember/object';

export default class SettingsPaymentsIndexController extends Controller {
    @service intl;
    @service fetch;
    @tracked hasStripeConnectAccount = true;
    @tracked table;
    @tracked page = 1;
    @tracked limit = 30;
    @tracked sort = '-created_at';
    @tracked query = null;
    queryParams = ['page', 'limit', 'sort', 'query'];
    columns = [
        {
            label: this.intl.t('fleet-ops.ui.controller.settings-payments-index.purchase-rate-id'),
            valuePath: 'public_id',
            cellComponent: 'click-to-copy',
        },
        {
            label: this.intl.t('fleet-ops.ui.controller.settings-payments-index.service-quote'),
            valuePath: 'service_quote_id',
            cellComponent: 'click-to-copy',
        },
        {
            label: this.intl.t('fleet-ops.ui.controller.settings-payments-index.order'),
            valuePath: 'order_id',
            cellComponent: 'click-to-copy',
        },
        {
            label: this.intl.t('fleet-ops.ui.controller.settings-payments-index.customer'),
            valuePath: 'customer.name',
        },
        {
            label: this.intl.t('fleet-ops.ui.controller.settings-payments-index.amount'),
            valuePath: 'amount',
            cellComponent: 'table/cell/currency',
        },
        {
            label: this.intl.t('fleet-ops.ui.controller.settings-payments-index.date'),
            valuePath: 'created_at',
        },
    ];

    get isStripeEnabled() {
        return !isEmpty(config.stripe.publishableKey);
    }

    @task *lookupStripeConnectAccount() {
        try {
            const { hasStripeConnectAccount } = yield this.fetch.get('fleet-ops/payments/has-stripe-connect-account');
            this.hasStripeConnectAccount = hasStripeConnectAccount;
        } catch (error) {
            this.hasStripeConnectAccount = false;
        }
    }

    @action refreshPayments() {
        return this.lookupStripeConnectAccount.perform();
    }
}
