<?php

namespace App\Support\Entregas;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vendor;

/**
 * Entregas RestaurantePro: os pedidos em andamento no mapa, um alfinete vermelho no endereço de entrega (desenho:
 * docs/superpowers/specs/2026-10-05-pedidos-no-mapa-design.md).
 *
 * - daCentral: todos os pedidos da empresa, com o nome da loja (MapaController@motoboys, mapa ao vivo do console);
 * - daLoja: só os pedidos dos donos informados (a loja da sessão e o contato do usuário), sem o nome da loja
 *   (PortalLojaController@motoboysNoMapa). A loja nunca recebe pedido, endereço ou cliente de outra loja (LGPD).
 * - doLider: os pedidos de daCentral para a aba Mapa do líder dos motoboys no app (LiderController), com o número do
 *   pedido (internal_id, o número do iFood), o public_id do motoboy (para a troca), a coleta e a hora da última
 *   atualização. É a exceção à regra do id do motoboy abaixo: o líder faz o papel da central (LiderDosMotoboys).
 *   umDoLider: um pedido nesse formato (resposta da troca do motoboy).
 *
 * Em andamento = status fora de StatusDoPedido::ENCERRADOS e atualizado nas últimas
 * SituacaoDoMotoboy::HORAS_PEDIDO_EM_ANDAMENTO horas, com ou sem motoboy (a mesma regra dos capacetes). Pedido sem destino
 * com coordenada válida fica de fora. Do motoboy sai só o nome: nunca o id (com ele, o canal driver.<id> do socket entrega
 * a posição e o telefone), o telefone ou o e-mail.
 */
class PedidosNoMapa
{
    /** No máximo tantos alfinetes, dos pedidos mais novos para os mais antigos. */
    public const LIMITE = 300;

    /** Relações que o doLider e o umDoLider leem (coleta, destino, motoboy com o nome e o número de rastreio). */
    public const RELACOES_DO_LIDER = ['payload.pickup', 'payload.dropoff', 'driverAssigned.user', 'trackingNumber'];

    /**
     * @return array<int, array{id: string, numero: string, latitude: float, longitude: float, endereco: ?string, status: ?string, motoboy: ?string, aceito: bool, criado_em: ?string, loja: ?string}>
     */
    public static function daCentral(string $companyUuid): array
    {
        $pedidos = static::consulta($companyUuid)
            ->applyDirectivesForPermissions('fleet-ops list order')
            ->with(['payload.pickup', 'payload.dropoff', 'driverAssigned.user', 'trackingNumber'])
            ->get();
        $lojas = static::nomesDasLojas($pedidos);

        return static::itens($pedidos, fn ($pedido) => ['loja' => static::nomeDaLoja($pedido, $lojas)]);
    }

    /**
     * @param array<int, string> $donos customer_uuid dos pedidos da loja: o Vendor e o contato do usuário
     *
     * @return array<int, array{id: string, numero: string, latitude: float, longitude: float, endereco: ?string, status: ?string, motoboy: ?string, aceito: bool, criado_em: ?string}>
     */
    public static function daLoja(string $companyUuid, array $donos): array
    {
        if ($donos === []) {
            return [];
        }

        $pedidos = static::consulta($companyUuid)
            ->whereIn('customer_uuid', $donos)
            ->with(['payload.dropoff', 'driverAssigned.user', 'trackingNumber'])
            ->get();

        return static::itens($pedidos);
    }

    /**
     * @return array<int, array{id: string, numero: string, latitude: float, longitude: float, endereco: ?string, status: ?string, motoboy: ?string, aceito: bool, criado_em: ?string, loja: ?string, motoboy_id: ?string, atualizado_em: ?string, coleta: ?array{latitude: float, longitude: float}}>
     */
    public static function doLider(string $companyUuid): array
    {
        $pedidos = static::consulta($companyUuid)
            ->applyDirectivesForPermissions('fleet-ops list order')
            ->with(static::RELACOES_DO_LIDER)
            ->get();
        $lojas = static::nomesDasLojas($pedidos);

        return static::itens($pedidos, fn ($pedido) => static::doLiderExtra($pedido, $lojas));
    }

    /** Um pedido no formato do doLider (o pedido já vem com as RELACOES_DO_LIDER); null se o destino não tem coordenada. */
    public static function umDoLider($pedido): ?array
    {
        $lojas = static::nomesDasLojas([$pedido]);

        return static::itens([$pedido], fn ($pedido) => static::doLiderExtra($pedido, $lojas))[0] ?? null;
    }

    protected static function doLiderExtra($pedido, array $lojas): array
    {
        $coleta    = $pedido->payload?->pickup;
        $latitude  = $coleta?->location?->getLat();
        $longitude = $coleta?->location?->getLng();

        return [
            // o número que o motoboy e a loja conhecem: o do iFood (internal_id); sem ele, o de rastreio
            'numero'        => $pedido->internal_id ?: ($pedido->trackingNumber?->tracking_number ?: $pedido->public_id),
            'loja'          => static::nomeDaLoja($pedido, $lojas),
            'motoboy_id'    => $pedido->driverAssigned?->public_id,
            'atualizado_em' => $pedido->updated_at?->toIso8601String(),
            'coleta'        => Coordenada::valida($latitude, $longitude) ? ['latitude' => (float) $latitude, 'longitude' => (float) $longitude] : null,
        ];
    }

    protected static function consulta(string $companyUuid)
    {
        return Order::where('company_uuid', $companyUuid)
            ->whereNotIn('status', StatusDoPedido::ENCERRADOS)
            ->where('updated_at', '>=', now()->subHours(SituacaoDoMotoboy::HORAS_PEDIDO_EM_ANDAMENTO))
            ->orderBy('created_at', 'desc')
            ->limit(static::LIMITE);
    }

    protected static function itens(iterable $pedidos, ?\Closure $extra = null): array
    {
        $lista = [];
        foreach ($pedidos as $pedido) {
            $destino   = $pedido->payload?->dropoff;
            $latitude  = $destino?->location?->getLat();
            $longitude = $destino?->location?->getLng();
            if (!Coordenada::valida($latitude, $longitude)) {
                continue;
            }

            $lista[] = array_merge([
                'id'        => $pedido->public_id,
                'numero'    => $pedido->trackingNumber?->tracking_number ?: $pedido->public_id,
                'latitude'  => (float) $latitude,
                'longitude' => (float) $longitude,
                'endereco'  => $destino->address ?: $destino->name,
                'status'    => $pedido->status,
                'motoboy'   => $pedido->driverAssigned?->name,
                // o motoboy já aceitou (não só foi atribuído pela central): o alfinete ganha o nome dele fixo embaixo
                'aceito'    => (bool) $pedido->started,
                'criado_em' => $pedido->created_at?->toIso8601String(),
            ], $extra ? $extra($pedido) : []);
        }

        return $lista;
    }

    /**
     * Nome de cada loja (Vendor) dona dos pedidos, numa consulta só, pelo uuid. O uuid de um contato nunca está em
     * `vendors`, e o `place` que o Vendor sempre carrega ($with) não é usado aqui.
     *
     * @return array<string, ?string>
     */
    protected static function nomesDasLojas(iterable $pedidos): array
    {
        $uuids = [];
        foreach ($pedidos as $pedido) {
            if ($pedido->customer_uuid) {
                $uuids[$pedido->customer_uuid] = true;
            }
        }
        if ($uuids === []) {
            return [];
        }

        $nomes = [];
        foreach (Vendor::without('place')->whereIn('uuid', array_keys($uuids))->get(['uuid', 'name']) as $vendor) {
            $nomes[$vendor->uuid] = $vendor->name;
        }

        return $nomes;
    }

    /** A loja dona do pedido; sem loja cadastrada, o nome do local de coleta (como na cobrança). */
    protected static function nomeDaLoja($pedido, array $lojas): ?string
    {
        return ($lojas[$pedido->customer_uuid] ?? null) ?: ($pedido->payload?->pickup?->name ?: null);
    }
}
