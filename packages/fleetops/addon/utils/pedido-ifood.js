// Entregas: pedido do iFood no console (selo "iFood #4821", painel iFood do detalhe, cancelamento escondido e o aviso
// de ação recusada pelo iFood). Sem imports: testado no Node (scripts/teste-portal/pedido-ifood.test.mjs).

/** Evento no canal da empresa quando o iFood recusa uma ação de logística (App\Events\Entregas\IfoodAcaoRecusada). */
export const EVENTO_ACAO_RECUSADA = 'entregas.ifood_acao_recusada';

/** Ações de logística, na ordem do iFood (App\Support\Entregas\Ifood\SequenciaIfood::ACOES). */
export const ACOES_IFOOD = ['assignDriver', 'goingToOrigin', 'arrivedAtOrigin', 'dispatch', 'arrivedAtDestination'];

/** Formas de pagamento do iFood → chave de tradução (fleet-ops.ui.ifood.forma.<chave>). */
export const FORMAS = {
    CASH: 'dinheiro',
    CREDIT: 'credito',
    DEBIT: 'debito',
    MEAL_VOUCHER: 'vale-refeicao',
    FOOD_VOUCHER: 'vale-alimentacao',
    PIX: 'pix',
    DIGITAL_WALLET: 'carteira-digital',
    GIFT_CARD: 'vale-presente',
    OTHER: 'outra-forma',
    MISTO: 'misto',
};

/**
 * Número do iFood do pedido, ou null. O CriadorDoPedidoIfood grava as notas "iFood #4821" (às vezes com marcas depois:
 * " [TESTE]") e o internal_id = 4821: os dois juntos evitam confundir um pedido comum cujas notas a central escreveu
 * assim. O servidor confere de verdade (RegrasDoPedidoIfood); aqui é só a tela.
 */
export function numeroIfood(notas, idInterno) {
    if (typeof notas !== 'string' || idInterno === null || idInterno === undefined || idInterno === '') {
        return null;
    }
    const achado = /^iFood #(\S+)/.exec(notas.trim());

    return achado && achado[1] === String(idInterno) ? achado[1] : null;
}

/** O número do iFood de um pedido (model do Ember ou objeto com notes e internal_id), ou null. */
export function numeroIfoodDoPedido(pedido) {
    return pedido ? numeroIfood(pedido.notes, pedido.internal_id) : null;
}

export function ehPedidoIfood(pedido) {
    return numeroIfoodDoPedido(pedido) !== null;
}

/** Algum pedido do iFood na lista (cancelamento em lote). */
export function algumPedidoIfood(pedidos) {
    return Array.from(pedidos ?? []).some((pedido) => ehPedidoIfood(pedido));
}

/** {id, uuid, numero, acao, status} do evento de ação recusada, ou null para outra mensagem. */
export function avisoAcaoRecusada(mensagem) {
    if (mensagem?.event !== EVENTO_ACAO_RECUSADA) {
        return null;
    }
    const dados = mensagem.data ?? {};
    if (typeof dados.id !== 'string' || dados.id === '') {
        return null;
    }
    const status = Number(dados.status);

    return {
        id: dados.id,
        uuid: typeof dados.uuid === 'string' && dados.uuid !== '' ? dados.uuid : null,
        numero: String(dados.numero ?? dados.id),
        acao: ACOES_IFOOD.includes(dados.acao) ? dados.acao : 'desconhecida',
        status: Number.isFinite(status) ? status : 0,
    };
}

/** "R$ 58,90"; com semCentavosSeInteiro, "R$ 100" em vez de "R$ 100,00" (como o CobrancaIfood do servidor). */
export function reais(centavos, semCentavosSeInteiro = false) {
    const valor = Math.round(Number(centavos) || 0);
    const inteiro = Math.floor(Math.abs(valor) / 100);
    const resto = Math.abs(valor) % 100;
    const milhar = String(inteiro).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    const sinal = valor < 0 ? '-' : '';

    return semCentavosSeInteiro && resto === 0 ? `R$ ${sinal}${milhar}` : `R$ ${sinal}${milhar},${String(resto).padStart(2, '0')}`;
}

/** As partes da forma de pagamento: [{chave}] para as conhecidas e [{texto}] para as outras ("CASH+CREDIT" → duas). */
export function partesDaForma(forma) {
    if (typeof forma !== 'string' || forma.trim() === '') {
        return [];
    }

    return forma
        .toUpperCase()
        .split('+')
        .map((parte) => parte.trim())
        .filter(Boolean)
        .map((parte) => (FORMAS[parte] ? { chave: FORMAS[parte] } : { texto: parte.toLowerCase() }));
}

/**
 * O troco aparece sempre que existe e é maior que o valor a cobrar (mesma regra do servidor, CobrancaIfood::texto). A forma
 * não entra na conta: o servidor só grava o troco que vem do dinheiro, e com "MISTO" as formas somem do texto.
 */
export function mostraTroco(centavos, forma, trocoPara) {
    return trocoPara !== null && trocoPara !== undefined && Number(trocoPara) > Number(centavos);
}
