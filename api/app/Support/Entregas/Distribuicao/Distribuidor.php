<?php

namespace App\Support\Entregas\Distribuicao;

use App\Jobs\Entregas\AvancarDistribuicao;
use App\Jobs\Entregas\AvancarOferta;
use App\Notifications\Entregas\OfertaDePedido;
use App\Support\Entregas\StatusDoPedido;
use App\Support\Entregas\TravaDoPedido;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\OrderPing;
use Illuminate\Support\Facades\Cache;
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
 * - encerrar, registrarAceite e oferecerAoEscolhidoSemTrava: sem a trava (rodam dentro de quem já a segura: o aceite no
 *   middleware, a TrocaDoMotoboy).
 *
 * iniciar, avancar, avancarOuAbrir, vencer, recusar, recusarOuDispensar, abrirATodos e mostrarATodos rodam sob a
 * TravaDoPedido (a mesma do aceite e do cancelamento) e relêem a distribuição com ela. A trava não é reentrante: dentro
 * dela só se chamam os métodos *SemTrava e o proximoPasso/proximoPassoOuAbrir, nunca os públicos que a tomam. Lançam LockTimeoutException se a trava não sair: o chamador decide (o
 * listener cai no alarme geral; o job volta à fila). Uma falha no ciclo dentro do iniciar (banco, bug) abre a
 * distribuição na hora (motivo `falha`, sem alarme) e relança: o listener manda o alarme geral e o aceite fica livre.
 * No vencer e no recusar (resposta já gravada), a falha abre a todos (falha) com o alarme geral e não relança.
 *
 * Oferta: ao primeiro da fila que ainda existe e que não ganhou a oferta de outro pedido enquanto a fila era calculada
 * (as travas são por pedido); nenhum: abre a todos (fila_esgotada).
 *
 * Em rodadas (Distribuicao::emRodadas), o avancarSemTrava segue o avancarEmRodadas: oferta de 20 s, rodadas R, 1,5R e
 * 2R, voltas até alguém aceitar e nunca o alarme a todos (sem prazo de 3 min; só a falha e o pedido sem coordenada
 * válida ainda abrem com o alarme geral). Passada 1 h do despacho (MINUTOS_ATE_PARAR_DE_TOCAR), não oferece mais: o
 * pedido fica só na lista aberta (soNaLista).
 * recusarOuDispensar, mostrarATodos e registrarAceitePelaLista são do ciclo em rodadas (com as rodadas desligadas, o
 * mostrarATodos devolve fora_de_ofertas sem tocar em nada e o recusarOuDispensar só recusa).
 *
 * Pedido que já tem motoboy, está encerrado ou deixou de ser aberto sem motoboy (o Order::updated não viu: saveQuietly)
 * não recebe oferta nem alarme: a distribuição é encerrada (atribuida/cancelada), como faz a varredura.
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

    /**
     * Como o avancar, mas uma falha no passo abre a todos (falha) com o alarme geral, como depois de uma resposta. Usado
     * pelo job AvancarDistribuicao e pela varredura em rodadas. Lança LockTimeoutException se a trava não sair.
     */
    public function avancarOuAbrir(string $pedidoUuid): void
    {
        TravaDoPedido::executar($pedidoUuid, function () use ($pedidoUuid) {
            $this->proximoPassoOuAbrir($pedidoUuid);
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
        return TravaDoPedido::executar($pedidoUuid, fn () => $this->recusarSemTrava($pedidoUuid, $motoboy));
    }

    /**
     * Recusar e Dispensar (POST v1/entregas/motoboy/pedidos/{id}/recusar): com a oferta pendente dele, recusa (RECUSADA);
     * senão, em rodadas (lista aberta ou fechada), grava a linha `dispensada` na volta atual (DISPENSADA: some da lista
     * dele e não recebe oferta até a volta seguinte; uma linha por volta). Null: nada com ele (a rota responde 409).
     */
    public function recusarOuDispensar(string $pedidoUuid, Driver $motoboy): ?string
    {
        return TravaDoPedido::executar($pedidoUuid, function () use ($pedidoUuid, $motoboy) {
            if ($this->recusarSemTrava($pedidoUuid, $motoboy)) {
                return Distribuicao::RECUSADA;
            }

            return $this->dispensarSemTrava($pedidoUuid, $motoboy) ? Distribuicao::DISPENSADA : null;
        });
    }

    /**
     * "Mostrar a todos agora" (console, em rodadas): abre a lista na hora, sem alarme geral; o ciclo segue.
     * LISTA_ABERTA_AGORA, LISTA_JA_ABERTA ou FORA_DE_OFERTAS (fora de ofertas, pedido com motoboy, encerrado ou apagado).
     * Rodadas desligadas: FORA_DE_OFERTAS sem tocar em nada (a lista aberta só existe em rodadas).
     */
    public function mostrarATodos(string $pedidoUuid): string
    {
        if (!Distribuicao::emRodadas()) {
            return Distribuicao::FORA_DE_OFERTAS;
        }

        return TravaDoPedido::executar($pedidoUuid, function () use ($pedidoUuid) {
            $distribuicao = Distribuicoes::doPedido($pedidoUuid);
            $pedido       = $distribuicao ? Order::where('uuid', $pedidoUuid)->first() : null;
            if ($distribuicao && !$pedido) {
                $this->encerrarSemTrava($distribuicao, Distribuicao::CANCELADA);

                return Distribuicao::FORA_DE_OFERTAS;
            }
            if (!$distribuicao || $distribuicao->fase !== Distribuicao::FASE_OFERTAS || $this->encerrouPeloPedido($distribuicao, $pedido)) {
                return Distribuicao::FORA_DE_OFERTAS;
            }
            if (!Distribuicoes::abrirLista((int) $distribuicao->id)) {
                return Distribuicao::LISTA_JA_ABERTA;
            }
            Log::info('[entregas] distribuição: lista aberta', ['pedido' => $pedido->public_id, 'distribuicao' => $distribuicao->id, 'pela_central' => true]);

            return Distribuicao::LISTA_ABERTA_AGORA;
        });
    }

    /** Sem a trava (o middleware do aceite já a segura): aceite pela lista aberta de quem não tinha a oferta. */
    public function registrarAceitePelaLista(object $distribuicao, ?string $motoboyUuid): void
    {
        $id = (int) $distribuicao->id;
        Distribuicoes::cancelarPendentes($id);
        if ($motoboyUuid) {
            Distribuicoes::registrarResposta($distribuicao, $motoboyUuid, Distribuicao::ACEITA_PELA_LISTA);
        }
        Distribuicoes::mudarFase($id, Distribuicao::FASE_ENCERRADA, Distribuicao::ACEITA);
        Log::info('[entregas] distribuição: aceita pela lista', ['distribuicao' => $id] + ($motoboyUuid ? $this->motoboyNoLog($motoboyUuid) : []));
    }

    /** Só de dentro da trava: a recusa da oferta pendente dele e o próximo passo. False se ele não tem oferta pendente. */
    protected function recusarSemTrava(string $pedidoUuid, Driver $motoboy): bool
    {
        $distribuicao = Distribuicoes::doPedido($pedidoUuid);
        $oferta       = $distribuicao ? Distribuicoes::ofertaPendente((int) $distribuicao->id) : null;
        if (!$oferta || (string) $oferta->motoboy_uuid !== (string) $motoboy->uuid) {
            return false;
        }
        Distribuicoes::responder((int) $oferta->id, Distribuicao::RECUSADA);
        Log::info('[entregas] distribuição: oferta recusada', ['oferta' => $oferta->id, 'motoboy' => $motoboy->public_id]);
        $this->proximoPassoOuAbrir($pedidoUuid);

        return true; // a recusa foi gravada, mesmo que o passo seguinte tenha falhado
    }

    /**
     * Só de dentro da trava: em rodadas, com a distribuição em ofertas e o pedido ainda aberto e sem motoboy, grava a
     * linha `dispensada` na volta (se ainda não recusou nem dispensou nela), com a lista aberta ou fechada: com ela
     * fechada (rodada 1 da volta 1, ex.: a oferta dele venceu e ele tocou Recusar), o 409 faria o APK escondê-lo até
     * reiniciar, e a spec quer que ele volte na volta seguinte. False se não cabe dispensa.
     */
    protected function dispensarSemTrava(string $pedidoUuid, Driver $motoboy): bool
    {
        if (!Distribuicao::emRodadas()) {
            return false;
        }
        $distribuicao = Distribuicoes::doPedido($pedidoUuid);
        if (!$distribuicao || $distribuicao->fase !== Distribuicao::FASE_OFERTAS) {
            return false;
        }
        $pedido = Order::where('uuid', $pedidoUuid)->first();
        if (!$pedido || $this->motivoParaEncerrar($pedido)) {
            return false;
        }
        $volta = (int) ($distribuicao->volta ?? 1);
        if (!in_array((string) $motoboy->uuid, Distribuicoes::motoboysQueDispensaramNaVolta((int) $distribuicao->id, $volta), true)) {
            $raio = Distribuicao::raioDaRodada(Distribuicao::raioBase($pedido), (int) ($distribuicao->rodada ?? 1));
            Distribuicoes::registrarResposta($distribuicao, (string) $motoboy->uuid, Distribuicao::DISPENSADA, $raio);
            Log::info('[entregas] distribuição: oferta dispensada', ['pedido' => $pedido->public_id, 'distribuicao' => $distribuicao->id, 'motoboy' => $motoboy->public_id, 'volta' => $volta]);
        }

        return true;
    }

    /** Abre a todos (central ou varredura). False se a distribuição não está em ofertas (ou o pedido já tem motoboy). */
    public function abrirATodos(string $pedidoUuid, string $motivo): bool
    {
        return TravaDoPedido::executar($pedidoUuid, function () use ($pedidoUuid, $motivo) {
            $distribuicao = Distribuicoes::doPedido($pedidoUuid);
            $pedido       = $distribuicao ? Order::where('uuid', $pedidoUuid)->first() : null;
            if ($distribuicao && !$pedido) {
                // pedido apagado: encerra (cancelada), senão a varredura tenta abrir pelo prazo para sempre
                $this->encerrarSemTrava($distribuicao, Distribuicao::CANCELADA);

                return false;
            }
            if (!$distribuicao || $distribuicao->fase !== Distribuicao::FASE_OFERTAS || $this->encerrouPeloPedido($distribuicao, $pedido)) {
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

    /**
     * A regra única do pedido que não precisa mais de distribuição: sumido ou encerrado = cancelada; com motoboy =
     * atribuida; sem motoboy e que deixou de ser aberto (a central desligou o adhoc sem atribuir) = cancelada. Sem a
     * última, o pedido seguiria recebendo ofertas (em rodadas, sem fim) e o Aceitar levaria o 400 do Fleet-Ops.
     */
    public function motivoParaEncerrar(?object $pedido): ?string
    {
        return match (true) {
            !$pedido                                                                         => Distribuicao::CANCELADA,
            in_array(strtolower((string) $pedido->status), StatusDoPedido::ENCERRADOS, true) => Distribuicao::CANCELADA,
            (bool) $pedido->driver_assigned_uuid                                             => Distribuicao::ATRIBUIDA,
            !$pedido->adhoc                                                                  => Distribuicao::CANCELADA,
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

    /**
     * Sem a trava (a TrocaDoMotoboy já a segura): a troca ou a atribuição pela central ou pelo líder antes da coleta.
     * Encerra a distribuição em curso do pedido (redespachada), cria outra e oferece primeiro ao motoboy escolhido (volta
     * 1, rodada 1, lista fechada), mesmo fora do raio, ocupado ou com o GPS velho: quem escolheu foi a central ou o líder.
     * Recusou ou venceu: o ciclo de sempre (com ou sem rodadas); o escolhido fica fora da volta 1 por já ter linha nela.
     * Nunca lança: uma falha abre a todos (falha) com o alarme geral; antes de a distribuição nova existir, o alarme geral
     * sai mesmo sem distribuição (o pedido já voltou à fila). A falha só na estimativa não derruba a oferta: tempo 0,
     * aproximado. Devolve a oferta, ou null (desligada ou falha).
     */
    public function oferecerAoEscolhidoSemTrava(Order $pedido, Driver $motoboy): ?object
    {
        if (!Distribuicao::ligada()) {
            return null;
        }
        $distribuicao = null;
        try {
            $anterior = Distribuicoes::doPedido((string) $pedido->uuid);
            if ($anterior) {
                $this->encerrarSemTrava($anterior, Distribuicao::REDESPACHADA);
            }
            $distribuicao = Distribuicoes::criar($pedido);
            Log::info('[entregas] distribuição: iniciada', ['pedido' => $pedido->public_id, 'distribuicao' => $distribuicao->id, 'escolhido' => $motoboy->public_id]);
            $linha = $this->linhaDoEscolhido($pedido, $motoboy);
            Distribuicoes::gravarFila((int) $distribuicao->id, [$linha]);
            $raio   = Distribuicao::emRodadas() ? Distribuicao::raioDaRodada(Distribuicao::raioBase($pedido), 1) : null;
            $oferta = Distribuicoes::criarOferta($distribuicao, $linha, 1, 1, 1, $raio);
            try {
                AvancarOferta::agendar((int) $oferta->id);
            } catch (\Throwable $e) {
                // a fila fora do ar: a varredura vence a oferta (FOLGA_DA_VARREDURA_S depois do vence_em)
                Log::warning('[entregas] distribuição: job da oferta não entrou na fila', ['oferta' => $oferta->id, 'erro' => get_class($e)]);
            }
            try {
                $motoboy->notify(new OfertaDePedido($pedido, $linha['distancia_m'], Distribuicoes::data($oferta->vence_em), Distribuicao::segundosDaOferta()));
            } catch (\Throwable $e) {
                Log::warning('[entregas] distribuição: push da oferta falhou', ['oferta' => $oferta->id, 'erro' => get_class($e)]);
            }
            Log::info('[entregas] distribuição: oferta ao escolhido enviada', ['pedido' => $pedido->public_id, 'oferta' => $oferta->id, 'motoboy' => $motoboy->public_id, 'tempo_s' => $linha['tempo_s']]);

            return $oferta;
        } catch (\Throwable $erro) {
            if (!$distribuicao) {
                // a TrocaDoMotoboy já devolveu o pedido à fila: ele não pode ficar aberto e mudo
                Log::warning('[entregas] distribuição: falha na oferta ao escolhido', ['pedido' => $pedido->public_id, 'erro' => get_class($erro)]);
                $this->abrirAposFalha((string) $pedido->uuid, $erro); // a que ficou em ofertas: abre com o alarme geral
                try {
                    $emCurso = Distribuicoes::doPedido((string) $pedido->uuid);
                } catch (\Throwable $e) {
                    $emCurso = null; // sem como ler: o alarme geral, por segurança
                }
                if (!$emCurso) {
                    try {
                        $this->alarmeGeral($pedido);
                    } catch (\Throwable $e) {
                        Log::warning('[entregas] distribuição: alarme geral da oferta ao escolhido falhou', ['pedido' => $pedido->public_id, 'erro' => get_class($e)]);
                    }
                }

                return null;
            }
            try {
                $this->abrirSemTrava($distribuicao, $pedido, Distribuicao::FALHA);
                Log::warning('[entregas] distribuição: falha na oferta ao escolhido; aberta a todos', ['pedido' => $pedido->public_id, 'distribuicao' => $distribuicao->id, 'erro' => get_class($erro)]);
            } catch (\Throwable $e) {
                $this->abrirPorFalha($distribuicao, $pedido->public_id, $erro);
            }

            return null;
        }
    }

    /**
     * A linha da fila do escolhido: com posição (e coleta com coordenada), o tempo até o cliente pela FilaDeCandidatos, com
     * as paradas dele e o encaixe do ciclo; sem posição, tempo 0 e aproximado (a oferta sai assim mesmo).
     */
    protected function linhaDoEscolhido(Order $pedido, Driver $motoboy): array
    {
        $posicao = Pontos::de($motoboy->location);
        $coleta  = Pontos::de($pedido->payload?->pickup?->location);
        if ($posicao && $coleta) {
            try {
                $uuid      = (string) $motoboy->uuid;
                $empresa   = (string) $pedido->company_uuid;
                $candidato = [
                    'motoboy'       => $motoboy,
                    'posicao'       => $posicao,
                    'distancia'     => Pontos::metros($posicao, $coleta),
                    'paradas'       => Candidatos::paradas($empresa, [$uuid])[$uuid] ?? [],
                    'corridas_hoje' => Candidatos::corridasHoje($empresa, [$uuid])[$uuid] ?? 0,
                ];
                $atraso = Distribuicao::emRodadas() ? Distribuicao::ATRASO_MAXIMO_EM_RODADAS_S : Distribuicao::ATRASO_MAXIMO_S;
                $fila   = $this->fila->para($pedido, [$candidato], $atraso);
                if ($fila !== []) {
                    return $fila[0];
                }
            } catch (\Throwable $e) {
                // a estimativa não derruba a oferta que a central ou o líder escolheu
                Log::warning('[entregas] distribuição: estimativa da oferta ao escolhido falhou; tempo aproximado', ['pedido' => $pedido->public_id, 'motoboy' => $motoboy->public_id, 'erro' => get_class($e)]);
            }
        }

        return [
            'motoboy_uuid'  => (string) $motoboy->uuid,
            'public_id'     => (string) $motoboy->public_id,
            'nome'          => (string) $motoboy->name,
            'tempo_s'       => 0,
            'encaixe'       => false,
            'aproximado'    => true,
            'livre'         => false, // sem a estimativa, não se sabe se ele está livre (o painel não diz "livre")
            'distancia_m'   => 0,
            'corridas_hoje' => 0,
            'desempate'     => false,
        ];
    }

    /** Relê a distribuição e o pedido e avança. Só de dentro da trava. */
    protected function proximoPasso(string $pedidoUuid): void
    {
        $distribuicao = Distribuicoes::doPedido($pedidoUuid);
        $pedido       = $distribuicao ? Order::where('uuid', $pedidoUuid)->first() : null;
        if ($distribuicao && !$pedido) {
            // pedido apagado (avancar, vencer, recusar): encerra (cancelada), em vez de ficar em ofertas sem pendente
            $this->encerrarSemTrava($distribuicao, Distribuicao::CANCELADA);

            return;
        }
        if ($distribuicao) {
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
        if (Distribuicao::emRodadas()) {
            $this->avancarEmRodadas($distribuicao, $pedido);

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
        if (!$this->oferecerAoPrimeiro($distribuicao, $pedido, $fila, 1, 1, null)) {
            $this->abrirSemTrava($distribuicao, $pedido, Distribuicao::FILA_ESGOTADA);
        }
    }

    /**
     * Um passo do ciclo em rodadas, já sob a trava e com a distribuição em `ofertas`: com uma oferta pendente, espera;
     * senão oferece ao primeiro da rodada atual. Rodada vazia passa à seguinte (sair da rodada 1 da volta 1 abre a
     * lista). Depois da rodada 3: volta nova (rodada 1, todos de novo) se já passou SEGUNDOS_ENTRE_VOLTAS do início da
     * volta; senão agenda o próximo passo (AvancarDistribuicao) e para. No máximo uma volta nova por passo: uma volta
     * inteira sem ninguém para e espera a varredura. Nunca abre a todos, exceto o pedido sem coordenada válida de coleta
     * ou de entrega: a fila dele sai sempre vazia e ele ficaria mudo, então abre a todos (sem_candidato), como hoje.
     */
    protected function avancarEmRodadas(object $distribuicao, Order $pedido): void
    {
        $id = (int) $distribuicao->id;
        if (!Pontos::de($pedido->payload?->pickup?->location) || !Pontos::de($pedido->payload?->dropoff?->location)) {
            Log::warning('[entregas] distribuição: pedido sem coordenada; aberta a todos', ['pedido' => $pedido->public_id, 'distribuicao' => $id]);
            $this->abrirSemTrava($distribuicao, $pedido, Distribuicao::SEM_CANDIDATO);

            return;
        }
        if (Distribuicoes::ofertaPendente($id)) {
            return; // alguém ainda está decidindo
        }
        if ($this->passouDoLimiteDeTocar($distribuicao)) {
            $this->soNaLista($distribuicao, $pedido);

            return;
        }
        $raioBase  = Distribuicao::raioBase($pedido);
        $volta     = max(1, (int) ($distribuicao->volta ?? 1));
        $rodada    = max(1, min(Distribuicao::ULTIMA_RODADA, (int) ($distribuicao->rodada ?? 1)));
        $voltaNova = false;

        while (true) {
            if ($this->oferecerNaRodada($distribuicao, $pedido, $volta, $rodada, Distribuicao::raioDaRodada($raioBase, $rodada))) {
                return;
            }
            if ($rodada < Distribuicao::ULTIMA_RODADA) {
                if ($volta === 1 && $rodada === 1 && Distribuicoes::abrirLista($id)) {
                    Log::info('[entregas] distribuição: lista aberta', ['pedido' => $pedido->public_id, 'distribuicao' => $id]);
                }
                $rodada++;
                Distribuicoes::irParaRodada($id, $rodada);
                Log::info('[entregas] distribuição: rodada ' . $rodada . ' (raio ' . Distribuicao::raioDaRodada($raioBase, $rodada) . ' m)', ['pedido' => $pedido->public_id, 'distribuicao' => $id, 'volta' => $volta]);

                continue;
            }
            if ($voltaNova) {
                // a volta inteira sem ninguém: para (a varredura tenta de novo a cada minuto)
                Distribuicoes::tocar($id);
                Log::info('[entregas] distribuição: aguardando motoboy', ['pedido' => $pedido->public_id, 'distribuicao' => $id, 'volta' => $volta]);

                return;
            }
            $inicio = Distribuicoes::data($distribuicao->volta_iniciada_em ?? null) ?? Distribuicoes::data($distribuicao->despachada_em);
            $falta  = $inicio ? $inicio->getTimestamp() + Distribuicao::SEGUNDOS_ENTRE_VOLTAS - now()->getTimestamp() : 0;
            if ($falta > 0) {
                Distribuicoes::tocar($id);
                try {
                    AvancarDistribuicao::agendar((string) $pedido->uuid, $falta);
                } catch (\Throwable $e) {
                    // a fila fora do ar: a varredura avança (SEGUNDOS_PARADA depois do updated_at)
                    Log::warning('[entregas] distribuição: job da próxima volta não entrou na fila', ['distribuicao' => $id, 'erro' => get_class($e)]);
                }

                return;
            }
            $volta++;
            $rodada    = 1;
            $voltaNova = true;
            Distribuicoes::novaVolta($id, $volta);
            Log::info('[entregas] distribuição: volta ' . $volta, ['pedido' => $pedido->public_id, 'distribuicao' => $id]);
        }
    }

    /** Em rodadas: passou MINUTOS_ATE_PARAR_DE_TOCAR do despacho (sem despachada_em, sem limite). */
    protected function passouDoLimiteDeTocar(object $distribuicao): bool
    {
        $despachada = Distribuicoes::data($distribuicao->despachada_em ?? null);

        return $despachada && now() >= $despachada->addMinutes(Distribuicao::MINUTOS_ATE_PARAR_DE_TOCAR);
    }

    /**
     * Passado o limite de 1 h: ninguém mais recebe oferta (nem agenda o próximo passo); a distribuição segue em
     * `ofertas` com a lista aberta (abre agora, se ainda estava fechada) e o aceite pela lista vale. O log sai uma vez
     * por distribuição (marca no cache; se o cache falhar, loga de novo, nunca derruba o passo).
     */
    protected function soNaLista(object $distribuicao, Order $pedido): void
    {
        $id = (int) $distribuicao->id;
        Distribuicoes::abrirLista($id);
        $chave = 'entregas:distribuicao-parou-de-tocar:' . $id;
        try {
            if (Cache::get($chave)) {
                return;
            }
            Cache::put($chave, true, 86400);
        } catch (\Throwable $e) {
            // sem o cache: o log pode repetir
        }
        Log::info('[entregas] distribuição: limite de 1 h; só na lista', ['pedido' => $pedido->public_id, 'distribuicao' => $id]);
    }

    /**
     * A oferta da rodada: os disponíveis até o raio, fora quem tem qualquer linha nesta volta e quem tem oferta pendente
     * de outro pedido, pelo tempo até o cliente novo com o encaixe de até 5 min de atraso para quem já espera
     * (ATRASO_MAXIMO_EM_RODADAS_S; nenhuma ordem cabe: "termina tudo e depois vai"). False se ninguém recebeu.
     */
    protected function oferecerNaRodada(object $distribuicao, Order $pedido, int $volta, int $rodada, int $raio): bool
    {
        $id        = (int) $distribuicao->id;
        $excluidos = array_merge(Distribuicoes::motoboysDaVolta($id, $volta), Distribuicoes::motoboysComOfertaPendente());
        $fila      = $this->fila->para($pedido, Candidatos::elegiveis($pedido, $excluidos, $raio), Distribuicao::ATRASO_MAXIMO_EM_RODADAS_S);
        if ($fila === []) {
            return false; // vazia não apaga a última fila gravada (o painel a mostra)
        }
        Distribuicoes::gravarFila($id, $fila);

        return $this->oferecerAoPrimeiro($distribuicao, $pedido, $fila, $volta, $rodada, $raio);
    }

    /**
     * Oferece ao primeiro da fila que ainda existe e que não ganhou, nesse meio-tempo, a oferta de outro pedido (outra
     * trava): grava a oferta, agenda o AvancarOferta e manda o push. False se ninguém da fila serviu.
     */
    protected function oferecerAoPrimeiro(object $distribuicao, Order $pedido, array $fila, int $volta, int $rodada, ?int $raio): bool
    {
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
            return false;
        }
        $posicao = Distribuicoes::proximaPosicao((int) $distribuicao->id);
        $oferta  = Distribuicoes::criarOferta($distribuicao, $primeiro, $posicao, $volta, $rodada, $raio);
        try {
            AvancarOferta::agendar((int) $oferta->id);
        } catch (\Throwable $e) {
            // a fila fora do ar: a varredura vence a oferta (FOLGA_DA_VARREDURA_S depois do vence_em)
            Log::warning('[entregas] distribuição: job da oferta não entrou na fila', ['oferta' => $oferta->id, 'erro' => get_class($e)]);
        }
        try {
            $motoboy->notify(new OfertaDePedido($pedido, $primeiro['distancia_m'], Distribuicoes::data($oferta->vence_em), Distribuicao::segundosDaOferta()));
        } catch (\Throwable $e) {
            // o envio para a fila falhou (Redis); a oferta vence sozinha (o FCM roda no worker)
            Log::warning('[entregas] distribuição: push da oferta falhou', ['oferta' => $oferta->id, 'erro' => get_class($e)]);
        }
        Log::info('[entregas] distribuição: oferta enviada', ['pedido' => $pedido->public_id, 'oferta' => $oferta->id, 'motoboy' => $motoboy->public_id, 'posicao' => $posicao, 'tempo_s' => $primeiro['tempo_s'], 'encaixe' => $primeiro['encaixe'], 'volta' => $volta, 'rodada' => $rodada, 'corridas_hoje' => $primeiro['corridas_hoje'] ?? 0, 'desempate' => $primeiro['desempate'] ?? false]);

        return true;
    }

    protected function abrirSemTrava(object $distribuicao, Order $pedido, string $motivo): void
    {
        Distribuicoes::cancelarPendentes((int) $distribuicao->id);
        Distribuicoes::mudarFase((int) $distribuicao->id, Distribuicao::FASE_ABERTA, $motivo);
        Log::info('[entregas] distribuição: aberta a todos (' . $motivo . ')', ['pedido' => $pedido->public_id, 'distribuicao' => $distribuicao->id]);
        $this->alarmeGeral($pedido);
    }

    /** O OrderPing comum a todos no raio (a falha no envio a um motoboy só registra; a da busca lança). */
    protected function alarmeGeral(Order $pedido): void
    {
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
