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
use Illuminate\Support\Carbon;
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
 * Fica de fora, já no SQL (os encerrados não gastam o limite POR_RODADA): cancelado pelo iFood, com recusa registrada
 * (recusa_acao: uma ação recusada não se repete sozinha; a próxima mudança do pedido tenta de novo), já com o
 * arrivedAtDestination, sem Order (ou apagado), Order encerrado que não seja o concluído (cancelado, expirado…) e o
 * que não foi despachado nem criado nas últimas JANELA_HORAS (o agendado é criado até ~1 dia antes e conta pelo
 * despachado_em). Índices: [despachado_em, despachar_em] e created_at. Posição do motoboy mais velha que
 * POSICAO_VALIDA_MINUTOS (drivers.updated_at: app fechado, sem sinal) não vale como chegada. EnviarAcaoIfood::enfileirar
 * não enfileira um segundo job do mesmo pedido enquanto o primeiro espera.
 */
class AcompanharIfood extends Command
{
    protected $signature = 'entregas:ifood-acompanhar';

    protected $description = 'iFood: chegada pelo GPS e reconciliação das ações de logística dos pedidos em andamento';

    public const PEDIDOS = 'entregas_ifood_pedidos';

    public const POR_RODADA = 300;

    /** Só pedidos despachados (ou, sem despacho, criados) nas últimas JANELA_HORAS. */
    public const JANELA_HORAS = 24;

    /** Idade máxima da última posição do motoboy (drivers.updated_at) para valer como chegada. */
    public const POSICAO_VALIDA_MINUTOS = 5;

    public function handle(): int
    {
        if (!ClienteIfood::ligada()) {
            return self::SUCCESS;
        }

        $p      = static::PEDIDOS;
        $janela = now()->subHours(static::JANELA_HORAS)->toDateTimeString();
        $linhas = DB::table($p)
            ->join('orders', 'orders.uuid', '=', "{$p}.order_uuid")
            ->whereNull("{$p}.cancelado_pelo_ifood_em")
            ->whereNull("{$p}.recusa_acao")
            ->where(fn ($q) => $q->whereNull("{$p}.ultima_acao")->orWhere("{$p}.ultima_acao", '!=', SequenciaIfood::CHEGOU_NO_CLIENTE))
            ->where(fn ($q) => $q->where("{$p}.despachado_em", '>=', $janela)->orWhere("{$p}.created_at", '>=', $janela))
            ->whereNotIn('orders.status', array_values(array_diff(StatusDoPedido::ENCERRADOS, ['completed'])))
            ->whereNull('orders.deleted_at')
            ->orderBy("{$p}.id")
            ->limit(static::POR_RODADA)
            ->select("{$p}.*")
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
                static::posicaoRecente($motoboy) ? static::ponto($motoboy->location) : null,
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

    /** A última posição do motoboy é recente (drivers.updated_at nos últimos POSICAO_VALIDA_MINUTOS)? */
    protected static function posicaoRecente(?object $motoboy): bool
    {
        if (!$motoboy || empty($motoboy->updated_at)) {
            return false;
        }

        return Carbon::parse($motoboy->updated_at) >= now()->subMinutes(static::POSICAO_VALIDA_MINUTOS);
    }

    /** [latitude, longitude] de um Point, ou null. */
    protected static function ponto($ponto): ?array
    {
        return is_object($ponto) && method_exists($ponto, 'getLat') ? [(float) $ponto->getLat(), (float) $ponto->getLng()] : null;
    }
}
