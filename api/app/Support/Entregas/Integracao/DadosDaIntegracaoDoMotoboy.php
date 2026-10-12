<?php

namespace App\Support\Entregas\Integracao;

use App\Support\Entregas\StatusDoPedido;

/**
 * Entregas RestaurantePro: o que o app do motoboy recebe de um pedido de outro sistema (GET
 * v1/entregas/motoboy/pedidos/{id}/integracao: card de aceitar e detalhes). Função pura sobre a linha da
 * entregas_integracao_pedidos e o Order. Pedido que não é da integração: {"integracao": false}. O telefone do cliente
 * só vai ao motoboy do pedido, com o pedido em andamento (a regra do 0800 do iFood).
 */
final class DadosDaIntegracaoDoMotoboy
{
    public static function resposta(?object $linha, object $pedido, string $motoboyUuid): array
    {
        if (!$linha) {
            return ['integracao' => false];
        }

        $doMotoboy = (string) $pedido->driver_assigned_uuid === $motoboyUuid;
        $encerrado = in_array($pedido->status, StatusDoPedido::ENCERRADOS, true);
        $centavos  = (int) $linha->cobranca_centavos;
        $troco     = $linha->troco_para_centavos !== null ? (int) $linha->troco_para_centavos : null;

        return [
            'integracao'           => true,
            'sistema'              => $linha->sistema,
            'numero'               => $linha->numero !== null ? (string) $linha->numero : null,
            'cobranca'             => [
                'forma'               => CobrancaDaIntegracao::forma($linha->cobranca_forma),
                'centavos'            => $centavos,
                'troco_para_centavos' => $troco,
                'texto'               => CobrancaDaIntegracao::texto($linha->cobranca_forma, $centavos, $troco),
            ],
            'observacoes'          => $linha->observacoes,
            'complemento'          => $linha->complemento,
            'referencia'           => $linha->referencia,
            'cancelado'            => (bool) $linha->cancelado_em,
            'pago_mesmo_cancelado' => (bool) $linha->pago_mesmo_cancelado,
            'telefone'             => $doMotoboy && !$encerrado && $linha->cliente_telefone ? (string) $linha->cliente_telefone : null,
        ];
    }
}
