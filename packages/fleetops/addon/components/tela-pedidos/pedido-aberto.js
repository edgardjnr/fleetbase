import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import { formatadores } from '../../utils/financeiro';
import { ehPedidoIfood } from '../../utils/pedido-ifood';
import { nomeEmCaixaNormal, cupomDosItens } from '../../utils/pedido-aberto';

const P = 'fleet-ops.ui.tela-pedidos.pedido-aberto';
// as mesmas relações do painel antigo do Fleetbase (rota operations.orders.index.details), para as ações do "Mais"
const RELACOES_DO_PEDIDO = ['payload', 'driverAssigned', 'orderConfig', 'customer', 'facilitator', 'trackingStatuses', 'trackingNumber'];

/**
 * Entregas: a folha do pedido aberto no mapa do /fleet-ops (sketch 008 A), no lugar do painel Pedidos (no celular,
 * dentro da folha de baixo). Segue a comanda do app do motoboy: número e "para <cliente>", motoboy (ou a oferta) com
 * Atribuir/Trocar, cobrança, endereço com complemento, referência e a observação do cliente, os produtos em cupom e a
 * loja da coleta. Sem 0800 nem localizador: só o motoboy do pedido recebe (regra do servidor).
 *
 * O "Mais" junta o que era do painel do Fleetbase: linha do tempo, valores (só admin), os painéis iFood, Integração e
 * Distribuição e as ações (editar rota, editar detalhes, atualizar status, desatribuir, etiqueta, metadados, cancelar,
 * excluir). O pedido do Ember Data só é carregado quando o "Mais" abre.
 *
 * @pedido   linha do pedido (linhaDoPedido do mapa, com situacao/encerrado), ou null enquanto a comanda não chega
 * @comanda  GET entregas/pedidos/{id}/comanda (null enquanto carrega)
 */
export default class TelaPedidosPedidoAbertoComponent extends Component {
    @service store;
    @service intl;
    @service orderActions;
    @service notifications;

    @tracked mais = false;
    @tracked pedidoDoFleetbase = null;

    get f() {
        return formatadores(this.intl.primaryLocale);
    }

    get comanda() {
        return this.args.comanda ?? null;
    }

    get pedido() {
        return this.args.pedido ?? null;
    }

    get carregando() {
        return !this.comanda && !this.args.falhou;
    }

    get numero() {
        if (this.pedido?.numeroTexto) return this.pedido.numeroTexto;
        const numero = this.comanda?.numero;
        if (!numero) return '';
        return this.comanda.origem === 'ifood' ? `#${numero}` : numero;
    }

    get nomeDoCliente() {
        return nomeEmCaixaNormal(this.comanda?.cliente?.nome);
    }

    get bairro() {
        const c = this.comanda?.cliente;
        return [c?.bairro, c?.cidade].filter(Boolean).join(' · ');
    }

    get endereco() {
        return this.comanda?.cliente?.endereco || this.pedido?.endereco || '';
    }

    get seloDaOrigem() {
        if (this.comanda?.origem === 'ifood' || this.pedido?.ifood) return { classe: 'tp-selo-ifood', texto: 'iFood' };
        if (this.comanda?.origem === 'integracao') return { classe: 'tp-selo-sistema', texto: this.comanda.sistema || this.intl.t(`${P}.sistema`) };
        return null;
    }

    get situacao() {
        return this.pedido?.situacao ?? null;
    }

    get encerrado() {
        return this.situacao === 'concluido' || this.situacao === 'cancelado';
    }

    get ehOferta() {
        return !this.encerrado && !!this.pedido?.ehOferta;
    }

    /** Pedido concluído: "Entregue por Carlos às 19:42" e "31 min do pedido à entrega". */
    get conclusao() {
        const conclusao = this.comanda?.conclusao;
        const motoboy = this.comanda?.motoboy?.nome ?? this.pedido?.motoboy ?? '';
        const titulo = conclusao?.em ? this.intl.t(`${P}.entregue`, { motoboy, hora: this.hora(conclusao.em) }) : this.intl.t(`${P}.situacao.concluido`);
        const detalhe = Number.isFinite(conclusao?.minutos) ? this.intl.t(`${P}.entregue-minutos`, { minutos: conclusao.minutos }) : null;
        return { titulo, detalhe };
    }

    /** "Cobrar na porta" com o valor, a forma e o troco; ou "Pago online · nada a cobrar". Sem a linha do iFood/integração, null. */
    get cobranca() {
        const c = this.comanda?.cobranca;
        if (!c) return null;
        const centavos = Number(c.centavos) || 0;
        const cobrar = centavos > 0 && c.forma !== 'pago';
        if (!cobrar) return { cobrar, titulo: this.intl.t(`${P}.pago`), detalhe: null, valor: null };
        const troco = Number(c.troco_para_centavos) > centavos ? this.intl.t(`${P}.troco`, { valor: this.f.valor(c.troco_para_centavos / 100) }) : null;
        return { cobrar, titulo: this.intl.t(`${P}.cobrar`), detalhe: [c.forma, troco].filter(Boolean).join(' · ') || null, valor: this.f.valor(centavos / 100) };
    }

    get cupom() {
        return cupomDosItens(this.comanda?.itens);
    }

    get loja() {
        const l = this.comanda?.loja;
        return { nome: l?.nome || this.pedido?.loja || '', endereco: l?.endereco || '', telefone: l?.telefone || null };
    }

    get linhaDoTempo() {
        return (this.comanda?.linha_do_tempo ?? []).map((item) => ({ ...item, hora: this.hora(item.em) }));
    }

    get valores() {
        const v = this.comanda?.valores;
        if (!v) return null;
        const motoboy = Number.isFinite(Number(v.valor_motoboy)) && v.valor_motoboy !== null ? Number(v.valor_motoboy) : null;
        const loja = Number.isFinite(Number(v.valor_loja)) && v.valor_loja !== null ? Number(v.valor_loja) : null;
        return {
            km: v.km !== null && v.km !== undefined ? this.f.km(v.km) : null,
            motoboy: motoboy !== null ? this.f.valor(motoboy) : null,
            loja: loja !== null ? this.f.valor(loja) : null,
            margem: motoboy !== null && loja !== null ? this.f.valor(loja - motoboy) : null,
            multiplicador: textoDoMultiplicador(v.multiplicador),
        };
    }

    get kmDaRota() {
        const metros = this.comanda?.rota?.metros;
        return Number.isFinite(metros) ? this.f.km(metros / 1000) : null;
    }

    get minutosDaRota() {
        const segundos = this.comanda?.rota?.segundos;
        return Number.isFinite(segundos) ? Math.max(1, Math.round(segundos / 60)) : null;
    }

    get ehIfood() {
        return this.comanda?.origem === 'ifood' || (this.pedidoDoFleetbase && ehPedidoIfood(this.pedidoDoFleetbase));
    }

    get podeCancelar() {
        return !this.encerrado && !this.ehIfood;
    }

    get podeDesatribuir() {
        return !this.encerrado && !!this.pedido?.motoboy_id;
    }

    hora(iso) {
        const data = iso ? new Date(iso) : null;
        if (!data || Number.isNaN(data.getTime())) return '';
        return new Intl.DateTimeFormat(this.intl.primaryLocale || 'pt-BR', { hour: '2-digit', minute: '2-digit' }).format(data);
    }

    /* ---------- Mais ---------- */

    @action alternarMais() {
        this.mais = !this.mais;
        if (this.mais && this.pedidoDoFleetbase?.public_id !== this.args.id) this.carregarPedido.perform();
    }

    @task *carregarPedido() {
        try {
            this.pedidoDoFleetbase = yield this.store.queryRecord('order', { public_id: this.args.id, single: true, with: RELACOES_DO_PEDIDO });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /** Ação do Fleetbase sobre o pedido (os modais são os mesmos do painel antigo). Relê a comanda ao terminar. */
    @action acao(nome) {
        const pedido = this.pedidoDoFleetbase;
        if (!pedido) return;
        const recarregar = () => this.args.aoRecarregar?.();
        switch (nome) {
            case 'rota':
                return this.orderActions.editRoute(pedido);
            case 'detalhes':
                return this.orderActions.editOrderDetails(pedido, { onFinish: recarregar });
            case 'status':
                return this.orderActions.updateActivity(pedido, { onFinish: recarregar });
            case 'desatribuir':
                return this.orderActions.unassignDriver(pedido, { onFinish: recarregar });
            case 'etiqueta':
                return this.orderActions.viewLabel(pedido);
            case 'metadados':
                return this.orderActions.viewMetadata(pedido);
            case 'cancelar':
                return this.orderActions.cancel(pedido, { onFinish: recarregar });
            case 'excluir':
                return this.orderActions.delete(pedido, { taskOptions: { callback: () => this.args.aoFechar?.() } });
        }
    }

    @action recarregar() {
        this.args.aoRecarregar?.();
    }
}

/** O multiplicador do pedido ({nome, motoboy, loja} do CalculoEntregas) em texto: "Chuva · motoboy ×1,2 · loja ×1,5". */
function textoDoMultiplicador(m) {
    if (!m || typeof m !== 'object') return null;
    const fator = (n) => (Number.isFinite(Number(n)) && Number(n) !== 1 ? `×${String(Number(n)).replace('.', ',')}` : null);
    const motoboy = fator(m.motoboy);
    const loja = fator(m.loja);
    if (!motoboy && !loja) return null;
    return [m.nome, motoboy && `motoboy ${motoboy}`, loja && `loja ${loja}`].filter(Boolean).join(' · ');
}
