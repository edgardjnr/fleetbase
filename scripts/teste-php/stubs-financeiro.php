<?php

// Stubs dos testes do financeiro (scripts/teste-php/financeiro-*.php), sobre os do iFood (banco em memória com o
// esquema lido das migrations, Log, fila de jobs, relógio, models do Fleetbase): o Setting e a Company do core, o Str
// (uuid), o Storage (disco 'local'), o UploadedFile e o URL (link assinado do comprovante).

namespace {
    require_once __DIR__ . '/stubs-ifood.php';
    require_once __DIR__ . '/stubs-ifood-fleetbase.php';
}

namespace Fleetbase\Models {
    if (!class_exists(Setting::class, false)) {
        // o Setting do core: lookup pela chave inteira; lookupCompany pela empresa da sessão
        class Setting
        {
            public static array $valores = [];
            public static function lookup(string $chave, $padrao = null) { return array_key_exists($chave, self::$valores) ? self::$valores[$chave] : $padrao; }
            public static function lookupCompany(string $chave, $padrao = null) { return session('company') ? self::lookup('company.' . session('company') . '.' . $chave, $padrao) : $padrao; }
            public static function configure(string $chave, $valor = null) { self::$valores[$chave] = $valor; return null; }
        }
    }

    if (!class_exists(Company::class, false)) {
        // Company::where('uuid', x)->value('timezone'): o fuso de Company::$fusos (null = sem fuso)
        class Company
        {
            public static array $fusos = [];
            public static function where($coluna, $valor) { return new \Teste\FusoDaEmpresa(self::$fusos[$valor] ?? null); }
        }
    }
}

namespace Illuminate\Support {
    if (!class_exists(Str::class, false)) {
        class Str
        {
            public static int $gerados = 0;
            // uuid previsível nos testes: 00000000-0000-4000-8000-<sequencial>
            public static function uuid() { return sprintf('00000000-0000-4000-8000-%012d', ++self::$gerados); }
        }
    }
}

namespace Illuminate\Support\Facades {
    if (!class_exists(Storage::class, false)) {
        class Storage
        {
            public static function disk(string $nome) { return new \Teste\Disco($nome); }
        }
    }

    if (!class_exists(URL::class, false)) {
        // o link assinado: guarda o pedido e devolve uma URL com expires e signature falsos
        class URL
        {
            public static array $assinadas = [];

            public static function temporarySignedRoute($nome, $expira, $parametros = [])
            {
                self::$assinadas[] = ['nome' => $nome, 'expira' => $expira, 'parametros' => $parametros];

                return 'https://api.teste/v1/entregas/comprovantes/' . ($parametros['uuid'] ?? '') . '?expires=' . $expira->getTimestamp() . '&signature=assinatura';
            }
        }
    }
}

namespace Illuminate\Http {
    if (!class_exists(UploadedFile::class, false)) {
        // o arquivo enviado: nome do cliente, tipo detectado no servidor (getMimeType) e tamanho em bytes
        class UploadedFile
        {
            public function __construct(public string $nome, public string $tipo, public int $tamanho) {}
            public function getClientOriginalName() { return $this->nome; }
            public function getClientOriginalExtension() { return pathinfo($this->nome, PATHINFO_EXTENSION); }
            public function getMimeType() { return $this->tipo; }
            public function getSize() { return $this->tamanho; }
        }
    }
}

namespace Teste {
    class FusoDaEmpresa
    {
        public function __construct(private ?string $fuso) {}
        public function value($coluna) { return $coluna === 'timezone' ? $this->fuso : null; }
    }

    // o disco do Storage: arquivos gravados (disco:caminho => nome original) e apagados; com $falhar, putFileAs devolve false
    class Disco
    {
        public static array $arquivos = [];
        public static array $apagados = [];
        public static bool $falhar    = false;

        public function __construct(public string $nome) {}

        public function putFileAs($pasta, $arquivo, $nome)
        {
            if (self::$falhar) {
                return false;
            }
            $caminho                                   = trim($pasta, '/') . '/' . $nome;
            self::$arquivos[$this->nome . ':' . $caminho] = $arquivo->getClientOriginalName();

            return $caminho;
        }

        public function delete($caminho)
        {
            self::$apagados[] = $this->nome . ':' . $caminho;
            unset(self::$arquivos[$this->nome . ':' . $caminho]);

            return true;
        }

        public function exists($caminho) { return isset(self::$arquivos[$this->nome . ':' . $caminho]); }

        // o FilesystemAdapter::response (o comprovante servido pelo ComprovantesController): devolve o que seria enviado
        public function response($caminho, $nome = null, array $cabecalhos = [], $disposicao = 'inline')
        {
            return (object) ['disco' => $this->nome, 'caminho' => $caminho, 'nome' => $nome, 'cabecalhos' => $cabecalhos, 'disposicao' => $disposicao];
        }
    }
}

namespace {
    /** Zera tudo e liga o financeiro na empresa-1 com corte em 2026-10-01 (fuso de Brasília). */
    function reiniciarFinanceiro(): void
    {
        reiniciarIfood();
        reiniciarFleetbase();
        \Fleetbase\Models\Setting::$valores                       = ['company.empresa-1.entregas.financeiro_corte' => '2026-10-01'];
        \Fleetbase\Models\Company::$fusos                         = ['empresa-1' => 'America/Sao_Paulo'];
        \Illuminate\Support\Facades\URL::$assinadas               = [];
        \Teste\Disco::$arquivos                                   = [];
        \Teste\Disco::$apagados                                   = [];
        \Teste\Disco::$falhar                                     = false;
        \Teste\Config::$valores['services.entregas.financeiro']   = '1';
    }

    /** As linhas de uma tabela em memória (arrays, na ordem do id). */
    function linhasDe(string $tabela): array
    {
        return array_values(\Teste\Banco::$tabelas[$tabela] ?? []);
    }
}
