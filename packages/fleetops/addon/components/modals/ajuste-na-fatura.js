import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { lerValor, validarAjuste } from '../../utils/financeiro-faturas';

const PREFIXO = 'fleet-ops.ui.financeiro-lojas';

/**
 * Entregas RestaurantePro: modal "+ Ajuste" no rascunho da fatura (desconto, taxa, mensalidade). Positivo cobra a mais,
 * negativo desconta. O motivo aparece para a loja (portal e e-mail); a observação interna não.
 * POST int/v1/entregas/financeiro/faturas/{id}/ajustes { valor, motivo, observacao_interna }.
 *
 * Opções: `fatura` (Fatura), `onSalvo()`.
 */
export default class ModalsAjusteNaFaturaComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;

    @tracked valor = '';
    @tracked motivo = '';
    @tracked observacao = '';

    constructor(owner, { options }) {
        super(...arguments);
        this.options = options;
        this.prepararOpcoes();
    }

    get erros() {
        return validarAjuste({ valor: this.valor, motivo: this.motivo }).map((erro) => this.intl.t(`${PREFIXO}.erros.${erro}`));
    }

    get valorEmReais() {
        const numero = lerValor(this.valor);
        return Number.isFinite(numero) && numero !== 0 ? new Intl.NumberFormat(this.intl.primaryLocale ?? 'pt-BR', { style: 'currency', currency: 'BRL' }).format(numero) : '';
    }

    get desconto() {
        return lerValor(this.valor) < 0;
    }

    prepararOpcoes() {
        const fatura = this.options.fatura;
        this.options.title = this.intl.t(`${PREFIXO}.ajuste-titulo`, { loja: fatura?.loja?.nome ?? '' });
        this.options.acceptButtonText = this.intl.t(`${PREFIXO}.adicionar-ajuste`);
        this.options.acceptButtonIcon = 'plus';
        this.options.declineButtonText = this.intl.t(`${PREFIXO}.cancelar`);
        // o modal só fecha quando o ajuste é gravado (erro ou validação: fica aberto com o que foi digitado)
        this.options.keepOpen = true;
        this.options.confirm = async (modal, done) => {
            if (this.erros.length) {
                this.notifications.warning(this.erros[0]);
                return;
            }

            modal.startLoading();
            try {
                const corpo = { valor: lerValor(this.valor), motivo: this.motivo.trim(), observacao_interna: this.observacao.trim() || null };
                await this.fetch.post(`entregas/financeiro/faturas/${fatura.id}/ajustes`, corpo);
                this.notifications.success(this.intl.t(`${PREFIXO}.ajuste-adicionado`));
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
