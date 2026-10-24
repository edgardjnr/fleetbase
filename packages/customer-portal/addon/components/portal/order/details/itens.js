import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { itensDaResposta, observacaoDaResposta, totalDeItens } from '../../../../utils/itens-do-pedido';

// Entregas: os produtos do pedido (iFood ou sistema da loja), os mesmos que o motoboy vê na comanda do app, e a
// observação do cliente (decisão do Edgard de 2026-10-24). Lê GET int/v1/entregas/loja/pedidos/{id}/itens uma vez por
// pedido (@pedidoId): os itens não mudam depois da criação. Pedido feito no portal não tem itens: o painel some.
export default class PortalOrderDetailsItensComponent extends Component {
    @service fetch;
    @tracked itens = [];
    @tracked observacao = null;
    @tracked carregado = false;
    @tracked falhou = false;
    lido = null;

    get total() {
        return totalDeItens(this.itens);
    }

    get mostrar() {
        return !this.carregado || this.falhou || this.itens.length > 0 || this.observacao !== null;
    }

    @action async carregar() {
        const pedido = this.args.pedidoId;
        if (!pedido || pedido === this.lido) {
            return;
        }
        this.lido = pedido;
        this.carregado = false;
        this.falhou = false;
        this.itens = [];
        this.observacao = null;

        try {
            const resposta = await this.fetch.get(`entregas/loja/pedidos/${encodeURIComponent(pedido)}/itens`);
            if (this.lido !== pedido || this.isDestroyed) {
                return;
            }
            this.itens = itensDaResposta(resposta);
            this.observacao = observacaoDaResposta(resposta);
        } catch {
            if (this.lido !== pedido || this.isDestroyed) {
                return;
            }
            this.falhou = true;
            // tenta de novo na próxima vez que o detalhe passar por aqui
            this.lido = null;
        }
        this.carregado = true;
    }
}
