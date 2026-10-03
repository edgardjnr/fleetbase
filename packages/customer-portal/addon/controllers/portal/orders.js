import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action, set } from '@ember/object';
import { tracked } from '@glimmer/tracking';
import { restartableTask, timeout } from 'ember-concurrency';

// Entregas: intervalo da atualização automática da lista de pedidos
const INTERVALO_LISTA_MS = 30000;

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
    // resetController). A busca do usuário vence: o ciclo pula enquanto há uma busca em andamento e descarta o
    // resultado se a busca ou a lista mudaram durante a consulta. Erro não vira aviso: tenta de novo no próximo ciclo
    @restartableTask *atualizarLista() {
        while (!this.isDestroying) {
            yield timeout(INTERVALO_LISTA_MS);

            if (this.customerPortalOrderActions.searchOrders.isRunning) {
                continue;
            }

            const model = this.model;
            const query = this.query;

            try {
                const orders = yield this.customerPortalOrderActions.loadOrders.perform({ query });

                if (model && this.model === model && this.query === query && !this.customerPortalOrderActions.searchOrders.isRunning) {
                    set(model, 'orders', orders);
                }
            } catch {
                // rede instável: sem aviso a cada ciclo
            }
        }
    }
}
