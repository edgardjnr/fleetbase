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
        public $current_job_uuid = null;
        public array $avisos = [];
        public function update(array $dados): bool
        {
            foreach ($dados as $campo => $valor) {
                $this->$campo = $valor;
            }

            return true;
        }
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
        public $dispatched = true;
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

    require '/repo/api/app/Support/Entregas/StatusDoPedido.php';
    require '/repo/api/app/Support/Entregas/TravaDoPedido.php';
    require '/repo/api/app/Support/Entregas/MotoboyDaSessao.php';
    require '/repo/api/app/Support/Entregas/LiderDosMotoboys.php';
    require '/repo/api/app/Support/Entregas/TrocaDoMotoboy.php';
    require '/repo/api/app/Notifications/Entregas/PedidoPassadoParaOutro.php';
    require '/repo/api/app/Http/Controllers/Entregas/LiderController.php';

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
        $p->dispatched           = $extra['dispatched'] ?? true;
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

    echo '== LiderController@acesso e @mapa' . PHP_EOL;
    User::$todos   = [usuario('u-lider'), usuario('u-comum'), usuario('u-admin', 'admin')];
    Driver::$todos = [
        motoboy('m-lider', 'u-lider', 'Líder'),
        motoboy('m-comum', 'u-comum', 'Comum'),
        motoboy('m-novo', 'u-novo', 'Novo'),
        motoboy('m-outra', 'u-outra', 'Outra empresa', 'empresa-b'),
    ];
    $controller = new LiderController();

    $acesso = $controller->acesso($token);
    confere($acesso->status === 200 && $acesso->dados === ['lider' => true], 'líder: {"lider": true}');
    $sessao = ['company' => EMPRESA, 'user' => 'u-comum'];
    $acesso = $controller->acesso($token);
    confere($acesso->status === 200 && $acesso->dados === ['lider' => false], 'motoboy comum: {"lider": false} com 200 (nunca 403)');
    $sessao = ['company' => EMPRESA, 'user' => 'u-admin'];
    confere($controller->acesso($token)->dados === ['lider' => false], 'admin sem cadastro de motoboy (não é o app): não é líder');
    $sessao = ['company' => EMPRESA, 'user' => 'u-lider'];
    confere($controller->acesso(new Request('flb_live_chave-do-apk'))->dados === ['lider' => false], 'chave de API (flb_live_ do APK, autentica como admin): não é líder');

    $mapa = $controller->mapa($token);
    confere($mapa->status === 200 && $mapa->dados === ['pedidos' => [['id' => 'order_1']], 'motoboys' => [['id' => 'driver_b']]] && \App\Support\Entregas\MapaDoLider::$chamadas === [EMPRESA],
        'mapa do líder: MapaDoLider::mapa com a empresa da sessão');
    $sessao = ['company' => EMPRESA, 'user' => 'u-comum'];
    $negado = $controller->mapa($token);
    confere($negado->status === 403 && $negado->dados === ['errors' => ['Disponível só para o líder dos motoboys.']], 'mapa de quem não é líder: 403');

    echo '== LiderController@trocarMotoboy (TrocaDoMotoboy)' . PHP_EOL;
    $troca = fn (string $pedido, ?string $motoboy) => $controller->trocarMotoboy(new Request('12|token-do-motoboy', $motoboy === null ? [] : ['motoboy' => $motoboy]), $pedido);
    Order::$todos = [
        pedido('o-ifood', 'started', 'm-comum', ['internal_id' => '4821', 'rastreio' => 'RP1']),
        pedido('o-aberto', 'dispatched', null, ['adhoc' => true, 'rastreio' => 'RP2']),
        pedido('o-rastreio', 'started', 'm-comum', ['rastreio' => 'RP3']),
        pedido('o-concluido', 'completed', 'm-comum'),
        pedido('o-cancelado', 'canceled', 'm-comum'),
        pedido('o-outra', 'started', 'm-comum', ['company_uuid' => 'empresa-b']),
        pedido('o-nao-despachado', 'created', 'm-comum', ['dispatched' => false]),
    ];

    confere($troca('order_ifood', 'driver_novo')->status === 403, 'quem não é líder não troca: 403');
    confere(Order::$todos[0]->atribuicoes === [], 'nada muda sem ser líder');

    $sessao = ['company' => EMPRESA, 'user' => 'u-lider'];
    $resposta = $troca('order_inexistente', 'driver_novo');
    confere($resposta->status === 404 && $resposta->dados === ['errors' => ['Pedido não encontrado.']], 'pedido que não existe: 404');
    confere($troca('order_outra', 'driver_novo')->status === 404, 'pedido de outra empresa: 404');
    $resposta = $troca('order_concluido', 'driver_novo');
    confere($resposta->status === 409 && $resposta->dados === ['errors' => ['Este pedido já foi encerrado.']], 'pedido concluído: 409 "Este pedido já foi encerrado."');
    confere($troca('order_cancelado', 'driver_novo')->status === 409, 'pedido cancelado: 409');
    $resposta = $troca('order_ifood', 'driver_outra');
    confere($resposta->status === 422 && $resposta->dados === ['errors' => ['Escolha um motoboy da lista.']], 'motoboy de outra empresa: 422');
    confere($troca('order_ifood', 'driver_inexistente')->status === 422 && $troca('order_ifood', null)->status === 422 && $troca('order_ifood', '  ')->status === 422,
        'motoboy que não existe, sem motoboy ou em branco: 422');
    $resposta = $troca('order_nao-despachado', 'driver_novo');
    confere($resposta->status === 409 && $resposta->dados === ['errors' => ['Este pedido ainda não foi despachado pela central.']], 'pedido criado e nunca despachado (ex.: iFood sem localização, agendado): 409');
    confere(Order::$todos[6]->atribuicoes === [] && Driver::$todos[1]->avisos === [], 'o não despachado não muda nem avisa ninguém');
    $resposta = $controller->trocarMotoboy(new Request('12|token-do-motoboy', ['motoboy' => ['driver_novo']]), 'order_ifood');
    confere($resposta->status === 422 && $resposta->dados === ['errors' => ['Escolha um motoboy da lista.']], 'motoboy que não é texto (lista): 422, e não 500');
    confere($controller->trocarMotoboy(new Request('12|token-do-motoboy', ['motoboy' => 123]), 'order_ifood')->status === 422, 'motoboy numérico: 422');
    confere(Order::$todos[0]->atribuicoes === [], 'nenhuma recusa mexeu no pedido');

    Cache::$travas = [];
    Log::$linhas   = [];
    $resposta      = $troca('order_ifood', 'driver_comum');
    confere($resposta->status === 200 && $resposta->dados === ['pedido' => ['id' => 'order_ifood', 'motoboy_uuid' => 'm-comum']], 'o mesmo motoboy de agora: 200 sem mudança, com o pedido');
    confere(Order::$todos[0]->atribuicoes === [] && Log::$linhas === [] && Driver::$todos[1]->avisos === [], 'mesmo motoboy: sem atribuição, sem aviso e sem log');

    Cache::$travas = [];
    $resposta      = $troca('o-ifood', 'driver_novo');
    $pedido        = Order::$todos[0];
    confere($resposta->status === 200 && $resposta->dados === ['pedido' => ['id' => 'order_ifood', 'motoboy_uuid' => 'm-novo']], 'troca: 200 com o pedido no formato do mapa (pelo uuid também)');
    confere(Cache::$travas === ['entregas:pedido:o-ifood'], 'com a TravaDoPedido (a mesma do aceite e do cancelamento)');
    confere($pedido->atribuicoes === [['motoboy' => 'm-novo', 'silencioso' => true, 'adhoc_no_save' => false]],
        'assignDriver do Fleet-Ops em modo silencioso (o OrderObserver avisa o motoboy novo uma vez), já com o pedido aberto desligado');
    confere($pedido->status === 'started', 'o pedido fica com o status que tinha');
    $aviso = Driver::$todos[1]->avisos[0] ?? null;
    confere($aviso instanceof PedidoPassadoParaOutro && $aviso->title === 'Pedido passado para outro motoboy' && $aviso->message === 'Pedido #4821 passou para outro motoboy.',
        'o anterior recebe "Pedido #4821 passou para outro motoboy." (número do iFood)');
    confere(($aviso->data ?? null) === ['type' => 'entregas_pedido_trocado', 'pedido' => 'order_ifood'], 'dados do push: o tipo e o pedido, sem "id" (o app não abre o pedido que não é mais dele)');
    confere(Driver::$todos[2]->avisos === [], 'o novo não recebe este aviso (recebe o "Novo pedido para você" do Fleet-Ops)');
    confere(Log::$linhas === [['info', '[entregas] líder trocou o motoboy', ['pedido' => 'order_ifood', 'anterior' => 'driver_comum', 'novo' => 'driver_novo', 'lider' => 'u-lider']]],
        'log "[entregas] líder trocou o motoboy" só com ids');

    echo '== current_job_uuid do motoboy anterior' . PHP_EOL;
    Order::$todos[] = pedido('o-corrente', 'started', 'm-comum');
    Order::$todos[] = pedido('o-outro-corrente', 'started', 'm-comum');
    Driver::$todos[1]->current_job_uuid = 'o-corrente';
    $troca('order_outro-corrente', 'driver_novo');
    confere(Driver::$todos[1]->current_job_uuid === 'o-corrente', 'troca de outro pedido do anterior: o pedido atual dele continua');
    $troca('order_corrente', 'driver_novo');
    confere(Driver::$todos[1]->current_job_uuid === null, 'o pedido trocado era o atual do anterior: current_job_uuid limpo');
    confere(Driver::$todos[2]->current_job_uuid === null, 'o novo não ganha pedido atual (ele o define ao aceitar)');
    Driver::$todos[1]->avisos = [];

    Log::$linhas = [];
    $resposta    = $troca('order_aberto', 'driver_comum');
    $aberto      = Order::$todos[1];
    confere($resposta->status === 200 && $aberto->adhoc === false && $aberto->driver_assigned_uuid === 'm-comum', 'pedido aberto sem motoboy: passa a ser do escolhido, com o adhoc desligado');
    confere(($aberto->atribuicoes[0]['adhoc_no_save'] ?? null) === false, 'adhoc desligado antes do save (o HandleOrderDriverAssigned só avisa pedido não aberto)');
    confere(array_key_exists('anterior', Log::$linhas[0][2] ?? []) && Log::$linhas[0][2]['anterior'] === null && count(Driver::$todos[1]->avisos) === 0, 'sem motoboy anterior: ninguém é avisado e o log fica com anterior nulo');

    Driver::$todos[1]->avisos = [];
    $troca('order_rastreio', 'driver_novo');
    confere((Driver::$todos[1]->avisos[0]->message ?? null) === 'Pedido RP3 passou para outro motoboy.', 'sem o número do iFood: o de rastreio, sem "#"');

    \Teste\Falhas::$aviso = true;
    Log::$linhas          = [];
    $resposta             = $troca('order_rastreio', 'driver_comum');
    \Teste\Falhas::$aviso = false;
    confere($resposta->status === 200 && Order::$todos[2]->driver_assigned_uuid === 'm-comum', 'aviso ao anterior falhou: a troca vale assim mesmo');
    confere(array_column(Log::$linhas, 1) === ['[entregas] líder trocou o motoboy, mas o aviso ao anterior falhou', '[entregas] líder trocou o motoboy'], 'a falha do aviso fica no log');

    Cache::$ocupada = true;
    $resposta       = $troca('order_ifood', 'driver_comum');
    Cache::$ocupada = false;
    confere($resposta->status === 409 && $resposta->dados === ['errors' => ['Outra pessoa está mexendo neste pedido. Tente de novo.']] && Order::$todos[0]->driver_assigned_uuid === 'm-novo',
        'trava ocupada (outro líder, aceite ou cancelamento): 409 e nada muda');

    echo '== PedidoPassadoParaOutro' . PHP_EOL;
    confere(PedidoPassadoParaOutro::rotulo('4821') === '#4821' && PedidoPassadoParaOutro::rotulo('RP-1') === 'RP-1', 'rótulo: "#" só no número do iFood');
    confere((new PedidoPassadoParaOutro('1', 'order_x'))->via(null) === ['NotificationChannels\Fcm\FcmChannel'], 'só push (FcmChannel, trocado pelo CanalFcmEntregas)');
    confere(in_array('Illuminate\Contracts\Queue\ShouldQueue', class_implements(PedidoPassadoParaOutro::class), true), 'vai para a fila');

    echo '== Rotas (RouteServiceProvider)' . PHP_EOL;
    $rotas = file_get_contents('/repo/api/app/Providers/RouteServiceProvider.php');
    foreach ([
        'use App\Http\Controllers\Entregas\LiderController;',
        "RateLimiter::for('entregas-lider', fn (Request \$request) => Limit::perMinute(120)->by('entregas-lider:' . (session('user') ?: \$request->ip())));",
        "Route::prefix('v1/entregas/lider')",
        "->middleware(['fleetbase.api', 'throttle:entregas-lider'])",
        "Route::get('acesso', [LiderController::class, 'acesso']);",
        "Route::get('mapa', [LiderController::class, 'mapa']);",
        "Route::post('pedidos/{id}/motoboy', [LiderController::class, 'trocarMotoboy']);",
    ] as $trecho) {
        confere(str_contains($rotas, $trecho), "rota/limitador: {$trecho}");
    }

    echo '== Fleet-Ops: o que a troca usa (cópia em packages/, a versão da produção)' . PHP_EOL;
    $order    = file_get_contents('/repo/packages/fleetops/server/src/Models/Order.php');
    $observer = file_get_contents('/repo/packages/fleetops/server/src/Observers/OrderObserver.php');
    $ouvinte  = file_get_contents('/repo/packages/fleetops/server/src/Listeners/HandleOrderDriverAssigned.php');
    confere(str_contains($order, 'public function assignDriver($driver, $silent = false)'), 'Order::assignDriver($driver, $silent) existe');
    confere(str_contains($observer, "if (\$order->wasChanged('driver_assigned_uuid')) {") && str_contains($observer, '$order->notifyDriverAssigned();'),
        'o OrderObserver dispara o OrderDriverAssigned quando o motoboy muda (por isso o assignDriver silencioso)');
    confere(str_contains($ouvinte, '$order->adhoc === false'), 'o HandleOrderDriverAssigned só avisa pedido não aberto (por isso o adhoc falso)');

    echo PHP_EOL . "FALHAS: {$falhas}" . PHP_EOL;
}
