import Route from '@ember/routing/route';

/**
 * Entregas RestaurantePro: Financeiro → Painel (a pagar, a receber, margem do período e a lista "Atenção").
 * Dados de `GET int/v1/entregas/financeiro/painel` (FinanceiroController@painel). Guarda de admin em routes/financeiro.js.
 */
export default class FinanceiroPainelRoute extends Route {
    setupController(controller) {
        super.setupController(...arguments);
        controller.carregar.perform();
    }

    resetController(controller, isExiting) {
        super.resetController(...arguments);
        if (isExiting) {
            controller.limpar();
        }
    }
}
