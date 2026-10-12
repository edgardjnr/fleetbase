<?php

namespace App\Support\Entregas\Integracao;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: a chave de integração de cada loja (entregas_integracao_lojas).
 *
 * - gerar: `ent_live_` + 40 caracteres de random_int; guarda só o SHA-256 e os 4 últimos; revoga a anterior na mesma
 *   transação, sob uma trava por loja (uma ativa por loja, garantida pela trava; duas gerações ao mesmo tempo esperam até 5 s). A chave em texto só sai daqui (tela Lojas, uma vez).
 * - daChave: a loja de um Bearer (formato exato, hash, não revogada) ou null.
 * - conectar: o sistema de origem registra a URL dos avisos; sai um segredo novo (64 hex), guardado com encrypt(), que
 *   só volta nessa resposta. O segredo assina os avisos (HMAC-SHA256, AvisosDaIntegracao).
 * - marcarUso: no máximo uma gravação por minuto (USO_A_CADA_SEGUNDOS), para não escrever a cada pedido.
 */
final class ChavesDeIntegracao
{
    public const ALFABETO = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public const USO_A_CADA_SEGUNDOS = 60;

    /** Gera a chave nova da loja (revoga a anterior) e devolve o texto, que só aparece aqui. */
    public static function gerar(string $companyUuid, string $vendorUuid): string
    {
        $chave = Integracao::PREFIXO_DA_CHAVE . static::aleatorio(Integracao::TAMANHO_DA_CHAVE);
        $agora = now()->toDateTimeString();

        Cache::lock("entregas:integracao-chave:{$vendorUuid}", 10)->block(5, function () use ($companyUuid, $vendorUuid, $chave, $agora) {
            DB::transaction(function () use ($companyUuid, $vendorUuid, $chave, $agora) {
                static::revogarAtivas($companyUuid, $vendorUuid, $agora);
                DB::table(Integracao::LOJAS)->insert([
                    'company_uuid' => $companyUuid,
                    'vendor_uuid'  => $vendorUuid,
                    'chave_hash'   => static::hash($chave),
                    'chave_final'  => substr($chave, -4),
                    'created_at'   => $agora,
                    'updated_at'   => $agora,
                ]);
            });
        });

        Log::info('[entregas] integração: chave gerada', ['vendor' => $vendorUuid]);

        return $chave;
    }

    /** Revoga a chave ativa da loja; false se não havia. */
    public static function revogar(string $companyUuid, string $vendorUuid): bool
    {
        $revogadas = static::revogarAtivas($companyUuid, $vendorUuid, now()->toDateTimeString());
        if ($revogadas > 0) {
            Log::info('[entregas] integração: chave revogada', ['vendor' => $vendorUuid]);
        }

        return $revogadas > 0;
    }

    /** A linha ativa da loja (não revogada), ou null. */
    public static function ativa(string $companyUuid, string $vendorUuid): ?object
    {
        return DB::table(Integracao::LOJAS)
            ->where('company_uuid', $companyUuid)
            ->where('vendor_uuid', $vendorUuid)
            ->whereNull('revogada_em')
            ->orderBy('id', 'desc')
            ->first();
    }

    /** A loja da chave (Bearer), ou null: formato errado, chave desconhecida ou revogada. */
    public static function daChave(#[\SensitiveParameter] ?string $chave): ?object
    {
        $chave = trim((string) $chave);
        if (!str_starts_with($chave, Integracao::PREFIXO_DA_CHAVE) || strlen($chave) !== strlen(Integracao::PREFIXO_DA_CHAVE) + Integracao::TAMANHO_DA_CHAVE) {
            return null;
        }

        return DB::table(Integracao::LOJAS)->where('chave_hash', static::hash($chave))->whereNull('revogada_em')->first();
    }

    /** Registra o sistema e a URL dos avisos e devolve o segredo novo (que só sai aqui). */
    public static function conectar(object $loja, string $sistema, string $url): string
    {
        $segredo = bin2hex(random_bytes(32));
        static::atualizar($loja, [
            'sistema'               => Integracao::nomeDoSistema($sistema),
            'webhook_url'           => $url,
            'webhook_segredo'       => encrypt($segredo),
            'conectada_em'          => now()->toDateTimeString(),
            'ultima_falha_aviso'    => null,
            'ultima_falha_aviso_em' => null,
        ]);
        Log::info('[entregas] integração: conectada', ['vendor' => $loja->vendor_uuid, 'sistema' => $loja->sistema]);

        return $segredo;
    }

    /** O segredo do webhook em texto, ou null (sem conexão ou ilegível: APP_KEY trocado). */
    public static function segredo(object $loja): ?string
    {
        if (empty($loja->webhook_segredo)) {
            return null;
        }

        try {
            $segredo = decrypt($loja->webhook_segredo);

            return is_string($segredo) && $segredo !== '' ? $segredo : null;
        } catch (\Throwable $e) {
            Log::warning('[entregas] integração: segredo do webhook ilegível (APP_KEY mudou?)', ['vendor' => $loja->vendor_uuid]);

            return null;
        }
    }

    /** Marca o último uso da chave, no máximo uma vez por minuto. */
    public static function marcarUso(object $loja): void
    {
        $agora  = now();
        $ultimo = $loja->ultimo_uso_em ? Carbon::parse(substr((string) $loja->ultimo_uso_em, 0, 19)) : null;
        if ($ultimo && $agora->getTimestamp() - $ultimo->getTimestamp() < static::USO_A_CADA_SEGUNDOS) {
            return;
        }
        static::atualizar($loja, ['ultimo_uso_em' => $agora->toDateTimeString()]);
    }

    /** O último aviso não chegou ao sistema de origem: a tela Lojas mostra o motivo. */
    public static function registrarFalhaDeAviso(object $loja, string $motivo): void
    {
        static::atualizar($loja, ['ultima_falha_aviso' => mb_substr($motivo, 0, 190), 'ultima_falha_aviso_em' => now()->toDateTimeString()]);
    }

    public static function limparFalhaDeAviso(object $loja): void
    {
        if ($loja->ultima_falha_aviso === null && $loja->ultima_falha_aviso_em === null) {
            return;
        }
        static::atualizar($loja, ['ultima_falha_aviso' => null, 'ultima_falha_aviso_em' => null]);
    }

    /** Grava (com updated_at) e copia os valores para o objeto de quem chamou. */
    public static function atualizar(object $loja, array $valores): void
    {
        $valores['updated_at'] = now()->toDateTimeString();
        DB::table(Integracao::LOJAS)->where('id', $loja->id)->update($valores);
        foreach ($valores as $coluna => $valor) {
            $loja->$coluna = $valor;
        }
    }

    public static function hash(#[\SensitiveParameter] string $chave): string
    {
        return hash('sha256', $chave);
    }

    protected static function revogarAtivas(string $companyUuid, string $vendorUuid, string $agora): int
    {
        return DB::table(Integracao::LOJAS)
            ->where('company_uuid', $companyUuid)
            ->where('vendor_uuid', $vendorUuid)
            ->whereNull('revogada_em')
            ->update(['revogada_em' => $agora, 'updated_at' => $agora]);
    }

    protected static function aleatorio(int $tamanho): string
    {
        $texto  = '';
        $maximo = strlen(static::ALFABETO) - 1;
        for ($i = 0; $i < $tamanho; $i++) {
            $texto .= static::ALFABETO[random_int(0, $maximo)];
        }

        return $texto;
    }
}
