/**
 * Entregas RestaurantePro: avatar do motoboy no mapa ao vivo = capacete na cor da situação dele.
 *
 * A situação vem da API (GET int/v1/entregas/mapa/motoboys, App\Support\Entregas\SituacaoDoMotoboy):
 * livre (verde), coleta = indo buscar na loja (amarelo), entrega = levando ao cliente (vermelho) e offline (cinza).
 * Enquanto a API não respondeu, vale só o online do motoboy (verde ou cinza).
 */
// Caminhos literais e completos: o build troca cada um pelo nome com hash (fingerprint); montado por partes, não troca.
export const CAPACETES = {
    livre: '/engines-dist/images/capacete-verde.png',
    coleta: '/engines-dist/images/capacete-amarelo.png',
    entrega: '/engines-dist/images/capacete-vermelho.png',
    offline: '/engines-dist/images/capacete-cinza.png',
};

/**
 * Indexa a resposta da API por uuid e por public_id (o id do motoboy no console é o uuid).
 *
 * @param {Array<{uuid: string, public_id: string, situacao: string}>} motoboys
 * @returns {Object<string, string>}
 */
export function indexarSituacoes(motoboys = []) {
    const situacoes = {};
    for (const motoboy of motoboys ?? []) {
        if (!motoboy || !CAPACETES[motoboy.situacao]) continue;
        if (motoboy.uuid) situacoes[motoboy.uuid] = motoboy.situacao;
        if (motoboy.public_id) situacoes[motoboy.public_id] = motoboy.situacao;
    }
    return situacoes;
}

export function situacaoDoMotoboy(driver, situacoes = {}) {
    if (!driver) return 'offline';
    return situacoes[driver.id] ?? situacoes[driver.public_id] ?? (driver.online ? 'livre' : 'offline');
}

export default function capaceteDoMotoboy(driver, situacoes = {}) {
    return CAPACETES[situacaoDoMotoboy(driver, situacoes)];
}
