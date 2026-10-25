import Component from '@glimmer/component';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { CAPACETES } from '../../../utils/entregas-capacete';
import { ICONE_DA_LOJA } from '../../../utils/entregas-icone-da-loja';
import { ALFINETE, rotuloNoAlfinete, classeDoRotulo, emEntrega } from '../../../utils/entregas-pedidos-no-mapa';
import ensureLeafletDrawEditNamespace from '../../../utils/leaflet-draw-namespace-guard';

// Ribeirão Preto: o centro até chegar o primeiro motoboy ou pedido
const CENTRO_PADRAO = { lat: -21.1775, lng: -47.8103 };
const APAGADO = 0.25;
const COR_DA_ROTA = '#ea7a1a';
// tamanhos fixos (mesma referência a cada tique do relógio): um array novo faria o Leaflet trocar o ícone todo segundo
const CAPACETE = [32, 32];
const CAPACETE_GRANDE = [42, 42];
const ALFINETE_GRANDE = { tamanho: ALFINETE.tamanho.map((n) => n * 1.4), ponta: ALFINETE.ponta.map((n) => n * 1.4) };

/**
 * Entregas: o mapa da vista Mapa da tela de pedidos (tela-pedidos/mapa). Capacete na cor da situação com o nome fixo,
 * pin laranja das lojas e alfinete vermelho no destino, com o rótulo "#4821 · Carlos" no pedido aceito (fundo pelo
 * prazo, como o mapa ao vivo antigo). O pedido escolhido fica maior, os outros apagados, e uma linha laranja liga o
 * motoboy à loja e ao cliente. O enquadramento desconta os painéis (@margens: esquerda, direita e baixo, em px).
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

    /** Linha do pedido escolhido: motoboy → loja → cliente (indo à loja), motoboy → cliente (a caminho), loja → cliente (sem motoboy). */
    get rota() {
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
        // o canto de cima à esquerda fica atrás do painel Motoboys
        this.mapa.zoomControl?.setPosition('bottomright');
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

    /** Ao escolher um pedido, mostra o motoboy, a loja e o cliente dele sem ficar atrás dos painéis. */
    @action focar() {
        if (!this.mapa || !this.mapa.getSize().y) return;
        const rota = this.rota;
        const pedido = this.pedidos.find((p) => p.selecionado);
        if (!pedido) return;
        const pontos = rota?.pontos ?? [[pedido.latitude, pedido.longitude]];
        this.mapa.fitBounds(pontos, { ...this.#padding(), maxZoom: 15 });
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
