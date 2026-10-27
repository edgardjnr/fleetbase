# Conversa da loja com a central — plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Objetivo:** no portal da loja, a loja abre sozinha uma conversa com a central (item fixo "Central" no topo de Conversas), e a central responde pelo chat do console.

**Arquitetura:** por cima do chat do Fleetbase, como as conversas da loja com motoboy (`ConversasDaLoja`). Um canal por loja, marcado no `meta` com `entregas_conversa_loja` e `entregas_conversa_central` (os dois com o uuid da loja), com os usuários ativos da loja e a central (`ChatComACentral::usuariosDaCentral`, corrigido em bed171ff). O canal ganha quem falta ao abrir e a cada mensagem nova (`ChatMessage::created`), sem tirar ninguém (quem a central adicionar pelo console fica). O portal mostra o item fixo pelo `tipo` que a lista passa a trazer.

**Tecnologia:** Laravel (api/app, PHP 8.2), Ember (packages/customer-portal), testes em php-wasm (`scripts/teste-php/`) e node:test (`scripts/teste-portal/`).

Desenho: `docs/superpowers/specs/2026-10-27-conversa-da-loja-com-a-central-design.md`.

**Comandos usados em todo o plano** (na raiz do repo, Git Bash):

```bash
# teste PHP (o php-wasm está instalado em C:\tmp\php-wasm)
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/<arquivo>.php
# sintaxe PHP 8.2
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs <arquivo.php>...
# teste do portal
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/conversas.test.mjs
```

## Arquivos

| Arquivo | O que muda |
|---|---|
| `scripts/teste-php/stubs-chat.php` (novo) | Banco em memória do chat (modelos, Collection, Cache, Log), saído do `chat-central-banco.php` e usado pelos dois testes de banco |
| `scripts/teste-php/chat-central-banco.php` | Passa a usar o `stubs-chat.php` |
| `api/app/Support/Entregas/ConversasDaLoja.php` | `META_CENTRAL`, funções puras da central, `abrirComACentral`, `aoCriarMensagem`, `tipo` na lista |
| `scripts/teste-php/conversas-da-loja.php` | Casos das funções puras novas |
| `scripts/teste-php/conversas-com-a-central.php` (novo) | Abrir e arrumar a conversa com o banco em memória |
| `api/app/Providers/AppServiceProvider.php` | O `ChatMessage::created` também chama o `ConversasDaLoja::aoCriarMensagem` |
| `api/app/Http/Controllers/Entregas/ConversasDaLojaController.php` | Ramo `central` no `abrir` |
| `packages/customer-portal/addon/utils/conversas.js` | `itensDaLista` |
| `scripts/teste-portal/conversas.test.mjs` | Casos do `itensDaLista` |
| `packages/customer-portal/addon/services/entregas-conversas.js` | Task `abrirComACentral` |
| `packages/customer-portal/addon/components/portal/conversas.js` e `.hbs` | Item fixo "Central", título e texto inicial |
| `packages/customer-portal/translations/pt-br.yaml` e `en-us.yaml` | 3 chaves novas |
| `packages/customer-portal/CLAUDE.md`, `docs/historico.md`, o desenho | Documentação |

---

### Tarefa 1: banco em memória compartilhado (`stubs-chat.php`)

**Arquivos:**
- Criar: `scripts/teste-php/stubs-chat.php`
- Modificar: `scripts/teste-php/chat-central-banco.php`

- [ ] **Passo 1: criar `scripts/teste-php/stubs-chat.php`** com tudo que hoje está antes do `namespace {` em `chat-central-banco.php` e mais: `whereNotNull` na `Consulta`, `whereIn` aceitando `Collection`, os modelos `Vendor`, `VendorPersonnel` e `Contact` do Fleet-Ops e a função `\Teste\montarEmpresa()` (a mesma `montar()` do teste, mais as lojas).

```php
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
```

- [ ] **Passo 2: trocar o começo de `chat-central-banco.php`.** Apague tudo do `namespace Illuminate\Support {` até o fim do bloco `namespace Fleetbase\FleetOps\Models { ... }` e, dentro do `namespace {`, troque o `require` e as funções `montar`, `ativos` e `ordenado` pelo `stubs-chat.php`. O arquivo fica assim no começo:

```php
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
```

e o resto (de `echo '== usuariosDaCentral'` até o fim) fica igual.

> Como o `stubs-chat.php` declara namespaces com chaves, o `chat-central-banco.php` também precisa usar `namespace { ... }` com chaves (já usa). O `require` de um arquivo com namespaces em bloco funciona de dentro de `namespace {}`.

- [ ] **Passo 3: rodar e conferir que continua passando**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/chat-central-banco.php`
Expected: 21 linhas `PASSA` e `FALHAS: 0`.

- [ ] **Passo 4: commit**

```bash
git add scripts/teste-php/stubs-chat.php scripts/teste-php/chat-central-banco.php
git commit -m "Testes do chat: banco em memória num arquivo só (stubs-chat.php)"
```

---

### Tarefa 2: funções puras da conversa com a central (`ConversasDaLoja`)

**Arquivos:**
- Modificar: `api/app/Support/Entregas/ConversasDaLoja.php`
- Teste: `scripts/teste-php/conversas-da-loja.php`

- [ ] **Passo 1: escrever os casos que falham.** Em `scripts/teste-php/conversas-da-loja.php`, antes do `echo PHP_EOL . "FALHAS: ..."`, acrescente:

```php
echo '== conversa com a central' . PHP_EOL;
confere(C::META_CENTRAL === 'entregas_conversa_central', 'marca da conversa com a central');
confere(C::metaDaCentral('v') === [C::META_LOJA => 'v', C::META_CENTRAL => 'v'], 'meta: loja e central com o uuid da loja');
confere(C::nomeDaCentral('Terraço') === 'Loja · Terraço', 'nome: Loja · <loja>');
confere(C::nomeDaCentral('  ') === 'Loja', 'sem nome: só Loja');
confere(C::nomeDaCentral(null) === 'Loja', 'nome nulo: só Loja');
confere(C::lojaDaCentralNoMeta([C::META_LOJA => 'v', C::META_CENTRAL => 'v']) === 'v', 'loja pelo meta em array');
confere(C::lojaDaCentralNoMeta((object) [C::META_CENTRAL => 'v']) === 'v', 'loja pelo meta em objeto');
confere(C::lojaDaCentralNoMeta(json_encode([C::META_CENTRAL => 'v'])) === 'v', 'loja pelo meta em texto JSON');
confere(C::lojaDaCentralNoMeta(C::meta('v', 'm')) === null, 'conversa com motoboy: não é da central');
confere(C::lojaDaCentralNoMeta(null) === null, 'sem meta');
confere(C::tipoDoCanal(C::metaDaCentral('v')) === 'central', 'tipo central');
confere(C::tipoDoCanal(C::meta('v', 'm')) === 'motoboy', 'tipo motoboy');
confere(C::participantesComACentral('u1', ['u1', 'u2'], ['a', 'u2', '']) === ['u1', 'u2', 'a'], 'participantes: quem abriu, loja e central, sem repetir nem vazio');
confere(C::participantesComACentral('', ['u1'], ['a']) === ['u1', 'a'], 'sem quem abriu (mensagem nova)');
```

- [ ] **Passo 2: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/conversas-da-loja.php`
Expected: erro fatal `Undefined constant ... META_CENTRAL`.

- [ ] **Passo 3: implementar.** Em `api/app/Support/Entregas/ConversasDaLoja.php`, logo depois de `public const META_MOTOBOY = 'entregas_conversa_motoboy';`:

```php

    /** Marca da conversa da loja com a central no `meta` (o valor é o uuid da loja, como em META_LOJA). */
    public const META_CENTRAL = 'entregas_conversa_central';
```

E, logo antes de `/** A marca do canal no `meta`. */` (o método `meta`), acrescente:

```php
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

```

- [ ] **Passo 4: o teste passa a carregar o `ChatComACentral`** (o `participantesComACentral` usa o `quemFalta`). No topo de `scripts/teste-php/conversas-da-loja.php`, troque a linha do `require` por:

```php
require __DIR__ . '/../../api/app/Support/Entregas/ChatComACentral.php';
require __DIR__ . '/../../api/app/Support/Entregas/ConversasDaLoja.php';
```

- [ ] **Passo 5: rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/conversas-da-loja.php`
Expected: todas `PASSA` e `FALHAS: 0`.

- [ ] **Passo 6: commit**

```bash
git add api/app/Support/Entregas/ConversasDaLoja.php scripts/teste-php/conversas-da-loja.php
git commit -m "Conversa da loja com a central: marca, nome e tipo do canal"
```

---

### Tarefa 3: abrir e arrumar a conversa com a central (servidor)

**Arquivos:**
- Modificar: `api/app/Support/Entregas/ConversasDaLoja.php`
- Modificar: `api/app/Providers/AppServiceProvider.php`
- Criar: `scripts/teste-php/conversas-com-a-central.php`

- [ ] **Passo 1: escrever o teste que falha** em `scripts/teste-php/conversas-com-a-central.php`:

```php
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

    echo '== aoCriarMensagem: outros canais ficam como estão' . PHP_EOL;
    montarEmpresa();
    ChatChannel::create(['uuid' => 'canal-motoboy', 'company_uuid' => 'empresa', 'created_by_uuid' => 'loja', 'meta' => C::meta('terraco', 'drv-roger')]);
    C::aoCriarMensagem(new ChatMessage(['chat_channel_uuid' => 'canal-motoboy']));
    confere(ativos('canal-motoboy') === ['loja'], 'conversa da loja com o motoboy: intacta');
    ChatChannel::create(['uuid' => 'canal-sem-loja', 'company_uuid' => 'empresa', 'created_by_uuid' => 'edgard', 'meta' => C::metaDaCentral('loja-que-nao-existe')]);
    C::aoCriarMensagem(new ChatMessage(['chat_channel_uuid' => 'canal-sem-loja']));
    confere(ativos('canal-sem-loja') === ['edgard'], 'loja do meta não existe: intacta');
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
```

- [ ] **Passo 2: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/conversas-com-a-central.php`
Expected: erro fatal `Call to undefined method ... abrirComACentral()`.

- [ ] **Passo 3: implementar no `ConversasDaLoja.php`.** Logo depois do método `abrir(...)` (antes de `/** Um canal da loja pelo public_id; ...`):

```php
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
     * (inclusive quem saiu), antes do notifyParticipants do core. Ninguém sai: quem a central adicionou pelo console fica.
     * Só age na conversa com a central; nunca lança (a mensagem já está gravada).
     */
    public static function aoCriarMensagem(ChatMessage $mensagem): void
    {
        try {
            // outra instância do canal: a relação da mensagem fica sem carregar, e o core lê os participantes já completos
            $canal  = ChatChannel::where('uuid', $mensagem->chat_channel_uuid)->first();
            $doMeta = $canal ? static::lojaDaCentralNoMeta($canal->meta) : null;
            $loja   = $doMeta ? Vendor::where('uuid', $doMeta)->first() : null;

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
```

E, logo antes de `/** O participante do usuário no canal; ...` (o método `participante`), os dois auxiliares:

```php
    /** Quem falta no canal entra (como o abrir das conversas com motoboy). */
    protected static function completar(ChatChannel $canal, array $quemDeveEstar): void
    {
        $presentes = ChatParticipant::where('chat_channel_uuid', $canal->uuid)->pluck('user_uuid')->all();

        foreach (ChatComACentral::quemFalta($quemDeveEstar, $presentes) as $quem) {
            ChatParticipant::create([
                'company_uuid'      => $canal->company_uuid,
                'user_uuid'         => $quem,
                'chat_channel_uuid' => $canal->uuid,
            ]);
        }
    }

    protected static function travaDaCentral(string $loja): string
    {
        return "entregas:conversa-loja-central:{$loja}";
    }

```

No docblock da classe (topo do arquivo), troque a frase "e tem só o motoboy e os usuários ativos da loja (decisão do Edgard em 2026-10-04: a central não entra sozinha, nem pelo app nem pelo portal; pode ser adicionada depois pelo chat do console, e quem já está no canal continua)." por:

```
 * e tem só o motoboy e os usuários ativos da loja (decisão do Edgard em 2026-10-04: a central não entra sozinha nessa
 * conversa; pode ser adicionada depois pelo chat do console, e quem já está no canal continua). A conversa da loja com a
 * central (2026-10-27, abrirComACentral) é outro canal, um por loja: META_LOJA e META_CENTRAL no `meta`, os usuários
 * ativos da loja e a central (ChatComACentral::usuariosDaCentral), completado ao abrir e a cada mensagem nova.
```

Os imports de `Cache`, `Log`, `ChatMessage`, `ChatParticipant` e `Vendor` já existem no arquivo.

- [ ] **Passo 4: ligar no `AppServiceProvider`.** Em `api/app/Providers/AppServiceProvider.php`, acrescente o import `use App\Support\Entregas\ConversasDaLoja;` logo depois de `use App\Support\Entregas\ConexaoDoStorefront;` e troque o método `arrumarConversasComACentral` por:

```php
    /**
     * Entregas: a cada mensagem nova, as conversas com a central se arrumam antes do notifyParticipants do core, que já
     * avisa quem entrou: a do motoboy volta a ter a central e só ela além dele (ChatComACentral::aoCriarMensagem) e a da
     * loja ganha quem falta da loja e da central (ConversasDaLoja::aoCriarMensagem). Cada uma só age no próprio canal.
     */
    protected function arrumarConversasComACentral(): void
    {
        ChatMessage::created(function ($mensagem) {
            ChatComACentral::aoCriarMensagem($mensagem);
            ConversasDaLoja::aoCriarMensagem($mensagem);
        });
    }
```

- [ ] **Passo 5: `tipo` na lista.** Em `ConversasDaLoja::listar`, troque:

```php
            $lista[] = [
                'id'            => $canal->public_id,
                'motoboy'       => ['nome' => $motoboy?->name, 'foto' => $motoboy?->photo_url],
```

por:

```php
            $tipo = static::tipoDoCanal($canal->meta);

            $lista[] = [
                'id'            => $canal->public_id,
                'tipo'          => $tipo,
                'motoboy'       => $tipo === 'central' ? ['nome' => null, 'foto' => null] : ['nome' => $motoboy?->name, 'foto' => $motoboy?->photo_url],
```

e no docblock do `listar` troque o `@return` por:

```php
     * @return array<int, array{id: string, tipo: string, motoboy: array{nome: ?string, foto: ?string}, ultima: ?array, nao_lidas: int, atualizada_em: ?string}>
```

- [ ] **Passo 6: rodar os testes e a sintaxe**

```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/conversas-com-a-central.php
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/conversas-da-loja.php
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/chat-central-banco.php
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/imports-dos-providers.php
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/ConversasDaLoja.php api/app/Providers/AppServiceProvider.php
```

Expected: `FALHAS: 0` em todos e `OK` nos dois arquivos.

- [ ] **Passo 7: commit**

```bash
git add api/app/Support/Entregas/ConversasDaLoja.php api/app/Providers/AppServiceProvider.php scripts/teste-php/conversas-com-a-central.php
git commit -m "Conversa da loja com a central: abrir, completar a cada mensagem e tipo na lista"
```

---

### Tarefa 4: rota (ramo `central` no `abrir`)

**Arquivos:**
- Modificar: `api/app/Http/Controllers/Entregas/ConversasDaLojaController.php`

- [ ] **Passo 1: implementar.** No `abrir`, logo depois de `$donos = $this->donosDosPedidos($loja);`, acrescente o ramo da central:

```php

        // a conversa da loja com a central (uma por loja)
        if ($request->boolean('central')) {
            $canal = ConversasDaLoja::abrirComACentral($loja, (string) session('user'));
            if (!$canal) {
                return $this->erro(422, 'A central não está disponível para conversa agora.');
            }

            return response()->json(['conversa' => static::itemDaLista($loja, $canal)]);
        }
```

troque o final do método (as duas linhas do `$conversa = collect(...)` e do `return`) por:

```php
        return response()->json(['conversa' => static::itemDaLista($loja, $canal)]);
```

e acrescente, antes de `protected function erro(...)`:

```php
    /** O canal no formato da lista (GET loja/conversas). */
    protected static function itemDaLista($loja, $canal): ?array
    {
        return collect(ConversasDaLoja::listar($loja, (string) session('user')))->firstWhere('id', $canal->public_id);
    }
```

No docblock do `abrir`, troque `/** Abre a conversa com um motoboy: ...` por:

```php
    /**
     * Abre a conversa: `central` = true (a conversa da loja com a central), `motoboy` = id opaco do mapa do portal ou
     * `pedido` = public_id de um pedido da loja.
     */
```

> Atenção: o `listar` devolve só as 50 conversas mais recentes. A conversa recém-aberta (sem mensagem) entra pela data de criação, que é agora, então fica no topo. É o mesmo comportamento de hoje com motoboy.

- [ ] **Passo 2: sintaxe**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Http/Controllers/Entregas/ConversasDaLojaController.php`
Expected: `OK`.

- [ ] **Passo 3: commit**

```bash
git add api/app/Http/Controllers/Entregas/ConversasDaLojaController.php
git commit -m "Conversa da loja com a central: POST loja/conversas com central = true"
```

---

### Tarefa 5: item fixo "Central" na lista (função pura do portal)

**Arquivos:**
- Modificar: `packages/customer-portal/addon/utils/conversas.js`
- Teste: `scripts/teste-portal/conversas.test.mjs`

- [ ] **Passo 1: escrever o teste que falha.** Em `scripts/teste-portal/conversas.test.mjs`, acrescente `itensDaLista,` no import (depois de `ultimasDosOutros,`) e, no fim do arquivo:

```js
test('lista: a conversa com a central sempre no topo, mesmo antes de existir', () => {
    const fixa = { id: null, tipo: 'central', motoboy: { nome: null, foto: null }, ultima: null, nao_lidas: 0 };

    assert.deepEqual(itensDaLista([]), [fixa]);
    assert.deepEqual(itensDaLista(null), [fixa]);

    const m1 = { id: 'm1', tipo: 'motoboy' };
    const m2 = { id: 'm2', tipo: 'motoboy' };
    const central = { id: 'c', tipo: 'central', nao_lidas: 2 };
    assert.deepEqual(itensDaLista([m1, central, m2]), [central, m1, m2], 'a central sobe; os motoboys ficam na ordem do servidor');

    const antigo = { id: 'm3' };
    assert.deepEqual(itensDaLista([antigo]), [fixa, antigo], 'servidor sem tipo: conversa com motoboy');
});
```

- [ ] **Passo 2: rodar e ver falhar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/conversas.test.mjs`
Expected: FAIL (`itensDaLista` não é exportado).

- [ ] **Passo 3: implementar.** No fim de `packages/customer-portal/addon/utils/conversas.js`:

```js

/**
 * A lista da gaveta: a conversa com a central (tipo `central`) sempre no topo, mesmo antes de existir (sem id: tocar
 * abre a conversa), e as dos motoboys depois, na ordem do servidor. Item sem `tipo` (servidor antigo) é de motoboy.
 */
export function itensDaLista(conversas) {
    const lista = (conversas ?? []).filter(Boolean);
    const central = lista.find((conversa) => conversa.tipo === 'central') ?? { id: null, tipo: 'central', motoboy: { nome: null, foto: null }, ultima: null, nao_lidas: 0 };

    return [central, ...lista.filter((conversa) => conversa.tipo !== 'central')];
}
```

E troque a primeira linha do arquivo por:

```js
// Entregas: funções puras do chat da loja no portal, com os motoboys e com a central (serviço entregas-conversas e componente Portal::Conversas).
```

- [ ] **Passo 4: rodar e ver passar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/conversas.test.mjs`
Expected: todos `ok`, `fail 0`.

- [ ] **Passo 5: commit**

```bash
git add packages/customer-portal/addon/utils/conversas.js scripts/teste-portal/conversas.test.mjs
git commit -m "Portal: a conversa com a central sempre no topo da lista"
```

---

### Tarefa 6: telas do portal (serviço, gaveta e traduções)

**Arquivos:**
- Modificar: `packages/customer-portal/addon/services/entregas-conversas.js`
- Modificar: `packages/customer-portal/addon/components/portal/conversas.js`
- Modificar: `packages/customer-portal/addon/components/portal/conversas.hbs`
- Modificar: `packages/customer-portal/translations/pt-br.yaml` e `en-us.yaml`

- [ ] **Passo 1: serviço.** Em `entregas-conversas.js`, logo antes de `/** Abre (ou cria) a conversa com o motoboy escolhido no mapa (id opaco). */`:

```js
    /** Abre (ou cria) a conversa da loja com a central. */
    @task *abrirComACentral() {
        yield this.abrir({ central: true });
    }

```

E na primeira frase do docblock da classe troque `o chat da loja com os motoboys` por `o chat da loja com os motoboys e com a central`.

- [ ] **Passo 2: componente.** Em `components/portal/conversas.js`:

troque o import das utils por:

```js
import { MAX_CARACTERES, itensDaLista, resumo } from '../../utils/conversas';
```

troque o `get titulo()` por:

```js
    get titulo() {
        const atual = this.conversas.conversaAtual;

        if (atual) {
            if (atual.tipo === 'central') {
                return this.intl.t('customer-portal.ui.entregas.chat-central');
            }

            return atual.motoboy?.nome || this.intl.t('customer-portal.ui.entregas.chat-courier');
        }

        if (this.conversas.escolhendoMotoboy) {
            return this.intl.t('customer-portal.ui.entregas.chat-new');
        }

        return this.intl.t('customer-portal.ui.entregas.chat-title');
    }

    /** O texto da conversa ainda sem mensagens. */
    get textoInicial() {
        return this.intl.t(this.conversas.conversaAtual?.tipo === 'central' ? 'customer-portal.ui.entregas.chat-start-central' : 'customer-portal.ui.entregas.chat-start');
    }
```

troque o `get itens()` por:

```js
    get itens() {
        return itensDaLista(this.conversas.conversas).map((conversa) => {
            const central = conversa.tipo === 'central';

            return {
                conversa,
                central,
                nome: central ? this.intl.t('customer-portal.ui.entregas.chat-central') : conversa.motoboy?.nome || this.intl.t('customer-portal.ui.entregas.chat-courier'),
                foto: central ? null : conversa.motoboy?.foto,
                resumo: conversa.ultima
                    ? `${conversa.ultima.minha ? this.intl.t('customer-portal.ui.entregas.chat-you') + ': ' : ''}${resumo(conversa.ultima.texto)}`
                    : this.intl.t(central ? 'customer-portal.ui.entregas.chat-central-hint' : 'customer-portal.ui.entregas.chat-no-messages'),
                em: conversa.ultima?.em,
                naoLidas: Number(conversa.nao_lidas) || 0,
            };
        });
    }
```

e acrescente, antes de `@action rolarParaOFim(elemento) {`:

```js
    // a conversa com a central sem id ainda não existe: tocar cria e abre
    @action abrirItem(item) {
        if (item.conversa.id) {
            this.conversas.escolherConversa(item.conversa, '');
        } else {
            this.conversas.abrirComACentral.perform();
        }
    }

```

Atualize o comentário do topo do componente para: `// Entregas: a gaveta do chat da loja com os motoboys e com a central (tela Pedidos). Três vistas, todas pelo serviço entregas-conversas:` (o resto igual).

- [ ] **Passo 3: template.** Em `components/portal/conversas.hbs`:

o comentário do topo passa a ser:

```hbs
{{!-- Entregas: gaveta do chat da loja (serviço entregas-conversas). Com o motoboy, a conversa é só da loja e dele (a
     central pode ser adicionada depois pelo chat do console); com a central (item fixo no topo), a loja e a central, que
     responde pelo chat do console. O motoboy responde pela aba Conversas do app --}}
```

troque `<div class="portal-conversas-empty">{{t "customer-portal.ui.entregas.chat-start"}}</div>` por:

```hbs
                        <div class="portal-conversas-empty">{{this.textoInicial}}</div>
```

e troque o botão da lista (de `<button type="button" class="portal-conversas-item" {{on "click" (fn this.conversas.escolherConversa item.conversa "")}}>` até o `</span>` do avatar) por:

```hbs
                    <button type="button" class="portal-conversas-item" disabled={{and item.central this.conversas.abrirComACentral.isRunning}} {{on "click" (fn this.abrirItem item)}}>
                        <span class="portal-conversas-avatar">
                            {{#if item.central}}<FaIcon @icon="headset" />{{else if item.foto}}<img src={{item.foto}} alt="" />{{else}}<FaIcon @icon="motorcycle" />{{/if}}
                        </span>
```

> O helper `and` (ember-truth-helpers) já é usado no portal (`integracoes/ifood.hbs`).

- [ ] **Passo 4: traduções.** Em `packages/customer-portal/translations/pt-br.yaml`, logo depois de `      chat-role-central: "Central"`:

```yaml
      chat-central: "Central"
      chat-central-hint: "Fale com a central"
      chat-start-central: "Escreva a primeira mensagem para a central."
```

Em `en-us.yaml`, logo depois de `      chat-role-central: "Dispatcher"`:

```yaml
      chat-central: "Dispatch"
      chat-central-hint: "Talk to dispatch"
      chat-start-central: "Write the first message to dispatch."
```

- [ ] **Passo 5: validar tradução e parse dos JS**

```bash
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit $?"
P=./$(ls -d console/node_modules/.pnpm/@babel+parser@7*/node_modules/@babel/parser | head -1)
for f in packages/customer-portal/addon/services/entregas-conversas.js packages/customer-portal/addon/components/portal/conversas.js packages/customer-portal/addon/utils/conversas.js; do
  node -e "require('$P').parse(require('fs').readFileSync('$f','utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties']}); console.log('OK $f')"
done
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/conversas.test.mjs
```

Expected: `exit 0`, três `OK` e `fail 0`.

- [ ] **Passo 6: commit**

```bash
git add packages/customer-portal/addon/services/entregas-conversas.js packages/customer-portal/addon/components/portal/conversas.js packages/customer-portal/addon/components/portal/conversas.hbs packages/customer-portal/translations/pt-br.yaml packages/customer-portal/translations/en-us.yaml
git commit -m "Portal: item Central na gaveta de Conversas"
```

---

### Tarefa 7: documentação e bateria inteira

**Arquivos:**
- Modificar: `packages/customer-portal/CLAUDE.md`, `docs/historico.md`, `docs/superpowers/specs/2026-10-27-conversa-da-loja-com-a-central-design.md`

- [ ] **Passo 1: `packages/customer-portal/CLAUDE.md`.** Na seção "Chat da loja com os motoboys":

troque o título `- **Chat da loja com os motoboys** (decisão de 2026-10-04; desenho: ...):` por `- **Chat da loja com os motoboys e com a central** (decisão de 2026-10-04; desenho: `docs/superpowers/specs/2026-10-04-chat-da-loja-design.md`; a central desde 2026-10-27, desenho `docs/superpowers/specs/2026-10-27-conversa-da-loja-com-a-central-design.md`):`

e acrescente, depois do item que começa por `  - **uma conversa por par loja × motoboy**`:

```markdown
  - **conversa com a central** (2026-10-27): item fixo "Central" (ícone de fone) no topo da lista, que existe antes da primeira mensagem (`itensDaLista`); tocar abre ou cria pelo `POST loja/conversas` com `central: true` (422 "A central não está disponível para conversa agora." sem ninguém na central). Um canal por loja ("Loja · <nome>", como a central vê no console), marcado no `meta` com `entregas_conversa_loja` e `entregas_conversa_central` (os dois com o uuid da loja), com os usuários ativos da loja e a central (`ChatComACentral::usuariosDaCentral`, quem abre o console). Completado ao abrir e a cada mensagem nova (`ConversasDaLoja::aoCriarMensagem`, no `ChatMessage::created`), sem tirar ninguém: quem a central adicionar pelo console fica. A lista traz `tipo` (`central` ou `motoboy`). A central responde pelo chat do console; o total de não lidas e o aviso sonoro contam a conversa;
```

e no item de testes acrescente `conversas-com-a-central.php`:

```markdown
  - testes: `scripts/teste-php/conversas-da-loja.php`, `conversas-com-a-central.php`, `mapa-da-loja.php` (`motoboyDoIdOpaco`) e `scripts/teste-portal/conversas.test.mjs`.
```

- [ ] **Passo 2: desenho.** Em `docs/superpowers/specs/2026-10-27-conversa-da-loja-com-a-central-design.md`, troque `` `entregas_conversa_central` = `true` `` por `` `entregas_conversa_central` = uuid do Vendor `` e o item "O canal se arruma ao abrir e a cada mensagem nova ... e quem sobra (motoboy, usuário de outra loja) sai." por "O canal ganha quem falta ao abrir e a cada mensagem nova (`ChatMessage::created`), inclusive quem saiu; ninguém sai (quem a central adicionar pelo console fica).".

- [ ] **Passo 3: histórico.** No fim de `docs/historico.md`:

```markdown
49. Conversa da loja com a central (2026-10-27; desenho `docs/superpowers/specs/2026-10-27-conversa-da-loja-com-a-central-design.md`): item fixo "Central" no topo de Conversas do portal; um canal por loja com os usuários dela e a central, que responde pelo chat do console (ver "Chat da loja com os motoboys e com a central" em `packages/customer-portal/CLAUDE.md`).
```

- [ ] **Passo 4: bateria inteira**

```bash
for f in scripts/teste-php/*.php; do case "$f" in *stubs*|*fixtures*|*stub-banco*) continue;; esac; out=$(PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs "$f" 2>&1) || echo "X $f: $(echo "$out" | grep -a "FALHA \|Fatal" | head -3)"; done; echo fim
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs 2>&1 | tail -8
```

Expected: só `fim` na primeira (nenhum `X`) e `fail 0` na segunda.

- [ ] **Passo 5: commit**

```bash
git add packages/customer-portal/CLAUDE.md docs/historico.md docs/superpowers/specs/2026-10-27-conversa-da-loja-com-a-central-design.md
git commit -m "Conversa da loja com a central: documentação"
```

- [ ] **Passo 6: push (só com o ok do Edgard) e deploy pelo Edgard:** `bash deploy/atualizar.sh api` e `bash deploy/atualizar.sh console` na VPS; depois, Ctrl+Shift+R no portal.
