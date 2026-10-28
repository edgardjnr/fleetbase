import Component from '@glimmer/component';
import { action } from '@ember/object';
import { pontoValido } from '../../../utils/chamar-motoboy';

/**
 * Entregas: o mapa pequeno do passo 2 do "Chamar motoboy", sempre à vista, com o alfinete fixo no centro (o mecanismo do
 * CoordinatesInput do ember-ui com @mapCenter). Abre no ponto escolhido ou, sem ele, na loja (@centro). Só o gesto da
 * loja move o ponto: grava no moveend que vem depois de um dragstart (o moveend sozinho dispara também no carregamento e
 * poria o ponto na loja; o dragend vem antes da inércia do arrasto, que ainda leva o mapa). Roda e pinça dão zoom em torno
 * do centro, então o zoom não mexe no ponto; teclado e Shift+arrastar ficam desligados (moviam o centro sem gravar).
 * O LeafletMap do ember-leaflet 5 não repassa o dragstart (não está nos leafletEvents do mapa), então os ouvintes entram
 * pelo @onLoad, direto no mapa do Leaflet.
 * Argumentos: @latitude, @longitude (o ponto ou null), @centro ({latitude, longitude} da loja), @onMudou({latitude, longitude}).
 */
export default class PortalChamarMotoboyPontoComponent extends Component {
    mapa = null;
    arrastando = false;

    get temPonto() {
        return pontoValido(this.args.latitude, this.args.longitude);
    }

    get latitude() {
        return this.temPonto ? this.args.latitude : this.args.centro?.latitude ?? null;
    }

    get longitude() {
        return this.temPonto ? this.args.longitude : this.args.centro?.longitude ?? null;
    }

    get mostrarMapa() {
        return this.latitude !== null && this.longitude !== null;
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this.desligar();
        this.mapa = null;
    }

    @action carregou(evento) {
        const mapa = evento?.target ?? evento;
        if (typeof mapa?.on !== 'function') return;
        this.desligar();
        this.mapa = mapa;
        mapa.on('dragstart', this.comecouArrastar);
        mapa.on('moveend', this.parou);
    }

    desligar() {
        this.mapa?.off?.('dragstart', this.comecouArrastar);
        this.mapa?.off?.('moveend', this.parou);
        this.arrastando = false;
    }

    @action comecouArrastar() {
        this.arrastando = true;
    }

    @action parou(evento) {
        if (!this.arrastando) return;
        this.arrastando = false;
        const centro = (evento?.target ?? this.mapa)?.getCenter?.();
        if (centro && typeof this.args.onMudou === 'function') {
            this.args.onMudou({ latitude: centro.lat, longitude: centro.lng });
        }
    }
}
