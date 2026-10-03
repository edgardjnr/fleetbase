/**
 * Entregas RestaurantePro: regra única de "o cliente do pedido é uma loja" (fornecedor) no
 * formulário do pedido. Vale `customer.isVendor` ou "vendor" no tipo do cliente ou no do pedido,
 * sem diferenciar maiúsculas: o cliente escolhido vem com "vendor" e o pedido grava "fleet-ops:vendor".
 *
 * @param {Object} args
 * @param {Object} [args.customer] o cliente (model customer)
 * @param {String} [args.customerType] o customer_type do pedido
 * @return {Boolean}
 */
export default function ehLoja({ customer, customerType } = {}) {
    if (customer?.isVendor) {
        return true;
    }

    return [customer?.customer_type, customerType].some((tipo) => typeof tipo === 'string' && /vendor/i.test(tipo));
}
