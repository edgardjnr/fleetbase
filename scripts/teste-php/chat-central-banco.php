<?php

// Conversa do motoboy com a central (App\Support\Entregas\ChatComACentral) com o banco em memória (stubs-chat.php):
// quem é a central (usuariosDaCentral, com os motoboys de `type = user` como em produção), a arrumação do canal a cada
// mensagem (aoCriarMensagem → sincronizar) e o abrir. As regras puras ficam no chat-central.php.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/chat-central-banco.php

namespace {
    require __DIR__ . '/stubs-chat.php';
    require __DIR__ . '/../../api/app/Support/Entregas/ChatComACentral.php';

    use App\Support\Entregas\ChatComACentral as C;
    use Fleetbase\FleetOps\Models\Driver;
    use Fleetbase\Models\ChatChannel;
    use Fleetbase\Models\ChatMessage;
    use Fleetbase\Models\ChatParticipant;
    use Fleetbase\Models\User;
    use Illuminate\Support\Facades\Log;

    use function Teste\ativos;
    use function Teste\montarEmpresa as montar;
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

    $central = \Teste\CENTRAL;

    echo '== usuariosDaCentral' . PHP_EOL;
    montar();
    $daCentral = ordenado(C::usuariosDaCentral('empresa', 'junior'));
    confere($daCentral === $central, 'admin, usuário do console, motoboy com Administrator e ex-motoboy: ' . implode(',', $daCentral));
    confere(!array_intersect($daCentral, ['roger', 'edmar', 'davi']), 'motoboys (type user ou driver) ficam fora');
    confere(!in_array('loja', $daCentral, true) && !in_array('admin-de-fora', $daCentral, true), 'loja e usuário de outra empresa ficam fora');
    confere(!in_array('junior', $daCentral, true), 'o próprio motoboy fica fora');

    echo '== aoCriarMensagem: o canal do Junior como em produção' . PHP_EOL;
    montar();
    $canal = ChatChannel::create(['uuid' => 'canal-junior', 'company_uuid' => 'empresa', 'created_by_uuid' => 'junior', 'meta' => [C::CHAVE_META => 'drv-junior']]);
    ChatParticipant::create(['uuid' => 'p-roger', 'company_uuid' => 'empresa', 'user_uuid' => 'roger', 'chat_channel_uuid' => 'canal-junior']);
    ChatParticipant::create(['uuid' => 'p-edmar', 'company_uuid' => 'empresa', 'user_uuid' => 'edmar', 'chat_channel_uuid' => 'canal-junior']);
    ChatParticipant::create(['uuid' => 'p-edgard', 'company_uuid' => 'empresa', 'user_uuid' => 'edgard', 'chat_channel_uuid' => 'canal-junior'])->delete();
    C::aoCriarMensagem(new ChatMessage(['chat_channel_uuid' => 'canal-junior']));
    confere(ativos('canal-junior') === ordenado(array_merge(['junior'], $central)), 'fica o Junior e a central: ' . implode(',', ativos('canal-junior')));
    confere(!array_intersect(ativos('canal-junior'), ['roger', 'edmar']), 'Roger e Edmar saem');
    confere(Log::$linhas === [], 'sem aviso no log');
    $antes = count(ChatParticipant::$linhas);
    C::aoCriarMensagem(new ChatMessage(['chat_channel_uuid' => 'canal-junior']));
    confere(count(ChatParticipant::$linhas) === $antes, 'segunda mensagem: nada muda');

    echo '== aoCriarMensagem: outros canais ficam como estão' . PHP_EOL;
    montar();
    ChatChannel::create(['uuid' => 'canal-loja', 'company_uuid' => 'empresa', 'created_by_uuid' => 'loja', 'meta' => ['entregas_conversa_loja' => 'v', 'entregas_conversa_motoboy' => 'drv-roger']]);
    ChatParticipant::create(['company_uuid' => 'empresa', 'user_uuid' => 'roger', 'chat_channel_uuid' => 'canal-loja']);
    C::aoCriarMensagem(new ChatMessage(['chat_channel_uuid' => 'canal-loja']));
    confere(ativos('canal-loja') === ['loja', 'roger'], 'conversa da loja com o motoboy: intacta');
    ChatChannel::create(['uuid' => 'canal-livre', 'company_uuid' => 'empresa', 'created_by_uuid' => 'edgard', 'meta' => []]);
    ChatParticipant::create(['company_uuid' => 'empresa', 'user_uuid' => 'roger', 'chat_channel_uuid' => 'canal-livre']);
    C::aoCriarMensagem(new ChatMessage(['chat_channel_uuid' => 'canal-livre']));
    confere(ativos('canal-livre') === ['edgard', 'roger'], 'canal criado no console: intacto');
    ChatChannel::create(['uuid' => 'canal-sem-motoboy', 'company_uuid' => 'empresa', 'created_by_uuid' => 'edgard', 'meta' => [C::CHAVE_META => 'drv-que-nao-existe']]);
    C::aoCriarMensagem(new ChatMessage(['chat_channel_uuid' => 'canal-sem-motoboy']));
    confere(ativos('canal-sem-motoboy') === ['edgard'], 'motoboy do meta não existe: intacto');
    C::aoCriarMensagem(new ChatMessage(['chat_channel_uuid' => 'canal-que-nao-existe']));
    confere(Log::$linhas === [], 'canal que não existe: nada, sem aviso');

    echo '== aoCriarMensagem nunca lança' . PHP_EOL;
    montar();
    ChatChannel::$explodir = 'banco fora';
    $lancou = false;
    try {
        C::aoCriarMensagem(new ChatMessage(['chat_channel_uuid' => 'canal-junior']));
    } catch (\Throwable $erro) {
        $lancou = true;
    }
    confere(!$lancou, 'falha no banco não derruba o envio da mensagem');
    confere(count(Log::$linhas) === 1 && str_contains(Log::$linhas[0][0], 'chat com a central'), 'fica o aviso no log');

    echo '== abrir' . PHP_EOL;
    montar();
    $junior = Driver::where('uuid', 'drv-junior')->first();
    $aberto = C::abrir($junior);
    confere($aberto !== null && ((array) $aberto->meta)[C::CHAVE_META] === 'drv-junior', 'cria o canal marcado no meta');
    confere($aberto?->name === 'Central · Junior', 'nome: Central · Junior');
    confere(ativos($aberto->uuid) === ordenado(array_merge(['junior'], $central)), 'só o Junior e a central: ' . implode(',', ativos($aberto->uuid)));
    $denovo = C::abrir($junior);
    confere($denovo === $aberto && count(ChatChannel::$linhas) === 1, 'segunda abertura: o mesmo canal');
    confere(ativos($aberto->uuid) === ordenado(array_merge(['junior'], $central)), 'sem participante repetido');

    montar();
    foreach (User::$linhas as $usuario) {
        if (in_array($usuario->uuid, ['edgard', 'operador', 'motoboy-admin', 'ex-motoboy'], true)) {
            $usuario->delete();
        }
    }
    confere(C::abrir(Driver::where('uuid', 'drv-junior')->first()) === null, 'empresa sem central: null');

    echo PHP_EOL . "FALHAS: {$falhas}" . PHP_EOL;
}
