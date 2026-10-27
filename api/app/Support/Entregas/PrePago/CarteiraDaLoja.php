<?php

namespace App\Support\Entregas\PrePago;

use App\Support\Entregas\CalculoEntregas;
use App\Support\Entregas\Financeiro\ExtratoDaConta;
use App\Support\Entregas\Financeiro\Financeiro;
use App\Support\Entregas\SituacaoDoMotoboy;
use App\Support\Entregas\StatusDoPedido;
use App\Support\Entregas\ValoresCongelados;
use Fleetbase\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: a "Minha carteira" da loja pré-paga. O crédito sai do mesmo livro do Financeiro: a conta da
 * loja soma as corridas (positivas) e os recebimentos (negativos; a recarga é um recebimento Pix), então crédito =
 * −saldo (negativo = a loja deve). Reservado = o valor já congelado dos pedidos da loja ainda em andamento (a corrida só
 * é lançada na conclusão). Disponível = crédito − reservado. O portal só cria pedido com o disponível de pelo menos o
 * menor preço da tabela de km (a entrega mais barata).
 */
final class CarteiraDaLoja
{
    public const SEM_CREDITO = 'Crédito insuficiente. Recarregue a carteira (Minha carteira) para pedir entregas.';

    /**
     * {modelo, credito, reservado, disponivel, minimo_para_pedir, pode_pedir, recarga: {ligada, minimo, maximo}}. Na
     * loja pós-paga, só {modelo: 'pos'}.
     */
    public static function resumo(string $companyUuid, string $lojaUuid): array
    {
        if (!PrePago::ehPrePaga($companyUuid, $lojaUuid)) {
            return ['modelo' => PrePago::POS];
        }
        $credito   = PrePago::centavos(-ExtratoDaConta::saldo($companyUuid, Financeiro::CONTA_LOJA, $lojaUuid));
        $minimo    = static::menorPreco($companyUuid);
        $reservado = static::reservado($companyUuid, $lojaUuid, $minimo);
        $livre     = $credito - $reservado;

        return [
            'modelo'            => PrePago::PRE,
            'credito'           => PrePago::reais($credito),
            'reservado'         => PrePago::reais($reservado),
            'disponivel'        => PrePago::reais($livre),
            'minimo_para_pedir' => PrePago::reais($minimo),
            'pode_pedir'        => $livre > 0 && $livre >= $minimo,
            'recarga'           => ['ligada' => PrePago::ligada(), 'minimo' => PrePago::VALOR_MINIMO, 'maximo' => PrePago::VALOR_MAXIMO],
        ];
    }

    /** A mensagem que barra o pedido novo no portal (RegrasPortalLoja), ou null (pós-pago ou com crédito). */
    public static function motivoDoBloqueio(string $companyUuid, string $lojaUuid): ?string
    {
        $resumo = static::resumo($companyUuid, $lojaUuid);

        return $resumo['modelo'] === PrePago::PRE && !$resumo['pode_pedir'] ? static::SEM_CREDITO : null;
    }

    /**
     * Centavos reservados nos pedidos da loja em andamento (não encerrados e mexidos nas últimas 12 h): o valor congelado
     * (loja) de cada um e, no pedido ainda sem valor (o valor congela na atribuição do motoboy), $porPedidoSemValor
     * centavos (a entrega mais barata). Assim, pedido aberto sem motoboy também segura crédito, e a loja não estoura o
     * crédito criando pedidos em sequência.
     */
    public static function reservado(string $companyUuid, string $lojaUuid, int $porPedidoSemValor = 0): int
    {
        $pedidos = DB::table('orders')
            ->where('company_uuid', $companyUuid)
            ->where('customer_uuid', $lojaUuid)
            ->whereNotIn('status', StatusDoPedido::ENCERRADOS)
            ->where('updated_at', '>=', now()->subHours(SituacaoDoMotoboy::HORAS_PEDIDO_EM_ANDAMENTO)->format('Y-m-d H:i:s'))
            ->whereNull('deleted_at')
            ->pluck('uuid')
            ->all();
        if (!$pedidos) {
            return 0;
        }
        $pedidos    = array_map('strval', $pedidos);
        $congelados = DB::table(ValoresCongelados::TABELA)->whereIn('order_uuid', $pedidos)->pluck('valor_loja', 'order_uuid')->all();
        $centavos   = 0;
        foreach ($pedidos as $pedido) {
            $centavos += array_key_exists($pedido, $congelados) ? PrePago::centavos($congelados[$pedido]) : $porPedidoSemValor;
        }

        return $centavos;
    }

    /**
     * Centavos do menor preço (loja) da tabela de km da empresa; 0 sem tabela. Pela chave inteira (Setting::lookup), sem
     * depender da sessão: o RegrasPortalLoja é middleware global e roda antes de o Fleetbase montar a sessão.
     */
    public static function menorPreco(string $companyUuid): int
    {
        $faixas = Setting::lookup('company.' . $companyUuid . '.' . CalculoEntregas::CHAVE_FAIXAS, []);
        if (is_string($faixas)) {
            $faixas = json_decode($faixas, true);
        }
        $precos = [];
        foreach (is_array($faixas) ? $faixas : [] as $faixa) {
            $loja = PrePago::centavos(is_array($faixa) ? ($faixa['loja'] ?? 0) : ($faixa->loja ?? 0));
            if ($loja > 0) {
                $precos[] = $loja;
            }
        }

        return $precos ? min($precos) : 0;
    }
}
