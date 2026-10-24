# Ganhos no Início e "Minha carteira": plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O topo do Início do app do motoboy mostra só os ganhos do período ("Ganhos · <período>"), e um botão "Minha carteira" abre uma folha com o que tem a receber, o que recebeu no mês e os últimos pagamentos.

**Architecture:** Servidor (repo `Delivery`, pasta `api/app`): uma classe nova `Carteira` no Financeiro e a rota `GET v1/entregas/motoboy/carteira` no `MotoboyController`. App (repo `entregas-navigator`): a função pura `ganhosDoPeriodo` substitui `aReceberNoPeriodo`/`totaisDoPeriodo`, um componente novo `FolhaDaCarteira` (folha do `@gorhom/bottom-sheet`, como a `FolhaDoPeriodo`) e o topo do `MeusGanhosScreen` muda.

**Tech Stack:** Laravel (PHP 8.2, `DB::table`, sem Models), testes em php-wasm (`scripts/teste-php`); React Native + Tamagui + `@gorhom/bottom-sheet`, testes com `node --experimental-strip-types --test`.

**Spec:** `docs/superpowers/specs/2026-10-24-ganhos-e-carteira-design.md`. **Visual:** sketch 008 B, `entregas-navigator/.planning/sketches/008-ganhos-e-carteira/index.html` (aba "B: Cartão da carteira").

**Caminhos:** `D:` = `C:\Users\Edgardjr\Documents\vibe coding\Delivery`; `N:` = `C:\Users\Edgardjr\Documents\vibe coding\entregas-navigator`. Os comandos usam o Git Bash.

**Regras do repo que valem aqui:**
- Responda e documente em português do Brasil. Comentários no código em português, no estilo dos arquivos vizinhos.
- Fuso: nunca formate data em UTC. O mês da carteira vem de `Financeiro::hoje($companyUuid)`.
- Antes de cada commit, confira `git rev-parse --show-toplevel` (a home `C:\Users\Edgardjr` também é um repo git).
- **Não faça push** em nenhum dos dois repos. O push na `main` do app gera um APK obrigatório (trava "Atualize o app") e só pode sair depois do deploy da API. Quem decide é o Edgard.
- Os `CLAUDE.md` novos do `Delivery` (ex.: `api/app/Http/Controllers/Entregas/CLAUDE.md`) e o `CLAUDE.md` da raiz do app estão **fora do git** (reorganização em andamento do Edgard). Edite-os na Tarefa 8, mas **não** os inclua em commit. O `api/app/Support/Entregas/Financeiro/CLAUDE.md` também tem mudança dele sem commit: edite, sem commit.
- Testes PHP: o php-wasm já está instalado em `C:/tmp/php-wasm` (`PHP_WASM_DIR=C:/tmp/php-wasm`).

---

## Mapa de arquivos

| Arquivo | Repo | O quê |
|---|---|---|
| `api/app/Support/Entregas/Financeiro/Carteira.php` | D | **Novo.** `Carteira::doMotoboy($companyUuid, $motoboyUuid)`: a resposta da rota |
| `api/app/Http/Controllers/Entregas/MotoboyController.php` | D | Método `carteira` |
| `api/app/Providers/RouteServiceProvider.php` | D | `Route::get('carteira', …)` no grupo `v1/entregas/motoboy` |
| `scripts/teste-php/financeiro-console.php` | D | Seção nova "App do motoboy: carteira" e a rota na seção "Rotas" |
| `src/utils/extrato.ts` | N | `ganhosDoPeriodo`, `primeiroDiaDoMes`, tipos `Carteira` e `PagamentoDaCarteira`; saem `aReceberNoPeriodo` e `totaisDoPeriodo` |
| `scripts/testes/extrato.teste.ts` | N | Testes de `ganhosDoPeriodo` e `primeiroDiaDoMes`; saem os de `aReceberNoPeriodo` e `totaisDoPeriodo` |
| `translations/pt.json`, `translations/en.json` | N | `Extrato.ganhosNoPeriodo` e o bloco `Carteira`; saem as chaves do topo antigo |
| `src/components/entregas/FolhaDaCarteira.tsx` | N | **Novo.** A folha "Minha carteira" |
| `src/screens/MeusGanhosScreen.tsx` | N | Topo com "Ganhos · período" + botão; abre a folha |
| `api/app/Http/Controllers/Entregas/CLAUDE.md`, `api/app/Support/Entregas/Financeiro/CLAUDE.md` (D) e `CLAUDE.md` (N) | D/N | Documentação (sem commit, ver regras) |

---

### Tarefa 1: `Carteira::doMotoboy` (servidor)

**Files:**
- Create: `D:/api/app/Support/Entregas/Financeiro/Carteira.php`
- Test: `D:/scripts/teste-php/financeiro-console.php`

- [ ] **Passo 1: escrever o teste que falha**

Em `scripts/teste-php/financeiro-console.php`, acrescente o `use` junto dos outros do Financeiro (ordem alfabética, antes de `ChavePix`):

```php
use App\Support\Entregas\Financeiro\Carteira;
```

E insira a seção nova **logo antes** da linha `echo '== Rotas (RouteServiceProvider)' . PHP_EOL;`. Ela chama `cenario()` de novo, que zera o banco em memória; por isso fica depois das seções que usam o `$pagamento` do cenário anterior.

```php
echo '== App do motoboy: carteira (Carteira::doMotoboy)' . PHP_EOL;
// o cenário: João (driver-1) com saldo 31,50 e o pagamento de 15,00 (Pix) em 06/10; hoje = 12/10/2026
['pagamento' => $pagamento] = cenario();
ChavePix::salvar('empresa-1', 'driver-1', '11999994321', 'telefone');
$carteira = Carteira::doMotoboy('empresa-1', 'driver-1');
confere(array_keys($carteira) === ['a_receber', 'mes', 'recebido_no_mes', 'pagamentos', 'pix'], 'só os campos do contrato: ' . json_encode(array_keys($carteira)));
confere(reais($carteira['a_receber']) === 31.5 && $carteira['mes'] === '2026-10' && reais($carteira['recebido_no_mes']) === 15.0, 'a receber = saldo da conta; recebido no mês = o pagamento de 06/10: ' . json_encode($carteira));
confere($carteira['pagamentos'] === [['id' => $pagamento->uuid, 'valor' => 15.0, 'data' => '2026-10-06', 'forma' => 'pix', 'estornado' => false]], 'o pagamento dele, sem comprovante nem observação interna');
confere($carteira['pix'] === ['chave' => '11999994321', 'tipo' => 'telefone'], 'a chave Pix dele ({chave, tipo})');

Pagamentos::registrar('empresa-1', 'motoboy', 'driver-1', 20.0, 'dinheiro', '2026-09-28', null, null, 'user-admin'); // mês passado
$estornado = Pagamentos::registrar('empresa-1', 'motoboy', 'driver-1', 7.0, 'pix', '2026-10-09', null, null, 'user-admin');
Pagamentos::estornar($estornado, 'user-admin');
Pagamentos::registrar('empresa-1', 'motoboy', 'driver-1', 1.0, 'pix', '2026-10-10', null, null, 'user-admin');
Pagamentos::registrar('empresa-1', 'motoboy', 'driver-1', 2.0, 'pix', '2026-10-11', null, null, 'user-admin');
Pagamentos::registrar('empresa-1', 'motoboy', 'driver-1', 3.0, 'transferencia', '2026-10-11', null, null, 'user-admin');
Pagamentos::registrar('empresa-1', 'motoboy', 'driver-2', 99.0, 'pix', '2026-10-11', null, null, 'user-admin'); // da Maria
$carteira = Carteira::doMotoboy('empresa-1', 'driver-1');
confere(reais($carteira['recebido_no_mes']) === 21.0, 'recebido no mês sem o de setembro, sem o estornado e sem o da Maria (15 + 1 + 2 + 3): ' . json_encode($carteira['recebido_no_mes']));
confere(array_column($carteira['pagamentos'], 'data') === ['2026-10-11', '2026-10-11', '2026-10-10', '2026-10-09', '2026-10-06'], 'os 5 mais recentes, mais novo primeiro (o de setembro fica de fora): ' . json_encode(array_column($carteira['pagamentos'], 'data')));
confere(array_column($carteira['pagamentos'], 'valor') === [3.0, 2.0, 1.0, 7.0, 15.0] && array_column($carteira['pagamentos'], 'forma')[0] === 'transferencia', 'no mesmo dia, o lançado por último primeiro');
confere(array_column($carteira['pagamentos'], 'estornado') === [false, false, false, true, false], 'o estornado vem na lista, marcado');
confere(reais($carteira['a_receber']) === 5.5, 'a receber = saldo (31,50 − 20 − 1 − 2 − 3; o estornado se anula): ' . json_encode($carteira['a_receber']));
Pagamentos::registrar('empresa-1', 'motoboy', 'driver-1', 30.0, 'pix', '2026-10-12', null, null, 'user-admin');
confere(reais(Carteira::doMotoboy('empresa-1', 'driver-1')['a_receber']) === -24.5, 'pagou a mais: a receber negativo (o app mostra "Adiantamento")');
confere(Carteira::doMotoboy('empresa-1', 'driver-sem-conta') === ['a_receber' => 0.0, 'mes' => '2026-10', 'recebido_no_mes' => 0.0, 'pagamentos' => [], 'pix' => null], 'motoboy sem lançamentos: zeros, lista vazia e sem Pix');
```

- [ ] **Passo 2: rodar e ver falhar**

```bash
cd "C:/Users/Edgardjr/Documents/vibe coding/Delivery" && PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/financeiro-console.php | tail -5
```

Esperado: erro de PHP `Class "App\Support\Entregas\Financeiro\Carteira" not found` (sai com 1).

- [ ] **Passo 3: criar a classe**

`D:/api/app/Support/Entregas/Financeiro/Carteira.php`:

```php
<?php

namespace App\Support\Entregas\Financeiro;

use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: a "Minha carteira" do app do motoboy (GET v1/entregas/motoboy/carteira; desenho em
 * docs/superpowers/specs/2026-10-24-ganhos-e-carteira-design.md). Não depende do período do Início: é a conta inteira.
 *
 * - a_receber: o saldo da conta (a soma de todos os lançamentos, o mesmo `saldo` do ExtratoDaConta); negativo = adiantamento;
 * - mes / recebido_no_mes: o mês de hoje no fuso da empresa ('AAAA-MM') e a soma dos pagamentos não estornados com `data`
 *   nesse mês;
 * - pagamentos: os 5 mais recentes (data, depois a ordem de registro), estornados incluídos (`estornado: true`), sem
 *   comprovante nem observação interna (o recibo completo é o GET pagamentos/{id});
 * - pix: {chave, tipo} (ChavePix) ou null.
 */
final class Carteira
{
    public const ULTIMOS_PAGAMENTOS = 5;

    public static function doMotoboy(string $companyUuid, string $motoboyUuid): array
    {
        $saldo = 0;
        foreach (Lancamentos::daConta($companyUuid, Financeiro::CONTA_MOTOBOY, $motoboyUuid) as $lancamento) {
            $saldo += static::centavos($lancamento->valor);
        }

        // poucos por motoboy: a soma do mês e o corte dos 5 ficam aqui, sem SQL de data
        $pagamentos = DB::table(Pagamentos::TABELA)
            ->where('company_uuid', $companyUuid)
            ->where('conta_tipo', Financeiro::CONTA_MOTOBOY)
            ->where('conta_uuid', $motoboyUuid)
            ->orderBy('data', 'desc')
            ->orderBy('id', 'desc')
            ->get()
            ->all();

        $mes      = substr(Financeiro::hoje($companyUuid), 0, 7);
        $recebido = 0;
        foreach ($pagamentos as $pagamento) {
            if (($pagamento->estornado_em ?? null) === null && substr((string) $pagamento->data, 0, 7) === $mes) {
                $recebido += static::centavos($pagamento->valor);
            }
        }

        return [
            'a_receber'       => round($saldo / 100, 2),
            'mes'             => $mes,
            'recebido_no_mes' => round($recebido / 100, 2),
            'pagamentos'      => array_map(fn ($pagamento) => [
                'id'        => (string) $pagamento->uuid,
                'valor'     => round((float) $pagamento->valor, 2),
                'data'      => substr((string) $pagamento->data, 0, 10),
                'forma'     => (string) $pagamento->forma,
                'estornado' => ($pagamento->estornado_em ?? null) !== null,
            ], array_slice($pagamentos, 0, static::ULTIMOS_PAGAMENTOS)),
            'pix'             => ChavePix::doMotoboy($companyUuid, $motoboyUuid),
        ];
    }

    private static function centavos($valor): int
    {
        return (int) round(((float) $valor) * 100);
    }
}
```

- [ ] **Passo 4: rodar e ver passar**

```bash
cd "C:/Users/Edgardjr/Documents/vibe coding/Delivery" && PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/financeiro-console.php | grep -E "FALHA|carteira|FALHAS"
```

Esperado: as linhas `PASSA` da seção da carteira e `FALHAS: 0`. Se `a_receber` vier `0` em vez de `0.0` no último caso, troque a comparação do teste por `reais(...)` campo a campo. Não mude a classe para isso.

- [ ] **Passo 5: sintaxe no PHP 8.2**

```bash
cd "C:/Users/Edgardjr/Documents/vibe coding/Delivery" && PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/Financeiro/Carteira.php
```

Esperado: sem erro, exit 0.

- [ ] **Passo 6: commit**

```bash
cd "C:/Users/Edgardjr/Documents/vibe coding/Delivery" && git rev-parse --show-toplevel && git add api/app/Support/Entregas/Financeiro/Carteira.php scripts/teste-php/financeiro-console.php && git commit -m "Financeiro: Carteira do motoboy (a receber, recebido no mês, últimos pagamentos, Pix)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 2: rota `GET v1/entregas/motoboy/carteira` (servidor)

**Files:**
- Modify: `D:/api/app/Http/Controllers/Entregas/MotoboyController.php` (depois do método `pagamento`, ~linha 147)
- Modify: `D:/api/app/Providers/RouteServiceProvider.php` (~linha 259-261)
- Test: `D:/scripts/teste-php/financeiro-console.php`

- [ ] **Passo 1: escrever o teste que falha**

No fim da seção "App do motoboy: carteira" (logo depois do último `confere` da Tarefa 1), acrescente:

```php
echo '== App do motoboy: carteira (MotoboyController@carteira)' . PHP_EOL;
$app = new App\Http\Controllers\Entregas\MotoboyController();
confere($app->carteira(doApp([], 'user-m1', 'flb_live_chave'))->status === 403, 'carteira com chave de API (sem "|") → 403');
confere($app->carteira(doApp([], 'user-loja'))->status === 403, 'usuário sem cadastro de motoboy → 403');
$resposta = $app->carteira(doApp());
confere($resposta->status === 200 && reais($resposta->dados['a_receber'] ?? null) === -24.5 && count($resposta->dados['pagamentos'] ?? []) === 5, 'a carteira do motoboy do token');
confere(reais($app->carteira(doApp([], 'user-m2'))->dados['a_receber'] ?? null) === -74.0, 'outro motoboy vê só a dele (Maria: diária 25 − pagamento 99)');
```

E, na seção "Rotas", troque o `confere` das rotas do app:

```php
confere(str_contains($doApp, "Route::get('extrato', [MotoboyController::class, 'extrato'])") && str_contains($doApp, "Route::get('pagamentos/{id}', [MotoboyController::class, 'pagamento'])"), 'app: extrato e pagamentos/{id} no grupo v1/entregas/motoboy (fleetbase.api, throttle:entregas-motoboy)');
```

por:

```php
confere(str_contains($doApp, "Route::get('extrato', [MotoboyController::class, 'extrato'])") && str_contains($doApp, "Route::get('pagamentos/{id}', [MotoboyController::class, 'pagamento'])") && str_contains($doApp, "Route::get('carteira', [MotoboyController::class, 'carteira'])"), 'app: extrato, pagamentos/{id} e carteira no grupo v1/entregas/motoboy (fleetbase.api, throttle:entregas-motoboy)');
```

- [ ] **Passo 2: rodar e ver falhar**

```bash
cd "C:/Users/Edgardjr/Documents/vibe coding/Delivery" && PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/financeiro-console.php | tail -5
```

Esperado: erro de PHP `Call to undefined method ...MotoboyController::carteira()`.

- [ ] **Passo 3: o método no controller**

Em `MotoboyController.php`, acrescente o `use` (ordem alfabética, antes de `ExtratoDaConta`):

```php
use App\Support\Entregas\Financeiro\Carteira;
```

E, logo depois do método `pagamento` (antes do docblock de `valor`):

```php
    /**
     * GET v1/entregas/motoboy/carteira: a "Minha carteira" do motoboy da sessão (Carteira::doMotoboy): {a_receber, mes,
     * recebido_no_mes, pagamentos (os 5 mais recentes), pix}. Não depende do período do Início.
     */
    public function carteira(Request $request)
    {
        $motoboy = MotoboyDaSessao::motoboy($request);
        if (!$motoboy) {
            return $this->soParaMotoboy();
        }

        return response()->json(Carteira::doMotoboy((string) session('company'), (string) $motoboy->uuid));
    }
```

- [ ] **Passo 4: a rota**

Em `RouteServiceProvider.php`, no grupo `Route::prefix('v1/entregas/motoboy')`, troque:

```php
                        // Financeiro no app: o extrato (Início) e o recibo de um pagamento
                        Route::get('extrato', [MotoboyController::class, 'extrato']);
                        Route::get('pagamentos/{id}', [MotoboyController::class, 'pagamento']);
```

por:

```php
                        // Financeiro no app: o extrato (Início), a "Minha carteira" e o recibo de um pagamento
                        Route::get('extrato', [MotoboyController::class, 'extrato']);
                        Route::get('carteira', [MotoboyController::class, 'carteira']);
                        Route::get('pagamentos/{id}', [MotoboyController::class, 'pagamento']);
```

- [ ] **Passo 5: rodar e ver passar**

```bash
cd "C:/Users/Edgardjr/Documents/vibe coding/Delivery" && PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/financeiro-console.php | grep -E "FALHA|carteira|FALHAS" ; PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Http/Controllers/Entregas/MotoboyController.php api/app/Providers/RouteServiceProvider.php
```

Esperado: `FALHAS: 0` e a sintaxe sem erro.

- [ ] **Passo 6: rodar os outros testes que leem o MotoboyController e as rotas**

```bash
cd "C:/Users/Edgardjr/Documents/vibe coding/Delivery" && for t in app-do-motoboy ganhos-motoboy imports-dos-providers financeiro-pagamentos; do echo "== $t"; PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php | tail -1; done
```

Esperado: `FALHAS: 0` em todos.

- [ ] **Passo 7: commit**

```bash
cd "C:/Users/Edgardjr/Documents/vibe coding/Delivery" && git rev-parse --show-toplevel && git add api/app/Http/Controllers/Entregas/MotoboyController.php api/app/Providers/RouteServiceProvider.php scripts/teste-php/financeiro-console.php && git commit -m "App do motoboy: rota GET v1/entregas/motoboy/carteira

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 3: `ganhosDoPeriodo` e o tipo da carteira (app)

**Files:**
- Modify: `N:/src/utils/extrato.ts`
- Test: `N:/scripts/testes/extrato.teste.ts`

- [ ] **Passo 1: trocar os testes**

Em `scripts/testes/extrato.teste.ts`:

1. Apague a linha `import { aReceberNoPeriodo, totaisDoPeriodo } from '../../src/utils/extrato.ts';`.
2. Apague os testes inteiros (do `test(` ao `});`) com estes nomes:
   - `'totais do período: ganhos = créditos de trabalho; recebido = pagamentos'`
   - `'totais do período: estorno de crédito sai dos ganhos; estorno de pagamento sai do recebido'`
   - `'totais do período: estorno de ajuste negativo não mexe em nada'`
   - `'totais do período: estorno cujo original é de antes do período'`
   - `'totais do período: soma em centavos, sem sobra de ponto flutuante'`
   - `'a receber no período: soma dos lançamentos do período (a regra do saldo do servidor)'`
3. No fim do arquivo, acrescente:

```ts
import { ganhosDoPeriodo, primeiroDiaDoMes } from '../../src/utils/extrato.ts';

const pagoCom = (id: string, estornado = false) => ({ id, forma: 'pix', tem_comprovante: false, estornado });

test('ganhos do período: o exemplo do Edgard (o pagamento de hoje por corridas da semana passada não entra)', () => {
    const hoje = [
        lancamento({ id: 10, tipo: 'pagamento', valor: -200, data: '2026-10-24', pagamento: pagoCom('p1') }),
        lancamento({ id: 9, tipo: 'corrida', valor: 45, data: '2026-10-24', situacao: 'aberto' }),
        lancamento({ id: 8, tipo: 'corrida', valor: 25, data: '2026-10-24', situacao: 'aberto' }),
    ];
    assert.equal(ganhosDoPeriodo(hoje), 70);
    const semanaPassada = [
        lancamento({ id: 4, tipo: 'corrida', valor: 60, data: '2026-10-23', situacao: 'pago' }),
        lancamento({ id: 3, tipo: 'corrida', valor: 40, data: '2026-10-23', situacao: 'pago' }),
        lancamento({ id: 2, tipo: 'corrida', valor: 55, data: '2026-10-21', situacao: 'pago' }),
        lancamento({ id: 1, tipo: 'corrida', valor: 45, data: '2026-10-21', situacao: 'pago' }),
    ];
    assert.equal(ganhosDoPeriodo([...hoje, ...semanaPassada]), 270);
    assert.equal(ganhosDoPeriodo([]), 0);
});

test('ganhos do período: o "Não pagar" e o estorno de corrida descontam; o estorno do "Não pagar" devolve', () => {
    assert.equal(
        ganhosDoPeriodo([lancamento({ id: 1, tipo: 'corrida', valor: 70 }), lancamento({ id: 2, tipo: 'ajuste', valor: -10, pedido: { id: 'order_x', numero: '4821' } })]),
        60,
    );
    assert.equal(
        ganhosDoPeriodo([
            lancamento({ id: 1, tipo: 'corrida', valor: 8.5, estornado: true }),
            lancamento({ id: 2, tipo: 'corrida', valor: 9 }),
            lancamento({ id: 3, tipo: 'estorno', valor: -8.5, estorno_de: 1, motivo: 'Pedido cancelado' }),
        ]),
        9,
    );
    assert.equal(
        ganhosDoPeriodo([
            lancamento({ id: 1, tipo: 'corrida', valor: 10 }),
            lancamento({ id: 2, tipo: 'ajuste', valor: -10, estornado: true }),
            lancamento({ id: 3, tipo: 'estorno', valor: 10, estorno_de: 2, motivo: 'Lançado errado' }),
        ]),
        10,
    );
    // diária, bônus, ajuste positivo e saldo inicial (dos dois sinais) também entram
    assert.equal(
        ganhosDoPeriodo([
            lancamento({ id: 1, tipo: 'diaria', valor: 30 }),
            lancamento({ id: 2, tipo: 'bonus', valor: 10 }),
            lancamento({ id: 3, tipo: 'ajuste', valor: 5.25 }),
            lancamento({ id: 4, tipo: 'saldo_inicial', valor: 20 }),
            lancamento({ id: 5, tipo: 'saldo_inicial', valor: -40 }),
        ]),
        25.25,
    );
});

test('ganhos do período: pagamento e estorno de pagamento ficam de fora', () => {
    assert.equal(
        ganhosDoPeriodo([
            lancamento({ id: 1, tipo: 'corrida', valor: 9 }),
            lancamento({ id: 2, tipo: 'pagamento', valor: -100, pagamento: pagoCom('p1', true) }),
            lancamento({ id: 3, tipo: 'estorno', valor: 100, estorno_de: 2, motivo: 'Pagamento estornado' }),
        ]),
        9,
    );
    // original fora do período: o estorno de pagamento é reconhecido pelo motivo que o servidor grava (sem pedido)
    assert.equal(ganhosDoPeriodo([lancamento({ id: 9, tipo: 'estorno', valor: 150, estorno_de: 1, motivo: 'Pagamento estornado' })]), 0);
    // estorno de crédito com o original fora do período: desconta (pode dar negativo)
    assert.equal(ganhosDoPeriodo([lancamento({ id: 9, tipo: 'estorno', valor: -8.5, estorno_de: 1 })]), -8.5);
});

test('ganhos do período: soma em centavos e valor ilegível não quebra', () => {
    assert.equal(ganhosDoPeriodo([0.1, 0.2].map((valor, i) => lancamento({ id: i + 1, valor }))), 0.3);
    assert.equal(ganhosDoPeriodo([lancamento({ id: 1, valor: 10 }), lancamento({ id: 2, valor: Number.NaN })]), 10);
});

test('mês da carteira: o primeiro dia do mês, para o nome do mês no idioma do app', () => {
    assert.equal(primeiroDiaDoMes('2026-10'), '2026-10-01');
    assert.equal(primeiroDiaDoMes('2026-13'), null);
    assert.equal(primeiroDiaDoMes('2026-1'), null);
    assert.equal(primeiroDiaDoMes(''), null);
    assert.equal(primeiroDiaDoMes(undefined), null);
});
```

- [ ] **Passo 2: rodar e ver falhar**

```bash
cd "C:/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && node --experimental-strip-types --test scripts/testes/extrato.teste.ts 2>&1 | tail -15
```

Esperado: falha de import (`does not provide an export named 'ganhosDoPeriodo'`).

- [ ] **Passo 3: implementar em `src/utils/extrato.ts`**

1. Depois do tipo `Pagamento` (o "Resposta de GET v1/entregas/motoboy/pagamentos/{id}"), acrescente:

```ts
/** Um pagamento da "Minha carteira" (os 5 mais recentes; o estornado vem marcado). */
export type PagamentoDaCarteira = { id: string; valor: number; data: string; forma: FormaDePagamento | string; estornado: boolean };

/** Resposta de GET v1/entregas/motoboy/carteira: a conta inteira, sem depender do período do Início. */
export type Carteira = {
    /** Saldo da conta: positivo = a receber; negativo = adiantamento (cartaoDoSaldo). */
    a_receber: number;
    /** Mês de hoje 'AAAA-MM' (fuso da empresa). */
    mes: string;
    /** Pagamentos não estornados do mês. */
    recebido_no_mes: number;
    /** Mais novo primeiro, até 5. */
    pagamentos: PagamentoDaCarteira[];
    pix: { chave: string; tipo: string } | null;
};
```

2. Troque o comentário de `TIPOS_DE_GANHO`:

```ts
// tipos de crédito que contam como "ganhos" no resumo do período (só os positivos: ajuste e saldo inicial também debitam)
```

por:

```ts
// créditos de trabalho: o estorno de um deles (valor original positivo) desfaz um ganho (oQueOEstornoDesfaz)
```

3. Apague as funções `totaisDoPeriodo` e `aReceberNoPeriodo` inteiras (com os docblocks) e ponha no lugar:

```ts
/**
 * O número grande do topo do Início (decisão de 2026-10-24): só o que ele fez no período. Soma os lançamentos do período,
 * menos os pagamentos e os estornos de pagamento; entram corrida, diária, bônus, ajuste (o "Não pagar" desconta), saldo
 * inicial e estorno de crédito. Pode dar negativo (estorno hoje de uma corrida do mês passado). O que falta receber e o que
 * ele recebeu ficam na "Minha carteira" (GET v1/entregas/motoboy/carteira).
 */
export function ganhosDoPeriodo(lancamentos: Lancamento[]): number {
    const porId = new Map<number, Lancamento>();
    for (const lancamento of lancamentos) porId.set(lancamento.id, lancamento);

    let total = 0;
    for (const lancamento of lancamentos) {
        if (lancamento.tipo === 'pagamento' || lancamento.tipo === 'recebimento') continue;
        if (lancamento.tipo === 'estorno' && oQueOEstornoDesfaz(lancamento, porId) === 'pagamento') continue;
        total += centavos(lancamento.valor);
    }
    return total / 100;
}

/** 'AAAA-MM' da carteira em 'AAAA-MM-01' (para o nome do mês com o date-fns), ou null se não for um mês válido. */
export function primeiroDiaDoMes(mes: string | null | undefined): string | null {
    if (!mes || !/^\d{4}-(0[1-9]|1[0-2])$/.test(mes)) return null;
    return `${mes}-01`;
}
```

- [ ] **Passo 4: rodar e ver passar**

```bash
cd "C:/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && node --experimental-strip-types --test scripts/testes/extrato.teste.ts 2>&1 | tail -8
```

Esperado: `# fail 0`.

- [ ] **Passo 5: commit** (o `MeusGanhosScreen` ainda importa as funções antigas. O tsc só passa de novo depois da Tarefa 6, e por isso não há push no meio.)

```bash
cd "C:/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && git rev-parse --show-toplevel && git add src/utils/extrato.ts scripts/testes/extrato.teste.ts && git commit -m "Extrato: ganhosDoPeriodo (sem pagamentos) e o tipo da Minha carteira

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 4: textos (app)

**Files:**
- Modify: `N:/translations/pt.json`, `N:/translations/en.json`

- [ ] **Passo 1: aplicar com um script que só mexe nessas chaves**

Salve em `C:/Users/Edgardjr/AppData/Local/Temp/claude/textos-carteira.mjs` e rode na raiz do app:

```js
// Troca as chaves do topo antigo do Início pelas de "Ganhos · período" e da "Minha carteira" (pt e en).
import { readFileSync, writeFileSync } from 'node:fs';

const novos = {
    pt: {
        ganhosNoPeriodo: 'Ganhos · {{periodo}}',
        carteira: {
            botao: 'Minha carteira',
            titulo: 'Minha carteira',
            marca: 'Entregas',
            aReceber: 'A receber',
            aReceberDica: 'Tudo o que a central ainda vai te pagar.',
            adiantamento: 'Adiantamento',
            adiantamentoDica: 'Você recebeu {{valor}} a mais. Sai das próximas corridas.',
            recebidoNoMes: 'Recebido em {{mes}}',
            chavePix: 'Chave Pix',
            ultimosPagamentos: 'Últimos pagamentos',
            semPagamentos: 'Nenhum pagamento ainda.',
            erro: 'Não foi possível carregar a carteira.',
            tentarDeNovo: 'Tentar de novo',
            fechar: 'Fechar',
        },
    },
    en: {
        ganhosNoPeriodo: 'Earnings · {{periodo}}',
        carteira: {
            botao: 'My wallet',
            titulo: 'My wallet',
            marca: 'Entregas',
            aReceber: 'To receive',
            aReceberDica: 'Everything the dispatch still owes you.',
            adiantamento: 'Advance',
            adiantamentoDica: 'You received {{valor}} in advance. It comes out of your next rides.',
            recebidoNoMes: 'Received in {{mes}}',
            chavePix: 'Pix key',
            ultimosPagamentos: 'Latest payments',
            semPagamentos: 'No payments yet.',
            erro: 'Could not load your wallet.',
            tentarDeNovo: 'Try again',
            fechar: 'Close',
        },
    },
};
const SAEM = ['aReceberNoPeriodo', 'recebidoAMais', 'recebidoAMaisDica', 'ultimoPagamento', 'semPagamento', 'noPeriodo', 'pix'];

for (const idioma of ['pt', 'en']) {
    const arquivo = `translations/${idioma}.json`;
    const original = readFileSync(arquivo, 'utf8');
    const json = JSON.parse(original);
    const extrato = { ganhosNoPeriodo: novos[idioma].ganhosNoPeriodo };
    for (const [chave, valor] of Object.entries(json.Extrato)) if (!SAEM.includes(chave)) extrato[chave] = valor;
    json.Extrato = extrato;
    json.Carteira = novos[idioma].carteira;
    const final = original.endsWith('\n') ? '\n' : '';
    writeFileSync(arquivo, JSON.stringify(json, null, 4) + final);
}
```

```bash
cd "C:/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && node C:/Users/Edgardjr/AppData/Local/Temp/claude/textos-carteira.mjs && git diff --stat translations/
```

- [ ] **Passo 2: conferir que o diff só mexe no que devia**

```bash
cd "C:/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && git diff translations/pt.json | grep '^[-+]' | grep -v '^[-+][-+]'
```

Esperado: só as 7 chaves que saem (`-`), a `ganhosNoPeriodo` e o bloco `Carteira` (`+`). Se aparecerem outras linhas (por exemplo, um caractere escapado que o `JSON.stringify` reescreveu), desfaça com `git checkout translations/` e faça a troca à mão com o Edit: apague as 7 linhas do bloco `"Extrato"`, ponha `"ganhosNoPeriodo"` como a primeira chave dele e cole o bloco `"Carteira"` no fim do arquivo, antes do `}` final.

- [ ] **Passo 3: commit**

```bash
cd "C:/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && git add translations/pt.json translations/en.json && git commit -m "Textos: Ganhos · período e Minha carteira (sai o topo antigo do Início)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 5: `FolhaDaCarteira` (app)

**Files:**
- Create: `N:/src/components/entregas/FolhaDaCarteira.tsx`

Visual: sketch 008 B (abra `N:/.planning/sketches/008-ganhos-e-carteira/index.html#b`, tela "Minha carteira"). Padrão de folha: a `FolhaDoPeriodo` em `src/screens/MeusGanhosScreen.tsx` (Portal + BottomSheet + véu + voltar do Android).

- [ ] **Passo 1: criar o componente**

```tsx
import { useCallback, useEffect, useRef, useState } from 'react';
import { BackHandler, Pressable } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import BottomSheet, { BottomSheetBackdrop, BottomSheetView } from '@gorhom/bottom-sheet';
import type { BottomSheetBackdropProps } from '@gorhom/bottom-sheet';
import { Portal } from '@gorhom/portal';
import { Text, XStack, YStack, useTheme } from 'tamagui';
import { FontAwesomeIcon } from '@fortawesome/react-native-fontawesome';
import { faChevronRight, faRotateRight, faTimes, faTriangleExclamation, faWallet } from '@fortawesome/free-solid-svg-icons';
import { format } from 'date-fns';
import { useLanguage } from '../../contexts/LanguageContext';
import useFleetbase from '../../hooks/use-fleetbase';
import { dateFnsLocaleOptions } from '../../utils/localize';
import { deIso, formatarReais } from '../../utils/ganhos';
import { cartaoDoSaldo, formatarDiaMes, primeiroDiaDoMes, rotuloDaForma } from '../../utils/extrato';
import type { Carteira } from '../../utils/extrato';
import SafeSpinner from '../SafeSpinner';
import Selo from './Selo';

// Entregas: a "Minha carteira" do Início (sketch 008 B, decisão de 2026-10-24). Uma folha com o cartão escuro (A receber ou
// Adiantamento, Recebido no mês e a chave Pix) e os 5 últimos pagamentos; o toque num pagamento fecha a folha e abre o Recibo.
// Não depende do período do Início: lê GET v1/entregas/motoboy/carteira a cada abertura. Sem a rota (API antiga) ou sem rede,
// mostra "Não foi possível carregar a carteira" com "Tentar de novo".

type Props = { aoFechar: () => void; aoAbrirRecibo: (id: string) => void };

const FolhaDaCarteira = ({ aoFechar, aoAbrirRecibo }: Props) => {
    const theme = useTheme();
    const insets = useSafeAreaInsets();
    const { t } = useLanguage();
    const { adapter } = useFleetbase();
    const adapterRef = useRef(adapter);
    adapterRef.current = adapter;
    const folhaRef = useRef<BottomSheet>(null);
    // o recibo só abre depois que a folha fecha (a folha fica no Portal, por cima de qualquer tela)
    const reciboRef = useRef<string | null>(null);
    const [carteira, setCarteira] = useState<Carteira | null>(null);
    const [erro, setErro] = useState(false);

    const carregar = useCallback(async () => {
        const api = adapterRef.current;
        if (!api) return;
        setErro(false);
        setCarteira(null);
        try {
            setCarteira(await api.get('entregas/motoboy/carteira'));
        } catch (falha) {
            console.warn('[carteira] erro ao carregar:', falha);
            setErro(true);
        }
    }, []);

    useEffect(() => {
        carregar();
    }, [carregar]);

    const fechar = useCallback(() => folhaRef.current?.close(), []);
    const aoFecharAFolha = useCallback(() => {
        const recibo = reciboRef.current;
        reciboRef.current = null;
        aoFechar();
        if (recibo) aoAbrirRecibo(recibo);
    }, [aoFechar, aoAbrirRecibo]);
    const abrirRecibo = (id: string) => {
        reciboRef.current = id;
        fechar();
    };

    // véu escuro atrás da folha; tocar nele fecha (useCallback: um componente novo a cada render remontaria o véu)
    const renderizarFundo = useCallback(
        (props: BottomSheetBackdropProps) => <BottomSheetBackdrop {...props} appearsOnIndex={0} disappearsOnIndex={-1} pressBehavior='close' />,
        []
    );

    // o voltar do Android fecha a folha em vez de sair da tela
    useEffect(() => {
        const assinatura = BackHandler.addEventListener('hardwareBackPress', () => {
            fechar();
            return true;
        });
        return () => assinatura.remove();
    }, [fechar]);

    const saldo = cartaoDoSaldo(carteira?.a_receber);
    const diaDoMes = primeiroDiaDoMes(carteira?.mes);
    const nomeDoMes = diaDoMes ? format(deIso(diaDoMes), 'MMMM', dateFnsLocaleOptions()) : '';
    const chavePix = carteira?.pix?.chave;
    const pagamentos = carteira?.pagamentos ?? [];

    const rotulo = (texto: string, sobreOCartao = false) => (
        <Text color={sobreOCartao ? '$background' : '$textSecondary'} opacity={sobreOCartao ? 0.7 : 1} fontSize={12} fontWeight='700' letterSpacing={0.6} textTransform='uppercase'>
            {texto}
        </Text>
    );

    const cartao = (
        <YStack bg='$textPrimary' borderRadius={20} px={20} pt={20} pb={16} overflow='hidden' mt='$2' mb='$4'>
            {/* os dois círculos laranja do canto, a marca do cartão */}
            <YStack position='absolute' width={160} height={160} borderRadius={80} bg='$primary' top={-40} right={-40} opacity={0.9} />
            <YStack position='absolute' width={120} height={120} borderRadius={60} bg='$primary' top={-70} right={40} opacity={0.45} />
            <XStack alignItems='center' justifyContent='space-between' mb='$4'>
                <FontAwesomeIcon icon={faWallet} color={theme.primary.val} size={28} />
                <Text color='$background' fontSize={12} fontWeight='700' letterSpacing={0.6} textTransform='uppercase'>
                    {t('Carteira.marca')}
                </Text>
            </XStack>
            {!carteira ? (
                <YStack height={120} alignItems='center' justifyContent='center'>
                    <SafeSpinner color={theme.background.val} />
                </YStack>
            ) : (
                <>
                    {rotulo(t(saldo.adiantamento ? 'Carteira.adiantamento' : 'Carteira.aReceber'), true)}
                    <Text color='$background' fontFamily='$heading' fontWeight='800' fontSize={46} lineHeight={52} numberOfLines={1} adjustsFontSizeToFit>
                        {formatarReais(saldo.valor)}
                    </Text>
                    <Text color='$background' opacity={0.75} fontSize={13}>
                        {saldo.adiantamento ? t('Carteira.adiantamentoDica', { valor: formatarReais(saldo.valor) }) : t('Carteira.aReceberDica')}
                    </Text>
                    <YStack height={1} bg='$background' opacity={0.2} mt='$4' mb='$3' />
                    <XStack alignItems='flex-end' justifyContent='space-between' gap='$3'>
                        <YStack flexShrink={1}>
                            {rotulo(t('Carteira.recebidoNoMes', { mes: nomeDoMes }), true)}
                            <Text color='$background' fontFamily='$heading' fontWeight='700' fontSize={22}>
                                {formatarReais(carteira.recebido_no_mes)}
                            </Text>
                        </YStack>
                        {!!chavePix && (
                            <YStack alignItems='flex-end' flexShrink={1}>
                                <Text color='$background' opacity={0.75} fontSize={12}>
                                    {t('Carteira.chavePix')}
                                </Text>
                                <Text color='$background' fontSize={13} fontWeight='700' numberOfLines={1}>
                                    {chavePix}
                                </Text>
                            </YStack>
                        )}
                    </XStack>
                </>
            )}
        </YStack>
    );

    const lista = (
        <YStack>
            {rotulo(t('Carteira.ultimosPagamentos'))}
            {carteira && pagamentos.length === 0 && (
                <Text color='$textSecondary' fontSize={14} textAlign='center' py='$3'>
                    {t('Carteira.semPagamentos')}
                </Text>
            )}
            {pagamentos.map((pagamento, indice) => {
                const riscado = pagamento.estornado;
                return (
                    <Pressable key={pagamento.id} onPress={() => abrirRecibo(pagamento.id)} accessibilityRole='button'>
                        {({ pressed }) => (
                            <XStack
                                py='$3'
                                gap='$3'
                                alignItems='center'
                                bg={pressed ? '$surface' : 'transparent'}
                                borderBottomWidth={indice < pagamentos.length - 1 ? 1 : 0}
                                borderColor='$borderColor'
                            >
                                <Text width={48} color={riscado ? '$textSecondary' : '$textPrimary'} fontWeight='700' fontSize={15} textDecorationLine={riscado ? 'line-through' : 'none'}>
                                    {formatarDiaMes(pagamento.data)}
                                </Text>
                                <XStack flex={1} minWidth={0} gap='$2' alignItems='center'>
                                    <Text color='$textSecondary' fontSize={15} numberOfLines={1}>
                                        {t(rotuloDaForma(pagamento.forma))}
                                    </Text>
                                    {riscado && <Selo variante='perigo'>{t('Extrato.situacao.estornado')}</Selo>}
                                </XStack>
                                <Text
                                    color={riscado ? '$textSecondary' : '$textPrimary'}
                                    fontFamily='$heading'
                                    fontWeight='700'
                                    fontSize={18}
                                    textDecorationLine={riscado ? 'line-through' : 'none'}
                                >
                                    {formatarReais(pagamento.valor)}
                                </Text>
                                <FontAwesomeIcon icon={faChevronRight} color={theme.textSecondary.val} size={12} />
                            </XStack>
                        )}
                    </Pressable>
                );
            })}
        </YStack>
    );

    const falha = (
        <YStack alignItems='center' gap='$3' py='$6'>
            <FontAwesomeIcon icon={faTriangleExclamation} color={theme.error.val} size={28} />
            <Text color='$textPrimary' fontFamily='$heading' fontWeight='700' fontSize={20} textAlign='center'>
                {t('Carteira.erro')}
            </Text>
            <Pressable onPress={carregar} accessibilityRole='button'>
                <XStack height={44} px='$4' borderRadius={12} borderWidth={1.5} borderColor='$borderColor' alignItems='center' gap='$2'>
                    <FontAwesomeIcon icon={faRotateRight} color={theme.textPrimary.val} size={14} />
                    <Text color='$textPrimary' fontWeight='700' fontSize={15}>
                        {t('Carteira.tentarDeNovo')}
                    </Text>
                </XStack>
            </Pressable>
        </YStack>
    );

    return (
        <Portal hostName='MainPortal'>
            <BottomSheet
                ref={folhaRef}
                index={0}
                enablePanDownToClose
                onClose={aoFecharAFolha}
                backdropComponent={renderizarFundo}
                backgroundStyle={{ backgroundColor: theme.cartao.val }}
                handleIndicatorStyle={{ backgroundColor: theme.borderColor.val, width: 40 }}
            >
                <BottomSheetView style={{ paddingHorizontal: 16, paddingBottom: insets.bottom + 16 }}>
                    <XStack alignItems='center' justifyContent='space-between'>
                        <Text color='$textPrimary' fontFamily='$heading' fontWeight='700' fontSize={26}>
                            {t('Carteira.titulo')}
                        </Text>
                        <Pressable onPress={fechar} hitSlop={10} accessibilityRole='button' accessibilityLabel={t('Carteira.fechar')}>
                            <YStack width={40} height={40} borderRadius={20} alignItems='center' justifyContent='center'>
                                <FontAwesomeIcon icon={faTimes} color={theme.textPrimary.val} size={18} />
                            </YStack>
                        </Pressable>
                    </XStack>
                    {erro ? (
                        falha
                    ) : (
                        <>
                            {cartao}
                            {lista}
                        </>
                    )}
                </BottomSheetView>
            </BottomSheet>
        </Portal>
    );
};

export default FolhaDaCarteira;
```

- [ ] **Passo 2: conferir o `Portal` e os tokens antes de seguir**

```bash
cd "C:/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && grep -n "<Portal" src/screens/MeusGanhosScreen.tsx && grep -rn "error:\|cartao:\|surface:" src/theme* tamagui.config.* 2>/dev/null | head
```

Na escrita do plano (2026-10-24), a `FolhaDoPeriodo` usava `<Portal hostName='MainPortal'>` e o tema tinha `$error`, `$cartao` e `$surface`. Esta conferência só pega uma mudança feita depois: o `<Portal …>` do componente precisa ser **igual** ao da `FolhaDoPeriodo`.

- [ ] **Passo 3: tipos**

```bash
cd "C:/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && npx tsc --noEmit -p . 2>&1 | grep "FolhaDaCarteira" ; echo "fim"
```

Esperado: nenhuma linha antes de `fim`. Os erros do `MeusGanhosScreen` (imports antigos) somem na Tarefa 6.

- [ ] **Passo 4: commit**

```bash
cd "C:/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && git add src/components/entregas/FolhaDaCarteira.tsx && git commit -m "Minha carteira: folha com o cartão escuro e os últimos pagamentos (sketch 008 B)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 6: topo do Início com "Ganhos · período" e o botão (app)

**Files:**
- Modify: `N:/src/screens/MeusGanhosScreen.tsx`

- [ ] **Passo 1: imports**

No import de `'../utils/extrato'`, troque `aReceberNoPeriodo,` por `ganhosDoPeriodo,` (a lista fica em ordem alfabética: mova o `ganhosDoPeriodo` para depois de `formatarDiaMes`) e apague `totaisDoPeriodo,`. Logo depois do import do `DriverOnlineToggle`, acrescente:

```tsx
import FolhaDaCarteira from '../components/entregas/FolhaDaCarteira';
```

- [ ] **Passo 2: comentário do topo do arquivo**

Troque o trecho:

```tsx
// Entregas: Início do app = Meus ganhos, que é o extrato do motoboy (Financeiro; rota v1/entregas/motoboy/extrato): o valor
// "A receber · <período>" (soma dos lançamentos do período, aReceberNoPeriodo) com o último pagamento, e os lançamentos do período (corridas com a situação
```

por:

```tsx
// Entregas: Início do app = Meus ganhos, que é o extrato do motoboy (Financeiro; rota v1/entregas/motoboy/extrato): o valor
// "Ganhos · <período>" (só o que ele fez no período, sem os pagamentos: ganhosDoPeriodo; decisão de 2026-10-24) com o botão
// "Minha carteira" (FolhaDaCarteira: a receber, recebido no mês, últimos pagamentos e Pix), e os lançamentos do período (corridas com a situação
```

- [ ] **Passo 3: estado da folha**

Junto do `const [calendarioAberto, setCalendarioAberto] = useState(false);` (procure com `grep -n "calendarioAberto, set" src/screens/MeusGanhosScreen.tsx`), acrescente:

```tsx
    const [carteiraAberta, setCarteiraAberta] = useState(false);
```

- [ ] **Passo 4: o valor**

Troque:

```tsx
    // o número grande acompanha o filtro: o que falta receber pelo período, já descontado o que ele recebeu nele
    const aReceber = useMemo(() => (dados ? aReceberNoPeriodo(dados.lancamentos ?? []) : null), [dados]);
    const saldo = cartaoDoSaldo(aReceber);
```

por:

```tsx
    // o número grande acompanha o filtro: só o que ele fez no período, sem os pagamentos (decisão de 2026-10-24)
    const ganhos = useMemo(() => (dados ? ganhosDoPeriodo(dados.lancamentos ?? []) : null), [dados]);
```

e troque:

```tsx
    // o mesmo valor na barra compacta (negativo: "-R$ 50,00", recebeu a mais no período)
    const totalNaTela = aReceber === null ? '—' : formatarReais(aReceber);
```

por:

```tsx
    // o mesmo valor no topo e na barra compacta (negativo: "-R$ 8,50", um estorno no período maior que os ganhos)
    const totalNaTela = ganhos === null ? '—' : formatarReais(ganhos);
```

- [ ] **Passo 5: tirar as linhas de baixo do topo antigo**

Apague o bloco inteiro, do comentário `// linhas abaixo do saldo: último pagamento e o resumo do período` até a linha `const chavePix = dados?.conta?.pix?.chave;` (inclusive): `ultimoPagamento`, `totaisDoResumo`, `resumoDoPeriodo` e `chavePix`.

- [ ] **Passo 6: o novo bloco do valor no topo**

No `const topo = (…)`, troque o `<YStack mt='$3' pb='$1'>` inteiro: começa no `<Text …>{t('Extrato.aReceberNoPeriodo', …)}</Text>` e termina depois do `{!!chavePix && (…)}`, no `</YStack>` antes do `</YStack>` final do topo. Ponha no lugar:

```tsx
            <YStack mt='$3' pb='$1'>
                <Text color='$sobrePrimario' fontSize={12} fontWeight='700' letterSpacing={0.6} textTransform='uppercase' opacity={0.8} numberOfLines={1}>
                    {t('Extrato.ganhosNoPeriodo', { periodo: rotuloDoPeriodo })}
                </Text>
                <XStack alignItems='center' gap='$3'>
                    <YStack flex={1} minWidth={0}>
                        {!dados && carregando ? (
                            <YStack py={4}>
                                <Esqueleto largura={180} altura={44} sobreLaranja />
                            </YStack>
                        ) : (
                            <Text color='$sobrePrimario' fontFamily='$heading' fontWeight='800' fontSize={46} lineHeight={52} numberOfLines={1} adjustsFontSizeToFit>
                                {totalNaTela}
                            </Text>
                        )}
                    </YStack>
                    {/* sketch 008 B: a carteira escura com o ícone laranja e "Minha carteira" embaixo */}
                    <Pressable onPress={() => setCarteiraAberta(true)} accessibilityRole='button' accessibilityLabel={t('Carteira.botao')}>
                        {({ pressed }) => (
                            <YStack alignItems='center' gap={4} opacity={pressed ? 0.8 : 1}>
                                <YStack width={56} height={56} borderRadius={18} bg='$textPrimary' alignItems='center' justifyContent='center'>
                                    <FontAwesomeIcon icon={faWallet} color={theme.primary.val} size={26} />
                                </YStack>
                                <Text color='$sobrePrimario' fontSize={12} fontWeight='700'>
                                    {t('Carteira.botao')}
                                </Text>
                            </YStack>
                        )}
                    </Pressable>
                </XStack>
            </YStack>
```

- [ ] **Passo 7: abrir a folha**

Logo depois da linha `{calendarioAberto && <FolhaDoPeriodo … />}`, acrescente:

```tsx
            {carteiraAberta && <FolhaDaCarteira aoFechar={() => setCarteiraAberta(false)} aoAbrirRecibo={(id) => navigation.navigate('Recibo', { id })} />}
```

- [ ] **Passo 8: limpar imports que sobraram**

```bash
cd "C:/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && for nome in cartaoDoSaldo formatarDiaMes rotuloDaForma aReceber saldo; do echo "$nome: $(grep -c "\b$nome\b" src/screens/MeusGanhosScreen.tsx)"; done
```

Quem tiver contagem `1` só aparece no import: apague do import. `aReceber` e `saldo` precisam dar `0`. Se der mais, há um uso que sobrou do topo antigo: procure com `grep -n` e remova.

- [ ] **Passo 9: tipos, lint e testes**

```bash
cd "C:/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && npx tsc --noEmit -p . 2>&1 | grep -E "MeusGanhosScreen|FolhaDaCarteira|extrato" ; echo "tsc fim" ; npx eslint src/screens/MeusGanhosScreen.tsx src/components/entregas/FolhaDaCarteira.tsx src/utils/extrato.ts ; for t in extrato ganhos inicio-do-motoboy; do node --experimental-strip-types --test scripts/testes/$t.teste.ts 2>&1 | grep -E "^# (pass|fail)"; done
```

Esperado: nenhuma linha antes de `tsc fim`, o eslint sem erro e `# fail 0` nos três testes.

- [ ] **Passo 10: nenhuma referência às chaves que saíram**

```bash
cd "C:/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && grep -rn "Extrato\.\(aReceberNoPeriodo\|recebidoAMais\|ultimoPagamento\|semPagamento\|noPeriodo\|pix\)\b\|aReceberNoPeriodo\|totaisDoPeriodo" src scripts ; echo "fim"
```

Esperado: nada antes de `fim`.

- [ ] **Passo 11: commit**

```bash
cd "C:/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && git add src/screens/MeusGanhosScreen.tsx && git commit -m "Início: topo com Ganhos · período e o botão Minha carteira

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Tarefa 7: revisão contra o sketch

- [ ] **Passo 1:** compare o JSX do topo e da folha com o sketch 008 B (`N:/.planning/sketches/008-ganhos-e-carteira/index.html#b`). Os três pontos são:
  - o botão à direita do valor (56 px, cantos de 18, fundo escuro, ícone laranja, texto embaixo);
  - o cartão escuro com os dois círculos laranja, A receber/Adiantamento, a dica, o fio, Recebido no mês à esquerda e a chave Pix à direita;
  - a lista com data, forma, valor e seta, com o estornado riscado e o selo "Estornado".

  Corrija o que estiver diferente. Não há emulador no PC: a conferência no celular fica para o Edgard, depois do APK.
- [ ] **Passo 2:** a barra compacta (cabeçalho recolhido ao rolar) continua com `{totalNaTela}` e sem o botão. Confira com `grep -n "totalNaTela" src/screens/MeusGanhosScreen.tsx`: o valor aparece no topo e na barra.

---

### Tarefa 8: documentação (sem commit dos CLAUDE.md fora do git)

**Files:**
- Modify: `D:/api/app/Http/Controllers/Entregas/CLAUDE.md` e a cópia em `N:/CLAUDE.md` (seção "App do motoboy": as duas precisam ficar iguais)
- Modify: `D:/api/app/Support/Entregas/Financeiro/CLAUDE.md`

- [ ] **Passo 1:** na seção "Início = extrato do Financeiro", nos dois arquivos, troque o item que começa com `` - Cartão **"A receber · <período>"** `` e o item que começa com `` - **Totais do período calculados no app** `` por:

```markdown
  - Topo **"Ganhos · <período>"** (decisão de 2026-10-24, sketch 008 B; spec `docs/superpowers/specs/2026-10-24-ganhos-e-carteira-design.md`): só o que ele fez no período, acompanhando o filtro = soma dos lançamentos do período **menos os pagamentos e os estornos de pagamento** (`ganhosDoPeriodo` em `src/utils/extrato.ts`): corrida, diária, bônus, ajuste (o "Não pagar" desconta), saldo inicial e estorno de crédito. Pode dar negativo. A barra compacta mostra o mesmo valor. Antes (2026-10-20 a 24) o topo era "A receber · <período>" com os pagamentos, e um pagamento de hoje por corridas antigas virava "Recebido a mais": por isso saiu.
  - Botão **"Minha carteira"** à direita do valor (carteira escura, ícone laranja) → `FolhaDaCarteira` (`src/components/entregas/FolhaDaCarteira.tsx`): cartão escuro com A receber (o saldo da conta; negativo = "Adiantamento"), Recebido em <mês> (pagamentos não estornados do mês) e a chave Pix; embaixo os 5 últimos pagamentos (estornado riscado; toque fecha a folha e abre o Recibo). Lê `GET v1/entregas/motoboy/carteira` a cada abertura; sem a rota, "Não foi possível carregar a carteira".
```

- [ ] **Passo 2:** no item da API do mesmo trecho (o que começa com `` - API (`MotoboyController` ``), nos dois arquivos, logo depois de `` `GET v1/entregas/motoboy/extrato?inicio&fim` (…) `` acrescente:

```markdown
, `GET v1/entregas/motoboy/carteira` (`Carteira::doMotoboy`: `{a_receber, mes, recebido_no_mes, pagamentos[5], pix}`, a conta inteira; o mês no fuso da empresa)
```

  E na "Ordem de implantação" do mesmo trecho, acrescente: "A carteira depende da rota `carteira`: deploy da API antes do APK (com o APK novo e a API antiga só a carteira dá erro)."

- [ ] **Passo 3:** em `D:/api/app/Support/Entregas/Financeiro/CLAUDE.md`, na linha "Classes (`App\Support\Entregas\Financeiro`)", acrescente `` `Carteira` (a "Minha carteira" do app) `` depois de `` `ChavePix` ``. No item dos testes que cita o `financeiro-console.php`, troque `MotoboyController@extrato e @pagamento` por `MotoboyController@extrato, @pagamento e @carteira, Carteira`.

- [ ] **Passo 4:** confira se as duas seções "App do motoboy" ficaram iguais:

```bash
cd "C:/Users/Edgardjr/Documents/vibe coding" && diff <(sed -n '/Início = extrato do Financeiro/,/Aba Pedidos/p' Delivery/api/app/Http/Controllers/Entregas/CLAUDE.md) <(sed -n '/Início = extrato do Financeiro/,/Aba Pedidos/p' entregas-navigator/CLAUDE.md) && echo iguais
```

Esperado: `iguais`. **Sem commit** desses três arquivos (ver "Regras").

---

### Tarefa 9: fechamento

- [ ] **Passo 1: todos os testes de novo**

```bash
cd "C:/Users/Edgardjr/Documents/vibe coding/Delivery" && for t in financeiro-console financeiro-pagamentos app-do-motoboy ganhos-motoboy imports-dos-providers; do echo "== $t: $(PHP_WASM_DIR=C:/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php | tail -1)"; done
cd "C:/Users/Edgardjr/Documents/vibe coding/entregas-navigator" && for t in extrato ganhos inicio-do-motoboy; do echo "== $t: $(node --experimental-strip-types --test scripts/testes/$t.teste.ts 2>&1 | grep '^# fail')"; done
```

Esperado: `FALHAS: 0` e `# fail 0` em todos.

- [ ] **Passo 2: relatório ao Edgard (sem push)**

Diga o que ficou pronto, os commits dos dois repos e a ordem de implantação:
1. Push do `Delivery` e `bash deploy/atualizar.sh api` na VPS (a rota `carteira`).
2. Só depois, o push na `main` do `entregas-navigator`, que gera o APK obrigatório.
3. Conferir no celular: o topo em Hoje e em 7 dias, a carteira aberta, o toque num pagamento abrindo o Recibo e o tema escuro.

Atualize a memória `ganhos-e-carteira.md` com os commits e o que falta.
