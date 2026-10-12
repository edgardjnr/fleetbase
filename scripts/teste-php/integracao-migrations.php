<?php

// Integração de pedidos de outros sistemas: as duas tabelas (lidas das migrations pelo banco em memória) e a config.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-migrations.php

require __DIR__ . '/stubs-integracao.php';

use Teste\Banco;

echo '== entregas_integracao_lojas' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
$repetida = excecao(fn () => Banco::inserir('entregas_integracao_lojas', ['company_uuid' => 'empresa-1', 'vendor_uuid' => 'vendor-b', 'chave_hash' => hash('sha256', CHAVE_DA_LOJA_A), 'chave_final' => 'AAAA'], false));
confere($repetida instanceof \Illuminate\Database\QueryException && $repetida->getCode() === '23000', 'chave_hash única');
confere(excecao(fn () => Banco::inserir('entregas_integracao_lojas', ['coluna_que_nao_existe' => 1], false)) instanceof RuntimeException, 'coluna desconhecida falha (o esquema veio da migration)');
confere(Banco::tamanhoDe('entregas_integracao_lojas', 'sistema') === 40 && Banco::tamanhoDe('entregas_integracao_lojas', 'webhook_url') === 500, 'tamanhos de sistema e webhook_url');

echo '== entregas_integracao_pedidos' . PHP_EOL;
$linha = ['company_uuid' => 'empresa-1', 'vendor_uuid' => 'vendor-a', 'order_uuid' => 'order-1', 'pedido_externo_id' => 'ord-1', 'cobranca_forma' => 'pix'];
confere(Banco::inserir('entregas_integracao_pedidos', $linha, false), 'insere');
$erro = excecao(fn () => Banco::inserir('entregas_integracao_pedidos', ['order_uuid' => 'order-2'] + $linha, false));
confere($erro instanceof \Illuminate\Database\QueryException && $erro->getCode() === '23000', 'mesmo pedido_externo_id na mesma loja: chave única (vendor_uuid + pedido_externo_id)');
confere(Banco::inserir('entregas_integracao_pedidos', ['vendor_uuid' => 'vendor-b', 'order_uuid' => 'order-3'] + $linha, false), 'o mesmo id externo em outra loja passa');
$erro = excecao(fn () => Banco::inserir('entregas_integracao_pedidos', ['vendor_uuid' => 'vendor-c', 'pedido_externo_id' => 'ord-9'] + $linha, false));
confere($erro instanceof \Illuminate\Database\QueryException, 'order_uuid único');
$nova = (new Teste\Consulta('entregas_integracao_pedidos'))->where('order_uuid', 'order-3')->first();
confere($nova->cobranca_centavos === 0 && $nova->pago_mesmo_cancelado === false && $nova->sem_localizacao === false && $nova->ultimo_aviso === null, 'padrões da migration');
confere(Banco::tamanhoDe('entregas_integracao_pedidos', 'pedido_externo_id') === 100 && Banco::tamanhoDe('entregas_integracao_pedidos', 'numero') === 20, 'tamanhos');

echo '== Config' . PHP_EOL;
$config = file_get_contents('/repo/api/config/services.php');
confere(str_contains($config, "'integracao' => env('ENTREGAS_INTEGRACAO')"), 'services.entregas.integracao');
confere(str_contains($config, "'console_url' => env('ENTREGAS_CONSOLE_URL', 'https://entregas.restaurantepro.com.br')"), 'services.entregas.console_url');

resumo();
