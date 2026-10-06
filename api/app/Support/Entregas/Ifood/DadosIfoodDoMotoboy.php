<?php

namespace App\Support\Entregas\Ifood;

use App\Support\Entregas\StatusDoPedido;
use Illuminate\Support\Carbon;

/**
 * Entregas RestaurantePro: o que o app do motoboy recebe de um pedido iFood (GET v1/entregas/motoboy/pedidos/{id}/ifood:
 * card de aceitar e detalhes). Função pura sobre a linha da entregas_ifood_pedidos e o Order.
 *
 * - Pedido que não é do iFood: {"ifood": false} (o app chama para todos e só mostra o bloco quando é).
 * - Número, cobrança (centavos, forma em pt-BR, troco e o texto da CobrancaIfood), observações, complemento,
 *   referência, se exige código, se a conclusão já foi liberada e se o iFood cancelou (com o pago mesmo cancelado).
 * - 0800 e localizador do cliente só para o motoboy do pedido, com o pedido em andamento e antes da expiração do
 *   localizador (telefone_expira_em, texto no fuso do app; sem data = vale). No pedido ainda aberto, não vão.
 */
final class DadosIfoodDoMotoboy
{
    /** @param string $agora 'Y-m-d H:i:s' no fuso do app */
    public static function resposta(?object $linha, object $pedido, string $motoboyUuid, string $agora): array
    {
        if (!$linha) {
            return ['ifood' => false];
        }

        $doMotoboy = (string) $pedido->driver_assigned_uuid === $motoboyUuid;
        $encerrado = in_array($pedido->status, StatusDoPedido::ENCERRADOS, true);
        $expira    = $linha->telefone_expira_em ? (string) $linha->telefone_expira_em : null;
        $telefone  = null;
        if ($doMotoboy && !$encerrado && $linha->telefone_0800 && ($expira === null || substr($expira, 0, 19) > $agora)) {
            $telefone = [
                'numero'      => (string) $linha->telefone_0800,
                'localizador' => $linha->localizador !== null ? (string) $linha->localizador : null,
                'expira_em'   => $expira ? Carbon::parse(substr($expira, 0, 19), date_default_timezone_get())->toIso8601String() : null,
            ];
        }

        $centavos = (int) $linha->cobrar_centavos;
        $troco    = $linha->troco_para_centavos !== null ? (int) $linha->troco_para_centavos : null;

        return [
            'ifood'                => true,
            'numero'               => $linha->numero !== null ? (string) $linha->numero : null,
            'teste'                => (bool) $linha->teste,
            'cobranca'             => [
                'centavos'            => $centavos,
                'forma'               => CobrancaIfood::forma($linha->forma_pagamento),
                'troco_para_centavos' => $troco,
                'texto'               => CobrancaIfood::texto($centavos, $linha->forma_pagamento, $troco),
            ],
            'observacoes'          => $linha->observacoes,
            'complemento'          => $linha->complemento,
            'referencia'           => $linha->referencia,
            'exige_codigo'         => (bool) $linha->exige_codigo,
            'conclusao_liberada'   => (bool) $linha->conclusao_liberada_em,
            'cancelado_pelo_ifood' => (bool) $linha->cancelado_pelo_ifood_em,
            'pago_mesmo_cancelado' => (bool) $linha->pago_mesmo_cancelado,
            'telefone'             => $telefone,
        ];
    }
}
