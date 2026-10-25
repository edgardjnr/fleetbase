import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { formatadores } from '../../utils/financeiro';

/** Entregas: legenda da rota do pedido aberto no mapa (feito, falta, até a loja, prevista) e os km do OSRM. */
export default class TelaPedidosLegendaDaRotaComponent extends Component {
    @service intl;

    km = (metros) => formatadores(this.intl.primaryLocale).km(metros / 1000).replace(/,(\d)\d$/, ',$1');
}
