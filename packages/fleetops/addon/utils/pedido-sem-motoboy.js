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
 * Ids (public_id e/ou uuid) do pedido cujo aviso deve sumir. O data.id é o public_id, mas numa chamada interna do
 * console pode vir o uuid: devolve todos os que vierem.
 *
 * @returns {string[]}
 */
export function pedidosResolvidos(mensagem) {
    if (!EVENTOS_QUE_RESOLVEM.has(mensagem?.event)) return [];

    const dados = mensagem.data ?? {};

    return [...new Set([dados.id, dados.public_id, dados.uuid].filter((id) => typeof id === 'string' && id))];
}
