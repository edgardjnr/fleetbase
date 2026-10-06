/**
 * Entregas RestaurantePro: funções puras do vínculo da loja com o iFood na tela Lojas (selo do card e modal
 * `modals/vincular-ifood`). O servidor é o api/app/Http/Controllers/Entregas/IfoodLojasController.php.
 */

/** Validade do código de vínculo quando o servidor não diz (o iFood dá 10 min). */
export const VALIDADE_PADRAO_SEGUNDOS = 600;

/** Situação do selo a partir do bloco `ifood` da loja: 'vinculada', 'perdido' ou 'nenhum'. */
export function situacaoDoIfood(ifood) {
    if (ifood?.situacao === 'vinculada') {
        return 'vinculada';
    }
    if (ifood?.situacao === 'vinculo_perdido') {
        return 'perdido';
    }
    return 'nenhum';
}

/** Instante (ms) em que o código vence, a partir da validade que o servidor devolveu e de quando a resposta chegou. */
export function vencimentoDoCodigo(expiraEmSegundos, recebidoEm) {
    const segundos = Number(expiraEmSegundos);
    return recebidoEm + (Number.isFinite(segundos) && segundos > 0 ? segundos : VALIDADE_PADRAO_SEGUNDOS) * 1000;
}

/** Segundos que faltam até o vencimento (nunca negativo; arredonda para cima). Valor não finito (NaN, undefined) conta como vencido (0). */
export function segundosRestantes(vencimento, agora) {
    const restante = Math.ceil((Number(vencimento) - Number(agora)) / 1000);
    return Number.isFinite(restante) ? Math.max(0, restante) : 0;
}

/** Contagem regressiva "9:05", "0:59", "0:00". */
export function contagem(segundos) {
    const total = Math.max(0, Math.floor(Number(segundos) || 0));
    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
}

/** O código de autorização como foi colado, sem nenhum espaço em branco (o código do iFood não tem espaços; tira também os do meio e as quebras de linha). */
export function limparCodigo(texto) {
    return String(texto ?? '').replace(/\s+/g, '');
}

/**
 * Só um link https do iFood vira botão (o servidor devolve o verificationUrlComplete do Portal do Parceiro).
 * O host precisa ser ifood.com.br ou um subdomínio dele ("evilifood.com.br" não vale), e a URL não pode ter usuário,
 * senha nem porta ("https://x@evil.com", "https://evil.com@portal.ifood.com.br", ":8443").
 */
export function linkSeguro(link) {
    try {
        const url = new URL(String(link ?? ''));
        const hostDoIfood = /(^|\.)ifood\.com\.br$/.test(url.hostname);
        return url.protocol === 'https:' && hostDoIfood && !url.username && !url.password && !url.port ? url.href : null;
    } catch {
        return null;
    }
}
