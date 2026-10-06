import Component from '@glimmer/component';
import { numeroIfoodDoPedido } from '../../utils/pedido-ifood';

/**
 * Entregas: selo "iFood #4821" do pedido que veio do iFood (lista, quadro, mapa e cabeçalho do detalhe). Não mostra nada
 * nos outros pedidos. A regra (notas + internal_id) fica em utils/pedido-ifood.js.
 */
export default class PedidoIfoodSeloComponent extends Component {
    get numero() {
        return numeroIfoodDoPedido(this.args.order);
    }
}
