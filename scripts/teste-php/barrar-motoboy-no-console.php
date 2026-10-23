<?php

// Motoboy fora do console (BarrarMotoboyNoConsole): login e token de motoboy (type driver ou com cadastro de motoboy)
// levam 403 na API do console e do portal (int/v1, customer-portal/int/v1); o app do motoboy (v1) passa; o papel
// Administrator na empresa e o type admin liberam; a loja (customer) fica com o ProtegerPortalLoja.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/barrar-motoboy-no-console.php

namespace Illuminate\Http {
    class Request
    {
        public function __construct(
            private string $caminho,
            private string $metodo = 'GET',
            private ?string $token = null,
            private array $entrada = [],
        ) {}
        public function path(): string { return $this->caminho; }
        public function method(): string { return $this->metodo; }
        public function isMethod(string $metodo): bool { return strtoupper($metodo) === $this->metodo; }
        public function bearerToken(): ?string { return $this->token; }
        public function input($chave = null, $padrao = null) { return $chave === null ? $this->entrada : ($this->entrada[$chave] ?? $padrao); }
    }
}

namespace Illuminate\Support\Facades {
    class Log
    {
        public static array $linhas = [];
        public static function info(string $mensagem, array $contexto = []): void { static::$linhas[] = ['info', $mensagem, $contexto]; }
        public static function warning(string $mensagem, array $contexto = []): void { static::$linhas[] = ['warning', $mensagem, $contexto]; }
    }
}

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

    /** Consulta em memória. Como o SoftDeletes do Fleetbase\Models\Model, a linha com deleted_at fica de fora. */
    class Consulta
    {
        public static array $registro = [];

        public function __construct(private string $modelo, private array $linhas)
        {
            $this->linhas = array_filter($this->linhas, fn ($linha) => ($linha->deleted_at ?? null) === null);
        }

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

        public function first()
        {
            $linha = reset($this->linhas);

            return $linha === false ? null : $linha;
        }

        public function exists(): bool { return $this->linhas !== []; }
    }

    abstract class Modelo
    {
        public $deleted_at = null;

        public function __construct(array $campos = [])
        {
            foreach ($campos as $campo => $valor) {
                $this->$campo = $valor;
            }
        }

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
        public $public_id;
        public $company_uuid;
        public $email;
        public $phone;
        public $type = 'user';
        public $status = 'active';
        public function isAdmin(): bool { return $this->type === 'admin'; }
    }

    class CompanyUser extends \Teste\Modelo
    {
        public static array $todos = [];
        public $user_uuid;
        public $company_uuid;
        public $status = 'active';
        public array $papeis = [];
        // como o hasRole do Spatie com um nome: algum papel do vínculo com esse nome
        public function hasRole($papel): bool { return in_array($papel, $this->papeis, true); }
    }
}

namespace Fleetbase\FleetOps\Models {
    class Driver extends \Teste\Modelo
    {
        public static array $todos = [];
        public $uuid;
        public $user_uuid;
        public $company_uuid;
    }
}

namespace Laravel\Sanctum {
    /** findToken do Sanctum: "id|texto" de um token existente → o token, com o dono em tokenable; senão null. */
    class PersonalAccessToken
    {
        public static array $tokens = [];
        public static array $consultas = [];
        public function __construct(public $tokenable) {}
        public static function findToken($token)
        {
            static::$consultas[] = $token;
            $dono = static::$tokens[$token] ?? null;

            return $dono ? new static($dono) : null;
        }
    }
}

namespace {
    use App\Http\Middleware\BarrarMotoboyNoConsole;
    use Fleetbase\FleetOps\Models\Driver;
    use Fleetbase\Models\CompanyUser;
    use Fleetbase\Models\User;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Log;
    use Laravel\Sanctum\PersonalAccessToken;

    require '/repo/api/app/Http/Middleware/ProtegerPortalLoja.php';
    require '/repo/api/app/Http/Middleware/BarrarMotoboyNoConsole.php';

    const EMPRESA  = 'empresa-a';
    const MENSAGEM = 'O console é só para a central. Use o app Entregas.';

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

    /** Um usuário no "banco", com token "<n>|<public_id>" e vínculo com a empresa (papéis), e o cadastro de motoboy. */
    function usuario(string $id, string $tipo, bool $motoboy = false, array $papeis = [], ?string $motoboyExcluido = null): User
    {
        $usuario = new User([
            'uuid'         => "u-{$id}",
            'public_id'    => "user_{$id}",
            'company_uuid' => EMPRESA,
            'email'        => "{$id}@exemplo.com",
            'phone'        => "+55119{$id}",
            'type'         => $tipo,
        ]);
        User::$todos[]                                 = $usuario;
        PersonalAccessToken::$tokens["7|tok-{$id}"]    = $usuario;
        CompanyUser::$todos[]                          = new CompanyUser(['user_uuid' => $usuario->uuid, 'company_uuid' => EMPRESA, 'papeis' => $papeis]);
        if ($motoboy || $motoboyExcluido) {
            Driver::$todos[] = new Driver(['uuid' => "d-{$id}", 'user_uuid' => $usuario->uuid, 'company_uuid' => EMPRESA, 'deleted_at' => $motoboyExcluido]);
        }

        return $usuario;
    }

    function reiniciar(): void
    {
        User::$todos                    = [];
        CompanyUser::$todos             = [];
        Driver::$todos                  = [];
        PersonalAccessToken::$tokens    = [];
        PersonalAccessToken::$consultas = [];
        Log::$linhas                    = [];
    }

    /** Roda o middleware; devolve a resposta (403) ou 'passou'. */
    function chamar(string $caminho, string $metodo = 'GET', ?string $token = null, array $entrada = [])
    {
        return (new BarrarMotoboyNoConsole())->handle(new Request($caminho, $metodo, $token, $entrada), fn () => 'passou');
    }

    function barrado($resposta): bool
    {
        return $resposta instanceof \Teste\Resposta && $resposta->status === 403 && ($resposta->dados['errors'] ?? null) === [MENSAGEM];
    }

    reiniciar();
    $motoboy      = usuario('moto', 'driver', true);
    $semCadastro  = usuario('driversemcad', 'driver');
    $userMotoboy  = usuario('usermoto', 'user', true);
    $comum        = usuario('comum', 'user');
    $adminMotoboy = usuario('admin', 'admin', true);
    $lider        = usuario('lider', 'driver', true, ['Líder de motoboys']);
    $chefe        = usuario('chefe', 'driver', true, ['Administrator']);
    $excluido     = usuario('excluido', 'user', false, [], '2026-10-01 10:00:00');
    $loja         = usuario('loja', 'customer', true);

    echo '== Login' . PHP_EOL;
    $resposta = chamar('int/v1/auth/login', 'POST', null, ['identity' => 'moto@exemplo.com', 'password' => 'x']);
    confere(barrado($resposta), 'motoboy (type driver) no login do console: 403 com a mensagem');
    confere(barrado(chamar('int/v1/auth/login', 'POST', null, ['identity' => '+55119moto'])), 'motoboy pelo telefone: 403');
    confere(barrado(chamar('customer-portal/int/v1/auth/login', 'POST', null, ['identity' => 'moto@exemplo.com'])), 'motoboy no login do portal: 403');
    confere(barrado(chamar('int/v1/auth/login', 'POST', null, ['identity' => 'lider@exemplo.com'])), 'líder dos motoboys (sem o papel Administrator) no login: 403');
    confere(chamar('int/v1/auth/login', 'POST', null, ['identity' => 'comum@exemplo.com']) === 'passou', 'usuário comum no login: passa');
    confere(chamar('int/v1/auth/login', 'POST', null, ['identity' => 'ninguem@exemplo.com']) === 'passou', 'identity que não existe: passa (o core responde)');
    confere(chamar('int/v1/auth/login', 'POST', null, []) === 'passou' && chamar('int/v1/auth/login', 'POST', null, ['identity' => ['x']]) === 'passou', 'sem identity ou identity que não é texto: passa');
    confere(chamar('int/v1/auth/login', 'POST', null, ['identity' => 'chefe@exemplo.com']) === 'passou', 'motoboy com o papel Administrator no login: passa');
    confere(chamar('int/v1/auth/login', 'GET', null, ['identity' => 'moto@exemplo.com']) === 'passou', 'GET no caminho do login (sem token): não é login, passa');

    echo '== Com token' . PHP_EOL;
    Log::$linhas = [];
    confere(barrado(chamar('int/v1/auth/session', 'GET', '7|tok-moto')), 'motoboy em int/v1/auth/session: 403 (derruba quem já está logado)');
    confere(Log::$linhas === [['info', '[entregas] console: motoboy barrado', ['caminho' => 'int/v1/auth/session', 'usuario' => 'user_moto']]], 'e registra o log com o caminho e o public_id (' . json_encode(Log::$linhas) . ')');
    confere(barrado(chamar('customer-portal/int/v1/orders', 'GET', '7|tok-moto')), 'motoboy em customer-portal/int/v1/orders: 403');
    confere(barrado(chamar('int/v1/orders', 'POST', '7|tok-lider')), 'líder em int/v1/orders: 403');
    confere(barrado(chamar('int/v1/fleet-ops/drivers', 'GET', '7|tok-driversemcad')), 'type driver sem cadastro de motoboy: 403');
    confere(barrado(chamar('int/v1/auth/session', 'GET', '7|tok-usermoto')), 'type user com cadastro de motoboy: 403');
    confere(chamar('int/v1/auth/session', 'GET', '7|tok-comum') === 'passou', 'type user sem cadastro de motoboy: passa');
    confere(chamar('int/v1/auth/session', 'GET', '7|tok-excluido') === 'passou', 'type user com cadastro de motoboy excluído: passa');
    confere(chamar('int/v1/auth/session', 'GET', '7|tok-admin') === 'passou', 'type admin com cadastro de motoboy: passa');
    confere(chamar('int/v1/orders', 'GET', '7|tok-chefe') === 'passou', 'motoboy com o papel Administrator na empresa: passa');
    confere(chamar('int/v1/auth/session', 'GET', '7|tok-loja') === 'passou', 'loja (customer, mesmo com cadastro de motoboy): passa (regras do ProtegerPortalLoja)');

    echo '== Caminhos' . PHP_EOL;
    confere(chamar('v1/drivers/driver_moto/track', 'POST', '7|tok-moto') === 'passou', 'motoboy em v1/drivers/... (app): passa');
    confere(chamar('v1/entregas/motoboy/app', 'GET', '7|tok-moto') === 'passou', 'motoboy em v1/entregas/motoboy/... (app): passa');
    confere(chamar('~registry/v1/engines', 'GET', '7|tok-moto') === 'passou', 'motoboy em ~registry/v1/engines: passa');
    confere(chamar('v1/auth/login', 'POST', null, ['identity' => 'moto@exemplo.com']) === 'passou', 'login do app (v1/auth/login): passa');
    confere(barrado(chamar('int/v1/auth/sessio%6E', 'GET', '7|tok-moto')), 'caminho codificado (int/v1/auth/sessio%6E) é decodificado: 403');
    confere(barrado(chamar('/int/v1/auth/session/', 'GET', '7|tok-moto')), 'barras nas pontas: 403');
    confere(chamar('int/v1x/auth/session', 'GET', '7|tok-moto') === 'passou', 'prefixo parecido (int/v1x): passa');

    echo '== Sem usuário' . PHP_EOL;
    PersonalAccessToken::$consultas = [];
    confere(chamar('int/v1/auth/session') === 'passou', 'sem token: passa');
    confere(chamar('int/v1/orders', 'GET', 'flb_live_chave') === 'passou', 'chave de API (sem "|"): passa');
    confere(PersonalAccessToken::$consultas === [], 'e nem consulta o banco de tokens');
    confere(chamar('int/v1/orders', 'GET', '9|nao-existe') === 'passou', 'token inválido: passa');

    echo PHP_EOL . 'FALHAS: ' . $falhas . PHP_EOL;
}
