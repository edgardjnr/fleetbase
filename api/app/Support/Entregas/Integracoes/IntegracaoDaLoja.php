<?php

namespace App\Support\Entregas\Integracoes;

/**
 * Entregas RestaurantePro: um sistema que a loja conecta sozinha pelo portal (Integrações). Cada sistema é uma classe
 * que implementa isto e entra na lista do CatalogoDeIntegracoes.
 *
 * - id: identificador estável, usado na rota, nas traduções da tela e no ícone (`ifood`, `restaurantepro`);
 * - fluxo: como a loja conecta, e qual tela o portal abre: `ifood` (código de vínculo + autorização no Portal do
 *   Parceiro) ou `chave` (a chave da loja colada no outro sistema, que chama a API de integração);
 * - nome: o nome do sistema, para quando a tela não tem tradução do id;
 * - ligada: o interruptor do servidor; desligada, o portal mostra o cartão sem o botão de conectar;
 * - situacao: a situação da loja, sem segredo: ['estado' => conectada | aguardando | atencao | desconectada, …detalhes].
 */
interface IntegracaoDaLoja
{
    public const CONECTADA    = 'conectada';
    public const AGUARDANDO   = 'aguardando';
    public const ATENCAO      = 'atencao';
    public const DESCONECTADA = 'desconectada';

    public function id(): string;

    public function fluxo(): string;

    public function nome(): string;

    public function ligada(): bool;

    public function situacao(string $companyUuid, string $vendorUuid): array;
}
