import Route from '@ember/routing/route';

export default class PortalExtratoRoute extends Route {
    // Entregas: a consulta começa ao abrir a tela, no período que o controller tiver (mês corrente até hoje)
    setupController(controller) {
        super.setupController(...arguments);
        controller.carregar.perform();
    }

    // Entregas: ao sair a tela volta ao estado inicial. Cancela a consulta (a resposta não deve virar aviso em outra
    // tela), limpa os dados e recoloca o período no mês corrente, para a próxima visita não abrir com o "hoje" de outro dia
    resetController(controller, isExiting) {
        super.resetController(...arguments);

        if (isExiting) {
            controller.reiniciar();
        }
    }
}
