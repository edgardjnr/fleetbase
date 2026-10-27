/**
 * Entregas RestaurantePro: funções puras da "Minha carteira" da loja pré-paga no portal (tela portal/carteira, aba do topo
 * e número do Início). Sem import do Ember: testadas no Node (scripts/teste-portal/carteira-da-loja.test.mjs). O servidor
 * é quem decide (crédito, limites e se a loja pode pedir): aqui só se lê e se mostra. Servidor:
 * api/app/Support/Entregas/PrePago/CLAUDE.md.
 */

/** Atalhos de valor do "Recarregar via Pix", em reais. */
export const ATALHOS = [50, 100, 200, 500];

/** Intervalo da consulta da recarga enquanto o Pix está sendo gerado ou na tela (o servidor consulta o Asaas a cada 5 s). */
export const INTERVALO_DO_PIX_MS = 5000;

/** Limites padrão de uma recarga (PrePago::VALOR_MINIMO e VALOR_MAXIMO), quando a resposta não traz os da central. */
export const MINIMO_PADRAO = 10;
export const MAXIMO_PADRAO = 5000;

/**
 * Evento disparado no `document` quando uma recarga é paga: o topo relê o resumo do dia na hora (a aba da carteira perde o
 * alerta sem esperar a volta de 1 min).
 */
export const EVENTO_DA_CARTEIRA = 'entregas-carteira-mudou';

/** O motivo que o servidor grava quando a loja cancela o Pix (Recargas::MOTIVO_CANCELADA): o selo vira "Cancelada". */
export const MOTIVO_CANCELADA = 'Pix cancelado.';

/** Situações da recarga em que a tela continua perguntando ao servidor. */
const EM_ANDAMENTO = ['criando', 'aguardando'];

/** Base64 puro (sem espaço, sem quebra e sem prefixo): só assim a imagem do QR vira `data:` URI. */
const BASE64 = /^[A-Za-z0-9+/]+={0,2}$/;

/** Número em reais (o MySQL manda decimal como texto) ou null. */
function reais(valor) {
    if (valor === null || valor === undefined || valor === '' || typeof valor === 'boolean') {
        return null;
    }
    const numero = Number(valor);

    return Number.isFinite(numero) ? numero : null;
}

/** A loja é pré-paga? Aceita a resposta do `resumo-do-dia` (com `carteira`) ou a própria `carteira`. */
export function ehPrePaga(resumoOuCarteira) {
    const carteira = resumoOuCarteira?.carteira ?? resumoOuCarteira;

    return carteira?.modelo === 'pre';
}

/**
 * A carteira da resposta (`resumo-do-dia` ou `loja/carteira`) com os números já como número; null na loja pós-paga ou
 * sem resposta. `podePedir` só é false quando o servidor disse false (sem a informação, não alarma).
 */
export function resumoDaCarteira(carteira) {
    if (!ehPrePaga(carteira)) {
        return null;
    }
    const recarga = carteira.recarga ?? {};
    const minimo = reais(recarga.minimo);
    const maximo = reais(recarga.maximo);

    return {
        credito: reais(carteira.credito),
        reservado: reais(carteira.reservado),
        disponivel: reais(carteira.disponivel),
        minimoParaPedir: reais(carteira.minimo_para_pedir),
        podePedir: carteira.pode_pedir !== false,
        recarga: {
            ligada: recarga.ligada === true,
            minimo: minimo !== null && minimo > 0 ? minimo : MINIMO_PADRAO,
            maximo: maximo !== null && maximo > 0 ? maximo : MAXIMO_PADRAO,
        },
    };
}

/** Quanto a loja deve (o crédito negativo, em reais positivos), ou null quando não deve. */
export function valorDevido(carteira) {
    const credito = reais(carteira?.credito);

    return credito !== null && credito < 0 ? Math.round(-credito * 100) / 100 : null;
}

/**
 * O valor digitado no campo da recarga, em reais (com no máximo 2 casas), ou null quando não dá para ler. Aceita "150",
 * "150,50", "150.50", "R$ 1.500,00", "1.500" e "1,500.00". Zero ou negativo é null.
 */
export function valorDigitado(texto) {
    if (typeof texto === 'number') {
        texto = String(texto);
    }
    if (typeof texto !== 'string') {
        return null;
    }
    let limpo = texto.replace(/R\$/gi, '').replace(/\s+/g, '');
    if (limpo === '' || !/^[\d.,]+$/.test(limpo)) {
        return null;
    }

    const virgula = limpo.lastIndexOf(',');
    const ponto = limpo.lastIndexOf('.');
    if (virgula >= 0 && ponto >= 0) {
        // os dois: o que vem por último é o decimal, o outro separa milhar
        const decimal = virgula > ponto ? ',' : '.';
        const milhar = decimal === ',' ? '.' : ',';
        const [inteira, ...resto] = limpo.split(decimal);
        const milhares = decimal === ',' ? /^\d{1,3}(\.\d{3})*$/ : /^\d{1,3}(,\d{3})*$/;
        if (resto.length !== 1 || !milhares.test(inteira)) {
            return null;
        }
        limpo = `${inteira.split(milhar).join('')}.${resto[0]}`;
    } else if (virgula >= 0 || ponto >= 0) {
        const separador = virgula >= 0 ? ',' : '.';
        const partes = limpo.split(separador);
        if (partes.length > 2) {
            // o separador repetido só pode ser de milhar ("1.500.000")
            if (!/^\d{1,3}([.,]\d{3})+$/.test(limpo)) {
                return null;
            }
            limpo = partes.join('');
        } else if (separador === '.' && /^\d{1,3}\.\d{3}$/.test(limpo)) {
            // "1.500" é mil e quinhentos (no Brasil o ponto separa milhar)
            limpo = partes.join('');
        } else {
            limpo = partes.join('.');
        }
    }

    const [, casas = ''] = limpo.split('.');
    if (casas.length > 2 || !/^\d+(\.\d+)?$/.test(limpo)) {
        return null;
    }
    const centavos = Math.round(Number(limpo) * 100);

    return Number.isFinite(centavos) && centavos > 0 ? centavos / 100 : null;
}

/**
 * Confere o valor contra os limites da recarga antes de enviar (o servidor confere de novo). Devolve null quando está
 * certo, ou o problema: `{chave: 'invalido'}`, `{chave: 'minimo', limite}` ou `{chave: 'maximo', limite}`.
 */
export function problemaDoValor(valor, recarga) {
    const minimo = reais(recarga?.minimo) || MINIMO_PADRAO;
    const maximo = reais(recarga?.maximo) || MAXIMO_PADRAO;

    if (valor === null || valor === undefined || !Number.isFinite(valor) || valor <= 0) {
        return { chave: 'invalido' };
    }
    if (valor < minimo) {
        return { chave: 'minimo', limite: minimo };
    }
    if (valor > maximo) {
        return { chave: 'maximo', limite: maximo };
    }

    return null;
}

/** A tela continua perguntando ao servidor nessa situação (o Pix está sendo gerado ou na tela)? */
export function deveAcompanhar(situacao) {
    return EM_ANDAMENTO.includes(situacao);
}

/**
 * O selo da recarga na lista: `paga`, `aguardando`, `gerando` (criando), `cancelada` (expirada pela loja), `expirada`,
 * `falhou` ou `desconhecida`, com a chave de tradução e a classe do CSS.
 */
export function seloDaRecarga(recarga) {
    const situacao = recarga?.situacao;
    let chave;

    if (situacao === 'criando') {
        chave = 'gerando';
    } else if (situacao === 'expirada') {
        chave = String(recarga?.motivo ?? '').trim() === MOTIVO_CANCELADA ? 'cancelada' : 'expirada';
    } else if (['paga', 'aguardando', 'falhou'].includes(situacao)) {
        chave = situacao;
    } else {
        chave = 'desconhecida';
    }

    return {
        chave,
        traducao: `customer-portal.ui.entregas.carteira.situacao.${chave}`,
        classe: `entregas-recarga-selo entregas-recarga-selo-${chave}`,
    };
}

/**
 * Quanto falta para o Pix vencer, pelo relógio do aparelho: `{segundos, texto, expirou}` ("mm:ss"; "h:mm:ss" com 1 h ou
 * mais), ou null sem data legível.
 */
export function tempoRestante(expiraEm, agora = Date.now()) {
    const fim = typeof expiraEm === 'string' && expiraEm.trim() !== '' ? Date.parse(expiraEm) : NaN;
    if (!Number.isFinite(fim) || !Number.isFinite(agora)) {
        return null;
    }
    const segundos = Math.max(0, Math.ceil((fim - agora) / 1000));
    const h = Math.floor(segundos / 3600);
    const m = Math.floor((segundos % 3600) / 60);
    const s = segundos % 60;
    const dois = (n) => String(n).padStart(2, '0');

    return { segundos, texto: h > 0 ? `${h}:${dois(m)}:${dois(s)}` : `${dois(m)}:${dois(s)}`, expirou: segundos === 0 };
}

/** A imagem do QR como `data:` URI, só quando a base64 é limpa (o texto vem do Asaas, via servidor); senão null. */
export function imagemDoQr(base64) {
    return typeof base64 === 'string' && base64.length > 0 && BASE64.test(base64) ? `data:image/png;base64,${base64}` : null;
}
