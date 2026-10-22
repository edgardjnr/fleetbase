import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { formatadores, isoDate, valorInterpretado } from '../../utils/financeiro';
import { corpoDoPreco, validarPreco } from '../../utils/corridas-do-sistema';

const PREFIXO = 'fleet-ops.ui.financeiro.corridas-do-sistema';
const ENDPOINT = 'entregas/sistema/precos';

/**
 * Entregas RestaurantePro: modal "Novo preço por corrida" (Financeiro → Corridas do sistema): central, valor e "vale a
 * partir de". Opções: `centrais` ([{company, nome}] do GET sistema/precos), `company` (a central já escolhida, ou null)
 * e `onSalvo()`. Só fecha quando grava (keepOpen). Sem `return` no confirm: ver a armadilha do modals-manager no
 * packages/fleetops/CLAUDE.md.
 */
export default class ModalsPrecoDaCentralComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;

    @tracked company = null;
    @tracked valor = '';
    @tracked data = isoDate(new Date());

    constructor(owner, { options }) {
        super(...arguments);
        this.options = options;
        this.company = options.company ?? null;
        this.prepararOpcoes();
    }

    get centrais() {
        return this.options.centrais ?? [];
    }

    get erros() {
        return validarPreco({ company: this.company, valor: this.valor, data: this.data }).map((erro) => this.intl.t(`${PREFIXO}.erros.${erro}`));
    }

    /** "= R$ 2,50" ao lado do campo, como nos outros modais do Financeiro. */
    get valorInterpretado() {
        return valorInterpretado(this.valor, formatadores(this.intl.primaryLocale));
    }

    prepararOpcoes() {
        this.options.title = this.intl.t(`${PREFIXO}.modal-titulo`);
        this.options.acceptButtonText = this.intl.t(`${PREFIXO}.salvar`);
        this.options.acceptButtonIcon = 'save';
        this.options.declineButtonText = this.intl.t(`${PREFIXO}.cancelar`);
        // só fecha quando grava (erro ou validação: fica aberto com o que foi digitado)
        this.options.keepOpen = true;
        this.options.confirm = async (modal, done) => {
            if (this.erros.length) {
                this.notifications.warning(this.erros[0]);
                return;
            }

            modal.startLoading();
            try {
                await this.fetch.post(ENDPOINT, corpoDoPreco({ company: this.company, valor: this.valor, data: this.data }));
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
        const valor = event.target.value;
        this[campo] = campo === 'company' ? valor || null : valor;
    }
}
