import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class DevelopersPaymentsIndexController extends Controller {
    @service fetch;
    @service intl;
    @tracked hasStripeConnectAccount = true;
    @tracked table;
    @tracked page = 1;
    @tracked limit = 30;
    @tracked sort = '-created_at';
    @tracked query = null;
    queryParams = ['page', 'limit', 'sort', 'query'];
    columns = [
        {
            label: this.intl.t('registry-bridge.ui.extension'),
            valuePath: 'extension.name',
            width: '20%',
        },
        {
            label: this.intl.t('registry-bridge.ui.category'),
            valuePath: 'extension.category_name',
            width: '20%',
        },
        {
            label: this.intl.t('registry-bridge.ui.customer'),
            valuePath: 'company.name',
            width: '20%',
        },
        {
            label: this.intl.t('registry-bridge.ui.amount'),
            valuePath: 'locked_price',
            cellComponent: 'table/cell/currency',
            width: '20%',
        },
        {
            label: this.intl.t('registry-bridge.ui.date'),
            valuePath: 'created_at',
            width: '20%',
        },
    ];

    @task *lookupStripeConnectAccount() {
        try {
            const { hasStripeConnectAccount } = yield this.fetch.get('payments/has-stripe-connect-account', {}, { namespace: '~registry/v1' });
            this.hasStripeConnectAccount = hasStripeConnectAccount;
        } catch (error) {
            this.hasStripeConnectAccount = false;
        }
    }
}
