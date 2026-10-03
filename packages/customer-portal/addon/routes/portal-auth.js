import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { localeDoVisitante } from '../utils/locale-do-visitante';

export default class PortalAuthRoute extends Route {
    @service('universe/hook-service') hookService;
    @service session;
    @service hostRouter;
    @service currentUser;
    @service intl;

    /**
     * If user is authentication redirect to portal.
     *
     * @memberof LoginRoute
     * @void
     */
    beforeModel(transition) {
        this.session.prohibitAuthentication('customer-portal.portal');
        this.hookService.execute('customer-portal:auth:before-model', this.session, this.hostRouter, transition);
    }

    /**
     * Entregas: quem ainda não entrou vê as telas de acesso (login, esqueci a senha, redefinir senha...) em pt-BR.
     * Sem isto elas saem em inglês: o idioma só vem do usuário depois do login e, antes, o console cai em en-US.
     *
     * Tem de ser no `activate` e não no `beforeModel`: o `activate` da rota `application` do console
     * (initializeLocale, en-US sem usuário) roda depois de todos os `beforeModel` da transição e desfaria o
     * idioma. O `activate` desta rota, que é filha dela, roda depois e vale.
     *
     * @memberof PortalAuthRoute
     * @void
     */
    activate() {
        super.activate(...arguments);

        if (!this.session.isAuthenticated) {
            this.intl.setLocale(localeDoVisitante(this.currentUser.getOption('locale')));
        }
    }
}
