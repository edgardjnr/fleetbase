import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class PortalOrdersDetailsRoute extends Route {
    @service customerPortalOrderActions;

    async model({ id }) {
        const order = await this.customerPortalOrderActions.loadOrder.perform(id);
        return { order };
    }

    setupController(controller, model) {
        super.setupController(controller, model);
        this.controllerFor('portal.orders').setSelectedOrder(model.order);
    }

    resetController(controller, isExiting) {
        const lista = this.controllerFor('portal.orders');
        lista.clearSelectedOrder();

        // Entregas: com o detalhe aberto a atualização da lista fica pausada. Ao fechá-lo (isExiting; na troca de um pedido
        // por outro o detalhe segue aberto), a lista é atualizada na hora, e não só na próxima volta de até 30 s. Saindo da
        // página de pedidos, o reset da rota pai roda em seguida (o do filho vem antes) e cancela a task antes de qualquer consulta
        if (isExiting) {
            lista.atualizarListaAgora();
        }
    }
}
