<?php

namespace App\Support\Entregas\Ifood;

use App\Support\Entregas\Coordenada;

/**
 * Entregas RestaurantePro: chegada do motoboy à coleta ou à entrega pela última posição do GPS (entregas:ifood-acompanhar,
 * a cada 30 s). Função pura: dada a etapa em que o pedido está (a mais adiantada entre a última ação aceita pelo iFood e
 * a que o estado do pedido pede: SequenciaIfood::alvoPeloPedido), a posição do motoboy e as duas paradas, diz a ação de
 * chegada a enviar, ou null.
 *
 * - etapa goingToOrigin (indo à loja) e motoboy a até RAIO_METROS da coleta → arrivedAtOrigin;
 * - etapa dispatch (saiu para a entrega) e motoboy a até RAIO_METROS da entrega → arrivedAtDestination;
 * - qualquer outra etapa, posição ausente ou sem GPS (0, 0), parada sem coordenada → null.
 *
 * O raio de 100 m é da spec; se é adequado é ponto a conferir na etapa 5 (a coleta é o Local da loja, e o motoboy
 * costuma parar na frente).
 */
final class ChegadaPeloGps
{
    public const RAIO_METROS = 100;

    /**
     * @param array{0: float, 1: float}|null $motoboy [latitude, longitude]
     * @param array{0: float, 1: float}|null $coleta
     * @param array{0: float, 1: float}|null $entrega
     */
    public static function acao(?string $etapa, ?array $motoboy, ?array $coleta, ?array $entrega, float $raio = self::RAIO_METROS): ?string
    {
        if (!static::valida($motoboy)) {
            return null;
        }

        if ($etapa === SequenciaIfood::INDO_A_LOJA && static::valida($coleta) && static::metros($motoboy, $coleta) <= $raio) {
            return SequenciaIfood::CHEGOU_NA_LOJA;
        }

        if ($etapa === SequenciaIfood::SAIU_PARA_ENTREGA && static::valida($entrega) && static::metros($motoboy, $entrega) <= $raio) {
            return SequenciaIfood::CHEGOU_NO_CLIENTE;
        }

        return null;
    }

    protected static function valida(?array $ponto): bool
    {
        return is_array($ponto) && count($ponto) === 2 && Coordenada::valida($ponto[0] ?? null, $ponto[1] ?? null);
    }

    protected static function metros(array $a, array $b): float
    {
        return PedidoDoIfood::metrosEntre((float) $a[0], (float) $a[1], (float) $b[0], (float) $b[1]);
    }
}
