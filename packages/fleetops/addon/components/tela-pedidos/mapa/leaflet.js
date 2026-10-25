import Component from '@glimmer/component';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { CAPACETES } from '../../../utils/entregas-capacete';
import { ICONE_DA_LOJA } from '../../../utils/entregas-icone-da-loja';
import { ALFINETE, rotuloNoAlfinete, classeDoRotulo, emEntrega } from '../../../utils/entregas-pedidos-no-mapa';
import ensureLeafletDrawEditNamespace from '../../../utils/leaflet-draw-namespace-guard';
import { pontosParaEnquadrar } from '../../../utils/pedido-aberto';

// Ribeirão Preto: o centro até chegar o primeiro motoboy ou pedido
const CENTRO_PADRAO = { lat: -21.1775, lng: -47.8103 };
const APAGADO = 0.25;
const COR_DA_ROTA = '#ea7a1a';
const CONTORNO = '#ffffff';
// o que falta é mais grosso que o que já foi feito
const LARGURAS = { feito: 4, feita: 4, prevista: 4, 'ate-loja': 4 };
// tamanhos fixos (mesma referência a cada tique do relógio): um array novo faria o Leaflet trocar o ícone todo segundo
const CAPACETE = [32, 32];
const CAPACETE_GRANDE = [42, 42];
const ALFINETE_GRANDE = { tamanho: ALFINETE.tamanho.map((n) => n * 1.4), ponta: ALFINETE.ponta.map((n) => n * 1.4) };

/**
 * Entregas: o mapa da vista Mapa da tela de pedidos (tela-pedidos/mapa). Capacete na cor da situação com o nome fixo,
 * pin laranja das lojas e alfinete vermelho no destino, com o rótulo "#4821 · Carlos" no pedido aceito (fundo pelo
 * prazo, como o mapa ao vivo antigo). O pedido escolhido fica maior, os outros apagados, e o @rotaDoPedido desenha a
 * rota do motoboy em trechos (utils/pedido-aberto: feito em cinza, falta em laranja, até a loja tracejado), com um
 * contorno branco por baixo e o P na coleta. Sem @rotaDoPedido, a linha reta motoboy → loja → cliente de antes. O
 * enquadramento desconta os painéis e a folha (@margens: esquerda, direita e baixo, em px).
 */
export default class TelaPedidosMapaLeafletComponent extends Component {
    @service mapSettings;

    centro = CENTRO_PADRAO;
    loja = ICONE_DA_LOJA;
    mapa = null;
    enquadrado = false;

    constructor() {
        super(...arguments);
        ensureLeafletDrawEditNamespace();
    }

    get tileUrl() {
        const escuro = typeof document !== 'undefined' && document.body?.dataset?.theme === 'dark';
        return this.mapSettings.getLeafletTileUrl(escuro ? 'dark' : 'light');
    }

    get motoboys() {
        return (this.args.motoboys ?? [])
            .filter((motoboy) => Number.isFinite(motoboy?.latitude) && Number.isFinite(motoboy?.longitude))
            .map((motoboy) => ({
                ...motoboy,
                capacete: CAPACETES[motoboy.situacao] ?? CAPACETES.offline,
                tamanho: motoboy.selecionado ? CAPACETE_GRANDE : CAPACETE,
                opacidade: motoboy.apagado ? APAGADO : 1,
            }));
    }

    get pedidos() {
        const agora = this.args.agora ?? Date.now();
        return (this.args.pedidos ?? [])
            .filter((pedido) => Number.isFinite(pedido?.latitude) && Number.isFinite(pedido?.longitude))
            .map((pedido) => ({
                ...pedido,
                rotulo: rotuloNoAlfinete(pedido),
                classe: classeDoRotulo(pedido, agora),
                emEntrega: emEntrega(pedido),
                tamanho: pedido.selecionado ? ALFINETE_GRANDE.tamanho : ALFINETE.tamanho,
                ponta: pedido.selecionado ? ALFINETE_GRANDE.ponta : ALFINETE.ponta,
                opacidade: pedido.apagado ? APAGADO : 1,
            }));
    }

    get alfinete() {
        return ALFINETE;
    }

    /** Trechos da rota do pedido aberto (do componente do mapa), cada um com o contorno branco por baixo. */
    get trechos() {
        return (this.args.rotaDoPedido?.trechos ?? []).map((trecho) => ({
            ...trecho,
            tracejado: trecho.tracejado ? '10 8' : null,
            largura: LARGURAS[trecho.tipo] ?? 5,
            contorno: (LARGURAS[trecho.tipo] ?? 5) + 4,
        }));
    }

    get contorno() {
        return CONTORNO;
    }

    /** O P da coleta do pedido aberto. */
    get coletaDoPedido() {
        const coleta = this.args.rotaDoPedido?.coleta;
        return coleta && Number.isFinite(coleta.latitude) && Number.isFinite(coleta.longitude) ? coleta : null;
    }

    /** Linha reta do pedido escolhido, sem @rotaDoPedido: motoboy → loja → cliente (indo à loja), motoboy → cliente (a caminho), loja → cliente (sem motoboy). */
    get rota() {
        if (this.args.rotaDoPedido) return null;
        const pedido = this.pedidos.find((p) => p.selecionado);
        if (!pedido) return null;
        const motoboy = this.motoboys.find((m) => m.id === pedido.motoboy_id);
        const ponto = (p) => (p && Number.isFinite(p.latitude) && Number.isFinite(p.longitude) ? [p.latitude, p.longitude] : null);
        const destino = ponto(pedido);
        const coleta = ponto(pedido.coleta);
        const daMoto = ponto(motoboy);
        let pontos;
        if (pedido.situacao === 'rota') pontos = [daMoto, destino];
        else if (pedido.situacao === 'loja') pontos = [daMoto, coleta, destino];
        else pontos = [coleta, destino];
        pontos = pontos.filter(Boolean);
        return pontos.length >= 2 ? { pontos, cor: COR_DA_ROTA } : null;
    }

    #padding() {
        const m = this.args.margens ?? {};
        return { paddingTopLeft: [(m.esquerda ?? 0) + 30, 80], paddingBottomRight: [(m.direita ?? 0) + 30, (m.baixo ?? 0) + 30] };
    }

    @action aoCarregar(evento) {
        this.mapa = evento?.target ?? null;
        if (!this.mapa) return;
        // em cima à esquerda fica o painel Motoboys e à direita o Pedidos: o zoom vai embaixo à esquerda
        this.mapa.zoomControl?.setPosition('bottomleft');
        this.observador = new ResizeObserver(() => {
            this.mapa?.invalidateSize();
            this.enquadrar();
        });
        this.observador.observe(this.mapa.getContainer());
        this.enquadrar();
    }

    /** Enquadra todos uma vez, quando chegam os primeiros pontos; depois quem mexe é a pessoa. */
    @action enquadrar() {
        if (!this.mapa || this.enquadrado || !this.mapa.getSize().y) return;
        const pontos = [...this.motoboys.map((m) => [m.latitude, m.longitude]), ...this.pedidos.map((p) => [p.latitude, p.longitude])];
        if (!pontos.length) return;
        this.mapa.fitBounds(pontos, { ...this.#padding(), maxZoom: 14 });
        this.enquadrado = true;
    }

    /**
     * Ao abrir um pedido, mostra a rota inteira (motoboy, loja e cliente) sem ficar atrás dos painéis nem da folha.
     * Enquadra uma vez por pedido (ao chegar a rota do servidor, de novo); depois quem mexe é a pessoa.
     */
    @action focar() {
        if (!this.mapa || !this.mapa.getSize().y) return;
        const pedido = this.pedidos.find((p) => p.selecionado);
        if (!pedido) {
            this.focado = null;
            return;
        }
        const daRota = this.args.rotaDoPedido;
        const pontosDoPrimeiro = daRota?.trechos?.[0]?.pontos?.length ?? 0;
        const chave = `${pedido.id}|${daRota?.trechos?.length ?? 0}|${pontosDoPrimeiro > 2 ? 'ruas' : 'reta'}`;
        if (this.focado === chave) return;
        this.focado = chave;
        const pontos = daRota ? pontosParaEnquadrar(daRota.trechos, [pedido, daRota.coleta]) : (this.rota?.pontos ?? [[pedido.latitude, pedido.longitude]]);
        if (!pontos.length) return;
        this.mapa.fitBounds(pontos, { ...this.#padding(), maxZoom: 16 });
    }

    @action escolherPedido(id) {
        this.args.aoEscolherPedido?.(id);
    }

    @action escolherMotoboy(id) {
        this.args.aoEscolherMotoboy?.(id);
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this.observador?.disconnect();
        this.mapa = null;
    }
}
