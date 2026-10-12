<?php

// Integração: quais avisos faltam, pelo estado do Order (SequenciaDaIntegracao, função pura).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-sequencia.php

require __DIR__ . '/stubs-integracao.php';

use App\Support\Entregas\Integracao\SequenciaDaIntegracao as S;

echo '== Alvo' . PHP_EOL;
confere(S::alvo('dispatched', false, null) === null && S::alvo('created', false, 'driver-1') === null, 'sem aceite: nada');
confere(S::alvo('started', true, 'driver-1') === S::ACEITO, 'aceito');
confere(S::alvo('enroute', true, 'driver-1') === S::A_CAMINHO, 'a caminho');
confere(S::alvo('picked_up', true, 'driver-1') === S::A_CAMINHO && S::alvo('ENROUTE', true, 'driver-1') === S::A_CAMINHO, 'a caminho: todo status de entrega, sem diferença de maiúsculas');
confere(S::alvo('completed', true, 'driver-1') === S::CONCLUIDO && S::alvo('done', true, 'driver-1') === S::CONCLUIDO, 'concluído');
confere(S::alvo('COMPLETED', true, 'driver-1') === S::CONCLUIDO && S::alvo('Canceled', false, null) === S::CANCELADO, 'concluído e cancelado sem diferença de maiúsculas');
foreach (['canceled', 'cancelled', 'order_canceled', 'expired'] as $status) {
    confere(S::alvo($status, false, null) === S::CANCELADO, "cancelado: {$status}");
}

echo '== Faltando' . PHP_EOL;
confere(S::faltando(null, null, false, false) === [], 'sem alvo: nada');
confere(S::faltando(null, S::ACEITO, true, false) === [S::ACEITO], 'primeiro aceite');
confere(S::faltando(S::ACEITO, S::ACEITO, true, false) === [], 'aceite já avisado');
confere(S::faltando(S::ACEITO, S::A_CAMINHO, true, false) === [S::A_CAMINHO], 'a caminho depois do aceite');
confere(S::faltando(null, S::A_CAMINHO, true, false) === [S::ACEITO, S::A_CAMINHO], 'a caminho sem o aceite avisado: os dois, em ordem');
confere(S::faltando(S::ACEITO, S::CONCLUIDO, true, false) === [S::CONCLUIDO], 'concluído sem passar pelo a caminho: só o concluído');
confere(S::faltando(null, S::CONCLUIDO, true, false) === [S::ACEITO, S::CONCLUIDO], 'concluído sem nada avisado: aceito e concluído');
confere(S::faltando(null, S::CONCLUIDO, false, false) === [S::CONCLUIDO], 'concluído pela central sem motoboy: só o concluído');
confere(S::faltando(S::A_CAMINHO, S::A_CAMINHO, true, true) === [S::ACEITO], 'troca de motoboy no a caminho: o aceito de novo');
confere(S::faltando(S::ACEITO, S::ACEITO, true, true) === [S::ACEITO], 'troca de motoboy antes do a caminho');
confere(S::faltando(S::A_CAMINHO, S::CANCELADO, true, false) === [S::CANCELADO] && S::faltando(null, S::CANCELADO, false, false) === [S::CANCELADO], 'cancelado: só o cancelado');
confere(S::faltando(S::CONCLUIDO, S::CANCELADO, true, false) === [] && S::faltando(S::CANCELADO, S::CANCELADO, true, false) === [], 'depois do fim: nada');

echo '== depoisDe' . PHP_EOL;
confere(S::depoisDe(null, S::ACEITO) === S::ACEITO && S::depoisDe(S::ACEITO, S::A_CAMINHO) === S::A_CAMINHO, 'avança');
confere(S::depoisDe(S::A_CAMINHO, S::ACEITO) === S::A_CAMINHO, 'o aceito reenviado (troca de motoboy) não volta');

echo '== doPedido' . PHP_EOL;
$linha = (object) ['ultimo_aviso' => S::A_CAMINHO, 'motoboy_avisado' => 'driver-1'];
confere(S::doPedido($linha, (object) ['status' => 'enroute', 'started' => true, 'driver_assigned_uuid' => 'driver-1']) === [], 'nada mudou');
confere(S::doPedido($linha, (object) ['status' => 'enroute', 'started' => true, 'driver_assigned_uuid' => 'driver-2']) === [S::ACEITO], 'motoboy trocado');
confere(S::doPedido((object) ['ultimo_aviso' => null, 'motoboy_avisado' => null], (object) ['status' => 'started', 'started' => true, 'driver_assigned_uuid' => 'driver-1']) === [S::ACEITO], 'primeiro aceite (motoboy_avisado nulo não conta como troca)');

resumo();
