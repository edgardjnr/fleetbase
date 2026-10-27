<?php

namespace App\Support\Entregas;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatMessage;
use Fleetbase\Models\ChatParticipant;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: a conversa do motoboy com a central, aberta pelo botão "Chat" do cartão do cliente nos
 * detalhes do pedido no app (MotoboyController@chatComACentral).
 *
 * O cliente do pedido é a loja (Vendor), que não tem usuário de chat, e o portal da loja não tem chat: por isso o botão
 * fala com a central. Um canal por motoboy, reaproveitado (marcado no `meta`), só com o motoboy e a central.
 *
 * A central é quem abre o console (a regra do BarrarMotoboyNoConsole): usuário `admin`, ou `user` sem cadastro de
 * motoboy, ou quem tem cadastro de motoboy e o papel Administrator na empresa. O `type` sozinho não basta: em produção os
 * motoboys ficam com `type = user` (2026-10-27), e o canal do Junior nasceu com outros motoboys dentro como se fossem a
 * central.
 *
 * O canal se arruma ao abrir e a cada mensagem nova (aoCriarMensagem, no ChatMessage::created): quem entrou na central
 * depois e quem saiu do canal voltam, e quem não é o motoboy dono nem da central sai. Sem isso, a mensagem escrita pela
 * aba Conversas do app (que não passa pelo abrir) caía num canal sem ninguém da central. As mensagens chegam ao console
 * pelo chat do próprio Fleetbase.
 *
 * O canal nasce com o motoboy como criador (o ChatChannel do core o põe como participante). A trava (cache = Redis em
 * produção) evita dois canais com dois toques seguidos e duas arrumações ao mesmo tempo.
 */
class ChatComACentral
{
    /** Chave no `meta` do canal com o uuid do motoboy. */
    public const CHAVE_META = 'entregas_chat_central';

    /** Tipos de usuário que podem ser da central. Loja é `customer`; motoboy pode ser `driver` ou `user` (ver ehDaCentral). */
    public const TIPOS_DA_CENTRAL = ['admin', 'user'];

    /** Papel na empresa que põe na central quem também tem cadastro de motoboy (o mesmo do BarrarMotoboyNoConsole). */
    public const PAPEL_DA_CENTRAL = 'Administrator';

    /** O canal do motoboy com a central, criado ou arrumado agora; null se a empresa não tem ninguém na central. */
    public static function abrir(Driver $motoboy): ?ChatChannel
    {
        $empresa = (string) $motoboy->company_uuid;
        $usuario = (string) $motoboy->user_uuid;
        $central = static::usuariosDaCentral($empresa, $usuario);

        if (!$empresa || !$usuario || empty($central)) {
            return null;
        }

        return Cache::lock(static::trava($motoboy->uuid), 15)->block(10, function () use ($motoboy, $empresa, $usuario, $central) {
            $canal = ChatChannel::where('company_uuid', $empresa)
                ->where('meta->' . static::CHAVE_META, $motoboy->uuid)
                ->first();

            if (!$canal) {
                $canal = ChatChannel::create([
                    'company_uuid'    => $empresa,
                    'created_by_uuid' => $usuario,
                    'name'            => static::nome($motoboy->name),
                    'meta'            => [static::CHAVE_META => $motoboy->uuid],
                ]);
            }

            static::sincronizar($canal, $usuario, $central);

            return $canal->refresh();
        });
    }

    /**
     * Arruma o canal a cada mensagem nova (ChatMessage::created): o notifyParticipants, chamado logo depois pelo core,
     * já avisa quem entrou agora. Só age no canal da central; nunca lança (a mensagem já está gravada).
     */
    public static function aoCriarMensagem(ChatMessage $mensagem): void
    {
        try {
            // outra instância do canal: a relação da mensagem fica sem carregar, e o core lê os participantes já arrumados
            $canal   = ChatChannel::where('uuid', $mensagem->chat_channel_uuid)->first();
            $doMeta  = $canal ? static::motoboyDoMeta($canal->meta) : null;
            $motoboy = $doMeta ? Driver::where('uuid', $doMeta)->first() : null;
            $usuario = (string) ($motoboy?->user_uuid ?? '');

            if (!$canal || $usuario === '') {
                return;
            }

            Cache::lock(static::trava($motoboy->uuid), 15)->block(10, function () use ($canal, $usuario) {
                static::sincronizar($canal, $usuario, static::usuariosDaCentral((string) $canal->company_uuid, $usuario));
            });
        } catch (\Throwable $erro) {
            Log::warning('[entregas] chat com a central: falha ao arrumar os participantes', ['canal' => $mensagem->chat_channel_uuid, 'erro' => $erro->getMessage()]);
        }
    }

    /** Deixa no canal só o motoboy dono e a central: quem falta entra, quem sobra sai (soft delete do participante). */
    public static function sincronizar(ChatChannel $canal, string $usuarioDoMotoboy, array $central): void
    {
        $quemDeveEstar = array_merge([$usuarioDoMotoboy], $central);
        $presentes     = ChatParticipant::where('chat_channel_uuid', $canal->uuid)->get();
        $uuids         = $presentes->pluck('user_uuid')->all();

        foreach (static::quemFalta($quemDeveEstar, $uuids) as $quem) {
            ChatParticipant::create([
                'company_uuid'      => $canal->company_uuid,
                'user_uuid'         => $quem,
                'chat_channel_uuid' => $canal->uuid,
            ]);
        }

        $sobram = static::quemSai($uuids, $quemDeveEstar);
        foreach ($presentes as $participante) {
            if (in_array((string) $participante->user_uuid, $sobram, true)) {
                $participante->delete();
            }
        }
    }

    /** Os uuids da central da empresa (ver ehDaCentral), menos o próprio motoboy. */
    public static function usuariosDaCentral(string $empresa, string $usuarioDoMotoboy): array
    {
        // nenhum model registra o CompanyScope nesta versão: a empresa é filtrada aqui, pela tabela company_users
        $vinculos = CompanyUser::where('company_uuid', $empresa)->get()->keyBy(fn ($vinculo) => (string) $vinculo->user_uuid);

        $usuarios = User::whereIn('uuid', $vinculos->keys()->all())
            ->whereIn('type', static::TIPOS_DA_CENTRAL)
            ->where('uuid', '!=', $usuarioDoMotoboy)
            ->get();

        // o Driver usa SoftDeletes: cadastro de motoboy excluído não conta (como no BarrarMotoboyNoConsole)
        $motoboys = Driver::whereIn('user_uuid', $usuarios->pluck('uuid')->all())
            ->pluck('user_uuid')
            ->map(fn ($uuid) => (string) $uuid)
            ->all();

        return $usuarios
            ->filter(function ($usuario) use ($vinculos, $motoboys) {
                $ehMotoboy = in_array((string) $usuario->uuid, $motoboys, true);
                // o papel só é lido de quem tem cadastro de motoboy
                $administrador = $ehMotoboy && (bool) $vinculos->get((string) $usuario->uuid)?->hasRole(static::PAPEL_DA_CENTRAL);

                return static::ehDaCentral($usuario->type, $ehMotoboy, $administrador);
            })
            ->pluck('uuid')
            ->map(fn ($uuid) => (string) $uuid)
            ->values()
            ->all();
    }

    /**
     * Se o usuário é da central, como no BarrarMotoboyNoConsole: `admin` sempre; loja (`customer`) nunca; `driver` ou quem
     * tem cadastro de motoboy só com o papel Administrator na empresa; `user` sem cadastro de motoboy sim.
     */
    public static function ehDaCentral(?string $tipo, bool $temCadastroDeMotoboy, bool $administradorDaEmpresa): bool
    {
        if ($tipo === 'admin') {
            return true;
        }

        if ($tipo === 'driver' || ($tipo === 'user' && $temCadastroDeMotoboy)) {
            return $administradorDaEmpresa;
        }

        return $tipo === 'user';
    }

    /** O uuid do motoboy dono do canal pelo `meta` (array, objeto ou texto JSON); null se não é canal da central. */
    public static function motoboyDoMeta(mixed $meta): ?string
    {
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }

        $meta    = is_object($meta) ? (array) $meta : $meta;
        $motoboy = is_array($meta) ? ($meta[static::CHAVE_META] ?? null) : null;

        return is_string($motoboy) && $motoboy !== '' ? $motoboy : null;
    }

    /** Nome do canal, como aparece no app e no chat do console. */
    public static function nome(?string $motoboy): string
    {
        $motoboy = trim((string) $motoboy);

        return $motoboy === '' ? 'Central' : "Central · {$motoboy}";
    }

    /** Quem ainda não está no canal, sem repetidos e sem vazios, na ordem dada. */
    public static function quemFalta(array $quemDeveEstar, array $presentes): array
    {
        $presentes = array_map('strval', $presentes);
        $faltam    = [];

        foreach ($quemDeveEstar as $quem) {
            $quem = (string) $quem;

            if ($quem !== '' && !in_array($quem, $presentes, true) && !in_array($quem, $faltam, true)) {
                $faltam[] = $quem;
            }
        }

        return $faltam;
    }

    /** Quem está no canal sem dever estar, sem repetidos e sem vazios, na ordem dada. */
    public static function quemSai(array $presentes, array $quemDeveEstar): array
    {
        $quemDeveEstar = array_map('strval', $quemDeveEstar);
        $saem          = [];

        foreach ($presentes as $quem) {
            $quem = (string) $quem;

            if ($quem !== '' && !in_array($quem, $quemDeveEstar, true) && !in_array($quem, $saem, true)) {
                $saem[] = $quem;
            }
        }

        return $saem;
    }

    protected static function trava(string $motoboy): string
    {
        return "entregas:chat-central:{$motoboy}";
    }
}
