import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { enqueueTask, race, task, timeout, waitForEvent } from 'ember-concurrency';
import { arrayFor, valueFor } from '../../../utils/model-access';
// Entregas: status, intervalos e situação do motoboy numa lista só, espelho da API
import { CANCELAVEIS, ENCERRADOS, INTERVALO_MS, VOLTAS_DETALHE, espera, situacao } from '../../../utils/entregas-pedido';

export default class PortalOrderDetailsComponent extends Component {
    @service customerPortalOrderActions;
    @service fetch;
    @service hostRouter;
    @service modalsManager;
    @service notifications;
    @service intl;

    @tracked order = this.args.model?.order;
    // Entregas: o motoboy da última resposta 2xx (null = ninguém chamado ainda) e se ela já veio (antes, o carregando)
    @tracked motoboy = null;
    @tracked motoboyConsultado = false;

    constructor() {
        super(...arguments);
        // Entregas: acompanha o pedido enquanto ele está aberto (o EC cancela o ciclo quando o componente sai da tela).
        // O template da rota cria um componente por pedido (templates/portal/orders/details.hbs), então o ciclo segue
        // sempre o pedido exibido
        this.acompanhar.perform();
    }

    // Entregas: aberto = fora da lista de encerrados
    get isOpen() {
        return Boolean(this.order) && !ENCERRADOS.includes(valueFor(this.order, 'status'));
    }

    // Entregas: o detalhe (relido) já mostra o pedido aceito. O painel do motoboy usa isto para não dizer "aguardando"
    // enquanto a consulta do motoboy ainda não trouxe o aceite (por exemplo, se a consulta depois da releitura falhou)
    get aceito() {
        return Boolean(valueFor(this.order, 'started'));
    }

    // Entregas: cancelamento só antes do aceite (depois dele o servidor recusa com 422)
    get canCancel() {
        return Boolean(this.order) && !valueFor(this.order, 'started') && CANCELAVEIS.includes(valueFor(this.order, 'status'));
    }

    // Entregas: pedido aberto que a loja já não cancela; o aviso fica no topo do painel, logo abaixo das ações
    get cancelLocked() {
        return this.isOpen && !this.canCancel;
    }

    // Entregas: public_id ou uuid (o id do record); a rota do motoboy aceita os dois
    get pedidoId() {
        return valueFor(this.order, 'public_id') ?? valueFor(this.order, 'id');
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
        }

        // Entregas: relê o pedido, na fila das releituras do ciclo. Cancelado: uma releitura do ciclo que já estava em curso
        // (lida antes do cancelamento) não fica por último no store. Recusado: o menu e o aviso mostram o estado atual
        this.recarregar.perform();
    }

    // Entregas: um ciclo só. A cada volta (20 s) consulta o motoboy, que é barato. O detalhe completo (15 relações e o
    // tracker, que pode pedir rota ao OSRM) é relido só quando muda quem está com o pedido, a cada VOLTAS_DETALHE voltas
    // (~60 s, para ETA e atividade) e depois de aba oculta ou de falha. As leituras vão em sequência, e o painel muda
    // junto com o detalhe relido. Em falha (429 inclusive), a espera dobra até 2 min e o painel mantém o último motoboy.
    // Com a aba oculta, não consulta nada, mas o ciclo continua e volta assim que a aba aparece. Encerrado, o ciclo para
    @task *acompanhar() {
        let falhas = 0; // falhas seguidas
        let volta = 0; // voltas com a aba visível
        let detalheAtrasado = false; // aba oculta ou releitura com falha: relê o detalhe na próxima volta

        while (this.isOpen) {
            const oculta = document.hidden;

            if (oculta) {
                detalheAtrasado = true;
            } else {
                let motoboy = yield this.consultarMotoboy(); // undefined em erro
                let ok = motoboy !== undefined;
                const mudou = ok && this.motoboyConsultado && situacao(motoboy) !== situacao(this.motoboy);

                if (mudou || detalheAtrasado || volta % VOLTAS_DETALHE === VOLTAS_DETALHE - 1) {
                    const releu = yield this.recarregar.perform();
                    detalheAtrasado = !releu;
                    ok = ok && releu;

                    // o aceite caiu entre as duas leituras: o detalhe já mostra o pedido aceito e o motoboy, lido antes, não.
                    // Relê o motoboy, para o painel mudar junto com o status. Só com o pedido ainda aberto: se a releitura o
                    // mostrou encerrado, o painel some e o servidor devolve null, então a consulta seria à toa
                    if (ok && this.isOpen && this.aceito && situacao(motoboy) !== 'aceito') {
                        const relido = yield this.consultarMotoboy();
                        ok = relido !== undefined;
                        motoboy = ok ? relido : motoboy;
                    }
                }

                // mudou quem está com o pedido, mas o detalhe não foi relido: o painel fica como está, para mudar junto
                // com o detalhe na próxima volta
                if (motoboy !== undefined && !(mudou && detalheAtrasado)) {
                    this.motoboy = motoboy;
                    this.motoboyConsultado = true;
                }

                falhas = ok ? 0 : falhas + 1;
                volta++;
            }

            if (this.isOpen) {
                const proxima = timeout(espera(INTERVALO_MS, falhas));
                // com a aba oculta, a próxima volta começa assim que ela aparece
                yield oculta ? race([proxima, waitForEvent(document, 'visibilitychange')]) : proxima;
            }
        }
    }

    // Entregas: o motoboy do pedido (GET int/v1/entregas/loja/pedidos/{id}/motoboy): o motoboy, null (ninguém chamado
    // ainda; também para pedido encerrado) ou undefined em erro (rede, 429) ou resposta fora do formato
    async consultarMotoboy() {
        try {
            const resposta = await this.fetch.get(`entregas/loja/pedidos/${encodeURIComponent(this.pedidoId)}/motoboy`);

            return resposta?.motoboy;
        } catch {
            return undefined;
        }
    }

    // Entregas: relê o pedido exibido e devolve se conseguiu. Em fila: a releitura pedida pelo cancelamento nunca corre
    // junto com a do ciclo (respostas fora de ordem deixariam um status velho no store). Sem restartable, drop ou
    // keepLatest aqui: o ciclo faz yield desta task, e o EC cancelaria o ciclo junto
    @enqueueTask *recarregar() {
        try {
            const order = yield this.customerPortalOrderActions.loadOrder.perform(this.customerPortalOrderActions.identifier(this.order));

            // o store.push já atualizou o record exibido no lugar; reatribuir o mesmo objeto recriaria o menu de ações
            // (e o fecharia, se estivesse aberto) a cada releitura
            if (order && order !== this.order) {
                this.order = order;
            }

            return true;
        } catch {
            return false;
        }
    }
}
