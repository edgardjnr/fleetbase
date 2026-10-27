<?php

// Conversa da loja com a central (ConversasDaLoja::abrirComACentral e aoCriarMensagem) com o banco em memória
// (stubs-chat.php): quem entra, o canal reaproveitado, a arrumação a cada mensagem e os canais que ficam como estão.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/conversas-com-a-central.php

namespace {
    require __DIR__ . '/stubs-chat.php';
    require __DIR__ . '/../../api/app/Support/Entregas/ChatComACentral.php';
    require __DIR__ . '/../../api/app/Support/Entregas/ConversasDaLoja.php';

    use App\Support\Entregas\ConversasDaLoja as C;
    use Fleetbase\FleetOps\Models\Vendor;
    use Fleetbase\Models\ChatChannel;
    use Fleetbase\Models\ChatMessage;
    use Fleetbase\Models\ChatParticipant;
    use Fleetbase\Models\User;
    use Illuminate\Support\Facades\Log;

    use function Teste\ativos;
    use function Teste\montarEmpresa;
    use function Teste\ordenado;

    $falhas = 0;

    function confere(bool $ok, string $caso): void
    {
        global $falhas;
        if (!$ok) {
            $falhas++;
        }
        echo ($ok ? 'PASSA ' : 'FALHA ') . $caso . PHP_EOL;
    }

    $esperados = ordenado(array_merge(['loja'], \Teste\CENTRAL));

    echo '== abrirComACentral' . PHP_EOL;
    montarEmpresa();
    $terraco = Vendor::where('uuid', 'terraco')->first();
    $canal   = C::abrirComACentral($terraco, 'loja');
    confere($canal !== null, 'abre a conversa');
    confere((array) $canal->meta === C::metaDaCentral('terraco'), 'meta com a loja e a central');
    confere($canal->name === 'Loja · Terraço Pizza Bar', 'nome: Loja · Terraço Pizza Bar');
    confere(ativos($canal->uuid) === $esperados, 'a loja e a central: ' . implode(',', ativos($canal->uuid)));
    confere(!array_intersect(ativos($canal->uuid), ['junior', 'roger', 'edmar', 'davi']), 'nenhum motoboy');
    confere(!array_intersect(ativos($canal->uuid), ['loja-inativa', 'loja-2']), 'nem usuário inativo nem de outra loja');
    $denovo = C::abrirComACentral($terraco, 'loja');
    confere($denovo === $canal && count(ChatChannel::$linhas) === 1, 'segunda abertura: o mesmo canal');
    confere(ativos($canal->uuid) === $esperados, 'sem participante repetido');

    echo '== a outra loja tem a conversa dela' . PHP_EOL;
    $outra      = Vendor::where('uuid', 'outra-loja')->first();
    $daOutra    = C::abrirComACentral($outra, 'loja-2');
    confere($daOutra !== $canal && count(ChatChannel::$linhas) === 2, 'canal separado');
    confere(ativos($daOutra->uuid) === ordenado(array_merge(['loja-2'], \Teste\CENTRAL)), 'com a loja dela e a central');

    echo '== empresa sem central' . PHP_EOL;
    montarEmpresa();
    foreach (User::$linhas as $usuario) {
        if (in_array($usuario->uuid, \Teste\CENTRAL, true)) {
            $usuario->delete();
        }
    }
    confere(C::abrirComACentral(Vendor::where('uuid', 'terraco')->first(), 'loja') === null, 'null');
    confere(ChatChannel::$linhas === [], 'nenhum canal criado');

    echo '== aoCriarMensagem' . PHP_EOL;
    montarEmpresa();
    $canal = C::abrirComACentral(Vendor::where('uuid', 'terraco')->first(), 'loja');
    foreach (ChatParticipant::$linhas as $participante) {
        if ($participante->user_uuid === 'edgard') {
            $participante->delete();
        }
    }
    ChatParticipant::create(['company_uuid' => 'empresa', 'user_uuid' => 'roger', 'chat_channel_uuid' => $canal->uuid]);
    User::create(['uuid' => 'operador-novo', 'type' => 'user']);
    \Fleetbase\Models\CompanyUser::create(['company_uuid' => 'empresa', 'user_uuid' => 'operador-novo']);
    C::aoCriarMensagem(new ChatMessage(['chat_channel_uuid' => $canal->uuid]));
    confere(in_array('edgard', ativos($canal->uuid), true), 'quem da central saiu volta');
    confere(in_array('operador-novo', ativos($canal->uuid), true), 'quem entrou na central depois entra');
    confere(in_array('roger', ativos($canal->uuid), true), 'quem a central adicionou pelo console fica');
    $antes = count(ChatParticipant::$linhas);
    C::aoCriarMensagem(new ChatMessage(['chat_channel_uuid' => $canal->uuid]));
    confere(count(ChatParticipant::$linhas) === $antes, 'segunda mensagem: nada muda');

    echo '== sai só o usuário de loja que não está mais ativo nela' . PHP_EOL;
    foreach (['abrir', 'mensagem'] as $quando) {
        montarEmpresa();
        $terraco = Vendor::where('uuid', 'terraco')->first();
        $canal   = C::abrirComACentral($terraco, 'loja');
        foreach (['loja-inativa', 'loja-2', 'roger'] as $posto) {
            ChatParticipant::create(['company_uuid' => 'empresa', 'user_uuid' => $posto, 'chat_channel_uuid' => $canal->uuid]);
        }
        $quando === 'abrir' ? C::abrirComACentral($terraco, 'loja') : C::aoCriarMensagem(new ChatMessage(['chat_channel_uuid' => $canal->uuid]));
        confere(!in_array('loja-inativa', ativos($canal->uuid), true), "{$quando}: usuário inativo da loja sai");
        confere(!in_array('loja-2', ativos($canal->uuid), true), "{$quando}: usuário de outra loja sai");
        confere(in_array('roger', ativos($canal->uuid), true), "{$quando}: motoboy posto pela central fica");
        confere(in_array('edgard', ativos($canal->uuid), true) && in_array('loja', ativos($canal->uuid), true), "{$quando}: a central e a loja ficam");
    }

    echo '== aoCriarMensagem: outros canais ficam como estão' . PHP_EOL;
    montarEmpresa();
    ChatChannel::create(['uuid' => 'canal-motoboy', 'company_uuid' => 'empresa', 'created_by_uuid' => 'loja', 'meta' => C::meta('terraco', 'drv-roger')]);
    C::aoCriarMensagem(new ChatMessage(['chat_channel_uuid' => 'canal-motoboy']));
    confere(ativos('canal-motoboy') === ['loja'], 'conversa da loja com o motoboy: intacta');
    ChatChannel::create(['uuid' => 'canal-sem-loja', 'company_uuid' => 'empresa', 'created_by_uuid' => 'edgard', 'meta' => C::metaDaCentral('loja-que-nao-existe')]);
    C::aoCriarMensagem(new ChatMessage(['chat_channel_uuid' => 'canal-sem-loja']));
    confere(ativos('canal-sem-loja') === ['edgard'], 'loja do meta não existe: intacta');
    // o console grava o meta que vem na requisição: um canal de outra empresa pode apontar para uma loja desta
    ChatChannel::create(['uuid' => 'canal-de-fora', 'company_uuid' => 'outra', 'created_by_uuid' => 'admin-de-fora', 'meta' => C::metaDaCentral('terraco')]);
    C::aoCriarMensagem(new ChatMessage(['chat_channel_uuid' => 'canal-de-fora']));
    confere(ativos('canal-de-fora') === ['admin-de-fora'], 'canal de outra empresa com a loja no meta: intacto');
    confere(Log::$linhas === [], 'sem aviso no log');

    echo '== aoCriarMensagem nunca lança' . PHP_EOL;
    montarEmpresa();
    ChatChannel::$explodir = 'banco fora';
    $lancou = false;
    try {
        C::aoCriarMensagem(new ChatMessage(['chat_channel_uuid' => 'qualquer']));
    } catch (\Throwable $erro) {
        $lancou = true;
    }
    confere(!$lancou, 'falha no banco não derruba o envio');
    confere(count(Log::$linhas) === 1 && str_contains(Log::$linhas[0][0], 'conversa da loja com a central'), 'fica o aviso no log');

    echo PHP_EOL . "FALHAS: {$falhas}" . PHP_EOL;
}
