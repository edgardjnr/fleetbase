<?php

// Stubs dos models do Fleetbase (e do Request, do Validator e do Auth) para os testes da integração iFood que criam e despacham
// pedidos (ifood-criador.php, ifood-processar.php, ifood-agendador.php) e da tela Lojas (ifood-lojas.php).
// Carregar depois do stubs-ifood.php. Cada model guarda os seus objetos numa lista estática ($todos), e as consultas
// (where/orWhere/closure) filtram essa lista com a precedência do SQL.

namespace Fleetbase\LaravelMysqlSpatial\Types {
    class Point
    {
        public function __construct(private float $latitude, private float $longitude) {}
        public function getLat() { return $this->latitude; }
        public function getLng() { return $this->longitude; }
    }
}

namespace Fleetbase\FleetOps\Support {
    class Utils
    {
        public static function getMutationType($modelo): string { return 'fleet-ops:' . strtolower((new \ReflectionClass($modelo))->getShortName()); }
        // o real confere instanceof Point; aqui também os pontos falsos dos testes (getLat/getLng)
        public static function isPoint($p): bool { return is_object($p) && method_exists($p, 'getLat') && method_exists($p, 'getLng'); }
    }
}

namespace Fleetbase\Support {
    class Auth
    {
        public static $usuario = null;
        public static function getUserFromSession($request = null) { return self::$usuario; }
    }
}

namespace App\Http\Controllers {
    // o real estende o Controller do Laravel; os nossos controllers só herdam dele
    class Controller {}
}

namespace Illuminate\Routing {
    // a rota que o roteador casou: só a ação (Controller@metodo) e os parâmetros
    class Route
    {
        public function __construct(public string $acao, public array $parametros = []) {}
        public function getActionName(): string { return $this->acao; }
    }
}

namespace Illuminate\Http {
    class Request
    {
        /** Bearer token (MotoboyDaSessao: token de usuário tem "|"). */
        public ?string $token = null;
        /** A rota casada (middlewares que olham a ação), ou null. */
        public ?\Illuminate\Routing\Route $rota = null;

        /** O método HTTP (middlewares que só olham o GET). */
        public string $metodo = 'GET';

        public function __construct(public array $dados = []) {}

        public function all(): array { return $this->dados; }
        public function method(): string { return $this->metodo; }
        public function boolean($chave, $padrao = false): bool { return filter_var($this->dados[$chave] ?? $padrao, FILTER_VALIDATE_BOOLEAN); }
        public function bearerToken(): ?string { return $this->token; }
        public function input($chave = null, $padrao = null) { return $chave === null ? $this->dados : ($this->dados[$chave] ?? $padrao); }
        public function array($chave): array { return (array) ($this->dados[$chave] ?? []); }
        public function ip(): string { return '127.0.0.1'; }

        // como o do Laravel: sem parâmetro, a rota; com nome, o parâmetro da rota
        public function route($parametro = null)
        {
            if ($parametro === null) {
                return $this->rota;
            }

            return $this->rota?->parametros[$parametro] ?? null;
        }

        // as regras do Laravel não rodam aqui: devolve só os campos que têm regra
        public function validate(array $regras): array
        {
            $validos = [];
            foreach (array_keys($regras) as $campo) {
                if (array_key_exists($campo, $this->dados)) {
                    $validos[$campo] = $this->dados[$campo];
                }
            }

            return $validos;
        }
    }
}

namespace Illuminate\Support\Facades {
    // o Validator::make do Laravel, só com as regras nullable, string e max:N (a primeira regra que falha encerra o
    // campo). A mensagem é a personalizada ("campo.regra") ou, sem ela, "sem mensagem: campo.regra" (o Laravel sairia em
    // inglês): assim o teste pega a regra sem texto em pt-BR
    class Validator
    {
        public static function make(array $dados, array $regras, array $mensagens = []) { return new \Teste\Validacao($dados, $regras, $mensagens); }
    }
}

namespace Teste {
    class MensagensDeValidacao
    {
        public function __construct(private array $porCampo) {}
        public function all(): array { return array_merge(...array_values($this->porCampo ?: [[]])); }
        public function toArray(): array { return $this->porCampo; }
    }

    class Validacao
    {
        private array $erros = [];

        public function __construct(private array $dados, private array $regras, array $mensagens)
        {
            foreach ($regras as $campo => $lista) {
                $valor = $dados[$campo] ?? null;
                if ($valor === null && in_array('nullable', $lista, true)) {
                    continue;
                }
                foreach ($lista as $regra) {
                    [$nome, $parametro] = array_pad(explode(':', $regra, 2), 2, null);
                    $falhou = match ($nome) {
                        'nullable' => false,
                        'string'   => !is_string($valor),
                        'max'      => is_string($valor) && mb_strlen($valor) > (int) $parametro,
                        default    => throw new \LogicException("regra não suportada no stub: {$regra}"),
                    };
                    if ($falhou) {
                        $this->erros[$campo][] = $mensagens["{$campo}.{$nome}"] ?? "sem mensagem: {$campo}.{$nome}";
                        break;
                    }
                }
            }
        }

        public function fails(): bool { return (bool) $this->erros; }
        public function errors(): MensagensDeValidacao { return new MensagensDeValidacao($this->erros); }

        public function validated(): array
        {
            if ($this->erros) {
                throw new \LogicException('validated() com erros (o Laravel lançaria ValidationException)');
            }

            return array_intersect_key($this->dados, $this->regras);
        }
    }

    // consulta de model sobre uma lista de objetos: OU de grupos E; where com closure vira um subgrupo
    class ConsultaDeModelo
    {
        private array $grupos = [[]];
        public function __construct(private array $itens) {}

        public function where($coluna, $valor = null)
        {
            $this->grupos[array_key_last($this->grupos)][] = $coluna instanceof \Closure ? $this->subgrupo($coluna) : fn ($item) => ($item->$coluna ?? null) === $valor;

            return $this;
        }

        public function whereIn($coluna, array $valores)
        {
            $this->grupos[array_key_last($this->grupos)][] = fn ($item) => in_array($item->$coluna ?? null, $valores, true);

            return $this;
        }

        public function orWhere($coluna, $valor = null)
        {
            $this->grupos[] = [];

            return $this->where($coluna, $valor);
        }

        public function get()
        {
            return array_values(array_filter($this->itens, fn ($item) => $this->passa($item)));
        }

        public function first()
        {
            foreach ($this->itens as $item) {
                if ($this->passa($item)) {
                    return $item;
                }
            }

            return null;
        }

        // when($valor, fn): aplica o filtro só com valor (o when do query builder); with(): relações não importam aqui
        public function when($valor, \Closure $filtro)
        {
            if ($valor) {
                $filtro($this, $valor);
            }

            return $this;
        }

        public function with($relacoes) { return $this; }

        public function firstOrFail()
        {
            return $this->first() ?? throw new \RuntimeException('404: registro não encontrado');
        }

        public function passa($item): bool
        {
            if ($this->grupos === [[]]) {
                return true;
            }
            foreach ($this->grupos as $grupo) {
                $todos = (bool) $grupo;
                foreach ($grupo as $filtro) {
                    if (!$filtro($item)) {
                        $todos = false;
                        break;
                    }
                }
                if ($todos) {
                    return true;
                }
            }

            return false;
        }

        private function subgrupo(\Closure $definicao): \Closure
        {
            $sub = new static([]);
            $definicao($sub);

            return fn ($item) => $sub->passa($item);
        }
    }

    trait ModeloDeTeste
    {
        public static array $todos = [];

        public function __construct(array $atributos = [])
        {
            foreach ($atributos as $chave => $valor) {
                $this->$chave = $valor;
            }
        }

        public static function where($coluna, $valor = null) { return (new ConsultaDeModelo(static::$todos))->where($coluna, $valor); }
        public static function whereIn($coluna, array $valores) { return (new ConsultaDeModelo(static::$todos))->whereIn($coluna, $valores); }
        public function refresh() { return $this; }
    }
}

namespace Fleetbase\FleetOps\Models {
    #[\AllowDynamicProperties]
    class Vendor
    {
        use \Teste\ModeloDeTeste;
        public $uuid;
        public $public_id;
        public $company_uuid;
        public $type = 'customer';
        public $name;
        public $place_uuid;
    }

    #[\AllowDynamicProperties]
    class Place
    {
        use \Teste\ModeloDeTeste;
        public static array $criados = [];
        public $uuid;
        public $company_uuid;
        public $location;
        public $name;

        public static function create(array $atributos): static
        {
            $place          = new static($atributos);
            $place->uuid  ??= 'place-novo-' . (count(self::$criados) + 1);
            self::$criados[] = $place;
            self::$todos[]   = $place;

            return $place;
        }
    }

    #[\AllowDynamicProperties]
    class Payload
    {
        public static array $salvos = [];
        public $uuid;
        public $company_uuid;
        public $pickup;
        public $dropoff;
        public $atual;

        public function setPickup($place, array $opcoes = [])
        {
            $this->pickup = $place;
            if (isset($opcoes['callback'])) {
                ($opcoes['callback'])($place, $this);
            }

            return $this;
        }

        public function setDropoff($place) { $this->dropoff = $place; return $this; }
        public function getPickupOrFirstWaypoint() { return $this->pickup; }
        public function getDropoffOrLastWaypoint() { return $this->dropoff; }
        public function setCurrentWaypoint($place) { $this->atual = $place; return $this; }

        public function save()
        {
            $this->uuid ??= 'payload-' . (count(self::$salvos) + 1);
            self::$salvos[] = $this;

            return true;
        }
    }

    #[\AllowDynamicProperties]
    class OrderConfig
    {
        use \Teste\ModeloDeTeste;
        public $uuid;
        public $key;
        public $company_uuid;
        public $namespace;

        public static function default()
        {
            foreach (self::$todos as $config) {
                if ($config->company_uuid === session('company') && $config->namespace === 'system:order-config:transport') {
                    return $config;
                }
            }

            return null;
        }
    }

    #[\AllowDynamicProperties]
    class Order
    {
        use \Teste\ModeloDeTeste;
        public static array $criados = [];
        public static bool $falharDespacho = false;
        public $uuid;
        public $public_id;
        public $company_uuid;
        public $status = 'created';
        public $dispatched = false;
        public $adhoc = false;
        public $scheduled_at = null;
        public $started = false;
        public $driver_assigned_uuid = null;
        public $deleted_at = null;
        public array $chamadas = [];
        /** O adhoc com que o pedido foi criado (o despacho liga o adhoc depois). */
        public ?bool $adhocAoCriar = null;
        /** Em cada firstDispatchWithActivity/insertDispatchActivity: se a trava do pedido (TravaDoPedido) estava tomada. */
        public array $travadoNoDespacho = [];
        public bool $temStatusDespachado = false;
        /** Campos que mudaram no último save (o wasChanged do Eloquent). */
        public array $alterados = [];
        /** Com true, o cancel() lança (o OrderCanceled falhou); com uma exceção, lança ela. */
        public static bool|\Throwable $falharCancelamento = false;
        /** Em cada cancel(): se a trava do pedido (TravaDoPedido) e a das ações (AcoesIfood) estavam tomadas. */
        public array $travadoNoCancelar = [];
        /** A empresa da sessão no momento do create (o TrackingNumberObserver depende dela). */
        public ?string $empresaNaSessao = null;

        public static function create(array $atributos): static
        {
            $pedido                  = new static($atributos);
            $numero                  = count(self::$criados) + 1;
            $pedido->uuid          ??= 'order-uuid-' . $numero;
            $pedido->public_id     ??= 'order_' . $numero;
            $pedido->empresaNaSessao = session('company');
            $pedido->adhocAoCriar    = $pedido->adhoc;
            self::$criados[]         = $pedido;
            self::$todos[]           = $pedido;

            return $pedido;
        }

        // relê "do banco": o objeto guardado com o mesmo uuid (null se sumiu); como o fresh() do Eloquent, que ignora os
        // escopos globais, o apagado (deleted_at) também volta
        public function fresh()
        {
            foreach (self::$todos as $pedido) {
                if ($pedido->uuid === $this->uuid) {
                    return $pedido;
                }
            }

            return null;
        }

        /** O adhoc em cada saveQuietly (o despacho grava o adhoc antes de despachar). */
        public array $adhocAoSalvar = [];

        /** Em cada saveQuietly: se a trava do pedido (TravaDoPedido) estava tomada. */
        public array $travadoNoSalvar = [];

        public function saveQuietly() { $this->chamadas[] = 'saveQuietly'; $this->adhocAoSalvar[] = $this->adhoc; $this->travadoNoSalvar[] = isset(\Teste\Trava::$ocupadas['entregas:pedido:' . $this->uuid]); return true; }
        private function anotarTrava(): void { $this->travadoNoDespacho[] = isset(\Teste\Trava::$ocupadas['entregas:pedido:' . $this->uuid]); }
        public function hasDispatchedStatus(): bool { return $this->temStatusDespachado; }

        // o que o HandleOrderDispatched (e o DistribuirPedidoAberto) usa no despacho
        public function save() { $this->chamadas[] = 'save'; return true; }
        public function flushAttributesCache() { return $this; }
        public function load($relacoes) { $this->chamadas[] = 'load'; return $this; }
        public function setStatus($codigo) { $this->chamadas[] = 'setStatus'; $this->status = $codigo; return true; }
        public function createActivity($atividade, $local = null) { $this->chamadas[] = 'createActivity'; return $atividade; }
        public function getLastLocation() { return null; }
        public function getPickupLocation() { return $this->payload->pickup->location ?? null; }
        public function getAdhocDistance() { return $this->adhoc_distance ?? 6000; }

        /** uuids dos pedidos que passaram por um newCollection(...)->loadMissing(...) (o carregamento em lote do Eloquent). */
        public static array $carregados = [];

        public function newCollection(array $modelos = [])
        {
            return new class($modelos) {
                public function __construct(private array $modelos) {}
                public function loadMissing($relacoes) { foreach ($this->modelos as $m) { Order::$carregados[] = $m->uuid; } return $this; }
            };
        }

        public function wasChanged($campos = null): bool
        {
            $campos = (array) $campos;

            return $campos === [] ? (bool) $this->alterados : (bool) array_intersect($campos, $this->alterados);
        }

        // o Order::cancel do Fleet-Ops: atividade "canceled", status canceled e OrderCanceled na fila
        public function cancel()
        {
            $this->chamadas[]          = 'cancel';
            $this->travadoNoCancelar[] = [isset(\Teste\Trava::$ocupadas['entregas:pedido:' . $this->uuid]), isset(\Teste\Trava::$ocupadas['entregas:ifood-acao:' . $this->uuid])];
            if (self::$falharCancelamento) {
                throw self::$falharCancelamento instanceof \Throwable ? self::$falharCancelamento : new \RuntimeException('falha no cancelamento');
            }
            $this->status = 'canceled';

            return true;
        }

        public function firstDispatchWithActivity()
        {
            $this->chamadas[] = 'firstDispatchWithActivity';
            $this->anotarTrava();
            if (self::$falharDespacho) {
                throw new \RuntimeException('falha no despacho');
            }
            $this->dispatched          = true;
            $this->status              = 'dispatched';
            $this->temStatusDespachado = true;

            return $this;
        }

        public function insertDispatchActivity()
        {
            $this->chamadas[]          = 'insertDispatchActivity';
            $this->anotarTrava();
            $this->temStatusDespachado = true;

            return $this;
        }
    }
}

namespace Fleetbase\FleetOps\Models {
    #[\AllowDynamicProperties]
    class Driver
    {
        use \Teste\ModeloDeTeste;
        public $uuid;
        public $public_id;
        public $company_uuid;
        public $user_uuid;
        public $name;
        public $phone;
        public $location;
        public $online = false;
        public $status = 'available';
        public $distance = null;
        /** Avisos enviados (notify): [[motoboy public_id, notificação]] */
        public static array $avisos = [];

        public function notify($notificacao): void { self::$avisos[] = [$this->public_id, $notificacao]; }
    }
}

namespace Fleetbase\FleetOps\Notifications {
    // o OrderPing do Fleet-Ops só com o que a distribuição usa (o real estende o Notification do Laravel e formata a
    // distância pelo Utils); a OfertaDePedido o estende
    class OrderPing
    {
        public $order;
        public $distance;
        public string $title;
        public string $message;
        public array $data = [];

        public function __construct($order, $distance = null)
        {
            $this->order    = $order;
            $this->distance = $distance;
            $this->title    = 'New incoming order!';
            $this->message  = 'New order is available for pickup.';
            $this->data     = ['id' => $order->public_id, 'type' => 'order_ping'];
        }
    }
}

namespace Fleetbase\FleetOps\Events {
    // o OrderDispatched do Fleet-Ops: o real relê o pedido no banco (getModelRecord); aqui guarda o objeto
    class OrderDispatched
    {
        public function __construct(public $pedido) {}
        public function getModelRecord() { return $this->pedido; }
    }
}

namespace Fleetbase\FleetOps\Listeners {
    // o HandleOrderDispatched do Fleet-Ops (o DistribuirPedidoAberto o estende): o handle só anota a chamada; os
    // motoboys do raio vêm de $proximos e o aviso é o OrderPing, como no real
    class HandleOrderDispatched
    {
        /** Eventos que chegaram ao handle original. */
        public static array $originais = [];
        /** Motoboys "no raio" do nearbyAvailableDrivers. */
        public static array $proximos = [];
        /** O doesntHaveDispatchActivity e o getDispatchActivity. */
        public static bool $semAtividade = false;
        public static $atividade = null;

        public function handle(\Fleetbase\FleetOps\Events\OrderDispatched $event) { self::$originais[] = $event; }
        protected function doesntHaveDispatchActivity($order): bool { return self::$semAtividade; }
        protected function getDispatchActivity($order): mixed { return self::$atividade; }
        protected function nearbyAvailableDrivers($pickup, int|float $distance) { return new \Illuminate\Support\Collection(self::$proximos); }
        protected function notifyAdhocDriver(\Fleetbase\FleetOps\Models\Driver $driver, $order): void { $driver->notify(new \Fleetbase\FleetOps\Notifications\OrderPing($order, $driver->distance)); }
    }
}

namespace Teste {
    // o container do Laravel (app()): instância registrada pelo teste ou criada com as dependências do construtor
    class Container
    {
        public static array $instancias = [];
    }
}

namespace {
    function app($classe = null)
    {
        if ($classe === null) {
            return null;
        }
        if (isset(\Teste\Container::$instancias[$classe])) {
            return \Teste\Container::$instancias[$classe];
        }
        $reflexao   = new \ReflectionClass($classe);
        $argumentos = [];
        foreach ($reflexao->getConstructor()?->getParameters() ?? [] as $parametro) {
            $tipo = $parametro->getType();
            if ($tipo instanceof \ReflectionNamedType && !$tipo->isBuiltin()) {
                $argumentos[] = app($tipo->getName());
            } else {
                $argumentos[] = $parametro->isDefaultValueAvailable() ? $parametro->getDefaultValue() : null;
            }
        }

        return $reflexao->newInstanceArgs($argumentos);
    }
}

namespace {
    // o DB::transaction falso desfaz estas listas junto com as tabelas (rollback)
    \Teste\Banco::$modelos = [
        \Fleetbase\FleetOps\Models\Order::class   => ['todos', 'criados'],
        \Fleetbase\FleetOps\Models\Place::class   => ['todos', 'criados'],
        \Fleetbase\FleetOps\Models\Payload::class => ['salvos'],
    ];

    // a tabela orders do join do entregas:ifood-acompanhar: os Orders falsos
    \Teste\Banco::$externas['orders'] = fn () => array_map(fn ($pedido) => get_object_vars($pedido), \Fleetbase\FleetOps\Models\Order::$todos);

    /** Zera os models e cria a loja de teste: Vendor com o Local de coleta e o tipo de pedido transport da empresa. */
    function reiniciarFleetbase(): void
    {
        \Fleetbase\FleetOps\Models\Vendor::$todos      = [];
        \Fleetbase\FleetOps\Models\Place::$todos       = [];
        \Fleetbase\FleetOps\Models\Place::$criados     = [];
        \Fleetbase\FleetOps\Models\Payload::$salvos    = [];
        \Fleetbase\FleetOps\Models\OrderConfig::$todos = [];
        \Fleetbase\FleetOps\Models\Order::$todos       = [];
        \Fleetbase\FleetOps\Models\Order::$criados     = [];
        \Fleetbase\FleetOps\Models\Order::$carregados  = [];
        \Fleetbase\FleetOps\Models\Order::$falharDespacho = false;
        \Fleetbase\FleetOps\Models\Order::$falharCancelamento = false;
        \Fleetbase\FleetOps\Models\Driver::$todos      = [];
        \Fleetbase\FleetOps\Models\Driver::$avisos     = [];
        \Fleetbase\FleetOps\Listeners\HandleOrderDispatched::$originais    = [];
        \Fleetbase\FleetOps\Listeners\HandleOrderDispatched::$proximos     = [];
        \Fleetbase\FleetOps\Listeners\HandleOrderDispatched::$semAtividade = false;
        \Fleetbase\FleetOps\Listeners\HandleOrderDispatched::$atividade    = null;
        \Teste\Container::$instancias                  = [];
        \Fleetbase\Support\Auth::$usuario              = null;

        \Fleetbase\FleetOps\Models\Place::$todos[]       = new \Fleetbase\FleetOps\Models\Place(['uuid' => 'place-loja', 'company_uuid' => 'empresa-1', 'name' => 'PIZZARIA FICTICIA', 'owner_uuid' => 'vendor-a', 'owner_type' => 'fleet-ops:vendor', 'location' => new \Fleetbase\LaravelMysqlSpatial\Types\Point(-21.1775, -47.8103)]);
        \Fleetbase\FleetOps\Models\Vendor::$todos[]      = new \Fleetbase\FleetOps\Models\Vendor(['uuid' => 'vendor-a', 'public_id' => 'vendor_a', 'company_uuid' => 'empresa-1', 'name' => 'Pizzaria Ficticia', 'place_uuid' => 'place-loja']);
        \Fleetbase\FleetOps\Models\OrderConfig::$todos[] = new \Fleetbase\FleetOps\Models\OrderConfig(['uuid' => 'config-transport', 'key' => 'transport', 'company_uuid' => 'empresa-1', 'namespace' => 'system:order-config:transport']);
    }

    /** O vínculo da loja de teste (vendor-a ↔ merchant-1), gravado na tabela; devolve a linha. */
    function vinculoDaLojaA(string $expiraEm = '2026-10-05 20:00:00'): object
    {
        \Teste\Banco::inserir('entregas_ifood_lojas', [
            'company_uuid' => 'empresa-1', 'vendor_uuid' => 'vendor-a', 'merchant_id' => 'merchant-1', 'nome_ifood' => 'Pizzaria Ficticia',
            'access_token' => encrypt('token-a'), 'refresh_token' => encrypt('refresh-a'), 'expira_em' => $expiraEm, 'situacao' => 'vinculada',
            'vinculado_em' => '2026-10-05 09:00:00', 'renovado_em' => null, 'created_at' => '2026-10-05 09:00:00', 'updated_at' => '2026-10-05 09:00:00',
        ], false);

        return (new \Teste\Consulta('entregas_ifood_lojas'))->where('vendor_uuid', 'vendor-a')->first();
    }
}
