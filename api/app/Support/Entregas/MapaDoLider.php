<?php

namespace App\Support\Entregas;

/**
 * Entregas RestaurantePro: a aba Mapa do líder dos motoboys no app (LiderController@mapa, relida a cada 10 s com a tela
 * aberta): os pedidos em andamento de todas as lojas (PedidosNoMapa::doLider, os mesmos do mapa do console) e os motoboys
 * do mapa (MotoboysNoMapaDaLoja::noMapa: os online e os offline com pedido aceito em andamento, os mesmos do mapa do
 * portal), com o public_id (a troca do motoboy usa), o nome, a posição, a situação (cor do capacete) e o online. Sem
 * telefone nem e-mail: o líder fala com os motoboys pelo chat de sempre.
 */
class MapaDoLider
{
    /**
     * @return array{pedidos: array<int, array>, motoboys: array<int, array{id: string, nome: ?string, latitude: float, longitude: float, situacao: string, online: bool}>}
     */
    public static function mapa(string $companyUuid): array
    {
        return ['pedidos' => PedidosNoMapa::doLider($companyUuid), 'motoboys' => static::motoboys($companyUuid)];
    }

    /**
     * @return array<int, array{id: string, nome: ?string, latitude: float, longitude: float, situacao: string, online: bool}>
     */
    public static function motoboys(string $companyUuid): array
    {
        return array_map(fn (array $linha) => [
            'id'        => $linha['motoboy']->public_id,
            'nome'      => $linha['motoboy']->name,
            'latitude'  => $linha['latitude'],
            'longitude' => $linha['longitude'],
            'situacao'  => $linha['situacao'],
            'online'    => (bool) $linha['motoboy']->online,
        ], MotoboysNoMapaDaLoja::noMapa($companyUuid));
    }
}
