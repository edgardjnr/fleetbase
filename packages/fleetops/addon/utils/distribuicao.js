// Entregas: painel "Distribuição" no detalhe do pedido (oferta um a um aos motoboys; ver App\Support\Entregas\Distribuicao
// na API). Só importa outro util relativo (pedido-ifood): testado no Node com o resolver.mjs (scripts/teste-portal/distribuicao.test.mjs).
import { ENCERRADOS } from './pedido-ifood';

/** Fases da distribuição (entregas_distribuicoes.fase) → chave fleet-ops.ui.distribuicao.fase.<chave>. */
export const FASES = ['ofertas', 'aberta', 'encerrada'];

/** Motivos (entregas_distribuicoes.motivo) → chave fleet-ops.ui.distribuicao.motivo.<chave>. */
export const MOTIVOS = ['aceita', 'atribuida', 'cancelada', 'aberta_pela_central', 'fila_esgotada', 'prazo', 'sem_candidato', 'redespachada', 'falha'];

/** Respostas da oferta (entregas_ofertas.resposta) → chave fleet-ops.ui.distribuicao.resposta.<chave>. */
export const RESPOSTAS = ['pendente', 'aceita', 'recusada', 'vencida', 'cancelada'];

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

/**
 * "Abrir a todos agora": só com a distribuição em ofertas, ligada no servidor (`ligada: false` = ENTREGAS_DISTRIBUICAO
 * vazia: a distribuição parou de avançar e o aceite já é livre) e o pedido não encerrado.
 */
export function podeAbrir(painel, statusDoPedido) {
    return painel?.fase === 'ofertas' && painel?.ligada !== false && !ENCERRADOS.includes(statusDoPedido);
}
