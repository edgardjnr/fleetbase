import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { arrayFor, valueFor } from '../../../utils/model-access';
import { pedidosEmAndamento } from '../../../utils/entregas-pedido';
import { prazoDoPedido } from '../../../utils/entregas-cronometro';
import { FILTROS, contagens, ordenarPorPrazo, passaNoFiltro, situacaoDoPedido } from '../../../utils/portal-da-loja';

const TEXTOS = {
    sem: 'customer-portal.ui.entregas.s.contagem.sem',
    atrasado: 'customer-portal.ui.entregas.s.contagem.atrasado',
    loja: 'customer-portal.ui.entregas.s.contagem.loja',
    caminho: 'customer-portal.ui.entregas.s.contagem.caminho',
};

/** Alturas da folha de baixo no celular, na ordem em que a alça troca. */
const ALTURAS = ['media', 'alta', 'baixa'];

/**
 * Entregas: a tela Pedidos do portal no desenho do sketch 009 S (ver workspace.hbs). Monta a lista da direita (só os em
 * andamento, no filtro das contagens e na ordem do prazo de entrega), as contagens e a altura da folha no celular.
 */
export default class PortalOrderWorkspaceComponent extends Component {
    @service hostRouter;
    @service customerPortalOrderCreation;
    @service entregasConversas;
    @service entregasMapaDaLoja;
    @service entregasRelogio;

    @tracked filtro = null;
    @tracked alturaDaFolha = 'media';

    // A lista de conversas é acompanhada pelo topo (Portal::Topo), em todas as telas. Ao sair da tela Pedidos, só a folha
    // das Conversas fecha
    willDestroy() {
        super.willDestroy(...arguments);
        this.entregasConversas.fecharGaveta();
    }

    get currentRouteName() {
        return this.hostRouter.currentRouteName ?? '';
    }

    get hasPanel() {
        return this.currentRouteName.includes('.new') || this.currentRouteName.includes('.details');
    }

    get isCreating() {
        return this.currentRouteName.includes('.new');
    }

    // a folha da direita está com algo aberto (pedido, novo pedido ou Conversas): a lista e as contagens saem
    get ladoAberto() {
        return this.hasPanel || this.entregasConversas.aberta;
    }

    get showTable() {
        return this.args.isTableView && !this.hasPanel;
    }

    // a situação de cada pedido em andamento; o aceite vem do motoboy do pedido no mapa (o mesmo do card)
    get emAndamento() {
        const agora = this.entregasRelogio.agora;

        return pedidosEmAndamento(arrayFor(this.args.orders), (pedido) => valueFor(pedido, 'status')).map((pedido) => ({
            pedido,
            situacao: situacaoDoPedido(
                {
                    status: valueFor(pedido, 'status'),
                    aceito: Boolean(this.entregasMapaDaLoja.motoboyDoPedido(valueFor(pedido, 'public_id'))),
                    prazoEm: prazoDoPedido(pedido),
                },
                agora
            ),
        }));
    }

    // só os em andamento (os concluídos e cancelados ficam na Tabela), no filtro escolhido e na ordem do prazo de
    // entrega: quem vence primeiro em cima, os atrasados no topo e os sem prazo no fim (decisão de 2026-10-26)
    get pedidosDaLista() {
        const filtrados = this.emAndamento.filter((item) => passaNoFiltro(item.situacao, this.filtro)).map((item) => item.pedido);

        return ordenarPorPrazo(filtrados, prazoDoPedido, (pedido) => valueFor(pedido, 'created_at'));
    }

    get contagens() {
        const total = contagens(this.emAndamento.map((item) => item.situacao));

        return FILTROS.map((chave) => ({
            chave,
            n: total[chave],
            texto: TEXTOS[chave],
            alerta: (chave === 'sem' || chave === 'atrasado') && total[chave] > 0,
            ativa: this.filtro === chave,
        }));
    }

    @action filtrar(chave) {
        this.filtro = this.filtro === chave ? null : chave;
    }

    @action alternarFolha() {
        this.alturaDaFolha = ALTURAS[(ALTURAS.indexOf(this.alturaDaFolha) + 1) % ALTURAS.length];
    }

    @action search(event) {
        if (typeof this.args.onSearch === 'function') {
            this.args.onSearch(event.target.value);
        }
    }

    @action reloadOrders() {
        if (typeof this.args.onReload === 'function') {
            this.args.onReload();
        }
    }
}
