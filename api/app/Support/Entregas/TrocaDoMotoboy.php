<?php

namespace App\Support\Entregas;

use App\Notifications\Entregas\PedidoPassadoParaOutro;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: o líder dos motoboys passa um pedido para outro motoboy pela aba Mapa do app
 * (LiderController@trocarMotoboy).
 *
 * Segue a troca do console (Fleet-Ops → pedido → Trocar motoboy grava o driver_assigned_uuid com o save do Order): o
 * pedido fica com o status que tinha. Com a TravaDoPedido (a mesma do aceite e do cancelamento), relê o pedido, desliga o
 * pedido aberto (adhoc falso: com ele ligado, o HandleOrderDriverAssigned do Fleet-Ops não avisa o motoboy novo e o
 * reenvio do pedido aberto continuaria) e chama o assignDriver do Order em modo silencioso. O save dele dispara o
 * OrderObserver do Fleet-Ops, que dispara o OrderDriverAssigned uma vez só (o assignDriver sem o silencioso dispara o
 * evento antes do save, e o observer de novo depois: o motoboy novo receberia dois avisos). O OrderDriverAssigned leva o
 * push "Novo pedido para você" ao motoboy novo (AvisosDoMotoboy) e, no pedido iFood, o ObservadorDosPedidosIfood manda o
 * assignDriver ao iFood. O motoboy anterior recebe o PedidoPassadoParaOutro.
 */
class TrocaDoMotoboy
{
    /**
     * @return array{0: int, 1: array} status HTTP e corpo da resposta ({"pedido": …} ou {"errors": […]})
     */
    public static function trocar(string $empresaUuid, string $pedidoId, string $motoboyId, string $liderUuid): array
    {
        $pedido = static::pedido($empresaUuid, $pedidoId);
        if (!$pedido) {
            return [404, ['errors' => ['Pedido não encontrado.']]];
        }
        if (static::encerrado($pedido)) {
            return [409, ['errors' => ['Este pedido já foi encerrado.']]];
        }

        // nenhum model registra o CompanyScope nesta versão: a empresa é filtrada aqui
        $novo = $motoboyId === '' ? null : Driver::where('company_uuid', $empresaUuid)->where('public_id', $motoboyId)->first();
        if (!$novo) {
            return [422, ['errors' => ['Escolha um motoboy da lista.']]];
        }

        try {
            [$resultado, $anteriorUuid] = TravaDoPedido::executar((string) $pedido->uuid, fn () => static::trocarComATrava($empresaUuid, (string) $pedido->uuid, $novo));
        } catch (LockTimeoutException) {
            return [409, ['errors' => ['Outra pessoa está mexendo neste pedido. Tente de novo.']]];
        }

        if ($resultado === 'sumiu') {
            return [404, ['errors' => ['Pedido não encontrado.']]];
        }
        if ($resultado === 'encerrado') {
            return [409, ['errors' => ['Este pedido já foi encerrado.']]];
        }

        $atualizado = static::pedido($empresaUuid, (string) $pedido->uuid, PedidosNoMapa::RELACOES_DO_LIDER) ?? $pedido;
        if ($resultado === 'trocado') {
            $anterior = static::avisarAnterior($empresaUuid, $anteriorUuid, $atualizado);
            Log::info('[entregas] líder trocou o motoboy', [
                'pedido'   => $atualizado->public_id,
                'anterior' => $anterior,
                'novo'     => $novo->public_id,
                'lider'    => $liderUuid,
            ]);
        }

        return [200, ['pedido' => PedidosNoMapa::umDoLider($atualizado)]];
    }

    /**
     * Dentro da trava: relê o pedido (outro líder, o aceite ou o cancelamento podem ter mudado) e troca.
     *
     * @return array{0: string, 1: ?string} resultado (sumiu, encerrado, igual, trocado) e o uuid do motoboy anterior
     */
    protected static function trocarComATrava(string $empresaUuid, string $pedidoUuid, Driver $novo): array
    {
        $pedido = static::pedido($empresaUuid, $pedidoUuid);
        if (!$pedido) {
            return ['sumiu', null];
        }
        if (static::encerrado($pedido)) {
            return ['encerrado', null];
        }
        if ((string) $pedido->driver_assigned_uuid === (string) $novo->uuid) {
            return ['igual', null];
        }

        $anterior      = $pedido->driver_assigned_uuid ?: null;
        $pedido->adhoc = false;
        $pedido->assignDriver($novo, true);

        return ['trocado', $anterior];
    }

    /** O push ao motoboy anterior; uma falha aqui não desfaz a troca (fica no log). Devolve o public_id dele. */
    protected static function avisarAnterior(string $empresaUuid, ?string $anteriorUuid, $pedido): ?string
    {
        if (!$anteriorUuid) {
            return null;
        }

        $anterior = Driver::where('company_uuid', $empresaUuid)->where('uuid', $anteriorUuid)->first();
        if (!$anterior) {
            return null;
        }

        try {
            $anterior->notify(new PedidoPassadoParaOutro(static::numero($pedido), (string) $pedido->public_id));
        } catch (\Throwable $erro) {
            Log::warning('[entregas] líder trocou o motoboy, mas o aviso ao anterior falhou', ['pedido' => $pedido->public_id, 'erro' => get_class($erro)]);
        }

        return $anterior->public_id;
    }

    /** O número do pedido no aviso: o do iFood (internal_id); sem ele, o de rastreio; sem os dois, o public_id. */
    protected static function numero($pedido): string
    {
        return (string) ($pedido->internal_id ?: ($pedido->trackingNumber?->tracking_number ?: $pedido->public_id));
    }

    /** Pedido da empresa pelo public_id ou pelo uuid. */
    protected static function pedido(string $empresaUuid, string $id, array $relacoes = [])
    {
        // nenhum model registra o CompanyScope nesta versão: a empresa é filtrada aqui
        return Order::where('company_uuid', $empresaUuid)
            ->where(fn ($query) => $query->where('public_id', $id)->orWhere('uuid', $id))
            ->with($relacoes)
            ->first();
    }

    protected static function encerrado($pedido): bool
    {
        return in_array($pedido->status, StatusDoPedido::ENCERRADOS, true);
    }
}
