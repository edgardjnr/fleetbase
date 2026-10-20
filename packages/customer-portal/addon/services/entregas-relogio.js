import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { INTERVALO_DO_CRONOMETRO_MS } from '../utils/entregas-cronometro';

/**
 * Entregas: o relógio do cronômetro do prazo de entrega no card do pedido da tela Pedidos. Um só para todos os cards:
 * `agora` anda a cada 30 s e na volta para a aba. Quem lê `agora` num getter se atualiza sozinho (tracked). O relógio é
 * o do aparelho. Cópia do serviço do console (um engine não importa do outro).
 */
export default class EntregasRelogioService extends Service {
    @tracked agora = Date.now();

    constructor() {
        super(...arguments);
        this._intervalo = setInterval(() => this.#tique(), INTERVALO_DO_CRONOMETRO_MS);
        this._aoVoltarParaAba = () => {
            if (!document.hidden) this.#tique();
        };
        if (typeof document !== 'undefined') {
            document.addEventListener('visibilitychange', this._aoVoltarParaAba);
        }
    }

    #tique() {
        if (this.isDestroying || this.isDestroyed) return;
        this.agora = Date.now();
    }

    willDestroy() {
        super.willDestroy(...arguments);
        clearInterval(this._intervalo);
        if (typeof document !== 'undefined') {
            document.removeEventListener('visibilitychange', this._aoVoltarParaAba);
        }
    }
}
