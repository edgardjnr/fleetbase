<?php

namespace App\Support\Entregas\Saques;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: o único ponto de HTTP com o Asaas (base `services.entregas.asaas_url`), com a chave de API da
 * central já decifrada (Cofre): o saque Pix do motoboy (transferências) e a recarga da loja pré-paga (cobranças Pix). Nunca lança: falha de rede vira RespostaAsaas com status 0. O log leva só o caminho e a
 * classe do erro, nunca a chave. Só https (outra base nem chama: status 0) e sem seguir redirecionamento (o access_token
 * iria junto).
 */
class AsaasCliente
{
    public const TEMPO_LIMITE = 20;

    public function __construct(#[\SensitiveParameter] private string $chave)
    {
    }

    public function dadosComerciais(): RespostaAsaas
    {
        return $this->chamar('GET', '/myAccount/commercialInfo/');
    }

    public function carteiras(): RespostaAsaas
    {
        return $this->chamar('GET', '/wallets');
    }

    public function transferirPix(float $valor, string $chavePix, string $tipoNoAsaas, string $descricao, string $referencia): RespostaAsaas
    {
        return $this->chamar('POST', '/transfers', [
            'value'             => round($valor, 2),
            'operationType'     => 'PIX',
            'pixAddressKey'     => $chavePix,
            'pixAddressKeyType' => $tipoNoAsaas,
            'description'       => $descricao,
            'externalReference' => $referencia,
        ]);
    }

    public function transferencia(string $id): RespostaAsaas
    {
        return $this->chamar('GET', '/transfers/' . rawurlencode($id));
    }

    /** As transferências mais recentes primeiro (o filtro por externalReference não é garantido: quem chama procura). */
    public function transferencias(int $offset, int $limite = 100): RespostaAsaas
    {
        return $this->chamar('GET', '/transfers?offset=' . $offset . '&limit=' . $limite);
    }

    /** Cliente da cobrança da recarga da loja pré-paga (o Asaas exige CPF/CNPJ); sem avisos do Asaas para a loja. */
    public function criarCliente(string $nome, string $documento, string $referencia): RespostaAsaas
    {
        return $this->chamar('POST', '/customers', [
            'name'                 => $nome,
            'cpfCnpj'              => $documento,
            'externalReference'    => $referencia,
            'notificationDisabled' => true,
        ]);
    }

    /** Cobrança Pix (a recarga da loja pré-paga); o QR code sai no qrCodePix. */
    public function criarCobrancaPix(string $cliente, float $valor, string $vencimento, string $descricao, string $referencia): RespostaAsaas
    {
        return $this->chamar('POST', '/payments', [
            'customer'          => $cliente,
            'billingType'       => 'PIX',
            'value'             => round($valor, 2),
            'dueDate'           => $vencimento,
            'description'       => $descricao,
            'externalReference' => $referencia,
        ]);
    }

    public function qrCodePix(string $id): RespostaAsaas
    {
        return $this->chamar('GET', '/payments/' . rawurlencode($id) . '/pixQrCode');
    }

    public function cobranca(string $id): RespostaAsaas
    {
        return $this->chamar('GET', '/payments/' . rawurlencode($id));
    }

    /** As cobranças com este externalReference (a recarga que ficou sem o id do Asaas). */
    public function cobrancasDaReferencia(string $referencia): RespostaAsaas
    {
        return $this->chamar('GET', '/payments?externalReference=' . rawurlencode($referencia) . '&limit=10');
    }

    /** Apaga (cancela) a cobrança: o QR code deixa de valer. A cobrança já paga o Asaas não apaga. */
    public function apagarCobranca(string $id): RespostaAsaas
    {
        return $this->chamar('DELETE', '/payments/' . rawurlencode($id));
    }

    /** A wallet da conta: o primeiro item do GET /wallets; null se não veio. */
    public static function walletDa(RespostaAsaas $resposta): ?string
    {
        $id = $resposta->dados['data'][0]['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    protected function chamar(string $metodo, string $caminho, ?array $dados = null): RespostaAsaas
    {
        $base = trim((string) config('services.entregas.asaas_url', 'https://api.asaas.com/v3'));
        if (!str_starts_with(strtolower($base), 'https://')) {
            // a chave iria em texto aberto: nem chama
            Log::warning('[entregas] saque: ASAAS_API_URL precisa ser https');

            return new RespostaAsaas(0, null);
        }
        $url = rtrim($base, '/') . $caminho;
        try {
            // sem seguir redirecionamento: o access_token iria junto para o outro endereço
            $pedido = Http::withHeaders(['access_token' => $this->chave, 'User-Agent' => 'Entregas RestaurantePro'])
                ->withOptions(['allow_redirects' => false])
                ->acceptJson()
                ->timeout(static::TEMPO_LIMITE);
            $resposta = match ($metodo) {
                'POST'   => $pedido->post($url, $dados ?? []),
                'DELETE' => $pedido->delete($url),
                default  => $pedido->get($url),
            };
        } catch (\Throwable $e) {
            Log::warning('[entregas] saque: Asaas sem resposta', ['caminho' => strtok($caminho, '?'), 'erro' => get_class($e)]);

            return new RespostaAsaas(0, null);
        }
        $json = $resposta->json();

        return new RespostaAsaas($resposta->status(), is_array($json) ? $json : null);
    }
}
