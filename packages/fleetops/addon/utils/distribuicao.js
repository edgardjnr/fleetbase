// Entregas: painel "Distribuição" no detalhe do pedido (oferta um a um aos motoboys; ver App\Support\Entregas\Distribuicao
// na API). Só importa outro util relativo (pedido-ifood): testado no Node com o resolver.mjs (scripts/teste-portal/distribuicao.test.mjs).
// Distribuição em rodadas (ENTREGAS_DISTRIBUICAO_RODADAS; a resposta traz `rodadas: true`): volta, rodada, raio, lista
// aberta e o botão "Mostrar a todos agora". Sem a chave, o painel de antes.
import { ENCERRADOS } from './pedido-ifood';

/** Fases da distribuição (entregas_distribuicoes.fase) → chave fleet-ops.ui.distribuicao.fase.<chave>. */
export const FASES = ['ofertas', 'aberta', 'encerrada'];

/** Motivos (entregas_distribuicoes.motivo) → chave fleet-ops.ui.distribuicao.motivo.<chave>. */
export const MOTIVOS = ['aceita', 'atribuida', 'cancelada', 'aberta_pela_central', 'fila_esgotada', 'prazo', 'sem_candidato', 'redespachada', 'falha'];

/**
 * Respostas da oferta (entregas_ofertas.resposta) → chave fleet-ops.ui.distribuicao.resposta.<chave>. `dispensada`
 * (dispensou pela lista aberta) e `aceita_pela_lista` vêm das rodadas.
 */
export const RESPOSTAS = ['pendente', 'aceita', 'recusada', 'vencida', 'cancelada', 'dispensada', 'aceita_pela_lista'];

export function chaveDaFase(fase) {
    return FASES.includes(fase) ? fase : 'desconhecida';
}

export function chaveDoMotivo(motivo) {
    if (motivo === null || motivo === undefined || motivo === '') {
        return null;
    }

    return MOTIVOS.includes(motivo) ? motivo : 'desconhecido';
}

export function chaveDaResposta(resposta) {
    return RESPOSTAS.includes(resposta) ? resposta : 'desconhecida';
}

/** Segundos até vencer (arredonda para cima; 0 se venceu ou data inválida). */
export function segundosRestantes(venceEm, agora = Date.now()) {
    const fim = Date.parse(venceEm ?? '');
    if (!Number.isFinite(fim)) {
        return 0;
    }

    return Math.max(0, Math.ceil((fim - agora) / 1000));
}

/** Segundos → minutos inteiros arredondados para cima (null fica null). */
export function minutos(segundos) {
    if (segundos === null || segundos === undefined || !Number.isFinite(Number(segundos))) {
        return null;
    }

    return Math.ceil(Number(segundos) / 60);
}

/** Distribuição em rodadas: só com `rodadas: true` na resposta (a chave ENTREGAS_DISTRIBUICAO_RODADAS ligada). */
export function emRodadas(painel) {
    return painel?.rodadas === true;
}

/** Volta e rodada: inteiro ≥ 1; qualquer outra coisa (nulo, texto, 0, fração) vira o padrão. */
export function inteiroPositivo(bruto, padrao = 1) {
    if (bruto === null || bruto === undefined || bruto === '') {
        return padrao;
    }
    const valor = Number(bruto);

    return Number.isInteger(valor) && valor >= 1 ? valor : padrao;
}

/** Metros → km com até 1 casa, no idioma ativo ("6", "7,5"); null se não for um número positivo. */
export function kmTexto(metros, locale = 'pt-BR') {
    if (metros === null || metros === undefined || metros === '') {
        return null;
    }
    const valor = Number(metros);
    if (!Number.isFinite(valor) || valor <= 0) {
        return null;
    }

    return new Intl.NumberFormat(locale, { maximumFractionDigits: 1 }).format(valor / 1000);
}

/** ISO → "HH:MM" em 24 h (fuso do navegador, ou o informado); null se a data não se lê. */
export function horaCurta(iso, locale = 'pt-BR', timeZone = undefined) {
    const instante = Date.parse(iso ?? '');
    if (!Number.isFinite(instante)) {
        return null;
    }
    const opcoes = { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' };
    if (timeZone) {
        opcoes.timeZone = timeZone;
    }

    return new Intl.DateTimeFormat(locale, opcoes).format(new Date(instante));
}

/** Histórico das ofertas agrupado por volta, na ordem em que as voltas aparecem (linha sem volta = volta 1). */
export function agruparPorVolta(historico) {
    const grupos = [];
    for (const oferta of historico ?? []) {
        const volta = inteiroPositivo(oferta?.volta);
        let grupo = grupos.find((existente) => existente.volta === volta);
        if (!grupo) {
            grupo = { volta, ofertas: [] };
            grupos.push(grupo);
        }
        grupo.ofertas.push(oferta);
    }

    return grupos;
}

/**
 * "Abrir a todos agora" / "Mostrar a todos agora": só com a distribuição em ofertas, ligada no servidor (`ligada: false`
 * = ENTREGAS_DISTRIBUICAO vazia: a distribuição parou de avançar e o aceite já é livre) e o pedido não encerrado. Com
 * rodadas, também só com a lista ainda fechada (`lista_aberta_em` nulo): aberta, o servidor responde 409.
 */
export function podeAbrir(painel, statusDoPedido) {
    const emOfertas = painel?.fase === 'ofertas' && painel?.ligada !== false && !ENCERRADOS.includes(statusDoPedido);
    if (!emOfertas) {
        return false;
    }

    return emRodadas(painel) ? !painel?.lista_aberta_em : true;
}

/**
 * Sufixos das chaves fleet-ops.ui.distribuicao.<sufixo> do botão e da confirmação, e o ícone. Com rodadas, "Mostrar a
 * todos agora" (abre a lista, sem alarme; as ofertas continuam); sem elas, "Abrir a todos agora" (alarme a todos no raio).
 */
export function textosDoBotao(painel) {
    if (emRodadas(painel)) {
        return { botao: 'mostrar', titulo: 'mostrar-titulo', texto: 'mostrar-texto', feito: 'mostrada', icone: 'list' };
    }

    return { botao: 'abrir', titulo: 'abrir-titulo', texto: 'abrir-texto', feito: 'aberto', icone: 'bullhorn' };
}
