import Component from '@glimmer/component';
import { origemDoPedido } from '../../../utils/entregas-pedido';

/**
 * Entregas: selo da origem do pedido no portal da loja: "iFood #4821" (vermelho) ou "RestaurantePro #4821" (laranja
 * escuro, pedido que a loja mandou pelo sistema dela). Recebe @notas e @idInterno. Nos outros pedidos, nada.
 */
export default class PortalOrderSeloDeOrigemComponent extends Component {
    get origem() {
        return origemDoPedido(this.args.notas, this.args.idInterno);
    }
}
