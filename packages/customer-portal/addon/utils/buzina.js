// Entregas: a buzina de moto do portal da loja quando um motoboy aceita um pedido dela (serviço entregas-buzina, rota
// entregas/loja/aceites). Funções puras, testadas em scripts/teste-portal/buzina.test.mjs.

/** O áudio da buzina: console/public/sons/ (fora do fingerprint, no console/ember-cli-build.js). */
export const ARQUIVO_DA_BUZINA = '/sons/buzina.wav';

/** As chaves dos aceites da resposta: o par pedido + aceito_em (a troca aceita grava outro aceito_em e toca de novo). */
export function chavesDosAceites(resposta) {
    const aceites = Array.isArray(resposta?.aceites) ? resposta.aceites : [];
    const chaves = [];
    for (const aceite of aceites) {
        if (typeof aceite?.pedido === 'string' && aceite.pedido && typeof aceite?.aceito_em === 'string' && aceite.aceito_em) {
            chaves.push(`${aceite.pedido}|${aceite.aceito_em}`);
        }
    }

    return chaves;
}

/**
 * Compara a leitura com as chaves já vistas. vistas null = primeira leitura: só registra (não toca pelo que já estava
 * aceito ao abrir o portal). Depois, tocar = true se apareceu alguma chave nova (várias na mesma leitura: um toque só).
 * As vistas passam a ser só as desta leitura (a janela de 2 h do servidor), para não crescer sem fim.
 */
export function registrarAceites(vistas, resposta) {
    const chaves = chavesDosAceites(resposta);
    if (vistas === null || vistas === undefined) {
        return { vistas: new Set(chaves), tocar: false };
    }

    return { vistas: new Set(chaves), tocar: chaves.some((chave) => !vistas.has(chave)) };
}
