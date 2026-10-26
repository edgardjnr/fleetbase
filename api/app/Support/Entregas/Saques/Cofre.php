<?php

namespace App\Support\Entregas\Saques;

/**
 * Entregas RestaurantePro: cifra e decifra a chave de API do Asaas das centrais (AES-256-GCM). A chave que cifra vem do
 * Docker secret `entregas_saque_chave` (arquivo `services.entregas.saque_chave_arquivo`, base64 de 32 bytes), nunca do
 * banco: o backup do MySQL e um acesso ao banco só mostram texto cifrado. Formato gravado: "v1:" + base64(iv 12 + tag 16
 * + cifra). O $contexto (o company_uuid da central) entra como dado autenticado do GCM ("entregas-asaas:<contexto>"): o
 * cifrado de uma central copiado para a linha de outra não decifra. Texto adulterado, de outra chave, de outro contexto
 * ou lixo: decifrar devolve null (a tag do GCM não confere).
 */
final class Cofre
{
    private const VERSAO = 'v1:';
    private const CIFRA  = 'aes-256-gcm';

    public static function disponivel(): bool
    {
        return static::chave() !== null;
    }

    public static function cifrar(#[\SensitiveParameter] string $texto, string $contexto): string
    {
        if ($contexto === '') {
            throw new \InvalidArgumentException('Cofre: contexto vazio.');
        }
        $chave = static::chave();
        if ($chave === null) {
            throw new CofreIndisponivel();
        }
        $iv     = random_bytes(12);
        $tag    = '';
        $cifra  = openssl_encrypt($texto, self::CIFRA, $chave, OPENSSL_RAW_DATA, $iv, $tag, static::aad($contexto), 16);
        if ($cifra === false) {
            throw new \RuntimeException('Não foi possível cifrar.');
        }

        return self::VERSAO . base64_encode($iv . $tag . $cifra);
    }

    public static function decifrar(?string $cifrado, string $contexto): ?string
    {
        $chave = static::chave();
        if ($chave === null || $contexto === '' || !is_string($cifrado) || !str_starts_with($cifrado, self::VERSAO)) {
            return null;
        }
        $bytes = base64_decode(substr($cifrado, strlen(self::VERSAO)), true);
        if ($bytes === false || strlen($bytes) <= 28) {
            return null;
        }
        $texto = openssl_decrypt(substr($bytes, 28), self::CIFRA, $chave, OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16), static::aad($contexto));

        return $texto === false ? null : $texto;
    }

    /** O dado autenticado do GCM: amarra o cifrado à central. */
    private static function aad(string $contexto): string
    {
        return 'entregas-asaas:' . $contexto;
    }

    /** Os 32 bytes da chave, lidos do arquivo do secret a cada uso (troca de secret vale sem reiniciar); null se faltar. */
    private static function chave(): ?string
    {
        $arquivo = config('services.entregas.saque_chave_arquivo');
        if (!is_string($arquivo) || $arquivo === '' || !is_file($arquivo) || !is_readable($arquivo)) {
            return null;
        }
        $bruta = base64_decode(trim((string) file_get_contents($arquivo)), true);

        return is_string($bruta) && strlen($bruta) === 32 ? $bruta : null;
    }
}
