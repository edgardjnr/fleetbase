<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Entregas\MotoboyController;
use App\Support\Entregas\AppDoMotoboy;
use App\Support\Entregas\MotoboyDaSessao;
use App\Support\Entregas\SituacaoDoMotoboy;
use Closure;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: trava "Atualize o app" (AppDoMotoboy). No começo do grupo `fleetbase.api`
 * (RouteServiceProvider), depois da autenticação: toda chamada com token de usuário (motoboy, líder) registra a versão
 * do app (cabeçalho X-Entregas-App) e, com ENTREGAS_APP_TRAVA=1, a do motoboy abaixo da versão mínima (ou sem o
 * cabeçalho: APK 34 e anteriores) leva 426 com `entregas_app` (versão mínima e link do APK), que o app novo mostra na
 * tela de atualização. Chave de API (`flb_live_`, integrações) passa sempre.
 *
 * Exceções:
 * - GET v1/entregas/motoboy/app (MotoboyController@app): é por ela que o app sabe que precisa atualizar;
 * - motoboy com pedido em andamento (a regra do capacete, SituacaoDoMotoboy) termina a entrega: só não aceita pedido
 *   aberto nem de outro motoboy (v1/orders/{id}/start), e não recebe oferta (Candidatos);
 * - usuário sem cadastro de motoboy passa.
 */
class ExigirAppAtualizado
{
    public const ACAO_DA_VERSAO = MotoboyController::class . '@app';
    public const ACAO_DO_ACEITE = BarrarAceiteDePedidoEncerrado::ACAO_DO_ACEITE;
    /** Um log por motoboy a cada 10 min (o app antigo chama a API o tempo todo). */
    public const SEGUNDOS_ENTRE_LOGS = 600;

    public function handle(Request $request, Closure $next)
    {
        $usuario = session('user');
        // chave de API não tem "|" (MotoboyDaSessao); só o token de usuário do app passa pela trava
        if (!$usuario || !str_contains((string) $request->bearerToken(), '|')) {
            return $next($request);
        }

        $versao = AppDoMotoboy::versaoDaChamada($request);
        try {
            AppDoMotoboy::registrar((string) $usuario, $versao);
            $barrar = AppDoMotoboy::desatualizado($versao) && $this->acao($request) !== static::ACAO_DA_VERSAO && $this->barrarMotoboy($request);
        } catch (\Throwable $e) {
            // a trava nunca derruba o app: na dúvida, passa
            Log::warning('[entregas] app do motoboy: falha na trava de versão', ['erro' => get_class($e)]);
            $barrar = false;
        }
        if (!$barrar) {
            return $next($request);
        }

        if (Cache::add('entregas:app-barrado-log:' . $usuario, true, static::SEGUNDOS_ENTRE_LOGS)) {
            Log::info('[entregas] app do motoboy desatualizado: chamada barrada', [
                'usuario' => $usuario,
                'versao'  => $versao,
                'minima'  => AppDoMotoboy::minima()['versao'] ?? null,
                'acao'    => $this->acao($request),
            ]);
        }

        return response()->json([
            'error'        => AppDoMotoboy::MENSAGEM,
            'errors'       => [AppDoMotoboy::MENSAGEM],
            'entregas_app' => AppDoMotoboy::situacao($versao),
        ], 426);
    }

    /** O motoboy desatualizado é barrado, a menos que esteja terminando um pedido dele. */
    protected function barrarMotoboy(Request $request): bool
    {
        $motoboy = MotoboyDaSessao::motoboy($request);
        if (!$motoboy) {
            return false;
        }

        if (!$this->temPedidoEmAndamento($motoboy)) {
            return true;
        }
        if ($this->acao($request) !== static::ACAO_DO_ACEITE) {
            return false;
        }

        // aceite: só o do pedido que a central já passou para ele; pedido aberto ou de outro motoboy, não
        $id     = $request->route('id');
        $pedido = is_string($id) && $id !== ''
            ? Order::where(fn ($q) => $q->where('public_id', $id)->orWhere('internal_id', $id))->where('company_uuid', $motoboy->company_uuid)->first()
            : null;

        return !$pedido || (bool) $pedido->adhoc || $pedido->driver_assigned_uuid !== $motoboy->uuid;
    }

    /** A regra do capacete (SituacaoDoMotoboy): pedido dele não encerrado, atualizado nas últimas 12 h. */
    protected function temPedidoEmAndamento(Driver $motoboy): bool
    {
        $pedidos = SituacaoDoMotoboy::pedidosEmAndamento((string) $motoboy->company_uuid, [(string) $motoboy->uuid]);

        return ($pedidos[(string) $motoboy->uuid] ?? []) !== [];
    }

    protected function acao(Request $request): ?string
    {
        $rota = $request->route();

        return $rota instanceof Route ? ltrim($rota->getActionName(), '\\') : null;
    }
}
