# Saque Pix pelo Asaas — Plano 1: servidor (API) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** a API do Entregas deixa o motoboy sacar o saldo por Pix, saindo da conta Asaas da central, com a chave de API cifrada fora do alcance do banco, validação de saque do Asaas obrigatória, limites, carência na troca da chave Pix e acompanhamento até o dinheiro cair.

**Architecture:** tudo em `api/app` (Laravel), no padrão do Financeiro: classes em `App\Support\Entregas\Saques` com `DB::table`, controllers em `Http/Controllers/Entregas`. O saque vira um `pagamento` Pix no livro do Financeiro na hora do pedido (o saldo já desconta) e é estornado sozinho se falhar. O Asaas é chamado só pelo `AsaasCliente`; a chave da central é cifrada (AES-256-GCM) com a chave do Docker secret `entregas_saque_chave`. Uma rota pública responde à validação de saque do Asaas e uma rotina por minuto acompanha as transferências.

**Tech Stack:** PHP 8.2, Laravel 10 (Fleetbase), MySQL 8, Http do Laravel, OpenSSL (`aes-256-gcm`), testes em `scripts/teste-php` (php-wasm, banco em memória).

**Spec:** `docs/superpowers/specs/2026-10-26-saque-pix-asaas-design.md`. Planos seguintes: plano 2 (console) e plano 3 (app `entregas-navigator`), escritos depois deste.

---

## Antes de começar

- **Worktree:** `git -C "C:/Users/Edgardjr/Documents/vibe coding/Delivery" worktree add C:/tmp/saque -b saque-pix`. Todos os caminhos abaixo são relativos à raiz do worktree (`/c/tmp/saque` no Git Bash).
- **Testes PHP:** `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/<arquivo>.php` (termina com `FALHAS: 0`; sai com 1 se falhar). Sintaxe: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs <arquivos.php>`.
- **Leia antes:** `CLAUDE.md` (raiz), `api/CLAUDE.md` (fuso), `api/app/Support/Entregas/Financeiro/CLAUDE.md` (livro, sinal, estorno), `api/app/Http/Controllers/Entregas/CLAUDE.md` (app do motoboy).
- **Regras que valem em todo o plano:**
  - Datas gravadas com `now()->format('Y-m-d H:i:s')` (hora de Brasília). Nunca UTC.
  - O MySQL devolve decimal como texto e booleano como 0/1: sempre `(float)`, `(int)`, `(bool)` ao ler.
  - Valores comparados em **centavos** (`Saque::centavos`).
  - **Nunca** a chave de API do Asaas, o token da validação nem a chave Pix inteira em log ou resposta.
  - Mensagens ao usuário em pt-BR, as do spec.
  - Commit ao fim de cada tarefa, terminando com a linha `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Sem push (o Edgard decide).

## Mudança em relação ao spec

- **Motivo do estorno no reembolso:** o estorno automático do pagamento de um saque que falhou usa o motivo padrão do `Pagamentos::estornar` ("Pagamento estornado"), e não "Saque não concluído". O app do motoboy reconhece o estorno de pagamento por esse texto (`MOTIVO_DO_PAGAMENTO_ESTORNADO` em `entregas-navigator/src/utils/extrato.ts`). O motivo da falha fica em `entregas_saques.motivo_falha` e vai no push. A Tarefa 14 atualiza o spec.
- **Taxa sem `pagamento_uuid`:** a taxa é um `ajuste` negativo ligado ao saque por `entregas_saques.taxa_lancamento_id`. Com `pagamento_uuid`, o extrato a mostraria como pagamento.

## Correções da revisão (depois da Task 10)

O código das Tasks 1–10 abaixo é o do plano original; o ramo `saque-pix` já tem estas correções (um commit cada, com
teste), e as Tasks 11–16 contam com elas:

- `ConexaoAsaas::testarValidacao($empresa, $tipo, $chave, ?string $usuarioUuid)`: um teste por vez, no máximo
  `TESTES_POR_DIA` (5) por dia de Brasília (colunas `teste_pedido_por`, `testes_no_dia`, `testes_dia` na migration),
  quem pediu e a chave escondida no log; teste que falha volta ao estado anterior; secret trocado → 422 "Conecte a chave
  de novo".
- Mínimo por saque ≥ R$ 2,00 (`ConexaoAsaas::MINIMO_POR_SAQUE`); limites numéricos finitos de 0 a 9.999.999,99.
- `AsaasCliente`: só base `https://` e `allow_redirects` false; `#[\SensitiveParameter]` na chave.
- `desconectar` e `Saques::pedir` travam a linha da conexão (`lockForUpdate`) na transação; dois `conectar` ao mesmo
  tempo viram troca. A rotina só adota pelo `externalReference` saque ainda em andamento.
- Testes: o banco em memória anota os `lockForUpdate` em `Teste\Banco::$travas` ([tabela, dentro de transação?]).

## Mapa dos arquivos

| Arquivo | Responsabilidade |
|---|---|
| `api/config/services.php` | `services.entregas.saque`, `saque_chave_arquivo`, `asaas_url` |
| `api/database/migrations/2026_10_27_100000_create_entregas_financeiro_saques_tables.php` | `entregas_asaas_conexoes`, `entregas_saques`, colunas novas em `entregas_motoboys_financeiro` |
| `api/app/Support/Entregas/Saques/Saque.php` | constantes, interruptor, funções puras |
| `.../Saques/Cofre.php`, `CofreIndisponivel.php` | cifrar/decifrar com a chave do Docker secret |
| `.../Saques/RespostaAsaas.php`, `AsaasCliente.php`, `ErroDoAsaas.php` | único ponto de HTTP com o Asaas |
| `.../Saques/AdminDaCentral.php` | admin do sistema ou papel Administrator na organização |
| `.../Saques/ConexaoAsaas.php` | conectar, trocar, desconectar, token, teste da validação, limites |
| `.../Saques/Saques.php`, `SaqueRecusado.php` | bloqueio, carência, disponível, pedido, envio, conclusão, falha e reembolso, painel |
| `.../Saques/ValidacaoDeSaque.php` | decisão APPROVED/REFUSED para o Asaas |
| `.../Saques/AcompanhamentoDeSaques.php` | rotina: consulta as transferências em andamento |
| `api/app/Console/Commands/Entregas/SaquesConferir.php` | `entregas:saques-conferir` |
| `api/app/Notifications/Entregas/SaqueConcluido.php`, `SaqueNaoConcluido.php`, `PixDeSaqueAlterado.php` | pushes ao motoboy |
| `api/app/Http/Controllers/Entregas/SaquesController.php` | app: pedir e acompanhar |
| `api/app/Http/Controllers/Entregas/SaquePixController.php` | console: conexão, token, teste, limites, lista, bloqueio |
| `api/app/Http/Controllers/Entregas/ValidacaoAsaasController.php` | rota pública da validação |
| Modificados | `ChavePix.php`, `Carteira.php`, `ExtratoDaConta.php`, `PainelFinanceiro.php`, `FinanceiroController.php`, `AvisosDoMotoboy.php`, `RouteServiceProvider.php`, `Console/Kernel.php`, `deploy/docker-stack.yml`, `deploy/stack.env.example`, `scripts/teste-php/imports-dos-providers.php` |
| Testes | `scripts/teste-php/stubs-saque.php` e `saque-*.php` |

---

### Task 1: Configuração e migration

**Files:**
- Modify: `api/config/services.php` (bloco `'entregas' => [`, antes de `'console_url'`)
- Create: `api/database/migrations/2026_10_27_100000_create_entregas_financeiro_saques_tables.php`
- Test: `scripts/teste-php/saque-esquema.php`

- [ ] **Step 1: Escrever o teste que falha**

Crie `scripts/teste-php/saque-esquema.php`:

```php
<?php

// Saque Pix (Asaas): a migration das tabelas novas e a configuração (services.entregas.saque*).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-esquema.php

require __DIR__ . '/stubs-financeiro.php';

use Illuminate\Support\Facades\Schema;

echo '== Migration' . PHP_EOL;
$arquivo = '/repo/api/database/migrations/2026_10_27_100000_create_entregas_financeiro_saques_tables.php';
confere(is_file($arquivo), 'a migration existe com o nome que o banco em memória lê (_entregas_financeiro_)');
$migration         = require $arquivo;
Schema::$criadas   = [];
Schema::$alteradas = [];
$migration->up();

function colunas(\Illuminate\Database\Schema\Blueprint $blueprint): array
{
    $saida = [];
    foreach ($blueprint->colunas as $coluna) {
        $primeiro = $coluna->argumentos[0] ?? null;
        $chave    = is_string($primeiro) && !in_array($coluna->tipo, ['index', 'unique'], true) ? $primeiro : $coluna->tipo . ':' . json_encode($primeiro);
        $saida[$chave] = $coluna;
    }

    return $saida;
}

confere(array_keys(Schema::$criadas) === ['entregas_asaas_conexoes', 'entregas_saques'], 'cria as duas tabelas');
$conexoes = colunas(Schema::$criadas['entregas_asaas_conexoes']);
foreach (['company_uuid', 'chave_cifrada', 'conta_nome', 'conta_documento', 'wallet_id', 'conectada_por', 'conectada_em', 'token_hash', 'token_gerado_em', 'validacao_confirmada_em', 'teste_transfer_id', 'teste_pedido_em', 'saque_ligado', 'minimo', 'maximo_dia', 'saques_dia', 'taxa', 'atualizada_por'] as $nome) {
    confere(isset($conexoes[$nome]), "entregas_asaas_conexoes.{$nome}");
}
confere(array_key_exists('unique', $conexoes['company_uuid']->modificadores), 'uma conexão por empresa');
confere($conexoes['chave_cifrada']->tipo === 'text', 'chave cifrada em text');
confere(array_key_exists('unique', $conexoes['token_hash']->modificadores) && ($conexoes['token_hash']->argumentos[1] ?? null) === 64, 'token: só o hash (64), único');
confere(($conexoes['saque_ligado']->modificadores['default'][0] ?? null) === false, 'saque nasce desligado');
confere(($conexoes['minimo']->modificadores['default'][0] ?? null) === 10 && ($conexoes['maximo_dia']->modificadores['default'][0] ?? null) === 500 && ($conexoes['saques_dia']->modificadores['default'][0] ?? null) === 3 && ($conexoes['taxa']->modificadores['default'][0] ?? null) === 0, 'padrões: mínimo 10, máximo/dia 500, 3 saques/dia, taxa 0');

$saques = colunas(Schema::$criadas['entregas_saques']);
foreach (['uuid', 'company_uuid', 'driver_uuid', 'valor', 'taxa', 'chave_pix', 'tipo_chave', 'situacao', 'motivo_falha', 'transfer_id', 'external_reference', 'asaas_status', 'pagamento_uuid', 'taxa_lancamento_id', 'comprovante_url', 'concluido_em'] as $nome) {
    confere(isset($saques[$nome]), "entregas_saques.{$nome}");
}
confere(array_key_exists('unique', $saques['uuid']->modificadores) && array_key_exists('unique', $saques['transfer_id']->modificadores) && array_key_exists('nullable', $saques['transfer_id']->modificadores), 'uuid único; transfer_id único e nulo até o Asaas responder');
confere(($saques['valor']->argumentos ?? null) === ['valor', 10, 2], 'valor decimal(10,2)');

$motoboys = colunas(Schema::$alteradas['entregas_motoboys_financeiro'][0] ?? new \Illuminate\Database\Schema\Blueprint());
confere(isset($motoboys['saque_bloqueado'], $motoboys['pix_alterada_em'], $motoboys['pix_alterada_por']), 'motoboy: bloqueio e troca da chave Pix');
confere(($motoboys['saque_bloqueado']->modificadores['default'][0] ?? null) === false, 'motoboy nasce desbloqueado');

echo '== Banco em memória' . PHP_EOL;
reiniciarFinanceiro();
\Illuminate\Support\Facades\DB::table('entregas_asaas_conexoes')->insert(['company_uuid' => 'empresa-1', 'chave_cifrada' => 'x', 'wallet_id' => 'w']);
$linha = linhasDe('entregas_asaas_conexoes')[0];
confere($linha['saque_ligado'] === false && $linha['minimo'] === 10 && $linha['saques_dia'] === 3, 'o banco em memória lê a migration nova (padrões aplicados)');

echo '== Configuração' . PHP_EOL;
$servicos = file_get_contents('/repo/api/config/services.php');
confere(str_contains($servicos, "'saque' => env('ENTREGAS_SAQUE')"), 'services.entregas.saque');
confere(str_contains($servicos, "'saque_chave_arquivo' => env('ENTREGAS_SAQUE_CHAVE_ARQUIVO', '/run/secrets/entregas_saque_chave')"), 'arquivo do Docker secret');
confere(str_contains($servicos, "'asaas_url' => env('ASAAS_API_URL', 'https://api.asaas.com/v3')"), 'URL do Asaas');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/saque-esquema.php`
Expected: FALHA em "a migration existe…" (e erro de PHP no `require`).

- [ ] **Step 3: Configuração**

Em `api/config/services.php`, dentro de `'entregas' => [`, logo depois da linha `'financeiro' => env('ENTREGAS_FINANCEIRO'),`, acrescente:

```php
        // saque Pix do motoboy pelo app, pela conta Asaas da central (App\Support\Entregas\Saques): 1 liga as rotas do app,
        // a validação de saque e o entregas:saques-conferir; vazio = nada de saque (o app não mostra o botão)
        'saque' => env('ENTREGAS_SAQUE'),
        // arquivo do Docker secret com a chave (base64 de 32 bytes) que cifra a chave de API do Asaas das centrais. Fora do
        // banco de propósito: o backup do MySQL e um acesso ao banco só mostram texto cifrado
        'saque_chave_arquivo' => env('ENTREGAS_SAQUE_CHAVE_ARQUIVO', '/run/secrets/entregas_saque_chave'),
        // API do Asaas (o ensaio usa https://api-sandbox.asaas.com/v3)
        'asaas_url' => env('ASAAS_API_URL', 'https://api.asaas.com/v3'),
```

- [ ] **Step 4: Migration**

Crie `api/database/migrations/2026_10_27_100000_create_entregas_financeiro_saques_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: saque Pix do motoboy pelo app, pela conta Asaas da central (App\Support\Entregas\Saques).
 * Desenho: docs/superpowers/specs/2026-10-26-saque-pix-asaas-design.md.
 *
 * - entregas_asaas_conexoes: a conta Asaas da central (uma por empresa). A chave de API fica cifrada (Cofre, com a chave
 *   do Docker secret, fora do banco); do token da validação de saque, só o hash.
 * - entregas_saques: cada saque pedido no app (o uuid vem do app e é a chave de idempotência).
 * - entregas_motoboys_financeiro: + bloqueio do saque e quem/quando trocou a chave Pix (carência de 24 h).
 *
 * O nome tem `_entregas_financeiro_` porque o banco em memória dos testes lê o esquema por glob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_asaas_conexoes', function (Blueprint $table) {
            $table->id();
            $table->char('company_uuid', 36)->unique();
            $table->text('chave_cifrada');                        // Cofre::cifrar: v1:base64(iv + tag + cifra)
            $table->string('conta_nome', 255)->nullable();
            $table->string('conta_documento', 20)->nullable();    // só os dígitos do CPF/CNPJ da conta
            $table->string('wallet_id', 64);                      // fixada ao conectar; conferida antes de cada saque
            $table->char('conectada_por', 36)->nullable();
            $table->timestamp('conectada_em')->nullable();
            $table->char('token_hash', 64)->nullable()->unique(); // sha256 do token da validação de saque
            $table->timestamp('token_gerado_em')->nullable();
            $table->timestamp('validacao_confirmada_em')->nullable();
            $table->string('teste_transfer_id', 64)->nullable();
            $table->timestamp('teste_pedido_em')->nullable();
            $table->boolean('saque_ligado')->default(false);
            $table->decimal('minimo', 10, 2)->default(10);
            $table->decimal('maximo_dia', 10, 2)->default(500);
            $table->unsignedTinyInteger('saques_dia')->default(3);
            $table->decimal('taxa', 10, 2)->default(0);
            $table->char('atualizada_por', 36)->nullable();
            $table->timestamps();
        });

        Schema::create('entregas_saques', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();                  // gerado no app: o mesmo pedido repetido não cria outro
            $table->char('company_uuid', 36);
            $table->char('driver_uuid', 36);
            $table->decimal('valor', 10, 2);                     // o que cai no Pix
            $table->decimal('taxa', 10, 2)->default(0);           // debitada do saldo à parte
            $table->string('chave_pix', 140);                    // copiada do cadastro no instante do pedido
            $table->string('tipo_chave', 12);
            $table->string('situacao', 12);                      // enviando processando concluido falhou
            $table->string('motivo_falha', 255)->nullable();
            $table->string('transfer_id', 64)->nullable()->unique();
            $table->string('external_reference', 80);            // saque:<uuid>
            $table->string('asaas_status', 30)->nullable();
            $table->char('pagamento_uuid', 36)->nullable();       // entregas_pagamentos.uuid (forma pix)
            $table->unsignedBigInteger('taxa_lancamento_id')->nullable();
            $table->string('comprovante_url', 500)->nullable();  // transactionReceiptUrl do Asaas
            $table->timestamp('concluido_em')->nullable();
            $table->timestamps();
            $table->index(['company_uuid', 'situacao'], 'ent_saq_situacao');
            $table->index(['driver_uuid', 'created_at'], 'ent_saq_motoboy');
            $table->index('pagamento_uuid');
        });

        Schema::table('entregas_motoboys_financeiro', function (Blueprint $table) {
            $table->boolean('saque_bloqueado')->default(false);
            $table->timestamp('pix_alterada_em')->nullable();
            $table->char('pix_alterada_por', 36)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('entregas_motoboys_financeiro', function (Blueprint $table) {
            $table->dropColumn(['saque_bloqueado', 'pix_alterada_em', 'pix_alterada_por']);
        });
        Schema::dropIfExists('entregas_saques');
        Schema::dropIfExists('entregas_asaas_conexoes');
    }
};
```

- [ ] **Step 5: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/saque-esquema.php`
Expected: `FALHAS: 0`.

Rode também `financeiro-pagamentos.php` e `financeiro-console.php`: o banco em memória passa a ter as colunas novas e os dois precisam continuar em `FALHAS: 0`. Se o `financeiro-pagamentos.php` falhar em "cria as cinco tabelas" por causa do glob, não mexa nele: ele só carrega a migration de 2026_10_13 (confira a falha antes de concluir qualquer coisa).

- [ ] **Step 6: Commit**

```bash
git add api/config/services.php api/database/migrations/2026_10_27_100000_create_entregas_financeiro_saques_tables.php scripts/teste-php/saque-esquema.php
git commit -m "Saque Pix: configuração e tabelas (conexão Asaas, saques, bloqueio e troca da chave Pix)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `Saque` (constantes e funções puras) e `Cofre`

**Files:**
- Create: `api/app/Support/Entregas/Saques/Saque.php`, `Cofre.php`, `CofreIndisponivel.php`
- Create: `scripts/teste-php/stubs-saque.php`
- Test: `scripts/teste-php/saque-cofre.php`

- [ ] **Step 1: Stubs dos testes do saque**

Crie `scripts/teste-php/stubs-saque.php` (usado por todos os testes seguintes):

```php
<?php

// Stubs dos testes do saque Pix (scripts/teste-php/saque-*.php), sobre os do financeiro: o Hash (senha do admin), o
// vínculo do usuário com a empresa (CompanyUser, papel Administrator), o usuário do console, o arquivo do Docker secret
// com a chave do Cofre, a fila de respostas do Asaas (Http falso) e os cenários comuns.

namespace {
    require_once __DIR__ . '/stubs-financeiro.php';

    // a base das notificações do Laravel (as do saque a estendem; o envio é o notify do Driver falso)
    if (!class_exists(\Illuminate\Notifications\Notification::class, false)) {
        eval('namespace Illuminate\Notifications; class Notification {}');
    }
}

namespace Illuminate\Support\Facades {
    if (!class_exists(Hash::class, false)) {
        // o Hash::check do Laravel: nos testes, o "hash" da senha é "hash:<senha>"
        class Hash
        {
            public static function check($valor, $hash): bool { return is_string($hash) && $hash === 'hash:' . $valor; }
        }
    }
}

namespace Fleetbase\Models {
    if (!class_exists(CompanyUser::class, false)) {
        // o vínculo do usuário com a empresa (Spatie HasRoles): hasRole pelo nome do papel
        #[\AllowDynamicProperties]
        class CompanyUser
        {
            use \Teste\ModeloDeTeste;
            public $user_uuid;
            public $company_uuid;
            public array $papeis = [];
            public function hasRole($papel): bool { return in_array($papel, $this->papeis, true); }
        }
    }
}

namespace Teste {
    // o usuário da sessão no console (Auth::getUserFromSession): type admin = administrador do sistema
    class UsuarioDoSaque
    {
        public function __construct(public string $uuid, public string $type = 'user', public string $password = 'hash:senha-certa') {}
        public function isAdmin(): bool { return $this->type === 'admin'; }
        public function isNotAdmin(): bool { return $this->type !== 'admin'; }
    }
}

namespace {
    define('CHAVE_DO_COFRE', sys_get_temp_dir() . '/entregas_saque_chave');
    const CHAVE_ASAAS    = 'chave-asaas-de-teste-123456';
    const TOKEN_DE_TESTE = 'token-de-teste';

    /** Zera tudo, liga o saque e grava a chave do Cofre no "Docker secret" de teste. */
    function reiniciarSaque(): void
    {
        reiniciarFinanceiro();
        \Fleetbase\Models\CompanyUser::$todos                               = [];
        \Teste\Config::$valores['services.entregas.saque']                 = '1';
        \Teste\Config::$valores['services.entregas.saque_chave_arquivo']   = CHAVE_DO_COFRE;
        \Teste\Config::$valores['services.entregas.asaas_url']             = 'https://asaas.teste/v3';
        \Teste\Config::$valores['app.url']                                = 'https://entregas-api.teste';
        file_put_contents(CHAVE_DO_COFRE, base64_encode(str_repeat('k', 32)) . "\n");
        \Illuminate\Support\Str::$gerados = 0;
        \Teste\Relogio::$agora            = '2026-10-27 10:00:00';
    }

    /** Põe uma resposta do Asaas na fila do Http falso: [status, corpo]. */
    function asaas(int $status, $corpo = null): void
    {
        \Teste\Http::$respostas[] = [$status, $corpo];
    }

    /** Motoboy da empresa-1 com chave Pix (telefone) e uma corrida de $saldo reais. public_id = uuid com "_" no lugar de "-". */
    function motoboyComSaldo(string $uuid, string $nome, float $saldo = 100.0, string $chave = '11999994321'): void
    {
        \Fleetbase\FleetOps\Models\Driver::$todos[] = new \Fleetbase\FleetOps\Models\Driver([
            'uuid' => $uuid, 'public_id' => str_replace('-', '_', $uuid), 'company_uuid' => 'empresa-1', 'user_uuid' => 'user-' . $uuid, 'name' => $nome,
        ]);
        \App\Support\Entregas\Financeiro\ChavePix::salvar('empresa-1', $uuid, $chave, 'telefone');
        \App\Support\Entregas\Financeiro\Lancamentos::inserir([
            'company_uuid' => 'empresa-1', 'conta_tipo' => 'motoboy', 'conta_uuid' => $uuid, 'tipo' => 'corrida', 'valor' => $saldo,
            'data_referencia' => '2026-10-26', 'order_uuid' => 'order-' . $uuid, 'chave_unica' => 'corrida:motoboy:order-' . $uuid,
        ]);
    }

    /**
     * A central empresa-1 conectada (chave CHAVE_ASAAS, wallet "wallet-1", token TOKEN_DE_TESTE), validação confirmada e
     * saque ligado com os padrões (ou os campos de $conexao); o motoboy driver-1 (João Silva) com chave 11999994321 e R$ 100.
     */
    function centralComSaque(array $conexao = []): void
    {
        reiniciarSaque();
        $antes = '2026-10-26 09:00:00';
        \Illuminate\Support\Facades\DB::table('entregas_asaas_conexoes')->insert(array_merge([
            'company_uuid' => 'empresa-1', 'chave_cifrada' => \App\Support\Entregas\Saques\Cofre::cifrar(CHAVE_ASAAS),
            'conta_nome' => 'Central Teste', 'conta_documento' => '12345678000199', 'wallet_id' => 'wallet-1',
            'conectada_por' => 'user-admin', 'conectada_em' => $antes, 'token_hash' => hash('sha256', TOKEN_DE_TESTE),
            'token_gerado_em' => $antes, 'validacao_confirmada_em' => $antes, 'saque_ligado' => true,
            'created_at' => $antes, 'updated_at' => $antes,
        ], $conexao));
        motoboyComSaldo('driver-1', 'João Silva');
    }

    /** O saque pedido e enviado: a wallet confere e o Asaas responde $transferencia ao POST /transfers. */
    function saqueEnviado(string $driver, string $uuid, float $valor, array $transferencia): object
    {
        $pedido = \App\Support\Entregas\Saques\Saques::pedir('empresa-1', $driver, $uuid, $valor);
        asaas(200, ['data' => [['id' => 'wallet-1']]]);
        asaas(200, $transferencia);

        return \App\Support\Entregas\Saques\Saques::enviar($pedido['saque']);
    }

    /** O saque relido do banco pelo uuid. */
    function saqueDoBanco(string $uuid): ?object
    {
        return \Illuminate\Support\Facades\DB::table('entregas_saques')->where('uuid', $uuid)->first();
    }
}
```

- [ ] **Step 2: Escrever o teste que falha**

Crie `scripts/teste-php/saque-cofre.php`:

```php
<?php

// Saque Pix: o Cofre (AES-256-GCM com a chave do Docker secret) e as funções puras do Saque.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-cofre.php

require __DIR__ . '/stubs-saque.php';

use App\Support\Entregas\Saques\Cofre;
use App\Support\Entregas\Saques\CofreIndisponivel;
use App\Support\Entregas\Saques\Saque;
use Teste\Config;

echo '== Cofre' . PHP_EOL;
reiniciarSaque();
confere(Cofre::disponivel(), 'com o secret: disponível');
$cifrado = Cofre::cifrar(CHAVE_ASAAS);
confere(str_starts_with($cifrado, 'v1:') && !str_contains($cifrado, CHAVE_ASAAS) && !str_contains(base64_decode(substr($cifrado, 3)), CHAVE_ASAAS), 'cifrado: v1:..., sem a chave em claro');
confere(Cofre::decifrar($cifrado) === CHAVE_ASAAS, 'decifra de volta');
confere(Cofre::cifrar('x') !== Cofre::cifrar('x'), 'cada cifra sai diferente (nonce aleatório)');
$bytes = base64_decode(substr($cifrado, 3));
$adulterado = 'v1:' . base64_encode(substr($bytes, 0, -1) . chr(ord(substr($bytes, -1)) ^ 1));
confere(Cofre::decifrar($adulterado) === null, 'texto adulterado: null (a tag do GCM não confere)');
confere(Cofre::decifrar('lixo') === null && Cofre::decifrar('v1:@@@') === null && Cofre::decifrar(null) === null && Cofre::decifrar('') === null, 'lixo, vazio ou null: null');

file_put_contents(CHAVE_DO_COFRE, base64_encode(str_repeat('z', 32)));
confere(Cofre::decifrar($cifrado) === null, 'outra chave no secret: não decifra');
file_put_contents(CHAVE_DO_COFRE, 'curta');
confere(!Cofre::disponivel(), 'secret que não é base64 de 32 bytes: indisponível');
unlink(CHAVE_DO_COFRE);
confere(!Cofre::disponivel(), 'sem o arquivo do secret: indisponível');
$erro = excecao(fn () => Cofre::cifrar('x'));
confere($erro instanceof CofreIndisponivel && $erro->getMessage() === 'Saque Pix indisponível: chave de criptografia não configurada no servidor.', 'sem o secret, cifrar lança CofreIndisponivel com a mensagem do spec');
confere(Cofre::decifrar($cifrado) === null, 'sem o secret, decifrar devolve null');
Config::$valores['services.entregas.saque_chave_arquivo'] = '';
confere(!Cofre::disponivel(), 'caminho vazio: indisponível');

echo '== Saque (funções puras)' . PHP_EOL;
reiniciarSaque();
confere(Saque::ligada() === true, 'ENTREGAS_SAQUE=1: ligada');
Config::$valores['services.entregas.saque'] = null;
confere(Saque::ligada() === false, 'vazia: desligada');
confere(Saque::situacaoDoAsaas('DONE') === 'concluido' && Saque::situacaoDoAsaas('FAILED') === 'falhou' && Saque::situacaoDoAsaas('cancelled') === 'falhou', 'DONE → concluido; FAILED e CANCELLED → falhou');
confere(Saque::situacaoDoAsaas('PENDING') === 'processando' && Saque::situacaoDoAsaas('BANK_PROCESSING') === 'processando' && Saque::situacaoDoAsaas(null) === 'processando', 'o resto segue processando');
confere(Saque::chaveEscondida('11999994321', 'telefone') === '*******4321' && Saque::chaveEscondida('12345678901', 'cpf') === '*******8901', 'telefone e CPF: só os 4 últimos');
confere(Saque::chaveEscondida('joao.silva@gmail.com', 'email') === 'jo***@gmail.com', 'e-mail: 2 letras e o domínio');
confere(Saque::chaveEscondida('123e4567-e89b-12d3-a456-426614174000', 'aleatoria') === '123e…4000', 'aleatória: começo e fim');
confere(Saque::centavos('150.10') === 15010 && Saque::centavos(0.1 + 0.2) === 30 && Saque::centavos(null) === 0, 'centavos');
confere(Saque::reais(15010) === 150.1 && Saque::dinheiro(1234.5) === 'R$ 1.234,50', 'reais e dinheiro');
confere(Saque::iso('2026-10-27 10:00:00', 'empresa-1') === '2026-10-27T10:00:00-03:00' && Saque::iso(null, 'empresa-1') === null, 'ISO 8601 no fuso da empresa');
confere(Saque::TIPOS_ASAAS === ['cpf' => 'CPF', 'cnpj' => 'CNPJ', 'telefone' => 'PHONE', 'email' => 'EMAIL', 'aleatoria' => 'EVP'], 'tipos de chave no Asaas');

resumo();
```

- [ ] **Step 3: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/saque-cofre.php`
Expected: erro "Class App\Support\Entregas\Saques\Cofre not found".

- [ ] **Step 4: Implementar `Saque`**

Crie `api/app/Support/Entregas/Saques/Saque.php`:

```php
<?php

namespace App\Support\Entregas\Saques;

use App\Support\Entregas\Financeiro\Financeiro;

/**
 * Entregas RestaurantePro: saque Pix do motoboy pelo app, pela conta Asaas da central. Constantes, o interruptor geral
 * (ENTREGAS_SAQUE, `services.entregas.saque`) e funções puras (centavos, dinheiro, chave Pix com o meio escondido,
 * situação a partir do status do Asaas). Desenho: docs/superpowers/specs/2026-10-26-saque-pix-asaas-design.md.
 */
final class Saque
{
    public const TABELA   = 'entregas_saques';
    public const CONEXOES = 'entregas_asaas_conexoes';

    public const ENVIANDO     = 'enviando';
    public const PROCESSANDO  = 'processando';
    public const CONCLUIDO    = 'concluido';
    public const FALHOU       = 'falhou';
    public const EM_ANDAMENTO = [self::ENVIANDO, self::PROCESSANDO];
    public const SITUACOES    = [self::ENVIANDO, self::PROCESSANDO, self::CONCLUIDO, self::FALHOU];

    /** Horas sem saque depois de a central trocar a chave Pix do motoboy. */
    public const CARENCIA_PIX_HORAS = 24;

    /** Minutos até a rotina procurar no Asaas o saque que ficou sem transfer_id (a API caiu no meio do envio). */
    public const MINUTOS_SEM_TRANSFERENCIA = 5;

    /** Minutos em que a validação aceita um saque (ou o teste) ainda sem transfer_id gravado. */
    public const MINUTOS_DA_VALIDACAO = 2;

    /** Em andamento há mais que isso: "saque parado" no painel. */
    public const MINUTOS_PARADO = 60;

    /** Itens de cada tipo no "Atenção" do painel. */
    public const LIMITE_NO_PAINEL = 20;

    public const PADROES = ['minimo' => 10.0, 'maximo_dia' => 500.0, 'saques_dia' => 3, 'taxa' => 0.0];

    public const TIPOS_ASAAS = ['cpf' => 'CPF', 'cnpj' => 'CNPJ', 'telefone' => 'PHONE', 'email' => 'EMAIL', 'aleatoria' => 'EVP'];

    public const OBSERVACAO_DO_PAGAMENTO = 'Saque Pix pelo app';
    public const MOTIVO_DA_TAXA          = 'Taxa do saque Pix';
    public const MOTIVO_TAXA_DEVOLVIDA   = 'Taxa do saque devolvida';
    public const DESCRICAO_NO_ASAAS      = 'Saque Entregas';

    public static function ligada(): bool
    {
        return filter_var(config('services.entregas.saque'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function centavos($valor): int
    {
        return (int) round(((float) $valor) * 100);
    }

    public static function reais(int $centavos): float
    {
        return round($centavos / 100, 2);
    }

    /** 1234.5 → "R$ 1.234,50". */
    public static function dinheiro(float $valor): string
    {
        return 'R$ ' . number_format($valor, 2, ',', '.');
    }

    /** A situação nossa para um status de transferência do Asaas: DONE fecha, FAILED/CANCELLED falham, o resto processa. */
    public static function situacaoDoAsaas(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            'DONE'                => self::CONCLUIDO,
            'FAILED', 'CANCELLED' => self::FALHOU,
            default               => self::PROCESSANDO,
        };
    }

    /** A chave Pix com o meio escondido: telefone/CPF/CNPJ só os 4 últimos, e-mail 2 letras + domínio, aleatória começo e fim. */
    public static function chaveEscondida(string $chave, string $tipo): string
    {
        if ($tipo === 'email') {
            [$nome, $dominio] = array_pad(explode('@', $chave, 2), 2, '');

            return mb_substr($nome, 0, 2) . '***@' . $dominio;
        }
        if ($tipo === 'aleatoria') {
            return mb_substr($chave, 0, 4) . '…' . mb_substr($chave, -4);
        }

        return str_repeat('*', max(0, mb_strlen($chave) - 4)) . mb_substr($chave, -4);
    }

    /** 'Y-m-d H:i:s' gravado em hora de Brasília → ISO 8601 no fuso da empresa (null fica null). */
    public static function iso(?string $quando, string $companyUuid): ?string
    {
        if ($quando === null || $quando === '') {
            return null;
        }
        $fusoDoApp = (string) (config('app.timezone') ?: Financeiro::FUSO_PADRAO);

        return (new \DateTimeImmutable($quando, new \DateTimeZone($fusoDoApp)))
            ->setTimezone(new \DateTimeZone(Financeiro::fuso($companyUuid)))
            ->format(DATE_ATOM);
    }
}
```

- [ ] **Step 5: Implementar `Cofre` e `CofreIndisponivel`**

Crie `api/app/Support/Entregas/Saques/CofreIndisponivel.php`:

```php
<?php

namespace App\Support\Entregas\Saques;

/** Entregas RestaurantePro: o Docker secret com a chave do Cofre não está no container (o controller responde 503). */
class CofreIndisponivel extends \RuntimeException
{
    public function __construct(string $mensagem = 'Saque Pix indisponível: chave de criptografia não configurada no servidor.')
    {
        parent::__construct($mensagem);
    }
}
```

Crie `api/app/Support/Entregas/Saques/Cofre.php`:

```php
<?php

namespace App\Support\Entregas\Saques;

/**
 * Entregas RestaurantePro: cifra e decifra a chave de API do Asaas das centrais (AES-256-GCM). A chave que cifra vem do
 * Docker secret `entregas_saque_chave` (arquivo `services.entregas.saque_chave_arquivo`, base64 de 32 bytes), nunca do
 * banco: o backup do MySQL e um acesso ao banco só mostram texto cifrado. Formato gravado: "v1:" + base64(iv 12 + tag 16
 * + cifra). Texto adulterado, de outra chave ou lixo: decifrar devolve null (a tag do GCM não confere).
 */
final class Cofre
{
    private const VERSAO = 'v1:';
    private const CIFRA  = 'aes-256-gcm';

    public static function disponivel(): bool
    {
        return static::chave() !== null;
    }

    public static function cifrar(string $texto): string
    {
        $chave = static::chave();
        if ($chave === null) {
            throw new CofreIndisponivel();
        }
        $iv     = random_bytes(12);
        $tag    = '';
        $cifra  = openssl_encrypt($texto, self::CIFRA, $chave, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cifra === false) {
            throw new \RuntimeException('Não foi possível cifrar.');
        }

        return self::VERSAO . base64_encode($iv . $tag . $cifra);
    }

    public static function decifrar(?string $cifrado): ?string
    {
        $chave = static::chave();
        if ($chave === null || !is_string($cifrado) || !str_starts_with($cifrado, self::VERSAO)) {
            return null;
        }
        $bytes = base64_decode(substr($cifrado, strlen(self::VERSAO)), true);
        if ($bytes === false || strlen($bytes) <= 28) {
            return null;
        }
        $texto = openssl_decrypt(substr($bytes, 28), self::CIFRA, $chave, OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16));

        return $texto === false ? null : $texto;
    }

    /** Os 32 bytes da chave, lidos do arquivo do secret a cada uso (troca de secret vale sem reiniciar); null se faltar. */
    private static function chave(): ?string
    {
        $arquivo = config('services.entregas.saque_chave_arquivo');
        if (!is_string($arquivo) || $arquivo === '' || !is_file($arquivo) || !is_readable($arquivo)) {
            return null;
        }
        $bruta = base64_decode(trim((string) file_get_contents($arquivo)), true);

        return is_string($bruta) && strlen($bruta) === 32 ? $bruta : null;
    }
}
```

- [ ] **Step 6: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/saque-cofre.php`
Expected: `FALHAS: 0`.

- [ ] **Step 7: Commit**

```bash
git add api/app/Support/Entregas/Saques/Saque.php api/app/Support/Entregas/Saques/Cofre.php api/app/Support/Entregas/Saques/CofreIndisponivel.php scripts/teste-php/stubs-saque.php scripts/teste-php/saque-cofre.php
git commit -m "Saque Pix: Cofre (AES-256-GCM com a chave do Docker secret) e funções do Saque

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `AsaasCliente` e `RespostaAsaas`

**Files:**
- Create: `api/app/Support/Entregas/Saques/RespostaAsaas.php`, `AsaasCliente.php`, `ErroDoAsaas.php`
- Test: `scripts/teste-php/saque-asaas.php`

- [ ] **Step 1: Escrever o teste que falha**

Crie `scripts/teste-php/saque-asaas.php`:

```php
<?php

// Saque Pix: o AsaasCliente (único ponto de HTTP com o Asaas) e a RespostaAsaas, com o Http falso.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-asaas.php

require __DIR__ . '/stubs-saque.php';

use App\Support\Entregas\Saques\AsaasCliente;
use App\Support\Entregas\Saques\RespostaAsaas;
use Illuminate\Support\Facades\Log;
use Teste\Http as HttpFalso;

reiniciarSaque();
$asaas = new AsaasCliente(CHAVE_ASAAS);

echo '== Chamadas' . PHP_EOL;
asaas(200, ['name' => 'Central Teste', 'cpfCnpj' => '12345678000199']);
$r = $asaas->dadosComerciais();
$c = HttpFalso::$chamadas[0];
confere($r->ok() && ($r->dados['name'] ?? null) === 'Central Teste', 'dados comerciais');
confere($c['metodo'] === 'GET' && $c['url'] === 'https://asaas.teste/v3/myAccount/commercialInfo/', 'GET /myAccount/commercialInfo/ na URL da configuração: ' . $c['url']);
confere(($c['headers']['access_token'] ?? null) === CHAVE_ASAAS && ($c['headers']['User-Agent'] ?? null) === 'Entregas RestaurantePro', 'cabeçalhos access_token e User-Agent');
confere($c['timeout'] === 20, 'tempo limite de 20 s');

asaas(200, ['data' => [['object' => 'wallet', 'id' => 'wallet-1']]]);
$r = $asaas->carteiras();
confere(HttpFalso::$chamadas[1]['url'] === 'https://asaas.teste/v3/wallets' && AsaasCliente::walletDa($r) === 'wallet-1', 'GET /wallets e a wallet da conta');
asaas(200, ['data' => []]);
confere(AsaasCliente::walletDa($asaas->carteiras()) === null, 'sem wallet: null');

asaas(200, ['id' => 'tra_1', 'status' => 'PENDING']);
$asaas->transferirPix(150.5, '11999994321', 'PHONE', 'Saque Entregas', 'saque:abc');
$c = HttpFalso::$chamadas[3];
confere($c['metodo'] === 'POST' && $c['url'] === 'https://asaas.teste/v3/transfers', 'POST /transfers');
confere($c['dados'] === ['value' => 150.5, 'operationType' => 'PIX', 'pixAddressKey' => '11999994321', 'pixAddressKeyType' => 'PHONE', 'description' => 'Saque Entregas', 'externalReference' => 'saque:abc'], 'corpo da transferência Pix: ' . json_encode($c['dados']));

asaas(200, ['id' => 'tra_1', 'status' => 'DONE']);
$asaas->transferencia('tra/1');
confere(HttpFalso::$chamadas[4]['url'] === 'https://asaas.teste/v3/transfers/tra%2F1', 'GET /transfers/{id} com o id codificado');
asaas(200, ['data' => []]);
$asaas->transferencias(100);
confere(HttpFalso::$chamadas[5]['url'] === 'https://asaas.teste/v3/transfers?offset=100&limit=100', 'lista paginada');

echo '== Respostas' . PHP_EOL;
HttpFalso::$respostas[] = 'conexao';
$r = $asaas->transferencia('tra_1');
confere($r->status === 0 && $r->semResposta() && !$r->ok(), 'fora do ar: status 0, sem resposta');
confere(!str_contains(json_encode(Log::$registros), CHAVE_ASAAS), 'o log nunca traz a chave');
asaas(502, '<html>erro</html>');
$r = $asaas->transferencia('tra_1');
confere($r->semResposta() && $r->dados === null, '5xx: sem resposta, sem dados');
asaas(400, ['errors' => [['code' => 'invalid_value', 'description' => 'Saldo insuficiente.'], ['code' => 'x', 'description' => 'Outro.']]]);
$r = $asaas->transferencia('tra_1');
confere(!$r->ok() && !$r->semResposta() && !$r->chaveRecusada() && $r->mensagem('padrão') === 'Saldo insuficiente.; Outro.', '4xx: a mensagem do Asaas');
asaas(401, null);
$r = $asaas->transferencia('tra_1');
confere($r->chaveRecusada() && $r->mensagem('Chave recusada.') === 'Chave recusada.', '401: chave recusada, mensagem padrão');
confere((new RespostaAsaas(403, null))->chaveRecusada(), '403: chave recusada');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/saque-asaas.php`
Expected: erro "Class App\Support\Entregas\Saques\AsaasCliente not found".

- [ ] **Step 3: Implementar**

Crie `api/app/Support/Entregas/Saques/ErroDoAsaas.php`:

```php
<?php

namespace App\Support\Entregas\Saques;

/** Entregas RestaurantePro: o Asaas fora do ar ou com erro que não é da chave (o controller responde 502). */
class ErroDoAsaas extends \RuntimeException
{
}
```

Crie `api/app/Support/Entregas/Saques/RespostaAsaas.php`:

```php
<?php

namespace App\Support\Entregas\Saques;

/**
 * Entregas RestaurantePro: a resposta do Asaas. status 0 = não houve resposta (rede, tempo esgotado); `dados` = o JSON
 * (null se não veio JSON). semResposta() também vale para 5xx: nesses casos a transferência PODE ter sido criada.
 */
final class RespostaAsaas
{
    public function __construct(public int $status, public ?array $dados) {}

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function semResposta(): bool
    {
        return $this->status === 0 || $this->status >= 500;
    }

    public function chaveRecusada(): bool
    {
        return $this->status === 401 || $this->status === 403;
    }

    /** As descrições de `errors` do Asaas, juntas (até 255), ou o $padrao. */
    public function mensagem(string $padrao): string
    {
        $erros = $this->dados['errors'] ?? null;
        if (is_array($erros)) {
            $textos = [];
            foreach ($erros as $erro) {
                $texto = is_array($erro) ? (string) ($erro['description'] ?? $erro['code'] ?? '') : '';
                if (trim($texto) !== '') {
                    $textos[] = trim($texto);
                }
            }
            if ($textos) {
                return mb_substr(implode('; ', $textos), 0, 255);
            }
        }

        return $padrao;
    }
}
```

Crie `api/app/Support/Entregas/Saques/AsaasCliente.php`:

```php
<?php

namespace App\Support\Entregas\Saques;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: o único ponto de HTTP com o Asaas (base `services.entregas.asaas_url`), com a chave de API da
 * central já decifrada (Cofre). Nunca lança: falha de rede vira RespostaAsaas com status 0. O log leva só o caminho e a
 * classe do erro, nunca a chave.
 */
class AsaasCliente
{
    public const TEMPO_LIMITE = 20;

    public function __construct(private string $chave)
    {
    }

    public function dadosComerciais(): RespostaAsaas
    {
        return $this->chamar('GET', '/myAccount/commercialInfo/');
    }

    public function carteiras(): RespostaAsaas
    {
        return $this->chamar('GET', '/wallets');
    }

    public function transferirPix(float $valor, string $chavePix, string $tipoNoAsaas, string $descricao, string $referencia): RespostaAsaas
    {
        return $this->chamar('POST', '/transfers', [
            'value'             => round($valor, 2),
            'operationType'     => 'PIX',
            'pixAddressKey'     => $chavePix,
            'pixAddressKeyType' => $tipoNoAsaas,
            'description'       => $descricao,
            'externalReference' => $referencia,
        ]);
    }

    public function transferencia(string $id): RespostaAsaas
    {
        return $this->chamar('GET', '/transfers/' . rawurlencode($id));
    }

    /** As transferências mais recentes primeiro (o filtro por externalReference não é garantido: quem chama procura). */
    public function transferencias(int $offset, int $limite = 100): RespostaAsaas
    {
        return $this->chamar('GET', '/transfers?offset=' . $offset . '&limit=' . $limite);
    }

    /** A wallet da conta: o primeiro item do GET /wallets; null se não veio. */
    public static function walletDa(RespostaAsaas $resposta): ?string
    {
        $id = $resposta->dados['data'][0]['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    protected function chamar(string $metodo, string $caminho, ?array $dados = null): RespostaAsaas
    {
        $url = rtrim((string) config('services.entregas.asaas_url', 'https://api.asaas.com/v3'), '/') . $caminho;
        try {
            $pedido = Http::withHeaders(['access_token' => $this->chave, 'User-Agent' => 'Entregas RestaurantePro'])
                ->acceptJson()
                ->timeout(static::TEMPO_LIMITE);
            $resposta = $metodo === 'POST' ? $pedido->post($url, $dados ?? []) : $pedido->get($url);
        } catch (\Throwable $e) {
            Log::warning('[entregas] saque: Asaas sem resposta', ['caminho' => strtok($caminho, '?'), 'erro' => get_class($e)]);

            return new RespostaAsaas(0, null);
        }
        $json = $resposta->json();

        return new RespostaAsaas($resposta->status(), is_array($json) ? $json : null);
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/saque-asaas.php`
Expected: `FALHAS: 0`.

- [ ] **Step 5: Commit**

```bash
git add api/app/Support/Entregas/Saques/ErroDoAsaas.php api/app/Support/Entregas/Saques/RespostaAsaas.php api/app/Support/Entregas/Saques/AsaasCliente.php scripts/teste-php/saque-asaas.php
git commit -m "Saque Pix: cliente do Asaas (dados da conta, wallet, transferência Pix, consulta)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `AdminDaCentral` e `ConexaoAsaas`

**Files:**
- Create: `api/app/Support/Entregas/Saques/AdminDaCentral.php`, `ConexaoAsaas.php`
- Test: `scripts/teste-php/saque-conexao.php`

- [ ] **Step 1: Escrever o teste que falha**

Crie `scripts/teste-php/saque-conexao.php`:

```php
<?php

// Saque Pix: quem pode configurar (AdminDaCentral) e a conexão da central com o Asaas (ConexaoAsaas): conectar, trocar
// a chave, desconectar, token da validação, teste da validação e limites.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-conexao.php

require __DIR__ . '/stubs-saque.php';

use App\Support\Entregas\Saques\AdminDaCentral;
use App\Support\Entregas\Saques\Cofre;
use App\Support\Entregas\Saques\CofreIndisponivel;
use App\Support\Entregas\Saques\ConexaoAsaas;
use App\Support\Entregas\Saques\ErroDoAsaas;
use Fleetbase\Models\CompanyUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Teste\Http as HttpFalso;
use Teste\UsuarioDoSaque;

function mensagem(callable $fazer): ?string
{
    return excecao($fazer)?->getMessage();
}

function conexao(): ?object
{
    return ConexaoAsaas::daEmpresa('empresa-1');
}

echo '== AdminDaCentral' . PHP_EOL;
reiniciarSaque();
session(['company' => 'empresa-1']);
\Fleetbase\Support\Auth::$usuario = null;
confere(AdminDaCentral::usuario(new Request()) === null, 'sem usuário: ninguém');
\Fleetbase\Support\Auth::$usuario = new UsuarioDoSaque('user-admin', 'admin');
confere(AdminDaCentral::usuario(new Request())?->uuid === 'user-admin', 'administrador do sistema pode');
\Fleetbase\Support\Auth::$usuario = new UsuarioDoSaque('user-org');
confere(AdminDaCentral::usuario(new Request()) === null, 'usuário comum sem papel: não pode');
CompanyUser::$todos[] = new CompanyUser(['user_uuid' => 'user-org', 'company_uuid' => 'empresa-2', 'papeis' => ['Administrator']]);
confere(AdminDaCentral::usuario(new Request()) === null, 'Administrator de OUTRA organização: não pode');
CompanyUser::$todos[] = new CompanyUser(['user_uuid' => 'user-org', 'company_uuid' => 'empresa-1', 'papeis' => ['Operador']]);
confere(AdminDaCentral::usuario(new Request()) === null, 'outro papel na organização: não pode');
CompanyUser::$todos[] = new CompanyUser(['user_uuid' => 'user-org', 'company_uuid' => 'empresa-1', 'papeis' => ['Administrator']]);
confere(AdminDaCentral::usuario(new Request())?->uuid === 'user-org', 'papel Administrator na organização da sessão: pode');

echo '== Conectar' . PHP_EOL;
reiniciarSaque();
confere(mensagem(fn () => ConexaoAsaas::conectar('empresa-1', 'curta', 'user-admin')) === 'Chave de API inválida.', 'chave curta: inválida');
confere(mensagem(fn () => ConexaoAsaas::conectar('empresa-1', 'chave com espaco no meio 123', 'user-admin')) === 'Chave de API inválida.', 'chave com espaço: inválida');
unlink(CHAVE_DO_COFRE);
confere(excecao(fn () => ConexaoAsaas::conectar('empresa-1', CHAVE_ASAAS, 'user-admin')) instanceof CofreIndisponivel && HttpFalso::$chamadas === [], 'sem o secret: CofreIndisponivel e nem chama o Asaas');
reiniciarSaque();
asaas(401, ['errors' => [['description' => 'A chave de API informada não pertence a este ambiente']]]);
$erro = excecao(fn () => ConexaoAsaas::conectar('empresa-1', CHAVE_ASAAS, 'user-admin'));
confere($erro instanceof \InvalidArgumentException && $erro->getMessage() === 'Chave de API inválida ou sem permissão. Gere uma nova no Asaas (Integrações → Chaves de API).', 'chave recusada pelo Asaas: 422 com a orientação');
HttpFalso::$respostas[] = 'conexao';
confere(excecao(fn () => ConexaoAsaas::conectar('empresa-1', CHAVE_ASAAS, 'user-admin')) instanceof ErroDoAsaas, 'Asaas fora do ar: ErroDoAsaas (502)');
asaas(200, ['name' => 'Central Teste']);
asaas(200, ['data' => []]);
confere(mensagem(fn () => ConexaoAsaas::conectar('empresa-1', CHAVE_ASAAS, 'user-admin')) === 'O Asaas não informou a carteira desta conta.', 'sem wallet: erro');
confere(linhasDe('entregas_asaas_conexoes') === [], 'nada gravado nas recusas');

asaas(200, ['name' => 'Central Teste', 'cpfCnpj' => '12.345.678/0001-99']);
asaas(200, ['data' => [['id' => 'wallet-1']]]);
$c = ConexaoAsaas::conectar('empresa-1', '  ' . CHAVE_ASAAS . ' ', 'user-admin');
confere($c->wallet_id === 'wallet-1' && $c->conta_nome === 'Central Teste' && $c->conta_documento === '12345678000199' && $c->conectada_por === 'user-admin' && $c->conectada_em === '2026-10-27 10:00:00', 'conectada: wallet, nome, documento (dígitos), quem e quando');
confere(Cofre::decifrar($c->chave_cifrada) === CHAVE_ASAAS, 'a chave (sem os espaços) decifra');
confere(!str_contains(json_encode(linhasDe('entregas_asaas_conexoes')), CHAVE_ASAAS), 'no banco, só a chave cifrada');
$pub = ConexaoAsaas::publico($c, 'empresa-1');
confere(!str_contains(json_encode($pub), 'v1:') && !array_key_exists('chave_cifrada', $pub) && !array_key_exists('token_hash', $pub), 'público: nada da chave nem do token');
confere($pub['conectada'] === true && $pub['conta'] === ['nome' => 'Central Teste', 'documento' => '**.345.678/0001-**', 'wallet' => 'wallet-1'], 'público: conta com o documento escondido');
confere($pub['saque_ligado'] === false && $pub['token_gerado'] === false && $pub['validacao_confirmada_em'] === null, 'público: saque desligado, sem token, sem validação');
confere($pub['limites'] === ['minimo' => 10.0, 'maximo_dia' => 500.0, 'saques_dia' => 3, 'taxa' => 0.0], 'público: limites padrão: ' . json_encode($pub['limites']));
confere($pub['url_da_validacao'] === 'https://entregas-api.teste/v1/entregas/asaas/validar-saque', 'público: a URL para colar no Asaas');
confere(ConexaoAsaas::publico(null, 'empresa-1') === ['conectada' => false, 'ligada' => true, 'cofre' => true], 'sem conexão: só conectada false, interruptor e cofre');

echo '== Trocar a chave' . PHP_EOL;
asaas(200, ['name' => 'Outra Central']);
asaas(200, ['data' => [['id' => 'wallet-2']]]);
confere(mensagem(fn () => ConexaoAsaas::conectar('empresa-1', 'chave-de-outra-conta-0000', 'user-admin')) === 'Esta chave é de outra conta Asaas. Para mudar de conta, desconecte e conecte de novo.', 'chave de outra conta: recusada');
confere(Cofre::decifrar(conexao()->chave_cifrada) === CHAVE_ASAAS, 'a chave antiga continua');
asaas(200, ['name' => 'Central Teste']);
asaas(200, ['data' => [['id' => 'wallet-1']]]);
ConexaoAsaas::conectar('empresa-1', 'chave-nova-da-mesma-conta-999', 'user-2');
confere(Cofre::decifrar(conexao()->chave_cifrada) === 'chave-nova-da-mesma-conta-999' && conexao()->conectada_por === 'user-2' && count(linhasDe('entregas_asaas_conexoes')) === 1, 'mesma conta: troca a chave, uma linha só');

echo '== Token da validação' . PHP_EOL;
$token = ConexaoAsaas::gerarToken('empresa-1');
confere(strlen($token) === 48 && ctype_xdigit($token), 'token de 48 caracteres hexadecimais');
confere(conexao()->token_hash === hash('sha256', $token) && !str_contains(json_encode(linhasDe('entregas_asaas_conexoes')), $token), 'no banco, só o hash do token');
confere(ConexaoAsaas::publico(conexao(), 'empresa-1')['token_gerado'] === true, 'público: token gerado');
confere(mensagem(fn () => ConexaoAsaas::gerarToken('empresa-9')) === 'Conecte a conta Asaas primeiro.', 'sem conexão: não gera token');

echo '== Teste da validação' . PHP_EOL;
confere(mensagem(fn () => ConexaoAsaas::configurar('empresa-1', ['saque_ligado' => true], 'user-admin')) === 'Teste a validação de saque antes de ligar.', 'não liga sem a validação testada');
confere(mensagem(fn () => ConexaoAsaas::testarValidacao('empresa-1', 'cpf', '123')) === 'Chave Pix inválida para o tipo escolhido.', 'teste: chave Pix inválida');
$antes = count(HttpFalso::$chamadas);
asaas(200, ['id' => 'tra_teste', 'status' => 'PENDING']);
ConexaoAsaas::testarValidacao('empresa-1', 'cpf', '123.456.789-01');
$c = HttpFalso::$chamadas[$antes];
confere($c['metodo'] === 'POST' && $c['url'] === 'https://asaas.teste/v3/transfers' && $c['dados']['value'] === 1.0 && $c['dados']['pixAddressKey'] === '12345678901' && $c['dados']['pixAddressKeyType'] === 'CPF' && str_starts_with($c['dados']['externalReference'], 'teste:'), 'teste: R$ 1,00 para a chave do admin: ' . json_encode($c['dados']));
confere(($c['headers']['access_token'] ?? null) === 'chave-nova-da-mesma-conta-999', 'teste: com a chave da central decifrada');
confere(conexao()->teste_transfer_id === 'tra_teste' && conexao()->teste_pedido_em === '2026-10-27 10:00:00' && conexao()->validacao_confirmada_em === null, 'teste pedido: id gravado, validação ainda não confirmada');
asaas(400, ['errors' => [['description' => 'Saldo insuficiente.']]]);
confere(mensagem(fn () => ConexaoAsaas::testarValidacao('empresa-1', 'cpf', '12345678901')) === 'Saldo insuficiente.', 'teste recusado pelo Asaas: a mensagem dele');
HttpFalso::$respostas[] = 'conexao';
confere(excecao(fn () => ConexaoAsaas::testarValidacao('empresa-1', 'cpf', '12345678901')) instanceof ErroDoAsaas, 'teste sem resposta: ErroDoAsaas');

echo '== Limites e ligar' . PHP_EOL;
DB::table('entregas_asaas_conexoes')->where('company_uuid', 'empresa-1')->update(['validacao_confirmada_em' => '2026-10-27 10:00:05']);
ConexaoAsaas::configurar('empresa-1', ['saque_ligado' => true, 'minimo' => '20,00', 'maximo_dia' => 300, 'saques_dia' => '2', 'taxa' => '1.5'], 'user-admin');
confere(ConexaoAsaas::limites(conexao()) === ['minimo' => 20.0, 'maximo_dia' => 300.0, 'saques_dia' => 2, 'taxa' => 1.5] && (bool) conexao()->saque_ligado && conexao()->atualizada_por === 'user-admin', 'limites e saque ligado');
confere(mensagem(fn () => ConexaoAsaas::configurar('empresa-1', ['minimo' => 0.5], 'u')) === 'O mínimo por saque é de pelo menos R$ 1,00.', 'mínimo abaixo de R$ 1');
confere(mensagem(fn () => ConexaoAsaas::configurar('empresa-1', ['maximo_dia' => 10], 'u')) === 'O máximo por dia precisa ser maior ou igual ao mínimo por saque.', 'máximo abaixo do mínimo');
confere(mensagem(fn () => ConexaoAsaas::configurar('empresa-1', ['saques_dia' => 0], 'u')) === 'Saques por dia: de 1 a 20.', 'saques por dia 0');
confere(mensagem(fn () => ConexaoAsaas::configurar('empresa-1', ['taxa' => 25], 'u')) === 'A taxa vai de R$ 0,00 a R$ 10,00.', 'taxa acima de 10');
confere(mensagem(fn () => ConexaoAsaas::configurar('empresa-1', ['minimo' => 5, 'taxa' => 5], 'u')) === 'A taxa precisa ser menor que o mínimo por saque.', 'taxa igual ao mínimo');
confere(mensagem(fn () => ConexaoAsaas::configurar('empresa-1', ['minimo' => 'abc'], 'u')) === 'Valor inválido: mínimo por saque.', 'mínimo que não é número');
confere(mensagem(fn () => ConexaoAsaas::configurar('empresa-1', ['saque_ligado' => 'talvez'], 'u')) === 'Informe se o saque fica ligado.', 'ligado que não é sim/não');
confere(ConexaoAsaas::limites(conexao())['minimo'] === 20.0, 'recusa não muda nada');
ConexaoAsaas::configurar('empresa-1', ['saque_ligado' => false], 'u');
confere(!(bool) conexao()->saque_ligado, 'desligar sempre pode');

echo '== Token novo pede novo teste' . PHP_EOL;
ConexaoAsaas::configurar('empresa-1', ['saque_ligado' => true], 'u');
ConexaoAsaas::gerarToken('empresa-1');
confere(!(bool) conexao()->saque_ligado && conexao()->validacao_confirmada_em === null && conexao()->teste_transfer_id === null, 'gerar outro token desliga o saque e apaga a validação');

echo '== Desconectar' . PHP_EOL;
DB::table('entregas_saques')->insert(['uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'company_uuid' => 'empresa-1', 'driver_uuid' => 'driver-1', 'valor' => 10, 'chave_pix' => '1', 'tipo_chave' => 'cpf', 'situacao' => 'processando', 'external_reference' => 'saque:x', 'created_at' => '2026-10-27 09:00:00', 'updated_at' => '2026-10-27 09:00:00']);
confere(mensagem(fn () => ConexaoAsaas::desconectar('empresa-1', 'user-admin')) === 'Há saque em andamento; espere ele terminar.', 'não desconecta com saque em andamento');
DB::table('entregas_saques')->where('uuid', 'aaaaaaaa-0000-4000-8000-000000000001')->update(['situacao' => 'concluido']);
ConexaoAsaas::desconectar('empresa-1', 'user-admin');
confere(conexao() === null, 'desconectada: a linha (chave, token, limites) some');
confere(mensagem(fn () => ConexaoAsaas::desconectar('empresa-1', 'user-admin')) === 'A conta Asaas não está conectada.', 'desconectar de novo: erro');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/saque-conexao.php`
Expected: erro "Class App\Support\Entregas\Saques\AdminDaCentral not found".

- [ ] **Step 3: Implementar `AdminDaCentral`**

Crie `api/app/Support/Entregas/Saques/AdminDaCentral.php`:

```php
<?php

namespace App\Support\Entregas\Saques;

use Fleetbase\Models\CompanyUser;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: quem configura o saque Pix no console. O administrador do sistema (type admin) ou quem tem o
 * papel Administrator na organização da sessão (a mesma regra do BarrarMotoboyNoConsole para liberar o console). O resto
 * do Financeiro continua só com o administrador do sistema (pendência do negarSeNaoAdmin, ver o CLAUDE.md do Financeiro).
 */
final class AdminDaCentral
{
    public const PAPEL = 'Administrator';

    /** O usuário da sessão, se ele pode configurar o saque da organização da sessão; null se não pode. */
    public static function usuario(Request $request): ?object
    {
        $usuario = Auth::getUserFromSession($request);
        if (!$usuario) {
            return null;
        }
        if (method_exists($usuario, 'isAdmin') && $usuario->isAdmin()) {
            return $usuario;
        }
        $empresa = session('company');
        if (!$empresa) {
            return null;
        }
        // percorre os vínculos da empresa (no Fleetbase é um só; o teste monta mais de um para provar que o papel é o que vale)
        foreach (CompanyUser::where('user_uuid', $usuario->uuid)->where('company_uuid', $empresa)->get() as $vinculo) {
            if ($vinculo->hasRole(static::PAPEL)) {
                return $usuario;
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Implementar `ConexaoAsaas`**

Crie `api/app/Support/Entregas/Saques/ConexaoAsaas.php`:

```php
<?php

namespace App\Support\Entregas\Saques;

use App\Support\Entregas\Financeiro\ChavePix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Entregas RestaurantePro: a conta Asaas da central (entregas_asaas_conexoes, uma por empresa). Conectar confere a chave
 * no Asaas (dados comerciais e wallet) e grava só a chave cifrada (Cofre) e a wallet, que fica fixada: trocar a chave só
 * pela de uma conta com a mesma wallet. O token da validação de saque é guardado só como hash e aparece uma vez. O teste
 * da validação manda R$ 1,00 para uma chave Pix do admin, que a nossa validação recusa (ValidacaoDeSaque); recebido o
 * pedido com o token certo, o saque pode ser ligado.
 *
 * Erros de entrada: \InvalidArgumentException (422, mensagem pt-BR). Asaas fora do ar: ErroDoAsaas (502). Sem o secret:
 * CofreIndisponivel (503).
 */
final class ConexaoAsaas
{
    public const TAMANHO_MINIMO_DA_CHAVE = 20;

    public const VALOR_DO_TESTE = 1.0;

    public const CAMINHO_DA_VALIDACAO = '/v1/entregas/asaas/validar-saque';

    public static function daEmpresa(string $companyUuid): ?object
    {
        return DB::table(Saque::CONEXOES)->where('company_uuid', $companyUuid)->first();
    }

    /** A URL que a central cola no Asaas (Integrações → Mecanismos de segurança). */
    public static function urlDaValidacao(): string
    {
        return rtrim((string) config('app.url'), '/') . static::CAMINHO_DA_VALIDACAO;
    }

    /** Os limites da conexão (ou os padrões), já como números. */
    public static function limites(?object $conexao): array
    {
        if (!$conexao) {
            return Saque::PADROES;
        }

        return [
            'minimo'     => round((float) $conexao->minimo, 2),
            'maximo_dia' => round((float) $conexao->maximo_dia, 2),
            'saques_dia' => (int) $conexao->saques_dia,
            'taxa'       => round((float) $conexao->taxa, 2),
        ];
    }

    /** A conexão no formato do console: nada da chave nem do token. */
    public static function publico(?object $conexao, string $companyUuid): array
    {
        $base = ['conectada' => $conexao !== null, 'ligada' => Saque::ligada(), 'cofre' => Cofre::disponivel()];
        if (!$conexao) {
            return $base;
        }

        return $base + [
            'conta'                   => [
                'nome'      => $conexao->conta_nome,
                'documento' => static::documentoEscondido($conexao->conta_documento),
                'wallet'    => (string) $conexao->wallet_id,
            ],
            'conectada_em'            => Saque::iso($conexao->conectada_em, $companyUuid),
            'token_gerado'            => $conexao->token_hash !== null,
            'url_da_validacao'        => static::urlDaValidacao(),
            'validacao_confirmada_em' => Saque::iso($conexao->validacao_confirmada_em, $companyUuid),
            'teste_pedido_em'         => Saque::iso($conexao->teste_pedido_em, $companyUuid),
            'saque_ligado'            => (bool) $conexao->saque_ligado,
            'limites'                 => static::limites($conexao),
        ];
    }

    /** CPF "***.456.789-**", CNPJ "**.345.678/0001-**"; outro tamanho, só os 4 últimos. */
    public static function documentoEscondido(?string $documento): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $documento);
        if ($digitos === '') {
            return null;
        }
        if (strlen($digitos) === 11) {
            return '***.' . substr($digitos, 3, 3) . '.' . substr($digitos, 6, 3) . '-**';
        }
        if (strlen($digitos) === 14) {
            return '**.' . substr($digitos, 2, 3) . '.' . substr($digitos, 5, 3) . '/' . substr($digitos, 8, 4) . '-**';
        }

        return Saque::chaveEscondida($digitos, 'cpf');
    }

    /** Conecta (ou troca a chave pela de outra chave da MESMA conta). Devolve a linha gravada. */
    public static function conectar(string $companyUuid, $chaveApi, ?string $usuarioUuid): object
    {
        $chave = is_string($chaveApi) ? trim($chaveApi) : '';
        if (mb_strlen($chave) < static::TAMANHO_MINIMO_DA_CHAVE || preg_match('/\s/', $chave)) {
            throw new \InvalidArgumentException('Chave de API inválida.');
        }
        if (!Cofre::disponivel()) {
            throw new CofreIndisponivel();
        }

        $asaas    = new AsaasCliente($chave);
        $dados    = static::exigir($asaas->dadosComerciais())->dados ?? [];
        $carteira = AsaasCliente::walletDa(static::exigir($asaas->carteiras()));
        if ($carteira === null) {
            throw new \InvalidArgumentException('O Asaas não informou a carteira desta conta.');
        }

        $atual = static::daEmpresa($companyUuid);
        if ($atual && (string) $atual->wallet_id !== $carteira) {
            throw new \InvalidArgumentException('Esta chave é de outra conta Asaas. Para mudar de conta, desconecte e conecte de novo.');
        }

        $agora  = now()->format('Y-m-d H:i:s');
        $nome   = trim((string) ($dados['name'] ?? $dados['companyName'] ?? ''));
        $campos = [
            'chave_cifrada'   => Cofre::cifrar($chave),
            'conta_nome'      => $nome !== '' ? mb_substr($nome, 0, 255) : null,
            'conta_documento' => preg_replace('/\D/', '', (string) ($dados['cpfCnpj'] ?? '')) ?: null,
            'wallet_id'       => $carteira,
            'conectada_por'   => $usuarioUuid,
            'conectada_em'    => $agora,
            'updated_at'      => $agora,
        ];
        if ($atual) {
            DB::table(Saque::CONEXOES)->where('company_uuid', $companyUuid)->update($campos);
        } else {
            DB::table(Saque::CONEXOES)->insert($campos + ['company_uuid' => $companyUuid, 'created_at' => $agora]);
        }
        Log::info('[entregas] saque: conta Asaas conectada', ['empresa' => $companyUuid, 'wallet' => $carteira, 'por' => $usuarioUuid, 'troca' => (bool) $atual]);

        return static::daEmpresa($companyUuid);
    }

    /** Apaga a conexão inteira (chave, token, limites). Com saque em andamento, recusa: sem chave ninguém o acompanha. */
    public static function desconectar(string $companyUuid, ?string $usuarioUuid): void
    {
        if (!static::daEmpresa($companyUuid)) {
            throw new \InvalidArgumentException('A conta Asaas não está conectada.');
        }
        $emAndamento = DB::table(Saque::TABELA)->where('company_uuid', $companyUuid)->whereIn('situacao', Saque::EM_ANDAMENTO)->exists();
        if ($emAndamento) {
            throw new \InvalidArgumentException('Há saque em andamento; espere ele terminar.');
        }
        DB::table(Saque::CONEXOES)->where('company_uuid', $companyUuid)->delete();
        Log::info('[entregas] saque: conta Asaas desconectada', ['empresa' => $companyUuid, 'por' => $usuarioUuid]);
    }

    /** Gera o token da validação (devolvido só aqui). Token novo apaga a validação testada e desliga o saque. */
    public static function gerarToken(string $companyUuid): string
    {
        if (!static::daEmpresa($companyUuid)) {
            throw new \InvalidArgumentException('Conecte a conta Asaas primeiro.');
        }
        $token = bin2hex(random_bytes(24));
        $agora = now()->format('Y-m-d H:i:s');
        DB::table(Saque::CONEXOES)->where('company_uuid', $companyUuid)->update([
            'token_hash'              => hash('sha256', $token),
            'token_gerado_em'         => $agora,
            'validacao_confirmada_em' => null,
            'teste_transfer_id'       => null,
            'teste_pedido_em'         => null,
            'saque_ligado'            => false,
            'updated_at'              => $agora,
        ]);
        Log::info('[entregas] saque: token da validação gerado', ['empresa' => $companyUuid]);

        return $token;
    }

    /** Manda R$ 1,00 para a chave Pix do admin; a validação do Entregas precisa recusar (e aí confirma). */
    public static function testarValidacao(string $companyUuid, $tipo, $chavePix): object
    {
        $conexao = static::daEmpresa($companyUuid);
        if (!$conexao) {
            throw new \InvalidArgumentException('Conecte a conta Asaas primeiro.');
        }
        if ($conexao->token_hash === null) {
            throw new \InvalidArgumentException('Gere o token e configure a validação no Asaas antes de testar.');
        }
        $pix = ChavePix::validar($tipo, $chavePix);
        if (isset($pix['erro'])) {
            throw new \InvalidArgumentException($pix['erro']);
        }
        $chave = Cofre::decifrar((string) $conexao->chave_cifrada);
        if ($chave === null) {
            throw new CofreIndisponivel();
        }

        $agora = now()->format('Y-m-d H:i:s');
        DB::table(Saque::CONEXOES)->where('company_uuid', $companyUuid)->update([
            'teste_pedido_em' => $agora, 'teste_transfer_id' => null, 'validacao_confirmada_em' => null, 'updated_at' => $agora,
        ]);
        $resposta = (new AsaasCliente($chave))->transferirPix(static::VALOR_DO_TESTE, $pix['chave'], Saque::TIPOS_ASAAS[$pix['tipo']], 'Teste da validação de saque do Entregas', 'teste:' . Str::uuid());
        $id       = $resposta->dados['id'] ?? null;
        if ($resposta->ok() && is_string($id) && $id !== '') {
            // whereNull: se a validação chegou antes e já gravou o id, fica o dela
            DB::table(Saque::CONEXOES)->where('company_uuid', $companyUuid)->whereNull('teste_transfer_id')->update(['teste_transfer_id' => $id]);
        } elseif ($resposta->semResposta()) {
            throw new ErroDoAsaas('O Asaas não respondeu. Tente de novo.');
        } elseif (!$resposta->ok()) {
            throw new \InvalidArgumentException($resposta->mensagem('O Asaas recusou o teste.'));
        }
        Log::info('[entregas] saque: teste da validação pedido', ['empresa' => $companyUuid, 'transfer' => $id]);

        return static::daEmpresa($companyUuid);
    }

    /** Limites e o interruptor da central. Ligar exige a validação testada; desligar sempre pode. */
    public static function configurar(string $companyUuid, array $dados, ?string $usuarioUuid): object
    {
        $atual = static::daEmpresa($companyUuid);
        if (!$atual) {
            throw new \InvalidArgumentException('Conecte a conta Asaas primeiro.');
        }
        $campos = [];
        foreach (['minimo' => 'mínimo por saque', 'maximo_dia' => 'máximo por dia', 'taxa' => 'taxa'] as $campo => $rotulo) {
            if (array_key_exists($campo, $dados)) {
                $valor = static::numero($dados[$campo]);
                if ($valor === null) {
                    throw new \InvalidArgumentException("Valor inválido: {$rotulo}.");
                }
                $campos[$campo] = $valor;
            }
        }
        if (array_key_exists('saques_dia', $dados)) {
            $quantos = filter_var($dados['saques_dia'], FILTER_VALIDATE_INT);
            if ($quantos === false || $quantos < 1 || $quantos > 20) {
                throw new \InvalidArgumentException('Saques por dia: de 1 a 20.');
            }
            $campos['saques_dia'] = $quantos;
        }

        $final = array_merge(static::limites($atual), $campos);
        if ($final['minimo'] < 1) {
            throw new \InvalidArgumentException('O mínimo por saque é de pelo menos R$ 1,00.');
        }
        if ($final['maximo_dia'] < $final['minimo']) {
            throw new \InvalidArgumentException('O máximo por dia precisa ser maior ou igual ao mínimo por saque.');
        }
        if ($final['taxa'] < 0 || $final['taxa'] > 10) {
            throw new \InvalidArgumentException('A taxa vai de R$ 0,00 a R$ 10,00.');
        }
        if ($final['taxa'] >= $final['minimo']) {
            throw new \InvalidArgumentException('A taxa precisa ser menor que o mínimo por saque.');
        }

        if (array_key_exists('saque_ligado', $dados)) {
            $ligar = filter_var($dados['saque_ligado'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($ligar === null) {
                throw new \InvalidArgumentException('Informe se o saque fica ligado.');
            }
            if ($ligar && $atual->validacao_confirmada_em === null) {
                throw new \InvalidArgumentException('Teste a validação de saque antes de ligar.');
            }
            $campos['saque_ligado'] = $ligar;
        }

        $agora = now()->format('Y-m-d H:i:s');
        DB::table(Saque::CONEXOES)->where('company_uuid', $companyUuid)->update($campos + ['atualizada_por' => $usuarioUuid, 'updated_at' => $agora]);
        Log::info('[entregas] saque: configuração da central', ['empresa' => $companyUuid, 'por' => $usuarioUuid, 'campos' => array_keys($campos)]);

        return static::daEmpresa($companyUuid);
    }

    /** "10,50", "10.5", 10.5 → 10.5; outra coisa → null. */
    private static function numero($valor): ?float
    {
        if (is_int($valor) || is_float($valor)) {
            return round((float) $valor, 2);
        }
        if (is_string($valor) && preg_match('/^\d{1,7}([.,]\d{1,2})?$/', trim($valor))) {
            return round((float) str_replace(',', '.', trim($valor)), 2);
        }

        return null;
    }

    /** A resposta, se deu certo; chave recusada → 422 com a orientação; sem resposta ou outro erro → 502. */
    private static function exigir(RespostaAsaas $resposta): RespostaAsaas
    {
        if ($resposta->ok()) {
            return $resposta;
        }
        if ($resposta->chaveRecusada()) {
            throw new \InvalidArgumentException('Chave de API inválida ou sem permissão. Gere uma nova no Asaas (Integrações → Chaves de API).');
        }
        if ($resposta->semResposta()) {
            throw new ErroDoAsaas('O Asaas não respondeu. Tente de novo.');
        }

        throw new ErroDoAsaas($resposta->mensagem('O Asaas recusou o pedido.'));
    }
}
```

- [ ] **Step 5: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/saque-conexao.php`
Expected: `FALHAS: 0`.

- [ ] **Step 6: Commit**

```bash
git add api/app/Support/Entregas/Saques/AdminDaCentral.php api/app/Support/Entregas/Saques/ConexaoAsaas.php scripts/teste-php/saque-conexao.php
git commit -m "Saque Pix: conexão da central com o Asaas (chave cifrada, wallet fixada, token, teste da validação, limites)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Pushes do saque

**Files:**
- Create: `api/app/Notifications/Entregas/SaqueConcluido.php`, `SaqueNaoConcluido.php`, `PixDeSaqueAlterado.php`
- Modify: `api/app/Notifications/Entregas/AvisosDoMotoboy.php` (constante `CANAIS` e o `match` do `texto()`)
- Test: `scripts/teste-php/saque-avisos.php`

- [ ] **Step 1: Escrever o teste que falha**

Crie `scripts/teste-php/saque-avisos.php`:

```php
<?php

// Saque Pix: os pushes ao motoboy (saque concluído, não concluído e chave Pix alterada) e o canal/texto no AvisosDoMotoboy.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-avisos.php

require __DIR__ . '/stubs-saque.php';

use App\Notifications\Entregas\AvisosDoMotoboy;
use App\Notifications\Entregas\PixDeSaqueAlterado;
use App\Notifications\Entregas\SaqueConcluido;
use App\Notifications\Entregas\SaqueNaoConcluido;

$ok = new SaqueConcluido('aaaaaaaa-0000-4000-8000-000000000001', 150.0);
confere($ok->title === 'Saque concluído' && $ok->message === 'Seu saque de R$ 150,00 caiu no Pix.', 'concluído: ' . $ok->message);
confere($ok->data === ['type' => 'entregas_saque', 'saque' => 'aaaaaaaa-0000-4000-8000-000000000001'], 'concluído: dados com o uuid do saque');

$nao = new SaqueNaoConcluido('s-2', 80.5, 'Saldo insuficiente na conta da central.');
confere($nao->title === 'Saque não concluído' && $nao->message === 'O saque de R$ 80,50 não foi concluído e o valor voltou para o seu saldo. Motivo: Saldo insuficiente na conta da central.', 'não concluído: ' . $nao->message);
confere($nao->data === ['type' => 'entregas_saque', 'saque' => 's-2'], 'não concluído: dados');
$longo = new SaqueNaoConcluido('s-3', 10, str_repeat('x', 300));
confere(mb_strlen($longo->message) < 260, 'motivo longo é cortado (120)');

$pix = new PixDeSaqueAlterado('28/10 às 10:00');
confere($pix->title === 'Chave Pix alterada' && $pix->message === 'A central alterou a chave Pix dos seus saques. Novos saques a partir de 28/10 às 10:00. Se você não pediu a troca, fale com a central.', 'chave alterada: ' . $pix->message);
confere($pix->data === ['type' => 'entregas_pix_alterado'], 'chave alterada: dados');

confere((AvisosDoMotoboy::CANAIS['entregas_saque'] ?? null) === 'avisos' && (AvisosDoMotoboy::CANAIS['entregas_pix_alterado'] ?? null) === 'avisos', 'canal "avisos" do app para os dois tipos');
confere(AvisosDoMotoboy::texto($ok) === [$ok->title, $ok->message] && AvisosDoMotoboy::texto($nao) === [$nao->title, $nao->message] && AvisosDoMotoboy::texto($pix) === [$pix->title, $pix->message], 'AvisosDoMotoboy conhece as três (sem o aviso "sem tradução")');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/saque-avisos.php`
Expected: erro "Class App\Notifications\Entregas\SaqueConcluido not found".

- [ ] **Step 3: Implementar as notificações**

Crie `api/app/Notifications/Entregas/SaqueConcluido.php`:

```php
<?php

namespace App\Notifications\Entregas;

use Fleetbase\Support\PushNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;

/**
 * Entregas RestaurantePro: o saque Pix do motoboy caiu (o Asaas respondeu DONE). Só push, canal "avisos" do app
 * (AvisosDoMotoboy). Os dados levam o uuid do saque (`saque`), não `id`: o app abre a carteira pelo tipo (TIPO).
 */
class SaqueConcluido extends Notification implements ShouldQueue
{
    use Queueable;

    public const TIPO = 'entregas_saque';

    public string $title;

    public string $message;

    public array $data;

    public function __construct(public string $saque, public float $valor)
    {
        $this->title   = 'Saque concluído';
        $this->message = 'Seu saque de ' . PagamentoRegistrado::reais($valor) . ' caiu no Pix.';
        $this->data    = ['type' => self::TIPO, 'saque' => $saque];
    }

    public function via($notifiable)
    {
        return [FcmChannel::class];
    }

    public function toFcm($notifiable)
    {
        return PushNotification::createFcmMessage($this->title, $this->message, $this->data);
    }
}
```

Crie `api/app/Notifications/Entregas/SaqueNaoConcluido.php`:

```php
<?php

namespace App\Notifications\Entregas;

use Fleetbase\Support\PushNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;

/**
 * Entregas RestaurantePro: o saque Pix do motoboy falhou e o valor (e a taxa) voltou ao saldo (Saques::falhar). Só push,
 * canal "avisos". O motivo vem do Asaas ou do Entregas (até 120 caracteres no texto).
 */
class SaqueNaoConcluido extends Notification implements ShouldQueue
{
    use Queueable;

    public const TIPO = 'entregas_saque';

    public string $title;

    public string $message;

    public array $data;

    public function __construct(public string $saque, public float $valor, public string $motivo)
    {
        $this->title   = 'Saque não concluído';
        $this->message = 'O saque de ' . PagamentoRegistrado::reais($valor) . ' não foi concluído e o valor voltou para o seu saldo. Motivo: ' . mb_substr(trim($motivo), 0, 120);
        $this->data    = ['type' => self::TIPO, 'saque' => $saque];
    }

    public function via($notifiable)
    {
        return [FcmChannel::class];
    }

    public function toFcm($notifiable)
    {
        return PushNotification::createFcmMessage($this->title, $this->message, $this->data);
    }
}
```

Crie `api/app/Notifications/Entregas/PixDeSaqueAlterado.php`:

```php
<?php

namespace App\Notifications\Entregas;

use Fleetbase\Support\PushNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;

/**
 * Entregas RestaurantePro: a central trocou a chave Pix do motoboy (FinanceiroController@salvarPix). O saque fica bloqueado
 * por 24 h (Saque::CARENCIA_PIX_HORAS) e o aviso deixa o motoboy reclamar se não foi ele quem pediu.
 */
class PixDeSaqueAlterado extends Notification implements ShouldQueue
{
    use Queueable;

    public const TIPO = 'entregas_pix_alterado';

    public string $title;

    public string $message;

    public array $data;

    /** @param string $liberadoEm "28/10 às 10:00" (no fuso da empresa) */
    public function __construct(public string $liberadoEm)
    {
        $this->title   = 'Chave Pix alterada';
        $this->message = 'A central alterou a chave Pix dos seus saques. Novos saques a partir de ' . $liberadoEm . '. Se você não pediu a troca, fale com a central.';
        $this->data    = ['type' => self::TIPO];
    }

    public function via($notifiable)
    {
        return [FcmChannel::class];
    }

    public function toFcm($notifiable)
    {
        return PushNotification::createFcmMessage($this->title, $this->message, $this->data);
    }
}
```

- [ ] **Step 4: Ensinar o `AvisosDoMotoboy`**

Em `api/app/Notifications/Entregas/AvisosDoMotoboy.php`, na constante `CANAIS`, depois da linha `'entregas_pagamento'      => 'avisos',`, acrescente:

```php
        // saque Pix pelo app: concluído ou não (SaqueConcluido, SaqueNaoConcluido) e chave Pix trocada (PixDeSaqueAlterado)
        'entregas_saque'          => 'avisos',
        'entregas_pix_alterado'   => 'avisos',
```

No `match (true)` do método `texto()`, depois da linha do `PagamentoRegistrado`, acrescente:

```php
            $notificacao instanceof SaqueConcluido       => [$notificacao->title, $notificacao->message],
            $notificacao instanceof SaqueNaoConcluido    => [$notificacao->title, $notificacao->message],
            $notificacao instanceof PixDeSaqueAlterado   => [$notificacao->title, $notificacao->message],
```

(Mesmo namespace: não precisa de `use`.)

- [ ] **Step 5: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/saque-avisos.php` e `.../avisos-push.php`
Expected: os dois com `FALHAS: 0`.

- [ ] **Step 6: Commit**

```bash
git add api/app/Notifications/Entregas/SaqueConcluido.php api/app/Notifications/Entregas/SaqueNaoConcluido.php api/app/Notifications/Entregas/PixDeSaqueAlterado.php api/app/Notifications/Entregas/AvisosDoMotoboy.php scripts/teste-php/saque-avisos.php
git commit -m "Saque Pix: pushes de saque concluído, não concluído e chave Pix alterada

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Troca da chave Pix (carência e aviso) e bloqueio por motoboy

**Files:**
- Modify: `api/app/Support/Entregas/Financeiro/ChavePix.php` (método `salvar`)
- Modify: `api/app/Http/Controllers/Entregas/FinanceiroController.php` (`salvarPix` e um método novo `avisarPixAlterado`)
- Create: `api/app/Support/Entregas/Saques/Saques.php` (primeira parte: `linhaDoMotoboy`, `bloquear`, `liberadoEm`)
- Test: `scripts/teste-php/saque-pix-alterada.php`

- [ ] **Step 1: Escrever o teste que falha**

Crie `scripts/teste-php/saque-pix-alterada.php`:

```php
<?php

// Saque Pix: a troca da chave Pix do motoboy marca quem e quando (carência de 24 h) e avisa o motoboy; bloqueio do saque
// por motoboy.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-pix-alterada.php

require __DIR__ . '/stubs-saque.php';

use App\Http\Controllers\Entregas\FinanceiroController;
use App\Notifications\Entregas\PixDeSaqueAlterado;
use App\Support\Entregas\Financeiro\ChavePix;
use App\Support\Entregas\Saques\Saques;
use Fleetbase\FleetOps\Models\Driver;
use Illuminate\Http\Request;
use Teste\Relogio;
use Teste\UsuarioDoSaque;

echo '== ChavePix::salvar' . PHP_EOL;
reiniciarSaque();
confere(ChavePix::salvar('empresa-1', 'driver-1', '11999994321', 'telefone', 'user-admin') === false, 'primeiro cadastro: não é troca');
$linha = Saques::linhaDoMotoboy('empresa-1', 'driver-1');
confere($linha->pix_alterada_em === null && $linha->pix_alterada_por === null, 'primeiro cadastro: sem carência');
confere(ChavePix::salvar('empresa-1', 'driver-1', '11999994321', 'telefone', 'user-admin') === false, 'a mesma chave de novo: não é troca');
Relogio::$agora = '2026-10-27 11:00:00';
confere(ChavePix::salvar('empresa-1', 'driver-1', '12345678901', 'cpf', 'user-admin') === true, 'chave diferente: troca');
$linha = Saques::linhaDoMotoboy('empresa-1', 'driver-1');
confere($linha->pix_alterada_em === '2026-10-27 11:00:00' && $linha->pix_alterada_por === 'user-admin' && $linha->chave_pix === '12345678901', 'troca: quem e quando');
confere(ChavePix::salvar('empresa-1', 'driver-1', '12345678901', 'cpf') === false, 'o salvar antigo (4 argumentos) continua valendo');

echo '== Carência (Saques::liberadoEm)' . PHP_EOL;
confere(Saques::liberadoEm($linha) === '2026-10-28 11:00:00', 'liberado 24 h depois da troca');
Relogio::$agora = '2026-10-28 11:00:01';
confere(Saques::liberadoEm($linha) === null, 'passadas as 24 h: sem carência');
confere(Saques::liberadoEm(null) === null, 'sem linha: sem carência');

echo '== Bloqueio' . PHP_EOL;
Saques::bloquear('empresa-1', 'driver-1', true);
confere((bool) Saques::linhaDoMotoboy('empresa-1', 'driver-1')->saque_bloqueado === true, 'bloqueia');
Saques::bloquear('empresa-1', 'driver-1', false);
confere((bool) Saques::linhaDoMotoboy('empresa-1', 'driver-1')->saque_bloqueado === false, 'desbloqueia');
Saques::bloquear('empresa-1', 'driver-9', true);
$nova = Saques::linhaDoMotoboy('empresa-1', 'driver-9');
confere($nova && (bool) $nova->saque_bloqueado && $nova->chave_pix === null, 'motoboy sem linha: cria a linha bloqueada, sem chave');

echo '== FinanceiroController@salvarPix avisa o motoboy' . PHP_EOL;
reiniciarSaque();
Relogio::$agora = '2026-10-27 10:00:00';
session(['company' => 'empresa-1']);
\Fleetbase\Support\Auth::$usuario = new UsuarioDoSaque('user-admin', 'admin');
Driver::$todos[] = new Driver(['uuid' => 'driver-1', 'public_id' => 'driver_1', 'company_uuid' => 'empresa-1', 'user_uuid' => 'user-m1', 'name' => 'João Silva']);
$controller = new FinanceiroController();
$r = $controller->salvarPix(new Request(['tipo' => 'telefone', 'chave' => '11999994321']), 'driver_1');
confere($r->status === 200 && Driver::$avisos === [], 'primeiro cadastro: sem aviso');
$r = $controller->salvarPix(new Request(['tipo' => 'cpf', 'chave' => '123.456.789-01']), 'driver_1');
$aviso = Driver::$avisos[0][1] ?? null;
confere($r->status === 200 && $aviso instanceof PixDeSaqueAlterado && str_contains($aviso->message, '28/10 às 10:00'), 'troca: push com a hora em que o saque volta: ' . ($aviso->message ?? 'nenhum'));
confere(Saques::linhaDoMotoboy('empresa-1', 'driver-1')->pix_alterada_por === 'user-admin', 'troca pelo console: grava o admin');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/saque-pix-alterada.php`
Expected: erro "Class App\Support\Entregas\Saques\Saques not found" (ou FALHA no retorno do `salvar`).

- [ ] **Step 3: `ChavePix::salvar` devolve se trocou**

Em `api/app/Support/Entregas/Financeiro/ChavePix.php`, troque o método `salvar` inteiro por:

```php
    /**
     * Grava (cria ou troca) a chave do motoboy. Devolve true quando TROCOU uma chave que já existia por outra (chave ou tipo
     * diferente): aí grava quem e quando (pix_alterada_por/_em), o que bloqueia o saque Pix por 24 h (Saques::liberadoEm).
     * O primeiro cadastro e a mesma chave de novo não contam como troca.
     */
    public static function salvar(string $companyUuid, string $driverUuid, string $chave, string $tipo, ?string $usuarioUuid = null): bool
    {
        $agora  = now()->format('Y-m-d H:i:s');
        $atual  = DB::table(static::TABELA)->where('driver_uuid', $driverUuid)->first();
        $antiga = $atual ? (string) ($atual->chave_pix ?? '') : '';
        $trocou = $antiga !== '' && ($antiga !== $chave || (string) $atual->tipo_chave !== $tipo);

        $campos = ['company_uuid' => $companyUuid, 'chave_pix' => $chave, 'tipo_chave' => $tipo, 'updated_at' => $agora];
        if ($trocou) {
            $campos['pix_alterada_em']  = $agora;
            $campos['pix_alterada_por'] = $usuarioUuid;
        }
        if ($atual) {
            DB::table(static::TABELA)->where('driver_uuid', $driverUuid)->update($campos);
        } else {
            DB::table(static::TABELA)->insert($campos + ['driver_uuid' => $driverUuid, 'created_at' => $agora]);
        }

        return $trocou;
    }
```

E troque a frase do docblock da classe "Só registro: o dinheiro sai pelo app do banco." por "O saque Pix pelo app usa esta chave (App\Support\Entregas\Saques); a troca bloqueia o saque por 24 h.".

- [ ] **Step 4: Primeira parte do `Saques`**

Crie `api/app/Support/Entregas/Saques/Saques.php`:

```php
<?php

namespace App\Support\Entregas\Saques;

use App\Support\Entregas\Financeiro\ChavePix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: o saque Pix do motoboy (entregas_saques) e o que ele exige da linha do motoboy no Financeiro
 * (entregas_motoboys_financeiro: chave Pix, bloqueio, troca da chave). Desenho:
 * docs/superpowers/specs/2026-10-26-saque-pix-asaas-design.md.
 */
final class Saques
{
    /** A linha do motoboy no Financeiro (chave Pix, bloqueio, troca); com $travar, lockForUpdate (dentro de transação). */
    public static function linhaDoMotoboy(string $companyUuid, string $driverUuid, bool $travar = false): ?object
    {
        $consulta = DB::table(ChavePix::TABELA)->where('company_uuid', $companyUuid)->where('driver_uuid', $driverUuid);
        if ($travar) {
            $consulta->lockForUpdate();
        }

        return $consulta->first();
    }

    /** Bloqueia ou libera o saque do motoboy (cria a linha sem chave Pix, se não houver). */
    public static function bloquear(string $companyUuid, string $driverUuid, bool $bloqueado): void
    {
        $agora = now()->format('Y-m-d H:i:s');
        if (static::linhaDoMotoboy($companyUuid, $driverUuid)) {
            DB::table(ChavePix::TABELA)->where('company_uuid', $companyUuid)->where('driver_uuid', $driverUuid)->update(['saque_bloqueado' => $bloqueado, 'updated_at' => $agora]);
        } else {
            DB::table(ChavePix::TABELA)->insert(['company_uuid' => $companyUuid, 'driver_uuid' => $driverUuid, 'saque_bloqueado' => $bloqueado, 'created_at' => $agora, 'updated_at' => $agora]);
        }
        Log::info('[entregas] saque: bloqueio do motoboy', ['empresa' => $companyUuid, 'motoboy' => $driverUuid, 'bloqueado' => $bloqueado]);
    }

    /** Até quando ('Y-m-d H:i:s') o saque fica bloqueado pela troca da chave Pix; null se não há carência agora. */
    public static function liberadoEm(?object $linha): ?string
    {
        $alterada = $linha->pix_alterada_em ?? null;
        if (!$alterada) {
            return null;
        }
        $liberado = (new \DateTimeImmutable((string) $alterada))->modify('+' . Saque::CARENCIA_PIX_HORAS . ' hours')->format('Y-m-d H:i:s');

        return $liberado > now()->format('Y-m-d H:i:s') ? $liberado : null;
    }
}
```

- [ ] **Step 5: `FinanceiroController@salvarPix` avisa a troca**

Em `api/app/Http/Controllers/Entregas/FinanceiroController.php`:

1. Nos `use`, acrescente (em ordem alfabética com os outros):

```php
use App\Notifications\Entregas\PixDeSaqueAlterado;
use App\Support\Entregas\Saques\Saque;
```

2. No `salvarPix`, troque a linha `ChavePix::salvar($this->empresa(), (string) $motoboy->uuid, $pix['chave'], $pix['tipo']);` por:

```php
        if (ChavePix::salvar($this->empresa(), (string) $motoboy->uuid, $pix['chave'], $pix['tipo'], $this->usuario($request))) {
            $this->avisarPixAlterado($motoboy);
        }
```

3. Logo depois do método `avisarMotoboy`, acrescente:

```php
    /** O push "Chave Pix alterada" ao motoboy, com a hora em que o saque volta (24 h); uma falha só fica no log. */
    protected function avisarPixAlterado(Driver $motoboy): void
    {
        try {
            $liberado = (new \DateTimeImmutable(now()->format('Y-m-d H:i:s'), new \DateTimeZone((string) (config('app.timezone') ?: Financeiro::FUSO_PADRAO))))
                ->modify('+' . Saque::CARENCIA_PIX_HORAS . ' hours')
                ->setTimezone(new \DateTimeZone(Financeiro::fuso($this->empresa())));
            $motoboy->notify(new PixDeSaqueAlterado($liberado->format('d/m') . ' às ' . $liberado->format('H:i')));
        } catch (\Throwable $e) {
            Log::warning('[entregas] saque: push da troca da chave Pix não saiu', ['motoboy' => $motoboy->uuid ?? null, 'erro' => get_class($e)]);
        }
    }
```

- [ ] **Step 6: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/saque-pix-alterada.php`
Expected: `FALHAS: 0`.

Rode também `financeiro-console.php`: ele chama o `salvarPix` duas vezes no mesmo motoboy com chaves diferentes, então agora sai um `PixDeSaqueAlterado` no `Driver::$avisos`. Se algum `confere` desse teste contar os avisos (procure `Driver::$avisos` no arquivo), ajuste o número esperado e escreva no texto do caso que a troca de chave avisa o motoboy. Nenhum outro caso pode mudar.

- [ ] **Step 7: Commit**

```bash
git add api/app/Support/Entregas/Financeiro/ChavePix.php api/app/Http/Controllers/Entregas/FinanceiroController.php api/app/Support/Entregas/Saques/Saques.php scripts/teste-php/saque-pix-alterada.php scripts/teste-php/financeiro-console.php
git commit -m "Saque Pix: troca da chave Pix marca quem/quando, avisa o motoboy e bloqueia o saque por 24 h; bloqueio por motoboy

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Disponível e pedido de saque (reserva no livro)

**Files:**
- Create: `api/app/Support/Entregas/Saques/SaqueRecusado.php`
- Modify: `api/app/Support/Entregas/Saques/Saques.php` (acrescentar métodos)
- Test: `scripts/teste-php/saque-pedido.php`

- [ ] **Step 1: Escrever o teste que falha**

Crie `scripts/teste-php/saque-pedido.php`:

```php
<?php

// Saque Pix: o que o motoboy pode sacar (Saques::situacaoDoMotoboy) e o pedido (Saques::pedir): conferências, limites,
// idempotência pelo uuid do app e a reserva no livro do Financeiro (pagamento Pix + taxa).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-pedido.php

require __DIR__ . '/stubs-saque.php';

use App\Support\Entregas\Financeiro\ChavePix;
use App\Support\Entregas\Financeiro\ExtratoDaConta;
use App\Support\Entregas\Financeiro\Lancamentos;
use App\Support\Entregas\Financeiro\Pagamentos;
use App\Support\Entregas\Saques\SaqueRecusado;
use App\Support\Entregas\Saques\Saques;
use Illuminate\Support\Facades\DB;
use Teste\Config;
use Teste\Relogio;

const U1 = 'aaaaaaaa-0000-4000-8000-000000000001';
const U2 = 'aaaaaaaa-0000-4000-8000-000000000002';
const U3 = 'aaaaaaaa-0000-4000-8000-000000000003';

function recusa(callable $fazer): ?string
{
    $erro = excecao($fazer);

    return $erro instanceof SaqueRecusado ? $erro->getMessage() : ($erro ? get_class($erro) . ': ' . $erro->getMessage() : null);
}

function situacao(): ?array
{
    return Saques::situacaoDoMotoboy('empresa-1', 'driver-1');
}

function saldo(string $driver = 'driver-1'): float
{
    return ExtratoDaConta::saldo('empresa-1', 'motoboy', $driver);
}

echo '== Disponível' . PHP_EOL;
centralComSaque();
confere(situacao() === ['disponivel' => 100.0, 'minimo' => 10.0, 'maximo_hoje' => 500.0, 'taxa' => 0.0, 'em_andamento' => null, 'indisponivel_motivo' => null], 'saldo 100, padrões: ' . json_encode(situacao(), JSON_UNESCAPED_UNICODE));
Config::$valores['services.entregas.saque'] = null;
confere(situacao() === null, 'ENTREGAS_SAQUE desligada: sem bloco (o app esconde o botão)');
Config::$valores['services.entregas.saque'] = '1';
DB::table('entregas_asaas_conexoes')->where('company_uuid', 'empresa-1')->update(['saque_ligado' => false]);
confere(situacao() === null, 'central com o saque desligado: sem bloco');
DB::table('entregas_asaas_conexoes')->where('company_uuid', 'empresa-1')->update(['saque_ligado' => true, 'validacao_confirmada_em' => null]);
confere(situacao() === null, 'validação não confirmada: sem bloco');
DB::table('entregas_asaas_conexoes')->where('company_uuid', 'empresa-1')->update(['validacao_confirmada_em' => '2026-10-26 09:00:00']);

echo '== Pedido recusado' . PHP_EOL;
confere(recusa(fn () => Saques::pedir('empresa-1', 'driver-1', 'nao-e-uuid', 50)) === 'Pedido de saque inválido.', 'uuid inválido');
confere(recusa(fn () => Saques::pedir('empresa-1', 'driver-1', U1, 'abc')) === 'Informe o valor do saque.', 'valor que não é número');
confere(recusa(fn () => Saques::pedir('empresa-1', 'driver-1', U1, 0)) === 'Informe o valor do saque.', 'valor zero');
confere(recusa(fn () => Saques::pedir('empresa-1', 'driver-1', U1, 5)) === 'O valor mínimo do saque é R$ 10,00.', 'abaixo do mínimo');
confere(recusa(fn () => Saques::pedir('empresa-1', 'driver-1', U1, 100.01)) === 'Valor acima do disponível para saque (R$ 100,00).', 'acima do disponível');
Config::$valores['services.entregas.saque'] = null;
confere(recusa(fn () => Saques::pedir('empresa-1', 'driver-1', U1, 50)) === 'Saque Pix indisponível no momento.', 'chave geral desligada');
Config::$valores['services.entregas.saque'] = '1';
DB::table('entregas_asaas_conexoes')->where('company_uuid', 'empresa-1')->update(['saque_ligado' => false]);
confere(recusa(fn () => Saques::pedir('empresa-1', 'driver-1', U1, 50)) === 'A central não liberou o saque Pix.', 'central com o saque desligado');
DB::table('entregas_asaas_conexoes')->where('company_uuid', 'empresa-1')->update(['saque_ligado' => true]);
confere(linhasDe('entregas_saques') === [] && linhasDe('entregas_pagamentos') === [], 'recusas não gravam nada');

echo '== Pedido aceito' . PHP_EOL;
$r     = Saques::pedir('empresa-1', 'driver-1', strtoupper(U1), '60,50');
$saque = $r['saque'];
confere($r['novo'] === true && $saque->uuid === U1 && $saque->situacao === 'enviando' && (float) $saque->valor === 60.5 && (float) $saque->taxa === 0.0, 'saque "enviando" de R$ 60,50 (uuid em minúsculas)');
confere($saque->chave_pix === '11999994321' && $saque->tipo_chave === 'telefone' && $saque->external_reference === 'saque:' . U1 && $saque->transfer_id === null, 'chave Pix copiada do cadastro; externalReference estável');
$pagamento = Pagamentos::porUuid('empresa-1', (string) $saque->pagamento_uuid);
confere($pagamento && (float) $pagamento->valor === 60.5 && $pagamento->forma === 'pix' && $pagamento->criado_por === null && $pagamento->observacao_interna === 'Saque Pix pelo app' && $pagamento->data === '2026-10-27', 'pagamento Pix do sistema no livro');
confere(saldo() === 39.5, 'o saldo já desconta o saque: ' . saldo());
confere($saque->taxa_lancamento_id === null, 'sem taxa: sem lançamento de taxa');

echo '== Idempotência' . PHP_EOL;
$de_novo = Saques::pedir('empresa-1', 'driver-1', U1, 60.5);
confere($de_novo['novo'] === false && (int) $de_novo['saque']->id === (int) $saque->id && count(linhasDe('entregas_saques')) === 1 && count(linhasDe('entregas_pagamentos')) === 1, 'o mesmo uuid devolve o mesmo saque, sem outro pagamento');
motoboyComSaldo('driver-2', 'Maria Souza');
confere(recusa(fn () => Saques::pedir('empresa-1', 'driver-2', U1, 20)) === 'Pedido de saque inválido.', 'uuid de outro motoboy: inválido');

echo '== Um por vez' . PHP_EOL;
confere(recusa(fn () => Saques::pedir('empresa-1', 'driver-1', U2, 10)) === 'Já há um saque em andamento.', 'com saque em andamento, outro não');
$s = situacao();
confere(($s['em_andamento']['id'] ?? null) === U1 && $s['indisponivel_motivo'] === 'Saque em andamento.', 'situação: o saque em andamento e o motivo');

echo '== Limites do dia' . PHP_EOL;
DB::table('entregas_saques')->where('uuid', U1)->update(['situacao' => 'concluido']);
DB::table('entregas_asaas_conexoes')->where('company_uuid', 'empresa-1')->update(['saques_dia' => 1]);
confere(situacao()['indisponivel_motivo'] === 'Limite de saques de hoje atingido.' && recusa(fn () => Saques::pedir('empresa-1', 'driver-1', U2, 10)) === 'Limite de saques de hoje atingido.', 'saques por dia');
DB::table('entregas_asaas_conexoes')->where('company_uuid', 'empresa-1')->update(['saques_dia' => 3, 'maximo_dia' => 70]);
confere(situacao()['maximo_hoje'] === 9.5 && situacao()['indisponivel_motivo'] === 'Limite de hoje atingido.', 'máximo do dia: sobra 9,50, abaixo do mínimo');
DB::table('entregas_saques')->where('uuid', U1)->update(['situacao' => 'falhou']);
confere(situacao()['maximo_hoje'] === 70.0, 'saque que falhou não conta no dia');
Relogio::$agora = '2026-10-28 00:00:01';
DB::table('entregas_saques')->where('uuid', U1)->update(['situacao' => 'concluido']);
confere(situacao()['maximo_hoje'] === 70.0, 'dia novo: limite cheio de novo');

echo '== Taxa' . PHP_EOL;
centralComSaque(['taxa' => 2.0]);
confere(situacao()['disponivel'] === 98.0 && situacao()['taxa'] === 2.0, 'disponível = saldo − taxa');
confere(recusa(fn () => Saques::pedir('empresa-1', 'driver-1', U3, 98.01)) === 'Valor acima do disponível para saque (R$ 98,00).', 'a taxa entra no limite');
$saque = Saques::pedir('empresa-1', 'driver-1', U3, 98)['saque'];
$taxa  = Lancamentos::porId('empresa-1', (int) $saque->taxa_lancamento_id);
confere($taxa && $taxa->tipo === 'ajuste' && (float) $taxa->valor === -2.0 && $taxa->motivo === 'Taxa do saque Pix' && $taxa->pagamento_uuid === null && (float) $saque->taxa === 2.0, 'taxa: ajuste −2,00, sem pagamento_uuid');
confere(saldo() === 0.0, 'saldo zerado (98 + 2)');

echo '== Impedimentos do motoboy' . PHP_EOL;
centralComSaque();
DB::table(ChavePix::TABELA)->where('driver_uuid', 'driver-1')->update(['chave_pix' => null]);
confere(situacao()['indisponivel_motivo'] === 'Sem chave Pix cadastrada: fale com a central.' && recusa(fn () => Saques::pedir('empresa-1', 'driver-1', U1, 10)) === 'Sem chave Pix cadastrada: fale com a central.', 'sem chave Pix');
DB::table(ChavePix::TABELA)->where('driver_uuid', 'driver-1')->update(['chave_pix' => '11999994321']);
Saques::bloquear('empresa-1', 'driver-1', true);
confere(recusa(fn () => Saques::pedir('empresa-1', 'driver-1', U1, 10)) === 'Saque bloqueado pela central.', 'bloqueado');
Saques::bloquear('empresa-1', 'driver-1', false);
ChavePix::salvar('empresa-1', 'driver-1', '21988887777', 'telefone', 'user-admin');
confere(recusa(fn () => Saques::pedir('empresa-1', 'driver-1', U1, 10)) === 'Chave Pix alterada; saque liberado às 10:00 de 28/10.', 'carência depois da troca');
Relogio::$agora = '2026-10-28 10:00:01';
Lancamentos::inserir(['company_uuid' => 'empresa-1', 'conta_tipo' => 'motoboy', 'conta_uuid' => 'driver-1', 'tipo' => 'ajuste', 'valor' => -95, 'data_referencia' => '2026-10-28', 'motivo' => 'Desconto']);
confere(situacao()['indisponivel_motivo'] === 'Saldo abaixo do mínimo para saque (R$ 10,00).', 'saldo 5 < mínimo');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/saque-pedido.php`
Expected: erro "Call to undefined method …Saques::situacaoDoMotoboy()".

- [ ] **Step 3: `SaqueRecusado`**

Crie `api/app/Support/Entregas/Saques/SaqueRecusado.php`:

```php
<?php

namespace App\Support\Entregas\Saques;

/** Entregas RestaurantePro: o pedido de saque não passou nas conferências (o app recebe 422 com a mensagem). */
class SaqueRecusado extends \RuntimeException
{
}
```

- [ ] **Step 4: Métodos do pedido no `Saques`**

Em `api/app/Support/Entregas/Saques/Saques.php`:

1. Troque os `use` por:

```php
use App\Support\Entregas\Financeiro\ChavePix;
use App\Support\Entregas\Financeiro\ExtratoDaConta;
use App\Support\Entregas\Financeiro\Financeiro;
use App\Support\Entregas\Financeiro\Lancamentos;
use App\Support\Entregas\Financeiro\Pagamentos;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
```

2. Logo no começo da classe, antes de `linhaDoMotoboy`, acrescente a constante:

```php
    public const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
```

3. Depois de `liberadoEm`, acrescente:

```php
    /**
     * O bloco `saque` da "Minha carteira" do app: {disponivel, minimo, maximo_hoje, taxa, em_andamento, indisponivel_motivo};
     * null quando o saque não está liberado na central (chave geral, saque ligado e validação testada): o app esconde o botão.
     */
    public static function situacaoDoMotoboy(string $companyUuid, string $driverUuid): ?array
    {
        if (!Saque::ligada()) {
            return null;
        }
        $conexao = ConexaoAsaas::daEmpresa($companyUuid);
        if (!static::liberadoNaCentral($conexao)) {
            return null;
        }
        $limites   = ConexaoAsaas::limites($conexao);
        $andamento = static::emAndamento($companyUuid, $driverUuid);
        $calculo   = static::calculo($companyUuid, $driverUuid, $limites);

        return [
            'disponivel'          => Saque::reais($calculo['disponivel']),
            'minimo'              => $limites['minimo'],
            'maximo_hoje'         => Saque::reais($calculo['restante_do_dia']),
            'taxa'                => $limites['taxa'],
            'em_andamento'        => $andamento ? static::publico($andamento) : null,
            'indisponivel_motivo' => $andamento ? 'Saque em andamento.' : static::impedimento(static::linhaDoMotoboy($companyUuid, $driverUuid), $limites, $calculo),
        ];
    }

    /**
     * Pede o saque: confere tudo e, numa transação com a linha do motoboy travada, grava o saque "enviando", o pagamento
     * Pix no livro (o saldo já desconta) e a taxa. O $uuid vem do app: repetido devolve o mesmo saque (`novo` false).
     * Recusas: SaqueRecusado com a mensagem pt-BR. Quem chama manda ao Asaas depois do commit (enviar).
     *
     * @return array{saque: object, novo: bool}
     */
    public static function pedir(string $companyUuid, string $driverUuid, $uuid, $valor): array
    {
        if (!Saque::ligada()) {
            throw new SaqueRecusado('Saque Pix indisponível no momento.');
        }
        $uuid = is_string($uuid) ? strtolower(trim($uuid)) : '';
        if (!preg_match(static::UUID, $uuid)) {
            throw new SaqueRecusado('Pedido de saque inválido.');
        }
        if ($repetido = static::repetido($companyUuid, $driverUuid, $uuid)) {
            return ['saque' => $repetido, 'novo' => false];
        }
        $centavos = static::valorEmCentavos($valor);
        if ($centavos === null) {
            throw new SaqueRecusado('Informe o valor do saque.');
        }
        $conexao = ConexaoAsaas::daEmpresa($companyUuid);
        if (!static::liberadoNaCentral($conexao)) {
            throw new SaqueRecusado('A central não liberou o saque Pix.');
        }
        $limites = ConexaoAsaas::limites($conexao);

        try {
            $saque = DB::transaction(function () use ($companyUuid, $driverUuid, $uuid, $centavos, $limites) {
                // a linha do motoboy travada: dois pedidos ao mesmo tempo não passam juntos pelas conferências
                $linha = static::linhaDoMotoboy($companyUuid, $driverUuid, true);
                if (static::emAndamento($companyUuid, $driverUuid)) {
                    throw new SaqueRecusado('Já há um saque em andamento.');
                }
                $calculo = static::calculo($companyUuid, $driverUuid, $limites);
                if ($motivo = static::impedimento($linha, $limites, $calculo)) {
                    throw new SaqueRecusado($motivo);
                }
                $minimo = Saque::centavos($limites['minimo']);
                if ($centavos < $minimo) {
                    throw new SaqueRecusado('O valor mínimo do saque é ' . Saque::dinheiro(Saque::reais($minimo)) . '.');
                }
                if ($centavos > $calculo['disponivel']) {
                    throw new SaqueRecusado('Valor acima do disponível para saque (' . Saque::dinheiro(Saque::reais($calculo['disponivel'])) . ').');
                }

                $hoje      = Financeiro::hoje($companyUuid);
                $pagamento = Pagamentos::registrar($companyUuid, Financeiro::CONTA_MOTOBOY, $driverUuid, Saque::reais($centavos), 'pix', $hoje, null, Saque::OBSERVACAO_DO_PAGAMENTO, null);
                $taxa      = Saque::centavos($limites['taxa']);
                $taxaId    = $taxa > 0 ? Lancamentos::inserir([
                    'company_uuid'    => $companyUuid,
                    'conta_tipo'      => Financeiro::CONTA_MOTOBOY,
                    'conta_uuid'      => $driverUuid,
                    'tipo'            => 'ajuste',
                    'valor'           => -Saque::reais($taxa),
                    'data_referencia' => $hoje,
                    'motivo'          => Saque::MOTIVO_DA_TAXA,
                ]) : null;

                $agora = now()->format('Y-m-d H:i:s');
                DB::table(Saque::TABELA)->insert([
                    'uuid'               => $uuid,
                    'company_uuid'       => $companyUuid,
                    'driver_uuid'        => $driverUuid,
                    'valor'              => Saque::reais($centavos),
                    'taxa'               => Saque::reais($taxa),
                    'chave_pix'          => (string) $linha->chave_pix,
                    'tipo_chave'         => (string) $linha->tipo_chave,
                    'situacao'           => Saque::ENVIANDO,
                    'external_reference' => 'saque:' . $uuid,
                    'pagamento_uuid'     => (string) $pagamento->uuid,
                    'taxa_lancamento_id' => $taxaId,
                    'created_at'         => $agora,
                    'updated_at'         => $agora,
                ]);

                return DB::table(Saque::TABELA)->where('uuid', $uuid)->first();
            });
        } catch (QueryException $e) {
            // o mesmo uuid em dois pedidos simultâneos: a chave única segura o segundo, que devolve o primeiro
            if ($repetido = static::repetido($companyUuid, $driverUuid, $uuid)) {
                return ['saque' => $repetido, 'novo' => false];
            }
            throw $e;
        }

        Log::info('[entregas] saque: pedido', ['empresa' => $companyUuid, 'motoboy' => $driverUuid, 'saque' => $uuid, 'valor' => Saque::reais($centavos)]);

        return ['saque' => $saque, 'novo' => true];
    }

    /** O saque em andamento (enviando ou processando) do motoboy, ou null. */
    public static function emAndamento(string $companyUuid, string $driverUuid): ?object
    {
        return DB::table(Saque::TABELA)
            ->where('company_uuid', $companyUuid)
            ->where('driver_uuid', $driverUuid)
            ->whereIn('situacao', Saque::EM_ANDAMENTO)
            ->orderBy('id', 'desc')
            ->first();
    }

    /** O saque no formato do app e do console: {id, valor, taxa, situacao, motivo, chave, tipo_chave, comprovante_url, criado_em, concluido_em}. */
    public static function publico(object $saque): array
    {
        $empresa = (string) $saque->company_uuid;

        return [
            'id'              => (string) $saque->uuid,
            'valor'           => round((float) $saque->valor, 2),
            'taxa'            => round((float) $saque->taxa, 2),
            'situacao'        => (string) $saque->situacao,
            'motivo'          => $saque->motivo_falha,
            'chave'           => Saque::chaveEscondida((string) $saque->chave_pix, (string) $saque->tipo_chave),
            'tipo_chave'      => (string) $saque->tipo_chave,
            'comprovante_url' => $saque->comprovante_url,
            'criado_em'       => Saque::iso($saque->created_at, $empresa),
            'concluido_em'    => Saque::iso($saque->concluido_em, $empresa),
        ];
    }

    /** O saque relido do banco. */
    public static function recarregar(object $saque): object
    {
        return DB::table(Saque::TABELA)->where('id', $saque->id)->first() ?? $saque;
    }

    private static function liberadoNaCentral(?object $conexao): bool
    {
        return $conexao !== null && (bool) $conexao->saque_ligado && $conexao->validacao_confirmada_em !== null;
    }

    /** O saque com este uuid, se for deste motoboy; de outro motoboy ou empresa, recusa. null se não existe. */
    private static function repetido(string $companyUuid, string $driverUuid, string $uuid): ?object
    {
        $saque = DB::table(Saque::TABELA)->where('uuid', $uuid)->first();
        if (!$saque) {
            return null;
        }
        if ((string) $saque->company_uuid !== $companyUuid || (string) $saque->driver_uuid !== $driverUuid) {
            throw new SaqueRecusado('Pedido de saque inválido.');
        }

        return $saque;
    }

    /** 50, 50.5, "50,50", "50.5" → centavos; outra coisa, zero ou negativo → null. */
    private static function valorEmCentavos($valor): ?int
    {
        if (is_int($valor) || is_float($valor)) {
            $centavos = Saque::centavos($valor);

            return $centavos > 0 ? $centavos : null;
        }
        if (is_string($valor) && preg_match('/^\d{1,7}([.,]\d{1,2})?$/', trim($valor))) {
            $centavos = Saque::centavos(str_replace(',', '.', trim($valor)));

            return $centavos > 0 ? $centavos : null;
        }

        return null;
    }

    /**
     * Em centavos: saldo, sacado e número de saques de hoje (os que não falharam; dia no fuso da empresa, que é o do
     * servidor), o que ainda cabe no máximo do dia e o disponível = min(saldo − taxa, restante do dia).
     */
    private static function calculo(string $companyUuid, string $driverUuid, array $limites): array
    {
        $saldo   = Saque::centavos(ExtratoDaConta::saldo($companyUuid, Financeiro::CONTA_MOTOBOY, $driverUuid));
        $inicio  = Financeiro::hoje($companyUuid) . ' 00:00:00';
        $sacado  = 0;
        $quantos = 0;
        $doDia   = DB::table(Saque::TABELA)
            ->where('company_uuid', $companyUuid)
            ->where('driver_uuid', $driverUuid)
            ->where('created_at', '>=', $inicio)
            ->get();
        foreach ($doDia as $saque) {
            if ($saque->situacao === Saque::FALHOU) {
                continue;
            }
            $sacado += Saque::centavos($saque->valor);
            $quantos++;
        }
        $restante = max(0, Saque::centavos($limites['maximo_dia']) - $sacado);

        return [
            'saldo'           => $saldo,
            'sacado_hoje'     => $sacado,
            'saques_hoje'     => $quantos,
            'restante_do_dia' => $restante,
            'disponivel'      => max(0, min($saldo - Saque::centavos($limites['taxa']), $restante)),
        ];
    }

    /** O motivo de o motoboy não poder sacar agora (fora o saque em andamento), ou null. */
    private static function impedimento(?object $linha, array $limites, array $calculo): ?string
    {
        if (!$linha || ($linha->chave_pix ?? null) === null || $linha->chave_pix === '') {
            return 'Sem chave Pix cadastrada: fale com a central.';
        }
        if ((bool) ($linha->saque_bloqueado ?? false)) {
            return 'Saque bloqueado pela central.';
        }
        if ($liberado = static::liberadoEm($linha)) {
            $quando = new \DateTimeImmutable($liberado);

            return 'Chave Pix alterada; saque liberado às ' . $quando->format('H:i') . ' de ' . $quando->format('d/m') . '.';
        }
        if ($calculo['saques_hoje'] >= (int) $limites['saques_dia']) {
            return 'Limite de saques de hoje atingido.';
        }
        $minimo = Saque::centavos($limites['minimo']);
        if ($calculo['restante_do_dia'] < $minimo) {
            return 'Limite de hoje atingido.';
        }
        if ($calculo['disponivel'] < $minimo) {
            return 'Saldo abaixo do mínimo para saque (' . Saque::dinheiro($limites['minimo']) . ').';
        }

        return null;
    }
```

- [ ] **Step 5: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/saque-pedido.php`
Expected: `FALHAS: 0`.

- [ ] **Step 6: Commit**

```bash
git add api/app/Support/Entregas/Saques/SaqueRecusado.php api/app/Support/Entregas/Saques/Saques.php scripts/teste-php/saque-pedido.php
git commit -m "Saque Pix: disponível e pedido do motoboy (limites, um por vez, idempotência, pagamento e taxa no livro)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Envio ao Asaas, conclusão, falha e reembolso

**Files:**
- Modify: `api/app/Support/Entregas/Saques/Saques.php`
- Test: `scripts/teste-php/saque-envio.php`

- [ ] **Step 1: Escrever o teste que falha**

Crie `scripts/teste-php/saque-envio.php`:

```php
<?php

// Saque Pix: o envio ao Asaas (Saques::enviar), a conclusão, a falha com reembolso (estorno do pagamento e da taxa) e os
// pushes. Nunca dá por falho o que pode ter saído (sem resposta no POST fica "enviando").
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-envio.php

require __DIR__ . '/stubs-saque.php';

use App\Notifications\Entregas\SaqueConcluido;
use App\Notifications\Entregas\SaqueNaoConcluido;
use App\Support\Entregas\Financeiro\ExtratoDaConta;
use App\Support\Entregas\Financeiro\Lancamentos;
use App\Support\Entregas\Financeiro\Pagamentos;
use App\Support\Entregas\Saques\Saques;
use Fleetbase\FleetOps\Models\Driver;
use Illuminate\Support\Facades\DB;
use Teste\Http as HttpFalso;

const U1 = 'aaaaaaaa-0000-4000-8000-000000000001';

function saldo(): float
{
    return ExtratoDaConta::saldo('empresa-1', 'motoboy', 'driver-1');
}

function ultimoAviso()
{
    $ultimo = end(Driver::$avisos);

    return $ultimo ? $ultimo[1] : null;
}

function pedido(float $valor): object
{
    return Saques::pedir('empresa-1', 'driver-1', U1, $valor)['saque'];
}

echo '== Enviado e processando' . PHP_EOL;
centralComSaque();
$s = saqueEnviado('driver-1', U1, 50, ['id' => 'tra_1', 'status' => 'PENDING']);
confere($s->situacao === 'processando' && $s->transfer_id === 'tra_1' && $s->asaas_status === 'PENDING', 'processando com o id do Asaas');
$post = HttpFalso::$chamadas[1];
confere($post['url'] === 'https://asaas.teste/v3/transfers' && $post['dados'] === ['value' => 50.0, 'operationType' => 'PIX', 'pixAddressKey' => '11999994321', 'pixAddressKeyType' => 'PHONE', 'description' => 'Saque Entregas', 'externalReference' => 'saque:' . U1], 'POST com a chave do banco e o externalReference: ' . json_encode($post['dados']));
confere(($post['headers']['access_token'] ?? null) === CHAVE_ASAAS && HttpFalso::$chamadas[0]['url'] === 'https://asaas.teste/v3/wallets', 'confere a wallet antes, com a chave decifrada');
confere(Driver::$avisos === [], 'processando: sem push ainda');

echo '== DONE na hora' . PHP_EOL;
centralComSaque();
$s = saqueEnviado('driver-1', U1, 20, ['id' => 'tra_2', 'status' => 'DONE', 'transactionReceiptUrl' => 'https://asaas.teste/comprovantes/2']);
confere($s->situacao === 'concluido' && $s->comprovante_url === 'https://asaas.teste/comprovantes/2' && $s->concluido_em === '2026-10-27 10:00:00' && $s->asaas_status === 'DONE', 'concluído com o comprovante');
confere(ultimoAviso() instanceof SaqueConcluido && ultimoAviso()->message === 'Seu saque de R$ 20,00 caiu no Pix.', 'push de concluído');
confere(saldo() === 80.0, 'o saldo continua descontado');

echo '== Recusado pelo Asaas' . PHP_EOL;
centralComSaque();
$s = pedido(50);
asaas(200, ['data' => [['id' => 'wallet-1']]]);
asaas(400, ['errors' => [['description' => 'Saldo insuficiente.']]]);
$s = Saques::enviar($s);
confere($s->situacao === 'falhou' && $s->motivo_falha === 'Saldo insuficiente.', 'falhou com a mensagem do Asaas');
$pagamento = Pagamentos::porUuid('empresa-1', (string) $s->pagamento_uuid);
confere($pagamento->estornado_em !== null && saldo() === 100.0, 'pagamento estornado: o dinheiro volta ao saldo');
$estorno = DB::table('entregas_lancamentos')->where('tipo', 'estorno')->first();
confere($estorno && $estorno->motivo === 'Pagamento estornado' && $estorno->criado_por === null, 'estorno com o motivo que o app reconhece, pelo sistema');
confere(ultimoAviso() instanceof SaqueNaoConcluido && str_ends_with(ultimoAviso()->message, 'Motivo: Saldo insuficiente.'), 'push de não concluído com o motivo');

echo '== Sem resposta no POST: fica enviando' . PHP_EOL;
centralComSaque();
$s = pedido(50);
asaas(200, ['data' => [['id' => 'wallet-1']]]);
HttpFalso::$respostas[] = 'conexao';
$s = Saques::enviar($s);
confere($s->situacao === 'enviando' && $s->transfer_id === null && Pagamentos::porUuid('empresa-1', (string) $s->pagamento_uuid)->estornado_em === null && saldo() === 50.0, 'pode ter saído: nada é estornado (a rotina confere)');
centralComSaque();
$s = pedido(50);
asaas(200, ['data' => [['id' => 'wallet-1']]]);
asaas(200, ['status' => 'PENDING']);
confere(Saques::enviar($s)->situacao === 'enviando', '200 sem id: também fica enviando');

echo '== Antes do POST: falha e reembolsa' . PHP_EOL;
centralComSaque();
$s = pedido(50);
asaas(401, null);
$s = Saques::enviar($s);
confere($s->situacao === 'falhou' && $s->motivo_falha === 'A chave do Asaas da central foi recusada. Avise a central.' && count(HttpFalso::$chamadas) === 1 && saldo() === 100.0, 'chave recusada no /wallets: falha sem chamar o /transfers');
centralComSaque();
$s = pedido(50);
HttpFalso::$respostas[] = 'conexao';
confere(Saques::enviar($s)->motivo_falha === 'O Asaas não respondeu. Tente de novo.', 'Asaas fora no /wallets: falha (nada saiu)');
centralComSaque();
$s = pedido(50);
asaas(200, ['data' => [['id' => 'wallet-OUTRA']]]);
confere(Saques::enviar($s)->motivo_falha === 'A chave do Asaas não é mais da conta conectada. Avise a central.' && count(HttpFalso::$chamadas) === 1, 'wallet diferente: falha sem transferir');
centralComSaque();
$s = pedido(50);
unlink(CHAVE_DO_COFRE);
confere(Saques::enviar($s)->motivo_falha === 'Saque Pix indisponível no momento. Avise a central.' && HttpFalso::$chamadas === [], 'sem o secret: falha sem chamar o Asaas');

echo '== Taxa devolvida e falha uma vez só' . PHP_EOL;
centralComSaque(['taxa' => 2.0]);
$s = pedido(50);
confere(saldo() === 48.0, 'saque 50 + taxa 2');
confere(Saques::falhar($s, 'Teste') === true, 'falhar');
confere(saldo() === 100.0 && Lancamentos::jaEstornados([(int) $s->taxa_lancamento_id]) === [(int) $s->taxa_lancamento_id], 'a taxa também volta');
$taxaDevolvida = DB::table('entregas_lancamentos')->where('estorno_de', (int) $s->taxa_lancamento_id)->first();
confere($taxaDevolvida->motivo === 'Taxa do saque devolvida', 'motivo da taxa devolvida');
$avisos = count(Driver::$avisos);
confere(Saques::falhar(Saques::recarregar($s), 'De novo') === false && saldo() === 100.0 && count(Driver::$avisos) === $avisos, 'falhar de novo: nada muda, sem outro push');
confere(Saques::concluir(Saques::recarregar($s), null) === false && Saques::recarregar($s)->situacao === 'falhou', 'concluir depois de falhar: nada muda');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/saque-envio.php`
Expected: erro "Call to undefined method …Saques::enviar()".

- [ ] **Step 3: Implementar envio, conclusão e falha**

Em `api/app/Support/Entregas/Saques/Saques.php`:

1. Acrescente aos `use`:

```php
use App\Notifications\Entregas\SaqueConcluido;
use App\Notifications\Entregas\SaqueNaoConcluido;
use Fleetbase\FleetOps\Models\Driver;
```

2. Depois de `recarregar`, acrescente:

```php
    /**
     * Manda o saque ao Asaas (depois do commit do pedido): decifra a chave, confere a wallet fixada e cria a transferência
     * Pix com a chave do banco e o externalReference estável. Antes do POST, qualquer problema falha e reembolsa (nada
     * saiu). No POST: id → processando (ou já concluído/falho); recusa 4xx → falha e reembolsa; sem resposta, 5xx ou 200
     * sem id → fica "enviando" e a rotina procura pelo externalReference. Devolve o saque relido.
     */
    public static function enviar(object $saque): object
    {
        $conexao = ConexaoAsaas::daEmpresa((string) $saque->company_uuid);
        $chave   = $conexao ? Cofre::decifrar((string) $conexao->chave_cifrada) : null;
        if ($chave === null) {
            static::falhar($saque, 'Saque Pix indisponível no momento. Avise a central.');

            return static::recarregar($saque);
        }

        $asaas     = new AsaasCliente($chave);
        $carteiras = $asaas->carteiras();
        if (!$carteiras->ok()) {
            static::falhar($saque, $carteiras->semResposta() ? 'O Asaas não respondeu. Tente de novo.' : 'A chave do Asaas da central foi recusada. Avise a central.');

            return static::recarregar($saque);
        }
        if (AsaasCliente::walletDa($carteiras) !== (string) $conexao->wallet_id) {
            Log::warning('[entregas] saque: a chave do Asaas é de outra carteira', ['empresa' => $saque->company_uuid]);
            static::falhar($saque, 'A chave do Asaas não é mais da conta conectada. Avise a central.');

            return static::recarregar($saque);
        }

        $resposta = $asaas->transferirPix(
            Saque::reais(Saque::centavos($saque->valor)),
            (string) $saque->chave_pix,
            Saque::TIPOS_ASAAS[(string) $saque->tipo_chave] ?? '',
            Saque::DESCRICAO_NO_ASAAS,
            (string) $saque->external_reference
        );
        $id = $resposta->dados['id'] ?? null;
        if ($resposta->ok() && is_string($id) && $id !== '') {
            static::registrarTransferencia($saque, $resposta->dados);
        } elseif ($resposta->ok() || $resposta->semResposta()) {
            Log::warning('[entregas] saque: envio sem confirmação do Asaas; a rotina confere', ['saque' => $saque->uuid, 'status' => $resposta->status]);
        } else {
            static::falhar($saque, $resposta->mensagem('O Asaas recusou a transferência.'));
        }

        return static::recarregar($saque);
    }

    /** Aplica o status de uma transferência do Asaas ao saque. true se ele terminou agora (concluído ou falho). */
    public static function aplicarStatus(object $saque, array $dados): bool
    {
        $status   = strtoupper((string) ($dados['status'] ?? ''));
        $situacao = Saque::situacaoDoAsaas($status);
        if ($situacao === Saque::CONCLUIDO) {
            $url = $dados['transactionReceiptUrl'] ?? null;

            return static::concluir($saque, is_string($url) && $url !== '' ? mb_substr($url, 0, 500) : null);
        }
        if ($situacao === Saque::FALHOU) {
            $motivo = is_string($dados['failReason'] ?? null) && trim($dados['failReason']) !== ''
                ? trim($dados['failReason'])
                : ($status === 'CANCELLED' ? 'Transferência cancelada no Asaas.' : 'Transferência não concluída pelo Asaas.');

            return static::falhar($saque, $motivo, $status);
        }
        if ($status !== '') {
            DB::table(Saque::TABELA)->where('id', $saque->id)->update(['asaas_status' => mb_substr($status, 0, 30), 'updated_at' => now()->format('Y-m-d H:i:s')]);
        }

        return false;
    }

    /** Conclui o saque em andamento (guarda o comprovante do Asaas) e avisa o motoboy. false se ele não estava em andamento. */
    public static function concluir(object $saque, ?string $comprovante): bool
    {
        $agora = now()->format('Y-m-d H:i:s');
        $feito = DB::table(Saque::TABELA)
            ->where('id', $saque->id)
            ->whereIn('situacao', Saque::EM_ANDAMENTO)
            ->update(['situacao' => Saque::CONCLUIDO, 'asaas_status' => 'DONE', 'comprovante_url' => $comprovante, 'concluido_em' => $agora, 'updated_at' => $agora]);
        if ($feito < 1) {
            return false;
        }
        Log::info('[entregas] saque: concluído', ['empresa' => $saque->company_uuid, 'saque' => $saque->uuid, 'valor' => round((float) $saque->valor, 2)]);
        static::avisar($saque, new SaqueConcluido((string) $saque->uuid, (float) $saque->valor));

        return true;
    }

    /**
     * Falha o saque em andamento e, na mesma transação, estorna o pagamento (motivo "Pagamento estornado", que o app
     * reconhece) e a taxa; depois avisa o motoboy. false se ele não estava em andamento (nada muda, nada é estornado).
     */
    public static function falhar(object $saque, string $motivo, ?string $statusDoAsaas = null): bool
    {
        $feito = DB::transaction(function () use ($saque, $motivo, $statusDoAsaas) {
            $agora = now()->format('Y-m-d H:i:s');
            $n     = DB::table(Saque::TABELA)
                ->where('id', $saque->id)
                ->whereIn('situacao', Saque::EM_ANDAMENTO)
                ->update([
                    'situacao'     => Saque::FALHOU,
                    'motivo_falha' => mb_substr($motivo, 0, 255),
                    'asaas_status' => $statusDoAsaas !== null && $statusDoAsaas !== '' ? mb_substr($statusDoAsaas, 0, 30) : null,
                    'updated_at'   => $agora,
                ]);
            if ($n < 1) {
                return false;
            }
            $pagamento = $saque->pagamento_uuid ? Pagamentos::porUuid((string) $saque->company_uuid, (string) $saque->pagamento_uuid) : null;
            if ($pagamento) {
                Pagamentos::estornar($pagamento, null);
            }
            $taxa = $saque->taxa_lancamento_id ? Lancamentos::porId((string) $saque->company_uuid, (int) $saque->taxa_lancamento_id) : null;
            if ($taxa) {
                Lancamentos::estornar($taxa, null, Saque::MOTIVO_TAXA_DEVOLVIDA);
            }

            return true;
        });
        if (!$feito) {
            return false;
        }
        Log::info('[entregas] saque: não concluído, valor devolvido', ['empresa' => $saque->company_uuid, 'saque' => $saque->uuid, 'motivo' => $motivo]);
        static::avisar($saque, new SaqueNaoConcluido((string) $saque->uuid, (float) $saque->valor, $motivo));

        return true;
    }

    /** Grava o id da transferência (o da validação, se ela chegou antes, fica) e passa a processando; aplica o status. */
    private static function registrarTransferencia(object $saque, array $dados): void
    {
        $agora = now()->format('Y-m-d H:i:s');
        DB::table(Saque::TABELA)->where('id', $saque->id)->whereNull('transfer_id')->update(['transfer_id' => (string) $dados['id'], 'updated_at' => $agora]);
        DB::table(Saque::TABELA)->where('id', $saque->id)->where('situacao', Saque::ENVIANDO)->update(['situacao' => Saque::PROCESSANDO, 'updated_at' => $agora]);
        if (($dados['authorized'] ?? null) === false) {
            Log::warning('[entregas] saque: transferência aguardando autorização no Asaas (token)', ['saque' => $saque->uuid]);
        }
        static::aplicarStatus(static::recarregar($saque), $dados);
    }

    /** Push ao motoboy do saque; uma falha só vai ao log. */
    private static function avisar(object $saque, object $notificacao): void
    {
        try {
            $motoboy = Driver::where('company_uuid', (string) $saque->company_uuid)->where('uuid', (string) $saque->driver_uuid)->first();
            $motoboy?->notify($notificacao);
        } catch (\Throwable $e) {
            Log::warning('[entregas] saque: push não saiu', ['saque' => $saque->uuid ?? null, 'erro' => get_class($e)]);
        }
    }
```

- [ ] **Step 4: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/saque-envio.php` e `.../saque-pedido.php`
Expected: os dois com `FALHAS: 0`.

- [ ] **Step 5: Commit**

```bash
git add api/app/Support/Entregas/Saques/Saques.php scripts/teste-php/saque-envio.php
git commit -m "Saque Pix: envio ao Asaas (wallet conferida), conclusão, falha com estorno do pagamento e da taxa, pushes

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Validação de saque (APPROVED/REFUSED)

**Files:**
- Create: `api/app/Support/Entregas/Saques/ValidacaoDeSaque.php`
- Test: `scripts/teste-php/saque-validacao.php`

- [ ] **Step 1: Escrever o teste que falha**

Crie `scripts/teste-php/saque-validacao.php`:

```php
<?php

// Saque Pix: a validação de saque do Asaas (ValidacaoDeSaque::decidir). Só o saque nosso em andamento, com o mesmo id,
// valor e chave, é aprovado; o teste da central é recusado e confirma a configuração.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-validacao.php

require __DIR__ . '/stubs-saque.php';

use App\Support\Entregas\Saques\ConexaoAsaas;
use App\Support\Entregas\Saques\Saques;
use App\Support\Entregas\Saques\ValidacaoDeSaque;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Teste\Config;
use Teste\Relogio;

const U1 = 'aaaaaaaa-0000-4000-8000-000000000001';
const U2 = 'aaaaaaaa-0000-4000-8000-000000000002';

function pedidoDoAsaas(string $id, $valor, ?string $chave = '+5511999994321', string $tipo = 'TRANSFER'): array
{
    return ['type' => $tipo, 'transfer' => ['object' => 'transfer', 'id' => $id, 'value' => $valor, 'status' => 'PENDING', 'operationType' => 'PIX', 'bankAccount' => ['pixAddressKey' => $chave]]];
}

function recusa(string $motivo): array
{
    return [200, ['status' => 'REFUSED', 'refuseReason' => $motivo]];
}

echo '== Saque nosso' . PHP_EOL;
centralComSaque();
saqueEnviado('driver-1', U1, 50, ['id' => 'tra_1', 'status' => 'PENDING']);
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_1', 50)) === [200, ['status' => 'APPROVED']], 'id, valor e chave (com +55) conferem: APPROVED');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_1', 50, null)) === [200, ['status' => 'APPROVED']], 'sem a chave no pedido: confere id e valor');
$log = json_encode(Log::$registros, JSON_UNESCAPED_UNICODE);
confere(str_contains($log, '[entregas] saque: validação') && str_contains($log, 'APPROVED') && !str_contains($log, TOKEN_DE_TESTE), 'decisão no log, sem o token');

echo '== Recusas' . PHP_EOL;
confere(ValidacaoDeSaque::decidir('token-errado', pedidoDoAsaas('tra_1', 50)) === [401, ['status' => 'REFUSED', 'refuseReason' => 'Token inválido.']], 'token errado: 401');
confere(ValidacaoDeSaque::decidir(null, pedidoDoAsaas('tra_1', 50))[0] === 401 && ValidacaoDeSaque::decidir('', pedidoDoAsaas('tra_1', 50))[0] === 401, 'sem token: 401');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_1', 50, null, 'BILL')) === recusa('Operação não reconhecida pelo Entregas.'), 'pagamento de conta: recusado');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, ['type' => 'TRANSFER']) === recusa('Operação não reconhecida pelo Entregas.'), 'sem transfer: recusado');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_X', 50)) === recusa('Transferência não reconhecida pelo Entregas.'), 'id desconhecido: recusado (chave vazada não tira dinheiro)');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_1', 49.99)) === recusa('Valor diferente do saque pedido.'), 'valor diferente');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_1', 50, '21988887777')) === recusa('Chave Pix diferente da cadastrada.'), 'chave diferente');
DB::table('entregas_asaas_conexoes')->insert(['company_uuid' => 'empresa-2', 'chave_cifrada' => 'x', 'wallet_id' => 'w2', 'token_hash' => hash('sha256', 'token-da-outra')]);
confere(ValidacaoDeSaque::decidir('token-da-outra', pedidoDoAsaas('tra_1', 50)) === recusa('Transferência não reconhecida pelo Entregas.'), 'token de outra central: não acha o saque desta');
DB::table('entregas_saques')->where('uuid', U1)->update(['situacao' => 'concluido']);
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_1', 50)) === recusa('Transferência não reconhecida pelo Entregas.'), 'saque já concluído: não aprova de novo');
Config::$valores['services.entregas.saque'] = null;
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_1', 50)) === recusa('Saque Pix desligado no Entregas.'), 'chave geral desligada: recusa tudo');
Config::$valores['services.entregas.saque'] = '1';

echo '== O Asaas chamou antes de gravarmos o id' . PHP_EOL;
centralComSaque();
$s = Saques::pedir('empresa-1', 'driver-1', U2, 30)['saque'];
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_9', 30)) === [200, ['status' => 'APPROVED']], 'saque "enviando" recente, mesmo valor: aprovado');
confere(Saques::recarregar($s)->transfer_id === 'tra_9', 'e o id fica gravado nele');
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_10', 30)) === recusa('Transferência não reconhecida pelo Entregas.'), 'outro id para o mesmo saque: recusado');
centralComSaque();
Saques::pedir('empresa-1', 'driver-1', U2, 30);
Relogio::$agora = '2026-10-27 10:02:01';
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_9', 30)) === recusa('Transferência não reconhecida pelo Entregas.'), 'mais de 2 min depois: recusado');

echo '== Teste da central' . PHP_EOL;
centralComSaque(['validacao_confirmada_em' => null, 'saque_ligado' => false, 'teste_transfer_id' => 'tra_teste', 'teste_pedido_em' => '2026-10-27 09:59:58']);
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_teste', 1)) === recusa('Teste do Entregas: validação funcionando.'), 'o teste é RECUSADO');
confere(ConexaoAsaas::daEmpresa('empresa-1')->validacao_confirmada_em === '2026-10-27 10:00:00', 'e confirma a validação');
centralComSaque(['validacao_confirmada_em' => null, 'saque_ligado' => false, 'teste_transfer_id' => null, 'teste_pedido_em' => '2026-10-27 09:59:58']);
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_t2', 1)) === recusa('Teste do Entregas: validação funcionando.') && ConexaoAsaas::daEmpresa('empresa-1')->teste_transfer_id === 'tra_t2', 'teste antes de gravarmos o id: reconhecido pelo R$ 1,00 recente');
centralComSaque(['validacao_confirmada_em' => null, 'saque_ligado' => false, 'teste_transfer_id' => null, 'teste_pedido_em' => '2026-10-27 09:59:58']);
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_t3', 2)) === recusa('Transferência não reconhecida pelo Entregas.') && ConexaoAsaas::daEmpresa('empresa-1')->validacao_confirmada_em === null, 'valor diferente de R$ 1,00: não é o teste');
Config::$valores['services.entregas.saque'] = null;
confere(ValidacaoDeSaque::decidir(TOKEN_DE_TESTE, pedidoDoAsaas('tra_t4', 1)) === recusa('Teste do Entregas: validação funcionando.'), 'o teste funciona com a chave geral desligada (para configurar antes de ligar)');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/saque-validacao.php`
Expected: erro "Class App\Support\Entregas\Saques\ValidacaoDeSaque not found".

- [ ] **Step 3: Implementar**

Crie `api/app/Support/Entregas/Saques/ValidacaoDeSaque.php`:

```php
<?php

namespace App\Support\Entregas\Saques;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: a resposta à validação de saque do Asaas (Integrações → Mecanismos de segurança na conta da
 * central). O Asaas pergunta antes de cada transferência por API; só executa com APPROVED. A central é achada pelo hash
 * do token do cabeçalho asaas-access-token. Aprova só o saque nosso em andamento da central com o mesmo transfer.id
 * (ou, se o Asaas chamou antes de gravarmos o id, o saque "enviando" dos últimos 2 min com o mesmo valor), mesmo valor e,
 * se o pedido trouxer, a mesma chave Pix. O teste da central (R$ 1,00) é recusado e confirma a configuração. Toda
 * decisão vai ao log (sem o token).
 */
final class ValidacaoDeSaque
{
    /** @return array{0: int, 1: array} [status HTTP, corpo] */
    public static function decidir(?string $token, array $corpo): array
    {
        $token   = is_string($token) ? trim($token) : '';
        $hash    = hash('sha256', $token);
        $conexao = $token !== '' ? DB::table(Saque::CONEXOES)->where('token_hash', $hash)->first() : null;
        if (!$conexao || !hash_equals((string) $conexao->token_hash, $hash)) {
            Log::warning('[entregas] saque: validação com token inválido');

            return [401, static::recusa('Token inválido.')];
        }

        $empresa  = (string) $conexao->company_uuid;
        $transfer = is_array($corpo['transfer'] ?? null) ? $corpo['transfer'] : [];
        $id       = is_scalar($transfer['id'] ?? null) ? (string) $transfer['id'] : '';
        $valor    = Saque::centavos($transfer['value'] ?? 0);
        if (($corpo['type'] ?? null) !== 'TRANSFER' || $id === '') {
            return static::decisao($empresa, $id, false, 'Operação não reconhecida pelo Entregas.');
        }

        if (static::ehOTeste($conexao, $id, $valor)) {
            $agora = now()->format('Y-m-d H:i:s');
            DB::table(Saque::CONEXOES)->where('id', $conexao->id)->update(['validacao_confirmada_em' => $agora, 'teste_transfer_id' => $id, 'updated_at' => $agora]);

            return static::decisao($empresa, $id, false, 'Teste do Entregas: validação funcionando.');
        }
        if (!Saque::ligada()) {
            return static::decisao($empresa, $id, false, 'Saque Pix desligado no Entregas.');
        }

        $saque = static::saqueDaTransferencia($empresa, $id, $valor);
        if (!$saque) {
            return static::decisao($empresa, $id, false, 'Transferência não reconhecida pelo Entregas.');
        }
        if (Saque::centavos($saque->valor) !== $valor) {
            return static::decisao($empresa, $id, false, 'Valor diferente do saque pedido.', (string) $saque->uuid);
        }
        $chaveDeles = $transfer['bankAccount']['pixAddressKey'] ?? ($transfer['pixAddressKey'] ?? null);
        if (is_string($chaveDeles) && trim($chaveDeles) !== '' && !static::mesmaChave((string) $saque->chave_pix, (string) $saque->tipo_chave, $chaveDeles)) {
            return static::decisao($empresa, $id, false, 'Chave Pix diferente da cadastrada.', (string) $saque->uuid);
        }

        return static::decisao($empresa, $id, true, 'Saque do motoboy.', (string) $saque->uuid);
    }

    /** A nossa chave e a do Asaas são a mesma (telefone: uma termina com a outra, por causa do +55). */
    public static function mesmaChave(string $nossa, string $tipo, string $deles): bool
    {
        $limpa = fn (string $chave) => strtolower((string) preg_replace('/[\s.\-\/()+]/', '', $chave));
        $a     = $limpa($nossa);
        $b     = $limpa($deles);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($tipo === 'telefone') {
            return str_ends_with($b, $a) || str_ends_with($a, $b);
        }

        return $a === $b;
    }

    /** O teste da central: o id gravado ou, sem ele, R$ 1,00 até 2 min depois de o teste ser pedido. */
    private static function ehOTeste(object $conexao, string $id, int $valor): bool
    {
        if (($conexao->teste_transfer_id ?? null) !== null) {
            return (string) $conexao->teste_transfer_id === $id;
        }
        $pedido = $conexao->teste_pedido_em ?? null;

        return $pedido !== null
            && (string) $pedido >= now()->subMinutes(Saque::MINUTOS_DA_VALIDACAO)->format('Y-m-d H:i:s')
            && $valor === Saque::centavos(ConexaoAsaas::VALOR_DO_TESTE);
    }

    /** O saque em andamento da central com este id; ou o "enviando" recente sem id e com o mesmo valor (e grava o id). */
    private static function saqueDaTransferencia(string $empresa, string $id, int $valor): ?object
    {
        $saque = DB::table(Saque::TABELA)->where('company_uuid', $empresa)->where('transfer_id', $id)->whereIn('situacao', Saque::EM_ANDAMENTO)->first();
        if ($saque) {
            return $saque;
        }

        $desde      = now()->subMinutes(Saque::MINUTOS_DA_VALIDACAO)->format('Y-m-d H:i:s');
        $candidatos = DB::table(Saque::TABELA)
            ->where('company_uuid', $empresa)
            ->where('situacao', Saque::ENVIANDO)
            ->whereNull('transfer_id')
            ->where('created_at', '>=', $desde)
            ->orderBy('id')
            ->get();
        foreach ($candidatos as $candidato) {
            if (Saque::centavos($candidato->valor) !== $valor) {
                continue;
            }
            $gravou = DB::table(Saque::TABELA)->where('id', $candidato->id)->whereNull('transfer_id')->update(['transfer_id' => $id, 'updated_at' => now()->format('Y-m-d H:i:s')]);
            if ($gravou > 0) {
                return DB::table(Saque::TABELA)->where('id', $candidato->id)->first();
            }
        }

        return null;
    }

    private static function decisao(string $empresa, string $id, bool $aprovar, string $motivo, ?string $saque = null): array
    {
        Log::info('[entregas] saque: validação', ['empresa' => $empresa, 'transfer' => $id, 'decisao' => $aprovar ? 'APPROVED' : 'REFUSED', 'motivo' => $motivo, 'saque' => $saque]);

        return [200, $aprovar ? ['status' => 'APPROVED'] : static::recusa($motivo)];
    }

    private static function recusa(string $motivo): array
    {
        return ['status' => 'REFUSED', 'refuseReason' => $motivo];
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/saque-validacao.php`
Expected: `FALHAS: 0`.

- [ ] **Step 5: Commit**

```bash
git add api/app/Support/Entregas/Saques/ValidacaoDeSaque.php scripts/teste-php/saque-validacao.php
git commit -m "Saque Pix: validação de saque do Asaas (só o saque nosso é aprovado; o teste confirma a configuração)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Rotina de acompanhamento

**Files:**
- Create: `api/app/Support/Entregas/Saques/AcompanhamentoDeSaques.php`
- Create: `api/app/Console/Commands/Entregas/SaquesConferir.php`
- Modify: `api/app/Console/Kernel.php` (`use` e uma linha no `schedule`)
- Test: `scripts/teste-php/saque-acompanhamento.php`

- [ ] **Step 1: Escrever o teste que falha**

Crie `scripts/teste-php/saque-acompanhamento.php`:

```php
<?php

// Saque Pix: a rotina entregas:saques-conferir (AcompanhamentoDeSaques): consulta as transferências em andamento, adota
// pelo externalReference a que ficou sem id e falha a que nunca chegou ao Asaas. Erro de rede nunca dá por falho.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-acompanhamento.php

require __DIR__ . '/stubs-saque.php';

use App\Console\Commands\Entregas\SaquesConferir;
use App\Notifications\Entregas\SaqueConcluido;
use App\Notifications\Entregas\SaqueNaoConcluido;
use App\Support\Entregas\Saques\AcompanhamentoDeSaques;
use App\Support\Entregas\Saques\Saques;
use Fleetbase\FleetOps\Models\Driver;
use Teste\Config;
use Teste\Http as HttpFalso;
use Teste\Relogio;

const UA = 'aaaaaaaa-0000-4000-8000-00000000000a';
const UB = 'aaaaaaaa-0000-4000-8000-00000000000b';
const UC = 'aaaaaaaa-0000-4000-8000-00000000000c';

echo '== Em processamento' . PHP_EOL;
centralComSaque();
motoboyComSaldo('driver-2', 'Maria Souza');
motoboyComSaldo('driver-3', 'Pedro Lima');
saqueEnviado('driver-1', UA, 10, ['id' => 'tra_a', 'status' => 'PENDING']);
saqueEnviado('driver-2', UB, 20, ['id' => 'tra_b', 'status' => 'PENDING']);
saqueEnviado('driver-3', UC, 30, ['id' => 'tra_c', 'status' => 'BANK_PROCESSING']);
HttpFalso::$chamadas = [];
Driver::$avisos      = [];
asaas(200, ['id' => 'tra_a', 'status' => 'DONE', 'transactionReceiptUrl' => 'https://asaas.teste/c/a']);
asaas(200, ['id' => 'tra_b', 'status' => 'FAILED', 'failReason' => 'Chave Pix não encontrada']);
HttpFalso::$respostas[] = 'conexao';
confere(AcompanhamentoDeSaques::conferir() === 2, 'dois saques terminaram');
confere(array_column(HttpFalso::$chamadas, 'url') === ['https://asaas.teste/v3/transfers/tra_a', 'https://asaas.teste/v3/transfers/tra_b', 'https://asaas.teste/v3/transfers/tra_c'], 'consulta cada um pelo id');
confere(saqueDoBanco(UA)->situacao === 'concluido' && saqueDoBanco(UA)->comprovante_url === 'https://asaas.teste/c/a', 'DONE: concluído com o comprovante');
confere(saqueDoBanco(UB)->situacao === 'falhou' && saqueDoBanco(UB)->motivo_falha === 'Chave Pix não encontrada' && saqueDoBanco(UB)->asaas_status === 'FAILED', 'FAILED: falhou com o motivo do Asaas');
confere(saqueDoBanco(UC)->situacao === 'processando', 'sem resposta: continua processando (nunca falha às cegas)');
confere(Driver::$avisos[0][1] instanceof SaqueConcluido && Driver::$avisos[1][1] instanceof SaqueNaoConcluido && count(Driver::$avisos) === 2, 'um push para cada um que terminou');
asaas(200, ['id' => 'tra_c', 'status' => 'CANCELLED']);
confere(AcompanhamentoDeSaques::conferir() === 1 && saqueDoBanco(UC)->motivo_falha === 'Transferência cancelada no Asaas.', 'CANCELLED sem motivo: "cancelada no Asaas"');

echo '== Sem id: espera 5 min, depois procura pelo externalReference' . PHP_EOL;
centralComSaque();
motoboyComSaldo('driver-2', 'Maria Souza');
$a = Saques::pedir('empresa-1', 'driver-1', UA, 10)['saque'];
$b = Saques::pedir('empresa-1', 'driver-2', UB, 20)['saque'];
confere(AcompanhamentoDeSaques::conferir() === 0 && HttpFalso::$chamadas === [], 'recém-criados sem id: não consulta ainda');
Relogio::$agora = '2026-10-27 10:06:00';
asaas(200, ['data' => [['id' => 'tra_z', 'externalReference' => 'saque:outro', 'status' => 'DONE'], ['id' => 'tra_a', 'externalReference' => 'saque:' . UA, 'status' => 'BANK_PROCESSING', 'value' => 10]], 'hasMore' => false]);
confere(AcompanhamentoDeSaques::conferir() === 1, 'um mudou (o que nunca chegou ao Asaas)');
confere(count(HttpFalso::$chamadas) === 1 && HttpFalso::$chamadas[0]['url'] === 'https://asaas.teste/v3/transfers?offset=0&limit=100', 'lista as transferências uma vez só para os dois');
confere(saqueDoBanco(UA)->situacao === 'processando' && saqueDoBanco(UA)->transfer_id === 'tra_a' && saqueDoBanco(UA)->asaas_status === 'BANK_PROCESSING', 'achado pelo externalReference: adotado');
confere(saqueDoBanco(UB)->situacao === 'falhou' && saqueDoBanco(UB)->motivo_falha === 'Saque interrompido antes de chegar ao Asaas.', 'não achado: falha e devolve');

echo '== Lista fora do ar: espera' . PHP_EOL;
centralComSaque();
Saques::pedir('empresa-1', 'driver-1', UC, 10);
Relogio::$agora = '2026-10-27 10:06:00';
HttpFalso::$respostas[] = 'conexao';
confere(AcompanhamentoDeSaques::conferir() === 0 && saqueDoBanco(UC)->situacao === 'enviando', 'sem a lista: não falha');

echo '== Sem o secret' . PHP_EOL;
unlink(CHAVE_DO_COFRE);
confere(AcompanhamentoDeSaques::conferir() === 0 && saqueDoBanco(UC)->situacao === 'enviando' && count(HttpFalso::$chamadas) === 1, 'sem chave: nada muda, nada é chamado');

echo '== Comando e agenda' . PHP_EOL;
centralComSaque();
saqueEnviado('driver-1', UA, 10, ['id' => 'tra_a', 'status' => 'PENDING']);
Config::$valores['services.entregas.saque'] = null;
$antes = count(HttpFalso::$chamadas);
confere((new SaquesConferir())->handle() === 0 && count(HttpFalso::$chamadas) === $antes, 'desligada: o comando sai sem chamar o Asaas');
Config::$valores['services.entregas.saque'] = '1';
asaas(200, ['id' => 'tra_a', 'status' => 'DONE']);
confere((new SaquesConferir())->handle() === 0 && saqueDoBanco(UA)->situacao === 'concluido', 'ligada: confere');
$kernel = file_get_contents('/repo/api/app/Console/Kernel.php');
confere(str_contains($kernel, "\$schedule->command('entregas:saques-conferir')->everyMinute()->when(fn () => Saque::ligada())->withoutOverlapping(5)->runInBackground()->appendOutputTo(static::SAIDA_DO_CONTAINER);") && str_contains($kernel, 'use App\Support\Entregas\Saques\Saque;'), 'agenda: a cada minuto, só com a chave ligada');
confere(str_contains(file_get_contents('/repo/api/app/Console/Commands/Entregas/SaquesConferir.php'), "'entregas:saques-conferir'"), 'assinatura do comando');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/saque-acompanhamento.php`
Expected: erro "Class …SaquesConferir not found" (ou AcompanhamentoDeSaques).

- [ ] **Step 3: Implementar**

Crie `api/app/Support/Entregas/Saques/AcompanhamentoDeSaques.php`:

```php
<?php

namespace App\Support\Entregas\Saques;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: acompanha no Asaas os saques em andamento (rotina entregas:saques-conferir, a cada minuto).
 * Com transfer_id: GET /transfers/{id} e aplica o status (Saques::aplicarStatus). Sem transfer_id há mais de 5 min (a API
 * caiu no meio do envio): procura nas transferências recentes pelo externalReference; achou, adota; não achou, falha e
 * devolve o valor. Erro de rede ou 404 nunca dá por falho: tenta no minuto seguinte. Uma central com problema não para
 * as outras.
 */
final class AcompanhamentoDeSaques
{
    public const PAGINAS = 5;

    public const POR_PAGINA = 100;

    /** Quantos saques terminaram (concluídos ou falhos) nesta passada. */
    public static function conferir(): int
    {
        $porEmpresa = [];
        foreach (DB::table(Saque::TABELA)->whereIn('situacao', Saque::EM_ANDAMENTO)->orderBy('id')->get() as $saque) {
            $porEmpresa[(string) $saque->company_uuid][] = $saque;
        }

        $terminaram = 0;
        foreach ($porEmpresa as $empresa => $saques) {
            try {
                $terminaram += static::daEmpresa((string) $empresa, $saques);
            } catch (\Throwable $e) {
                Log::warning('[entregas] saque: acompanhamento falhou', ['empresa' => $empresa, 'erro' => get_class($e)]);
            }
        }

        return $terminaram;
    }

    private static function daEmpresa(string $empresa, array $saques): int
    {
        $conexao = ConexaoAsaas::daEmpresa($empresa);
        $chave   = $conexao ? Cofre::decifrar((string) $conexao->chave_cifrada) : null;
        if ($chave === null) {
            Log::warning('[entregas] saque: sem a chave do Asaas para acompanhar', ['empresa' => $empresa, 'saques' => count($saques)]);

            return 0;
        }

        $asaas      = new AsaasCliente($chave);
        $limite     = now()->subMinutes(Saque::MINUTOS_SEM_TRANSFERENCIA)->format('Y-m-d H:i:s');
        $recentes   = false;
        $terminaram = 0;
        foreach ($saques as $saque) {
            if ($saque->transfer_id !== null) {
                $resposta = $asaas->transferencia((string) $saque->transfer_id);
                if ($resposta->ok() && is_array($resposta->dados)) {
                    $terminaram += Saques::aplicarStatus($saque, $resposta->dados) ? 1 : 0;
                }
                continue;
            }
            if ((string) $saque->created_at > $limite) {
                continue;
            }
            if ($recentes === false) {
                $recentes = static::transferenciasRecentes($asaas);
            }
            if ($recentes === null) {
                continue;
            }
            $achada = $recentes[(string) $saque->external_reference] ?? null;
            if ($achada) {
                $agora = now()->format('Y-m-d H:i:s');
                DB::table(Saque::TABELA)->where('id', $saque->id)->whereNull('transfer_id')->update(['transfer_id' => (string) $achada['id'], 'situacao' => Saque::PROCESSANDO, 'updated_at' => $agora]);
                $terminaram += Saques::aplicarStatus(Saques::recarregar($saque), $achada) ? 1 : 0;
                continue;
            }
            $terminaram += Saques::falhar($saque, 'Saque interrompido antes de chegar ao Asaas.') ? 1 : 0;
        }

        return $terminaram;
    }

    /** externalReference => transferência, das mais recentes (até 500); null se o Asaas não respondeu. */
    private static function transferenciasRecentes(AsaasCliente $asaas): ?array
    {
        $porReferencia = [];
        for ($pagina = 0; $pagina < static::PAGINAS; $pagina++) {
            $resposta = $asaas->transferencias($pagina * static::POR_PAGINA, static::POR_PAGINA);
            if (!$resposta->ok()) {
                return null;
            }
            $itens = is_array($resposta->dados['data'] ?? null) ? $resposta->dados['data'] : [];
            foreach ($itens as $item) {
                $referencia = $item['externalReference'] ?? null;
                if (is_string($referencia) && $referencia !== '' && is_string($item['id'] ?? null)) {
                    $porReferencia[$referencia] = $item;
                }
            }
            if (count($itens) < static::POR_PAGINA || ($resposta->dados['hasMore'] ?? null) === false) {
                break;
            }
        }

        return $porReferencia;
    }
}
```

Crie `api/app/Console/Commands/Entregas/SaquesConferir.php`:

```php
<?php

namespace App\Console\Commands\Entregas;

use App\Support\Entregas\Saques\AcompanhamentoDeSaques;
use App\Support\Entregas\Saques\Saque;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: acompanha no Asaas os saques Pix em andamento dos motoboys, a cada minuto (Kernel, só com
 * ENTREGAS_SAQUE). Ver AcompanhamentoDeSaques.
 */
class SaquesConferir extends Command
{
    protected $signature   = 'entregas:saques-conferir';
    protected $description = 'Entregas: acompanha no Asaas os saques Pix em andamento dos motoboys';

    public function handle(): int
    {
        if (!Saque::ligada()) {
            return self::SUCCESS;
        }
        $terminaram = AcompanhamentoDeSaques::conferir();
        if ($terminaram > 0) {
            Log::info('[entregas] saque: rotina atualizou saques', ['saques' => $terminaram]);
        }

        return self::SUCCESS;
    }
}
```

Em `api/app/Console/Kernel.php`:

1. Acrescente aos `use` (em ordem alfabética): `use App\Support\Entregas\Saques\Saque;`
2. Depois da linha do `entregas:financeiro-rascunhos`, acrescente:

```php
        // saque Pix do motoboy: acompanha no Asaas as transferências em andamento (AcompanhamentoDeSaques)
        $schedule->command('entregas:saques-conferir')->everyMinute()->when(fn () => Saque::ligada())->withoutOverlapping(5)->runInBackground()->appendOutputTo(static::SAIDA_DO_CONTAINER);
```

- [ ] **Step 4: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/saque-acompanhamento.php` e `.../financeiro-lancador.php` (confere a agenda do Kernel)
Expected: os dois com `FALHAS: 0`.

- [ ] **Step 5: Commit**

```bash
git add api/app/Support/Entregas/Saques/AcompanhamentoDeSaques.php api/app/Console/Commands/Entregas/SaquesConferir.php api/app/Console/Kernel.php scripts/teste-php/saque-acompanhamento.php
git commit -m "Saque Pix: rotina por minuto que acompanha as transferências no Asaas

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Saque no Financeiro (estorno bloqueado, extrato, carteira, painel)

**Files:**
- Modify: `api/app/Support/Entregas/Saques/Saques.php` (`doPagamento`, `atencao`)
- Modify: `api/app/Http/Controllers/Entregas/FinanceiroController.php` (`estornarPagamento`)
- Modify: `api/app/Support/Entregas/Financeiro/ExtratoDaConta.php` (`pagamento` e `pagamentoDaLinha`)
- Modify: `api/app/Support/Entregas/Financeiro/Carteira.php` (`doMotoboy`)
- Modify: `api/app/Support/Entregas/Financeiro/PainelFinanceiro.php` (`montar`, lista `atencao`)
- Test: `scripts/teste-php/saque-financeiro.php`

- [ ] **Step 1: Escrever o teste que falha**

Crie `scripts/teste-php/saque-financeiro.php`:

```php
<?php

// Saque Pix no Financeiro: o pagamento de saque não se estorna à mão, o extrato e o recibo marcam o saque, a carteira do
// app traz o bloco `saque` e o painel avisa saque que falhou ou parou.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-financeiro.php

require __DIR__ . '/stubs-saque.php';

use App\Http\Controllers\Entregas\FinanceiroController;
use App\Support\Entregas\Financeiro\Carteira;
use App\Support\Entregas\Financeiro\ExtratoDaConta;
use App\Support\Entregas\Financeiro\Pagamentos;
use App\Support\Entregas\Saques\Saques;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Teste\Config;
use Teste\Relogio;
use Teste\UsuarioDoSaque;

const U1 = 'aaaaaaaa-0000-4000-8000-000000000001';

function estornar(string $pagamentoUuid)
{
    session(['company' => 'empresa-1']);
    \Fleetbase\Support\Auth::$usuario = new UsuarioDoSaque('user-admin', 'admin');

    return (new FinanceiroController())->estornarPagamento(new Request(), $pagamentoUuid);
}

function linhaDoPagamento(array $extrato, string $pagamentoUuid): ?array
{
    foreach ($extrato['lancamentos'] ?? [] as $linha) {
        if (($linha['pagamento']['id'] ?? null) === $pagamentoUuid) {
            return $linha;
        }
    }

    return null;
}

echo '== Estorno à mão bloqueado' . PHP_EOL;
centralComSaque();
$s = saqueEnviado('driver-1', U1, 50, ['id' => 'tra_1', 'status' => 'PENDING']);
$r = estornar((string) $s->pagamento_uuid);
confere($r->status === 422 && $r->dados === ['errors' => ['Saque em andamento.']], 'em andamento: 422');
Saques::concluir($s, 'https://asaas.teste/c/1');
$r = estornar((string) $s->pagamento_uuid);
confere($r->status === 422 && $r->dados === ['errors' => ['O dinheiro do saque já saiu; corrija com um ajuste.']], 'concluído: 422');
confere(Pagamentos::porUuid('empresa-1', (string) $s->pagamento_uuid)->estornado_em === null, 'o pagamento continua valendo');
$comum = Pagamentos::registrar('empresa-1', 'motoboy', 'driver-1', 5, 'dinheiro', '2026-10-27', null, null, 'user-admin');
confere(estornar((string) $comum->uuid)->status === 200, 'pagamento comum: estorna como antes');

echo '== Extrato e recibo marcam o saque' . PHP_EOL;
$publico = ExtratoDaConta::montar('empresa-1', 'motoboy', 'driver-1', '2026-10-01', '2026-10-31', true);
$linha   = linhaDoPagamento($publico, (string) $s->pagamento_uuid);
confere(($linha['pagamento']['saque'] ?? null) === ['situacao' => 'concluido', 'comprovante_url' => 'https://asaas.teste/c/1'], 'extrato do app: pagamento com o saque: ' . json_encode($linha['pagamento'] ?? null));
confere(!array_key_exists('saque', linhaDoPagamento($publico, (string) $comum->uuid)['pagamento'] ?? ['saque' => 1]), 'pagamento comum: sem a chave saque');
$console = ExtratoDaConta::montar('empresa-1', 'motoboy', 'driver-1', '2026-10-01', '2026-10-31', false);
confere((linhaDoPagamento($console, (string) $s->pagamento_uuid)['pagamento']['saque']['situacao'] ?? null) === 'concluido', 'extrato do console: também');
$recibo = ExtratoDaConta::pagamento(Pagamentos::porUuid('empresa-1', (string) $s->pagamento_uuid));
confere(($recibo['saque'] ?? null) === ['situacao' => 'concluido', 'comprovante_url' => 'https://asaas.teste/c/1'], 'recibo: o saque');
confere(!array_key_exists('saque', ExtratoDaConta::pagamento(Pagamentos::porUuid('empresa-1', (string) $comum->uuid))), 'recibo comum: sem saque');

echo '== Carteira do app' . PHP_EOL;
$carteira = Carteira::doMotoboy('empresa-1', 'driver-1');
confere(isset($carteira['saque']) && $carteira['saque']['disponivel'] === Saques::situacaoDoMotoboy('empresa-1', 'driver-1')['disponivel'], 'com o saque ligado: bloco saque');
Config::$valores['services.entregas.saque'] = null;
confere(!array_key_exists('saque', Carteira::doMotoboy('empresa-1', 'driver-1')), 'desligado: sem a chave (o app esconde o botão)');
Config::$valores['services.entregas.saque'] = '1';

echo '== Painel: atenção' . PHP_EOL;
centralComSaque();
motoboyComSaldo('driver-2', 'Maria Souza');
$falho = saqueEnviado('driver-1', U1, 10, ['id' => 'tra_1', 'status' => 'PENDING']);
Saques::falhar($falho, 'Saldo insuficiente.');
saqueEnviado('driver-2', 'aaaaaaaa-0000-4000-8000-000000000002', 20, ['id' => 'tra_2', 'status' => 'PENDING']);
Relogio::$agora = '2026-10-27 11:00:01';
$atencao = Saques::atencao('empresa-1');
confere(($atencao[0] ?? null) === ['tipo' => 'saque_falhou', 'saque' => U1, 'motoboy' => 'João Silva', 'valor' => 10.0, 'motivo' => 'Saldo insuficiente.'], 'saque que falhou nas últimas 24 h: ' . json_encode($atencao[0] ?? null, JSON_UNESCAPED_UNICODE));
confere(($atencao[1]['tipo'] ?? null) === 'saque_parado' && $atencao[1]['motoboy'] === 'Maria Souza' && $atencao[1]['desde'] === '2026-10-27T10:00:00-03:00', 'em andamento há mais de 1 h: parado');
Relogio::$agora = '2026-10-28 11:00:01';
confere(array_column(Saques::atencao('empresa-1'), 'tipo') === ['saque_parado'], 'depois de 24 h, a falha sai do painel');
Config::$valores['services.entregas.saque'] = null;
confere(Saques::atencao('empresa-1') === [], 'desligado: nada');
confere(str_contains(file_get_contents('/repo/api/app/Support/Entregas/Financeiro/PainelFinanceiro.php'), 'foreach (Saques::atencao($companyUuid) as $item)'), 'o painel inclui os avisos do saque');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/saque-financeiro.php`
Expected: FALHA em "em andamento: 422" (o estorno ainda passa).

- [ ] **Step 3: `doPagamento` e `atencao` no `Saques`**

Em `api/app/Support/Entregas/Saques/Saques.php`, depois de `emAndamento`, acrescente:

```php
    /** O saque cujo pagamento é este (null se não é de saque, ou se a tabela ainda não existe: API antes da migration). */
    public static function doPagamento(string $companyUuid, string $pagamentoUuid): ?object
    {
        try {
            return DB::table(Saque::TABELA)->where('company_uuid', $companyUuid)->where('pagamento_uuid', $pagamentoUuid)->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** {situacao, comprovante_url} do saque deste pagamento, para o extrato e o recibo; null se não é de saque. */
    public static function marcaDoPagamento(object $pagamento): ?array
    {
        if (($pagamento->criado_por ?? null) !== null || ($pagamento->forma ?? null) !== 'pix') {
            return null;
        }
        $saque = static::doPagamento((string) $pagamento->company_uuid, (string) $pagamento->uuid);

        return $saque ? ['situacao' => (string) $saque->situacao, 'comprovante_url' => $saque->comprovante_url] : null;
    }

    /**
     * Os avisos do saque no "Atenção" do painel do Financeiro: saque_falhou (últimas 24 h) e saque_parado (em andamento há
     * mais de 1 h), até 20 de cada. Desligado ou com o banco sem a tabela: nada.
     */
    public static function atencao(string $companyUuid): array
    {
        if (!Saque::ligada()) {
            return [];
        }
        try {
            $falhos = DB::table(Saque::TABELA)
                ->where('company_uuid', $companyUuid)
                ->where('situacao', Saque::FALHOU)
                ->where('updated_at', '>=', now()->subHours(24)->format('Y-m-d H:i:s'))
                ->orderBy('id', 'desc')
                ->limit(Saque::LIMITE_NO_PAINEL)
                ->get()
                ->all();
            $parados = DB::table(Saque::TABELA)
                ->where('company_uuid', $companyUuid)
                ->whereIn('situacao', Saque::EM_ANDAMENTO)
                ->where('created_at', '<=', now()->subMinutes(Saque::MINUTOS_PARADO)->format('Y-m-d H:i:s'))
                ->orderBy('id')
                ->limit(Saque::LIMITE_NO_PAINEL)
                ->get()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('[entregas] saque: painel sem os avisos de saque', ['empresa' => $companyUuid, 'erro' => get_class($e)]);

            return [];
        }

        $nomes = [];
        $uuids = array_values(array_unique(array_map(fn ($s) => (string) $s->driver_uuid, array_merge($falhos, $parados))));
        if ($uuids) {
            foreach (Driver::withTrashed()->where('company_uuid', $companyUuid)->whereIn('uuid', $uuids)->get() as $motoboy) {
                $nomes[(string) $motoboy->uuid] = $motoboy->name;
            }
        }

        $itens = [];
        foreach ($falhos as $saque) {
            $itens[] = ['tipo' => 'saque_falhou', 'saque' => (string) $saque->uuid, 'motoboy' => $nomes[(string) $saque->driver_uuid] ?? null, 'valor' => round((float) $saque->valor, 2), 'motivo' => $saque->motivo_falha];
        }
        foreach ($parados as $saque) {
            $itens[] = ['tipo' => 'saque_parado', 'saque' => (string) $saque->uuid, 'motoboy' => $nomes[(string) $saque->driver_uuid] ?? null, 'valor' => round((float) $saque->valor, 2), 'desde' => Saque::iso($saque->created_at, $companyUuid)];
        }

        return $itens;
    }
```

- [ ] **Step 4: Estorno bloqueado no console**

Em `api/app/Http/Controllers/Entregas/FinanceiroController.php`:

1. Acrescente aos `use`: `use App\Support\Entregas\Saques\Saques;`
2. No `estornarPagamento`, logo depois do `if (!$pagamento) { … 404 … }`, acrescente:

```php
        // o pagamento de um saque Pix pelo app não se estorna à mão: em andamento, a rotina resolve; concluído, o dinheiro já
        // saiu (corrige-se com ajuste); falho, já foi estornado sozinho
        if ($saque = Saques::doPagamento($empresa, $id)) {
            return $this->erro(match ((string) $saque->situacao) {
                Saque::CONCLUIDO => 'O dinheiro do saque já saiu; corrija com um ajuste.',
                Saque::FALHOU    => 'Este saque não foi concluído e o pagamento já foi estornado.',
                default          => 'Saque em andamento.',
            });
        }
```

- [ ] **Step 5: Extrato e recibo**

Em `api/app/Support/Entregas/Financeiro/ExtratoDaConta.php`:

1. Acrescente aos `use`: `use App\Support\Entregas\Saques\Saques;`
2. No método `pagamento(object $pagamento)`, troque o `return [ … ];` por:

```php
        $saida = [
            'id'          => (string) $pagamento->uuid,
            'valor'       => round((float) $pagamento->valor, 2),
            'forma'       => (string) $pagamento->forma,
            'data'        => substr((string) $pagamento->data, 0, 10),
            'estornado'   => ($pagamento->estornado_em ?? null) !== null,
            'comprovante' => $url ? [
                'url'  => $url,
                'tipo' => $pagamento->comprovante_tipo ?? null,
                'nome' => $pagamento->comprovante_nome ?? null,
            ] : null,
        ];
        // saque Pix pelo app: a situação e o comprovante do Asaas (só quando o pagamento é de saque)
        if ($saque = Saques::marcaDoPagamento($pagamento)) {
            $saida['saque'] = $saque;
        }

        return $saida;
```

3. No `pagamentoDaLinha`, logo antes do `return $saida;`, acrescente:

```php
        if ($saque = Saques::marcaDoPagamento($pagamento)) {
            $saida['saque'] = $saque;
        }
```

4. No docblock do `pagamento`, acrescente ao fim: "Pagamento de saque Pix: + `saque` {situacao, comprovante_url}."

- [ ] **Step 6: Carteira**

Em `api/app/Support/Entregas/Financeiro/Carteira.php`:

1. Acrescente aos `use`: `use App\Support\Entregas\Saques\Saques;`
2. Troque `return [` (o do `doMotoboy`) por `$carteira = [` e, depois do `];` desse array, acrescente:

```php
        // saque Pix pelo app: o bloco só vem quando a central liberou o saque (sem ele, o app não mostra o botão)
        $saque = Saques::situacaoDoMotoboy($companyUuid, $motoboyUuid);
        if ($saque !== null) {
            $carteira['saque'] = $saque;
        }

        return $carteira;
```

3. No docblock da classe, acrescente a linha: ` * - saque: o bloco do saque Pix (Saques::situacaoDoMotoboy), só quando a central liberou o saque.`

- [ ] **Step 7: Painel**

Em `api/app/Support/Entregas/Financeiro/PainelFinanceiro.php`:

1. Acrescente aos `use`: `use App\Support\Entregas\Saques\Saques;`
2. Depois do laço `foreach (static::semValor($companyUuid, $lancador) as $item) { $atencao[] = $item; }`, acrescente:

```php
        // saque Pix pelo app: saque que falhou nas últimas 24 h e saque parado há mais de 1 h
        foreach (Saques::atencao($companyUuid) as $item) {
            $atencao[] = $item;
        }
```

- [ ] **Step 8: Rodar e ver passar**

Run, um por um: `saque-financeiro.php`, `financeiro-console.php`, `financeiro-pagamentos.php`, `financeiro-portal-emails.php`, `ganhos-motoboy.php`.
Expected: todos com `FALHAS: 0`.

- [ ] **Step 9: Commit**

```bash
git add api/app/Support/Entregas/Saques/Saques.php api/app/Http/Controllers/Entregas/FinanceiroController.php api/app/Support/Entregas/Financeiro/ExtratoDaConta.php api/app/Support/Entregas/Financeiro/Carteira.php api/app/Support/Entregas/Financeiro/PainelFinanceiro.php scripts/teste-php/saque-financeiro.php
git commit -m "Saque Pix no Financeiro: estorno à mão bloqueado, saque no extrato e no recibo, bloco na carteira, avisos no painel

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: Controllers e rotas

**Files:**
- Create: `api/app/Http/Controllers/Entregas/SaquesController.php`, `SaquePixController.php`, `ValidacaoAsaasController.php`
- Modify: `api/app/Providers/RouteServiceProvider.php`
- Modify: `scripts/teste-php/imports-dos-providers.php`
- Test: `scripts/teste-php/saque-controllers.php`

- [ ] **Step 1: Escrever o teste que falha**

Crie `scripts/teste-php/saque-controllers.php`:

```php
<?php

// Saque Pix: os controllers do app (SaquesController), do console (SaquePixController) e da validação do Asaas
// (ValidacaoAsaasController), e as rotas no RouteServiceProvider.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-controllers.php

require __DIR__ . '/stubs-saque.php';

use App\Http\Controllers\Entregas\SaquePixController;
use App\Http\Controllers\Entregas\SaquesController;
use App\Http\Controllers\Entregas\ValidacaoAsaasController;
use App\Support\Entregas\Saques\ConexaoAsaas;
use App\Support\Entregas\Saques\Saques;
use Illuminate\Http\Request;
use Teste\Http as HttpFalso;
use Teste\UsuarioDoSaque;

const U1 = 'aaaaaaaa-0000-4000-8000-000000000001';

function doMotoboy(array $dados = [], string $usuario = 'user-driver-1'): Request
{
    session(['company' => 'empresa-1', 'user' => $usuario]);
    $request        = new Request($dados);
    $request->token = '12|abc';

    return $request;
}

function doAdmin(array $dados = [], string $tipo = 'admin'): Request
{
    session(['company' => 'empresa-1']);
    \Fleetbase\Support\Auth::$usuario = new UsuarioDoSaque('user-admin', $tipo);

    return new Request($dados);
}

echo '== App: pedir e acompanhar' . PHP_EOL;
centralComSaque();
$app = new SaquesController();
$semMotoboy        = new Request(['uuid' => U1, 'valor' => 50]);
$semMotoboy->token = 'flb_live_chave';
confere($app->pedir($semMotoboy)->status === 403, 'chave de API (sem "|"): 403');
$r = $app->pedir(doMotoboy(['uuid' => U1, 'valor' => 5]));
confere($r->status === 422 && $r->dados === ['errors' => ['O valor mínimo do saque é R$ 10,00.']], 'recusa: 422 com a mensagem');
asaas(200, ['data' => [['id' => 'wallet-1']]]);
asaas(200, ['id' => 'tra_1', 'status' => 'PENDING']);
$r = $app->pedir(doMotoboy(['uuid' => U1, 'valor' => '50,00']));
confere($r->status === 201 && ($r->dados['saque']['id'] ?? null) === U1 && $r->dados['saque']['situacao'] === 'processando' && $r->dados['saque']['chave'] === '*******4321', 'pedido aceito: 201, processando, chave escondida: ' . json_encode($r->dados, JSON_UNESCAPED_UNICODE));
confere(!str_contains(json_encode($r->dados), '11999994321'), 'a resposta nunca traz a chave Pix inteira');
$chamadas = count(HttpFalso::$chamadas);
$r = $app->pedir(doMotoboy(['uuid' => U1, 'valor' => 50]));
confere($r->status === 200 && $r->dados['saque']['id'] === U1 && count(HttpFalso::$chamadas) === $chamadas, 'repetido: 200, sem mandar de novo ao Asaas');
confere($app->mostrar(doMotoboy(), U1)->dados['saque']['situacao'] === 'processando', 'acompanhar: o saque dele');
motoboyComSaldo('driver-2', 'Maria Souza');
confere($app->mostrar(doMotoboy([], 'user-driver-2'), U1)->status === 404, 'saque de outro motoboy: 404');
confere($app->mostrar(doMotoboy(), 'aaaaaaaa-0000-4000-8000-000000000999')->status === 404, 'saque que não existe: 404');

echo '== Validação do Asaas' . PHP_EOL;
$validacao                         = new Request(['type' => 'TRANSFER', 'transfer' => ['id' => 'tra_1', 'value' => 50]]);
$validacao->cabecalhos             = ['asaas-access-token' => TOKEN_DE_TESTE];
$r = (new ValidacaoAsaasController())->validar($validacao);
confere($r->status === 200 && $r->dados === ['status' => 'APPROVED'], 'token no cabeçalho: APPROVED');
$validacao->cabecalhos = [];
confere((new ValidacaoAsaasController())->validar($validacao)->status === 401, 'sem cabeçalho: 401');

echo '== Console' . PHP_EOL;
reiniciarSaque();
$console = new SaquePixController();
confere($console->situacao(doAdmin([], 'user'))->status === 403 && $console->situacao(doAdmin([], 'user'))->dados === ['errors' => ['Somente administradores podem mexer no saque Pix.']], 'sem ser admin: 403');
confere($console->situacao(doAdmin())->dados === ['conectada' => false, 'ligada' => true, 'cofre' => true], 'situação sem conexão');
confere($console->conectar(doAdmin(['chave' => CHAVE_ASAAS, 'senha' => 'errada']))->dados === ['errors' => ['Senha incorreta.']] && HttpFalso::$chamadas === [], 'senha errada: 422 sem chamar o Asaas');
confere($console->conectar(doAdmin(['chave' => CHAVE_ASAAS]))->status === 422, 'sem senha: 422');
unlink(CHAVE_DO_COFRE);
confere($console->conectar(doAdmin(['chave' => CHAVE_ASAAS, 'senha' => 'senha-certa']))->status === 503, 'sem o secret: 503');
reiniciarSaque();
HttpFalso::$respostas[] = 'conexao';
confere($console->conectar(doAdmin(['chave' => CHAVE_ASAAS, 'senha' => 'senha-certa']))->status === 502, 'Asaas fora: 502');
asaas(200, ['name' => 'Central Teste', 'cpfCnpj' => '12345678000199']);
asaas(200, ['data' => [['id' => 'wallet-1']]]);
$r = $console->conectar(doAdmin(['chave' => CHAVE_ASAAS, 'senha' => 'senha-certa']));
confere($r->status === 200 && $r->dados['conectada'] === true && $r->dados['conta']['wallet'] === 'wallet-1' && !str_contains(json_encode($r->dados), CHAVE_ASAAS), 'conectada: a conexão pública, sem a chave');
$r = $console->gerarToken(doAdmin());
confere($r->status === 200 && strlen($r->dados['token']) === 48 && $r->dados['url'] === 'https://entregas-api.teste/v1/entregas/asaas/validar-saque' && $r->dados['conexao']['token_gerado'] === true, 'token: aparece só aqui, com a URL');
asaas(200, ['id' => 'tra_teste', 'status' => 'PENDING']);
confere($console->testar(doAdmin(['tipo' => 'cpf', 'chave' => '12345678901']))->dados['teste_pedido_em'] === '2026-10-27T10:00:00-03:00', 'teste pedido');
confere(\App\Support\Entregas\Saques\ConexaoAsaas::daEmpresa('empresa-1')->teste_pedido_por === 'user-admin', 'teste: grava quem pediu');
confere($console->testar(doAdmin(['tipo' => 'cpf', 'chave' => '12345678901']))->dados === ['errors' => ['Espere o resultado do teste anterior.']], 'outro teste antes do resultado: 422');
confere($console->configurar(doAdmin(['saque_ligado' => true]))->dados === ['errors' => ['Teste a validação de saque antes de ligar.']], 'ligar antes do teste confirmar: 422');
\Illuminate\Support\Facades\DB::table('entregas_asaas_conexoes')->update(['validacao_confirmada_em' => '2026-10-27 10:00:05']);
$r = $console->configurar(doAdmin(['saque_ligado' => true, 'minimo' => 15]));
confere($r->status === 200 && $r->dados['saque_ligado'] === true && $r->dados['limites']['minimo'] === 15.0, 'ligado com mínimo 15');

echo '== Console: saques e bloqueio' . PHP_EOL;
\Fleetbase\FleetOps\Models\Driver::$todos[] = new \Fleetbase\FleetOps\Models\Driver(['uuid' => 'driver-1', 'public_id' => 'driver_1', 'company_uuid' => 'empresa-1', 'user_uuid' => 'user-driver-1', 'name' => 'João Silva']);
\App\Support\Entregas\Financeiro\ChavePix::salvar('empresa-1', 'driver-1', '11999994321', 'telefone');
$r = $console->bloquear(doAdmin(['bloqueado' => true]), 'driver_1');
confere($r->status === 200 && $r->dados === ['bloqueado' => true] && (bool) Saques::linhaDoMotoboy('empresa-1', 'driver-1')->saque_bloqueado, 'bloquear');
confere($console->bloquear(doAdmin(['bloqueado' => 'talvez']), 'driver_1')->status === 422 && $console->bloquear(doAdmin(['bloqueado' => true]), 'driver_9')->status === 404, 'bloquear: valor inválido 422, motoboy de fora 404');
$lista = $console->saques(doAdmin())->dados;
confere(($lista['motoboys'] ?? null) === [['id' => 'driver_1', 'nome' => 'João Silva', 'chave' => '*******4321', 'tipo' => 'telefone', 'bloqueado' => true, 'liberado_em' => null]], 'motoboys com chave Pix, para o interruptor: ' . json_encode($lista['motoboys'] ?? null, JSON_UNESCAPED_UNICODE));
confere(($lista['saques'] ?? null) === [], 'sem saques');
confere($console->saques(doAdmin(['situacao' => 'lixo']))->status === 422, 'situação inválida: 422');

echo '== Console: desconectar' . PHP_EOL;
confere($console->desconectar(doAdmin(['senha' => 'errada']))->dados === ['errors' => ['Senha incorreta.']] && ConexaoAsaas::daEmpresa('empresa-1') !== null, 'senha errada: continua conectada');
$r = $console->desconectar(doAdmin(['senha' => 'senha-certa']));
confere($r->status === 200 && $r->dados['conectada'] === false, 'desconectada');

echo '== Rotas' . PHP_EOL;
$rotas = file_get_contents('/repo/api/app/Providers/RouteServiceProvider.php');
foreach (['SaquesController', 'SaquePixController', 'ValidacaoAsaasController'] as $classe) {
    confere(str_contains($rotas, 'use App\\Http\\Controllers\\Entregas\\' . $classe . ';'), "use do {$classe}");
}
foreach ([
    "Route::get('financeiro/saque-pix', [SaquePixController::class, 'situacao']);",
    "Route::put('financeiro/saque-pix', [SaquePixController::class, 'configurar']);",
    "Route::post('financeiro/saque-pix/conexao', [SaquePixController::class, 'conectar'])->middleware('throttle:10,1');",
    "Route::delete('financeiro/saque-pix/conexao', [SaquePixController::class, 'desconectar'])->middleware('throttle:10,1');",
    "Route::post('financeiro/saque-pix/token', [SaquePixController::class, 'gerarToken']);",
    "Route::post('financeiro/saque-pix/teste', [SaquePixController::class, 'testar'])->middleware('throttle:10,1');",
    "Route::get('financeiro/saques', [SaquePixController::class, 'saques']);",
    "Route::put('financeiro/motoboys/{id}/saque', [SaquePixController::class, 'bloquear']);",
    "Route::post('saques', [SaquesController::class, 'pedir']);",
    "Route::get('saques/{id}', [SaquesController::class, 'mostrar']);",
    "Route::post('validar-saque', [ValidacaoAsaasController::class, 'validar']);",
    "->middleware(['fleetbase.api', 'throttle:entregas-motoboy-saque'])",
    "Route::prefix('v1/entregas/asaas')",
    "->middleware(['throttle:entregas-asaas-validacao'])",
    "RateLimiter::for('entregas-motoboy-saque'",
    "RateLimiter::for('entregas-asaas-validacao'",
] as $trecho) {
    confere(str_contains($rotas, $trecho), 'rota: ' . $trecho);
}

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/saque-controllers.php`
Expected: erro "Class …SaquesController not found".

- [ ] **Step 3: `SaquesController` (app)**

Crie `api/app/Http/Controllers/Entregas/SaquesController.php`:

```php
<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\MotoboyDaSessao;
use App\Support\Entregas\Saques\Saque;
use App\Support\Entregas\Saques\SaqueRecusado;
use App\Support\Entregas\Saques\Saques;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: saque Pix do motoboy pelo app (API v1, token dele; MotoboyDaSessao). Pedir: confere, reserva
 * no livro (Saques::pedir) e manda ao Asaas na hora (Saques::enviar); o mesmo uuid repetido devolve o saque, sem mandar de
 * novo. Acompanhar: o saque dele pelo uuid (o app consulta a cada 3 s por até 1 min; depois, o push avisa).
 */
class SaquesController extends Controller
{
    /** POST v1/entregas/motoboy/saques {uuid, valor} → {saque} (201 novo, 200 repetido, 422 recusa). */
    public function pedir(Request $request)
    {
        $motoboy = MotoboyDaSessao::motoboy($request);
        if (!$motoboy) {
            return response()->json(['errors' => ['Disponível só para motoboys.']], 403);
        }

        try {
            $pedido = Saques::pedir((string) session('company'), (string) $motoboy->uuid, $request->input('uuid'), $request->input('valor'));
        } catch (SaqueRecusado $e) {
            return response()->json(['errors' => [$e->getMessage()]], 422);
        }
        $saque = $pedido['novo'] ? Saques::enviar($pedido['saque']) : $pedido['saque'];

        return response()->json(['saque' => Saques::publico($saque)], $pedido['novo'] ? 201 : 200);
    }

    /** GET v1/entregas/motoboy/saques/{uuid} → {saque}; de outro motoboy ou inexistente: 404. */
    public function mostrar(Request $request, string $id)
    {
        $motoboy = MotoboyDaSessao::motoboy($request);
        if (!$motoboy) {
            return response()->json(['errors' => ['Disponível só para motoboys.']], 403);
        }
        $saque = DB::table(Saque::TABELA)
            ->where('company_uuid', (string) session('company'))
            ->where('driver_uuid', (string) $motoboy->uuid)
            ->where('uuid', strtolower($id))
            ->first();
        if (!$saque) {
            return response()->json(['errors' => ['Saque não encontrado.']], 404);
        }

        return response()->json(['saque' => Saques::publico($saque)]);
    }
}
```

- [ ] **Step 4: `ValidacaoAsaasController`**

Crie `api/app/Http/Controllers/Entregas/ValidacaoAsaasController.php`:

```php
<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\Saques\ValidacaoDeSaque;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: POST v1/entregas/asaas/validar-saque, a validação de saque do Asaas (sem token de usuário: a
 * central vem do cabeçalho asaas-access-token). Responde {status: APPROVED} ou {status: REFUSED, refuseReason}.
 */
class ValidacaoAsaasController extends Controller
{
    public function validar(Request $request)
    {
        [$status, $corpo] = ValidacaoDeSaque::decidir($request->header('asaas-access-token'), $request->all());

        return response()->json($corpo, $status);
    }
}
```

- [ ] **Step 5: `SaquePixController` (console)**

Crie `api/app/Http/Controllers/Entregas/SaquePixController.php`:

```php
<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\Financeiro\ChavePix;
use App\Support\Entregas\Saques\AdminDaCentral;
use App\Support\Entregas\Saques\CofreIndisponivel;
use App\Support\Entregas\Saques\ConexaoAsaas;
use App\Support\Entregas\Saques\ErroDoAsaas;
use App\Support\Entregas\Saques\Saque;
use App\Support\Entregas\Saques\Saques;
use Fleetbase\FleetOps\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Entregas RestaurantePro: o saque Pix no console (Financeiro → Configurações e aba Saques), para o administrador do
 * sistema e o da organização (AdminDaCentral): conta Asaas da central (conectar e desconectar pedem a senha), token e
 * teste da validação, limites e interruptor, lista dos saques e bloqueio por motoboy. As respostas trazem a conexão no
 * formato público (ConexaoAsaas::publico): nunca a chave nem o token (o token sai só no gerarToken).
 */
class SaquePixController extends Controller
{
    public const NEGADO = 'Somente administradores podem mexer no saque Pix.';

    public const DIAS_DA_LISTA = 30;

    /** GET financeiro/saque-pix → a conexão. */
    public function situacao(Request $request)
    {
        if (!AdminDaCentral::usuario($request)) {
            return $this->negado();
        }

        return $this->conexao();
    }

    /** POST financeiro/saque-pix/conexao {chave, senha}: conecta ou troca a chave (mesma conta). */
    public function conectar(Request $request)
    {
        $usuario = AdminDaCentral::usuario($request);
        if (!$usuario) {
            return $this->negado();
        }
        if (!$this->senhaConfere($request, $usuario)) {
            return $this->erro('Senha incorreta.');
        }

        return $this->tentar(fn () => ConexaoAsaas::conectar($this->empresa(), $request->input('chave'), (string) $usuario->uuid));
    }

    /** DELETE financeiro/saque-pix/conexao {senha}. */
    public function desconectar(Request $request)
    {
        $usuario = AdminDaCentral::usuario($request);
        if (!$usuario) {
            return $this->negado();
        }
        if (!$this->senhaConfere($request, $usuario)) {
            return $this->erro('Senha incorreta.');
        }

        return $this->tentar(fn () => ConexaoAsaas::desconectar($this->empresa(), (string) $usuario->uuid));
    }

    /** POST financeiro/saque-pix/token → {token, url, conexao}: o token aparece só aqui. */
    public function gerarToken(Request $request)
    {
        if (!AdminDaCentral::usuario($request)) {
            return $this->negado();
        }
        try {
            $token = ConexaoAsaas::gerarToken($this->empresa());
        } catch (\InvalidArgumentException $e) {
            return $this->erro($e->getMessage());
        }

        return response()->json([
            'token'   => $token,
            'url'     => ConexaoAsaas::urlDaValidacao(),
            'conexao' => ConexaoAsaas::publico(ConexaoAsaas::daEmpresa($this->empresa()), $this->empresa()),
        ]);
    }

    /**
     * POST financeiro/saque-pix/teste {tipo, chave}: R$ 1,00 para a chave do admin, que a validação precisa recusar. Um
     * teste por vez e no máximo 5 por dia (422 "Espere o resultado do teste anterior." / "Limite de testes de hoje
     * atingido."); grava e loga quem pediu.
     */
    public function testar(Request $request)
    {
        $usuario = AdminDaCentral::usuario($request);
        if (!$usuario) {
            return $this->negado();
        }

        return $this->tentar(fn () => ConexaoAsaas::testarValidacao($this->empresa(), $request->input('tipo'), $request->input('chave'), (string) $usuario->uuid));
    }

    /** PUT financeiro/saque-pix {saque_ligado?, minimo?, maximo_dia?, saques_dia?, taxa?}. */
    public function configurar(Request $request)
    {
        $usuario = AdminDaCentral::usuario($request);
        if (!$usuario) {
            return $this->negado();
        }

        return $this->tentar(fn () => ConexaoAsaas::configurar($this->empresa(), $request->all(), (string) $usuario->uuid));
    }

    /**
     * GET financeiro/saques?situacao → {saques (últimos 30 dias, mais novo primeiro, com o motoboy), motoboys (com chave
     * Pix: id, nome, chave escondida, tipo, bloqueado, liberado_em)}.
     */
    public function saques(Request $request)
    {
        if (!AdminDaCentral::usuario($request)) {
            return $this->negado();
        }
        $situacao = $request->input('situacao');
        if ($situacao !== null && $situacao !== '' && !in_array($situacao, Saque::SITUACOES, true)) {
            return $this->erro('Situação inválida.');
        }

        $empresa  = $this->empresa();
        $consulta = DB::table(Saque::TABELA)
            ->where('company_uuid', $empresa)
            ->where('created_at', '>=', now()->subDays(static::DIAS_DA_LISTA)->format('Y-m-d H:i:s'));
        if ($situacao) {
            $consulta->where('situacao', $situacao);
        }
        $linhas = $consulta->orderBy('id', 'desc')->get()->all();

        $motoboys = [];
        foreach (Driver::withTrashed()->where('company_uuid', $empresa)->get() as $motoboy) {
            $motoboys[(string) $motoboy->uuid] = $motoboy;
        }

        $saques = array_map(fn ($saque) => Saques::publico($saque) + ['motoboy' => [
            'id'   => $motoboys[(string) $saque->driver_uuid]->public_id ?? null,
            'nome' => $motoboys[(string) $saque->driver_uuid]->name ?? null,
        ]], $linhas);

        $comPix = [];
        foreach (DB::table(ChavePix::TABELA)->where('company_uuid', $empresa)->whereNotNull('chave_pix')->get() as $linha) {
            $motoboy = $motoboys[(string) $linha->driver_uuid] ?? null;
            if (!$motoboy || ($motoboy->deleted_at ?? null) !== null) {
                continue;
            }
            $liberado = Saques::liberadoEm($linha);
            $comPix[] = [
                'id'          => $motoboy->public_id,
                'nome'        => $motoboy->name,
                'chave'       => Saque::chaveEscondida((string) $linha->chave_pix, (string) $linha->tipo_chave),
                'tipo'        => (string) $linha->tipo_chave,
                'bloqueado'   => (bool) ($linha->saque_bloqueado ?? false),
                'liberado_em' => Saque::iso($liberado, $empresa),
            ];
        }
        usort($comPix, fn ($a, $b) => strcmp((string) $a['nome'], (string) $b['nome']));

        return response()->json(['saques' => $saques, 'motoboys' => $comPix]);
    }

    /** PUT financeiro/motoboys/{id}/saque {bloqueado} → {bloqueado}. */
    public function bloquear(Request $request, string $id)
    {
        if (!AdminDaCentral::usuario($request)) {
            return $this->negado();
        }
        $motoboy = Driver::withTrashed()->where('company_uuid', $this->empresa())->where('public_id', $id)->first();
        if (!$motoboy) {
            return $this->erro('Motoboy não encontrado.', 404);
        }
        $bloqueado = filter_var($request->input('bloqueado'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($bloqueado === null) {
            return $this->erro('Informe se o saque fica bloqueado.');
        }
        Saques::bloquear($this->empresa(), (string) $motoboy->uuid, $bloqueado);

        return response()->json(['bloqueado' => $bloqueado]);
    }

    /** Roda a ação e responde com a conexão; entrada inválida 422, Asaas 502, sem o secret 503. */
    protected function tentar(\Closure $fazer)
    {
        try {
            $fazer();
        } catch (\InvalidArgumentException $e) {
            return $this->erro($e->getMessage());
        } catch (ErroDoAsaas $e) {
            return $this->erro($e->getMessage(), 502);
        } catch (CofreIndisponivel $e) {
            return $this->erro($e->getMessage(), 503);
        }

        return $this->conexao();
    }

    protected function conexao()
    {
        return response()->json(ConexaoAsaas::publico(ConexaoAsaas::daEmpresa($this->empresa()), $this->empresa()));
    }

    protected function senhaConfere(Request $request, object $usuario): bool
    {
        $senha = $request->input('senha');
        $hash  = $usuario->password ?? null;

        return is_string($senha) && $senha !== '' && is_string($hash) && $hash !== '' && Hash::check($senha, $hash);
    }

    protected function empresa(): string
    {
        return (string) session('company');
    }

    protected function negado()
    {
        return $this->erro(static::NEGADO, 403);
    }

    protected function erro(string $mensagem, int $status = 422)
    {
        return response()->json(['errors' => [$mensagem]], $status);
    }
}
```

> O `desconectar` passa ao `tentar` uma função que devolve `null` (o `ConexaoAsaas::desconectar` é `void`): tudo bem, o `tentar` ignora o retorno.

- [ ] **Step 6: Rotas**

Em `api/app/Providers/RouteServiceProvider.php`:

1. Nos `use` dos controllers, em ordem alfabética, acrescente:

```php
use App\Http\Controllers\Entregas\SaquePixController;
use App\Http\Controllers\Entregas\SaquesController;
use App\Http\Controllers\Entregas\ValidacaoAsaasController;
```

2. Depois da linha `RateLimiter::for('entregas-motoboy-recusa', …);`, acrescente:

```php
        // saque Pix pelo app: pedir e acompanhar (o app consulta a cada 3 s por até 1 min), 30 por minuto por motoboy
        RateLimiter::for('entregas-motoboy-saque', fn (Request $request) => Limit::perMinute(30)->by('entregas-motoboy-saque:' . (session('user') ?: $request->ip())));
        // validação de saque do Asaas (sem usuário): 120 por minuto por IP
        RateLimiter::for('entregas-asaas-validacao', fn (Request $request) => Limit::perMinute(120)->by('entregas-asaas-validacao:' . $request->ip()));
```

3. No grupo `Route::prefix('int/v1/entregas')` do console, depois das rotas dos multiplicadores, acrescente:

```php
                        // saque Pix do motoboy pelo app (SaquePixController; admin do sistema ou da organização): conta Asaas
                        // da central, validação de saque, limites, lista dos saques e bloqueio por motoboy
                        Route::get('financeiro/saque-pix', [SaquePixController::class, 'situacao']);
                        Route::put('financeiro/saque-pix', [SaquePixController::class, 'configurar']);
                        Route::post('financeiro/saque-pix/conexao', [SaquePixController::class, 'conectar'])->middleware('throttle:10,1');
                        Route::delete('financeiro/saque-pix/conexao', [SaquePixController::class, 'desconectar'])->middleware('throttle:10,1');
                        Route::post('financeiro/saque-pix/token', [SaquePixController::class, 'gerarToken']);
                        Route::post('financeiro/saque-pix/teste', [SaquePixController::class, 'testar'])->middleware('throttle:10,1');
                        Route::get('financeiro/saques', [SaquePixController::class, 'saques']);
                        Route::put('financeiro/motoboys/{id}/saque', [SaquePixController::class, 'bloquear']);
```

4. Depois do grupo do comprovante (`Route::prefix('v1/entregas/comprovantes')…`), acrescente:

```php
                // Entregas RestaurantePro: saque Pix do motoboy pelo app (SaquesController), com o token dele
                Route::prefix('v1/entregas/motoboy')
                    ->middleware(['fleetbase.api', 'throttle:entregas-motoboy-saque'])
                    ->group(function () {
                        Route::post('saques', [SaquesController::class, 'pedir']);
                        Route::get('saques/{id}', [SaquesController::class, 'mostrar']);
                    });

                // Entregas RestaurantePro: validação de saque do Asaas (o Asaas pergunta antes de cada transferência), sem token
                // de usuário: a central é achada pelo token do cabeçalho asaas-access-token (ValidacaoDeSaque)
                Route::prefix('v1/entregas/asaas')
                    ->middleware(['throttle:entregas-asaas-validacao'])
                    ->group(function () {
                        Route::post('validar-saque', [ValidacaoAsaasController::class, 'validar']);
                    });
```

- [ ] **Step 7: Teste dos imports**

Em `scripts/teste-php/imports-dos-providers.php`, no `array_merge` de `$arquivos`, depois de `glob($app . '/Support/Entregas/Sistema/*.php'),` acrescente `glob($app . '/Support/Entregas/Saques/*.php'),`. Na lista fixa, depois do bloco `// financeiro: API do console e do app (B2)`, acrescente:

```php
        // saque Pix pelo app (Asaas)
        $app . '/Http/Controllers/Entregas/SaquesController.php',
        $app . '/Http/Controllers/Entregas/SaquePixController.php',
        $app . '/Http/Controllers/Entregas/ValidacaoAsaasController.php',
        $app . '/Console/Commands/Entregas/SaquesConferir.php',
        $app . '/Notifications/Entregas/SaqueConcluido.php',
        $app . '/Notifications/Entregas/SaqueNaoConcluido.php',
        $app . '/Notifications/Entregas/PixDeSaqueAlterado.php',
```

- [ ] **Step 8: Rodar e ver passar**

Run: `saque-controllers.php`, `imports-dos-providers.php` e `financeiro-console.php` (ele confere o grupo das rotas do console).
Expected: os três com `FALHAS: 0`.

- [ ] **Step 9: Commit**

```bash
git add api/app/Http/Controllers/Entregas/SaquesController.php api/app/Http/Controllers/Entregas/SaquePixController.php api/app/Http/Controllers/Entregas/ValidacaoAsaasController.php api/app/Providers/RouteServiceProvider.php scripts/teste-php/imports-dos-providers.php scripts/teste-php/saque-controllers.php
git commit -m "Saque Pix: rotas do app (pedir e acompanhar), do console (conexão, token, teste, limites, saques, bloqueio) e da validação

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 13: Stack, modelo do env e README do deploy

**Files:**
- Modify: `deploy/docker-stack.yml`, `deploy/stack.env.example`, `deploy/README.md`
- Test: `scripts/teste-php/saque-implantacao.php`

- [ ] **Step 1: Escrever o teste que falha**

Crie `scripts/teste-php/saque-implantacao.php`:

```php
<?php

// Saque Pix: o stack (ENTREGAS_SAQUE, ASAAS_API_URL e o Docker secret na API e no agendador) e o modelo do stack.env.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-implantacao.php

require __DIR__ . '/stubs-ifood.php';

$stack  = file_get_contents('/repo/deploy/docker-stack.yml');
$modelo = file_get_contents('/repo/deploy/stack.env.example');
$readme = file_get_contents('/repo/deploy/README.md');

confere(str_contains($stack, 'ENTREGAS_SAQUE: ${ENTREGAS_SAQUE:-}'), 'ENTREGAS_SAQUE no x-api-env');
confere(str_contains($stack, 'ASAAS_API_URL: ${ASAAS_API_URL:-https://api.asaas.com/v3}'), 'ASAAS_API_URL com o padrão de produção');
confere(preg_match('/^secrets:\r?\n  entregas_saque_chave:\r?\n    external: true/m', $stack) === 1, 'secret externo entregas_saque_chave no fim do stack');

/** O bloco de um serviço (do nome até o próximo serviço de 2 espaços). */
function servico(string $stack, string $nome): string
{
    preg_match('/^  ' . preg_quote($nome, '/') . ':\r?\n(.*?)(?=^  [a-z][a-z-]*:\r?\n|^[a-z])/ms', $stack, $m);

    return $m[1] ?? '';
}

confere(str_contains(servico($stack, 'application'), 'secrets: [entregas_saque_chave]'), 'a API recebe o secret');
confere(str_contains(servico($stack, 'scheduler'), 'secrets: [entregas_saque_chave]'), 'o agendador recebe o secret (rotina)');
confere(!str_contains(servico($stack, 'queue'), 'entregas_saque_chave') && !str_contains(servico($stack, 'console'), 'entregas_saque_chave'), 'fila e console não precisam do secret');
confere(preg_match('/^ENTREGAS_SAQUE=\r?$/m', $modelo) === 1 && preg_match('/^ASAAS_API_URL=\r?$/m', $modelo) === 1, 'modelo do stack.env');
confere(str_contains($readme, 'openssl rand -base64 32 | docker secret create entregas_saque_chave -'), 'README: como criar o secret');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/saque-implantacao.php`
Expected: FALHA em todos.

- [ ] **Step 3: Stack**

Em `deploy/docker-stack.yml`:

1. No `x-api-env`, logo depois de `ENTREGAS_FINANCEIRO: ${ENTREGAS_FINANCEIRO:-}`, acrescente:

```yaml
  # saque Pix do motoboy pelo app, pela conta Asaas da central: 1 liga. A chave que cifra a chave de API das centrais vem
  # do Docker secret entregas_saque_chave (só application e scheduler). O ensaio usa https://api-sandbox.asaas.com/v3
  ENTREGAS_SAQUE: ${ENTREGAS_SAQUE:-}
  ASAAS_API_URL: ${ASAAS_API_URL:-https://api.asaas.com/v3}
```

2. No serviço `application`, depois do bloco `volumes:` dele, acrescente:

```yaml
    secrets: [entregas_saque_chave]
```

3. No serviço `scheduler`, depois do bloco `volumes:` dele, acrescente a mesma linha `    secrets: [entregas_saque_chave]`.

4. No fim do arquivo, depois do bloco `networks:`, acrescente:

```yaml

# Chave do Cofre do saque Pix (cifra a chave de API do Asaas das centrais). Crie ANTES de atualizar o stack, senão o
# deploy falha com "secret not found":
#   openssl rand -base64 32 | docker secret create entregas_saque_chave -
# Nunca apague nem troque à toa: sem ela, as chaves gravadas não decifram (as centrais precisam conectar de novo).
secrets:
  entregas_saque_chave:
    external: true
```

- [ ] **Step 4: Modelo do env**

Em `deploy/stack.env.example`, logo depois da linha `ENTREGAS_FINANCEIRO=`, acrescente:

```
# Saque Pix do motoboy pelo app (Asaas da central): 1 liga as rotas, a validação de saque e a rotina entregas:saques-conferir.
# Antes, crie o Docker secret entregas_saque_chave (deploy/README.md). Vazio = sem saque (o app não mostra o botão).
ENTREGAS_SAQUE=
# API do Asaas. Vazio = produção (https://api.asaas.com/v3); no ensaio, https://api-sandbox.asaas.com/v3.
ASAAS_API_URL=
```

> Atenção: `ASAAS_API_URL=` vazio no `stack.env` vira string vazia no container e o `${ASAAS_API_URL:-…}` do stack põe o padrão. Confira que o `:-` está lá (o teste do Step 1 confere).

- [ ] **Step 5: README do deploy**

Em `deploy/README.md`, acrescente uma seção no fim:

```markdown
## Saque Pix (Asaas): Docker secret

A chave de API do Asaas de cada central fica cifrada no MySQL. A chave que cifra fica num **Docker secret**, fora do
banco e do Portainer. Crie uma vez, **antes** de colar o `docker-stack.yml` novo no Portainer:

```bash
openssl rand -base64 32 | docker secret create entregas_saque_chave -
docker secret ls | grep entregas_saque_chave
```

- O secret entra só na API (`application`) e no agendador (`scheduler`), em `/run/secrets/entregas_saque_chave`.
- Não apague nem recrie: sem a mesma chave, as chaves do Asaas gravadas não decifram e as centrais precisam conectar de
  novo. Para trocar de propósito: desligue o saque, crie `entregas_saque_chave_2`, aponte o stack para ele e peça às
  centrais para conectar de novo.
- Depois do stack atualizado: `ENTREGAS_SAQUE=1` no `stack.env` (Stacks → entregas → Editor → Update the stack, "Re-pull
  image" desligado).
```

(Dentro do Markdown do README, o bloco de código interno usa três crases: ao editar, mantenha a seção como acima.)

- [ ] **Step 6: Rodar e ver passar**

Run: `saque-implantacao.php` e `financeiro-lancador.php` (confere o stack do financeiro).
Expected: os dois com `FALHAS: 0`.

- [ ] **Step 7: Commit**

```bash
git add deploy/docker-stack.yml deploy/stack.env.example deploy/README.md scripts/teste-php/saque-implantacao.php
git commit -m "Saque Pix: stack com ENTREGAS_SAQUE, ASAAS_API_URL e o Docker secret entregas_saque_chave

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 14: Documentação e conferência geral

**Files:**
- Create: `api/app/Support/Entregas/Saques/CLAUDE.md`
- Modify: `CLAUDE.md` (raiz, tabela "Onde está cada assunto"), `api/app/Support/Entregas/Financeiro/CLAUDE.md`, `api/app/Http/Controllers/Entregas/CLAUDE.md`, `deploy/CLAUDE.md`, `docs/historico.md`, `docs/superpowers/specs/2026-10-26-saque-pix-asaas-design.md`

- [ ] **Step 1: `CLAUDE.md` do assunto**

Crie `api/app/Support/Entregas/Saques/CLAUDE.md`:

```markdown
# Saque Pix do motoboy (Asaas da central)

Parte do `CLAUDE.md` da raiz (índice em "Onde está cada assunto"). Responda e documente em português do Brasil.

O motoboy saca o saldo pelo app e o dinheiro cai na chave Pix cadastrada pela central, saindo da conta Asaas **da
central**. Spec: `docs/superpowers/specs/2026-10-26-saque-pix-asaas-design.md`; plano do servidor:
`docs/superpowers/plans/2026-10-26-saque-pix-asaas-api.md`. Modelo: o pagamento de freelancers do RestaurantePro
(`restaurantepro-main`, edge `freelancer-payout`).

## Segredos

- **Chave de API do Asaas:** `entregas_asaas_conexoes.chave_cifrada`, AES-256-GCM (`Cofre`). A chave que cifra é o
  Docker secret `entregas_saque_chave` (`/run/secrets/…`, `services.entregas.saque_chave_arquivo`), só na API e no
  agendador. Sem o secret: conectar 503, envio falha e devolve, a rotina não consulta. Secret trocado depois de conectar
  (a chave gravada não decifra): testar responde 422 pedindo para conectar a chave de novo.
- **Token da validação:** só o sha256 (`token_hash`), mostrado uma vez no `POST financeiro/saque-pix/token`.
- Nunca em log ou resposta: a chave, o token e a chave Pix inteira (`Saque::chaveEscondida`). A chave tem
  `#[\SensitiveParameter]` (`AsaasCliente::__construct`, `Cofre::cifrar`, `ConexaoAsaas::conectar`): some do stack trace.
- **Só https:** o `AsaasCliente` não chama base sem `https://` (`ASAAS_API_URL` errada: status 0 e warning
  "ASAAS_API_URL precisa ser https") e não segue redirecionamento (`allow_redirects` false: o `access_token` iria junto).

## Camadas

1. Chave Pix e valor sempre do banco; saldo recalculado com a linha do motoboy travada (`lockForUpdate`). O pedido relê a
   conexão travada na mesma transação (central desligada ou desconectada no meio: recusa); desconectar trava a mesma linha
   antes de conferir saque em andamento. Dois "conectar" ao mesmo tempo: o segundo vira troca (a wallet tem de bater).
2. **Validação de saque do Asaas obrigatória** (`ValidacaoDeSaque`, `POST v1/entregas/asaas/validar-saque`): só o saque
   nosso em andamento com o mesmo `transfer.id`, valor e chave recebe APPROVED; o teste de R$ 1,00 é recusado e confirma
   (`validacao_confirmada_em`). Sem ela, o saque não liga. **Teste:** um por vez (o anterior sem resposta da validação
   segura por 2 min: "Espere o resultado do teste anterior."), no máximo 5 por dia de Brasília (`testes_no_dia`,
   `testes_dia`: "Limite de testes de hoje atingido."), com quem pediu (`teste_pedido_por`) e a chave Pix escondida no
   log. Teste que o Asaas recusa ou não responde volta a validação e o teste ao que eram: não desliga o saque de uma
   central já confirmada (se a validação confirmou durante a chamada, responde sem erro).
3. Wallet fixada ao conectar e conferida antes de cada transferência.
4. Troca da chave Pix (`ChavePix::salvar` devolve true): `pix_alterada_em/_por`, 24 h sem saque, push `PixDeSaqueAlterado`.
5. Limites da central (mínimo **≥ R$ 2,00**, para nenhum saque se confundir com o teste de R$ 1,00; máximo/dia; saques/dia;
   taxa; valores de 0 a 9.999.999,99), um saque por vez, uuid do app como idempotência.
6. Bloqueio por motoboy (`saque_bloqueado`).

## Fluxo

- `Saques::pedir` (transação): saque `enviando` + `Pagamentos::registrar` (pix, sistema, "Saque Pix pelo app") + taxa
  (`ajuste` negativo "Taxa do saque Pix", ligado por `taxa_lancamento_id`, sem `pagamento_uuid`).
- `Saques::enviar` (depois do commit, na própria requisição): Cofre → `GET /wallets` → `POST /transfers`
  (`externalReference` = `saque:<uuid>`). Antes do POST, qualquer problema falha e devolve. No POST: id → processando;
  4xx → falha e devolve; sem resposta/5xx/200 sem id → fica `enviando` (a rotina procura).
- `entregas:saques-conferir` (todo minuto, `AcompanhamentoDeSaques`): `GET /transfers/{id}`; sem id há 5 min, procura
  pelo `externalReference` (até 500 recentes): achou adota (só se ainda em andamento), não achou falha. Erro de rede nunca falha.
- **Falha** (`Saques::falhar`): `Pagamentos::estornar` (motivo "Pagamento estornado", que o app reconhece) + estorno da
  taxa ("Taxa do saque devolvida") + push `SaqueNaoConcluido`. **Conclusão**: comprovante do Asaas + push `SaqueConcluido`.
- O pagamento de saque não se estorna à mão (`FinanceiroController@estornarPagamento`: 422).

## Interruptores

- `ENTREGAS_SAQUE` (`Saque::ligada`): desligada, nada de saque (a carteira não traz o bloco; a validação recusa tudo,
  menos o teste).
- Por central: `saque_ligado` (só com a validação confirmada). Token novo desliga e pede novo teste.

## Rotas

| Quem | Método e caminho | Controller |
|---|---|---|
| App (token do motoboy, `throttle:entregas-motoboy-saque`) | POST `v1/entregas/motoboy/saques` `{uuid, valor}` · GET `saques/{uuid}` | `SaquesController` |
| Asaas (`throttle:entregas-asaas-validacao`) | POST `v1/entregas/asaas/validar-saque` | `ValidacaoAsaasController` |
| Console (`AdminDaCentral`: admin do sistema ou papel Administrator) | GET/PUT `int/v1/entregas/financeiro/saque-pix`, POST/DELETE `…/saque-pix/conexao` (senha), POST `…/saque-pix/token`, POST `…/saque-pix/teste`, GET `…/financeiro/saques`, PUT `…/financeiro/motoboys/{id}/saque` | `SaquePixController` |

A carteira do app (`GET v1/entregas/motoboy/carteira`) traz `saque` {disponivel, minimo, maximo_hoje, taxa,
em_andamento, indisponivel_motivo} só quando a central liberou. O extrato e o recibo marcam o pagamento de saque com
`saque` {situacao, comprovante_url}. O painel do Financeiro avisa `saque_falhou` (24 h) e `saque_parado` (1 h).

## Testes

`PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/<teste>.php`: `saque-esquema`,
`saque-cofre`, `saque-asaas`, `saque-conexao`, `saque-avisos`, `saque-pix-alterada`, `saque-pedido`, `saque-envio`,
`saque-validacao`, `saque-acompanhamento`, `saque-financeiro`, `saque-controllers`, `saque-implantacao` (stubs em
`stubs-saque.php`: Http falso com a fila `asaas(status, corpo)`, Hash "hash:<senha>", CompanyUser, secret em arquivo
temporário).

## Armadilhas

- O formato da chave Pix de telefone no Asaas (com ou sem 55) não está documentado: o cadastro guarda DDD + número e é
  enviado assim; a validação aceita o `+55` na comparação. Confirmar no ensaio do sandbox.
- `authorized: false` na resposta do `POST /transfers` = o Asaas espera autorização por token na conta: o saque fica
  processando e aparece como "saque parado" no painel. Confirmar no ensaio se a validação por webhook dispensa o token.
- A validação de saque ligada na conta da central vale para **todo** saque por API daquela conta: a conta precisa ser
  só para isso.
- O dia dos limites é o do servidor (Brasília) = o da empresa; uma central em outro fuso contaria o dia pelo de Brasília.

## Ao atualizar o Laravel, o core-api ou o fleetops-api, confira

- `Fleetbase\Models\CompanyUser::hasRole` e o papel "Administrator" (AdminDaCentral).
- `users.password` acessível no model do usuário (senha do conectar/desconectar) e o `Hash::check`.
- `Driver::notify` e o `CanalFcmEntregas` (pushes).
- `Illuminate\Http\Client` (`Http::withHeaders()->withOptions(['allow_redirects' => false])->acceptJson()->timeout()`), usado pelo `AsaasCliente`.
```

- [ ] **Step 2: Índice da raiz**

Em `CLAUDE.md` (raiz), na tabela "Onde está cada assunto", depois da linha do Financeiro, acrescente:

```markdown
| Saque Pix do motoboy pelo app (Asaas da central, chave cifrada, validação de saque) | `api/app/Support/Entregas/Saques/CLAUDE.md` |
```

- [ ] **Step 3: Financeiro**

Em `api/app/Support/Entregas/Financeiro/CLAUDE.md`, troque a linha que começa com `**Só registro:**` por:

```markdown
**Registro + saque Pix pelo app:** o pagamento feito pela central continua só registro (o dinheiro sai pelo app do banco). O motoboy pode sacar o saldo pelo app quando a central conecta o Asaas e liga o saque (`api/app/Support/Entregas/Saques/CLAUDE.md`): o saque vira um pagamento Pix do sistema, que não se estorna à mão.
```

E na lista "Mensagens 422 que o admin pode ver", acrescente: `"Saque em andamento.", "O dinheiro do saque já saiu; corrija com um ajuste.", "Este saque não foi concluído e o pagamento já foi estornado." (estornar pagamento de saque).`

- [ ] **Step 4: App do motoboy, deploy e histórico**

- Em `api/app/Http/Controllers/Entregas/CLAUDE.md`, na seção do Financeiro no app ("Início = extrato do Financeiro" ou a da carteira), acrescente o parágrafo:

```markdown
- **Saque Pix** (`SaquesController`, ver `api/app/Support/Entregas/Saques/CLAUDE.md`): `POST v1/entregas/motoboy/saques {uuid, valor}` (uuid gerado no app; repetido devolve o mesmo saque) e `GET saques/{uuid}`. A carteira traz o bloco `saque` só quando a central liberou; sem ele, o app esconde o botão. Pushes `entregas_saque` (concluído / não concluído, dados `{saque}`) e `entregas_pix_alterado`, no canal "avisos".
```

- Em `deploy/CLAUDE.md`, acrescente uma seção curta:

```markdown
## Docker secret do saque Pix

`entregas_saque_chave` (externo) cifra a chave de API do Asaas das centrais. Criado à mão na VPS antes do stack novo (`deploy/README.md`); entra só em `application` e `scheduler`. Sem ele o deploy do stack falha ("secret not found"). Não recriar: as chaves gravadas deixam de decifrar.
```

- Em `docs/historico.md`, acrescente o item seguinte da lista (mesmo formato dos outros): "2026-10-26 — Saque Pix do motoboy pelo app (Asaas da central): servidor (plano 1). Chave cifrada com Docker secret, validação de saque obrigatória, limites, carência de 24 h na troca da chave Pix, rotina de acompanhamento."

- [ ] **Step 5: Atualizar o spec**

Em `docs/superpowers/specs/2026-10-26-saque-pix-asaas-design.md`, na seção "Reembolso", troque `motivo "Saque não concluído"` por `motivo padrão "Pagamento estornado" (o app reconhece o estorno de pagamento por esse texto; o motivo da falha fica no saque e no push)` e, no passo 5 da "Reserva", troque `ajuste negativo, motivo "Taxa do saque Pix", pagamento_uuid do saque (para estornar junto)` por `ajuste negativo, motivo "Taxa do saque Pix", ligado ao saque por taxa_lancamento_id (sem pagamento_uuid, para o extrato não mostrar como pagamento)`.

- [ ] **Step 6: Conferência geral**

Rode e confira `FALHAS: 0` em cada um (um comando por arquivo):

```bash
for t in saque-esquema saque-cofre saque-asaas saque-conexao saque-avisos saque-pix-alterada saque-pedido saque-envio saque-validacao saque-acompanhamento saque-financeiro saque-controllers saque-implantacao financeiro-pagamentos financeiro-console financeiro-lancador financeiro-faturas financeiro-lojas financeiro-portal-emails financeiro-multiplicadores entrega-junto ganhos-motoboy avisos-push imports-dos-providers; do
  echo "== $t"; PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php | tail -1
done
```

Expected: 24 linhas `FALHAS: 0`.

Sintaxe de todos os PHP novos e alterados:

```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/Saques/*.php api/app/Http/Controllers/Entregas/SaquesController.php api/app/Http/Controllers/Entregas/SaquePixController.php api/app/Http/Controllers/Entregas/ValidacaoAsaasController.php api/app/Console/Commands/Entregas/SaquesConferir.php api/app/Notifications/Entregas/SaqueConcluido.php api/app/Notifications/Entregas/SaqueNaoConcluido.php api/app/Notifications/Entregas/PixDeSaqueAlterado.php api/app/Notifications/Entregas/AvisosDoMotoboy.php api/app/Support/Entregas/Financeiro/ChavePix.php api/app/Support/Entregas/Financeiro/Carteira.php api/app/Support/Entregas/Financeiro/ExtratoDaConta.php api/app/Support/Entregas/Financeiro/PainelFinanceiro.php api/app/Http/Controllers/Entregas/FinanceiroController.php api/app/Providers/RouteServiceProvider.php api/app/Console/Kernel.php api/config/services.php api/database/migrations/2026_10_27_100000_create_entregas_financeiro_saques_tables.php
```

Expected: sai com 0.

Procure vazamento de segredo em log: `grep -n "Log::" api/app/Support/Entregas/Saques/*.php` e confira, linha a linha, que nenhum contexto leva `$chave`, `$token`, `chave_cifrada`, `chave_pix` ou `$chaveApi`.

- [ ] **Step 7: Commit**

```bash
git add api/app/Support/Entregas/Saques/CLAUDE.md CLAUDE.md api/app/Support/Entregas/Financeiro/CLAUDE.md api/app/Http/Controllers/Entregas/CLAUDE.md deploy/CLAUDE.md docs/historico.md docs/superpowers/specs/2026-10-26-saque-pix-asaas-design.md
git commit -m "Saque Pix: documentação do assunto, índice, Financeiro, app, deploy e histórico

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 15: Revisão de segurança (antes do merge)

**Files:** nenhum novo (correções, se houver, com teste).

- [ ] **Step 1:** Rode uma revisão de segurança focada no diff do ramo (`git diff main...saque-pix -- api/`), com um subagente `code-security-skills:code-security-reviewer`. Peça que confira, no mínimo:
  - todo caminho que chega ao `POST /transfers` (só `Saques::enviar` e `ConexaoAsaas::testarValidacao`) e que o destino sempre vem do banco;
  - que o `ValidacaoDeSaque` não aprova nada além do saque nosso em andamento (id, valor, chave, empresa do token);
  - concorrência no `Saques::pedir` (trava, um em andamento, uuid único) e no `falhar`/`concluir` (update condicional);
  - autorização de cada rota do console (`AdminDaCentral`) e do app (`MotoboyDaSessao`, só o saque dele);
  - nada de segredo em log, resposta ou exceção;
  - o Cofre (GCM, IV aleatório, tag conferida, chave fora do banco).
- [ ] **Step 2:** Para cada achado confirmado: escreva o teste que falha no `saque-*.php` do assunto, corrija, rode a conferência geral da Task 14 e faça um commit por correção.
- [ ] **Step 3:** Merge **local** na `main` só com o OK do Edgard; push e deploy são dele.

---

### Task 16: Implantação e ensaio no sandbox (depois dos planos 2 e 3)

Passos manuais com o Edgard, na ordem. Nada aqui é feito sem o OK dele.

- [ ] **Step 1:** Na VPS: `openssl rand -base64 32 | docker secret create entregas_saque_chave -` e `docker secret ls`.
- [ ] **Step 2:** Push da `main` e `bash deploy/atualizar.sh` (api e console). A migration roda no `deploy.sh`.
- [ ] **Step 3:** Portainer → Stacks → entregas → Editor: colar o `docker-stack.yml` novo, `ENTREGAS_SAQUE=1` e `ASAAS_API_URL=https://api-sandbox.asaas.com/v3` no env, "Re-pull image" **desligado**, Update the stack. Conferir: `docker exec $(docker ps -q -f name=entregas_application) ls /run/secrets/` mostra `entregas_saque_chave`.
- [ ] **Step 4:** Com uma conta de **sandbox** do Asaas: no console, conectar a chave de sandbox, gerar o token, configurar a validação no Asaas sandbox (Integrações → Mecanismos de segurança → URL `https://entregas-api.restaurantepro.com.br/v1/entregas/asaas/validar-saque` + token), testar a validação (R$ 1,00 recusado), ligar o saque com mínimo R$ 2 (o menor permitido).
- [ ] **Step 5:** Com um motoboy de teste (não da Terraço), APK do plano 3: saldo de teste por lançamento manual, saque de R$ 1 a R$ 5, ver "Caiu no seu Pix" e o comprovante. Testar: chave de telefone (formato com/sem 55), saque recusado (sem saldo no sandbox), troca de chave Pix (carência e push), bloqueio, limite do dia. Conferir nos logs (`docker service logs entregas_application | grep '\[entregas\] saque'`) que nenhum segredo aparece.
- [ ] **Step 6:** Anotar em `api/app/Support/Entregas/Saques/CLAUDE.md` o formato do telefone que funcionou e se apareceu `authorized: false`.
- [ ] **Step 7:** Produção: desconectar a conta de sandbox, trocar `ASAAS_API_URL` para vazio (produção) no stack, a central conecta a chave real, configura a validação real, testa e liga. Primeiro saque real com um motoboy avisado e valor pequeno.
