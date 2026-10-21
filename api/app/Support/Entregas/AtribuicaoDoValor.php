<?php

namespace App\Support\Entregas;

use App\Support\Entregas\Financeiro\Multiplicadores;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: as consultas da atribuição do valor da entrega (CalculoEntregas): quantos pedidos o motoboy
 * já leva, o multiplicador de um instante e a reconstituição pelos horários para o pedido que escapou do observador.
 * Classe própria para o teste do cálculo trocar por uma falsa. Horários em Brasília, texto 'Y-m-d H:i:s'.
 */
class AtribuicaoDoValor
{
    /**
     * Pedidos em aberto do motoboy, sem $exceto: não encerrados e atualizados nas últimas
     * SituacaoDoMotoboy::HORAS_PEDIDO_EM_ANDAMENTO horas (pedido esquecido aberto não conta), como o mapa e a fila.
     */
    public function emAberto(string $empresa, string $motoboyUuid, string $exceto): int
    {
        return DB::table('orders')
            ->where('company_uuid', $empresa)
            ->where('driver_assigned_uuid', $motoboyUuid)
            ->where('uuid', '!=', $exceto)
            ->whereNull('deleted_at')
            ->whereNotIn('status', StatusDoPedido::ENCERRADOS)
            ->where('updated_at', '>=', now()->subHours(SituacaoDoMotoboy::HORAS_PEDIDO_EM_ANDAMENTO)->format('Y-m-d H:i:s'))
            ->count();
    }

    /** O período ativo no instante. Sem a tabela (API nova antes da migration) ou com o banco fora: nenhum, e o log. */
    public function multiplicadorEm(string $empresa, string $instante): ?object
    {
        try {
            return Multiplicadores::ativoEm($empresa, $instante);
        } catch (\Throwable $e) {
            Log::warning('[entregas] valor: não deu para ler o multiplicador; vale ×1', ['erro' => get_class($e)]);

            return null;
        }
    }

    /** Os períodos que tocam [de, ate] (reserva por horários). Falha: nenhum, e o log. */
    public function multiplicadoresDoPeriodo(string $empresa, string $de, string $ate): array
    {
        try {
            return Multiplicadores::doPeriodo($empresa, $de, $ate);
        } catch (\Throwable $e) {
            Log::warning('[entregas] valor: não deu para ler os multiplicadores do período; vale ×1', ['erro' => get_class($e)]);

            return [];
        }
    }

    /**
     * Posição de cada pedido pelos horários (reserva), numa consulta só: os pedidos dos motoboys aceitos desde 12 h antes
     * do primeiro instante. Aceite = started_at (sem ele, dispatched_at); fim = updated_at do pedido encerrado (aproximado:
     * uma edição depois da conclusão o adia).
     *
     * @param array<int, array{uuid: string, motoboy: string, instante: string}> $pedidos
     *
     * @return array<string, int> uuid do pedido => posição
     */
    public function posicoesPorHorarios(string $empresa, array $pedidos): array
    {
        if (!$pedidos) {
            return [];
        }

        $instantes = array_column($pedidos, 'instante');
        $de        = (new \DateTimeImmutable(min($instantes)))->modify('-' . SituacaoDoMotoboy::HORAS_PEDIDO_EM_ANDAMENTO . ' hours')->format('Y-m-d H:i:s');
        $ate       = max($instantes);

        $linhas = DB::table('orders')
            ->where('company_uuid', $empresa)
            ->whereIn('driver_assigned_uuid', array_values(array_unique(array_column($pedidos, 'motoboy'))))
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereBetween('started_at', [$de, $ate])->orWhereBetween('dispatched_at', [$de, $ate]))
            ->get(['uuid', 'driver_assigned_uuid', 'started_at', 'dispatched_at', 'status', 'updated_at']);

        $porMotoboy = [];
        foreach ($linhas as $linha) {
            $aceite = $linha->started_at ?? $linha->dispatched_at;
            $porMotoboy[(string) $linha->driver_assigned_uuid][] = [
                'uuid'   => (string) $linha->uuid,
                'aceite' => $aceite !== null ? substr((string) $aceite, 0, 19) : null,
                'fim'    => in_array(strtolower((string) $linha->status), StatusDoPedido::ENCERRADOS, true) && $linha->updated_at !== null ? substr((string) $linha->updated_at, 0, 19) : null,
            ];
        }

        $posicoes = [];
        foreach ($pedidos as $pedido) {
            $outros = array_values(array_filter($porMotoboy[$pedido['motoboy']] ?? [], fn ($outro) => $outro['uuid'] !== $pedido['uuid']));
            $posicoes[$pedido['uuid']] = ValorDaEntrega::posicaoPorHorarios($pedido['instante'], $outros);
        }

        return $posicoes;
    }
}
