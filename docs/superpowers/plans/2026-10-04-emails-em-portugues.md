# E-mails em português: plano de implementação

> **Para agentes:** sub-skill obrigatória: use `superpowers:subagent-driven-development` (recomendado) ou
> `superpowers:executing-plans` para executar este plano tarefa por tarefa. Os passos usam caixas (`- [ ]`) para
> acompanhamento.

**Objetivo:** todos os e-mails do Entregas RestaurantePro em pt-BR, no layout minimalista (estilo C), sem e-mail ao
motoboy (exceto o código de login), com o link de "esqueci a senha" da loja apontando para o portal.

**Arquitetura:** tudo fica em `api/` e é aplicado por cima dos pacotes do Composer, sem editá-los:

- views sobrescritas em `api/resources/views/vendor/{mail,notifications,fleetbase}`;
- um canal de e-mail próprio (`CanalEmailEntregas`, no lugar do `MailChannel` do Laravel, como já é feito com o
  `CanalFcmEntregas`) que reescreve as notificações conhecidas por um catálogo (`EmailsEmPortugues`);
- uma escuta de `MessageSending` que traduz o assunto dos Mailables.

A única mudança de frontend é a rota de reset do portal da loja, que passa a receber o id.

**Tecnologias:** Laravel 10 (commit `74e222ce`), core-api 1.6.61, fleetops-api 0.6.65, Blade/Markdown mail, php-wasm
(PHP 8.2) para os testes, Ember (portal).

**Desenho:** `docs/superpowers/specs/2026-10-04-emails-em-portugues-design.md`.

---

## Contexto para quem executa

- Leia o `CLAUDE.md` da raiz.
  - Responda e comente em pt-BR.
  - `packages/*/server` e `packages/core-api` **não** vão para a produção: servem só de referência e de arquivos reais nos
    testes.
- **Testes PHP:** não há PHP no Windows. Rode com o php-wasm:
  `PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/emails.php`.
  - O teste imprime `PASSA`/`FALHA` e termina com `FALHAS: <n>`.
  - Para instalar o php-wasm numa pasta fora do repo, uma vez: `npm i --prefix <pasta> @php-wasm/node@3.1.54 @php-wasm/universal@3.1.54`.
    Nesta sessão já está em
    `C:/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/5e9fefbb-16d5-4968-835a-1df8e42aadad/scratchpad/phpwasm`.
  - Sintaxe de PHP novo: `PHP_WASM_DIR=<pasta> node scripts/teste-php/sintaxe.mjs <arquivos.php>`.
- **Modelo de teste:** `scripts/teste-php/avisos-push.php` e `stubs-avisos.php` (os do push do motoboy).
  - O `stubs.php` já define: `Illuminate\Notifications\Notification` (vazia), `ShouldQueue`, `Queueable`,
    `Fleetbase\FleetOps\Models\Driver` e `Order`, as funções `confere()`, `resumo()` e `confereVersaoDoFleetOps()`, e o
    autoload das classes `App\` a partir de `/repo/api/app`.
  - O `stubs.php` **não** define `Log`, `config()` nem `data_get()`; o `stubs-emails.php` (Tarefa 1) define.
- **Commits:**
  - antes de cada commit, confira `git rev-parse --show-toplevel`, que deve ser `.../Delivery`;
  - termine a mensagem com `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`;
  - não faça push.

## Estrutura de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `api/app/Notifications/Entregas/Email/Saudacao.php` | "Olá, {nome}!" / "Olá!", com o nome sem autolink |
| `api/app/Notifications/Entregas/Email/CodigosPorEmail.php` | Assunto, título, texto e observação dos e-mails de código (`VerificationMail`), por `type` |
| `api/app/Notifications/Entregas/Email/EmailsEmPortugues.php` | Catálogo: notificação conhecida → `MailMessage` em pt-BR |
| `api/app/Notifications/Entregas/Email/CanalEmailEntregas.php` | Canal de e-mail: corta motoboy, aplica o catálogo, registra o que não é traduzido |
| `api/app/Notifications/Entregas/Email/MarcaDoEmail.php` | Logo e URL do console usados no cabeçalho dos e-mails |
| `api/app/Listeners/Entregas/AssuntoDosEmailsEmPortugues.php` | `MessageSending`: assunto em pt-BR dos Mailables |
| `api/app/Providers/AppServiceProvider.php` | + bind do `MailChannel` |
| `api/app/Providers/EventServiceProvider.php` | + listener de `MessageSending` |
| `api/resources/views/vendor/mail/html/{message,codigo}.blade.php`, `html/themes/default.css` | Layout HTML (estilo C) |
| `api/resources/views/vendor/mail/text/{message,codigo}.blade.php` | Versão em texto |
| `api/resources/views/vendor/notifications/email.blade.php` | Corpo das notificações (MailMessage) |
| `api/resources/views/vendor/fleetbase/layout/mail.blade.php` | Layout dos Mailables da Fleetbase → o mesmo layout |
| `api/resources/views/vendor/fleetbase/mail/{verification,user-credentials,test}.blade.php` | Corpo dos Mailables |
| `packages/customer-portal/addon/routes.js` | Rota de reset com `:id` |
| `scripts/teste-php/laravel10/{SimpleMessage,MailMessage,Action}.php` | Cópias reais do Laravel 10 (MIT) para os testes |
| `scripts/teste-php/stubs-emails.php`, `scripts/teste-php/emails.php` | Stubs e teste de comportamento |
| `scripts/teste-emails-views.mjs` | Checagem estática das views (sem inglês, sem "Fleetbase", sem recuo que vira bloco de código) |

---

### Tarefa 1: base do teste

**Arquivos:**
- Criar: `scripts/teste-php/laravel10/SimpleMessage.php`, `MailMessage.php` e `Action.php` (cópias)
- Criar: `scripts/teste-php/stubs-emails.php`
- Criar: `scripts/teste-php/emails.php`

- [ ] **Passo 1: copiar as classes de mensagem do Laravel 10 da produção (commit `74e222ce`)**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/Delivery"
mkdir -p scripts/teste-php/laravel10
B="https://raw.githubusercontent.com/laravel/framework/74e222cee687f957d95aaadddae69270e3205cf7/src/Illuminate/Notifications"
curl -sfL "$B/Messages/SimpleMessage.php" -o scripts/teste-php/laravel10/SimpleMessage.php
curl -sfL "$B/Messages/MailMessage.php" -o scripts/teste-php/laravel10/MailMessage.php
curl -sfL "$B/Action.php" -o scripts/teste-php/laravel10/Action.php
grep -c "class " scripts/teste-php/laravel10/*.php
```
Esperado: cada arquivo com pelo menos `1` em `class `.

- [ ] **Passo 2: criar `scripts/teste-php/stubs-emails.php`**

```php
<?php

// Stubs dos testes de e-mail (emails.php), além do stubs.php. SimpleMessage, MailMessage e Action são as reais do
// Laravel 10 da produção (commit 74e222ce, MIT), copiadas em laravel10/; o resto do Laravel e da Fleetbase é mínimo.
// As notificações do core-api e do Fleet-Ops são as reais (cópias em packages/), carregadas pelo emails.php.

namespace Illuminate\Contracts\Support {
    interface Htmlable { public function toHtml(); }
    interface Renderable { public function render(); }
    interface Arrayable { public function toArray(); }
}

namespace Illuminate\Contracts\Mail {
    interface Attachable {}
    interface Mailable {}
}

namespace Illuminate\Support\Traits {
    trait Conditionable {}
}

namespace Illuminate\Mail {
    class Attachment {}
    class Markdown {}
}

namespace Illuminate\Container {
    class Container
    {
        public static function getInstance() { return new static(); }
    }
}

namespace Illuminate\Support\Facades {
    class Log
    {
        public static array $registros = [];
        public static function info($mensagem, array $contexto = []) { self::$registros[] = ['info', $mensagem, $contexto]; }
        public static function warning($mensagem, array $contexto = []) { self::$registros[] = ['warning', $mensagem, $contexto]; }
        public static function error($mensagem, array $contexto = []) { self::$registros[] = ['error', $mensagem, $contexto]; }
    }
}

namespace Illuminate\Mail\Events {
    class MessageSending
    {
        public function __construct(public $message, public array $data = []) {}
    }
}

namespace Symfony\Component\Mime {
    class Email
    {
        private ?string $assunto = null;
        public function subject(string $assunto): static { $this->assunto = $assunto; return $this; }
        public function getSubject(): ?string { return $this->assunto; }
    }
}

namespace Illuminate\Notifications\Channels {
    // o essencial do MailChannel do Laravel 10: o CanalEmailEntregas repete o send() e usa estes três métodos
    class MailChannel
    {
        protected $mailer;
        protected $markdown;

        public function __construct($mailer, $markdown)
        {
            $this->mailer   = $mailer;
            $this->markdown = $markdown;
        }

        protected function buildView($message) { return ['html' => 'notifications::email', 'text' => 'notifications::email']; }
        protected function additionalMessageData($notification) { return ['__laravel_notification' => get_class($notification)]; }
        protected function messageBuilder($notifiable, $notification, $message) { return function () {}; }
    }
}

namespace Fleetbase\Support {
    class Utils
    {
        public static function consoleUrl(string $path = '', ?array $queryParams = [], $subdomain = null): string
        {
            return 'https://entregas.restaurantepro.com.br/' . ltrim($path, '/') . ($queryParams ? '?' . http_build_query($queryParams) : '');
        }

        public static function delinkify(?string $texto): string { return (string) $texto; }
    }
}

namespace Fleetbase\Models {
    class User
    {
        public $uuid  = 'user-1';
        public $name;
        public $email;
        public $phone;
        public $type  = 'user';
        public function routeNotificationFor($canal, $notificacao = null) { return $this->email; }
    }

    class Company { public $uuid = 'empresa'; public $name; }

    class Invite { public $code; public $uri; public $subject; public $createdBy; }

    class VerificationCode { public $uuid; public $code; public $for; public $meta = []; public $subject; }
}

namespace Teste {
    // o mailer do Laravel: guarda o que o canal mandou enviar
    class CarteiroFalso
    {
        public array $enviados = [];
        public function mailer($nome = null) { return $this; }
        public function send($view, array $dados, $construtor) { $this->enviados[] = ['view' => $view, 'dados' => $dados]; return 'enviado'; }
    }

    // pedido com os campos que o catálogo lê (o Order do stubs.php não tem rastreio nem cliente)
    class PedidoDoEmail extends \Fleetbase\FleetOps\Models\Order
    {
        public $trackingNumber;
        public $tracking;
        public $customer;
    }
}

namespace {
    function config($chave = null, $padrao = null)
    {
        $valores = [
            'app.name'                  => 'Entregas',
            'fleetbase.branding.logo_url' => 'https://entregas.restaurantepro.com.br/images/logo.png',
        ];

        return $valores[$chave] ?? $padrao;
    }

    function data_get($alvo, $chave, $padrao = null)
    {
        foreach (explode('.', $chave) as $parte) {
            if (is_array($alvo) && array_key_exists($parte, $alvo)) {
                $alvo = $alvo[$parte];
            } elseif (is_object($alvo) && isset($alvo->{$parte})) {
                $alvo = $alvo->{$parte};
            } else {
                return $padrao;
            }
        }

        return $alvo;
    }

    require __DIR__ . '/laravel10/Action.php';
    require __DIR__ . '/laravel10/SimpleMessage.php';
    require __DIR__ . '/laravel10/MailMessage.php';
}
```

- [ ] **Passo 3: criar `scripts/teste-php/emails.php` só com a checagem de versão**

```php
<?php

// E-mails em pt-BR: CodigosPorEmail, EmailsEmPortugues, CanalEmailEntregas e o assunto dos Mailables.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/emails.php

require __DIR__ . '/stubs.php';
require __DIR__ . '/stubs-emails.php';

// as cópias em packages/ (carregadas pelos testes) têm de ser as versões que a produção instala (api/composer.lock)
function confereVersaoDoCore(): void
{
    $local    = json_decode(file_get_contents('/repo/packages/core-api/composer.json'), true)['version'] ?? null;
    $producao = null;
    foreach (json_decode(file_get_contents('/repo/api/composer.lock'), true)['packages'] ?? [] as $pacote) {
        if (($pacote['name'] ?? null) === 'fleetbase/core-api') {
            $producao = ltrim((string) $pacote['version'], 'v');
        }
    }
    confere($local !== null && $local === $producao, "core-api local ({$local}) é a versão da produção ({$producao})");
}

echo '== Versões' . PHP_EOL;
confereVersaoDoCore();
confereVersaoDoFleetOps();

resumo();
```

- [ ] **Passo 4: rodar**

```bash
P="C:/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/5e9fefbb-16d5-4968-835a-1df8e42aadad/scratchpad/phpwasm"
PHP_WASM_DIR="$P" node scripts/teste-php/rodar.mjs scripts/teste-php/emails.php
```
Esperado: duas linhas `PASSA` e `FALHAS: 0`.

- [ ] **Passo 5: commit**

```bash
git add scripts/teste-php/laravel10 scripts/teste-php/stubs-emails.php scripts/teste-php/emails.php
git commit -m "Teste de e-mails: base (MailMessage real do Laravel 10 e stubs)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Tarefa 2: saudação e textos dos e-mails de código

**Arquivos:**
- Criar: `api/app/Notifications/Entregas/Email/Saudacao.php`
- Criar: `api/app/Notifications/Entregas/Email/CodigosPorEmail.php`
- Modificar: `scripts/teste-php/emails.php`

- [ ] **Passo 1: escrever o teste.** Adicione ao `emails.php`, antes do `resumo();`:

```php
use App\Notifications\Entregas\Email\CodigosPorEmail;
use App\Notifications\Entregas\Email\Saudacao;

echo PHP_EOL . '== Saudação' . PHP_EOL;
confere(Saudacao::para('Ana') === 'Olá, Ana!', 'saudação com nome');
confere(Saudacao::para('  ') === 'Olá!', 'saudação sem nome (só espaços)');
confere(Saudacao::para(null) === 'Olá!', 'saudação sem nome (null)');

echo PHP_EOL . '== Códigos por e-mail' . PHP_EOL;
$codigos = [
    'email_verification'    => ['123456 é o seu código de verificação', 'Confirme seu e-mail', 'Olá, Ana! Use o código abaixo para confirmar seu e-mail no Entregas RestaurantePro.', 'O código vale por 1 hora. Se não foi você, ignore este e-mail.'],
    '2fa'                   => ['123456 é o seu código de acesso', 'Seu código de acesso', 'Olá, Ana! Para concluir a entrada na sua conta, digite este código:', 'Se você não tentou entrar agora, troque sua senha.'],
    'driver_login'          => ['123456 é o seu código para entrar no app', 'Entrar no app de entregas', 'Olá, Ana! Digite este código no app para entrar:', 'Se não foi você, ignore este e-mail.'],
    'driver_password_reset' => ['123456 é o seu código para redefinir a senha', 'Redefinir a senha do app', 'Olá, Ana! Use este código no app para criar uma nova senha:', 'Se não foi você, ignore este e-mail.'],
    'tipo_inventado'        => ['123456 é o seu código', 'Seu código', 'Olá, Ana! Use este código para continuar:', 'Se não foi você, ignore este e-mail.'],
];
foreach ($codigos as $tipo => [$assunto, $titulo, $texto, $observacao]) {
    $t = CodigosPorEmail::textos($tipo, 'Ana');
    confere(CodigosPorEmail::assunto($tipo, '123456') === $assunto, "assunto de {$tipo}");
    confere($t['titulo'] === $titulo && $t['texto'] === $texto && $t['observacao'] === $observacao, "título, texto e observação de {$tipo}");
}
confere(CodigosPorEmail::conhece('2fa') && !CodigosPorEmail::conhece('tipo_inventado') && !CodigosPorEmail::conhece(null), 'conhece só os tipos da tabela');
confere(CodigosPorEmail::textos('2fa', null)['texto'] === 'Olá! Para concluir a entrada na sua conta, digite este código:', 'texto sem nome');
```

- [ ] **Passo 2: rodar e ver falhar**

Rode o mesmo comando da Tarefa 1. Esperado: erro de PHP `Class "App\Notifications\Entregas\Email\Saudacao" not found` (o
`rodar.mjs` sai com 1).

- [ ] **Passo 3: criar `api/app/Notifications/Entregas/Email/Saudacao.php`**

```php
<?php

namespace App\Notifications\Entregas\Email;

use Fleetbase\Support\Utils;

/**
 * Entregas RestaurantePro: saudação dos e-mails ("Olá, Ana!" ou "Olá!"). O nome passa pelo delinkify do core-api, como
 * nas views originais, para um nome com cara de link ou e-mail não virar link clicável no leitor de e-mail.
 */
class Saudacao
{
    public static function para(?string $nome): string
    {
        $nome = trim(Utils::delinkify($nome));

        return $nome === '' ? 'Olá!' : "Olá, {$nome}!";
    }
}
```

- [ ] **Passo 4: criar `api/app/Notifications/Entregas/Email/CodigosPorEmail.php`**

```php
<?php

namespace App\Notifications\Entregas\Email;

/**
 * Entregas RestaurantePro: textos em pt-BR dos e-mails de código (VerificationMail do core-api), por tipo de código
 * (`VerificationCode::for`). Usado pela view vendor/fleetbase/mail/verification.blade.php (título, texto, observação) e
 * pelo AssuntoDosEmailsEmPortugues (assunto). Tipo fora da tabela usa o texto genérico.
 */
class CodigosPorEmail
{
    /** tipo => [assunto (%s = código), título, frase depois da saudação, observação] */
    private const TEXTOS = [
        'email_verification'    => ['%s é o seu código de verificação', 'Confirme seu e-mail', 'Use o código abaixo para confirmar seu e-mail no Entregas RestaurantePro.', 'O código vale por 1 hora. Se não foi você, ignore este e-mail.'],
        '2fa'                   => ['%s é o seu código de acesso', 'Seu código de acesso', 'Para concluir a entrada na sua conta, digite este código:', 'Se você não tentou entrar agora, troque sua senha.'],
        'driver_login'          => ['%s é o seu código para entrar no app', 'Entrar no app de entregas', 'Digite este código no app para entrar:', 'Se não foi você, ignore este e-mail.'],
        'driver_password_reset' => ['%s é o seu código para redefinir a senha', 'Redefinir a senha do app', 'Use este código no app para criar uma nova senha:', 'Se não foi você, ignore este e-mail.'],
    ];

    private const PADRAO = ['%s é o seu código', 'Seu código', 'Use este código para continuar:', 'Se não foi você, ignore este e-mail.'];

    public static function conhece(?string $tipo): bool
    {
        return $tipo !== null && isset(self::TEXTOS[$tipo]);
    }

    public static function assunto(?string $tipo, string $codigo): string
    {
        return sprintf(self::linha($tipo)[0], $codigo);
    }

    /** @return array{titulo: string, texto: string, observacao: string} */
    public static function textos(?string $tipo, ?string $nome): array
    {
        [, $titulo, $frase, $observacao] = self::linha($tipo);

        return ['titulo' => $titulo, 'texto' => Saudacao::para($nome) . ' ' . $frase, 'observacao' => $observacao];
    }

    private static function linha(?string $tipo): array
    {
        return self::conhece($tipo) ? self::TEXTOS[$tipo] : self::PADRAO;
    }
}
```

- [ ] **Passo 5: rodar e ver passar.** Mesmo comando. Esperado: só `PASSA` e `FALHAS: 0`.

- [ ] **Passo 6: commit**

```bash
git add api/app/Notifications/Entregas/Email/Saudacao.php api/app/Notifications/Entregas/Email/CodigosPorEmail.php scripts/teste-php/emails.php
git commit -m "E-mails: textos em pt-BR dos e-mails de código e saudação

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Tarefa 3: catálogo das notificações (`EmailsEmPortugues`)

**Arquivos:**
- Criar: `api/app/Notifications/Entregas/Email/EmailsEmPortugues.php`
- Modificar: `scripts/teste-php/emails.php`

- [ ] **Passo 1: escrever o teste.** No topo do `emails.php`, depois dos dois `require` de stubs, carregue as notificações reais:

```php
// notificações reais do core-api e do Fleet-Ops (cópias em packages/, nas versões da produção)
require '/repo/packages/core-api/src/Notifications/UserForgotPassword.php';
require '/repo/packages/core-api/src/Notifications/UserInvited.php';
require '/repo/packages/core-api/src/Notifications/UserEmailChange.php';
require '/repo/packages/core-api/src/Notifications/UserCreated.php';
require '/repo/packages/core-api/src/Notifications/UserAcceptedCompanyInvite.php';
require '/repo/packages/fleetops/server/src/Notifications/OrderDispatchFailed.php';
require '/repo/packages/fleetops/server/src/Notifications/OrderCompleted.php';
require '/repo/packages/fleetops/server/src/Notifications/OrderFailed.php';
require '/repo/packages/fleetops/server/src/Notifications/OrderCanceled.php';
```

E, antes do `resumo();`:

```php
use App\Notifications\Entregas\Email\EmailsEmPortugues;
use Fleetbase\FleetOps\Notifications\OrderCanceled;
use Fleetbase\FleetOps\Notifications\OrderCompleted;
use Fleetbase\FleetOps\Notifications\OrderDispatchFailed;
use Fleetbase\FleetOps\Notifications\OrderFailed;
use Fleetbase\Models\Company;
use Fleetbase\Models\Invite;
use Fleetbase\Models\User;
use Fleetbase\Models\VerificationCode;
use Fleetbase\Notifications\UserAcceptedCompanyInvite;
use Fleetbase\Notifications\UserCreated;
use Fleetbase\Notifications\UserEmailChange;
use Fleetbase\Notifications\UserForgotPassword;
use Fleetbase\Notifications\UserInvited;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;

// notificação real sem o construtor (que pede modelos do banco), com as propriedades públicas preenchidas
function notificacao(string $classe, array $propriedades)
{
    $n = (new ReflectionClass($classe))->newInstanceWithoutConstructor();
    foreach ($propriedades as $nome => $valor) {
        $n->{$nome} = $valor;
    }

    return $n;
}

function usuario(string $nome, string $email, string $tipo = 'user'): User
{
    $u        = new User();
    $u->name  = $nome;
    $u->email = $email;
    $u->type  = $tipo;

    return $u;
}

function codigoDeVerificacao(string $codigo, string $uuid, $usuario = null, array $meta = []): VerificationCode
{
    $c          = new VerificationCode();
    $c->code    = $codigo;
    $c->uuid    = $uuid;
    $c->subject = $usuario;
    $c->meta    = $meta;

    return $c;
}

function empresa(string $nome): Company
{
    $c       = new Company();
    $c->name = $nome;

    return $c;
}

function pedido(string $rastreio, ?string $loja = null): \Teste\PedidoDoEmail
{
    $p                 = new \Teste\PedidoDoEmail();
    $p->public_id      = 'order_abc';
    $p->trackingNumber = (object) ['tracking_number' => $rastreio];
    $p->customer       = $loja === null ? null : (object) ['name' => $loja];

    return $p;
}

// o resumo do que o leitor de e-mail mostra: assunto, título, linhas antes e depois do botão, botão
function resumoDoEmail(?MailMessage $m): ?array
{
    return $m === null ? null : [
        'assunto' => $m->subject,
        'titulo'  => $m->greeting,
        'antes'   => $m->introLines,
        'botao'   => $m->actionText,
        'url'     => $m->actionUrl,
        'depois'  => $m->outroLines,
    ];
}

function trackOrder(string $rastreio): MailMessage
{
    return (new MailMessage())->subject('Order ' . $rastreio)->line('english')->action('Track Order', 'https://entregas.restaurantepro.com.br/track-order?order=' . $rastreio);
}

echo PHP_EOL . '== Catálogo: senha, convite e troca de e-mail' . PHP_EOL;

$ana        = usuario('Ana', 'ana@exemplo.com');
$codigoAna  = codigoDeVerificacao('482915', 'vc-1', $ana);
$esqueci    = notificacao(UserForgotPassword::class, ['verificationCode' => $codigoAna, 'url' => 'https://entregas.restaurantepro.com.br/auth/reset-password/vc-1?code=482915']);
confere(resumoDoEmail(EmailsEmPortugues::traduzir($esqueci, $ana, $esqueci->toMail($ana))) === [
    'assunto' => 'Redefina sua senha do Entregas RestaurantePro',
    'titulo'  => 'Redefinir sua senha',
    'antes'   => ['Olá, Ana! Recebemos um pedido para redefinir a senha da sua conta. Toque no botão para criar uma nova.'],
    'botao'   => 'Criar nova senha',
    'url'     => 'https://entregas.restaurantepro.com.br/auth/reset-password/vc-1?code=482915',
    'depois'  => ['Se a página pedir, use o código: 482915', 'Se não foi você, ignore este e-mail: sua senha continua a mesma.'],
], 'esqueci a senha (console)');

$loja = usuario('Terraço', 'loja@exemplo.com', 'customer');
confere(EmailsEmPortugues::traduzir($esqueci, $loja, $esqueci->toMail($loja))->actionUrl === 'https://entregas.restaurantepro.com.br/customer-portal/auth/reset-password/vc-1?code=482915', 'esqueci a senha (loja): link do portal');

$convite          = new Invite();
$convite->code    = 'X7K2Q9';
$convidou         = notificacao(UserInvited::class, ['invite' => $convite, 'company' => empresa('Central Entregas'), 'sender' => usuario('Edgard', 'edgard@exemplo.com'), 'url' => 'https://entregas.restaurantepro.com.br/join/org/abc']);
$bruno            = usuario('Bruno', 'bruno@exemplo.com');
confere(resumoDoEmail(EmailsEmPortugues::traduzir($convidou, $bruno, $convidou->toMail($bruno))) === [
    'assunto' => 'Você foi convidado para a equipe Central Entregas',
    'titulo'  => 'Você foi convidado!',
    'antes'   => ['Olá, Bruno! Edgard convidou você para a equipe Central Entregas no Entregas RestaurantePro.'],
    'botao'   => 'Aceitar convite',
    'url'     => 'https://entregas.restaurantepro.com.br/join/org/abc',
    'depois'  => ['Código do convite: X7K2Q9'],
], 'convite');

$semRemetente = notificacao(UserInvited::class, ['invite' => $convite, 'company' => empresa('Central Entregas'), 'url' => 'https://entregas.restaurantepro.com.br/join/org/abc']);
confere(EmailsEmPortugues::traduzir($semRemetente, $bruno, new MailMessage())->introLines === ['Olá, Bruno! Você foi convidado para a equipe Central Entregas no Entregas RestaurantePro.'], 'convite sem quem convidou');

$troca = notificacao(UserEmailChange::class, [
    'verificationCode' => codigoDeVerificacao('731904', 'vc-2', $ana, ['old_email' => 'ana@antigo.com', 'new_email' => 'ana@novo.com']),
    'url'              => 'https://entregas.restaurantepro.com.br/auth/confirm-email-change/vc-2?code=731904',
]);
confere(resumoDoEmail(EmailsEmPortugues::traduzir($troca, $ana, $troca->toMail($ana))) === [
    'assunto' => 'Confirme seu novo e-mail',
    'titulo'  => 'Confirme seu novo e-mail',
    'antes'   => ['Olá, Ana! Foi pedida a troca do e-mail de login da sua conta.', 'E-mail atual: ana@antigo.com', 'Novo e-mail: ana@novo.com'],
    'botao'   => 'Confirmar troca de e-mail',
    'url'     => 'https://entregas.restaurantepro.com.br/auth/confirm-email-change/vc-2?code=731904',
    'depois'  => ['Se o botão não abrir, use o código: 731904', 'Se não foi você, ignore este e-mail: o e-mail da conta não muda.'],
], 'troca de e-mail');

echo PHP_EOL . '== Catálogo: avisos opcionais da equipe' . PHP_EOL;

$novo = notificacao(UserCreated::class, ['user' => usuario('Bruno', 'bruno@exemplo.com'), 'company' => empresa('Central Entregas')]);
confere(resumoDoEmail(EmailsEmPortugues::traduzir($novo, $ana, new MailMessage())) === [
    'assunto' => 'Novo usuário na equipe Central Entregas',
    'titulo'  => 'Novo usuário na equipe',
    'antes'   => ['Bruno (bruno@exemplo.com) entrou na equipe Central Entregas.'],
    'botao'   => 'Ver usuários',
    'url'     => 'https://entregas.restaurantepro.com.br/iam/users',
    'depois'  => [],
], 'novo usuário');

$aceito = notificacao(UserAcceptedCompanyInvite::class, ['user' => usuario('Bruno', 'bruno@exemplo.com'), 'company' => empresa('Central Entregas')]);
confere(resumoDoEmail(EmailsEmPortugues::traduzir($aceito, $ana, new MailMessage())) === [
    'assunto' => 'Bruno aceitou o convite para a equipe Central Entregas',
    'titulo'  => 'Convite aceito',
    'antes'   => ['Bruno agora faz parte da equipe Central Entregas.'],
    'botao'   => 'Ver a equipe',
    'url'     => 'https://entregas.restaurantepro.com.br/iam/users',
    'depois'  => [],
], 'convite aceito');

echo PHP_EOL . '== Catálogo: pedidos' . PHP_EOL;

$falha = notificacao(OrderDispatchFailed::class, ['order' => pedido('ABC123', 'Terraço Pizza Bar'), 'reason' => 'No driver assigned for order to dispatch to.']);
confere(resumoDoEmail(EmailsEmPortugues::traduzir($falha, $ana, trackOrder('ABC123'))) === [
    'assunto' => 'Pedido ABC123 não foi despachado',
    'titulo'  => 'O pedido não foi despachado',
    'antes'   => ['O pedido ABC123 da loja Terraço Pizza Bar não pôde ser despachado.', 'Motivo: nenhum motoboy atribuído ao pedido.'],
    'botao'   => 'Acompanhar o pedido',
    'url'     => 'https://entregas.restaurantepro.com.br/track-order?order=ABC123',
    'depois'  => [],
], 'falha no despacho');

$avisado = notificacao(OrderDispatchFailed::class, ['order' => pedido('ABC123'), 'reason' => 'Order was dispatched, but driver was unable to be notified.']);
confere(EmailsEmPortugues::traduzir($avisado, $ana, trackOrder('ABC123'))->introLines === ['O pedido ABC123 não pôde ser despachado.', 'Motivo: o pedido foi despachado, mas o motoboy não pôde ser avisado.'], 'falha no despacho: sem loja e o outro motivo');

Log::$registros = [];
$estranho       = notificacao(OrderDispatchFailed::class, ['order' => pedido('ABC123'), 'reason' => 'Something else']);
confere(EmailsEmPortugues::traduzir($estranho, $ana, trackOrder('ABC123'))->introLines[1] === 'Motivo: não informado.', 'motivo desconhecido vira "não informado"');
confere((Log::$registros[0][1] ?? null) === '[entregas] motivo de falha de despacho sem tradução' && (Log::$registros[0][2]['motivo'] ?? null) === 'Something else', 'motivo desconhecido vai para o log');

$semRastreio                 = pedido('');
$semRastreio->trackingNumber = null;
$porId                       = notificacao(OrderDispatchFailed::class, ['order' => $semRastreio, 'reason' => 'No driver assigned for order to dispatch to.']);
confere(EmailsEmPortugues::traduzir($porId, $ana, new MailMessage())->subject === 'Pedido order_abc não foi despachado', 'sem rastreio usa o public_id');
confere(EmailsEmPortugues::traduzir($porId, $ana, new MailMessage())->actionText === null, 'sem URL na original, sem botão');

$entregue = notificacao(OrderCompleted::class, ['order' => pedido('RP-1')]);
confere(resumoDoEmail(EmailsEmPortugues::traduzir($entregue, $ana, trackOrder('RP-1'))) === [
    'assunto' => 'Pedido RP-1 entregue',
    'titulo'  => 'Pedido entregue',
    'antes'   => ['O pedido RP-1 foi entregue.'],
    'botao'   => 'Acompanhar o pedido',
    'url'     => 'https://entregas.restaurantepro.com.br/track-order?order=RP-1',
    'depois'  => [],
], 'pedido entregue');

$falhou = notificacao(OrderFailed::class, ['order' => pedido('RP-1'), 'reason' => 'Cliente ausente']);
confere(EmailsEmPortugues::traduzir($falhou, $ana, trackOrder('RP-1'))->introLines === ['A entrega do pedido RP-1 falhou.', 'Motivo: Cliente ausente'], 'pedido com falha, com motivo');
confere(EmailsEmPortugues::traduzir($falhou, $ana, trackOrder('RP-1'))->subject === 'Pedido RP-1 com falha na entrega', 'assunto do pedido com falha');

$cancelado = notificacao(OrderCanceled::class, ['order' => pedido('RP-1')]);
confere(EmailsEmPortugues::traduzir($cancelado, $ana, trackOrder('RP-1'))->introLines === ['O pedido RP-1 foi cancelado.'], 'pedido cancelado sem motivo');
confere(EmailsEmPortugues::traduzir($cancelado, $ana, trackOrder('RP-1'))->subject === 'Pedido RP-1 cancelado', 'assunto do pedido cancelado');

echo PHP_EOL . '== Catálogo: desconhecido e falha de leitura' . PHP_EOL;

$inventada = new class extends \Illuminate\Notifications\Notification {};
confere(!EmailsEmPortugues::conhece($inventada) && EmailsEmPortugues::traduzir($inventada, $ana, new MailMessage()) === null, 'notificação desconhecida: null');

Log::$registros = [];
$quebrada       = notificacao(UserForgotPassword::class, ['url' => 'x']); // sem verificationCode
confere(EmailsEmPortugues::conhece($quebrada) && EmailsEmPortugues::traduzir($quebrada, $ana, new MailMessage()) === null, 'leitura que falha: null');
confere((Log::$registros[0][1] ?? null) === '[entregas] falha ao montar o e-mail em pt-BR', 'leitura que falha vai para o log');
```

- [ ] **Passo 2: rodar e ver falhar.** Esperado: erro `Class "App\Notifications\Entregas\Email\EmailsEmPortugues" not found`.

- [ ] **Passo 3: criar `api/app/Notifications/Entregas/Email/EmailsEmPortugues.php`**

```php
<?php

namespace App\Notifications\Entregas\Email;

use Fleetbase\Support\Utils;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: assunto e texto em pt-BR das notificações por e-mail dos pacotes (core-api 1.6.61 e
 * fleetops-api 0.6.65), que vêm fixos em inglês. Usado pelo CanalEmailEntregas; textos no desenho
 * docs/superpowers/specs/2026-10-04-emails-em-portugues-design.md.
 *
 * Cada entrada lê só propriedades públicas da notificação, o notifiable e a URL do botão da mensagem original. Se a
 * leitura falhar (o pacote mudou), devolve null, registra "[entregas] falha ao montar o e-mail em pt-BR" e o e-mail sai
 * como veio. Ao atualizar os pacotes, confira as classes de ENTRADAS e as propriedades lidas em cada método.
 */
class EmailsEmPortugues
{
    /** classe da notificação => método que monta o e-mail */
    private const ENTRADAS = [
        'Fleetbase\Notifications\UserForgotPassword'           => 'esqueciASenha',
        'Fleetbase\Notifications\UserInvited'                  => 'convite',
        'Fleetbase\Notifications\UserEmailChange'              => 'trocaDeEmail',
        'Fleetbase\Notifications\UserCreated'                  => 'novoUsuario',
        'Fleetbase\Notifications\UserAcceptedCompanyInvite'    => 'conviteAceito',
        'Fleetbase\FleetOps\Notifications\OrderDispatchFailed' => 'falhaNoDespacho',
        'Fleetbase\FleetOps\Notifications\OrderCompleted'      => 'pedidoEntregue',
        'Fleetbase\FleetOps\Notifications\OrderFailed'         => 'pedidoComFalha',
        'Fleetbase\FleetOps\Notifications\OrderCanceled'       => 'pedidoCancelado',
    ];

    /** motivos de falha de despacho do Fleet-Ops (HandleOrderDispatched) */
    private const MOTIVOS_DE_DESPACHO = [
        'No driver assigned for order to dispatch to.'                => 'nenhum motoboy atribuído ao pedido',
        'Order was dispatched, but driver was unable to be notified.' => 'o pedido foi despachado, mas o motoboy não pôde ser avisado',
    ];

    public static function conhece(object $notificacao): bool
    {
        return isset(self::ENTRADAS[get_class($notificacao)]);
    }

    public static function traduzir(object $notificacao, $notifiable, MailMessage $original): ?MailMessage
    {
        $metodo = self::ENTRADAS[get_class($notificacao)] ?? null;
        if ($metodo === null) {
            return null;
        }

        try {
            return self::$metodo($notificacao, $notifiable, $original);
        } catch (\Throwable $erro) {
            Log::warning('[entregas] falha ao montar o e-mail em pt-BR', ['aviso' => get_class($notificacao), 'erro' => $erro->getMessage()]);

            return null;
        }
    }

    private static function esqueciASenha($n, $notifiable, MailMessage $original): MailMessage
    {
        $codigo = $n->verificationCode->code;
        // a loja (usuário customer) redefine a senha no portal, não no console
        $url = ($notifiable->type ?? null) === 'customer'
            ? Utils::consoleUrl('customer-portal/auth/reset-password/' . $n->verificationCode->uuid, ['code' => $codigo])
            : $n->url;

        return (new MailMessage())
            ->subject('Redefina sua senha do Entregas RestaurantePro')
            ->greeting('Redefinir sua senha')
            ->line(Saudacao::para($notifiable->name ?? null) . ' Recebemos um pedido para redefinir a senha da sua conta. Toque no botão para criar uma nova.')
            ->action('Criar nova senha', $url)
            ->line('Se a página pedir, use o código: ' . $codigo)
            ->line('Se não foi você, ignore este e-mail: sua senha continua a mesma.');
    }

    private static function convite($n, $notifiable, MailMessage $original): MailMessage
    {
        $empresa = Utils::delinkify($n->company->name);
        $quem    = trim(Utils::delinkify($n->sender->name ?? null));
        $texto   = $quem !== ''
            ? "{$quem} convidou você para a equipe {$empresa} no Entregas RestaurantePro."
            : "Você foi convidado para a equipe {$empresa} no Entregas RestaurantePro.";

        return (new MailMessage())
            ->subject("Você foi convidado para a equipe {$empresa}")
            ->greeting('Você foi convidado!')
            ->line(Saudacao::para($notifiable->name ?? null) . ' ' . $texto)
            ->action('Aceitar convite', $n->url)
            ->line('Código do convite: ' . $n->invite->code);
    }

    private static function trocaDeEmail($n, $notifiable, MailMessage $original): MailMessage
    {
        $codigo  = $n->verificationCode;
        $usuario = $codigo->subject;

        return (new MailMessage())
            ->subject('Confirme seu novo e-mail')
            ->greeting('Confirme seu novo e-mail')
            ->line(Saudacao::para($usuario->name ?? null) . ' Foi pedida a troca do e-mail de login da sua conta.')
            ->line('E-mail atual: ' . (data_get($codigo->meta, 'old_email') ?: '—'))
            ->line('Novo e-mail: ' . (data_get($codigo->meta, 'new_email') ?: '—'))
            ->action('Confirmar troca de e-mail', $n->url)
            ->line('Se o botão não abrir, use o código: ' . $codigo->code)
            ->line('Se não foi você, ignore este e-mail: o e-mail da conta não muda.');
    }

    private static function novoUsuario($n, $notifiable, MailMessage $original): MailMessage
    {
        $nome    = Utils::delinkify($n->user->name);
        $email   = Utils::delinkify($n->user->email);
        $empresa = Utils::delinkify($n->company->name ?? null);

        return (new MailMessage())
            ->subject(trim("Novo usuário na equipe {$empresa}"))
            ->greeting('Novo usuário na equipe')
            ->line(trim("{$nome} ({$email}) entrou na equipe {$empresa}") . '.')
            ->action('Ver usuários', Utils::consoleUrl('iam/users'));
    }

    private static function conviteAceito($n, $notifiable, MailMessage $original): MailMessage
    {
        $nome    = Utils::delinkify($n->user->name);
        $empresa = Utils::delinkify($n->company->name);

        return (new MailMessage())
            ->subject("{$nome} aceitou o convite para a equipe {$empresa}")
            ->greeting('Convite aceito')
            ->line("{$nome} agora faz parte da equipe {$empresa}.")
            ->action('Ver a equipe', Utils::consoleUrl('iam/users'));
    }

    private static function falhaNoDespacho($n, $notifiable, MailMessage $original): MailMessage
    {
        $codigo   = self::codigoDoPedido($n->order);
        $loja     = self::nomeDaLoja($n->order);
        $motivoOriginal = trim((string) ($n->reason ?? ''));
        $motivo   = self::MOTIVOS_DE_DESPACHO[$motivoOriginal] ?? null;
        if ($motivo === null && $motivoOriginal !== '') {
            Log::info('[entregas] motivo de falha de despacho sem tradução', ['motivo' => $motivoOriginal]);
        }

        $mensagem = (new MailMessage())
            ->subject("Pedido {$codigo} não foi despachado")
            ->greeting('O pedido não foi despachado')
            ->line($loja !== '' ? "O pedido {$codigo} da loja {$loja} não pôde ser despachado." : "O pedido {$codigo} não pôde ser despachado.")
            ->line('Motivo: ' . ($motivo ?? 'não informado') . '.');

        return self::comBotao($mensagem, 'Acompanhar o pedido', $original->actionUrl);
    }

    private static function pedidoEntregue($n, $notifiable, MailMessage $original): MailMessage
    {
        $codigo = self::codigoDoPedido($n->order);

        return self::comBotao(
            (new MailMessage())->subject("Pedido {$codigo} entregue")->greeting('Pedido entregue')->line("O pedido {$codigo} foi entregue."),
            'Acompanhar o pedido',
            $original->actionUrl
        );
    }

    private static function pedidoComFalha($n, $notifiable, MailMessage $original): MailMessage
    {
        $codigo   = self::codigoDoPedido($n->order);
        $mensagem = (new MailMessage())->subject("Pedido {$codigo} com falha na entrega")->greeting('Falha na entrega')->line("A entrega do pedido {$codigo} falhou.");

        return self::comBotao(self::comMotivo($mensagem, $n->reason ?? null), 'Acompanhar o pedido', $original->actionUrl);
    }

    private static function pedidoCancelado($n, $notifiable, MailMessage $original): MailMessage
    {
        $codigo   = self::codigoDoPedido($n->order);
        $mensagem = (new MailMessage())->subject("Pedido {$codigo} cancelado")->greeting('Pedido cancelado')->line("O pedido {$codigo} foi cancelado.");

        return self::comBotao(self::comMotivo($mensagem, $n->reason ?? null), 'Acompanhar o pedido', $original->actionUrl);
    }

    /** número de rastreio do pedido; sem ele, o public_id */
    private static function codigoDoPedido($pedido): string
    {
        $rastreio = $pedido->trackingNumber->tracking_number ?? $pedido->tracking ?? null;

        return (string) ($rastreio ?: ($pedido->public_id ?? ''));
    }

    /** a loja do pedido é o Vendor cliente (orders.customer_uuid); sem cliente, vazio */
    private static function nomeDaLoja($pedido): string
    {
        return trim(Utils::delinkify($pedido->customer->name ?? null));
    }

    private static function comMotivo(MailMessage $mensagem, ?string $motivo): MailMessage
    {
        $motivo = trim((string) $motivo);

        return $motivo === '' ? $mensagem : $mensagem->line('Motivo: ' . $motivo);
    }

    private static function comBotao(MailMessage $mensagem, string $texto, ?string $url): MailMessage
    {
        return $url ? $mensagem->action($texto, $url) : $mensagem;
    }
}
```

- [ ] **Passo 4: rodar e ver passar.** Esperado: só `PASSA` e `FALHAS: 0`.

Se algum caso falhar porque o `toMail()` real usa algo que o stub não tem (por exemplo, `config()` com outra chave),
ajuste o **stub** e nunca o catálogo nem o arquivo real do pacote.

- [ ] **Passo 5: sintaxe e commit**

```bash
P="C:/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/5e9fefbb-16d5-4968-835a-1df8e42aadad/scratchpad/phpwasm"
PHP_WASM_DIR="$P" node scripts/teste-php/sintaxe.mjs api/app/Notifications/Entregas/Email/*.php
git add api/app/Notifications/Entregas/Email/EmailsEmPortugues.php scripts/teste-php/emails.php
git commit -m "E-mails: catálogo das notificações em pt-BR (senha, convite, troca de e-mail, pedidos)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Tarefa 4: canal de e-mail (`CanalEmailEntregas`)

**Arquivos:**
- Criar: `api/app/Notifications/Entregas/Email/CanalEmailEntregas.php`
- Modificar: `api/app/Providers/AppServiceProvider.php` (método `register()`, logo depois do bind do `FcmChannel`)
- Modificar: `scripts/teste-php/emails.php`

- [ ] **Passo 1: escrever o teste.** Antes do `resumo();`:

```php
use App\Notifications\Entregas\Email\CanalEmailEntregas;
use Fleetbase\FleetOps\Models\Driver;

echo PHP_EOL . '== Canal de e-mail' . PHP_EOL;

$carteiro = new \Teste\CarteiroFalso();
$canal    = new CanalEmailEntregas($carteiro, null);

// motoboy (Driver) não recebe e-mail de pedido
Log::$registros = [];
$motoboy            = new Driver();
$motoboy->public_id = 'driver_1';
confere($canal->send($motoboy, $entregue) === null && $carteiro->enviados === [], 'motoboy: e-mail de pedido não sai');
confere((Log::$registros[0][1] ?? null) === '[entregas] e-mail ao motoboy não enviado' && (Log::$registros[0][2]['motoboy'] ?? null) === 'driver_1', 'motoboy: corte no log');

// usuário do tipo motoboy não recebe o convite de organização
$usuarioMotoboy = usuario('Carlos', 'carlos@exemplo.com', 'driver');
confere($canal->send($usuarioMotoboy, $convidou) === null && $carteiro->enviados === [], 'convite para usuário motoboy não sai');

// notificação conhecida sai em pt-BR, com os dados da mensagem e o nome da notificação
confere($canal->send($ana, $esqueci) === 'enviado', 'esqueci a senha sai');
$dados = $carteiro->enviados[0]['dados'] ?? [];
confere(($dados['subject'] ?? null) === 'Redefina sua senha do Entregas RestaurantePro' && ($dados['actionText'] ?? null) === 'Criar nova senha', 'sai com assunto e botão em pt-BR');
confere(($dados['__laravel_notification'] ?? null) === UserForgotPassword::class, 'mantém o nome da notificação nos dados');

// usuário de loja: link do portal também pelo canal
$carteiro->enviados = [];
$canal->send($loja, $esqueci);
confere(str_contains($carteiro->enviados[0]['dados']['actionUrl'] ?? '', '/customer-portal/auth/reset-password/vc-1'), 'loja: link do portal pelo canal');

// desconhecida: sai como veio, com aviso no log
$carteiro->enviados = [];
Log::$registros     = [];
$semTraducao        = new class extends \Illuminate\Notifications\Notification {
    public function toMail($notifiable) { return (new MailMessage())->subject('Original in English')->line('english'); }
};
confere($canal->send($ana, $semTraducao) === 'enviado' && ($carteiro->enviados[0]['dados']['subject'] ?? null) === 'Original in English', 'desconhecida sai como veio');
confere((Log::$registros[0][1] ?? null) === '[entregas] e-mail sem tradução', 'desconhecida vai para o log');

// sem endereço de e-mail: não envia (como o MailChannel do Laravel)
$carteiro->enviados = [];
$semEmail           = usuario('Sem', '');
confere($canal->send($semEmail, $esqueci) === null && $carteiro->enviados === [], 'sem e-mail: não envia');

// a configuração de envio da original (remetente, mailer, etiquetas) passa para a mensagem traduzida
confere(CanalEmailEntregas::copiarEnvio((new MailMessage())->from('central@exemplo.com', 'Central')->mailer('smtp')->tag('senha'), new MailMessage())->from === ['central@exemplo.com', 'Central'], 'copia o remetente da original');
```

- [ ] **Passo 2: rodar e ver falhar.** Esperado: `Class "App\Notifications\Entregas\Email\CanalEmailEntregas" not found`.

- [ ] **Passo 3: criar `api/app/Notifications/Entregas/Email/CanalEmailEntregas.php`**

```php
<?php

namespace App\Notifications\Entregas\Email;

use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: canal de e-mail no lugar do MailChannel do Laravel (bind no AppServiceProvider; o
 * ChannelManager o resolve pelo container). Vale para as notificações do core-api, do Fleet-Ops e do portal.
 *
 * - Motoboy não recebe e-mail: Driver (pedido atribuído, despachado, cancelado...) e o convite de organização para
 *   usuário do tipo driver. Ele já recebe push e entra pelo app; o código de login por e-mail é Mailable, não passa aqui.
 * - Notificação conhecida sai com assunto e texto em pt-BR (EmailsEmPortugues); desconhecida sai como veio, já no
 *   layout novo (views em api/resources/views/vendor), e aparece no log como "[entregas] e-mail sem tradução".
 *
 * O send() repete o do MailChannel do Laravel 10 (commit 74e222ce) e só troca a MailMessage. Ao atualizar o Laravel,
 * confira o send() do pai e os métodos usados (buildView, additionalMessageData, messageBuilder). Ao atualizar o
 * core-api ou o fleetops-api, confira também as views sobrescritas (fleetbase::layout.mail, fleetbase::mail.verification,
 * fleetbase::mail.user-credentials, fleetbase::mail.test), o alias mail-layout, as variáveis delas (code, type, user,
 * plaintextPassword, mailer, currentHour) e as classes e propriedades do EmailsEmPortugues.
 */
class CanalEmailEntregas extends MailChannel
{
    /** configuração de envio da mensagem original que passa para a traduzida */
    private const ENVIO = ['mailer', 'from', 'replyTo', 'cc', 'bcc', 'attachments', 'rawAttachments', 'tags', 'metadata', 'priority', 'callbacks', 'theme', 'level'];

    public function send($notifiable, Notification $notification)
    {
        if (self::paraMotoboy($notifiable, $notification)) {
            Log::info('[entregas] e-mail ao motoboy não enviado', ['aviso' => get_class($notification), 'motoboy' => $notifiable->public_id ?? null]);

            return null;
        }

        $message = $notification->toMail($notifiable);

        if (!$notifiable->routeNotificationFor('mail', $notification) && !$message instanceof Mailable) {
            return null;
        }

        if ($message instanceof Mailable) {
            return $message->send($this->mailer);
        }

        $message = $this->emPortugues($notifiable, $notification, $message);

        return $this->mailer->mailer($message->mailer ?? null)->send(
            $this->buildView($message),
            array_merge($message->data(), $this->additionalMessageData($notification)),
            $this->messageBuilder($notifiable, $notification, $message)
        );
    }

    public static function paraMotoboy($notifiable, $notification): bool
    {
        if ($notifiable instanceof \Fleetbase\FleetOps\Models\Driver) {
            return true;
        }

        return get_class($notification) === 'Fleetbase\Notifications\UserInvited' && ($notifiable->type ?? null) === 'driver';
    }

    /** copia remetente, mailer, anexos, etiquetas etc. da original para a traduzida */
    public static function copiarEnvio(MailMessage $original, MailMessage $traduzida): MailMessage
    {
        foreach (self::ENVIO as $propriedade) {
            $traduzida->{$propriedade} = $original->{$propriedade};
        }

        return $traduzida;
    }

    private function emPortugues($notifiable, Notification $notification, $original)
    {
        if (!$original instanceof MailMessage) {
            return $original;
        }

        if (!EmailsEmPortugues::conhece($notification)) {
            Log::warning('[entregas] e-mail sem tradução', ['aviso' => get_class($notification)]);

            return $original;
        }

        // falha ao montar: EmailsEmPortugues já registrou o motivo; sai a original
        $traduzida = EmailsEmPortugues::traduzir($notification, $notifiable, $original);

        return $traduzida === null ? $original : self::copiarEnvio($original, $traduzida);
    }
}
```

- [ ] **Passo 4: registrar o canal.** Em `api/app/Providers/AppServiceProvider.php`:

Adicione os imports em ordem alfabética:
```php
use App\Notifications\Entregas\Email\CanalEmailEntregas;
use Illuminate\Notifications\Channels\MailChannel;
```

No `register()`, logo depois da linha `$this->app->bind(FcmChannel::class, CanalFcmEntregas::class);`:
```php

        // Entregas: todo e-mail de notificação sai em pt-BR e o motoboy não recebe e-mail (ver CanalEmailEntregas)
        $this->app->bind(MailChannel::class, CanalEmailEntregas::class);
```

- [ ] **Passo 5: rodar, ver passar e conferir a sintaxe**

```bash
P="C:/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/5e9fefbb-16d5-4968-835a-1df8e42aadad/scratchpad/phpwasm"
PHP_WASM_DIR="$P" node scripts/teste-php/rodar.mjs scripts/teste-php/emails.php
PHP_WASM_DIR="$P" node scripts/teste-php/sintaxe.mjs api/app/Notifications/Entregas/Email/CanalEmailEntregas.php api/app/Providers/AppServiceProvider.php
```
Esperado: `FALHAS: 0` e `OK` nos dois arquivos.

- [ ] **Passo 6: commit**

```bash
git add api/app/Notifications/Entregas/Email/CanalEmailEntregas.php api/app/Providers/AppServiceProvider.php scripts/teste-php/emails.php
git commit -m "E-mails: canal próprio (pt-BR pelo catálogo, sem e-mail ao motoboy)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Tarefa 5: assunto dos Mailables (`MessageSending`)

**Arquivos:**
- Criar: `api/app/Listeners/Entregas/AssuntoDosEmailsEmPortugues.php`
- Modificar: `api/app/Providers/EventServiceProvider.php` (array `$listen`)
- Modificar: `scripts/teste-php/emails.php`

- [ ] **Passo 1: escrever o teste.** Antes do `resumo();`:

```php
use App\Listeners\Entregas\AssuntoDosEmailsEmPortugues;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Email;

echo PHP_EOL . '== Assunto dos Mailables' . PHP_EOL;

$escuta = new AssuntoDosEmailsEmPortugues();
// $data como o Laravel monta: dados da view (Mailable::buildViewData) + 'mailer'
$casos = [
    'verificação' => [['code' => '123456', 'type' => 'email_verification', 'mailer' => 'smtp'], '123456 is your Entregas verification code', '123456 é o seu código de verificação'],
    '2FA'         => [['code' => '123456', 'type' => '2fa', 'mailer' => 'smtp'], '123456 is your Entregas verification code', '123456 é o seu código de acesso'],
    'motoboy'     => [['code' => '123456', 'type' => 'driver_login', 'mailer' => 'smtp'], '123456 is your Entregas verification code', '123456 é o seu código para entrar no app'],
    'credenciais' => [['plaintextPassword' => 'x', 'user' => null, 'mailer' => 'smtp'], 'Your login credentials for Central on Entregas', 'Seus dados de acesso ao Entregas RestaurantePro'],
    'teste'       => [['mailSubject' => '🎉 Your Fleetbase Mail Configuration Works!', 'mailer' => 'smtp'], '🎉 Your Fleetbase Mail Configuration Works!', 'Teste de e-mail do Entregas RestaurantePro'],
    'notificação' => [['code' => '1', 'type' => '2fa', '__laravel_notification' => UserForgotPassword::class], 'Redefina sua senha do Entregas RestaurantePro', 'Redefina sua senha do Entregas RestaurantePro'],
    'outro'       => [['qualquer' => 1], 'Assunto qualquer', 'Assunto qualquer'],
];
foreach ($casos as $nome => [$dados, $antes, $depois]) {
    $email   = (new Email())->subject($antes);
    $retorno = $escuta->handle(new MessageSending($email, $dados));
    confere($email->getSubject() === $depois, "assunto: {$nome}");
    confere($retorno === null, "não cancela o envio: {$nome}");
}

Log::$registros = [];
$email          = (new Email())->subject('x');
$escuta->handle(new MessageSending($email, ['code' => '9', 'type' => 'tipo_inventado']));
confere($email->getSubject() === '9 é o seu código' && (Log::$registros[0][1] ?? null) === '[entregas] e-mail sem tradução', 'código de tipo desconhecido: assunto genérico e log');
```

- [ ] **Passo 2: rodar e ver falhar.** Esperado: `Class "App\Listeners\Entregas\AssuntoDosEmailsEmPortugues" not found`.

- [ ] **Passo 3: criar `api/app/Listeners/Entregas/AssuntoDosEmailsEmPortugues.php`**

```php
<?php

namespace App\Listeners\Entregas;

use App\Notifications\Entregas\Email\CodigosPorEmail;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: assunto em pt-BR dos Mailables do core-api (VerificationMail, UserCredentialsMail e TestMail),
 * que fixam o assunto em inglês no PHP. O corpo deles vem das views sobrescritas (vendor/fleetbase/mail). As
 * notificações não passam aqui: já saem em pt-BR pelo CanalEmailEntregas (têm __laravel_notification nos dados).
 *
 * O Mailable é reconhecido pelos dados da view (Mailable::buildViewData):
 * - VerificationMail: code e type;
 * - UserCredentialsMail: plaintextPassword;
 * - TestMail: mailSubject (propriedade pública).
 * Nunca devolve false: o Mailer usa events->until() e false cancelaria o envio.
 */
class AssuntoDosEmailsEmPortugues
{
    public function handle(MessageSending $evento)
    {
        $dados = $evento->data;
        if (isset($dados['__laravel_notification'])) {
            return null;
        }

        try {
            $assunto = $this->assunto($dados);
            if ($assunto !== null) {
                $evento->message->subject($assunto);
            }
        } catch (\Throwable $erro) {
            Log::warning('[entregas] falha ao traduzir o assunto do e-mail', ['erro' => $erro->getMessage()]);
        }

        return null;
    }

    private function assunto(array $dados): ?string
    {
        if (isset($dados['code'], $dados['type'])) {
            if (!CodigosPorEmail::conhece($dados['type'])) {
                Log::warning('[entregas] e-mail sem tradução', ['aviso' => 'VerificationMail', 'tipo' => $dados['type']]);
            }

            return CodigosPorEmail::assunto($dados['type'], (string) $dados['code']);
        }

        if (array_key_exists('plaintextPassword', $dados)) {
            return 'Seus dados de acesso ao Entregas RestaurantePro';
        }

        if (isset($dados['mailSubject'])) {
            return 'Teste de e-mail do Entregas RestaurantePro';
        }

        return null;
    }
}
```

- [ ] **Passo 4: registrar.** Em `api/app/Providers/EventServiceProvider.php`, adicione os imports:
```php
use App\Listeners\Entregas\AssuntoDosEmailsEmPortugues;
use Illuminate\Mail\Events\MessageSending;
```
E no `$listen`, depois do bloco do `Registered::class`:
```php
        // Entregas: assunto em pt-BR dos e-mails de código, credenciais e teste do core-api
        MessageSending::class => [
            AssuntoDosEmailsEmPortugues::class,
        ],
```

- [ ] **Passo 5: rodar, ver passar e conferir a sintaxe**

```bash
PHP_WASM_DIR="$P" node scripts/teste-php/rodar.mjs scripts/teste-php/emails.php
PHP_WASM_DIR="$P" node scripts/teste-php/sintaxe.mjs api/app/Listeners/Entregas/AssuntoDosEmailsEmPortugues.php api/app/Providers/EventServiceProvider.php
```
Esperado: `FALHAS: 0` e `OK`.

- [ ] **Passo 6: commit**

```bash
git add api/app/Listeners/Entregas/AssuntoDosEmailsEmPortugues.php api/app/Providers/EventServiceProvider.php scripts/teste-php/emails.php
git commit -m "E-mails: assunto em pt-BR dos e-mails de código, credenciais e teste

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Tarefa 6: layout (estilo C) e corpo das notificações

**Arquivos:**
- Criar: `api/app/Notifications/Entregas/Email/MarcaDoEmail.php`
- Criar: `api/resources/views/vendor/mail/html/message.blade.php`, `html/codigo.blade.php` e `html/themes/default.css`
- Criar: `api/resources/views/vendor/mail/text/message.blade.php` e `text/codigo.blade.php`
- Criar: `api/resources/views/vendor/notifications/email.blade.php`
- Criar: `api/resources/views/vendor/fleetbase/layout/mail.blade.php`
- Criar: `scripts/teste-emails-views.mjs`

**Regra das views de markdown** (`notifications/email`, `fleetbase/mail/*`): o conteúdo vira Markdown (CommonMark). Uma
linha com 4 espaços de recuo vira bloco de código. Escreva o conteúdo e os componentes `<x-mail::...>` **sem recuo**,
como nas views do Laravel.

- [ ] **Passo 1: escrever a checagem das views, que deve falhar.** Crie `scripts/teste-emails-views.mjs`:

```js
#!/usr/bin/env node
// Checagem estática das views de e-mail do Entregas RestaurantePro (api/resources/views/vendor): existem, não têm texto
// em inglês das views originais nem "Fleetbase", e as views de markdown não têm linha recuada (vira bloco de código).
// Uso, da raiz do repo: node scripts/teste-emails-views.mjs
import fs from 'node:fs';
import path from 'node:path';

const base = 'api/resources/views/vendor';
const views = {
    'mail/html/message.blade.php': false,
    'mail/html/codigo.blade.php': false,
    'mail/html/themes/default.css': false,
    'mail/text/message.blade.php': false,
    'mail/text/codigo.blade.php': false,
    'notifications/email.blade.php': true,
    'fleetbase/layout/mail.blade.php': true,
    'fleetbase/mail/verification.blade.php': true,
    'fleetbase/mail/user-credentials.blade.php': true,
    'fleetbase/mail/test.blade.php': true,
};
const proibidos = ['Fleetbase', 'Regards', 'Hello', 'Whoops', 'All rights reserved', 'All Rights Reserved', 'Good Morning', 'Verify Email', "If you're having trouble", 'Your verification code', 'Your login credentials'];
// arquivo => textos que têm de aparecer
const obrigatorios = {
    'mail/html/message.blade.php': ['MarcaDoEmail::logo()', 'Entregas RestaurantePro · e-mail automático, não responda.'],
    'mail/text/message.blade.php': ['Entregas RestaurantePro · e-mail automático, não responda.'],
    'notifications/email.blade.php': ['Se o botão', 'align="left"'],
    'fleetbase/mail/verification.blade.php': ['CodigosPorEmail::textos', '<x-mail::codigo>'],
    'fleetbase/mail/user-credentials.blade.php': ['Seus dados de acesso', '$plaintextPassword'],
    'fleetbase/mail/test.blade.php': ['O envio de e-mail está funcionando'],
};

let falhas = 0;
const confere = (ok, descricao) => {
    if (!ok) falhas++;
    console.log(`${ok ? 'PASSA' : 'FALHA'} ${descricao}`);
};

for (const [arquivo, markdown] of Object.entries(views)) {
    const caminho = path.join(base, arquivo);
    const existe = fs.existsSync(caminho);
    confere(existe, `${arquivo} existe`);
    if (!existe) continue;
    const texto = fs.readFileSync(caminho, 'utf8');
    for (const p of proibidos) confere(!texto.includes(p), `${arquivo} sem "${p}"`);
    for (const o of obrigatorios[arquivo] ?? []) confere(texto.includes(o), `${arquivo} contém "${o}"`);
    if (markdown) {
        const recuadas = texto.split(/\r?\n/).filter((l) => /^( {4}|\t)/.test(l) && !/^\s*(\{\{--|@php|@endphp|\$)/.test(l));
        confere(recuadas.length === 0, `${arquivo} sem linha recuada (${recuadas.length})`);
    }
}
console.log(`\nFALHAS: ${falhas}`);
process.exit(falhas ? 1 : 0);
```

Rode `node scripts/teste-emails-views.mjs`. Esperado: várias `FALHA ... existe` e saída 1.

- [ ] **Passo 2: criar `api/app/Notifications/Entregas/Email/MarcaDoEmail.php`**

```php
<?php

namespace App\Notifications\Entregas\Email;

/**
 * Entregas RestaurantePro: logo e link do cabeçalho dos e-mails (vendor/mail/html/message.blade.php). O logo vem de
 * Admin → Marca (Setting::getBrandingLogoUrl do core-api); sem imagem lá, cai em config('fleetbase.branding.logo_url'),
 * que o api/config/fleetbase.php aponta para o logo do RestaurantePro.
 */
class MarcaDoEmail
{
    public static function logo(): string
    {
        try {
            if (class_exists(\Fleetbase\Models\Setting::class)) {
                return (string) \Fleetbase\Models\Setting::getBrandingLogoUrl();
            }
        } catch (\Throwable $erro) {
            // sem banco (comando, teste): fica o logo da config
        }

        return (string) config('fleetbase.branding.logo_url');
    }

    public static function urlDoConsole(): string
    {
        try {
            if (class_exists(\Fleetbase\Support\Utils::class)) {
                return \Fleetbase\Support\Utils::consoleUrl();
            }
        } catch (\Throwable $erro) {
            // sem config do console: fica a URL da aplicação
        }

        return (string) config('app.url');
    }
}
```

- [ ] **Passo 3: criar `api/resources/views/vendor/mail/html/message.blade.php`**

```blade
{{-- Entregas RestaurantePro: layout dos e-mails (estilo C). Usado pelas notificações (notifications::email) e pelos
Mailables do core-api (vendor/fleetbase/layout/mail.blade.php). Estilos em themes/default.css. --}}
<x-mail::layout>
{{-- Cabeçalho --}}
<x-slot:header>
<x-mail::header :url="\App\Notifications\Entregas\Email\MarcaDoEmail::urlDoConsole()">
<img src="{{ \App\Notifications\Entregas\Email\MarcaDoEmail::logo() }}" class="logo" alt="Entregas RestaurantePro" height="40" style="height: 40px; width: auto; max-width: 240px;">
</x-mail::header>
</x-slot:header>

{{-- Corpo --}}
{{ $slot }}

{{-- Link do botão por extenso --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{{ $subcopy }}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Rodapé --}}
<x-slot:footer>
<x-mail::footer>
Entregas RestaurantePro · e-mail automático, não responda.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
```

- [ ] **Passo 4: criar `api/resources/views/vendor/mail/html/codigo.blade.php`**

```blade
<table class="codigo" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="codigo-valor">{{ $slot }}</td>
</tr>
</table>
```

- [ ] **Passo 5: criar `api/resources/views/vendor/mail/html/themes/default.css`** (o Laravel o embute nos elementos com
CssToInlineStyles)

```css
/* Entregas RestaurantePro: tema dos e-mails no estilo C (minimalista, como carta). */

body,
body *:not(html):not(style):not(br):not(tr):not(code) {
    box-sizing: border-box;
    font-family: Arial, Helvetica, sans-serif;
    position: relative;
}

body {
    -webkit-text-size-adjust: none;
    background-color: #ffffff;
    color: #374151;
    height: 100%;
    line-height: 1.5;
    margin: 0;
    padding: 0;
    width: 100% !important;
}

p,
ul,
ol,
blockquote {
    line-height: 1.5;
    text-align: left;
}

a {
    color: #111827;
}

a img {
    border: none;
}

h1 {
    color: #111827;
    font-size: 20px;
    font-weight: bold;
    margin-top: 0;
    text-align: left;
}

h2 {
    color: #111827;
    font-size: 16px;
    font-weight: bold;
    margin-top: 0;
    text-align: left;
}

h3 {
    color: #111827;
    font-size: 14px;
    font-weight: bold;
    margin-top: 0;
    text-align: left;
}

p {
    color: #374151;
    font-size: 15px;
    line-height: 1.5em;
    margin-top: 0;
    text-align: left;
}

p.sub {
    font-size: 12px;
}

img {
    max-width: 100%;
}

.wrapper {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    background-color: #ffffff;
    margin: 0;
    padding: 0;
    width: 100%;
}

.content {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 0;
    padding: 0;
    width: 100%;
}

.header {
    padding: 24px 0 0;
    text-align: left;
}

.header a {
    display: inline-block;
    text-decoration: none;
}

.logo {
    border: 0;
    height: 40px;
    max-height: 40px;
    width: auto;
}

.body {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    background-color: #ffffff;
    border: hidden !important;
    margin: 0;
    padding: 0;
    width: 100%;
}

.inner-body {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 560px;
    background-color: #ffffff;
    border-top: 1px solid #e5e7eb;
    margin: 16px auto 0;
    padding: 0;
    width: 560px;
}

.subcopy {
    border-top: 1px solid #e5e7eb;
    margin-top: 24px;
    padding-top: 16px;
}

.subcopy p {
    color: #6b7280;
    font-size: 13px;
}

.footer {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 560px;
    border-top: 1px solid #e5e7eb;
    margin: 0 auto;
    padding: 0;
    text-align: left;
    width: 560px;
}

.footer p {
    color: #9ca3af;
    font-size: 12px;
    text-align: left;
}

.footer a {
    color: #9ca3af;
    text-decoration: underline;
}

.table table {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 16px 0;
    width: 100%;
}

.table th {
    border-bottom: 1px solid #e5e7eb;
    margin: 0;
    padding-bottom: 8px;
    text-align: left;
}

.table td {
    color: #374151;
    font-size: 15px;
    line-height: 1.5;
    margin: 0;
    padding: 6px 12px 6px 0;
}

.content-cell {
    max-width: 100vw;
    padding: 24px 0;
}

.action {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    float: unset;
    margin: 24px 0;
    padding: 0;
    text-align: left;
    width: 100%;
}

.button {
    -webkit-text-size-adjust: none;
    border-radius: 6px;
    color: #ffffff;
    display: inline-block;
    font-weight: bold;
    overflow: hidden;
    text-decoration: none;
}

.button-blue,
.button-primary,
.button-green,
.button-success,
.button-red,
.button-error {
    background-color: #111827;
    border-bottom: 10px solid #111827;
    border-left: 20px solid #111827;
    border-right: 20px solid #111827;
    border-top: 10px solid #111827;
}

.panel {
    border-left: #F28C13 solid 4px;
    margin: 16px 0;
}

.panel-content {
    background-color: #f3f4f6;
    color: #374151;
    padding: 16px;
}

.panel-content p {
    color: #374151;
}

.panel-item {
    padding: 0;
}

.panel-item p:last-of-type {
    margin-bottom: 0;
    padding-bottom: 0;
}

.codigo {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 16px 0 20px;
    width: 100%;
}

.codigo-valor {
    background-color: #f3f4f6;
    border-radius: 6px;
    color: #111827;
    font-size: 28px;
    font-weight: bold;
    letter-spacing: 6px;
    padding: 14px 0;
    text-align: center;
}

.break-all {
    word-break: break-all;
}
```

- [ ] **Passo 6: criar as versões em texto**

`api/resources/views/vendor/mail/text/message.blade.php`:
```blade
<x-mail::layout>
{{-- Cabeçalho --}}
<x-slot:header>
<x-mail::header :url="\App\Notifications\Entregas\Email\MarcaDoEmail::urlDoConsole()">
Entregas RestaurantePro
</x-mail::header>
</x-slot:header>

{{-- Corpo --}}
{{ $slot }}

{{-- Link do botão por extenso --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{{ $subcopy }}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Rodapé --}}
<x-slot:footer>
<x-mail::footer>
Entregas RestaurantePro · e-mail automático, não responda.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
```

`api/resources/views/vendor/mail/text/codigo.blade.php`:
```blade
{{ $slot }}
```

- [ ] **Passo 7: criar `api/resources/views/vendor/notifications/email.blade.php`** (sem recuo)

```blade
{{-- Entregas RestaurantePro: corpo das notificações por e-mail. Assunto, título (greeting), linhas e botão vêm em
pt-BR do CanalEmailEntregas; notificação ainda sem tradução sai com o texto original neste mesmo layout. --}}
<x-mail::message>
{{-- Título --}}
# {{ ! empty($greeting) ? $greeting : 'Olá!' }}

{{-- Linhas antes do botão --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Botão --}}
@isset($actionText)
<x-mail::button :url="$actionUrl" color="primary" align="left">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Linhas depois do botão --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Link do botão por extenso --}}
@isset($actionText)
<x-slot:subcopy>
Se o botão "{{ $actionText }}" não abrir, copie este endereço no navegador: <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
```

- [ ] **Passo 8: criar `api/resources/views/vendor/fleetbase/layout/mail.blade.php`** (o `<x-mail-layout>` dos Mailables
passa a ser o mesmo layout das notificações)

```blade
{{-- Entregas RestaurantePro: o <x-mail-layout> dos Mailables do core-api e do Fleet-Ops (alias registrado no
CoreServiceProvider do core-api) usa o mesmo layout das notificações: vendor/mail/html/message.blade.php. --}}
<x-mail::message>
{{ $slot }}
@isset($subcopy)
<x-slot:subcopy>
{{ $subcopy }}
</x-slot:subcopy>
@endisset
</x-mail::message>
```

- [ ] **Passo 9: rodar a checagem.** `node scripts/teste-emails-views.mjs`. Esperado: `PASSA` em tudo o que é desta tarefa e
`FALHA ... existe` só nas três views `fleetbase/mail/*`, que são da Tarefa 7. Sintaxe do PHP novo:

```bash
PHP_WASM_DIR="$P" node scripts/teste-php/sintaxe.mjs api/app/Notifications/Entregas/Email/MarcaDoEmail.php
```

- [ ] **Passo 10: commit**

```bash
git add api/app/Notifications/Entregas/Email/MarcaDoEmail.php api/resources/views/vendor scripts/teste-emails-views.mjs
git commit -m "E-mails: layout no estilo C (logo, botão escuro, rodapé) para notificações e Mailables

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Tarefa 7: corpo dos Mailables (código, credenciais, teste)

**Arquivos:**
- Criar: `api/resources/views/vendor/fleetbase/mail/verification.blade.php`, `user-credentials.blade.php` e `test.blade.php`

Variáveis disponíveis (core-api 1.6.61):
- `verification`: `$appName`, `$currentHour`, `$user` (pode ser `null`), `$code`, `$type`, `$content`;
- `user-credentials`: `$user`, `$plaintextPassword`, `$currentHour`;
- `test`: `$user`, `$mailer`, `$currentHour`.

- [ ] **Passo 1: criar `verification.blade.php`** (sem recuo)

```blade
{{-- Entregas RestaurantePro: e-mail de código (VerificationMail do core-api). Textos por tipo em CodigosPorEmail; o
$content em inglês e o botão de verificar do original ficam de fora (o código é digitado na tela). Assunto em
AssuntoDosEmailsEmPortugues. --}}
@php
$textos = \App\Notifications\Entregas\Email\CodigosPorEmail::textos($type ?? null, $user?->name ?? null);
@endphp
<x-mail-layout>
# {{ $textos['titulo'] }}

{{ $textos['texto'] }}

<x-mail::codigo>{{ $code }}</x-mail::codigo>

{{ $textos['observacao'] }}
</x-mail-layout>
```

- [ ] **Passo 2: criar `user-credentials.blade.php`**

```blade
{{-- Entregas RestaurantePro: dados de acesso enviados pelo admin (UserCredentialsMail do core-api). --}}
<x-mail-layout>
# Seus dados de acesso

{{ \App\Notifications\Entregas\Email\Saudacao::para($user?->name ?? null) }} Seguem seus dados para entrar:

<x-mail::table>
| | |
|:--|:--|
| E-mail | **{{ $user->email }}** |
| Senha | **{{ $plaintextPassword }}** |
</x-mail::table>

<x-mail::button :url="\App\Notifications\Entregas\Email\MarcaDoEmail::urlDoConsole()" color="primary" align="left">
Entrar
</x-mail::button>

Troque a senha no primeiro acesso, em Ver perfil.
</x-mail-layout>
```

- [ ] **Passo 3: criar `test.blade.php`**

```blade
{{-- Entregas RestaurantePro: teste de e-mail de Admin → Configurações → E-mail (TestMail do core-api). --}}
<x-mail-layout>
# O envio de e-mail está funcionando

Se você recebeu esta mensagem, a configuração de e-mail do Entregas RestaurantePro está certa.

Envio: {{ strtoupper($mailer) }} · ambiente: {{ app()->environment() }}
</x-mail-layout>
```

- [ ] **Passo 4: rodar a checagem.** `node scripts/teste-emails-views.mjs`. Esperado: só `PASSA` e `FALHAS: 0`.

A checagem ignora linhas recuadas que começam com `{{--`, `@php`, `@endphp` ou `$` (o bloco `@php`). Se acusar recuo,
tire o recuo da linha.

- [ ] **Passo 5: commit**

```bash
git add api/resources/views/vendor/fleetbase/mail
git commit -m "E-mails: código, dados de acesso e teste em pt-BR no layout novo

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Tarefa 8: portal da loja, reset de senha com id

**Arquivos:**
- Modificar: `packages/customer-portal/addon/routes.js:9`

- [ ] **Passo 1: trocar a rota.** Em `packages/customer-portal/addon/routes.js`, troque
```js
        this.route('reset-password');
```
por
```js
        // Entregas: o link do e-mail traz o id do código (customer-portal/auth/reset-password/<uuid>?code=...); a rota
        // lê model({ id }) e, sem o segmento, validava um id vazio
        this.route('reset-password', { path: '/reset-password/:id' });
```

- [ ] **Passo 2: conferir que nada leva à rota sem id**

```bash
grep -rn "reset-password" packages/customer-portal/addon --include=*.js --include=*.hbs
```
Esperado: só `routes.js`, `routes/portal-auth/reset-password.js` (`model({ id })`),
`controllers/portal-auth/reset-password.js` (`queryParams = ['code']` e `link: id`) e o template. Nenhum
`transitionTo`/`LinkTo` para `portal-auth.reset-password`.

- [ ] **Passo 3: parse e i18n**

```bash
P=$(ls -d console/node_modules/.pnpm/@babel+parser@7*/node_modules/@babel/parser | head -1)
node -e 'require("./'"$P"'").parse(require("fs").readFileSync("packages/customer-portal/addon/routes.js","utf8"),{sourceType:"module"});console.log("parse ok")'
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal >/dev/null; echo "i18n exit $?"
```
Esperado: `parse ok` e `i18n exit 0`.

- [ ] **Passo 4: commit**

```bash
git add packages/customer-portal/addon/routes.js
git commit -m "Portal da loja: rota de redefinição de senha recebe o id do link do e-mail

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Tarefa 9: documentação e verificação final

**Arquivos:**
- Modificar: `CLAUDE.md` (nova seção "E-mails", depois de "Marca Entregas RestaurantePro")

- [ ] **Passo 1: documentar.** No `CLAUDE.md`, logo antes de `## Tradução pt-BR (convenções)`, inclua:

```markdown
## E-mails (pt-BR, layout do RestaurantePro)

- Desenho: `docs/superpowers/specs/2026-10-04-emails-em-portugues-design.md`. Os textos dos pacotes vêm fixos em inglês; tudo é sobrescrito em `api/`, sem editar os pacotes.
- **Layout (estilo C):** `api/resources/views/vendor/mail/html/{message,codigo}.blade.php` + `themes/default.css` (e `text/*`). Os Mailables da Fleetbase usam o mesmo layout (`vendor/fleetbase/layout/mail.blade.php`). Logo: `MarcaDoEmail` (Admin → Marca ou `api/config/fleetbase.php`).
- **Notificações:** `CanalEmailEntregas` (bind do `MailChannel` no `AppServiceProvider`) monta assunto e texto pelo catálogo `EmailsEmPortugues` e **não manda e-mail ao motoboy** (Driver e convite para usuário `type=driver`). Aviso sem tradução sai em inglês no layout novo e aparece no log como `[entregas] e-mail sem tradução` (no serviço da **fila**: `docker service logs entregas_queue | grep '\[entregas\]'`).
- **Mailables (código, credenciais, teste):** corpo em `vendor/fleetbase/mail/*.blade.php` (textos do código em `CodigosPorEmail`), assunto pelo `AssuntoDosEmailsEmPortugues` (`MessageSending`).
- **"Esqueci a senha" da loja** (usuário `customer`) leva ao portal: `customer-portal/auth/reset-password/<uuid>?code=`.
- Teste: `scripts/teste-php/emails.php` (php-wasm) e `node scripts/teste-emails-views.mjs`.
- **Ao atualizar o Laravel, o core-api ou o fleetops-api:** confira a lista no docblock do `CanalEmailEntregas` (send() do `MailChannel`, nomes e variáveis das views sobrescritas, classes e propriedades do catálogo).
```

- [ ] **Passo 2: verificação completa**

```bash
P="C:/Users/Edgardjr/AppData/Local/Temp/claude/C--Users-Edgardjr-Documents-vibe-coding-Delivery/5e9fefbb-16d5-4968-835a-1df8e42aadad/scratchpad/phpwasm"
PHP_WASM_DIR="$P" node scripts/teste-php/rodar.mjs scripts/teste-php/emails.php | tail -1
PHP_WASM_DIR="$P" node scripts/teste-php/rodar.mjs scripts/teste-php/avisos-push.php | tail -1
PHP_WASM_DIR="$P" node scripts/teste-php/sintaxe.mjs api/app/Notifications/Entregas/Email/*.php api/app/Listeners/Entregas/*.php api/app/Providers/*.php
node scripts/teste-emails-views.mjs | tail -1
```
Esperado: `FALHAS: 0` nos três testes e `OK` em todos os PHP. O teste do push confirma que o `AppServiceProvider`
continua íntegro.

- [ ] **Passo 3: commit**

```bash
git add CLAUDE.md
git commit -m "CLAUDE.md: e-mails em pt-BR (layout, canal, catálogo, motoboy sem e-mail)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

- [ ] **Passo 4: avisar o Edgard o que conferir depois do deploy** (`bash deploy/atualizar.sh api` e `bash deploy/atualizar.sh console`):
  1. Admin → Configurações → E-mail → Testar: chega "Teste de e-mail do Entregas RestaurantePro", com o logo e o rodapé.
  2. "Esqueci a senha" no console e no portal da Terraço Pizza Bar: o link do portal abre o reset do portal e troca a senha.
  3. Convite de um usuário de teste pelo IAM.
  4. Cadastro de um motoboy de teste: nenhum e-mail chega. No log da fila aparece `[entregas] e-mail ao motoboy não enviado`.
  5. Log da fila sem `[entregas] e-mail sem tradução` inesperado.
