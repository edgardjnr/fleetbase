<?php

// Stubs do teste do reenvio (reenvio.php), além do stubs.php: o SocketClusterService (a transmissão no socket), o
// Channel, o ShouldBroadcastNow e o Log.

namespace Illuminate\Broadcasting {
    class Channel
    {
        public function __construct(public string $name) {}
    }
}

namespace Illuminate\Contracts\Broadcasting {
    interface ShouldBroadcastNow {}
}

namespace Illuminate\Support\Facades {
    class Log
    {
        public static array $linhas = [];
        public static function warning($mensagem, array $contexto = []) { self::$linhas[] = ['warning', $mensagem, $contexto]; }
        public static function info($mensagem, array $contexto = []) { self::$linhas[] = ['info', $mensagem, $contexto]; }
    }
}

namespace Fleetbase\Support\SocketCluster {
    // como o real: send() não lança, guarda a mensagem em error e devolve false
    class SocketClusterService
    {
        protected ?string $error = null;

        public function send($canal, array $dados = []): bool
        {
            \Teste\Socket::$tentativas++;
            if (\Teste\Socket::$falhar) {
                $this->error = 'socket fora do ar';

                return false;
            }
            \Teste\Socket::$transmitidos[] = ['quando' => \Carbon\CarbonImmutable::now(), 'canal' => $canal, 'dados' => $dados];

            return true;
        }

        public function error(): ?string
        {
            return $this->error;
        }
    }
}

namespace Teste {
    class Socket
    {
        public static array $transmitidos = [];
        public static bool $falhar        = false;
        public static int $tentativas     = 0;
    }
}
