import Route from '@ember/routing/route';

/**
 * Entregas RestaurantePro: Financeiro → Tabela de km e relatório (a antiga "Pagamento e cobrança").
 * Dados de `int/v1/entregas/pagamento-motoboys` (api/app/Http/Controllers/Entregas/PagamentoMotoboysController.php).
 */
export default class FinanceiroRelatorioRoute extends Route {
    setupController(controller) {
        super.setupController(...arguments);
        controller.load.perform();
    }
}
