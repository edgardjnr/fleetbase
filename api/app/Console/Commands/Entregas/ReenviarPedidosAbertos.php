<?php

namespace App\Console\Commands\Entregas;

use App\Notifications\Entregas\LembretePedidoAberto;
use App\Support\Entregas\StatusDoPedido;
use Carbon\CarbonImmutable;
use Fleetbase\FleetOps\Console\Commands\DispatchAdhocOrders;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Entregas RestaurantePro: repete o aviso de pedido aberto (ad hoc, ainda sem motoboy) aos motoboys próximos.
 *
 * Roda no lugar do `fleetops:dispatch-adhoc` do Fleet-Ops, com o mesmo nome e o mesmo agendamento a cada minuto (a
 * troca fica no AppServiceProvider). O original tinha dois defeitos:
 * - nunca achava pedido: o Carbon é mutável e os dois limites do whereBetween viravam o mesmo instante
 *   (agora - 72 h - 4 min). O pedido cujo primeiro aviso ninguém viu ficava aberto sem aviso novo;
 * - corrigido só isso, avisaria a cada ~5 min por até 2 dias, e duas vezes os motoboys livres: o dispatch(true)
 *   regrava o dispatched_at e dispara o OrderDispatched, que já avisa os motoboys próximos, e o comando avisava de
 *   novo em seguida.
 *
 * Aqui o pedido não é alterado. O aviso volta a cada INTERVALO_MINUTOS, no máximo MAX_REENVIOS vezes, para os motoboys
 * online e livres perto da coleta, num raio que cresce a cada reenvio (MULTIPLICADORES_DO_RAIO sobre o raio R do
 * primeiro aviso: 1,5R, 2R e 2R). Sem motoboy no raio, tenta de novo no minuto seguinte sem gastar reenvio. A contagem
 * fica no cache, por pedido e despacho: despachar o pedido de novo recomeça.
 *
 * Ao atualizar o fleetops-api, confira se a classe pai ainda tem getNearbyDriversForOrder, newOrderQuery e
 * newDriverQuery.
 */
class ReenviarPedidosAbertos extends DispatchAdhocOrders
{
    /** Minutos entre um aviso e o seguinte (o primeiro é o do despacho). */
    public const INTERVALO_MINUTOS = 4;

    /** Avisos extras por pedido; depois disso o pedido continua na lista de pedidos próximos do app. */
    public const MAX_REENVIOS = 3;

    /** O agendador roda o comando alguns segundos depois do minuto cheio: sem folga, um intervalo às vezes vira 5 min. */
    public const FOLGA_SEGUNDOS = 30;

    /** Raio de cada reenvio em relação ao raio do primeiro aviso (R, getAdhocPingDistance): 1,5R, 2R e 2R. */
    public const MULTIPLICADORES_DO_RAIO = [1.5, 2, 2];

    public function handle(): void
    {
        date_default_timezone_set('UTC');

        $sandbox = Utils::castBoolean($this->option('sandbox'));
        $testing = Utils::castBoolean($this->option('testing'));
        $dias    = max(1, (int) $this->option('days'));
        $agora   = CarbonImmutable::now();

        $pedidos = $this->pedidosAbertos($sandbox, $dias, $agora);

        if ($pedidos->isEmpty()) {
            $this->info('Nenhum pedido aberto aguardando novo aviso.');

            return;
        }

        foreach ($pedidos as $pedido) {
            $chave  = 'entregas:reenvio-pedido:' . $pedido->uuid . ':' . $pedido->dispatched_at->getTimestamp();
            $estado = Cache::get($chave, ['vezes' => 0, 'ultimo' => $pedido->dispatched_at->getTimestamp()]);

            if ($estado['vezes'] >= self::MAX_REENVIOS || $agora->getTimestamp() - $estado['ultimo'] < self::intervaloEmSegundos()) {
                continue;
            }

            $coleta = $pedido->getPickupLocation();
            if (!Utils::isPoint($coleta)) {
                $this->warn('Pedido ' . $pedido->public_id . ': local de coleta inválido.');
                continue;
            }

            $raio     = self::raioDoReenvio($pedido->getAdhocPingDistance(), $estado['vezes'] + 1);
            $motoboys = $this->getNearbyDriversForOrder($pedido, $coleta, $raio, $testing);
            if ($motoboys->isEmpty()) {
                $this->line('Pedido ' . $pedido->public_id . ': nenhum motoboy livre a até ' . $raio . ' m da coleta.');
                continue;
            }

            foreach ($motoboys as $motoboy) {
                $motoboy->notify(new LembretePedidoAberto($pedido, $motoboy->distance));
            }

            $vezes = $estado['vezes'] + 1;
            Cache::put($chave, ['vezes' => $vezes, 'ultimo' => $agora->getTimestamp()], now()->addDay());

            $this->info('Pedido ' . $pedido->public_id . ': aviso ' . $vezes . ' de ' . self::MAX_REENVIOS . ' reenviado a ' . $motoboys->count() . ' motoboy(s) (raio ' . $raio . ' m).');
        }
    }

    /**
     * Raio do reenvio de número $reenvio (1, 2, 3...), em metros. Depois do último multiplicador, repete o último.
     */
    public static function raioDoReenvio(int $raio, int $reenvio): int
    {
        $multiplicadores = self::MULTIPLICADORES_DO_RAIO;
        $indice          = max(0, min($reenvio, count($multiplicadores)) - 1);

        return (int) round($raio * $multiplicadores[$indice]);
    }

    /**
     * Pedidos ad hoc despachados, sem motoboy e não iniciados, dentro da janela de reenvio.
     */
    protected function pedidosAbertos(bool $sandbox, int $dias, CarbonImmutable $agora): Collection
    {
        return $this->newOrderQuery($sandbox ? 'sandbox' : 'mysql')
            ->withoutGlobalScopes()
            ->where(['adhoc' => 1, 'dispatched' => 1, 'started' => 0])
            ->whereNull('driver_assigned_uuid')
            ->whereNull('deleted_at')
            ->whereNotIn('status', StatusDoPedido::ENCERRADOS)
            ->where('created_at', '>=', $agora->subDays($dias))
            ->whereBetween('dispatched_at', [
                // um intervalo a mais depois do último reenvio, para quando faltou motoboy no raio em algum minuto
                $agora->subMinutes(self::INTERVALO_MINUTOS * (self::MAX_REENVIOS + 1)),
                $agora->subSeconds(self::intervaloEmSegundos()),
            ])
            ->whereHas('payload')
            ->with(['company', 'payload'])
            ->get();
    }

    protected static function intervaloEmSegundos(): int
    {
        return self::INTERVALO_MINUTOS * 60 - self::FOLGA_SEGUNDOS;
    }

    /**
     * Os mesmos motoboys do primeiro aviso (HandleOrderDispatched do Fleet-Ops): online e livres.
     */
    protected function newDriverQuery()
    {
        return parent::newDriverQuery()->where('status', 'available');
    }
}
