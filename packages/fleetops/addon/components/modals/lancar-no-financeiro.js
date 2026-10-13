import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { aceitaNegativo, isoDate, numeroDigitado, rotuloDoTipo, tiposDaConta, validarLancamento } from '../../utils/financeiro';

const ENDPOINT = 'entregas/financeiro/lancamentos';

/**
 * Entregas RestaurantePro: modal "Lançar" do Financeiro (diária, bônus, ajuste ou saldo inicial do motoboy; ajuste ou
 * saldo inicial da loja), para uma ou várias contas de uma vez (POST int/v1/entregas/financeiro/lancamentos).
 *
 * Opções: contaTipo ('motoboy' | 'loja'), contas ([{ id, nome }], id = public_id), tipo (opcional, já escolhido) e
 * onFinish(options). O onFinish roda ao fechar de qualquer jeito; depois de lançar, options.lancado traz a resposta
 * ({ lancamentos }) — quem abriu relê a tela só nesse caso.
 *
 * O confirm não devolve promessa de propósito: o modals-manager fecharia o modal ao fim dela mesmo com erro. O modal
 * fica aberto com o que foi digitado quando a validação ou o servidor recusam, e fecha (done) só depois de lançar.
 */
export default class ModalsLancarNoFinanceiroComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;

    @tracked tipo;
    @tracked valor = '';
    @tracked data = isoDate(new Date());
    @tracked pedido = '';
    @tracked motivo = '';
    @tracked observacao = '';
    // as dicas de erro só aparecem depois da primeira tentativa (ou de começar a digitar o valor)
    @tracked tentou = false;

    constructor(owner, { options }) {
        super(...arguments);
        this.options = options;
        this.contaTipo = options.contaTipo === 'loja' ? 'loja' : 'motoboy';
        this.contas = options.contas ?? [];
        const tipos = tiposDaConta(this.contaTipo);
        this.tipo = tipos.includes(options.tipo) ? options.tipo : tipos[0];

        options.title = this.intl.t(`fleet-ops.ui.financeiro.lancar.titulo-${this.contaTipo}`);
        options.acceptButtonText = this.intl.t('fleet-ops.ui.financeiro.lancar.confirmar');
        options.acceptButtonIcon = 'check';
        options.confirm = (modal, done) => this.confirmar(modal, done);
    }

    get tipos() {
        return tiposDaConta(this.contaTipo).map((tipo) => ({ valor: tipo, chave: rotuloDoTipo(tipo) }));
    }

    get paraQuem() {
        if (this.contas.length === 1) {
            return this.intl.t('fleet-ops.ui.financeiro.lancar.para-um', { nome: this.contas[0].nome });
        }

        return this.intl.t(`fleet-ops.ui.financeiro.lancar.para-varios-${this.contaTipo}`, { count: this.contas.length });
    }

    get aceitaPedido() {
        return this.tipo === 'ajuste';
    }

    get aceitaNegativo() {
        return aceitaNegativo(this.tipo);
    }

    get erros() {
        return validarLancamento({
            contaTipo: this.contaTipo,
            tipo: this.tipo,
            valor: this.valor,
            contas: this.contas.map((conta) => conta.id),
            pedido: this.aceitaPedido ? this.pedido : '',
        });
    }

    get dicas() {
        if (!this.tentou && String(this.valor).trim() === '') {
            return [];
        }

        return this.erros.map((codigo) => this.intl.t(`fleet-ops.ui.financeiro.erros.${codigo}`));
    }

    @action setCampo(campo, event) {
        this[campo] = event.target.value;
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
        const texto = (valor) => String(valor ?? '').trim() || null;
        const corpo = {
            conta_tipo: this.contaTipo,
            contas: this.contas.map((conta) => conta.id),
            tipo: this.tipo,
            valor: Math.round(numeroDigitado(this.valor) * 100) / 100,
            data: this.data,
            pedido: this.aceitaPedido ? texto(this.pedido) : null,
            motivo: texto(this.motivo),
            observacao_interna: texto(this.observacao),
        };

        modal.startLoading();
        try {
            const resposta = yield this.fetch.post(ENDPOINT, corpo);
            this.options.lancado = resposta;
            this.notifications.success(this.intl.t('fleet-ops.ui.financeiro.lancar.lancado', { count: resposta?.lancamentos?.length ?? corpo.contas.length }));
            done();
        } catch (error) {
            modal.stopLoading();
            this.notifications.serverError(error);
        }
    }
}
