import Component from '@glimmer/component';
import { cached } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { ESPERANDO_MOTOBOY, nomeDoCliente, origemDoPedido } from '../../../utils/entregas-pedido';
import { TIPO_DO_BADGE, cronometroDoPedido } from '../../../utils/entregas-cronometro';
import { capaceteDaSituacao } from '../../../utils/motoboys-no-mapa';

/**
 * Entregas: card do pedido na lista da tela Pedidos (coluna da esquerda), no desenho do sketch 002 ("A compacto"):
 * linha 1 com o número do pedido (o do iFood ou do sistema da loja; pedido feito no portal, o código de rastreio), a
 * origem e a situação; linha 2 com o motoboy (capacete na cor da situação) ou "procurando motoboy", o cliente, a hora e o
 * cronômetro do prazo de entrega (relógio com fundo verde no prazo, amarelo a 15 min ou menos, vermelho "Atrasado há
 * N min"; meta.entregas_prazo_em, gravado pelo servidor; some no encerrado e sem prazo). Sem os endereços: a entrega
 * fica no detalhe e no alfinete do mapa.
 */
export default class PortalOrderListCardComponent extends Component {
    @service entregasMapaDaLoja;
    @service entregasRelogio;
    @service intl;

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

    // o motoboy vem da consulta do mapa (só depois do aceite); antes dele, o pedido criado ou despachado espera um motoboy
    get motoboy() {
        return this.entregasMapaDaLoja.motoboyDoPedido(this.order.public_id);
    }

    get capacete() {
        return capaceteDaSituacao(this.motoboy?.situacao);
    }

    get procurandoMotoboy() {
        return !this.motoboy && ESPERANDO_MOTOBOY.includes(this.order.status);
    }

    // cronômetro do prazo de entrega: o relógio (serviço entregas-relogio) anda a cada 30 s. @cached: o template lê
    // cronometro (no if e no texto) e tipoDoPrazo; a conta roda uma vez por tique do relógio
    @cached
    get cronometro() {
        return cronometroDoPedido(this.order, this.entregasRelogio.agora, {
            faltam: (n) => this.intl.t('customer-portal.ui.entregas.card.prazo-faltam', { n }),
            atrasado: (n) => this.intl.t('customer-portal.ui.entregas.card.prazo-atrasado', { n }),
        });
    }

    // só é lido com o cronômetro presente (if no template), então o estado sempre tem tipo
    get tipoDoPrazo() {
        return TIPO_DO_BADGE[this.cronometro?.estado];
    }
}
