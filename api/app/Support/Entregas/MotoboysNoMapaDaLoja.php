<?php

namespace App\Support\Entregas;

use Fleetbase\FleetOps\Models\Driver;

/**
 * Entregas RestaurantePro: os motoboys no mapa do portal da loja (PortalLojaController@motoboysNoMapa, consultado a cada
 * 5 s com o mapa aberto).
 *
 * Decisão de 2026-10-04: a loja vê todos os motoboys online, com a cor da situação (a regra do mapa do console,
 * SituacaoDoMotoboy) e o nome, inclusive os que levam pedidos de outras lojas. Aparece também o offline que já aceitou um
 * pedido ainda em andamento (está trabalhando). Some o offline sem pedido aceito, mesmo com pedido só atribuído pela
 * central (a última posição dele pode ser a casa), e quem não tem coordenada válida.
 *
 * Nunca sai daqui o id do motoboy (public_id ou uuid: com ele, o canal driver.<id> do socket entrega a posição ao vivo e o
 * telefone), nem telefone, e-mail, veículo ou pedido de outra loja: o `id` é um HMAC do uuid com a chave do app, e
 * `pedidos` traz só os pedidos dos donos informados (a loja da sessão e o contato do usuário).
 */
class MotoboysNoMapaDaLoja
{
    /**
     * @param array<int, string> $donos customer_uuid dos pedidos da loja: o Vendor e o contato do usuário
     *
     * @return array<int, array{id: string, nome: ?string, latitude: float, longitude: float, situacao: string, pedidos: array<int, string>}>
     */
    public static function listar(string $companyUuid, array $donos): array
    {
        // o nome do motoboy vem do usuário dele: carregado junto, e não um a um dentro do accessor
        $motoboys          = Driver::where('company_uuid', $companyUuid)->with('user')->get();
        $pedidosPorMotoboy = SituacaoDoMotoboy::pedidosEmAndamento($companyUuid, $motoboys->pluck('uuid'));

        $lista = [];
        foreach ($motoboys as $motoboy) {
            $pedidos = $pedidosPorMotoboy[$motoboy->uuid] ?? [];
            $online  = (bool) $motoboy->online;

            // offline só aparece trabalhando: com um pedido aceito (started) ainda em andamento
            if (!$online && !static::aceitouAlgum($pedidos)) {
                continue;
            }

            $latitude  = $motoboy->location?->getLat();
            $longitude = $motoboy->location?->getLng();
            if (!static::coordenadaValida($latitude, $longitude)) {
                continue;
            }

            // array_map, e não array_column: com o model do Eloquent, o array_column pula status nulo
            $situacao = SituacaoDoMotoboy::classificar($online, array_map(fn ($pedido) => $pedido->status, $pedidos));
            if ($situacao === SituacaoDoMotoboy::OFFLINE) {
                continue;
            }

            $daLoja = array_filter($pedidos, fn ($pedido) => in_array($pedido->customer_uuid, $donos, true));

            $lista[] = [
                'id'        => static::idOpaco($motoboy->uuid),
                'nome'      => $motoboy->name,
                'latitude'  => (float) $latitude,
                'longitude' => (float) $longitude,
                'situacao'  => $situacao,
                'pedidos'   => array_values(array_map(fn ($pedido) => $pedido->public_id, $daLoja)),
            ];
        }

        return $lista;
    }

    /** Id estável para o portal saber qual capacete mover, sem permitir chegar ao motoboy (nem ao canal do socket). */
    public static function idOpaco(string $uuid): string
    {
        return substr(hash_hmac('sha256', $uuid, (string) config('app.key')), 0, 16);
    }

    protected static function aceitouAlgum(array $pedidos): bool
    {
        foreach ($pedidos as $pedido) {
            if ($pedido->started) {
                return true;
            }
        }

        return false;
    }

    /** Números dentro da faixa e fora do (0, 0), que é o "sem GPS" do Fleetbase (mesmo critério do CalculoEntregas). */
    protected static function coordenadaValida($latitude, $longitude): bool
    {
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return false;
        }

        $latitude  = (float) $latitude;
        $longitude = (float) $longitude;

        if (abs($latitude) > 90 || abs($longitude) > 180) {
            return false;
        }

        return !(abs($latitude) <= 0.0001 && abs($longitude) <= 0.0001);
    }
}
