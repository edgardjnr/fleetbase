# Saque Pix pelo Asaas — Plano 2: console Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** a central configura e acompanha o saque Pix dos motoboys pelo console: o card "Saque Pix (Asaas)" em Financeiro → Configurações (conectar a conta, validação de saque, ligar e limites), a tela nova "Saques Pix", o interruptor de bloqueio e a carência no extrato do motoboy, o selo do pagamento de saque, o selo "saque bloqueado" na lista de motoboys e os avisos do saque no painel.

**Architecture:** tudo no engine do Fleet-Ops (`packages/fleetops/addon`), no padrão das telas do Financeiro: rotas `financeiro.*`, controllers com `ember-concurrency`, contas em funções puras testadas no Node (`utils/financeiro-saque-pix.js` e `utils/financeiro.js`) e textos em `fleet-ops.ui.financeiro.*`. O card das Configurações é um componente com um subcomponente por passo. Uma mudança pequena na API (Task 1, **depende do plano 1**) acrescenta o que as telas antigas precisam e o plano 1 não traz: o bloco `saque` no extrato do console, `saque_bloqueado` na lista de motoboys e o IP de saída na conexão.

**Tech Stack:** Ember (Glimmer components, ember-concurrency, ember-intl), Tailwind com a paleta Entregas, PHP 8.2 / Laravel (só a Task 1), testes em `scripts/teste-portal` (Node) e `scripts/teste-php` (php-wasm).

**Spec:** `docs/superpowers/specs/2026-10-26-saque-pix-asaas-design.md` (seção "Telas → Console"). **Plano 1 (API):** `docs/superpowers/plans/2026-10-26-saque-pix-asaas-api.md`, de onde vêm as rotas e os formatos (Task 4: `ConexaoAsaas::publico`; Task 7: `Saques::publico`; Task 11: `saque` no pagamento e `atencao`; Task 12: `SaquePixController`).

---

## Antes de começar

- **Onde:** no worktree do plano 1, `C:/tmp/saque` (ramo `saque-pix`), **depois** das Tasks 1 a 14 do plano 1 commitadas. A Task 1 deste plano mexe em arquivos que o plano 1 cria (`ConexaoAsaas.php`, `stubs-saque.php`) e muda (`FinanceiroController.php`). As Tasks 2 em diante são só do console e não chamam código PHP, mas as telas só funcionam com a API do plano 1. Todos os caminhos abaixo são relativos à raiz do worktree (`/c/tmp/saque` no Git Bash).
- **Dependências do console no worktree** (o i18n-check, o parse do Babel e o build leem `console/node_modules`, que o worktree não tem):

```bash
cd /c/tmp/saque/console && npx pnpm@11.0.9 install --frozen-lockfile
```

O `console/pnpm-workspace.yaml` liga os `packages/*` do próprio worktree. O `node_modules` é ignorado pelo git: nunca o adicione.

- **Leia antes:** `CLAUDE.md` (raiz), `packages/CLAUDE.md` (tradução), `packages/fleetops/CLAUDE.md` (Financeiro: modais, `perform`, datas, YAML com ": "), `console/CLAUDE.md` (paleta, celular, build) e `api/app/Support/Entregas/Saques/CLAUDE.md` (criado no plano 1).
- **Comandos:**
  - Testes PHP: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/<arquivo>.php` (termina com `FALHAS: 0`).
  - Sintaxe PHP: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs <arquivos.php>`.
  - Testes do console: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/<arquivo>.test.mjs` (termina com `# fail 0`).
  - Tradução: `node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit $?"` (precisa de `exit 0`).
  - Parse do Babel dos JS alterados (`packages/CLAUDE.md`). Cole esta função no shell uma vez por sessão e chame `parse_js <arquivos.js>`:

```bash
parse_js() { node -e '
const fs = require("fs"), path = require("path");
const base = "console/node_modules/.pnpm";
const pasta = fs.readdirSync(base).filter((d) => d.startsWith("@babel+parser@7")).sort().pop();
const { parse } = require(path.resolve(base, pasta, "node_modules/@babel/parser"));
for (const arquivo of process.argv.slice(1)) {
  parse(fs.readFileSync(arquivo, "utf8"), { sourceType: "module", plugins: ["decorators-legacy", "classProperties"] });
  console.log("ok", arquivo);
}' "$@"; }
```

- **Regras que valem em todo o plano:**
  - Texto de tela sempre por chave de tradução, nas duas línguas (`en-us.yaml` e `pt-br.yaml`). Em pt-BR, "Operação", nunca "Fleet-Ops". Texto com ": " no meio vai entre aspas no YAML.
  - Visual igual ao das telas do Financeiro: cartões `rounded-lg border border-gray-200 dark:border-gray-700 p-4`, `ContentPanel` com `@wrapperClass="bordered-classic"`, avisos `bg-yellow-50 text-yellow-800` / `bg-red-50 text-red-800`, links com as mesmas classes `text-blue-600 dark:text-blue-400` das outras telas (a paleta do console troca o `blue` pelo laranja da marca; não use cor fixa em CSS).
  - Hora vinda do servidor em ISO: mostre **a hora escrita no ISO** (`quandoDoIso`), nunca `new Date(iso)` convertida para o fuso do navegador.
  - Modais e confirmações: o `confirm` do `modalsManager.confirm` é `async` e fecha o modal ao terminar (padrão de `controllers/financeiro/fatura.js`). O helper `perform` passa o evento do clique como último argumento: as tasks deste plano não leem argumentos de clique.
  - Nunca mostrar, guardar em `localStorage` ou mandar para log a chave de API do Asaas, a senha ou o token além da tela em que foram digitados ou gerados.
  - Commit ao fim de cada tarefa, conferindo antes `git rev-parse --show-toplevel` (precisa ser `C:/tmp/saque`), com a linha final `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Sem push (o Edgard decide).

## Decisões deste plano (o spec não dizia)

1. **"Aba Saques" = tela própria no menu do Financeiro** (`financeiro.saques`, "Saques Pix", entre Motoboys e Lojas e faturas). Cada item do Financeiro já é uma rota do menu; as abas internas (`TabNavigation`) só existem em Lojas e faturas.
2. **Admin da organização:** o console reconhece o papel pelo `currentUser.roleName === 'Administrator'` (o nome gravado do papel, o mesmo do `AdminDaCentral`). Ele vê no menu só "Saques Pix" e "Configurações" do Financeiro; nas Configurações, só o card do saque (o resto da tela chama rotas que a API ainda nega a ele). A guarda da rota `financeiro` passa a deixar esse usuário entrar só nessas duas telas. A proteção de verdade continua na API (403).
3. **Carência no extrato:** em vez de cruzar com a lista da tela Saques, o extrato do console (`GET financeiro/motoboys/{id}/extrato`) ganha o bloco `saque` `{bloqueado, liberado_em, alterada_por}` (Task 1), com o **nome** de quem trocou a chave ("chave trocada por Fulano"), que a lista da tela Saques não traz.
4. **IP fixo da VPS:** a API passa a mandar `ip_de_saida` na conexão (env novo `ENTREGAS_IP_DE_SAIDA`, só no `stack.env` do Edgard). Sem ele, o card pede o IP a quem administra o Entregas. O IP aparece só para o admin da central conectada.
5. **Comprovante do Asaas:** o link só aparece se começar com `https://` (`linkSeguro`).
6. **Pagamento de saque sem botão Estornar** no extrato (o servidor já recusa com 422; a tela não oferece).
7. **Teste da validação:** a tela relê `GET financeiro/saque-pix` a cada 3 s por até 60 s depois do `POST …/teste`; "confirmada" = `validacao_confirmada_em` preenchido.

## Mapa dos arquivos

| Arquivo | Responsabilidade |
|---|---|
| `api/app/Support/Entregas/Saques/SaqueNoConsole.php` (novo) | bloco `saque` do extrato do console e motoboys bloqueados |
| `api/app/Http/Controllers/Entregas/FinanceiroController.php` | `extratoDoMotoboy` + `saque`; `motoboys` + `saque_bloqueado` |
| `api/app/Support/Entregas/Saques/ConexaoAsaas.php` | `ip_de_saida` no formato público |
| `api/config/services.php`, `deploy/docker-stack.yml`, `deploy/stack.env.example` | `ENTREGAS_IP_DE_SAIDA` |
| `scripts/teste-php/saque-console.php` (novo), `scripts/teste-php/financeiro-console.php` | testes da Task 1 |
| `packages/fleetops/addon/utils/financeiro.js` | `quandoDoIso`, `podeEstornar` sem saque, itens `saque_*` do "Atenção" |
| `packages/fleetops/addon/utils/financeiro-saque-pix.js` (novo) | funções puras do saque no console |
| `scripts/teste-portal/financeiro.test.mjs`, `financeiro-saque-pix.test.mjs` (novo) | testes das funções puras |
| `packages/fleetops/translations/pt-br.yaml`, `en-us.yaml`; `console/translations/pt-br.yaml`, `en-us.yaml` | textos |
| `packages/fleetops/addon/templates/financeiro/painel.hbs` | link dos avisos do saque |
| `packages/fleetops/addon/routes.js`, `routes/financeiro/saques.js`, `controllers/financeiro/saques.js`, `templates/financeiro/saques.hbs` (novos, menos o `routes.js`) | tela Saques Pix |
| `packages/fleetops/addon/routes/financeiro.js`, `routes/financeiro/index.js`, `components/layout/fleet-ops-sidebar.js`, `console/app/components/doca-celular.js` | acesso do admin da organização e menus |
| `packages/fleetops/addon/components/financeiro/saque-pix.{js,hbs}` e `saque-pix/{conta,validacao,ligar}.{js,hbs}` (novos) + reexports em `packages/fleetops/app/components/financeiro/` | card das Configurações |
| `packages/fleetops/addon/controllers/financeiro/configuracoes.js`, `templates/financeiro/configuracoes.hbs` | card na tela |
| `packages/fleetops/addon/controllers/financeiro/motoboy.js`, `templates/financeiro/motoboy.hbs` | interruptor, carência, selo do pagamento |
| `packages/fleetops/addon/templates/financeiro/motoboys.hbs` | selo "saque bloqueado" |
| `packages/fleetops/CLAUDE.md`, `api/app/Support/Entregas/Saques/CLAUDE.md`, `console/CLAUDE.md`, `docs/historico.md`, o spec | documentação |

---

### Task 1: API para as telas antigas (depende do plano 1)

**Files:**
- Create: `api/app/Support/Entregas/Saques/SaqueNoConsole.php`
- Modify: `api/app/Http/Controllers/Entregas/FinanceiroController.php` (`use`, `motoboys`, `extratoDoMotoboy`)
- Modify: `api/app/Support/Entregas/Saques/ConexaoAsaas.php` (`publico`)
- Modify: `api/config/services.php`, `deploy/docker-stack.yml`, `deploy/stack.env.example`
- Modify: `scripts/teste-php/financeiro-console.php` (um `confere` da lista de motoboys)
- Test: `scripts/teste-php/saque-console.php`

- [ ] **Step 1: Escrever o teste que falha**

Crie `scripts/teste-php/saque-console.php`:

```php
<?php

// Saque Pix nas telas antigas do Financeiro (plano 2, console): o bloco `saque` do extrato do motoboy no console
// (bloqueio e carência depois da troca da chave Pix, com o nome de quem trocou), o `saque_bloqueado` da lista de motoboys
// e o IP de saída na conexão pública (lista de IPs do Asaas). Depende do plano 1 (stubs-saque.php, Saques, ConexaoAsaas).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/saque-console.php

require __DIR__ . '/stubs-saque.php';

use App\Http\Controllers\Entregas\FinanceiroController;
use App\Support\Entregas\Financeiro\ChavePix;
use App\Support\Entregas\Saques\ConexaoAsaas;
use App\Support\Entregas\Saques\SaqueNoConsole;
use App\Support\Entregas\Saques\Saques;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Teste\Banco;
use Teste\Config;
use Teste\Relogio;
use Teste\UsuarioDoSaque;

function comoAdminDoSistema(): void
{
    session(['company' => 'empresa-1', 'user' => 'user-admin']);
    \Fleetbase\Support\Auth::$usuario = new UsuarioDoSaque('user-admin', 'admin');
}

function extratoDoJoao(): array
{
    comoAdminDoSistema();

    return (new FinanceiroController())->extratoDoMotoboy(new Request(), 'driver_1')->dados;
}

/** A lista de motoboys do console, por public_id. */
function listaDeMotoboys(): array
{
    comoAdminDoSistema();
    $lista = (new FinanceiroController())->motoboys(new Request())->dados['motoboys'] ?? [];

    return array_column($lista, null, 'id');
}

echo '== SaqueNoConsole::doMotoboy' . PHP_EOL;
centralComSaque();
Banco::inserir('users', ['uuid' => 'user-admin', 'name' => 'Edgard'], false);
$bloco = SaqueNoConsole::doMotoboy('empresa-1', 'driver-1');
confere($bloco === ['bloqueado' => false, 'liberado_em' => null, 'alterada_por' => null], 'liberado, sem carência: ' . json_encode($bloco));
Saques::bloquear('empresa-1', 'driver-1', true);
confere(SaqueNoConsole::doMotoboy('empresa-1', 'driver-1')['bloqueado'] === true, 'bloqueado');
Saques::bloquear('empresa-1', 'driver-1', false);
Relogio::$agora = '2026-10-27 11:00:00';
ChavePix::salvar('empresa-1', 'driver-1', '12345678901', 'cpf', 'user-admin');
$bloco = SaqueNoConsole::doMotoboy('empresa-1', 'driver-1');
confere($bloco === ['bloqueado' => false, 'liberado_em' => '2026-10-28T11:00:00-03:00', 'alterada_por' => 'Edgard'], 'troca da chave: carência até 28/10 11:00, trocada por Edgard: ' . json_encode($bloco, JSON_UNESCAPED_UNICODE));
Relogio::$agora = '2026-10-28 11:00:01';
$bloco = SaqueNoConsole::doMotoboy('empresa-1', 'driver-1');
confere($bloco['liberado_em'] === null && $bloco['alterada_por'] === null, 'passadas as 24 h: sem carência e sem o nome');
confere(SaqueNoConsole::doMotoboy('empresa-1', 'driver-9') === ['bloqueado' => false, 'liberado_em' => null, 'alterada_por' => null], 'motoboy sem linha no financeiro: liberado');
Config::$valores['services.entregas.saque'] = null;
confere(SaqueNoConsole::doMotoboy('empresa-1', 'driver-1') === null, 'ENTREGAS_SAQUE desligado: null');
Config::$valores['services.entregas.saque'] = '1';
DB::table('entregas_asaas_conexoes')->where('company_uuid', 'empresa-1')->delete();
confere(SaqueNoConsole::doMotoboy('empresa-1', 'driver-1') === null, 'central sem conta Asaas: null');

echo '== SaqueNoConsole::bloqueados e a lista de motoboys' . PHP_EOL;
centralComSaque();
motoboyComSaldo('driver-2', 'Maria Souza');
confere(SaqueNoConsole::bloqueados('empresa-1') === [], 'ninguém bloqueado');
Saques::bloquear('empresa-1', 'driver-2', true);
confere(SaqueNoConsole::bloqueados('empresa-1') === ['driver-2' => true], 'Maria bloqueada');
$lista = listaDeMotoboys();
confere(($lista['driver_2']['saque_bloqueado'] ?? null) === true && ($lista['driver_1']['saque_bloqueado'] ?? null) === false, 'lista do console: saque_bloqueado por motoboy: ' . json_encode(array_column($lista, 'saque_bloqueado', 'id')));
Config::$valores['services.entregas.saque'] = null;
confere(SaqueNoConsole::bloqueados('empresa-1') === [] && (listaDeMotoboys()['driver_2']['saque_bloqueado'] ?? null) === false, 'desligado: ninguém aparece bloqueado');

echo '== Extrato do motoboy no console' . PHP_EOL;
centralComSaque();
$extrato = extratoDoJoao();
confere(($extrato['saque'] ?? null) === ['bloqueado' => false, 'liberado_em' => null, 'alterada_por' => null], 'extrato: bloco saque: ' . json_encode($extrato['saque'] ?? null));
confere(($extrato['conta']['pix'] ?? null) === ['chave' => '11999994321', 'tipo' => 'telefone'], 'a conta continua igual');
Config::$valores['services.entregas.saque'] = null;
confere(!array_key_exists('saque', extratoDoJoao()), 'desligado: sem a chave saque (o console esconde o interruptor)');

echo '== IP de saída na conexão' . PHP_EOL;
centralComSaque();
Config::$valores['services.entregas.ip_de_saida'] = ' 203.0.113.10 ';
$publico = ConexaoAsaas::publico(ConexaoAsaas::daEmpresa('empresa-1'), 'empresa-1');
confere(($publico['ip_de_saida'] ?? null) === '203.0.113.10', 'conectada: o IP, sem espaços');
Config::$valores['services.entregas.ip_de_saida'] = null;
$publico = ConexaoAsaas::publico(ConexaoAsaas::daEmpresa('empresa-1'), 'empresa-1');
confere(array_key_exists('ip_de_saida', $publico) && $publico['ip_de_saida'] === null, 'sem IP configurado: null');
confere(ConexaoAsaas::publico(null, 'empresa-1') === ['conectada' => false, 'ligada' => true, 'cofre' => true], 'sem conexão: nada do IP');

echo '== Configuração e stack' . PHP_EOL;
confere(str_contains(file_get_contents('/repo/api/config/services.php'), "'ip_de_saida' => env('ENTREGAS_IP_DE_SAIDA')"), 'services.entregas.ip_de_saida');
confere(str_contains(file_get_contents('/repo/deploy/docker-stack.yml'), 'ENTREGAS_IP_DE_SAIDA: ${ENTREGAS_IP_DE_SAIDA:-}'), 'stack: ENTREGAS_IP_DE_SAIDA no x-api-env');
confere(preg_match('/^ENTREGAS_IP_DE_SAIDA=\r?$/m', file_get_contents('/repo/deploy/stack.env.example')) === 1, 'modelo do stack.env');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/saque-console.php`
Expected: erro "Class App\Support\Entregas\Saques\SaqueNoConsole not found".

- [ ] **Step 3: Criar `SaqueNoConsole`**

Crie `api/app/Support/Entregas/Saques/SaqueNoConsole.php`:

```php
<?php

namespace App\Support\Entregas\Saques;

use App\Support\Entregas\Financeiro\ChavePix;
use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: o saque Pix nas telas do Financeiro que já existiam (plano 2, console). O bloco `saque` do
 * extrato do motoboy (interruptor de bloqueio e carência depois da troca da chave Pix, com o nome de quem trocou) e os
 * motoboys bloqueados (selo na lista). Só com o saque ligado no servidor (ENTREGAS_SAQUE) e a central com a conta Asaas
 * conectada; fora disso, nada (o console esconde o interruptor e o selo). API antes da migration: nada.
 */
final class SaqueNoConsole
{
    /** {bloqueado, liberado_em (ISO, só durante a carência), alterada_por (nome de quem trocou a chave, idem)} ou null. */
    public static function doMotoboy(string $companyUuid, string $driverUuid): ?array
    {
        if (!Saque::ligada()) {
            return null;
        }
        try {
            if (!ConexaoAsaas::daEmpresa($companyUuid)) {
                return null;
            }
            $linha    = Saques::linhaDoMotoboy($companyUuid, $driverUuid);
            $liberado = Saques::liberadoEm($linha);
            $por      = $liberado !== null && ($linha->pix_alterada_por ?? null)
                ? DB::table('users')->where('uuid', (string) $linha->pix_alterada_por)->value('name')
                : null;
        } catch (\Throwable $e) {
            return null;
        }

        return [
            'bloqueado'    => (bool) ($linha->saque_bloqueado ?? false),
            'liberado_em'  => Saque::iso($liberado, $companyUuid),
            'alterada_por' => $por !== null && $por !== '' ? (string) $por : null,
        ];
    }

    /** Os motoboys com o saque bloqueado: [driver_uuid => true]. Desligado ou sem a coluna: []. */
    public static function bloqueados(string $companyUuid): array
    {
        if (!Saque::ligada()) {
            return [];
        }
        try {
            $uuids = DB::table(ChavePix::TABELA)->where('company_uuid', $companyUuid)->where('saque_bloqueado', true)->pluck('driver_uuid')->all();
        } catch (\Throwable $e) {
            return [];
        }

        return array_fill_keys(array_map('strval', $uuids), true);
    }
}
```

- [ ] **Step 4: `FinanceiroController`**

Em `api/app/Http/Controllers/Entregas/FinanceiroController.php`:

1. Nos `use`, logo depois de `use App\Support\Entregas\Saques\Saque;` (acrescentado pelo plano 1), acrescente:

```php
use App\Support\Entregas\Saques\SaqueNoConsole;
```

2. No `motoboys`, troque:

```php
        $comPix  = ChavePix::motoboysComChave($empresa);
```

por:

```php
        $comPix  = ChavePix::motoboysComChave($empresa);
        // saque Pix pelo app: o selo "saque bloqueado" da lista (vazio com o saque desligado no servidor)
        $bloqueados = SaqueNoConsole::bloqueados($empresa);
```

3. Ainda no `motoboys`, troque:

```php
                'excluido'         => $excluido,
            ];
```

por:

```php
                'excluido'         => $excluido,
                'saque_bloqueado'  => isset($bloqueados[$uuid]),
            ];
```

4. No docblock do `motoboys`, troque `se tem chave Pix e \`excluido\`` por `se tem chave Pix, \`excluido\` e \`saque_bloqueado\` (saque Pix pelo app)`.

5. No `extratoDoMotoboy`, troque a última linha:

```php
        return response()->json(ExtratoDaConta::montar($this->empresa(), Financeiro::CONTA_MOTOBOY, (string) $motoboy->uuid, $inicio, $fim, false, $tipo ?: null));
```

por:

```php
        $extrato = ExtratoDaConta::montar($this->empresa(), Financeiro::CONTA_MOTOBOY, (string) $motoboy->uuid, $inicio, $fim, false, $tipo ?: null);
        // saque Pix pelo app: o interruptor de bloqueio e a carência da troca da chave (só com o saque ligado na central)
        if ($saque = SaqueNoConsole::doMotoboy($this->empresa(), (string) $motoboy->uuid)) {
            $extrato['saque'] = $saque;
        }

        return response()->json($extrato);
```

6. No docblock do `extratoDoMotoboy`, troque `/** GET financeiro/motoboys/{id}/extrato?inicio&fim&tipo (período opcional; o console sempre manda). */` por `/** GET financeiro/motoboys/{id}/extrato?inicio&fim&tipo (período opcional; o console sempre manda) + \`saque\` (SaqueNoConsole). */`.

- [ ] **Step 5: IP de saída na conexão**

Em `api/app/Support/Entregas/Saques/ConexaoAsaas.php`, no `publico`, troque:

```php
            'limites'                 => static::limites($conexao),
        ];
```

por:

```php
            'limites'                 => static::limites($conexao),
            // o IP fixo do servidor (ENTREGAS_IP_DE_SAIDA), para a central pôr na lista de IPs do Asaas; null sem configurar
            'ip_de_saida'             => trim((string) config('services.entregas.ip_de_saida')) ?: null,
        ];
```

Em `api/config/services.php`, logo depois da linha `'asaas_url' => env('ASAAS_API_URL', 'https://api.asaas.com/v3'),` (do plano 1), acrescente:

```php
        // IP fixo de saída do servidor (o da VPS), mostrado no card do saque Pix do console para a central pôr na lista de
        // IPs autorizados do Asaas (recomendação). Vazio = o console pede o IP a quem administra o Entregas
        'ip_de_saida' => env('ENTREGAS_IP_DE_SAIDA'),
```

Em `deploy/docker-stack.yml`, no `x-api-env`, logo depois de `  ASAAS_API_URL: ${ASAAS_API_URL:-https://api.asaas.com/v3}`, acrescente:

```yaml
  # IP fixo de saída da VPS, mostrado no card "Saque Pix (Asaas)" do console (lista de IPs do Asaas). Vazio = o card pede
  # o IP a quem administra o Entregas
  ENTREGAS_IP_DE_SAIDA: ${ENTREGAS_IP_DE_SAIDA:-}
```

Em `deploy/stack.env.example`, logo depois da linha `ASAAS_API_URL=`, acrescente:

```
# IP fixo de saída da VPS (o que o Asaas vê). Aparece no card "Saque Pix (Asaas)" do console, para a central cadastrar na
# lista de IPs autorizados do Asaas. Vazio = o card pede o IP a quem administra o Entregas.
ENTREGAS_IP_DE_SAIDA=
```

- [ ] **Step 6: Ajustar o teste antigo da lista de motoboys**

Em `scripts/teste-php/financeiro-console.php`, no `confere` do João na seção "== Motoboys (lista do console)", troque o trecho `'tem_pix' => false, 'excluido' => false],` por `'tem_pix' => false, 'excluido' => false, 'saque_bloqueado' => false],`. Nenhum outro caso muda.

- [ ] **Step 7: Rodar e ver passar**

```bash
for t in saque-console financeiro-console saque-conexao saque-controllers saque-implantacao imports-dos-providers; do
  echo "== $t"; PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php | tail -1
done
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/Saques/SaqueNoConsole.php api/app/Support/Entregas/Saques/ConexaoAsaas.php api/app/Http/Controllers/Entregas/FinanceiroController.php api/config/services.php
```

Expected: seis `FALHAS: 0` e a sintaxe sai com 0.

- [ ] **Step 8: Commit**

```bash
git rev-parse --show-toplevel
git add api/app/Support/Entregas/Saques/SaqueNoConsole.php api/app/Support/Entregas/Saques/ConexaoAsaas.php api/app/Http/Controllers/Entregas/FinanceiroController.php api/config/services.php deploy/docker-stack.yml deploy/stack.env.example scripts/teste-php/saque-console.php scripts/teste-php/financeiro-console.php
git commit -m "Saque Pix no console (API): bloco saque no extrato, saque_bloqueado na lista e IP de saída na conexão

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `utils/financeiro.js` (hora do ISO, estorno, avisos do saque)

**Files:**
- Modify: `packages/fleetops/addon/utils/financeiro.js`
- Test: `scripts/teste-portal/financeiro.test.mjs`

- [ ] **Step 1: Escrever os testes que falham**

Em `scripts/teste-portal/financeiro.test.mjs`:

1. Na lista do `import { … } from '../../packages/fleetops/addon/utils/financeiro.js';`, acrescente `quandoDoIso,` depois de `podeEstornar,`.

2. No fim do `test('pode estornar', …)`, antes do `});`, acrescente:

```js
    // saque Pix pelo app: o servidor recusa o estorno à mão (em andamento, concluído ou já estornado sozinho)
    assert.equal(podeEstornar({ tipo: 'pagamento', estornado: false, pagamento: { id: 'p2', estornado: false, saque: { situacao: 'concluido', comprovante_url: null } } }), false, 'pagamento de saque não se estorna');
```

3. No fim do arquivo, acrescente:

```js
test('hora escrita no ISO, sem converter o fuso', () => {
    assert.deepEqual(quandoDoIso('2026-10-27T14:30:00-03:00'), { data: '27/10/2026', dia: '27/10', hora: '14:30' });
    assert.deepEqual(quandoDoIso('2026-10-27T23:59:00+00:00'), { data: '27/10/2026', dia: '27/10', hora: '23:59' }, 'nunca converte para o fuso do navegador');
    assert.equal(quandoDoIso(null), null);
    assert.equal(quandoDoIso('27/10/2026'), null);
});

test('itens de atenção do saque Pix abrem a tela Saques Pix com o filtro', () => {
    const f = formatadores('pt-BR');
    const falhou = itemDeAtencao({ tipo: 'saque_falhou', saque: 'u1', motoboy: 'João Silva', valor: 10, motivo: 'Saldo insuficiente.' }, f);
    assert.equal(falhou.rota, 'financeiro.saques');
    assert.equal(falhou.modelo, null, 'sem modelo: a rota não tem segmento dinâmico');
    assert.deepEqual(falhou.consulta, { situacao: 'falhou' });
    assert.equal(falhou.chave, 'fleet-ops.ui.financeiro.painel.atencao.saque_falhou');
    assert.equal(falhou.params.motoboy, 'João Silva');
    assert.equal(falhou.params.motivo, 'Saldo insuficiente.');
    assert.match(falhou.params.valor, /10,00/);
    assert.match(falhou.cor, /red/);

    const parado = itemDeAtencao({ tipo: 'saque_parado', saque: 'u2', motoboy: 'Maria Souza', valor: 20, desde: '2026-10-27T10:00:00-03:00' }, f);
    assert.deepEqual(parado.consulta, { situacao: '' }, 'parado pode estar enviando ou processando: sem filtro');
    assert.equal(parado.params.hora, '10:00');
    assert.equal(parado.params.dia, '27/10');
    assert.match(parado.cor, /yellow/);

    const semNada = itemDeAtencao({ tipo: 'saque_falhou', motoboy: null, valor: 5, motivo: null }, f);
    assert.equal(semNada.params.motivo, '—', 'sem motivo: travessão');
    assert.equal(semNada.params.motoboy, '');
    falhou.consulta.situacao = 'mexido';
    assert.deepEqual(itemDeAtencao({ tipo: 'saque_falhou', valor: 1 }, f).consulta, { situacao: 'falhou' }, 'cada item leva a própria cópia do filtro');
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/financeiro.test.mjs`
Expected: FAIL (`quandoDoIso` não é exportado; `pagamento de saque não se estorna` falha).

- [ ] **Step 3: `quandoDoIso`**

Em `packages/fleetops/addon/utils/financeiro.js`, logo depois da função `dataDoDia` (termina antes de `const FORMATADORES = new Map();`), acrescente:

```js
/**
 * ISO do servidor ('2026-10-27T14:30:00-03:00') → { data: '27/10/2026', dia: '27/10', hora: '14:30' }, pela hora ESCRITA
 * no ISO (o servidor já manda no fuso da empresa; new Date() converteria para o fuso do navegador). null se inválido.
 */
export function quandoDoIso(iso) {
    const partes = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(String(iso ?? ''));

    return partes ? { data: `${partes[3]}/${partes[2]}/${partes[1]}`, dia: `${partes[3]}/${partes[2]}`, hora: `${partes[4]}:${partes[5]}` } : null;
}
```

- [ ] **Step 4: `podeEstornar`**

Na função `podeEstornar`, troque:

```js
    if (lancamento.fatura?.numero) {
        return false;
    }
```

por:

```js
    if (lancamento.fatura?.numero) {
        return false;
    }
    // saque Pix pelo app: o servidor não deixa estornar à mão (em andamento, a rotina resolve; concluído, só com ajuste;
    // falho, já foi estornado sozinho)
    if (lancamento.pagamento?.saque) {
        return false;
    }
```

- [ ] **Step 5: Itens do "Atenção"**

Troque o bloco inteiro que começa em `const ROTA_DO_PEDIDO ='operations.orders.index.details';` e vai até o fim da função `itemDeAtencao` (o `}` antes de `const dataBr = (data) => {`) por:

```js
const ROTA_DO_PEDIDO = 'operations.orders.index.details';
const ROTA_DA_FATURA = 'financeiro.fatura';
const ROTA_DOS_SAQUES = 'financeiro.saques';
const ATENCAO = {
    fatura_vencida: { icone: 'circle-exclamation', cor: 'text-red-600 dark:text-red-400', rota: ROTA_DA_FATURA },
    rascunho: { icone: 'file-pen', cor: 'text-yellow-600 dark:text-yellow-400', rota: ROTA_DA_FATURA },
    email_nao_enviado: { icone: 'envelope', cor: 'text-yellow-600 dark:text-yellow-400', rota: ROTA_DA_FATURA },
    sem_loja: { icone: 'store-slash', cor: 'text-gray-500 dark:text-gray-400', rota: ROTA_DO_PEDIDO },
    sem_valor: { icone: 'ruler', cor: 'text-gray-500 dark:text-gray-400', rota: ROTA_DO_PEDIDO },
    // saque Pix pelo app (Saques::atencao no servidor): abrem a tela Saques Pix já filtrada
    saque_falhou: { icone: 'circle-exclamation', cor: 'text-red-600 dark:text-red-400', rota: ROTA_DOS_SAQUES, consulta: { situacao: 'falhou' } },
    saque_parado: { icone: 'hourglass-half', cor: 'text-yellow-600 dark:text-yellow-400', rota: ROTA_DOS_SAQUES, consulta: { situacao: '' } },
};

/**
 * Item da lista "Atenção" do painel → { tipo, icone, cor, chave, params, rota, modelo, consulta? } para o template (rota
 * relativa ao engine; fatura → financeiro.fatura com o uuid, pedido → detalhes do pedido com o public_id, saque Pix →
 * financeiro.saques sem modelo e com o filtro em `consulta`). Tipo desconhecido: null. `f` = formatadores(idioma).
 */
export function itemDeAtencao(item, f) {
    const config = ATENCAO[item?.tipo];
    if (!config) {
        return null;
    }
    const base = { tipo: item.tipo, icone: config.icone, cor: config.cor, chave: `${PREFIXO}.painel.atencao.${item.tipo}`, rota: config.rota };
    if (config.rota === ROTA_DOS_SAQUES) {
        const desde = quandoDoIso(item.desde);
        return {
            ...base,
            modelo: null,
            consulta: { ...config.consulta },
            params: { valor: f.valor(item.valor), motoboy: item.motoboy ?? '', motivo: item.motivo || '—', hora: desde?.hora ?? '', dia: desde?.dia ?? '' },
        };
    }
    const ehPedido = config.rota === ROTA_DO_PEDIDO;
    // sem o id não há link (o LinkTo sem o modelo quebra a tela inteira): o item sai da lista
    const modelo = ehPedido ? item.pedido : item.fatura;
    if (!modelo) {
        return null;
    }
    const params = ehPedido
        ? { numero: item.numero || item.pedido }
        : { numero: item.numero ?? '', loja: item.loja ?? '', valor: f.valor(item.valor), vencimento: f.dia(item.vencimento) };

    return { ...base, params, modelo };
}
```

- [ ] **Step 6: Rodar e ver passar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/financeiro.test.mjs scripts/teste-portal/financeiro-faturas.test.mjs scripts/teste-portal/financeiro-multiplicadores.test.mjs scripts/teste-portal/corridas-do-sistema.test.mjs`
Expected: `# fail 0` (os três últimos importam de `financeiro.js` e continuam passando).

Run: `parse_js packages/fleetops/addon/utils/financeiro.js`
Expected: `ok packages/fleetops/addon/utils/financeiro.js`.

- [ ] **Step 7: Commit**

```bash
git rev-parse --show-toplevel
git add packages/fleetops/addon/utils/financeiro.js scripts/teste-portal/financeiro.test.mjs
git commit -m "Financeiro (console): hora escrita no ISO, pagamento de saque sem estorno e avisos do saque no painel

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `utils/financeiro-saque-pix.js` (funções puras do saque no console)

**Files:**
- Create: `packages/fleetops/addon/utils/financeiro-saque-pix.js`
- Test: `scripts/teste-portal/financeiro-saque-pix.test.mjs`

- [ ] **Step 1: Escrever o teste que falha**

Crie `scripts/teste-portal/financeiro-saque-pix.test.mjs`:

```js
// Saque Pix no console (packages/fleetops/addon/utils/financeiro-saque-pix.js): quem configura, rotas liberadas ao admin
// da organização, etapas do card, teste da validação, limites, situação dos saques, carência e links do comprovante.
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/financeiro-saque-pix.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    API_DO_SAQUE_PIX,
    ESPERA_DO_TESTE_MS,
    INTERVALO_DO_TESTE_MS,
    SITUACOES_DO_SAQUE,
    avisoDeCarencia,
    chaveDaSituacaoDoSaque,
    consultaDosSaques,
    ehAdminDaCentral,
    etapasDoSaque,
    limitesParaEdicao,
    linhaDoMotoboyComPix,
    linhaDoSaque,
    linkSeguro,
    resultadoDoTeste,
    rotaDoAdminDaCentral,
    segundosRestantes,
    seloDoSaque,
    validarLimites,
} from '../../packages/fleetops/addon/utils/financeiro-saque-pix.js';

const S = 'fleet-ops.ui.financeiro.saque-pix';

test('rota da API', () => {
    assert.equal(API_DO_SAQUE_PIX, 'entregas/financeiro/saque-pix');
});

test('quem configura: admin do sistema ou papel Administrator na organização', () => {
    assert.equal(ehAdminDaCentral({ isAdmin: true, roleName: 'Operador' }), true);
    assert.equal(ehAdminDaCentral({ isAdmin: false, roleName: 'Administrator' }), true);
    assert.equal(ehAdminDaCentral({ isAdmin: false, roleName: 'Administrador' }), false, 'vale o nome gravado do papel (em inglês)');
    assert.equal(ehAdminDaCentral({ isAdmin: undefined, roleName: undefined }), false);
    assert.equal(ehAdminDaCentral(null), false);
});

test('rotas liberadas ao admin da organização: Saques Pix e Configurações', () => {
    for (const nome of ['console.fleet-ops.financeiro.saques', 'console.fleet-ops.financeiro.configuracoes', 'console.fleet-ops.financeiro.index', 'console.fleet-ops.financeiro', 'financeiro.saques']) {
        assert.equal(rotaDoAdminDaCentral(nome), true, nome);
    }
    for (const nome of ['console.fleet-ops.financeiro.painel', 'console.fleet-ops.financeiro.motoboy', 'console.fleet-ops.financeiro.corridas-do-sistema', 'console.fleet-ops.operations.orders.index', '', null]) {
        assert.equal(rotaDoAdminDaCentral(nome), false, String(nome));
    }
});

test('etapas do card: conectar, token, validação e ligar', () => {
    assert.deepEqual(etapasDoSaque(null), { conectada: false, tokenGerado: false, validada: false, ligado: false, podeConectar: true, podeTestar: false, podeLigar: false });
    assert.equal(etapasDoSaque({ conectada: false, cofre: false }).podeConectar, false, 'sem o secret no servidor: não conecta');
    const conectada = { conectada: true, cofre: true, token_gerado: false, validacao_confirmada_em: null, saque_ligado: false };
    assert.deepEqual(etapasDoSaque(conectada), { conectada: true, tokenGerado: false, validada: false, ligado: false, podeConectar: true, podeTestar: false, podeLigar: false });
    assert.equal(etapasDoSaque({ ...conectada, token_gerado: true }).podeTestar, true);
    const validada = { ...conectada, token_gerado: true, validacao_confirmada_em: '2026-10-27T10:00:05-03:00' };
    assert.equal(etapasDoSaque(validada).podeLigar, true);
    assert.equal(etapasDoSaque({ ...validada, saque_ligado: true }).ligado, true);
    assert.equal(etapasDoSaque({ ...conectada, saque_ligado: true }).ligado, false, 'ligado sem validação não conta');
});

test('teste da validação: confirmada, esperando até 60 s e sem resposta', () => {
    const t0 = 1000000;
    assert.equal(ESPERA_DO_TESTE_MS, 60000);
    assert.equal(INTERVALO_DO_TESTE_MS, 3000);
    assert.equal(resultadoDoTeste({ validacao_confirmada_em: null }, null, t0), null, 'nenhum teste pedido nesta tela');
    assert.equal(resultadoDoTeste({ validacao_confirmada_em: null }, t0, t0 + 3000), 'esperando');
    assert.equal(resultadoDoTeste({ validacao_confirmada_em: null }, t0, t0 + ESPERA_DO_TESTE_MS - 1), 'esperando');
    assert.equal(resultadoDoTeste({ validacao_confirmada_em: null }, t0, t0 + ESPERA_DO_TESTE_MS), 'sem-resposta');
    assert.equal(resultadoDoTeste({ validacao_confirmada_em: '2026-10-27T10:00:05-03:00' }, t0, t0 + 999999), 'confirmada');
    assert.equal(resultadoDoTeste({ validacao_confirmada_em: '2026-10-27T10:00:05-03:00' }, null, t0), 'confirmada', 'já confirmada antes de abrir a tela');
    assert.equal(segundosRestantes(t0, t0), 60);
    assert.equal(segundosRestantes(t0, t0 + 59001), 1);
    assert.equal(segundosRestantes(t0, t0 + 70000), 0);
});

test('limites: edição e validação iguais às do servidor', () => {
    assert.deepEqual(limitesParaEdicao({ minimo: 10, maximo_dia: 500, saques_dia: 3, taxa: 0 }), { minimo: '10,00', maximo_dia: '500,00', saques_dia: '3', taxa: '0,00' });
    assert.deepEqual(limitesParaEdicao(undefined), { minimo: '10,00', maximo_dia: '500,00', saques_dia: '3', taxa: '0,00' }, 'sem conexão: os padrões');
    assert.deepEqual(validarLimites({ minimo: '20', maximo_dia: '1.500', saques_dia: '2', taxa: '1,5' }), { erro: null, corpo: { minimo: '20.00', maximo_dia: '1500.00', saques_dia: 2, taxa: '1.50' } });
    const erro = (campos) => validarLimites({ minimo: '10', maximo_dia: '500', saques_dia: '3', taxa: '0', ...campos }).erro;
    assert.equal(erro({}), null);
    assert.equal(erro({ minimo: 'abc' }), 'minimo-invalido');
    assert.equal(erro({ minimo: '0,50' }), 'minimo-baixo');
    assert.equal(erro({ maximo_dia: '' }), 'maximo-invalido');
    assert.equal(erro({ maximo_dia: '9,99' }), 'maximo-menor');
    assert.equal(erro({ maximo_dia: '10' }), null, 'máximo igual ao mínimo vale');
    assert.equal(erro({ saques_dia: '0' }), 'saques-dia');
    assert.equal(erro({ saques_dia: '21' }), 'saques-dia');
    assert.equal(erro({ saques_dia: '2,5' }), 'saques-dia');
    assert.equal(erro({ saques_dia: 4 }), null, 'número também vale');
    assert.equal(erro({ taxa: 'x' }), 'taxa-invalida');
    assert.equal(erro({ taxa: '-1' }), 'taxa-faixa');
    assert.equal(erro({ taxa: '10,01', minimo: '20' }), 'taxa-faixa');
    assert.equal(erro({ taxa: '10' }), 'taxa-maior', 'taxa igual ao mínimo');
    assert.equal(erro({ taxa: '9,99' }), null);
    assert.equal(validarLimites({ minimo: 'x' }).corpo, null, 'com erro, nada para mandar');
});

test('situação do saque: chave, selo e linha da tabela', () => {
    assert.deepEqual(SITUACOES_DO_SAQUE, ['enviando', 'processando', 'concluido', 'falhou']);
    assert.equal(chaveDaSituacaoDoSaque('concluido'), `${S}.situacoes.concluido`);
    assert.equal(chaveDaSituacaoDoSaque('xyz'), `${S}.situacoes.desconhecida`);
    assert.deepEqual(seloDoSaque({ situacao: 'enviando' }), { status: 'warning', chave: `${S}.situacoes.enviando` });
    assert.deepEqual(seloDoSaque({ situacao: 'processando' }), { status: 'warning', chave: `${S}.situacoes.processando` });
    assert.deepEqual(seloDoSaque({ situacao: 'concluido' }), { status: 'success', chave: `${S}.situacoes.concluido` });
    assert.deepEqual(seloDoSaque({ situacao: 'falhou' }), { status: 'error', chave: `${S}.situacoes.falhou` });
    assert.deepEqual(seloDoSaque(null), { status: 'info', chave: `${S}.situacoes.desconhecida` });

    const linha = linhaDoSaque({
        id: 'u1', valor: 50, taxa: 1.5, situacao: 'falhou', motivo: 'Saldo insuficiente.', comprovante_url: null,
        criado_em: '2026-10-27T10:00:00-03:00', motoboy: { id: 'driver_1', nome: 'João Silva' },
    });
    assert.deepEqual(linha, {
        id: 'u1', quando: '27/10 10:00', motoboyId: 'driver_1', motoboy: 'João Silva', valor: 50, taxa: 1.5,
        status: 'error', chave: `${S}.situacoes.falhou`, motivo: 'Saldo insuficiente.', comprovante: null,
    });
    assert.equal(linhaDoSaque({ situacao: 'concluido', motivo: 'x' }).motivo, '', 'motivo só no que falhou');
    assert.equal(linhaDoSaque({ situacao: 'concluido', comprovante_url: 'https://www.asaas.com/comprovantes/1' }).comprovante, 'https://www.asaas.com/comprovantes/1');
    assert.equal(linhaDoSaque({ motoboy: null }).motoboyId, null);
    assert.equal(linhaDoSaque({ criado_em: null }).quando, '');
});

test('link do comprovante: só https', () => {
    assert.equal(linkSeguro('https://www.asaas.com/c/1'), 'https://www.asaas.com/c/1');
    assert.equal(linkSeguro('  https://x.y/z '), 'https://x.y/z');
    assert.equal(linkSeguro('javascript:alert(1)'), null);
    assert.equal(linkSeguro('http://x.y'), null);
    assert.equal(linkSeguro(null), null);
});

test('filtro da tela Saques Pix', () => {
    assert.deepEqual(consultaDosSaques('falhou'), { situacao: 'falhou' });
    assert.deepEqual(consultaDosSaques(''), {});
    assert.deepEqual(consultaDosSaques('lixo'), {});
});

test('carência depois da troca da chave Pix', () => {
    assert.equal(avisoDeCarencia(null), null);
    assert.equal(avisoDeCarencia({ bloqueado: false, liberado_em: null, alterada_por: null }), null);
    assert.deepEqual(avisoDeCarencia({ liberado_em: '2026-10-27T14:30:00-03:00', alterada_por: 'Fulano' }), { chave: `${S}.carencia-por`, params: { hora: '14:30', dia: '27/10', nome: 'Fulano' } });
    assert.deepEqual(avisoDeCarencia({ liberado_em: '2026-10-27T14:30:00-03:00', alterada_por: null }), { chave: `${S}.carencia`, params: { hora: '14:30', dia: '27/10', nome: '' } });
});

test('motoboy com chave Pix na tela Saques Pix', () => {
    assert.deepEqual(
        linhaDoMotoboyComPix({ id: 'driver_1', nome: 'João', chave: '*******4321', tipo: 'telefone', bloqueado: true, liberado_em: '2026-10-28T11:00:00-03:00' }),
        { id: 'driver_1', nome: 'João', chave: '*******4321', chaveDoTipo: 'fleet-ops.ui.financeiro.pix.tipos.telefone', bloqueado: true, carencia: { hora: '11:00', dia: '28/10' } }
    );
    assert.equal(linhaDoMotoboyComPix({ id: 'd', bloqueado: false, liberado_em: null }).carencia, null);
    assert.equal(linhaDoMotoboyComPix({ id: 'd', tipo: 'banco' }).chaveDoTipo, null, 'tipo desconhecido: sem rótulo');
    assert.equal(linhaDoMotoboyComPix({ id: 'd' }).bloqueado, false);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/financeiro-saque-pix.test.mjs`
Expected: FAIL com "Cannot find module …/financeiro-saque-pix.js".

- [ ] **Step 3: Implementar**

Crie `packages/fleetops/addon/utils/financeiro-saque-pix.js`:

```js
/**
 * Entregas RestaurantePro: funções puras do saque Pix do motoboy no console (card "Saque Pix (Asaas)" das Configurações,
 * tela Saques Pix, extrato e lista de motoboys). Os dados vêm de int/v1/entregas/financeiro/saque-pix, …/saques e do
 * extrato (api/app/Http/Controllers/Entregas/SaquePixController.php e FinanceiroController.php; formatos em
 * docs/superpowers/plans/2026-10-26-saque-pix-asaas-api.md). Testado no Node
 * (scripts/teste-portal/financeiro-saque-pix.test.mjs).
 */
import { TIPOS_CHAVE_PIX, numeroDigitado, quandoDoIso, valorParaEnviar } from './financeiro';

const PREFIXO = 'fleet-ops.ui.financeiro.saque-pix';

/** Rota da API (namespace int/v1 do fetch): GET/PUT; + /conexao, /token, /teste. */
export const API_DO_SAQUE_PIX = 'entregas/financeiro/saque-pix';

export const SITUACOES_DO_SAQUE = ['enviando', 'processando', 'concluido', 'falhou'];

/** O teste da validação espera o Asaas chamar o Entregas por até 60 s, relendo a conexão a cada 3 s. */
export const ESPERA_DO_TESTE_MS = 60000;
export const INTERVALO_DO_TESTE_MS = 3000;

/** O nome gravado do papel de administrador da organização (o mesmo do AdminDaCentral no servidor). */
export const PAPEL_ADMIN_DA_CENTRAL = 'Administrator';

// telas do Financeiro abertas ao admin da organização (o resto segue só com o admin do sistema, como a API)
const ROTAS_DO_ADMIN_DA_CENTRAL = ['financeiro', 'financeiro.index', 'financeiro.saques', 'financeiro.configuracoes'];

const STATUS_DO_SELO = { enviando: 'warning', processando: 'warning', concluido: 'success', falhou: 'error' };

const PADROES = { minimo: 10, maximo_dia: 500, saques_dia: 3, taxa: 0 };

const centavos = (valor) => Math.round(valor * 100);
const reaisParaEdicao = (valor) => (Number(valor) || 0).toFixed(2).replace('.', ',');

/** Admin do sistema (`isAdmin`) ou papel Administrator na organização da sessão (`roleName`): configura o saque Pix. */
export function ehAdminDaCentral(usuario) {
    return usuario?.isAdmin === true || usuario?.roleName === PAPEL_ADMIN_DA_CENTRAL;
}

/** A rota (nome curto ou com o prefixo do console) é uma das telas do Financeiro abertas ao admin da organização. */
export function rotaDoAdminDaCentral(nome) {
    const rota = String(nome ?? '');

    return rota !== '' && ROTAS_DO_ADMIN_DA_CENTRAL.some((liberada) => rota === liberada || rota.endsWith(`.${liberada}`));
}

/** Em que pé está o card (conexão no formato ConexaoAsaas::publico; null = ainda não carregou). */
export function etapasDoSaque(conexao) {
    const conectada = conexao?.conectada === true;
    const tokenGerado = conectada && conexao.token_gerado === true;
    const validada = conectada && Boolean(conexao.validacao_confirmada_em);

    return {
        conectada,
        tokenGerado,
        validada,
        ligado: validada && conexao.saque_ligado === true,
        podeConectar: conexao?.cofre !== false,
        podeTestar: tokenGerado,
        podeLigar: validada,
    };
}

/**
 * Resultado do teste da validação: 'confirmada' (validacao_confirmada_em preenchido), 'esperando' (até 60 s depois de
 * pedir), 'sem-resposta' (passou de 60 s) ou null (nenhum teste pedido nesta tela e nada confirmado).
 */
export function resultadoDoTeste(conexao, comecouEm, agora) {
    if (conexao?.validacao_confirmada_em) {
        return 'confirmada';
    }
    if (!comecouEm) {
        return null;
    }

    return agora - comecouEm >= ESPERA_DO_TESTE_MS ? 'sem-resposta' : 'esperando';
}

/** Segundos que faltam da espera do teste (0 depois de 60 s). */
export function segundosRestantes(comecouEm, agora) {
    return Math.max(0, Math.ceil((ESPERA_DO_TESTE_MS - (agora - comecouEm)) / 1000));
}

/** Limites do servidor → campos do formulário ("10,00"; saques por dia em texto). Sem limites, os padrões. */
export function limitesParaEdicao(limites) {
    const atuais = { ...PADROES, ...(limites ?? {}) };

    return {
        minimo: reaisParaEdicao(atuais.minimo),
        maximo_dia: reaisParaEdicao(atuais.maximo_dia),
        saques_dia: String(atuais.saques_dia),
        taxa: reaisParaEdicao(atuais.taxa),
    };
}

/**
 * As regras do servidor (ConexaoAsaas::configurar): mínimo ≥ R$ 1,00; máximo do dia ≥ mínimo; saques por dia de 1 a 20;
 * taxa de R$ 0 a R$ 10 e menor que o mínimo. → { erro: código (chave em saque-pix.erros) | null, corpo: o PUT | null }.
 */
export function validarLimites({ minimo, maximo_dia, saques_dia, taxa } = {}) {
    const min = numeroDigitado(minimo);
    const max = numeroDigitado(maximo_dia);
    const tx = numeroDigitado(taxa);
    const texto = String(saques_dia ?? '').trim();
    const quantos = /^\d{1,2}$/.test(texto) ? Number(texto) : NaN;
    const falha = (erro) => ({ erro, corpo: null });

    if (!Number.isFinite(min)) return falha('minimo-invalido');
    if (centavos(min) < 100) return falha('minimo-baixo');
    if (!Number.isFinite(max)) return falha('maximo-invalido');
    if (centavos(max) < centavos(min)) return falha('maximo-menor');
    if (!Number.isInteger(quantos) || quantos < 1 || quantos > 20) return falha('saques-dia');
    if (!Number.isFinite(tx)) return falha('taxa-invalida');
    if (centavos(tx) < 0 || centavos(tx) > 1000) return falha('taxa-faixa');
    if (centavos(tx) >= centavos(min)) return falha('taxa-maior');

    return { erro: null, corpo: { minimo: valorParaEnviar(minimo), maximo_dia: valorParaEnviar(maximo_dia), saques_dia: quantos, taxa: valorParaEnviar(taxa) } };
}

/** Chave i18n da situação do saque. */
export function chaveDaSituacaoDoSaque(situacao) {
    return `${PREFIXO}.situacoes.${SITUACOES_DO_SAQUE.includes(situacao) ? situacao : 'desconhecida'}`;
}

/** Selo da situação: { status do Badge, chave i18n }. */
export function seloDoSaque(saque) {
    return { status: STATUS_DO_SELO[saque?.situacao] ?? 'info', chave: chaveDaSituacaoDoSaque(saque?.situacao) };
}

/** O link do comprovante só se for https (o href nunca recebe javascript: ou http:). */
export function linkSeguro(url) {
    const texto = typeof url === 'string' ? url.trim() : '';

    return /^https:\/\//i.test(texto) ? texto : null;
}

/** Query do GET financeiro/saques: só a situação conhecida. */
export function consultaDosSaques(situacao) {
    return SITUACOES_DO_SAQUE.includes(situacao) ? { situacao } : {};
}

/** Saque da API (Saques::publico + motoboy) → linha da tabela da tela Saques Pix. */
export function linhaDoSaque(saque) {
    const quando = quandoDoIso(saque?.criado_em);

    return {
        id: saque?.id,
        quando: quando ? `${quando.dia} ${quando.hora}` : '',
        motoboyId: saque?.motoboy?.id ?? null,
        motoboy: saque?.motoboy?.nome ?? '',
        valor: Number(saque?.valor) || 0,
        taxa: Number(saque?.taxa) || 0,
        ...seloDoSaque(saque),
        motivo: saque?.situacao === 'falhou' ? saque?.motivo ?? '' : '',
        comprovante: linkSeguro(saque?.comprovante_url),
    };
}

/** Motoboy com chave Pix (lista `motoboys` do GET financeiro/saques) → linha com o rótulo do tipo e a carência. */
export function linhaDoMotoboyComPix(motoboy) {
    const quando = quandoDoIso(motoboy?.liberado_em);

    return {
        id: motoboy?.id,
        nome: motoboy?.nome ?? '',
        chave: motoboy?.chave ?? '',
        chaveDoTipo: TIPOS_CHAVE_PIX.includes(motoboy?.tipo) ? `fleet-ops.ui.financeiro.pix.tipos.${motoboy.tipo}` : null,
        bloqueado: motoboy?.bloqueado === true,
        carencia: quando ? { hora: quando.hora, dia: quando.dia } : null,
    };
}

/** Aviso de carência do extrato (bloco `saque` do servidor) → { chave, params } ou null fora da carência. */
export function avisoDeCarencia(saque) {
    const quando = quandoDoIso(saque?.liberado_em);
    if (!quando) {
        return null;
    }
    const nome = saque.alterada_por ?? '';

    return { chave: `${PREFIXO}.${nome ? 'carencia-por' : 'carencia'}`, params: { hora: quando.hora, dia: quando.dia, nome } };
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/financeiro-saque-pix.test.mjs`
Expected: `# fail 0`.

Run: `parse_js packages/fleetops/addon/utils/financeiro-saque-pix.js`
Expected: `ok …`.

- [ ] **Step 5: Commit**

```bash
git rev-parse --show-toplevel
git add packages/fleetops/addon/utils/financeiro-saque-pix.js scripts/teste-portal/financeiro-saque-pix.test.mjs
git commit -m "Saque Pix no console: funções puras (admin da central, etapas, teste da validação, limites, situação, carência)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Traduções

**Files:**
- Modify: `packages/fleetops/translations/pt-br.yaml`, `packages/fleetops/translations/en-us.yaml`
- Modify: `console/translations/pt-br.yaml`, `console/translations/en-us.yaml`

Atenção à indentação: no `pt-br.yaml` do fleetops o `financeiro:` tem 4 espaços e os filhos 6; no `en-us.yaml`, 8 e 12.

- [ ] **Step 1: Menu (fleetops)**

Em `packages/fleetops/translations/pt-br.yaml`, troque `        motoboys: Motoboys` (a do bloco `financeiro.menu`, a única com 8 espaços) por:

```yaml
        motoboys: Motoboys
        saques: Saques Pix
```

Em `packages/fleetops/translations/en-us.yaml`, troque `                motoboys: Drivers` (bloco `financeiro.menu`, a única com 16 espaços) por:

```yaml
                motoboys: Drivers
                saques: Pix withdrawals
```

- [ ] **Step 2: Avisos do painel (fleetops)**

Em `pt-br.yaml`, troque a linha `          sem_valor: 'Pedido {numero} concluído sem valor (sem km ou sem faixas)'` por:

```yaml
          sem_valor: 'Pedido {numero} concluído sem valor (sem km ou sem faixas)'
          saque_falhou: 'Saque de {valor} de {motoboy} não foi concluído: {motivo}'
          saque_parado: 'Saque de {valor} de {motoboy} em andamento desde {hora} de {dia}'
```

Em `en-us.yaml`, troque a linha `                    sem_valor: 'Order {numero} completed without a value (no km or no bands)'` por:

```yaml
                    sem_valor: 'Order {numero} completed without a value (no km or no bands)'
                    saque_falhou: 'Withdrawal of {valor} by {motoboy} was not completed: {motivo}'
                    saque_parado: 'Withdrawal of {valor} by {motoboy} in progress since {hora} on {dia}'
```

- [ ] **Step 3: Bloco `saque-pix` em pt-BR (fleetops)**

Em `pt-br.yaml`, logo depois da linha `        ciclo-ajuda: Vale para as lojas sem ciclo próprio. A fatura nasce como rascunho no dia seguinte ao fechamento.` (última do `financeiro.configuracoes`, antes de `    order-form:`), acrescente:

```yaml
      saque-pix:
        titulo: Saque Pix (Asaas)
        intro: 'O motoboy saca o saldo pelo app e o dinheiro cai na chave Pix dele, saindo da conta Asaas da central. Configure em três passos.'
        desligado-no-servidor: 'O saque Pix está desligado no servidor (ENTREGAS_SAQUE). Dá para configurar, mas nenhum motoboy saca até ele ser ligado.'
        sem-cofre: 'Saque Pix indisponível: chave de criptografia não configurada no servidor. Fale com quem administra o Entregas.'
        passo: 'Passo {numero}'
        feito: Feito
        conta:
          titulo: Conectar a conta Asaas
          ajuda: 'Use uma conta Asaas só para os saques dos motoboys (conta própria ou subconta). A validação de saque do passo 2 vale para toda transferência por API dessa conta.'
          chave: Chave de API do Asaas
          chave-ajuda: 'No Asaas: Integrações → Chaves de API. A chave fica guardada cifrada e não aparece de novo.'
          senha: Sua senha do console
          senha-ajuda: Conectar, trocar a chave e desconectar pedem a sua senha.
          conectar: Conectar
          conectada: Conta Asaas conectada.
          nome: Conta
          documento: CPF/CNPJ
          wallet: Carteira (wallet)
          conectada-em: 'Conectada em {quando}'
          trocar: Trocar a chave
          trocar-ajuda: 'A chave nova precisa ser da mesma conta Asaas. Para mudar de conta, desconecte e conecte de novo.'
          salvar-troca: Salvar a chave nova
          chave-trocada: Chave de API trocada.
          desconectar: Desconectar
          desconectar-ajuda: 'Desconectar apaga a chave e o token, desliga o saque e pede o teste da validação de novo. Com saque em andamento, espere ele terminar.'
          confirmar-desconexao: Desconectar a conta
          desconectada: Conta Asaas desconectada.
          cancelar: Cancelar
          preencha: Preencha a chave de API e a sua senha.
          preencha-senha: Digite a sua senha.
        validacao:
          titulo: Validação de saque
          ajuda: 'Antes de cada transferência pela API, o Asaas pergunta ao Entregas se pode pagar. O Entregas só aprova o saque que ele mesmo criou: uma chave do Asaas vazada não tira dinheiro.'
          conecte-antes: Conecte a conta Asaas primeiro.
          url: URL da validação
          token: Token de autenticação
          gerar: Gerar token
          gerar-outro: Gerar outro token
          gerar-outro-titulo: Gerar outro token?
          gerar-outro-texto: O token atual deixa de valer, o saque é desligado e a validação precisa ser testada de novo.
          token-gerado: Token gerado. Copie agora e cole no Asaas.
          token-uma-vez: 'Copie agora: o token não aparece de novo.'
          token-existe: Token já gerado. Ele não aparece de novo; se você perdeu, gere outro.
          passos-titulo: No Asaas da central
          passo-1: Abra Integrações → Mecanismos de segurança.
          passo-2: Ligue a validação de saque, cole a URL e o token acima e salve.
          passo-3: Volte aqui e teste.
          ips-titulo: 'Recomendado: lista de IPs'
          ips-com-ip: 'Na mesma tela, cadastre o IP fixo do servidor do Entregas na lista de IPs autorizados: {ip}. Assim a chave de API só funciona a partir do nosso servidor.'
          ips-sem-ip: Na mesma tela, cadastre o IP fixo do servidor do Entregas na lista de IPs autorizados (peça o IP a quem administra o Entregas). Assim a chave de API só funciona a partir do nosso servidor.
          teste-titulo: Testar a validação
          teste-ajuda: O Entregas pede ao Asaas uma transferência de R$ 1,00 para uma chave Pix sua, e a validação do Entregas recusa. Se a validação não estiver configurada no Asaas, o R$ 1,00 cai na sua conta.
          teste-tipo: Tipo da sua chave Pix
          teste-chave: Sua chave Pix
          testar: Testar validação
          esperando: 'Esperando o Asaas chamar o Entregas… {segundos} s'
          confirmada: 'Recusada pelo Entregas ✓ Validação confirmada em {quando}.'
          sem-resposta: 'O Asaas não chamou o Entregas: confira a URL e o token no Asaas e teste de novo.'
          gere-o-token: Gere o token e configure o Asaas antes de testar.
        ligar:
          titulo: Ligar o saque e definir os limites
          travado: Teste a validação de saque (passo 2) para poder ligar.
          interruptor: Saque Pix pelo app
          ligado: Saque ligado. Os motoboys com chave Pix já podem sacar.
          desligado: Saque desligado.
          minimo: Mínimo por saque (R$)
          maximo-dia: Máximo por dia, por motoboy (R$)
          saques-dia: Saques por dia, por motoboy
          taxa: Taxa por saque (R$)
          taxa-ajuda: 'Sai do saldo do motoboy, à parte do valor sacado. Use para repassar a tarifa do Asaas; R$ 0,00 = a central paga.'
          salvar: Salvar limites
          salvos: Limites salvos.
        erros:
          minimo-invalido: Mínimo por saque inválido.
          minimo-baixo: O mínimo por saque é de pelo menos R$ 1,00.
          maximo-invalido: Máximo por dia inválido.
          maximo-menor: O máximo por dia precisa ser maior ou igual ao mínimo por saque.
          saques-dia: 'Saques por dia: de 1 a 20.'
          taxa-invalida: Taxa inválida.
          taxa-faixa: A taxa vai de R$ 0,00 a R$ 10,00.
          taxa-maior: A taxa precisa ser menor que o mínimo por saque.
        situacoes:
          enviando: Enviando
          processando: Processando
          concluido: Concluído
          falhou: Falhou
          desconhecida: Outra
        saques:
          titulo: Saques Pix
          ajuda: Saques pedidos pelos motoboys no app nos últimos 30 dias, do mais novo para o mais antigo.
          filtro: Situação
          todas: Todas
          quando: Data e hora
          motoboy: Motoboy
          valor: Valor
          taxa: Taxa
          situacao: Situação
          comprovante: Comprovante
          ver-comprovante: Ver no Asaas
          vazio: Nenhum saque nos últimos 30 dias.
          configurar: Configurar o saque Pix
        motoboys:
          titulo: Motoboys com chave Pix
          ajuda: Bloqueie o saque de um motoboy em caso de celular roubado ou acerto pendente. A chave Pix é cadastrada no extrato do motoboy.
          liberado: Saque liberado
          bloqueado: Saque bloqueado
          vazio: Nenhum motoboy com chave Pix.
          salvo-liberado: 'Saque de {nome} liberado.'
          salvo-bloqueado: 'Saque de {nome} bloqueado.'
        carencia: 'Saque liberado às {hora} de {dia}'
        carencia-por: 'Saque liberado às {hora} de {dia} · chave trocada por {nome}'
        selo-bloqueado: saque bloqueado
        selo-bloqueado-ajuda: O motoboy não consegue sacar pelo app. Libere no extrato ou na tela Saques Pix.
        selo-app: 'Saque Pix · app'
        comprovante-asaas: comprovante do Asaas
```

- [ ] **Step 4: Bloco `saque-pix` em inglês (fleetops)**

Em `en-us.yaml`, logo depois da linha `                ciclo-ajuda: Applies to stores without their own cycle. The invoice starts as a draft the day after closing.` (antes de `        order-form:`), acrescente:

```yaml
            saque-pix:
                titulo: Pix withdrawal (Asaas)
                intro: 'Drivers withdraw their balance in the app and the money goes to their Pix key, out of the dispatch center Asaas account. Set it up in three steps.'
                desligado-no-servidor: 'Pix withdrawal is turned off on the server (ENTREGAS_SAQUE). You can set it up, but no driver can withdraw until it is turned on.'
                sem-cofre: 'Pix withdrawal unavailable: the encryption key is not configured on the server. Contact whoever runs Entregas.'
                passo: 'Step {numero}'
                feito: Done
                conta:
                    titulo: Connect the Asaas account
                    ajuda: 'Use an Asaas account only for driver withdrawals (own account or subaccount). The withdrawal validation in step 2 applies to every API transfer from that account.'
                    chave: Asaas API key
                    chave-ajuda: 'In Asaas: Integrations → API keys. The key is stored encrypted and is never shown again.'
                    senha: Your console password
                    senha-ajuda: Connecting, changing the key and disconnecting ask for your password.
                    conectar: Connect
                    conectada: Asaas account connected.
                    nome: Account
                    documento: CPF/CNPJ
                    wallet: Wallet
                    conectada-em: 'Connected on {quando}'
                    trocar: Change the key
                    trocar-ajuda: 'The new key must belong to the same Asaas account. To switch accounts, disconnect and connect again.'
                    salvar-troca: Save the new key
                    chave-trocada: API key changed.
                    desconectar: Disconnect
                    desconectar-ajuda: 'Disconnecting deletes the key and the token, turns withdrawals off and asks for the validation test again. If a withdrawal is in progress, wait for it to finish.'
                    confirmar-desconexao: Disconnect the account
                    desconectada: Asaas account disconnected.
                    cancelar: Cancel
                    preencha: Fill in the API key and your password.
                    preencha-senha: Type your password.
                validacao:
                    titulo: Withdrawal validation
                    ajuda: 'Before each API transfer, Asaas asks Entregas whether it may pay. Entregas only approves withdrawals it created itself: a leaked Asaas key cannot take money out.'
                    conecte-antes: Connect the Asaas account first.
                    url: Validation URL
                    token: Authentication token
                    gerar: Generate token
                    gerar-outro: Generate another token
                    gerar-outro-titulo: Generate another token?
                    gerar-outro-texto: The current token stops working, withdrawals are turned off and the validation must be tested again.
                    token-gerado: Token generated. Copy it now and paste it in Asaas.
                    token-uma-vez: 'Copy it now: the token is not shown again.'
                    token-existe: Token already generated. It is not shown again; if you lost it, generate another one.
                    passos-titulo: In the dispatch center Asaas account
                    passo-1: Open Integrations → Security mechanisms.
                    passo-2: Turn on withdrawal validation, paste the URL and the token above and save.
                    passo-3: Come back here and test it.
                    ips-titulo: 'Recommended: IP list'
                    ips-com-ip: 'On the same screen, add the Entregas server fixed IP to the authorized IP list: {ip}. This way the API key only works from our server.'
                    ips-sem-ip: On the same screen, add the Entregas server fixed IP to the authorized IP list (ask whoever runs Entregas for the IP). This way the API key only works from our server.
                    teste-titulo: Test the validation
                    teste-ajuda: Entregas asks Asaas for a R$ 1.00 transfer to a Pix key of yours, and the Entregas validation refuses it. If the validation is not set up in Asaas, the R$ 1.00 goes to your account.
                    teste-tipo: Your Pix key type
                    teste-chave: Your Pix key
                    testar: Test validation
                    esperando: 'Waiting for Asaas to call Entregas… {segundos} s'
                    confirmada: 'Refused by Entregas ✓ Validation confirmed on {quando}.'
                    sem-resposta: 'Asaas did not call Entregas: check the URL and the token in Asaas and test again.'
                    gere-o-token: Generate the token and set up Asaas before testing.
                ligar:
                    titulo: Turn withdrawals on and set the limits
                    travado: Test the withdrawal validation (step 2) to turn it on.
                    interruptor: Pix withdrawal in the app
                    ligado: Withdrawals on. Drivers with a Pix key can withdraw now.
                    desligado: Withdrawals off.
                    minimo: Minimum per withdrawal (R$)
                    maximo-dia: Maximum per day, per driver (R$)
                    saques-dia: Withdrawals per day, per driver
                    taxa: Fee per withdrawal (R$)
                    taxa-ajuda: 'Taken from the driver balance, apart from the amount withdrawn. Use it to pass on the Asaas fee; R$ 0.00 = the dispatch center pays.'
                    salvar: Save limits
                    salvos: Limits saved.
                erros:
                    minimo-invalido: Invalid minimum per withdrawal.
                    minimo-baixo: The minimum per withdrawal is at least R$ 1.00.
                    maximo-invalido: Invalid maximum per day.
                    maximo-menor: The maximum per day must be greater than or equal to the minimum per withdrawal.
                    saques-dia: 'Withdrawals per day: from 1 to 20.'
                    taxa-invalida: Invalid fee.
                    taxa-faixa: The fee goes from R$ 0.00 to R$ 10.00.
                    taxa-maior: The fee must be lower than the minimum per withdrawal.
                situacoes:
                    enviando: Sending
                    processando: Processing
                    concluido: Completed
                    falhou: Failed
                    desconhecida: Other
                saques:
                    titulo: Pix withdrawals
                    ajuda: Withdrawals requested by drivers in the app in the last 30 days, newest first.
                    filtro: Status
                    todas: All
                    quando: Date and time
                    motoboy: Driver
                    valor: Amount
                    taxa: Fee
                    situacao: Status
                    comprovante: Receipt
                    ver-comprovante: View in Asaas
                    vazio: No withdrawals in the last 30 days.
                    configurar: Set up Pix withdrawal
                motoboys:
                    titulo: Drivers with a Pix key
                    ajuda: Block withdrawals for a driver if the phone was stolen or a settlement is pending. The Pix key is set in the driver statement.
                    liberado: Withdrawal allowed
                    bloqueado: Withdrawal blocked
                    vazio: No driver with a Pix key.
                    salvo-liberado: 'Withdrawal allowed for {nome}.'
                    salvo-bloqueado: 'Withdrawal blocked for {nome}.'
                carencia: 'Withdrawal allowed at {hora} on {dia}'
                carencia-por: 'Withdrawal allowed at {hora} on {dia} · key changed by {nome}'
                selo-bloqueado: withdrawal blocked
                selo-bloqueado-ajuda: The driver cannot withdraw in the app. Allow it in the statement or on the Pix withdrawals screen.
                selo-app: 'Pix withdrawal · app'
                comprovante-asaas: Asaas receipt
```

- [ ] **Step 5: Doca do celular (console)**

Em `console/translations/pt-br.yaml`, troque `        extrato-motoboys: Extrato dos motoboys` por:

```yaml
        extrato-motoboys: Extrato dos motoboys
        saques-pix: Saques Pix
```

Em `console/translations/en-us.yaml`, troque `        extrato-motoboys: Driver statements` por:

```yaml
        extrato-motoboys: Driver statements
        saques-pix: Pix withdrawals
```

- [ ] **Step 6: Conferir**

Run: `node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit $?"`
Expected: `exit 0` (os YAML abrem e as chaves das duas línguas batem).

Confira também que o YAML abriu como esperado (uma chave com ": " sem aspas viraria outro mapa):

```bash
node -e '
const fs = require("fs"), path = require("path");
const base = "console/node_modules/.pnpm";
const yaml = require(path.resolve(base, fs.readdirSync(base).filter((d) => d.startsWith("js-yaml@4")).sort().pop(), "node_modules/js-yaml"));
for (const [arquivo, raiz] of [["packages/fleetops/translations/pt-br.yaml", "fleet-ops"], ["packages/fleetops/translations/en-us.yaml", "fleet-ops"]]) {
  const s = yaml.load(fs.readFileSync(arquivo, "utf8"))[raiz].ui.financeiro["saque-pix"];
  if (typeof s.validacao["ips-titulo"] !== "string" || typeof s.erros["saques-dia"] !== "string" || typeof s.carencia !== "string") throw new Error(arquivo);
  console.log("ok", arquivo, Object.keys(s).length, "chaves no topo");
}'
```

Expected: duas linhas `ok … 19 chaves no topo`.

- [ ] **Step 7: Commit**

```bash
git rev-parse --show-toplevel
git add packages/fleetops/translations/pt-br.yaml packages/fleetops/translations/en-us.yaml console/translations/pt-br.yaml console/translations/en-us.yaml
git commit -m "Saque Pix no console: textos em pt-BR e inglês

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Painel do Financeiro, avisos do saque

**Files:**
- Modify: `packages/fleetops/addon/templates/financeiro/painel.hbs`

O controller do painel já passa cada item por `itemDeAtencao` (Task 2). Os itens do saque não têm modelo: o `LinkTo` com `@model` vazio quebraria a tela, por isso o template ganha o ramo com `@query`.

- [ ] **Step 1: Link dos avisos**

Em `packages/fleetops/addon/templates/financeiro/painel.hbs`, troque:

```hbs
                            <LinkTo @route={{item.rota}} @model={{item.modelo}} class="text-blue-600 dark:text-blue-400 hover:underline">
                                {{t item.chave numero=item.params.numero loja=item.params.loja valor=item.params.valor vencimento=item.params.vencimento}}
                            </LinkTo>
```

por:

```hbs
                            {{#if item.modelo}}
                                <LinkTo @route={{item.rota}} @model={{item.modelo}} class="text-blue-600 dark:text-blue-400 hover:underline">
                                    {{t item.chave numero=item.params.numero loja=item.params.loja valor=item.params.valor vencimento=item.params.vencimento}}
                                </LinkTo>
                            {{else}}
                                {{! saque Pix: a tela Saques Pix (rota sem modelo), com o filtro da situação }}
                                <LinkTo @route={{item.rota}} @query={{item.consulta}} class="text-blue-600 dark:text-blue-400 hover:underline">
                                    {{t item.chave valor=item.params.valor motoboy=item.params.motoboy motivo=item.params.motivo hora=item.params.hora dia=item.params.dia}}
                                </LinkTo>
                            {{/if}}
```

- [ ] **Step 2: Conferir**

Run: `node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit $?"`
Expected: `exit 0` (o template compila). A rota `financeiro.saques` entra na Task 6; até lá, o aviso só aparece com a API do plano 1 ligada, e o build da Task 13 confere o conjunto.

- [ ] **Step 3: Commit**

```bash
git rev-parse --show-toplevel
git add packages/fleetops/addon/templates/financeiro/painel.hbs
git commit -m "Painel do Financeiro: avisos de saque Pix que falhou ou parou, com link para a tela Saques Pix

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Tela Saques Pix

**Files:**
- Modify: `packages/fleetops/addon/routes.js`
- Create: `packages/fleetops/addon/routes/financeiro/saques.js`
- Create: `packages/fleetops/addon/controllers/financeiro/saques.js`
- Create: `packages/fleetops/addon/templates/financeiro/saques.hbs`

- [ ] **Step 1: Rota no mapa**

Em `packages/fleetops/addon/routes.js`, troque:

```js
        this.route('motoboy', { path: '/motoboys/:id' });
```

por:

```js
        this.route('motoboy', { path: '/motoboys/:id' });
        // saque Pix pelo app: lista dos saques e bloqueio por motoboy (também o admin da organização; ver routes/financeiro.js)
        this.route('saques');
```

- [ ] **Step 2: Route**

Crie `packages/fleetops/addon/routes/financeiro/saques.js`:

```js
import Route from '@ember/routing/route';

/**
 * Entregas RestaurantePro: Financeiro → Saques Pix (saques dos últimos 30 dias e os motoboys com chave Pix, com o
 * interruptor de bloqueio). Dados de `GET int/v1/entregas/financeiro/saques?situacao` (SaquePixController@saques). Aberta
 * ao admin do sistema e ao da organização (guarda em routes/financeiro.js). `?situacao=` vem dos avisos do painel.
 */
export default class FinanceiroSaquesRoute extends Route {
    queryParams = {
        situacao: { refreshModel: false },
    };

    setupController(controller) {
        super.setupController(...arguments);
        controller.carregar.perform();
    }

    resetController(controller, isExiting) {
        super.resetController(...arguments);
        if (isExiting) {
            controller.limpar();
        }
    }
}
```

- [ ] **Step 3: Controller**

Crie `packages/fleetops/addon/controllers/financeiro/saques.js`:

```js
import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { formatadores } from '../../utils/financeiro';
import { SITUACOES_DO_SAQUE, chaveDaSituacaoDoSaque, consultaDosSaques, linhaDoMotoboyComPix, linhaDoSaque } from '../../utils/financeiro-saque-pix';

const ENDPOINT = 'entregas/financeiro/saques';
const P = 'fleet-ops.ui.financeiro.saque-pix';

/**
 * Entregas RestaurantePro: tela Saques Pix do Financeiro. Os saques pedidos no app nos últimos 30 dias (mais novo
 * primeiro), com filtro por situação, motivo da falha e o comprovante do Asaas; e os motoboys com chave Pix, com o
 * interruptor "Saque liberado / bloqueado" (`PUT int/v1/entregas/financeiro/motoboys/{id}/saque {bloqueado}`). O nome do
 * motoboy abre o extrato só para o admin do sistema (o extrato ainda é negado ao admin da organização).
 */
export default class FinanceiroSaquesController extends Controller {
    @service fetch;
    @service intl;
    @service notifications;
    @service currentUser;
    @service hostRouter;

    queryParams = ['situacao'];

    @tracked situacao = '';
    @tracked saques = [];
    @tracked motoboys = [];
    @tracked carregado = false;

    get f() {
        return formatadores(this.intl.primaryLocale);
    }

    get ehAdminDoSistema() {
        return this.currentUser.isAdmin === true;
    }

    get situacoes() {
        return SITUACOES_DO_SAQUE.map((situacao) => ({ valor: situacao, chave: chaveDaSituacaoDoSaque(situacao) }));
    }

    get linhas() {
        return this.saques.map(linhaDoSaque);
    }

    get linhasDosMotoboys() {
        return this.motoboys.map(linhaDoMotoboyComPix);
    }

    limpar() {
        this.carregar.cancelAll();
        this.saques = [];
        this.motoboys = [];
        this.carregado = false;
    }

    @action valor(numero) {
        return this.f.valor(numero);
    }

    @action setSituacao(event) {
        this.situacao = event.target.value;
        this.carregar.perform();
    }

    @action configurar() {
        this.hostRouter.transitionTo('console.fleet-ops.financeiro.configuracoes');
    }

    /** O Toggle chama com o estado novo: ligado = saque liberado. */
    @action alternarBloqueio(motoboy, liberado) {
        this.salvarBloqueio.perform(motoboy, liberado);
    }

    @task({ restartable: true }) *carregar() {
        try {
            const resposta = yield this.fetch.get(ENDPOINT, consultaDosSaques(this.situacao));
            this.saques = resposta?.saques ?? [];
            this.motoboys = resposta?.motoboys ?? [];
            this.carregado = true;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ enqueue: true }) *salvarBloqueio(motoboy, liberado) {
        try {
            const resposta = yield this.fetch.put(`entregas/financeiro/motoboys/${motoboy.id}/saque`, { bloqueado: liberado !== true });
            const bloqueado = resposta?.bloqueado === true;
            this.motoboys = this.motoboys.map((item) => (item.id === motoboy.id ? { ...item, bloqueado } : item));
            this.notifications.success(this.intl.t(`${P}.motoboys.${bloqueado ? 'salvo-bloqueado' : 'salvo-liberado'}`, { nome: motoboy.nome }));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
```

- [ ] **Step 4: Template**

Crie `packages/fleetops/addon/templates/financeiro/saques.hbs`:

```hbs
<Layout::Section::Header @title={{t "fleet-ops.ui.financeiro.saque-pix.saques.titulo"}}>
    <div class="flex items-center space-x-2">
        <Button @icon="refresh" @size="sm" @helpText={{t "common.reload"}} @isLoading={{this.carregar.isRunning}} @onClick={{perform this.carregar}} />
        <Button @icon="gear" @size="sm" @text={{t "fleet-ops.ui.financeiro.saque-pix.saques.configurar"}} @onClick={{this.configurar}} />
    </div>
</Layout::Section::Header>

<Layout::Section::Body class="overflow-y-scroll-i h-full">
    <div class="max-w-6xl mx-auto px-4 py-6 space-y-5">
        <ContentPanel @title={{t "fleet-ops.ui.financeiro.saque-pix.saques.titulo"}} @open={{true}} @wrapperClass="bordered-classic">
            <div class="flex flex-wrap items-end justify-between gap-3 mb-3">
                <p class="text-xs text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.saque-pix.saques.ajuda"}}</p>
                <InputGroup @name={{t "fleet-ops.ui.financeiro.saque-pix.saques.filtro"}} @wrapperClass="mb-0" as |id|>
                    <select id={{id}} class="form-select form-select-sm" {{on "change" this.setSituacao}}>
                        <option value="" selected={{eq this.situacao ""}}>{{t "fleet-ops.ui.financeiro.saque-pix.saques.todas"}}</option>
                        {{#each this.situacoes as |opcao|}}
                            <option value={{opcao.valor}} selected={{eq opcao.valor this.situacao}}>{{t opcao.chave}}</option>
                        {{/each}}
                    </select>
                </InputGroup>
            </div>
            {{#if this.linhas.length}}
                <div class="overflow-x-auto">
                    <table class="table w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="py-2">{{t "fleet-ops.ui.financeiro.saque-pix.saques.quando"}}</th>
                                <th class="py-2">{{t "fleet-ops.ui.financeiro.saque-pix.saques.motoboy"}}</th>
                                <th class="py-2 text-right">{{t "fleet-ops.ui.financeiro.saque-pix.saques.valor"}}</th>
                                <th class="py-2 text-right">{{t "fleet-ops.ui.financeiro.saque-pix.saques.taxa"}}</th>
                                <th class="py-2">{{t "fleet-ops.ui.financeiro.saque-pix.saques.situacao"}}</th>
                                <th class="py-2">{{t "fleet-ops.ui.financeiro.saque-pix.saques.comprovante"}}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {{#each this.linhas key="id" as |saque|}}
                                <tr class="border-t border-gray-100 dark:border-gray-800 dark:text-gray-100">
                                    <td class="py-2 whitespace-nowrap">{{saque.quando}}</td>
                                    <td class="py-2">
                                        {{#if (and this.ehAdminDoSistema saque.motoboyId)}}
                                            <LinkTo @route="financeiro.motoboy" @model={{saque.motoboyId}} class="text-blue-600 dark:text-blue-400 hover:underline">{{saque.motoboy}}</LinkTo>
                                        {{else}}
                                            {{or saque.motoboy "—"}}
                                        {{/if}}
                                    </td>
                                    <td class="py-2 text-right whitespace-nowrap font-semibold">{{this.valor saque.valor}}</td>
                                    <td class="py-2 text-right whitespace-nowrap text-gray-500 dark:text-gray-400">{{this.valor saque.taxa}}</td>
                                    <td class="py-2">
                                        <Badge @status={{saque.status}} @hideStatusDot={{true}}>{{t saque.chave}}</Badge>
                                        {{#if saque.motivo}}
                                            <div class="text-xs text-gray-500 dark:text-gray-400">{{saque.motivo}}</div>
                                        {{/if}}
                                    </td>
                                    <td class="py-2 whitespace-nowrap">
                                        {{#if saque.comprovante}}
                                            <a href={{saque.comprovante}} target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 hover:underline">
                                                <FaIcon @icon="receipt" class="mr-1" />{{t "fleet-ops.ui.financeiro.saque-pix.saques.ver-comprovante"}}
                                            </a>
                                        {{else}}
                                            <span class="text-gray-400">—</span>
                                        {{/if}}
                                    </td>
                                </tr>
                            {{/each}}
                        </tbody>
                    </table>
                </div>
            {{else if this.carregar.isRunning}}
                <Spinner />
            {{else if this.carregado}}
                <p class="text-sm text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.saque-pix.saques.vazio"}}</p>
            {{/if}}
        </ContentPanel>

        <ContentPanel @title={{t "fleet-ops.ui.financeiro.saque-pix.motoboys.titulo"}} @open={{true}} @wrapperClass="bordered-classic">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{t "fleet-ops.ui.financeiro.saque-pix.motoboys.ajuda"}}</p>
            {{#if this.linhasDosMotoboys.length}}
                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    {{#each this.linhasDosMotoboys key="id" as |motoboy|}}
                        <li class="py-2 flex flex-wrap items-center justify-between gap-3 text-sm dark:text-gray-100">
                            <div class="min-w-0">
                                <div class="font-medium">{{motoboy.nome}}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                    {{#if motoboy.chaveDoTipo}}{{t motoboy.chaveDoTipo}} · {{/if}}<span class="font-mono">{{motoboy.chave}}</span>
                                </div>
                                {{#if motoboy.carencia}}
                                    <div class="text-xs text-yellow-700 dark:text-yellow-300">{{t "fleet-ops.ui.financeiro.saque-pix.carencia" hora=motoboy.carencia.hora dia=motoboy.carencia.dia}}</div>
                                {{/if}}
                            </div>
                            <Toggle
                                @isToggled={{not motoboy.bloqueado}}
                                @onToggle={{fn this.alternarBloqueio motoboy}}
                                @label={{if motoboy.bloqueado (t "fleet-ops.ui.financeiro.saque-pix.motoboys.bloqueado") (t "fleet-ops.ui.financeiro.saque-pix.motoboys.liberado")}}
                            />
                        </li>
                    {{/each}}
                </ul>
            {{else if this.carregado}}
                <p class="text-sm text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.saque-pix.motoboys.vazio"}}</p>
            {{/if}}
        </ContentPanel>
    </div>
    <Spacer @height="300px" />
</Layout::Section::Body>
```

- [ ] **Step 5: Conferir**

```bash
parse_js packages/fleetops/addon/routes.js packages/fleetops/addon/routes/financeiro/saques.js packages/fleetops/addon/controllers/financeiro/saques.js
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit $?"
```

Expected: três `ok` e `exit 0`.

- [ ] **Step 6: Commit**

```bash
git rev-parse --show-toplevel
git add packages/fleetops/addon/routes.js packages/fleetops/addon/routes/financeiro/saques.js packages/fleetops/addon/controllers/financeiro/saques.js packages/fleetops/addon/templates/financeiro/saques.hbs
git commit -m "Financeiro: tela Saques Pix (saques dos últimos 30 dias e bloqueio do saque por motoboy)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Acesso do admin da organização e menus

**Files:**
- Modify: `packages/fleetops/addon/routes/financeiro.js`
- Modify: `packages/fleetops/addon/routes/financeiro/index.js`
- Modify: `packages/fleetops/addon/components/layout/fleet-ops-sidebar.js` (import, `financeiroItems`, `defaultPriorityForRoute`)
- Modify: `console/app/components/doca-celular.js` (getter e item do Financeiro)

- [ ] **Step 1: Guarda da rota `financeiro`**

Troque o conteúdo inteiro de `packages/fleetops/addon/routes/financeiro.js` por:

```js
import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { ehAdminDaCentral, rotaDoAdminDaCentral } from '../utils/financeiro-saque-pix';

/**
 * Entregas RestaurantePro: Financeiro (painel, motoboys, saques Pix, lojas e faturas, tabela de km e relatório,
 * configurações). Só administradores, como a API (int/v1/entregas/financeiro/*, negarSeNaoAdmin). Exceção do saque Pix:
 * o administrador da organização (papel Administrator, AdminDaCentral na API) entra em Saques Pix e Configurações (só o
 * card do saque); o resto continua só com o administrador do sistema.
 *
 * A guarda roda ao entrar no Financeiro. Entre as telas filhas ela não roda de novo: o menu só mostra ao admin da
 * organização as duas telas dele, e a API nega o resto (403).
 */
export default class FinanceiroRoute extends Route {
    @service notifications;
    @service hostRouter;
    @service currentUser;
    @service intl;

    beforeModel(transition) {
        if (this.currentUser.isAdmin) {
            return;
        }
        if (ehAdminDaCentral(this.currentUser) && rotaDoAdminDaCentral(transition?.to?.name)) {
            return;
        }
        this.notifications.warning(this.intl.t('fleet-ops.ui.financeiro.admin-only'));
        return this.hostRouter.transitionTo('console.fleet-ops');
    }
}
```

- [ ] **Step 2: `/financeiro` do admin da organização abre Saques Pix**

Troque o conteúdo inteiro de `packages/fleetops/addon/routes/financeiro/index.js` por:

```js
import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/** Entregas RestaurantePro: /financeiro abre o Painel; para o admin da organização (sem ser do sistema), Saques Pix. */
export default class FinanceiroIndexRoute extends Route {
    @service hostRouter;
    @service currentUser;

    beforeModel() {
        const destino = this.currentUser.isAdmin ? 'painel' : 'saques';
        return this.hostRouter.transitionTo(`console.fleet-ops.financeiro.${destino}`);
    }
}
```

- [ ] **Step 3: Menu do Fleet-Ops**

Em `packages/fleetops/addon/components/layout/fleet-ops-sidebar.js`:

1. Troque `import isEntregasHiddenRoute from '../../utils/entregas-hidden-routes';` por:

```js
import isEntregasHiddenRoute from '../../utils/entregas-hidden-routes';
import { ehAdminDaCentral } from '../../utils/financeiro-saque-pix';
```

2. Troque o getter `financeiroItems` inteiro (do comentário `/** Entregas RestaurantePro: telas do Financeiro (a API só atende administradores). */` até o `}` que fecha o getter) por:

```js
    /**
     * Entregas RestaurantePro: telas do Financeiro (a API só atende administradores). Saques Pix e Configurações também
     * para o administrador da organização (papel Administrator): o saque Pix aceita os dois admins na API.
     */
    get financeiroItems() {
        const admin = this.currentUser.isAdmin === true;
        const daCentral = ehAdminDaCentral(this.currentUser);
        const item = (intl, icon, route, keywords, visivel = admin) => ({
            ...this.createItem(intl, icon, route, 'fleet-ops list driver', 'fleet-ops see driver', keywords),
            visible: visivel,
        });

        return this.sortByPriority([
            item('fleet-ops.ui.financeiro.menu.painel', 'chart-pie', 'financeiro.painel', ['a pagar', 'a receber', 'margem']),
            item('fleet-ops.ui.financeiro.menu.motoboys', 'motorcycle', 'financeiro.motoboys', ['extrato', 'pagamento', 'diária', 'pix']),
            item('fleet-ops.ui.financeiro.menu.saques', 'money-bill-transfer', 'financeiro.saques', ['saque', 'pix', 'asaas', 'bloquear'], daCentral),
            item('fleet-ops.ui.financeiro.menu.lojas', 'file-invoice-dollar', 'financeiro.lojas', ['fatura', 'loja', 'cobrança', 'recebimento']),
            item('fleet-ops.ui.financeiro.menu.relatorio', 'table', 'financeiro.relatorio', ['faixas', 'km', 'relatório', 'payout']),
            item('fleet-ops.ui.financeiro.menu.multiplicadores', 'cloud-rain', 'financeiro.multiplicadores', ['chuva', 'multiplicador', 'intempérie', 'adicional']),
            item('fleet-ops.ui.financeiro.menu.configuracoes', 'gear', 'financeiro.configuracoes', ['data de corte', 'ciclo', 'saque pix', 'asaas'], daCentral),
            // relatório do dono do sistema (todas as organizações): regra própria, independente da dos itens acima
            item('fleet-ops.ui.financeiro.menu.corridas-do-sistema', 'building', 'financeiro.corridas-do-sistema', ['corridas', 'centrais', 'organizações', 'sistema'], this.currentUser.isAdmin === true),
        ]);
    }
```

3. No `defaultPriorityForRoute`, troque as linhas do Financeiro:

```js
            'financeiro.painel': 0,
            'financeiro.motoboys': 1,
            'financeiro.lojas': 2,
            'financeiro.relatorio': 3,
            'financeiro.multiplicadores': 4,
            'financeiro.configuracoes': 5,
            'financeiro.corridas-do-sistema': 6,
```

por:

```js
            'financeiro.painel': 0,
            'financeiro.motoboys': 1,
            'financeiro.saques': 2,
            'financeiro.lojas': 3,
            'financeiro.relatorio': 4,
            'financeiro.multiplicadores': 5,
            'financeiro.configuracoes': 6,
            'financeiro.corridas-do-sistema': 7,
```

- [ ] **Step 4: Doca do celular (folha "Mais")**

Em `console/app/components/doca-celular.js`:

1. Logo depois do getter `ehAdmin` (termina em `return this.currentUser.isAdmin === true;` + `}`), acrescente:

```js
    /**
     * Admin do sistema ou papel Administrator na organização: vê os Saques Pix (a mesma regra do ehAdminDaCentral de
     * packages/fleetops/addon/utils/financeiro-saque-pix.js; o console não importa utils do engine).
     */
    get ehAdminDaCentral() {
        return this.currentUser.isAdmin === true || this.currentUser.roleName === 'Administrator';
    }
```

2. No grupo `financeiro` do getter `grupos`, troque:

```js
                    item('motorcycle', t('extrato-motoboys'), 'console.fleet-ops.financeiro.motoboys', this.ehAdmin),
```

por:

```js
                    item('motorcycle', t('extrato-motoboys'), 'console.fleet-ops.financeiro.motoboys', this.ehAdmin),
                    item('money-bill-transfer', t('saques-pix'), 'console.fleet-ops.financeiro.saques', this.ehAdminDaCentral),
```

- [ ] **Step 5: Conferir**

```bash
parse_js packages/fleetops/addon/routes/financeiro.js packages/fleetops/addon/routes/financeiro/index.js packages/fleetops/addon/components/layout/fleet-ops-sidebar.js console/app/components/doca-celular.js
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/financeiro-saque-pix.test.mjs
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit $?"
```

Expected: quatro `ok`, `# fail 0` e `exit 0`.

- [ ] **Step 6: Commit**

```bash
git rev-parse --show-toplevel
git add packages/fleetops/addon/routes/financeiro.js packages/fleetops/addon/routes/financeiro/index.js packages/fleetops/addon/components/layout/fleet-ops-sidebar.js console/app/components/doca-celular.js
git commit -m "Financeiro: Saques Pix no menu e na doca; admin da organização entra em Saques Pix e Configurações

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Card "Saque Pix (Asaas)", passo 1 (conta) e a tela Configurações

**Files:**
- Create: `packages/fleetops/addon/components/financeiro/saque-pix.js`, `saque-pix.hbs`
- Create: `packages/fleetops/addon/components/financeiro/saque-pix/conta.js`, `conta.hbs`
- Create: `packages/fleetops/addon/components/financeiro/saque-pix/validacao.hbs`, `ligar.hbs` (provisórios, completados nas Tasks 9 e 10)
- Create: `packages/fleetops/app/components/financeiro/saque-pix.js`, `packages/fleetops/app/components/financeiro/saque-pix/conta.js`
- Modify: `packages/fleetops/addon/controllers/financeiro/configuracoes.js`
- Modify: `packages/fleetops/addon/templates/financeiro/configuracoes.hbs`

O card fica completo nas Tasks 9 e 10 (validação e ligar). Nesta, o pai já chama os três passos, por isso os templates dos passos 2 e 3 são criados aqui com um corpo mínimo (componentes só de template, que o build aceita) e as tarefas seguintes criam as classes e os reexports deles.

- [ ] **Step 1: Componente pai**

Crie `packages/fleetops/addon/components/financeiro/saque-pix.js`:

```js
import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { API_DO_SAQUE_PIX, etapasDoSaque } from '../../utils/financeiro-saque-pix';

/**
 * Entregas RestaurantePro: card "Saque Pix (Asaas)" de Financeiro → Configurações, em três passos (conta Asaas, validação
 * de saque, ligar e limites). Lê `GET int/v1/entregas/financeiro/saque-pix` (SaquePixController@situacao, formato
 * ConexaoAsaas::publico: nunca a chave nem o token) e repassa a conexão aos passos; cada passo grava pela própria rota e
 * devolve a conexão nova por @aoMudar. Para o admin do sistema e o da organização (ehAdminDaCentral).
 */
export default class FinanceiroSaquePixComponent extends Component {
    @service fetch;
    @service notifications;

    @tracked conexao = null;

    constructor() {
        super(...arguments);
        this.carregar.perform();
    }

    get etapas() {
        return etapasDoSaque(this.conexao);
    }

    /** A conexão nova devolvida por um passo (o formato público sempre traz `conectada`). */
    @action aoMudar(conexao) {
        if (conexao && typeof conexao === 'object' && 'conectada' in conexao) {
            this.conexao = conexao;
        }
    }

    @task({ restartable: true }) *carregar() {
        try {
            this.conexao = yield this.fetch.get(API_DO_SAQUE_PIX);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
```

Crie `packages/fleetops/addon/components/financeiro/saque-pix.hbs`:

```hbs
<ContentPanel @title={{t "fleet-ops.ui.financeiro.saque-pix.titulo"}} @open={{true}} @wrapperClass="bordered-classic">
    <div class="space-y-4">
        <div class="flex items-start justify-between gap-3">
            <p class="text-sm text-gray-600 dark:text-gray-300">{{t "fleet-ops.ui.financeiro.saque-pix.intro"}}</p>
            <Button @icon="refresh" @size="xs" @helpText={{t "common.reload"}} @isLoading={{this.carregar.isRunning}} @onClick={{perform this.carregar}} />
        </div>
        {{#if this.conexao}}
            {{#if (eq this.conexao.ligada false)}}
                <div class="text-sm rounded-md px-3 py-2 bg-yellow-50 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-100">{{t "fleet-ops.ui.financeiro.saque-pix.desligado-no-servidor"}}</div>
            {{/if}}
            {{#if (eq this.conexao.cofre false)}}
                <div class="text-sm rounded-md px-3 py-2 bg-red-50 text-red-800 dark:bg-red-900 dark:text-red-100">{{t "fleet-ops.ui.financeiro.saque-pix.sem-cofre"}}</div>
            {{/if}}
            <Financeiro::SaquePix::Conta @conexao={{this.conexao}} @etapas={{this.etapas}} @aoMudar={{this.aoMudar}} />
            <Financeiro::SaquePix::Validacao @conexao={{this.conexao}} @etapas={{this.etapas}} @aoMudar={{this.aoMudar}} />
            <Financeiro::SaquePix::Ligar @conexao={{this.conexao}} @etapas={{this.etapas}} @aoMudar={{this.aoMudar}} />
        {{else if this.carregar.isRunning}}
            <Spinner />
        {{/if}}
    </div>
</ContentPanel>
```

- [ ] **Step 2: Passo 1, conta**

Crie `packages/fleetops/addon/components/financeiro/saque-pix/conta.js`:

```js
import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { quandoDoIso } from '../../../utils/financeiro';
import { API_DO_SAQUE_PIX } from '../../../utils/financeiro-saque-pix';

const P = 'fleet-ops.ui.financeiro.saque-pix.conta';

/**
 * Entregas RestaurantePro: passo 1 do card Saque Pix. Conectar a conta Asaas da central (chave de API + senha do admin),
 * mostrar a conta conectada, trocar a chave (mesma conta) e desconectar (com a senha): POST/DELETE
 * int/v1/entregas/financeiro/saque-pix/conexao (SaquePixController@conectar/desconectar). A chave e a senha digitadas
 * somem da tela ao gravar, ao errar (a senha) ou ao cancelar, e a chave nunca volta do servidor.
 */
export default class FinanceiroSaquePixContaComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;

    // null = só a conta; 'trocar' = formulário da chave nova; 'desconectar' = confirmação com a senha
    @tracked modo = null;
    @tracked chave = '';
    @tracked senha = '';

    get conta() {
        return this.args.conexao?.conta ?? null;
    }

    get conectadaEm() {
        const quando = quandoDoIso(this.args.conexao?.conectada_em);
        return quando ? `${quando.data} ${quando.hora}` : '';
    }

    get semCofre() {
        return this.args.conexao?.cofre === false;
    }

    /** O formulário da chave: para conectar (sem conta) ou para trocar a chave. */
    get mostrarFormulario() {
        return !this.args.etapas.conectada || this.modo === 'trocar';
    }

    @action setCampo(campo, event) {
        this[campo] = event.target.value;
    }

    @action abrir(modo) {
        this.modo = modo;
        this.chave = '';
        this.senha = '';
    }

    @action fechar() {
        this.abrir(null);
    }

    @task({ drop: true }) *conectar() {
        if (!this.chave.trim() || !this.senha) {
            this.notifications.warning(this.intl.t(`${P}.preencha`));
            return;
        }
        const trocando = this.args.etapas.conectada;
        try {
            const conexao = yield this.fetch.post(`${API_DO_SAQUE_PIX}/conexao`, { chave: this.chave.trim(), senha: this.senha });
            this.args.aoMudar(conexao);
            this.fechar();
            this.notifications.success(this.intl.t(trocando ? `${P}.chave-trocada` : `${P}.conectada`));
        } catch (error) {
            this.senha = '';
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *desconectar() {
        if (!this.senha) {
            this.notifications.warning(this.intl.t(`${P}.preencha-senha`));
            return;
        }
        try {
            const conexao = yield this.fetch.delete(`${API_DO_SAQUE_PIX}/conexao`, { senha: this.senha });
            this.args.aoMudar(conexao);
            this.fechar();
            this.notifications.success(this.intl.t(`${P}.desconectada`));
        } catch (error) {
            this.senha = '';
            this.notifications.serverError(error);
        }
    }
}
```

Crie `packages/fleetops/addon/components/financeiro/saque-pix/conta.hbs`:

```hbs
<section class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-3">
    <header class="flex flex-wrap items-center gap-2">
        <span class="text-xs uppercase text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.saque-pix.passo" numero=1}}</span>
        <h3 class="text-sm font-semibold dark:text-white">{{t "fleet-ops.ui.financeiro.saque-pix.conta.titulo"}}</h3>
        {{#if @etapas.conectada}}
            <Badge @status="success" @hideStatusDot={{true}}>{{t "fleet-ops.ui.financeiro.saque-pix.feito"}}</Badge>
        {{/if}}
    </header>
    <p class="text-xs text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.saque-pix.conta.ajuda"}}</p>

    {{#if @etapas.conectada}}
        <dl class="grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
            <div>
                <dt class="text-xs text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.saque-pix.conta.nome"}}</dt>
                <dd class="dark:text-gray-100">{{or this.conta.nome "—"}}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.saque-pix.conta.documento"}}</dt>
                <dd class="font-mono dark:text-gray-100">{{or this.conta.documento "—"}}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.saque-pix.conta.wallet"}}</dt>
                <dd class="font-mono break-all dark:text-gray-100">{{this.conta.wallet}}</dd>
            </div>
        </dl>
        {{#if this.conectadaEm}}
            <p class="text-xs text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.saque-pix.conta.conectada-em" quando=this.conectadaEm}}</p>
        {{/if}}
        {{#unless this.modo}}
            <div class="flex flex-wrap gap-2">
                <Button @size="xs" @icon="key" @text={{t "fleet-ops.ui.financeiro.saque-pix.conta.trocar"}} @onClick={{fn this.abrir "trocar"}} />
                <Button @size="xs" @type="danger" @icon="link-slash" @text={{t "fleet-ops.ui.financeiro.saque-pix.conta.desconectar"}} @onClick={{fn this.abrir "desconectar"}} />
            </div>
        {{/unless}}
    {{/if}}

    {{#if this.mostrarFormulario}}
        {{#if (eq this.modo "trocar")}}
            <p class="text-xs text-yellow-700 dark:text-yellow-300">{{t "fleet-ops.ui.financeiro.saque-pix.conta.trocar-ajuda"}}</p>
        {{/if}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <InputGroup @name={{t "fleet-ops.ui.financeiro.saque-pix.conta.chave"}} @helpText={{t "fleet-ops.ui.financeiro.saque-pix.conta.chave-ajuda"}} @wrapperClass="mb-0" as |id|>
                <input
                    id={{id}}
                    type="password"
                    autocomplete="off"
                    spellcheck="false"
                    class="form-input form-input-sm w-full font-mono"
                    value={{this.chave}}
                    disabled={{this.semCofre}}
                    {{on "input" (fn this.setCampo "chave")}}
                />
            </InputGroup>
            <InputGroup @name={{t "fleet-ops.ui.financeiro.saque-pix.conta.senha"}} @helpText={{t "fleet-ops.ui.financeiro.saque-pix.conta.senha-ajuda"}} @wrapperClass="mb-0" as |id|>
                <input id={{id}} type="password" autocomplete="current-password" class="form-input form-input-sm w-full" value={{this.senha}} {{on "input" (fn this.setCampo "senha")}} />
            </InputGroup>
        </div>
        <div class="flex items-center gap-2">
            <Button
                @type="primary"
                @size="sm"
                @icon={{if @etapas.conectada "save" "plug"}}
                @text={{if @etapas.conectada (t "fleet-ops.ui.financeiro.saque-pix.conta.salvar-troca") (t "fleet-ops.ui.financeiro.saque-pix.conta.conectar")}}
                @disabled={{this.semCofre}}
                @isLoading={{this.conectar.isRunning}}
                @onClick={{perform this.conectar}}
            />
            {{#if this.modo}}
                <Button @size="sm" @text={{t "fleet-ops.ui.financeiro.saque-pix.conta.cancelar"}} @onClick={{this.fechar}} />
            {{/if}}
        </div>
    {{/if}}

    {{#if (eq this.modo "desconectar")}}
        <div class="text-sm rounded-md px-3 py-2 bg-red-50 text-red-800 dark:bg-red-900 dark:text-red-100">{{t "fleet-ops.ui.financeiro.saque-pix.conta.desconectar-ajuda"}}</div>
        <InputGroup @name={{t "fleet-ops.ui.financeiro.saque-pix.conta.senha"}} @wrapperClass="mb-0" as |id|>
            <input id={{id}} type="password" autocomplete="current-password" class="form-input form-input-sm w-full md:w-1/2" value={{this.senha}} {{on "input" (fn this.setCampo "senha")}} />
        </InputGroup>
        <div class="flex items-center gap-2">
            <Button
                @type="danger"
                @size="sm"
                @icon="link-slash"
                @text={{t "fleet-ops.ui.financeiro.saque-pix.conta.confirmar-desconexao"}}
                @isLoading={{this.desconectar.isRunning}}
                @onClick={{perform this.desconectar}}
            />
            <Button @size="sm" @text={{t "fleet-ops.ui.financeiro.saque-pix.conta.cancelar"}} @onClick={{this.fechar}} />
        </div>
    {{/if}}
</section>
```

- [ ] **Step 3: Passos 2 e 3 provisórios**

Crie `packages/fleetops/addon/components/financeiro/saque-pix/validacao.hbs` e `packages/fleetops/addon/components/financeiro/saque-pix/ligar.hbs`, cada um só com (as Tasks 9 e 10 trocam o conteúdo e criam os `.js` e os reexports):

```hbs
<section class="rounded-lg border border-gray-200 dark:border-gray-700 p-4"></section>
```

- [ ] **Step 4: Reexports para o console**

Crie os dois arquivos abaixo, cada um com uma linha (o mesmo padrão de `app/components/tela-pedidos/*`):

`packages/fleetops/app/components/financeiro/saque-pix.js`:

```js
export { default } from '@fleetbase/fleetops-engine/components/financeiro/saque-pix';
```

`packages/fleetops/app/components/financeiro/saque-pix/conta.js`:

```js
export { default } from '@fleetbase/fleetops-engine/components/financeiro/saque-pix/conta';
```

- [ ] **Step 5: Controller das Configurações**

Em `packages/fleetops/addon/controllers/financeiro/configuracoes.js`:

1. Troque `import { CICLOS, diaAoTrocarDeCiclo, diasDeFechamento, validarCobranca } from '../../utils/financeiro';` por:

```js
import { CICLOS, diaAoTrocarDeCiclo, diasDeFechamento, validarCobranca } from '../../utils/financeiro';
import { ehAdminDaCentral } from '../../utils/financeiro-saque-pix';
```

2. No docblock da classe, troque a linha ` * - "ligada" (só leitura) = ENTREGAS_FINANCEIRO no servidor; desligada, as telas funcionam mas nada é lançado sozinho.` por:

```js
 * - "ligada" (só leitura) = ENTREGAS_FINANCEIRO no servidor; desligada, as telas funcionam mas nada é lançado sozinho.
 * - Card "Saque Pix (Asaas)" (componente Financeiro::SaquePix), também para o admin da organização. Esse admin vê só o
 *   card: as rotas de corte e ciclo continuam só com o admin do sistema na API, e o `carregar` nem as chama para ele.
```

3. Troque `    @service notifications;` (a única da classe) por:

```js
    @service notifications;
    @service currentUser;
```

4. Troque `    @tracked prazo = String(PADRAO.prazo_dias);` por:

```js
    @tracked prazo = String(PADRAO.prazo_dias);

    get ehAdminDoSistema() {
        return this.currentUser.isAdmin === true;
    }

    get ehAdminDaCentral() {
        return ehAdminDaCentral(this.currentUser);
    }
```

5. Troque:

```js
    @task({ restartable: true }) *carregar() {
        try {
            this.preencher(yield this.fetch.get(ENDPOINT));
```

por:

```js
    @task({ restartable: true }) *carregar() {
        // o admin da organização vê só o card do saque Pix (a API nega a ele as configurações gerais)
        if (!this.ehAdminDoSistema) {
            return;
        }
        try {
            this.preencher(yield this.fetch.get(ENDPOINT));
```

- [ ] **Step 6: Template das Configurações**

Troque o conteúdo inteiro de `packages/fleetops/addon/templates/financeiro/configuracoes.hbs` por:

```hbs
<Layout::Section::Header @title={{t "fleet-ops.ui.financeiro.configuracoes.titulo"}}>
    {{#if this.ehAdminDoSistema}}
        <div class="flex items-center space-x-2">
            <Button @icon="refresh" @size="sm" @helpText={{t "common.reload"}} @isLoading={{this.carregar.isRunning}} @onClick={{perform this.carregar}} />
            <Button
                @type="primary"
                @icon="save"
                @size="sm"
                @text={{t "fleet-ops.ui.financeiro.configuracoes.salvar"}}
                @disabled={{or (not this.carregado) this.erro}}
                @isLoading={{this.salvar.isRunning}}
                @onClick={{perform this.salvar}}
            />
        </div>
    {{/if}}
</Layout::Section::Header>

<Layout::Section::Body class="overflow-y-scroll-i h-full">
    <div class="max-w-3xl mx-auto px-4 py-6 space-y-5">
        {{#if this.ehAdminDoSistema}}
            {{#if this.carregado}}
                {{#unless this.ligada}}
                    <div class="text-sm rounded-md px-3 py-2 bg-yellow-50 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-100">
                        {{t "fleet-ops.ui.financeiro.configuracoes.desligada"}}
                    </div>
                {{/unless}}

                <ContentPanel @title={{t "fleet-ops.ui.financeiro.configuracoes.corte-titulo"}} @open={{true}} @wrapperClass="bordered-classic">
                    <div class="flex flex-wrap items-end gap-3">
                        <InputGroup @name={{t "fleet-ops.ui.financeiro.configuracoes.corte"}} @wrapperClass="mb-0" as |id|>
                            <input id={{id}} type="date" class="form-input form-input-sm" value={{this.corte}} {{on "change" this.setCorte}} />
                        </InputGroup>
                        {{#if this.corte}}
                            <Button @size="sm" @icon="xmark" @text={{t "fleet-ops.ui.financeiro.configuracoes.tirar-corte"}} @onClick={{this.limparCorte}} />
                        {{/if}}
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">{{t "fleet-ops.ui.financeiro.configuracoes.corte-ajuda"}}</p>
                    {{#unless this.corte}}
                        <p class="text-xs text-yellow-700 dark:text-yellow-300 mt-1">{{t "fleet-ops.ui.financeiro.configuracoes.sem-corte"}}</p>
                    {{/unless}}
                </ContentPanel>

                <ContentPanel @title={{t "fleet-ops.ui.financeiro.configuracoes.ciclo-titulo"}} @open={{true}} @wrapperClass="bordered-classic">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <InputGroup @name={{t "fleet-ops.ui.financeiro.configuracoes.ciclo"}} @wrapperClass="mb-0" as |id|>
                            <select id={{id}} class="form-select form-select-sm w-full" {{on "change" this.setCiclo}}>
                                {{#each this.ciclos as |opcao|}}
                                    <option value={{opcao.valor}} selected={{eq opcao.valor this.ciclo}}>{{t opcao.chave}}</option>
                                {{/each}}
                            </select>
                        </InputGroup>
                        {{#if this.dias.length}}
                            <InputGroup @name={{t "fleet-ops.ui.financeiro.configuracoes.dia-fechamento"}} @wrapperClass="mb-0" as |id|>
                                <select id={{id}} class="form-select form-select-sm w-full" {{on "change" this.setDia}}>
                                    {{#each this.dias as |opcao|}}
                                        <option value={{opcao.valor}} selected={{eq opcao.valor this.dia}}>{{t opcao.chave dia=opcao.params.dia}}</option>
                                    {{/each}}
                                </select>
                            </InputGroup>
                        {{else}}
                            <div class="text-xs text-gray-500 dark:text-gray-400 md:pt-6">{{t "fleet-ops.ui.financeiro.configuracoes.quinzenal-ajuda"}}</div>
                        {{/if}}
                        <InputGroup @name={{t "fleet-ops.ui.financeiro.configuracoes.prazo"}} @wrapperClass="mb-0" as |id|>
                            <input id={{id}} type="number" min="0" max="60" step="1" inputmode="numeric" class="form-input form-input-sm w-full" value={{this.prazo}} {{on "input" this.setPrazo}} />
                        </InputGroup>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">{{t "fleet-ops.ui.financeiro.configuracoes.ciclo-ajuda"}}</p>
                    {{#if this.erro}}
                        <p class="text-xs text-yellow-700 dark:text-yellow-300 mt-1">{{this.erro}}</p>
                    {{/if}}
                </ContentPanel>
            {{else if this.carregar.isRunning}}
                <Spinner />
            {{/if}}
        {{/if}}

        {{#if this.ehAdminDaCentral}}
            <Financeiro::SaquePix />
        {{/if}}
    </div>
    <Spacer @height="300px" />
</Layout::Section::Body>
```

- [ ] **Step 7: Conferir**

```bash
parse_js packages/fleetops/addon/components/financeiro/saque-pix.js packages/fleetops/addon/components/financeiro/saque-pix/conta.js packages/fleetops/addon/controllers/financeiro/configuracoes.js packages/fleetops/app/components/financeiro/saque-pix.js packages/fleetops/app/components/financeiro/saque-pix/conta.js
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit $?"
```

Expected: cinco `ok` e `exit 0`.

- [ ] **Step 8: Commit**

```bash
git rev-parse --show-toplevel
git add packages/fleetops/addon/components/financeiro packages/fleetops/app/components/financeiro packages/fleetops/addon/controllers/financeiro/configuracoes.js packages/fleetops/addon/templates/financeiro/configuracoes.hbs
git commit -m "Financeiro → Configurações: card Saque Pix (Asaas), passo 1 (conectar, trocar a chave, desconectar com senha)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Passo 2, validação de saque

**Files:**
- Create: `packages/fleetops/addon/components/financeiro/saque-pix/validacao.js`
- Create: `packages/fleetops/app/components/financeiro/saque-pix/validacao.js`
- Modify (troca o conteúdo): `packages/fleetops/addon/components/financeiro/saque-pix/validacao.hbs`

- [ ] **Step 1: Componente**

Crie `packages/fleetops/addon/components/financeiro/saque-pix/validacao.js`:

```js
import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task, timeout } from 'ember-concurrency';
import { TIPOS_CHAVE_PIX, quandoDoIso, validarPix } from '../../../utils/financeiro';
import { API_DO_SAQUE_PIX, INTERVALO_DO_TESTE_MS, resultadoDoTeste, segundosRestantes } from '../../../utils/financeiro-saque-pix';

const P = 'fleet-ops.ui.financeiro.saque-pix.validacao';

/**
 * Entregas RestaurantePro: passo 2 do card Saque Pix, a validação de saque do Asaas. Mostra a URL que a central cola no
 * Asaas, gera o token (POST …/saque-pix/token → {token, url, conexao}: o token aparece só aqui, só nesta tela, e some ao
 * sair), o passo a passo do Asaas e a recomendação da lista de IPs (com o `ip_de_saida` do servidor). "Testar validação"
 * (POST …/saque-pix/teste {tipo, chave}) manda R$ 1,00 para uma chave Pix do admin, que a validação do Entregas recusa;
 * a tela relê a conexão a cada 3 s por até 60 s esperando `validacao_confirmada_em`.
 */
export default class FinanceiroSaquePixValidacaoComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;
    @service modalsManager;

    // o token acabado de gerar: só na memória desta tela (o servidor guarda só o hash)
    @tracked tokenNovo = null;
    @tracked tipo = 'cpf';
    @tracked chavePix = '';
    // instante (ms) em que o teste desta tela foi pedido; null = nenhum
    @tracked comecouEm = null;
    @tracked agora = Date.now();

    get url() {
        return this.args.conexao?.url_da_validacao ?? '';
    }

    get ip() {
        return this.args.conexao?.ip_de_saida || null;
    }

    get tiposDePix() {
        return TIPOS_CHAVE_PIX.map((tipo) => ({ valor: tipo, chave: `fleet-ops.ui.financeiro.pix.tipos.${tipo}` }));
    }

    get erroDoPix() {
        const erro = validarPix(this.tipo, this.chavePix);
        return erro ? this.intl.t(`fleet-ops.ui.financeiro.erros.${erro}`) : null;
    }

    get resultado() {
        return resultadoDoTeste(this.args.conexao, this.comecouEm, this.agora);
    }

    get segundos() {
        return this.comecouEm ? segundosRestantes(this.comecouEm, this.agora) : 0;
    }

    get confirmadaEm() {
        const quando = quandoDoIso(this.args.conexao?.validacao_confirmada_em);
        return quando ? `${quando.data} ${quando.hora}` : '';
    }

    @action setCampo(campo, event) {
        this[campo] = event.target.value;
    }

    /** Primeiro token: gera direto. Outro token: confirma, porque invalida o anterior, desliga o saque e pede novo teste. */
    @action pedirToken() {
        if (!this.args.etapas.tokenGerado) {
            this.gerarToken.perform();
            return;
        }
        this.modalsManager.confirm({
            title: this.intl.t(`${P}.gerar-outro-titulo`),
            body: this.intl.t(`${P}.gerar-outro-texto`),
            acceptButtonText: this.intl.t(`${P}.gerar-outro`),
            acceptButtonIcon: 'key',
            confirm: async (modal) => {
                modal.startLoading();
                await this.gerarToken.perform();
            },
        });
    }

    @task({ drop: true }) *gerarToken() {
        try {
            const resposta = yield this.fetch.post(`${API_DO_SAQUE_PIX}/token`);
            this.tokenNovo = resposta?.token ?? null;
            this.comecouEm = null;
            this.args.aoMudar(resposta?.conexao);
            this.notifications.success(this.intl.t(`${P}.token-gerado`));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *testar() {
        if (this.erroDoPix) {
            this.notifications.warning(this.erroDoPix);
            return;
        }
        try {
            this.args.aoMudar(yield this.fetch.post(`${API_DO_SAQUE_PIX}/teste`, { tipo: this.tipo, chave: this.chavePix.trim() }));
        } catch (error) {
            this.notifications.serverError(error);
            return;
        }
        this.comecouEm = Date.now();
        this.agora = this.comecouEm;
        yield this.acompanhar.perform();
    }

    /** Relê a conexão a cada 3 s até a validação confirmar ou passar de 60 s (resultadoDoTeste). */
    @task({ restartable: true }) *acompanhar() {
        while (resultadoDoTeste(this.args.conexao, this.comecouEm, Date.now()) === 'esperando') {
            yield timeout(INTERVALO_DO_TESTE_MS);
            this.agora = Date.now();
            try {
                this.args.aoMudar(yield this.fetch.get(API_DO_SAQUE_PIX));
            } catch (error) {
                // uma leitura que falhou não encerra a espera: tenta de novo no próximo intervalo
            }
        }
        this.agora = Date.now();
    }
}
```

- [ ] **Step 2: Template**

Troque o conteúdo de `packages/fleetops/addon/components/financeiro/saque-pix/validacao.hbs` por:

```hbs
<section class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-3 {{unless @etapas.conectada 'opacity-60'}}">
    <header class="flex flex-wrap items-center gap-2">
        <span class="text-xs uppercase text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.saque-pix.passo" numero=2}}</span>
        <h3 class="text-sm font-semibold dark:text-white">{{t "fleet-ops.ui.financeiro.saque-pix.validacao.titulo"}}</h3>
        {{#if @etapas.validada}}
            <Badge @status="success" @hideStatusDot={{true}}>{{t "fleet-ops.ui.financeiro.saque-pix.feito"}}</Badge>
        {{/if}}
    </header>
    <p class="text-xs text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.saque-pix.validacao.ajuda"}}</p>

    {{#if @etapas.conectada}}
        <div class="space-y-1">
            <div class="text-xs uppercase text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.saque-pix.validacao.url"}}</div>
            <ClickToCopy @value={{this.url}} class="inline-block font-mono text-sm text-gray-800 dark:text-gray-100 break-all">
                <FaIcon @icon="copy" class="mr-1 text-gray-400" />{{this.url}}
            </ClickToCopy>
        </div>

        <div class="space-y-1">
            <div class="text-xs uppercase text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.saque-pix.validacao.token"}}</div>
            {{#if this.tokenNovo}}
                <div class="text-sm rounded-md px-3 py-2 bg-yellow-50 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-100">{{t "fleet-ops.ui.financeiro.saque-pix.validacao.token-uma-vez"}}</div>
                <ClickToCopy @value={{this.tokenNovo}} class="inline-block font-mono text-sm text-gray-800 dark:text-gray-100 break-all">
                    <FaIcon @icon="copy" class="mr-1 text-gray-400" />{{this.tokenNovo}}
                </ClickToCopy>
            {{else if @etapas.tokenGerado}}
                <p class="text-xs text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.saque-pix.validacao.token-existe"}}</p>
            {{/if}}
            <div>
                <Button
                    @size="xs"
                    @icon="key"
                    @text={{if @etapas.tokenGerado (t "fleet-ops.ui.financeiro.saque-pix.validacao.gerar-outro") (t "fleet-ops.ui.financeiro.saque-pix.validacao.gerar")}}
                    @isLoading={{this.gerarToken.isRunning}}
                    @onClick={{this.pedirToken}}
                />
            </div>
        </div>

        <div class="rounded-md border border-gray-200 dark:border-gray-700 p-3 space-y-2">
            <div class="text-sm font-semibold dark:text-white">{{t "fleet-ops.ui.financeiro.saque-pix.validacao.passos-titulo"}}</div>
            <ol class="list-decimal pl-5 text-sm text-gray-700 dark:text-gray-200 space-y-1">
                <li>{{t "fleet-ops.ui.financeiro.saque-pix.validacao.passo-1"}}</li>
                <li>{{t "fleet-ops.ui.financeiro.saque-pix.validacao.passo-2"}}</li>
                <li>{{t "fleet-ops.ui.financeiro.saque-pix.validacao.passo-3"}}</li>
            </ol>
            <div class="text-sm font-semibold dark:text-white pt-1">{{t "fleet-ops.ui.financeiro.saque-pix.validacao.ips-titulo"}}</div>
            <p class="text-sm text-gray-700 dark:text-gray-200">
                {{#if this.ip}}
                    {{t "fleet-ops.ui.financeiro.saque-pix.validacao.ips-com-ip" ip=this.ip}}
                {{else}}
                    {{t "fleet-ops.ui.financeiro.saque-pix.validacao.ips-sem-ip"}}
                {{/if}}
            </p>
        </div>

        <div class="space-y-2">
            <div class="text-sm font-semibold dark:text-white">{{t "fleet-ops.ui.financeiro.saque-pix.validacao.teste-titulo"}}</div>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.saque-pix.validacao.teste-ajuda"}}</p>
            {{#if @etapas.podeTestar}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                    <InputGroup @name={{t "fleet-ops.ui.financeiro.saque-pix.validacao.teste-tipo"}} @wrapperClass="mb-0" as |id|>
                        <select id={{id}} class="form-select form-select-sm w-full" {{on "change" (fn this.setCampo "tipo")}}>
                            {{#each this.tiposDePix as |opcao|}}
                                <option value={{opcao.valor}} selected={{eq opcao.valor this.tipo}}>{{t opcao.chave}}</option>
                            {{/each}}
                        </select>
                    </InputGroup>
                    <InputGroup @name={{t "fleet-ops.ui.financeiro.saque-pix.validacao.teste-chave"}} @wrapperClass="mb-0" as |id|>
                        <input id={{id}} type="text" autocomplete="off" maxlength="140" class="form-input form-input-sm w-full font-mono" value={{this.chavePix}} {{on "input" (fn this.setCampo "chavePix")}} />
                    </InputGroup>
                    <div>
                        <Button
                            @type="primary"
                            @size="sm"
                            @icon="vial"
                            @text={{t "fleet-ops.ui.financeiro.saque-pix.validacao.testar"}}
                            @disabled={{or this.acompanhar.isRunning (not this.chavePix)}}
                            @isLoading={{this.testar.isRunning}}
                            @onClick={{perform this.testar}}
                        />
                    </div>
                </div>
                {{#if (and this.chavePix this.erroDoPix)}}
                    <p class="text-xs text-yellow-700 dark:text-yellow-300">{{this.erroDoPix}}</p>
                {{/if}}
            {{else}}
                <p class="text-xs text-yellow-700 dark:text-yellow-300">{{t "fleet-ops.ui.financeiro.saque-pix.validacao.gere-o-token"}}</p>
            {{/if}}

            {{#if (eq this.resultado "confirmada")}}
                <p class="text-sm text-green-700 dark:text-green-400"><FaIcon @icon="circle-check" class="mr-1" />{{t "fleet-ops.ui.financeiro.saque-pix.validacao.confirmada" quando=this.confirmadaEm}}</p>
            {{else if (eq this.resultado "esperando")}}
                <p class="text-sm text-gray-600 dark:text-gray-300"><FaIcon @icon="hourglass-half" class="mr-1" />{{t "fleet-ops.ui.financeiro.saque-pix.validacao.esperando" segundos=this.segundos}}</p>
            {{else if (eq this.resultado "sem-resposta")}}
                <p class="text-sm text-red-600 dark:text-red-400"><FaIcon @icon="triangle-exclamation" class="mr-1" />{{t "fleet-ops.ui.financeiro.saque-pix.validacao.sem-resposta"}}</p>
            {{/if}}
        </div>
    {{else}}
        <p class="text-xs text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.saque-pix.validacao.conecte-antes"}}</p>
    {{/if}}
</section>
```

- [ ] **Step 3: Reexport para o console**

Crie `packages/fleetops/app/components/financeiro/saque-pix/validacao.js`:

```js
export { default } from '@fleetbase/fleetops-engine/components/financeiro/saque-pix/validacao';
```

- [ ] **Step 4: Conferir**

```bash
parse_js packages/fleetops/addon/components/financeiro/saque-pix/validacao.js packages/fleetops/app/components/financeiro/saque-pix/validacao.js
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/financeiro-saque-pix.test.mjs
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit $?"
```

Expected: dois `ok`, `# fail 0`, `exit 0`.

- [ ] **Step 5: Commit**

```bash
git rev-parse --show-toplevel
git add packages/fleetops/addon/components/financeiro/saque-pix/validacao.js packages/fleetops/addon/components/financeiro/saque-pix/validacao.hbs packages/fleetops/app/components/financeiro/saque-pix/validacao.js
git commit -m "Card Saque Pix, passo 2: URL, token mostrado uma vez, passo a passo do Asaas, lista de IPs e teste da validação

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Passo 3, ligar o saque e os limites

**Files:**
- Create: `packages/fleetops/addon/components/financeiro/saque-pix/ligar.js`
- Create: `packages/fleetops/app/components/financeiro/saque-pix/ligar.js`
- Modify (troca o conteúdo): `packages/fleetops/addon/components/financeiro/saque-pix/ligar.hbs`

- [ ] **Step 1: Componente**

Crie `packages/fleetops/addon/components/financeiro/saque-pix/ligar.js`:

```js
import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { formatadores, valorInterpretado } from '../../../utils/financeiro';
import { API_DO_SAQUE_PIX, limitesParaEdicao, validarLimites } from '../../../utils/financeiro-saque-pix';

const P = 'fleet-ops.ui.financeiro.saque-pix';

/**
 * Entregas RestaurantePro: passo 3 do card Saque Pix. Interruptor do saque (travado até a validação confirmada; desligar
 * sempre pode) e os limites da central: mínimo por saque, máximo por dia, saques por dia e taxa. PUT
 * int/v1/entregas/financeiro/saque-pix (SaquePixController@configurar): o interruptor vai sozinho, os limites pelo
 * "Salvar limites", com as mesmas regras do servidor (validarLimites).
 */
export default class FinanceiroSaquePixLigarComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;

    @tracked campos;

    constructor() {
        super(...arguments);
        this.campos = limitesParaEdicao(this.args.conexao?.limites);
    }

    get ligado() {
        return this.args.conexao?.saque_ligado === true;
    }

    get validacao() {
        return validarLimites(this.campos);
    }

    get erro() {
        const codigo = this.validacao.erro;
        return codigo ? this.intl.t(`${P}.erros.${codigo}`) : null;
    }

    /** "= R$ 1.500,00" ao lado do campo (vazio enquanto o campo é inválido). */
    @action interpretado(valor) {
        return valorInterpretado(valor, formatadores(this.intl.primaryLocale));
    }

    @action setCampo(campo, event) {
        this.campos = { ...this.campos, [campo]: event.target.value };
    }

    /** O Toggle chama com o estado novo. */
    @action ligar(ligado) {
        this.salvarLigado.perform(ligado);
    }

    @task({ drop: true }) *salvarLigado(ligado) {
        try {
            const conexao = yield this.fetch.put(API_DO_SAQUE_PIX, { saque_ligado: ligado === true });
            this.args.aoMudar(conexao);
            this.notifications.success(this.intl.t(conexao?.saque_ligado ? `${P}.ligar.ligado` : `${P}.ligar.desligado`));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *salvarLimites() {
        const { erro, corpo } = this.validacao;
        if (erro) {
            this.notifications.warning(this.erro);
            return;
        }
        try {
            const conexao = yield this.fetch.put(API_DO_SAQUE_PIX, corpo);
            this.args.aoMudar(conexao);
            this.campos = limitesParaEdicao(conexao?.limites);
            this.notifications.success(this.intl.t(`${P}.ligar.salvos`));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
```

- [ ] **Step 2: Template**

Troque o conteúdo de `packages/fleetops/addon/components/financeiro/saque-pix/ligar.hbs` por:

```hbs
<section class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-3 {{unless @etapas.conectada 'opacity-60'}}">
    <header class="flex flex-wrap items-center gap-2">
        <span class="text-xs uppercase text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.saque-pix.passo" numero=3}}</span>
        <h3 class="text-sm font-semibold dark:text-white">{{t "fleet-ops.ui.financeiro.saque-pix.ligar.titulo"}}</h3>
        {{#if @etapas.ligado}}
            <Badge @status="success" @hideStatusDot={{true}}>{{t "fleet-ops.ui.financeiro.saque-pix.feito"}}</Badge>
        {{/if}}
    </header>

    {{#if @etapas.conectada}}
        <Toggle
            @isToggled={{this.ligado}}
            @disabled={{or (not @etapas.podeLigar) this.salvarLigado.isRunning}}
            @onToggle={{this.ligar}}
            @label={{t "fleet-ops.ui.financeiro.saque-pix.ligar.interruptor"}}
        />
        {{#unless @etapas.podeLigar}}
            <p class="text-xs text-yellow-700 dark:text-yellow-300">{{t "fleet-ops.ui.financeiro.saque-pix.ligar.travado"}}</p>
        {{/unless}}

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <InputGroup @name={{t "fleet-ops.ui.financeiro.saque-pix.ligar.minimo"}} @wrapperClass="mb-0" as |id|>
                <input id={{id}} type="text" inputmode="decimal" autocomplete="off" class="form-input form-input-sm w-full" value={{this.campos.minimo}} {{on "input" (fn this.setCampo "minimo")}} />
                {{#if (this.interpretado this.campos.minimo)}}
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.valor-interpretado" valor=(this.interpretado this.campos.minimo)}}</p>
                {{/if}}
            </InputGroup>
            <InputGroup @name={{t "fleet-ops.ui.financeiro.saque-pix.ligar.maximo-dia"}} @wrapperClass="mb-0" as |id|>
                <input id={{id}} type="text" inputmode="decimal" autocomplete="off" class="form-input form-input-sm w-full" value={{this.campos.maximo_dia}} {{on "input" (fn this.setCampo "maximo_dia")}} />
                {{#if (this.interpretado this.campos.maximo_dia)}}
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.valor-interpretado" valor=(this.interpretado this.campos.maximo_dia)}}</p>
                {{/if}}
            </InputGroup>
            <InputGroup @name={{t "fleet-ops.ui.financeiro.saque-pix.ligar.saques-dia"}} @wrapperClass="mb-0" as |id|>
                <input id={{id}} type="number" min="1" max="20" step="1" inputmode="numeric" class="form-input form-input-sm w-full" value={{this.campos.saques_dia}} {{on "input" (fn this.setCampo "saques_dia")}} />
            </InputGroup>
            <InputGroup @name={{t "fleet-ops.ui.financeiro.saque-pix.ligar.taxa"}} @helpText={{t "fleet-ops.ui.financeiro.saque-pix.ligar.taxa-ajuda"}} @wrapperClass="mb-0" as |id|>
                <input id={{id}} type="text" inputmode="decimal" autocomplete="off" class="form-input form-input-sm w-full" value={{this.campos.taxa}} {{on "input" (fn this.setCampo "taxa")}} />
                {{#if (this.interpretado this.campos.taxa)}}
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.valor-interpretado" valor=(this.interpretado this.campos.taxa)}}</p>
                {{/if}}
            </InputGroup>
        </div>
        {{#if this.erro}}
            <p class="text-xs text-yellow-700 dark:text-yellow-300">{{this.erro}}</p>
        {{/if}}
        <div>
            <Button
                @type="primary"
                @size="sm"
                @icon="save"
                @text={{t "fleet-ops.ui.financeiro.saque-pix.ligar.salvar"}}
                @disabled={{this.erro}}
                @isLoading={{this.salvarLimites.isRunning}}
                @onClick={{perform this.salvarLimites}}
            />
        </div>
    {{else}}
        <p class="text-xs text-gray-500 dark:text-gray-400">{{t "fleet-ops.ui.financeiro.saque-pix.validacao.conecte-antes"}}</p>
    {{/if}}
</section>
```

- [ ] **Step 3: Reexport para o console**

Crie `packages/fleetops/app/components/financeiro/saque-pix/ligar.js`:

```js
export { default } from '@fleetbase/fleetops-engine/components/financeiro/saque-pix/ligar';
```

- [ ] **Step 4: Conferir**

```bash
parse_js packages/fleetops/addon/components/financeiro/saque-pix/ligar.js packages/fleetops/app/components/financeiro/saque-pix/ligar.js
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit $?"
```

Expected: dois `ok` e `exit 0`.

- [ ] **Step 5: Commit**

```bash
git rev-parse --show-toplevel
git add packages/fleetops/addon/components/financeiro/saque-pix/ligar.js packages/fleetops/addon/components/financeiro/saque-pix/ligar.hbs packages/fleetops/app/components/financeiro/saque-pix/ligar.js
git commit -m "Card Saque Pix, passo 3: interruptor travado até a validação e limites (mínimo, máximo/dia, saques/dia, taxa)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Extrato do motoboy (interruptor, carência, selo e comprovante do saque)

**Files:**
- Modify: `packages/fleetops/addon/controllers/financeiro/motoboy.js`
- Modify: `packages/fleetops/addon/templates/financeiro/motoboy.hbs`

O botão Estornar some sozinho do pagamento de saque (`podeEstornar`, Task 2).

- [ ] **Step 1: Controller**

Em `packages/fleetops/addon/controllers/financeiro/motoboy.js`:

1. Troque `import { selosDaCorrida } from '../../utils/financeiro-multiplicadores';` por:

```js
import { selosDaCorrida } from '../../utils/financeiro-multiplicadores';
import { avisoDeCarencia, linkSeguro, seloDoSaque } from '../../utils/financeiro-saque-pix';
```

2. No docblock da classe, troque ` * servidor com a conta inteira), Registrar pagamento, Lançar, Estornar por linha e CSV.` por:

```js
 * servidor com a conta inteira), Registrar pagamento, Lançar, Estornar por linha e CSV. Saque Pix pelo app: o interruptor
 * "Saque liberado / bloqueado" e o aviso de carência depois da troca da chave (bloco `saque` do extrato, só com o saque
 * ligado na central) e o selo "Saque Pix · app" com a situação e o comprovante do Asaas no pagamento de saque.
```

3. Troque:

```js
    get grupos() {
        return agruparPorDia(this.extrato?.lancamentos);
    }
```

por:

```js
    get grupos() {
        return agruparPorDia(this.extrato?.lancamentos);
    }

    /** {bloqueado, liberado_em, alterada_por} do saque Pix; null com o saque desligado na central (sem interruptor). */
    get saque() {
        return this.extrato?.saque ?? null;
    }

    get carencia() {
        return avisoDeCarencia(this.saque);
    }
```

4. Troque:

```js
    @action selos(detalhes) {
        return selosDaCorrida(detalhes, this.intl.primaryLocale);
    }
```

por:

```js
    @action selos(detalhes) {
        return selosDaCorrida(detalhes, this.intl.primaryLocale);
    }

    /** Selo "Saque Pix · app" do pagamento de saque: {status do Badge, chave da situação}. */
    @action seloDoSaque(saque) {
        return seloDoSaque(saque);
    }

    @action linkSeguro(url) {
        return linkSeguro(url);
    }

    /** O Toggle chama com o estado novo: ligado = saque liberado. */
    @action alternarSaque(liberado) {
        this.salvarSaque.perform(liberado);
    }
```

5. Logo depois da task `salvarPix` (o último `}` antes do `}` que fecha a classe), acrescente:

```js

    @task({ drop: true }) *salvarSaque(liberado) {
        try {
            yield this.fetch.put(`${ENDPOINT}/${this.motoboyId}/saque`, { bloqueado: liberado !== true });
            this.notifications.success(this.intl.t(`fleet-ops.ui.financeiro.saque-pix.motoboys.${liberado ? 'salvo-liberado' : 'salvo-bloqueado'}`, { nome: this.nome }));
            yield this.carregar.perform();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
```

- [ ] **Step 2: Interruptor e carência junto da chave Pix**

Em `packages/fleetops/addon/templates/financeiro/motoboy.hbs`, troque:

```hbs
                        <div class="mt-1 text-sm text-yellow-700 dark:text-yellow-300">{{t "fleet-ops.ui.financeiro.motoboys.sem-pix"}}</div>
                        <div class="mt-2">
                            <Button @size="xs" @icon="plus" @text={{t "fleet-ops.ui.financeiro.pix.cadastrar"}} @onClick={{this.abrirPix}} />
                        </div>
                    {{/if}}
                </div>
```

por:

```hbs
                        <div class="mt-1 text-sm text-yellow-700 dark:text-yellow-300">{{t "fleet-ops.ui.financeiro.motoboys.sem-pix"}}</div>
                        <div class="mt-2">
                            <Button @size="xs" @icon="plus" @text={{t "fleet-ops.ui.financeiro.pix.cadastrar"}} @onClick={{this.abrirPix}} />
                        </div>
                    {{/if}}
                    {{! saque Pix pelo app: só com o saque ligado na central (o servidor manda extrato.saque) }}
                    {{#if this.saque}}
                        <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700 space-y-1">
                            <Toggle
                                @isToggled={{not this.saque.bloqueado}}
                                @disabled={{this.salvarSaque.isRunning}}
                                @onToggle={{this.alternarSaque}}
                                @label={{if this.saque.bloqueado (t "fleet-ops.ui.financeiro.saque-pix.motoboys.bloqueado") (t "fleet-ops.ui.financeiro.saque-pix.motoboys.liberado")}}
                            />
                            {{#if this.carencia}}
                                <p class="text-xs text-yellow-700 dark:text-yellow-300">{{t this.carencia.chave hora=this.carencia.params.hora dia=this.carencia.params.dia nome=this.carencia.params.nome}}</p>
                            {{/if}}
                        </div>
                    {{/if}}
                </div>
```

- [ ] **Step 3: Selo e comprovante no pagamento de saque**

No mesmo arquivo, troque:

```hbs
                                                    {{else if lancamento.pagamento.tem_comprovante}}
                                                        <span class="text-gray-500 dark:text-gray-400"><FaIcon @icon="paperclip" class="mr-1" />{{t "fleet-ops.ui.financeiro.extrato.comprovante"}}</span>
                                                    {{/if}}
                                                {{/if}}
```

por:

```hbs
                                                    {{else if lancamento.pagamento.tem_comprovante}}
                                                        <span class="text-gray-500 dark:text-gray-400"><FaIcon @icon="paperclip" class="mr-1" />{{t "fleet-ops.ui.financeiro.extrato.comprovante"}}</span>
                                                    {{/if}}
                                                    {{! saque Pix pelo app: a situação do saque e o comprovante do Asaas }}
                                                    {{#if lancamento.pagamento.saque}}
                                                        {{#let (this.seloDoSaque lancamento.pagamento.saque) as |selo|}}
                                                            <Badge @status={{selo.status}} @hideStatusDot={{true}}>{{t "fleet-ops.ui.financeiro.saque-pix.selo-app"}} · {{t selo.chave}}</Badge>
                                                        {{/let}}
                                                        {{#if (this.linkSeguro lancamento.pagamento.saque.comprovante_url)}}
                                                            <a href={{this.linkSeguro lancamento.pagamento.saque.comprovante_url}} target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 hover:underline">
                                                                <FaIcon @icon="receipt" class="mr-1" />{{t "fleet-ops.ui.financeiro.saque-pix.comprovante-asaas"}}
                                                            </a>
                                                        {{/if}}
                                                    {{/if}}
                                                {{/if}}
```

- [ ] **Step 4: Conferir**

```bash
parse_js packages/fleetops/addon/controllers/financeiro/motoboy.js
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/financeiro.test.mjs scripts/teste-portal/financeiro-saque-pix.test.mjs
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit $?"
```

Expected: `ok`, `# fail 0`, `exit 0`.

- [ ] **Step 5: Commit**

```bash
git rev-parse --show-toplevel
git add packages/fleetops/addon/controllers/financeiro/motoboy.js packages/fleetops/addon/templates/financeiro/motoboy.hbs
git commit -m "Extrato do motoboy: interruptor do saque Pix, carência da troca da chave e selo do pagamento de saque

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: Lista de motoboys, selo "saque bloqueado"

**Files:**
- Modify: `packages/fleetops/addon/templates/financeiro/motoboys.hbs`

- [ ] **Step 1: Selo**

Em `packages/fleetops/addon/templates/financeiro/motoboys.hbs`, troque:

```hbs
                                    {{#if (this.excluido motoboy)}}
                                        <span class="ml-1 px-1.5 py-0.5 rounded text-xs font-semibold bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200" title={{t "fleet-ops.ui.financeiro.motoboys.excluido-ajuda"}}>{{t "fleet-ops.ui.financeiro.motoboys.excluido"}}</span>
                                    {{/if}}
```

por:

```hbs
                                    {{#if (this.excluido motoboy)}}
                                        <span class="ml-1 px-1.5 py-0.5 rounded text-xs font-semibold bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200" title={{t "fleet-ops.ui.financeiro.motoboys.excluido-ajuda"}}>{{t "fleet-ops.ui.financeiro.motoboys.excluido"}}</span>
                                    {{/if}}
                                    {{! saque Pix pelo app bloqueado pela central (o servidor manda saque_bloqueado só com o saque ligado) }}
                                    {{#if (eq motoboy.saque_bloqueado true)}}
                                        <span class="ml-1 px-1.5 py-0.5 rounded text-xs font-semibold bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-100" title={{t "fleet-ops.ui.financeiro.saque-pix.selo-bloqueado-ajuda"}}>{{t "fleet-ops.ui.financeiro.saque-pix.selo-bloqueado"}}</span>
                                    {{/if}}
```

- [ ] **Step 2: Conferir**

Run: `node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit $?"`
Expected: `exit 0`.

- [ ] **Step 3: Commit**

```bash
git rev-parse --show-toplevel
git add packages/fleetops/addon/templates/financeiro/motoboys.hbs
git commit -m "Financeiro → Motoboys: selo \"saque bloqueado\"

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 13: Conferência geral e build do console

**Files:** nenhum novo (correções, se houver, com o teste do assunto).

- [ ] **Step 1: Todos os testes**

```bash
for t in saque-console financeiro-console saque-conexao saque-controllers saque-financeiro saque-pix-alterada saque-implantacao imports-dos-providers; do
  echo "== $t"; PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php | tail -1
done
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit $?"
parse_js packages/fleetops/addon/utils/financeiro.js packages/fleetops/addon/utils/financeiro-saque-pix.js packages/fleetops/addon/routes.js packages/fleetops/addon/routes/financeiro.js packages/fleetops/addon/routes/financeiro/index.js packages/fleetops/addon/routes/financeiro/saques.js packages/fleetops/addon/controllers/financeiro/saques.js packages/fleetops/addon/controllers/financeiro/configuracoes.js packages/fleetops/addon/controllers/financeiro/motoboy.js packages/fleetops/addon/components/layout/fleet-ops-sidebar.js packages/fleetops/addon/components/financeiro/saque-pix.js packages/fleetops/addon/components/financeiro/saque-pix/conta.js packages/fleetops/addon/components/financeiro/saque-pix/validacao.js packages/fleetops/addon/components/financeiro/saque-pix/ligar.js console/app/components/doca-celular.js
node scripts/paleta-entregas.mjs --conferir; echo "paleta exit $?"
```

Expected: oito `FALHAS: 0`, `# fail 0`, `exit 0`, quinze `ok` e `paleta exit 0` (nenhuma cor fixa nova em CSS).

- [ ] **Step 2: Build local do console** (~10 min, Windows, `console/CLAUDE.md`)

```bash
cd /c/tmp/saque/console && DISABLE_RUNTIME_CONFIG=false pnpm build --environment production
```

Expected: termina sem erro (`Built project successfully`). Erro de resolução de componente (`Financeiro::SaquePix::…`) = reexport faltando em `packages/fleetops/app/components/financeiro/`.

- [ ] **Step 3: Conferência na tela antes do deploy**

1. Grave `console/dist/fleetbase.config.json` com `API_HOST=https://entregas-api.restaurantepro.com.br` (como em `console/CLAUDE.md`).
2. `npx serve -s dist -l 4200` dentro de `console/` e faça login em `http://localhost:4200`.

A API de produção **ainda não tem** as rotas do saque: o card mostra o aviso de erro do servidor e a tela Saques Pix fica vazia. Confira o que não depende delas:
- menu do Fleet-Ops: "Saques Pix" entre Motoboys e Lojas e faturas; ícone certo;
- `/fleet-ops/financeiro` abre o Painel (admin do sistema);
- Configurações: corte e ciclo como antes, o card "Saque Pix (Asaas)" embaixo;
- claro e escuro, sem azul (links e botões no laranja da marca);
- 390 px de largura (DevTools): o card, a tabela de saques (rola de lado dentro do cartão) e a folha "Mais" da doca com "Saques Pix".

- [ ] **Step 4: Conferência com a API (no ensaio do sandbox, Task 16 do plano 1)**

Depois do deploy da API e do console com o sandbox ligado (`ASAAS_API_URL=https://api-sandbox.asaas.com/v3`, `ENTREGAS_SAQUE=1`, `ENTREGAS_IP_DE_SAIDA` preenchido), confira com o Edgard, nesta ordem:
1. Configurações → card: conectar com senha errada (aviso "Senha incorreta."), com a chave do sandbox e a senha certa (nome, CPF/CNPJ escondido e wallet aparecem; a chave some do campo).
2. Gerar token: aparece uma vez com o aviso; recarregar a página mostra "Token já gerado". "Gerar outro token" pede confirmação.
3. Passo a passo e lista de IPs com o IP da VPS.
4. Testar validação com uma chave Pix do Edgard: contagem regressiva e "Recusada pelo Entregas ✓". Com o token errado no Asaas, depois de 60 s, "O Asaas não chamou o Entregas…".
5. Interruptor travado antes do teste; liga depois; limites inválidos mostram o erro e o botão fica apagado; salvar mostra "Limites salvos.".
6. Saque de teste pelo APK do plano 3: a tela Saques Pix mostra o saque (Processando → Concluído, com "Ver no Asaas"); o extrato do motoboy mostra o selo "Saque Pix · app · Concluído", o comprovante do Asaas e nenhum botão Estornar nessa linha.
7. Trocar a chave Pix do motoboy no extrato: aparece "Saque liberado às HH:MM de DD/MM · chave trocada por Edgard". Bloquear pelo extrato e pela tela Saques Pix: o selo "saque bloqueado" aparece na lista de motoboys.
8. Saque que falha (sem saldo no sandbox): aviso no Painel → Atenção, que abre Saques Pix filtrada em "Falhou".
9. Com um usuário de teste com papel Administrador na organização (sem ser admin do sistema): o menu do Financeiro mostra só Saques Pix e Configurações; as Configurações mostram só o card; `/fleet-ops/financeiro/painel` digitado na barra de endereço volta para a Operação com o aviso.

- [ ] **Step 5: Commit das correções (se houver)**

Cada correção com o teste do assunto e um commit próprio, com a linha `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

---

### Task 14: Documentação

**Files:**
- Modify: `packages/fleetops/CLAUDE.md`, `api/app/Support/Entregas/Saques/CLAUDE.md`, `console/CLAUDE.md`, `docs/historico.md`, `docs/superpowers/specs/2026-10-26-saque-pix-asaas-design.md`

- [ ] **Step 1: `packages/fleetops/CLAUDE.md`**

1. No item **Rotas** do Financeiro, troque `` `painel`, `motoboys`, `motoboy` (`/motoboys/:id`, public_id do Driver), `` por `` `painel`, `motoboys`, `motoboy` (`/motoboys/:id`, public_id do Driver), `saques` (Saques Pix), `` e, no fim do mesmo item, acrescente: ` Exceção: o admin da organização (papel Administrator) entra em \`saques\` e \`configuracoes\` (\`rotaDoAdminDaCentral\`); \`financeiro.index\` o manda para \`saques\`.`
2. No item **Menu**, troque `Painel, Motoboys, Lojas e faturas,` por `Painel, Motoboys, Saques Pix (\`money-bill-transfer\`, também para o admin da organização), Lojas e faturas,` e acrescente ao fim do item: ` Configurações também aparece para o admin da organização (só com o card do saque).`
3. Depois do item **Corridas do sistema**, acrescente o item:

```markdown
  - **Saque Pix pelo app** (decisão de 2026-10-26; servidor em `api/app/Support/Entregas/Saques/CLAUDE.md`; planos `docs/superpowers/plans/2026-10-26-saque-pix-asaas-api.md` e `…-console.md`). Quem configura: o admin do sistema **e o da organização** (`ehAdminDaCentral`: `currentUser.isAdmin` ou `roleName === 'Administrator'`, o nome gravado do papel, como o `AdminDaCentral` da API).
    - **Configurações → card "Saque Pix (Asaas)"** (`components/financeiro/saque-pix.{js,hbs}` + `saque-pix/{conta,validacao,ligar}`, reexports em `app/components/financeiro/`): passo 1 conecta com a chave de API e a senha (troca só pela chave da mesma conta; desconectar pede a senha); passo 2 mostra a URL, o token (só uma vez, só na memória da tela), o passo a passo do Asaas e a lista de IPs com o `ip_de_saida` (env `ENTREGAS_IP_DE_SAIDA`), e o "Testar validação" relê `GET financeiro/saque-pix` a cada 3 s por até 60 s; passo 3 tem o interruptor (travado até `validacao_confirmada_em`) e os limites (`validarLimites`, as regras do servidor). O admin da organização vê só o card (o controller nem chama `financeiro/configuracoes`).
    - **Saques Pix** (`financeiro.saques`, `?situacao=`): saques dos últimos 30 dias (situação em `Badge`, motivo da falha, "Ver no Asaas") e os motoboys com chave Pix com o interruptor de bloqueio (`PUT financeiro/motoboys/{id}/saque`). O nome abre o extrato só para o admin do sistema.
    - **Extrato do motoboy:** bloco `saque` do extrato do console (`SaqueNoConsole`, só com o saque ligado e a conta conectada): interruptor "Saque liberado / bloqueado" junto da chave Pix e a carência ("Saque liberado às 14:30 de 27/10 · chave trocada por Fulano"). Pagamento de saque (`pagamento.saque`): selo "Saque Pix · app · <situação>", link do comprovante do Asaas e **sem Estornar** (`podeEstornar`).
    - **Motoboys:** selo "saque bloqueado" (`saque_bloqueado`). **Painel → Atenção:** `saque_falhou` e `saque_parado`, que abrem Saques Pix filtrada (`itemDeAtencao` devolve `modelo: null` e `consulta`; o template tem o ramo com `@query`, porque o `LinkTo` com `@model` vazio quebra a tela).
    - **Hora:** tudo pela hora escrita no ISO (`quandoDoIso` em `utils/financeiro.js`). **Links do Asaas:** só `https://` (`linkSeguro`).
    - **Funções puras:** `addon/utils/financeiro-saque-pix.js` (teste `scripts/teste-portal/financeiro-saque-pix.test.mjs`). **Textos:** `fleet-ops.ui.financeiro.saque-pix.*`, `fleet-ops.ui.financeiro.menu.saques`, `fleet-ops.ui.financeiro.painel.atencao.saque_*` e `console.ui.celular.mais.saques-pix`. Chaves montadas no JS (`situacoes.*`, `erros.*`, `motoboys.salvo-*`, `carencia*`, os avisos do JS do card): confira à mão nas duas línguas.
```

- [ ] **Step 2: `api/app/Support/Entregas/Saques/CLAUDE.md`**

Logo antes da seção `## Testes`, acrescente:

```markdown
## Console (plano 2)

- Telas: ver "Saque Pix pelo app" em `packages/fleetops/CLAUDE.md`.
- `SaqueNoConsole` (só com `ENTREGAS_SAQUE` ligada e a conta conectada; sem a tabela, nada): `doMotoboy` → bloco `saque` `{bloqueado, liberado_em, alterada_por}` do extrato do console (`FinanceiroController@extratoDoMotoboy`; `alterada_por` é o nome de quem trocou a chave, só durante a carência); `bloqueados` → `saque_bloqueado` da lista `GET financeiro/motoboys`.
- `ConexaoAsaas::publico` traz `ip_de_saida` (`services.entregas.ip_de_saida` = env `ENTREGAS_IP_DE_SAIDA`, no `stack.env`): o IP fixo da VPS que o card mostra para a lista de IPs do Asaas. Vazio = o card pede o IP a quem administra.
- Teste: `saque-console.php`.
```

E na lista da seção `## Testes`, acrescente `` `saque-console` `` depois de `` `saque-implantacao` ``.

- [ ] **Step 3: `console/CLAUDE.md`**

Na seção "Console no celular", no item **Mais**, troque `(lista fixa no getter \`grupos\`; o Financeiro e Lojas só para o admin)` por `(lista fixa no getter \`grupos\`; o Financeiro e Lojas só para o admin, menos Saques Pix, que o admin da organização também vê: \`ehAdminDaCentral\` do \`doca-celular.js\`, cópia da regra do fleetops)`.

- [ ] **Step 4: `docs/historico.md`**

Acrescente o item seguinte da lista (número = o último + 1), no formato dos outros:

```markdown
N. Saque Pix no console (2026-10-26, plano 2 do saque Pix): card "Saque Pix (Asaas)" em Financeiro → Configurações (conectar com senha, token mostrado uma vez, passo a passo do Asaas e lista de IPs, teste da validação, interruptor e limites), tela Saques Pix (últimos 30 dias, comprovante, bloqueio por motoboy), interruptor e carência no extrato, selo do pagamento de saque, selo "saque bloqueado" na lista e avisos no painel. O admin da organização entra em Saques Pix e no card (ver "Saque Pix pelo app" em `packages/fleetops/CLAUDE.md`).
```

(troque o `N` pelo número).

- [ ] **Step 5: Spec**

Em `docs/superpowers/specs/2026-10-26-saque-pix-asaas-design.md`, no fim da subseção "### Console" de "## Telas", acrescente:

```markdown
- Decisões do plano 2 (console): a "aba Saques" é a tela própria "Saques Pix" no menu do Financeiro; o extrato do console traz o bloco `saque` (`{bloqueado, liberado_em, alterada_por}`, `SaqueNoConsole`) para a carência com o nome de quem trocou; o IP da VPS vem de `ENTREGAS_IP_DE_SAIDA` (`ip_de_saida` na conexão); o admin da organização vê no console só Saques Pix e o card das Configurações.
```

- [ ] **Step 6: Commit**

```bash
git rev-parse --show-toplevel
git add packages/fleetops/CLAUDE.md api/app/Support/Entregas/Saques/CLAUDE.md console/CLAUDE.md docs/historico.md docs/superpowers/specs/2026-10-26-saque-pix-asaas-design.md
git commit -m "Saque Pix no console: documentação (Fleet-Ops, Saques, console, histórico e spec)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 7: Implantação**

Nada sobe sozinho. A implantação é a Task 16 do plano 1, que acontece depois dos planos 2 e 3. Antes dela, o Edgard põe `ENTREGAS_IP_DE_SAIDA=<IP da VPS>` no `stack.env` (Stacks → entregas → Editor → Update the stack, "Re-pull image" desligado) e roda `bash deploy/atualizar.sh api` e `bash deploy/atualizar.sh console` na VPS. Depois de cada deploy do console, abrir com Ctrl+Shift+R.
