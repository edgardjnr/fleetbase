// Uso: PHP_PARSER_DIR=<pasta com node_modules/php-parser> node scripts/php-lint.cjs arquivo1.php [arquivo2.php...]
// Só sintaxe (não há PHP no Windows). Sai com 1 se algum arquivo não parsear.
const path = require('path');
const fs = require('fs');
const Engine = require(path.join(process.env.PHP_PARSER_DIR || '.', 'node_modules', 'php-parser'));
const parser = new Engine({ parser: { php8: true, extractDoc: true }, ast: { withPositions: true } });
let falhou = false;
for (const arquivo of process.argv.slice(2)) {
    try {
        parser.parseCode(fs.readFileSync(arquivo, 'utf8'), arquivo);
        console.log('ok   ', arquivo);
    } catch (e) {
        falhou = true;
        console.log('ERRO ', arquivo, '-', e.message);
    }
}
process.exit(falhou ? 1 : 0);
