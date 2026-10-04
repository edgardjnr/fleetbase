<?php

// Stubs dos testes dos ganhos do motoboy (ganhos-motoboy.php). Independentes do stubs.php: o Order, o Driver e o
// Carbon daqui têm o que o CalculoEntregas, o MotoboyController e a migration usam. Sem vendor e sem banco: a tabela
// entregas_valores_pedido é um arranjo em memória (Teste\Banco) e as consultas filtram listas (Teste\Consulta).

namespace Illuminate\Support {
    class Collection implements \IteratorAggregate, \Countable, \ArrayAccess
    {
        public function __construct(protected array $itens = []) {}
        public function all(): array { return $this->itens; }
        public function count(): int { return count($this->itens); }
        public function isEmpty(): bool { return !$this->itens; }
        public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->itens); }
        public function first() { return $this->itens ? reset($this->itens) : null; }
        public function get($chave, $padrao = null) { return $this->itens[$chave] ?? $padrao; }
        public function values(): static { return new static(array_values($this->itens)); }
        public function filter(?callable $funcao = null): static { return new static($funcao ? array_filter($this->itens, $funcao) : array_filter($this->itens)); }
        public function unique(): static { return new static(array_unique($this->itens)); }
        public function pluck(string $campo): static { return new static(array_map(fn ($item) => is_array($item) ? ($item[$campo] ?? null) : ($item->$campo ?? null), $this->itens)); }

        public function keyBy(string $campo): static
        {
            $indexado = [];
            foreach ($this->itens as $item) {
                $indexado[is_array($item) ? $item[$campo] : $item->$campo] = $item;
            }

            return new static($indexado);
        }

        public function offsetExists($chave): bool { return isset($this->itens[$chave]); }
        public function offsetGet($chave): mixed { return $this->itens[$chave]; }
        public function offsetSet($chave, $valor): void { if ($chave === null) { $this->itens[] = $valor; } else { $this->itens[$chave] = $valor; } }
        public function offsetUnset($chave): void { unset($this->itens[$chave]); }
    }

    // o Carbon do Laravel, só no que o CalculoEntregas e o MotoboyController usam
    class Carbon extends \DateTime
    {
        public static function parse($valor, $fuso = null): static
        {
            return new static($valor, $fuso ? new \DateTimeZone($fuso) : null);
        }

        public static function createFromFormat($formato, $valor, $fuso = null): static|false
        {
            $data = \DateTime::createFromFormat($formato, $valor, is_string($fuso) ? new \DateTimeZone($fuso) : $fuso);

            return $data ? new static($data->format('Y-m-d H:i:s.u'), $data->getTimezone()) : false;
        }

        public function setTimezone($fuso): static
        {
            parent::setTimezone(is_string($fuso) ? new \DateTimeZone($fuso) : $fuso);

            return $this;
        }

        public function startOfDay(): static { $this->setTime(0, 0, 0, 0); return $this; }
        public function endOfDay(): static { $this->setTime(23, 59, 59, 999999); return $this; }
        public function utc(): static { return $this->setTimezone('UTC'); }
        public function toIso8601String(): string { return $this->format('Y-m-d\TH:i:sP'); }
    }
}

namespace Illuminate\Support\Facades {
    class DB
    {
        public static function table(string $tabela) { return new \Teste\Tabela($tabela); }
        public static function raw($sql) { return $sql; }
    }

    class Log
    {
        public static array $registros = [];
        public static function info($mensagem, array $contexto = []) { self::$registros[] = ['info', $mensagem, $contexto]; }
        public static function warning($mensagem, array $contexto = []) { self::$registros[] = ['warning', $mensagem, $contexto]; }
        public static function error($mensagem, array $contexto = []) { self::$registros[] = ['error', $mensagem, $contexto]; }
    }

    // a migration: Schema::create entrega um Blueprint que só registra as colunas
    class Schema
    {
        public static array $criadas = [];

        public static function create(string $tabela, \Closure $definicao): void
        {
            $blueprint = new \Illuminate\Database\Schema\Blueprint();
            $definicao($blueprint);
            self::$criadas[$tabela] = $blueprint;
        }

        public static function dropIfExists(string $tabela): void { unset(self::$criadas[$tabela]); }
    }
}

namespace Illuminate\Database\Migrations {
    abstract class Migration {}
}

namespace Illuminate\Database\Schema {
    class Blueprint
    {
        /** @var \Teste\Coluna[] */
        public array $colunas = [];

        public function __call($tipo, $argumentos)
        {
            return $this->colunas[] = new \Teste\Coluna($tipo, $argumentos);
        }
    }
}

namespace Illuminate\Http {
    class Request
    {
        public function __construct(public array $dados = [], public ?string $token = null) {}
        public function bearerToken() { return $this->token; }
        public function input($chave, $padrao = null) { return $this->dados[$chave] ?? $padrao; }
        // o formato das datas é conferido pelo Laravel (não testado aqui)
        public function validate(array $regras) { return $this->dados; }
    }
}

namespace App\Http\Controllers {
    // o real estende o Controller do Laravel; os nossos controllers só herdam dele
    class Controller {}
}

namespace Fleetbase\Models {
    class Setting
    {
        public static array $valores = [];
        public static function lookupCompany($chave, $padrao = null) { return self::$valores[$chave] ?? $padrao; }
    }

    class Company
    {
        public static string $fuso = 'America/Sao_Paulo';
        public static function where($coluna, $valor) { return new \Teste\ValorFixo(self::$fuso); }
    }
}

namespace Fleetbase\FleetOps\Support {
    class OSRM
    {
        public static int $chamadas = 0;
        /** Metros da rota que o OSRM devolve; null = o serviço falha. */
        public static ?float $metros = 3210.4;

        public static function getRouteFromCoordinatesString($coordenadas, $opcoes = [])
        {
            self::$chamadas++;
            if (self::$metros === null) {
                throw new \RuntimeException('OSRM fora do ar');
            }

            return ['code' => 'Ok', 'routes' => [['distance' => self::$metros]]];
        }
    }

    class Utils
    {
        /** Distância em linha reta, em metros (a estimativa do cálculo multiplica por 1,3). */
        public static float $linhaReta = 2000;
        public static function calculateDrivingDistanceAndTime($origem, $destino) { return (object) ['distance' => self::$linhaReta, 'time' => 0]; }
    }
}

namespace Fleetbase\FleetOps\Models {
    trait PreencheAtributos
    {
        public function __construct(array $atributos = [])
        {
            foreach ($atributos as $chave => $valor) {
                $this->$chave = $valor;
            }
        }
    }

    class Place
    {
        use PreencheAtributos;
        public $uuid;
        public $public_id;
        public $name;
        public $street1;
        public $street2;
        public $neighborhood;
        public $address;
        public $city;
        public $location;
    }

    class Payload
    {
        public function __construct(public ?Place $pickup = null, public ?Place $dropoff = null) {}
        public function getPickupOrFirstWaypoint() { return $this->pickup; }
        public function getDropoffOrLastWaypoint() { return $this->dropoff; }
    }

    class Vendor
    {
        use PreencheAtributos;
        public static array $lojas = [];
        public $uuid;
        public $public_id;
        public $name;
        public static function withTrashed() { return new \Teste\ConsultaLojas(); }
    }

    class Driver
    {
        use PreencheAtributos;
        public static array $todos = [];
        public $uuid;
        public $public_id;
        public $name;
        public $user_uuid;
        public $company_uuid = 'empresa';
        public static function where($coluna, $valor) { return (new \Teste\Consulta(self::$todos))->where($coluna, $valor); }
    }

    class Order
    {
        use PreencheAtributos;
        public static array $todos = [];
        public $uuid;
        public $public_id;
        public $company_uuid          = 'empresa';
        public $status                = 'completed';
        public $adhoc                 = false;
        public $driver_assigned_uuid  = null;
        public $customer_uuid         = null;
        public $internal_id           = null;
        public $payload               = null;
        public $driverAssigned        = null;
        public $entregas_concluido_em = '2026-10-03 22:42:00';
        public $timestamps            = true;
        public array $meta            = [];

        public function getMeta($chave) { return \Teste\pegar($this->meta, $chave); }
        public function updateMeta($chave, $valor) { \Teste\definir($this->meta, $chave, $valor); return true; }
        public static function where($coluna, $valor = null) { return (new \Teste\Consulta(self::$todos))->where($coluna, $valor); }
    }
}

namespace Teste {
    class Ponto
    {
        public function __construct(private float $lat, private float $lng) {}
        public function getLat() { return $this->lat; }
        public function getLng() { return $this->lng; }
    }

    class Coluna
    {
        public array $modificadores = [];
        public function __construct(public string $tipo, public array $argumentos) {}
        public function __call($modificador, $argumentos) { $this->modificadores[$modificador] = $argumentos; return $this; }
    }

    class ValorFixo
    {
        public function __construct(private $valor) {}
        public function value($coluna) { return $this->valor; }
    }

    class Resposta
    {
        public function __construct(public $dados, public int $status = 200) {}
    }

    // tabela entregas_valores_pedido em memória: order_uuid => linha
    class Banco
    {
        public static array $tabelas = [];
        public static int $upserts   = 0;
    }

    class Tabela
    {
        private ?array $filtro = null;
        public function __construct(private string $nome) {}

        public function whereIn($coluna, array $valores) { $this->filtro = [$coluna, $valores]; return $this; }

        public function get()
        {
            [$coluna, $valores] = $this->filtro ?? [null, []];
            $linhas             = array_filter(Banco::$tabelas[$this->nome] ?? [], fn ($linha) => $coluna === null || in_array($linha[$coluna], $valores, true));

            return new \Illuminate\Support\Collection(array_values(array_map(fn ($linha) => (object) static::comoMysql($linha), $linhas)));
        }

        public function upsert(array $linhas, $unicas, array $atualizar)
        {
            Banco::$upserts++;
            foreach ($linhas as $linha) {
                $chave     = $linha['order_uuid'];
                $existente = Banco::$tabelas[$this->nome][$chave] ?? null;
                if ($existente === null) {
                    Banco::$tabelas[$this->nome][$chave] = $linha;
                    continue;
                }
                foreach ($atualizar as $coluna) {
                    if (array_key_exists($coluna, $linha)) {
                        $existente[$coluna] = $linha[$coluna];
                    }
                }
                Banco::$tabelas[$this->nome][$chave] = $existente;
            }

            return count($linhas);
        }

        // como o PDO do MySQL devolve: decimal em texto ("8.00"), booleano em inteiro
        private static function comoMysql(array $linha): array
        {
            foreach (['de_km', 'ate_km', 'valor_motoboy', 'valor_loja'] as $coluna) {
                $linha[$coluna] = number_format((float) $linha[$coluna], 2, '.', '');
            }
            $linha['acima'] = (int) !empty($linha['acima']);

            return $linha;
        }
    }

    // consulta sobre uma lista de objetos: where (igualdade, ou um grupo de "ou" numa closure), orWhere e first
    class Consulta
    {
        private array $filtros      = [];
        private array $alternativas = [];
        public function __construct(private array $itens) {}

        public function where($coluna, $valor = null)
        {
            if ($coluna instanceof \Closure) {
                $grupo = new self([]);
                $coluna($grupo);
                $alternativas    = $grupo->alternativas;
                $this->filtros[] = function ($item) use ($alternativas) {
                    foreach ($alternativas as $alternativa) {
                        if ($alternativa($item)) {
                            return true;
                        }
                    }

                    return false;
                };

                return $this;
            }

            $condicao             = fn ($item) => $item->$coluna === $valor;
            $this->filtros[]      = $condicao;
            $this->alternativas[] = $condicao;

            return $this;
        }

        public function orWhere($coluna, $valor) { $this->alternativas[] = fn ($item) => $item->$coluna === $valor; return $this; }
        public function with($relacoes) { return $this; }

        public function first()
        {
            foreach ($this->itens as $item) {
                if ($this->passa($item)) {
                    return $item;
                }
            }

            return null;
        }

        private function passa($item): bool
        {
            foreach ($this->filtros as $filtro) {
                if (!$filtro($item)) {
                    return false;
                }
            }

            return true;
        }
    }

    class ConsultaLojas
    {
        private array $uuids = [];
        public function without($relacao) { return $this; }
        public function whereIn($coluna, $valores) { $this->uuids = $valores instanceof \Illuminate\Support\Collection ? $valores->all() : (array) $valores; return $this; }
        public function get() { return new \Illuminate\Support\Collection(array_values(array_filter(\Fleetbase\FleetOps\Models\Vendor::$lojas, fn ($loja) => in_array($loja->uuid, $this->uuids, true)))); }
    }

    // a query que o filtro do pedidosConcluidos recebe: só registra os where
    class ConsultaRegistrada
    {
        public array $wheres = [];
        public function where($coluna, $valor = null) { $this->wheres[] = [$coluna, $valor]; return $this; }
    }

    function pegar(array $dados, string $chave)
    {
        foreach (explode('.', $chave) as $parte) {
            if (!is_array($dados) || !array_key_exists($parte, $dados)) {
                return null;
            }
            $dados = $dados[$parte];
        }

        return $dados;
    }

    function definir(array &$dados, string $chave, $valor): void
    {
        $atual = &$dados;
        foreach (explode('.', $chave) as $parte) {
            if (!isset($atual[$parte]) || !is_array($atual[$parte])) {
                $atual[$parte] = [];
            }
            $atual = &$atual[$parte];
        }
        $atual = $valor;
    }
}

namespace {
    function session($chave = null)
    {
        return $chave === null ? $GLOBALS['sessao'] : ($GLOBALS['sessao'][$chave] ?? null);
    }

    function response()
    {
        return new class {
            public function json($dados = [], $status = 200) { return new \Teste\Resposta($dados, $status); }
        };
    }

    function now()
    {
        return new \Illuminate\Support\Carbon('now', new \DateTimeZone('UTC'));
    }

    function collect($itens = [])
    {
        return new \Illuminate\Support\Collection(is_array($itens) ? $itens : iterator_to_array($itens));
    }

    function data_get($alvo, $chave, $padrao = null)
    {
        foreach (explode('.', (string) $chave) as $parte) {
            if (is_array($alvo) && array_key_exists($parte, $alvo)) {
                $alvo = $alvo[$parte];
            } elseif (is_object($alvo) && isset($alvo->$parte)) {
                $alvo = $alvo->$parte;
            } else {
                return $padrao;
            }
        }

        return $alvo;
    }

    $GLOBALS['sessao'] = ['company' => 'empresa', 'user' => 'usuario-motoca'];

    // classes do api/app (App\...) carregadas direto do repositório montado em /repo
    spl_autoload_register(function (string $classe) {
        if (str_starts_with($classe, 'App\\')) {
            $arquivo = '/repo/api/app/' . str_replace('\\', '/', substr($classe, 4)) . '.php';
            if (is_file($arquivo)) {
                require $arquivo;
            }
        }
    });

    // como o HandleExceptions do Laravel: warning e notice viram ErrorException; deprecation só aparece na saída
    set_error_handler(function (int $nivel, string $mensagem, string $arquivo = '', int $linha = 0): bool {
        if (in_array($nivel, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
            return false;
        }

        throw new \ErrorException($mensagem, 0, $nivel, $arquivo, $linha);
    });

    $GLOBALS['falhas'] = 0;

    function confere(bool $ok, string $descricao): void
    {
        if (!$ok) {
            $GLOBALS['falhas']++;
        }
        echo ($ok ? 'PASSA ' : 'FALHA ') . $descricao . PHP_EOL;
    }

    function resumo(): void
    {
        echo PHP_EOL . 'FALHAS: ' . $GLOBALS['falhas'] . PHP_EOL;
    }
}
