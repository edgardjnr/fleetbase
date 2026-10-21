import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { campoDeData, chaveDaSituacao, corDaSituacao, separarPeriodos, somarHoras, textoDoFator } from '../../utils/financeiro-multiplicadores';
import { formatadores } from '../../utils/financeiro';

const ENDPOINT = 'entregas/financeiro/multiplicadores';
const PREFIXO = 'fleet-ops.ui.financeiro.multiplicadores';

/**
 * Entregas RestaurantePro: Financeiro → Multiplicadores. A empresa cria um período (nome, ×motoboy, ×loja, início e fim)
 * e as entregas atribuídas dentro dele valem a faixa × o fator. Regras no servidor (Multiplicadores); o modal
 * periodo-multiplicador confere as mesmas antes de enviar. Agendado: editar ou apagar; em andamento: mudar o fim ou
 * encerrar agora; encerrado: só consulta.
 */
export default class FinanceiroMultiplicadoresController extends Controller {
    @service fetch;
    @service intl;
    @service notifications;
    @service modalsManager;

    @tracked periodos = [];
    @tracked carregado = false;

    get separados() {
        return separarPeriodos(this.periodos);
    }

    limpar() {
        this.carregar.cancelAll();
        this.carregado = false;
    }

    @action fator(valor) {
        return textoDoFator(valor, this.intl.primaryLocale);
    }

    @action instante(iso) {
        return formatadores(this.intl.primaryLocale).instante(iso);
    }

    @action situacao(periodo) {
        return this.intl.t(chaveDaSituacao(periodo?.situacao));
    }

    @action corDaSituacao(periodo) {
        return corDaSituacao(periodo?.situacao);
    }

    @task({ restartable: true }) *carregar() {
        try {
            const resposta = yield this.fetch.get(ENDPOINT);
            this.periodos = resposta?.multiplicadores ?? [];
            this.carregado = true;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action novo() {
        this.abrirModal({ periodo: null });
    }

    /** "Começar agora": início = agora, fim sugerido em 3 h (editável). */
    @action comecarAgora() {
        const agora = campoDeData(new Date());
        this.abrirModal({ periodo: null, inicio: agora, fim: somarHoras(agora, 3) });
    }

    @action editar(periodo) {
        this.abrirModal({ periodo });
    }

    abrirModal(opcoes) {
        this.modalsManager.show('modals/periodo-multiplicador', { ...opcoes, onSalvo: () => this.carregar.perform() });
    }

    @action encerrar(periodo) {
        this.modalsManager.confirm({
            title: this.intl.t(`${PREFIXO}.encerrar-titulo`, { nome: periodo.nome }),
            body: this.intl.t(`${PREFIXO}.encerrar-texto`),
            acceptButtonText: this.intl.t(`${PREFIXO}.encerrar-agora`),
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await this.fetch.post(`${ENDPOINT}/${periodo.id}/encerrar`);
                    this.notifications.success(this.intl.t(`${PREFIXO}.encerrado-ok`));
                    modal.done();
                    this.carregar.perform();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    @action apagar(periodo) {
        this.modalsManager.confirm({
            title: this.intl.t(`${PREFIXO}.apagar-titulo`, { nome: periodo.nome }),
            body: this.intl.t(`${PREFIXO}.apagar-texto`),
            acceptButtonText: this.intl.t(`${PREFIXO}.apagar`),
            acceptButtonScheme: 'danger',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await this.fetch.delete(`${ENDPOINT}/${periodo.id}`);
                    this.notifications.success(this.intl.t(`${PREFIXO}.apagado-ok`));
                    modal.done();
                    this.carregar.perform();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }
}
