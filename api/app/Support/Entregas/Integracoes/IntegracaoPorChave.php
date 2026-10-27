<?php

namespace App\Support\Entregas\Integracoes;

use App\Support\Entregas\Integracao\ChavesDeIntegracao;
use App\Support\Entregas\Integracao\Integracao;
use App\Support\Entregas\Integracao\PainelDaIntegracao;

/**
 * Entregas RestaurantePro: um sistema que manda os pedidos pela API de integração (v1/entregas/integracao/*) com a
 * chave da loja. A loja gera a chave no portal (Integrações), cola no outro sistema e ele conecta (POST conexao).
 *
 * A chave é uma só por loja (ChavesDeIntegracao): gerar outra revoga a anterior. Um segundo sistema por chave ao mesmo
 * tempo precisa de chave por sistema; até lá, o cartão mostra o sistema que conectou com a chave da loja.
 */
abstract class IntegracaoPorChave implements IntegracaoDaLoja
{
    public function fluxo(): string
    {
        return 'chave';
    }

    public function ligada(): bool
    {
        return Integracao::ligada();
    }

    public function situacao(string $companyUuid, string $vendorUuid): array
    {
        return static::doPainel(PainelDaIntegracao::daLoja(ChavesDeIntegracao::ativa($companyUuid, $vendorUuid)));
    }

    /**
     * O bloco da tela Lojas (PainelDaIntegracao::daLoja) como a loja vê: sem chave = desconectada; chave gerada e o
     * sistema ainda não chamou a conexão = aguardando; conectada, com o sistema, o último uso e a última falha ao
     * avisar o sistema (o sistema da loja não recebeu a mudança de um pedido). Sem o host do webhook.
     */
    public static function doPainel(array $painel): array
    {
        if (empty($painel['ativa'])) {
            return ['estado' => self::DESCONECTADA];
        }

        return [
            'estado'                => empty($painel['conectada']) ? self::AGUARDANDO : self::CONECTADA,
            'chave_final'           => $painel['chave_final'] ?? null,
            'sistema'               => $painel['sistema'] ?? null,
            'gerada_em'             => $painel['gerada_em'] ?? null,
            'conectada_em'          => $painel['conectada_em'] ?? null,
            'ultimo_uso_em'         => $painel['ultimo_uso_em'] ?? null,
            'ultima_falha_aviso'    => $painel['ultima_falha_aviso'] ?? null,
            'ultima_falha_aviso_em' => $painel['ultima_falha_aviso_em'] ?? null,
        ];
    }
}
