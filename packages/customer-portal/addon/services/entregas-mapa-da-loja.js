import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { mesmaLista, motoboysDosPedidos } from '../utils/pedidos-no-mapa';

/**
 * Entregas: o motoboy de cada pedido da loja para os cards da lista da tela Pedidos. O mapa (Workspace::Map) já consulta
 * loja/motoboys a cada 5 s e entrega aqui a resposta; os cards só leem. Guarda só nome e situação por pedido (não as
 * posições), para os cards não redesenharem a cada volta. Sem mapa (Tabela ou outra tela), vazio.
 */
export default class EntregasMapaDaLojaService extends Service {
    @tracked porPedido = {};
    // quantos motoboys estão no mapa (as contagens da tela Pedidos, sketch 009 S)
    @tracked motoboysNoMapa = 0;

    atualizar(pedidos, motoboys) {
        const total = Array.isArray(motoboys) ? motoboys.length : 0;
        if (total !== this.motoboysNoMapa) {
            this.motoboysNoMapa = total;
        }

        const novo = motoboysDosPedidos(pedidos, motoboys);

        if (!mesmaLista(novo, this.porPedido)) {
            this.porPedido = novo;
        }
    }

    limpar() {
        this.porPedido = {};
        this.motoboysNoMapa = 0;
    }

    motoboyDoPedido(publicId) {
        return publicId ? (this.porPedido[publicId] ?? null) : null;
    }
}
