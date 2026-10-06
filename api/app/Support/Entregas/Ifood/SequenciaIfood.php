<?php

namespace App\Support\Entregas\Ifood;

use App\Support\Entregas\StatusDoPedido;

/**
 * Entregas RestaurantePro: a ordem das ações de logística do iFood e o que cada estado do pedido no Fleetbase pede.
 * Funções puras.
 *
 * O iFood exige a ordem assignDriver → goingToOrigin → arrivedAtOrigin → dispatch → arrivedAtDestination (fora dela,
 * 409) e não devolve evento das nossas ações (sonda de 2026-10-05): a coluna `ultima_acao` da entregas_ifood_pedidos é a
 * única fonte do que já foi aceito. faltando() lista as ações a enviar, em ordem, para chegar a uma ação alvo (ex.: o GPS
 * falhou e o motoboy tocou "A caminho": arrivedAtOrigin e depois dispatch).
 *
 * alvoPeloPedido() traduz o estado do pedido (fluxo transport do Fleet-Ops: created → dispatched → started → enroute →
 * completed; FleetOps.php do fleetops-api) na ação que ele pede:
 * - com motoboy atribuído (aceite do pedido aberto ou atribuição pela central) → assignDriver;
 * - iniciado (`started`, gravado pelo startOrder antes da atividade) → goingToOrigin;
 * - status enroute ("A caminho" no app) → dispatch;
 * - status completed (conclusão pelo app ou pela central) → arrivedAtDestination (o código, quando exigido, é a rota
 *   codigo-ifood do app; a central que conclui no console não confere código);
 * - sem motoboy, ou cancelado/expirado → nada.
 * As chegadas (arrivedAtOrigin e, antes da conclusão, arrivedAtDestination) não têm estado no Fleetbase: vêm do GPS
 * (ChegadaPeloGps) ou da conclusão do app (ConclusaoIfood).
 */
final class SequenciaIfood
{
    public const ATRIBUIR          = 'assignDriver';
    public const INDO_A_LOJA       = 'goingToOrigin';
    public const CHEGOU_NA_LOJA    = 'arrivedAtOrigin';
    public const SAIU_PARA_ENTREGA = 'dispatch';
    public const CHEGOU_NO_CLIENTE = 'arrivedAtDestination';

    /** As ações, na ordem que o iFood exige. */
    public const ACOES = [self::ATRIBUIR, self::INDO_A_LOJA, self::CHEGOU_NA_LOJA, self::SAIU_PARA_ENTREGA, self::CHEGOU_NO_CLIENTE];

    /** Posição da ação na ordem; -1 para nenhuma (null) ou desconhecida. */
    public static function posicao(?string $acao): int
    {
        $posicao = $acao === null ? false : array_search($acao, static::ACOES, true);

        return $posicao === false ? -1 : $posicao;
    }

    /** As ações depois de $ultima até $alvo (inclusive), em ordem; [] se o alvo não está adiante da última. */
    public static function faltando(?string $ultima, ?string $alvo): array
    {
        $de  = static::posicao($ultima);
        $ate = static::posicao($alvo);

        return $ate > $de ? array_slice(static::ACOES, $de + 1, $ate - $de) : [];
    }

    /** A mais adiantada das ações dadas (null e desconhecidas não contam), ou null. */
    public static function maisAdiante(?string ...$acoes): ?string
    {
        $melhor = null;
        foreach ($acoes as $acao) {
            if (static::posicao($acao) > static::posicao($melhor)) {
                $melhor = $acao;
            }
        }

        return $melhor;
    }

    /** A ação que o estado do pedido pede (ver o docblock da classe), ou null. */
    public static function alvoPeloPedido(?string $status, bool $iniciado, ?string $motoboyUuid): ?string
    {
        if ($motoboyUuid === null || $motoboyUuid === '') {
            return null;
        }
        if ($status === 'completed') {
            return static::CHEGOU_NO_CLIENTE;
        }
        if (in_array($status, StatusDoPedido::ENCERRADOS, true)) {
            return null;
        }
        if ($status === 'enroute') {
            return static::SAIU_PARA_ENTREGA;
        }

        return $iniciado ? static::INDO_A_LOJA : static::ATRIBUIR;
    }

    /** O dispatch já foi aceito pelo iFood: um CAN daqui em diante vale como entrega paga (pago_mesmo_cancelado). */
    public static function saiuParaEntrega(?string $ultima): bool
    {
        return static::posicao($ultima) >= static::posicao(static::SAIU_PARA_ENTREGA);
    }
}
