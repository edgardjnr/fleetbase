import Component from '@glimmer/component';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { CAPACETES } from '../../../utils/entregas-capacete';
import { ICONE_DA_LOJA } from '../../../utils/entregas-icone-da-loja';
import { ALFINETE } from '../../../utils/entregas-pedidos-no-mapa';
import ensureLeafletDrawEditNamespace from '../../../utils/leaflet-draw-namespace-guard';

// Ribeirão Preto: o centro até chegar o primeiro motoboy ou pedido
const CENTRO_PADRAO = { lat: -21.1775, lng: -47.8103 };

/**
 * Entregas: mapa do Ao vivo do painel inicial (home/painel-inicial). Capacete na cor da situação com o nome fixo, pin
 * laranja das lojas e alfinete vermelho no destino (clique abre o pedido). Enquadra uma vez, quando chegam os primeiros
 * pontos; depois quem mexe é a pessoa. Sem zoom na rolagem (a página rola por cima do mapa).
 */
export default class HomePainelInicialMapaComponent extends Component {
    @service mapSettings;

    centro = CENTRO_PADRAO;
    loja = ICONE_DA_LOJA;
    alfinete = ALFINETE;
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
            .map((motoboy) => ({ ...motoboy, capacete: CAPACETES[motoboy.situacao] ?? CAPACETES.offline }));
    }

    get pedidos() {
        return (this.args.pedidos ?? []).filter((pedido) => Number.isFinite(pedido?.latitude) && Number.isFinite(pedido?.longitude));
    }

    @action aoCarregar(evento) {
        this.mapa = evento?.target ?? null;
        if (!this.mapa) return;
        // ao ganhar tamanho (o mapa pode nascer com altura 0), redesenha e enquadra se ainda não enquadrou
        this.observador = new ResizeObserver(() => {
            this.mapa?.invalidateSize();
            this.enquadrar();
        });
        this.observador.observe(this.mapa.getContainer());
        this.enquadrar();
    }

    @action enquadrar() {
        if (!this.mapa || this.enquadrado || !this.mapa.getSize().y) return;
        const pontos = [...this.motoboys.map((m) => [m.latitude, m.longitude]), ...this.pedidos.map((p) => [p.latitude, p.longitude])];
        if (!pontos.length) return;
        this.mapa.fitBounds(pontos, { padding: [30, 30], maxZoom: 14 });
        this.enquadrado = true;
    }

    @action abrir(id) {
        this.args.aoAbrirPedido?.(id);
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this.observador?.disconnect();
        this.mapa = null;
    }
}
