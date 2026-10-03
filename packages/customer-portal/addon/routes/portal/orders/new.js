import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class PortalOrdersNewRoute extends Route {
    @service customerPortalOrderActions;
    @service customerPortalOrderCreation;
    @service fetch;

    async model() {
        // Entregas: a loja vem junto (coleta fixa); sem loja (404) ou com erro, o formulário segue sem ela
        const [orderConfigs, places, minhaLoja] = await Promise.all([
            this.customerPortalOrderActions.loadOrderConfigs.perform(),
            this.customerPortalOrderActions.loadPlaces.perform(),
            this.fetch.get('entregas/loja/minha-loja').catch(() => null),
        ]);
        const loja = minhaLoja?.loja ?? null;
        this.customerPortalOrderCreation.start(orderConfigs);

        // Entregas: só para mostrar a coleta e desenhar a rota; quem grava a coleta no pedido é o servidor
        if (loja?.coleta) {
            this.customerPortalOrderCreation.setPayloadField('pickup', loja.coleta);
        }

        // o setPayloadField troca o objeto do rascunho: o do start() já não vale
        return { draft: this.customerPortalOrderCreation.draft, orderConfigs, places, loja };
    }
}
