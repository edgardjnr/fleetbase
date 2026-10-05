<?php

namespace App\Support\Entregas\Ifood;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Entregas RestaurantePro: funções puras sobre os eventos do polling do iFood.
 *
 * O iFood entrega eventos repetidos e fora de ordem (documentação do polling): deduplicar() fica com o primeiro de cada
 * id e ordenar() põe em ordem de createdAt (milissegundos); no empate, pela ordem natural do pedido (ORDEM: PLC antes
 * do CFM … CAN por último; código fora da lista depois de todos) e, por fim, pelo id. createdAt que não é texto
 * (número, lista) vale como data inválida e vai para o fim. acao() diz o que o ProcessarPedidoIfood faz com cada código
 * na etapa 2:
 * - PLC cria o pedido (a sonda de 2026-10-05 mostrou o pedido disponível para a logística já no PLC); se o PLC se
 *   perdeu, outro código **anterior à coleta** também cria (CRIAM_PEDIDO: CFM, RTP, DDCR, DPCR). Nunca cria a partir de
 *   cancelamento (CAN, CAR, CARF), de etapa da entrega (ADR, GTO, AAO, DDD, CLT, DSP, AAD, DDCS, CON: um entregador já
 *   está nele ou ele já foi coletado/entregue) nem do OPA (alteração, que pode chegar em qualquer etapa);
 * - DDCR (chega logo depois do CFM, sem metadata) marca exige_codigo;
 * - CAN só registra cancelado_pelo_ifood_em (o cancelamento do pedido no Entregas é da etapa 3);
 * - os outros códigos conhecidos só ficam registrados (as ações e o ORDER_PATCHED são da etapa 3);
 * - código desconhecido fica gravado como ignorado; o log dele é warning quando o código exige ação da loja no iFood
 *   (HSD, negociação que "obrigatoriamente deve ser respondida", referência, grupo HANDSHAKE_PLATFORM), info nos outros
 *   (nivelDoIgnorado).
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

    /** Códigos que criam o pedido quando ele ainda não existe: o PLC e, se ele se perdeu, os anteriores à coleta. */
    public const CRIAM_PEDIDO = ['PLC', 'CFM', 'RTP', 'DDCR', 'DPCR'];

    /** Ordem natural do pedido, para o desempate de createdAt igual: criação, preparo, entrega, conclusão, cancelamento. */
    public const ORDEM = ['PLC', 'CFM', 'DDCR', 'DPCR', 'RTP', 'ADR', 'GTO', 'AAO', 'DDD', 'CLT', 'DSP', 'AAD', 'DDCS', 'CON', 'OPA', 'CAR', 'CARF', 'CAN'];

    /** Códigos ignorados que exigem resposta da loja no iFood (log warning em vez de info). */
    public const EXIGEM_ACAO_DA_LOJA = ['HSD'];

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

    /** Em ordem de createdAt; data inválida vai para o fim; empate pela ordem natural do código e depois pelo id. */
    public static function ordenar(array $eventos): array
    {
        $chave = fn (array $evento) => [
            static::instante($evento['createdAt'] ?? null),
            static::posicao($evento['code'] ?? null),
            is_scalar($evento['id'] ?? null) ? (string) $evento['id'] : '',
        ];
        usort($eventos, fn (array $a, array $b) => $chave($a) <=> $chave($b));

        return $eventos;
    }

    /** Posição do código na ORDEM; código fora da lista (ou que não é texto) vem depois de todos. */
    public static function posicao($codigo): int
    {
        $posicao = is_string($codigo) ? array_search($codigo, static::ORDEM, true) : false;

        return $posicao === false ? count(static::ORDEM) : $posicao;
    }

    /** Segundos desde 1970, com microssegundos; INF para data ausente, inválida ou que não é texto. Sem fuso, vale UTC. */
    public static function instante($createdAt): float
    {
        if (!is_string($createdAt) || trim($createdAt) === '') {
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

    /** O evento cria o pedido quando ele ainda não existe: só o PLC e os códigos anteriores à coleta (CRIAM_PEDIDO). */
    public static function criaPedido(string $codigo): bool
    {
        return in_array($codigo, static::CRIAM_PEDIDO, true);
    }

    /** Nível do log do evento ignorado: warning se o código exige ação da loja no iFood (HSD), info nos outros. */
    public static function nivelDoIgnorado(string $codigo): string
    {
        return in_array($codigo, static::EXIGEM_ACAO_DA_LOJA, true) ? 'warning' : 'info';
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
