import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { ABAS_DA_TABELA } from '../../utils/tela-de-pedidos';

/** Entregas: abas da Tabela de pedidos (Todos, Em andamento, Sem motoboy, Concluídos, Cancelados). @aba = a atual. */
export default class TelaPedidosAbasComponent extends Component {
    @service intl;

    get abas() {
        return Object.keys(ABAS_DA_TABELA).map((chave) => ({ chave, texto: this.intl.t(`fleet-ops.ui.tela-pedidos.abas.${chave}`), ativa: this.args.aba === chave }));
    }
}
