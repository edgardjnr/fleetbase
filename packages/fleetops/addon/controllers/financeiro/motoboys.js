import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { formatadores, periodoDoAtalho } from '../../utils/financeiro';

const ENDPOINT = 'entregas/financeiro/motoboys';

/**
 * Entregas RestaurantePro: lista de motoboys do Financeiro. O saldo é de agora (positivo = a pagar, negativo =
 * adiantado); corridas e valor são do período. Marcar vários motoboys lança a mesma diária (ou bônus…) para todos.
 * Clicar na linha abre o extrato (financeiro.motoboy).
 */
export default class FinanceiroMotoboysController extends Controller {
    @service fetch;
    @service intl;
    @service notifications;
    @service modalsManager;
    @service hostRouter;

    @tracked inicio = periodoDoAtalho('this-month').inicio;
    @tracked fim = periodoDoAtalho('this-month').fim;
    @tracked motoboys = [];
    @tracked carregado = false;
    // ids (public_id) marcados para "Lançar para os selecionados"
    @tracked selecionados = [];

    get f() {
        return formatadores(this.intl.primaryLocale);
    }

    get todosSelecionados() {
        return this.motoboys.length > 0 && this.selecionados.length === this.motoboys.length;
    }

    get totais() {
        return this.motoboys.reduce(
            (totais, motoboy) => ({
                aPagar: totais.aPagar + Math.max(Number(motoboy.saldo) || 0, 0),
                adiantado: totais.adiantado + Math.max(-(Number(motoboy.saldo) || 0), 0),
                corridas: totais.corridas + (Number(motoboy.corridas) || 0),
                valorCorridas: totais.valorCorridas + (Number(motoboy.valor_corridas) || 0),
            }),
            { aPagar: 0, adiantado: 0, corridas: 0, valorCorridas: 0 }
        );
    }

    limpar() {
        this.carregar.cancelAll();
        this.motoboys = [];
        this.carregado = false;
        this.selecionados = [];
    }

    @action valor(numero) {
        return this.f.valor(numero);
    }

    @action dia(data) {
        return this.f.diaCurto(data);
    }

    @action selecionado(id) {
        return this.selecionados.includes(id);
    }

    @action setData(campo, event) {
        this[campo] = event.target.value;
    }

    @action setPeriodo(atalho) {
        const periodo = periodoDoAtalho(atalho);
        if (periodo) {
            this.inicio = periodo.inicio;
            this.fim = periodo.fim;
            this.carregar.perform();
        }
    }

    @action alternar(id) {
        this.selecionados = this.selecionados.includes(id) ? this.selecionados.filter((atual) => atual !== id) : [...this.selecionados, id];
    }

    @action alternarTodos() {
        this.selecionados = this.todosSelecionados ? [] : this.motoboys.map((motoboy) => motoboy.id);
    }

    /** Clique na linha abre o extrato; clique no checkbox, botão ou link da linha não. */
    @action abrirLinha(motoboy, event) {
        if (event?.target?.closest?.('button, input, a, label')) {
            return;
        }
        this.hostRouter.transitionTo('console.fleet-ops.financeiro.motoboy', motoboy.id);
    }

    @action pagar(motoboy) {
        this.modalsManager.show('modals/registrar-pagamento', {
            contaTipo: 'motoboy',
            contaId: motoboy.id,
            nome: motoboy.nome,
            saldo: motoboy.saldo,
            onFinish: (opcoes) => {
                if (opcoes?.registrado) {
                    this.carregar.perform();
                }
            },
        });
    }

    @action lancarParaSelecionados() {
        const contas = this.motoboys.filter((motoboy) => this.selecionados.includes(motoboy.id)).map((motoboy) => ({ id: motoboy.id, nome: motoboy.nome }));
        if (!contas.length) {
            return;
        }

        this.modalsManager.show('modals/lancar-no-financeiro', {
            contaTipo: 'motoboy',
            contas,
            onFinish: (opcoes) => {
                if (opcoes?.lancado) {
                    this.selecionados = [];
                    this.carregar.perform();
                }
            },
        });
    }

    @task({ restartable: true }) *carregar() {
        if (!this.inicio || !this.fim) {
            return;
        }

        try {
            const resposta = yield this.fetch.get(ENDPOINT, { inicio: this.inicio, fim: this.fim });
            this.motoboys = resposta?.motoboys ?? [];
            // quem saiu da lista sai da seleção
            this.selecionados = this.selecionados.filter((id) => this.motoboys.some((motoboy) => motoboy.id === id));
            this.carregado = true;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
