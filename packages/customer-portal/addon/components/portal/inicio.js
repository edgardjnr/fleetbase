import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task, timeout, waitForEvent, race } from 'ember-concurrency';
import { arrayFor, valueFor } from '../../utils/model-access';
import { pedidosEmAndamento, espera } from '../../utils/entregas-pedido';
import { prazoDoPedido } from '../../utils/entregas-cronometro';
import { numerosDoInicio, ordenarPorPrazo, saudacao, situacaoDoPedido } from '../../utils/portal-da-loja';
import { ehPrePaga } from '../../utils/carteira-da-loja';

/** O Início relê os números e os pedidos a cada 30 s, com a aba à vista (espera crescente em erro). */
const INTERVALO_MS = 30000;

/**
 * Entregas: o Início do portal da loja (sketch 009 S, a tela 1 da variante B): a faixa laranja com a saudação, o resumo e
 * o Novo pedido; os seis números de hoje (pedidos hoje, em andamento, entregues, no prazo, tempo médio e a pagar no
 * ciclo), cada um levando à sua tela; e o Ao vivo, com a lista dos pedidos em andamento (na ordem do prazo, como na tela
 * Pedidos) e o mapa com os motoboys. Números de `entregas/loja/resumo-do-dia` e do `ciclo_atual` de
 * `entregas/loja/financeiro`. Na loja pré-paga, o sexto número é o crédito disponível (o `carteira` do resumo do dia,
 * levando à Minha carteira), e o Financeiro deixa de ser lido.
 */
export default class PortalInicioComponent extends Component {
    @service fetch;
    @service customerPortalOrderActions;
    @service entregasMapaDaLoja;
    @service entregasRelogio;
    @service intl;

    @tracked resumo = null;
    @tracked ciclo = null;
    @tracked pedidos = [];
    @tracked carregou = false;

    constructor() {
        super(...arguments);
        this.acompanhar.perform();
    }

    @task({ restartable: true }) *acompanhar() {
        let falhas = 0;

        while (!this.isDestroying) {
            if (!document.hidden) {
                // a loja pré-paga (pelo último resumo) não tem ciclo de fatura: o número vem do próprio resumo
                const prePaga = ehPrePaga(this.resumo);
                const [resumo, financeiro, pedidos] = yield Promise.all([
                    this.fetch.get('entregas/loja/resumo-do-dia').catch(() => undefined),
                    prePaga ? Promise.resolve(undefined) : this.fetch.get('entregas/loja/financeiro').catch(() => undefined),
                    this.customerPortalOrderActions.atualizarPedidos.perform({}).catch(() => undefined),
                ]);
                if (this.isDestroying) {
                    return;
                }
                // uma leitura que falhou mantém o que já estava na tela
                if (resumo !== undefined) this.resumo = resumo;
                if (financeiro !== undefined) this.ciclo = financeiro?.ciclo_atual ?? null;
                if (pedidos !== undefined) this.pedidos = pedidos;
                falhas = resumo === undefined && pedidos === undefined ? falhas + 1 : 0;
                this.carregou = true;
            }
            yield race([timeout(espera(INTERVALO_MS, falhas)), waitForEvent(document, 'visibilitychange')]);
        }
    }

    get saudacao() {
        return saudacao(new Date(this.entregasRelogio.agora).getHours());
    }

    get numeros() {
        return numerosDoInicio(this.resumo, this.ciclo);
    }

    // os em andamento, na ordem do prazo de entrega (a mesma da tela Pedidos)
    get emAndamento() {
        const lista = pedidosEmAndamento(arrayFor(this.pedidos), (pedido) => valueFor(pedido, 'status'));

        return ordenarPorPrazo(lista, prazoDoPedido, (pedido) => valueFor(pedido, 'created_at'));
    }

    get procurando() {
        const agora = this.entregasRelogio.agora;

        return this.emAndamento.filter(
            (pedido) =>
                situacaoDoPedido(
                    { status: valueFor(pedido, 'status'), aceito: Boolean(this.entregasMapaDaLoja.motoboyDoPedido(valueFor(pedido, 'public_id'))), prazoEm: null },
                    agora
                ).chave === 'sem'
        ).length;
    }

    get detalheDoCiclo() {
        const n = this.numeros.entregasDoCiclo;

        return n === null ? '' : this.intl.t('customer-portal.ui.entregas.s.inicio.entregas-no-ciclo', { n });
    }
}
