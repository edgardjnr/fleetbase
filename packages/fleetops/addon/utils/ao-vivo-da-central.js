import { tracked } from '@glimmer/tracking';
import escutarCanalDaEmpresa from './escutar-canal-da-empresa';

const INTERVALO_MS = 20000;
const ESPERA_DO_SOCKET_MS = 800;
const EVENTOS_AO_VIVO = /^(order\.|waypoint\.|entity\.|driver\.(created|updated|deleted)$|entregas\.motoboy_online$)/;

/**
 * Entregas: os pedidos em andamento e os motoboys da central, ao vivo, para a tela de pedidos (/fleet-ops: Mapa e
 * Quadro). Lê GET entregas/painel-inicial/ao-vivo ao criar, a cada 20 s e 0,8 s depois dos eventos do socket (o mesmo
 * ritmo do painel inicial); com a aba escondida, não lê. O relógio de 1 s (agora) só anda o cronômetro e a oferta.
 * Quem cria chama parar() no willDestroy.
 */
export default class AoVivoDaCentral {
    @tracked pedidos = [];
    @tracked motoboys = [];
    @tracked concluidos = { total: 0, ultimos: [] };
    @tracked carregado = false;
    @tracked agora = Date.now();
    @tracked enviando = false;

    constructor({ fetch, socket, currentUser }) {
        this.fetch = fetch;
        this.carregar();
        this._intervalo = setInterval(() => this.#seVisivel(() => this.carregar()), INTERVALO_MS);
        this._tique = setInterval(() => this.#seVisivel(() => (this.agora = Date.now())), 1000);
        this._aoVoltar = () => {
            if (!document.hidden) this.carregar();
        };
        document.addEventListener('visibilitychange', this._aoVoltar);
        this._escuta = escutarCanalDaEmpresa({
            socket,
            currentUser,
            aoReceber: (mensagem) => {
                if (EVENTOS_AO_VIVO.test(String(mensagem?.event ?? ''))) this.#agendar();
            },
        });
    }

    parar() {
        clearInterval(this._intervalo);
        clearInterval(this._tique);
        clearTimeout(this._espera);
        document.removeEventListener('visibilitychange', this._aoVoltar);
        this._escuta?.parar();
        this._parado = true;
    }

    #seVisivel(fazer) {
        if (typeof document === 'undefined' || !document.hidden) fazer();
    }

    #agendar() {
        clearTimeout(this._espera);
        this._espera = setTimeout(() => this.#seVisivel(() => this.carregar()), ESPERA_DO_SOCKET_MS);
    }

    async carregar() {
        try {
            const resposta = await this.fetch.get('entregas/painel-inicial/ao-vivo');
            if (this._parado) return;
            this.pedidos = resposta?.pedidos ?? [];
            this.motoboys = resposta?.motoboys ?? [];
            this.concluidos = resposta?.concluidos ?? { total: 0, ultimos: [] };
            this.carregado = true;
        } catch {
            // silencioso: o próximo ciclo (20 s) ou o próximo evento do socket tenta de novo
        }
    }

    /** Atribui (ou troca) o motoboy do pedido pelo TrocaDoMotoboy (só admin; a API devolve 403 para os outros). Devolve a resposta (com `oferta` quando o escolhido precisa aceitar). */
    async atribuir(pedidoId, motoboyId) {
        this.enviando = true;
        try {
            const resposta = await this.fetch.post(`entregas/painel-inicial/pedidos/${pedidoId}/motoboy`, { motoboy: motoboyId });
            await this.carregar();

            return resposta;
        } finally {
            this.enviando = false;
        }
    }
}
