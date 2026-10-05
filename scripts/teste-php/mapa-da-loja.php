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

        // só as colunas pedidas: se o código deixar de pedir uma coluna que usa, o teste quebra em vez de passar calado
        public function get(array $colunas = ['*']): Collection
        {
            $linhas = array_values($this->linhas);
            if ($colunas !== ['*']) {
                $linhas = array_map(fn ($linha) => (object) array_intersect_key((array) $linha, array_flip($colunas)), $linhas);
            }

            return new Collection($linhas);
        }

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
    $deGerador = S::pedidosEmAndamento(EMPRESA, (function () { yield 'm-b'; })());
    $deArray   = S::pedidosEmAndamento(EMPRESA, ['m-b']);
    confere($ids($deGerador['m-b'] ?? []) === ['order_3'] && $ids($deArray['m-b'] ?? []) === ['order_3'], 'aceita array e qualquer iterável (generator)');

    echo '== MotoboysNoMapaDaLoja::listar (mapa do portal da loja)' . PHP_EOL;
    require '/repo/api/app/Support/Entregas/Coordenada.php';
    require '/repo/api/app/Support/Entregas/MotoboysNoMapaDaLoja.php';

    $naRua = new Ponto(-21.1702, -47.8101);
    Driver::$todos = [
        motoboy('m-livre', true, $naRua, 'Livre'),
        motoboy('m-coleta', true, new Ponto(-21.18, -47.82), 'Coleta'),
        motoboy('m-entrega', true, new Ponto(-21.19, -47.83), 'Entrega'),
        motoboy('m-off', false, $naRua, 'Offline'),
        motoboy('m-off-atribuido', false, $naRua, 'Offline atribuído'),
        motoboy('m-off-aceito', false, new Ponto(-21.2, -47.84), 'Offline com pedido aceito'),
        motoboy('m-sem-gps', true, new Ponto(0.0, 0.0), 'Sem GPS'),
        motoboy('m-sem-local', true, null, 'Sem local'),
        motoboy('m-velho', true, $naRua, 'Pedido velho'),
        motoboy('m-contato', true, $naRua, 'Pedido do contato'),
        motoboy('m-outra-empresa', true, $naRua, 'Outra empresa', 'empresa-b'),
    ];
    Order::$todos = [
        pedido('m-coleta', 'started', true, 'order_a1', 'vendor-a', $recente),
        pedido('m-entrega', 'enroute', true, 'order_b1', 'vendor-b', $recente),
        pedido('m-off-atribuido', 'dispatched', false, 'order_a2', 'vendor-a', $recente),
        pedido('m-off-aceito', 'started', true, 'order_b2', 'vendor-b', $recente),
        pedido('m-velho', 'enroute', true, 'order_a3', 'vendor-a', $antigo),
        pedido('m-contato', 'started', true, 'order_c1', 'contato-a', $recente),
        pedido('m-livre', 'completed', true, 'order_a4', 'vendor-a', $recente),
    ];
    $lista   = MotoboysNoMapaDaLoja::listar(EMPRESA, ['vendor-a', 'contato-a']);
    $porNome = array_column($lista, null, 'nome');
    confere(array_keys($porNome) === ['Livre', 'Coleta', 'Entrega', 'Offline com pedido aceito', 'Pedido velho', 'Pedido do contato'],
        'aparecem os online e o offline com pedido aceito; sem coordenada e outra empresa ficam fora');
    confere(!isset($porNome['Offline']) && !isset($porNome['Offline atribuído']),
        'offline sem pedido aceito fica fora, mesmo com pedido só atribuído (a última posição pode ser a casa)');
    confere(array_column($lista, 'situacao', 'nome') === [
        'Livre' => 'livre', 'Coleta' => 'coleta', 'Entrega' => 'entrega',
        'Offline com pedido aceito' => 'coleta', 'Pedido velho' => 'livre', 'Pedido do contato' => 'coleta',
    ], 'situação pela regra do console; pedido parado há mais de 12 h não conta');
    confere(array_column($lista, 'pedidos', 'nome') === [
        'Livre' => [], 'Coleta' => ['order_a1'], 'Entrega' => [],
        'Offline com pedido aceito' => [], 'Pedido velho' => [], 'Pedido do contato' => ['order_c1'],
    ], 'pedidos: só os da loja da sessão (Vendor e contato do usuário), nunca os de outra loja');
    confere(array_keys($lista[0] ?? []) === ['id', 'nome', 'latitude', 'longitude', 'situacao', 'pedidos'],
        'só id, nome, latitude, longitude, situação e pedidos');
    confere(($porNome['Livre']['latitude'] ?? null) === -21.1702 && ($porNome['Livre']['longitude'] ?? null) === -47.8101, 'coordenadas em número');
    $json  = json_encode($lista);
    $vazou = array_filter(Driver::$todos, fn ($m) => str_contains($json, $m->uuid) || str_contains($json, $m->public_id) || str_contains($json, $m->phone));
    confere($vazou === [] && !str_contains($json, 'driver_') && !str_contains($json, '@teste.com'), 'sem uuid, public_id, telefone nem e-mail do motoboy');
    confere(preg_match('/^[0-9a-f]{16}$/', $porNome['Livre']['id'] ?? '') === 1, 'id opaco de 16 caracteres hexadecimais');
    confere(($porNome['Livre']['id'] ?? null) === substr(hash_hmac('sha256', 'm-livre', 'base64:chave-de-teste'), 0, 16), 'id = HMAC do uuid com a chave do app');
    confere(count(array_unique(array_column($lista, 'id'))) === count($lista), 'um id diferente por motoboy');
    confere((MotoboysNoMapaDaLoja::listar(EMPRESA, ['vendor-a', 'contato-a'])[0]['id'] ?? null) === ($lista[0]['id'] ?? false), 'id estável entre consultas');
    confere(in_array('where company_uuid =', \Teste\Consulta::$registro[Driver::class] ?? [], true), 'motoboys filtrados pela empresa');
    confere(in_array('with user', \Teste\Consulta::$registro[Driver::class] ?? [], true), 'usuário do motoboy (nome) carregado junto');

    echo '== MotoboysNoMapaDaLoja::motoboyDoIdOpaco (chat da loja)' . PHP_EOL;
    $doId = fn (string $id) => MotoboysNoMapaDaLoja::motoboyDoIdOpaco(EMPRESA, ['vendor-a', 'contato-a'], $id)?->uuid;
    confere($doId($porNome['Coleta']['id'] ?? '') === 'm-coleta', 'id opaco do mapa → o motoboy');
    confere($doId($porNome['Offline com pedido aceito']['id'] ?? '') === 'm-off-aceito', 'offline trabalhando também (está no mapa)');
    confere($doId(MotoboysNoMapaDaLoja::idOpaco('m-off')) === null, 'offline sem pedido aceito (fora do mapa): ninguém');
    confere($doId(MotoboysNoMapaDaLoja::idOpaco('m-outra-empresa')) === null, 'motoboy de outra empresa: ninguém');
    confere($doId('m-coleta') === null && $doId('') === null && $doId('0123456789abcdef') === null, 'uuid, vazio ou id inventado: ninguém');

    echo '== PortalLojaController (rota loja/motoboys e motoboy do pedido sem posição)' . PHP_EOL;
    require '/repo/api/app/Http/Controllers/Entregas/PortalLojaController.php';

    $controller = new PortalLojaController();
    $sessao     = ['company' => EMPRESA, 'user' => 'usuario-a'];
    $resposta   = $controller->motoboysNoMapa()->dados;
    confere(array_keys($resposta) === ['motoboys'], 'resposta { motoboys: [...] }');
    confere($resposta['motoboys'] === json_decode(json_encode(MotoboysNoMapaDaLoja::listar(EMPRESA, ['vendor-a', 'contato-a'])), true),
        'a lista do MotoboysNoMapaDaLoja, com a empresa da sessão e os donos da loja (Vendor e contato do usuário)');

    $sessao = ['company' => EMPRESA, 'user' => 'usuario-sem-loja'];
    try {
        $controller->motoboysNoMapa();
        confere(false, 'usuário sem loja recebe 404');
    } catch (Abortado $erro) {
        confere($erro->status === 404, 'usuário sem loja recebe 404');
    }

    $sessao = ['company' => EMPRESA, 'user' => 'usuario-a'];
    $doMapa = (object) ['name' => 'Coleta', 'photo_url' => 'https://foto.teste/coleta.jpg', 'location' => new Ponto(-21.18, -47.82)];
    Order::$todos = [
        (object) ['company_uuid' => EMPRESA, 'customer_uuid' => 'vendor-a', 'public_id' => 'order_a1', 'uuid' => 'uuid-a1', 'status' => 'started', 'started' => true, 'started_at' => $recente, 'driverAssigned' => $doMapa],
        (object) ['company_uuid' => EMPRESA, 'customer_uuid' => 'vendor-a', 'public_id' => 'order_a9', 'uuid' => 'uuid-a9', 'status' => 'completed', 'started' => true, 'started_at' => $recente, 'driverAssigned' => $doMapa],
        (object) ['company_uuid' => EMPRESA, 'customer_uuid' => 'vendor-b', 'public_id' => 'order_b1', 'uuid' => 'uuid-b1', 'status' => 'started', 'started' => true, 'started_at' => $recente, 'driverAssigned' => $doMapa],
    ];
    confere(($controller->motoboy('order_a1')->dados['motoboy'] ?? null) === ['nome' => 'Coleta', 'foto' => 'https://foto.teste/coleta.jpg', 'aceitou' => true],
        'motoboy do pedido: nome, foto e aceite, sem latitude e longitude');
    confere(($controller->motoboy('uuid-a1')->dados['motoboy']['nome'] ?? null) === 'Coleta', 'pedido também pelo uuid');
    $encerrado = $controller->motoboy('order_a9')->dados;
    confere(array_key_exists('motoboy', $encerrado) && $encerrado['motoboy'] === null, 'pedido encerrado: motoboy null');
    try {
        $controller->motoboy('order_b1');
        confere(false, 'pedido de outra loja: 404');
    } catch (NaoEncontrado) {
        confere(true, 'pedido de outra loja: 404');
    }
    confere(!defined(PortalLojaController::class . '::HORAS_POSICAO'), 'sem a regra das 4 h (HORAS_POSICAO)');

    echo PHP_EOL . "FALHAS: {$falhas}" . PHP_EOL;
}
