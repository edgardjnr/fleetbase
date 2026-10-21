import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { campoDeData, campoDoInstante, corpoDoPeriodo, fatorDigitado, textoDoFator, validarPeriodo } from '../../utils/financeiro-multiplicadores';

const PREFIXO = 'fleet-ops.ui.financeiro.multiplicadores';
const ENDPOINT = 'entregas/financeiro/multiplicadores';

/**
 * Entregas RestaurantePro: modal do período de multiplicador (Financeiro → Multiplicadores). Novo (POST) ou edição (PUT
 * .../{id}); em andamento só o fim é editável (o servidor ignora o resto). Opções: `periodo` (null = novo), `inicio` e
 * `fim` sugeridos (campos 'YYYY-MM-DDTHH:MM', "Começar agora"), `onSalvo()`. Só fecha quando grava (keepOpen).
 */
export default class ModalsPeriodoMultiplicadorComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;

    @tracked nome = '';
    @tracked multMotoboy = '1,2';
    @tracked multLoja = '1';
    @tracked inicio = '';
    @tracked fim = '';

    constructor(owner, { options }) {
        super(...arguments);
        this.options = options;
        const periodo = options.periodo;
        if (periodo) {
            this.nome = periodo.nome;
            this.multMotoboy = String(periodo.mult_motoboy).replace('.', ',');
            this.multLoja = String(periodo.mult_loja).replace('.', ',');
            this.inicio = campoDoInstante(periodo.inicio);
            this.fim = campoDoInstante(periodo.fim);
        } else {
            this.inicio = options.inicio ?? '';
            this.fim = options.fim ?? '';
        }
        this.prepararOpcoes();
    }

    get soFim() {
        return this.options.periodo?.situacao === 'em_andamento';
    }

    get erros() {
        const dados = { nome: this.nome, multMotoboy: this.multMotoboy, multLoja: this.multLoja, inicio: this.inicio, fim: this.fim, soFim: this.soFim };

        return validarPeriodo(dados, campoDeData(new Date())).map((erro) => this.intl.t(`${PREFIXO}.erros.${erro}`));
    }

    /** "= ×1,2" ao lado do campo, como o "valor interpretado" dos outros modais. */
    get fatorMotoboy() {
        return textoDoFator(fatorDigitado(this.multMotoboy), this.intl.primaryLocale);
    }

    get fatorLoja() {
        return textoDoFator(fatorDigitado(this.multLoja), this.intl.primaryLocale);
    }

    prepararOpcoes() {
        const periodo = this.options.periodo;
        this.options.title = periodo ? this.intl.t(`${PREFIXO}.modal-editar`, { nome: periodo.nome }) : this.intl.t(`${PREFIXO}.modal-novo`);
        this.options.acceptButtonText = this.intl.t(`${PREFIXO}.salvar`);
        this.options.acceptButtonIcon = 'save';
        this.options.declineButtonText = this.intl.t(`${PREFIXO}.cancelar`);
        // só fecha quando grava (erro ou validação: fica aberto com o que foi digitado). Sem `return` no confirm: ver a
        // armadilha do modals-manager no packages/fleetops/CLAUDE.md
        this.options.keepOpen = true;
        this.options.confirm = async (modal, done) => {
            if (this.erros.length) {
                this.notifications.warning(this.erros[0]);
                return;
            }

            modal.startLoading();
            try {
                const corpo = corpoDoPeriodo({ nome: this.nome, multMotoboy: this.multMotoboy, multLoja: this.multLoja, inicio: this.inicio, fim: this.fim, soFim: this.soFim });
                if (periodo) {
                    await this.fetch.put(`${ENDPOINT}/${periodo.id}`, corpo);
                } else {
                    await this.fetch.post(ENDPOINT, corpo);
                }
                this.notifications.success(this.intl.t(`${PREFIXO}.salvo`));
                done();
                this.options.onSalvo?.();
            } catch (error) {
                this.notifications.serverError(error);
                modal.stopLoading();
            }
        };
        this.options.decline = (modal, done) => done();
    }

    @action setCampo(campo, event) {
        this[campo] = event.target.value;
    }
}
