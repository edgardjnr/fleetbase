<?php

// Teste estático: todo nome curto de classe usado nos providers, no Kernel e nos arquivos novos da distribuição precisa
// estar importado (`use`), ser a própria classe, existir no mesmo namespace em disco ou ser reservado. O `php -l` não pega:
// um `Nome::class` sem `use` vira uma classe inexistente do namespace e só estoura em tempo de execução (erro 500 na API).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/imports-dos-providers.php

$raiz = dirname(__DIR__, 2);
$app  = $raiz . '/api/app';

$arquivos = array_merge(
    glob($app . '/Providers/*.php'),
    [$app . '/Console/Kernel.php'],
    glob($app . '/Support/Entregas/Distribuicao/*.php'),
    glob($app . '/Listeners/Entregas/*.php'),
    [
        $app . '/Jobs/Entregas/AvancarOferta.php',
        $app . '/Jobs/Entregas/AvancarDistribuicao.php',
        $app . '/Http/Middleware/BarrarAceiteDePedidoEncerrado.php',
        $app . '/Http/Middleware/FiltrarPedidosAbertosDoMotoboy.php',
        $app . '/Http/Controllers/Entregas/DistribuicaoController.php',
        $app . '/Http/Controllers/Entregas/DriverControllerSemGeocodificacao.php',
        $app . '/Console/Commands/Entregas/VarrerDistribuicoes.php',
        $app . '/Notifications/Entregas/OfertaDePedido.php',
    ]
);

$reservadas = ['self', 'static', 'parent', 'int', 'integer', 'string', 'array', 'bool', 'boolean', 'float', 'double', 'callable', 'iterable',
    'mixed', 'object', 'void', 'null', 'never', 'false', 'true', 'resource', 'numeric'];

const BARRA = '\\';

/** O arquivo da classe (namespace App\...) em api/app, ou null se não for do App. */
function arquivoDa(string $classe, string $app): ?string
{
    return str_starts_with($classe, 'App' . BARRA) ? $app . '/' . str_replace(BARRA, '/', substr($classe, 4)) . '.php' : null;
}

/** O último segmento de um nome com namespace. */
function ultimo(string $nome): string
{
    $partes = explode(BARRA, $nome);

    return end($partes);
}

/** Nomes curtos usados como classe que não estão declarados: lista de [nome, linha]. */
function faltando(string $arquivo, string $app, array $reservadas): array
{
    $tokens = array_values(array_filter(token_get_all(file_get_contents($arquivo)), fn ($t) => !(is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))));
    $n      = count($tokens);
    $texto  = fn ($i) => is_array($tokens[$i] ?? null) ? $tokens[$i][1] : ($tokens[$i] ?? '');
    $tipo   = fn ($i) => is_array($tokens[$i] ?? null) ? $tokens[$i][0] : null;
    $nomes  = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

    $namespace  = '';
    $declarados = []; // nome curto (minúsculo) => true
    $bloco      = 0;
    for ($i = 0; $i < $n; $i++) {
        $t = $texto($i);
        if ($tipo($i) === T_NAMESPACE && in_array($tipo($i + 1), $nomes, true)) {
            $namespace = $texto($i + 1);
        } elseif ($t === '{') {
            $bloco++;
        } elseif ($t === '}') {
            $bloco--;
        } elseif ($tipo($i) === T_USE && $bloco === 0) {
            // use A\B\C; | use A\B\C as D; | use A\B\{C, D as E};
            $j = $i + 1;
            if (in_array($tipo($j), [T_FUNCTION, T_CONST], true)) {
                continue;
            }
            if ($texto($j + 1) === '{' || $texto($j + 1) === BARRA . '{') {
                $j += 2;
                while ($texto($j) !== '}') {
                    if (in_array($tipo($j), $nomes, true)) {
                        $declarados[strtolower($tipo($j + 1) === T_AS ? $texto($j + 2) : ultimo($texto($j)))] = true;
                    }
                    $j++;
                }
            } else {
                $declarados[strtolower($tipo($j + 1) === T_AS ? $texto($j + 2) : ultimo($texto($j)))] = true;
            }
        } elseif (in_array($tipo($i), [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && $tipo($i + 1) === T_STRING && $tipo($i - 1) !== T_DOUBLE_COLON) {
            $declarados[strtolower($texto($i + 1))] = true;
        }
    }

    $achados = [];
    for ($i = 0; $i < $n; $i++) {
        if (!in_array($tipo($i), [T_STRING, T_NAME_QUALIFIED], true)) {
            continue;
        }
        $nome = $texto($i);
        $ant  = $tipo($i - 1);
        if (in_array($ant, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NAMESPACE, T_USE, T_CLASS, T_INTERFACE, T_TRAIT, T_CONST, T_FUNCTION, T_AS], true)) {
            continue;
        }
        $prox       = $tipo($i + 1);
        $textoProx  = $texto($i + 1);
        $textoAnt   = $texto($i - 1);
        $comoClasse = $prox === T_DOUBLE_COLON                                              // Nome::class, Nome::metodo(
            || $ant === T_NEW || $ant === T_INSTANCEOF || $ant === T_EXTENDS || $ant === T_IMPLEMENTS
            || $prox === T_VARIABLE || $prox === T_ELLIPSIS                                  // type hint de parâmetro, propriedade, catch
            || ($textoProx === '&' && $tipo($i + 2) === T_VARIABLE)
            || ($textoAnt === ':' && $texto($i - 2) === ')' && in_array($textoProx, ['{', ';'], true)) // tipo de retorno
            || (in_array($textoAnt, ['|', '?'], true) && in_array($textoProx, ['|', '{', ';'], true))
            || ($textoProx === '|' && in_array($textoAnt, ['(', ',', ':', '?'], true));      // primeiro tipo de uma união
        if (!$comoClasse) {
            continue;
        }
        $primeiro = strtolower(explode(BARRA, $nome)[0]);
        if (in_array($primeiro, $reservadas, true) || isset($declarados[$primeiro])) {
            continue;
        }
        // do mesmo namespace, em disco (nome curto ou relativo)
        $candidato = arquivoDa($namespace . BARRA . $nome, $app);
        if ($candidato && is_file($candidato)) {
            continue;
        }
        $achados[] = [$nome, $tokens[$i][2] ?? 0];
    }

    return $achados;
}

$falhas = 0;
foreach ($arquivos as $arquivo) {
    $rel = substr($arquivo, strlen($raiz) + 1);
    if (!is_file($arquivo)) {
        echo "FALHA $rel (não existe)" . PHP_EOL;
        $falhas++;
        continue;
    }
    $achados = faltando($arquivo, $app, $reservadas);
    if ($achados) {
        $falhas++;
        echo "FALHA $rel: sem import: " . implode(', ', array_map(fn ($a) => "{$a[0]} (linha {$a[1]})", $achados)) . PHP_EOL;
    } else {
        echo "PASSA $rel" . PHP_EOL;
    }
}

echo PHP_EOL . "FALHAS: $falhas" . PHP_EOL;
exit($falhas ? 1 : 0);
