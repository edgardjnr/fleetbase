/**
 * Entregas RestaurantePro: funções puras da entrega junto e dos multiplicadores no console (Financeiro → Tabela de km e
 * relatório, Financeiro → Multiplicadores, selos no extrato e na fatura). Regras iguais às do servidor
 * (App\Support\Entregas\Financeiro\Multiplicadores). Sem imports do Ember: testado no Node
 * (scripts/teste-portal/financeiro-multiplicadores.test.mjs).
 * Spec: docs/superpowers/specs/2026-10-21-entrega-junto-e-multiplicador-design.md.
 */
import { numeroDigitado } from './financeiro';

export const SITUACOES = ['em_andamento', 'agendado', 'encerrado'];
export const FATOR_MINIMO = 1;
export const FATOR_MAXIMO = 5;
export const MAX_DIAS = 7;
export const MAX_NOME = 40;

const PREFIXO = 'fleet-ops.ui.financeiro.multiplicadores';
const CAMPO = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/;
const pad = (n) => String(n).padStart(2, '0');
// o "até km" e os valores das faixas: vírgula ou ponto decimal (a regra da tabela de sempre)
const numero = (valor) => Number(String(valor ?? '').trim().replace(',', '.'));

/* ---------------------------------------------------------------- faixas (as duas tabelas) */

export function faixaParaEdicao(faixa) {
    return { ate_km: String(faixa.ate_km), motoboy: String(faixa.motoboy), loja: String(faixa.loja) };
}

/** Faixas com o "de km" (limite da anterior) e o índice, para exibir. */
export function linhasDeFaixas(faixas) {
    return (faixas ?? []).map((faixa, indice) => ({ ...faixa, indice, de_km: indice === 0 ? '0' : faixas[indice - 1].ate_km || '?' }));
}

/** "Até" maior que zero e crescente, valores ≥ 0. A lista vazia vale (a segunda tabela vazia desliga o recurso). */
export function faixasValidas(faixas) {
    let anterior = 0;
    for (const faixa of faixas ?? []) {
        const ate = numero(faixa.ate_km);
        if (!(ate > anterior) || !(numero(faixa.motoboy) >= 0) || !(numero(faixa.loja) >= 0)) {
            return false;
        }
        anterior = ate;
    }

    return true;
}

/** A lista com uma faixa nova no fim: 1 km depois da última, com os valores dela. */
export function comNovaFaixa(faixas) {
    const ultima = (faixas ?? []).at(-1);
    const proximoAte = ultima ? (numero(ultima.ate_km) || 0) + 1 : 1;

    return [...(faixas ?? []), { ate_km: String(proximoAte), motoboy: ultima?.motoboy ?? '', loja: ultima?.loja ?? '' }];
}

export function faixasParaEnviar(faixas) {
    return (faixas ?? []).map((faixa) => ({ ate_km: numero(faixa.ate_km), motoboy: numero(faixa.motoboy), loja: numero(faixa.loja) }));
}

/* ---------------------------------------------------------------- fator e datas */

/** Fator digitado ("1,2", "1.5", 2) de 1 a 5 com até duas casas, ou NaN. */
export function fatorDigitado(valor) {
    const fator = numeroDigitado(valor);

    return Number.isFinite(fator) && fator >= FATOR_MINIMO && fator <= FATOR_MAXIMO ? fator : NaN;
}

/** "×1,2" no idioma do console; vazio sem número. */
export function textoDoFator(fator, locale = 'pt-BR') {
    if (fator === null || fator === undefined || fator === '') {
        return '';
    }
    const valor = Number(fator);

    return Number.isFinite(valor) ? `×${new Intl.NumberFormat(locale, { maximumFractionDigits: 2 }).format(valor)}` : '';
}

/** Campo datetime-local ('YYYY-MM-DDTHH:MM') a partir do ISO do servidor, com a hora de Brasília escrita nele. */
export function campoDoInstante(iso) {
    const partes = /^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/.exec(String(iso ?? ''));

    return partes ? `${partes[1]}T${partes[2]}` : '';
}

/** Campo datetime-local de um Date local (o relógio de quem usa o console). */
export function campoDeData(data) {
    return `${data.getFullYear()}-${pad(data.getMonth() + 1)}-${pad(data.getDate())}T${pad(data.getHours())}:${pad(data.getMinutes())}`;
}

/** O campo + N horas (o "Começar agora" sugere o fim em 3 h); campo inválido: vazio. */
export function somarHoras(campo, horas) {
    const partes = CAMPO.exec(String(campo ?? ''));
    if (!partes) {
        return '';
    }
    const data = new Date(Number(partes[1]), Number(partes[2]) - 1, Number(partes[3]), Number(partes[4]), Number(partes[5]));
    data.setHours(data.getHours() + horas);

    return campoDeData(data);
}

/* ---------------------------------------------------------------- formulário do período */

/**
 * Erros do formulário (códigos de fleet-ops.ui.financeiro.multiplicadores.erros.*), com as regras do servidor
 * (Multiplicadores::conferir). `soFim`: período em andamento, em que só o fim muda. `agora`: campo 'YYYY-MM-DDTHH:MM'.
 */
export function validarPeriodo({ nome, multMotoboy, multLoja, inicio, fim, soFim = false } = {}, agora) {
    const erros = [];
    if (!soFim) {
        const texto = String(nome ?? '').trim();
        if (!texto) {
            erros.push('nome');
        } else if (texto.length > MAX_NOME) {
            erros.push('nome-longo');
        }
        if (Number.isNaN(fatorDigitado(multMotoboy)) || Number.isNaN(fatorDigitado(multLoja))) {
            erros.push('fator');
        }
    }

    if (!CAMPO.test(String(inicio ?? '')) || !CAMPO.test(String(fim ?? ''))) {
        erros.push('datas');
        return erros;
    }

    // o servidor troca o início no passado por agora ("Começar agora"); em andamento, o início não muda
    const comeco = !soFim && inicio < agora ? agora : inicio;
    if (fim <= comeco || fim <= agora) {
        erros.push('fim');
    } else if (fim > somarHoras(comeco, MAX_DIAS * 24)) {
        erros.push('duracao');
    }

    return erros;
}

/** O corpo do POST/PUT: fatores em texto com duas casas ("1.20"), datas como o campo. Em andamento, só o fim. */
export function corpoDoPeriodo({ nome, multMotoboy, multLoja, inicio, fim, soFim = false } = {}) {
    if (soFim) {
        return { fim };
    }

    return { nome: String(nome ?? '').trim(), mult_motoboy: fatorDigitado(multMotoboy).toFixed(2), mult_loja: fatorDigitado(multLoja).toFixed(2), inicio, fim };
}

/* ---------------------------------------------------------------- lista */

export function chaveDaSituacao(situacao) {
    return `${PREFIXO}.situacao.${SITUACOES.includes(situacao) ? situacao : 'encerrado'}`;
}

const CORES = {
    em_andamento: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-100',
    agendado: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-100',
    encerrado: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
};

export function corDaSituacao(situacao) {
    return CORES[situacao] ?? CORES.encerrado;
}

/** O período em andamento, os agendados (o próximo primeiro) e os encerrados (como vieram: o mais recente primeiro). */
export function separarPeriodos(lista) {
    const periodos = Array.from(lista ?? []);

    return {
        emAndamento: periodos.find((p) => p.situacao === 'em_andamento') ?? null,
        agendados: periodos.filter((p) => p.situacao === 'agendado').sort((a, b) => String(a.inicio).localeCompare(String(b.inicio))),
        encerrados: periodos.filter((p) => p.situacao === 'encerrado'),
    };
}

/* ---------------------------------------------------------------- selos */

/**
 * Selos de uma corrida no extrato e na fatura (os `detalhes` do lançamento: `junto` = posição, `multiplicador` =
 * {nome, fator} da própria conta): [{chave, params}] em fleet-ops.ui.financeiro.selos.*.
 */
export function selosDaCorrida(detalhes, locale = 'pt-BR') {
    const selos = [];
    if (Number(detalhes?.junto) >= 2) {
        selos.push({ chave: 'junto', params: { posicao: Number(detalhes.junto) } });
    }
    const multiplicador = detalhes?.multiplicador;
    if (multiplicador && textoDoFator(multiplicador.fator, locale)) {
        selos.push({ chave: 'multiplicador', params: { nome: multiplicador.nome || '', fator: textoDoFator(multiplicador.fator, locale) } });
    }

    return selos;
}

/** Selos da linha do relatório (só admin): a tabela usada e o multiplicador com os dois fatores. */
export function selosDoRelatorio(entrega, locale = 'pt-BR') {
    const selos = [];
    if (entrega?.tabela === 'junto' && Number(entrega.posicao) >= 2) {
        selos.push({ chave: 'junto', params: { posicao: Number(entrega.posicao) } });
    }
    const multiplicador = entrega?.multiplicador;
    if (multiplicador) {
        selos.push({ chave: 'multiplicador-relatorio', params: { nome: multiplicador.nome || '', motoboy: textoDoFator(multiplicador.motoboy, locale), loja: textoDoFator(multiplicador.loja, locale) } });
    }

    return selos;
}
