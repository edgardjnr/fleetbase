<?php

// Integração iFood: as três tabelas novas (entregas_ifood_lojas, _eventos e _pedidos).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-migrations.php

require __DIR__ . '/stubs-ifood.php';

use Illuminate\Support\Facades\Schema;

/** Colunas da tabela criada pela migration: nome => Teste\Coluna. */
function colunasDa(string $arquivo, string $tabela): array
{
    $migration = require '/repo/api/database/migrations/' . $arquivo;
    $migration->up();
    $colunas = [];
    foreach ((Schema::$criadas[$tabela] ?? null)?->colunas ?? [] as $coluna) {
        $primeiro = $coluna->argumentos[0] ?? null;
        // índice composto ($table->index([...])) fica como "index:a,b"
        $colunas[is_array($primeiro) ? $coluna->tipo . ':' . implode(',', $primeiro) : ($primeiro ?? $coluna->tipo)] = $coluna;
    }

    return $colunas;
}

function tem(array $colunas, string $nome, string $tipo, array $modificadores = []): bool
{
    $coluna = $colunas[$nome] ?? null;
    if (!$coluna || $coluna->tipo !== $tipo) {
        return false;
    }
    foreach ($modificadores as $modificador) {
        if (!array_key_exists($modificador, $coluna->modificadores)) {
            return false;
        }
    }

    return true;
}

echo '== entregas_ifood_lojas' . PHP_EOL;
$lojas = colunasDa('2026_10_05_120000_create_entregas_ifood_lojas_table.php', 'entregas_ifood_lojas');
confere(tem($lojas, 'vendor_uuid', 'char', ['unique']), 'vendor_uuid único: uma loja, um vínculo');
confere(tem($lojas, 'merchant_id', 'string', ['nullable', 'unique']), 'merchant_id único e nulo depois de desvincular');
confere(tem($lojas, 'access_token', 'text', ['nullable']) && tem($lojas, 'refresh_token', 'text', ['nullable']), 'tokens em text (cifrados, até 8000 caracteres)');
confere(tem($lojas, 'company_uuid', 'char', ['index']) && tem($lojas, 'situacao', 'string', ['index']), 'company_uuid e situacao com índice');
confere(isset($lojas['id'], $lojas['nome_ifood'], $lojas['expira_em'], $lojas['vinculado_em'], $lojas['renovado_em'], $lojas['timestamps']), 'id, nome, validade, datas');

echo '== entregas_ifood_eventos' . PHP_EOL;
$eventos = colunasDa('2026_10_05_120100_create_entregas_ifood_eventos_table.php', 'entregas_ifood_eventos');
confere(tem($eventos, 'evento_id', 'string', ['unique']), 'evento_id (id do iFood) único: descarta repetidos');
confere(tem($eventos, 'pedido_ifood_id', 'string', ['index']) && tem($eventos, 'merchant_id', 'string', ['index']), 'pedido e loja com índice');
confere(($eventos['criado_no_ifood']->argumentos ?? null) === ['criado_no_ifood', 3], 'createdAt com milissegundos');
confere(tem($eventos, 'processado_em', 'timestamp', ['nullable', 'index']) && ($eventos['ignorado']->modificadores['default'] ?? null) === [false], 'processado_em e ignorado');
confere(tem($eventos, 'payload', 'json', ['nullable']) && isset($eventos['codigo']), 'payload e código');

echo '== entregas_ifood_pedidos' . PHP_EOL;
$pedidos = colunasDa('2026_10_05_120200_create_entregas_ifood_pedidos_table.php', 'entregas_ifood_pedidos');
confere(tem($pedidos, 'pedido_ifood_id', 'string', ['unique']), 'pedido_ifood_id único: um pedido iFood, um pedido nosso');
confere(tem($pedidos, 'order_uuid', 'char', ['nullable', 'unique']), 'order_uuid único');
foreach (['numero', 'merchant_id', 'vendor_uuid', 'telefone_0800', 'localizador', 'telefone_expira_em', 'cobrar_centavos', 'forma_pagamento', 'troco_para_centavos', 'observacoes', 'complemento', 'referencia', 'exige_codigo', 'ultima_acao', 'cancelado_pelo_ifood_em', 'pago_mesmo_cancelado', 'teste', 'agendado', 'despachar_em', 'despachado_em', 'timestamps'] as $coluna) {
    confere(isset($pedidos[$coluna]), "coluna {$coluna}");
}
confere(isset($pedidos['index:despachado_em,despachar_em']), 'índice composto do entregas:ifood-agendados (despachado_em, despachar_em)');
confere(($pedidos['cobrar_centavos']->modificadores['default'] ?? null) === [0], 'cobrar_centavos começa em 0 (pago online)');
foreach (['exige_codigo', 'pago_mesmo_cancelado', 'teste', 'agendado'] as $coluna) {
    confere(($pedidos[$coluna]->modificadores['default'] ?? null) === [false], "{$coluna} começa falso");
}

echo '== down' . PHP_EOL;
foreach (['2026_10_05_120000_create_entregas_ifood_lojas_table.php' => 'entregas_ifood_lojas', '2026_10_05_120100_create_entregas_ifood_eventos_table.php' => 'entregas_ifood_eventos', '2026_10_05_120200_create_entregas_ifood_pedidos_table.php' => 'entregas_ifood_pedidos'] as $arquivo => $tabela) {
    (require '/repo/api/database/migrations/' . $arquivo)->down();
    confere(!isset(Schema::$criadas[$tabela]), "down apaga {$tabela}");
}

resumo();
