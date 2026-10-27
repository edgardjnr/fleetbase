<?php

// Conversa do motoboy com a central (App\Support\Entregas\ChatComACentral) com o banco em memória: quem é a central
// (usuariosDaCentral, com os motoboys de `type = user` como em produção), a arrumação do canal a cada mensagem
// (aoCriarMensagem → sincronizar) e o abrir. As regras puras ficam no chat-central.php.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/chat-central-banco.php

namespace Illuminate\Support {
    class Collection implements \IteratorAggregate, \Countable
    {
        public function __construct(protected array $itens = []) {}
        public function all(): array { return $this->itens; }
        public function count(): int { return count($this->itens); }
        public function isEmpty(): bool { return $this->itens === []; }
        public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->itens); }
        public function get($chave, $padrao = null) { return $this->itens[$chave] ?? $padrao; }
        public function keys(): static { return new static(array_keys($this->itens)); }
        public function values(): static { return new static(array_values($this->itens)); }
        public function pluck(string $campo): static { return new static(array_map(fn ($item) => $item->$campo ?? null, array_values($this->itens))); }
        public function map(callable $funcao): static
        {
            $saida = [];
            foreach ($this->itens as $chave => $item) {
                $saida[$chave] = $funcao($item, $chave);
            }

            return new static($saida);
        }
        public function filter(callable $funcao): static { return new static(array_filter($this->itens, $funcao)); }
        public function keyBy(callable $funcao): static
        {
            $saida = [];
            foreach ($this->itens as $item) {
                $saida[$funcao($item)] = $item;
            }

            return new static($saida);
        }
    }
}

namespace Illuminate\Support\Facades {
    class Cache
    {
        public static array $travas = [];
        public static function lock(string $chave, int $segundos)
        {
            static::$travas[] = $chave;

            return new class {
                public function block(int $segundos, callable $funcao) { return $funcao(); }
            };
        }
    }

    class Log
    {
        public static array $linhas = [];
        public static function warning(string $mensagem, array $contexto = []): void { static::$linhas[] = [$mensagem, $contexto]; }
    }
}

namespace Teste {
    use Illuminate\Support\Collection;

    /** Consulta em memória. Como o SoftDeletes do Fleetbase\Models\Model, a linha com deleted_at fica de fora. */
    class Consulta
    {
        private array $linhas;

        public function __construct(private string $modelo)
        {
            if (isset($modelo::$explodir)) {
                throw new \RuntimeException($modelo::$explodir);
            }
            $this->linhas = array_filter($modelo::$linhas, fn ($linha) => $linha->deleted_at === null);
        }

        private static function valor($linha, string $coluna)
        {
            if (str_contains($coluna, '->')) {
                [$campo, $chave] = explode('->', $coluna, 2);

                return ((array) ($linha->$campo ?? []))[$chave] ?? null;
            }

            return $linha->$coluna ?? null;
        }

        public function where(string $coluna, $operador, $valor = null): static
        {
            [$operador, $valor] = func_num_args() === 2 ? ['=', $operador] : [$operador, $valor];
            $this->linhas = array_filter($this->linhas, fn ($linha) => $operador === '!=' ? static::valor($linha, $coluna) !== $valor : static::valor($linha, $coluna) === $valor);

            return $this;
        }

        public function whereIn(string $coluna, array $valores): static
        {
            $this->linhas = array_filter($this->linhas, fn ($linha) => in_array(static::valor($linha, $coluna), $valores, true));

            return $this;
        }

        public function get(): Collection { return new Collection(array_values($this->linhas)); }
        public function first() { return array_values($this->linhas)[0] ?? null; }
        public function pluck(string $coluna): Collection { return $this->get()->pluck($coluna); }
    }

    /** Atributos dinâmicos, como no Eloquent (o PHP 8.2 reclama de propriedade criada sem declarar). */
    abstract class Modelo
    {
        public static array $linhas = [];
        public ?string $deleted_at = null;
        private array $atributos = [];

        public function __get($chave) { return $this->atributos[$chave] ?? null; }
        public function __set($chave, $valor): void { $this->atributos[$chave] = $valor; }
        public function __isset($chave): bool { return isset($this->atributos[$chave]); }

        public function __construct(array $atributos = [])
        {
            foreach ($atributos as $chave => $valor) {
                $this->$chave = $valor;
            }
            $this->uuid ??= static::class . '-' . (count(static::$linhas) + 1);
        }

        public static function where(...$argumentos): Consulta { return (new Consulta(static::class))->where(...$argumentos); }
        public static function whereIn(...$argumentos): Consulta { return (new Consulta(static::class))->whereIn(...$argumentos); }

        public static function create(array $atributos): static
        {
            $registro          = new static($atributos);
            static::$linhas[]  = $registro;

            return $registro;
        }

        public function delete(): void { $this->deleted_at = '2026-10-27 06:00:00'; }
        public function refresh(): static { return $this; }
    }
}

namespace Fleetbase\Models {
    class User extends \Teste\Modelo
    {
        public static array $linhas = [];
    }

    class CompanyUser extends \Teste\Modelo
    {
        public static array $linhas = [];
        public array $papeis = [];
        public function hasRole(string $papel): bool { return in_array($papel, $this->papeis, true); }
    }

    class ChatChannel extends \Teste\Modelo
    {
        public static array $linhas = [];
        public static ?string $explodir = null;

        /** Como o core: o criador do canal entra como participante. */
        public static function create(array $atributos): static
        {
            $canal = parent::create($atributos);
            ChatParticipant::create(['company_uuid' => $canal->company_uuid, 'user_uuid' => $canal->created_by_uuid, 'chat_channel_uuid' => $canal->uuid]);

            return $canal;
        }
    }

    class ChatParticipant extends \Teste\Modelo
    {
        public static array $linhas = [];
    }

    class ChatMessage extends \Teste\Modelo
    {
        public static array $linhas = [];
    }
}

namespace Fleetbase\FleetOps\Models {
    class Driver extends \Teste\Modelo
    {
        public static array $linhas = [];
    }
}

namespace {
    require __DIR__ . '/../../api/app/Support/Entregas/ChatComACentral.php';

    use App\Support\Entregas\ChatComACentral as C;
    use Fleetbase\FleetOps\Models\Driver;
    use Fleetbase\Models\ChatChannel;
    use Fleetbase\Models\ChatMessage;
    use Fleetbase\Models\ChatParticipant;
    use Fleetbase\Models\CompanyUser;
    use Fleetbase\Models\User;
    use Illuminate\Support\Facades\Log;

    $falhas = 0;

    function confere(bool $ok, string $caso): void
    {
        global $falhas;
        if (!$ok) {
            $falhas++;
        }
        echo ($ok ? 'PASSA ' : 'FALHA ') . $caso . PHP_EOL;
    }

    /** A empresa como em produção (2026-10-27): os motoboys com `type = user`. */
    function montar(): void
    {
        User::$linhas = CompanyUser::$linhas = ChatChannel::$linhas = ChatParticipant::$linhas = ChatMessage::$linhas = Driver::$linhas = [];
        ChatChannel::$explodir = null;
        Log::$linhas = [];

        $usuarios = [
            // uuid, tipo, motoboy (null = não; 'excluido' = cadastro excluído), papéis na empresa
            ['edgard', 'admin', null, ['Administrator']],
            ['operador', 'user', null, ['Operador']],
            ['junior', 'user', 'drv-junior', ['Lideres Motoboy']],
            ['roger', 'user', 'drv-roger', ['Lideres Motoboy']],
            ['edmar', 'user', 'drv-edmar', ['Lideres Motoboy']],
            ['davi', 'driver', 'drv-davi', ['Driver']],
            ['motoboy-admin', 'user', 'drv-admin', ['Administrator']],
            ['ex-motoboy', 'user', 'excluido', []],
            ['loja', 'customer', null, []],
        ];

        foreach ($usuarios as [$uuid, $tipo, $motoboy, $papeis]) {
            User::create(['uuid' => $uuid, 'type' => $tipo]);
            CompanyUser::create(['uuid' => "cu-{$uuid}", 'company_uuid' => 'empresa', 'user_uuid' => $uuid, 'papeis' => $papeis]);
            if ($motoboy === 'excluido') {
                Driver::create(['uuid' => "drv-{$uuid}", 'user_uuid' => $uuid, 'company_uuid' => 'empresa'])->delete();
            } elseif ($motoboy) {
                Driver::create(['uuid' => $motoboy, 'user_uuid' => $uuid, 'company_uuid' => 'empresa', 'name' => ucfirst($uuid)]);
            }
        }

        // admin de outra empresa
        User::create(['uuid' => 'admin-de-fora', 'type' => 'user']);
        CompanyUser::create(['uuid' => 'cu-fora', 'company_uuid' => 'outra', 'user_uuid' => 'admin-de-fora']);
    }

    function ativos(string $canal): array
    {
        $uuids = ChatParticipant::where('chat_channel_uuid', $canal)->pluck('user_uuid')->all();
        sort($uuids);

        return $uuids;
    }

    function ordenado(array $lista): array
    {
        sort($lista);

        return $lista;
    }

    $central = ordenado(['edgard', 'operador', 'motoboy-admin', 'ex-motoboy']);

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
