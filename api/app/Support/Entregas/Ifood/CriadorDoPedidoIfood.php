<?php

namespace App\Support\Entregas\Ifood;

use App\Support\Entregas\Coordenada;
use App\Support\Entregas\StatusDoPedido;
use App\Support\Entregas\TravaDoPedido;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: cria no Fleetbase o pedido que veio do iFood e o despacha aos motoboys. Adaptador fino sobre
 * os models do Fleet-Ops, no mesmo formato dos pedidos do portal da loja (PortalOrderService::buildPayloadFromInput e o
 * OrderController do customer-portal):
 * - cliente = a Loja (Vendor) do vínculo; coleta = o Local da Loja (não o endereço do iFood);
 * - entrega = Place novo com as coordenadas do iFood (sem geocodificação) e sem dono (não entra nos endereços salvos
 *   da loja no portal);
 * - Order tipo transport, status created, internal_id = número do iFood, notas "iFood #4821";
 * - adhoc só no pedido que vai aos motoboys sozinho: o imediato e o agendado com janela (o fleetops:dispatch-orders
 *   despacha o agendado sem ligar o adhoc). O de teste, o sem coordenadas e o agendado sem janela nascem com adhoc
 *   falso: a central atribui, e o despacho manual de um pedido adhoc avisaria todos os motoboys livres no raio (um
 *   deles poderia tomar o pedido do motoboy atribuído: HandleOrderDispatched + startOrder com assign);
 * - a linha de entregas_ifood_pedidos entra na mesma transação do Order: o pedido_ifood_id único impede um segundo
 *   pedido para o mesmo pedido do iFood (se a linha falhar, o Order, o Payload e o Place da entrega são desfeitos).
 *
 * Coleta = o Local da loja, conferido como no portal (RegrasPortalLoja): da mesma empresa, com dono = o Vendor e com
 * coordenadas válidas (Coordenada::valida, fora do (0, 0) "sem GPS"). Senão, o pedido não é criado (erro no log).
 *
 * A empresa vai para a sessão antes de criar: o TrackingNumberObserver e o OrderConfig::default() a leem de lá.
 * Despacho como o do portal (RegrasPortalLoja::despacharParaMotoboys): adhoc + firstDispatchWithActivity, com a trava
 * do pedido (TravaDoPedido). Pedido de teste, sem coordenadas ou agendado não é despachado aqui (o agendado sai pelo
 * entregas:ifood-agendados). Coleta a mais de 300 m do endereço da loja no iFood gera aviso no log (um dos cadastros
 * deve estar errado).
 *
 * Eventos dentro da transação: o Order::create (WebhookEventsObserver) põe o broadcast ResourceLifecycleEvent na fila
 * antes do commit. Hoje isso não quebra porque o criar() roda no job ProcessarPedidoIfood, no único worker da fila
 * (serviço queue com `replicas: 1` no deploy/docker-stack.yml, um `queue:work`): o broadcast só é processado depois que
 * este job termina, já com o commit. Com mais workers (ou réplicas), outro worker poderia pegar o broadcast antes do
 * commit e não achar o pedido: ver `afterCommit` (dispatch()->afterCommit() ou `after_commit` na conexão da fila).
 * O OrderDispatched do despacho já sai com afterCommit (Order::dispatch) e fica fora da transação.
 */
class CriadorDoPedidoIfood
{
    public const TABELA = 'entregas_ifood_pedidos';

    /** Distância máxima entre o Local da loja e o endereço dela no iFood antes do aviso no log. */
    public const DIVERGENCIA_MAXIMA_METROS = 300;

    /** Status em que o pedido já despachado ainda recebe a atividade "dispatched" que falta (antes do aceite). */
    public const STATUS_ANTES_DO_ACEITE = ['created', 'dispatched'];

    /** Cria o pedido e devolve a linha de entregas_ifood_pedidos; null se a loja não tem Local de coleta válido. */
    public function criar(object $vinculo, array $pedidoIfood): ?object
    {
        $vendor = Vendor::where('uuid', $vinculo->vendor_uuid)->where('company_uuid', $vinculo->company_uuid)->first();
        $coleta = $vendor && $vendor->place_uuid ? Place::where('uuid', $vendor->place_uuid)->first() : null;
        if (!$vendor || !$coleta || !$this->coletaValida($coleta, $vendor, $vinculo->company_uuid)) {
            Log::error('[entregas] ifood: loja sem local de coleta válido; pedido não criado', ['merchant' => $vinculo->merchant_id, 'numero' => $pedidoIfood['displayId'] ?? null]);

            return null;
        }

        $dados = PedidoDoIfood::mapear($pedidoIfood, (float) $coleta->location->getLat(), (float) $coleta->location->getLng(), now());
        $this->conferirColeta($pedidoIfood, $coleta, $dados);
        if ($dados['sem_coordenadas']) {
            Log::warning('[entregas] ifood: pedido sem coordenadas de entrega; não despachado', ['merchant' => $vinculo->merchant_id, 'numero' => $dados['numero']]);
        }

        // o TrackingNumberObserver e o OrderConfig::default() leem a empresa da sessão
        session(['company' => $vinculo->company_uuid]);

        $pedido = DB::transaction(function () use ($vinculo, $vendor, $coleta, $dados, $pedidoIfood) {
            $entrega = $dados['entrega'];
            // nome e endereço em maiúsculas com mb_strtoupper: o PlaceObserver só faz strtoupper (ASCII) e gravaria "RIBEIRãO"
            $maiusculo = fn (?string $texto) => $texto === null ? null : mb_strtoupper($texto, 'UTF-8');
            $destino   = Place::create([
                'company_uuid' => $vinculo->company_uuid,
                'name'         => $maiusculo($entrega['nome']),
                'street1'      => $maiusculo($entrega['street1']),
                'street2'      => $maiusculo($entrega['street2']),
                'neighborhood' => $maiusculo($entrega['neighborhood']),
                'city'         => $maiusculo($entrega['city']),
                'province'     => $maiusculo($entrega['province']),
                'postal_code'  => $entrega['postal_code'],
                'country'      => $entrega['country'],
                'location'     => new Point($entrega['latitude'], $entrega['longitude']),
            ]);

            $payload               = new Payload();
            $payload->company_uuid = $vinculo->company_uuid;
            $payload->setPickup($coleta, [
                'callback' => function ($pickup, $payload) {
                    $payload->setCurrentWaypoint($pickup);
                },
            ]);
            $payload->setDropoff($destino);
            $payload->save();

            $config = OrderConfig::default() ?? OrderConfig::where('company_uuid', $vinculo->company_uuid)->first();
            if (!$config) {
                throw new \RuntimeException('a empresa não tem tipo de pedido (OrderConfig)');
            }

            $pedido = Order::create([
                'company_uuid'      => $vinculo->company_uuid,
                'customer_uuid'     => $vendor->uuid,
                'customer_type'     => Utils::getMutationType($vendor),
                'payload_uuid'      => $payload->uuid,
                'order_config_uuid' => $config->uuid,
                'type'              => $config->key,
                'status'            => 'created',
                // adhoc só quando vai aos motoboys sozinho: agora (despachar_agora) ou pelo fleetops:dispatch-orders
                // (scheduled_at), que despacha sem ligar o adhoc. Os outros a central atribui: num pedido adhoc, o
                // despacho manual avisaria todos os motoboys livres no raio, e um deles poderia tomar o pedido do
                // motoboy atribuído (HandleOrderDispatched + startOrder com assign)
                'adhoc'             => $dados['despachar_agora'] || $dados['scheduled_at'] !== null,
                'internal_id'       => $dados['numero'],
                'scheduled_at'      => $dados['scheduled_at'],
                'notes'             => $dados['notas'],
            ]);

            $agora = now()->toDateTimeString();
            DB::table(static::TABELA)->insert($dados['linha'] + [
                'company_uuid'    => $vinculo->company_uuid,
                'order_uuid'      => $pedido->uuid,
                'pedido_ifood_id' => (string) $pedidoIfood['id'],
                'merchant_id'     => $vinculo->merchant_id,
                'vendor_uuid'     => $vendor->uuid,
                'created_at'      => $agora,
                'updated_at'      => $agora,
            ]);

            return $pedido;
        });

        Log::info('[entregas] ifood: pedido criado', ['pedido' => $pedido->public_id, 'numero' => $dados['numero'], 'teste' => $dados['teste'], 'agendado' => $dados['agendado']]);

        if ($dados['despachar_agora']) {
            $this->despachar($pedido);
        }

        return DB::table(static::TABELA)->where('pedido_ifood_id', (string) $pedidoIfood['id'])->first();
    }

    /**
     * Pedido aberto (adhoc) aos motoboys próximos da coleta, como o portal, com a trava do pedido (TravaDoPedido, a
     * mesma do aceite do motoboy e do cancelamento) e o pedido relido com ela.
     *
     * Devolve true quando o pedido foi (ou já estava) despachado: marca despachado_em. Devolve false quando não
     * despachou:
     * - falha temporária (trava ocupada por mais de TravaDoPedido::ESPERA s ou erro no despacho): a linha fica na fila
     *   e o entregas:ifood-agendados tenta de novo na rodada seguinte;
     * - nada a despachar: o pedido sumiu, foi apagado ou encerrado (StatusDoPedido::ENCERRADOS), já foi aceito
     *   (started) ou a central atribuiu um motoboy. A linha sai da fila (despachar_em nulo, como o agendador faz com o
     *   encerrado) e o log `[entregas] ifood: pedido não despachado` registra o motivo, só com o id do pedido.
     *
     * Já despachado (dispatched) sem a atividade "dispatched": só insere a atividade, sem avisar os motoboys de novo, e
     * só se o status ainda for created/dispatched. É reserva: o fleetops:dispatch-orders despacha o agendado (quem cai a
     * ±1 min do scheduled_at) COM a atividade, porque o HandleOrderDispatched cria a DISPATCHED quando falta; ela só
     * faltaria se o listener ainda não tivesse rodado na fila ou tivesse falhado.
     */
    public function despachar(Order $pedido): bool
    {
        try {
            return TravaDoPedido::executar($pedido->uuid, fn () => $this->despacharComATrava($pedido));
        } catch (LockTimeoutException $e) {
            Log::warning('[entregas] ifood: trava do pedido ocupada; despacho fica para a próxima rodada', ['pedido' => $pedido->public_id]);

            return false;
        } catch (\Throwable $e) {
            Log::error('[entregas] ifood: falha ao despachar o pedido', ['pedido' => $pedido->public_id, 'erro' => get_class($e)]);

            return false;
        }
    }

    protected function despacharComATrava(Order $pedido): bool
    {
        session(['company' => $pedido->company_uuid]);

        // relido com a trava: a central pode ter atribuído ou encerrado, ou um motoboy aceitado, desde a leitura de quem chamou
        $atual  = $pedido->fresh();
        $motivo = $this->motivoParaNaoDespachar($atual);
        if ($motivo !== null) {
            DB::table(static::TABELA)->where('order_uuid', $pedido->uuid)->update(['despachar_em' => null, 'updated_at' => now()->toDateTimeString()]);
            Log::info("[entregas] ifood: pedido não despachado ({$motivo}); sai da fila", ['pedido' => $pedido->public_id]);

            return false;
        }

        if ($atual->dispatched) {
            if (in_array($atual->status, static::STATUS_ANTES_DO_ACEITE, true) && !$atual->hasDispatchedStatus()) {
                $atual->insertDispatchActivity();
            }
        } else {
            // sem eventos: o despacho logo abaixo grava de novo e emite os eventos
            $atual->adhoc = true;
            $atual->saveQuietly();
            // dispatched + atividade "dispatched" + OrderDispatched na fila → avisa os motoboys no raio da coleta
            $atual->firstDispatchWithActivity();
        }

        $agora = now()->toDateTimeString();
        DB::table(static::TABELA)->where('order_uuid', $pedido->uuid)->update(['despachado_em' => $agora, 'updated_at' => $agora]);

        return true;
    }

    /** Por que o pedido (relido) não deve ir aos motoboys, ou null se pode ir. */
    protected function motivoParaNaoDespachar(?Order $pedido): ?string
    {
        if (!$pedido || $pedido->deleted_at) {
            return 'apagado';
        }
        if (in_array($pedido->status, StatusDoPedido::ENCERRADOS, true)) {
            return 'encerrado';
        }
        if ($pedido->started) {
            return 'aceito';
        }
        if ($pedido->driver_assigned_uuid) {
            return 'motoboy atribuído';
        }

        return null;
    }

    /** Mesmo critério do portal (RegrasPortalLoja) para a coleta: da empresa, com dono = o Vendor e com coordenadas. */
    protected function coletaValida(Place $coleta, Vendor $vendor, ?string $companyUuid): bool
    {
        return $companyUuid
            && $coleta->company_uuid === $companyUuid
            && $coleta->owner_uuid === $vendor->uuid
            && $coleta->owner_type === Utils::getMutationType($vendor)
            && $coleta->location instanceof Point
            && Coordenada::valida($coleta->location->getLat(), $coleta->location->getLng());
    }

    /** Aviso se o Local da loja está a mais de 300 m do endereço dela no iFood (pedido de teste não conta). */
    protected function conferirColeta(array $pedidoIfood, object $coleta, array $dados): void
    {
        $endereco = $pedidoIfood['merchant']['merchantAddress'] ?? null;
        if ($dados['teste'] || !is_array($endereco) || !isset($endereco['latitude'], $endereco['longitude'])) {
            return;
        }

        $metros = PedidoDoIfood::metrosEntre((float) $coleta->location->getLat(), (float) $coleta->location->getLng(), (float) $endereco['latitude'], (float) $endereco['longitude']);
        if ($metros > static::DIVERGENCIA_MAXIMA_METROS) {
            Log::warning('[entregas] ifood: coleta diverge do iFood (' . (int) round($metros) . ' m)', ['merchant' => $pedidoIfood['merchant']['id'] ?? null, 'numero' => $dados['numero']]);
        }
    }
}
