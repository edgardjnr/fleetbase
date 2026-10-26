import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class PortalController extends Controller {
    @service universe;
    @service session;
    @service currentUser;
    @service customerSession;
    @service intl;
    @service hostRouter;

    // Entregas: a barra fixa do Novo pedido no celular (sketch 009 S) fica fora da própria tela do novo pedido e da tela
    // Pedidos (a folha de baixo do mapa já tem o botão); no desktop o CSS a esconde e o botão fica no topo
    get mostrarBarraNovo() {
        return !(this.hostRouter.currentRouteName ?? '').startsWith('customer-portal.portal.orders');
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
