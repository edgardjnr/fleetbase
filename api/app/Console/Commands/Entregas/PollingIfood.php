<?php

namespace App\Console\Commands\Entregas;

use App\Jobs\Entregas\ProcessarPedidoIfood;
use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\ErroIfood;
use App\Support\Entregas\Ifood\EventosIfood;
use App\Support\Entregas\Ifood\VinculoPerdido;
use App\Support\Entregas\Ifood\VinculosIfood;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: polling de eventos do iFood (agendado a cada 30 s no App\Console\Kernel, sem sobrepor).
 *
 * Só roda com a integração ligada (ENTREGAS_IFOOD=1 e as credenciais). As lojas vinculadas são agrupadas pelo token
 * (no app distribuído cada loja tem o seu, então em geral é um polling por loja), até 100 lojas por chamada. Para cada
 * lote: grava os eventos em entregas_ifood_eventos (o evento_id único descarta repetidos), manda o ack de todos os
 * recebidos só depois de gravar (se o banco falhar, sem ack: o iFood reenvia na rodada seguinte) e enfileira um
 * ProcessarPedidoIfood por pedido que recebeu evento e tem evento pendente. Uma loja com erro (token, rede, erro
 * inesperado) nunca para as outras.
 *
 * 429: para a rodada e pausa o polling pelo Retry-After (cache). 401: renova o token e repete uma vez
 * (VinculosIfood::comToken). 403 com unauthorizedMerchants: o dono revogou; a loja vira vínculo perdido.
 *
 * Varredura (em toda rodada, mesmo com o polling falhando ou em pausa): o Redis da produção roda sem persistência (um
 * restart perde os jobs da fila) e o ProcessarPedidoIfood desiste (prazo de 30 min, fail() em erro permanente). Os
 * eventos continuam pendentes no MySQL, e nada os reprocessaria sem outro evento do mesmo pedido. Por isso cada rodada
 * enfileira de novo os pedidos com evento pendente gravado há mais de 2 min e há menos de 6 h (até 50 por rodada; cada
 * pedido no máximo uma vez a cada 5 min). Pendente há mais de 6 h não é mais enfileirado: um warning por pedido
 * ("evento pendente há mais de 6 h"), para a central conferir.
 *
 * Uma vez por dia apaga os eventos processados há mais de 7 dias. Logs "[entregas] ifood:" só com ids, contagens e a
 * classe do erro (a mensagem do QueryException fica de fora: traz o SQL com os valores).
 */
class PollingIfood extends Command
{
    protected $signature = 'entregas:ifood-polling';

    protected $description = 'iFood: busca os eventos das lojas vinculadas, grava, confirma (ack) e enfileira o processamento';

    public const EVENTOS = 'entregas_ifood_eventos';

    /** Cache com a pausa depois de um 429 (validade = Retry-After). */
    public const CHAVE_PAUSA = 'entregas:ifood-polling-pausa';

    /** Cache que marca a limpeza do dia. */
    public const CHAVE_LIMPEZA = 'entregas:ifood-eventos-limpeza';

    public const DIAS_GUARDADOS = 7;

    /** A varredura só pega o evento pendente gravado há mais disto (o job da própria rodada ainda pode estar na fila). */
    public const VARREDURA_DEPOIS_SEGUNDOS = 120;

    /** Pendente há mais disto não é mais enfileirado: só o warning, uma vez por pedido. */
    public const VARREDURA_ATE_SEGUNDOS = 21600;

    /** Pedidos enfileirados pela varredura, no máximo, por rodada. */
    public const VARREDURA_POR_RODADA = 50;

    /** Pedidos distintos lidos por consulta da varredura (acima dos 50: os já enfileirados nos últimos 5 min são pulados). */
    public const VARREDURA_CONSULTA = 500;

    /** Cada pedido é enfileirado pela varredura no máximo uma vez neste intervalo (segundos). */
    public const VARREDURA_INTERVALO_SEGUNDOS = 300;

    /** Pedidos enfileirados nesta rodada (pelo lote), para a varredura não enfileirar de novo. */
    protected array $enfileirados = [];

    public static function chaveDaVarredura(string $pedidoIfoodId): string
    {
        return "entregas:ifood-varredura:{$pedidoIfoodId}";
    }

    public static function chaveDoPendenteAntigo(string $pedidoIfoodId): string
    {
        return "entregas:ifood-pendente-antigo:{$pedidoIfoodId}";
    }

    public function handle(VinculosIfood $vinculos, ClienteIfood $cliente): int
    {
        if (!ClienteIfood::ligada()) {
            return self::SUCCESS;
        }

        $this->enfileirados = [];
        $resultado          = self::SUCCESS;

        if (!Cache::get(static::CHAVE_PAUSA)) {
            try {
                $this->buscarEventos($vinculos, $cliente);
            } catch (\Throwable $e) {
                // a varredura e a limpeza rodam mesmo assim
                Log::error('[entregas] ifood: polling interrompido', static::descreverErro($e));
                $resultado = self::FAILURE;
            }
        }

        $this->varrerPendentes();
        $this->limparAntigos();

        return $resultado;
    }

    /**
     * Agrupa pelo token e corta em lotes de até 100 lojas: [['token', 'vinculo' (o primeiro do lote, para renovar no
     * 401), 'merchants' => [...]], ...].
     */
    public static function lotes(array $comToken): array
    {
        $porToken = [];
        foreach ($comToken as $item) {
            $porToken[(string) $item['token']][] = $item;
        }

        $lotes = [];
        foreach ($porToken as $token => $itens) {
            foreach (array_chunk($itens, ClienteIfood::MAX_MERCHANTS_POR_POLLING) as $pedaco) {
                $lotes[] = ['token' => (string) $token, 'vinculo' => $pedaco[0]['vinculo'], 'merchants' => array_column($pedaco, 'merchant_id')];
            }
        }

        return $lotes;
    }

    protected function buscarEventos(VinculosIfood $vinculos, ClienteIfood $cliente): void
    {
        $comToken = [];
        foreach ($vinculos->vinculadas() as $vinculo) {
            // uma loja com problema não para as outras
            try {
                $comToken[] = ['token' => $vinculos->tokenValido($vinculo), 'vinculo' => $vinculo, 'merchant_id' => $vinculo->merchant_id];
            } catch (VinculoPerdido) {
                continue;
            } catch (ErroIfood $e) {
                Log::warning('[entregas] ifood: token indisponível para o polling', ['loja' => $vinculo->vendor_uuid ?? null, 'merchant' => $vinculo->merchant_id, 'status' => $e->status]);
            } catch (\Throwable $e) {
                Log::warning('[entregas] ifood: token indisponível para o polling', ['loja' => $vinculo->vendor_uuid ?? null, 'merchant' => $vinculo->merchant_id] + static::descreverErro($e));
            }
        }

        foreach (static::lotes($comToken) as $lote) {
            if (!$this->rodarLote($lote, $vinculos, $cliente)) {
                break;
            }
        }
    }

    /** false = 429: a rodada para. */
    protected function rodarLote(array $lote, VinculosIfood $vinculos, ClienteIfood $cliente): bool
    {
        try {
            $eventos = $vinculos->comToken($lote['vinculo'], fn (string $token) => $cliente->polling($token, $lote['merchants']));
        } catch (VinculoPerdido) {
            return true;
        } catch (ErroIfood $e) {
            if ($e->limiteExcedido()) {
                $espera = $e->retryAfter ?? ClienteIfood::ESPERA_PADRAO_429;
                Cache::put(static::CHAVE_PAUSA, true, $espera);
                Log::warning("[entregas] ifood: 429, esperando {$espera} s", ['lojas' => count($lote['merchants'])]);

                return false;
            }
            if ($e->status === 403) {
                foreach ((array) ($e->corpoJson()['unauthorizedMerchants'] ?? []) as $merchant) {
                    if (is_string($merchant)) {
                        $vinculos->perderPorMerchant($merchant, 403);
                    }
                }
            }
            Log::warning('[entregas] ifood: polling falhou', ['status' => $e->status, 'lojas' => count($lote['merchants'])]);

            return true;
        } catch (\Throwable $e) {
            // erro inesperado (banco na renovação, bug): este lote fica para a próxima rodada, os outros seguem
            Log::warning('[entregas] ifood: polling falhou', ['merchants' => $lote['merchants']] + static::descreverErro($e));

            return true;
        }

        if (!$eventos) {
            return true;
        }

        $unicos = EventosIfood::deduplicar($eventos);
        $agora  = now()->toDateTimeString();
        $linhas = array_values(array_filter(array_map(fn (array $evento) => EventosIfood::paraGravar($evento, $agora), $unicos)));

        try {
            DB::table(static::EVENTOS)->insertOrIgnore($linhas);
        } catch (\Throwable $e) {
            Log::error('[entregas] ifood: falha ao gravar os eventos; sem ack, o iFood reenvia', ['erro' => get_class($e), 'eventos' => count($linhas)]);

            return true;
        }

        // ack de todos os recebidos, inclusive os repetidos e os que não vamos usar (a documentação pede)
        $ids = array_column($unicos, 'id');
        try {
            $vinculos->comToken($lote['vinculo'], fn (string $token) => $cliente->ack($token, $ids));
        } catch (\Throwable $e) {
            Log::warning('[entregas] ifood: ack falhou', ['status' => $e instanceof ErroIfood ? $e->status : get_class($e), 'eventos' => count($ids)]);
        }

        // um processamento por pedido que recebeu evento nesta rodada e tem evento pendente (o job processa também os
        // pendentes antigos dele); pedido com pendente antigo e sem evento novo fica para a varredura
        $pedidos = array_values(array_unique(array_column($linhas, 'pedido_ifood_id')));
        if ($pedidos) {
            $pendentes = DB::table(static::EVENTOS)->whereIn('pedido_ifood_id', $pedidos)->whereNull('processado_em')->pluck('pedido_ifood_id')->all();
            foreach (array_values(array_unique($pendentes)) as $pedido) {
                $this->enfileirar($pedido);
            }
        }

        return true;
    }

    /**
     * Enfileira de novo os pedidos com evento pendente gravado entre 2 min e 6 h atrás (job perdido num restart do Redis
     * ou que desistiu), até VARREDURA_POR_RODADA, cada um no máximo uma vez a cada 5 min. Pendente há mais de 6 h (e
     * menos de 7 dias): só o warning, uma vez por pedido.
     */
    protected function varrerPendentes(): void
    {
        $agora  = now();
        $depois = (clone $agora)->subSeconds(static::VARREDURA_DEPOIS_SEGUNDOS)->toDateTimeString();
        $ate    = (clone $agora)->subSeconds(static::VARREDURA_ATE_SEGUNDOS)->toDateTimeString();
        $limite = (clone $agora)->subDays(static::DIAS_GUARDADOS)->toDateTimeString();

        $pendentes = DB::table(static::EVENTOS)
            ->whereNull('processado_em')
            ->where('created_at', '<', $depois)
            ->where('created_at', '>=', $ate)
            ->distinct()
            ->limit(static::VARREDURA_CONSULTA)
            ->pluck('pedido_ifood_id')
            ->all();

        $enfileirados = 0;
        foreach ($pendentes as $pedido) {
            if ($enfileirados >= static::VARREDURA_POR_RODADA) {
                break;
            }
            if (!is_string($pedido) || isset($this->enfileirados[$pedido])) {
                continue;
            }
            if (!Cache::add(static::chaveDaVarredura($pedido), true, static::VARREDURA_INTERVALO_SEGUNDOS)) {
                continue;
            }
            ProcessarPedidoIfood::dispatch($pedido);
            $this->enfileirados[$pedido] = true;
            $enfileirados++;
        }
        if ($enfileirados) {
            Log::info('[entregas] ifood: pedidos com evento pendente enfileirados de novo', ['quantidade' => $enfileirados]);
        }

        $antigos = DB::table(static::EVENTOS)
            ->whereNull('processado_em')
            ->where('created_at', '<', $ate)
            ->where('created_at', '>=', $limite)
            ->distinct()
            ->limit(static::VARREDURA_CONSULTA)
            ->pluck('pedido_ifood_id')
            ->all();

        foreach ($antigos as $pedido) {
            if (is_string($pedido) && Cache::add(static::chaveDoPendenteAntigo($pedido), true, static::DIAS_GUARDADOS * 86400)) {
                Log::warning('[entregas] ifood: evento pendente há mais de 6 h', ['pedido_ifood' => $pedido]);
            }
        }
    }

    protected function enfileirar(string $pedido): void
    {
        ProcessarPedidoIfood::dispatch($pedido);
        $this->enfileirados[$pedido] = true;
        // a varredura não enfileira este pedido de novo nos próximos 5 min (o job pode ainda estar na fila)
        Cache::put(static::chaveDaVarredura($pedido), true, static::VARREDURA_INTERVALO_SEGUNDOS);
    }

    protected function limparAntigos(): void
    {
        if (Cache::get(static::CHAVE_LIMPEZA)) {
            return;
        }
        Cache::put(static::CHAVE_LIMPEZA, true, 86400);

        $apagados = DB::table(static::EVENTOS)
            ->whereNotNull('processado_em')
            ->where('processado_em', '<', now()->subDays(static::DIAS_GUARDADOS)->toDateTimeString())
            ->delete();

        if ($apagados) {
            Log::info('[entregas] ifood: eventos antigos apagados', ['quantidade' => $apagados]);
        }
    }

    /** Classe e mensagem do erro para o log; a do QueryException fica de fora (traz o SQL com os valores). */
    protected static function descreverErro(\Throwable $e): array
    {
        return $e instanceof QueryException
            ? ['erro' => get_class($e)]
            : ['erro' => get_class($e), 'mensagem' => mb_substr($e->getMessage(), 0, 200)];
    }
}
