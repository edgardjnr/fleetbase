import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class PortalDocumentsRoute extends Route {
    @service fetch;
    @service hostRouter;

    beforeModel() {
        // Entregas: tela fora do escopo do portal da loja
        return this.hostRouter.transitionTo('customer-portal.portal.orders');
    }

    async model() {
        try {
            const response = await this.fetch.get('documents', {}, { namespace: 'customer-portal/int/v1' });

            return {
                documents: this.fetch.normalizeModel(response.documents ?? [], 'file'),
            };
        } catch {
            return { documents: [] };
        }
    }
}
