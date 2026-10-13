import Route from '@ember/routing/route';

export default class PortalFinanceiroRoute extends Route {
    // Entregas: a consulta (ciclo em andamento e faturas) começa ao abrir a tela; a loja vem da sessão do usuário
    setupController(controller) {
        super.setupController(...arguments);
        controller.carregar.perform();
    }

    // Entregas: ao sair, cancela a consulta (a resposta não deve virar aviso em outra tela) e limpa os dados, para a próxima
    // visita não mostrar faturas antigas enquanto carrega
    resetController(controller, isExiting) {
        super.resetController(...arguments);

        if (isExiting) {
            controller.reiniciar();
        }
    }
}
