import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import titleize from 'ember-cli-string-helpers/utils/titleize';

export default class LayoutResourcePanelHeaderComponent extends Component {
    @service intl;

    get modelName() {
        if (this.args.modelName) return this.args.modelName;
        const model = this.args.resource;
        const modelName = model?.constructor?.modelName ?? model?._internalModel?.modelName;
        if (!modelName) {
            return 'Resource';
        }

        // Entregas RestaurantePro: nome do recurso no idioma ativo ("Criar Pedido", e não "Criar Order") quando há a
        // chave resource.<model>; sem ela, fica o nome do model como antes
        if (this.intl.exists(`resource.${modelName}`)) {
            return this.intl.t(`resource.${modelName}`);
        }

        const normalized = modelName.replace(/[-_]/g, ' ');
        return titleize(normalized);
    }
}
