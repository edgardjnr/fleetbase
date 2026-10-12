<?php

namespace App\Support\Entregas\Integracao;

/**
 * Entregas RestaurantePro: a URL dos avisos de um sistema de origem só pode ser https, na porta 443, num host que resolve
 * só para IPs públicos. Sem isso, quem tem uma chave usaria o webhook para sondar a VPS (localhost, rede do Swarm,
 * metadados da nuvem em 169.254.169.254). Conferida na conexão e de novo em cada envio (o DNS pode mudar). Os avisos não
 * seguem redirecionamento (AvisosDaIntegracao). Risco aceito: DNS que muda entre a conferência e o envio (rebinding) e
 * IPv6 do host (o gethostbynamel só devolve IPv4).
 *
 * Função pura: o resolvedor de DNS é injetável (host => lista de IPs); null = gethostbynamel.
 */
final class UrlDoWebhook
{
    public const TAMANHO_MAXIMO = 500;

    /** O motivo da recusa (texto curto, pt-BR), ou null se a URL pode receber os avisos. */
    public static function recusa(string $url, ?\Closure $resolver = null): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > static::TAMANHO_MAXIMO) {
            return 'URL vazia ou longa demais';
        }

        $partes = parse_url($url);
        if (!is_array($partes) || strtolower($partes['scheme'] ?? '') !== 'https' || empty($partes['host'])) {
            return 'a URL precisa começar com https://';
        }
        if (isset($partes['user']) || isset($partes['pass'])) {
            return 'a URL não pode ter usuário e senha';
        }
        if (isset($partes['port']) && (int) $partes['port'] !== 443) {
            return 'só a porta 443';
        }

        $host = strtolower(trim($partes['host'], '[]'));
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            if (!str_contains($host, '.') || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
                return 'endereço interno';
            }
            $ips = ($resolver ?? fn (string $nome) => gethostbynamel($nome) ?: [])($host);
        }

        if (!$ips) {
            return 'o endereço não foi encontrado';
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'endereço de rede privada';
            }
        }

        return null;
    }
}
