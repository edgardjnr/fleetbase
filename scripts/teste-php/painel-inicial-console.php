<?php

// Controller do painel inicial (PainelInicialController) e as rotas no RouteServiceProvider.
// Uso: PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/painel-inicial-console.php

require __DIR__ . '/stubs-financeiro.php';

use App\Http\Controllers\Entregas\PainelInicialController;
use Teste\Banco;
use Teste\Relogio;

class UsuarioDoPainel
{
    public function __construct(public string $uuid, public bool $admin) {}
    public function isNotAdmin(): bool { return !$this->admin; }
}

function sessaoDoPainel(bool $admin): void
{
    \Fleetbase\Support\Auth::$usuario = new UsuarioDoPainel($admin ? 'user-admin' : 'user-comum', $admin);
    session(['company' => 'empresa-1', 'user' => $admin ? 'user-admin' : 'user-comum']);
}

function pedidoDoPainel(array $dados = []): \Illuminate\Http\Request
{
    return new \Illuminate\Http\Request($dados);
}

reiniciarFinanceiro();
Relogio::$agora = '2026-10-24 21:14:00';
Banco::inserir('entregas_corridas_finalizadas', [
    'order_uuid' => 'uuid-p1', 'company_uuid' => 'empresa-1', 'pedido' => 'order_p1', 'numero' => null, 'loja_uuid' => 'vendor-a',
    'loja_nome' => 'Terraço', 'motoboy_uuid' => 'driver-1', 'motoboy_nome' => 'Carlos', 'finalizada_em' => '2026-10-24 19:10:00',
    'origem' => 'conclusao', 'created_at' => '2026-10-24 19:10:00',
], false);

$controller = new PainelInicialController();

// resumo: sem datas = hoje (fuso da empresa)
sessaoDoPainel(false);
$resposta = $controller->resumo(pedidoDoPainel());
confere($resposta->status === 200 && $resposta->dados['inicio'] === '2026-10-24' && $resposta->dados['corridas'] === 1, 'resumo sem datas = hoje');
confere($resposta->dados['faturado'] === null && $resposta->dados['atencao'] === [], 'não admin: sem dinheiro e sem Atenção');

sessaoDoPainel(true);
confere($controller->resumo(pedidoDoPainel(['inicio' => '2026-10-24', 'fim' => '2026-10-24']))->status === 200, 'admin: resumo 200');
confere($controller->resumo(pedidoDoPainel(['inicio' => '2026-13-01', 'fim' => '2026-10-24']))->status === 422, 'data inválida → 422');
confere($controller->resumo(pedidoDoPainel(['inicio' => '2026-10-24', 'fim' => '2026-10-01']))->status === 422, 'período invertido → 422');
confere($controller->resumo(pedidoDoPainel(['inicio' => '2026-08-01', 'fim' => '2026-10-24']))->status === 422, 'mais de 31 dias → 422');

// atribuir: só admin; motoboy vazio → 422 vem do TrocaDoMotoboy (ou 404 sem o pedido)
sessaoDoPainel(false);
$negado = $controller->atribuir(pedidoDoPainel(['motoboy' => 'driver_1']), 'order_x');
confere($negado->status === 403 && str_contains((string) ($negado->dados['errors'][0] ?? ''), 'administradores'), 'atribuir: não admin → 403');

// rotas e use no provider
$provider = file_get_contents(__DIR__ . '/../../api/app/Providers/RouteServiceProvider.php');
confere(str_contains($provider, 'use App\Http\Controllers\Entregas\PainelInicialController;'), 'provider importa o controller');
confere(str_contains($provider, "Route::get('painel-inicial', [PainelInicialController::class, 'resumo']);"), 'rota do resumo');
confere(str_contains($provider, "Route::get('painel-inicial/ao-vivo', [PainelInicialController::class, 'aoVivo']);"), 'rota do ao vivo');
confere(str_contains($provider, "Route::post('painel-inicial/pedidos/{id}/motoboy', [PainelInicialController::class, 'atribuir']);"), 'rota do atribuir');

resumo();
