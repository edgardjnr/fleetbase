import Service, { inject as service } from '@ember/service';
import { debug } from '@ember/debug';
import escutarCanalDaEmpresa from '../utils/escutar-canal-da-empresa';
import { avisoSemMotoboy, pedidosResolvidos } from '../utils/pedido-sem-motoboy';
import { prepararSomDeAlerta, tocarSomDeAlerta } from '../utils/som-de-alerta';

const ROTA_DO_PEDIDO = 'console.fleet-ops.operations.orders.index.details';

/**
 * Entregas RestaurantePro: avisa a central quando um pedido aberto passa ~12 min sem motoboy (evento
 * entregas.pedido_sem_motoboy do ReenviarPedidosAbertos). Toca um som e mostra uma notificação fixa; o clique abre o
 * pedido. Ela some quando o pedido ganha motoboy, é iniciado, cancelado, concluído, falha ou é atualizado como encerrado
 * (utils/pedido-sem-motoboy).
 *
 * Iniciado na rota raiz do Fleet-Ops (routes/application.js) e ativo daí em diante, em qualquer tela do console.
 * Evento perdido (console fechado na hora) não volta: o aviso é só para quem está com o console aberto.
 */
export default class PedidoSemMotoboyService extends Service {
    @service socket;
    @service currentUser;
    @service notifications;
    @service intl;
    @service hostRouter;

    /** public_id e uuid do pedido → { publicId, uuid, notificacao } */
    avisos = new Map();
    canal = null;
    _liberarSom = null;

    iniciar() {
        if (this.canal) return;

        this.canal = escutarCanalDaEmpresa({
            socket: this.socket,
            currentUser: this.currentUser,
            aoReceber: (mensagem) => this.#receber(mensagem),
            aoFalhar: (err) => debug('Pedido sem motoboy: ' + err?.message),
        });

        if (typeof document !== 'undefined') {
            this._liberarSom = () => prepararSomDeAlerta();
            document.addEventListener('pointerdown', this._liberarSom, true);
            document.addEventListener('keydown', this._liberarSom, true);
        }
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this.canal?.parar();
        this.canal = null;

        if (this._liberarSom && typeof document !== 'undefined') {
            document.removeEventListener('pointerdown', this._liberarSom, true);
            document.removeEventListener('keydown', this._liberarSom, true);
        }
    }

    #receber(mensagem) {
        const aviso = avisoSemMotoboy(mensagem);
        if (aviso) {
            this.#mostrar(aviso);
        }

        pedidosResolvidos(mensagem).forEach((id) => this.#fechar(id));
    }

    #mostrar({ id, uuid, numero, minutos }) {
        // limpa os avisos já dispensados no "x", para o mapa não acumular
        for (const entrada of new Set(this.avisos.values())) {
            if (entrada.notificacao?.dismiss) {
                this.avisos.delete(entrada.publicId);
                if (entrada.uuid) {
                    this.avisos.delete(entrada.uuid);
                }
            }
        }

        const atual = this.avisos.get(id);
        if (atual) return;

        tocarSomDeAlerta();

        const entrada = { publicId: id, uuid, notificacao: null };
        entrada.notificacao = this.notifications.warning(this.intl.t('fleet-ops.ui.pedido-sem-motoboy.aviso', { numero, minutos }), {
            autoClear: false,
            onClick: () => {
                this.hostRouter.transitionTo(ROTA_DO_PEDIDO, id);
                this.#fechar(id);
            },
        });

        this.avisos.set(id, entrada);
        if (uuid) {
            this.avisos.set(uuid, entrada);
        }
    }

    #fechar(id) {
        const entrada = this.avisos.get(id);
        if (!entrada) return;

        this.avisos.delete(entrada.publicId);
        if (entrada.uuid) {
            this.avisos.delete(entrada.uuid);
        }
        this.notifications.removeNotification(entrada.notificacao);
    }
}
