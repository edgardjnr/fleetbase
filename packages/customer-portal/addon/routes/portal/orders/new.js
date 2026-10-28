import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/**
 * Entregas: o "Novo pedido" do portal é o painel "Chamar motoboy" (Portal::ChamarMotoboy; spec
 * docs/superpowers/specs/2026-10-28-chamar-motoboy-portal-design.md). A loja vem para o nome e o centro do mapa; o
 * rascunho do customerPortalOrderCreation continua com a coleta, para o mapa grande mostrar o P da loja (quem grava a
 * coleta no pedido é o servidor).
 */
export default class PortalOrdersNewRoute extends Route {
    @service customerPortalOrderActions;
    @service customerPortalOrderCreation;
    @service fetch;

    async model() {
        // sem loja (404) ou com erro, o painel segue sem ela (o mapa abre quando houver ponto)
        const [orderConfigs, minhaLoja] = await Promise.all([this.customerPortalOrderActions.loadOrderConfigs.perform(), this.fetch.get('entregas/loja/minha-loja').catch(() => null)]);
        const loja = minhaLoja?.loja ?? null;
        this.customerPortalOrderCreation.start(orderConfigs);
        if (loja?.coleta) {
            this.customerPortalOrderCreation.setPayloadField('pickup', loja.coleta);
        }

        return { loja };
    }

    // ao sair do painel (fechar ou "Ver no mapa"), a lista de pedidos é relida na hora, com o pedido chamado nela
    resetController(controller, isExiting) {
        if (isExiting) {
            this.controllerFor('portal.orders').atualizarListaAgora?.();
        }
    }
}
