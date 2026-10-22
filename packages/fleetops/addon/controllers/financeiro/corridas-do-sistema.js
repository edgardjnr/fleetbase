import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { schedule } from '@ember/runloop';
import { task } from 'ember-concurrency';
import { formatadores, isoDate, periodoAoAbrir, periodoDoAtalho } from '../../utils/financeiro';
import { chaveDaOrigem, dataHoraDoIso, extratoDaCentral, linhasCsvDasCorridas, nomeDoCsv, precoDaCentral, reais } from '../../utils/corridas-do-sistema';

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
    // extrato de uma central sendo impresso (o bloco [data-extrato-impressao] só existe enquanto preenchido)
    @tracked extrato = null;
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
        this.extrato = null;
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

    /**
     * Extrato de uso de uma central (para enviar a ela; o navegador oferece "Salvar como PDF"), no período da tela.
     * Do botão vem o uuid da central (`fn this.imprimirExtrato central.company`); o `fn` acrescenta o evento como último
     * argumento, então só texto conta. Lista cortada: não imprime (pede um período menor). Corridas sem preço: confirma
     * antes. Dados montados por extratoDaCentral (utils/corridas-do-sistema.js).
     */
    @action imprimirExtrato(company) {
        const extrato = typeof company === 'string' ? extratoDaCentral(this.relatorio, company) : null;
        if (!extrato) {
            return;
        }

        if (extrato.listaCortada) {
            this.notifications.warning(this.intl.t(`${PREFIXO}.extrato.lista-cortada`));
            return;
        }

        if (extrato.semPreco > 0) {
            this.modalsManager.confirm({
                title: this.intl.t(`${PREFIXO}.extrato.sem-preco-titulo`, { central: extrato.central }),
                body: this.intl.t(`${PREFIXO}.extrato.sem-preco-texto`, { count: extrato.semPreco }),
                acceptButtonText: this.intl.t(`${PREFIXO}.extrato.imprimir-mesmo-assim`),
                acceptButtonIcon: 'print',
                confirm: (modal) => {
                    modal.done();
                    this.mostrarEImprimir(extrato);
                },
            });
            return;
        }

        this.mostrarEImprimir(extrato);
    }

    /** Textos prontos do extrato (dinheiro sempre 'R$ 1.234,56') e a data de emissão (hoje, no fuso do navegador). */
    paraImpressao(extrato) {
        const [ano, mes, dia] = isoDate(new Date()).split('-');
        const precos = Array.isArray(extrato.precos) ? extrato.precos : [];
        let valorPorCorrida = ['—'];
        if (extrato.precoVariou) {
            valorPorCorrida = precos.map((preco) => this.intl.t(`${PREFIXO}.extrato.preco-vezes`, { valor: reais(preco.valor), count: preco.quantidade }));
        } else if (precos.length) {
            valorPorCorrida = [reais(precos[0].valor)];
        }

        return {
            ...extrato,
            emitidoEm: `${dia}/${mes}/${ano}`,
            valorPorCorrida,
            totalReais: reais(extrato.total),
            linhas: (extrato.linhas ?? []).map((linha) => ({ ...linha, valorReais: linha.valor === null || linha.valor === undefined ? '—' : reais(linha.valor) })),
        };
    }

    /**
     * Renderiza o bloco [data-extrato-impressao] (escondido na tela) e imprime pelo mesmo mecanismo da fatura: cópia do
     * bloco no <body> (.entregas-impressao-copia) e o CSS "entregas-imprimindo" (fleetops-engine.css) esconde o resto
     * do console. No afterprint a cópia sai e o bloco deixa de existir.
     */
    mostrarEImprimir(extrato) {
        this.extrato = this.paraImpressao(extrato);
        schedule('afterRender', () => {
            const original = document.querySelector('[data-extrato-impressao]');
            if (!original) {
                this.extrato = null;
                return;
            }

            // cópia de uma impressão anterior sem afterprint (fica escondida na tela, mas não pode sair duas vezes no papel)
            document.querySelectorAll('.entregas-impressao-copia').forEach((velha) => velha.remove());

            const copia = original.cloneNode(true);
            copia.classList.remove('hidden');
            copia.classList.add('entregas-impressao-copia');
            document.body.appendChild(copia);
            document.body.classList.add('entregas-imprimindo');

            const limpar = () => {
                copia.remove();
                document.body.classList.remove('entregas-imprimindo');
                window.removeEventListener('afterprint', limpar);
                this.extrato = null;
            };
            // o afterprint vem depois do diálogo, bloqueante ou não; a cópia fica com display:none fora da impressão
            window.addEventListener('afterprint', limpar);
            window.print();
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
