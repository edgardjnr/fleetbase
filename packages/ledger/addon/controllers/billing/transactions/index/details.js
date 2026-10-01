import Controller from '@ember/controller';
import { inject as service } from '@ember/service';

export default class BillingTransactionsIndexDetailsController extends Controller {
    @service intl;

    get tabs() {
        return [{ label: this.intl.t('ledger.ui.billing.details'), route: 'console.ledger.billing.transactions.index.details.index' }];
    }

    get actionButtons() {
        return [];
    }
}
