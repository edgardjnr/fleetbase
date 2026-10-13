import Route from '@ember/routing/route';

/**
 * Entregas RestaurantePro: Financeiro → extrato de um motoboy (:id = public_id do Driver).
 * Dados de `GET int/v1/entregas/financeiro/motoboys/{id}/extrato` (FinanceiroController@extratoDoMotoboy).
 */
export default class FinanceiroMotoboyRoute extends Route {
    model({ id }) {
        return { id };
    }

    setupController(controller, model) {
        super.setupController(...arguments);
        controller.abrir(model.id);
    }

    resetController(controller, isExiting) {
        super.resetController(...arguments);
        if (isExiting) {
            controller.limpar();
        }
    }
}
