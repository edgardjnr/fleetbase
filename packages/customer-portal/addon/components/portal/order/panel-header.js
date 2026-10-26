import Component from '@glimmer/component';
import { cached } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { format } from 'date-fns';
import dateFnsLocaleOptions from '@fleetbase/ember-core/utils/date-fns-locale';
import { nomeDoCliente, origemDoPedido } from '../../../utils/entregas-pedido';
import { TIPO_DO_BADGE, cronometroDoPedido } from '../../../utils/entregas-cronometro';

export default class PortalOrderPanelHeaderComponent extends Component {
    @service entregasRelogio;
    @service intl;

    get resource() {
        return this.args.resource ?? {};
    }

    get trackingNumber() {
        return this.resource.tracking_number?.tracking_number ?? this.resource.tracking ?? this.resource.public_id ?? this.resource.id;
    }

    // Entregas: o número do iFood ou do sistema da loja (o mesmo do card); pedido feito no portal fica com o rastreio
    get numero() {
        return origemDoPedido(this.resource.notes, this.resource.internal_id)?.numero ?? null;
    }

    get cliente() {
        return nomeDoCliente(this.resource.payload?.dropoff);
    }

    get createdAt() {
        return this.resource.createdAt ?? format(new Date(this.resource.created_at), 'dd/MM HH:mm', dateFnsLocaleOptions()) ?? '-';
    }

    @cached
    get cronometro() {
        return cronometroDoPedido(this.resource, this.entregasRelogio.agora, {
            faltam: (n) => this.intl.t('customer-portal.ui.entregas.card.prazo-faltam', { n }),
            atrasado: (n) => this.intl.t('customer-portal.ui.entregas.card.prazo-atrasado', { n }),
        });
    }

    get tipoDoPrazo() {
        return TIPO_DO_BADGE[this.cronometro?.estado];
    }
}
