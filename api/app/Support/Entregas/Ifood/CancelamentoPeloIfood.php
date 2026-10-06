<?php

namespace App\Support\Entregas\Ifood;

use App\Support\Entregas\StatusDoPedido;
use App\Support\Entregas\TravaDoPedido;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: o iFood cancelou o pedido (evento CAN, processado pelo ProcessarPedidoIfood). Spec, seção 3:
 *
 * - pedido já concluído: nada muda (só o cancelado_pelo_ifood_em e o log);
 * - nos outros casos, cancela como o portal da loja (RegrasPortalLoja::completarCancelamento): sai dos pedidos abertos
 *   sem eventos (dispatched e adhoc falsos, scheduled_at nulo, saveQuietly) e depois Order::cancel(): atividade
 *   "canceled" e OrderCanceled na fila (depois do commit), que o HandleOrderCanceled do Fleet-Ops transforma no push
 *   OrderCanceled ao motoboy atribuído. O texto do push sai do AvisosDoMotoboy: "Pedido #4821 cancelado pelo iFood";
 * - com o dispatch já aceito pelo iFood (ultima_acao dispatch ou depois) ou com o Order já "A caminho" (enroute: o
 *   motoboy saiu com o pedido, mesmo que o dispatch ainda não tenha chegado ao iFood): pago_mesmo_cancelado. O relatório de
 *   pagamento, a cobrança da loja e os ganhos do motoboy contam o pedido pelo valor congelado da faixa, na data do
 *   cancelamento (CalculoEntregas::pedidosConcluidos), e o push acrescenta "Você recebe por esta entrega…";
 * - pedido já cancelado (por uma tentativa anterior que caiu depois do cancel): só grava o que faltar;
 * - sem Order no Entregas: só grava o cancelado_pelo_ifood_em, com log info.
 *
 * Uma exceção no cancelamento sobe como RuntimeException com só a classe e o SQLSTATE (o failed_jobs guarda a exceção
 * inteira, e a do banco traz o SQL com dados do pedido); LockTimeoutException sobe como veio (o CAN fica pendente).
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
            static::logSemOrder($linha);

            return static::SEM_PEDIDO;
        }

        $orderUuid = (string) $linha->order_uuid;

        try {
            return TravaDoPedido::executar($orderUuid, fn () => $this->acoes->comATrava($orderUuid, fn () => $this->aplicarComAsTravas($orderUuid, $quando)));
        } catch (LockTimeoutException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $sqlstate = $e instanceof QueryException ? preg_replace('/[^0-9A-Z]/', '', (string) $e->getCode()) : null;
            throw new \RuntimeException('cancelamento pelo iFood falhou: ' . get_class($e) . ($sqlstate ? " (SQLSTATE {$sqlstate})" : ''));
        }
    }

    protected static function logSemOrder(object $linha): void
    {
        Log::info('[entregas] ifood: CAN de pedido sem Order no Entregas; só registrado', ['pedido_ifood' => $linha->pedido_ifood_id ?? null, 'numero' => $linha->numero ?? null]);
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
            static::logSemOrder($linha);

            return static::SEM_PEDIDO;
        }

        if ($pedido->status === 'completed') {
            PedidosIfood::atualizar($linha, ['cancelado_pelo_ifood_em' => $quando]);
            Log::info('[entregas] ifood: CAN de pedido já concluído; nada muda', ['pedido' => $pedido->public_id, 'numero' => $linha->numero]);

            return static::JA_CONCLUIDO;
        }

        // o motoboy já saiu com o pedido: o dispatch aceito pelo iFood ou o "A caminho" tocado no app (Order enroute)
        $pago = (bool) $linha->pago_mesmo_cancelado || SequenciaIfood::saiuParaEntrega($linha->ultima_acao) || $pedido->status === 'enroute';
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
