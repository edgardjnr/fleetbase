// Entregas RestaurantePro: endereço no formato brasileiro e as sugestões do Google na busca de endereço
// (EnderecoGoogleInput e o campo do pedido). Funções puras, sem import do Ember (testadas no Node:
// scripts/teste-portal/endereco-brasileiro.test.mjs).

/** O servidor só consulta o Google a partir de 3 caracteres (BuscaDeEnderecos::MINIMO_DE_CARACTERES). */
export const MINIMO_DE_CARACTERES = 3;

function preenchido(valor) {
    return valor !== null && valor !== undefined && String(valor).trim() !== '';
}

/** "Jardim Paulista, Ribeirão Preto - SP, 14025-150": bairro, cidade com a UF e CEP, o que existir. */
export function linhaDoBairroECidade(place) {
    if (!place) {
        return '';
    }

    const cidade = [place.city, place.province]
        .filter(preenchido)
        .map((valor) => String(valor).trim())
        .join(' - ');

    return [place.neighborhood, cidade, place.postal_code]
        .filter(preenchido)
        .map((valor) => String(valor).trim())
        .join(', ');
}

export function temTextoParaBuscar(texto) {
    return typeof texto === 'string' && texto.trim().length >= MINIMO_DE_CARACTERES;
}

/** {place_id, sessao, texto} da sugestão do Google no campo do pedido (EnderecosController@busca), ou null. */
export function dadosDaSugestao(place) {
    const dados = place?.meta?.entregas_sugestao;

    return dados && typeof dados.place_id === 'string' && dados.place_id !== '' ? dados : null;
}

export function ehSugestaoDoGoogle(place) {
    return dadosDaSugestao(place) !== null;
}

/** Token de sessão do Google: uma busca (várias teclas) até a escolha de um endereço. */
export function novaSessaoDeBusca() {
    if (typeof globalThis.crypto?.randomUUID === 'function') {
        return globalThis.crypto.randomUUID();
    }

    let texto = '';
    for (let i = 0; i < 32; i++) {
        texto += Math.floor(Math.random() * 16).toString(16);
    }

    return texto;
}
