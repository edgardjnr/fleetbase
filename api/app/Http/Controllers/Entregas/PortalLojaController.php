<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\CalculoEntregas;
use App\Support\Entregas\LojaDoUsuario;
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
    public const STATUS_ENCERRADOS = ['completed', 'done', 'canceled', 'cancelled', 'order_canceled', 'expired'];

    /** Rotas novas calculadas por consulta do extrato (o portal não repete a chamada como a tela da central). */
    public const LIMITE_CALCULOS_PORTAL = 10;

    /** Maior período do extrato, em dias entre o início e o fim (1 ano, contando o bissexto). */
    public const MAX_DIAS_EXTRATO = 366;

    /** A loja só vê a posição do motoboy até estas horas depois do aceite (LGPD). */
    public const HORAS_POSICAO = 4;

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

        // até 1 ano por consulta: o extrato varre os pedidos do período e calcula rotas, e a loja pode chamar quando quiser
        $dias = Carbon::parse($request->input('inicio'), 'UTC')->diffInDays(Carbon::parse($request->input('fim'), 'UTC'));
        if ($dias > static::MAX_DIAS_EXTRATO) {
            return response()->json(['errors' => ['Escolha um período de até 1 ano.']], 422);
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

    /** Motoboy do pedido em andamento (nome, foto e, com o pedido aceito há pouco, a posição). Só pedido desta loja. */
    public function motoboy(string $id)
    {
        $vendor = $this->lojaDaSessao();

        // name e photo_url do Driver leem o usuário dele e o avatar do usuário: vêm junto, e não sob demanda dentro dos accessors
        $pedido = Order::where('company_uuid', session('company'))
            ->where('customer_uuid', $vendor->uuid)
            ->where(fn ($q) => $q->where('public_id', $id)->orWhere('uuid', $id))
            ->with('driverAssigned.user.avatar')
            ->firstOrFail();

        $motoboy = $pedido->driverAssigned;
        if (!$motoboy || in_array($pedido->status, static::STATUS_ENCERRADOS, true)) {
            return response()->json(['motoboy' => null]);
        }

        // LGPD: a posição só aparece com o pedido aceito (started) há no máximo HORAS_POSICAO horas. Antes do aceite, ou num
        // pedido que ficou aberto por horas, o motoboy pode estar em outra entrega ou fora do expediente
        $latitude = $longitude = null;
        if ($pedido->started && $pedido->started_at?->gte(now()->subHours(static::HORAS_POSICAO))) {
            $posicao   = $motoboy->location;
            $latitude  = $posicao?->getLat();
            $longitude = $posicao?->getLng();

            // o Fleetbase grava (0, 0) em motorista que ainda não mandou GPS: é "sem posição", não um ponto no mapa
            // (mesmo critério do CalculoEntregas::temCoordenadas)
            if (abs((float) $latitude) <= 0.0001 && abs((float) $longitude) <= 0.0001) {
                $latitude = $longitude = null;
            }
        }

        return response()->json([
            'motoboy' => [
                'nome'      => $motoboy->name,
                'foto'      => $motoboy->photo_url,
                'aceitou'   => (bool) $pedido->started,
                'latitude'  => $latitude,
                'longitude' => $longitude,
            ],
        ]);
    }

    protected function lojaDaSessao(): Vendor
    {
        $vendor = LojaDoUsuario::vendor(session('user'));
        abort_if(!$vendor, 404, 'Usuário sem loja.');

        return $vendor;
    }
}
