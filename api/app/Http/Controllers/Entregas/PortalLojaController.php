<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\CalculoEntregas;
use App\Support\Entregas\LojaDoUsuario;
use App\Support\Entregas\MotoboysNoMapaDaLoja;
use App\Support\Entregas\PedidosNoMapa;
use App\Support\Entregas\StatusDoPedido;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Entregas RestaurantePro: dados do portal da loja (usuário type=customer).
 * Tudo filtrado pela loja do usuário da sessão; a loja nunca vem por parâmetro.
 */
class PortalLojaController extends Controller
{
    /** Rotas novas calculadas por consulta do extrato (o portal não repete a chamada como a tela da central). */
    public const LIMITE_CALCULOS_PORTAL = 10;

    /**
     * Maior período do extrato, em dias entre o início e o fim: 3 meses (do dia 1 até o dia 1 três meses depois são, no
     * máximo, 92 dias). O portal confere o mesmo limite antes de consultar (MAX_DIAS no controller do extrato).
     */
    public const MAX_DIAS_EXTRATO = 92;

    public function minhaLoja(CalculoEntregas $calculo)
    {
        $vendor = $this->lojaDaSessao();
        $coleta = LojaDoUsuario::coleta($vendor);

        return response()->json([
            'loja' => [
                'id'       => $vendor->public_id,
                'nome'     => $vendor->name,
                'endereco' => $coleta ? $calculo->enderecoCurto($coleta) : null,
                'cidade'   => $coleta?->city,
                // o portal mostra a coleta e desenha a rota com isto; quem grava a coleta no pedido é o servidor
                'coleta'   => $coleta ? [
                    'id'           => $coleta->public_id,
                    'uuid'         => $coleta->uuid,
                    'public_id'    => $coleta->public_id,
                    'name'         => $vendor->name,
                    'street1'      => $coleta->street1,
                    'street2'      => $coleta->street2,
                    'neighborhood' => $coleta->neighborhood,
                    'city'         => $coleta->city,
                    'latitude'     => $coleta->location?->getLat(),
                    'longitude'    => $coleta->location?->getLng(),
                ] : null,
            ],
        ]);
    }

    public function extrato(Request $request, CalculoEntregas $calculo)
    {
        $vendor = $this->lojaDaSessao();

        $request->validate([
            'inicio' => ['required', 'date_format:Y-m-d'],
            'fim'    => ['required', 'date_format:Y-m-d', 'after_or_equal:inicio'],
        ]);

        // até 3 meses por consulta: o extrato carrega de uma vez todos os pedidos do período (cerca de 7 models por pedido) e
        // calcula rotas, e a loja pode chamar quando quiser. Um ano de loja movimentada passa de 36 mil entregas, o que estoura
        // a memória do PHP e o tempo do Octane
        $dias = Carbon::parse($request->input('inicio'), 'UTC')->diffInDays(Carbon::parse($request->input('fim'), 'UTC'));
        if ($dias > static::MAX_DIAS_EXTRATO) {
            return response()->json(['errors' => ['Escolha um período de até 3 meses.']], 422);
        }

        $companyUuid = session('company');
        $fuso        = Company::where('uuid', $companyUuid)->value('timezone') ?: 'America/Sao_Paulo';
        $inicio      = Carbon::createFromFormat('Y-m-d', $request->input('inicio'), $fuso)->startOfDay()->utc();
        $fim         = Carbon::createFromFormat('Y-m-d', $request->input('fim'), $fuso)->endOfDay()->utc();

        $pedidos = $calculo->pedidosConcluidos($companyUuid, $inicio, $fim, function ($query) use ($vendor) {
            $query->where('orders.customer_uuid', $vendor->uuid);
        });
        [$entregas, $pendentes] = $calculo->entregas($pedidos, $fuso, static::LIMITE_CALCULOS_PORTAL);

        // a loja não vê o valor pago ao motoboy nem quem levou
        $entregas = array_map(fn ($e) => [
            'pedido'       => $e['pedido'],
            'id_interno'   => $e['id_interno'],
            'concluido_em' => $e['concluido_em'],
            'destino'      => $e['destino'],
            'km'           => $e['km'],
            'faixa'        => $e['faixa'] ? ['de_km' => $e['faixa']['de_km'], 'ate_km' => $e['faixa']['ate_km'], 'acima' => $e['faixa']['acima'] ?? false] : null,
            'valor'        => $e['valor_loja'],
        ], $entregas);

        return response()->json([
            'inicio'    => $request->input('inicio'),
            'fim'       => $request->input('fim'),
            'pendentes' => $pendentes,
            'totais'    => [
                'entregas' => count($entregas),
                'km'       => round(collect($entregas)->sum(fn ($e) => $e['km'] ?? 0), 2),
                'valor'    => round(collect($entregas)->sum(fn ($e) => $e['valor'] ?? 0), 2),
            ],
            'entregas'  => $entregas,
        ]);
    }

    /** Motoboy do pedido em andamento (nome, foto e aceite). Só pedido que o portal mostra a este usuário. */
    public function motoboy(string $id)
    {
        $vendor = $this->lojaDaSessao();

        // name e photo_url do Driver leem o usuário dele e o avatar do usuário: vêm junto, e não sob demanda dentro dos accessors
        $pedido = Order::where('company_uuid', session('company'))
            ->whereIn('customer_uuid', $this->donosDosPedidos($vendor))
            ->where(fn ($q) => $q->where('public_id', $id)->orWhere('uuid', $id))
            ->with('driverAssigned.user.avatar')
            ->firstOrFail();

        $motoboy = $pedido->driverAssigned;
        if (!$motoboy || in_array($pedido->status, StatusDoPedido::ENCERRADOS, true)) {
            return response()->json(['motoboy' => null]);
        }

        // a posição fica no mapa de motoboys (motoboysNoMapa), que mostra todos os motoboys online
        return response()->json([
            'motoboy' => [
                'nome'    => $motoboy->name,
                'foto'    => $motoboy->photo_url,
                'aceitou' => (bool) $pedido->started,
            ],
        ]);
    }

    /**
     * Motoboys no mapa do portal (todos os online e os offline com pedido aceito), consultado a cada 5 s com o mapa aberto.
     * Sem id, telefone nem pedido de outra loja (MotoboysNoMapaDaLoja). Traz também os alfinetes dos pedidos em andamento
     * da loja (PedidosNoMapa::daLoja): só os dela, nunca os das outras lojas.
     */
    public function motoboysNoMapa()
    {
        $vendor = $this->lojaDaSessao();
        $donos  = $this->donosDosPedidos($vendor);

        return response()->json([
            'motoboys' => MotoboysNoMapaDaLoja::listar(session('company'), $donos),
            'pedidos'  => PedidosNoMapa::daLoja(session('company'), $donos),
        ]);
    }

    /**
     * Os donos de pedido que o portal mostra a este usuário (PortalOrderService::accountCustomerUuids): a loja e o contato
     * do usuário (a central pode lançar o pedido no contato; só com a loja, esse pedido aparece no portal).
     *
     * @return array<int, string>
     */
    protected function donosDosPedidos(Vendor $vendor): array
    {
        return array_values(array_filter([
            $vendor->uuid,
            LojaDoUsuario::contato(session('user'))?->uuid,
        ]));
    }

    protected function lojaDaSessao(): Vendor
    {
        $vendor = LojaDoUsuario::vendor(session('user'));
        abort_if(!$vendor, 404, 'Usuário sem loja.');

        return $vendor;
    }
}
