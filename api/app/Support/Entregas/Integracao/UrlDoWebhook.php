<?php

namespace App\Support\Entregas\Integracao;

/**
 * Entregas RestaurantePro: a URL dos avisos de um sistema de origem só pode ser https, na porta 443, num domínio que
 * resolve só para IPv4 público. Sem isso, quem tem uma chave usaria o webhook para sondar a VPS (localhost, rede do
 * Swarm, metadados da nuvem em 169.254.169.254). IPv6 literal não é aceito (evita IPv4 embutido, como ::ffff:127.0.0.1).
 * Os avisos não seguem redirecionamento (AvisosDaIntegracao).
 *
 * `conferir` devolve o IP conferido: o envio prende a conexão a ele (CURLOPT_RESOLVE), sem nova resolução de DNS.
 * Função pura: o resolvedor de DNS é injetável (host => lista de IPs); null = gethostbynamel.
 */
final class UrlDoWebhook
{
    public const TAMANHO_MAXIMO = 500;

    /** Faixas IPv4 que o filtro do PHP deixa passar e não são públicas: [rede, bits]. */
    private const FAIXAS_EXTRAS = [['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15], ['224.0.0.0', 4]];

    /** O motivo da recusa (texto curto, pt-BR), ou null se a URL pode receber os avisos. */
    public static function recusa(string $url, ?\Closure $resolver = null): ?string
    {
        return static::conferir($url, $resolver)['motivo'];
    }

    /** @return array{motivo: ?string, host: ?string, ip: ?string} */
    public static function conferir(string $url, ?\Closure $resolver = null): array
    {
        $recusa = fn (string $motivo) => ['motivo' => $motivo, 'host' => null, 'ip' => null];

        $url = trim($url);
        if ($url === '' || strlen($url) > static::TAMANHO_MAXIMO) {
            return $recusa('URL vazia ou longa demais');
        }

        $partes = parse_url($url);
        if (!is_array($partes) || strtolower($partes['scheme'] ?? '') !== 'https' || empty($partes['host'])) {
            return $recusa('a URL precisa começar com https://');
        }
        if (isset($partes['user']) || isset($partes['pass'])) {
            return $recusa('a URL não pode ter usuário e senha');
        }
        if (isset($partes['port']) && (int) $partes['port'] !== 443) {
            return $recusa('só a porta 443');
        }

        $host = rtrim(strtolower(trim($partes['host'], '[]')), '.');
        if ($host === '') {
            return $recusa('a URL precisa começar com https://');
        }
        if (str_contains($host, ':')) {
            return $recusa('endereço IPv6 não aceito');
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ips = [$host];
        } else {
            if (preg_match('/^[0-9a-fx.]+$/i', $host)) {
                return $recusa('endereço interno');
            }
            if (!str_contains($host, '.') || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
                return $recusa('endereço interno');
            }
            $resolvidos = ($resolver ?? fn (string $nome) => gethostbynamel($nome) ?: [])($host);
            $ips = array_values(array_filter((array) $resolvidos, fn ($ip) => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
        }

        if (!$ips) {
            return $recusa('o endereço não foi encontrado');
        }
        foreach ($ips as $ip) {
            if (!static::ipv4Publico($ip)) {
                return $recusa('endereço de rede privada');
            }
        }

        return ['motivo' => null, 'host' => $host, 'ip' => $ips[0]];
    }

    private static function ipv4Publico(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        $n = ip2long($ip);
        foreach (static::FAIXAS_EXTRAS as [$rede, $bits]) {
            $mascara = (-1 << (32 - $bits)) & 0xFFFFFFFF;
            if (($n & $mascara) === (ip2long($rede) & $mascara)) {
                return false;
            }
        }

        return true;
    }
}
