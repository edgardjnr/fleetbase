<?php

namespace App\Support\Entregas\Integracao;

use App\Support\Entregas\Ifood\CancelamentoPeloIfood;
use App\Support\Entregas\SituacaoDoMotoboy;
use App\Support\Entregas\StatusDoPedido;
use App\Support\Entregas\TravaDoPedido;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: o sistema de origem cancelou o pedido (POST v1/entregas/integracao/pedidos/{id}/cancelar).
 * Spec, Parte 2, "Cancelamento pela origem":
 *
 * - sem aceite ou aceito antes do "A caminho": cancela sem pagar;
 * - "A caminho" (qualquer status de SituacaoDoMotoboy::STATUS_EM_ENTREGA, a lista do capacete vermelho: o motoboy está com a comida): cancela como pago mesmo cancelado (a regra do iFood): o
 *   relatório, a cobrança da loja e os ganhos contam o pedido pela data do cancelamento (CalculoEntregas), e o push
 *   acrescenta "Você recebe por esta entrega…";
 * - concluído: JA_CONCLUIDO (409 na rota), sem mexer;
 * - já cancelado (pela origem antes, pela central ou pelo portal): devolve o resultado sem cancelar de novo;
 * - expirado: só registra o cancelamento na linha;
 * - sem Order: só registra.
 *
 * Como o CancelamentoPeloIfood: com a TravaDoPedido (um aceite no mesmo instante espera e relê), sai dos pedidos abertos
 * sem eventos (dispatched e adhoc falsos, scheduled_at nulo, saveQuietly) e depois Order::cancel() (atividade
 * "canceled" e OrderCanceled → push ao motoboy atribuído; a distribuição encerra pelo ObservadorDaDistribuicao). A
 * linha é gravada antes do cancel(): o push (fila) e o aviso de volta leem o cancelado_por e o pago_mesmo_cancelado.
 *
 * Risco aceito (igual ao do iFood e ao do portal): o updateActivity do Fleet-Ops não toma a TravaDoPedido, então um
 * "A caminho" no mesmo instante do cancelamento pode ser gravado depois da leitura do status e o pedido ser cancelado
 * sem pagamento.
 *
 * @throws \Illuminate\Contracts\Cache\LockTimeoutException trava ocupada (a rota responde 503)
 */
class CancelamentoPelaOrigem
{
    public const CANCELADO      = 'cancelado';
    public const CANCELADO_PAGO = 'cancelado_pago';
    public const JA_CONCLUIDO   = 'ja_concluido';

    public function cancelar(object $linha): string
    {
        if (empty($linha->order_uuid)) {
            PedidosDaIntegracao::atualizar($linha, [
                'cancelado_em'  => $linha->cancelado_em ?? now()->toDateTimeString(),
                'cancelado_por' => $linha->cancelado_por ?? 'loja',
            ]);
            Log::info('[entregas] integração: cancelamento de pedido sem Order; só registrado', ['pedido_externo' => $linha->pedido_externo_id ?? null]);

            return static::CANCELADO;
        }

        $orderUuid = (string) $linha->order_uuid;

        return TravaDoPedido::executar($orderUuid, fn () => $this->comATrava($orderUuid, $linha));
    }

    /** Título e texto do push ao motoboy para o pedido cancelado pela origem, ou null (outro cancelamento). */
    public static function textoDoPush(object $linha): ?array
    {
        if (empty($linha->cancelado_em) || ($linha->cancelado_por ?? null) !== 'loja') {
            return null;
        }

        $numero = trim((string) ($linha->numero ?? ''));
        $titulo = $numero !== '' ? "Pedido #{$numero} cancelado pela loja" : 'Pedido cancelado pela loja';

        return [$titulo, !empty($linha->pago_mesmo_cancelado) ? CancelamentoPeloIfood::TEXTO_PAGO : 'Não precisa mais fazer esta entrega.'];
    }

    protected function comATrava(string $orderUuid, object $linhaRecebida): string
    {
        // relidos com a trava: um aceite ou um "A caminho" que terminou enquanto esperávamos aparece aqui
        $linha  = PedidosDaIntegracao::doPedido($orderUuid) ?? $linhaRecebida;
        $pedido = Order::where('uuid', $orderUuid)->first();

        if ($pedido && in_array($pedido->status, StatusDoPedido::CANCELADOS, true)) {
            return !empty($linha->pago_mesmo_cancelado) ? static::CANCELADO_PAGO : static::CANCELADO;
        }
        if ($pedido && in_array($pedido->status, ['completed', 'done'], true)) {
            return static::JA_CONCLUIDO;
        }

        $pago = $pedido !== null && in_array(strtolower((string) $pedido->status), SituacaoDoMotoboy::STATUS_EM_ENTREGA, true);
        PedidosDaIntegracao::atualizar($linha, [
            'cancelado_em'         => $linha->cancelado_em ?? now()->toDateTimeString(),
            'cancelado_por'        => $linha->cancelado_por ?? ($pedido !== null && $pedido->status === 'expired' ? 'sistema' : 'loja'),
            'pago_mesmo_cancelado' => $pago,
        ]);

        if (!$pedido) {
            Log::info('[entregas] integração: cancelamento de pedido sem Order; só registrado', ['pedido_externo' => $linha->pedido_externo_id]);

            return static::CANCELADO;
        }
        if ($pedido->status === 'expired') {
            return static::CANCELADO;
        }

        // primeiro e sem eventos: sai dos pedidos abertos do app e do agendamento mesmo que o cancel() falhe
        $pedido->dispatched   = false;
        $pedido->adhoc        = false;
        $pedido->scheduled_at = null;
        $pedido->saveQuietly();

        session(['company' => $pedido->company_uuid]);
        $pedido->cancel();

        Log::warning('[entregas] integração: pedido cancelado pela origem', [
            'pedido'               => $pedido->public_id,
            'pago_mesmo_cancelado' => $pago,
            'com_motoboy'          => (bool) $pedido->driver_assigned_uuid,
        ]);

        return $pago ? static::CANCELADO_PAGO : static::CANCELADO;
    }
}
