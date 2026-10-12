// Entregas: de onde veio o pedido, na tela do console. Serve ao selo "iFood #4821" ou "RestaurantePro #4821" (tabela,
// quadro, mapa e cabeçalho), ao painel "Integração" do detalhe e ao rótulo do cancelado pago no Pagamento e cobrança.
// O CriadorDoPedidoIfood grava as notas "iFood #N" e o CriadorDoPedidoDaIntegracao grava "<Sistema> #N"; nos dois, o
// internal_id = N. Exigir os dois juntos evita confundir um pedido comum cujas notas a central escreveu assim. É só a
// tela: quem decide de verdade é o servidor (RegrasDoPedidoIfood, entregas_integracao_pedidos).
// A regra do iFood é a mesma do numeroIfood (utils/pedido-ifood.js), que continua decidindo o Cancelar escondido.
// Mesma regra no portal (customer-portal/addon/utils/entregas-pedido.js, origemDoPedido) e no app
// (src/utils/cartao-do-pedido.ts, origemDasNotas). Sem imports: testado no Node (scripts/teste-portal/pedido-de-origem.test.mjs).

// O nome do sistema passa pelo Integracao::nomeDoSistema do servidor: letras, números, espaço, ponto, hífen e
// sublinhado, até 40 caracteres.
const NOTAS_DE_ORIGEM = /^([\p{L}\p{N} ._-]{1,40}?) #(\S+)/u;

/** Avisos de volta ao sistema de origem, na ordem (App\Support\Entregas\Integracao\SequenciaDaIntegracao). */
export const AVISOS_DA_INTEGRACAO = ['aceito', 'a_caminho', 'concluido', 'cancelado'];

/** Quem cancelou (entregas_integracao_pedidos.cancelado_por). */
export const CANCELADO_POR = ['loja', 'central', 'sistema'];

/**
 * {ifood, sistema, numero} do pedido, ou null. O iFood volta com ifood = true. Um sistema que começa com "ifood" e não
 * é o próprio iFood volta null: o servidor nunca grava esse nome (vira "Integração").
 */
export function origemDoPedido(notas, idInterno) {
    if (typeof notas !== 'string' || idInterno === null || idInterno === undefined || idInterno === '') {
        return null;
    }
    const achado = NOTAS_DE_ORIGEM.exec(notas.trim());
    if (!achado || achado[2] !== String(idInterno)) {
        return null;
    }
    const sistema = achado[1].trim();
    if (sistema === 'iFood') {
        return { ifood: true, sistema, numero: achado[2] };
    }
    if (sistema === '' || /^ifood/i.test(sistema)) {
        return null;
    }

    return { ifood: false, sistema, numero: achado[2] };
}

/** A origem de um pedido (model do Ember ou objeto com notes e internal_id), ou null. */
export function origemDoModelo(pedido) {
    return pedido ? origemDoPedido(pedido.notes, pedido.internal_id) : null;
}

/** Pedido que veio de outro sistema pela integração (não do iFood). */
export function ehPedidoDaIntegracao(pedido) {
    const origem = origemDoModelo(pedido);
    return origem !== null && origem.ifood === false;
}

/** Chave fleet-ops.ui.integracao.aviso.<chave> do último aviso entregue ao sistema. */
export function chaveDoAviso(aviso) {
    return AVISOS_DA_INTEGRACAO.includes(aviso) ? aviso : 'desconhecido';
}

/** Chave fleet-ops.ui.integracao.cancelado-por.<chave>. */
export function chaveDoCanceladoPor(por) {
    return CANCELADO_POR.includes(por) ? por : 'desconhecido';
}

/**
 * Chave fleet-ops.ui.driver-payouts.<chave> do cancelado pago, pela origem do cancelamento (cancelado_origem do
 * CalculoEntregas). 'integracao' = a loja cancelou pelo sistema dela. 'ifood' ou ausente (API antiga) = o rótulo do iFood.
 */
export function chaveDoCanceladoPago(origem) {
    return origem === 'integracao' ? 'cancelado-pago-loja' : 'cancelado-pago';
}
