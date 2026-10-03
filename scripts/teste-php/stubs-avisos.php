<?php

// Stubs dos testes de avisos push (avisos-push.php), além do stubs.php: o pacote FCM (laravel-notification-channels/fcm
// 4.5.0, MIT, copiado: o formato da mensagem e o envio do canal), o kreait e partes do Laravel. As notificações do
// Fleet-Ops e do core são as reais (cópias em packages/), carregadas pelo avisos-push.php.

namespace Illuminate\Contracts\Events {
    interface Dispatcher
    {
        public function dispatch($event, $payload = [], $halt = false);
    }
}

namespace Illuminate\Notifications\Events {
    class NotificationFailed
    {
        public function __construct(public $notifiable, public $notification, public $channel, public $data = []) {}
    }
}

namespace Illuminate\Support {
    class Arr
    {
        public static function wrap($valor): array
        {
            return $valor === null ? [] : (is_array($valor) ? $valor : [$valor]);
        }
    }

    class Collection implements \IteratorAggregate, \Countable
    {
        public function __construct(protected array $itens = []) {}
        public static function make($itens = []): static { return new static(is_array($itens) ? $itens : iterator_to_array($itens)); }
        public function chunk(int $tamanho): static { return new static(array_map(fn ($parte) => new static($parte), array_chunk($this->itens, $tamanho, true))); }
        public function map(callable $funcao): static { return new static(array_map($funcao, $this->itens)); }
        public function filter(?callable $funcao = null): static { return new static($funcao ? array_filter($this->itens, $funcao) : array_filter($this->itens)); }
        public function each(callable $funcao): static { foreach ($this->itens as $chave => $valor) { $funcao($valor, $chave); } return $this; }
        public function merge($itens): static { return new static(array_merge($this->itens, $itens instanceof self ? $itens->all() : (array) $itens)); }
        public function all(): array { return $this->itens; }
        public function count(): int { return count($this->itens); }
        public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->itens); }
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

namespace Kreait\Firebase\Contract {
    interface Messaging
    {
        public function sendMulticast($message, $registrationTokens, bool $validateOnly = false);
    }
}

namespace Kreait\Firebase\Messaging {
    interface Message extends \JsonSerializable {}

    // kreait/firebase-php 7.24.1: na versão real o construtor é privado (SendReport::success/failure), aqui é público
    // por conveniência; os nomes dos métodos usados pelo canal são os reais
    class SendReport
    {
        public function __construct(
            private bool $falhou = false,
            private bool $invalida = false,
            private string $token = 'token',
            private ?\Throwable $erro = null,
            private bool $tokenInvalido = false,
            private bool $desconhecido = false,
        ) {}
        public function isFailure(): bool { return $this->falhou; }
        public function messageWasInvalid(): bool { return $this->invalida; }
        public function messageTargetWasInvalid(): bool { return $this->tokenInvalido; }
        public function messageWasSentToUnknownToken(): bool { return $this->desconhecido; }
        public function error(): ?\Throwable { return $this->erro; }
        public function target(): MessageTarget { return new MessageTarget($this->token); }
    }

    class MessageTarget
    {
        public function __construct(private string $valor) {}
        public function value(): string { return $this->valor; }
    }

    class MulticastSendReport
    {
        public function __construct(private array $itens = []) {}
        public function getItems(): array { return $this->itens; }
    }
}

namespace NotificationChannels\Fcm\Resources {
    abstract class FcmResource
    {
        public static function create(...$args): static { return new static(...$args); }
        abstract public function toArray(): array;
    }

    class Notification extends FcmResource
    {
        public function __construct(public ?string $title = null, public ?string $body = null, public ?string $image = null) {}
        public function toArray(): array { return array_filter(['title' => $this->title, 'body' => $this->body, 'image' => $this->image]); }
    }
}

namespace NotificationChannels\Fcm {
    use Illuminate\Contracts\Events\Dispatcher;
    use Illuminate\Notifications\Events\NotificationFailed;
    use Illuminate\Notifications\Notification;
    use Illuminate\Support\Arr;
    use Illuminate\Support\Collection;
    use Kreait\Firebase\Contract\Messaging;
    use Kreait\Firebase\Messaging\Message;
    use Kreait\Firebase\Messaging\MulticastSendReport;
    use Kreait\Firebase\Messaging\SendReport;
    use NotificationChannels\Fcm\Resources\Notification as NotificacaoFcm;

    class FcmMessage implements Message
    {
        public function __construct(
            public ?string $name = null,
            public ?string $token = null,
            public ?string $topic = null,
            public ?string $condition = null,
            public ?array $data = [],
            public array $custom = [],
            public ?NotificacaoFcm $notification = null,
            public ?Messaging $client = null,
        ) {}

        public function data(?array $data): self { $this->data = $data; return $this; }
        public function custom(?array $custom): self { $this->custom = $custom; return $this; }
        public function usingClient(Messaging $client): self { $this->client = $client; return $this; }

        public function toArray()
        {
            return array_filter([
                'name'         => $this->name,
                'data'         => $this->data,
                'token'        => $this->token,
                'topic'        => $this->topic,
                'condition'    => $this->condition,
                'notification' => $this->notification?->toArray(),
                ...$this->custom,
            ]);
        }

        public function jsonSerialize(): mixed { return $this->toArray(); }
    }

    class FcmChannel
    {
        const TOKENS_PER_REQUEST = 500;

        public function __construct(protected Dispatcher $events, protected Messaging $client) {}

        public function send(mixed $notifiable, Notification $notification): ?Collection
        {
            $tokens = Arr::wrap($notifiable->routeNotificationFor('fcm', $notification));

            if (empty($tokens)) {
                return null;
            }

            $fcmMessage = $notification->toFcm($notifiable);

            return Collection::make($tokens)
                ->chunk(self::TOKENS_PER_REQUEST)
                ->map(fn ($tokens) => ($fcmMessage->client ?? $this->client)->sendMulticast($fcmMessage, $tokens->all()))
                ->map(fn (MulticastSendReport $report) => $this->checkReportForFailures($notifiable, $notification, $report));
        }

        protected function checkReportForFailures(mixed $notifiable, Notification $notification, MulticastSendReport $report): MulticastSendReport
        {
            Collection::make($report->getItems())
                ->filter(fn (SendReport $report) => $report->isFailure())
                ->each(fn (SendReport $report) => $this->dispatchFailedNotification($notifiable, $notification, $report));

            return $report;
        }

        protected function dispatchFailedNotification(mixed $notifiable, Notification $notification, SendReport $report): void
        {
            $this->events->dispatch(new NotificationFailed($notifiable, $notification, self::class, ['report' => $report]));
        }
    }
}

namespace Fleetbase\Support {
    // o createFcmMessage do core-api 1.6.61 (src/Support/PushNotification.php), sem configurar o cliente do Firebase
    class PushNotification
    {
        public static ?\Kreait\Firebase\Contract\Messaging $cliente = null;

        public static function createFcmMessage(string $title, string $body, array $data = []): \NotificationChannels\Fcm\FcmMessage
        {
            $mensagem = (new \NotificationChannels\Fcm\FcmMessage(notification: new \NotificationChannels\Fcm\Resources\Notification(title: $title, body: $body)))
                ->data($data)
                ->custom([
                    'android' => [
                        'notification' => ['color' => '#4391EA', 'sound' => 'default'],
                        'fcm_options'  => ['analytics_label' => 'analytics'],
                    ],
                    'apns' => [
                        'payload'     => ['aps' => ['sound' => 'default']],
                        'fcm_options' => ['analytics_label' => 'analytics'],
                    ],
                ]);

            return static::$cliente ? $mensagem->usingClient(static::$cliente) : $mensagem;
        }
    }
}
