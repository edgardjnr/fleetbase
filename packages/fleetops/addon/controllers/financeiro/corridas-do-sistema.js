import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { formatadores, periodoAoAbrir, periodoDoAtalho } from '../../utils/financeiro';
import { chaveDaOrigem, dataHoraDoIso, linhasCsvDasCorridas, nomeDoCsv, precoDaCentral } from '../../utils/corridas-do-sistema';

const ENDPOINT = 'entregas/sistema';
const PREFIXO = 'fleet-ops.ui.financeiro.corridas-do-sistema';

/**
 * Entregas RestaurantePro: Financeiro → Corridas do sistema. Período (padrão: este mês), resumo, por central (preço
 * "variado" quando mudou no período, selo "sem preço"), preços por central (adicionar no modal preco-da-central, apagar
 * com confirmação), a lista das corridas e o CSV. Regras no servidor (App\Support\Entregas\Sistema).
 */
export default class FinanceiroCorridasDoSistemaController extends Controller {
    @service fetch;
    @service intl;
    @service notifications;
    @service modalsManager;

    @tracked inicio = periodoDoAtalho('this-month').inicio;
    @tracked fim = periodoDoAtalho('this-month').fim;
    @tracked relatorio = null;
    @tracked centraisDosPrecos = [];
    // atalho do período em uso (recalculado ao voltar à tela); null = datas escolhidas à mão
    atalho = 'this-month';

    get f() {
        return formatadores(this.intl.primaryLocale);
    }

    get total() {
        return this.relatorio?.total ?? { corridas: 0, a_cobrar: 0, sem_preco: 0 };
    }

    get centrais() {
        return this.relatorio?.centrais ?? [];
    }

    get corridas() {
        return this.relatorio?.corridas ?? [];
    }

    /** Na entrada da tela (setupController): o período do atalho em uso recalculado para hoje; datas à mão ficam. */
    atualizarPeriodo() {
        const periodo = periodoAoAbrir(this.atalho, { inicio: this.inicio, fim: this.fim });
        this.inicio = periodo.inicio;
        this.fim = periodo.fim;
    }

    limpar() {
        this.carregar.cancelAll();
        this.carregarPrecos.cancelAll();
        this.relatorio = null;
        this.centraisDosPrecos = [];
    }

    @action valor(numero) {
        return this.f.valor(numero);
    }

    @action dia(data) {
        return this.f.dia(data);
    }

    /** Data e hora da corrida pela hora escrita no ISO do servidor (-03:00), sem converter para o fuso do navegador. */
    @action dataHora(iso) {
        return dataHoraDoIso(iso);
    }

    @action origem(corrida) {
        return this.intl.t(chaveDaOrigem(corrida?.origem));
    }

    @action precoDaCentral(central) {
        const preco = precoDaCentral(central?.precos);
        if (preco === null) {
            return this.intl.t(`${PREFIXO}.sem-preco`);
        }

        return preco === 'variado' ? this.intl.t(`${PREFIXO}.variado`) : this.f.valor(preco);
    }

    @action precoDaCorrida(corrida) {
        return corrida?.preco === null || corrida?.preco === undefined ? this.intl.t(`${PREFIXO}.sem-preco`) : this.f.valor(corrida.preco);
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
        try {
            this.relatorio = yield this.fetch.get(`${ENDPOINT}/corridas`, { inicio: this.inicio, fim: this.fim });
        } catch (error) {
            // sem os dados de outro período na tela
            this.relatorio = null;
            this.notifications.serverError(error);
        }
    }

    @task({ restartable: true }) *carregarPrecos() {
        try {
            const resposta = yield this.fetch.get(`${ENDPOINT}/precos`);
            this.centraisDosPrecos = resposta?.centrais ?? [];
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /** Relê os preços e o relatório (o preço muda o valor das corridas do período). */
    @action recarregar() {
        this.carregarPrecos.perform();
        this.carregar.perform();
    }

    /**
     * Abre o modal do preço. Do botão de uma central vem o uuid dela (`fn this.novoPreco central.company`); do botão do
     * cabeçalho vem o evento do clique (o onClick e o `fn` acrescentam o evento como último argumento): só texto conta
     * como central.
     */
    @action novoPreco(company) {
        this.modalsManager.show('modals/preco-da-central', {
            centrais: this.centraisDosPrecos,
            company: typeof company === 'string' ? company : null,
            onSalvo: () => this.recarregar(),
        });
    }

    @action apagarPreco(central, preco) {
        this.modalsManager.confirm({
            title: this.intl.t(`${PREFIXO}.apagar-titulo`, { valor: this.f.valor(preco.valor), central: central.nome }),
            body: this.intl.t(`${PREFIXO}.apagar-texto`, { data: this.f.dia(preco.vale_a_partir_de) }),
            acceptButtonText: this.intl.t(`${PREFIXO}.apagar`),
            acceptButtonScheme: 'danger',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await this.fetch.delete(`${ENDPOINT}/precos/${preco.id}`);
                    this.notifications.success(this.intl.t(`${PREFIXO}.apagado-ok`));
                    modal.done();
                    this.recarregar();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    @action exportarCsv() {
        if (!this.relatorio) {
            return;
        }
        const linhas = linhasCsvDasCorridas(this.relatorio, (chave, opcoes) => this.intl.t(chave, opcoes));
        const blob = new Blob(['\uFEFF' + linhas.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = nomeDoCsv(this.relatorio.inicio, this.relatorio.fim);
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }
}
