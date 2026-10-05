<?php

namespace App\Support\Entregas\Ifood;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Entregas RestaurantePro: única porta HTTP para a Merchant API do iFood (app distribuído).
 *
 * URLs e formatos conferidos com a sonda de 2026-10-05 (docs/ifood/referencia-logistics.md, "Descobertas da sonda"):
 * base https://merchant-api.ifood.com.br; token e userCode em form-urlencoded; polling com excludeHeartbeat=true (sem
 * ele a loja abre indevidamente) e o cabeçalho x-polling-merchants (até 100 ids); 204 = nenhum evento; ack com [{id}].
 *
 * Toda resposta fora de 2xx vira ErroIfood (status, corpo e Retry-After); falha de rede vira ErroIfood com status 0.
 * Não renova token: quem chama (VinculosIfood::comToken) renova no 401 e repete uma vez. Não registra nada no log,
 * para nenhum token, nome, telefone ou endereço ir parar lá.
 */
class ClienteIfood
{
    /** Segundos de espera por resposta. */
    public const TEMPO_LIMITE = 15;

    /** Lojas por chamada de polling (x-polling-merchants). */
    public const MAX_MERCHANTS_POR_POLLING = 100;

    /** Ids por ack: a página de conceitos diz até 2000; a referência, 10000. Fica o menor. */
    public const MAX_IDS_POR_ACK = 2000;

    /** Espera no 429 sem Retry-After, em segundos. */
    public const ESPERA_PADRAO_429 = 60;

    protected string $baseUrl;
    protected string $clientId;
    protected string $clientSecret;

    public function __construct(?string $baseUrl = null, ?string $clientId = null, ?string $clientSecret = null)
    {
        $this->baseUrl      = rtrim($baseUrl ?? (string) config('services.ifood.base_url', 'https://merchant-api.ifood.com.br'), '/');
        $this->clientId     = $clientId ?? (string) config('services.ifood.client_id');
        $this->clientSecret = $clientSecret ?? (string) config('services.ifood.client_secret');
    }

    /** Integração ligada: ENTREGAS_IFOOD=1 (ou true) e as credenciais do app preenchidas no stack.env. */
    public static function ligada(): bool
    {
        return filter_var(config('services.ifood.ativo'), FILTER_VALIDATE_BOOLEAN)
            && (string) config('services.ifood.client_id') !== ''
            && (string) config('services.ifood.client_secret') !== '';
    }

    /** Código de vínculo (userCode, authorizationCodeVerifier, verificationUrl, verificationUrlComplete, expiresIn). */
    public function pedirCodigoDeVinculo(): array
    {
        $resposta = $this->enviar('userCode', fn () => Http::asForm()->acceptJson()->timeout(static::TEMPO_LIMITE)
            ->post($this->baseUrl . '/authentication/v1.0/oauth/userCode', ['clientId' => $this->clientId]));

        return (array) $resposta->json();
    }

    /** Troca o código de autorização (que o dono da loja recebe no Portal do Parceiro) pelos tokens. */
    public function trocarCodigo(string $codigoDeAutorizacao, string $verificador): array
    {
        return $this->token('token', [
            'grantType'                 => 'authorization_code',
            'clientId'                  => $this->clientId,
            'clientSecret'              => $this->clientSecret,
            'authorizationCode'         => $codigoDeAutorizacao,
            'authorizationCodeVerifier' => $verificador,
        ]);
    }

    /** Token novo pelo refresh token. */
    public function renovar(string $refreshToken): array
    {
        return $this->token('refresh', [
            'grantType'    => 'refresh_token',
            'clientId'     => $this->clientId,
            'clientSecret' => $this->clientSecret,
            'refreshToken' => $refreshToken,
        ]);
    }

    /** Lojas que o token enxerga: [{id, name, corporateName}]. */
    public function lojasDoToken(string $token): array
    {
        $resposta = $this->enviar('merchants', fn () => $this->comToken($token)->get($this->baseUrl . '/merchant/v1.0/merchants'));

        return array_values(array_filter((array) $resposta->json(), 'is_array'));
    }

    /** Eventos ainda sem ack das lojas dadas (1 a 100); [] quando o iFood responde 204. */
    public function polling(string $token, array $merchantIds): array
    {
        $merchantIds = array_values(array_unique(array_filter($merchantIds, fn ($id) => is_string($id) && $id !== '')));
        if (!$merchantIds || count($merchantIds) > static::MAX_MERCHANTS_POR_POLLING) {
            throw new InvalidArgumentException('polling: de 1 a ' . static::MAX_MERCHANTS_POR_POLLING . ' lojas por chamada');
        }

        $resposta = $this->enviar('polling', fn () => $this->comToken($token)
            ->withHeaders(['x-polling-merchants' => implode(',', $merchantIds)])
            ->get($this->baseUrl . '/events/v1.0/events:polling', ['excludeHeartbeat' => 'true']));

        return $resposta->status() === 204 ? [] : array_values(array_filter((array) $resposta->json(), 'is_array'));
    }

    /** Confirma o recebimento dos eventos (202), em lotes de até MAX_IDS_POR_ACK. */
    public function ack(string $token, array $ids): void
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => is_string($id) && $id !== '')));
        foreach (array_chunk($ids, static::MAX_IDS_POR_ACK) as $lote) {
            $this->enviar('ack', fn () => $this->comToken($token)
                ->post($this->baseUrl . '/events/v1.0/events/acknowledgment', array_map(fn ($id) => ['id' => $id], $lote)));
        }
    }

    /** O pedido no módulo Logistics (GET /logistics/v1.0/orders/{id}). */
    public function pedidoLogistics(string $token, string $pedidoId): array
    {
        $resposta = $this->enviar('pedido', fn () => $this->comToken($token)->get($this->baseUrl . '/logistics/v1.0/orders/' . rawurlencode($pedidoId)));

        return (array) $resposta->json();
    }

    protected function token(string $operacao, array $campos): array
    {
        $resposta = $this->enviar($operacao, fn () => Http::asForm()->acceptJson()->timeout(static::TEMPO_LIMITE)
            ->post($this->baseUrl . '/authentication/v1.0/oauth/token', $campos));

        $dados = (array) $resposta->json();
        if (!is_string($dados['accessToken'] ?? null) || $dados['accessToken'] === '') {
            throw new ErroIfood($operacao, $resposta->status(), 'resposta sem accessToken');
        }

        return $dados;
    }

    protected function comToken(string $token)
    {
        return Http::withToken($token)->acceptJson()->timeout(static::TEMPO_LIMITE);
    }

    protected function enviar(string $operacao, Closure $chamada): Response
    {
        try {
            $resposta = $chamada();
        } catch (ConnectionException $e) {
            throw new ErroIfood($operacao, 0, substr($e->getMessage(), 0, 300));
        }

        if ($resposta->successful()) {
            return $resposta;
        }

        $espera = null;
        if ($resposta->status() === 429) {
            $cabecalho = trim($resposta->header('Retry-After'));
            $espera    = ctype_digit($cabecalho) ? max(1, (int) $cabecalho) : static::ESPERA_PADRAO_429;
        }

        throw new ErroIfood($operacao, $resposta->status(), substr($resposta->body(), 0, 2000), $espera);
    }
}
