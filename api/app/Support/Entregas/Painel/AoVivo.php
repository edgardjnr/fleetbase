<?php

namespace App\Support\Entregas\Painel;

use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Distribuicoes;
use App\Support\Entregas\Financeiro\Financeiro;
use App\Support\Entregas\Ifood\PedidosIfood;
use App\Support\Entregas\MapaDoLider;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: seção "Ao vivo" do painel inicial do console (GET int/v1/entregas/painel-inicial/ao-vivo).
 *
 * Os pedidos em andamento e os motoboys do mapa são os do MapaDoLider (mesma lista do mapa do console e da aba Mapa do
 * líder). Acrescenta a cada pedido:
 * - ifood: o pedido veio do iFood (entregas_ifood_pedidos);
 * - oferta: no pedido sem motoboy, a distribuição em andamento (fase, rodada e o vencimento da oferta pendente, em ISO
 *   de Brasília). É null sem distribuição (aguardando a central) ou com ela encerrada.
 * Tudo em lote (3 consultas, qualquer que seja o número de pedidos) e com cache de 5 s por central: várias telas da
 * mesma central dividem a conta. O atribuir do painel chama esquecer().
 *
 * A tela de pedidos (/fleet-ops, sketch 005) usa a mesma resposta e lê também:
 * - motoboys[].corridas_hoje: corridas finalizadas hoje (dia de Brasília) por motoboy (entregas_corridas_finalizadas);
 * - concluidos: {total, ultimos} das corridas finalizadas hoje, os 6 mais recentes primeiro (coluna Concluídos do Quadro).
 * Mais 2 consultas (corridas de hoje e o public_id dos motoboys delas).
 */
final class AoVivo
{
    public const CACHE_S = 5;

    /** @param array|null $mapa o MapaDoLider::mapa já montado (os testes passam um pronto) */
    public static function montar(string $companyUuid, ?array $mapa = null): array
    {
        $chave    = static::chave($companyUuid);
        $guardado = Cache::get($chave);
        if (is_array($guardado)) {
            return $guardado;
        }

        $resposta = static::calcular($companyUuid, $mapa ?? MapaDoLider::mapa($companyUuid));
        Cache::put($chave, $resposta, static::CACHE_S);

        return $resposta;
    }

    public static function esquecer(string $companyUuid): void
    {
        Cache::forget(static::chave($companyUuid));
    }

    private static function chave(string $companyUuid): string
    {
        return "entregas:painel-inicial:ao-vivo:{$companyUuid}";
    }

    private static function calcular(string $co, array $mapa): array
    {
        $pedidos = array_values($mapa['pedidos'] ?? []);
        $ids     = array_values(array_filter(array_map(fn ($pedido) => (string) ($pedido['id'] ?? ''), $pedidos)));

        $uuids = [];
        if ($ids) {
            $linhas = DB::table('orders')->select(['uuid', 'public_id'])->where('company_uuid', $co)->whereIn('public_id', $ids)->get()->all();
            foreach ($linhas as $linha) {
                $uuids[(string) $linha->public_id] = (string) $linha->uuid;
            }
        }

        $doIfood = [];
        if ($uuids) {
            foreach (DB::table(PedidosIfood::TABELA)->whereIn('order_uuid', array_values($uuids))->get()->all() as $linha) {
                $doIfood[(string) $linha->order_uuid] = true;
            }
        }

        $semMotoboy = [];
        foreach ($pedidos as $pedido) {
            $uuid = $uuids[(string) ($pedido['id'] ?? '')] ?? null;
            if ($uuid !== null && empty($pedido['motoboy_id'])) {
                $semMotoboy[] = $uuid;
            }
        }
        $ofertas = static::ofertas($co, $semMotoboy);

        foreach ($pedidos as &$pedido) {
            $uuid            = $uuids[(string) ($pedido['id'] ?? '')] ?? null;
            $pedido['ifood'] = $uuid !== null && isset($doIfood[$uuid]);
            $pedido['oferta'] = ($uuid !== null && empty($pedido['motoboy_id'])) ? ($ofertas[$uuid] ?? null) : null;
        }
        unset($pedido);

        [$porMotoboy, $concluidos] = static::corridasDeHoje($co);
        $motoboys = array_map(function ($motoboy) use ($porMotoboy) {
            $motoboy['corridas_hoje'] = $porMotoboy[(string) ($motoboy['id'] ?? '')] ?? 0;

            return $motoboy;
        }, array_values($mapa['motoboys'] ?? []));

        return ['pedidos' => $pedidos, 'motoboys' => $motoboys, 'concluidos' => $concluidos];
    }

    public const ULTIMOS_CONCLUIDOS = 6;

    /**
     * Corridas finalizadas hoje: a contagem por motoboy (public_id) e os últimos concluídos.
     *
     * @return array{0: array<string, int>, 1: array{total: int, ultimos: array<int, array>}}
     */
    private static function corridasDeHoje(string $co): array
    {
        $hoje   = Financeiro::hoje($co);
        $linhas = DB::table(PainelInicial::TABELA_CORRIDAS)
            ->where('company_uuid', $co)
            ->where('finalizada_em', '>=', $hoje . ' 00:00:00')
            ->where('finalizada_em', '<=', $hoje . ' 23:59:59')
            ->get()
            ->all();

        $uuids = array_values(array_unique(array_filter(array_map(fn ($linha) => (string) ($linha->motoboy_uuid ?? ''), $linhas))));
        $ids   = [];
        if ($uuids) {
            foreach (DB::table('drivers')->select(['uuid', 'public_id'])->whereIn('uuid', $uuids)->get()->all() as $motoboy) {
                $ids[(string) $motoboy->uuid] = (string) $motoboy->public_id;
            }
        }

        $porMotoboy = [];
        foreach ($linhas as $linha) {
            $id = $ids[(string) ($linha->motoboy_uuid ?? '')] ?? null;
            if ($id !== null) {
                $porMotoboy[$id] = ($porMotoboy[$id] ?? 0) + 1;
            }
        }

        usort($linhas, fn ($a, $b) => strcmp((string) $b->finalizada_em, (string) $a->finalizada_em));
        $fuso    = Financeiro::fuso($co);
        $ultimos = array_map(fn ($linha) => [
            'id'            => (string) $linha->pedido,
            'numero'        => $linha->numero !== null && $linha->numero !== '' ? (string) $linha->numero : (string) $linha->pedido,
            'loja'          => $linha->loja_nome,
            'motoboy'       => $linha->motoboy_nome,
            'finalizada_em' => static::iso($linha->finalizada_em ?? null, $fuso),
        ], array_slice($linhas, 0, static::ULTIMOS_CONCLUIDOS));

        return [$porMotoboy, ['total' => count($linhas), 'ultimos' => $ultimos]];
    }

    /**
     * Distribuição aberta (ofertas ou aberta a todos) de cada pedido e o vencimento da oferta pendente: 2 consultas.
     *
     * @return array<string, array{fase: string, rodada: ?int, rodadas: bool, vence_em: ?string}>
     */
    private static function ofertas(string $co, array $pedidoUuids): array
    {
        if (!$pedidoUuids) {
            return [];
        }

        $abertas = DB::table(Distribuicoes::TABELA)
            ->where('company_uuid', $co)
            ->whereIn('pedido_uuid', $pedidoUuids)
            ->whereIn('fase', [Distribuicao::FASE_OFERTAS, Distribuicao::FASE_ABERTA])
            ->get()
            ->all();
        if (!$abertas) {
            return [];
        }

        $pendentes = [];
        $linhas    = DB::table(Distribuicoes::OFERTAS)->whereIn('pedido_uuid', $pedidoUuids)->where('resposta', Distribuicao::PENDENTE)->get()->all();
        foreach ($linhas as $oferta) {
            $pendentes[(string) $oferta->pedido_uuid] = $oferta;
        }

        $fuso    = Financeiro::fuso($co);
        $rodadas = Distribuicao::emRodadas();
        $saida   = [];
        foreach ($abertas as $distribuicao) {
            $uuid         = (string) $distribuicao->pedido_uuid;
            $pendente     = $pendentes[$uuid] ?? null;
            $saida[$uuid] = [
                'fase'     => (string) $distribuicao->fase,
                'rodada'   => isset($distribuicao->rodada) ? (int) $distribuicao->rodada : null,
                'rodadas'  => $rodadas,
                'vence_em' => $pendente ? static::iso($pendente->vence_em ?? null, $fuso) : null,
            ];
        }

        return $saida;
    }

    /** TIMESTAMP já em Brasília → ISO com o fuso ('2026-10-24T21:14:40-03:00'); null se vazio. */
    private static function iso($valor, string $fuso): ?string
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format(DATE_ATOM);
        }
        if (!is_string($valor) || $valor === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($valor, new DateTimeZone($fuso)))->format(DATE_ATOM);
        } catch (\Exception) {
            return null;
        }
    }
}
