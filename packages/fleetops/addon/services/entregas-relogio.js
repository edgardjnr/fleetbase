import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { INTERVALO_DO_CRONOMETRO_MS } from '../utils/entregas-cronometro';

/**
 * Entregas: o relógio do cronômetro do prazo de entrega (coluna "Prazo" da tabela de pedidos e cabeçalho do detalhe).
 * Um só para todas as telas: `agora` anda a cada 30 s e na volta para a aba. Quem lê `agora` num getter se atualiza
 * sozinho (tracked). O relógio é o do aparelho. Com a aba oculta o intervalo para (não há o que mostrar); na volta,
 * tique na hora e intervalo novo.
 */
export default class EntregasRelogioService extends Service {
    @tracked agora = Date.now();

    constructor() {
        super(...arguments);
        this._aoMudarAba = () => {
            if (document.hidden) {
                this.#parar();
            } else {
                this.#tique();
                this.#ligar();
            }
        };
        if (typeof document !== 'undefined') {
            document.addEventListener('visibilitychange', this._aoMudarAba);
            if (document.hidden) return;
        }
        this.#ligar();
    }

    #ligar() {
        if (this.isDestroying || this.isDestroyed || this._intervalo) return;
        this._intervalo = setInterval(() => this.#tique(), INTERVALO_DO_CRONOMETRO_MS);
    }

    #parar() {
        clearInterval(this._intervalo);
        this._intervalo = null;
    }

    #tique() {
        if (this.isDestroying || this.isDestroyed) return;
        this.agora = Date.now();
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this.#parar();
        if (typeof document !== 'undefined') {
            document.removeEventListener('visibilitychange', this._aoMudarAba);
        }
    }
}
