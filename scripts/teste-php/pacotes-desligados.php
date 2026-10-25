<?php

// Pacotes do Fleetbase sem uso fora do package:discover (api/composer.json, extra.laravel.dont-discover): storefront,
// ledger, registry-bridge, ai, valhalla, vroom e cashier. Eles continuam instalados (composer.lock); só os providers não
// sobem. Confere a lista, o content-hash do composer.lock (sem ele em dia, o composer install avisa que o lock está
// velho), a conexão do Storefront que o mysql:createdb do deploy.sh ainda pede (ConexaoDoStorefront), a rota própria
// ~registry/v1/engines e o deploy.sh sem o registry:init.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/pacotes-desligados.php

require '/repo/api/app/Support/Entregas/ConexaoDoStorefront.php';

use App\Support\Entregas\ConexaoDoStorefront;

$falhas = 0;
function confere(bool $ok, string $descricao): void
{
    if (!$ok) {
        $GLOBALS['falhas']++;
    }
    echo ($ok ? 'PASSA ' : 'FALHA ') . $descricao . PHP_EOL;
}

function semCr(string $texto): string
{
    return str_replace("\r\n", "\n", $texto);
}

const DESLIGADOS = [
    'fleetbase/storefront-api',
    'fleetbase/ledger-api',
    'fleetbase/registry-bridge',
    'fleetbase/ai',
    'fleetbase/valhalla-api',
    'fleetbase/vroom-api',
    'laravel/cashier',
];

echo '== composer.json e composer.lock' . PHP_EOL;
$composer = json_decode(file_get_contents('/repo/api/composer.json'), true);
$travado  = json_decode(file_get_contents('/repo/api/composer.lock'), true);
$naoDescobre = $composer['extra']['laravel']['dont-discover'] ?? null;
confere($naoDescobre === DESLIGADOS, 'dont-discover: ' . implode(', ', (array) $naoDescobre));

$instalados = [];
foreach ($travado['packages'] ?? [] as $pacote) {
    $instalados[$pacote['name']] = $pacote;
}
foreach (DESLIGADOS as $nome) {
    confere(isset($instalados[$nome]['extra']['laravel']['providers']), "{$nome} segue no composer.lock e tem provider para o package:discover pular");
}
foreach (['fleetbase/core-api', 'fleetbase/fleetops-api', 'fleetbase/customer-portal-api'] as $nome) {
    confere(!in_array($nome, $naoDescobre ?? [], true), "{$nome} continua descoberto");
}
// o portal confere só o class_exists do provider do Ledger (o autoload continua com a classe)
$portal = file_get_contents('/repo/packages/customer-portal/server/src/Providers/CustomerPortalServiceProvider.php');
confere(str_contains($portal, 'if (!class_exists(LedgerServiceProvider::class))') && !str_contains($portal, '$this->app->register(LedgerServiceProvider'), 'o customer-portal só exige a classe do Ledger, não registra o provider dele');

// o content-hash como o Composer calcula (Locker::getContentHash): sem ele em dia, o composer install avisa que o lock
// está velho (e o composer validate falha)
$relevantes = [];
foreach (['name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide', 'minimum-stability', 'prefer-stable', 'repositories', 'extra'] as $chave) {
    if (array_key_exists($chave, $composer)) {
        $relevantes[$chave] = $composer[$chave];
    }
}
if (isset($composer['config']['platform'])) {
    $relevantes['config']['platform'] = $composer['config']['platform'];
}
ksort($relevantes);
confere(md5(json_encode($relevantes, 0)) === ($travado['content-hash'] ?? null), 'content-hash do composer.lock em dia com o composer.json');

echo '== ConexaoDoStorefront (mysql:createdb)' . PHP_EOL;
$mysql = ['driver' => 'mysql', 'host' => 'entregas-db', 'database' => 'fleetbase', 'username' => 'root', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'];
$conexao = ConexaoDoStorefront::configuracao(['mysql' => $mysql, 'sandbox' => ['database' => 'fleetbase_sandbox']]);
confere(($conexao['database'] ?? null) === 'fleetbase_storefront', 'sem a conexão: a mysql com o banco fleetbase_storefront');
confere(array_diff_key($conexao ?? [], ['database' => 1]) === array_diff_key($mysql, ['database' => 1]), 'o resto é o da mysql (host, usuário, charset, collation)');
confere(ConexaoDoStorefront::configuracao(['mysql' => $mysql, 'storefront' => ['database' => 'outro']]) === null, 'já existe (Storefront religado ou config:cache): fica como está');
confere(ConexaoDoStorefront::configuracao(['sandbox' => []]) === null, 'sem a mysql: nada');
confere((ConexaoDoStorefront::configuracao(['mysql' => ['database' => '']])['database'] ?? null) === 'fleetbase_storefront', 'mysql sem banco: fleetbase_storefront');

// o que o mysql:createdb lê: o extra.fleetbase.create-database dos pacotes instalados
$pedidos = [];
foreach ($travado['packages'] ?? [] as $pacote) {
    if (isset($pacote['extra']['fleetbase']['create-database'])) {
        $pedidos[] = $pacote['extra']['fleetbase']['create-database'];
    }
}
confere($pedidos === [ConexaoDoStorefront::NOME], 'o único banco extra que o mysql:createdb pede é o do storefront (' . implode(', ', $pedidos) . ')');

$provedor = semCr(file_get_contents('/repo/api/app/Providers/AppServiceProvider.php'));
preg_match('/public function register\(\)\s*\{(.*?)\n    \}/s', $provedor, $register);
$posConexao = strpos($register[1] ?? '', '$this->manterConexaoDoStorefront();');
$posFuso    = strpos($register[1] ?? '', '$this->configurarFuso();');
confere($posConexao !== false && $posFuso !== false && $posConexao < $posFuso, 'register(): a conexão do storefront antes do fuso');
confere(str_contains($provedor, 'use App\Support\Entregas\ConexaoDoStorefront;'), 'import do ConexaoDoStorefront');

echo '== deploy.sh' . PHP_EOL;
$deploy = semCr(file_get_contents('/repo/api/deploy.sh'));
confere(!preg_match('/^\s*php artisan registry:/m', $deploy), 'sem registry:init (o comando sumiu com o registry-bridge e o set -e pararia o deploy)');
confere((bool) preg_match('/^php artisan mysql:createdb$/m', $deploy), 'mysql:createdb continua');
confere(!preg_match('/^\s*php artisan (storefront|ledger|ai|registry):/m', $deploy), 'nenhum comando dos pacotes desligados');

echo '== ~registry/v1/engines' . PHP_EOL;
$rotas = semCr(file_get_contents('/repo/api/app/Providers/RouteServiceProvider.php'));
confere(str_contains($rotas, 'Route::get(ProtegerPortalLoja::CAMINHO_DAS_EXTENSOES, fn () => response()->json([]));'), 'rota própria GET ~registry/v1/engines → []');
confere(str_contains($rotas, 'use App\Http\Middleware\ProtegerPortalLoja;'), 'import do ProtegerPortalLoja');
confere(str_contains(file_get_contents('/repo/api/app/Http/Middleware/ProtegerPortalLoja.php'), "CAMINHO_DAS_EXTENSOES = '~registry/v1/engines'"), 'o caminho é o que o console pede');
$console = file_get_contents('/repo/packages/ember-core/addon/utils/load-installed-extensions.js');
confere(str_contains($console, "fleetbaseApiFetch('GET', 'engines', {}, { namespace: '~registry/v1', fallbackResponse: [] })"), 'o ember-core pede GET engines em ~registry/v1');
foreach (['@fleetbase/fleetops-engine', '@fleetbase/dev-engine', '@fleetbase/iam-engine', '@fleetbase/customer-portal-engine'] as $engine) {
    confere(str_contains($console, "'{$engine}'"), "{$engine} está na lista fixa do ember-core (não depende da resposta)");
}

echo PHP_EOL . 'FALHAS: ' . $falhas . PHP_EOL;
