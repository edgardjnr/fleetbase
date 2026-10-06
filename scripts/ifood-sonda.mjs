// Sonda da API do iFood (Merchant API, módulos Events e Logistics), para ver os eventos e o pedido REAIS da loja de teste
// antes de escrever a integração (etapa 2 da spec docs/superpowers/specs/2026-10-05-integracao-ifood-logistics-design.md).
//
// Credenciais: deploy/ifood-teste.env (ignorado pelo git), uma por linha, do app CENTRALIZADO de teste (Portal do
// Desenvolvedor > Meus aplicativos > Teste (C) > Credenciais):
//   IFOOD_CLIENT_ID=...
//   IFOOD_CLIENT_SECRET=...
//   IFOOD_MERCHANT=d5d191fa-2e43-4b86-aa9c-9f8c8b251378   (opcional; o padrão é a loja de teste)
// O segredo nunca é impresso.
//
// Uso, na raiz do repo:
//   node scripts/ifood-sonda.mjs                      polling a cada 30 s até Ctrl+C (grava, imprime e faz ack)
//   node scripts/ifood-sonda.mjs uma-vez              um polling só
//   node scripts/ifood-sonda.mjs pedido <orderId>     grava o pedido do Logistics e do Order
//   node scripts/ifood-sonda.mjs acao <orderId> <acao> ['<json>']
//   node scripts/ifood-sonda.mjs get </caminho>       ex.: get /merchant/v1.0/merchants
//   node scripts/ifood-sonda.mjs post </caminho> ['<json>']
//   No Git Bash, rode com MSYS_NO_PATHCONV=1 (senão o "/caminho" vira caminho do Windows).
//        POST /logistics/v1.0/orders/<orderId>/<acao> (assignDriver, goingToOrigin, arrivedAtOrigin, dispatch,
//        arrivedAtDestination, verifyDeliveryCode) e imprime a resposta
//
// Saída em deploy/ifood-sonda/ (ignorado pelo git; tem dados do cliente de teste):
//   eventos.jsonl                     um evento por linha, como veio da API
//   pedidos/<orderId>-<hora>-logistics.json e -order.json   detalhes do pedido a cada evento novo do pedido
//   acoes.jsonl                       ações enviadas e respostas
import { readFileSync, appendFileSync, mkdirSync, writeFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const RAIZ = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SAIDA = path.join(RAIZ, 'deploy', 'ifood-sonda');
const BASE = 'https://merchant-api.ifood.com.br';
const INTERVALO_MS = 30_000;
const LOJA_DE_TESTE = 'd5d191fa-2e43-4b86-aa9c-9f8c8b251378';

function lerCredenciais() {
    const arquivo = path.join(RAIZ, 'deploy', 'ifood-teste.env');
    if (!existsSync(arquivo)) {
        console.error(`Falta ${path.relative(RAIZ, arquivo)} (ver o cabeçalho deste script).`);
        process.exit(2);
    }
    const valores = {};
    for (const linha of readFileSync(arquivo, 'utf8').split(/\r?\n/)) {
        const m = linha.match(/^\s*([A-Z_]+)\s*=\s*(.*?)\s*$/);
        if (m) valores[m[1]] = m[2].replace(/^["']|["']$/g, '');
    }
    if (!valores.IFOOD_CLIENT_ID || !valores.IFOOD_CLIENT_SECRET) {
        console.error('deploy/ifood-teste.env precisa de IFOOD_CLIENT_ID e IFOOD_CLIENT_SECRET.');
        process.exit(2);
    }
    return { clientId: valores.IFOOD_CLIENT_ID, clientSecret: valores.IFOOD_CLIENT_SECRET, merchant: valores.IFOOD_MERCHANT || LOJA_DE_TESTE };
}

const cred = lerCredenciais();
mkdirSync(path.join(SAIDA, 'pedidos'), { recursive: true });

const hora = () => new Date().toISOString().replace(/[:.]/g, '-');
const agora = () => new Date().toLocaleTimeString('pt-BR');
const esperar = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

let token = null;
let tokenVenceEm = 0;

async function obterToken(forcar = false) {
    if (!forcar && token && Date.now() < tokenVenceEm - 5 * 60_000) return token;
    const corpo = new URLSearchParams({ grantType: 'client_credentials', clientId: cred.clientId, clientSecret: cred.clientSecret });
    const r = await fetch(`${BASE}/authentication/v1.0/oauth/token`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: corpo,
    });
    const texto = await r.text();
    if (!r.ok) throw new Error(`token: HTTP ${r.status} ${texto.slice(0, 300)}`);
    const dados = JSON.parse(texto);
    token = dados.accessToken;
    tokenVenceEm = Date.now() + (Number(dados.expiresIn) || 3600) * 1000;
    console.log(`[${agora()}] token novo (type=${dados.type}, expiresIn=${dados.expiresIn}, campos: ${Object.keys(dados).join(', ')})`);
    return token;
}

/** Chamada autenticada; em 401 renova o token uma vez e repete (como a homologação pede). */
async function api(metodo, caminho, { corpo, headers = {} } = {}) {
    for (let tentativa = 0; tentativa < 2; tentativa++) {
        const r = await fetch(`${BASE}${caminho}`, {
            method: metodo,
            headers: {
                Authorization: `Bearer ${await obterToken(tentativa > 0)}`,
                ...(corpo !== undefined ? { 'Content-Type': 'application/json' } : {}),
                ...headers,
            },
            body: corpo !== undefined ? JSON.stringify(corpo) : undefined,
        });
        if (r.status === 401 && tentativa === 0) {
            console.log(`[${agora()}] 401 em ${caminho}: renovando o token`);
            continue;
        }
        const texto = await r.text();
        let json = null;
        try {
            json = texto ? JSON.parse(texto) : null;
        } catch {
            // corpo não é JSON
        }
        return { status: r.status, json, texto, retryAfter: r.headers.get('retry-after') };
    }
}

async function gravarPedido(orderId, motivo) {
    const quando = hora();
    for (const [nome, caminho] of [
        ['logistics', `/logistics/v1.0/orders/${orderId}`],
        ['order', `/order/v1.0/orders/${orderId}`],
    ]) {
        const r = await api('GET', caminho);
        const arquivo = path.join(SAIDA, 'pedidos', `${orderId}-${quando}-${nome}.json`);
        writeFileSync(arquivo, JSON.stringify({ motivo, status: r.status, corpo: r.json ?? r.texto }, null, 2));
        console.log(`    ${nome}: HTTP ${r.status} → ${path.relative(RAIZ, arquivo)}`);
    }
}

const pedidosVistos = new Set();

async function pollingUmaVez() {
    const r = await api('GET', '/events/v1.0/events:polling?excludeHeartbeat=true', { headers: { 'x-polling-merchants': cred.merchant } });
    if (r.status === 204) {
        console.log(`[${agora()}] polling: nenhum evento`);
        return;
    }
    if (r.status !== 200 || !Array.isArray(r.json)) {
        console.log(`[${agora()}] polling: HTTP ${r.status}${r.retryAfter ? ` (Retry-After ${r.retryAfter})` : ''} ${r.texto.slice(0, 300)}`);
        return;
    }

    const eventos = [...r.json].sort((a, b) => String(a.createdAt).localeCompare(String(b.createdAt)));
    for (const e of eventos) {
        appendFileSync(path.join(SAIDA, 'eventos.jsonl'), JSON.stringify({ recebidoEm: new Date().toISOString(), ...e }) + '\n');
        const meta = e.metadata ? ` metadata{${Object.keys(e.metadata).join(', ')}}` : '';
        console.log(`[${agora()}] ${e.code} (${e.fullCode}) pedido ${String(e.orderId).slice(0, 8)} criado ${e.createdAt}${meta}`);
    }
    // grava os dados ANTES do ack (como a integração deve fazer); o pedido é relido a cada evento, para ver a evolução
    for (const orderId of new Set(eventos.map((e) => e.orderId).filter(Boolean))) {
        const motivo = eventos
            .filter((e) => e.orderId === orderId)
            .map((e) => e.code)
            .join(',');
        console.log(`  pedido ${orderId}${pedidosVistos.has(orderId) ? '' : ' (novo)'}: eventos ${motivo}`);
        pedidosVistos.add(orderId);
        await gravarPedido(orderId, motivo);
    }

    const ack = await api('POST', '/events/v1.0/events/acknowledgment', { corpo: eventos.map((e) => ({ id: e.id })) });
    console.log(`[${agora()}] ack de ${eventos.length} evento(s): HTTP ${ack.status}${ack.texto ? ` ${ack.texto.slice(0, 200)}` : ''}`);
}

async function enviarAcao(orderId, acao, corpoJson) {
    const corpo = corpoJson ? JSON.parse(corpoJson) : undefined;
    const r = await api('POST', `/logistics/v1.0/orders/${orderId}/${acao}`, { corpo });
    const registro = { enviadoEm: new Date().toISOString(), orderId, acao, corpo, status: r.status, retryAfter: r.retryAfter, resposta: r.json ?? r.texto };
    appendFileSync(path.join(SAIDA, 'acoes.jsonl'), JSON.stringify(registro) + '\n');
    console.log(`${acao}: HTTP ${r.status}${r.retryAfter ? ` (Retry-After ${r.retryAfter})` : ''} ${r.texto.slice(0, 500)}`);
}

const [comando, ...args] = process.argv.slice(2);

try {
    if (comando === 'pedido') {
        if (!args[0]) throw new Error('uso: pedido <orderId>');
        await gravarPedido(args[0], 'manual');
    } else if (comando === 'acao') {
        if (!args[0] || !args[1]) throw new Error("uso: acao <orderId> <acao> ['<json>']");
        await enviarAcao(args[0], args[1], args[2]);
    } else if (comando === 'get') {
        if (!args[0]) throw new Error('uso: get </caminho>');
        const r = await api('GET', args[0]);
        console.log(`GET ${args[0]}: HTTP ${r.status} ${r.texto.slice(0, 1500)}`);
    } else if (comando === 'post') {
        // simula a loja (ex.: post /order/v1.0/orders/<id>/confirm) ou testa outro endpoint
        if (!args[0]) throw new Error("uso: post </caminho> ['<json>']");
        const r = await api('POST', args[0], { corpo: args[1] ? JSON.parse(args[1]) : undefined });
        appendFileSync(path.join(SAIDA, 'acoes.jsonl'), JSON.stringify({ enviadoEm: new Date().toISOString(), caminho: args[0], status: r.status, resposta: r.json ?? r.texto }) + '\n');
        console.log(`POST ${args[0]}: HTTP ${r.status} ${r.texto.slice(0, 500)}`);
    } else if (comando === 'uma-vez') {
        await pollingUmaVez();
    } else {
        console.log(`Polling da loja ${cred.merchant} a cada ${INTERVALO_MS / 1000} s (Ctrl+C para parar). Saída em ${path.relative(RAIZ, SAIDA)}`);
        for (;;) {
            try {
                await pollingUmaVez();
            } catch (erro) {
                console.log(`[${agora()}] erro: ${erro.message}`);
            }
            await esperar(INTERVALO_MS);
        }
    }
} catch (erro) {
    console.error(erro.message, erro.cause ? `(${erro.cause.code ?? ''} ${erro.cause.message ?? ''})` : '');
    process.exit(1);
}
