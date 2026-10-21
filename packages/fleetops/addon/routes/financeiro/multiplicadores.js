import Route from '@ember/routing/route';

/**
 * Entregas RestaurantePro: Financeiro → Multiplicadores (períodos de chuva e afins). Dados de
 * `int/v1/entregas/financeiro/multiplicadores` (api/app/Http/Controllers/Entregas/MultiplicadoresController.php).
 */
export default class FinanceiroMultiplicadoresRoute extends Route {
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
