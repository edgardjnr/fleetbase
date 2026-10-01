import Component from '@glimmer/component';
import { inject as service } from '@ember/service';

export default class StorefrontOrderDetailsCustomerInsightsComponent extends Component {
    @service intl;

    get customer() {
        return this.args.resource?.customer;
    }

    get orderCount() {
        return Number(this.customer?.orders ?? this.customer?.order_count ?? 0);
    }

    get hasCompleteContact() {
        return Boolean(this.customer?.phone && this.customer?.email);
    }

    get customerType() {
        if (!this.customer) {
            return null;
        }

        return this.orderCount > 1 ? this.intl.t('storefront.ui.order.returning-customer') : this.intl.t('storefront.ui.order.first-time-customer');
    }

    get contactStatus() {
        if (this.hasCompleteContact) {
            return this.intl.t('storefront.ui.order.phone-and-email-available');
        }

        if (this.customer?.phone) {
            return this.intl.t('storefront.ui.order.phone-available');
        }

        if (this.customer?.email) {
            return this.intl.t('storefront.ui.order.email-available');
        }

        return this.intl.t('storefront.ui.order.no-contact-details');
    }
}
