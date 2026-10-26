<?php

// Saque Pix nas telas antigas do Financeiro (plano 2, console): o bloco `saque` do extrato do motoboy no console
// (bloqueio e carência depois da troca da chave Pix, com o nome de quem trocou), o `saque_bloqueado` da lista de motoboys
// e o IP de saída na conexão pública (lista de IPs do Asaas). Depende do plano 1 (stubs-saque.php, Saques, ConexaoAsaas).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-console.php

require __DIR__ . '/stubs-saque.php';

use App\Http\Controllers\Entregas\FinanceiroController;
use App\Support\Entregas\Financeiro\ChavePix;
use App\Support\Entregas\Saques\ConexaoAsaas;
use App\Support\Entregas\Saques\SaqueNoConsole;
use App\Support\Entregas\Saques\Saques;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Teste\Banco;
use Teste\Config;
use Teste\Relogio;
use Teste\UsuarioDoSaque;

function comoAdminDoSistema(): void
{
    session(['company' => 'empresa-1', 'user' => 'user-admin']);
    \Fleetbase\Support\Auth::$usuario = new UsuarioDoSaque('user-admin', 'admin');
}

function extratoDoJoao(): array
{
    comoAdminDoSistema();

    return (new FinanceiroController())->extratoDoMotoboy(new Request(), 'driver_1')->dados;
}

/** A lista de motoboys do console, por public_id. */
function listaDeMotoboys(): array
{
    comoAdminDoSistema();
    $lista = (new FinanceiroController())->motoboys(new Request())->dados['motoboys'] ?? [];

    return array_column($lista, null, 'id');
}

echo '== SaqueNoConsole::doMotoboy' . PHP_EOL;
centralComSaque();
Banco::inserir('users', ['uuid' => 'user-admin', 'name' => 'Edgard'], false);
$bloco = SaqueNoConsole::doMotoboy('empresa-1', 'driver-1');
confere($bloco === ['bloqueado' => false, 'liberado_em' => null, 'alterada_por' => null], 'liberado, sem carência: ' . json_encode($bloco));
Saques::bloquear('empresa-1', 'driver-1', true);
confere(SaqueNoConsole::doMotoboy('empresa-1', 'driver-1')['bloqueado'] === true, 'bloqueado');
Saques::bloquear('empresa-1', 'driver-1', false);
Relogio::$agora = '2026-10-27 11:00:00';
ChavePix::salvar('empresa-1', 'driver-1', '12345678901', 'cpf', 'user-admin');
$bloco = SaqueNoConsole::doMotoboy('empresa-1', 'driver-1');
confere($bloco === ['bloqueado' => false, 'liberado_em' => '2026-10-28T11:00:00-03:00', 'alterada_por' => 'Edgard'], 'troca da chave: carência até 28/10 11:00, trocada por Edgard: ' . json_encode($bloco, JSON_UNESCAPED_UNICODE));
Relogio::$agora = '2026-10-28 11:00:01';
$bloco = SaqueNoConsole::doMotoboy('empresa-1', 'driver-1');
confere($bloco['liberado_em'] === null && $bloco['alterada_por'] === null, 'passadas as 24 h: sem carência e sem o nome');
confere(SaqueNoConsole::doMotoboy('empresa-1', 'driver-9') === ['bloqueado' => false, 'liberado_em' => null, 'alterada_por' => null], 'motoboy sem linha no financeiro: liberado');
Config::$valores['services.entregas.saque'] = null;
confere(SaqueNoConsole::doMotoboy('empresa-1', 'driver-1') === null, 'ENTREGAS_SAQUE desligado: null');
Config::$valores['services.entregas.saque'] = '1';
DB::table('entregas_asaas_conexoes')->where('company_uuid', 'empresa-1')->delete();
confere(SaqueNoConsole::doMotoboy('empresa-1', 'driver-1') === null, 'central sem conta Asaas: null');

echo '== SaqueNoConsole::bloqueados e a lista de motoboys' . PHP_EOL;
centralComSaque();
motoboyComSaldo('driver-2', 'Maria Souza');
confere(SaqueNoConsole::bloqueados('empresa-1') === [], 'ninguém bloqueado');
Saques::bloquear('empresa-1', 'driver-2', true);
confere(SaqueNoConsole::bloqueados('empresa-1') === ['driver-2' => true], 'Maria bloqueada');
$lista = listaDeMotoboys();
confere(($lista['driver_2']['saque_bloqueado'] ?? null) === true && ($lista['driver_1']['saque_bloqueado'] ?? null) === false, 'lista do console: saque_bloqueado por motoboy: ' . json_encode(array_column($lista, 'saque_bloqueado', 'id')));
Config::$valores['services.entregas.saque'] = null;
confere(SaqueNoConsole::bloqueados('empresa-1') === [] && (listaDeMotoboys()['driver_2']['saque_bloqueado'] ?? null) === false, 'desligado: ninguém aparece bloqueado');

echo '== Extrato do motoboy no console' . PHP_EOL;
centralComSaque();
$extrato = extratoDoJoao();
confere(($extrato['saque'] ?? null) === ['bloqueado' => false, 'liberado_em' => null, 'alterada_por' => null], 'extrato: bloco saque: ' . json_encode($extrato['saque'] ?? null));
confere(($extrato['conta']['pix'] ?? null) === ['chave' => '11999994321', 'tipo' => 'telefone'], 'a conta continua igual');
Config::$valores['services.entregas.saque'] = null;
confere(!array_key_exists('saque', extratoDoJoao()), 'desligado: sem a chave saque (o console esconde o interruptor)');

echo '== IP de saída na conexão' . PHP_EOL;
centralComSaque();
Config::$valores['services.entregas.ip_de_saida'] = ' 203.0.113.10 ';
$publico = ConexaoAsaas::publico(ConexaoAsaas::daEmpresa('empresa-1'), 'empresa-1');
confere(($publico['ip_de_saida'] ?? null) === '203.0.113.10', 'conectada: o IP, sem espaços');
Config::$valores['services.entregas.ip_de_saida'] = null;
$publico = ConexaoAsaas::publico(ConexaoAsaas::daEmpresa('empresa-1'), 'empresa-1');
confere(array_key_exists('ip_de_saida', $publico) && $publico['ip_de_saida'] === null, 'sem IP configurado: null');
confere(ConexaoAsaas::publico(null, 'empresa-1') === ['conectada' => false, 'ligada' => true, 'cofre' => true], 'sem conexão: nada do IP');

echo '== Configuração e stack' . PHP_EOL;
confere(str_contains(file_get_contents('/repo/api/config/services.php'), "'ip_de_saida' => env('ENTREGAS_IP_DE_SAIDA')"), 'services.entregas.ip_de_saida');
confere(str_contains(file_get_contents('/repo/deploy/docker-stack.yml'), 'ENTREGAS_IP_DE_SAIDA: ${ENTREGAS_IP_DE_SAIDA:-}'), 'stack: ENTREGAS_IP_DE_SAIDA no x-api-env');
confere(preg_match('/^ENTREGAS_IP_DE_SAIDA=\r?$/m', file_get_contents('/repo/deploy/stack.env.example')) === 1, 'modelo do stack.env');

resumo();
