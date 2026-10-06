import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { ACOES_IFOOD, ENCERRADOS, ehPedidoIfood, mostraTroco, numeroIfoodDoPedido, partesDaForma, reais } from '../../../utils/pedido-ifood';

/**
 * Entregas: painel "iFood" no detalhe do pedido (só pedidos do iFood; rota GET int/v1/entregas/pedidos/{id}/ifood, só
 * administradores: para os demais o painel nem aparece, nem carrega). Mostra a última ação aceita pelo iFood, a última recusa, a cobrança na porta, o código de entrega,
 * observações, complemento e referência, e o cancelamento pelo iFood. "Liberar sem código" é a saída da central quando o
 * motoboy não consegue o código do cliente (POST .../ifood/liberar-sem-codigo): o app conclui pelo fluxo comum e o
 * iFood conclui sozinho 4 h depois. As ações chegam pelo código (assignDriver…) e são traduzidas aqui.
 */
export default class OrderDetailsIfoodComponent extends Component {
    @service fetch;
    @service currentUser;
    @service intl;
    @service notifications;
    @service modalsManager;
    @tracked painel = null;
    @tracked erro = false;
    // o pedido do último carregamento (não rastreado: só o `recarregar` lê e grava)
    ultimoId = null;

    get ehIfood() {
        return ehPedidoIfood(this.args.resource);
    }

    // só administrador vê (e carrega) o painel: a rota do servidor é só de admin e os demais veriam só o aviso de erro
    get mostrar() {
        return this.ehIfood && this.currentUser.isAdmin === true;
    }

    get numero() {
        return numeroIfoodDoPedido(this.args.resource);
    }

    get id() {
        return encodeURIComponent(this.args.resource?.public_id ?? this.args.resource?.id ?? '');
    }

    get acaoTexto() {
        const acao = this.painel?.ultima_acao;

        return acao ? this.textoDaAcao(acao) : this.intl.t('fleet-ops.ui.ifood.nenhuma-acao');
    }

    get recusaTexto() {
        const recusa = this.painel?.recusa;
        if (!recusa) {
            return null;
        }

        return this.intl.t('fleet-ops.ui.ifood.recusa', { acao: this.textoDaAcao(recusa.acao), status: recusa.status || '—' });
    }

    get cobrancaTexto() {
        const painel = this.painel;
        if (!painel?.cobrar_centavos) {
            return this.intl.t('fleet-ops.ui.ifood.pago-online');
        }
        const partes = [reais(painel.cobrar_centavos)];
        const formas = partesDaForma(painel.forma_pagamento).map((parte) => (parte.chave ? this.intl.t(`fleet-ops.ui.ifood.forma.${parte.chave}`) : parte.texto));
        if (formas.length) {
            partes.push(formas.join(' + '));
        }
        if (mostraTroco(painel.cobrar_centavos, painel.forma_pagamento, painel.troco_para_centavos)) {
            partes.push(this.intl.t('fleet-ops.ui.ifood.troco', { valor: reais(painel.troco_para_centavos, true) }));
        }

        return partes.join(' · ');
    }

    get codigoTexto() {
        const painel = this.painel;
        if (!painel?.exige_codigo) {
            return this.intl.t('fleet-ops.ui.ifood.codigo-nao-exigido');
        }
        if (painel.conclusao_sem_codigo) {
            return this.intl.t('fleet-ops.ui.ifood.codigo-dispensado');
        }

        return painel.conclusao_liberada_em ? this.intl.t('fleet-ops.ui.ifood.codigo-conferido') : this.intl.t('fleet-ops.ui.ifood.codigo-pendente');
    }

    get podeLiberar() {
        const painel = this.painel;

        return Boolean(painel?.exige_codigo && !painel.conclusao_liberada_em && !painel.cancelado_pelo_ifood_em && !ENCERRADOS.includes(this.args.resource?.status));
    }

    textoDaAcao(acao) {
        return this.intl.t(`fleet-ops.ui.ifood.acao.${ACOES_IFOOD.includes(acao) ? acao : 'desconhecida'}`);
    }

    /**
     * O Glimmer reaproveita o componente quando o @resource muda (outro pedido com o detalhe aberto, ou o refresh do socket
     * que devolve a mesma instância): carrega na entrada e a cada mudança de id, status ou atualização do pedido. Trocou de
     * pedido: zera o painel antes, para não mostrar os dados do anterior.
     */
    @action recarregar() {
        const id = this.id;
        if (id !== this.ultimoId) {
            this.ultimoId = id;
            this.painel = null;
            this.erro = false;
        }
        if (this.mostrar) {
            this.carregar.perform();
        }
    }

    @task({ restartable: true }) *carregar() {
        try {
            this.painel = yield this.fetch.get(`entregas/pedidos/${this.id}/ifood`);
            this.erro = false;
        } catch (error) {
            this.erro = true;
        }
    }

    @action liberarSemCodigo() {
        this.modalsManager.confirm({
            title: this.intl.t('fleet-ops.ui.ifood.liberar-titulo', { numero: this.numero }),
            body: this.intl.t('fleet-ops.ui.ifood.liberar-texto'),
            acceptButtonText: this.intl.t('fleet-ops.ui.ifood.liberar'),
            acceptButtonIcon: 'unlock',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    this.painel = await this.fetch.post(`entregas/pedidos/${this.id}/ifood/liberar-sem-codigo`);
                    this.notifications.success(this.intl.t('fleet-ops.ui.ifood.liberado'));
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }
}
