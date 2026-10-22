# Corridas do sistema (relatório do dono): plano de implementação

> **Para agentes:** SUB-SKILL OBRIGATÓRIA: use superpowers:subagent-driven-development (recomendado) ou superpowers:executing-plans para executar este plano tarefa por tarefa. Os passos usam caixas (`- [ ]`) para acompanhar.

**Objetivo:** o administrador do sistema (Edgard) vê, no Fleet-Ops → Financeiro → Corridas do sistema, todas as corridas finalizadas de todas as organizações (centrais), separadas por central, com quantidade × preço por corrida = valor a cobrar, e cadastra o preço de cada central com vigência.

**Arquitetura:** um registro permanente próprio (`entregas_corridas_finalizadas`, uma linha por pedido, nunca atualizada nem apagada) alimentado por um observador de `Order::updated`, por uma conferência a cada 5 min (`entregas:corridas-conferir`, últimos 2 dias) e, com a tabela vazia, por uma importação do histórico. Os preços ficam em `entregas_precos_centrais` (um por central e data "vale a partir de"); o relatório calcula o preço de cada corrida na hora da consulta. Regras em `App\Support\Entregas\Sistema\` (`CorridasFinalizadas`, `PrecosDasCentrais`, `RelatorioDasCorridas`), API em `CorridasDoSistemaController` com a trava `negarSeNaoDonoDoSistema`, tela nova `financeiro.corridas-do-sistema` com um modal.

**Stack:** Laravel (PHP 8.2) em `api/app`, testes php-wasm em `scripts/teste-php/`; Ember (engine `@fleetbase/fleetops-engine`), ember-concurrency, testes `node --test` em `scripts/teste-portal/`.

**Spec:** `docs/superpowers/specs/2026-10-22-corridas-do-sistema-design.md`.

---

## Antes de começar

- Leia `CLAUDE.md` (raiz), `api/CLAUDE.md` ("Fuso"), `api/app/Support/Entregas/Financeiro/CLAUDE.md`, `packages/fleetops/CLAUDE.md` ("Financeiro": armadilha do `modals-manager`, valor digitado, datas, CSV) e `packages/CLAUDE.md` (tradução).
- Trabalhe numa worktree própria (`superpowers:using-git-worktrees`), ramo `corridas-do-sistema`, a partir da `main` do `C:\Users\Edgardjr\Documents\vibe coding\Delivery`, em `C:\tmp\cs`. **Confira `git rev-parse --show-toplevel` antes de cada commit:** a home `C:\Users\Edgardjr` também é um repo git. Nunca commite `deploy/stack.env`, `api/.env` nem `console/dist`. Use `git add` com os arquivos da tarefa, nunca `git add -A`.
- A worktree não tem `console/node_modules` (o `i18n-check` e o `@babel/parser` precisam dele). Crie uma junção, uma vez, no PowerShell:
  ```powershell
  if (-not (Test-Path C:\tmp\cs\console\node_modules)) { cmd /c mklink /J "C:\tmp\cs\console\node_modules" "C:\Users\Edgardjr\Documents\vibe coding\Delivery\console\node_modules" }
  ```
  Ao remover a worktree no fim, tire a junção antes (`cmd /c rmdir C:\tmp\cs\console\node_modules`), senão a remoção apaga o `node_modules` do checkout principal.
- php-wasm: a instalação fica em `/c/tmp/php-wasm` (se não existir: `npm i --prefix /c/tmp/php-wasm @php-wasm/node@3.1.54 @php-wasm/universal@3.1.54`). Os comandos abaixo rodam **na raiz da worktree**, no Git Bash:
  - um teste: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/<arquivo>.php` (passa quando a saída termina em `FALHAS: 0`);
  - sintaxe: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs <arquivos.php...>`;
  - front: `node --import ./scripts/teste-portal/resolver.mjs --test <arquivo>` (passa com `# fail 0`).
- Fuso: tudo em hora de Brasília, texto `'Y-m-d H:i:s'` sem fuso. Nunca formate em UTC para gravar nem para consultar.
- Comentários, mensagens e commits em pt-BR, no estilo dos arquivos vizinhos.

## Mapa dos arquivos

| Arquivo | Papel |
|---|---|
| `api/database/migrations/2026_10_22_100000_create_entregas_sistema_tables.php` (novo) | tabelas `entregas_corridas_finalizadas` e `entregas_precos_centrais` |
| `scripts/teste-php/stubs-ifood.php` | o banco em memória passa a ler as migrations `*_entregas_sistema_*.php` |
| `api/app/Support/Entregas/Sistema/CorridasFinalizadas.php` (novo) | registro permanente: gravar (idempotente), conferir, histórico |
| `api/app/Support/Entregas/Sistema/PrecosDasCentrais.php` (novo) | preços por central: validação, criar, apagar, lista, vigência, nomes das centrais |
| `api/app/Support/Entregas/Sistema/RelatorioDasCorridas.php` (novo) | relatório do período: período, consulta e agregação (pura) |
| `api/app/Listeners/Entregas/ObservadorDasCorridasDoSistema.php` (novo) | `Order::updated` → grava a corrida na conclusão |
| `api/app/Providers/AppServiceProvider.php` | registra o observador |
| `api/app/Console/Commands/Entregas/CorridasConferir.php` (novo) | `entregas:corridas-conferir {--historico}` |
| `api/app/Console/Kernel.php` | agenda a conferência a cada 5 min, sem `when` |
| `api/app/Http/Controllers/Entregas/CorridasDoSistemaController.php` (novo) | rotas `sistema/*` com `negarSeNaoDonoDoSistema` |
| `api/app/Providers/RouteServiceProvider.php` | rotas novas no grupo `int/v1/entregas` |
| `scripts/teste-php/corridas-do-sistema.php` (novo) | teste php-wasm de tudo acima |
| `scripts/teste-php/ifood-agendador.php` | tira o comando novo da lista exata de comandos do iFood |
| `scripts/teste-php/imports-dos-providers.php` | confere os `use` dos arquivos novos |
| `packages/fleetops/addon/utils/corridas-do-sistema.js` (novo) | funções puras: preço "variado", data e hora, origem, CSV, formulário do preço |
| `scripts/teste-portal/corridas-do-sistema.test.mjs` (novo) | teste das funções puras |
| `packages/fleetops/translations/pt-br.yaml`, `en-us.yaml` | `fleet-ops.ui.financeiro.corridas-do-sistema.*` e o item do menu |
| `packages/fleetops/addon/routes.js` | rota `financeiro.corridas-do-sistema` |
| `packages/fleetops/addon/routes/financeiro/corridas-do-sistema.js` (novo) | guarda própria do administrador do sistema e carga |
| `packages/fleetops/addon/controllers/financeiro/corridas-do-sistema.js` (novo) | período, relatório, preços, CSV |
| `packages/fleetops/addon/templates/financeiro/corridas-do-sistema.hbs` (novo) | a tela |
| `packages/fleetops/addon/components/modals/preco-da-central.{js,hbs}` (novos) e `packages/fleetops/app/components/modals/preco-da-central.js` (re-export) | modal "Novo preço por corrida" |
| `packages/fleetops/addon/components/layout/fleet-ops-sidebar.js` | item do menu, último do Financeiro, com `visible` próprio |
| `api/app/Support/Entregas/Sistema/CLAUDE.md` (novo), `CLAUDE.md` (raiz), `packages/fleetops/CLAUDE.md`, `api/app/Support/Entregas/Financeiro/CLAUDE.md`, `deploy/zerar-pedidos/LEIAME.md`, `deploy/zerar-pedidos/1-inventario.sql`, `docs/historico.md` | documentação |

### Decisões tomadas no plano (detalhes que a spec não fixa)

- **Terceira classe `RelatorioDasCorridas`** (a spec cita só `CorridasFinalizadas` e `PrecosDasCentrais`): a agregação é uma função pura (`agregar`) testada sem banco; o `montar` só valida o período e consulta.
- **Leitura pelo `DB::table`** (`orders`, `tracking_statuses`, `vendors`, `drivers`, `users`, `companies`): sem os escopos do Eloquent, o pedido e a organização excluídos (soft delete) entram sem `withTrashed`. É também o que o banco em memória dos testes simula.
- **`tracking_statuses` excluídos também contam** para achar o `COMPLETED` (o `CalculoEntregas` filtra `deleted_at`; aqui a regra é "nunca sai", e o pedido excluído pode ter levado os status junto).
- **Janela da conferência:** "últimos 2 dias" = `orders.updated_at` ≥ agora − 2 dias para o status concluído, e `tracking_statuses.created_at` ≥ agora − 2 dias para o `COMPLETED` no rastreio.
- **Índices:** em vez de um índice só em `company_uuid` mais o composto, fica só o composto (`company_uuid`, `finalizada_em`), que já serve às consultas por central; o mesmo vale para o único (`company_uuid`, `vale_a_partir_de`) dos preços. `finalizada_em` tem índice próprio (o relatório filtra só pela data).
- **`GET sistema/precos`** devolve as organizações não excluídas e também as excluídas que têm preço (para dar para apagar o preço delas).
- **Ordem:** centrais por nome; preços de cada central do mais recente para o mais antigo; corridas por `finalizada_em` e `id`.
- **Agenda:** `withoutOverlapping(30)` (e não 10): a primeira rodada importa o histórico inteiro e pode demorar.
- **Data e hora na tela e no CSV** pela hora escrita no ISO do servidor (`-03:00`), sem converter para o fuso do navegador.
- **Ordem das tarefas:** as traduções vêm antes da tela (Tarefa 8), para todo commit passar no `i18n-check`.

---

### Tarefa 1: Migrations e o esquema no banco em memória

**Arquivos:**
- Criar: `api/database/migrations/2026_10_22_100000_create_entregas_sistema_tables.php`
- Modificar: `scripts/teste-php/stubs-ifood.php` (função `Banco::esquema`, lista de globs)
- Criar: `scripts/teste-php/corridas-do-sistema.php`

- [ ] **Passo 1: Escreva o teste (esqueleto do arquivo + seção das migrations)**

Crie `scripts/teste-php/corridas-do-sistema.php`. O arquivo já traz os `use` e os ajudantes de todas as tarefas (o `use` do PHP não carrega a classe, e um ajudante só falha se for chamado). As tarefas seguintes acrescentam seções **antes da linha `    resumo();`**.

```php
<?php

// Corridas do sistema (relatório do dono, todas as organizações): migrations, registro permanente
// (CorridasFinalizadas), preço por central (PrecosDasCentrais), relatório (RelatorioDasCorridas), observador, comando
// entregas:corridas-conferir, agenda, controller (403 para quem não é o administrador do sistema) e rotas.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/corridas-do-sistema.php

namespace {
    require __DIR__ . '/stubs-financeiro.php';
    date_default_timezone_set('America/Sao_Paulo');

    use App\Console\Commands\Entregas\CorridasConferir;
    use App\Console\Kernel;
    use App\Http\Controllers\Entregas\CorridasDoSistemaController;
    use App\Listeners\Entregas\ObservadorDasCorridasDoSistema;
    use App\Support\Entregas\Sistema\CorridasFinalizadas;
    use App\Support\Entregas\Sistema\PrecosDasCentrais;
    use App\Support\Entregas\Sistema\RelatorioDasCorridas;
    use Fleetbase\FleetOps\Models\Order;
    use Fleetbase\Support\Auth;
    use Illuminate\Console\Scheduling\Schedule;
    use Illuminate\Http\Request;
    use Teste\Banco;
    use Teste\Relogio;

    function preparar(): void
    {
        reiniciarFinanceiro();
        Relogio::$agora = '2026-10-22 10:00:00';
    }

    /** Organizações (central-a, central-b e uma excluída), a loja, o motoboy e o usuário dele. */
    function cadastros(): void
    {
        Banco::inserir('companies', ['uuid' => 'central-a', 'name' => 'Central Norte', 'deleted_at' => null], false);
        Banco::inserir('companies', ['uuid' => 'central-b', 'name' => 'Central Sul', 'deleted_at' => null], false);
        Banco::inserir('companies', ['uuid' => 'central-x', 'name' => 'Central Antiga', 'deleted_at' => '2026-09-01 10:00:00'], false);
        Banco::inserir('vendors', ['uuid' => 'loja-1', 'name' => 'Pizzaria Boa', 'company_uuid' => 'central-a'], false);
        Banco::inserir('drivers', ['uuid' => 'motoboy-1', 'user_uuid' => 'usuario-1', 'company_uuid' => 'central-a'], false);
        Banco::inserir('users', ['uuid' => 'usuario-1', 'name' => 'Carlos Silva'], false);
    }

    /** Uma linha de orders (uuid = 'uuid-' + public_id, salvo se vier); devolve a linha como objeto. */
    function pedido(array $campos): object
    {
        $linha = $campos + ['company_uuid' => 'central-a', 'status' => 'completed', 'internal_id' => null, 'customer_uuid' => null, 'driver_assigned_uuid' => null, 'tracking_number_uuid' => null, 'updated_at' => '2026-10-22 09:00:00', 'deleted_at' => null];
        $linha['uuid'] ??= 'uuid-' . $linha['public_id'];
        Banco::inserir('orders', $linha, false);

        return (object) $linha;
    }

    /** Status COMPLETED no rastreio (tracking_statuses), com a hora em Brasília. */
    function concluidoNoRastreio(string $rastreio, string $quando): void
    {
        Banco::inserir('tracking_statuses', ['tracking_number_uuid' => $rastreio, 'code' => 'COMPLETED', 'company_uuid' => 'central-a', 'created_at' => $quando, 'deleted_at' => null], false);
    }

    /** A linha do registro de um pedido (pelo public_id), ou []. */
    function linhaDoPedido(string $publicId): array
    {
        foreach (linhasDe('entregas_corridas_finalizadas') as $linha) {
            if ($linha['pedido'] === $publicId) {
                return $linha;
            }
        }

        return [];
    }

    /** Os public_id registrados, em ordem alfabética. */
    function pedidosRegistrados(): array
    {
        $pedidos = array_column(linhasDe('entregas_corridas_finalizadas'), 'pedido');
        sort($pedidos);

        return $pedidos;
    }

    /** Uma corrida já registrada (linha pronta), para o relatório. */
    function corrida(string $pedido, string $company, string $finalizadaEm, array $extra = []): void
    {
        Banco::inserir('entregas_corridas_finalizadas', $extra + [
            'order_uuid' => 'uuid-' . $pedido, 'company_uuid' => $company, 'pedido' => $pedido, 'numero' => null,
            'loja_uuid' => null, 'loja_nome' => null, 'motoboy_uuid' => null, 'motoboy_nome' => null,
            'finalizada_em' => $finalizadaEm, 'origem' => 'conclusao', 'created_at' => $finalizadaEm,
        ], false);
    }

    /** Um preço já cadastrado (linha pronta). */
    function preco(string $company, string $valor, string $desde): void
    {
        Banco::inserir('entregas_precos_centrais', ['company_uuid' => $company, 'valor' => $valor, 'vale_a_partir_de' => $desde, 'criado_por' => null, 'created_at' => '2026-10-01 08:00:00'], false);
    }

    /** O usuário da sessão: 'admin' = administrador do sistema; 'user' = qualquer outro (inclusive o Administrator de uma organização). */
    function usuario(string $tipo): object
    {
        return new class($tipo) {
            public string $uuid = 'user-edgard';
            public function __construct(public string $type) {}
            public function isAdmin(): bool { return $this->type === 'admin'; }
            public function isNotAdmin(): bool { return $this->type !== 'admin'; }
        };
    }

    function api(): CorridasDoSistemaController
    {
        return new CorridasDoSistemaController();
    }

    function req(array $dados = []): Request
    {
        return new Request($dados);
    }

    function erroDe($resposta): string
    {
        return (string) ($resposta->dados['errors'][0] ?? '');
    }

    echo '== Migrations' . PHP_EOL;
    preparar();
    \Illuminate\Support\Facades\Schema::$criadas = [];
    $migration = require '/repo/api/database/migrations/2026_10_22_100000_create_entregas_sistema_tables.php';
    $migration->up();
    $colunasDe = fn (string $tabela) => array_values(array_filter(array_map(fn ($c) => $c->tipo === 'id' ? 'id' : ($c->argumentos[0] ?? null), \Illuminate\Support\Facades\Schema::$criadas[$tabela]->colunas ?? []), 'is_string')); // index([...]) tem lista, não nome
    $corridas  = $colunasDe('entregas_corridas_finalizadas');
    confere(array_diff(['id', 'order_uuid', 'company_uuid', 'pedido', 'numero', 'loja_uuid', 'loja_nome', 'motoboy_uuid', 'motoboy_nome', 'finalizada_em', 'origem', 'created_at'], $corridas) === [], 'corridas: as colunas que a classe usa');
    confere(!in_array('updated_at', $corridas, true) && !in_array('deleted_at', $corridas, true), 'corridas: sem updated_at nem deleted_at (a linha nunca muda nem sai)');
    confere(array_diff(['id', 'company_uuid', 'valor', 'vale_a_partir_de', 'criado_por', 'created_at'], $colunasDe('entregas_precos_centrais')) === [], 'preços: as colunas');
    $migration->down();
    confere(!isset(\Illuminate\Support\Facades\Schema::$criadas['entregas_corridas_finalizadas']) && !isset(\Illuminate\Support\Facades\Schema::$criadas['entregas_precos_centrais']), 'o down apaga as duas');
    confere(Banco::unicasDe('entregas_corridas_finalizadas') === ['order_uuid'], 'order_uuid único (o banco em memória leu a migration)');
    Banco::inserir('entregas_precos_centrais', ['company_uuid' => 'central-a', 'valor' => '2.00', 'vale_a_partir_de' => '2026-10-01'], false);
    confere(excecao(fn () => Banco::inserir('entregas_precos_centrais', ['company_uuid' => 'central-a', 'valor' => '3.00', 'vale_a_partir_de' => '2026-10-01'], false)) instanceof \Illuminate\Database\QueryException, 'preços: uma linha por central e data');

    resumo();
}
```

- [ ] **Passo 2: Rode e veja falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/corridas-do-sistema.php`
Expected: FAIL, erro de PHP `Failed opening required '/repo/api/database/migrations/2026_10_22_100000_create_entregas_sistema_tables.php'` e exit 1.

- [ ] **Passo 3: Crie a migration**

`api/database/migrations/2026_10_22_100000_create_entregas_sistema_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: corridas do sistema (relatório do dono, todas as organizações). Desenho:
 * docs/superpowers/specs/2026-10-22-corridas-do-sistema-design.md.
 *
 * - entregas_corridas_finalizadas: uma linha por pedido finalizado (order_uuid único), de qualquer organização. Sem
 *   updated_at e sem deleted_at: o código nunca atualiza nem apaga (cancelar, excluir o pedido ou o
 *   deploy/zerar-pedidos não tiram). Nomes da loja e do motoboy gravados na hora; finalizada_em em Brasília.
 * - entregas_precos_centrais: o preço por corrida de cada central (organização), um por data "vale a partir de".
 *
 * O nome tem `_entregas_sistema_` porque o banco em memória dos testes lê o esquema por glob. Roda no
 * `php artisan migrate --force` do deploy.sh, no banco principal e no sandbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_corridas_finalizadas', function (Blueprint $table) {
            $table->id();
            $table->char('order_uuid', 36)->unique();
            $table->char('company_uuid', 36);           // a central (organização dona do pedido)
            $table->string('pedido', 64);               // orders.public_id
            $table->string('numero', 191)->nullable();  // orders.internal_id (número da loja / iFood)
            $table->char('loja_uuid', 36)->nullable();  // vendors.uuid quando o cliente do pedido é uma loja
            $table->string('loja_nome', 191)->nullable();
            $table->char('motoboy_uuid', 36)->nullable(); // drivers.uuid (driver_assigned_uuid na conclusão)
            $table->string('motoboy_nome', 191)->nullable();
            $table->dateTime('finalizada_em')->index(); // Brasília
            $table->string('origem', 16);               // conclusao | conferencia | historico
            $table->timestamp('created_at')->nullable();
            $table->index(['company_uuid', 'finalizada_em'], 'ent_corr_fin_empresa_data');
        });

        Schema::create('entregas_precos_centrais', function (Blueprint $table) {
            $table->id();
            $table->char('company_uuid', 36);
            $table->decimal('valor', 10, 2);           // R$ por corrida, de 0,00 a 1000,00
            $table->date('vale_a_partir_de');          // dia em Brasília
            $table->char('criado_por', 36)->nullable(); // users.uuid
            $table->timestamp('created_at')->nullable();
            $table->unique(['company_uuid', 'vale_a_partir_de'], 'ent_precos_centrais_empresa_data');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_precos_centrais');
        Schema::dropIfExists('entregas_corridas_finalizadas');
    }
};
```

- [ ] **Passo 4: O banco em memória lê a migration nova**

Em `scripts/teste-php/stubs-ifood.php`, dentro de `Banco::esquema()`, troque:

```php
                // as do financeiro: a das cinco tabelas (entregas_lancamentos, entregas_pagamentos, entregas_faturas...) e a dos multiplicadores (entregas_multiplicadores)
                glob(dirname(__DIR__, 2) . '/api/database/migrations/*_entregas_financeiro_*.php') ?: []
            );
```

por:

```php
                // as do financeiro: a das cinco tabelas (entregas_lancamentos, entregas_pagamentos, entregas_faturas...) e a dos multiplicadores (entregas_multiplicadores)
                glob(dirname(__DIR__, 2) . '/api/database/migrations/*_entregas_financeiro_*.php') ?: [],
                // as das corridas do sistema (entregas_corridas_finalizadas e entregas_precos_centrais)
                glob(dirname(__DIR__, 2) . '/api/database/migrations/*_entregas_sistema_*.php') ?: []
            );
```

- [ ] **Passo 5: Rode e veja passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/corridas-do-sistema.php`
Expected: 6 linhas `PASSA` e a saída termina em `FALHAS: 0`.

Run (o esquema mudou para todos os testes que usam o banco em memória): `for t in financeiro-multiplicadores financeiro-pagamentos ifood-migrations integracao-migrations; do PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php | tail -1; done`
Expected: quatro linhas `FALHAS: 0`.

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/database/migrations/2026_10_22_100000_create_entregas_sistema_tables.php`
Expected: exit 0, sem erro de sintaxe.

- [ ] **Passo 6: Commit**

```bash
git rev-parse --show-toplevel   # esperado: C:/tmp/cs (nunca C:/Users/Edgardjr)
git add api/database/migrations/2026_10_22_100000_create_entregas_sistema_tables.php scripts/teste-php/stubs-ifood.php scripts/teste-php/corridas-do-sistema.php
git commit -m "$(cat <<'EOF'
Corridas do sistema: tabelas do registro permanente e dos preços por central

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Tarefa 2: `CorridasFinalizadas` (registro permanente)

**Arquivos:**
- Criar: `api/app/Support/Entregas/Sistema/CorridasFinalizadas.php`
- Modificar: `scripts/teste-php/corridas-do-sistema.php` (seções novas antes de `    resumo();`)

- [ ] **Passo 1: Escreva os testes**

Acrescente antes da linha `    resumo();`:

```php
    echo '== Registro (CorridasFinalizadas)' . PHP_EOL;
    preparar();
    cadastros();
    $p1 = pedido(['public_id' => 'order_a1', 'internal_id' => '4821', 'customer_uuid' => 'loja-1', 'driver_assigned_uuid' => 'motoboy-1', 'tracking_number_uuid' => 'rastreio-a1', 'updated_at' => '2026-10-22 09:40:00']);
    concluidoNoRastreio('rastreio-a1', '2026-10-22 09:35:00');
    concluidoNoRastreio('rastreio-a1', '2026-10-22 09:31:00');
    confere(CorridasFinalizadas::registrarLote([$p1], CorridasFinalizadas::CONFERENCIA) === 1, 'grava o pedido concluído');
    $linha = linhaDoPedido('order_a1');
    confere(($linha['company_uuid'] ?? null) === 'central-a' && ($linha['order_uuid'] ?? null) === 'uuid-order_a1' && ($linha['numero'] ?? null) === '4821', 'central, pedido e número');
    confere(($linha['loja_uuid'] ?? null) === 'loja-1' && $linha['loja_nome'] === 'Pizzaria Boa' && $linha['motoboy_uuid'] === 'motoboy-1' && $linha['motoboy_nome'] === 'Carlos Silva', 'nomes da loja e do motoboy gravados na hora');
    confere(($linha['finalizada_em'] ?? null) === '2026-10-22 09:31:00' && $linha['origem'] === 'conferencia' && $linha['created_at'] === '2026-10-22 10:00:00', 'finalizada_em = o primeiro COMPLETED do rastreio (Brasília)');
    confere(CorridasFinalizadas::registrarLote([$p1], CorridasFinalizadas::HISTORICO) === 0 && CorridasFinalizadas::registrarPedido($p1, CorridasFinalizadas::CONCLUSAO, '2026-10-22 11:00:00') === false, 'o mesmo pedido de novo: não grava');
    confere(count(linhasDe('entregas_corridas_finalizadas')) === 1 && linhaDoPedido('order_a1')['origem'] === 'conferencia' && linhaDoPedido('order_a1')['finalizada_em'] === '2026-10-22 09:31:00', 'idempotente: fica a primeira linha, sem mudar');

    $semRastreio = pedido(['public_id' => 'order_a2', 'customer_uuid' => 'contato-1', 'updated_at' => '2026-10-22 08:00:00']);
    $motoboySumido = pedido(['public_id' => 'order_a3', 'driver_assigned_uuid' => 'motoboy-apagado', 'updated_at' => '2026-10-22 08:10:00']);
    confere(CorridasFinalizadas::registrarLote([$semRastreio, $motoboySumido, $semRastreio], CorridasFinalizadas::CONFERENCIA) === 2, 'lote com repetido: uma linha por pedido');
    $l2 = linhaDoPedido('order_a2');
    confere(($l2['finalizada_em'] ?? null) === '2026-10-22 08:00:00', 'sem COMPLETED no rastreio: o updated_at do pedido');
    confere($l2['loja_uuid'] === null && $l2['loja_nome'] === null && $l2['motoboy_uuid'] === null && $l2['motoboy_nome'] === null && $l2['numero'] === null, 'cliente que não é loja e sem motoboy: campos vazios');
    $l3 = linhaDoPedido('order_a3');
    confere(($l3['motoboy_uuid'] ?? null) === 'motoboy-apagado' && $l3['motoboy_nome'] === null, 'motoboy que não existe mais: fica o uuid, sem nome');

    $doObservador = pedido(['public_id' => 'order_a4', 'tracking_number_uuid' => 'rastreio-a4']);
    concluidoNoRastreio('rastreio-a4', '2026-10-22 09:00:00');
    confere(CorridasFinalizadas::registrarPedido($doObservador, CorridasFinalizadas::CONCLUSAO, '2026-10-22 10:00:00') === true && linhaDoPedido('order_a4')['finalizada_em'] === '2026-10-22 10:00:00' && linhaDoPedido('order_a4')['origem'] === 'conclusao', 'a hora passada pelo observador vale');

    $comCarbon = (object) ['uuid' => 'uuid-order_a5', 'company_uuid' => 'central-a', 'public_id' => 'order_a5', 'updated_at' => \Illuminate\Support\Carbon::parse('2026-10-22T12:00:00Z')];
    CorridasFinalizadas::registrarLote([$comCarbon], CorridasFinalizadas::CONFERENCIA);
    confere((linhaDoPedido('order_a5')['finalizada_em'] ?? null) === '2026-10-22 09:00:00', 'updated_at em Carbon UTC: gravado na hora de Brasília');
    confere(CorridasFinalizadas::registrarLote([(object) ['uuid' => 'sem-empresa', 'public_id' => 'order_x']], CorridasFinalizadas::CONFERENCIA) === 0, 'pedido sem organização: ignorado');
    confere(CorridasFinalizadas::iso('2026-10-22 09:31:00') === '2026-10-22T09:31:00-03:00', 'iso: o instante com o fuso de Brasília');

    echo '== Conferência (CorridasFinalizadas::conferir)' . PHP_EOL;
    preparar();
    cadastros();
    pedido(['public_id' => 'order_ok', 'updated_at' => '2026-10-21 20:00:00']);
    pedido(['public_id' => 'order_done', 'status' => 'done', 'updated_at' => '2026-10-22 09:00:00']);
    pedido(['public_id' => 'order_excluido', 'updated_at' => '2026-10-22 09:10:00', 'deleted_at' => '2026-10-22 09:20:00']);
    pedido(['public_id' => 'order_cancelado_depois', 'status' => 'canceled', 'tracking_number_uuid' => 'rastreio-cd', 'updated_at' => '2026-10-22 09:50:00']);
    concluidoNoRastreio('rastreio-cd', '2026-10-22 09:30:00');
    pedido(['public_id' => 'order_cancelado', 'status' => 'canceled', 'updated_at' => '2026-10-22 09:00:00']);
    pedido(['public_id' => 'order_andamento', 'status' => 'started', 'updated_at' => '2026-10-22 09:00:00']);
    pedido(['public_id' => 'order_antigo', 'updated_at' => '2026-10-10 09:00:00']);
    pedido(['public_id' => 'order_outra_central', 'company_uuid' => 'central-b', 'updated_at' => '2026-10-22 09:00:00']);
    pedido(['public_id' => 'order_ja_tem', 'updated_at' => '2026-10-22 09:00:00']);
    corrida('order_ja_tem', 'central-a', '2026-10-22 08:59:00');
    $n = CorridasFinalizadas::conferir('2026-10-20 10:00:00');
    confere($n === 5, "grava as 5 que faltavam ({$n})");
    confere(pedidosRegistrados() === ['order_cancelado_depois', 'order_done', 'order_excluido', 'order_ja_tem', 'order_ok', 'order_outra_central'], 'concluído, done, excluído, cancelado depois do COMPLETED e de outra central entram; cancelado sem conclusão, em andamento e antigo não');
    confere(linhaDoPedido('order_cancelado_depois')['finalizada_em'] === '2026-10-22 09:30:00' && linhaDoPedido('order_cancelado_depois')['origem'] === 'conferencia', 'o cancelado depois de finalizado: pela hora do COMPLETED');
    confere(linhaDoPedido('order_ja_tem')['origem'] === 'conclusao', 'o que já tinha linha fica como estava');
    confere(CorridasFinalizadas::conferir('2026-10-20 10:00:00') === 0, 'de novo: nada');

    echo '== Histórico (CorridasFinalizadas::importarHistorico)' . PHP_EOL;
    preparar();
    cadastros();
    foreach ([1, 2, 3, 4, 5] as $i) {
        pedido(['public_id' => "order_h{$i}", 'updated_at' => "2025-12-0{$i} 12:00:00"]);
    }
    pedido(['public_id' => 'order_h_cancelado', 'status' => 'canceled', 'tracking_number_uuid' => 'rastreio-h', 'updated_at' => '2026-01-01 12:00:00']);
    concluidoNoRastreio('rastreio-h', '2025-12-31 23:50:00');
    pedido(['public_id' => 'order_h_aberto', 'status' => 'created', 'updated_at' => '2026-01-01 12:00:00']);
    confere(CorridasFinalizadas::vazia(), 'tabela vazia antes');
    confere(CorridasFinalizadas::importarHistorico(2) === 6, 'importa tudo, em lotes de 2, sem limite de data');
    confere(array_values(array_unique(array_column(linhasDe('entregas_corridas_finalizadas'), 'origem'))) === ['historico'] && linhaDoPedido('order_h_cancelado')['finalizada_em'] === '2025-12-31 23:50:00' && linhaDoPedido('order_h_aberto') === [], 'origem historico; o cancelado depois de finalizado entra; o aberto não');
    confere(CorridasFinalizadas::importarHistorico(2) === 0 && count(linhasDe('entregas_corridas_finalizadas')) === 6 && !CorridasFinalizadas::vazia(), 'de novo: nada (idempotente)');
```

- [ ] **Passo 2: Rode e veja falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/corridas-do-sistema.php`
Expected: FAIL com `Class "App\Support\Entregas\Sistema\CorridasFinalizadas" not found`, exit 1.

- [ ] **Passo 3: Implemente**

`api/app/Support/Entregas/Sistema/CorridasFinalizadas.php`:

```php
<?php

namespace App\Support\Entregas\Sistema;

use App\Listeners\Entregas\ObservadorFinanceiroDosPedidos;
use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: registro permanente das corridas finalizadas de todas as organizações (centrais), a base do
 * relatório do dono do sistema (Fleet-Ops → Financeiro → Corridas do sistema). Uma linha por pedido
 * (entregas_corridas_finalizadas, order_uuid único): entra quando o pedido fica concluído e nunca muda nem sai
 * (cancelar, excluir o pedido ou o deploy/zerar-pedidos não tiram). Nomes da loja e do motoboy gravados na hora.
 *
 * Quem grava: o ObservadorDasCorridasDoSistema (Order::updated, origem `conclusao`, hora = agora), o
 * entregas:corridas-conferir a cada 5 min (últimos 2 dias, origem `conferencia`) e, com a tabela vazia ou --historico,
 * tudo o que está no banco (origem `historico`). Gravar é idempotente (insertOrIgnore pelo order_uuid): fica a primeira.
 *
 * Lê pelo DB::table (sem os escopos do Eloquent): o pedido excluído (deleted_at) também entra. Horários em Brasília,
 * texto 'Y-m-d H:i:s' (ver "Fuso" em api/CLAUDE.md). Spec: docs/superpowers/specs/2026-10-22-corridas-do-sistema-design.md.
 */
final class CorridasFinalizadas
{
    public const TABELA = 'entregas_corridas_finalizadas';

    /** Status de pedido finalizado: os mesmos do observador do financeiro. */
    public const CONCLUIDOS = ObservadorFinanceiroDosPedidos::CONCLUIDOS;

    public const CONCLUSAO   = 'conclusao';
    public const CONFERENCIA = 'conferencia';
    public const HISTORICO   = 'historico';

    /** Pedidos por consulta (whereIn e insertOrIgnore) e por lote do histórico. */
    public const LOTE = 500;

    public static function vazia(): bool
    {
        return !DB::table(static::TABELA)->exists();
    }

    /** Grava a corrida de um pedido (o observador passa a hora da conclusão). true se gravou agora. */
    public static function registrarPedido(object $pedido, string $origem, ?string $finalizadaEm = null): bool
    {
        return static::registrarLote([$pedido], $origem, $finalizadaEm) > 0;
    }

    /**
     * Grava as corridas dos pedidos (linhas de orders ou models) que ainda não têm linha. finalizada_em: $finalizadaEm;
     * sem ela, o primeiro COMPLETED do rastreio; sem ele, o updated_at do pedido. Devolve quantas linhas gravou.
     */
    public static function registrarLote(array $pedidos, string $origem, ?string $finalizadaEm = null): int
    {
        $porUuid = [];
        foreach ($pedidos as $pedido) {
            $uuid = (string) ($pedido->uuid ?? '');
            if ($uuid !== '' && (string) ($pedido->company_uuid ?? '') !== '') {
                $porUuid[$uuid] = $pedido;
            }
        }

        $gravadas = 0;
        foreach (array_chunk($porUuid, static::LOTE, true) as $lote) {
            $jaTem = DB::table(static::TABELA)->whereIn('order_uuid', array_keys($lote))->pluck('order_uuid')->all();
            $lote  = array_diff_key($lote, array_flip(array_map('strval', $jaTem)));
            if ($lote === []) {
                continue;
            }

            $conclusoes = $finalizadaEm === null ? static::conclusoes($lote) : [];
            $lojas      = static::nomesDasLojas($lote);
            $motoboys   = static::nomesDosMotoboys($lote);
            $agora      = now()->format('Y-m-d H:i:s');
            $linhas     = [];
            foreach ($lote as $uuid => $pedido) {
                $loja     = (string) ($pedido->customer_uuid ?? '');
                $motoboy  = (string) ($pedido->driver_assigned_uuid ?? '');
                $rastreio = (string) ($pedido->tracking_number_uuid ?? '');
                $linhas[] = [
                    'order_uuid'    => (string) $uuid,
                    'company_uuid'  => (string) $pedido->company_uuid,
                    'pedido'        => mb_substr((string) (($pedido->public_id ?? '') ?: $uuid), 0, 64),
                    'numero'        => static::curto($pedido->internal_id ?? null, 191),
                    'loja_uuid'     => isset($lojas[$loja]) ? $loja : null,
                    'loja_nome'     => isset($lojas[$loja]) ? static::curto($lojas[$loja], 191) : null,
                    'motoboy_uuid'  => $motoboy !== '' ? $motoboy : null,
                    'motoboy_nome'  => static::curto($motoboys[$motoboy] ?? null, 191),
                    'finalizada_em' => $finalizadaEm ?? $conclusoes[$rastreio] ?? static::texto($pedido->updated_at ?? null) ?? $agora,
                    'origem'        => $origem,
                    'created_at'    => $agora,
                ];
            }
            $gravadas += DB::table(static::TABELA)->insertOrIgnore($linhas);
        }

        return $gravadas;
    }

    /**
     * Grava as corridas que faltam desde o instante ('Y-m-d H:i:s' de Brasília): pedidos com status concluído
     * atualizados desde então e pedidos com COMPLETED no rastreio desde então (inclusive cancelados depois), de todas as
     * organizações, excluídos também. Origem `conferencia`. Devolve quantas gravou.
     */
    public static function conferir(string $desde): int
    {
        $pedidos = DB::table('orders')->whereIn('status', static::CONCLUIDOS)->where('updated_at', '>=', $desde)->get()->all();

        $rastreios = DB::table('tracking_statuses')->where('code', 'COMPLETED')->where('created_at', '>=', $desde)->pluck('tracking_number_uuid')->all();
        foreach (array_chunk(static::distintos($rastreios), static::LOTE) as $lote) {
            array_push($pedidos, ...DB::table('orders')->whereIn('tracking_number_uuid', $lote)->get()->all());
        }

        return static::registrarLote($pedidos, static::CONFERENCIA);
    }

    /**
     * Tudo o que está no banco, sem limite de data, em lotes pelo id: os pedidos com status concluído e os que têm
     * COMPLETED no rastreio (cancelados ou reabertos depois). Origem `historico`. Idempotente. Devolve quantas gravou.
     */
    public static function importarHistorico(int $lote = self::LOTE): int
    {
        $gravadas = 0;

        $ultimo = 0;
        do {
            $pedidos = DB::table('orders')->whereIn('status', static::CONCLUIDOS)->where('id', '>', $ultimo)->orderBy('id')->limit($lote)->get()->all();
            if ($pedidos !== []) {
                $ultimo = (int) $pedidos[count($pedidos) - 1]->id;
                $gravadas += static::registrarLote($pedidos, static::HISTORICO);
            }
        } while (count($pedidos) === $lote);

        $ultimo = 0;
        do {
            $status = DB::table('tracking_statuses')->where('code', 'COMPLETED')->where('id', '>', $ultimo)->orderBy('id')->limit($lote)->get()->all();
            if ($status !== []) {
                $ultimo    = (int) $status[count($status) - 1]->id;
                $rastreios = static::distintos(array_map(fn ($linha) => $linha->tracking_number_uuid ?? null, $status));
                if ($rastreios !== []) {
                    $gravadas += static::registrarLote(DB::table('orders')->whereIn('tracking_number_uuid', $rastreios)->get()->all(), static::HISTORICO);
                }
            }
        } while (count($status) === $lote);

        return $gravadas;
    }

    /** 'Y-m-d H:i:s' de Brasília → ISO com o fuso ('2026-10-22T09:31:00-03:00'). */
    public static function iso(string $instante): string
    {
        return (new \DateTimeImmutable($instante, new \DateTimeZone(date_default_timezone_get())))->format('Y-m-d\TH:i:sP');
    }

    /** Data do banco (texto em Brasília) ou DateTime (convertido para o fuso do app) → 'Y-m-d H:i:s'; vazio: null. */
    public static function texto($valor): ?string
    {
        if ($valor instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($valor)->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        }
        $texto = trim((string) ($valor ?? ''));

        return $texto === '' ? null : substr($texto, 0, 19);
    }

    /** Os valores não vazios, como texto, sem repetir. */
    public static function distintos(array $valores): array
    {
        $textos = array_map(fn ($valor) => $valor === null ? '' : (string) $valor, $valores);

        return array_values(array_unique(array_filter($textos, fn ($valor) => $valor !== '')));
    }

    /** [tracking_number_uuid => primeiro created_at do COMPLETED], inclusive status excluídos (pedido excluído inteiro). */
    protected static function conclusoes(array $pedidos): array
    {
        $rastreios = static::distintos(array_map(fn ($pedido) => $pedido->tracking_number_uuid ?? null, $pedidos));
        if ($rastreios === []) {
            return [];
        }

        $primeiras = [];
        foreach (DB::table('tracking_statuses')->where('code', 'COMPLETED')->whereIn('tracking_number_uuid', $rastreios)->get() as $status) {
            $rastreio = (string) $status->tracking_number_uuid;
            $quando   = static::texto($status->created_at ?? null);
            if ($quando !== null && (!isset($primeiras[$rastreio]) || $quando < $primeiras[$rastreio])) {
                $primeiras[$rastreio] = $quando;
            }
        }

        return $primeiras;
    }

    /** [vendors.uuid => nome] dos clientes dos pedidos que são lojas (inclusive excluídas). */
    protected static function nomesDasLojas(array $pedidos): array
    {
        $uuids = static::distintos(array_map(fn ($pedido) => $pedido->customer_uuid ?? null, $pedidos));

        return $uuids === [] ? [] : array_map('strval', DB::table('vendors')->whereIn('uuid', $uuids)->pluck('name', 'uuid')->all());
    }

    /** [drivers.uuid => nome do usuário] dos motoboys dos pedidos (o nome do Driver é o do User). */
    protected static function nomesDosMotoboys(array $pedidos): array
    {
        $uuids = static::distintos(array_map(fn ($pedido) => $pedido->driver_assigned_uuid ?? null, $pedidos));
        if ($uuids === []) {
            return [];
        }

        $usuarios = DB::table('drivers')->whereIn('uuid', $uuids)->pluck('user_uuid', 'uuid')->all();
        $doUser   = static::distintos(array_values($usuarios));
        $nomes    = $doUser === [] ? [] : DB::table('users')->whereIn('uuid', $doUser)->pluck('name', 'uuid')->all();

        $saida = [];
        foreach ($usuarios as $motoboy => $usuario) {
            if (isset($nomes[(string) $usuario])) {
                $saida[(string) $motoboy] = (string) $nomes[(string) $usuario];
            }
        }

        return $saida;
    }

    protected static function curto($valor, int $tamanho): ?string
    {
        $texto = trim((string) ($valor ?? ''));

        return $texto === '' ? null : mb_substr($texto, 0, $tamanho);
    }
}
```

- [ ] **Passo 4: Rode e veja passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/corridas-do-sistema.php`
Expected: tudo `PASSA`, termina em `FALHAS: 0`.

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/Sistema/CorridasFinalizadas.php`
Expected: exit 0.

- [ ] **Passo 5: Commit**

```bash
git rev-parse --show-toplevel   # esperado: C:/tmp/cs
git add api/app/Support/Entregas/Sistema/CorridasFinalizadas.php scripts/teste-php/corridas-do-sistema.php
git commit -m "$(cat <<'EOF'
Corridas do sistema: registro permanente das corridas finalizadas (gravar, conferir, histórico)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Tarefa 3: `PrecosDasCentrais` e `RelatorioDasCorridas`

**Arquivos:**
- Criar: `api/app/Support/Entregas/Sistema/PrecosDasCentrais.php`
- Criar: `api/app/Support/Entregas/Sistema/RelatorioDasCorridas.php`
- Modificar: `scripts/teste-php/corridas-do-sistema.php`

- [ ] **Passo 1: Escreva os testes**

Acrescente antes da linha `    resumo();`:

```php
    echo '== Preço por central (PrecosDasCentrais)' . PHP_EOL;
    foreach ([['2,50', 2.5], ['2.5', 2.5], ['0', 0.0], ['1000', 1000.0], ['12,34', 12.34]] as [$digitado, $esperado]) {
        confere(PrecosDasCentrais::valor($digitado) === $esperado, "valor \"{$digitado}\" = {$esperado}");
    }
    foreach (['-1', '1000,01', '1,234', 'abc', '', '1.000,00'] as $digitado) {
        confere(PrecosDasCentrais::valor($digitado) === null, "valor \"{$digitado}\": inválido");
    }
    confere(PrecosDasCentrais::valor(null) === null && PrecosDasCentrais::valor(2.5) === 2.5, 'null é inválido; número vale');
    confere(number_format(PrecosDasCentrais::valor('-0'), 2, '.', '') === '0.00', '"-0" vira 0,00 (sem o sinal)');
    confere(PrecosDasCentrais::data('2026-10-01') === '2026-10-01' && PrecosDasCentrais::data('2026-02-30') === null && PrecosDasCentrais::data('01/10/2026') === null && PrecosDasCentrais::data(null) === null, 'data: só Y-m-d que existe');

    $vigencia = [['vale' => '2026-10-01', 'valor' => 2.0], ['vale' => '2026-10-15', 'valor' => 2.5]];
    confere(PrecosDasCentrais::precoNoDia($vigencia, '2026-09-30') === null, 'antes do primeiro preço: sem preço');
    confere(PrecosDasCentrais::precoNoDia($vigencia, '2026-10-01') === 2.0 && PrecosDasCentrais::precoNoDia($vigencia, '2026-10-14') === 2.0, 'do primeiro dia até a véspera do seguinte: o primeiro');
    confere(PrecosDasCentrais::precoNoDia($vigencia, '2026-10-15') === 2.5 && PrecosDasCentrais::precoNoDia($vigencia, '2026-12-01') === 2.5, 'do dia da troca em diante: o novo');
    confere(PrecosDasCentrais::precoNoDia([], '2026-10-15') === null, 'central sem preço: null');

    preparar();
    cadastros();
    $r = PrecosDasCentrais::criar(['company' => 'central-a', 'valor' => '2,50', 'vale_a_partir_de' => '2026-10-01'], 'user-edgard', '2026-10-22 10:00:00');
    confere(is_array($r) && $r['company'] === 'central-a' && $r['valor'] === 2.5 && $r['vale_a_partir_de'] === '2026-10-01' && is_int($r['id']), 'cria: {company, id, valor, vale_a_partir_de}');
    $linha = linhasDe('entregas_precos_centrais')[0] ?? [];
    confere(($linha['valor'] ?? null) === '2.50' && $linha['criado_por'] === 'user-edgard' && $linha['created_at'] === '2026-10-22 10:00:00', 'grava o valor com 2 casas, quem criou e a hora de Brasília');
    $r = PrecosDasCentrais::criar(['company' => 'central-a', 'valor' => '3', 'vale_a_partir_de' => '2026-10-01'], null, '2026-10-22 10:00:00');
    confere($r === 'Esta central já tem um preço a partir de 01/10/2026. Apague o antigo para trocar.', 'mesma central e data: erro');
    foreach ([
        'central excluída'       => ['company' => 'central-x', 'valor' => '2', 'vale_a_partir_de' => '2026-10-01'],
        'central que não existe' => ['company' => 'nao-existe', 'valor' => '2', 'vale_a_partir_de' => '2026-10-01'],
        'sem central'            => ['valor' => '2', 'vale_a_partir_de' => '2026-10-01'],
        'valor acima de 1000'    => ['company' => 'central-b', 'valor' => '1000,01', 'vale_a_partir_de' => '2026-10-01'],
        'valor negativo'         => ['company' => 'central-b', 'valor' => '-2', 'vale_a_partir_de' => '2026-10-01'],
        'data inválida'          => ['company' => 'central-b', 'valor' => '2', 'vale_a_partir_de' => '2026-13-01'],
    ] as $caso => $dados) {
        $erro = PrecosDasCentrais::criar($dados, null, '2026-10-22 10:00:00');
        confere(is_string($erro) && $erro !== '', "{$caso}: erro (" . (is_string($erro) ? $erro : 'criou') . ')');
    }
    confere(count(linhasDe('entregas_precos_centrais')) === 1, 'nos erros, nada gravado');

    PrecosDasCentrais::criar(['company' => 'central-a', 'valor' => '3', 'vale_a_partir_de' => '2026-10-15'], null, '2026-10-22 10:00:00');
    preco('central-x', '1.00', '2026-08-01');
    $lista = PrecosDasCentrais::lista();
    confere(array_column($lista, 'nome') === ['Central Antiga', 'Central Norte', 'Central Sul'], 'lista: as organizações ativas e a excluída que tem preço, por nome');
    confere(array_column($lista[1]['precos'], 'vale_a_partir_de') === ['2026-10-15', '2026-10-01'] && $lista[1]['precos'][0]['valor'] === 3.0 && $lista[2]['precos'] === [], 'preços do mais recente para o mais antigo; central sem preço com a lista vazia');
    $id = $lista[1]['precos'][0]['id'];
    confere(PrecosDasCentrais::apagar($id) === true && PrecosDasCentrais::apagar($id) === false && PrecosDasCentrais::apagar(0) === false, 'apagar: true uma vez; de novo (e id 0): false');
    confere(PrecosDasCentrais::nomes(['central-a', '9f3b2c1d-0000-4000-8000-000000000000']) === ['central-a' => 'Central Norte', '9f3b2c1d-0000-4000-8000-000000000000' => '9f3b2c1d'], 'nome da central; a que não existe mais vira o uuid curto');
    confere(PrecosDasCentrais::porCentral(['central-a']) === ['central-a' => [['vale' => '2026-10-01', 'valor' => 2.5]]], 'porCentral: vigência em ordem de data');

    echo '== Relatório (RelatorioDasCorridas)' . PHP_EOL;
    $precos = ['central-a' => [['vale' => '2026-10-01', 'valor' => 2.0], ['vale' => '2026-10-15', 'valor' => 2.5]], 'central-b' => [['vale' => '2026-10-10', 'valor' => 3.0]]];
    $nomes  = ['central-a' => 'Central Norte', 'central-b' => 'Central Sul'];
    $linhaDoRelatorio = fn (string $pedido, string $company, string $quando) => (object) ['id' => 0, 'company_uuid' => $company, 'pedido' => $pedido, 'numero' => null, 'loja_nome' => null, 'motoboy_nome' => null, 'finalizada_em' => $quando, 'origem' => 'conclusao'];
    $doPeriodo = [
        $linhaDoRelatorio('a1', 'central-a', '2026-10-05 12:00:00'),
        $linhaDoRelatorio('b1', 'central-b', '2026-10-09 23:59:59'),
        $linhaDoRelatorio('b2', 'central-b', '2026-10-10 00:00:00'),
        $linhaDoRelatorio('a2', 'central-a', '2026-10-15 00:00:00'),
        $linhaDoRelatorio('a3', 'central-a', '2026-10-20 18:00:00'),
    ];
    $r = RelatorioDasCorridas::agregar($doPeriodo, $precos, $nomes);
    confere($r['total'] === ['corridas' => 5, 'a_cobrar' => 10.0, 'sem_preco' => 1], 'total: 5 corridas, R$ 10,00, 1 sem preço');
    confere(array_column($r['centrais'], 'nome') === ['Central Norte', 'Central Sul'], 'centrais por nome');
    [$norte, $sul] = $r['centrais'];
    confere($norte['company'] === 'central-a' && $norte['corridas'] === 3 && $norte['a_cobrar'] === 7.0 && $norte['sem_preco'] === 0 && $norte['precos'] === [2.0, 2.5], 'troca de preço no meio do período: dois preços (o console mostra "variado"); o dia da troca já vale o novo');
    confere($sul['corridas'] === 2 && $sul['a_cobrar'] === 3.0 && $sul['sem_preco'] === 1 && $sul['precos'] === [3.0], 'antes do primeiro preço: sem preço (R$ 0,00) e contada');
    $porPedido = array_column($r['corridas'], null, 'pedido');
    confere($porPedido['b1']['preco'] === null && $porPedido['a2']['preco'] === 2.5 && $porPedido['a1']['finalizada_em'] === '2026-10-05T12:00:00-03:00' && $porPedido['a1']['central'] === 'Central Norte' && $porPedido['a1']['company'] === 'central-a' && $porPedido['a1']['origem'] === 'conclusao', 'cada corrida: preço do dia, ISO com -03:00, central e origem');
    confere($r['lista_cortada'] === false && count($r['corridas']) === 5, 'lista inteira');
    $cortado = RelatorioDasCorridas::agregar($doPeriodo, $precos, $nomes, 2);
    confere(count($cortado['corridas']) === 2 && $cortado['lista_cortada'] === true && $cortado['total']['corridas'] === 5 && $cortado['total']['a_cobrar'] === 10.0, 'acima do limite: lista cortada, totais completos');

    preparar();
    cadastros();
    corrida('order_set_30', 'central-a', '2026-09-30 23:59:59');
    corrida('order_out_01', 'central-a', '2026-10-01 00:00:00');
    corrida('order_out_31', 'central-b', '2026-10-31 23:59:59');
    corrida('order_nov_01', 'central-a', '2026-11-01 00:00:00');
    corrida('order_sumida', '9f3b2c1d-0000-4000-8000-000000000000', '2026-10-10 10:00:00');
    preco('central-a', '2.00', '2026-10-01');
    $r = RelatorioDasCorridas::montar('2026-10-01', '2026-10-31');
    confere(is_array($r) && $r['inicio'] === '2026-10-01' && $r['fim'] === '2026-10-31' && array_column($r['corridas'], 'pedido') === ['order_out_01', 'order_sumida', 'order_out_31'], 'o período inteiro em Brasília (00:00:00 a 23:59:59), em ordem de finalização');
    confere(is_array($r) && in_array('9f3b2c1d', array_column($r['centrais'], 'nome'), true) && $r['total']['a_cobrar'] === 2.0, 'central que não existe mais: o uuid curto; só a Norte tem preço');
    foreach (['data inválida' => ['2026-10-01', '31/10/2026'], 'fim antes do início' => ['2026-10-31', '2026-10-01'], '367 dias' => ['2025-01-01', '2026-01-02'], 'sem datas' => [null, null]] as $caso => [$de, $ate]) {
        confere(is_string(RelatorioDasCorridas::montar($de, $ate)), "{$caso}: erro");
    }
    confere(is_array(RelatorioDasCorridas::montar('2024-01-01', '2024-12-31')), '366 dias (ano bissexto inteiro): pode');
```

- [ ] **Passo 2: Rode e veja falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/corridas-do-sistema.php`
Expected: FAIL com `Class "App\Support\Entregas\Sistema\PrecosDasCentrais" not found`.

- [ ] **Passo 3: Implemente `PrecosDasCentrais`**

`api/app/Support/Entregas/Sistema/PrecosDasCentrais.php`:

```php
<?php

namespace App\Support\Entregas\Sistema;

use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: preço fixo por corrida que o dono do sistema cobra de cada central (organização). Tabela
 * entregas_precos_centrais, uma linha por central e data "vale a partir de" (dia em Brasília). O preço de uma corrida é o
 * da linha da central com a maior data ≤ dia da finalização; antes do primeiro, a corrida fica sem preço. Calculado na
 * hora do relatório: um preço novo não muda o que veio antes da data dele, e um preço com data antiga precifica o
 * histórico. Não se edita: cadastra-se outro com outra data (apagar existe para corrigir digitação).
 */
final class PrecosDasCentrais
{
    public const TABELA = 'entregas_precos_centrais';

    public const MAXIMO = 1000.0;

    /**
     * Todas as organizações não excluídas, mais as excluídas que têm preço (para poder apagá-lo), por nome:
     * [{company, nome, precos: [{id, valor, vale_a_partir_de}]}], os preços do mais recente para o mais antigo.
     */
    public static function lista(): array
    {
        $ativas = DB::table('companies')->whereNull('deleted_at')->pluck('uuid')->all();
        $precos = DB::table(static::TABELA)->get()->all();
        $uuids  = CorridasFinalizadas::distintos(array_merge($ativas, array_map(fn ($preco) => $preco->company_uuid, $precos)));
        $nomes  = static::nomes($uuids);

        $porCentral = [];
        foreach ($precos as $preco) {
            $porCentral[(string) $preco->company_uuid][] = static::formatar($preco);
        }

        $centrais = [];
        foreach ($uuids as $uuid) {
            $lista = $porCentral[$uuid] ?? [];
            usort($lista, fn ($a, $b) => strcmp($b['vale_a_partir_de'], $a['vale_a_partir_de']));
            $centrais[] = ['company' => $uuid, 'nome' => $nomes[$uuid], 'precos' => $lista];
        }
        usort($centrais, fn ($a, $b) => strcasecmp($a['nome'], $b['nome']));

        return $centrais;
    }

    /** {id, valor, vale_a_partir_de}. */
    public static function formatar(object $preco): array
    {
        return [
            'id'               => (int) $preco->id,
            'valor'            => round((float) $preco->valor, 2),
            'vale_a_partir_de' => substr((string) $preco->vale_a_partir_de, 0, 10),
        ];
    }

    /** Cadastra {company, valor, vale_a_partir_de}. Devolve {company, id, valor, vale_a_partir_de} ou a mensagem de erro. */
    public static function criar(array $dados, ?string $usuario, string $agora): array|string
    {
        $company = trim((string) ($dados['company'] ?? ''));
        if ($company === '' || !DB::table('companies')->where('uuid', $company)->whereNull('deleted_at')->exists()) {
            return 'Organização não encontrada.';
        }

        $valor = static::valor($dados['valor'] ?? null);
        if ($valor === null) {
            return 'O preço por corrida vai de R$ 0,00 a R$ 1.000,00, com até duas casas.';
        }

        $data = static::data($dados['vale_a_partir_de'] ?? null);
        if ($data === null) {
            return 'Informe a data a partir da qual o preço vale.';
        }

        if (DB::table(static::TABELA)->where('company_uuid', $company)->where('vale_a_partir_de', $data)->exists()) {
            return sprintf('Esta central já tem um preço a partir de %s. Apague o antigo para trocar.', implode('/', array_reverse(explode('-', $data))));
        }

        $id = DB::table(static::TABELA)->insertGetId([
            'company_uuid'     => $company,
            'valor'            => number_format($valor, 2, '.', ''),
            'vale_a_partir_de' => $data,
            'criado_por'       => $usuario,
            'created_at'       => $agora,
        ]);

        return ['company' => $company] + static::formatar((object) ['id' => $id, 'valor' => $valor, 'vale_a_partir_de' => $data]);
    }

    /** Apaga o preço pelo id. true se apagou. */
    public static function apagar(int $id): bool
    {
        return $id > 0 && DB::table(static::TABELA)->where('id', $id)->delete() > 0;
    }

    /** Valor de 0,00 a 1000,00 com até duas casas ("2,50", "2.5", 2.5), ou null. O console manda "1000.00". */
    public static function valor($valor): ?float
    {
        if (is_string($valor)) {
            $valor = str_replace(',', '.', trim($valor));
        }
        if (!is_numeric($valor)) {
            return null;
        }

        $numero = (float) $valor;
        if (!is_finite($numero) || abs($numero * 100 - round($numero * 100)) > 0.0001) {
            return null;
        }
        // + 0.0: "-0" vira 0.0 (sem isso, o number_format gravaria "-0.00")
        $numero = round($numero, 2) + 0.0;

        return $numero >= 0 && $numero <= static::MAXIMO ? $numero : null;
    }

    /** 'Y-m-d' de um dia que existe, ou null. */
    public static function data($valor): ?string
    {
        if (!is_string($valor) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($valor))) {
            return null;
        }

        $texto = trim($valor);
        $data  = \DateTime::createFromFormat('!Y-m-d', $texto);

        return $data && $data->format('Y-m-d') === $texto ? $texto : null;
    }

    /** [company_uuid => [['vale' => 'Y-m-d', 'valor' => float], ...]] das centrais pedidas, em ordem de data. */
    public static function porCentral(array $companies): array
    {
        $companies = CorridasFinalizadas::distintos($companies);
        if ($companies === []) {
            return [];
        }

        $saida = [];
        foreach (DB::table(static::TABELA)->whereIn('company_uuid', $companies)->orderBy('vale_a_partir_de')->get() as $preco) {
            $saida[(string) $preco->company_uuid][] = ['vale' => substr((string) $preco->vale_a_partir_de, 0, 10), 'valor' => round((float) $preco->valor, 2)];
        }

        return $saida;
    }

    /** O preço que vale no dia ('Y-m-d'): o de maior "vale a partir de" ≤ dia, ou null. $precos em ordem de data. */
    public static function precoNoDia(array $precos, string $dia): ?float
    {
        $preco = null;
        foreach ($precos as $item) {
            if ($item['vale'] > $dia) {
                break;
            }
            $preco = (float) $item['valor'];
        }

        return $preco;
    }

    /** [company_uuid => nome] das organizações, inclusive excluídas; a que não existe mais (ou sem nome) vira o uuid curto. */
    public static function nomes(array $uuids): array
    {
        $uuids   = CorridasFinalizadas::distintos($uuids);
        $achados = $uuids === [] ? [] : DB::table('companies')->whereIn('uuid', $uuids)->pluck('name', 'uuid')->all();

        $nomes = [];
        foreach ($uuids as $uuid) {
            $nome         = trim((string) ($achados[$uuid] ?? ''));
            $nomes[$uuid] = $nome !== '' ? $nome : substr($uuid, 0, 8);
        }

        return $nomes;
    }
}
```

- [ ] **Passo 4: Implemente `RelatorioDasCorridas`**

`api/app/Support/Entregas/Sistema/RelatorioDasCorridas.php`:

```php
<?php

namespace App\Support\Entregas\Sistema;

use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: relatório "Corridas do sistema" de um período (dias em Brasília, até 366): as corridas
 * finalizadas de todas as organizações, por central, com o preço de cada uma pela vigência (PrecosDasCentrais) e os
 * totais. A lista vem cortada em LIMITE_DA_LISTA linhas (lista_cortada: true); os totais contam todas.
 */
final class RelatorioDasCorridas
{
    public const MAX_DIAS = 366;

    public const LIMITE_DA_LISTA = 20000;

    /** {inicio, fim, total, centrais, corridas, lista_cortada} ou a mensagem de erro do período. */
    public static function montar($inicio, $fim): array|string
    {
        $de  = PrecosDasCentrais::data($inicio);
        $ate = PrecosDasCentrais::data($fim);
        if ($de === null || $ate === null) {
            return 'Informe o início e o fim do período.';
        }
        if ($ate < $de) {
            return 'O fim do período precisa ser igual ou depois do início.';
        }
        if ((new \DateTimeImmutable($de))->diff(new \DateTimeImmutable($ate))->days + 1 > static::MAX_DIAS) {
            return 'O período pode ter até 366 dias.';
        }

        $corridas = DB::table(CorridasFinalizadas::TABELA)
            ->where('finalizada_em', '>=', $de . ' 00:00:00')
            ->where('finalizada_em', '<=', $ate . ' 23:59:59')
            ->orderBy('finalizada_em')
            ->orderBy('id')
            ->get()
            ->all();
        $centrais = CorridasFinalizadas::distintos(array_map(fn ($corrida) => $corrida->company_uuid, $corridas));

        return ['inicio' => $de, 'fim' => $ate] + static::agregar($corridas, PrecosDasCentrais::porCentral($centrais), PrecosDasCentrais::nomes($centrais));
    }

    /**
     * Pura: soma as corridas (linhas da tabela, em ordem) com os preços (PrecosDasCentrais::porCentral) e os nomes das
     * centrais. {total: {corridas, a_cobrar, sem_preco}, centrais: [{company, nome, corridas, a_cobrar, sem_preco,
     * precos (valores distintos usados, em ordem)}], corridas: [{finalizada_em (ISO), company, central, pedido, numero,
     * loja_nome, motoboy_nome, preco|null, origem}], lista_cortada}. Contas em centavos.
     */
    public static function agregar(array $corridas, array $precos, array $nomes, int $limite = self::LIMITE_DA_LISTA): array
    {
        $total      = ['corridas' => 0, 'a_cobrar' => 0, 'sem_preco' => 0];
        $porCentral = [];
        $lista      = [];

        foreach ($corridas as $corrida) {
            $company  = (string) $corrida->company_uuid;
            $instante = substr((string) $corrida->finalizada_em, 0, 19);
            $preco    = PrecosDasCentrais::precoNoDia($precos[$company] ?? [], substr($instante, 0, 10));
            $centavos = $preco === null ? 0 : (int) round($preco * 100);

            if (!isset($porCentral[$company])) {
                $porCentral[$company] = ['company' => $company, 'nome' => $nomes[$company] ?? substr($company, 0, 8), 'corridas' => 0, 'a_cobrar' => 0, 'sem_preco' => 0, 'precos' => []];
            }
            $porCentral[$company]['corridas']++;
            $porCentral[$company]['a_cobrar'] += $centavos;
            $total['corridas']++;
            $total['a_cobrar'] += $centavos;
            if ($preco === null) {
                $porCentral[$company]['sem_preco']++;
                $total['sem_preco']++;
            } else {
                $porCentral[$company]['precos'][$centavos] = $preco;
            }

            if (count($lista) < $limite) {
                $lista[] = [
                    'finalizada_em' => CorridasFinalizadas::iso($instante),
                    'company'       => $company,
                    'central'       => $porCentral[$company]['nome'],
                    'pedido'        => (string) $corrida->pedido,
                    'numero'        => isset($corrida->numero) ? (string) $corrida->numero : null,
                    'loja_nome'     => isset($corrida->loja_nome) ? (string) $corrida->loja_nome : null,
                    'motoboy_nome'  => isset($corrida->motoboy_nome) ? (string) $corrida->motoboy_nome : null,
                    'preco'         => $preco,
                    'origem'        => (string) $corrida->origem,
                ];
            }
        }

        $centrais = array_map(function (array $central) {
            ksort($central['precos'], SORT_NUMERIC);
            $central['precos']   = array_values($central['precos']);
            $central['a_cobrar'] = round($central['a_cobrar'] / 100, 2);

            return $central;
        }, array_values($porCentral));
        usort($centrais, fn ($a, $b) => strcasecmp($a['nome'], $b['nome']));
        $total['a_cobrar'] = round($total['a_cobrar'] / 100, 2);

        return ['total' => $total, 'centrais' => $centrais, 'corridas' => $lista, 'lista_cortada' => $total['corridas'] > count($lista)];
    }
}
```

- [ ] **Passo 5: Rode e veja passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/corridas-do-sistema.php`
Expected: tudo `PASSA`, termina em `FALHAS: 0`.

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/Sistema/PrecosDasCentrais.php api/app/Support/Entregas/Sistema/RelatorioDasCorridas.php`
Expected: exit 0.

- [ ] **Passo 6: Commit**

```bash
git rev-parse --show-toplevel   # esperado: C:/tmp/cs
git add api/app/Support/Entregas/Sistema/PrecosDasCentrais.php api/app/Support/Entregas/Sistema/RelatorioDasCorridas.php scripts/teste-php/corridas-do-sistema.php
git commit -m "$(cat <<'EOF'
Corridas do sistema: preço por central com vigência e relatório do período

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Tarefa 4: Observador da conclusão e registro no `AppServiceProvider`

**Arquivos:**
- Criar: `api/app/Listeners/Entregas/ObservadorDasCorridasDoSistema.php`
- Modificar: `api/app/Providers/AppServiceProvider.php` (`use`, `boot()`, método novo)
- Modificar: `scripts/teste-php/corridas-do-sistema.php`

- [ ] **Passo 1: Escreva os testes**

Acrescente antes da linha `    resumo();`:

```php
    echo '== Observador (Order::updated)' . PHP_EOL;
    preparar();
    cadastros();
    $concluido = function (string $publicId, string $status, array $alterados = ['status']) {
        $pedido            = new Order(['uuid' => 'uuid-' . $publicId, 'public_id' => $publicId, 'company_uuid' => 'central-a', 'status' => $status, 'internal_id' => '77', 'customer_uuid' => 'loja-1', 'driver_assigned_uuid' => 'motoboy-1', 'tracking_number_uuid' => null]);
        $pedido->alterados = $alterados;

        return $pedido;
    };
    Relogio::$agora = '2026-10-22 10:07:00';
    ObservadorDasCorridasDoSistema::aoAtualizar($concluido('order_obs', 'completed'));
    $l = linhaDoPedido('order_obs');
    confere(($l['origem'] ?? null) === 'conclusao' && $l['finalizada_em'] === '2026-10-22 10:07:00' && $l['motoboy_nome'] === 'Carlos Silva' && $l['loja_nome'] === 'Pizzaria Boa', 'concluído: grava com a hora de agora (Brasília) e origem conclusao');
    ObservadorDasCorridasDoSistema::aoAtualizar($concluido('order_obs_done', 'DONE'));
    confere(linhaDoPedido('order_obs_done') !== [], 'done (com maiúsculas, da API v1): grava');
    ObservadorDasCorridasDoSistema::aoAtualizar($concluido('order_obs_andamento', 'enroute'));
    ObservadorDasCorridasDoSistema::aoAtualizar($concluido('order_obs_cancelado', 'canceled'));
    ObservadorDasCorridasDoSistema::aoAtualizar($concluido('order_obs_outro_campo', 'completed', ['meta']));
    confere(linhaDoPedido('order_obs_andamento') === [] && linhaDoPedido('order_obs_cancelado') === [] && linhaDoPedido('order_obs_outro_campo') === [], 'outro status, cancelado ou status que não mudou: nada');
    ObservadorDasCorridasDoSistema::aoAtualizar($concluido('order_obs', 'completed'));
    confere(count(array_filter(linhasDe('entregas_corridas_finalizadas'), fn ($linha) => $linha['pedido'] === 'order_obs')) === 1, 'concluído de novo (reaberto): continua uma linha só');
    Banco::$falhar['entregas_corridas_finalizadas'] = 'banco fora do ar';
    confere(excecao(fn () => ObservadorDasCorridasDoSistema::aoAtualizar($concluido('order_obs_falha', 'completed'))) === null && logou('[entregas] sistema: falha ao registrar a corrida finalizada', 'warning'), 'banco fora do ar: não lança (a conclusão segue), só avisa no log');
    confere(logsSem(['Carlos Silva', 'Pizzaria Boa']), 'o log não leva nomes');
    unset(Banco::$falhar['entregas_corridas_finalizadas']);

    $provedor = file_get_contents('/repo/api/app/Providers/AppServiceProvider.php');
    $boot     = substr($provedor, (int) strpos($provedor, 'public function boot()'));
    confere(str_contains($provedor, 'use App\Listeners\Entregas\ObservadorDasCorridasDoSistema;') && str_contains($provedor, 'Order::updated(fn ($pedido) => ObservadorDasCorridasDoSistema::aoAtualizar($pedido));'), 'AppServiceProvider: Order::updated → observador das corridas do sistema');
    confere(str_contains(substr($boot, 0, (int) strpos($boot, '}')), '$this->registrarCorridasDoSistema();'), 'ligado no boot()');
```

- [ ] **Passo 2: Rode e veja falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/corridas-do-sistema.php`
Expected: FAIL com `Class "App\Listeners\Entregas\ObservadorDasCorridasDoSistema" not found`.

- [ ] **Passo 3: Crie o observador**

`api/app/Listeners/Entregas/ObservadorDasCorridasDoSistema.php`:

```php
<?php

namespace App\Listeners\Entregas;

use App\Support\Entregas\Sistema\CorridasFinalizadas;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: Order::updated → quando o status do pedido muda para concluído (completed/done), grava a
 * corrida no registro permanente do dono do sistema (CorridasFinalizadas, origem `conclusao`, hora = agora em
 * Brasília). Registrado no AppServiceProvider::registrarCorridasDoSistema, sem depender de chave nenhuma (nem da
 * ENTREGAS_FINANCEIRO). O que escapar (saveQuietly, query builder, banco fora do ar) o entregas:corridas-conferir grava
 * em até 5 min.
 *
 * Nunca lança: roda dentro da gravação do pedido (aviso no log só com o pedido e a classe do erro).
 */
class ObservadorDasCorridasDoSistema
{
    public static function aoAtualizar(object $pedido): void
    {
        try {
            if (!$pedido->wasChanged('status')) {
                return;
            }
            // o status pode vir com maiúsculas (API v1)
            if (!in_array(strtolower((string) $pedido->status), CorridasFinalizadas::CONCLUIDOS, true)) {
                return;
            }
            CorridasFinalizadas::registrarPedido($pedido, CorridasFinalizadas::CONCLUSAO, now()->format('Y-m-d H:i:s'));
        } catch (\Throwable $e) {
            Log::warning('[entregas] sistema: falha ao registrar a corrida finalizada', ['pedido' => $pedido->public_id ?? null, 'erro' => get_class($e)]);
        }
    }
}
```

- [ ] **Passo 4: Registre no `AppServiceProvider`**

Em `api/app/Providers/AppServiceProvider.php`:

1. Troque
```php
use App\Listeners\Entregas\ObservadorDaDistribuicao;
use App\Listeners\Entregas\ObservadorDoValorDaEntrega;
```
por
```php
use App\Listeners\Entregas\ObservadorDaDistribuicao;
use App\Listeners\Entregas\ObservadorDasCorridasDoSistema;
use App\Listeners\Entregas\ObservadorDoValorDaEntrega;
```

2. No `boot()`, troque
```php
        $this->lancarCorridasNoFinanceiro();
        $this->gravarPrazoDosPedidos();
```
por
```php
        $this->lancarCorridasNoFinanceiro();
        $this->registrarCorridasDoSistema();
        $this->gravarPrazoDosPedidos();
```

3. Logo depois do método `lancarCorridasNoFinanceiro()` (o que termina com `Order::updated(fn ($pedido) => ObservadorFinanceiroDosPedidos::aoAtualizar($pedido));` e `}`), acrescente:
```php

    /**
     * Entregas: pedido concluído → corrida no registro permanente do dono do sistema (relatório "Corridas do sistema",
     * todas as organizações; ver ObservadorDasCorridasDoSistema e CorridasFinalizadas). Sem chave: vale sempre. O resto,
     * o entregas:corridas-conferir grava em até 5 min.
     */
    protected function registrarCorridasDoSistema(): void
    {
        Order::updated(fn ($pedido) => ObservadorDasCorridasDoSistema::aoAtualizar($pedido));
    }
```

- [ ] **Passo 5: Rode e veja passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/corridas-do-sistema.php`
Expected: tudo `PASSA`, termina em `FALHAS: 0`.

Run (o provider é conferido por outros testes): `for t in entrega-junto financeiro-lancador imports-dos-providers; do PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php | tail -1; done`
Expected: três linhas `FALHAS: 0`.

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Listeners/Entregas/ObservadorDasCorridasDoSistema.php api/app/Providers/AppServiceProvider.php`
Expected: exit 0.

- [ ] **Passo 6: Commit**

```bash
git rev-parse --show-toplevel   # esperado: C:/tmp/cs
git add api/app/Listeners/Entregas/ObservadorDasCorridasDoSistema.php api/app/Providers/AppServiceProvider.php scripts/teste-php/corridas-do-sistema.php
git commit -m "$(cat <<'EOF'
Corridas do sistema: observador grava a corrida na conclusão do pedido

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Tarefa 5: Comando `entregas:corridas-conferir` e agenda

**Arquivos:**
- Criar: `api/app/Console/Commands/Entregas/CorridasConferir.php`
- Modificar: `api/app/Console/Kernel.php`
- Modificar: `scripts/teste-php/ifood-agendador.php` (a lista exata de comandos do iFood)
- Modificar: `scripts/teste-php/corridas-do-sistema.php`

- [ ] **Passo 1: Escreva os testes**

Acrescente antes da linha `    resumo();` em `scripts/teste-php/corridas-do-sistema.php`:

```php
    echo '== Comando entregas:corridas-conferir' . PHP_EOL;
    preparar();
    cadastros();
    pedido(['public_id' => 'order_velho', 'updated_at' => '2026-09-01 12:00:00']);
    pedido(['public_id' => 'order_ontem', 'updated_at' => '2026-10-21 18:00:00']);
    $comando = new CorridasConferir();
    confere($comando->handle() === 0, 'sai com SUCCESS');
    confere(pedidosRegistrados() === ['order_ontem', 'order_velho'] && array_values(array_unique(array_column(linhasDe('entregas_corridas_finalizadas'), 'origem'))) === ['historico'], 'tabela vazia (primeira rodada depois do deploy): importa o histórico inteiro');
    confere(logou('[entregas] sistema: histórico das corridas importado', 'info'), 'avisa no log quantas o histórico trouxe');
    pedido(['public_id' => 'order_agora', 'updated_at' => '2026-10-22 09:55:00']);
    pedido(['public_id' => 'order_esquecido', 'updated_at' => '2026-09-15 12:00:00']);
    $comando->handle();
    confere((linhaDoPedido('order_agora')['origem'] ?? null) === 'conferencia' && linhaDoPedido('order_esquecido') === [], 'com a tabela cheia: só os últimos 2 dias');
    $comando->opcoes = ['historico' => true];
    $comando->handle();
    confere((linhaDoPedido('order_esquecido')['origem'] ?? null) === 'historico' && count(linhasDe('entregas_corridas_finalizadas')) === 4, '--historico: importa tudo de novo, sem repetir');
    $comando->opcoes = [];
    Banco::$falharAoConsultar['orders'] = new \RuntimeException('banco fora do ar');
    confere($comando->handle() === 1 && logou('[entregas] sistema: conferência das corridas falhou', 'warning'), 'banco fora do ar: avisa e sai com FAILURE (a próxima rodada tenta de novo)');
    unset(Banco::$falharAoConsultar['orders']);

    echo '== Agenda (Kernel)' . PHP_EOL;
    $schedule = new Schedule();
    $metodo   = new ReflectionMethod(Kernel::class, 'schedule');
    $metodo->setAccessible(true);
    $metodo->invoke((new ReflectionClass(Kernel::class))->newInstanceWithoutConstructor(), $schedule);
    $evento = null;
    foreach ($schedule->eventos as $agendado) {
        if ($agendado->comando === 'entregas:corridas-conferir') {
            $evento = $agendado->chamadas;
        }
    }
    confere($evento !== null && isset($evento['everyFiveMinutes']) && ($evento['withoutOverlapping'][0] ?? null) === 30 && isset($evento['runInBackground']) && ($evento['appendOutputTo'] ?? null) === ['/proc/1/fd/1'], 'a cada 5 min, withoutOverlapping(30), em segundo plano, saída no container');
    confere($evento !== null && !isset($evento['when']), 'sem when(): não depende da ENTREGAS_FINANCEIRO');
    confere(str_contains(file_get_contents('/repo/api/app/Console/Commands/Entregas/CorridasConferir.php'), "'entregas:corridas-conferir {--historico"), 'assinatura com --historico');
```

Em `scripts/teste-php/ifood-agendador.php`, troque
```php
$rascunhos = $porComando['entregas:financeiro-rascunhos'] ?? null;
unset($porComando['entregas:financeiro-rascunhos']);
```
por
```php
$rascunhos = $porComando['entregas:financeiro-rascunhos'] ?? null;
unset($porComando['entregas:financeiro-rascunhos']);
// as corridas do sistema não dependem de chave (sem when): conferidas no corridas-do-sistema.php
$corridasDoSistema = $porComando['entregas:corridas-conferir'] ?? null;
unset($porComando['entregas:corridas-conferir']);
confere($corridasDoSistema !== null, 'entregas:corridas-conferir agendado (detalhes no corridas-do-sistema.php)');
```

- [ ] **Passo 2: Rode e veja falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/corridas-do-sistema.php`
Expected: FAIL com `Class "App\Console\Commands\Entregas\CorridasConferir" not found`.

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-agendador.php | grep corridas-conferir`
Expected: `FALHA entregas:corridas-conferir agendado (detalhes no corridas-do-sistema.php)`.

- [ ] **Passo 3: Crie o comando**

`api/app/Console/Commands/Entregas/CorridasConferir.php`:

```php
<?php

namespace App\Console\Commands\Entregas;

use App\Support\Entregas\Sistema\CorridasFinalizadas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: conferência das corridas do sistema (relatório do dono, todas as organizações), a cada 5 min
 * (Kernel), sem depender de chave nenhuma. Grava as corridas finalizadas dos últimos 2 dias que o observador não gravou
 * (saveQuietly, query builder, banco fora do ar no momento da conclusão). Com a tabela vazia (a primeira rodada depois
 * do deploy) ou com --historico, importa antes tudo o que já foi concluído e ainda está no banco, inclusive pedido
 * cancelado ou excluído depois. Idempotente: pode repetir. Log só com contagens.
 */
class CorridasConferir extends Command
{
    public const DIAS = 2;

    protected $signature   = 'entregas:corridas-conferir {--historico : importa tudo o que já foi concluído, sem limite de data}';
    protected $description = 'Entregas: grava no relatório do dono as corridas finalizadas que faltaram (e o histórico, na primeira vez)';

    public function handle(): int
    {
        try {
            if ($this->option('historico') || CorridasFinalizadas::vazia()) {
                $n = CorridasFinalizadas::importarHistorico();
                if ($n > 0) {
                    Log::info('[entregas] sistema: histórico das corridas importado', ['corridas' => $n]);
                }
            }

            $n = CorridasFinalizadas::conferir(now()->subDays(static::DIAS)->format('Y-m-d H:i:s'));
            if ($n > 0) {
                Log::info('[entregas] sistema: conferência gravou corridas', ['corridas' => $n]);
            }
        } catch (\Throwable $e) {
            Log::warning('[entregas] sistema: conferência das corridas falhou', ['erro' => get_class($e)]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Passo 4: Agende no `Kernel`**

Em `api/app/Console/Kernel.php`, logo depois da linha do `entregas:financeiro-rascunhos` (`$schedule->command('entregas:financeiro-rascunhos')->dailyAt('00:30')...;`), acrescente:

```php

        // corridas do sistema (relatório do dono, todas as organizações): grava as que o observador não gravou e, na
        // primeira vez (tabela vazia), o histórico (CorridasConferir). Sem when(): não depende da chave do financeiro.
        // withoutOverlapping(30): a importação do histórico pode passar de 10 min
        $schedule->command('entregas:corridas-conferir')->everyFiveMinutes()->withoutOverlapping(30)->runInBackground()->appendOutputTo(static::SAIDA_DO_CONTAINER);
```

- [ ] **Passo 5: Rode e veja passar**

Run: `for t in corridas-do-sistema ifood-agendador financeiro-lancador; do PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php | tail -1; done`
Expected: três linhas `FALHAS: 0`.

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Console/Commands/Entregas/CorridasConferir.php api/app/Console/Kernel.php`
Expected: exit 0.

- [ ] **Passo 6: Commit**

```bash
git rev-parse --show-toplevel   # esperado: C:/tmp/cs
git add api/app/Console/Commands/Entregas/CorridasConferir.php api/app/Console/Kernel.php scripts/teste-php/corridas-do-sistema.php scripts/teste-php/ifood-agendador.php
git commit -m "$(cat <<'EOF'
Corridas do sistema: entregas:corridas-conferir a cada 5 min (histórico na primeira vez)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Tarefa 6: `CorridasDoSistemaController`, rotas e trava do administrador do sistema

**Arquivos:**
- Criar: `api/app/Http/Controllers/Entregas/CorridasDoSistemaController.php`
- Modificar: `api/app/Providers/RouteServiceProvider.php`
- Modificar: `scripts/teste-php/imports-dos-providers.php`
- Modificar: `scripts/teste-php/corridas-do-sistema.php`

- [ ] **Passo 1: Escreva os testes**

Acrescente antes da linha `    resumo();` em `scripts/teste-php/corridas-do-sistema.php`:

```php
    echo '== Controller (CorridasDoSistemaController)' . PHP_EOL;
    preparar();
    cadastros();
    corrida('order_a1', 'central-a', '2026-10-05 09:31:00');
    preco('central-a', '2.50', '2026-10-01');
    foreach (['usuário comum (Administrator de organização)' => usuario('user'), 'sem sessão' => null] as $quem => $daSessao) {
        Auth::$usuario = $daSessao;
        foreach ([
            'corridas'    => fn () => api()->corridas(req(['inicio' => '2026-10-01', 'fim' => '2026-10-31'])),
            'precos'      => fn () => api()->precos(req()),
            'criarPreco'  => fn () => api()->criarPreco(req(['company' => 'central-b', 'valor' => '3', 'vale_a_partir_de' => '2026-10-01'])),
            'apagarPreco' => fn () => api()->apagarPreco(req(), '1'),
        ] as $metodo => $chamar) {
            $r = $chamar();
            confere($r->status === 403 && erroDe($r) === 'Somente o administrador do sistema pode ver esta tela.', "{$metodo}: {$quem} → 403");
        }
    }
    confere(count(linhasDe('entregas_precos_centrais')) === 1, '403: nada gravado nem apagado');

    Auth::$usuario = usuario('admin');
    $r = api()->corridas(req(['inicio' => '2026-10-01', 'fim' => '2026-10-31']));
    confere($r->status === 200 && $r->dados['total'] === ['corridas' => 1, 'a_cobrar' => 2.5, 'sem_preco' => 0] && $r->dados['centrais'][0]['nome'] === 'Central Norte' && $r->dados['corridas'][0]['finalizada_em'] === '2026-10-05T09:31:00-03:00', 'GET corridas: o relatório do período');
    $r = api()->corridas(req(['inicio' => '2026-10-31', 'fim' => '2026-10-01']));
    confere($r->status === 422 && erroDe($r) !== '', 'GET corridas com o fim antes do início: 422');
    $r = api()->precos(req());
    confere($r->status === 200 && array_column($r->dados['centrais'], 'nome') === ['Central Norte', 'Central Sul'], 'GET precos: as organizações ativas, mesmo sem corrida');
    $r = api()->criarPreco(req(['company' => 'central-b', 'valor' => '3,00', 'vale_a_partir_de' => '2026-10-01']));
    $novo = linhasDe('entregas_precos_centrais')[1] ?? [];
    confere($r->status === 200 && $r->dados['preco']['valor'] === 3.0 && ($novo['criado_por'] ?? null) === 'user-edgard' && $novo['created_at'] === '2026-10-22 10:00:00', 'POST precos: grava com quem criou e a hora de Brasília');
    $repetido = api()->criarPreco(req(['company' => 'central-b', 'valor' => '4', 'vale_a_partir_de' => '2026-10-01']));
    confere($repetido->status === 422 && str_contains(erroDe($repetido), '01/10/2026'), 'POST com data repetida para a central: 422');
    confere(api()->criarPreco(req(['company' => 'central-b', 'valor' => '1000,01', 'vale_a_partir_de' => '2026-10-02']))->status === 422, 'POST com valor fora da faixa: 422');
    $id = (string) $r->dados['preco']['id'];
    confere(api()->apagarPreco(req(), $id)->dados === ['apagado' => true] && api()->apagarPreco(req(), $id)->status === 404 && api()->apagarPreco(req(), 'abc')->status === 404, 'DELETE: apaga; de novo ou id inválido: 404');

    echo '== Rotas' . PHP_EOL;
    $rotas = file_get_contents('/repo/api/app/Providers/RouteServiceProvider.php');
    confere(str_contains($rotas, 'use App\\Http\\Controllers\\Entregas\\CorridasDoSistemaController;'), 'RouteServiceProvider importa o controller');
    $grupo = substr($rotas, (int) strpos($rotas, "Route::prefix('int/v1/entregas')"), max(0, (int) strpos($rotas, "Route::prefix('v1/entregas/motoboy')") - (int) strpos($rotas, "Route::prefix('int/v1/entregas')")));
    foreach ([['get', 'sistema/corridas', 'corridas'], ['get', 'sistema/precos', 'precos'], ['post', 'sistema/precos', 'criarPreco'], ['delete', 'sistema/precos/{id}', 'apagarPreco']] as [$verbo, $caminho, $metodo]) {
        confere(str_contains($grupo, "Route::{$verbo}('{$caminho}', [CorridasDoSistemaController::class, '{$metodo}'])") && method_exists(CorridasDoSistemaController::class, $metodo), strtoupper($verbo) . " {$caminho} → {$metodo} (grupo int/v1/entregas, fleetbase.protected)");
    }
```

Em `scripts/teste-php/imports-dos-providers.php`:

1. Troque
```php
    glob($app . '/Support/Entregas/Financeiro/*.php'),
```
por
```php
    glob($app . '/Support/Entregas/Financeiro/*.php'),
    glob($app . '/Support/Entregas/Sistema/*.php'),
```

2. Troque
```php
        $app . '/Http/Middleware/IncluirNotasNaListaDePedidos.php',
    ]
```
por
```php
        $app . '/Http/Middleware/IncluirNotasNaListaDePedidos.php',
        // corridas do sistema (relatório do dono); as classes de Support/Entregas/Sistema entram pelo glob acima
        $app . '/Http/Controllers/Entregas/CorridasDoSistemaController.php',
        $app . '/Console/Commands/Entregas/CorridasConferir.php',
    ]
```

- [ ] **Passo 2: Rode e veja falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/corridas-do-sistema.php`
Expected: FAIL com `Class "App\Http\Controllers\Entregas\CorridasDoSistemaController" not found`.

- [ ] **Passo 3: Crie o controller**

`api/app/Http/Controllers/Entregas/CorridasDoSistemaController.php`:

```php
<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\Sistema\PrecosDasCentrais;
use App\Support\Entregas\Sistema\RelatorioDasCorridas;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: Corridas do sistema (console → Fleet-Ops → Financeiro → Corridas do sistema): todas as
 * corridas finalizadas de todas as organizações (centrais), com o preço por corrida de cada central, para o dono do
 * sistema cobrar. Só o administrador do sistema (users.type = 'admin', o primeiro usuário da instalação), pela
 * negarSeNaoDonoDoSistema: NÃO é o negarSeNaoAdmin do Financeiro, que vai passar a ser o administrador da organização.
 * Rotas em int/v1/entregas/sistema/* (RouteServiceProvider). Regras em App\Support\Entregas\Sistema.
 * Spec: docs/superpowers/specs/2026-10-22-corridas-do-sistema-design.md.
 */
class CorridasDoSistemaController extends Controller
{
    public const NEGADO = 'Somente o administrador do sistema pode ver esta tela.';

    /** GET sistema/corridas?inicio=Y-m-d&fim=Y-m-d → {inicio, fim, total, centrais, corridas, lista_cortada}. */
    public function corridas(Request $request)
    {
        if ($negado = $this->negarSeNaoDonoDoSistema($request)) {
            return $negado;
        }

        $relatorio = RelatorioDasCorridas::montar($request->input('inicio'), $request->input('fim'));

        return is_string($relatorio) ? $this->erro($relatorio) : response()->json($relatorio);
    }

    /** GET sistema/precos → {centrais: [{company, nome, precos: [{id, valor, vale_a_partir_de}]}]}, todas as organizações. */
    public function precos(Request $request)
    {
        if ($negado = $this->negarSeNaoDonoDoSistema($request)) {
            return $negado;
        }

        return response()->json(['centrais' => PrecosDasCentrais::lista()]);
    }

    /** POST sistema/precos {company, valor, vale_a_partir_de} → {preco: {company, id, valor, vale_a_partir_de}}. */
    public function criarPreco(Request $request)
    {
        if ($negado = $this->negarSeNaoDonoDoSistema($request)) {
            return $negado;
        }

        $preco = PrecosDasCentrais::criar($request->all(), $this->usuario($request), now()->format('Y-m-d H:i:s'));

        return is_string($preco) ? $this->erro($preco) : response()->json(['preco' => $preco]);
    }

    /** DELETE sistema/precos/{id} → {apagado: true}. */
    public function apagarPreco(Request $request, string $id)
    {
        if ($negado = $this->negarSeNaoDonoDoSistema($request)) {
            return $negado;
        }

        return PrecosDasCentrais::apagar((int) $id) ? response()->json(['apagado' => true]) : $this->erro('Preço não encontrado.', 404);
    }

    protected function usuario(Request $request): ?string
    {
        $usuario = Auth::getUserFromSession($request);

        return $usuario?->uuid ? (string) $usuario->uuid : null;
    }

    protected function erro(string $mensagem, int $status = 422)
    {
        return response()->json(['errors' => [$mensagem]], $status);
    }

    /** Só o administrador do sistema (User::isAdmin(): type 'admin'); o "Administrator" de uma organização é 'user' e não passa. */
    protected function negarSeNaoDonoDoSistema(Request $request)
    {
        $usuario = Auth::getUserFromSession($request);

        if (!$usuario || !$usuario->isAdmin()) {
            return response()->json(['errors' => [static::NEGADO]], 403);
        }

        return null;
    }
}
```

- [ ] **Passo 4: Rotas**

Em `api/app/Providers/RouteServiceProvider.php`:

1. Troque
```php
use App\Http\Controllers\Entregas\ConversasDaLojaController;
```
por
```php
use App\Http\Controllers\Entregas\ConversasDaLojaController;
use App\Http\Controllers\Entregas\CorridasDoSistemaController;
```

2. Troque
```php
                        Route::delete('financeiro/multiplicadores/{id}', [MultiplicadoresController::class, 'apagar']);
```
por
```php
                        Route::delete('financeiro/multiplicadores/{id}', [MultiplicadoresController::class, 'apagar']);

                        // corridas do sistema (relatório do dono, todas as organizações): CorridasDoSistemaController, só o
                        // administrador do sistema (negarSeNaoDonoDoSistema)
                        Route::get('sistema/corridas', [CorridasDoSistemaController::class, 'corridas']);
                        Route::get('sistema/precos', [CorridasDoSistemaController::class, 'precos']);
                        Route::post('sistema/precos', [CorridasDoSistemaController::class, 'criarPreco']);
                        Route::delete('sistema/precos/{id}', [CorridasDoSistemaController::class, 'apagarPreco']);
```

- [ ] **Passo 5: Rode e veja passar**

Run: `for t in corridas-do-sistema imports-dos-providers financeiro-multiplicadores; do PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php | tail -1; done`
Expected: três linhas `FALHAS: 0`.

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Http/Controllers/Entregas/CorridasDoSistemaController.php api/app/Providers/RouteServiceProvider.php`
Expected: exit 0.

- [ ] **Passo 6: Commit**

```bash
git rev-parse --show-toplevel   # esperado: C:/tmp/cs
git add api/app/Http/Controllers/Entregas/CorridasDoSistemaController.php api/app/Providers/RouteServiceProvider.php scripts/teste-php/corridas-do-sistema.php scripts/teste-php/imports-dos-providers.php
git commit -m "$(cat <<'EOF'
Corridas do sistema: API sistema/corridas e sistema/precos, só o administrador do sistema

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Tarefa 7: Funções puras do console

**Arquivos:**
- Criar: `packages/fleetops/addon/utils/corridas-do-sistema.js`
- Criar: `scripts/teste-portal/corridas-do-sistema.test.mjs`

- [ ] **Passo 1: Escreva o teste**

`scripts/teste-portal/corridas-do-sistema.test.mjs`:

```js
// Corridas do sistema no console (packages/fleetops/addon/utils/corridas-do-sistema.js): preço da central no período
// ("variado"), data e hora do ISO, origem, CSV, formulário e corpo do preço.
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/corridas-do-sistema.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chaveDaOrigem, corpoDoPreco, dataHoraDoIso, linhasCsvDasCorridas, nomeDoCsv, precoDaCentral, validarPreco } from '../../packages/fleetops/addon/utils/corridas-do-sistema.js';

const PREFIXO = 'fleet-ops.ui.financeiro.corridas-do-sistema';

test('preço da central no período: nenhum, um só ou variado', () => {
    assert.equal(precoDaCentral([]), null);
    assert.equal(precoDaCentral(undefined), null);
    assert.equal(precoDaCentral([null]), null);
    assert.equal(precoDaCentral([2]), 2);
    assert.equal(precoDaCentral([2.5, '2.50']), 2.5, 'o mesmo valor escrito de dois jeitos é um preço só');
    assert.equal(precoDaCentral([2, 2.5]), 'variado');
});

test('data e hora pela hora escrita no ISO (Brasília), sem converter fuso', () => {
    assert.equal(dataHoraDoIso('2026-10-22T10:05:00-03:00'), '22/10/2026 10:05');
    assert.equal(dataHoraDoIso('2026-10-09T23:59:59-03:00'), '09/10/2026 23:59');
    assert.equal(dataHoraDoIso(''), '');
    assert.equal(dataHoraDoIso(null), '');
    assert.equal(dataHoraDoIso('lixo'), '');
});

test('origem da linha', () => {
    assert.equal(chaveDaOrigem('conclusao'), `${PREFIXO}.origens.conclusao`);
    assert.equal(chaveDaOrigem('conferencia'), `${PREFIXO}.origens.conferencia`);
    assert.equal(chaveDaOrigem('historico'), `${PREFIXO}.origens.historico`);
    assert.equal(chaveDaOrigem('xyz'), `${PREFIXO}.origens.outra`);
});

const relatorio = {
    inicio: '2026-10-01',
    fim: '2026-10-31',
    total: { corridas: 5, a_cobrar: 10, sem_preco: 1 },
    centrais: [
        { company: 'a', nome: 'Central Norte', corridas: 3, a_cobrar: 7, sem_preco: 0, precos: [2, 2.5] },
        { company: 'b', nome: 'Central Sul', corridas: 2, a_cobrar: 3, sem_preco: 1, precos: [3] },
    ],
    corridas: [
        { finalizada_em: '2026-10-05T09:31:00-03:00', company: 'a', central: 'Central Norte', pedido: 'order_a1', numero: '4821', loja_nome: '=Pizzaria', motoboy_nome: 'Carlos "Ligeiro"', preco: 2, origem: 'conclusao' },
        { finalizada_em: '2026-10-09T23:59:59-03:00', company: 'b', central: 'Central Sul', pedido: 'order_b1', numero: null, loja_nome: null, motoboy_nome: null, preco: null, origem: 'historico' },
    ],
    lista_cortada: false,
};

test('CSV: por central com o total, linha em branco e as corridas (pt-BR sem tradutor)', () => {
    assert.deepEqual(linhasCsvDasCorridas(relatorio), [
        '"Central";"Corridas";"Preço";"A cobrar";"Sem preço"',
        '"Central Norte";3;"variado";7,00;0',
        '"Central Sul";2;3,00;3,00;1',
        '"Total";5;;10,00;1',
        '',
        '"Data e hora";"Central";"Pedido";"Nº";"Loja";"Motoboy";"Preço";"Origem"',
        `"05/10/2026 09:31";"Central Norte";"order_a1";"4821";"'=Pizzaria";"Carlos ""Ligeiro""";2,00;"Na conclusão"`,
        '"09/10/2026 23:59";"Central Sul";"order_b1";"";"";"";;"Histórico"',
    ]);
});

test('CSV com tradutor: as chaves de fleet-ops.ui.financeiro.corridas-do-sistema', () => {
    const linhas = linhasCsvDasCorridas(relatorio, (chave) => chave);
    assert.equal(linhas[0].split(';')[0], `"${PREFIXO}.central"`);
    assert.equal(linhas[1].split(';')[2], `"${PREFIXO}.variado"`);
    assert.equal(linhas[6].split(';').at(-1), `"${PREFIXO}.origens.conclusao"`);
});

test('CSV sem relatório: cabeçalhos e o total zerado', () => {
    assert.deepEqual(linhasCsvDasCorridas(null), [
        '"Central";"Corridas";"Preço";"A cobrar";"Sem preço"',
        '"Total";0;;0,00;0',
        '',
        '"Data e hora";"Central";"Pedido";"Nº";"Loja";"Motoboy";"Preço";"Origem"',
    ]);
});

test('nome do arquivo', () => {
    assert.equal(nomeDoCsv('2026-10-01', '2026-10-31'), 'corridas-do-sistema_2026-10-01_2026-10-31.csv');
});

test('formulário do preço: central, valor de 0 a 1000 e data', () => {
    assert.deepEqual(validarPreco({ company: 'central-a', valor: '2,50', data: '2026-10-01' }), []);
    assert.deepEqual(validarPreco({}), ['central', 'valor', 'data']);
    assert.deepEqual(validarPreco({ company: 'a', valor: '0', data: '2026-10-01' }), [], 'zero vale');
    assert.deepEqual(validarPreco({ company: 'a', valor: '1.000', data: '2026-10-01' }), [], '"1.000" = mil, o máximo');
    assert.deepEqual(validarPreco({ company: 'a', valor: '1000,01', data: '2026-10-01' }), ['valor']);
    assert.deepEqual(validarPreco({ company: 'a', valor: '-1', data: '2026-10-01' }), ['valor']);
    assert.deepEqual(validarPreco({ company: 'a', valor: '2,505', data: '2026-10-01' }), ['valor'], 'mais de 2 casas');
    assert.deepEqual(validarPreco({ company: 'a', valor: '2', data: '2026-02-30' }), ['data']);
    assert.deepEqual(corpoDoPreco({ company: 'central-a', valor: '2,5', data: '2026-10-01' }), { company: 'central-a', valor: '2.50', vale_a_partir_de: '2026-10-01' });
});
```

- [ ] **Passo 2: Rode e veja falhar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/corridas-do-sistema.test.mjs`
Expected: FAIL com `ERR_MODULE_NOT_FOUND` (`corridas-do-sistema.js`).

- [ ] **Passo 3: Implemente**

`packages/fleetops/addon/utils/corridas-do-sistema.js`:

```js
/**
 * Entregas RestaurantePro: funções puras da tela Financeiro → Corridas do sistema (relatório do dono, todas as
 * organizações). Dados de int/v1/entregas/sistema/* (api/app/Http/Controllers/Entregas/CorridasDoSistemaController.php).
 * Sem imports do Ember: testado no Node (scripts/teste-portal/corridas-do-sistema.test.mjs).
 * Spec: docs/superpowers/specs/2026-10-22-corridas-do-sistema-design.md.
 */
import { celulaCsv, numeroDigitado, valorParaEnviar } from './financeiro';

const PREFIXO = 'fleet-ops.ui.financeiro.corridas-do-sistema';

/** Mesmo limite do servidor (PrecosDasCentrais::MAXIMO). */
export const PRECO_MAXIMO = 1000;

export const ORIGENS = ['conclusao', 'conferencia', 'historico'];

// textos do CSV quando não há tradução (o produto é pt-BR)
const TEXTOS_PADRAO = {
    central: 'Central',
    corridas: 'Corridas',
    preco: 'Preço',
    'a-cobrar': 'A cobrar',
    'sem-preco': 'Sem preço',
    variado: 'variado',
    total: 'Total',
    'data-hora': 'Data e hora',
    pedido: 'Pedido',
    numero: 'Nº',
    loja: 'Loja',
    motoboy: 'Motoboy',
    origem: 'Origem',
    'origens.conclusao': 'Na conclusão',
    'origens.conferencia': 'Na conferência',
    'origens.historico': 'Histórico',
    'origens.outra': 'Outra',
};

const valorCsv = (valor) => (Number(valor) || 0).toFixed(2).replace('.', ',');

/** Preço da central no período: null (nenhuma corrida com preço), o valor (um só) ou 'variado' (mudou no período). */
export function precoDaCentral(precos) {
    const validos = (Array.isArray(precos) ? precos : []).filter((preco) => preco !== null && preco !== '' && Number.isFinite(Number(preco)));
    const centavos = [...new Set(validos.map((preco) => Math.round(Number(preco) * 100)))];
    if (!centavos.length) {
        return null;
    }

    return centavos.length === 1 ? centavos[0] / 100 : 'variado';
}

/** 'origens.<origem>' (desconhecida: 'origens.outra'). */
function origemCurta(origem) {
    return `origens.${ORIGENS.includes(origem) ? origem : 'outra'}`;
}

/** Chave i18n da origem da linha (conclusao, conferencia, historico). */
export function chaveDaOrigem(origem) {
    return `${PREFIXO}.${origemCurta(origem)}`;
}

/** "22/10/2026 10:05" a partir do ISO do servidor ('2026-10-22T10:05:00-03:00'), pela hora escrita (sem converter o fuso). */
export function dataHoraDoIso(iso) {
    const partes = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(String(iso ?? ''));
    return partes ? `${partes[3]}/${partes[2]}/${partes[1]} ${partes[4]}:${partes[5]}` : '';
}

/**
 * Linhas do CSV (sem o BOM; quem baixa junta com \r\n e põe o \uFEFF na frente): por central (Central; Corridas; Preço;
 * A cobrar; Sem preço) com a linha do total, uma linha em branco e as corridas (Data e hora; Central; Pedido; Nº; Loja;
 * Motoboy; Preço; Origem). Valores com vírgula; preço "variado" ou vazio (sem preço). `t(chave)` traduz (chaves
 * fleet-ops.ui.financeiro.corridas-do-sistema.*); sem ele, pt-BR.
 */
export function linhasCsvDasCorridas(relatorio, t = null) {
    const texto = (chave) => (typeof t === 'function' ? t(`${PREFIXO}.${chave}`) : (TEXTOS_PADRAO[chave] ?? chave));
    const cabecalho = (chaves) => chaves.map((chave) => celulaCsv(texto(chave))).join(';');
    const linhas = [cabecalho(['central', 'corridas', 'preco', 'a-cobrar', 'sem-preco'])];

    for (const central of relatorio?.centrais ?? []) {
        const preco = precoDaCentral(central.precos);
        const precoCsv = preco === 'variado' ? celulaCsv(texto('variado')) : preco === null ? '' : valorCsv(preco);
        linhas.push([celulaCsv(central.nome), Number(central.corridas) || 0, precoCsv, valorCsv(central.a_cobrar), Number(central.sem_preco) || 0].join(';'));
    }
    const total = relatorio?.total ?? {};
    linhas.push([celulaCsv(texto('total')), Number(total.corridas) || 0, '', valorCsv(total.a_cobrar), Number(total.sem_preco) || 0].join(';'));

    linhas.push('');
    linhas.push(cabecalho(['data-hora', 'central', 'pedido', 'numero', 'loja', 'motoboy', 'preco', 'origem']));
    for (const corrida of relatorio?.corridas ?? []) {
        linhas.push(
            [
                celulaCsv(dataHoraDoIso(corrida.finalizada_em)),
                celulaCsv(corrida.central),
                celulaCsv(corrida.pedido),
                celulaCsv(corrida.numero ?? ''),
                celulaCsv(corrida.loja_nome ?? ''),
                celulaCsv(corrida.motoboy_nome ?? ''),
                corrida.preco === null || corrida.preco === undefined ? '' : valorCsv(corrida.preco),
                celulaCsv(texto(origemCurta(corrida.origem))),
            ].join(';')
        );
    }

    return linhas;
}

/** Nome do arquivo do CSV. */
export function nomeDoCsv(inicio, fim) {
    return `corridas-do-sistema_${inicio}_${fim}.csv`;
}

function dataValida(data) {
    const partes = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(data ?? ''));
    if (!partes) {
        return false;
    }
    const [ano, mes, dia] = [Number(partes[1]), Number(partes[2]), Number(partes[3])];
    const local = new Date(ano, mes - 1, dia);

    return local.getFullYear() === ano && local.getMonth() === mes - 1 && local.getDate() === dia;
}

/** Erros do formulário do preço (chaves erros.<x>): central, valor (0 a 1000, até 2 casas) e data ('Y-m-d' que existe). */
export function validarPreco({ company, valor, data } = {}) {
    const erros = [];
    if (!company) {
        erros.push('central');
    }
    const numero = numeroDigitado(valor);
    if (!Number.isFinite(numero) || numero < 0 || numero > PRECO_MAXIMO) {
        erros.push('valor');
    }
    if (!dataValida(data)) {
        erros.push('data');
    }

    return erros;
}

/** Corpo do POST sistema/precos; o valor vai sempre como "12.50" (valorParaEnviar). */
export function corpoDoPreco({ company, valor, data } = {}) {
    return { company, valor: valorParaEnviar(valor), vale_a_partir_de: data };
}
```

- [ ] **Passo 4: Rode e veja passar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/corridas-do-sistema.test.mjs`
Expected: `# pass 8`, `# fail 0`.

Run (o util do financeiro é importado; nada pode ter mudado nele): `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/financeiro.test.mjs`
Expected: `# fail 0`.

- [ ] **Passo 5: Commit**

```bash
git rev-parse --show-toplevel   # esperado: C:/tmp/cs
git add packages/fleetops/addon/utils/corridas-do-sistema.js scripts/teste-portal/corridas-do-sistema.test.mjs
git commit -m "$(cat <<'EOF'
Corridas do sistema: funções puras do console (preço variado, CSV, formulário do preço)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Tarefa 8: Traduções

**Arquivos:**
- Modificar: `packages/fleetops/translations/pt-br.yaml` (2 espaços, CRLF)
- Modificar: `packages/fleetops/translations/en-us.yaml` (4 espaços, CRLF)

Os dois arquivos são CRLF: edite com a ferramenta Edit (ela mantém o fim de linha do arquivo) e confira no Passo 3. Texto com `: ` no meio vai entre aspas simples.

- [ ] **Passo 1: pt-br**

1. Item do menu. Troque
```yaml
        multiplicadores: Multiplicadores
        configuracoes: Configurações
      relatorio:
```
por
```yaml
        multiplicadores: Multiplicadores
        configuracoes: Configurações
        corridas-do-sistema: Corridas do sistema
      relatorio:
```

2. Seção da tela, entre o fim de `multiplicadores:` e `ciclos:`. Troque
```yaml
          duracao: O período pode durar até 7 dias.
      ciclos:
```
por
```yaml
          duracao: O período pode durar até 7 dias.
      corridas-do-sistema:
        titulo: Corridas do sistema
        ajuda: 'Todas as corridas finalizadas em todas as organizações (centrais), para cobrar de cada central o preço por corrida. A corrida entra quando o motoqueiro finaliza e não sai mais, mesmo se o pedido for cancelado ou excluído depois.'
        so-admin: Somente o administrador do sistema pode ver esta tela.
        periodo: Período
        corridas: Corridas
        a-cobrar: A cobrar
        sem-preco-aviso: '{count, plural, one {# corrida sem preço no período: cadastre o preço da central com uma data igual ou anterior à corrida.} other {# corridas sem preço no período: cadastre o preço da central com uma data igual ou anterior à corrida.}}'
        por-central: Por central
        central: Central
        preco: Preço
        variado: variado
        sem-preco: sem preço
        selo-sem-preco: '{count} sem preço'
        total: Total
        vazio: Nenhuma corrida finalizada no período.
        precos: Preços por central
        precos-ajuda: 'O preço vale para as corridas finalizadas a partir da data, até o próximo preço da central. Para mudar, cadastre outro com a data nova; o que veio antes não muda.'
        preco-desde: '{valor} a partir de {data}'
        nenhum-preco: Sem preço cadastrado.
        novo-preco: Adicionar preço
        apagar: Apagar
        apagar-titulo: 'Apagar o preço de {valor} da {central}?'
        apagar-texto: 'As corridas a partir de {data} passam a usar o preço anterior (ou ficam sem preço) até o próximo preço da central.'
        apagado-ok: Preço apagado.
        lista: Corridas finalizadas
        lista-cortada: 'A lista mostra as primeiras {count} corridas; os totais contam todas. Diminua o período para ver o resto.'
        data-hora: Data e hora
        pedido: Pedido
        numero: Nº
        loja: Loja
        motoboy: Motoboy
        origem: Origem
        origens:
          conclusao: Na conclusão
          conferencia: Na conferência
          historico: Histórico
          outra: Outra
        exportar: Exportar CSV
        modal-titulo: Novo preço por corrida
        modal-ajuda: 'O preço vale para as corridas finalizadas a partir do dia escolhido. Data antiga também vale: é assim que se dá preço ao histórico.'
        modal-central: Central
        modal-escolha: Escolha a central
        modal-valor: Preço por corrida (R$)
        modal-data: Vale a partir de
        salvar: Salvar
        cancelar: Cancelar
        salvo: Preço salvo.
        erros:
          central: Escolha a central.
          valor: 'O preço vai de R$ 0,00 a R$ 1.000,00, com até duas casas.'
          data: Informe a data a partir da qual o preço vale.
      ciclos:
```

- [ ] **Passo 2: en-us**

1. Troque
```yaml
                multiplicadores: Multipliers
                configuracoes: Settings
            relatorio:
```
por
```yaml
                multiplicadores: Multipliers
                configuracoes: Settings
                corridas-do-sistema: System rides
            relatorio:
```

2. Troque
```yaml
                    duracao: The period can last up to 7 days.
            ciclos:
```
por
```yaml
                    duracao: The period can last up to 7 days.
            corridas-do-sistema:
                titulo: System rides
                ajuda: 'Every ride finished in every organization (dispatch center), to bill each center its price per ride. A ride enters when the driver finishes it and never leaves, even if the order is canceled or deleted later.'
                so-admin: Only the system administrator can see this screen.
                periodo: Period
                corridas: Rides
                a-cobrar: To bill
                sem-preco-aviso: '{count, plural, one {# ride without a price in the period: add the center price with a date on or before the ride.} other {# rides without a price in the period: add the center price with a date on or before the ride.}}'
                por-central: By center
                central: Center
                preco: Price
                variado: varied
                sem-preco: no price
                selo-sem-preco: '{count} without price'
                total: Total
                vazio: No ride finished in the period.
                precos: Prices by center
                precos-ajuda: 'The price applies to rides finished from the date on, until the next price of the center. To change it, add another one with the new date; what came before does not change.'
                preco-desde: '{valor} from {data}'
                nenhum-preco: No price yet.
                novo-preco: Add price
                apagar: Delete
                apagar-titulo: 'Delete the {valor} price of {central}?'
                apagar-texto: 'Rides from {data} on go back to the previous price (or no price) until the next price of the center.'
                apagado-ok: Price deleted.
                lista: Finished rides
                lista-cortada: 'The list shows the first {count} rides; the totals count all of them. Shorten the period to see the rest.'
                data-hora: Date and time
                pedido: Order
                numero: No.
                loja: Store
                motoboy: Driver
                origem: Source
                origens:
                    conclusao: On completion
                    conferencia: On check
                    historico: History
                    outra: Other
                exportar: Export CSV
                modal-titulo: New price per ride
                modal-ajuda: 'The price applies to rides finished from the chosen day on. An old date also works: that is how the history gets a price.'
                modal-central: Center
                modal-escolha: Choose the center
                modal-valor: Price per ride (R$)
                modal-data: Valid from
                salvar: Save
                cancelar: Cancel
                salvo: Price saved.
                erros:
                    central: Choose the center.
                    valor: 'The price goes from R$ 0.00 to R$ 1,000.00, with up to two decimals.'
                    data: Fill in the date the price is valid from.
            ciclos:
```

- [ ] **Passo 3: Confira**

```bash
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit $?"
for f in packages/fleetops/translations/pt-br.yaml packages/fleetops/translations/en-us.yaml; do node -e "const t=require('fs').readFileSync(process.argv[1],'utf8');console.log(process.argv[1], /(^|[^\r])\n/.test(t)?'LF solto':'só CRLF')" $f; done
```
Expected: `exit 0`; os dois arquivos com `só CRLF`.

- [ ] **Passo 4: Commit**

```bash
git rev-parse --show-toplevel   # esperado: C:/tmp/cs
git add packages/fleetops/translations/pt-br.yaml packages/fleetops/translations/en-us.yaml
git commit -m "$(cat <<'EOF'
Corridas do sistema: traduções pt-BR e en-US da tela e do menu

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Tarefa 9: Tela do console, modal e item do menu

**Arquivos:**
- Modificar: `packages/fleetops/addon/routes.js`
- Criar: `packages/fleetops/addon/routes/financeiro/corridas-do-sistema.js`
- Criar: `packages/fleetops/addon/controllers/financeiro/corridas-do-sistema.js`
- Criar: `packages/fleetops/addon/templates/financeiro/corridas-do-sistema.hbs`
- Criar: `packages/fleetops/addon/components/modals/preco-da-central.js`
- Criar: `packages/fleetops/addon/components/modals/preco-da-central.hbs`
- Criar: `packages/fleetops/app/components/modals/preco-da-central.js`
- Modificar: `packages/fleetops/addon/components/layout/fleet-ops-sidebar.js`

As funções puras já estão testadas (Tarefa 7); aqui a conferência é o parse do JS, a compilação dos `.hbs` pelo `i18n-check` e a tela no navegador (Passo 9).

- [ ] **Passo 1: Rota no `routes.js`**

Em `packages/fleetops/addon/routes.js`, troque
```js
        this.route('multiplicadores');
        this.route('configuracoes');
    });
```
por
```js
        this.route('multiplicadores');
        this.route('configuracoes');
        // relatório do dono do sistema (todas as organizações): guarda própria em routes/financeiro/corridas-do-sistema.js
        this.route('corridas-do-sistema');
    });
```

- [ ] **Passo 2: Rota da tela**

`packages/fleetops/addon/routes/financeiro/corridas-do-sistema.js`:

```js
import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/**
 * Entregas RestaurantePro: Financeiro → Corridas do sistema (relatório do dono: todas as corridas finalizadas de todas as
 * organizações, por central, com o preço por corrida). Só o administrador do sistema (`currentUser.isAdmin`), com guarda
 * própria: não depende da guarda do Financeiro (routes/financeiro.js), que vai passar a ser o administrador da
 * organização. Dados de `int/v1/entregas/sistema/*` (api/app/Http/Controllers/Entregas/CorridasDoSistemaController.php).
 */
export default class FinanceiroCorridasDoSistemaRoute extends Route {
    @service currentUser;
    @service notifications;
    @service hostRouter;
    @service intl;

    beforeModel() {
        if (this.currentUser.isAdmin !== true) {
            this.notifications.warning(this.intl.t('fleet-ops.ui.financeiro.corridas-do-sistema.so-admin'));
            return this.hostRouter.transitionTo('console.fleet-ops');
        }
    }

    setupController(controller) {
        super.setupController(...arguments);
        controller.atualizarPeriodo();
        controller.carregar.perform();
        controller.carregarPrecos.perform();
    }

    resetController(controller, isExiting) {
        super.resetController(...arguments);
        if (isExiting) {
            controller.limpar();
        }
    }
}
```

- [ ] **Passo 3: Controller**

`packages/fleetops/addon/controllers/financeiro/corridas-do-sistema.js`:

```js
import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { formatadores, periodoAoAbrir, periodoDoAtalho } from '../../utils/financeiro';
import { chaveDaOrigem, dataHoraDoIso, linhasCsvDasCorridas, nomeDoCsv, precoDaCentral } from '../../utils/corridas-do-sistema';

const ENDPOINT = 'entregas/sistema';
const PREFIXO = 'fleet-ops.ui.financeiro.corridas-do-sistema';

/**
 * Entregas RestaurantePro: Financeiro → Corridas do sistema. Período (padrão: este mês), resumo, por central (preço
 * "variado" quando mudou no período, selo "sem preço"), preços por central (adicionar no modal preco-da-central, apagar
 * com confirmação), a lista das corridas e o CSV. Regras no servidor (App\Support\Entregas\Sistema).
 */
export default class FinanceiroCorridasDoSistemaController extends Controller {
    @service fetch;
    @service intl;
    @service notifications;
    @service modalsManager;

    @tracked inicio = periodoDoAtalho('this-month').inicio;
    @tracked fim = periodoDoAtalho('this-month').fim;
    @tracked relatorio = null;
    @tracked centraisDosPrecos = [];
    // atalho do período em uso (recalculado ao voltar à tela); null = datas escolhidas à mão
    atalho = 'this-month';

    get f() {
        return formatadores(this.intl.primaryLocale);
    }

    get total() {
        return this.relatorio?.total ?? { corridas: 0, a_cobrar: 0, sem_preco: 0 };
    }

    get centrais() {
        return this.relatorio?.centrais ?? [];
    }

    get corridas() {
        return this.relatorio?.corridas ?? [];
    }

    /** Na entrada da tela (setupController): o período do atalho em uso recalculado para hoje; datas à mão ficam. */
    atualizarPeriodo() {
        const periodo = periodoAoAbrir(this.atalho, { inicio: this.inicio, fim: this.fim });
        this.inicio = periodo.inicio;
        this.fim = periodo.fim;
    }

    limpar() {
        this.carregar.cancelAll();
        this.carregarPrecos.cancelAll();
        this.relatorio = null;
    }

    @action valor(numero) {
        return this.f.valor(numero);
    }

    @action dia(data) {
        return this.f.dia(data);
    }

    @action dataHora(iso) {
        return dataHoraDoIso(iso);
    }

    @action origem(corrida) {
        return this.intl.t(chaveDaOrigem(corrida?.origem));
    }

    @action precoDaCentral(central) {
        const preco = precoDaCentral(central?.precos);
        if (preco === null) {
            return this.intl.t(`${PREFIXO}.sem-preco`);
        }

        return preco === 'variado' ? this.intl.t(`${PREFIXO}.variado`) : this.f.valor(preco);
    }

    @action precoDaCorrida(corrida) {
        return corrida?.preco === null || corrida?.preco === undefined ? this.intl.t(`${PREFIXO}.sem-preco`) : this.f.valor(corrida.preco);
    }

    @action setData(campo, event) {
        this[campo] = event.target.value;
        this.atalho = null;
    }

    @action setPeriodo(atalho) {
        const periodo = periodoDoAtalho(atalho);
        if (periodo) {
            this.atalho = atalho;
            this.inicio = periodo.inicio;
            this.fim = periodo.fim;
            this.carregar.perform();
        }
    }

    @task({ restartable: true }) *carregar() {
        try {
            this.relatorio = yield this.fetch.get(`${ENDPOINT}/corridas`, { inicio: this.inicio, fim: this.fim });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ restartable: true }) *carregarPrecos() {
        try {
            const resposta = yield this.fetch.get(`${ENDPOINT}/precos`);
            this.centraisDosPrecos = resposta?.centrais ?? [];
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /** Relê os preços e o relatório (o preço muda o valor das corridas do período). */
    @action recarregar() {
        this.carregarPrecos.perform();
        this.carregar.perform();
    }

    /**
     * Abre o modal do preço. Do botão de uma central vem o uuid dela (`fn this.novoPreco central.company`); do botão do
     * cabeçalho vem o evento do clique (o onClick e o `fn` acrescentam o evento como último argumento): só texto conta
     * como central.
     */
    @action novoPreco(company) {
        this.modalsManager.show('modals/preco-da-central', {
            centrais: this.centraisDosPrecos,
            company: typeof company === 'string' ? company : null,
            onSalvo: () => this.recarregar(),
        });
    }

    @action apagarPreco(central, preco) {
        this.modalsManager.confirm({
            title: this.intl.t(`${PREFIXO}.apagar-titulo`, { valor: this.f.valor(preco.valor), central: central.nome }),
            body: this.intl.t(`${PREFIXO}.apagar-texto`, { data: this.f.dia(preco.vale_a_partir_de) }),
            acceptButtonText: this.intl.t(`${PREFIXO}.apagar`),
            acceptButtonScheme: 'danger',
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await this.fetch.delete(`${ENDPOINT}/precos/${preco.id}`);
                    this.notifications.success(this.intl.t(`${PREFIXO}.apagado-ok`));
                    modal.done();
                    this.recarregar();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    @action exportarCsv() {
        if (!this.relatorio) {
            return;
        }
        const linhas = linhasCsvDasCorridas(this.relatorio, (chave) => this.intl.t(chave));
        const blob = new Blob(['\uFEFF' + linhas.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = nomeDoCsv(this.relatorio.inicio, this.relatorio.fim);
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }
}
```

- [ ] **Passo 4: Template**

`packages/fleetops/addon/templates/financeiro/corridas-do-sistema.hbs`:

```hbs
<Layout::Section::Header @title={{t "fleet-ops.ui.financeiro.corridas-do-sistema.titulo"}}>
    <div class="flex items-center space-x-2">
        <Button @icon="refresh" @size="sm" @helpText={{t "common.reload"}} @isLoading={{this.carregar.isRunning}} @onClick={{this.recarregar}} />
        <Button @icon="file-export" @size="sm" @text={{t "fleet-ops.ui.financeiro.corridas-do-sistema.exportar"}} @disabled={{not this.relatorio}} @onClick={{this.exportarCsv}} />
        <Button @type="primary" @icon="plus" @size="sm" @text={{t "fleet-ops.ui.financeiro.corridas-do-sistema.novo-preco"}} @onClick={{this.novoPreco}} />
    </div>
</Layout::Section::Header>

<Layout::Section::Body class="overflow-y-scroll-i h-full">
    <div class="max-w-6xl mx-auto px-4 py-6 space-y-5">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.corridas-do-sistema.ajuda"}}</p>

        <ContentPanel @title={{t "fleet-ops.ui.financeiro.corridas-do-sistema.periodo"}} @open={{true}} @wrapperClass="bordered-classic">
            <div class="flex flex-wrap items-end gap-4">
                <InputGroup @name={{t "fleet-ops.ui.financeiro.periodo.inicio"}} @wrapperClass="mb-0">
                    <input type="date" class="form-input form-input-sm" value={{this.inicio}} max={{this.fim}} {{on "change" (fn this.setData "inicio")}} />
                </InputGroup>
                <InputGroup @name={{t "fleet-ops.ui.financeiro.periodo.fim"}} @wrapperClass="mb-0">
                    <input type="date" class="form-input form-input-sm" value={{this.fim}} min={{this.inicio}} {{on "change" (fn this.setData "fim")}} />
                </InputGroup>
                <Button @type="primary" @size="sm" @icon="magnifying-glass" @text={{t "fleet-ops.ui.financeiro.periodo.aplicar"}} @isLoading={{this.carregar.isRunning}} @onClick={{perform this.carregar}} />
                <div class="flex items-center gap-2">
                    <Button @size="xs" @text={{t "fleet-ops.ui.financeiro.periodo.este-mes"}} @onClick={{fn this.setPeriodo "this-month"}} />
                    <Button @size="xs" @text={{t "fleet-ops.ui.financeiro.periodo.mes-passado"}} @onClick={{fn this.setPeriodo "last-month"}} />
                </div>
            </div>
        </ContentPanel>

        {{#if this.relatorio}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <div class="text-xs uppercase text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.corridas-do-sistema.corridas"}}</div>
                    <div class="text-2xl font-semibold dark:text-white">{{this.total.corridas}}</div>
                </div>
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <div class="text-xs uppercase text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.corridas-do-sistema.a-cobrar"}}</div>
                    <div class="text-2xl font-semibold text-green-700 dark:text-green-400">{{this.valor this.total.a_cobrar}}</div>
                </div>
            </div>
            {{#if this.total.sem_preco}}
                <p class="text-sm rounded-md px-3 py-2 bg-yellow-50 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-100">{{t "fleet-ops.ui.financeiro.corridas-do-sistema.sem-preco-aviso" count=this.total.sem_preco}}</p>
            {{/if}}

            <ContentPanel @title={{t "fleet-ops.ui.financeiro.corridas-do-sistema.por-central"}} @open={{true}} @wrapperClass="bordered-classic">
                {{#if this.centrais.length}}
                    <table class="table w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="py-2">{{t "fleet-ops.ui.financeiro.corridas-do-sistema.central"}}</th>
                                <th class="py-2 text-right">{{t "fleet-ops.ui.financeiro.corridas-do-sistema.corridas"}}</th>
                                <th class="py-2 text-right">{{t "fleet-ops.ui.financeiro.corridas-do-sistema.preco"}}</th>
                                <th class="py-2 text-right">{{t "fleet-ops.ui.financeiro.corridas-do-sistema.a-cobrar"}}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {{#each this.centrais key="company" as |central|}}
                                <tr class="border-t border-gray-100 dark:border-gray-800 dark:text-gray-100">
                                    <td class="py-2 font-medium">
                                        {{central.nome}}
                                        {{#if central.sem_preco}}
                                            <span class="ml-2 text-xs font-medium rounded px-2 py-0.5 bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-100">{{t "fleet-ops.ui.financeiro.corridas-do-sistema.selo-sem-preco" count=central.sem_preco}}</span>
                                        {{/if}}
                                    </td>
                                    <td class="py-2 text-right">{{central.corridas}}</td>
                                    <td class="py-2 text-right">{{this.precoDaCentral central}}</td>
                                    <td class="py-2 text-right font-medium">{{this.valor central.a_cobrar}}</td>
                                </tr>
                            {{/each}}
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-200 dark:border-gray-700 font-semibold dark:text-white">
                                <td class="py-2">{{t "fleet-ops.ui.financeiro.corridas-do-sistema.total"}}</td>
                                <td class="py-2 text-right">{{this.total.corridas}}</td>
                                <td class="py-2"></td>
                                <td class="py-2 text-right">{{this.valor this.total.a_cobrar}}</td>
                            </tr>
                        </tfoot>
                    </table>
                {{else}}
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.corridas-do-sistema.vazio"}}</p>
                {{/if}}
            </ContentPanel>
        {{else if this.carregar.isRunning}}
            <Spinner />
        {{/if}}

        <ContentPanel @title={{t "fleet-ops.ui.financeiro.corridas-do-sistema.precos"}} @open={{true}} @wrapperClass="bordered-classic">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">{{t "fleet-ops.ui.financeiro.corridas-do-sistema.precos-ajuda"}}</p>
            {{#each this.centraisDosPrecos key="company" as |central|}}
                <div class="flex flex-wrap items-start justify-between gap-3 py-3 border-t border-gray-100 dark:border-gray-800">
                    <div>
                        <div class="font-medium dark:text-white">{{central.nome}}</div>
                        {{#if central.precos.length}}
                            <ul class="mt-1 space-y-1 text-sm text-gray-600 dark:text-gray-300">
                                {{#each central.precos key="id" as |preco|}}
                                    <li class="flex items-center gap-2">
                                        <span>{{t "fleet-ops.ui.financeiro.corridas-do-sistema.preco-desde" valor=(this.valor preco.valor) data=(this.dia preco.vale_a_partir_de)}}</span>
                                        <Button @size="xs" @icon="trash" @helpText={{t "fleet-ops.ui.financeiro.corridas-do-sistema.apagar"}} @onClick={{fn this.apagarPreco central preco}} />
                                    </li>
                                {{/each}}
                            </ul>
                        {{else}}
                            <p class="mt-1 text-sm text-yellow-700 dark:text-yellow-300">{{t "fleet-ops.ui.financeiro.corridas-do-sistema.nenhum-preco"}}</p>
                        {{/if}}
                    </div>
                    <Button @size="xs" @icon="plus" @text={{t "fleet-ops.ui.financeiro.corridas-do-sistema.novo-preco"}} @onClick={{fn this.novoPreco central.company}} />
                </div>
            {{else}}
                {{#if this.carregarPrecos.isRunning}}
                    <Spinner />
                {{/if}}
            {{/each}}
        </ContentPanel>

        {{#if this.corridas.length}}
            <ContentPanel @title={{t "fleet-ops.ui.financeiro.corridas-do-sistema.lista"}} @open={{true}} @wrapperClass="bordered-classic">
                {{#if this.relatorio.lista_cortada}}
                    <p class="text-xs rounded-md px-3 py-2 mb-3 bg-yellow-50 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-100">{{t "fleet-ops.ui.financeiro.corridas-do-sistema.lista-cortada" count=this.corridas.length}}</p>
                {{/if}}
                <div class="overflow-x-auto">
                    <table class="table w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="py-2">{{t "fleet-ops.ui.financeiro.corridas-do-sistema.data-hora"}}</th>
                                <th class="py-2">{{t "fleet-ops.ui.financeiro.corridas-do-sistema.central"}}</th>
                                <th class="py-2">{{t "fleet-ops.ui.financeiro.corridas-do-sistema.pedido"}}</th>
                                <th class="py-2">{{t "fleet-ops.ui.financeiro.corridas-do-sistema.numero"}}</th>
                                <th class="py-2">{{t "fleet-ops.ui.financeiro.corridas-do-sistema.loja"}}</th>
                                <th class="py-2">{{t "fleet-ops.ui.financeiro.corridas-do-sistema.motoboy"}}</th>
                                <th class="py-2 text-right">{{t "fleet-ops.ui.financeiro.corridas-do-sistema.preco"}}</th>
                                <th class="py-2">{{t "fleet-ops.ui.financeiro.corridas-do-sistema.origem"}}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {{#each this.corridas as |corrida|}}
                                <tr class="border-t border-gray-100 dark:border-gray-800 dark:text-gray-100">
                                    <td class="py-2 whitespace-nowrap">{{this.dataHora corrida.finalizada_em}}</td>
                                    <td class="py-2">{{corrida.central}}</td>
                                    <td class="py-2 whitespace-nowrap">{{corrida.pedido}}</td>
                                    <td class="py-2">{{corrida.numero}}</td>
                                    <td class="py-2">{{corrida.loja_nome}}</td>
                                    <td class="py-2">{{corrida.motoboy_nome}}</td>
                                    <td class="py-2 text-right whitespace-nowrap">{{this.precoDaCorrida corrida}}</td>
                                    <td class="py-2 text-xs text-gray-500 dark:text-gray-400">{{this.origem corrida}}</td>
                                </tr>
                            {{/each}}
                        </tbody>
                    </table>
                </div>
            </ContentPanel>
        {{/if}}
    </div>
    <Spacer @height="300px" />
</Layout::Section::Body>
```

- [ ] **Passo 5: Modal do preço**

`packages/fleetops/addon/components/modals/preco-da-central.js`:

```js
import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { formatadores, isoDate, valorInterpretado } from '../../utils/financeiro';
import { corpoDoPreco, validarPreco } from '../../utils/corridas-do-sistema';

const PREFIXO = 'fleet-ops.ui.financeiro.corridas-do-sistema';
const ENDPOINT = 'entregas/sistema/precos';

/**
 * Entregas RestaurantePro: modal "Novo preço por corrida" (Financeiro → Corridas do sistema): central, valor e "vale a
 * partir de". Opções: `centrais` ([{company, nome}] do GET sistema/precos), `company` (a central já escolhida, ou null)
 * e `onSalvo()`. Só fecha quando grava (keepOpen). Sem `return` no confirm: ver a armadilha do modals-manager no
 * packages/fleetops/CLAUDE.md.
 */
export default class ModalsPrecoDaCentralComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;

    @tracked company = null;
    @tracked valor = '';
    @tracked data = isoDate(new Date());

    constructor(owner, { options }) {
        super(...arguments);
        this.options = options;
        this.company = options.company ?? null;
        this.prepararOpcoes();
    }

    get centrais() {
        return this.options.centrais ?? [];
    }

    get erros() {
        return validarPreco({ company: this.company, valor: this.valor, data: this.data }).map((erro) => this.intl.t(`${PREFIXO}.erros.${erro}`));
    }

    /** "= R$ 2,50" ao lado do campo, como nos outros modais do Financeiro. */
    get valorInterpretado() {
        return valorInterpretado(this.valor, formatadores(this.intl.primaryLocale));
    }

    prepararOpcoes() {
        this.options.title = this.intl.t(`${PREFIXO}.modal-titulo`);
        this.options.acceptButtonText = this.intl.t(`${PREFIXO}.salvar`);
        this.options.acceptButtonIcon = 'save';
        this.options.declineButtonText = this.intl.t(`${PREFIXO}.cancelar`);
        this.options.keepOpen = true;
        this.options.confirm = async (modal, done) => {
            if (this.erros.length) {
                this.notifications.warning(this.erros[0]);
                return;
            }

            modal.startLoading();
            try {
                await this.fetch.post(ENDPOINT, corpoDoPreco({ company: this.company, valor: this.valor, data: this.data }));
                this.notifications.success(this.intl.t(`${PREFIXO}.salvo`));
                done();
                this.options.onSalvo?.();
            } catch (error) {
                this.notifications.serverError(error);
                modal.stopLoading();
            }
        };
        this.options.decline = (modal, done) => done();
    }

    @action setCampo(campo, event) {
        const valor = event.target.value;
        this[campo] = campo === 'company' ? valor || null : valor;
    }
}
```

`packages/fleetops/addon/components/modals/preco-da-central.hbs`:

```hbs
<Modal::Default @modalIsOpened={{@modalIsOpened}} @options={{@options}} @confirm={{@onConfirm}} @decline={{@onDecline}}>
    <div class="px-4 space-y-4 text-gray-900 dark:text-gray-50">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.corridas-do-sistema.modal-ajuda"}}</p>
        <InputGroup @name={{t "fleet-ops.ui.financeiro.corridas-do-sistema.modal-central"}} @required={{true}} @wrapperClass="mb-0" as |id|>
            <select id={{id}} class="form-select w-full text-sm" {{on "change" (fn this.setCampo "company")}}>
                <option value="" selected={{not this.company}}>{{t "fleet-ops.ui.financeiro.corridas-do-sistema.modal-escolha"}}</option>
                {{#each this.centrais as |central|}}
                    <option value={{central.company}} selected={{eq central.company this.company}}>{{central.nome}}</option>
                {{/each}}
            </select>
        </InputGroup>
        <div class="grid grid-cols-2 gap-4">
            <InputGroup @name={{t "fleet-ops.ui.financeiro.corridas-do-sistema.modal-valor"}} @required={{true}} @wrapperClass="mb-0" as |id|>
                <input id={{id}} type="text" inputmode="decimal" class="form-input w-full" value={{this.valor}} {{on "input" (fn this.setCampo "valor")}} />
                {{#if this.valorInterpretado}}
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.valor-interpretado" valor=this.valorInterpretado}}</span>
                {{/if}}
            </InputGroup>
            <InputGroup @name={{t "fleet-ops.ui.financeiro.corridas-do-sistema.modal-data"}} @required={{true}} @wrapperClass="mb-0" as |id|>
                <input id={{id}} type="date" class="form-input w-full" value={{this.data}} {{on "change" (fn this.setCampo "data")}} />
            </InputGroup>
        </div>
        {{#each this.erros as |erro|}}
            <p class="text-xs text-yellow-700 dark:text-yellow-300">{{erro}}</p>
        {{/each}}
    </div>
</Modal::Default>
```

`packages/fleetops/app/components/modals/preco-da-central.js`:

```js
export { default } from '@fleetbase/fleetops-engine/components/modals/preco-da-central';
```

- [ ] **Passo 6: Item do menu**

Em `packages/fleetops/addon/components/layout/fleet-ops-sidebar.js`:

1. Em `get financeiroItems()`, troque
```js
            item('fleet-ops.ui.financeiro.menu.configuracoes', 'gear', 'financeiro.configuracoes', ['data de corte', 'ciclo']),
        ]);
```
por
```js
            item('fleet-ops.ui.financeiro.menu.configuracoes', 'gear', 'financeiro.configuracoes', ['data de corte', 'ciclo']),
            // relatório do dono do sistema (todas as organizações): regra própria, independente da dos itens acima, que vai
            // passar a ser o administrador da organização
            {
                ...item('fleet-ops.ui.financeiro.menu.corridas-do-sistema', 'building', 'financeiro.corridas-do-sistema', ['corridas', 'centrais', 'organizações', 'sistema']),
                visible: this.currentUser.isAdmin === true,
            },
        ]);
```

2. Em `defaultPriorityForRoute`, troque
```js
            'financeiro.configuracoes': 5,
```
por
```js
            'financeiro.configuracoes': 5,
            'financeiro.corridas-do-sistema': 6,
```

- [ ] **Passo 7: Confira o parse, o BOM e os templates**

```bash
node -e "const fs=require('fs');const d=fs.readdirSync('console/node_modules/.pnpm').filter(n=>n.startsWith('@babel+parser@7')).sort().pop();const p=require('./console/node_modules/.pnpm/'+d+'/node_modules/@babel/parser');for(const f of process.argv.slice(1)){p.parse(fs.readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties']});console.log('ok '+f)}" packages/fleetops/addon/routes.js packages/fleetops/addon/routes/financeiro/corridas-do-sistema.js packages/fleetops/addon/controllers/financeiro/corridas-do-sistema.js packages/fleetops/addon/components/modals/preco-da-central.js packages/fleetops/app/components/modals/preco-da-central.js packages/fleetops/addon/components/layout/fleet-ops-sidebar.js packages/fleetops/addon/utils/corridas-do-sistema.js
grep -nP '\x{FEFF}' packages/fleetops/addon/controllers/financeiro/corridas-do-sistema.js || echo "sem BOM literal"
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit $?"
```
Expected: sete linhas `ok ...`; `sem BOM literal`; `exit 0` (o `i18n-check` compila os `.hbs` novos e confere as chaves literais).

- [ ] **Passo 8: Testes do console de novo**

Run: `for t in corridas-do-sistema financeiro financeiro-multiplicadores; do node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/$t.test.mjs 2>&1 | grep '^# fail'; done`
Expected: três linhas `# fail 0`.

- [ ] **Passo 9: Conferência no navegador (se houver console local)**

Sem console local (o build leva ~15 min na VPS), esta conferência fica para a implantação (seção "Implantação", passo 5). Com console local apontando para uma API com as rotas novas: entre como o administrador do sistema, abra Fleet-Ops → Financeiro → Corridas do sistema (último item, ícone de prédio), cadastre um preço, veja o "variado" ao cadastrar um segundo preço no meio do mês, apague um preço (confirmação), baixe o CSV e abra no Excel (acentos certos, vírgula nos valores).

- [ ] **Passo 10: Commit**

```bash
git rev-parse --show-toplevel   # esperado: C:/tmp/cs
git add packages/fleetops/addon/routes.js packages/fleetops/addon/routes/financeiro/corridas-do-sistema.js packages/fleetops/addon/controllers/financeiro/corridas-do-sistema.js packages/fleetops/addon/templates/financeiro/corridas-do-sistema.hbs packages/fleetops/addon/components/modals/preco-da-central.js packages/fleetops/addon/components/modals/preco-da-central.hbs packages/fleetops/app/components/modals/preco-da-central.js packages/fleetops/addon/components/layout/fleet-ops-sidebar.js
git commit -m "$(cat <<'EOF'
Console: Financeiro → Corridas do sistema (por central, preços com vigência, lista e CSV), só o administrador do sistema

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Tarefa 10: Documentação

**Arquivos:**
- Criar: `api/app/Support/Entregas/Sistema/CLAUDE.md`
- Modificar: `CLAUDE.md` (raiz, tabela "Onde está cada assunto")
- Modificar: `packages/fleetops/CLAUDE.md` (Financeiro)
- Modificar: `api/app/Support/Entregas/Financeiro/CLAUDE.md` ("Só administradores")
- Modificar: `deploy/zerar-pedidos/LEIAME.md` e `deploy/zerar-pedidos/1-inventario.sql`
- Modificar: `docs/historico.md`

**Pré-condição:** em 2026-10-22 a reorganização dos `CLAUDE.md` (raiz enxuta com a tabela "Onde está cada assunto", `api/CLAUDE.md`, `packages/fleetops/CLAUDE.md` e os outros por pasta) **ainda não estava commitada** no checkout principal. Antes desta tarefa, rode `git ls-files packages/fleetops/CLAUDE.md api/CLAUDE.md docs/historico.md` na worktree:
- se listar os três, siga os passos;
- se não, faça só os passos 1, 5 e 6 (arquivos que já existem no git), não crie cópias dos arquivos da reorganização, e entregue ao Edgard o texto dos passos 2, 3 e 4 para ele aplicar quando commitar a reorganização. Nunca commite os arquivos dele junto com os seus.

Os `CLAUDE.md` da raiz, o `packages/fleetops/CLAUDE.md` e o `docs/historico.md` são CRLF (o Edit mantém).

- [ ] **Passo 1: `api/app/Support/Entregas/Sistema/CLAUDE.md`**

```markdown
# Corridas do sistema (relatório do dono)

Parte do `CLAUDE.md` da raiz (índice em "Onde está cada assunto"). Responda e documente em português do Brasil.

Controle do Edgard para cobrar das centrais de motoqueiros (cada central = uma organização nova na mesma instalação) um valor fixo por corrida finalizada. Spec: `docs/superpowers/specs/2026-10-22-corridas-do-sistema-design.md`; plano: `docs/superpowers/plans/2026-10-22-corridas-do-sistema.md`. Tela: Fleet-Ops → Financeiro → Corridas do sistema (`packages/fleetops/CLAUDE.md`, "Financeiro"). Só o relatório: os recebimentos das centrais ficam fora (ele controla fora por enquanto).

## Quem vê

- Só o **administrador do sistema**: `users.type = 'admin'` (`User::isAdmin()`; no console, `currentUser.isAdmin`, que vem do `is_admin`). O Fleetbase só dá esse tipo ao **primeiro usuário** da instalação (`OnboardController::createAccount`: `$isAdmin = !User::exists()`); quem cria organização depois entra como `user`, mesmo sendo "Administrator" da própria organização. Em 2026-10-22, o único é o Edgard Junior (organização Restaurante Pro).
- API: `CorridasDoSistemaController::negarSeNaoDonoDoSistema` em todas as rotas (403 "Somente o administrador do sistema pode ver esta tela."). **Não** troque pelo `negarSeNaoAdmin` do Financeiro: aquele vai passar a ser o administrador da organização (ver "Só administradores" em `api/app/Support/Entregas/Financeiro/CLAUDE.md`).
- Console: item do menu com `visible: currentUser.isAdmin === true` próprio e guarda própria na rota (`routes/financeiro/corridas-do-sistema.js`), independentes das do resto do Financeiro.

## Registro permanente (`CorridasFinalizadas`)

- Tabela `entregas_corridas_finalizadas` (migration `2026_10_22_100000_create_entregas_sistema_tables.php`): uma linha por pedido (`order_uuid` único), sem `updated_at` e sem `deleted_at`. **O código nunca atualiza nem apaga:** cancelar, excluir o pedido ou o `deploy/zerar-pedidos` não tiram a corrida.
- Colunas: `company_uuid` (a central), `pedido` (`public_id`), `numero` (`internal_id`), `loja_uuid`/`loja_nome` (o Vendor dono do pedido; cliente que não é loja fica vazio), `motoboy_uuid`/`motoboy_nome` (o `driver_assigned_uuid` e o nome do User dele), `finalizada_em` (Brasília, `'Y-m-d H:i:s'`), `origem` (`conclusao`, `conferencia`, `historico`), `created_at`. Os nomes ficam gravados na hora; o nome da central é lido no relatório em `companies`, com as excluídas, e cai no uuid curto (8 caracteres) se a organização não existir mais.
- **Finalizada** = `orders.status` em `completed` ou `done` (`CorridasFinalizadas::CONCLUIDOS` = `ObservadorFinanceiroDosPedidos::CONCLUIDOS`), **ou** um `COMPLETED` no `tracking_statuses` do pedido (o cancelado depois de finalizado entra). O cancelado pago pelo iFood ou pela integração sem `COMPLETED` **não** entra.
- `finalizada_em`: no observador, agora; na conferência e no histórico, o primeiro `tracking_statuses.created_at` com `code = 'COMPLETED'` (inclusive status excluídos), senão o `orders.updated_at`.
- **Quem grava** (sempre `insertOrIgnore` pelo `order_uuid`: fica a primeira linha):
  1. `App\Listeners\Entregas\ObservadorDasCorridasDoSistema` (`Order::updated`, `AppServiceProvider::registrarCorridasDoSistema`): status mudou para concluído → origem `conclusao`. Sem chave nenhuma. Nunca lança (log `[entregas] sistema: falha ao registrar a corrida finalizada`, só o pedido e a classe do erro).
  2. `entregas:corridas-conferir` (`CorridasConferir`, a cada 5 min, `withoutOverlapping(30)`, **sem** `when`: não depende da `ENTREGAS_FINANCEIRO`): pedidos concluídos (status atualizado nos últimos 2 dias) ou com `COMPLETED` nos últimos 2 dias, de todas as organizações, excluídos também → origem `conferencia`.
  3. Histórico: a mesma rotina, com a tabela **vazia** (a primeira rodada depois do deploy), importa tudo o que está no banco, sem limite de data, em lotes de 500 pelo id → origem `historico`. À mão: `php artisan entregas:corridas-conferir --historico` (idempotente).
- Lê pelo `DB::table` (sem escopos do Eloquent): pedido e organização excluídos entram sem `withTrashed`.
- Logs no `docker service logs entregas_scheduler`: `[entregas] sistema: histórico das corridas importado`, `... conferência gravou corridas` (com a contagem), `... conferência das corridas falhou` (sai com FAILURE; a rodada seguinte tenta de novo).

## Preço por central (`PrecosDasCentrais`) e relatório (`RelatorioDasCorridas`)

- Tabela `entregas_precos_centrais`: `company_uuid`, `valor` (R$ 0,00 a 1000,00, até 2 casas), `vale_a_partir_de` (dia em Brasília), `criado_por`, `created_at`; único (`company_uuid`, `vale_a_partir_de`).
- O preço de uma corrida é o da central com a maior data ≤ dia da `finalizada_em`, **calculado na hora do relatório**: um preço novo não muda o que veio antes da data dele; um preço com data antiga precifica o histórico (é assim que se dá preço ao que veio da importação). Antes do primeiro preço, a corrida vale R$ 0,00 e conta em "sem preço".
- Não se edita: cadastra-se outro com outra data. Apagar existe (correção de digitação), com confirmação na tela, porque muda os valores do período dele.
- Relatório: período de até 366 dias, `finalizada_em` de `inicio 00:00:00` a `fim 23:59:59`; centrais por nome com `precos` = valores distintos usados (o console mostra "variado" quando há mais de um); lista em ordem de finalização, cortada em 20.000 linhas (`lista_cortada`), com os totais sempre completos. Contas em centavos.

## Rotas

Grupo `int/v1/entregas` (`fleetbase.protected`), todas com `negarSeNaoDonoDoSistema`. Erros em `{"errors": ["…"]}`.

| Método e caminho | Controller@método |
|---|---|
| GET `sistema/corridas?inicio=Y-m-d&fim=Y-m-d` | `CorridasDoSistemaController@corridas` → `{inicio, fim, total: {corridas, a_cobrar, sem_preco}, centrais: [{company, nome, corridas, a_cobrar, sem_preco, precos}], corridas: [{finalizada_em (ISO -03:00), company, central, pedido, numero, loja_nome, motoboy_nome, preco, origem}], lista_cortada}`; período inválido → 422 |
| GET `sistema/precos` | `@precos` → `{centrais: [{company, nome, precos: [{id, valor, vale_a_partir_de}]}]}`: as organizações não excluídas e as excluídas que têm preço; preços do mais recente para o mais antigo |
| POST `sistema/precos` `{company, valor, vale_a_partir_de}` | `@criarPreco` → `{preco}`; 422 para valor fora da faixa, data inválida, organização inexistente ou excluída e data repetida para a central |
| DELETE `sistema/precos/{id}` | `@apagarPreco` → `{apagado: true}`; 404 se não existe |

## Implantação

API + console (`bash deploy/atualizar.sh` na VPS). A migration cria as duas tabelas no `deploy.sh`; a primeira conferência (até 5 min depois) importa o histórico. Depois, o Edgard cadastra o preço da Restaurante Pro (e de cada central nova) com a data a partir da qual quer cobrar.

## Testes

- `scripts/teste-php/corridas-do-sistema.php` (migrations, registro idempotente, conferência com excluído e cancelado depois do `COMPLETED`, histórico só com tabela vazia, preço pela vigência e "variado", totais, observador, comando, agenda, 403 para quem não é `admin`, validações do POST, rotas); `ifood-agendador.php` tira o comando da lista dos do iFood; `imports-dos-providers.php`.
- Front: `scripts/teste-portal/corridas-do-sistema.test.mjs` (`packages/fleetops/addon/utils/corridas-do-sistema.js`).

## Armadilhas

- O `insertOrIgnore` do MySQL engole outros erros além da chave repetida (dado truncado): por isso os textos são cortados antes (`pedido` em 64, nomes e número em 191).
- O `deploy/zerar-pedidos` **não** apaga esta tabela (de propósito: a corrida nunca sai). Se um dia for preciso, apague à mão, e a próxima conferência reimporta o que ainda estiver no banco.
- Pedido concluído sem `orders.updated_at` recente e sem `COMPLETED` no rastreio (ex.: status trocado no banco à mão com data antiga) só entra pelo `--historico`.

## Ao atualizar o fleetops-api ou o core-api, confira

- `Order::updated` ainda dispara na conclusão (o app conclui pela API v1) e o `status` muda para `completed`/`done`.
- `tracking_statuses.code = 'COMPLETED'` e o vínculo `orders.tracking_number_uuid` → `tracking_statuses.tracking_number_uuid`.
- `orders.id` (lotes do histórico), `drivers.user_uuid` → `users.name` (nome do motoboy), `vendors.name`, `companies.name` e `companies.deleted_at`.
- `User::isAdmin()` (`type === 'admin'`) e o `OnboardController` (só o primeiro usuário é admin); no console, `currentUser.isAdmin`.
```

- [ ] **Passo 2: Linha na tabela do `CLAUDE.md` da raiz**

Troque
```markdown
| Financeiro: extrato e pagamento dos motoboys, faturas das lojas, painel | `api/app/Support/Entregas/Financeiro/CLAUDE.md` |
```
por
```markdown
| Financeiro: extrato e pagamento dos motoboys, faturas das lojas, painel | `api/app/Support/Entregas/Financeiro/CLAUDE.md` |
| Corridas do sistema: relatório do dono (todas as organizações) e preço por central | `api/app/Support/Entregas/Sistema/CLAUDE.md` |
```

- [ ] **Passo 3: `packages/fleetops/CLAUDE.md`**

1. Na linha das rotas do Financeiro, troque
```markdown
`fatura` (`/faturas/:id`, uuid), `relatorio`, `multiplicadores` e `configuracoes`.
```
por
```markdown
`fatura` (`/faturas/:id`, uuid), `relatorio`, `multiplicadores`, `configuracoes` e `corridas-do-sistema` (guarda própria, ver abaixo).
```

2. Na linha do menu, troque
```markdown
Painel, Motoboys, Lojas e faturas, Tabela de km e relatório, Multiplicadores, Configurações.
```
por
```markdown
Painel, Motoboys, Lojas e faturas, Tabela de km e relatório, Multiplicadores, Configurações e, por último, Corridas do sistema (`visible` próprio).
```

3. Logo depois do bullet `  - **Multiplicadores** (...)`, acrescente:
```markdown
  - **Corridas do sistema** (`financeiro.corridas-do-sistema`, decisão de 2026-10-22; último item do menu, ícone `building`): relatório do dono do sistema com as corridas finalizadas de **todas as organizações**, por central, com o preço por corrida. **Só o administrador do sistema** (`currentUser.isAdmin`), com `visible` e guarda de rota próprios, independentes do resto do Financeiro (que vai passar a ser o administrador da organização). Período (padrão este mês, atalhos este mês e mês passado), resumo com aviso "N corridas sem preço", por central (preço único ou "variado", selo "sem preço", total no rodapé), preços por central (modal `preco-da-central`, `keepOpen`; apagar com confirmação), lista das corridas (data e hora pela hora escrita no ISO, sem converter o fuso) e CSV (`;`, BOM `\uFEFF`, vírgula). Funções puras em `addon/utils/corridas-do-sistema.js` (teste `scripts/teste-portal/corridas-do-sistema.test.mjs`); traduções `fleet-ops.ui.financeiro.corridas-do-sistema.*`. Servidor: `api/app/Support/Entregas/Sistema/CLAUDE.md`.
```

- [ ] **Passo 4: `api/app/Support/Entregas/Financeiro/CLAUDE.md`**

Troque
```markdown
- **Só administradores** (`negarSeNaoAdmin` em todos os métodos do console: 403 "Somente administradores podem mexer no financeiro." / "… podem ver e mexer no financeiro." nas lojas). Sem papel "Financeiro" ou só leitura.
```
por
```markdown
- **Só administradores** (`negarSeNaoAdmin` em todos os métodos do console: 403 "Somente administradores podem mexer no financeiro." / "… podem ver e mexer no financeiro." nas lojas). Sem papel "Financeiro" ou só leitura.
  - **Pendência (2026-10-22):** o `negarSeNaoAdmin` usa `isNotAdmin()` (`type !== 'admin'`), que só o primeiro usuário da instalação passa. Quando as centrais entrarem como organizações novas, o administrador de cada uma não vai conseguir usar o Financeiro (nem o painel da distribuição) da própria organização: trocar pela regra do papel "Administrator" da organização. A tela "Corridas do sistema" fica de fora dessa troca (trava própria: `api/app/Support/Entregas/Sistema/CLAUDE.md`).
```

- [ ] **Passo 5: `deploy/zerar-pedidos`**

Em `deploy/zerar-pedidos/LEIAME.md`, troque
```markdown
corte, ciclo padrão). O Local de cada loja (coleta) fica.
```
por
```markdown
corte, ciclo padrão). O Local de cada loja (coleta) fica. As **corridas do sistema** (`entregas_corridas_finalizadas`, relatório do
dono) e os preços das centrais também ficam: a corrida finalizada nunca sai do relatório.
```

Em `deploy/zerar-pedidos/1-inventario.sql`, troque
```sql
UNION ALL SELECT 'entregas_motoboys_financeiro (fica)', COUNT(*), NULL FROM entregas_motoboys_financeiro;
```
por
```sql
UNION ALL SELECT 'entregas_motoboys_financeiro (fica)', COUNT(*), NULL FROM entregas_motoboys_financeiro
UNION ALL SELECT 'entregas_corridas_finalizadas (fica)', COUNT(*), NULL FROM entregas_corridas_finalizadas
UNION ALL SELECT 'entregas_precos_centrais (fica)', COUNT(*), NULL FROM entregas_precos_centrais;
```

- [ ] **Passo 6: `docs/historico.md`**

Depois do item `35.` (último), acrescente:
```markdown
36. Corridas do sistema (2026-10-22, ramo `corridas-do-sistema`; spec `docs/superpowers/specs/2026-10-22-corridas-do-sistema-design.md`): relatório do dono com as corridas finalizadas de todas as organizações (centrais), registro permanente que nunca perde a corrida (observador, conferência a cada 5 min e histórico importado na primeira rodada), preço fixo por corrida por central com vigência, tela em Financeiro → Corridas do sistema só para o administrador do sistema, com CSV (ver `api/app/Support/Entregas/Sistema/CLAUDE.md`).
```

- [ ] **Passo 7: Confira o fim de linha dos CRLF**

```bash
for f in CLAUDE.md packages/fleetops/CLAUDE.md docs/historico.md; do [ -f "$f" ] && node -e "const t=require('fs').readFileSync(process.argv[1],'utf8');console.log(process.argv[1], /(^|[^\r])\n/.test(t)?'LF solto':'só CRLF')" "$f"; done
```
Expected: `só CRLF` em cada arquivo existente.

- [ ] **Passo 8: Commit**

```bash
git rev-parse --show-toplevel   # esperado: C:/tmp/cs
git status --short              # confira que só estão os arquivos desta tarefa
git add api/app/Support/Entregas/Sistema/CLAUDE.md deploy/zerar-pedidos/LEIAME.md deploy/zerar-pedidos/1-inventario.sql
# só se a pré-condição foi atendida (arquivos da reorganização já no git):
git add CLAUDE.md packages/fleetops/CLAUDE.md api/app/Support/Entregas/Financeiro/CLAUDE.md docs/historico.md
git commit -m "$(cat <<'EOF'
docs: corridas do sistema (registro, preços, rotas, implantação) e a pendência do Financeiro por organização

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Tarefa 11: Bateria completa

- [ ] **Passo 1: Todos os testes php-wasm**

```bash
falhou=0; for t in scripts/teste-php/*.php; do case "$t" in */stubs*|*/fixtures-*) continue;; esac; saida=$(PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs "$t" 2>&1 | tail -1); [ "$saida" = "FALHAS: 0" ] || { echo "$t: $saida"; falhou=1; }; done; echo "falhou=$falhou"
```
Expected: `falhou=0` (nenhum arquivo listado). Se algum teste já falhava na `main` antes deste ramo, confira com `git stash`/checkout da `main` e anote; não corrija o que não é deste trabalho.

- [ ] **Passo 2: Sintaxe de todo PHP novo ou alterado**

```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs $(git diff --name-only main...HEAD -- 'api/*.php')
```
Expected: exit 0.

- [ ] **Passo 3: Front e tradução**

```bash
for t in scripts/teste-portal/*.test.mjs; do node --import ./scripts/teste-portal/resolver.mjs --test "$t" 2>&1 | grep -q '^# fail 0' || echo "FALHOU $t"; done; echo fim
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit $?"
```
Expected: só `fim` (nenhum `FALHOU`); `exit 0`.

- [ ] **Passo 4: Revisão**

Use `superpowers:requesting-code-review` sobre `git diff main...HEAD` e `superpowers:finishing-a-development-branch` para o merge na `main`. O push e o deploy são do Edgard.

---

## Implantação (quem roda é o Edgard)

1. **Push** da `main` (o Claude não tem acesso à VPS; o push não faz deploy).
2. **Na VPS:** `cd ~/entregas && bash deploy/atualizar.sh` (API, queue, scheduler e console; ~15 min do console). O `deploy.sh` roda a migration nas duas tabelas novas (banco principal e sandbox). Não há variável nova no stack: nada a mudar no Portainer.
3. **Histórico:** a primeira rodada do `entregas:corridas-conferir` (até 5 min depois do restart do scheduler) importa tudo o que já foi concluído. Para acompanhar:
   ```bash
   docker service logs --since 15m entregas_scheduler 2>&1 | grep '\[entregas\] sistema'
   ```
   Esperado: `[entregas] sistema: histórico das corridas importado {"corridas":N}`. Para não esperar (ou repetir com segurança):
   ```bash
   docker exec -it $(docker ps -q -f name=entregas_application) php artisan entregas:corridas-conferir --historico
   ```
4. **Conferir no banco** (opcional):
   ```bash
   DB=$(docker ps -q -f name=entregas_database)
   docker exec -i $DB mysql -uroot -p fleetbase -e "SET time_zone='-03:00'; SELECT origem, COUNT(*), MIN(finalizada_em), MAX(finalizada_em) FROM entregas_corridas_finalizadas GROUP BY origem;"
   ```
   (use o usuário e o nome do banco do `stack.env`).
5. **Conferir pelo console** (Ctrl+Shift+R depois do deploy do console; o `fleetbase.config.json` fica 1 h no localStorage):
   - logado como Edgard: Fleet-Ops → Financeiro → **Corridas do sistema** (último item). O total do mês deve bater com os pedidos concluídos do mês; a diferença esperada para o "Tabela de km e relatório" é o cancelado pago (lá entra, aqui não) e os pedidos concluídos sem motoboy (aqui entram);
   - cadastrar o preço da **Restaurante Pro** com a data a partir da qual quer cobrar (data antiga precifica o histórico) e ver o "a cobrar" mudar; o aviso "N corridas sem preço" some quando a data cobre o período;
   - baixar o CSV e abrir no Excel;
   - com um usuário que não é o administrador do sistema (ex.: um usuário de central de teste): o item não aparece e a URL `/fleet-ops/financeiro/corridas-do-sistema` volta para o Fleet-Ops com o aviso.
6. **Depois:** a cada central nova (organização nova na mesma instalação), cadastrar o preço dela. A **Terraço Pizza Bar é loja real**: não faça pedido de teste nela para conferir esta tela.
