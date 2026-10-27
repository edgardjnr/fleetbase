/**
 * Entregas: funções puras da tela Integrações do portal da loja (rota portal.integracoes e componentes
 * Portal::Integracoes::*). Os cartões vêm de GET int/v1/entregas/loja/integracoes (CatalogoDeIntegracoes no servidor):
 * {id, nome, fluxo, disponivel, estado, …}. Sem imports: testado no Node (scripts/teste-portal/integracoes-da-loja.test.mjs).
 *
 * As funções do código do iFood (validade, contagem, link) são cópia das de packages/fleetops/addon/utils/vinculo-ifood.js
 * (tela Lojas da central): mudou a regra lá, mude aqui.
 */

/** Estados que o servidor manda (IntegracaoDaLoja). */
export const ESTADOS = ['conectada', 'aguardando', 'atencao', 'desconectada'];

/** Fluxos de conexão que o portal sabe mostrar; cartão de outro fluxo aparece sem o botão de conectar. */
export const FLUXOS = ['ifood', 'chave'];

/** Estado do cartão: o do servidor, ou 'desconectada' quando vem vazio ou desconhecido. */
export function estadoDoCartao(integracao) {
    return ESTADOS.includes(integracao?.estado) ? integracao.estado : 'desconectada';
}

/** O portal tem a tela de conexão deste cartão (o fluxo é conhecido e o servidor está com a integração ligada). */
export function podeConectar(integracao) {
    return Boolean(integracao?.disponivel) && FLUXOS.includes(integracao?.fluxo);
}

/**
 * O que o cartão mostra embaixo do nome: 'conectada' | 'aguardando' | 'atencao' (o estado), 'indisponivel' (desligada no
 * servidor e sem conexão a cuidar) ou 'desconectada'. Conectada, aguardando ou em atenção continua aparecendo mesmo
 * desligada, para a loja poder desconectar.
 */
export function seloDoCartao(integracao) {
    const estado = estadoDoCartao(integracao);
    if (estado !== 'desconectada') {
        return estado;
    }

    return podeConectar(integracao) ? 'desconectada' : 'indisponivel';
}

/** Ordem da tela: as que pedem atenção primeiro, depois a ordem do servidor (estável). */
export function ordenarCartoes(integracoes) {
    const peso = (integracao) => (estadoDoCartao(integracao) === 'atencao' ? 0 : 1);

    return (Array.isArray(integracoes) ? integracoes : [])
        .map((integracao, posicao) => ({ integracao, posicao }))
        .sort((a, b) => peso(a.integracao) - peso(b.integracao) || a.posicao - b.posicao)
        .map(({ integracao }) => integracao);
}

/** Troca um cartão da lista pelo que o servidor devolveu (mesmo id); id novo vai para o fim. */
export function trocarCartao(integracoes, novo) {
    const lista = Array.isArray(integracoes) ? integracoes : [];
    if (!novo?.id) {
        return lista;
    }

    return lista.some((integracao) => integracao?.id === novo.id) ? lista.map((integracao) => (integracao?.id === novo.id ? novo : integracao)) : [...lista, novo];
}

/** Resumo de quantos sistemas estão conectados, para o topo da tela. */
export function totalConectadas(integracoes) {
    return (Array.isArray(integracoes) ? integracoes : []).filter((integracao) => estadoDoCartao(integracao) === 'conectada').length;
}

/** Iniciais do selo do sistema ("iFood" → "iF", "RestaurantePro" → "RP", "Anota AI" → "AA"). */
export function iniciaisDoSistema(nome) {
    const texto = String(nome ?? '').trim();
    if (!texto) {
        return '?';
    }
    const palavras = texto.split(/\s+/);
    if (palavras.length > 1) {
        return (palavras[0][0] + palavras[1][0]).toUpperCase();
    }
    const maiusculas = texto.match(/[A-Z]/g) ?? [];
    if (maiusculas.length >= 2) {
        return maiusculas[0] + maiusculas[1];
    }

    return texto.slice(0, 2);
}

/**
 * Quanto tempo passou desde o instante ISO, para o "último pedido há 3 min": {unidade: 'agora'|'minutos'|'horas'|'dias', n}.
 * Data inválida ou ausente: null. Instante no futuro (relógio do PC atrasado) conta como "agora". Cópia do tempoDesde da
 * tela Lojas (packages/fleetops/addon/utils/integracao-da-loja.js).
 */
export function tempoDesde(iso, agora) {
    const quando = Date.parse(iso ?? '');
    if (!Number.isFinite(quando)) {
        return null;
    }
    const segundos = Math.max(0, Math.floor((Number(agora) - quando) / 1000));
    if (segundos < 60) {
        return { unidade: 'agora', n: 0 };
    }
    const minutos = Math.floor(segundos / 60);
    if (minutos < 60) {
        return { unidade: 'minutos', n: minutos };
    }
    const horas = Math.floor(minutos / 60);
    if (horas < 24) {
        return { unidade: 'horas', n: horas };
    }

    return { unidade: 'dias', n: Math.floor(horas / 24) };
}

/** Validade do código de vínculo quando o servidor não diz (o iFood dá 10 min). */
export const VALIDADE_PADRAO_SEGUNDOS = 600;

/** Instante (ms) em que o código vence, a partir da validade que o servidor devolveu e de quando a resposta chegou. */
export function vencimentoDoCodigo(expiraEmSegundos, recebidoEm) {
    const segundos = Number(expiraEmSegundos);
    return recebidoEm + (Number.isFinite(segundos) && segundos > 0 ? segundos : VALIDADE_PADRAO_SEGUNDOS) * 1000;
}

/** Segundos que faltam até o vencimento (nunca negativo; arredonda para cima). Valor não finito conta como vencido (0). */
export function segundosRestantes(vencimento, agora) {
    const restante = Math.ceil((Number(vencimento) - Number(agora)) / 1000);
    return Number.isFinite(restante) ? Math.max(0, restante) : 0;
}

/** Contagem regressiva "9:05", "0:59", "0:00". */
export function contagem(segundos) {
    const total = Math.max(0, Math.floor(Number(segundos) || 0));
    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
}

/** O código de autorização como foi colado, sem nenhum espaço em branco (o código do iFood não tem espaços). */
export function limparCodigo(texto) {
    return String(texto ?? '').replace(/\s+/g, '');
}

/**
 * Só um link https do iFood vira botão (o servidor devolve o verificationUrlComplete do Portal do Parceiro): host
 * ifood.com.br ou subdomínio dele, sem usuário, senha nem porta.
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
