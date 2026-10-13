/**
 * Entregas RestaurantePro: funções puras da tela "Lojas e faturas" do Financeiro (rotas financeiro.lojas,
 * financeiro.loja e financeiro.fatura). Os dados vêm de int/v1/entregas/financeiro/{lojas,faturas}
 * (FinanceiroLojasController, formatos "Fatura", "Lojas" e "Lancamento" do plano do Financeiro).
 * Sem imports: testado no Node (scripts/teste-portal/financeiro-faturas.test.mjs).
 *
 * As chaves devolvidas são relativas a `fleet-ops.ui.financeiro-lojas.` (o controller monta a chave inteira).
 */

const CICLOS = ['semanal', 'quinzenal', 'mensal'];

// selo da fatura: rascunho azul, cancelada riscada, vencida vermelha, paga verde, parcial amarela, aberta cinza
const CORES_DA_FATURA = {
    rascunho: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-100',
    cancelada: 'bg-gray-100 text-gray-500 line-through dark:bg-gray-800 dark:text-gray-400',
    vencida: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-100',
    paga: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-100',
    parcial: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-100',
    aberta: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200',
};

// selo da situação de um crédito no extrato (Quitacao::creditos): pago, parcial, aberto, anulado
const CORES_DA_SITUACAO = {
    pago: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-100',
    parcial: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-100',
    aberto: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200',
    anulado: 'bg-gray-100 text-gray-500 line-through dark:bg-gray-800 dark:text-gray-400',
};

const inteiro = (valor) => {
    const texto = String(valor ?? '').trim();
    return /^\d+$/.test(texto) ? Number(texto) : NaN;
};

const nomeDoCiclo = (ciclo) => (typeof ciclo === 'string' ? ciclo : ciclo?.ciclo);

/**
 * Situação exibida da fatura, nesta precedência: 'rascunho', 'cancelada', 'vencida', 'paga', 'parcial', 'aberta'.
 * `situacao` vem gravada (rascunho, emitida, cancelada); `pagamento` e `vencida` são calculados pelo servidor.
 */
export function rotuloDaFatura(fatura) {
    if (fatura?.situacao === 'rascunho') {
        return 'rascunho';
    }
    if (fatura?.situacao === 'cancelada') {
        return 'cancelada';
    }
    if (fatura?.vencida) {
        return 'vencida';
    }
    if (fatura?.pagamento === 'paga') {
        return 'paga';
    }
    if (fatura?.pagamento === 'parcial') {
        return 'parcial';
    }

    return 'aberta';
}

/** Classes do selo da fatura. */
export function corDaFatura(fatura) {
    return CORES_DA_FATURA[rotuloDaFatura(fatura)];
}

/** Classes do selo da situação de um lançamento ('pago', 'parcial', 'aberto', 'anulado'); vazio sem situação. */
export function corDaSituacao(situacao) {
    return CORES_DA_SITUACAO[situacao] ?? '';
}

/**
 * Filtra a lista de faturas. `filtro` é a situação ('todas', 'rascunho', 'aberta', 'vencida', 'paga', 'cancelada') ou
 * `{ situacao, loja }` (loja = vendor public_id). 'aberta' = emitida e ainda não paga (inclui parcial e vencida).
 */
export function filtrarFaturas(faturas, filtro) {
    const { situacao = 'todas', loja = null } = typeof filtro === 'string' ? { situacao: filtro } : filtro ?? {};

    return (faturas ?? []).filter((fatura) => {
        if (loja && fatura?.loja?.id !== loja) {
            return false;
        }

        const rotulo = rotuloDaFatura(fatura);
        switch (situacao) {
            case 'todas':
            case '':
            case null:
                return true;
            case 'aberta':
                return ['aberta', 'parcial', 'vencida'].includes(rotulo);
            default:
                return rotulo === situacao;
        }
    });
}

/**
 * Texto do ciclo de cobrança: `{ chave, params, chaveDoDia }`. No semanal, `chaveDoDia` é o dia da semana a traduzir e
 * passar como `params.dia` (ex.: 'dias-semana.0' = domingo). `params.prazo` = dias até o vencimento.
 */
export function textoDoCiclo(ciclo) {
    const nome = nomeDoCiclo(ciclo);
    if (!CICLOS.includes(nome)) {
        return { chave: 'ciclo-texto.desconhecido', params: {}, chaveDoDia: null };
    }

    const prazo = Number(ciclo.prazo_dias) || 0;
    const dia = Number(ciclo.dia_fechamento) || 0;

    if (nome === 'semanal') {
        return { chave: 'ciclo-texto.semanal', params: { prazo }, chaveDoDia: `dias-semana.${dia}` };
    }
    if (nome === 'quinzenal') {
        return { chave: 'ciclo-texto.quinzenal', params: { prazo }, chaveDoDia: null };
    }
    if (dia === 0) {
        return { chave: 'ciclo-texto.mensal-ultimo-dia', params: { prazo }, chaveDoDia: null };
    }

    return { chave: 'ciclo-texto.mensal', params: { prazo, dia }, chaveDoDia: null };
}

/**
 * Opções do dia de fechamento para o ciclo ('semanal' | 'quinzenal' | 'mensal' ou o objeto do ciclo):
 * `[{ valor, chave, params }]`. Semanal: 0 (domingo) a 6 (sábado); mensal: 0 = último dia, depois 1 a 28; quinzenal: nenhuma.
 */
export function opcoesDeDia(ciclo) {
    const nome = nomeDoCiclo(ciclo);
    if (nome === 'semanal') {
        return Array.from({ length: 7 }, (_, dia) => ({ valor: dia, chave: `dias-semana.${dia}`, params: {} }));
    }
    if (nome === 'mensal') {
        return [{ valor: 0, chave: 'ultimo-dia', params: {} }, ...Array.from({ length: 28 }, (_, i) => ({ valor: i + 1, chave: 'dia-do-mes', params: { dia: i + 1 } }))];
    }

    return [];
}

/**
 * Erros do ciclo, com as mesmas regras do servidor (CicloDeCobranca::validar): ciclo conhecido; dia 0..6 no semanal e
 * 0..28 no mensal; prazo inteiro de 0 a 60. Devolve as chaves ('ciclo-invalido', 'dia-invalido', 'prazo-invalido').
 */
export function validarCiclo({ ciclo, dia_fechamento, prazo_dias } = {}) {
    const erros = [];
    if (!CICLOS.includes(ciclo)) {
        erros.push('ciclo-invalido');
    } else if (ciclo !== 'quinzenal') {
        const dia = inteiro(dia_fechamento);
        const maximo = ciclo === 'semanal' ? 6 : 28;
        if (!(dia >= 0 && dia <= maximo)) {
            erros.push('dia-invalido');
        }
    }

    const prazo = inteiro(prazo_dias);
    if (!(prazo >= 0 && prazo <= 60)) {
        erros.push('prazo-invalido');
    }

    return erros;
}

/** Número da fatura ('FAT-0031') ou o rótulo do rascunho (que não tem número). */
export function numeroOuRascunho(fatura, rotuloRascunho = 'Rascunho') {
    return fatura?.numero || rotuloRascunho;
}

/**
 * Valor digitado em reais: aceita vírgula decimal ("12,50", "-8,5", "1.234,56") e ponto decimal ("12.5"). Inválido ou
 * vazio = NaN.
 */
export function lerValor(texto) {
    let digitado = String(texto ?? '').trim();
    if (digitado.includes(',')) {
        digitado = digitado.replace(/\./g, '').replace(',', '.');
    }

    return /^[+-]?\d+(\.\d+)?$/.test(digitado) ? Number(digitado) : NaN;
}

/** Erros do ajuste na fatura: valor diferente de zero (positivo ou negativo) e motivo (aparece para a loja). */
export function validarAjuste({ valor, motivo } = {}) {
    const erros = [];
    const numero = lerValor(valor);
    if (!Number.isFinite(numero) || numero === 0) {
        erros.push('valor-invalido');
    }
    if (!String(motivo ?? '').trim()) {
        erros.push('motivo-obrigatorio');
    }

    return erros;
}

/** Erros do "Gerar fatura": loja escolhida, as duas datas ('Y-m-d') e início até o fim. */
export function validarGerarFatura({ loja, inicio, fim } = {}) {
    if (!loja) {
        return ['loja-obrigatoria'];
    }
    if (!inicio || !fim) {
        return ['periodo-obrigatorio'];
    }
    if (inicio > fim) {
        return ['periodo-invertido'];
    }

    return [];
}

/** A corrida já tem o ajuste "Não cobrar" (ajuste negativo do mesmo pedido) entre os itens da fatura. */
export function jaNaoCobrado(item, itens) {
    const pedido = item?.pedido?.id;
    if (!pedido) {
        return false;
    }

    return (itens ?? []).some((outro) => outro?.tipo === 'ajuste' && Number(outro.valor) < 0 && outro.pedido?.id === pedido);
}

/** "Não cobrar" só em corrida com pedido, de fatura em rascunho, ainda não marcada. */
export function podeNaoCobrar(item, fatura, itens) {
    return fatura?.situacao === 'rascunho' && item?.tipo === 'corrida' && Boolean(item?.pedido?.id) && !jaNaoCobrado(item, itens);
}

/**
 * Estorno na conta da loja: recebimento (estorna o pagamento, se ainda não estornado) ou lançamento que não é estorno,
 * não foi estornado e ainda não entrou numa fatura (o faturado se corrige cancelando a fatura ou com ajuste no rascunho).
 */
export function podeEstornarNaLoja(lancamento) {
    if (!lancamento) {
        return false;
    }
    if (lancamento.tipo === 'recebimento') {
        return Boolean(lancamento.pagamento?.id) && !lancamento.pagamento.estornado;
    }

    return lancamento.tipo !== 'estorno' && !lancamento.estorno_de && !lancamento.estornado && !lancamento.fatura;
}

/** 'Y-m-d' (ou instante ISO) → 'dd/mm/aaaa', sem passar por Date (nada de fuso). Inválido = ''. */
export function dataBr(iso) {
    const partes = String(iso ?? '').match(/^(\d{4})-(\d{2})-(\d{2})/);
    return partes ? `${partes[3]}/${partes[2]}/${partes[1]}` : '';
}

const celula = (valor) => `"${String(valor ?? '').replace(/"/g, '""')}"`;
const decimal = (valor, casas = 2) => (Number(valor) || 0).toFixed(casas).replace('.', ',');

/**
 * Linhas do CSV da fatura (separador ";", vírgula decimal, texto entre aspas, como o relatório). O controller junta com
 * "\r\n" e põe o BOM. `rotulos` traz os textos já traduzidos (cabeçalhos, 'rascunho' e `tipos` para item sem motivo).
 */
export function linhasCsvDaFatura(fatura, itens, rotulos = {}) {
    const r = (chave) => rotulos[chave] ?? chave;
    const periodo = fatura?.periodo_inicio && fatura?.periodo_fim ? `${dataBr(fatura.periodo_inicio)} a ${dataBr(fatura.periodo_fim)}` : '';
    const linhas = [
        [celula(r('fatura')), celula(numeroOuRascunho(fatura, r('rascunho')))].join(';'),
        [celula(r('loja')), celula(fatura?.loja?.nome)].join(';'),
        [celula(r('periodo')), celula(periodo)].join(';'),
        [celula(r('vencimento')), celula(dataBr(fatura?.vencimento))].join(';'),
        '',
        [r('data'), r('pedido'), r('km'), r('destino'), r('motivo'), r('valor')].map(celula).join(';'),
    ];

    for (const item of itens ?? []) {
        const km = item?.detalhes?.km;
        linhas.push(
            [
                celula(dataBr(item?.data)),
                celula(item?.pedido ? item.pedido.numero || item.pedido.id : ''),
                km === null || km === undefined ? '' : decimal(km),
                celula(item?.detalhes?.destino),
                celula(item?.motivo || (item?.tipo === 'corrida' ? '' : rotulos.tipos?.[item?.tipo] ?? '')),
                decimal(item?.valor),
            ].join(';')
        );
    }

    linhas.push('');
    linhas.push([celula(r('total')), decimal(fatura?.total)].join(';'));
    linhas.push([celula(r('recebido')), decimal(fatura?.recebido)].join(';'));
    linhas.push([celula(r('emAberto')), decimal(fatura?.em_aberto)].join(';'));

    return linhas;
}
