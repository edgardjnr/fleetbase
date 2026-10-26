<?php

namespace App\Support\Entregas\Financeiro;

use App\Support\Entregas\Saques\Saques;
use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: a "Minha carteira" do app do motoboy (GET v1/entregas/motoboy/carteira; desenho em
 * docs/superpowers/specs/2026-10-24-ganhos-e-carteira-design.md). Não depende do período do Início: é a conta inteira.
 *
 * - a_receber: o saldo da conta (ExtratoDaConta::saldo: SUM dos lançamentos, em reais com 2 casas); negativo = adiantamento;
 * - mes / recebido_no_mes: o mês de hoje no fuso da empresa ('AAAA-MM') e a soma dos pagamentos não estornados com `data`
 *   nesse mês;
 * - pagamentos: os 5 mais recentes (data, depois a ordem de registro), estornados incluídos (`estornado: true`), sem
 *   comprovante nem observação interna (o recibo completo é o GET pagamentos/{id});
 * - pix: {chave, tipo} (ChavePix) ou null;
 * - saque: o bloco do saque Pix (Saques::situacaoDoMotoboy), só quando a central liberou o saque.
 */
final class Carteira
{
    public const ULTIMOS_PAGAMENTOS = 5;

    public static function doMotoboy(string $companyUuid, string $motoboyUuid): array
    {
        // poucos por motoboy: a soma do mês e o corte dos 5 ficam aqui, sem SQL de data; só as colunas da resposta
        // (nunca a observação interna nem o caminho do comprovante)
        $pagamentos = DB::table(Pagamentos::TABELA)
            ->select(['id', 'uuid', 'valor', 'data', 'forma', 'estornado_em'])
            ->where('company_uuid', $companyUuid)
            ->where('conta_tipo', Financeiro::CONTA_MOTOBOY)
            ->where('conta_uuid', $motoboyUuid)
            ->orderBy('data', 'desc')
            ->orderBy('id', 'desc')
            ->get()
            ->all();

        $mes      = substr(Financeiro::hoje($companyUuid), 0, 7);
        $recebido = 0;
        foreach ($pagamentos as $pagamento) {
            if (($pagamento->estornado_em ?? null) === null && substr((string) $pagamento->data, 0, 7) === $mes) {
                $recebido += static::centavos($pagamento->valor);
            }
        }

        $carteira = [
            'a_receber'       => ExtratoDaConta::saldo($companyUuid, Financeiro::CONTA_MOTOBOY, $motoboyUuid),
            'mes'             => $mes,
            'recebido_no_mes' => round($recebido / 100, 2),
            'pagamentos'      => array_map(fn ($pagamento) => [
                'id'        => (string) $pagamento->uuid,
                'valor'     => round((float) $pagamento->valor, 2),
                'data'      => substr((string) $pagamento->data, 0, 10),
                'forma'     => (string) $pagamento->forma,
                'estornado' => ($pagamento->estornado_em ?? null) !== null,
            ], array_slice($pagamentos, 0, static::ULTIMOS_PAGAMENTOS)),
            'pix'             => ChavePix::doMotoboy($companyUuid, $motoboyUuid),
        ];
        // saque Pix pelo app: o bloco só vem quando a central liberou o saque (sem ele, o app não mostra o botão)
        $saque = Saques::situacaoDoMotoboy($companyUuid, $motoboyUuid);
        if ($saque !== null) {
            $carteira['saque'] = $saque;
        }

        return $carteira;
    }

    private static function centavos($valor): int
    {
        return (int) round(((float) $valor) * 100);
    }
}
