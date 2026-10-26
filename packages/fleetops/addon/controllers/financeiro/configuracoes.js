import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { CICLOS, diaAoTrocarDeCiclo, diasDeFechamento, validarCobranca } from '../../utils/financeiro';
import { ehAdminDaCentral } from '../../utils/financeiro-saque-pix';

const ENDPOINT = 'entregas/financeiro/configuracoes';
const PADRAO = { ciclo: 'semanal', dia_fechamento: 0, prazo_dias: 3 };

/**
 * Entregas RestaurantePro: configurações do Financeiro.
 * - Data de corte (Setting company.<uuid>.entregas.financeiro_corte): corridas antes dela não são lançadas; sem data, nada
 *   é lançado automaticamente nessa organização.
 * - Ciclo padrão de cobrança das lojas (Setting ...cobranca_padrao), usado pela loja sem ciclo próprio.
 * - "ligada" (só leitura) = ENTREGAS_FINANCEIRO no servidor; desligada, as telas funcionam mas nada é lançado sozinho.
 * - Card "Saque Pix (Asaas)" (componente Financeiro::SaquePix), também para o admin da organização. Esse admin vê só o
 *   card: as rotas de corte e ciclo continuam só com o admin do sistema na API, e o `carregar` nem as chama para ele.
 */
export default class FinanceiroConfiguracoesController extends Controller {
    @service fetch;
    @service intl;
    @service notifications;
    @service currentUser;

    @tracked carregado = false;
    @tracked ligada = true;
    @tracked corte = '';
    @tracked ciclo = PADRAO.ciclo;
    @tracked dia = PADRAO.dia_fechamento;
    @tracked prazo = String(PADRAO.prazo_dias);

    get ehAdminDoSistema() {
        return this.currentUser.isAdmin === true;
    }

    get ehAdminDaCentral() {
        return ehAdminDaCentral(this.currentUser);
    }

    get ciclos() {
        return CICLOS.map((ciclo) => ({ valor: ciclo, chave: `fleet-ops.ui.financeiro.ciclos.${ciclo}` }));
    }

    get dias() {
        return diasDeFechamento(this.ciclo);
    }

    get validacao() {
        return validarCobranca({ ciclo: this.ciclo, dia_fechamento: this.dia, prazo_dias: this.prazo });
    }

    get erro() {
        const codigo = this.validacao.erro;
        return codigo ? this.intl.t(`fleet-ops.ui.financeiro.erros.${codigo}`) : null;
    }

    limpar() {
        this.carregar.cancelAll();
        this.carregado = false;
    }

    preencher(resposta) {
        const cobranca = { ...PADRAO, ...(resposta?.cobranca_padrao ?? {}) };
        this.ligada = resposta?.ligada !== false;
        this.corte = resposta?.corte ?? '';
        this.ciclo = cobranca.ciclo;
        this.dia = Number(cobranca.dia_fechamento) || 0;
        this.prazo = String(cobranca.prazo_dias ?? PADRAO.prazo_dias);
        this.carregado = true;
    }

    @action setCorte(event) {
        this.corte = event.target.value;
    }

    @action limparCorte() {
        this.corte = '';
    }

    @action setCiclo(event) {
        this.ciclo = event.target.value;
        this.dia = diaAoTrocarDeCiclo(this.ciclo, this.dia);
    }

    @action setDia(event) {
        this.dia = Number(event.target.value);
    }

    @action setPrazo(event) {
        this.prazo = event.target.value;
    }

    @task({ restartable: true }) *carregar() {
        // o admin da organização vê só o card do saque Pix (a API nega a ele as configurações gerais)
        if (!this.ehAdminDoSistema) {
            return;
        }
        try {
            this.preencher(yield this.fetch.get(ENDPOINT));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *salvar() {
        const { config, erro } = this.validacao;
        if (erro) {
            this.notifications.warning(this.erro);
            return;
        }

        try {
            const resposta = yield this.fetch.put(ENDPOINT, { corte: this.corte || null, cobranca_padrao: config });
            // o contrato devolve o formato "Configuracoes"; sem ele, a tela fica com o que foi salvo
            if (resposta?.cobranca_padrao) {
                this.preencher(resposta);
            }
            this.notifications.success(this.intl.t('fleet-ops.ui.financeiro.configuracoes.salvo'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
