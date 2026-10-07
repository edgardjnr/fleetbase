<?php

namespace App\Support\Entregas\Distribuicao;

use App\Jobs\Entregas\AvancarOferta;
use App\Notifications\Entregas\OfertaDePedido;
use App\Support\Entregas\StatusDoPedido;
use App\Support\Entregas\TravaDoPedido;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\OrderPing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: o ciclo da distribuição de um pedido aberto (ver Distribuicao):
 *
 * - iniciar: encerra a distribuição anterior do pedido (redespacho), cria outra em `ofertas` e avança;
 * - avancar: passado o prazo (MINUTOS_ATE_ABRIR do despacho), abre a todos, mesmo com uma oferta pendente; com uma
 *   pendente, espera; senão recalcula a fila (fora quem já respondeu neste despacho e quem tem oferta pendente de outro
 *   pedido), oferece ao primeiro (push OfertaDePedido + job AvancarOferta em SEGUNDOS_DA_OFERTA) ou, sem candidato,
 *   abre a todos;
 * - recusar / vencer: a resposta da oferta pendente e o próximo passo;
 * - abrirATodos: fase `aberta` e o OrderPing comum a todos no raio (daí em diante vale o que já existe: reenvios e o
 *   aviso "sem motoboy");
 * - encerrar e registrarAceite: só gravação, sem a trava (rodam dentro de quem já a segura: o aceite no middleware, a
 *   TrocaDoMotoboy).
 *
 * iniciar, avancar, vencer, recusar e abrirATodos rodam sob a TravaDoPedido (a mesma do aceite e do cancelamento) e
 * relêem a distribuição com ela. A trava não é reentrante: dentro dela só se chamam os métodos *SemTrava e o
 * proximoPasso, nunca os públicos que a tomam. Lançam LockTimeoutException se a trava não sair: o chamador decide (o
 * listener cai no alarme geral; o job volta à fila). Uma falha no ciclo dentro do iniciar (banco, bug) abre a
 * distribuição na hora (motivo `falha`, sem alarme) e relança: o listener manda o alarme geral e o aceite fica livre.
 * No vencer e no recusar (resposta já gravada), a falha abre a todos (falha) com o alarme geral e não relança.
 *
 * Oferta: ao primeiro da fila que ainda existe e que não ganhou a oferta de outro pedido enquanto a fila era calculada
 * (as travas são por pedido); nenhum: abre a todos (fila_esgotada).
 *
 * Pedido que já tem motoboy ou está encerrado (o Order::updated não viu: saveQuietly) não recebe oferta nem alarme: a
 * distribuição é encerrada (atribuida/cancelada), como faz a varredura.
 */
class Distribuidor
{
    public function __construct(protected FilaDeCandidatos $fila) {}

    public function iniciar(Order $pedido): void
    {
        if (!Distribuicao::ligada()) {
            return;
        }
        TravaDoPedido::executar((string) $pedido->uuid, function () use ($pedido) {
            $anterior = Distribuicoes::doPedido((string) $pedido->uuid);
            if ($anterior) {
                $this->encerrarSemTrava($anterior, Distribuicao::REDESPACHADA);
            }
            $distribuicao = Distribuicoes::criar($pedido);
            Log::info('[entregas] distribuição: iniciada', ['pedido' => $pedido->public_id, 'distribuicao' => $distribuicao->id]);
            try {
                $this->avancarSemTrava($distribuicao, $pedido);
            } catch (\Throwable $e) {
                // sem isto a distribuição ficava em `ofertas` e o aceite, restrito, até a varredura abrir pelo prazo
                $this->abrirPorFalha($distribuicao, $pedido->public_id, $e);

                throw $e; // o listener manda o alarme geral
            }
        });
    }

    public function avancar(string $pedidoUuid): void
    {
        TravaDoPedido::executar($pedidoUuid, function () use ($pedidoUuid) {
            $this->proximoPasso($pedidoUuid);
        });
    }

    /** O job (ou a varredura): a oferta pendente venceu. Oferta já respondida: nada. */
    public function vencer(int $ofertaId): void
    {
        $oferta = Distribuicoes::oferta($ofertaId);
        if (!$oferta || $oferta->resposta !== Distribuicao::PENDENTE) {
            return;
        }
        TravaDoPedido::executar((string) $oferta->pedido_uuid, function () use ($ofertaId) {
            $oferta = Distribuicoes::oferta($ofertaId); // relida com a trava: o aceite pode ter chegado antes
            if (!$oferta || $oferta->resposta !== Distribuicao::PENDENTE) {
                return;
            }
            Distribuicoes::responder($ofertaId, Distribuicao::VENCIDA);
            Log::info('[entregas] distribuição: oferta vencida', ['oferta' => $ofertaId] + $this->motoboyNoLog((string) $oferta->motoboy_uuid));
            $this->proximoPassoOuAbrir((string) $oferta->pedido_uuid); // não relança: o job não deve repetir
        });
    }

    /** O motoboy recusou. False se ele não tem oferta pendente neste pedido. */
    public function recusar(string $pedidoUuid, Driver $motoboy): bool
    {
        return TravaDoPedido::executar($pedidoUuid, function () use ($pedidoUuid, $motoboy) {
            $distribuicao = Distribuicoes::doPedido($pedidoUuid);
            $oferta       = $distribuicao ? Distribuicoes::ofertaPendente((int) $distribuicao->id) : null;
            if (!$oferta || (string) $oferta->motoboy_uuid !== (string) $motoboy->uuid) {
                return false;
            }
            Distribuicoes::responder((int) $oferta->id, Distribuicao::RECUSADA);
            Log::info('[entregas] distribuição: oferta recusada', ['oferta' => $oferta->id, 'motoboy' => $motoboy->public_id]);
            $this->proximoPassoOuAbrir($pedidoUuid);

            return true; // a recusa foi gravada, mesmo que o passo seguinte tenha falhado
        });
    }

    /** Abre a todos (central ou varredura). False se a distribuição não está em ofertas (ou o pedido já tem motoboy). */
    public function abrirATodos(string $pedidoUuid, string $motivo): bool
    {
        return TravaDoPedido::executar($pedidoUuid, function () use ($pedidoUuid, $motivo) {
            $distribuicao = Distribuicoes::doPedido($pedidoUuid);
            $pedido       = $distribuicao ? Order::where('uuid', $pedidoUuid)->first() : null;
            if (!$distribuicao || !$pedido || $distribuicao->fase !== Distribuicao::FASE_OFERTAS || $this->encerrouPeloPedido($distribuicao, $pedido)) {
                return false;
            }
            $this->abrirSemTrava($distribuicao, $pedido, $motivo);

            return true;
        });
    }

    /** Sem a trava: pedido ganhou motoboy ou foi encerrado (observador do Order). Não muda uma distribuição já encerrada. A varredura usa o encerrarDistribuicao. */
    public function encerrar(string $pedidoUuid, string $motivo): void
    {
        $distribuicao = Distribuicoes::doPedido($pedidoUuid);
        if ($distribuicao) {
            $this->encerrarSemTrava($distribuicao, $motivo);
        }
    }

    /** Como o encerrar, mas a distribuição exata (usado pela varredura): relê por id e só encerra se não estiver encerrada. Sem a trava. */
    public function encerrarDistribuicao(int $id, string $motivo): void
    {
        $distribuicao = DB::table(Distribuicoes::TABELA)->where('id', $id)->first();
        if ($distribuicao) {
            $this->encerrarSemTrava($distribuicao, $motivo);
        }
    }

    /** A regra única do pedido que não precisa mais de distribuição: sumido ou encerrado = cancelada; com motoboy = atribuida. */
    public function motivoParaEncerrar(?object $pedido): ?string
    {
        return match (true) {
            !$pedido                                                                         => Distribuicao::CANCELADA,
            in_array(strtolower((string) $pedido->status), StatusDoPedido::ENCERRADOS, true) => Distribuicao::CANCELADA,
            (bool) $pedido->driver_assigned_uuid                                             => Distribuicao::ATRIBUIDA,
            default                                                                          => null,
        };
    }

    /** Sem a trava (o middleware do aceite já a segura): a oferta foi aceita. */
    public function registrarAceite(object $oferta): void
    {
        Distribuicoes::responder((int) $oferta->id, Distribuicao::ACEITA);
        Distribuicoes::cancelarPendentes((int) $oferta->distribuicao_id);
        Distribuicoes::mudarFase((int) $oferta->distribuicao_id, Distribuicao::FASE_ENCERRADA, Distribuicao::ACEITA);
        Log::info('[entregas] distribuição: encerrada (aceita)', ['oferta' => $oferta->id] + $this->motoboyNoLog((string) $oferta->motoboy_uuid));
    }

    /** Relê a distribuição e o pedido e avança. Só de dentro da trava. */
    protected function proximoPasso(string $pedidoUuid): void
    {
        $distribuicao = Distribuicoes::doPedido($pedidoUuid);
        $pedido       = $distribuicao ? Order::where('uuid', $pedidoUuid)->first() : null;
        if ($distribuicao && $pedido) {
            $this->avancarSemTrava($distribuicao, $pedido);
        }
    }

    /**
     * O próximo passo depois de uma resposta já gravada (recusa, vencimento). Uma falha (banco, bug) não pode deixar a
     * distribuição em `ofertas` sem pendente (ninguém aceitaria até a varredura): abre a todos (falha) com o alarme
     * geral e não relança.
     */
    protected function proximoPassoOuAbrir(string $pedidoUuid): void
    {
        try {
            $this->proximoPasso($pedidoUuid);
        } catch (\Throwable $erro) {
            $this->abrirAposFalha($pedidoUuid, $erro);
        }
    }

    protected function abrirAposFalha(string $pedidoUuid, \Throwable $erro): void
    {
        $distribuicao = null;
        $pedido       = null;
        try {
            $distribuicao = Distribuicoes::doPedido($pedidoUuid);
            $pedido       = $distribuicao ? Order::where('uuid', $pedidoUuid)->first() : null;
        } catch (\Throwable $e) {
            // segue com o que deu para ler
        }
        if (!$distribuicao) {
            Log::warning('[entregas] distribuição: falha no ciclo; não foi possível abrir a distribuição', ['erro' => get_class($erro)]);

            return;
        }
        if ($distribuicao->fase !== Distribuicao::FASE_OFERTAS) {
            // já saiu de ofertas antes da falha (ex.: abriu e o alarme geral falhou): o aceite já é livre
            Log::warning('[entregas] distribuição: falha no ciclo', ['distribuicao' => $distribuicao->id, 'fase' => $distribuicao->fase, 'erro' => get_class($erro)]);

            return;
        }
        if ($pedido) {
            try {
                $this->abrirSemTrava($distribuicao, $pedido, Distribuicao::FALHA);
                Log::warning('[entregas] distribuição: falha no ciclo; aberta a todos', ['pedido' => $pedido->public_id, 'distribuicao' => $distribuicao->id, 'erro' => get_class($erro)]);

                return;
            } catch (\Throwable $e) {
                // o alarme também falhou: ao menos abre (abaixo)
            }
        }
        $this->abrirPorFalha($distribuicao, $pedido?->public_id, $erro);
    }

    protected function avancarSemTrava(object $distribuicao, Order $pedido): void
    {
        if ($distribuicao->fase !== Distribuicao::FASE_OFERTAS || $this->encerrouPeloPedido($distribuicao, $pedido)) {
            return;
        }
        // o prazo vale antes da pendente: aos MINUTOS_ATE_ABRIR abre a todos, cancelando a oferta que ainda corre
        $despachada = Distribuicoes::data($distribuicao->despachada_em);
        if ($despachada && now() >= $despachada->addMinutes(Distribuicao::MINUTOS_ATE_ABRIR)) {
            $this->abrirSemTrava($distribuicao, $pedido, Distribuicao::PRAZO);

            return;
        }
        if (Distribuicoes::ofertaPendente((int) $distribuicao->id)) {
            return; // alguém ainda está decidindo
        }

        $excluidos = array_merge(Distribuicoes::motoboysQueResponderam((int) $distribuicao->id), Distribuicoes::motoboysComOfertaPendente());
        $fila      = $this->fila->para($pedido, Candidatos::elegiveis($pedido, $excluidos));
        if ($fila === []) {
            // vazia não apaga a última fila gravada (o painel a mostra)
            $jaOfereceu = Distribuicoes::ofertas((int) $distribuicao->id) !== [];
            $this->abrirSemTrava($distribuicao, $pedido, $jaOfereceu ? Distribuicao::FILA_ESGOTADA : Distribuicao::SEM_CANDIDATO);

            return;
        }
        Distribuicoes::gravarFila((int) $distribuicao->id, $fila);

        // o primeiro da fila que ainda existe e que não ganhou, nesse meio-tempo, a oferta de outro pedido (outra trava)
        $ocupados = Distribuicoes::motoboysComOfertaPendente();
        $primeiro = null;
        $motoboy  = null;
        foreach ($fila as $candidato) {
            if (in_array($candidato['motoboy_uuid'], $ocupados, true)) {
                continue;
            }
            $motoboy = Driver::where('uuid', $candidato['motoboy_uuid'])->first();
            if ($motoboy) {
                $primeiro = $candidato;
                break;
            }
        }
        if (!$primeiro) {
            $this->abrirSemTrava($distribuicao, $pedido, Distribuicao::FILA_ESGOTADA);

            return;
        }
        $posicao = count(Distribuicoes::ofertas((int) $distribuicao->id)) + 1;
        $oferta  = Distribuicoes::criarOferta($distribuicao, $primeiro, $posicao);
        try {
            AvancarOferta::agendar((int) $oferta->id);
        } catch (\Throwable $e) {
            // a fila fora do ar: a varredura vence a oferta (FOLGA_DA_VARREDURA_S depois do vence_em)
            Log::warning('[entregas] distribuição: job da oferta não entrou na fila', ['oferta' => $oferta->id, 'erro' => get_class($e)]);
        }
        try {
            $motoboy->notify(new OfertaDePedido($pedido, $primeiro['distancia_m'], Distribuicoes::data($oferta->vence_em)));
        } catch (\Throwable $e) {
            // o envio para a fila falhou (Redis); a oferta vence sozinha (o FCM roda no worker)
            Log::warning('[entregas] distribuição: push da oferta falhou', ['oferta' => $oferta->id, 'erro' => get_class($e)]);
        }
        Log::info('[entregas] distribuição: oferta enviada', ['pedido' => $pedido->public_id, 'oferta' => $oferta->id, 'motoboy' => $motoboy->public_id, 'posicao' => $posicao, 'tempo_s' => $primeiro['tempo_s'], 'encaixe' => $primeiro['encaixe']]);
    }

    protected function abrirSemTrava(object $distribuicao, Order $pedido, string $motivo): void
    {
        Distribuicoes::cancelarPendentes((int) $distribuicao->id);
        Distribuicoes::mudarFase((int) $distribuicao->id, Distribuicao::FASE_ABERTA, $motivo);
        Log::info('[entregas] distribuição: aberta a todos (' . $motivo . ')', ['pedido' => $pedido->public_id, 'distribuicao' => $distribuicao->id]);

        foreach (Candidatos::noRaio($pedido, false) as $candidato) {
            try {
                $candidato['motoboy']->notify(new OrderPing($pedido, $candidato['distancia']));
            } catch (\Throwable $e) {
                Log::warning('[entregas] distribuição: alarme geral falhou para um motoboy', ['motoboy' => $candidato['motoboy']->public_id, 'erro' => get_class($e)]);
            }
        }
    }

    protected function encerrarSemTrava(object $distribuicao, string $motivo): void
    {
        if ($distribuicao->fase === Distribuicao::FASE_ENCERRADA) {
            return;
        }
        Distribuicoes::cancelarPendentes((int) $distribuicao->id);
        Distribuicoes::mudarFase((int) $distribuicao->id, Distribuicao::FASE_ENCERRADA, $motivo);
        Log::info('[entregas] distribuição: encerrada (' . $motivo . ')', ['pedido' => $distribuicao->pedido_uuid, 'distribuicao' => $distribuicao->id]);
    }

    /** O ciclo falhou no iniciar: aberta (falha) sem alarme, que fica com o listener. Falha aqui também só registra. */
    protected function abrirPorFalha(object $distribuicao, ?string $pedidoPublicId, \Throwable $erro): void
    {
        try {
            Distribuicoes::cancelarPendentes((int) $distribuicao->id);
            Distribuicoes::mudarFase((int) $distribuicao->id, Distribuicao::FASE_ABERTA, Distribuicao::FALHA);
            Log::warning('[entregas] distribuição: falha no ciclo; aberta a todos', ['pedido' => $pedidoPublicId, 'distribuicao' => $distribuicao->id, 'erro' => get_class($erro)]);
        } catch (\Throwable $e) {
            Log::warning('[entregas] distribuição: falha no ciclo; não foi possível abrir a distribuição', ['pedido' => $pedidoPublicId, 'distribuicao' => $distribuicao->id, 'erro' => get_class($erro), 'erro_ao_abrir' => get_class($e)]);
        }
    }

    /** O motoboy no log: o public_id (como nos outros logs) ou, se não der para ler, o uuid com a chave explícita. */
    protected function motoboyNoLog(string $uuid): array
    {
        try {
            $publicId = Driver::where('uuid', $uuid)->first()?->public_id;
        } catch (\Throwable $e) {
            $publicId = null;
        }

        return $publicId ? ['motoboy' => $publicId] : ['motoboy_uuid' => $uuid];
    }

    /** Pedido já com motoboy ou encerrado: encerra a distribuição (atribuida/cancelada) e devolve true. */
    protected function encerrouPeloPedido(object $distribuicao, Order $pedido): bool
    {
        $motivo = $this->motivoParaEncerrar($pedido);
        if (!$motivo) {
            return false;
        }
        $this->encerrarSemTrava($distribuicao, $motivo);

        return true;
    }
}
