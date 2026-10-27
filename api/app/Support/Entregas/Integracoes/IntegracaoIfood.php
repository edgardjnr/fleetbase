<?php

namespace App\Support\Entregas\Integracoes;

use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\VinculosIfood;

/**
 * Entregas RestaurantePro: o iFood no portal da loja (Integrações). A loja vincula pelo fluxo distribuído, como a
 * central na tela Lojas: o portal pede o código de vínculo, a loja autoriza no Portal do Parceiro e cola o código de
 * autorização (IntegracoesDaLojaController + VinculoIfoodNaTela). A situação vem do VinculosIfood::resumo.
 */
class IntegracaoIfood implements IntegracaoDaLoja
{
    public function id(): string
    {
        return 'ifood';
    }

    public function fluxo(): string
    {
        return 'ifood';
    }

    public function nome(): string
    {
        return 'iFood';
    }

    public function ligada(): bool
    {
        return ClienteIfood::ligada();
    }

    public function situacao(string $companyUuid, string $vendorUuid): array
    {
        return static::doResumo(VinculosIfood::resumo($vendorUuid));
    }

    /**
     * O resumo do vínculo (['situacao', 'nome', 'merchant_id']) como a loja vê: vinculada = conectada, com o nome da loja
     * no iFood; vínculo perdido = atenção (o iFood recusou a renovação: é preciso vincular de novo); sem vínculo =
     * desconectada. O merchant_id não sai.
     */
    public static function doResumo(array $resumo): array
    {
        return match ($resumo['situacao'] ?? null) {
            VinculosIfood::VINCULADA => ['estado' => self::CONECTADA, 'loja_no_ifood' => $resumo['nome'] ?? null],
            VinculosIfood::PERDIDO   => ['estado' => self::ATENCAO, 'loja_no_ifood' => $resumo['nome'] ?? null],
            default                  => ['estado' => self::DESCONECTADA, 'loja_no_ifood' => null],
        };
    }
}
