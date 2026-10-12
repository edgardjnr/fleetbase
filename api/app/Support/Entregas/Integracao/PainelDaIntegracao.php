<?php

namespace App\Support\Entregas\Integracao;

use Illuminate\Support\Carbon;

/**
 * Entregas RestaurantePro: o que o console mostra da integração (funções puras). A tela Lojas (daLoja): se há chave
 * ativa, o final dela, o sistema conectado, só o host do webhook (a URL tem o token do destino), as datas e a última
 * falha de aviso. O painel do pedido (doPedido): sistema, ids, cobrança, último aviso, cancelamento. Nunca o segredo, a
 * chave ou o telefone do cliente. Datas em ISO no fuso do app.
 */
final class PainelDaIntegracao
{
    public static function daLoja(?object $loja): array
    {
        $host = $loja && $loja->webhook_url ? parse_url((string) $loja->webhook_url, PHP_URL_HOST) : null;

        return [
            'ligada'                => Integracao::ligada(),
            'ativa'                 => $loja !== null,
            'chave_final'           => $loja->chave_final ?? null,
            'sistema'               => $loja->sistema ?? null,
            'conectada'             => (bool) ($loja->conectada_em ?? null),
            'webhook_host'          => $host ?: null,
            'gerada_em'             => static::iso($loja->created_at ?? null),
            'conectada_em'          => static::iso($loja->conectada_em ?? null),
            'ultimo_uso_em'         => static::iso($loja->ultimo_uso_em ?? null),
            'ultima_falha_aviso'    => $loja->ultima_falha_aviso ?? null,
            'ultima_falha_aviso_em' => static::iso($loja->ultima_falha_aviso_em ?? null),
        ];
    }

    public static function doPedido(?object $linha, ?object $loja): array
    {
        if (!$linha) {
            return ['integracao' => false];
        }

        $centavos = (int) $linha->cobranca_centavos;
        $troco    = $linha->troco_para_centavos !== null ? (int) $linha->troco_para_centavos : null;

        return [
            'integracao'            => true,
            'sistema'               => $linha->sistema,
            'pedido_externo_id'     => $linha->pedido_externo_id,
            'numero'                => $linha->numero,
            'cobranca'              => [
                'forma'               => CobrancaDaIntegracao::forma($linha->cobranca_forma),
                'centavos'            => $centavos,
                'troco_para_centavos' => $troco,
                'texto'               => CobrancaDaIntegracao::texto($linha->cobranca_forma, $centavos, $troco),
            ],
            'observacoes'           => $linha->observacoes,
            'sem_localizacao'       => (bool) $linha->sem_localizacao,
            'despachado_em'         => static::iso($linha->despachado_em),
            'ultimo_aviso'          => $linha->ultimo_aviso,
            'cancelado_em'          => static::iso($linha->cancelado_em),
            'cancelado_por'         => $linha->cancelado_por,
            'pago_mesmo_cancelado'  => (bool) $linha->pago_mesmo_cancelado,
            'ultima_falha_aviso'    => $loja->ultima_falha_aviso ?? null,
            'ultima_falha_aviso_em' => static::iso($loja->ultima_falha_aviso_em ?? null),
        ];
    }

    /** Texto do banco (fuso do app, sem fuso) → ISO com o fuso; null fica null. */
    public static function iso(?string $texto): ?string
    {
        return $texto ? Carbon::parse(substr($texto, 0, 19), date_default_timezone_get())->toIso8601String() : null;
    }
}
