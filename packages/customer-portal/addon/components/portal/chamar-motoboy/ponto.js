import Component from '@glimmer/component';
import { action } from '@ember/object';
import { pontoValido } from '../../../utils/chamar-motoboy';

/**
 * Entregas: o mapa pequeno do passo 2 do "Chamar motoboy", sempre à vista, com o alfinete fixo no centro (o mecanismo do
 * CoordinatesInput do ember-ui com @mapCenter). Abre no ponto escolhido ou, sem ele, na loja (@centro). Só o gesto da
 * loja move o ponto (o dragend do Leaflet): o moveend dispara também no carregamento e poria o ponto na loja. Roda e pinça
 * dão zoom em torno do centro, então o zoom não mexe no ponto.
 * O LeafletMap do ember-leaflet 5 não repassa o dragend (não está nos leafletEvents do mapa), então o ouvinte entra pelo
 * @onLoad, direto no mapa do Leaflet.
 * Argumentos: @latitude, @longitude (o ponto ou null), @centro ({latitude, longitude} da loja), @onMudou({latitude, longitude}).
 */
export default class PortalChamarMotoboyPontoComponent extends Component {
    mapa = null;

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
        this.mapa?.off?.('dragend', this.arrastou);
        this.mapa = null;
    }

    @action carregou(evento) {
        const mapa = evento?.target ?? evento;
        if (typeof mapa?.on !== 'function') return;
        this.mapa?.off?.('dragend', this.arrastou);
        this.mapa = mapa;
        mapa.on('dragend', this.arrastou);
    }

    @action arrastou(evento) {
        const centro = (evento?.target ?? this.mapa)?.getCenter?.();
        if (centro && typeof this.args.onMudou === 'function') {
            this.args.onMudou({ latitude: centro.lat, longitude: centro.lng });
        }
    }
}
