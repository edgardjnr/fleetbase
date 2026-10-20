# Produtos do pedido no servidor (iFood e RestaurantePro): plano de implementação

> **Para agentes:** SUB-SKILL OBRIGATÓRIA: use superpowers:subagent-driven-development (recomendado) ou
> superpowers:executing-plans para executar este plano tarefa a tarefa. Os passos usam caixas (`- [ ]`) para
> acompanhamento.

**Objetivo:** guardar os produtos dos pedidos do iFood e do RestaurantePro e entregá-los ao app do motoboy no campo
`itens` das rotas `GET v1/entregas/motoboy/pedidos/{id}/ifood` e `.../integracao`.

**Arquitetura:** uma classe pura nova, `App\Support\Entregas\ItensDoPedido`, normaliza a lista no formato do app,
grava o JSON e o lê de volta com defesa contra JSON quebrado. Os dois lados a usam: o `PedidoDoIfood::mapear` converte
os `items` do Logistics e põe o JSON na linha que o criador já grava; o `PedidoDaIntegracao::validar` normaliza o campo
opcional `itens` sem nunca recusar o pedido; as duas respostas ao app leem a coluna nova `itens` (JSON, nula) das
tabelas `entregas_ifood_pedidos` e `entregas_integracao_pedidos`. No RestaurantePro, uma função pura nova
(`itensDoPedido`, em `_shared/entregas.ts`) monta os `itens` a partir de `orders.items`, e a `entregas-tick` os envia
no `POST pedidos`.

**Tecnologias:** Laravel (PHP 8.2) em `api/app`, testes em php-wasm (`scripts/teste-php/`); Supabase Edge Functions em
Deno/TypeScript no `restaurantepro-main`, testes `deno test` e o smoke em Node.

Spec: `docs/superpowers/specs/2026-10-19-produtos-do-pedido-servidor-design.md`. App (outro plano):
`docs/superpowers/specs/2026-10-19-detalhe-do-pedido-app-design.md`.

---

## Antes de começar

### O que foi conferido no código (2026-10-19)

- **iFood:** `PedidoDoIfood::mapear` (`api/app/Support/Entregas/Ifood/PedidoDoIfood.php:71-158`) devolve
  `['numero', 'teste', 'agendado', 'sem_coordenadas', 'sem_janela', 'despachar_agora', 'scheduled_at', 'notas',
  'entrega' => [...], 'linha' => [...]]`. O `CriadorDoPedidoIfood::criar` grava `$dados['linha'] + [ids e datas]`
  com `DB::table('entregas_ifood_pedidos')->insert(...)` dentro da transação
  (`CriadorDoPedidoIfood.php:136-145`). **Basta pôr `itens` (o JSON) na `linha`**: o criador grava sem mudar uma linha
  de código. A tarefa do criador é só de teste.
- **Formato do Logistics confirmado:** as respostas guardadas pela sonda de 2026-10-05
  (`deploy/ifood-sonda/pedidos/*-logistics.json`, pasta ignorada pelo git) trazem
  `items[{index, name, quantity, options[{name, quantity}]}]`, com `name` preenchido nos pedidos de teste (ex.:
  `"PRODUTO 2 (COMBO) - NÃO ENTREGAR - Primeiro Nível"` com 4 `options`). O Logistics **não** traz observação por item
  (só o módulo Order traz `observations`, e vazio). As `customizations` de terceiro nível do módulo Order não vêm no
  Logistics.
- **Integração:** `PedidoDaIntegracao::validar` (`api/app/Support/Entregas/Integracao/PedidoDaIntegracao.php:21-69`)
  devolve `['erros' => [...], 'dados' => [...]]`; o `IntegracaoController::criar` (`api/app/Http/Controllers/Entregas/
  IntegracaoController.php:55-74`) responde 422 se houver erro. O `CriadorDoPedidoDaIntegracao::gravar` monta o insert
  campo a campo (`CriadorDoPedidoDaIntegracao.php:189-207`); o reenvio sai antes, no `existente()` (linha 52).
- **Respostas ao app:** `DadosIfoodDoMotoboy::resposta` (`Ifood/DadosIfoodDoMotoboy.php:21-61`) e
  `DadosDaIntegracaoDoMotoboy::resposta` (`Integracao/DadosDaIntegracaoDoMotoboy.php:15-43`) recebem a linha lida com
  `select *` (`PedidosIfood::doPedido`, `PedidosDaIntegracao::doPedido`). As rotas (`MotoboyController::ifood` e
  `::integracao`, `api/app/Http/Controllers/Entregas/MotoboyController.php:233-261`) já filtram quem pode ler pelo
  `pedidoQuePodeVer` (linhas 338-347: pedido dele ou aberto). Os itens vão a quem passa por esse filtro.
- **Banco em memória dos testes:** `stubs-ifood.php:547-621` lê as colunas de todas as migrations
  `*_entregas_ifood_*_table.php` e `*_entregas_integracao_*_table.php`. As migrations novas entram sozinhas pelo nome; o
  banco recusa coluna que não existe, então o insert com `itens` só passa depois da migration (Task 2 vem antes da 3).
- **RestaurantePro:** o spec cita a edge `entregas-delivery`, mas ela só cuida de status, conectar, desconectar e
  "Tentar de novo". **Quem faz o `POST pedidos` é a `entregas-tick`** (`supabase/functions/entregas-tick/index.ts:171-250`),
  com o corpo montado por `corpoDoPedido` em `supabase/functions/_shared/entregas.ts:155-193`, e o pedido lido em
  `getOrder` (`entregas-tick/index.ts:137-147`, `select` sem `items`).
- **Produtos no RestaurantePro:** coluna `orders.items` (lida como lista pelo PostgREST, como na `foody-tick`:
  `Array.isArray(order.items)`), gravada pela edge `public-order` (`supabase/functions/public-order/index.ts:300-307`
  e o insert nas linhas 461-473), no formato `OrderItem` de `types/products.ts:64-79`:
  `{productId, productName, category, quantity, price, selectedAddons: [{addonGroupId, addonGroupName,
  options: [{id, name, price, quantity}]}]}`. Adicionais **e complementos** moram em `selectedAddons` (decisão de
  2026-08-23, ver `buildOrderDetails` em `_shared/foody.ts:67-78`). **Não existe observação por item**: só a do pedido
  (`orders.observation`), que já vai em `observacoes`. O `CREATE TABLE orders` não está nas migrations do repositório
  (só `ALTER`s): a Task 11 confere o esquema no banco, só com leitura.

### Decisões tomadas neste plano (o spec deixava em aberto)

- **Texto longo é cortado, não descartado:** `nome` em 120 e `observacao` em 200 caracteres (`mb_substr`), como o
  `PedidoDoIfood::texto` e o `corte` do RestaurantePro já fazem. Item sem nome continua não entrando.
- **Quantidade inválida ou ausente vale 1** (no item e na opção). O app soma as quantidades ("4 itens") e precisa do
  campo; "o campo cai" deixaria o item sem quantidade.
- **Ordem do `index` só no iFood** (`PedidoDoIfood::itens`); a integração mantém a ordem recebida.
- **A resposta à origem (`IntegracaoController::recurso`) não muda**: os itens não voltam no 201/200.

### Regras que valem em todas as tarefas

- Responda e documente em português do Brasil. Fuso: nada aqui grava data; **não use `now()` formatado em UTC**
  (ver "Fuso (horário de Brasília)" em `api/CLAUDE.md`).
- **"Não quebrar a resposta":** os itens nunca derrubam o pedido nem a rota do app. Normalização sem exceção, leitura
  com `try/catch` e warning só com ids.
- **Delivery:** todo o trabalho na worktree `/c/tmp/pp`, ramo `produtos-do-pedido` a partir da `main`. A pasta
  principal (`C:\Users\Edgardjr\Documents\vibe coding\Delivery`) tem `CLAUDE.md` não rastreados e mudanças de outra
  pessoa (`CLAUDE.md` da raiz, `console/app/router.js`): **não commite nada lá**. Os `CLAUDE.md` por pasta só existem na
  pasta principal; leia-os de lá.
- **RestaurantePro:** worktree `/c/tmp/pp-rp`, ramo `produtos-do-pedido` a partir da `main`. A pasta principal tem
  `deno.lock` modificado, PNGs `kling_*` e `terraco/` soltos: não mexa neles.
- **Nunca `git add .` nem `git add -A`:** cada commit lista os arquivos dele.
- **Sem push e sem deploy** neste plano. A Task 15 é o roteiro para o Edgard.
- Comandos de teste (sempre na raiz da worktree):
  - PHP: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/<arquivo>.php` (imprime
    `PASSA`/`FALHA` por caso e termina com `FALHAS: <n>`);
  - sintaxe PHP: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs <arquivos>` (`OK   <arquivo>`);
  - Deno: `deno test supabase/functions/_shared/entregas_test.ts` (se aparecer `Cannot find name 'Deno'`, rode com
    `--no-config`).

## Mapa de arquivos

| Arquivo | Ação | Responsabilidade |
|---|---|---|
| `api/app/Support/Entregas/ItensDoPedido.php` | Criar | Normaliza a lista (`normalizar`), grava (`paraOBanco`) e lê (`doBanco`) a coluna `itens` |
| `api/database/migrations/2026_10_19_120000_add_itens_to_entregas_ifood_pedidos_table.php` | Criar | Coluna `itens` (JSON, nula) no iFood |
| `api/database/migrations/2026_10_19_120100_add_itens_to_entregas_integracao_pedidos_table.php` | Criar | Coluna `itens` (JSON, nula) na integração |
| `api/app/Support/Entregas/Ifood/PedidoDoIfood.php` | Modificar | `mapear` devolve `itens` e o JSON na `linha`; `itens()` converte e ordena os `items` |
| `api/app/Support/Entregas/Ifood/DadosIfoodDoMotoboy.php` | Modificar | `itens` na resposta ao app |
| `api/app/Support/Entregas/Integracao/PedidoDaIntegracao.php` | Modificar | `itens` opcional, normalizado, nunca é erro |
| `api/app/Support/Entregas/Integracao/CriadorDoPedidoDaIntegracao.php` | Modificar | Grava `itens` na linha |
| `api/app/Support/Entregas/Integracao/DadosDaIntegracaoDoMotoboy.php` | Modificar | `itens` na resposta ao app |
| `scripts/teste-php/itens-do-pedido.php` | Criar | Testes da `ItensDoPedido` |
| `scripts/teste-php/fixtures-ifood.php` | Modificar | `pedidoComProdutos()` no formato da sonda |
| `scripts/teste-php/ifood-pedido.php`, `ifood-criador.php`, `ifood-conclusao.php`, `ifood-migrations.php` | Modificar | Casos do iFood |
| `scripts/teste-php/integracao-pedido.php`, `integracao-rotas.php`, `integracao-criador.php`, `integracao-ligacoes.php`, `integracao-migrations.php` | Modificar | Casos da integração |
| `docs/superpowers/specs/2026-10-12-integracao-restaurantepro-design.md` | Modificar | Contrato do `POST pedidos` com `itens` |
| `docs/superpowers/specs/2026-10-19-produtos-do-pedido-servidor-design.md` | Modificar | Corrige a edge (`entregas-tick`) |
| `docs/superpowers/plans/2026-10-19-produtos-do-pedido-servidor.md` | Copiar para a worktree e commitar (Task 9) | Este plano |
| `api/app/Support/Entregas/Ifood/CLAUDE.md`, `api/app/Support/Entregas/Integracao/CLAUDE.md` (pasta principal) | Modificar, **sem commit** | Documentação do assunto |
| `restaurantepro-main`: `supabase/functions/_shared/entregas.ts` | Modificar | `itensDoPedido` e `itens` no `corpoDoPedido` |
| `restaurantepro-main`: `supabase/functions/_shared/entregas_test.ts` | Modificar | Testes Deno |
| `restaurantepro-main`: `supabase/functions/entregas-tick/index.ts` | Modificar | `items` no `select` do pedido |
| `restaurantepro-main`: `CLAUDE.md` | Modificar | Uma linha na seção da integração |

---

### Task 0: Worktree do Delivery e base dos testes

**Files:** nenhum.

- [ ] **Step 1: Criar a worktree**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/Delivery"
git rev-parse --show-toplevel
git worktree add /c/tmp/pp -b produtos-do-pedido main
cd /c/tmp/pp && git status --short && git log --oneline -1
```

Expected: o toplevel é `C:/Users/Edgardjr/Documents/vibe coding/Delivery`; `Preparing worktree (new branch
'produtos-do-pedido')`; `git status --short` vazio; o último commit é o da `main` (em 2026-10-19,
`1f44cc27 Specs: tela Comanda do pedido no app e produtos do pedido no servidor`, ou mais novo).

- [ ] **Step 2: Conferir a base dos testes que serão mexidos**

```bash
cd /c/tmp/pp
for t in ifood-pedido ifood-criador ifood-conclusao ifood-migrations ifood-stub-banco integracao-pedido integracao-criador integracao-rotas integracao-ligacoes integracao-migrations; do printf '%s: ' $t; PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php 2>&1 | tail -1; done
```

Expected: as 10 linhas terminam com `FALHAS: 0`. Se não existir `/c/tmp/php-wasm`, instale uma vez:
`npm i --prefix /c/tmp/php-wasm @php-wasm/node@3.1.54 @php-wasm/universal@3.1.54`.

---

### Task 1: `ItensDoPedido` (normalização, gravação e leitura)

**Files:**
- Create: `api/app/Support/Entregas/ItensDoPedido.php`
- Test: `scripts/teste-php/itens-do-pedido.php`

- [ ] **Step 1: Escrever o teste**

Crie `scripts/teste-php/itens-do-pedido.php`:

```php
<?php

// Produtos do pedido (ItensDoPedido): a normalização para o formato do app, a gravação e a leitura da coluna `itens`.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/itens-do-pedido.php

require __DIR__ . '/stubs-ifood.php';

use App\Support\Entregas\ItensDoPedido;

/** Normaliza uma lista com um item só e devolve esse item (ou null se ele caiu). */
function umItem(array $item): ?array
{
    return ItensDoPedido::normalizar([$item])[0] ?? null;
}

echo '== Formato completo' . PHP_EOL;
$completo = [['quantidade' => 1, 'nome' => 'Pizza grande Calabresa', 'opcoes' => [['quantidade' => 1, 'nome' => 'Borda de catupiry']], 'observacao' => 'sem cebola']];
confere(ItensDoPedido::normalizar($completo) === $completo, 'o formato do contrato passa igual');
confere(ItensDoPedido::normalizar([['nome' => 'Refrigerante']]) === [['quantidade' => 1, 'nome' => 'Refrigerante']], 'sem opcoes nem observacao: as chaves não aparecem; quantidade ausente vale 1');
confere(umItem(['quantidade' => 2, 'nome' => 'Esfiha', 'opcoes' => [], 'observacao' => '  ']) === ['quantidade' => 2, 'nome' => 'Esfiha'], 'opcoes vazias e observacao em branco saem');
confere(array_keys(umItem(['observacao' => 'x', 'opcoes' => [['nome' => 'a']], 'nome' => 'b', 'quantidade' => 3])) === ['quantidade', 'nome', 'opcoes', 'observacao'], 'chaves sempre na ordem do contrato');

echo '== Quantidade' . PHP_EOL;
foreach ([[2, 2], [2.0, 2], ['3', 3], [' 4 ', 4], [999, 999]] as [$entrada, $esperada]) {
    confere(umItem(['quantidade' => $entrada, 'nome' => 'X'])['quantidade'] === $esperada, 'quantidade ' . var_export($entrada, true) . ' = ' . $esperada);
}
foreach ([0, -1, 1000, 1.5, '1000', 'dois', true, null, [2], INF, NAN] as $invalida) {
    confere(umItem(['quantidade' => $invalida, 'nome' => 'X'])['quantidade'] === 1, 'quantidade inválida (' . str_replace(PHP_EOL, '', var_export($invalida, true)) . ') vale 1');
}

echo '== Nome' . PHP_EOL;
confere(umItem(['nome' => "  Pizza \n  grande\t "])['nome'] === 'Pizza grande', 'quebras e espaços colapsados, pontas aparadas');
confere(umItem(['nome' => 'Pizza 🍕 Çã'])['nome'] === 'Pizza 🍕 Çã', 'emoji e acento intactos');
confere(umItem(['nome' => "Pi\0zza\u{200B}"])['nome'] === 'Pizza', 'controle e invisível saem');
confere(umItem(['nome' => 123])['nome'] === '123', 'número vira texto');
confere(mb_strlen(umItem(['nome' => str_repeat('á', 130)])['nome']) === 120, 'nome longo cortado em 120 caracteres');
confere(umItem(['nome' => str_repeat('a', 119) . ' b'])['nome'] === str_repeat('a', 119), 'corte não deixa espaço sobrando no fim');
foreach (['vazio' => '', 'só espaço' => "  \u{00A0} ", 'lista' => ['x'], 'nulo' => null, 'booleano' => true, 'UTF-8 inválido' => "\xFFPizza"] as $caso => $nome) {
    confere(umItem(['quantidade' => 1, 'nome' => $nome]) === null, "item sem nome válido ({$caso}) não entra");
}

echo '== Opções' . PHP_EOL;
$item = umItem(['nome' => 'Combo', 'opcoes' => [['nome' => 'Molho'], ['quantidade' => 2, 'nome' => 'Bacon'], ['quantidade' => 3], 'texto', ['nome' => '']]]);
confere($item['opcoes'] === [['quantidade' => 1, 'nome' => 'Molho'], ['quantidade' => 2, 'nome' => 'Bacon']], 'opção sem nome cai; quantidade padrão 1');
$muitas = array_map(fn ($n) => ['nome' => "Opção {$n}"], range(1, 25));
confere(count(umItem(['nome' => 'X', 'opcoes' => $muitas])['opcoes']) === 20, 'até 20 opções');
confere(!array_key_exists('opcoes', umItem(['nome' => 'X', 'opcoes' => ['a' => ['nome' => 'Molho']]])), 'opcoes que não é lista: a chave sai');
confere(!array_key_exists('opcoes', umItem(['nome' => 'X', 'opcoes' => [['quantidade' => 1]]])), 'nenhuma opção válida: a chave sai');

echo '== Observação' . PHP_EOL;
confere(umItem(['nome' => 'X', 'observacao' => "sem\ncebola"])['observacao'] === 'sem cebola', 'uma linha');
confere(mb_strlen(umItem(['nome' => 'X', 'observacao' => str_repeat('o', 250)])['observacao']) === 200, 'cortada em 200 caracteres');
confere(!array_key_exists('observacao', umItem(['nome' => 'X', 'observacao' => ['x']])), 'observacao que não é texto: a chave sai');

echo '== Lista' . PHP_EOL;
$sessenta = array_map(fn ($n) => ['nome' => "Item {$n}"], range(1, 60));
$lista    = ItensDoPedido::normalizar($sessenta);
confere(count($lista) === 50 && $lista[49]['nome'] === 'Item 50', 'até 50 itens, os primeiros');
$comInvalidos = array_merge(array_fill(0, 10, ['nome' => '']), $sessenta);
confere(count(ItensDoPedido::normalizar($comInvalidos)) === 50 && ItensDoPedido::normalizar($comInvalidos)[0]['nome'] === 'Item 1', 'item que caiu não conta no limite');
confere(ItensDoPedido::normalizar([['nome' => 'A'], 'texto', null, ['nome' => 'B']]) === [['quantidade' => 1, 'nome' => 'A'], ['quantidade' => 1, 'nome' => 'B']], 'entrada que não é objeto cai; a ordem fica');
foreach (['nulo' => null, 'vazia' => [], 'texto' => 'Pizza', 'objeto' => ['a' => ['nome' => 'Pizza']], 'só inválidos' => [['nome' => ''], ['quantidade' => 2]]] as $caso => $entrada) {
    confere(ItensDoPedido::normalizar($entrada) === null, "{$caso}: null");
}

echo '== Banco' . PHP_EOL;
confere(ItensDoPedido::paraOBanco(null) === null && ItensDoPedido::paraOBanco([]) === null && ItensDoPedido::paraOBanco([['nome' => '']]) === null, 'nada a gravar: null');
$json = ItensDoPedido::paraOBanco($completo);
confere($json === '[{"quantidade":1,"nome":"Pizza grande Calabresa","opcoes":[{"quantidade":1,"nome":"Borda de catupiry"}],"observacao":"sem cebola"}]', 'JSON com acento sem escape');
confere(ItensDoPedido::doBanco($json, ['pedido' => 'order_1']) === $completo, 'ida e volta');
$comoOMysql = '[{"nome": "Pizza", "opcoes": [{"nome": "Borda", "quantidade": 1}], "quantidade": 2}]';
confere(ItensDoPedido::doBanco($comoOMysql) === [['quantidade' => 2, 'nome' => 'Pizza', 'opcoes' => [['quantidade' => 1, 'nome' => 'Borda']]]], 'JSON do MySQL (chaves em outra ordem): volta na ordem do contrato');
\Illuminate\Support\Facades\Log::$registros = [];
confere(ItensDoPedido::doBanco(null) === null && ItensDoPedido::doBanco('') === null && \Illuminate\Support\Facades\Log::$registros === [], 'coluna vazia: null, sem log');
foreach (['quebrado' => '[{"nome": "Pizza"', 'texto' => '"Pizza"', 'objeto' => '{"nome": "Pizza"}', 'número' => '12'] as $caso => $valor) {
    \Illuminate\Support\Facades\Log::$registros = [];
    confere(ItensDoPedido::doBanco($valor, ['pedido' => 'order_1']) === null, "JSON {$caso}: null");
    confere(logou('[entregas] itens do pedido ilegíveis no banco', 'warning') && \Illuminate\Support\Facades\Log::$registros[0][2]['pedido'] === 'order_1' && logsSem(['Pizza']), "JSON {$caso}: warning só com o id");
}

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd /c/tmp/pp && PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/itens-do-pedido.php`
Expected: `PHP Fatal error:  Uncaught Error: Class "App\Support\Entregas\ItensDoPedido" not found` (sai com 1).

- [ ] **Step 3: Escrever a classe**

Crie `api/app/Support/Entregas/ItensDoPedido.php`:

```php
<?php

namespace App\Support\Entregas;

use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: os produtos do pedido (iFood e integração) no formato que o app do motoboy lê, o campo
 * `itens` de GET v1/entregas/motoboy/pedidos/{id}/ifood e .../integracao (spec
 * docs/superpowers/specs/2026-10-19-produtos-do-pedido-servidor-design.md):
 *
 *   [{"quantidade": 1, "nome": "Pizza", "opcoes": [{"quantidade": 1, "nome": "Borda"}], "observacao": "sem cebola"}]
 *
 * - `quantidade`: inteiro de 1 a 999 (aceita 2, 2.0 e "2"); ausente ou inválida vale 1 (o app soma as quantidades);
 * - `nome`: uma linha, sem espaços sobrando, cortado em 120 caracteres; item sem nome não entra;
 * - `opcoes`: até 20, cada uma com `quantidade` (padrão 1) e `nome`; opção sem nome não entra; sem nenhuma, sai;
 * - `observacao`: uma linha, cortada em 200 caracteres; vazia ou inválida, a chave sai;
 * - até 50 itens (os primeiros válidos); lista vazia, sem item válido ou que não é lista = null.
 *
 * Nunca lança nem recusa: os itens são um extra e não podem derrubar o pedido nem a resposta ao app. Função pura,
 * fora o aviso do doBanco (só com os ids que quem chama passa, nunca o conteúdo).
 */
final class ItensDoPedido
{
    public const MAXIMO_ITENS      = 50;
    public const MAXIMO_OPCOES     = 20;
    public const NOME_MAXIMO       = 120;
    public const OBSERVACAO_MAXIMO = 200;
    public const QUANTIDADE_MAXIMA = 999;

    /** A lista no formato do app, ou null. */
    public static function normalizar($itens): ?array
    {
        if (!is_array($itens) || !array_is_list($itens)) {
            return null;
        }

        $lista = [];
        foreach ($itens as $item) {
            if (count($lista) >= static::MAXIMO_ITENS) {
                break;
            }
            $normal = is_array($item) ? static::item($item) : null;
            if ($normal !== null) {
                $lista[] = $normal;
            }
        }

        return $lista ?: null;
    }

    /** O texto JSON da coluna `itens` (normalizado de novo, por segurança), ou null. */
    public static function paraOBanco(?array $itens): ?string
    {
        $itens = static::normalizar($itens);
        if ($itens === null) {
            return null;
        }
        $json = json_encode($itens, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? null : $json;
    }

    /**
     * A coluna `itens` lida do banco, normalizada (o MySQL devolve o JSON com as chaves em outra ordem). Nula ou
     * vazia = null. JSON quebrado ou que não é lista = null e o warning `[entregas] itens do pedido ilegíveis no banco`
     * com o $contexto (só ids), sem derrubar a resposta.
     */
    public static function doBanco($valor, array $contexto = []): ?array
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        try {
            $dados = is_string($valor) ? json_decode($valor, true, 16, JSON_THROW_ON_ERROR) : $valor;
            if (!is_array($dados) || !array_is_list($dados)) {
                throw new \UnexpectedValueException('não é lista');
            }

            return static::normalizar($dados);
        } catch (\Throwable $e) {
            Log::warning('[entregas] itens do pedido ilegíveis no banco', $contexto + ['erro' => get_class($e)]);

            return null;
        }
    }

    protected static function item(array $item): ?array
    {
        $nome = static::texto($item['nome'] ?? null, static::NOME_MAXIMO);
        if ($nome === null) {
            return null;
        }

        $normal = ['quantidade' => static::quantidade($item['quantidade'] ?? null), 'nome' => $nome];
        $opcoes = static::opcoes($item['opcoes'] ?? null);
        if ($opcoes) {
            $normal['opcoes'] = $opcoes;
        }
        $observacao = static::texto($item['observacao'] ?? null, static::OBSERVACAO_MAXIMO);
        if ($observacao !== null) {
            $normal['observacao'] = $observacao;
        }

        return $normal;
    }

    protected static function opcoes($opcoes): array
    {
        if (!is_array($opcoes) || !array_is_list($opcoes)) {
            return [];
        }

        $lista = [];
        foreach ($opcoes as $opcao) {
            if (count($lista) >= static::MAXIMO_OPCOES) {
                break;
            }
            $nome = is_array($opcao) ? static::texto($opcao['nome'] ?? null, static::NOME_MAXIMO) : null;
            if ($nome !== null) {
                $lista[] = ['quantidade' => static::quantidade($opcao['quantidade'] ?? null), 'nome' => $nome];
            }
        }

        return $lista;
    }

    /** Inteiro de 1 a 999; 2.0 e "2" valem 2; o resto (0, negativo, fração, texto, booleano, ausente) vale 1. */
    protected static function quantidade($valor): int
    {
        if (is_string($valor) && preg_match('/^\s*\d{1,3}\s*$/', $valor)) {
            $valor = (int) trim($valor);
        }
        if (is_float($valor) && is_finite($valor) && floor($valor) === $valor && $valor >= 1 && $valor <= static::QUANTIDADE_MAXIMA) {
            $valor = (int) $valor;
        }

        return is_int($valor) && $valor >= 1 && $valor <= static::QUANTIDADE_MAXIMA ? $valor : 1;
    }

    /**
     * Uma linha: quebras e tabs viram espaço, controles e invisíveis saem (o ZWJ do emoji composto fica), espaços
     * (inclusive os Unicode) colapsam e as pontas são aparadas; depois corta em $maximo caracteres. Número vira texto.
     * Vazio, não texto ou UTF-8 inválido = null.
     */
    protected static function texto($valor, int $maximo): ?string
    {
        if (is_int($valor) || is_float($valor)) {
            $valor = (string) $valor;
        }
        if (!is_string($valor) || !mb_check_encoding($valor, 'UTF-8')) {
            return null;
        }

        $limpo = preg_replace('/[\t\n\r\f\v\x{85}]/u', ' ', $valor);
        $limpo = $limpo === null ? null : preg_replace('/\p{Cc}|(?!\x{200D})\p{Cf}/u', '', $limpo);
        $limpo = $limpo === null ? null : preg_replace('/[\s\p{Z}]+/u', ' ', $limpo);
        $limpo = $limpo === null ? '' : trim($limpo);

        return $limpo === '' ? null : rtrim(mb_substr($limpo, 0, $maximo));
    }
}
```

- [ ] **Step 4: Rodar e ver passar; conferir a sintaxe**

```bash
cd /c/tmp/pp
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/itens-do-pedido.php | tail -1
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/ItensDoPedido.php
```

Expected: `FALHAS: 0` (60 casos `PASSA`) e `OK   api/app/Support/Entregas/ItensDoPedido.php`.

- [ ] **Step 5: Commit**

```bash
cd /c/tmp/pp
git add api/app/Support/Entregas/ItensDoPedido.php scripts/teste-php/itens-do-pedido.php
git commit -m "$(cat <<'EOF'
Produtos do pedido: ItensDoPedido normaliza, grava e lê a lista de itens do app

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Migrations da coluna `itens`

**Files:**
- Create: `api/database/migrations/2026_10_19_120000_add_itens_to_entregas_ifood_pedidos_table.php`
- Create: `api/database/migrations/2026_10_19_120100_add_itens_to_entregas_integracao_pedidos_table.php`
- Test: `scripts/teste-php/ifood-migrations.php`, `scripts/teste-php/integracao-migrations.php`

- [ ] **Step 1: Escrever os testes**

Em `scripts/teste-php/ifood-migrations.php`, localize a linha `echo '== down' . PHP_EOL;` e substitua por:

```php
echo '== entregas_ifood_pedidos: produtos do pedido (itens)' . PHP_EOL;
Schema::$alteradas = [];
$migracao = require '/repo/api/database/migrations/2026_10_19_120000_add_itens_to_entregas_ifood_pedidos_table.php';
$migracao->up();
$itens = Schema::$alteradas['entregas_ifood_pedidos'][0]->colunas ?? [];
confere(count($itens) === 1 && tem(['itens' => $itens[0]], 'itens', 'json', ['nullable']) && $itens[0]->argumentos === ['itens'], 'itens: json nulo');
confere(excecao(fn () => \Teste\Banco::exigirColuna('entregas_ifood_pedidos', 'itens')) === null, 'o banco em memória conhece a coluna itens');
\Teste\Banco::limpar();
\Teste\Banco::inserir('entregas_ifood_pedidos', ['pedido_ifood_id' => 'p-2', 'order_uuid' => 'o-2'], false);
confere(\Teste\Banco::linhas('entregas_ifood_pedidos')[0]->itens === null, 'pedido sem itens: NULL');
Schema::$alteradas = [];
$migracao->down();
$desfeito = Schema::$alteradas['entregas_ifood_pedidos'][0]->colunas[0] ?? null;
confere($desfeito && $desfeito->tipo === 'dropColumn' && $desfeito->argumentos === ['itens'], 'down tira a coluna');

echo '== down' . PHP_EOL;
```

Em `scripts/teste-php/integracao-migrations.php`, localize a linha `echo '== Config' . PHP_EOL;` e substitua por:

```php
echo '== entregas_integracao_pedidos: produtos do pedido (itens)' . PHP_EOL;
\Illuminate\Support\Facades\Schema::$alteradas = [];
$migracao = require '/repo/api/database/migrations/2026_10_19_120100_add_itens_to_entregas_integracao_pedidos_table.php';
$migracao->up();
$coluna = \Illuminate\Support\Facades\Schema::$alteradas['entregas_integracao_pedidos'][0]->colunas[0] ?? null;
confere($coluna && $coluna->tipo === 'json' && $coluna->argumentos === ['itens'] && array_key_exists('nullable', $coluna->modificadores), 'itens: json nulo');
confere(property_exists($nova, 'itens') && $nova->itens === null, 'pedido sem itens: NULL (a coluna veio da migration)');
\Illuminate\Support\Facades\Schema::$alteradas = [];
$migracao->down();
$desfeito = \Illuminate\Support\Facades\Schema::$alteradas['entregas_integracao_pedidos'][0]->colunas[0] ?? null;
confere($desfeito && $desfeito->tipo === 'dropColumn' && $desfeito->argumentos === ['itens'], 'down tira a coluna');

echo '== Config' . PHP_EOL;
```

(`$nova` é a linha `order-3` lida mais acima no mesmo arquivo.)

- [ ] **Step 2: Rodar e ver falhar**

```bash
cd /c/tmp/pp
for t in ifood-migrations integracao-migrations; do PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php 2>&1 | grep -E 'Fatal|FALHAS'; done
```

Expected: `PHP Fatal error:  Uncaught Error: Failed opening required '/repo/api/database/migrations/2026_10_19_120000_add_itens_to_entregas_ifood_pedidos_table.php'` e o mesmo para a `..._120100_..._integracao_...`.

- [ ] **Step 3: Escrever as migrations**

Crie `api/database/migrations/2026_10_19_120000_add_itens_to_entregas_ifood_pedidos_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: os produtos do pedido iFood (`items` do GET logistics/orders/{id}) no formato do app do
 * motoboy (App\Support\Entregas\ItensDoPedido), gravados pelo CriadorDoPedidoIfood na criação. Nula nos pedidos antigos
 * e quando o iFood não manda produto com nome. O ORDER_PATCHED não atualiza (risco aceito na spec
 * 2026-10-19-produtos-do-pedido-servidor-design.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entregas_ifood_pedidos', function (Blueprint $table) {
            $table->json('itens')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('entregas_ifood_pedidos', function (Blueprint $table) {
            $table->dropColumn('itens');
        });
    }
};
```

Crie `api/database/migrations/2026_10_19_120100_add_itens_to_entregas_integracao_pedidos_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: os produtos do pedido de outro sistema (campo opcional `itens` do POST
 * v1/entregas/integracao/pedidos) no formato do app do motoboy (App\Support\Entregas\ItensDoPedido), gravados pelo
 * CriadorDoPedidoDaIntegracao. Nula nos pedidos antigos e quando a origem não manda itens válidos. O reenvio do mesmo
 * pedido_externo_id não atualiza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entregas_integracao_pedidos', function (Blueprint $table) {
            $table->json('itens')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('entregas_integracao_pedidos', function (Blueprint $table) {
            $table->dropColumn('itens');
        });
    }
};
```

- [ ] **Step 4: Rodar e ver passar (com o teste do banco em memória)**

```bash
cd /c/tmp/pp
for t in ifood-migrations integracao-migrations ifood-stub-banco; do printf '%s: ' $t; PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php 2>&1 | tail -1; done
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/database/migrations/2026_10_19_120000_add_itens_to_entregas_ifood_pedidos_table.php api/database/migrations/2026_10_19_120100_add_itens_to_entregas_integracao_pedidos_table.php
```

Expected: as três com `FALHAS: 0` e as duas migrations `OK`.

- [ ] **Step 5: Commit**

```bash
cd /c/tmp/pp
git add api/database/migrations/2026_10_19_120000_add_itens_to_entregas_ifood_pedidos_table.php api/database/migrations/2026_10_19_120100_add_itens_to_entregas_integracao_pedidos_table.php scripts/teste-php/ifood-migrations.php scripts/teste-php/integracao-migrations.php
git commit -m "$(cat <<'EOF'
Produtos do pedido: coluna itens (JSON) no iFood e na integração

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: iFood: `mapear` devolve os itens e o criador os grava

**Files:**
- Modify: `api/app/Support/Entregas/Ifood/PedidoDoIfood.php` (use na linha 5, docblock na 42, `mapear` nas 118-157 e
  método novo depois da 158)
- Modify: `scripts/teste-php/fixtures-ifood.php` (no fim)
- Test: `scripts/teste-php/ifood-pedido.php`, `scripts/teste-php/ifood-criador.php`

O `CriadorDoPedidoIfood` não muda: ele grava `$dados['linha']` inteira (`CriadorDoPedidoIfood.php:137`).

- [ ] **Step 1: A fixture no formato da sonda**

No fim de `scripts/teste-php/fixtures-ifood.php`, acrescente:

```php

/**
 * Pedido real com dois produtos no formato da sonda de 2026-10-05 (`items[{index, name, quantity, options[{name,
 * quantity}]}]`), fora da ordem do `index` de propósito; o combo traz complementos.
 */
function pedidoComProdutos(): array
{
    $pedido          = pedidoEmDinheiroComTroco();
    $pedido['id']    = 'pedido-produtos-1';
    $pedido['items'] = [
        ['index' => 2, 'name' => 'PRODUTO 2 (COMBO) - Primeiro Nível', 'quantity' => 1, 'options' => [
            ['name' => 'Complemento 1 - Segundo Nível', 'quantity' => 1],
            ['name' => 'Complemento 2 - Segundo Nível', 'quantity' => 2],
        ]],
        ['index' => 1, 'name' => 'PRODUTO 1 - Primeiro Nível', 'quantity' => 2, 'options' => []],
    ];

    return $pedido;
}
```

- [ ] **Step 2: Escrever os testes**

Em `scripts/teste-php/ifood-pedido.php`, localize `echo '== Docblocks de até 120 colunas' . PHP_EOL;` e substitua por:

```php
echo '== Produtos do pedido (itens)' . PHP_EOL;
$dados = PedidoDoIfood::mapear(pedidoEmDinheiroComTroco(), LAT_COLETA, LNG_COLETA, $agora);
confere($dados['itens'] === [['quantidade' => 1, 'nome' => 'Pizza grande']], 'o produto do pedido no formato do app');
confere($dados['linha']['itens'] === '[{"quantidade":1,"nome":"Pizza grande"}]', 'o JSON vai na linha (gravada pelo criador)');
$dados = PedidoDoIfood::mapear(pedidoComProdutos(), LAT_COLETA, LNG_COLETA, $agora);
confere($dados['itens'] === [
    ['quantidade' => 2, 'nome' => 'PRODUTO 1 - Primeiro Nível'],
    ['quantidade' => 1, 'nome' => 'PRODUTO 2 (COMBO) - Primeiro Nível', 'opcoes' => [
        ['quantidade' => 1, 'nome' => 'Complemento 1 - Segundo Nível'],
        ['quantidade' => 2, 'nome' => 'Complemento 2 - Segundo Nível'],
    ]],
], 'na ordem do index, com as opções (complementos)');
$semItens = pedidoEmDinheiroComTroco();
unset($semItens['items']);
$dados = PedidoDoIfood::mapear($semItens, LAT_COLETA, LNG_COLETA, $agora);
confere($dados['itens'] === null && $dados['linha']['itens'] === null, 'sem items: null');
$semNome          = pedidoEmDinheiroComTroco();
$semNome['items'] = [['index' => 1, 'quantity' => 2, 'weight' => ['unit' => 'GRAMS', 'value' => 500]]];
confere(PedidoDoIfood::mapear($semNome, LAT_COLETA, LNG_COLETA, $agora)['itens'] === null, 'item sem name (a tabela da documentação): não entra');
$semIndice          = pedidoEmDinheiroComTroco();
$semIndice['items'] = [['name' => 'Lanche', 'quantity' => 1, 'observations' => 'sem cebola'], ['index' => 1, 'name' => 'Suco', 'quantity' => 1]];
confere(PedidoDoIfood::mapear($semIndice, LAT_COLETA, LNG_COLETA, $agora)['itens'] === [
    ['quantidade' => 1, 'nome' => 'Suco'],
    ['quantidade' => 1, 'nome' => 'Lanche', 'observacao' => 'sem cebola'],
], 'sem index vai para o fim; observations do item vira observacao');
$estranho          = pedidoEmDinheiroComTroco();
$estranho['items'] = ['a' => ['name' => 'Pizza']];
confere(PedidoDoIfood::mapear($estranho, LAT_COLETA, LNG_COLETA, $agora)['itens'] === null, 'items que não é lista: null (o pedido nasce igual)');

echo '== Docblocks de até 120 colunas' . PHP_EOL;
```

Em `scripts/teste-php/ifood-criador.php`, localize o fim do arquivo:

```php
confere((new CriadorDoPedidoIfood())->despachar($velho) && $pedido->chamadas === ['saveQuietly', 'firstDispatchWithActivity'] && $pedido->adhoc === true, 'adhoc + firstDispatchWithActivity no pedido relido');

resumo();
```

e substitua por:

```php
confere((new CriadorDoPedidoIfood())->despachar($velho) && $pedido->chamadas === ['saveQuietly', 'firstDispatchWithActivity'] && $pedido->adhoc === true, 'adhoc + firstDispatchWithActivity no pedido relido');

echo '== Produtos do pedido gravados na linha' . PHP_EOL;
$vinculo = preparar();
$linha   = (new CriadorDoPedidoIfood())->criar($vinculo, pedidoComProdutos());
confere(is_string($linha->itens) && json_decode($linha->itens, true) === [
    ['quantidade' => 2, 'nome' => 'PRODUTO 1 - Primeiro Nível'],
    ['quantidade' => 1, 'nome' => 'PRODUTO 2 (COMBO) - Primeiro Nível', 'opcoes' => [
        ['quantidade' => 1, 'nome' => 'Complemento 1 - Segundo Nível'],
        ['quantidade' => 2, 'nome' => 'Complemento 2 - Segundo Nível'],
    ]],
], 'itens (JSON) na linha, na mesma transação do pedido');
confere(logsSem(['PRODUTO 1', 'Complemento 1']), 'os produtos não vão para o log');
$vinculo  = preparar();
$semItens = pedidoEmDinheiroComTroco();
unset($semItens['items']);
confere((new CriadorDoPedidoIfood())->criar($vinculo, $semItens)->itens === null, 'sem items: itens nulo, o pedido nasce igual');

resumo();
```

- [ ] **Step 3: Rodar e ver falhar**

```bash
cd /c/tmp/pp
for t in ifood-pedido ifood-criador; do echo "-- $t"; PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php 2>&1 | grep -E '^FALHA|Undefined array key|FALHAS'; done
```

Expected: no `ifood-pedido`, `PHP Warning:  Undefined array key "itens"` e linhas `FALHA` da seção nova; no
`ifood-criador`, `FALHA itens (JSON) na linha, na mesma transação do pedido`.

- [ ] **Step 4: Implementar no `PedidoDoIfood`**

Em `api/app/Support/Entregas/Ifood/PedidoDoIfood.php`:

1. Localize

```php
namespace App\Support\Entregas\Ifood;

use DateTimeImmutable;
```

e substitua por

```php
namespace App\Support\Entregas\Ifood;

use App\Support\Entregas\ItensDoPedido;
use DateTimeImmutable;
```

2. No docblock da classe, localize

```php
 * - 0800 e localizador do cliente (`customer.phone`) com a expiração do localizador.
 *
```

e substitua por

```php
 * - 0800 e localizador do cliente (`customer.phone`) com a expiração do localizador.
 * - Produtos (`items`): `itens` no formato do app (ItensDoPedido), na ordem do `index`, e o JSON na linha (itens()).
 *
```

3. Localize

```php
        $marcas = ($teste ? ' [TESTE]' : '') . ($semCoordenadas ? ' [SEM LOCALIZAÇÃO]' : '') . ($semJanela ? ' [AGENDADO SEM HORÁRIO]' : '');

        return [
```

e substitua por

```php
        $marcas = ($teste ? ' [TESTE]' : '') . ($semCoordenadas ? ' [SEM LOCALIZAÇÃO]' : '') . ($semJanela ? ' [AGENDADO SEM HORÁRIO]' : '');
        $itens  = static::itens($pedido['items'] ?? null);

        return [
```

4. Localize

```php
            'notas'           => 'iFood #' . $numero . $marcas,
            'entrega'         => [
```

e substitua por

```php
            'notas'           => 'iFood #' . $numero . $marcas,
            'itens'           => $itens,
            'entrega'         => [
```

5. Localize o fim do `mapear`

```php
                'despachar_em'        => $semDespacho ? null : static::paraOBanco($despacharEm),
            ],
        ];
    }
```

e substitua por

```php
                'despachar_em'        => $semDespacho ? null : static::paraOBanco($despacharEm),
                'itens'               => ItensDoPedido::paraOBanco($itens),
            ],
        ];
    }

    /**
     * Os produtos do Logistics (`items[{index, name, quantity, options[{name, quantity}]}]`, formato da sonda) no
     * formato do app (ItensDoPedido), na ordem do `index` (sem `index`, no fim, na ordem em que vieram). Item sem
     * `name` não entra: a tabela da documentação (só index, quantity e weight) é de outra resposta; conferir no
     * primeiro pedido de teste. O Logistics não traz observação do item; se vier `observations`, ela entra.
     */
    public static function itens($items): ?array
    {
        if (!is_array($items) || !array_is_list($items)) {
            return null;
        }

        $ordem = [];
        foreach ($items as $posicao => $item) {
            if (is_array($item)) {
                $ordem[] = [is_numeric($item['index'] ?? null) ? (float) $item['index'] : INF, $posicao, $item];
            }
        }
        usort($ordem, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return ItensDoPedido::normalizar(array_map(fn (array $linha) => [
            'quantidade' => $linha[2]['quantity'] ?? null,
            'nome'       => $linha[2]['name'] ?? null,
            'opcoes'     => static::opcoesDoIfood($linha[2]['options'] ?? null),
            'observacao' => $linha[2]['observations'] ?? null,
        ], $ordem));
    }

    /** `options[{name, quantity}]` com os nomes do contrato (o ItensDoPedido descarta o que não serve). */
    protected static function opcoesDoIfood($opcoes): ?array
    {
        if (!is_array($opcoes)) {
            return null;
        }

        return array_map(
            fn ($opcao) => is_array($opcao) ? ['quantidade' => $opcao['quantity'] ?? null, 'nome' => $opcao['name'] ?? null] : null,
            $opcoes
        );
    }
```

- [ ] **Step 5: Rodar e ver passar**

```bash
cd /c/tmp/pp
for t in ifood-pedido ifood-criador itens-do-pedido; do printf '%s: ' $t; PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php 2>&1 | tail -1; done
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/Ifood/PedidoDoIfood.php
```

Expected: as três com `FALHAS: 0` (o `ifood-pedido` inclui o caso "nenhuma linha de docblock com mais de 120
colunas") e `OK   api/app/Support/Entregas/Ifood/PedidoDoIfood.php`.

- [ ] **Step 6: Commit**

```bash
cd /c/tmp/pp
git add api/app/Support/Entregas/Ifood/PedidoDoIfood.php scripts/teste-php/fixtures-ifood.php scripts/teste-php/ifood-pedido.php scripts/teste-php/ifood-criador.php
git commit -m "$(cat <<'EOF'
iFood: produtos do pedido (items do Logistics) na linha do pedido, na ordem do index

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: iFood: `itens` na resposta ao app

**Files:**
- Modify: `api/app/Support/Entregas/Ifood/DadosIfoodDoMotoboy.php` (use na linha 5, docblock nas 8-17, retorno nas
  42-60)
- Test: `scripts/teste-php/ifood-conclusao.php` (no fim)

- [ ] **Step 1: Escrever o teste**

Em `scripts/teste-php/ifood-conclusao.php`, localize o fim do arquivo:

```php
confere((new IfoodPedidosController())->painel(new Request(), 'order_1')->dados === ['ifood' => false], 'pedido que não é do iFood: ifood falso');

resumo();
```

e substitua por:

```php
confere((new IfoodPedidosController())->painel(new Request(), 'order_1')->dados === ['ifood' => false], 'pedido que não é do iFood: ifood falso');

echo '== Produtos do pedido (itens) para o app' . PHP_EOL;
$esperados = [['quantidade' => 1, 'nome' => 'Pizza grande', 'opcoes' => [['quantidade' => 1, 'nome' => 'Borda de catupiry']], 'observacao' => 'sem cebola']];
pedidoIfood([], ['itens' => '[{"nome": "Pizza grande", "opcoes": [{"nome": "Borda de catupiry", "quantidade": 1}], "observacao": "sem cebola", "quantidade": 1}]']);
confere((new MotoboyController())->ifood(doMotoboy(), 'order_1')->dados['itens'] === $esperados, 'motoboy do pedido: os itens, na ordem do contrato (o MySQL devolve as chaves em outra ordem)');
$aberto = new Order(['uuid' => 'order-1', 'public_id' => 'order_1', 'status' => 'dispatched', 'driver_assigned_uuid' => null, 'adhoc' => true]);
$dados  = DadosIfoodDoMotoboy::resposta(linha(), $aberto, 'driver-1', '2026-10-05 15:00:00');
confere($dados['itens'] === $esperados && $dados['telefone'] === null, 'pedido ainda aberto: os itens vão, o 0800 não');
pedidoIfood();
confere((new MotoboyController())->ifood(doMotoboy(), 'order_1')->dados['itens'] === null, 'pedido antigo (sem itens): null');
pedidoIfood([], ['itens' => '[{"nome": "Pizza grande"']);
$dados = (new MotoboyController())->ifood(doMotoboy(), 'order_1')->dados;
confere($dados['itens'] === null && $dados['numero'] === '4821' && $dados['telefone'] !== null, 'JSON quebrado: itens null e o resto da resposta igual');
confere(logou('[entregas] itens do pedido ilegíveis no banco', 'warning') && logsSem(['Pizza grande']), 'warning só com o id do pedido');

resumo();
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd /c/tmp/pp && PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-conclusao.php 2>&1 | grep -E '^FALHA|FALHAS'`
Expected: `FALHA motoboy do pedido: os itens…`, `FALHA pedido ainda aberto: os itens vão, o 0800 não`, `FALHA warning só
com o id do pedido` e `FALHAS: 3`.

- [ ] **Step 3: Implementar**

Em `api/app/Support/Entregas/Ifood/DadosIfoodDoMotoboy.php`:

1. Localize `use App\Support\Entregas\StatusDoPedido;` e substitua por

```php
use App\Support\Entregas\ItensDoPedido;
use App\Support\Entregas\StatusDoPedido;
```

2. Localize

```php
 *   localizador (telefone_expira_em, texto no fuso do app; sem data = vale). No pedido ainda aberto, não vão.
 */
```

e substitua por

```php
 *   localizador (telefone_expira_em, texto no fuso do app; sem data = vale). No pedido ainda aberto, não vão.
 * - Produtos (`itens`, ItensDoPedido::doBanco): a todo motoboy que pode ler a rota, inclusive com o pedido aberto (o
 *   app mostra a linha resumida antes do aceite). Pedido antigo = null; JSON quebrado = null e um warning.
 */
```

3. Localize

```php
            'telefone'             => $telefone,
        ];
```

e substitua por

```php
            'telefone'             => $telefone,
            'itens'                => ItensDoPedido::doBanco($linha->itens ?? null, ['pedido' => $pedido->public_id ?? null]),
        ];
```

(`$linha->itens ?? null`: a linha lida antes da migration, ou montada à mão, não tem a propriedade.)

- [ ] **Step 4: Rodar e ver passar**

```bash
cd /c/tmp/pp
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-conclusao.php | tail -1
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/Ifood/DadosIfoodDoMotoboy.php
```

Expected: `FALHAS: 0` e `OK   api/app/Support/Entregas/Ifood/DadosIfoodDoMotoboy.php`.

- [ ] **Step 5: Commit**

```bash
cd /c/tmp/pp
git add api/app/Support/Entregas/Ifood/DadosIfoodDoMotoboy.php scripts/teste-php/ifood-conclusao.php
git commit -m "$(cat <<'EOF'
iFood: itens do pedido na rota do motoboy (também com o pedido aberto)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Integração: `itens` opcional no contrato (validação)

**Files:**
- Modify: `api/app/Support/Entregas/Integracao/PedidoDaIntegracao.php` (namespace na linha 3, docblock nas 5-12,
  `dados` nas 50-68)
- Test: `scripts/teste-php/integracao-pedido.php`, `scripts/teste-php/integracao-rotas.php`

- [ ] **Step 1: Escrever os testes**

Em `scripts/teste-php/integracao-pedido.php`, localize `echo '== Cobrança' . PHP_EOL;` e substitua por:

```php
echo '== Itens (produtos do pedido)' . PHP_EOL;
$r = validar();
confere($r['erros'] === [] && $r['dados']['itens'] === null, 'sem itens: null (o sistema que não manda continua igual)');
$r = validar(['itens' => [
    ['quantidade' => 2, 'nome' => '  Pizza grande ', 'opcoes' => [['nome' => 'Borda de catupiry'], ['quantidade' => 1]], 'observacao' => 'sem cebola'],
    ['quantidade' => 1, 'nome' => ''],
]]);
confere($r['erros'] === [] && $r['dados']['itens'] === [['quantidade' => 2, 'nome' => 'Pizza grande', 'opcoes' => [['quantidade' => 1, 'nome' => 'Borda de catupiry']], 'observacao' => 'sem cebola']], 'normalizados; o item e a opção sem nome caem');
foreach (['texto' => 'Pizza', 'número' => 12, 'objeto' => ['nome' => 'Pizza'], 'lista sem item válido' => [null, 'x', ['quantidade' => 2]]] as $caso => $itens) {
    $r = validar(['itens' => $itens]);
    confere($r['erros'] === [] && $r['dados']['itens'] === null && $r['dados']['numero'] === '4821', "itens inválido ({$caso}): o pedido não é recusado");
}
confere(count(validar(['itens' => array_fill(0, 60, ['nome' => 'Esfiha'])])['dados']['itens']) === 50, 'até 50 itens');

echo '== Cobrança' . PHP_EOL;
```

Em `scripts/teste-php/integracao-rotas.php`, localize

```php
confere($r->status === 409 && str_contains($r->dados['errors'][0], 'local de coleta'), 'loja sem coleta: 409');
```

e substitua por

```php
confere($r->status === 409 && str_contains($r->dados['errors'][0], 'local de coleta'), 'loja sem coleta: 409');
reiniciarIntegracao();
lojaIntegradaA();
$r = criarPelaRota(['itens' => 'não é lista']);
confere($r->status === 201 && !array_key_exists('itens', $r->dados), 'itens inválido: 201 mesmo assim; a resposta à origem não muda');
```

- [ ] **Step 2: Rodar e ver falhar**

```bash
cd /c/tmp/pp
for t in integracao-pedido integracao-rotas; do echo "-- $t"; PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php 2>&1 | grep -E '^FALHA|Fatal|FALHAS'; done
```

Expected: no `integracao-pedido`, `FALHA normalizados; o item e a opção sem nome caem` e `PHP Fatal error: Uncaught
TypeError: count(): Argument #1 ($value) must be of type Countable|array, null given`. O `integracao-rotas` já passa
(`FALHAS: 0`): hoje o campo desconhecido é ignorado; o caso novo é guarda de regressão.

- [ ] **Step 3: Implementar**

Em `api/app/Support/Entregas/Integracao/PedidoDaIntegracao.php`, localize

```php
namespace App\Support\Entregas\Integracao;

/**
```

e substitua por

```php
namespace App\Support\Entregas\Integracao;

use App\Support\Entregas\ItensDoPedido;

/**
```

Localize

```php
 * maiúsculas, CEP "99999-999", valores em centavos (aceita "58,90"), troco 0 = sem troco, forma em minúsculas.
 */
```

e substitua por

```php
 * maiúsculas, CEP "99999-999", valores em centavos (aceita "58,90"), troco 0 = sem troco, forma em minúsculas.
 *
 * `itens` (opcional, spec 2026-10-19-produtos-do-pedido-servidor-design.md) nunca gera erro: o que não serve cai
 * (ItensDoPedido::normalizar) e o resto do pedido entra. Sem item válido, `itens` = null.
 */
```

Localize

```php
            'observacoes'         => $observacoes,
        ]];
```

e substitua por

```php
            'observacoes'         => $observacoes,
            'itens'               => ItensDoPedido::normalizar(static::campo($corpo, 'itens')),
        ]];
```

- [ ] **Step 4: Rodar e ver passar**

```bash
cd /c/tmp/pp
for t in integracao-pedido integracao-rotas; do printf '%s: ' $t; PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php 2>&1 | tail -1; done
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/Integracao/PedidoDaIntegracao.php
```

Expected: as duas com `FALHAS: 0` e `OK   api/app/Support/Entregas/Integracao/PedidoDaIntegracao.php`.

- [ ] **Step 5: Commit**

```bash
cd /c/tmp/pp
git add api/app/Support/Entregas/Integracao/PedidoDaIntegracao.php scripts/teste-php/integracao-pedido.php scripts/teste-php/integracao-rotas.php
git commit -m "$(cat <<'EOF'
Integração: campo opcional itens no POST pedidos (inválido cai, nunca recusa o pedido)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Integração: o criador grava os itens

**Files:**
- Modify: `api/app/Support/Entregas/Integracao/CriadorDoPedidoDaIntegracao.php` (use na linha 6, docblock nas 28-29,
  insert nas 189-207)
- Test: `scripts/teste-php/integracao-criador.php`

- [ ] **Step 1: Escrever o teste**

Em `scripts/teste-php/integracao-criador.php`, localize `echo '== PedidosDaIntegracao::daLoja' . PHP_EOL;` e
substitua por:

```php
echo '== Produtos do pedido gravados na linha' . PHP_EOL;
reiniciarIntegracao();
[$linha] = criar(['itens' => [['quantidade' => 2, 'nome' => 'Pizza grande', 'opcoes' => [['nome' => 'Borda de catupiry']]], ['nome' => '']]]);
confere($linha->itens === '[{"quantidade":2,"nome":"Pizza grande","opcoes":[{"quantidade":1,"nome":"Borda de catupiry"}]}]', 'itens normalizados (JSON) na linha');
confere(logsSem(['Pizza grande', 'Borda de catupiry']), 'os produtos não vão para o log');
[$repetida, $novo] = criar(['itens' => [['nome' => 'Outro produto']]]);
confere($novo === false && $repetida->itens === $linha->itens, 'reenvio (mesmo pedido_externo_id): devolve o existente, sem atualizar os itens');
reiniciarIntegracao();
[$linha] = criar();
confere($linha->itens === null, 'sem itens: null');

echo '== PedidosDaIntegracao::daLoja' . PHP_EOL;
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd /c/tmp/pp && PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-criador.php 2>&1 | grep -E '^FALHA|FALHAS'`
Expected: `FALHA itens normalizados (JSON) na linha` e `FALHAS: 1`.

- [ ] **Step 3: Implementar**

Em `api/app/Support/Entregas/Integracao/CriadorDoPedidoDaIntegracao.php`, localize

```php
use App\Support\Entregas\Ifood\PedidoDoIfood;
use App\Support\Entregas\StatusDoPedido;
```

e substitua por

```php
use App\Support\Entregas\Ifood\PedidoDoIfood;
use App\Support\Entregas\ItensDoPedido;
use App\Support\Entregas\StatusDoPedido;
```

Localize

```php
 * - a linha da entregas_integracao_pedidos na mesma transação: (vendor_uuid, pedido_externo_id) único impede um segundo
 *   pedido para o mesmo pedido da origem (o reenvio devolve o mesmo);
```

e substitua por

```php
 * - a linha da entregas_integracao_pedidos na mesma transação: (vendor_uuid, pedido_externo_id) único impede um segundo
 *   pedido para o mesmo pedido da origem (o reenvio devolve o mesmo, sem atualizar nada, nem os `itens`);
 * - os produtos (`itens`, já normalizados pelo PedidoDaIntegracao) vão na linha em JSON (ItensDoPedido::paraOBanco);
```

Localize

```php
            'troco_para_centavos' => $dados['troco_para_centavos'],
            'sem_localizacao'     => $semLocalizacao,
```

e substitua por

```php
            'troco_para_centavos' => $dados['troco_para_centavos'],
            'itens'               => ItensDoPedido::paraOBanco($dados['itens'] ?? null),
            'sem_localizacao'     => $semLocalizacao,
```

- [ ] **Step 4: Rodar e ver passar**

```bash
cd /c/tmp/pp
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-criador.php | tail -1
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/Integracao/CriadorDoPedidoDaIntegracao.php
```

Expected: `FALHAS: 0` e `OK   api/app/Support/Entregas/Integracao/CriadorDoPedidoDaIntegracao.php`.

- [ ] **Step 5: Commit**

```bash
cd /c/tmp/pp
git add api/app/Support/Entregas/Integracao/CriadorDoPedidoDaIntegracao.php scripts/teste-php/integracao-criador.php
git commit -m "$(cat <<'EOF'
Integração: grava os itens do pedido na linha (o reenvio não atualiza)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Integração: `itens` na resposta ao app

**Files:**
- Modify: `api/app/Support/Entregas/Integracao/DadosDaIntegracaoDoMotoboy.php` (use na linha 5, docblock nas 7-12,
  retorno nas 26-42)
- Test: `scripts/teste-php/integracao-ligacoes.php` (seção "App do motoboy", depois da linha 79)

- [ ] **Step 1: Escrever o teste**

Em `scripts/teste-php/integracao-ligacoes.php`, localize

```php
confere(DadosDaIntegracaoDoMotoboy::resposta(null, (object) ['status' => 'enroute', 'driver_assigned_uuid' => 'driver-1'], 'driver-1') === ['integracao' => false], 'não é da integração');
```

e substitua por

```php
confere(DadosDaIntegracaoDoMotoboy::resposta(null, (object) ['status' => 'enroute', 'driver_assigned_uuid' => 'driver-1'], 'driver-1') === ['integracao' => false], 'não é da integração');
confere(array_key_exists('itens', $dados) && $dados['itens'] === null, 'pedido antigo (linha sem itens): itens null');
$comItens        = clone $linha;
$comItens->itens = '[{"nome": "Pizza grande", "quantidade": 2}]';
$aberto          = DadosDaIntegracaoDoMotoboy::resposta($comItens, (object) ['public_id' => 'order_1', 'status' => 'dispatched', 'driver_assigned_uuid' => null], 'driver-1');
confere($aberto['itens'] === [['quantidade' => 2, 'nome' => 'Pizza grande']] && $aberto['telefone'] === null, 'pedido aberto: os itens vão (na ordem do contrato), o telefone não');
$comItens->itens = '[{"nome": "Pizza grande"';
\Illuminate\Support\Facades\Log::$registros = [];
$quebrado = DadosDaIntegracaoDoMotoboy::resposta($comItens, (object) ['public_id' => 'order_1', 'status' => 'enroute', 'driver_assigned_uuid' => 'driver-1'], 'driver-1');
confere($quebrado['itens'] === null && $quebrado['telefone'] === '16999990000' && $quebrado['numero'] === '4821', 'JSON quebrado: itens null e o resto da resposta igual');
confere(logou('[entregas] itens do pedido ilegíveis no banco', 'warning') && \Illuminate\Support\Facades\Log::$registros[0][2]['pedido'] === 'order_1' && logsSem(['Pizza grande']), 'warning só com o id do pedido');
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd /c/tmp/pp && PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-ligacoes.php 2>&1 | grep -E '^FALHA|FALHAS'`
Expected: `FALHA pedido antigo (linha sem itens): itens null`, `FALHA pedido aberto: os itens vão…`, `FALHA warning só
com o id do pedido` e `FALHAS: 3`.

- [ ] **Step 3: Implementar**

Em `api/app/Support/Entregas/Integracao/DadosDaIntegracaoDoMotoboy.php`, localize

```php
use App\Support\Entregas\StatusDoPedido;

/**
 * Entregas RestaurantePro: o que o app do motoboy recebe de um pedido de outro sistema (GET
 * v1/entregas/motoboy/pedidos/{id}/integracao: card de aceitar e detalhes). Função pura sobre a linha da
 * entregas_integracao_pedidos e o Order. Pedido que não é da integração: {"integracao": false}. O telefone do cliente
 * só vai ao motoboy do pedido, com o pedido em andamento (a regra do 0800 do iFood).
 */
```

e substitua por

```php
use App\Support\Entregas\ItensDoPedido;
use App\Support\Entregas\StatusDoPedido;

/**
 * Entregas RestaurantePro: o que o app do motoboy recebe de um pedido de outro sistema (GET
 * v1/entregas/motoboy/pedidos/{id}/integracao: card de aceitar e detalhes). Função pura sobre a linha da
 * entregas_integracao_pedidos e o Order. Pedido que não é da integração: {"integracao": false}. O telefone do cliente
 * só vai ao motoboy do pedido, com o pedido em andamento (a regra do 0800 do iFood). Os produtos (`itens`,
 * ItensDoPedido::doBanco) vão a todo motoboy que pode ler a rota, inclusive com o pedido aberto; sem eles, null.
 */
```

Localize

```php
            'telefone'             => $doMotoboy && !$encerrado && $linha->cliente_telefone ? (string) $linha->cliente_telefone : null,
        ];
```

e substitua por

```php
            'telefone'             => $doMotoboy && !$encerrado && $linha->cliente_telefone ? (string) $linha->cliente_telefone : null,
            'itens'                => ItensDoPedido::doBanco($linha->itens ?? null, ['pedido' => $pedido->public_id ?? null]),
        ];
```

- [ ] **Step 4: Rodar e ver passar**

```bash
cd /c/tmp/pp
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/integracao-ligacoes.php | tail -1
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/Integracao/DadosDaIntegracaoDoMotoboy.php
```

Expected: `FALHAS: 0` e `OK   api/app/Support/Entregas/Integracao/DadosDaIntegracaoDoMotoboy.php`.

- [ ] **Step 5: Commit**

```bash
cd /c/tmp/pp
git add api/app/Support/Entregas/Integracao/DadosDaIntegracaoDoMotoboy.php scripts/teste-php/integracao-ligacoes.php
git commit -m "$(cat <<'EOF'
Integração: itens do pedido na rota do motoboy (também com o pedido aberto)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: Regressão completa no Delivery

**Files:** nenhum (só verificação).

- [ ] **Step 1: Todos os testes PHP**

```bash
cd /c/tmp/pp
for f in scripts/teste-php/*.php; do case "$(basename "$f")" in stubs*|fixtures*) continue;; esac; printf '%s: ' "$(basename "$f")"; PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs "$f" 2>&1 | tail -1; done | grep -v 'FALHAS: 0$'; echo fim
```

Expected: só `fim` (71 arquivos, todos com `FALHAS: 0`; na `main` de 2026-10-19 eram 70, todos verdes). Leva uns 10
minutos. Qualquer linha antes do `fim` é falha: corrija antes de seguir.

- [ ] **Step 2: Sintaxe dos arquivos tocados**

```bash
cd /c/tmp/pp
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/ItensDoPedido.php api/app/Support/Entregas/Ifood/PedidoDoIfood.php api/app/Support/Entregas/Ifood/DadosIfoodDoMotoboy.php api/app/Support/Entregas/Integracao/PedidoDaIntegracao.php api/app/Support/Entregas/Integracao/CriadorDoPedidoDaIntegracao.php api/app/Support/Entregas/Integracao/DadosDaIntegracaoDoMotoboy.php api/database/migrations/2026_10_19_120000_add_itens_to_entregas_ifood_pedidos_table.php api/database/migrations/2026_10_19_120100_add_itens_to_entregas_integracao_pedidos_table.php
```

Expected: 8 linhas `OK`.

- [ ] **Step 3: Tradução (regra da raiz; nada de tela mudou)**

Run: `cd /c/tmp/pp && node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo exit=$?`
Expected: `exit=0`.

- [ ] **Step 4: Conferir o que entrou no ramo**

Run: `cd /c/tmp/pp && git diff --stat main...produtos-do-pedido`
Expected: só os arquivos do "Mapa de arquivos" do lado do Delivery (sem `CLAUDE.md`, sem `console/`, sem `packages/`).

---

### Task 9: Contrato da integração (Delivery, com commit)

**Files:**
- Modify: `docs/superpowers/specs/2026-10-12-integracao-restaurantepro-design.md` (Parte 1, `POST pedidos`, linhas
  56-83; Parte 2, linha 94)

O contrato do `POST pedidos` para outros sistemas é a Parte 1 desse spec (não há outro documento do contrato no
repositório).

- [ ] **Step 1: Contrato com `itens`**

Em `docs/superpowers/specs/2026-10-12-integracao-restaurantepro-design.md`, localize

```text
  "cobranca": { "forma": "dinheiro", "valor": 58.90, "troco_para": 100 },
  "observacoes": "Sem cebola"
}
```

e substitua por

```text
  "cobranca": { "forma": "dinheiro", "valor": 58.90, "troco_para": 100 },
  "observacoes": "Sem cebola",
  "itens": [
    { "quantidade": 1, "nome": "Pizza grande Calabresa", "opcoes": [{ "quantidade": 1, "nome": "Borda de catupiry" }], "observacao": "sem cebola" }
  ]
}
```

Localize a linha que começa com ``- **`cobranca`:** `forma` ∈`` e acrescente logo **depois** dela:

```markdown
- **`itens`** (opcional; desde 2026-10-19, spec `2026-10-19-produtos-do-pedido-servidor-design.md`): os produtos do pedido, para a Comanda do app do motoboy. Cada item tem `quantidade` (inteiro de 1 a 999; fora disso vale 1), `nome` (sem ele o item não entra; cortado em 120 caracteres), `opcoes` (até 20, cada uma com `quantidade` e `nome`) e `observacao` (até 200). Até 50 itens. **Nunca gera 422:** item ou campo inválido cai e o resto do pedido entra; sem `itens`, nada muda. O reenvio do mesmo `pedido_externo_id` não atualiza os itens, e a resposta não os devolve.
```

Na Parte 2, localize o trecho `` `cobranca_troco`, `observacoes`, `ultimo_aviso` `` e substitua por
`` `cobranca_troco`, `observacoes`, `itens` (JSON, desde 2026-10-19), `ultimo_aviso` ``.

- [ ] **Step 2: Commit**

A spec dos produtos já foi corrigida na `main` (a edge é a `entregas-tick`) e este plano já está commitado nela.

```bash
cd /c/tmp/pp
git add docs/superpowers/specs/2026-10-12-integracao-restaurantepro-design.md
git commit -m "$(cat <<'EOF'
Docs: itens no contrato do POST pedidos da integração

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 10: `CLAUDE.md` do iFood e da integração (pasta principal, **sem commit**)

**Files:**
- Modify: `C:\Users\Edgardjr\Documents\vibe coding\Delivery\api\app\Support\Entregas\Ifood\CLAUDE.md`
- Modify: `C:\Users\Edgardjr\Documents\vibe coding\Delivery\api\app\Support\Entregas\Integracao\CLAUDE.md`

Os dois arquivos não são rastreados (`git status` da pasta principal: `??`). Edite-os **na pasta principal**, não na
worktree, e não faça `git add` neles.

- [ ] **Step 1: iFood: seção nova antes de "### Tabelas"**

Em `api/app/Support/Entregas/Ifood/CLAUDE.md`, localize

```markdown
### Tabelas

As migrations ficam em `api/database/migrations`.
```

e substitua por

```markdown
### Produtos do pedido (2026-10-19)

Spec `docs/superpowers/specs/2026-10-19-produtos-do-pedido-servidor-design.md`; plano `docs/superpowers/plans/2026-10-19-produtos-do-pedido-servidor.md`. É a parte de servidor da tela "Comanda" do app.

- **Origem:** `items[{index, name, quantity, options[{name, quantity}]}]` do `GET logistics/orders/{id}` (formato da sonda; a tabela da documentação, sem `name`, é de outra resposta). `PedidoDoIfood::itens` ordena pelo `index` (sem `index`, no fim), converte para o formato do app e o `ItensDoPedido` normaliza: `[{quantidade, nome, opcoes?: [{quantidade, nome}], observacao?}]`, até 50 itens e 20 opções, nome cortado em 120 e observação em 200, quantidade inválida vale 1, item sem `name` não entra. O Logistics não traz observação por item (só se vier `observations`).
- **Gravação:** coluna `itens` (JSON, nula) da `entregas_ifood_pedidos`, na `linha` do `mapear`, gravada pelo criador na mesma transação. Pedidos antigos ficam nulos (sem preenchimento retroativo).
- **App:** `itens` no `GET v1/entregas/motoboy/pedidos/{id}/ifood` para qualquer motoboy que pode ler a rota, inclusive com o pedido aberto. JSON quebrado na coluna = `null` e o warning `[entregas] itens do pedido ilegíveis no banco` (só o `public_id`; `entregas_application`), sem derrubar a resposta. O console e o portal não mostram os itens.
- **Fora (risco aceito):** o ORDER_PATCHED (EDIT_ITEMS) não atualiza os itens: o pedido editado depois de criado mostra os da criação.
- **A conferir no primeiro pedido de teste:** se o `name` vem preenchido nos `items` do pedido real. Sem ele, `itens` fica nulo e o bloco some no app.
- Testes: `scripts/teste-php/itens-do-pedido.php`, os casos novos de `ifood-pedido.php`, `ifood-criador.php`, `ifood-conclusao.php` e `ifood-migrations.php`.

### Tabelas

As migrations ficam em `api/database/migrations`.
```

- [ ] **Step 2: iFood: tabela e rota do app**

Localize

```markdown
- `entregas_ifood_pedidos`: dados do iFood de cada pedido (0800, cobrança, observações, `exige_codigo`, `cancelado_pelo_ifood_em`, `teste`, `agendado`, `despachar_em`, `despachado_em`). Fica fora do `meta` do Order de propósito.
```

e substitua por

```markdown
- `entregas_ifood_pedidos`: dados do iFood de cada pedido (0800, cobrança, observações, `exige_codigo`, `cancelado_pelo_ifood_em`, `teste`, `agendado`, `despachar_em`, `despachado_em`, `itens`). Fica fora do `meta` do Order de propósito. `itens` (JSON, migration `2026_10_19_120000_add_itens_*`): ver "Produtos do pedido".
```

Localize

```markdown
0800 e localizador só para o motoboy do pedido, em andamento e antes de expirar. O alarme leva `entregas_ifood` e `entregas_cobrar`.
```

e substitua por

```markdown
0800 e localizador só para o motoboy do pedido, em andamento e antes de expirar; `itens` (os produtos) para qualquer motoboy que pode ler a rota, inclusive com o pedido aberto (ver "Produtos do pedido"). O alarme leva `entregas_ifood` e `entregas_cobrar`.
```

- [ ] **Step 3: Integração**

Em `api/app/Support/Entregas/Integracao/CLAUDE.md`, localize

```markdown
  - **Erros:** o 429 sai em pt-BR, com `Retry-After` e `X-RateLimit-*`. Os erros do próprio framework (404 de rota inexistente, 405, 500) saem em inglês: avise o RestaurantePro.
```

e substitua por

```markdown
  - **Erros:** o 429 sai em pt-BR, com `Retry-After` e `X-RateLimit-*`. Os erros do próprio framework (404 de rota inexistente, 405, 500) saem em inglês: avise o RestaurantePro.
  - **`itens`** (opcional, 2026-10-19; spec `2026-10-19-produtos-do-pedido-servidor-design.md`): os produtos do pedido, `[{quantidade, nome, opcoes?: [{quantidade, nome}], observacao?}]`, normalizados pelo `App\Support\Entregas\ItensDoPedido` (o mesmo do iFood): até 50 itens e 20 opções, nome cortado em 120 e observação em 200, quantidade inválida vale 1, item sem nome cai. **Nunca gera 422**: o que não serve cai e o resto do pedido entra. Gravado na coluna `itens` (JSON) pelo criador; o reenvio do mesmo `pedido_externo_id` não atualiza. A resposta à origem não devolve os itens. O RestaurantePro monta o campo no `corpoDoPedido` (`_shared/entregas.ts`), enviado pela `entregas-tick`.
```

Localize

```markdown
(telefone do cliente, cobrança em centavos, complemento, referência, observações, `ultimo_aviso`, `avisos_enviados`, cancelamento). O app lê em `GET v1/entregas/motoboy/pedidos/{id}/integracao` (limitador `entregas-motoboy-integracao`, 120 por minuto por motoboy; telefone só para o motoboy do pedido em andamento); o console, em `GET int/v1/entregas/pedidos/{id}/integracao`.
```

e substitua por

```markdown
(telefone do cliente, cobrança em centavos, complemento, referência, observações, produtos (`itens`, JSON), `ultimo_aviso`, `avisos_enviados`, cancelamento). O app lê em `GET v1/entregas/motoboy/pedidos/{id}/integracao` (limitador `entregas-motoboy-integracao`, 120 por minuto por motoboy; telefone só para o motoboy do pedido em andamento; `itens` para qualquer motoboy que pode ler a rota, inclusive com o pedido aberto, e JSON quebrado vira `null` com o warning `[entregas] itens do pedido ilegíveis no banco`); o console, em `GET int/v1/entregas/pedidos/{id}/integracao` (sem os itens).
```

Localize

```markdown
Tudo se acerta sozinho depois da migration. Para evitar, rode o deploy fora do horário de pico.
```

e substitua por

```markdown
Tudo se acerta sozinho depois da migration. Para evitar, rode o deploy fora do horário de pico. A coluna `itens` (2026-10-19) tem a mesma janela: nesses segundos o `POST pedidos` dá 500 (a `entregas-tick` do RestaurantePro tenta de novo) e o pedido iFood falha no job (volta para a fila).
```

Localize

```markdown
`chave`, `migrations`, `pedido`, `criador`, `rotas`, `sequencia`, `cancelamento`, `avisos`, `webhook-url`, `acompanhar` e `ligacoes`.
```

e substitua por

```markdown
`chave`, `migrations`, `pedido`, `criador`, `rotas`, `sequencia`, `cancelamento`, `avisos`, `webhook-url`, `acompanhar` e `ligacoes`. A normalização dos produtos (comum ao iFood) tem o `itens-do-pedido.php`.
```

- [ ] **Step 4: Conferir que nada entrou em commit**

Run: `cd "/c/Users/Edgardjr/Documents/vibe coding/Delivery" && git status --short api/app/Support/Entregas/Ifood/CLAUDE.md api/app/Support/Entregas/Integracao/CLAUDE.md`
Expected: as duas linhas com `??` (continuam não rastreadas).

---

### Task 11: RestaurantePro: worktree e conferência do esquema (só leitura)

**Files:** nenhum.

Esta tarefa é **portão**: o resultado decide se a Task 12 segue como está.

- [ ] **Step 1: Worktree**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/restaurantepro-main"
git rev-parse --show-toplevel
git worktree add /c/tmp/pp-rp -b produtos-do-pedido main
cd /c/tmp/pp-rp && git status --short && git log --oneline -1
```

Expected: toplevel `C:/Users/Edgardjr/Documents/vibe coding/restaurantepro-main`; `git status --short` vazio; o último
commit é o da `main` (em 2026-10-19, `a4267981 fix(perfil): …`, ou mais novo).

- [ ] **Step 2: Base dos testes**

```bash
cd /c/tmp/pp-rp
deno test supabase/functions/_shared/entregas_test.ts 2>&1 | tail -1
npm run -s smoke:entregas
```

Expected: `ok | 30 passed | 0 failed` e `smoke:entregas OK` (o `ExperimentalWarning` do Node é normal). Se o
`deno.lock` mudar (`git status --short`), restaure com `git checkout deno.lock`.

- [ ] **Step 3: Conferir o esquema de `orders.items` no banco (SQL só de leitura)**

O `CREATE TABLE orders` não está em `supabase/migrations` (só `ALTER`s), então o tipo e as chaves reais saem do banco.
Rode no projeto `rmzvlqynuwcalqrakklp` pelo `execute_sql` do MCP do Supabase (ou no SQL Editor do painel). São só
`SELECT`s:

```sql
-- 1) tipo das colunas
select column_name, data_type, udt_name
  from information_schema.columns
 where table_schema = 'public' and table_name = 'orders' and column_name in ('items', 'observation');
```

Expected: `items` com `udt_name = jsonb` (ou `json`) e `observation` `text`.

```sql
-- 2) chaves de cada item (delivery, 90 dias)
select chave, count(*) as itens
  from public.orders o
 cross join lateral jsonb_array_elements(case when jsonb_typeof(o.items) = 'array' then o.items else '[]'::jsonb end) as i(item)
 cross join lateral jsonb_object_keys(case when jsonb_typeof(i.item) = 'object' then i.item else '{}'::jsonb end) as k(chave)
 where o.order_type = 'delivery' and o.created_at > now() - interval '90 days'
 group by chave order by itens desc;
```

Expected: `productId`, `productName`, `quantity`, `price`, `category`, `selectedAddons` e **nenhuma** chave de
observação do item (`observation`, `observacao`, `notes`, `note`, `obs`, `comment`).

```sql
-- 3) chaves de cada opção (selectedAddons[].options[])
select chave, count(*) as opcoes
  from public.orders o
 cross join lateral jsonb_array_elements(case when jsonb_typeof(o.items) = 'array' then o.items else '[]'::jsonb end) as i(item)
 cross join lateral jsonb_array_elements(case when jsonb_typeof(i.item -> 'selectedAddons') = 'array' then i.item -> 'selectedAddons' else '[]'::jsonb end) as g(grupo)
 cross join lateral jsonb_array_elements(case when jsonb_typeof(g.grupo -> 'options') = 'array' then g.grupo -> 'options' else '[]'::jsonb end) as op(opcao)
 cross join lateral jsonb_object_keys(case when jsonb_typeof(op.opcao) = 'object' then op.opcao else '{}'::jsonb end) as k(chave)
 where o.order_type = 'delivery' and o.created_at > now() - interval '90 days'
 group by chave order by opcoes desc;
```

Expected: `id`, `name`, `price`, `quantity`.

```sql
-- 4) tipos e tamanhos
select 'items' as campo, coalesce(jsonb_typeof(items), 'null') as valor, count(*) as pedidos
  from public.orders where order_type = 'delivery' and created_at > now() - interval '90 days' group by 2
union all
select 'quantity', coalesce(jsonb_typeof(i.item -> 'quantity'), 'ausente'), count(*)
  from public.orders o
 cross join lateral jsonb_array_elements(case when jsonb_typeof(o.items) = 'array' then o.items else '[]'::jsonb end) as i(item)
 where o.order_type = 'delivery' and o.created_at > now() - interval '90 days' group by 2
union all
select 'maior_lista', coalesce(max(jsonb_array_length(items))::text, '0'), count(*)
  from public.orders where order_type = 'delivery' and jsonb_typeof(items) = 'array' and created_at > now() - interval '90 days';
```

Expected: `items` = `array` em todos; `quantity` = `number`; `maior_lista` bem abaixo de 50.

Se a consulta 1 mostrar `json` (não `jsonb`), troque nas consultas 2 a 4 `jsonb_` por `json_` e `'[]'::jsonb`/`'{}'::jsonb`
por `'[]'::json`/`'{}'::json`.

- [ ] **Step 4: Decidir pelo resultado**

- **Como esperado** (lista, as chaves acima, sem observação por item): siga para a Task 12 sem mudança.
- **Existe chave de observação no item** (ex.: `notes`): na Task 12, troque `bruto.observation` pelo nome real em
  `itensDoPedido` e `observation:` pelo mesmo nome no teste "o que não serve cai e o resto vai".
- **`items` guardado como texto** (`udt_name = text`) ou não lista: **pare** e leve ao Edgard. O PostgREST devolveria
  string, e a função teria de fazer `JSON.parse` (decisão fora deste plano).
- Anote o resultado no relatório da tarefa (tipo, chaves e maior lista).

---

### Task 12: RestaurantePro: `itensDoPedido` (função pura)

**Files:**
- Modify: `supabase/functions/_shared/entregas.ts` (depois da linha 141, `const umaLinha`)
- Test: `supabase/functions/_shared/entregas_test.ts` (import na linha 14; bloco novo antes da linha 219)

- [ ] **Step 1: Escrever os testes**

Em `supabase/functions/_shared/entregas_test.ts`, localize

```ts
  hmacHex,
  linkSeguro,
```

e substitua por

```ts
  hmacHex,
  itensDoPedido,
  linkSeguro,
```

Localize `// ── Parte 2: respostas da API ───────────────────────────────────────────` e substitua por:

```ts
// ── Parte 1b: produtos do pedido (`itens`) ──────────────────────────────

/** Um item como o public-order grava em orders.items (adicional e complemento moram em selectedAddons). */
const PIZZA = {
  productId: 'p1', productName: 'Pizza grande Calabresa', category: 'Pizzas', quantity: 2, price: 50,
  selectedAddons: [
    { addonGroupId: 'g1', addonGroupName: 'Borda', options: [{ id: 'o1', name: 'Borda de catupiry', price: 8, quantity: 1 }] },
    { addonGroupId: 'g2', addonGroupName: 'Bebida', options: [{ id: 'p9', name: 'Refrigerante 2L', price: 12, quantity: 2 }] },
  ],
};

Deno.test('itensDoPedido: produto, quantidade e opções de todos os grupos, na ordem', () => {
  assertEquals(itensDoPedido([PIZZA, { productName: 'Suco', quantity: 1, selectedAddons: [] }]), [
    {
      quantidade: 2,
      nome: 'Pizza grande Calabresa',
      opcoes: [{ quantidade: 1, nome: 'Borda de catupiry' }, { quantidade: 2, nome: 'Refrigerante 2L' }],
    },
    { quantidade: 1, nome: 'Suco' },
  ]);
});

Deno.test('itensDoPedido: o que não serve cai e o resto vai', () => {
  assertEquals(itensDoPedido([
    { productName: '   ', quantity: 1 },
    { productName: { x: 1 }, quantity: 1 },
    null,
    'texto',
    { productName: '  Esfiha \n carne ', quantity: 0, selectedAddons: [{ options: [{ name: '' }, { quantity: 3 }, { name: 'Limão', quantity: 'dois' }] }] },
    { productName: 'Água', quantity: '3', observation: '  gelada\n ' },
  ]), [
    { quantidade: 1, nome: 'Esfiha carne', opcoes: [{ quantidade: 1, nome: 'Limão' }] },
    { quantidade: 3, nome: 'Água', observacao: 'gelada' },
  ]);
});

Deno.test('itensDoPedido: tetos do Entregas (50 itens, 20 opções, 120 e 200 caracteres)', () => {
  const muitos = Array.from({ length: 60 }, (_, i) => ({ productName: `Item ${i + 1}`, quantity: 1 }));
  const r = itensDoPedido(muitos)!;
  assertEquals([r.length, r[49].nome], [50, 'Item 50']);
  const opcoes = Array.from({ length: 25 }, (_, i) => ({ name: `Opção ${i + 1}`, quantity: 1 }));
  assertEquals(itensDoPedido([{ productName: 'X', quantity: 1, selectedAddons: [{ options: opcoes }] }])![0].opcoes!.length, 20);
  const emoji = '\u{1F600}';
  const longo = itensDoPedido([{ productName: 'a'.repeat(119) + emoji + 'b', quantity: 1, observation: 'o'.repeat(250) }])![0];
  assertEquals([longo.nome, longo.observacao!.length], ['a'.repeat(119) + emoji, 200]);
  assertEquals(itensDoPedido([{ productName: 'X', quantity: 1000 }])![0].quantidade, 1);
});

Deno.test('itensDoPedido: sem produto = null', () => {
  assertEquals(itensDoPedido(null), null);
  assertEquals(itensDoPedido([]), null);
  assertEquals(itensDoPedido({ productName: 'X' }), null);
  assertEquals(itensDoPedido([{ productName: '' }]), null);
});

// ── Parte 2: respostas da API ───────────────────────────────────────────
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd /c/tmp/pp-rp && deno test supabase/functions/_shared/entregas_test.ts 2>&1 | head -5`
Expected: `TS2305 [ERROR]: Module '"…/entregas.ts"' has no exported member 'itensDoPedido'.`

- [ ] **Step 3: Implementar**

Em `supabase/functions/_shared/entregas.ts`, localize

```ts
export interface PedidoParaEnvio extends PagamentoDoPedido {
  id: string;
  customer_name?: string | null;
  customer_phone?: string | null;
  delivery_address?: string | null;
  observation?: string | null;
}

const umaLinha = (v: unknown) => String(v ?? '').replace(/\s+/g, ' ').trim();
```

e substitua por

```ts
export interface PedidoParaEnvio extends PagamentoDoPedido {
  id: string;
  customer_name?: string | null;
  customer_phone?: string | null;
  delivery_address?: string | null;
  observation?: string | null;
  /** orders.items (jsonb): o que vier, lido com cuidado por itensDoPedido. */
  items?: unknown;
}

const umaLinha = (v: unknown) => String(v ?? '').replace(/\s+/g, ' ').trim();

/** Um produto no formato `itens` do Entregas (repo Delivery, spec 2026-10-19-produtos-do-pedido-servidor-design.md). */
export interface ItemDoEntregas {
  quantidade: number;
  nome: string;
  opcoes?: Array<{ quantidade: number; nome: string }>;
  observacao?: string;
}

/** Tetos do contrato `itens` do Entregas (acima disso ele descarta o resto; aqui o corte é o mesmo). */
const ITENS_MAXIMO = 50;
const OPCOES_MAXIMO = 20;

/** Texto de uma linha só de string ou número (objeto não vira "[object Object]"). */
const textoDoItem = (v: unknown, max: number): string =>
  typeof v === 'string' || typeof v === 'number' ? corte(umaLinha(v), max).trim() : '';

/** Inteiro de 1 a 999 (aceita "2"); o resto vale 1, como no Entregas. */
function quantidadeDoItem(v: unknown): number {
  const n = typeof v === 'string' && /^\s*\d{1,3}\s*$/.test(v) ? Number(v) : v;
  return typeof n === 'number' && Number.isInteger(n) && n >= 1 && n <= 999 ? n : 1;
}

/** Os produtos do pedido do cardápio (orders.items: productName, quantity e selectedAddons[].options[] com name e
 * quantity, onde também moram os complementos) no formato `itens` do Entregas, para a Comanda do app do motoboy.
 * Item sem productName não vai; as opções de todos os grupos vão juntas, na ordem; até 50 itens e 20 opções. O
 * cardápio não tem observação por item (a do pedido segue em `observacoes`): `observation` do item só entra se um dia
 * existir. Nenhum item = null (o corpo vai sem `itens`). */
export function itensDoPedido(items: unknown): ItemDoEntregas[] | null {
  if (!Array.isArray(items)) return null;
  const lista: ItemDoEntregas[] = [];
  for (const it of items) {
    if (lista.length >= ITENS_MAXIMO) break;
    if (!it || typeof it !== 'object') continue;
    const bruto = it as Record<string, unknown>;
    const nome = textoDoItem(bruto.productName, 120);
    if (!nome) continue;
    const item: ItemDoEntregas = { quantidade: quantidadeDoItem(bruto.quantity), nome };
    const grupos = Array.isArray(bruto.selectedAddons) ? bruto.selectedAddons : [];
    const opcoes = grupos
      .flatMap((g: any) => (g && Array.isArray(g.options) ? g.options : []))
      .map((o: any) => ({ quantidade: quantidadeDoItem(o?.quantity), nome: textoDoItem(o?.name, 120) }))
      .filter((o: { nome: string }) => o.nome)
      .slice(0, OPCOES_MAXIMO);
    if (opcoes.length > 0) item.opcoes = opcoes;
    const observacao = textoDoItem(bruto.observation, 200);
    if (observacao) item.observacao = observacao;
    lista.push(item);
  }
  return lista.length > 0 ? lista : null;
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `cd /c/tmp/pp-rp && deno test supabase/functions/_shared/entregas_test.ts 2>&1 | tail -1`
Expected: `ok | 34 passed | 0 failed`.

- [ ] **Step 5: Commit**

```bash
cd /c/tmp/pp-rp
git status --short
git add supabase/functions/_shared/entregas.ts supabase/functions/_shared/entregas_test.ts
git commit -m "$(cat <<'EOF'
feat(entregas): itensDoPedido monta os produtos do pedido no formato itens do Entregas

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

(Se o `git status --short` mostrar `deno.lock`, rode `git checkout deno.lock` antes do `git add`.)

---

### Task 13: RestaurantePro: `itens` no `POST pedidos`

**Files:**
- Modify: `supabase/functions/_shared/entregas.ts` (`corpoDoPedido`, linhas 190-192 da versão original)
- Modify: `supabase/functions/entregas-tick/index.ts:141` (`select` do `getOrder`)
- Test: `supabase/functions/_shared/entregas_test.ts`

- [ ] **Step 1: Escrever o teste**

Em `supabase/functions/_shared/entregas_test.ts`, localize

```ts
Deno.test('itensDoPedido: sem produto = null', () => {
  assertEquals(itensDoPedido(null), null);
  assertEquals(itensDoPedido([]), null);
  assertEquals(itensDoPedido({ productName: 'X' }), null);
  assertEquals(itensDoPedido([{ productName: '' }]), null);
});
```

e substitua por

```ts
Deno.test('itensDoPedido: sem produto = null', () => {
  assertEquals(itensDoPedido(null), null);
  assertEquals(itensDoPedido([]), null);
  assertEquals(itensDoPedido({ productName: 'X' }), null);
  assertEquals(itensDoPedido([{ productName: '' }]), null);
});

Deno.test('corpoDoPedido: leva os itens quando o pedido tem produtos', () => {
  const base = { id: ORDER_ID, delivery_address: 'Rua A, 10', total: 20, payment_method: 'pix' };
  const r = corpoDoPedido({ ...base, items: [PIZZA] }, null, 'Ribeirão Preto');
  assertEquals(r.ok && r.corpo.itens, itensDoPedido([PIZZA]));
  const sem = corpoDoPedido({ ...base, items: [] }, null, 'Ribeirão Preto');
  assertEquals(sem.ok && 'itens' in sem.corpo, false);
});
```

(Os testes antigos do `corpoDoPedido` comparam o corpo inteiro e não têm `items`: continuam passando porque, sem
produto, a chave `itens` não aparece.)

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd /c/tmp/pp-rp && deno test supabase/functions/_shared/entregas_test.ts 2>&1 | grep -E 'FAILED|failed' | tail -2`
Expected: `corpoDoPedido: leva os itens quando o pedido tem produtos => …` em FAILURES e `34 passed | 1 failed`.

- [ ] **Step 3: Implementar no `corpoDoPedido`**

Em `supabase/functions/_shared/entregas.ts`, localize

```ts
  const observacoes = corte(String(pedido.observation ?? '').trim(), 1000);
  if (observacoes) corpo.observacoes = observacoes;
  return { ok: true, corpo };
```

e substitua por

```ts
  const observacoes = corte(String(pedido.observation ?? '').trim(), 1000);
  if (observacoes) corpo.observacoes = observacoes;
  const itens = itensDoPedido(pedido.items);
  if (itens) corpo.itens = itens;
  return { ok: true, corpo };
```

- [ ] **Step 4: Ler `items` na `entregas-tick`**

Em `supabase/functions/entregas-tick/index.ts`, localize

```ts
        `&select=id,company_id,status,order_type,customer_name,customer_phone,delivery_address,observation,total,` +
```

e substitua por

```ts
        `&select=id,company_id,status,order_type,customer_name,customer_phone,delivery_address,observation,items,total,` +
```

- [ ] **Step 5: Rodar testes, tipos e smoke**

```bash
cd /c/tmp/pp-rp
deno test supabase/functions/_shared/entregas_test.ts 2>&1 | tail -1
deno check supabase/functions/entregas-tick/index.ts supabase/functions/entregas-delivery/index.ts supabase/functions/entregas-webhook/index.ts
npm run -s smoke:entregas && npm run -s smoke:foody && npm run -s smoke:serviceauth
git status --short
```

Expected: `ok | 35 passed | 0 failed`; três linhas `Check …/index.ts`; `smoke:entregas OK`, `smoke:foody OK` e
`service-auth-smoke: OK`; o `git status --short` só com os três arquivos desta tarefa:
`M supabase/functions/_shared/entregas.ts`, `M supabase/functions/_shared/entregas_test.ts` e
`M supabase/functions/entregas-tick/index.ts` (se aparecer `deno.lock`, restaure com `git checkout deno.lock`).

- [ ] **Step 6: Commit**

```bash
cd /c/tmp/pp-rp
git add supabase/functions/_shared/entregas.ts supabase/functions/_shared/entregas_test.ts supabase/functions/entregas-tick/index.ts
git commit -m "$(cat <<'EOF'
feat(entregas): POST pedidos leva os itens do pedido (orders.items) para a Comanda do motoboy

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 14: RestaurantePro: documentação

**Files:**
- Modify: `CLAUDE.md` do `restaurantepro-main` (seção "Integração Entregas RestaurantePro (2026-10-12)", antes da
  linha 134, "- **Fila:**")

- [ ] **Step 1: Uma linha na seção da integração**

Em `/c/tmp/pp-rp/CLAUDE.md`, localize a linha que começa com
``- **Fila:** falha de leitura no `entregas-tick` `` e acrescente logo **antes** dela:

```markdown
- **Produtos (`itens`, 2026-10-19):** o `corpoDoPedido` manda `itens` montados por `itensDoPedido` (`_shared/entregas.ts`) a partir de `orders.items`: `productName`, `quantity` e as opções de todos os grupos de `selectedAddons` (adicionais e complementos), até 50 itens e 20 opções. É a Comanda do app do motoboy no Entregas (repo `Delivery`, spec `2026-10-19-produtos-do-pedido-servidor-design.md`). Sem produto, o corpo vai sem a chave. O Entregas nunca recusa o pedido por causa dos itens, e o reenvio não os atualiza. O cardápio não tem observação por item: a do pedido segue em `observacoes`.
```

- [ ] **Step 2: Commit**

```bash
cd /c/tmp/pp-rp
git add CLAUDE.md
git commit -m "$(cat <<'EOF'
docs: itens do pedido no POST pedidos do Entregas

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
)"
git log --oneline main..produtos-do-pedido
```

Expected: três commits no ramo (Tasks 12, 13 e 14).

---

### Task 15: Roteiro de implantação e conferência no primeiro pedido real (para o Edgard)

**Files:** nenhum. Este plano **não** faz push nem deploy: os passos abaixo são do Edgard (o Claude não tem SSH).

- [ ] **Step 1: Delivery: merge e push**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/Delivery"
git rev-parse --show-toplevel
git checkout main && git merge --no-ff produtos-do-pedido
git push origin main
```

Expected: o merge passa. A pasta principal tem mudanças de outra pessoa (`CLAUDE.md` da raiz, `console/app/router.js`)
e `CLAUDE.md` não rastreados: o merge não toca nesses arquivos; **não** os inclua em commit.

- [ ] **Step 2: API na VPS (fora do horário de pico)**

```bash
cd ~/entregas && bash deploy/atualizar.sh api
```

Sem variável nova no stack. **Janela da migration:** o `atualizar.sh` faz o `service update` antes do `deploy.sh`
(migrations). Nesses segundos, a coluna `itens` ainda não existe: o pedido iFood que chegar falha no job e volta para a
fila; o `POST pedidos` do RestaurantePro dá 500 e a `entregas-tick` tenta de novo; as rotas do app seguem respondendo
(`itens` nulo). Tudo se acerta depois da migration.

Conferir a migration:

```bash
docker exec -it $(docker ps -q -f name=entregas_database) mysql -uroot -p fleetbase -e "SHOW COLUMNS FROM entregas_ifood_pedidos LIKE 'itens'; SHOW COLUMNS FROM entregas_integracao_pedidos LIKE 'itens';"
```

Expected: uma linha `itens | json | YES` em cada tabela.

- [ ] **Step 3: RestaurantePro: merge, push e deploy da edge**

```bash
cd "/c/Users/Edgardjr/Documents/vibe coding/restaurantepro-main"
git checkout main && git merge --no-ff produtos-do-pedido && git push origin main
npx supabase functions deploy entregas-tick --project-ref rmzvlqynuwcalqrakklp --no-verify-jwt
```

Só a `entregas-tick` precisa de deploy (a `entregas-delivery` e a `entregas-webhook` importam o `_shared/entregas.ts`,
mas não usam o `corpoDoPedido`). Sem migration no Supabase. O push da `main` dispara o deploy do front na Vercel, que
não muda. A ordem API → edge é a segura; a inversa também funciona (a API antiga ignora o campo desconhecido).

- [ ] **Step 4: Conferência no primeiro pedido de teste do iFood (Terraço Pizza Bar)**

Avise os motoboys online perto da loja antes (o pedido de teste vai a eles como o real). Gere um pedido de teste no
Portal do Parceiro com pelo menos um produto com complemento e, depois que ele entrar:

```bash
docker exec -it $(docker ps -q -f name=entregas_database) mysql -uroot -p fleetbase -e "SELECT numero, JSON_LENGTH(itens) AS produtos, itens FROM entregas_ifood_pedidos ORDER BY id DESC LIMIT 3;"
```

Expected: o pedido novo com `produtos` ≥ 1 e os nomes dos produtos e complementos. Depois, pela rota do app, com o
token de um motoboy (o de teste; `<pedido>` = `public_id`, ex.: `order_…`; com a trava "Atualize o app" ligada, mande
o cabeçalho `X-Entregas-App` com o número do APK instalado):

```bash
curl -s -H "Authorization: Bearer <token do motoboy>" -H "X-Entregas-App: <n do APK>" \
  https://entregas-api.restaurantepro.com.br/v1/entregas/motoboy/pedidos/<pedido>/ifood
```

Expected: `"itens": [{"quantidade": …, "nome": "…", "opcoes": [...]}]`. **Se vier `"itens": null` com produto no
pedido:** o Logistics de produção não mandou `name` nos `items`. Registre em "Produtos do pedido → A conferir" do
`api/app/Support/Entregas/Ifood/CLAUDE.md` e leve ao Edgard (ver "Pontos de decisão"). Confira também que não há
`itens do pedido ilegíveis` no log: `docker service logs entregas_application --since 30m 2>&1 | grep 'itens do pedido'`
(esperado: nada).

- [ ] **Step 5: Conferência no primeiro pedido do RestaurantePro**

Pré-requisito ainda pendente (memória de 2026-10-12): `ENTREGAS_INTEGRACAO=1` no stack e a loja de teste conectada.
Com isso feito, faça um pedido de delivery no cardápio da loja de teste com um produto e um adicional, mova para
"Preparando" e, depois do minuto da `entregas-tick`:

```bash
docker exec -it $(docker ps -q -f name=entregas_database) mysql -uroot -p fleetbase -e "SELECT numero, JSON_LENGTH(itens) AS produtos, itens FROM entregas_integracao_pedidos ORDER BY id DESC LIMIT 3;"
curl -s -H "Authorization: Bearer <token do motoboy>" -H "X-Entregas-App: <n do APK>" \
  https://entregas-api.restaurantepro.com.br/v1/entregas/motoboy/pedidos/<pedido>/integracao
```

Expected: `itens` com o produto (`nome` = `productName`, `quantidade`) e o adicional em `opcoes`. Se vier nulo, confira
nos logs da `entregas-tick` (Supabase → Edge Functions → entregas-tick → Logs) se o `POST pedidos` saiu e se o
`select` traz `items` (o deploy da edge foi feito?).

- [ ] **Step 6: Depois da conferência**

Atualize a memória do projeto e o "A conferir" do `api/app/Support/Entregas/Ifood/CLAUDE.md` (o `name` veio?). O app
só mostra os itens com o APK da tela Comanda (outro plano, spec `2026-10-19-detalhe-do-pedido-app-design.md`); APKs
antigos ignoram o campo.

---

## Pontos de decisão (para o Edgard)

1. **Corte em vez de descarte:** nome acima de 120 e observação acima de 200 caracteres são cortados (o spec dizia "texto
   de 1 a 120"; descartar tiraria o produto inteiro da comanda). Quantidade inválida vira 1 (o app precisa do número).
2. **iFood sem `name` em produção:** a sonda viu `name` nos pedidos de teste. Se o pedido real vier sem, `itens` fica
   nulo. A alternativa (ler do módulo Order, que tem nome, observação e customizações) exige outro módulo homologado no
   app do iFood: fora deste plano.
3. **Observação por item no RestaurantePro:** o cardápio não tem. O spec previa "a observação do item, se houver"; o
   código lê `observation` do item por precaução, e a Task 11 confere no banco.
4. **Edge certa:** o spec citava a `entregas-delivery`; quem envia é a `entregas-tick` (corrigido na Task 9).
5. **Janela da migration** no `atualizar.sh api`: alguns segundos com falha temporária e nova tentativa automática nos
   dois lados. Deploy fora do pico.
6. **Conferência do RestaurantePro** depende de ligar `ENTREGAS_INTEGRACAO=1` e conectar a loja de teste, ainda
   pendentes.

## Auto-revisão (feita ao escrever)

- **Cobertura do spec:** formato e regras (Task 1); iFood: `mapear` na ordem do `index`, observação só se vier, coluna
  nova, criador, ORDER_PATCHED fora, pedidos antigos nulos (Tasks 2, 3 e docs); integração: contrato opcional, inválido
  não recusa, sem `itens` igual, gravação, reenvio sem atualizar, lado do RestaurantePro com deploy (Tasks 5, 6, 12, 13
  e 15); entrega ao app com pedido aberto e leitura defensiva com warning (Tasks 4 e 7); fora do escopo respeitado
  (console, portal e `payload.entities` não mudam); testes da seção 5 (Tasks 1 a 7 e 12-13); implantação da seção 6
  (Task 15).
- **Placeholders:** nenhum "TBD"/"depois"; todo passo de código traz o código; os únicos valores a preencher são o
  token do motoboy e o número do APK na conferência manual, que não existem no repositório.
- **Consistência de nomes:** `ItensDoPedido::normalizar`, `::paraOBanco`, `::doBanco` (Tasks 1, 3, 4, 5, 6, 7);
  `PedidoDoIfood::itens` e `opcoesDoIfood` (Task 3); fixture `pedidoComProdutos()` (Task 3); log
  `[entregas] itens do pedido ilegíveis no banco` (Tasks 1, 4, 7 e docs); `itensDoPedido`/`ItemDoEntregas` (Tasks 12 e
  13). Todo o código PHP e TypeScript deste plano foi rodado num rascunho fora do repositório antes de escrito aqui:
  `itens-do-pedido.php` e os 31 `ifood-*`/`integracao-*` verdes, com as falhas "antes" descritas em cada tarefa
  conferidas; 35 testes Deno, `deno check` das três edges e `smoke:entregas` verdes. A suíte PHP completa da `main`
  (70 arquivos) estava verde em 2026-10-19; a Task 8 roda os 71 no ramo.
