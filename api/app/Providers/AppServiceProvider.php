<?php

namespace App\Providers;

use App\Console\Commands\Entregas\Fuso\AtualizarEstimativasDosPedidos;
use App\Console\Commands\Entregas\Fuso\DespacharPedidosAgendados;
use App\Console\Commands\Entregas\Fuso\EnviarLembretesDeManutencao;
use App\Console\Commands\Entregas\Fuso\ProcessarGatilhosDeManutencao;
use App\Console\Commands\Entregas\ReenviarPedidosAbertos;
use App\Http\Controllers\Entregas\DriverControllerSemGeocodificacao;
use App\Listeners\Entregas\ObservadorDaDistribuicao;
use App\Listeners\Entregas\ObservadorDosPedidosIfood;
use App\Notifications\Entregas\CanalFcmEntregas;
use App\Notifications\Entregas\Email\CanalEmailEntregas;
use App\Support\Entregas\Distribuicao\TrocaDoListenerDoDespacho;
use App\Support\Entregas\FusoDoServidor;
use Fleetbase\FleetOps\Console\Commands\DispatchAdhocOrders;
use Fleetbase\FleetOps\Console\Commands\DispatchOrders;
use Fleetbase\FleetOps\Console\Commands\ProcessMaintenanceTriggers;
use Fleetbase\FleetOps\Console\Commands\SendMaintenanceReminders;
use Fleetbase\FleetOps\Console\Commands\TrackOrderDistanceAndTime;
use Fleetbase\FleetOps\Events\OrderDriverAssigned;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\DriverController as ApiDriverController;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use NotificationChannels\Fcm\FcmChannel;
use Psr\Http\Message\RequestInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Entregas: comandos agendados do Fleet-Ops (original => nosso) que chamam date_default_timezone_set('UTC') no
     * handle(). Ao atualizar o fleetops-api, confira se algum outro passou a chamar (scripts/teste-php/fuso.php).
     */
    public const COMANDOS_SEM_UTC = [
        DispatchOrders::class             => DespacharPedidosAgendados::class,
        TrackOrderDistanceAndTime::class  => AtualizarEstimativasDosPedidos::class,
        ProcessMaintenanceTriggers::class => ProcessarGatilhosDeManutencao::class,
        SendMaintenanceReminders::class   => EnviarLembretesDeManutencao::class,
    ];

    /**
     * Ring buffer of recent statements, kept only while the transaction tripwire is armed.
     *
     * @var array<int, string>
     */
    protected static array $recentStatements = [];

    /**
     * Whether a divergence has already been reported on this worker pass.
     */
    protected static bool $divergenceReported = false;

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Entregas: servidor no horário de Brasília (sessão do MySQL e datas do Eloquent); ver FusoDoServidor
        $this->configurarFuso();

        // Entregas: o fleetops:dispatch-adhoc, que o Fleet-Ops agenda a cada minuto, roda a versão corrigida do
        // reenvio de pedidos abertos (ver ReenviarPedidosAbertos)
        $this->app->bind(DispatchAdhocOrders::class, ReenviarPedidosAbertos::class);

        // Entregas: os comandos agendados do Fleet-Ops que fixavam o PHP em UTC rodam sem essa linha (ver as classes em
        // App\Console\Commands\Entregas\Fuso). O agendador resolve o comando pelo container, como no reenvio acima
        foreach (static::COMANDOS_SEM_UTC as $original => $nosso) {
            $this->app->bind($original, $nosso);
        }

        // Entregas: todo push (FCM) sai em pt-BR e no formato do app do motoboy (ver CanalFcmEntregas e AvisosDoMotoboy)
        $this->app->bind(FcmChannel::class, CanalFcmEntregas::class);

        // Entregas: todo e-mail de notificação sai em pt-BR e o motoboy não recebe e-mail (ver CanalEmailEntregas)
        $this->app->bind(MailChannel::class, CanalEmailEntregas::class);

        // Entregas: a posição do motoboy (track) não geocodifica no Google; era uma consulta paga por posição (ver
        // DriverControllerSemGeocodificacao). A rota e o Internal\v1\DriverController resolvem o controller pelo
        // container
        $this->app->bind(ApiDriverController::class, DriverControllerSemGeocodificacao::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureOutboundHttpLogging();
        $this->configureTransactionTripwire();
        $this->acompanharPedidosIfood();
        $this->distribuirPedidosAbertos();
    }

    /**
     * Entregas: mudança de pedido iFood (motoboy definido, iniciado, a caminho, concluído) → ação de logística no iFood,
     * na fila (ver ObservadorDosPedidosIfood). O updated do Eloquent pega o que passa por save() (aceite, atribuição pela
     * central, atividades); o OrderDriverAssigned do Fleet-Ops, a atribuição em lote. O resto, o
     * entregas:ifood-acompanhar reconcilia em até 30 s.
     */
    protected function acompanharPedidosIfood(): void
    {
        Order::updated(fn ($pedido) => ObservadorDosPedidosIfood::aoAtualizar($pedido));
        Event::listen(OrderDriverAssigned::class, fn ($evento) => ObservadorDosPedidosIfood::aoAtribuirMotoboy($evento));
    }

    /**
     * Entregas: distribuição de pedidos abertos (ver App\Support\Entregas\Distribuicao). O DistribuirPedidoAberto entra
     * no lugar do HandleOrderDispatched do Fleet-Ops (pedido aberto: uma oferta por vez; o resto: o original), e os
     * outros listeners do OrderDispatched ficam, na mesma ordem: o SendResourceLifecycleWebhook e o NotifyOrderEvent do
     * Fleet-Ops e o HandleOrderDispatched do Storefront (ver TrocaDoListenerDoDespacho). Os EventServiceProvider
     * registram os listeners no boot deles (callback booting); o booted() roda depois do boot de todos os providers,
     * inclusive os do Composer. Vale também com o event:cache (o cache só troca a fonte do $listen; o registro continua
     * no boot). O Order::updated encerra a distribuição quando o pedido ganha motoboy ou é encerrado
     * (ObservadorDaDistribuicao).
     *
     * Ao atualizar o fleetops-api, confira a lista de listeners do OrderDispatched em
     * packages/fleetops/server/src/Providers/EventServiceProvider.php (scripts/teste-php/distribuicao-ciclo.php).
     */
    protected function distribuirPedidosAbertos(): void
    {
        Order::updated(fn ($pedido) => ObservadorDaDistribuicao::aoAtualizar($pedido));

        $this->app->booted(fn () => TrocaDoListenerDoDespacho::aplicar(Event::getFacadeRoot()));
    }

    /**
     * Entregas: fuso da sessão do MySQL nas conexões do Fleetbase e conversão de toda data da fachada Date para o fuso do
     * app (America/Sao_Paulo, config/app.php). No register(), antes de qualquer conexão abrir (o core só lê o banco no
     * boot); com o config:cache, o valor também fica gravado no cache. Ver FusoDoServidor.
     */
    protected function configurarFuso(): void
    {
        $configuracoes = FusoDoServidor::configuracoesDoBanco((array) config('database.connections', []), FusoDoServidor::fusoDoBanco());
        if ($configuracoes) {
            config($configuracoes);
        }

        $fuso = (string) config('app.timezone');
        Date::useCallable(fn ($data) => FusoDoServidor::paraOFusoDoApp($data, $fuso));
    }

    /**
     * Detect the moment Laravel's transaction counter stops agreeing with the MySQL session.
     *
     * Laravel decides whether to issue a COMMIT from its own integer counter
     * (ManagesTransactions::commit(), and the inline twin inside transaction()),
     * while PDO decides whether a COMMIT is legal from the server's
     * SERVER_STATUS_IN_TRANS flag. If anything ends the server-side transaction
     * without going through the Connection - an implicit commit from DDL, or a
     * second PDO handle aliasing the same persistent MySQL session - the counter
     * keeps saying "1". Every statement issued since BEGIN is then already
     * durable, and the eventual commit throws "There is no active transaction",
     * so the caller reports failure for a write that landed.
     *
     * This logs the divergence at the statement that caused it, which is the
     * only place the culprit is still identifiable.
     */
    protected function configureTransactionTripwire(): void
    {
        if (!env('DB_TXN_TRIPWIRE_ENABLED', false)) {
            return;
        }

        // One report per transaction, not one per worker: a worker serves up to
        // --max-requests before it recycles, and a single latched flag would hide
        // every occurrence after the first.
        Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) {
            if ($event->connection->transactionLevel() === 1) {
                static::$divergenceReported = false;
            }
        });

        DB::listen(function (QueryExecuted $query) {
            $this->recordStatement($query->sql);

            if ($query->connection->transactionLevel() < 1) {
                return;
            }

            $pdo = $query->connection->getRawPdo();

            if (!$pdo instanceof \PDO || $pdo->inTransaction()) {
                return;
            }

            $this->reportTransactionDivergence($query->connection, 'after-statement', $query->sql);
        });

        // Backstop: the divergence may be caused by something we never see as a
        // query of ours (another PDO handle on the same session). This catches it
        // immediately before the doomed COMMIT.
        Event::listen(TransactionCommitting::class, function (TransactionCommitting $event) {
            $pdo = $event->connection->getRawPdo();

            if ($pdo instanceof \PDO && !$pdo->inTransaction()) {
                $this->reportTransactionDivergence($event->connection, 'at-commit', null);
            }
        });
    }

    protected function recordStatement(string $sql): void
    {
        static::$recentStatements[] = Str::limit(preg_replace('/\s+/', ' ', $sql), 200);

        if (count(static::$recentStatements) > 25) {
            array_shift(static::$recentStatements);
        }
    }

    protected function reportTransactionDivergence(Connection $connection, string $phase, ?string $sql): void
    {
        // One report per worker pass. Re-entrancy guard as well: the CONNECTION_ID()
        // lookup below is itself a query and would otherwise trip the listener.
        if (static::$divergenceReported) {
            return;
        }

        static::$divergenceReported = true;

        $pdo = $connection->getRawPdo();

        try {
            $mysqlConnectionId = $pdo instanceof \PDO
                ? $pdo->query('SELECT CONNECTION_ID()')->fetchColumn()
                : null;
        } catch (\Throwable $e) {
            $mysqlConnectionId = 'unavailable: ' . $e->getMessage();
        }

        Log::error('[db:txn:divergence] transaction ended outside the Connection', [
            'phase'               => $phase,
            'statement'           => $sql === null ? null : Str::limit(preg_replace('/\s+/', ' ', $sql), 300),
            'connection'          => $connection->getName(),
            'transaction_level'   => $connection->transactionLevel(),
            'pdo_object_id'       => $pdo instanceof \PDO ? spl_object_id($pdo) : null,
            'connection_object_id'=> spl_object_id($connection),
            'mysql_connection_id' => $mysqlConnectionId,
            'recent_statements'   => static::$recentStatements,
            'trace'               => $this->applicationTrace(),
        ]);
    }

    protected function applicationTrace(): array
    {
        return collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60))
            ->map(fn ($frame) => ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? '?'))
            ->values()
            ->all();
    }

    protected function configureOutboundHttpLogging(): void
    {
        if (!env('HTTP_CLIENT_TRACE_ENABLED', false)) {
            return;
        }

        Http::globalMiddleware(function (callable $handler) {
            return function (RequestInterface $request, array $options) use ($handler) {
                $id      = (string) Str::uuid();
                $started = microtime(true);

                Log::info('[http:out:start]', [
                    'id'              => $id,
                    'method'          => $request->getMethod(),
                    'url'             => $this->redactOutboundHttpUrl((string) $request->getUri()),
                    'timeout'         => $options['timeout'] ?? null,
                    'connect_timeout' => $options['connect_timeout'] ?? null,
                    'trace'           => $this->outboundHttpTrace(),
                ]);

                return $handler($request, $options)->then(
                    function ($response) use ($id, $started) {
                        Log::info('[http:out:finish]', [
                            'id'         => $id,
                            'status'     => $response->getStatusCode(),
                            'elapsed_ms' => $this->elapsedMilliseconds($started),
                        ]);

                        return $response;
                    },
                    function ($reason) use ($id, $started) {
                        Log::warning('[http:out:error]', [
                            'id'         => $id,
                            'elapsed_ms' => $this->elapsedMilliseconds($started),
                            'error'      => $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason,
                        ]);

                        if ($reason instanceof \Throwable) {
                            throw $reason;
                        }

                        throw new \RuntimeException((string) $reason);
                    }
                );
            };
        });
    }

    protected function redactOutboundHttpUrl(string $url): string
    {
        return preg_replace('/([?&](?:token|access_token|api_key|apikey|key|secret|signature)=)[^&#]*/i', '$1[redacted]', $url) ?? $url;
    }

    protected function outboundHttpTrace(): array
    {
        return collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20))
            ->filter(function ($frame) {
                $file = $frame['file'] ?? null;

                return $file && !str_contains($file, '/vendor/');
            })
            ->map(fn ($frame) => $frame['file'] . ':' . ($frame['line'] ?? '?'))
            ->values()
            ->take(8)
            ->all();
    }

    protected function elapsedMilliseconds(float $started): int
    {
        return (int) ((microtime(true) - $started) * 1000);
    }
}
