<?php

// Criar e entrar em organização só para o administrador do sistema (BarrarOrganizacaoParaNaoAdmin): int/v1/auth/
// create-organization e join-organization levam 403 para quem não é type admin; o resto do grupo fleetbase.protected
// passa. Confere também o registro no RouteServiceProvider e as rotas no core (cópia em packages/core-api).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/barrar-organizacao.php

namespace Illuminate\Http {
    class Request
    {
        public function __construct(private string $caminho, private string $metodo = 'POST') {}
        public function path(): string { return $this->caminho; }
        public function method(): string { return $this->metodo; }
    }
}

namespace Illuminate\Support\Facades {
    class Log
    {
        public static array $linhas = [];
        public static function info(string $mensagem, array $contexto = []): void { static::$linhas[] = ['info', $mensagem, $contexto]; }
    }
}

namespace Fleetbase\Models {
    class User
    {
        public function __construct(public string $public_id, public string $type) {}
        public function isAdmin(): bool { return $this->type === 'admin'; }
    }
}

namespace Fleetbase\Support {
    /** Como o Auth::getUserFromSession do core: o usuário da requisição (aqui, o que o teste pôs na "sessão"). */
    class Auth
    {
        public static $usuario = null;
        public static int $consultas = 0;
        public static function getUserFromSession($request = null) { static::$consultas++; return static::$usuario; }
    }
}

namespace Teste {
    class Resposta
    {
        public function __construct(public $dados, public int $status) {}
    }

    class Fabrica
    {
        public function json($dados, int $status = 200) { return new Resposta(json_decode(json_encode($dados), true), $status); }
    }
}

namespace {
    use App\Http\Middleware\BarrarOrganizacaoParaNaoAdmin;
    use Fleetbase\Models\User;
    use Fleetbase\Support\Auth;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Log;

    require '/repo/api/app/Http/Middleware/BarrarOrganizacaoParaNaoAdmin.php';

    const MENSAGEM = 'Só o administrador do sistema cria ou entra em organização.';

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

    function chamar(string $caminho, $usuario, string $metodo = 'POST')
    {
        Auth::$usuario = $usuario;

        return (new BarrarOrganizacaoParaNaoAdmin())->handle(new Request($caminho, $metodo), fn () => 'passou');
    }

    function barrado($resposta): bool
    {
        return $resposta instanceof \Teste\Resposta && $resposta->status === 403 && ($resposta->dados['errors'] ?? null) === [MENSAGEM];
    }

    $admin   = new User('user_admin', 'admin');
    $central = new User('user_central', 'user');     // da central, mesmo com o papel Administrator na empresa
    $motoboy = new User('user_moto', 'driver');
    $loja    = new User('user_loja', 'customer');

    echo '== As duas rotas' . PHP_EOL;
    foreach (BarrarOrganizacaoParaNaoAdmin::ROTAS as $rota) {
        confere(chamar($rota, $admin) === 'passou', "admin do sistema em {$rota}: passa");
        confere(barrado(chamar($rota, $central)), "usuário da central (type user) em {$rota}: 403");
        confere(barrado(chamar($rota, $motoboy)) && barrado(chamar($rota, $loja)), "motoboy e loja em {$rota}: 403");
        confere(barrado(chamar($rota, null)), "sem usuário na sessão em {$rota}: 403 (por garantia)");
    }
    Log::$linhas = [];
    chamar('int/v1/auth/create-organization', $central);
    confere(Log::$linhas === [['info', '[entregas] organização: criar ou entrar barrado (não é admin)', ['caminho' => 'int/v1/auth/create-organization', 'usuario' => 'user_central']]], 'registra o log com o caminho e o public_id');
    confere(barrado(chamar('/int/v1/auth/create-organizatio%6E/', $central)), 'caminho codificado e com barras nas pontas: 403');

    echo '== O resto passa sem consultar o usuário' . PHP_EOL;
    Auth::$consultas = 0;
    foreach (['int/v1/auth/switch-organization', 'int/v1/auth/session', 'int/v1/auth/organizations', 'int/v1/orders', 'int/v1/auth/create-organization-x', 'customer-portal/int/v1/auth/create-organization'] as $caminho) {
        confere(chamar($caminho, $central) === 'passou', "{$caminho}: passa");
    }
    confere(Auth::$consultas === 0, 'e nem consulta o usuário');

    echo '== Registro e rotas do core' . PHP_EOL;
    $rotas = str_replace("\r\n", "\n", file_get_contents('/repo/api/app/Providers/RouteServiceProvider.php'));
    confere(str_contains($rotas, "\$this->app['router']->pushMiddlewareToGroup('fleetbase.protected', BarrarOrganizacaoParaNaoAdmin::class);"), 'no fim do grupo fleetbase.protected (depois da autenticação)');
    confere(str_contains($rotas, 'use App\Http\Middleware\BarrarOrganizacaoParaNaoAdmin;'), 'import no RouteServiceProvider');

    $expansao = str_replace("\r\n", "\n", file_get_contents('/repo/packages/core-api/src/Expansions/Route.php'));
    preg_match("/\\\$router->group\(\['middleware' => \['fleetbase.protected'\]\], function \(Router \\\$router\)(.*?)\n                \}\);/s", $expansao, $protegidas);
    confere(str_contains($protegidas[1] ?? '', "\$router->post('join-organization', [\$authControllerClass, 'joinOrganization']);") && str_contains($protegidas[1] ?? '', "\$router->post('create-organization', [\$authControllerClass, 'createOrganization']);"), 'no core, as duas rotas estão no grupo fleetbase.protected do fleetbaseAuthRoutes');
    $nucleo = file_get_contents('/repo/packages/core-api/src/routes.php');
    confere(str_contains($nucleo, '$router->fleetbaseAuthRoutes();') && str_contains($nucleo, "config('fleetbase.api.routing.internal_prefix', 'int')"), 'o fleetbaseAuthRoutes fica em int/v1 (prefixo auth)');
    confere(str_contains($nucleo, "\$router->post('create-account', 'OnboardController@createAccount');") && str_contains($nucleo, "\$router->post('accept-company-invite', 'UserController@acceptCompanyInvite');"), 'instalação (onboard/create-account) e convite (users/accept-company-invite) são outras rotas: não passam por aqui');
    $usuarioDoCore = file_get_contents('/repo/packages/core-api/src/Models/User.php');
    confere(str_contains($usuarioDoCore, "return \$this->type === 'admin';") && str_contains($usuarioDoCore, 'public function getIsAdminAttribute(): bool'), 'isAdmin() = type admin, o mesmo is_admin que o console usa (currentUser.isAdmin)');

    echo PHP_EOL . 'FALHAS: ' . $falhas . PHP_EOL;
}
