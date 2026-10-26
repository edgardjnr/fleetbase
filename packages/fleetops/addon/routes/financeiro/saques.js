import Route from '@ember/routing/route';

/**
 * Entregas RestaurantePro: Financeiro → Saques Pix (saques dos últimos 30 dias e os motoboys com chave Pix, com o
 * interruptor de bloqueio). Dados de `GET int/v1/entregas/financeiro/saques?situacao` (SaquePixController@saques). Aberta
 * ao admin do sistema e ao da organização (guarda em routes/financeiro.js). `?situacao=` vem dos avisos do painel.
 */
export default class FinanceiroSaquesRoute extends Route {
    queryParams = {
        situacao: { refreshModel: false },
    };

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
