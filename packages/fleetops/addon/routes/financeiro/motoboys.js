import Route from '@ember/routing/route';

/**
 * Entregas RestaurantePro: Financeiro → Motoboys (saldo, último pagamento e corridas no período de cada motoboy).
 * Dados de `GET int/v1/entregas/financeiro/motoboys` (FinanceiroController@motoboys). Guarda de admin em routes/financeiro.js.
 */
export default class FinanceiroMotoboysRoute extends Route {
    setupController(controller) {
        super.setupController(...arguments);
        controller.atualizarPeriodo();
        controller.carregar.perform();
    }

    resetController(controller, isExiting) {
        super.resetController(...arguments);
        if (isExiting) {
            controller.limpar();
        }
    }
}
