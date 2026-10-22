/**
 * Entregas RestaurantePro: funções puras da tela Financeiro → Corridas do sistema (relatório do dono, todas as
 * organizações). Dados de int/v1/entregas/sistema/* (api/app/Http/Controllers/Entregas/CorridasDoSistemaController.php;
 * formato do relatório em api/app/Support/Entregas/Sistema/RelatorioDasCorridas.php::agregar).
 * Sem imports do Ember: testado no Node (scripts/teste-portal/corridas-do-sistema.test.mjs).
 * Spec: docs/superpowers/specs/2026-10-22-corridas-do-sistema-design.md.
 */
import { celulaCsv, formatadores, numeroDigitado, valorParaEnviar } from './financeiro';

const PREFIXO = 'fleet-ops.ui.financeiro.corridas-do-sistema';

/** Mesmo limite do servidor (PrecosDasCentrais::MAXIMO). */
export const PRECO_MAXIMO = 1000;

/** Mesma faixa de anos do servidor (PrecosDasCentrais::PRIMEIRO_ANO e ULTIMO_ANO). */
export const PRIMEIRO_ANO = 2020;
export const ULTIMO_ANO = 2100;

/** Origens gravadas pelo servidor (CorridasFinalizadas::CONCLUSAO, CONFERENCIA e HISTORICO). */
export const ORIGENS = ['conclusao', 'conferencia', 'historico'];

// textos do CSV quando não há tradução (o produto é pt-BR)
const TEXTOS_PADRAO = {
    central: 'Central',
    corridas: 'Corridas',
    preco: 'Preço',
    'a-cobrar': 'A cobrar',
    'csv-sem-preco': 'Sem preço',
    variado: 'variado',
    total: 'Total',
    'data-hora': 'Data e hora',
    pedido: 'Pedido',
    numero: 'Nº',
    loja: 'Loja',
    motoboy: 'Motoboy',
    origem: 'Origem',
    'origens.conclusao': 'Na conclusão',
    'origens.conferencia': 'Na conferência',
    'origens.historico': 'Histórico',
    'origens.outra': 'Outra',
    'lista-cortada': 'A lista mostra as primeiras {count} corridas; os totais contam todas. Diminua o período para ver o resto.',
};

const valorCsv = (valor) => (Number(valor) || 0).toFixed(2).replace('.', ',');

/** Preço da central no período: null (nenhuma corrida com preço), o valor (um só) ou 'variado' (mudou no período). */
export function precoDaCentral(precos) {
    const validos = (Array.isArray(precos) ? precos : []).filter((preco) => preco !== null && preco !== '' && Number.isFinite(Number(preco)));
    const centavos = [...new Set(validos.map((preco) => Math.round(Number(preco) * 100)))];
    if (!centavos.length) {
        return null;
    }

    return centavos.length === 1 ? centavos[0] / 100 : 'variado';
}

/** 'origens.<origem>' (desconhecida: 'origens.outra'). */
function origemCurta(origem) {
    return `origens.${ORIGENS.includes(origem) ? origem : 'outra'}`;
}

/** Chave i18n da origem da linha (conclusao, conferencia, historico). */
export function chaveDaOrigem(origem) {
    return `${PREFIXO}.${origemCurta(origem)}`;
}

/** "22/10/2026 10:05" a partir do ISO do servidor ('2026-10-22T10:05:00-03:00'), pela hora escrita (sem converter o fuso). */
export function dataHoraDoIso(iso) {
    const partes = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(String(iso ?? ''));
    return partes ? `${partes[3]}/${partes[2]}/${partes[1]} ${partes[4]}:${partes[5]}` : '';
}

/**
 * Linhas do CSV (sem o BOM; quem baixa junta com \r\n e põe o BOM na frente): por central (Central; Corridas; Preço;
 * A cobrar; Sem preço) com a linha do total, uma linha em branco e as corridas (Data e hora; Central; Pedido; Nº; Loja;
 * Motoboy; Preço; Origem). Valores com vírgula; preço "variado" ou vazio (sem preço). Com `lista_cortada`, uma linha
 * final com o aviso (o arquivo não parece completo). `t(chave, opcoes)` traduz (chaves
 * fleet-ops.ui.financeiro.corridas-do-sistema.*); sem ele, pt-BR.
 */
export function linhasCsvDasCorridas(relatorio, t = null) {
    const texto = (chave, opcoes = {}) =>
        typeof t === 'function'
            ? t(`${PREFIXO}.${chave}`, opcoes)
            : String(TEXTOS_PADRAO[chave] ?? chave).replace(/\{(\w+)\}/g, (inteiro, nome) => (nome in opcoes ? String(opcoes[nome]) : inteiro));
    const cabecalho = (chaves) => chaves.map((chave) => celulaCsv(texto(chave))).join(';');
    const linhas = [cabecalho(['central', 'corridas', 'preco', 'a-cobrar', 'csv-sem-preco'])];

    for (const central of relatorio?.centrais ?? []) {
        const preco = precoDaCentral(central.precos);
        const precoCsv = preco === 'variado' ? celulaCsv(texto('variado')) : preco === null ? '' : valorCsv(preco);
        linhas.push([celulaCsv(central.nome), Number(central.corridas) || 0, precoCsv, valorCsv(central.a_cobrar), Number(central.sem_preco) || 0].join(';'));
    }
    const total = relatorio?.total ?? {};
    linhas.push([celulaCsv(texto('total')), Number(total.corridas) || 0, '', valorCsv(total.a_cobrar), Number(total.sem_preco) || 0].join(';'));

    linhas.push('');
    linhas.push(cabecalho(['data-hora', 'central', 'pedido', 'numero', 'loja', 'motoboy', 'preco', 'origem']));
    for (const corrida of relatorio?.corridas ?? []) {
        linhas.push(
            [
                celulaCsv(dataHoraDoIso(corrida.finalizada_em)),
                celulaCsv(corrida.central),
                celulaCsv(corrida.pedido),
                celulaCsv(corrida.numero ?? ''),
                celulaCsv(corrida.loja_nome ?? ''),
                celulaCsv(corrida.motoboy_nome ?? ''),
                corrida.preco === null || corrida.preco === undefined ? '' : valorCsv(corrida.preco),
                celulaCsv(texto(origemCurta(corrida.origem))),
            ].join(';')
        );
    }
    if (relatorio?.lista_cortada === true) {
        linhas.push('');
        linhas.push(celulaCsv(texto('lista-cortada', { count: (relatorio?.corridas ?? []).length })));
    }

    return linhas;
}

/** Dinheiro do extrato: 'R$ 1.234,56' (sempre 2 casas; o espaço especial do Intl vira espaço comum). */
export function reais(valor, locale = 'pt-BR') {
    return formatadores(locale).valor(valor).replace(/\s/g, ' ');
}

/** Centavos de um preço (2.5 e '2.50' dão 250); null quando não há preço. */
function centavosDoPreco(preco) {
    if (preco === null || preco === undefined || preco === '' || !Number.isFinite(Number(preco))) {
        return null;
    }

    return Math.round(Number(preco) * 100);
}

/** 'dd/mm/aaaa' de um dia 'Y-m-d' ('' se não for). */
function diaDoExtrato(dia) {
    const partes = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(dia ?? ''));
    return partes ? `${partes[3]}/${partes[2]}/${partes[1]}` : '';
}

/**
 * Dados do extrato de uma central (PDF para o cliente; spec 2026-10-22-extrato-da-central-design.md): resumo da linha
 * da central em relatorio.centrais (total completo, mesmo com a lista cortada), preços distintos das corridas com preço
 * (em ordem crescente, com a quantidade) e as linhas das corridas da central na ordem recebida. Data e hora pela hora
 * escrita no ISO (Brasília), sem converter fuso. Central que não está no relatório: null.
 */
export function extratoDaCentral(relatorio, company) {
    const linhaDaCentral = (relatorio?.centrais ?? []).find((central) => central?.company === company);
    if (!linhaDaCentral) {
        return null;
    }
    const corridas = (relatorio?.corridas ?? []).filter((corrida) => corrida?.company === company);

    const porPreco = new Map();
    const linhas = corridas.map((corrida) => {
        const centavos = centavosDoPreco(corrida.preco);
        if (centavos !== null) {
            porPreco.set(centavos, (porPreco.get(centavos) ?? 0) + 1);
        }
        const [data = '', hora = ''] = dataHoraDoIso(corrida.finalizada_em).split(' ');

        return {
            data,
            hora,
            numero: String(corrida.numero || corrida.pedido || ''),
            loja: corrida.loja_nome ?? '',
            motoboy: corrida.motoboy_nome ?? '',
            valor: centavos === null ? null : centavos / 100,
        };
    });
    const precos = [...porPreco.entries()].sort(([a], [b]) => a - b).map(([centavos, quantidade]) => ({ valor: centavos / 100, quantidade }));

    return {
        central: linhaDaCentral.nome || corridas.find((corrida) => corrida.central)?.central || '',
        inicio: diaDoExtrato(relatorio?.inicio),
        fim: diaDoExtrato(relatorio?.fim),
        corridas: Number(linhaDaCentral.corridas) || 0,
        total: Math.round((Number(linhaDaCentral.a_cobrar) || 0) * 100) / 100,
        semPreco: Number(linhaDaCentral.sem_preco) || 0,
        listaCortada: relatorio?.lista_cortada === true,
        precoVariou: precos.length > 1,
        precos,
        linhas,
    };
}

/** Nome do arquivo do CSV. */
export function nomeDoCsv(inicio, fim) {
    return `corridas-do-sistema_${inicio}_${fim}.csv`;
}

/** 'Y-m-d' de um dia que existe, de PRIMEIRO_ANO a ULTIMO_ANO (como PrecosDasCentrais::data). */
function dataValida(data) {
    const partes = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(data ?? ''));
    if (!partes) {
        return false;
    }
    const [ano, mes, dia] = [Number(partes[1]), Number(partes[2]), Number(partes[3])];
    if (ano < PRIMEIRO_ANO || ano > ULTIMO_ANO) {
        return false;
    }
    const local = new Date(ano, mes - 1, dia);

    return local.getFullYear() === ano && local.getMonth() === mes - 1 && local.getDate() === dia;
}

/** Erros do formulário do preço (chaves erros.<x>): central, valor (0 a 1000, até 2 casas) e data ('Y-m-d' que existe). */
export function validarPreco({ company, valor, data } = {}) {
    const erros = [];
    if (!company) {
        erros.push('central');
    }
    const numero = numeroDigitado(valor);
    if (!Number.isFinite(numero) || numero < 0 || numero > PRECO_MAXIMO) {
        erros.push('valor');
    }
    if (!dataValida(data)) {
        erros.push('data');
    }

    return erros;
}

/** Corpo do POST sistema/precos; o valor vai sempre como "12.50" (valorParaEnviar), que o servidor aceita. */
export function corpoDoPreco({ company, valor, data } = {}) {
    return { company, valor: valorParaEnviar(valor), vale_a_partir_de: data };
}
