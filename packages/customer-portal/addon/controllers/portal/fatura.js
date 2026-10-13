import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { restartableTask } from 'ember-concurrency';
import { format, isValid, parseISO } from 'date-fns';
import dateFnsLocaleOptions from '@fleetbase/ember-core/utils/date-fns-locale';
import { situacaoDaFatura, chaveDaSituacao, classeDoSelo, descricaoDoItem, numeroDoPedido, linhasCsvDaFatura, csvDeLinhas } from '../../utils/entregas-financeiro';

const SEM_VALOR = '—';

// Entregas: quanto tempo o endereço do download (blob) continua válido. Revogar logo depois do click() cancela o download
// em navegadores que só leem o blob depois do click
const VALIDADE_DOWNLOAD_MS = 60000;

/**
 * Entregas: detalhe de uma fatura da loja no portal (GET int/v1/entregas/loja/faturas/{uuid}), que é também a página de
 * impressão: o "Imprimir / salvar PDF" chama o window.print() e o CSS de impressão (customer-portal-engine.css) esconde o
 * menu, a barra superior e os botões. Sem PDF no servidor. Rascunho e fatura de outra loja voltam 404 do servidor: o aviso
 * do erro aparece e a tela volta ao Financeiro.
 */
export default class PortalFaturaController extends Controller {
    @service fetch;
    @service notifications;
    @service intl;
    @service hostRouter;

    @tracked dados = null;
    @tracked impressoEm = new Date();

    get fatura() {
        return this.dados?.fatura ?? null;
    }

    get itens() {
        return this.dados?.itens ?? [];
    }

    get situacao() {
        return situacaoDaFatura(this.fatura);
    }

    get rotuloDaSituacao() {
        return this.intl.t(chaveDaSituacao(this.situacao));
    }

    get classeDoSelo() {
        return classeDoSelo(this.situacao);
    }

    get titulo() {
        return this.intl.t('customer-portal.ui.entregas.financeiro.invoice-title', { numero: this.fatura?.numero || SEM_VALOR });
    }

    get cancelada() {
        return this.situacao === 'cancelada';
    }

    get vencida() {
        return this.situacao === 'vencida';
    }

    get avisoDeVencida() {
        return this.intl.t('customer-portal.ui.entregas.financeiro.overdue-notice', {
            data: this.dataCurta(this.fatura?.vencimento),
            valor: this.dinheiro(this.fatura?.em_aberto),
        });
    }

    get periodo() {
        return this.intl.t('customer-portal.ui.entregas.financeiro.period-range', {
            inicio: this.dataCurta(this.fatura?.periodo_inicio),
            fim: this.dataCurta(this.fatura?.periodo_fim),
        });
    }

    get textoImpressoEm() {
        return this.intl.t('customer-portal.ui.entregas.financeiro.printed-at', { data: format(this.impressoEm, 'P p', dateFnsLocaleOptions()) });
    }

    get temItens() {
        return this.itens.length > 0;
    }

    @restartableTask *carregar(id) {
        // outra fatura: a anterior sai da tela enquanto esta carrega
        if (this.fatura?.id !== id) {
            this.dados = null;
        }

        try {
            this.dados = yield this.fetch.get(`entregas/loja/faturas/${encodeURIComponent(id)}`);
            this.impressoEm = new Date();
        } catch (error) {
            this.notifications.serverError(error);

            // a rota cancela a consulta ao sair; a guarda cobre a resposta que chega durante outra navegação
            if (this.hostRouter.currentRouteName === 'customer-portal.portal.fatura') {
                this.hostRouter.transitionTo('customer-portal.portal.financeiro');
            }
        }
    }

    // Entregas: chamado pela rota ao sair da tela
    reiniciar() {
        this.carregar.cancelAll();
        this.dados = null;
    }

    @action voltar() {
        this.hostRouter.transitionTo('customer-portal.portal.financeiro');
    }

    @action imprimir() {
        this.impressoEm = new Date();
        // o rodapé "Impresso em" é redesenhado antes do diálogo de impressão abrir
        requestAnimationFrame(() => window.print());
    }

    // Data 'AAAA-MM-DD' no idioma ativo (31/10/2026)
    @action dataCurta(valor) {
        const data = valor ? parseISO(String(valor)) : null;

        return data && isValid(data) ? format(data, 'P', dateFnsLocaleOptions()) : SEM_VALOR;
    }

    // Instante ISO no fuso da organização ("2026-10-05T10:00:00-03:00"): vale o relógio da própria string, sem o
    // deslocamento, para não mudar de dia para quem está em outro fuso (como no extrato)
    @action dataHora(valor) {
        const data = valor ? parseISO(String(valor).slice(0, 19)) : null;

        return data && isValid(data) ? format(data, 'P p', dateFnsLocaleOptions()) : SEM_VALOR;
    }

    @action dinheiro(valor) {
        return valor === null || valor === undefined ? SEM_VALOR : this.intl.formatNumber(Number(valor) || 0, { style: 'currency', currency: 'BRL' });
    }

    @action distancia(valor) {
        return valor === null || valor === undefined ? SEM_VALOR : this.intl.formatNumber(Number(valor), { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    @action pedido(item) {
        return numeroDoPedido(item) ?? SEM_VALOR;
    }

    // "Ajuste: Desconto da semana", "Cancelado pelo iFood (cobrado)" ou vazio (corrida comum)
    @action descricao(item) {
        const { chave, motivo } = descricaoDoItem(item);
        const rotulo = chave ? this.intl.t(`customer-portal.ui.entregas.${chave}`) : null;

        return [rotulo, motivo].filter(Boolean).join(': ');
    }

    // CSV para o Excel brasileiro (BOM, ";" e vírgula decimal): os itens e os totais da fatura
    @action baixarCsv() {
        if (!this.fatura) {
            return;
        }

        const t = (chave) => this.intl.t(`customer-portal.ui.entregas.financeiro.${chave}`);
        const linhas = linhasCsvDaFatura(this.fatura, this.itens, {
            cabecalho: [t('date'), t('order'), t('km'), t('destination'), t('description'), t('amount')],
            total: t('total'),
            recebido: t('received'),
            emAberto: t('open-amount'),
            data: (valor) => (valor ? this.dataCurta(valor) : ''),
            descricao: (item) => this.descricao(item),
        });
        const endereco = URL.createObjectURL(new Blob([csvDeLinhas(linhas)], { type: 'text/csv;charset=utf-8' }));
        const link = document.createElement('a');

        link.href = endereco;
        link.download = `fatura_${this.fatura.numero || this.fatura.id}.csv`;
        document.body.appendChild(link);
        link.click();
        link.remove();

        setTimeout(() => URL.revokeObjectURL(endereco), VALIDADE_DOWNLOAD_MS);
    }
}
