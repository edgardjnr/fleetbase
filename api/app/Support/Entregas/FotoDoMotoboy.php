<?php

namespace App\Support\Entregas;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Entregas RestaurantePro: a foto do motoboy trocada ou removida pelo app (PUT v1/drivers/{id} com `photo`, ver o
 * update() do DriverControllerSemGeocodificacao).
 *
 * O Fleet-Ops grava a foto nova como um File e só troca o `users.avatar_uuid`: a antiga ficava no disco e na tabela
 * `files` para sempre. E não tinha como remover (o `photo: "REMOVE"` do app era ignorado). Aqui:
 * - remover = `users.avatar_uuid` nulo;
 * - a foto antiga, quando deixa de ser o avatar, é apagada do disco e a linha em `files` ganha o `deleted_at`.
 *
 * **Nunca um DELETE em `files`:** várias tabelas apontam para ela com ON DELETE CASCADE (`users.avatar_uuid`,
 * `companies.logo_uuid`, `contacts.photo_uuid`, `vehicles.photo_uuid`, `vendors.logo_uuid`…). Apagar a linha de um
 * arquivo ainda em uso apagaria o usuário, a empresa ou a loja junto. O soft delete não dispara a cascata. E o arquivo
 * só sai do disco se nenhuma dessas colunas (REFERENCIAS) aponta para ele; com qualquer dúvida (consulta que falha), fica.
 */
final class FotoDoMotoboy
{
    /**
     * Tabela => colunas que apontam para `files.uuid` (core-api 1.6.61, fleetops-api 0.6.65, registry-bridge). As do
     * registry-bridge ficam: ele saiu do package:discover, mas as tabelas continuam no banco (a que não existir é pulada).
     */
    public const REFERENCIAS = [
        'users'                      => ['avatar_uuid'],
        'companies'                  => ['logo_uuid', 'backdrop_uuid'],
        'contacts'                   => ['photo_uuid'],
        'vehicles'                   => ['photo_uuid'],
        'vendors'                    => ['logo_uuid'],
        'categories'                 => ['icon_file_uuid'],
        'extensions'                 => ['icon_uuid'],
        'order_configs'              => ['icon_uuid'],
        'proofs'                     => ['file_uuid'],
        'chat_attachments'           => ['file_uuid'],
        'registry_extensions'        => ['icon_uuid'],
        'registry_extension_bundles' => ['bundle_uuid'],
    ];

    /** O app manda "REMOVE" para tirar a foto; vazio ou nulo também vale como remoção. */
    public static function pedidoDeRemocao($valor): bool
    {
        if ($valor === null) {
            return true;
        }

        return is_string($valor) && in_array(strtoupper(trim($valor)), ['', 'REMOVE'], true);
    }

    /**
     * O usuário do motoboy e o avatar dele: `['usuario' => uuid, 'avatar' => uuid|null]`, ou null (motoboy sem usuário,
     * inexistente ou consulta que falhou). `$id` = public_id (driver_…) ou uuid, como o findRecordOrFail do Fleet-Ops.
     */
    public static function situacao(string $id): ?array
    {
        try {
            $usuario = DB::table('drivers')
                ->where(str_starts_with($id, 'driver_') ? 'public_id' : 'uuid', $id)
                ->whereNull('deleted_at')
                ->value('user_uuid');

            if (!$usuario) {
                return null;
            }

            return ['usuario' => (string) $usuario, 'avatar' => static::avatarDoUsuario((string) $usuario)];
        } catch (\Throwable $e) {
            Log::warning('[entregas] foto do motoboy: leitura falhou: ' . get_class($e), ['motoboy' => $id]);

            return null;
        }
    }

    public static function avatarDoUsuario(string $usuario): ?string
    {
        $avatar = DB::table('users')->where('uuid', $usuario)->value('avatar_uuid');

        return $avatar ? (string) $avatar : null;
    }

    public static function removerDoUsuario(string $usuario): void
    {
        DB::table('users')->where('uuid', $usuario)->update(['avatar_uuid' => null]);
    }

    /** true quando alguma coluna de REFERENCIAS aponta para o arquivo (tabela inexistente não conta). */
    public static function emUso(string $arquivo): bool
    {
        foreach (static::REFERENCIAS as $tabela => $colunas) {
            if (!Schema::hasTable($tabela)) {
                continue;
            }
            foreach ($colunas as $coluna) {
                if (DB::table($tabela)->where($coluna, $arquivo)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Apaga a foto antiga: do disco e com `deleted_at` na tabela `files`, só se nada mais a usa. Nunca lança (a troca
     * da foto já deu certo; um arquivo que sobrou não é motivo para erro). true quando apagou.
     */
    public static function apagarArquivo(string $arquivo): bool
    {
        try {
            if (static::emUso($arquivo)) {
                Log::info('[entregas] foto do motoboy: arquivo antigo mantido (em uso)', ['arquivo' => $arquivo]);

                return false;
            }

            $linha = DB::table('files')->where('uuid', $arquivo)->whereNull('deleted_at')->first(['disk', 'path']);
            if (!$linha) {
                return false;
            }

            if ($linha->path) {
                Storage::disk($linha->disk ?: config('filesystems.default'))->delete($linha->path);
            }
            DB::table('files')->where('uuid', $arquivo)->update(['deleted_at' => now()]);
            Cache::forget('file_url_' . $arquivo);

            Log::info('[entregas] foto do motoboy: arquivo antigo apagado', ['arquivo' => $arquivo]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('[entregas] foto do motoboy: arquivo antigo não apagado: ' . get_class($e), ['arquivo' => $arquivo]);

            return false;
        }
    }

    /** A resposta do update() do Fleet-Ops deu certo? (o recurso do motorista não tem status; o erro é JsonResponse 4xx) */
    public static function deuCerto($resposta): bool
    {
        return !is_object($resposta) || !method_exists($resposta, 'getStatusCode') || $resposta->getStatusCode() < 300;
    }
}
