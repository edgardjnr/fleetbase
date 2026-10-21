# Entrega junto e multiplicador: servidor (API) — plano de implementação

> **Para agentes:** SUB-SKILL OBRIGATÓRIA: use superpowers:subagent-driven-development (recomendado) ou superpowers:executing-plans para executar este plano tarefa por tarefa. Os passos usam caixas (`- [ ]`) para acompanhar.

**Objetivo:** a API passa a calcular o valor de cada entrega com a segunda tabela de faixas (pedido pego enquanto o motoboy leva outro) e com o multiplicador do período (chuva), congelados na atribuição do motoboy, e expõe as rotas do console.

**Arquitetura:** a linha de `entregas_valores_pedido` ganha a parte da segunda tabela (congelada com o km) e a parte da atribuição (motoboy, instante, posição e multiplicador). O valor final sai sempre de uma função pura (`ValorDaEntrega::final`). Um observador de `Order::updated` grava a atribuição quando o `driver_assigned_uuid` muda. Os períodos ficam em `entregas_multiplicadores` (classe `Financeiro\Multiplicadores` + `MultiplicadoresController`). Para o pedido que escapou do observador, o `CalculoEntregas::entregas()` reconstitui a atribuição pelos horários.

**Stack:** Laravel (PHP 8.2) em `api/app`, testes php-wasm em `scripts/teste-php/`.

**Spec:** `docs/superpowers/specs/2026-10-21-entrega-junto-e-multiplicador-design.md`.

**Planos irmãos:** `2026-10-21-entrega-junto-e-multiplicador-console-portal.md` (telas do console e do portal) e `2026-10-21-entrega-junto-e-multiplicador-app.md` (APK). Este vai primeiro: os campos novos da API são opcionais, então o console e o app antigos continuam funcionando.

---

## Antes de começar

- Trabalhe numa worktree própria (`superpowers:using-git-worktrees`), ramo `entrega-junto`, a partir da `main` do `C:\Users\Edgardjr\Documents\vibe coding\Delivery`. Confira `git rev-parse --show-toplevel` antes de cada commit: a home `C:\Users\Edgardjr` também é um repo git.
- php-wasm: a instalação fica em `/c/tmp/php-wasm` (se não existir: `npm i --prefix /c/tmp/php-wasm @php-wasm/node@3.1.54 @php-wasm/universal@3.1.54`). Todos os comandos de teste abaixo rodam **na raiz do repo**, no Git Bash:
  - um teste: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/<arquivo>.php` (passa quando a saída termina em `FALHAS: 0`);
  - sintaxe: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs <arquivos.php...>`.
- Fuso: tudo em hora de Brasília, texto `'Y-m-d H:i:s'` sem fuso (ver `api/CLAUDE.md`, "Fuso"). Nunca formate em UTC.
- Comentários e mensagens em pt-BR, no estilo dos arquivos vizinhos.

## Mapa dos arquivos

| Arquivo | Papel |
|---|---|
| `api/database/migrations/2026_10_21_100000_create_entregas_financeiro_multiplicadores_table.php` (novo) | tabela `entregas_multiplicadores`. O nome casa com o glob `*_entregas_financeiro_*.php` do banco em memória dos testes |
| `api/database/migrations/2026_10_21_100100_add_junto_e_multiplicador_to_entregas_valores_pedido_table.php` (novo) | colunas novas do valor congelado |
| `api/app/Support/Entregas/ValorDaEntrega.php` (novo) | funções puras: valor final, posição por horários, fator visível a cada conta |
| `api/app/Support/Entregas/Financeiro/Multiplicadores.php` (novo) | períodos: consulta, regras, sobreposição |
| `api/app/Support/Entregas/AtribuicaoDoValor.php` (novo) | consultas da atribuição (pedidos em aberto, posições por horários, períodos), trocável nos testes |
| `api/app/Support/Entregas/ValoresCongelados.php` | colunas da segunda tabela no recongelamento; `gravarAtribuicoes` |
| `api/app/Support/Entregas/CalculoEntregas.php` | segunda tabela, valor final, prévia, `registrarAtribuicao`, reserva por horários |
| `api/app/Listeners/Entregas/ObservadorDoValorDaEntrega.php` (novo) | `Order::updated` → `registrarAtribuicao` |
| `api/app/Providers/AppServiceProvider.php` | registra o observador |
| `api/app/Support/Entregas/Financeiro/LancadorDeCorridas.php` | `detalhes` por conta (`junto`, `multiplicador`) |
| `api/app/Support/Entregas/Financeiro/ExtratoDaConta.php` | devolve `junto` e `multiplicador` dos detalhes |
| `api/app/Support/Entregas/GanhosDoMotoboy.php` | `junto` e `multiplicador` (do motoboy) para o app |
| `api/app/Http/Controllers/Entregas/MotoboyController.php` | prévia com o motoboy da sessão |
| `api/app/Http/Controllers/Entregas/PortalLojaController.php` | `junto` e `multiplicador` (da loja) no extrato do portal |
| `api/app/Http/Controllers/Entregas/PagamentoMotoboysController.php` | `faixas_juntas` no GET; `PUT .../faixas-juntas` |
| `api/app/Http/Controllers/Entregas/MultiplicadoresController.php` (novo) | rotas dos períodos |
| `api/app/Providers/RouteServiceProvider.php` | rotas novas |
| `scripts/teste-php/stubs-ganhos.php`, `scripts/teste-php/stubs-financeiro.php` | o que os testes novos precisam |
| `scripts/teste-php/entrega-junto.php` (novo), `scripts/teste-php/financeiro-multiplicadores.php` (novo) | testes |

### Decisões tomadas no plano (diferem do texto da spec)

- **Push de alarme:** o `AvisosDoMotoboy::cartao` não sabe para quem vai o push (só recebe a notificação). Então o cartão do alarme mostra sempre a prévia sem posição (tabela normal × multiplicador de agora). O card de aceitar dentro do app, que é onde o motoboy decide, mostra a prévia com a posição dele. A Tarefa 12 atualiza a spec.
- **Edição do período:** `PUT financeiro/multiplicadores/{id}` (o console usa `fetch.put` em todo o Financeiro), não `PATCH`.
- **Colunas `criado_por` e `encerrado_por`**, como `entregas_lancamentos.criado_por`.

---

### Tarefa 1: Migrations e stubs

**Arquivos:**
- Criar: `api/database/migrations/2026_10_21_100000_create_entregas_financeiro_multiplicadores_table.php`
- Criar: `api/database/migrations/2026_10_21_100100_add_junto_e_multiplicador_to_entregas_valores_pedido_table.php`
- Modificar: `scripts/teste-php/stubs-ganhos.php`
- Modificar: `scripts/teste-php/stubs-financeiro.php`

- [ ] **Passo 1: Migration dos períodos**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: multiplicadores por período do Financeiro (chuva, intempérie). A entrega atribuída dentro do
 * período vale a faixa × o fator (App\Support\Entregas\Financeiro\Multiplicadores). Horários em Brasília, como as
 * DATETIME do Fleet-Ops. Roda no `php artisan migrate --force` do deploy.sh, no banco principal e no sandbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_multiplicadores', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->char('company_uuid', 36);
            $table->string('nome', 40);
            $table->decimal('mult_motoboy', 4, 2);       // 1.00 a 5.00
            $table->decimal('mult_loja', 4, 2);          // 1.00 a 5.00 (1.00 = a loja paga o normal)
            $table->dateTime('inicio');                  // Brasília; vale para atribuição em [inicio, fim)
            $table->dateTime('fim');
            $table->char('criado_por', 36)->nullable();  // users.uuid
            $table->char('encerrado_por', 36)->nullable();
            $table->timestamps();
            $table->dateTime('deleted_at')->nullable();  // só o agendado é apagado
            $table->index(['company_uuid', 'inicio'], 'ent_mult_empresa_inicio');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_multiplicadores');
    }
};
```

- [ ] **Passo 2: Migration das colunas novas do valor congelado**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: entrega junto e multiplicador no valor congelado (CalculoEntregas, ValoresCongelados,
 * ValorDaEntrega). Parte do km: a faixa e os valores da segunda tabela ("a partir da 2ª entrega junto"), congelados
 * com a tabela normal. Parte da atribuição: motoboy, instante, posição e o multiplicador do período (cópias). Todas
 * nulas: as linhas antigas valem como antes.
 */
return new class extends Migration
{
    public const COLUNAS = [
        'junto_de_km', 'junto_ate_km', 'junto_acima', 'junto_motoboy', 'junto_loja',
        'motoboy_uuid', 'atribuido_em', 'posicao', 'multiplicador_uuid', 'multiplicador_nome', 'mult_motoboy', 'mult_loja',
    ];

    public function up(): void
    {
        Schema::table('entregas_valores_pedido', function (Blueprint $table) {
            $table->decimal('junto_de_km', 8, 2)->nullable();
            $table->decimal('junto_ate_km', 8, 2)->nullable();
            $table->boolean('junto_acima')->nullable();
            $table->decimal('junto_motoboy', 10, 2)->nullable();
            $table->decimal('junto_loja', 10, 2)->nullable();
            $table->char('motoboy_uuid', 36)->nullable();
            $table->dateTime('atribuido_em')->nullable();   // Brasília
            $table->unsignedTinyInteger('posicao')->nullable();
            $table->char('multiplicador_uuid', 36)->nullable();
            $table->string('multiplicador_nome', 40)->nullable();
            $table->decimal('mult_motoboy', 4, 2)->nullable();
            $table->decimal('mult_loja', 4, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('entregas_valores_pedido', function (Blueprint $table) {
            $table->dropColumn(self::COLUNAS);
        });
    }
};
```

- [ ] **Passo 3: `stubs-ganhos.php`: `Schema::table`, sessão gravável, horários do pedido e colunas novas como o MySQL devolve**

No `class Schema` (namespace `Illuminate\Support\Facades`), acrescente depois do `dropIfExists`:

```php
        public static array $alteradas = [];

        public static function table(string $tabela, \Closure $definicao): void
        {
            $blueprint = new \Illuminate\Database\Schema\Blueprint();
            $definicao($blueprint);
            self::$alteradas[$tabela][] = $blueprint;
        }
```

No `class Order` (namespace `Fleetbase\FleetOps\Models`), acrescente depois de `public $entregas_cancelado_origem;`:

```php
        public $started_at            = null; // DATETIME em hora de Brasília (aceite)
        public $dispatched_at         = null;
```

No `Teste\Tabela::comoMysql`, depois do `foreach` das quatro colunas, acrescente:

```php
            // colunas da entrega junto e do multiplicador (nulas nas linhas antigas)
            foreach (['junto_de_km', 'junto_ate_km', 'junto_motoboy', 'junto_loja', 'mult_motoboy', 'mult_loja'] as $coluna) {
                if (isset($linha[$coluna])) {
                    $linha[$coluna] = number_format((float) $linha[$coluna], 2, '.', '');
                }
            }
            if (isset($linha['junto_acima'])) {
                $linha['junto_acima'] = (int) !empty($linha['junto_acima']);
            }
```

Troque a função global `session` por:

```php
    function session($chave = null)
    {
        // session(['company' => x]) grava, como no Laravel
        if (is_array($chave)) {
            $GLOBALS['sessao'] = array_merge($GLOBALS['sessao'], $chave);

            return null;
        }

        return $chave === null ? $GLOBALS['sessao'] : ($GLOBALS['sessao'][$chave] ?? null);
    }
```

- [ ] **Passo 4: `stubs-financeiro.php`: `Setting::configureCompany`**

No `class Setting` do stub, depois do `configure`:

```php
            public static function configureCompany(string $chave, $valor = null) { self::$valores['company.' . session('company') . '.' . $chave] = $valor; return null; }
```

- [ ] **Passo 5: Sintaxe e os testes que já existem**

Rode:
```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/database/migrations/2026_10_21_100000_create_entregas_financeiro_multiplicadores_table.php api/database/migrations/2026_10_21_100100_add_junto_e_multiplicador_to_entregas_valores_pedido_table.php scripts/teste-php/stubs-ganhos.php scripts/teste-php/stubs-financeiro.php
for t in ganhos-motoboy financeiro-lancador financeiro-console financeiro-lojas financeiro-faturas financeiro-portal-emails; do PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php | tail -1; done
```
Esperado: sintaxe sem erro; todos com `FALHAS: 0`. A migration nova entra no esquema do banco em memória dos testes `financeiro-*`, e nada a usa ainda.

- [ ] **Passo 6: Commit**

```bash
git add api/database/migrations/2026_10_21_1000*.php api/database/migrations/2026_10_21_100100_*.php scripts/teste-php/stubs-ganhos.php scripts/teste-php/stubs-financeiro.php
git commit -m "Entrega junto e multiplicador: migrations (períodos e colunas do valor congelado) e stubs dos testes"
```

---

### Tarefa 2: `ValorDaEntrega` (funções puras)

**Arquivos:**
- Criar: `api/app/Support/Entregas/ValorDaEntrega.php`
- Criar: `scripts/teste-php/entrega-junto.php`

- [ ] **Passo 1: Escreva o teste (só a parte pura e a migration)**

`scripts/teste-php/entrega-junto.php`:

```php
<?php

// Entrega junto (segunda tabela de faixas) e multiplicador por período: valor final (ValorDaEntrega), congelamento das
// duas tabelas e da atribuição (CalculoEntregas + ValoresCongelados), prévia do card, reserva por horários, o observador,
// o que o app recebe (GanhosDoMotoboy) e a migration. Os períodos (Multiplicadores, rotas) ficam no
// financeiro-multiplicadores.php.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/entrega-junto.php

require __DIR__ . '/stubs-ganhos.php';

use App\Support\Entregas\ValorDaEntrega;
use Illuminate\Support\Facades\Schema;

echo '== Valor final (ValorDaEntrega)' . PHP_EOL;

// linha congelada como o MySQL devolve: decimal em texto, booleano em 0/1
$base = [
    'de_km' => '2.00', 'ate_km' => '4.00', 'acima' => 0, 'valor_motoboy' => '8.00', 'valor_loja' => '11.37',
    'junto_de_km' => '3.00', 'junto_ate_km' => '6.00', 'junto_acima' => 0, 'junto_motoboy' => '6.00', 'junto_loja' => '9.00',
];
$chuva = ['multiplicador_uuid' => 'mult-chuva', 'multiplicador_nome' => 'Chuva', 'mult_motoboy' => '1.20', 'mult_loja' => '1.10'];

$v = ValorDaEntrega::final($base);
confere($v['motoboy'] === 8.0 && $v['loja'] === 11.37 && $v['tabela'] === 'normal' && $v['posicao'] === null && $v['multiplicador'] === null, 'sem atribuição: tabela normal × 1');
confere($v['faixa'] === ['de_km' => 2.0, 'ate_km' => 4.0, 'motoboy' => 8.0, 'loja' => 11.37], 'a faixa da tabela normal, em números');

$v = ValorDaEntrega::final(['posicao' => 1, 'mult_motoboy' => '1.50', 'mult_loja' => '1.00', 'multiplicador_nome' => 'Chuva'] + $base);
confere($v['motoboy'] === 12.0 && $v['loja'] === 11.37 && $v['tabela'] === 'normal', '1ª entrega com ×1,5 ao motoboy e ×1 à loja: 12,00 / 11,37');
confere($v['multiplicador'] === ['nome' => 'Chuva', 'motoboy' => 1.5, 'loja' => 1.0], 'o multiplicador aplicado, com os dois fatores');

$v = ValorDaEntrega::final(['posicao' => 2] + $chuva + $base);
confere($v['motoboy'] === 7.2 && $v['loja'] === 9.9 && $v['tabela'] === 'junto' && $v['posicao'] === 2, '2ª entrega com chuva: segunda tabela × fatores (6 × 1,2 = 7,20; 9 × 1,1 = 9,90)');
confere($v['faixa'] === ['de_km' => 3.0, 'ate_km' => 6.0, 'motoboy' => 6.0, 'loja' => 9.0], 'a faixa é a da segunda tabela');

$v = ValorDaEntrega::final(['posicao' => 3, 'junto_motoboy' => null, 'junto_loja' => null] + $base);
confere($v['motoboy'] === 8.0 && $v['tabela'] === 'normal' && $v['posicao'] === 3, 'segunda tabela vazia no cálculo: tabela normal (a posição fica guardada)');

$v = ValorDaEntrega::final(['posicao' => 4, 'junto_acima' => 1] + $base);
confere(($v['faixa']['acima'] ?? null) === true && $v['tabela'] === 'junto', 'acima da última faixa da segunda tabela');

confere(ValorDaEntrega::final(['posicao' => 1, 'mult_motoboy' => '1.10', 'mult_loja' => '1.10', 'multiplicador_nome' => 'Chuva'] + $base)['loja'] === 12.51, 'arredonda a 2 casas (11,37 × 1,1 = 12,507 → 12,51)');

echo '== Posição pelos horários' . PHP_EOL;
$outros = [
    ['aceite' => '2026-10-21 18:40:00', 'fim' => null],                  // ainda levando
    ['aceite' => '2026-10-21 18:30:00', 'fim' => '2026-10-21 18:55:00'], // terminou antes
    ['aceite' => '2026-10-21 19:05:00', 'fim' => null],                  // aceito depois
    ['aceite' => '2026-10-21 18:50:00', 'fim' => '2026-10-21 19:10:00'], // levando no instante
    ['aceite' => null, 'fim' => null],                                   // sem horário
];
confere(ValorDaEntrega::posicaoPorHorarios('2026-10-21 19:00:00', $outros) === 3, 'dois pedidos em mãos às 19:00: 3ª entrega');
confere(ValorDaEntrega::posicaoPorHorarios('2026-10-21 19:00:00', []) === 1, 'sem outros: 1ª');

echo '== Fator visível a cada conta' . PHP_EOL;
$m = ['nome' => 'Chuva', 'motoboy' => 1.2, 'loja' => 1.0];
confere(ValorDaEntrega::doMotoboy($m) === ['nome' => 'Chuva', 'fator' => 1.2] && ValorDaEntrega::daLoja($m) === null, 'motoboy vê ×1,2; a loja (×1) não vê selo');
confere(ValorDaEntrega::doMotoboy(null) === null && ValorDaEntrega::daLoja(['nome' => 'Chuva', 'motoboy' => 1.0, 'loja' => 1.1]) === ['nome' => 'Chuva', 'fator' => 1.1], 'sem multiplicador: nada; loja ×1,1: selo da loja');

echo '== Migration das colunas novas' . PHP_EOL;
$migration = require '/repo/api/database/migrations/2026_10_21_100100_add_junto_e_multiplicador_to_entregas_valores_pedido_table.php';
$migration->up();
$colunas = [];
foreach ((Schema::$alteradas['entregas_valores_pedido'][0] ?? null)?->colunas ?? [] as $coluna) {
    $colunas[$coluna->argumentos[0] ?? $coluna->tipo] = $coluna;
}
$esperadas = ['junto_de_km', 'junto_ate_km', 'junto_acima', 'junto_motoboy', 'junto_loja', 'motoboy_uuid', 'atribuido_em', 'posicao', 'multiplicador_uuid', 'multiplicador_nome', 'mult_motoboy', 'mult_loja'];
confere(array_keys($colunas) === $esperadas, 'as doze colunas, na ordem');
confere(!array_filter($colunas, fn ($c) => !array_key_exists('nullable', $c->modificadores)), 'todas nulas: as linhas antigas valem como antes');
confere($colunas['junto_motoboy']->argumentos === ['junto_motoboy', 10, 2] && $colunas['mult_motoboy']->argumentos === ['mult_motoboy', 4, 2] && $colunas['posicao']->tipo === 'unsignedTinyInteger', 'valores em decimal(10,2), fatores em decimal(4,2), posição tinyint');
confere(array_diff(ValorDaEntrega::COLUNAS_DA_ATRIBUICAO, $esperadas) === [], 'toda coluna da atribuição existe na migration');
$migration->down();
$drop = (Schema::$alteradas['entregas_valores_pedido'][1] ?? null)?->colunas[0] ?? null;
confere($drop && $drop->tipo === 'dropColumn' && $drop->argumentos[0] === $esperadas, 'o down apaga as doze');

resumo();
```

- [ ] **Passo 2: Rode e veja falhar**

`PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/entrega-junto.php`
Esperado: erro "Class App\Support\Entregas\ValorDaEntrega not found".

- [ ] **Passo 3: Implemente**

`api/app/Support/Entregas/ValorDaEntrega.php`:

```php
<?php

namespace App\Support\Entregas;

/**
 * Entregas RestaurantePro: valor final de uma entrega a partir da linha congelada (entregas_valores_pedido): a tabela
 * normal ou a segunda tabela ("a partir da 2ª entrega junto", quando a posição é 2 ou mais e havia segunda tabela no
 * cálculo), vezes o multiplicador do período gravado na atribuição. Funções puras, sem banco.
 * Spec: docs/superpowers/specs/2026-10-21-entrega-junto-e-multiplicador-design.md.
 *
 * A linha pode vir do banco (decimal em texto, booleano em 0/1) ou recém-montada (números).
 */
final class ValorDaEntrega
{
    /** A partir desta posição o pedido usa a segunda tabela. */
    public const JUNTO_A_PARTIR = 2;

    /** A parte da atribuição na linha congelada (gravada por ValoresCongelados::gravarAtribuicoes). */
    public const COLUNAS_DA_ATRIBUICAO = ['motoboy_uuid', 'atribuido_em', 'posicao', 'multiplicador_uuid', 'multiplicador_nome', 'mult_motoboy', 'mult_loja'];

    /**
     * @return array{faixa: array, motoboy: float, loja: float, tabela: string, posicao: ?int, multiplicador: ?array{nome: string, motoboy: float, loja: float}}
     */
    public static function final(array $linha): array
    {
        $posicao = static::temValor($linha['posicao'] ?? null) ? (int) $linha['posicao'] : null;
        $junto   = $posicao !== null && $posicao >= static::JUNTO_A_PARTIR
            && static::temValor($linha['junto_motoboy'] ?? null) && static::temValor($linha['junto_loja'] ?? null);

        $prefixo = $junto ? 'junto_' : '';
        $faixa   = [
            'de_km'   => (float) $linha[$prefixo . 'de_km'],
            'ate_km'  => (float) $linha[$prefixo . 'ate_km'],
            'motoboy' => (float) $linha[$junto ? 'junto_motoboy' : 'valor_motoboy'],
            'loja'    => (float) $linha[$junto ? 'junto_loja' : 'valor_loja'],
        ];
        if (!empty($linha[$prefixo . 'acima'])) {
            $faixa['acima'] = true;
        }

        $multiplicador = null;
        if (static::temValor($linha['mult_motoboy'] ?? null) || static::temValor($linha['mult_loja'] ?? null)) {
            $multiplicador = [
                'nome'    => (string) ($linha['multiplicador_nome'] ?? ''),
                'motoboy' => static::fator($linha['mult_motoboy'] ?? null),
                'loja'    => static::fator($linha['mult_loja'] ?? null),
            ];
        }

        return [
            'faixa'         => $faixa,
            'motoboy'       => round($faixa['motoboy'] * ($multiplicador['motoboy'] ?? 1.0), 2),
            'loja'          => round($faixa['loja'] * ($multiplicador['loja'] ?? 1.0), 2),
            'tabela'        => $junto ? 'junto' : 'normal',
            'posicao'       => $posicao,
            'multiplicador' => $multiplicador,
        ];
    }

    /**
     * Posição do pedido aceito em $instante: 1 + os outros pedidos do mesmo motoboy aceitos antes e ainda não
     * encerrados nele. $outros: [['aceite' => 'Y-m-d H:i:s'|null, 'fim' => 'Y-m-d H:i:s'|null], ...] em Brasília.
     */
    public static function posicaoPorHorarios(string $instante, array $outros): int
    {
        $levando = 0;
        foreach ($outros as $outro) {
            $aceite = $outro['aceite'] ?? null;
            $fim    = $outro['fim'] ?? null;
            if ($aceite !== null && $aceite < $instante && ($fim === null || $fim > $instante)) {
                $levando++;
            }
        }

        return 1 + $levando;
    }

    /** O que o motoboy vê do multiplicador: nome e o fator dele; nada quando o fator dele é 1. */
    public static function doMotoboy(?array $multiplicador): ?array
    {
        return static::daConta($multiplicador, 'motoboy');
    }

    /** O que a loja vê do multiplicador: nome e o fator dela; nada quando o fator dela é 1. */
    public static function daLoja(?array $multiplicador): ?array
    {
        return static::daConta($multiplicador, 'loja');
    }

    private static function daConta(?array $multiplicador, string $conta): ?array
    {
        $fator = $multiplicador[$conta] ?? null;
        if ($fator === null || abs((float) $fator - 1.0) < 0.001) {
            return null;
        }

        return ['nome' => (string) ($multiplicador['nome'] ?? ''), 'fator' => round((float) $fator, 2)];
    }

    private static function fator($valor): float
    {
        return static::temValor($valor) ? round((float) $valor, 2) : 1.0;
    }

    private static function temValor($valor): bool
    {
        return $valor !== null && $valor !== '';
    }
}
```

- [ ] **Passo 4: Rode e veja passar**

`PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/entrega-junto.php`
Esperado: `FALHAS: 0`.

- [ ] **Passo 5: Commit**

```bash
git add api/app/Support/Entregas/ValorDaEntrega.php scripts/teste-php/entrega-junto.php
git commit -m "Entrega junto: valor final pela segunda tabela e pelo multiplicador (ValorDaEntrega, funções puras)"
```

---

### Tarefa 3: `Multiplicadores` (períodos)

**Arquivos:**
- Criar: `api/app/Support/Entregas/Financeiro/Multiplicadores.php`
- Criar: `scripts/teste-php/financeiro-multiplicadores.php`

- [ ] **Passo 1: Escreva o teste das regras**

`scripts/teste-php/financeiro-multiplicadores.php`:

```php
<?php

// Financeiro: multiplicadores por período (Multiplicadores e MultiplicadoresController): regras do formulário,
// sobreposição, edição por situação, ativoEm nas bordas, a segunda tabela de faixas (PagamentoMotoboysController), a
// migration e as rotas. O valor da entrega com o multiplicador fica no entrega-junto.php.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/financeiro-multiplicadores.php

namespace {
    require __DIR__ . '/stubs-financeiro.php';
    date_default_timezone_set('America/Sao_Paulo');

    use App\Support\Entregas\Financeiro\Multiplicadores;
    use Teste\Relogio;

    function preparar(): void
    {
        reiniciarFinanceiro();
        session(['company' => 'empresa-1']);
        Relogio::$agora = '2026-10-21 17:00:00';
    }

    /** O formulário da chuva de hoje, 18:00 às 22:00 (como o campo datetime-local do console manda). */
    function chuva(array $troca = []): array
    {
        return $troca + ['nome' => 'Chuva', 'mult_motoboy' => '1,2', 'mult_loja' => '1', 'inicio' => '2026-10-21T18:00', 'fim' => '2026-10-21T22:00'];
    }

    function agora(): string
    {
        return Relogio::$agora;
    }

    echo '== Criar' . PHP_EOL;
    preparar();
    $m = Multiplicadores::criar('empresa-1', chuva(), 'user-admin', agora());
    confere(is_array($m) && $m['nome'] === 'Chuva' && $m['mult_motoboy'] === 1.2 && $m['mult_loja'] === 1.0, 'cria com os fatores em número (1,2 e 1)');
    confere(is_array($m) && $m['inicio'] === '2026-10-21T18:00:00-03:00' && $m['fim'] === '2026-10-21T22:00:00-03:00' && $m['situacao'] === 'agendado', 'horários em ISO com o fuso de Brasília; ainda não começou');
    $linha = linhasDe('entregas_multiplicadores')[0] ?? [];
    confere(($linha['company_uuid'] ?? null) === 'empresa-1' && ($linha['criado_por'] ?? null) === 'user-admin' && ($linha['inicio'] ?? null) === '2026-10-21 18:00:00' && array_key_exists('deleted_at', $linha) && $linha['deleted_at'] === null, 'grava a empresa, quem criou e o início em Brasília, sem fuso');

    foreach ([
        'sem nome'            => chuva(['nome' => '  ']),
        'nome com 41 letras'  => chuva(['nome' => str_repeat('a', 41)]),
        'fator abaixo de 1'   => chuva(['mult_motoboy' => '0,9']),
        'fator acima de 5'    => chuva(['mult_loja' => '5,01']),
        'três casas'          => chuva(['mult_motoboy' => '1,234']),
        'fator em texto'      => chuva(['mult_motoboy' => 'abc']),
        'sem fim'             => chuva(['fim' => null]),
        'data que não existe' => chuva(['inicio' => '2026-02-30T18:00']),
        'fim antes do início' => chuva(['fim' => '2026-10-21T17:30']),
        'mais de 7 dias'      => chuva(['fim' => '2026-10-28T18:01']),
    ] as $caso => $dados) {
        $r = Multiplicadores::criar('empresa-1', $dados, 'user-admin', agora());
        confere(is_string($r) && $r !== '', "{$caso}: erro (" . (is_string($r) ? $r : 'criou') . ')');
    }

    $r = Multiplicadores::criar('empresa-1', chuva(['nome' => 'Temporal', 'inicio' => '2026-10-21T21:00', 'fim' => '2026-10-21T23:00']), null, agora());
    confere($r === 'Já existe o período "Chuva" de 21/10 18:00 a 21/10 22:00 nesse horário.', 'sobreposto: erro que cita o outro período');
    $noite = Multiplicadores::criar('empresa-1', chuva(['nome' => 'Noite', 'inicio' => '2026-10-21T22:00', 'fim' => '2026-10-21T23:00']), null, agora());
    confere(is_array($noite), 'encostado no fim do outro (22:00): pode');
    confere(is_array(Multiplicadores::criar('empresa-2', chuva(), null, agora())), 'outra empresa no mesmo horário: pode');

    echo '== Começar agora' . PHP_EOL;
    preparar();
    $agora = Multiplicadores::criar('empresa-1', chuva(['inicio' => '2026-10-21T16:00', 'fim' => '2026-10-21T20:00']), null, agora());
    confere(is_array($agora) && $agora['inicio'] === '2026-10-21T17:00:00-03:00' && $agora['situacao'] === 'em_andamento', 'início no passado vira agora (17:00): já em andamento');

    echo '== Vale no instante (ativoEm)' . PHP_EOL;
    preparar();
    Multiplicadores::criar('empresa-1', chuva(), null, agora());
    Multiplicadores::criar('empresa-1', chuva(['nome' => 'Noite', 'inicio' => '2026-10-21T22:00', 'fim' => '2026-10-21T23:00']), null, agora());
    confere(Multiplicadores::ativoEm('empresa-1', '2026-10-21 17:59:59') === null, '17:59:59: nenhum');
    confere(Multiplicadores::ativoEm('empresa-1', '2026-10-21 18:00:00')?->nome === 'Chuva', '18:00: a chuva (o início vale)');
    confere(Multiplicadores::ativoEm('empresa-1', '2026-10-21 22:00:00')?->nome === 'Noite', '22:00: o seguinte (o fim da chuva não vale)');
    confere(Multiplicadores::ativoEm('empresa-2', '2026-10-21 19:00:00') === null, 'outra empresa: nenhum');
    $periodos = Multiplicadores::doPeriodo('empresa-1', '2026-10-21 21:00:00', '2026-10-21 21:30:00');
    confere(count($periodos) === 1 && $periodos[0]->nome === 'Chuva', 'doPeriodo: só os que tocam o intervalo');
    confere(Multiplicadores::noInstante($periodos, '2026-10-21 21:10:00')?->nome === 'Chuva' && Multiplicadores::noInstante($periodos, '2026-10-21 22:10:00') === null, 'noInstante escolhe da lista');

    echo '== Lista' . PHP_EOL;
    $lista = Multiplicadores::lista('empresa-1', agora());
    confere(array_column($lista, 'nome') === ['Noite', 'Chuva'], 'mais recente primeiro (pelo início)');

    resumo();
}
```

- [ ] **Passo 2: Rode e veja falhar**

`PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/financeiro-multiplicadores.php`
Esperado: "Class App\Support\Entregas\Financeiro\Multiplicadores not found".

- [ ] **Passo 3: Implemente**

`api/app/Support/Entregas/Financeiro/Multiplicadores.php`:

```php
<?php

namespace App\Support\Entregas\Financeiro;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Entregas RestaurantePro: multiplicadores por período do Financeiro (chuva, intempérie). A entrega atribuída dentro do
 * período (início ≤ atribuição < fim) vale a faixa × o fator: um para o motoboy, outro para a loja. Tabela
 * entregas_multiplicadores; horários em Brasília ('Y-m-d H:i:s', como as DATETIME do Fleet-Ops).
 *
 * Regras: nome até 40; fator de 1,00 a 5,00 com até duas casas; fim obrigatório, depois do início e de agora, no máximo
 * 7 dias depois do início; início no passado vira agora ("Começar agora"); períodos da empresa não se sobrepõem.
 * Edição: agendado muda tudo ou é apagado; em andamento só muda o fim (ou encerra); encerrado não muda (os valores já
 * estão congelados nos pedidos). Spec: docs/superpowers/specs/2026-10-21-entrega-junto-e-multiplicador-design.md.
 */
final class Multiplicadores
{
    public const TABELA = 'entregas_multiplicadores';

    public const FATOR_MINIMO = 1.0;
    public const FATOR_MAXIMO = 5.0;
    public const MAX_DIAS     = 7;
    public const MAX_NOME     = 40;

    public const AGENDADO     = 'agendado';
    public const EM_ANDAMENTO = 'em_andamento';
    public const ENCERRADO    = 'encerrado';

    /** O período que vale no instante ('Y-m-d H:i:s' de Brasília), ou null. */
    public static function ativoEm(string $empresa, string $instante): ?object
    {
        return DB::table(static::TABELA)
            ->where('company_uuid', $empresa)
            ->whereNull('deleted_at')
            ->where('inicio', '<=', $instante)
            ->where('fim', '>', $instante)
            ->first();
    }

    /** Os períodos que tocam [de, ate], numa consulta só (reserva do CalculoEntregas). */
    public static function doPeriodo(string $empresa, string $de, string $ate): array
    {
        return DB::table(static::TABELA)
            ->where('company_uuid', $empresa)
            ->whereNull('deleted_at')
            ->where('inicio', '<=', $ate)
            ->where('fim', '>', $de)
            ->get()
            ->all();
    }

    /** O período de uma lista (doPeriodo) que vale no instante, ou null. */
    public static function noInstante(array $periodos, string $instante): ?object
    {
        foreach ($periodos as $periodo) {
            if ((string) $periodo->inicio <= $instante && (string) $periodo->fim > $instante) {
                return $periodo;
            }
        }

        return null;
    }

    /** Os períodos da empresa no formato do console, o de início mais recente primeiro. */
    public static function lista(string $empresa, string $agora): array
    {
        $periodos = DB::table(static::TABELA)->where('company_uuid', $empresa)->whereNull('deleted_at')->get()->all();
        usort($periodos, fn ($a, $b) => strcmp((string) $b->inicio, (string) $a->inicio));

        return array_map(fn ($periodo) => static::formatar($periodo, $agora), $periodos);
    }

    /** {id, nome, mult_motoboy, mult_loja, inicio, fim (ISO com fuso), situacao}. */
    public static function formatar(object $periodo, string $agora): array
    {
        return [
            'id'           => (string) $periodo->uuid,
            'nome'         => (string) $periodo->nome,
            'mult_motoboy' => round((float) $periodo->mult_motoboy, 2),
            'mult_loja'    => round((float) $periodo->mult_loja, 2),
            'inicio'       => static::iso((string) $periodo->inicio),
            'fim'          => static::iso((string) $periodo->fim),
            'situacao'     => static::situacao($periodo, $agora),
        ];
    }

    public static function situacao(object $periodo, string $agora): string
    {
        if ((string) $periodo->fim <= $agora) {
            return static::ENCERRADO;
        }

        return (string) $periodo->inicio <= $agora ? static::EM_ANDAMENTO : static::AGENDADO;
    }

    /** 'Y-m-d H:i:s' de Brasília → '2026-10-21T18:00:00-03:00'. */
    public static function iso(string $instante): string
    {
        return (new \DateTimeImmutable($instante, new \DateTimeZone(date_default_timezone_get())))->format('Y-m-d\TH:i:sP');
    }

    /** Cria o período. Devolve o formato do console ou a mensagem de erro. */
    public static function criar(string $empresa, array $dados, ?string $usuario, string $agora): array|string
    {
        $periodo = static::conferir($dados, $agora);
        if (is_string($periodo)) {
            return $periodo;
        }

        return DB::transaction(function () use ($empresa, $periodo, $usuario, $agora) {
            if ($conflito = static::sobreposto($empresa, $periodo['inicio'], $periodo['fim'], null)) {
                return static::mensagemDeConflito($conflito);
            }

            $uuid = (string) Str::uuid();
            DB::table(static::TABELA)->insert($periodo + [
                'uuid'         => $uuid,
                'company_uuid' => $empresa,
                'criado_por'   => $usuario,
                'created_at'   => $agora,
                'updated_at'   => $agora,
            ]);

            return static::formatar(static::buscar($empresa, $uuid), $agora);
        });
    }

    /**
     * Agendado: muda tudo (mesmas regras do criar). Em andamento: só o fim (os outros campos são ignorados). Encerrado:
     * erro. Devolve o formato do console, a mensagem de erro, ou null quando o período não existe nesta empresa.
     */
    public static function alterar(string $empresa, string $uuid, array $dados, string $agora): array|string|null
    {
        return DB::transaction(function () use ($empresa, $uuid, $dados, $agora) {
            $periodo = static::buscar($empresa, $uuid);
            if (!$periodo) {
                return null;
            }

            $situacao = static::situacao($periodo, $agora);
            if ($situacao === static::ENCERRADO) {
                return 'Período encerrado não muda.';
            }

            if ($situacao === static::AGENDADO) {
                $novo = static::conferir($dados, $agora);
                if (is_string($novo)) {
                    return $novo;
                }
            } else {
                $fim = static::instante($dados['fim'] ?? null);
                if ($fim === null) {
                    return 'Informe o fim do período.';
                }
                if ($erro = static::erroDoFim((string) $periodo->inicio, $fim, $agora)) {
                    return $erro;
                }
                $novo = ['fim' => $fim];
            }

            if ($conflito = static::sobreposto($empresa, $novo['inicio'] ?? (string) $periodo->inicio, $novo['fim'], $uuid)) {
                return static::mensagemDeConflito($conflito);
            }

            DB::table(static::TABELA)->where('company_uuid', $empresa)->where('uuid', $uuid)->update($novo + ['updated_at' => $agora]);

            return static::formatar(static::buscar($empresa, $uuid), $agora);
        });
    }

    /** "Encerrar agora": só o período em andamento (fim = agora). */
    public static function encerrar(string $empresa, string $uuid, ?string $usuario, string $agora): array|string|null
    {
        $periodo = static::buscar($empresa, $uuid);
        if (!$periodo) {
            return null;
        }

        $situacao = static::situacao($periodo, $agora);
        if ($situacao === static::AGENDADO) {
            return 'O período ainda não começou: apague em vez de encerrar.';
        }
        if ($situacao === static::ENCERRADO) {
            return 'O período já está encerrado.';
        }

        DB::table(static::TABELA)->where('company_uuid', $empresa)->where('uuid', $uuid)->update(['fim' => $agora, 'encerrado_por' => $usuario, 'updated_at' => $agora]);

        return static::formatar(static::buscar($empresa, $uuid), $agora);
    }

    /** Apaga (marca deleted_at) só o período agendado. true, a mensagem de erro, ou null quando não existe. */
    public static function apagar(string $empresa, string $uuid, string $agora): bool|string|null
    {
        $periodo = static::buscar($empresa, $uuid);
        if (!$periodo) {
            return null;
        }
        if (static::situacao($periodo, $agora) !== static::AGENDADO) {
            return 'Só dá para apagar período que ainda não começou.';
        }

        DB::table(static::TABELA)->where('company_uuid', $empresa)->where('uuid', $uuid)->update(['deleted_at' => $agora, 'updated_at' => $agora]);

        return true;
    }

    /** Confere e normaliza {nome, mult_motoboy, mult_loja, inicio, fim}. Erro: a mensagem. */
    public static function conferir(array $dados, string $agora): array|string
    {
        $nome = trim((string) ($dados['nome'] ?? ''));
        if ($nome === '') {
            return 'Dê um nome ao período (ex.: Chuva).';
        }
        if (mb_strlen($nome) > static::MAX_NOME) {
            return 'O nome pode ter até 40 caracteres.';
        }

        $motoboy = static::fator($dados['mult_motoboy'] ?? null);
        $loja    = static::fator($dados['mult_loja'] ?? null);
        if ($motoboy === null || $loja === null) {
            return 'O multiplicador vai de 1,00 a 5,00, com até duas casas (ex.: 1,2).';
        }

        $inicio = static::instante($dados['inicio'] ?? null);
        $fim    = static::instante($dados['fim'] ?? null);
        if ($inicio === null || $fim === null) {
            return 'Informe o início e o fim do período.';
        }
        // "Começar agora" (e relógio do navegador um pouco atrasado): o período começa agora, não no passado
        if ($inicio < $agora) {
            $inicio = $agora;
        }
        if ($erro = static::erroDoFim($inicio, $fim, $agora)) {
            return $erro;
        }

        return ['nome' => $nome, 'mult_motoboy' => $motoboy, 'mult_loja' => $loja, 'inicio' => $inicio, 'fim' => $fim];
    }

    /** Fator de 1,00 a 5,00 com até duas casas ("1,2", "1.2", 1.2), ou null. */
    public static function fator($valor): ?float
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
        $numero = round($numero, 2);

        return $numero >= static::FATOR_MINIMO && $numero <= static::FATOR_MAXIMO ? $numero : null;
    }

    /** 'Y-m-d H:i', 'Y-m-d\TH:i' (o datetime-local do console), com ou sem segundos → 'Y-m-d H:i:s'; inválido: null. */
    public static function instante($valor): ?string
    {
        if (!is_string($valor) || !preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(:\d{2})?$/', trim($valor), $partes)) {
            return null;
        }

        $texto = $partes[1] . ' ' . $partes[2] . (($partes[3] ?? '') !== '' ? $partes[3] : ':00');
        $data  = \DateTime::createFromFormat('!Y-m-d H:i:s', $texto);

        return $data && $data->format('Y-m-d H:i:s') === $texto ? $texto : null;
    }

    protected static function erroDoFim(string $inicio, string $fim, string $agora): ?string
    {
        if ($fim <= $inicio || $fim <= $agora) {
            return 'O fim precisa ser depois do início e de agora.';
        }

        $limite = (new \DateTimeImmutable($inicio))->modify('+' . static::MAX_DIAS . ' days')->format('Y-m-d H:i:s');

        return $fim > $limite ? 'O período pode durar até 7 dias.' : null;
    }

    /** O período da empresa que cruza [inicio, fim), com trava das linhas (dentro da transação), ou null. */
    protected static function sobreposto(string $empresa, string $inicio, string $fim, ?string $ignorar): ?object
    {
        $periodos = DB::table(static::TABELA)->where('company_uuid', $empresa)->whereNull('deleted_at')->lockForUpdate()->get()->all();
        foreach ($periodos as $periodo) {
            if ($ignorar !== null && (string) $periodo->uuid === $ignorar) {
                continue;
            }
            if ((string) $periodo->inicio < $fim && (string) $periodo->fim > $inicio) {
                return $periodo;
            }
        }

        return null;
    }

    protected static function mensagemDeConflito(object $periodo): string
    {
        $curto = fn (string $instante) => (new \DateTimeImmutable($instante))->format('d/m H:i');

        return sprintf('Já existe o período "%s" de %s a %s nesse horário.', $periodo->nome, $curto((string) $periodo->inicio), $curto((string) $periodo->fim));
    }

    protected static function buscar(string $empresa, string $uuid): ?object
    {
        return DB::table(static::TABELA)->where('company_uuid', $empresa)->where('uuid', $uuid)->whereNull('deleted_at')->first();
    }
}
```

- [ ] **Passo 4: Rode e veja passar**

`PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/financeiro-multiplicadores.php`
Esperado: `FALHAS: 0`. Se o banco em memória acusar "coluna desconhecida", confira se o nome da migration casa com o glob `*_entregas_financeiro_*.php` do `stubs-ifood.php`.

- [ ] **Passo 5: Commit**

```bash
git add api/app/Support/Entregas/Financeiro/Multiplicadores.php scripts/teste-php/financeiro-multiplicadores.php
git commit -m "Financeiro: multiplicadores por período (regras, sobreposição, edição por situação)"
```

---

### Tarefa 4: `MultiplicadoresController`, faixas da segunda tabela e rotas

**Arquivos:**
- Criar: `api/app/Http/Controllers/Entregas/MultiplicadoresController.php`
- Modificar: `api/app/Http/Controllers/Entregas/PagamentoMotoboysController.php`
- Modificar: `api/app/Support/Entregas/CalculoEntregas.php` (só a constante e `faixasJuntas`)
- Modificar: `api/app/Providers/RouteServiceProvider.php`
- Modificar: `scripts/teste-php/financeiro-multiplicadores.php`

- [ ] **Passo 1: Acrescente os casos do controller ao teste**

Em `scripts/teste-php/financeiro-multiplicadores.php`, acrescente aos `use` do topo:

```php
    use App\Http\Controllers\Entregas\MultiplicadoresController;
    use App\Http\Controllers\Entregas\PagamentoMotoboysController;
    use App\Support\Entregas\CalculoEntregas;
    use Fleetbase\Support\Auth;
    use Illuminate\Http\Request;
```

Acrescente depois da função `agora()`:

```php
    function usuario(bool $admin = true): object
    {
        return new class($admin) {
            public string $uuid = 'user-admin';
            public function __construct(public bool $admin) {}
            public function isNotAdmin(): bool { return !$this->admin; }
        };
    }

    function api(): MultiplicadoresController
    {
        return new MultiplicadoresController();
    }

    function req(array $dados = []): Request
    {
        return new Request($dados);
    }

    function erroDe($resposta): string
    {
        return (string) ($resposta->dados['errors'][0] ?? '');
    }
```

E troque o `resumo();` do fim por:

```php
    echo '== Rotas do console (MultiplicadoresController)' . PHP_EOL;
    preparar();
    Auth::$usuario = usuario(false);
    foreach ([
        'lista'    => fn () => api()->lista(req()),
        'criar'    => fn () => api()->criar(req(chuva())),
        'alterar'  => fn () => api()->alterar(req(chuva()), 'x'),
        'encerrar' => fn () => api()->encerrar(req(), 'x'),
        'apagar'   => fn () => api()->apagar(req(), 'x'),
    ] as $metodo => $chamar) {
        $r = $chamar();
        confere($r->status === 403 && erroDe($r) !== '', "{$metodo}: não admin → 403");
    }
    confere(linhasDe('entregas_multiplicadores') === [], 'nada gravado');

    Auth::$usuario = usuario();
    $r = api()->criar(req(chuva()));
    $chuva = $r->dados['multiplicador'] ?? null;
    confere($r->status === 200 && ($chuva['nome'] ?? null) === 'Chuva' && (linhasDe('entregas_multiplicadores')[0]['criado_por'] ?? null) === 'user-admin', 'POST cria com quem criou: { multiplicador }');
    confere(api()->criar(req(chuva(['mult_motoboy' => '9'])))->status === 422, 'POST inválido: 422');
    $noite = api()->criar(req(chuva(['nome' => 'Noite', 'inicio' => '2026-10-21T22:00', 'fim' => '2026-10-21T23:00'])))->dados['multiplicador']['id'];
    $r = api()->lista(req());
    confere($r->status === 200 && array_column($r->dados['multiplicadores'], 'nome') === ['Noite', 'Chuva'] && $r->dados['agora'] === '2026-10-21T17:00:00-03:00', 'GET: a lista e a hora do servidor');

    $id = $chuva['id'];
    $r  = api()->alterar(req(chuva(['mult_motoboy' => '1,5'])), $id);
    confere($r->status === 200 && $r->dados['multiplicador']['mult_motoboy'] === 1.5, 'agendado: PUT muda o fator');

    Relogio::$agora = '2026-10-21 19:00:00';
    $r = api()->alterar(req(['fim' => '2026-10-21T21:30', 'mult_motoboy' => '3']), $id);
    confere($r->status === 200 && $r->dados['multiplicador']['fim'] === '2026-10-21T21:30:00-03:00' && $r->dados['multiplicador']['mult_motoboy'] === 1.5, 'em andamento: só o fim muda (o fator enviado é ignorado)');
    $r = api()->alterar(req(['fim' => '2026-10-21T22:30']), $id);
    confere($r->status === 422 && str_contains(erroDe($r), 'Noite'), 'prorrogar por cima do seguinte: 422 citando o outro');
    confere(api()->apagar(req(), $id)->status === 422, 'em andamento não se apaga');
    $r = api()->encerrar(req(), $id);
    confere($r->status === 200 && $r->dados['multiplicador']['situacao'] === 'encerrado' && $r->dados['multiplicador']['fim'] === '2026-10-21T19:00:00-03:00', 'encerrar agora: fim = agora');
    $linhaDaChuva = array_values(array_filter(linhasDe('entregas_multiplicadores'), fn ($l) => $l['uuid'] === $id))[0] ?? [];
    confere(($linhaDaChuva['encerrado_por'] ?? null) === 'user-admin', 'grava quem encerrou');
    confere(api()->alterar(req(chuva()), $id)->status === 422 && api()->encerrar(req(), $id)->status === 422, 'encerrado não muda nem encerra de novo');

    confere(api()->encerrar(req(), $noite)->status === 422, 'agendado: não se encerra (apague)');
    $r = api()->apagar(req(), $noite);
    confere($r->status === 200 && $r->dados === ['apagado' => true], 'agendado: DELETE apaga');
    confere(Multiplicadores::ativoEm('empresa-1', '2026-10-21 22:30:00') === null && count(api()->lista(req())->dados['multiplicadores']) === 1, 'o apagado não vale nem aparece');

    $deOutra = Multiplicadores::criar('empresa-2', chuva(['inicio' => '2026-10-21T20:00']), null, agora());
    foreach (['alterar' => fn () => api()->alterar(req(chuva()), $deOutra['id']), 'encerrar' => fn () => api()->encerrar(req(), $deOutra['id']), 'apagar' => fn () => api()->apagar(req(), $deOutra['id']), 'inexistente' => fn () => api()->apagar(req(), 'nao-existe')] as $caso => $chamar) {
        confere($chamar()->status === 404, "{$caso}: período de outra empresa ou que não existe → 404");
    }

    echo '== Segunda tabela de faixas (PagamentoMotoboysController)' . PHP_EOL;
    preparar();
    Auth::$usuario = usuario();
    $faixas = new PagamentoMotoboysController();
    $r = $faixas->salvarFaixasJuntas(req(['faixas' => [['ate_km' => 6, 'motoboy' => '6', 'loja' => 9], ['ate_km' => 3, 'motoboy' => 4, 'loja' => 6]]]), new CalculoEntregas());
    confere($r->status === 200 && $r->dados['faixas'] === [['ate_km' => 3.0, 'motoboy' => 4.0, 'loja' => 6.0], ['ate_km' => 6.0, 'motoboy' => 6.0, 'loja' => 9.0]], 'grava em ordem de km, em números');
    confere(\Fleetbase\Models\Setting::$valores['company.empresa-1.entregas.faixas_juntas'] === $r->dados['faixas'] && !isset(\Fleetbase\Models\Setting::$valores['company.empresa-1.entregas.faixas']), 'na chave da segunda tabela, sem mexer na normal');
    confere($faixas->salvarFaixasJuntas(req(['faixas' => [['ate_km' => 3, 'motoboy' => 4, 'loja' => 6], ['ate_km' => 3, 'motoboy' => 5, 'loja' => 7]]]), new CalculoEntregas())->status === 422, 'dois "até km" iguais: 422');
    $r = $faixas->salvarFaixasJuntas(req(['faixas' => []]), new CalculoEntregas());
    confere($r->status === 200 && $r->dados['faixas'] === [] && \Fleetbase\Models\Setting::$valores['company.empresa-1.entregas.faixas_juntas'] === [], 'lista vazia desliga');
    Auth::$usuario = usuario(false);
    confere($faixas->salvarFaixasJuntas(req(['faixas' => []]), new CalculoEntregas())->status === 403, 'não admin: 403');

    echo '== Rotas' . PHP_EOL;
    $provider = file_get_contents('/repo/api/app/Providers/RouteServiceProvider.php');
    confere(str_contains($provider, 'use App\\Http\\Controllers\\Entregas\\MultiplicadoresController;'), 'RouteServiceProvider importa o controller');
    $grupo = substr($provider, (int) strpos($provider, "Route::prefix('int/v1/entregas')"), max(0, (int) strpos($provider, "Route::prefix('v1/entregas/motoboy')") - (int) strpos($provider, "Route::prefix('int/v1/entregas')")));
    foreach ([
        ['get', 'financeiro/multiplicadores', MultiplicadoresController::class, 'MultiplicadoresController', 'lista'],
        ['post', 'financeiro/multiplicadores', MultiplicadoresController::class, 'MultiplicadoresController', 'criar'],
        ['put', 'financeiro/multiplicadores/{id}', MultiplicadoresController::class, 'MultiplicadoresController', 'alterar'],
        ['post', 'financeiro/multiplicadores/{id}/encerrar', MultiplicadoresController::class, 'MultiplicadoresController', 'encerrar'],
        ['delete', 'financeiro/multiplicadores/{id}', MultiplicadoresController::class, 'MultiplicadoresController', 'apagar'],
        ['put', 'pagamento-motoboys/faixas-juntas', PagamentoMotoboysController::class, 'PagamentoMotoboysController', 'salvarFaixasJuntas'],
    ] as [$verbo, $caminho, $classe, $curta, $metodo]) {
        confere(str_contains($grupo, "Route::{$verbo}('{$caminho}', [{$curta}::class, '{$metodo}'])") && method_exists($classe, $metodo), strtoupper($verbo) . " {$caminho} → {$metodo}");
    }

    echo '== Migration da tabela' . PHP_EOL;
    \Illuminate\Support\Facades\Schema::$criadas = [];
    $migration = require '/repo/api/database/migrations/2026_10_21_100000_create_entregas_financeiro_multiplicadores_table.php';
    $migration->up();
    $nomes = array_map(fn ($c) => $c->argumentos[0] ?? $c->tipo, \Illuminate\Support\Facades\Schema::$criadas['entregas_multiplicadores']->colunas ?? []);
    confere(array_diff(['uuid', 'company_uuid', 'nome', 'mult_motoboy', 'mult_loja', 'inicio', 'fim', 'criado_por', 'encerrado_por', 'deleted_at'], $nomes) === [], 'as colunas que a classe usa');
    $migration->down();
    confere(!isset(\Illuminate\Support\Facades\Schema::$criadas['entregas_multiplicadores']), 'o down apaga a tabela');

    resumo();
}
```

- [ ] **Passo 2: Rode e veja falhar**

`PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/financeiro-multiplicadores.php`
Esperado: "Class App\Http\Controllers\Entregas\MultiplicadoresController not found".

- [ ] **Passo 3: O controller**

`api/app/Http/Controllers/Entregas/MultiplicadoresController.php`:

```php
<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\Financeiro\Multiplicadores;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: multiplicadores por período do Financeiro (console → Fleet-Ops → Financeiro →
 * Multiplicadores), só para administradores. Regras em App\Support\Entregas\Financeiro\Multiplicadores. Rotas em
 * int/v1/entregas/financeiro/multiplicadores (RouteServiceProvider); o id é o uuid do período.
 */
class MultiplicadoresController extends Controller
{
    /** GET financeiro/multiplicadores → {multiplicadores: [...], agora: ISO} (a hora do servidor, para a situação). */
    public function lista(Request $request)
    {
        if ($negado = $this->negarSeNaoAdmin($request)) {
            return $negado;
        }

        return response()->json([
            'multiplicadores' => Multiplicadores::lista($this->empresa(), $this->agora()),
            'agora'           => Multiplicadores::iso($this->agora()),
        ]);
    }

    /** POST financeiro/multiplicadores {nome, mult_motoboy, mult_loja, inicio, fim} → {multiplicador}. */
    public function criar(Request $request)
    {
        if ($negado = $this->negarSeNaoAdmin($request)) {
            return $negado;
        }

        return $this->resposta(Multiplicadores::criar($this->empresa(), $request->all(), $this->usuario($request), $this->agora()));
    }

    /** PUT financeiro/multiplicadores/{id}: agendado muda tudo; em andamento, só o fim. */
    public function alterar(Request $request, string $id)
    {
        if ($negado = $this->negarSeNaoAdmin($request)) {
            return $negado;
        }

        return $this->resposta(Multiplicadores::alterar($this->empresa(), $id, $request->all(), $this->agora()));
    }

    /** POST financeiro/multiplicadores/{id}/encerrar: fim = agora (só em andamento). */
    public function encerrar(Request $request, string $id)
    {
        if ($negado = $this->negarSeNaoAdmin($request)) {
            return $negado;
        }

        return $this->resposta(Multiplicadores::encerrar($this->empresa(), $id, $this->usuario($request), $this->agora()));
    }

    /** DELETE financeiro/multiplicadores/{id}: só agendado → {apagado: true}. */
    public function apagar(Request $request, string $id)
    {
        if ($negado = $this->negarSeNaoAdmin($request)) {
            return $negado;
        }

        $resultado = Multiplicadores::apagar($this->empresa(), $id, $this->agora());
        if ($resultado === null) {
            return $this->erro('Período não encontrado.', 404);
        }

        return is_string($resultado) ? $this->erro($resultado) : response()->json(['apagado' => true]);
    }

    protected function resposta(array|string|null $resultado)
    {
        if ($resultado === null) {
            return $this->erro('Período não encontrado.', 404);
        }

        return is_string($resultado) ? $this->erro($resultado) : response()->json(['multiplicador' => $resultado]);
    }

    /** Agora em Brasília, sem fuso (o formato da tabela). */
    protected function agora(): string
    {
        return now()->format('Y-m-d H:i:s');
    }

    protected function empresa(): string
    {
        return (string) session('company');
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

    protected function negarSeNaoAdmin(Request $request)
    {
        $usuario = Auth::getUserFromSession($request);

        if (!$usuario || $usuario->isNotAdmin()) {
            return response()->json(['errors' => ['Somente administradores podem mexer no financeiro.']], 403);
        }

        return null;
    }
}
```

- [ ] **Passo 4: Constante e leitura da segunda tabela no `CalculoEntregas`**

Em `api/app/Support/Entregas/CalculoEntregas.php`, depois de `public const CHAVE_FAIXAS = 'entregas.faixas';`:

```php
    /** Segunda tabela ("a partir da 2ª entrega junto"), no mesmo formato. Vazia = recurso desligado. */
    public const CHAVE_FAIXAS_JUNTAS = 'entregas.faixas_juntas';
```

E depois do método `faixas()`:

```php
    /** Faixas da segunda tabela, em ordem crescente de km (vazia = todas as entregas pela tabela normal). */
    public function faixasJuntas(): array
    {
        $faixas = Setting::lookupCompany(static::CHAVE_FAIXAS_JUNTAS, []);

        return $this->normalizarFaixas(is_array($faixas) ? $faixas : []);
    }
```

- [ ] **Passo 5: `PagamentoMotoboysController`**

No `relatorio()`, depois de `'faixas'    => $calculo->faixas(),` acrescente:

```php
            'faixas_juntas' => $calculo->faixasJuntas(),
```

Troque o método `salvarFaixas` inteiro por:

```php
    public function salvarFaixas(Request $request, CalculoEntregas $calculo)
    {
        return $this->salvarTabela($request, $calculo, CalculoEntregas::CHAVE_FAIXAS);
    }

    /** PUT pagamento-motoboys/faixas-juntas: a segunda tabela ("a partir da 2ª entrega junto"); lista vazia desliga. */
    public function salvarFaixasJuntas(Request $request, CalculoEntregas $calculo)
    {
        return $this->salvarTabela($request, $calculo, CalculoEntregas::CHAVE_FAIXAS_JUNTAS);
    }

    protected function salvarTabela(Request $request, CalculoEntregas $calculo, string $chave)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }

        $request->validate([
            'faixas'           => ['present', 'array', 'max:' . CalculoEntregas::MAX_FAIXAS],
            'faixas.*.ate_km'  => ['required', 'numeric', 'gt:0', 'max:1000'],
            'faixas.*.motoboy' => ['required', 'numeric', 'min:0', 'max:100000'],
            'faixas.*.loja'    => ['required', 'numeric', 'min:0', 'max:100000'],
        ]);

        $faixas  = $calculo->normalizarFaixas($request->input('faixas', []));
        $limites = array_column($faixas, 'ate_km');
        if (count($limites) !== count(array_unique($limites))) {
            return response()->json(['errors' => ['Há duas faixas com o mesmo "até km".']], 422);
        }

        Setting::configureCompany($chave, $faixas);

        return response()->json(['faixas' => $faixas]);
    }
```

- [ ] **Passo 6: Rotas**

Em `api/app/Providers/RouteServiceProvider.php`, acrescente o `use` na ordem alfabética (depois de `use App\Http\Controllers\Entregas\MotoboyController;`, ou onde a ordem mandar):

```php
use App\Http\Controllers\Entregas\MultiplicadoresController;
```

Depois de `Route::put('pagamento-motoboys/faixas', [PagamentoMotoboysController::class, 'salvarFaixas']);`:

```php
                        Route::put('pagamento-motoboys/faixas-juntas', [PagamentoMotoboysController::class, 'salvarFaixasJuntas']);
```

Depois de `Route::put('financeiro/configuracoes', [FinanceiroController::class, 'salvarConfiguracoes']);`:

```php

                        // multiplicadores por período (chuva): MultiplicadoresController, só admin
                        Route::get('financeiro/multiplicadores', [MultiplicadoresController::class, 'lista']);
                        Route::post('financeiro/multiplicadores', [MultiplicadoresController::class, 'criar']);
                        Route::put('financeiro/multiplicadores/{id}', [MultiplicadoresController::class, 'alterar']);
                        Route::post('financeiro/multiplicadores/{id}/encerrar', [MultiplicadoresController::class, 'encerrar']);
                        Route::delete('financeiro/multiplicadores/{id}', [MultiplicadoresController::class, 'apagar']);
```

- [ ] **Passo 7: Rode os testes**

```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/financeiro-multiplicadores.php
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/imports-dos-providers.php
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Http/Controllers/Entregas/MultiplicadoresController.php api/app/Http/Controllers/Entregas/PagamentoMotoboysController.php api/app/Providers/RouteServiceProvider.php api/app/Support/Entregas/CalculoEntregas.php
```
Esperado: `FALHAS: 0` nos dois e sintaxe sem erro.

- [ ] **Passo 8: Commit**

```bash
git add api/app/Http/Controllers/Entregas/MultiplicadoresController.php api/app/Http/Controllers/Entregas/PagamentoMotoboysController.php api/app/Support/Entregas/CalculoEntregas.php api/app/Providers/RouteServiceProvider.php scripts/teste-php/financeiro-multiplicadores.php
git commit -m "Financeiro: rotas dos multiplicadores e da segunda tabela de faixas (faixas-juntas)"
```

---

### Tarefa 5: `AtribuicaoDoValor` e `ValoresCongelados::gravarAtribuicoes`

**Arquivos:**
- Criar: `api/app/Support/Entregas/AtribuicaoDoValor.php`
- Modificar: `api/app/Support/Entregas/ValoresCongelados.php`

Estas duas classes não têm teste isolado: a `AtribuicaoDoValor` só faz consultas, e o teste do cálculo (Tarefa 6) a troca por uma falsa. O `gravarAtribuicoes` é exercitado pela Tarefa 6.

- [ ] **Passo 1: `AtribuicaoDoValor`**

```php
<?php

namespace App\Support\Entregas;

use App\Support\Entregas\Financeiro\Multiplicadores;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: as consultas da atribuição do valor da entrega (CalculoEntregas): quantos pedidos o motoboy
 * já leva, o multiplicador de um instante e a reconstituição pelos horários para o pedido que escapou do observador.
 * Classe própria para o teste do cálculo trocar por uma falsa. Horários em Brasília, texto 'Y-m-d H:i:s'.
 */
class AtribuicaoDoValor
{
    /**
     * Pedidos em aberto do motoboy, sem $exceto: não encerrados e atualizados nas últimas
     * SituacaoDoMotoboy::HORAS_PEDIDO_EM_ANDAMENTO horas (pedido esquecido aberto não conta), como o mapa e a fila.
     */
    public function emAberto(string $empresa, string $motoboyUuid, string $exceto): int
    {
        return DB::table('orders')
            ->where('company_uuid', $empresa)
            ->where('driver_assigned_uuid', $motoboyUuid)
            ->where('uuid', '!=', $exceto)
            ->whereNull('deleted_at')
            ->whereNotIn('status', StatusDoPedido::ENCERRADOS)
            ->where('updated_at', '>=', now()->subHours(SituacaoDoMotoboy::HORAS_PEDIDO_EM_ANDAMENTO)->format('Y-m-d H:i:s'))
            ->count();
    }

    /** O período ativo no instante. Sem a tabela (API nova antes da migration) ou com o banco fora: nenhum, e o log. */
    public function multiplicadorEm(string $empresa, string $instante): ?object
    {
        try {
            return Multiplicadores::ativoEm($empresa, $instante);
        } catch (\Throwable $e) {
            Log::warning('[entregas] valor: não deu para ler o multiplicador; vale ×1', ['erro' => get_class($e)]);

            return null;
        }
    }

    /** Os períodos que tocam [de, ate] (reserva por horários). Falha: nenhum, e o log. */
    public function multiplicadoresDoPeriodo(string $empresa, string $de, string $ate): array
    {
        try {
            return Multiplicadores::doPeriodo($empresa, $de, $ate);
        } catch (\Throwable $e) {
            Log::warning('[entregas] valor: não deu para ler os multiplicadores do período; vale ×1', ['erro' => get_class($e)]);

            return [];
        }
    }

    /**
     * Posição de cada pedido pelos horários (reserva), numa consulta só: os pedidos dos motoboys aceitos desde 12 h antes
     * do primeiro instante. Aceite = started_at (sem ele, dispatched_at); fim = updated_at do pedido encerrado (aproximado:
     * uma edição depois da conclusão o adia).
     *
     * @param array<int, array{uuid: string, motoboy: string, instante: string}> $pedidos
     *
     * @return array<string, int> uuid do pedido => posição
     */
    public function posicoesPorHorarios(string $empresa, array $pedidos): array
    {
        if (!$pedidos) {
            return [];
        }

        $instantes = array_column($pedidos, 'instante');
        $de        = (new \DateTimeImmutable(min($instantes)))->modify('-' . SituacaoDoMotoboy::HORAS_PEDIDO_EM_ANDAMENTO . ' hours')->format('Y-m-d H:i:s');
        $ate       = max($instantes);

        $linhas = DB::table('orders')
            ->where('company_uuid', $empresa)
            ->whereIn('driver_assigned_uuid', array_values(array_unique(array_column($pedidos, 'motoboy'))))
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereBetween('started_at', [$de, $ate])->orWhereBetween('dispatched_at', [$de, $ate]))
            ->get(['uuid', 'driver_assigned_uuid', 'started_at', 'dispatched_at', 'status', 'updated_at']);

        $porMotoboy = [];
        foreach ($linhas as $linha) {
            $aceite = $linha->started_at ?? $linha->dispatched_at;
            $porMotoboy[(string) $linha->driver_assigned_uuid][] = [
                'uuid'   => (string) $linha->uuid,
                'aceite' => $aceite !== null ? substr((string) $aceite, 0, 19) : null,
                'fim'    => in_array(strtolower((string) $linha->status), StatusDoPedido::ENCERRADOS, true) && $linha->updated_at !== null ? substr((string) $linha->updated_at, 0, 19) : null,
            ];
        }

        $posicoes = [];
        foreach ($pedidos as $pedido) {
            $outros = array_values(array_filter($porMotoboy[$pedido['motoboy']] ?? [], fn ($outro) => $outro['uuid'] !== $pedido['uuid']));
            $posicoes[$pedido['uuid']] = ValorDaEntrega::posicaoPorHorarios($pedido['instante'], $outros);
        }

        return $posicoes;
    }
}
```

- [ ] **Passo 2: `ValoresCongelados`**

Troque a constante `COLUNAS_ATUALIZADAS` por:

```php
    /** Colunas regravadas quando o pedido já tem valor e o km mudou (a parte do km, com a segunda tabela). */
    public const COLUNAS_ATUALIZADAS = ['chave', 'metros', 'fonte', 'de_km', 'ate_km', 'acima', 'valor_motoboy', 'valor_loja', 'junto_de_km', 'junto_ate_km', 'junto_acima', 'junto_motoboy', 'junto_loja', 'updated_at'];
```

E acrescente, depois do `gravar()`:

```php
    /**
     * Grava a parte da atribuição (ValorDaEntrega::COLUNAS_DA_ATRIBUICAO) de linhas completas (a parte do km junto, para o
     * caso de a linha ainda não existir). Numa linha que já existe, só a atribuição muda: o km fica como está.
     */
    public function gravarAtribuicoes(array $linhas): void
    {
        if (!$linhas) {
            return;
        }

        $agora  = now();
        $linhas = array_map(function (array $linha) use ($agora) {
            // linhas lidas do banco trazem o id e as datas: o upsert não regrava o id, e o updated_at é o de agora
            unset($linha['id'], $linha['created_at']);
            $linha['updated_at'] = $agora;

            return ['created_at' => $agora] + $linha;
        }, array_values($linhas));

        foreach (array_chunk($linhas, 500) as $lote) {
            DB::table(static::TABELA)->upsert($lote, ['order_uuid'], array_merge(ValorDaEntrega::COLUNAS_DA_ATRIBUICAO, ['updated_at']));
        }
    }
```

- [ ] **Passo 3: Sintaxe e os testes de hoje**

```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/AtribuicaoDoValor.php api/app/Support/Entregas/ValoresCongelados.php
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ganhos-motoboy.php
```
Esperado: sintaxe ok. No `ganhos-motoboy.php`, a única falha aceitável neste ponto é a de `upsert: a coluna junto_de_km não veio na linha`: o recongelamento já pede as colunas novas, e o `CalculoEntregas` só as monta na Tarefa 6. Se aparecer, siga para a Tarefa 6 antes de commitar.

- [ ] **Passo 4: Commit (junto com a Tarefa 6, se o ganhos-motoboy falhou no passo 3)**

```bash
git add api/app/Support/Entregas/AtribuicaoDoValor.php api/app/Support/Entregas/ValoresCongelados.php
git commit -m "Entrega junto: consultas da atribuição e gravação da atribuição no valor congelado"
```

---

### Tarefa 6: `CalculoEntregas`: segunda tabela, valor final, prévia, atribuição e reserva

**Arquivos:**
- Modificar: `api/app/Support/Entregas/CalculoEntregas.php`
- Modificar: `scripts/teste-php/entrega-junto.php`
- Modificar: `scripts/teste-php/ganhos-motoboy.php`

- [ ] **Passo 1: Acrescente os casos do cálculo ao `entrega-junto.php`**

Acrescente aos `use` do topo:

```php
use App\Support\Entregas\AtribuicaoDoValor;
use App\Support\Entregas\CalculoEntregas;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Teste\Banco;
use Teste\Ponto;
```

Logo depois dos `use`, antes do `echo '== Valor final…'`, acrescente:

```php
const NORMAL = [
    ['ate_km' => 1, 'motoboy' => 5, 'loja' => 7],
    ['ate_km' => 2, 'motoboy' => 6, 'loja' => 8],
    ['ate_km' => 4, 'motoboy' => 8, 'loja' => 11.37],
    ['ate_km' => 6, 'motoboy' => 10, 'loja' => 14],
];
// segunda tabela ("a partir da 2ª entrega junto"), com limites próprios: 3,21 km cai em 3 a 6 (6,00 / 9,00)
const JUNTAS = [
    ['ate_km' => 3, 'motoboy' => 4, 'loja' => 6],
    ['ate_km' => 6, 'motoboy' => 6, 'loja' => 9],
];

/** Pedidos em aberto, períodos e posições controlados pelo teste (sem banco de pedidos nem tabela de períodos). */
class AtribuicaoFalsa extends AtribuicaoDoValor
{
    public int $emAberto      = 0;
    public ?object $periodo   = null;
    public array $periodos    = [];
    public array $posicoes    = [];
    public array $chamadas    = [];

    public function emAberto(string $empresa, string $motoboyUuid, string $exceto): int
    {
        $this->chamadas[] = ['emAberto', $motoboyUuid, $exceto];

        return $this->emAberto;
    }

    public function multiplicadorEm(string $empresa, string $instante): ?object
    {
        $this->chamadas[] = ['multiplicadorEm', $instante];

        return $this->periodo;
    }

    public function multiplicadoresDoPeriodo(string $empresa, string $de, string $ate): array
    {
        $this->chamadas[] = ['multiplicadoresDoPeriodo', $de, $ate];

        return $this->periodos;
    }

    public function posicoesPorHorarios(string $empresa, array $pedidos): array
    {
        $this->chamadas[] = ['posicoesPorHorarios', $pedidos];

        return $this->posicoes;
    }
}

/** O período como o banco devolve (decimal em texto). */
function periodoDeChuva(string $inicio = '2026-10-03 18:00:00', string $fim = '2026-10-03 22:00:00'): object
{
    return (object) ['uuid' => 'mult-chuva', 'nome' => 'Chuva', 'mult_motoboy' => '1.20', 'mult_loja' => '1.10', 'inicio' => $inicio, 'fim' => $fim];
}

function reiniciar(array $juntas = JUNTAS): void
{
    Setting::$valores   = ['entregas.faixas' => NORMAL, 'entregas.faixas_juntas' => $juntas];
    Banco::$tabelas     = [];
    Banco::$upserts     = 0;
    Banco::$consultas   = [];
    Banco::$falharAoLer = [];
    Log::$registros     = [];
    Order::$todos       = [];
}

function lugar(string $id, ?float $lat, ?float $lng, string $nome): Place
{
    return new Place(['uuid' => 'uuid-' . $id, 'public_id' => $id, 'name' => $nome, 'street1' => 'Rua ' . $nome . ', 10', 'neighborhood' => 'Centro', 'location' => $lat === null ? null : new Ponto($lat, $lng)]);
}

// pedido do motoboy Motoca, da Loja Centro para um cliente a uns 3 km
function pedido(string $id, array $atributos = []): Order
{
    return new Order($atributos + [
        'uuid'                 => 'uuid-' . $id,
        'public_id'            => $id,
        'customer_uuid'        => 'uuid-vendor_centro',
        'driver_assigned_uuid' => 'uuid-driver_motoca',
        'driverAssigned'       => new Driver(['uuid' => 'uuid-driver_motoca', 'public_id' => 'driver_motoca', 'name' => 'Motoca']),
        'payload'              => new Payload(lugar('place_loja', -21.17, -47.81, 'Loja Centro'), lugar('place_cliente-' . $id, -21.2, -47.8, 'Cliente')),
    ]);
}

// km já calculado e guardado no meta, com a chave das coordenadas atuais (o OSRM não é chamado)
function comKm(Order $pedido, float $metros): Order
{
    $o            = $pedido->payload->pickup->location;
    $d            = $pedido->payload->dropoff->location;
    $pedido->meta = ['entregas' => ['km_rota' => ['metros' => $metros, 'fonte' => 'osrm', 'chave' => md5(implode(',', [$o->getLat(), $o->getLng(), $d->getLat(), $d->getLng()]))]]];

    return $pedido;
}

/** A linha congelada como está no banco em memória (sem a conversão do MySQL). */
function linhaDe(string $id): array
{
    return Banco::$tabelas['entregas_valores_pedido']['uuid-' . $id] ?? [];
}

function logou(string $trecho): bool
{
    foreach (Log::$registros as [, $mensagem]) {
        if (str_contains($mensagem, $trecho)) {
            return true;
        }
    }

    return false;
}

Vendor::$lojas = [new Vendor(['uuid' => 'uuid-vendor_centro', 'public_id' => 'vendor_centro', 'name' => 'Loja Centro'])];
```

Troque o `resumo();` do fim por:

```php
echo '== Congela as duas tabelas' . PHP_EOL;
reiniciar();
$atribuicao = new AtribuicaoFalsa();
$calculo    = new CalculoEntregas(null, $atribuicao);
$pedidos    = new Collection([comKm(pedido('order_a'), 3210.4)]);
[$entregas] = $calculo->entregas($pedidos, 'America/Sao_Paulo');
$a          = linhaDe('order_a');
confere(($a['junto_de_km'] ?? null) === 3.0 && ($a['junto_ate_km'] ?? null) === 6.0 && ($a['junto_acima'] ?? null) === false && ($a['junto_motoboy'] ?? null) === 6.0 && ($a['junto_loja'] ?? null) === 9.0, 'congela também a faixa da segunda tabela (3 a 6 km: 6,00 / 9,00)');
confere($entregas[0]['valor_motoboy'] === 8.0 && $entregas[0]['valor_loja'] === 11.37 && $entregas[0]['tabela'] === 'normal' && $entregas[0]['posicao'] === null && $entregas[0]['multiplicador'] === null, 'sem atribuição e sem horário de aceite: tabela normal, nada a reconstituir');
confere($atribuicao->chamadas === [], 'e nenhuma consulta de atribuição');
Setting::$valores['entregas.faixas_juntas'] = [['ate_km' => 6, 'motoboy' => 1, 'loja' => 2]];
$calculo->entregas($pedidos, 'America/Sao_Paulo');
confere(linhaDe('order_a')['junto_motoboy'] === 6.0 && Banco::$upserts === 1, 'segunda tabela nova não muda o pedido já calculado');
reiniciar([]);
$calculo->entregas(new Collection([comKm(pedido('order_b'), 3210.4)]), 'America/Sao_Paulo');
$b = linhaDe('order_b');
confere(array_key_exists('junto_motoboy', $b) && $b['junto_motoboy'] === null && $b['junto_de_km'] === null && $b['junto_acima'] === null, 'segunda tabela vazia: as colunas dela ficam nulas');

echo '== Prévia no card (valorDoPedido)' . PHP_EOL;
reiniciar();
$atribuicao = new AtribuicaoFalsa();
$calculo    = new CalculoEntregas(null, $atribuicao);
$aberto     = comKm(pedido('order_p', ['driver_assigned_uuid' => null, 'driverAssigned' => null, 'status' => 'created', 'adhoc' => true]), 3210.4);
$atribuicao->emAberto = 1;
$atribuicao->periodo  = periodoDeChuva();
$v = $calculo->valorDoPedido($aberto, 'uuid-driver_motoca');
confere($v['valor_motoboy'] === 7.2 && $v['tabela'] === 'junto' && $v['posicao'] === 2 && $v['multiplicador'] === ['nome' => 'Chuva', 'motoboy' => 1.2, 'loja' => 1.1], 'motoboy que já leva um pedido, com chuva: 6 × 1,2 = 7,20 pela segunda tabela');
confere($v['faixa'] === ['de_km' => 3.0, 'ate_km' => 6.0, 'motoboy' => 6.0, 'loja' => 9.0] && $v['km'] === 3.21, 'com a faixa da segunda tabela');
confere(in_array(['emAberto', 'uuid-driver_motoca', 'uuid-order_p'], $atribuicao->chamadas, true), 'conta os pedidos em aberto dele, sem este');
confere((linhaDe('order_p')['motoboy_uuid'] ?? null) === null, 'a prévia não grava a atribuição');
$v = $calculo->valorDoPedido($aberto);
confere($v['valor_motoboy'] === 9.6 && $v['posicao'] === 1 && $v['tabela'] === 'normal', 'sem motoboy (alarme aberto a vários): tabela normal × chuva (8 × 1,2 = 9,60)');
$atribuicao->periodo  = null;
$atribuicao->emAberto = 0;
$v = $calculo->valorDoPedido($aberto, 'uuid-driver_motoca');
confere($v['valor_motoboy'] === 8.0 && $v['tabela'] === 'normal' && $v['multiplicador'] === null, 'livre e sem período: como hoje');

echo '== Atribuição (registrarAtribuicao)' . PHP_EOL;
reiniciar();
$atribuicao = new AtribuicaoFalsa();
$calculo    = new CalculoEntregas(null, $atribuicao);
$p          = comKm(pedido('order_c', ['status' => 'started']), 3210.4);
$atribuicao->emAberto = 1;
$atribuicao->periodo  = periodoDeChuva();
$calculo->registrarAtribuicao($p);
$c = linhaDe('order_c');
confere(($c['motoboy_uuid'] ?? null) === 'uuid-driver_motoca' && ($c['posicao'] ?? null) === 2 && ($c['multiplicador_uuid'] ?? null) === 'mult-chuva' && ($c['multiplicador_nome'] ?? null) === 'Chuva' && ($c['mult_motoboy'] ?? null) === 1.2 && ($c['mult_loja'] ?? null) === 1.1, 'grava o motoboy, a posição 2 e a chuva');
confere(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($c['atribuido_em'] ?? '')) === 1, 'o instante da atribuição em Brasília, sem fuso');
confere(($c['junto_motoboy'] ?? null) === 6.0 && ($c['valor_motoboy'] ?? null) === 8.0, 'e congela o km com as duas tabelas');
$p->status  = 'completed';
[$entregas] = $calculo->entregas(new Collection([$p]), 'America/Sao_Paulo');
confere($entregas[0]['valor_motoboy'] === 7.2 && $entregas[0]['valor_loja'] === 9.9 && $entregas[0]['tabela'] === 'junto' && $entregas[0]['posicao'] === 2, 'no relatório: 7,20 / 9,90 pela segunda tabela');
$atribuicao->emAberto = 0;
$atribuicao->periodo  = null;
confere($calculo->valorDoPedido($p, 'uuid-driver_motoca')['valor_motoboy'] === 7.2, 'atribuição gravada: o card mostra ela, não a prévia');

comKm($p, 4500.0);
[$entregas] = $calculo->entregas(new Collection([$p]), 'America/Sao_Paulo');
$c          = linhaDe('order_c');
confere($c['metros'] === 4500 && $c['posicao'] === 2 && $c['mult_motoboy'] === 1.2, 'km novo: recongela o km e mantém a atribuição');
confere($entregas[0]['valor_motoboy'] === 7.2 && $entregas[0]['faixa']['de_km'] === 3.0, '4,5 km: mesma faixa da segunda tabela (3 a 6) × 1,2');

$p->driver_assigned_uuid = 'uuid-driver_outro';
$calculo->registrarAtribuicao($p);
$c = linhaDe('order_c');
confere($c['motoboy_uuid'] === 'uuid-driver_outro' && $c['posicao'] === 1 && $c['multiplicador_uuid'] === null && $c['mult_motoboy'] === null, 'troca de motoboy: recalcula para o novo (1ª, sem período)');
$p->driver_assigned_uuid = null;
$calculo->registrarAtribuicao($p);
$c = linhaDe('order_c');
confere($c['motoboy_uuid'] === null && $c['posicao'] === null && $c['atribuido_em'] === null, 'pedido sem motoboy: a atribuição é apagada');
confere($c['metros'] === 4500 && $c['valor_motoboy'] === 10.0, 'e a parte do km fica');

reiniciar();
$atribuicao = new AtribuicaoFalsa();
$calculo    = new CalculoEntregas(null, $atribuicao);
$l          = comKm(pedido('order_l'), 3210.4);
$calculo->entregas(new Collection([$l]), 'America/Sao_Paulo');
Banco::$tabelas['entregas_lancamentos'] = [1 => ['id' => 1, 'order_uuid' => 'uuid-order_l', 'tipo' => 'corrida', 'conta_tipo' => 'motoboy', 'valor' => '8.00']];
$atribuicao->emAberto = 2;
$atribuicao->periodo  = periodoDeChuva();
$calculo->registrarAtribuicao($l);
confere((linhaDe('order_l')['posicao'] ?? null) === null && $atribuicao->chamadas === [], 'corrida lançada no financeiro: a atribuição não muda (nem consulta)');

$s = pedido('order_s', ['payload' => new Payload(lugar('place_loja', null, null, 'Loja'), lugar('place_x', null, null, 'Cliente'))]);
$calculo->registrarAtribuicao($s);
confere(linhaDe('order_s') === [] && logou('sem km na atribuição'), 'pedido sem endereço: nada gravado, fica para a reserva (log)');

echo '== Reserva por horários (pedido que escapou do observador)' . PHP_EOL;
reiniciar();
$atribuicao = new AtribuicaoFalsa();
$calculo    = new CalculoEntregas(null, $atribuicao);
$r          = comKm(pedido('order_r', ['started_at' => '2026-10-03 19:00:00']), 3210.4);
$atribuicao->periodos = [periodoDeChuva()];
$atribuicao->posicoes = ['uuid-order_r' => 2];
[$entregas] = $calculo->entregas(new Collection([$r]), 'America/Sao_Paulo');
confere($entregas[0]['valor_motoboy'] === 7.2 && $entregas[0]['valor_loja'] === 9.9 && $entregas[0]['posicao'] === 2 && $entregas[0]['tabela'] === 'junto', 'reconstitui pelo aceite (19:00, dentro da chuva) e pela posição dos horários');
$x = linhaDe('order_r');
confere(($x['atribuido_em'] ?? null) === '2026-10-03 19:00:00' && ($x['motoboy_uuid'] ?? null) === 'uuid-driver_motoca' && ($x['posicao'] ?? null) === 2 && ($x['mult_motoboy'] ?? null) === 1.2, 'e grava a atribuição reconstituída');
$chamada = array_values(array_filter($atribuicao->chamadas, fn ($c) => $c[0] === 'posicoesPorHorarios'))[0] ?? null;
confere($chamada !== null && $chamada[1] === [['uuid' => 'uuid-order_r', 'motoboy' => 'uuid-driver_motoca', 'instante' => '2026-10-03 19:00:00']], 'as posições numa consulta só, com o aceite');
$antes = count($atribuicao->chamadas);
$calculo->entregas(new Collection([$r]), 'America/Sao_Paulo');
confere(count($atribuicao->chamadas) === $antes, 'na consulta seguinte, já gravada: nada a reconstituir');

$d          = comKm(pedido('order_d', ['dispatched_at' => '2026-10-03 20:00:00']), 3210.4);
$atribuicao->posicoes = [];
[$entregas] = $calculo->entregas(new Collection([$d]), 'America/Sao_Paulo');
confere($entregas[0]['valor_motoboy'] === 9.6 && (linhaDe('order_d')['atribuido_em'] ?? null) === '2026-10-03 20:00:00', 'sem started_at: vale o dispatched_at (atribuído pela central); 1ª com chuva = 9,60');

reiniciar([]);
$atribuicao = new AtribuicaoFalsa();
$calculo    = new CalculoEntregas(null, $atribuicao);
$q          = comKm(pedido('order_q', ['started_at' => '2026-10-03 23:30:00']), 3210.4);
$atribuicao->periodos = [periodoDeChuva()];
[$entregas] = $calculo->entregas(new Collection([$q]), 'America/Sao_Paulo');
confere($entregas[0]['valor_motoboy'] === 8.0 && (linhaDe('order_q')['motoboy_uuid'] ?? null) === null, 'aceite fora do período e sem segunda tabela: nada muda, nada gravado');
confere(!array_filter($atribuicao->chamadas, fn ($c) => $c[0] === 'posicoesPorHorarios'), 'nem consulta as posições');

resumo();
```

- [ ] **Passo 2: Ajuste as expectativas do `ganhos-motoboy.php` ao formato novo**

1. Linha ~142, a lista de chaves de cada entrega: acrescente `'posicao', 'tabela', 'multiplicador'` no fim:

```php
confere(array_keys($entregas[0]) === ['loja', 'loja_nome', 'pedido', 'id_interno', 'motoboy', 'motoboy_nome', 'concluido_em', 'origem', 'destino', 'km', 'fonte', 'faixa', 'valor_motoboy', 'valor_loja', 'cancelado_pago', 'cancelado_origem', 'order_uuid', 'motoboy_uuid', 'loja_uuid', 'posicao', 'tabela', 'multiplicador'], 'o formato do relatório e do extrato (cancelado_pago: etapa 3 do iFood; cancelado_origem: integração; uuids: financeiro; posicao/tabela/multiplicador: entrega junto)');
```

2. Linha ~187, "toda coluna gravada existe na migration": some as colunas da migration nova. Logo antes dela, acrescente:

```php
$alteracao = require '/repo/api/database/migrations/2026_10_21_100100_add_junto_e_multiplicador_to_entregas_valores_pedido_table.php';
$colunasDaMigration = array_merge(array_keys($colunas), $alteracao::COLUNAS);
```

e troque `array_keys($colunas)` por `$colunasDaMigration` dentro do `array_diff` daquela linha.

3. O `MotoboyController@valor` (linhas ~292–299) passa a pedir a prévia: troque cada `new CalculoEntregas()` dessas chamadas por `new CalculoEntregas(null, new AtribuicaoSemNada())` e declare, perto do topo do arquivo (depois dos `use`):

```php
/** Sem pedidos em aberto e sem período: a prévia vale como hoje. */
class AtribuicaoSemNada extends \App\Support\Entregas\AtribuicaoDoValor
{
    public function emAberto(string $empresa, string $motoboyUuid, string $exceto): int { return 0; }
    public function multiplicadorEm(string $empresa, string $instante): ?object { return null; }
}
```

A expectativa do `valor()` (linha ~293) muda na Tarefa 8, junto com o `GanhosDoMotoboy`.

- [ ] **Passo 3: Rode e veja falhar**

`PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/entrega-junto.php`
Esperado: falha (o `CalculoEntregas` ainda não recebe a `AtribuicaoDoValor` nem tem `registrarAtribuicao`).

- [ ] **Passo 4: Implemente no `CalculoEntregas`**

4.1. No cabeçalho da classe (o comentário grande), acrescente ao fim do último parágrafo:

```php
 *
 * Entrega junto e multiplicador (spec docs/superpowers/specs/2026-10-21-entrega-junto-e-multiplicador-design.md): a linha
 * congelada guarda também a faixa da segunda tabela (`entregas.faixas_juntas`, "a partir da 2ª entrega junto") e, quando o
 * pedido ganha motoboy (registrarAtribuicao, pelo ObservadorDoValorDaEntrega), a posição dele (2ª ou mais = segunda tabela)
 * e o multiplicador do período ativo. O valor final sai do ValorDaEntrega::final. Pedido concluído sem atribuição gravada
 * (escapou do observador) é reconstituído pelos horários (aplicarReserva).
```

4.2. Construtor e propriedade:

```php
    protected ValoresCongelados $congelados;

    protected AtribuicaoDoValor $atribuicao;

    public function __construct(?ValoresCongelados $congelados = null, ?AtribuicaoDoValor $atribuicao = null)
    {
        $this->congelados = $congelados ?? new ValoresCongelados();
        $this->atribuicao = $atribuicao ?? new AtribuicaoDoValor();
    }
```

4.3. `entregas()`: troque o corpo inteiro por:

```php
    public function entregas(Collection $pedidos, string $fuso, int $limiteCalculos = self::LIMITE_CALCULOS): array
    {
        $this->osrmFalhou = false;

        $pendentes    = 0;
        $entregas     = [];
        $gravar       = [];
        $reserva      = [];
        $faixas       = $this->faixas();
        $faixasJuntas = $this->faixasJuntas();
        $lojas        = $this->lojasDosPedidos($pedidos);
        $comCota      = $this->pedidosComCota($pedidos, $limiteCalculos);
        $congelados   = $this->congelados->carregar($pedidos->pluck('uuid')->all());
        $lancados     = $this->pedidosLancados($pedidos->pluck('uuid')->all());

        foreach ($pedidos as $i => $pedido) {
            $rota = $this->rotaDoPedido($pedido, isset($comCota[$i]));
            if ($rota === null) {
                $pendentes++;
            }

            $motoboy           = $pedido->driverAssigned;
            $coleta            = $pedido->payload?->getPickupOrFirstWaypoint();
            $km                = $rota ? round($rota['metros'] / 1000, 2) : null;
            $usada             = null;
            $lancado           = isset($lancados[$pedido->uuid]);
            $valor             = $rota ? $this->valorCongelado($pedido, $rota, $faixas, $faixasJuntas, $congelados[$pedido->uuid] ?? null, $gravar, $lancado, $usada) : null;
            [$loja, $lojaNome] = $this->lojaDoPedido($pedido, $coleta, $lojas);

            // pedido que escapou do observador (sem a atribuição deste motoboy): reconstituído pelos horários no fim
            $motoboyUuid = (string) ($pedido->driver_assigned_uuid ?: $motoboy?->uuid);
            if ($usada !== null && !$lancado && $motoboyUuid !== '' && (string) ($usada['motoboy_uuid'] ?? '') !== $motoboyUuid) {
                $instante = $this->instanteDoAceite($pedido);
                if ($instante !== null) {
                    $reserva[count($entregas)] = ['uuid' => (string) $pedido->uuid, 'motoboy' => $motoboyUuid, 'instante' => $instante, 'linha' => $usada];
                }
            }

            $entregas[] = [
                'loja'          => $loja,
                'loja_nome'     => $lojaNome,
                'pedido'        => $pedido->public_id,
                'id_interno'    => $pedido->internal_id,
                'motoboy'       => $motoboy?->public_id,
                'motoboy_nome'  => $motoboy?->name,
                // texto cru do COALESCE, na hora da sessão do MySQL, que é a do fuso do app
                'concluido_em'  => Carbon::parse($pedido->entregas_concluido_em, date_default_timezone_get())->setTimezone($fuso)->toIso8601String(),
                'origem'        => $this->enderecoCurto($coleta),
                'destino'       => $this->enderecoCurto($pedido->payload?->getDropoffOrLastWaypoint()),
                'km'            => $km,
                'fonte'         => $rota['fonte'] ?? null,
                'faixa'         => $valor['faixa'] ?? null,
                'valor_motoboy' => $valor['motoboy'] ?? null,
                'valor_loja'    => $valor['loja'] ?? null,
                // pedido iFood cancelado pelo iFood depois do dispatch: conta pela data do cancelamento
                'cancelado_pago' => (bool) ($pedido->entregas_cancelado_pago ?? false),
                // quem cancelou o pago mesmo cancelado: 'ifood' ou 'integracao' (o sistema de origem); null no concluído
                'cancelado_origem' => ($pedido->entregas_cancelado_pago ?? false) ? ($pedido->entregas_cancelado_origem ?? null) : null,
                // para o lançador do financeiro (LancadorDeCorridas): o pedido, o motoboy atribuído e a loja (Vendor) dona
                'order_uuid'    => $pedido->uuid,
                'motoboy_uuid'  => $pedido->driver_assigned_uuid ?: $motoboy?->uuid,
                'loja_uuid'     => $lojas->get($pedido->customer_uuid)?->uuid,
                // entrega junto e multiplicador (ValorDaEntrega): a posição, a tabela usada e os fatores (só para a central:
                // o app e o portal recebem o fator da própria conta)
                'posicao'       => $valor['posicao'] ?? null,
                'tabela'        => $valor['tabela'] ?? null,
                'multiplicador' => $valor['multiplicador'] ?? null,
            ];
        }

        $this->congelados->gravar($gravar);
        $this->aplicarReserva((string) ($pedidos->first()?->company_uuid ?? ''), $reserva, $entregas);

        return [$entregas, $pendentes];
    }
```

4.4. `valorDoPedido()`: troque o método inteiro por:

```php
    /**
     * Km e valor de um pedido para o app do motoboy (card de aceitar e detalhes): calcula a rota agora, se faltar, e
     * congela o valor como em entregas(). Antes da atribuição, a prévia (comPrevia): a posição que o pedido teria para
     * $motoboyUuid (sem ele, a 1ª) e o multiplicador de agora; nada da prévia é gravado.
     *
     * @return array{km: ?float, fonte: ?string, faixa: ?array, valor_motoboy: ?float, posicao: ?int, tabela: ?string, multiplicador: ?array}
     */
    public function valorDoPedido(Order $pedido, ?string $motoboyUuid = null): array
    {
        $this->osrmFalhou = false;

        $rota = $this->rotaDoPedido($pedido, true);
        if ($rota === null) {
            return ['km' => null, 'fonte' => null, 'faixa' => null, 'valor_motoboy' => null, 'posicao' => null, 'tabela' => null, 'multiplicador' => null];
        }

        $gravar = [];
        $usada  = null;
        $linha  = $this->congelados->carregar([$pedido->uuid])[$pedido->uuid] ?? null;
        // a consulta ao financeiro só quando o km mudou (o card de aceitar chama a cada exibição)
        $mudou  = $linha !== null && (int) $linha['metros'] !== (int) round((float) $rota['metros']);
        $valor  = $this->valorCongelado($pedido, $rota, $this->faixas(), $this->faixasJuntas(), $linha, $gravar, $mudou && isset($this->pedidosLancados([$pedido->uuid])[$pedido->uuid]), $usada);
        $this->congelados->gravar($gravar);

        if ($usada !== null && empty($usada['motoboy_uuid'])) {
            $valor = $this->comPrevia($pedido, $usada, $motoboyUuid) ?? $valor;
        }

        return [
            'km'            => round($rota['metros'] / 1000, 2),
            'fonte'         => $rota['fonte'] ?? null,
            'faixa'         => $valor['faixa'] ?? null,
            'valor_motoboy' => $valor['motoboy'] ?? null,
            'posicao'       => $valor['posicao'] ?? null,
            'tabela'        => $valor['tabela'] ?? null,
            'multiplicador' => $valor['multiplicador'] ?? null,
        ];
    }

    /**
     * O pedido ganhou (ou perdeu) motoboy: grava a parte da atribuição do valor congelado (motoboy, agora, posição e o
     * multiplicador do período ativo), congelando o km antes se faltar. Pedido com a corrida lançada no financeiro não
     * muda. Sem km (sem endereço) não grava: a reserva do entregas() reconstitui pelos horários quando ele concluir.
     * Chamado pelo ObservadorDoValorDaEntrega, com a empresa do pedido na sessão (as faixas são da empresa da sessão).
     */
    public function registrarAtribuicao(Order $pedido): void
    {
        $this->osrmFalhou = false;

        $uuid = (string) $pedido->uuid;
        if (isset($this->pedidosLancados([$uuid])[$uuid])) {
            return;
        }

        $motoboy = (string) ($pedido->driver_assigned_uuid ?? '');
        $linha   = $this->congelados->carregar([$uuid])[$uuid] ?? null;

        if ($motoboy === '') {
            if ($linha !== null && !empty($linha['motoboy_uuid'])) {
                $this->congelados->gravarAtribuicoes([array_fill_keys(ValorDaEntrega::COLUNAS_DA_ATRIBUICAO, null) + $linha]);
            }

            return;
        }

        $rota = $this->rotaDoPedido($pedido, true);
        if ($rota === null) {
            Log::info('[entregas] valor: pedido sem km na atribuição; fica para a reserva por horários', ['pedido' => $pedido->public_id]);

            return;
        }

        $gravar = [];
        $usada  = null;
        $this->valorCongelado($pedido, $rota, $this->faixas(), $this->faixasJuntas(), $linha, $gravar, false, $usada);
        $this->congelados->gravar($gravar);
        if ($usada === null) {
            return; // sem faixas cadastradas: não há valor para atribuir
        }

        $empresa    = (string) $pedido->company_uuid;
        $agora      = now()->format('Y-m-d H:i:s');
        $atribuicao = $this->atribuicaoNova($motoboy, $agora, 1 + $this->atribuicao->emAberto($empresa, $motoboy, $uuid), $this->atribuicao->multiplicadorEm($empresa, $agora));
        $this->congelados->gravarAtribuicoes([$atribuicao + $usada]);
    }

    /** A prévia antes da atribuição (não grava). Falha na consulta: null (vale o valor sem a prévia), e o log. */
    protected function comPrevia(Order $pedido, array $linha, ?string $motoboyUuid): ?array
    {
        try {
            $empresa = (string) $pedido->company_uuid;
            $agora   = now()->format('Y-m-d H:i:s');
            $posicao = $motoboyUuid ? 1 + $this->atribuicao->emAberto($empresa, $motoboyUuid, (string) $pedido->uuid) : 1;
            $periodo = $this->atribuicao->multiplicadorEm($empresa, $agora);
        } catch (\Throwable $e) {
            Log::warning('[entregas] valor: não deu para montar a prévia do pedido; vale o valor sem ela', ['pedido' => $pedido->public_id, 'erro' => get_class($e)]);

            return null;
        }

        return ValorDaEntrega::final($this->atribuicaoNova((string) $motoboyUuid, $agora, $posicao, $periodo) + $linha);
    }

    /** A parte da atribuição de uma linha (ValorDaEntrega::COLUNAS_DA_ATRIBUICAO), com as cópias do período. */
    protected function atribuicaoNova(string $motoboyUuid, string $instante, int $posicao, ?object $periodo): array
    {
        return [
            'motoboy_uuid'       => $motoboyUuid !== '' ? $motoboyUuid : null,
            'atribuido_em'       => $instante,
            'posicao'            => max(1, min(255, $posicao)),
            'multiplicador_uuid' => $periodo ? (string) $periodo->uuid : null,
            'multiplicador_nome' => $periodo ? (string) $periodo->nome : null,
            'mult_motoboy'       => $periodo ? round((float) $periodo->mult_motoboy, 2) : null,
            'mult_loja'          => $periodo ? round((float) $periodo->mult_loja, 2) : null,
        ];
    }

    /**
     * Reconstitui a atribuição dos pedidos concluídos que escaparam do observador (atribuição pelo query builder,
     * saveQuietly, pedido de antes do deploy), pelo horário do aceite. Só quando muda o valor: havia segunda tabela no
     * cálculo ou um período no instante. Uma consulta de períodos e uma de posições para a lista toda.
     *
     * @param array<int, array{uuid: string, motoboy: string, instante: string, linha: array}> $reserva índice em $entregas => pedido
     */
    protected function aplicarReserva(string $empresa, array $reserva, array &$entregas): void
    {
        if (!$reserva || $empresa === '') {
            return;
        }

        $instantes = array_column($reserva, 'instante');
        $periodos  = $this->atribuicao->multiplicadoresDoPeriodo($empresa, min($instantes), max($instantes));

        $precisa = [];
        foreach ($reserva as $indice => $item) {
            $periodo = Multiplicadores::noInstante($periodos, $item['instante']);
            if ($periodo !== null || ($item['linha']['junto_motoboy'] ?? null) !== null) {
                $precisa[$indice] = $item + ['periodo' => $periodo];
            }
        }
        if (!$precisa) {
            return;
        }

        $posicoes = $this->atribuicao->posicoesPorHorarios($empresa, array_values(array_map(fn ($item) => ['uuid' => $item['uuid'], 'motoboy' => $item['motoboy'], 'instante' => $item['instante']], $precisa)));

        $gravar = [];
        foreach ($precisa as $indice => $item) {
            $linha    = $this->atribuicaoNova($item['motoboy'], $item['instante'], $posicoes[$item['uuid']] ?? 1, $item['periodo']) + $item['linha'];
            $gravar[] = $linha;
            $valor    = ValorDaEntrega::final($linha);

            $entregas[$indice]['faixa']         = $valor['faixa'];
            $entregas[$indice]['valor_motoboy'] = $valor['motoboy'];
            $entregas[$indice]['valor_loja']    = $valor['loja'];
            $entregas[$indice]['posicao']       = $valor['posicao'];
            $entregas[$indice]['tabela']        = $valor['tabela'];
            $entregas[$indice]['multiplicador'] = $valor['multiplicador'];
        }

        $this->congelados->gravarAtribuicoes($gravar);
    }

    /** Aceite do pedido em Brasília ('Y-m-d H:i:s'): started_at, ou o dispatched_at (atribuído pela central); null sem eles. */
    protected function instanteDoAceite(Order $pedido): ?string
    {
        $valor = $pedido->started_at ?: $pedido->dispatched_at;
        if (!$valor) {
            return null;
        }

        return $valor instanceof \DateTimeInterface ? $valor->format('Y-m-d H:i:s') : substr((string) $valor, 0, 19);
    }
```

4.5. `valorCongelado()`: troque o método inteiro por:

```php
    /**
     * Valor da entrega para esta rota: o da linha congelada, se foi calculada com o mesmo km (metros); senão, as faixas
     * das duas tabelas vigentes, que entram em $gravar (congela, ou recongela quando o km mudou; a atribuição já gravada
     * continua valendo). Pedido com a corrida lançada no financeiro ($lancado) fica com o congelado, mesmo com outro km.
     * Sem faixas cadastradas e sem valor congelado para este km, null. $usada recebe a linha que deu o valor (para a
     * prévia, a atribuição e a reserva).
     *
     * @return array{faixa: array, motoboy: float, loja: float, tabela: string, posicao: ?int, multiplicador: ?array}|null
     */
    protected function valorCongelado(Order $pedido, array $rota, array $faixas, array $faixasJuntas, ?array $linha, array &$gravar, bool $lancado = false, ?array &$usada = null): ?array
    {
        $usada  = null;
        $metros = (int) round((float) $rota['metros']);
        if ($linha !== null && ($lancado || (int) $linha['metros'] === $metros)) {
            $usada = $linha;

            return ValorDaEntrega::final($linha);
        }

        // a faixa sai do mesmo km exibido (metros reais); os metros inteiros só identificam o km da linha congelada
        $km    = round((float) $rota['metros'] / 1000, 2);
        $faixa = $this->faixaDoKm($faixas, $km);
        if ($faixa === null) {
            return null;
        }
        $junto = $this->faixaDoKm($faixasJuntas, $km);

        $nova = [
            'company_uuid'  => $pedido->company_uuid,
            'order_uuid'    => $pedido->uuid,
            'chave'         => $rota['chave'] ?? null,
            'metros'        => $metros,
            'fonte'         => $rota['fonte'] ?? 'osrm',
            'de_km'         => $faixa['de_km'],
            'ate_km'        => $faixa['ate_km'],
            'acima'         => !empty($faixa['acima']),
            'valor_motoboy' => $faixa['motoboy'],
            'valor_loja'    => $faixa['loja'],
            'junto_de_km'   => $junto['de_km'] ?? null,
            'junto_ate_km'  => $junto['ate_km'] ?? null,
            'junto_acima'   => $junto !== null ? !empty($junto['acima']) : null,
            'junto_motoboy' => $junto['motoboy'] ?? null,
            'junto_loja'    => $junto['loja'] ?? null,
        ];
        $gravar[] = $nova;
        $usada    = $nova + array_intersect_key($linha ?? [], array_flip(ValorDaEntrega::COLUNAS_DA_ATRIBUICAO));

        return ValorDaEntrega::final($usada);
    }
```

4.6. Apague o método `valorDaLinha()` (o `ValorDaEntrega::final` o substitui). Confirme com `grep -n "valorDaLinha" api/app -r` que não sobrou chamada.

4.7. Imports no topo do arquivo: acrescente `use App\Support\Entregas\Financeiro\Multiplicadores;` (o `Lancamentos` já é importado do mesmo namespace; mantenha a ordem alfabética dos `use`).

- [ ] **Passo 5: Rode e veja passar**

```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/entrega-junto.php
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ganhos-motoboy.php
```
Esperado: o `entrega-junto.php` com `FALHAS: 0`. No `ganhos-motoboy.php`, só as duas expectativas do formato do app (linhas ~226 e ~293) podem falhar ainda: elas mudam na Tarefa 8. Qualquer outra falha é regressão: corrija antes de seguir.

- [ ] **Passo 6: Commit**

```bash
git add api/app/Support/Entregas/CalculoEntregas.php scripts/teste-php/entrega-junto.php scripts/teste-php/ganhos-motoboy.php
git commit -m "Entrega junto: segunda tabela congelada, prévia no card, atribuição e reserva por horários no CalculoEntregas"
```

---

### Tarefa 7: Observador da atribuição

**Arquivos:**
- Criar: `api/app/Listeners/Entregas/ObservadorDoValorDaEntrega.php`
- Modificar: `api/app/Providers/AppServiceProvider.php`
- Modificar: `scripts/teste-php/entrega-junto.php`

- [ ] **Passo 1: Teste**

No `entrega-junto.php`, acrescente o `use App\Listeners\Entregas\ObservadorDoValorDaEntrega;` e, depois da classe `AtribuicaoFalsa`:

```php
/** O cálculo do observador: registra quem foi chamado e com qual empresa na sessão. */
class CalculoQueRegistra extends CalculoEntregas
{
    public array $registrados = [];
    public array $sessoes     = [];
    public bool $falhar       = false;

    public function registrarAtribuicao(Order $pedido): void
    {
        if ($this->falhar) {
            throw new \RuntimeException('banco fora do ar');
        }
        $this->registrados[] = $pedido->uuid;
        $this->sessoes[]     = session('company');
    }
}
```

Antes do `resumo();` final:

```php
echo '== Observador (Order::updated)' . PHP_EOL;
reiniciar();
$registra = new CalculoQueRegistra(null, new AtribuicaoFalsa());
ObservadorDoValorDaEntrega::$calculo = fn () => $registra;
Order::$todos = [comKm(pedido('order_o', ['company_uuid' => 'empresa-o']), 3210.4)];
$evento = fn (array $mudou, string $uuid = 'uuid-order_o') => new class($mudou, $uuid) {
    public $public_id    = 'order_o';
    public $company_uuid = 'empresa-o';
    public function __construct(private array $mudou, public string $uuid) {}
    public function wasChanged($campo) { return in_array($campo, $this->mudou, true); }
};
ObservadorDoValorDaEntrega::aoAtualizar($evento(['status']));
confere($registra->registrados === [], 'sem troca de motoboy: nada');
ObservadorDoValorDaEntrega::aoAtualizar($evento(['driver_assigned_uuid', 'status']));
confere($registra->registrados === ['uuid-order_o'] && $registra->sessoes === ['empresa-o'], 'motoboy mudou: registra o pedido relido, com a empresa dele na sessão');
confere(session('company') === 'empresa', 'e devolve a sessão como estava');
ObservadorDoValorDaEntrega::aoAtualizar($evento(['driver_assigned_uuid'], 'uuid-sumiu'));
confere(count($registra->registrados) === 1, 'pedido que não se acha mais: nada');
$registra->falhar = true;
ObservadorDoValorDaEntrega::aoAtualizar($evento(['driver_assigned_uuid']));
confere(logou('falha ao gravar a atribuição') && session('company') === 'empresa', 'falha: só o log, nunca lança, e a sessão volta');
ObservadorDoValorDaEntrega::$calculo = null;
```

- [ ] **Passo 2: Rode e veja falhar**

`PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/entrega-junto.php`
Esperado: "Class App\Listeners\Entregas\ObservadorDoValorDaEntrega not found".

- [ ] **Passo 3: Implemente**

`api/app/Listeners/Entregas/ObservadorDoValorDaEntrega.php`:

```php
<?php

namespace App\Listeners\Entregas;

use App\Support\Entregas\CalculoEntregas;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: Order::updated → quando o motoboy do pedido muda (aceite, atribuição pela central, troca pelo
 * líder, pedido que volta a ficar sem motoboy), grava a parte da atribuição do valor congelado (posição e multiplicador:
 * CalculoEntregas::registrarAtribuicao). Registrado no AppServiceProvider::congelarValorNaAtribuicao. Independe da
 * distribuição estar ligada.
 *
 * Relê o pedido (instância própria: o cálculo grava o km no meta com timestamps desligados, e isso não pode vazar para a
 * instância que o Fleet-Ops ainda vai salvar) e põe a empresa dele na sessão (as faixas são da empresa da sessão; o
 * aceite pelo app e os comandos da fila podem rodar sem ela).
 *
 * Nunca lança: roda dentro da gravação do pedido (vai para o log). O que escapar (saveQuietly, atribuição pelo query
 * builder) o CalculoEntregas reconstitui pelos horários quando o pedido concluir.
 */
class ObservadorDoValorDaEntrega
{
    /** Testes: devolve o cálculo no lugar do container. */
    public static ?\Closure $calculo = null;

    public static function aoAtualizar(object $pedido): void
    {
        try {
            if (!$pedido->wasChanged('driver_assigned_uuid')) {
                return;
            }

            $fresco = Order::where('company_uuid', $pedido->company_uuid)
                ->where('uuid', $pedido->uuid)
                ->with(['payload.pickup', 'payload.dropoff', 'payload.waypoints'])
                ->first();
            if (!$fresco) {
                return;
            }

            $calculo  = static::$calculo ? (static::$calculo)() : app(CalculoEntregas::class);
            $anterior = session('company');
            session(['company' => $pedido->company_uuid]);
            try {
                $calculo->registrarAtribuicao($fresco);
            } finally {
                session(['company' => $anterior]);
            }
        } catch (\Throwable $e) {
            Log::warning('[entregas] valor: falha ao gravar a atribuição do pedido', ['pedido' => $pedido->public_id ?? null, 'erro' => get_class($e)]);
        }
    }
}
```

Se o `Teste\Consulta` do `stubs-ganhos.php` não aceitar `where(...)->where(...)->with(...)->first()` (confira as linhas ~392–450 dele: `where`, `with` e `first` existem), ajuste o stub e não o observador.

No `AppServiceProvider.php`:
- `use App\Listeners\Entregas\ObservadorDoValorDaEntrega;` junto dos outros `use App\Listeners\Entregas\...`;
- no `boot()`, depois de `$this->lancarCorridasNoFinanceiro();`, a linha `$this->congelarValorNaAtribuicao();`;
- o método, depois do `lancarCorridasNoFinanceiro()`:

```php
    /**
     * Entregas: o motoboy do pedido mudou → grava a posição dele (entrega junto) e o multiplicador do período no valor
     * congelado (ver ObservadorDoValorDaEntrega e CalculoEntregas::registrarAtribuicao).
     */
    protected function congelarValorNaAtribuicao(): void
    {
        Order::updated(fn ($pedido) => ObservadorDoValorDaEntrega::aoAtualizar($pedido));
    }
```

- [ ] **Passo 4: Rode**

```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/entrega-junto.php
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/imports-dos-providers.php
```
Esperado: `FALHAS: 0` nos dois.

- [ ] **Passo 5: Commit**

```bash
git add api/app/Listeners/Entregas/ObservadorDoValorDaEntrega.php api/app/Providers/AppServiceProvider.php scripts/teste-php/entrega-junto.php
git commit -m "Entrega junto: observador grava a posição e o multiplicador quando o pedido ganha motoboy"
```

---

### Tarefa 8: O que sai para o Financeiro, o app e o portal

**Arquivos:**
- Modificar: `api/app/Support/Entregas/Financeiro/LancadorDeCorridas.php`
- Modificar: `api/app/Support/Entregas/Financeiro/ExtratoDaConta.php`
- Modificar: `api/app/Support/Entregas/GanhosDoMotoboy.php`
- Modificar: `api/app/Http/Controllers/Entregas/MotoboyController.php`
- Modificar: `api/app/Http/Controllers/Entregas/PortalLojaController.php`
- Modificar testes: `financeiro-lancador.php`, `financeiro-console.php`, `financeiro-portal-emails.php`, `ganhos-motoboy.php`, `entrega-junto.php`

- [ ] **Passo 1: Testes**

1. `financeiro-lancador.php`, no `entregas()` do `CalculoFalso` (linhas ~101–132), acrescente ao fim do array de cada entrega:

```php
                'posicao'          => $p->posicao ?? null,
                'tabela'           => $p->tabela ?? null,
                'multiplicador'    => $p->multiplicador ?? null,
```

(Se o `ModeloDeTeste` não responder a `??` em atributo ausente, troque por `$p->getAttribute('posicao')` ou o que o stub oferecer: confira em `stubs-ifood-fleetbase.php`.)

Depois do caso "== lancar" (linhas ~171–191), acrescente:

```php
echo '== Entrega junto e multiplicador nos detalhes' . PHP_EOL;
reiniciarFinanceiro();
$calculo  = new CalculoFalso();
$lancador = new LancadorDeCorridas($calculo);
$j        = pedidoConcluido('order_j', '2026-10-21 19:30:00', ['posicao' => 2, 'tabela' => 'junto', 'multiplicador' => ['nome' => 'Chuva', 'motoboy' => 1.2, 'loja' => 1.0]]);
$lancador->lancar('empresa-1', new Collection([$j]));
$dm = json_decode((string) (daConta(lancamentosDo('order_j'), 'motoboy')['detalhes'] ?? ''), true);
$dl = json_decode((string) (daConta(lancamentosDo('order_j'), 'loja')['detalhes'] ?? ''), true);
confere(($dm['junto'] ?? null) === 2 && ($dl['junto'] ?? null) === 2, '2ª entrega nas duas contas');
confere(($dm['multiplicador'] ?? null) === ['nome' => 'Chuva', 'fator' => 1.2] && !array_key_exists('multiplicador', $dl ?? []), 'chuva só na conta cujo fator mudou (motoboy ×1,2; loja ×1)');
$n = pedidoConcluido('order_n', '2026-10-21 19:40:00', ['posicao' => 2, 'tabela' => 'normal']);
$lancador->lancar('empresa-1', new Collection([$n]));
$dn = json_decode((string) (daConta(lancamentosDo('order_n'), 'motoboy')['detalhes'] ?? ''), true);
confere(!array_key_exists('junto', $dn ?? []) && !array_key_exists('multiplicador', $dn ?? []), 'posição 2 pela tabela normal (segunda tabela vazia) e sem período: detalhes como antes');
```

2. `financeiro-console.php`, linha ~109: acrescente `, 'junto' => null, 'multiplicador' => null` antes do `]` final do array esperado dos `detalhes`. Faça o mesmo em `financeiro-portal-emails.php`, linha ~344.

Ainda no `financeiro-console.php`, depois da linha ~109:

```php
$comSelos = Lancamentos::inserir([
    'company_uuid' => 'empresa-1', 'conta_tipo' => 'motoboy', 'conta_uuid' => 'driver-1', 'tipo' => 'corrida', 'valor' => 7.2,
    'data_referencia' => '2026-10-03', 'order_uuid' => 'order-selos', 'chave_unica' => 'corrida:motoboy:order-selos',
    'detalhes' => ['pedido' => 'order_selos', 'numero' => 'RP9', 'km' => 3.21, 'faixa' => ['de_km' => 3, 'ate_km' => 6, 'acima' => false],
        'loja_nome' => 'Pizzaria Ficticia', 'destino' => 'Rua X, 10', 'cancelado_pago' => false, 'cancelado_origem' => null,
        'junto' => 2, 'multiplicador' => ['nome' => 'Chuva', 'fator' => 1.2]],
]);
$linhaSelos = \App\Support\Entregas\Financeiro\ExtratoDaConta::linhas([(object) (array_values(array_filter(linhasDe('entregas_lancamentos'), fn ($l) => $l['order_uuid'] === 'order-selos'))[0])], [], true)[0] ?? [];
confere(($linhaSelos['detalhes']['junto'] ?? null) === 2 && ($linhaSelos['detalhes']['multiplicador'] ?? null) === ['nome' => 'Chuva', 'fator' => 1.2], 'extrato: 2ª entrega e a chuva saem nos detalhes');
```

(O `ExtratoDaConta::linhas($lancamentos, $situacoes, $publico)` é o formatador em lote usado por `Faturas::itensFormatados`. Se a assinatura for outra, siga a de lá.)

3. `ganhos-motoboy.php`:
- linha ~216 (chaves da linha do app): acrescente `'junto', 'multiplicador'` no fim;
- linhas ~226 e ~293 (valor de um pedido): acrescente `'junto' => null, 'multiplicador' => null` no fim do array esperado.

4. `entrega-junto.php`, antes do `resumo();`, acrescente `use App\Support\Entregas\GanhosDoMotoboy;` no topo e:

```php
echo '== O que o app recebe (GanhosDoMotoboy)' . PHP_EOL;
$entrega = ['pedido' => 'order_e', 'concluido_em' => '2026-10-03T19:42:00-03:00', 'loja_nome' => 'Loja', 'destino' => 'Rua', 'km' => 3.21, 'fonte' => 'osrm', 'faixa' => ['de_km' => 3.0, 'ate_km' => 6.0, 'motoboy' => 6.0, 'loja' => 9.0], 'valor_motoboy' => 7.2, 'cancelado_pago' => false, 'cancelado_origem' => null, 'posicao' => 2, 'tabela' => 'junto', 'multiplicador' => ['nome' => 'Chuva', 'motoboy' => 1.2, 'loja' => 1.1]];
$linha   = GanhosDoMotoboy::linha($entrega);
confere($linha['junto'] === 2 && $linha['multiplicador'] === ['nome' => 'Chuva', 'fator' => 1.2], 'linha dos ganhos: a posição (selo "2ª entrega") e a chuva dele');
confere(!str_contains(json_encode($linha), '1.1') && !str_contains(json_encode($linha), '9.0'), 'nunca o fator nem o valor da loja');
$valor = GanhosDoMotoboy::valor(new Order(['public_id' => 'order_e']), ['km' => 3.21, 'fonte' => 'osrm', 'faixa' => $entrega['faixa'], 'valor_motoboy' => 7.2, 'posicao' => 2, 'tabela' => 'junto', 'multiplicador' => ['nome' => 'Chuva', 'motoboy' => 1.0, 'loja' => 1.1]]);
confere($valor['junto'] === 2 && $valor['multiplicador'] === null, 'card: 2ª entrega; fator do motoboy ×1, sem selo de chuva');
confere(GanhosDoMotoboy::valor(new Order(['public_id' => 'order_e']), ['km' => 3.21, 'fonte' => 'osrm', 'faixa' => $entrega['faixa'], 'valor_motoboy' => 8.0, 'posicao' => 2, 'tabela' => 'normal', 'multiplicador' => null])['junto'] === null, 'posição 2 pela tabela normal (sem segunda tabela): sem selo');
```

- [ ] **Passo 2: Rode e veja falhar**

```bash
for t in entrega-junto financeiro-lancador financeiro-console financeiro-portal-emails ganhos-motoboy; do echo "== $t"; PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php | grep -E "FALHA|FALHAS"; done
```
Esperado: falhas nos casos novos e nos formatos que mudaram.

- [ ] **Passo 3: `LancadorDeCorridas`**

No `lancar()`, tire `'detalhes' => static::detalhes($entrega),` do `$base` e ponha os detalhes em cada inserção:

```php
                $motoboy = Lancamentos::inserir($base + [
                    'conta_tipo'  => Financeiro::CONTA_MOTOBOY,
                    'conta_uuid'  => $entrega['motoboy_uuid'],
                    'valor'       => (float) $entrega['valor_motoboy'],
                    'detalhes'    => static::detalhes($entrega, Financeiro::CONTA_MOTOBOY),
                    'chave_unica' => 'corrida:' . Financeiro::CONTA_MOTOBOY . ':' . $entrega['order_uuid'],
                ]);
```

```php
                    $loja = Lancamentos::inserir($base + [
                        'conta_tipo'  => Financeiro::CONTA_LOJA,
                        'conta_uuid'  => $entrega['loja_uuid'],
                        'valor'       => (float) $entrega['valor_loja'],
                        'detalhes'    => static::detalhes($entrega, Financeiro::CONTA_LOJA),
                        'chave_unica' => 'corrida:' . Financeiro::CONTA_LOJA . ':' . $entrega['order_uuid'],
                    ]);
```

Procure outras chamadas de `static::detalhes(` no arquivo (`grep -n "static::detalhes(" api/app/Support/Entregas/Financeiro/LancadorDeCorridas.php`) e passe a conta de cada uma. O relançamento (linha ~350) copia os `detalhes` da corrida estornada e não chama o método.

Troque o método `detalhes`:

```php
    /**
     * Detalhes da corrida da conta (nenhum valor, só a faixa com os limites). Entrega junto (`junto`: a posição, quando a
     * segunda tabela foi usada) nas duas contas; o multiplicador ({nome, fator}) só na conta cujo fator mudou: o motoboy
     * nunca vê o fator da loja, nem a loja o do motoboy.
     */
    private static function detalhes(array $entrega, string $conta): array
    {
        $faixa    = $entrega['faixa'] ?? null;
        $detalhes = [
            'pedido'           => $entrega['pedido'] ?? null,
            'numero'           => $entrega['id_interno'] ?? null,
            'km'               => $entrega['km'] ?? null,
            'faixa'            => is_array($faixa) ? [
                'de_km'  => (float) ($faixa['de_km'] ?? 0),
                'ate_km' => (float) ($faixa['ate_km'] ?? 0),
                'acima'  => !empty($faixa['acima']),
            ] : null,
            'loja_nome'        => $entrega['loja_nome'] ?? null,
            'destino'          => $entrega['destino'] ?? null,
            'cancelado_pago'   => (bool) ($entrega['cancelado_pago'] ?? false),
            'cancelado_origem' => $entrega['cancelado_origem'] ?? null,
        ];

        if (($entrega['tabela'] ?? null) === 'junto' && !empty($entrega['posicao'])) {
            $detalhes['junto'] = (int) $entrega['posicao'];
        }
        $multiplicador = $conta === Financeiro::CONTA_LOJA ? ValorDaEntrega::daLoja($entrega['multiplicador'] ?? null) : ValorDaEntrega::doMotoboy($entrega['multiplicador'] ?? null);
        if ($multiplicador !== null) {
            $detalhes['multiplicador'] = $multiplicador;
        }

        return $detalhes;
    }
```

E o `use App\Support\Entregas\ValorDaEntrega;` no topo.

- [ ] **Passo 4: `ExtratoDaConta`**

```php
    /** Campos dos detalhes da corrida que saem no extrato (nunca um valor). */
    private const CAMPOS_DOS_DETALHES = ['km', 'faixa', 'loja_nome', 'destino', 'cancelado_pago', 'cancelado_origem', 'junto', 'multiplicador'];
```

No array devolvido por `detalhes()`, depois de `'cancelado_origem' => …,`:

```php
            // entrega junto (a posição, quando a segunda tabela foi usada) e o multiplicador desta conta ({nome, fator})
            'junto'            => isset($crus['junto']) && is_numeric($crus['junto']) ? (int) $crus['junto'] : null,
            'multiplicador'    => is_array($crus['multiplicador'] ?? null) ? [
                'nome'  => (string) ($crus['multiplicador']['nome'] ?? ''),
                'fator' => round((float) ($crus['multiplicador']['fator'] ?? 1), 2),
            ] : null,
```

Atualize o comentário do `detalhes()` ("Os detalhes do contrato (km, faixa, loja, destino, cancelado pago, entrega junto e multiplicador)…").

- [ ] **Passo 5: `GanhosDoMotoboy`**

No `linha()`, depois de `'cancelado_origem' => …,`:

```php
            // entrega junto (a posição, quando pagou pela segunda tabela; senão null) e o multiplicador dele ({nome, fator};
            // nunca o da loja). Mesmo formato dos detalhes do extrato (junto = posição)
            'junto'         => static::junto($entrega),
            'multiplicador' => ValorDaEntrega::doMotoboy($entrega['multiplicador'] ?? null),
```

No `valor()`, depois de `'valor' => $valor['valor_motoboy'],`:

```php
            'junto'         => static::junto($valor),
            'multiplicador' => ValorDaEntrega::doMotoboy($valor['multiplicador'] ?? null),
```

E o ajudante, no fim da classe:

```php
    /** A posição da entrega junto (2, 3…) quando ela pagou pela segunda tabela; senão null. */
    protected static function junto(array $valor): ?int
    {
        return ($valor['tabela'] ?? null) === 'junto' && !empty($valor['posicao']) ? (int) $valor['posicao'] : null;
    }
```

`GanhosDoMotoboy` está no namespace `App\Support\Entregas`, o mesmo do `ValorDaEntrega`: não precisa de `use`.

- [ ] **Passo 6: `MotoboyController@valor`**

Troque `$calculo->valorDoPedido($pedido)` por `$calculo->valorDoPedido($pedido, (string) $motoboy->uuid)` e atualize o docblock do método (se houver) com "prévia para este motoboy (posição e multiplicador) antes da atribuição".

- [ ] **Passo 7: `PortalLojaController` (extrato da loja)**

No `array_map` das entregas (linhas ~94–106), depois de `'cancelado_origem' => …,`:

```php
            // entrega junto e multiplicador: o selo da loja só quando o valor dela mudou (fator ≠ 1); nunca o do motoboy
            'junto'         => ($e['tabela'] ?? null) === 'junto' ? (int) $e['posicao'] : null,
            'multiplicador' => \App\Support\Entregas\ValorDaEntrega::daLoja($e['multiplicador'] ?? null),
```

(Se o arquivo já importa classes de `App\Support\Entregas`, use `use App\Support\Entregas\ValorDaEntrega;` no topo e o nome curto.)

Se `financeiro-portal-emails.php` ou `mapa-da-loja.php` conferirem as chaves exatas da entrega do extrato do portal (`grep -n "cancelado_origem" scripts/teste-php/financeiro-portal-emails.php scripts/teste-php/mapa-da-loja.php`), acrescente `'junto' => null, 'multiplicador' => null` lá também.

- [ ] **Passo 8: Rode tudo**

```bash
for t in entrega-junto financeiro-multiplicadores financeiro-lancador financeiro-console financeiro-lojas financeiro-faturas financeiro-pagamentos financeiro-quitacao financeiro-portal-emails financeiro-ciclo ganhos-motoboy cartao-do-alarme avisos-push app-do-motoboy mapa-da-loja fuso fuso-relatorio imports-dos-providers; do printf "%-28s " $t; PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php | tail -1; done
```
Esperado: todos `FALHAS: 0`. O `cartao-do-alarme.php` chama o `valorDoPedido` sem motoboy: se a prévia lançar ali (stub sem `whereNull`/operadores), o `comPrevia` cai no `catch` e loga, e o valor fica o de hoje. Se esse teste conferir "sem warnings", injete uma `AtribuicaoDoValor` falsa no `CalculoEntregas` que ele monta, como no `ganhos-motoboy.php` (Tarefa 6, passo 2.3).

- [ ] **Passo 9: Commit**

```bash
git add api/app/Support/Entregas/Financeiro/LancadorDeCorridas.php api/app/Support/Entregas/Financeiro/ExtratoDaConta.php api/app/Support/Entregas/GanhosDoMotoboy.php api/app/Http/Controllers/Entregas/MotoboyController.php api/app/Http/Controllers/Entregas/PortalLojaController.php scripts/teste-php/*.php
git commit -m "Entrega junto: selos nos detalhes da corrida, no extrato, no app e no portal (fator só da própria conta)"
```

---

### Tarefa 9: Sintaxe de tudo e bateria completa

- [ ] **Passo 1: Sintaxe**

```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs $(git diff --name-only main -- 'api/*.php')
```
Esperado: nenhum erro.

- [ ] **Passo 2: Todos os testes php-wasm**

```bash
for f in scripts/teste-php/*.php; do case "$f" in *stubs*|*fixtures*) continue;; esac; r=$(PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs "$f" 2>&1 | tail -1); echo "$r  $f"; done | grep -v "FALHAS: 0"
```
Esperado: saída vazia (nenhum teste com falha). Os arquivos `ifood-stub-banco.php` e afins que não são testes podem aparecer: confira se eles terminavam em `FALHAS: 0` antes da mudança (`git stash` não; rode na `main` noutra worktree) e ignore só os que já não passavam.

- [ ] **Passo 3: Commit, se algo mudou**

```bash
git status --short
```

---

### Tarefa 10: Documentação

**Arquivos:**
- Modificar: `api/app/Support/Entregas/Financeiro/CLAUDE.md`
- Modificar: `packages/fleetops/CLAUDE.md` (só o servidor da Tabela de km; as telas entram no plano do console)
- Modificar: `api/app/Http/Controllers/Entregas/CLAUDE.md`
- Modificar: `docs/superpowers/specs/2026-10-21-entrega-junto-e-multiplicador-design.md`

Os `CLAUDE.md` por pasta ainda não estão versionados nesta máquina (`git status` mostra `??`). Edite e **não** os inclua no commit se ainda estiverem fora do git; avise o Edgard no fim.

- [ ] **Passo 1: `api/app/Support/Entregas/Financeiro/CLAUDE.md`**, nova seção depois de "## Regras":

```markdown
## Entrega junto e multiplicador

Spec `docs/superpowers/specs/2026-10-21-entrega-junto-e-multiplicador-design.md`; planos `docs/superpowers/plans/2026-10-21-entrega-junto-e-multiplicador-*.md`.

- **Segunda tabela** (`Setting` `company.<uuid>.entregas.faixas_juntas`, mesmo formato da `entregas.faixas`; vazia = desligada): vale para o pedido que o motoboy pega enquanto já leva outro (posição ≥ 2), igual da 2ª entrega em diante. Congela com o km (colunas `junto_*` da `entregas_valores_pedido`).
- **Multiplicadores** (`entregas_multiplicadores`, `Financeiro\Multiplicadores`, `MultiplicadoresController`): período com nome, ×motoboy e ×loja (1,00 a 5,00), início e fim (até 7 dias, sem sobreposição). Vale para o pedido **atribuído** em `[início, fim)`. Agendado muda tudo ou é apagado; em andamento só muda o fim ou encerra; encerrado não muda.
- **Atribuição** (`ObservadorDoValorDaEntrega` → `CalculoEntregas::registrarAtribuicao`): quando o `driver_assigned_uuid` muda, grava motoboy, instante, posição (1 + pedidos em aberto dele, critério do mapa: não encerrados e atualizados nas últimas 12 h) e cópias do período. Fixa depois disso; troca de motoboy recalcula; sem motoboy apaga. Pedido com corrida lançada não muda.
- **Valor final** = faixa (normal ou segunda tabela) × fator, arredondado a 2 casas, sempre pelo `ValorDaEntrega::final` (a linha guarda as partes, não o final).
- **Prévia** (`valorDoPedido($pedido, $motoboyUuid)`): antes da atribuição, a posição para quem pergunta e o multiplicador de agora, sem gravar. O push de alarme não sabe o destinatário: mostra a prévia sem posição.
- **Reserva**: pedido concluído sem a atribuição do motoboy dele (escapou do observador) é reconstituído pelo aceite (`started_at`, senão `dispatched_at`) e pelos horários dos outros pedidos dele (`AtribuicaoDoValor::posicoesPorHorarios`), só quando havia segunda tabela ou período no instante.
- **Detalhes da corrida**: `junto` (posição) quando a segunda tabela foi usada, nas duas contas; `multiplicador` `{nome, fator}` só na conta cujo fator ≠ 1. O motoboy nunca vê o fator da loja.
- **Testes:** `scripts/teste-php/entrega-junto.php` e `financeiro-multiplicadores.php`.
```

- [ ] **Passo 2: `api/app/Http/Controllers/Entregas/CLAUDE.md`**, na linha do card de aceitar (a que cita `GET v1/entregas/motoboy/pedidos/{id}/valor`), acrescente: "A resposta traz `junto` (a posição 2, 3… quando pagou pela segunda tabela, senão null) e `multiplicador` (`{nome, fator}` do motoboy ou null), e o valor já é a prévia para este motoboy antes da atribuição (ver "Entrega junto e multiplicador" em `api/app/Support/Entregas/Financeiro/CLAUDE.md`)." Lembre que esta seção é copiada no `CLAUDE.md` do `entregas-navigator`: o plano do app atualiza a cópia.

- [ ] **Passo 3: Spec**, na seção "Prévia antes do aceite", troque o item do push de alarme por:

```markdown
- **Push de alarme** (`CartaoDoAlarme::doPedido`): o `AvisosDoMotoboy` não recebe o destinatário, então o cartão do
  alarme mostra a prévia sem posição (tabela normal × multiplicador ativo). O card de aceitar do app mostra a prévia
  com a posição do motoboy.
```

E na seção "Servidor", troque `PATCH financeiro/multiplicadores/{uuid}` por `PUT financeiro/multiplicadores/{uuid}`.

- [ ] **Passo 4: Commit**

```bash
git add docs/superpowers/specs/2026-10-21-entrega-junto-e-multiplicador-design.md
git commit -m "docs: entrega junto e multiplicador (alarme sem posição, PUT do período)"
```

---

## Implantação (quem roda é o Edgard, na VPS)

1. Merge do ramo na `main` e push (o push não faz deploy).
2. `cd ~/entregas && bash deploy/atualizar.sh api` (API, queue e scheduler; o `deploy.sh` roda as duas migrations no banco principal e no sandbox).
3. Nada muda até alguém preencher a segunda tabela ou criar um período: as telas vêm no plano do console.
