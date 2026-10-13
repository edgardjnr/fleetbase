import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { FORMAS_DE_PAGAMENTO, avisoDeAdiantamento, camposDoPagamento, formatadores, isoDate, numeroDigitado, validarComprovante } from '../../utils/financeiro';

const ENDPOINT = 'entregas/financeiro/pagamentos';

/**
 * Entregas RestaurantePro: modal "Registrar pagamento" (conta do motoboy) ou "Registrar recebimento" (conta da loja, F2).
 * Só registra: o dinheiro sai e entra fora do sistema. Envia multipart para POST int/v1/entregas/financeiro/pagamentos
 * (campos de camposDoPagamento + o arquivo no campo "comprovante"); a resposta é { pagamento, saldo }.
 *
 * Opções: contaTipo ('motoboy' | 'loja'), contaId (public_id), nome, saldo (o valor sugerido é o saldo, se positivo) e
 * onFinish(options). O onFinish roda ao fechar de qualquer jeito; depois de registrar, options.registrado traz a resposta.
 *
 * O confirm não devolve promessa de propósito (o modals-manager fecharia o modal mesmo com erro): o modal só fecha
 * (done) depois de registrar.
 */
export default class ModalsRegistrarPagamentoComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;

    @tracked valor = '';
    @tracked forma = 'pix';
    @tracked data = isoDate(new Date());
    @tracked observacao = '';
    @tracked comprovante = null;
    @tracked tentou = false;

    constructor(owner, { options }) {
        super(...arguments);
        this.options = options;
        this.contaTipo = options.contaTipo === 'loja' ? 'loja' : 'motoboy';
        this.saldo = Number(options.saldo) || 0;
        if (this.saldo > 0) {
            this.valor = this.saldo.toFixed(2).replace('.', ',');
        }

        options.title = this.intl.t(`fleet-ops.ui.financeiro.pagar.titulo-${this.contaTipo}`);
        options.acceptButtonText = this.intl.t(`fleet-ops.ui.financeiro.pagar.confirmar-${this.contaTipo}`);
        options.acceptButtonIcon = 'check';
        options.confirm = (modal, done) => this.confirmar(modal, done);
    }

    get f() {
        return formatadores(this.intl.primaryLocale);
    }

    get nome() {
        return this.options.nome ?? '';
    }

    get formas() {
        return FORMAS_DE_PAGAMENTO.map((forma) => ({ valor: forma, chave: `fleet-ops.ui.financeiro.formas.${forma}` }));
    }

    get excedente() {
        return avisoDeAdiantamento(this.valor, this.saldo);
    }

    get erros() {
        const erros = [];
        if (!(numeroDigitado(this.valor) > 0)) {
            erros.push('pagamento-valor');
        }
        if (!this.data) {
            erros.push('pagamento-data');
        }
        const comprovante = validarComprovante(this.comprovante);
        if (comprovante) {
            erros.push(comprovante);
        }
        return erros;
    }

    get dicas() {
        const erros = this.tentou ? this.erros : this.erros.filter((codigo) => codigo.startsWith('comprovante-'));
        return erros.map((codigo) => this.intl.t(`fleet-ops.ui.financeiro.erros.${codigo}`));
    }

    @action valorFormatado(numero) {
        return this.f.valor(numero);
    }

    @action setCampo(campo, event) {
        this[campo] = event.target.value;
    }

    @action setComprovante(event) {
        this.comprovante = event.target.files?.[0] ?? null;
    }

    @action tirarComprovante() {
        this.comprovante = null;
    }

    confirmar(modal, done) {
        this.tentou = true;
        if (this.erros.length) {
            this.notifications.warning(this.dicas[0]);
            return;
        }

        this.enviar.perform(modal, done);
    }

    @task({ drop: true }) *enviar(modal, done) {
        const corpo = new FormData();
        for (const [nome, valor] of camposDoPagamento({
            contaTipo: this.contaTipo,
            contaId: this.options.contaId,
            valor: this.valor,
            forma: this.forma,
            data: this.data,
            observacao: this.observacao,
        })) {
            corpo.append(nome, valor);
        }
        if (this.comprovante) {
            corpo.append('comprovante', this.comprovante);
        }

        // multipart: o navegador põe o Content-Type com o boundary; o JSON padrão do fetch quebraria o envio
        // (por isso não o fetch.upload, que mantém o Content-Type: application/json)
        const headers = this.fetch.getHeaders();
        delete headers['Content-Type'];

        modal.startLoading();
        try {
            const resposta = yield this.fetch.request(ENDPOINT, 'POST', { body: corpo, headers });
            this.options.registrado = resposta;
            this.notifications.success(this.intl.t(`fleet-ops.ui.financeiro.pagar.registrado-${this.contaTipo}`));
            done();
        } catch (error) {
            modal.stopLoading();
            this.notifications.serverError(error);
        }
    }
}
