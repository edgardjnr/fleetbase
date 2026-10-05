<?php

// Sanidade do banco em memória dos testes do iFood (stubs-ifood.php): ele conhece as colunas das migrations
// entregas_ifood_* e lança, como o "Unknown column" do MySQL, para coluna que não existe.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-stub-banco.php

require __DIR__ . '/stubs-ifood.php';

use Illuminate\Support\Facades\DB;
use Teste\Banco;

function lancaColuna(callable $fazer): bool
{
    $e = excecao($fazer);

    return $e instanceof \RuntimeException && str_contains($e->getMessage(), 'coluna desconhecida');
}

echo '== inserir' . PHP_EOL;
Banco::limpar();
confere(excecao(fn () => DB::table('entregas_ifood_eventos')->insert(['evento_id' => 'e1', 'merchant_id' => 'm', 'pedido_ifood_id' => 'p', 'codigo' => 'PLC', 'created_at' => 'x'])) === null, 'coluna existente (inclusive created_at, de timestamps) é aceita');
confere(lancaColuna(fn () => DB::table('entregas_ifood_eventos')->insert(['evento_id' => 'e2', 'evnto_codigo' => 'PLC'])), 'insert com coluna inexistente lança');
confere(lancaColuna(fn () => DB::table('entregas_ifood_eventos')->insertOrIgnore(['evento_id' => 'e3', 'processado' => true])), 'insertOrIgnore com coluna inexistente lança');
confere(count(Banco::linhas('entregas_ifood_eventos')) === 1, 'e a linha inválida não é gravada');
confere(excecao(fn () => DB::table('tabela_qualquer')->insert(['qualquer' => 1])) === null, 'tabela fora do iFood fica como estava');

echo '== update' . PHP_EOL;
confere(excecao(fn () => DB::table('entregas_ifood_eventos')->where('evento_id', 'e1')->update(['ignorado' => true, 'updated_at' => 'y'])) === null, 'update com colunas existentes funciona');
confere(lancaColuna(fn () => DB::table('entregas_ifood_eventos')->where('evento_id', 'e1')->update(['ignorada' => true])), 'update com coluna inexistente lança');
confere(Banco::linhas('entregas_ifood_eventos')[0]->ignorado === true, 'o valor certo foi gravado');

echo '== where, orderBy e leitura' . PHP_EOL;
confere(DB::table('entregas_ifood_eventos')->where('evento_id', 'e1')->orderBy('id')->count() === 1, 'where e orderBy com coluna existente funcionam');
confere(lancaColuna(fn () => DB::table('entregas_ifood_eventos')->where('evento', 'e1')), 'where com coluna inexistente lança');
confere(lancaColuna(fn () => DB::table('entregas_ifood_eventos')->whereNull('processado')), 'whereNull com coluna inexistente lança');
confere(lancaColuna(fn () => DB::table('entregas_ifood_eventos')->orderBy('quando')), 'orderBy com coluna inexistente lança');
confere(lancaColuna(fn () => DB::table('entregas_ifood_eventos')->pluck('quando')), 'pluck com coluna inexistente lança');

echo '== únicas vindas das migrations' . PHP_EOL;
confere(Banco::unicasDe('entregas_ifood_lojas') === ['vendor_uuid', 'merchant_id'], 'lojas: vendor_uuid e merchant_id');
confere(Banco::unicasDe('entregas_ifood_eventos') === ['evento_id'], 'eventos: evento_id');
$pedidosUnicas = Banco::unicasDe('entregas_ifood_pedidos');
sort($pedidosUnicas);
confere($pedidosUnicas === ['order_uuid', 'pedido_ifood_id'], 'pedidos: pedido_ifood_id e order_uuid');
$repetido = excecao(fn () => DB::table('entregas_ifood_eventos')->insert(['evento_id' => 'e1']));
confere($repetido !== null, 'evento_id repetido continua sendo recusado');
confere($repetido instanceof \Illuminate\Database\QueryException && $repetido->getCode() === '23000' && $repetido->errorInfo[0] === '23000', 'como QueryException com SQLSTATE 23000');
Banco::$falhar['entregas_ifood_eventos'] = 'MySQL server has gone away';
$fora = excecao(fn () => DB::table('entregas_ifood_eventos')->insert(['evento_id' => 'e9']));
confere($fora instanceof Teste\ErroDeBanco && $fora->getCode() !== '23000', 'banco fora do ar: outro SQLSTATE');
unset(Banco::$falhar['entregas_ifood_eventos']);

echo '== gancho antes do insert' . PHP_EOL;
$vezes = 0;
Banco::$antesDeInserir['entregas_ifood_eventos'] = function () use (&$vezes) { $vezes++; };
DB::table('entregas_ifood_eventos')->insert(['evento_id' => 'g1']);
DB::table('entregas_ifood_eventos')->insert(['evento_id' => 'g2']);
confere($vezes === 1, 'roda uma vez só, antes do próximo insert');

echo '== não estraga o Schema::$criadas' . PHP_EOL;
confere(\Illuminate\Support\Facades\Schema::$criadas === [], 'as migrations lidas pelo banco não ficam registradas no Schema');

echo '== errorInfo e chaves únicas no update' . PHP_EOL;
Banco::limpar();
DB::table('entregas_ifood_eventos')->insert(['evento_id' => 'u1']);
DB::table('entregas_ifood_eventos')->insert(['evento_id' => 'u2']);
$repetido = excecao(fn () => DB::table('entregas_ifood_eventos')->insert(['evento_id' => 'u1']));
confere($repetido instanceof \Illuminate\Database\QueryException && $repetido->errorInfo[0] === '23000' && $repetido->errorInfo[1] === 1062, 'insert repetido: errorInfo = [23000, 1062, mensagem]');
$repetido = excecao(fn () => DB::table('entregas_ifood_eventos')->where('evento_id', 'u2')->update(['evento_id' => 'u1']));
confere($repetido instanceof \Illuminate\Database\QueryException && $repetido->getCode() === '23000' && $repetido->errorInfo[1] === 1062, 'update que repete uma chave única: o mesmo erro 23000/1062');
confere(Banco::linhas('entregas_ifood_eventos')[1]->evento_id === 'u2', 'e a linha não é alterada');
confere(excecao(fn () => DB::table('entregas_ifood_eventos')->where('evento_id', 'u2')->update(['evento_id' => 'u2', 'ignorado' => true])) === null, 'update que mantém o próprio valor único passa');
confere(excecao(fn () => DB::table('entregas_ifood_eventos')->where('evento_id', 'u2')->update(['evento_id' => 'u3'])) === null, 'update para valor livre passa');
Banco::limpar();
DB::table('entregas_ifood_lojas')->insert(['vendor_uuid' => 'v1', 'merchant_id' => null]);
DB::table('entregas_ifood_lojas')->insert(['vendor_uuid' => 'v2', 'merchant_id' => null]);
confere(excecao(fn () => DB::table('entregas_ifood_lojas')->whereNull('merchant_id')->update(['merchant_id' => null])) === null, 'NULL não conta como repetido (vários desvinculados)');
confere(excecao(fn () => DB::table('entregas_ifood_lojas')->whereNull('merchant_id')->update(['merchant_id' => 'm1'])) instanceof \Illuminate\Database\QueryException, 'duas linhas com o mesmo valor novo numa chave única: erro');

echo '== encrypt() com nonce, como o real' . PHP_EOL;
confere(encrypt('segredo') !== encrypt('segredo') && decrypt(encrypt('segredo')) === 'segredo', 'o mesmo valor cifra em textos diferentes e decifra igual');
confere(decrypt(encrypt(['a' => 1])) === ['a' => 1] && !str_contains(encrypt('segredo'), 'segredo'), 'serve para lista e não mostra o valor');
confere(excecao(fn () => decrypt('lixo')) instanceof \Illuminate\Contracts\Encryption\DecryptException && excecao(fn () => decrypt('cifrado:sem-separador')) instanceof \Illuminate\Contracts\Encryption\DecryptException, 'texto corrompido: DecryptException');

resumo();
