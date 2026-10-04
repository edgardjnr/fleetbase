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
