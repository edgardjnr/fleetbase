<?php

// Conversa do motoboy com a central (App\Support\Entregas\ChatComACentral): o nome do canal, quem é da central, quem
// falta entrar e quem sai, e o motoboy dono pelo `meta`. A busca e a gravação no banco (abrir, sincronizar,
// usuariosDaCentral, aoCriarMensagem) usam os models do core e não rodam aqui.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/chat-central.php

require __DIR__ . '/../../api/app/Support/Entregas/ChatComACentral.php';

use App\Support\Entregas\ChatComACentral as C;

$falhas = 0;

function confere(bool $ok, string $caso): void
{
    global $falhas;
    if (!$ok) {
        $falhas++;
    }
    echo ($ok ? 'PASSA ' : 'FALHA ') . $caso . PHP_EOL;
}

echo '== nome' . PHP_EOL;
confere(C::nome('João Silva') === 'Central · João Silva', 'com o nome do motoboy');
confere(C::nome('  Ana  ') === 'Central · Ana', 'sem espaços nas pontas');
confere(C::nome('') === 'Central', 'sem nome: só Central');
confere(C::nome(null) === 'Central', 'nome nulo: só Central');

echo '== quemFalta' . PHP_EOL;
confere(C::quemFalta(['m', 'a', 'b'], []) === ['m', 'a', 'b'], 'canal novo: todos, na ordem');
confere(C::quemFalta(['m', 'a', 'b'], ['m']) === ['a', 'b'], 'criador já entrou pelo core: só a central');
confere(C::quemFalta(['m', 'a', 'b'], ['m', 'a', 'b']) === [], 'todos presentes: ninguém');
confere(C::quemFalta(['m', 'a', 'c'], ['m', 'a', 'b']) === ['c'], 'quem entrou na central depois é incluído');
confere(C::quemFalta(['m', 'a', 'a', ''], []) === ['m', 'a'], 'sem repetidos e sem vazios');

echo '== quemSai' . PHP_EOL;
confere(C::quemSai(['m', 'a', 'r', 'e'], ['m', 'a']) === ['r', 'e'], 'motoboys que entraram como central saem');
confere(C::quemSai(['m', 'a'], ['m', 'a', 'b']) === [], 'só quem deve estar: ninguém sai');
confere(C::quemSai(['m', 'r', 'r', ''], ['m']) === ['r'], 'sem repetidos e sem vazios');

echo '== ehDaCentral (tipo, tem cadastro de motoboy, papel Administrator na empresa)' . PHP_EOL;
confere(C::ehDaCentral('admin', false, false) === true, 'admin do sistema');
confere(C::ehDaCentral('admin', true, false) === true, 'admin com cadastro de motoboy continua na central');
confere(C::ehDaCentral('user', false, false) === true, 'usuário do console sem cadastro de motoboy');
confere(C::ehDaCentral('user', true, false) === false, 'motoboy com type user (como em produção) fica fora');
confere(C::ehDaCentral('user', true, true) === true, 'motoboy com papel Administrator na empresa entra');
confere(C::ehDaCentral('driver', false, false) === false, 'type driver fica fora');
confere(C::ehDaCentral('driver', true, true) === true, 'type driver com papel Administrator entra');
confere(C::ehDaCentral('customer', false, true) === false, 'usuário de loja nunca');
confere(C::ehDaCentral(null, false, false) === false, 'sem tipo fica fora');

echo '== motoboyDoMeta' . PHP_EOL;
confere(C::motoboyDoMeta([C::CHAVE_META => 'drv']) === 'drv', 'meta em array');
confere(C::motoboyDoMeta((object) [C::CHAVE_META => 'drv']) === 'drv', 'meta em objeto');
confere(C::motoboyDoMeta(json_encode([C::CHAVE_META => 'drv'])) === 'drv', 'meta em texto JSON');
confere(C::motoboyDoMeta(['entregas_conversa_loja' => 'v']) === null, 'canal da loja: não é da central');
confere(C::motoboyDoMeta([]) === null, 'meta vazio');
confere(C::motoboyDoMeta(null) === null, 'sem meta');
confere(C::motoboyDoMeta([C::CHAVE_META => '']) === null, 'motoboy vazio');

echo '== constantes' . PHP_EOL;
confere(C::TIPOS_DA_CENTRAL === ['admin', 'user'], 'central = usuários do console (sem driver nem customer)');

echo PHP_EOL . "FALHAS: {$falhas}" . PHP_EOL;
