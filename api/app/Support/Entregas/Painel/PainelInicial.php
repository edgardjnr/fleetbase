<?php

namespace App\Support\Entregas\Painel;

use App\Support\Entregas\Financeiro\Financeiro;
use App\Support\Entregas\Financeiro\Lancamentos;
use App\Support\Entregas\Financeiro\PainelFinanceiro;
use App\Support\Entregas\Ifood\VinculosIfood;
use App\Support\Entregas\PrazoDaEntrega;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: resumo do período do painel inicial do console (GET int/v1/entregas/painel-inicial).
 *
 * - Corridas, por hora/dia e rankings: entregas_corridas_finalizadas (tem a hora; independe da chave do Financeiro).
 * - Dinheiro e km: lançamentos tipo corrida não estornados (em centavos), só com o Financeiro ligado; dinheiro só para
 *   admin (null para os outros).
 * - No prazo e tempo médio: orders das corridas (aceite = started_at ?: dispatched_at; prazo = meta.entregas_prazo_em).
 *   Pedido sem prazo (antes de 2026-10-20) fica fora do %.
 * - Atenção: a lista do PainelFinanceiro (só admin), com cache de 5 min.
 * Cache do resumo inteiro: 60 s por central + período + admin (várias telas abertas dividem a conta).
 */
final class PainelInicial
{
    public const TABELA_CORRIDAS   = 'entregas_corridas_finalizadas';
    public const LIMITE_RANKING    = 6;
    public const SEMANAS_DA_MEDIA  = 4;
    public const LOTE              = 500;
    public const CACHE_RESUMO_S    = 60;
    public const CACHE_ATENCAO_S   = 300;

    public static function montar(string $companyUuid, string $inicio, string $fim, bool $admin): array
    {
        $chave = "entregas:painel-inicial:resumo:{$companyUuid}:{$inicio}:{$fim}:" . ($admin ? '1' : '0');
        $guardado = Cache::get($chave);
        if (is_array($guardado)) {
            return $guardado;
        }

        $resumo = static::calcular($companyUuid, $inicio, $fim, $admin);
        Cache::put($chave, $resumo, static::CACHE_RESUMO_S);

        return $resumo;
    }

    private static function calcular(string $co, string $inicio, string $fim, bool $admin): array
    {
        $corridas              = static::corridas($co, $inicio, $fim);
        [$deAntes, $ateAntes]  = AgregadorDoPainel::periodoAnterior($inicio, $fim);
        $anterior              = count(static::corridas($co, $deAntes, $ateAntes));
        $instantes             = array_map(fn ($corrida) => (string) $corrida->finalizada_em, $corridas);
        $livro                 = Financeiro::ligada() ? static::livro($co, $inicio, $fim) : null;
        $dinheiro              = $admin && $livro !== null;

        $lojas = array_slice(AgregadorDoPainel::ranking($corridas, 'loja_uuid', 'loja_nome'), 0, static::LIMITE_RANKING);
        $ifood = VinculosIfood::resumos(array_column($lojas, 'uuid'));
        $motoboys = array_slice(AgregadorDoPainel::ranking($corridas, 'motoboy_uuid', 'motoboy_nome'), 0, static::LIMITE_RANKING);

        return array_merge([
            'inicio'            => $inicio,
            'fim'               => $fim,
            'corridas'          => count($corridas),
            'corridas_anterior' => $anterior,
            'variacao'          => AgregadorDoPainel::variacao(count($corridas), $anterior),
        ], AgregadorDoPainel::prazos(static::pedidosParaPrazo($co, $corridas), Financeiro::fuso($co)), [
            'km'       => $livro['km'] ?? null,
            'faturado' => $dinheiro ? $livro['faturado'] : null,
            'a_pagar'  => $dinheiro ? $livro['a_pagar'] : null,
            'margem'   => $dinheiro ? round($livro['faturado'] - $livro['a_pagar'], 2) : null,
            'grafico'  => static::grafico($co, $inicio, $fim, $instantes),
            'lojas'    => array_map(fn (array $loja) => $loja + [
                'faturado' => $dinheiro ? round($livro['por_loja'][$loja['uuid']] ?? 0, 2) : null,
                'ifood'    => $ifood[$loja['uuid']]['situacao'] ?? null,
            ], $lojas),
            'motoboys' => array_map(fn (array $motoboy) => $motoboy + [
                'ganhos' => $dinheiro ? round($livro['por_motoboy'][$motoboy['uuid']] ?? 0, 2) : null,
            ], $motoboys),
            'atencao'  => $admin ? static::atencao($co, $inicio, $fim) : [],
        ]);
    }

    /** Corridas finalizadas da central no período (dias em Brasília). */
    private static function corridas(string $co, string $inicio, string $fim): array
    {
        return DB::table(static::TABELA_CORRIDAS)
            ->where('company_uuid', $co)
            ->where('finalizada_em', '>=', $inicio . ' 00:00:00')
            ->where('finalizada_em', '<=', $fim . ' 23:59:59')
            ->get()
            ->all();
    }

    /** Somas das corridas no livro do Financeiro (centavos → reais), por conta, e o km dos motoboys. */
    private static function livro(string $co, string $inicio, string $fim): array
    {
        $linhas = DB::table(Lancamentos::TABELA)
            ->where('company_uuid', $co)
            ->where('tipo', 'corrida')
            ->where('data_referencia', '>=', $inicio)
            ->where('data_referencia', '<=', $fim)
            ->get()
            ->all();
        $estornados = array_flip(Lancamentos::jaEstornados(array_map(fn ($linha) => (int) $linha->id, $linhas)));

        $centavos = [Financeiro::CONTA_LOJA => [], Financeiro::CONTA_MOTOBOY => []];
        $km       = 0.0;
        foreach ($linhas as $linha) {
            $tipo = (string) $linha->conta_tipo;
            if (isset($estornados[(int) $linha->id]) || !isset($centavos[$tipo])) {
                continue;
            }
            $conta                    = (string) $linha->conta_uuid;
            $centavos[$tipo][$conta]  = ($centavos[$tipo][$conta] ?? 0) + (int) round((float) $linha->valor * 100);
            if ($tipo === Financeiro::CONTA_MOTOBOY) {
                $detalhes = is_string($linha->detalhes) ? json_decode($linha->detalhes, true) : (array) $linha->detalhes;
                $km      += (float) ($detalhes['km'] ?? 0);
            }
        }

        return [
            'faturado'    => array_sum($centavos[Financeiro::CONTA_LOJA]) / 100,
            'a_pagar'     => array_sum($centavos[Financeiro::CONTA_MOTOBOY]) / 100,
            'km'          => round($km, 1),
            'por_loja'    => array_map(fn (int $c) => $c / 100, $centavos[Financeiro::CONTA_LOJA]),
            'por_motoboy' => array_map(fn (int $c) => $c / 100, $centavos[Financeiro::CONTA_MOTOBOY]),
        ];
    }

    /** Um dia: por hora, com a média do mesmo dia da semana nas 4 semanas antes. Vários dias: por dia, sem média. */
    private static function grafico(string $co, string $inicio, string $fim, array $instantes): array
    {
        if ($inicio !== $fim) {
            return ['tipo' => 'dia', 'barras' => array_map(
                fn (array $dia) => ['rotulo' => $dia['dia'], 'total' => $dia['total'], 'media' => null],
                AgregadorDoPainel::porDia($instantes, $inicio, $fim)
            )];
        }

        $antes = [];
        for ($semana = 1; $semana <= static::SEMANAS_DA_MEDIA; $semana++) {
            $dia = (new DateTimeImmutable($inicio))->modify('-' . (7 * $semana) . ' days')->format('Y-m-d');
            foreach (static::corridas($co, $dia, $dia) as $corrida) {
                $antes[] = (string) $corrida->finalizada_em;
            }
        }
        $hoje  = AgregadorDoPainel::porHora($instantes);
        $media = AgregadorDoPainel::mediaPorHora($antes, static::SEMANAS_DA_MEDIA);

        return ['tipo' => 'hora', 'barras' => array_map(
            fn (int $hora) => ['rotulo' => sprintf('%02d', $hora), 'total' => $hoje[$hora], 'media' => $media[$hora]],
            range(0, 23)
        )];
    }

    /** Conclusão, aceite e prazo dos pedidos das corridas (só as colunas usadas, em lotes). */
    private static function pedidosParaPrazo(string $co, array $corridas): array
    {
        $concluidoEm = [];
        foreach ($corridas as $corrida) {
            $concluidoEm[(string) $corrida->order_uuid] = (string) $corrida->finalizada_em;
        }

        $pedidos = [];
        foreach (array_chunk(array_keys($concluidoEm), static::LOTE) as $lote) {
            $linhas = DB::table('orders')
                ->select(['uuid', 'started_at', 'dispatched_at', 'meta'])
                ->where('company_uuid', $co)
                ->whereIn('uuid', $lote)
                ->get()
                ->all();
            foreach ($linhas as $pedido) {
                $pedidos[] = [
                    'concluido_em' => $concluidoEm[(string) $pedido->uuid] ?? null,
                    'aceito_em'    => static::texto($pedido->started_at ?? null) ?? static::texto($pedido->dispatched_at ?? null),
                    'prazo_em'     => PrazoDaEntrega::daMeta($pedido->meta ?? null),
                ];
            }
        }

        return $pedidos;
    }

    private static function texto($valor): ?string
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d H:i:s');
        }

        return is_string($valor) && $valor !== '' ? $valor : null;
    }

    /** A lista Atenção do Financeiro, com cache de 5 min (a conta do "sem valor" é a mais pesada do painel). */
    private static function atencao(string $co, string $inicio, string $fim): array
    {
        $chave    = "entregas:painel-inicial:atencao:{$co}";
        $guardado = Cache::get($chave);
        if (is_array($guardado)) {
            return $guardado;
        }

        try {
            $lista = PainelFinanceiro::montar($co, $inicio, $fim)['atencao'] ?? [];
        } catch (\Throwable $erro) {
            Log::warning('[entregas] painel inicial: sem a lista Atenção', ['erro' => $erro->getMessage()]);

            return [];
        }
        Cache::put($chave, $lista, static::CACHE_ATENCAO_S);

        return $lista;
    }
}
