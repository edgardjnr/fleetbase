<?php

// Stubs dos testes da integração iFood (scripts/teste-php/ifood-*.php). Independentes dos outros stubs: aqui ficam o
// Http do Laravel (fila de respostas e registro das chamadas), o DB (tabelas em memória, com o pouco de query builder
// que as classes do iFood usam e as chaves únicas das tabelas novas), o Cache (com trava), o Log, o encrypt/decrypt, o
// relógio (now()), a fila de jobs, o Command, o Schedule e o Schema das migrations. Os models do Fleetbase (Order,
// Place, Payload, Vendor, OrderConfig) e o Request ficam no stubs-ifood-fleetbase.php.

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

            return new static((string) $valor, is_string($fuso) ? new \DateTimeZone($fuso) : ($fuso ?? new \DateTimeZone('UTC')));
        }

        public function addSeconds($n): static { $this->modify('+' . (int) $n . ' seconds'); return $this; }
        public function subSeconds($n): static { $this->modify('-' . (int) $n . ' seconds'); return $this; }
        public function addMinutes($n): static { $this->modify('+' . (int) $n . ' minutes'); return $this; }
        public function subMinutes($n): static { $this->modify('-' . (int) $n . ' minutes'); return $this; }
        public function subMinute(): static { return $this->subMinutes(1); }
        public function addHour(): static { $this->modify('+1 hour'); return $this; }
        public function subDays($n): static { $this->modify('-' . (int) $n . ' days'); return $this; }
        public function toDateTimeString(): string { return $this->format('Y-m-d H:i:s'); }
        public function toIso8601String(): string { return $this->format('Y-m-d\TH:i:sP'); }
    }
}

namespace Illuminate\Support\Facades {
    class DB
    {
        public static function table(string $tabela) { return new \Teste\Consulta($tabela); }

        // desfaz as escritas nas tabelas em memória se a função lançar (como o rollback do MySQL)
        public static function transaction(\Closure $fazer)
        {
            $copia = \Teste\Banco::$tabelas;
            try {
                return $fazer();
            } catch (\Throwable $e) {
                \Teste\Banco::$tabelas = $copia;
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
        public static function lock($nome, $segundos = 0) { return new \Teste\Trava($nome); }
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

namespace Illuminate\Bus {
    trait Queueable {}
}

namespace Illuminate\Queue {
    trait InteractsWithQueue
    {
        /** Segundos do release() (o job voltou para a fila), ou null. */
        public ?int $liberadoPor = null;
        public function release($atraso = 0) { $this->liberadoPor = (int) $atraso; }
    }
}

namespace Illuminate\Foundation\Bus {
    trait Dispatchable
    {
        public static function dispatch(...$argumentos) { \Teste\Fila::$jobs[] = new static(...$argumentos); return null; }
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
        public static string $agora = '2026-10-05 18:00:00';
    }

    class Config
    {
        public static array $valores = [];
    }

    class Sessao
    {
        public static array $dados = [];
    }

    class Fila
    {
        public static array $jobs = [];
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
                throw new \RuntimeException("trava ocupada: {$this->nome}");
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

            return new \Illuminate\Http\Client\Response(...$resposta);
        }
    }

    class ErroDeBanco extends \RuntimeException {}

    // tabelas em memória: nome => [id => linha]
    class Banco
    {
        public static array $tabelas = [];
        public static array $proximoId = [];
        /**
         * Colunas e colunas únicas das tabelas entregas_ifood_*, lidas das migrations (carregadas uma vez, na primeira
         * consulta): tabela => ['colunas' => [nomes], 'unicas' => [nomes]]. Assim um nome de coluna errado falha aqui,
         * como o "Unknown column" do MySQL, e a lista de únicas não se descola das migrations.
         */
        private static ?array $esquema = null;
        /** Tabela => mensagem: toda escrita nela lança ErroDeBanco (banco fora do ar). */
        public static array $falhar = [];

        public static function limpar(): void
        {
            self::$tabelas   = [];
            self::$proximoId = [];
            self::$falhar    = [];
        }

        /** O esquema das migrations do iFood: lê os arquivos uma vez, sem mexer no Schema::$criadas dos testes. */
        private static function esquema(): array
        {
            if (self::$esquema !== null) {
                return self::$esquema;
            }
            $guardadas = \Illuminate\Support\Facades\Schema::$criadas;
            self::$esquema = [];
            foreach (glob(dirname(__DIR__, 2) . '/api/database/migrations/*_create_entregas_ifood_*_table.php') ?: [] as $arquivo) {
                \Illuminate\Support\Facades\Schema::$criadas = [];
                (require $arquivo)->up();
                foreach (\Illuminate\Support\Facades\Schema::$criadas as $tabela => $blueprint) {
                    $colunas = [];
                    $unicas  = [];
                    foreach ($blueprint->colunas as $coluna) {
                        $primeiro = $coluna->argumentos[0] ?? null;
                        if ($coluna->tipo === 'timestamps') {
                            array_push($colunas, 'created_at', 'updated_at');
                        } elseif ($coluna->tipo === 'id') {
                            $colunas[] = $primeiro ?? 'id';
                        } elseif (is_string($primeiro)) {
                            // index([...]) e afins não são colunas (o primeiro argumento é uma lista)
                            $colunas[] = $primeiro;
                            if (array_key_exists('unique', $coluna->modificadores)) {
                                $unicas[] = $primeiro;
                            }
                        }
                    }
                    self::$esquema[$tabela] = ['colunas' => $colunas, 'unicas' => $unicas];
                }
            }
            \Illuminate\Support\Facades\Schema::$criadas = $guardadas;

            return self::$esquema;
        }

        /** Colunas únicas da tabela (NULL não conta, como no MySQL); vazio para tabela fora do esquema do iFood. */
        public static function unicasDe(string $tabela): array
        {
            return self::esquema()[$tabela]['unicas'] ?? [];
        }

        /** Lança como o MySQL ("Unknown column") se a coluna não existe na tabela entregas_ifood_*; as outras tabelas passam. */
        public static function exigirColuna(string $tabela, $coluna): void
        {
            $colunas = self::esquema()[$tabela]['colunas'] ?? null;
            if ($colunas !== null && is_string($coluna) && $coluna !== '*' && !in_array($coluna, $colunas, true)) {
                throw new \RuntimeException("coluna desconhecida {$coluna} em {$tabela}");
            }
        }

        /** As linhas da tabela, como objetos (o que o DB::table()->get() devolve). */
        public static function linhas(string $tabela): array
        {
            return array_values(array_map(fn ($linha) => (object) $linha, self::$tabelas[$tabela] ?? []));
        }

        public static function inserir(string $tabela, array $linha, bool $ignorarRepetida): bool
        {
            if (isset(self::$falhar[$tabela])) {
                throw new ErroDeBanco(self::$falhar[$tabela]);
            }
            foreach (array_keys($linha) as $coluna) {
                self::exigirColuna($tabela, $coluna);
            }
            foreach (self::unicasDe($tabela) as $coluna) {
                $valor = $linha[$coluna] ?? null;
                if ($valor === null) {
                    continue;
                }
                foreach (self::$tabelas[$tabela] ?? [] as $existente) {
                    if (($existente[$coluna] ?? null) === $valor) {
                        if ($ignorarRepetida) {
                            return false;
                        }
                        throw new ErroDeBanco("Duplicate entry '{$valor}' for key '{$tabela}.{$coluna}'");
                    }
                }
            }
            $id                             = self::$proximoId[$tabela] = (self::$proximoId[$tabela] ?? 0) + 1;
            self::$tabelas[$tabela][$id] = ['id' => $id] + $linha;

            return true;
        }
    }

    // o query builder do DB::table, só no que as classes do iFood usam (todos os where ligados por E)
    class Consulta
    {
        private array $filtros = [];
        private array $ordem   = [];
        private ?int $limite   = null;

        public function __construct(private string $tabela) {}

        public function where($coluna, $operador = null, $valor = null)
        {
            if (func_num_args() === 2) {
                $valor    = $operador;
                $operador = '=';
            }
            Banco::exigirColuna($this->tabela, $coluna);
            $this->filtros[] = fn (array $linha) => static::compara($linha[$coluna] ?? null, $operador, $valor);

            return $this;
        }

        public function whereIn($coluna, array $valores) { Banco::exigirColuna($this->tabela, $coluna); $this->filtros[] = fn (array $linha) => in_array($linha[$coluna] ?? null, $valores, true); return $this; }
        public function whereNull($coluna) { Banco::exigirColuna($this->tabela, $coluna); $this->filtros[] = fn (array $linha) => ($linha[$coluna] ?? null) === null; return $this; }
        public function whereNotNull($coluna) { Banco::exigirColuna($this->tabela, $coluna); $this->filtros[] = fn (array $linha) => ($linha[$coluna] ?? null) !== null; return $this; }
        public function orderBy($coluna, $direcao = 'asc') { Banco::exigirColuna($this->tabela, $coluna); $this->ordem[] = [$coluna, strtolower($direcao)]; return $this; }
        public function limit(int $quantos) { $this->limite = $quantos; return $this; }

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

        private function selecionadas(): array
        {
            $linhas = array_filter(Banco::$tabelas[$this->tabela] ?? [], function (array $linha) {
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

            return $this->limite === null ? $linhas : array_slice($linhas, 0, $this->limite, true);
        }

        public function get($colunas = ['*']) { return new \Illuminate\Support\Collection(array_values(array_map(fn ($linha) => (object) $linha, $this->selecionadas()))); }
        public function first() { $linhas = $this->selecionadas(); return $linhas ? (object) reset($linhas) : null; }
        public function pluck($coluna) { Banco::exigirColuna($this->tabela, $coluna); return new \Illuminate\Support\Collection(array_values(array_map(fn ($linha) => $linha[$coluna] ?? null, $this->selecionadas()))); }
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
            $alteradas = 0;
            foreach (array_keys($this->selecionadas()) as $id) {
                Banco::$tabelas[$this->tabela][$id] = array_merge(Banco::$tabelas[$this->tabela][$id], $valores);
                $alteradas++;
            }

            return $alteradas;
        }

        public function delete(): int
        {
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
    }
}

namespace {
    function now(): \Illuminate\Support\Carbon { return \Illuminate\Support\Carbon::parse(\Teste\Relogio::$agora, 'UTC'); }
    function config($chave, $padrao = null) { return array_key_exists($chave, \Teste\Config::$valores) ? \Teste\Config::$valores[$chave] : $padrao; }
    function encrypt($valor) { return 'cifrado:' . base64_encode(serialize($valor)); }

    function decrypt($valor)
    {
        if (!is_string($valor) || !str_starts_with($valor, 'cifrado:')) {
            throw new \RuntimeException('decrypt: valor que não foi cifrado');
        }

        return unserialize(base64_decode(substr($valor, 8)));
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
        \Teste\Sessao::$dados                         = [];
        \Teste\Relogio::$agora                        = '2026-10-05 18:00:00';
        \Teste\Config::$valores                       = [
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
