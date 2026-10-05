<?php

namespace App\Support\Entregas\Ifood;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: cria no Fleetbase o pedido que veio do iFood e o despacha aos motoboys. Adaptador fino sobre
 * os models do Fleet-Ops, no mesmo formato dos pedidos do portal da loja (PortalOrderService::buildPayloadFromInput e o
 * OrderController do customer-portal):
 * - cliente = a Loja (Vendor) do vínculo; coleta = o Local da Loja (não o endereço do iFood);
 * - entrega = Place novo com as coordenadas do iFood (sem geocodificação) e sem dono (não entra nos endereços salvos
 *   da loja no portal);
 * - Order tipo transport, status created, adhoc, internal_id = número do iFood, notas "iFood #4821";
 * - a linha de entregas_ifood_pedidos entra na mesma transação do Order: o pedido_ifood_id único impede um segundo
 *   pedido para o mesmo pedido do iFood (se a linha falhar, o Order é desfeito junto).
 *
 * A empresa vai para a sessão antes de criar: o TrackingNumberObserver e o OrderConfig::default() a leem de lá.
 * Despacho como o do portal (RegrasPortalLoja::despacharParaMotoboys): adhoc + firstDispatchWithActivity. Pedido de
 * teste, sem coordenadas ou agendado não é despachado aqui (o agendado sai pelo entregas:ifood-agendados). Coleta a
 * mais de 300 m do endereço da loja no iFood gera aviso no log (um dos cadastros deve estar errado).
 */
class CriadorDoPedidoIfood
{
    public const TABELA = 'entregas_ifood_pedidos';

    /** Distância máxima entre o Local da loja e o endereço dela no iFood antes do aviso no log. */
    public const DIVERGENCIA_MAXIMA_METROS = 300;

    /** Cria o pedido e devolve a linha de entregas_ifood_pedidos; null se a loja não tem Local de coleta. */
    public function criar(object $vinculo, array $pedidoIfood): ?object
    {
        $vendor = Vendor::where('uuid', $vinculo->vendor_uuid)->where('company_uuid', $vinculo->company_uuid)->first();
        $coleta = $vendor && $vendor->place_uuid ? Place::where('uuid', $vendor->place_uuid)->first() : null;
        if (!$vendor || !$coleta || !$coleta->location) {
            Log::error('[entregas] ifood: loja sem local de coleta; pedido não criado', ['merchant' => $vinculo->merchant_id, 'numero' => $pedidoIfood['displayId'] ?? null]);

            return null;
        }

        $dados = PedidoDoIfood::mapear($pedidoIfood, (float) $coleta->location->getLat(), (float) $coleta->location->getLng(), now());
        $this->conferirColeta($pedidoIfood, $coleta, $dados);
        if ($dados['sem_coordenadas']) {
            Log::warning('[entregas] ifood: pedido sem coordenadas de entrega; não despachado', ['merchant' => $vinculo->merchant_id, 'numero' => $dados['numero']]);
        }

        // o TrackingNumberObserver e o OrderConfig::default() leem a empresa da sessão
        session(['company' => $vinculo->company_uuid]);

        $pedido = DB::transaction(function () use ($vinculo, $vendor, $coleta, $dados, $pedidoIfood) {
            $entrega = $dados['entrega'];
            // nome e endereço em maiúsculas com mb_strtoupper: o PlaceObserver só faz strtoupper (ASCII) e gravaria "RIBEIRãO"
            $maiusculo = fn (?string $texto) => $texto === null ? null : mb_strtoupper($texto, 'UTF-8');
            $destino   = Place::create([
                'company_uuid' => $vinculo->company_uuid,
                'name'         => $maiusculo($entrega['nome']),
                'street1'      => $maiusculo($entrega['street1']),
                'street2'      => $maiusculo($entrega['street2']),
                'neighborhood' => $maiusculo($entrega['neighborhood']),
                'city'         => $maiusculo($entrega['city']),
                'province'     => $maiusculo($entrega['province']),
                'postal_code'  => $entrega['postal_code'],
                'country'      => $entrega['country'],
                'location'     => new Point($entrega['latitude'], $entrega['longitude']),
            ]);

            $payload               = new Payload();
            $payload->company_uuid = $vinculo->company_uuid;
            $payload->setPickup($coleta, [
                'callback' => function ($pickup, $payload) {
                    $payload->setCurrentWaypoint($pickup);
                },
            ]);
            $payload->setDropoff($destino);
            $payload->save();

            $config = OrderConfig::default() ?? OrderConfig::where('company_uuid', $vinculo->company_uuid)->first();
            if (!$config) {
                throw new \RuntimeException('a empresa não tem tipo de pedido (OrderConfig)');
            }

            $pedido = Order::create([
                'company_uuid'      => $vinculo->company_uuid,
                'customer_uuid'     => $vendor->uuid,
                'customer_type'     => Utils::getMutationType($vendor),
                'payload_uuid'      => $payload->uuid,
                'order_config_uuid' => $config->uuid,
                'type'              => $config->key,
                'status'            => 'created',
                'adhoc'             => true,
                'internal_id'       => $dados['numero'],
                'scheduled_at'      => $dados['scheduled_at'],
                'notes'             => $dados['notas'],
            ]);

            $agora = now()->toDateTimeString();
            DB::table(static::TABELA)->insert($dados['linha'] + [
                'company_uuid'    => $vinculo->company_uuid,
                'order_uuid'      => $pedido->uuid,
                'pedido_ifood_id' => (string) $pedidoIfood['id'],
                'merchant_id'     => $vinculo->merchant_id,
                'vendor_uuid'     => $vendor->uuid,
                'created_at'      => $agora,
                'updated_at'      => $agora,
            ]);

            return $pedido;
        });

        Log::info('[entregas] ifood: pedido criado', ['pedido' => $pedido->public_id, 'numero' => $dados['numero'], 'teste' => $dados['teste'], 'agendado' => $dados['agendado']]);

        if ($dados['despachar_agora']) {
            $this->despachar($pedido);
        }

        return DB::table(static::TABELA)->where('pedido_ifood_id', (string) $pedidoIfood['id'])->first();
    }

    /**
     * Pedido aberto (adhoc) aos motoboys próximos da coleta, como o portal. Se o fleetops:dispatch-orders já despachou o
     * agendado (ele despacha quem cai a ±1 min do scheduled_at, sem a atividade), só falta a atividade "dispatched".
     * Marca despachado_em; em falha, só registra no log (o entregas:ifood-agendados tenta de novo).
     */
    public function despachar(Order $pedido): bool
    {
        try {
            session(['company' => $pedido->company_uuid]);
            if ($pedido->dispatched) {
                if (!$pedido->hasDispatchedStatus()) {
                    $pedido->insertDispatchActivity();
                }
            } else {
                $pedido->adhoc = true;
                $pedido->saveQuietly();
                // dispatched + atividade "dispatched" + OrderDispatched na fila → avisa os motoboys no raio da coleta
                $pedido->firstDispatchWithActivity();
            }

            $agora = now()->toDateTimeString();
            DB::table(static::TABELA)->where('order_uuid', $pedido->uuid)->update(['despachado_em' => $agora, 'updated_at' => $agora]);

            return true;
        } catch (\Throwable $e) {
            Log::error('[entregas] ifood: falha ao despachar o pedido', ['pedido' => $pedido->public_id, 'erro' => get_class($e)]);

            return false;
        }
    }

    /** Aviso se o Local da loja está a mais de 300 m do endereço dela no iFood (pedido de teste não conta). */
    protected function conferirColeta(array $pedidoIfood, object $coleta, array $dados): void
    {
        $endereco = $pedidoIfood['merchant']['merchantAddress'] ?? null;
        if ($dados['teste'] || !is_array($endereco) || !isset($endereco['latitude'], $endereco['longitude'])) {
            return;
        }

        $metros = PedidoDoIfood::metrosEntre((float) $coleta->location->getLat(), (float) $coleta->location->getLng(), (float) $endereco['latitude'], (float) $endereco['longitude']);
        if ($metros > static::DIVERGENCIA_MAXIMA_METROS) {
            Log::warning('[entregas] ifood: coleta diverge do iFood (' . (int) round($metros) . ' m)', ['merchant' => $pedidoIfood['merchant']['id'] ?? null, 'numero' => $dados['numero']]);
        }
    }
}
