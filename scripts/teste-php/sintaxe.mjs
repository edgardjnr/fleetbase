// Confere a sintaxe de arquivos PHP com o PHP 8.2 da produção (php -l no php-wasm), sem PHP instalado.
// Uso, na raiz do repo (mesma instalação do rodar.mjs):
//   PHP_WASM_DIR=<pasta> node scripts/teste-php/sintaxe.mjs api/app/Arquivo.php [outros.php...]
// Sai com 1 se algum arquivo tiver erro de sintaxe.
import path from 'node:path';
import { statSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const raiz = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const arquivos = process.argv.slice(2);
if (!arquivos.length) {
    console.error('Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/sintaxe.mjs <arquivo.php>...');
    process.exit(2);
}

const carregar = createRequire(path.join(path.resolve(process.env.PHP_WASM_DIR || '.'), 'index.js'));
const { PHP } = carregar('@php-wasm/universal');
const { loadNodeRuntime, createNodeFsMountHandler } = carregar('@php-wasm/node');

let falhas = 0;
for (const arquivo of arquivos) {
    // o php -l aceita pasta (e "", que vira a raiz do repo) e responde OK sem ler nada
    if (!statSync(arquivo, { throwIfNoEntry: false })?.isFile()) {
        falhas++;
        console.log(`ERRO ${arquivo}\n     não é um arquivo (ou não existe)`);
        continue;
    }
    // um PHP por arquivo: depois do cli() a instância não serve mais (reusar devolve o resultado do primeiro arquivo, um falso OK)
    // processId: fora do Vitest, o php-wasm exige o id do processo (usado pelo gerenciador de travas de arquivo)
    const php = new PHP(await loadNodeRuntime('8.2', { emscriptenOptions: { processId: 1 } }));
    await php.mount('/repo', createNodeFsMountHandler(raiz));
    const caminho = '/repo/' + path.relative(raiz, path.resolve(arquivo)).split(path.sep).join('/');
    const resposta = await php.cli(['php', '-l', caminho]);
    // em erro, a última linha é a do stderr ("PHP Parse error: ... on line N"); o stdout termina em "Errors parsing"
    const saida = ((await resposta.stdoutText) + (await resposta.stderrText)).trim();
    const ok = (await resposta.exitCode) === 0;
    if (!ok) falhas++;
    console.log(`${ok ? 'OK  ' : 'ERRO'} ${arquivo}${ok ? '' : '\n     ' + saida.split('\n').pop()}`);
}
// process.exit: o php-wasm mantém o event loop vivo e o Node não terminaria sozinho
process.exit(falhas ? 1 : 0);
