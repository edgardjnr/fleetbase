<?php

// Saque Pix (Asaas): a migration das tabelas novas e a configuração (services.entregas.saque*).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-esquema.php

require __DIR__ . '/stubs-financeiro.php';

use Illuminate\Support\Facades\Schema;

echo '== Migration' . PHP_EOL;
$arquivo = '/repo/api/database/migrations/2026_10_27_100000_create_entregas_financeiro_saques_tables.php';
confere(is_file($arquivo), 'a migration existe com o nome que o banco em memória lê (_entregas_financeiro_)');
$migration         = require $arquivo;
Schema::$criadas   = [];
Schema::$alteradas = [];
$migration->up();

function colunas(\Illuminate\Database\Schema\Blueprint $blueprint): array
{
    $saida = [];
    foreach ($blueprint->colunas as $coluna) {
        $primeiro = $coluna->argumentos[0] ?? null;
        $chave    = is_string($primeiro) && !in_array($coluna->tipo, ['index', 'unique'], true) ? $primeiro : $coluna->tipo . ':' . json_encode($primeiro);
        $saida[$chave] = $coluna;
    }

    return $saida;
}

confere(array_keys(Schema::$criadas) === ['entregas_asaas_conexoes', 'entregas_saques'], 'cria as duas tabelas');
$conexoes = colunas(Schema::$criadas['entregas_asaas_conexoes']);
foreach (['company_uuid', 'chave_cifrada', 'conta_nome', 'conta_documento', 'wallet_id', 'conectada_por', 'conectada_em', 'token_hash', 'token_gerado_em', 'validacao_confirmada_em', 'teste_transfer_id', 'teste_pedido_em', 'teste_pedido_por', 'testes_no_dia', 'testes_dia', 'saque_ligado', 'minimo', 'maximo_dia', 'saques_dia', 'taxa', 'atualizada_por'] as $nome) {
    confere(isset($conexoes[$nome]), "entregas_asaas_conexoes.{$nome}");
}
confere(array_key_exists('unique', $conexoes['company_uuid']->modificadores), 'uma conexão por empresa');
confere($conexoes['chave_cifrada']->tipo === 'text', 'chave cifrada em text');
confere(array_key_exists('unique', $conexoes['token_hash']->modificadores) && ($conexoes['token_hash']->argumentos[1] ?? null) === 64, 'token: só o hash (64), único');
confere($conexoes['testes_no_dia']->tipo === 'unsignedTinyInteger' && ($conexoes['testes_no_dia']->modificadores['default'][0] ?? null) === 0 && $conexoes['testes_dia']->tipo === 'date' && array_key_exists('nullable', $conexoes['testes_dia']->modificadores) && ($conexoes['teste_pedido_por']->argumentos[1] ?? null) === 36, 'teste da validação: quem pediu e a contagem do dia (começa em 0)');
confere(($conexoes['saque_ligado']->modificadores['default'][0] ?? null) === false, 'saque nasce desligado');
confere(($conexoes['minimo']->modificadores['default'][0] ?? null) === 10 && ($conexoes['maximo_dia']->modificadores['default'][0] ?? null) === 500 && ($conexoes['saques_dia']->modificadores['default'][0] ?? null) === 3 && ($conexoes['taxa']->modificadores['default'][0] ?? null) === 0, 'padrões: mínimo 10, máximo/dia 500, 3 saques/dia, taxa 0');

$saques = colunas(Schema::$criadas['entregas_saques']);
foreach (['uuid', 'company_uuid', 'driver_uuid', 'valor', 'taxa', 'chave_pix', 'tipo_chave', 'situacao', 'motivo_falha', 'transfer_id', 'external_reference', 'asaas_status', 'pagamento_uuid', 'taxa_lancamento_id', 'comprovante_url', 'concluido_em'] as $nome) {
    confere(isset($saques[$nome]), "entregas_saques.{$nome}");
}
confere(array_key_exists('unique', $saques['uuid']->modificadores) && array_key_exists('unique', $saques['transfer_id']->modificadores) && array_key_exists('nullable', $saques['transfer_id']->modificadores), 'uuid único; transfer_id único e nulo até o Asaas responder');
confere(($saques['valor']->argumentos ?? null) === ['valor', 10, 2], 'valor decimal(10,2)');

$motoboys = colunas(Schema::$alteradas['entregas_motoboys_financeiro'][0] ?? new \Illuminate\Database\Schema\Blueprint());
confere(isset($motoboys['saque_bloqueado'], $motoboys['pix_alterada_em'], $motoboys['pix_alterada_por']), 'motoboy: bloqueio e troca da chave Pix');
confere(($motoboys['saque_bloqueado']->modificadores['default'][0] ?? null) === false, 'motoboy nasce desbloqueado');

echo '== Banco em memória' . PHP_EOL;
reiniciarFinanceiro();
\Illuminate\Support\Facades\DB::table('entregas_asaas_conexoes')->insert(['company_uuid' => 'empresa-1', 'chave_cifrada' => 'x', 'wallet_id' => 'w']);
$linha = linhasDe('entregas_asaas_conexoes')[0];
confere($linha['saque_ligado'] === false && $linha['minimo'] === 10 && $linha['saques_dia'] === 3 && $linha['testes_no_dia'] === 0, 'o banco em memória lê a migration nova (padrões aplicados)');

echo '== Configuração' . PHP_EOL;
$servicos = file_get_contents('/repo/api/config/services.php');
confere(str_contains($servicos, "'saque' => env('ENTREGAS_SAQUE')"), 'services.entregas.saque');
confere(str_contains($servicos, "'saque_chave_arquivo' => env('ENTREGAS_SAQUE_CHAVE_ARQUIVO', '/run/secrets/entregas_saque_chave')"), 'arquivo do Docker secret');
confere(str_contains($servicos, "'asaas_url' => env('ASAAS_API_URL', 'https://api.asaas.com/v3')"), 'URL do Asaas');

resumo();
