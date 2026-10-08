<?php

// Posição do motoboy (POST v1/drivers/{id}/track) sem geocodificação no Google (DriverControllerSemGeocodificacao):
// o track() do Fleet-Ops geocodificava cada posição (cidade vazia no Brasil = consulta paga a cada ping). O nosso
// track() troca o geocoder por um desligado só durante a chamada e devolve o original depois, inclusive com exceção.
// Também confere, nos arquivos reais do fleetops-api 0.6.65 (cópia em packages/fleetops), que o Geocoder::reverse do
// track() continua sendo o único ponto de geocodificação desse caminho.
// Antes do track() do Fleet-Ops, o FiltroDePosicaoDoMotoboy (regras em posicao-do-motoboy.php): a posição descartada
// não chega ao track() original e responde com o motoboy; a aceita passa e vira a última no cache.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/track-sem-geocodificacao.php

namespace Teste {
    /** Container mínimo: binding com closure (compartilhado), instance e forgetInstance, como o do Laravel. */
    class Container
    {
        public array $bindings  = [];
        public array $instances = [];

        public function singleton(string $nome, \Closure $fabrica): void
        {
            $this->bindings[$nome] = $fabrica;
        }

        public function instance(string $nome, $objeto)
        {
            $this->instances[$nome] = $objeto;

            return $objeto;
        }

        public function forgetInstance(string $nome): void
        {
            unset($this->instances[$nome]);
        }

        public function make(string $nome)
        {
            if (!array_key_exists($nome, $this->instances)) {
                $this->instances[$nome] = ($this->bindings[$nome])();
            }

            return $this->instances[$nome];
        }
    }

    /** O geocoder de verdade (ProviderAndDumperAggregator): conta as consultas e sempre acha um endereço. */
    class GeocoderReal
    {
        public int $consultas = 0;

        public function reverse($latitude, $longitude): self
        {
            $this->consultas++;

            return $this;
        }

        public function geocode(string $texto): self
        {
            $this->consultas++;

            return $this;
        }

        public function get(): \Illuminate\Support\Collection
        {
            return collect(['endereco']);
        }
    }
}

namespace Illuminate\Support {
    class Collection
    {
        public function __construct(public array $itens = [])
        {
        }

        public function first()
        {
            return $this->itens[0] ?? null;
        }

        public function isEmpty(): bool
        {
            return $this->itens === [];
        }
    }
}

namespace Illuminate\Support\Facades {
    /** O essencial da Facade do Laravel 10: instância resolvida em cache estático, swap e clearResolvedInstance. */
    abstract class Facade
    {
        protected static $app;
        protected static $resolvedInstance = [];

        public static function setFacadeApplication($app): void
        {
            static::$app = $app;
        }

        public static function swap($instance): void
        {
            static::$resolvedInstance[static::getFacadeAccessor()] = $instance;
            if (isset(static::$app)) {
                static::$app->instance(static::getFacadeAccessor(), $instance);
            }
        }

        public static function getFacadeRoot()
        {
            $nome = static::getFacadeAccessor();
            if (isset(static::$resolvedInstance[$nome])) {
                return static::$resolvedInstance[$nome];
            }

            return static::$resolvedInstance[$nome] = static::$app->make($nome);
        }

        public static function clearResolvedInstance($name): void
        {
            unset(static::$resolvedInstance[$name]);
        }

        public static function __callStatic($metodo, $argumentos)
        {
            return static::getFacadeRoot()->$metodo(...$argumentos);
        }
    }
}

namespace Illuminate\Support\Facades {
    /** Cache em memória (get/put com prazo). */
    class Cache
    {
        public static array $itens  = [];
        public static array $prazos = [];
        public static bool $fora    = false;

        public static function get(string $chave)
        {
            if (static::$fora) {
                throw new \RuntimeException('Redis fora do ar');
            }

            return static::$itens[$chave] ?? null;
        }

        public static function put(string $chave, $valor, int $segundos): void
        {
            if (static::$fora) {
                throw new \RuntimeException('Redis fora do ar');
            }
            static::$itens[$chave]  = $valor;
            static::$prazos[$chave] = $segundos;
        }
    }

    /** Log que guarda as linhas. */
    class Log
    {
        public static array $linhas = [];

        public static function info(string $mensagem, array $contexto = []): void
        {
            static::$linhas[] = ['info', $mensagem, $contexto];
        }

        public static function warning(string $mensagem, array $contexto = []): void
        {
            static::$linhas[] = ['warning', $mensagem, $contexto];
        }
    }
}

namespace Illuminate\Database\Eloquent {
    class ModelNotFoundException extends \RuntimeException
    {
    }
}

namespace Geocoder\Laravel\Facades {
    class Geocoder extends \Illuminate\Support\Facades\Facade
    {
        protected static function getFacadeAccessor()
        {
            return 'geocoder';
        }
    }
}

namespace Illuminate\Http {
    class Request
    {
        public function __construct(public array $dados = [])
        {
        }

        public function input(string $chave)
        {
            return $this->dados[$chave] ?? null;
        }
    }
}

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1 {
    use Geocoder\Laravel\Facades\Geocoder;
    use Illuminate\Http\Request;

    /** O track() do Fleet-Ops reduzido ao que importa: grava a posição e geocodifica pela facade. */
    class DriverController
    {
        public array $cidadesGravadas  = [];
        public array $posicoesGravadas = [];

        public function track(string $id, Request $request)
        {
            if ($request->input('falhar')) {
                throw new \RuntimeException('falha no meio do track');
            }

            $this->posicoesGravadas[] = [$request->input('latitude'), $request->input('longitude')];

            $geocoded = Geocoder::reverse($request->input('latitude'), $request->input('longitude'))->get()->first();
            if ($geocoded) {
                $this->cidadesGravadas[] = $geocoded;
            }

            return 'motoboy ' . $id;
        }

        protected function findDriver(string $id, array $with = [])
        {
            if ($id === 'driver_sumido') {
                throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
            }

            return 'registro ' . $id;
        }

        protected function driverResource($driver)
        {
            return 'recurso ' . $driver;
        }

        protected function apiError(string $message, int $status = 400)
        {
            return "erro {$status}: {$message}";
        }
    }
}

namespace {
    use App\Http\Controllers\Entregas\DriverControllerSemGeocodificacao;
    use Geocoder\Laravel\Facades\Geocoder;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\Facade;
    use Illuminate\Support\Facades\Log;

    $GLOBALS['falhas'] = 0;

    function confere(bool $ok, string $descricao): void
    {
        if (!$ok) {
            $GLOBALS['falhas']++;
        }
        echo ($ok ? 'PASSA ' : 'FALHA ') . $descricao . PHP_EOL;
    }

    function collect(array $itens = []): Illuminate\Support\Collection
    {
        return new Illuminate\Support\Collection($itens);
    }

    function app(?string $nome = null)
    {
        return $nome === null ? $GLOBALS['container'] : $GLOBALS['container']->make($nome);
    }

    require '/repo/api/app/Support/Entregas/GeocoderDesligado.php';
    require '/repo/api/app/Support/Entregas/FiltroDePosicaoDoMotoboy.php';
    require '/repo/api/app/Http/Controllers/Entregas/DriverControllerSemGeocodificacao.php';

    /** Container novo com o geocoder de verdade registrado (como o provider do geocoder-laravel). */
    function cenario(): Teste\GeocoderReal
    {
        Cache::$itens  = [];
        Cache::$prazos = [];
        Cache::$fora   = false;
        Log::$linhas   = [];
        $real                 = new Teste\GeocoderReal();
        $GLOBALS['container'] = new Teste\Container();
        $GLOBALS['container']->singleton('geocoder', fn () => $real);
        Facade::setFacadeApplication($GLOBALS['container']);
        Geocoder::clearResolvedInstance('geocoder');

        return $real;
    }

    function posicao(array $extra = []): Request
    {
        return new Request(['latitude' => -21.17, 'longitude' => -47.81] + $extra);
    }

    /** Corpo de um método (do "function <nome>(" até a chave que fecha), sem comentários. */
    function corpoDoMetodo(string $arquivo, string $metodo): string
    {
        $codigo = file_get_contents($arquivo);
        if (!preg_match('/function\s+' . $metodo . '\s*\(/', $codigo, $achou, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $inicio = strpos($codigo, '{', $achou[0][1]);
        $nivel  = 0;
        for ($i = $inicio; $i < strlen($codigo); $i++) {
            $nivel += $codigo[$i] === '{' ? 1 : ($codigo[$i] === '}' ? -1 : 0);
            if ($nivel === 0) {
                break;
            }
        }
        $corpo = substr($codigo, $inicio, $i - $inicio + 1);

        return preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $corpo);
    }

    echo '== O nosso track() não consulta o Google' . PHP_EOL;
    $real     = cenario();
    $resposta = (new DriverControllerSemGeocodificacao())->track('driver_a', posicao());
    confere($real->consultas === 0, 'motoboy sem cidade: nenhuma consulta ao geocoder');
    confere($resposta === 'motoboy driver_a', 'a resposta do track() do Fleet-Ops volta igual');

    $real     = cenario();
    $controle = new DriverControllerSemGeocodificacao();
    for ($i = 0; $i < 50; $i++) {
        $controle->track('driver_a', posicao());
    }
    confere($real->consultas === 0 && $controle->cidadesGravadas === [], '50 posições seguidas: nenhuma consulta e nada gravado como cidade');

    echo '== O geocoder volta depois do track()' . PHP_EOL;
    $real = cenario();
    Geocoder::reverse(1, 2)->get();
    confere($real->consultas === 1, 'antes: a facade usa o geocoder de verdade (e fica em cache)');
    (new DriverControllerSemGeocodificacao())->track('driver_a', posicao());
    Geocoder::reverse(1, 2)->get();
    confere($real->consultas === 2, 'depois: a facade volta ao geocoder de verdade');
    confere(app('geocoder') === $real, 'depois: o container devolve o geocoder de verdade');

    $real = cenario();
    try {
        (new DriverControllerSemGeocodificacao())->track('driver_a', posicao(['falhar' => true]));
        $erro = null;
    } catch (RuntimeException $e) {
        $erro = $e->getMessage();
    }
    confere($erro === 'falha no meio do track', 'exceção no track() do Fleet-Ops: sobe como veio');
    Geocoder::geocode('Rua X')->get();
    confere($real->consultas === 1 && app('geocoder') === $real, 'exceção no track(): o geocoder de verdade volta mesmo assim');

    $real = cenario();
    $desligado = new App\Support\Entregas\GeocoderDesligado();
    confere($desligado->reverse(1, 2)->get()->isEmpty() && $desligado->geocode('x')->limit(1)->get()->first() === null, 'GeocoderDesligado: qualquer consulta encadeada devolve vazio');

    echo '== Filtro da posição antes do track() do Fleet-Ops' . PHP_EOL;
    $chave = 'entregas:motoboy-ultima-posicao:driver_a';
    $iso   = fn (int $segundosAtras) => gmdate('Y-m-d\TH:i:s', time() - $segundosAtras) . '.000Z';

    $real     = cenario();
    $controle = new DriverControllerSemGeocodificacao();
    $resposta = $controle->track('driver_a', posicao(['timestamp' => $iso(5), 'accuracy' => 8]));
    confere($resposta === 'motoboy driver_a' && count($controle->posicoesGravadas) === 1, 'posição nova: passa ao track() do Fleet-Ops');
    $guardada = Cache::get($chave);
    confere(is_array($guardada) && $guardada['precisao'] === 8.0 && abs($guardada['instante'] - (time() - 5) * 1000) < 1000, 'a aceita vira a última no cache (instante e precisão)');
    confere((Cache::$prazos[$chave] ?? null) === 86400, 'o cache guarda a última por 24 h');
    confere($real->consultas === 0, 'com o filtro, o track() continua sem consultar o Google');

    $resposta = $controle->track('driver_a', posicao(['timestamp' => $iso(240), 'accuracy' => 8, 'latitude' => -21.0]));
    confere($resposta === 'recurso registro driver_a', 'posição de 4 min atrás (cache do batimento): responde o motoboy, com 200');
    confere(count($controle->posicoesGravadas) === 1, 'posição antiga: o track() do Fleet-Ops não roda (nada gravado, nada no socket)');
    confere(Cache::get($chave) === $guardada, 'posição antiga: a última no cache não muda');
    confere(count(Log::$linhas) === 1 && Log::$linhas[0][1] === '[entregas] posição do motoboy descartada (antiga)' && Log::$linhas[0][2] === ['motoboy' => 'driver_a', 'precisao' => 8.0], 'posição antiga: log com o motivo, o id e a precisão (sem coordenadas)');

    $controle->track('driver_a', posicao(['timestamp' => $iso(1), 'accuracy' => 1800]));
    confere(count($controle->posicoesGravadas) === 1 && str_ends_with(Log::$linhas[1][1] ?? '', '(imprecisa)'), 'posição de 1,8 km de precisão logo depois de uma boa: descartada');

    $controle->track('driver_a', posicao(['timestamp' => $iso(0), 'accuracy' => 10]));
    confere(count($controle->posicoesGravadas) === 2, 'a boa seguinte passa');

    $controle->track('driver_a', posicao());
    confere(count($controle->posicoesGravadas) === 3, 'sem timestamp nem precisão (SDK, APK antigo): passa');

    cenario();
    $controle = new DriverControllerSemGeocodificacao();
    $resposta = $controle->track('driver_a', new Request(['latitude' => '', 'longitude' => null, 'timestamp' => $iso(0)]));
    confere($resposta === 'motoboy driver_a' && Cache::get($chave) === null, 'sem latitude e longitude: vai direto ao original, sem mexer no cache');

    cenario();
    Cache::put('entregas:motoboy-ultima-posicao:driver_sumido', ['instante' => (time() + 60) * 1000, 'precisao' => 5.0, 'em' => time() * 1000], 60);
    $resposta = (new DriverControllerSemGeocodificacao())->track('driver_sumido', posicao(['timestamp' => $iso(0)]));
    confere($resposta === 'erro 404: Driver resource not found.', 'descartada de motoboy que não existe: 404 como o original');

    cenario();
    $controle = new DriverControllerSemGeocodificacao();
    try {
        $controle->track('driver_a', posicao(['timestamp' => $iso(0), 'falhar' => true]));
    } catch (RuntimeException $e) {
    }
    confere(Cache::get($chave) === null, 'exceção no track() do Fleet-Ops: a posição não vira a última');

    cenario();
    Cache::$fora = true;
    $controle    = new DriverControllerSemGeocodificacao();
    $resposta    = $controle->track('driver_a', posicao(['timestamp' => $iso(0), 'accuracy' => 8]));
    confere($resposta === 'motoboy driver_a' && count($controle->posicoesGravadas) === 1, 'cache fora do ar: a posição passa sem filtro');
    confere((Log::$linhas[0][0] ?? null) === 'warning' && str_contains(Log::$linhas[0][1], 'cache da última posição falhou: RuntimeException'), 'cache fora do ar: warning só com a classe do erro');

    echo '== Fleet-Ops real (fleetops-api 0.6.65, cópia em packages/fleetops)' . PHP_EOL;
    $raizFleetOps = '/repo/packages/fleetops/server/src/Http/Controllers/';
    $apiDriver    = file_get_contents($raizFleetOps . 'Api/v1/DriverController.php');
    $track        = corpoDoMetodo($raizFleetOps . 'Api/v1/DriverController.php', 'track');
    confere(str_contains($apiDriver, 'use Geocoder\Laravel\Facades\Geocoder;'), 'Api\v1\DriverController usa a facade Geocoder do geocoder-laravel');
    confere(substr_count($track, 'Geocoder::') === 1 && str_contains($track, 'Geocoder::reverse('), 'track(): a única consulta é o Geocoder::reverse (a que o nosso track() desliga)');
    foreach (['Geocoding::', 'PlaceSearch', 'createFromCoordinates', 'createFromReverseGeocodingLookup', 'createFromGeocodingLookup', 'Http::', 'GoogleMaps'] as $outra) {
        confere(!str_contains($track, $outra), "track(): não chama {$outra} (outra porta para o Google, fora do nosso desligamento)");
    }
    confere(str_contains(corpoDoMetodo($raizFleetOps . 'Internal/v1/DriverController.php', 'track'), 'app(ApiDriverController::class)->track('), 'Internal\v1\DriverController@track usa o da API v1 pelo container (cai no nosso)');
    foreach (['VehicleController', 'TrailerController'] as $outro) {
        $corpo = corpoDoMetodo($raizFleetOps . 'Api/v1/' . $outro . '.php', 'track');
        confere($corpo !== '' && !preg_match('/Geocod|PlaceSearch|createFrom/', $corpo), "{$outro}@track não geocodifica");
    }

    $lock   = json_decode(file_get_contents('/repo/api/composer.lock'), true);
    $versao = null;
    foreach ($lock['packages'] as $pacote) {
        if ($pacote['name'] === 'fleetbase/fleetops-api') {
            $versao = $pacote['version'];
        }
    }
    $copia = json_decode(file_get_contents('/repo/packages/fleetops/composer.json'), true)['version'] ?? null;
    confere($versao !== null && $versao === $copia, "a cópia em packages/fleetops ({$copia}) é a versão da produção ({$versao})");

    echo '== Ligação no container' . PHP_EOL;
    $provedor = file_get_contents('/repo/api/app/Providers/AppServiceProvider.php');
    preg_match('/public function register\(\)\s*\{(.*?)\n    \}/s', $provedor, $register);
    confere(str_contains($register[1] ?? '', '$this->app->bind(ApiDriverController::class, DriverControllerSemGeocodificacao::class)'), 'AppServiceProvider::register troca o Api\v1\DriverController pelo nosso');
    confere(str_contains($provedor, 'use Fleetbase\FleetOps\Http\Controllers\Api\v1\DriverController as ApiDriverController;'), 'AppServiceProvider importa o controller certo');
    $nosso = file_get_contents('/repo/api/app/Http/Controllers/Entregas/DriverControllerSemGeocodificacao.php');
    confere(str_contains($nosso, 'extends DriverController') && preg_match_all('/public function /', $nosso) === 1, 'o nosso controller só sobrescreve o track(); o resto é o do Fleet-Ops');
    foreach (['findDriver(string $id', 'driverResource(Driver $driver)', 'apiError(string $message'] as $assinatura) {
        confere(str_contains($apiDriver, 'protected function ' . $assinatura), "Api\\v1\\DriverController ainda tem o protected {$assinatura}…) que o descarte usa");
    }
    confere(str_contains($track, 'if (empty($latitude) && empty($longitude))') && str_contains($track, '$latitude  = (float) $request->input(\'latitude\')'), 'track(): sem latitude e longitude o original só devolve o motoboy (o nosso repete a conta antes do filtro)');

    echo PHP_EOL . 'FALHAS: ' . $GLOBALS['falhas'] . PHP_EOL;
}
