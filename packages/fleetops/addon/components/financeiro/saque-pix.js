import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { API_DO_SAQUE_PIX, etapasDoSaque } from '../../utils/financeiro-saque-pix';

/**
 * Entregas RestaurantePro: card "Saque Pix (Asaas)" de Financeiro → Configurações, em três passos (conta Asaas, validação
 * de saque, ligar e limites). Lê `GET int/v1/entregas/financeiro/saque-pix` (SaquePixController@situacao, formato
 * ConexaoAsaas::publico: nunca a chave nem o token) e repassa a conexão aos passos; cada passo grava pela própria rota e
 * devolve a conexão nova por @aoMudar. Para o admin do sistema e o da organização (ehAdminDaCentral).
 */
export default class FinanceiroSaquePixComponent extends Component {
    @service fetch;
    @service notifications;

    @tracked conexao = null;

    constructor() {
        super(...arguments);
        this.carregar.perform();
    }

    get etapas() {
        return etapasDoSaque(this.conexao);
    }

    /** A conexão nova devolvida por um passo (o formato público sempre traz `conectada`). */
    @action aoMudar(conexao) {
        if (conexao && typeof conexao === 'object' && 'conectada' in conexao) {
            this.conexao = conexao;
        }
    }

    @task({ restartable: true }) *carregar() {
        try {
            this.conexao = yield this.fetch.get(API_DO_SAQUE_PIX);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
