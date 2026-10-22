<?php

namespace App\Support\Entregas\Sistema;

use App\Listeners\Entregas\ObservadorFinanceiroDosPedidos;
use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: registro permanente das corridas finalizadas de todas as organizações (centrais), a base do
 * relatório do dono do sistema (Fleet-Ops → Financeiro → Corridas do sistema). Uma linha por pedido
 * (entregas_corridas_finalizadas, order_uuid único): entra quando o pedido fica concluído e nunca muda nem sai
 * (cancelar, excluir o pedido ou o deploy/zerar-pedidos não tiram). Nomes da loja e do motoboy gravados na hora.
 *
 * Quem grava: o ObservadorDasCorridasDoSistema (Order::updated, origem `conclusao`, hora = agora), o
 * entregas:corridas-conferir a cada 5 min (últimos 2 dias, origem `conferencia`) e, enquanto a marca
 * `entregas.sistema.historico_importado` não existir (ou com --historico), tudo o que está no banco (origem `historico`). Gravar é idempotente (insertOrIgnore pelo order_uuid): fica a primeira.
 *
 * Lê pelo DB::table (sem os escopos do Eloquent): o pedido excluído (deleted_at) também entra. Horários em Brasília,
 * texto 'Y-m-d H:i:s' (ver "Fuso" em api/CLAUDE.md). Spec: docs/superpowers/specs/2026-10-22-corridas-do-sistema-design.md.
 */
final class CorridasFinalizadas
{
    public const TABELA = 'entregas_corridas_finalizadas';

    /** Status de pedido finalizado: os mesmos do observador do financeiro. */
    public const CONCLUIDOS = ObservadorFinanceiroDosPedidos::CONCLUIDOS;

    public const CONCLUSAO   = 'conclusao';
    public const CONFERENCIA = 'conferencia';
    public const HISTORICO   = 'historico';

    /** Pedidos por consulta (whereIn e insertOrIgnore) e por lote do histórico. */
    public const LOTE = 500;

    /** As colunas de orders que o registro lê (o id pagina o histórico); o resto do pedido não vem do banco. */
    public const COLUNAS_DO_PEDIDO = ['id', 'uuid', 'company_uuid', 'public_id', 'internal_id', 'customer_uuid', 'driver_assigned_uuid', 'tracking_number_uuid', 'updated_at'];

    /** Grava a corrida de um pedido (o observador passa a hora da conclusão). true se gravou agora. */
    public static function registrarPedido(object $pedido, string $origem, ?string $finalizadaEm = null): bool
    {
        return static::registrarLote([$pedido], $origem, $finalizadaEm) > 0;
    }

    /**
     * Grava as corridas dos pedidos (linhas de orders ou models) que ainda não têm linha. finalizada_em: $finalizadaEm;
     * sem ela, o primeiro COMPLETED do rastreio; sem ele, o updated_at do pedido. Devolve quantas linhas gravou.
     */
    public static function registrarLote(array $pedidos, string $origem, ?string $finalizadaEm = null): int
    {
        $porUuid = [];
        foreach ($pedidos as $pedido) {
            $uuid = (string) ($pedido->uuid ?? '');
            if ($uuid !== '' && (string) ($pedido->company_uuid ?? '') !== '') {
                $porUuid[$uuid] = $pedido;
            }
        }

        $gravadas = 0;
        foreach (array_chunk($porUuid, static::LOTE, true) as $lote) {
            $jaTem = DB::table(static::TABELA)->whereIn('order_uuid', array_keys($lote))->pluck('order_uuid')->all();
            $lote  = array_diff_key($lote, array_flip(array_map('strval', $jaTem)));
            if ($lote === []) {
                continue;
            }

            $conclusoes = $finalizadaEm === null ? static::conclusoes($lote) : [];
            $lojas      = static::nomesDasLojas($lote);
            $motoboys   = static::nomesDosMotoboys($lote);
            $agora      = now()->format('Y-m-d H:i:s');
            $linhas     = [];
            foreach ($lote as $uuid => $pedido) {
                $loja     = (string) ($pedido->customer_uuid ?? '');
                $motoboy  = (string) ($pedido->driver_assigned_uuid ?? '');
                $rastreio = (string) ($pedido->tracking_number_uuid ?? '');
                $linhas[] = [
                    'order_uuid'    => (string) $uuid,
                    'company_uuid'  => (string) $pedido->company_uuid,
                    'pedido'        => mb_substr((string) (($pedido->public_id ?? '') ?: $uuid), 0, 64),
                    'numero'        => static::curto($pedido->internal_id ?? null, 191),
                    'loja_uuid'     => isset($lojas[$loja]) ? $loja : null,
                    'loja_nome'     => isset($lojas[$loja]) ? static::curto($lojas[$loja], 191) : null,
                    'motoboy_uuid'  => $motoboy !== '' ? $motoboy : null,
                    'motoboy_nome'  => static::curto($motoboys[$motoboy] ?? null, 191),
                    'finalizada_em' => $finalizadaEm ?? $conclusoes[$rastreio] ?? static::texto($pedido->updated_at ?? null) ?? $agora,
                    'origem'        => $origem,
                    'created_at'    => $agora,
                ];
            }
            $gravadas += DB::table(static::TABELA)->insertOrIgnore($linhas);
        }

        return $gravadas;
    }

    /**
     * Grava as corridas que faltam desde o instante ('Y-m-d H:i:s' de Brasília): pedidos com status concluído
     * atualizados desde então e pedidos com COMPLETED no rastreio desde então (inclusive cancelados depois), de todas as
     * organizações, excluídos também. Origem `conferencia`. Devolve quantas gravou.
     */
    public static function conferir(string $desde): int
    {
        $pedidos = DB::table('orders')->select(static::COLUNAS_DO_PEDIDO)->whereIn('status', static::CONCLUIDOS)->where('updated_at', '>=', $desde)->get()->all();

        $rastreios = DB::table('tracking_statuses')->where('code', 'COMPLETED')->where('created_at', '>=', $desde)->pluck('tracking_number_uuid')->all();
        foreach (array_chunk(static::distintos($rastreios), static::LOTE) as $lote) {
            array_push($pedidos, ...DB::table('orders')->select(static::COLUNAS_DO_PEDIDO)->whereIn('tracking_number_uuid', $lote)->get()->all());
        }

        return static::registrarLote($pedidos, static::CONFERENCIA);
    }

    /**
     * Tudo o que está no banco, sem limite de data, em lotes pelo id: os pedidos com status concluído e os que têm
     * COMPLETED no rastreio (cancelados ou reabertos depois). Origem `historico`. Idempotente. Devolve quantas gravou.
     */
    public static function importarHistorico(int $lote = self::LOTE): int
    {
        $gravadas = 0;

        $ultimo = 0;
        do {
            $pedidos = DB::table('orders')->select(static::COLUNAS_DO_PEDIDO)->whereIn('status', static::CONCLUIDOS)->where('id', '>', $ultimo)->orderBy('id')->limit($lote)->get()->all();
            if ($pedidos !== []) {
                $ultimo = (int) $pedidos[count($pedidos) - 1]->id;
                $gravadas += static::registrarLote($pedidos, static::HISTORICO);
            }
        } while (count($pedidos) === $lote);

        $ultimo = 0;
        do {
            $status = DB::table('tracking_statuses')->select(['id', 'tracking_number_uuid'])->where('code', 'COMPLETED')->where('id', '>', $ultimo)->orderBy('id')->limit($lote)->get()->all();
            if ($status !== []) {
                $ultimo    = (int) $status[count($status) - 1]->id;
                $rastreios = static::distintos(array_map(fn ($linha) => $linha->tracking_number_uuid ?? null, $status));
                if ($rastreios !== []) {
                    $gravadas += static::registrarLote(DB::table('orders')->select(static::COLUNAS_DO_PEDIDO)->whereIn('tracking_number_uuid', $rastreios)->get()->all(), static::HISTORICO);
                }
            }
        } while (count($status) === $lote);

        return $gravadas;
    }

    /** 'Y-m-d H:i:s' de Brasília → ISO com o fuso ('2026-10-22T09:31:00-03:00'). */
    public static function iso(string $instante): string
    {
        return (new \DateTimeImmutable($instante, new \DateTimeZone(date_default_timezone_get())))->format('Y-m-d\TH:i:sP');
    }

    /** Data do banco (texto em Brasília) ou DateTime (convertido para o fuso do app) → 'Y-m-d H:i:s'; vazio: null. */
    public static function texto($valor): ?string
    {
        if ($valor instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($valor)->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        }
        $texto = trim((string) ($valor ?? ''));

        return $texto === '' ? null : substr($texto, 0, 19);
    }

    /** Os valores não vazios, como texto, sem repetir. */
    public static function distintos(array $valores): array
    {
        $textos = array_map(fn ($valor) => $valor === null ? '' : (string) $valor, $valores);

        return array_values(array_unique(array_filter($textos, fn ($valor) => $valor !== '')));
    }

    /** [tracking_number_uuid => primeiro created_at do COMPLETED], inclusive status excluídos (pedido excluído inteiro). */
    protected static function conclusoes(array $pedidos): array
    {
        $rastreios = static::distintos(array_map(fn ($pedido) => $pedido->tracking_number_uuid ?? null, $pedidos));
        if ($rastreios === []) {
            return [];
        }

        $primeiras = [];
        foreach (DB::table('tracking_statuses')->select(['tracking_number_uuid', 'created_at'])->where('code', 'COMPLETED')->whereIn('tracking_number_uuid', $rastreios)->get() as $status) {
            $rastreio = (string) $status->tracking_number_uuid;
            $quando   = static::texto($status->created_at ?? null);
            if ($quando !== null && (!isset($primeiras[$rastreio]) || $quando < $primeiras[$rastreio])) {
                $primeiras[$rastreio] = $quando;
            }
        }

        return $primeiras;
    }

    /** [vendors.uuid => nome] dos clientes dos pedidos que são lojas (inclusive excluídas). */
    protected static function nomesDasLojas(array $pedidos): array
    {
        $uuids = static::distintos(array_map(fn ($pedido) => $pedido->customer_uuid ?? null, $pedidos));

        return $uuids === [] ? [] : array_map('strval', DB::table('vendors')->whereIn('uuid', $uuids)->pluck('name', 'uuid')->all());
    }

    /** [drivers.uuid => nome do usuário] dos motoboys dos pedidos (o nome do Driver é o do User). */
    protected static function nomesDosMotoboys(array $pedidos): array
    {
        $uuids = static::distintos(array_map(fn ($pedido) => $pedido->driver_assigned_uuid ?? null, $pedidos));
        if ($uuids === []) {
            return [];
        }

        $usuarios = DB::table('drivers')->whereIn('uuid', $uuids)->pluck('user_uuid', 'uuid')->all();
        $doUser   = static::distintos(array_values($usuarios));
        $nomes    = $doUser === [] ? [] : DB::table('users')->whereIn('uuid', $doUser)->pluck('name', 'uuid')->all();

        $saida = [];
        foreach ($usuarios as $motoboy => $usuario) {
            if (isset($nomes[(string) $usuario])) {
                $saida[(string) $motoboy] = (string) $nomes[(string) $usuario];
            }
        }

        return $saida;
    }

    protected static function curto($valor, int $tamanho): ?string
    {
        $texto = trim((string) ($valor ?? ''));

        return $texto === '' ? null : mb_substr($texto, 0, $tamanho);
    }
}
