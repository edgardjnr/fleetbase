import Route from '@ember/routing/route';

export default class PortalCarteiraRoute extends Route {
    // Entregas: a consulta da carteira (crédito, Pix aguardando e últimas recargas) começa ao abrir a tela; a loja vem da
    // sessão do usuário
    setupController(controller) {
        super.setupController(...arguments);
        controller.carregar.perform();
    }

    // Entregas: ao sair, para a consulta do Pix e o relógio (nada de pergunta ao servidor nem aviso em outra tela) e limpa
    // os dados, para a próxima visita não mostrar um QR antigo enquanto carrega
    resetController(controller, isExiting) {
        super.resetController(...arguments);

        if (isExiting) {
            controller.reiniciar();
        }
    }
}
