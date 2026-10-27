import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { restartableTask } from 'ember-concurrency';
import { ordenarCartoes, totalConectadas, trocarCartao } from '../../utils/integracoes-da-loja';

/**
 * Entregas: Integrações do portal da loja. A loja escolhe um sistema (iFood, RestaurantePro…) e conecta sozinha, sem
 * passar pela central. Os cartões vêm de GET int/v1/entregas/loja/integracoes (CatalogoDeIntegracoes no servidor); a
 * conexão de cada um fica no componente do fluxo dele (Portal::Integracoes::Ifood ou ::Chave), aberto pelo
 * `?sistema=<id>`.
 */
export default class PortalIntegracoesController extends Controller {
    @service fetch;
    @service notifications;

    queryParams = ['sistema'];

    @tracked sistema = null;
    @tracked integracoes = null;

    get cartoes() {
        return ordenarCartoes(this.integracoes);
    }

    get conectadas() {
        return totalConectadas(this.integracoes);
    }

    // o sistema aberto; id desconhecido (link antigo) volta para a lista
    get aberta() {
        return this.sistema ? (this.integracoes ?? []).find((integracao) => integracao.id === this.sistema) ?? null : null;
    }

    @restartableTask *carregar() {
        try {
            const resposta = yield this.fetch.get('entregas/loja/integracoes');
            this.integracoes = Array.isArray(resposta?.integracoes) ? resposta.integracoes : [];
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    // Entregas: a releitura automática enquanto um sistema por chave espera a conexão; sem aviso de erro (a próxima volta
    // tenta de novo, e o aviso a cada 10 s cansaria)
    @restartableTask *reler() {
        try {
            const resposta = yield this.fetch.get('entregas/loja/integracoes');
            if (Array.isArray(resposta?.integracoes)) {
                this.integracoes = resposta.integracoes;
            }
        } catch {
            // sem aviso: ver acima
        }
    }

    // Entregas: chamado pela rota ao sair da tela
    reiniciar() {
        this.carregar.cancelAll();
        this.reler.cancelAll();
        this.integracoes = null;
        this.sistema = null;
    }

    @action atualizar() {
        return this.carregar.perform();
    }

    @action abrir(integracao) {
        this.sistema = integracao?.id ?? null;
    }

    @action voltar() {
        this.sistema = null;
    }

    // o componente do fluxo devolve o cartão novo depois de conectar ou desconectar
    @action mudou(cartao) {
        this.integracoes = trocarCartao(this.integracoes, cartao);
    }
}
