import { action } from '@ember/object';
import TelaDePedidosBase from '../../utils/tela-de-pedidos-base';
import { CAPACETES } from '../../utils/entregas-capacete';
import { colunasDoQuadro, horaLocal } from '../../utils/tela-de-pedidos';

const P = 'fleet-ops.ui.tela-pedidos';

/**
 * Entregas: vista Quadro da tela de pedidos (/fleet-ops?layout=kanban; sketch 005, variante A). Colunas Sem motoboy,
 * Indo à loja, A caminho (os pedidos em andamento, na ordem da fila) e Concluídos (os de hoje, os 6 mais recentes).
 * Clique no cartão abre o pedido; no sem motoboy, o admin atribui ali mesmo.
 */
export default class TelaPedidosQuadroComponent extends TelaDePedidosBase {
    get colunas() {
        const c = colunasDoQuadro(this.aoVivo.pedidos, this.agora);
        const linha = (pedido) => this.linhaDoPedido(pedido);
        return [
            { chave: 'oferta', titulo: this.intl.t(`${P}.quadro.oferta`), icone: 'tower-broadcast', pedidos: c.oferta.map(linha), alerta: c.oferta.length > 0 },
            { chave: 'loja', titulo: this.intl.t(`${P}.quadro.loja`), capacete: CAPACETES.coleta, pedidos: c.loja.map(linha) },
            { chave: 'rota', titulo: this.intl.t(`${P}.quadro.rota`), capacete: CAPACETES.entrega, pedidos: c.rota.map(linha) },
        ];
    }

    get concluidos() {
        const dados = this.aoVivo.concluidos ?? { total: 0, ultimos: [] };
        return {
            total: dados.total ?? 0,
            ultimos: (dados.ultimos ?? []).map((pedido) => ({
                ...pedido,
                numeroTexto: /^\d+$/.test(String(pedido.numero ?? '')) ? `#${pedido.numero}` : pedido.numero,
                texto: this.intl.t(`${P}.quadro.entregue`, { hora: horaLocal(pedido.finalizada_em) }),
            })),
        };
    }

    @action verConcluidos() {
        this.args.aoVerConcluidos?.();
    }
}
