<?php

namespace App\Support\Entregas\Ifood;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: a conclusão de um pedido iFood pelo app do motoboy (spec, seção 4), fora da fila.
 *
 * 1. concluir() (POST v1/entregas/motoboy/pedidos/{id}/concluir-ifood): garante no iFood tudo até o
 *    arrivedAtDestination (AcoesIfood::sincronizar, na hora). Uma recusa (4xx) dessas ações não prende o motoboy: a
 *    central já foi avisada e a conclusão segue. Com `exige_codigo` (DDCR, que chega logo depois da confirmação),
 *    responde PRECISA_CODIGO; senão libera a conclusão.
 * 2. conferirCodigo() (POST .../codigo-ifood): confere o código com o iFood (verifyDeliveryCode) na hora. Certo →
 *    libera; HTTP 400 "Confirmation code is invalid" (sonda) ou 422 (documentação), ou 2xx com success = false →
 *    CODIGO_INCORRETO; qualquer outra falha → TENTE_DE_NOVO.
 * 3. Liberado (conclusao_liberada_em), o app conclui pelo fluxo de sempre (atividade "completed" da API v1, com a prova
 *    de entrega se a central exigir): a trava "Atualize o app" (RegrasDoPedidoIfood) deixa passar. Assim a conclusão
 *    continua sendo a do Fleet-Ops (atividade, OrderCompleted, prova), sem cópia dela aqui. Uma nova tentativa depois
 *    de liberado não chama o iFood de novo (o código já conferido daria erro).
 *
 * Pedido de teste: segue o `exige_codigo` como qualquer outro (o Gestor de Pedidos do iFood pediu o código ao concluir
 * um pedido de teste). Sem como obter o código, a central libera no console (liberarSemCodigo): fica registrado
 * (conclusao_sem_codigo e o log) e o iFood conclui sozinho 4 h depois.
 */
class ConclusaoIfood
{
    public const PODE_CONCLUIR    = 'pode_concluir';
    public const PRECISA_CODIGO   = 'precisa_codigo';
    public const CODIGO_INCORRETO = 'codigo_incorreto';
    public const TENTE_DE_NOVO    = 'tente_de_novo';

    public function __construct(protected AcoesIfood $acoes, protected VinculosIfood $vinculos, protected ClienteIfood $cliente) {}

    public function concluir(object $linha, Order $pedido): string
    {
        if ($linha->conclusao_liberada_em) {
            return static::PODE_CONCLUIR;
        }

        try {
            $this->acoes->sincronizar((string) $pedido->uuid, SequenciaIfood::CHEGOU_NO_CLIENTE);
        } catch (LockTimeoutException | ErroIfood $e) {
            Log::info('[entregas] ifood: conclusão sem resposta do iFood; o motoboy tenta de novo', ['pedido' => $pedido->public_id, 'status' => $e instanceof ErroIfood ? $e->status : null]);

            return static::TENTE_DE_NOVO;
        }

        $linha = PedidosIfood::doPedido((string) $pedido->uuid) ?? $linha;
        if ($linha->exige_codigo) {
            return static::PRECISA_CODIGO;
        }

        $this->liberar($linha, false);

        return static::PODE_CONCLUIR;
    }

    public function conferirCodigo(object $linha, Order $pedido, #[\SensitiveParameter] string $codigo): string
    {
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
            $resposta = $this->acoes->comATrava((string) $pedido->uuid, fn () => $this->vinculos->comToken(
                $vinculo,
                fn (string $token) => $this->cliente->verificarCodigo($token, (string) $linha->pedido_ifood_id, $codigo)
            ));
        } catch (LockTimeoutException | VinculoPerdido $e) {
            return static::TENTE_DE_NOVO;
        } catch (ErroIfood $e) {
            if ($e->status === 400 || $e->status === 422) {
                Log::info('[entregas] ifood: código de entrega incorreto', ['pedido' => $pedido->public_id, 'status' => $e->status]);

                return static::CODIGO_INCORRETO;
            }
            Log::warning('[entregas] ifood: falha ao conferir o código de entrega', ['pedido' => $pedido->public_id, 'status' => $e->status, 'corpo' => mb_substr($e->corpo, 0, 300)]);

            return static::TENTE_DE_NOVO;
        }

        if (($resposta['success'] ?? null) === false) {
            Log::info('[entregas] ifood: código de entrega incorreto', ['pedido' => $pedido->public_id, 'status' => 200]);

            return static::CODIGO_INCORRETO;
        }

        $this->liberar($linha, false);
        Log::info('[entregas] ifood: código de entrega conferido', ['pedido' => $pedido->public_id, 'numero' => $linha->numero]);

        return static::PODE_CONCLUIR;
    }

    /** A central libera a conclusão sem o código (painel iFood do console): registrado no banco e no log. */
    public function liberarSemCodigo(object $linha, Order $pedido, ?string $usuarioUuid): void
    {
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
}
