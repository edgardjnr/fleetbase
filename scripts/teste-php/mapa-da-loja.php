<?php

// Mapa de motoboys do portal da loja: a consulta compartilhada com o mapa do console
// (SituacaoDoMotoboy::pedidosEmAndamento), a lista do portal (MotoboysNoMapaDaLoja) e as rotas do PortalLojaController
// (loja/motoboys e o motoboy do pedido, que deixou de mandar a posição).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/mapa-da-loja.php

namespace Illuminate\Support {
    class Collection implements \IteratorAggregate, \Countable, \JsonSerializable
    {
        public function __construct(protected array $itens = []) {}
        public function jsonSerialize(): mixed { return $this->itens; }
        public function all(): array { return $this->itens; }
        public function count(): int { return count($this->itens); }
        public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->itens); }
        public function pluck(string $campo): static { return new static(array_map(fn ($item) => $item->$campo ?? null, $this->itens)); }
    }

    class Carbon extends \DateTimeImmutable
    {
        public function subHours(int $horas): static { return $this->modify("-{$horas} hours"); }
        public function gte($outra): bool { return $this >= $outra; }
    }
}

namespace Teste {
    use Illuminate\Support\Collection;

    class NaoEncontrado extends \RuntimeException {}

    class Abortado extends \RuntimeException
    {
        public function __construct(public int $status, string $mensagem = '') { parent::__construct($mensagem); }
    }

    class Ponto
    {
        public function __construct(private float $lat, private float $lng) {}
        public function getLat(): float { return $this->lat; }
        public function getLng(): float { return $this->lng; }
    }

    /** where(fn ($q) => $q->where(...)->orWhere(...)): a linha passa se bater com qualquer condição do grupo. */
    class Grupo
    {
        private array $condicoes = [];
        public function where($coluna, $valor): static { $this->condicoes[] = [$coluna, $valor]; return $this; }
        public function orWhere($coluna, $valor): static { $this->condicoes[] = [$coluna, $valor]; return $this; }
        public function aceita($linha): bool
        {
            foreach ($this->condicoes as [$coluna, $valor]) {
                if (($linha->$coluna ?? null) === $valor) {
                    return true;
                }
            }

            return false;
        }
    }

    /** Consulta em memória: aplica os filtros que o código usa e registra as chamadas. */
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
                $grupo = new Grupo();
                $coluna($grupo);
                $this->linhas = array_filter($this->linhas, fn ($linha) => $grupo->aceita($linha));

                return $this->anotar('where (grupo)');
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

        public function with($relacoes): static { return $this->anotar('with ' . implode(',', (array) $relacoes)); }

        public function get(array $colunas = ['*']): Collection { return new Collection(array_values($this->linhas)); }

        public function firstOrFail()
        {
            $linha = reset($this->linhas);
            if ($linha === false) {
                throw new NaoEncontrado('não encontrado');
            }

            return $linha;
        }
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

    class Fabrica
    {
        public function json($dados) { return new Resposta(json_decode(json_encode($dados), true)); }
    }
}

namespace Fleetbase\FleetOps\Models {
    #[\AllowDynamicProperties]
    class Vendor extends \Teste\Modelo { public static array $todos = []; }
    class Driver extends \Teste\Modelo { public static array $todos = []; }
    class Order extends \Teste\Modelo { public static array $todos = []; }
}

namespace App\Http\Controllers {
    class Controller {}
}

namespace App\Support\Entregas {
    // a loja do usuário da sessão: "usuario-a" é da Loja A (Vendor vendor-a, contato contato-a); o resto não tem loja
    class LojaDoUsuario
    {
        public static function vendor(?string $userUuid): ?\Fleetbase\FleetOps\Models\Vendor
        {
            if ($userUuid !== 'usuario-a') {
                return null;
            }
            $vendor       = new \Fleetbase\FleetOps\Models\Vendor();
            $vendor->uuid = 'vendor-a';

            return $vendor;
        }

        public static function contato(?string $userUuid): ?object
        {
            return $userUuid === 'usuario-a' ? (object) ['uuid' => 'contato-a'] : null;
        }
    }
}

namespace {
    use App\Http\Controllers\Entregas\PortalLojaController;
    use App\Support\Entregas\MotoboysNoMapaDaLoja;
    use App\Support\Entregas\SituacaoDoMotoboy as S;
    use Fleetbase\FleetOps\Models\Driver;
    use Fleetbase\FleetOps\Models\Order;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Collection;
    use Teste\Abortado;
    use Teste\NaoEncontrado;
    use Teste\Ponto;

    require '/repo/api/app/Support/Entregas/StatusDoPedido.php';
    require '/repo/api/app/Support/Entregas/SituacaoDoMotoboy.php';

    const EMPRESA = 'empresa-a';
    $agora   = new Carbon('2026-10-04 12:00:00');
    $recente = new Carbon('2026-10-04 11:30:00');
    $antigo  = new Carbon('2026-10-03 20:00:00');
    $sessao  = ['company' => EMPRESA, 'user' => 'usuario-a'];

    function session($chave) { global $sessao; return $sessao[$chave] ?? null; }
    function now() { global $agora; return $agora; }
    function response() { return new \Teste\Fabrica(); }
    function config($chave) { return $chave === 'app.key' ? 'base64:chave-de-teste' : null; }
    function abort_if($condicao, int $status, string $mensagem = ''): void
    {
        if ($condicao) {
            throw new Abortado($status, $mensagem);
        }
    }

    function pedido(string $motoboy, ?string $status, bool $aceito, string $id, string $dono, $atualizado, string $empresa = EMPRESA): object
    {
        return (object) [
            'company_uuid' => $empresa, 'driver_assigned_uuid' => $motoboy, 'status' => $status, 'started' => $aceito,
            'public_id' => $id, 'customer_uuid' => $dono, 'updated_at' => $atualizado,
        ];
    }

    function motoboy(string $uuid, bool $online, ?Ponto $local, string $nome, string $empresa = EMPRESA): object
    {
        static $n = 0;
        $n++;

        return (object) [
            'uuid' => $uuid, 'public_id' => 'driver_' . substr($uuid, 2), 'company_uuid' => $empresa, 'online' => $online ? 1 : 0,
            'location' => $local, 'name' => $nome, 'phone' => sprintf('+551699990%04d', $n), 'email' => "{$uuid}@teste.com",
        ];
    }

    $falhas = 0;
    function confere(bool $ok, string $caso): void
    {
        global $falhas;
        if (!$ok) {
            $falhas++;
        }
        echo ($ok ? 'PASSA ' : 'FALHA ') . $caso . PHP_EOL;
    }

    echo '== SituacaoDoMotoboy::pedidosEmAndamento (consulta do mapa do console e do portal)' . PHP_EOL;
    Order::$todos = [
        pedido('m-a', 'started', true, 'order_1', 'vendor-a', $recente),
        pedido('m-a', 'enroute', true, 'order_2', 'vendor-b', $recente),
        pedido('m-b', 'dispatched', false, 'order_3', 'vendor-a', $recente),
        pedido('m-b', 'completed', true, 'order_4', 'vendor-a', $recente),
        pedido('m-c', 'enroute', true, 'order_5', 'vendor-a', $antigo),
        pedido('m-fora', 'started', true, 'order_6', 'vendor-a', $recente),
        pedido('m-a', 'started', true, 'order_7', 'vendor-a', $recente, 'empresa-b'),
    ];
    $porMotoboy = S::pedidosEmAndamento(EMPRESA, new Collection(['m-a', 'm-b', 'm-c']));
    $ids        = fn (array $pedidos) => array_map(fn ($pedido) => $pedido->public_id, $pedidos);
    confere(array_keys($porMotoboy) === ['m-a', 'm-b'], 'agrupa pelo uuid do motoboy; sem pedido em andamento, o motoboy fica fora');
    confere($ids($porMotoboy['m-a'] ?? []) === ['order_1', 'order_2'], 'pedidos em andamento de qualquer loja, só da empresa');
    confere($ids($porMotoboy['m-b'] ?? []) === ['order_3'], 'pedido encerrado fica fora');
    confere(!isset($porMotoboy['m-c']), 'pedido parado há mais de 12 h fica fora');
    confere(($porMotoboy['m-b'][0]->started ?? null) === false && ($porMotoboy['m-a'][1]->customer_uuid ?? null) === 'vendor-b',
        'cada pedido traz started e customer_uuid (visibilidade e pedidos da loja)');
    confere(in_array('where company_uuid =', \Teste\Consulta::$registro[Order::class] ?? [], true), 'pedidos filtrados pela empresa');

    echo PHP_EOL . "FALHAS: {$falhas}" . PHP_EOL;
}
