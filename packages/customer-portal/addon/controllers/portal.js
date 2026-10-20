import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';

export default class PortalController extends Controller {
    @service universe;
    @service session;
    @service currentUser;
    @service customerSession;
    @service intl;
    @service hostRouter;

    // Entregas: na tela Pedidos a coluna do menu fica com a lista de pedidos e o menu vira uma gaveta (☰); nas outras telas
    // o ☰ esconde ou mostra a coluna do menu. Sketch 001, variante B
    @tracked gavetaAberta = false;
    @tracked menuEscondido = false;

    constructor() {
        super(...arguments);
        // a gaveta fecha ao trocar de tela (clique num item do menu, inclusive o da própria tela Pedidos)
        this.hostRouter.on('routeDidChange', () => {
            this.gavetaAberta = false;
        });
    }

    get naTelaPedidos() {
        return (this.hostRouter.currentRouteName ?? '').startsWith('customer-portal.portal.orders');
    }

    get menuVisivel() {
        return this.naTelaPedidos ? this.gavetaAberta : !this.menuEscondido;
    }

    @action alternarMenu() {
        if (this.naTelaPedidos) {
            this.gavetaAberta = !this.gavetaAberta;
        } else {
            this.menuEscondido = !this.menuEscondido;
        }
    }

    @action fecharGaveta() {
        this.gavetaAberta = false;
    }

    get accountName() {
        return this.customerSession.get('name') || this.currentUser.name;
    }

    get accountTypeLabel() {
        return this.customerSession.accountType === 'vendor' ? this.intl.t('customer-portal.ui.account.company-account') : this.intl.t('customer-portal.ui.account.customer-account');
    }

    get accountEmail() {
        return this.customerSession.get('email') || this.currentUser.email;
    }

    /**
     * Action to invalidate and log user out
     *
     * @void
     */
    @action invalidateSession() {
        this.session.invalidateWithLoader();
    }
}
