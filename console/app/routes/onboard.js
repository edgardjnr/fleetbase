import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class OnboardRoute extends Route {
    @service installation;
    @service router;

    /**
     * Entregas: a organização é criada só pela central; ninguém abre uma pelo /onboard (o botão "Criar uma nova conta" saiu
     * do login e a API recusa o POST onboard/create-account). Só a primeira instalação, sem nenhuma organização, segue para o
     * cadastro do administrador: é o mesmo `should-onboard` que a rota auth.login usa para mandar para cá. Em qualquer outro
     * caso, ou se a consulta falhar, volta ao login.
     *
     * @return {Transition|undefined}
     * @memberof OnboardRoute
     */
    async beforeModel() {
        try {
            const { notConfigured, shouldOnboard, transition } = await this.installation.checkOnboarding();

            if (notConfigured) {
                return transition;
            }

            if (shouldOnboard) {
                return;
            }
        } catch {
            // sem resposta da API, o cadastro continua fechado
        }

        return this.router.transitionTo('auth.login');
    }
}
