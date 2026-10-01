import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';

export default class PaymentsTransactionsIndexDetailsController extends Controller {
    @service intl;
    @tracked overlay = null;

    get tabs() {
        return [{ label: this.intl.t('ledger.ui.payments.field.overview'), route: 'payments.transactions.index.details.index' }];
    }

    get actionButtons() {
        return [];
    }
}
