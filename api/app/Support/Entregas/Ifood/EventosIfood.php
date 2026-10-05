<?php

namespace App\Support\Entregas\Ifood;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Entregas RestaurantePro: funções puras sobre os eventos do polling do iFood.
 *
 * O iFood entrega eventos repetidos e fora de ordem (documentação do polling): deduplicar() fica com o primeiro de cada
 * id e ordenar() põe em ordem de createdAt (milissegundos), com o id de desempate. acao() diz o que o
 * ProcessarPedidoIfood faz com cada código na etapa 2:
 * - PLC cria o pedido (a sonda de 2026-10-05 mostrou o pedido disponível para a logística já no PLC); qualquer outro
 *   código conhecido, menos o CAN, também cria se o PLC se perdeu (criaPedido);
 * - DDCR (chega logo depois do CFM, sem metadata) marca exige_codigo;
 * - CAN só registra cancelado_pelo_ifood_em (o cancelamento do pedido no Entregas é da etapa 3);
 * - os outros códigos conhecidos só ficam registrados (as ações e o ORDER_PATCHED são da etapa 3);
 * - código desconhecido fica gravado como ignorado.
 */
final class EventosIfood
{
    public const CRIA         = 'cria';
    public const EXIGE_CODIGO = 'exige_codigo';
    public const CANCELA      = 'cancela';
    public const REGISTRA     = 'registra';
    public const IGNORA       = 'ignora';

    /** Códigos conhecidos (spec, "Eventos relevantes"). */
    public const CONHECIDOS = ['PLC', 'CFM', 'RTP', 'DSP', 'CON', 'CAN', 'CAR', 'CARF', 'ADR', 'GTO', 'AAO', 'DDD', 'CLT', 'AAD', 'DDCR', 'DDCS', 'DPCR', 'OPA'];

    /** Um evento por id (o primeiro); evento sem id é descartado. */
    public static function deduplicar(array $eventos): array
    {
        $vistos = [];
        foreach ($eventos as $evento) {
            $id = is_array($evento) ? ($evento['id'] ?? null) : null;
            if (is_string($id) && $id !== '' && !isset($vistos[$id])) {
                $vistos[$id] = $evento;
            }
        }

        return array_values($vistos);
    }

    /** Em ordem de createdAt; data inválida vai para o fim; empate pelo id. */
    public static function ordenar(array $eventos): array
    {
        usort($eventos, fn (array $a, array $b) => [static::instante($a['createdAt'] ?? null), (string) ($a['id'] ?? '')] <=> [static::instante($b['createdAt'] ?? null), (string) ($b['id'] ?? '')]);

        return $eventos;
    }

    /** Segundos desde 1970, com microssegundos; INF para data ausente ou inválida. Sem fuso no texto, vale UTC. */
    public static function instante(?string $createdAt): float
    {
        if ($createdAt === null || trim($createdAt) === '') {
            return INF;
        }

        try {
            return (float) (new DateTimeImmutable($createdAt, new DateTimeZone('UTC')))->format('U.u');
        } catch (\Exception) {
            return INF;
        }
    }

    public static function acao(string $codigo): string
    {
        return match (true) {
            $codigo === 'PLC'                         => static::CRIA,
            $codigo === 'DDCR'                        => static::EXIGE_CODIGO,
            $codigo === 'CAN'                         => static::CANCELA,
            in_array($codigo, static::CONHECIDOS, true) => static::REGISTRA,
            default                                   => static::IGNORA,
        };
    }

    /** O evento cria o pedido quando ele ainda não existe: qualquer código conhecido, menos o CAN. */
    public static function criaPedido(string $codigo): bool
    {
        return !in_array(static::acao($codigo), [static::CANCELA, static::IGNORA], true);
    }

    public static function temCancelamento(array $eventos): bool
    {
        foreach ($eventos as $evento) {
            if (($evento['code'] ?? null) === 'CAN') {
                return true;
            }
        }

        return false;
    }

    /** A linha da entregas_ifood_eventos para o evento, ou null se faltar id, orderId, merchantId ou code. */
    public static function paraGravar(array $evento, string $agora): ?array
    {
        foreach (['id', 'orderId', 'merchantId', 'code'] as $campo) {
            if (!is_string($evento[$campo] ?? null) || $evento[$campo] === '') {
                return null;
            }
        }

        $instante = static::instante($evento['createdAt'] ?? null);

        return [
            'evento_id'       => substr($evento['id'], 0, 64),
            'merchant_id'     => substr($evento['merchantId'], 0, 64),
            'pedido_ifood_id' => substr($evento['orderId'], 0, 64),
            'codigo'          => substr($evento['code'], 0, 10),
            'criado_no_ifood' => is_finite($instante) ? DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $instante))->format('Y-m-d H:i:s.v') : null,
            'payload'         => json_encode($evento, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'processado_em'   => null,
            'ignorado'        => false,
            'created_at'      => $agora,
            'updated_at'      => $agora,
        ];
    }
}
