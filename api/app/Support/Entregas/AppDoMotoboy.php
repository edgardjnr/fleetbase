<?php

namespace App\Support\Entregas;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: trava de versão do app do motoboy ("Atualize o app"). Cada APK do entregas-navigator manda o
 * número dele (versionCode = número do build no GitHub Actions) no cabeçalho X-Entregas-App de toda chamada; a versão
 * mínima é a última publicada no MinIO pelo próprio Actions (JSON {versao, url} em ENTREGAS_APP_VERSAO_URL), ou a
 * fixada à mão em ENTREGAS_APP_VERSAO_MINIMA (emergência: build quebrado). Com ENTREGAS_APP_TRAVA=1, o motoboy abaixo
 * dela (ou sem o cabeçalho: APK 34 e anteriores) é barrado pelo ExigirAppAtualizado e não recebe oferta (Candidatos).
 *
 * O JSON é lido no máximo uma vez por minuto (cache) e, se o MinIO falhar, vale o último lido; sem nenhum, não há trava.
 * A versão de cada motoboy fica no cache por usuário (registrada a cada chamada do app), para a fila de ofertas.
 */
class AppDoMotoboy
{
    public const CABECALHO = 'X-Entregas-App';
    public const CACHE_PUBLICADA = 'entregas:app-versao';
    public const CACHE_ULTIMA_LIDA = 'entregas:app-versao:ultima';
    public const CACHE_DO_USUARIO = 'entregas:app-do-usuario:';
    public const SEGUNDOS_DO_CACHE = 60;
    public const SEGUNDOS_DA_ULTIMA_LIDA = 30 * 24 * 3600;
    public const SEGUNDOS_DO_USUARIO = 7 * 24 * 3600;
    public const TIMEOUT_S = 3;
    public const MENSAGEM = 'Atualize o app para continuar.';

    public static function travaLigada(): bool
    {
        return filter_var(config('services.entregas.app_trava'), FILTER_VALIDATE_BOOLEAN);
    }

    /** O número do APK que fez a chamada (cabeçalho X-Entregas-App), ou null (APK antigo, sem o cabeçalho). */
    public static function versaoDaChamada(Request $request): ?int
    {
        return static::inteiro($request->header(static::CABECALHO));
    }

    /** A última versão publicada no MinIO: {versao, url}, ou null se nunca foi lida. */
    public static function publicada(): ?array
    {
        $url = (string) config('services.entregas.app_versao_url');
        if ($url === '') {
            return null;
        }

        $emCache = Cache::get(static::CACHE_PUBLICADA);
        if (is_array($emCache)) {
            return $emCache;
        }

        $lida = null;
        try {
            $resposta = Http::timeout(static::TIMEOUT_S)->acceptJson()->get($url);
            if ($resposta->successful()) {
                $lida = static::validar($resposta->json());
            }
            if ($lida === null) {
                Log::warning('[entregas] app do motoboy: versão publicada ilegível', ['status' => $resposta->status()]);
            }
        } catch (\Throwable $e) {
            Log::warning('[entregas] app do motoboy: falha ao ler a versão publicada', ['erro' => get_class($e)]);
        }

        if ($lida !== null) {
            Cache::put(static::CACHE_ULTIMA_LIDA, $lida, static::SEGUNDOS_DA_ULTIMA_LIDA);
        } else {
            $lida = Cache::get(static::CACHE_ULTIMA_LIDA);
            $lida = is_array($lida) ? $lida : null;
        }
        // a falha também fica um minuto no cache: o MinIO fora do ar não custa 3 s a cada chamada do app
        if ($lida !== null) {
            Cache::put(static::CACHE_PUBLICADA, $lida, static::SEGUNDOS_DO_CACHE);
        }

        return $lida;
    }

    /**
     * A versão mínima exigida, com o link do APK: a fixada em ENTREGAS_APP_VERSAO_MINIMA (link do mesmo número, na pasta
     * do JSON) ou a publicada. null = sem trava (nada publicado nem fixado).
     */
    public static function minima(): ?array
    {
        $fixada = static::inteiro(config('services.entregas.app_versao_minima'));
        if ($fixada !== null) {
            $pasta = dirname((string) config('services.entregas.app_versao_url'));

            return ['versao' => $fixada, 'url' => $pasta . '/entregas-motoboy-' . $fixada . '.apk'];
        }

        return static::publicada();
    }

    /** Com a trava ligada: o APK $versao (null = sem o cabeçalho) está abaixo da mínima. */
    public static function desatualizado(?int $versao): bool
    {
        if (!static::travaLigada()) {
            return false;
        }
        $minima = static::minima();

        return $minima !== null && ($versao === null || $versao < $minima['versao']);
    }

    /** Guarda a versão do app do usuário (motoboy) para a fila de ofertas; só grava quando muda. */
    public static function registrar(string $usuario, ?int $versao): void
    {
        if ($versao === null || $usuario === '') {
            return;
        }
        $chave = static::CACHE_DO_USUARIO . $usuario;
        if (Cache::get($chave) !== $versao) {
            Cache::put($chave, $versao, static::SEGUNDOS_DO_USUARIO);
        }
    }

    public static function versaoDoUsuario(?string $usuario): ?int
    {
        if (!$usuario) {
            return null;
        }

        return static::inteiro(Cache::get(static::CACHE_DO_USUARIO . $usuario));
    }

    /** A fila de ofertas: com a trava ligada, só quem usa o app na versão mínima ou acima (pelo cache do usuário). */
    public static function podeReceberOferta(?string $usuario): bool
    {
        return !static::desatualizado(static::versaoDoUsuario($usuario));
    }

    /** O que o app recebe em GET v1/entregas/motoboy/app e no 426. */
    public static function situacao(?int $versao): array
    {
        $minima = static::minima();

        return [
            'trava'         => static::travaLigada(),
            'versao'        => $versao,
            'versao_minima' => $minima['versao'] ?? null,
            'url'           => $minima['url'] ?? null,
            'desatualizado' => static::desatualizado($versao),
        ];
    }

    private static function validar($dados): ?array
    {
        if (!is_array($dados)) {
            return null;
        }
        $versao = static::inteiro($dados['versao'] ?? null);
        $url    = is_string($dados['url'] ?? null) ? $dados['url'] : '';
        if ($versao === null || !str_starts_with($url, 'https://')) {
            return null;
        }

        return ['versao' => $versao, 'url' => $url];
    }

    private static function inteiro($valor): ?int
    {
        if (is_int($valor)) {
            return $valor > 0 ? $valor : null;
        }
        if (is_string($valor) && preg_match('/^\s*(\d{1,9})\s*$/', $valor, $m)) {
            return (int) $m[1] > 0 ? (int) $m[1] : null;
        }

        return null;
    }
}
