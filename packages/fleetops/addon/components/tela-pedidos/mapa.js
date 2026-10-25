import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import TelaDePedidosBase from '../../utils/tela-de-pedidos-base';
import { CAPACETES } from '../../utils/entregas-capacete';
import { FILTROS, contagens, filaDoPainel, resumoDosMotoboys, situacaoDoPedido } from '../../utils/painel-inicial';
import { FILTROS_DOS_MOTOBOYS, motoboysDaTela, filtrarMotoboys, passaNoFiltro } from '../../utils/tela-de-pedidos';

const P = 'fleet-ops.ui.tela-pedidos';
const PI = 'fleet-ops.ui.painel-inicial';
const CELULAR = '(max-width: 767px)';
// largura dos painéis + margem (o enquadramento do mapa desconta)
const LARGURA_MOTOBOYS = 300;
const LARGURA_PEDIDOS = 390;

/**
 * Entregas: vista Mapa da tela de pedidos (/fleet-ops; sketch 005, variante A invertida). Mapa em tela cheia com o
 * painel Motoboys flutuando à esquerda e o Pedidos à direita (recolhíveis), as contagens no topo (filtram mapa e
 * lista) e o detalhe do pedido escolhido embaixo, junto aos Pedidos. No celular, a folha de baixo com Pedidos e
 * Motoboys.
 */
export default class TelaPedidosMapaComponent extends TelaDePedidosBase {
    @tracked filtro = 'todos';
    @tracked filtroDosMotoboys = 'todos';
    @tracked selecionado = null;
    @tracked motoboySelecionado = null;
    @tracked painelPedidos = true;
    @tracked painelMotoboys = true;
    @tracked celular = false;
    @tracked abaDoCelular = 'pedidos';
    @tracked folha = 'meia';
    @tracked lojas = [];

    constructor() {
        super(...arguments);
        this.carregarLojas.perform();
        if (typeof window !== 'undefined' && window.matchMedia) {
            this._consulta = window.matchMedia(CELULAR);
            this.celular = this._consulta.matches;
            this._aoMudarTela = (evento) => (this.celular = evento.matches);
            this._consulta.addEventListener?.('change', this._aoMudarTela);
        }
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this._consulta?.removeEventListener?.('change', this._aoMudarTela);
    }

    /* ---------- contagens e filtros ---------- */

    get contagem() {
        return contagens(this.aoVivo.pedidos, this.agora);
    }

    get online() {
        return resumoDosMotoboys(this.aoVivo.motoboys);
    }

    get chips() {
        const c = this.contagem;
        const t = (chave) => this.intl.t(`${P}.contagens.${chave}`);
        return [
            { chave: 'oferta', icone: 'tower-broadcast', n: c.oferta, texto: t('oferta'), alerta: c.oferta >= 2 },
            { chave: 'atrasado', icone: 'clock', n: c.atrasado, texto: t('atrasado'), alerta: c.atrasado > 0 },
            { chave: 'loja', capacete: CAPACETES.coleta, n: c.loja, texto: t('loja') },
            { chave: 'rota', capacete: CAPACETES.entrega, n: c.rota, texto: t('rota') },
        ].map((chip) => ({ ...chip, ativo: this.filtro === chip.chave }));
    }

    get textoDosLivres() {
        return this.intl.t(`${P}.contagens.livres`, { livres: this.online.livres, online: this.online.online });
    }

    get filtros() {
        const c = this.contagem;
        return FILTROS.map((chave) => ({
            chave,
            texto: this.intl.t(`${PI}.filtros.${chave}`),
            n: c[chave],
            ativo: this.filtro === chave,
            alerta: (chave === 'oferta' || chave === 'atrasado') && c[chave] > 0,
        }));
    }

    get textoDoVazio() {
        return this.intl.t(`${PI}.vazio.${this.filtro}`);
    }

    /* ---------- listas ---------- */

    get pedidos() {
        return filaDoPainel(this.aoVivo.pedidos, this.filtro, this.agora)
            .filter((pedido) => !this.motoboySelecionado || pedido.motoboy_id === this.motoboySelecionado)
            .map((pedido) => ({ ...this.linhaDoPedido(pedido), selecionado: pedido.id === this.selecionado }));
    }

    get motoboys() {
        const textos = { livre: this.intl.t(`${P}.motoboy.livre`), offline: this.intl.t(`${P}.motoboy.offline`) };
        return filtrarMotoboys(motoboysDaTela(this.aoVivo.motoboys, this.aoVivo.pedidos), this.filtroDosMotoboys).map((motoboy) => {
            let detalhe = textos[motoboy.situacao] ?? '';
            if (motoboy.pedido) {
                const numero = motoboy.pedido.ifood ? `#${motoboy.pedido.numero}` : motoboy.pedido.numero;
                const chave = situacaoDoPedido(motoboy.pedido) === 'rota' ? 'motoboy.a-caminho' : 'motoboy.indo';
                detalhe = this.intl.t(`${P}.${chave}`, { numero, loja: motoboy.pedido.loja ?? '' });
                if (motoboy.outros) detalhe += ` · +${motoboy.outros}`;
            }
            return { ...motoboy, capacete: CAPACETES[motoboy.situacao] ?? CAPACETES.offline, detalhe, selecionado: motoboy.id === this.motoboySelecionado };
        });
    }

    get filtrosDosMotoboys() {
        const textos = { todos: this.intl.t(`${P}.motoboys.todos`), livre: this.intl.t(`${P}.motoboys.livres`, { n: this.online.livres }), ocupado: this.intl.t(`${P}.motoboys.ocupados`) };
        return FILTROS_DOS_MOTOBOYS.map((chave) => ({ chave, texto: textos[chave], ativo: this.filtroDosMotoboys === chave }));
    }

    get pedidoSelecionado() {
        const pedido = this.aoVivo.pedidos.find((p) => p.id === this.selecionado);
        if (!pedido) return null;
        const linha = this.linhaDoPedido(pedido);
        const motoboy = this.aoVivo.motoboys.find((m) => m.id === pedido.motoboy_id);
        return { ...linha, motoboyDoPedido: motoboy ? { ...motoboy, capacete: CAPACETES[motoboy.situacao] ?? CAPACETES.offline } : null };
    }

    /* ---------- mapa ---------- */

    get pedidosDoMapa() {
        return this.aoVivo.pedidos.map((pedido) => {
            const linha = this.linhaDoPedido(pedido);
            const selecionado = pedido.id === this.selecionado;
            const apagado =
                (!!this.selecionado && !selecionado) ||
                !passaNoFiltro(pedido, this.filtro, this.agora) ||
                (!!this.motoboySelecionado && pedido.motoboy_id !== this.motoboySelecionado);
            return { ...linha, selecionado, apagado, comRotulo: !linha.ehOferta && !!pedido.aceito };
        });
    }

    get motoboysDoMapa() {
        const doSelecionado = this.pedidoSelecionado?.motoboy_id ?? null;
        return this.aoVivo.motoboys.map((motoboy) => {
            const pedido = this.aoVivo.pedidos.find((p) => p.motoboy_id === motoboy.id);
            const selecionado = motoboy.id === this.motoboySelecionado || motoboy.id === doSelecionado;
            const apagado =
                !selecionado &&
                ((!!this.selecionado && motoboy.id !== doSelecionado) ||
                    (!!this.motoboySelecionado && motoboy.id !== this.motoboySelecionado) ||
                    (this.filtro !== 'todos' && !(pedido && passaNoFiltro(pedido, this.filtro, this.agora))));
            return { ...motoboy, selecionado, apagado };
        });
    }

    get margens() {
        if (this.celular) return { esquerda: 0, direita: 0, baixo: 260 };
        return { esquerda: this.painelMotoboys ? LARGURA_MOTOBOYS : 0, direita: this.painelPedidos ? LARGURA_PEDIDOS : 0, baixo: this.selecionado ? 280 : 0 };
    }

    /* ---------- ações ---------- */

    @action escolherFiltro(chave) {
        this.filtro = this.filtro === chave && chave !== 'todos' ? 'todos' : chave;
        this.selecionado = null;
    }

    @action escolherFiltroDosMotoboys(chave) {
        this.filtroDosMotoboys = this.filtroDosMotoboys === chave && chave !== 'todos' ? 'todos' : chave;
    }

    @action escolherPedido(id) {
        this.selecionado = this.selecionado === id ? null : id;
        this.motoboySelecionado = null;
        this.atribuindo = null;
        if (this.celular && this.selecionado && this.folha === 'baixa') this.folha = 'meia';
    }

    @action escolherMotoboy(id) {
        this.motoboySelecionado = this.motoboySelecionado === id ? null : id;
        this.selecionado = null;
    }

    /** Atribuir na linha: escolhe o pedido e abre os candidatos no detalhe (a lista fica num lugar só). */
    @action atribuirPedido(id, evento) {
        evento?.stopPropagation?.();
        this.selecionado = id;
        this.motoboySelecionado = null;
        this.atribuindo = id;
        if (this.celular && this.folha === 'baixa') this.folha = 'meia';
    }

    @action fecharDetalhe() {
        this.selecionado = null;
        this.atribuindo = null;
    }

    @action alternarPainel(qual) {
        this[qual] = !this[qual];
    }

    @action escolherAba(aba) {
        this.abaDoCelular = aba;
        if (this.folha === 'baixa') this.folha = 'meia';
    }

    @action mexerNaFolha() {
        this.folha = { baixa: 'meia', meia: 'alta', alta: 'baixa' }[this.folha];
    }

    @task *carregarLojas() {
        try {
            this.lojas = yield this.fetch.get('entregas/mapa/locais-de-coleta', {}, { normalizeToEmberData: true, normalizeModelType: 'place' });
        } catch {
            this.lojas = [];
        }
    }
}
