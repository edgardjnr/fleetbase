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

/** 'Y-m-d' do servidor → Date local à meia-noite (new Date('Y-m-d') seria UTC e cairia no dia anterior); null se inválido. */
export function dataDoDia(dia) {
    const partes = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(dia ?? ''));
    return partes ? new Date(Number(partes[1]), Number(partes[2]) - 1, Number(partes[3])) : null;
}

/** Formatadores de exibição no idioma do console: valor (R$), dia, diaCurto, diaComSemana (dias 'Y-m-d') e km. */
export function formatadores(idioma) {
    const locale = idioma || 'pt-BR';
    const moeda = new Intl.NumberFormat(locale, { style: 'currency', currency: 'BRL' });
    const doisDecimais = new Intl.NumberFormat(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const dia = new Intl.DateTimeFormat(locale, { day: '2-digit', month: '2-digit', year: 'numeric' });
    const diaCurto = new Intl.DateTimeFormat(locale, { day: '2-digit', month: '2-digit' });
    const diaComSemana = new Intl.DateTimeFormat(locale, { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' });
    const comData = (formato) => (valor) => {
        const data = dataDoDia(valor);
        return data ? formato.format(data) : '';
    };

    return {
        valor: (valor) => moeda.format(Number(valor) || 0),
        dia: comData(dia),
        diaCurto: comData(diaCurto),
        diaComSemana: comData(diaComSemana),
        km: (km) => (km === null || km === undefined || km === '' ? '' : doisDecimais.format(Number(km) || 0)),
    };
}

// formas aceitas do valor em reais (sem o sinal): milhar com ponto ("1.500", "1.234,56"), vírgula decimal ("30", "30,5")
// ou ponto decimal ("12.5", "12.50"). Ponto seguido de 3 dígitos é sempre milhar ("1.500" = mil e quinhentos, nunca 1,5)
const MILHAR_COM_PONTO = /^[1-9]\d{0,2}(\.\d{3})+(,\d{1,2})?$/;
const VIRGULA_DECIMAL = /^\d+(,\d{1,2})?$/;
const PONTO_DECIMAL = /^\d+\.\d{1,2}$/;

/**
 * Número digitado em reais: "30", "30,5", "-2,75", "1.500" (mil e quinhentos), "1.234,56" ou "12.5". Vazio, texto
 * inválido ou mais de 2 casas decimais ("1,505") vira NaN (Number('') seria 0).
 */
export function numeroDigitado(valor) {
    if (typeof valor === 'number') {
        return Number.isFinite(valor) ? valor : NaN;
    }
    const partes = /^([+-]?)(.+)$/.exec(String(valor ?? '').replace(/\s/g, ''));
    if (!partes) {
        return NaN;
    }
    const [, sinal, corpo] = partes;
    let normal;
    if (MILHAR_COM_PONTO.test(corpo)) {
        normal = corpo.replace(/\./g, '').replace(',', '.');
    } else if (VIRGULA_DECIMAL.test(corpo)) {
        normal = corpo.replace(',', '.');
    } else if (PONTO_DECIMAL.test(corpo)) {
        normal = corpo;
    } else {
        return NaN;
    }

    return Number(`${sinal}${normal}`);
}

const centavos = (valor) => Math.round(valor * 100) / 100;

/**
 * Valor para mandar ao servidor: texto com ponto decimal e 2 casas ("1500.00", "-2.50"), nunca o que foi digitado (o
 * servidor recusa "1.500" sem vírgula). null se o digitado não é número.
 */
export function valorParaEnviar(valor) {
    const numero = numeroDigitado(valor);
    return Number.isFinite(numero) ? (centavos(numero) || 0).toFixed(2) : null;
}

/** Valor interpretado ao lado do campo ("R$ 1.500,00"); vazio enquanto o campo está vazio ou inválido. `f` = formatadores(idioma). */
export function valorInterpretado(valor, f) {
    const numero = numeroDigitado(valor);
    return Number.isFinite(numero) ? f.valor(centavos(numero)) : '';
}

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

/** Tipos de chave Pix (Financeiro::TIPOS_CHAVE_PIX) e formas de pagamento (Financeiro::FORMAS). */
export const TIPOS_CHAVE_PIX = ['cpf', 'cnpj', 'telefone', 'email', 'aleatoria'];
export const FORMAS_DE_PAGAMENTO = ['pix', 'dinheiro', 'transferencia', 'outro'];

/**
 * Conferência da chave Pix antes de salvar: null se está boa, ou o código do erro (fleet-ops.ui.financeiro.erros.*):
 * pix-tipo, pix-chave-vazia, pix-chave-invalida. CPF/CNPJ/telefone aceitam pontuação (contam só os dígitos).
 */
export function validarPix(tipo, chave) {
    if (!TIPOS_CHAVE_PIX.includes(tipo)) {
        return 'pix-tipo';
    }
    const texto = String(chave ?? '').trim();
    if (!texto) {
        return 'pix-chave-vazia';
    }
    const digitos = texto.replace(/[\s.\-/()+]/g, '');
    const valida = {
        cpf: () => /^\d{11}$/.test(digitos),
        cnpj: () => /^\d{14}$/.test(digitos),
        telefone: () => /^\d{10,13}$/.test(digitos),
        email: () => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(texto),
        aleatoria: () => /^[0-9a-f-]{32,36}$/i.test(texto),
    }[tipo];

    return valida() ? null : 'pix-chave-invalida';
}

export const LIMITE_DO_COMPROVANTE = 10 * 1024 * 1024;

/** Comprovante opcional: imagem ou PDF até 10 MB. null se está bom (ou não há arquivo); senão comprovante-tipo ou comprovante-grande. */
export function validarComprovante(arquivo) {
    if (!arquivo) {
        return null;
    }
    const tipo = String(arquivo.type ?? '');
    if (!tipo.startsWith('image/') && tipo !== 'application/pdf') {
        return 'comprovante-tipo';
    }

    return Number(arquivo.size) > LIMITE_DO_COMPROVANTE ? 'comprovante-grande' : null;
}

/**
 * Campos do multipart de POST financeiro/pagamentos, como pares [nome, valor] para o FormData (o arquivo vai à parte, no
 * campo "comprovante"). conta = public_id do motoboy (driver_…) ou da loja (vendor_…); valor com ponto e 2 casas.
 */
export function camposDoPagamento({ contaTipo, contaId, valor, forma, data, observacao }) {
    const campos = [
        ['conta_tipo', contaTipo === 'loja' ? 'loja' : 'motoboy'],
        ['conta', String(contaId ?? '')],
        ['valor', valorParaEnviar(valor) ?? ''],
        ['forma', FORMAS_DE_PAGAMENTO.includes(forma) ? forma : 'pix'],
        ['data', String(data ?? '')],
    ];
    const nota = String(observacao ?? '').trim();
    if (nota) {
        campos.push(['observacao_interna', nota]);
    }

    return campos;
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

export const CICLOS = ['semanal', 'quinzenal', 'mensal'];

/**
 * Ciclo de cobrança (padrão da organização ou de uma loja) com as regras do servidor (CicloDeCobranca::validar): ciclo
 * semanal/quinzenal/mensal; dia 0..6 no semanal (0 = domingo), 0..28 no mensal (0 = último dia), ignorado no quinzenal;
 * prazo 0..60 dias. Devolve { config: { ciclo, dia_fechamento, prazo_dias } } com números, ou { erro } com o código
 * (fleet-ops.ui.financeiro.erros.*): ciclo-invalido, dia-invalido, prazo-invalido.
 */
export function validarCobranca({ ciclo, dia_fechamento, prazo_dias } = {}) {
    if (!CICLOS.includes(ciclo)) {
        return { erro: 'ciclo-invalido' };
    }
    const dia = ciclo === 'quinzenal' ? 0 : Number(dia_fechamento);
    const maximo = ciclo === 'semanal' ? 6 : 28;
    if (!Number.isInteger(dia) || dia < 0 || dia > maximo) {
        return { erro: 'dia-invalido' };
    }
    const prazo = String(prazo_dias ?? '').trim() === '' ? NaN : Number(prazo_dias);
    if (!Number.isInteger(prazo) || prazo < 0 || prazo > 60) {
        return { erro: 'prazo-invalido' };
    }

    return { config: { ciclo, dia_fechamento: dia, prazo_dias: prazo } };
}

/** Dia de fechamento ao trocar de ciclo: mantém o que ainda vale no ciclo novo; senão domingo (semanal) ou último dia (mensal). */
export function diaAoTrocarDeCiclo(ciclo, diaAtual) {
    const dia = Number(diaAtual);
    if (ciclo === 'semanal') {
        return Number.isInteger(dia) && dia >= 0 && dia <= 6 ? dia : 0;
    }
    if (ciclo === 'mensal') {
        return Number.isInteger(dia) && dia >= 0 && dia <= 28 ? dia : 0;
    }

    return 0;
}

const ROTA_DO_PEDIDO ='operations.orders.index.details';
const ROTA_DA_FATURA = 'financeiro.fatura';
const ATENCAO = {
    fatura_vencida: { icone: 'circle-exclamation', cor: 'text-red-600 dark:text-red-400', rota: ROTA_DA_FATURA },
    rascunho: { icone: 'file-pen', cor: 'text-yellow-600 dark:text-yellow-400', rota: ROTA_DA_FATURA },
    email_nao_enviado: { icone: 'envelope', cor: 'text-yellow-600 dark:text-yellow-400', rota: ROTA_DA_FATURA },
    sem_loja: { icone: 'store-slash', cor: 'text-gray-500 dark:text-gray-400', rota: ROTA_DO_PEDIDO },
    sem_valor: { icone: 'ruler', cor: 'text-gray-500 dark:text-gray-400', rota: ROTA_DO_PEDIDO },
};

/**
 * Item da lista "Atenção" do painel → { tipo, icone, cor, chave, params, rota, modelo } para o template (rota relativa ao
 * engine; fatura → financeiro.fatura com o uuid, pedido → detalhes do pedido com o public_id). Tipo desconhecido: null.
 * `f` = formatadores(idioma).
 */
export function itemDeAtencao(item, f) {
    const config = ATENCAO[item?.tipo];
    if (!config) {
        return null;
    }
    const ehPedido = config.rota === ROTA_DO_PEDIDO;
    // sem o id não há link (o LinkTo sem o modelo quebra a tela inteira): o item sai da lista
    const modelo = ehPedido ? item.pedido : item.fatura;
    if (!modelo) {
        return null;
    }
    const params = ehPedido
        ? { numero: item.numero || item.pedido }
        : { numero: item.numero ?? '', loja: item.loja ?? '', valor: f.valor(item.valor), vencimento: f.dia(item.vencimento) };

    return {
        tipo: item.tipo,
        icone: config.icone,
        cor: config.cor,
        chave: `${PREFIXO}.painel.atencao.${item.tipo}`,
        params,
        rota: config.rota,
        modelo,
    };
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
