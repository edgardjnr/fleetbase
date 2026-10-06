<?php

// Aba Mapa do líder dos motoboys no app: quem é líder (LiderDosMotoboys), as rotas do LiderController (acesso, mapa e troca
// do motoboy), a troca (TrocaDoMotoboy) com o aviso ao motoboy anterior (PedidoPassadoParaOutro) e a ligação das rotas no
// RouteServiceProvider. O PedidosNoMapa::doLider e o MapaDoLider têm teste próprio (pedidos-no-mapa.php e mapa-da-loja.php).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/lider.php

namespace Illuminate\Http {
    class Request
    {
        public function __construct(private ?string $token = null, private array $entrada = []) {}
        public function bearerToken(): ?string { return $this->token; }
        public function input(string $chave, $padrao = null) { return $this->entrada[$chave] ?? $padrao; }
    }
}

namespace Illuminate\Contracts\Cache {
    class LockTimeoutException extends \Exception {}
}

namespace Illuminate\Support\Facades {
    class Cache
    {
        public static bool $ocupada = false;
        public static array $travas = [];

        public static function lock(string $chave, int $validade)
        {
            return new class($chave) {
                public function __construct(private string $chave) {}

                public function block(int $espera, \Closure $fazer)
                {
                    if (Cache::$ocupada) {
                        throw new \Illuminate\Contracts\Cache\LockTimeoutException('ocupada');
                    }
                    Cache::$travas[] = $this->chave;

                    return $fazer();
                }
            };
        }
    }

    class Log
    {
        public static array $linhas = [];
        public static function info(string $mensagem, array $contexto = []): void { static::$linhas[] = ['info', $mensagem, $contexto]; }
        public static function warning(string $mensagem, array $contexto = []): void { static::$linhas[] = ['warning', $mensagem, $contexto]; }
    }
}

namespace Illuminate\Notifications { class Notification {} }
namespace Illuminate\Contracts\Queue { interface ShouldQueue {} }
namespace Illuminate\Bus { trait Queueable {} }

namespace Teste {
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

    /** Consulta em memória: devolve os próprios objetos (como o mesmo registro relido do banco). */
    class Consulta
    {
        public static array $registro = [];

        public function __construct(private string $modelo, private array $linhas) {}

        public function where($coluna, $valor = null): static
        {
            if ($coluna instanceof \Closure) {
                $grupo = new Grupo();
                $coluna($grupo);
                $this->linhas = array_filter($this->linhas, fn ($linha) => $grupo->aceita($linha));
                static::$registro[$this->modelo][] = 'where (grupo)';

                return $this;
            }
            static::$registro[$this->modelo][] = "where {$coluna}";
            $this->linhas = array_filter($this->linhas, fn ($linha) => ($linha->$coluna ?? null) === $valor);

            return $this;
        }

        public function with($relacoes): static
        {
            static::$registro[$this->modelo][] = 'with ' . implode(',', (array) $relacoes);

            return $this;
        }

        public function first()
        {
            $linha = reset($this->linhas);

            return $linha === false ? null : $linha;
        }
    }

    abstract class Modelo
    {
        public static function where($coluna, $valor = null)
        {
            return (new Consulta(static::class, static::$todos))->where($coluna, $valor);
        }
    }

    class Resposta
    {
        public function __construct(public $dados, public int $status) {}
    }

    class Fabrica
    {
        public function json($dados, int $status = 200) { return new Resposta(json_decode(json_encode($dados), true), $status); }
    }
}

namespace Fleetbase\Models {
    class User extends \Teste\Modelo
    {
        public static array $todos = [];
        public $uuid;
        public $type = 'user';
        public function isAdmin(): bool { return $this->type === 'admin'; }
    }

    class CompanyUser extends \Teste\Modelo
    {
        public static array $todos = [];
        public $user_uuid;
        public $company_uuid;
        public $status = 'active';
        public array $permissoes = [];
        // como o CompanyUser do core: diretas, dos papéis, das políticas e das políticas dos papéis
        public function getAllPermissions() { return array_map(fn ($nome) => (object) ['name' => $nome], $this->permissoes); }
    }
}

namespace Fleetbase\FleetOps\Models {
    class Driver extends \Teste\Modelo
    {
        public static array $todos = [];
        public $uuid;
        public $public_id;
        public $user_uuid;
        public $company_uuid;
        public $name;
        public array $avisos = [];
        public function notify($aviso): void
        {
            if (\Teste\Falhas::$aviso) {
                throw new \RuntimeException('fila fora');
            }
            $this->avisos[] = $aviso;
        }
    }

    class Order extends \Teste\Modelo
    {
        public static array $todos = [];
        public $uuid;
        public $public_id;
        public $company_uuid;
        public $status = 'started';
        public $adhoc = true;
        public $driver_assigned_uuid = null;
        public $internal_id = null;
        public $trackingNumber = null;
        public array $atribuicoes = [];

        // como o assignDriver do Fleet-Ops: grava o motoboy e salva (o save dispara o OrderObserver)
        public function assignDriver($motoboy, $silencioso = false)
        {
            $this->atribuicoes[] = ['motoboy' => $motoboy->uuid, 'silencioso' => $silencioso, 'adhoc_no_save' => $this->adhoc];
            $this->driver_assigned_uuid = $motoboy->uuid;

            return $this;
        }
    }
}

namespace Teste {
    class Falhas
    {
        public static bool $aviso = false;
    }
}

namespace App\Http\Controllers {
    class Controller {}
}

namespace App\Support\Entregas {
    // dublês: os dois têm teste próprio (pedidos-no-mapa.php e mapa-da-loja.php)
    class PedidosNoMapa
    {
        public const RELACOES_DO_LIDER = ['payload.pickup', 'payload.dropoff', 'driverAssigned.user', 'trackingNumber'];
        public static function umDoLider($pedido): ?array { return ['id' => $pedido->public_id, 'motoboy_uuid' => $pedido->driver_assigned_uuid]; }
    }

    class MapaDoLider
    {
        public static array $chamadas = [];
        public static function mapa(string $empresa): array { static::$chamadas[] = $empresa; return ['pedidos' => [['id' => 'order_1']], 'motoboys' => [['id' => 'driver_b']]]; }
    }
}

namespace {
    use App\Http\Controllers\Entregas\LiderController;
    use App\Notifications\Entregas\PedidoPassadoParaOutro;
    use App\Support\Entregas\LiderDosMotoboys;
    use Fleetbase\FleetOps\Models\Driver;
    use Fleetbase\FleetOps\Models\Order;
    use Fleetbase\Models\CompanyUser;
    use Fleetbase\Models\User;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\Log;

    require '/repo/api/app/Support/Entregas/MotoboyDaSessao.php';
    require '/repo/api/app/Support/Entregas/LiderDosMotoboys.php';

    const EMPRESA = 'empresa-a';
    $sessao = ['company' => EMPRESA, 'user' => 'u-lider'];

    function session($chave) { global $sessao; return $sessao[$chave] ?? null; }
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

    function usuario(string $uuid, string $tipo = 'user'): User
    {
        $u       = new User();
        $u->uuid = $uuid;
        $u->type = $tipo;

        return $u;
    }

    function vinculo(string $usuario, array $permissoes, string $status = 'active', string $empresa = EMPRESA): CompanyUser
    {
        $v               = new CompanyUser();
        $v->user_uuid    = $usuario;
        $v->company_uuid = $empresa;
        $v->status       = $status;
        $v->permissoes   = $permissoes;

        return $v;
    }

    function motoboy(string $uuid, string $usuario, string $nome, string $empresa = EMPRESA): Driver
    {
        $m               = new Driver();
        $m->uuid         = $uuid;
        $m->public_id    = 'driver_' . substr($uuid, 2);
        $m->user_uuid    = $usuario;
        $m->company_uuid = $empresa;
        $m->name         = $nome;

        return $m;
    }

    function pedido(string $uuid, string $status, ?string $motoboy, array $extra = []): Order
    {
        $p                       = new Order();
        $p->uuid                 = $uuid;
        $p->public_id            = 'order_' . substr($uuid, 2);
        $p->company_uuid         = $extra['company_uuid'] ?? EMPRESA;
        $p->status               = $status;
        $p->driver_assigned_uuid = $motoboy;
        $p->adhoc                = $extra['adhoc'] ?? false;
        $p->internal_id          = $extra['internal_id'] ?? null;
        $p->trackingNumber       = isset($extra['rastreio']) ? (object) ['tracking_number' => $extra['rastreio']] : null;

        return $p;
    }

    $token = new Request('12|token-do-motoboy');

    echo '== LiderDosMotoboys::ehLider' . PHP_EOL;
    CompanyUser::$todos = [
        vinculo('u-lider', ['fleet-ops see order', 'fleet-ops assign-driver-for order']),
        vinculo('u-curinga-recurso', ['fleet-ops * order']),
        vinculo('u-curinga-servico', ['fleet-ops *']),
        vinculo('u-comum', ['fleet-ops see order', 'fleet-ops list order', 'fleet-ops assign-vehicle-for order']),
        vinculo('u-desativado', ['fleet-ops assign-driver-for order'], 'inactive'),
        vinculo('u-admin', []),
        vinculo('u-admin-desativado', [], 'inactive'),
        vinculo('u-outra-empresa', ['fleet-ops assign-driver-for order'], 'active', 'empresa-b'),
    ];
    confere(LiderDosMotoboys::ehLider(usuario('u-lider'), EMPRESA), 'com a permissão "fleet-ops assign-driver-for order" (papel, política ou direta): líder');
    confere(LiderDosMotoboys::ehLider(usuario('u-curinga-recurso'), EMPRESA) && LiderDosMotoboys::ehLider(usuario('u-curinga-servico'), EMPRESA), 'curingas "fleet-ops * order" e "fleet-ops *": líder');
    confere(!LiderDosMotoboys::ehLider(usuario('u-comum'), EMPRESA), 'motoboy comum (permissões do papel Driver): não é líder');
    confere(LiderDosMotoboys::ehLider(usuario('u-admin', 'admin'), EMPRESA), 'administrador: líder');
    confere(!LiderDosMotoboys::ehLider(usuario('u-desativado'), EMPRESA) && !LiderDosMotoboys::ehLider(usuario('u-admin-desativado', 'admin'), EMPRESA), 'vínculo desativado no IAM: não é líder (nem o admin)');
    confere(!LiderDosMotoboys::ehLider(usuario('u-outra-empresa'), EMPRESA), 'permissão só em outra empresa: não é líder na da sessão');
    confere(!LiderDosMotoboys::ehLider(usuario('u-sem-vinculo'), EMPRESA) && !LiderDosMotoboys::ehLider(null, EMPRESA) && !LiderDosMotoboys::ehLider(usuario('u-lider'), ''), 'sem vínculo, sem usuário ou sem empresa: não é líder');

    echo PHP_EOL . "FALHAS: {$falhas}" . PHP_EOL;
}
