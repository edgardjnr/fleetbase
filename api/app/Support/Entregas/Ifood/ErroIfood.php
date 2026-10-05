<?php

namespace App\Support\Entregas\Ifood;

use RuntimeException;

/**
 * Entregas RestaurantePro: resposta de erro do iFood numa chamada do ClienteIfood, ou falha de rede (status 0).
 *
 * O corpo é o da resposta de erro (ex.: {"errorType","description","code"}), que não traz dados do cliente; mesmo assim,
 * os logs usam só a operação e o status. No 429, retryAfter vem do cabeçalho Retry-After (segundos; 60 se não vier).
 */
class ErroIfood extends RuntimeException
{
    public function __construct(
        public readonly string $operacao,
        public readonly int $status,
        public readonly string $corpo = '',
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct("iFood respondeu {$status} em {$operacao}");
    }

    /** Token vencido ou inválido: quem chama renova e repete uma vez (VinculosIfood::comToken). */
    public function naoAutorizado(): bool
    {
        return $this->status === 401;
    }

    public function limiteExcedido(): bool
    {
        return $this->status === 429;
    }

    /** Rede, 429 ou 5xx: vale tentar de novo mais tarde. */
    public function temporario(): bool
    {
        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }

    /** O corpo como JSON, ou null. */
    public function corpoJson(): ?array
    {
        $dados = json_decode($this->corpo, true);

        return is_array($dados) ? $dados : null;
    }
}
