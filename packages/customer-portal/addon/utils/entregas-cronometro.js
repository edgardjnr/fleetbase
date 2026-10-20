/**
 * Entregas: cronômetro do prazo de entrega no portal da loja (spec
 * docs/superpowers/specs/2026-10-20-cronometro-do-prazo-design.md). O servidor grava o prazo na criação do pedido, no
 * meta (entregas_prazo_em, ISO com o fuso de Brasília; ver App\Support\Entregas\PrazoDaEntrega). Aqui só a conta, com o
 * relógio do aparelho:
 * - restante > 15 min → 'no_prazo' (verde), minutos para cima ("32 min");
 * - 0 < restante ≤ 15 min → 'perto' (amarelo), minutos para cima (59 s = "1 min");
 * - restante ≤ 0 → 'atrasado' (vermelho), minutos para cima, no mínimo 1 (0 s a 60 s = "Atrasado há 1 min", 61 s = 2;
 *   nunca "há 0 min").
 * Prazo ausente ou ilegível → null (sem cronômetro).
 *
 * Funções puras, sem import do Ember, testadas em scripts/teste-portal/cronometro.test.mjs. Cópia da do console
 * (fleetops/addon/utils/entregas-cronometro.js): um engine não importa do outro, e o teste roda os mesmos casos nas duas.
 * Os ENCERRADOS são os de utils/entregas-pedido.js (o teste confere). Mudou aqui, mude lá.
 */

/** Chave do prazo no meta do pedido (PrazoDaEntrega::CHAVE). */
export const CHAVE_DO_PRAZO = 'entregas_prazo_em';

/** Faltando isto ou menos, o cronômetro fica amarelo. */
export const PERTO_MS = 15 * 60000;

/** De quanto em quanto tempo as telas recalculam o cronômetro (serviço entregas-relogio). */
export const INTERVALO_DO_CRONOMETRO_MS = 30000;

/** Pedido encerrado (StatusDoPedido::ENCERRADOS do servidor): sem cronômetro. */
export const ENCERRADOS = ['completed', 'done', 'canceled', 'cancelled', 'order_canceled', 'expired'];

/** Tipo do Badge do ember-ui (tokens de cor) por estado: verde, amarelo e vermelho. */
export const TIPO_DO_BADGE = { no_prazo: 'success', perto: 'warning', atrasado: 'error' };

/** Textos padrão (pt-BR); as telas passam os do intl. */
export const TEXTOS_PADRAO = {
    faltam: (n) => `${n} min`,
    atrasado: (n) => `Atrasado há ${n} min`,
};

const ISO_COM_HORA = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/;

/**
 * cronometro(prazoIso, agora, textos) → null | { minutos, estado: 'no_prazo' | 'perto' | 'atrasado', texto }.
 * `agora` em milissegundos (Date.now()) ou Date.
 */
export function cronometro(prazoIso, agora = Date.now(), textos = TEXTOS_PADRAO) {
    if (typeof prazoIso !== 'string' || !ISO_COM_HORA.test(prazoIso.trim())) {
        return null;
    }

    const prazo = Date.parse(prazoIso.trim());
    const instante = agora instanceof Date ? agora.getTime() : typeof agora === 'number' ? agora : NaN;

    if (!Number.isFinite(prazo) || !Number.isFinite(instante)) {
        return null;
    }

    const restante = prazo - instante;

    if (restante > 0) {
        const minutos = Math.ceil(restante / 60000);

        return { minutos, estado: restante > PERTO_MS ? 'no_prazo' : 'perto', texto: textos.faltam(minutos) };
    }

    // para cima também depois do prazo, com mínimo 1: 0 s a 60 s = 1; 61 s a 120 s = 2 (a mesma regra do app)
    const minutos = Math.max(1, Math.ceil(-restante / 60000));

    return { minutos, estado: 'atrasado', texto: textos.atrasado(minutos) };
}

/** O prazo gravado no meta do pedido (model do Ember Data ou objeto simples), ou null. */
export function prazoDoPedido(pedido) {
    const meta = pedido?.meta;
    const prazo = meta && typeof meta === 'object' ? meta[CHAVE_DO_PRAZO] : null;

    return typeof prazo === 'string' && prazo.trim() !== '' ? prazo : null;
}

/** Cronômetro do pedido: null quando o pedido está encerrado (concluído, cancelado, expirado) ou não tem prazo. */
export function cronometroDoPedido(pedido, agora = Date.now(), textos = TEXTOS_PADRAO) {
    if (!pedido || ENCERRADOS.includes(pedido.status)) {
        return null;
    }

    return cronometro(prazoDoPedido(pedido), agora, textos);
}
