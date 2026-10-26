import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { quandoDoIso } from '../../../utils/financeiro';
import { API_DO_SAQUE_PIX } from '../../../utils/financeiro-saque-pix';

const P = 'fleet-ops.ui.financeiro.saque-pix.conta';

/**
 * Entregas RestaurantePro: passo 1 do card Saque Pix. Conectar a conta Asaas da central (chave de API + senha do admin),
 * mostrar a conta conectada, trocar a chave (mesma conta) e desconectar (com a senha): POST/DELETE
 * int/v1/entregas/financeiro/saque-pix/conexao (SaquePixController@conectar/desconectar). A chave e a senha digitadas
 * somem da tela ao gravar, ao errar (a senha) ou ao cancelar, e a chave nunca volta do servidor.
 */
export default class FinanceiroSaquePixContaComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;

    // null = só a conta; 'trocar' = formulário da chave nova; 'desconectar' = confirmação com a senha
    @tracked modo = null;
    @tracked chave = '';
    @tracked senha = '';

    get conta() {
        return this.args.conexao?.conta ?? null;
    }

    get conectadaEm() {
        const quando = quandoDoIso(this.args.conexao?.conectada_em);
        return quando ? `${quando.data} ${quando.hora}` : '';
    }

    get semCofre() {
        return this.args.conexao?.cofre === false;
    }

    /** O formulário da chave: para conectar (sem conta) ou para trocar a chave. */
    get mostrarFormulario() {
        return !this.args.etapas.conectada || this.modo === 'trocar';
    }

    @action setCampo(campo, event) {
        this[campo] = event.target.value;
    }

    @action abrir(modo) {
        this.modo = modo;
        this.chave = '';
        this.senha = '';
    }

    @action fechar() {
        this.abrir(null);
    }

    @task({ drop: true }) *conectar() {
        if (!this.chave.trim() || !this.senha) {
            this.notifications.warning(this.intl.t(`${P}.preencha`));
            return;
        }
        const trocando = this.args.etapas.conectada;
        try {
            const conexao = yield this.fetch.post(`${API_DO_SAQUE_PIX}/conexao`, { chave: this.chave.trim(), senha: this.senha });
            this.args.aoMudar(conexao);
            this.fechar();
            this.notifications.success(this.intl.t(trocando ? `${P}.chave-trocada` : `${P}.conectada`));
        } catch (error) {
            this.senha = '';
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *desconectar() {
        if (!this.senha) {
            this.notifications.warning(this.intl.t(`${P}.preencha-senha`));
            return;
        }
        try {
            const conexao = yield this.fetch.delete(`${API_DO_SAQUE_PIX}/conexao`, { senha: this.senha });
            this.args.aoMudar(conexao);
            this.fechar();
            this.notifications.success(this.intl.t(`${P}.desconectada`));
        } catch (error) {
            this.senha = '';
            this.notifications.serverError(error);
        }
    }
}
