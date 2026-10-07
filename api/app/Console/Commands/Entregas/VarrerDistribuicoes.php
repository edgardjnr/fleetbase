<?php

namespace App\Console\Commands\Entregas;

use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Distribuicoes;
use App\Support\Entregas\Distribuicao\Distribuidor;
use App\Support\Entregas\StatusDoPedido;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: reserva da distribuição de pedidos abertos, a cada minuto (Kernel). O caminho normal é o job
 * AvancarOferta; esta varredura pega o que ficou preso quando o Redis reiniciou (fila sem persistência):
 * - oferta pendente vencida há mais de FOLGA_DA_VARREDURA_S: vence (e o Distribuidor passa ao próximo);
 * - distribuição não encerrada cujo pedido já tem motoboy, está encerrado ou sumiu: encerra;
 * - distribuição em ofertas há mais de MINUTOS_ATE_ABRIR sem oferta pendente: abre a todos (prazo).
 * Uma falha num item não para os outros (log só com ids).
 */
class VarrerDistribuicoes extends Command
{
    protected $signature   = 'entregas:distribuicao-varrer';
    protected $description = 'Entregas: vence ofertas presas e abre ou encerra distribuições de pedidos abertos';

    public function handle(Distribuidor $distribuidor): int
    {
        if (!Distribuicao::ligada()) {
            return self::SUCCESS;
        }

        foreach (Distribuicoes::pendentesVencidasHa(Distribuicao::FOLGA_DA_VARREDURA_S) as $oferta) {
            $this->tentar('vencer a oferta', ['oferta' => $oferta->id], fn () => $distribuidor->vencer((int) $oferta->id));
        }

        foreach (Distribuicoes::naoEncerradas() as $distribuicao) {
            $pedido = Order::where('uuid', $distribuicao->pedido_uuid)->first();
            $motivo = match (true) {
                !$pedido                                                                         => Distribuicao::CANCELADA,
                in_array(strtolower((string) $pedido->status), StatusDoPedido::ENCERRADOS, true) => Distribuicao::CANCELADA,
                (bool) $pedido->driver_assigned_uuid                                             => Distribuicao::ATRIBUIDA,
                default                                                                          => null,
            };
            if ($motivo) {
                $this->tentar('encerrar a distribuição', ['distribuicao' => $distribuicao->id], fn () => $distribuidor->encerrar((string) $distribuicao->pedido_uuid, $motivo));
            }
        }

        foreach (Distribuicoes::emOfertasHaMais(Distribuicao::MINUTOS_ATE_ABRIR) as $distribuicao) {
            if (Distribuicoes::ofertaPendente((int) $distribuicao->id)) {
                continue; // a pendente vence pelo job (ou pelo laço acima na próxima rodada)
            }
            $this->tentar('abrir a distribuição', ['distribuicao' => $distribuicao->id], fn () => $distribuidor->abrirATodos((string) $distribuicao->pedido_uuid, Distribuicao::PRAZO));
        }

        return self::SUCCESS;
    }

    protected function tentar(string $acao, array $contexto, \Closure $fazer): void
    {
        try {
            $fazer();
        } catch (\Throwable $e) {
            Log::warning('[entregas] distribuição: varredura não conseguiu ' . $acao, $contexto + ['erro' => get_class($e)]);
        }
    }
}
