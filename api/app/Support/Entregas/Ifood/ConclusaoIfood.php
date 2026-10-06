<?php

namespace App\Support\Entregas\Ifood;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: a conclusão de um pedido iFood pelo app do motoboy (spec, seção 4), fora da fila.
 *
 * 1. concluir() (POST v1/entregas/motoboy/pedidos/{id}/concluir-ifood): garante no iFood tudo até o
 *    arrivedAtDestination (AcoesIfood::sincronizar, na hora). Uma recusa (4xx) dessas ações não prende o motoboy: a
 *    central já foi avisada e a conclusão segue. As ações que passaram do orçamento de tempo (ORCAMENTO_NA_ROTA, 20 s,
 *    menor que os 45 s do job) respondem TENTE_DE_NOVO (o arrivedAtDestination ainda não saiu). Com `exige_codigo`
 *    (DDCR, que chega logo depois da confirmação), responde PRECISA_CODIGO; senão libera a conclusão.
 * 2. conferirCodigo() (POST .../codigo-ifood): confere o código com o iFood (verifyDeliveryCode) na hora. Certo →
 *    libera; 400/422 cuja description/message/code diz código inválido ("Confirmation code is invalid" na sonda), ou
 *    2xx com success = false → CODIGO_INCORRETO (conta para o teto); outro 4xx (inclusive 409 e 412) →
 *    CODIGO_NAO_CONFERIDO ("peça à central para liberar", sem contar no teto); 408, 429, 5xx e rede → TENTE_DE_NOVO.
 *    O log leva só o resumo do erro (AcoesIfood::resumoDoErro), nunca o corpo cru. Com a trava das ações, relê a
 *    linha antes de chamar o iFood: já liberada (dois cliques, ou a central liberou) → PODE_CONCLUIR sem chamar. Depois de MAXIMO_DE_ERROS
 *    códigos errados no mesmo pedido (contados no cache por VALIDADE_DOS_ERROS), responde MUITAS_TENTATIVAS sem chamar
 *    o iFood: a central libera no console (além do limitador entregas-ifood-codigo, 10 por minuto por motoboy).
 * 3. Liberado (conclusao_liberada_em), o app conclui pelo fluxo de sempre (atividade "completed" da API v1, com a prova
 *    de entrega se a central exigir): a trava "Atualize o app" (RegrasDoPedidoIfood) deixa passar. Assim a conclusão
 *    continua sendo a do Fleet-Ops (atividade, OrderCompleted, prova), sem cópia dela aqui. Uma nova tentativa depois
 *    de liberado não chama o iFood de novo (o código já conferido daria erro).
 *
 * Pedido de teste: segue o `exige_codigo` como qualquer outro (o Gestor de Pedidos do iFood pediu o código ao concluir
 * um pedido de teste). Sem como obter o código, a central libera no console (liberarSemCodigo): fica registrado
 * (conclusao_sem_codigo e o log) e o iFood conclui sozinho 4 h depois. Já liberado, liberarSemCodigo não muda nada.
 */
class ConclusaoIfood
{
    public const PODE_CONCLUIR     = 'pode_concluir';
    public const PRECISA_CODIGO    = 'precisa_codigo';
    public const CODIGO_INCORRETO  = 'codigo_incorreto';
    public const TENTE_DE_NOVO     = 'tente_de_novo';
    public const MUITAS_TENTATIVAS = 'muitas_tentativas';
    public const CODIGO_NAO_CONFERIDO = 'codigo_nao_conferido';

    /**
     * Orçamento, em segundos, do envio das ações na rota síncrona do app (concluir-ifood e codigo-ifood): menor que o
     * do job (AcoesIfood::ORCAMENTO_SEGUNDOS, 45 s), para o motoboy não ficar na porta esperando a resposta.
     */
    public const ORCAMENTO_NA_ROTA = 20;

    /** Contador de códigos errados por pedido (cache): entregas:ifood-codigo-erros:<order uuid>. */
    public const ERROS_DO_CODIGO = 'entregas:ifood-codigo-erros:';

    public const MAXIMO_DE_ERROS = 10;

    /** Validade do contador, em segundos (3 dias: mais que a vida de qualquer pedido em andamento). */
    public const VALIDADE_DOS_ERROS = 259200;

    public function __construct(protected AcoesIfood $acoes, protected VinculosIfood $vinculos, protected ClienteIfood $cliente) {}

    public function concluir(object $linha, Order $pedido): string
    {
        if ($linha->conclusao_liberada_em) {
            return static::PODE_CONCLUIR;
        }

        try {
            $resultado = $this->acoes->sincronizar((string) $pedido->uuid, SequenciaIfood::CHEGOU_NO_CLIENTE, static::ORCAMENTO_NA_ROTA);
        } catch (LockTimeoutException | ErroIfood $e) {
            Log::info('[entregas] ifood: conclusão sem resposta do iFood; o motoboy tenta de novo', ['pedido' => $pedido->public_id, 'status' => $e instanceof ErroIfood ? $e->status : null]);

            return static::TENTE_DE_NOVO;
        }

        if ($resultado['incompleto'] ?? false) {
            Log::info('[entregas] ifood: conclusão com ações ainda por enviar; o motoboy tenta de novo', ['pedido' => $pedido->public_id]);

            return static::TENTE_DE_NOVO;
        }

        $linha = PedidosIfood::doPedido((string) $pedido->uuid) ?? $linha;
        if ($linha->conclusao_liberada_em) {
            return static::PODE_CONCLUIR;
        }
        if ($linha->exige_codigo) {
            return static::PRECISA_CODIGO;
        }

        $this->liberar($linha, false);

        return static::PODE_CONCLUIR;
    }

    public function conferirCodigo(object $linha, Order $pedido, #[\SensitiveParameter] string $codigo): string
    {
        if ($linha->conclusao_liberada_em) {
            return static::PODE_CONCLUIR;
        }

        if ($this->errosDoCodigo($pedido) >= static::MAXIMO_DE_ERROS) {
            Log::warning('[entregas] ifood: código de entrega com tentativas demais; a central libera no console', ['pedido' => $pedido->public_id, 'numero' => $linha->numero ?? null]);

            return static::MUITAS_TENTATIVAS;
        }

        $chegada = $this->concluir($linha, $pedido);
        if ($chegada !== static::PRECISA_CODIGO) {
            // já liberado, não exigia o código ou o iFood não respondeu
            return $chegada;
        }

        $linha   = PedidosIfood::doPedido((string) $pedido->uuid) ?? $linha;
        $vinculo = $this->vinculos->porMerchant((string) $linha->merchant_id);
        if (!$vinculo) {
            Log::warning('[entregas] ifood: código de entrega sem vínculo ativo da loja', ['pedido' => $pedido->public_id]);

            return static::TENTE_DE_NOVO;
        }

        try {
            $resposta = $this->acoes->comATrava((string) $pedido->uuid, function () use ($pedido, $vinculo, $linha, $codigo) {
                // relida com a trava: outro clique (ou a central) pode ter liberado enquanto este esperava
                $atual = PedidosIfood::doPedido((string) $pedido->uuid);
                if ($atual && $atual->conclusao_liberada_em) {
                    return null;
                }

                return $this->vinculos->comToken(
                    $vinculo,
                    fn (string $token) => $this->cliente->verificarCodigo($token, (string) $linha->pedido_ifood_id, $codigo)
                );
            });
        } catch (LockTimeoutException | VinculoPerdido $e) {
            return static::TENTE_DE_NOVO;
        } catch (ErroIfood $e) {
            if (static::codigoInvalido($e)) {
                $this->contarErro($pedido);
                Log::info('[entregas] ifood: código de entrega incorreto', ['pedido' => $pedido->public_id, 'status' => $e->status]);

                return static::CODIGO_INCORRETO;
            }
            // o resumo (errorType/code/description), nunca o corpo cru; o código digitado sai mascarado
            $resumo = str_replace($codigo, '***', AcoesIfood::resumoDoErro($e));
            if (!$e->temporario() && $e->status >= 400 && $e->status < 500) {
                Log::warning('[entregas] ifood: código de entrega não conferido pelo iFood; a central libera no console', ['pedido' => $pedido->public_id, 'status' => $e->status, 'erro' => $resumo]);

                return static::CODIGO_NAO_CONFERIDO;
            }
            Log::warning('[entregas] ifood: falha ao conferir o código de entrega', ['pedido' => $pedido->public_id, 'status' => $e->status, 'erro' => $resumo]);

            return static::TENTE_DE_NOVO;
        }

        if ($resposta === null) {
            return static::PODE_CONCLUIR;
        }

        if (($resposta['success'] ?? null) === false) {
            $this->contarErro($pedido);
            Log::info('[entregas] ifood: código de entrega incorreto', ['pedido' => $pedido->public_id, 'status' => 200]);

            return static::CODIGO_INCORRETO;
        }

        $this->liberar($linha, false);
        Log::info('[entregas] ifood: código de entrega conferido', ['pedido' => $pedido->public_id, 'numero' => $linha->numero]);

        return static::PODE_CONCLUIR;
    }

    /**
     * A central libera a conclusão sem o código (painel iFood do console): registrado no banco e no log. Já liberado
     * (código conferido ou liberação anterior), não grava nem registra nada.
     */
    public function liberarSemCodigo(object $linha, Order $pedido, ?string $usuarioUuid): void
    {
        if ($linha->conclusao_liberada_em) {
            return;
        }

        $this->liberar($linha, true);
        Log::warning('[entregas] ifood: conclusão sem código liberada pela central', ['pedido' => $pedido->public_id, 'numero' => $linha->numero, 'usuario' => $usuarioUuid]);
    }

    protected function liberar(object $linha, bool $semCodigo): void
    {
        PedidosIfood::atualizar($linha, [
            'conclusao_liberada_em' => $linha->conclusao_liberada_em ?: now()->toDateTimeString(),
            'conclusao_sem_codigo'  => $semCodigo || (bool) $linha->conclusao_sem_codigo,
        ]);
    }

    /**
     * 400/422 que diz que o código está errado: "Confirmation code is invalid" (sonda, 400), "Invalid delivery code"
     * (400) e "Verification failed - code does not match" (422), na description, message ou code. Outro 4xx (estado do
     * pedido, campo faltando, 404, 409, 412) não é código errado e não conta para o teto.
     */
    protected static function codigoInvalido(ErroIfood $e): bool
    {
        if ($e->status !== 400 && $e->status !== 422) {
            return false;
        }

        $corpo = $e->corpoJson() ?? [];
        $texto = implode(' ', array_map(fn ($chave) => is_scalar($corpo[$chave] ?? null) ? (string) $corpo[$chave] : '', ['description', 'message', 'code']));

        return (bool) preg_match('/\b(confirmation|delivery|verification)?\s*code\b.*\b(invalid|incorrect|wrong|does not match|mismatch)|\binvalid\b.*\bcode\b|verification failed/i', $texto);
    }

    protected function errosDoCodigo(Order $pedido): int
    {
        return (int) Cache::get(static::ERROS_DO_CODIGO . $pedido->uuid, 0);
    }

    /** Mais um código errado no pedido (sem trava: o limitador por minuto já segura a corrida). */
    protected function contarErro(Order $pedido): void
    {
        Cache::put(static::ERROS_DO_CODIGO . $pedido->uuid, $this->errosDoCodigo($pedido) + 1, static::VALIDADE_DOS_ERROS);
    }
}
