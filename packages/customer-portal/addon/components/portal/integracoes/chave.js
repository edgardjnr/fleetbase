import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task, timeout } from 'ember-concurrency';
import { estadoDoCartao } from '../../../utils/integracoes-da-loja';

const BASE = 'customer-portal.ui.entregas.integracoes';

/** Enquanto a chave espera o outro sistema conectar, a tela relê a situação a cada 10 s (com a aba à vista). */
const INTERVALO_MS = 10000;

/**
 * Entregas: a loja conecta um sistema que manda os pedidos pela chave da loja (fluxo `chave`, hoje o RestaurantePro;
 * IntegracoesDaLojaController no servidor):
 * 1. "Gerar chave" (POST entregas/loja/integracoes/<id>/chave): a chave aparece uma vez, para copiar;
 * 2. a loja cola a chave no outro sistema (onde colar: tradução `…sistemas.<id>.onde-colar`);
 * 3. o outro sistema conecta e o cartão vira Conectada (a tela relê sozinha enquanto espera).
 * Gerar outra chave e desconectar pedem confirmação na própria tela: a chave anterior para de funcionar na hora.
 */
export default class PortalIntegracoesChaveComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;

    // a chave em texto, só logo depois de gerar (o servidor não devolve de novo)
    @tracked chave = null;
    // 'gerar' (gerar outra chave) | 'desconectar' | null
    @tracked confirmando = null;

    constructor() {
        super(...arguments);
        this.acompanhar.perform();
    }

    get id() {
        return this.args.integracao?.id ?? '';
    }

    get nome() {
        return this.args.integracao?.nome || this.id;
    }

    get estado() {
        return estadoDoCartao(this.args.integracao);
    }

    get disponivel() {
        return Boolean(this.args.integracao?.disponivel);
    }

    get ondeColar() {
        const chave = `${BASE}.sistemas.${this.id}.onde-colar`;

        return this.intl.exists(chave) ? this.intl.t(chave) : this.intl.t(`${BASE}.chave.onde-colar-padrao`, { sistema: this.nome });
    }

    get sistemaConectado() {
        return this.args.integracao?.sistema || this.nome;
    }

    @action pedirConfirmacao(qual) {
        this.confirmando = qual;
    }

    @action cancelarConfirmacao() {
        this.confirmando = null;
    }

    @action fecharChave() {
        this.chave = null;
    }

    @task({ drop: true }) *gerar() {
        try {
            const resposta = yield this.fetch.post(`entregas/loja/integracoes/${this.id}/chave`);
            this.confirmando = null;
            this.chave = resposta?.chave ?? null;
            this.args.onMudou?.(resposta?.integracao);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *desconectar() {
        try {
            const resposta = yield this.fetch.delete(`entregas/loja/integracoes/${this.id}/chave`);
            this.confirmando = null;
            this.chave = null;
            this.args.onMudou?.(resposta?.integracao);
            this.notifications.success(this.intl.t(`${BASE}.chave.desconectada`, { sistema: this.nome }));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    // enquanto aguarda o outro sistema, relê a lista; ao conectar, avisa uma vez
    @task *acompanhar() {
        while (!this.isDestroying) {
            yield timeout(INTERVALO_MS);
            if (this.estado !== 'aguardando' || document.hidden || !this.args.onAtualizar) {
                continue;
            }
            yield this.args.onAtualizar();
            if (this.estado === 'conectada') {
                this.chave = null;
                this.notifications.success(this.intl.t(`${BASE}.chave.conectada`, { sistema: this.sistemaConectado }));
            }
        }
    }
}
