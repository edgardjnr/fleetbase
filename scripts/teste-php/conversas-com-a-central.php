<?php

// Conversa da loja com a central (ConversasDaLoja::abrirComACentral, aoCriarMensagem, item e listar) com o banco em
// memória (stubs-chat.php): quem entra e quem sai, o canal reaproveitado e separado por loja, a arrumação a cada mensagem,
// a trava, os canais que ficam como estão (inclusive pelo gancho do ChatComACentral) e a lista sempre com a central.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/conversas-com-a-central.php

namespace {
    require __DIR__ . '/stubs-chat.php';
    require __DIR__ . '/../../api/app/Support/Entregas/ChatComACentral.php';
    require __DIR__ . '/../../api/app/Support/Entregas/ConversasDaLoja.php';

    use App\Support\Entregas\ChatComACentral;
    use App\Support\Entregas\ConversasDaLoja as C;
    use Fleetbase\FleetOps\Models\Driver;
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
    confere($denovo->uuid === $canal->uuid && count(ChatChannel::$linhas) === 1, 'segunda abertura: o mesmo canal');
    confere(ativos($canal->uuid) === $esperados, 'sem participante repetido');

    echo '== a outra loja tem a conversa dela' . PHP_EOL;
    $outra      = Vendor::where('uuid', 'outra-loja')->first();
    $daOutra    = C::abrirComACentral($outra, 'loja-2');
    confere($daOutra->uuid !== $canal->uuid && count(ChatChannel::$linhas) === 2, 'canal separado');
    confere(ativos($daOutra->uuid) === ordenado(array_merge(['loja-2'], \Teste\CENTRAL)), 'com a loja dela e a central');
    confere(C::daLoja($terraco, $canal->public_id)?->uuid === $canal->uuid, 'daLoja: a loja acha a conversa dela com a central');
    confere(C::daLoja($outra, $canal->public_id) === null, 'daLoja: outra loja não acha a conversa do Terraço com a central');

    echo '== abrir com motoboy não reaproveita a conversa com a central' . PHP_EOL;
    $doRoger = C::abrir($terraco, Driver::where('uuid', 'drv-roger')->first(), 'loja');
    confere($doRoger->uuid !== $canal->uuid && (array) $doRoger->meta === C::meta('terraco', 'drv-roger'), 'canal novo, marcado com o motoboy');
    confere(ativos($doRoger->uuid) === ['loja', 'roger'], 'só a loja e o motoboy');
    confere(ativos($canal->uuid) === $esperados, 'a conversa com a central fica como estava');

    echo '== a trava do abrir e a da mensagem nova são a mesma' . PHP_EOL;
    \Illuminate\Support\Facades\Cache::$travas = [];
    C::abrirComACentral($terraco, 'loja');
    $doAbrir = \Illuminate\Support\Facades\Cache::$travas;
    \Illuminate\Support\Facades\Cache::$travas = [];
    C::aoCriarMensagem(new ChatMessage(['chat_channel_uuid' => $canal->uuid]));
    confere($doAbrir === ['entregas:conversa-loja-central:terraco'] && \Illuminate\Support\Facades\Cache::$travas === $doAbrir, 'a mesma chave: ' . implode(',', $doAbrir));

    echo '== o gancho do motoboy com a central não mexe na conversa da loja com a central' . PHP_EOL;
    $antes = ativos($canal->uuid);
    ChatParticipant::create(['company_uuid' => 'empresa', 'user_uuid' => 'roger', 'chat_channel_uuid' => $canal->uuid]);
    ChatComACentral::aoCriarMensagem(new ChatMessage(['chat_channel_uuid' => $canal->uuid]));
    confere(ativos($canal->uuid) === ordenado(array_merge($antes, ['roger'])), 'ninguém sai nem entra');

    echo '== o gancho da loja não mexe na conversa do motoboy com a central' . PHP_EOL;
    ChatChannel::create(['uuid' => 'canal-junior', 'company_uuid' => 'empresa', 'created_by_uuid' => 'junior', 'meta' => [ChatComACentral::CHAVE_META => 'drv-junior']]);
    ChatParticipant::create(['company_uuid' => 'empresa', 'user_uuid' => 'edgard', 'chat_channel_uuid' => 'canal-junior']);
    C::aoCriarMensagem(new ChatMessage(['chat_channel_uuid' => 'canal-junior']));
    confere(ativos('canal-junior') === ['edgard', 'junior'], 'intacta');

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

    echo '== item' . PHP_EOL;
    montarEmpresa();
    $terraco = Vendor::where('uuid', 'terraco')->first();
    $canal   = C::abrirComACentral($terraco, 'loja');
    $item    = C::item($canal, 'loja');
    confere(array_keys($item) === ['id', 'tipo', 'motoboy', 'ultima', 'nao_lidas', 'atualizada_em'], 'formato do item da lista');
    confere($item['id'] === $canal->public_id && $item['tipo'] === 'central', 'conversa com a central: id e tipo central');
    confere($item['motoboy'] === ['nome' => null, 'foto' => null], 'conversa com a central: motoboy sem nome nem foto');
    confere($item['ultima'] === null && $item['nao_lidas'] === 0, 'sem mensagem: sem última e sem não lidas');
    $daCentral = ChatParticipant::where('chat_channel_uuid', $canal->uuid)->where('user_uuid', 'edgard')->first();
    ChatMessage::create(['chat_channel_uuid' => $canal->uuid, 'sender_uuid' => $daCentral->uuid, 'content' => 'Oi, loja', 'created_at' => '2026-10-27T10:00:00-03:00']);
    $item = C::item($canal, 'loja');
    confere($item['ultima'] === ['texto' => 'Oi, loja', 'em' => '2026-10-27T10:00:00-03:00', 'minha' => false], 'a mensagem da central é a última');
    confere($item['nao_lidas'] === 1 && $item['atualizada_em'] === '2026-10-27T10:00:00-03:00', 'uma não lida e a hora dela');
    $doRoger = C::abrir($terraco, Driver::where('uuid', 'drv-roger')->first(), 'loja');
    $item    = C::item($doRoger, 'loja');
    confere($item['tipo'] === 'motoboy' && $item['motoboy'] === ['nome' => 'Roger', 'foto' => null], 'conversa com o motoboy: tipo motoboy e o nome dele');

    echo '== listar: a conversa com a central sempre vem' . PHP_EOL;
    $lista = C::listar($terraco, 'loja');
    confere(array_column($lista, 'id') === [$canal->public_id, $doRoger->public_id], 'a central (mensagem mais recente) e o Roger: ' . implode(',', array_column($lista, 'id')));
    confere($lista[0] === C::item($canal, 'loja'), 'o item da lista é o mesmo do item()');
    confere(C::listar(Vendor::where('uuid', 'outra-loja')->first(), 'loja-2') === [], 'outra loja: nada desta');
    for ($i = 0; $i <= C::CONVERSAS_NA_LISTA; $i++) {
        $canalDoMotoboy = ChatChannel::create(['company_uuid' => 'empresa', 'created_by_uuid' => 'loja', 'meta' => C::meta('terraco', "drv-x{$i}")]);
        ChatMessage::create(['chat_channel_uuid' => $canalDoMotoboy->uuid, 'sender_uuid' => 'outro', 'content' => "m{$i}", 'created_at' => sprintf('2026-10-27T11:%02d:00-03:00', $i)]);
    }
    $lista = C::listar($terraco, 'loja');
    $comMotoboy = array_values(array_filter($lista, fn ($item) => $item['tipo'] === 'motoboy'));
    confere(count($comMotoboy) === C::CONVERSAS_NA_LISTA, 'as ' . C::CONVERSAS_NA_LISTA . ' conversas com motoboy mais recentes');
    confere(end($lista)['id'] === $canal->public_id && end($lista)['tipo'] === 'central', 'a conversa com a central vem, mesmo mais antiga, no fim');
    confere(count(array_filter($lista, fn ($item) => $item['tipo'] === 'central')) === 1, 'uma vez só');
    confere(!in_array($doRoger->public_id, array_column($lista, 'id'), true) && $comMotoboy[0]['ultima']['texto'] === 'm' . C::CONVERSAS_NA_LISTA, 'a mais antiga com motoboy fica fora; a mais recente em cima');

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
