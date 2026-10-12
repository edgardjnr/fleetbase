# Integração RestaurantePro → Entregas: API do Entregas (plano 1 de 3)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expor no Entregas a API `v1/entregas/integracao/*` (chave por loja), criar e despachar os pedidos que chegam por ela, cancelar pela origem e devolver os avisos de status por webhook assinado, mais as rotas de apoio do console e do app do motoboy.

**Architecture:** Tudo em `api/app` (nada em `packages/*/server` chega à produção). Uma pasta nova `App\Support\Entregas\Integracao` com classes pequenas: funções puras (validação do corpo, URL do webhook, sequência dos avisos, cobrança, painel) e adaptadores finos (chaves no banco, criação do pedido no molde do `CriadorDoPedidoIfood`, cancelamento no molde do `CancelamentoPeloIfood`, envio do webhook). As rotas externas autenticam pela chave no próprio controller (sem Sanctum). Os avisos saem por um job na fila `default`, enfileirado por um observador do `Order::updated` e por um comando de reconciliação a cada minuto.

**Tech Stack:** Laravel 10 (PHP 8.2), Fleetbase Fleet-Ops (models `Order`, `Place`, `Payload`, `Vendor`), Redis (trava e fila), testes em php-wasm (`scripts/teste-php/rodar.mjs`).

**Spec:** `docs/superpowers/specs/2026-10-12-integracao-restaurantepro-design.md` (Partes 1, 2 e as rotas de servidor da Parte 4).

---

## Antes de começar

- Trabalhe num ramo: `git checkout -b integracao-restaurantepro` (o diretório de trabalho tem `console/app/router.js` modificado e um PNG solto que **não** são desta tarefa: não os inclua em commit nenhum; use sempre `git add <arquivos>`).
- Confirme a raiz: `git rev-parse --show-toplevel` deve ser `C:/Users/Edgardjr/Documents/vibe coding/Delivery`.
- Testes PHP (sem PHP instalado), na raiz do repo, no Git Bash:
  ```bash
  PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/<teste>.php
  ```
  O teste imprime `PASSA`/`FALHA` por caso e termina com `FALHAS: <n>`; sai com 1 se houver falha.
- Sintaxe de arquivo PHP novo: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs <arquivo.php>...`
- Os testes rodam sobre os stubs de `scripts/teste-php/stubs-ifood.php` (DB em memória que lê as colunas das migrations, Cache com trava, Http com fila de respostas, Log, `now()` fixo em `2026-10-05 15:00:00`) e `stubs-ifood-fleetbase.php` (models falsos). Leia o cabeçalho dos dois antes da Task 1.
- Convenções da casa: comentários e logs em pt-BR, log com prefixo `[entregas] integração:` e **só ids** (nunca nome, telefone, endereço, chave ou segredo); datas gravadas com `now()->toDateTimeString()` (fuso do app); `DB::table` nas tabelas `entregas_*` (sem models).

## Mapa de arquivos

**Novos (servidor):**

| Arquivo | Responsabilidade |
|---|---|
| `api/database/migrations/2026_10_12_100000_create_entregas_integracao_lojas_table.php` | Tabela da chave e do webhook de cada loja |
| `api/database/migrations/2026_10_12_100100_create_entregas_integracao_pedidos_table.php` | Tabela dos dados de origem de cada pedido |
| `api/app/Support/Entregas/Integracao/Integracao.php` | Constantes, interruptor, link de rastreio, nome do sistema |
| `api/app/Support/Entregas/Integracao/ChavesDeIntegracao.php` | Gerar, achar, revogar a chave; conectar o webhook; segredo; marcas de uso e de falha |
| `api/app/Support/Entregas/Integracao/UrlDoWebhook.php` | Recusa de URL não https / rede privada (pura, com resolvedor injetável) |
| `api/app/Support/Entregas/Integracao/PedidoDaIntegracao.php` | Validação e normalização do corpo do `POST pedidos` (pura) |
| `api/app/Support/Entregas/Integracao/CobrancaDaIntegracao.php` | Texto da cobrança na porta (pura) |
| `api/app/Support/Entregas/Integracao/ErroDaIntegracao.php` | Erro com status HTTP para o controller |
| `api/app/Support/Entregas/Integracao/FalhaNoAviso.php` | Webhook não respondeu 2xx (o job tenta de novo) |
| `api/app/Support/Entregas/Integracao/GeocodificadorDaIntegracao.php` | Reserva: Google Geocoding quando o corpo vem sem coordenadas |
| `api/app/Support/Entregas/Integracao/PedidosDaIntegracao.php` | Leitura e gravação da `entregas_integracao_pedidos` |
| `api/app/Support/Entregas/Integracao/CriadorDoPedidoDaIntegracao.php` | Cria o Order (Place, Payload, linha) e despacha |
| `api/app/Support/Entregas/Integracao/CancelamentoPelaOrigem.php` | Cancelamento pedido pela origem, com a trava do pedido; texto do push |
| `api/app/Support/Entregas/Integracao/SequenciaDaIntegracao.php` | Quais avisos faltam, pelo estado do Order (pura) |
| `api/app/Support/Entregas/Integracao/AvisosDaIntegracao.php` | Monta, assina e envia os avisos; grava o último enviado |
| `api/app/Support/Entregas/Integracao/PainelDaIntegracao.php` | O que a tela Lojas e o painel do pedido mostram (pura) |
| `api/app/Support/Entregas/Integracao/DadosDaIntegracaoDoMotoboy.php` | O que o app do motoboy recebe do pedido (pura) |
| `api/app/Http/Controllers/Entregas/IntegracaoController.php` | Rotas externas `v1/entregas/integracao/*` |
| `api/app/Http/Controllers/Entregas/IntegracaoLojasController.php` | Rotas do console (chave da loja e painel do pedido) |
| `api/app/Jobs/Entregas/EnviarAvisoDaIntegracao.php` | Job dos avisos (um por pedido de cada vez) |
| `api/app/Listeners/Entregas/ObservadorDosPedidosDaIntegracao.php` | `Order::updated`/`OrderDriverAssigned` → job; registra quem cancelou |
| `api/app/Console/Commands/Entregas/AcompanharIntegracao.php` | `entregas:integracao-acompanhar`: refaz despacho falho e avisos que faltam |

**Alterados:** `api/config/services.php`, `api/app/Providers/RouteServiceProvider.php`, `api/app/Providers/AppServiceProvider.php`, `api/app/Console/Kernel.php`, `api/app/Support/Entregas/CalculoEntregas.php`, `api/app/Notifications/Entregas/AvisosDoMotoboy.php`, `api/app/Support/Entregas/CartaoDoAlarme.php`, `api/app/Http/Controllers/Entregas/MotoboyController.php`, `deploy/docker-stack.yml`, `deploy/stack.env.example`, `CLAUDE.md`.

**Testes:** `scripts/teste-php/stubs-integracao.php` (novo), `stubs-ifood.php` (chave única composta, Http com corpo cru), `integracao-migrations.php`, `integracao-chave.php`, `integracao-webhook-url.php`, `integracao-pedido.php`, `integracao-criador.php`, `integracao-cancelamento.php`, `integracao-rotas.php`, `integracao-sequencia.php`, `integracao-avisos.php`, `integracao-acompanhar.php`, `integracao-ligacoes.php`; ajustes em `ifood-ligacoes.php` e `imports-dos-providers.php`.

---

### Task 1: Config, migrations e stubs dos testes

**Files:**
- Modify: `api/config/services.php` (bloco `'entregas' => [`)
- Create: `api/database/migrations/2026_10_12_100000_create_entregas_integracao_lojas_table.php`
- Create: `api/database/migrations/2026_10_12_100100_create_entregas_integracao_pedidos_table.php`
- Modify: `scripts/teste-php/stubs-ifood.php` (esquema das migrations, chave única composta, Http com corpo cru)
- Create: `scripts/teste-php/stubs-integracao.php`
- Test: `scripts/teste-php/integracao-migrations.php`

- [ ] **Step 1: Escrever o teste**

`scripts/teste-php/integracao-migrations.php`:

```php
<?php

// Integração de pedidos de outros sistemas: as duas tabelas (lidas das migrations pelo banco em memória) e a config.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-migrations.php

require __DIR__ . '/stubs-integracao.php';

use Teste\Banco;

echo '== entregas_integracao_lojas' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
$repetida = excecao(fn () => Banco::inserir('entregas_integracao_lojas', ['company_uuid' => 'empresa-1', 'vendor_uuid' => 'vendor-b', 'chave_hash' => hash('sha256', CHAVE_DA_LOJA_A), 'chave_final' => 'AAAA'], false));
confere($repetida instanceof \Illuminate\Database\QueryException && $repetida->getCode() === '23000', 'chave_hash única');
confere(excecao(fn () => Banco::inserir('entregas_integracao_lojas', ['coluna_que_nao_existe' => 1], false)) instanceof RuntimeException, 'coluna desconhecida falha (o esquema veio da migration)');
confere(Banco::tamanhoDe('entregas_integracao_lojas', 'sistema') === 40 && Banco::tamanhoDe('entregas_integracao_lojas', 'webhook_url') === 500, 'tamanhos de sistema e webhook_url');

echo '== entregas_integracao_pedidos' . PHP_EOL;
$linha = ['company_uuid' => 'empresa-1', 'vendor_uuid' => 'vendor-a', 'order_uuid' => 'order-1', 'pedido_externo_id' => 'ord-1', 'cobranca_forma' => 'pix'];
confere(Banco::inserir('entregas_integracao_pedidos', $linha, false), 'insere');
$erro = excecao(fn () => Banco::inserir('entregas_integracao_pedidos', ['order_uuid' => 'order-2'] + $linha, false));
confere($erro instanceof \Illuminate\Database\QueryException && $erro->getCode() === '23000', 'mesmo pedido_externo_id na mesma loja: chave única (vendor_uuid + pedido_externo_id)');
confere(Banco::inserir('entregas_integracao_pedidos', ['vendor_uuid' => 'vendor-b', 'order_uuid' => 'order-3'] + $linha, false), 'o mesmo id externo em outra loja passa');
$erro = excecao(fn () => Banco::inserir('entregas_integracao_pedidos', ['vendor_uuid' => 'vendor-c', 'pedido_externo_id' => 'ord-9'] + $linha, false));
confere($erro instanceof \Illuminate\Database\QueryException, 'order_uuid único');
$nova = (new Teste\Consulta('entregas_integracao_pedidos'))->where('order_uuid', 'order-3')->first();
confere($nova->cobranca_centavos === 0 && $nova->pago_mesmo_cancelado === false && $nova->sem_localizacao === false && $nova->ultimo_aviso === null, 'padrões da migration');
confere(Banco::tamanhoDe('entregas_integracao_pedidos', 'pedido_externo_id') === 100 && Banco::tamanhoDe('entregas_integracao_pedidos', 'numero') === 20, 'tamanhos');

echo '== Config' . PHP_EOL;
$config = file_get_contents('/repo/api/config/services.php');
confere(str_contains($config, "'integracao' => env('ENTREGAS_INTEGRACAO')"), 'services.entregas.integracao');
confere(str_contains($config, "'console_url' => env('ENTREGAS_CONSOLE_URL', 'https://entregas.restaurantepro.com.br')"), 'services.entregas.console_url');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-migrations.php`
Expected: erro de PHP (`stubs-integracao.php` não existe).

- [ ] **Step 3: Config**

Em `api/config/services.php`, dentro de `'entregas' => [`, logo depois da linha `'app_versao_minima' => env('ENTREGAS_APP_VERSAO_MINIMA'),`, acrescente:

```php
        // integração de pedidos de outros sistemas (RestaurantePro primeiro): 1 liga as rotas v1/entregas/integracao/*,
        // os avisos por webhook e o entregas:integracao-acompanhar; vazio = tudo responde 409 e nenhum aviso sai
        'integracao' => env('ENTREGAS_INTEGRACAO'),
        // domínio do console, para o link de rastreio do cliente final (…/~/track-order?order=<código>)
        'console_url' => env('ENTREGAS_CONSOLE_URL', 'https://entregas.restaurantepro.com.br'),
```

- [ ] **Step 4: Migrations**

`api/database/migrations/2026_10_12_100000_create_entregas_integracao_lojas_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: a chave de integração de cada loja (App\Support\Entregas\Integracao). Guarda só o SHA-256 da
 * chave (`chave_hash`, único) e os 4 últimos caracteres; a chave em texto aparece uma vez, na tela Lojas. Uma ativa por
 * loja (`revogada_em` nulo), garantido no código (ChavesDeIntegracao::gerar revoga a anterior na mesma transação).
 * `webhook_url` e `webhook_segredo` (cifrado com encrypt()) vêm do POST v1/entregas/integracao/conexao.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_integracao_lojas', function (Blueprint $table) {
            $table->id();
            $table->char('company_uuid', 36)->index();
            $table->char('vendor_uuid', 36)->index();
            $table->char('chave_hash', 64)->unique();
            $table->string('chave_final', 4);
            // o sistema que conectou ("RestaurantePro"): vai nas notas do pedido e no selo
            $table->string('sistema', 40)->nullable();
            $table->string('webhook_url', 500)->nullable();
            $table->text('webhook_segredo')->nullable();
            $table->timestamp('conectada_em')->nullable();
            $table->timestamp('ultimo_uso_em')->nullable();
            $table->timestamp('ultima_falha_aviso_em')->nullable();
            $table->string('ultima_falha_aviso', 190)->nullable();
            $table->timestamp('revogada_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_integracao_lojas');
    }
};
```

`api/database/migrations/2026_10_12_100100_create_entregas_integracao_pedidos_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: os dados de origem de cada pedido criado pela integração (App\Support\Entregas\Integracao).
 * Fica fora do `meta` do Order de propósito: o `meta` sai na API v1 e no socket, e aqui estão o telefone do cliente e a
 * cobrança. (vendor_uuid, pedido_externo_id) único = o mesmo pedido da origem nunca vira dois (o reenvio devolve o
 * mesmo). `ultimo_aviso`/`motoboy_avisado` guiam os avisos de volta (AvisosDaIntegracao); `despachado_em`, a
 * reconciliação do despacho (entregas:integracao-acompanhar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_integracao_pedidos', function (Blueprint $table) {
            $table->id();
            $table->char('company_uuid', 36)->index();
            $table->char('vendor_uuid', 36);
            $table->char('order_uuid', 36)->nullable()->unique();
            $table->string('pedido_externo_id', 100);
            $table->string('sistema', 40)->nullable();
            // número curto da origem (o código da comanda), o mesmo do internal_id do Order
            $table->string('numero', 20)->nullable();
            $table->string('cliente_nome', 120)->nullable();
            $table->string('cliente_telefone', 20)->nullable();
            $table->string('complemento', 190)->nullable();
            $table->string('referencia', 190)->nullable();
            $table->text('observacoes')->nullable();
            // dinheiro | cartao | pix | pago; o valor e o troco em centavos
            $table->string('cobranca_forma', 20);
            $table->unsignedInteger('cobranca_centavos')->default(0);
            $table->unsignedInteger('troco_para_centavos')->nullable();
            $table->boolean('sem_localizacao')->default(false);
            $table->timestamp('despachado_em')->nullable();
            // último aviso aceito pelo sistema de origem: aceito | a_caminho | concluido | cancelado
            $table->string('ultimo_aviso', 20)->nullable();
            $table->char('motoboy_avisado', 36)->nullable();
            $table->timestamp('cancelado_em')->nullable();
            // loja (a origem ou o portal) | central | sistema (expirado)
            $table->string('cancelado_por', 20)->nullable();
            $table->boolean('pago_mesmo_cancelado')->default(false);
            $table->timestamps();
            $table->unique(['vendor_uuid', 'pedido_externo_id']);
            // entregas:integracao-acompanhar: só as últimas 24 h
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_integracao_pedidos');
    }
};
```

- [ ] **Step 5: Stubs do banco e do Http**

Em `scripts/teste-php/stubs-ifood.php`:

1. No `Banco::esquema()`, troque o `array_merge(` dos `$arquivos` por:

```php
            $arquivos = array_merge(
                glob(dirname(__DIR__, 2) . '/api/database/migrations/*_entregas_ifood_*_table.php') ?: [],
                glob(dirname(__DIR__, 2) . '/api/database/migrations/*_entregas_distribuicao_*.php') ?: [],
                glob(dirname(__DIR__, 2) . '/api/database/migrations/*_entregas_integracao_*_table.php') ?: []
            );
```

2. Ainda no `esquema()`, onde cada `$tabela` é inicializada (`self::$esquema[$tabela] = ['colunas' => [], 'unicas' => [], 'padroes' => []];`), troque por:

```php
                    self::$esquema[$tabela] = ['colunas' => [], 'unicas' => [], 'padroes' => [], 'compostas' => []];
```

3. No laço `foreach ($blueprints as [$tabela, $blueprint])`, logo depois de `$tamanhos = [];`, acrescente `$compostas = [];`. No laço das colunas, troque a linha `} elseif (is_string($primeiro)) {` por:

```php
                        } elseif ($coluna->tipo === 'unique' && is_array($primeiro)) {
                            // $table->unique([...]): chave única de várias colunas
                            $compostas[] = $primeiro;
                        } elseif (is_string($primeiro)) {
```

e, no `self::$esquema[$tabela] = [...]` do fim do laço, acrescente a linha:

```php
                        'compostas' => array_merge(self::$esquema[$tabela]['compostas'] ?? [], $compostas),
```

4. No fim do `Banco::colunaRepetida()`, antes do `return null;`, acrescente:

```php
            // chaves únicas compostas: repetida quando todas as colunas repetem (com alguma NULL, não conta, como no MySQL)
            foreach (self::esquema()[$tabela]['compostas'] ?? [] as $colunas) {
                $valores = array_map(fn ($c) => $linha[$c] ?? null, $colunas);
                if (in_array(null, $valores, true)) {
                    continue;
                }
                foreach ($existentes as $existente) {
                    if (array_map(fn ($c) => $existente[$c] ?? null, $colunas) === $valores) {
                        return implode('+', $colunas);
                    }
                }
            }
```

5. Nas duas chamadas a `erroDeRepetida(` (no `Banco::inserir` e no `Consulta::update`), o valor passa a aceitar a chave composta: troque `$linha[$repetida]` por `$linha[$repetida] ?? $repetida` e `$novas[$id][$repetida]` por `$novas[$id][$repetida] ?? $repetida`.

6. No `class PedidoHttp`, troque a linha `private array $opcoes = [...]` por:

```php
        private array $opcoes = ['form' => false, 'token' => null, 'headers' => [], 'timeout' => null, 'options' => [], 'corpo' => null];
```

e acrescente, depois de `public function timeout(...)`:

```php
        // o webhook da integração: corpo cru (JSON já assinado) e sem seguir redirecionamento
        public function withOptions(array $opcoes) { $this->opcoes['options'] = array_merge($this->opcoes['options'], $opcoes); return $this; }
        public function withBody($corpo, $tipo = 'application/json') { $this->opcoes['corpo'] = $corpo; return $this; }
```

- [ ] **Step 6: Stubs da integração**

`scripts/teste-php/stubs-integracao.php`:

```php
<?php

// Stubs dos testes da integração de pedidos de outros sistemas (scripts/teste-php/integracao-*.php), sobre os do iFood
// (banco em memória, Cache, Http, Log, models do Fleetbase): o User do core (quem cancelou), a loja integrada de teste,
// o pedido de teste e o corpo padrão do POST pedidos.

namespace {
    require_once __DIR__ . '/stubs-ifood.php';
    require_once __DIR__ . '/stubs-ifood-fleetbase.php';
}

namespace Fleetbase\Models {
    if (!class_exists(User::class, false)) {
        #[\AllowDynamicProperties]
        class User
        {
            use \Teste\ModeloDeTeste;
            public $uuid;
            public $type;
        }
    }
}

namespace {
    define('CHAVE_DA_LOJA_A', 'ent_live_' . str_repeat('A', 40));

    /** Zera tudo e liga a integração (com a chave do Google para a geocodificação de reserva). */
    function reiniciarIntegracao(): void
    {
        reiniciarIfood();
        reiniciarFleetbase();
        \Fleetbase\Models\User::$todos                                   = [];
        \Teste\Config::$valores['services.entregas.integracao']          = '1';
        \Teste\Config::$valores['services.entregas.console_url']         = 'https://entregas.restaurantepro.com.br';
        \Teste\Config::$valores['services.google_maps.api_key']          = 'chave-google';
    }

    /** A loja A (vendor-a) com a chave CHAVE_DA_LOJA_A; conectada = com webhook e segredo ("segredo-a"). Devolve a linha. */
    function lojaIntegradaA(bool $conectada = true): object
    {
        \Teste\Banco::inserir('entregas_integracao_lojas', [
            'company_uuid'    => 'empresa-1',
            'vendor_uuid'     => 'vendor-a',
            'chave_hash'      => hash('sha256', CHAVE_DA_LOJA_A),
            'chave_final'     => 'AAAA',
            'sistema'         => $conectada ? 'RestaurantePro' : null,
            'webhook_url'     => $conectada ? 'https://api.restaurantepro.com.br/functions/v1/entregas-webhook?t=abc' : null,
            'webhook_segredo' => $conectada ? encrypt('segredo-a') : null,
            'conectada_em'    => $conectada ? '2026-10-05 09:00:00' : null,
            'created_at'      => '2026-10-05 08:00:00',
            'updated_at'      => '2026-10-05 08:00:00',
        ], false);

        return (new \Teste\Consulta('entregas_integracao_lojas'))->where('vendor_uuid', 'vendor-a')->first();
    }

    /** Resolvedor de DNS falso: todo nome aponta para um IP público. */
    function resolverPublico(): \Closure
    {
        return fn (string $host) => ['200.150.10.20'];
    }

    /** O corpo padrão do POST pedidos (dados fictícios), com $mudancas por cima (array_replace_recursive). */
    function corpoDoPedido(array $mudancas = []): array
    {
        return array_replace_recursive([
            'pedido_externo_id' => 'ord-6f1c',
            'numero'            => '4821',
            'cliente'           => ['nome' => 'Maria Ficticia', 'telefone' => '(16) 99999-0000'],
            'entrega'           => [
                'endereco'    => 'Rua Ficticia, 45',
                'complemento' => 'Ap 12',
                'bairro'      => 'Jardim Ficticio',
                'cidade'      => 'Ribeirão Preto',
                'uf'          => 'sp',
                'cep'         => '14090000',
                'referencia'  => 'Perto da praça',
                'latitude'    => -21.17,
                'longitude'   => -47.81,
            ],
            'cobranca'          => ['forma' => 'dinheiro', 'valor' => 58.9, 'troco_para' => 100],
            'observacoes'       => 'Sem cebola',
        ], $mudancas);
    }

    /**
     * Um Order de teste da loja A (aceito pelo driver-1, ainda na coleta) e a linha dele na entregas_integracao_pedidos.
     * Devolve o Order.
     */
    function pedidoDaIntegracao(array $order = [], array $linha = []): \Fleetbase\FleetOps\Models\Order
    {
        $pedido = new \Fleetbase\FleetOps\Models\Order($order + [
            'uuid' => 'order-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1', 'customer_uuid' => 'vendor-a',
            'status' => 'started', 'started' => true, 'dispatched' => true, 'adhoc' => false, 'driver_assigned_uuid' => 'driver-1',
            'tracking' => 'RP0001',
        ]);
        if ($pedido->driver_assigned_uuid) {
            $motoboy = \Fleetbase\FleetOps\Models\Driver::where('uuid', $pedido->driver_assigned_uuid)->first();
            if (!$motoboy) {
                $motoboy = new \Fleetbase\FleetOps\Models\Driver(['uuid' => $pedido->driver_assigned_uuid, 'public_id' => 'driver_' . substr($pedido->driver_assigned_uuid, -1), 'name' => 'Motoboy Ficticio', 'photo_url' => 'https://exemplo.com/foto.png']);
                \Fleetbase\FleetOps\Models\Driver::$todos[] = $motoboy;
            }
            $pedido->driverAssigned = $motoboy;
        }
        \Fleetbase\FleetOps\Models\Order::$todos[] = $pedido;

        \Teste\Banco::inserir('entregas_integracao_pedidos', $linha + [
            'company_uuid' => 'empresa-1', 'vendor_uuid' => 'vendor-a', 'order_uuid' => $pedido->uuid, 'pedido_externo_id' => 'ord-1',
            'sistema' => 'RestaurantePro', 'numero' => '4821', 'cliente_nome' => 'Maria Ficticia', 'cliente_telefone' => '16999990000',
            'cobranca_forma' => 'dinheiro', 'cobranca_centavos' => 5890, 'troco_para_centavos' => 10000,
            'despachado_em' => '2026-10-05 14:50:00', 'created_at' => '2026-10-05 14:50:00', 'updated_at' => '2026-10-05 14:50:00',
        ], false);

        return $pedido;
    }

    /** A linha do pedido (relida do banco em memória). */
    function linhaDaIntegracao(string $orderUuid = 'order-1'): ?object
    {
        return (new \Teste\Consulta('entregas_integracao_pedidos'))->where('order_uuid', $orderUuid)->first();
    }
}
```

- [ ] **Step 7: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-migrations.php`
Expected: todos `PASSA`, `FALHAS: 0`.

Rode também os testes do iFood que usam o banco em memória, para garantir que a mudança do stub não quebrou nada:

```bash
for f in ifood-migrations ifood-criador ifood-processar ifood-acompanhar distribuicao-ciclo; do PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$f.php | tail -1; done
```
Expected: `FALHAS: 0` em todos.

- [ ] **Step 8: Commit**

```bash
git add api/config/services.php api/database/migrations/2026_10_12_100000_create_entregas_integracao_lojas_table.php api/database/migrations/2026_10_12_100100_create_entregas_integracao_pedidos_table.php scripts/teste-php/stubs-ifood.php scripts/teste-php/stubs-integracao.php scripts/teste-php/integracao-migrations.php
git commit -m "Integração: tabelas da chave da loja e dos pedidos de origem

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `Integracao` e `ChavesDeIntegracao`

**Files:**
- Create: `api/app/Support/Entregas/Integracao/Integracao.php`
- Create: `api/app/Support/Entregas/Integracao/ChavesDeIntegracao.php`
- Test: `scripts/teste-php/integracao-chave.php`

- [ ] **Step 1: Escrever o teste**

`scripts/teste-php/integracao-chave.php`:

```php
<?php

// Integração: a chave da loja (gerar, achar, revogar), a conexão do webhook, o segredo e as marcas de uso e de falha.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-chave.php

require __DIR__ . '/stubs-integracao.php';

use App\Support\Entregas\Integracao\ChavesDeIntegracao;
use App\Support\Entregas\Integracao\Integracao;
use Teste\Banco;
use Teste\Relogio;

function ativaDaLojaA(): ?object
{
    return ChavesDeIntegracao::ativa('empresa-1', 'vendor-a');
}

echo '== Gerar' . PHP_EOL;
reiniciarIntegracao();
$chave = ChavesDeIntegracao::gerar('empresa-1', 'vendor-a');
confere(preg_match('/^ent_live_[0-9A-Za-z]{40}$/', $chave) === 1, 'formato ent_live_ + 40 caracteres');
$linhas = Banco::linhas('entregas_integracao_lojas');
confere(count($linhas) === 1 && $linhas[0]->chave_hash === hash('sha256', $chave) && $linhas[0]->chave_final === substr($chave, -4), 'guarda só o hash e os 4 últimos');
confere(!str_contains(json_encode($linhas), $chave), 'a chave em texto não fica no banco');
confere(logou('[entregas] integração: chave gerada', 'info') && logsSem([$chave]), 'log sem a chave');
$outra = ChavesDeIntegracao::gerar('empresa-1', 'vendor-a');
confere($outra !== $chave && ChavesDeIntegracao::daChave($chave) === null && ChavesDeIntegracao::daChave($outra)?->vendor_uuid === 'vendor-a', 'gerar de novo revoga a anterior');
confere(ativaDaLojaA()->chave_hash === hash('sha256', $outra), 'ativa = a mais nova');

echo '== daChave' . PHP_EOL;
foreach ([null, '', 'flb_live_' . str_repeat('A', 40), 'ent_live_curta', $outra . 'x'] as $invalida) {
    confere(ChavesDeIntegracao::daChave($invalida) === null, 'inválida: ' . var_export($invalida, true));
}
confere(ChavesDeIntegracao::daChave(' ' . $outra . ' ')?->vendor_uuid === 'vendor-a', 'espaços em volta não atrapalham');

echo '== Revogar' . PHP_EOL;
confere(ChavesDeIntegracao::revogar('empresa-1', 'vendor-a') === true && ChavesDeIntegracao::daChave($outra) === null && ativaDaLojaA() === null, 'revogada não autentica');
confere(ChavesDeIntegracao::revogar('empresa-1', 'vendor-a') === false, 'sem chave ativa: nada a revogar');
confere(logou('[entregas] integração: chave revogada', 'info'), 'log da revogação');

echo '== Conectar e segredo' . PHP_EOL;
reiniciarIntegracao();
$loja    = lojaIntegradaA(false);
$segredo = ChavesDeIntegracao::conectar($loja, '  RestaurantePro ', 'https://api.restaurantepro.com.br/functions/v1/entregas-webhook?t=abc');
$lida    = ativaDaLojaA();
confere(preg_match('/^[0-9a-f]{64}$/', $segredo) === 1 && $lida->webhook_segredo !== $segredo && str_starts_with($lida->webhook_segredo, 'cifrado:'), 'segredo de 64 hex, guardado cifrado');
confere(ChavesDeIntegracao::segredo($lida) === $segredo && $lida->sistema === 'RestaurantePro' && $lida->conectada_em === '2026-10-05 15:00:00', 'segredo legível, sistema limpo, conectada agora');
confere($loja->webhook_url === 'https://api.restaurantepro.com.br/functions/v1/entregas-webhook?t=abc', 'o objeto de quem chamou também fica atualizado');
confere(logsSem([$segredo]), 'log sem o segredo');
$lida->webhook_segredo = 'texto-corrompido';
confere(ChavesDeIntegracao::segredo($lida) === null && logou('segredo do webhook ilegível', 'warning'), 'segredo ilegível: null com aviso');
$lida->webhook_segredo = null;
confere(ChavesDeIntegracao::segredo($lida) === null, 'sem segredo: null');

echo '== Uso' . PHP_EOL;
reiniciarIntegracao();
ChavesDeIntegracao::marcarUso(lojaIntegradaA());
confere(ativaDaLojaA()->ultimo_uso_em === '2026-10-05 15:00:00', 'marca o uso');
Relogio::$agora = '2026-10-05 15:00:30';
ChavesDeIntegracao::marcarUso(ativaDaLojaA());
confere(ativaDaLojaA()->ultimo_uso_em === '2026-10-05 15:00:00', 'no mesmo minuto não grava de novo');
Relogio::$agora = '2026-10-05 15:01:05';
ChavesDeIntegracao::marcarUso(ativaDaLojaA());
confere(ativaDaLojaA()->ultimo_uso_em === '2026-10-05 15:01:05', 'depois de 1 min grava');

echo '== Falha do aviso' . PHP_EOL;
ChavesDeIntegracao::registrarFalhaDeAviso(ativaDaLojaA(), str_repeat('x', 300));
confere(mb_strlen(ativaDaLojaA()->ultima_falha_aviso) === 190 && ativaDaLojaA()->ultima_falha_aviso_em === '2026-10-05 15:01:05', 'registra a falha (até 190 caracteres)');
ChavesDeIntegracao::limparFalhaDeAviso(ativaDaLojaA());
confere(ativaDaLojaA()->ultima_falha_aviso === null && ativaDaLojaA()->ultima_falha_aviso_em === null, 'limpa a falha');

echo '== Integracao' . PHP_EOL;
confere(Integracao::ligada(), 'ligada com ENTREGAS_INTEGRACAO=1');
\Teste\Config::$valores['services.entregas.integracao'] = '';
confere(!Integracao::ligada(), 'vazia = desligada');
confere(Integracao::linkDeRastreio('RP12345') === 'https://entregas.restaurantepro.com.br/~/track-order?order=RP12345', 'link de rastreio');
confere(Integracao::linkDeRastreio(null) === null && Integracao::linkDeRastreio('  ') === null, 'sem código: sem link');
confere(Integracao::nomeDoSistema('Saipos <b>') === 'Saipos b' && Integracao::nomeDoSistema('') === 'Integração' && Integracao::nomeDoSistema(null) === 'Integração', 'nome do sistema limpo, com padrão');
confere(mb_strlen(Integracao::nomeDoSistema(str_repeat('a', 60))) === 40, 'nome do sistema com até 40 caracteres');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-chave.php`
Expected: erro `Class "App\Support\Entregas\Integracao\ChavesDeIntegracao" not found`.

- [ ] **Step 3: Implementar `Integracao`**

`api/app/Support/Entregas/Integracao/Integracao.php`:

```php
<?php

namespace App\Support\Entregas\Integracao;

/**
 * Entregas RestaurantePro: integração de pedidos vindos de outros sistemas (o RestaurantePro primeiro). Constantes, o
 * interruptor geral (ENTREGAS_INTEGRACAO, `services.entregas.integracao`), o link de rastreio do cliente final e o nome
 * do sistema que vai nas notas do pedido. Desenho: docs/superpowers/specs/2026-10-12-integracao-restaurantepro-design.md.
 */
final class Integracao
{
    public const LOJAS   = 'entregas_integracao_lojas';
    public const PEDIDOS = 'entregas_integracao_pedidos';

    public const PREFIXO_DA_CHAVE = 'ent_live_';
    public const TAMANHO_DA_CHAVE = 40;

    public const SISTEMA_PADRAO = 'Integração';

    public static function ligada(): bool
    {
        return filter_var(config('services.entregas.integracao'), FILTER_VALIDATE_BOOLEAN);
    }

    /** A página de rastreio do console, já com o código (`?order=` abre preenchida), ou null sem código. */
    public static function linkDeRastreio(?string $codigo): ?string
    {
        $codigo = trim((string) $codigo);
        if ($codigo === '') {
            return null;
        }
        $base = rtrim((string) config('services.entregas.console_url', 'https://entregas.restaurantepro.com.br'), '/');

        return $base . '/~/track-order?order=' . rawurlencode($codigo);
    }

    /** "RestaurantePro": só letras, números, espaço, ponto, hífen e sublinhado, até 40 caracteres; vazio = "Integração". */
    public static function nomeDoSistema(?string $sistema): string
    {
        $limpo = trim(preg_replace('/[^\p{L}\p{N} ._-]+/u', '', (string) $sistema) ?? '');

        return $limpo === '' ? static::SISTEMA_PADRAO : mb_substr($limpo, 0, 40);
    }
}
```

- [ ] **Step 4: Implementar `ChavesDeIntegracao`**

`api/app/Support/Entregas/Integracao/ChavesDeIntegracao.php`:

```php
<?php

namespace App\Support\Entregas\Integracao;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: a chave de integração de cada loja (entregas_integracao_lojas).
 *
 * - gerar: `ent_live_` + 40 caracteres de random_int; guarda só o SHA-256 e os 4 últimos; revoga a anterior na mesma
 *   transação (uma ativa por loja). A chave em texto só sai daqui (tela Lojas, uma vez).
 * - daChave: a loja de um Bearer (formato exato, hash, não revogada) ou null.
 * - conectar: o sistema de origem registra a URL dos avisos; sai um segredo novo (64 hex), guardado com encrypt(), que
 *   só volta nessa resposta. O segredo assina os avisos (HMAC-SHA256, AvisosDaIntegracao).
 * - marcarUso: no máximo uma gravação por minuto (USO_A_CADA_SEGUNDOS), para não escrever a cada pedido.
 */
final class ChavesDeIntegracao
{
    public const ALFABETO = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public const USO_A_CADA_SEGUNDOS = 60;

    /** Gera a chave nova da loja (revoga a anterior) e devolve o texto, que só aparece aqui. */
    public static function gerar(string $companyUuid, string $vendorUuid): string
    {
        $chave = Integracao::PREFIXO_DA_CHAVE . static::aleatorio(Integracao::TAMANHO_DA_CHAVE);
        $agora = now()->toDateTimeString();

        DB::transaction(function () use ($companyUuid, $vendorUuid, $chave, $agora) {
            static::revogarAtivas($companyUuid, $vendorUuid, $agora);
            DB::table(Integracao::LOJAS)->insert([
                'company_uuid' => $companyUuid,
                'vendor_uuid'  => $vendorUuid,
                'chave_hash'   => static::hash($chave),
                'chave_final'  => substr($chave, -4),
                'created_at'   => $agora,
                'updated_at'   => $agora,
            ]);
        });

        Log::info('[entregas] integração: chave gerada', ['vendor' => $vendorUuid]);

        return $chave;
    }

    /** Revoga a chave ativa da loja; false se não havia. */
    public static function revogar(string $companyUuid, string $vendorUuid): bool
    {
        $revogadas = static::revogarAtivas($companyUuid, $vendorUuid, now()->toDateTimeString());
        if ($revogadas > 0) {
            Log::info('[entregas] integração: chave revogada', ['vendor' => $vendorUuid]);
        }

        return $revogadas > 0;
    }

    /** A linha ativa da loja (não revogada), ou null. */
    public static function ativa(string $companyUuid, string $vendorUuid): ?object
    {
        return DB::table(Integracao::LOJAS)
            ->where('company_uuid', $companyUuid)
            ->where('vendor_uuid', $vendorUuid)
            ->whereNull('revogada_em')
            ->orderBy('id', 'desc')
            ->first();
    }

    /** A loja da chave (Bearer), ou null: formato errado, chave desconhecida ou revogada. */
    public static function daChave(?string $chave): ?object
    {
        $chave = trim((string) $chave);
        if (!str_starts_with($chave, Integracao::PREFIXO_DA_CHAVE) || strlen($chave) !== strlen(Integracao::PREFIXO_DA_CHAVE) + Integracao::TAMANHO_DA_CHAVE) {
            return null;
        }

        return DB::table(Integracao::LOJAS)->where('chave_hash', static::hash($chave))->whereNull('revogada_em')->first();
    }

    /** Registra o sistema e a URL dos avisos e devolve o segredo novo (que só sai aqui). */
    public static function conectar(object $loja, string $sistema, string $url): string
    {
        $segredo = bin2hex(random_bytes(32));
        static::atualizar($loja, [
            'sistema'               => Integracao::nomeDoSistema($sistema),
            'webhook_url'           => $url,
            'webhook_segredo'       => encrypt($segredo),
            'conectada_em'          => now()->toDateTimeString(),
            'ultima_falha_aviso'    => null,
            'ultima_falha_aviso_em' => null,
        ]);
        Log::info('[entregas] integração: conectada', ['vendor' => $loja->vendor_uuid, 'sistema' => $loja->sistema]);

        return $segredo;
    }

    /** O segredo do webhook em texto, ou null (sem conexão ou ilegível: APP_KEY trocado). */
    public static function segredo(object $loja): ?string
    {
        if (empty($loja->webhook_segredo)) {
            return null;
        }

        try {
            $segredo = decrypt($loja->webhook_segredo);

            return is_string($segredo) && $segredo !== '' ? $segredo : null;
        } catch (\Throwable $e) {
            Log::warning('[entregas] integração: segredo do webhook ilegível (APP_KEY mudou?)', ['vendor' => $loja->vendor_uuid]);

            return null;
        }
    }

    /** Marca o último uso da chave, no máximo uma vez por minuto. */
    public static function marcarUso(object $loja): void
    {
        $agora  = now();
        $ultimo = $loja->ultimo_uso_em ? Carbon::parse(substr((string) $loja->ultimo_uso_em, 0, 19)) : null;
        if ($ultimo && $agora->getTimestamp() - $ultimo->getTimestamp() < static::USO_A_CADA_SEGUNDOS) {
            return;
        }
        static::atualizar($loja, ['ultimo_uso_em' => $agora->toDateTimeString()]);
    }

    /** O último aviso não chegou ao sistema de origem: a tela Lojas mostra o motivo. */
    public static function registrarFalhaDeAviso(object $loja, string $motivo): void
    {
        static::atualizar($loja, ['ultima_falha_aviso' => mb_substr($motivo, 0, 190), 'ultima_falha_aviso_em' => now()->toDateTimeString()]);
    }

    public static function limparFalhaDeAviso(object $loja): void
    {
        if ($loja->ultima_falha_aviso === null && $loja->ultima_falha_aviso_em === null) {
            return;
        }
        static::atualizar($loja, ['ultima_falha_aviso' => null, 'ultima_falha_aviso_em' => null]);
    }

    /** Grava (com updated_at) e copia os valores para o objeto de quem chamou. */
    public static function atualizar(object $loja, array $valores): void
    {
        $valores['updated_at'] = now()->toDateTimeString();
        DB::table(Integracao::LOJAS)->where('id', $loja->id)->update($valores);
        foreach ($valores as $coluna => $valor) {
            $loja->$coluna = $valor;
        }
    }

    public static function hash(string $chave): string
    {
        return hash('sha256', $chave);
    }

    protected static function revogarAtivas(string $companyUuid, string $vendorUuid, string $agora): int
    {
        return DB::table(Integracao::LOJAS)
            ->where('company_uuid', $companyUuid)
            ->where('vendor_uuid', $vendorUuid)
            ->whereNull('revogada_em')
            ->update(['revogada_em' => $agora, 'updated_at' => $agora]);
    }

    protected static function aleatorio(int $tamanho): string
    {
        $texto  = '';
        $maximo = strlen(static::ALFABETO) - 1;
        for ($i = 0; $i < $tamanho; $i++) {
            $texto .= static::ALFABETO[random_int(0, $maximo)];
        }

        return $texto;
    }
}
```

- [ ] **Step 5: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-chave.php`
Expected: `FALHAS: 0`.

- [ ] **Step 6: Commit**

```bash
git add api/app/Support/Entregas/Integracao/Integracao.php api/app/Support/Entregas/Integracao/ChavesDeIntegracao.php scripts/teste-php/integracao-chave.php
git commit -m "Integração: chave da loja, conexão do webhook e segredo

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `UrlDoWebhook`

**Files:**
- Create: `api/app/Support/Entregas/Integracao/UrlDoWebhook.php`
- Test: `scripts/teste-php/integracao-webhook-url.php`

- [ ] **Step 1: Escrever o teste**

`scripts/teste-php/integracao-webhook-url.php`:

```php
<?php

// Integração: a URL dos avisos só pode ser https pública (sem sondar a VPS pela rede interna).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-webhook-url.php

require __DIR__ . '/stubs-integracao.php';

use App\Support\Entregas\Integracao\UrlDoWebhook;

$publico = resolverPublico();

echo '== Aceitas' . PHP_EOL;
confere(UrlDoWebhook::recusa('https://api.restaurantepro.com.br/functions/v1/entregas-webhook?t=abc', $publico) === null, 'https público');
confere(UrlDoWebhook::recusa('https://api.exemplo.com.br:443/x', $publico) === null, 'porta 443 explícita');
confere(UrlDoWebhook::recusa('https://200.150.10.20/x', $publico) === null, 'IP público direto');

echo '== Recusadas' . PHP_EOL;
foreach ([
    'http://api.restaurantepro.com.br/x'          => 'http',
    'ftp://api.exemplo.com.br/x'                  => 'outro esquema',
    'não é url'                                   => 'texto',
    'https://localhost/x'                         => 'localhost',
    'https://algo.localhost/x'                    => '*.localhost',
    'https://127.0.0.1/x'                         => 'loopback',
    'https://0.0.0.0/x'                           => '0.0.0.0',
    'https://10.0.0.5/x'                          => '10.x',
    'https://172.16.3.4/x'                        => '172.16.x',
    'https://192.168.0.10/x'                      => '192.168.x',
    'https://169.254.169.254/latest/meta-data'    => 'metadados da nuvem',
    'https://[::1]/x'                             => 'IPv6 loopback',
    'https://[fd00::1]/x'                         => 'IPv6 privado',
    'https://entregas_application/x'              => 'nome do Swarm, sem ponto',
    'https://usuario:senha@api.exemplo.com.br/x'  => 'com usuário e senha',
    'https://api.exemplo.com.br:8443/x'           => 'porta fora da 443',
] as $url => $caso) {
    confere(UrlDoWebhook::recusa($url, $publico) !== null, "recusa: {$caso}");
}
confere(UrlDoWebhook::recusa('https://interno.exemplo.com.br/x', fn ($host) => ['10.0.0.7']) !== null, 'nome que resolve para IP privado');
confere(UrlDoWebhook::recusa('https://misto.exemplo.com.br/x', fn ($host) => ['200.150.10.20', '192.168.1.1']) !== null, 'nome com um dos IPs privado');
confere(UrlDoWebhook::recusa('https://sumiu.exemplo.com.br/x', fn ($host) => []) !== null, 'nome que não resolve');
confere(UrlDoWebhook::recusa('https://' . str_repeat('a', 500) . '.com.br/x', $publico) !== null, 'URL longa demais');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-webhook-url.php`
Expected: erro `Class "App\Support\Entregas\Integracao\UrlDoWebhook" not found`.

- [ ] **Step 3: Implementar**

`api/app/Support/Entregas/Integracao/UrlDoWebhook.php`:

```php
<?php

namespace App\Support\Entregas\Integracao;

/**
 * Entregas RestaurantePro: a URL dos avisos de um sistema de origem só pode ser https, na porta 443, num host que resolve
 * só para IPs públicos. Sem isso, quem tem uma chave usaria o webhook para sondar a VPS (localhost, rede do Swarm,
 * metadados da nuvem em 169.254.169.254). Conferida na conexão e de novo em cada envio (o DNS pode mudar). Os avisos não
 * seguem redirecionamento (AvisosDaIntegracao). Risco aceito: DNS que muda entre a conferência e o envio (rebinding) e
 * IPv6 do host (o gethostbynamel só devolve IPv4).
 *
 * Função pura: o resolvedor de DNS é injetável (host => lista de IPs); null = gethostbynamel.
 */
final class UrlDoWebhook
{
    public const TAMANHO_MAXIMO = 500;

    /** O motivo da recusa (texto curto, pt-BR), ou null se a URL pode receber os avisos. */
    public static function recusa(string $url, ?\Closure $resolver = null): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > static::TAMANHO_MAXIMO) {
            return 'URL vazia ou longa demais';
        }

        $partes = parse_url($url);
        if (!is_array($partes) || strtolower($partes['scheme'] ?? '') !== 'https' || empty($partes['host'])) {
            return 'a URL precisa começar com https://';
        }
        if (isset($partes['user']) || isset($partes['pass'])) {
            return 'a URL não pode ter usuário e senha';
        }
        if (isset($partes['port']) && (int) $partes['port'] !== 443) {
            return 'só a porta 443';
        }

        $host = strtolower(trim($partes['host'], '[]'));
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            if (!str_contains($host, '.') || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
                return 'endereço interno';
            }
            $ips = ($resolver ?? fn (string $nome) => gethostbynamel($nome) ?: [])($host);
        }

        if (!$ips) {
            return 'o endereço não foi encontrado';
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'endereço de rede privada';
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-webhook-url.php`
Expected: `FALHAS: 0`.

- [ ] **Step 5: Commit**

```bash
git add api/app/Support/Entregas/Integracao/UrlDoWebhook.php scripts/teste-php/integracao-webhook-url.php
git commit -m "Integração: URL do webhook só https pública

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `PedidoDaIntegracao` e `CobrancaDaIntegracao`

**Files:**
- Create: `api/app/Support/Entregas/Integracao/PedidoDaIntegracao.php`
- Create: `api/app/Support/Entregas/Integracao/CobrancaDaIntegracao.php`
- Test: `scripts/teste-php/integracao-pedido.php`

- [ ] **Step 1: Escrever o teste**

`scripts/teste-php/integracao-pedido.php`:

```php
<?php

// Integração: validação e normalização do corpo do POST pedidos e o texto da cobrança na porta.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-pedido.php

require __DIR__ . '/stubs-integracao.php';

use App\Support\Entregas\Integracao\CobrancaDaIntegracao;
use App\Support\Entregas\Integracao\PedidoDaIntegracao;

function validar(array $mudancas = []): array
{
    return PedidoDaIntegracao::validar(corpoDoPedido($mudancas));
}

function temErro(array $resultado, string $trecho): bool
{
    foreach ($resultado['erros'] as $erro) {
        if (str_contains($erro, $trecho)) {
            return true;
        }
    }

    return false;
}

echo '== Corpo completo' . PHP_EOL;
$r = validar();
$d = $r['dados'];
confere($r['erros'] === [] && $d !== null, 'sem erros');
confere($d['pedido_externo_id'] === 'ord-6f1c' && $d['numero'] === '4821' && $d['cliente_nome'] === 'Maria Ficticia', 'ids e nome');
confere($d['cliente_telefone'] === '16999990000', 'telefone só com dígitos');
confere($d['endereco'] === 'Rua Ficticia, 45' && $d['complemento'] === 'Ap 12' && $d['bairro'] === 'Jardim Ficticio' && $d['cidade'] === 'Ribeirão Preto', 'endereço');
confere($d['uf'] === 'SP' && $d['cep'] === '14090-000' && $d['referencia'] === 'Perto da praça', 'UF em maiúsculas e CEP formatado');
confere($d['latitude'] === -21.17 && $d['longitude'] === -47.81, 'coordenadas');
confere($d['cobranca_forma'] === 'dinheiro' && $d['cobranca_centavos'] === 5890 && $d['troco_para_centavos'] === 10000, 'cobrança em centavos');
confere($d['observacoes'] === 'Sem cebola', 'observações');

echo '== Normalização' . PHP_EOL;
$d = validar(['numero' => 4821, 'cliente' => ['nome' => "  Maria \n  Ficticia "], 'cobranca' => ['valor' => '58,90', 'troco_para' => '100'], 'observacoes' => "Linha 1\r\nLinha 2"])['dados'];
confere($d['numero'] === '4821' && $d['cliente_nome'] === 'Maria Ficticia', 'número inteiro vira texto; espaços e quebras colapsados');
confere($d['cobranca_centavos'] === 5890 && $d['troco_para_centavos'] === 10000, 'valor com vírgula');
confere($d['observacoes'] === "Linha 1\nLinha 2", 'observações mantêm a quebra de linha');
$d = validar(['entrega' => ['latitude' => null, 'longitude' => null, 'complemento' => '', 'bairro' => null, 'uf' => '', 'cep' => null], 'cliente' => ['telefone' => '']])['dados'];
confere($d['latitude'] === null && $d['longitude'] === null && $d['complemento'] === null && $d['bairro'] === null && $d['uf'] === null && $d['cep'] === null && $d['cliente_telefone'] === null, 'opcionais vazios = null');
$d = validar(['cobranca' => ['forma' => 'PAGO', 'valor' => null, 'troco_para' => null]])['dados'];
confere($d['cobranca_forma'] === 'pago' && $d['cobranca_centavos'] === 0 && $d['troco_para_centavos'] === null, 'pago: nada a cobrar');
$d = validar(['cobranca' => ['forma' => 'dinheiro', 'troco_para' => 0]])['dados'];
confere($d['troco_para_centavos'] === null, 'troco 0 = sem troco');
$d = validar(['cobranca' => ['forma' => 'cartao', 'troco_para' => null]])['dados'];
confere($d['cobranca_forma'] === 'cartao' && $d['troco_para_centavos'] === null, 'cartão sem troco');

echo '== Erros' . PHP_EOL;
confere(temErro(validar(['pedido_externo_id' => '']), 'pedido_externo_id é obrigatório'), 'sem pedido_externo_id');
confere(temErro(validar(['pedido_externo_id' => 'ord 1/2']), 'pedido_externo_id aceita só'), 'pedido_externo_id com caractere estranho');
confere(temErro(validar(['pedido_externo_id' => str_repeat('a', 101)]), 'pedido_externo_id passa de 100'), 'pedido_externo_id longo');
confere(temErro(validar(['numero' => null]), 'numero é obrigatório'), 'sem número');
confere(temErro(validar(['cliente' => ['nome' => ['x']]]), 'cliente.nome precisa ser texto'), 'nome que não é texto');
confere(temErro(validar(['entrega' => ['endereco' => '  ']]), 'entrega.endereco é obrigatório'), 'sem endereço');
confere(temErro(validar(['entrega' => ['cidade' => null]]), 'entrega.cidade é obrigatório'), 'sem cidade');
confere(temErro(validar(['entrega' => ['uf' => 'São Paulo']]), 'entrega.uf'), 'UF inválida');
confere(temErro(validar(['entrega' => ['cep' => '1409']]), 'entrega.cep'), 'CEP curto');
confere(temErro(validar(['cliente' => ['telefone' => '123']]), 'cliente.telefone'), 'telefone curto');
confere(temErro(validar(['entrega' => ['latitude' => -21.17, 'longitude' => null]]), 'entrega.latitude e entrega.longitude'), 'só uma coordenada');
confere(temErro(validar(['entrega' => ['latitude' => 'norte']]), 'entrega.latitude e entrega.longitude'), 'coordenada que não é número');
confere(temErro(validar(['entrega' => ['latitude' => 95]]), 'entrega.latitude e entrega.longitude'), 'latitude fora da faixa');
confere(temErro(validar(['cobranca' => ['forma' => 'cheque']]), 'cobranca.forma'), 'forma desconhecida');
confere(temErro(validar(['cobranca' => ['valor' => 0]]), 'cobranca.valor'), 'valor zero fora de pago');
confere(temErro(validar(['cobranca' => ['valor' => 200000]]), 'cobranca.valor'), 'valor acima de R$ 100 mil');
confere(temErro(validar(['cobranca' => ['troco_para' => 50]]), 'cobranca.troco_para'), 'troco menor que o valor');
confere(temErro(validar(['cobranca' => ['forma' => 'pix', 'troco_para' => 100]]), 'cobranca.troco_para'), 'troco fora do dinheiro');
confere(temErro(validar(['observacoes' => str_repeat('a', 1001)]), 'observacoes passa de 1000'), 'observações longas');
confere(validar(['numero' => null])['dados'] === null, 'com erro: sem dados');
$varios = PedidoDaIntegracao::validar([]);
confere(count($varios['erros']) >= 5, 'corpo vazio: todos os obrigatórios de uma vez');

echo '== Cobrança' . PHP_EOL;
confere(CobrancaDaIntegracao::texto('dinheiro', 5890, 10000) === 'Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100', 'dinheiro com troco');
confere(CobrancaDaIntegracao::texto('dinheiro', 5890, null) === 'Cobrar R$ 58,90 · dinheiro', 'dinheiro sem troco');
confere(CobrancaDaIntegracao::texto('cartao', 5890, null) === 'Cobrar R$ 58,90 · cartão (maquininha)', 'cartão');
confere(CobrancaDaIntegracao::texto('pix', 5890, null) === 'Cobrar R$ 58,90 · Pix', 'Pix');
confere(CobrancaDaIntegracao::texto('pago', 0, null) === null && CobrancaDaIntegracao::texto('dinheiro', 0, null) === null, 'nada a cobrar: null');
confere(CobrancaDaIntegracao::forma('cartao') === 'cartão (maquininha)' && CobrancaDaIntegracao::forma('pago') === 'pago' && CobrancaDaIntegracao::forma(null) === null, 'forma em pt-BR');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-pedido.php`
Expected: erro `Class "App\Support\Entregas\Integracao\PedidoDaIntegracao" not found`.

- [ ] **Step 3: Implementar `CobrancaDaIntegracao`**

`api/app/Support/Entregas/Integracao/CobrancaDaIntegracao.php`:

```php
<?php

namespace App\Support\Entregas\Integracao;

use App\Support\Entregas\Ifood\CobrancaIfood;

/**
 * Entregas RestaurantePro: o texto da cobrança na porta de um pedido de outro sistema, no mesmo formato da faixa amarela
 * do iFood no app ("Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100"). Função pura sobre as colunas da
 * entregas_integracao_pedidos (cobranca_forma, cobranca_centavos, troco_para_centavos). Pago = null (sem faixa).
 */
final class CobrancaDaIntegracao
{
    public const FORMAS = ['dinheiro' => 'dinheiro', 'cartao' => 'cartão (maquininha)', 'pix' => 'Pix'];

    public static function texto(?string $forma, int $centavos, ?int $trocoParaCentavos): ?string
    {
        if ($forma === 'pago' || $centavos <= 0) {
            return null;
        }

        $partes = ['Cobrar ' . CobrancaIfood::reais($centavos)];
        if (isset(static::FORMAS[$forma])) {
            $partes[] = static::FORMAS[$forma];
        }
        if ($forma === 'dinheiro' && $trocoParaCentavos !== null && $trocoParaCentavos > $centavos) {
            $partes[] = 'troco p/ ' . CobrancaIfood::reais($trocoParaCentavos, true);
        }

        return implode(' · ', $partes);
    }

    /** A forma em pt-BR ("cartão (maquininha)"), "pago" ou null. */
    public static function forma(?string $forma): ?string
    {
        return $forma === 'pago' ? 'pago' : (static::FORMAS[$forma] ?? null);
    }
}
```

- [ ] **Step 4: Implementar `PedidoDaIntegracao`**

`api/app/Support/Entregas/Integracao/PedidoDaIntegracao.php`:

```php
<?php

namespace App\Support\Entregas\Integracao;

/**
 * Entregas RestaurantePro: confere e normaliza o corpo do POST v1/entregas/integracao/pedidos (função pura). Devolve
 * ['erros' => [...], 'dados' => [...]|null], com todos os erros de uma vez; as mensagens citam o campo (quem lê é o
 * desenvolvedor do sistema de origem). Contrato: spec 2026-10-12-integracao-restaurantepro-design.md, Parte 1.
 *
 * Normaliza: textos sem espaços sobrando (as observações mantêm a quebra de linha), telefone só com dígitos, UF em
 * maiúsculas, CEP "99999-999", valores em centavos (aceita "58,90"), troco 0 = sem troco, forma em minúsculas.
 */
final class PedidoDaIntegracao
{
    public const FORMAS = ['dinheiro', 'cartao', 'pix', 'pago'];

    /** R$ 100 mil, em centavos: acima disso é erro de digitação ou de unidade (centavos mandados como reais). */
    public const VALOR_MAXIMO_CENTAVOS = 10000000;

    /** @return array{erros: string[], dados: ?array} */
    public static function validar(array $corpo): array
    {
        $erros = [];

        $externo = static::texto($corpo, 'pedido_externo_id', 100, true, $erros);
        if ($externo !== null && !preg_match('/^[A-Za-z0-9._:-]+$/', $externo)) {
            $erros[] = 'pedido_externo_id aceita só letras, números, ponto, hífen, sublinhado e dois-pontos.';
        }
        $numero      = static::texto($corpo, 'numero', 20, true, $erros);
        $nome        = static::texto($corpo, 'cliente.nome', 120, true, $erros);
        $telefone    = static::telefone($corpo, $erros);
        $endereco    = static::texto($corpo, 'entrega.endereco', 190, true, $erros);
        $complemento = static::texto($corpo, 'entrega.complemento', 190, false, $erros);
        $bairro      = static::texto($corpo, 'entrega.bairro', 120, false, $erros);
        $cidade      = static::texto($corpo, 'entrega.cidade', 120, true, $erros);
        $uf          = static::uf($corpo, $erros);
        $cep         = static::cep($corpo, $erros);
        $referencia  = static::texto($corpo, 'entrega.referencia', 190, false, $erros);
        [$latitude, $longitude]     = static::coordenadas($corpo, $erros);
        $observacoes                = static::texto($corpo, 'observacoes', 1000, false, $erros, false);
        [$forma, $centavos, $troco] = static::cobranca($corpo, $erros);

        if ($erros) {
            return ['erros' => $erros, 'dados' => null];
        }

        return ['erros' => [], 'dados' => [
            'pedido_externo_id'   => $externo,
            'numero'              => $numero,
            'cliente_nome'        => $nome,
            'cliente_telefone'    => $telefone,
            'endereco'            => $endereco,
            'complemento'         => $complemento,
            'bairro'              => $bairro,
            'cidade'              => $cidade,
            'uf'                  => $uf,
            'cep'                 => $cep,
            'referencia'          => $referencia,
            'latitude'            => $latitude,
            'longitude'           => $longitude,
            'cobranca_forma'      => $forma,
            'cobranca_centavos'   => $centavos,
            'troco_para_centavos' => $troco,
            'observacoes'         => $observacoes,
        ]];
    }

    /** O valor em "a.b" do corpo, ou null (sem o data_get do Laravel: a classe fica pura). */
    public static function campo(array $corpo, string $caminho)
    {
        $valor = $corpo;
        foreach (explode('.', $caminho) as $parte) {
            if (!is_array($valor) || !array_key_exists($parte, $valor)) {
                return null;
            }
            $valor = $valor[$parte];
        }

        return $valor;
    }

    protected static function texto(array $corpo, string $caminho, int $maximo, bool $obrigatorio, array &$erros, bool $umaLinha = true): ?string
    {
        $valor = static::campo($corpo, $caminho);
        if (is_int($valor) || is_float($valor)) {
            $valor = (string) $valor;
        }
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            if ($obrigatorio) {
                $erros[] = "{$caminho} é obrigatório.";
            }

            return null;
        }
        if (!is_string($valor)) {
            $erros[] = "{$caminho} precisa ser texto.";

            return null;
        }

        $limpo = $umaLinha
            ? preg_replace('/\s+/u', ' ', $valor)
            : (mb_check_encoding($valor, 'UTF-8') ? str_replace("\r\n", "\n", $valor) : null);
        if ($limpo === null) {
            $erros[] = "{$caminho} precisa ser texto em UTF-8.";

            return null;
        }
        $limpo = trim($limpo);
        if (mb_strlen($limpo) > $maximo) {
            $erros[] = "{$caminho} passa de {$maximo} caracteres.";

            return null;
        }

        return $limpo;
    }

    protected static function telefone(array $corpo, array &$erros): ?string
    {
        $valor = static::campo($corpo, 'cliente.telefone');
        if ($valor === null || $valor === '') {
            return null;
        }
        if (!is_string($valor) && !is_int($valor)) {
            $erros[] = 'cliente.telefone precisa ser texto.';

            return null;
        }
        $digitos = preg_replace('/\D+/', '', (string) $valor);
        if ($digitos === '') {
            return null;
        }
        if (strlen($digitos) < 8 || strlen($digitos) > 15) {
            $erros[] = 'cliente.telefone precisa ter de 8 a 15 dígitos.';

            return null;
        }

        return $digitos;
    }

    protected static function uf(array $corpo, array &$erros): ?string
    {
        $valor = static::campo($corpo, 'entrega.uf');
        if ($valor === null || $valor === '') {
            return null;
        }
        if (!is_string($valor) || !preg_match('/^[A-Za-z]{2}$/', trim($valor))) {
            $erros[] = 'entrega.uf precisa ter 2 letras (ex.: SP).';

            return null;
        }

        return strtoupper(trim($valor));
    }

    protected static function cep(array $corpo, array &$erros): ?string
    {
        $valor = static::campo($corpo, 'entrega.cep');
        if ($valor === null || $valor === '') {
            return null;
        }
        $digitos = is_string($valor) || is_int($valor) ? preg_replace('/\D+/', '', (string) $valor) : '';
        if (strlen($digitos) !== 8) {
            $erros[] = 'entrega.cep precisa ter 8 dígitos.';

            return null;
        }

        return substr($digitos, 0, 5) . '-' . substr($digitos, 5);
    }

    /** @return array{0: ?float, 1: ?float} */
    protected static function coordenadas(array $corpo, array &$erros): array
    {
        $latitude  = static::campo($corpo, 'entrega.latitude');
        $longitude = static::campo($corpo, 'entrega.longitude');
        if (($latitude === null || $latitude === '') && ($longitude === null || $longitude === '')) {
            return [null, null];
        }
        if (!is_numeric($latitude) || !is_numeric($longitude) || abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
            $erros[] = 'entrega.latitude e entrega.longitude vão juntas, em graus decimais (ex.: -21.1931 e -47.8122).';

            return [null, null];
        }

        return [(float) $latitude, (float) $longitude];
    }

    /** @return array{0: ?string, 1: int, 2: ?int} forma, centavos a cobrar e troco para (centavos) */
    protected static function cobranca(array $corpo, array &$erros): array
    {
        $forma = static::campo($corpo, 'cobranca.forma');
        $forma = is_string($forma) ? strtolower(trim($forma)) : null;
        if (!in_array($forma, static::FORMAS, true)) {
            $erros[] = 'cobranca.forma precisa ser dinheiro, cartao, pix ou pago.';

            return [null, 0, null];
        }
        if ($forma === 'pago') {
            return ['pago', 0, null];
        }

        $centavos = static::centavos(static::campo($corpo, 'cobranca.valor'));
        if ($centavos === null || $centavos <= 0 || $centavos > static::VALOR_MAXIMO_CENTAVOS) {
            $erros[] = 'cobranca.valor precisa ser o valor a cobrar na porta, em reais (ex.: 58.90), maior que zero e até 100000.';

            return [$forma, 0, null];
        }

        $bruto = static::campo($corpo, 'cobranca.troco_para');
        $troco = static::centavos($bruto);
        if ($bruto === null || $bruto === '' || $troco === 0) {
            return [$forma, $centavos, null];
        }
        if ($forma !== 'dinheiro' || $troco === null || $troco < $centavos || $troco > static::VALOR_MAXIMO_CENTAVOS) {
            $erros[] = 'cobranca.troco_para só vale com dinheiro e não pode ser menor que o valor.';

            return [$forma, $centavos, null];
        }

        return [$forma, $centavos, $troco];
    }

    protected static function centavos($valor): ?int
    {
        if (is_string($valor)) {
            $valor = str_replace(',', '.', trim($valor));
        }

        return is_numeric($valor) ? (int) round(((float) $valor) * 100) : null;
    }
}
```

- [ ] **Step 5: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-pedido.php`
Expected: `FALHAS: 0`.

- [ ] **Step 6: Commit**

```bash
git add api/app/Support/Entregas/Integracao/PedidoDaIntegracao.php api/app/Support/Entregas/Integracao/CobrancaDaIntegracao.php scripts/teste-php/integracao-pedido.php
git commit -m "Integração: validação do pedido e texto da cobrança

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Criação do pedido (`CriadorDoPedidoDaIntegracao` e apoio)

**Files:**
- Create: `api/app/Support/Entregas/Integracao/ErroDaIntegracao.php`
- Create: `api/app/Support/Entregas/Integracao/PedidosDaIntegracao.php`
- Create: `api/app/Support/Entregas/Integracao/GeocodificadorDaIntegracao.php`
- Create: `api/app/Support/Entregas/Integracao/CriadorDoPedidoDaIntegracao.php`
- Test: `scripts/teste-php/integracao-criador.php`

- [ ] **Step 1: Escrever o teste**

`scripts/teste-php/integracao-criador.php`:

```php
<?php

// Integração: criação e despacho do pedido (CriadorDoPedidoDaIntegracao), a geocodificação de reserva e a leitura da
// linha pela loja (PedidosDaIntegracao::daLoja).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-criador.php

require __DIR__ . '/stubs-integracao.php';

use App\Support\Entregas\Integracao\ChavesDeIntegracao;
use App\Support\Entregas\Integracao\CriadorDoPedidoDaIntegracao;
use App\Support\Entregas\Integracao\ErroDaIntegracao;
use App\Support\Entregas\Integracao\GeocodificadorDaIntegracao;
use App\Support\Entregas\Integracao\PedidoDaIntegracao;
use App\Support\Entregas\Integracao\PedidosDaIntegracao;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Teste\Banco;
use Teste\Http;
use Teste\Trava;

/** A loja A (a mesma linha a cada chamada: inserir de novo repetiria a chave_hash, que é única). */
function lojaA(): object
{
    return ChavesDeIntegracao::ativa('empresa-1', 'vendor-a') ?? lojaIntegradaA();
}

function criar(array $mudancas = []): array
{
    $dados = PedidoDaIntegracao::validar(corpoDoPedido($mudancas))['dados'];

    return (new CriadorDoPedidoDaIntegracao(new GeocodificadorDaIntegracao()))->criar(lojaA(), $dados);
}

echo '== Pedido com coordenadas: cria e despacha' . PHP_EOL;
reiniciarIntegracao();
[$linha, $novo] = criar();
$pedido         = Order::$criados[0] ?? null;
confere($novo === true && count(Order::$criados) === 1, 'um pedido novo');
confere($pedido->customer_uuid === 'vendor-a' && $pedido->customer_type === 'fleet-ops:vendor' && $pedido->company_uuid === 'empresa-1', 'cliente = a loja');
confere($pedido->empresaNaSessao === 'empresa-1' && $pedido->type === 'transport' && $pedido->order_config_uuid === 'config-transport', 'empresa na sessão e tipo transport');
confere($pedido->internal_id === '4821' && $pedido->notes === "RestaurantePro #4821\nSem cebola", 'número no internal_id e nas notas, com as observações');
$destino = Place::$criados[0];
confere($destino->location->getLat() === -21.17 && $destino->location->getLng() === -47.81 && !isset($destino->owner_uuid), 'entrega = Place novo nas coordenadas, sem dono');
confere($destino->name === 'MARIA FICTICIA' && $destino->street1 === 'RUA FICTICIA, 45' && $destino->street2 === 'AP 12' && $destino->city === 'RIBEIRÃO PRETO' && $destino->province === 'SP' && $destino->postal_code === '14090-000', 'endereço em maiúsculas (com acento)');
confere(!isset($destino->phone), 'o telefone do cliente não vai para o Place');
$payload = Payload::$salvos[0];
confere($payload->pickup->uuid === 'place-loja' && $payload->dropoff === $destino && $pedido->payload_uuid === $payload->uuid, 'coleta = Local da loja');
confere($linha->order_uuid === $pedido->uuid && $linha->pedido_externo_id === 'ord-6f1c' && $linha->vendor_uuid === 'vendor-a' && $linha->sistema === 'RestaurantePro', 'linha ligada ao pedido');
confere($linha->cobranca_forma === 'dinheiro' && $linha->cobranca_centavos === 5890 && $linha->troco_para_centavos === 10000 && $linha->cliente_telefone === '16999990000', 'cobrança e telefone na linha');
confere($linha->complemento === 'Ap 12' && $linha->referencia === 'Perto da praça' && $linha->observacoes === 'Sem cebola' && $linha->sem_localizacao === false, 'complemento, referência e observações');
confere($pedido->adhocAoCriar === true && $pedido->chamadas === ['saveQuietly', 'firstDispatchWithActivity'] && $pedido->travadoNoDespacho === [true], 'despachado como pedido aberto, com a trava');
confere($linha->despachado_em === '2026-10-05 15:00:00' && Trava::$ocupadas === [], 'despachado_em marcado; trava solta');
confere(Http::$chamadas === [], 'com coordenadas, não consulta o Google');
confere(logou('[entregas] integração: pedido criado', 'info') && logsSem(['Maria Ficticia', 'MARIA FICTICIA', 'Rua Ficticia', '99999', 'Sem cebola']), 'log sem nome, endereço, telefone nem observações');

echo '== Repetido: devolve o mesmo, sem criar outro' . PHP_EOL;
[$repetida, $novo] = criar(['numero' => '9999']);
confere($novo === false && $repetida->id === $linha->id && count(Order::$criados) === 1, 'mesmo pedido_externo_id: o mesmo pedido');
confere(logou('[entregas] integração: pedido repetido', 'info'), 'log do repetido');

echo '== Sem coordenadas: geocodifica' . PHP_EOL;
reiniciarIntegracao();
Http::responder(200, ['status' => 'OK', 'results' => [['geometry' => ['location' => ['lat' => -21.19, 'lng' => -47.8]]]]]);
[$linha] = criar(['entrega' => ['latitude' => null, 'longitude' => null]]);
$chamada = Http::$chamadas[0] ?? null;
confere($chamada && $chamada['metodo'] === 'GET' && $chamada['url'] === GeocodificadorDaIntegracao::URL && $chamada['timeout'] === 5, 'uma consulta ao Geocoding, com timeout de 5 s');
confere($chamada['dados']['address'] === 'Rua Ficticia, 45, Jardim Ficticio, Ribeirão Preto, SP, 14090-000, Brasil' && $chamada['dados']['key'] === 'chave-google' && $chamada['dados']['region'] === 'br', 'endereço completo, a chave e a região');
confere(Place::$criados[0]->location->getLat() === -21.19 && $linha->sem_localizacao === false && Order::$criados[0]->dispatched === true, 'usa o ponto do Google e despacha');
confere(logou('[entregas] integração: endereço geocodificado', 'info') && logsSem(['Rua Ficticia', 'chave-google']), 'log sem endereço nem chave');

echo '== Sem localização: nasce sem despacho' . PHP_EOL;
$casos = [
    'Google sem resultado'   => [fn () => Http::responder(200, ['status' => 'ZERO_RESULTS', 'results' => []]), ['entrega' => ['latitude' => null, 'longitude' => null]]],
    'Google fora do ar'      => [fn () => Http::falharConexao(), ['entrega' => ['latitude' => null, 'longitude' => null]]],
    'longe da loja (50 km+)' => [fn () => null, ['entrega' => ['latitude' => -23.55, 'longitude' => -46.63]]],
    'coordenada 0,0'         => [fn () => null, ['entrega' => ['latitude' => 0, 'longitude' => 0]]],
];
foreach ($casos as $caso => [$preparar, $mudancas]) {
    reiniciarIntegracao();
    $preparar();
    [$linha] = criar($mudancas);
    $pedido  = Order::$criados[0];
    confere($linha->sem_localizacao === true && $pedido->adhocAoCriar === false && $pedido->chamadas === [] && $linha->despachado_em === null, "{$caso}: sem despacho, adhoc falso");
    confere($pedido->notes === "RestaurantePro #4821 [SEM LOCALIZAÇÃO]\nSem cebola", "{$caso}: marca nas notas");
    confere(abs(Place::$criados[0]->location->getLat() - (-21.1775 + 0.009)) < 0.000001 && Place::$criados[0]->location->getLng() === -47.8103, "{$caso}: entrega ~1 km ao norte da loja");
    confere(logou('pedido sem localização', 'warning'), "{$caso}: aviso no log");
}
reiniciarIntegracao();
unset(\Teste\Config::$valores['services.google_maps.api_key']);
[$linha] = criar(['entrega' => ['latitude' => null, 'longitude' => null]]);
confere(Http::$chamadas === [] && $linha->sem_localizacao === true, 'sem a chave do Google: nem consulta, sem localização');

echo '== Loja sem local de coleta válido' . PHP_EOL;
reiniciarIntegracao();
Vendor::$todos[0]->place_uuid = null;
$erro = excecao(fn () => criar());
confere($erro instanceof ErroDaIntegracao && $erro->status === 409 && Order::$criados === [] && Banco::linhas('entregas_integracao_pedidos') === [], '409, nada criado');
confere(logou('loja sem local de coleta válido', 'error'), 'log de erro');

echo '== Banco falhou na linha: desfaz tudo' . PHP_EOL;
reiniciarIntegracao();
Banco::$falharComo['entregas_integracao_pedidos'] = ['HY000', 2006];
$erro = excecao(fn () => criar());
confere($erro instanceof \Illuminate\Database\QueryException && Order::$criados === [] && Place::$criados === [], 'erro sobe e o Order e o Place são desfeitos');

echo '== Despacho com a trava ocupada: fica para o acompanhamento' . PHP_EOL;
reiniciarIntegracao();
Trava::$ocupadas['entregas:pedido:order-uuid-1'] = true;
[$linha] = criar();
confere($linha->despachado_em === null && Order::$criados[0]->chamadas === [] && logou('trava do pedido ocupada', 'warning'), 'não despacha agora; despachado_em nulo');

echo '== PedidosDaIntegracao::daLoja' . PHP_EOL;
reiniciarIntegracao();
[$linha] = criar();
$loja    = lojaA();
confere(PedidosDaIntegracao::daLoja($loja, 'ord-6f1c')?->id === $linha->id, 'pelo pedido_externo_id');
confere(PedidosDaIntegracao::daLoja($loja, 'order_1')?->id === $linha->id, 'pelo public_id do Order');
$outra = clone $loja;
$outra->vendor_uuid = 'vendor-b';
confere(PedidosDaIntegracao::daLoja($outra, 'ord-6f1c') === null && PedidosDaIntegracao::daLoja($outra, 'order_1') === null, 'a outra loja não acha');
confere(PedidosDaIntegracao::daLoja($loja, '') === null && PedidosDaIntegracao::daLoja($loja, 'nao-existe') === null, 'vazio ou desconhecido: null');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-criador.php`
Expected: erro `Class "App\Support\Entregas\Integracao\CriadorDoPedidoDaIntegracao" not found`.

- [ ] **Step 3: `ErroDaIntegracao`**

`api/app/Support/Entregas/Integracao/ErroDaIntegracao.php`:

```php
<?php

namespace App\Support\Entregas\Integracao;

/** Entregas RestaurantePro: erro da integração com o status HTTP da resposta (o IntegracaoController responde com ele). */
final class ErroDaIntegracao extends \RuntimeException
{
    public function __construct(public readonly int $status, string $mensagem)
    {
        parent::__construct($mensagem);
    }
}
```

- [ ] **Step 4: `PedidosDaIntegracao`**

`api/app/Support/Entregas/Integracao/PedidosDaIntegracao.php`:

```php
<?php

namespace App\Support\Entregas\Integracao;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: leitura e gravação da entregas_integracao_pedidos num lugar só. Pedido da integração = Order
 * com linha aqui. A loja de um pedido é o vendor_uuid da linha (sobrevive à troca da chave: a chave nova acha os
 * pedidos feitos com a anterior).
 */
final class PedidosDaIntegracao
{
    public static function doPedido(?string $orderUuid): ?object
    {
        if ($orderUuid === null || $orderUuid === '') {
            return null;
        }

        return DB::table(Integracao::PEDIDOS)->where('order_uuid', $orderUuid)->first();
    }

    public static function ehDaIntegracao(?string $orderUuid): bool
    {
        return $orderUuid !== null && $orderUuid !== '' && DB::table(Integracao::PEDIDOS)->where('order_uuid', $orderUuid)->exists();
    }

    /** O pedido da loja pelo id da origem (pedido_externo_id) ou pelo public_id do Order (order_…); null se não é dela. */
    public static function daLoja(object $loja, string $id): ?object
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }

        $porExterno = DB::table(Integracao::PEDIDOS)
            ->where('company_uuid', $loja->company_uuid)
            ->where('vendor_uuid', $loja->vendor_uuid)
            ->where('pedido_externo_id', $id)
            ->first();
        if ($porExterno || !str_starts_with($id, 'order_')) {
            return $porExterno;
        }

        $pedido = Order::where('company_uuid', $loja->company_uuid)->where('public_id', $id)->first();

        return $pedido
            ? DB::table(Integracao::PEDIDOS)->where('order_uuid', $pedido->uuid)->where('vendor_uuid', $loja->vendor_uuid)->first()
            : null;
    }

    /** Grava os valores na linha (com updated_at) e copia para o objeto de quem chamou. */
    public static function atualizar(object $linha, array $valores): void
    {
        $valores['updated_at'] = now()->toDateTimeString();
        DB::table(Integracao::PEDIDOS)->where('id', $linha->id)->update($valores);
        foreach ($valores as $coluna => $valor) {
            $linha->$coluna = $valor;
        }
    }
}
```

- [ ] **Step 5: `GeocodificadorDaIntegracao`**

`api/app/Support/Entregas/Integracao/GeocodificadorDaIntegracao.php`:

```php
<?php

namespace App\Support\Entregas\Integracao;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: reserva para o pedido que chega sem coordenadas. Uma consulta ao Google Geocoding (paga, na
 * chave de Admin → Serviços, `services.google_maps.api_key`, a mesma da busca de endereço), sem o Place::createFromMixed
 * do Fleet-Ops (que geocodifica sempre). O RestaurantePro manda as coordenadas, então isso é para terceiros e para a
 * falha de geocodificação dele. Sem chave, sem resultado ou Google fora do ar: null (o pedido nasce sem localização e
 * a central atribui). O log não leva o endereço nem a chave.
 */
final class GeocodificadorDaIntegracao
{
    public const URL = 'https://maps.googleapis.com/maps/api/geocode/json';

    /** @return array{0: float, 1: float}|null [latitude, longitude] */
    public function coordenadas(array $dados): ?array
    {
        $chave = (string) config('services.google_maps.api_key');
        if ($chave === '') {
            return null;
        }

        $partes   = [$dados['endereco'] ?? null, $dados['bairro'] ?? null, $dados['cidade'] ?? null, $dados['uf'] ?? null, $dados['cep'] ?? null, 'Brasil'];
        $endereco = implode(', ', array_filter($partes, fn ($parte) => is_string($parte) && $parte !== ''));

        try {
            $resposta = Http::timeout(5)->get(static::URL, ['address' => $endereco, 'region' => 'br', 'language' => 'pt-BR', 'key' => $chave]);
        } catch (\Throwable $e) {
            Log::warning('[entregas] integração: geocodificação falhou', ['erro' => get_class($e)]);

            return null;
        }

        $resultados = $resposta->successful() && $resposta->json('status') === 'OK' ? $resposta->json('results') : null;
        $local      = is_array($resultados) ? ($resultados[0]['geometry']['location'] ?? null) : null;
        if (!is_array($local) || !is_numeric($local['lat'] ?? null) || !is_numeric($local['lng'] ?? null)) {
            Log::warning('[entregas] integração: endereço não geocodificado', ['status' => $resposta->status(), 'google' => (string) $resposta->json('status')]);

            return null;
        }

        Log::info('[entregas] integração: endereço geocodificado');

        return [(float) $local['lat'], (float) $local['lng']];
    }
}
```

- [ ] **Step 6: `CriadorDoPedidoDaIntegracao`**

`api/app/Support/Entregas/Integracao/CriadorDoPedidoDaIntegracao.php`:

```php
<?php

namespace App\Support\Entregas\Integracao;

use App\Support\Entregas\Coordenada;
use App\Support\Entregas\Ifood\PedidoDoIfood;
use App\Support\Entregas\StatusDoPedido;
use App\Support\Entregas\TravaDoPedido;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: cria no Fleetbase o pedido que veio de outro sistema e o despacha aos motoboys, no molde do
 * CriadorDoPedidoIfood:
 * - cliente = a Loja (Vendor) da chave; coleta = o Local da loja (dono = o Vendor, coordenadas válidas);
 * - entrega = Place novo com as coordenadas recebidas (ou do Google, como reserva), sem dono e sem o telefone do cliente
 *   (o telefone fica na entregas_integracao_pedidos: o app só o mostra ao motoboy do pedido em andamento);
 * - Order tipo transport, internal_id = número da origem, notas "RestaurantePro #4821" + as observações;
 * - a linha da entregas_integracao_pedidos na mesma transação: (vendor_uuid, pedido_externo_id) único impede um segundo
 *   pedido para o mesmo pedido da origem (o reenvio devolve o mesmo);
 * - ponto ausente, inválido ou a mais de 50 km da coleta: nasce sem despacho, adhoc falso, "[SEM LOCALIZAÇÃO]" nas notas
 *   e a entrega ~1 km ao norte da loja (a regra do iFood); a central atribui;
 * - despacho como o do iFood e do portal: adhoc + firstDispatchWithActivity, com a TravaDoPedido e o pedido relido, o
 *   que dispara a distribuição. Falhou (trava ocupada, erro): o entregas:integracao-acompanhar refaz em 1 min.
 */
class CriadorDoPedidoDaIntegracao
{
    public const DISTANCIA_MAXIMA_METROS = 50000;

    /** ~1 km ao norte da coleta, em graus de latitude (a entrega do pedido sem localização). */
    public const DESLOCAMENTO_SEM_LOCALIZACAO = 0.009;

    public function __construct(protected GeocodificadorDaIntegracao $geocodificador) {}

    /**
     * @return array{0: object, 1: bool} a linha da entregas_integracao_pedidos e se o pedido nasceu agora (false = repetido)
     *
     * @throws ErroDaIntegracao 409 se a loja não tem Local de coleta válido
     */
    public function criar(object $loja, array $dados): array
    {
        if ($repetido = $this->existente($loja, $dados['pedido_externo_id'])) {
            Log::info('[entregas] integração: pedido repetido', ['vendor' => $loja->vendor_uuid, 'pedido_externo' => $dados['pedido_externo_id']]);

            return [$repetido, false];
        }

        $vendor = Vendor::where('uuid', $loja->vendor_uuid)->where('company_uuid', $loja->company_uuid)->first();
        $coleta = $vendor && $vendor->place_uuid ? Place::where('uuid', $vendor->place_uuid)->first() : null;
        if (!$vendor || !$coleta || !$this->coletaValida($coleta, $vendor, (string) $loja->company_uuid)) {
            Log::error('[entregas] integração: loja sem local de coleta válido; pedido não criado', ['vendor' => $loja->vendor_uuid]);
            throw new ErroDaIntegracao(409, 'A loja não tem local de coleta válido no Entregas. Fale com a central.');
        }

        [$latitude, $longitude, $semLocalizacao] = $this->destino($dados, $coleta);
        $sistema                                 = Integracao::nomeDoSistema($loja->sistema ?? null);

        // o TrackingNumberObserver e o OrderConfig::default() leem a empresa da sessão
        session(['company' => $loja->company_uuid]);

        try {
            $pedido = DB::transaction(fn () => $this->gravar($loja, $vendor, $coleta, $dados, $sistema, $latitude, $longitude, $semLocalizacao));
        } catch (QueryException $e) {
            // o mesmo pedido chegou duas vezes no mesmo instante: a chave única segurou o segundo, que devolve o primeiro
            if ((string) $e->getCode() === '23000' && ($repetido = $this->existente($loja, $dados['pedido_externo_id']))) {
                return [$repetido, false];
            }
            throw $e;
        }

        Log::info('[entregas] integração: pedido criado', ['pedido' => $pedido->public_id, 'sistema' => $sistema, 'sem_localizacao' => $semLocalizacao]);

        if ($semLocalizacao) {
            Log::warning('[entregas] integração: pedido sem localização; a central atribui', ['pedido' => $pedido->public_id]);
        } else {
            $this->despachar($pedido);
        }

        return [PedidosDaIntegracao::doPedido((string) $pedido->uuid), true];
    }

    /**
     * Pedido aberto aos motoboys (a distribuição), com a trava do pedido e o pedido relido. Já despachado: só marca o
     * despachado_em. Com motoboy atribuído pela central: despacha só para ele (adhoc falso). Apagado, encerrado ou aceito:
     * não despacha. Devolve se despachou (ou já estava).
     */
    public function despachar(Order $pedido): bool
    {
        try {
            return TravaDoPedido::executar((string) $pedido->uuid, fn () => $this->despacharComATrava($pedido));
        } catch (LockTimeoutException $e) {
            Log::warning('[entregas] integração: trava do pedido ocupada; despacho fica para o acompanhamento', ['pedido' => $pedido->public_id]);

            return false;
        } catch (\Throwable $e) {
            Log::error('[entregas] integração: falha ao despachar o pedido', ['pedido' => $pedido->public_id, 'erro' => get_class($e)]);

            return false;
        }
    }

    protected function despacharComATrava(Order $pedido): bool
    {
        session(['company' => $pedido->company_uuid]);

        $atual = $pedido->fresh();
        if (!$atual || $atual->deleted_at || in_array($atual->status, StatusDoPedido::ENCERRADOS, true) || $atual->started) {
            return false;
        }

        if (!$atual->dispatched) {
            // sem eventos: o despacho logo abaixo grava de novo e emite os eventos (OrderDispatched → distribuição)
            $atual->adhoc = !$atual->driver_assigned_uuid;
            $atual->saveQuietly();
            $atual->firstDispatchWithActivity();
        }

        $agora = now()->toDateTimeString();
        DB::table(Integracao::PEDIDOS)->where('order_uuid', $pedido->uuid)->update(['despachado_em' => $agora, 'updated_at' => $agora]);

        return true;
    }

    public static function notas(string $sistema, array $dados, bool $semLocalizacao): string
    {
        $notas = "{$sistema} #{$dados['numero']}" . ($semLocalizacao ? ' [SEM LOCALIZAÇÃO]' : '');

        return $dados['observacoes'] ? $notas . "\n" . $dados['observacoes'] : $notas;
    }

    protected function gravar(object $loja, Vendor $vendor, Place $coleta, array $dados, string $sistema, float $latitude, float $longitude, bool $semLocalizacao): Order
    {
        // mb_strtoupper: o PlaceObserver só faz strtoupper (ASCII) e gravaria "RIBEIRãO"
        $maiusculo = fn (?string $texto) => $texto === null ? null : mb_strtoupper($texto, 'UTF-8');
        $destino   = Place::create([
            'company_uuid' => $loja->company_uuid,
            'name'         => $maiusculo($dados['cliente_nome']),
            'street1'      => $maiusculo($dados['endereco']),
            'street2'      => $maiusculo($dados['complemento']),
            'neighborhood' => $maiusculo($dados['bairro']),
            'city'         => $maiusculo($dados['cidade']),
            'province'     => $dados['uf'],
            'postal_code'  => $dados['cep'],
            'country'      => 'BR',
            'location'     => new Point($latitude, $longitude),
        ]);

        $payload               = new Payload();
        $payload->company_uuid = $loja->company_uuid;
        $payload->setPickup($coleta, [
            'callback' => function ($pickup, $payload) {
                $payload->setCurrentWaypoint($pickup);
            },
        ]);
        $payload->setDropoff($destino);
        $payload->save();

        $config = OrderConfig::default() ?? OrderConfig::where('company_uuid', $loja->company_uuid)->first();
        if (!$config) {
            throw new \RuntimeException('a empresa não tem tipo de pedido (OrderConfig)');
        }

        $pedido = Order::create([
            'company_uuid'      => $loja->company_uuid,
            'customer_uuid'     => $vendor->uuid,
            'customer_type'     => Utils::getMutationType($vendor),
            'payload_uuid'      => $payload->uuid,
            'order_config_uuid' => $config->uuid,
            'type'              => $config->key,
            'status'            => 'created',
            'adhoc'             => !$semLocalizacao,
            'internal_id'       => $dados['numero'],
            'notes'             => static::notas($sistema, $dados, $semLocalizacao),
        ]);

        $agora = now()->toDateTimeString();
        DB::table(Integracao::PEDIDOS)->insert([
            'company_uuid'        => $loja->company_uuid,
            'vendor_uuid'         => $vendor->uuid,
            'order_uuid'          => $pedido->uuid,
            'pedido_externo_id'   => $dados['pedido_externo_id'],
            'sistema'             => $sistema,
            'numero'              => $dados['numero'],
            'cliente_nome'        => $dados['cliente_nome'],
            'cliente_telefone'    => $dados['cliente_telefone'],
            'complemento'         => $dados['complemento'],
            'referencia'          => $dados['referencia'],
            'observacoes'         => $dados['observacoes'],
            'cobranca_forma'      => $dados['cobranca_forma'],
            'cobranca_centavos'   => $dados['cobranca_centavos'],
            'troco_para_centavos' => $dados['troco_para_centavos'],
            'sem_localizacao'     => $semLocalizacao,
            'created_at'          => $agora,
            'updated_at'          => $agora,
        ]);

        return $pedido;
    }

    /** @return array{0: float, 1: float, 2: bool} latitude, longitude e se ficou sem localização */
    protected function destino(array $dados, Place $coleta): array
    {
        $latitudeDaColeta  = (float) $coleta->location->getLat();
        $longitudeDaColeta = (float) $coleta->location->getLng();

        $latitude  = $dados['latitude'];
        $longitude = $dados['longitude'];
        if ($latitude === null || $longitude === null) {
            [$latitude, $longitude] = $this->geocodificador->coordenadas($dados) ?? [null, null];
        }

        $valida = $latitude !== null && $longitude !== null
            && Coordenada::valida($latitude, $longitude)
            && PedidoDoIfood::metrosEntre($latitudeDaColeta, $longitudeDaColeta, (float) $latitude, (float) $longitude) <= static::DISTANCIA_MAXIMA_METROS;

        return $valida
            ? [(float) $latitude, (float) $longitude, false]
            : [$latitudeDaColeta + static::DESLOCAMENTO_SEM_LOCALIZACAO, $longitudeDaColeta, true];
    }

    protected function existente(object $loja, string $pedidoExternoId): ?object
    {
        return DB::table(Integracao::PEDIDOS)
            ->where('company_uuid', $loja->company_uuid)
            ->where('vendor_uuid', $loja->vendor_uuid)
            ->where('pedido_externo_id', $pedidoExternoId)
            ->first();
    }

    /** Mesmo critério do portal e do iFood para a coleta: da empresa, com dono = o Vendor e com coordenadas. */
    protected function coletaValida(Place $coleta, Vendor $vendor, string $companyUuid): bool
    {
        return $companyUuid !== ''
            && $coleta->company_uuid === $companyUuid
            && $coleta->owner_uuid === $vendor->uuid
            && $coleta->owner_type === Utils::getMutationType($vendor)
            && $coleta->location instanceof Point
            && Coordenada::valida($coleta->location->getLat(), $coleta->location->getLng());
    }
}
```

- [ ] **Step 7: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-criador.php`
Expected: `FALHAS: 0`.

Se o caso "longe da loja" falhar porque `Coordenada::valida` recusa a coordenada antes da distância, confira a assinatura em `api/app/Support/Entregas/Coordenada.php` (recebe latitude e longitude numéricas e recusa só o 0,0 e fora da faixa); a coordenada de São Paulo deve passar por ela e cair pela distância.

- [ ] **Step 8: Commit**

```bash
git add api/app/Support/Entregas/Integracao/ErroDaIntegracao.php api/app/Support/Entregas/Integracao/PedidosDaIntegracao.php api/app/Support/Entregas/Integracao/GeocodificadorDaIntegracao.php api/app/Support/Entregas/Integracao/CriadorDoPedidoDaIntegracao.php scripts/teste-php/integracao-criador.php
git commit -m "Integração: cria e despacha o pedido de outro sistema

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: `CancelamentoPelaOrigem`

**Files:**
- Create: `api/app/Support/Entregas/Integracao/CancelamentoPelaOrigem.php`
- Test: `scripts/teste-php/integracao-cancelamento.php`

- [ ] **Step 1: Escrever o teste**

`scripts/teste-php/integracao-cancelamento.php`:

```php
<?php

// Integração: cancelamento pedido pela origem (CancelamentoPelaOrigem) e o texto do push ao motoboy.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-cancelamento.php

require __DIR__ . '/stubs-integracao.php';

use App\Support\Entregas\Integracao\CancelamentoPelaOrigem;
use Fleetbase\FleetOps\Models\Order;
use Teste\Relogio;
use Teste\Trava;

function cancelar(): string
{
    return (new CancelamentoPelaOrigem())->cancelar(linhaDaIntegracao());
}

echo '== Sem aceite: cancela e sai da distribuição' . PHP_EOL;
reiniciarIntegracao();
$pedido = pedidoDaIntegracao(['status' => 'dispatched', 'started' => false, 'adhoc' => true, 'driver_assigned_uuid' => null]);
confere(cancelar() === CancelamentoPelaOrigem::CANCELADO, 'resultado: cancelado');
confere($pedido->chamadas === ['saveQuietly', 'cancel'] && $pedido->status === 'canceled' && $pedido->dispatched === false && $pedido->adhoc === false, 'sai dos abertos (saveQuietly) e cancel()');
confere($pedido->travadoNoSalvar === [true] && $pedido->travadoNoCancelar[0][0] === true && Trava::$ocupadas === [], 'com a trava do pedido, solta no fim');
confere(linhaDaIntegracao()->cancelado_em === '2026-10-05 15:00:00' && linhaDaIntegracao()->cancelado_por === 'loja' && linhaDaIntegracao()->pago_mesmo_cancelado === false, 'linha: cancelado pela loja, sem pagamento');
confere(\Teste\Sessao::$dados['company'] === 'empresa-1' && logou('pedido cancelado pela origem', 'warning'), 'empresa na sessão e log');

echo '== Aceito, antes de "A caminho": cancela sem pagar' . PHP_EOL;
reiniciarIntegracao();
$pedido = pedidoDaIntegracao(['status' => 'started']);
confere(cancelar() === CancelamentoPelaOrigem::CANCELADO && $pedido->status === 'canceled' && linhaDaIntegracao()->pago_mesmo_cancelado === false, 'cancelado, sem pagamento');

echo '== "A caminho": cancela e paga' . PHP_EOL;
reiniciarIntegracao();
$pedido = pedidoDaIntegracao(['status' => 'enroute']);
confere(cancelar() === CancelamentoPelaOrigem::CANCELADO_PAGO && $pedido->status === 'canceled' && linhaDaIntegracao()->pago_mesmo_cancelado === true, 'cancelado_pago');

echo '== Concluído: nada muda' . PHP_EOL;
reiniciarIntegracao();
$pedido = pedidoDaIntegracao(['status' => 'completed']);
confere(cancelar() === CancelamentoPelaOrigem::JA_CONCLUIDO && $pedido->chamadas === [] && linhaDaIntegracao()->cancelado_em === null, 'ja_concluido, sem mexer');

echo '== Já cancelado: idempotente' . PHP_EOL;
reiniciarIntegracao();
$pedido = pedidoDaIntegracao(['status' => 'enroute']);
cancelar();
Relogio::$agora = '2026-10-05 15:10:00';
confere(cancelar() === CancelamentoPelaOrigem::CANCELADO_PAGO && $pedido->chamadas === ['saveQuietly', 'cancel'] && linhaDaIntegracao()->cancelado_em === '2026-10-05 15:00:00', 'segunda vez: mesmo resultado, sem outro cancel(), mesma data');
reiniciarIntegracao();
$pedido = pedidoDaIntegracao(['status' => 'canceled'], ['cancelado_em' => '2026-10-05 14:55:00', 'cancelado_por' => 'central']);
confere(cancelar() === CancelamentoPelaOrigem::CANCELADO && linhaDaIntegracao()->cancelado_por === 'central', 'cancelado antes pela central: cancelado, sem trocar quem cancelou');

echo '== Expirado: registra como cancelado' . PHP_EOL;
reiniciarIntegracao();
$pedido = pedidoDaIntegracao(['status' => 'expired']);
confere(cancelar() === CancelamentoPelaOrigem::CANCELADO && $pedido->chamadas === [] && linhaDaIntegracao()->cancelado_em !== null, 'sem cancel(), linha marcada');

echo '== Sem Order: só registra' . PHP_EOL;
reiniciarIntegracao();
pedidoDaIntegracao();
Order::$todos = [];
confere(cancelar() === CancelamentoPelaOrigem::CANCELADO && linhaDaIntegracao()->cancelado_em !== null && logou('cancelamento de pedido sem Order', 'info'), 'cancelado e registrado');

echo '== Trava ocupada' . PHP_EOL;
reiniciarIntegracao();
$pedido                                     = pedidoDaIntegracao();
Trava::$ocupadas['entregas:pedido:order-1'] = true;
confere(excecao(fn () => cancelar()) instanceof \Illuminate\Contracts\Cache\LockTimeoutException && $pedido->chamadas === [] && linhaDaIntegracao()->cancelado_em === null, 'LockTimeoutException, nada gravado');

echo '== Texto do push' . PHP_EOL;
confere(CancelamentoPelaOrigem::textoDoPush((object) ['numero' => '4821', 'cancelado_em' => '2026-10-05 15:00:00', 'cancelado_por' => 'loja', 'pago_mesmo_cancelado' => true]) === ['Pedido #4821 cancelado pela loja', 'Você recebe por esta entrega. Combine com a loja a devolução.'], 'pago');
confere(CancelamentoPelaOrigem::textoDoPush((object) ['numero' => '4821', 'cancelado_em' => '2026-10-05 15:00:00', 'cancelado_por' => 'loja', 'pago_mesmo_cancelado' => false]) === ['Pedido #4821 cancelado pela loja', 'Não precisa mais fazer esta entrega.'], 'sem pagamento');
confere(CancelamentoPelaOrigem::textoDoPush((object) ['numero' => '4821', 'cancelado_em' => '2026-10-05 15:00:00', 'cancelado_por' => 'central', 'pago_mesmo_cancelado' => false]) === null, 'cancelado pela central: texto comum');
confere(CancelamentoPelaOrigem::textoDoPush((object) ['numero' => '4821', 'cancelado_em' => null, 'cancelado_por' => null, 'pago_mesmo_cancelado' => false]) === null, 'não cancelado: null');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-cancelamento.php`
Expected: erro `Class "App\Support\Entregas\Integracao\CancelamentoPelaOrigem" not found`.

- [ ] **Step 3: Implementar**

`api/app/Support/Entregas/Integracao/CancelamentoPelaOrigem.php`:

```php
<?php

namespace App\Support\Entregas\Integracao;

use App\Support\Entregas\Ifood\CancelamentoPeloIfood;
use App\Support\Entregas\StatusDoPedido;
use App\Support\Entregas\TravaDoPedido;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: o sistema de origem cancelou o pedido (POST v1/entregas/integracao/pedidos/{id}/cancelar).
 * Spec, Parte 2, "Cancelamento pela origem":
 *
 * - sem aceite ou aceito antes do "A caminho": cancela sem pagar;
 * - "A caminho" (enroute: o motoboy está com a comida): cancela como pago mesmo cancelado (a regra do iFood): o
 *   relatório, a cobrança da loja e os ganhos contam o pedido pela data do cancelamento (CalculoEntregas), e o push
 *   acrescenta "Você recebe por esta entrega…";
 * - concluído: JA_CONCLUIDO (409 na rota), sem mexer;
 * - já cancelado (pela origem antes, pela central ou pelo portal): devolve o resultado sem cancelar de novo;
 * - expirado: só registra o cancelamento na linha;
 * - sem Order: só registra.
 *
 * Como o CancelamentoPeloIfood: com a TravaDoPedido (um aceite no mesmo instante espera e relê), sai dos pedidos abertos
 * sem eventos (dispatched e adhoc falsos, scheduled_at nulo, saveQuietly) e depois Order::cancel() (atividade
 * "canceled" e OrderCanceled → push ao motoboy atribuído; a distribuição encerra pelo ObservadorDaDistribuicao). A
 * linha é gravada antes do cancel(): o push (fila) e o aviso de volta leem o cancelado_por e o pago_mesmo_cancelado.
 *
 * @throws \Illuminate\Contracts\Cache\LockTimeoutException trava ocupada (a rota responde 503)
 */
class CancelamentoPelaOrigem
{
    public const CANCELADO      = 'cancelado';
    public const CANCELADO_PAGO = 'cancelado_pago';
    public const JA_CONCLUIDO   = 'ja_concluido';

    public function cancelar(object $linha): string
    {
        $orderUuid = (string) $linha->order_uuid;

        return TravaDoPedido::executar($orderUuid, fn () => $this->comATrava($orderUuid));
    }

    /** Título e texto do push ao motoboy para o pedido cancelado pela origem, ou null (outro cancelamento). */
    public static function textoDoPush(object $linha): ?array
    {
        if (empty($linha->cancelado_em) || ($linha->cancelado_por ?? null) !== 'loja') {
            return null;
        }

        $numero = trim((string) ($linha->numero ?? ''));
        $titulo = $numero !== '' ? "Pedido #{$numero} cancelado pela loja" : 'Pedido cancelado pela loja';

        return [$titulo, !empty($linha->pago_mesmo_cancelado) ? CancelamentoPeloIfood::TEXTO_PAGO : 'Não precisa mais fazer esta entrega.'];
    }

    protected function comATrava(string $orderUuid): string
    {
        // relidos com a trava: um aceite ou um "A caminho" que terminou enquanto esperávamos aparece aqui
        $linha  = PedidosDaIntegracao::doPedido($orderUuid);
        $pedido = Order::where('uuid', $orderUuid)->first();

        if ($pedido && in_array($pedido->status, StatusDoPedido::CANCELADOS, true)) {
            return !empty($linha->pago_mesmo_cancelado) ? static::CANCELADO_PAGO : static::CANCELADO;
        }
        if ($pedido && in_array($pedido->status, ['completed', 'done'], true)) {
            return static::JA_CONCLUIDO;
        }

        $pago = $pedido !== null && $pedido->status === 'enroute';
        PedidosDaIntegracao::atualizar($linha, [
            'cancelado_em'         => $linha->cancelado_em ?? now()->toDateTimeString(),
            'cancelado_por'        => $linha->cancelado_por ?? 'loja',
            'pago_mesmo_cancelado' => $pago,
        ]);

        if (!$pedido) {
            Log::info('[entregas] integração: cancelamento de pedido sem Order; só registrado', ['pedido_externo' => $linha->pedido_externo_id]);

            return static::CANCELADO;
        }
        if ($pedido->status === 'expired') {
            return static::CANCELADO;
        }

        // primeiro e sem eventos: sai dos pedidos abertos do app e do agendamento mesmo que o cancel() falhe
        $pedido->dispatched   = false;
        $pedido->adhoc        = false;
        $pedido->scheduled_at = null;
        $pedido->saveQuietly();

        session(['company' => $pedido->company_uuid]);
        $pedido->cancel();

        Log::warning('[entregas] integração: pedido cancelado pela origem', [
            'pedido'               => $pedido->public_id,
            'pago_mesmo_cancelado' => $pago,
            'com_motoboy'          => (bool) $pedido->driver_assigned_uuid,
        ]);

        return $pago ? static::CANCELADO_PAGO : static::CANCELADO;
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-cancelamento.php`
Expected: `FALHAS: 0`.

- [ ] **Step 5: Commit**

```bash
git add api/app/Support/Entregas/Integracao/CancelamentoPelaOrigem.php scripts/teste-php/integracao-cancelamento.php
git commit -m "Integração: cancelamento pela origem (pago depois do A caminho)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: `SequenciaDaIntegracao`

**Files:**
- Create: `api/app/Support/Entregas/Integracao/SequenciaDaIntegracao.php`
- Test: `scripts/teste-php/integracao-sequencia.php`

- [ ] **Step 1: Escrever o teste**

`scripts/teste-php/integracao-sequencia.php`:

```php
<?php

// Integração: quais avisos faltam, pelo estado do Order (SequenciaDaIntegracao, função pura).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-sequencia.php

require __DIR__ . '/stubs-integracao.php';

use App\Support\Entregas\Integracao\SequenciaDaIntegracao as S;

echo '== Alvo' . PHP_EOL;
confere(S::alvo('dispatched', false, null) === null && S::alvo('created', false, 'driver-1') === null, 'sem aceite: nada');
confere(S::alvo('started', true, 'driver-1') === S::ACEITO, 'aceito');
confere(S::alvo('enroute', true, 'driver-1') === S::A_CAMINHO, 'a caminho');
confere(S::alvo('completed', true, 'driver-1') === S::CONCLUIDO && S::alvo('done', true, 'driver-1') === S::CONCLUIDO, 'concluído');
foreach (['canceled', 'cancelled', 'order_canceled', 'expired'] as $status) {
    confere(S::alvo($status, false, null) === S::CANCELADO, "cancelado: {$status}");
}

echo '== Faltando' . PHP_EOL;
confere(S::faltando(null, null, false, false) === [], 'sem alvo: nada');
confere(S::faltando(null, S::ACEITO, true, false) === [S::ACEITO], 'primeiro aceite');
confere(S::faltando(S::ACEITO, S::ACEITO, true, false) === [], 'aceite já avisado');
confere(S::faltando(S::ACEITO, S::A_CAMINHO, true, false) === [S::A_CAMINHO], 'a caminho depois do aceite');
confere(S::faltando(null, S::A_CAMINHO, true, false) === [S::ACEITO, S::A_CAMINHO], 'a caminho sem o aceite avisado: os dois, em ordem');
confere(S::faltando(S::ACEITO, S::CONCLUIDO, true, false) === [S::CONCLUIDO], 'concluído sem passar pelo a caminho: só o concluído');
confere(S::faltando(null, S::CONCLUIDO, true, false) === [S::ACEITO, S::CONCLUIDO], 'concluído sem nada avisado: aceito e concluído');
confere(S::faltando(null, S::CONCLUIDO, false, false) === [S::CONCLUIDO], 'concluído pela central sem motoboy: só o concluído');
confere(S::faltando(S::A_CAMINHO, S::A_CAMINHO, true, true) === [S::ACEITO], 'troca de motoboy no a caminho: o aceito de novo');
confere(S::faltando(S::ACEITO, S::ACEITO, true, true) === [S::ACEITO], 'troca de motoboy antes do a caminho');
confere(S::faltando(S::A_CAMINHO, S::CANCELADO, true, false) === [S::CANCELADO] && S::faltando(null, S::CANCELADO, false, false) === [S::CANCELADO], 'cancelado: só o cancelado');
confere(S::faltando(S::CONCLUIDO, S::CANCELADO, true, false) === [] && S::faltando(S::CANCELADO, S::CANCELADO, true, false) === [], 'depois do fim: nada');

echo '== depoisDe' . PHP_EOL;
confere(S::depoisDe(null, S::ACEITO) === S::ACEITO && S::depoisDe(S::ACEITO, S::A_CAMINHO) === S::A_CAMINHO, 'avança');
confere(S::depoisDe(S::A_CAMINHO, S::ACEITO) === S::A_CAMINHO, 'o aceito reenviado (troca de motoboy) não volta');

echo '== doPedido' . PHP_EOL;
$linha = (object) ['ultimo_aviso' => S::A_CAMINHO, 'motoboy_avisado' => 'driver-1'];
confere(S::doPedido($linha, (object) ['status' => 'enroute', 'started' => true, 'driver_assigned_uuid' => 'driver-1']) === [], 'nada mudou');
confere(S::doPedido($linha, (object) ['status' => 'enroute', 'started' => true, 'driver_assigned_uuid' => 'driver-2']) === [S::ACEITO], 'motoboy trocado');
confere(S::doPedido((object) ['ultimo_aviso' => null, 'motoboy_avisado' => null], (object) ['status' => 'started', 'started' => true, 'driver_assigned_uuid' => 'driver-1']) === [S::ACEITO], 'primeiro aceite (motoboy_avisado nulo não conta como troca)');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-sequencia.php`
Expected: erro `Class "App\Support\Entregas\Integracao\SequenciaDaIntegracao" not found`.

- [ ] **Step 3: Implementar**

`api/app/Support/Entregas/Integracao/SequenciaDaIntegracao.php`:

```php
<?php

namespace App\Support\Entregas\Integracao;

use App\Support\Entregas\StatusDoPedido;

/**
 * Entregas RestaurantePro: os avisos de volta ao sistema de origem (função pura). O estado vem do Order (status, started,
 * motoboy); o que já foi aceito pelo destino, da linha (ultimo_aviso, motoboy_avisado). Como o SequenciaIfood: o job não
 * recebe o evento, relê o pedido e manda o que falta, então um aviso perdido sai junto com o seguinte.
 *
 * - aceito: com o motoboy (nome e foto). Sai de novo quando o motoboy muda (troca pela central ou pelo líder), sem
 *   voltar o ultimo_aviso;
 * - a_caminho: só quando o pedido está "A caminho" (o RestaurantePro manda o WhatsApp com o link): se já foi concluído,
 *   o a_caminho atrasado não sai;
 * - concluido;
 * - cancelado (inclusive expirado): encerra a sequência, em qualquer ponto.
 */
final class SequenciaDaIntegracao
{
    public const ACEITO    = 'aceito';
    public const A_CAMINHO = 'a_caminho';
    public const CONCLUIDO = 'concluido';
    public const CANCELADO = 'cancelado';

    public const ORDEM = [self::ACEITO => 1, self::A_CAMINHO => 2, self::CONCLUIDO => 3, self::CANCELADO => 4];

    /** O ponto em que o pedido está, ou null (ainda sem aceite). */
    public static function alvo(string $status, bool $iniciado, ?string $motoboy): ?string
    {
        if (in_array($status, StatusDoPedido::CANCELADOS, true) || $status === 'expired') {
            return static::CANCELADO;
        }
        if (in_array($status, ['completed', 'done'], true)) {
            return static::CONCLUIDO;
        }
        if ($status === 'enroute') {
            return static::A_CAMINHO;
        }

        return $iniciado && $motoboy ? static::ACEITO : null;
    }

    /** Os eventos a enviar, em ordem. */
    public static function faltando(?string $ultimo, ?string $alvo, bool $temMotoboy, bool $motoboyMudou): array
    {
        if ($alvo === null || in_array($ultimo, [static::CONCLUIDO, static::CANCELADO], true)) {
            return [];
        }
        if ($alvo === static::CANCELADO) {
            return [static::CANCELADO];
        }

        $nivel   = static::ORDEM[$ultimo] ?? 0;
        $eventos = [];
        if ($temMotoboy && ($nivel < static::ORDEM[static::ACEITO] || $motoboyMudou)) {
            $eventos[] = static::ACEITO;
        }
        if ($alvo === static::A_CAMINHO && $nivel < static::ORDEM[static::A_CAMINHO]) {
            $eventos[] = static::A_CAMINHO;
        }
        if ($alvo === static::CONCLUIDO) {
            $eventos[] = static::CONCLUIDO;
        }

        return $eventos;
    }

    /** O ultimo_aviso depois de um envio aceito: o aceito reenviado não volta o ponto. */
    public static function depoisDe(?string $ultimo, string $evento): string
    {
        return (static::ORDEM[$evento] ?? 0) > (static::ORDEM[$ultimo] ?? 0) ? $evento : (string) $ultimo;
    }

    /** Os eventos que faltam para a linha e o Order (os dois só com os campos usados). */
    public static function doPedido(object $linha, object $pedido): array
    {
        $motoboy = $pedido->driver_assigned_uuid ? (string) $pedido->driver_assigned_uuid : null;
        $avisado = $linha->motoboy_avisado ?? null;
        $mudou   = $motoboy !== null && $avisado !== null && $avisado !== $motoboy;

        return static::faltando($linha->ultimo_aviso ?? null, static::alvo((string) $pedido->status, (bool) $pedido->started, $motoboy), $motoboy !== null, $mudou);
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-sequencia.php`
Expected: `FALHAS: 0`.

- [ ] **Step 5: Commit**

```bash
git add api/app/Support/Entregas/Integracao/SequenciaDaIntegracao.php scripts/teste-php/integracao-sequencia.php
git commit -m "Integração: sequência dos avisos de volta

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Avisos por webhook (`AvisosDaIntegracao`, job e observador)

**Files:**
- Create: `api/app/Support/Entregas/Integracao/FalhaNoAviso.php`
- Create: `api/app/Support/Entregas/Integracao/AvisosDaIntegracao.php`
- Create: `api/app/Jobs/Entregas/EnviarAvisoDaIntegracao.php`
- Create: `api/app/Listeners/Entregas/ObservadorDosPedidosDaIntegracao.php`
- Test: `scripts/teste-php/integracao-avisos.php`

- [ ] **Step 1: Escrever o teste**

`scripts/teste-php/integracao-avisos.php`:

```php
<?php

// Integração: avisos de volta por webhook assinado (AvisosDaIntegracao), o job (EnviarAvisoDaIntegracao) e o observador
// do Order (ObservadorDosPedidosDaIntegracao).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-avisos.php

require __DIR__ . '/stubs-integracao.php';

use App\Jobs\Entregas\EnviarAvisoDaIntegracao;
use App\Listeners\Entregas\ObservadorDosPedidosDaIntegracao;
use App\Support\Entregas\Integracao\AvisosDaIntegracao;
use App\Support\Entregas\Integracao\ChavesDeIntegracao;
use App\Support\Entregas\Integracao\FalhaNoAviso;
use Fleetbase\FleetOps\Models\Driver;
use Illuminate\Support\Facades\Cache;
use Teste\Fila;
use Teste\Http;
use Teste\Trava;

function sincronizar(?\Closure $resolver = null): array
{
    return (new AvisosDaIntegracao($resolver ?? resolverPublico()))->sincronizar('order-1');
}

function corpoEnviado(int $indice = 0): array
{
    return json_decode(Http::$chamadas[$indice]['corpo'], true);
}

function lojaA(): ?object
{
    return ChavesDeIntegracao::ativa('empresa-1', 'vendor-a');
}

echo '== Aceito: envia assinado' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao();
Http::responder(200, ['ok' => true]);
confere(sincronizar() === ['aceito'], 'envia o aceito');
$chamada = Http::$chamadas[0];
confere($chamada['metodo'] === 'POST' && $chamada['url'] === 'https://api.restaurantepro.com.br/functions/v1/entregas-webhook?t=abc', 'POST na URL da loja');
confere($chamada['timeout'] === 5 && $chamada['options'] === ['allow_redirects' => false], 'timeout de 5 s e sem seguir redirecionamento');
confere($chamada['headers']['X-Entregas-Evento'] === 'pedido.aceito' && $chamada['headers']['X-Entregas-Id'] === AvisosDaIntegracao::idDoAviso('order-1', 'aceito', 'driver-1'), 'cabeçalhos do evento e do id');
confere($chamada['headers']['X-Entregas-Assinatura'] === 'sha256=' . hash_hmac('sha256', $chamada['corpo'], 'segredo-a'), 'assinatura HMAC-SHA256 do corpo cru');
$corpo = corpoEnviado();
confere($corpo['evento'] === 'pedido.aceito' && $corpo['pedido_externo_id'] === 'ord-1' && $corpo['id'] === 'order_1' && $corpo['status'] === 'started', 'corpo: evento e ids');
confere($corpo['motoboy'] === ['nome' => 'Motoboy Ficticio', 'foto_url' => 'https://exemplo.com/foto.png'] && $corpo['id_entrega'] === $chamada['headers']['X-Entregas-Id'], 'corpo: motoboy e id do aviso');
confere($corpo['codigo_rastreio'] === 'RP0001' && $corpo['link_rastreio'] === 'https://entregas.restaurantepro.com.br/~/track-order?order=RP0001' && $corpo['cancelamento'] === null, 'corpo: rastreio');
confere($corpo['ocorrido_em'] === '2026-10-05T15:00:00-03:00', 'corpo: ocorrido_em em -03:00');
confere(linhaDaIntegracao()->ultimo_aviso === 'aceito' && linhaDaIntegracao()->motoboy_avisado === 'driver-1', 'grava o último aviso e o motoboy');
confere(logou('[entregas] integração: aviso enviado', 'info') && logsSem(['segredo-a', 'Motoboy Ficticio']), 'log sem segredo nem nome');
confere(sincronizar() === [] && count(Http::$chamadas) === 1, 'de novo: nada a enviar');

echo '== A caminho e concluído' . PHP_EOL;
\Fleetbase\FleetOps\Models\Order::$todos[0]->status = 'enroute';
Http::responder(204);
confere(sincronizar() === ['a_caminho'] && corpoEnviado(1)['evento'] === 'pedido.a_caminho', 'a caminho (204 também vale)');
\Fleetbase\FleetOps\Models\Order::$todos[0]->status = 'completed';
Http::responder(200);
confere(sincronizar() === ['concluido'] && linhaDaIntegracao()->ultimo_aviso === 'concluido', 'concluído');

echo '== Concluído sem nada avisado: aceito e concluído' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao(['status' => 'completed']);
Http::responder(200);
Http::responder(200);
confere(sincronizar() === ['aceito', 'concluido'] && corpoEnviado(0)['evento'] === 'pedido.aceito' && corpoEnviado(1)['evento'] === 'pedido.concluido', 'os dois, em ordem');

echo '== Troca de motoboy' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
$pedido = pedidoDaIntegracao(['status' => 'enroute'], ['ultimo_aviso' => 'a_caminho', 'motoboy_avisado' => 'driver-1']);
Driver::$todos[]              = new Driver(['uuid' => 'driver-2', 'public_id' => 'driver_2', 'name' => 'Outro Ficticio']);
$pedido->driver_assigned_uuid = 'driver-2';
$pedido->driverAssigned       = Driver::$todos[1];
Http::responder(200);
confere(sincronizar() === ['aceito'] && corpoEnviado()['motoboy']['nome'] === 'Outro Ficticio', 'o aceito de novo, com o motoboy novo');
confere(linhaDaIntegracao()->ultimo_aviso === 'a_caminho' && linhaDaIntegracao()->motoboy_avisado === 'driver-2', 'o ponto não volta; motoboy avisado trocado');

echo '== Cancelado' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao(['status' => 'canceled'], ['ultimo_aviso' => 'a_caminho', 'motoboy_avisado' => 'driver-1', 'cancelado_em' => '2026-10-05 14:58:00', 'cancelado_por' => 'loja', 'pago_mesmo_cancelado' => true]);
Http::responder(200);
confere(sincronizar() === ['cancelado'] && corpoEnviado()['cancelamento'] === ['por' => 'loja', 'pago' => true], 'cancelado, com quem e se paga');
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao(['status' => 'canceled', 'started' => false, 'driver_assigned_uuid' => null]);
Http::responder(200);
confere(sincronizar() === ['cancelado'] && corpoEnviado()['cancelamento'] === ['por' => 'central', 'pago' => false] && corpoEnviado()['motoboy'] === null, 'sem quem cancelou gravado: central');

echo '== Falhas' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao();
Http::responder(500, 'erro interno com dados');
$erro = excecao(fn () => sincronizar());
confere($erro instanceof FalhaNoAviso && $erro->status === 500 && linhaDaIntegracao()->ultimo_aviso === null, '500: FalhaNoAviso, nada gravado');
confere(!str_contains($erro->getMessage(), 'dados'), 'a exceção não leva o corpo da resposta');
Http::falharConexao();
$erro = excecao(fn () => sincronizar());
confere($erro instanceof FalhaNoAviso && $erro->status === 0 && logou('aviso sem resposta', 'warning'), 'rede: FalhaNoAviso com status 0');
Http::responder(410);
confere(sincronizar() === [] && lojaA()->ultima_falha_aviso !== null && logou('o destino recusou os avisos (410)', 'warning'), '410: para sem exceção e registra a falha');
$antes = count(Http::$chamadas);
confere(sincronizar(fn ($host) => ['10.0.0.7']) === [] && count(Http::$chamadas) === $antes, 'URL que resolve para rede privada: não envia');
confere(str_contains(lojaA()->ultima_falha_aviso, 'URL do webhook recusada') && logou('webhook recusado', 'warning'), 'registra e avisa no log');
Http::responder(200);
sincronizar();
confere(lojaA()->ultima_falha_aviso === null, 'o envio aceito limpa a falha');

echo '== Sem destino' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA(false);
pedidoDaIntegracao();
confere(sincronizar() === [] && Http::$chamadas === [], 'loja sem conexão: nada');
reiniciarIntegracao();
pedidoDaIntegracao();
confere(sincronizar() === [] && Http::$chamadas === [], 'sem chave ativa: nada');

echo '== Trava' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao();
Trava::$ocupadas['entregas:integracao-aviso:order-1'] = true;
confere(excecao(fn () => sincronizar()) instanceof \Illuminate\Contracts\Cache\LockTimeoutException, 'trava ocupada: LockTimeoutException');

echo '== Job' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao();
confere(EnviarAvisoDaIntegracao::enfileirar('order-1') === true && EnviarAvisoDaIntegracao::enfileirar('order-1') === false && count(Fila::$jobs) === 1, 'um job por pedido de cada vez');
$job = Fila::$jobs[0];
confere($job->afterCommit === true && $job->queue === null, 'afterCommit, fila default');
Http::responder(503);
$erro = excecao(fn () => $job->handle(new AvisosDaIntegracao(resolverPublico())));
confere($erro instanceof FalhaNoAviso && Cache::get(EnviarAvisoDaIntegracao::PENDENTE . 'order-1') === true, 'falha sobe (nova tentativa) e a marca fica');
$job->failed($erro);
confere(Cache::get(EnviarAvisoDaIntegracao::PENDENTE . 'order-1') === null && logou('[entregas] integração: aviso não entregue', 'error') && str_contains((string) lojaA()->ultima_falha_aviso, '503'), 'esgotadas: tira a marca, loga e registra a falha na loja');
Trava::$ocupadas['entregas:integracao-aviso:order-1'] = true;
$job->handle(new AvisosDaIntegracao(resolverPublico()));
confere($job->liberadoPor === EnviarAvisoDaIntegracao::ESPERA_DA_TRAVA, 'trava ocupada: volta para a fila');
unset(Trava::$ocupadas['entregas:integracao-aviso:order-1']);
\Teste\Config::$valores['services.entregas.integracao'] = '';
$antes = count(Http::$chamadas);
$job->handle(new AvisosDaIntegracao(resolverPublico()));
confere(count(Http::$chamadas) === $antes, 'integração desligada: o job não envia');

echo '== Observador' . PHP_EOL;
reiniciarIntegracao();
$pedido            = pedidoDaIntegracao();
$pedido->alterados = ['status'];
ObservadorDosPedidosDaIntegracao::aoAtualizar($pedido);
confere(count(Fila::$jobs) === 1 && Fila::$jobs[0]->orderUuid === 'order-1', 'status mudou: enfileira');
reiniciarIntegracao();
$pedido            = pedidoDaIntegracao();
$pedido->alterados = ['notes'];
ObservadorDosPedidosDaIntegracao::aoAtualizar($pedido);
confere(Fila::$jobs === [], 'outro campo: nada');
reiniciarIntegracao();
$outro = new \Fleetbase\FleetOps\Models\Order(['uuid' => 'order-9', 'alterados' => ['status']]);
ObservadorDosPedidosDaIntegracao::aoAtualizar($outro);
confere(Fila::$jobs === [], 'pedido que não é da integração: nada');
reiniciarIntegracao();
$pedido            = pedidoDaIntegracao();
$pedido->alterados = ['status'];
\Teste\Config::$valores['services.entregas.integracao'] = '';
ObservadorDosPedidosDaIntegracao::aoAtualizar($pedido);
confere(Fila::$jobs === [], 'desligada: nada');

echo '== Observador: quem cancelou' . PHP_EOL;
foreach ([['canceled', null, 'central'], ['canceled', 'customer', 'loja'], ['canceled', 'user', 'central'], ['expired', null, 'sistema']] as [$status, $tipo, $esperado]) {
    reiniciarIntegracao();
    $pedido            = pedidoDaIntegracao(['status' => $status]);
    $pedido->alterados = ['status'];
    if ($tipo) {
        \Fleetbase\Models\User::$todos[] = new \Fleetbase\Models\User(['uuid' => 'user-1', 'type' => $tipo]);
        \Teste\Sessao::$dados['user']    = 'user-1';
    }
    ObservadorDosPedidosDaIntegracao::aoAtualizar($pedido);
    confere(linhaDaIntegracao()->cancelado_por === $esperado && linhaDaIntegracao()->cancelado_em === '2026-10-05 15:00:00' && count(Fila::$jobs) === 1, "{$status}" . ($tipo ? " por usuário {$tipo}" : '') . ": {$esperado}");
}
reiniciarIntegracao();
$pedido            = pedidoDaIntegracao(['status' => 'canceled'], ['cancelado_em' => '2026-10-05 14:50:00', 'cancelado_por' => 'loja']);
$pedido->alterados = ['status'];
ObservadorDosPedidosDaIntegracao::aoAtualizar($pedido);
confere(linhaDaIntegracao()->cancelado_por === 'loja' && linhaDaIntegracao()->cancelado_em === '2026-10-05 14:50:00', 'já registrado pela origem: não sobrescreve');
reiniciarIntegracao();
pedidoDaIntegracao();
ObservadorDosPedidosDaIntegracao::aoAtribuirMotoboy((object) ['modelUuid' => 'order-1']);
confere(count(Fila::$jobs) === 1, 'OrderDriverAssigned: enfileira');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-avisos.php`
Expected: erro `Class "App\Support\Entregas\Integracao\AvisosDaIntegracao" not found`.

- [ ] **Step 3: `FalhaNoAviso`**

`api/app/Support/Entregas/Integracao/FalhaNoAviso.php`:

```php
<?php

namespace App\Support\Entregas\Integracao;

/**
 * Entregas RestaurantePro: o sistema de origem não respondeu 2xx a um aviso (rede = status 0). O job tenta de novo. A
 * mensagem leva só o status (o failed_jobs guarda a exceção; o corpo da resposta pode ter dados do cliente).
 */
final class FalhaNoAviso extends \RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct("aviso da integração não entregue (status {$status})");
    }
}
```

- [ ] **Step 4: `AvisosDaIntegracao`**

`api/app/Support/Entregas/Integracao/AvisosDaIntegracao.php`:

```php
<?php

namespace App\Support\Entregas\Integracao;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: os avisos de volta ao sistema de origem (webhook). Relê o pedido, calcula o que falta
 * (SequenciaDaIntegracao) e envia em ordem, gravando o ultimo_aviso a cada envio aceito (2xx).
 *
 * - Corpo JSON {evento, id_entrega, pedido_externo_id, id, status, motoboy, codigo_rastreio, link_rastreio,
 *   cancelamento, ocorrido_em}; cabeçalhos X-Entregas-Evento, X-Entregas-Id (estável por pedido, evento e motoboy: o
 *   destino descarta o repetido) e X-Entregas-Assinatura = "sha256=" + HMAC-SHA256 do corpo cru com o segredo da loja.
 * - Timeout de 5 s, sem seguir redirecionamento, URL conferida de novo a cada envio (UrlDoWebhook).
 * - Não 2xx ou rede: FalhaNoAviso (o job tenta de novo). 410: o destino desistiu: registra a falha na loja e para, sem
 *   exceção. URL recusada: registra e para.
 * - Loja sem chave ativa, sem URL ou sem segredo legível: nada a enviar.
 * - Trava por pedido (entregas:integracao-aviso:<uuid>): o job e um envio no mesmo instante não mandam o mesmo aviso
 *   duas vezes.
 */
class AvisosDaIntegracao
{
    public const TIMEOUT = 5;

    public const TRAVA = 'entregas:integracao-aviso:';

    /** host => IPs, para a UrlDoWebhook (null = DNS de verdade). */
    protected ?\Closure $resolver;

    /**
     * `?callable`, e não `?\Closure`: o container do Laravel injeta o padrão (null) num parâmetro de tipo nativo; com o
     * tipo Closure ele tentaria construir um Closure.
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver === null ? null : \Closure::fromCallable($resolver);
    }

    /**
     * @return string[] os eventos enviados
     *
     * @throws FalhaNoAviso
     * @throws \Illuminate\Contracts\Cache\LockTimeoutException
     */
    public function sincronizar(string $orderUuid): array
    {
        return Cache::lock(static::TRAVA . $orderUuid, 60)->block(5, fn () => $this->sincronizarComATrava($orderUuid));
    }

    /** O job desistiu (tentativas esgotadas): a tela Lojas mostra a falha. */
    public function registrarFalha(string $orderUuid, int $status): void
    {
        $linha = PedidosDaIntegracao::doPedido($orderUuid);
        $loja  = $linha ? ChavesDeIntegracao::ativa((string) $linha->company_uuid, (string) $linha->vendor_uuid) : null;
        if ($loja) {
            ChavesDeIntegracao::registrarFalhaDeAviso($loja, $status > 0 ? "o sistema de origem respondeu {$status}" : 'o sistema de origem não respondeu');
        }
    }

    public static function idDoAviso(string $orderUuid, string $evento, ?string $motoboy): string
    {
        return substr(hash('sha256', "{$orderUuid}|{$evento}|{$motoboy}"), 0, 32);
    }

    public static function assinatura(string $json, string $segredo): string
    {
        return 'sha256=' . hash_hmac('sha256', $json, $segredo);
    }

    public static function corpo(string $evento, object $linha, object $pedido, ?object $motoboy, string $id, string $agora): array
    {
        $codigo = $pedido->tracking ?? null;

        return [
            'evento'            => 'pedido.' . $evento,
            'id_entrega'        => $id,
            'pedido_externo_id' => $linha->pedido_externo_id,
            'id'                => $pedido->public_id,
            'status'            => $pedido->status,
            'motoboy'           => $motoboy ? ['nome' => $motoboy->name, 'foto_url' => $motoboy->photo_url ?? null] : null,
            'codigo_rastreio'   => $codigo,
            'link_rastreio'     => Integracao::linkDeRastreio($codigo),
            'cancelamento'      => $evento === SequenciaDaIntegracao::CANCELADO
                ? ['por' => $linha->cancelado_por ?? 'central', 'pago' => (bool) ($linha->pago_mesmo_cancelado ?? false)]
                : null,
            'ocorrido_em'       => $agora,
        ];
    }

    protected function sincronizarComATrava(string $orderUuid): array
    {
        $linha  = PedidosDaIntegracao::doPedido($orderUuid);
        $pedido = $linha ? Order::where('uuid', $orderUuid)->with(['trackingNumber', 'driverAssigned.user.avatar'])->first() : null;
        if (!$linha || !$pedido) {
            return [];
        }

        $loja    = ChavesDeIntegracao::ativa((string) $linha->company_uuid, (string) $linha->vendor_uuid);
        $segredo = $loja && $loja->webhook_url ? ChavesDeIntegracao::segredo($loja) : null;
        if (!$segredo) {
            return [];
        }

        $eventos = SequenciaDaIntegracao::doPedido($linha, $pedido);
        if (!$eventos) {
            return [];
        }

        $motivo = UrlDoWebhook::recusa((string) $loja->webhook_url, $this->resolver);
        if ($motivo !== null) {
            ChavesDeIntegracao::registrarFalhaDeAviso($loja, 'URL do webhook recusada: ' . $motivo);
            Log::warning('[entregas] integração: webhook recusado (URL privada ou inválida)', ['vendor' => $loja->vendor_uuid, 'motivo' => $motivo]);

            return [];
        }

        $motoboyUuid = $pedido->driver_assigned_uuid ? (string) $pedido->driver_assigned_uuid : null;
        $motoboy     = $pedido->driverAssigned ?? null;
        $enviados    = [];
        foreach ($eventos as $evento) {
            $id     = static::idDoAviso($orderUuid, $evento, $motoboyUuid);
            $corpo  = static::corpo($evento, $linha, $pedido, $motoboy, $id, now()->toIso8601String());
            $status = $this->enviar($loja, $segredo, $evento, $id, $corpo);

            if ($status === 410) {
                ChavesDeIntegracao::registrarFalhaDeAviso($loja, 'o sistema de origem respondeu 410 (integração encerrada lá)');
                Log::warning('[entregas] integração: o destino recusou os avisos (410)', ['pedido' => $pedido->public_id, 'evento' => $evento]);

                return $enviados;
            }
            if ($status < 200 || $status >= 300) {
                throw new FalhaNoAviso($status);
            }

            $valores = ['ultimo_aviso' => SequenciaDaIntegracao::depoisDe($linha->ultimo_aviso ?? null, $evento)];
            if ($evento === SequenciaDaIntegracao::ACEITO) {
                $valores['motoboy_avisado'] = $motoboyUuid;
            }
            PedidosDaIntegracao::atualizar($linha, $valores);
            ChavesDeIntegracao::limparFalhaDeAviso($loja);
            Log::info('[entregas] integração: aviso enviado', ['pedido' => $pedido->public_id, 'evento' => $evento, 'status' => $status]);
            $enviados[] = $evento;
        }

        return $enviados;
    }

    /** O status HTTP da resposta, ou 0 sem resposta (rede, timeout). */
    protected function enviar(object $loja, string $segredo, string $evento, string $id, array $corpo): int
    {
        $json = (string) json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $resposta = Http::withHeaders([
                'X-Entregas-Evento'     => 'pedido.' . $evento,
                'X-Entregas-Id'         => $id,
                'X-Entregas-Assinatura' => static::assinatura($json, $segredo),
                'User-Agent'            => 'EntregasRestaurantePro/1.0',
            ])->withOptions(['allow_redirects' => false])->timeout(static::TIMEOUT)->withBody($json, 'application/json')->post((string) $loja->webhook_url);
        } catch (\Throwable $e) {
            Log::warning('[entregas] integração: aviso sem resposta', ['vendor' => $loja->vendor_uuid, 'evento' => $evento, 'erro' => get_class($e)]);

            return 0;
        }

        return $resposta->status();
    }
}
```

- [ ] **Step 5: Job `EnviarAvisoDaIntegracao`**

`api/app/Jobs/Entregas/EnviarAvisoDaIntegracao.php`:

```php
<?php

namespace App\Jobs\Entregas;

use App\Support\Entregas\Integracao\AvisosDaIntegracao;
use App\Support\Entregas\Integracao\FalhaNoAviso;
use App\Support\Entregas\Integracao\Integracao;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: leva os avisos de um pedido da integração até o sistema de origem (AvisosDaIntegracao), na
 * fila `default` (não disputa com o iFood). Enfileirado pelo ObservadorDosPedidosDaIntegracao e pelo
 * entregas:integracao-acompanhar. Um job por pedido de cada vez, com a marca de pendente no cache (o mesmo esquema do
 * EnviarAcaoIfood): o job apaga a marca antes de ler o pedido; ao voltar para a fila, a marca volta junto.
 *
 * Falhas: trava ocupada volta em ESPERA_DA_TRAVA s; FalhaNoAviso sobe e a fila tenta de novo pelo $backoff (até 5 vezes
 * em 30 min). Esgotadas, failed() registra `[entregas] integração: aviso não entregue` e a falha na loja (tela Lojas); a
 * próxima mudança do pedido ou o acompanhamento (depois de 5 min) tentam de novo.
 */
class EnviarAvisoDaIntegracao implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const PENDENTE = 'entregas:integracao-aviso-pendente:';

    public const VALIDADE_DO_PENDENTE = 120;

    public const ESPERA_DA_TRAVA = 5;

    public const PRAZO_MINUTOS = 30;

    public int $timeout = 60;

    public int $maxExceptions = 5;

    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(public string $orderUuid)
    {
        // afterCommit no construtor: o trait Queueable já declara a propriedade (ver EnviarAcaoIfood)
        $this->afterCommit = true;
    }

    /** Enfileira, a não ser que este pedido já tenha um job esperando (devolve false). */
    public static function enfileirar(string $orderUuid): bool
    {
        if (!Cache::add(static::PENDENTE . $orderUuid, true, static::VALIDADE_DO_PENDENTE)) {
            return false;
        }

        try {
            static::dispatch($orderUuid);
        } catch (\Throwable $e) {
            Cache::forget(static::PENDENTE . $orderUuid);
            throw $e;
        }

        return true;
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(static::PRAZO_MINUTOS);
    }

    public function handle(AvisosDaIntegracao $avisos): void
    {
        Cache::forget(static::PENDENTE . $this->orderUuid);
        if (!Integracao::ligada()) {
            return;
        }

        try {
            $avisos->sincronizar($this->orderUuid);
        } catch (LockTimeoutException $e) {
            $this->voltarParaAFila(static::ESPERA_DA_TRAVA);
        } catch (FalhaNoAviso $e) {
            $this->marcarPendente(max($this->backoff));
            throw $e;
        }
    }

    public function failed(\Throwable $erro): void
    {
        Cache::forget(static::PENDENTE . $this->orderUuid);

        $status = $erro instanceof FalhaNoAviso ? $erro->status : 0;
        Log::error('[entregas] integração: aviso não entregue', ['order_uuid' => $this->orderUuid, 'status' => $status, 'erro' => get_class($erro)]);

        try {
            app(AvisosDaIntegracao::class)->registrarFalha($this->orderUuid, $status);
        } catch (\Throwable $e) {
            Log::warning('[entregas] integração: falha ao registrar o aviso não entregue', ['order_uuid' => $this->orderUuid, 'erro' => get_class($e)]);
        }
    }

    protected function voltarParaAFila(int $espera): void
    {
        $this->marcarPendente($espera);
        $this->release($espera);
    }

    protected function marcarPendente(int $espera): void
    {
        Cache::put(static::PENDENTE . $this->orderUuid, true, $espera + static::VALIDADE_DO_PENDENTE);
    }
}
```

- [ ] **Step 6: Observador**

`api/app/Listeners/Entregas/ObservadorDosPedidosDaIntegracao.php`:

```php
<?php

namespace App\Listeners\Entregas;

use App\Jobs\Entregas\EnviarAvisoDaIntegracao;
use App\Support\Entregas\Integracao\Integracao;
use App\Support\Entregas\Integracao\PedidosDaIntegracao;
use App\Support\Entregas\StatusDoPedido;
use Fleetbase\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: converte a mudança de um pedido da integração no aviso de volta ao sistema de origem
 * (EnviarAvisoDaIntegracao). Registrado no AppServiceProvider, como o ObservadorDosPedidosIfood:
 * - `Order::updated` com mudança em driver_assigned_uuid, started ou status;
 * - `OrderDriverAssigned` (atribuição em lote);
 * - o que escapar (saveQuietly, job perdido) o entregas:integracao-acompanhar acha em até 1 min.
 *
 * No cancelamento que não veio da origem, grava na linha quem cancelou: "loja" (usuário de loja, o portal), "central"
 * (o console, a API v1) ou "sistema" (expirado). Nunca lança: roda dentro da requisição do app ou do console.
 */
class ObservadorDosPedidosDaIntegracao
{
    public const CAMPOS = ['driver_assigned_uuid', 'started', 'status'];

    public static function aoAtualizar(object $pedido): void
    {
        try {
            if (!Integracao::ligada() || !$pedido->wasChanged(static::CAMPOS)) {
                return;
            }
            $linha = PedidosDaIntegracao::doPedido((string) $pedido->uuid);
            if (!$linha) {
                return;
            }
            $cancelado = in_array($pedido->status, StatusDoPedido::CANCELADOS, true) || $pedido->status === 'expired';
            if ($cancelado && !$linha->cancelado_em) {
                PedidosDaIntegracao::atualizar($linha, [
                    'cancelado_em'  => now()->toDateTimeString(),
                    'cancelado_por' => $pedido->status === 'expired' ? 'sistema' : static::quemCancelou(),
                ]);
            }
            EnviarAvisoDaIntegracao::enfileirar((string) $pedido->uuid);
        } catch (\Throwable $e) {
            Log::warning('[entregas] integração: falha ao enfileirar o aviso do pedido', ['pedido' => $pedido->public_id ?? null, 'erro' => get_class($e)]);
        }
    }

    /** OrderDriverAssigned (Fleet-Ops): o evento guarda o uuid do pedido (modelUuid). */
    public static function aoAtribuirMotoboy(object $evento): void
    {
        try {
            $uuid = $evento->modelUuid ?? null;
            if (Integracao::ligada() && is_string($uuid) && $uuid !== '' && PedidosDaIntegracao::ehDaIntegracao($uuid)) {
                EnviarAvisoDaIntegracao::enfileirar($uuid);
            }
        } catch (\Throwable $e) {
            Log::warning('[entregas] integração: falha ao enfileirar o aviso do pedido', ['erro' => get_class($e)]);
        }
    }

    /** "loja" se quem está na sessão é usuário de loja (portal), senão "central". */
    protected static function quemCancelou(): string
    {
        try {
            $usuario = session('user');
            if (!$usuario) {
                return 'central';
            }

            return User::where('uuid', $usuario)->first()?->type === 'customer' ? 'loja' : 'central';
        } catch (\Throwable $e) {
            return 'central';
        }
    }
}
```

- [ ] **Step 7: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-avisos.php`
Expected: `FALHAS: 0`.

- [ ] **Step 8: Commit**

```bash
git add api/app/Support/Entregas/Integracao/FalhaNoAviso.php api/app/Support/Entregas/Integracao/AvisosDaIntegracao.php api/app/Jobs/Entregas/EnviarAvisoDaIntegracao.php api/app/Listeners/Entregas/ObservadorDosPedidosDaIntegracao.php scripts/teste-php/integracao-avisos.php
git commit -m "Integração: avisos de volta por webhook assinado

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Rotas externas e do console (controllers e `PainelDaIntegracao`)

**Files:**
- Create: `api/app/Support/Entregas/Integracao/PainelDaIntegracao.php`
- Create: `api/app/Http/Controllers/Entregas/IntegracaoController.php`
- Create: `api/app/Http/Controllers/Entregas/IntegracaoLojasController.php`
- Modify: `api/app/Providers/RouteServiceProvider.php`
- Test: `scripts/teste-php/integracao-rotas.php`

- [ ] **Step 1: Escrever o teste**

`scripts/teste-php/integracao-rotas.php`:

```php
<?php

// Integração: as rotas externas (IntegracaoController: conexão, criar, consultar, cancelar) e o que o console mostra
// (PainelDaIntegracao).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-rotas.php

require __DIR__ . '/stubs-integracao.php';

use App\Http\Controllers\Entregas\IntegracaoController;
use App\Support\Entregas\Integracao\CancelamentoPelaOrigem;
use App\Support\Entregas\Integracao\ChavesDeIntegracao;
use App\Support\Entregas\Integracao\CriadorDoPedidoDaIntegracao;
use App\Support\Entregas\Integracao\PainelDaIntegracao;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Http\Request;
use Teste\Trava;

function pedido(array $dados = [], ?string $chave = CHAVE_DA_LOJA_A): Request
{
    $request         = new Request($dados);
    $request->token  = $chave;
    $request->metodo = 'POST';

    return $request;
}

function controlador(): IntegracaoController
{
    return new IntegracaoController();
}

function criarPelaRota(array $mudancas = []): \Teste\RespostaJson
{
    return controlador()->criar(pedido(corpoDoPedido($mudancas)), app(CriadorDoPedidoDaIntegracao::class));
}

echo '== Autenticação' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
\Teste\Config::$valores['services.entregas.integracao'] = '';
$r = criarPelaRota();
confere($r->status === 409 && $r->dados === ['errors' => ['Integração desligada.']], 'desligada: 409');
\Teste\Config::$valores['services.entregas.integracao'] = '1';
foreach ([null, 'flb_live_xyz', 'ent_live_' . str_repeat('B', 40)] as $chave) {
    $r = controlador()->criar(pedido(corpoDoPedido(), $chave), app(CriadorDoPedidoDaIntegracao::class));
    confere($r->status === 401 && $r->dados === ['errors' => ['Chave de integração inválida ou revogada.']], 'chave inválida: 401 (' . var_export($chave, true) . ')');
}
ChavesDeIntegracao::revogar('empresa-1', 'vendor-a');
confere(criarPelaRota()->status === 401, 'chave revogada: 401');
reiniciarIntegracao();
lojaIntegradaA();
\Fleetbase\FleetOps\Models\Vendor::$todos = [];
$r = criarPelaRota();
confere($r->status === 409 && $r->dados['errors'] === ['Loja não encontrada no Entregas. Fale com a central.'], 'loja apagada: 409');

echo '== Conexão' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA(false);
$r = controlador()->conexao(pedido(['sistema' => 'RestaurantePro', 'webhook_url' => 'http://inseguro.com.br/x']));
confere($r->status === 422 && str_contains($r->dados['errors'][0], 'webhook_url recusada'), 'URL http: 422');
$r = controlador()->conexao(pedido(['sistema' => '', 'webhook_url' => 'https://api.restaurantepro.com.br/x']));
confere($r->status === 422 && str_contains($r->dados['errors'][0], 'sistema'), 'sem sistema: 422');
$r = controlador()->conexao(pedido(['sistema' => 'RestaurantePro', 'webhook_url' => 'https://200.150.10.20/functions/v1/entregas-webhook?t=abc']));
confere($r->status === 200 && $r->dados['loja'] === ['nome' => 'Pizzaria Ficticia'] && preg_match('/^[0-9a-f]{64}$/', $r->dados['webhook_segredo']) === 1, 'conectada: nome da loja e o segredo');
$loja = ChavesDeIntegracao::ativa('empresa-1', 'vendor-a');
confere($loja->webhook_url === 'https://200.150.10.20/functions/v1/entregas-webhook?t=abc' && ChavesDeIntegracao::segredo($loja) === $r->dados['webhook_segredo'] && $loja->ultimo_uso_em === '2026-10-05 15:00:00', 'URL, segredo e uso gravados');

echo '== Criar' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
$r = criarPelaRota(['cobranca' => ['forma' => 'cheque'], 'numero' => null]);
confere($r->status === 422 && count($r->dados['errors']) === 2 && Order::$criados === [], 'corpo inválido: 422 com todos os erros, nada criado');
$r = criarPelaRota();
confere($r->status === 201 && $r->dados['id'] === 'order_1' && $r->dados['pedido_externo_id'] === 'ord-6f1c' && $r->dados['status'] === 'dispatched', '201 com o pedido');
confere(array_key_exists('codigo_rastreio', $r->dados) && $r->dados['motoboy'] === null && $r->dados['cancelamento'] === null && $r->dados['sem_localizacao'] === false, 'campos do recurso');
confere(\Teste\Sessao::$dados['company'] === 'empresa-1', 'empresa da chave na sessão');
$r = criarPelaRota(['numero' => '1111']);
confere($r->status === 200 && $r->dados['id'] === 'order_1' && count(Order::$criados) === 1, 'repetido: 200 com o mesmo pedido');
reiniciarIntegracao();
lojaIntegradaA();
\Fleetbase\FleetOps\Models\Vendor::$todos[0]->place_uuid = null;
$r = criarPelaRota();
confere($r->status === 409 && str_contains($r->dados['errors'][0], 'local de coleta'), 'loja sem coleta: 409');

echo '== Consultar' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
criarPelaRota();
$pedidoCriado = Order::$criados[0];
$pedidoCriado->tracking = 'RP0001';
$r = controlador()->consultar(pedido(), 'ord-6f1c');
confere($r->status === 200 && $r->dados['codigo_rastreio'] === 'RP0001' && $r->dados['link_rastreio'] === 'https://entregas.restaurantepro.com.br/~/track-order?order=RP0001', 'pelo id da origem, com o rastreio');
$pedidoCriado->started        = true;
$pedidoCriado->driverAssigned = new Driver(['uuid' => 'driver-1', 'name' => 'Motoboy Ficticio', 'photo_url' => null]);
confere(controlador()->consultar(pedido(), 'order_1')->dados['motoboy'] === ['nome' => 'Motoboy Ficticio', 'foto_url' => null], 'pelo public_id, com o motoboy depois do aceite');
confere(controlador()->consultar(pedido(), 'ord-outro')->status === 404, 'desconhecido: 404');
\Teste\Banco::inserir('entregas_integracao_pedidos', ['company_uuid' => 'empresa-1', 'vendor_uuid' => 'vendor-b', 'order_uuid' => 'order-b', 'pedido_externo_id' => 'ord-b', 'cobranca_forma' => 'pix'], false);
confere(controlador()->consultar(pedido(), 'ord-b')->status === 404, 'pedido de outra loja: 404');

echo '== Cancelar' . PHP_EOL;
$r = controlador()->cancelar(pedido(), 'ord-6f1c', new CancelamentoPelaOrigem());
confere($r->status === 200 && $r->dados['resultado'] === 'cancelado' && $r->dados['cancelamento'] === ['por' => 'loja', 'pago' => false], 'cancelado, com o recurso atualizado');
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao(['status' => 'completed']);
$r = controlador()->cancelar(pedido(), 'ord-1', new CancelamentoPelaOrigem());
confere($r->status === 409 && $r->dados['resultado'] === 'ja_concluido' && $r->dados['errors'] === ['O pedido já foi concluído.'], 'concluído: 409');
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao();
Trava::$ocupadas['entregas:pedido:order-1'] = true;
$r = controlador()->cancelar(pedido(), 'ord-1', new CancelamentoPelaOrigem());
confere($r->status === 503, 'trava ocupada: 503');
confere(controlador()->cancelar(pedido(), 'ord-x', new CancelamentoPelaOrigem())->status === 404, 'desconhecido: 404');

echo '== Painel do console' . PHP_EOL;
reiniciarIntegracao();
confere(PainelDaIntegracao::daLoja(null) === ['ligada' => true, 'ativa' => false, 'chave_final' => null, 'sistema' => null, 'conectada' => false, 'webhook_host' => null, 'gerada_em' => null, 'conectada_em' => null, 'ultimo_uso_em' => null, 'ultima_falha_aviso' => null, 'ultima_falha_aviso_em' => null], 'sem chave');
$loja = lojaIntegradaA();
$p    = PainelDaIntegracao::daLoja($loja);
confere($p['ativa'] && $p['chave_final'] === 'AAAA' && $p['sistema'] === 'RestaurantePro' && $p['conectada'] && $p['webhook_host'] === 'api.restaurantepro.com.br', 'conectada: final da chave, sistema e só o host do webhook');
confere($p['gerada_em'] === '2026-10-05T08:00:00-03:00' && $p['conectada_em'] === '2026-10-05T09:00:00-03:00', 'datas em ISO -03:00');
confere(!str_contains(json_encode($p), 'segredo') && !str_contains(json_encode($p), '?t=abc'), 'sem segredo nem o token da URL');
confere(PainelDaIntegracao::doPedido(null, null) === ['integracao' => false], 'pedido que não é da integração');
pedidoDaIntegracao(['status' => 'canceled'], ['cancelado_em' => '2026-10-05 14:58:00', 'cancelado_por' => 'loja', 'pago_mesmo_cancelado' => true, 'ultimo_aviso' => 'cancelado']);
$p = PainelDaIntegracao::doPedido(linhaDaIntegracao(), $loja);
confere($p['integracao'] === true && $p['sistema'] === 'RestaurantePro' && $p['pedido_externo_id'] === 'ord-1' && $p['numero'] === '4821', 'ids');
confere($p['cobranca'] === ['forma' => 'dinheiro', 'centavos' => 5890, 'troco_para_centavos' => 10000, 'texto' => 'Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100'], 'cobrança');
confere($p['ultimo_aviso'] === 'cancelado' && $p['cancelado_por'] === 'loja' && $p['pago_mesmo_cancelado'] === true && $p['cancelado_em'] === '2026-10-05T14:58:00-03:00', 'avisos e cancelamento');
confere(!array_key_exists('cliente_telefone', $p) && !str_contains(json_encode($p), '16999990000'), 'sem o telefone do cliente');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-rotas.php`
Expected: erro `Class "App\Http\Controllers\Entregas\IntegracaoController" not found`.

- [ ] **Step 3: `PainelDaIntegracao`**

`api/app/Support/Entregas/Integracao/PainelDaIntegracao.php`:

```php
<?php

namespace App\Support\Entregas\Integracao;

use Illuminate\Support\Carbon;

/**
 * Entregas RestaurantePro: o que o console mostra da integração (funções puras). A tela Lojas (daLoja): se há chave
 * ativa, o final dela, o sistema conectado, só o host do webhook (a URL tem o token do destino), as datas e a última
 * falha de aviso. O painel do pedido (doPedido): sistema, ids, cobrança, último aviso, cancelamento. Nunca o segredo, a
 * chave ou o telefone do cliente. Datas em ISO no fuso do app.
 */
final class PainelDaIntegracao
{
    public static function daLoja(?object $loja): array
    {
        $host = $loja && $loja->webhook_url ? parse_url((string) $loja->webhook_url, PHP_URL_HOST) : null;

        return [
            'ligada'                => Integracao::ligada(),
            'ativa'                 => $loja !== null,
            'chave_final'           => $loja->chave_final ?? null,
            'sistema'               => $loja->sistema ?? null,
            'conectada'             => (bool) ($loja->conectada_em ?? null),
            'webhook_host'          => $host ?: null,
            'gerada_em'             => static::iso($loja->created_at ?? null),
            'conectada_em'          => static::iso($loja->conectada_em ?? null),
            'ultimo_uso_em'         => static::iso($loja->ultimo_uso_em ?? null),
            'ultima_falha_aviso'    => $loja->ultima_falha_aviso ?? null,
            'ultima_falha_aviso_em' => static::iso($loja->ultima_falha_aviso_em ?? null),
        ];
    }

    public static function doPedido(?object $linha, ?object $loja): array
    {
        if (!$linha) {
            return ['integracao' => false];
        }

        $centavos = (int) $linha->cobranca_centavos;
        $troco    = $linha->troco_para_centavos !== null ? (int) $linha->troco_para_centavos : null;

        return [
            'integracao'            => true,
            'sistema'               => $linha->sistema,
            'pedido_externo_id'     => $linha->pedido_externo_id,
            'numero'                => $linha->numero,
            'cobranca'              => [
                'forma'               => CobrancaDaIntegracao::forma($linha->cobranca_forma),
                'centavos'            => $centavos,
                'troco_para_centavos' => $troco,
                'texto'               => CobrancaDaIntegracao::texto($linha->cobranca_forma, $centavos, $troco),
            ],
            'observacoes'           => $linha->observacoes,
            'sem_localizacao'       => (bool) $linha->sem_localizacao,
            'despachado_em'         => static::iso($linha->despachado_em),
            'ultimo_aviso'          => $linha->ultimo_aviso,
            'cancelado_em'          => static::iso($linha->cancelado_em),
            'cancelado_por'         => $linha->cancelado_por,
            'pago_mesmo_cancelado'  => (bool) $linha->pago_mesmo_cancelado,
            'ultima_falha_aviso'    => $loja->ultima_falha_aviso ?? null,
            'ultima_falha_aviso_em' => static::iso($loja->ultima_falha_aviso_em ?? null),
        ];
    }

    /** Texto do banco (fuso do app, sem fuso) → ISO com o fuso; null fica null. */
    public static function iso(?string $texto): ?string
    {
        return $texto ? Carbon::parse(substr($texto, 0, 19), date_default_timezone_get())->toIso8601String() : null;
    }
}
```

- [ ] **Step 4: `IntegracaoController`**

`api/app/Http/Controllers/Entregas/IntegracaoController.php`:

```php
<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\Integracao\CancelamentoPelaOrigem;
use App\Support\Entregas\Integracao\ChavesDeIntegracao;
use App\Support\Entregas\Integracao\CriadorDoPedidoDaIntegracao;
use App\Support\Entregas\Integracao\ErroDaIntegracao;
use App\Support\Entregas\Integracao\Integracao;
use App\Support\Entregas\Integracao\PedidoDaIntegracao;
use App\Support\Entregas\Integracao\PedidosDaIntegracao;
use App\Support\Entregas\Integracao\UrlDoWebhook;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vendor;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: a API dos sistemas de origem (v1/entregas/integracao/*), autenticada pela chave da loja no
 * Bearer (ChavesDeIntegracao), fora do grupo fleetbase.api (que exige a chave do Fleetbase). A chave só enxerga os
 * pedidos da loja dela. Erros sempre {"errors": ["…"]}: 401 (chave), 409 (desligada, loja sem coleta ou apagada,
 * concluído), 422 (validação), 404, 503 (trava ocupada). Contrato: spec 2026-10-12-integracao-restaurantepro-design.md.
 *
 * - conexao: registra o sistema e a URL dos avisos; devolve o nome da loja e o segredo (só aqui);
 * - criar: 201 com o pedido novo; 200 com o mesmo pedido quando o pedido_externo_id repete;
 * - consultar: status, motoboy (depois do aceite), código e link de rastreio, cancelamento;
 * - cancelar: cancelado | cancelado_pago, ou 409 ja_concluido.
 */
class IntegracaoController extends Controller
{
    public function conexao(Request $request)
    {
        [$loja, $erro] = $this->loja($request);
        if ($erro) {
            return $erro;
        }

        $sistema = $request->input('sistema');
        if (!is_string($sistema) || trim($sistema) === '' || mb_strlen(trim($sistema)) > 40) {
            return response()->json(['errors' => ['sistema é obrigatório (até 40 caracteres).']], 422);
        }
        $url    = $request->input('webhook_url');
        $motivo = is_string($url) ? UrlDoWebhook::recusa(trim($url)) : 'informe a URL';
        if ($motivo !== null) {
            return response()->json(['errors' => ['webhook_url recusada: ' . $motivo . '.']], 422);
        }

        $segredo = ChavesDeIntegracao::conectar($loja, $sistema, trim($url));
        $vendor  = Vendor::where('uuid', $loja->vendor_uuid)->first();

        return response()->json(['loja' => ['nome' => $vendor?->name], 'webhook_segredo' => $segredo]);
    }

    public function criar(Request $request, CriadorDoPedidoDaIntegracao $criador)
    {
        [$loja, $erro] = $this->loja($request);
        if ($erro) {
            return $erro;
        }

        $validacao = PedidoDaIntegracao::validar((array) $request->all());
        if ($validacao['erros']) {
            return response()->json(['errors' => $validacao['erros']], 422);
        }

        try {
            [$linha, $novo] = $criador->criar($loja, $validacao['dados']);
        } catch (ErroDaIntegracao $e) {
            return response()->json(['errors' => [$e->getMessage()]], $e->status);
        }

        return response()->json(static::recurso($linha, $this->order($linha)), $novo ? 201 : 200);
    }

    public function consultar(Request $request, string $id)
    {
        [$loja, $erro] = $this->loja($request);
        if ($erro) {
            return $erro;
        }

        $linha = PedidosDaIntegracao::daLoja($loja, $id);
        if (!$linha) {
            return response()->json(['errors' => ['Pedido não encontrado.']], 404);
        }

        return response()->json(static::recurso($linha, $this->order($linha)));
    }

    public function cancelar(Request $request, string $id, CancelamentoPelaOrigem $cancelamento)
    {
        [$loja, $erro] = $this->loja($request);
        if ($erro) {
            return $erro;
        }

        $linha = PedidosDaIntegracao::daLoja($loja, $id);
        if (!$linha) {
            return response()->json(['errors' => ['Pedido não encontrado.']], 404);
        }

        try {
            $resultado = $cancelamento->cancelar($linha);
        } catch (LockTimeoutException $e) {
            return response()->json(['errors' => ['O pedido está sendo atualizado. Tente de novo em instantes.']], 503);
        }

        if ($resultado === CancelamentoPelaOrigem::JA_CONCLUIDO) {
            return response()->json(['resultado' => $resultado, 'errors' => ['O pedido já foi concluído.']], 409);
        }

        $linha = PedidosDaIntegracao::doPedido((string) $linha->order_uuid) ?? $linha;

        return response()->json(['resultado' => $resultado] + static::recurso($linha, $this->order($linha)));
    }

    /** O pedido como a origem vê: ids, status, rastreio, motoboy (só depois do aceite) e cancelamento. */
    public static function recurso(object $linha, ?object $pedido): array
    {
        $codigo  = $pedido ? ($pedido->tracking ?? null) : null;
        $motoboy = $pedido && $pedido->started ? ($pedido->driverAssigned ?? null) : null;

        return [
            'id'                => $pedido?->public_id,
            'pedido_externo_id' => $linha->pedido_externo_id,
            'status'            => $pedido?->status,
            'codigo_rastreio'   => $codigo,
            'link_rastreio'     => Integracao::linkDeRastreio($codigo),
            'motoboy'           => $motoboy ? ['nome' => $motoboy->name, 'foto_url' => $motoboy->photo_url ?? null] : null,
            'sem_localizacao'   => (bool) $linha->sem_localizacao,
            'cancelamento'      => $linha->cancelado_em ? ['por' => $linha->cancelado_por, 'pago' => (bool) $linha->pago_mesmo_cancelado] : null,
        ];
    }

    /** @return array{0: ?object, 1: mixed} [a loja da chave, null] ou [null, a resposta de erro] */
    protected function loja(Request $request): array
    {
        if (!Integracao::ligada()) {
            return [null, response()->json(['errors' => ['Integração desligada.']], 409)];
        }

        $loja = ChavesDeIntegracao::daChave($request->bearerToken());
        if (!$loja) {
            return [null, response()->json(['errors' => ['Chave de integração inválida ou revogada.']], 401)];
        }

        $vendor = Vendor::where('uuid', $loja->vendor_uuid)->where('company_uuid', $loja->company_uuid)->first();
        if (!$vendor) {
            return [null, response()->json(['errors' => ['Loja não encontrada no Entregas. Fale com a central.']], 409)];
        }

        session(['company' => $loja->company_uuid]);
        ChavesDeIntegracao::marcarUso($loja);

        return [$loja, null];
    }

    protected function order(?object $linha): ?Order
    {
        return $linha && $linha->order_uuid
            ? Order::where('uuid', $linha->order_uuid)->with(['trackingNumber', 'driverAssigned.user.avatar'])->first()
            : null;
    }
}
```

- [ ] **Step 5: `IntegracaoLojasController`**

`api/app/Http/Controllers/Entregas/IntegracaoLojasController.php`:

```php
<?php

namespace App\Http\Controllers\Entregas;

use App\Support\Entregas\Integracao\ChavesDeIntegracao;
use App\Support\Entregas\Integracao\Integracao;
use App\Support\Entregas\Integracao\PainelDaIntegracao;
use App\Support\Entregas\Integracao\PedidosDaIntegracao;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: a integração no console (só admin): o bloco "Integração" do card da loja (Fleet-Ops → Lojas)
 * e o painel "Integração" no detalhe do pedido.
 * - situacao (GET int/v1/entregas/lojas/{id}/integracao);
 * - gerarChave (POST .../integracao/chave): a chave em texto, uma vez; revoga a anterior. 409 com a integração desligada;
 * - revogar (DELETE .../integracao): funciona sempre;
 * - painel (GET int/v1/entregas/pedidos/{id}/integracao): {"integracao": false} se o pedido não é da integração.
 */
class IntegracaoLojasController extends LojasController
{
    public function situacao(Request $request, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $vendor = $this->acharLoja($id);

        return response()->json(['integracao' => PainelDaIntegracao::daLoja(ChavesDeIntegracao::ativa((string) session('company'), (string) $vendor->uuid))]);
    }

    public function gerarChave(Request $request, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        if (!Integracao::ligada()) {
            return response()->json(['errors' => ['Integração desligada.']], 409);
        }
        $vendor = $this->acharLoja($id);
        $chave  = ChavesDeIntegracao::gerar((string) session('company'), (string) $vendor->uuid);

        return response()->json(['chave' => $chave, 'integracao' => PainelDaIntegracao::daLoja(ChavesDeIntegracao::ativa((string) session('company'), (string) $vendor->uuid))]);
    }

    public function revogar(Request $request, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $vendor = $this->acharLoja($id);
        ChavesDeIntegracao::revogar((string) session('company'), (string) $vendor->uuid);

        return response()->json(['integracao' => PainelDaIntegracao::daLoja(null)]);
    }

    public function painel(Request $request, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }

        // nenhum model registra o CompanyScope nesta versão: a empresa é filtrada aqui
        $pedido = Order::where('company_uuid', session('company'))->where(fn ($q) => $q->where('public_id', $id)->orWhere('uuid', $id))->first();
        if (!$pedido) {
            return response()->json(['errors' => ['Pedido não encontrado.']], 404);
        }

        $linha = PedidosDaIntegracao::doPedido((string) $pedido->uuid);
        $loja  = $linha ? ChavesDeIntegracao::ativa((string) $linha->company_uuid, (string) $linha->vendor_uuid) : null;

        return response()->json(PainelDaIntegracao::doPedido($linha, $loja));
    }
}
```

- [ ] **Step 6: Rotas e limitador**

Em `api/app/Providers/RouteServiceProvider.php`:

1. Nos `use` do topo, depois de `use App\Http\Controllers\Entregas\IfoodPedidosController;`, acrescente:

```php
use App\Http\Controllers\Entregas\IntegracaoController;
use App\Http\Controllers\Entregas\IntegracaoLojasController;
```

2. Depois do `RateLimiter::for('entregas-enderecos', ...)`, acrescente:

```php
        // integração de pedidos de outros sistemas (RestaurantePro): até 60 chamadas por minuto por chave (o hash do Bearer;
        // sem Bearer, o IP). As rotas ficam fora do fleetbase.api: a chave da loja é conferida no IntegracaoController
        RateLimiter::for('entregas-integracao', fn (Request $request) => Limit::perMinute(60)->by('entregas-integracao:' . hash('sha256', (string) ($request->bearerToken() ?: $request->ip()))));

        // dados do pedido da integração no app (card de aceitar e detalhes), num balde próprio por motoboy
        RateLimiter::for('entregas-motoboy-integracao', fn (Request $request) => Limit::perMinute(120)->by('entregas-motoboy-integracao:' . (session('user') ?: $request->ip())));
```

3. Dentro do grupo `Route::prefix('int/v1/entregas')`, depois da linha `Route::delete('lojas/{id}/ifood', [IfoodLojasController::class, 'desvincular']);`, acrescente:

```php
                        // integração de pedidos de outros sistemas: a chave da loja (gerar, revogar, situação)
                        Route::get('lojas/{id}/integracao', [IntegracaoLojasController::class, 'situacao']);
                        Route::post('lojas/{id}/integracao/chave', [IntegracaoLojasController::class, 'gerarChave']);
                        Route::delete('lojas/{id}/integracao', [IntegracaoLojasController::class, 'revogar']);
```

e, depois da linha `Route::post('pedidos/{id}/ifood/liberar-sem-codigo', [IfoodPedidosController::class, 'liberarSemCodigo']);`, acrescente:

```php
                        // painel "Integração" no detalhe do pedido
                        Route::get('pedidos/{id}/integracao', [IntegracaoLojasController::class, 'painel']);
```

4. No fim do `$this->routes(function () { ... })`, depois do último grupo (`v1/entregas/motoboy` com `throttle:entregas-motoboy-ifood`), acrescente:

```php
                // Entregas RestaurantePro: pedidos de outros sistemas (RestaurantePro), com a chave da loja (IntegracaoController)
                Route::prefix('v1/entregas/integracao')
                    ->middleware(['throttle:entregas-integracao'])
                    ->group(function () {
                        Route::post('conexao', [IntegracaoController::class, 'conexao']);
                        Route::post('pedidos', [IntegracaoController::class, 'criar']);
                        Route::get('pedidos/{id}', [IntegracaoController::class, 'consultar']);
                        Route::post('pedidos/{id}/cancelar', [IntegracaoController::class, 'cancelar']);
                    });
```

- [ ] **Step 7: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-rotas.php`
Expected: `FALHAS: 0`.

Sintaxe dos arquivos que o php-wasm não carrega nos testes:

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Http/Controllers/Entregas/IntegracaoLojasController.php api/app/Providers/RouteServiceProvider.php`
Expected: sem erro (exit 0).

- [ ] **Step 8: Commit**

```bash
git add api/app/Support/Entregas/Integracao/PainelDaIntegracao.php api/app/Http/Controllers/Entregas/IntegracaoController.php api/app/Http/Controllers/Entregas/IntegracaoLojasController.php api/app/Providers/RouteServiceProvider.php scripts/teste-php/integracao-rotas.php
git commit -m "Integração: rotas externas com a chave da loja e rotas do console

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Reconciliação (`entregas:integracao-acompanhar`)

**Files:**
- Create: `api/app/Console/Commands/Entregas/AcompanharIntegracao.php`
- Modify: `api/app/Console/Kernel.php`
- Test: `scripts/teste-php/integracao-acompanhar.php`

- [ ] **Step 1: Escrever o teste**

`scripts/teste-php/integracao-acompanhar.php`:

```php
<?php

// Integração: o entregas:integracao-acompanhar (AcompanharIntegracao): refaz o despacho que falhou e enfileira os avisos
// que faltam.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-acompanhar.php

require __DIR__ . '/stubs-integracao.php';

use App\Console\Commands\Entregas\AcompanharIntegracao;
use App\Support\Entregas\Integracao\ChavesDeIntegracao;
use App\Support\Entregas\Integracao\CriadorDoPedidoDaIntegracao;
use Teste\Fila;

function acompanhar(): int
{
    return (new AcompanharIntegracao())->handle(app(CriadorDoPedidoDaIntegracao::class));
}

echo '== Aviso que falta: enfileira' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao();
confere(acompanhar() === 0 && count(Fila::$jobs) === 1 && Fila::$jobs[0]->orderUuid === 'order-1', 'aceito sem aviso: um job');
Fila::$jobs = [];
confere(acompanhar() === 0 && Fila::$jobs === [], 'job ainda pendente (marca no cache): não enfileira outro');

echo '== Nada a avisar' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao([], ['ultimo_aviso' => 'aceito', 'motoboy_avisado' => 'driver-1']);
acompanhar();
confere(Fila::$jobs === [], 'já avisado: nada');
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao(['status' => 'completed'], ['ultimo_aviso' => 'concluido']);
acompanhar();
confere(Fila::$jobs === [], 'concluído e avisado: fora da consulta');
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao([], ['created_at' => '2026-10-04 14:00:00']);
acompanhar();
confere(Fila::$jobs === [], 'criado há mais de 24 h: fora');

echo '== Loja que não recebe' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA(false);
pedidoDaIntegracao();
acompanhar();
confere(Fila::$jobs === [], 'loja não conectada: não enfileira');
reiniciarIntegracao();
ChavesDeIntegracao::registrarFalhaDeAviso(lojaIntegradaA(), 'o sistema de origem respondeu 500');
pedidoDaIntegracao();
acompanhar();
confere(Fila::$jobs === [], 'falha de aviso há menos de 5 min: espera');
\Teste\Relogio::$agora = '2026-10-05 15:06:00';
acompanhar();
confere(count(Fila::$jobs) === 1, 'depois de 5 min: tenta de novo');

echo '== Despacho que falhou' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
$pedido = pedidoDaIntegracao(['status' => 'created', 'started' => false, 'dispatched' => false, 'driver_assigned_uuid' => null], ['despachado_em' => null, 'created_at' => '2026-10-05 14:58:00']);
acompanhar();
confere($pedido->chamadas === ['saveQuietly', 'firstDispatchWithActivity'] && $pedido->adhoc === true && linhaDaIntegracao()->despachado_em === '2026-10-05 15:00:00', 'despacha agora, como pedido aberto');
reiniciarIntegracao();
lojaIntegradaA();
$pedido = pedidoDaIntegracao(['status' => 'created', 'started' => false, 'dispatched' => false, 'driver_assigned_uuid' => null], ['despachado_em' => null, 'created_at' => '2026-10-05 14:59:30']);
acompanhar();
confere($pedido->chamadas === [], 'criado há menos de 1 min: espera o despacho do próprio pedido');
reiniciarIntegracao();
lojaIntegradaA();
$pedido = pedidoDaIntegracao(['status' => 'created', 'started' => false, 'dispatched' => false, 'driver_assigned_uuid' => null], ['despachado_em' => null, 'sem_localizacao' => true, 'created_at' => '2026-10-05 14:50:00']);
acompanhar();
confere($pedido->chamadas === [], 'sem localização: a central atribui, não despacha');
reiniciarIntegracao();
lojaIntegradaA();
$pedido = pedidoDaIntegracao(['status' => 'canceled', 'started' => false, 'dispatched' => false, 'driver_assigned_uuid' => null], ['despachado_em' => null, 'created_at' => '2026-10-05 14:50:00']);
acompanhar();
confere($pedido->chamadas === [], 'cancelado: não despacha');

echo '== Desligada' . PHP_EOL;
reiniciarIntegracao();
lojaIntegradaA();
pedidoDaIntegracao();
\Teste\Config::$valores['services.entregas.integracao'] = '';
confere(acompanhar() === 0 && Fila::$jobs === [], 'nada');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-acompanhar.php`
Expected: erro `Class "App\Console\Commands\Entregas\AcompanharIntegracao" not found`.

- [ ] **Step 3: Implementar o comando**

`api/app/Console/Commands/Entregas/AcompanharIntegracao.php`:

```php
<?php

namespace App\Console\Commands\Entregas;

use App\Jobs\Entregas\EnviarAvisoDaIntegracao;
use App\Support\Entregas\Integracao\CriadorDoPedidoDaIntegracao;
use App\Support\Entregas\Integracao\Integracao;
use App\Support\Entregas\Integracao\SequenciaDaIntegracao;
use App\Support\Entregas\StatusDoPedido;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: reserva da integração de pedidos, a cada minuto (Kernel). Nos pedidos das últimas 24 h ainda
 * sem o aviso final:
 * - refaz o despacho que falhou no POST pedidos (trava ocupada, erro), passado 1 min da criação; o pedido sem
 *   localização fica com a central;
 * - enfileira o aviso que falta (o saveQuietly do Fleet-Ops, um job perdido com o Redis reiniciado), só para lojas
 *   conectadas e sem falha de aviso nos últimos 5 min (a falha esgotou as tentativas do job: espera antes de tentar de
 *   novo, em vez de bater no destino fora do ar a cada minuto).
 * Até 300 pedidos por rodada. Uma falha num pedido não para os outros (log `falha ao acompanhar o pedido`).
 */
class AcompanharIntegracao extends Command
{
    protected $signature = 'entregas:integracao-acompanhar';

    protected $description = 'Entregas: refaz o despacho que falhou e reenvia os avisos que faltam dos pedidos da integração';

    public const LIMITE = 300;

    public const JANELA_HORAS = 24;

    public const FOLGA_DO_DESPACHO_SEGUNDOS = 60;

    public const PAUSA_DEPOIS_DE_FALHA_MINUTOS = 5;

    public function handle(CriadorDoPedidoDaIntegracao $criador): int
    {
        if (!Integracao::ligada()) {
            return static::SUCCESS;
        }

        $linhas = DB::table(Integracao::PEDIDOS)
            ->where('created_at', '>=', now()->subHours(static::JANELA_HORAS)->toDateTimeString())
            ->whereNotNull('order_uuid')
            ->where(fn ($q) => $q->whereNull('ultimo_aviso')->orWhere('ultimo_aviso', SequenciaDaIntegracao::ACEITO)->orWhere('ultimo_aviso', SequenciaDaIntegracao::A_CAMINHO))
            ->orderBy('id')
            ->limit(static::LIMITE)
            ->get();
        if ($linhas->isEmpty()) {
            return static::SUCCESS;
        }

        $pedidos = [];
        foreach (Order::whereIn('uuid', $linhas->pluck('order_uuid')->all())->get() as $pedido) {
            $pedidos[$pedido->uuid] = $pedido;
        }
        $lojas = $this->lojasQueRecebem();

        foreach ($linhas as $linha) {
            $pedido = $pedidos[$linha->order_uuid] ?? null;
            if (!$pedido) {
                continue;
            }

            try {
                if ($this->despachoFalhou($linha, $pedido)) {
                    $criador->despachar($pedido);
                }
                if (isset($lojas[$linha->vendor_uuid]) && SequenciaDaIntegracao::doPedido($linha, $pedido) !== []) {
                    EnviarAvisoDaIntegracao::enfileirar((string) $pedido->uuid);
                }
            } catch (\Throwable $e) {
                Log::warning('[entregas] integração: falha ao acompanhar o pedido', ['pedido' => $pedido->public_id, 'erro' => get_class($e)]);
            }
        }

        return static::SUCCESS;
    }

    /** vendor_uuid => loja, das lojas com chave ativa, conectadas e sem falha de aviso recente. */
    protected function lojasQueRecebem(): array
    {
        $limite = now()->subMinutes(static::PAUSA_DEPOIS_DE_FALHA_MINUTOS)->toDateTimeString();
        $lojas  = [];
        foreach (DB::table(Integracao::LOJAS)->whereNull('revogada_em')->whereNotNull('conectada_em')->get() as $loja) {
            if ($loja->ultima_falha_aviso_em && substr((string) $loja->ultima_falha_aviso_em, 0, 19) > $limite) {
                continue;
            }
            $lojas[$loja->vendor_uuid] = $loja;
        }

        return $lojas;
    }

    protected function despachoFalhou(object $linha, Order $pedido): bool
    {
        if ($linha->sem_localizacao || $linha->despachado_em || $pedido->dispatched || $pedido->started || in_array($pedido->status, StatusDoPedido::ENCERRADOS, true)) {
            return false;
        }

        return substr((string) $linha->created_at, 0, 19) <= now()->subSeconds(static::FOLGA_DO_DESPACHO_SEGUNDOS)->toDateTimeString();
    }
}
```

- [ ] **Step 4: Agenda**

Em `api/app/Console/Kernel.php`:

1. Nos `use`, depois de `use App\Support\Entregas\Ifood\ClienteIfood;`, acrescente `use App\Support\Entregas\Integracao\Integracao;`.

2. No fim do `schedule()`, depois da linha do `entregas:distribuicao-varrer`, acrescente:

```php
        // integração de pedidos de outros sistemas: despacho que falhou e avisos que faltam (AcompanharIntegracao)
        $schedule->command('entregas:integracao-acompanhar')->everyMinute()->when(fn () => Integracao::ligada())->withoutOverlapping(5)->runInBackground()->appendOutputTo(static::SAIDA_DO_CONTAINER);
```

- [ ] **Step 5: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-acompanhar.php`
Expected: `FALHAS: 0`.

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Console/Kernel.php`
Expected: sem erro.

- [ ] **Step 6: Commit**

```bash
git add api/app/Console/Commands/Entregas/AcompanharIntegracao.php api/app/Console/Kernel.php scripts/teste-php/integracao-acompanhar.php
git commit -m "Integração: acompanhamento a cada minuto (despacho e avisos)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Ligações (provider, pagamento, push, alarme, app do motoboy, stack)

**Files:**
- Create: `api/app/Support/Entregas/Integracao/DadosDaIntegracaoDoMotoboy.php`
- Modify: `api/app/Providers/AppServiceProvider.php`
- Modify: `api/app/Support/Entregas/CalculoEntregas.php:59-61,97-110`
- Modify: `api/app/Notifications/Entregas/AvisosDoMotoboy.php:126,136-150`
- Modify: `api/app/Support/Entregas/CartaoDoAlarme.php`
- Modify: `api/app/Http/Controllers/Entregas/MotoboyController.php`
- Modify: `api/app/Providers/RouteServiceProvider.php` (rota do motoboy)
- Modify: `deploy/docker-stack.yml`, `deploy/stack.env.example`
- Modify: `scripts/teste-php/ifood-ligacoes.php:87`, `scripts/teste-php/imports-dos-providers.php`
- Test: `scripts/teste-php/integracao-ligacoes.php`

- [ ] **Step 1: Escrever o teste**

`scripts/teste-php/integracao-ligacoes.php`:

```php
<?php

// Integração: o que liga as peças no Laravel (providers, rotas, agenda, pagamento, push, alarme, stack), conferido no
// texto dos arquivos (os providers não sobem no php-wasm), e as funções puras do app do motoboy e do alarme.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-ligacoes.php

require __DIR__ . '/stubs-integracao.php';

use App\Support\Entregas\CartaoDoAlarme;
use App\Support\Entregas\CalculoEntregas;
use App\Support\Entregas\Integracao\DadosDaIntegracaoDoMotoboy;

$app     = file_get_contents('/repo/api/app/Providers/AppServiceProvider.php');
$rotas   = file_get_contents('/repo/api/app/Providers/RouteServiceProvider.php');
$kernel  = file_get_contents('/repo/api/app/Console/Kernel.php');
$calculo = file_get_contents('/repo/api/app/Support/Entregas/CalculoEntregas.php');
$avisos  = file_get_contents('/repo/api/app/Notifications/Entregas/AvisosDoMotoboy.php');
$motoboy = file_get_contents('/repo/api/app/Http/Controllers/Entregas/MotoboyController.php');
$stack   = file_get_contents('/repo/deploy/docker-stack.yml');
$modelo  = file_get_contents('/repo/deploy/stack.env.example');

echo '== AppServiceProvider' . PHP_EOL;
confere(str_contains($app, 'Order::updated(fn ($pedido) => ObservadorDosPedidosDaIntegracao::aoAtualizar($pedido));'), 'Order::updated → ObservadorDosPedidosDaIntegracao');
confere(str_contains($app, 'Event::listen(OrderDriverAssigned::class, fn ($evento) => ObservadorDosPedidosDaIntegracao::aoAtribuirMotoboy($evento));'), 'OrderDriverAssigned → ObservadorDosPedidosDaIntegracao');
confere(str_contains($app, 'use App\Listeners\Entregas\ObservadorDosPedidosDaIntegracao;') && str_contains($app, '$this->acompanharPedidosDaIntegracao();'), 'import e chamada no boot');

echo '== Rotas' . PHP_EOL;
foreach ([
    "Route::prefix('v1/entregas/integracao')",
    "->middleware(['throttle:entregas-integracao'])",
    "Route::post('conexao', [IntegracaoController::class, 'conexao']);",
    "Route::post('pedidos', [IntegracaoController::class, 'criar']);",
    "Route::get('pedidos/{id}', [IntegracaoController::class, 'consultar']);",
    "Route::post('pedidos/{id}/cancelar', [IntegracaoController::class, 'cancelar']);",
    "Route::get('lojas/{id}/integracao', [IntegracaoLojasController::class, 'situacao']);",
    "Route::post('lojas/{id}/integracao/chave', [IntegracaoLojasController::class, 'gerarChave']);",
    "Route::delete('lojas/{id}/integracao', [IntegracaoLojasController::class, 'revogar']);",
    "Route::get('pedidos/{id}/integracao', [IntegracaoLojasController::class, 'painel']);",
    "Route::get('pedidos/{id}/integracao', [MotoboyController::class, 'integracao']);",
    "RateLimiter::for('entregas-integracao'",
    "RateLimiter::for('entregas-motoboy-integracao'",
    "->middleware(['fleetbase.api', 'throttle:entregas-motoboy-integracao'])",
] as $trecho) {
    confere(str_contains($rotas, $trecho), "rota/limitador: {$trecho}");
}
$externas = substr($rotas, strpos($rotas, "Route::prefix('v1/entregas/integracao')"), 400);
confere(!str_contains($externas, 'fleetbase.api') && !str_contains($externas, 'fleetbase.protected'), 'as rotas externas ficam fora dos grupos do Fleetbase');
confere(str_contains($motoboy, 'public function integracao(Request $request, string $id)'), 'MotoboyController@integracao existe');

echo '== Agenda' . PHP_EOL;
confere(str_contains($kernel, "\$schedule->command('entregas:integracao-acompanhar')->everyMinute()->when(fn () => Integracao::ligada())->withoutOverlapping(5)->runInBackground()"), 'a cada minuto, em segundo plano, sem sobreposição');
confere(str_contains(file_get_contents('/repo/api/app/Console/Commands/Entregas/AcompanharIntegracao.php'), "'entregas:integracao-acompanhar'"), 'o comando tem a assinatura da agenda');

echo '== Pagamento e cobrança' . PHP_EOL;
confere(str_contains($calculo, "->leftJoin('entregas_integracao_pedidos as entregas_integracao', 'entregas_integracao.order_uuid', '=', 'orders.uuid')"), 'junta a linha da integração');
confere(str_contains($calculo, "where('entregas_integracao.pago_mesmo_cancelado', true)->whereNotNull('entregas_integracao.cancelado_em')"), 'pago mesmo cancelado pela origem entra');
confere(str_contains(CalculoEntregas::DATA_DA_ENTREGA, 'ELSE COALESCE(entregas_ifood.cancelado_pelo_ifood_em, entregas_integracao.cancelado_em) END'), 'conta pela data do cancelamento');

echo '== Push' . PHP_EOL;
confere(str_contains($avisos, 'static::canceladoPeloIfood($notificacao) ?? static::canceladoPelaOrigem($notificacao) ??'), 'OrderCanceled: o texto do cancelamento pela origem');
confere(str_contains($avisos, 'CancelamentoPelaOrigem::textoDoPush($linha)'), 'usa o CancelamentoPelaOrigem::textoDoPush');

echo '== Alarme' . PHP_EOL;
confere(CartaoDoAlarme::integracao(null) === [], 'sem linha: nada');
confere(CartaoDoAlarme::integracao((object) ['sistema' => 'RestaurantePro', 'numero' => '4821', 'cobranca_forma' => 'dinheiro', 'cobranca_centavos' => 5890, 'troco_para_centavos' => 10000]) === ['entregas_origem' => 'RestaurantePro #4821', 'entregas_cobrar' => 'Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100'], 'origem e cobrança');
confere(CartaoDoAlarme::integracao((object) ['sistema' => 'RestaurantePro', 'numero' => '4821', 'cobranca_forma' => 'pago', 'cobranca_centavos' => 0, 'troco_para_centavos' => null]) === ['entregas_origem' => 'RestaurantePro #4821'], 'pago: só a origem');

echo '== App do motoboy' . PHP_EOL;
$linha  = (object) ['sistema' => 'RestaurantePro', 'numero' => '4821', 'cobranca_forma' => 'dinheiro', 'cobranca_centavos' => 5890, 'troco_para_centavos' => 10000, 'observacoes' => 'Sem cebola', 'complemento' => 'Ap 12', 'referencia' => 'Perto da praça', 'cliente_telefone' => '16999990000', 'cancelado_em' => null, 'pago_mesmo_cancelado' => false];
$dados  = DadosDaIntegracaoDoMotoboy::resposta($linha, (object) ['status' => 'enroute', 'driver_assigned_uuid' => 'driver-1'], 'driver-1');
confere($dados['integracao'] === true && $dados['sistema'] === 'RestaurantePro' && $dados['numero'] === '4821', 'sistema e número');
confere($dados['cobranca'] === ['forma' => 'dinheiro', 'centavos' => 5890, 'troco_para_centavos' => 10000, 'texto' => 'Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100'], 'cobrança');
confere($dados['observacoes'] === 'Sem cebola' && $dados['complemento'] === 'Ap 12' && $dados['referencia'] === 'Perto da praça' && $dados['telefone'] === '16999990000', 'textos e telefone do motoboy do pedido');
confere(DadosDaIntegracaoDoMotoboy::resposta($linha, (object) ['status' => 'dispatched', 'driver_assigned_uuid' => null], 'driver-1')['telefone'] === null, 'pedido aberto: sem telefone');
confere(DadosDaIntegracaoDoMotoboy::resposta($linha, (object) ['status' => 'enroute', 'driver_assigned_uuid' => 'driver-2'], 'driver-1')['telefone'] === null, 'pedido de outro motoboy: sem telefone');
confere(DadosDaIntegracaoDoMotoboy::resposta($linha, (object) ['status' => 'completed', 'driver_assigned_uuid' => 'driver-1'], 'driver-1')['telefone'] === null, 'encerrado: sem telefone');
confere(DadosDaIntegracaoDoMotoboy::resposta(null, (object) ['status' => 'enroute', 'driver_assigned_uuid' => 'driver-1'], 'driver-1') === ['integracao' => false], 'não é da integração');

echo '== Stack' . PHP_EOL;
confere(str_contains($stack, 'ENTREGAS_INTEGRACAO: ${ENTREGAS_INTEGRACAO:-}') && str_contains($stack, 'ENTREGAS_CONSOLE_URL: ${ENTREGAS_CONSOLE_URL:-https://entregas.restaurantepro.com.br}'), 'as duas variáveis no x-api-env');
confere(str_contains($modelo, "\nENTREGAS_INTEGRACAO=\n"), 'no modelo do stack.env');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-ligacoes.php`
Expected: vários `FALHA` (nada ligado ainda) ou erro `CartaoDoAlarme::integracao` indefinido.

- [ ] **Step 3: `DadosDaIntegracaoDoMotoboy`**

`api/app/Support/Entregas/Integracao/DadosDaIntegracaoDoMotoboy.php`:

```php
<?php

namespace App\Support\Entregas\Integracao;

use App\Support\Entregas\StatusDoPedido;

/**
 * Entregas RestaurantePro: o que o app do motoboy recebe de um pedido de outro sistema (GET
 * v1/entregas/motoboy/pedidos/{id}/integracao: card de aceitar e detalhes). Função pura sobre a linha da
 * entregas_integracao_pedidos e o Order. Pedido que não é da integração: {"integracao": false}. O telefone do cliente
 * só vai ao motoboy do pedido, com o pedido em andamento (a regra do 0800 do iFood).
 */
final class DadosDaIntegracaoDoMotoboy
{
    public static function resposta(?object $linha, object $pedido, string $motoboyUuid): array
    {
        if (!$linha) {
            return ['integracao' => false];
        }

        $doMotoboy = (string) $pedido->driver_assigned_uuid === $motoboyUuid;
        $encerrado = in_array($pedido->status, StatusDoPedido::ENCERRADOS, true);
        $centavos  = (int) $linha->cobranca_centavos;
        $troco     = $linha->troco_para_centavos !== null ? (int) $linha->troco_para_centavos : null;

        return [
            'integracao'           => true,
            'sistema'              => $linha->sistema,
            'numero'               => $linha->numero !== null ? (string) $linha->numero : null,
            'cobranca'             => [
                'forma'               => CobrancaDaIntegracao::forma($linha->cobranca_forma),
                'centavos'            => $centavos,
                'troco_para_centavos' => $troco,
                'texto'               => CobrancaDaIntegracao::texto($linha->cobranca_forma, $centavos, $troco),
            ],
            'observacoes'          => $linha->observacoes,
            'complemento'          => $linha->complemento,
            'referencia'           => $linha->referencia,
            'cancelado'            => (bool) $linha->cancelado_em,
            'pago_mesmo_cancelado' => (bool) $linha->pago_mesmo_cancelado,
            'telefone'             => $doMotoboy && !$encerrado && $linha->cliente_telefone ? (string) $linha->cliente_telefone : null,
        ];
    }
}
```

- [ ] **Step 4: AppServiceProvider**

Em `api/app/Providers/AppServiceProvider.php`:

1. Nos `use`, depois de `use App\Listeners\Entregas\ObservadorDaDistribuicao;`, acrescente `use App\Listeners\Entregas\ObservadorDosPedidosDaIntegracao;`.
2. No `boot()`, depois de `$this->acompanharPedidosIfood();`, acrescente `$this->acompanharPedidosDaIntegracao();`.
3. Depois do método `acompanharPedidosIfood()`, acrescente:

```php
    /**
     * Entregas: mudança de um pedido da integração de outros sistemas (motoboy definido, iniciado, a caminho, concluído,
     * cancelado) → aviso de volta ao sistema de origem, na fila (ver ObservadorDosPedidosDaIntegracao). O resto, o
     * entregas:integracao-acompanhar reconcilia em até 1 min.
     */
    protected function acompanharPedidosDaIntegracao(): void
    {
        Order::updated(fn ($pedido) => ObservadorDosPedidosDaIntegracao::aoAtualizar($pedido));
        Event::listen(OrderDriverAssigned::class, fn ($evento) => ObservadorDosPedidosDaIntegracao::aoAtribuirMotoboy($evento));
    }
```

- [ ] **Step 5: CalculoEntregas (pago mesmo cancelado pela origem)**

Em `api/app/Support/Entregas/CalculoEntregas.php`:

1. Troque a constante `DATA_DA_ENTREGA` por:

```php
    public const DATA_DA_ENTREGA = "CASE WHEN orders.status = 'completed' THEN COALESCE(conclusoes.concluido_em, orders.updated_at) ELSE COALESCE(entregas_ifood.cancelado_pelo_ifood_em, entregas_integracao.cancelado_em) END";
```

e, no docblock dela, acrescente ao fim: ` O pedido de outro sistema cancelado pela origem depois do "A caminho" (entregas_integracao_pedidos) também conta pela data do cancelamento.`

2. No `pedidosConcluidos`, logo depois da linha `->leftJoin('entregas_ifood_pedidos as entregas_ifood', 'entregas_ifood.order_uuid', '=', 'orders.uuid')`, acrescente:

```php
            // a linha da integração de outros sistemas (uma por pedido, order_uuid único): o pago mesmo cancelado pela origem
            ->leftJoin('entregas_integracao_pedidos as entregas_integracao', 'entregas_integracao.order_uuid', '=', 'orders.uuid')
```

3. Troque o `->where(fn ($situacao) => ...)` por:

```php
            ->where(fn ($situacao) => $situacao->where('orders.status', 'completed')
                ->orWhere(fn ($pago) => $pago->where('entregas_ifood.pago_mesmo_cancelado', true)->whereNotNull('entregas_ifood.cancelado_pelo_ifood_em'))
                ->orWhere(fn ($pago) => $pago->where('entregas_integracao.pago_mesmo_cancelado', true)->whereNotNull('entregas_integracao.cancelado_em')))
```

4. No docblock do `pedidosConcluidos`, troque "e os pedidos iFood pagos mesmo cancelados" por "e os pedidos iFood e da integração pagos mesmo cancelados".

5. Em `scripts/teste-php/ifood-ligacoes.php`, linha 87, troque o trecho conferido `'ELSE entregas_ifood.cancelado_pelo_ifood_em END'` por `'ELSE COALESCE(entregas_ifood.cancelado_pelo_ifood_em, entregas_integracao.cancelado_em) END'`.

- [ ] **Step 6: Push do cancelamento pela origem**

Em `api/app/Notifications/Entregas/AvisosDoMotoboy.php`:

1. Nos `use`, junto dos do iFood, acrescente:

```php
use App\Support\Entregas\Integracao\CancelamentoPelaOrigem;
use App\Support\Entregas\Integracao\PedidosDaIntegracao;
```

2. Na linha do `OrderCanceled` do `match`, troque `static::canceladoPeloIfood($notificacao) ??` por `static::canceladoPeloIfood($notificacao) ?? static::canceladoPelaOrigem($notificacao) ??`.

3. Depois do método `canceladoPeloIfood()`, acrescente:

```php
    /**
     * Pedido de outro sistema cancelado pela loja (CancelamentoPelaOrigem): "Pedido #4821 cancelado pela loja", e "Você
     * recebe por esta entrega…" depois do "A caminho". null para os outros cancelamentos, ou se a consulta falhar.
     */
    protected static function canceladoPelaOrigem(Notification $notificacao): ?array
    {
        try {
            $uuid  = $notificacao->order->uuid ?? null;
            $linha = $uuid ? PedidosDaIntegracao::doPedido((string) $uuid) : null;

            return $linha ? CancelamentoPelaOrigem::textoDoPush($linha) : null;
        } catch (\Throwable) {
            return null;
        }
    }
```

- [ ] **Step 7: Cartão do alarme**

Em `api/app/Support/Entregas/CartaoDoAlarme.php`:

1. Nos `use`, acrescente:

```php
use App\Support\Entregas\Integracao\CobrancaDaIntegracao;
use App\Support\Entregas\Integracao\PedidosDaIntegracao;
```

2. No `doPedido`, troque `), static::ifoodDoPedido($pedido));` por `), static::ifoodDoPedido($pedido), static::integracaoDoPedido($pedido));`.

3. No docblock da classe, depois da linha do iFood, acrescente: ` * Pedido de outro sistema (RestaurantePro): entregas_origem ("RestaurantePro #4821") e entregas_cobrar (CobrancaDaIntegracao).`

4. Depois do método `ifoodDoPedido()`, acrescente:

```php
    /**
     * Pedido de outro sistema: entregas_origem ("RestaurantePro #4821", para o selo do cartão do APK novo) e
     * entregas_cobrar (só com valor a cobrar na porta). O APK atual ignora o entregas_origem.
     */
    public static function integracao(?object $linha): array
    {
        if (!$linha) {
            return [];
        }

        $numero = isset($linha->numero) && $linha->numero !== '' ? (string) $linha->numero : null;

        return array_filter([
            'entregas_origem' => $numero !== null ? ($linha->sistema ?: 'Integração') . ' #' . $numero : null,
            'entregas_cobrar' => CobrancaDaIntegracao::texto($linha->cobranca_forma ?? null, (int) ($linha->cobranca_centavos ?? 0), isset($linha->troco_para_centavos) ? (int) $linha->troco_para_centavos : null),
        ], fn ($valor) => $valor !== null);
    }

    /** Os dados da integração do pedido; uma falha na consulta não tira o resto do cartão. */
    protected static function integracaoDoPedido(Order $pedido): array
    {
        try {
            return static::integracao(PedidosDaIntegracao::doPedido((string) $pedido->uuid));
        } catch (\Throwable $e) {
            Log::warning('[entregas] alarme sem os dados da integração', ['pedido' => $pedido->public_id, 'erro' => get_class($e)]);

            return [];
        }
    }
```

- [ ] **Step 8: Rota do app do motoboy**

Em `api/app/Http/Controllers/Entregas/MotoboyController.php`:

1. Nos `use`, acrescente:

```php
use App\Support\Entregas\Integracao\DadosDaIntegracaoDoMotoboy;
use App\Support\Entregas\Integracao\PedidosDaIntegracao;
```

2. No docblock da classe, depois do item "ifood, concluirIfood e codigoIfood", acrescente: ` * - integracao: o pedido de outro sistema (RestaurantePro) no app: cobrança, observações e o telefone do cliente (DadosDaIntegracaoDoMotoboy).`

3. Depois do método `ifood()`, acrescente:

```php
    public function integracao(Request $request, string $id)
    {
        $motoboy = MotoboyDaSessao::motoboy($request);
        if (!$motoboy) {
            return $this->soParaMotoboy();
        }

        $pedido = $this->pedidoQuePodeVer($id, $motoboy);
        if (!$pedido) {
            return response()->json(['errors' => ['Pedido não encontrado.']], 404);
        }

        return response()->json(DadosDaIntegracaoDoMotoboy::resposta(PedidosDaIntegracao::doPedido((string) $pedido->uuid), $pedido, (string) $motoboy->uuid));
    }
```

Em `api/app/Providers/RouteServiceProvider.php`, depois do grupo `v1/entregas/motoboy` com `throttle:entregas-motoboy-ifood` (e antes do grupo `v1/entregas/integracao` da Task 9), acrescente:

```php
                // Entregas RestaurantePro: dados do pedido de outro sistema no app (card de aceitar e detalhes; MotoboyController@integracao)
                Route::prefix('v1/entregas/motoboy')
                    ->middleware(['fleetbase.api', 'throttle:entregas-motoboy-integracao'])
                    ->group(function () {
                        Route::get('pedidos/{id}/integracao', [MotoboyController::class, 'integracao']);
                    });
```

- [ ] **Step 9: Stack**

Em `deploy/docker-stack.yml`, depois da linha `  ENTREGAS_DISTRIBUICAO_RODADAS: ${ENTREGAS_DISTRIBUICAO_RODADAS:-}`, acrescente:

```yaml
  # integração de pedidos de outros sistemas (RestaurantePro): 1 liga as rotas v1/entregas/integracao/*, os avisos por
  # webhook e o entregas:integracao-acompanhar; vazio = as rotas respondem 409 (o RestaurantePro grava o erro no pedido)
  ENTREGAS_INTEGRACAO: ${ENTREGAS_INTEGRACAO:-}
  # domínio do console no link de rastreio que o sistema de origem manda ao cliente (…/~/track-order?order=<código>)
  ENTREGAS_CONSOLE_URL: ${ENTREGAS_CONSOLE_URL:-https://entregas.restaurantepro.com.br}
```

Em `deploy/stack.env.example`, depois da linha `ENTREGAS_DISTRIBUICAO_RODADAS=`, acrescente:

```
# Integração de pedidos de outros sistemas (RestaurantePro): 1 = as rotas v1/entregas/integracao/* aceitam pedidos com a
# chave da loja (gerada em Fleet-Ops → Lojas) e os avisos de status voltam por webhook. Vazio = tudo responde 409. Só
# chega aos containers com o docker-stack.yml novo colado no Portainer.
ENTREGAS_INTEGRACAO=
```

- [ ] **Step 10: Teste de imports**

Em `scripts/teste-php/imports-dos-providers.php`, no `array_merge(` dos `$arquivos`, depois de `glob($app . '/Listeners/Entregas/*.php'),`, acrescente `glob($app . '/Support/Entregas/Integracao/*.php'),` e, na lista entre colchetes, depois de `$app . '/Http/Controllers/Entregas/MotoboyController.php',`, acrescente:

```php
        // integração de pedidos de outros sistemas
        $app . '/Http/Controllers/Entregas/IntegracaoController.php',
        $app . '/Http/Controllers/Entregas/IntegracaoLojasController.php',
        $app . '/Jobs/Entregas/EnviarAvisoDaIntegracao.php',
        $app . '/Console/Commands/Entregas/AcompanharIntegracao.php',
```

- [ ] **Step 11: Rodar e ver passar**

```bash
for f in integracao-ligacoes ifood-ligacoes imports-dos-providers avisos-push cartao-do-alarme ganhos-motoboy fuso-relatorio; do echo "-- $f"; PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$f.php | tail -1; done
```
Expected: `FALHAS: 0` em todos.

```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Providers/AppServiceProvider.php api/app/Providers/RouteServiceProvider.php api/app/Support/Entregas/CalculoEntregas.php api/app/Notifications/Entregas/AvisosDoMotoboy.php api/app/Support/Entregas/CartaoDoAlarme.php api/app/Http/Controllers/Entregas/MotoboyController.php
```
Expected: sem erro.

- [ ] **Step 12: Commit**

```bash
git add api/app/Support/Entregas/Integracao/DadosDaIntegracaoDoMotoboy.php api/app/Providers/AppServiceProvider.php api/app/Support/Entregas/CalculoEntregas.php api/app/Notifications/Entregas/AvisosDoMotoboy.php api/app/Support/Entregas/CartaoDoAlarme.php api/app/Http/Controllers/Entregas/MotoboyController.php api/app/Providers/RouteServiceProvider.php deploy/docker-stack.yml deploy/stack.env.example scripts/teste-php/integracao-ligacoes.php scripts/teste-php/ifood-ligacoes.php scripts/teste-php/imports-dos-providers.php
git commit -m "Integração: observador, pagamento, push, alarme, rota do app e stack

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: Documentação e verificação final

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Seção no CLAUDE.md**

Em `CLAUDE.md`, logo antes da linha `## Marca Entregas RestaurantePro (sem Fleetbase na tela)`, acrescente:

```markdown
## Integração de pedidos de outros sistemas (RestaurantePro)

Decisão de 2026-10-12. Desenho: `docs/superpowers/specs/2026-10-12-integracao-restaurantepro-design.md`; planos `docs/superpowers/plans/2026-10-12-integracao-restaurantepro-api.md` (este servidor), `-console-e-app.md` e `-restaurantepro.md` (**o código é a referência**). Os pedidos de delivery do cardápio do RestaurantePro (`restaurantepro-main`, no molde da integração Foody de lá) entram no Entregas e vão à distribuição; o contrato serve para outros sistemas depois.

- **Chave por loja:** Fleet-Ops → Lojas → "Integração" → Gerar chave (só admin; `IntegracaoLojasController`, `int/v1/entregas/lojas/{id}/integracao*`). `ent_live_` + 40 caracteres, mostrada uma vez; o banco guarda só o SHA-256 e os 4 últimos (`entregas_integracao_lojas`). Gerar de novo revoga a anterior. A chave só cria, consulta e cancela pedidos da loja dela.
- **Interruptor:** `ENTREGAS_INTEGRACAO=1` (`services.entregas.integracao`, `Integracao::ligada()`, no `x-api-env`). Vazio: as rotas externas e o "Gerar chave" respondem 409, nenhum aviso sai e o comando não roda. `ENTREGAS_CONSOLE_URL` monta o link de rastreio.
- **Rotas externas** (`IntegracaoController`, `v1/entregas/integracao/*`, fora do `fleetbase.api`, limitador `entregas-integracao` de 60/min por chave): `POST conexao` (`{sistema, webhook_url}` → `{loja, webhook_segredo}`, o segredo só sai aqui), `POST pedidos`, `GET pedidos/{id}`, `POST pedidos/{id}/cancelar` (`{id}` = `pedido_externo_id` ou `order_…`). O `ProtegerPortalLoja` deixa passar: o Bearer `ent_live_` não é token Sanctum.
- **Criação** (`CriadorDoPedidoDaIntegracao`, molde do iFood): loja = Vendor da chave, coleta = Local da loja, entrega = Place novo nas coordenadas recebidas (sem o `createFromMixed`, que geocodifica sempre), notas "RestaurantePro #4821" + observações, despacho adhoc com a `TravaDoPedido` → distribuição. `pedido_externo_id` único por loja: o reenvio devolve o mesmo (200). Sem coordenadas: uma consulta ao Google Geocoding (`GeocodificadorDaIntegracao`, chave de Admin → Serviços). Ponto ausente, inválido ou a mais de 50 km: "[SEM LOCALIZAÇÃO]", sem despacho, a central atribui.
- **Dados fora do `meta`:** `entregas_integracao_pedidos` (telefone do cliente, cobrança em centavos, complemento, referência, observações, `ultimo_aviso`, cancelamento). O app lê em `GET v1/entregas/motoboy/pedidos/{id}/integracao` (telefone só para o motoboy do pedido em andamento); o console, em `GET int/v1/entregas/pedidos/{id}/integracao`.
- **Cancelamento pela origem** (`CancelamentoPelaOrigem`): sem aceite ou antes do "A caminho" = cancelado; "A caminho" = **pago mesmo cancelado** (entra no `CalculoEntregas` pela data do cancelamento, como o iFood; push "Pedido #4821 cancelado pela loja" + "Você recebe…"); concluído = 409. A central e o portal continuam cancelando (o observador grava `cancelado_por` = central | loja | sistema).
- **Avisos de volta** (`AvisosDaIntegracao`, job `EnviarAvisoDaIntegracao` na fila `default`, `ObservadorDosPedidosDaIntegracao` no `Order::updated` e no `OrderDriverAssigned`): `pedido.aceito` (com o motoboy; de novo na troca), `pedido.a_caminho` (com o código e o link de rastreio), `pedido.concluido`, `pedido.cancelado` (`por`, `pago`). POST com `X-Entregas-Evento`, `X-Entregas-Id` (estável por pedido, evento e motoboy) e `X-Entregas-Assinatura: sha256=<HMAC do corpo cru>`; 5 s, sem seguir redirecionamento; até 5 tentativas em 30 min; 410 = para. A URL só pode ser https pública na 443 (`UrlDoWebhook`, conferida na conexão e a cada envio).
- **`entregas:integracao-acompanhar`** (a cada minuto, `withoutOverlapping(5)`): refaz o despacho que falhou (1 min depois da criação) e enfileira o aviso que falta (lojas conectadas, 5 min de pausa depois de uma falha).
- **Logs:** prefixo `[entregas] integração:`, só ids: `chave gerada`, `chave revogada`, `conectada`, `pedido criado`, `pedido repetido`, `endereço geocodificado`, `pedido sem localização`, `pedido cancelado pela origem`, `aviso enviado`, `aviso não entregue` (`entregas_queue`), `webhook recusado`, `falha ao acompanhar o pedido` (`entregas_scheduler`).
- **Ao atualizar o fleetops-api, confira** o mesmo que no iFood: `Order::cancel()`, `firstDispatchWithActivity`, `OrderConfig::default()`, `Payload::setPickup`/`setDropoff`, o `OrderDriverAssigned` e o accessor `tracking` do Order (o código de rastreio dos avisos).
- **Testes:** `scripts/teste-php/integracao-*.php` (stubs em `stubs-integracao.php`).
- **Riscos aceitos:** o webhook confere o DNS antes do envio, mas um DNS que muda entre a conferência e o envio passa (rebinding); a chave vazada cria pedidos falsos na loja dela até ser revogada (tela Lojas).
```

E, na lista do `## Histórico (2026-10-01)`, no fim, acrescente:

```markdown
30. Integração de pedidos de outros sistemas, servidor (2026-10-12, ramo `integracao-restaurantepro`): chave por loja, `v1/entregas/integracao/*` (conexão, criar, consultar, cancelar), avisos de volta por webhook assinado e o acompanhamento a cada minuto, atrás de `ENTREGAS_INTEGRACAO` (ver "Integração de pedidos de outros sistemas (RestaurantePro)").
```

- [ ] **Step 2: Rodar todos os testes PHP**

```bash
for f in scripts/teste-php/*.php; do case "$f" in *stubs*|*fixtures*) continue;; esac; r=$(PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs "$f" 2>&1 | tail -1); echo "$r  $f"; done | grep -v "FALHAS: 0"
```
Expected: nenhuma linha (todos com `FALHAS: 0`). Se algum teste antigo que não roda neste ambiente aparecer (ex.: `emails.php`, que pode exigir outra preparação), confira com `git stash` se ele já falhava antes deste ramo e anote no relatório.

- [ ] **Step 3: Sintaxe de todos os arquivos PHP novos e alterados**

```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs $(git diff --name-only main...HEAD -- 'api/*.php')
```
Expected: sem erro.

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md
git commit -m "Integração: documentação do servidor no CLAUDE.md

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 5: Roteiro de conferência em produção (para o Edgard, depois do deploy)**

Não executar aqui (o Claude não tem SSH). Deixar no relatório final, para o Edgard rodar na VPS depois de `bash deploy/atualizar.sh api` e do `docker-stack.yml` novo no Portainer com `ENTREGAS_INTEGRACAO=1`:

1. `docker exec $(docker ps -q -f name=entregas_application) php artisan route:list --path=entregas/integracao` lista as 4 rotas externas.
2. Gerar a chave da loja de teste (Terraço Pizza Bar) pela API do console até a tela do plano 2 existir: `POST int/v1/entregas/lojas/<vendor_…>/integracao/chave` com o token de admin do console.
3. `curl -s -X POST https://entregas-api.restaurantepro.com.br/v1/entregas/integracao/conexao -H "Authorization: Bearer ent_live_…" -H "Content-Type: application/json" -d '{"sistema":"Teste","webhook_url":"https://webhook.site/<id>"}'` → 200 com `webhook_segredo` (confirma que o `ProtegerPortalLoja` deixa a chave passar).
4. `POST .../pedidos` com o corpo do exemplo da spec e coordenadas a ~1 km da loja de teste (motoboys avisados) → 201; no log, `pedido criado` e `iniciada` da distribuição.
5. Aceitar no app, tocar "A caminho" e concluir; no webhook.site, os três avisos com a assinatura.
6. `POST .../pedidos/<id>/cancelar` num segundo pedido antes do aceite → `cancelado`.
7. Se algo der errado: `ENTREGAS_INTEGRACAO=` vazio e Update the stack.

---

## Autorrevisão (feita ao escrever o plano)

- **Cobertura da spec (Partes 1, 2 e servidor da 4):** chave e rotas do console (Tasks 2, 9); `UrlDoWebhook` (3); corpo e cobrança (4); criação, idempotência, geocodificação de reserva, sem localização, despacho (5); cancelamento nas cinco situações e o pago (6, 11); avisos, assinatura, cabeçalhos, retentativas, 410, URL recusada (7, 8); reconciliação (10); `cancelado_pago` no `CalculoEntregas`, push, alarme, rota do app, stack, logs (11); docs (12). O painel do console e o APK ficam no plano 2; o RestaurantePro, no plano 3.
- **Desvios da spec, de propósito:** a autenticação fica no controller (sem middleware `ChaveDeIntegracao`), porque o `Request` do Laravel não precisa carregar a loja entre camadas e assim fica testável no php-wasm; a linha do pedido guarda o `vendor_uuid` (não o id da chave), para a chave nova achar os pedidos feitos com a anterior; o cancelamento pelo portal sai como `por: loja` pelo tipo do usuário da sessão.
- **Nomes conferidos entre tarefas:** `ChavesDeIntegracao::{gerar,revogar,ativa,daChave,conectar,segredo,marcarUso,registrarFalhaDeAviso,limparFalhaDeAviso,atualizar}`, `PedidosDaIntegracao::{doPedido,ehDaIntegracao,daLoja,atualizar}`, `SequenciaDaIntegracao::{alvo,faltando,depoisDe,doPedido}` e as constantes `ACEITO`/`A_CAMINHO`/`CONCLUIDO`/`CANCELADO`, `CancelamentoPelaOrigem::{cancelar,textoDoPush}` e `CANCELADO`/`CANCELADO_PAGO`/`JA_CONCLUIDO`, `AvisosDaIntegracao::{sincronizar,registrarFalha,idDoAviso,assinatura,corpo}`, `EnviarAvisoDaIntegracao::{enfileirar,PENDENTE,ESPERA_DA_TRAVA}`, `CriadorDoPedidoDaIntegracao::{criar,despachar,notas}`.
