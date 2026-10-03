#!/usr/bin/env node
// Teste de isolamento do portal da loja (Entregas RestaurantePro).
//
// Roda contra a API de produção, depois do deploy, e confere as regras do portal da loja (ProtegerPortalLoja,
// RegrasPortalLoja, BarrarAceiteDePedidoEncerrado e PortalLojaController, em api/app):
// - cada loja só vê e mexe no que é dela;
// - a coleta do pedido é sempre o Local da loja;
// - endereço salvo não muda pelo portal;
// - pedido cancelado não volta;
// - o usuário de loja não alcança o resto da API.
//
// Preparar: crie o arquivo deploy/teste-lojas.env. Ele é ignorado pelo git e fica só no seu PC, como o stack.env:
// nunca o commite. Use uma CHAVE=valor por linha, sem aspas (aspas em volta do valor são tiradas). Linha que começa
// com # é comentário; não existe comentário no fim da linha, porque a senha pode ter #.
// - A e B TÊM DE SER as lojas de teste (Loja Teste A e Loja Teste B, criadas em Fleet-Ops → Recursos → Lojas, com
//   endereços diferentes). O teste cria pedido, endereços e arquivo nelas e para antes do item 1 se o nome de
//   alguma das duas não tiver "teste".
// - Os usuários do teste têm de estar sem verificação em duas etapas: com ela, o login do script não entra.
//
//     API=https://entregas-api.restaurantepro.com.br
//     LOJA_A_EMAIL=...
//     LOJA_A_SENHA=...
//     LOJA_B_EMAIL=...
//     LOJA_B_SENHA=...
//     # Opcionais. Sem eles, os itens que dependem deles ficam PULADO.
//     # Item 14: uma chave de API da organização (Developers → Chaves de API), que não seja a do app do motoboy, e o
//     # driver_… de um motoboy de teste. A chave também é o plano B da limpeza: se o portal não cancelar um pedido
//     # do teste, a limpeza cancela pela API v1.
//     CHAVE_API=...
//     MOTOBOY_ID=driver_...
//     # Item 16: um usuário de loja desativado. Crie um usuário numa loja de teste e desligue o acesso dele na tela Lojas.
//     LOJA_DESATIVADA_EMAIL=...
//     LOJA_DESATIVADA_SENHA=...
//     # Item 17: um usuário ligado às duas lojas de teste. A tela Lojas não deixa um login em duas lojas: o segundo
//     # vínculo (um vendor_personnel ativo do contato do usuário com a outra loja) é feito à mão e desfeito depois.
//     LOJA_DUPLA_EMAIL=...
//     LOJA_DUPLA_SENHA=...
//
// Rodar (Node 18 ou mais novo, sem dependências), da raiz do repo:
//     node scripts/teste-isolamento-lojas.mjs
// Para usar outro arquivo de configuração:
//     TESTE_LOJAS_ENV=caminho/do/arquivo node scripts/teste-isolamento-lojas.mjs
//
// Atenção:
// - O teste cria um pedido real na Loja A, e o servidor o despacha: os motoboys online perto dela recebem o aviso de
//   pedido novo. O push toca por 30 s, e o alarme do app (AlertaPedido) pode tocar por até 3 min, mesmo com o pedido
//   já cancelado. Os webhooks da organização recebem o pedido criado, o despachado e o cancelado.
// - O teste cancela o pedido logo depois de conferir o despacho, e a limpeza do fim confere o cancelamento, inclusive
//   se algo falhar no meio. Avise os motoboys ou rode quando não houver ninguém online perto da loja de teste.
// - O script mostra as duas lojas e espera 10 s antes de começar, e mais 5 s antes do primeiro pedido: para desistir,
//   use Ctrl+C. Ctrl+C no meio do teste também cancela, antes de sair, os pedidos já criados.
// - Ficam salvos na Loja A os endereços "Teste isolamento" e "Teste isolamento perto da loja", reaproveitados nas
//   rodadas seguintes, e um PNG de 1x1 do item 15 (a foto do perfil não muda). O portal não apaga endereço salvo:
//   o 403 é de propósito.
// - Se uma trava falhar, podem ficar também os endereços "Rua Falsa, 999" e "Rua X" (do pedido com coleta falsa e do
//   destino sem id) e o PNG acima de 5 MB do item 15c. Nesses casos, o script avisa com "ATENÇÃO" o que mudou.
// - Os itens seguem a numeração do plano (Task 14 de docs/superpowers/plans/2026-10-02-portal-da-loja.md). A ordem de
//   execução deixa o pedido real aberto pelo menor tempo possível.
// - Imprime PASSOU, FALHOU ou PULADO por item e sai com 1 se algum falhar. Senha e token nunca são impressos.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// ---------------------------------------------------------------------------------------------------------------
// Constantes
// ---------------------------------------------------------------------------------------------------------------

const RAIZ_DO_REPO = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const ARQUIVO_DE_CONFIGURACAO = process.env.TESTE_LOJAS_ENV
    ? path.resolve(process.env.TESTE_LOJAS_ENV)
    : path.join(RAIZ_DO_REPO, 'deploy', 'teste-lojas.env');

const CHAVES_OBRIGATORIAS = ['API', 'LOJA_A_EMAIL', 'LOJA_A_SENHA', 'LOJA_B_EMAIL', 'LOJA_B_SENHA'];
const CHAVES_SECRETAS = ['LOJA_A_SENHA', 'LOJA_B_SENHA', 'LOJA_DESATIVADA_SENHA', 'LOJA_DUPLA_SENHA', 'CHAVE_API'];

const TEMPO_LIMITE_MS = 20000;
const AGENTE = 'entregas-teste-isolamento/1.0';

// os mesmos status do api/app/Support/Entregas/StatusDoPedido.php
const CANCELADOS = ['canceled', 'cancelled', 'order_canceled'];
const ENCERRADOS = ['completed', 'done', ...CANCELADOS, 'expired'];

// só lojas de teste: o teste cria pedido, endereços e arquivo na Loja A e mexe no perfil da Loja B
const LOJA_DE_TESTE = /teste/i;
const ESPERA_ANTES_DE_COMECAR_MS = 10000;
const ESPERA_ANTES_DO_PEDIDO_MS = 5000;

const NOME_DO_ENDERECO = 'Teste isolamento';
const RUA_DO_ENDERECO = 'Rua Teste, 1';
// destino a ~10 m do Local da Loja A (item 2c)
const NOME_DO_ENDERECO_PERTO = 'Teste isolamento perto da loja';
const RUA_DO_ENDERECO_PERTO = 'Rua Teste, 2';
const CIDADE = 'Ribeirão Preto';
// item 11: o UserController do core acha o papel pelo id ou pelo nome (resolveAssignableRole)
const PAPEL_ADMINISTRADOR = 'Administrator';
// pontos de Ribeirão Preto para o destino de teste: vale o primeiro que fica a 500 m ou mais da coleta da Loja A
const PONTOS_DO_DESTINO = [
    [-21.18, -47.81],
    [-21.19, -47.8],
    [-21.17, -47.82],
    [-21.2, -47.82],
    [-21.16, -47.8],
];
// o texto marca os pedidos do teste: a limpeza também cancela os que ficaram abertos com ele
const NOTA_DO_PEDIDO = 'Teste automático do portal da loja: não aceite. O pedido é cancelado em seguida.';
// corpo inválido de propósito (scheduled_at precisa ser data, meta precisa ser objeto): se a trava da API pública de
// clientes falhar, a API recusa o pedido em vez de criá-lo
const PEDIDO_INVALIDO_V1 = { scheduled_at: 'não é uma data', meta: 'inválido de propósito' };
// PNG de 1x1 (item 15)
const PNG_1X1 = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
    'base64'
);
const FOTO_TAMANHO_MAXIMO = 5 * 1024 * 1024; // o mesmo do ProtegerPortalLoja
const EXTRATO_DIAS_DEMAIS = 120; // o extrato aceita até 92 dias (PortalLojaController)

const SEM_LOGIN_A = 'sem o login da Loja A (item 0a)';
const SEM_LOGIN_B = 'sem o login da Loja B (item 0b)';
const SEM_COLETA = 'sem o Local de coleta da Loja A (item 0c)';

// ---------------------------------------------------------------------------------------------------------------
// Segredos: nada do que é guardado aqui aparece na saída
// ---------------------------------------------------------------------------------------------------------------

// valores que nunca são impressos, também na forma que tomam dentro de um JSON e de uma URL
const segredos = new Set();
// tokens e chave de API (aleatórios): um pedaço deles também é escondido (o servidor pode cortar o valor numa mensagem)
const segredosAleatorios = new Set();

/** Guarda um valor que nunca pode ser impresso. `aleatorio`: token ou chave de API. */
function guardarSegredo(valor, aleatorio = false) {
    if (typeof valor !== 'string' || valor.trim().length < 3) {
        return;
    }
    const valores = [valor];
    // token Sanctum "id|texto": o texto sozinho também é segredo
    const barra = valor.indexOf('|');
    if (barra >= 0 && valor.length - barra > 8) {
        valores.push(valor.slice(barra + 1));
    }
    for (const v of valores) {
        segredos.add(v);
        segredos.add(JSON.stringify(v).slice(1, -1)); // num JSON: " e \ escapados
        segredos.add(encodeURIComponent(v)); // numa URL
        if (aleatorio) {
            segredosAleatorios.add(v);
        }
    }
}

const ENTIDADES_HTML = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ' };

/** Entidades HTML de volta a caracteres (&amp;, &lt;, &#39;, &#x27;...): uma página de erro pode trazer o segredo assim. */
function desfazerEntidades(texto) {
    return texto.replace(/&(#x[0-9a-f]+|#[0-9]+|[a-z]+);/gi, (entidade, corpo) => {
        if (corpo[0] !== '#') {
            return ENTIDADES_HTML[corpo.toLowerCase()] ?? entidade;
        }
        const codigo = /^#x/i.test(corpo) ? parseInt(corpo.slice(2), 16) : parseInt(corpo.slice(1), 10);
        return codigo > 0 && codigo <= 0x10ffff ? String.fromCodePoint(codigo) : entidade;
    });
}

/** Troca por *** todo segredo conhecido que aparecer no texto. */
function limpar(texto) {
    let saida = desfazerEntidades(String(texto ?? ''));
    // os mais longos primeiro: o texto de um token "id|texto" está dentro do token inteiro
    for (const segredo of [...segredos].sort((a, b) => b.length - a.length)) {
        saida = saida.split(segredo).join('***');
    }
    // pedaço de token ou de chave: 10 ou mais letras e números seguidos que estão dentro de um deles
    return saida.replace(/[A-Za-z0-9]{10,}/g, (trecho) => ([...segredosAleatorios].some((s) => s.includes(trecho)) ? '***' : trecho));
}

/** Campos de JSON cujo valor nunca é impresso (no resumo de uma resposta). */
const CAMPO_SECRETO = /token|password|senha|secret|authorization|cookie|session|credential|^key$|api_?key/i;

/** Campos de token ou senha em qualquer resposta viram segredos conhecidos, antes de qualquer impressão. */
function coletarSegredos(valor, profundidade = 0) {
    if (!valor || typeof valor !== 'object' || profundidade > 8) {
        return;
    }
    for (const [chave, item] of Object.entries(valor)) {
        if (typeof item === 'string' && item.length >= 16 && /token|password|secret|authorization/i.test(chave)) {
            guardarSegredo(item.replace(/^Bearer\s+/i, ''), !/password/i.test(chave));
        } else {
            coletarSegredos(item, profundidade + 1);
        }
    }
}

/** Replacer do JSON.stringify do resumo: esconde os campos secretos e limpa cada texto antes de o JSON escapá-lo. */
function ocultarCampos(chave, valor) {
    if (chave && CAMPO_SECRETO.test(chave)) {
        return '***';
    }
    return typeof valor === 'string' ? limpar(valor) : valor;
}

// ---------------------------------------------------------------------------------------------------------------
// Saída e resultados
// ---------------------------------------------------------------------------------------------------------------

const contagem = { PASSOU: 0, FALHOU: 0, PULADO: 0 };

function registrar(resultado, id, descricao, detalhe) {
    contagem[resultado] += 1;
    console.log(limpar(`${resultado.padEnd(7)}[${id}] ${descricao}${detalhe ? ` — ${detalhe}` : ''}`));
}

function info(texto) {
    console.log(limpar(`        ${texto}`));
}

function secao(titulo) {
    console.log(`\n— ${titulo} —`);
}

class Falha extends Error {}
class Pulo extends Error {}
class Interrompido extends Error {}

function falha(motivo) {
    throw new Falha(motivo);
}

function pular(motivo) {
    throw new Pulo(motivo);
}

function exigir(condicao, motivo) {
    if (!condicao) {
        throw new Falha(typeof motivo === 'function' ? motivo() : motivo);
    }
}

/**
 * Um item do teste. `requisitos` = [[valor, motivo], ...]: com algum valor falso, o item fica PULADO (depende de
 * um item anterior que falhou ou de uma chave opcional que não foi preenchida). `executar` devolve o detalhe do
 * PASSOU, ou lança Falha (FALHOU) ou Pulo (PULADO). Devolve se passou.
 */
async function item(id, descricao, requisitos, executar) {
    if (interrompido) {
        throw new Interrompido();
    }
    for (const [ok, motivo] of requisitos) {
        if (!ok) {
            registrar('PULADO', id, descricao, motivo);
            return false;
        }
    }
    try {
        registrar('PASSOU', id, descricao, (await executar()) || '');
        return true;
    } catch (erro) {
        if (erro instanceof Interrompido) {
            throw erro;
        }
        if (erro instanceof Pulo) {
            registrar('PULADO', id, descricao, erro.message);
            return false;
        }
        registrar('FALHOU', id, descricao, erro instanceof Falha ? erro.message : `erro no script: ${descreverErro(erro)}`);
        return false;
    }
}

// ---------------------------------------------------------------------------------------------------------------
// Ctrl+C: interrompe entre um item e outro, e a limpeza cancela os pedidos criados antes de sair
// ---------------------------------------------------------------------------------------------------------------

let interrompido = false;

// SIGHUP: terminal fechado (no Windows, a janela do console); SIGTERM: kill
for (const sinal of ['SIGINT', 'SIGTERM', 'SIGHUP']) {
    process.on(sinal, () => {
        if (interrompido) {
            console.log('\nSaindo sem terminar a limpeza: confira no console os pedidos da loja de teste.');
            process.exit(130);
        }
        interrompido = true;
        console.log(`\nInterrompido (${sinal}). Os pedidos criados pelo teste são cancelados antes de sair; Ctrl+C de novo sai na hora.`);
    });
}

function dormir(ms) {
    return new Promise((resolver) => setTimeout(resolver, ms));
}

/** Espera no fluxo do teste: termina antes se o teste for interrompido. */
async function pausa(ms) {
    const fim = Date.now() + ms;
    while (!interrompido && Date.now() < fim) {
        await dormir(Math.min(200, fim - Date.now()));
    }
    if (interrompido) {
        throw new Interrompido();
    }
}

// ---------------------------------------------------------------------------------------------------------------
// Configuração
// ---------------------------------------------------------------------------------------------------------------

let cfg = {};
let API = '';

function lerConfiguracao(arquivo) {
    let texto;
    try {
        texto = fs.readFileSync(arquivo, 'utf8');
    } catch (erro) {
        return { erro: `não consegui ler ${arquivo} (${erro.code || erro.message}). Veja no topo deste script como prepará-lo.` };
    }

    const config = {};
    const avisos = [];
    texto
        .replace(/^\uFEFF/, '')
        .split(/\r?\n/)
        .forEach((linhaCrua, indice) => {
            const linha = linhaCrua.trim();
            if (!linha || linha.startsWith('#')) {
                return;
            }
            const igual = linha.indexOf('=');
            if (igual <= 0) {
                // o conteúdo da linha não é impresso: pode ser uma senha
                avisos.push(`a linha ${indice + 1} não tem CHAVE=valor e foi ignorada`);
                return;
            }
            let valor = linha.slice(igual + 1).trim();
            if (valor.length >= 2 && ((valor.startsWith('"') && valor.endsWith('"')) || (valor.startsWith("'") && valor.endsWith("'")))) {
                valor = valor.slice(1, -1);
            }
            config[linha.slice(0, igual).trim()] = valor;
        });

    return { config, avisos };
}

/** A URL da API: https://, ou http:// só para a própria máquina (um servidor de teste local). */
function validarApi(valor) {
    let url;
    try {
        url = new URL(valor);
    } catch {
        return { erro: 'API não é uma URL válida (ex.: https://entregas-api.restaurantepro.com.br)' };
    }
    const local = ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname);
    if (url.protocol !== 'https:' && !(url.protocol === 'http:' && local)) {
        return { erro: 'API precisa começar com https:// (http:// só para localhost): a senha vai na requisição' };
    }
    if (url.username || url.password) {
        return { erro: 'API não pode ter usuário nem senha na URL' };
    }
    if (url.search || url.hash || /[?#]/.test(valor)) {
        return { erro: 'API não pode ter ? nem # (só o endereço, ex.: https://entregas-api.restaurantepro.com.br)' };
    }
    return { api: `${url.origin}${url.pathname.replace(/\/+$/, '')}` };
}

// ---------------------------------------------------------------------------------------------------------------
// HTTP
// ---------------------------------------------------------------------------------------------------------------

/**
 * Chama a API e devolve { status, json, texto } (status 0 = sem resposta, com o motivo em `erro`). Sempre com
 * `Accept: application/json` e tempo limite; um 429 (limite de chamadas) espera e repete, até 3 vezes: com o 429,
 * a API não executou a chamada. Não segue redirecionamento, para o token não ir a outro endereço.
 */
async function chamar(metodo, caminho, { token, corpo, cabecalhos = {}, formulario, tempoLimite = TEMPO_LIMITE_MS } = {}) {
    const url = `${API}/${caminho.replace(/^\/+/, '')}`;
    const headers = { Accept: 'application/json', 'User-Agent': AGENTE, ...cabecalhos };
    if (token) {
        headers.Authorization = `Bearer ${token}`;
    }
    let body;
    if (formulario) {
        body = formulario; // multipart: o fetch monta o Content-Type com o boundary
    } else if (corpo !== undefined) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(corpo);
    }

    for (let tentativa = 1; ; tentativa += 1) {
        const controle = new AbortController();
        const relogio = setTimeout(() => controle.abort(), tempoLimite);
        let status;
        let texto;
        let retryAfter;
        try {
            const resposta = await fetch(url, { method: metodo, headers, body, redirect: 'manual', signal: controle.signal });
            status = resposta.status;
            retryAfter = resposta.headers.get('retry-after');
            texto = await resposta.text();
        } catch (erro) {
            const motivo = controle.signal.aborted ? `sem resposta em ${Math.round(tempoLimite / 1000)} s` : descreverErro(erro);
            return { status: 0, json: null, texto: '', erro: motivo };
        } finally {
            clearTimeout(relogio);
        }

        let json = null;
        try {
            json = texto ? JSON.parse(texto) : null;
        } catch {
            json = null;
        }
        coletarSegredos(json);

        if (status === 429 && tentativa < 3) {
            const espera = Math.min(65, Math.max(1, Number(retryAfter) || 10));
            info(`limite de chamadas da API (429) em ${metodo} ${caminho.split('?')[0]}: esperando ${espera} s`);
            await dormir(espera * 1000);
            continue;
        }

        return { status, json, texto };
    }
}

function descreverErro(erro) {
    const causa = erro?.cause?.code || erro?.cause?.message;
    return limpar(causa ? `${erro.message} (${causa})` : String(erro?.message ?? erro));
}

function sucesso(r) {
    return r.status >= 200 && r.status < 300;
}

/** A mensagem de erro da resposta: {errors: [...]} (Fleetbase e Entregas), {error} (API v1) ou {message}. */
function mensagem(r) {
    const json = r?.json;
    if (!json || typeof json !== 'object') {
        return '';
    }
    const { errors, error, message } = json;
    if (Array.isArray(errors) && errors.length) {
        return String(errors[0]);
    }
    if (errors && typeof errors === 'object') {
        const primeira = Object.values(errors).flat()[0];
        if (primeira) {
            return String(primeira);
        }
    }
    if (typeof errors === 'string') {
        return errors;
    }
    if (typeof error === 'string') {
        return error;
    }
    return typeof message === 'string' ? message : '';
}

/** Status e corpo resumido, sem segredos e com no máximo 300 caracteres. */
function resumo(r) {
    if (!r || r.status === 0) {
        return `sem resposta da API (${r?.erro ?? 'erro desconhecido'})`;
    }
    let corpo = mensagem(r);
    if (!corpo) {
        corpo = r.json !== null
            ? JSON.stringify(r.json, ocultarCampos)
            : (r.texto || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
    }
    corpo = limpar(corpo);
    if (corpo.length > 300) {
        corpo = `${corpo.slice(0, 300)}…`;
    }
    return corpo ? `HTTP ${r.status}: ${corpo}` : `HTTP ${r.status}`;
}

/** A resposta tem de ser este erro, em JSON (uma página HTML de erro não veio da API) e, se pedido, com esta mensagem. */
function esperarErro(r, status, padrao) {
    if (r.status !== status) {
        falha(`esperava HTTP ${status}, veio ${resumo(r)}`);
    }
    if (r.json === null || typeof r.json !== 'object') {
        falha(`HTTP ${status} sem JSON (não parece vir da API): ${resumo(r)}`);
    }
    if (padrao && !padrao.test(mensagem(r))) {
        falha(`HTTP ${status}, mas com outra mensagem: ${resumo(r)}`);
    }
}

/** 403 do ProtegerPortalLoja. */
function negado(r) {
    if (sucesso(r)) {
        falha(`liberado: ${resumo(r)}`);
    }
    esperarErro(r, 403, /usuários de loja/i);
    return '';
}

/** Foto recusada pelo ProtegerPortalLoja (422). Com `aceita413`, vale também o 413 de corpo grande demais (PHP ou proxy). */
function fotoRecusada(r, aceita413 = false) {
    if (sucesso(r)) {
        falha(`aceita: arquivo ${idDe(r.json?.file)}`);
    }
    if (aceita413 && r.status === 413) {
        return resumo(r);
    }
    esperarErro(r, 422, /foto do perfil/i);
    return '';
}

// ---------------------------------------------------------------------------------------------------------------
// Ajudantes
// ---------------------------------------------------------------------------------------------------------------

const estado = {
    tokenA: null,
    tokenB: null,
    outrosTokens: [], // do usuário em duas lojas e de um login desativado que entrou: todos saem no fim
    lojaA: null,
    lojaB: null,
    coleta: null, // Local de coleta da Loja A (minha-loja), com lat/lng
    usuarioA: null,
    enderecoTeste: null, // destino salvo da Loja A, com lat/lng e o nome/rua enviados
    pedido: null, // pedido do item 3
    pedidoCancelado: false,
    tentouCriarPedido: false,
    // criações de pedido sem resposta clara (sem resposta, 5xx ou 2xx sem o id): a limpeza procura pela nota do teste
    pedidosIncertos: 0,
};

/** Pedidos criados pelo teste (a limpeza confere se terminaram cancelados). */
const pedidosCriados = new Map();

function idDe(objeto) {
    return objeto?.public_id || objeto?.uuid || objeto?.id || '(sem id)';
}

function idNaUrl(objeto) {
    return encodeURIComponent(objeto.public_id || objeto.uuid);
}

/** O mesmo registro (pedido ou endereço), pelo uuid ou pelo public_id. */
function mesmoRegistro(a, b) {
    return Boolean(a && b && ((a.uuid && a.uuid === b.uuid) || (a.public_id && a.public_id === b.public_id)));
}

function lojasDiferentes() {
    return Boolean(estado.lojaA && estado.lojaB && estado.lojaA.id !== estado.lojaB.id);
}

function nomeDaLojaA() {
    return estado.lojaA?.nome || 'Loja A';
}

/** {lat, lng} de um objeto com latitude/longitude ou com `location` GeoJSON ([lng, lat]). */
function coordenadas(objeto) {
    if (!objeto || typeof objeto !== 'object') {
        return null;
    }
    if (objeto.latitude != null && objeto.longitude != null) {
        const lat = Number(objeto.latitude);
        const lng = Number(objeto.longitude);
        if (Number.isFinite(lat) && Number.isFinite(lng)) {
            return { lat, lng };
        }
    }
    const ponto = objeto.location?.coordinates;
    if (Array.isArray(ponto) && ponto.length >= 2) {
        const lng = Number(ponto[0]);
        const lat = Number(ponto[1]);
        if (Number.isFinite(lat) && Number.isFinite(lng)) {
            return { lat, lng };
        }
    }
    return null;
}

/** Mesmo critério do RegrasPortalLoja: dentro do mundo e fora do (0, 0). */
function coordenadaValida(ponto) {
    return Boolean(
        ponto && Math.abs(ponto.lat) <= 90 && Math.abs(ponto.lng) <= 180 && (Math.abs(ponto.lat) > 0.0001 || Math.abs(ponto.lng) > 0.0001)
    );
}

function mesmoPonto(a, b) {
    return Boolean(a && b && Math.abs(a.lat - b.lat) < 0.000001 && Math.abs(a.lng - b.lng) < 0.000001);
}

/** Distância em metros (haversine, raio médio da Terra), como o RegrasPortalLoja. */
function metrosEntre(a, b) {
    const rad = (graus) => (graus * Math.PI) / 180;
    const dLat = rad(b.lat - a.lat);
    const dLng = rad(b.lng - a.lng);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLng / 2) ** 2;
    return 2 * 6371000 * Math.asin(Math.min(1, Math.sqrt(h)));
}

/** O ponto `metros` ao norte. */
function deslocar(ponto, metros) {
    return { lat: ponto.lat + metros / 111320, lng: ponto.lng };
}

function formatar(ponto) {
    return ponto ? `${ponto.lat.toFixed(5)}, ${ponto.lng.toFixed(5)}` : '(sem coordenadas)';
}

function partesEmSaoPaulo(data) {
    const formato = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'America/Sao_Paulo',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23',
    });
    return Object.fromEntries(formato.formatToParts(data).map((parte) => [parte.type, parte.value]));
}

/** Data (AAAA-MM-DD) no fuso da organização, `dias` depois de hoje. */
function dataEmSaoPaulo(dias = 0) {
    const p = partesEmSaoPaulo(new Date(Date.now() + dias * 86400000));
    return `${p.year}-${p.month}-${p.day}`;
}

const CARIMBO = (() => {
    const p = partesEmSaoPaulo(new Date());
    return `${p.year}${p.month}${p.day}-${p.hour}${p.minute}${p.second}`;
})();

function corpoDoEndereco(nome, rua, ponto, cidade = CIDADE) {
    // `name` vai sempre: sem ele, o PlaceController do portal dá 500
    return { name: nome, street1: rua, city: cidade, location: { type: 'Point', coordinates: [ponto.lng, ponto.lat] } };
}

function novoEndereco(corpo) {
    return chamar('POST', 'customer-portal/int/v1/places', { token: estado.tokenA, corpo });
}

/** Endereços salvos da Loja A que casam com a busca (só os salvos: a busca vai sem geocodificação). */
async function buscarEnderecos(texto) {
    const r = await chamar('GET', `customer-portal/int/v1/places/search?query=${encodeURIComponent(texto)}&limit=50`, {
        token: estado.tokenA,
    });
    exigir(r.status === 200 && Array.isArray(r.json?.places), () => `busca de endereços: ${resumo(r)}`);
    return r.json.places;
}

/** O endereço tem este nome e esta rua (o portal grava em maiúsculas). */
function mesmoNomeERua(lugar, nome, rua) {
    const normalizar = (texto) => String(texto ?? '').trim().toUpperCase();
    return normalizar(lugar?.name) === normalizar(nome) && normalizar(lugar?.street1) === normalizar(rua);
}

function lerPedido(pedido, token = estado.tokenA) {
    return chamar('GET', `customer-portal/int/v1/orders/${idNaUrl(pedido)}`, { token });
}

async function entrar(email, senha) {
    const r = await chamar('POST', 'customer-portal/int/v1/auth/login', { corpo: { identity: email, password: senha } });
    const token = r.json?.token;
    if (sucesso(r) && typeof token === 'string' && token) {
        guardarSegredo(token, true);
        return token;
    }
    if (r.json?.twoFaSession || r.json?.isEnabled) {
        falha('o usuário pede verificação em duas etapas: desligue-a no usuário de teste');
    }
    falha(`login recusado: ${resumo(r)}`);
}

/** Pedido novo pela Loja A, sempre com a nota do teste. O pedido criado entra na limpeza. */
async function criarPedido(corpo, origem) {
    estado.tentouCriarPedido = true;
    const r = await chamar('POST', 'customer-portal/int/v1/orders', { token: estado.tokenA, corpo: { notes: NOTA_DO_PEDIDO, ...corpo } });
    const pedido = r.json?.order;
    if (sucesso(r) && pedido && (pedido.uuid || pedido.public_id)) {
        pedidosCriados.set(pedido.uuid || pedido.public_id, { uuid: pedido.uuid, public_id: pedido.public_id, origem });
        info(`pedido criado (${origem}): ${idDe(pedido)}`);
    } else if (sucesso(r) || r.status === 0 || r.status >= 500) {
        // sem resposta, erro do servidor ou 2xx sem o id: o pedido pode existir sem a resposta mostrar
        estado.pedidosIncertos += 1;
        info(`ATENÇÃO: não dá para saber se o pedido (${origem}) foi criado (${resumo(r)}). A limpeza procura pela nota do teste.`);
    }
    return r;
}

/** Cancela na hora um pedido que uma trava quebrada deixou criar (itens 2c e 5). Devolve o que aconteceu. */
async function cancelarNaHora(pedido) {
    if (!pedido || !(pedido.uuid || pedido.public_id)) {
        return 'sem o id para cancelar: a limpeza procura pela nota do teste';
    }
    try {
        return await garantirCancelado(pedido);
    } catch (erro) {
        return `não cancelou (${erro.message}); a limpeza tenta de novo`;
    }
}

// ---------------------------------------------------------------------------------------------------------------
// Etapas
// ---------------------------------------------------------------------------------------------------------------

async function etapaPreparacao() {
    secao('Preparação');

    await item('0a', 'Login da Loja A', [], async () => {
        estado.tokenA = await entrar(cfg.LOJA_A_EMAIL, cfg.LOJA_A_SENHA);
        return 'ok (o token não é impresso)';
    });

    await item('0b', 'Login da Loja B', [], async () => {
        estado.tokenB = await entrar(cfg.LOJA_B_EMAIL, cfg.LOJA_B_SENHA);
        return 'ok (o token não é impresso)';
    });

    await item('0c', 'Loja A tem Local de coleta com coordenadas (minha-loja)', [[estado.tokenA, SEM_LOGIN_A]], async () => {
        const r = await chamar('GET', 'int/v1/entregas/loja/minha-loja', { token: estado.tokenA });
        exigir(r.status === 200 && r.json?.loja, () => resumo(r));
        const loja = r.json.loja;
        estado.lojaA = loja; // o nome vale para o item 0e mesmo sem coleta
        const ponto = coordenadas(loja.coleta);
        exigir(
            loja.coleta?.uuid && coordenadaValida(ponto),
            'a Loja A está sem Local de coleta com coordenadas: cadastre o endereço dela na tela Lojas'
        );
        estado.coleta = { ...loja.coleta, ...ponto };
        return `${loja.nome} (${loja.id}), Local ${idDe(loja.coleta)} em ${formatar(ponto)}`;
    });

    await item('0d', 'Loja B é outra loja (minha-loja)', [[estado.tokenB, SEM_LOGIN_B]], async () => {
        const r = await chamar('GET', 'int/v1/entregas/loja/minha-loja', { token: estado.tokenB });
        exigir(r.status === 200 && r.json?.loja, () => resumo(r));
        estado.lojaB = r.json.loja;
        exigir(
            !estado.lojaA || estado.lojaB.id !== estado.lojaA.id,
            `A e B entram na mesma loja (${estado.lojaB.nome}): use no teste-lojas.env usuários de lojas diferentes`
        );
        return `${estado.lojaB.nome} (${estado.lojaB.id})`;
    });

    // o teste cria pedido, endereços e arquivo na Loja A e tenta mudar o papel do usuário da Loja B: só lojas de teste
    const deTeste = await item('0e', 'A e B são lojas de teste (o nome tem "teste")', [], async () => {
        exigir(estado.lojaA?.nome, 'não deu para conferir o nome da Loja A (item 0c)');
        exigir(estado.lojaB?.nome, 'não deu para conferir o nome da Loja B (item 0d)');
        for (const [letra, loja] of [
            ['A', estado.lojaA],
            ['B', estado.lojaB],
        ]) {
            exigir(LOJA_DE_TESTE.test(loja.nome), `a Loja ${letra} é "${loja.nome}": no teste-lojas.env, use só usuários das lojas de teste`);
        }
        return '';
    });
    if (!deTeste) {
        console.log('\nO teste parou antes do item 1: ele só roda com as duas lojas de teste.');
        return false;
    }

    console.log(
        limpar(
            `\nAVISO  Lojas do teste: A = "${estado.lojaA.nome}" (${estado.lojaA.id}) e B = "${estado.lojaB.nome}" (${estado.lojaB.id}).\n` +
                `       O teste cria endereços e um pedido real na "${estado.lojaA.nome}": os motoboys online perto dela recebem\n` +
                '       o aviso de pedido novo (o push toca 30 s, e o alarme do app, até 3 min), e os webhooks da organização\n' +
                '       recebem o pedido. O próprio teste cancela o pedido. Começa em 10 s; para desistir, aperte Ctrl+C.'
        )
    );
    await pausa(ESPERA_ANTES_DE_COMECAR_MS);
    return true;
}

/** O primeiro ponto de PONTOS_DO_DESTINO a 500 m ou mais da coleta (sem a coleta, o primeiro). */
function pontoDoDestino() {
    for (const [lat, lng] of PONTOS_DO_DESTINO) {
        const ponto = { lat, lng };
        if (!estado.coleta || metrosEntre(ponto, estado.coleta) >= 500) {
            return ponto;
        }
    }
    return deslocar(estado.coleta, 1500);
}

function conferirEnderecoSalvo(r, alvo) {
    const lugar = r.json?.place;
    exigir(lugar?.uuid, () => `resposta sem o endereço: ${resumo(r)}`);
    const ponto = coordenadas(lugar);
    exigir(
        ponto && Math.abs(ponto.lat - alvo.lat) < 0.0001 && Math.abs(ponto.lng - alvo.lng) < 0.0001,
        `o endereço ${idDe(lugar)} foi salvo em ${formatar(ponto)}, e não no ponto marcado (${formatar(alvo)})`
    );
    return { ...lugar, ...ponto };
}

/** Item 2: salva o destino de teste ou, se ele já existe de uma rodada anterior, reaproveita o salvo. */
async function prepararEnderecoDeTeste() {
    const alvo = pontoDoDestino();
    const r = await novoEndereco(corpoDoEndereco(NOME_DO_ENDERECO, RUA_DO_ENDERECO, alvo));

    if (sucesso(r)) {
        const salvo = conferirEnderecoSalvo(r, alvo);
        estado.enderecoTeste = { ...salvo, nomeEnviado: NOME_DO_ENDERECO, ruaEnviada: RUA_DO_ENDERECO };
        return `salvo: ${idDe(salvo)} em ${formatar(salvo)}`;
    }

    if (r.status === 422 && /já existe/i.test(mensagem(r))) {
        const existente = (await buscarEnderecos(NOME_DO_ENDERECO)).find((lugar) => mesmoNomeERua(lugar, NOME_DO_ENDERECO, RUA_DO_ENDERECO));
        exigir(existente, `422 de endereço repetido, mas a busca não achou "${NOME_DO_ENDERECO}"`);
        const ponto = coordenadas(existente);
        if (coordenadaValida(ponto) && (!estado.coleta || metrosEntre(ponto, estado.coleta) >= 100)) {
            estado.enderecoTeste = { ...existente, ...ponto, nomeEnviado: NOME_DO_ENDERECO, ruaEnviada: RUA_DO_ENDERECO };
            return `já existia (rodada anterior): ${idDe(existente)} em ${formatar(ponto)}`;
        }

        // o salvo não serve (sem coordenadas ou perto demais da coleta): um novo, com o nome desta rodada
        const nome = `${NOME_DO_ENDERECO} ${CARIMBO}`;
        const r2 = await novoEndereco(corpoDoEndereco(nome, RUA_DO_ENDERECO, alvo));
        exigir(sucesso(r2), () => `o "${NOME_DO_ENDERECO}" salvo não serve (${formatar(ponto)}) e o novo foi recusado: ${resumo(r2)}`);
        const salvo = conferirEnderecoSalvo(r2, alvo);
        estado.enderecoTeste = { ...salvo, nomeEnviado: nome, ruaEnviada: RUA_DO_ENDERECO };
        return `o salvo ${idDe(existente)} não servia (${formatar(ponto)}); novo: ${idDe(salvo)} em ${formatar(salvo)}`;
    }

    falha(`esperava HTTP 200 (ou 422 de endereço repetido), veio ${resumo(r)}`);
}

async function etapaEnderecos() {
    secao('Endereços da Loja A');
    const semA = [estado.tokenA, SEM_LOGIN_A];

    await item('1', 'Endereço sem coordenadas é recusado (422)', [semA], async () => {
        const r = await novoEndereco({ name: NOME_DO_ENDERECO, street1: RUA_DO_ENDERECO });
        if (sucesso(r)) {
            falha(`o endereço foi salvo sem coordenadas (${idDe(r.json?.place)}; fica na Loja A)`);
        }
        esperarErro(r, 422, /mapa/i);
        return '';
    });

    await item('2', 'Endereço com coordenadas é salvo (200)', [semA], () => prepararEnderecoDeTeste());

    const temEndereco = [estado.enderecoTeste, 'depende do item 2'];

    await item('2b.1', 'Endereço salvo não muda por PATCH (403)', [semA, temEndereco], async () => {
        const salvo = estado.enderecoTeste;
        const r = await chamar('PATCH', `customer-portal/int/v1/places/${idNaUrl(salvo)}`, {
            token: estado.tokenA,
            corpo: { name: salvo.nomeEnviado, street1: salvo.ruaEnviada, street2: 'ALTERADO PELO TESTE' },
        });
        if (sucesso(r)) {
            falha(`o portal alterou o endereço salvo ${idDe(salvo)}`);
        }
        esperarErro(r, 403, /não podem ser alterados/i);
        return '';
    });

    await item('2b.2', 'Endereço com o mesmo nome e rua é recusado (422) e o salvo não muda', [semA, temEndereco], async () => {
        const salvo = estado.enderecoTeste;
        const r = await novoEndereco(corpoDoEndereco(salvo.nomeEnviado, salvo.ruaEnviada, deslocar(salvo, 120)));
        let depois = null;
        let erroDaBusca = null;
        try {
            depois = (await buscarEnderecos(salvo.nomeEnviado)).find((lugar) => mesmoRegistro(lugar, salvo));
        } catch (erro) {
            erroDaBusca = erro.message;
        }
        if (sucesso(r)) {
            falha(
                mesmoRegistro(r.json?.place, salvo)
                    ? `o portal sobrescreveu o endereço salvo ${idDe(salvo)} (agora em ${formatar(coordenadas(r.json.place))})`
                    : `o portal salvou um endereço repetido (${idDe(r.json?.place)}; fica na Loja A)`
            );
        }
        esperarErro(r, 422, /já existe/i);
        exigir(!erroDaBusca, () => `recusado, mas não deu para reler o endereço salvo (${erroDaBusca})`);
        exigir(
            depois && mesmoPonto(coordenadas(depois), salvo),
            () => `o endereço salvo mudou: ${depois ? formatar(coordenadas(depois)) : 'sumiu da busca'} (era ${formatar(salvo)})`
        );
        return '';
    });

    await item(
        '2b.3',
        'Endereço com o nome e a rua do Local da loja é recusado (422) e o Local não muda',
        [semA, [estado.coleta, SEM_COLETA]],
        async () => {
            const coleta = estado.coleta;
            // o nome, a rua e o ponto do próprio Place: o nome e a rua são a chave do firstOrNew do portal, e o minha-loja
            // traz o nome da loja, não o do Place. Vão exatamente como estão, com o mesmo ponto e sem cidade: se a trava
            // falhar, o portal regrava o Local com os mesmos dados e nada muda
            let doLocal;
            try {
                doLocal = coleta.street1 ? (await buscarEnderecos(coleta.street1)).find((lugar) => mesmoRegistro(lugar, coleta)) : null;
            } catch (erro) {
                pular(`a busca de endereços falhou: ${erro.message}`);
            }
            const ponto = coordenadas(doLocal);
            if (!doLocal?.name || !doLocal?.street1 || !coordenadaValida(ponto)) {
                pular('o Local da Loja A não apareceu, com nome, rua e coordenadas, na busca de endereços da loja');
            }

            const r = await novoEndereco({
                name: doLocal.name,
                street1: doLocal.street1,
                location: { type: 'Point', coordinates: [ponto.lng, ponto.lat] },
            });
            // rede de segurança: o Local continua o mesmo Place, no mesmo ponto
            const depois = await chamar('GET', 'int/v1/entregas/loja/minha-loja', { token: estado.tokenA });
            exigir(depois.status === 200, () => `não deu para reler o minha-loja: ${resumo(depois)}`);
            const coletaDepois = depois.json?.loja?.coleta;
            if (!mesmoRegistro(coletaDepois, coleta) || !mesmoPonto(coordenadas(coletaDepois), coleta)) {
                // os itens seguintes conferem contra o Local como ele está agora
                if (coletaDepois?.uuid && coordenadaValida(coordenadas(coletaDepois))) {
                    estado.coleta = { ...coletaDepois, ...coordenadas(coletaDepois) };
                }
                falha(
                    `ATENÇÃO: o Local da Loja A mudou (agora ${idDe(coletaDepois)} em ${formatar(coordenadas(coletaDepois))}; ` +
                        `era ${idDe(coleta)} em ${formatar(coleta)}). Corrija o endereço da loja na tela Lojas.`
                );
            }
            if (sucesso(r)) {
                falha(
                    mesmoRegistro(r.json?.place, coleta)
                        ? 'a trava não reconheceu o Local: o portal regravou o Local da loja (com os mesmos dados, então nada mudou)'
                        : `o portal salvou outro endereço com o nome e a rua do Local (${idDe(r.json?.place)}; fica na Loja A)`
                );
            }
            esperarErro(r, 422, /já existe/i);
            return `nome "${doLocal.name}", rua "${doLocal.street1}": o Local continua igual`;
        }
    );

    await item('2b.4', 'Endereço salvo não é apagado por DELETE (403)', [semA, temEndereco], async () => {
        const salvo = estado.enderecoTeste;
        const r = await chamar('DELETE', `customer-portal/int/v1/places/${idNaUrl(salvo)}`, { token: estado.tokenA });
        if (sucesso(r)) {
            // os itens do pedido precisam de um destino salvo
            estado.enderecoTeste = null;
            let recriado;
            try {
                recriado = await prepararEnderecoDeTeste();
            } catch (erro) {
                recriado = `não deu para recriar: ${erro.message}`;
            }
            falha(`o portal apagou o endereço salvo ${idDe(salvo)} (para os próximos itens: ${recriado})`);
        }
        esperarErro(r, 403, /não podem ser alterados/i);
        return '';
    });
}

/** Item 10: com o token de B, todas estas dão 403 (ProtegerPortalLoja). */
const ROTAS_NEGADAS = [
    ['GET', 'int/v1/orders'],
    ['GET', 'int/v1/contacts'],
    ['GET', 'int/v1/places'],
    ['GET', 'int/v1/drivers'],
    ['GET', 'v1/orders'],
    ['GET', 'int/v1/entregas/lojas'],
    ['GET', 'int/v1/entregas/pagamento-motoboys?inicio=2026-01-01&fim=2026-01-02'],
    ['GET', 'customer-portal/int/v1/settings/config'],
    ['GET', 'customer-portal/int/v1/account/personnel-candidates'],
    ['POST', 'customer-portal/int/v1/service-quotes/preliminary'],
];

function papel(usuario) {
    return usuario?.role_name || usuario?.role?.name || '(sem papel)';
}

function ehAdministrador(usuario) {
    return Boolean(
        usuario &&
            (/^administrator$/i.test(usuario.role_name ?? '') ||
                /^administrator$/i.test(usuario.role?.name ?? '') ||
                usuario.is_admin === true ||
                usuario.type === 'admin')
    );
}

async function etapaAcessoDeB() {
    secao('Loja B fora do portal');
    const semB = [estado.tokenB, SEM_LOGIN_B];

    let n = 0;
    for (const [metodo, caminho] of ROTAS_NEGADAS) {
        n += 1;
        await item(`10.${n}`, `B → 403: ${metodo} ${caminho}`, [semB], async () =>
            negado(await chamar(metodo, caminho, { token: estado.tokenB, corpo: metodo === 'GET' ? undefined : {} }))
        );
    }

    // API pública de clientes: o ProtegerPortalLoja identifica a loja também pelo cabeçalho Customer-Token
    const comTokenDeCliente = (bearer) =>
        chamar('POST', 'v1/customers/orders', { token: bearer, corpo: PEDIDO_INVALIDO_V1, cabecalhos: { 'Customer-Token': estado.tokenB } });
    await item(`10.${(n += 1)}`, 'B → 403: POST v1/customers/orders com Customer-Token, sem Bearer', [semB], async () =>
        negado(await comTokenDeCliente(undefined))
    );
    await item(`10.${(n += 1)}`, 'B → 403: POST v1/customers/orders com Customer-Token e um Bearer qualquer', [semB], async () =>
        negado(await comTokenDeCliente('flb_live_teste_isolamento_invalida'))
    );
    await item(
        `10.${(n += 1)}`,
        'B → 403: POST v1/customers/orders com Customer-Token e a chave de API',
        [semB, [cfg.CHAVE_API, 'sem CHAVE_API no teste-lojas.env']],
        async () => negado(await comTokenDeCliente(cfg.CHAVE_API))
    );

    await item('11', 'B não vira administrador pelo próprio perfil', [semB], async () => {
        const r1 = await chamar('GET', 'int/v1/users/me', { token: estado.tokenB });
        exigir(r1.status === 200 && r1.json?.user, () => `users/me: ${resumo(r1)}`);
        const eu = r1.json.user;
        // o ProtegerPortalLoja reconhece o próprio perfil pelo uuid, pelo public_id ou pelo id
        const id = eu.uuid || eu.public_id || eu.id;
        exigir(id, 'o users/me não trouxe o id do usuário');
        exigir(!ehAdministrador(eu), `B já era administrador antes do teste (papel ${papel(eu)}): corrija o usuário de B`);
        // o papel de agora, para devolver na hora se a trava falhar (o core aceita o id ou o nome do papel)
        const papelOriginal = eu.role?.id ?? eu.role_name ?? eu.role?.name;
        if (papelOriginal === undefined || papelOriginal === null || papelOriginal === '') {
            pular('o users/me de B veio sem papel: se a trava falhasse, não haveria papel para devolver');
        }

        const salvarPerfil = (role) => {
            const corpo = { user: { role: String(role) } };
            if (typeof eu.name === 'string' && eu.name) {
                corpo.user.name = eu.name;
            }
            return chamar('PUT', `int/v1/users/${encodeURIComponent(id)}`, { token: estado.tokenB, corpo });
        };
        const lerPerfil = async () => {
            const r = await chamar('GET', 'int/v1/users/me', { token: estado.tokenB });
            return { r, usuario: r.status === 200 ? r.json?.user ?? null : null };
        };

        const r2 = await salvarPerfil(PAPEL_ADMINISTRADOR);
        const depois = await lerPerfil();
        if (ehAdministrador(depois.usuario) || (sucesso(r2) && ehAdministrador(r2.json?.user))) {
            // a trava falhou: desfaz na hora, pelo mesmo caminho
            const promovido = ehAdministrador(depois.usuario) ? depois.usuario : r2.json.user;
            const reversao = await salvarPerfil(papelOriginal);
            const agora = await lerPerfil();
            const revertido = agora.usuario && !ehAdministrador(agora.usuario) && papel(agora.usuario) === papel(eu);
            falha(
                `B virou ${papel(promovido)} pelo PUT do próprio perfil. ` +
                    (revertido
                        ? `Revertido para ${papel(eu)}.`
                        : `ATENÇÃO: reverter à mão (devolva o papel ${papel(eu)} ao usuário de B na central; a reversão deu ${resumo(reversao)})`)
            );
        }
        exigir(
            sucesso(r2),
            () => `o PUT do próprio perfil deu ${resumo(r2)}. Com a trava, só o nome chega ao controller: o erro sugere que o role chegou`
        );
        exigir(depois.usuario, () => `users/me depois do PUT: ${resumo(depois.r)}`);
        exigir(depois.usuario.type === eu.type, `o tipo do usuário mudou de ${eu.type} para ${depois.usuario.type}`);
        exigir(papel(depois.usuario) === papel(eu), `o papel mudou de ${papel(eu)} para ${papel(depois.usuario)}`);
        return `papel antes e depois: ${papel(eu)} (o PUT deu HTTP ${r2.status})`;
    });

    await item('12a', 'Extrato de B do dia (200, com totais)', [semB], async () => {
        const hoje = dataEmSaoPaulo();
        const r = await chamar('GET', `int/v1/entregas/loja/extrato?inicio=${hoje}&fim=${hoje}`, { token: estado.tokenB });
        exigir(r.status === 200, () => `esperava HTTP 200, veio ${resumo(r)}`);
        const totais = r.json?.totais;
        exigir(totais && typeof totais === 'object', () => `resposta sem totais: ${resumo(r)}`);
        return `${hoje}: ${totais.entregas ?? '?'} entrega(s)`;
    });

    await item('12b', `Extrato de ${EXTRATO_DIAS_DEMAIS} dias é recusado (422)`, [semB], async () => {
        const inicio = dataEmSaoPaulo(-EXTRATO_DIAS_DEMAIS);
        const fim = dataEmSaoPaulo();
        const r = await chamar('GET', `int/v1/entregas/loja/extrato?inicio=${inicio}&fim=${fim}`, { token: estado.tokenB });
        // só o status: o texto do limite muda com o período máximo
        esperarErro(r, 422);
        return mensagem(r);
    });
}

async function enviarFoto(conteudo, nomeDoArquivo, tipo, tempoLimite) {
    const formulario = new FormData();
    formulario.append('type', 'user_avatar');
    formulario.append('subject_uuid', estado.usuarioA.uuid);
    formulario.append('subject_type', 'user');
    formulario.append('file', new Blob([conteudo], { type: tipo }), nomeDoArquivo);
    return chamar('POST', 'int/v1/files/upload', { token: estado.tokenA, formulario, tempoLimite });
}

async function etapaFoto() {
    secao('Foto do perfil da Loja A');
    const semA = [estado.tokenA, SEM_LOGIN_A];

    if (estado.tokenA && !estado.usuarioA) {
        const r = await chamar('GET', 'int/v1/users/me', { token: estado.tokenA });
        if (r.status === 200 && r.json?.user?.uuid) {
            estado.usuarioA = r.json.user;
        } else {
            info(`users/me da Loja A: ${resumo(r)}`);
        }
    }
    const temUsuario = [estado.usuarioA, 'sem o id do usuário da Loja A (users/me)'];
    const texto = Buffer.from('isto não é uma foto\n', 'utf8');

    await item('15a', 'Arquivo de texto é recusado (422)', [semA, temUsuario], async () =>
        fotoRecusada(await enviarFoto(texto, 'teste.txt', 'text/plain'))
    );
    await item('15b', 'Texto com nome .png é recusado (422)', [semA, temUsuario], async () =>
        fotoRecusada(await enviarFoto(texto, 'foto.png', 'image/png'))
    );
    await item('15c', 'PNG acima de 5 MB é recusado (422 ou 413)', [semA, temUsuario], async () => {
        const grande = Buffer.concat([PNG_1X1, Buffer.alloc(FOTO_TAMANHO_MAXIMO + 1024 - PNG_1X1.length)]);
        return fotoRecusada(await enviarFoto(grande, 'grande.png', 'image/png', 120000), true);
    });
    await item('15d', 'PNG pequeno é aceito (2xx)', [semA, temUsuario], async () => {
        const r = await enviarFoto(PNG_1X1, 'foto.png', 'image/png');
        exigir(sucesso(r), () => `esperava 2xx, veio ${resumo(r)}`);
        return `arquivo ${idDe(r.json?.file)} (a foto do perfil não muda)`;
    });
}

async function etapaOutrosUsuarios() {
    secao('Outros usuários de loja');

    await item(
        '16',
        'Login de usuário de loja desativado é recusado (401)',
        [[cfg.LOJA_DESATIVADA_EMAIL && cfg.LOJA_DESATIVADA_SENHA, 'sem LOJA_DESATIVADA_EMAIL e LOJA_DESATIVADA_SENHA no teste-lojas.env']],
        async () => {
            const r = await chamar('POST', 'customer-portal/int/v1/auth/login', {
                corpo: { identity: cfg.LOJA_DESATIVADA_EMAIL, password: cfg.LOJA_DESATIVADA_SENHA },
            });
            if (sucesso(r)) {
                if (typeof r.json?.token === 'string') {
                    estado.outrosTokens.push(r.json.token);
                }
                falha('o login entrou (o token não é impresso)');
            }
            esperarErro(r, 401, /desativad/i);
            return '';
        }
    );

    await item(
        '17',
        'Usuário ligado a duas lojas é barrado no portal (403)',
        [[cfg.LOJA_DUPLA_EMAIL && cfg.LOJA_DUPLA_SENHA, 'sem LOJA_DUPLA_EMAIL e LOJA_DUPLA_SENHA no teste-lojas.env']],
        async () => {
            const token = await entrar(cfg.LOJA_DUPLA_EMAIL, cfg.LOJA_DUPLA_SENHA);
            estado.outrosTokens.push(token);
            const r = await chamar('GET', 'customer-portal/int/v1/orders', { token });
            if (sucesso(r)) {
                falha('o portal listou os pedidos (juntaria os das duas lojas)');
            }
            esperarErro(r, 403, /usuários de loja/i);
            return '';
        }
    );
}

/** Item 2c: o destino a ~10 m do Local da Loja A, reaproveitado entre as rodadas. Devolve { lugar } ou { recusa }. */
async function enderecoPertoDaLoja() {
    const perto = deslocar(estado.coleta, 10);
    const cidade = estado.coleta.city || CIDADE;
    const r = await novoEndereco(corpoDoEndereco(NOME_DO_ENDERECO_PERTO, RUA_DO_ENDERECO_PERTO, perto, cidade));
    if (sucesso(r)) {
        const lugar = conferirEnderecoSalvo(r, perto);
        info(`endereço salvo na Loja A: ${idDe(lugar)} "${NOME_DO_ENDERECO_PERTO}" (o portal não o apaga)`);
        return { lugar };
    }
    if (!(r.status === 422 && /já existe/i.test(mensagem(r)))) {
        return { recusa: r };
    }

    // de uma rodada anterior: vale se ainda estiver a menos de 30 m do Local (a loja pode ter mudado de lugar)
    const existente = (await buscarEnderecos(NOME_DO_ENDERECO_PERTO)).find((lugar) =>
        mesmoNomeERua(lugar, NOME_DO_ENDERECO_PERTO, RUA_DO_ENDERECO_PERTO)
    );
    exigir(existente, `422 de endereço repetido, mas a busca não achou "${NOME_DO_ENDERECO_PERTO}"`);
    const ponto = coordenadas(existente);
    if (coordenadaValida(ponto) && metrosEntre(ponto, estado.coleta) < 25) {
        return { lugar: { ...existente, ...ponto } };
    }
    const nome = `${NOME_DO_ENDERECO_PERTO} ${CARIMBO}`;
    const r2 = await novoEndereco(corpoDoEndereco(nome, RUA_DO_ENDERECO_PERTO, perto, cidade));
    if (!sucesso(r2)) {
        return { recusa: r2 };
    }
    const lugar = conferirEnderecoSalvo(r2, perto);
    info(`o "${NOME_DO_ENDERECO_PERTO}" salvo está longe do Local (${formatar(ponto)}); novo: ${idDe(lugar)} "${nome}"`);
    return { lugar };
}

async function etapaPedido() {
    secao('Pedido de teste');
    const semA = [estado.tokenA, SEM_LOGIN_A];
    const semColeta = [estado.coleta, SEM_COLETA];

    if (estado.tokenA && estado.coleta) {
        console.log(
            limpar(
                `AVISO  Os próximos itens criam um pedido real na ${nomeDaLojaA()}, e o servidor o despacha: os motoboys\n` +
                    '       online perto dela recebem o aviso de pedido novo. O próprio teste cancela o pedido logo depois de\n' +
                    '       conferir o despacho (e a limpeza do fim confere, se algo falhar no meio). Para desistir, aperte\n' +
                    '       Ctrl+C nos próximos 5 s: ainda não há pedido.'
            )
        );
        await pausa(ESPERA_ANTES_DO_PEDIDO_MS);
    }

    await item('2c', 'Destino a menos de 30 m da coleta é recusado (422)', [semA, semColeta], async () => {
        const { lugar, recusa } = await enderecoPertoDaLoja();
        if (recusa) {
            // o servidor confere a distância no pedido; se passar a conferir já no endereço, essa recusa também vale
            esperarErro(recusa, 422, /mesmo lugar/i);
            return `o servidor já recusou o endereço: ${mensagem(recusa)}`;
        }
        const rp = await criarPedido({ dropoff: lugar.uuid }, 'item 2c');
        if (sucesso(rp)) {
            falha(
                `o pedido foi aceito com o destino a ${Math.round(metrosEntre(lugar, estado.coleta))} m do Local ` +
                    `(${idDe(rp.json?.order)}: ${await cancelarNaHora(rp.json?.order)})`
            );
        }
        esperarErro(rp, 422, /mesmo lugar/i);
        return `destino ${idDe(lugar)}, a ${Math.round(metrosEntre(lugar, estado.coleta))} m do Local: o pedido foi recusado`;
    });

    await item('5', 'Pedido com destino sem id é recusado (422)', [semA, semColeta], async () => {
        const rp = await criarPedido({ dropoff: { street1: 'Rua X', latitude: -21.2, longitude: -47.8 } }, 'item 5');
        if (sucesso(rp)) {
            falha(`o pedido foi aceito (${idDe(rp.json?.order)}: ${await cancelarNaHora(rp.json?.order)})`);
        }
        esperarErro(rp, 422, /endereço de entrega/i);
        return '';
    });

    await item(
        '3',
        `Pedido com coleta falsa sai com o Local da ${nomeDaLojaA()} (200)`,
        [semA, semColeta, [estado.enderecoTeste, 'depende do item 2']],
        async () => {
            const r = await criarPedido(
                {
                    dropoff: estado.enderecoTeste.uuid,
                    pickup: { street1: 'Rua Falsa, 999', latitude: -23.5, longitude: -46.6 },
                    meta: { entregas: { km_rota: { metros: 1 } } },
                    internal_id: 'TESTE-ISOLAMENTO',
                    pod_required: true,
                },
                'item 3'
            );
            exigir(sucesso(r), () => `esperava HTTP 200, veio ${resumo(r)}`);
            const pedido = r.json?.order;
            exigir(pedido?.public_id || pedido?.uuid, () => `resposta sem o pedido: ${resumo(r)}`);
            estado.pedido = pedido;

            const problemas = [];
            // sem o campo na resposta, as conferências abaixo passariam sem conferir nada
            for (const campo of ['meta', 'internal_id', 'pod_required']) {
                if (!Object.prototype.hasOwnProperty.call(pedido, campo)) {
                    problemas.push(`a resposta não traz ${campo} (não deu para conferir)`);
                }
            }
            const coleta = pedido.payload?.pickup;
            if (!coleta) {
                problemas.push('o pedido veio sem coleta');
            } else {
                if (!mesmoRegistro(coleta, estado.coleta)) {
                    problemas.push(`a coleta é ${idDe(coleta)} (${coleta.street1 ?? 'sem rua'}), e não o Local ${idDe(estado.coleta)}`);
                }
                if (/falsa/i.test(coleta.street1 ?? '')) {
                    problemas.push('a coleta ficou com a "Rua Falsa"');
                }
            }
            if (!mesmoRegistro(pedido.payload?.dropoff, estado.enderecoTeste)) {
                problemas.push(`o destino é ${idDe(pedido.payload?.dropoff)}, e não o endereço ${idDe(estado.enderecoTeste)}`);
            }
            if (pedido.meta?.entregas != null) {
                problemas.push('o pedido ficou com meta.entregas (o cache do km)');
            }
            if (pedido.internal_id === 'TESTE-ISOLAMENTO') {
                problemas.push('o pedido ficou com o internal_id enviado');
            }
            if (pedido.pod_required === true) {
                problemas.push('o pedido ficou com pod_required');
            }
            exigir(!problemas.length, problemas.join('; '));
            return `pedido ${idDe(pedido)}, coleta "${coleta.street1 ?? idDe(coleta)}"`;
        }
    );

    const temPedido = [estado.pedido, 'depende do item 3'];
    const deB = [
        [estado.tokenB, SEM_LOGIN_B],
        [lojasDiferentes(), 'depende dos itens 0c e 0d (as lojas A e B, diferentes)'],
    ];

    // o pedido real fica aberto só até o cancelamento do item 13a: 6 a 9 conferem o isolamento com ele já cancelado
    await item('4', 'Pedido despachado aos motoboys (adhoc)', [temPedido], async () => {
        // o RegrasPortalLoja despacha antes de responder à criação: a leitura logo depois já mostra o despacho
        let r;
        for (let tentativa = 0; tentativa < 3; tentativa += 1) {
            if (tentativa) {
                await pausa(1500);
            }
            r = await lerPedido(estado.pedido);
            const pedido = r.json?.order;
            if (r.status === 200 && pedido?.adhoc === true && (pedido.dispatched === true || pedido.status === 'dispatched')) {
                return `status ${pedido.status}`;
            }
        }
        exigir(r.status === 200, () => resumo(r));
        const pedido = r.json?.order;
        falha(`adhoc=${pedido?.adhoc}, dispatched=${pedido?.dispatched}, status=${pedido?.status}`);
    });

    await item('13a', 'A cancela o próprio pedido antes do aceite (200, canceled)', [temPedido], async () => {
        const r = await chamar('POST', `customer-portal/int/v1/orders/${idNaUrl(estado.pedido)}/cancel`, { token: estado.tokenA });
        if (r.status === 422 && /aceitou/i.test(mensagem(r))) {
            falha(
                `um motoboy aceitou o pedido de teste antes do cancelamento (${mensagem(r)}). A limpeza tenta pela chave de API; ` +
                    'se não der, cancele pela central e avise o motoboy'
            );
        }
        exigir(sucesso(r), () => `esperava HTTP 200, veio ${resumo(r)}`);
        const status = r.json?.order?.status;
        exigir(CANCELADOS.includes(status), `o status ficou ${status}`);
        estado.pedidoCancelado = true;
        return `status ${status}`;
    });

    await item(
        '13b',
        'Pedido cancelado sai dos pedidos abertos do app e grava a atividade',
        [[estado.pedidoCancelado, 'depende do item 13a']],
        async () => {
            let r;
            let pedido;
            for (let tentativa = 0; tentativa < 3; tentativa += 1) {
                if (tentativa) {
                    await pausa(2000);
                }
                r = await lerPedido(estado.pedido);
                pedido = r.json?.order;
                if (pedido && pedido.adhoc === false && pedido.dispatched === false) {
                    break;
                }
            }
            exigir(r.status === 200 && pedido, () => resumo(r));
            const problemas = [];
            if (!CANCELADOS.includes(pedido.status)) {
                problemas.push(`status ${pedido.status}`);
            }
            if (pedido.adhoc !== false) {
                problemas.push(`adhoc=${pedido.adhoc}: o pedido continua na lista de pedidos abertos do app do motoboy`);
            }
            if (pedido.dispatched !== false) {
                problemas.push(`dispatched=${pedido.dispatched}`);
            }
            let atividade = 'a resposta não traz tracking_statuses (atividade não conferida)';
            if (Array.isArray(pedido.tracking_statuses)) {
                if (pedido.tracking_statuses.some((t) => /cancel/i.test(`${t?.code ?? ''} ${t?.status ?? ''}`))) {
                    atividade = 'atividade de cancelamento gravada';
                } else {
                    problemas.push('o tracker não tem a atividade de cancelamento');
                }
            }
            exigir(!problemas.length, problemas.join('; '));
            return `adhoc e dispatched desligados; ${atividade}`;
        }
    );

    await item('6', 'B não vê o pedido de A na lista', [temPedido, ...deB], async () => {
        const ra = await chamar('GET', 'customer-portal/int/v1/orders?limit=100', { token: estado.tokenA });
        exigir(ra.status === 200 && Array.isArray(ra.json?.orders), () => `controle (A lista os próprios pedidos): ${resumo(ra)}`);
        exigir(ra.json.orders.some((o) => mesmoRegistro(o, estado.pedido)), 'controle: o pedido não aparece nem na lista de A');
        const rb = await chamar('GET', 'customer-portal/int/v1/orders?limit=100', { token: estado.tokenB });
        exigir(rb.status === 200 && Array.isArray(rb.json?.orders), () => `B lista os pedidos: ${resumo(rb)}`);
        const deA = rb.json.orders.filter((o) => ra.json.orders.some((a) => mesmoRegistro(o, a)));
        exigir(!deA.length, () => `B vê ${deA.length} pedido(s) de A: ${deA.map(idDe).join(', ')}`);
        return `B vê ${rb.json.orders.length} pedido(s), nenhum de A`;
    });

    await item('7', 'B não abre o pedido de A (404)', [temPedido, ...deB], async () => {
        const ra = await lerPedido(estado.pedido);
        exigir(ra.status === 200, () => `controle (A abre o próprio pedido): ${resumo(ra)}`);
        const ids = [...new Set([estado.pedido.public_id, estado.pedido.uuid].filter(Boolean))];
        for (const id of ids) {
            const rb = await chamar('GET', `customer-portal/int/v1/orders/${encodeURIComponent(id)}`, { token: estado.tokenB });
            if (sucesso(rb)) {
                falha(`B abriu o pedido de A pelo ${id}`);
            }
            esperarErro(rb, 404);
        }
        return ids.length > 1 ? 'pelo public_id e pelo uuid' : '';
    });

    await item('8', 'B não cancela o pedido de A (404)', [temPedido, ...deB], async () => {
        const antes = await lerPedido(estado.pedido);
        const statusAntes = antes.json?.order?.status;
        exigir(antes.status === 200 && statusAntes, () => `controle (A lê o pedido): ${resumo(antes)}`);
        const rb = await chamar('POST', `customer-portal/int/v1/orders/${idNaUrl(estado.pedido)}/cancel`, { token: estado.tokenB });
        const depois = await lerPedido(estado.pedido);
        const statusDepois = depois.json?.order?.status;
        if (sucesso(rb) || (!CANCELADOS.includes(statusAntes) && CANCELADOS.includes(statusDepois))) {
            falha(`B cancelou o pedido de A (${resumo(rb)}; status agora ${statusDepois})`);
        }
        // com o isolamento quebrado, o pedido já cancelado no item 13a daria 422 (encerrado), e não 404
        esperarErro(rb, 404);
        exigir(
            depois.status === 200 && statusDepois === statusAntes,
            () => `o status do pedido mudou de ${statusAntes} para ${statusDepois ?? resumo(depois)}`
        );
        return `continua ${statusDepois}`;
    });

    await item('9', 'B não vê o motoboy do pedido de A (404)', [temPedido, ...deB], async () => {
        const caminho = `int/v1/entregas/loja/pedidos/${idNaUrl(estado.pedido)}/motoboy`;
        const ra = await chamar('GET', caminho, { token: estado.tokenA });
        exigir(ra.status === 200, () => `controle (A pede o motoboy do próprio pedido): ${resumo(ra)}`);
        const rb = await chamar('GET', caminho, { token: estado.tokenB });
        if (sucesso(rb)) {
            falha(`B recebeu ${resumo(rb)}`);
        }
        esperarErro(rb, 404);
        return '';
    });

    await item(
        '14',
        'Aceite do pedido cancelado é barrado (400) e o pedido continua cancelado',
        [
            [cfg.CHAVE_API, 'sem CHAVE_API no teste-lojas.env'],
            [cfg.MOTOBOY_ID, 'sem MOTOBOY_ID no teste-lojas.env'],
            [/^driver_/.test(cfg.MOTOBOY_ID ?? ''), 'MOTOBOY_ID precisa ser o driver_… do motoboy'],
            [estado.pedidoCancelado, 'depende do item 13a'],
            [estado.pedido?.public_id, 'o pedido veio sem public_id'],
        ],
        async () => {
            const publicId = encodeURIComponent(estado.pedido.public_id);
            const r = await chamar('POST', `v1/orders/${publicId}/start`, { token: cfg.CHAVE_API, corpo: { assign: cfg.MOTOBOY_ID } });
            const depois = await lerPedido(estado.pedido);
            const status = depois.json?.order?.status;
            if (sucesso(r)) {
                const rc = await chamar('DELETE', `v1/orders/${publicId}/cancel`, { token: cfg.CHAVE_API });
                falha(
                    `ATENÇÃO: o aceite passou e o pedido voltou (status ${status}); cancelado de novo pela API v1: ${resumo(rc)}. ` +
                        'Avise o motoboy de teste'
                );
            }
            if (r.status === 401 || r.status === 403) {
                falha(`a API recusou a chave (${resumo(r)}): use uma chave de API da organização que não seja a do app do motoboy`);
            }
            if (r.status === 404) {
                falha(`${resumo(r)}: a chave não acha o pedido; provavelmente a CHAVE_API é de outra organização`);
            }
            esperarErro(r, 400, /cancelad/i);
            exigir(depois.status === 200 && CANCELADOS.includes(status), () => `depois do aceite barrado, o pedido está ${status ?? resumo(depois)}`);
            return mensagem(r);
        }
    );
}

/** Cancela o pedido se ainda estiver aberto: pelo portal (A) e, se não der, pela API v1 com a CHAVE_API. */
async function garantirCancelado(pedido) {
    const antes = await lerPedido(pedido);
    const status = antes.json?.order?.status;
    if (CANCELADOS.includes(status) || status === 'expired') {
        return `já estava ${status}`;
    }
    if (status === 'completed' || status === 'done') {
        falha(`um motoboy concluiu o pedido (${status}): tire-o do pagamento e da cobrança`);
    }

    const pelaLoja = await chamar('POST', `customer-portal/int/v1/orders/${idNaUrl(pedido)}/cancel`, { token: estado.tokenA });
    if (sucesso(pelaLoja) && CANCELADOS.includes(pelaLoja.json?.order?.status)) {
        return 'cancelado agora pelo portal';
    }
    let motivo = `o portal não cancelou (${resumo(pelaLoja)})`;

    if (cfg.CHAVE_API && pedido.public_id) {
        const pelaApi = await chamar('DELETE', `v1/orders/${encodeURIComponent(pedido.public_id)}/cancel`, { token: cfg.CHAVE_API });
        const depois = await lerPedido(pedido);
        if (CANCELADOS.includes(depois.json?.order?.status)) {
            return `cancelado agora pela API v1 (${motivo})`;
        }
        motivo += `; nem a API v1 (${resumo(pelaApi)})`;
    }
    falha(motivo);
}

async function limpeza() {
    if (!estado.tokenA || !estado.tentouCriarPedido) {
        return;
    }
    secao('Limpeza');

    // pedidos do teste ainda abertos na Loja A, pela nota: pega também o pedido criado cuja resposta não veio. Com uma
    // criação incerta, a lista é lida até 3 vezes, a cada 5 s, porque o pedido pode aparecer depois
    const tentativas = estado.pedidosIncertos ? 3 : 1;
    let listou = false;
    let achadosNaLista = 0;
    for (let tentativa = 1; tentativa <= tentativas; tentativa += 1) {
        if (tentativa > 1) {
            info(`procurando de novo o pedido da criação incerta em 5 s (leitura ${tentativa} de ${tentativas})`);
            await dormir(5000);
        }
        const lista = await chamar('GET', 'customer-portal/int/v1/orders?limit=100', { token: estado.tokenA });
        if (lista.status !== 200 || !Array.isArray(lista.json?.orders)) {
            info(`não deu para listar os pedidos da Loja A: ${resumo(lista)}`);
            continue;
        }
        listou = true;
        for (const pedido of lista.json.orders) {
            const chave = pedido.uuid || pedido.public_id;
            if (pedido.notes === NOTA_DO_PEDIDO && !ENCERRADOS.includes(pedido.status) && chave && !pedidosCriados.has(chave)) {
                pedidosCriados.set(chave, { uuid: pedido.uuid, public_id: pedido.public_id, origem: 'aberto na lista da Loja A' });
                achadosNaLista += 1;
            }
        }
        if (achadosNaLista >= estado.pedidosIncertos) {
            break;
        }
    }

    if (!listou && estado.pedidosIncertos) {
        registrar(
            'FALHOU',
            'limpeza',
            'Pedido de criação incerta não conferido',
            `a lista de pedidos não veio: confira no console os pedidos da ${nomeDaLojaA()} com a nota "${NOTA_DO_PEDIDO}" e cancele os abertos`
        );
    } else if (achadosNaLista < estado.pedidosIncertos) {
        info('a criação incerta não deixou pedido aberto na lista da Loja A: o pedido provavelmente não foi criado');
    }

    if (!pedidosCriados.size) {
        if (listou) {
            info('nenhum pedido do teste ficou aberto na Loja A');
        }
        return;
    }
    for (const pedido of pedidosCriados.values()) {
        try {
            info(`pedido ${idDe(pedido)} (${pedido.origem}): ${await garantirCancelado(pedido)}`);
        } catch (erro) {
            registrar(
                'FALHOU',
                'limpeza',
                `Pedido ${idDe(pedido)} (${pedido.origem}) continua aberto`,
                `${erro.message}. Cancele-o no console (Fleet-Ops → Pedidos) e avise os motoboys`
            );
        }
    }
}

/** Sai das sessões que o teste abriu (POST int/v1/auth/logout, como o console faz). Um erro aqui não importa. */
async function sairDasSessoes() {
    for (const token of [estado.tokenA, estado.tokenB, ...estado.outrosTokens]) {
        if (token) {
            await chamar('POST', 'int/v1/auth/logout', { token, tempoLimite: 10000 });
        }
    }
}

// ---------------------------------------------------------------------------------------------------------------
// Principal
// ---------------------------------------------------------------------------------------------------------------

async function principal() {
    if (typeof fetch !== 'function' || typeof FormData !== 'function' || typeof Blob !== 'function') {
        console.log('Este teste precisa do Node 18 ou mais novo (fetch nativo).');
        return 1;
    }

    const lido = lerConfiguracao(ARQUIVO_DE_CONFIGURACAO);
    if (lido.erro) {
        console.log(`Configuração: ${lido.erro}`);
        return 1;
    }
    cfg = lido.config;
    for (const chave of CHAVES_SECRETAS) {
        guardarSegredo(cfg[chave], chave === 'CHAVE_API');
    }
    for (const aviso of lido.avisos) {
        console.log(`Configuração: ${aviso}`);
    }
    const faltando = CHAVES_OBRIGATORIAS.filter((chave) => !cfg[chave]);
    if (faltando.length) {
        console.log(`Configuração: faltam no ${ARQUIVO_DE_CONFIGURACAO}: ${faltando.join(', ')}`);
        return 1;
    }
    const api = validarApi(cfg.API);
    if (api.erro) {
        console.log(`Configuração: ${api.erro}`);
        return 1;
    }
    API = api.api;

    const sim = (valor) => (valor ? 'sim' : 'não');
    console.log('Teste de isolamento do portal da loja');
    console.log(`API: ${API}`);
    console.log(`Configuração: ${path.relative(process.cwd(), ARQUIVO_DE_CONFIGURACAO) || ARQUIVO_DE_CONFIGURACAO}`);
    console.log(
        `Opcionais: chave de API e motoboy (item 14): ${sim(cfg.CHAVE_API && cfg.MOTOBOY_ID)}; ` +
            `loja desativada (16): ${sim(cfg.LOJA_DESATIVADA_EMAIL && cfg.LOJA_DESATIVADA_SENHA)}; ` +
            `usuário em duas lojas (17): ${sim(cfg.LOJA_DUPLA_EMAIL && cfg.LOJA_DUPLA_SENHA)}`
    );

    try {
        // a preparação para o teste antes do item 1 se A ou B não forem lojas de teste
        if (await etapaPreparacao()) {
            await etapaEnderecos();
            await etapaAcessoDeB();
            await etapaFoto();
            await etapaOutrosUsuarios();
            await etapaPedido();
        }
    } catch (erro) {
        if (!(erro instanceof Interrompido)) {
            registrar('FALHOU', 'script', 'Erro inesperado no teste', descreverErro(erro));
        }
    } finally {
        // todo pedido criado pelo teste termina cancelado, inclusive se algo falhou ou o teste foi interrompido
        try {
            await limpeza();
        } catch (erro) {
            registrar('FALHOU', 'limpeza', 'Erro inesperado na limpeza', `${descreverErro(erro)}. Confira os pedidos da loja de teste no console`);
        }
        try {
            await sairDasSessoes();
        } catch {
            // sair da sessão é só arrumação
        }
    }

    const { PASSOU: passou, FALHOU: falhou, PULADO: pulado } = contagem;
    console.log(
        `\nResumo: ${passou} ${passou === 1 ? 'passou' : 'passaram'}, ${falhou} ${falhou === 1 ? 'falhou' : 'falharam'}, ` +
            `${pulado} ${pulado === 1 ? 'pulado' : 'pulados'}${interrompido ? ' (interrompido antes do fim)' : ''}.`
    );
    return falhou > 0 || interrompido ? 1 : 0;
}

principal().then(
    (codigo) => {
        process.exitCode = codigo;
    },
    (erro) => {
        console.log(`Erro inesperado: ${descreverErro(erro)}`);
        process.exitCode = 1;
    }
);
