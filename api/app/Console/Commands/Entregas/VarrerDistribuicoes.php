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
 * Em rodadas (Distribuicao::emRodadas) não há prazo: no lugar dele, avança as distribuições em ofertas sem oferta pendente
 * paradas há mais de SEGUNDOS_PARADA - FOLGA_DO_RELOGIO_S (volta que esperava o intervalo, ninguém disponível, job perdido);
 * cada tentativa sem candidato toca o updated_at. A varredura roda nos minutos cheios e o updated_at é gravado no mesmo
 * instante da rodada anterior: com o limite de 60 s exato, uma distribuição tocada às 10:00:00 não estaria "parada há mais
 * de 60 s" às 10:01:00 e a nova tentativa sairia a cada 2 min. A folga de 10 s mantém a cadência de 1 min.
 * O encerramento e o avanço em rodadas só olham as distribuições despachadas nas últimas 24 h (as mais antigas, o
 * observador do Order encerra). Vencer e abrir pelo prazo não têm esse limite.
 * Uma falha num item não para os outros (log só com ids).
 */
class VarrerDistribuicoes extends Command
{
    /** Folga sobre SEGUNDOS_PARADA (ver o docblock): a cadência de 1 min não pode virar 2. */
    protected const FOLGA_DO_RELOGIO_S = 10;

    protected $signature  = 'entregas:distribuicao-varrer';
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

        if (Distribuicao::emRodadas()) {
            foreach (Distribuicoes::emOfertasParadasHa(Distribuicao::SEGUNDOS_PARADA - static::FOLGA_DO_RELOGIO_S) as $distribuicao) {
                $this->tentar('avançar a distribuição', ['distribuicao' => $distribuicao->id], fn () => $distribuidor->avancarOuAbrir((string) $distribuicao->pedido_uuid));
            }

            return self::SUCCESS;
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
