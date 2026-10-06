<?php

// Stubs mínimos do Laravel/Fleetbase para os testes de scripts/teste-php: rodam os arquivos reais do Fleet-Ops
// (fleetops-api 0.6.65) e os nossos do api/app sem vendor nem banco. Ver rodar.mjs.

namespace Illuminate\Console {
    class Command
    {
        public array $opcoes = ['sandbox' => false, 'testing' => false, 'days' => 2];
        public array $saida  = [];

        public function option($chave = null) { return $this->opcoes[$chave] ?? null; }
        public function info($m) { $this->saida[] = $m; }
        public function warn($m) { $this->saida[] = $m; }
        public function line($m) { $this->saida[] = $m; }
        public function error($m) { $this->saida[] = $m; }
        public function alert($m) { $this->saida[] = $m; }
        public function table($cabecalho, $linhas) {}
    }
}

namespace Illuminate\Database\Eloquent {
    class Collection implements \IteratorAggregate, \Countable
    {
        public function __construct(public array $itens = []) {}
        public function isEmpty() { return !$this->itens; }
        public function count(): int { return count($this->itens); }
        public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->itens); }
        public function map($f) { return new static(array_map($f, $this->itens)); }
    }
}

namespace Illuminate\Support {
    // como o Carbon mutável do Laravel: sub* altera o próprio objeto e devolve $this
    class Carbon extends \DateTime
    {
        public static function now($tz = null): static { return new static(\Carbon\CarbonImmutable::$agoraTeste ?? 'now', new \DateTimeZone('UTC')); }
        public function subHours($n) { $this->modify("-{$n} hours"); return $this; }
        public function subMinutes($n) { $this->modify("-{$n} minutes"); return $this; }
        public function subDays($n) { $this->modify("-{$n} days"); return $this; }
        public function subSeconds($n) { $this->modify("-{$n} seconds"); return $this; }
        public function addDay() { $this->modify('+1 day'); return $this; }
        public function toDateTimeString() { return $this->format('Y-m-d H:i:s'); }
        public function toIso8601String() { return $this->format('Y-m-d\TH:i:sP'); }
    }
}

namespace Illuminate\Support\Facades {
    class Cache
    {
        public static array $dados = [];
        public static function get($chave, $padrao = null) { return array_key_exists($chave, self::$dados) ? self::$dados[$chave] : $padrao; }
        public static function put($chave, $valor, $ttl = null) { self::$dados[$chave] = $valor; return true; }
    }
}

namespace Carbon {
    class CarbonImmutable extends \DateTimeImmutable
    {
        public static ?string $agoraTeste = null;
        public static function now($tz = null): static { return new static(self::$agoraTeste ?? 'now', new \DateTimeZone('UTC')); }
        public function subMinutes($n) { return $this->modify("-{$n} minutes"); }
        public function subSeconds($n) { return $this->modify("-{$n} seconds"); }
        public function subDays($n) { return $this->modify("-{$n} days"); }
        public function addDay() { return $this->modify('+1 day'); }
    }
}

namespace Illuminate\Notifications { class Notification {} }
namespace Illuminate\Contracts\Queue { interface ShouldQueue {} }
namespace Illuminate\Bus { trait Queueable {} }
namespace Fleetbase\LaravelMysqlSpatial\Types { class Point {} }

namespace Fleetbase\FleetOps\Support {
    class Utils
    {
        public static function castBoolean($v) { return filter_var($v, FILTER_VALIDATE_BOOLEAN); }
        public static function isPoint($p) { return $p instanceof \Fleetbase\LaravelMysqlSpatial\Types\Point; }
        public static function formatMeters($m, $abreviado = true) { return round($m / 1000, 1) . 'km'; }
    }
}

namespace Fleetbase\FleetOps\Models {
    class Order
    {
        public $uuid;
        public $public_id;
        public $adhoc                = 1;
        public $dispatched           = 1;
        public $started              = 0;
        public $driver_assigned_uuid = null;
        public $internal_id          = null;
        public $deleted_at           = null;
        public $status               = 'dispatched';
        public $created_at;
        public $dispatched_at;
        public $payload        = true;
        public $company_uuid   = 'empresa';
        public $adhoc_distance = 6000;

        public static function on($conexao) { return new \Teste\ConsultaPedidos(); }
        public function getPickupLocation() { return new \Fleetbase\LaravelMysqlSpatial\Types\Point(); }
        public function getAdhocPingDistance(): int { return (int) $this->adhoc_distance; }
        public function setRelations(array $relacoes) { return $this; }
    }

    class Driver
    {
        public $name;
        public $public_id;
        public $online       = 1;
        public $status       = 'available';
        public $distance;
        public $company_uuid = 'empresa';

        public static function query() { return new \Teste\ConsultaMotoboys(); }
        public function notify($aviso) { \Teste\Registro::$avisos[] = ['quando' => \Carbon\CarbonImmutable::now(), 'motoboy' => $this->name, 'aviso' => $aviso]; }
    }
}

namespace Teste {
    use Illuminate\Database\Eloquent\Collection;

    class Registro
    {
        public static array $avisos = [];
    }

    function comparar($x, $op, $y)
    {
        $x = $x instanceof \DateTimeInterface ? $x->getTimestamp() : $x;
        $y = $y instanceof \DateTimeInterface ? $y->getTimestamp() : $y;

        return match ($op) { '=' => $x == $y, '!=', '<>' => $x != $y, '>=' => $x >= $y, '<=' => $x <= $y, '>' => $x > $y, '<' => $x < $y };
    }

    // Pedidos em memória com os filtros que o comando monta. Os limites do whereBetween são lidos no get(), como o
    // Laravel faz ao formatar os bindings na execução.
    class ConsultaPedidos
    {
        public static array $pedidos        = [];
        public static ?array $limitesUsados = null;
        private array $filtros              = [];
        private array $entre                = [];

        public function withoutGlobalScopes() { return $this; }

        public function where($coluna, $op = null, $valor = null)
        {
            if (is_array($coluna)) {
                foreach ($coluna as $c => $v) {
                    $this->filtros[] = fn ($p) => $p->$c == $v;
                }

                return $this;
            }
            if (func_num_args() === 2) {
                $valor = $op;
                $op    = '=';
            }
            $this->filtros[] = fn ($p) => comparar($p->$coluna, $op, $valor);

            return $this;
        }

        public function whereNull($c) { $this->filtros[] = fn ($p) => $p->$c === null; return $this; }
        public function whereNotIn($c, array $valores) { $this->filtros[] = fn ($p) => !in_array($p->$c, $valores, true); return $this; }
        public function whereBetween($c, array $limites) { $this->entre = [$c, $limites[0], $limites[1]]; return $this; }
        public function whereHas($relacao, $cb = null) { if ($relacao === 'payload') { $this->filtros[] = fn ($p) => (bool) $p->payload; } return $this; }
        public function with($relacoes) { return $this; }

        public function get()
        {
            [$c, $de, $ate]      = $this->entre;
            self::$limitesUsados = [$de->format('Y-m-d H:i:s'), $ate->format('Y-m-d H:i:s')];
            $filtros             = array_merge($this->filtros, [fn ($p) => comparar($p->$c, '>=', $de) && comparar($p->$c, '<=', $ate)]);

            return new Collection(array_values(array_filter(self::$pedidos, function ($p) use ($filtros) {
                foreach ($filtros as $f) {
                    if (!$f($p)) {
                        return false;
                    }
                }

                return true;
            })));
        }
    }

    // Motoboys em memória: online/status pelos where, raio pelo distanceSphere (todos da mesma empresa)
    class ConsultaMotoboys
    {
        public static array $motoboys = [];
        private array $filtros        = [];
        private $raio                 = null;

        public function where($coluna, $op = null, $valor = null)
        {
            if ($coluna instanceof \Closure) {
                return $this;
            }
            if (is_array($coluna)) {
                foreach ($coluna as $c => $v) {
                    $this->filtros[] = fn ($m) => $m->$c == $v;
                }

                return $this;
            }
            if (func_num_args() === 2) {
                $valor = $op;
                $op    = '=';
            }
            $this->filtros[] = fn ($m) => comparar($m->$coluna, $op, $valor);

            return $this;
        }

        public function whereNull($c) { return $this; }
        public function whereNotNull($c) { return $this; }
        public function whereRaw($sql) { return $this; }
        public function withoutGlobalScopes() { return $this; }
        public function distanceSphere($coluna, $ponto, $distancia) { $this->raio = $distancia; return $this; }
        public function distanceSphereValue($coluna, $ponto) { return $this; }

        public function get()
        {
            $raio    = $this->raio;
            $filtros = $this->filtros;

            return new Collection(array_values(array_filter(self::$motoboys, function ($m) use ($raio, $filtros) {
                if ($raio !== null && $m->distance > $raio) {
                    return false;
                }
                foreach ($filtros as $f) {
                    if (!$f($m)) {
                        return false;
                    }
                }

                return true;
            })));
        }
    }
}

namespace {
    // como o now() do Laravel: devolve o Carbon mutável (Illuminate\Support\Carbon)
    function now()
    {
        return \Illuminate\Support\Carbon::now();
    }

    // classes do api/app (App\...) carregadas direto do repositório montado em /repo
    spl_autoload_register(function (string $classe) {
        if (str_starts_with($classe, 'App\\')) {
            $arquivo = '/repo/api/app/' . str_replace('\\', '/', substr($classe, 4)) . '.php';
            if (is_file($arquivo)) {
                require $arquivo;
            }
        }
    });

    // como o HandleExceptions do Laravel: warning e notice viram ErrorException (o comando aborta em produção);
    // deprecation segue o tratamento padrão do PHP (só aparece na saída)
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

    // a cópia do Fleet-Ops em packages/ (carregada pelos testes) tem de ser a versão que a produção instala (api/composer.lock)
    function confereVersaoDoFleetOps(): void
    {
        $local    = json_decode(file_get_contents('/repo/packages/fleetops/composer.json'), true)['version'] ?? null;
        $producao = null;
        foreach (json_decode(file_get_contents('/repo/api/composer.lock'), true)['packages'] ?? [] as $pacote) {
            if (($pacote['name'] ?? null) === 'fleetbase/fleetops-api') {
                $producao = ltrim((string) $pacote['version'], 'v');
            }
        }
        confere($local !== null && $local === $producao, "Fleet-Ops local ({$local}) é a versão da produção ({$producao})");
    }
}
