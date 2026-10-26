<?php

namespace App\Support\Entregas;

use App\Support\Entregas\Painel\AgregadorDoPainel;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: os números de hoje do portal da loja (rota `int/v1/entregas/loja/resumo-do-dia`): a faixa do
 * Início (sketch 009 S) e, antes dele, a gaveta do menu (sketch 003 B).
 *
 * - em andamento: os pedidos da loja não encerrados e mexidos nas últimas 12 h (a mesma regra dos alfinetes do mapa,
 *   PedidosNoMapa, sem exigir coordenada);
 * - entregues hoje: os pedidos da loja concluídos (status `completed`) com a atividade COMPLETED de hoje, no horário de
 *   Brasília (o servidor roda nele: ver "Fuso" em api/CLAUDE.md);
 * - motoboys online: todos os motoboys online da empresa, como no mapa da loja (MotoboysNoMapaDaLoja mostra todos);
 * - pedidos hoje: os pedidos da loja criados hoje (Brasília), em qualquer situação;
 * - no prazo e tempo médio: dos entregues hoje, a mesma conta do painel inicial do console
 *   (AgregadorDoPainel::prazos: % concluído até o meta.entregas_prazo_em, só os com prazo; minutos do aceite à
 *   conclusão). Sem entregue com prazo (ou sem aceite), null.
 *
 * Só números: nenhum pedido de outra loja, nenhum motoboy identificado.
 */
class ResumoDoDiaDaLoja
{
    /**
     * @param array<int, string> $donos customer_uuid dos pedidos da loja: o Vendor e o contato do usuário
     *
     * @return array{em_andamento: int, entregues_hoje: int, motoboys_online: int, pedidos_hoje: int, no_prazo: ?int, tempo_medio_min: ?int}
     */
    public static function contar(string $companyUuid, array $donos): array
    {
        $entregues = static::entreguesHoje($companyUuid, $donos);
        $prazos    = AgregadorDoPainel::prazos($entregues, date_default_timezone_get());

        return [
            'em_andamento'    => static::emAndamento($companyUuid, $donos),
            'entregues_hoje'  => count($entregues),
            'motoboys_online' => Driver::where('company_uuid', $companyUuid)->where('online', 1)->count(),
            'pedidos_hoje'    => static::pedidosHoje($companyUuid, $donos),
            'no_prazo'        => $prazos['no_prazo'],
            'tempo_medio_min' => $prazos['tempo_medio_min'],
        ];
    }

    protected static function pedidosHoje(string $companyUuid, array $donos): int
    {
        if ($donos === []) {
            return 0;
        }

        return Order::where('company_uuid', $companyUuid)
            ->whereIn('customer_uuid', $donos)
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
            ->count();
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

    /**
     * Os pedidos da loja entregues hoje, cada um com a conclusão, o aceite e o prazo (o formato do
     * AgregadorDoPainel::prazos).
     *
     * @return array<int, array{concluido_em: ?string, aceito_em: ?string, prazo_em: ?string}>
     */
    protected static function entreguesHoje(string $companyUuid, array $donos): array
    {
        if ($donos === []) {
            return [];
        }

        // hoje no fuso do app (Brasília): o query builder formata o Carbon no fuso do próprio objeto
        $conclusoes = DB::table('tracking_statuses')
            ->where('company_uuid', $companyUuid)
            ->where('code', 'COMPLETED')
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
            ->pluck('created_at', 'tracking_number_uuid')
            ->all();
        unset($conclusoes['']);
        if ($conclusoes === []) {
            return [];
        }

        $pedidos = Order::where('company_uuid', $companyUuid)
            ->whereIn('customer_uuid', $donos)
            ->where('status', 'completed')
            ->whereIn('tracking_number_uuid', array_keys($conclusoes))
            ->get(['tracking_number_uuid', 'started_at', 'dispatched_at', 'meta'])
            ->all();

        return array_map(fn ($pedido) => [
            'concluido_em' => static::texto($conclusoes[$pedido->tracking_number_uuid] ?? null),
            'aceito_em'    => static::texto($pedido->started_at ?? null) ?? static::texto($pedido->dispatched_at ?? null),
            'prazo_em'     => PrazoDaEntrega::doPedido($pedido),
        ], $pedidos);
    }

    private static function texto($valor): ?string
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d H:i:s');
        }

        return is_string($valor) && $valor !== '' ? $valor : null;
    }
}
