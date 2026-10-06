<?php

// Mapa ao vivo do console: situação do motoboy (SituacaoDoMotoboy, cor do capacete), o MapaController
// (locais de coleta = só os Locais das lojas; situação de cada motoboy da empresa) e o aviso de online no socket
// (AvisarOnlineDoMotoboy + OnlineDoMotoboyMudou).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/mapa.php

namespace Illuminate\Support {
    class Collection implements \IteratorAggregate, \Countable, \JsonSerializable
    {
        public function __construct(protected array $itens = []) {}
        public function jsonSerialize(): mixed { return $this->itens; }
        public function all(): array { return $this->itens; }
        public function count(): int { return count($this->itens); }
        public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->itens); }
        public function get($chave, $padrao = null) { return $this->itens[$chave] ?? $padrao; }
        public function values(): static { return new static(array_values($this->itens)); }
        public function pluck(string $campo): static { return new static(array_map(fn ($item) => $item->$campo ?? null, $this->itens)); }
        public function map(callable $funcao): static
        {
            $saida = [];
            foreach ($this->itens as $chave => $item) {
                $saida[$chave] = $funcao($item, $chave);
            }

            return new static($saida);
        }
        public function groupBy(string $campo): static
        {
            $grupos = [];
            foreach ($this->itens as $item) {
                $grupos[$item->$campo][] = $item;
            }

            return new static(array_map(fn ($grupo) => new static($grupo), $grupos));
        }
    }

    class Carbon extends \DateTimeImmutable
    {
        public function subHours(int $horas): static { return $this->modify("-{$horas} hours"); }
        public function toIso8601String(): string { return $this->format('Y-m-d\TH:i:sP'); }
    }
}

namespace Teste {
    use Illuminate\Support\Collection;

    /** Consulta em memória: aplica os filtros que o controller usa e registra as chamadas. */
    class Consulta
    {
        public static array $registro = [];

        public function __construct(private string $modelo, private array $linhas) {}

        private function anotar(string $chamada): static
        {
            static::$registro[$this->modelo][] = $chamada;

            return $this;
        }

        public function where($coluna, $operador = null, $valor = null): static
        {
            if ($coluna instanceof \Closure) {
                $grupo = new Ou();
                $coluna($grupo);
                $this->anotar('where (' . implode(' ou ', array_keys($grupo->condicoes)) . ')');
                $this->linhas = array_filter($this->linhas, fn ($linha) => (bool) array_filter($grupo->condicoes, fn ($valor, $campo) => ($linha->$campo ?? null) === $valor, ARRAY_FILTER_USE_BOTH));

                return $this;
            }
            if (func_num_args() === 2) {
                [$operador, $valor] = ['=', $operador];
            }
            $this->anotar("where {$coluna} {$operador}");
            $this->linhas = array_filter($this->linhas, function ($linha) use ($coluna, $operador, $valor) {
                $atual = $linha->$coluna ?? null;

                return match ($operador) {
                    '='  => $atual === $valor,
                    '>=' => $atual >= $valor,
                };
            });

            return $this;
        }

        public function whereIn($coluna, $valores): static
        {
            $valores = $valores instanceof Collection ? $valores->all() : $valores;
            $this->linhas = array_filter($this->linhas, fn ($linha) => in_array($linha->$coluna ?? null, $valores, true));

            return $this->anotar("whereIn {$coluna}");
        }

        public function whereNotIn($coluna, array $valores): static
        {
            $this->linhas = array_filter($this->linhas, fn ($linha) => !in_array($linha->$coluna ?? null, $valores, true));

            return $this->anotar("whereNotIn {$coluna}");
        }

        public function whereNotNull($coluna): static
        {
            $this->linhas = array_filter($this->linhas, fn ($linha) => ($linha->$coluna ?? null) !== null);

            return $this->anotar("whereNotNull {$coluna}");
        }

        public function applyDirectivesForPermissions(string $permissao): static { return $this->anotar("permissao {$permissao}"); }

        public function orderBy($coluna): static
        {
            usort($this->linhas, fn ($a, $b) => strcmp((string) $a->$coluna, (string) $b->$coluna));

            return $this->anotar("orderBy {$coluna}");
        }

        public function pluck(string $campo): Collection { return new Collection(array_values(array_map(fn ($linha) => $linha->$campo, $this->linhas))); }

        public function get(array $colunas = ['*']): Collection { return new Collection(array_values($this->linhas)); }

        public function first(array $colunas = ['*']) { return array_values($this->linhas)[0] ?? null; }
    }

    abstract class Modelo
    {
        public static array $todos = [];

        public static function where($coluna, $operador = null, $valor = null)
        {
            return (new Consulta(static::class, static::$todos))->where(...func_get_args());
        }
    }

    class Resposta
    {
        public function __construct(public $dados) {}
    }

    /** Grupo de condições "ou" de um where(fn ($q) => ...). */
    class Ou
    {
        public array $condicoes = [];
        public function where($campo, $valor) { $this->condicoes[$campo] = $valor; return $this; }
        public function orWhere($campo, $valor) { $this->condicoes[$campo] = $valor; return $this; }
    }

    class RespostaHttp
    {
        public function __construct(public int $status = 200) {}
        public function getStatusCode(): int { return $this->status; }
    }

    class Fabrica
    {
        public function json($dados) { return new Resposta(json_decode(json_encode($dados), true)); }
    }
}

namespace Fleetbase\FleetOps\Models {
    class Vendor extends \Teste\Modelo { public static array $todos = []; }
    class Place extends \Teste\Modelo { public static array $todos = []; }
    class Driver extends \Teste\Modelo { public static array $todos = []; }
    class Order extends \Teste\Modelo { public static array $todos = []; }
}

namespace Fleetbase\FleetOps\Http\Resources\v1\Index {
    class Place
    {
        public static function collection($itens) { return ['places' => array_map(fn ($place) => $place->uuid, $itens->all())]; }
    }
}

namespace App\Http\Controllers {
    class Controller {}
}

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1 {
    class DriverController {}
}

namespace Illuminate\Routing {
    class Route
    {
        public function __construct(private string $acao, public array $parametros = []) {}
        public function getActionName() { return $this->acao; }
    }
}

namespace Illuminate\Http {
    class Request
    {
        public function __construct(private ?\Illuminate\Routing\Route $rota = null) {}
        public function route($parametro = null)
        {
            return $parametro === null ? $this->rota : ($this->rota->parametros[$parametro] ?? null);
        }
    }
}

namespace Illuminate\Broadcasting {
    class Channel
    {
        public function __construct(public string $name) {}
    }
}

namespace Illuminate\Contracts\Broadcasting {
    interface ShouldBroadcastNow {}
}

namespace Illuminate\Support\Facades {
    class Log
    {
        public static array $avisos = [];
        public static function warning($mensagem, array $contexto = []) { self::$avisos[] = [$mensagem, $contexto]; }
    }
}

namespace App\Support\Entregas {
    // dublê: a lista real tem teste próprio (pedidos-no-mapa.php)
    class PedidosNoMapa
    {
        public static array $chamadas = [];
        public static function daCentral(string $empresa): array { static::$chamadas[] = ['daCentral', $empresa]; return [['id' => 'order_x']]; }
    }
}

namespace {
    use App\Http\Controllers\Entregas\MapaController;
    use App\Support\Entregas\SituacaoDoMotoboy as S;
    use Fleetbase\FleetOps\Models\Driver;
    use Fleetbase\FleetOps\Models\Order;
    use Fleetbase\FleetOps\Models\Place;
    use Fleetbase\FleetOps\Models\Vendor;
    use Illuminate\Support\Carbon;

    // o LojasController de verdade puxaria o Fleetbase inteiro; o MapaController só usa a constante TIPO_LOJA
    eval('namespace App\Http\Controllers\Entregas; class LojasController { public const TIPO_LOJA = "customer"; }');

    require '/repo/api/app/Support/Entregas/StatusDoPedido.php';
    require '/repo/api/app/Support/Entregas/SituacaoDoMotoboy.php';
    require '/repo/api/app/Http/Controllers/Entregas/MapaController.php';
    require '/repo/api/app/Events/Entregas/OnlineDoMotoboyMudou.php';
    require '/repo/api/app/Http/Middleware/AvisarOnlineDoMotoboy.php';

    // como na produção: o PHP no fuso do app (America/Sao_Paulo), o mesmo da sessão do MySQL
    date_default_timezone_set('America/Sao_Paulo');

    const EMPRESA = 'empresa-a';
    $agora = new Carbon('2026-10-04 09:00:00');

    $sessaoEmpresa = EMPRESA;
    function session($chave) { global $sessaoEmpresa; return $chave === 'company' ? $sessaoEmpresa : null; }

    $transmitidos = [];
    $falharSocket = false;
    function broadcast($evento)
    {
        global $transmitidos, $falharSocket;
        if ($falharSocket) {
            throw new \RuntimeException('socket fora do ar');
        }
        $transmitidos[] = $evento;
    }
    function now() { global $agora; return $agora; }
    function response() { return new \Teste\Fabrica(); }

    $falhas = 0;
    function confere(bool $ok, string $caso): void
    {
        global $falhas;
        if (!$ok) {
            $falhas++;
        }
        echo ($ok ? 'PASSA ' : 'FALHA ') . $caso . PHP_EOL;
    }

    echo '== SituacaoDoMotoboy::classificar' . PHP_EOL;
    confere(S::classificar(true, []) === S::LIVRE, 'online sem pedido: livre (verde)');
    confere(S::classificar(false, []) === S::OFFLINE, 'offline sem pedido: offline (cinza)');
    confere(S::classificar(true, ['started']) === S::COLETA, 'pedido aceito (started): coleta (amarelo)');
    confere(S::classificar(true, ['dispatched']) === S::COLETA, 'pedido atribuído (dispatched): coleta');
    confere(S::classificar(true, ['enroute']) === S::ENTREGA, 'a caminho do cliente (enroute): entrega (vermelho)');
    confere(S::classificar(true, ['started', 'enroute']) === S::ENTREGA, 'dois pedidos: vale o mais adiantado');
    confere(S::classificar(true, ['enroute', 'started']) === S::ENTREGA, 'dois pedidos, outra ordem: entrega');
    confere(S::classificar(false, ['started']) === S::COLETA, 'offline com pedido em andamento: continua ocupado');
    confere(S::classificar(true, ['completed', 'canceled', 'expired']) === S::LIVRE, 'só pedidos encerrados: livre');
    confere(S::classificar(true, ['ENROUTE']) === S::ENTREGA, 'status em maiúsculas');
    confere(S::classificar(true, [null]) === S::COLETA, 'pedido sem status mas atribuído: coleta');

    echo '== MapaController@locaisDeColeta' . PHP_EOL;
    Vendor::$todos = [
        (object) ['company_uuid' => EMPRESA, 'type' => 'customer', 'place_uuid' => 'place-loja-b', 'name' => 'Loja B'],
        (object) ['company_uuid' => EMPRESA, 'type' => 'customer', 'place_uuid' => 'place-loja-a', 'name' => 'Loja A'],
        (object) ['company_uuid' => EMPRESA, 'type' => 'customer', 'place_uuid' => null, 'name' => 'Loja sem local'],
        (object) ['company_uuid' => EMPRESA, 'type' => 'vendor', 'place_uuid' => 'place-fornecedor', 'name' => 'Fornecedor'],
        (object) ['company_uuid' => 'empresa-b', 'type' => 'customer', 'place_uuid' => 'place-outra-empresa', 'name' => 'Loja de outra empresa'],
    ];
    Place::$todos = [
        (object) ['uuid' => 'place-loja-a', 'company_uuid' => EMPRESA, 'location' => 'POINT', 'name' => 'Loja A'],
        (object) ['uuid' => 'place-loja-b', 'company_uuid' => EMPRESA, 'location' => 'POINT', 'name' => 'Loja B'],
        (object) ['uuid' => 'place-cliente-1', 'company_uuid' => EMPRESA, 'location' => 'POINT', 'name' => 'Rua do cliente'],
        (object) ['uuid' => 'place-fornecedor', 'company_uuid' => EMPRESA, 'location' => 'POINT', 'name' => 'Fornecedor'],
        (object) ['uuid' => 'place-outra-empresa', 'company_uuid' => 'empresa-b', 'location' => 'POINT', 'name' => 'Outra'],
    ];
    $locais = (new MapaController())->locaisDeColeta();
    confere($locais['places'] === ['place-loja-a', 'place-loja-b'], 'só os Locais das lojas da empresa, em ordem de nome (sem endereço de cliente nem fornecedor)');
    confere(in_array('permissao fleet-ops list place', \Teste\Consulta::$registro[Place::class], true), 'Place com o filtro de permissão do Fleet-Ops');

    Place::$todos[0]->location = null;
    $locais = (new MapaController())->locaisDeColeta();
    confere($locais['places'] === ['place-loja-b'], 'Local sem coordenada fica fora');

    echo '== MapaController@motoboys' . PHP_EOL;
    Driver::$todos = [
        (object) ['uuid' => 'm-livre', 'public_id' => 'driver_livre', 'company_uuid' => EMPRESA, 'online' => 1],
        (object) ['uuid' => 'm-coleta', 'public_id' => 'driver_coleta', 'company_uuid' => EMPRESA, 'online' => 1],
        (object) ['uuid' => 'm-entrega', 'public_id' => 'driver_entrega', 'company_uuid' => EMPRESA, 'online' => 1],
        (object) ['uuid' => 'm-off', 'public_id' => 'driver_off', 'company_uuid' => EMPRESA, 'online' => 0],
        (object) ['uuid' => 'm-velho', 'public_id' => 'driver_velho', 'company_uuid' => EMPRESA, 'online' => 1],
        (object) ['uuid' => 'm-outra', 'public_id' => 'driver_outra', 'company_uuid' => 'empresa-b', 'online' => 1],
    ];
    $recente = new Carbon('2026-10-04 08:30:00');
    $antigo  = new Carbon('2026-10-03 17:00:00');
    Order::$todos = [
        (object) ['company_uuid' => EMPRESA, 'driver_assigned_uuid' => 'm-livre', 'status' => 'completed', 'updated_at' => $recente],
        (object) ['company_uuid' => EMPRESA, 'driver_assigned_uuid' => 'm-coleta', 'status' => 'started', 'updated_at' => $recente],
        (object) ['company_uuid' => EMPRESA, 'driver_assigned_uuid' => 'm-entrega', 'status' => 'started', 'updated_at' => $recente],
        (object) ['company_uuid' => EMPRESA, 'driver_assigned_uuid' => 'm-entrega', 'status' => 'enroute', 'updated_at' => $recente],
        (object) ['company_uuid' => EMPRESA, 'driver_assigned_uuid' => 'm-velho', 'status' => 'enroute', 'updated_at' => $antigo],
        (object) ['company_uuid' => 'empresa-b', 'driver_assigned_uuid' => 'm-livre', 'status' => 'enroute', 'updated_at' => $recente],
    ];
    $resposta = (new MapaController())->motoboys()->dados['motoboys'];
    $porId    = array_column($resposta, 'situacao', 'uuid');
    confere($porId === ['m-livre' => 'livre', 'm-coleta' => 'coleta', 'm-entrega' => 'entrega', 'm-off' => 'offline', 'm-velho' => 'livre'],
        'livre, coleta, entrega e offline; sem motoboy de outra empresa');
    confere(($porId['m-velho'] ?? null) === 'livre', 'pedido parado há mais de 12 h não ocupa o motoboy');
    confere(array_column($resposta, 'public_id', 'uuid')['m-entrega'] === 'driver_entrega', 'resposta traz o public_id');
    confere(array_column($resposta, 'online', 'uuid')['m-off'] === false && array_column($resposta, 'online', 'uuid')['m-livre'] === true, 'resposta traz o online como booleano');
    confere(in_array('permissao fleet-ops list driver', \Teste\Consulta::$registro[Driver::class], true), 'Driver com o filtro de permissão do Fleet-Ops');
    confere(in_array('where company_uuid =', \Teste\Consulta::$registro[Order::class], true), 'pedidos filtrados pela empresa');
    $completa = (new MapaController())->motoboys()->dados;
    confere(array_keys($completa) === ['motoboys', 'pedidos'], 'resposta { motoboys, pedidos }');
    confere($completa['pedidos'] === [['id' => 'order_x']] && end(\App\Support\Entregas\PedidosNoMapa::$chamadas) === ['daCentral', EMPRESA],
        'pedidos = PedidosNoMapa::daCentral com a empresa da sessão');

    echo '== AvisarOnlineDoMotoboy + OnlineDoMotoboyMudou' . PHP_EOL;
    $acao    = \Fleetbase\FleetOps\Http\Controllers\Api\v1\DriverController::class . '@toggleOnline';
    $proximo = fn ($status) => fn ($request) => new \Teste\RespostaHttp($status);
    $toggle  = fn ($id, $acaoDaRota = null) => new \Illuminate\Http\Request(new \Illuminate\Routing\Route($acaoDaRota ?? $acao, ['id' => $id]));
    $avisar  = new \App\Http\Middleware\AvisarOnlineDoMotoboy();
    Driver::$todos[3]->online = 1; // m-off acabou de ligar o online

    $resposta = $avisar->handle($toggle('driver_off'), $proximo(200));
    $evento   = $transmitidos[0] ?? null;
    confere($resposta->getStatusCode() === 200 && count($transmitidos) === 1, 'toggle-online com sucesso: um aviso no socket e a resposta intacta');
    confere($evento?->broadcastOn()[0]->name === 'company.' . EMPRESA, 'aviso no canal company.<uuid da empresa>');
    confere($evento?->broadcastAs() === 'entregas.motoboy_online', 'nome do evento entregas.motoboy_online (o filtro do console espera este)');
    $dados = $evento?->broadcastWith();
    confere(($dados['event'] ?? null) === 'entregas.motoboy_online' && $dados['data'] === ['id' => 'driver_off', 'uuid' => 'm-off', 'online' => true], 'dados: public_id, uuid e o online já gravado');

    $transmitidos = [];
    $avisar->handle($toggle('m-coleta'), $proximo(200));
    confere(count($transmitidos) === 1 && $transmitidos[0]->motoboyPublicId === 'driver_coleta', 'acha o motoboy também pelo uuid');

    $transmitidos = [];
    $avisar->handle($toggle('driver_outra'), $proximo(200));
    confere($transmitidos === [], 'motoboy de outra empresa: nenhum aviso');

    $avisar->handle($toggle('driver_off'), $proximo(404));
    confere($transmitidos === [], 'resposta de erro: nenhum aviso');

    $avisar->handle($toggle('driver_off', 'Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController@startOrder'), $proximo(200));
    confere($transmitidos === [], 'outra rota da API v1: nenhum aviso');

    $sessaoEmpresa = null;
    $avisar->handle($toggle('driver_off'), $proximo(200));
    confere($transmitidos === [], 'sem empresa na sessão: nenhum aviso');
    $sessaoEmpresa = EMPRESA;

    $falharSocket = true;
    $resposta     = $avisar->handle($toggle('driver_off'), $proximo(200));
    confere($resposta->getStatusCode() === 200 && count(\Illuminate\Support\Facades\Log::$avisos) === 1, 'socket fora do ar: a resposta segue e fica um aviso no log');
    $falharSocket = false;

    echo PHP_EOL . "FALHAS: {$falhas}" . PHP_EOL;
}
