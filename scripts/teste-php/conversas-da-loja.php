<?php

// Chat da loja com os motoboys (App\Support\Entregas\ConversasDaLoja): as regras puras (nome do canal, papel de quem
// escreveu, texto aceito e a marca do canal no meta). A busca e a gravação no banco usam os models do core e não rodam aqui.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/conversas-da-loja.php

require __DIR__ . '/../../api/app/Support/Entregas/ConversasDaLoja.php';

use App\Support\Entregas\ConversasDaLoja as C;

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
confere(C::nome('Terraço Pizza', 'João') === 'Terraço Pizza · João', 'loja · motoboy');
confere(C::nome(' Terraço ', ' ') === 'Terraço', 'sem motoboy: só a loja');
confere(C::nome(null, 'João') === 'João', 'sem loja: só o motoboy');
confere(C::nome(null, null) === 'Conversa', 'sem nada: Conversa');

echo '== papel' . PHP_EOL;
confere(C::papel('customer') === 'loja', 'usuário de loja (customer)');
confere(C::papel('driver') === 'motoboy', 'motoboy (driver)');
confere(C::papel('admin') === 'central', 'admin: central');
confere(C::papel('user') === 'central', 'usuário do console: central');
confere(C::papel(null) === 'central', 'sem tipo: central');

echo '== textoValido' . PHP_EOL;
confere(C::textoValido('  oi  ') === 'oi', 'sem espaços nas pontas');
confere(C::textoValido("linha 1\nlinha 2") === "linha 1\nlinha 2", 'quebra de linha fica');
confere(C::textoValido('   ') === null, 'só espaços: recusa');
confere(C::textoValido('') === null, 'vazio: recusa');
confere(C::textoValido(null) === null, 'nulo: recusa');
confere(C::textoValido(['a']) === null, 'lista: recusa');
confere(C::textoValido(str_repeat('á', 1000)) === str_repeat('á', 1000), '1000 caracteres (multibyte): aceita');
confere(C::textoValido(str_repeat('a', 1001)) === null, '1001 caracteres: recusa');

echo '== participantes' . PHP_EOL;
confere(C::participantes('u-loja', ['u-loja', 'u-loja2'], 'u-moto') === ['u-loja', 'u-loja2', 'u-moto'], 'só a loja e o motoboy (a central não entra sozinha)');
confere(C::participantes('u-moto', ['u-loja'], 'u-moto') === ['u-moto', 'u-loja'], 'aberta pelo motoboy: ele e a loja, sem repetir');
confere(C::participantes('u-loja', [], 'u-moto') === ['u-loja', 'u-moto'], 'loja sem outros usuários ativos');
confere(C::participantes('', ['', 'u-loja'], 'u-moto') === ['u-loja', 'u-moto'], 'sem vazios');

echo '== meta' . PHP_EOL;
confere(C::meta('v-1', 'd-1') === ['entregas_conversa_loja' => 'v-1', 'entregas_conversa_motoboy' => 'd-1'], 'marca do canal: loja e motoboy');

echo PHP_EOL . "FALHAS: {$falhas}" . PHP_EOL;
