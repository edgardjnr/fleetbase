import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { registerDestructor } from '@ember/destroyable';
import { task, timeout } from 'ember-concurrency';
import { arrayFor, valueFor } from '../../../utils/model-access';

// Entregas: a mesma lista de encerrados do servidor (StatusDoPedido::ENCERRADOS)
const CLOSED_STATUSES = ['completed', 'done', 'canceled', 'cancelled', 'order_canceled', 'expired'];
// Entregas: a loja só cancela nestes status e antes do aceite (RegrasPortalLoja::STATUS_CANCELAVEIS)
const CANCELABLE_STATUSES = ['created', 'dispatched'];
// Entregas: intervalo do acompanhamento do pedido aberto
const INTERVALO_MS = 20000;

export default class PortalOrderDetailsComponent extends Component {
    @service customerPortalOrderActions;
    @service hostRouter;
    @service modalsManager;
    @service notifications;
    @service intl;

    @tracked order = this.args.model?.order;

    constructor() {
        super(...arguments);
        // Entregas: o detalhe se atualiza sozinho enquanto o pedido está aberto. O template da rota cria um componente
        // por pedido (templates/portal/orders/details.hbs), então o ciclo segue sempre o pedido exibido
        this.acompanhar.perform();
        registerDestructor(this, () => {
            this.acompanhar.cancelAll();
            this.recarregar.cancelAll();
        });
    }

    // Entregas: aberto = fora da lista de encerrados
    get isOpen() {
        return Boolean(this.order) && !CLOSED_STATUSES.includes(valueFor(this.order, 'status'));
    }

    // Entregas: cancelamento só antes do aceite (depois dele o servidor recusa com 422)
    get canCancel() {
        return Boolean(this.order) && !valueFor(this.order, 'started') && CANCELABLE_STATUSES.includes(valueFor(this.order, 'status'));
    }

    // Entregas: pedido aberto que a loja já não cancela; o aviso fica no topo do painel, logo abaixo das ações
    get cancelLocked() {
        return this.isOpen && !this.canCancel;
    }

    get labelUrl() {
        const files = arrayFor(valueFor(this.order, 'files'));
        const labelFile = files.find((file) => ['label', 'order_label', 'shipping_label'].includes(valueFor(file, 'type')) || valueFor(file, 'meta.type') === 'label');

        return valueFor(this.order, 'label_url') ?? valueFor(this.order, 'tracking_number.label_url') ?? valueFor(labelFile, 'url');
    }

    get actionButtons() {
        return [
            {
                icon: 'ellipsis-h',
                type: 'default',
                size: 'sm',
                renderInPlace: true,
                // Entregas: só a etiqueta e o cancelamento. Sem chamado de suporte e sem reagendar (rotas negadas à loja)
                items: [
                    {
                        text: this.intl.t('customer-portal.ui.order.view-label'),
                        icon: 'file-invoice',
                        disabled: !this.labelUrl,
                        fn: this.viewLabel,
                    },
                    {
                        separator: true,
                    },
                    {
                        text: this.intl.t('customer-portal.ui.order.cancel-order'),
                        icon: 'ban',
                        class: 'text-danger',
                        disabled: !this.canCancel,
                        fn: this.confirmCancelOrder,
                    },
                ],
            },
        ];
    }

    @action close() {
        this.hostRouter.transitionTo('customer-portal.portal.orders.index');
    }

    @action viewLabel() {
        if (!this.labelUrl) {
            this.notifications.info(this.intl.t('customer-portal.ui.order.no-label'));
            return;
        }

        window.open(this.labelUrl, '_blank', 'noopener,noreferrer');
    }

    @action async confirmCancelOrder() {
        // Entregas: o item fica desabilitado depois do aceite, mas o link ainda recebe Enter pelo teclado
        if (!this.canCancel) {
            return;
        }

        await this.modalsManager.confirm({
            title: this.intl.t('customer-portal.ui.order.cancel-title'),
            body: this.intl.t('customer-portal.ui.order.cancel-body'),
            acceptButtonText: this.intl.t('customer-portal.ui.order.cancel-order'),
            acceptButtonType: 'danger',
            confirm: this.cancelOrder,
        });
    }

    @action async cancelOrder() {
        try {
            this.order = await this.customerPortalOrderActions.cancelOrder.perform(this.order);
            this.notifications.success(this.intl.t('customer-portal.ui.order.canceled'));
        } catch (error) {
            // Entregas: a mensagem do servidor vem em pt-BR (422 depois do aceite, 409 com o pedido em atualização)
            this.notifications.serverError(error);
            // Entregas: o motoboy pode ter aceitado no meio; relê o pedido para o menu e o aviso mostrarem o estado atual
            this.recarregar.perform();
        }
    }

    // Entregas: o painel do motoboy viu mudar quem está com o pedido (aceite, desistência ou encerramento). Relê o
    // pedido agora, e não só no próximo ciclo; o painel espera esta leitura para mudar junto
    @action motoboyMudou() {
        return this.isOpen ? this.recarregar.perform() : null;
    }

    // Entregas: relê o pedido a cada 20 s até ele encerrar
    @task *acompanhar() {
        while (this.isOpen) {
            yield timeout(INTERVALO_MS);

            // o pedido pode ter encerrado durante a espera (cancelado nesta tela, por exemplo)
            if (this.isOpen) {
                yield this.recarregar.perform();
            }
        }
    }

    // Entregas: relê o pedido exibido. Erro (rede instável) não aparece na tela: tenta de novo no próximo ciclo
    @task *recarregar() {
        try {
            const order = yield this.customerPortalOrderActions.loadOrder.perform(this.customerPortalOrderActions.identifier(this.order));

            // o store.push já atualizou o record exibido no lugar; reatribuir o mesmo objeto recriaria o menu de ações
            // (e o fecharia, se estivesse aberto) a cada ciclo
            if (order && order !== this.order) {
                this.order = order;
            }
        } catch {
            // tenta de novo no próximo ciclo
        }
    }
}
