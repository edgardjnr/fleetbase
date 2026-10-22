<?php

namespace App\Support\Entregas\Sistema;

use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: relatório "Corridas do sistema" de um período (dias em Brasília, até 366): as corridas
 * finalizadas de todas as organizações, por central, com o preço de cada uma pela vigência (PrecosDasCentrais) e os
 * totais. A lista vem cortada em LIMITE_DA_LISTA linhas (lista_cortada: true); os totais contam todas.
 */
final class RelatorioDasCorridas
{
    public const MAX_DIAS = 366;

    public const LIMITE_DA_LISTA = 20000;

    /** As colunas do registro que o relatório lê. */
    public const COLUNAS = ['id', 'company_uuid', 'pedido', 'numero', 'loja_nome', 'motoboy_nome', 'finalizada_em', 'origem'];

    /** {inicio, fim, total, centrais, corridas, lista_cortada} ou a mensagem de erro do período. */
    public static function montar($inicio, $fim, int $limite = self::LIMITE_DA_LISTA): array|string
    {
        $de  = PrecosDasCentrais::data($inicio);
        $ate = PrecosDasCentrais::data($fim);
        if ($de === null || $ate === null) {
            return 'Informe o início e o fim do período.';
        }
        if ($ate < $de) {
            return 'O fim do período precisa ser igual ou depois do início.';
        }
        if ((new \DateTimeImmutable($de))->diff(new \DateTimeImmutable($ate))->days + 1 > static::MAX_DIAS) {
            return 'O período pode ter até 366 dias.';
        }

        $doPeriodo = fn () => DB::table(CorridasFinalizadas::TABELA)
            ->where('finalizada_em', '>=', $de . ' 00:00:00')
            ->where('finalizada_em', '<=', $ate . ' 23:59:59');

        // primeiro só as centrais do período (para os preços e os nomes); depois as corridas uma a uma (cursor), sem
        // montar todas as linhas do período em memória
        $centrais = CorridasFinalizadas::distintos($doPeriodo()->distinct()->pluck('company_uuid')->all());
        $corridas = $doPeriodo()->select(static::COLUNAS)->orderBy('finalizada_em')->orderBy('id')->cursor();

        return ['inicio' => $de, 'fim' => $ate] + static::agregar($corridas, PrecosDasCentrais::porCentral($centrais), PrecosDasCentrais::nomes($centrais), $limite);
    }

    /**
     * Pura: soma as corridas (linhas da tabela, em ordem) com os preços (PrecosDasCentrais::porCentral) e os nomes das
     * centrais. {total: {corridas, a_cobrar, sem_preco}, centrais: [{company, nome, corridas, a_cobrar, sem_preco,
     * precos (valores distintos usados, em ordem)}], corridas: [{finalizada_em (ISO), company, central, pedido, numero,
     * loja_nome, motoboy_nome, preco|null, origem}], lista_cortada}. Contas em centavos.
     */
    public static function agregar(iterable $corridas, array $precos, array $nomes, int $limite = self::LIMITE_DA_LISTA): array
    {
        $total      = ['corridas' => 0, 'a_cobrar' => 0, 'sem_preco' => 0];
        $porCentral = [];
        $lista      = [];

        foreach ($corridas as $corrida) {
            $company  = (string) $corrida->company_uuid;
            $instante = substr((string) $corrida->finalizada_em, 0, 19);
            $preco    = PrecosDasCentrais::precoNoDia($precos[$company] ?? [], substr($instante, 0, 10));
            $centavos = $preco === null ? 0 : (int) round($preco * 100);

            if (!isset($porCentral[$company])) {
                $porCentral[$company] = ['company' => $company, 'nome' => $nomes[$company] ?? substr($company, 0, 8), 'corridas' => 0, 'a_cobrar' => 0, 'sem_preco' => 0, 'precos' => []];
            }
            $porCentral[$company]['corridas']++;
            $porCentral[$company]['a_cobrar'] += $centavos;
            $total['corridas']++;
            $total['a_cobrar'] += $centavos;
            if ($preco === null) {
                $porCentral[$company]['sem_preco']++;
                $total['sem_preco']++;
            } else {
                $porCentral[$company]['precos'][$centavos] = $preco;
            }

            if (count($lista) < $limite) {
                $lista[] = [
                    'finalizada_em' => CorridasFinalizadas::iso($instante),
                    'company'       => $company,
                    'central'       => $porCentral[$company]['nome'],
                    'pedido'        => (string) $corrida->pedido,
                    'numero'        => isset($corrida->numero) ? (string) $corrida->numero : null,
                    'loja_nome'     => isset($corrida->loja_nome) ? (string) $corrida->loja_nome : null,
                    'motoboy_nome'  => isset($corrida->motoboy_nome) ? (string) $corrida->motoboy_nome : null,
                    'preco'         => $preco,
                    'origem'        => (string) $corrida->origem,
                ];
            }
        }

        $centrais = array_map(function (array $central) {
            ksort($central['precos'], SORT_NUMERIC);
            $central['precos']   = array_values($central['precos']);
            $central['a_cobrar'] = round($central['a_cobrar'] / 100, 2);

            return $central;
        }, array_values($porCentral));
        usort($centrais, fn ($a, $b) => PrecosDasCentrais::compararNomes($a['nome'], $b['nome']));
        $total['a_cobrar'] = round($total['a_cobrar'] / 100, 2);

        return ['total' => $total, 'centrais' => $centrais, 'corridas' => $lista, 'lista_cortada' => $total['corridas'] > count($lista)];
    }
}
