import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task, timeout, waitForEvent, race } from 'ember-concurrency';
import { iniciais, numeroDoResumo } from '../../utils/gaveta-do-menu';
import { EVENTO_DA_CARTEIRA, ehPrePaga, resumoDaCarteira } from '../../utils/carteira-da-loja';

/**
 * A contagem de Pedidos na aba (pedidos em andamento) é relida a cada minuto, com a aba do navegador à vista, e na hora
 * em que uma recarga da carteira é paga (EVENTO_DA_CARTEIRA).
 */
const INTERVALO_DO_RESUMO_MS = 60000;

/**
 * Entregas: o topo do portal da loja no desenho do sketch 009 S (variante C da moldura): a loja (iniciais, nome e
 * "Portal da loja"), as abas Início, Pedidos (com os em andamento), Extrato e Financeiro (na loja pré-paga, Minha
 * carteira), o Novo pedido, as Conversas com as não lidas e o avatar (Configurações, tema escuro e sair). Fica em todas
 * as telas do portal, então é ele quem acompanha a lista de conversas (o número de não lidas e o aviso sonoro) enquanto o
 * portal está aberto.
 */
export default class PortalTopoComponent extends Component {
    @service entregasConversas;
    @service hostRouter;
    @service fetch;
    @service currentUser;
    @tracked resumo = null;

    constructor() {
        super(...arguments);
        this.entregasConversas.acompanharLista.perform();
        this.acompanharResumo.perform();
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this.entregasConversas.encerrar();
    }

    get iniciais() {
        return iniciais(this.args.nomeDaConta);
    }

    get emAndamento() {
        const n = numeroDoResumo(this.resumo?.em_andamento);

        // sem pedido em andamento (ou sem o número), a aba fica sem a contagem
        return n === '–' || n === '0' ? null : n;
    }

    // a loja pré-paga troca o Financeiro pela Minha carteira (pelo `carteira` do resumo do dia; sem resumo, Financeiro)
    get prePaga() {
        return ehPrePaga(this.resumo);
    }

    // o crédito acabou (o servidor recusa pedido novo): o pontinho de alerta na aba da carteira
    get semCredito() {
        return resumoDaCarteira(this.resumo?.carteira)?.podePedir === false;
    }

    get naoLidas() {
        return this.entregasConversas.naoLidas;
    }

    // a fatura é uma rota irmã do Financeiro (portal.fatura): a aba Financeiro acende nela também
    get naFatura() {
        return this.hostRouter.currentRouteName === 'customer-portal.portal.fatura';
    }

    get naTelaDePedidos() {
        return (this.hostRouter.currentRouteName ?? '').startsWith('customer-portal.portal.orders');
    }

    @task({ restartable: true }) *acompanharResumo() {
        while (!this.isDestroying) {
            if (!document.hidden) {
                try {
                    this.resumo = yield this.fetch.get('entregas/loja/resumo-do-dia');
                } catch {
                    // sem o número: a aba Pedidos fica sem a contagem até a próxima volta (e a aba da carteira como estava)
                }
            }
            yield race([timeout(INTERVALO_DO_RESUMO_MS), waitForEvent(document, 'visibilitychange'), waitForEvent(document, EVENTO_DA_CARTEIRA)]);
        }
    }

    // As Conversas abrem na folha da direita da tela Pedidos, no lugar da lista; de outra tela, vai para o mapa e abre
    @action async abrirConversas() {
        if (this.naTelaDePedidos) {
            this.entregasConversas.alternarGaveta();

            return;
        }

        await this.hostRouter.transitionTo('customer-portal.portal.orders.index', { queryParams: { view: 'map' } });
        this.entregasConversas.abrirGaveta();
    }
}
