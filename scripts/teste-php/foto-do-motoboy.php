<?php

// Foto do motoboy trocada ou removida pelo app (PUT v1/drivers/{id} com `photo`; DriverControllerSemGeocodificacao +
// FotoDoMotoboy): a foto antiga sai do disco e ganha `deleted_at` em `files`, só quando nada mais a usa; "REMOVE" tira
// o avatar. Nunca um DELETE em `files` (ON DELETE CASCADE apagaria o usuário, a empresa ou a loja).
// Também confere, nas migrations reais (cópias em packages/), que toda chave estrangeira para `files` está nas
// REFERENCIAS, e no update() do fleetops-api 0.6.65 que a foto ainda vai só para o `users.avatar_uuid`.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/foto-do-motoboy.php

namespace Teste {
    /** Banco em memória: tabelas como listas de linhas (arrays). */
    class Banco
    {
        public static array $tabelas  = [];
        public static array $comandos = [];
        public static bool $falhar    = false;
    }

    class Consulta
    {
        private array $filtros = [];

        public function __construct(private string $tabela)
        {
        }

        public function where(string $coluna, $valor): self
        {
            $this->filtros[] = fn ($l) => ($l[$coluna] ?? null) === $valor;

            return $this;
        }

        public function whereNull(string $coluna): self
        {
            $this->filtros[] = fn ($l) => ($l[$coluna] ?? null) === null;

            return $this;
        }

        private function linhas(): array
        {
            if (Banco::$falhar) {
                throw new \RuntimeException('banco fora do ar');
            }
            $todas = Banco::$tabelas[$this->tabela] ?? [];

            return array_filter($todas, function ($l) {
                foreach ($this->filtros as $f) {
                    if (!$f($l)) {
                        return false;
                    }
                }

                return true;
            });
        }

        public function value(string $coluna)
        {
            $l = array_values($this->linhas())[0] ?? null;

            return $l[$coluna] ?? null;
        }

        public function first(array $colunas = [])
        {
            $l = array_values($this->linhas())[0] ?? null;

            return $l === null ? null : (object) $l;
        }

        public function exists(): bool
        {
            return $this->linhas() !== [];
        }

        public function update(array $valores): int
        {
            $n = 0;
            foreach ($this->linhas() as $i => $l) {
                Banco::$tabelas[$this->tabela][$i] = $valores + $l;
                $n++;
            }
            Banco::$comandos[] = ['update', $this->tabela, $valores];

            return $n;
        }

        public function delete(): int
        {
            Banco::$comandos[] = ['delete', $this->tabela];
            throw new \LogicException('DELETE não pode acontecer');
        }
    }

    class Disco
    {
        public static array $apagados = [];

        public function __construct(public string $nome)
        {
        }

        public function delete(string $caminho): bool
        {
            static::$apagados[] = $this->nome . ':' . $caminho;

            return true;
        }
    }
}

namespace Illuminate\Support\Facades {
    class DB
    {
        public static function table(string $tabela): \Teste\Consulta
        {
            return new \Teste\Consulta($tabela);
        }
    }

    class Schema
    {
        public static function hasTable(string $tabela): bool
        {
            return array_key_exists($tabela, \Teste\Banco::$tabelas);
        }
    }

    class Storage
    {
        public static function disk(string $nome): \Teste\Disco
        {
            return new \Teste\Disco($nome);
        }
    }

    class Cache
    {
        public static array $esquecidos = [];

        public static function forget(string $chave): bool
        {
            static::$esquecidos[] = $chave;

            return true;
        }
    }

    class Log
    {
        public static array $linhas = [];

        public static function info(string $mensagem, array $contexto = []): void
        {
            static::$linhas[] = ['info', $mensagem, $contexto];
        }

        public static function warning(string $mensagem, array $contexto = []): void
        {
            static::$linhas[] = ['warning', $mensagem, $contexto];
        }
    }
}

namespace Illuminate\Database\Eloquent {
    class ModelNotFoundException extends \RuntimeException
    {
    }
}

namespace Illuminate\Http {
    class Request
    {
        public function __construct(public array $dados = [])
        {
        }

        public function input(string $chave)
        {
            return $this->dados[$chave] ?? null;
        }

        public function has(string $chave): bool
        {
            return array_key_exists($chave, $this->dados);
        }
    }

    class JsonResponse
    {
        public function __construct(public int $status)
        {
        }

        public function getStatusCode(): int
        {
            return $this->status;
        }
    }
}

namespace Fleetbase\FleetOps\Http\Requests {
    class UpdateDriverRequest extends \Illuminate\Http\Request
    {
    }
}

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1 {
    use Fleetbase\FleetOps\Http\Requests\UpdateDriverRequest;
    use Teste\Banco;

    /** O update() do Fleet-Ops reduzido ao que importa: foto em data URI vira um File novo e o avatar do usuário. */
    class DriverController
    {
        public int $updates = 0;

        public function update($id, UpdateDriverRequest $request)
        {
            $this->updates++;
            if ($id === 'driver_sumido') {
                return new \Illuminate\Http\JsonResponse(404);
            }
            $foto = $request->input('photo');
            if (is_string($foto) && str_starts_with($foto, 'data:image')) {
                Banco::$tabelas['files'][] = ['uuid' => 'arq-novo', 'disk' => 'public', 'path' => 'uploads/emp/drivers/novo.png', 'deleted_at' => null];
                foreach (Banco::$tabelas['users'] as $i => $u) {
                    if ($u['uuid'] === 'usr-1') {
                        Banco::$tabelas['users'][$i]['avatar_uuid'] = 'arq-novo';
                    }
                }
            }

            return 'recurso do Fleet-Ops';
        }

        protected function findDriver(string $id, array $with = [])
        {
            return 'motoboy relido ' . $id;
        }

        protected function driverResource($driver)
        {
            return 'recurso ' . $driver;
        }

        protected function apiError(string $message, int $status = 400)
        {
            return "erro {$status}: {$message}";
        }
    }
}

namespace {
    use App\Http\Controllers\Entregas\DriverControllerSemGeocodificacao;
    use App\Support\Entregas\FotoDoMotoboy;
    use Fleetbase\FleetOps\Http\Requests\UpdateDriverRequest;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\Log;
    use Teste\Banco;
    use Teste\Disco;

    $GLOBALS['falhas'] = 0;

    function confere(bool $ok, string $descricao): void
    {
        if (!$ok) {
            $GLOBALS['falhas']++;
        }
        echo ($ok ? 'PASSA ' : 'FALHA ') . $descricao . PHP_EOL;
    }

    function config(string $chave, $padrao = null)
    {
        return $chave === 'filesystems.default' ? 'public' : $padrao;
    }

    function now(): string
    {
        return '2026-10-12 10:00:00';
    }

    require '/repo/api/app/Support/Entregas/GeocoderDesligado.php';
    require '/repo/api/app/Support/Entregas/FiltroDePosicaoDoMotoboy.php';
    require '/repo/api/app/Support/Entregas/FotoDoMotoboy.php';
    require '/repo/api/app/Http/Controllers/Entregas/DriverControllerSemGeocodificacao.php';

    /** Motoboy driver_a (usuário usr-1) com a foto arq-velho; as tabelas de REFERENCIAS existem, vazias. */
    function cenario(?string $avatar = 'arq-velho'): void
    {
        Banco::$tabelas = array_fill_keys(array_keys(FotoDoMotoboy::REFERENCIAS), []);
        Banco::$tabelas['drivers'] = [['uuid' => 'drv-1', 'public_id' => 'driver_a', 'user_uuid' => 'usr-1', 'deleted_at' => null]];
        Banco::$tabelas['users']   = [['uuid' => 'usr-1', 'avatar_uuid' => $avatar]];
        Banco::$tabelas['files']   = [['uuid' => 'arq-velho', 'disk' => 'public', 'path' => 'uploads/emp/drivers/velho.png', 'deleted_at' => null]];
        Banco::$comandos  = [];
        Banco::$falhar    = false;
        Disco::$apagados  = [];
        Cache::$esquecidos = [];
        Log::$linhas      = [];
    }

    function arquivo(string $uuid): ?array
    {
        foreach (Banco::$tabelas['files'] as $l) {
            if ($l['uuid'] === $uuid) {
                return $l;
            }
        }

        return null;
    }

    function avatar(): ?string
    {
        return Banco::$tabelas['users'][0]['avatar_uuid'];
    }

    function pedido(array $dados): UpdateDriverRequest
    {
        return new UpdateDriverRequest($dados);
    }

    $foto = 'data:image/jpeg;base64,QUJD';

    echo '== Troca: a foto antiga sai do disco e ganha deleted_at' . PHP_EOL;
    cenario();
    $resposta = (new DriverControllerSemGeocodificacao())->update('driver_a', pedido(['photo' => $foto]));
    confere($resposta === 'recurso do Fleet-Ops', 'devolve a resposta do Fleet-Ops');
    confere(avatar() === 'arq-novo', 'o avatar é a foto nova');
    confere(Disco::$apagados === ['public:uploads/emp/drivers/velho.png'], 'a antiga saiu do disco');
    confere(arquivo('arq-velho')['deleted_at'] === '2026-10-12 10:00:00', 'a linha da antiga ganhou deleted_at');
    confere(arquivo('arq-novo')['deleted_at'] === null, 'a nova ficou intacta');
    confere(in_array('file_url_arq-velho', Cache::$esquecidos, true), 'o cache da URL antiga foi esquecido');

    echo '== Remoção ("REMOVE"): avatar nulo, antiga apagada, motoboy relido' . PHP_EOL;
    cenario();
    $resposta = (new DriverControllerSemGeocodificacao())->update('driver_a', pedido(['photo' => 'REMOVE']));
    confere(avatar() === null, 'o avatar ficou nulo');
    confere(Disco::$apagados === ['public:uploads/emp/drivers/velho.png'], 'a antiga saiu do disco');
    confere(arquivo('arq-velho')['deleted_at'] !== null, 'a linha da antiga ganhou deleted_at');
    confere($resposta === 'recurso motoboy relido driver_a', 'devolve o motoboy relido (sem a foto)');

    echo '== Remoção sem foto: nada a apagar' . PHP_EOL;
    cenario(null);
    $resposta = (new DriverControllerSemGeocodificacao())->update('driver_a', pedido(['photo' => 'REMOVE']));
    confere(avatar() === null && Disco::$apagados === [], 'nenhum arquivo apagado');
    confere($resposta === 'recurso motoboy relido driver_a', 'responde com o motoboy');

    echo '== Foto que o Fleet-Ops não gravou (base64 puro): nada muda' . PHP_EOL;
    cenario();
    (new DriverControllerSemGeocodificacao())->update('driver_a', pedido(['photo' => 'QUJD']));
    confere(avatar() === 'arq-velho', 'o avatar continua o antigo');
    confere(Disco::$apagados === [] && arquivo('arq-velho')['deleted_at'] === null, 'a antiga não foi apagada');

    echo '== Sem photo no pedido: só o Fleet-Ops' . PHP_EOL;
    cenario();
    $c = new DriverControllerSemGeocodificacao();
    $resposta = $c->update('driver_a', pedido(['name' => 'Fulano']));
    confere($c->updates === 1 && $resposta === 'recurso do Fleet-Ops', 'chama o update original uma vez');
    confere(Banco::$comandos === [] && Disco::$apagados === [], 'não mexe em foto nenhuma');

    echo '== Erro do Fleet-Ops: não apaga nada' . PHP_EOL;
    cenario();
    Banco::$tabelas['drivers'][] = ['uuid' => 'drv-x', 'public_id' => 'driver_sumido', 'user_uuid' => 'usr-1', 'deleted_at' => null];
    $resposta = (new DriverControllerSemGeocodificacao())->update('driver_sumido', pedido(['photo' => 'REMOVE']));
    confere($resposta instanceof \Illuminate\Http\JsonResponse && $resposta->getStatusCode() === 404, 'devolve o erro original');
    confere(avatar() === 'arq-velho' && Disco::$apagados === [], 'avatar e arquivo intactos');

    echo '== Arquivo antigo ainda em uso: fica no disco e sem deleted_at' . PHP_EOL;
    foreach ([['vendors', 'logo_uuid'], ['users', 'avatar_uuid'], ['companies', 'backdrop_uuid'], ['contacts', 'photo_uuid']] as [$tabela, $coluna]) {
        cenario();
        Banco::$tabelas[$tabela][] = [$coluna => 'arq-velho', 'uuid' => 'outro'];
        (new DriverControllerSemGeocodificacao())->update('driver_a', pedido(['photo' => $foto]));
        confere(avatar() === 'arq-novo', "{$tabela}.{$coluna}: a foto nova vale");
        confere(Disco::$apagados === [] && arquivo('arq-velho')['deleted_at'] === null, "{$tabela}.{$coluna}: a antiga fica");
    }

    echo '== Tabela que não existe não conta como uso' . PHP_EOL;
    cenario();
    unset(Banco::$tabelas['registry_extensions'], Banco::$tabelas['registry_extension_bundles']);
    (new DriverControllerSemGeocodificacao())->update('driver_a', pedido(['photo' => $foto]));
    confere(Disco::$apagados === ['public:uploads/emp/drivers/velho.png'], 'apaga a antiga');

    echo '== Banco fora ao conferir o uso: não apaga e não derruba a troca' . PHP_EOL;
    cenario();
    Banco::$falhar = true;
    $ok = FotoDoMotoboy::apagarArquivo('arq-velho');
    Banco::$falhar = false;
    confere($ok === false && Disco::$apagados === [], 'devolve false sem apagar');
    confere(str_contains(Log::$linhas[0][1] ?? '', 'arquivo antigo não apagado: RuntimeException'), 'registra só a classe do erro');

    echo '== Nunca um DELETE em files' . PHP_EOL;
    $codigo = file_get_contents('/repo/api/app/Support/Entregas/FotoDoMotoboy.php');
    confere(!preg_match('/->delete\(\s*\)/', $codigo), 'FotoDoMotoboy não chama delete() no banco');
    confere(!preg_match('/forceDelete/', $codigo), 'nem forceDelete');

    echo '== pedidoDeRemocao' . PHP_EOL;
    confere(FotoDoMotoboy::pedidoDeRemocao('REMOVE') && FotoDoMotoboy::pedidoDeRemocao(' remove ') && FotoDoMotoboy::pedidoDeRemocao('') && FotoDoMotoboy::pedidoDeRemocao(null), 'REMOVE, vazio e nulo removem');
    confere(!FotoDoMotoboy::pedidoDeRemocao($foto) && !FotoDoMotoboy::pedidoDeRemocao('file_abc'), 'foto e id de arquivo não removem');

    echo '== Toda chave estrangeira para files (migrations reais) está nas REFERENCIAS' . PHP_EOL;
    $faltando = [];
    $achadas  = 0;
    foreach (array_merge(glob('/repo/packages/*/migrations/*.php'), glob('/repo/packages/*/server/migrations/*.php')) as $migration) {
        $texto = file_get_contents($migration);
        if (!preg_match_all("/Schema::(?:table|create)\\('([a-z_]+)'.*?\\n\\s*\\}\\);/s", $texto, $blocos, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($blocos as [$bloco, $tabela]) {
            $padroes = [
                "/foreign\\(\\[?'([a-z_]+)'\\]?\\)->references\\(\\[?'uuid'\\]?\\)->on\\('files'\\)/",
                "/foreignUuid\\('([a-z_]+)'\\)[^;]*->on\\('files'\\)/",
            ];
            foreach ($padroes as $padrao) {
                preg_match_all($padrao, $bloco, $colunas);
                foreach ($colunas[1] as $coluna) {
                    $achadas++;
                    if (!in_array($coluna, FotoDoMotoboy::REFERENCIAS[$tabela] ?? [], true)) {
                        $faltando[] = "{$tabela}.{$coluna}";
                    }
                }
            }
        }
    }
    confere($achadas >= 12, "achou as chaves estrangeiras ({$achadas})");
    confere($faltando === [], 'nenhuma fora da lista' . ($faltando ? ': ' . implode(', ', $faltando) : ''));

    echo '== update() do fleetops-api 0.6.65 grava a foto só no avatar do usuário' . PHP_EOL;
    $driverController = file_get_contents('/repo/packages/fleetops/server/src/Http/Controllers/Api/v1/DriverController.php');
    preg_match('/public function update\(\$id, UpdateDriverRequest \$request\)\s*\{(.*?)\R    \}\R/s', $driverController, $update);
    $corpo = $update[1] ?? '';
    confere($corpo !== '', 'achou o update()');
    confere(str_contains($corpo, "\$driver->user->update(['avatar_uuid' => \$file->uuid])"), 'a foto vai para users.avatar_uuid');
    confere(substr_count($corpo, 'avatar_uuid') === 1 && !str_contains($corpo, 'photo_uuid'), 'e para nenhum outro lugar');
    foreach (['findDriver', 'driverResource', 'apiError'] as $metodo) {
        confere((bool) preg_match('/function ' . $metodo . '\(/', $driverController), "{$metodo} existe no DriverController");
    }

    echo PHP_EOL . 'FALHAS: ' . $GLOBALS['falhas'] . PHP_EOL;
}
