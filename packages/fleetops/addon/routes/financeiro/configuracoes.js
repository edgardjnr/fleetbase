import Route from '@ember/routing/route';

/**
 * Entregas RestaurantePro: Financeiro → Configurações (data de corte e ciclo padrão de cobrança das lojas).
 * Dados de `GET/PUT int/v1/entregas/financeiro/configuracoes` (FinanceiroController@configuracoes/salvarConfiguracoes).
 */
export default class FinanceiroConfiguracoesRoute extends Route {
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
