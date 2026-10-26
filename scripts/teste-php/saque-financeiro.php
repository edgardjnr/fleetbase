<?php

// Saque Pix no Financeiro: o pagamento de saque não se estorna à mão, o extrato e o recibo marcam o saque, a carteira do
// app traz o bloco `saque` e o painel avisa saque que falhou ou parou.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-financeiro.php

require __DIR__ . '/stubs-saque.php';

use App\Http\Controllers\Entregas\FinanceiroController;
use App\Support\Entregas\Financeiro\Carteira;
use App\Support\Entregas\Financeiro\ExtratoDaConta;
use App\Support\Entregas\Financeiro\Pagamentos;
use App\Support\Entregas\Saques\Saques;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Teste\Config;
use Teste\Relogio;
use Teste\UsuarioDoSaque;

const U1 = 'aaaaaaaa-0000-4000-8000-000000000001';

function estornar(string $pagamentoUuid)
{
    session(['company' => 'empresa-1']);
    \Fleetbase\Support\Auth::$usuario = new UsuarioDoSaque('user-admin', 'admin');

    return (new FinanceiroController())->estornarPagamento(new Request(), $pagamentoUuid);
}

function linhaDoPagamento(array $extrato, string $pagamentoUuid): ?array
{
    foreach ($extrato['lancamentos'] ?? [] as $linha) {
        if (($linha['pagamento']['id'] ?? null) === $pagamentoUuid) {
            return $linha;
        }
    }

    return null;
}

echo '== Estorno à mão bloqueado' . PHP_EOL;
centralComSaque();
$s = saqueEnviado('driver-1', U1, 50, ['id' => 'tra_1', 'status' => 'PENDING']);
$r = estornar((string) $s->pagamento_uuid);
confere($r->status === 422 && $r->dados === ['errors' => ['Saque em andamento.']], 'em andamento: 422');
Saques::concluir($s, 'https://www.asaas.com/c/1');
$r = estornar((string) $s->pagamento_uuid);
confere($r->status === 422 && $r->dados === ['errors' => ['O dinheiro do saque já saiu; corrija com um ajuste.']], 'concluído: 422');
confere(Pagamentos::porUuid('empresa-1', (string) $s->pagamento_uuid)->estornado_em === null, 'o pagamento continua valendo');
$comum = Pagamentos::registrar('empresa-1', 'motoboy', 'driver-1', 5, 'dinheiro', '2026-10-27', null, null, 'user-admin');
confere(estornar((string) $comum->uuid)->status === 200, 'pagamento comum: estorna como antes');

echo '== Extrato e recibo marcam o saque' . PHP_EOL;
$publico = ExtratoDaConta::montar('empresa-1', 'motoboy', 'driver-1', '2026-10-01', '2026-10-31', true);
$linha   = linhaDoPagamento($publico, (string) $s->pagamento_uuid);
confere(($linha['pagamento']['saque'] ?? null) === ['situacao' => 'concluido', 'comprovante_url' => 'https://www.asaas.com/c/1'], 'extrato do app: pagamento com o saque: ' . json_encode($linha['pagamento'] ?? null));
confere(!array_key_exists('saque', linhaDoPagamento($publico, (string) $comum->uuid)['pagamento'] ?? ['saque' => 1]), 'pagamento comum: sem a chave saque');
$console = ExtratoDaConta::montar('empresa-1', 'motoboy', 'driver-1', '2026-10-01', '2026-10-31', false);
confere((linhaDoPagamento($console, (string) $s->pagamento_uuid)['pagamento']['saque']['situacao'] ?? null) === 'concluido', 'extrato do console: também');
$recibo = ExtratoDaConta::pagamento(Pagamentos::porUuid('empresa-1', (string) $s->pagamento_uuid));
confere(($recibo['saque'] ?? null) === ['situacao' => 'concluido', 'comprovante_url' => 'https://www.asaas.com/c/1'], 'recibo: o saque');
confere(!array_key_exists('saque', ExtratoDaConta::pagamento(Pagamentos::porUuid('empresa-1', (string) $comum->uuid))), 'recibo comum: sem saque');

echo '== Carteira do app' . PHP_EOL;
$carteira = Carteira::doMotoboy('empresa-1', 'driver-1');
confere(isset($carteira['saque']) && $carteira['saque']['disponivel'] === Saques::situacaoDoMotoboy('empresa-1', 'driver-1')['disponivel'], 'com o saque ligado: bloco saque');
Config::$valores['services.entregas.saque'] = null;
confere(!array_key_exists('saque', Carteira::doMotoboy('empresa-1', 'driver-1')), 'desligado: sem a chave (o app esconde o botão)');
Config::$valores['services.entregas.saque'] = '1';

echo '== Painel: atenção' . PHP_EOL;
centralComSaque();
motoboyComSaldo('driver-2', 'Maria Souza');
$falho = saqueEnviado('driver-1', U1, 10, ['id' => 'tra_1', 'status' => 'PENDING']);
Saques::falhar($falho, 'Saldo insuficiente.');
saqueEnviado('driver-2', 'aaaaaaaa-0000-4000-8000-000000000002', 20, ['id' => 'tra_2', 'status' => 'PENDING']);
Relogio::$agora = '2026-10-27 11:00:01';
$atencao = Saques::atencao('empresa-1');
confere(($atencao[0] ?? null) === ['tipo' => 'saque_falhou', 'saque' => U1, 'motoboy' => 'João Silva', 'valor' => 10.0, 'motivo' => 'Saldo insuficiente.'], 'saque que falhou nas últimas 24 h: ' . json_encode($atencao[0] ?? null, JSON_UNESCAPED_UNICODE));
confere(($atencao[1]['tipo'] ?? null) === 'saque_parado' && $atencao[1]['motoboy'] === 'Maria Souza' && $atencao[1]['desde'] === '2026-10-27T10:00:00-03:00', 'em andamento há mais de 1 h: parado');
\Illuminate\Support\Facades\DB::table('entregas_saques')->where('uuid', U1)->update(['motivo_falha' => 'Não foi possível concluir o saque agora.', 'motivo_asaas' => 'Chave Pix inexistente no DICT.']);
confere((Saques::atencao('empresa-1')[0]['motivo'] ?? null) === 'Chave Pix inexistente no DICT.', 'falha do Asaas: o painel mostra o texto do Asaas');
Relogio::$agora = '2026-10-28 11:00:01';
confere(array_column(Saques::atencao('empresa-1'), 'tipo') === ['saque_parado'], 'depois de 24 h, a falha sai do painel');
Config::$valores['services.entregas.saque'] = null;
confere(Saques::atencao('empresa-1') === [], 'desligado: nada');
confere(str_contains(file_get_contents('/repo/api/app/Support/Entregas/Financeiro/PainelFinanceiro.php'), 'foreach (Saques::atencao($companyUuid) as $item)'), 'o painel inclui os avisos do saque');

resumo();
