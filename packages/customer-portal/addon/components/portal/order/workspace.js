import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { arrayFor, valueFor } from '../../../utils/model-access';
import { pedidosEmAndamento } from '../../../utils/entregas-pedido';

export default class PortalOrderWorkspaceComponent extends Component {
    @service hostRouter;
    @service customerPortalOrderCreation;
    @service entregasConversas;

    // Entregas: o chat da loja com os motoboys acompanha a lista de conversas (o total de não lidas no botão Conversas)
    // enquanto a tela Pedidos existe; ao sair dela, as leituras param e a gaveta fecha
    constructor() {
        super(...arguments);
        this.entregasConversas.acompanharLista.perform();
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this.entregasConversas.encerrar();
    }

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
