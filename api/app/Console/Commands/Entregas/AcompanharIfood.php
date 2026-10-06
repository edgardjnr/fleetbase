<?php

namespace App\Console\Commands\Entregas;

use App\Jobs\Entregas\EnviarAcaoIfood;
use App\Support\Entregas\Ifood\ChegadaPeloGps;
use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\SequenciaIfood;
use App\Support\Entregas\StatusDoPedido;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: a cada 30 s (App\Console\Kernel), acompanha os pedidos iFood em andamento.
 *
 * - Chegada pelo GPS: compara a última posição do motoboy (drivers.location, gravada pelo track do app) com a coleta e
 *   a entrega (ChegadaPeloGps, raio de 100 m) e enfileira o arrivedAtOrigin ou o arrivedAtDestination. Não escuta cada
 *   posição: o DriverLocationChanged do Fleet-Ops sai por broadcast() e nem passa pelos listeners do Laravel, e uma
 *   leitura a cada 30 s não pesa no socket.
 * - Reconciliação: o pedido cujo estado pede uma ação além da `ultima_acao` (ou com motoboy trocado) e que o
 *   ObservadorDosPedidosIfood não pegou (scheduleOrder com saveQuietly, job perdido no Redis sem persistência) é
 *   enfileirado também.
 *
 * Fica de fora: cancelado pelo iFood, com recusa registrada (recusa_acao: uma ação recusada não se repete sozinha; a
 * próxima mudança do pedido tenta de novo), já com o arrivedAtDestination, sem Order, cancelado ou expirado, e o criado
 * há mais de JANELA_HORAS. EnviarAcaoIfood::enfileirar não enfileira um segundo job do mesmo pedido enquanto o primeiro
 * espera.
 */
class AcompanharIfood extends Command
{
    protected $signature = 'entregas:ifood-acompanhar';

    protected $description = 'iFood: chegada pelo GPS e reconciliação das ações de logística dos pedidos em andamento';

    public const PEDIDOS = 'entregas_ifood_pedidos';

    public const POR_RODADA = 300;

    /** Só pedidos criados nas últimas JANELA_HORAS (um agendado é criado até ~1 dia antes). */
    public const JANELA_HORAS = 24;

    public function handle(): int
    {
        if (!ClienteIfood::ligada()) {
            return self::SUCCESS;
        }

        $linhas = DB::table(static::PEDIDOS)
            ->whereNotNull('order_uuid')
            ->whereNull('cancelado_pelo_ifood_em')
            ->whereNull('recusa_acao')
            ->where('created_at', '>=', now()->subHours(static::JANELA_HORAS)->toDateTimeString())
            ->orderBy('id')
            ->limit(static::POR_RODADA)
            ->get()
            ->all();

        foreach ($linhas as $linha) {
            if ($linha->ultima_acao === SequenciaIfood::CHEGOU_NO_CLIENTE) {
                continue;
            }

            try {
                $this->acompanhar($linha);
            } catch (\Throwable $e) {
                Log::warning('[entregas] ifood: falha ao acompanhar o pedido', ['pedido_ifood' => $linha->pedido_ifood_id, 'erro' => get_class($e)]);
            }
        }

        return self::SUCCESS;
    }

    protected function acompanhar(object $linha): void
    {
        $pedido = Order::where('uuid', $linha->order_uuid)->first();
        if (!$pedido || ($pedido->status !== 'completed' && in_array($pedido->status, StatusDoPedido::ENCERRADOS, true))) {
            return;
        }

        $motoboyUuid = $pedido->driver_assigned_uuid ? (string) $pedido->driver_assigned_uuid : null;
        $estado      = SequenciaIfood::alvoPeloPedido($pedido->status, (bool) $pedido->started, $motoboyUuid);
        $etapa       = SequenciaIfood::maisAdiante($linha->ultima_acao, $estado);

        $chegada = null;
        if ($motoboyUuid !== null && $pedido->status !== 'completed') {
            $motoboy = Driver::where('uuid', $motoboyUuid)->first();
            $chegada = ChegadaPeloGps::acao(
                $etapa,
                static::ponto($motoboy?->location),
                static::ponto($pedido->payload?->getPickupOrFirstWaypoint()?->location),
                static::ponto($pedido->payload?->getDropoffOrLastWaypoint()?->location)
            );
        }

        $alvo  = SequenciaIfood::maisAdiante($estado, $chegada);
        $troca = $motoboyUuid !== null && $linha->ultima_acao !== null && $linha->motoboy_no_ifood && $linha->motoboy_no_ifood !== $motoboyUuid;
        if (!SequenciaIfood::faltando($linha->ultima_acao, $alvo) && !$troca) {
            return;
        }

        if (EnviarAcaoIfood::enfileirar((string) $linha->order_uuid, $chegada) && $chegada !== null) {
            Log::info('[entregas] ifood: chegada pelo GPS', ['acao' => $chegada, 'pedido' => $pedido->public_id, 'numero' => $linha->numero]);
        }
    }

    /** [latitude, longitude] de um Point, ou null. */
    protected static function ponto($ponto): ?array
    {
        return is_object($ponto) && method_exists($ponto, 'getLat') ? [(float) $ponto->getLat(), (float) $ponto->getLng()] : null;
    }
}
