// Entregas: funções puras do chat da loja com os motoboys (serviço entregas-conversas e componente Portal::Conversas).
// Sem imports, para os testes rodarem com o Node: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
// O servidor é o App\Support\Entregas\ConversasDaLoja (mesmo limite de texto).

/** Maior mensagem aceita (ConversasDaLoja::MAX_CARACTERES). */
export const MAX_CARACTERES = 1000;

/** Intervalo da leitura da conversa aberta. */
export const INTERVALO_CONVERSA_MS = 5000;

/** Intervalo da lista de conversas (o total de não lidas no botão Conversas). */
export const INTERVALO_LISTA_CONVERSAS_MS = 20000;

const horario = (mensagem) => {
    const valor = new Date(mensagem?.em ?? 0).getTime();
    return Number.isNaN(valor) ? 0 : valor;
};

/**
 * As mensagens das duas listas sem repetir (pelo id; a versão de `novas` vence), da mais antiga para a mais nova. Serve para
 * juntar a mensagem recém-enviada à conversa antes da próxima leitura.
 */
export function mesclarMensagens(atuais, novas) {
    const porId = new Map();

    for (const mensagem of [...(atuais ?? []), ...(novas ?? [])]) {
        if (mensagem?.id) {
            porId.set(mensagem.id, mensagem);
        }
    }

    return [...porId.values()].sort((a, b) => horario(a) - horario(b));
}

/** Total de mensagens não lidas nas conversas da loja. */
export function totalNaoLidas(conversas) {
    return (conversas ?? []).reduce((total, conversa) => total + (Number(conversa?.nao_lidas) || 0), 0);
}

/** O começo do texto quando a conversa é aberta a partir de um pedido. */
export function prefixoDoPedido(numero) {
    return numero ? `Pedido ${numero}: ` : '';
}

/** A última mensagem numa linha, cortada em `tamanho` caracteres. */
export function resumo(texto, tamanho = 60) {
    const linha = String(texto ?? '')
        .replace(/\s+/g, ' ')
        .trim();

    return linha.length > tamanho ? `${linha.slice(0, tamanho - 1)}…` : linha;
}

/** O texto que o servidor aceita (sem espaços nas pontas, de 1 a MAX_CARACTERES caracteres) ou null. */
export function textoValido(texto) {
    if (typeof texto !== 'string') {
        return null;
    }

    const limpo = texto.trim();

    return limpo !== '' && [...limpo].length <= MAX_CARACTERES ? limpo : null;
}

/**
 * Aviso sonoro de mensagem nova. `avisadas` = { conversa: horário (ms) da última mensagem dos outros já vista }, ou null
 * antes da primeira leitura. `itens` = [{ id: conversa, em }] com a última mensagem dos outros de cada conversa.
 * Toca se alguma conversa traz mensagem mais nova que a registrada (ou é uma conversa nova); a primeira leitura só
 * registra, para não tocar com as mensagens que já estavam lá.
 */
export function registrarUltimas(avisadas, itens) {
    const registradas = { ...(avisadas ?? {}) };
    let tocar = false;

    for (const item of itens ?? []) {
        const em = item?.em ? horario(item) : 0;

        if (!item?.id || !em) {
            continue;
        }

        if (avisadas && em > (avisadas[item.id] ?? 0)) {
            tocar = true;
        }

        registradas[item.id] = Math.max(registradas[item.id] ?? 0, em);
    }

    return { avisadas: registradas, tocar };
}

/**
 * A última mensagem dos outros por conversa: a `ultima` de cada conversa da lista (quando não é minha) e, com uma conversa
 * aberta, a mais recente dos outros nas mensagens dela.
 */
export function ultimasDosOutros(conversas, conversaAberta = null, mensagens = []) {
    const itens = (conversas ?? []).filter((conversa) => conversa?.ultima && !conversa.ultima.minha).map((conversa) => ({ id: conversa.id, em: conversa.ultima.em }));
    const dosOutros = (mensagens ?? []).filter((mensagem) => mensagem && !mensagem.minha);

    if (conversaAberta && dosOutros.length) {
        itens.push({ id: conversaAberta, em: dosOutros.reduce((maior, mensagem) => (horario(mensagem) > horario(maior) ? mensagem : maior)).em });
    }

    return itens;
}
