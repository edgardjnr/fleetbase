<?php

namespace App\Console\Commands\Entregas;

use App\Jobs\Entregas\EnviarAvisoDaIntegracao;
use App\Support\Entregas\Integracao\CriadorDoPedidoDaIntegracao;
use App\Support\Entregas\Integracao\Integracao;
use App\Support\Entregas\Integracao\SequenciaDaIntegracao;
use App\Support\Entregas\StatusDoPedido;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: reserva da integração de pedidos, a cada minuto (Kernel). Nos pedidos das últimas 24 h ainda
 * sem o aviso final:
 * - refaz o despacho que falhou no POST pedidos (trava ocupada, erro), passado 1 min da criação; o pedido sem
 *   localização fica com a central;
 * - enfileira o aviso que falta (o saveQuietly do Fleet-Ops, um job perdido com o Redis reiniciado), só para lojas
 *   conectadas e sem falha de aviso nos últimos 5 min (a falha esgotou as tentativas do job: espera antes de tentar de
 *   novo, em vez de bater no destino fora do ar a cada minuto).
 * Até 300 pedidos por rodada. Uma falha num pedido não para os outros (log `falha ao acompanhar o pedido`).
 */
class AcompanharIntegracao extends Command
{
    protected $signature = 'entregas:integracao-acompanhar';

    protected $description = 'Entregas: refaz o despacho que falhou e reenvia os avisos que faltam dos pedidos da integração';

    public const LIMITE = 300;

    public const JANELA_HORAS = 24;

    public const FOLGA_DO_DESPACHO_SEGUNDOS = 60;

    public const PAUSA_DEPOIS_DE_FALHA_MINUTOS = 5;

    public function handle(CriadorDoPedidoDaIntegracao $criador): int
    {
        if (!Integracao::ligada()) {
            return static::SUCCESS;
        }

        $linhas = DB::table(Integracao::PEDIDOS)
            ->where('created_at', '>=', now()->subHours(static::JANELA_HORAS)->toDateTimeString())
            ->whereNotNull('order_uuid')
            ->where(fn ($q) => $q->whereNull('ultimo_aviso')->orWhere('ultimo_aviso', SequenciaDaIntegracao::ACEITO)->orWhere('ultimo_aviso', SequenciaDaIntegracao::A_CAMINHO))
            ->orderBy('id')
            ->limit(static::LIMITE)
            ->get();
        if ($linhas->isEmpty()) {
            return static::SUCCESS;
        }

        $pedidos = [];
        foreach (Order::whereIn('uuid', $linhas->pluck('order_uuid')->all())->get() as $pedido) {
            $pedidos[$pedido->uuid] = $pedido;
        }
        $lojas = $this->lojasQueRecebem();

        foreach ($linhas as $linha) {
            $pedido = $pedidos[$linha->order_uuid] ?? null;
            if (!$pedido) {
                continue;
            }

            try {
                if ($this->despachoFalhou($linha, $pedido)) {
                    $criador->despachar($pedido);
                }
                if (isset($lojas[$linha->vendor_uuid]) && SequenciaDaIntegracao::doPedido($linha, $pedido) !== []) {
                    EnviarAvisoDaIntegracao::enfileirar((string) $pedido->uuid);
                }
            } catch (\Throwable $e) {
                Log::warning('[entregas] integração: falha ao acompanhar o pedido', ['pedido' => $pedido->public_id, 'erro' => get_class($e)]);
            }
        }

        return static::SUCCESS;
    }

    /** vendor_uuid => loja, das lojas com chave ativa, conectadas e sem falha de aviso recente. */
    protected function lojasQueRecebem(): array
    {
        $limite = now()->subMinutes(static::PAUSA_DEPOIS_DE_FALHA_MINUTOS)->toDateTimeString();
        $lojas  = [];
        foreach (DB::table(Integracao::LOJAS)->whereNull('revogada_em')->whereNotNull('conectada_em')->get() as $loja) {
            if ($loja->ultima_falha_aviso_em && substr((string) $loja->ultima_falha_aviso_em, 0, 19) > $limite) {
                continue;
            }
            $lojas[$loja->vendor_uuid] = $loja;
        }

        return $lojas;
    }

    protected function despachoFalhou(object $linha, Order $pedido): bool
    {
        if ($linha->sem_localizacao || $linha->despachado_em || $pedido->dispatched || $pedido->started || in_array($pedido->status, StatusDoPedido::ENCERRADOS, true)) {
            return false;
        }

        return substr((string) $linha->created_at, 0, 19) <= now()->subSeconds(static::FOLGA_DO_DESPACHO_SEGUNDOS)->toDateTimeString();
    }
}
