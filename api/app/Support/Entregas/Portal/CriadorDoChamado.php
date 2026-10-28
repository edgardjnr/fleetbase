<?php

namespace App\Support\Entregas\Portal;

use App\Http\Middleware\RegrasPortalLoja;
use App\Support\Entregas\Coordenada;
use App\Support\Entregas\Ifood\PedidoDoIfood;
use App\Support\Entregas\Integracao\CriadorDoPedidoDaIntegracao;
use App\Support\Entregas\PedidoRecusado;
use App\Support\Entregas\StatusDoPedido;
use App\Support\Entregas\TravaDoPedido;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: cria o pedido do "Chamar motoboy" do portal da loja e o despacha, no molde do
 * CriadorDoPedidoDaIntegracao (spec docs/superpowers/specs/2026-10-28-chamar-motoboy-portal-design.md):
 * - a mesma chave da loja devolve o pedido que já nasceu (repetido), sem criar outro;
 * - coleta = o Local da loja (empresa, dono e coordenadas); o ponto da entrega a 30 m ou mais da coleta (a regra do
 *   portal, RegrasPortalLoja) e a até 50 km (a da integração);
 * - entrega = o Local do mesmo cliente no mesmo ponto (reaproveitado) ou um Local novo com dono = a loja, criado direto
 *   (sem o firstOrNew do PlaceController, que sobrescreveria um salvo) e sem o telefone (o Place vai aos motoboys);
 * - Order da loja, tipo do OrderConfig::default(), aberto (adhoc), sem notas (a observação com "#" viraria selo de origem),
 *   e a linha da entregas_portal_pedidos na mesma transação;
 * - despacho como o do portal e da integração: adhoc + firstDispatchWithActivity com a TravaDoPedido → distribuição.
 *   Falhou: só o log, o pedido existe e a central despacha à mão.
 * O crédito da loja pré-paga fica com quem chama (CreditoNoPedido, no ChamarMotoboyController).
 */
class CriadorDoChamado
{
    /** O Local de antes serve se o ponto novo estiver a até isto dele (e nome, rua e complemento baterem). */
    public const REAPROVEITAR_ATE_METROS = 30;

    /**
     * @return array{0: object, 1: Order, 2: bool} a linha, o pedido e se nasceu agora (false = a mesma chave)
     *
     * @throws PedidoRecusado 422 coleta inválida ou ponto fora dos limites
     */
    public function criar(Vendor $vendor, ?Place $coleta, array $dados, string $usuarioUuid): array
    {
        if ($repetido = $this->repetido($vendor, $dados['chave'])) {
            return $repetido;
        }

        $empresa = (string) $vendor->company_uuid;
        if (!$coleta || !$this->coletaValida($coleta, $vendor, $empresa)) {
            Log::warning('[entregas] portal da loja: loja sem local de coleta válido', ['loja' => $vendor->public_id]);
            throw new PedidoRecusado(422, 'A loja ainda não tem endereço de coleta cadastrado. Fale com a central.');
        }

        $metros = PedidoDoIfood::metrosEntre((float) $coleta->location->getLat(), (float) $coleta->location->getLng(), $dados['latitude'], $dados['longitude']);
        if ($metros < RegrasPortalLoja::DISTANCIA_MINIMA_DA_COLETA_METROS) {
            throw new PedidoRecusado(422, 'O ponto da entrega está no mesmo lugar da loja. Arraste o mapa até o local da entrega.');
        }
        if ($metros > CriadorDoPedidoDaIntegracao::DISTANCIA_MAXIMA_METROS) {
            throw new PedidoRecusado(422, 'O ponto da entrega está a mais de 50 km da loja. Confira a cidade e o ponto no mapa.');
        }

        // o TrackingNumberObserver e o OrderConfig::default() leem a empresa da sessão
        session(['company' => $empresa, 'user' => $usuarioUuid]);
        $reaproveitado = $this->lugarParaReaproveitar($vendor, $dados);

        try {
            [$linha, $pedido] = DB::transaction(fn () => $this->gravar($vendor, $coleta, $dados, $usuarioUuid, $reaproveitado));
        } catch (QueryException $e) {
            // a mesma chave chegou duas vezes no mesmo instante: a chave única segurou a segunda, que devolve a primeira
            if ((string) $e->getCode() === '23000' && ($repetido = $this->repetido($vendor, $dados['chave']))) {
                return $repetido;
            }
            throw $e;
        }

        Log::info('[entregas] portal da loja: motoboy chamado', ['pedido' => $pedido->public_id, 'loja' => $vendor->public_id, 'endereco_reaproveitado' => $reaproveitado !== null]);
        $this->despachar($pedido, $usuarioUuid);

        return [$linha, $pedido, true];
    }

    /** O pedido que já nasceu com esta chave na loja, ou null. */
    public function repetido(Vendor $vendor, string $chave): ?array
    {
        $linha  = PedidosDoPortal::daChave((string) $vendor->uuid, $chave);
        $pedido = $linha ? Order::where('uuid', $linha->order_uuid)->where('company_uuid', $vendor->company_uuid)->first() : null;
        if (!$pedido) {
            return null;
        }
        Log::info('[entregas] portal da loja: chamado repetido', ['pedido' => $pedido->public_id, 'loja' => $vendor->public_id]);

        return [$linha, $pedido, false];
    }

    /** Pedido aberto aos motoboys, com a trava do pedido e o pedido relido. Nunca lança. */
    public function despachar(Order $pedido, string $usuarioUuid): void
    {
        try {
            TravaDoPedido::executar((string) $pedido->uuid, function () use ($pedido, $usuarioUuid) {
                $atual = $pedido->fresh();
                if (!$atual || $atual->deleted_at || $atual->dispatched || $atual->started || in_array(strtolower((string) $atual->status), StatusDoPedido::ENCERRADOS, true)) {
                    return;
                }
                session(['company' => $atual->company_uuid, 'user' => $usuarioUuid]);
                // sem eventos: o despacho logo abaixo grava de novo e emite os eventos (OrderDispatched → distribuição)
                $atual->adhoc = true;
                $atual->saveQuietly();
                $atual->firstDispatchWithActivity();
            });
        } catch (LockTimeoutException $e) {
            Log::warning('[entregas] portal da loja: trava do pedido ocupada; despacho fica para a central', ['pedido' => $pedido->public_id]);
        } catch (\Throwable $e) {
            Log::error('[entregas] portal da loja: falha ao despachar o pedido aos motoboys', ['pedido' => $pedido->public_id, 'erro' => get_class($e)]);
        }
    }

    /** @return array{0: object, 1: Order} */
    protected function gravar(Vendor $vendor, Place $coleta, array $dados, string $usuarioUuid, ?Place $destino): array
    {
        $empresa = (string) $vendor->company_uuid;
        // mb_strtoupper: o PlaceObserver só faz strtoupper (ASCII) e gravaria "RIBEIRãO"
        $maiusculo = fn (?string $texto) => $texto === null ? null : mb_strtoupper($texto, 'UTF-8');
        $destino ??= Place::create([
            'company_uuid' => $empresa,
            'owner_uuid'   => $vendor->uuid,
            'owner_type'   => Utils::getMutationType($vendor),
            'name'         => $maiusculo($dados['cliente_nome']),
            'street1'      => $maiusculo($dados['rua']),
            'street2'      => $maiusculo($dados['complemento']),
            'neighborhood' => $maiusculo($dados['bairro']),
            'city'         => $maiusculo($dados['cidade']),
            'province'     => $dados['uf'],
            'postal_code'  => $dados['cep'],
            'country'      => 'BR',
            'location'     => new Point($dados['latitude'], $dados['longitude']),
        ]);

        $payload               = new Payload();
        $payload->company_uuid = $empresa;
        $payload->setPickup($coleta, [
            'callback' => function ($pickup, $payload) {
                $payload->setCurrentWaypoint($pickup);
            },
        ]);
        $payload->setDropoff($destino);
        $payload->save();

        $config = OrderConfig::default() ?? OrderConfig::where('company_uuid', $empresa)->first();
        if (!$config) {
            throw new \RuntimeException('a empresa não tem tipo de pedido (OrderConfig)');
        }

        $agora  = now()->toDateTimeString();
        $pedido = Order::create([
            'company_uuid'      => $empresa,
            'customer_uuid'     => $vendor->uuid,
            'customer_type'     => Utils::getMutationType($vendor),
            'payload_uuid'      => $payload->uuid,
            'order_config_uuid' => $config->uuid,
            'type'              => $config->key,
            'status'            => 'created',
            'adhoc'             => true,
            'meta'              => ['customer_portal' => ['created_by_uuid' => $usuarioUuid, 'created_at' => $agora]],
        ]);

        $id = DB::table(PedidosDoPortal::TABELA)->insertGetId([
            'company_uuid'        => $empresa,
            'vendor_uuid'         => $vendor->uuid,
            'order_uuid'          => $pedido->uuid,
            'place_uuid'          => $destino->uuid,
            'chave'               => $dados['chave'],
            'cliente_nome'        => $dados['cliente_nome'],
            'cliente_telefone'    => $dados['cliente_telefone'],
            'complemento'         => $dados['complemento'],
            'referencia'          => $dados['referencia'],
            'observacoes'         => $dados['observacoes'],
            'cobranca_forma'      => $dados['cobranca_forma'],
            'cobranca_centavos'   => $dados['cobranca_centavos'],
            'troco_para_centavos' => $dados['troco_para_centavos'],
            'created_at'          => $agora,
            'updated_at'          => $agora,
        ]);

        return [DB::table(PedidosDoPortal::TABELA)->where('id', $id)->first(), $pedido];
    }

    /** O Local de um pedido recente do mesmo telefone na loja, com o mesmo nome, rua e complemento e o ponto a até 30 m. */
    protected function lugarParaReaproveitar(Vendor $vendor, array $dados): ?Place
    {
        $vistos = [];
        foreach (PedidosDoPortal::doTelefone((string) $vendor->company_uuid, (string) $vendor->uuid, $dados['cliente_telefone']) as $linha) {
            $uuid = (string) ($linha->place_uuid ?? '');
            if ($uuid === '' || isset($vistos[$uuid])) {
                continue;
            }
            $vistos[$uuid] = true;
            $lugar         = Place::where('uuid', $uuid)->where('company_uuid', $vendor->company_uuid)->where('owner_uuid', $vendor->uuid)->first();
            if ($lugar && $this->mesmoLugar($lugar, $dados)) {
                return $lugar;
            }
        }

        return null;
    }

    protected function mesmoLugar(Place $lugar, array $dados): bool
    {
        $igual = fn ($a, $b) => mb_strtoupper(trim((string) $a), 'UTF-8') === mb_strtoupper(trim((string) $b), 'UTF-8');

        return ($lugar->location ?? null) instanceof Point
            && $igual($lugar->name ?? '', $dados['cliente_nome'])
            && $igual($lugar->street1 ?? '', $dados['rua'])
            && $igual($lugar->street2 ?? '', $dados['complemento'] ?? '')
            && PedidoDoIfood::metrosEntre((float) $lugar->location->getLat(), (float) $lugar->location->getLng(), $dados['latitude'], $dados['longitude']) <= static::REAPROVEITAR_ATE_METROS;
    }

    /** Mesmo critério do portal, do iFood e da integração para a coleta: da empresa, com dono = o Vendor e com coordenadas. */
    protected function coletaValida(Place $coleta, Vendor $vendor, string $empresa): bool
    {
        return $empresa !== ''
            && $coleta->company_uuid === $empresa
            && ($coleta->owner_uuid ?? null) === $vendor->uuid
            && ($coleta->owner_type ?? null) === Utils::getMutationType($vendor)
            && ($coleta->location ?? null) instanceof Point
            && Coordenada::valida($coleta->location->getLat(), $coleta->location->getLng());
    }
}
