<?php

namespace App\Support\Entregas\Integracoes;

/**
 * Entregas RestaurantePro: os sistemas que a loja conecta pelo portal (Integrações), na ordem da tela.
 *
 * Sistema novo: uma classe que implementa IntegracaoDaLoja (ou estende IntegracaoPorChave, se mandar os pedidos pela
 * API de integração), uma linha em INTEGRACOES e os textos `customer-portal.ui.entregas.integracoes.sistemas.<id>.*` do
 * portal. Fluxo novo (que não seja `ifood` nem `chave`) precisa também das rotas e da tela dele.
 */
final class CatalogoDeIntegracoes
{
    /** @var array<class-string<IntegracaoDaLoja>> */
    public const INTEGRACOES = [
        IntegracaoIfood::class,
        IntegracaoRestaurantePro::class,
    ];

    /** @return IntegracaoDaLoja[] */
    public static function todas(): array
    {
        return array_map(fn (string $classe) => new $classe(), static::INTEGRACOES);
    }

    public static function porId(string $id): ?IntegracaoDaLoja
    {
        foreach (static::todas() as $integracao) {
            if ($integracao->id() === $id) {
                return $integracao;
            }
        }

        return null;
    }

    /** Os cartões da tela Integrações da loja. */
    public static function daLoja(string $companyUuid, string $vendorUuid): array
    {
        return array_map(fn (IntegracaoDaLoja $integracao) => static::cartao($integracao, $companyUuid, $vendorUuid), static::todas());
    }

    /** Um cartão: {id, nome, fluxo, disponivel, estado, …detalhes da situação}. */
    public static function cartao(IntegracaoDaLoja $integracao, string $companyUuid, string $vendorUuid): array
    {
        return [
            'id'         => $integracao->id(),
            'nome'       => $integracao->nome(),
            'fluxo'      => $integracao->fluxo(),
            'disponivel' => $integracao->ligada(),
        ] + $integracao->situacao($companyUuid, $vendorUuid);
    }
}
