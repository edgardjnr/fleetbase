<?php

namespace App\Support\Entregas\Distribuicao;

/**
 * Entregas RestaurantePro: distribuição de pedidos abertos. Em vez do alarme a todos os motoboys do raio
 * (HandleOrderDispatched do Fleet-Ops), o pedido é oferecido a um motoboy por vez, por SEGUNDOS_DA_OFERTA, na ordem do
 * menor tempo estimado até o cliente (FilaDeCandidatos). Esgotada a fila, ou passados MINUTOS_ATE_ABRIR do despacho,
 * abre a todos no raio, como antes. Ligada por ENTREGAS_DISTRIBUICAO=1 (config services.entregas.distribuicao).
 * Spec: docs/superpowers/specs/2026-10-07-distribuicao-de-pedidos-design.md.
 *
 * Em rodadas (ENTREGAS_DISTRIBUICAO_RODADAS=1, emRodadas()): oferta de SEGUNDOS_DA_OFERTA_EM_RODADAS, rodadas de raio
 * R, 1,5R e 2R (raioDaRodada), voltas até alguém aceitar (a seguinte só SEGUNDOS_ENTRE_VOLTAS depois do início da
 * anterior), lista aberta a partir da rodada 2 da volta 1 e nunca o alarme a todos (só na falha).
 * Spec: docs/superpowers/specs/2026-10-07-distribuicao-em-rodadas-design.md.
 */
class Distribuicao
{
    public const SEGUNDOS_DA_OFERTA = 30;
    public const MINUTOS_ATE_ABRIR  = 3;
    /**
     * Posição do GPS mais velha que isto não serve para estimar (drivers.updated_at). Posição de quem está online e parado
     * continua certa; o corte só tira celular que morreu com o online ligado. O APK vai mandar posição a cada 1 min
     * parado e aí a janela pode cair.
     */
    public const GPS_MINUTOS = 30;
    /** Atraso máximo aceito numa entrega já aceita ao encaixar o pedido novo (ciclo sem rodadas). */
    public const ATRASO_MAXIMO_S = 600;
    /**
     * Em rodadas: atraso máximo para quem já espera ao encaixar o pedido novo (coleta junto na mesma loja, loja no
     * caminho). Nenhuma ordem cabe: "termina tudo e depois vai". Decisão do Edgard (2026-10-07).
     */
    public const ATRASO_MAXIMO_EM_RODADAS_S = 300;
    public const PARADA_LOJA_S    = 180;
    public const PARADA_CLIENTE_S = 120;
    /** Reserva em linha reta quando o OSRM falha: metros × fator, a 25 km/h. */
    public const FATOR_LINHA_RETA  = 1.3;
    public const METROS_POR_SEGUNDO = 25000 / 3600;
    /** Acima disto, o OSRM (max-table-size padrão 100) recusa a matriz: estimativa em linha reta. */
    public const MAX_PONTOS_DA_MATRIZ = 100;
    /** Oferta vencida há mais que isto sem o job (Redis reiniciado): a varredura vence. */
    public const FOLGA_DA_VARREDURA_S = 20;

    public const FASE_OFERTAS   = 'ofertas';
    public const FASE_ABERTA    = 'aberta';
    public const FASE_ENCERRADA = 'encerrada';

    public const ACEITA              = 'aceita';
    public const ATRIBUIDA           = 'atribuida';
    public const CANCELADA           = 'cancelada';
    public const ABERTA_PELA_CENTRAL = 'aberta_pela_central';
    public const FILA_ESGOTADA       = 'fila_esgotada';
    public const PRAZO               = 'prazo';
    public const SEM_CANDIDATO       = 'sem_candidato';
    public const REDESPACHADA        = 'redespachada';
    /** O ciclo falhou no despacho (banco, bug): aberta na hora, e o listener manda o alarme geral. */
    public const FALHA               = 'falha';

    public const PENDENTE = 'pendente';
    public const RECUSADA = 'recusada';
    public const VENCIDA  = 'vencida';
    // 'aceita' e 'cancelada' são as mesmas constantes acima
    /** Em rodadas: o motoboy dispensou o pedido sem ter a oferta (lista aberta ou fechada; linha sem oferta, uma por volta). */
    public const DISPENSADA        = 'dispensada';
    /** Em rodadas: aceito pela lista aberta por quem não tinha a oferta. */
    public const ACEITA_PELA_LISTA = 'aceita_pela_lista';

    /** Em rodadas: cada oferta dura isto (o ciclo de hoje, SEGUNDOS_DA_OFERTA). */
    public const SEGUNDOS_DA_OFERTA_EM_RODADAS = 20;
    /** Raio de cada rodada em múltiplos do raio de pedido aberto (R = Order::getAdhocDistance()). */
    public const MULTIPLICADOR_DA_RODADA = [1 => 1.0, 2 => 1.5, 3 => 2.0];
    public const ULTIMA_RODADA           = 3;
    /** A volta nova só começa este tempo depois do início da anterior. */
    public const SEGUNDOS_ENTRE_VOLTAS = 60;
    /** A varredura avança a distribuição em ofertas, sem oferta pendente, parada (updated_at) há mais que isto. */
    public const SEGUNDOS_PARADA = 60;
    /**
     * Em rodadas: passado isto do despacho, ninguém mais recebe oferta (o pedido de teste esquecido não toca o dia
     * inteiro, e a `posicao` não estoura). A distribuição segue em `ofertas`, só na lista aberta, e o aceite pela lista vale.
     */
    public const MINUTOS_ATE_PARAR_DE_TOCAR = 60;
    /** A lista aberta mostra o pedido ao motoboy com a coleta até este múltiplo de R da posição dele. */
    public const MULTIPLICADOR_DA_LISTA = 2.0;

    /** Resultados do "Mostrar a todos agora" (Distribuidor::mostrarATodos). */
    public const LISTA_ABERTA_AGORA = 'aberta';
    public const LISTA_JA_ABERTA    = 'ja_aberta';
    public const FORA_DE_OFERTAS    = 'fora_de_ofertas';

    /** Distribuição em rodadas: ENTREGAS_DISTRIBUICAO_RODADAS=1, e só com a distribuição ligada. */
    public static function emRodadas(): bool
    {
        return static::ligada() && filter_var(config('services.entregas.distribuicao_rodadas'), FILTER_VALIDATE_BOOLEAN);
    }

    /** Quanto dura uma oferta: 20 s em rodadas, 30 s no ciclo de hoje. */
    public static function segundosDaOferta(): int
    {
        return static::emRodadas() ? static::SEGUNDOS_DA_OFERTA_EM_RODADAS : static::SEGUNDOS_DA_OFERTA;
    }

    /** Raio (m) da rodada 1 a ULTIMA_RODADA a partir do raio de pedido aberto; fora da faixa, a mais próxima. */
    public static function raioDaRodada(int $raio, int $rodada): int
    {
        $rodada = max(1, min(static::ULTIMA_RODADA, $rodada));

        return (int) round($raio * static::MULTIPLICADOR_DA_RODADA[$rodada]);
    }

    public static function osrmLigado(): bool
    {
        return filter_var(config('services.entregas.distribuicao_osrm'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function ligada(): bool
    {
        return filter_var(config('services.entregas.distribuicao'), FILTER_VALIDATE_BOOLEAN);
    }
}
