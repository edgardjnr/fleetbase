<?php

namespace App\Support\Entregas\Distribuicao;

use Illuminate\Support\Carbon;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * As tabelas entregas_distribuicoes e entregas_ofertas (query builder, como PedidosIfood). Datas em TIMESTAMP gravadas
 * como texto no fuso do app e lidas nesse fuso (ver "Fuso" no CLAUDE.md). Sem regra de negócio: isso é o Distribuidor.
 */
class Distribuicoes
{
    public const TABELA  = 'entregas_distribuicoes';
    public const OFERTAS = 'entregas_ofertas';

    public static function agora(): string
    {
        return now()->format('Y-m-d H:i:s');
    }

    public static function data(?string $texto): ?Carbon
    {
        return $texto ? Carbon::parse(substr($texto, 0, 19), date_default_timezone_get()) : null;
    }

    /** A distribuição não encerrada do pedido (ofertas ou aberta), ou null. */
    public static function doPedido(string $pedidoUuid): ?object
    {
        return DB::table(static::TABELA)->where('pedido_uuid', $pedidoUuid)->whereIn('fase', [Distribuicao::FASE_OFERTAS, Distribuicao::FASE_ABERTA])->orderBy('id', 'desc')->first();
    }

    public static function porId(int $id): ?object
    {
        return DB::table(static::TABELA)->where('id', $id)->first();
    }

    public static function emOfertas(string $pedidoUuid): bool
    {
        return DB::table(static::TABELA)->where('pedido_uuid', $pedidoUuid)->where('fase', Distribuicao::FASE_OFERTAS)->exists();
    }

    public static function criar(Order $pedido): object
    {
        $agora = static::agora();
        $id    = DB::table(static::TABELA)->insertGetId([
            'pedido_uuid'   => (string) $pedido->uuid,
            'company_uuid'  => (string) $pedido->company_uuid,
            'despachada_em' => $agora,
            'fase'          => Distribuicao::FASE_OFERTAS,
            'created_at'    => $agora,
            'updated_at'    => $agora,
        ]);

        return static::porId($id);
    }

    public static function mudarFase(int $id, string $fase, string $motivo): void
    {
        $agora  = static::agora();
        $campos = ['fase' => $fase, 'motivo' => $motivo, 'updated_at' => $agora];
        if ($fase === Distribuicao::FASE_ABERTA) {
            $campos['aberta_em'] = $agora;
        }
        if ($fase === Distribuicao::FASE_ENCERRADA) {
            $campos['encerrada_em'] = $agora;
        }
        DB::table(static::TABELA)->where('id', $id)->update($campos);
    }

    public static function gravarFila(int $id, array $fila): void
    {
        DB::table(static::TABELA)->where('id', $id)->update(['fila' => json_encode($fila, JSON_UNESCAPED_UNICODE), 'updated_at' => static::agora()]);
    }

    /** @param array{motoboy_uuid: string, tempo_s: int, encaixe: bool, aproximado: bool} $candidato */
    public static function criarOferta(object $distribuicao, array $candidato, int $posicao): object
    {
        $agora = now();
        $id    = DB::table(static::OFERTAS)->insertGetId([
            'distribuicao_id'  => (int) $distribuicao->id,
            'pedido_uuid'      => (string) $distribuicao->pedido_uuid,
            'motoboy_uuid'     => $candidato['motoboy_uuid'],
            'posicao'          => $posicao,
            'tempo_estimado_s' => (int) $candidato['tempo_s'],
            'encaixe'          => (bool) $candidato['encaixe'],
            'aproximado'       => (bool) $candidato['aproximado'],
            'oferecida_em'     => $agora->format('Y-m-d H:i:s'),
            'vence_em'         => now()->addSeconds(Distribuicao::SEGUNDOS_DA_OFERTA)->format('Y-m-d H:i:s'),
            'resposta'         => Distribuicao::PENDENTE,
            'created_at'       => $agora->format('Y-m-d H:i:s'),
            'updated_at'       => $agora->format('Y-m-d H:i:s'),
        ]);

        return static::oferta($id);
    }

    public static function oferta(int $id): ?object
    {
        return DB::table(static::OFERTAS)->where('id', $id)->first();
    }

    public static function ofertaPendente(int $distribuicaoId): ?object
    {
        return DB::table(static::OFERTAS)->where('distribuicao_id', $distribuicaoId)->where('resposta', Distribuicao::PENDENTE)->orderBy('id', 'desc')->first();
    }

    /** As ofertas da distribuição, na ordem (o histórico do painel). */
    public static function ofertas(int $distribuicaoId): array
    {
        return DB::table(static::OFERTAS)->where('distribuicao_id', $distribuicaoId)->orderBy('id')->get()->all();
    }

    public static function responder(int $ofertaId, string $resposta): void
    {
        DB::table(static::OFERTAS)->where('id', $ofertaId)->update(['resposta' => $resposta, 'respondida_em' => static::agora(), 'updated_at' => static::agora()]);
    }

    /** Marca canceladas as pendentes da distribuição; devolve quantas. */
    public static function cancelarPendentes(int $distribuicaoId): int
    {
        return DB::table(static::OFERTAS)->where('distribuicao_id', $distribuicaoId)->where('resposta', Distribuicao::PENDENTE)
            ->update(['resposta' => Distribuicao::CANCELADA, 'respondida_em' => static::agora(), 'updated_at' => static::agora()]);
    }

    /** @return array<string> uuids de quem recusou ou deixou vencer nesta distribuição */
    public static function motoboysQueResponderam(int $distribuicaoId): array
    {
        return array_values(array_unique(DB::table(static::OFERTAS)->where('distribuicao_id', $distribuicaoId)->whereIn('resposta', [Distribuicao::RECUSADA, Distribuicao::VENCIDA])->pluck('motoboy_uuid')->all()));
    }

    /** @return array<string> uuids com oferta pendente em qualquer pedido */
    public static function motoboysComOfertaPendente(): array
    {
        return array_values(array_unique(DB::table(static::OFERTAS)->where('resposta', Distribuicao::PENDENTE)->pluck('motoboy_uuid')->all()));
    }

    /**
     * A oferta que autoriza o motoboy a aceitar o pedido: a pendente dele ou, se a dele venceu, a vencida enquanto ninguém
     * foi oferecido depois (o job venceu antes do toque chegar). Null = não pode.
     */
    public static function ofertaParaAceite(string $pedidoUuid, string $motoboyUuid): ?object
    {
        $distribuicao = static::doPedido($pedidoUuid);
        if (!$distribuicao || $distribuicao->fase !== Distribuicao::FASE_OFERTAS) {
            return null;
        }
        $ultima = DB::table(static::OFERTAS)->where('distribuicao_id', $distribuicao->id)->orderBy('id', 'desc')->first();
        if (!$ultima || (string) $ultima->motoboy_uuid !== $motoboyUuid) {
            return null;
        }

        return in_array($ultima->resposta, [Distribuicao::PENDENTE, Distribuicao::VENCIDA], true) ? $ultima : null;
    }

    /** Pendentes cujo vence_em passou há mais de $folga segundos (o job não veio). */
    public static function pendentesVencidasHa(int $folga): array
    {
        $limite = now()->subSeconds($folga)->format('Y-m-d H:i:s');

        return DB::table(static::OFERTAS)->where('resposta', Distribuicao::PENDENTE)->where('vence_em', '<', $limite)->orderBy('id')->get()->all();
    }

    public static function emOfertasHaMais(int $minutos): array
    {
        $limite = now()->subMinutes($minutos)->format('Y-m-d H:i:s');

        return DB::table(static::TABELA)->where('fase', Distribuicao::FASE_OFERTAS)->where('despachada_em', '<', $limite)->orderBy('id')->get()->all();
    }

    public static function naoEncerradas(): array
    {
        return DB::table(static::TABELA)->whereIn('fase', [Distribuicao::FASE_OFERTAS, Distribuicao::FASE_ABERTA])->orderBy('id')->get()->all();
    }
}
