import Component from '@glimmer/component';
import { origemDoModelo } from '../../utils/pedido-de-origem';

/**
 * Entregas: selo da origem do pedido: "iFood #4821" (vermelho) ou "RestaurantePro #4821" (laranja escuro, pedido de
 * outro sistema pela integração). Aparece na lista, no quadro, no mapa e no cabeçalho do detalhe. Nos outros pedidos não
 * mostra nada. A regra (notas + internal_id) fica em utils/pedido-de-origem.js.
 */
export default class PedidoDeOrigemSeloComponent extends Component {
    get origem() {
        return origemDoModelo(this.args.order);
    }
}
