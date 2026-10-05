import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { contagem, limparCodigo, linkSeguro, segundosRestantes, vencimentoDoCodigo } from '../../utils/vinculo-ifood';

const ENDPOINT = 'entregas/lojas';

/**
 * Entregas RestaurantePro: modal "Vincular iFood" da tela Lojas, pelo fluxo distribuído do iFood
 * (api/app/Http/Controllers/Entregas/IfoodLojasController.php):
 * 1. ao abrir, pede o código de vínculo (POST lojas/{id}/ifood/codigo) e mostra o código grande, o link do Portal do
 *    Parceiro com o código preenchido e a contagem de 10 min. Vencido, oferece um código novo;
 * 2. a central cola o código de autorização que o dono da loja recebeu no portal e vincula (POST .../ifood/vincular);
 * 3. com várias lojas na conta do iFood, a central escolhe uma (POST .../ifood/vincular com merchant_id).
 * Os botões ficam no corpo (o rodapé só tem Fechar). Vinculada, avisa a tela (options.onVinculado) e fecha.
 * Depois de um erro ao vincular ou escolher (código vencido, recusado, loja em outra conta: o servidor responde 422 e o
 * console não repassa o status), "Gerar outro código" aparece também na escolha e com o código ainda válido.
 */
export default class ModalsVincularIfoodComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;
    @service modalsManager;

    // { codigo, link } do código de vínculo atual; null enquanto gera ou se falhou
    @tracked codigo = null;
    @tracked vencimento = 0;
    @tracked agora = Date.now();
    @tracked autorizacao = '';
    // lojas da conta do iFood para a central escolher (vazio = não está escolhendo)
    @tracked lojasDoIfood = [];
    // o último vincular/escolher deu erro: oferece "Gerar outro código" sem precisar fechar e abrir de novo
    @tracked falhou = false;

    constructor(owner, { options }) {
        super(...arguments);
        this.options = options;
        this.loja = options.loja;
        this.relogio = setInterval(() => {
            this.agora = Date.now();
        }, 1000);
        this.gerarCodigo.perform();
    }

    willDestroy() {
        super.willDestroy(...arguments);
        clearInterval(this.relogio);
    }

    get segundos() {
        return segundosRestantes(this.vencimento, this.agora);
    }

    get venceu() {
        return Boolean(this.codigo) && this.segundos === 0;
    }

    get tempo() {
        return contagem(this.segundos);
    }

    get escolhendo() {
        return this.lojasDoIfood.length > 0;
    }

    get podeVincular() {
        return Boolean(this.codigo) && !this.venceu && limparCodigo(this.autorizacao) !== '';
    }

    @action setAutorizacao(event) {
        this.autorizacao = event.target.value;
    }

    @task({ drop: true }) *gerarCodigo() {
        this.codigo = null;
        this.autorizacao = '';
        this.lojasDoIfood = [];
        this.falhou = false;

        try {
            const resposta = yield this.fetch.post(`${ENDPOINT}/${this.loja.id}/ifood/codigo`);
            this.vencimento = vencimentoDoCodigo(resposta.expira_em_segundos, Date.now());
            this.agora = Date.now();
            this.codigo = { codigo: resposta.codigo, link: linkSeguro(resposta.link) };
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *vincular() {
        if (!this.podeVincular) {
            return;
        }

        try {
            const resposta = yield this.fetch.post(`${ENDPOINT}/${this.loja.id}/ifood/vincular`, { authorizationCode: limparCodigo(this.autorizacao) });
            this.falhou = false;
            this.concluir(resposta);
        } catch (error) {
            this.falhou = true;
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *escolher(lojaDoIfood) {
        try {
            const resposta = yield this.fetch.post(`${ENDPOINT}/${this.loja.id}/ifood/vincular`, { merchant_id: lojaDoIfood.id });
            this.falhou = false;
            this.concluir(resposta);
        } catch (error) {
            this.falhou = true;
            this.notifications.serverError(error);
        }
    }

    concluir(resposta) {
        if (Array.isArray(resposta?.escolher)) {
            this.lojasDoIfood = resposta.escolher;
            return;
        }

        if (resposta?.loja && typeof this.options.onVinculado === 'function') {
            this.options.onVinculado(resposta.loja);
        }
        this.notifications.success(this.intl.t('fleet-ops.ui.lojas.ifood.linked-success'));
        this.modalsManager.done();
    }
}
