import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { ehAdminDaCentral, rotaDoAdminDaCentral } from '../utils/financeiro-saque-pix';

/**
 * Entregas RestaurantePro: Financeiro (painel, motoboys, saques Pix, lojas e faturas, tabela de km e relatório,
 * configurações). Só administradores, como a API (int/v1/entregas/financeiro/*, negarSeNaoAdmin). Exceção do saque Pix:
 * o administrador da organização (papel Administrator, AdminDaCentral na API) entra em Saques Pix e Configurações (só o
 * card do saque); o resto continua só com o administrador do sistema.
 *
 * A guarda roda ao entrar no Financeiro. Entre as telas filhas ela não roda de novo: o menu só mostra ao admin da
 * organização as duas telas dele, e a API nega o resto (403).
 */
export default class FinanceiroRoute extends Route {
    @service notifications;
    @service hostRouter;
    @service currentUser;
    @service intl;

    beforeModel(transition) {
        if (this.currentUser.isAdmin) {
            return;
        }
        if (ehAdminDaCentral(this.currentUser) && rotaDoAdminDaCentral(transition?.to?.name)) {
            return;
        }
        this.notifications.warning(this.intl.t('fleet-ops.ui.financeiro.admin-only'));
        return this.hostRouter.transitionTo('console.fleet-ops');
    }
}
