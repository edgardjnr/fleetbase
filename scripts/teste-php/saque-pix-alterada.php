<?php

// Saque Pix: a troca da chave Pix do motoboy marca quem e quando (carência de 24 h) e avisa o motoboy; bloqueio do saque
// por motoboy.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-pix-alterada.php

require __DIR__ . '/stubs-saque.php';

use App\Http\Controllers\Entregas\FinanceiroController;
use App\Notifications\Entregas\PixDeSaqueAlterado;
use App\Support\Entregas\Financeiro\ChavePix;
use App\Support\Entregas\Saques\Saques;
use Fleetbase\FleetOps\Models\Driver;
use Illuminate\Http\Request;
use Teste\Relogio;
use Teste\UsuarioDoSaque;

echo '== ChavePix::salvar' . PHP_EOL;
reiniciarSaque();
confere(ChavePix::salvar('empresa-1', 'driver-1', '11999994321', 'telefone', 'user-admin') === false, 'primeiro cadastro: não é troca');
$linha = Saques::linhaDoMotoboy('empresa-1', 'driver-1');
confere($linha->pix_alterada_em === null && $linha->pix_alterada_por === null, 'primeiro cadastro: sem carência');
confere(ChavePix::salvar('empresa-1', 'driver-1', '11999994321', 'telefone', 'user-admin') === false, 'a mesma chave de novo: não é troca');
Relogio::$agora = '2026-10-27 11:00:00';
confere(ChavePix::salvar('empresa-1', 'driver-1', '12345678901', 'cpf', 'user-admin') === true, 'chave diferente: troca');
$linha = Saques::linhaDoMotoboy('empresa-1', 'driver-1');
confere($linha->pix_alterada_em === '2026-10-27 11:00:00' && $linha->pix_alterada_por === 'user-admin' && $linha->chave_pix === '12345678901', 'troca: quem e quando');
confere(ChavePix::salvar('empresa-1', 'driver-1', '12345678901', 'cpf') === false, 'o salvar antigo (4 argumentos) continua valendo');

echo '== Carência (Saques::liberadoEm)' . PHP_EOL;
confere(Saques::liberadoEm($linha) === '2026-10-28 11:00:00', 'liberado 24 h depois da troca');
Relogio::$agora = '2026-10-28 11:00:01';
confere(Saques::liberadoEm($linha) === null, 'passadas as 24 h: sem carência');
confere(Saques::liberadoEm(null) === null, 'sem linha: sem carência');

echo '== Bloqueio' . PHP_EOL;
Saques::bloquear('empresa-1', 'driver-1', true);
confere((bool) Saques::linhaDoMotoboy('empresa-1', 'driver-1')->saque_bloqueado === true, 'bloqueia');
Saques::bloquear('empresa-1', 'driver-1', false);
confere((bool) Saques::linhaDoMotoboy('empresa-1', 'driver-1')->saque_bloqueado === false, 'desbloqueia');
Saques::bloquear('empresa-1', 'driver-9', true);
$nova = Saques::linhaDoMotoboy('empresa-1', 'driver-9');
confere($nova && (bool) $nova->saque_bloqueado && $nova->chave_pix === null, 'motoboy sem linha: cria a linha bloqueada, sem chave');

echo '== FinanceiroController@salvarPix avisa o motoboy' . PHP_EOL;
reiniciarSaque();
Relogio::$agora = '2026-10-27 10:00:00';
session(['company' => 'empresa-1']);
\Fleetbase\Support\Auth::$usuario = new UsuarioDoSaque('user-admin', 'admin');
Driver::$todos[] = new Driver(['uuid' => 'driver-1', 'public_id' => 'driver_1', 'company_uuid' => 'empresa-1', 'user_uuid' => 'user-m1', 'name' => 'João Silva']);
$controller = new FinanceiroController();
$r = $controller->salvarPix(new Request(['tipo' => 'telefone', 'chave' => '11999994321']), 'driver_1');
confere($r->status === 200 && Driver::$avisos === [], 'primeiro cadastro: sem aviso');
$r = $controller->salvarPix(new Request(['tipo' => 'cpf', 'chave' => '123.456.789-01']), 'driver_1');
$aviso = Driver::$avisos[0][1] ?? null;
confere($r->status === 200 && $aviso instanceof PixDeSaqueAlterado && str_contains($aviso->message, '28/10 às 10:00'), 'troca: push com a hora em que o saque volta: ' . ($aviso->message ?? 'nenhum'));
confere(Saques::linhaDoMotoboy('empresa-1', 'driver-1')->pix_alterada_por === 'user-admin', 'troca pelo console: grava o admin');

resumo();
