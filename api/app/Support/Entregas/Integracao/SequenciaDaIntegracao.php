<?php

namespace App\Support\Entregas\Integracao;

use App\Support\Entregas\SituacaoDoMotoboy;
use App\Support\Entregas\StatusDoPedido;

/**
 * Entregas RestaurantePro: os avisos de volta ao sistema de origem (função pura). O estado vem do Order (status, started,
 * motoboy); o que já foi aceito pelo destino, da linha (ultimo_aviso, motoboy_avisado). Como o SequenciaIfood: o job não
 * recebe o evento, relê o pedido e manda o que falta, então um aviso perdido sai junto com o seguinte.
 *
 * - aceito: com o motoboy (nome e foto). Sai de novo quando o motoboy muda (troca pela central ou pelo líder), sem
 *   voltar o ultimo_aviso;
 * - a_caminho: o motoboy já está com o pedido (SituacaoDoMotoboy::STATUS_EM_ENTREGA; o RestaurantePro manda o WhatsApp
 *   com o link): se já foi concluído, o a_caminho atrasado não sai;
 * - concluido;
 * - cancelado (inclusive expirado): encerra a sequência, em qualquer ponto.
 */
final class SequenciaDaIntegracao
{
    public const ACEITO    = 'aceito';
    public const A_CAMINHO = 'a_caminho';
    public const CONCLUIDO = 'concluido';
    public const CANCELADO = 'cancelado';

    public const ORDEM = [self::ACEITO => 1, self::A_CAMINHO => 2, self::CONCLUIDO => 3, self::CANCELADO => 4];

    /** O ponto em que o pedido está, ou null (ainda sem aceite). */
    public static function alvo(string $status, bool $iniciado, ?string $motoboy): ?string
    {
        $status = strtolower($status);
        if (in_array($status, StatusDoPedido::CANCELADOS, true) || $status === 'expired') {
            return static::CANCELADO;
        }
        if (in_array($status, ['completed', 'done'], true)) {
            return static::CONCLUIDO;
        }
        if (in_array($status, SituacaoDoMotoboy::STATUS_EM_ENTREGA, true)) {
            return static::A_CAMINHO;
        }

        return $iniciado && $motoboy ? static::ACEITO : null;
    }

    /** Os eventos a enviar, em ordem. */
    public static function faltando(?string $ultimo, ?string $alvo, bool $temMotoboy, bool $motoboyMudou): array
    {
        if ($alvo === null || in_array($ultimo, [static::CONCLUIDO, static::CANCELADO], true)) {
            return [];
        }
        if ($alvo === static::CANCELADO) {
            return [static::CANCELADO];
        }

        $nivel   = static::ORDEM[$ultimo] ?? 0;
        $eventos = [];
        if ($temMotoboy && ($nivel < static::ORDEM[static::ACEITO] || $motoboyMudou)) {
            $eventos[] = static::ACEITO;
        }
        if ($alvo === static::A_CAMINHO && $nivel < static::ORDEM[static::A_CAMINHO]) {
            $eventos[] = static::A_CAMINHO;
        }
        if ($alvo === static::CONCLUIDO) {
            $eventos[] = static::CONCLUIDO;
        }

        return $eventos;
    }

    /** O ultimo_aviso depois de um envio aceito: o aceito reenviado não volta o ponto. */
    public static function depoisDe(?string $ultimo, string $evento): string
    {
        return (static::ORDEM[$evento] ?? 0) > (static::ORDEM[$ultimo] ?? 0) ? $evento : (string) $ultimo;
    }

    /** Os eventos que faltam para a linha e o Order (os dois só com os campos usados). */
    public static function doPedido(object $linha, object $pedido): array
    {
        $motoboy = $pedido->driver_assigned_uuid ? (string) $pedido->driver_assigned_uuid : null;
        $avisado = $linha->motoboy_avisado ?? null;
        $mudou   = $motoboy !== null && $avisado !== null && $avisado !== $motoboy;

        return static::faltando($linha->ultimo_aviso ?? null, static::alvo((string) $pedido->status, (bool) $pedido->started, $motoboy), $motoboy !== null, $mudou);
    }
}
