<?php

namespace App\Support\Entregas\Ifood;

use App\Events\Entregas\IfoodAcaoRecusada;
use App\Support\Entregas\TransmissaoNoSocket;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: leva um pedido iFood, no iFood, até a etapa em que ele está no Fleetbase (spec, seção 3).
 *
 * sincronizar() relê o pedido e a linha da entregas_ifood_pedidos com a trava das ações do pedido (Cache::lock
 * `entregas:ifood-acao:<order uuid>`: dois envios do mesmo pedido nunca correm juntos), calcula a ação alvo
 * (SequenciaIfood::alvoPeloPedido, ou $alvoMinimo quando mais adiante: a chegada pelo GPS e a conclusão do app) e envia,
 * em ordem, as que faltam depois da `ultima_acao` (SequenciaIfood::faltando). Cada ação aceita grava a `ultima_acao` na
 * hora: uma falha no meio guarda o que já foi.
 *
 * - Troca de motoboy: com o iFood já tendo recebido outro motoboy (`motoboy_no_ifood`) e o pedido com um novo,
 *   assignDriver de novo antes de seguir. A documentação não diz se o iFood aceita depois do goingToOrigin; um 409 vira
 *   recusa (abaixo).
 * - Recusa (409 ou outro 4xx do iFood, vínculo perdido ou motoboy sem nome/telefone): não tenta de novo; grava
 *   recusa_acao/recusa_status/recusa_em, registra `[entregas] ifood: ação recusada` (ação, status e o corpo da resposta,
 *   que não traz dados do cliente) e avisa a central no console (IfoodAcaoRecusada). Para nesta ação: as seguintes
 *   ficam para a próxima mudança do pedido. Uma ação aceita depois limpa a recusa.
 * - Falha temporária (429, 408, 5xx, rede): o ErroIfood sobe para quem chamou (o job EnviarAcaoIfood tenta de novo; a
 *   rota do app responde "tente de novo").
 * - Pedido cancelado pelo iFood, sem linha, sem Order ou sem motoboy: nada.
 *
 * Os logs levam só ids, a ação, o status e o número do pedido; o nome e o telefone do motoboy vão só no corpo do
 * assignDriver.
 */
class AcoesIfood
{
    public const TRAVA = 'entregas:ifood-acao:';

    /** Validade da trava, em segundos: maior que o $timeout do job (80 s), para não vencer com o envio no meio. */
    public const VALIDADE_DA_TRAVA = 90;

    /** Espera pela trava, em segundos, antes de desistir com LockTimeoutException. */
    public const ESPERA_DA_TRAVA = 10;

    public const VEICULO = 'MOTORCYCLE';

    public function __construct(protected VinculosIfood $vinculos, protected ClienteIfood $cliente) {}

    /**
     * @return array{enviadas: string[], recusada: ?array{acao: string, status: int}}
     *
     * @throws LockTimeoutException trava das ações do pedido ocupada por mais de ESPERA_DA_TRAVA s
     * @throws ErroIfood            falha temporária do iFood (quem chama tenta de novo)
     */
    public function sincronizar(string $orderUuid, ?string $alvoMinimo = null): array
    {
        return $this->comATrava($orderUuid, fn () => $this->sincronizarComATrava($orderUuid, $alvoMinimo));
    }

    /** Roda $fazer com a trava das ações do pedido (a mesma do sincronizar; a ConclusaoIfood confere o código com ela). */
    public function comATrava(string $orderUuid, \Closure $fazer): mixed
    {
        return Cache::lock(static::TRAVA . $orderUuid, static::VALIDADE_DA_TRAVA)->block(static::ESPERA_DA_TRAVA, $fazer);
    }

    /** O job desistiu (tentativas esgotadas com o iFood fora do ar): a próxima ação que faltava vira recusa, com aviso. */
    public function desistir(string $orderUuid, int $status): void
    {
        $linha  = PedidosIfood::doPedido($orderUuid);
        $pedido = Order::where('uuid', $orderUuid)->first();
        if (!$linha || !$pedido || $linha->cancelado_pelo_ifood_em) {
            return;
        }

        $motoboy = $pedido->driver_assigned_uuid ? (string) $pedido->driver_assigned_uuid : null;
        $alvo    = SequenciaIfood::alvoPeloPedido($pedido->status, (bool) $pedido->started, $motoboy);
        $proxima = SequenciaIfood::faltando($linha->ultima_acao, $alvo)[0] ?? SequenciaIfood::ATRIBUIR;
        $this->recusar($linha, $pedido, $proxima, $status, 'iFood fora do ar: tentativas esgotadas');
    }

    protected function sincronizarComATrava(string $orderUuid, ?string $alvoMinimo): array
    {
        $resultado = ['enviadas' => [], 'recusada' => null];

        $linha  = PedidosIfood::doPedido($orderUuid);
        $pedido = $linha ? Order::where('uuid', $orderUuid)->first() : null;
        if (!$linha || !$pedido || $linha->cancelado_pelo_ifood_em) {
            return $resultado;
        }

        $motoboy = $pedido->driver_assigned_uuid ? (string) $pedido->driver_assigned_uuid : null;
        if ($motoboy === null) {
            // sem motoboy não há o que informar (assignDriver é a primeira); motoboy tirado do pedido não tem ação no iFood
            return $resultado;
        }

        // concluído sem passar pela conclusão do app (pela central no console, ou por APK antigo com a trava desligada) num
        // pedido que exigia o código: o iFood fica com a confirmação pendente e conclui sozinho 4 h depois
        if ($pedido->status === 'completed' && $linha->exige_codigo && !$linha->conclusao_liberada_em && !$linha->conclusao_sem_codigo) {
            PedidosIfood::atualizar($linha, ['conclusao_sem_codigo' => true]);
            Log::warning('[entregas] ifood: pedido concluído sem o código do cliente', ['pedido' => $pedido->public_id, 'numero' => $linha->numero]);
        }

        $alvo = SequenciaIfood::maisAdiante(SequenciaIfood::alvoPeloPedido($pedido->status, (bool) $pedido->started, $motoboy), $alvoMinimo);

        // troca de motoboy: o iFood tem outro (o pedido já passou do assignDriver)
        if ($linha->ultima_acao !== null && $linha->motoboy_no_ifood && $linha->motoboy_no_ifood !== $motoboy) {
            if (!$this->enviar($linha, $pedido, SequenciaIfood::ATRIBUIR, $motoboy, $resultado)) {
                return $resultado;
            }
        }

        foreach (SequenciaIfood::faltando($linha->ultima_acao, $alvo) as $acao) {
            if (!$this->enviar($linha, $pedido, $acao, $motoboy, $resultado)) {
                break;
            }
        }

        return $resultado;
    }

    /** Envia uma ação; true se o iFood aceitou. Recusa grava, registra e avisa (false); falha temporária sobe. */
    protected function enviar(object $linha, Order $pedido, string $acao, string $motoboyUuid, array &$resultado): bool
    {
        $vinculo = $this->vinculos->porMerchant((string) $linha->merchant_id);
        if (!$vinculo) {
            return $this->recusa($linha, $pedido, $acao, 0, 'loja sem vínculo ativo com o iFood', $resultado);
        }

        $corpo = null;
        if ($acao === SequenciaIfood::ATRIBUIR) {
            $corpo = static::corpoDoMotoboy(Driver::where('uuid', $motoboyUuid)->first());
            if ($corpo === null) {
                return $this->recusa($linha, $pedido, $acao, 0, 'motoboy sem nome ou telefone no cadastro', $resultado);
            }
        }

        try {
            $this->vinculos->comToken($vinculo, fn (string $token) => $this->cliente->acaoLogistica($token, (string) $linha->pedido_ifood_id, $acao, $corpo));
        } catch (VinculoPerdido) {
            return $this->recusa($linha, $pedido, $acao, 0, 'vínculo da loja perdido', $resultado);
        } catch (ErroIfood $e) {
            if ($e->temporario()) {
                throw $e;
            }

            return $this->recusa($linha, $pedido, $acao, $e->status, $e->operacao . ': ' . $e->corpo, $resultado);
        }

        $valores = ['recusa_acao' => null, 'recusa_status' => null, 'recusa_em' => null];
        if (SequenciaIfood::posicao($acao) > SequenciaIfood::posicao($linha->ultima_acao)) {
            $valores['ultima_acao'] = $acao;
        }
        if ($acao === SequenciaIfood::ATRIBUIR) {
            $valores['motoboy_no_ifood'] = $motoboyUuid;
        }
        PedidosIfood::atualizar($linha, $valores);

        Log::info('[entregas] ifood: ação enviada', ['acao' => $acao, 'pedido' => $pedido->public_id, 'numero' => $linha->numero]);
        $resultado['enviadas'][] = $acao;

        return true;
    }

    protected function recusa(object $linha, Order $pedido, string $acao, int $status, string $detalhe, array &$resultado): bool
    {
        $this->recusar($linha, $pedido, $acao, $status, $detalhe);
        $resultado['recusada'] = ['acao' => $acao, 'status' => $status];

        return false;
    }

    protected function recusar(object $linha, Order $pedido, string $acao, int $status, string $detalhe): void
    {
        PedidosIfood::atualizar($linha, [
            'recusa_acao'   => $acao,
            'recusa_status' => $status > 0 ? $status : null,
            'recusa_em'     => now()->toDateTimeString(),
        ]);

        Log::warning('[entregas] ifood: ação recusada', [
            'acao'   => $acao,
            'status' => $status,
            'corpo'  => mb_substr($detalhe, 0, 500),
            'pedido' => $pedido->public_id,
            'numero' => $linha->numero,
        ]);

        $erro = TransmissaoNoSocket::enviar(new IfoodAcaoRecusada(
            (string) $pedido->company_uuid,
            (string) $pedido->uuid,
            (string) $pedido->public_id,
            $linha->numero ? (string) $linha->numero : null,
            $acao,
            $status
        ));
        if ($erro !== null) {
            Log::warning('[entregas] ifood: aviso de ação recusada não chegou ao socket', ['pedido' => $pedido->public_id, 'erro' => $erro]);
        }
    }

    /** Corpo do assignDriver: nome e telefone do motoboy (o cadastro dele no Fleetbase) e o veículo; null se faltar um dos dois. */
    public static function corpoDoMotoboy(?Driver $motoboy): ?array
    {
        $nome     = trim((string) ($motoboy?->name ?? ''));
        $telefone = static::telefone($motoboy?->phone);
        if ($nome === '' || $telefone === null) {
            return null;
        }

        return ['workerName' => mb_substr($nome, 0, 100), 'workerPhone' => $telefone, 'workerVehicleType' => static::VEICULO];
    }

    /**
     * Telefone no formato que a sonda viu o iFood aceitar: DDD + número, só dígitos ("16999990000"). O cadastro do
     * motoboy guarda E.164 ("+5516999990000"): o 55 do Brasil sai. Menos de 10 dígitos (sem DDD) ou mais de 11 = null.
     */
    public static function telefone(?string $telefone): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $telefone);
        if (strlen($digitos) >= 12 && str_starts_with($digitos, '55')) {
            $digitos = substr($digitos, 2);
        }

        return strlen($digitos) >= 10 && strlen($digitos) <= 11 ? $digitos : null;
    }
}
