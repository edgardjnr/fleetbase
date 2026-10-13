import Route from '@ember/routing/route';

export default class PortalFaturaRoute extends Route {
    // Entregas: o :id é o uuid da fatura. A consulta fica no controller (como no extrato) para o erro virar aviso e a volta
    // ao Financeiro, em vez da tela de erro da rota
    model(params) {
        return { id: params.id };
    }

    setupController(controller, model) {
        super.setupController(...arguments);
        controller.carregar.perform(model.id);
    }

    resetController(controller, isExiting) {
        super.resetController(...arguments);

        if (isExiting) {
            controller.reiniciar();
        }
    }
}
