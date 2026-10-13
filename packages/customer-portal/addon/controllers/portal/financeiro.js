import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { restartableTask } from 'ember-concurrency';
import { format, isValid, parseISO } from 'date-fns';
import dateFnsLocaleOptions from '@fleetbase/ember-core/utils/date-fns-locale';
import { situacaoDaFatura, chaveDaSituacao, classeDoSelo, textoDoCiclo } from '../../utils/entregas-financeiro';

const SEM_VALOR = '—';

// 'AAAA-MM-DD' como data local (o parseISO não aplica fuso a data sem hora), ou null
const dataLocal = (valor) => {
    const data = valor ? parseISO(String(valor)) : null;

    return data && isValid(data) ? data : null;
};

/**
 * Entregas: Financeiro da loja no portal. Mostra o ciclo em andamento (lançamentos ainda sem fatura) e as faturas emitidas e
 * canceladas (GET int/v1/entregas/loja/financeiro). Os rascunhos nunca chegam aqui: o servidor só devolve as emitidas e as
 * canceladas da loja da sessão.
 */
export default class PortalFinanceiroController extends Controller {
    @service fetch;
    @service notifications;
    @service intl;
    @service hostRouter;

    @tracked dados = null;

    get ciclo() {
        return textoDoCiclo(this.dados?.ciclo_atual);
    }

    // "Desde 06/10 · 42 entregas · R$ 315,00 até agora"
    get resumoDoCiclo() {
        const { ciclo } = this;

        if (!ciclo) {
            return '';
        }

        return this.intl.t('customer-portal.ui.entregas.financeiro.cycle-summary', {
            desde: this.diaEMes(ciclo.desde),
            entregas: ciclo.entregas,
            valor: this.dinheiro(ciclo.valor),
        });
    }

    get valorDoCiclo() {
        return this.ciclo ? this.dinheiro(this.ciclo.valor) : SEM_VALOR;
    }

    // Cada fatura com a situação exibida e o selo já resolvidos (o servidor manda a mais nova primeiro)
    get faturas() {
        return (this.dados?.faturas ?? []).map((fatura) => {
            const situacao = situacaoDaFatura(fatura);

            return { fatura, situacao, rotulo: this.intl.t(chaveDaSituacao(situacao)), classe: classeDoSelo(situacao) };
        });
    }

    get temFaturas() {
        return this.faturas.length > 0;
    }

    // Já há dados na tela e uma nova consulta está em andamento (os dados antigos continuam visíveis, só esmaecidos)
    get atualizando() {
        return Boolean(this.dados) && this.carregar.isRunning;
    }

    @restartableTask *carregar() {
        try {
            this.dados = yield this.fetch.get('entregas/loja/financeiro');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    // Entregas: chamado pela rota ao sair da tela
    reiniciar() {
        this.carregar.cancelAll();
        this.dados = null;
    }

    @action atualizar() {
        this.carregar.perform();
    }

    // Clique na linha abre a fatura. O número da fatura é um link (teclado e "abrir em nova aba"); o clique nele já navega
    @action abrir(fatura, event) {
        if (event?.target?.closest?.('a')) {
            return;
        }

        this.hostRouter.transitionTo('customer-portal.portal.fatura', fatura.id);
    }

    // Data 'AAAA-MM-DD' no idioma ativo (31/10/2026)
    @action dataCurta(valor) {
        const data = dataLocal(valor);

        return data ? format(data, 'P', dateFnsLocaleOptions()) : SEM_VALOR;
    }

    // Só dia e mês no idioma ativo (06/10)
    diaEMes(valor) {
        const data = dataLocal(valor);

        return data ? this.intl.formatDate(data, { day: '2-digit', month: '2-digit' }) : SEM_VALOR;
    }

    @action periodo(fatura) {
        return this.intl.t('customer-portal.ui.entregas.financeiro.period-range', {
            inicio: this.dataCurta(fatura?.periodo_inicio),
            fim: this.dataCurta(fatura?.periodo_fim),
        });
    }

    @action dinheiro(valor) {
        return valor === null || valor === undefined ? SEM_VALOR : this.intl.formatNumber(Number(valor) || 0, { style: 'currency', currency: 'BRL' });
    }

    @action numero(fatura) {
        return fatura?.numero || SEM_VALOR;
    }
}
