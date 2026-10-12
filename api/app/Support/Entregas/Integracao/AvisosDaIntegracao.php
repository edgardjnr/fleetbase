<?php

namespace App\Support\Entregas\Integracao;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: os avisos de volta ao sistema de origem (webhook). Relê o pedido, calcula o que falta
 * (SequenciaDaIntegracao) e envia em ordem, gravando o ultimo_aviso a cada envio aceito (2xx).
 *
 * - Corpo JSON {evento, id_entrega, pedido_externo_id, id, status, motoboy, codigo_rastreio, link_rastreio,
 *   cancelamento, ocorrido_em}; cabeçalhos X-Entregas-Evento, X-Entregas-Id (estável por pedido, evento e motoboy: o
 *   destino descarta o repetido) e X-Entregas-Assinatura = "sha256=" + HMAC-SHA256 do corpo cru com o segredo da loja.
 * - Timeout de 5 s, sem seguir redirecionamento, URL conferida de novo a cada envio (UrlDoWebhook) e a conexão presa
 *   ao IP conferido (CURLOPT_RESOLVE, só IPv4): sem nova resolução de DNS no envio (rebinding, AAAA privado).
 * - Não 2xx ou rede: FalhaNoAviso (o job tenta de novo). 410: o destino desistiu: registra a falha na loja e para, sem
 *   exceção. URL recusada: registra e para.
 * - Loja sem chave ativa, sem URL ou sem segredo legível: nada a enviar.
 * - Trava por pedido (entregas:integracao-aviso:<uuid>): o job e um envio no mesmo instante não mandam o mesmo aviso
 *   duas vezes.
 */
class AvisosDaIntegracao
{
    public const TIMEOUT = 5;

    public const TRAVA = 'entregas:integracao-aviso:';

    /** host => IPs, para a UrlDoWebhook (null = DNS de verdade). */
    protected ?\Closure $resolver;

    /**
     * `?callable`, e não `?\Closure`: o container do Laravel injeta o padrão (null) num parâmetro de tipo nativo; com o
     * tipo Closure ele tentaria construir um Closure.
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver === null ? null : \Closure::fromCallable($resolver);
    }

    /**
     * @return string[] os eventos enviados
     *
     * @throws FalhaNoAviso
     * @throws \Illuminate\Contracts\Cache\LockTimeoutException
     */
    public function sincronizar(string $orderUuid): array
    {
        return Cache::lock(static::TRAVA . $orderUuid, 60)->block(5, fn () => $this->sincronizarComATrava($orderUuid));
    }

    /** O job desistiu (tentativas esgotadas): a tela Lojas mostra a falha. */
    public function registrarFalha(string $orderUuid, int $status): void
    {
        $linha = PedidosDaIntegracao::doPedido($orderUuid);
        $loja  = $linha ? ChavesDeIntegracao::ativa((string) $linha->company_uuid, (string) $linha->vendor_uuid) : null;
        if ($loja) {
            ChavesDeIntegracao::registrarFalhaDeAviso($loja, $status > 0 ? "o sistema de origem respondeu {$status}" : 'o sistema de origem não respondeu');
        }
    }

    public static function idDoAviso(string $orderUuid, string $evento, ?string $motoboy): string
    {
        return substr(hash('sha256', "{$orderUuid}|{$evento}|{$motoboy}"), 0, 32);
    }

    public static function assinatura(string $json, string $segredo): string
    {
        return 'sha256=' . hash_hmac('sha256', $json, $segredo);
    }

    public static function corpo(string $evento, object $linha, object $pedido, ?object $motoboy, string $id, string $agora): array
    {
        $codigo = $pedido->tracking ?? null;

        return [
            'evento'            => 'pedido.' . $evento,
            'id_entrega'        => $id,
            'pedido_externo_id' => $linha->pedido_externo_id,
            'id'                => $pedido->public_id,
            'status'            => $pedido->status,
            'motoboy'           => $motoboy ? ['nome' => $motoboy->name, 'foto_url' => $motoboy->photo_url ?? null] : null,
            'codigo_rastreio'   => $codigo,
            'link_rastreio'     => Integracao::linkDeRastreio($codigo),
            'cancelamento'      => $evento === SequenciaDaIntegracao::CANCELADO
                ? ['por' => $linha->cancelado_por ?? 'central', 'pago' => (bool) ($linha->pago_mesmo_cancelado ?? false)]
                : null,
            'ocorrido_em'       => $agora,
        ];
    }

    protected function sincronizarComATrava(string $orderUuid): array
    {
        $linha  = PedidosDaIntegracao::doPedido($orderUuid);
        $pedido = $linha ? Order::where('uuid', $orderUuid)->with(['trackingNumber', 'driverAssigned.user.avatar'])->first() : null;
        if (!$linha || !$pedido) {
            return [];
        }

        $loja    = ChavesDeIntegracao::ativa((string) $linha->company_uuid, (string) $linha->vendor_uuid);
        $segredo = $loja && $loja->webhook_url ? ChavesDeIntegracao::segredo($loja) : null;
        if (!$segredo) {
            return [];
        }

        $eventos = SequenciaDaIntegracao::doPedido($linha, $pedido);
        if (!$eventos) {
            return [];
        }

        $conferida = UrlDoWebhook::conferir((string) $loja->webhook_url, $this->resolver);
        if ($conferida['motivo'] !== null) {
            ChavesDeIntegracao::registrarFalhaDeAviso($loja, 'URL do webhook recusada: ' . $conferida['motivo']);
            Log::warning('[entregas] integração: webhook recusado (URL privada ou inválida)', ['vendor' => $loja->vendor_uuid, 'motivo' => $conferida['motivo']]);

            return [];
        }

        $motoboyUuid = $pedido->driver_assigned_uuid ? (string) $pedido->driver_assigned_uuid : null;
        $motoboy     = $pedido->driverAssigned ?? null;
        $enviados    = [];
        foreach ($eventos as $evento) {
            $id     = static::idDoAviso($orderUuid, $evento, $motoboyUuid);
            $corpo  = static::corpo($evento, $linha, $pedido, $motoboy, $id, now()->toIso8601String());
            $status = $this->enviar($loja, $segredo, $evento, $id, $corpo, (string) $conferida['host'], (string) $conferida['ip']);

            if ($status === 410) {
                ChavesDeIntegracao::registrarFalhaDeAviso($loja, 'o sistema de origem respondeu 410 (integração encerrada lá)');
                Log::warning('[entregas] integração: o destino recusou os avisos (410)', ['pedido' => $pedido->public_id, 'evento' => $evento]);

                return $enviados;
            }
            if ($status < 200 || $status >= 300) {
                throw new FalhaNoAviso($status);
            }

            $valores = ['ultimo_aviso' => SequenciaDaIntegracao::depoisDe($linha->ultimo_aviso ?? null, $evento)];
            if ($evento === SequenciaDaIntegracao::ACEITO) {
                $valores['motoboy_avisado'] = $motoboyUuid;
            }
            PedidosDaIntegracao::atualizar($linha, $valores);
            ChavesDeIntegracao::limparFalhaDeAviso($loja);
            Log::info('[entregas] integração: aviso enviado', ['pedido' => $pedido->public_id, 'evento' => $evento, 'status' => $status]);
            $enviados[] = $evento;
        }

        return $enviados;
    }

    /** O status HTTP da resposta, ou 0 sem resposta (rede, timeout). */
    protected function enviar(object $loja, string $segredo, string $evento, string $id, array $corpo, string $host, string $ip): int
    {
        $json = (string) json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $resposta = Http::withHeaders([
                'X-Entregas-Evento'     => 'pedido.' . $evento,
                'X-Entregas-Id'         => $id,
                'X-Entregas-Assinatura' => static::assinatura($json, $segredo),
                'User-Agent'            => 'EntregasRestaurantePro/1.0',
            ])->withOptions([
                'allow_redirects'  => false,
                // só IPv4 e no IP conferido pela UrlDoWebhook: o curl não resolve o nome de novo (DNS rebinding, AAAA privado)
                'force_ip_resolve' => 'v4',
                'curl'             => [static::curlResolve() => ["{$host}:443:{$ip}"]],
            ])->timeout(static::TIMEOUT)->withBody($json, 'application/json')->post((string) $loja->webhook_url);
        } catch (\Throwable $e) {
            Log::warning('[entregas] integração: aviso sem resposta', ['vendor' => $loja->vendor_uuid, 'evento' => $evento, 'erro' => get_class($e)]);

            return 0;
        }

        return $resposta->status();
    }

    /** CURLOPT_RESOLVE (10203): a constante só existe com a extensão curl carregada (o php-wasm dos testes não tem). */
    protected static function curlResolve(): int
    {
        return \defined('CURLOPT_RESOLVE') ? \constant('CURLOPT_RESOLVE') : 10203;
    }
}
