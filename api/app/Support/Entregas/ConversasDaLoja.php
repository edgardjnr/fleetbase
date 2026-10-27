<?php

namespace App\Support\Entregas;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\VendorPersonnel;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatMessage;
use Fleetbase\Models\ChatParticipant;
use Fleetbase\Models\ChatReceipt;
use Fleetbase\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: o chat da loja com os motoboys no portal (ConversasDaLojaController). Desenho:
 * docs/superpowers/specs/2026-10-04-chat-da-loja-design.md.
 *
 * Uma conversa por par loja × motoboy, por cima do chat do Fleetbase: o canal é marcado no `meta` (META_LOJA = uuid do
 * Vendor, META_MOTOBOY = uuid do Driver) e tem só o motoboy e os usuários ativos da loja (decisão do Edgard em 2026-10-04:
 * a central não entra sozinha nessa conversa; pode ser adicionada depois pelo chat do console, e quem já está no canal
 * continua). Quem falta da loja ou o motoboy entra a cada abertura. A conversa da loja com a central (2026-10-27,
 * abrirComACentral) é outro canal, um por loja: META_LOJA e META_CENTRAL no `meta`, os usuários ativos da loja e a
 * central (ChatComACentral::usuariosDaCentral), completado ao abrir e a cada mensagem nova; dele sai só o usuário de loja
 * que não está mais ativo nela (quem a central adicionar pelo console fica). A mensagem nasce como no core
 * (ChatMessage::create + notifyParticipants): o motoboy recebe no app (socket e push) e a loja no portal.
 *
 * A loja nunca usa as rotas de chat do Fleetbase (a de participantes lista todos os usuários da empresa) e as respostas
 * daqui não trazem id de motoboy, de participante nem de usuário: só o public_id do canal e o das mensagens.
 */
class ConversasDaLoja
{
    public const META_LOJA = 'entregas_conversa_loja';

    public const META_MOTOBOY = 'entregas_conversa_motoboy';

    /** Marca da conversa da loja com a central no `meta` (o valor é o uuid da loja, como em META_LOJA). */
    public const META_CENTRAL = 'entregas_conversa_central';

    public const MAX_CARACTERES = 1000;

    /** Mensagens devolvidas por leitura (as mais recentes). */
    public const MENSAGENS_POR_LEITURA = 50;

    /** Conversas devolvidas na lista (as de mensagem mais recente). */
    public const CONVERSAS_NA_LISTA = 50;

    /** O canal da loja com o motoboy, criado ou completado agora; null se o motoboy não tem usuário. */
    public static function abrir(Vendor $loja, Driver $motoboy, string $usuario): ?ChatChannel
    {
        $empresa   = (string) $loja->company_uuid;
        $doMotoboy = (string) $motoboy->user_uuid;

        if ($empresa === '' || $doMotoboy === '' || $usuario === '') {
            return null;
        }

        $quemDeveEstar = static::participantes($usuario, static::usuariosDaLoja($loja), $doMotoboy);

        // a trava (cache = Redis em produção) evita dois canais para o mesmo par com dois toques seguidos
        return Cache::lock("entregas:conversa-loja:{$loja->uuid}:{$motoboy->uuid}", 15)->block(10, function () use ($loja, $motoboy, $empresa, $usuario, $quemDeveEstar) {
            $canal = ChatChannel::where('company_uuid', $empresa)
                ->where('meta->' . static::META_LOJA, $loja->uuid)
                ->where('meta->' . static::META_MOTOBOY, $motoboy->uuid)
                ->first();

            if (!$canal) {
                // o ChatChannel do core põe o criador (o usuário da loja) como participante
                $canal = ChatChannel::create([
                    'company_uuid'    => $empresa,
                    'created_by_uuid' => $usuario,
                    'name'            => static::nome($loja->name, $motoboy->name),
                    'meta'            => static::meta($loja->uuid, $motoboy->uuid),
                ]);
            }

            $presentes = ChatParticipant::where('chat_channel_uuid', $canal->uuid)->pluck('user_uuid')->all();

            foreach (ChatComACentral::quemFalta($quemDeveEstar, $presentes) as $quem) {
                ChatParticipant::create([
                    'company_uuid'      => $empresa,
                    'user_uuid'         => $quem,
                    'chat_channel_uuid' => $canal->uuid,
                ]);
            }

            return $canal->refresh();
        });
    }

    /**
     * A conversa da loja com a central, criada ou completada agora; null se a empresa não tem ninguém na central. Um canal
     * por loja, com os usuários ativos da loja e a central (ChatComACentral::usuariosDaCentral: quem abre o console).
     */
    public static function abrirComACentral(Vendor $loja, string $usuario): ?ChatChannel
    {
        $empresa = (string) $loja->company_uuid;
        $central = $empresa !== '' ? ChatComACentral::usuariosDaCentral($empresa, '') : [];

        if ($empresa === '' || $usuario === '' || empty($central)) {
            return null;
        }

        $quemDeveEstar = static::participantesComACentral($usuario, static::usuariosDaLoja($loja), $central);

        return Cache::lock(static::travaDaCentral($loja->uuid), 15)->block(10, function () use ($loja, $empresa, $usuario, $quemDeveEstar) {
            $canal = ChatChannel::where('company_uuid', $empresa)
                ->where('meta->' . static::META_CENTRAL, $loja->uuid)
                ->first();

            if (!$canal) {
                // o ChatChannel do core põe o criador (o usuário da loja) como participante
                $canal = ChatChannel::create([
                    'company_uuid'    => $empresa,
                    'created_by_uuid' => $usuario,
                    'name'            => static::nomeDaCentral($loja->name),
                    'meta'            => static::metaDaCentral($loja->uuid),
                ]);
            }

            static::completar($canal, $quemDeveEstar);

            return $canal->refresh();
        });
    }

    /**
     * A cada mensagem nova (ChatMessage::created), a conversa da loja com a central ganha quem falta da loja e da central
     * (inclusive quem saiu), antes do notifyParticipants do core. Sai só o usuário de loja que não está mais ativo nela
     * (ver completar): quem a central adicionou pelo console fica. Só age na conversa com a central de uma loja da mesma
     * empresa do canal; nunca lança (a mensagem já está gravada).
     */
    public static function aoCriarMensagem(ChatMessage $mensagem): void
    {
        try {
            // outra instância do canal: a relação da mensagem fica sem carregar, e o core lê os participantes já completos
            $canal  = ChatChannel::where('uuid', $mensagem->chat_channel_uuid)->first();
            $doMeta = $canal ? static::lojaDaCentralNoMeta($canal->meta) : null;
            // o console grava o `meta` que vem na requisição: só vale a loja da mesma empresa do canal
            $loja = $doMeta ? Vendor::where('uuid', $doMeta)->where('company_uuid', $canal->company_uuid)->first() : null;

            if (!$canal || !$loja) {
                return;
            }

            Cache::lock(static::travaDaCentral($loja->uuid), 15)->block(10, function () use ($canal, $loja) {
                $central = ChatComACentral::usuariosDaCentral((string) $canal->company_uuid, '');
                static::completar($canal, static::participantesComACentral('', static::usuariosDaLoja($loja), $central));
            });
        } catch (\Throwable $erro) {
            Log::warning('[entregas] conversa da loja com a central: falha ao completar os participantes', ['canal' => $mensagem->chat_channel_uuid, 'erro' => $erro->getMessage()]);
        }
    }

    /** Um canal da loja pelo public_id; null se não existe ou é de outra loja. */
    public static function daLoja(Vendor $loja, string $id): ?ChatChannel
    {
        return ChatChannel::where('company_uuid', $loja->company_uuid)
            ->where('meta->' . static::META_LOJA, $loja->uuid)
            ->where('public_id', $id)
            ->first();
    }

    /**
     * As conversas da loja, a de mensagem mais recente primeiro.
     *
     * @return array<int, array{id: string, tipo: string, motoboy: array{nome: ?string, foto: ?string}, ultima: ?array, nao_lidas: int, atualizada_em: ?string}>
     */
    public static function listar(Vendor $loja, string $usuario): array
    {
        $canais = ChatChannel::where('company_uuid', $loja->company_uuid)
            ->where('meta->' . static::META_LOJA, $loja->uuid)
            ->get();

        if ($canais->isEmpty()) {
            return [];
        }

        // o nome e a foto do motoboy leem o usuário dele: carregados de uma vez
        $motoboys = Driver::whereIn('uuid', $canais->map(fn ($canal) => data_get($canal->meta, static::META_MOTOBOY))->filter()->values())
            ->with('user')
            ->get()
            ->keyBy('uuid');

        $meus = ChatParticipant::whereIn('chat_channel_uuid', $canais->pluck('uuid'))
            ->where('user_uuid', $usuario)
            ->get()
            ->keyBy('chat_channel_uuid');

        $lista = [];
        foreach ($canais as $canal) {
            $eu      = $meus->get($canal->uuid);
            $ultima  = static::mensagensDo($canal)->latest()->orderByDesc('id')->first();
            $motoboy = $motoboys->get(data_get($canal->meta, static::META_MOTOBOY));

            $tipo = static::tipoDoCanal($canal->meta);

            $lista[] = [
                'id'            => $canal->public_id,
                'tipo'          => $tipo,
                'motoboy'       => $tipo === 'central' ? ['nome' => null, 'foto' => null] : ['nome' => $motoboy?->name, 'foto' => $motoboy?->photo_url],
                'ultima'        => $ultima ? [
                    'texto' => $ultima->content,
                    'em'    => static::data($ultima->created_at),
                    'minha' => $eu !== null && $ultima->sender_uuid === $eu->uuid,
                ] : null,
                'nao_lidas'     => $eu ? static::naoLidas($canal, $eu) : 0,
                'atualizada_em' => static::data($ultima?->created_at ?? $canal->created_at),
            ];
        }

        // ISO 8601 no mesmo fuso: a ordem do texto é a ordem do tempo
        usort($lista, fn ($a, $b) => strcmp((string) $b['atualizada_em'], (string) $a['atualizada_em']));

        return array_slice($lista, 0, static::CONVERSAS_NA_LISTA);
    }

    /**
     * As mensagens mais recentes do canal, da mais antiga para a mais nova, e as dos outros marcadas como lidas pelo
     * usuário.
     *
     * @return array<int, array{id: string, texto: ?string, em: ?string, autor: array{nome: ?string, papel: string}, minha: bool}>
     */
    public static function mensagens(ChatChannel $canal, string $usuario): array
    {
        $eu = static::participante($canal, $usuario);

        $mensagens = static::mensagensDo($canal)
            ->with('sender.user')
            ->latest()
            ->orderByDesc('id')
            ->limit(static::MENSAGENS_POR_LEITURA)
            ->get()
            ->reverse()
            ->values();

        $dosOutros = $mensagens->filter(fn ($mensagem) => $mensagem->sender_uuid !== $eu->uuid)->pluck('uuid')->all();
        static::marcarComoLidas($canal, $eu, $dosOutros);

        $motoboys = static::motoboysEntre($mensagens->map(fn ($mensagem) => $mensagem->sender?->user_uuid)->all());

        return $mensagens->map(fn ($mensagem) => static::formatar($mensagem, $eu, $motoboys))->all();
    }

    /** Grava a mensagem do usuário e avisa os outros participantes (como o sendMessage do core). */
    public static function enviar(ChatChannel $canal, string $usuario, string $texto): array
    {
        $eu = static::participante($canal, $usuario);

        $mensagem = ChatMessage::create([
            'company_uuid'      => $canal->company_uuid,
            'chat_channel_uuid' => $canal->uuid,
            'sender_uuid'       => $eu->uuid,
            'content'           => $texto,
        ]);

        // a mensagem já está gravada: uma falha no aviso (push, socket) não desfaz o envio
        try {
            $mensagem->notifyParticipants();
        } catch (\Throwable $erro) {
            Log::warning('[entregas] chat da loja: falha ao avisar os participantes', ['canal' => $canal->public_id, 'erro' => $erro->getMessage()]);
        }

        $mensagem->load('sender.user');

        return static::formatar($mensagem, $eu, static::motoboysEntre([$mensagem->sender?->user_uuid]));
    }

    /** Os usuários ativos da loja (Contact `customer` com VendorPersonnel ativo, como o LojaDoUsuario). */
    public static function usuariosDaLoja(Vendor $loja): array
    {
        $contatos = VendorPersonnel::where('vendor_uuid', $loja->uuid)->where('status', 'active')->pluck('contact_uuid');

        return Contact::whereIn('uuid', $contatos)
            ->where('type', 'customer')
            ->whereNotNull('user_uuid')
            ->pluck('user_uuid')
            ->map(fn ($uuid) => (string) $uuid)
            ->all();
    }

    /**
     * Quem a conversa precisa ter: quem abriu (usuário da loja ou o motoboy), os usuários ativos da loja e o motoboy, sem
     * repetir e sem vazios. A central fica de fora.
     *
     * @param array<int, string> $daLoja
     *
     * @return array<int, string>
     */
    public static function participantes(string $quemAbriu, array $daLoja, string $doMotoboy): array
    {
        $todos = [];

        foreach (array_merge([$quemAbriu], $daLoja, [$doMotoboy]) as $quem) {
            $quem = (string) $quem;

            if ($quem !== '' && !in_array($quem, $todos, true)) {
                $todos[] = $quem;
            }
        }

        return $todos;
    }

    /** Nome do canal, como aparece no app do motoboy e no chat do console. */
    public static function nome(?string $loja, ?string $motoboy): string
    {
        $partes = array_values(array_filter([trim((string) $loja), trim((string) $motoboy)], fn ($parte) => $parte !== ''));

        return $partes ? implode(' · ', $partes) : 'Conversa';
    }

    /**
     * O papel de quem escreveu: loja (customer), motoboy (driver ou com cadastro de motoboy) ou central (o resto). Em
     * produção os motoboys ficam com `type = user` (2026-10-27): o cadastro de motoboy decide.
     */
    public static function papel(?string $tipoDoUsuario, bool $temCadastroDeMotoboy = false): string
    {
        if ($tipoDoUsuario === 'customer') {
            return 'loja';
        }

        return $tipoDoUsuario === 'driver' || $temCadastroDeMotoboy ? 'motoboy' : 'central';
    }

    /** O texto aceito (sem espaços nas pontas, de 1 a MAX_CARACTERES caracteres) ou null. */
    public static function textoValido(mixed $texto): ?string
    {
        if (!is_string($texto)) {
            return null;
        }

        $texto = trim($texto);

        return $texto !== '' && mb_strlen($texto) <= static::MAX_CARACTERES ? $texto : null;
    }

    /** A marca da conversa da loja com a central: a da loja (META_LOJA, para o daLoja e a lista) e a da central. */
    public static function metaDaCentral(string $loja): array
    {
        return [static::META_LOJA => $loja, static::META_CENTRAL => $loja];
    }

    /** Nome da conversa com a central, como a central a vê no chat do console. */
    public static function nomeDaCentral(?string $loja): string
    {
        $loja = trim((string) $loja);

        return $loja === '' ? 'Loja' : "Loja · {$loja}";
    }

    /** O uuid da loja de uma conversa com a central pelo `meta` (array, objeto ou texto JSON); null se não é com a central. */
    public static function lojaDaCentralNoMeta(mixed $meta): ?string
    {
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }

        $meta = is_object($meta) ? (array) $meta : $meta;
        $loja = is_array($meta) ? ($meta[static::META_CENTRAL] ?? null) : null;

        return is_string($loja) && $loja !== '' ? $loja : null;
    }

    /** O `tipo` da conversa na lista do portal: com a central ou com um motoboy. */
    public static function tipoDoCanal(mixed $meta): string
    {
        return static::lojaDaCentralNoMeta($meta) !== null ? 'central' : 'motoboy';
    }

    /**
     * Quem a conversa com a central precisa ter: quem abriu (vazio numa mensagem nova), os usuários ativos da loja e a
     * central, sem repetir e sem vazios.
     *
     * @param array<int, string> $daLoja
     * @param array<int, string> $central
     *
     * @return array<int, string>
     */
    public static function participantesComACentral(string $quemAbriu, array $daLoja, array $central): array
    {
        return ChatComACentral::quemFalta(array_merge([$quemAbriu], $daLoja, $central), []);
    }

    /** A marca do canal no `meta`. */
    public static function meta(string $loja, string $motoboy): array
    {
        return [static::META_LOJA => $loja, static::META_MOTOBOY => $motoboy];
    }

    /**
     * Arruma a conversa com a central: quem falta entra e sai só o usuário de loja (`customer`) que não está em
     * $quemDeveEstar (inativo na loja ou de outra loja), com soft delete do participante. O resto que estiver lá fica
     * (quem a central adicionou pelo console).
     */
    protected static function completar(ChatChannel $canal, array $quemDeveEstar): void
    {
        $presentes = ChatParticipant::where('chat_channel_uuid', $canal->uuid)->get();
        $uuids     = $presentes->pluck('user_uuid')->all();

        foreach (ChatComACentral::quemFalta($quemDeveEstar, $uuids) as $quem) {
            ChatParticipant::create([
                'company_uuid'      => $canal->company_uuid,
                'user_uuid'         => $quem,
                'chat_channel_uuid' => $canal->uuid,
            ]);
        }

        $daLoja = $uuids ? User::whereIn('uuid', $uuids)->where('type', 'customer')->pluck('uuid')->map(fn ($uuid) => (string) $uuid)->all() : [];
        $sobram = ChatComACentral::quemSai($daLoja, $quemDeveEstar);

        foreach ($presentes as $participante) {
            if (in_array((string) $participante->user_uuid, $sobram, true)) {
                $participante->delete();
            }
        }
    }

    protected static function travaDaCentral(string $loja): string
    {
        return "entregas:conversa-loja-central:{$loja}";
    }

    /** O participante do usuário no canal; entra agora se ainda não está (usuário de loja criado depois do canal). */
    protected static function participante(ChatChannel $canal, string $usuario): ChatParticipant
    {
        return ChatParticipant::firstOrCreate(
            ['chat_channel_uuid' => $canal->uuid, 'user_uuid' => $usuario],
            ['company_uuid' => $canal->company_uuid]
        );
    }

    /** As mensagens do canal sem o remetente, os anexos e os recibos que o ChatMessage carrega sempre ($with). */
    protected static function mensagensDo(ChatChannel $canal)
    {
        return ChatMessage::query()->without(['sender', 'attachments', 'receipts'])->where('chat_channel_uuid', $canal->uuid);
    }

    protected static function naoLidas(ChatChannel $canal, ChatParticipant $eu): int
    {
        return static::mensagensDo($canal)
            ->where('sender_uuid', '!=', $eu->uuid)
            ->whereNotIn('uuid', ChatReceipt::where('participant_uuid', $eu->uuid)->select('chat_message_uuid'))
            ->count();
    }

    /** Recibo de leitura para as mensagens dos outros que o usuário ainda não leu. */
    protected static function marcarComoLidas(ChatChannel $canal, ChatParticipant $eu, array $dosOutros): void
    {
        if (!$dosOutros) {
            return;
        }

        $lidas = ChatReceipt::where('participant_uuid', $eu->uuid)->whereIn('chat_message_uuid', $dosOutros)->pluck('chat_message_uuid')->all();

        foreach (array_diff($dosOutros, $lidas) as $mensagem) {
            ChatReceipt::create([
                'company_uuid'      => $canal->company_uuid,
                'chat_message_uuid' => $mensagem,
                'participant_uuid'  => $eu->uuid,
                'read_at'           => Carbon::now(),
            ]);
        }
    }

    /** Os usuários da lista com cadastro de motoboy (o Driver usa SoftDeletes: o excluído não conta), numa consulta só. */
    protected static function motoboysEntre(array $usuarios): array
    {
        $usuarios = array_values(array_unique(array_filter(array_map('strval', $usuarios))));

        return $usuarios ? Driver::whereIn('user_uuid', $usuarios)->pluck('user_uuid')->map(fn ($uuid) => (string) $uuid)->all() : [];
    }

    protected static function formatar(ChatMessage $mensagem, ChatParticipant $eu, array $motoboys = []): array
    {
        $autor = $mensagem->sender?->user;

        return [
            'id'    => $mensagem->public_id,
            'texto' => $mensagem->content,
            'em'    => static::data($mensagem->created_at),
            'autor' => ['nome' => $autor?->name, 'papel' => static::papel($autor?->type, in_array((string) $autor?->uuid, $motoboys, true))],
            'minha' => $mensagem->sender_uuid === $eu->uuid,
        ];
    }

    protected static function data($data): ?string
    {
        return $data ? Carbon::parse($data)->toIso8601String() : null;
    }
}
