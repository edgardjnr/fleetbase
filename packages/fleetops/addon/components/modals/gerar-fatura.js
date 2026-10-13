import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { validarGerarFatura } from '../../utils/financeiro-faturas';

const PREFIXO = 'fleet-ops.ui.financeiro-lojas';

/**
 * Entregas RestaurantePro: modal "Gerar fatura" (Financeiro → Lojas e faturas). Cria à mão o rascunho de uma loja num
 * período (POST int/v1/entregas/financeiro/faturas { loja, inicio, fim }), para o caso fora do ciclo. O servidor junta os
 * lançamentos da loja ainda sem fatura com data até o fim; sem lançamentos não cria (resposta sem fatura ou erro 422).
 *
 * Opções: `lojas` ([Loja] da tela), `loja` (id pré-escolhido ou null), `onGerada(fatura)`.
 * Escolher a loja preenche o período com o ciclo em andamento dela, enquanto as datas não forem mexidas.
 */
export default class ModalsGerarFaturaComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;

    @tracked loja = '';
    @tracked inicio = '';
    @tracked fim = '';
    @tracked datasMexidas = false;

    constructor(owner, { options }) {
        super(...arguments);
        this.options = options;
        this.escolherLoja(options.loja ?? '');
        this.prepararOpcoes();
    }

    get lojas() {
        return this.options.lojas ?? [];
    }

    get erros() {
        return validarGerarFatura({ loja: this.loja, inicio: this.inicio, fim: this.fim }).map((erro) => this.intl.t(`${PREFIXO}.erros.${erro}`));
    }

    escolherLoja(id) {
        this.loja = id;
        if (this.datasMexidas) {
            return;
        }

        const ciclo = this.lojas.find((loja) => loja.id === id)?.ciclo_atual;
        this.inicio = ciclo?.inicio ?? '';
        this.fim = ciclo?.fim ?? '';
    }

    prepararOpcoes() {
        this.options.title = this.intl.t(`${PREFIXO}.gerar-fatura`);
        this.options.acceptButtonText = this.intl.t(`${PREFIXO}.gerar-rascunho`);
        this.options.acceptButtonIcon = 'file-circle-plus';
        this.options.declineButtonText = this.intl.t(`${PREFIXO}.cancelar`);
        // o modal só fecha quando o rascunho é criado (sem lançamentos, fica aberto para trocar o período)
        this.options.keepOpen = true;
        this.options.confirm = async (modal, done) => {
            if (this.erros.length) {
                this.notifications.warning(this.erros[0]);
                return;
            }

            modal.startLoading();
            try {
                const resposta = await this.fetch.post('entregas/financeiro/faturas', { loja: this.loja, inicio: this.inicio, fim: this.fim });
                const fatura = resposta?.fatura ?? null;
                if (!fatura?.id) {
                    this.notifications.warning(this.intl.t(`${PREFIXO}.sem-lancamentos-no-periodo`));
                    modal.stopLoading();
                    return;
                }

                this.notifications.success(this.intl.t(`${PREFIXO}.rascunho-gerado`));
                done();
                this.options.onGerada?.(fatura);
            } catch (error) {
                this.notifications.serverError(error);
                modal.stopLoading();
            }
        };
        this.options.decline = (modal, done) => done();
    }

    @action setLoja(event) {
        this.escolherLoja(event.target.value);
    }

    @action setData(campo, event) {
        this[campo] = event.target.value;
        this.datasMexidas = true;
    }
}
