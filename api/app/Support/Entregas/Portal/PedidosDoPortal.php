<?php

namespace App\Support\Entregas\Portal;

use App\Support\Entregas\Integracao\CobrancaDaIntegracao;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: leitura da entregas_portal_pedidos num lugar só (o chamar motoboy do portal da loja). Pedido
 * do portal = Order com uma linha aqui. Não é linha da integração: nada daqui vai ao webhook da loja, ao acompanhamento da
 * integração nem ao CalculoEntregas. Quem lê: o app do motoboy (comoLinhaDaIntegracao, no formato da integração), a
 * comanda da central e o detalhe do pedido no portal. Spec: docs/superpowers/specs/2026-10-28-chamar-motoboy-portal-design.md.
 */
final class PedidosDoPortal
{
    public const TABELA = 'entregas_portal_pedidos';

    /** O nome que o app mostra no selo de origem (o mesmo do card do portal). */
    public const SISTEMA = 'Portal';

    /** Linhas do mesmo telefone olhadas para reaproveitar o Local. */
    public const ULTIMOS = 20;

    /**
     * A linha do pedido, ou null. Erro de banco (a tabela ainda não existe nos segundos entre o service update e a
     * migration do deploy) vira null com warning: o app, a comanda e o portal seguem como pedido sem linha, sem 500.
     */
    public static function doPedido(?string $orderUuid): ?object
    {
        if ($orderUuid === null || $orderUuid === '') {
            return null;
        }

        try {
            return DB::table(static::TABELA)->where('order_uuid', $orderUuid)->first();
        } catch (QueryException $e) {
            Log::warning('[entregas] portal da loja: pedidos do portal ilegíveis no banco', ['erro' => get_class($e)]);

            return null;
        }
    }

    public static function daChave(string $vendorUuid, string $chave): ?object
    {
        return DB::table(static::TABELA)->where('vendor_uuid', $vendorUuid)->where('chave', $chave)->first();
    }

    /** As últimas linhas do telefone na loja, a mais nova primeiro. */
    public static function doTelefone(string $empresa, string $vendorUuid, string $telefone): array
    {
        return DB::table(static::TABELA)
            ->where('company_uuid', $empresa)
            ->where('vendor_uuid', $vendorUuid)
            ->where('cliente_telefone', $telefone)
            ->orderBy('id', 'desc')
            ->limit(static::ULTIMOS)
            ->get()
            ->all();
    }

    /**
     * O "Já pediu antes" do painel: nome, contagem e o último endereço (rua, bairro, cidade, UF, CEP e ponto do Local;
     * complemento e referência da linha). Só da loja informada; sem pedido do telefone, null. Local apagado ou sem ponto:
     * endereco null.
     */
    public static function clienteDoTelefone(string $empresa, string $vendorUuid, string $telefone): ?array
    {
        $linhas = static::doTelefone($empresa, $vendorUuid, $telefone);
        if (!$linhas) {
            return null;
        }
        $ultima = $linhas[0];
        $lugar  = $ultima->place_uuid ? Place::where('uuid', $ultima->place_uuid)->where('company_uuid', $empresa)->first() : null;
        $ponto  = $lugar && ($lugar->location ?? null) instanceof Point ? $lugar->location : null;

        return [
            'nome'     => (string) $ultima->cliente_nome,
            'telefone' => $telefone,
            'pedidos'  => DB::table(static::TABELA)->where('company_uuid', $empresa)->where('vendor_uuid', $vendorUuid)->where('cliente_telefone', $telefone)->count(),
            'endereco' => $ponto ? [
                'rua'         => $lugar->street1 ?? null,
                'bairro'      => $lugar->neighborhood ?? null,
                'cidade'      => $lugar->city ?? null,
                'uf'          => $lugar->province ?? null,
                'cep'         => $lugar->postal_code ?? null,
                'complemento' => $ultima->complemento,
                'referencia'  => $ultima->referencia,
                'latitude'    => (float) $ponto->getLat(),
                'longitude'   => (float) $ponto->getLng(),
            ] : null,
        ];
    }

    /**
     * A linha no formato da da entregas_integracao_pedidos, para o DadosDaIntegracaoDoMotoboy (app do motoboy, sem APK
     * novo): sistema "Portal", sem número da origem, sem itens e sem cancelamento pago.
     */
    public static function comoLinhaDaIntegracao(?object $linha): ?object
    {
        if (!$linha) {
            return null;
        }

        return (object) [
            'sistema'              => static::SISTEMA,
            'numero'               => null,
            'cliente_nome'         => $linha->cliente_nome,
            'cliente_telefone'     => $linha->cliente_telefone,
            'complemento'          => $linha->complemento,
            'referencia'           => $linha->referencia,
            'observacoes'          => $linha->observacoes,
            'cobranca_forma'       => $linha->cobranca_forma,
            'cobranca_centavos'    => (int) $linha->cobranca_centavos,
            'troco_para_centavos'  => $linha->troco_para_centavos !== null ? (int) $linha->troco_para_centavos : null,
            'itens'                => null,
            'cancelado_em'         => null,
            'pago_mesmo_cancelado' => false,
        ];
    }

    /** A resposta do POST chamar-motoboy (a tela "Procurando motoboy"): número, cliente, endereço e cobrança (null = já pago). */
    public static function resumo(object $pedido, object $linha): array
    {
        $lugar    = $linha->place_uuid ? Place::where('uuid', $linha->place_uuid)->where('company_uuid', $linha->company_uuid)->first() : null;
        $endereco = implode(' · ', array_filter([$lugar->street1 ?? null, $linha->complemento, $lugar->neighborhood ?? null], fn ($parte) => is_string($parte) && trim($parte) !== ''));

        return [
            'id'       => (string) $pedido->public_id,
            'numero'   => (string) (($pedido->tracking ?? null) ?: $pedido->public_id),
            'cliente'  => (string) $linha->cliente_nome,
            'endereco' => $endereco !== '' ? $endereco : null,
            'cobranca' => CobrancaDaIntegracao::texto($linha->cobranca_forma, (int) $linha->cobranca_centavos, $linha->troco_para_centavos !== null ? (int) $linha->troco_para_centavos : null),
        ];
    }
}
