import Component from '@glimmer/component';

/**
 * Entregas RestaurantePro: modal que mostra a chave de integração recém-gerada (ent_live_…), uma vez só. O servidor
 * guarda só o hash: perdeu, gera outra. O clique copia (ClickToCopy). Fechar descarta a chave da tela.
 */
export default class ModalsChaveDaIntegracaoComponent extends Component {
    get chave() {
        return this.args.options?.chave ?? '';
    }

    get loja() {
        return this.args.options?.loja ?? {};
    }
}
