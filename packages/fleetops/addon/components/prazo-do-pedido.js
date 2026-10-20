import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { TIPO_DO_BADGE, cronometroDoPedido } from '../utils/entregas-cronometro';

/**
 * Entregas: cronômetro do prazo de entrega do pedido (meta.entregas_prazo_em, gravado pelo servidor na criação; ver
 * App\Support\Entregas\PrazoDaEntrega). Badge verde com mais de 15 min, amarelo com 15 min ou menos e vermelho
 * "Atrasado há N min". Some no pedido encerrado e no pedido sem prazo (criado antes do deploy). O relógio é o serviço
 * entregas-relogio (30 s). Usado na coluna "Prazo" da tabela (cell/prazo-do-pedido) e no cabeçalho do detalhe
 * (order/panel-header). A lista recebe o prazo pelo IncluirNotasNaListaDePedidos (o recurso enxuto não traz o meta).
 */
export default class PrazoDoPedidoComponent extends Component {
    @service intl;
    @service entregasRelogio;

    get cronometro() {
        return cronometroDoPedido(this.args.order, this.entregasRelogio.agora, {
            faltam: (n) => this.intl.t('fleet-ops.ui.cronometro.faltam', { n }),
            atrasado: (n) => this.intl.t('fleet-ops.ui.cronometro.atrasado', { n }),
        });
    }

    get tipo() {
        return TIPO_DO_BADGE[this.cronometro?.estado] ?? 'info';
    }
}
