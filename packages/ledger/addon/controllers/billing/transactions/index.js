import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class BillingTransactionsIndexController extends Controller {
    @service hostRouter;
    @service intl;

    queryParams = ['page', 'limit', 'sort', 'query', 'status'];

    @tracked page = 1;
    @tracked limit = 30;
    @tracked sort = '-created_at';
    @tracked query = null;
    @tracked status = null;
    @tracked table = null;

    get columns() {
        return [
            { label: this.intl.t('column.date'), valuePath: 'created_at', width: '140px', sortable: true, component: 'table/cell/date' },
            { label: this.intl.t('column.reference'), valuePath: 'gateway_transaction_id', width: '200px' },
            { label: this.intl.t('column.gateway'), valuePath: 'gateway_code', width: '120px' },
            { label: this.intl.t('column.customer'), valuePath: 'customer_name', width: '160px' },
            { label: this.intl.t('column.amount'), valuePath: 'formatted_amount', width: '120px', sortable: true },
            { label: this.intl.t('column.status'), valuePath: 'status', width: '100px', component: 'table/cell/status' },
        ];
    }

    get actionButtons() {
        return [];
    }

    @task({ restartable: true }) *search(query) {
        yield Promise.resolve();
        this.query = query;
    }

    @action viewTransaction(txn) {
        this.hostRouter.transitionTo('console.ledger.billing.transactions.index.details', txn.id);
    }

    @action reload() {
        return this.hostRouter.refresh();
    }
}
