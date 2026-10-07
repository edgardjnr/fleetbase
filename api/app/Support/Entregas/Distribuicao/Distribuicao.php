<?php

namespace App\Support\Entregas\Distribuicao;

/**
 * Entregas RestaurantePro: distribuição de pedidos abertos. Em vez do alarme a todos os motoboys do raio
 * (HandleOrderDispatched do Fleet-Ops), o pedido é oferecido a um motoboy por vez, por SEGUNDOS_DA_OFERTA, na ordem do
 * menor tempo estimado até o cliente (FilaDeCandidatos). Esgotada a fila, ou passados MINUTOS_ATE_ABRIR do despacho,
 * abre a todos no raio, como antes. Ligada por ENTREGAS_DISTRIBUICAO=1 (config services.entregas.distribuicao).
 * Spec: docs/superpowers/specs/2026-10-07-distribuicao-de-pedidos-design.md.
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
    /** Atraso máximo aceito numa entrega já aceita ao encaixar o pedido novo. */
    public const ATRASO_MAXIMO_S = 600;
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

    public const PENDENTE = 'pendente';
    public const RECUSADA = 'recusada';
    public const VENCIDA  = 'vencida';
    // 'aceita' e 'cancelada' são as mesmas constantes acima

    public static function osrmLigado(): bool
    {
        return filter_var(config('services.entregas.distribuicao_osrm'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function ligada(): bool
    {
        return filter_var(config('services.entregas.distribuicao'), FILTER_VALIDATE_BOOLEAN);
    }
}
