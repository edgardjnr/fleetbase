import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import TelaDePedidosBase from '../../utils/tela-de-pedidos-base';
import { CAPACETES } from '../../utils/entregas-capacete';
import { FILTROS, contagens, filaDoPainel, resumoDosMotoboys, situacaoDoPedido } from '../../utils/painel-inicial';
import { FILTROS_DOS_MOTOBOYS, motoboysDaTela, filtrarMotoboys, passaNoFiltro } from '../../utils/tela-de-pedidos';
import { trechosDaRota, situacaoDaComanda } from '../../utils/pedido-aberto';

const P = 'fleet-ops.ui.tela-pedidos';
const PI = 'fleet-ops.ui.painel-inicial';
const CELULAR = '(max-width: 767px)';
// largura dos painéis + margem (o enquadramento do mapa desconta)
const LARGURA_MOTOBOYS = 300;
const LARGURA_PEDIDOS = 390;
// folha do pedido aberto (no lugar do painel Pedidos) + margem
const LARGURA_FOLHA = 470;
// releitura da comanda com o pedido aberto (o Ao vivo já relê nos eventos; isto pega o que ele não traz)
const RELER_COMANDA_MS = 60 * 1000;

/**
 * Entregas: vista Mapa da tela de pedidos (/fleet-ops; sketch 005, variante A invertida). Mapa em tela cheia com o
 * painel Motoboys flutuando à esquerda e o Pedidos à direita (recolhíveis) e as contagens no topo (filtram mapa e
 * lista). No celular, a folha de baixo com Pedidos e Motoboys.
 *
 * Pedido aberto (sketch 008 A): o `@pedido` da URL (?pedido=<public_id>) abre a folha da comanda no lugar do painel
 * Pedidos, recolhe o Motoboys numa pílula e desenha a rota do motoboy em trechos (feito em cinza, falta em laranja). A
 * comanda vem de GET entregas/pedidos/{id}/comanda e é relida quando o pedido muda no Ao vivo (status, motoboy) e a
 * cada minuto. Pedido encerrado (fora do Ao vivo) também abre, com os dados da comanda.
 */
export default class TelaPedidosMapaComponent extends TelaDePedidosBase {
    @tracked filtro = 'todos';
    @tracked filtroDosMotoboys = 'todos';
    @tracked motoboySelecionado = null;
    @tracked comanda = null;
    @tracked comandaFalhou = false;
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
        clearInterval(this._relerComanda);
    }

    /* ---------- pedido aberto ---------- */

    /** O pedido aberto é o da URL. */
    get selecionado() {
        return this.args.pedido || null;
    }

    /** O pedido aberto no formato do Ao vivo: o da lista, ou o da comanda (pedido encerrado ou fora da lista). */
    get pedidoAbertoBruto() {
        if (!this.selecionado) return null;
        const doAoVivo = this.aoVivo.pedidos.find((p) => p.id === this.selecionado);
        if (doAoVivo) return doAoVivo;
        const daComanda = this.comandaDoPedido?.pedido;
        return daComanda ?? null;
    }

    /** Muda quando o pedido muda no Ao vivo: aí a comanda é relida. */
    get versaoDoPedidoAberto() {
        const p = this.aoVivo.pedidos.find((x) => x.id === this.selecionado);
        return p ? `${p.status}|${p.motoboy_id ?? ''}|${p.aceito ? 1 : 0}` : 'fora';
    }

    get comandaDoPedido() {
        return this.comanda && this.comanda.pedido?.id === this.selecionado ? this.comanda : null;
    }

    get situacaoDoPedidoAberto() {
        const bruto = this.pedidoAbertoBruto;
        return situacaoDaComanda(this.comandaDoPedido, bruto ? situacaoDoPedido(bruto) : null);
    }

    /** A rota do motoboy em trechos (feito em cinza, falta em laranja), com a posição atual dele. */
    get rotaDoPedidoAberto() {
        const bruto = this.pedidoAbertoBruto;
        if (!bruto) return null;
        const comanda = this.comandaDoPedido;
        const motoboy = this.aoVivo.motoboys.find((m) => m.id === bruto.motoboy_id);
        const situacao = this.situacaoDoPedidoAberto;
        const trechos = trechosDaRota({
            situacao,
            encerrado: !!comanda?.encerrado,
            rota: comanda?.rota ?? null,
            coleta: bruto.coleta,
            entrega: Number.isFinite(bruto.latitude) ? { latitude: bruto.latitude, longitude: bruto.longitude } : null,
            motoboy: motoboy && !comanda?.encerrado ? motoboy : null,
        });
        return { id: bruto.id, trechos, situacao, coleta: bruto.coleta ?? null };
    }

    @action aoMudarPedido() {
        clearInterval(this._relerComanda);
        this.atribuindo = null;
        this.comandaFalhou = false;
        if (!this.selecionado) return;
        this.carregarComanda.perform();
        this._relerComanda = setInterval(() => {
            if (typeof document !== 'undefined' && document.hidden) return;
            this.carregarComanda.perform();
        }, RELER_COMANDA_MS);
        if (this.celular && this.folha !== 'alta') this.folha = 'alta';
    }

    @action recarregarComanda() {
        if (this.selecionado) this.carregarComanda.perform();
    }

    @task({ restartable: true }) *carregarComanda() {
        const id = this.selecionado;
        if (!id) return;
        try {
            const comanda = yield this.fetch.get(`entregas/pedidos/${id}/comanda`);
            if (id === this.selecionado) {
                this.comanda = comanda;
                this.comandaFalhou = false;
            }
        } catch {
            if (id === this.selecionado) this.comandaFalhou = true;
        }
    }

    get textoDoVoltar() {
        const de = this.args.de;
        return de ? this.intl.t(`${P}.pedido-aberto.voltar.${de}`) : null;
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
        const pedido = this.pedidoAbertoBruto;
        if (!pedido) return null;
        const linha = this.linhaDoPedido(pedido);
        const motoboy = this.aoVivo.motoboys.find((m) => m.id === pedido.motoboy_id);
        const situacao = this.situacaoDoPedidoAberto;
        const encerrado = situacao === 'concluido' || situacao === 'cancelado';
        return {
            ...linha,
            situacao,
            encerrado,
            ehOferta: !encerrado && linha.ehOferta,
            prazo: encerrado ? null : linha.prazo,
            textoDaSituacao: encerrado ? this.intl.t(`${P}.pedido-aberto.situacao.${situacao}`) : linha.textoDaSituacao,
            motoboyDoPedido: motoboy ? { ...motoboy, capacete: CAPACETES[motoboy.situacao] ?? CAPACETES.offline } : null,
        };
    }

    /* ---------- mapa ---------- */

    get pedidosDoMapa() {
        const bruto = this.pedidoAbertoBruto;
        const fora = bruto && !this.aoVivo.pedidos.some((p) => p.id === bruto.id) && Number.isFinite(bruto.latitude);
        return (fora ? [...this.aoVivo.pedidos, bruto] : this.aoVivo.pedidos).map((pedido) => {
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
        if (this.celular) return { esquerda: 0, direita: 0, baixo: this.selecionado ? Math.round((typeof window !== 'undefined' ? window.innerHeight : 700) * 0.55) : 260 };
        if (this.selecionado) return { esquerda: 0, direita: LARGURA_FOLHA, baixo: 0 };
        return { esquerda: this.painelMotoboys ? LARGURA_MOTOBOYS : 0, direita: this.painelPedidos ? LARGURA_PEDIDOS : 0, baixo: 0 };
    }

    /* ---------- ações ---------- */

    @action escolherFiltro(chave) {
        this.filtro = this.filtro === chave && chave !== 'todos' ? 'todos' : chave;
        if (this.selecionado) this.args.aoAbrirPedido?.(null);
    }

    @action escolherFiltroDosMotoboys(chave) {
        this.filtroDosMotoboys = this.filtroDosMotoboys === chave && chave !== 'todos' ? 'todos' : chave;
    }

    /** Clique no pedido (lista ou alfinete): abre a folha dele; no que já está aberto, fecha. */
    @action escolherPedido(id) {
        this.motoboySelecionado = null;
        if (this.selecionado === id) this.args.aoAbrirPedido?.(null);
        else this.args.aoAbrirPedido?.(id, this.args.de);
    }

    @action escolherMotoboy(id) {
        this.motoboySelecionado = this.motoboySelecionado === id ? null : id;
        if (this.selecionado) this.args.aoAbrirPedido?.(null);
    }

    /** Atribuir na linha: abre o pedido já com os candidatos (a lista fica num lugar só). */
    @action atribuirPedido(id, evento) {
        evento?.stopPropagation?.();
        this.motoboySelecionado = null;
        if (this.selecionado === id) {
            this.atribuindo = id;
            return;
        }
        this.atribuirDepois = id;
        this.args.aoAbrirPedido?.(id, this.args.de);
    }

    /** Depois do aoMudarPedido (que zera o atribuindo), abre os candidatos pedidos pela linha. */
    @action aoMudarPedidoEAtribuir() {
        this.aoMudarPedido();
        if (this.atribuirDepois && this.atribuirDepois === this.selecionado) this.atribuindo = this.atribuirDepois;
        this.atribuirDepois = null;
    }

    @action fecharDetalhe() {
        this.atribuindo = null;
        this.args.aoAbrirPedido?.(null);
    }

    @action voltar() {
        this.atribuindo = null;
        this.args.aoVoltar?.();
    }

    /** Depois de atribuir/trocar, a comanda também muda (motoboy e linha do tempo). */
    @action async enviarERecarregar(pedidoId, motoboyId, evento) {
        await this.enviar(pedidoId, motoboyId, evento);
        this.recarregarComanda();
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
