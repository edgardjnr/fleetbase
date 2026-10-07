<?php

// Stubs dos testes da integração iFood (scripts/teste-php/ifood-*.php). Independentes dos outros stubs: aqui ficam o
// Http do Laravel (fila de respostas e registro das chamadas), o DB (tabelas em memória, com o pouco de query builder
// que as classes do iFood usam e as chaves únicas das tabelas novas), o Cache (com trava), o Log, o encrypt/decrypt, o
// relógio (now()), a fila de jobs, o Command, o Schedule, o Schema das migrations e o socket (SocketClusterService e
// Channel, para o aviso à central). Os models do Fleetbase (Order, Place, Payload, Vendor, OrderConfig), o Request e o
// Validator ficam no stubs-ifood-fleetbase.php.

namespace {
    // como na produção: o Laravel faz date_default_timezone_set(config('app.timezone')), que é America/Sao_Paulo
    // (horário de Brasília). Ver CLAUDE.md, "Fuso (horário de Brasília)"
    date_default_timezone_set('America/Sao_Paulo');
}

namespace Illuminate\Support {
    class Collection implements \IteratorAggregate, \Countable
    {
        public function __construct(protected array $itens = []) {}
        public function all(): array { return $this->itens; }
        public function count(): int { return count($this->itens); }
        public function isEmpty(): bool { return !$this->itens; }
        public function first() { return $this->itens ? reset($this->itens) : null; }
        public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->itens); }
        public function map(callable $funcao): static { return new static(array_map($funcao, $this->itens)); }
        public function values(): static { return new static(array_values($this->itens)); }
        public function pluck(string $campo): static { return new static(array_map(fn ($item) => is_array($item) ? ($item[$campo] ?? null) : ($item->$campo ?? null), array_values($this->itens))); }
    }

    // o Carbon do Laravel (mutável, como o de verdade), só no que as classes do iFood usam
    class Carbon extends \DateTime
    {
        public static function parse($valor = 'now', $fuso = null): static
        {
            if ($valor instanceof \DateTimeInterface) {
                return new static($valor->format('Y-m-d H:i:s.u'), $valor->getTimezone());
            }

            return new static((string) $valor, is_string($fuso) ? new \DateTimeZone($fuso) : ($fuso ?? new \DateTimeZone(date_default_timezone_get())));
        }

        public function addSeconds($n): static { $this->modify('+' . (int) $n . ' seconds'); return $this; }
        public function subSeconds($n): static { $this->modify('-' . (int) $n . ' seconds'); return $this; }
        public function addMinutes($n): static { $this->modify('+' . (int) $n . ' minutes'); return $this; }
        public function subMinutes($n): static { $this->modify('-' . (int) $n . ' minutes'); return $this; }
        public function subMinute(): static { return $this->subMinutes(1); }
        public function addHour(): static { $this->modify('+1 hour'); return $this; }
        public function subHours($n): static { $this->modify('-' . (int) $n . ' hours'); return $this; }
        public function subDays($n): static { $this->modify('-' . (int) $n . ' days'); return $this; }
        public function toDateTimeString(): string { return $this->format('Y-m-d H:i:s'); }
        public function toIso8601String(): string { return $this->format('Y-m-d\TH:i:sP'); }
    }
}

namespace Illuminate\Support\Facades {
    class DB
    {
        public static function table(string $tabela)
        {
            \Teste\Banco::$consultadas[] = $tabela;
            if (isset(\Teste\Banco::$falharAoConsultar[$tabela])) {
                throw \Teste\Banco::$falharAoConsultar[$tabela];
            }

            return new \Teste\Consulta($tabela);
        }

        // desfaz as escritas nas tabelas em memória e nas listas dos models falsos (Banco::$modelos) se a função lançar
        // (como o rollback do MySQL)
        public static function transaction(\Closure $fazer)
        {
            $copia   = \Teste\Banco::$tabelas;
            $modelos = [];
            foreach (\Teste\Banco::$modelos as $classe => $listas) {
                foreach ($listas as $lista) {
                    $modelos[$classe][$lista] = $classe::$$lista;
                }
            }
            try {
                return $fazer();
            } catch (\Throwable $e) {
                \Teste\Banco::$tabelas = $copia;
                foreach ($modelos as $classe => $listas) {
                    foreach ($listas as $lista => $valor) {
                        $classe::$$lista = $valor;
                    }
                }
                throw $e;
            }
        }
    }

    class Cache
    {
        public static array $dados = [];
        public static array $validades = [];
        public static function get($chave, $padrao = null) { return array_key_exists($chave, self::$dados) ? self::$dados[$chave] : $padrao; }
        public static function put($chave, $valor, $ttl = null) { self::$dados[$chave] = $valor; self::$validades[$chave] = $ttl; return true; }
        public static function forget($chave) { unset(self::$dados[$chave], self::$validades[$chave]); return true; }
        // grava só se a chave não existe (o teste simula o vencimento com forget)
        public static function add($chave, $valor, $ttl = null) { if (array_key_exists($chave, self::$dados)) { return false; } return self::put($chave, $valor, $ttl); }
        public static function lock($nome, $segundos = 0) { \Teste\Trava::$validades[$nome] = $segundos; return new \Teste\Trava($nome); }
    }

    class Log
    {
        public static array $registros = [];
        public static function debug($mensagem, array $contexto = []) { self::$registros[] = ['debug', $mensagem, $contexto]; }
        public static function info($mensagem, array $contexto = []) { self::$registros[] = ['info', $mensagem, $contexto]; }
        public static function warning($mensagem, array $contexto = []) { self::$registros[] = ['warning', $mensagem, $contexto]; }
        public static function error($mensagem, array $contexto = []) { self::$registros[] = ['error', $mensagem, $contexto]; }
    }

    class Http
    {
        public static function __callStatic($metodo, $argumentos) { return (new \Teste\PedidoHttp())->$metodo(...$argumentos); }
    }

    // a migration: Schema::create entrega um Blueprint que só registra as colunas
    class Schema
    {
        public static array $criadas = [];

        public static function create(string $tabela, \Closure $definicao): void
        {
            $blueprint = new \Illuminate\Database\Schema\Blueprint();
            $definicao($blueprint);
            self::$criadas[$tabela] = $blueprint;
        }

        public static function dropIfExists(string $tabela): void { unset(self::$criadas[$tabela]); }

        /** Schema::table (migration que acrescenta colunas): tabela => [Blueprint, ...], na ordem. */
        public static array $alteradas = [];

        public static function table(string $tabela, \Closure $definicao): void
        {
            $blueprint = new \Illuminate\Database\Schema\Blueprint();
            $definicao($blueprint);
            self::$alteradas[$tabela][] = $blueprint;
        }
    }
}

namespace Illuminate\Http\Client {
    class ConnectionException extends \Exception {}

    // a resposta do Http do Laravel, só no que o ClienteIfood usa
    class Response
    {
        public function __construct(private int $codigo, private $corpo = null, private array $cabecalhos = []) {}
        public function status(): int { return $this->codigo; }
        public function successful(): bool { return $this->codigo >= 200 && $this->codigo < 300; }
        public function body(): string { return is_string($this->corpo) ? $this->corpo : ($this->corpo === null ? '' : (string) json_encode($this->corpo)); }

        public function json($chave = null, $padrao = null)
        {
            $dados = is_string($this->corpo) ? json_decode($this->corpo, true) : $this->corpo;

            return $chave === null ? $dados : ($dados[$chave] ?? $padrao);
        }

        public function header(string $nome): string
        {
            foreach ($this->cabecalhos as $chave => $valor) {
                if (strcasecmp($chave, $nome) === 0) {
                    return (string) $valor;
                }
            }

            return '';
        }
    }
}

// o Guzzle (por baixo do Http do Laravel): erros de transferência fora do ConnectionException (redirecionamentos
// demais, resposta truncada...)
namespace GuzzleHttp\Exception {
    interface GuzzleException extends \Throwable {}
    class TransferException extends \RuntimeException implements GuzzleException {}
}

// o decrypt() com APP_KEY trocado ou texto corrompido
namespace Illuminate\Contracts\Encryption {
    class DecryptException extends \RuntimeException {}
}

// o block() da trava do Cache que esperou o tempo todo
namespace Illuminate\Contracts\Cache {
    class LockTimeoutException extends \Exception {}
}

// erro do banco: código = SQLSTATE (23000 = chave única), como o QueryException de verdade (que copia o do PDOException)
namespace Illuminate\Database {
    class QueryException extends \PDOException {}
}

namespace Illuminate\Database\Migrations {
    abstract class Migration {}
}

namespace Illuminate\Database\Schema {
    class Blueprint
    {
        /** @var \Teste\Coluna[] */
        public array $colunas = [];
        public function __call($tipo, $argumentos) { return $this->colunas[] = new \Teste\Coluna($tipo, $argumentos); }
    }
}

namespace Illuminate\Contracts\Queue {
    interface ShouldQueue {}
}

// o aviso à central (App\Events\Entregas\PedidoSemMotoboy) e a transmissão no socket (TransmissaoNoSocket)
namespace Illuminate\Broadcasting {
    class Channel
    {
        public function __construct(public string $name) {}
    }
}

namespace Illuminate\Contracts\Broadcasting {
    interface ShouldBroadcastNow {}
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
            \Teste\Socket::$transmitidos[] = ['canal' => $canal, 'dados' => $dados];

            return true;
        }

        public function error(): ?string { return $this->error; }
    }
}

namespace Illuminate\Bus {
    // como o do Laravel 10: as propriedades sem valor padrão (um job que redeclara uma delas com outro padrão quebra
    // na composição da classe, erro fatal que só aparece ao carregar o job)
    trait Queueable
    {
        public $connection;
        public $queue;
        public $delay;
        public $afterCommit;
        public $middleware = [];
        public $chained = [];

        // como o Queueable do Laravel: a fila do job (FilaIfood)
        public function onQueue($queue) { $this->queue = $queue; return $this; }
    }
}

namespace Illuminate\Queue {
    trait InteractsWithQueue
    {
        /** Segundos do release() (o job voltou para a fila), ou null. */
        public ?int $liberadoPor = null;
        /** Exceção do fail() (o job desistiu sem nova tentativa), ou null. */
        public ?\Throwable $falhouCom = null;
        public function release($atraso = 0) { $this->liberadoPor = (int) $atraso; }
        public function fail($erro = null) { $this->falhouCom = $erro instanceof \Throwable ? $erro : new \Exception((string) $erro); }
    }
}

namespace Illuminate\Foundation\Bus {
    trait Dispatchable
    {
        // com Fila::$falhar, lança como o dispatch com o Redis fora do ar
        public static function dispatch(...$argumentos)
        {
            if (\Teste\Fila::$falhar) {
                throw \Teste\Fila::$falhar;
            }
            $job                 = new static(...$argumentos);
            \Teste\Fila::$jobs[] = $job;

            return new \Teste\DespachoPendente($job);
        }
    }
}

namespace Illuminate\Console {
    class Command
    {
        public const SUCCESS = 0;
        public const FAILURE = 1;
        public function info($texto) {}
        public function line($texto) {}
        public function warn($texto) {}
    }
}

namespace Illuminate\Console\Scheduling {
    class Schedule
    {
        /** @var \Teste\EventoAgendado[] */
        public array $eventos = [];
        public function command(string $comando) { return $this->eventos[] = new \Teste\EventoAgendado($comando); }
    }
}

namespace Illuminate\Foundation\Console {
    abstract class Kernel
    {
        protected function schedule(\Illuminate\Console\Scheduling\Schedule $schedule) {}
        protected function load($caminhos) {}
    }
}

namespace Teste {
    class Relogio
    {
        // hora de Brasília (15:00 = 18:00 UTC)
        public static string $agora = '2026-10-05 15:00:00';
    }

    class Config
    {
        public static array $valores = [];
    }

    class Sessao
    {
        public static array $dados = [];
    }

    // o que o SocketClusterService falso transmitiu; com $falhar, todo send() devolve false
    class Socket
    {
        public static array $transmitidos = [];
        public static bool $falhar        = false;
        public static int $tentativas     = 0;
    }

    class Fila
    {
        public static array $jobs = [];
        /** Erro que todo dispatch lança (fila fora do ar), ou null. */
        public static ?\Throwable $falhar = null;
    }

    /** O PendingDispatch do Laravel: delay() e onQueue() ficam anotados no job (DespachoPendente::$atrasos[<índice do job em Fila::$jobs>] = segundos). */
    class DespachoPendente
    {
        public static array $atrasos = [];

        public function __construct(private object $job) {}

        public function delay($quando)
        {
            if ($quando instanceof \DateTimeInterface) {
                $segundos = $quando->getTimestamp() - now()->getTimestamp();
            } elseif ($quando instanceof \DateInterval) {
                $segundos = (int) (new \DateTimeImmutable('@0'))->add($quando)->getTimestamp();
            } else {
                $segundos = (int) $quando;
            }
            self::$atrasos[count(Fila::$jobs) - 1] = $segundos;
            $this->job->delay                      = $segundos;

            return $this;
        }

        public function onQueue($fila)
        {
            $this->job->queue = $fila;

            return $this;
        }
        public function afterCommit() { return $this; }
    }

    class Coluna
    {
        public array $modificadores = [];
        public function __construct(public string $tipo, public array $argumentos) {}
        public function __call($modificador, $argumentos) { $this->modificadores[$modificador] = $argumentos; return $this; }
    }

    class EventoAgendado
    {
        public array $chamadas = [];
        public function __construct(public string $comando) {}
        public function __call($metodo, $argumentos) { $this->chamadas[$metodo] = $argumentos; return $this; }
    }

    // trava do Cache::lock: uma por nome; o teste ocupa uma pondo o nome em $ocupadas
    class Trava
    {
        public static array $ocupadas = [];
        /** Validade pedida em cada Cache::lock (nome => segundos). */
        public static array $validades = [];
        public function __construct(private string $nome) {}

        public function get($callback = null)
        {
            if (isset(self::$ocupadas[$this->nome])) {
                return false;
            }
            self::$ocupadas[$this->nome] = true;
            if ($callback === null) {
                return true;
            }
            try {
                return $callback();
            } finally {
                $this->release();
            }
        }

        public function block($segundos, $callback = null)
        {
            if (isset(self::$ocupadas[$this->nome])) {
                // o Laravel espera $segundos e desiste com LockTimeoutException
                throw new \Illuminate\Contracts\Cache\LockTimeoutException("trava ocupada: {$this->nome}");
            }

            return $this->get($callback);
        }

        public function release() { unset(self::$ocupadas[$this->nome]); return true; }
    }

    // respostas do iFood em fila (uma por chamada, na ordem) e o registro das chamadas feitas
    class Http
    {
        public static array $respostas = [];
        public static array $chamadas = [];
        public static function responder(int $status, $corpo = null, array $cabecalhos = []): void { self::$respostas[] = [$status, $corpo, $cabecalhos]; }
        public static function falharConexao(): void { self::$respostas[] = 'conexao'; }
        public static function falharTransferencia(): void { self::$respostas[] = 'transferencia'; }
        /** Resposta montada na hora da chamada: $fazer() roda nesse momento (ex.: outro processo mexe no banco) e devolve [status, corpo, cabeçalhos]. */
        public static function responderCom(\Closure $fazer): void { self::$respostas[] = $fazer; }

        /** URLs chamadas, na ordem (sem a base). */
        public static function urls(): array
        {
            return array_map(fn ($c) => $c['metodo'] . ' ' . str_replace('https://merchant-api.ifood.com.br', '', $c['url']), self::$chamadas);
        }
    }

    class PedidoHttp
    {
        private array $opcoes = ['form' => false, 'token' => null, 'headers' => [], 'timeout' => null];
        public function asForm() { $this->opcoes['form'] = true; return $this; }
        public function acceptJson() { return $this; }
        public function withToken($token) { $this->opcoes['token'] = $token; return $this; }
        public function withHeaders(array $cabecalhos) { $this->opcoes['headers'] = array_merge($this->opcoes['headers'], $cabecalhos); return $this; }
        public function timeout($segundos) { $this->opcoes['timeout'] = $segundos; return $this; }
        public function get($url, $query = []) { return $this->enviar('GET', $url, $query); }
        public function post($url, $dados = []) { return $this->enviar('POST', $url, $dados); }
        // send('POST', $url) sem opções = POST sem corpo (as ações de logística do iFood); dados = null
        public function send($metodo, $url, array $opcoes = []) { return $this->enviar(strtoupper($metodo), $url, $opcoes['json'] ?? null); }

        private function enviar(string $metodo, string $url, $dados)
        {
            Http::$chamadas[] = ['metodo' => $metodo, 'url' => $url, 'dados' => $dados] + $this->opcoes;
            $resposta = array_shift(Http::$respostas);
            if ($resposta === null) {
                throw new \LogicException("Http: nenhuma resposta na fila para {$metodo} {$url}");
            }
            if ($resposta === 'conexao') {
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out');
            }
            if ($resposta === 'transferencia') {
                throw new \GuzzleHttp\Exception\TransferException('Will not follow more than 5 redirects');
            }
            if ($resposta instanceof \Closure) {
                $resposta = $resposta();
            }

            return new \Illuminate\Http\Client\Response(...$resposta);
        }
    }

    // erro do banco em memória, com o errorInfo do PDO: [SQLSTATE, código do driver, mensagem]. '23000' + 1062 = chave
    // única repetida (duplicate entry); '23000' com outro código (1451, 1048...) é outra violação de integridade.
    class ErroDeBanco extends \Illuminate\Database\QueryException
    {
        public function __construct(string $mensagem, string $sqlstate = 'HY000', ?int $codigoDoDriver = null)
        {
            parent::__construct($mensagem);
            $this->code      = $sqlstate;
            $this->errorInfo = [$sqlstate, $codigoDoDriver, $mensagem];
        }
    }

    // tabelas em memória: nome => [id => linha]
    class Banco
    {
        public static array $tabelas = [];
        public static array $proximoId = [];
        /** Classe => [nomes das listas estáticas]: models falsos que o DB::transaction desfaz junto (stubs-ifood-fleetbase.php). */
        public static array $modelos = [];
        /** Tabela => função que devolve as linhas (arrays) de uma tabela fora do banco em memória, para o join (ex.: orders). */
        public static array $externas = [];
        /** As tabelas abertas com DB::table, na ordem (o teste conta as consultas). */
        public static array $consultadas = [];
        /** Tabela => exceção que o DB::table lança (o banco fora do ar). */
        public static array $falharAoConsultar = [];
        /**
         * Colunas e colunas únicas das tabelas entregas_ifood_* e da distribuição (entregas_distribuicoes, entregas_ofertas), lidas das migrations (carregadas uma vez, na primeira
         * consulta): tabela => ['colunas' => [nomes], 'unicas' => [nomes]]. Assim um nome de coluna errado falha aqui,
         * como o "Unknown column" do MySQL, e a lista de únicas não se descola das migrations.
         */
        private static ?array $esquema = null;
        /** Tabela => mensagem: toda escrita nela lança ErroDeBanco (banco fora do ar). */
        public static array $falhar = [];
        /** Tabela => [SQLSTATE, código do driver]: o próximo insert nela lança esse erro (depois do gancho), uma vez. */
        public static array $falharComo = [];
        /** Tabela => função: roda uma vez, logo antes do próximo insert nela (outro processo gravando no meio). */
        public static array $antesDeInserir = [];

        public static function limpar(): void
        {
            self::$tabelas        = [];
            self::$proximoId      = [];
            self::$falhar         = [];
            self::$falharComo     = [];
            self::$antesDeInserir = [];
            self::$consultadas    = [];
            self::$falharAoConsultar = [];
        }

        /** O esquema das migrations do iFood e da distribuição: lê os arquivos uma vez, sem mexer no Schema::$criadas dos testes. */
        private static function esquema(): array
        {
            if (self::$esquema !== null) {
                return self::$esquema;
            }
            $guardadas  = \Illuminate\Support\Facades\Schema::$criadas;
            $alteradas  = \Illuminate\Support\Facades\Schema::$alteradas;
            self::$esquema = [];
            // as que criam e as que acrescentam colunas (Schema::table), na ordem dos arquivos (a data no nome)
            $arquivos = array_merge(
                glob(dirname(__DIR__, 2) . '/api/database/migrations/*_entregas_ifood_*_table.php') ?: [],
                glob(dirname(__DIR__, 2) . '/api/database/migrations/*_entregas_distribuicao_*.php') ?: []
            );
            foreach ($arquivos as $arquivo) {
                \Illuminate\Support\Facades\Schema::$criadas   = [];
                \Illuminate\Support\Facades\Schema::$alteradas = [];
                (require $arquivo)->up();
                $blueprints = [];
                foreach (\Illuminate\Support\Facades\Schema::$criadas as $tabela => $blueprint) {
                    self::$esquema[$tabela] = ['colunas' => [], 'unicas' => [], 'padroes' => []];
                    $blueprints[]           = [$tabela, $blueprint];
                }
                foreach (\Illuminate\Support\Facades\Schema::$alteradas as $tabela => $lista) {
                    foreach ($lista as $blueprint) {
                        $blueprints[] = [$tabela, $blueprint];
                    }
                }
                foreach ($blueprints as [$tabela, $blueprint]) {
                    $colunas = [];
                    $unicas  = [];
                    $padroes = [];
                    $tamanhos = [];
                    foreach ($blueprint->colunas as $coluna) {
                        $primeiro = $coluna->argumentos[0] ?? null;
                        if ($coluna->tipo === 'timestamps') {
                            array_push($colunas, 'created_at', 'updated_at');
                        } elseif ($coluna->tipo === 'id') {
                            $colunas[] = $primeiro ?? 'id';
                        } elseif (is_string($primeiro)) {
                            // index([...]) e afins não são colunas (o primeiro argumento é uma lista)
                            $colunas[] = $primeiro;
                            // string('col', N): o tamanho declarado (o MySQL estrito recusa o que passa dele); a migration seguinte com ->change() vale
                            if ($coluna->tipo === 'string') {
                                $tamanhos[$primeiro] = $coluna->argumentos[1] ?? 255;
                            }
                            if (array_key_exists('unique', $coluna->modificadores)) {
                                $unicas[] = $primeiro;
                            }
                            // ->default(x): o valor que o MySQL põe quando o insert não traz a coluna
                            if (array_key_exists('default', $coluna->modificadores)) {
                                $padroes[$primeiro] = $coluna->modificadores['default'][0] ?? null;
                            }
                        }
                    }
                    self::$esquema[$tabela] = [
                        'colunas' => array_merge(self::$esquema[$tabela]['colunas'] ?? [], $colunas),
                        'unicas'  => array_merge(self::$esquema[$tabela]['unicas'] ?? [], $unicas),
                        'padroes' => array_merge(self::$esquema[$tabela]['padroes'] ?? [], $padroes),
                        'tamanhos' => array_merge(self::$esquema[$tabela]['tamanhos'] ?? [], $tamanhos),
                    ];
                }
            }
            \Illuminate\Support\Facades\Schema::$criadas   = $guardadas;
            \Illuminate\Support\Facades\Schema::$alteradas = $alteradas;

            return self::$esquema;
        }

        /** Tamanho declarado de uma coluna string() das migrations (depois dos ->change()); null se não é string ou não existe. */
        public static function tamanhoDe(string $tabela, string $coluna): ?int
        {
            return self::esquema()[$tabela]['tamanhos'][$coluna] ?? null;
        }

        /** Colunas únicas da tabela (NULL não conta, como no MySQL); vazio para tabela fora do esquema do iFood e da distribuição. */
        public static function unicasDe(string $tabela): array
        {
            return self::esquema()[$tabela]['unicas'] ?? [];
        }

        /** Lança como o MySQL ("Unknown column") se a coluna não existe na tabela entregas_ifood_*, entregas_distribuicoes ou entregas_ofertas; as outras tabelas passam. */
        public static function exigirColuna(string $tabela, $coluna): void
        {
            // "tabela.coluna" (com join): confere na tabela do prefixo
            if (is_string($coluna) && str_contains($coluna, '.')) {
                [$tabela, $coluna] = explode('.', $coluna, 2);
            }
            $colunas = self::esquema()[$tabela]['colunas'] ?? null;
            if ($colunas !== null && is_string($coluna) && $coluna !== '*' && !in_array($coluna, $colunas, true)) {
                throw new \RuntimeException("coluna desconhecida {$coluna} em {$tabela}");
            }
        }

        /** As linhas (arrays) de uma tabela para o join: as do banco em memória ou as de Banco::$externas. */
        public static function linhasDe(string $tabela): array
        {
            return isset(self::$externas[$tabela]) ? (self::$externas[$tabela])() : (self::$tabelas[$tabela] ?? []);
        }

        /** As linhas da tabela, como objetos (o que o DB::table()->get() devolve). */
        public static function linhas(string $tabela): array
        {
            return array_values(array_map(fn ($linha) => (object) $linha, self::$tabelas[$tabela] ?? []));
        }

        /** A primeira coluna única em que $linha repete o valor de alguma das $existentes (NULL não conta), ou null. */
        public static function colunaRepetida(string $tabela, array $linha, array $existentes): ?string
        {
            foreach (self::unicasDe($tabela) as $coluna) {
                $valor = $linha[$coluna] ?? null;
                if ($valor === null) {
                    continue;
                }
                foreach ($existentes as $existente) {
                    if (($existente[$coluna] ?? null) === $valor) {
                        return $coluna;
                    }
                }
            }

            return null;
        }

        /** O erro do MySQL para chave única repetida: SQLSTATE 23000, erro 1062 do driver. */
        public static function erroDeRepetida(string $tabela, string $coluna, $valor): ErroDeBanco
        {
            return new ErroDeBanco("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '{$valor}' for key '{$tabela}.{$coluna}'", '23000', 1062);
        }

        public static function inserir(string $tabela, array $linha, bool $ignorarRepetida): bool
        {
            if (isset(self::$falhar[$tabela])) {
                throw new ErroDeBanco(self::$falhar[$tabela]);
            }
            if (isset(self::$antesDeInserir[$tabela])) {
                $fazer = self::$antesDeInserir[$tabela];
                unset(self::$antesDeInserir[$tabela]);
                $fazer();
            }
            if (isset(self::$falharComo[$tabela])) {
                [$sqlstate, $codigoDoDriver] = self::$falharComo[$tabela];
                unset(self::$falharComo[$tabela]);
                throw new ErroDeBanco("SQLSTATE[{$sqlstate}]: erro {$codigoDoDriver} simulado", $sqlstate, $codigoDoDriver);
            }
            foreach (array_keys($linha) as $coluna) {
                self::exigirColuna($tabela, $coluna);
            }
            $repetida = self::colunaRepetida($tabela, $linha, self::$tabelas[$tabela] ?? []);
            if ($repetida !== null) {
                if ($ignorarRepetida) {
                    return false;
                }
                throw self::erroDeRepetida($tabela, $repetida, $linha[$repetida]);
            }
            $id                             = self::$proximoId[$tabela] = (self::$proximoId[$tabela] ?? 0) + 1;
            // como o MySQL, a coluna que o insert não trouxe existe na linha: com o ->default() da migration, ou NULL
            $esquema = self::esquema()[$tabela] ?? [];
            self::$tabelas[$tabela][$id] = ['id' => $id] + $linha + ($esquema['padroes'] ?? []) + array_fill_keys($esquema['colunas'] ?? [], null);

            return true;
        }
    }

    // subgrupo de um where(function ($q) {...}): OU de grupos E, com where, orWhere, whereNull e orWhereNull
    class GrupoDeFiltros
    {
        private array $grupos = [[]];
        public function __construct(private string $tabela) {}
        public function where($coluna, $operador = null, $valor = null)
        {
            if (func_num_args() === 2) {
                $valor    = $operador;
                $operador = '=';
            }
            Banco::exigirColuna($this->tabela, $coluna);
            $this->grupos[array_key_last($this->grupos)][] = fn (array $linha) => Consulta::compara($linha[$coluna] ?? null, $operador, $valor);

            return $this;
        }
        public function orWhere(...$argumentos) { $this->grupos[] = []; return $this->where(...$argumentos); }
        public function whereNull($coluna) { Banco::exigirColuna($this->tabela, $coluna); $this->grupos[array_key_last($this->grupos)][] = fn (array $linha) => ($linha[$coluna] ?? null) === null; return $this; }
        public function orWhereNull($coluna) { $this->grupos[] = []; return $this->whereNull($coluna); }
        public function passa(array $linha): bool
        {
            foreach ($this->grupos as $grupo) {
                if ($grupo && array_reduce($grupo, fn ($todos, $filtro) => $todos && $filtro($linha), true)) {
                    return true;
                }
            }

            return false;
        }
    }

    // o query builder do DB::table, só no que as classes do iFood usam (where ligados por E; where com closure = subgrupo OU)
    class Consulta
    {
        private array $filtros = [];
        private array $ordem   = [];
        private ?int $limite   = null;
        private bool $distinta = false;
        private ?string $grupo = null;
        /** [tabela, coluna, coluna] de cada join(). */
        private array $juncoes = [];
        /** [coluna, direção] do orderByRaw('min(coluna) asc|desc'), ou null. */
        private ?array $ordemPeloMinimo = null;

        public function __construct(private string $tabela) {}

        public function where($coluna, $operador = null, $valor = null)
        {
            // where(function ($q) {...}): subgrupo com orWhere (OU de grupos E)
            if ($coluna instanceof \Closure) {
                $grupo = new GrupoDeFiltros($this->tabela);
                $coluna($grupo);
                $this->filtros[] = fn (array $linha) => $grupo->passa($linha);

                return $this;
            }
            if (func_num_args() === 2) {
                $valor    = $operador;
                $operador = '=';
            }
            Banco::exigirColuna($this->tabela, $coluna);
            $this->filtros[] = fn (array $linha) => static::compara($linha[$coluna] ?? null, $operador, $valor);

            return $this;
        }

        public function whereIn($coluna, array $valores) { Banco::exigirColuna($this->tabela, $coluna); $this->filtros[] = fn (array $linha) => in_array($linha[$coluna] ?? null, $valores, true); return $this; }
        public function whereNotIn($coluna, array $valores) { Banco::exigirColuna($this->tabela, $coluna); $this->filtros[] = fn (array $linha) => ($linha[$coluna] ?? null) !== null && !in_array($linha[$coluna], $valores, true); return $this; }
        // join(tabela, 'tabela.coluna', '=', 'outra.coluna'): inner join; os filtros e a ordem usam "tabela.coluna"
        public function join(string $tabela, string $primeira, string $operador, string $segunda) { $this->juncoes[] = [$tabela, $primeira, $segunda]; return $this; }
        // select('tabela.*'): com join, o get() devolve só as colunas da tabela principal (o único select usado com join)
        public function select(...$colunas) { return $this; }
        public function whereNull($coluna) { Banco::exigirColuna($this->tabela, $coluna); $this->filtros[] = fn (array $linha) => ($linha[$coluna] ?? null) === null; return $this; }
        public function whereNotNull($coluna) { Banco::exigirColuna($this->tabela, $coluna); $this->filtros[] = fn (array $linha) => ($linha[$coluna] ?? null) !== null; return $this; }
        public function orderBy($coluna, $direcao = 'asc') { Banco::exigirColuna($this->tabela, $coluna); $this->ordem[] = [$coluna, strtolower($direcao)]; return $this; }
        public function limit(int $quantos) { $this->limite = $quantos; return $this; }
        public function groupBy($coluna) { Banco::exigirColuna($this->tabela, $coluna); $this->grupo = $coluna; return $this; }

        // só "min(<coluna>) [asc|desc]", com groupBy (o que as classes do iFood usam); o resto lança, para não passar calado
        public function orderByRaw(string $expressao)
        {
            if (!preg_match('/^\s*min\((\w+)\)\s*(asc|desc)?\s*$/i', $expressao, $partes)) {
                throw new \LogicException("orderByRaw não suportado no stub: {$expressao}");
            }
            Banco::exigirColuna($this->tabela, $partes[1]);
            $this->ordemPeloMinimo = [$partes[1], strtolower($partes[2] ?? 'asc')];

            return $this;
        }

        public static function compara($atual, string $operador, $valor): bool
        {
            if ($valor === null) {
                return $operador === '=' ? $atual === null : $atual !== null;
            }
            // no SQL, NULL não é igual, diferente, maior nem menor que nada
            if ($atual === null) {
                return false;
            }
            if (is_bool($valor) || is_bool($atual)) {
                $atual = (int) (bool) $atual;
                $valor = (int) (bool) $valor;
            }

            return match ($operador) {
                '='        => $atual == $valor,
                '!=', '<>' => $atual != $valor,
                '<'        => $atual < $valor,
                '<='       => $atual <= $valor,
                '>'        => $atual > $valor,
                '>='       => $atual >= $valor,
            };
        }

        /** Linhas da tabela principal com as colunas das tabelas do join (inner: sem par, a linha sai), por id. */
        private function juntadas(array $base): array
        {
            $saida = [];
            foreach ($base as $id => $linha) {
                $junta = $linha;
                foreach ($linha as $coluna => $valor) {
                    $junta[$this->tabela . '.' . $coluna] = $valor;
                }
                foreach ($this->juncoes as [$tabela, $primeira, $segunda]) {
                    [$daJuntada, $daOutra] = str_starts_with($primeira, $tabela . '.') ? [$primeira, $segunda] : [$segunda, $primeira];
                    $chave = substr($daJuntada, strlen($tabela) + 1);
                    $par   = null;
                    foreach (Banco::linhasDe($tabela) as $outra) {
                        if (($outra[$chave] ?? null) !== null && ($outra[$chave] ?? null) === ($junta[$daOutra] ?? null)) {
                            $par = $outra;
                            break;
                        }
                    }
                    if ($par === null) {
                        continue 2;
                    }
                    foreach ($par as $coluna => $valor) {
                        $junta[$tabela . '.' . $coluna] = $valor;
                    }
                }
                $saida[$id] = $junta;
            }

            return $saida;
        }

        private function selecionadas(): array
        {
            $base   = Banco::$tabelas[$this->tabela] ?? [];
            $linhas = $this->juncoes ? $this->juntadas($base) : $base;
            $linhas = array_filter($linhas, function (array $linha) {
                foreach ($this->filtros as $filtro) {
                    if (!$filtro($linha)) {
                        return false;
                    }
                }

                return true;
            });
            // uasort é estável: ordenar da última chave para a primeira dá a ordem de várias colunas
            foreach (array_reverse($this->ordem) as [$coluna, $direcao]) {
                uasort($linhas, fn ($a, $b) => $direcao === 'desc' ? (($b[$coluna] ?? null) <=> ($a[$coluna] ?? null)) : (($a[$coluna] ?? null) <=> ($b[$coluna] ?? null)));
            }

            $linhas = $this->limite === null ? $linhas : array_slice($linhas, 0, $this->limite, true);

            // com join, devolve as linhas da tabela principal, na ordem já calculada
            if ($this->juncoes) {
                $principais = [];
                foreach (array_keys($linhas) as $id) {
                    $principais[$id] = $base[$id];
                }

                return $principais;
            }

            return $linhas;
        }

        public function get($colunas = ['*']) { return new \Illuminate\Support\Collection(array_values(array_map(fn ($linha) => (object) $linha, $this->selecionadas()))); }
        public function first() { $linhas = $this->selecionadas(); return $linhas ? (object) reset($linhas) : null; }
        public function distinct() { $this->distinta = true; return $this; }

        public function pluck($coluna, $chave = null)
        {
            Banco::exigirColuna($this->tabela, $coluna);
            // pluck('coluna', 'chave'): um valor por chave (o pedido por uuid)
            if ($chave !== null) {
                Banco::exigirColuna($this->tabela, $chave);
                $porChave = [];
                foreach ($this->selecionadas() as $linha) {
                    $porChave[$linha[$chave] ?? ''] = $linha[$coluna] ?? null;
                }

                return new \Illuminate\Support\Collection($porChave);
            }
            if ($this->grupo !== null) {
                return new \Illuminate\Support\Collection($this->agrupadas($coluna));
            }
            if (!$this->distinta) {
                return new \Illuminate\Support\Collection(array_values(array_map(fn ($linha) => $linha[$coluna] ?? null, $this->selecionadas())));
            }
            // select distinct: o limit vale depois de tirar os repetidos, como no SQL
            $limite       = $this->limite;
            $this->limite = null;
            $valores      = array_values(array_unique(array_map(fn ($linha) => $linha[$coluna] ?? null, $this->selecionadas()), SORT_REGULAR));
            $this->limite = $limite;

            return new \Illuminate\Support\Collection($limite === null ? $valores : array_slice($valores, 0, $limite));
        }
        /** group by: um valor por grupo, na ordem do orderByRaw('min(...)') se houver; o limit vale depois de agrupar. */
        private function agrupadas(string $coluna): array
        {
            if ($coluna !== $this->grupo) {
                // como o ONLY_FULL_GROUP_BY do MySQL 8
                throw new \LogicException("pluck({$coluna}) com groupBy({$this->grupo})");
            }
            $limite       = $this->limite;
            $this->limite = null;
            $linhas       = $this->selecionadas();
            $this->limite = $limite;

            // valor do grupo => [valor, mínimo da coluna do orderByRaw]
            $grupos = [];
            foreach ($linhas as $linha) {
                $chave = (string) ($linha[$coluna] ?? '');
                $valor = $this->ordemPeloMinimo ? ($linha[$this->ordemPeloMinimo[0]] ?? null) : null;
                if (!isset($grupos[$chave])) {
                    $grupos[$chave] = [$linha[$coluna] ?? null, $valor];
                } elseif ($valor !== null && ($grupos[$chave][1] === null || $valor < $grupos[$chave][1])) {
                    $grupos[$chave][1] = $valor;
                }
            }
            if ($this->ordemPeloMinimo) {
                $direcao = $this->ordemPeloMinimo[1];
                uasort($grupos, fn ($a, $b) => $direcao === 'desc' ? ($b[1] <=> $a[1]) : ($a[1] <=> $b[1]));
            }
            $valores = array_values(array_map(fn ($par) => $par[0], $grupos));

            return $limite === null ? $valores : array_slice($valores, 0, $limite);
        }

        public function value($coluna) { Banco::exigirColuna($this->tabela, $coluna); $linha = $this->first(); return $linha ? ($linha->$coluna ?? null) : null; }
        public function exists(): bool { return (bool) $this->selecionadas(); }
        public function count(): int { return count($this->selecionadas()); }

        public function insert(array $linhas): bool
        {
            foreach (static::emLista($linhas) as $linha) {
                Banco::inserir($this->tabela, $linha, false);
            }

            return true;
        }

        /** Como o insertGetId do query builder: insere uma linha e devolve o id (o inserir() já atribui o sequencial por tabela). */
        public function insertGetId(array $linha): int
        {
            Banco::inserir($this->tabela, $linha, false);

            return (int) Banco::$proximoId[$this->tabela];
        }

        public function insertOrIgnore(array $linhas): int
        {
            $inseridas = 0;
            foreach (static::emLista($linhas) as $linha) {
                $inseridas += (int) Banco::inserir($this->tabela, $linha, true);
            }

            return $inseridas;
        }

        public function update(array $valores): int
        {
            if (isset(Banco::$falhar[$this->tabela])) {
                throw new ErroDeBanco(Banco::$falhar[$this->tabela]);
            }
            foreach (array_keys($valores) as $coluna) {
                Banco::exigirColuna($this->tabela, $coluna);
            }
            // todas as linhas novas são conferidas antes de gravar (o update do MySQL é atômico): uma chave única repetida,
            // com as outras linhas ou entre as próprias linhas alteradas, lança o mesmo erro do insert (23000 / 1062)
            $novas = Banco::$tabelas[$this->tabela] ?? [];
            foreach (array_keys($this->selecionadas()) as $id) {
                $novas[$id] = array_merge($novas[$id], $valores);
                $outras     = $novas;
                unset($outras[$id]);
                $repetida = Banco::colunaRepetida($this->tabela, $novas[$id], $outras);
                if ($repetida !== null) {
                    throw Banco::erroDeRepetida($this->tabela, $repetida, $novas[$id][$repetida]);
                }
            }
            $alteradas = 0;
            foreach (array_keys($this->selecionadas()) as $id) {
                Banco::$tabelas[$this->tabela][$id] = $novas[$id];
                $alteradas++;
            }

            return $alteradas;
        }

        public function delete(): int
        {
            if (isset(Banco::$falhar[$this->tabela])) {
                throw new ErroDeBanco(Banco::$falhar[$this->tabela]);
            }
            $apagadas = 0;
            foreach (array_keys($this->selecionadas()) as $id) {
                unset(Banco::$tabelas[$this->tabela][$id]);
                $apagadas++;
            }

            return $apagadas;
        }

        private static function emLista(array $linhas): array
        {
            if (!$linhas) {
                return [];
            }

            return array_is_list($linhas) && is_array($linhas[0]) ? $linhas : [$linhas];
        }
    }

    // o que o response()->json() devolve
    class RespostaJson
    {
        public function __construct(public $dados, public int $status = 200) {}
    }

    class FabricaDeResposta
    {
        public function json($dados = [], int $status = 200) { return new RespostaJson($dados, $status); }
        // o macro apiError do Fleetbase: {"error": "..."} (400 por padrão)
        public function apiError($mensagem, int $status = 400) { return new RespostaJson(['error' => $mensagem], $status); }
    }
}

namespace {
    // o relógio em hora de Brasília (o fuso do app)
    function now(): \Illuminate\Support\Carbon { return \Illuminate\Support\Carbon::parse(\Teste\Relogio::$agora); }
    function config($chave, $padrao = null) { return array_key_exists($chave, \Teste\Config::$valores) ? \Teste\Config::$valores[$chave] : $padrao; }
    // como o Encrypter do Laravel, o texto muda a cada chamada (nonce aleatório): quem compara tokens cifrados do banco
    // precisa comparar o texto lido, não cifrar de novo
    function encrypt($valor) { return 'cifrado:' . bin2hex(random_bytes(8)) . ':' . base64_encode(serialize($valor)); }

    function decrypt($valor)
    {
        $partes = is_string($valor) ? explode(':', $valor, 3) : [];
        if (count($partes) !== 3 || $partes[0] !== 'cifrado' || !preg_match('/^[0-9a-f]{16}$/', $partes[1])) {
            // como o Encrypter com APP_KEY trocado ou texto corrompido
            throw new \Illuminate\Contracts\Encryption\DecryptException('The MAC is invalid.');
        }

        return unserialize(base64_decode($partes[2]));
    }

    function session($chave = null)
    {
        if (is_array($chave)) {
            \Teste\Sessao::$dados = array_merge(\Teste\Sessao::$dados, $chave);

            return null;
        }

        return $chave === null ? \Teste\Sessao::$dados : (\Teste\Sessao::$dados[$chave] ?? null);
    }

    function response() { return new \Teste\FabricaDeResposta(); }

    // classes do App\ saem do api/app do repositório
    spl_autoload_register(function (string $classe) {
        if (str_starts_with($classe, 'App\\')) {
            $arquivo = '/repo/api/app/' . str_replace('\\', '/', substr($classe, 4)) . '.php';
            if (is_file($arquivo)) {
                require $arquivo;
            }
        }
    });

    /** Zera o estado entre os casos e liga a integração com credenciais de teste. */
    function reiniciarIfood(): void
    {
        \Teste\Banco::limpar();
        \Illuminate\Support\Facades\Cache::$dados     = [];
        \Illuminate\Support\Facades\Cache::$validades = [];
        \Illuminate\Support\Facades\Log::$registros   = [];
        \Teste\Trava::$ocupadas                       = [];
        \Teste\Http::$respostas                       = [];
        \Teste\Http::$chamadas                        = [];
        \Teste\Fila::$jobs                            = [];
        \Teste\Fila::$falhar                          = null;
        \Teste\DespachoPendente::$atrasos             = [];
        \Teste\Socket::$transmitidos                  = [];
        \Teste\Socket::$falhar                        = false;
        \Teste\Socket::$tentativas                    = 0;
        \Teste\Sessao::$dados                         = [];
        \Teste\Relogio::$agora                        = '2026-10-05 15:00:00';
        \Teste\Config::$valores                       = [
            'app.timezone'                 => 'America/Sao_Paulo',
            'services.ifood.ativo'         => '1',
            'services.ifood.client_id'     => 'cliente-teste',
            'services.ifood.client_secret' => 'segredo-teste',
            'services.ifood.base_url'      => 'https://merchant-api.ifood.com.br',
        ];
    }

    /** Algum log cuja mensagem contém $trecho (com o nível, se dado). */
    function logou(string $trecho, ?string $nivel = null): bool
    {
        foreach (\Illuminate\Support\Facades\Log::$registros as [$nivelDoLog, $mensagem]) {
            if (str_contains($mensagem, $trecho) && ($nivel === null || $nivel === $nivelDoLog)) {
                return true;
            }
        }

        return false;
    }

    /** Nenhum log (mensagem e contexto) contém algum dos textos (tokens, nome, telefone, endereço). */
    function logsSem(array $textos): bool
    {
        $tudo = json_encode(\Illuminate\Support\Facades\Log::$registros, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($textos as $texto) {
            if (str_contains($tudo, $texto)) {
                return false;
            }
        }

        return true;
    }

    $GLOBALS['falhas'] = 0;

    function confere(bool $ok, string $descricao): void
    {
        if (!$ok) {
            $GLOBALS['falhas']++;
        }
        echo ($ok ? 'PASSA ' : 'FALHA ') . $descricao . PHP_EOL;
    }

    /** Roda $fazer e devolve a exceção lançada (ou null). */
    function excecao(callable $fazer): ?\Throwable
    {
        try {
            $fazer();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    function resumo(): void
    {
        echo PHP_EOL . 'FALHAS: ' . $GLOBALS['falhas'] . PHP_EOL;
    }
}
