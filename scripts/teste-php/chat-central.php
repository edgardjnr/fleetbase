<?php

// Conversa do motoboy com a central (App\Support\Entregas\ChatComACentral): o nome do canal e quem falta entrar nele.
// A busca e a gravação no banco (abrir, usuariosDaCentral) usam os models do core e não rodam aqui.
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

echo '== constantes' . PHP_EOL;
confere(C::TIPOS_DA_CENTRAL === ['admin', 'user'], 'central = usuários do console (sem driver nem customer)');

echo PHP_EOL . "FALHAS: {$falhas}" . PHP_EOL;
