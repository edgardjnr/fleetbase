<?php

// Stubs dos testes de avisos push (avisos-push.php), além do stubs.php: o pacote FCM (laravel-notification-channels/fcm
// 4.5.0, MIT, copiado: o formato da mensagem e o envio do canal), o kreait, partes do Laravel e as notificações do
// Fleet-Ops e do core com o que o AvisosDoMotoboy lê delas. O OrderPing é o real.

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
        public function all(): array { return $this->itens; }
        public function count(): int { return count($this->itens); }
        public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->itens); }
    }
}

namespace Illuminate\Support\Facades {
    class Log
    {
        public static array $registros = [];
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

    class SendReport
    {
        public function __construct(private bool $falhou = false) {}
        public function isFailure(): bool { return $this->falhou; }
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

namespace Fleetbase\FleetOps\Notifications {
    // o que o AvisosDoMotoboy lê das notificações reais do fleetops-api 0.6.65: título e texto originais (em inglês),
    // dados e o pedido; o toFcm é igual ao delas
    abstract class AvisoDoFleetOps extends \Illuminate\Notifications\Notification
    {
        public $order;
        public string $title   = '';
        public string $message = '';
        public array $data     = [];

        public function toFcm($notifiable)
        {
            return \Fleetbase\Support\PushNotification::createFcmMessage($this->title, $this->message, $this->data);
        }
    }

    class OrderAssigned extends AvisoDoFleetOps {}
    class OrderDispatched extends AvisoDoFleetOps {}
    class OrderCanceled extends AvisoDoFleetOps {}
    class OrderFailed extends AvisoDoFleetOps {}
    class OrderCompleted extends AvisoDoFleetOps {}
    class WaypointCompleted extends AvisoDoFleetOps {}
}

namespace Fleetbase\Notifications {
    class ChatMessageReceived extends \Fleetbase\FleetOps\Notifications\AvisoDoFleetOps {}
    class TestPushNotification extends \Fleetbase\FleetOps\Notifications\AvisoDoFleetOps {}
}
