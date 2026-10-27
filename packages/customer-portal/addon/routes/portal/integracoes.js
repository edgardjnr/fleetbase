import Route from '@ember/routing/route';

export default class PortalIntegracoesRoute extends Route {
    // Entregas: `?sistema=ifood` abre direto a conexão daquele sistema (link que dá para mandar à loja). Sem `replace`:
    // o voltar do navegador (e do Android) volta da conexão para a lista
    // Entregas: a lista dos sistemas começa ao abrir a tela; a loja vem da sessão do usuário
    setupController(controller) {
        super.setupController(...arguments);
        controller.carregar.perform();
    }

    // Entregas: ao sair, cancela a consulta e limpa a lista (a próxima visita não mostra uma situação antiga)
    resetController(controller, isExiting) {
        super.resetController(...arguments);

        if (isExiting) {
            controller.reiniciar();
        }
    }
}
