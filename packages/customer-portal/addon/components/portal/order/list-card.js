import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { CANCELAVEIS, nomeDoCliente, origemDoPedido } from '../../../utils/entregas-pedido';
import { capaceteDaSituacao } from '../../../utils/motoboys-no-mapa';

/**
 * Entregas: card do pedido na lista da tela Pedidos (coluna da esquerda), no desenho do sketch 002 ("A compacto"):
 * linha 1 com o número do pedido (o do iFood ou do sistema da loja; pedido feito no portal, o código de rastreio), a
 * origem e a situação; linha 2 com o motoboy (capacete na cor da situação) ou "procurando motoboy", o cliente, a hora e o
 * tempo até a entrega. Sem os endereços: a entrega fica no detalhe e no alfinete do mapa.
 */
export default class PortalOrderListCardComponent extends Component {
    @service entregasMapaDaLoja;

    get order() {
        return this.args.order ?? {};
    }

    get identifier() {
        return this.order.public_id ?? this.order.id;
    }

    get trackingNumber() {
        return this.order.tracking_number?.tracking_number ?? this.order.tracking_number ?? this.order.public_id ?? this.order.id;
    }

    get origem() {
        return origemDoPedido(this.order.notes, this.order.internal_id);
    }

    get numero() {
        return this.origem?.numero ?? null;
    }

    get cliente() {
        return nomeDoCliente(this.order.payload?.dropoff);
    }

    get hora() {
        return this.order.scheduled_at ?? this.order.created_at;
    }

    // o motoboy vem da consulta do mapa (só depois do aceite); antes dele, o pedido ainda cancelável espera um motoboy
    get motoboy() {
        return this.entregasMapaDaLoja.motoboyDoPedido(this.order.public_id);
    }

    get capacete() {
        return capaceteDaSituacao(this.motoboy?.situacao);
    }

    get procurandoMotoboy() {
        return !this.motoboy && CANCELAVEIS.includes(this.order.status);
    }

    get trackingSummary() {
        const tracking = this.order.tracker_data ?? this.order.tracking_data ?? this.order.tracking ?? {};
        return tracking.summary ?? tracking;
    }

    get etaLabel() {
        return (
            this.order.eta ??
            this.order.eta_at ??
            this.order.estimated_arrival_at ??
            this.trackingSummary.eta ??
            this.trackingSummary.estimated_arrival_at ??
            this.trackingSummary.destination_eta
        );
    }
}
