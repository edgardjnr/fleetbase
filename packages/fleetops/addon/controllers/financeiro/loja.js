import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { formatadores } from '../../utils/financeiro';
import { agruparLancamentosPorDia, corDaSituacao, dataBr, linhasCsvDoExtratoDaLoja, podeEstornarNaLoja } from '../../utils/financeiro-faturas';

const ENDPOINT = 'entregas/financeiro';
const PREFIXO = 'fleet-ops.ui.financeiro-lojas';
const TIPOS = ['corrida', 'diaria', 'bonus', 'ajuste', 'saldo_inicial', 'pagamento', 'recebimento', 'estorno'];
const SITUACOES = ['pago', 'parcial', 'aberto', 'anulado'];
const FORMAS = ['pix', 'dinheiro', 'transferencia', 'outro'];
const pad = (n) => String(n).padStart(2, '0');
// data local 'Y-m-d' (nunca toISOString, que vira UTC)
const isoDate = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
const inicioDoMes = () => isoDate(new Date(new Date().getFullYear(), new Date().getMonth(), 1));

/**
 * Entregas RestaurantePro: extrato da conta de uma loja no Financeiro. Crédito = a loja me deve (corrida, ajuste
 * positivo, saldo inicial); débito = recebimento, desconto, estorno. A coluna "Fatura" liga o lançamento à fatura em que
 * entrou (sem fatura = ainda não faturado, entra no próximo rascunho).
 * Botões: Registrar recebimento e Lançar ajuste / saldo inicial (modais da tela de motoboys, com contaTipo 'loja');
 * Estornar por linha (lançamento ainda não faturado ou recebimento).
 */
export default class FinanceiroLojaController extends Controller {
    @service fetch;
    @service intl;
    @service notifications;
    @service modalsManager;
    @service hostRouter;

    @tracked id = null;
    @tracked extrato = null;
    @tracked carregado = false;
    @tracked inicio = inicioDoMes();
    @tracked fim = isoDate(new Date());

    get nome() {
        return this.extrato?.conta?.nome ?? '';
    }

    get grupos() {
        return agruparLancamentosPorDia(this.extrato?.lancamentos ?? []);
    }

    get totais() {
        return this.extrato?.totais ?? { creditos: 0, debitos: 0 };
    }

    abrir(id) {
        if (this.id !== id) {
            this.limpar();
        }
        this.id = id;
        this.carregar.perform();
    }

    /** Zera a tela ao sair dela (resposta atrasada não regrava outra loja). O período escolhido fica. */
    limpar() {
        this.carregar.cancelAll();
        this.extrato = null;
        this.carregado = false;
    }

    @action valor(numero) {
        return formatadores(this.intl.primaryLocale).valor(numero);
    }

    @action km(valor) {
        return `${formatadores(this.intl.primaryLocale).km(Number(valor) || 0)} km`;
    }

    @action data(iso) {
        return dataBr(iso);
    }

    @action tipo(lancamento) {
        return TIPOS.includes(lancamento?.tipo) ? this.intl.t(`${PREFIXO}.tipos.${lancamento.tipo}`) : String(lancamento?.tipo ?? '');
    }

    @action situacao(lancamento) {
        return SITUACOES.includes(lancamento?.situacao) ? this.intl.t(`${PREFIXO}.situacoes-lancamento.${lancamento.situacao}`) : '';
    }

    @action forma(forma) {
        return FORMAS.includes(forma) ? this.intl.t(`${PREFIXO}.formas.${forma}`) : String(forma ?? '');
    }

    @action corDaSituacao(lancamento) {
        return corDaSituacao(lancamento?.situacao);
    }

    @action podeEstornar(lancamento) {
        return podeEstornarNaLoja(lancamento);
    }

    @action voltar() {
        return this.hostRouter.transitionTo('console.fleet-ops.financeiro.lojas');
    }

    @action setData(campo, event) {
        this[campo] = event.target.value;
    }

    @action setPeriodo(atalho) {
        const hoje = new Date();
        if (atalho === 'este-mes') {
            this.inicio = inicioDoMes();
            this.fim = isoDate(hoje);
        } else if (atalho === 'mes-passado') {
            this.inicio = isoDate(new Date(hoje.getFullYear(), hoje.getMonth() - 1, 1));
            this.fim = isoDate(new Date(hoje.getFullYear(), hoje.getMonth(), 0));
        } else if (atalho === 'ultimos-90-dias') {
            this.inicio = isoDate(new Date(hoje.getFullYear(), hoje.getMonth(), hoje.getDate() - 89));
            this.fim = isoDate(hoje);
        }
        this.carregar.perform();
    }

    @task({ restartable: true }) *carregar() {
        const id = this.id;
        if (!id || !this.inicio || !this.fim) {
            return;
        }

        try {
            this.extrato = yield this.fetch.get(`${ENDPOINT}/lojas/${id}/extrato`, { inicio: this.inicio, fim: this.fim });
            this.carregado = true;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /** Registrar recebimento (modal da tela de motoboys; na conta da loja o título é "Registrar recebimento"). */
    @action registrarRecebimento() {
        this.modalsManager.show('modals/registrar-pagamento', {
            contaTipo: 'loja',
            contaId: this.id,
            nome: this.nome,
            saldo: Number(this.extrato?.saldo) || 0,
            onFinish: () => this.carregar.perform(),
        });
    }

    /** Lançar ajuste ou saldo inicial na conta da loja (fica sem fatura e entra no próximo rascunho). */
    @action lancar() {
        this.modalsManager.show('modals/lancar-no-financeiro', {
            contaTipo: 'loja',
            contas: [{ id: this.id, nome: this.nome }],
            onFinish: () => this.carregar.perform(),
        });
    }

    /** Estorno: recebimento → estorna o pagamento (o saldo volta); lançamento ainda sem fatura → lançamento oposto. */
    @action estornar(lancamento) {
        const recebimento = lancamento.tipo === 'recebimento';
        this.modalsManager.confirm({
            title: this.intl.t(`${PREFIXO}.estornar-titulo`),
            body: this.intl.t(recebimento ? `${PREFIXO}.estornar-recebimento-texto` : `${PREFIXO}.estornar-texto`, { tipo: this.tipo(lancamento), valor: this.valor(lancamento.valor) }),
            acceptButtonText: this.intl.t(`${PREFIXO}.estornar`),
            acceptButtonScheme: 'danger',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    if (recebimento) {
                        await this.fetch.post(`${ENDPOINT}/pagamentos/${lancamento.pagamento.id}/estornar`);
                    } else {
                        await this.fetch.post(`${ENDPOINT}/lancamentos/${lancamento.id}/estornar`, { motivo: null });
                    }
                    this.notifications.success(this.intl.t(`${PREFIXO}.estornado`));
                    await this.carregar.perform();
                } catch (error) {
                    this.notifications.serverError(error);
                }
            },
        });
    }

    @action exportarCsv() {
        const t = (chave) => this.intl.t(`${PREFIXO}.${chave}`);
        const rotulos = {
            data: t('data'),
            tipo: t('tipo'),
            pedido: t('pedido'),
            motivo: t('motivo'),
            fatura: t('fatura'),
            valor: t('valor'),
            situacao: t('situacao'),
            tipos: Object.fromEntries(TIPOS.map((tipo) => [tipo, t(`tipos.${tipo}`)])),
            situacoes: Object.fromEntries(SITUACOES.map((situacao) => [situacao, t(`situacoes-lancamento.${situacao}`)])),
        };
        const linhas = linhasCsvDoExtratoDaLoja(this.extrato, rotulos);

        const blob = new Blob(['﻿' + linhas.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `extrato-loja_${this.id}_${this.inicio}_${this.fim}.csv`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }
}
