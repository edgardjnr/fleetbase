<?php

namespace App\Support\Entregas\Saques;

/**
 * Entregas RestaurantePro: a resposta do Asaas. status 0 = não houve resposta (rede, tempo esgotado); `dados` = o JSON
 * (null se não veio JSON). semResposta() também vale para 5xx: nesses casos a transferência PODE ter sido criada.
 */
final class RespostaAsaas
{
    public function __construct(public int $status, public ?array $dados) {}

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function semResposta(): bool
    {
        return $this->status === 0 || $this->status >= 500;
    }

    public function chaveRecusada(): bool
    {
        return $this->status === 401 || $this->status === 403;
    }

    /** As descrições de `errors` do Asaas, juntas (até 255), ou o $padrao. */
    public function mensagem(string $padrao): string
    {
        $erros = $this->dados['errors'] ?? null;
        if (is_array($erros)) {
            $textos = [];
            foreach ($erros as $erro) {
                $texto = is_array($erro) ? (string) ($erro['description'] ?? $erro['code'] ?? '') : '';
                if (trim($texto) !== '') {
                    $textos[] = trim($texto);
                }
            }
            if ($textos) {
                return mb_substr(implode('; ', $textos), 0, 255);
            }
        }

        return $padrao;
    }
}
