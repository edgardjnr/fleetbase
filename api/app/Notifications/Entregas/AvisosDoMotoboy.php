<?php

namespace App\Notifications\Entregas;

use Fleetbase\FleetOps\Notifications\OrderAssigned;
use Fleetbase\FleetOps\Notifications\OrderCanceled;
use Fleetbase\FleetOps\Notifications\OrderCompleted;
use Fleetbase\FleetOps\Notifications\OrderDispatched;
use Fleetbase\FleetOps\Notifications\OrderFailed;
use Fleetbase\FleetOps\Notifications\OrderPing;
use Fleetbase\FleetOps\Notifications\WaypointCompleted;
use Fleetbase\Notifications\ChatMessageReceived;
use Fleetbase\Notifications\TestPushNotification;
use Illuminate\Notifications\Notification;

/**
 * Entregas RestaurantePro: o push que o motoboy recebe, em pt-BR e no formato que o app trata. O CanalFcmEntregas
 * passa todo push por aqui.
 *
 * Os avisos do Fleet-Ops e do core saem em inglês; cada classe conhecida ganha título e texto em pt-BR. O código do
 * pedido vem do título original ("Order X …" / "New order X …"); sem ele, a frase sai sem o código.
 */
class AvisosDoMotoboy
{
    /**
     * Título e texto em pt-BR do aviso, ou null se a classe não tem tradução.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function texto(Notification $notificacao): ?array
    {
        $codigo = static::codigo((string) ($notificacao->title ?? ''));

        return match (true) {
            $notificacao instanceof LembretePedidoAberto => [$notificacao->title, $notificacao->message],
            $notificacao instanceof OrderPing            => ['Novo pedido disponível', static::textoDaColeta($notificacao->distance)],
            $notificacao instanceof OrderAssigned        => ['Novo pedido para você', static::textoDoAtribuido($notificacao, $codigo)],
            $notificacao instanceof OrderDispatched      => [static::comCodigo('Pedido %s liberado para você', 'Pedido liberado para você', $codigo), 'Toque para ver e iniciar a entrega.'],
            $notificacao instanceof OrderFailed          => [static::comCodigo('Entrega do pedido %s não concluída', 'Entrega não concluída', $codigo), static::comCodigo('A entrega do pedido %s falhou.', 'A entrega do pedido falhou.', $codigo)],
            $notificacao instanceof OrderCanceled        => [static::comCodigo('Pedido %s cancelado', 'Pedido cancelado', $codigo), static::comCodigo('O pedido %s foi cancelado.', 'O pedido foi cancelado.', $codigo)],
            $notificacao instanceof OrderCompleted       => [static::comCodigo('Pedido %s concluído', 'Pedido concluído', $codigo), static::comCodigo('O pedido %s foi concluído.', 'O pedido foi concluído.', $codigo)],
            $notificacao instanceof WaypointCompleted    => [static::comCodigo('Pedido %s: parada concluída', 'Parada concluída', $codigo), static::comCodigo('Uma parada do pedido %s foi concluída.', 'Uma parada do pedido foi concluída.', $codigo)],
            $notificacao instanceof ChatMessageReceived  => [static::tituloDoChat((string) $notificacao->title), (string) $notificacao->message],
            $notificacao instanceof TestPushNotification => [(string) $notificacao->title, (string) $notificacao->message],
            default                                      => null,
        };
    }

    /** "Coleta a 1,2 km de você. Toque para ver o pedido." (sem distância, só o convite). */
    public static function textoDaColeta($distancia): string
    {
        $metros = (int) round((float) $distancia);
        if ($metros < 1) {
            return 'Toque para ver o pedido.';
        }

        $texto = $metros < 1000 ? $metros . ' m' : number_format($metros / 1000, 1, ',', '.') . ' km';

        return 'Coleta a ' . $texto . ' de você. Toque para ver o pedido.';
    }

    /** O código de rastreamento que o Fleet-Ops põe no título original ("Order X …" / "New order X …"). */
    protected static function codigo(string $tituloOriginal): ?string
    {
        return preg_match('/^(?:New order|Order) (\S+)/', $tituloOriginal, $achado) ? $achado[1] : null;
    }

    protected static function comCodigo(string $comCodigo, string $semCodigo, ?string $codigo): string
    {
        return $codigo === null ? $semCodigo : str_replace('%s', $codigo, $comCodigo);
    }

    protected static function textoDoAtribuido(OrderAssigned $notificacao, ?string $codigo): string
    {
        $agendado = str_starts_with((string) $notificacao->message, 'You have a new order scheduled for');
        $quando   = $agendado ? static::quandoAgendado($notificacao) : null;

        if ($quando !== null) {
            return static::comCodigo('Pedido %s agendado para ' . $quando . '.', 'Pedido agendado para ' . $quando . '.', $codigo);
        }

        return static::comCodigo('Pedido %s. Toque para ver os detalhes.', 'Toque para ver os detalhes.', $codigo);
    }

    /** "03/10 às 15:00", no fuso da organização do pedido (America/Sao_Paulo se faltar). */
    protected static function quandoAgendado(OrderAssigned $notificacao): ?string
    {
        try {
            $agendado = $notificacao->order?->scheduled_at;
            if (!$agendado instanceof \DateTimeInterface) {
                return null;
            }

            $fuso  = $notificacao->order?->company?->timezone ?: 'America/Sao_Paulo';
            $local = \DateTimeImmutable::createFromInterface($agendado)->setTimezone(new \DateTimeZone($fuso));

            return $local->format('d/m') . ' às ' . $local->format('H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    protected static function tituloDoChat(string $tituloOriginal): string
    {
        $prefixo = 'Message from ';

        return str_starts_with($tituloOriginal, $prefixo) ? 'Mensagem de ' . substr($tituloOriginal, strlen($prefixo)) : 'Nova mensagem';
    }
}
