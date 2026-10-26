/**
 * Entregas RestaurantePro: funções puras do saque Pix do motoboy no console (card "Saque Pix (Asaas)" das Configurações,
 * tela Saques Pix, extrato e lista de motoboys). Os dados vêm de int/v1/entregas/financeiro/saque-pix, …/saques e do
 * extrato (api/app/Http/Controllers/Entregas/SaquePixController.php e FinanceiroController.php; formatos em
 * docs/superpowers/plans/2026-10-26-saque-pix-asaas-api.md). Testado no Node
 * (scripts/teste-portal/financeiro-saque-pix.test.mjs).
 */
import { TIPOS_CHAVE_PIX, numeroDigitado, quandoDoIso, valorParaEnviar } from './financeiro';

const PREFIXO = 'fleet-ops.ui.financeiro.saque-pix';

/** Rota da API (namespace int/v1 do fetch): GET/PUT; + /conexao, /token, /teste. */
export const API_DO_SAQUE_PIX = 'entregas/financeiro/saque-pix';

export const SITUACOES_DO_SAQUE = ['enviando', 'processando', 'concluido', 'falhou'];

/** O teste da validação espera o Asaas chamar o Entregas por até 60 s, relendo a conexão a cada 3 s. */
export const ESPERA_DO_TESTE_MS = 60000;
export const INTERVALO_DO_TESTE_MS = 3000;

/** O nome gravado do papel de administrador da organização (o mesmo do AdminDaCentral no servidor). */
export const PAPEL_ADMIN_DA_CENTRAL = 'Administrator';

// telas do Financeiro abertas ao admin da organização (o resto segue só com o admin do sistema, como a API)
const ROTAS_DO_ADMIN_DA_CENTRAL = ['financeiro', 'financeiro.index', 'financeiro.saques', 'financeiro.configuracoes'];

const STATUS_DO_SELO = { enviando: 'warning', processando: 'warning', concluido: 'success', falhou: 'error' };

const PADROES = { minimo: 10, maximo_dia: 500, saques_dia: 3, taxa: 0 };

/** O maior valor que o servidor aceita nos limites (ConexaoAsaas::MAIOR_VALOR, R$ 9.999.999,99), em centavos. */
const MAIOR_EM_CENTAVOS = 999999999;

/** O menor "mínimo por saque" (ConexaoAsaas::MINIMO_POR_SAQUE, R$ 2,00): acima do R$ 1,00 do teste da validação. */
export const MINIMO_POR_SAQUE = 2;

const centavos = (valor) => Math.round(valor * 100);
const numeroValido = (valor) => Number.isFinite(valor) && centavos(valor) <= MAIOR_EM_CENTAVOS;
const reaisParaEdicao = (valor) => (Number(valor) || 0).toFixed(2).replace('.', ',');

/** Admin do sistema (`isAdmin`) ou papel Administrator na organização da sessão (`roleName`): configura o saque Pix. */
export function ehAdminDaCentral(usuario) {
    return usuario?.isAdmin === true || usuario?.roleName === PAPEL_ADMIN_DA_CENTRAL;
}

/** A rota (nome curto ou com o prefixo do console) é uma das telas do Financeiro abertas ao admin da organização. */
export function rotaDoAdminDaCentral(nome) {
    const rota = String(nome ?? '');

    return rota !== '' && ROTAS_DO_ADMIN_DA_CENTRAL.some((liberada) => rota === liberada || rota.endsWith(`.${liberada}`));
}

/** Em que pé está o card (conexão no formato ConexaoAsaas::publico; null = ainda não carregou). */
export function etapasDoSaque(conexao) {
    const conectada = conexao?.conectada === true;
    const tokenGerado = conectada && conexao.token_gerado === true;
    const validada = conectada && Boolean(conexao.validacao_confirmada_em);

    return {
        conectada,
        tokenGerado,
        validada,
        ligado: validada && conexao.saque_ligado === true,
        podeConectar: conexao?.cofre !== false,
        podeTestar: tokenGerado,
        podeLigar: validada,
    };
}

/**
 * Resultado do teste da validação: 'confirmada' (validacao_confirmada_em preenchido), 'esperando' (até 60 s depois de
 * pedir), 'sem-resposta' (passou de 60 s) ou null (nenhum teste pedido nesta tela e nada confirmado).
 */
export function resultadoDoTeste(conexao, comecouEm, agora) {
    if (conexao?.validacao_confirmada_em) {
        return 'confirmada';
    }
    if (!comecouEm) {
        return null;
    }

    return agora - comecouEm >= ESPERA_DO_TESTE_MS ? 'sem-resposta' : 'esperando';
}

/** Segundos que faltam da espera do teste (0 depois de 60 s). */
export function segundosRestantes(comecouEm, agora) {
    return Math.max(0, Math.ceil((ESPERA_DO_TESTE_MS - (agora - comecouEm)) / 1000));
}

/** Limites do servidor → campos do formulário ("10,00"; saques por dia em texto). Sem limites, os padrões. */
export function limitesParaEdicao(limites) {
    const atuais = { ...PADROES, ...(limites ?? {}) };

    return {
        minimo: reaisParaEdicao(atuais.minimo),
        maximo_dia: reaisParaEdicao(atuais.maximo_dia),
        saques_dia: String(atuais.saques_dia),
        taxa: reaisParaEdicao(atuais.taxa),
    };
}

/**
 * As regras do servidor (ConexaoAsaas::configurar): valores até R$ 9.999.999,99; mínimo ≥ R$ 2,00; máximo do dia ≥
 * mínimo; saques por dia de 1 a 20; taxa de R$ 0 a R$ 10 e menor que o mínimo. → { erro: código (chave em saque-pix.erros) | null, corpo: o PUT | null }.
 */
export function validarLimites({ minimo, maximo_dia, saques_dia, taxa } = {}) {
    const min = numeroDigitado(minimo);
    const max = numeroDigitado(maximo_dia);
    const tx = numeroDigitado(taxa);
    const texto = String(saques_dia ?? '').trim();
    const quantos = /^\d{1,2}$/.test(texto) ? Number(texto) : NaN;
    const falha = (erro) => ({ erro, corpo: null });

    if (!numeroValido(min)) return falha('minimo-invalido');
    if (centavos(min) < centavos(MINIMO_POR_SAQUE)) return falha('minimo-baixo');
    if (!numeroValido(max)) return falha('maximo-invalido');
    if (centavos(max) < centavos(min)) return falha('maximo-menor');
    if (!Number.isInteger(quantos) || quantos < 1 || quantos > 20) return falha('saques-dia');
    if (!Number.isFinite(tx)) return falha('taxa-invalida');
    if (centavos(tx) < 0 || centavos(tx) > 1000) return falha('taxa-faixa');
    if (centavos(tx) >= centavos(min)) return falha('taxa-maior');

    return { erro: null, corpo: { minimo: valorParaEnviar(minimo), maximo_dia: valorParaEnviar(maximo_dia), saques_dia: quantos, taxa: valorParaEnviar(taxa) } };
}

/** Chave i18n da situação do saque. */
export function chaveDaSituacaoDoSaque(situacao) {
    return `${PREFIXO}.situacoes.${SITUACOES_DO_SAQUE.includes(situacao) ? situacao : 'desconhecida'}`;
}

/** Selo da situação: { status do Badge, chave i18n }. */
export function seloDoSaque(saque) {
    return { status: STATUS_DO_SELO[saque?.situacao] ?? 'info', chave: chaveDaSituacaoDoSaque(saque?.situacao) };
}

/** O link do comprovante só se for https (o href nunca recebe javascript: ou http:). */
export function linkSeguro(url) {
    const texto = typeof url === 'string' ? url.trim() : '';

    return /^https:\/\//i.test(texto) ? texto : null;
}

/** Query do GET financeiro/saques: só a situação conhecida. */
export function consultaDosSaques(situacao) {
    return SITUACOES_DO_SAQUE.includes(situacao) ? { situacao } : {};
}

/** Saque da API (Saques::publico + motoboy) → linha da tabela da tela Saques Pix. */
export function linhaDoSaque(saque) {
    const quando = quandoDoIso(saque?.criado_em);

    return {
        id: saque?.id,
        quando: quando ? `${quando.dia} ${quando.hora}` : '',
        motoboyId: saque?.motoboy?.id ?? null,
        motoboy: saque?.motoboy?.nome ?? '',
        valor: Number(saque?.valor) || 0,
        taxa: Number(saque?.taxa) || 0,
        ...seloDoSaque(saque),
        motivo: saque?.situacao === 'falhou' ? saque?.motivo ?? '' : '',
        comprovante: linkSeguro(saque?.comprovante_url),
    };
}

/** Motoboy com chave Pix (lista `motoboys` do GET financeiro/saques) → linha com o rótulo do tipo e a carência. */
export function linhaDoMotoboyComPix(motoboy) {
    const quando = quandoDoIso(motoboy?.liberado_em);

    return {
        id: motoboy?.id,
        nome: motoboy?.nome ?? '',
        chave: motoboy?.chave ?? '',
        chaveDoTipo: TIPOS_CHAVE_PIX.includes(motoboy?.tipo) ? `fleet-ops.ui.financeiro.pix.tipos.${motoboy.tipo}` : null,
        bloqueado: motoboy?.bloqueado === true,
        carencia: quando ? { hora: quando.hora, dia: quando.dia } : null,
    };
}

/** Aviso de carência do extrato (bloco `saque` do servidor) → { chave, params } ou null fora da carência. */
export function avisoDeCarencia(saque) {
    const quando = quandoDoIso(saque?.liberado_em);
    if (!quando) {
        return null;
    }
    const nome = saque.alterada_por ?? '';

    return { chave: `${PREFIXO}.${nome ? 'carencia-por' : 'carencia'}`, params: { hora: quando.hora, dia: quando.dia, nome } };
}
