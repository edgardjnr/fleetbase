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
