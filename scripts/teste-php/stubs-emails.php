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
