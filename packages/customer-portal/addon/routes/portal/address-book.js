import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class PortalAddressBookRoute extends Route {
    @service fetch;
    @service hostRouter;

    beforeModel() {
        // Entregas: tela fora do escopo do portal da loja
        return this.hostRouter.transitionTo('customer-portal.portal.orders');
    }

    async model() {
        try {
            return await this.fetch.get('address-book', {}, { namespace: 'customer-portal/int/v1' });
        } catch {
            return { places: [], contacts: [] };
        }
    }
}
