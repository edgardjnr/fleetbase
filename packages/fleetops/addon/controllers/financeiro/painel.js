import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { formatadores, itemDeAtencao, periodoAoAbrir, periodoDoAtalho } from '../../utils/financeiro';

const ENDPOINT = 'entregas/financeiro/painel';

/**
 * Entregas RestaurantePro: painel do Financeiro. Os saldos (a pagar, adiantado, a receber, vencido, não faturado) são de
 * agora; a margem é do período escolhido (padrão: este mês). Formato "Painel" do contrato (plano do financeiro, 1.5).
 */
export default class FinanceiroPainelController extends Controller {
    @service fetch;
    @service intl;
    @service notifications;

    @tracked inicio = periodoDoAtalho('this-month').inicio;
    @tracked fim = periodoDoAtalho('this-month').fim;
    @tracked painel = null;
    // atalho do período em uso (recalculado ao voltar à tela); null = datas escolhidas à mão
    atalho = 'this-month';

    get f() {
        return formatadores(this.intl.primaryLocale);
    }

    get periodo() {
        return this.painel?.periodo ?? { cobrado: 0, pago_motoboys: 0, margem: 0 };
    }

    get atencao() {
        const f = this.f;
        return (this.painel?.atencao ?? []).map((item) => itemDeAtencao(item, f)).filter(Boolean);
    }

    /** Na entrada da tela (setupController): o período do atalho em uso recalculado para hoje; datas à mão ficam. */
    atualizarPeriodo() {
        const periodo = periodoAoAbrir(this.atalho, { inicio: this.inicio, fim: this.fim });
        this.inicio = periodo.inicio;
        this.fim = periodo.fim;
    }

    /** Há pedido concluído sem valor na lista: o painel explica como lançar a corrida depois de corrigir. */
    get temSemValor() {
        return this.atencao.some((item) => item.tipo === 'sem_valor');
    }

    limpar() {
        this.carregar.cancelAll();
        this.painel = null;
    }

    @action valor(numero) {
        return this.f.valor(numero);
    }

    @action setData(campo, event) {
        this[campo] = event.target.value;
        this.atalho = null;
    }

    @action setPeriodo(atalho) {
        const periodo = periodoDoAtalho(atalho);
        if (periodo) {
            this.atalho = atalho;
            this.inicio = periodo.inicio;
            this.fim = periodo.fim;
            this.carregar.perform();
        }
    }

    @task({ restartable: true }) *carregar() {
        if (!this.inicio || !this.fim) {
            return;
        }

        try {
            this.painel = yield this.fetch.get(ENDPOINT, { inicio: this.inicio, fim: this.fim });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
