import Component from '@glimmer/component';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';

export default class ModalsRefundResultComponent extends Component {
    @service intl;
    @tracked email = this.args.options.customerEmail ?? '';

    get isCompleted() {
        return this.args.options.walletStatus === 'accepted' || this.args.options.refundStatus === 'refunded';
    }

    get statusTitle() {
        return this.isCompleted ? this.intl.t('ledger.ui.refund.completed-title') : this.intl.t('ledger.ui.refund.issued-title');
    }

    get statusMessage() {
        if (this.isCompleted) {
            return this.intl.t('ledger.ui.refund.completed-message');
        }

        return this.intl.t('ledger.ui.refund.issued-message');
    }

    @action setEmail(event) {
        this.email = event.target.value;
    }

    @action sendRefundUri() {
        return this.args.options.sendRefundUri?.(this.args.options.refund, this.email);
    }
}
