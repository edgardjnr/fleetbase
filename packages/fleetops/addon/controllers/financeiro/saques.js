import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { formatadores } from '../../utils/financeiro';
import { SITUACOES_DO_SAQUE, chaveDaSituacaoDoSaque, consultaDosSaques, linhaDoMotoboyComPix, linhaDoSaque } from '../../utils/financeiro-saque-pix';

const ENDPOINT = 'entregas/financeiro/saques';
const P = 'fleet-ops.ui.financeiro.saque-pix';

/**
 * Entregas RestaurantePro: tela Saques Pix do Financeiro. Os saques pedidos no app nos últimos 30 dias (mais novo
 * primeiro), com filtro por situação, motivo da falha e o comprovante do Asaas; e os motoboys com chave Pix, com o
 * interruptor "Saque liberado / bloqueado" (`PUT int/v1/entregas/financeiro/motoboys/{id}/saque {bloqueado}`). O nome do
 * motoboy abre o extrato só para o admin do sistema (o extrato ainda é negado ao admin da organização).
 */
export default class FinanceiroSaquesController extends Controller {
    @service fetch;
    @service intl;
    @service notifications;
    @service currentUser;
    @service hostRouter;

    queryParams = ['situacao'];

    @tracked situacao = '';
    @tracked saques = [];
    @tracked motoboys = [];
    @tracked carregado = false;

    get f() {
        return formatadores(this.intl.primaryLocale);
    }

    get ehAdminDoSistema() {
        return this.currentUser.isAdmin === true;
    }

    get situacoes() {
        return SITUACOES_DO_SAQUE.map((situacao) => ({ valor: situacao, chave: chaveDaSituacaoDoSaque(situacao) }));
    }

    get linhas() {
        return this.saques.map(linhaDoSaque);
    }

    get linhasDosMotoboys() {
        return this.motoboys.map(linhaDoMotoboyComPix);
    }

    limpar() {
        this.carregar.cancelAll();
        this.saques = [];
        this.motoboys = [];
        this.carregado = false;
    }

    @action valor(numero) {
        return this.f.valor(numero);
    }

    @action setSituacao(event) {
        this.situacao = event.target.value;
        this.carregar.perform();
    }

    @action configurar() {
        this.hostRouter.transitionTo('console.fleet-ops.financeiro.configuracoes');
    }

    /** O Toggle chama com o estado novo: ligado = saque liberado. */
    @action alternarBloqueio(motoboy, liberado) {
        this.salvarBloqueio.perform(motoboy, liberado);
    }

    @task({ restartable: true }) *carregar() {
        try {
            const resposta = yield this.fetch.get(ENDPOINT, consultaDosSaques(this.situacao));
            this.saques = resposta?.saques ?? [];
            this.motoboys = resposta?.motoboys ?? [];
            this.carregado = true;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ enqueue: true }) *salvarBloqueio(motoboy, liberado) {
        try {
            const resposta = yield this.fetch.put(`entregas/financeiro/motoboys/${motoboy.id}/saque`, { bloqueado: liberado !== true });
            const bloqueado = resposta?.bloqueado === true;
            this.motoboys = this.motoboys.map((item) => (item.id === motoboy.id ? { ...item, bloqueado } : item));
            this.notifications.success(this.intl.t(`${P}.motoboys.${bloqueado ? 'salvo-bloqueado' : 'salvo-liberado'}`, { nome: motoboy.nome }));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
