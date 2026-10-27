import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { contagem, estadoDoCartao, limparCodigo, linkSeguro, segundosRestantes, vencimentoDoCodigo } from '../../../utils/integracoes-da-loja';

const ENDPOINT = 'entregas/loja/integracoes/ifood';

/**
 * Entregas: a loja conecta o iFood sozinha (IntegracoesDaLojaController no servidor; o mesmo vínculo da tela Lojas):
 * 1. "Gerar código": o portal pede o código de conexão (POST …/ifood/codigo) e mostra o código grande, a contagem de
 *    10 min e o botão do Portal do Parceiro com o código já preenchido;
 * 2. a loja entra no Portal do Parceiro com a conta dela, autoriza o Entregas e o iFood mostra o código de autorização;
 * 3. a loja cola o código aqui e conecta (POST …/ifood/vincular). Conta com várias lojas: escolhe qual é esta.
 * Conectada, mostra a loja no iFood e o Desconectar (com confirmação na própria tela). O cartão novo volta pela
 * @onMudou.
 */
export default class PortalIntegracoesIfoodComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;

    // { codigo, link } do código de conexão atual; null antes de gerar
    @tracked codigo = null;
    @tracked vencimento = 0;
    @tracked agora = Date.now();
    @tracked autorizacao = '';
    // lojas da conta do iFood para escolher (vazio = não está escolhendo)
    @tracked lojasDoIfood = [];
    @tracked confirmandoSaida = false;

    willDestroy() {
        super.willDestroy(...arguments);
        clearInterval(this.relogio);
    }

    get estado() {
        return estadoDoCartao(this.args.integracao);
    }

    get conectada() {
        return this.estado === 'conectada';
    }

    get disponivel() {
        return Boolean(this.args.integracao?.disponivel);
    }

    get segundos() {
        return segundosRestantes(this.vencimento, this.agora);
    }

    get venceu() {
        return Boolean(this.codigo) && this.segundos === 0;
    }

    get tempo() {
        return contagem(this.segundos);
    }

    get escolhendo() {
        return this.lojasDoIfood.length > 0;
    }

    get podeConectar() {
        return Boolean(this.codigo) && !this.venceu && limparCodigo(this.autorizacao) !== '';
    }

    @action setAutorizacao(event) {
        this.autorizacao = event.target.value;
    }

    @action pedirConfirmacao() {
        this.confirmandoSaida = true;
    }

    @action cancelarSaida() {
        this.confirmandoSaida = false;
    }

    @task({ drop: true }) *gerarCodigo() {
        this.codigo = null;
        this.autorizacao = '';
        this.lojasDoIfood = [];

        try {
            const resposta = yield this.fetch.post(`${ENDPOINT}/codigo`);
            this.vencimento = vencimentoDoCodigo(resposta.expira_em_segundos, Date.now());
            this.agora = Date.now();
            this.codigo = { codigo: resposta.codigo, link: linkSeguro(resposta.link) };
            this.ligarRelogio();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *conectar() {
        if (!this.podeConectar) {
            return;
        }

        try {
            this.concluir(yield this.fetch.post(`${ENDPOINT}/vincular`, { authorizationCode: limparCodigo(this.autorizacao) }));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *escolher(lojaDoIfood) {
        try {
            this.concluir(yield this.fetch.post(`${ENDPOINT}/vincular`, { merchant_id: lojaDoIfood.id }));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *desconectar() {
        try {
            const resposta = yield this.fetch.delete(ENDPOINT);
            this.confirmandoSaida = false;
            this.reiniciar();
            this.args.onMudou?.(resposta?.integracao);
            this.notifications.success(this.intl.t('customer-portal.ui.entregas.integracoes.ifood.desconectada'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    concluir(resposta) {
        if (Array.isArray(resposta?.escolher)) {
            this.lojasDoIfood = resposta.escolher;
            return;
        }

        this.reiniciar();
        this.args.onMudou?.(resposta?.integracao);
        this.notifications.success(this.intl.t('customer-portal.ui.entregas.integracoes.ifood.conectada'));
    }

    reiniciar() {
        clearInterval(this.relogio);
        this.relogio = null;
        this.codigo = null;
        this.autorizacao = '';
        this.lojasDoIfood = [];
    }

    // a contagem do código só anda enquanto há um código válido na tela; vencido, o relógio para
    ligarRelogio() {
        clearInterval(this.relogio);
        this.relogio = setInterval(() => {
            this.agora = Date.now();
            if (this.segundos === 0) {
                clearInterval(this.relogio);
                this.relogio = null;
            }
        }, 1000);
    }
}
