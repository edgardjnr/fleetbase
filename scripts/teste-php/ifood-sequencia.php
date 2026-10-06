<?php

// Integração iFood (etapa 3): funções puras da ordem das ações (SequenciaIfood), do texto da cobrança (CobrancaIfood) e
// da chegada pelo GPS (ChegadaPeloGps).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-sequencia.php

require __DIR__ . '/stubs-ifood.php';

use App\Support\Entregas\Ifood\ChegadaPeloGps;
use App\Support\Entregas\Ifood\CobrancaIfood;
use App\Support\Entregas\Ifood\SequenciaIfood;

// --- SequenciaIfood ---
confere(SequenciaIfood::faltando(null, 'goingToOrigin') === ['assignDriver', 'goingToOrigin'], 'nada enviado: assignDriver e goingToOrigin');
confere(SequenciaIfood::faltando('goingToOrigin', 'dispatch') === ['arrivedAtOrigin', 'dispatch'], 'GPS falhou e tocou "A caminho": arrivedAtOrigin e dispatch');
confere(SequenciaIfood::faltando('dispatch', 'dispatch') === [], 'alvo igual à última: nada');
confere(SequenciaIfood::faltando('dispatch', 'goingToOrigin') === [], 'alvo atrás da última: nada (não volta etapa)');
confere(SequenciaIfood::faltando('assignDriver', null) === [], 'sem alvo: nada');
confere(SequenciaIfood::faltando('acaoInventada', 'assignDriver') === ['assignDriver'], 'última desconhecida vale como nenhuma');
confere(SequenciaIfood::maisAdiante(null, 'assignDriver', 'dispatch', 'goingToOrigin') === 'dispatch', 'mais adiante: dispatch');
confere(SequenciaIfood::maisAdiante(null, null) === null, 'mais adiante sem nenhuma: null');

confere(SequenciaIfood::alvoPeloPedido('dispatched', false, null) === null, 'sem motoboy: nada');
confere(SequenciaIfood::alvoPeloPedido('dispatched', false, 'driver-1') === 'assignDriver', 'com motoboy: assignDriver');
confere(SequenciaIfood::alvoPeloPedido('dispatched', true, 'driver-1') === 'goingToOrigin', 'iniciado (antes da atividade): goingToOrigin');
confere(SequenciaIfood::alvoPeloPedido('started', true, 'driver-1') === 'goingToOrigin', 'status started: goingToOrigin');
confere(SequenciaIfood::alvoPeloPedido('enroute', true, 'driver-1') === 'dispatch', 'enroute: dispatch');
confere(SequenciaIfood::alvoPeloPedido('completed', true, 'driver-1') === 'arrivedAtDestination', 'concluído: arrivedAtDestination');
confere(SequenciaIfood::alvoPeloPedido('canceled', true, 'driver-1') === null, 'cancelado: nada');
confere(SequenciaIfood::alvoPeloPedido('expired', false, 'driver-1') === null, 'expirado: nada');

confere(SequenciaIfood::saiuParaEntrega('dispatch') && SequenciaIfood::saiuParaEntrega('arrivedAtDestination'), 'dispatch aceito: saiu para entrega');
confere(!SequenciaIfood::saiuParaEntrega('arrivedAtOrigin') && !SequenciaIfood::saiuParaEntrega(null), 'antes do dispatch: não saiu');

// --- CobrancaIfood ---
confere(CobrancaIfood::texto(0, 'CASH', 10000) === null, 'nada a cobrar: null');
confere(CobrancaIfood::texto(5890, 'CASH', 10000) === 'Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100', 'dinheiro com troco (exemplo da spec)');
confere(CobrancaIfood::texto(5890, 'CASH', 5000) === 'Cobrar R$ 58,90 · dinheiro', 'troco menor que o valor não aparece');
confere(CobrancaIfood::texto(5890, 'CASH', 10050) === 'Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100,50', 'troco com centavos');
// o troco só é gravado a partir do bloco de dinheiro (PedidoDoIfood::cobranca): existindo, aparece, mesmo com "MISTO",
// que esconde as formas
confere(CobrancaIfood::texto(5890, 'MISTO', 10000) === 'Cobrar R$ 58,90 · formas variadas · troco p/ R$ 100', 'MISTO com troco: o troco aparece');
confere(CobrancaIfood::texto(5890, 'GIFT_CARD', null) === 'Cobrar R$ 58,90 · vale-presente', 'GIFT_CARD: vale-presente');
confere(CobrancaIfood::texto(5890, 'OTHER', null) === 'Cobrar R$ 58,90 · outra forma', 'OTHER: outra forma');
confere(CobrancaIfood::texto(123456, 'CASH+CREDIT', 200000) === 'Cobrar R$ 1.234,56 · dinheiro + cartão de crédito · troco p/ R$ 2.000', 'duas formas e milhar');
confere(CobrancaIfood::texto(5890, null, null) === 'Cobrar R$ 58,90', 'sem forma');
confere(CobrancaIfood::texto(5890, 'MISTO', null) === 'Cobrar R$ 58,90 · formas variadas', 'MISTO');
confere(CobrancaIfood::texto(5890, 'BOLETO_X', null) === 'Cobrar R$ 58,90 · boleto_x', 'forma desconhecida em minúsculas');

// --- ChegadaPeloGps (loja em -21.1775, -47.8103; 0,0009° de latitude ≈ 100 m) ---
$loja    = [-21.1775, -47.8103];
$cliente = [-21.1685, -47.8103];
confere(ChegadaPeloGps::acao('goingToOrigin', [-21.1780, -47.8103], $loja, $cliente) === 'arrivedAtOrigin', 'indo à loja e a ~56 m: arrivedAtOrigin');
confere(ChegadaPeloGps::acao('goingToOrigin', [-21.1795, -47.8103], $loja, $cliente) === null, 'indo à loja e a ~220 m: nada');
confere(ChegadaPeloGps::acao('assignDriver', [-21.1775, -47.8103], $loja, $cliente) === null, 'ainda não iniciou: nada');
confere(ChegadaPeloGps::acao('arrivedAtOrigin', [-21.1685, -47.8103], $loja, $cliente) === null, 'na loja (sem dispatch) e no cliente: nada');
confere(ChegadaPeloGps::acao('dispatch', [-21.1690, -47.8103], $loja, $cliente) === 'arrivedAtDestination', 'saiu para entrega e a ~56 m do cliente: arrivedAtDestination');
confere(ChegadaPeloGps::acao('dispatch', [-21.1775, -47.8103], $loja, $cliente) === null, 'saiu para entrega e ainda na loja: nada');
confere(ChegadaPeloGps::acao('dispatch', [0.0, 0.0], $loja, $cliente) === null, 'sem GPS (0, 0): nada');
confere(ChegadaPeloGps::acao('dispatch', null, $loja, $cliente) === null, 'sem posição: nada');
confere(ChegadaPeloGps::acao('dispatch', [-21.1690, -47.8103], $loja, null) === null, 'entrega sem coordenada: nada');

resumo();
