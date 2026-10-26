#!/usr/bin/env node
/**
 * Entregas: troca, nos CSS do console, das engines e do ember-ui, as cores fixas do cinza azulado, do azul e do sky do
 * Fleetbase pela paleta do console (packages/ember-ui/tailwind.paleta-entregas.js). As classes do Tailwind (bg-gray-900,
 * text-blue-600, @apply…) já saem certas pela config; este script cuida do que está escrito à mão: #1f2937,
 * rgb(17 24 39 / …), rgba(37, 99, 235, 0.2)…
 *
 * Uso (na raiz do repo):
 *   node scripts/paleta-entregas.mjs            # troca e lista quantas cores mudaram por arquivo
 *   node scripts/paleta-entregas.mjs --conferir # só conta; sai com 1 se alguma cor antiga sobrou
 *
 * Rode de novo depois de atualizar o ember-ui, o fleetops ou outra engine pelo upstream, e depois de mudar um tom da
 * paleta (nesse caso, volte antes os CSS pelo git, porque o script só conhece as cores originais). É idempotente.
 * Cores de mapa e de calendário em JS/HBS (linhas de rota etc.) ficam de fora de propósito.
 */
import { readFileSync, writeFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const raiz = join(dirname(fileURLToPath(import.meta.url)), '..');
const { trocas } = createRequire(import.meta.url)(join(raiz, 'packages/ember-ui/tailwind.paleta-entregas.js'));

const PASTAS = [
    'console/app/styles',
    'packages/ember-ui/addon/styles',
    'packages/fleetops/addon/styles',
    'packages/customer-portal/addon/styles',
    'packages/iam-engine/addon/styles',
    'packages/dev-engine/addon/styles',
    'packages/ember-core/addon/styles',
];

const hexParaRgb = (hex) => [0, 2, 4].map((i) => parseInt(hex.slice(i, i + 2), 16));

const porHex = new Map(Object.entries(trocas).map(([de, para]) => [de.toLowerCase(), para.replace('#', '').toLowerCase()]));
const porRgb = new Map([...porHex].map(([de, para]) => [hexParaRgb(de).join(','), hexParaRgb(para)]));

function trocar(css) {
    let n = 0;
    css = css.replace(/#([0-9a-fA-F]{6})([0-9a-fA-F]{2})?\b/g, (todo, hex, alfa) => {
        const para = porHex.get(hex.toLowerCase());
        if (!para) return todo;
        n++;
        return '#' + para + (alfa || '');
    });
    // rgb(17 24 39 / …), rgb(17,24,39), rgba(17, 24, 39, 0.5)
    css = css.replace(/(rgba?\(\s*)(\d{1,3})(\s*,\s*|\s+)(\d{1,3})\3(\d{1,3})(?=\s*[,/)])/g, (todo, abre, r, sep, g, b) => {
        const para = porRgb.get([r, g, b].map(Number).join(','));
        if (!para) return todo;
        n++;
        return abre + para.join(sep);
    });
    return { css, n };
}

function arquivosCss(pasta) {
    const lista = [];
    let itens;
    try {
        itens = readdirSync(pasta);
    } catch {
        return lista;
    }
    for (const nome of itens) {
        const caminho = join(pasta, nome);
        if (statSync(caminho).isDirectory()) lista.push(...arquivosCss(caminho));
        else if (nome.endsWith('.css')) lista.push(caminho);
    }
    return lista;
}

const soConferir = process.argv.includes('--conferir');
let total = 0;
for (const pasta of PASTAS) {
    for (const arquivo of arquivosCss(join(raiz, pasta))) {
        const antes = readFileSync(arquivo, 'utf8');
        const { css, n } = trocar(antes);
        if (!n) continue;
        total += n;
        console.log(`${String(n).padStart(5)}  ${relative(raiz, arquivo).replaceAll('\\', '/')}`);
        if (!soConferir) writeFileSync(arquivo, css);
    }
}
console.log(soConferir ? `${total} cores antigas encontradas.` : `${total} cores trocadas.`);
if (soConferir && total > 0) process.exit(1);
