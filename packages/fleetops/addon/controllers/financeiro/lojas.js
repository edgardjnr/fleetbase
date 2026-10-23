import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { formatadores } from '../../utils/financeiro';
import { corDaFatura, dataBr, filtrarFaturas, numeroOuRascunho, opcoesDeDia, rotuloDaFatura, textoDoCiclo, validarCiclo } from '../../utils/financeiro-faturas';

const ENDPOINT = 'entregas/financeiro';
const PREFIXO = 'fleet-ops.ui.financeiro-lojas';
const SITUACOES = ['todas', 'rascunho', 'aberta', 'vencida', 'paga', 'cancelada'];
const ABAS = ['faturas', 'lojas', 'ciclo'];

/**
 * Entregas RestaurantePro: Financeiro → Lojas e faturas. Três abas sem rota (TabNavigation, a aba fica na URL em `?aba=`
 * para o "voltar" da fatura cair na mesma aba):
 * - Faturas: rascunhos, emitidas e canceladas, com filtro de situação e loja (no cliente; a lista vem inteira);
 * - Lojas: saldo, em aberto, vencido, não faturado e o ciclo de cobrança (editável na própria linha);
 * - Ciclo em andamento: o período atual de cada loja e o que ainda não foi faturado.
 * A API (int/v1/entregas/financeiro/*) só atende administradores.
 */
export default class FinanceiroLojasController extends Controller {
    @service fetch;
    @service intl;
    @service notifications;
    @service modalsManager;
    @service hostRouter;

    queryParams = ['aba'];

    @tracked aba = 'faturas';
    @tracked faturas = [];
    @tracked lojas = [];
    @tracked carregado = false;
    @tracked filtroSituacao = 'todas';
    @tracked filtroLoja = '';
    // ciclo em edição na aba Lojas: { lojaId, ciclo, dia_fechamento, prazo_dias } (tudo como texto, como é escolhido); null = fechado
    @tracked cicloEditando = null;

    get abaAtiva() {
        return ABAS.includes(this.aba) ? this.aba : 'faturas';
    }

    get abas() {
        return [
            { id: 'faturas', label: this.intl.t(`${PREFIXO}.aba-faturas`), icon: 'file-invoice-dollar' },
            { id: 'lojas', label: this.intl.t(`${PREFIXO}.aba-lojas`), icon: 'store' },
            { id: 'ciclo', label: this.intl.t(`${PREFIXO}.aba-ciclo`), icon: 'hourglass-half' },
        ];
    }

    get opcoesDeSituacao() {
        return SITUACOES.map((valor) => ({ valor, texto: this.intl.t(`${PREFIXO}.filtro.${valor}`) }));
    }

    get faturasFiltradas() {
        return filtrarFaturas(this.faturas, { situacao: this.filtroSituacao, loja: this.filtroLoja || null });
    }

    get totalEmAbertoFiltrado() {
        return this.faturasFiltradas.reduce((soma, fatura) => soma + (fatura.situacao === 'emitida' ? Number(fatura.em_aberto) || 0 : 0), 0);
    }

    get opcoesDoDiaEditando() {
        return opcoesDeDia(this.cicloEditando?.ciclo).map((opcao) => ({ valor: String(opcao.valor), texto: this.intl.t(`${PREFIXO}.${opcao.chave}`, opcao.params) }));
    }

    get errosDoCiclo() {
        return this.cicloEditando ? validarCiclo(this.cicloEditando).map((erro) => this.intl.t(`${PREFIXO}.erros.${erro}`)) : [];
    }

    /** Zera a tela ao sair dela (a resposta atrasada de um carregamento não regrava a lista). */
    limpar() {
        this.carregar.cancelAll();
        this.faturas = [];
        this.lojas = [];
        this.carregado = false;
        this.cicloEditando = null;
    }

    @action mudarAba(aba) {
        this.aba = aba?.id ?? 'faturas';
    }

    @action valor(numero) {
        return formatadores(this.intl.primaryLocale).valor(numero);
    }

    @action data(iso) {
        return dataBr(iso);
    }

    @action numeroDaFatura(fatura) {
        return numeroOuRascunho(fatura, this.intl.t(`${PREFIXO}.rascunho`));
    }

    @action rotuloDaFatura(fatura) {
        return this.intl.t(`${PREFIXO}.situacoes.${rotuloDaFatura(fatura)}`);
    }

    @action corDaFatura(fatura) {
        return corDaFatura(fatura);
    }

    @action vencida(fatura) {
        return rotuloDaFatura(fatura) === 'vencida';
    }

    /** Emitida com valor em aberto (aberta, em parte ou vencida): pode registrar recebimento. */
    @action emAberto(fatura) {
        return fatura?.situacao === 'emitida' && ['aberta', 'parcial', 'vencida'].includes(rotuloDaFatura(fatura));
    }

    /** Ciclo da loja em texto ("Semanal, fecha domingo · vence em 3 dias"). */
    @action textoDoCiclo(ciclo) {
        const { chave, params, chaveDoDia } = textoDoCiclo(ciclo);
        const parametros = chaveDoDia ? { ...params, dia: this.intl.t(`${PREFIXO}.${chaveDoDia}`) } : params;
        return this.intl.t(`${PREFIXO}.${chave}`, parametros);
    }

    @action setFiltro(campo, event) {
        this[campo] = event.target.value;
    }

    @action abrirFatura(fatura) {
        return this.hostRouter.transitionTo('console.fleet-ops.financeiro.fatura', fatura.id);
    }

    /** Apaga o rascunho (feito errado ou de teste): as entregas voltam para a próxima fatura; dá para gerar de novo. */
    @action apagarRascunho(fatura) {
        this.modalsManager.confirm({
            title: this.intl.t(`${PREFIXO}.apagar-rascunho-titulo`, { loja: fatura.loja?.nome ?? '' }),
            body: this.intl.t(`${PREFIXO}.apagar-rascunho-texto`, { inicio: dataBr(fatura.periodo_inicio), fim: dataBr(fatura.periodo_fim) }),
            acceptButtonText: this.intl.t(`${PREFIXO}.apagar-rascunho`),
            acceptButtonScheme: 'danger',
            acceptButtonIcon: 'trash',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await this.fetch.delete(`${ENDPOINT}/faturas/${fatura.id}`);
                    this.notifications.success(this.intl.t(`${PREFIXO}.rascunho-apagado`));
                    await this.carregar.perform();
                } catch (error) {
                    this.notifications.serverError(error);
                }
            },
        });
    }

    @action abrirLoja(loja) {
        return this.hostRouter.transitionTo('console.fleet-ops.financeiro.loja', loja.id);
    }

    /**
     * "Registrar recebimento" da fatura (modal da tela de motoboys, na conta da loja). Sugere o em aberto da fatura; o
     * saldo é o da loja (da aba Lojas; sem a linha, o modal busca), porque o recebimento quita primeiro a fatura mais
     * antiga em aberto.
     */
    @action registrarRecebimento(fatura) {
        const loja = this.lojas.find((linha) => linha.id === fatura.loja?.id);
        this.modalsManager.show('modals/registrar-pagamento', {
            contaTipo: 'loja',
            contaId: fatura.loja?.id,
            nome: fatura.loja?.nome,
            saldo: loja ? loja.saldo : undefined,
            sugestao: Number(fatura.em_aberto) || 0,
            fatura: fatura.id,
            onFinish: () => this.recarregar(),
        });
    }

    /** "Gerar fatura": rascunho à mão (loja + período), para o caso fora do ciclo. Abre a fatura gerada para conferir. */
    @action gerarFatura(loja = null) {
        this.modalsManager.show('modals/gerar-fatura', {
            lojas: this.lojas,
            loja: loja?.id ?? null,
            onGerada: (fatura) => {
                if (fatura?.id) {
                    this.abrirFatura(fatura);
                } else {
                    this.recarregar();
                }
            },
        });
    }

    @action editarCiclo(loja) {
        if (this.cicloEditando?.lojaId === loja.id) {
            this.cicloEditando = null;
            return;
        }

        const ciclo = loja.ciclo ?? {};
        this.cicloEditando = {
            lojaId: loja.id,
            ciclo: ciclo.ciclo ?? 'semanal',
            dia_fechamento: String(ciclo.dia_fechamento ?? 0),
            prazo_dias: String(ciclo.prazo_dias ?? 3),
        };
    }

    @action setCampoCiclo(campo, event) {
        const valor = event.target.value;
        const editando = { ...this.cicloEditando, [campo]: valor };
        // trocar o ciclo põe um dia que existe nele (semanal: domingo; mensal: último dia; quinzenal: ignorado)
        if (campo === 'ciclo' && !opcoesDeDia(valor).some((opcao) => String(opcao.valor) === editando.dia_fechamento)) {
            editando.dia_fechamento = '0';
        }
        this.cicloEditando = editando;
    }

    @action cancelarCiclo() {
        this.cicloEditando = null;
    }

    recarregar() {
        if (this.carregado) {
            this.carregar.perform();
        }
    }

    @task({ restartable: true }) *carregar() {
        try {
            const [faturas, lojas] = yield Promise.all([this.fetch.get(`${ENDPOINT}/faturas`), this.fetch.get(`${ENDPOINT}/lojas`)]);
            this.faturas = faturas?.faturas ?? [];
            this.lojas = lojas?.lojas ?? [];
            this.carregado = true;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *salvarCiclo() {
        const editando = this.cicloEditando;
        if (!editando || validarCiclo(editando).length) {
            return;
        }

        const corpo = {
            ciclo: editando.ciclo,
            dia_fechamento: editando.ciclo === 'quinzenal' ? 0 : Number(editando.dia_fechamento),
            prazo_dias: Number(editando.prazo_dias),
        };

        try {
            const resposta = yield this.fetch.put(`${ENDPOINT}/lojas/${editando.lojaId}/ciclo`, corpo);
            if (resposta?.loja?.id) {
                this.lojas = this.lojas.map((loja) => (loja.id === resposta.loja.id ? resposta.loja : loja));
            } else {
                yield this.carregar.perform();
            }
            if (this.cicloEditando === editando) {
                this.cicloEditando = null;
            }
            this.notifications.success(this.intl.t(`${PREFIXO}.ciclo-salvo`));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
