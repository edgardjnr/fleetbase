<?php

// Stubs do teste do reenvio (reenvio.php), além do stubs.php: a transmissão no socket (broadcast), o Channel, o
// ShouldBroadcastNow e o Log.

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

namespace Teste {
    class Socket
    {
        public static array $transmitidos = [];
        public static bool $falhar        = false;
    }
}

namespace {
    function broadcast($evento)
    {
        if (\Teste\Socket::$falhar) {
            throw new \RuntimeException('socket fora do ar');
        }
        \Teste\Socket::$transmitidos[] = ['quando' => \Carbon\CarbonImmutable::now(), 'evento' => $evento];
    }
}
