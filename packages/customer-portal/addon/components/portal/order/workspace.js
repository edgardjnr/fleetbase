import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { arrayFor, valueFor } from '../../../utils/model-access';
import { pedidosEmAndamento } from '../../../utils/entregas-pedido';

export default class PortalOrderWorkspaceComponent extends Component {
    @service hostRouter;
    @service customerPortalOrderCreation;

    get currentRouteName() {
        return this.hostRouter.currentRouteName ?? '';
    }

    get hasPanel() {
        return this.currentRouteName.includes('.new') || this.currentRouteName.includes('.details');
    }

    get isCreating() {
        return this.currentRouteName.includes('.new');
    }

    get isViewingDetails() {
        return this.currentRouteName.includes('.details');
    }

    // Entregas: a lista ao lado do mapa mostra só os pedidos em andamento; os concluídos e cancelados ficam na Tabela
    get pedidosDaLista() {
        return pedidosEmAndamento(arrayFor(this.args.orders), (pedido) => valueFor(pedido, 'status'));
    }

    get showTable() {
        return this.args.isTableView && !this.hasPanel;
    }

    @action search(event) {
        if (typeof this.args.onSearch === 'function') {
            this.args.onSearch(event.target.value);
        }
    }

    @action createOrder() {
        this.hostRouter.transitionTo('customer-portal.portal.orders.new');
    }

    @action reloadOrders() {
        if (typeof this.args.onReload === 'function') {
            this.args.onReload();
        }
    }
}
