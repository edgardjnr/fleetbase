<?php

// O cache do Laravel num banco do Redis diferente do da fila (api/config/database.php, conexões `cache` e `default`).
// Com os dois no mesmo banco, o `cache:clear` do deploy.sh (FLUSHDB do banco do cache) apagava também os jobs da fila,
// inclusive os com atraso da distribuição (AvancarOferta, AvancarDistribuicao). O phpredis seleciona o banco com
// (int) $config['database'], então a conta aqui é a mesma.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/cache-separado-da-fila.php

namespace Illuminate\Support {
    class Str
    {
        public static function slug($texto, $separador = '-')
        {
            return strtolower(preg_replace('/[^A-Za-z0-9]+/', $separador, (string) $texto));
        }
    }
}

namespace {
    function env($chave, $padrao = null)
    {
        $valor = getenv($chave);

        return $valor === false ? $padrao : $valor;
    }

    $falhas = 0;
    function confere(bool $ok, string $descricao): void
    {
        if (!$ok) {
            $GLOBALS['falhas']++;
        }
        echo ($ok ? 'PASSA ' : 'FALHA ') . $descricao . PHP_EOL;
    }

    function bancos(): array
    {
        $config = require '/repo/api/config/database.php';
        $redis  = $config['redis'];

        return ['fila' => (int) $redis['default']['database'], 'cache' => (int) $redis['cache']['database']];
    }

    echo '== Produção (CACHE_URL=tcp://entregas-cache, sem caminho)' . PHP_EOL;
    putenv('CACHE_URL=tcp://entregas-cache');
    $b = bancos();
    confere($b['fila'] === 0, 'a fila continua no banco 0 (os jobs pendentes não mudam de lugar) (' . $b['fila'] . ')');
    confere($b['cache'] === 1, 'o cache vai para o banco 1 (' . $b['cache'] . ')');
    confere($b['cache'] !== $b['fila'], 'cache e fila em bancos diferentes: o cache:clear não apaga a fila');

    echo '== REDIS_CACHE_DATABASE escolhe o banco do cache' . PHP_EOL;
    putenv('REDIS_CACHE_DATABASE=3');
    $b = bancos();
    confere($b['cache'] === 3 && $b['fila'] === 0, 'cache no 3, fila no 0 (' . json_encode($b) . ')');
    putenv('REDIS_CACHE_DATABASE');

    echo '== deploy.sh e cache.php' . PHP_EOL;
    $cache = file_get_contents('/repo/api/config/cache.php');
    confere(preg_match("/'redis' => \[\s*'driver' => 'redis',\s*'connection' => 'cache',\s*'lock_connection' => 'default'/", $cache) === 1, "o store redis usa a conexão `cache` e as travas ficam na `default`");

    echo PHP_EOL . 'FALHAS: ' . $falhas . PHP_EOL;
}
