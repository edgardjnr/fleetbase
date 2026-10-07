<?php

namespace App\Console\Commands\Entregas;

use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Distribuicoes;
use App\Support\Entregas\Distribuicao\Distribuidor;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: reserva da distribuição de pedidos abertos, a cada minuto (Kernel). O caminho normal é o job
 * AvancarOferta; esta varredura pega o que ficou preso quando o Redis reiniciou (fila sem persistência):
 * - oferta pendente vencida há mais de FOLGA_DA_VARREDURA_S: vence (e o Distribuidor passa ao próximo);
 * - distribuição não encerrada cujo pedido já tem motoboy, está encerrado ou sumiu: encerra;
 * - distribuição em ofertas há mais de MINUTOS_ATE_ABRIR: abre a todos (prazo), mesmo com oferta pendente (o abrirATodos a cancela).
 * O encerramento só olha as distribuições despachadas nas últimas 24 h (as mais antigas, o observador do Order encerra).
 * Vencer e abrir pelo prazo não têm esse limite.
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

        $abertas = Distribuicoes::naoEncerradas(); // só as das últimas 24 h
        $pedidos = [];
        if ($abertas) {
            foreach (Order::whereIn('uuid', array_values(array_unique(array_map(fn ($d) => (string) $d->pedido_uuid, $abertas))))->get() as $pedido) {
                $pedidos[(string) $pedido->uuid] = $pedido;
            }
        }
        foreach ($abertas as $distribuicao) {
            $motivo = $distribuidor->motivoParaEncerrar($pedidos[(string) $distribuicao->pedido_uuid] ?? null);
            if ($motivo) {
                $this->tentar('encerrar a distribuição', ['distribuicao' => $distribuicao->id], fn () => $distribuidor->encerrarDistribuicao((int) $distribuicao->id, $motivo));
            }
        }

        foreach (Distribuicoes::emOfertasHaMais(Distribuicao::MINUTOS_ATE_ABRIR) as $distribuicao) {
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
