<?php

// Saque Pix: os controllers do app (SaquesController), do console (SaquePixController) e da validação do Asaas
// (ValidacaoAsaasController), e as rotas no RouteServiceProvider.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-controllers.php

require __DIR__ . '/stubs-saque.php';

use App\Http\Controllers\Entregas\SaquePixController;
use App\Http\Controllers\Entregas\SaquesController;
use App\Http\Controllers\Entregas\ValidacaoAsaasController;
use App\Support\Entregas\Saques\ConexaoAsaas;
use App\Support\Entregas\Saques\Saques;
use Illuminate\Http\Request;
use Teste\Http as HttpFalso;
use Teste\UsuarioDoSaque;

const U1 = 'aaaaaaaa-0000-4000-8000-000000000001';

function doMotoboy(array $dados = [], string $usuario = 'user-driver-1'): Request
{
    session(['company' => 'empresa-1', 'user' => $usuario]);
    $request        = new Request($dados);
    $request->token = '12|abc';

    return $request;
}

function doAdmin(array $dados = [], string $tipo = 'admin'): Request
{
    session(['company' => 'empresa-1']);
    \Fleetbase\Support\Auth::$usuario = new UsuarioDoSaque('user-admin', $tipo);

    return new Request($dados);
}

echo '== App: pedir e acompanhar' . PHP_EOL;
centralComSaque();
$app = new SaquesController();
$semMotoboy        = new Request(['uuid' => U1, 'valor' => 50]);
$semMotoboy->token = 'flb_live_chave';
confere($app->pedir($semMotoboy)->status === 403, 'chave de API (sem "|"): 403');
$r = $app->pedir(doMotoboy(['uuid' => U1, 'valor' => 5]));
confere($r->status === 422 && $r->dados === ['errors' => ['O valor mínimo do saque é R$ 10,00.']], 'recusa: 422 com a mensagem');
asaas(200, ['data' => [['id' => 'wallet-1']]]);
asaas(200, ['id' => 'tra_1', 'status' => 'PENDING']);
$r = $app->pedir(doMotoboy(['uuid' => U1, 'valor' => '50,00']));
confere($r->status === 201 && ($r->dados['saque']['id'] ?? null) === U1 && $r->dados['saque']['situacao'] === 'processando' && $r->dados['saque']['chave'] === '*******4321', 'pedido aceito: 201, processando, chave escondida: ' . json_encode($r->dados, JSON_UNESCAPED_UNICODE));
confere(!str_contains(json_encode($r->dados), '11999994321'), 'a resposta nunca traz a chave Pix inteira');
$chamadas = count(HttpFalso::$chamadas);
$r = $app->pedir(doMotoboy(['uuid' => U1, 'valor' => 50]));
confere($r->status === 200 && $r->dados['saque']['id'] === U1 && count(HttpFalso::$chamadas) === $chamadas, 'repetido: 200, sem mandar de novo ao Asaas');
confere($app->mostrar(doMotoboy(), U1)->dados['saque']['situacao'] === 'processando', 'acompanhar: o saque dele');
motoboyComSaldo('driver-2', 'Maria Souza');
confere($app->mostrar(doMotoboy([], 'user-driver-2'), U1)->status === 404, 'saque de outro motoboy: 404');
confere($app->mostrar(doMotoboy(), 'aaaaaaaa-0000-4000-8000-000000000999')->status === 404, 'saque que não existe: 404');

echo '== Validação do Asaas' . PHP_EOL;
$validacao                         = new Request(['type' => 'TRANSFER', 'transfer' => ['id' => 'tra_1', 'value' => 50]]);
$validacao->cabecalhos             = ['asaas-access-token' => TOKEN_DE_TESTE];
$r = (new ValidacaoAsaasController())->validar($validacao);
confere($r->status === 200 && $r->dados === ['status' => 'APPROVED'], 'token no cabeçalho: APPROVED');
$validacao->cabecalhos = [];
confere((new ValidacaoAsaasController())->validar($validacao)->status === 401, 'sem cabeçalho: 401');

echo '== Console' . PHP_EOL;
reiniciarSaque();
$console = new SaquePixController();
confere($console->situacao(doAdmin([], 'user'))->status === 403 && $console->situacao(doAdmin([], 'user'))->dados === ['errors' => ['Somente administradores podem mexer no saque Pix.']], 'sem ser admin: 403');
confere($console->situacao(doAdmin())->dados === ['conectada' => false, 'ligada' => true, 'cofre' => true], 'situação sem conexão');
confere($console->conectar(doAdmin(['chave' => CHAVE_ASAAS, 'senha' => 'errada']))->dados === ['errors' => ['Senha incorreta.']] && HttpFalso::$chamadas === [], 'senha errada: 422 sem chamar o Asaas');
confere($console->conectar(doAdmin(['chave' => CHAVE_ASAAS]))->status === 422, 'sem senha: 422');
unlink(CHAVE_DO_COFRE);
confere($console->conectar(doAdmin(['chave' => CHAVE_ASAAS, 'senha' => 'senha-certa']))->status === 503, 'sem o secret: 503');
reiniciarSaque();
HttpFalso::$respostas[] = 'conexao';
confere($console->conectar(doAdmin(['chave' => CHAVE_ASAAS, 'senha' => 'senha-certa']))->status === 502, 'Asaas fora: 502');
asaas(200, ['name' => 'Central Teste', 'cpfCnpj' => '12345678000199']);
asaas(200, ['data' => [['id' => 'wallet-1']]]);
$r = $console->conectar(doAdmin(['chave' => CHAVE_ASAAS, 'senha' => 'senha-certa']));
confere($r->status === 200 && $r->dados['conectada'] === true && $r->dados['conta']['wallet'] === 'wallet-1' && !str_contains(json_encode($r->dados), CHAVE_ASAAS), 'conectada: a conexão pública, sem a chave');
$r = $console->gerarToken(doAdmin());
confere($r->status === 200 && strlen($r->dados['token']) === 48 && $r->dados['url'] === 'https://entregas-api.teste/v1/entregas/asaas/validar-saque' && $r->dados['conexao']['token_gerado'] === true, 'token: aparece só aqui, com a URL');
asaas(200, ['id' => 'tra_teste', 'status' => 'PENDING']);
confere($console->testar(doAdmin(['tipo' => 'cpf', 'chave' => '12345678901']))->dados['teste_pedido_em'] === '2026-10-27T10:00:00-03:00', 'teste pedido');
confere(\App\Support\Entregas\Saques\ConexaoAsaas::daEmpresa('empresa-1')->teste_pedido_por === 'user-admin', 'teste: grava quem pediu');
confere($console->testar(doAdmin(['tipo' => 'cpf', 'chave' => '12345678901']))->dados === ['errors' => ['Espere o resultado do teste anterior.']], 'outro teste antes do resultado: 422');
confere($console->configurar(doAdmin(['saque_ligado' => true]))->dados === ['errors' => ['Teste a validação de saque antes de ligar.']], 'ligar antes do teste confirmar: 422');
\Illuminate\Support\Facades\DB::table('entregas_asaas_conexoes')->update(['validacao_confirmada_em' => '2026-10-27 10:00:05']);
$r = $console->configurar(doAdmin(['saque_ligado' => true, 'minimo' => 15]));
confere($r->status === 200 && $r->dados['saque_ligado'] === true && $r->dados['limites']['minimo'] === 15.0, 'ligado com mínimo 15');

echo '== Console: saques e bloqueio' . PHP_EOL;
\Fleetbase\FleetOps\Models\Driver::$todos[] = new \Fleetbase\FleetOps\Models\Driver(['uuid' => 'driver-1', 'public_id' => 'driver_1', 'company_uuid' => 'empresa-1', 'user_uuid' => 'user-driver-1', 'name' => 'João Silva']);
\App\Support\Entregas\Financeiro\ChavePix::salvar('empresa-1', 'driver-1', '11999994321', 'telefone');
$r = $console->bloquear(doAdmin(['bloqueado' => true]), 'driver_1');
confere($r->status === 200 && $r->dados === ['bloqueado' => true] && (bool) Saques::linhaDoMotoboy('empresa-1', 'driver-1')->saque_bloqueado, 'bloquear');
confere($console->bloquear(doAdmin(['bloqueado' => 'talvez']), 'driver_1')->status === 422 && $console->bloquear(doAdmin(['bloqueado' => true]), 'driver_9')->status === 404, 'bloquear: valor inválido 422, motoboy de fora 404');
$lista = $console->saques(doAdmin())->dados;
confere(($lista['motoboys'] ?? null) === [['id' => 'driver_1', 'nome' => 'João Silva', 'chave' => '*******4321', 'tipo' => 'telefone', 'bloqueado' => true, 'liberado_em' => null]], 'motoboys com chave Pix, para o interruptor: ' . json_encode($lista['motoboys'] ?? null, JSON_UNESCAPED_UNICODE));
confere(($lista['saques'] ?? null) === [], 'sem saques');
confere($console->saques(doAdmin(['situacao' => 'lixo']))->status === 422, 'situação inválida: 422');

echo '== Console: desconectar' . PHP_EOL;
confere($console->desconectar(doAdmin(['senha' => 'errada']))->dados === ['errors' => ['Senha incorreta.']] && ConexaoAsaas::daEmpresa('empresa-1') !== null, 'senha errada: continua conectada');
$r = $console->desconectar(doAdmin(['senha' => 'senha-certa']));
confere($r->status === 200 && $r->dados['conectada'] === false, 'desconectada');

echo '== Rotas' . PHP_EOL;
$rotas = file_get_contents('/repo/api/app/Providers/RouteServiceProvider.php');
foreach (['SaquesController', 'SaquePixController', 'ValidacaoAsaasController'] as $classe) {
    confere(str_contains($rotas, 'use App\\Http\\Controllers\\Entregas\\' . $classe . ';'), "use do {$classe}");
}
foreach ([
    "Route::get('financeiro/saque-pix', [SaquePixController::class, 'situacao']);",
    "Route::put('financeiro/saque-pix', [SaquePixController::class, 'configurar']);",
    "Route::post('financeiro/saque-pix/conexao', [SaquePixController::class, 'conectar'])->middleware('throttle:10,1');",
    "Route::delete('financeiro/saque-pix/conexao', [SaquePixController::class, 'desconectar'])->middleware('throttle:10,1');",
    "Route::post('financeiro/saque-pix/token', [SaquePixController::class, 'gerarToken']);",
    "Route::post('financeiro/saque-pix/teste', [SaquePixController::class, 'testar'])->middleware('throttle:10,1');",
    "Route::get('financeiro/saques', [SaquePixController::class, 'saques']);",
    "Route::put('financeiro/motoboys/{id}/saque', [SaquePixController::class, 'bloquear']);",
    "Route::post('saques', [SaquesController::class, 'pedir']);",
    "Route::get('saques/{id}', [SaquesController::class, 'mostrar']);",
    "Route::post('validar-saque', [ValidacaoAsaasController::class, 'validar']);",
    "->middleware(['fleetbase.api', 'throttle:entregas-motoboy-saque'])",
    "Route::prefix('v1/entregas/asaas')",
    "->middleware(['throttle:entregas-asaas-validacao'])",
    "RateLimiter::for('entregas-motoboy-saque'",
    "RateLimiter::for('entregas-asaas-validacao'",
    "Limit::perMinute(120)->by('entregas-asaas-validacao:t:' . hash('sha256', (string) \$request->header('asaas-access-token')))",
    "Limit::perMinute(1000)->by('entregas-asaas-validacao:ip:' . \$request->ip())",
] as $trecho) {
    confere(str_contains($rotas, $trecho), 'rota: ' . $trecho);
}
confere(!str_contains($rotas, "Limit::perMinute(120)->by('entregas-asaas-validacao:' . \$request->ip())"), 'a validação não tem mais um balde só por IP (os IPs do Asaas são de todas as centrais)');

resumo();
