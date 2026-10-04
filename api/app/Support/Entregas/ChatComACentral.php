<?php

namespace App\Support\Entregas;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatParticipant;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Entregas RestaurantePro: a conversa do motoboy com a central, aberta pelo botão "Chat" do cartão do cliente nos
 * detalhes do pedido no app (MotoboyController@chatComACentral).
 *
 * O cliente do pedido é a loja (Vendor), que não tem usuário de chat, e o portal da loja não tem chat: por isso o botão
 * fala com a central. Um canal por motoboy, reaproveitado (marcado no `meta`), com o motoboy e os usuários do console
 * da empresa (tipo `admin` ou `user`; ficam de fora motoboys e lojas). Quem entrou na central depois é incluído na
 * próxima vez que o motoboy abre a conversa, e quem saiu do canal volta. As mensagens chegam ao console pelo chat do
 * próprio Fleetbase.
 *
 * O canal nasce com o motoboy como criador (o ChatChannel do core o põe como participante). A trava (cache = Redis em
 * produção) evita dois canais com dois toques seguidos.
 */
class ChatComACentral
{
    /** Chave no `meta` do canal com o uuid do motoboy. */
    public const CHAVE_META = 'entregas_chat_central';

    /** Tipos de usuário da central (console). Motoboy é `driver` e usuário de loja é `customer`. */
    public const TIPOS_DA_CENTRAL = ['admin', 'user'];

    /** O canal do motoboy com a central, criado ou completado agora; null se a empresa não tem ninguém na central. */
    public static function abrir(Driver $motoboy): ?ChatChannel
    {
        $empresa = (string) $motoboy->company_uuid;
        $usuario = (string) $motoboy->user_uuid;
        $central = static::usuariosDaCentral($empresa, $usuario);

        if (!$empresa || !$usuario || empty($central)) {
            return null;
        }

        return Cache::lock("entregas:chat-central:{$motoboy->uuid}", 15)->block(10, function () use ($motoboy, $empresa, $usuario, $central) {
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

            $presentes = ChatParticipant::where('chat_channel_uuid', $canal->uuid)->pluck('user_uuid')->all();

            foreach (static::quemFalta(array_merge([$usuario], $central), $presentes) as $quem) {
                ChatParticipant::create([
                    'company_uuid'      => $empresa,
                    'user_uuid'         => $quem,
                    'chat_channel_uuid' => $canal->uuid,
                ]);
            }

            return $canal->refresh();
        });
    }

    /** Os uuids dos usuários do console da empresa, menos o próprio motoboy (um admin pode ter cadastro de motoboy). */
    public static function usuariosDaCentral(string $empresa, string $usuarioDoMotoboy): array
    {
        // nenhum model registra o CompanyScope nesta versão: a empresa é filtrada aqui, pela tabela company_users
        $daEmpresa = CompanyUser::where('company_uuid', $empresa)->pluck('user_uuid');

        return User::whereIn('uuid', $daEmpresa)
            ->whereIn('type', static::TIPOS_DA_CENTRAL)
            ->where('uuid', '!=', $usuarioDoMotoboy)
            ->pluck('uuid')
            ->map(fn ($uuid) => (string) $uuid)
            ->all();
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
}
