/**
 * Entregas RestaurantePro: funções puras do Financeiro no console (painel, motoboys, extrato, modais e configurações).
 * Os dados vêm de int/v1/entregas/financeiro/* (api/app/Http/Controllers/Entregas/FinanceiroController.php), nos
 * formatos do contrato (docs/superpowers/plans/2026-10-12-financeiro.md, seção 1.5).
 * Sem imports: testado no Node (scripts/teste-portal/financeiro.test.mjs).
 */

const PREFIXO = 'fleet-ops.ui.financeiro';

const TIPOS = ['corrida', 'diaria', 'bonus', 'ajuste', 'saldo_inicial', 'pagamento', 'recebimento', 'estorno'];

// mesmos tipos manuais do servidor (Financeiro::TIPOS_MANUAIS_*)
const TIPOS_MANUAIS = {
    motoboy: ['diaria', 'bonus', 'ajuste', 'saldo_inicial'],
    loja: ['ajuste', 'saldo_inicial'],
};
const ACEITAM_NEGATIVO = ['ajuste', 'saldo_inicial'];
const MAXIMO_DE_CONTAS = 100;

// textos do CSV quando não há tradução (o produto é pt-BR)
const TEXTOS_PADRAO = {
    'csv.data': 'Data',
    'csv.tipo': 'Tipo',
    'csv.pedido': 'Pedido',
    'csv.motivo': 'Motivo',
    'csv.valor': 'Valor',
    'csv.situacao': 'Situação',
    'tipos.corrida': 'Corrida',
    'tipos.diaria': 'Diária',
    'tipos.bonus': 'Bônus',
    'tipos.ajuste': 'Ajuste',
    'tipos.saldo_inicial': 'Saldo inicial',
    'tipos.pagamento': 'Pagamento',
    'tipos.recebimento': 'Recebimento',
    'tipos.estorno': 'Estorno',
    'tipos.desconhecido': 'Outro',
    'situacao.pago': 'Pago',
    'situacao.parcial': 'Pago em parte',
    'situacao.aberto': 'A receber',
    'situacao.anulado': 'Anulado',
};

const CORES = {
    pago: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-100',
    parcial: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-100',
    aberto: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200',
    anulado: 'bg-gray-100 text-gray-500 line-through dark:bg-gray-800 dark:text-gray-400',
    vencida: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-100',
};

const pad = (n) => String(n).padStart(2, '0');

/** Data local 'Y-m-d' (nunca toISOString, que muda o dia depois das 21h em Brasília). */
export function isoDate(data) {
    return `${data.getFullYear()}-${pad(data.getMonth() + 1)}-${pad(data.getDate())}`;
}

/**
 * Número digitado: "30", "30,5", "-2,75", "1.234,56" ou "12.5". Vazio ou texto inválido vira NaN (Number('') seria 0).
 */
export function numeroDigitado(valor) {
    if (typeof valor === 'number') {
        return valor;
    }
    let texto = String(valor ?? '')
        .trim()
        .replace(/\s/g, '');
    if (texto.includes(',')) {
        // vírgula decimal: os pontos são separador de milhar
        texto = texto.replace(/\./g, '').replace(',', '.');
    }

    return /^[+-]?\d+(\.\d+)?$/.test(texto) ? Number(texto) : NaN;
}

const centavos = (valor) => Math.round(valor * 100) / 100;

/** Chave i18n do tipo de lançamento (fleet-ops.ui.financeiro.tipos.<tipo>). */
export function rotuloDoTipo(tipo) {
    return `${PREFIXO}.tipos.${TIPOS.includes(tipo) ? tipo : 'desconhecido'}`;
}

/** Chave i18n da situação de um crédito, ou null (débitos não têm situação). */
export function rotuloDaSituacao(situacao) {
    return ['pago', 'parcial', 'aberto', 'anulado'].includes(situacao) ? `${PREFIXO}.situacao.${situacao}` : null;
}

/** Classes do selo da situação: pago verde, parcial amarelo, aberto cinza, anulado cinza riscado, vencida vermelho. */
export function corDaSituacao(situacao) {
    return CORES[situacao] ?? CORES.aberto;
}

/** [{ data, lancamentos }] na ordem em que os dias aparecem (a lista já vem do mais novo para o mais antigo). */
export function agruparPorDia(lancamentos) {
    const grupos = [];
    const porData = new Map();
    for (const lancamento of lancamentos ?? []) {
        let grupo = porData.get(lancamento.data);
        if (!grupo) {
            grupo = { data: lancamento.data, lancamentos: [] };
            porData.set(lancamento.data, grupo);
            grupos.push(grupo);
        }
        grupo.lancamentos.push(lancamento);
    }

    return grupos;
}

/**
 * Se a linha do extrato mostra "Estornar": não é estorno, não foi estornada e não está numa fatura emitida (na conta da
 * loja, a correção é cancelar a fatura). Pagamento e recebimento estornam pelo registro do pagamento (pagamento.id).
 */
export function podeEstornar(lancamento) {
    if (!lancamento || lancamento.tipo === 'estorno' || lancamento.estornado) {
        return false;
    }
    if (lancamento.fatura?.numero) {
        return false;
    }
    if (lancamento.tipo === 'pagamento' || lancamento.tipo === 'recebimento') {
        return Boolean(lancamento.pagamento?.id) && !lancamento.pagamento.estornado;
    }

    return true;
}

/** Tipos que o admin lança à mão na conta (motoboy: diária, bônus, ajuste, saldo inicial; loja: ajuste, saldo inicial). */
export function tiposDaConta(contaTipo) {
    return [...(TIPOS_MANUAIS[contaTipo] ?? [])];
}

/** Ajuste e saldo inicial aceitam valor negativo; diária e bônus, não. */
export function aceitaNegativo(tipo) {
    return ACEITAM_NEGATIVO.includes(tipo);
}

/**
 * Erros do formulário "Lançar" (as mesmas regras do servidor), como códigos de fleet-ops.ui.financeiro.erros.*:
 * tipo-invalido, valor-invalido, valor-zero, valor-negativo, pedido-so-em-ajuste, sem-conta, contas-demais.
 */
export function validarLancamento({ contaTipo = 'motoboy', tipo, valor, contas, pedido } = {}) {
    const erros = [];
    if (!tiposDaConta(contaTipo).includes(tipo)) {
        erros.push('tipo-invalido');
    }

    const numero = numeroDigitado(valor);
    if (Number.isNaN(numero)) {
        erros.push('valor-invalido');
    } else if (centavos(numero) === 0) {
        erros.push('valor-zero');
    } else if (numero < 0 && !aceitaNegativo(tipo)) {
        erros.push('valor-negativo');
    }

    if (String(pedido ?? '').trim() && tipo !== 'ajuste') {
        erros.push('pedido-so-em-ajuste');
    }

    const quantidade = Array.isArray(contas) ? contas.length : 0;
    if (quantidade === 0) {
        erros.push('sem-conta');
    } else if (quantidade > MAXIMO_DE_CONTAS) {
        erros.push('contas-demais');
    }

    return erros;
}

/** Quanto do pagamento passa do saldo (vira adiantamento); 0 quando cabe no saldo ou o valor é inválido. */
export function avisoDeAdiantamento(valor, saldo) {
    const pago = numeroDigitado(valor);
    if (!(pago > 0)) {
        return 0;
    }
    const excedente = centavos(pago - Math.max(Number(saldo) || 0, 0));

    return excedente > 0 ? excedente : 0;
}

/** Atalhos de período ('this-month', 'last-month', 'last-7-days') a partir do dia local de hoje; null se desconhecido. */
export function periodoDoAtalho(atalho, hoje = new Date()) {
    const ano = hoje.getFullYear();
    const mes = hoje.getMonth();
    if (atalho === 'this-month') {
        return { inicio: isoDate(new Date(ano, mes, 1)), fim: isoDate(hoje) };
    }
    if (atalho === 'last-month') {
        return { inicio: isoDate(new Date(ano, mes - 1, 1)), fim: isoDate(new Date(ano, mes, 0)) };
    }
    if (atalho === 'last-7-days') {
        return { inicio: isoDate(new Date(ano, mes, hoje.getDate() - 6)), fim: isoDate(hoje) };
    }

    return null;
}

/**
 * Opções do "dia de fechamento" do ciclo padrão: semanal = dias da semana (0 = domingo); mensal = 1..28 e 0 = último dia;
 * quinzenal = nenhuma (fecha em 15 e no último dia). Cada opção: { valor, chave, params } para o intl.
 */
export function diasDeFechamento(ciclo) {
    if (ciclo === 'semanal') {
        return [0, 1, 2, 3, 4, 5, 6].map((dia) => ({ valor: dia, chave: `${PREFIXO}.dias-da-semana.${dia}`, params: {} }));
    }
    if (ciclo === 'mensal') {
        const dias = Array.from({ length: 28 }, (_, i) => ({ valor: i + 1, chave: `${PREFIXO}.configuracoes.dia-do-mes`, params: { dia: i + 1 } }));
        return [...dias, { valor: 0, chave: `${PREFIXO}.configuracoes.ultimo-dia`, params: {} }];
    }

    return [];
}

const dataBr = (data) => {
    const partes = String(data ?? '').split('-');
    return partes.length === 3 ? `${partes[2]}/${partes[1]}/${partes[0]}` : String(data ?? '');
};
const celula = (valor) => `"${String(valor ?? '').replace(/"/g, '""')}"`;
const valorCsv = (valor) => (Number(valor) || 0).toFixed(2).replace('.', ',');

/**
 * Linhas do CSV do extrato (sem o BOM; quem baixa junta com \r\n): cabeçalho Data;Tipo;Pedido;Motivo;Valor;Situação e
 * um lançamento por linha, na ordem do extrato. `t(chave)` traduz (chaves fleet-ops.ui.financeiro.*); sem ele, pt-BR.
 */
export function linhasCsvDoExtrato(extrato, t = null) {
    const texto = (chave) => (typeof t === 'function' ? t(`${PREFIXO}.${chave}`) : (TEXTOS_PADRAO[chave] ?? chave));
    const linhas = [['data', 'tipo', 'pedido', 'motivo', 'valor', 'situacao'].map((coluna) => celula(texto(`csv.${coluna}`))).join(';')];

    for (const lancamento of extrato?.lancamentos ?? []) {
        const situacao = rotuloDaSituacao(lancamento.situacao);
        linhas.push(
            [
                celula(dataBr(lancamento.data)),
                celula(texto(rotuloDoTipo(lancamento.tipo).slice(PREFIXO.length + 1))),
                celula(lancamento.pedido ? lancamento.pedido.numero || lancamento.pedido.id : ''),
                celula(lancamento.motivo),
                valorCsv(lancamento.valor),
                celula(situacao ? texto(situacao.slice(PREFIXO.length + 1)) : ''),
            ].join(';')
        );
    }

    return linhas;
}
