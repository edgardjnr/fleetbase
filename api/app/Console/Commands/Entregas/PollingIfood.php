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
 * gravado (o hash dele, sem renovar; no app distribuído cada loja tem o seu, então em geral é um polling por loja), até
 * 100 lojas por chamada. O token de cada lote é pedido dentro do laço dos lotes (VinculosIfood::tokenValido, que renova
 * o que vence em menos de 5 min), sob o teto da rodada; a loja em pausa fica fora antes disso, sem pedir token. Para
 * cada lote: grava os eventos em entregas_ifood_eventos (o evento_id único descarta repetidos), manda o ack de todos os
 * recebidos só depois de gravar (se o banco falhar, sem ack: o iFood reenvia na rodada seguinte) e enfileira um
 * ProcessarPedidoIfood por pedido que recebeu evento e tem evento pendente. Evento sem orderId, merchantId ou code não é
 * gravado, mas recebe o ack (log info "eventos sem pedido descartados", com a quantidade e os códigos). Evento sem id
 * não é gravado nem recebe ack (não há o que confirmar): log info "eventos sem id descartados (sem ack)", com a
 * quantidade e os códigos. Uma loja com erro (token, rede, erro inesperado, erro ao tratar o 403, fila fora do ar
 * depois do ack) nunca para as outras.
 *
 * Pausas por loja (cache entregas:ifood-polling-pausa:<id do vínculo>; a loja pausada fica fora das rodadas até a
 * pausa vencer, as outras seguem):
 * - 429 no polling ou no ack: o limite é do token, então pausa todas as lojas daquele token pelo Retry-After (os outros
 *   lotes do mesmo token ficam de fora já nesta rodada). No ack, os eventos já estão gravados e enfileirados; sem o
 *   ack, o iFood os reenvia depois da pausa e os repetidos não são gravados de novo;
 * - 403 com unauthorizedMerchants: o dono revogou; as lojas listadas que são do lote viram vínculo perdido. 403 sem
 *   lista legível (corpo truncado em 2000 caracteres pelo ClienteIfood, outro formato) ou só com lojas de fora do lote:
 *   pausa o lote por PAUSA_403_SEGUNDOS, com warning, em vez de repetir a cada 30 s. Erro ao marcar o vínculo perdido
 *   (banco, bug): log error "falha ao tratar o 403 do polling" e a mesma pausa do lote.
 * 401: renova o token e repete uma vez (chamarComToken, como o VinculosIfood::comToken), no polling e no ack; o ack usa
 * o token renovado no polling.
 *
 * Limites da rodada: não abre lote novo depois de LIMITE_DA_RODADA_SEGUNDOS (cada chamada pode levar até 15 s,
 * ClienteIfood::TEMPO_LIMITE, e a renovação do token entra nessa conta) e encerra os lotes depois de
 * MAX_FALHAS_TEMPORARIAS falhas temporárias seguidas (rede, 408, 5xx: o iFood está fora, não adianta tentar as outras
 * lojas agora). Os lotes que sobraram ficam para a rodada seguinte, que começa por eles: o cache CHAVE_CURSOR
 * (entregas:ifood-polling-cursor, 1 h) guarda o índice do primeiro lote não atendido (a ordem dos lotes é a do id do
 * primeiro vínculo, e a rodada segue dali até o fim e volta ao início). Rodada que atende todos os lotes grava 0. O
 * índice é aproximado: uma loja que entra, sai ou fica em pausa desloca os lotes, mas o ponto de partida continua
 * andando e nenhuma loja fica sempre de fora.
 *
 * Varredura (em toda rodada, mesmo com o polling falhando ou em pausa): o Redis da produção roda sem persistência (um
 * restart perde os jobs da fila) e o ProcessarPedidoIfood desiste (prazo de 30 min, fail() em erro permanente). Os
 * eventos continuam pendentes no MySQL, e nada os reprocessaria sem outro evento do mesmo pedido. Por isso cada rodada
 * enfileira de novo os pedidos com evento pendente gravado há mais de 2 min e há menos de 6 h (até 50 por rodada, os de
 * evento mais antigo primeiro). Cada pedido fica marcado depois de enfileirado, para não haver dois jobs vivos do mesmo
 * pedido: pelo prazo do job + 5 min (35 min) na primeira vez, depois 2 h e 4 h (intervaloDaVarredura). Se o dispatch
 * falha (fila fora do ar), a marca sai, a vez não conta e os outros pedidos da rodada seguem; um log error por rodada
 * ("falha ao enfileirar pedidos da varredura", com a quantidade e até 5 ids), e a rodada seguinte tenta de novo.
 * Pendente há mais de 6 h não é mais enfileirado: um warning por pedido ("evento pendente há mais de 6 h"), para a
 * central conferir.
 *
 * Uma vez por dia (marca gravada só depois de apagar) apaga os eventos processados há mais de 7 dias e os pendentes
 * gravados há mais de 30 dias (warning com a quantidade). Busca, varredura e limpeza têm cada uma o seu try/catch: a
 * falha de uma não impede as outras. Logs "[entregas] ifood:" só com ids, contagens e a classe do erro (a mensagem do
 * QueryException fica de fora: traz o SQL com os valores).
 */
class PollingIfood extends Command
{
    protected $signature = 'entregas:ifood-polling';

    protected $description = 'iFood: busca os eventos das lojas vinculadas, grava, confirma (ack) e enfileira o processamento';

    public const EVENTOS = 'entregas_ifood_eventos';

    /** Prefixo do cache com a pausa de uma loja (chaveDaPausa). */
    public const CHAVE_PAUSA = 'entregas:ifood-polling-pausa';

    /** Cache com o índice do primeiro lote que a rodada anterior não atendeu (a rodada seguinte começa por ele). */
    public const CHAVE_CURSOR = 'entregas:ifood-polling-cursor';

    /** Validade do cursor (sem rodada por mais disto, volta ao primeiro lote). */
    public const CURSOR_SEGUNDOS = 3600;

    /** Cache que marca a limpeza do dia. */
    public const CHAVE_LIMPEZA = 'entregas:ifood-eventos-limpeza';

    public const DIAS_GUARDADOS = 7;

    /** Pendente gravado há mais disto é apagado na limpeza (nunca vai ser processado). */
    public const DIAS_GUARDADOS_PENDENTES = 30;

    /** Pausa do lote depois de um 403 sem a lista de lojas (ou só com lojas de fora do lote), em segundos. */
    public const PAUSA_403_SEGUNDOS = 300;

    /** Depois disto (segundos desde o início da rodada), nenhum lote novo é aberto. */
    public const LIMITE_DA_RODADA_SEGUNDOS = 25;

    /** Falhas temporárias seguidas (rede, 408, 5xx) que encerram os lotes da rodada. */
    public const MAX_FALHAS_TEMPORARIAS = 3;

    /** A varredura só pega o evento pendente gravado há mais disto (o job da própria rodada ainda pode estar na fila). */
    public const VARREDURA_DEPOIS_SEGUNDOS = 120;

    /** Pendente há mais disto não é mais enfileirado: só o warning, uma vez por pedido. */
    public const VARREDURA_ATE_SEGUNDOS = 21600;

    /** Pedidos enfileirados pela varredura, no máximo, por rodada. */
    public const VARREDURA_POR_RODADA = 50;

    /** Pedidos distintos lidos por consulta da varredura (acima dos 50: os marcados são pulados). */
    public const VARREDURA_CONSULTA = 500;

    /** Intervalos das vezes seguintes em que a varredura enfileira o mesmo pedido (a primeira é intervaloBase()). */
    public const VARREDURA_INTERVALOS_SEGUINTES = [7200, 14400];

    /** Validade do contador de vezes da varredura por pedido (passa da janela de 6 h). */
    public const VARREDURA_VEZES_SEGUNDOS = 86400;

    /** Resultados de rodarLote. */
    protected const LOTE_OK               = 'ok';
    protected const LOTE_FALHA_TEMPORARIA = 'falha_temporaria';
    protected const LOTE_LIMITE_DO_TOKEN  = 'limite_do_token';

    /** Pedidos enfileirados nesta rodada (pelo lote), para a varredura não enfileirar de novo. */
    protected array $enfileirados = [];

    /** relogio() no início da rodada. */
    protected float $inicio = 0.0;

    /** Espera do último 429 (segundos), para pausar os outros lotes do mesmo token. */
    protected int $esperaDoLimite = ClienteIfood::ESPERA_PADRAO_429;

    public static function chaveDaVarredura(string $pedidoIfoodId): string
    {
        return "entregas:ifood-varredura:{$pedidoIfoodId}";
    }

    public static function chaveDasVezesDaVarredura(string $pedidoIfoodId): string
    {
        return "entregas:ifood-varredura-vezes:{$pedidoIfoodId}";
    }

    public static function chaveDoPendenteAntigo(string $pedidoIfoodId): string
    {
        return "entregas:ifood-pendente-antigo:{$pedidoIfoodId}";
    }

    public static function chaveDaPausa(int|string $vinculoId): string
    {
        return static::CHAVE_PAUSA . ':' . $vinculoId;
    }

    /**
     * Marca do pedido enfileirado (segundos): mais que o prazo total do ProcessarPedidoIfood (o job vive até lá, com os
     * release()), para a varredura nunca criar um segundo job do mesmo pedido com o primeiro ainda vivo.
     */
    public static function intervaloBase(): int
    {
        return ProcessarPedidoIfood::PRAZO_MINUTOS * 60 + 300;
    }

    /** Marca da varredura na vez $vezes (0 = primeira) do mesmo pedido: 35 min, 2 h, 4 h, 4 h... */
    public static function intervaloDaVarredura(int $vezes): int
    {
        if ($vezes <= 0) {
            return static::intervaloBase();
        }
        $seguintes = static::VARREDURA_INTERVALOS_SEGUINTES;

        return $seguintes[min($vezes, count($seguintes)) - 1];
    }

    public function handle(VinculosIfood $vinculos, ClienteIfood $cliente): int
    {
        if (!ClienteIfood::ligada()) {
            return self::SUCCESS;
        }

        $this->enfileirados = [];
        $this->inicio       = $this->relogio();
        $resultado          = self::SUCCESS;

        // cada etapa no seu try: a falha de uma não impede as outras
        try {
            $this->buscarEventos($vinculos, $cliente);
        } catch (\Throwable $e) {
            Log::error('[entregas] ifood: polling interrompido', static::descreverErro($e));
            $resultado = self::FAILURE;
        }

        try {
            $this->varrerPendentes();
        } catch (\Throwable $e) {
            Log::error('[entregas] ifood: varredura dos pendentes falhou', static::descreverErro($e));
            $resultado = self::FAILURE;
        }

        try {
            $this->limparAntigos();
        } catch (\Throwable $e) {
            Log::error('[entregas] ifood: limpeza dos eventos antigos falhou', static::descreverErro($e));
            $resultado = self::FAILURE;
        }

        return $resultado;
    }

    /**
     * Segundos (com fração) do relógio monotônico (hrtime: não anda para trás nem salta com o ajuste do relógio do
     * sistema), para medir a rodada; o valor só serve para diferenças. Os testes trocam.
     */
    protected function relogio(): float
    {
        return hrtime(true) / 1e9;
    }

    /**
     * Agrupa pelo token ('token' = a chave do agrupamento, chaveDoToken) e corta em lotes de até 100 lojas, na ordem
     * da lista: [['token', 'vinculo' (o primeiro do lote: dá o token e renova no 401), 'vinculos' => [ids],
     * 'merchants' => [...]], ...].
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
                $lotes[] = [
                    'token'     => (string) $token,
                    'vinculo'   => $pedaco[0]['vinculo'],
                    'vinculos'  => array_map(fn (array $item) => $item['vinculo']->id ?? null, $pedaco),
                    'merchants' => array_column($pedaco, 'merchant_id'),
                ];
            }
        }

        return $lotes;
    }

    protected function buscarEventos(VinculosIfood $vinculos, ClienteIfood $cliente): void
    {
        $itens = [];
        foreach ($vinculos->vinculadas() as $vinculo) {
            // loja em pausa (429 do token dela, 403 sem lista) fica fora até vencer, sem nem pedir o token
            if (Cache::get(static::chaveDaPausa($vinculo->id))) {
                continue;
            }
            $itens[] = ['token' => static::chaveDoToken($vinculo), 'vinculo' => $vinculo, 'merchant_id' => $vinculo->merchant_id];
        }

        $lotes = static::lotes($itens);
        $total = count($lotes);
        if (!$total) {
            return;
        }

        // a rodada começa pelo primeiro lote que a anterior não atendeu (teto de tempo, falhas seguidas): com a ordem
        // fixa, as lojas do fim da lista nunca seriam consultadas
        $partida = (int) Cache::get(static::CHAVE_CURSOR, 0);
        if ($partida < 0 || $partida >= $total) {
            $partida = 0;
        }
        $proximo        = 0;
        $tokensNoLimite = [];
        $falhasSeguidas = 0;
        for ($passo = 0; $passo < $total; $passo++) {
            $indice = ($partida + $passo) % $total;
            $lote   = $lotes[$indice];
            if (isset($tokensNoLimite[$lote['token']])) {
                continue;
            }
            if ($passo > 0 && $this->relogio() - $this->inicio >= static::LIMITE_DA_RODADA_SEGUNDOS) {
                Log::warning('[entregas] ifood: rodada passou do tempo; os lotes restantes ficam para a próxima', ['segundos' => static::LIMITE_DA_RODADA_SEGUNDOS, 'lotes_restantes' => $total - $passo]);
                $proximo = $indice;
                break;
            }

            $resultado = $this->rodarLote($lote, $vinculos, $cliente);

            if ($resultado === static::LOTE_LIMITE_DO_TOKEN) {
                // o limite é do token: as outras lojas dele também ficam em pausa (nesta rodada e nas seguintes)
                $tokensNoLimite[$lote['token']] = true;
                foreach ($lotes as $outro) {
                    if ($outro['token'] === $lote['token']) {
                        $this->pausar($outro, $this->esperaDoLimite);
                    }
                }
            }

            $falhasSeguidas = $resultado === static::LOTE_FALHA_TEMPORARIA ? $falhasSeguidas + 1 : 0;
            if ($falhasSeguidas >= static::MAX_FALHAS_TEMPORARIAS) {
                $restantes = $total - $passo - 1;
                if ($restantes > 0) {
                    Log::warning('[entregas] ifood: polling encerrado na rodada depois de falhas temporárias seguidas', ['falhas' => $falhasSeguidas, 'lotes_restantes' => $restantes]);
                    $proximo = ($indice + 1) % $total;
                }
                break;
            }
        }

        Cache::put(static::CHAVE_CURSOR, $proximo, static::CURSOR_SEGUNDOS);
    }

    /**
     * Chave do agrupamento por token: o hash do token gravado, sem renovar (a renovação fica no lote, sob o teto da
     * rodada). Sem token ou ilegível: um lote só da loja (o tokenValido do lote renova ou tira a loja do polling).
     */
    protected static function chaveDoToken(object $vinculo): string
    {
        try {
            if ($vinculo->access_token ?? null) {
                return 'token:' . hash('sha256', (string) decrypt($vinculo->access_token));
            }
        } catch (\Throwable) {
            // cai no lote próprio
        }

        return 'vinculo:' . ($vinculo->id ?? spl_object_id($vinculo));
    }

    /** Pausa todas as lojas do lote por $segundos. */
    protected function pausar(array $lote, int $segundos): void
    {
        foreach ($lote['vinculos'] as $id) {
            if ($id !== null) {
                Cache::put(static::chaveDaPausa($id), true, $segundos);
            }
        }
    }

    protected function rodarLote(array $lote, VinculosIfood $vinculos, ClienteIfood $cliente): string
    {
        // o token do lote (renovado se vence em menos de 5 min) é pedido aqui, dentro do laço dos lotes e sob o teto da
        // rodada; uma loja com problema no token não para as outras
        $vinculo = $lote['vinculo'];
        try {
            $token = $vinculos->tokenValido($vinculo);
        } catch (VinculoPerdido) {
            return static::LOTE_OK;
        } catch (ErroIfood $e) {
            Log::warning('[entregas] ifood: token indisponível para o polling', ['loja' => $vinculo->vendor_uuid ?? null, 'merchant' => $vinculo->merchant_id ?? null, 'status' => $e->status]);

            return static::LOTE_OK;
        } catch (\Throwable $e) {
            Log::warning('[entregas] ifood: token indisponível para o polling', ['loja' => $vinculo->vendor_uuid ?? null, 'merchant' => $vinculo->merchant_id ?? null] + static::descreverErro($e));

            return static::LOTE_OK;
        }

        try {
            $eventos = $this->chamarComToken($vinculo, $token, $vinculos, fn (string $token) => $cliente->polling($token, $lote['merchants']));
        } catch (VinculoPerdido) {
            return static::LOTE_OK;
        } catch (ErroIfood $e) {
            if ($e->limiteExcedido()) {
                // buscarEventos pausa as lojas deste lote e as dos outros lotes do mesmo token
                $this->limiteDoToken($e, '429', ['lojas' => count($lote['merchants'])]);

                return static::LOTE_LIMITE_DO_TOKEN;
            }
            if ($e->status === 403) {
                try {
                    $this->tratar403($e, $lote, $vinculos);
                } catch (\Throwable $falha) {
                    // erro ao marcar o vínculo perdido (banco, bug): o lote pausa, em vez de repetir a cada 30 s, e os
                    // outros lotes seguem
                    Log::error('[entregas] ifood: falha ao tratar o 403 do polling; lote em pausa', ['lojas' => count($lote['merchants']), 'segundos' => static::PAUSA_403_SEGUNDOS] + static::descreverErro($falha));
                    $this->pausar($lote, static::PAUSA_403_SEGUNDOS);
                }

                return static::LOTE_OK;
            }
            Log::warning('[entregas] ifood: polling falhou', ['status' => $e->status, 'lojas' => count($lote['merchants'])]);

            return $e->temporario() ? static::LOTE_FALHA_TEMPORARIA : static::LOTE_OK;
        } catch (\Throwable $e) {
            // erro inesperado (banco na renovação do 401, bug): este lote fica para a próxima rodada, os outros seguem
            Log::warning('[entregas] ifood: polling falhou', ['merchants' => $lote['merchants']] + static::descreverErro($e));

            return static::LOTE_OK;
        }

        if (!$eventos) {
            return static::LOTE_OK;
        }

        // evento sem id não tem como receber o ack nem ser gravado: só a contagem e os códigos no log (sem o payload)
        $semId = array_filter($eventos, fn (array $evento) => !is_string($evento['id'] ?? null) || $evento['id'] === '');
        if ($semId) {
            Log::info('[entregas] ifood: eventos sem id descartados (sem ack)', ['quantidade' => count($semId), 'codigos' => static::codigosDoLog($semId)]);
        }

        $unicos    = EventosIfood::deduplicar($eventos);
        $agora     = now()->toDateTimeString();
        $linhas    = [];
        $descartes = [];
        foreach ($unicos as $evento) {
            $linha = EventosIfood::paraGravar($evento, $agora);
            if ($linha) {
                $linhas[] = $linha;
            } else {
                $descartes[] = $evento;
            }
        }
        if ($descartes) {
            // recebem o ack (abaixo), mas sem pedido não há o que processar; só a contagem e os códigos (sem o payload)
            Log::info('[entregas] ifood: eventos sem pedido descartados', ['quantidade' => count($descartes), 'codigos' => static::codigosDoLog($descartes)]);
        }

        try {
            DB::table(static::EVENTOS)->insertOrIgnore($linhas);
        } catch (\Throwable $e) {
            Log::error('[entregas] ifood: falha ao gravar os eventos; sem ack, o iFood reenvia', ['erro' => get_class($e), 'eventos' => count($linhas)]);

            return static::LOTE_OK;
        }

        // ack de todos os recebidos, inclusive os repetidos e os que não vamos usar (a documentação pede)
        $resultado = static::LOTE_OK;
        $ids       = array_column($unicos, 'id');
        try {
            $this->chamarComToken($vinculo, $token, $vinculos, fn (string $token) => $cliente->ack($token, $ids));
        } catch (\Throwable $e) {
            if ($e instanceof ErroIfood && $e->limiteExcedido()) {
                // o limite é do token, como no polling: buscarEventos pausa as lojas dele pelo Retry-After (sem ack, o
                // iFood reenvia os eventos depois da pausa, e os repetidos não são gravados de novo)
                $this->limiteDoToken($e, '429 no ack', ['eventos' => count($ids)]);
                $resultado = static::LOTE_LIMITE_DO_TOKEN;
            } else {
                Log::warning('[entregas] ifood: ack falhou', ['status' => $e instanceof ErroIfood ? $e->status : get_class($e), 'eventos' => count($ids)]);
            }
        }

        // um processamento por pedido que recebeu evento nesta rodada e tem evento pendente (o job processa também os
        // pendentes antigos dele); pedido com pendente antigo e sem evento novo fica para a varredura. Erro aqui (banco,
        // fila fora do ar) não para os outros lotes: os eventos já estão gravados e a varredura enfileira depois.
        $pedidos = array_values(array_unique(array_column($linhas, 'pedido_ifood_id')));
        if ($pedidos) {
            try {
                $pendentes = DB::table(static::EVENTOS)->whereIn('pedido_ifood_id', $pedidos)->whereNull('processado_em')->pluck('pedido_ifood_id')->all();
                foreach (array_values(array_unique($pendentes)) as $pedido) {
                    $this->enfileirar($pedido);
                }
            } catch (\Throwable $e) {
                Log::error('[entregas] ifood: falha ao enfileirar os pedidos do lote; a varredura tenta de novo', ['pedidos' => count($pedidos)] + static::descreverErro($e));
            }
        }

        return $resultado;
    }

    /**
     * Roda $chamada($token) com o token do lote; no 401, renova (com o token recusado: se outro processo já renovou, vale
     * o dele) e repete uma vez, como o VinculosIfood::comToken, mas sem pedir o token de novo. O token renovado fica em
     * $token para a chamada seguinte do lote (o ack).
     */
    protected function chamarComToken(object $vinculo, #[\SensitiveParameter] string &$token, VinculosIfood $vinculos, \Closure $chamada): mixed
    {
        try {
            return $chamada($token);
        } catch (ErroIfood $e) {
            if (!$e->naoAutorizado()) {
                throw $e;
            }
            Log::info('[entregas] ifood: token recusado (401), renovando', ['merchant' => $vinculo->merchant_id ?? null]);
            $token = $vinculos->renovar($vinculo, $token);

            return $chamada($token);
        }
    }

    /** 429 (polling ou ack): guarda a espera do Retry-After para buscarEventos pausar as lojas do token. */
    protected function limiteDoToken(ErroIfood $e, string $onde, array $contexto): void
    {
        $espera               = $e->retryAfter ?? ClienteIfood::ESPERA_PADRAO_429;
        $this->esperaDoLimite = $espera;
        Log::warning("[entregas] ifood: {$onde}, esperando {$espera} s", $contexto);
    }

    /** Os códigos distintos dos eventos (até 10 caracteres cada), para o log; nunca o payload. */
    protected static function codigosDoLog(array $eventos): array
    {
        $codigos = [];
        foreach ($eventos as $evento) {
            if (is_string($evento['code'] ?? null) && $evento['code'] !== '') {
                $codigos[] = mb_substr($evento['code'], 0, 10);
            }
        }

        return array_values(array_unique($codigos));
    }

    /**
     * 403: as lojas de unauthorizedMerchants que são deste lote viram vínculo perdido (uma lista com lojas de outro lote
     * não derruba ninguém de fora). Sem lista legível (corpo truncado, outro formato) ou sem nenhuma loja do lote nela:
     * pausa o lote, em vez de repetir a cada 30 s.
     */
    protected function tratar403(ErroIfood $e, array $lote, VinculosIfood $vinculos): void
    {
        $lista    = $e->corpoJson()['unauthorizedMerchants'] ?? null;
        $listados = is_array($lista) ? array_values(array_filter($lista, 'is_string')) : [];
        $perdidos = array_values(array_intersect($lote['merchants'], $listados));

        foreach ($perdidos as $merchant) {
            $vinculos->perderPorMerchant($merchant, 403);
        }

        if ($perdidos) {
            Log::warning('[entregas] ifood: polling falhou', ['status' => 403, 'lojas' => count($lote['merchants']), 'perdidas' => count($perdidos)]);

            return;
        }

        $this->pausar($lote, static::PAUSA_403_SEGUNDOS);
        Log::warning('[entregas] ifood: polling falhou com 403 sem a lista de lojas do lote; lote em pausa', ['status' => 403, 'lojas' => count($lote['merchants']), 'merchants' => array_slice($lote['merchants'], 0, 5), 'segundos' => static::PAUSA_403_SEGUNDOS]);
    }

    /**
     * Enfileira de novo os pedidos com evento pendente gravado entre 2 min e 6 h atrás (job perdido num restart do Redis
     * ou que desistiu), até VARREDURA_POR_RODADA, os de evento mais antigo primeiro, cada um marcado por
     * intervaloDaVarredura(vezes). Pendente há mais de 6 h (e menos de 7 dias): só o warning, uma vez por pedido.
     */
    protected function varrerPendentes(): void
    {
        $agora  = now();
        $depois = (clone $agora)->subSeconds(static::VARREDURA_DEPOIS_SEGUNDOS)->toDateTimeString();
        $ate    = (clone $agora)->subSeconds(static::VARREDURA_ATE_SEGUNDOS)->toDateTimeString();
        $limite = (clone $agora)->subDays(static::DIAS_GUARDADOS)->toDateTimeString();

        $enfileirados = 0;
        $falhas       = [];
        $erro         = null;
        foreach ($this->pedidosPendentes($depois, $ate) as $pedido) {
            if ($enfileirados >= static::VARREDURA_POR_RODADA) {
                break;
            }
            if (!is_string($pedido) || isset($this->enfileirados[$pedido])) {
                continue;
            }
            $vezes = (int) Cache::get(static::chaveDasVezesDaVarredura($pedido), 0);
            if (!Cache::add(static::chaveDaVarredura($pedido), true, static::intervaloDaVarredura($vezes))) {
                continue;
            }
            try {
                $this->despachar($pedido);
            } catch (\Throwable $e) {
                // sem job, a marca seguraria o pedido por 35 min ou mais: sai, e a rodada seguinte tenta de novo; os
                // outros pedidos desta rodada seguem
                Cache::forget(static::chaveDaVarredura($pedido));
                $falhas[] = $pedido;
                $erro ??= $e;
                continue;
            }
            Cache::put(static::chaveDasVezesDaVarredura($pedido), $vezes + 1, static::VARREDURA_VEZES_SEGUNDOS);
            $this->enfileirados[$pedido] = true;
            $enfileirados++;
        }
        if ($enfileirados) {
            Log::info('[entregas] ifood: pedidos com evento pendente enfileirados de novo', ['quantidade' => $enfileirados]);
        }
        if ($falhas) {
            // um log por rodada (com a fila fora do ar, seriam até 50 a cada 30 s), com o erro do primeiro
            Log::error('[entregas] ifood: falha ao enfileirar pedidos da varredura; a rodada seguinte tenta de novo', ['quantidade' => count($falhas), 'pedidos_ifood' => array_slice($falhas, 0, 5)] + static::descreverErro($erro));
        }

        foreach ($this->pedidosPendentes($ate, $limite) as $pedido) {
            if (is_string($pedido) && Cache::add(static::chaveDoPendenteAntigo($pedido), true, static::DIAS_GUARDADOS * 86400)) {
                Log::warning('[entregas] ifood: evento pendente há mais de 6 h', ['pedido_ifood' => $pedido]);
            }
        }
    }

    /**
     * Pedidos distintos com evento pendente gravado em [$desde, $antesDe), os de evento mais antigo primeiro, até
     * VARREDURA_CONSULTA. GROUP BY + ORDER BY MIN(created_at): o MySQL 8 recusa ORDER BY created_at com DISTINCT
     * (coluna fora do SELECT), e sem ordem o LIMIT devolveria um recorte arbitrário.
     */
    protected function pedidosPendentes(string $antesDe, string $desde): array
    {
        return DB::table(static::EVENTOS)
            ->whereNull('processado_em')
            ->where('created_at', '<', $antesDe)
            ->where('created_at', '>=', $desde)
            ->groupBy('pedido_ifood_id')
            ->orderByRaw('min(created_at) asc')
            ->limit(static::VARREDURA_CONSULTA)
            ->pluck('pedido_ifood_id')
            ->all();
    }

    /** Enfileira o ProcessarPedidoIfood do pedido (os testes trocam para simular a fila fora do ar num pedido só). */
    protected function despachar(string $pedido): void
    {
        ProcessarPedidoIfood::dispatch($pedido);
    }

    protected function enfileirar(string $pedido): void
    {
        $this->despachar($pedido);
        $this->enfileirados[$pedido] = true;
        // a varredura não enfileira este pedido de novo enquanto o job pode estar vivo (prazo do job + 5 min)
        Cache::put(static::chaveDaVarredura($pedido), true, static::intervaloBase());
    }

    protected function limparAntigos(): void
    {
        if (Cache::get(static::CHAVE_LIMPEZA)) {
            return;
        }

        $apagados = DB::table(static::EVENTOS)
            ->whereNotNull('processado_em')
            ->where('processado_em', '<', now()->subDays(static::DIAS_GUARDADOS)->toDateTimeString())
            ->delete();

        $pendentes = DB::table(static::EVENTOS)
            ->whereNull('processado_em')
            ->where('created_at', '<', now()->subDays(static::DIAS_GUARDADOS_PENDENTES)->toDateTimeString())
            ->delete();

        // a marca do dia só depois de apagar: com o banco fora, a rodada seguinte tenta de novo
        Cache::put(static::CHAVE_LIMPEZA, true, 86400);

        if ($apagados) {
            Log::info('[entregas] ifood: eventos antigos apagados', ['quantidade' => $apagados]);
        }
        if ($pendentes) {
            Log::warning('[entregas] ifood: eventos pendentes há mais de 30 dias apagados sem processar', ['quantidade' => $pendentes]);
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
