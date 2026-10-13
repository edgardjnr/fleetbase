/**
 * Entregas RestaurantePro: regras do pedido criado pela central (Fleet-Ops → Pedidos → Novo).
 *
 * - O pedido novo já abre como ad-hoc (oferta aos motoboys) e com despacho ligado.
 * - Só cria com uma loja como cliente e com o ID interno preenchido: sem a loja, o pedido não entra no portal nem
 *   na cobrança dela (Financeiro), e o ID interno é o número que a loja e o motoboy usam para achar o pedido.
 *
 * Sem imports do Ember: testado no Node (scripts/teste-portal/entregas-pedido-novo.test.mjs).
 */
import ehLoja from './entregas-loja';

/** Raio padrão do ad-hoc, em metros, quando a empresa não configurou (o mesmo do toggle do formulário). */
export const RAIO_ADHOC_PADRAO = 5000;

/**
 * Atributos iniciais do pedido novo.
 *
 * @param {Number} [raio] o `fleetops.adhoc_distance` da empresa
 * @return {{adhoc: Boolean, adhoc_distance: Number, dispatched: Boolean}}
 */
export function padroesDoPedidoNovo(raio) {
    const distancia = Number(raio);

    return {
        adhoc: true,
        adhoc_distance: Number.isFinite(distancia) && distancia > 0 ? distancia : RAIO_ADHOC_PADRAO,
        dispatched: true,
    };
}

/**
 * O que falta para criar o pedido: 'loja' (o cliente não é uma loja) e/ou 'id-interno' (vazio ou só espaços).
 *
 * @param {Object} pedido o model order (ou um objeto com customer, customer_type e internal_id)
 * @return {String[]}
 */
export function pendenciasDoPedidoNovo(pedido) {
    const pendencias = [];

    if (!pedido?.customer || !ehLoja({ customer: pedido.customer, customerType: pedido.customer_type })) {
        pendencias.push('loja');
    }

    if (String(pedido?.internal_id ?? '').trim() === '') {
        pendencias.push('id-interno');
    }

    return pendencias;
}
