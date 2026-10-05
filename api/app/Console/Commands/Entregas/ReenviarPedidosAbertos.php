<?php

namespace App\Console\Commands\Entregas;

use App\Events\Entregas\PedidoSemMotoboy;
use App\Notifications\Entregas\LembretePedidoAberto;
use App\Support\Entregas\StatusDoPedido;
use App\Support\Entregas\TransmissaoNoSocket;
use Carbon\CarbonImmutable;
use Fleetbase\FleetOps\Console\Commands\DispatchAdhocOrders;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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
 * online e livres perto da coleta. O raio cresce com o tempo desde o despacho (MULTIPLICADORES_DO_RAIO sobre o raio R
 * do primeiro aviso: 1,5R a partir de ~4 min, 2R a partir de ~8 min), mesmo quando um reenvio não achou ninguém. Sem
 * motoboy no raio, tenta de novo no minuto seguinte sem gastar reenvio. A contagem fica no cache, por pedido e
 * despacho: despachar o pedido de novo recomeça.
 *
 * O motoboy além de R recebe o alarme e pode aceitar por ele, mas o pedido não aparece na lista de pedidos próximos do
 * app, que filtra pelo raio R da empresa.
 *
 * Aviso à central: com AVISO_CENTRAL_MINUTOS sem aceite desde o despacho (o momento do último reenvio), transmite
 * entregas.pedido_sem_motoboy (App\Events\Entregas\PedidoSemMotoboy) no canal da empresa, uma vez por despacho, mesmo
 * sem motoboy no raio. O console toca um som e mostra um aviso fixo até o pedido ganhar motoboy. O envio confere o
 * retorno do socket (TransmissaoNoSocket, porque o broadcast() do Fleetbase engole a falha): se não chegou, vai para o
 * log e o aviso é tentado de novo no minuto seguinte, por até JANELA_AVISO_CENTRAL_MINUTOS (60 min) depois do despacho.
 * Passada a janela dos reenvios (16 min), o pedido só recebe o aviso à central, não mais os motoboys. Os avisos saem
 * depois dos reenvios aos motoboys e, na primeira falha, os demais ficam para o minuto seguinte (socket pendurado não
 * atrasa os motoboys). A falha também é gravada na saída do container (registrarFalhaDoAviso).
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

    /** Minutos sem aceite, desde o despacho, para avisar a central (o momento do último reenvio). */
    public const AVISO_CENTRAL_MINUTOS = self::INTERVALO_MINUTOS * self::MAX_REENVIOS;

    /** Por quanto tempo depois do despacho o aviso à central ainda é tentado (socket fora, agendador parado num deploy). */
    public const JANELA_AVISO_CENTRAL_MINUTOS = 60;

    /**
     * Saída de erro do container do agendador (PID 1), onde a falha do aviso é gravada: veja registrarFalhaDoAviso. Nos
     * testes, aponta para um arquivo.
     */
    protected const SAIDA_DO_CONTAINER = '/proc/1/fd/2';

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

        $paraAvisar = [];

        foreach ($pedidos as $pedido) {
            $segundos = $agora->getTimestamp() - $pedido->dispatched_at->getTimestamp();

            // o aviso à central sai só depois do laço: com o socket pendurado, cada envio pode demorar segundos e não
            // pode atrasar o reenvio aos motoboys
            if ($this->precisaAvisarCentral($pedido, $segundos)) {
                $paraAvisar[] = $pedido;
            }

            // depois da janela dos reenvios, só o aviso à central; sem esta trava, um pedido que nunca achou motoboy
            // seria reenviado até o fim da janela do aviso
            if ($segundos > self::INTERVALO_MINUTOS * (self::MAX_REENVIOS + 1) * 60) {
                continue;
            }

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

            $raio     = self::raioDoReenvio($pedido->getAdhocPingDistance(), self::etapaPeloTempo($segundos));
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

        $this->avisarCentral($paraAvisar, $agora);
    }

    /**
     * Raio do reenvio na etapa $etapa (1, 2, 3...), em metros. Depois do último multiplicador, repete o último.
     */
    public static function raioDoReenvio(int $raio, int $etapa): int
    {
        $multiplicadores = self::MULTIPLICADORES_DO_RAIO;
        $indice          = max(0, min($etapa, count($multiplicadores)) - 1);

        return (int) round($raio * $multiplicadores[$indice]);
    }

    /**
     * Etapa do raio pelo tempo desde o despacho (1 a partir de ~4 min, 2 a partir de ~8 min...), com a mesma folga do
     * intervalo. Independe de algum reenvio ter achado motoboy: sem ninguém perto, o raio cresce do mesmo jeito.
     */
    public static function etapaPeloTempo(int $segundosDesdeODespacho): int
    {
        return max(1, intdiv($segundosDesdeODespacho + self::FOLGA_SEGUNDOS, self::INTERVALO_MINUTOS * 60));
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
                // a janela do aviso à central; a dos reenvios aos motoboys (um intervalo a mais depois do último, para
                // quando faltou motoboy no raio em algum minuto) é menor e fica no handle
                $agora->subMinutes(max(self::JANELA_AVISO_CENTRAL_MINUTOS, self::INTERVALO_MINUTOS * (self::MAX_REENVIOS + 1))),
                $agora->subSeconds(self::intervaloEmSegundos()),
            ])
            ->whereHas('payload')
            ->with(['company', 'payload'])
            ->get();
    }

    /**
     * Com AVISO_CENTRAL_MINUTOS sem aceite desde o despacho e sem o aviso já enviado (uma vez por despacho, mesmo sem
     * motoboy no raio).
     */
    protected function precisaAvisarCentral(Order $pedido, int $segundosParado): bool
    {
        return $segundosParado >= self::AVISO_CENTRAL_MINUTOS * 60 - self::FOLGA_SEGUNDOS && !Cache::get($this->chaveDoAvisoCentral($pedido));
    }

    protected function chaveDoAvisoCentral(Order $pedido): string
    {
        return 'entregas:pedido-sem-motoboy:' . $pedido->uuid . ':' . $pedido->dispatched_at->getTimestamp();
    }

    /**
     * Avisa a central no socket (PedidoSemMotoboy), em sequência, depois dos reenvios aos motoboys. Na primeira falha
     * (TransmissaoNoSocket devolve o erro) registra e para: com o socket fora do ar, tentar os demais só atrasaria o
     * comando. Os que sobram tentam de novo no minuto seguinte.
     *
     * @param Order[] $pedidos
     */
    protected function avisarCentral(array $pedidos, CarbonImmutable $agora): void
    {
        foreach ($pedidos as $indice => $pedido) {
            $minutos = (int) round(($agora->getTimestamp() - $pedido->dispatched_at->getTimestamp()) / 60);

            $erro = TransmissaoNoSocket::enviar(new PedidoSemMotoboy(
                (string) $pedido->company_uuid,
                (string) $pedido->uuid,
                (string) $pedido->public_id,
                $pedido->internal_id ? (string) $pedido->internal_id : null,
                $minutos
            ));

            if ($erro !== null) {
                $this->registrarFalhaDoAviso((string) $pedido->public_id, $erro);
                $this->warn(count($pedidos) - $indice . ' aviso(s) à central ficam para o próximo minuto (socket: ' . $erro . ').');

                return;
            }

            Cache::put($this->chaveDoAvisoCentral($pedido), true, now()->addDay());
            $this->warn('Pedido ' . $pedido->public_id . ': ' . $minutos . ' min sem motoboy; central avisada.');
        }
    }

    /**
     * Registra a falha no Log e também direto na saída de erro do container. O Fleet-Ops agenda este comando com
     * storeOutputInDb(), que redireciona a saída do processo filho para storage/logs/schedule-<hash>.log: com
     * LOG_CHANNEL=stdout, o Log::warning iria para esse arquivo e não apareceria em `docker service logs
     * entregas_scheduler`. No container do agendador o PID 1 é o ssm-parent e o go-crond roda como root, então dá para
     * escrever em /proc/1/fd/2. Fora do container (ou sem permissão) a saída não existe e é ignorada em silêncio.
     */
    protected function registrarFalhaDoAviso(string $publicId, string $erro): void
    {
        $mensagem = '[entregas] aviso de pedido sem motoboy não chegou ao socket';

        Log::warning($mensagem, ['pedido' => $publicId, 'erro' => $erro]);

        if (is_writable(static::SAIDA_DO_CONTAINER)) {
            $linha = json_encode(['message' => $mensagem, 'pedido' => $publicId, 'erro' => $erro, 'datetime' => now()->toIso8601String()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            @file_put_contents(static::SAIDA_DO_CONTAINER, $linha . PHP_EOL, FILE_APPEND);
        }
    }

    protected static function intervaloEmSegundos(): int
    {
        return self::INTERVALO_MINUTOS * 60 - self::FOLGA_SEGUNDOS;
    }

    /**
     * O mesmo filtro do primeiro aviso (HandleOrderDispatched do Fleet-Ops): online e livres.
     */
    protected function newDriverQuery()
    {
        return parent::newDriverQuery()->where('status', 'available');
    }
}
