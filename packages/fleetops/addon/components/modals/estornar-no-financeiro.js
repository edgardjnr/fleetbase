import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { formatadores, rotuloDoTipo } from '../../utils/financeiro';

/**
 * Entregas RestaurantePro: confirmação do "Estornar" de uma linha do extrato. Nada se apaga: o estorno cria o lançamento
 * oposto e os dois ficam no extrato.
 * - pagamento/recebimento → POST int/v1/entregas/financeiro/pagamentos/{pagamento.id}/estornar (marca o pagamento e o saldo volta);
 * - os demais → POST int/v1/entregas/financeiro/lancamentos/{id}/estornar { motivo } (motivo visível ao motoboy/loja).
 *
 * Opções: lancamento (formato "Lancamento" do contrato) e onFinish(options); depois de estornar, options.estornado = true.
 * O confirm não devolve promessa (o modals-manager fecharia o modal mesmo com erro); fecha só depois de estornar.
 */
export default class ModalsEstornarNoFinanceiroComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;

    @tracked motivo = '';

    constructor(owner, { options }) {
        super(...arguments);
        this.options = options;
        this.lancamento = options.lancamento ?? {};

        options.title = this.intl.t(this.ehPagamento ? `fleet-ops.ui.financeiro.estornar.titulo-${this.lancamento.tipo}` : 'fleet-ops.ui.financeiro.estornar.titulo');
        options.acceptButtonText = this.intl.t('fleet-ops.ui.financeiro.estornar.confirmar');
        options.acceptButtonIcon = 'rotate-left';
        options.acceptButtonScheme = 'danger';
        // sem "return": a TaskInstance é "thenable" e o modals-manager fecharia o modal ao fim dela, mesmo com erro
        options.confirm = (modal, done) => {
            this.estornar.perform(modal, done);
        };
    }

    get ehPagamento() {
        return this.lancamento.tipo === 'pagamento' || this.lancamento.tipo === 'recebimento';
    }

    get descricao() {
        const f = formatadores(this.intl.primaryLocale);
        return this.intl.t('fleet-ops.ui.financeiro.estornar.linha', {
            tipo: this.intl.t(rotuloDoTipo(this.lancamento.tipo)),
            valor: f.valor(this.lancamento.valor),
            data: f.dia(this.lancamento.data),
        });
    }

    @action setMotivo(event) {
        this.motivo = event.target.value;
    }

    @task({ drop: true }) *estornar(modal, done) {
        modal.startLoading();
        try {
            if (this.ehPagamento) {
                yield this.fetch.post(`entregas/financeiro/pagamentos/${this.lancamento.pagamento.id}/estornar`);
            } else {
                yield this.fetch.post(`entregas/financeiro/lancamentos/${this.lancamento.id}/estornar`, { motivo: this.motivo.trim() || null });
            }
            this.options.estornado = true;
            this.notifications.success(this.intl.t('fleet-ops.ui.financeiro.estornar.feito'));
            done();
        } catch (error) {
            modal.stopLoading();
            this.notifications.serverError(error);
        }
    }
}
