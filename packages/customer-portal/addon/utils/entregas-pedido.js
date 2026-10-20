// Entregas: regras do pedido no portal da loja numa lista só. Os status espelham a API (App\Support\Entregas\StatusDoPedido
// e App\Http\Middleware\RegrasPortalLoja): mude junto com o servidor.

/** Pedido encerrado (StatusDoPedido::ENCERRADOS): a loja não cancela e não vê mais o motoboy. */
export const ENCERRADOS = ['completed', 'done', 'canceled', 'cancelled', 'order_canceled', 'expired'];

/** Status em que a loja ainda cancela, se nenhum motoboy aceitou (RegrasPortalLoja::STATUS_CANCELAVEIS). */
export const CANCELAVEIS = ['created', 'dispatched'];

/** Intervalo do acompanhamento do pedido aberto: o motoboy é consultado a cada volta. */
export const INTERVALO_MS = 20000;

/** O detalhe completo é relido a cada tantas voltas (~60 s), além de quando muda quem está com o pedido. */
export const VOLTAS_DETALHE = 3;

/** Intervalo da atualização automática da lista de pedidos. */
export const INTERVALO_LISTA_MS = 30000;

/** Intervalo da consulta dos motoboys no mapa (posição e situação de todos os motoboys online). */
export const INTERVALO_MAPA_MS = 5000;

/**
 * Teto da espera depois de falhas seguidas. O throttle:60,1 das rotas loja/* é por usuário e soma as abas abertas; o mapa de
 * motoboys (loja/motoboys) tem um balde próprio, também por usuário (entregas-loja-mapa).
 */
export const ESPERA_MAXIMA_MS = 120000;

/** Espera até a próxima consulta: o intervalo, dobrado a cada falha seguida (429 inclusive), até ESPERA_MAXIMA_MS. */
export function espera(intervalo, falhas = 0) {
    return Math.min(intervalo * 2 ** Math.min(falhas, 10), ESPERA_MAXIMA_MS);
}

/** Quem está com o pedido, pela resposta do motoboy: ninguém, um motoboy chamado (ainda sem aceitar) ou um que aceitou. */
export function situacao(motoboy) {
    if (!motoboy) {
        return 'ninguem';
    }

    return motoboy.aceitou ? 'aceito' : 'chamado';
}

/**
 * Os pedidos da lista ao lado do mapa: só os em andamento (sem os encerrados, isto é, concluídos, cancelados e expirados).
 * O `status` lê o status de cada pedido (model do Ember Data ou objeto simples).
 */
export function pedidosEmAndamento(pedidos, status = (pedido) => pedido?.status) {
    return Array.from(pedidos ?? []).filter((pedido) => !ENCERRADOS.includes(status(pedido)));
}

/**
 * Número do iFood do pedido, ou null: as notas começam com "iFood #<número>" e o internal_id é o mesmo número
 * (CriadorDoPedidoIfood). O portal mostra o selo e esconde o Cancelar; o servidor recusa o cancelamento de qualquer
 * jeito (RegrasPortalLoja). Mesma regra do console (packages/fleetops/addon/utils/pedido-ifood.js).
 */
export function numeroIfood(notas, idInterno) {
    if (typeof notas !== 'string' || idInterno === null || idInterno === undefined || idInterno === '') {
        return null;
    }
    const achado = /^iFood #(\S+)/.exec(notas.trim());

    return achado && achado[1] === String(idInterno) ? achado[1] : null;
}

// Mesma regra do console (packages/fleetops/addon/utils/pedido-de-origem.js, origemDoPedido): o nome do sistema
// passa pelo Integracao::nomeDoSistema do servidor (letras, números, espaço, ponto, hífen e sublinhado, até 40).
const NOTAS_DE_ORIGEM = /^([\p{L}\p{N} ._-]{1,40}?) #(\S+)/u;

/**
 * Origem do pedido para o selo do portal: {ifood: true, sistema: 'iFood', numero} (notas "iFood #N"),
 * {ifood: false, sistema, numero} (pedido que a loja mandou pelo sistema dela, notas "<Sistema> #N") ou null. Nos dois
 * casos o internal_id precisa ser igual a N. O Cancelar não depende disto: no pedido da integração ele segue a regra do
 * portal (antes do aceite); no do iFood, o numeroIfood esconde.
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

/**
 * Chave customer-portal.ui.entregas.<chave> do cancelado cobrado no extrato, pelo cancelado_origem do servidor:
 * 'integracao' = a própria loja cancelou pelo sistema dela depois da coleta; 'ifood' ou ausente (API antiga) = o iFood.
 */
export function chaveDoCanceladoCobrado(origem) {
    return origem === 'integracao' ? 'canceled-store-charged' : 'canceled-ifood-charged';
}

/**
 * Nome do cliente no card e no detalhe do pedido: o nome do Local de entrega. O iFood grava o `customer.name`
 * (PedidoDoIfood, "Cliente iFood" sem nome), a integração o `cliente.nome` (em maiúsculas) e o portal o "Nome do cliente"
 * do novo pedido. Sem nome, ou com o próprio endereço no lugar do nome, null.
 */
export function nomeDoCliente(local) {
    const nome = typeof local?.name === 'string' ? local.name.trim() : '';

    if (nome === '') {
        return null;
    }

    const comparavel = (texto) => (typeof texto === 'string' ? texto.trim().toLowerCase() : '');
    const enderecos = [local.street1, local.address].map(comparavel).filter((texto) => texto !== '');
    const nomeComparavel = nome.toLowerCase();

    return enderecos.some((endereco) => endereco === nomeComparavel || endereco.startsWith(nomeComparavel) || nomeComparavel.startsWith(endereco)) ? null : nome;
}

/**
 * Tempo até a entrega no card do pedido. O primeiro valor útil da lista vale: o `eta` do recurso do pedido é um objeto do
 * Fleet-Ops (TrackingIntelligenceService::eta: `completion_seconds`, até terminar a entrega, ou `active_stop_seconds`, até
 * a parada atual) e vira "N min"; número = segundos; data ISO vira a hora de chegada (HH:mm); outro texto fica como veio.
 * Sem nada útil, null (o card não mostra o relógio). Antes, o objeto aparecia como "[object Object]".
 */
export function textoDoEta(valores) {
    const minutos = (segundos) => (Number.isFinite(segundos) && segundos > 0 ? `${Math.max(1, Math.round(segundos / 60))} min` : null);

    for (const valor of Array.isArray(valores) ? valores : []) {
        if (typeof valor === 'number') {
            const texto = minutos(valor);
            if (texto) return texto;
        } else if (typeof valor === 'string') {
            const texto = valor.trim();
            if (texto === '') continue;
            if (/^\d{4}-\d{2}-\d{2}T/.test(texto)) {
                const data = new Date(texto);
                if (!Number.isNaN(data.getTime())) return `${String(data.getHours()).padStart(2, '0')}:${String(data.getMinutes()).padStart(2, '0')}`;
                continue;
            }
            return texto;
        } else if (valor && typeof valor === 'object' && !Array.isArray(valor)) {
            const texto = minutos(valor.completion_seconds ?? valor.active_stop_seconds);
            if (texto) return texto;
        }
    }

    return null;
}
