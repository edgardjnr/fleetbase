/**
 * Entregas RestaurantePro: aviso à central de pedido aberto sem motoboy (serviço pedido-sem-motoboy).
 *
 * O evento vem do reenvio de pedidos abertos (App\Events\Entregas\PedidoSemMotoboy, ~12 min sem aceite), com
 * `data = {id: public_id, uuid, numero, minutos}`. O aviso some quando chega, no mesmo canal, um evento do Fleet-Ops que
 * tira o pedido dessa situação.
 */
export const EVENTO_PEDIDO_SEM_MOTOBOY = 'entregas.pedido_sem_motoboy';

/** Eventos do Fleet-Ops (order.<eventName>) que encerram o aviso: o pedido ganhou motoboy ou saiu do ar. */
const EVENTOS_QUE_RESOLVEM = new Set(['order.driver_assigned', 'order.started', 'order.canceled', 'order.completed', 'order.failed']);

/**
 * @returns {{id: string, uuid: ?string, numero: string, minutos: number} | null}
 */
export function avisoSemMotoboy(mensagem) {
    if (mensagem?.event !== EVENTO_PEDIDO_SEM_MOTOBOY) return null;

    const dados = mensagem.data ?? {};
    if (typeof dados.id !== 'string' || !dados.id) return null;

    return {
        id: dados.id,
        uuid: typeof dados.uuid === 'string' && dados.uuid ? dados.uuid : null,
        numero: dados.numero ? String(dados.numero) : dados.id,
        minutos: Number(dados.minutos) || 0,
    };
}

/**
 * Status que encerram o pedido. É a mesma lista de App\Support\Entregas\StatusDoPedido::ENCERRADOS: manter as duas iguais.
 */
const STATUS_ENCERRADOS = new Set(['completed', 'done', 'canceled', 'cancelled', 'order_canceled', 'expired']);

/**
 * Ids do pedido cujo aviso deve sumir. Resolvem o aviso:
 * - os eventos de EVENTOS_QUE_RESOLVEM;
 * - rede de proteção: um `order.updated` com status encerrado (o pedido que expira, ou termina como done/order_canceled,
 *   não tem evento próprio) ou com `driver_assigned` preenchido (cobre o aceite que chega antes do aviso). O recurso v1
 *   do pedido manda o public_id do motorista, ou null.
 *
 * O payload atual do Fleet-Ops (ResourceLifecycleEvent + Order::toWebhookPayload) traz só `data.id` = public_id;
 * `public_id` e `uuid` ficam por defesa (uma chamada interna do console pode mandar o uuid): devolve todos os que vierem.
 *
 * @returns {string[]}
 */
export function pedidosResolvidos(mensagem) {
    const evento = mensagem?.event;
    const dados = mensagem?.data ?? {};

    const resolve =
        EVENTOS_QUE_RESOLVEM.has(evento) ||
        (evento === 'order.updated' && (STATUS_ENCERRADOS.has(dados.status) || Boolean(dados.driver_assigned)));
    if (!resolve) return [];

    return [...new Set([dados.id, dados.public_id, dados.uuid].filter((id) => typeof id === 'string' && id))];
}
