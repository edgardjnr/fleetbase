<?php

// Banco em memória do chat (ChatComACentral, ConversasDaLoja) para os testes de php-wasm: Collection, Cache, Log e os
// modelos do core e do Fleet-Ops que o chat usa. A empresa montada em \Teste\montarEmpresa() é a da produção em
// 2026-10-27 (motoboys com `type = user`). Usado por chat-central-banco.php e conversas-com-a-central.php.

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

        public function whereIn(string $coluna, $valores): static
        {
            $valores      = $valores instanceof Collection ? $valores->all() : $valores;
            $this->linhas = array_filter($this->linhas, fn ($linha) => in_array(static::valor($linha, $coluna), $valores, true));

            return $this;
        }

        public function whereNotNull(string $coluna): static
        {
            $this->linhas = array_filter($this->linhas, fn ($linha) => static::valor($linha, $coluna) !== null);

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
            $registro         = new static($atributos);
            static::$linhas[] = $registro;

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

    class Vendor extends \Teste\Modelo
    {
        public static array $linhas = [];
    }

    class VendorPersonnel extends \Teste\Modelo
    {
        public static array $linhas = [];
    }

    class Contact extends \Teste\Modelo
    {
        public static array $linhas = [];
    }
}

namespace Teste {
    use Fleetbase\FleetOps\Models\Contact;
    use Fleetbase\FleetOps\Models\Driver;
    use Fleetbase\FleetOps\Models\Vendor;
    use Fleetbase\FleetOps\Models\VendorPersonnel;
    use Fleetbase\Models\ChatChannel;
    use Fleetbase\Models\ChatMessage;
    use Fleetbase\Models\ChatParticipant;
    use Fleetbase\Models\CompanyUser;
    use Fleetbase\Models\User;
    use Illuminate\Support\Facades\Log;

    /**
     * A empresa como em produção (2026-10-27): os motoboys com `type = user`. Central = edgard, operador, motoboy-admin
     * e ex-motoboy. Lojas: terraco (usuários `loja` ativo e `loja-inativa`) e outra-loja (`loja-2`).
     */
    function montarEmpresa(): void
    {
        User::$linhas = CompanyUser::$linhas = ChatChannel::$linhas = ChatParticipant::$linhas = ChatMessage::$linhas = [];
        Driver::$linhas = Vendor::$linhas = VendorPersonnel::$linhas = Contact::$linhas = [];
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
            ['loja-inativa', 'customer', null, []],
            ['loja-2', 'customer', null, []],
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

        // lojas (Vendor) e seus usuários (Contact customer com VendorPersonnel)
        Vendor::create(['uuid' => 'terraco', 'company_uuid' => 'empresa', 'name' => 'Terraço Pizza Bar']);
        Vendor::create(['uuid' => 'outra-loja', 'company_uuid' => 'empresa', 'name' => 'Outra']);
        foreach ([['c-loja', 'loja', 'terraco', 'active'], ['c-inativa', 'loja-inativa', 'terraco', 'inactive'], ['c-loja-2', 'loja-2', 'outra-loja', 'active']] as [$contato, $usuario, $loja, $situacao]) {
            Contact::create(['uuid' => $contato, 'type' => 'customer', 'user_uuid' => $usuario]);
            VendorPersonnel::create(['vendor_uuid' => $loja, 'contact_uuid' => $contato, 'status' => $situacao]);
        }
    }

    /** Os usuários ativos no canal, em ordem alfabética. */
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

    /** A central da empresa montada. */
    const CENTRAL = ['edgard', 'ex-motoboy', 'motoboy-admin', 'operador'];
}
