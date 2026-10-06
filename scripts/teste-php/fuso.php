<?php

// Fuso do servidor (horário de Brasília): a configuração (FusoDoServidor, AppServiceProvider, config/app.php), os comandos
// agendados do Fleet-Ops sem o date_default_timezone_set('UTC') (App\Console\Commands\Entregas\Fuso) e as datas da
// integração iFood no fuso do app. Ver CLAUDE.md, "Fuso (horário de Brasília)".
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/fuso.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/fixtures-ifood.php';

use App\Support\Entregas\FusoDoServidor;
use App\Support\Entregas\Ifood\EventosIfood;
use App\Support\Entregas\Ifood\PedidoDoIfood;
use App\Support\Entregas\Ifood\VinculosIfood;

// como na produção: o Laravel faz date_default_timezone_set(config('app.timezone'))
date_default_timezone_set('America/Sao_Paulo');

const BRASILIA = 'America/Sao_Paulo';

/** Os tokens do PHP sem espaços e comentários, em texto (para comparar código). */
function tokensSemEspaco(string $codigo): array
{
    $saida = [];
    foreach (token_get_all($codigo) as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
            continue;
        }
        $saida[] = is_array($token) ? $token[1] : $token;
    }

    return $saida;
}

/** O método handle() da classe do arquivo, em tokens (do "public function handle" até a chave que fecha). */
function tokensDoHandle(string $arquivo): array
{
    $tokens = tokensSemEspaco('<?php ' . preg_replace('/^<\?php/', '', file_get_contents($arquivo)));
    foreach ($tokens as $i => $token) {
        if ($token === 'function' && ($tokens[$i + 1] ?? null) === 'handle') {
            $nivel = 0;
            for ($j = $i; $j < count($tokens); $j++) {
                if ($tokens[$j] === '{' || $tokens[$j] === '${' || $tokens[$j] === '{$') {
                    $nivel++;
                } elseif ($tokens[$j] === '}') {
                    $nivel--;
                    if ($nivel === 0) {
                        return array_slice($tokens, $i, $j - $i + 1);
                    }
                }
            }
        }
    }

    return [];
}

/** Chama date_default_timezone_set (o nome seguido de "(", fora de comentários e textos)? */
function chamaTrocaDeFuso(string $arquivo): bool
{
    $tokens = array_values(array_filter(token_get_all(file_get_contents($arquivo)), fn ($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    foreach ($tokens as $i => $token) {
        if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) && ltrim($token[1], '\\') === 'date_default_timezone_set' && ($tokens[$i + 1] ?? null) === '(') {
            return true;
        }
    }

    return false;
}

function arquivosPhp(string $pasta): array
{
    $arquivos = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pasta, FilesystemIterator::SKIP_DOTS)) as $arquivo) {
        if ($arquivo->getExtension() === 'php') {
            $arquivos[] = $arquivo->getPathname();
        }
    }
    sort($arquivos);

    return $arquivos;
}

echo '== Configuração (config/app.php, AppServiceProvider, FusoDoServidor)' . PHP_EOL;
$app = file_get_contents('/repo/api/config/app.php');
confere(preg_match("/'timezone'\s*=>\s*'America\/Sao_Paulo'/", $app) === 1, "config/app.php: 'timezone' => 'America/Sao_Paulo'");
$provedor = file_get_contents('/repo/api/app/Providers/AppServiceProvider.php');
preg_match('/public function register\(\)\s*\{(.*?)\n    \}/s', $provedor, $register);
confere(str_contains($register[1] ?? '', '$this->configurarFuso();'), 'o register() configura o fuso (antes de o core abrir o banco, no boot)');
confere(str_contains($provedor, 'Date::useCallable(') && str_contains($provedor, 'FusoDoServidor::paraOFusoDoApp('), 'toda data da fachada Date passa pelo FusoDoServidor::paraOFusoDoApp');

$conexoes = ['mysql' => ['driver' => 'mysql'], 'sandbox' => ['driver' => 'mysql'], 'sqlite' => ['driver' => 'sqlite']];
confere(FusoDoServidor::configuracoesDoBanco($conexoes, '-03:00') === ['database.connections.mysql.timezone' => '-03:00', 'database.connections.sandbox.timezone' => '-03:00'], 'fuso no mysql e no sandbox; o storefront só se existir; outras conexões ficam');
$conexoes['storefront'] = ['driver' => 'mysql'];
confere(array_keys(FusoDoServidor::configuracoesDoBanco($conexoes, '-03:00')) === ['database.connections.mysql.timezone', 'database.connections.sandbox.timezone', 'database.connections.storefront.timezone'], 'com o storefront instalado, nele também');
confere(FusoDoServidor::configuracoesDoBanco(['mysql' => 'url'], '-03:00') === [], 'chave que não é uma conexão (array) fica de fora');

putenv('DB_TIMEZONE');
confere(FusoDoServidor::fusoDoBanco() === '-03:00', 'sem DB_TIMEZONE: -03:00');
putenv('DB_TIMEZONE=+00:00');
confere(FusoDoServidor::fusoDoBanco() === '+00:00', 'DB_TIMEZONE troca o fuso da sessão');
putenv('DB_TIMEZONE=  ');
confere(FusoDoServidor::fusoDoBanco() === '-03:00', 'DB_TIMEZONE em branco: -03:00');
putenv('DB_TIMEZONE');

// o Eloquent grava a data formatando no fuso do próprio objeto (HasAttributes::fromDateTime): o ISO com "Z" do console
// (scheduled_at do Ember Data) virava a hora UTC. Com o Date::useCallable, a data criada passa para o fuso do app
$doConsole = new DateTime('2026-10-06T01:10:00.000Z');
$gravada   = FusoDoServidor::paraOFusoDoApp($doConsole, BRASILIA);
confere($gravada->format('Y-m-d H:i:s') === '2026-10-05 22:10:00' && $gravada->getTimestamp() === (new DateTime('2026-10-06T01:10:00Z'))->getTimestamp(), 'ISO com Z do console vira a hora de Brasília, no mesmo instante (01:10Z → 22:10)');
$imutavel   = new DateTimeImmutable('2026-10-05T12:00:00+00:00');
$convertida = FusoDoServidor::paraOFusoDoApp($imutavel, BRASILIA);
confere($convertida->format('H:i P') === '09:00 -03:00' && $imutavel->format('H:i P') === '12:00 +00:00', 'data imutável: devolve uma nova, sem mexer na original');
$semFuso = DateTime::createFromFormat('Y-m-d H:i:s', '2026-10-05 22:10:00');
confere(FusoDoServidor::paraOFusoDoApp($semFuso, BRASILIA)->format('Y-m-d H:i:s') === '2026-10-05 22:10:00', 'texto sem fuso (lido do banco) fica como está');
confere(FusoDoServidor::paraOFusoDoApp(false, BRASILIA) === false && FusoDoServidor::paraOFusoDoApp(null, BRASILIA) === null && FusoDoServidor::paraOFusoDoApp('x', BRASILIA) === 'x', 'o que não é data passa como veio');

echo '== Comandos agendados sem date_default_timezone_set' . PHP_EOL;
$comPhpEmUtc = array_values(array_filter(arquivosPhp('/repo/api/app'), 'chamaTrocaDeFuso'));
confere($comPhpEmUtc === [], 'nada no api/app chama date_default_timezone_set' . ($comPhpEmUtc ? ': ' . implode(', ', $comPhpEmUtc) : ''));

preg_match('/COMANDOS_SEM_UTC = \[(.*?)\];/s', $provedor, $bloco);
preg_match_all('/(\w+)::class\s*=>\s*(\w+)::class/', $bloco[1] ?? '', $pares, PREG_SET_ORDER);
$trocas = [];
foreach ($pares as $par) {
    $trocas[$par[1]] = $par[2];
}
confere($trocas === [
    'DispatchOrders'             => 'DespacharPedidosAgendados',
    'TrackOrderDistanceAndTime'  => 'AtualizarEstimativasDosPedidos',
    'ProcessMaintenanceTriggers' => 'ProcessarGatilhosDeManutencao',
    'SendMaintenanceReminders'   => 'EnviarLembretesDeManutencao',
], 'o AppServiceProvider troca os quatro comandos do Fleet-Ops');
confere(str_contains($provedor, 'foreach (static::COMANDOS_SEM_UTC as $original => $nosso)') && str_contains($provedor, '$this->app->bind($original, $nosso);'), 'com bind, como o fleetops:dispatch-adhoc (o agendador resolve o comando pelo container)');

$pastaFleetOps = '/repo/packages/fleetops/server/src/Console/Commands/';
foreach ($trocas as $original => $nosso) {
    $arquivoNosso    = '/repo/api/app/Console/Commands/Entregas/Fuso/' . $nosso . '.php';
    $arquivoOriginal = $pastaFleetOps . $original . '.php';
    $codigo          = file_get_contents($arquivoNosso);
    confere(str_contains($codigo, 'use Fleetbase\FleetOps\Console\Commands\\' . $original . ';') && str_contains($codigo, "class {$nosso} extends {$original}"), "{$nosso} estende o {$original}");

    // o handle() é o do original sem a linha do fuso: se o Fleet-Ops mudar o original, este teste avisa
    $doOriginal = tokensDoHandle($arquivoOriginal);
    $semFuso    = [];
    for ($i = 0; $i < count($doOriginal); $i++) {
        if ($doOriginal[$i] === 'date_default_timezone_set' && array_slice($doOriginal, $i + 1, 4) === ['(', "'UTC'", ')', ';']) {
            $i += 4;
            continue;
        }
        $semFuso[] = $doOriginal[$i];
    }
    confere(chamaTrocaDeFuso($arquivoOriginal), "o {$original} do Fleet-Ops ainda fixa UTC (se não fixar mais, a troca pode sair)");
    confere($semFuso !== [] && tokensDoHandle($arquivoNosso) === $semFuso, "o handle() do {$nosso} é o do {$original} sem o date_default_timezone_set");
}

// nenhum outro comando do Fleet-Ops ou do core fixa UTC: o dispatch-adhoc já roda o ReenviarPedidosAbertos
$outros = [];
foreach (array_merge(arquivosPhp($pastaFleetOps), arquivosPhp('/repo/packages/core-api/src/Console/Commands')) as $arquivo) {
    $classe = basename($arquivo, '.php');
    if (chamaTrocaDeFuso($arquivo) && !isset($trocas[$classe]) && $classe !== 'DispatchAdhocOrders') {
        $outros[] = $classe;
    }
}
confere($outros === [], 'nenhum outro comando do Fleet-Ops ou do core chama date_default_timezone_set' . ($outros ? ': ' . implode(', ', $outros) : ''));
confere(str_contains($provedor, '$this->app->bind(DispatchAdhocOrders::class, ReenviarPedidosAbertos::class);'), 'o fleetops:dispatch-adhoc continua trocado pelo ReenviarPedidosAbertos');

echo '== Relatório, extrato e ganhos: período no fuso do app' . PHP_EOL;
foreach (['MotoboyController', 'PagamentoMotoboysController', 'PortalLojaController'] as $controller) {
    $codigo = file_get_contents('/repo/api/app/Http/Controllers/Entregas/' . $controller . '.php');
    confere(!str_contains($codigo, '->utc()') && substr_count($codigo, '->setTimezone(date_default_timezone_get())') === 2, "{$controller}: início e fim do período no fuso do app (sem ->utc())");
}
confere(!str_contains(file_get_contents('/repo/api/app/Support/Entregas/CalculoEntregas.php'), "'UTC'"), 'CalculoEntregas: a conclusão (texto do banco) é lida no fuso do app');

echo '== iFood: datas gravadas no fuso do app' . PHP_EOL;
$agora = new DateTimeImmutable('2026-10-05 18:00:00', new DateTimeZone('UTC')); // 15:00 em Brasília

$dados = PedidoDoIfood::mapear(pedidoAgendado(), -21.1775, -47.8103, $agora);
confere($dados['agendado'] === true && $dados['scheduled_at'] === '2026-10-05 16:20:00', "agendado (janela às 20:00Z): scheduled_at do Order às 16:20 de Brasília ({$dados['scheduled_at']})");
confere($dados['linha']['despachar_em'] === '2026-10-05 16:20:00', 'despachar_em também às 16:20 de Brasília (o agendador compara com o now() do app)');

$dados = PedidoDoIfood::mapear(pedidoEmDinheiroComTroco(), -21.1775, -47.8103, $agora);
confere($dados['linha']['despachar_em'] === '2026-10-05 15:00:00', 'imediato: despachar_em = agora em Brasília (15:00)');
confere($dados['linha']['telefone_expira_em'] === '2026-10-05 18:58:00', 'expiração do localizador (21:58Z) às 18:58 de Brasília');
confere(PedidoDoIfood::paraOBanco(null) === null && PedidoDoIfood::paraOBanco(new DateTimeImmutable('2026-10-05T03:00:00Z')) === '2026-10-05 00:00:00', 'paraOBanco: nulo fica nulo; 03:00Z = meia-noite em Brasília');

$evento = ['id' => 'evento-1', 'orderId' => 'pedido-1', 'merchantId' => 'loja-1', 'code' => 'PLC', 'createdAt' => '2026-10-05T18:26:35.864Z'];
$linha  = EventosIfood::paraGravar($evento, '2026-10-05 15:30:00');
confere($linha['criado_no_ifood'] === '2026-10-05 15:26:35.864', "criado_no_ifood no fuso do app, com milissegundos ({$linha['criado_no_ifood']})");
confere(EventosIfood::instante($linha['criado_no_ifood']) === EventosIfood::instante('2026-10-05T18:26:35.864Z'), 'o texto gravado (sem fuso) lido de volta dá o mesmo instante');

// o access token vence às 16:00 de Brasília (texto do banco); o relógio dos stubs está em 18:00Z = 15:00 de Brasília
$segundos = new ReflectionMethod(VinculosIfood::class, 'segundosAteVencer');
$segundos->setAccessible(true);
confere($segundos->invoke(null, (object) ['expira_em' => '2026-10-05 16:00:00']) === 3600, 'validade do token (expira_em) lida no fuso do app: falta 1 h');

$longas = [];
foreach (array_merge(['/repo/api/app/Support/Entregas/FusoDoServidor.php'], arquivosPhp('/repo/api/app/Console/Commands/Entregas/Fuso')) as $arquivo) {
    foreach (file($arquivo) as $numero => $linhaDoArquivo) {
        if (preg_match('/^\s*(\*|\/\/)/', $linhaDoArquivo) && mb_strlen(rtrim($linhaDoArquivo, "\r\n")) > 120) {
            $longas[] = basename($arquivo) . ':' . ($numero + 1);
        }
    }
}
confere($longas === [], 'nenhum comentário com mais de 120 colunas nos arquivos novos' . ($longas ? ' (' . implode(', ', $longas) . ')' : ''));

resumo();
