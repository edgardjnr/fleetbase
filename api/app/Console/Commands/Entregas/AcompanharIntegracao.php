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
 * Entregas RestaurantePro: reserva da integração de pedidos, a cada minuto (Kernel). Duas consultas, cada uma com o
 * próprio limite (POR_RODADA), para linhas presas (Order apagado, loja sem conexão, destino fora do ar) não tirarem a
 * vez dos pedidos novos:
 * - despacho: pedido que ficou sem despachar (trava ocupada, erro no POST pedidos), de 1 min a 30 min depois da criação.
 *   O limite de 30 min evita tentar 1440 vezes uma falha permanente. Dentro dessa janela o pedido que a central parou
 *   (adhoc desligado) continua sendo despachado, como no entregas:ifood-agendados. O pedido sem localização fica com a
 *   central; aceito, despachado ou encerrado não entra;
 * - avisos: pedidos das últimas 24 h ainda sem o aviso final, só de lojas conectadas e sem falha de aviso nos últimos
 *   5 min (a falha esgotou as tentativas do job: espera, em vez de bater no destino fora do ar a cada minuto), os mais
 *   recentes primeiro. Enfileira o aviso que falta (o saveQuietly do Fleet-Ops, um job perdido com o Redis reiniciado).
 * Pára depois de ORCAMENTO_SEGUNDOS (cada despacho pode esperar até 10 s pela trava); o que ficou vai na rodada seguinte.
 * Uma falha num pedido não para os outros (log `falha ao acompanhar o pedido`).
 */
class AcompanharIntegracao extends Command
{
    protected $signature = 'entregas:integracao-acompanhar';

    protected $description = 'Entregas: refaz o despacho que falhou e reenvia os avisos que faltam dos pedidos da integração';

    public const POR_RODADA = 300;

    public const JANELA_HORAS = 24;

    public const FOLGA_DO_DESPACHO_SEGUNDOS = 60;

    public const JANELA_DO_DESPACHO_MINUTOS = 30;

    public const PAUSA_DEPOIS_DE_FALHA_MINUTOS = 5;

    public const ORCAMENTO_SEGUNDOS = 45;

    public function handle(CriadorDoPedidoDaIntegracao $criador): int
    {
        if (!Integracao::ligada()) {
            return static::SUCCESS;
        }

        $inicio = microtime(true);
        $p      = Integracao::PEDIDOS;

        $paraDespachar = DB::table($p)
            ->join('orders', 'orders.uuid', '=', "{$p}.order_uuid")
            ->whereNull("{$p}.despachado_em")
            ->where("{$p}.sem_localizacao", false)
            ->where("{$p}.created_at", '>=', now()->subMinutes(static::JANELA_DO_DESPACHO_MINUTOS)->toDateTimeString())
            ->where("{$p}.created_at", '<=', now()->subSeconds(static::FOLGA_DO_DESPACHO_SEGUNDOS)->toDateTimeString())
            ->where('orders.dispatched', false)
            ->whereNull('orders.deleted_at')
            ->orderBy("{$p}.id")
            ->limit(static::POR_RODADA)
            ->select("{$p}.*")
            ->get()
            ->all();

        $lojas      = $this->lojasQueRecebem();
        $paraAvisar = [];
        if ($lojas !== []) {
            $paraAvisar = DB::table($p)
                ->join('orders', 'orders.uuid', '=', "{$p}.order_uuid")
                ->whereIn("{$p}.vendor_uuid", array_keys($lojas))
                ->where("{$p}.created_at", '>=', now()->subHours(static::JANELA_HORAS)->toDateTimeString())
                ->where(fn ($q) => $q->whereNull("{$p}.ultimo_aviso")->orWhere("{$p}.ultimo_aviso", SequenciaDaIntegracao::ACEITO)->orWhere("{$p}.ultimo_aviso", SequenciaDaIntegracao::A_CAMINHO))
                ->whereNull('orders.deleted_at')
                ->orderBy("{$p}.created_at", 'desc')
                ->limit(static::POR_RODADA)
                ->select("{$p}.*")
                ->get()
                ->all();
        }

        $uuids = array_values(array_unique(array_merge(array_column($paraDespachar, 'order_uuid'), array_column($paraAvisar, 'order_uuid'))));
        if ($uuids === []) {
            return static::SUCCESS;
        }
        $pedidos = [];
        foreach (Order::whereIn('uuid', $uuids)->get() as $pedido) {
            $pedidos[$pedido->uuid] = $pedido;
        }

        foreach ($paraDespachar as $linha) {
            if ($this->semTempo($inicio)) {
                return static::SUCCESS;
            }
            $pedido = $pedidos[$linha->order_uuid] ?? null;
            if (!$pedido || $pedido->started || in_array($pedido->status, StatusDoPedido::ENCERRADOS, true)) {
                continue;
            }
            try {
                $criador->despachar($pedido);
            } catch (\Throwable $e) {
                Log::warning('[entregas] integração: falha ao acompanhar o pedido', ['pedido' => $pedido->public_id, 'erro' => get_class($e)]);
            }
        }

        foreach ($paraAvisar as $linha) {
            if ($this->semTempo($inicio)) {
                return static::SUCCESS;
            }
            $pedido = $pedidos[$linha->order_uuid] ?? null;
            if (!$pedido) {
                continue;
            }
            try {
                if (SequenciaDaIntegracao::doPedido($linha, $pedido) !== []) {
                    EnviarAvisoDaIntegracao::enfileirar((string) $pedido->uuid);
                }
            } catch (\Throwable $e) {
                Log::warning('[entregas] integração: falha ao acompanhar o pedido', ['pedido' => $pedido->public_id, 'erro' => get_class($e)]);
            }
        }

        return static::SUCCESS;
    }

    protected function semTempo(float $inicio): bool
    {
        return microtime(true) - $inicio > static::ORCAMENTO_SEGUNDOS;
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
}
