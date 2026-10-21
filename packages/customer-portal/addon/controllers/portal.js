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

    // Entregas: um menu só, em todas as telas: a gaveta Portal::Gaveta, aberta pelo ☰ da barra superior (sketch 003, B).
    // A coluna fixa do menu saiu (2026-10-21)
    @tracked gavetaAberta = false;

    constructor() {
        super(...arguments);
        // a gaveta fecha ao trocar de tela (clique num item do menu, inclusive o da própria tela)
        this.hostRouter.on('routeDidChange', () => {
            this.gavetaAberta = false;
        });
    }

    @action alternarMenu() {
        this.gavetaAberta = !this.gavetaAberta;
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
