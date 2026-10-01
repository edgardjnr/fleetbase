#!/usr/bin/env node
/**
 * i18n-check: validação das traduções pt-BR do Console e das extensões.
 *
 * Uso (na raiz do repo):
 *   node scripts/i18n-check.cjs                 # todos os módulos
 *   node scripts/i18n-check.cjs fleetops ledger # só esses (nomes de packages/ ou "console")
 *
 * Verifica, por módulo:
 *   1. translations/en-us.yaml e pt-br.yaml abrem sem erro
 *   2. chaves que existem em en-us e faltam em pt-br
 *   3. todo .hbs do módulo compila (sintaxe Glimmer)
 *   4. toda chave literal usada em {{t "x"}}, (t "x"), intl.t('x') existe em en-us E pt-br
 *      de algum módulo (as traduções são globais e mescladas no build)
 *   5. textos fixos que ainda restam nos templates (heurística, só informativo)
 */
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const pnpmDir = path.join(root, 'console', 'node_modules', '.pnpm');
const pick = (prefix) => {
    const dir = fs.readdirSync(pnpmDir).filter((d) => d.startsWith(prefix)).sort().pop();
    if (!dir) throw new Error(`rode "pnpm install" em console/ (não achei ${prefix})`);
    return path.join(pnpmDir, dir, 'node_modules', prefix.replace(/\+/g, '/').replace(/@[^@]*$/, ''));
};
const yaml = require(pick('js-yaml@4'));
const { preprocess } = require(pick('@glimmer+syntax@0.84'));

const MODULES = {
    console: { dir: 'console', src: ['console/app'] },
};
for (const p of fs.readdirSync(path.join(root, 'packages'))) {
    const base = path.join('packages', p);
    if (fs.existsSync(path.join(root, base, 'addon'))) MODULES[p] = { dir: base, src: [path.join(base, 'addon')] };
}

const flatten = (obj, prefix = '', out = {}) => {
    for (const [k, v] of Object.entries(obj || {})) {
        const key = prefix ? `${prefix}.${k}` : k;
        if (v && typeof v === 'object' && !Array.isArray(v)) flatten(v, key, out);
        else out[key] = v;
    }
    return out;
};
const loadYaml = (file) => {
    if (!fs.existsSync(file)) return { data: {}, missing: true };
    try {
        return { data: flatten(yaml.load(fs.readFileSync(file, 'utf8'))) };
    } catch (e) {
        return { data: {}, error: e.message.split('\n')[0] };
    }
};
const walk = (dir, exts, out = []) => {
    if (!fs.existsSync(dir)) return out;
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
        const p = path.join(dir, e.name);
        if (e.isDirectory()) {
            if (e.name !== 'node_modules') walk(p, exts, out);
        } else if (exts.some((x) => e.name.endsWith(x))) out.push(p);
    }
    return out;
};

// all translations of all modules (merged globally at build time)
const allEn = {};
const allPt = {};
const perModule = {};
for (const [name, m] of Object.entries(MODULES)) {
    const tdir = path.join(root, m.dir, 'translations');
    const en = loadYaml(fs.existsSync(path.join(tdir, 'en-us.yml')) ? path.join(tdir, 'en-us.yml') : path.join(tdir, 'en-us.yaml'));
    const pt = loadYaml(path.join(tdir, 'pt-br.yaml'));
    perModule[name] = { en, pt };
    Object.assign(allEn, en.data);
    Object.assign(allPt, pt.data);
}

const only = process.argv.slice(2);
let failed = false;
const keyRe = [/\(t\s+"([^"]+)"/g, /\{\{t\s+"([^"]+)"/g, /\(t\s+'([^']+)'/g, /\{\{t\s+'([^']+)'/g, /intl\.t\(\s*['"]([^'"]+)['"]/g, /\bt\(\s*['"]([a-z0-9-]+(?:\.[a-z0-9_-]+)+)['"]/g];

for (const [name, m] of Object.entries(MODULES)) {
    if (only.length && !only.includes(name)) continue;
    const { en, pt } = perModule[name];
    const problems = [];
    if (en.error) problems.push(`en-us.yaml inválido: ${en.error}`);
    if (pt.error) problems.push(`pt-br.yaml inválido: ${pt.error}`);
    const missingPt = Object.keys(en.data).filter((k) => !(k in pt.data));
    const sameAsEn = Object.keys(en.data).filter((k) => pt.data[k] === en.data[k] && typeof en.data[k] === 'string' && /[a-z]{4,}/i.test(en.data[k]));

    const hbs = m.src.flatMap((d) => walk(path.join(root, d), ['.hbs']));
    const js = m.src.flatMap((d) => walk(path.join(root, d), ['.js', '.gjs', '.ts']));
    const syntaxErrors = [];
    let hardcoded = 0;
    const hardFiles = {};
    const usedKeys = new Map();
    for (const f of hbs) {
        const src = fs.readFileSync(f, 'utf8');
        try {
            preprocess(src, { mode: 'codemod' });
        } catch (e) {
            syntaxErrors.push(`${path.relative(root, f)}: ${e.message.split('\n')[0]}`);
        }
        const noMustache = src.replace(/\{\{!--[\s\S]*?--\}\}|\{\{![\s\S]*?\}\}/g, '').replace(/\{\{[\s\S]*?\}\}/g, ' ');
        const texts = (noMustache.match(/>([^<>]+)</g) || []).map((t) => t.slice(1, -1).trim()).filter((t) => /[A-Za-z]{2,}/.test(t) && !/^&[a-z]+;$/.test(t));
        const attrs = src.match(/@(?:title|label|placeholder|text|helpText|emptyStateMessage|subtitle|description|buttonText|tooltip|tooltipText|confirmText|declineText)="[^"{]*[A-Za-z][^"{]*"/g) || [];
        const n = texts.length + attrs.length;
        if (n) hardFiles[path.relative(root, f)] = n;
        hardcoded += n;
    }
    for (const f of [...hbs, ...js]) {
        const src = fs.readFileSync(f, 'utf8');
        for (const re of keyRe) {
            re.lastIndex = 0;
            let mm;
            while ((mm = re.exec(src))) if (!mm[1].includes('${')) usedKeys.set(mm[1], path.relative(root, f));
        }
    }
    const undefinedKeys = [...usedKeys].filter(([k]) => !(k in allEn) || !(k in allPt));

    console.log(`\n== ${name}`);
    console.log(`  chaves en-us: ${Object.keys(en.data).length} | faltando em pt-br: ${missingPt.length} | pt-br igual ao inglês: ${sameAsEn.length}`);
    console.log(`  templates: ${hbs.length} | erros de sintaxe: ${syntaxErrors.length} | textos fixos (heurística): ${hardcoded}`);
    console.log(`  chaves usadas no código: ${usedKeys.size} | sem tradução em en-us/pt-br: ${undefinedKeys.length}`);
    for (const p of problems) console.log(`  ERRO ${p}`);
    for (const s of syntaxErrors.slice(0, 20)) console.log(`  ERRO sintaxe ${s}`);
    for (const [k, f] of undefinedKeys.slice(0, 30)) console.log(`  ERRO chave sem tradução: ${k}  (${f}) en=${k in allEn} pt=${k in allPt}`);
    if (undefinedKeys.length > 30) console.log(`  ... +${undefinedKeys.length - 30} chaves`);
    if (process.env.VERBOSE) {
        for (const k of missingPt) console.log(`  falta pt-br: ${k}`);
        for (const [f, n] of Object.entries(hardFiles).sort((a, b) => b[1] - a[1])) console.log(`  fixos ${n}: ${f}`);
    }
    if (problems.length || syntaxErrors.length || undefinedKeys.length || missingPt.length) failed = true;
}
process.exit(failed ? 1 : 0);
