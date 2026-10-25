<?php

// Resumo do período do painel inicial (App\Support\Entregas\Painel\PainelInicial).
// Uso: PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/painel-inicial.php

require __DIR__ . '/stubs-financeiro.php';

use App\Support\Entregas\Financeiro\Lancamentos;
use App\Support\Entregas\Painel\PainelInicial;
use Teste\Banco;
use Teste\Relogio;

function corrida(string $pedido, string $finalizadaEm, ?string $loja, ?string $lojaNome, ?string $motoboy, ?string $motoboyNome, string $empresa = 'empresa-1'): void
{
    Banco::inserir('entregas_corridas_finalizadas', [
        'order_uuid' => 'uuid-' . $pedido, 'company_uuid' => $empresa, 'pedido' => $pedido, 'numero' => null,
        'loja_uuid' => $loja, 'loja_nome' => $lojaNome, 'motoboy_uuid' => $motoboy, 'motoboy_nome' => $motoboyNome,
        'finalizada_em' => $finalizadaEm, 'origem' => 'conclusao', 'created_at' => $finalizadaEm,
    ], false);
}

function lancamento(string $conta, string $contaTipo, string $tipo, float $valor, string $pedido, ?float $km = null): void
{
    Lancamentos::inserir([
        'company_uuid' => 'empresa-1', 'conta_tipo' => $contaTipo, 'conta_uuid' => $conta, 'tipo' => $tipo, 'valor' => $valor,
        'data_referencia' => '2026-10-24', 'order_uuid' => 'uuid-' . $pedido, 'chave_unica' => "{$tipo}:{$contaTipo}:{$pedido}",
        'detalhes' => ['pedido' => $pedido, 'numero' => $pedido, 'km' => $km],
    ]);
}

function pedidoNoBanco(string $uuid, ?string $iniciado, ?string $despachado, array $meta): void
{
    Banco::inserir('orders', ['uuid' => $uuid, 'public_id' => 'order_' . $uuid, 'company_uuid' => 'empresa-1', 'started_at' => $iniciado, 'dispatched_at' => $despachado, 'meta' => json_encode($meta)], false);
}

function cenario(): void
{
    reiniciarFinanceiro();
    Relogio::$agora = '2026-10-24 21:14:00';

    corrida('p1', '2026-10-24 19:10:00', 'vendor-a', 'Terraço', 'driver-1', 'Carlos');
    corrida('p2', '2026-10-24 19:40:00', 'vendor-a', 'Terraço', 'driver-2', 'Juliana');
    corrida('p3', '2026-10-24 20:05:00', 'vendor-b', 'Spunto', 'driver-1', 'Carlos');
    corrida('p4', '2026-10-25 00:10:00', 'vendor-a', 'Terraço', 'driver-1', 'Carlos');   // outro dia
    corrida('p5', '2026-10-17 19:30:00', 'vendor-a', 'Terraço', 'driver-1', 'Carlos');   // sábado passado
    corrida('p6', '2026-10-24 19:00:00', 'vendor-x', 'Outra', 'driver-x', 'Outro', 'empresa-2');

    // DB::table('orders') lê a tabela em memória (o Order::$todos só entra nos joins): as linhas como o MySQL devolve
    pedidoNoBanco('uuid-p1', '2026-10-24 18:40:00', '2026-10-24 18:35:00', ['entregas_prazo_em' => '2026-10-24T19:30:00-03:00']);
    pedidoNoBanco('uuid-p2', '2026-10-24 19:00:00', null, ['entregas_prazo_em' => '2026-10-24T19:30:00-03:00']);
    pedidoNoBanco('uuid-p3', null, '2026-10-24 19:35:00', []);

    lancamento('vendor-a', 'loja', 'corrida', 14.00, 'p1');
    lancamento('vendor-a', 'loja', 'corrida', 14.00, 'p2');
    lancamento('vendor-b', 'loja', 'corrida', 15.50, 'p3');
    lancamento('driver-1', 'motoboy', 'corrida', 10.00, 'p1', 3.2);
    lancamento('driver-2', 'motoboy', 'corrida', 9.50, 'p2', 2.0);
    lancamento('driver-1', 'motoboy', 'corrida', 11.00, 'p3', 4.1);
    lancamento('driver-1', 'motoboy', 'diaria', 50.00, 'd1');                                // não é corrida
}

cenario();
$r = PainelInicial::montar('empresa-1', '2026-10-24', '2026-10-24', true);

confere($r['corridas'] === 3, 'conta só as corridas do dia e da central');
confere($r['corridas_anterior'] === 1 && $r['variacao'] === 200, 'compara com o sábado passado');
confere($r['no_prazo'] === 50 && $r['tempo_medio_min'] === 33, 'no prazo e tempo médio');
confere($r['faturado'] === 43.5 && $r['a_pagar'] === 30.5 && $r['margem'] === 13.0, 'faturado, a pagar e margem só de corridas');
confere($r['km'] === 9.3, 'km das corridas dos motoboys');
confere($r['grafico']['tipo'] === 'hora' && count($r['grafico']['barras']) === 24, 'gráfico por hora com 24 barras');
confere($r['grafico']['barras'][19]['total'] === 2 && $r['grafico']['barras'][19]['media'] === 0.3, 'hora 19: 2 hoje, média 1/4 = 0,3');
confere(array_column($r['lojas'], 'nome') === ['Terraço', 'Spunto'] && $r['lojas'][0]['faturado'] === 28.0, 'lojas com corridas e faturado');
confere(array_key_exists('ifood', $r['lojas'][0]) && $r['lojas'][0]['ifood'] === null, 'loja sem vínculo iFood: null');
confere(array_column($r['motoboys'], 'nome') === ['Carlos', 'Juliana'] && $r['motoboys'][0]['ganhos'] === 21.0, 'motoboys com corridas e ganhos');
confere(is_array($r['atencao']), 'admin recebe a lista Atenção');

// não admin: sem dinheiro e sem Atenção, com km e números
cenario();
$r = PainelInicial::montar('empresa-1', '2026-10-24', '2026-10-24', false);
confere($r['faturado'] === null && $r['a_pagar'] === null && $r['margem'] === null, 'não admin: sem dinheiro');
confere($r['lojas'][0]['faturado'] === null && $r['motoboys'][0]['ganhos'] === null, 'não admin: listas sem dinheiro');
confere($r['atencao'] === [] && $r['corridas'] === 3 && $r['km'] === 9.3, 'não admin: sem Atenção, com corridas e km');

// Financeiro desligado: sem dinheiro nem km, mesmo para admin
cenario();
\Teste\Config::$valores['services.entregas.financeiro'] = '0';
$r = PainelInicial::montar('empresa-1', '2026-10-24', '2026-10-24', true);
confere($r['faturado'] === null && $r['km'] === null && $r['corridas'] === 3, 'Financeiro desligado: só as contagens');

// 7 dias: gráfico por dia, sem média
cenario();
$r = PainelInicial::montar('empresa-1', '2026-10-18', '2026-10-24', true);
confere($r['grafico']['tipo'] === 'dia' && count($r['grafico']['barras']) === 7, '7 dias: uma barra por dia');
confere(end($r['grafico']['barras'])['total'] === 3 && end($r['grafico']['barras'])['media'] === null, 'último dia com 3, sem média');
confere($r['corridas'] === 3 && $r['corridas_anterior'] === 1 && $r['variacao'] === 200, '7 dias (18 a 24): o sábado 17 vai para os 7 anteriores');

// cache: a segunda chamada devolve o guardado (60 s)
cenario();
$primeira = PainelInicial::montar('empresa-1', '2026-10-24', '2026-10-24', true);
corrida('p7', '2026-10-24 21:00:00', 'vendor-a', 'Terraço', 'driver-1', 'Carlos');
confere(PainelInicial::montar('empresa-1', '2026-10-24', '2026-10-24', true)['corridas'] === $primeira['corridas'], 'resumo em cache por 60 s');

resumo();
