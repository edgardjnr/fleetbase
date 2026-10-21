<?php

namespace App\Support\Entregas;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: o resumo do dia na gaveta do menu do portal da loja (sketch 003, variante B; rota
 * `int/v1/entregas/loja/resumo-do-dia`, lida ao abrir a gaveta).
 *
 * - em andamento: os pedidos da loja não encerrados e mexidos nas últimas 12 h (a mesma regra dos alfinetes do mapa,
 *   PedidosNoMapa, sem exigir coordenada);
 * - entregues hoje: os pedidos da loja concluídos (status `completed`) com a atividade COMPLETED de hoje, no horário de
 *   Brasília (o servidor roda nele: ver "Fuso" em api/CLAUDE.md);
 * - motoboys online: todos os motoboys online da empresa, como no mapa da loja (MotoboysNoMapaDaLoja mostra todos).
 *
 * Só números: nenhum pedido de outra loja, nenhum motoboy identificado.
 */
class ResumoDoDiaDaLoja
{
    /**
     * @param array<int, string> $donos customer_uuid dos pedidos da loja: o Vendor e o contato do usuário
     *
     * @return array{em_andamento: int, entregues_hoje: int, motoboys_online: int}
     */
    public static function contar(string $companyUuid, array $donos): array
    {
        return [
            'em_andamento'    => static::emAndamento($companyUuid, $donos),
            'entregues_hoje'  => static::entreguesHoje($companyUuid, $donos),
            'motoboys_online' => Driver::where('company_uuid', $companyUuid)->where('online', 1)->count(),
        ];
    }

    protected static function emAndamento(string $companyUuid, array $donos): int
    {
        if ($donos === []) {
            return 0;
        }

        return Order::where('company_uuid', $companyUuid)
            ->whereIn('customer_uuid', $donos)
            ->whereNotIn('status', StatusDoPedido::ENCERRADOS)
            ->where('updated_at', '>=', now()->subHours(SituacaoDoMotoboy::HORAS_PEDIDO_EM_ANDAMENTO))
            ->count();
    }

    protected static function entreguesHoje(string $companyUuid, array $donos): int
    {
        if ($donos === []) {
            return 0;
        }

        // hoje no fuso do app (Brasília): o query builder formata o Carbon no fuso do próprio objeto
        $rastreios = DB::table('tracking_statuses')
            ->where('company_uuid', $companyUuid)
            ->where('code', 'COMPLETED')
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
            ->pluck('tracking_number_uuid')
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($rastreios === []) {
            return 0;
        }

        return Order::where('company_uuid', $companyUuid)
            ->whereIn('customer_uuid', $donos)
            ->where('status', 'completed')
            ->whereIn('tracking_number_uuid', $rastreios)
            ->count();
    }
}
