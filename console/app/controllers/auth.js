import Controller from '@ember/controller';
import { inject as service } from '@ember/service';

/**
 * Entregas: a tela de login é uma landing de página inteira; as outras telas de auth seguem no cartão centralizado.
 */
export default class AuthController extends Controller {
    @service router;

    get ehLanding() {
        return this.router.currentRouteName === 'auth.login';
    }
}
