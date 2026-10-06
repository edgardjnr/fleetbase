<?php

namespace App\Support\Entregas\Ifood;

use App\Support\Entregas\StatusDoPedido;
use App\Support\Entregas\TravaDoPedido;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: o iFood cancelou o pedido (evento CAN, processado pelo ProcessarPedidoIfood). Spec, seção 3:
 *
 * - pedido já concluído: nada muda (só o cancelado_pelo_ifood_em e o log);
 * - nos outros casos, cancela como o portal da loja (RegrasPortalLoja::completarCancelamento): sai dos pedidos abertos
 *   sem eventos (dispatched e adhoc falsos, scheduled_at nulo, saveQuietly) e depois Order::cancel(): atividade
 *   "canceled" e OrderCanceled na fila (depois do commit), que o HandleOrderCanceled do Fleet-Ops transforma no push
 *   OrderCanceled ao motoboy atribuído. O texto do push sai do AvisosDoMotoboy: "Pedido #4821 cancelado pelo iFood";
 * - com o dispatch já aceito pelo iFood (ultima_acao dispatch ou depois): pago_mesmo_cancelado. O relatório de
 *   pagamento, a cobrança da loja e os ganhos do motoboy contam o pedido pelo valor congelado da faixa, na data do
 *   cancelamento (CalculoEntregas::pedidosConcluidos), e o push acrescenta "Você recebe por esta entrega…";
 * - pedido já cancelado (por uma tentativa anterior que caiu depois do cancel): só grava o que faltar.
 *
 * Roda com a trava do pedido (TravaDoPedido, a mesma do aceite do motoboy) e, dentro dela, a das ações do iFood
 * (AcoesIfood::comATrava): um aceite ou um dispatch no mesmo instante esperam, e a ultima_acao lida é a definitiva.
 * Grava a linha antes do cancel(): o push do OrderCanceled (fila) lê o cancelado_pelo_ifood_em e o pago_mesmo_cancelado
 * para o texto. Idempotente: o job chama de novo enquanto o CAN estiver pendente.
 */
class CancelamentoPeloIfood
{
    public const CANCELADO      = 'cancelado';
    public const CANCELADO_PAGO = 'cancelado_pago';
    public const JA_CONCLUIDO   = 'ja_concluido';
    public const JA_CANCELADO   = 'ja_cancelado';
    public const SEM_PEDIDO     = 'sem_pedido';

    /** O que o push acrescenta quando o motoboy recebe mesmo com o cancelamento (spec, seção 3). */
    public const TEXTO_PAGO = 'Você recebe por esta entrega. Combine com a loja a devolução.';

    public function __construct(protected AcoesIfood $acoes) {}

    /**
     * @param string $quando a data do CAN (createdAt do evento, já no fuso do app)
     *
     * @return string o que aconteceu (uma das constantes)
     *
     * @throws LockTimeoutException trava do pedido ou das ações ocupada (o CAN fica pendente e o job tenta de novo)
     */
    public function aplicar(object $linha, string $quando): string
    {
        if (!$linha->order_uuid) {
            PedidosIfood::atualizar($linha, ['cancelado_pelo_ifood_em' => $linha->cancelado_pelo_ifood_em ?? $quando]);

            return static::SEM_PEDIDO;
        }

        $orderUuid = (string) $linha->order_uuid;

        return TravaDoPedido::executar($orderUuid, fn () => $this->acoes->comATrava($orderUuid, fn () => $this->aplicarComAsTravas($orderUuid, $quando)));
    }

    /**
     * Título e texto do push ao motoboy (AvisosDoMotoboy, no OrderCanceled) para o pedido cancelado pelo iFood, ou null
     * se o cancelamento não foi do iFood.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function textoDoPush(object $linha): ?array
    {
        if (empty($linha->cancelado_pelo_ifood_em)) {
            return null;
        }

        $numero = trim((string) ($linha->numero ?? ''));
        $titulo = $numero !== '' ? "Pedido #{$numero} cancelado pelo iFood" : 'Pedido cancelado pelo iFood';

        return [$titulo, !empty($linha->pago_mesmo_cancelado) ? static::TEXTO_PAGO : 'Não precisa mais fazer esta entrega.'];
    }

    protected function aplicarComAsTravas(string $orderUuid, string $quando): string
    {
        // relidos com as travas: um aceite ou uma ação que terminou enquanto o job esperava aparece aqui
        $linha  = PedidosIfood::doPedido($orderUuid);
        $pedido = Order::where('uuid', $orderUuid)->first();
        $quando = $linha->cancelado_pelo_ifood_em ?? $quando;

        if (!$pedido) {
            PedidosIfood::atualizar($linha, ['cancelado_pelo_ifood_em' => $quando]);

            return static::SEM_PEDIDO;
        }

        if ($pedido->status === 'completed') {
            PedidosIfood::atualizar($linha, ['cancelado_pelo_ifood_em' => $quando]);
            Log::info('[entregas] ifood: CAN de pedido já concluído; nada muda', ['pedido' => $pedido->public_id, 'numero' => $linha->numero]);

            return static::JA_CONCLUIDO;
        }

        $pago = (bool) $linha->pago_mesmo_cancelado || SequenciaIfood::saiuParaEntrega($linha->ultima_acao);
        PedidosIfood::atualizar($linha, ['cancelado_pelo_ifood_em' => $quando, 'pago_mesmo_cancelado' => $pago]);

        if (in_array($pedido->status, StatusDoPedido::CANCELADOS, true)) {
            return static::JA_CANCELADO;
        }

        // primeiro e sem eventos: sai dos pedidos abertos do app e do agendamento mesmo que o cancel() falhe
        $pedido->dispatched   = false;
        $pedido->adhoc        = false;
        $pedido->scheduled_at = null;
        $pedido->saveQuietly();

        session(['company' => $pedido->company_uuid]);
        // atividade "canceled" + OrderCanceled na fila → HandleOrderCanceled avisa o motoboy atribuído (push)
        $pedido->cancel();

        Log::warning('[entregas] ifood: pedido cancelado pelo iFood', [
            'pedido'               => $pedido->public_id,
            'order_uuid'           => $pedido->uuid,
            'numero'               => $linha->numero,
            'pago_mesmo_cancelado' => $pago,
            'com_motoboy'          => (bool) $pedido->driver_assigned_uuid,
        ]);

        return $pago ? static::CANCELADO_PAGO : static::CANCELADO;
    }
}
