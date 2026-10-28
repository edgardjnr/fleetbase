<?php

// Chamar motoboy pelo portal da loja: a tabela entregas_portal_pedidos, o CriadorDoChamado, o PedidosDoPortal, o
// ChamarMotoboyController e os leitores da linha (app do motoboy, comanda, detalhe do portal).
// Spec: docs/superpowers/specs/2026-10-28-chamar-motoboy-portal-design.md.
// Uso: PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/chamar-motoboy.php

require __DIR__ . '/stubs-integracao.php';

use Teste\Banco;

$raiz = dirname(__DIR__, 2);

echo '== Tabela entregas_portal_pedidos' . PHP_EOL;
$esquema = Banco::esquema()['entregas_portal_pedidos'] ?? null;
confere($esquema !== null, 'a migration cria a tabela');
foreach (['id', 'company_uuid', 'vendor_uuid', 'order_uuid', 'place_uuid', 'chave', 'cliente_nome', 'cliente_telefone', 'complemento', 'referencia', 'observacoes', 'cobranca_forma', 'cobranca_centavos', 'troco_para_centavos', 'created_at', 'updated_at'] as $coluna) {
    confere(in_array($coluna, $esquema['colunas'] ?? [], true), "coluna {$coluna}");
}
confere(in_array('order_uuid', $esquema['unicas'] ?? [], true), 'um pedido, uma linha (order_uuid único)');
confere(in_array(['vendor_uuid', 'chave'], $esquema['compostas'] ?? [], true), 'a chave do chamado é única por loja');
confere(($esquema['padroes']['cobranca_centavos'] ?? null) === 0, 'cobranca_centavos começa em 0');
$migration = file_get_contents($raiz . '/api/database/migrations/2026_10_28_150000_create_entregas_portal_pedidos_table.php');
confere(str_contains($migration, "->charset('ascii')->collation('ascii_bin')"), 'chave em ascii_bin');
confere(str_contains($migration, "\$table->index(['vendor_uuid', 'cliente_telefone'], 'entregas_portal_ped_tel_index');"), 'índice da busca pelo telefone');

resumo();
