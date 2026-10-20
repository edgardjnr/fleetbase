<?php

// Produtos do pedido (ItensDoPedido): a normalização para o formato do app, a gravação e a leitura da coluna `itens`.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/itens-do-pedido.php

require __DIR__ . '/stubs-ifood.php';

use App\Support\Entregas\ItensDoPedido;

/** Normaliza uma lista com um item só e devolve esse item (ou null se ele caiu). */
function umItem(array $item): ?array
{
    return ItensDoPedido::normalizar([$item])[0] ?? null;
}

echo '== Formato completo' . PHP_EOL;
$completo = [['quantidade' => 1, 'nome' => 'Pizza grande Calabresa', 'opcoes' => [['quantidade' => 1, 'nome' => 'Borda de catupiry']], 'observacao' => 'sem cebola']];
confere(ItensDoPedido::normalizar($completo) === $completo, 'o formato do contrato passa igual');
confere(ItensDoPedido::normalizar([['nome' => 'Refrigerante']]) === [['quantidade' => 1, 'nome' => 'Refrigerante']], 'sem opcoes nem observacao: as chaves não aparecem; quantidade ausente vale 1');
confere(umItem(['quantidade' => 2, 'nome' => 'Esfiha', 'opcoes' => [], 'observacao' => '  ']) === ['quantidade' => 2, 'nome' => 'Esfiha'], 'opcoes vazias e observacao em branco saem');
confere(array_keys(umItem(['observacao' => 'x', 'opcoes' => [['nome' => 'a']], 'nome' => 'b', 'quantidade' => 3])) === ['quantidade', 'nome', 'opcoes', 'observacao'], 'chaves sempre na ordem do contrato');

echo '== Quantidade' . PHP_EOL;
foreach ([[2, 2], [2.0, 2], ['3', 3], [' 4 ', 4], [999, 999]] as [$entrada, $esperada]) {
    confere(umItem(['quantidade' => $entrada, 'nome' => 'X'])['quantidade'] === $esperada, 'quantidade ' . var_export($entrada, true) . ' = ' . $esperada);
}
foreach ([0, -1, 1000, 1.5, '1000', 'dois', true, null, [2], INF, NAN] as $invalida) {
    confere(umItem(['quantidade' => $invalida, 'nome' => 'X'])['quantidade'] === 1, 'quantidade inválida (' . str_replace(PHP_EOL, '', var_export($invalida, true)) . ') vale 1');
}

echo '== Nome' . PHP_EOL;
confere(umItem(['nome' => "  Pizza \n  grande\t "])['nome'] === 'Pizza grande', 'quebras e espaços colapsados, pontas aparadas');
confere(umItem(['nome' => 'Pizza 🍕 Çã'])['nome'] === 'Pizza 🍕 Çã', 'emoji e acento intactos');
confere(umItem(['nome' => "Pi\0zza\u{200B}"])['nome'] === 'Pizza', 'controle e invisível saem');
confere(umItem(['nome' => 123])['nome'] === '123', 'número vira texto');
confere(mb_strlen(umItem(['nome' => str_repeat('á', 130)])['nome']) === 120, 'nome longo cortado em 120 caracteres');
confere(umItem(['nome' => str_repeat('a', 119) . ' b'])['nome'] === str_repeat('a', 119), 'corte não deixa espaço sobrando no fim');
foreach (['vazio' => '', 'só espaço' => "  \u{00A0} ", 'lista' => ['x'], 'nulo' => null, 'booleano' => true, 'UTF-8 inválido' => "\xFFPizza"] as $caso => $nome) {
    confere(umItem(['quantidade' => 1, 'nome' => $nome]) === null, "item sem nome válido ({$caso}) não entra");
}

echo '== Opções' . PHP_EOL;
$item = umItem(['nome' => 'Combo', 'opcoes' => [['nome' => 'Molho'], ['quantidade' => 2, 'nome' => 'Bacon'], ['quantidade' => 3], 'texto', ['nome' => '']]]);
confere($item['opcoes'] === [['quantidade' => 1, 'nome' => 'Molho'], ['quantidade' => 2, 'nome' => 'Bacon']], 'opção sem nome cai; quantidade padrão 1');
$muitas = array_map(fn ($n) => ['nome' => "Opção {$n}"], range(1, 25));
confere(count(umItem(['nome' => 'X', 'opcoes' => $muitas])['opcoes']) === 20, 'até 20 opções');
confere(!array_key_exists('opcoes', umItem(['nome' => 'X', 'opcoes' => ['a' => ['nome' => 'Molho']]])), 'opcoes que não é lista: a chave sai');
confere(!array_key_exists('opcoes', umItem(['nome' => 'X', 'opcoes' => [['quantidade' => 1]]])), 'nenhuma opção válida: a chave sai');

echo '== Observação' . PHP_EOL;
confere(umItem(['nome' => 'X', 'observacao' => "sem\ncebola"])['observacao'] === 'sem cebola', 'uma linha');
confere(mb_strlen(umItem(['nome' => 'X', 'observacao' => str_repeat('o', 250)])['observacao']) === 200, 'cortada em 200 caracteres');
confere(!array_key_exists('observacao', umItem(['nome' => 'X', 'observacao' => ['x']])), 'observacao que não é texto: a chave sai');

echo '== Lista' . PHP_EOL;
$sessenta = array_map(fn ($n) => ['nome' => "Item {$n}"], range(1, 60));
$lista    = ItensDoPedido::normalizar($sessenta);
confere(count($lista) === 50 && $lista[49]['nome'] === 'Item 50', 'até 50 itens, os primeiros');
$comInvalidos = array_merge(array_fill(0, 10, ['nome' => '']), $sessenta);
confere(count(ItensDoPedido::normalizar($comInvalidos)) === 50 && ItensDoPedido::normalizar($comInvalidos)[0]['nome'] === 'Item 1', 'item que caiu não conta no limite');
confere(ItensDoPedido::normalizar([['nome' => 'A'], 'texto', null, ['nome' => 'B']]) === [['quantidade' => 1, 'nome' => 'A'], ['quantidade' => 1, 'nome' => 'B']], 'entrada que não é objeto cai; a ordem fica');
foreach (['nulo' => null, 'vazia' => [], 'texto' => 'Pizza', 'objeto' => ['a' => ['nome' => 'Pizza']], 'só inválidos' => [['nome' => ''], ['quantidade' => 2]]] as $caso => $entrada) {
    confere(ItensDoPedido::normalizar($entrada) === null, "{$caso}: null");
}

echo '== Banco' . PHP_EOL;
confere(ItensDoPedido::paraOBanco(null) === null && ItensDoPedido::paraOBanco([]) === null && ItensDoPedido::paraOBanco([['nome' => '']]) === null, 'nada a gravar: null');
$json = ItensDoPedido::paraOBanco($completo);
confere($json === '[{"quantidade":1,"nome":"Pizza grande Calabresa","opcoes":[{"quantidade":1,"nome":"Borda de catupiry"}],"observacao":"sem cebola"}]', 'JSON com acento sem escape');
confere(ItensDoPedido::doBanco($json, ['pedido' => 'order_1']) === $completo, 'ida e volta');
$comoOMysql = '[{"nome": "Pizza", "opcoes": [{"nome": "Borda", "quantidade": 1}], "quantidade": 2}]';
confere(ItensDoPedido::doBanco($comoOMysql) === [['quantidade' => 2, 'nome' => 'Pizza', 'opcoes' => [['quantidade' => 1, 'nome' => 'Borda']]]], 'JSON do MySQL (chaves em outra ordem): volta na ordem do contrato');
\Illuminate\Support\Facades\Log::$registros = [];
confere(ItensDoPedido::doBanco(null) === null && ItensDoPedido::doBanco('') === null && \Illuminate\Support\Facades\Log::$registros === [], 'coluna vazia: null, sem log');
foreach (['quebrado' => '[{"nome": "Pizza"', 'texto' => '"Pizza"', 'objeto' => '{"nome": "Pizza"}', 'número' => '12'] as $caso => $valor) {
    \Illuminate\Support\Facades\Log::$registros = [];
    confere(ItensDoPedido::doBanco($valor, ['pedido' => 'order_1']) === null, "JSON {$caso}: null");
    confere(logou('[entregas] itens do pedido ilegíveis no banco', 'warning') && \Illuminate\Support\Facades\Log::$registros[0][2]['pedido'] === 'order_1' && logsSem(['Pizza']), "JSON {$caso}: warning só com o id");
}

resumo();
