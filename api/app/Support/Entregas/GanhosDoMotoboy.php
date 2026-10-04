<?php

namespace App\Support\Entregas;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;

/**
 * Entregas RestaurantePro: o que o app do motoboy (Navigator) recebe dos valores das entregas (MotoboyController).
 * Só o valor pago a ele: nunca o valor cobrado da loja, a margem ou dados de outro motoboy.
 */
class GanhosDoMotoboy
{
    /** Maior período da tela Meus ganhos, em dias entre o início e o fim: 3 meses, como o extrato da loja. */
    public const MAX_DIAS = 92;

    /** Rotas novas calculadas por consulta dos ganhos (o app não repete a chamada como a tela da central). */
    public const LIMITE_CALCULOS = 10;

    /** Pedido que o motoboy pode consultar: o dele, ou um aberto (avulso, sem motoboy e não encerrado). */
    public static function podeVer(Order $pedido, Driver $motoboy): bool
    {
        $atribuido = (string) $pedido->driver_assigned_uuid;
        if ($atribuido !== '') {
            return $atribuido === $motoboy->uuid;
        }

        return (bool) $pedido->adhoc && !in_array($pedido->status, StatusDoPedido::ENCERRADOS, true);
    }

    /** Uma entrega do CalculoEntregas::entregas() como o app recebe. */
    public static function linha(array $entrega): array
    {
        return [
            'pedido'       => $entrega['pedido'],
            'concluido_em' => $entrega['concluido_em'],
            'loja'         => $entrega['loja_nome'],
            'destino'      => $entrega['destino'],
            'km'           => $entrega['km'],
            'aproximado'   => ($entrega['fonte'] ?? null) === 'estimativa',
            'faixa'        => static::limitesDaFaixa($entrega['faixa'] ?? null),
            'valor'        => $entrega['valor_motoboy'],
        ];
    }

    /** Resposta da rota de ganhos: as entregas do período, quantas ainda estão sem km e os totais. */
    public static function resumo(array $entregas, string $inicio, string $fim, int $pendentes): array
    {
        $linhas = array_map([static::class, 'linha'], $entregas);

        return [
            'inicio'    => $inicio,
            'fim'       => $fim,
            'pendentes' => $pendentes,
            'totais'    => [
                'entregas' => count($linhas),
                'km'       => round(array_sum(array_map(fn ($linha) => $linha['km'] ?? 0, $linhas)), 2),
                'valor'    => round(array_sum(array_map(fn ($linha) => $linha['valor'] ?? 0, $linhas)), 2),
            ],
            'entregas'  => $linhas,
        ];
    }

    /** Resposta da rota do valor de um pedido, a partir do CalculoEntregas::valorDoPedido(). */
    public static function valor(Order $pedido, array $valor): array
    {
        return [
            'pedido'     => $pedido->public_id,
            'km'         => $valor['km'],
            'aproximado' => ($valor['fonte'] ?? null) === 'estimativa',
            'faixa'      => static::limitesDaFaixa($valor['faixa'] ?? null),
            'valor'      => $valor['valor_motoboy'],
        ];
    }

    /** Dias do início ao fim (fim − início), com as datas AAAA-MM-DD já validadas. */
    public static function diasDoPeriodo(string $inicio, string $fim): int
    {
        $utc = new \DateTimeZone('UTC');
        $de  = \DateTimeImmutable::createFromFormat('!Y-m-d', $inicio, $utc);
        $ate = \DateTimeImmutable::createFromFormat('!Y-m-d', $fim, $utc);

        return (int) $de->diff($ate)->format('%r%a');
    }

    /** Só os limites: a faixa do relatório também traz o valor cobrado da loja. */
    protected static function limitesDaFaixa(?array $faixa): ?array
    {
        return $faixa ? ['de_km' => $faixa['de_km'], 'ate_km' => $faixa['ate_km'], 'acima' => !empty($faixa['acima'])] : null;
    }
}
