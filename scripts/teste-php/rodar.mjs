// Testes de comportamento do PHP do api/app sem PHP instalado (não há PHP no Windows): roda um arquivo de teste no
// PHP 8.2 (php-wasm, a versão da produção) com o repositório montado em /repo.
//
// Instalação, uma vez, numa pasta fora do repo:
//   npm i --prefix <pasta> @php-wasm/node@3.1.54 @php-wasm/universal@3.1.54
// Uso, na raiz do repo:
//   PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/avisos-push.php
//
// O teste imprime PASSA/FALHA por caso e termina com "FALHAS: <n>"; sai com 1 se houver falha ou erro de PHP.
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const raiz = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const arquivo = process.argv[2];
if (!arquivo) {
    console.error('Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs <teste.php>');
    process.exit(2);
}

const carregar = createRequire(path.join(path.resolve(process.env.PHP_WASM_DIR || '.'), 'index.js'));
const { PHP } = carregar('@php-wasm/universal');
const { loadNodeRuntime, createNodeFsMountHandler } = carregar('@php-wasm/node');

const php = new PHP(await loadNodeRuntime('8.2', { emscriptenOptions: { processId: 1 } }));
await php.mount('/repo', createNodeFsMountHandler(raiz));

const caminho = '/repo/' + path.relative(raiz, path.resolve(arquivo)).split(path.sep).join('/');
let saida;
let erros;
try {
    const resultado = await php.run({ scriptPath: caminho });
    saida = resultado.text;
    erros = resultado.errors;
} catch (erro) {
    // o php-wasm lança quando o PHP termina com erro fatal; a saída vem junto
    saida = erro.response ? new TextDecoder().decode(erro.response.bytes) : '';
    erros = erro.response ? erro.response.errors : String(erro);
}
process.stdout.write(saida);
if (erros) process.stderr.write(erros);
process.exit(/FALHAS: 0\s*$/.test(saida) ? 0 : 1);
