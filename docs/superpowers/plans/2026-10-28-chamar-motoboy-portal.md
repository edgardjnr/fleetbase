# Chamar motoboy pelo portal da loja: plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Trocar o "Novo pedido" do portal da loja pelo painel "Chamar motoboy" em 3 passos (cliente pelo telefone,
endereço com o Google e o mapa, pagamento), com cobrança e observação chegando ao motoboy e à central.

**Architecture:** Uma rota nova da loja (`POST int/v1/entregas/loja/chamar-motoboy`) valida, acha ou cria o Local do
cliente, cria o pedido como a integração cria (coleta = Local da loja, 30 m, pré-pago, despacho com a trava → distribuição)
e grava cobrança, observação, telefone, complemento e referência numa tabela própria (`entregas_portal_pedidos`). A rota
`GET loja/clientes?telefone=` devolve o último endereço do telefone. O app, a comanda e o detalhe do portal leem a linha
nova pelos mesmos formatos de hoje, sem APK. O front é um componente novo na rota `portal.orders.new`.

**Tech Stack:** Laravel (api/app, PHP 8.2; testes php-wasm em `scripts/teste-php/`), Ember (packages/customer-portal;
testes `scripts/teste-portal/` com `node --test`). App (`entregas-navigator`) sem código novo.

**Spec:** `docs/superpowers/specs/2026-10-28-chamar-motoboy-portal-design.md`. Leia antes: `packages/customer-portal/CLAUDE.md`,
`api/app/Support/Entregas/Integracao/CLAUDE.md`, `api/app/Support/Entregas/Painel/CLAUDE.md` (comanda),
`api/app/Http/Controllers/Entregas/CLAUDE.md` (comanda do motoboy), `api/CLAUDE.md` e `packages/CLAUDE.md`.

**Como rodar** (na raiz do repo, no Git Bash; o php-wasm está em `C:\tmp\php-wasm`):

```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/<arquivo>.php      # termina com "FALHAS: 0"
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs <arquivos.php>                    # php -l do PHP 8.2
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/<arquivo>.test.mjs
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal
```

Parse dos JS alterados com o `@babel/parser` do console (regra de `packages/CLAUDE.md`):

```bash
node -e "const p=require(require('path').resolve('console/node_modules/.pnpm/@babel+parser@7.29.8/node_modules/@babel/parser'));for(const f of process.argv.slice(1)){p.parse(require('fs').readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties']});console.log('ok',f)}" <arquivos.js>
```

Antes de cada commit: `git rev-parse --show-toplevel` tem de ser `C:/Users/Edgardjr/Documents/vibe coding/Delivery`.
Não faça push nem deploy sem o Edgard (a Tarefa 15 é dele).

## Mapa dos arquivos

| Arquivo | Papel |
|---|---|
| `api/database/migrations/2026_10_28_150000_create_entregas_portal_pedidos_table.php` | tabela nova |
| `api/app/Support/Entregas/PedidoRecusado.php` | recusa com status HTTP e mensagem |
| `api/app/Support/Entregas/PrePago/CreditoNoPedido.php` | a trava e o crédito da loja pré-paga (saiu do `RegrasPortalLoja`) |
| `api/app/Support/Entregas/Portal/ChamadoDaLoja.php` | validação pura do corpo e do telefone |
| `api/app/Support/Entregas/Portal/PedidosDoPortal.php` | leitura da tabela, cliente do telefone, formato da integração, resumo |
| `api/app/Support/Entregas/Portal/CriadorDoChamado.php` | Local, pedido, linha e despacho |
| `api/app/Http/Controllers/Entregas/ChamarMotoboyController.php` | `clientes` e `chamar` |
| `api/app/Http/Middleware/RegrasPortalLoja.php` | passa a usar o `CreditoNoPedido` |
| `api/app/Providers/RouteServiceProvider.php` | as duas rotas |
| `api/app/Http/Controllers/Entregas/MotoboyController.php`, `PedidoAbertoController.php`, `PortalLojaController.php`, `api/app/Support/Entregas/Painel/ComandaDaCentral.php` | leem a linha do portal |
| `scripts/teste-php/stubs-ifood.php` | o esquema dos testes lê a migration nova |
| `scripts/teste-php/chamar-motoboy-validacao.php`, `chamar-motoboy.php` | testes novos; `prepago-loja.php` e `comanda-do-pedido.php` ganham casos |
| `packages/customer-portal/addon/utils/chamar-motoboy.js` + `scripts/teste-portal/chamar-motoboy.test.mjs` | funções puras do painel |
| `packages/customer-portal/addon/components/portal/chamar-motoboy.{js,hbs}` e `chamar-motoboy/ponto.{js,hbs}` (+ re-exports em `app/`) | o painel e o mapa |
| `packages/customer-portal/addon/routes/portal/orders/new.js`, `templates/portal/orders/new.hbs` | a rota mostra o painel |
| `packages/customer-portal/addon/styles/customer-portal-engine.css`, `translations/{pt-br,en-us}.yaml` | CSS `ps-cm-*` e textos `customer-portal.ui.entregas.chamar.*` |

---

## Parte A: servidor

### Tarefa 1: Tabela `entregas_portal_pedidos`

**Files:**
- Create: `api/database/migrations/2026_10_28_150000_create_entregas_portal_pedidos_table.php`
- Modify: `scripts/teste-php/stubs-ifood.php` (lista de migrations do esquema, perto da linha 569)
- Create: `scripts/teste-php/chamar-motoboy.php`

- [ ] **Step 1: Escrever o teste que falha**

Crie `scripts/teste-php/chamar-motoboy.php`:

```php
<?php

// Chamar motoboy pelo portal da loja: a tabela entregas_portal_pedidos, o CriadorDoChamado, o PedidosDoPortal, o
// ChamarMotoboyController e os leitores da linha (app do motoboy, comanda, detalhe do portal).
// Spec: docs/superpowers/specs/2026-10-28-chamar-motoboy-portal-design.md.
// Uso: PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/chamar-motoboy.php

require __DIR__ . '/stubs-integracao.php';

use Teste\Banco;

$raiz = dirname(__DIR__, 2);

echo '== Tabela entregas_portal_pedidos' . PHP_EOL;
$esquema = Banco::esquema()['entregas_portal_pedidos'] ?? null;
confere($esquema !== null, 'a migration cria a tabela');
foreach (['id', 'company_uuid', 'vendor_uuid', 'order_uuid', 'place_uuid', 'chave', 'cliente_nome', 'cliente_telefone', 'complemento', 'referencia', 'observacoes', 'cobranca_forma', 'cobranca_centavos', 'troco_para_centavos', 'created_at', 'updated_at'] as $coluna) {
    confere(in_array($coluna, $esquema['colunas'] ?? [], true), "coluna {$coluna}");
}
confere(in_array('order_uuid', $esquema['unicas'] ?? [], true), 'um pedido, uma linha (order_uuid único)');
confere(in_array(['vendor_uuid', 'chave'], $esquema['compostas'] ?? [], true), 'a chave do chamado é única por loja');
confere(($esquema['padroes']['cobranca_centavos'] ?? null) === 0, 'cobranca_centavos começa em 0');
$migration = file_get_contents($raiz . '/api/database/migrations/2026_10_28_150000_create_entregas_portal_pedidos_table.php');
confere(str_contains($migration, "->charset('ascii')->collation('ascii_bin')"), 'chave em ascii_bin');
confere(str_contains($migration, "\$table->index(['vendor_uuid', 'cliente_telefone'], 'entregas_portal_ped_tel_index');"), 'índice da busca pelo telefone');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/chamar-motoboy.php`
Expected: FALHA em "a migration cria a tabela" (e erro de leitura do arquivo da migration).

- [ ] **Step 3: Criar a migration**

`api/database/migrations/2026_10_28_150000_create_entregas_portal_pedidos_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: o que a loja informa ao chamar motoboy pelo portal e que o Fleetbase não guarda (telefone do
 * cliente, cobrança na entrega, observação para o motoboy, complemento e referência), uma linha por pedido. Lida pelo
 * App\Support\Entregas\Portal\PedidosDoPortal. Spec: docs/superpowers/specs/2026-10-28-chamar-motoboy-portal-design.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_portal_pedidos', function (Blueprint $table) {
            $table->id();
            $table->char('company_uuid', 36)->index();
            $table->char('vendor_uuid', 36);
            $table->char('order_uuid', 36)->unique();
            // o Local de entrega (dono = a loja), reaproveitado no próximo pedido do mesmo cliente no mesmo ponto
            $table->char('place_uuid', 36)->nullable();
            // uuid gerado pelo portal ao abrir o painel: a repetição (rede caiu) devolve o mesmo pedido
            $table->char('chave', 36)->charset('ascii')->collation('ascii_bin');
            $table->string('cliente_nome', 120);
            // só dígitos, com DDD (ChamadoDaLoja::telefone)
            $table->string('cliente_telefone', 20);
            $table->string('complemento', 190)->nullable();
            $table->string('referencia', 190)->nullable();
            $table->text('observacoes')->nullable();
            // pago | dinheiro | cartao | pix; valor e troco em centavos (como a entregas_integracao_pedidos)
            $table->string('cobranca_forma', 20);
            $table->unsignedInteger('cobranca_centavos')->default(0);
            $table->unsignedInteger('troco_para_centavos')->nullable();
            $table->timestamps();
            $table->unique(['vendor_uuid', 'chave'], 'entregas_portal_ped_chave_unique');
            $table->index(['vendor_uuid', 'cliente_telefone'], 'entregas_portal_ped_tel_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_portal_pedidos');
    }
};
```

- [ ] **Step 4: O esquema dos testes lê a migration nova**

Em `scripts/teste-php/stubs-ifood.php`, logo depois da linha

```php
                glob(dirname(__DIR__, 2) . '/api/database/migrations/*_entregas_integracao_*_table.php') ?: [],
```

acrescente:

```php
                // a do chamar motoboy pelo portal da loja (entregas_portal_pedidos)
                glob(dirname(__DIR__, 2) . '/api/database/migrations/*_entregas_portal_*_table.php') ?: [],
```

- [ ] **Step 5: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/chamar-motoboy.php`
Expected: todos PASSA, "FALHAS: 0". Rode também `integracao-criador.php` e `comanda-do-pedido.php` (o esquema mudou): "FALHAS: 0".

- [ ] **Step 6: Sintaxe e commit**

```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/database/migrations/2026_10_28_150000_create_entregas_portal_pedidos_table.php
git add api/database/migrations/2026_10_28_150000_create_entregas_portal_pedidos_table.php scripts/teste-php/stubs-ifood.php scripts/teste-php/chamar-motoboy.php
git commit -m "Chamar motoboy: tabela entregas_portal_pedidos

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Tarefa 2: `PedidoRecusado` e `CreditoNoPedido` (saem do `RegrasPortalLoja`)

**Files:**
- Create: `api/app/Support/Entregas/PedidoRecusado.php`
- Create: `api/app/Support/Entregas/PrePago/CreditoNoPedido.php`
- Modify: `api/app/Http/Middleware/RegrasPortalLoja.php` (imports, constantes da trava, `criarComCredito`)
- Modify: `scripts/teste-php/prepago-loja.php` (casos novos depois do caso "trava ocupada: 409")

- [ ] **Step 1: Escrever o teste que falha**

Em `scripts/teste-php/prepago-loja.php`, logo depois da linha

```php
    confere($r->status === 409 && $criados === 2, 'outro pedido da loja sendo criado (trava ocupada): 409 sem criar');
```

acrescente:

```php
    echo '== CreditoNoPedido (o mesmo do chamar motoboy)' . PHP_EOL;
    unset(\Teste\Trava::$ocupadas['entregas:carteira:vendor-a']);
    confere(\App\Support\Entregas\PrePago\CreditoNoPedido::criar('empresa-1', 'vendor-a', $criar) === 'criado' && $criados === 3, 'pré-paga com crédito: cria');
    \Teste\Trava::$ocupadas['entregas:carteira:vendor-a'] = true;
    $recusa = null;
    try {
        \App\Support\Entregas\PrePago\CreditoNoPedido::criar('empresa-1', 'vendor-a', $criar);
    } catch (\App\Support\Entregas\PedidoRecusado $e) {
        $recusa = $e;
    }
    confere($recusa !== null && $recusa->status === 409 && str_contains($recusa->getMessage(), 'Outro pedido da loja') && $criados === 3, 'trava ocupada: PedidoRecusado 409, sem criar');
    unset(\Teste\Trava::$ocupadas['entregas:carteira:vendor-a']);
    confere(\App\Support\Entregas\PrePago\CreditoNoPedido::criar('empresa-1', 'vendor-b', $criar) === 'criado' && $criados === 4, 'pós-paga: cria direto');
    confere(\App\Http\Middleware\RegrasPortalLoja::VALIDADE_DA_TRAVA_DA_CARTEIRA === \App\Support\Entregas\PrePago\CreditoNoPedido::VALIDADE_DA_TRAVA, 'a validade da trava é uma só');
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/prepago-loja.php`
Expected: erro fatal "Class App\Support\Entregas\PrePago\CreditoNoPedido not found".

- [ ] **Step 3: Criar as duas classes**

`api/app/Support/Entregas/PedidoRecusado.php`:

```php
<?php

namespace App\Support\Entregas;

/**
 * Entregas RestaurantePro: pedido recusado antes de nascer, com o status HTTP e a mensagem para a tela (sem crédito,
 * trava da carteira ocupada, ponto da entrega na loja). Quem responde à requisição transforma em {"errors": [mensagem]}.
 */
class PedidoRecusado extends \RuntimeException
{
    public function __construct(public readonly int $status, string $mensagem)
    {
        parent::__construct($mensagem);
    }
}
```

`api/app/Support/Entregas/PrePago/CreditoNoPedido.php`:

```php
<?php

namespace App\Support\Entregas\PrePago;

use App\Support\Entregas\PedidoRecusado;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Entregas RestaurantePro: o pedido novo da loja pré-paga só sai com crédito para pelo menos a entrega mais barata
 * (CarteiraDaLoja). Confere e cria com a trava da carteira da loja: o pedido criado já reserva crédito, então dois pedidos
 * seguidos não passam com o crédito de um. Loja pós-paga: cria direto. Usado pelo RegrasPortalLoja (POST orders do
 * portal) e pelo ChamarMotoboyController.
 */
final class CreditoNoPedido
{
    /** Trava da carteira (Redis): validade e espera, em segundos. */
    public const VALIDADE_DA_TRAVA = 30;
    public const ESPERA_DA_TRAVA   = 10;

    /**
     * @return mixed o que $criar devolver
     *
     * @throws PedidoRecusado 422 sem crédito; 409 com outro pedido da loja sendo criado
     */
    public static function criar(string $empresa, string $lojaUuid, Closure $criar)
    {
        if (!PrePago::ehPrePaga($empresa, $lojaUuid)) {
            return $criar();
        }

        try {
            return Cache::lock('entregas:carteira:' . $lojaUuid, static::VALIDADE_DA_TRAVA)->block(static::ESPERA_DA_TRAVA, function () use ($empresa, $lojaUuid, $criar) {
                if ($semCredito = CarteiraDaLoja::motivoDoBloqueio($empresa, $lojaUuid)) {
                    throw new PedidoRecusado(422, $semCredito);
                }

                return $criar();
            });
        } catch (LockTimeoutException $e) {
            throw new PedidoRecusado(409, 'Outro pedido da loja está sendo criado. Tente de novo em alguns segundos.');
        }
    }
}
```

- [ ] **Step 4: O `RegrasPortalLoja` passa a usar o `CreditoNoPedido`**

Em `api/app/Http/Middleware/RegrasPortalLoja.php`:

1. Nos `use`, troque

```php
use App\Support\Entregas\LojaDoUsuario;
use App\Support\Entregas\PrePago\CarteiraDaLoja;
use App\Support\Entregas\PrePago\PrePago;
use App\Support\Entregas\SituacaoDoMotoboy;
```

por

```php
use App\Support\Entregas\LojaDoUsuario;
use App\Support\Entregas\PedidoRecusado;
use App\Support\Entregas\PrePago\CreditoNoPedido;
use App\Support\Entregas\SituacaoDoMotoboy;
```

e apague a linha `use Illuminate\Support\Facades\Cache;` (a única chamada era a da trava). O `LockTimeoutException`
fica (o cancelamento usa).

2. Troque

```php
    /** Trava da carteira da loja pré-paga no pedido novo (Redis, como a TravaDoPedido): validade e espera, em segundos. */
    public const VALIDADE_DA_TRAVA_DA_CARTEIRA = 30;
    public const ESPERA_DA_TRAVA_DA_CARTEIRA   = 10;
```

por

```php
    /** Trava da carteira da loja pré-paga no pedido novo: a do CreditoNoPedido. */
    public const VALIDADE_DA_TRAVA_DA_CARTEIRA = CreditoNoPedido::VALIDADE_DA_TRAVA;
    public const ESPERA_DA_TRAVA_DA_CARTEIRA   = CreditoNoPedido::ESPERA_DA_TRAVA;
```

3. Troque o método `criarComCredito` inteiro (docblock incluído) por:

```php
    /** Loja pré-paga: o pedido só sai com crédito (CreditoNoPedido, a mesma regra do chamar motoboy); pós-paga: cria direto. */
    protected function criarComCredito(string $empresa, string $lojaUuid, Closure $criar)
    {
        try {
            return CreditoNoPedido::criar($empresa, $lojaUuid, $criar);
        } catch (PedidoRecusado $e) {
            return $this->erro($e->status, $e->getMessage());
        }
    }
```

- [ ] **Step 5: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/prepago-loja.php`
Expected: "FALHAS: 0" (os casos antigos do `RegrasPortalLoja` seguem passando: 422 com a mensagem, 409, trava de 30 s).
Rode também `portal-cancelamento.php`: "FALHAS: 0".

- [ ] **Step 6: Sintaxe e commit**

```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/PedidoRecusado.php api/app/Support/Entregas/PrePago/CreditoNoPedido.php api/app/Http/Middleware/RegrasPortalLoja.php
git add api/app/Support/Entregas/PedidoRecusado.php api/app/Support/Entregas/PrePago/CreditoNoPedido.php api/app/Http/Middleware/RegrasPortalLoja.php scripts/teste-php/prepago-loja.php
git commit -m "Loja pré-paga: crédito do pedido novo no CreditoNoPedido (portal e chamar motoboy)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Tarefa 3: Validação do chamado (`ChamadoDaLoja`)

**Files:**
- Create: `api/app/Support/Entregas/Portal/ChamadoDaLoja.php`
- Create: `scripts/teste-php/chamar-motoboy-validacao.php`

- [ ] **Step 1: Escrever o teste que falha**

`scripts/teste-php/chamar-motoboy-validacao.php`:

```php
<?php

// Chamar motoboy: validação e normalização do corpo do POST loja/chamar-motoboy e do telefone (ChamadoDaLoja, função pura).
// Uso: PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/chamar-motoboy-validacao.php

require __DIR__ . '/stubs-integracao.php';

use App\Support\Entregas\Portal\ChamadoDaLoja;

function corpoDoChamado(array $mudancas = []): array
{
    return array_replace_recursive([
        'chave'      => '6F1C0E8A-2B1D-4C3E-9A7F-0D5B8C2E4F10',
        'cliente'    => ['nome' => '  Mariana  Souza ', 'telefone' => '+55 (16) 99999-0000'],
        'entrega'    => [
            'rua' => 'Rua Olinda, 45', 'bairro' => 'Centro', 'cidade' => 'Ribeirão Preto', 'uf' => 'sp', 'cep' => '14010000',
            'latitude' => -21.18, 'longitude' => -47.81, 'complemento' => 'Ap 12', 'referencia' => 'Perto da praça',
        ],
        'cobranca'   => ['forma' => 'dinheiro', 'valor' => '58,90', 'troco_para' => '100'],
        'observacao' => "Tocar o interfone\r\nBolo frágil",
    ], $mudancas);
}

function tem(array $r, string $trecho): bool
{
    foreach ($r['erros'] as $erro) {
        if (str_contains($erro, $trecho)) {
            return true;
        }
    }

    return false;
}

echo '== Corpo completo' . PHP_EOL;
$r = ChamadoDaLoja::validar(corpoDoChamado());
$d = $r['dados'];
confere($r['erros'] === [] && $d !== null, 'sem erros');
confere($d['chave'] === '6f1c0e8a-2b1d-4c3e-9a7f-0d5b8c2e4f10', 'chave em minúsculas');
confere($d['cliente_nome'] === 'Mariana Souza' && $d['cliente_telefone'] === '16999990000', 'nome sem espaços sobrando; telefone sem o 55');
confere($d['rua'] === 'Rua Olinda, 45' && $d['bairro'] === 'Centro' && $d['cidade'] === 'Ribeirão Preto' && $d['uf'] === 'SP' && $d['cep'] === '14010-000', 'endereço, UF e CEP');
confere($d['latitude'] === -21.18 && $d['longitude'] === -47.81, 'ponto');
confere($d['complemento'] === 'Ap 12' && $d['referencia'] === 'Perto da praça', 'complemento e referência');
confere($d['cobranca_forma'] === 'dinheiro' && $d['cobranca_centavos'] === 5890 && $d['troco_para_centavos'] === 10000, 'cobrança em centavos');
confere($d['observacoes'] === "Tocar o interfone\nBolo frágil", 'observação com a quebra de linha');

echo '== Telefone' . PHP_EOL;
confere(ChamadoDaLoja::telefone('(16) 99999-0000') === '16999990000', 'celular com DDD');
confere(ChamadoDaLoja::telefone('16 3333-0000') === '1633330000', 'fixo com DDD');
confere(ChamadoDaLoja::telefone('016 99999-0000') === '16999990000', 'zero da frente sai');
confere(ChamadoDaLoja::telefone('5516999990000') === '16999990000', '55 da frente sai');
confere(ChamadoDaLoja::telefone('55999990000') === '55999990000', 'DDD 55 (RS) fica');
confere(ChamadoDaLoja::telefone('99999-0000') === null && ChamadoDaLoja::telefone('') === null && ChamadoDaLoja::telefone(null) === null && ChamadoDaLoja::telefone(['x']) === null, 'sem DDD, vazio ou não texto: null');
confere(ChamadoDaLoja::telefone('0999990000') === null, 'DDD não começa com 0');

echo '== Cobrança' . PHP_EOL;
$d = ChamadoDaLoja::validar(corpoDoChamado(['cobranca' => ['forma' => 'pago', 'valor' => '10']]))['dados'];
confere($d['cobranca_forma'] === 'pago' && $d['cobranca_centavos'] === 0 && $d['troco_para_centavos'] === null, 'já pago: nada a cobrar');
$d = ChamadoDaLoja::validar(corpoDoChamado(['cobranca' => ['forma' => 'PIX', 'valor' => 'R$ 1.234,56', 'troco_para' => '2000']]))['dados'];
confere($d['cobranca_forma'] === 'pix' && $d['cobranca_centavos'] === 123456 && $d['troco_para_centavos'] === null, 'pix com milhar; troco só em dinheiro (ignorado)');
$d = ChamadoDaLoja::validar(corpoDoChamado(['cobranca' => ['troco_para' => '58,90']]))['dados'];
confere($d['troco_para_centavos'] === null, 'troco igual ao valor = sem troco');
$d = ChamadoDaLoja::validar(corpoDoChamado(['cobranca' => ['troco_para' => '']]))['dados'];
confere($d['troco_para_centavos'] === null, 'troco vazio = sem troco');
confere(tem(ChamadoDaLoja::validar(corpoDoChamado(['cobranca' => ['troco_para' => '50']])), 'troco precisa ser para um valor maior'), 'troco menor que o valor: erro');
confere(tem(ChamadoDaLoja::validar(corpoDoChamado(['cobranca' => ['valor' => '0']])), 'Informe o valor a cobrar'), 'valor zero: erro');
confere(tem(ChamadoDaLoja::validar(corpoDoChamado(['cobranca' => ['valor' => '200000']])), 'Informe o valor a cobrar'), 'valor acima de R$ 100 mil: erro');
confere(tem(ChamadoDaLoja::validar(corpoDoChamado(['cobranca' => ['forma' => 'boleto']])), 'já pagou ou como'), 'forma desconhecida: erro');

echo '== Obrigatórios e limites' . PHP_EOL;
$r = ChamadoDaLoja::validar(['cliente' => [], 'entrega' => [], 'cobranca' => []]);
confere($r['dados'] === null && count($r['erros']) === 6, 'corpo vazio: os seis erros de uma vez');
confere(tem($r, 'Atualize a página') && tem($r, 'nome do cliente') && tem($r, 'telefone do cliente com DDD') && tem($r, 'rua e o número') && tem($r, 'Marque no mapa') && tem($r, 'já pagou'), 'mensagens para a loja');
confere(tem(ChamadoDaLoja::validar(corpoDoChamado(['entrega' => ['latitude' => 0, 'longitude' => 0]])), 'Marque no mapa'), 'ponto 0,0: erro');
confere(tem(ChamadoDaLoja::validar(corpoDoChamado(['entrega' => ['latitude' => 'x']])), 'Marque no mapa'), 'ponto não numérico: erro');
$d = ChamadoDaLoja::validar(corpoDoChamado(['cliente' => ['nome' => str_repeat('a', 200)], 'entrega' => ['uf' => 'São Paulo', 'cep' => '140', 'complemento' => '', 'referencia' => null], 'observacao' => null]))['dados'];
confere(mb_strlen($d['cliente_nome']) === 120, 'nome longo é cortado em 120 (sem 422)');
confere($d['uf'] === null && $d['cep'] === null && $d['complemento'] === null && $d['referencia'] === null && $d['observacoes'] === null, 'UF e CEP inválidos e opcionais vazios: null');
$d = ChamadoDaLoja::validar(corpoDoChamado(['cliente' => ['nome' => "Ana\u{00A0}\u{0007}Paula"]]))['dados'];
confere($d['cliente_nome'] === 'Ana Paula', 'espaço Unicode e controle saem do nome');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/chamar-motoboy-validacao.php`
Expected: erro fatal "Class App\Support\Entregas\Portal\ChamadoDaLoja not found".

- [ ] **Step 3: Implementar**

`api/app/Support/Entregas/Portal/ChamadoDaLoja.php`:

```php
<?php

namespace App\Support\Entregas\Portal;

use App\Support\Entregas\Coordenada;

/**
 * Entregas RestaurantePro: confere e normaliza o corpo do POST int/v1/entregas/loja/chamar-motoboy (o painel "Chamar
 * motoboy" do portal da loja). Função pura. Devolve ['erros' => [...], 'dados' => [...]|null], todos os erros de uma vez,
 * com mensagens para a loja (sem nome de campo: o front já confere antes, e o que chega aqui aparece no aviso de erro).
 * Texto longo é cortado (o front limita), UF e CEP inválidos (vêm do Google) viram null. O telefone segue a mesma regra
 * do front (packages/customer-portal/addon/utils/chamar-motoboy.js, digitosDoTelefone): mudou aqui, mude lá.
 * Spec: docs/superpowers/specs/2026-10-28-chamar-motoboy-portal-design.md.
 */
final class ChamadoDaLoja
{
    public const FORMAS = ['pago', 'dinheiro', 'cartao', 'pix'];

    /** R$ 100 mil, em centavos (a regra da integração). */
    public const VALOR_MAXIMO_CENTAVOS = 10000000;

    public const LIMITES = ['nome' => 120, 'rua' => 190, 'bairro' => 120, 'cidade' => 120, 'complemento' => 190, 'referencia' => 190, 'observacao' => 500];

    public const PADRAO_DA_CHAVE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** @return array{erros: string[], dados: ?array} */
    public static function validar(array $corpo): array
    {
        $erros    = [];
        $cliente  = is_array($corpo['cliente'] ?? null) ? $corpo['cliente'] : [];
        $entrega  = is_array($corpo['entrega'] ?? null) ? $corpo['entrega'] : [];
        $cobranca = is_array($corpo['cobranca'] ?? null) ? $corpo['cobranca'] : [];

        $chave = is_string($corpo['chave'] ?? null) ? strtolower(trim($corpo['chave'])) : '';
        if (!preg_match(static::PADRAO_DA_CHAVE, $chave)) {
            $erros[] = 'Atualize a página e tente de novo.';
        }

        $nome = static::texto($cliente['nome'] ?? null, static::LIMITES['nome']);
        if ($nome === null) {
            $erros[] = 'Informe o nome do cliente.';
        }
        $telefone = static::telefone($cliente['telefone'] ?? null);
        if ($telefone === null) {
            $erros[] = 'Informe o telefone do cliente com DDD.';
        }
        $rua = static::texto($entrega['rua'] ?? null, static::LIMITES['rua']);
        if ($rua === null) {
            $erros[] = 'Informe a rua e o número da entrega.';
        }
        $latitude  = $entrega['latitude'] ?? null;
        $longitude = $entrega['longitude'] ?? null;
        $temPonto  = is_numeric($latitude) && is_numeric($longitude) && Coordenada::valida((float) $latitude, (float) $longitude);
        if (!$temPonto) {
            $erros[] = 'Marque no mapa o local da entrega.';
        }
        [$forma, $centavos, $troco] = static::cobranca($cobranca, $erros);

        if ($erros) {
            return ['erros' => $erros, 'dados' => null];
        }

        return ['erros' => [], 'dados' => [
            'chave'               => $chave,
            'cliente_nome'        => $nome,
            'cliente_telefone'    => $telefone,
            'rua'                 => $rua,
            'bairro'              => static::texto($entrega['bairro'] ?? null, static::LIMITES['bairro']),
            'cidade'              => static::texto($entrega['cidade'] ?? null, static::LIMITES['cidade']),
            'uf'                  => static::uf($entrega['uf'] ?? null),
            'cep'                 => static::cep($entrega['cep'] ?? null),
            'latitude'            => (float) $latitude,
            'longitude'           => (float) $longitude,
            'complemento'         => static::texto($entrega['complemento'] ?? null, static::LIMITES['complemento']),
            'referencia'          => static::texto($entrega['referencia'] ?? null, static::LIMITES['referencia']),
            'cobranca_forma'      => $forma,
            'cobranca_centavos'   => $centavos,
            'troco_para_centavos' => $troco,
            'observacoes'         => static::texto($corpo['observacao'] ?? null, static::LIMITES['observacao'], true),
        ]];
    }

    /**
     * Só os dígitos, com DDD: tira o 55 da frente (12 ou 13 dígitos) e depois o 0 do interurbano (11 ou 12 dígitos).
     * Vale com 10 (fixo) ou 11 (celular) dígitos e o DDD sem 0 na frente; senão null.
     */
    public static function telefone($valor): ?string
    {
        if (is_int($valor)) {
            $valor = (string) $valor;
        }
        if (!is_string($valor)) {
            return null;
        }
        $digitos = preg_replace('/\D+/', '', $valor) ?? '';
        if (strlen($digitos) >= 12 && str_starts_with($digitos, '55')) {
            $digitos = substr($digitos, 2);
        }
        if ((strlen($digitos) === 11 || strlen($digitos) === 12) && str_starts_with($digitos, '0')) {
            $digitos = substr($digitos, 1);
        }

        return (strlen($digitos) === 10 || strlen($digitos) === 11) && $digitos[0] !== '0' ? $digitos : null;
    }

    /** Valor em reais vindo do campo ("58,90", "1.234,56", "R$ 10", 58.9) em centavos; null se não for valor. */
    public static function centavos($valor): ?int
    {
        if (is_string($valor)) {
            $valor = trim(str_replace(['R$', ' ', "\u{00A0}"], '', $valor));
            if (str_contains($valor, ',')) {
                $valor = str_replace(['.', ','], ['', '.'], $valor);
            }
        }
        if (!is_numeric($valor)) {
            return null;
        }
        $reais = (float) $valor;
        if (!is_finite($reais) || $reais < 0 || $reais > static::VALOR_MAXIMO_CENTAVOS / 100) {
            return null;
        }

        return (int) round($reais * 100);
    }

    /**
     * Texto limpo: sem controles nem espaço Unicode sobrando (uma linha) ou com as quebras de linha mantidas
     * ($variasLinhas), cortado no limite; vazio = null.
     */
    public static function texto($valor, int $maximo, bool $variasLinhas = false): ?string
    {
        if (is_int($valor) || is_float($valor)) {
            $valor = (string) $valor;
        }
        if (!is_string($valor) || !mb_check_encoding($valor, 'UTF-8')) {
            return null;
        }
        $limpo = $variasLinhas
            ? preg_replace(['/\r\n?/u', '/\t/u', '/[^\P{Cc}\n]/u', '/[ \x{00A0}]{2,}/u', '/\n{3,}/u'], ["\n", ' ', '', ' ', "\n\n"], $valor)
            : preg_replace(['/\p{Cc}+/u', '/[\s\p{Z}]+/u'], [' ', ' '], $valor);
        $limpo = $limpo === null ? '' : trim($limpo);

        return $limpo === '' ? null : mb_substr($limpo, 0, $maximo);
    }

    protected static function uf($valor): ?string
    {
        return is_string($valor) && preg_match('/^\s*([A-Za-z]{2})\s*$/', $valor, $m) ? strtoupper($m[1]) : null;
    }

    protected static function cep($valor): ?string
    {
        $digitos = is_string($valor) || is_int($valor) ? preg_replace('/\D+/', '', (string) $valor) : '';

        return strlen((string) $digitos) === 8 ? substr($digitos, 0, 5) . '-' . substr($digitos, 5) : null;
    }

    /** @return array{0: ?string, 1: int, 2: ?int} forma, centavos a cobrar e troco para (centavos) */
    protected static function cobranca(array $cobranca, array &$erros): array
    {
        $forma = is_string($cobranca['forma'] ?? null) ? strtolower(trim($cobranca['forma'])) : null;
        if (!in_array($forma, static::FORMAS, true)) {
            $erros[] = 'Diga se o cliente já pagou ou como ele vai pagar.';

            return [null, 0, null];
        }
        if ($forma === 'pago') {
            return ['pago', 0, null];
        }

        $centavos = static::centavos($cobranca['valor'] ?? null);
        if ($centavos === null || $centavos <= 0) {
            $erros[] = 'Informe o valor a cobrar na entrega.';

            return [$forma, 0, null];
        }

        $bruto = $cobranca['troco_para'] ?? null;
        if ($forma !== 'dinheiro' || $bruto === null || $bruto === '') {
            return [$forma, $centavos, null];
        }
        $troco = static::centavos($bruto);
        if ($troco === null) {
            $erros[] = 'Confira o valor do troco.';

            return [$forma, $centavos, null];
        }
        if ($troco === 0 || $troco === $centavos) {
            return [$forma, $centavos, null];
        }
        if ($troco < $centavos) {
            $erros[] = 'O troco precisa ser para um valor maior que o da cobrança.';

            return [$forma, $centavos, null];
        }

        return [$forma, $centavos, $troco];
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/chamar-motoboy-validacao.php`
Expected: "FALHAS: 0".

- [ ] **Step 5: Sintaxe e commit**

```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/Portal/ChamadoDaLoja.php
git add api/app/Support/Entregas/Portal/ChamadoDaLoja.php scripts/teste-php/chamar-motoboy-validacao.php
git commit -m "Chamar motoboy: validação do chamado da loja (ChamadoDaLoja)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Tarefa 4: Leitura da tabela (`PedidosDoPortal`)

**Files:**
- Create: `api/app/Support/Entregas/Portal/PedidosDoPortal.php`
- Modify: `scripts/teste-php/chamar-motoboy.php` (casos antes do `resumo();`)

- [ ] **Step 1: Escrever o teste que falha**

Em `scripts/teste-php/chamar-motoboy.php`, troque `use Teste\Banco;` por:

```php
use App\Support\Entregas\Integracao\CobrancaDaIntegracao;
use App\Support\Entregas\Integracao\DadosDaIntegracaoDoMotoboy;
use App\Support\Entregas\Portal\PedidosDoPortal;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Teste\Banco;

/** Uma linha da entregas_portal_pedidos (dados fictícios), com $campos por cima. */
function linhaDoPortal(array $campos = []): void
{
    Banco::inserir('entregas_portal_pedidos', $campos + [
        'company_uuid' => 'empresa-1', 'vendor_uuid' => 'vendor-a', 'order_uuid' => 'order-1', 'place_uuid' => 'place-cliente',
        'chave' => '6f1c0e8a-2b1d-4c3e-9a7f-0d5b8c2e4f10', 'cliente_nome' => 'Mariana Souza', 'cliente_telefone' => '16999990000',
        'complemento' => 'Ap 12', 'referencia' => 'Perto da praça', 'observacoes' => 'Tocar o interfone',
        'cobranca_forma' => 'dinheiro', 'cobranca_centavos' => 5890, 'troco_para_centavos' => 10000,
        'created_at' => '2026-10-05 14:00:00', 'updated_at' => '2026-10-05 14:00:00',
    ], false);
}

/** O Local do cliente (dono = a loja A). */
function localDoCliente(array $campos = []): Place
{
    $lugar = new Place($campos + [
        'uuid' => 'place-cliente', 'company_uuid' => 'empresa-1', 'owner_uuid' => 'vendor-a', 'owner_type' => 'fleet-ops:vendor',
        'name' => 'MARIANA SOUZA', 'street1' => 'RUA OLINDA, 45', 'street2' => 'AP 12', 'neighborhood' => 'CENTRO',
        'city' => 'RIBEIRÃO PRETO', 'province' => 'SP', 'postal_code' => '14010-000', 'location' => new Point(-21.18, -47.81),
    ]);
    Place::$todos[] = $lugar;

    return $lugar;
}
```

E, antes do `resumo();` final, acrescente:

```php
echo '== PedidosDoPortal: linha do pedido e da chave' . PHP_EOL;
reiniciarIntegracao();
linhaDoPortal();
confere(PedidosDoPortal::doPedido('order-1')?->cliente_telefone === '16999990000', 'doPedido acha a linha');
confere(PedidosDoPortal::doPedido('order-2') === null && PedidosDoPortal::doPedido(null) === null && PedidosDoPortal::doPedido('') === null, 'sem linha: null');
confere(PedidosDoPortal::daChave('vendor-a', '6f1c0e8a-2b1d-4c3e-9a7f-0d5b8c2e4f10')?->order_uuid === 'order-1', 'daChave da loja');
confere(PedidosDoPortal::daChave('vendor-b', '6f1c0e8a-2b1d-4c3e-9a7f-0d5b8c2e4f10') === null, 'a chave de outra loja não serve');
$fonte = file_get_contents($raiz . '/api/app/Support/Entregas/Portal/PedidosDoPortal.php');
confere(str_contains($fonte, 'catch (QueryException $e)') && str_contains($fonte, 'pedidos do portal ilegíveis no banco'), 'doPedido engole o erro de banco (tabela ainda sem migration) com warning');

echo '== PedidosDoPortal: cliente do telefone' . PHP_EOL;
reiniciarIntegracao();
localDoCliente();
localDoCliente(['uuid' => 'place-antigo', 'street1' => 'RUA ANTIGA, 1']);
linhaDoPortal(['order_uuid' => 'order-0', 'place_uuid' => 'place-antigo', 'chave' => '00000000-0000-4000-8000-000000000000', 'cliente_nome' => 'Mari', 'complemento' => null]);
linhaDoPortal();
linhaDoPortal(['vendor_uuid' => 'vendor-b', 'order_uuid' => 'order-b', 'chave' => '11111111-1111-4111-8111-111111111111', 'cliente_nome' => 'De outra loja']);
$c = PedidosDoPortal::clienteDoTelefone('empresa-1', 'vendor-a', '16999990000');
confere($c['nome'] === 'Mariana Souza' && $c['telefone'] === '16999990000' && $c['pedidos'] === 2, 'nome do último pedido e a contagem só da loja');
confere($c['endereco'] === ['rua' => 'RUA OLINDA, 45', 'bairro' => 'CENTRO', 'cidade' => 'RIBEIRÃO PRETO', 'uf' => 'SP', 'cep' => '14010-000', 'complemento' => 'Ap 12', 'referencia' => 'Perto da praça', 'latitude' => -21.18, 'longitude' => -47.81], 'último endereço: o Local + complemento e referência da linha');
confere(PedidosDoPortal::clienteDoTelefone('empresa-1', 'vendor-a', '16988887777') === null, 'telefone sem pedido: null');
confere(PedidosDoPortal::clienteDoTelefone('empresa-1', 'vendor-c', '16999990000') === null, 'loja sem o telefone: null (não vê o de outra loja)');
Place::$todos = array_values(array_filter(Place::$todos, fn ($p) => $p->uuid !== 'place-cliente'));
confere(PedidosDoPortal::clienteDoTelefone('empresa-1', 'vendor-a', '16999990000')['endereco'] === null, 'Local apagado: só o nome');

echo '== PedidosDoPortal: no formato da integração (app do motoboy)' . PHP_EOL;
reiniciarIntegracao();
linhaDoPortal();
$linha  = PedidosDoPortal::comoLinhaDaIntegracao(PedidosDoPortal::doPedido('order-1'));
$pedido = new Order(['uuid' => 'order-1', 'public_id' => 'order_1', 'status' => 'driver_enroute', 'driver_assigned_uuid' => 'driver-1']);
$r      = DadosDaIntegracaoDoMotoboy::resposta($linha, $pedido, 'driver-1');
confere($r['integracao'] === true && $r['sistema'] === 'Portal' && $r['numero'] === null, 'integracao true, selo "Portal", sem número da origem');
confere($r['cobranca']['texto'] === CobrancaDaIntegracao::texto('dinheiro', 5890, 10000) && $r['cobranca']['centavos'] === 5890 && $r['cobranca']['troco_para_centavos'] === 10000, 'a cobrança do app');
confere($r['observacoes'] === 'Tocar o interfone' && $r['complemento'] === 'Ap 12' && $r['referencia'] === 'Perto da praça', 'observação, complemento e referência');
confere($r['telefone'] === '16999990000' && $r['itens'] === null && $r['cancelado'] === false && $r['pago_mesmo_cancelado'] === false, 'telefone ao motoboy do pedido; sem itens nem cancelamento pago');
confere(DadosDaIntegracaoDoMotoboy::resposta($linha, $pedido, 'driver-2')['telefone'] === null, 'outro motoboy: sem telefone');
confere(PedidosDoPortal::comoLinhaDaIntegracao(null) === null, 'sem linha: null');
linhaDoPortal(['order_uuid' => 'order-2', 'chave' => '22222222-2222-4222-8222-222222222222', 'cobranca_forma' => 'pago', 'cobranca_centavos' => 0, 'troco_para_centavos' => null]);
$pago = DadosDaIntegracaoDoMotoboy::resposta(PedidosDoPortal::comoLinhaDaIntegracao(PedidosDoPortal::doPedido('order-2')), $pedido, 'driver-1');
confere($pago['cobranca']['forma'] === 'pago' && $pago['cobranca']['texto'] === null, 'já pago: o app mostra "Já pago"');

echo '== PedidosDoPortal: resumo da resposta' . PHP_EOL;
reiniciarIntegracao();
localDoCliente();
linhaDoPortal();
$resumo = PedidosDoPortal::resumo(new Order(['uuid' => 'order-1', 'public_id' => 'order_1', 'tracking' => 'RP123456']), PedidosDoPortal::doPedido('order-1'));
confere($resumo === ['id' => 'order_1', 'numero' => 'RP123456', 'cliente' => 'Mariana Souza', 'endereco' => 'RUA OLINDA, 45 · Ap 12 · CENTRO', 'cobranca' => CobrancaDaIntegracao::texto('dinheiro', 5890, 10000)], 'id, rastreio, cliente, endereço e cobrança');
confere(PedidosDoPortal::resumo(new Order(['uuid' => 'order-1', 'public_id' => 'order_1']), PedidosDoPortal::doPedido('order-1'))['numero'] === 'order_1', 'sem rastreio: o public_id');
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/chamar-motoboy.php`
Expected: erro fatal "Class App\Support\Entregas\Portal\PedidosDoPortal not found".

- [ ] **Step 3: Implementar**

`api/app/Support/Entregas/Portal/PedidosDoPortal.php`:

```php
<?php

namespace App\Support\Entregas\Portal;

use App\Support\Entregas\Integracao\CobrancaDaIntegracao;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: leitura da entregas_portal_pedidos num lugar só (o chamar motoboy do portal da loja). Pedido
 * do portal = Order com uma linha aqui. Não é linha da integração: nada daqui vai ao webhook da loja, ao acompanhamento da
 * integração nem ao CalculoEntregas. Quem lê: o app do motoboy (comoLinhaDaIntegracao, no formato da integração), a
 * comanda da central e o detalhe do pedido no portal. Spec: docs/superpowers/specs/2026-10-28-chamar-motoboy-portal-design.md.
 */
final class PedidosDoPortal
{
    public const TABELA = 'entregas_portal_pedidos';

    /** O nome que o app mostra no selo de origem (o mesmo do card do portal). */
    public const SISTEMA = 'Portal';

    /** Linhas do mesmo telefone olhadas para reaproveitar o Local. */
    public const ULTIMOS = 20;

    /**
     * A linha do pedido, ou null. Erro de banco (a tabela ainda não existe nos segundos entre o service update e a
     * migration do deploy) vira null com warning: o app, a comanda e o portal seguem como pedido sem linha, sem 500.
     */
    public static function doPedido(?string $orderUuid): ?object
    {
        if ($orderUuid === null || $orderUuid === '') {
            return null;
        }

        try {
            return DB::table(static::TABELA)->where('order_uuid', $orderUuid)->first();
        } catch (QueryException $e) {
            Log::warning('[entregas] portal da loja: pedidos do portal ilegíveis no banco', ['erro' => get_class($e)]);

            return null;
        }
    }

    public static function daChave(string $vendorUuid, string $chave): ?object
    {
        return DB::table(static::TABELA)->where('vendor_uuid', $vendorUuid)->where('chave', $chave)->first();
    }

    /** As últimas linhas do telefone na loja, a mais nova primeiro. */
    public static function doTelefone(string $empresa, string $vendorUuid, string $telefone): array
    {
        return DB::table(static::TABELA)
            ->where('company_uuid', $empresa)
            ->where('vendor_uuid', $vendorUuid)
            ->where('cliente_telefone', $telefone)
            ->orderBy('id', 'desc')
            ->limit(static::ULTIMOS)
            ->get()
            ->all();
    }

    /**
     * O "Já pediu antes" do painel: nome, contagem e o último endereço (rua, bairro, cidade, UF, CEP e ponto do Local;
     * complemento e referência da linha). Só da loja informada; sem pedido do telefone, null. Local apagado ou sem ponto:
     * endereco null.
     */
    public static function clienteDoTelefone(string $empresa, string $vendorUuid, string $telefone): ?array
    {
        $linhas = static::doTelefone($empresa, $vendorUuid, $telefone);
        if (!$linhas) {
            return null;
        }
        $ultima = $linhas[0];
        $lugar  = $ultima->place_uuid ? Place::where('uuid', $ultima->place_uuid)->where('company_uuid', $empresa)->first() : null;
        $ponto  = $lugar && ($lugar->location ?? null) instanceof Point ? $lugar->location : null;

        return [
            'nome'     => (string) $ultima->cliente_nome,
            'telefone' => $telefone,
            'pedidos'  => DB::table(static::TABELA)->where('company_uuid', $empresa)->where('vendor_uuid', $vendorUuid)->where('cliente_telefone', $telefone)->count(),
            'endereco' => $ponto ? [
                'rua'         => $lugar->street1 ?? null,
                'bairro'      => $lugar->neighborhood ?? null,
                'cidade'      => $lugar->city ?? null,
                'uf'          => $lugar->province ?? null,
                'cep'         => $lugar->postal_code ?? null,
                'complemento' => $ultima->complemento,
                'referencia'  => $ultima->referencia,
                'latitude'    => (float) $ponto->getLat(),
                'longitude'   => (float) $ponto->getLng(),
            ] : null,
        ];
    }

    /**
     * A linha no formato da da entregas_integracao_pedidos, para o DadosDaIntegracaoDoMotoboy (app do motoboy, sem APK
     * novo): sistema "Portal", sem número da origem, sem itens e sem cancelamento pago.
     */
    public static function comoLinhaDaIntegracao(?object $linha): ?object
    {
        if (!$linha) {
            return null;
        }

        return (object) [
            'sistema'              => static::SISTEMA,
            'numero'               => null,
            'cliente_nome'         => $linha->cliente_nome,
            'cliente_telefone'     => $linha->cliente_telefone,
            'complemento'          => $linha->complemento,
            'referencia'           => $linha->referencia,
            'observacoes'          => $linha->observacoes,
            'cobranca_forma'       => $linha->cobranca_forma,
            'cobranca_centavos'    => (int) $linha->cobranca_centavos,
            'troco_para_centavos'  => $linha->troco_para_centavos !== null ? (int) $linha->troco_para_centavos : null,
            'itens'                => null,
            'cancelado_em'         => null,
            'pago_mesmo_cancelado' => false,
        ];
    }

    /** A resposta do POST chamar-motoboy (a tela "Procurando motoboy"): número, cliente, endereço e cobrança (null = já pago). */
    public static function resumo(object $pedido, object $linha): array
    {
        $lugar    = $linha->place_uuid ? Place::where('uuid', $linha->place_uuid)->first() : null;
        $endereco = implode(' · ', array_filter([$lugar->street1 ?? null, $linha->complemento, $lugar->neighborhood ?? null], fn ($parte) => is_string($parte) && trim($parte) !== ''));

        return [
            'id'       => (string) $pedido->public_id,
            'numero'   => (string) (($pedido->tracking ?? null) ?: $pedido->public_id),
            'cliente'  => (string) $linha->cliente_nome,
            'endereco' => $endereco !== '' ? $endereco : null,
            'cobranca' => CobrancaDaIntegracao::texto($linha->cobranca_forma, (int) $linha->cobranca_centavos, $linha->troco_para_centavos !== null ? (int) $linha->troco_para_centavos : null),
        ];
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/chamar-motoboy.php`
Expected: "FALHAS: 0".

- [ ] **Step 5: Sintaxe e commit**

```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/Portal/PedidosDoPortal.php
git add api/app/Support/Entregas/Portal/PedidosDoPortal.php scripts/teste-php/chamar-motoboy.php
git commit -m "Chamar motoboy: leitura dos pedidos do portal (PedidosDoPortal)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Tarefa 5: Criar o pedido (`CriadorDoChamado`)

**Files:**
- Create: `api/app/Support/Entregas/Portal/CriadorDoChamado.php`
- Modify: `scripts/teste-php/chamar-motoboy.php` (casos antes do `resumo();`)

- [ ] **Step 1: Escrever o teste que falha**

Em `scripts/teste-php/chamar-motoboy.php`, acrescente nos `use`:

```php
use App\Support\Entregas\PedidoRecusado;
use App\Support\Entregas\Portal\ChamadoDaLoja;
use App\Support\Entregas\Portal\CriadorDoChamado;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Vendor;
use Teste\Trava;
```

e as funções (logo depois de `localDoCliente`):

```php
/** Os dados validados de um chamado (o corpo do painel), com $mudancas por cima. */
function dadosDoChamado(array $mudancas = []): array
{
    $r = ChamadoDaLoja::validar(array_replace_recursive([
        'chave'      => '6f1c0e8a-2b1d-4c3e-9a7f-0d5b8c2e4f10',
        'cliente'    => ['nome' => 'Mariana Souza', 'telefone' => '(16) 99999-0000'],
        'entrega'    => ['rua' => 'Rua Olinda, 45', 'bairro' => 'Centro', 'cidade' => 'Ribeirão Preto', 'uf' => 'SP', 'cep' => '14010-000', 'latitude' => -21.18, 'longitude' => -47.81, 'complemento' => 'Ap 12', 'referencia' => 'Perto da praça'],
        'cobranca'   => ['forma' => 'dinheiro', 'valor' => '58,90', 'troco_para' => '100'],
        'observacao' => 'Tocar o interfone',
    ], $mudancas));

    return $r['dados'] ?? throw new \RuntimeException('chamado de teste inválido: ' . implode(' ', $r['erros']));
}

function lojaA(): Vendor
{
    return Vendor::where('uuid', 'vendor-a')->first();
}

function coletaA(): Place
{
    return Place::where('uuid', 'place-loja')->first();
}

/** Chama e devolve [linha, pedido, novo]; uma recusa volta como PedidoRecusado. */
function chamar(array $mudancas = [], ?Place $coleta = null)
{
    try {
        return (new CriadorDoChamado())->criar(lojaA(), $coleta ?? coletaA(), dadosDoChamado($mudancas), 'usuario-da-loja');
    } catch (PedidoRecusado $e) {
        return $e;
    }
}
```

Antes do `resumo();` final:

```php
echo '== CriadorDoChamado: endereço novo' . PHP_EOL;
reiniciarIntegracao();
[$linha, $pedido, $novo] = chamar();
$destino = Place::$criados[0] ?? null;
confere($novo === true && count(Order::$criados) === 1 && count(Place::$criados) === 1, 'um pedido e um Local novos');
confere($pedido->customer_uuid === 'vendor-a' && $pedido->customer_type === 'fleet-ops:vendor' && $pedido->company_uuid === 'empresa-1' && $pedido->empresaNaSessao === 'empresa-1', 'cliente = a loja, empresa na sessão');
confere($pedido->type === 'transport' && $pedido->order_config_uuid === 'config-transport' && $pedido->status === 'created' && $pedido->adhocAoCriar === true, 'tipo transport, criado como pedido aberto');
confere(!isset($pedido->notes) && ($pedido->meta['customer_portal']['created_by_uuid'] ?? null) === 'usuario-da-loja', 'sem notas (observação com # viraria selo); meta.customer_portal como o portal grava');
confere($destino->owner_uuid === 'vendor-a' && $destino->owner_type === 'fleet-ops:vendor' && $destino->company_uuid === 'empresa-1', 'Local do cliente com dono = a loja');
confere($destino->name === 'MARIANA SOUZA' && $destino->street1 === 'RUA OLINDA, 45' && $destino->street2 === 'AP 12' && $destino->neighborhood === 'CENTRO' && $destino->city === 'RIBEIRÃO PRETO' && $destino->province === 'SP' && $destino->postal_code === '14010-000' && $destino->country === 'BR', 'endereço em maiúsculas (com acento)');
confere($destino->location->getLat() === -21.18 && $destino->location->getLng() === -47.81 && !isset($destino->phone), 'ponto marcado; o telefone não vai para o Local');
$payload = Payload::$salvos[0];
confere($payload->pickup->uuid === 'place-loja' && $payload->dropoff === $destino && $pedido->payload_uuid === $payload->uuid, 'coleta = Local da loja, entrega = o Local do cliente');
confere($linha->order_uuid === $pedido->uuid && $linha->vendor_uuid === 'vendor-a' && $linha->company_uuid === 'empresa-1' && $linha->place_uuid === $destino->uuid && $linha->chave === '6f1c0e8a-2b1d-4c3e-9a7f-0d5b8c2e4f10', 'linha ligada ao pedido, ao Local e à chave');
confere($linha->cliente_nome === 'Mariana Souza' && $linha->cliente_telefone === '16999990000' && $linha->complemento === 'Ap 12' && $linha->referencia === 'Perto da praça' && $linha->observacoes === 'Tocar o interfone', 'cliente, complemento, referência e observação na linha');
confere($linha->cobranca_forma === 'dinheiro' && (int) $linha->cobranca_centavos === 5890 && (int) $linha->troco_para_centavos === 10000, 'cobrança na linha');
confere($pedido->chamadas === ['saveQuietly', 'firstDispatchWithActivity'] && $pedido->travadoNoDespacho === [true] && Trava::$ocupadas === [], 'despachado como pedido aberto, com a trava, e a trava solta');
confere(logou('[entregas] portal da loja: motoboy chamado', 'info') && logsSem(['Mariana', 'MARIANA', 'OLINDA', 'Olinda', '16999990000', 'interfone']), 'log sem nome, endereço, telefone nem observação');

echo '== CriadorDoChamado: mesma chave' . PHP_EOL;
[$repetida, $mesmo, $novo] = chamar(['cobranca' => ['valor' => '99']]);
confere($novo === false && $mesmo === $pedido && $repetida->id === $linha->id && count(Order::$criados) === 1, 'a repetição devolve o mesmo pedido, sem criar outro');
confere(logou('[entregas] portal da loja: chamado repetido', 'info'), 'log da repetição');

echo '== CriadorDoChamado: mesmo cliente no mesmo ponto reaproveita o Local' . PHP_EOL;
[$linha2, $pedido2, $novo2] = chamar(['chave' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'cliente' => ['nome' => 'mariana souza '], 'entrega' => ['rua' => 'RUA OLINDA, 45', 'latitude' => -21.18009, 'complemento' => 'ap 12']]);
confere($novo2 === true && count(Order::$criados) === 2 && count(Place::$criados) === 1 && $linha2->place_uuid === $destino->uuid, 'nome, rua e complemento iguais (sem diferença de caixa) e ponto a 10 m: o mesmo Local');
confere(Payload::$salvos[1]->dropoff === $destino, 'a entrega do pedido novo é o Local de antes');
[$linha3] = chamar(['chave' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'cliente' => ['nome' => 'Joana Souza']]);
confere(count(Place::$criados) === 2 && $linha3->place_uuid !== $destino->uuid && $destino->name === 'MARIANA SOUZA', 'outro nome no mesmo telefone: Local novo, o antigo não muda');
[$linha4] = chamar(['chave' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'entrega' => ['latitude' => -21.181]]);
confere(count(Place::$criados) === 3 && $linha4->place_uuid !== $destino->uuid, 'mesmo cliente a mais de 30 m: Local novo');
[$linha5] = chamar(['chave' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'cliente' => ['telefone' => '16988887777']]);
confere(count(Place::$criados) === 4 && $linha5->place_uuid !== $destino->uuid, 'outro telefone: Local novo');

echo '== CriadorDoChamado: recusas' . PHP_EOL;
reiniciarIntegracao();
$r = chamar(['entrega' => ['latitude' => -21.1776, 'longitude' => -47.8103]]);
confere($r instanceof PedidoRecusado && $r->status === 422 && str_contains($r->getMessage(), 'mesmo lugar da loja') && Order::$criados === [] && Place::$criados === [], 'ponto a menos de 30 m da loja: 422, sem nada criado');
$r = chamar(['entrega' => ['latitude' => -21.7, 'longitude' => -47.81]]);
confere($r instanceof PedidoRecusado && $r->status === 422 && str_contains($r->getMessage(), 'mais de 50 km') && Order::$criados === [], 'ponto a mais de 50 km: 422');
$semPonto = new Place(['uuid' => 'place-loja', 'company_uuid' => 'empresa-1', 'owner_uuid' => 'vendor-a', 'owner_type' => 'fleet-ops:vendor', 'location' => null]);
$r = chamar([], $semPonto);
confere($r instanceof PedidoRecusado && $r->status === 422 && str_contains($r->getMessage(), 'endereço de coleta') && logou('[entregas] portal da loja: loja sem local de coleta válido', 'warning'), 'loja sem coleta válida: 422 e warning');
$deOutraLoja = new Place(['uuid' => 'place-x', 'company_uuid' => 'empresa-1', 'owner_uuid' => 'vendor-b', 'owner_type' => 'fleet-ops:vendor', 'location' => new Point(-21.1775, -47.8103)]);
confere(chamar([], $deOutraLoja) instanceof PedidoRecusado, 'coleta com outro dono: recusa');

echo '== CriadorDoChamado: falha no despacho' . PHP_EOL;
reiniciarIntegracao();
Order::$falharDespacho = true;
[$linha, $pedido, $novo] = chamar();
confere($novo === true && count(Order::$criados) === 1 && $linha !== null, 'o pedido existe mesmo com o despacho falhando');
confere(logou('[entregas] portal da loja: falha ao despachar o pedido aos motoboys', 'error') && Trava::$ocupadas === [], 'erro no log (a central despacha à mão) e a trava solta');
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/chamar-motoboy.php`
Expected: erro fatal "Class App\Support\Entregas\Portal\CriadorDoChamado not found".

- [ ] **Step 3: Implementar**

`api/app/Support/Entregas/Portal/CriadorDoChamado.php`:

```php
<?php

namespace App\Support\Entregas\Portal;

use App\Http\Middleware\RegrasPortalLoja;
use App\Support\Entregas\Coordenada;
use App\Support\Entregas\Ifood\PedidoDoIfood;
use App\Support\Entregas\Integracao\CriadorDoPedidoDaIntegracao;
use App\Support\Entregas\PedidoRecusado;
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
 * Entregas RestaurantePro: cria o pedido do "Chamar motoboy" do portal da loja e o despacha, no molde do
 * CriadorDoPedidoDaIntegracao (spec docs/superpowers/specs/2026-10-28-chamar-motoboy-portal-design.md):
 * - a mesma chave da loja devolve o pedido que já nasceu (repetido), sem criar outro;
 * - coleta = o Local da loja (empresa, dono e coordenadas); o ponto da entrega a 30 m ou mais da coleta (a regra do
 *   portal, RegrasPortalLoja) e a até 50 km (a da integração);
 * - entrega = o Local do mesmo cliente no mesmo ponto (reaproveitado) ou um Local novo com dono = a loja, criado direto
 *   (sem o firstOrNew do PlaceController, que sobrescreveria um salvo) e sem o telefone (o Place vai aos motoboys);
 * - Order da loja, tipo do OrderConfig::default(), aberto (adhoc), sem notas (a observação com "#" viraria selo de origem),
 *   e a linha da entregas_portal_pedidos na mesma transação;
 * - despacho como o do portal e da integração: adhoc + firstDispatchWithActivity com a TravaDoPedido → distribuição.
 *   Falhou: só o log, o pedido existe e a central despacha à mão.
 * O crédito da loja pré-paga fica com quem chama (CreditoNoPedido, no ChamarMotoboyController).
 */
class CriadorDoChamado
{
    /** O Local de antes serve se o ponto novo estiver a até isto dele (e nome, rua e complemento baterem). */
    public const REAPROVEITAR_ATE_METROS = 30;

    /**
     * @return array{0: object, 1: Order, 2: bool} a linha, o pedido e se nasceu agora (false = a mesma chave)
     *
     * @throws PedidoRecusado 422 coleta inválida ou ponto fora dos limites
     */
    public function criar(Vendor $vendor, ?Place $coleta, array $dados, string $usuarioUuid): array
    {
        if ($repetido = $this->repetido($vendor, $dados['chave'])) {
            return $repetido;
        }

        $empresa = (string) $vendor->company_uuid;
        if (!$coleta || !$this->coletaValida($coleta, $vendor, $empresa)) {
            Log::warning('[entregas] portal da loja: loja sem local de coleta válido', ['loja' => $vendor->public_id]);
            throw new PedidoRecusado(422, 'A loja ainda não tem endereço de coleta cadastrado. Fale com a central.');
        }

        $metros = PedidoDoIfood::metrosEntre((float) $coleta->location->getLat(), (float) $coleta->location->getLng(), $dados['latitude'], $dados['longitude']);
        if ($metros < RegrasPortalLoja::DISTANCIA_MINIMA_DA_COLETA_METROS) {
            throw new PedidoRecusado(422, 'O ponto da entrega está no mesmo lugar da loja. Arraste o mapa até o local da entrega.');
        }
        if ($metros > CriadorDoPedidoDaIntegracao::DISTANCIA_MAXIMA_METROS) {
            throw new PedidoRecusado(422, 'O ponto da entrega está a mais de 50 km da loja. Confira a cidade e o ponto no mapa.');
        }

        // o TrackingNumberObserver e o OrderConfig::default() leem a empresa da sessão
        session(['company' => $empresa, 'user' => $usuarioUuid]);
        $reaproveitado = $this->lugarParaReaproveitar($vendor, $dados);

        try {
            [$linha, $pedido] = DB::transaction(fn () => $this->gravar($vendor, $coleta, $dados, $usuarioUuid, $reaproveitado));
        } catch (QueryException $e) {
            // a mesma chave chegou duas vezes no mesmo instante: a chave única segurou a segunda, que devolve a primeira
            if ((string) $e->getCode() === '23000' && ($repetido = $this->repetido($vendor, $dados['chave']))) {
                return $repetido;
            }
            throw $e;
        }

        Log::info('[entregas] portal da loja: motoboy chamado', ['pedido' => $pedido->public_id, 'loja' => $vendor->public_id, 'endereco_reaproveitado' => $reaproveitado !== null]);
        $this->despachar($pedido, $usuarioUuid);

        return [$linha, $pedido, true];
    }

    /** O pedido que já nasceu com esta chave na loja, ou null. */
    public function repetido(Vendor $vendor, string $chave): ?array
    {
        $linha  = PedidosDoPortal::daChave((string) $vendor->uuid, $chave);
        $pedido = $linha ? Order::where('uuid', $linha->order_uuid)->where('company_uuid', $vendor->company_uuid)->first() : null;
        if (!$pedido) {
            return null;
        }
        Log::info('[entregas] portal da loja: chamado repetido', ['pedido' => $pedido->public_id, 'loja' => $vendor->public_id]);

        return [$linha, $pedido, false];
    }

    /** Pedido aberto aos motoboys, com a trava do pedido e o pedido relido. Nunca lança. */
    public function despachar(Order $pedido, string $usuarioUuid): void
    {
        try {
            TravaDoPedido::executar((string) $pedido->uuid, function () use ($pedido, $usuarioUuid) {
                $atual = $pedido->fresh();
                if (!$atual || $atual->deleted_at || $atual->dispatched || $atual->started || in_array(strtolower((string) $atual->status), StatusDoPedido::ENCERRADOS, true)) {
                    return;
                }
                session(['company' => $atual->company_uuid, 'user' => $usuarioUuid]);
                // sem eventos: o despacho logo abaixo grava de novo e emite os eventos (OrderDispatched → distribuição)
                $atual->adhoc = true;
                $atual->saveQuietly();
                $atual->firstDispatchWithActivity();
            });
        } catch (LockTimeoutException $e) {
            Log::warning('[entregas] portal da loja: trava do pedido ocupada; despacho fica para a central', ['pedido' => $pedido->public_id]);
        } catch (\Throwable $e) {
            Log::error('[entregas] portal da loja: falha ao despachar o pedido aos motoboys', ['pedido' => $pedido->public_id, 'erro' => get_class($e)]);
        }
    }

    /** @return array{0: object, 1: Order} */
    protected function gravar(Vendor $vendor, Place $coleta, array $dados, string $usuarioUuid, ?Place $destino): array
    {
        $empresa = (string) $vendor->company_uuid;
        // mb_strtoupper: o PlaceObserver só faz strtoupper (ASCII) e gravaria "RIBEIRãO"
        $maiusculo = fn (?string $texto) => $texto === null ? null : mb_strtoupper($texto, 'UTF-8');
        $destino ??= Place::create([
            'company_uuid' => $empresa,
            'owner_uuid'   => $vendor->uuid,
            'owner_type'   => Utils::getMutationType($vendor),
            'name'         => $maiusculo($dados['cliente_nome']),
            'street1'      => $maiusculo($dados['rua']),
            'street2'      => $maiusculo($dados['complemento']),
            'neighborhood' => $maiusculo($dados['bairro']),
            'city'         => $maiusculo($dados['cidade']),
            'province'     => $dados['uf'],
            'postal_code'  => $dados['cep'],
            'country'      => 'BR',
            'location'     => new Point($dados['latitude'], $dados['longitude']),
        ]);

        $payload               = new Payload();
        $payload->company_uuid = $empresa;
        $payload->setPickup($coleta, [
            'callback' => function ($pickup, $payload) {
                $payload->setCurrentWaypoint($pickup);
            },
        ]);
        $payload->setDropoff($destino);
        $payload->save();

        $config = OrderConfig::default() ?? OrderConfig::where('company_uuid', $empresa)->first();
        if (!$config) {
            throw new \RuntimeException('a empresa não tem tipo de pedido (OrderConfig)');
        }

        $agora  = now()->toDateTimeString();
        $pedido = Order::create([
            'company_uuid'      => $empresa,
            'customer_uuid'     => $vendor->uuid,
            'customer_type'     => Utils::getMutationType($vendor),
            'payload_uuid'      => $payload->uuid,
            'order_config_uuid' => $config->uuid,
            'type'              => $config->key,
            'status'            => 'created',
            'adhoc'             => true,
            'meta'              => ['customer_portal' => ['created_by_uuid' => $usuarioUuid, 'created_at' => $agora]],
        ]);

        $id = DB::table(PedidosDoPortal::TABELA)->insertGetId([
            'company_uuid'        => $empresa,
            'vendor_uuid'         => $vendor->uuid,
            'order_uuid'          => $pedido->uuid,
            'place_uuid'          => $destino->uuid,
            'chave'               => $dados['chave'],
            'cliente_nome'        => $dados['cliente_nome'],
            'cliente_telefone'    => $dados['cliente_telefone'],
            'complemento'         => $dados['complemento'],
            'referencia'          => $dados['referencia'],
            'observacoes'         => $dados['observacoes'],
            'cobranca_forma'      => $dados['cobranca_forma'],
            'cobranca_centavos'   => $dados['cobranca_centavos'],
            'troco_para_centavos' => $dados['troco_para_centavos'],
            'created_at'          => $agora,
            'updated_at'          => $agora,
        ]);

        return [DB::table(PedidosDoPortal::TABELA)->where('id', $id)->first(), $pedido];
    }

    /** O Local de um pedido recente do mesmo telefone na loja, com o mesmo nome, rua e complemento e o ponto a até 30 m. */
    protected function lugarParaReaproveitar(Vendor $vendor, array $dados): ?Place
    {
        $vistos = [];
        foreach (PedidosDoPortal::doTelefone((string) $vendor->company_uuid, (string) $vendor->uuid, $dados['cliente_telefone']) as $linha) {
            $uuid = (string) ($linha->place_uuid ?? '');
            if ($uuid === '' || isset($vistos[$uuid])) {
                continue;
            }
            $vistos[$uuid] = true;
            $lugar         = Place::where('uuid', $uuid)->where('company_uuid', $vendor->company_uuid)->where('owner_uuid', $vendor->uuid)->first();
            if ($lugar && $this->mesmoLugar($lugar, $dados)) {
                return $lugar;
            }
        }

        return null;
    }

    protected function mesmoLugar(Place $lugar, array $dados): bool
    {
        $igual = fn ($a, $b) => mb_strtoupper(trim((string) $a), 'UTF-8') === mb_strtoupper(trim((string) $b), 'UTF-8');

        return ($lugar->location ?? null) instanceof Point
            && $igual($lugar->name ?? '', $dados['cliente_nome'])
            && $igual($lugar->street1 ?? '', $dados['rua'])
            && $igual($lugar->street2 ?? '', $dados['complemento'] ?? '')
            && PedidoDoIfood::metrosEntre((float) $lugar->location->getLat(), (float) $lugar->location->getLng(), $dados['latitude'], $dados['longitude']) <= static::REAPROVEITAR_ATE_METROS;
    }

    /** Mesmo critério do portal, do iFood e da integração para a coleta: da empresa, com dono = o Vendor e com coordenadas. */
    protected function coletaValida(Place $coleta, Vendor $vendor, string $empresa): bool
    {
        return $empresa !== ''
            && $coleta->company_uuid === $empresa
            && ($coleta->owner_uuid ?? null) === $vendor->uuid
            && ($coleta->owner_type ?? null) === Utils::getMutationType($vendor)
            && ($coleta->location ?? null) instanceof Point
            && Coordenada::valida($coleta->location->getLat(), $coleta->location->getLng());
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/chamar-motoboy.php`
Expected: "FALHAS: 0". Se o stub do `Order` não guardar o `meta` como array, ajuste a leitura no teste para
`(array) $pedido->meta` (não mude o código de produção por causa do stub).

- [ ] **Step 5: Sintaxe e commit**

```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/Portal/CriadorDoChamado.php
git add api/app/Support/Entregas/Portal/CriadorDoChamado.php scripts/teste-php/chamar-motoboy.php
git commit -m "Chamar motoboy: criação e despacho do pedido (CriadorDoChamado)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Tarefa 6: Controller e rotas (`loja/clientes`, `loja/chamar-motoboy`)

**Files:**
- Create: `api/app/Http/Controllers/Entregas/ChamarMotoboyController.php`
- Modify: `api/app/Providers/RouteServiceProvider.php` (import e duas rotas depois de `loja/aceites`)
- Modify: `scripts/teste-php/chamar-motoboy.php`

- [ ] **Step 1: Escrever o teste que falha**

Em `scripts/teste-php/chamar-motoboy.php`, acrescente nos `use`:

```php
use App\Http\Controllers\Entregas\ChamarMotoboyController;
use Illuminate\Http\Request;
use Teste\Sessao;
```

(O stub do `Request` fica em `namespace Illuminate\Http` no `stubs-ifood-fleetbase.php`, com `all()` e `input()`.)

Depois das funções auxiliares:

```php
// a loja e a coleta da sessão vêm do LojaDoUsuario (Eloquent com whereHas); aqui são fixas. O crédito é contado e pode
// ser recusado pelo teste (a regra dele tem os testes no prepago-loja.php)
class ControllerDeTeste extends ChamarMotoboyController
{
    public ?Vendor $loja = null;
    public int $creditos = 0;
    public ?PedidoRecusado $recusa = null;

    protected function lojaDaSessao(): ?Vendor { return $this->loja; }
    protected function coletaDaLoja(Vendor $vendor): ?Place { return coletaA(); }
    protected function comCredito(string $empresa, string $lojaUuid, \Closure $criar)
    {
        $this->creditos++;
        if ($this->recusa) {
            throw $this->recusa;
        }

        return $criar();
    }
}

function controller(): ControllerDeTeste
{
    reiniciarIntegracao();
    Sessao::$dados = ['company' => 'empresa-1', 'user' => 'usuario-da-loja'];
    $c       = new ControllerDeTeste();
    $c->loja = lojaA();

    return $c;
}

function corpoDaTela(array $mudancas = []): array
{
    return array_replace_recursive([
        'chave'      => '6f1c0e8a-2b1d-4c3e-9a7f-0d5b8c2e4f10',
        'cliente'    => ['nome' => 'Mariana Souza', 'telefone' => '16999990000'],
        'entrega'    => ['rua' => 'Rua Olinda, 45', 'bairro' => 'Centro', 'cidade' => 'Ribeirão Preto', 'uf' => 'SP', 'cep' => '14010-000', 'latitude' => -21.18, 'longitude' => -47.81, 'complemento' => 'Ap 12', 'referencia' => null],
        'cobranca'   => ['forma' => 'pago'],
        'observacao' => null,
    ], $mudancas);
}
```

Antes do `resumo();` final:

```php
echo '== ChamarMotoboyController@chamar' . PHP_EOL;
$c = controller();
$r = $c->chamar(new Request(corpoDaTela()), new CriadorDoChamado());
confere($r->status === 201 && $r->dados['pedido']['id'] === Order::$criados[0]->public_id && $r->dados['pedido']['cliente'] === 'Mariana Souza' && $r->dados['pedido']['cobranca'] === null && $c->creditos === 1, '201 com o resumo; já pago = cobrança null; passou pelo crédito');
$r = $c->chamar(new Request(corpoDaTela()), new CriadorDoChamado());
confere($r->status === 200 && $r->dados['pedido']['id'] === Order::$criados[0]->public_id && count(Order::$criados) === 1 && $c->creditos === 1, 'mesma chave: 200 com o mesmo pedido, sem passar pelo crédito de novo');
$r = $c->chamar(new Request(corpoDaTela(['chave' => 'x', 'cliente' => ['telefone' => '123']])), new CriadorDoChamado());
confere($r->status === 422 && in_array('Atualize a página e tente de novo.', $r->dados['errors'], true) && in_array('Informe o telefone do cliente com DDD.', $r->dados['errors'], true), 'corpo inválido: 422 com as mensagens');
$c->recusa = new PedidoRecusado(422, 'Crédito insuficiente. Recarregue a carteira.');
$r         = $c->chamar(new Request(corpoDaTela(['chave' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee'])), new CriadorDoChamado());
confere($r->status === 422 && $r->dados === ['errors' => ['Crédito insuficiente. Recarregue a carteira.']] && count(Order::$criados) === 1, 'sem crédito: 422 com a mensagem, sem pedido');
$c->recusa = null;
$r         = $c->chamar(new Request(corpoDaTela(['chave' => 'ffffffff-ffff-4fff-8fff-ffffffffffff', 'entrega' => ['latitude' => -21.1776, 'longitude' => -47.8103]])), new CriadorDoChamado());
confere($r->status === 422 && str_contains($r->dados['errors'][0], 'mesmo lugar da loja'), 'recusa do criador: 422 com a mensagem');
$c->loja = null;
$r       = $c->chamar(new Request(corpoDaTela()), new CriadorDoChamado());
confere($r->status === 403 && str_contains($r->dados['errors'][0], 'não está ligado a nenhuma loja'), 'usuário sem loja: 403');

echo '== ChamarMotoboyController@clientes' . PHP_EOL;
$c = controller();
localDoCliente();
linhaDoPortal();
$r = $c->clientes(new Request(['telefone' => '(16) 99999-0000']));
confere($r->dados['cliente']['nome'] === 'Mariana Souza' && $r->dados['cliente']['pedidos'] === 1, 'telefone formatado acha o cliente da loja');
confere($c->clientes(new Request(['telefone' => '9999']))->dados === ['cliente' => null], 'telefone incompleto: cliente null (sem 422)');
$c->loja = new Vendor(['uuid' => 'vendor-b', 'public_id' => 'vendor_b', 'company_uuid' => 'empresa-1', 'name' => 'Outra']);
confere($c->clientes(new Request(['telefone' => '16999990000']))->dados === ['cliente' => null], 'outra loja não vê o cliente');
$c->loja = null;
confere($c->clientes(new Request(['telefone' => '16999990000']))->status === 403, 'sem loja: 403');

echo '== Rotas e liberação' . PHP_EOL;
$rotas = file_get_contents($raiz . '/api/app/Providers/RouteServiceProvider.php');
confere(str_contains($rotas, "Route::get('loja/clientes', [ChamarMotoboyController::class, 'clientes'])->middleware('throttle:60,1');"), 'GET loja/clientes, 60 por minuto');
confere(str_contains($rotas, "Route::post('loja/chamar-motoboy', [ChamarMotoboyController::class, 'chamar'])->middleware('throttle:entregas-loja-chamar');"), 'POST loja/chamar-motoboy no limitador nomeado');
// o throttle sem nome divide um contador por usuário entre todas as rotas que o usam (o 60,1 da loja esgotaria um 20,1)
$limitador = 'RateLimiter::for(\'entregas-loja-chamar\', fn (Request $request) => Limit::perMinute(20)->by(\'entregas-loja-chamar:\' . (session(\'user\') ?: $request->ip())));';
confere(str_contains($rotas, $limitador), 'limitador entregas-loja-chamar: 20 por minuto por usuário, balde próprio');
confere(str_contains($rotas, 'use App\Http\Controllers\Entregas\ChamarMotoboyController;'), 'import do controller');
$proteger = file_get_contents($raiz . '/api/app/Http/Middleware/ProtegerPortalLoja.php');
confere(str_contains($proteger, "'#^[A-Z]+ entregas/loja/.+$#'"), 'liberadas à loja pelo ProtegerPortalLoja (entregas/loja/*)');
$ctl = file_get_contents($raiz . '/api/app/Http/Controllers/Entregas/ChamarMotoboyController.php');
confere(str_contains($ctl, 'return CreditoNoPedido::criar($empresa, $lojaUuid, $criar);'), 'o crédito da loja pré-paga é o CreditoNoPedido');
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/chamar-motoboy.php`
Expected: erro fatal "Class App\Http\Controllers\Entregas\ChamarMotoboyController not found".

- [ ] **Step 3: Implementar o controller**

`api/app/Http/Controllers/Entregas/ChamarMotoboyController.php`:

```php
<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\LojaDoUsuario;
use App\Support\Entregas\PedidoRecusado;
use App\Support\Entregas\Portal\ChamadoDaLoja;
use App\Support\Entregas\Portal\CriadorDoChamado;
use App\Support\Entregas\Portal\PedidosDoPortal;
use App\Support\Entregas\PrePago\CreditoNoPedido;
use Closure;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: o painel "Chamar motoboy" do portal da loja (int/v1/entregas/loja/*, liberadas à loja pelo
 * ProtegerPortalLoja; a loja é sempre a da sessão, nunca por parâmetro). Spec:
 * docs/superpowers/specs/2026-10-28-chamar-motoboy-portal-design.md.
 * - GET loja/clientes?telefone=: o "Já pediu antes" (PedidosDoPortal::clienteDoTelefone), só da loja da sessão;
 *   telefone incompleto = {"cliente": null}.
 * - POST loja/chamar-motoboy: valida (ChamadoDaLoja), devolve o pedido da mesma chave (200) sem passar pelo crédito,
 *   confere o crédito da loja pré-paga (CreditoNoPedido) e cria e despacha (CriadorDoChamado): 201.
 */
class ChamarMotoboyController extends Controller
{
    public function clientes(Request $request)
    {
        $vendor = $this->lojaDaSessao();
        if (!$vendor) {
            return $this->semLoja();
        }

        $telefone = ChamadoDaLoja::telefone($request->input('telefone'));

        return response()->json([
            'cliente' => $telefone ? PedidosDoPortal::clienteDoTelefone((string) $vendor->company_uuid, (string) $vendor->uuid, $telefone) : null,
        ]);
    }

    public function chamar(Request $request, CriadorDoChamado $criador)
    {
        $vendor = $this->lojaDaSessao();
        if (!$vendor) {
            return $this->semLoja();
        }

        $validacao = ChamadoDaLoja::validar($request->all());
        if ($validacao['erros']) {
            return response()->json(['errors' => $validacao['erros']], 422);
        }
        $dados   = $validacao['dados'];
        $usuario = (string) session('user');

        try {
            // a mesma chave (a loja tocou de novo depois de a rede cair): o pedido que já nasceu, sem conferir o crédito de
            // novo (o próprio pedido já reservou o crédito e a conferência o recusaria)
            [$linha, $pedido, $novo] = $criador->repetido($vendor, $dados['chave'])
                ?? $this->comCredito((string) $vendor->company_uuid, (string) $vendor->uuid, fn () => $criador->criar($vendor, $this->coletaDaLoja($vendor), $dados, $usuario));
        } catch (PedidoRecusado $e) {
            return response()->json(['errors' => [$e->getMessage()]], $e->status);
        }

        return response()->json(['pedido' => PedidosDoPortal::resumo($pedido, $linha)], $novo ? 201 : 200);
    }

    protected function lojaDaSessao(): ?Vendor
    {
        return LojaDoUsuario::vendor(session('user'));
    }

    protected function coletaDaLoja(Vendor $vendor): ?Place
    {
        return LojaDoUsuario::coleta($vendor);
    }

    protected function comCredito(string $empresa, string $lojaUuid, Closure $criar)
    {
        return CreditoNoPedido::criar($empresa, $lojaUuid, $criar);
    }

    protected function semLoja()
    {
        return response()->json(['errors' => ['Este usuário não está ligado a nenhuma loja. Fale com a central.']], 403);
    }
}
```

- [ ] **Step 4: Registrar as rotas**

Em `api/app/Providers/RouteServiceProvider.php`:

1. Depois de `use App\Http\Controllers\Entregas\CarteiraDaLojaController;` acrescente
   `use App\Http\Controllers\Entregas\ChamarMotoboyController;`.
2. Depois da linha

```php
                        Route::get('loja/aceites', [PortalLojaController::class, 'aceites'])->middleware('throttle:entregas-loja-aceites');
```

acrescente:

```php
                        // chamar motoboy (o painel de 3 passos do portal; spec 2026-10-28-chamar-motoboy-portal-design.md)
                        Route::get('loja/clientes', [ChamarMotoboyController::class, 'clientes'])->middleware('throttle:60,1');
                        Route::post('loja/chamar-motoboy', [ChamarMotoboyController::class, 'chamar'])->middleware('throttle:entregas-loja-chamar');
```

3. Depois do `RateLimiter::for('entregas-loja-recarga', …)`, o limitador nomeado (ajuste da execução: o `ifood-lojas.php`
   proíbe o `throttle:20,1` sem nome, que conta por usuário em todas as rotas que o usam e seria esgotado pelo 60,1 das
   outras rotas da loja):

```php
        RateLimiter::for('entregas-loja-chamar', fn (Request $request) => Limit::perMinute(20)->by('entregas-loja-chamar:' . (session('user') ?: $request->ip())));
```

- [ ] **Step 5: Rodar e ver passar**

```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/chamar-motoboy.php
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/imports-dos-providers.php
```
Expected: "FALHAS: 0" nos dois.

- [ ] **Step 6: Sintaxe e commit**

```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Http/Controllers/Entregas/ChamarMotoboyController.php api/app/Providers/RouteServiceProvider.php
git add api/app/Http/Controllers/Entregas/ChamarMotoboyController.php api/app/Providers/RouteServiceProvider.php scripts/teste-php/chamar-motoboy.php
git commit -m "Chamar motoboy: rotas loja/clientes e loja/chamar-motoboy

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Tarefa 7: App, comanda e detalhe do portal leem a linha do portal

**Files:**
- Modify: `api/app/Http/Controllers/Entregas/MotoboyController.php` (`integracao`)
- Modify: `api/app/Http/Controllers/Entregas/PedidoAbertoController.php` (`comanda`)
- Modify: `api/app/Support/Entregas/Painel/ComandaDaCentral.php` (`resposta`)
- Modify: `api/app/Http/Controllers/Entregas/PortalLojaController.php` (`itens`)
- Modify: `scripts/teste-php/comanda-do-pedido.php`, `scripts/teste-php/chamar-motoboy.php`

- [ ] **Step 1: Escrever os testes que falham**

Em `scripts/teste-php/comanda-do-pedido.php`, logo antes da linha `echo '== Nome do cliente (sem nome = null)' . PHP_EOL;`,
acrescente:

```php
echo '== Pedido chamado pelo portal (linha do portal)' . PHP_EOL;
cenario([], ['name' => 'MARIANA SOUZA', 'street2' => 'AP 12']);
Banco::inserir('entregas_portal_pedidos', [
    'company_uuid' => 'empresa-1', 'vendor_uuid' => 'vendor-a', 'order_uuid' => 'order-1', 'place_uuid' => 'place-cliente',
    'chave' => '6f1c0e8a-2b1d-4c3e-9a7f-0d5b8c2e4f10', 'cliente_nome' => 'Mariana Souza', 'cliente_telefone' => '16999990000',
    'complemento' => 'Ap 12', 'referencia' => 'Perto da praça', 'observacoes' => 'Tocar o interfone',
    'cobranca_forma' => 'dinheiro', 'cobranca_centavos' => 5890, 'troco_para_centavos' => 10000,
    'created_at' => '2026-10-24 20:30:00', 'updated_at' => '2026-10-24 20:30:00',
], false);
$d = comanda('order_1')->dados;
confere($d['origem'] === 'portal' && $d['sistema'] === null && $d['teste'] === false, 'origem portal, sem sistema (o console não põe selo)');
confere($d['numero'] === 'RP123456', 'número: o rastreio');
confere($d['cobranca'] === ['texto' => CobrancaDaIntegracao::texto('dinheiro', 5890, 10000), 'centavos' => 5890, 'forma' => CobrancaDaIntegracao::forma('dinheiro'), 'troco_para_centavos' => 10000], 'cobrança igual à do app');
confere($d['observacoes'] === 'Tocar o interfone' && $d['cliente']['complemento'] === 'Ap 12' && $d['cliente']['referencia'] === 'Perto da praça' && $d['itens'] === null, 'observação, complemento e referência da linha; sem produtos');
confere(!str_contains(json($d), '16999990000'), 'sem o telefone do cliente');
```

Em `scripts/teste-php/chamar-motoboy.php`, antes do `resumo();` final:

```php
echo '== Leitores da linha do portal' . PHP_EOL;
$motoboy = file_get_contents($raiz . '/api/app/Http/Controllers/Entregas/MotoboyController.php');
confere(str_contains($motoboy, 'DadosDaIntegracaoDoMotoboy::resposta(PedidosDaIntegracao::doPedido((string) $pedido->uuid) ?? PedidosDoPortal::comoLinhaDaIntegracao(PedidosDoPortal::doPedido((string) $pedido->uuid)), $pedido, (string) $motoboy->uuid)'), 'app do motoboy: a linha do portal no formato da integração');
$aberto = file_get_contents($raiz . '/api/app/Http/Controllers/Entregas/PedidoAbertoController.php');
confere(str_contains($aberto, 'portal: PedidosDoPortal::doPedido($uuid),'), 'comanda da central: a linha do portal');
$portal = file_get_contents($raiz . '/api/app/Http/Controllers/Entregas/PortalLojaController.php');
confere(str_contains($portal, '?? PedidosDaIntegracao::doPedido((string) $pedido->uuid) ?? PedidosDoPortal::doPedido((string) $pedido->uuid);'), 'detalhe do portal: a observação do pedido chamado');
```

- [ ] **Step 2: Rodar e ver falhar**

```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/comanda-do-pedido.php
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/chamar-motoboy.php
```
Expected: FALHA em "origem portal…" e nos três "Leitores da linha do portal".

- [ ] **Step 3: `ComandaDaCentral`**

Em `api/app/Support/Entregas/Painel/ComandaDaCentral.php`:

1. No docblock de `resposta`, depois de `@param bool        $admin` (ou no fim da lista de `@param`), acrescente
   ` * @param object|null $portal       a linha da entregas_portal_pedidos (chamar motoboy do portal), ou null`.
2. Troque

```php
        ?array $valor,
        bool $admin,
    ): array {
```

por

```php
        ?array $valor,
        bool $admin,
        ?object $portal = null,
    ): array {
```

3. Troque `        $linha     = $ifood ?? $integracao;` por `        $linha     = $ifood ?? $integracao ?? $portal;`.
4. Troque

```php
            'origem'         => $ifood ? 'ifood' : ($integracao ? 'integracao' : null),
```

por

```php
            'origem'         => $ifood ? 'ifood' : ($integracao ? 'integracao' : ($portal ? 'portal' : null)),
```

5. Troque `            'cobranca'       => static::cobranca($ifood, $integracao),` por
   `            'cobranca'       => static::cobranca($ifood, $integracao ?? $portal),` (a linha do portal tem as mesmas
   colunas da cobrança da integração).

- [ ] **Step 4: Os três controllers**

1. `api/app/Http/Controllers/Entregas/PedidoAbertoController.php`: acrescente `use App\Support\Entregas\Portal\PedidosDoPortal;`
   nos `use` e, na chamada `ComandaDaCentral::resposta(`, depois de `            admin: $admin,`, a linha
   `            portal: PedidosDoPortal::doPedido($uuid),`.
2. `api/app/Http/Controllers/Entregas/MotoboyController.php`: acrescente `use App\Support\Entregas\Portal\PedidosDoPortal;`
   e troque, no método `integracao`,

```php
        return response()->json(DadosDaIntegracaoDoMotoboy::resposta(PedidosDaIntegracao::doPedido((string) $pedido->uuid), $pedido, (string) $motoboy->uuid));
```

por

```php
        // pedido chamado pelo portal da loja: a linha dele no formato da integração (cobrança, observação, telefone; selo
        // "Portal" no app, sem APK novo)
        return response()->json(DadosDaIntegracaoDoMotoboy::resposta(PedidosDaIntegracao::doPedido((string) $pedido->uuid) ?? PedidosDoPortal::comoLinhaDaIntegracao(PedidosDoPortal::doPedido((string) $pedido->uuid)), $pedido, (string) $motoboy->uuid));
```

3. `api/app/Http/Controllers/Entregas/PortalLojaController.php`: acrescente `use App\Support\Entregas\Portal\PedidosDoPortal;`
   e troque, no método `itens`,

```php
        $linha = PedidosIfood::doPedido((string) $pedido->uuid) ?? PedidosDaIntegracao::doPedido((string) $pedido->uuid);
```

por

```php
        $linha = PedidosIfood::doPedido((string) $pedido->uuid) ?? PedidosDaIntegracao::doPedido((string) $pedido->uuid) ?? PedidosDoPortal::doPedido((string) $pedido->uuid);
```

   No docblock do método, troque "pedido feito no portal (sem itens) ou antigo: itens null." por "pedido feito no portal
   (sem itens; a observação do chamar motoboy vem da entregas_portal_pedidos) ou antigo: itens null.".

- [ ] **Step 5: Rodar e ver passar**

```bash
for t in comanda-do-pedido chamar-motoboy mapa-da-loja ifood-conclusao integracao-rotas; do PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php | tail -1; done
```
Expected: "FALHAS: 0" em todos.

- [ ] **Step 6: Sintaxe e commit**

```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/Painel/ComandaDaCentral.php api/app/Http/Controllers/Entregas/PedidoAbertoController.php api/app/Http/Controllers/Entregas/MotoboyController.php api/app/Http/Controllers/Entregas/PortalLojaController.php
git add api/app/Support/Entregas/Painel/ComandaDaCentral.php api/app/Http/Controllers/Entregas/PedidoAbertoController.php api/app/Http/Controllers/Entregas/MotoboyController.php api/app/Http/Controllers/Entregas/PortalLojaController.php scripts/teste-php/comanda-do-pedido.php scripts/teste-php/chamar-motoboy.php
git commit -m "Chamar motoboy: app, comanda e detalhe do portal leem a cobrança e a observação

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Parte B: front (portal da loja)

### Tarefa 8: Funções puras do painel

**Files:**
- Create: `packages/customer-portal/addon/utils/chamar-motoboy.js`
- Create: `scripts/teste-portal/chamar-motoboy.test.mjs`

- [ ] **Step 1: Escrever o teste que falha**

`scripts/teste-portal/chamar-motoboy.test.mjs`:

```js
// Painel "Chamar motoboy" do portal da loja (packages/customer-portal/addon/utils/chamar-motoboy.js): telefone (a regra
// do servidor, ChamadoDaLoja::telefone), valor em centavos, troco, problema de cada passo, corpo da requisição e endereço.
// Uso, na raiz do repo: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/chamar-motoboy.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    digitosDoTelefone,
    telefoneValido,
    formatarTelefone,
    centavosDoTexto,
    formatarReais,
    trocoDoMotoboy,
    semNumero,
    pontoValido,
    pontoDoEndereco,
    problemaDoPasso,
    corpoDoChamado,
    linhaDoEndereco,
    enderecoDaSugestao,
    enderecoDoCliente,
    novaChave,
} from '../../packages/customer-portal/addon/utils/chamar-motoboy.js';

const estado = (campos = {}) => ({
    passo: 'pagamento',
    chave: '6f1c0e8a-2b1d-4c3e-9a7f-0d5b8c2e4f10',
    telefone: '(16) 99999-0000',
    nome: ' Mariana Souza ',
    endereco: { rua: 'Rua Olinda, 45', bairro: 'Centro', cidade: 'Ribeirão Preto', uf: 'SP', cep: '14010-000', latitude: -21.18, longitude: -47.81 },
    complemento: 'Ap 12',
    referencia: '',
    pago: false,
    valor: '58,90',
    trocoPara: '100',
    forma: 'dinheiro',
    observacao: ' Tocar o interfone ',
    ...campos,
});

test('telefone: a mesma regra do servidor', () => {
    assert.equal(digitosDoTelefone('+55 (16) 99999-0000'), '16999990000');
    assert.equal(digitosDoTelefone('016 99999-0000'), '16999990000');
    assert.equal(digitosDoTelefone('55999990000'), '55999990000');
    assert.equal(telefoneValido('(16) 3333-0000'), true);
    assert.equal(telefoneValido('99999-0000'), false);
    assert.equal(telefoneValido('0999990000'), false);
    assert.equal(formatarTelefone('16999990000'), '(16) 99999-0000');
    assert.equal(formatarTelefone('1633330000'), '(16) 3333-0000');
    assert.equal(formatarTelefone('123'), '123');
});

test('valor em centavos e em reais', () => {
    assert.equal(centavosDoTexto('58,90'), 5890);
    assert.equal(centavosDoTexto('R$ 1.234,56'), 123456);
    assert.equal(centavosDoTexto('58.9'), 5890);
    assert.equal(centavosDoTexto('100'), 10000);
    assert.equal(centavosDoTexto(''), null);
    assert.equal(centavosDoTexto('abc'), null);
    assert.equal(centavosDoTexto('-5'), null);
    assert.equal(formatarReais(4110), 'R$ 41,10');
    assert.equal(formatarReais(123456), 'R$ 1.234,56');
});

test('troco do motoboy só quando o troco é maior que o valor', () => {
    assert.equal(trocoDoMotoboy(5890, 10000), 4110);
    assert.equal(trocoDoMotoboy(5890, 5890), null);
    assert.equal(trocoDoMotoboy(5890, 5000), null);
    assert.equal(trocoDoMotoboy(null, 10000), null);
});

test('rua sem número e ponto', () => {
    assert.equal(semNumero('Rua Olinda'), true);
    assert.equal(semNumero('Rua Olinda, 45'), false);
    assert.equal(semNumero(''), false);
    assert.equal(pontoValido(-21.18, -47.81), true);
    assert.equal(pontoValido(0, 0), false);
    assert.equal(pontoValido(null, null), false);
    assert.deepEqual(pontoDoEndereco({ location: { type: 'Point', coordinates: [-47.81, -21.18] } }), { latitude: -21.18, longitude: -47.81 });
    assert.deepEqual(pontoDoEndereco({ latitude: '-21.18', longitude: '-47.81' }), { latitude: -21.18, longitude: -47.81 });
    assert.equal(pontoDoEndereco({}), null);
});

test('problema de cada passo', () => {
    assert.equal(problemaDoPasso('cliente', estado({ telefone: '9999' })), 'telefone');
    assert.equal(problemaDoPasso('cliente', estado({ nome: '  ' })), 'nome');
    assert.equal(problemaDoPasso('cliente', estado()), null);
    assert.equal(problemaDoPasso('endereco', estado({ endereco: { rua: '', latitude: -21.18, longitude: -47.81 } })), 'rua');
    assert.equal(problemaDoPasso('endereco', estado({ endereco: { rua: 'Rua A, 1', latitude: null, longitude: null } })), 'ponto');
    assert.equal(problemaDoPasso('endereco', estado()), null);
    assert.equal(problemaDoPasso('pagamento', estado({ pago: null })), 'pago');
    assert.equal(problemaDoPasso('pagamento', estado({ pago: true, valor: '' })), null);
    assert.equal(problemaDoPasso('pagamento', estado({ valor: '0' })), 'valor');
    assert.equal(problemaDoPasso('pagamento', estado({ trocoPara: '50' })), 'troco');
    assert.equal(problemaDoPasso('pagamento', estado({ forma: 'pix', trocoPara: '50' })), null);
    assert.equal(problemaDoPasso('pagamento', estado({ trocoPara: '' })), null);
    assert.equal(problemaDoPasso('pagamento', estado()), null);
});

test('corpo da requisição', () => {
    assert.deepEqual(corpoDoChamado(estado()), {
        chave: '6f1c0e8a-2b1d-4c3e-9a7f-0d5b8c2e4f10',
        cliente: { nome: 'Mariana Souza', telefone: '16999990000' },
        entrega: { rua: 'Rua Olinda, 45', bairro: 'Centro', cidade: 'Ribeirão Preto', uf: 'SP', cep: '14010-000', latitude: -21.18, longitude: -47.81, complemento: 'Ap 12', referencia: null },
        cobranca: { forma: 'dinheiro', valor: '58,90', troco_para: '100' },
        observacao: 'Tocar o interfone',
    });
    assert.deepEqual(corpoDoChamado(estado({ pago: true })).cobranca, { forma: 'pago' });
    assert.deepEqual(corpoDoChamado(estado({ forma: 'cartao' })).cobranca, { forma: 'cartao', valor: '58,90' });
    assert.equal(corpoDoChamado(estado({ observacao: '' })).observacao, null);
});

test('endereço: linha, sugestão do Google e cliente que já pediu', () => {
    assert.equal(linhaDoEndereco({ rua: 'Rua Olinda, 45', bairro: 'Centro' }, 'Ap 12'), 'Rua Olinda, 45 · Ap 12 · Centro');
    assert.equal(linhaDoEndereco({ rua: 'Rua Olinda, 45' }, ''), 'Rua Olinda, 45');
    const sugestao = { street1: 'Rua Olinda, 45', neighborhood: 'Centro', city: 'Ribeirão Preto', province: 'SP', postal_code: '14010-000', location: { type: 'Point', coordinates: [-47.81, -21.18] } };
    assert.deepEqual(enderecoDaSugestao(sugestao, {}), { rua: 'Rua Olinda, 45', bairro: 'Centro', cidade: 'Ribeirão Preto', uf: 'SP', cep: '14010-000', latitude: -21.18, longitude: -47.81 });
    assert.deepEqual(enderecoDaSugestao({ street1: 'Rua B' }, { latitude: -21.2, longitude: -47.8 }), { rua: 'Rua B', bairro: '', cidade: '', uf: '', cep: '', latitude: -21.2, longitude: -47.8 });
    const cliente = { nome: 'Mariana', endereco: { rua: 'RUA OLINDA, 45', bairro: 'CENTRO', cidade: 'RIBEIRÃO PRETO', uf: 'SP', cep: '14010-000', complemento: 'Ap 12', referencia: null, latitude: -21.18, longitude: -47.81 } };
    assert.deepEqual(enderecoDoCliente(cliente), {
        endereco: { rua: 'RUA OLINDA, 45', bairro: 'CENTRO', cidade: 'RIBEIRÃO PRETO', uf: 'SP', cep: '14010-000', latitude: -21.18, longitude: -47.81 },
        complemento: 'Ap 12',
        referencia: '',
    });
    assert.equal(enderecoDoCliente({ nome: 'X', endereco: null }), null);
    assert.equal(enderecoDoCliente(null), null);
});

test('chave do chamado: uuid v4', () => {
    const chave = novaChave();
    assert.match(chave, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    assert.notEqual(novaChave(), chave);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/chamar-motoboy.test.mjs`
Expected: FAIL (o arquivo `chamar-motoboy.js` não existe).

- [ ] **Step 3: Implementar**

`packages/customer-portal/addon/utils/chamar-motoboy.js`:

```js
/**
 * Entregas: funções puras do painel "Chamar motoboy" do portal da loja (Portal::ChamarMotoboy; spec
 * docs/superpowers/specs/2026-10-28-chamar-motoboy-portal-design.md). Teste: scripts/teste-portal/chamar-motoboy.test.mjs.
 * O telefone segue a regra do servidor (App\Support\Entregas\Portal\ChamadoDaLoja::telefone): mudou lá, mude aqui.
 */

/** Espera entre a última tecla do telefone e a busca do cliente. */
export const ESPERA_DA_BUSCA_MS = 400;

/** Os mesmos limites do servidor (ChamadoDaLoja::LIMITES), no maxlength dos campos. */
export const LIMITES = { nome: 120, rua: 190, complemento: 190, referencia: 190, observacao: 500 };

/** Só os dígitos, com DDD: sem o 55 da frente (12 ou 13 dígitos) e sem o 0 do interurbano (11 ou 12). */
export function digitosDoTelefone(texto) {
    let digitos = String(texto ?? '').replace(/\D+/g, '');
    if (digitos.length >= 12 && digitos.startsWith('55')) digitos = digitos.slice(2);
    if ((digitos.length === 11 || digitos.length === 12) && digitos.startsWith('0')) digitos = digitos.slice(1);
    return digitos;
}

/** 10 (fixo) ou 11 (celular) dígitos, DDD sem 0 na frente. */
export function telefoneValido(texto) {
    const digitos = digitosDoTelefone(texto);
    return (digitos.length === 10 || digitos.length === 11) && digitos[0] !== '0';
}

export function formatarTelefone(texto) {
    const d = digitosDoTelefone(texto);
    if (d.length === 11) return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`;
    if (d.length === 10) return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`;
    return String(texto ?? '');
}

/** "58,90", "R$ 1.234,56", "58.9" ou 58.9 em centavos; vazio ou inválido = null. */
export function centavosDoTexto(texto) {
    if (typeof texto === 'number') return Number.isFinite(texto) && texto >= 0 ? Math.round(texto * 100) : null;
    let limpo = String(texto ?? '').replace(/R\$|\s/g, '');
    if (limpo === '') return null;
    if (limpo.includes(',')) limpo = limpo.replace(/\./g, '').replace(',', '.');
    if (!/^\d+(\.\d+)?$/.test(limpo)) return null;
    return Math.round(parseFloat(limpo) * 100);
}

export function formatarReais(centavos) {
    const n = Math.round(Number(centavos) || 0);
    const reais = Math.floor(Math.abs(n) / 100)
        .toString()
        .replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    const resto = String(Math.abs(n) % 100).padStart(2, '0');
    return `${n < 0 ? '-' : ''}R$ ${reais},${resto}`;
}

/** O que o motoboy leva de troco (troco para − valor), só quando o troco é maior que o valor. */
export function trocoDoMotoboy(valorCentavos, trocoParaCentavos) {
    if (!Number.isInteger(valorCentavos) || !Number.isInteger(trocoParaCentavos) || trocoParaCentavos <= valorCentavos) return null;
    return trocoParaCentavos - valorCentavos;
}

/** Rua digitada sem nenhum número (o Google às vezes devolve só a rua). */
export function semNumero(rua) {
    const texto = String(rua ?? '').trim();
    return texto !== '' && !/\d/.test(texto);
}

export function pontoValido(latitude, longitude) {
    const lat = typeof latitude === 'number' ? latitude : NaN;
    const lng = typeof longitude === 'number' ? longitude : NaN;
    return Number.isFinite(lat) && Number.isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180 && (Math.abs(lat) > 0.0001 || Math.abs(lng) > 0.0001);
}

/** {latitude, longitude} do GeoJSON do Google (`location.coordinates` = [lng, lat]) ou dos campos soltos; senão null. */
export function pontoDoEndereco(endereco) {
    const coordenadas = endereco?.location?.coordinates;
    if (Array.isArray(coordenadas) && coordenadas.length === 2) {
        const [lng, lat] = coordenadas.map(Number);
        if (pontoValido(lat, lng)) return { latitude: lat, longitude: lng };
    }
    const lat = Number(endereco?.latitude);
    const lng = Number(endereco?.longitude);
    return endereco?.latitude != null && endereco?.longitude != null && pontoValido(lat, lng) ? { latitude: lat, longitude: lng } : null;
}

/** O que impede de seguir no passo (null = pode): telefone, nome, rua, ponto, pago, valor ou troco. */
export function problemaDoPasso(passo, e) {
    if (passo === 'cliente') {
        if (!telefoneValido(e.telefone)) return 'telefone';
        if (!String(e.nome ?? '').trim()) return 'nome';
        return null;
    }
    if (passo === 'endereco') {
        if (!String(e.endereco?.rua ?? '').trim()) return 'rua';
        if (!pontoValido(e.endereco?.latitude, e.endereco?.longitude)) return 'ponto';
        return null;
    }
    if (passo === 'pagamento') {
        if (e.pago !== true && e.pago !== false) return 'pago';
        if (e.pago) return null;
        const valor = centavosDoTexto(e.valor);
        if (!valor) return 'valor';
        if (e.forma === 'dinheiro' && String(e.trocoPara ?? '').trim() !== '') {
            const troco = centavosDoTexto(e.trocoPara);
            if (troco === null || (troco !== 0 && troco < valor)) return 'troco';
        }
        return null;
    }
    return null;
}

/** O corpo do POST entregas/loja/chamar-motoboy. */
export function corpoDoChamado(e) {
    const texto = (valor) => String(valor ?? '').trim();
    const ouNull = (valor) => texto(valor) || null;
    const cobrar = e.pago === false;
    const cobranca = cobrar ? { forma: e.forma, valor: texto(e.valor) } : { forma: 'pago' };
    if (cobrar && e.forma === 'dinheiro' && texto(e.trocoPara)) cobranca.troco_para = texto(e.trocoPara);

    return {
        chave: e.chave,
        cliente: { nome: texto(e.nome), telefone: digitosDoTelefone(e.telefone) },
        entrega: {
            rua: texto(e.endereco.rua),
            bairro: ouNull(e.endereco.bairro),
            cidade: ouNull(e.endereco.cidade),
            uf: ouNull(e.endereco.uf),
            cep: ouNull(e.endereco.cep),
            latitude: e.endereco.latitude,
            longitude: e.endereco.longitude,
            complemento: ouNull(e.complemento),
            referencia: ouNull(e.referencia),
        },
        cobranca,
        observacao: ouNull(e.observacao),
    };
}

/** "Rua Olinda, 45 · Ap 12 · Centro". */
export function linhaDoEndereco(endereco, complemento) {
    return [endereco?.rua, complemento, endereco?.bairro]
        .map((parte) => String(parte ?? '').trim())
        .filter(Boolean)
        .join(' · ');
}

/** O endereço do painel a partir da sugestão escolhida no EnderecoGoogleInput (sem ponto, fica o que havia). */
export function enderecoDaSugestao(sugestao, atual = {}) {
    const ponto = pontoDoEndereco(sugestao);
    return {
        rua: sugestao?.street1 ?? atual?.rua ?? '',
        bairro: sugestao?.neighborhood ?? '',
        cidade: sugestao?.city ?? '',
        uf: sugestao?.province ?? '',
        cep: sugestao?.postal_code ?? '',
        latitude: ponto?.latitude ?? atual?.latitude ?? null,
        longitude: ponto?.longitude ?? atual?.longitude ?? null,
    };
}

/** O último endereço do "Já pediu antes" (loja/clientes) no formato do painel; sem ponto, null. */
export function enderecoDoCliente(cliente) {
    const e = cliente?.endereco;
    const ponto = e ? pontoDoEndereco(e) : null;
    if (!ponto) return null;
    return {
        endereco: { rua: e.rua ?? '', bairro: e.bairro ?? '', cidade: e.cidade ?? '', uf: e.uf ?? '', cep: e.cep ?? '', ...ponto },
        complemento: e.complemento ?? '',
        referencia: e.referencia ?? '',
    };
}

/** uuid v4 do chamado (a repetição pela rede devolve o mesmo pedido). */
export function novaChave() {
    if (typeof globalThis.crypto?.randomUUID === 'function') return globalThis.crypto.randomUUID();
    const bytes = new Uint8Array(16);
    globalThis.crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const h = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/chamar-motoboy.test.mjs`
Expected: todos os testes passam.

- [ ] **Step 5: Parse e commit**

```bash
node -e "const p=require(require('path').resolve('console/node_modules/.pnpm/@babel+parser@7.29.8/node_modules/@babel/parser'));for(const f of process.argv.slice(1)){p.parse(require('fs').readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties']});console.log('ok',f)}" packages/customer-portal/addon/utils/chamar-motoboy.js
git add packages/customer-portal/addon/utils/chamar-motoboy.js scripts/teste-portal/chamar-motoboy.test.mjs
git commit -m "Portal da loja: funções puras do chamar motoboy

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Tarefa 9: Textos (pt-BR e en-US)

**Files:**
- Modify: `packages/customer-portal/translations/pt-br.yaml` e `en-us.yaml` (filho de `customer-portal.ui.entregas`)

- [ ] **Step 1: Acrescentar os textos**

Nos dois arquivos, dentro de `customer-portal:` → `ui:` → `entregas:` (a linha `    entregas:`; os filhos ficam com 6
espaços), acrescente o bloco `chamar:` (mantenha a indentação de 6 espaços na primeira linha).

`pt-br.yaml`:

```yaml
      chamar:
        titulo: Chamar motoboy
        fechar: Fechar
        passo-de: "Passo {atual} de 3"
        passos:
          cliente: Cliente
          endereco: Endereço
          pagamento: Pagamento
        continuar: Continuar
        voltar: Voltar
        chamar: Chamar motoboy
        chamando: Chamando…
        cliente:
          pergunta: Para quem é a entrega?
          dica: "Comece pelo telefone: se o cliente já pediu, o endereço vem junto."
          telefone: Telefone
          telefone-placeholder: (16) 99999-0000
          telefone-invalido: Digite o telefone com DDD.
          buscando: Procurando o cliente…
          ja-pediu: "Já pediu antes · {pedidos, plural, one {# pedido} other {# pedidos}}"
          usar: Usar e ir ao pagamento
          outro-endereco: Outro endereço
          nome: Nome
          nome-placeholder: Nome do cliente
        endereco:
          pergunta: Onde entregar?
          rua: Rua e número
          rua-placeholder: Digite a rua e o número
          sem-numero: Confira o número da casa.
          mapa-dica: Arraste o mapa para ajustar o ponto da entrega.
          sem-ponto: Escolha uma sugestão ou arraste o mapa até o local da entrega.
          complemento: Complemento
          complemento-placeholder: Apto, bloco, casa
          referencia: Referência
          referencia-placeholder: Perto de…
        pagamento:
          alterar: Alterar
          pergunta: O cliente já pagou?
          ja-pago: Já pago
          cobrar: Cobrar na entrega
          valor: Valor a cobrar
          troco-para: Troco para
          forma: Forma
          dinheiro: Dinheiro
          cartao: Cartão
          pix: Pix
          troco-do-motoboy: "O motoboy leva de troco {valor}"
          troco-menor: O troco precisa ser para um valor maior que o da cobrança.
          observacao: Observação para o motoboy
          observacao-placeholder: "Ex.: tocar o interfone, bolo frágil"
        procurando:
          titulo: Procurando motoboy
          pedido: Pedido
          cliente: Cliente
          endereco: Endereço
          cobranca: Cobrança
          ja-pago: Já pago
          dica: Dá para cancelar até o motoboy sair para a entrega.
          ver-no-mapa: Ver no mapa
          novo: Novo pedido
```

`en-us.yaml` (mesmas chaves):

```yaml
      chamar:
        titulo: Call a rider
        fechar: Close
        passo-de: "Step {atual} of 3"
        passos:
          cliente: Customer
          endereco: Address
          pagamento: Payment
        continuar: Continue
        voltar: Back
        chamar: Call a rider
        chamando: Calling…
        cliente:
          pergunta: Who is the delivery for?
          dica: "Start with the phone: if the customer ordered before, the address comes along."
          telefone: Phone
          telefone-placeholder: (16) 99999-0000
          telefone-invalido: Type the phone with the area code.
          buscando: Looking up the customer…
          ja-pediu: "Ordered before · {pedidos, plural, one {# order} other {# orders}}"
          usar: Use it and go to payment
          outro-endereco: Another address
          nome: Name
          nome-placeholder: Customer name
        endereco:
          pergunta: Where to deliver?
          rua: Street and number
          rua-placeholder: Type the street and number
          sem-numero: Check the house number.
          mapa-dica: Drag the map to adjust the delivery point.
          sem-ponto: Pick a suggestion or drag the map to the delivery spot.
          complemento: Complement
          complemento-placeholder: Apt, block, house
          referencia: Landmark
          referencia-placeholder: Near…
        pagamento:
          alterar: Change
          pergunta: Has the customer already paid?
          ja-pago: Already paid
          cobrar: Collect on delivery
          valor: Amount to collect
          troco-para: Change for
          forma: Method
          dinheiro: Cash
          cartao: Card
          pix: Pix
          troco-do-motoboy: "The rider takes {valor} in change"
          troco-menor: Change must be for an amount greater than the charge.
          observacao: Note for the rider
          observacao-placeholder: "E.g.: ring the intercom, fragile cake"
        procurando:
          titulo: Looking for a rider
          pedido: Order
          cliente: Customer
          endereco: Address
          cobranca: Collect
          ja-pago: Already paid
          dica: You can cancel until the rider leaves for the delivery.
          ver-no-mapa: See on the map
          novo: New order
```

- [ ] **Step 2: Conferir o YAML**

Run: `node -e "const y=require(require('path').resolve('console/node_modules/js-yaml'));for(const f of ['pt-br','en-us']){const d=y.load(require('fs').readFileSync('packages/customer-portal/translations/'+f+'.yaml','utf8'));console.log(f,Object.keys(d['customer-portal'].ui.entregas.chamar).length)}"`
Expected: `pt-br 13` e `en-us 13` (se o `js-yaml` não estiver em `console/node_modules`, procure com
`ls console/node_modules/.pnpm | grep js-yaml` e use o caminho de lá).

- [ ] **Step 3: Commit**

```bash
git add packages/customer-portal/translations/pt-br.yaml packages/customer-portal/translations/en-us.yaml
git commit -m "Portal da loja: textos do chamar motoboy

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

(O `i18n-check` roda na Tarefa 11, quando o template usar as chaves.)

### Tarefa 10: Mapa do ponto (`Portal::ChamarMotoboy::Ponto`)

**Files:**
- Create: `packages/customer-portal/addon/components/portal/chamar-motoboy/ponto.js`, `ponto.hbs`
- Create: `packages/customer-portal/app/components/portal/chamar-motoboy/ponto.js`

- [ ] **Step 1: Componente**

`packages/customer-portal/addon/components/portal/chamar-motoboy/ponto.js`:

```js
import Component from '@glimmer/component';
import { action } from '@ember/object';
import { pontoValido } from '../../../utils/chamar-motoboy';

/**
 * Entregas: o mapa pequeno do passo 2 do "Chamar motoboy", sempre à vista, com o alfinete fixo no centro (o mecanismo do
 * CoordinatesInput do ember-ui com @mapCenter). Abre no ponto escolhido ou, sem ele, na loja (@centro). Só o gesto da
 * loja move o ponto (@onDragend): o moveend dispara também no carregamento e poria o ponto na loja. Roda e pinça dão zoom
 * em torno do centro, então o zoom não mexe no ponto.
 * Argumentos: @latitude, @longitude (o ponto ou null), @centro ({latitude, longitude} da loja), @onMudou({latitude, longitude}).
 */
export default class PortalChamarMotoboyPontoComponent extends Component {
    get temPonto() {
        return pontoValido(this.args.latitude, this.args.longitude);
    }

    get latitude() {
        return this.temPonto ? this.args.latitude : this.args.centro?.latitude ?? null;
    }

    get longitude() {
        return this.temPonto ? this.args.longitude : this.args.centro?.longitude ?? null;
    }

    @action arrastou({ target }) {
        const centro = target?.getCenter?.();
        if (centro && typeof this.args.onMudou === 'function') {
            this.args.onMudou({ latitude: centro.lat, longitude: centro.lng });
        }
    }
}
```

`packages/customer-portal/addon/components/portal/chamar-motoboy/ponto.hbs`:

```hbs
{{! Entregas: mapa do ponto da entrega no "Chamar motoboy" (ver o JS) }}
<div class="ps-cm-mapa {{unless this.temPonto 'is-sem-ponto'}}">
    {{#if this.latitude}}
        <LeafletMap
            class="ps-cm-leaflet"
            @lat={{this.latitude}}
            @lng={{this.longitude}}
            @zoom={{16}}
            @zoomControl={{true}}
            @doubleClickZoom={{false}}
            @scrollWheelZoom="center"
            @touchZoom="center"
            @onDragend={{this.arrastou}}
            as |layers|
        >
            <layers.tile @url="https://tile.openstreetmap.org/{z}/{x}/{y}.png" />
        </LeafletMap>
        <span class="ps-cm-pino" aria-hidden="true"><FaIcon @icon="location-dot" /></span>
    {{/if}}
</div>
```

`packages/customer-portal/app/components/portal/chamar-motoboy/ponto.js`:

```js
export { default } from '@fleetbase/customer-portal-engine/components/portal/chamar-motoboy/ponto';
```

- [ ] **Step 2: Parse e commit**

```bash
node -e "const p=require(require('path').resolve('console/node_modules/.pnpm/@babel+parser@7.29.8/node_modules/@babel/parser'));for(const f of process.argv.slice(1)){p.parse(require('fs').readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties']});console.log('ok',f)}" packages/customer-portal/addon/components/portal/chamar-motoboy/ponto.js packages/customer-portal/app/components/portal/chamar-motoboy/ponto.js
git add packages/customer-portal/addon/components/portal/chamar-motoboy/ packages/customer-portal/app/components/portal/chamar-motoboy/
git commit -m "Portal da loja: mapa do ponto da entrega no chamar motoboy

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Tarefa 11: Painel `Portal::ChamarMotoboy` na rota do novo pedido

**Files:**
- Create: `packages/customer-portal/addon/components/portal/chamar-motoboy.js`, `chamar-motoboy.hbs`
- Create: `packages/customer-portal/app/components/portal/chamar-motoboy.js`
- Modify: `packages/customer-portal/addon/routes/portal/orders/new.js`, `addon/templates/portal/orders/new.hbs`
- Modify: `packages/customer-portal/addon/styles/customer-portal-engine.css` (bloco novo no fim)

- [ ] **Step 1: Componente (JS)**

`packages/customer-portal/addon/components/portal/chamar-motoboy.js`:

```js
import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task, timeout } from 'ember-concurrency';
import {
    ESPERA_DA_BUSCA_MS,
    LIMITES,
    centavosDoTexto,
    corpoDoChamado,
    digitosDoTelefone,
    enderecoDaSugestao,
    enderecoDoCliente,
    formatarReais,
    formatarTelefone,
    linhaDoEndereco,
    novaChave,
    pontoValido,
    problemaDoPasso,
    semNumero,
    telefoneValido,
    trocoDoMotoboy,
} from '../../utils/chamar-motoboy';

/** O endereço com campos @tracked: o EnderecoGoogleInput liga o @value da rua em duas vias. */
class EnderecoDoChamado {
    @tracked rua = '';
    @tracked bairro = '';
    @tracked cidade = '';
    @tracked uf = '';
    @tracked cep = '';
    @tracked latitude = null;
    @tracked longitude = null;

    constructor(dados = {}) {
        Object.assign(this, dados);
    }

    get dados() {
        return { rua: this.rua, bairro: this.bairro, cidade: this.cidade, uf: this.uf, cep: this.cep, latitude: this.latitude, longitude: this.longitude };
    }
}

const NUMERO_DO_PASSO = { cliente: 1, endereco: 2, pagamento: 3 };

/**
 * Entregas: painel "Chamar motoboy" do portal da loja (rota portal.orders.new; spec
 * docs/superpowers/specs/2026-10-28-chamar-motoboy-portal-design.md). Passos cliente → endereço → pagamento e a tela
 * "Procurando motoboy". O cliente que já pediu vem do GET entregas/loja/clientes; o pedido sai do POST
 * entregas/loja/chamar-motoboy com a chave do painel (a repetição pela rede devolve o mesmo pedido; o "Novo pedido"
 * troca a chave). Funções puras em utils/chamar-motoboy.js. Argumento: @loja (loja/minha-loja: o centro do mapa).
 */
export default class PortalChamarMotoboyComponent extends Component {
    @service fetch;
    @service notifications;
    @service hostRouter;
    @service customerPortalOrderCreation;

    LIMITES = LIMITES;

    @tracked passo = 'cliente';
    @tracked chave = novaChave();
    @tracked telefone = '';
    @tracked nome = '';
    @tracked endereco = new EnderecoDoChamado();
    @tracked complemento = '';
    @tracked referencia = '';
    @tracked pago = null;
    @tracked valor = '';
    @tracked trocoPara = '';
    @tracked forma = 'dinheiro';
    @tracked observacao = '';
    /** O "Já pediu antes" (loja/clientes), ou null. */
    @tracked cliente = null;
    /** A resposta do chamar-motoboy (a tela "Procurando motoboy"). */
    @tracked chamado = null;

    get estado() {
        return {
            passo: this.passo,
            chave: this.chave,
            telefone: this.telefone,
            nome: this.nome,
            endereco: this.endereco.dados,
            complemento: this.complemento,
            referencia: this.referencia,
            pago: this.pago,
            valor: this.valor,
            trocoPara: this.trocoPara,
            forma: this.forma,
            observacao: this.observacao,
        };
    }

    get numeroDoPasso() {
        return NUMERO_DO_PASSO[this.passo] ?? 3;
    }

    get passoFeitoCliente() {
        return this.numeroDoPasso > 1;
    }

    get passoFeitoEndereco() {
        return this.numeroDoPasso > 2;
    }

    get problema() {
        return problemaDoPasso(this.passo, this.estado);
    }

    get podeContinuar() {
        return this.problema === null;
    }

    get avisoDoTelefone() {
        return digitosDoTelefone(this.telefone).length >= 8 && !telefoneValido(this.telefone);
    }

    get telefoneFormatado() {
        return formatarTelefone(this.telefone);
    }

    get centroDoMapa() {
        const coleta = this.args.loja?.coleta;
        const latitude = Number(coleta?.latitude);
        const longitude = Number(coleta?.longitude);
        return coleta && pontoValido(latitude, longitude) ? { latitude, longitude } : null;
    }

    get temPonto() {
        return pontoValido(this.endereco.latitude, this.endereco.longitude);
    }

    get ruaSemNumero() {
        return semNumero(this.endereco.rua);
    }

    get linhaDoEndereco() {
        return linhaDoEndereco(this.endereco, this.complemento);
    }

    get linhaDoEnderecoDoCliente() {
        const e = this.cliente?.endereco;
        return e ? linhaDoEndereco(e, e.complemento) : null;
    }

    get podeUsarEnderecoDoCliente() {
        return enderecoDoCliente(this.cliente) !== null;
    }

    get cobrar() {
        return this.pago === false;
    }

    get emDinheiro() {
        return this.forma === 'dinheiro';
    }

    get trocoDoMotoboy() {
        if (!this.cobrar || !this.emDinheiro) return null;
        const troco = trocoDoMotoboy(centavosDoTexto(this.valor), centavosDoTexto(this.trocoPara));
        return troco === null ? null : formatarReais(troco);
    }

    @action aoDigitarTelefone(evento) {
        this.cliente = null;
        this.buscarCliente.perform(evento?.target?.value ?? this.telefone);
    }

    /** Pergunta quem é o telefone depois da última tecla; sem resposta, a loja segue digitando (sem aviso). */
    @task({ restartable: true }) *buscarCliente(texto) {
        const digitos = digitosDoTelefone(texto);
        if (!telefoneValido(digitos)) return;
        yield timeout(ESPERA_DA_BUSCA_MS);
        try {
            const resposta = yield this.fetch.get('entregas/loja/clientes', { telefone: digitos });
            this.cliente = resposta?.cliente ?? null;
            if (this.cliente?.nome && !String(this.nome).trim()) {
                this.nome = this.cliente.nome;
            }
        } catch {
            this.cliente = null;
        }
    }

    @action usarEnderecoDoCliente() {
        const salvo = enderecoDoCliente(this.cliente);
        if (!salvo) return;
        this.endereco = new EnderecoDoChamado(salvo.endereco);
        this.complemento = salvo.complemento;
        this.referencia = salvo.referencia;
        if (!String(this.nome).trim()) this.nome = this.cliente.nome ?? '';
        this.passo = 'pagamento';
    }

    @action outroEndereco() {
        if (!String(this.nome).trim()) this.nome = this.cliente?.nome ?? '';
        this.endereco = new EnderecoDoChamado();
        this.complemento = '';
        this.referencia = '';
        this.passo = 'endereco';
    }

    @action continuar() {
        if (!this.podeContinuar) return;
        this.passo = this.passo === 'cliente' ? 'endereco' : 'pagamento';
    }

    @action voltar() {
        this.passo = this.passo === 'pagamento' ? 'endereco' : 'cliente';
    }

    @action irPara(passo) {
        this.passo = passo;
    }

    @action escolherSugestao(sugestao) {
        this.endereco = new EnderecoDoChamado(enderecoDaSugestao(sugestao, this.endereco.dados));
    }

    @action moverPonto({ latitude, longitude }) {
        this.endereco.latitude = latitude;
        this.endereco.longitude = longitude;
    }

    @action escolherPago(pago) {
        this.pago = pago;
    }

    @action escolherForma(forma) {
        this.forma = forma;
    }

    /** O segundo toque é descartado; o servidor confere de novo e o 422 traz a frase pronta. */
    @task({ drop: true }) *chamar() {
        if (this.problema !== null) return;
        try {
            const resposta = yield this.fetch.post('entregas/loja/chamar-motoboy', corpoDoChamado(this.estado));
            this.chamado = resposta?.pedido ?? null;
            this.passo = 'chamado';
        } catch (erro) {
            this.notifications.serverError(erro);
        }
    }

    @action verNoMapa() {
        if (this.chamado?.id) {
            this.hostRouter.transitionTo('customer-portal.portal.orders.details', this.chamado.id);
        }
    }

    @action novoPedido() {
        this.passo = 'cliente';
        this.chave = novaChave();
        this.telefone = '';
        this.nome = '';
        this.endereco = new EnderecoDoChamado();
        this.complemento = '';
        this.referencia = '';
        this.pago = null;
        this.valor = '';
        this.trocoPara = '';
        this.forma = 'dinheiro';
        this.observacao = '';
        this.cliente = null;
        this.chamado = null;
    }

    @action fechar() {
        this.customerPortalOrderCreation.reset();
        this.hostRouter.transitionTo('customer-portal.portal.orders.index');
    }
}
```

- [ ] **Step 2: Componente (template)**

`packages/customer-portal/addon/components/portal/chamar-motoboy.hbs`:

```hbs
{{! Entregas: "Chamar motoboy" em 3 passos e o "Procurando motoboy" (ver o JS) }}
<div class="ps-cm">
    <header class="ps-cm-topo">
        <h2 class="ps-cm-titulo">{{t "customer-portal.ui.entregas.chamar.titulo"}}</h2>
        <button type="button" class="ps-cm-fechar" aria-label={{t "customer-portal.ui.entregas.chamar.fechar"}} {{on "click" this.fechar}}><FaIcon @icon="xmark" /></button>
    </header>

    {{#if (eq this.passo "chamado")}}
        <div class="ps-cm-corpo ps-cm-procurando">
            <div class="ps-cm-radar" aria-hidden="true"><FaIcon @icon="motorcycle" /></div>
            <h3 class="ps-cm-pergunta">{{t "customer-portal.ui.entregas.chamar.procurando.titulo"}}</h3>
            {{#if this.chamado}}
                <dl class="ps-cm-resumo">
                    <div><dt>{{t "customer-portal.ui.entregas.chamar.procurando.pedido"}}</dt><dd>{{this.chamado.numero}}</dd></div>
                    <div><dt>{{t "customer-portal.ui.entregas.chamar.procurando.cliente"}}</dt><dd>{{this.chamado.cliente}}</dd></div>
                    {{#if this.chamado.endereco}}
                        <div><dt>{{t "customer-portal.ui.entregas.chamar.procurando.endereco"}}</dt><dd>{{this.chamado.endereco}}</dd></div>
                    {{/if}}
                    <div><dt>{{t "customer-portal.ui.entregas.chamar.procurando.cobranca"}}</dt><dd>{{or this.chamado.cobranca (t "customer-portal.ui.entregas.chamar.procurando.ja-pago")}}</dd></div>
                </dl>
            {{/if}}
            <p class="ps-cm-dica">{{t "customer-portal.ui.entregas.chamar.procurando.dica"}}</p>
        </div>
        <footer class="ps-cm-rodape">
            <button type="button" class="ps-btn" {{on "click" this.verNoMapa}}><FaIcon @icon="map-location-dot" /><span>{{t "customer-portal.ui.entregas.chamar.procurando.ver-no-mapa"}}</span></button>
            <button type="button" class="ps-btn ps-btn-marca" {{on "click" this.novoPedido}}><FaIcon @icon="plus" /><span>{{t "customer-portal.ui.entregas.chamar.procurando.novo"}}</span></button>
        </footer>
    {{else}}
        <ol class="ps-cm-passos" aria-label={{t "customer-portal.ui.entregas.chamar.passo-de" atual=this.numeroDoPasso}}>
            <li class="{{if (eq this.passo 'cliente') 'is-atual'}} {{if this.passoFeitoCliente 'is-feito'}}">{{t "customer-portal.ui.entregas.chamar.passos.cliente"}}</li>
            <li class="{{if (eq this.passo 'endereco') 'is-atual'}} {{if this.passoFeitoEndereco 'is-feito'}}">{{t "customer-portal.ui.entregas.chamar.passos.endereco"}}</li>
            <li class="{{if (eq this.passo 'pagamento') 'is-atual'}}">{{t "customer-portal.ui.entregas.chamar.passos.pagamento"}}</li>
        </ol>

        <div class="ps-cm-corpo">
            {{#if (eq this.passo "cliente")}}
                <h3 class="ps-cm-pergunta">{{t "customer-portal.ui.entregas.chamar.cliente.pergunta"}}</h3>
                <p class="ps-cm-dica">{{t "customer-portal.ui.entregas.chamar.cliente.dica"}}</p>
                <label class="ps-cm-campo">
                    <span>{{t "customer-portal.ui.entregas.chamar.cliente.telefone"}}</span>
                    <Input @type="tel" @value={{this.telefone}} inputmode="tel" autocomplete="off" maxlength="20" placeholder={{t "customer-portal.ui.entregas.chamar.cliente.telefone-placeholder"}} {{on "input" this.aoDigitarTelefone}} />
                </label>
                {{#if this.avisoDoTelefone}}
                    <p class="ps-cm-aviso">{{t "customer-portal.ui.entregas.chamar.cliente.telefone-invalido"}}</p>
                {{/if}}
                {{#if this.buscarCliente.isRunning}}
                    <p class="ps-cm-dica">{{t "customer-portal.ui.entregas.chamar.cliente.buscando"}}</p>
                {{/if}}
                {{#if this.cliente}}
                    <div class="ps-cm-cliente">
                        <p class="ps-cm-cliente-selo"><FaIcon @icon="clock-rotate-left" /> {{t "customer-portal.ui.entregas.chamar.cliente.ja-pediu" pedidos=this.cliente.pedidos}}</p>
                        <p class="ps-cm-cliente-nome">{{this.cliente.nome}}</p>
                        {{#if this.linhaDoEnderecoDoCliente}}
                            <p>{{this.linhaDoEnderecoDoCliente}}</p>
                        {{/if}}
                        {{#if this.cliente.endereco.referencia}}
                            <p class="ps-cm-dica">{{this.cliente.endereco.referencia}}</p>
                        {{/if}}
                        <div class="ps-cm-cliente-acoes">
                            {{#if this.podeUsarEnderecoDoCliente}}
                                <button type="button" class="ps-btn ps-btn-marca" {{on "click" this.usarEnderecoDoCliente}}>{{t "customer-portal.ui.entregas.chamar.cliente.usar"}}</button>
                            {{/if}}
                            <button type="button" class="ps-btn" {{on "click" this.outroEndereco}}>{{t "customer-portal.ui.entregas.chamar.cliente.outro-endereco"}}</button>
                        </div>
                    </div>
                {{/if}}
                <label class="ps-cm-campo">
                    <span>{{t "customer-portal.ui.entregas.chamar.cliente.nome"}}</span>
                    <Input @value={{this.nome}} maxlength={{this.LIMITES.nome}} autocomplete="off" placeholder={{t "customer-portal.ui.entregas.chamar.cliente.nome-placeholder"}} />
                </label>
            {{else if (eq this.passo "endereco")}}
                <h3 class="ps-cm-pergunta">{{t "customer-portal.ui.entregas.chamar.endereco.pergunta"}}</h3>
                <p class="ps-cm-dica">{{this.nome}} · {{this.telefoneFormatado}}</p>
                <div class="ps-cm-campo">
                    <span>{{t "customer-portal.ui.entregas.chamar.endereco.rua"}}</span>
                    <EnderecoGoogleInput @value={{this.endereco.rua}} @onSelect={{this.escolherSugestao}} @placeholder={{t "customer-portal.ui.entregas.chamar.endereco.rua-placeholder"}} />
                </div>
                {{#if this.ruaSemNumero}}
                    <p class="ps-cm-aviso">{{t "customer-portal.ui.entregas.chamar.endereco.sem-numero"}}</p>
                {{/if}}
                <Portal::ChamarMotoboy::Ponto @latitude={{this.endereco.latitude}} @longitude={{this.endereco.longitude}} @centro={{this.centroDoMapa}} @onMudou={{this.moverPonto}} />
                <p class="ps-cm-dica">
                    {{#if this.temPonto}}{{t "customer-portal.ui.entregas.chamar.endereco.mapa-dica"}}{{else}}{{t "customer-portal.ui.entregas.chamar.endereco.sem-ponto"}}{{/if}}
                </p>
                <div class="ps-cm-duplo">
                    <label class="ps-cm-campo">
                        <span>{{t "customer-portal.ui.entregas.chamar.endereco.complemento"}}</span>
                        <Input @value={{this.complemento}} maxlength={{this.LIMITES.complemento}} autocomplete="off" placeholder={{t "customer-portal.ui.entregas.chamar.endereco.complemento-placeholder"}} />
                    </label>
                    <label class="ps-cm-campo">
                        <span>{{t "customer-portal.ui.entregas.chamar.endereco.referencia"}}</span>
                        <Input @value={{this.referencia}} maxlength={{this.LIMITES.referencia}} autocomplete="off" placeholder={{t "customer-portal.ui.entregas.chamar.endereco.referencia-placeholder"}} />
                    </label>
                </div>
            {{else}}
                <div class="ps-cm-resumo-cliente">
                    <div>
                        <strong>{{this.nome}}</strong> · {{this.telefoneFormatado}}
                        <p>{{this.linhaDoEndereco}}</p>
                    </div>
                    <button type="button" class="ps-cm-link" {{on "click" (fn this.irPara "endereco")}}>{{t "customer-portal.ui.entregas.chamar.pagamento.alterar"}}</button>
                </div>
                <h3 class="ps-cm-pergunta">{{t "customer-portal.ui.entregas.chamar.pagamento.pergunta"}}</h3>
                <div class="ps-cm-segmento" role="group">
                    <button type="button" class={{if (eq this.pago true) "is-ativo"}} aria-pressed={{if (eq this.pago true) "true" "false"}} {{on "click" (fn this.escolherPago true)}}>{{t "customer-portal.ui.entregas.chamar.pagamento.ja-pago"}}</button>
                    <button type="button" class={{if (eq this.pago false) "is-ativo"}} aria-pressed={{if (eq this.pago false) "true" "false"}} {{on "click" (fn this.escolherPago false)}}>{{t "customer-portal.ui.entregas.chamar.pagamento.cobrar"}}</button>
                </div>
                {{#if this.cobrar}}
                    <p class="ps-cm-rotulo">{{t "customer-portal.ui.entregas.chamar.pagamento.forma"}}</p>
                    <div class="ps-cm-segmento" role="group">
                        <button type="button" class={{if (eq this.forma "dinheiro") "is-ativo"}} aria-pressed={{if (eq this.forma "dinheiro") "true" "false"}} {{on "click" (fn this.escolherForma "dinheiro")}}>{{t "customer-portal.ui.entregas.chamar.pagamento.dinheiro"}}</button>
                        <button type="button" class={{if (eq this.forma "cartao") "is-ativo"}} aria-pressed={{if (eq this.forma "cartao") "true" "false"}} {{on "click" (fn this.escolherForma "cartao")}}>{{t "customer-portal.ui.entregas.chamar.pagamento.cartao"}}</button>
                        <button type="button" class={{if (eq this.forma "pix") "is-ativo"}} aria-pressed={{if (eq this.forma "pix") "true" "false"}} {{on "click" (fn this.escolherForma "pix")}}>{{t "customer-portal.ui.entregas.chamar.pagamento.pix"}}</button>
                    </div>
                    <div class="ps-cm-duplo">
                        <label class="ps-cm-campo">
                            <span>{{t "customer-portal.ui.entregas.chamar.pagamento.valor"}}</span>
                            <Input @value={{this.valor}} inputmode="decimal" autocomplete="off" placeholder="0,00" />
                        </label>
                        {{#if this.emDinheiro}}
                            <label class="ps-cm-campo">
                                <span>{{t "customer-portal.ui.entregas.chamar.pagamento.troco-para"}}</span>
                                <Input @value={{this.trocoPara}} inputmode="decimal" autocomplete="off" placeholder="0,00" />
                            </label>
                        {{/if}}
                    </div>
                    {{#if this.trocoDoMotoboy}}
                        <p class="ps-cm-troco">{{t "customer-portal.ui.entregas.chamar.pagamento.troco-do-motoboy" valor=this.trocoDoMotoboy}}</p>
                    {{/if}}
                    {{#if (eq this.problema "troco")}}
                        <p class="ps-cm-aviso">{{t "customer-portal.ui.entregas.chamar.pagamento.troco-menor"}}</p>
                    {{/if}}
                {{/if}}
                <label class="ps-cm-campo">
                    <span>{{t "customer-portal.ui.entregas.chamar.pagamento.observacao"}}</span>
                    <Textarea @value={{this.observacao}} rows="3" maxlength={{this.LIMITES.observacao}} placeholder={{t "customer-portal.ui.entregas.chamar.pagamento.observacao-placeholder"}} />
                </label>
            {{/if}}
        </div>

        <footer class="ps-cm-rodape">
            {{#if (eq this.passo "cliente")}}
                <span></span>
            {{else}}
                <button type="button" class="ps-btn" {{on "click" this.voltar}}>{{t "customer-portal.ui.entregas.chamar.voltar"}}</button>
            {{/if}}
            {{#if (eq this.passo "pagamento")}}
                <button type="button" class="ps-btn ps-btn-marca" disabled={{or (not this.podeContinuar) this.chamar.isRunning}} {{on "click" (perform this.chamar)}}>
                    <FaIcon @icon="motorcycle" />
                    <span>{{if this.chamar.isRunning (t "customer-portal.ui.entregas.chamar.chamando") (t "customer-portal.ui.entregas.chamar.chamar")}}</span>
                </button>
            {{else}}
                <button type="button" class="ps-btn ps-btn-marca" disabled={{not this.podeContinuar}} {{on "click" this.continuar}}>{{t "customer-portal.ui.entregas.chamar.continuar"}}</button>
            {{/if}}
        </footer>
    {{/if}}
</div>
```

> O helper `perform` é do ember-concurrency e o portal já o usa (`portal/conversas.hbs`, `portal/settings/account.hbs`).

`packages/customer-portal/app/components/portal/chamar-motoboy.js`:

```js
export { default } from '@fleetbase/customer-portal-engine/components/portal/chamar-motoboy';
```

- [ ] **Step 3: A rota passa a mostrar o painel**

`packages/customer-portal/addon/routes/portal/orders/new.js` (inteiro):

```js
import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/**
 * Entregas: o "Novo pedido" do portal é o painel "Chamar motoboy" (Portal::ChamarMotoboy; spec
 * docs/superpowers/specs/2026-10-28-chamar-motoboy-portal-design.md). A loja vem para o centro do mapa; o rascunho do
 * customerPortalOrderCreation continua com a coleta, para o mapa grande mostrar o P da loja (quem grava a coleta no
 * pedido é o servidor).
 */
export default class PortalOrdersNewRoute extends Route {
    @service customerPortalOrderActions;
    @service customerPortalOrderCreation;
    @service fetch;

    async model() {
        // sem loja (404) ou com erro, o painel segue sem ela (o mapa abre quando houver ponto)
        const [orderConfigs, minhaLoja] = await Promise.all([this.customerPortalOrderActions.loadOrderConfigs.perform(), this.fetch.get('entregas/loja/minha-loja').catch(() => null)]);
        const loja = minhaLoja?.loja ?? null;
        this.customerPortalOrderCreation.start(orderConfigs);
        if (loja?.coleta) {
            this.customerPortalOrderCreation.setPayloadField('pickup', loja.coleta);
        }

        return { loja };
    }
}
```

`packages/customer-portal/addon/templates/portal/orders/new.hbs` (inteiro):

```hbs
<Portal::ChamarMotoboy @loja={{this.model.loja}} />
```

- [ ] **Step 4: CSS**

No fim de `packages/customer-portal/addon/styles/customer-portal-engine.css`, acrescente:

```css
/* ===== Entregas: "Chamar motoboy" (Portal::ChamarMotoboy; spec 2026-10-28-chamar-motoboy-portal-design.md). Só tokens --pi-*. ===== */
body.fleetbase-portal .portal-container.ps.ps .ps-cm {
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: 0;
    background: var(--pi-cartao);
    color: var(--pi-tinta);
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-topo {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px 8px;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-titulo {
    margin: 0;
    font-size: 18px;
    font-weight: 800;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-fechar {
    border: 0;
    background: transparent;
    color: var(--pi-apagado);
    font-size: 18px;
    cursor: pointer;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-passos {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 6px;
    margin: 0;
    padding: 0 16px 10px;
    list-style: none;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-passos li {
    padding-top: 6px;
    border-top: 3px solid var(--pi-linha);
    color: var(--pi-apagado);
    font-size: 12px;
    font-weight: 700;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-passos li.is-feito {
    border-top-color: var(--pi-laranja-forte);
    color: var(--pi-tinta-2);
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-passos li.is-atual {
    border-top-color: var(--pi-laranja);
    color: var(--pi-tinta);
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-corpo {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 4px 16px 16px;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-pergunta {
    margin: 8px 0 4px;
    font-size: 16px;
    font-weight: 800;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-dica {
    margin: 4px 0 10px;
    color: var(--pi-apagado);
    font-size: 13px;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-aviso {
    margin: 4px 0 10px;
    color: var(--pi-amarelo-texto);
    font-size: 13px;
    font-weight: 600;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-campo {
    display: block;
    margin-bottom: 12px;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-campo > span,
body.fleetbase-portal .portal-container.ps.ps .ps-cm-rotulo {
    display: block;
    margin-bottom: 4px;
    color: var(--pi-tinta-2);
    font-size: 12px;
    font-weight: 700;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-campo input,
body.fleetbase-portal .portal-container.ps.ps .ps-cm-campo textarea {
    width: 100%;
    padding: 9px 11px;
    border: 1px solid var(--pi-linha);
    border-radius: 10px;
    background: var(--pi-fundo);
    color: var(--pi-tinta);
    font-size: 15px;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-duplo {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-cliente {
    margin: 4px 0 14px;
    padding: 12px;
    border: 1px solid var(--pi-laranja);
    border-radius: 12px;
    background: var(--pi-cartao-2);
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-cliente p {
    margin: 0 0 4px;
    font-size: 14px;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-cliente-selo {
    color: var(--pi-laranja-texto);
    font-size: 12px;
    font-weight: 800;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-cliente-nome {
    font-weight: 800;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-cliente-acoes {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-mapa {
    position: relative;
    height: 200px;
    margin: 4px 0;
    overflow: hidden;
    border: 1px solid var(--pi-linha);
    border-radius: 12px;
    background: var(--pi-fundo);
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-mapa .ps-cm-leaflet {
    width: 100%;
    height: 100%;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-mapa.is-sem-ponto .ps-cm-pino {
    opacity: 0.55;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-pino {
    position: absolute;
    top: 50%;
    left: 50%;
    z-index: 500;
    transform: translate(-50%, -100%);
    color: var(--pi-vermelho);
    font-size: 30px;
    pointer-events: none;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-resumo-cliente {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 12px;
    background: var(--pi-cartao-2);
    font-size: 14px;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-resumo-cliente p {
    margin: 4px 0 0;
    color: var(--pi-tinta-2);
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-link {
    border: 0;
    background: transparent;
    color: var(--pi-laranja-texto);
    font-weight: 700;
    cursor: pointer;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-segmento {
    display: flex;
    gap: 6px;
    margin-bottom: 12px;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-segmento button {
    flex: 1;
    padding: 9px 8px;
    border: 1px solid var(--pi-linha);
    border-radius: 10px;
    background: var(--pi-cartao);
    color: var(--pi-tinta);
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-segmento button.is-ativo {
    border-color: var(--pi-laranja);
    background: var(--pi-laranja);
    color: var(--pi-sobre-laranja);
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-troco {
    margin: 0 0 12px;
    padding: 8px 10px;
    border-radius: 10px;
    background: var(--pi-amarelo-claro);
    color: var(--pi-amarelo-texto);
    font-size: 14px;
    font-weight: 700;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-rodape {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 12px 16px;
    border-top: 1px solid var(--pi-linha);
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-rodape .ps-btn[disabled] {
    opacity: 0.5;
    cursor: not-allowed;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-procurando {
    text-align: center;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-radar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 64px;
    height: 64px;
    margin: 12px auto 4px;
    border-radius: 50%;
    background: var(--pi-laranja);
    color: var(--pi-sobre-laranja);
    font-size: 26px;
    animation: ps-cm-pulso 1.6s ease-in-out infinite;
}
@keyframes ps-cm-pulso {
    0%, 100% { box-shadow: 0 0 0 0 rgb(0 0 0 / 0); }
    50% { box-shadow: 0 0 0 12px rgb(0 0 0 / 0.08); }
}
@media (prefers-reduced-motion: reduce) {
    body.fleetbase-portal .portal-container.ps.ps .ps-cm-radar {
        animation: none;
    }
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-resumo {
    margin: 12px 0;
    text-align: left;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-resumo > div {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 8px 0;
    border-bottom: 1px solid var(--pi-linha);
    font-size: 14px;
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-resumo dt {
    color: var(--pi-apagado);
}
body.fleetbase-portal .portal-container.ps.ps .ps-cm-resumo dd {
    margin: 0;
    font-weight: 700;
    text-align: right;
}
@media (max-width: 767px) {
    body.fleetbase-portal .portal-container.ps.ps .ps-cm-duplo {
        grid-template-columns: 1fr;
    }
}
```

- [ ] **Step 5: Conferir**

```bash
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal
node -e "const p=require(require('path').resolve('console/node_modules/.pnpm/@babel+parser@7.29.8/node_modules/@babel/parser'));for(const f of process.argv.slice(1)){p.parse(require('fs').readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties']});console.log('ok',f)}" packages/customer-portal/addon/components/portal/chamar-motoboy.js packages/customer-portal/app/components/portal/chamar-motoboy.js packages/customer-portal/addon/routes/portal/orders/new.js
```
Expected: testes passam; i18n-check exit 0 (se acusar texto fixo no template, o "0,00" do placeholder: troque por uma
chave `customer-portal.ui.entregas.chamar.pagamento.valor-placeholder: "0,00"` nas duas línguas); parse "ok" nos três.

- [ ] **Step 6: Commit**

```bash
git add packages/customer-portal/addon/components/portal/chamar-motoboy.js packages/customer-portal/addon/components/portal/chamar-motoboy.hbs packages/customer-portal/app/components/portal/chamar-motoboy.js packages/customer-portal/addon/routes/portal/orders/new.js packages/customer-portal/addon/templates/portal/orders/new.hbs packages/customer-portal/addon/styles/customer-portal-engine.css packages/customer-portal/translations/
git commit -m "Portal da loja: painel Chamar motoboy no lugar do novo pedido

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Tarefa 12: Apagar o formulário antigo

**Files:**
- Delete: `packages/customer-portal/addon/components/portal/order/form.{js,hbs}`, `form/route.{js,hbs}`, `form/notes.{js,hbs}`,
  `addon/components/modals/portal-order-place-form.{js,hbs}` e os re-exports em `packages/customer-portal/app/components/`.

- [ ] **Step 1: Conferir que ninguém mais usa**

```bash
grep -rn "Portal::Order::Form\|portal/order/form\|portal-order-place-form\|PortalOrderPlaceForm" packages/ console/app --include=*.js --include=*.hbs | grep -v "^packages/customer-portal/addon/components/portal/order/form\|^packages/customer-portal/app/components/portal/order/form\|^packages/customer-portal/addon/components/modals/portal-order-place-form\|^packages/customer-portal/app/components/modals/portal-order-place-form"
```
Expected: nenhuma linha. Se aparecer algum uso, **pare** e deixe o arquivo usado (registre no commit).

- [ ] **Step 2: Apagar**

```bash
git rm -r packages/customer-portal/addon/components/portal/order/form packages/customer-portal/addon/components/portal/order/form.js packages/customer-portal/addon/components/portal/order/form.hbs packages/customer-portal/app/components/portal/order/form packages/customer-portal/app/components/portal/order/form.js packages/customer-portal/addon/components/modals/portal-order-place-form.js packages/customer-portal/addon/components/modals/portal-order-place-form.hbs packages/customer-portal/app/components/modals/portal-order-place-form.js
```

- [ ] **Step 3: Conferir e commit**

```bash
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal
git commit -m "Portal da loja: sai o formulário antigo do novo pedido (endereços salvos e modal)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
Expected: testes passam; i18n-check exit 0 (chaves que ficaram sem uso não são erro).

---

## Parte C: app do motoboy (`entregas-navigator`)

### Tarefa 13: Conferência sem código (sem APK)

O app já mostra tudo o que a linha do portal manda (spec, "Precisa de APK? Não."). **Não faça commit nem push no
`entregas-navigator` por esta tarefa:** todo push na `main` do app vira APK obrigatório (trava "Atualize o app").

- [ ] **Step 1: Conferir no código que nada mudou de lá para cá**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator"
grep -n "useDadosDaIntegracao(numeroIfoodDasNotas(order) ? null : order.id" src/screens/OrderScreen.tsx src/components/AdhocOrderCard.tsx
grep -n "origemDosDados\|textoDaCobrancaDaIntegracao" src/utils/pedido-de-origem.ts
grep -n "integracao?.observacoes\|integracao?.complemento\|LigarParaOClienteDaIntegracao" src/screens/OrderScreen.tsx
cd -
```
Expected: as duas chamadas do `useDadosDaIntegracao` (pedido que não é do iFood busca `.../integracao`), as funções da
origem e da cobrança, e os usos de observação, complemento e "Ligar para o cliente". Se algum sumiu, o pedido do portal
pode não mostrar a cobrança no app: avise o Edgard antes do deploy.

- [ ] **Step 2: Depois do deploy (Tarefa 15), no celular**

Com um pedido de teste chamado pelo portal numa loja de teste (não no Terraço, que é loja real), confira no app:
selo "Portal" no card de aceitar; na tela do pedido, a cobrança ("Cobrar R$ … · dinheiro · troco p/ R$ …" ou "Já pago"),
a observação em amarelo, o complemento e, depois do aceite e a caminho do cliente, "Ligar para o cliente".

---

## Parte D: documentação e implantação

### Tarefa 14: Documentação

**Files:**
- Modify: `packages/customer-portal/CLAUDE.md`, `api/app/Support/Entregas/Integracao/CLAUDE.md`,
  `api/app/Support/Entregas/Painel/CLAUDE.md`, `api/app/Http/Controllers/Entregas/CLAUDE.md`, `docs/historico.md`

- [ ] **Step 1: `packages/customer-portal/CLAUDE.md`**

1. Em "**Front**", troque a linha `  - novo pedido só com o destino;` por:

```markdown
  - **novo pedido = "Chamar motoboy"** (decisão de 2026-10-28; spec `docs/superpowers/specs/2026-10-28-chamar-motoboy-portal-design.md`): na rota `portal.orders.new`, o `Portal::ChamarMotoboy` em 3 passos (cliente pelo telefone com o "Já pediu antes · N pedidos" do `loja/clientes`; endereço com o `EnderecoGoogleInput` e o mapa sempre à vista `Portal::ChamarMotoboy::Ponto`, alfinete fixo no centro e só o arrasto move o ponto; pagamento "Já pago"/"Cobrar na entrega" com valor, forma, troco e "O motoboy leva de troco R$ X", e a observação para o motoboy) e a tela "Procurando motoboy" (Ver no mapa, Novo pedido). Um POST só (`loja/chamar-motoboy`, abaixo) com a chave do painel. Saíram o `Portal::Order::Form` (com `Form::Route` e `Form::Notes`) e o modal `portal-order-place-form`. Sem estimativa do valor da corrida (ver o spec). Funções puras em `utils/chamar-motoboy.js` (teste `scripts/teste-portal/chamar-motoboy.test.mjs`; o telefone é cópia da regra do `ChamadoDaLoja::telefone`); textos `customer-portal.ui.entregas.chamar.*`; CSS `ps-cm-*`;
```

2. Na lista "**API do portal**", depois do item `aceites`, acrescente:

```markdown
  - `chamar-motoboy` e `clientes` (`ChamarMotoboyController`, decisão de 2026-10-28): `POST loja/chamar-motoboy` (limitador `entregas-loja-chamar`, 20 por minuto por usuário) valida (`Portal\ChamadoDaLoja`: mensagens para a loja; telefone com DDD, só dígitos), devolve o pedido da mesma chave (200, sem passar pelo crédito), confere o crédito da pré-paga (`PrePago\CreditoNoPedido`, o mesmo do `RegrasPortalLoja`) e cria (`Portal\CriadorDoChamado`): coleta = Local da loja, ponto a 30 m ou mais e a até 50 km, Local do cliente reaproveitado (mesmo telefone, nome, rua e complemento, a até 30 m) ou novo com dono = a loja (direto, sem o `firstOrNew`; sem o telefone), pedido aberto sem notas, despacho com a `TravaDoPedido` → distribuição; 201 `{pedido: {id, numero, cliente, endereco, cobranca}}`. Cobrança, observação, telefone, complemento e referência ficam na **`entregas_portal_pedidos`** (`Portal\PedidosDoPortal`), não na da integração (o observador e o acompanhamento da integração mandariam avisos ao sistema da loja). `GET loja/clientes?telefone=` (`throttle:60,1`): o último pedido do telefone **na loja da sessão** (nome, contagem e endereço), telefone incompleto = `{cliente: null}`. Testes: `scripts/teste-php/chamar-motoboy.php` e `chamar-motoboy-validacao.php`;
```

3. Em "`RegrasPortalLoja` (global)", no item do destino, acrescente ao fim: ` O POST orders continua com estas regras (compatibilidade), mas o front usa o `loja/chamar-motoboy` desde 2026-10-28; o crédito da pré-paga saiu para o `PrePago\CreditoNoPedido`.`

- [ ] **Step 2: `api/app/Support/Entregas/Integracao/CLAUDE.md`**

Depois do item "**Dados fora do `meta`:**", acrescente:

```markdown
- **Pedido chamado pelo portal da loja não é da integração** (2026-10-28): cobrança, observação e telefone dele ficam na `entregas_portal_pedidos` (`App\Support\Entregas\Portal\PedidosDoPortal`), fora do observador, dos avisos, do acompanhamento e do `CalculoEntregas`. Só a rota do app `.../integracao` o entrega no mesmo formato (`PedidosDoPortal::comoLinhaDaIntegracao`, `sistema` "Portal", sem número, sem itens), para o APK atual mostrar a cobrança sem mudança. Ver "API do portal" em `packages/customer-portal/CLAUDE.md`.
```

- [ ] **Step 3: `api/app/Support/Entregas/Painel/CLAUDE.md`**

Na tabela da comanda, troque a linha de `origem`, `sistema`, `teste` por:

```markdown
| `origem`, `sistema`, `teste` | `ifood` (linha em `entregas_ifood_pedidos`), `integracao` (`entregas_integracao_pedidos`, com o `sistema`), `portal` (`entregas_portal_pedidos`, o chamar motoboy do portal da loja, sem `sistema`; o console não põe selo) ou null; `teste` = pedido de teste do iFood |
```

e, nas linhas de `observacoes`, `cobranca` e `cliente`, onde diz "da linha do iFood ou da integração", passe a dizer "da
linha do iFood, da integração ou do portal".

- [ ] **Step 4: `api/app/Http/Controllers/Entregas/CLAUDE.md`**

No item "Produtos:" da **Comanda**, acrescente ao fim: ` O pedido chamado pelo portal da loja (2026-10-28) também responde na `.../integracao`, com `sistema` "Portal" (selo "Portal" no app), a cobrança, a observação, o complemento, a referência e o telefone (só ao motoboy do pedido em andamento), sem itens; não precisou de APK.`

Copie a mesma frase no `CLAUDE.md` do `entregas-navigator` (a seção é cópia), **sem push** (vai junto no próximo push do app):

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && git rev-parse --show-toplevel && git add CLAUDE.md && git commit -m "Docs: pedido chamado pelo portal da loja na rota de integração

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>" && cd -
```

- [ ] **Step 5: `docs/historico.md`**

Acrescente no fim:

```markdown
52. Chamar motoboy pelo portal da loja (2026-10-28; desenho `docs/superpowers/specs/2026-10-28-chamar-motoboy-portal-design.md`, plano `docs/superpowers/plans/2026-10-28-chamar-motoboy-portal.md`): o "Novo pedido" do portal virou o painel "Chamar motoboy" em 3 passos (cliente pelo telefone, com o último endereço de quem já pediu; endereço com o Google e o mapa; pagamento já pago ou a cobrar, com forma, troco e observação) e a tela "Procurando motoboy". Uma rota só (`loja/chamar-motoboy`, com chave contra pedido em dobro) cria o pedido com as regras do portal; cobrança, observação e telefone ficam na tabela `entregas_portal_pedidos` e chegam ao motoboy pelo APK atual (selo "Portal") e à comanda do console (ver "API do portal" em `packages/customer-portal/CLAUDE.md`).
```

- [ ] **Step 6: Commit**

```bash
git add packages/customer-portal/CLAUDE.md api/app/Support/Entregas/Integracao/CLAUDE.md api/app/Support/Entregas/Painel/CLAUDE.md api/app/Http/Controllers/Entregas/CLAUDE.md docs/historico.md
git commit -m "Chamar motoboy: documentação

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Tarefa 15: Implantação (o Edgard roda)

- [ ] **Step 1: Conferência final no PC**

```bash
for t in chamar-motoboy chamar-motoboy-validacao prepago-loja comanda-do-pedido portal-cancelamento integracao-criador integracao-rotas mapa-da-loja imports-dos-providers; do PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php | tail -1; done
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal
```
Expected: "FALHAS: 0" em todos, testes do portal passam, i18n-check exit 0. Depois, `git push` na `main` (pedir ao Edgard).

- [ ] **Step 2: Deploy na VPS (fora do horário de pico)**

```bash
cd ~/entregas && bash deploy/atualizar.sh api console
```
O `api` roda o `deploy.sh` com a migration `2026_10_28_150000_create_entregas_portal_pedidos_table`. Nos segundos entre o
`service update` e a migration, o `PedidosDoPortal::doPedido` responde null (app, comanda e portal seguem como hoje).
Confira: `docker exec $(docker ps -q -f name=entregas_application) php artisan migrate:status | grep entregas_portal`
(deve sair "Ran"). Depois, abra o portal com Ctrl+Shift+R (o console guarda a configuração por 1 h).

- [ ] **Step 3: Teste real (loja de teste, não o Terraço)**

1. Portal → Novo pedido: telefone novo → nome → endereço pelo Google → ajuste no mapa → "Cobrar na entrega", R$ 58,90,
   dinheiro, troco para R$ 100 ("O motoboy leva de troco R$ 41,10") → observação → Chamar motoboy. Deve aparecer
   "Procurando motoboy" com o número e a cobrança.
2. Console → o pedido: a comanda mostra a cobrança, a observação e o complemento, sem selo de origem.
3. App (Tarefa 13, Step 2).
4. Portal → Novo pedido com o mesmo telefone: o cartão "Já pediu antes · 1 pedido" e "Usar e ir ao pagamento" pula para o
   passo 3; o pedido novo reaproveita o Local (log `[entregas] portal da loja: motoboy chamado` com
   `endereco_reaproveitado: true` em `docker service logs entregas_application | grep 'motoboy chamado'`).
5. Cancele os dois pedidos de teste pelo portal (vale até o motoboy sair para a entrega).
6. `node scripts/teste-isolamento-lojas.mjs` (janela anônima, `deploy/teste-lojas.env`).

**APK:** não precisa. **Stack/Portainer:** sem variável nova.
