import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task, timeout } from 'ember-concurrency';
import { TIPOS_CHAVE_PIX, quandoDoIso, validarPix } from '../../../utils/financeiro';
import { API_DO_SAQUE_PIX, INTERVALO_DO_TESTE_MS, resultadoDoTeste, segundosRestantes } from '../../../utils/financeiro-saque-pix';

const P = 'fleet-ops.ui.financeiro.saque-pix.validacao';

/**
 * Entregas RestaurantePro: passo 2 do card Saque Pix, a validação de saque do Asaas. Mostra a URL que a central cola no
 * Asaas, gera o token (POST …/saque-pix/token {senha} → {token, url, conexao}: o token aparece só aqui, só nesta tela, e
 * some ao sair), o passo a passo do Asaas e a recomendação da lista de IPs (com o `ip_de_saida` do servidor). "Testar
 * validação" (POST …/saque-pix/teste {tipo, chave, senha}) manda R$ 1,00 para uma chave Pix do admin, que a validação do
 * Entregas recusa; a tela relê a conexão a cada 3 s por até 60 s esperando `validacao_confirmada_em`. Gerar o token e
 * testar pedem a senha do admin (422 "Senha incorreta." do servidor, mostrado como vem); cada campo de senha é limpo
 * depois de cada pedido, deu certo ou não.
 */
export default class FinanceiroSaquePixValidacaoComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;
    @service modalsManager;

    // o token acabado de gerar: só na memória desta tela (o servidor guarda só o hash)
    @tracked tokenNovo = null;
    @tracked tipo = 'cpf';
    @tracked chavePix = '';
    // a senha do admin, digitada para gerar o token e para testar; limpa depois de cada pedido
    @tracked senhaDoToken = '';
    @tracked senhaDoTeste = '';
    // instante (ms) em que o teste desta tela foi pedido; null = nenhum
    @tracked comecouEm = null;
    @tracked agora = Date.now();

    get url() {
        return this.args.conexao?.url_da_validacao ?? '';
    }

    get ip() {
        return this.args.conexao?.ip_de_saida || null;
    }

    get tiposDePix() {
        return TIPOS_CHAVE_PIX.map((tipo) => ({ valor: tipo, chave: `fleet-ops.ui.financeiro.pix.tipos.${tipo}` }));
    }

    get erroDoPix() {
        const erro = validarPix(this.tipo, this.chavePix);
        return erro ? this.intl.t(`fleet-ops.ui.financeiro.erros.${erro}`) : null;
    }

    get resultado() {
        return resultadoDoTeste(this.args.conexao, this.comecouEm, this.agora);
    }

    get segundos() {
        return this.comecouEm ? segundosRestantes(this.comecouEm, this.agora) : 0;
    }

    get confirmadaEm() {
        const quando = quandoDoIso(this.args.conexao?.validacao_confirmada_em);
        return quando ? `${quando.data} ${quando.hora}` : '';
    }

    @action setCampo(campo, event) {
        this[campo] = event.target.value;
    }

    /** Primeiro token: gera direto. Outro token: confirma, porque invalida o anterior, desliga o saque e pede novo teste. */
    @action pedirToken() {
        if (!this.senhaDoToken) {
            this.notifications.warning(this.intl.t(`${P}.preencha-senha`));
            return;
        }
        if (!this.args.etapas.tokenGerado) {
            this.gerarToken.perform();
            return;
        }
        this.modalsManager.confirm({
            title: this.intl.t(`${P}.gerar-outro-titulo`),
            body: this.intl.t(`${P}.gerar-outro-texto`),
            acceptButtonText: this.intl.t(`${P}.gerar-outro`),
            acceptButtonIcon: 'key',
            confirm: async (modal) => {
                modal.startLoading();
                await this.gerarToken.perform();
            },
        });
    }

    @task({ drop: true }) *gerarToken() {
        const senha = this.senhaDoToken;
        this.senhaDoToken = '';
        if (!senha) {
            this.notifications.warning(this.intl.t(`${P}.preencha-senha`));
            return;
        }
        try {
            const resposta = yield this.fetch.post(`${API_DO_SAQUE_PIX}/token`, { senha });
            this.tokenNovo = resposta?.token ?? null;
            this.comecouEm = null;
            this.args.aoMudar(resposta?.conexao);
            this.notifications.success(this.intl.t(`${P}.token-gerado`));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *testar() {
        if (this.erroDoPix) {
            this.notifications.warning(this.erroDoPix);
            return;
        }
        if (!this.senhaDoTeste) {
            this.notifications.warning(this.intl.t(`${P}.preencha-senha`));
            return;
        }
        const senha = this.senhaDoTeste;
        this.senhaDoTeste = '';
        try {
            this.args.aoMudar(yield this.fetch.post(`${API_DO_SAQUE_PIX}/teste`, { tipo: this.tipo, chave: this.chavePix.trim(), senha }));
        } catch (error) {
            this.notifications.serverError(error);
            return;
        }
        this.comecouEm = Date.now();
        this.agora = this.comecouEm;
        yield this.acompanhar.perform();
    }

    /** Relê a conexão a cada 3 s até a validação confirmar ou passar de 60 s (resultadoDoTeste). */
    @task({ restartable: true }) *acompanhar() {
        while (resultadoDoTeste(this.args.conexao, this.comecouEm, Date.now()) === 'esperando') {
            yield timeout(INTERVALO_DO_TESTE_MS);
            this.agora = Date.now();
            try {
                this.args.aoMudar(yield this.fetch.get(API_DO_SAQUE_PIX));
            } catch (error) {
                // uma leitura que falhou não encerra a espera: tenta de novo no próximo intervalo
            }
        }
        this.agora = Date.now();
    }
}
