import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action, set } from '@ember/object';
import { tracked } from '@glimmer/tracking';
import { race, restartableTask, timeout, waitForEvent } from 'ember-concurrency';
// Entregas: intervalo e espera crescente da atualização automática da lista
import { INTERVALO_LISTA_MS, espera } from '../../utils/entregas-pedido';

export default class PortalOrdersController extends Controller {
    @service customerPortalOrderActions;
    @service hostRouter;
    @service notifications;

    queryParams = ['view', 'query'];

    @tracked view = 'map';
    @tracked query = '';
    @tracked selectedOrder;

    get isTableView() {
        return this.view === 'table';
    }

    @action switchView(view) {
        this.view = view;
    }

    @action async search(query) {
        this.query = query;

        try {
            const orders = await this.customerPortalOrderActions.searchOrders.perform(query);

            // Entregas: o model é um objeto simples; a atribuição direta não avisava o template (só o set() avisa).
            // Vale só o resultado da busca mais recente
            if (this.query === query) {
                set(this.model, 'orders', orders);
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action setSelectedOrder(order) {
        this.selectedOrder = order;
    }

    @action clearSelectedOrder() {
        this.selectedOrder = null;
    }

    @action async reloadOrders() {
        const query = this.query;

        try {
            const orders = await this.customerPortalOrderActions.loadOrders.perform({ query });

            // Entregas: set() avisa o template; o resultado vale só se a busca não mudou no meio
            if (this.query === query) {
                set(this.model, 'orders', orders);
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    // Entregas: a lista se atualiza sozinha a cada 30 s, com a busca ativa (a rota inicia no setupController e para no
    // resetController), numa task própria do serviço (o botão de recarregar não pisca). Pula a vez com a aba oculta,
    // com o detalhe aberto (a lista nem aparece; o detalhe tem o ciclo dele) e durante uma busca. A busca do usuário
    // vence: o resultado é descartado se a busca ou a lista mudaram durante a consulta. Erro não vira aviso: a espera
    // dobra a cada falha seguida (429 inclusive), até 2 min, e volta a 30 s no primeiro sucesso
    @restartableTask *atualizarLista() {
        let falhas = 0;
        let oculta = false;

        while (!this.isDestroying) {
            const proxima = timeout(espera(INTERVALO_LISTA_MS, falhas));
            // a aba estava oculta na última vez: atualiza assim que ela aparece
            yield oculta ? race([proxima, waitForEvent(document, 'visibilitychange')]) : proxima;

            oculta = document.hidden;
            if (oculta || this.hostRouter.currentRouteName?.endsWith('.details') || this.customerPortalOrderActions.searchOrders.isRunning) {
                continue;
            }

            const model = this.model;
            const query = this.query;

            try {
                const orders = yield this.customerPortalOrderActions.atualizarPedidos.perform({ query });
                falhas = 0;

                if (model && this.model === model && this.query === query && !this.customerPortalOrderActions.searchOrders.isRunning) {
                    set(model, 'orders', orders);
                }
            } catch {
                falhas++;
            }
        }
    }
}
