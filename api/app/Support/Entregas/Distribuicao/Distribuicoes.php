<?php

namespace App\Support\Entregas\Distribuicao;

use Illuminate\Support\Carbon;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * As tabelas entregas_distribuicoes e entregas_ofertas (query builder, como PedidosIfood). Datas em TIMESTAMP gravadas
 * como texto no fuso do app e lidas nesse fuso (ver "Fuso" no CLAUDE.md). Sem regra de negócio: isso é o Distribuidor.
 * motoboysComOfertaPendente, pendentesVencidasHa, emOfertasHaMais e naoEncerradas são globais de propósito (uma organização só,
 * modelo A; o CompanyScope não está ativo). No MySQL real, encaixe e aproximado voltam como 0/1: quem consome faz (bool).
 */
class Distribuicoes
{
    public const TABELA  = 'entregas_distribuicoes';
    public const OFERTAS = 'entregas_ofertas';

    /** Respostas das linhas sem oferta (em rodadas): gravadas com posicao 0, não contam na ordem das ofertas. */
    public const SEM_OFERTA = [Distribuicao::DISPENSADA, Distribuicao::ACEITA_PELA_LISTA];

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

    /** A última distribuição do pedido, encerrada ou não (o painel mostra o histórico depois do aceite). */
    public static function ultimaDoPedido(string $pedidoUuid): ?object
    {
        return DB::table(static::TABELA)->where('pedido_uuid', $pedidoUuid)->orderBy('id', 'desc')->first();
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
            'despachada_em'     => $agora,
            'fase'              => Distribuicao::FASE_OFERTAS,
            'volta_iniciada_em' => $agora, // volta e rodada: 1 (padrão da coluna)
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

    /**
     * A oferta pendente a um motoboy, vencendo em Distribuicao::segundosDaOferta(). $volta, $rodada e $raioM: em que volta,
     * rodada e raio ela saiu (o ciclo de hoje grava 1, 1 e null).
     *
     * @param array{motoboy_uuid: string, tempo_s: int, encaixe: bool, aproximado: bool} $candidato
     */
    public static function criarOferta(object $distribuicao, array $candidato, int $posicao, int $volta = 1, int $rodada = 1, ?int $raioM = null): object
    {
        $agora = now()->format('Y-m-d H:i:s');
        $vence = static::data($agora)->addSeconds(Distribuicao::segundosDaOferta())->format('Y-m-d H:i:s');
        $id    = DB::table(static::OFERTAS)->insertGetId([
            'distribuicao_id'  => (int) $distribuicao->id,
            'pedido_uuid'      => (string) $distribuicao->pedido_uuid,
            'motoboy_uuid'     => $candidato['motoboy_uuid'],
            'posicao'          => $posicao,
            'volta'            => $volta,
            'rodada'           => $rodada,
            'raio_m'           => $raioM,
            'tempo_estimado_s' => (int) $candidato['tempo_s'],
            'encaixe'          => (bool) $candidato['encaixe'],
            'aproximado'       => (bool) $candidato['aproximado'],
            'oferecida_em'     => $agora,
            'vence_em'         => $vence,
            'resposta'         => Distribuicao::PENDENTE,
            'created_at'       => $agora,
            'updated_at'       => $agora,
        ]);

        return static::oferta($id);
    }

    /**
     * Uma linha sem oferta (em rodadas: `dispensada` pela lista, `aceita_pela_lista`), na volta e rodada atuais da
     * distribuição: oferecida, vencida e respondida agora. Posição 0: não ocupa número na ordem das ofertas.
     */
    public static function registrarResposta(object $distribuicao, string $motoboyUuid, string $resposta, ?int $raioM = null): object
    {
        $agora = static::agora();
        $id    = DB::table(static::OFERTAS)->insertGetId([
            'distribuicao_id'  => (int) $distribuicao->id,
            'pedido_uuid'      => (string) $distribuicao->pedido_uuid,
            'motoboy_uuid'     => $motoboyUuid,
            'posicao'          => 0,
            'volta'            => (int) ($distribuicao->volta ?? 1),
            'rodada'           => (int) ($distribuicao->rodada ?? 1),
            'raio_m'           => $raioM,
            'tempo_estimado_s' => null,
            'encaixe'          => false,
            'aproximado'       => false,
            'oferecida_em'     => $agora,
            'vence_em'         => $agora,
            'resposta'         => $resposta,
            'respondida_em'    => $agora,
            'created_at'       => $agora,
            'updated_at'       => $agora,
        ]);

        return static::oferta($id);
    }

    public static function irParaRodada(int $id, int $rodada): void
    {
        DB::table(static::TABELA)->where('id', $id)->update(['rodada' => $rodada, 'updated_at' => static::agora()]);
    }

    /** Volta nova: rodada 1, iniciada agora. */
    public static function novaVolta(int $id, int $volta): void
    {
        $agora = static::agora();
        DB::table(static::TABELA)->where('id', $id)->update(['volta' => $volta, 'rodada' => 1, 'volta_iniciada_em' => $agora, 'updated_at' => $agora]);
    }

    /** Grava lista_aberta_em, se a lista ainda estava fechada. True se abriu agora. */
    public static function abrirLista(int $id): bool
    {
        $agora = static::agora();

        return DB::table(static::TABELA)->where('id', $id)->whereNull('lista_aberta_em')->update(['lista_aberta_em' => $agora, 'updated_at' => $agora]) > 0;
    }

    /** updated_at agora: a varredura só volta a esta distribuição depois de SEGUNDOS_PARADA. */
    public static function tocar(int $id): void
    {
        DB::table(static::TABELA)->where('id', $id)->update(['updated_at' => static::agora()]);
    }

    /** @return array<string> uuids com qualquer linha na volta (oferta de qualquer resposta, dispensa): fora das ofertas da volta */
    public static function motoboysDaVolta(int $distribuicaoId, int $volta): array
    {
        return array_values(array_unique(DB::table(static::OFERTAS)->where('distribuicao_id', $distribuicaoId)->where('volta', $volta)->pluck('motoboy_uuid')->all()));
    }

    /** @return array<string> uuids que recusaram ou dispensaram na volta: o pedido some da lista aberta deles */
    public static function motoboysQueDispensaramNaVolta(int $distribuicaoId, int $volta): array
    {
        return array_values(array_unique(DB::table(static::OFERTAS)->where('distribuicao_id', $distribuicaoId)->where('volta', $volta)
            ->whereIn('resposta', [Distribuicao::RECUSADA, Distribuicao::DISPENSADA])->pluck('motoboy_uuid')->all()));
    }

    /**
     * Em rodadas: as distribuições em ofertas, sem oferta pendente, paradas (updated_at) há mais de $segundos e
     * despachadas nas últimas 24 h (a varredura avança; as mais antigas ficam com o observador do Order).
     */
    public static function emOfertasParadasHa(int $segundos): array
    {
        $limite = now()->subSeconds($segundos)->format('Y-m-d H:i:s');
        $dia    = now()->subDays(1)->format('Y-m-d H:i:s');
        $linhas = DB::table(static::TABELA)->where('fase', Distribuicao::FASE_OFERTAS)->where('updated_at', '<', $limite)
            ->where('despachada_em', '>=', $dia)->orderBy('id')->get()->all();

        return array_values(array_filter($linhas, fn ($d) => !static::ofertaPendente((int) $d->id)));
    }

    /** Em rodadas: as distribuições em ofertas com a lista aberta da empresa (a lista do app acrescenta as além de R). */
    public static function comListaAberta(string $empresa, int $limite = 50): array
    {
        return DB::table(static::TABELA)->where('company_uuid', $empresa)->where('fase', Distribuicao::FASE_OFERTAS)
            ->whereNotNull('lista_aberta_em')->orderBy('id', 'desc')->limit($limite)->get()->all();
    }

    /**
     * Em rodadas: as distribuições em ofertas da empresa em que o motoboy tem a oferta pendente (a lista do app acrescenta
     * a dele mesmo fora do raio da lista e com a lista fechada). Índice (motoboy_uuid, resposta).
     */
    public static function comOfertaPendenteDo(string $empresa, string $motoboyUuid): array
    {
        $ids = DB::table(static::OFERTAS)->where('motoboy_uuid', $motoboyUuid)->where('resposta', Distribuicao::PENDENTE)->pluck('distribuicao_id')->all();
        if ($ids === []) {
            return [];
        }

        return DB::table(static::TABELA)->where('company_uuid', $empresa)->where('fase', Distribuicao::FASE_OFERTAS)
            ->whereIn('id', array_values(array_unique(array_map('intval', $ids))))->get()->all();
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

    /** A posição da próxima oferta de verdade: as linhas sem oferta (SEM_OFERTA, posicao 0) não contam. */
    public static function proximaPosicao(int $distribuicaoId): int
    {
        return DB::table(static::OFERTAS)->where('distribuicao_id', $distribuicaoId)->whereNotIn('resposta', static::SEM_OFERTA)->count() + 1;
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
        // a última oferta de verdade: as linhas sem oferta (dispensa, aceite pela lista) não tiram a oferta de quem a tem
        $ultima = DB::table(static::OFERTAS)->where('distribuicao_id', $distribuicao->id)
            ->whereNotIn('resposta', [Distribuicao::DISPENSADA, Distribuicao::ACEITA_PELA_LISTA])->orderBy('id', 'desc')->first();
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

    /** As não encerradas despachadas nas últimas 24 h (a varredura não mexe nas mais antigas: o observador as encerra). */
    public static function naoEncerradas(): array
    {
        $limite = now()->subDays(1)->format('Y-m-d H:i:s');

        return DB::table(static::TABELA)->whereIn('fase', [Distribuicao::FASE_OFERTAS, Distribuicao::FASE_ABERTA])->where('despachada_em', '>=', $limite)->orderBy('id')->get()->all();
    }
}
