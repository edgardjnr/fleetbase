# iFood etapa 2A (servidor): tabelas, vínculo, polling, ack e criação do pedido

> **Situação (2026-10-05):** executado no ramo `ifood-etapa-2` (Tasks 1 a 12; a Task 13, verificação em produção, fica
> para depois do deploy), com ajustes das rodadas de revisão. **O código real é a referência**; o código e os textos
> deste plano ficaram desatualizados em vários pontos. Principais divergências:
> - `EventosIfood::criaPedido`: só PLC e eventos anteriores à coleta (CFM, RTP, DDCR, DPCR); o job não cria pedido com
>   CAN nem que já passou da coleta (procura entre todos os eventos gravados) e loga o "evento sem pedido".
> - `PedidoDoIfood`: pedido real sem coordenadas e agendado sem janela (marcas nas notas, sem despacho); `pending`
>   explícito manda na cobrança; log de pagamento inconsistente.
> - Adhoc só no pedido que vai aos motoboys sozinho; `despachar(Order): bool` com a `TravaDoPedido` e o pedido relido;
>   agendado com motoboy atribuído vai só a ele.
> - Job com `retryUntil` (30 min) e `$maxExceptions` em vez de `$tries`; `fail()` só no erro do GET do pedido.
> - Polling: pausa por token no 429 (polling e ack), 403 só nas lojas listadas do lote, teto de 25 s, 3 falhas seguidas,
>   cursor, varredura dos pendentes (2 min a 6 h) e limpeza dos pendentes de mais de 30 dias.
> - Agendador: desiste depois de 30 min com o aviso sonoro "sem motoboy"; CAN tira da fila. Os três comandos com
>   `runInBackground()` e `when(ClienteIfood::ligada())`.
> - Vínculo: refresh recusado = 400/401 com compare-and-set e refresh mantido cifrado; token ilegível; tokens da troca
>   no cache (repetir sem código novo); limitador nomeado `entregas-ifood-vinculo`; erros `{"errors": [...]}`
>   (Validator) e 422 com a mensagem da operação que falhou.
> - Task 12: a documentação foi escrita a partir do código (`CLAUDE.md`, seção "Integração iFood"), não do texto
>   proposto aqui.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** etapa 2 da integração iFood Logistics (spec `docs/superpowers/specs/2026-10-05-integracao-ifood-logistics-design.md`),
lado do servidor: as três tabelas novas, o vínculo das lojas pelo app distribuído (endpoints para a tela Lojas), o
polling a cada 30 s com gravação antes do ack, o processamento dos eventos por pedido e a criação do pedido no
Fleetbase já no PLC (despacho como o do portal; pedido de teste sem despacho; agendado 40 min antes da janela).

**Architecture:** tudo em `api/app` (nada em `packages/*/server` chega à produção). Uma porta HTTP só
(`ClienteIfood`), o vínculo e os tokens cifrados em `VinculosIfood` (tabela `entregas_ifood_lojas`), funções puras para
os eventos (`EventosIfood`) e para o pedido (`PedidoDoIfood`), um adaptador fino sobre os models do Fleet-Ops
(`CriadorDoPedidoIfood`), o job `ProcessarPedidoIfood` (um por pedido, com trava) e três comandos agendados no
`App\Console\Kernel` (`entregas:ifood-polling` a cada 30 s, `entregas:ifood-agendados` a cada minuto,
`entregas:ifood-tokens` a cada 30 min), todos parados sem `ENTREGAS_IFOOD=1`.

```
iFood ──polling 30 s──> entregas:ifood-polling ──insertOrIgnore──> entregas_ifood_eventos ──ack──> iFood
                                   └──fila──> ProcessarPedidoIfood (1 por pedido, trava) ──GET logistics──> CriadorDoPedidoIfood
                                                                                              ├─ Place + Payload + Order (transação)
                                                                                              ├─ entregas_ifood_pedidos
                                                                                              └─ despacho adhoc (imediato)
entregas:ifood-agendados (1 min) ──> despacha agendados vencidos e despachos que falharam
entregas:ifood-tokens (30 min) ──> renova tokens que vencem em < 1 h
```

**Tech Stack:** Laravel 10 / PHP 8.2 (`api/app`), Http do Laravel (Guzzle), Redis (cache, trava e fila), testes
php-wasm (`scripts/teste-php`).

**Depois deste plano:** o plano 2B (`docs/superpowers/plans/2026-10-05-ifood-etapa-2b-tela-lojas.md`) faz a tela Lojas
(selo e modal "Vincular iFood") sobre os endpoints da Task 11. A verificação em produção (Task 13) roda depois do 2B.

## Contexto para quem executa

- Leia o `CLAUDE.md` da raiz e a spec (seções Arquitetura, Tabelas novas, 1. Vínculo, 2. Entrada dos pedidos e 5. Erros)
  antes de começar. A referência da API é `docs/ifood/referencia-logistics.md`; a seção final, "Descobertas da sonda",
  vale mais que o resto onde divergem. Tudo em pt-BR: textos, comentários, nomes novos.
- PHP próprio vai em `api/app`; nada em `packages/*/server` chega à produção.
- Testes de PHP sem PHP instalado: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs <teste.php>`.
  Se `/c/tmp/php-wasm` não existir: `npm i --prefix /c/tmp/php-wasm @php-wasm/node@3.1.54 @php-wasm/universal@3.1.54`.
  Sintaxe: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs <arquivo.php>...`.
- Os testes do iFood usam stubs próprios (`stubs-ifood.php` e `stubs-ifood-fleetbase.php`), independentes dos outros:
  DB em memória com as chaves únicas das tabelas novas, `Http` com fila de respostas, `Cache` com trava, `Log`,
  `encrypt/decrypt` reversíveis, relógio fixo (`now()` = 2026-10-05 18:00:00 UTC) e fila de jobs. Os dados dos pedidos
  são fictícios (`fixtures-ifood.php`), com a estrutura da sonda; **não copie nomes, telefones nem localizadores de
  `deploy/ifood-sonda/`** (dados de teste reais, fora do git).
- Comandos em Git Bash, na raiz do repo (`C:\Users\Edgardjr\Documents\vibe coding\Delivery`). Confirme
  `git rev-parse --show-toplevel` antes de cada commit (a home também é um repo git). Há mudanças de outras sessões no
  repo: use sempre `git add <arquivos da task>`, nunca `git add -A`.
- Commits terminam com `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`. **Não** faça push.
- O código de cada task foi escrito e rodado contra os testes deste plano (php-wasm, PHP 8.2) antes de o plano ser
  publicado: se um teste falhar, confira primeiro se o arquivo foi copiado inteiro.

## Decisões deste plano (além da spec)

- **`evento_id`**: o `id` do iFood vai na coluna `evento_id` (única), porque `id` é o autoincremento (`$table->id()`).
- **`merchant_id` nulo ao desvincular**: a linha fica (`situacao = desvinculada`), mas sem tokens e sem merchant, para o
  merchant poder ir para outra loja (a chave única ignora NULL).
- **Colunas a mais em `entregas_ifood_pedidos`**: `company_uuid`, `vendor_uuid`, `agendado`, `despachar_em` e
  `despachado_em`. O `entregas:ifood-agendados` despacha tudo com `despachar_em` vencido e sem `despachado_em`: o
  agendado e também o imediato cujo despacho falhou no job (1 min de folga para não correr junto com o job).
- **Escolha da loja do iFood**: o código de autorização vale uma vez só. Com várias lojas na conta, os tokens ficam no
  cache (cifrados, 10 min) até a central escolher; a escolha não chama o iFood de novo.
- **Verificador e tokens no cache, cifrados** com `encrypt()` (o Redis não guarda segredo em claro).
- **Renovação com trava por loja** e releitura do vínculo: o polling e o job não gastam o mesmo refresh token ao mesmo
  tempo (se o iFood girar o refresh, o segundo seria recusado e a loja cairia).
- **403 no polling com `unauthorizedMerchants`** = o dono revogou: a loja vira `vinculo_perdido`.
- **Cancelado antes de entrar**: se o CAN já está entre os eventos pendentes de um pedido que ainda não existe, o pedido
  não é criado (não vai aos motoboys).
- **Pedido real sem coordenadas (0,0)**: entra (entrega deslocada 1 km da loja, como o de teste), mas não é despachado e
  o log avisa; a central confere.
- **Local da entrega sem dono**: o Place do cliente iFood não entra nos endereços salvos da loja no portal. Nome e
  endereço em maiúsculas com `mb_strtoupper` (o `PlaceObserver` só faz `strtoupper` ASCII). Complemento e referência
  vão também no `street2` ("APTO 501 · REF.: PERTO DA PRAÇA"), para o motoboy ler já na etapa 2.
- **Agendado já despachado pelo `fleetops:dispatch-orders`** (ele despacha quem cai a ±1 min do `scheduled_at`, sem a
  atividade): o `CriadorDoPedidoIfood::despachar` só põe a atividade "dispatched" que falta, sem avisar os motoboys de
  novo.
- **Saída dos comandos agendados**: `appendOutputTo('/proc/1/fd/1')`. Sem isso o Laravel joga a saída do comando
  agendado em `/dev/null` e os logs `[entregas] ifood:` (LOG_CHANNEL=stdout) não apareceriam em
  `docker service logs entregas_scheduler`. `withoutOverlapping(5|10)` com validade curta (o padrão, 24 h, travaria o
  polling por um dia se o processo morresse segurando a trava) e `runInBackground()` no polling e nos agendados.
- **`everyThirtySeconds()`**: existe desde o Laravel 10.15; o `api/composer.lock` está no `laravel/framework 10.x-dev`
  de 2026-08-12. Não há `vendor/` no repo: a Task 13 confere com `php artisan schedule:list` no container.
- **ORDER_PATCHED (OPA)** só fica registrado nesta etapa (a atualização do pedido fica para a etapa 3, com as ações).
- **Rotas do vínculo com `throttle:20,1`** (código e vínculo), para um clique repetido não martelar o iFood.

## A conferir (a referência e a sonda não bastaram)

- **Nome do campo do refresh token** na resposta do `/oauth/token` (a documentação não lista; a sonda usou o app
  centralizado, que não recebe refresh). O código grava `refreshToken` ou, de reserva, `refresh_token`; sem nenhum dos
  dois, grava sem refresh e registra `[entregas] ifood: resposta do token sem refresh token` com os nomes dos campos
  (`campos`). Conferir no primeiro vínculo real (Task 13) e, se for outro nome, acrescentar em
  `VinculosIfood::refreshDaResposta`.
- **Rotação do refresh token** (se a renovação devolve um refresh novo): tratado dos dois jeitos.
- **Validade do refresh token**: não documentada; o vínculo cai quando o iFood recusar.
- **Formato do `schedule`** (pedido agendado) e de **`payments` com `pending > 0`** (dinheiro, troco): a sonda só viu
  pedido imediato e pago online. Os testes seguem a documentação (`fixtures-ifood.php`).
- **`excludeHeartbeat`**: query (como na sonda), não header.

## Arquivos

| Arquivo | Ação | Papel |
|---|---|---|
| `api/config/services.php` | Modificar | Bloco `ifood` (interruptor, credenciais, base) |
| `deploy/docker-stack.yml` | Modificar | `ENTREGAS_IFOOD`, `IFOOD_CLIENT_ID`, `IFOOD_CLIENT_SECRET` no `x-api-env` |
| `deploy/stack.env.example` | Modificar | Documenta as três variáveis (vazio = desligada) |
| `api/database/migrations/2026_10_05_120000_create_entregas_ifood_lojas_table.php` | Criar | Vínculo loja ↔ merchant e tokens cifrados |
| `api/database/migrations/2026_10_05_120100_create_entregas_ifood_eventos_table.php` | Criar | Eventos do polling (id único) |
| `api/database/migrations/2026_10_05_120200_create_entregas_ifood_pedidos_table.php` | Criar | Dados iFood de cada pedido (fora do `meta`) |
| `api/app/Support/Entregas/Ifood/ErroIfood.php` | Criar | Erro tipado (status, corpo, Retry-After) |
| `api/app/Support/Entregas/Ifood/ClienteIfood.php` | Criar | Única porta HTTP |
| `api/app/Support/Entregas/Ifood/ErroDeVinculo.php` | Criar | Erro do vínculo com mensagem para a central |
| `api/app/Support/Entregas/Ifood/VinculoPerdido.php` | Criar | Refresh recusado: loja fora do polling |
| `api/app/Support/Entregas/Ifood/VinculosIfood.php` | Criar | Vínculo, tokens, renovação, perda, resumo |
| `api/app/Support/Entregas/Ifood/EventosIfood.php` | Criar | Funções puras dos eventos |
| `api/app/Support/Entregas/Ifood/PedidoDoIfood.php` | Criar | Função pura: pedido do Logistics → dados |
| `api/app/Support/Entregas/Ifood/CriadorDoPedidoIfood.php` | Criar | Cria e despacha o pedido no Fleetbase |
| `api/app/Jobs/Entregas/ProcessarPedidoIfood.php` | Criar | Processa os eventos pendentes de um pedido |
| `api/app/Console/Commands/Entregas/PollingIfood.php` | Criar | `entregas:ifood-polling` |
| `api/app/Console/Commands/Entregas/RenovarTokensIfood.php` | Criar | `entregas:ifood-tokens` |
| `api/app/Console/Commands/Entregas/AgendadosIfood.php` | Criar | `entregas:ifood-agendados` |
| `api/app/Console/Kernel.php` | Modificar | Agenda os três comandos |
| `api/app/Http/Controllers/Entregas/IfoodLojasController.php` | Criar | Endpoints do vínculo |
| `api/app/Http/Controllers/Entregas/LojasController.php` | Modificar | Bloco `ifood` na loja e `ifood_ligado` na lista |
| `api/app/Providers/RouteServiceProvider.php` | Modificar | Rotas do vínculo |
| `scripts/teste-php/stubs-ifood.php` | Criar | Stubs do Laravel para os testes do iFood |
| `scripts/teste-php/stubs-ifood-fleetbase.php` | Criar | Models do Fleetbase falsos, Request e Auth |
| `scripts/teste-php/fixtures-ifood.php` | Criar | Pedidos do Logistics fictícios |
| `scripts/teste-php/ifood-*.php` (10) | Criar | Testes |
| `CLAUDE.md`, spec | Modificar | Documentação e situação |

---

### Task 1: configuração e variáveis do stack

**Files:**
- Modify: `api/config/services.php`
- Modify: `deploy/docker-stack.yml`
- Modify: `deploy/stack.env.example`

- [ ] **Step 1: bloco `ifood` no `services.php`**

Em `api/config/services.php`, troque o fim do arquivo:

```php
    'entregas' => [
        'chave_app_motoboy' => env('ENTREGAS_CHAVE_APP_MOTOBOY'),
    ],
];
```

por:

```php
    'entregas' => [
        'chave_app_motoboy' => env('ENTREGAS_CHAVE_APP_MOTOBOY'),
    ],

    // Entregas RestaurantePro: integração iFood Logistics, app distribuído (App\Support\Entregas\Ifood). ENTREGAS_IFOOD
    // vazio ou 0 = desligada: os comandos agendados saem sem fazer nada e o vínculo na tela Lojas responde 409. As
    // credenciais ficam só no stack.env (nunca no banco nem no código). Aqui, e não em env() solto, porque o deploy.sh
    // roda config:cache.
    'ifood' => [
        'ativo'         => env('ENTREGAS_IFOOD'),
        'client_id'     => env('IFOOD_CLIENT_ID'),
        'client_secret' => env('IFOOD_CLIENT_SECRET'),
        'base_url'      => env('IFOOD_BASE_URL', 'https://merchant-api.ifood.com.br'),
    ],
];
```

- [ ] **Step 2: variáveis no `x-api-env` do stack**

Em `deploy/docker-stack.yml`, logo depois da linha `  ENTREGAS_ALARME_POR_DADOS: ${ENTREGAS_ALARME_POR_DADOS:-}`
(última do bloco `x-api-env`, antes da linha em branco e de `x-deploy:`), acrescente:

```yaml
  # integração iFood Logistics (app distribuído): vazio = desligada; 1 = ligada. Credenciais do app no Portal do
  # Desenvolvedor do iFood (CLAUDE.md, "Integração iFood")
  ENTREGAS_IFOOD: ${ENTREGAS_IFOOD:-}
  IFOOD_CLIENT_ID: ${IFOOD_CLIENT_ID:-}
  IFOOD_CLIENT_SECRET: ${IFOOD_CLIENT_SECRET:-}
```

(`IFOOD_BASE_URL` fica de fora: o padrão do `services.php` serve.)

- [ ] **Step 3: documentar no `stack.env.example`**

No fim de `deploy/stack.env.example`, acrescente:

```bash

# Integração iFood Logistics (api/app/Support/Entregas/Ifood; CLAUDE.md, "Integração iFood"). Vazio = desligada: nada de
# polling, renovação ou despacho de agendados, e a tela Lojas não mostra "Vincular iFood". 1 = ligada (com as duas
# credenciais preenchidas). As credenciais são do app DISTRIBUÍDO no Portal do Desenvolvedor do iFood (Meus Apps →
# Credenciais). Mudou? Update the stack no Portainer ("Re-pull image" desligado) e reinicie a fila e o scheduler:
#   docker service update --force entregas_queue && docker service update --force entregas_scheduler
# Conferir: docker exec $(docker ps -q -f name=entregas_application) printenv ENTREGAS_IFOOD
ENTREGAS_IFOOD=
IFOOD_CLIENT_ID=
IFOOD_CLIENT_SECRET=
```

- [ ] **Step 4: conferir**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/config/services.php`
Expected: `OK   api/config/services.php`.

Run: `grep -n "IFOOD" deploy/docker-stack.yml deploy/stack.env.example`
Expected: as três variáveis nos dois arquivos.

- [ ] **Step 5: commit**

```bash
git rev-parse --show-toplevel
git add api/config/services.php deploy/docker-stack.yml deploy/stack.env.example
git commit -m "iFood: configuração da integração (ENTREGAS_IFOOD e credenciais do app distribuído)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: stubs dos testes e as três tabelas

**Files:**
- Create: `scripts/teste-php/stubs-ifood.php`
- Create: `scripts/teste-php/ifood-migrations.php`
- Create: `api/database/migrations/2026_10_05_120000_create_entregas_ifood_lojas_table.php`
- Create: `api/database/migrations/2026_10_05_120100_create_entregas_ifood_eventos_table.php`
- Create: `api/database/migrations/2026_10_05_120200_create_entregas_ifood_pedidos_table.php`

O `stubs-ifood.php` serve a todos os testes do iFood deste plano. O DB em memória (`Teste\Banco` + `Teste\Consulta`)
aplica as chaves únicas das tabelas novas (NULL não conta, como no MySQL) e implementa só o que as classes usam:
`where` (com operador), `whereIn`, `whereNull`, `whereNotNull`, `orderBy`, `limit`, `first`, `get`, `pluck`, `value`,
`exists`, `count`, `insert`, `insertOrIgnore`, `update`, `delete` e `DB::transaction` (desfaz em erro).
`Teste\Banco::$falhar['tabela']` simula o banco fora do ar.

- [ ] **Step 1: criar `scripts/teste-php/stubs-ifood.php`**

```php
<?php

// Stubs dos testes da integração iFood (scripts/teste-php/ifood-*.php). Independentes dos outros stubs: aqui ficam o
// Http do Laravel (fila de respostas e registro das chamadas), o DB (tabelas em memória, com o pouco de query builder
// que as classes do iFood usam e as chaves únicas das tabelas novas), o Cache (com trava), o Log, o encrypt/decrypt, o
// relógio (now()), a fila de jobs, o Command, o Schedule e o Schema das migrations. Os models do Fleetbase (Order,
// Place, Payload, Vendor, OrderConfig) e o Request ficam no stubs-ifood-fleetbase.php.

namespace Illuminate\Support {
    class Collection implements \IteratorAggregate, \Countable
    {
        public function __construct(protected array $itens = []) {}
        public function all(): array { return $this->itens; }
        public function count(): int { return count($this->itens); }
        public function isEmpty(): bool { return !$this->itens; }
        public function first() { return $this->itens ? reset($this->itens) : null; }
        public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->itens); }
        public function map(callable $funcao): static { return new static(array_map($funcao, $this->itens)); }
        public function values(): static { return new static(array_values($this->itens)); }
        public function pluck(string $campo): static { return new static(array_map(fn ($item) => is_array($item) ? ($item[$campo] ?? null) : ($item->$campo ?? null), array_values($this->itens))); }
    }

    // o Carbon do Laravel (mutável, como o de verdade), só no que as classes do iFood usam
    class Carbon extends \DateTime
    {
        public static function parse($valor = 'now', $fuso = null): static
        {
            if ($valor instanceof \DateTimeInterface) {
                return new static($valor->format('Y-m-d H:i:s.u'), $valor->getTimezone());
            }

            return new static((string) $valor, is_string($fuso) ? new \DateTimeZone($fuso) : ($fuso ?? new \DateTimeZone('UTC')));
        }

        public function addSeconds($n): static { $this->modify('+' . (int) $n . ' seconds'); return $this; }
        public function subSeconds($n): static { $this->modify('-' . (int) $n . ' seconds'); return $this; }
        public function addMinutes($n): static { $this->modify('+' . (int) $n . ' minutes'); return $this; }
        public function subMinutes($n): static { $this->modify('-' . (int) $n . ' minutes'); return $this; }
        public function subMinute(): static { return $this->subMinutes(1); }
        public function addHour(): static { $this->modify('+1 hour'); return $this; }
        public function subDays($n): static { $this->modify('-' . (int) $n . ' days'); return $this; }
        public function toDateTimeString(): string { return $this->format('Y-m-d H:i:s'); }
        public function toIso8601String(): string { return $this->format('Y-m-d\TH:i:sP'); }
    }
}

namespace Illuminate\Support\Facades {
    class DB
    {
        public static function table(string $tabela) { return new \Teste\Consulta($tabela); }

        // desfaz as escritas nas tabelas em memória se a função lançar (como o rollback do MySQL)
        public static function transaction(\Closure $fazer)
        {
            $copia = \Teste\Banco::$tabelas;
            try {
                return $fazer();
            } catch (\Throwable $e) {
                \Teste\Banco::$tabelas = $copia;
                throw $e;
            }
        }
    }

    class Cache
    {
        public static array $dados = [];
        public static array $validades = [];
        public static function get($chave, $padrao = null) { return array_key_exists($chave, self::$dados) ? self::$dados[$chave] : $padrao; }
        public static function put($chave, $valor, $ttl = null) { self::$dados[$chave] = $valor; self::$validades[$chave] = $ttl; return true; }
        public static function forget($chave) { unset(self::$dados[$chave], self::$validades[$chave]); return true; }
        public static function lock($nome, $segundos = 0) { return new \Teste\Trava($nome); }
    }

    class Log
    {
        public static array $registros = [];
        public static function debug($mensagem, array $contexto = []) { self::$registros[] = ['debug', $mensagem, $contexto]; }
        public static function info($mensagem, array $contexto = []) { self::$registros[] = ['info', $mensagem, $contexto]; }
        public static function warning($mensagem, array $contexto = []) { self::$registros[] = ['warning', $mensagem, $contexto]; }
        public static function error($mensagem, array $contexto = []) { self::$registros[] = ['error', $mensagem, $contexto]; }
    }

    class Http
    {
        public static function __callStatic($metodo, $argumentos) { return (new \Teste\PedidoHttp())->$metodo(...$argumentos); }
    }

    // a migration: Schema::create entrega um Blueprint que só registra as colunas
    class Schema
    {
        public static array $criadas = [];

        public static function create(string $tabela, \Closure $definicao): void
        {
            $blueprint = new \Illuminate\Database\Schema\Blueprint();
            $definicao($blueprint);
            self::$criadas[$tabela] = $blueprint;
        }

        public static function dropIfExists(string $tabela): void { unset(self::$criadas[$tabela]); }
    }
}

namespace Illuminate\Http\Client {
    class ConnectionException extends \Exception {}

    // a resposta do Http do Laravel, só no que o ClienteIfood usa
    class Response
    {
        public function __construct(private int $codigo, private $corpo = null, private array $cabecalhos = []) {}
        public function status(): int { return $this->codigo; }
        public function successful(): bool { return $this->codigo >= 200 && $this->codigo < 300; }
        public function body(): string { return is_string($this->corpo) ? $this->corpo : ($this->corpo === null ? '' : (string) json_encode($this->corpo)); }

        public function json($chave = null, $padrao = null)
        {
            $dados = is_string($this->corpo) ? json_decode($this->corpo, true) : $this->corpo;

            return $chave === null ? $dados : ($dados[$chave] ?? $padrao);
        }

        public function header(string $nome): string
        {
            foreach ($this->cabecalhos as $chave => $valor) {
                if (strcasecmp($chave, $nome) === 0) {
                    return (string) $valor;
                }
            }

            return '';
        }
    }
}

namespace Illuminate\Database\Migrations {
    abstract class Migration {}
}

namespace Illuminate\Database\Schema {
    class Blueprint
    {
        /** @var \Teste\Coluna[] */
        public array $colunas = [];
        public function __call($tipo, $argumentos) { return $this->colunas[] = new \Teste\Coluna($tipo, $argumentos); }
    }
}

namespace Illuminate\Contracts\Queue {
    interface ShouldQueue {}
}

namespace Illuminate\Bus {
    trait Queueable {}
}

namespace Illuminate\Queue {
    trait InteractsWithQueue
    {
        /** Segundos do release() (o job voltou para a fila), ou null. */
        public ?int $liberadoPor = null;
        public function release($atraso = 0) { $this->liberadoPor = (int) $atraso; }
    }
}

namespace Illuminate\Foundation\Bus {
    trait Dispatchable
    {
        public static function dispatch(...$argumentos) { \Teste\Fila::$jobs[] = new static(...$argumentos); return null; }
    }
}

namespace Illuminate\Console {
    class Command
    {
        public const SUCCESS = 0;
        public const FAILURE = 1;
        public function info($texto) {}
        public function line($texto) {}
        public function warn($texto) {}
    }
}

namespace Illuminate\Console\Scheduling {
    class Schedule
    {
        /** @var \Teste\EventoAgendado[] */
        public array $eventos = [];
        public function command(string $comando) { return $this->eventos[] = new \Teste\EventoAgendado($comando); }
    }
}

namespace Illuminate\Foundation\Console {
    abstract class Kernel
    {
        protected function schedule(\Illuminate\Console\Scheduling\Schedule $schedule) {}
        protected function load($caminhos) {}
    }
}

namespace Teste {
    class Relogio
    {
        public static string $agora = '2026-10-05 18:00:00';
    }

    class Config
    {
        public static array $valores = [];
    }

    class Sessao
    {
        public static array $dados = [];
    }

    class Fila
    {
        public static array $jobs = [];
    }

    class Coluna
    {
        public array $modificadores = [];
        public function __construct(public string $tipo, public array $argumentos) {}
        public function __call($modificador, $argumentos) { $this->modificadores[$modificador] = $argumentos; return $this; }
    }

    class EventoAgendado
    {
        public array $chamadas = [];
        public function __construct(public string $comando) {}
        public function __call($metodo, $argumentos) { $this->chamadas[$metodo] = $argumentos; return $this; }
    }

    // trava do Cache::lock: uma por nome; o teste ocupa uma pondo o nome em $ocupadas
    class Trava
    {
        public static array $ocupadas = [];
        public function __construct(private string $nome) {}

        public function get($callback = null)
        {
            if (isset(self::$ocupadas[$this->nome])) {
                return false;
            }
            self::$ocupadas[$this->nome] = true;
            if ($callback === null) {
                return true;
            }
            try {
                return $callback();
            } finally {
                $this->release();
            }
        }

        public function block($segundos, $callback = null)
        {
            if (isset(self::$ocupadas[$this->nome])) {
                throw new \RuntimeException("trava ocupada: {$this->nome}");
            }

            return $this->get($callback);
        }

        public function release() { unset(self::$ocupadas[$this->nome]); return true; }
    }

    // respostas do iFood em fila (uma por chamada, na ordem) e o registro das chamadas feitas
    class Http
    {
        public static array $respostas = [];
        public static array $chamadas = [];
        public static function responder(int $status, $corpo = null, array $cabecalhos = []): void { self::$respostas[] = [$status, $corpo, $cabecalhos]; }
        public static function falharConexao(): void { self::$respostas[] = 'conexao'; }

        /** URLs chamadas, na ordem (sem a base). */
        public static function urls(): array
        {
            return array_map(fn ($c) => $c['metodo'] . ' ' . str_replace('https://merchant-api.ifood.com.br', '', $c['url']), self::$chamadas);
        }
    }

    class PedidoHttp
    {
        private array $opcoes = ['form' => false, 'token' => null, 'headers' => [], 'timeout' => null];
        public function asForm() { $this->opcoes['form'] = true; return $this; }
        public function acceptJson() { return $this; }
        public function withToken($token) { $this->opcoes['token'] = $token; return $this; }
        public function withHeaders(array $cabecalhos) { $this->opcoes['headers'] = array_merge($this->opcoes['headers'], $cabecalhos); return $this; }
        public function timeout($segundos) { $this->opcoes['timeout'] = $segundos; return $this; }
        public function get($url, $query = []) { return $this->enviar('GET', $url, $query); }
        public function post($url, $dados = []) { return $this->enviar('POST', $url, $dados); }

        private function enviar(string $metodo, string $url, $dados)
        {
            Http::$chamadas[] = ['metodo' => $metodo, 'url' => $url, 'dados' => $dados] + $this->opcoes;
            $resposta = array_shift(Http::$respostas);
            if ($resposta === null) {
                throw new \LogicException("Http: nenhuma resposta na fila para {$metodo} {$url}");
            }
            if ($resposta === 'conexao') {
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out');
            }

            return new \Illuminate\Http\Client\Response(...$resposta);
        }
    }

    class ErroDeBanco extends \RuntimeException {}

    // tabelas em memória: nome => [id => linha]
    class Banco
    {
        public static array $tabelas = [];
        public static array $proximoId = [];
        /** Colunas únicas de cada tabela (NULL não conta, como no MySQL). */
        public static array $unicas = [
            'entregas_ifood_lojas'   => ['vendor_uuid', 'merchant_id'],
            'entregas_ifood_eventos' => ['evento_id'],
            'entregas_ifood_pedidos' => ['pedido_ifood_id', 'order_uuid'],
        ];
        /** Tabela => mensagem: toda escrita nela lança ErroDeBanco (banco fora do ar). */
        public static array $falhar = [];

        public static function limpar(): void
        {
            self::$tabelas   = [];
            self::$proximoId = [];
            self::$falhar    = [];
        }

        /** As linhas da tabela, como objetos (o que o DB::table()->get() devolve). */
        public static function linhas(string $tabela): array
        {
            return array_values(array_map(fn ($linha) => (object) $linha, self::$tabelas[$tabela] ?? []));
        }

        public static function inserir(string $tabela, array $linha, bool $ignorarRepetida): bool
        {
            if (isset(self::$falhar[$tabela])) {
                throw new ErroDeBanco(self::$falhar[$tabela]);
            }
            foreach (self::$unicas[$tabela] ?? [] as $coluna) {
                $valor = $linha[$coluna] ?? null;
                if ($valor === null) {
                    continue;
                }
                foreach (self::$tabelas[$tabela] ?? [] as $existente) {
                    if (($existente[$coluna] ?? null) === $valor) {
                        if ($ignorarRepetida) {
                            return false;
                        }
                        throw new ErroDeBanco("Duplicate entry '{$valor}' for key '{$tabela}.{$coluna}'");
                    }
                }
            }
            $id                             = self::$proximoId[$tabela] = (self::$proximoId[$tabela] ?? 0) + 1;
            self::$tabelas[$tabela][$id] = ['id' => $id] + $linha;

            return true;
        }
    }

    // o query builder do DB::table, só no que as classes do iFood usam (todos os where ligados por E)
    class Consulta
    {
        private array $filtros = [];
        private array $ordem   = [];
        private ?int $limite   = null;

        public function __construct(private string $tabela) {}

        public function where($coluna, $operador = null, $valor = null)
        {
            if (func_num_args() === 2) {
                $valor    = $operador;
                $operador = '=';
            }
            $this->filtros[] = fn (array $linha) => static::compara($linha[$coluna] ?? null, $operador, $valor);

            return $this;
        }

        public function whereIn($coluna, array $valores) { $this->filtros[] = fn (array $linha) => in_array($linha[$coluna] ?? null, $valores, true); return $this; }
        public function whereNull($coluna) { $this->filtros[] = fn (array $linha) => ($linha[$coluna] ?? null) === null; return $this; }
        public function whereNotNull($coluna) { $this->filtros[] = fn (array $linha) => ($linha[$coluna] ?? null) !== null; return $this; }
        public function orderBy($coluna, $direcao = 'asc') { $this->ordem[] = [$coluna, strtolower($direcao)]; return $this; }
        public function limit(int $quantos) { $this->limite = $quantos; return $this; }

        public static function compara($atual, string $operador, $valor): bool
        {
            if ($valor === null) {
                return $operador === '=' ? $atual === null : $atual !== null;
            }
            // no SQL, NULL não é igual, diferente, maior nem menor que nada
            if ($atual === null) {
                return false;
            }
            if (is_bool($valor) || is_bool($atual)) {
                $atual = (int) (bool) $atual;
                $valor = (int) (bool) $valor;
            }

            return match ($operador) {
                '='        => $atual == $valor,
                '!=', '<>' => $atual != $valor,
                '<'        => $atual < $valor,
                '<='       => $atual <= $valor,
                '>'        => $atual > $valor,
                '>='       => $atual >= $valor,
            };
        }

        private function selecionadas(): array
        {
            $linhas = array_filter(Banco::$tabelas[$this->tabela] ?? [], function (array $linha) {
                foreach ($this->filtros as $filtro) {
                    if (!$filtro($linha)) {
                        return false;
                    }
                }

                return true;
            });
            // uasort é estável: ordenar da última chave para a primeira dá a ordem de várias colunas
            foreach (array_reverse($this->ordem) as [$coluna, $direcao]) {
                uasort($linhas, fn ($a, $b) => $direcao === 'desc' ? (($b[$coluna] ?? null) <=> ($a[$coluna] ?? null)) : (($a[$coluna] ?? null) <=> ($b[$coluna] ?? null)));
            }

            return $this->limite === null ? $linhas : array_slice($linhas, 0, $this->limite, true);
        }

        public function get($colunas = ['*']) { return new \Illuminate\Support\Collection(array_values(array_map(fn ($linha) => (object) $linha, $this->selecionadas()))); }
        public function first() { $linhas = $this->selecionadas(); return $linhas ? (object) reset($linhas) : null; }
        public function pluck($coluna) { return new \Illuminate\Support\Collection(array_values(array_map(fn ($linha) => $linha[$coluna] ?? null, $this->selecionadas()))); }
        public function value($coluna) { $linha = $this->first(); return $linha ? ($linha->$coluna ?? null) : null; }
        public function exists(): bool { return (bool) $this->selecionadas(); }
        public function count(): int { return count($this->selecionadas()); }

        public function insert(array $linhas): bool
        {
            foreach (static::emLista($linhas) as $linha) {
                Banco::inserir($this->tabela, $linha, false);
            }

            return true;
        }

        public function insertOrIgnore(array $linhas): int
        {
            $inseridas = 0;
            foreach (static::emLista($linhas) as $linha) {
                $inseridas += (int) Banco::inserir($this->tabela, $linha, true);
            }

            return $inseridas;
        }

        public function update(array $valores): int
        {
            if (isset(Banco::$falhar[$this->tabela])) {
                throw new ErroDeBanco(Banco::$falhar[$this->tabela]);
            }
            $alteradas = 0;
            foreach (array_keys($this->selecionadas()) as $id) {
                Banco::$tabelas[$this->tabela][$id] = array_merge(Banco::$tabelas[$this->tabela][$id], $valores);
                $alteradas++;
            }

            return $alteradas;
        }

        public function delete(): int
        {
            $apagadas = 0;
            foreach (array_keys($this->selecionadas()) as $id) {
                unset(Banco::$tabelas[$this->tabela][$id]);
                $apagadas++;
            }

            return $apagadas;
        }

        private static function emLista(array $linhas): array
        {
            if (!$linhas) {
                return [];
            }

            return array_is_list($linhas) && is_array($linhas[0]) ? $linhas : [$linhas];
        }
    }

    // o que o response()->json() devolve
    class RespostaJson
    {
        public function __construct(public $dados, public int $status = 200) {}
    }

    class FabricaDeResposta
    {
        public function json($dados = [], int $status = 200) { return new RespostaJson($dados, $status); }
    }
}

namespace {
    function now(): \Illuminate\Support\Carbon { return \Illuminate\Support\Carbon::parse(\Teste\Relogio::$agora, 'UTC'); }
    function config($chave, $padrao = null) { return array_key_exists($chave, \Teste\Config::$valores) ? \Teste\Config::$valores[$chave] : $padrao; }
    function encrypt($valor) { return 'cifrado:' . base64_encode(serialize($valor)); }

    function decrypt($valor)
    {
        if (!is_string($valor) || !str_starts_with($valor, 'cifrado:')) {
            throw new \RuntimeException('decrypt: valor que não foi cifrado');
        }

        return unserialize(base64_decode(substr($valor, 8)));
    }

    function session($chave = null)
    {
        if (is_array($chave)) {
            \Teste\Sessao::$dados = array_merge(\Teste\Sessao::$dados, $chave);

            return null;
        }

        return $chave === null ? \Teste\Sessao::$dados : (\Teste\Sessao::$dados[$chave] ?? null);
    }

    function response() { return new \Teste\FabricaDeResposta(); }

    // classes do App\ saem do api/app do repositório
    spl_autoload_register(function (string $classe) {
        if (str_starts_with($classe, 'App\\')) {
            $arquivo = '/repo/api/app/' . str_replace('\\', '/', substr($classe, 4)) . '.php';
            if (is_file($arquivo)) {
                require $arquivo;
            }
        }
    });

    /** Zera o estado entre os casos e liga a integração com credenciais de teste. */
    function reiniciarIfood(): void
    {
        \Teste\Banco::limpar();
        \Illuminate\Support\Facades\Cache::$dados     = [];
        \Illuminate\Support\Facades\Cache::$validades = [];
        \Illuminate\Support\Facades\Log::$registros   = [];
        \Teste\Trava::$ocupadas                       = [];
        \Teste\Http::$respostas                       = [];
        \Teste\Http::$chamadas                        = [];
        \Teste\Fila::$jobs                            = [];
        \Teste\Sessao::$dados                         = [];
        \Teste\Relogio::$agora                        = '2026-10-05 18:00:00';
        \Teste\Config::$valores                       = [
            'services.ifood.ativo'         => '1',
            'services.ifood.client_id'     => 'cliente-teste',
            'services.ifood.client_secret' => 'segredo-teste',
            'services.ifood.base_url'      => 'https://merchant-api.ifood.com.br',
        ];
    }

    /** Algum log cuja mensagem contém $trecho (com o nível, se dado). */
    function logou(string $trecho, ?string $nivel = null): bool
    {
        foreach (\Illuminate\Support\Facades\Log::$registros as [$nivelDoLog, $mensagem]) {
            if (str_contains($mensagem, $trecho) && ($nivel === null || $nivel === $nivelDoLog)) {
                return true;
            }
        }

        return false;
    }

    /** Nenhum log (mensagem e contexto) contém algum dos textos (tokens, nome, telefone, endereço). */
    function logsSem(array $textos): bool
    {
        $tudo = json_encode(\Illuminate\Support\Facades\Log::$registros, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($textos as $texto) {
            if (str_contains($tudo, $texto)) {
                return false;
            }
        }

        return true;
    }

    $GLOBALS['falhas'] = 0;

    function confere(bool $ok, string $descricao): void
    {
        if (!$ok) {
            $GLOBALS['falhas']++;
        }
        echo ($ok ? 'PASSA ' : 'FALHA ') . $descricao . PHP_EOL;
    }

    /** Roda $fazer e devolve a exceção lançada (ou null). */
    function excecao(callable $fazer): ?\Throwable
    {
        try {
            $fazer();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    function resumo(): void
    {
        echo PHP_EOL . 'FALHAS: ' . $GLOBALS['falhas'] . PHP_EOL;
    }
}
```

- [ ] **Step 2: escrever o teste das migrations**

Crie `scripts/teste-php/ifood-migrations.php`:

```php
<?php

// Integração iFood: as três tabelas novas (entregas_ifood_lojas, _eventos e _pedidos).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-migrations.php

require __DIR__ . '/stubs-ifood.php';

use Illuminate\Support\Facades\Schema;

/** Colunas da tabela criada pela migration: nome => Teste\Coluna. */
function colunasDa(string $arquivo, string $tabela): array
{
    $migration = require '/repo/api/database/migrations/' . $arquivo;
    $migration->up();
    $colunas = [];
    foreach ((Schema::$criadas[$tabela] ?? null)?->colunas ?? [] as $coluna) {
        $colunas[$coluna->argumentos[0] ?? $coluna->tipo] = $coluna;
    }

    return $colunas;
}

function tem(array $colunas, string $nome, string $tipo, array $modificadores = []): bool
{
    $coluna = $colunas[$nome] ?? null;
    if (!$coluna || $coluna->tipo !== $tipo) {
        return false;
    }
    foreach ($modificadores as $modificador) {
        if (!array_key_exists($modificador, $coluna->modificadores)) {
            return false;
        }
    }

    return true;
}

echo '== entregas_ifood_lojas' . PHP_EOL;
$lojas = colunasDa('2026_10_05_120000_create_entregas_ifood_lojas_table.php', 'entregas_ifood_lojas');
confere(tem($lojas, 'vendor_uuid', 'char', ['unique']), 'vendor_uuid único: uma loja, um vínculo');
confere(tem($lojas, 'merchant_id', 'string', ['nullable', 'unique']), 'merchant_id único e nulo depois de desvincular');
confere(tem($lojas, 'access_token', 'text', ['nullable']) && tem($lojas, 'refresh_token', 'text', ['nullable']), 'tokens em text (cifrados, até 8000 caracteres)');
confere(tem($lojas, 'company_uuid', 'char', ['index']) && tem($lojas, 'situacao', 'string', ['index']), 'company_uuid e situacao com índice');
confere(isset($lojas['id'], $lojas['nome_ifood'], $lojas['expira_em'], $lojas['vinculado_em'], $lojas['renovado_em'], $lojas['timestamps']), 'id, nome, validade, datas');

echo '== entregas_ifood_eventos' . PHP_EOL;
$eventos = colunasDa('2026_10_05_120100_create_entregas_ifood_eventos_table.php', 'entregas_ifood_eventos');
confere(tem($eventos, 'evento_id', 'string', ['unique']), 'evento_id (id do iFood) único: descarta repetidos');
confere(tem($eventos, 'pedido_ifood_id', 'string', ['index']) && tem($eventos, 'merchant_id', 'string', ['index']), 'pedido e loja com índice');
confere(($eventos['criado_no_ifood']->argumentos ?? null) === ['criado_no_ifood', 3], 'createdAt com milissegundos');
confere(tem($eventos, 'processado_em', 'timestamp', ['nullable', 'index']) && ($eventos['ignorado']->modificadores['default'] ?? null) === [false], 'processado_em e ignorado');
confere(tem($eventos, 'payload', 'json', ['nullable']) && isset($eventos['codigo']), 'payload e código');

echo '== entregas_ifood_pedidos' . PHP_EOL;
$pedidos = colunasDa('2026_10_05_120200_create_entregas_ifood_pedidos_table.php', 'entregas_ifood_pedidos');
confere(tem($pedidos, 'pedido_ifood_id', 'string', ['unique']), 'pedido_ifood_id único: um pedido iFood, um pedido nosso');
confere(tem($pedidos, 'order_uuid', 'char', ['nullable', 'unique']), 'order_uuid único');
foreach (['numero', 'merchant_id', 'vendor_uuid', 'telefone_0800', 'localizador', 'telefone_expira_em', 'cobrar_centavos', 'forma_pagamento', 'troco_para_centavos', 'observacoes', 'complemento', 'referencia', 'exige_codigo', 'ultima_acao', 'cancelado_pelo_ifood_em', 'pago_mesmo_cancelado', 'teste', 'agendado', 'despachar_em', 'despachado_em', 'timestamps'] as $coluna) {
    confere(isset($pedidos[$coluna]), "coluna {$coluna}");
}
confere(($pedidos['cobrar_centavos']->modificadores['default'] ?? null) === [0], 'cobrar_centavos começa em 0 (pago online)');
foreach (['exige_codigo', 'pago_mesmo_cancelado', 'teste', 'agendado'] as $coluna) {
    confere(($pedidos[$coluna]->modificadores['default'] ?? null) === [false], "{$coluna} começa falso");
}

echo '== down' . PHP_EOL;
foreach (['2026_10_05_120000_create_entregas_ifood_lojas_table.php' => 'entregas_ifood_lojas', '2026_10_05_120100_create_entregas_ifood_eventos_table.php' => 'entregas_ifood_eventos', '2026_10_05_120200_create_entregas_ifood_pedidos_table.php' => 'entregas_ifood_pedidos'] as $arquivo => $tabela) {
    (require '/repo/api/database/migrations/' . $arquivo)->down();
    confere(!isset(Schema::$criadas[$tabela]), "down apaga {$tabela}");
}

resumo();
```

- [ ] **Step 3: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-migrations.php`
Expected: erro de PHP "Failed opening required '/repo/api/database/migrations/2026_10_05_120000_create_entregas_ifood_lojas_table.php'" (sai com 1).

- [ ] **Step 4: criar as migrations**

`api/database/migrations/2026_10_05_120000_create_entregas_ifood_lojas_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: vínculo de cada Loja (Vendor) com uma loja do iFood (merchantId), pelo app distribuído
 * (App\Support\Entregas\Ifood\VinculosIfood). Os tokens ficam cifrados com encrypt() (APP_KEY); merchant_id é nulo
 * depois de desvincular, para o merchant poder ir para outra loja (no MySQL, NULL não conta na chave única).
 * Roda no `php artisan migrate --force` do deploy.sh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_ifood_lojas', function (Blueprint $table) {
            $table->id();
            $table->char('company_uuid', 36)->index();
            $table->char('vendor_uuid', 36)->unique();
            $table->string('merchant_id', 64)->nullable()->unique();
            $table->string('nome_ifood', 190)->nullable();
            // até 8000 caracteres cada (documentação do iFood), cifrados: text
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expira_em')->nullable();
            // vinculada | vinculo_perdido | desvinculada
            $table->string('situacao', 20)->index();
            $table->timestamp('vinculado_em')->nullable();
            $table->timestamp('renovado_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_ifood_lojas');
    }
};
```

`api/database/migrations/2026_10_05_120100_create_entregas_ifood_eventos_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: eventos do polling do iFood (entregas:ifood-polling), gravados antes do ack. O `evento_id`
 * (o `id` do iFood) é único: o iFood reenvia eventos e o insertOrIgnore descarta os repetidos. O ProcessarPedidoIfood
 * marca `processado_em` (e `ignorado`, para código desconhecido ou loja não vinculada); os processados há mais de 7
 * dias são apagados pelo próprio polling, uma vez por dia. O payload é o evento como veio (não traz dados do cliente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_ifood_eventos', function (Blueprint $table) {
            $table->id();
            $table->string('evento_id', 64)->unique();
            $table->string('merchant_id', 64)->index();
            $table->string('pedido_ifood_id', 64)->index();
            $table->string('codigo', 10);
            // createdAt do iFood, em UTC, com milissegundos (a ordem dos eventos sai daqui)
            $table->timestamp('criado_no_ifood', 3)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('processado_em')->nullable()->index();
            $table->boolean('ignorado')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_ifood_eventos');
    }
};
```

`api/database/migrations/2026_10_05_120200_create_entregas_ifood_pedidos_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas RestaurantePro: dados iFood de cada pedido criado pela integração (App\Support\Entregas\Ifood). Fica fora do
 * `meta` do Order de propósito: o `meta` sai na API v1 e no socket, e aqui estão o 0800 com o localizador e a cobrança.
 * `pedido_ifood_id` único = o mesmo pedido do iFood nunca vira dois pedidos. `despachar_em`/`despachado_em` guiam o
 * entregas:ifood-agendados (agendados e despacho que falhou); o pedido de teste não tem `despachar_em`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas_ifood_pedidos', function (Blueprint $table) {
            $table->id();
            $table->char('company_uuid', 36)->index();
            $table->char('order_uuid', 36)->nullable()->unique();
            $table->string('pedido_ifood_id', 64)->unique();
            // número curto do iFood (displayId), o mesmo do internal_id do Order
            $table->string('numero', 20)->nullable();
            $table->string('merchant_id', 64)->index();
            $table->char('vendor_uuid', 36)->nullable()->index();
            $table->string('telefone_0800', 30)->nullable();
            $table->string('localizador', 20)->nullable();
            $table->timestamp('telefone_expira_em')->nullable();
            // a cobrar na porta (payments.pending), em centavos; 0 = pago online
            $table->unsignedInteger('cobrar_centavos')->default(0);
            $table->string('forma_pagamento', 30)->nullable();
            $table->unsignedInteger('troco_para_centavos')->nullable();
            $table->text('observacoes')->nullable();
            $table->string('complemento', 190)->nullable();
            $table->string('referencia', 190)->nullable();
            // DDCR recebido: a conclusão exige o código do cliente (etapa 4)
            $table->boolean('exige_codigo')->default(false);
            // última ação de logística aceita pelo iFood (etapa 3)
            $table->string('ultima_acao', 30)->nullable();
            $table->timestamp('cancelado_pelo_ifood_em')->nullable();
            $table->boolean('pago_mesmo_cancelado')->default(false);
            $table->boolean('teste')->default(false);
            $table->boolean('agendado')->default(false);
            $table->timestamp('despachar_em')->nullable()->index();
            $table->timestamp('despachado_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_ifood_pedidos');
    }
};
```

- [ ] **Step 5: rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-migrations.php`
Expected: só `PASSA`, terminando em `FALHAS: 0`.

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/database/migrations/2026_10_05_*.php scripts/teste-php/stubs-ifood.php`
Expected: `OK` nos quatro.

- [ ] **Step 6: commit**

```bash
git rev-parse --show-toplevel
git add scripts/teste-php/stubs-ifood.php scripts/teste-php/ifood-migrations.php api/database/migrations/2026_10_05_120000_create_entregas_ifood_lojas_table.php api/database/migrations/2026_10_05_120100_create_entregas_ifood_eventos_table.php api/database/migrations/2026_10_05_120200_create_entregas_ifood_pedidos_table.php
git commit -m "iFood: tabelas do vínculo, dos eventos e dos pedidos, e stubs dos testes

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: porta HTTP (ClienteIfood)

**Files:**
- Create: `api/app/Support/Entregas/Ifood/ErroIfood.php`
- Create: `api/app/Support/Entregas/Ifood/ClienteIfood.php`
- Create: `scripts/teste-php/ifood-cliente.php`

URLs e formatos da sonda: `POST /authentication/v1.0/oauth/userCode` e `/oauth/token` em form-urlencoded; `GET
/merchant/v1.0/merchants`; `GET /events/v1.0/events:polling?excludeHeartbeat=true` com `x-polling-merchants` (até
100); 204 = nenhum evento; `POST /events/v1.0/events/acknowledgment` com `[{id}]` (202); `GET
/logistics/v1.0/orders/{id}`. Fora de 2xx vira `ErroIfood` (429 com `Retry-After`, 60 s se não vier); rede vira
status 0. O cliente não loga nada.

- [ ] **Step 1: escrever o teste**

Crie `scripts/teste-php/ifood-cliente.php`:

```php
<?php

// Integração iFood: a porta HTTP (ClienteIfood) e os erros tipados (ErroIfood).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-cliente.php

require __DIR__ . '/stubs-ifood.php';

use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\ErroIfood;
use Illuminate\Support\Facades\Log;
use Teste\Config;
use Teste\Http;

const BASE = 'https://merchant-api.ifood.com.br';

echo '== Interruptor' . PHP_EOL;
reiniciarIfood();
confere(ClienteIfood::ligada(), 'ligada com ENTREGAS_IFOOD=1 e as credenciais');
foreach (['' => 'vazio', '0' => '0', 'false' => 'false'] as $valor => $nome) {
    Config::$valores['services.ifood.ativo'] = $valor;
    confere(!ClienteIfood::ligada(), "ENTREGAS_IFOOD {$nome} = desligada");
}
Config::$valores['services.ifood.ativo'] = 'true';
confere(ClienteIfood::ligada(), 'ENTREGAS_IFOOD=true também liga');
Config::$valores['services.ifood.client_secret'] = '';
confere(!ClienteIfood::ligada(), 'sem o client secret = desligada');

echo '== Código de vínculo (userCode)' . PHP_EOL;
reiniciarIfood();
$cliente = new ClienteIfood();
Http::responder(200, ['userCode' => 'ABCD-EFGH', 'authorizationCodeVerifier' => 'verificador-1', 'verificationUrl' => 'https://portal.ifood.com.br/apps/code', 'verificationUrlComplete' => 'https://portal.ifood.com.br/apps/code?c=ABCD-EFGH', 'expiresIn' => 600]);
$codigo  = $cliente->pedirCodigoDeVinculo();
$chamada = Http::$chamadas[0];
confere($chamada['metodo'] === 'POST' && $chamada['url'] === BASE . '/authentication/v1.0/oauth/userCode', 'POST no /oauth/userCode');
confere($chamada['form'] === true && $chamada['dados'] === ['clientId' => 'cliente-teste'], 'form-urlencoded só com o clientId');
confere($chamada['timeout'] === ClienteIfood::TEMPO_LIMITE, 'com tempo limite');
confere($codigo['userCode'] === 'ABCD-EFGH' && $codigo['authorizationCodeVerifier'] === 'verificador-1', 'devolve o userCode e o verificador');

echo '== Token (authorization_code e refresh_token)' . PHP_EOL;
Http::responder(200, ['accessToken' => 'token-1', 'type' => 'bearer', 'expiresIn' => 21600, 'refreshToken' => 'refresh-1']);
$tokens  = $cliente->trocarCodigo('AUTH-1', 'verificador-1');
$chamada = Http::$chamadas[1];
confere($chamada['url'] === BASE . '/authentication/v1.0/oauth/token' && $chamada['form'] === true, 'POST form no /oauth/token');
confere($chamada['dados'] === ['grantType' => 'authorization_code', 'clientId' => 'cliente-teste', 'clientSecret' => 'segredo-teste', 'authorizationCode' => 'AUTH-1', 'authorizationCodeVerifier' => 'verificador-1'], 'campos do authorization_code');
confere($tokens['accessToken'] === 'token-1' && $tokens['refreshToken'] === 'refresh-1', 'devolve os tokens como vieram');
Http::responder(200, ['accessToken' => 'token-2', 'type' => 'bearer', 'expiresIn' => 21600]);
$cliente->renovar('refresh-1');
confere(Http::$chamadas[2]['dados'] === ['grantType' => 'refresh_token', 'clientId' => 'cliente-teste', 'clientSecret' => 'segredo-teste', 'refreshToken' => 'refresh-1'], 'campos do refresh_token');
Http::responder(200, ['type' => 'bearer']);
$erro = excecao(fn () => $cliente->renovar('refresh-1'));
confere($erro instanceof ErroIfood && $erro->operacao === 'refresh', 'resposta sem accessToken vira ErroIfood');

echo '== Lojas do token' . PHP_EOL;
reiniciarIfood();
Http::responder(200, [['id' => 'merchant-1', 'name' => 'Pizzaria Um', 'corporateName' => 'Pizzaria Um LTDA']]);
$lojas = $cliente->lojasDoToken('token-1');
confere(Http::urls() === ['GET /merchant/v1.0/merchants'] && Http::$chamadas[0]['token'] === 'token-1', 'GET /merchant/v1.0/merchants com Bearer');
confere($lojas === [['id' => 'merchant-1', 'name' => 'Pizzaria Um', 'corporateName' => 'Pizzaria Um LTDA']], 'devolve as lojas');

echo '== Polling' . PHP_EOL;
reiniciarIfood();
$evento = ['id' => 'ev-1', 'code' => 'PLC', 'fullCode' => 'PLACED', 'orderId' => 'pedido-1', 'merchantId' => 'merchant-1', 'createdAt' => '2026-10-05T18:00:00.123Z', 'salesChannel' => 'IFOOD'];
Http::responder(200, [$evento]);
$eventos = $cliente->polling('token-1', ['merchant-1', 'merchant-2', 'merchant-1']);
$chamada = Http::$chamadas[0];
confere($chamada['metodo'] === 'GET' && $chamada['url'] === BASE . '/events/v1.0/events:polling', 'GET /events/v1.0/events:polling');
confere($chamada['dados'] === ['excludeHeartbeat' => 'true'], 'com excludeHeartbeat=true (sem ele a loja abre indevidamente)');
confere($chamada['headers'] === ['x-polling-merchants' => 'merchant-1,merchant-2'], 'x-polling-merchants sem repetidos');
confere($chamada['token'] === 'token-1' && $eventos === [$evento], 'Bearer e eventos devolvidos');
Http::responder(204);
confere($cliente->polling('token-1', ['merchant-1']) === [], '204 = nenhum evento');
$cento = array_map(fn ($i) => "merchant-{$i}", range(1, 101));
confere(excecao(fn () => $cliente->polling('token-1', $cento)) instanceof InvalidArgumentException, 'mais de 100 lojas numa chamada é recusado antes de chamar');
confere(excecao(fn () => $cliente->polling('token-1', [])) instanceof InvalidArgumentException, 'nenhuma loja também');
confere(count(Http::$chamadas) === 2, 'nenhuma chamada a mais');

echo '== Ack' . PHP_EOL;
reiniciarIfood();
Http::responder(202);
$cliente->ack('token-1', ['ev-1', 'ev-2', 'ev-1']);
confere(Http::urls() === ['POST /events/v1.0/events/acknowledgment'], 'POST /events/v1.0/events/acknowledgment');
confere(Http::$chamadas[0]['dados'] === [['id' => 'ev-1'], ['id' => 'ev-2']] && Http::$chamadas[0]['form'] === false, 'corpo JSON [{id}] sem repetidos');
Http::responder(202);
Http::responder(202);
$cliente->ack('token-1', array_map(fn ($i) => "ev-{$i}", range(1, 2001)));
confere(count(Http::$chamadas) === 3 && count(Http::$chamadas[1]['dados']) === 2000 && count(Http::$chamadas[2]['dados']) === 1, 'mais de 2000 ids vão em dois acks');

echo '== Pedido do Logistics' . PHP_EOL;
reiniciarIfood();
Http::responder(200, ['id' => 'pedido-1', 'displayId' => '4821']);
$pedido = $cliente->pedidoLogistics('token-1', 'pedido-1');
confere(Http::urls() === ['GET /logistics/v1.0/orders/pedido-1'] && $pedido['displayId'] === '4821', 'GET /logistics/v1.0/orders/{id}');

echo '== Erros' . PHP_EOL;
reiniciarIfood();
Http::responder(429, ['message' => 'Too Many Requests'], ['Retry-After' => '17']);
$erro = excecao(fn () => $cliente->polling('token-1', ['merchant-1']));
confere($erro instanceof ErroIfood && $erro->limiteExcedido() && $erro->retryAfter === 17 && $erro->temporario(), '429 com Retry-After: 17 s');
Http::responder(429, ['code' => '429', 'message' => 'Throttling applied.']);
$erro = excecao(fn () => $cliente->polling('token-1', ['merchant-1']));
confere($erro instanceof ErroIfood && $erro->retryAfter === ClienteIfood::ESPERA_PADRAO_429, '429 sem Retry-After: espera padrão');
Http::responder(401, ['error' => ['code' => 'Unauthorized', 'message' => 'Bad credentials']]);
$erro = excecao(fn () => $cliente->pedidoLogistics('token-1', 'pedido-1'));
confere($erro instanceof ErroIfood && $erro->naoAutorizado() && !$erro->temporario() && $erro->operacao === 'pedido', '401 vira naoAutorizado');
Http::responder(403, ['unauthorizedMerchants' => ['merchant-9']]);
$erro = excecao(fn () => $cliente->polling('token-1', ['merchant-9']));
confere($erro instanceof ErroIfood && $erro->status === 403 && $erro->corpoJson() === ['unauthorizedMerchants' => ['merchant-9']], '403 traz o corpo (unauthorizedMerchants)');
Http::responder(404, ['message' => 'Order not found']);
$erro = excecao(fn () => $cliente->pedidoLogistics('token-1', 'pedido-x'));
confere($erro instanceof ErroIfood && $erro->status === 404 && !$erro->temporario(), '404 não é temporário');
Http::responder(503, 'Service Unavailable');
$erro = excecao(fn () => $cliente->pedidoLogistics('token-1', 'pedido-1'));
confere($erro instanceof ErroIfood && $erro->temporario() && $erro->corpo === 'Service Unavailable', '5xx é temporário');
Http::falharConexao();
$erro = excecao(fn () => $cliente->ack('token-1', ['ev-1']));
confere($erro instanceof ErroIfood && $erro->status === 0 && $erro->temporario(), 'falha de rede vira status 0');
confere(Log::$registros === [], 'a porta HTTP não registra nada no log');

resumo();
```

- [ ] **Step 2: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-cliente.php`
Expected: erro de PHP `Class "App\Support\Entregas\Ifood\ClienteIfood" not found` (sai com 1).

- [ ] **Step 3: criar o erro tipado**

`api/app/Support/Entregas/Ifood/ErroIfood.php`:

```php
<?php

namespace App\Support\Entregas\Ifood;

use RuntimeException;

/**
 * Entregas RestaurantePro: resposta de erro do iFood numa chamada do ClienteIfood, ou falha de rede (status 0).
 *
 * O corpo é o da resposta de erro (ex.: {"errorType","description","code"}), que não traz dados do cliente; mesmo assim,
 * os logs usam só a operação e o status. No 429, retryAfter vem do cabeçalho Retry-After (segundos; 60 se não vier).
 */
class ErroIfood extends RuntimeException
{
    public function __construct(
        public readonly string $operacao,
        public readonly int $status,
        public readonly string $corpo = '',
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct("iFood respondeu {$status} em {$operacao}");
    }

    /** Token vencido ou inválido: quem chama renova e repete uma vez (VinculosIfood::comToken). */
    public function naoAutorizado(): bool
    {
        return $this->status === 401;
    }

    public function limiteExcedido(): bool
    {
        return $this->status === 429;
    }

    /** Rede, 429 ou 5xx: vale tentar de novo mais tarde. */
    public function temporario(): bool
    {
        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }

    /** O corpo como JSON, ou null. */
    public function corpoJson(): ?array
    {
        $dados = json_decode($this->corpo, true);

        return is_array($dados) ? $dados : null;
    }
}
```

- [ ] **Step 4: criar o cliente**

`api/app/Support/Entregas/Ifood/ClienteIfood.php`:

```php
<?php

namespace App\Support\Entregas\Ifood;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Entregas RestaurantePro: única porta HTTP para a Merchant API do iFood (app distribuído).
 *
 * URLs e formatos conferidos com a sonda de 2026-10-05 (docs/ifood/referencia-logistics.md, "Descobertas da sonda"):
 * base https://merchant-api.ifood.com.br; token e userCode em form-urlencoded; polling com excludeHeartbeat=true (sem
 * ele a loja abre indevidamente) e o cabeçalho x-polling-merchants (até 100 ids); 204 = nenhum evento; ack com [{id}].
 *
 * Toda resposta fora de 2xx vira ErroIfood (status, corpo e Retry-After); falha de rede vira ErroIfood com status 0.
 * Não renova token: quem chama (VinculosIfood::comToken) renova no 401 e repete uma vez. Não registra nada no log,
 * para nenhum token, nome, telefone ou endereço ir parar lá.
 */
class ClienteIfood
{
    /** Segundos de espera por resposta. */
    public const TEMPO_LIMITE = 15;

    /** Lojas por chamada de polling (x-polling-merchants). */
    public const MAX_MERCHANTS_POR_POLLING = 100;

    /** Ids por ack: a página de conceitos diz até 2000; a referência, 10000. Fica o menor. */
    public const MAX_IDS_POR_ACK = 2000;

    /** Espera no 429 sem Retry-After, em segundos. */
    public const ESPERA_PADRAO_429 = 60;

    protected string $baseUrl;
    protected string $clientId;
    protected string $clientSecret;

    public function __construct(?string $baseUrl = null, ?string $clientId = null, ?string $clientSecret = null)
    {
        $this->baseUrl      = rtrim($baseUrl ?? (string) config('services.ifood.base_url', 'https://merchant-api.ifood.com.br'), '/');
        $this->clientId     = $clientId ?? (string) config('services.ifood.client_id');
        $this->clientSecret = $clientSecret ?? (string) config('services.ifood.client_secret');
    }

    /** Integração ligada: ENTREGAS_IFOOD=1 (ou true) e as credenciais do app preenchidas no stack.env. */
    public static function ligada(): bool
    {
        return filter_var(config('services.ifood.ativo'), FILTER_VALIDATE_BOOLEAN)
            && (string) config('services.ifood.client_id') !== ''
            && (string) config('services.ifood.client_secret') !== '';
    }

    /** Código de vínculo (userCode, authorizationCodeVerifier, verificationUrl, verificationUrlComplete, expiresIn). */
    public function pedirCodigoDeVinculo(): array
    {
        $resposta = $this->enviar('userCode', fn () => Http::asForm()->acceptJson()->timeout(static::TEMPO_LIMITE)
            ->post($this->baseUrl . '/authentication/v1.0/oauth/userCode', ['clientId' => $this->clientId]));

        return (array) $resposta->json();
    }

    /** Troca o código de autorização (que o dono da loja recebe no Portal do Parceiro) pelos tokens. */
    public function trocarCodigo(string $codigoDeAutorizacao, string $verificador): array
    {
        return $this->token('token', [
            'grantType'                 => 'authorization_code',
            'clientId'                  => $this->clientId,
            'clientSecret'              => $this->clientSecret,
            'authorizationCode'         => $codigoDeAutorizacao,
            'authorizationCodeVerifier' => $verificador,
        ]);
    }

    /** Token novo pelo refresh token. */
    public function renovar(string $refreshToken): array
    {
        return $this->token('refresh', [
            'grantType'    => 'refresh_token',
            'clientId'     => $this->clientId,
            'clientSecret' => $this->clientSecret,
            'refreshToken' => $refreshToken,
        ]);
    }

    /** Lojas que o token enxerga: [{id, name, corporateName}]. */
    public function lojasDoToken(string $token): array
    {
        $resposta = $this->enviar('merchants', fn () => $this->comToken($token)->get($this->baseUrl . '/merchant/v1.0/merchants'));

        return array_values(array_filter((array) $resposta->json(), 'is_array'));
    }

    /** Eventos ainda sem ack das lojas dadas (1 a 100); [] quando o iFood responde 204. */
    public function polling(string $token, array $merchantIds): array
    {
        $merchantIds = array_values(array_unique(array_filter($merchantIds, fn ($id) => is_string($id) && $id !== '')));
        if (!$merchantIds || count($merchantIds) > static::MAX_MERCHANTS_POR_POLLING) {
            throw new InvalidArgumentException('polling: de 1 a ' . static::MAX_MERCHANTS_POR_POLLING . ' lojas por chamada');
        }

        $resposta = $this->enviar('polling', fn () => $this->comToken($token)
            ->withHeaders(['x-polling-merchants' => implode(',', $merchantIds)])
            ->get($this->baseUrl . '/events/v1.0/events:polling', ['excludeHeartbeat' => 'true']));

        return $resposta->status() === 204 ? [] : array_values(array_filter((array) $resposta->json(), 'is_array'));
    }

    /** Confirma o recebimento dos eventos (202), em lotes de até MAX_IDS_POR_ACK. */
    public function ack(string $token, array $ids): void
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => is_string($id) && $id !== '')));
        foreach (array_chunk($ids, static::MAX_IDS_POR_ACK) as $lote) {
            $this->enviar('ack', fn () => $this->comToken($token)
                ->post($this->baseUrl . '/events/v1.0/events/acknowledgment', array_map(fn ($id) => ['id' => $id], $lote)));
        }
    }

    /** O pedido no módulo Logistics (GET /logistics/v1.0/orders/{id}). */
    public function pedidoLogistics(string $token, string $pedidoId): array
    {
        $resposta = $this->enviar('pedido', fn () => $this->comToken($token)->get($this->baseUrl . '/logistics/v1.0/orders/' . rawurlencode($pedidoId)));

        return (array) $resposta->json();
    }

    protected function token(string $operacao, array $campos): array
    {
        $resposta = $this->enviar($operacao, fn () => Http::asForm()->acceptJson()->timeout(static::TEMPO_LIMITE)
            ->post($this->baseUrl . '/authentication/v1.0/oauth/token', $campos));

        $dados = (array) $resposta->json();
        if (!is_string($dados['accessToken'] ?? null) || $dados['accessToken'] === '') {
            throw new ErroIfood($operacao, $resposta->status(), 'resposta sem accessToken');
        }

        return $dados;
    }

    protected function comToken(string $token)
    {
        return Http::withToken($token)->acceptJson()->timeout(static::TEMPO_LIMITE);
    }

    protected function enviar(string $operacao, Closure $chamada): Response
    {
        try {
            $resposta = $chamada();
        } catch (ConnectionException $e) {
            throw new ErroIfood($operacao, 0, substr($e->getMessage(), 0, 300));
        }

        if ($resposta->successful()) {
            return $resposta;
        }

        $espera = null;
        if ($resposta->status() === 429) {
            $cabecalho = trim($resposta->header('Retry-After'));
            $espera    = ctype_digit($cabecalho) ? max(1, (int) $cabecalho) : static::ESPERA_PADRAO_429;
        }

        throw new ErroIfood($operacao, $resposta->status(), substr($resposta->body(), 0, 2000), $espera);
    }
}
```

- [ ] **Step 5: rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-cliente.php`
Expected: `FALHAS: 0`.

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/Ifood/ErroIfood.php api/app/Support/Entregas/Ifood/ClienteIfood.php`
Expected: `OK` nos dois.

- [ ] **Step 6: commit**

```bash
git rev-parse --show-toplevel
git add api/app/Support/Entregas/Ifood/ErroIfood.php api/app/Support/Entregas/Ifood/ClienteIfood.php scripts/teste-php/ifood-cliente.php
git commit -m "iFood: porta HTTP única (token, userCode, lojas, polling, ack e pedido) com erros tipados

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: vínculo das lojas e tokens (VinculosIfood)

**Files:**
- Create: `api/app/Support/Entregas/Ifood/ErroDeVinculo.php`
- Create: `api/app/Support/Entregas/Ifood/VinculoPerdido.php`
- Create: `api/app/Support/Entregas/Ifood/VinculosIfood.php`
- Create: `scripts/teste-php/ifood-vinculos.php`

- [ ] **Step 1: escrever o teste**

Crie `scripts/teste-php/ifood-vinculos.php`:

```php
<?php

// Integração iFood: vínculo das lojas (VinculosIfood): userCode, troca do código, escolha da loja, renovação e perda.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-vinculos.php

require __DIR__ . '/stubs-ifood.php';

use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\ErroDeVinculo;
use App\Support\Entregas\Ifood\ErroIfood;
use App\Support\Entregas\Ifood\VinculoPerdido;
use App\Support\Entregas\Ifood\VinculosIfood;
use Illuminate\Support\Facades\Cache;
use Teste\Banco;
use Teste\Http;
use Teste\Relogio;

const EMPRESA = 'empresa-1';
const LOJA_A  = 'vendor-a';
const LOJA_B  = 'vendor-b';

function vinculos(): VinculosIfood
{
    return new VinculosIfood(new ClienteIfood());
}

function respostaDoCodigo(): void
{
    Http::responder(200, ['userCode' => 'ABCD-EFGH', 'authorizationCodeVerifier' => 'verificador-1', 'verificationUrl' => 'https://portal.ifood.com.br/apps/code', 'verificationUrlComplete' => 'https://portal.ifood.com.br/apps/code?c=ABCD-EFGH', 'expiresIn' => 600]);
}

function respostaDoToken(string $acesso = 'token-1', ?string $refresh = 'refresh-1'): void
{
    $corpo = ['accessToken' => $acesso, 'type' => 'bearer', 'expiresIn' => 21600];
    if ($refresh !== null) {
        $corpo['refreshToken'] = $refresh;
    }
    Http::responder(200, $corpo);
}

/** Uma loja já vinculada, gravada direto na tabela. */
function vinculada(string $vendor, string $merchant, string $expiraEm, string $acesso = 'token-1', ?string $refresh = 'refresh-1'): object
{
    Banco::inserir('entregas_ifood_lojas', [
        'company_uuid' => EMPRESA, 'vendor_uuid' => $vendor, 'merchant_id' => $merchant, 'nome_ifood' => 'Loja ' . $merchant,
        'access_token' => encrypt($acesso), 'refresh_token' => $refresh === null ? null : encrypt($refresh), 'expira_em' => $expiraEm,
        'situacao' => 'vinculada', 'vinculado_em' => '2026-10-05 12:00:00', 'renovado_em' => null, 'created_at' => '2026-10-05 12:00:00', 'updated_at' => '2026-10-05 12:00:00',
    ], false);

    return linhaDa($vendor);
}

function linhaDa(string $vendor): ?object
{
    foreach (Banco::linhas('entregas_ifood_lojas') as $linha) {
        if ($linha->vendor_uuid === $vendor) {
            return $linha;
        }
    }

    return null;
}

echo '== Passo 1: código de vínculo' . PHP_EOL;
reiniciarIfood();
respostaDoCodigo();
$codigo = vinculos()->iniciar(LOJA_A);
confere($codigo === ['codigo' => 'ABCD-EFGH', 'link' => 'https://portal.ifood.com.br/apps/code?c=ABCD-EFGH', 'expira_em_segundos' => 600], 'devolve o código, o link com o código e a validade');
$chave = VinculosIfood::chaveDoVerificador(LOJA_A);
confere(isset(Cache::$dados[$chave]) && Cache::$dados[$chave] !== 'verificador-1' && decrypt(Cache::$dados[$chave]) === 'verificador-1', 'verificador no cache, cifrado, por loja');
confere(Cache::$validades[$chave] === 600, 'pelo tempo do código (10 min)');
confere(Banco::linhas('entregas_ifood_lojas') === [], 'nada gravado na tabela ainda');

echo '== Passo 2: uma loja na conta' . PHP_EOL;
respostaDoToken();
Http::responder(200, [['id' => 'merchant-1', 'name' => 'Pizzaria Um', 'corporateName' => 'Pizzaria Um LTDA']]);
$resultado = vinculos()->concluir(EMPRESA, LOJA_A, '  AUTH-1  ');
confere($resultado === ['situacao' => 'vinculada'], 'vinculada direto');
confere(Http::$chamadas[1]['dados']['authorizationCode'] === 'AUTH-1' && Http::$chamadas[1]['dados']['authorizationCodeVerifier'] === 'verificador-1', 'troca com o código (sem espaços) e o verificador guardado');
confere(Http::$chamadas[2]['token'] === 'token-1', 'lista as lojas com o token novo');
$linha = linhaDa(LOJA_A);
confere($linha && $linha->merchant_id === 'merchant-1' && $linha->nome_ifood === 'Pizzaria Um' && $linha->company_uuid === EMPRESA && $linha->situacao === 'vinculada', 'linha com merchant, nome e situação');
confere($linha->access_token !== 'token-1' && decrypt($linha->access_token) === 'token-1' && decrypt($linha->refresh_token) === 'refresh-1', 'tokens cifrados');
confere($linha->expira_em === '2026-10-06 00:00:00', 'validade = agora + expiresIn (6 h)');
confere(!isset(Cache::$dados[$chave]), 'verificador apagado (o código vale uma vez)');
confere(logou('[entregas] ifood: loja vinculada', 'info'), 'log da loja vinculada');

echo '== Vincular de novo a mesma loja atualiza a linha' . PHP_EOL;
respostaDoCodigo();
vinculos()->iniciar(LOJA_A);
respostaDoToken('token-9', 'refresh-9');
Http::responder(200, [['id' => 'merchant-1', 'name' => 'Pizzaria Um']]);
vinculos()->concluir(EMPRESA, LOJA_A, 'AUTH-2');
confere(count(Banco::linhas('entregas_ifood_lojas')) === 1 && decrypt(linhaDa(LOJA_A)->access_token) === 'token-9', 'uma linha só, com o token novo');

echo '== Código vencido' . PHP_EOL;
reiniciarIfood();
$erro = excecao(fn () => vinculos()->concluir(EMPRESA, LOJA_A, 'AUTH-1'));
confere($erro instanceof ErroDeVinculo && str_contains($erro->getMessage(), 'venceu'), 'sem verificador no cache: "código venceu"');
confere(Http::$chamadas === [], 'nem chama o iFood');

echo '== Várias lojas na conta: a central escolhe' . PHP_EOL;
reiniciarIfood();
respostaDoCodigo();
vinculos()->iniciar(LOJA_A);
respostaDoToken();
Http::responder(200, [['id' => 'merchant-1', 'name' => 'Pizzaria Centro'], ['id' => 'merchant-2', 'name' => 'Pizzaria Bairro']]);
$resultado = vinculos()->concluir(EMPRESA, LOJA_A, 'AUTH-1');
confere($resultado === ['situacao' => 'escolher', 'lojas' => [['id' => 'merchant-1', 'nome' => 'Pizzaria Centro'], ['id' => 'merchant-2', 'nome' => 'Pizzaria Bairro']]], 'devolve as lojas para escolher');
confere(Banco::linhas('entregas_ifood_lojas') === [], 'nada gravado antes da escolha');
$escolha = Cache::$dados[VinculosIfood::chaveDaEscolha(LOJA_A)] ?? null;
confere(is_string($escolha) && !str_contains($escolha, 'token-1') && Cache::$validades[VinculosIfood::chaveDaEscolha(LOJA_A)] === 600, 'tokens da escolha no cache, cifrados, por 10 min');
$erro = excecao(fn () => vinculos()->escolher(EMPRESA, LOJA_A, 'merchant-9'));
confere($erro instanceof ErroDeVinculo && Banco::linhas('entregas_ifood_lojas') === [], 'loja fora da conta é recusada');
$resultado = vinculos()->escolher(EMPRESA, LOJA_A, 'merchant-2');
confere($resultado === ['situacao' => 'vinculada'] && linhaDa(LOJA_A)->merchant_id === 'merchant-2' && linhaDa(LOJA_A)->nome_ifood === 'Pizzaria Bairro', 'vincula a escolhida');
confere(!isset(Cache::$dados[VinculosIfood::chaveDaEscolha(LOJA_A)]) && count(Http::$chamadas) === 3, 'escolha apagada, sem chamar o iFood de novo');
$erro = excecao(fn () => vinculos()->escolher(EMPRESA, LOJA_A, 'merchant-1'));
confere($erro instanceof ErroDeVinculo && str_contains($erro->getMessage(), 'venceu'), 'escolha usada não vale de novo');

echo '== Conta sem lojas' . PHP_EOL;
reiniciarIfood();
respostaDoCodigo();
vinculos()->iniciar(LOJA_A);
respostaDoToken();
Http::responder(200, []);
confere(excecao(fn () => vinculos()->concluir(EMPRESA, LOJA_A, 'AUTH-1')) instanceof ErroDeVinculo, 'conta sem lojas: erro de vínculo');

echo '== Merchant já ligado a outra loja' . PHP_EOL;
reiniciarIfood();
vinculada(LOJA_B, 'merchant-1', '2026-10-05 23:00:00');
respostaDoCodigo();
vinculos()->iniciar(LOJA_A);
respostaDoToken('token-a');
Http::responder(200, [['id' => 'merchant-1', 'name' => 'Pizzaria Um']]);
$erro = excecao(fn () => vinculos()->concluir(EMPRESA, LOJA_A, 'AUTH-1'));
confere($erro instanceof ErroDeVinculo && str_contains($erro->getMessage(), 'outra loja'), 'recusa: "já está vinculada a outra loja"');
confere(linhaDa(LOJA_A) === null && decrypt(linhaDa(LOJA_B)->access_token) === 'token-1', 'a outra loja continua como estava');

echo '== Resposta do token sem refresh token' . PHP_EOL;
reiniciarIfood();
respostaDoCodigo();
vinculos()->iniciar(LOJA_A);
respostaDoToken('token-1', null);
Http::responder(200, [['id' => 'merchant-1', 'name' => 'Pizzaria Um']]);
vinculos()->concluir(EMPRESA, LOJA_A, 'AUTH-1');
confere(linhaDa(LOJA_A)->refresh_token === null && logou('resposta do token sem refresh token', 'warning'), 'grava sem refresh e avisa no log (a conferir no primeiro vínculo real)');

echo '== Desvincular' . PHP_EOL;
reiniciarIfood();
vinculada(LOJA_A, 'merchant-1', '2026-10-05 23:00:00');
vinculos()->desvincular(LOJA_A);
$linha = linhaDa(LOJA_A);
confere($linha->situacao === 'desvinculada' && $linha->merchant_id === null && $linha->access_token === null && $linha->refresh_token === null, 'sem tokens e sem merchant');
confere(vinculos()->vinculadas() === [] && VinculosIfood::resumo(LOJA_A) === ['situacao' => null, 'nome' => null, 'merchant_id' => null], 'fora do polling e "—" na tela');
respostaDoCodigo();
vinculos()->iniciar(LOJA_B);
respostaDoToken('token-b');
Http::responder(200, [['id' => 'merchant-1', 'name' => 'Pizzaria Um']]);
confere(vinculos()->concluir(EMPRESA, LOJA_B, 'AUTH-1') === ['situacao' => 'vinculada'], 'o merchant fica livre para outra loja');

echo '== Token válido e renovação' . PHP_EOL;
reiniciarIfood();
$longe = vinculada(LOJA_A, 'merchant-1', '2026-10-05 20:00:00');
confere(vinculos()->tokenValido($longe) === 'token-1' && Http::$chamadas === [], 'vence em 2 h: usa o atual, sem chamar o iFood');
$perto = vinculada(LOJA_B, 'merchant-2', '2026-10-05 18:04:00', 'token-b', 'refresh-b');
respostaDoToken('token-b2', 'refresh-b2');
confere(vinculos()->tokenValido($perto) === 'token-b2', 'vence em 4 min: renova antes');
confere(Http::$chamadas[0]['dados']['refreshToken'] === 'refresh-b', 'renova com o refresh guardado');
$linha = linhaDa(LOJA_B);
confere(decrypt($linha->access_token) === 'token-b2' && decrypt($linha->refresh_token) === 'refresh-b2' && $linha->expira_em === '2026-10-06 00:00:00' && $linha->renovado_em === '2026-10-05 18:00:00', 'grava o token, o refresh novo, a validade e a data');
respostaDoToken('token-b3', null);
vinculos()->renovar(linhaDa(LOJA_B));
confere(decrypt(linhaDa(LOJA_B)->refresh_token) === 'refresh-b2', 'sem refresh novo na resposta, o atual continua');

echo '== Outro processo já renovou' . PHP_EOL;
Relogio::$agora = '2026-10-05 18:10:00';
$antigo = $perto;
$chamadas = count(Http::$chamadas);
confere(vinculos()->renovar($antigo) === 'token-b3' && count(Http::$chamadas) === $chamadas, 'vínculo lido antes da renovação: usa o token novo sem gastar o refresh');

echo '== 401: renova e repete uma vez' . PHP_EOL;
reiniciarIfood();
$vinculo = vinculada(LOJA_A, 'merchant-1', '2026-10-05 20:00:00');
$tokens  = [];
respostaDoToken('token-2', 'refresh-2');
$resultado = vinculos()->comToken($vinculo, function (string $token) use (&$tokens) {
    $tokens[] = $token;
    if ($token === 'token-1') {
        throw new ErroIfood('pedido', 401);
    }

    return 'ok';
});
confere($resultado === 'ok' && $tokens === ['token-1', 'token-2'], 'tenta com o atual, renova e repete com o novo');
$tentativas = new ArrayObject();
respostaDoToken('token-3', 'refresh-3');
$erro = excecao(fn () => vinculos()->comToken(linhaDa(LOJA_A), function (string $token) use ($tentativas) {
    $tentativas[] = $token;
    throw new ErroIfood('pedido', 401);
}));
confere($erro instanceof ErroIfood && $erro->status === 401 && $tentativas->getArrayCopy() === ['token-2', 'token-3'], 'o segundo 401 sobe (só uma repetição)');
$erro = excecao(fn () => vinculos()->comToken(linhaDa(LOJA_A), fn () => throw new ErroIfood('pedido', 404)));
confere($erro instanceof ErroIfood && $erro->status === 404 && count(Http::$chamadas) === 2, 'outro erro sobe sem renovar');

echo '== Refresh recusado = vínculo perdido' . PHP_EOL;
reiniciarIfood();
$vinculo = vinculada(LOJA_A, 'merchant-1', '2026-10-05 18:01:00');
Http::responder(401, ['error' => ['code' => 'Unauthorized', 'message' => 'Bad credentials']]);
$erro  = excecao(fn () => vinculos()->tokenValido($vinculo));
$linha = linhaDa(LOJA_A);
confere($erro instanceof VinculoPerdido && $linha->situacao === 'vinculo_perdido' && $linha->access_token === null && $linha->refresh_token === null, 'marca vinculo_perdido e apaga os tokens');
confere(logou('[entregas] ifood: vínculo perdido', 'warning') && vinculos()->vinculadas() === [], 'log "vínculo perdido" e fora do polling');
confere(VinculosIfood::resumo(LOJA_A)['situacao'] === 'vinculo_perdido', 'a tela mostra "vínculo perdido"');
reiniciarIfood();
$vinculo = vinculada(LOJA_A, 'merchant-1', '2026-10-05 18:01:00');
Http::responder(503, 'Service Unavailable');
$erro = excecao(fn () => vinculos()->tokenValido($vinculo));
confere($erro instanceof ErroIfood && linhaDa(LOJA_A)->situacao === 'vinculada', '5xx na renovação: erro temporário, continua vinculada');
reiniciarIfood();
$vinculo = vinculada(LOJA_A, 'merchant-1', '2026-10-05 18:01:00', 'token-1', null);
confere(excecao(fn () => vinculos()->tokenValido($vinculo)) instanceof VinculoPerdido && Http::$chamadas === [], 'sem refresh guardado: perdido sem chamar o iFood');

echo '== Renovação proativa (entregas:ifood-tokens)' . PHP_EOL;
reiniciarIfood();
vinculada('vendor-1', 'merchant-1', '2026-10-05 18:30:00', 'token-1', 'refresh-1');
vinculada('vendor-2', 'merchant-2', '2026-10-05 23:00:00', 'token-2', 'refresh-2');
vinculada('vendor-3', 'merchant-3', '2026-10-05 18:20:00', 'token-3', 'refresh-3');
vinculada('vendor-4', 'merchant-4', '2026-10-05 18:40:00', 'token-4', 'refresh-4');
respostaDoToken('token-1b', 'refresh-1b');
Http::responder(400, ['error' => 'invalid_grant']);
Http::responder(500, 'erro');
$resultado = vinculos()->renovarVencendo();
confere($resultado === ['renovados' => 1, 'perdidos' => 1, 'falhas' => 1], 'renova os que vencem em menos de 1 h (1 ok, 1 perdido, 1 falha)');
confere(array_map(fn ($c) => $c['dados']['refreshToken'], Http::$chamadas) === ['refresh-1', 'refresh-3', 'refresh-4'], 'o que vence em 5 h fica para depois');
confere(linhaDa('vendor-3')->situacao === 'vinculo_perdido' && linhaDa('vendor-4')->situacao === 'vinculada', 'perdido só o recusado');
confere(logou('renovação do token falhou', 'warning'), 'falha temporária no log');

echo '== Resumos para a tela' . PHP_EOL;
reiniciarIfood();
vinculada(LOJA_A, 'merchant-1', '2026-10-05 23:00:00');
$resumos = VinculosIfood::resumos([LOJA_A, LOJA_B]);
confere($resumos === [LOJA_A => ['situacao' => 'vinculada', 'nome' => 'Loja merchant-1', 'merchant_id' => 'merchant-1'], LOJA_B => ['situacao' => null, 'nome' => null, 'merchant_id' => null]], 'situação, nome e merchant de cada loja (sem tokens)');
confere(VinculosIfood::resumos([]) === [], 'lista vazia');

echo '== Sem tokens nos logs' . PHP_EOL;
confere(logsSem(['token-', 'refresh-', 'verificador-1', 'AUTH-']), 'nenhum log com token, refresh, verificador ou código');

resumo();
```

- [ ] **Step 2: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-vinculos.php`
Expected: erro de PHP `Class "App\Support\Entregas\Ifood\VinculosIfood" not found` (sai com 1).

- [ ] **Step 3: criar as duas exceções**

`api/app/Support/Entregas/Ifood/ErroDeVinculo.php`:

```php
<?php

namespace App\Support\Entregas\Ifood;

use RuntimeException;

/**
 * Entregas RestaurantePro: o vínculo da loja com o iFood não pode seguir (código vencido, loja do iFood já ligada a
 * outra loja, conta sem lojas). A mensagem, em pt-BR, vai para a central (IfoodLojasController responde 422).
 */
class ErroDeVinculo extends RuntimeException
{
}
```

`api/app/Support/Entregas/Ifood/VinculoPerdido.php`:

```php
<?php

namespace App\Support\Entregas\Ifood;

use RuntimeException;

/**
 * Entregas RestaurantePro: o refresh token da loja venceu ou foi revogado (ou nunca veio). A loja já foi marcada
 * `vinculo_perdido` e saiu do polling; só um vínculo novo pela tela Lojas a traz de volta.
 */
class VinculoPerdido extends RuntimeException
{
}
```

- [ ] **Step 4: criar o `VinculosIfood`**

`api/app/Support/Entregas/Ifood/VinculosIfood.php`:

```php
<?php

namespace App\Support\Entregas\Ifood;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: vínculo das Lojas (Vendor) com as lojas do iFood (merchantId), tabela entregas_ifood_lojas.
 *
 * Fluxo distribuído (docs/ifood/referencia-logistics.md, seção 1.1), pela tela Lojas:
 * 1. iniciar(): pede o userCode ao iFood e guarda o authorizationCodeVerifier no cache (cifrado), por loja, pelo
 *    tempo do código (10 min). A central mostra o código e o link ao dono da loja.
 * 2. concluir(): troca o código de autorização pelos tokens (o código vale uma vez só) e lista as lojas da conta
 *    (GET /merchant/v1.0/merchants). Uma só é vinculada direto; várias ficam no cache (cifradas, 10 min) até a central
 *    escolher (escolher()). Um merchantId nunca fica em duas Lojas.
 *
 * Tokens cifrados com encrypt() (APP_KEY). Renovação: tokenValido() renova o que vence em menos de 5 min; o
 * entregas:ifood-tokens renova o que vence em menos de 1 h (renovarVencendo); comToken() renova no 401 e repete uma
 * vez. A renovação roda com trava por loja e relê o vínculo: dois processos não gastam o mesmo refresh token. Refresh
 * vencido ou revogado (400/401/403) = `vinculo_perdido`, log "[entregas] ifood: vínculo perdido" e a loja sai do
 * polling. Os logs levam só ids (loja = uuid do Vendor, merchant), nunca token.
 *
 * A confirmar no primeiro vínculo real: o nome do campo do refresh token na resposta (a documentação não lista;
 * tratamos `refreshToken` e, de reserva, `refresh_token`) e se o iFood devolve um refresh novo a cada renovação (se não
 * devolver, o atual continua).
 */
class VinculosIfood
{
    public const TABELA = 'entregas_ifood_lojas';

    public const VINCULADA    = 'vinculada';
    public const PERDIDO      = 'vinculo_perdido';
    public const DESVINCULADA = 'desvinculada';

    /** tokenValido() renova o token que vence em menos disto (segundos). */
    public const RENOVAR_ANTES_SEGUNDOS = 300;

    /** entregas:ifood-tokens renova os tokens que vencem em menos disto (segundos). */
    public const RENOVACAO_PROATIVA_SEGUNDOS = 3600;

    /** Validade do código de vínculo e da escolha da loja, se o iFood não disser (segundos). */
    public const VALIDADE_DO_CODIGO_SEGUNDOS = 600;

    /** Validade do token, se a resposta não trouxer expiresIn (6 h, documentação). */
    public const VALIDADE_PADRAO_DO_TOKEN = 21600;

    public function __construct(protected ClienteIfood $cliente)
    {
    }

    public static function chaveDoVerificador(string $vendorUuid): string
    {
        return "entregas:ifood-vinculo:{$vendorUuid}";
    }

    public static function chaveDaEscolha(string $vendorUuid): string
    {
        return "entregas:ifood-escolha:{$vendorUuid}";
    }

    /** Passo 1: o código de vínculo para o dono da loja autorizar no Portal do Parceiro. */
    public function iniciar(string $vendorUuid): array
    {
        $resposta    = $this->cliente->pedirCodigoDeVinculo();
        $codigo      = (string) ($resposta['userCode'] ?? '');
        $verificador = (string) ($resposta['authorizationCodeVerifier'] ?? '');
        if ($codigo === '' || $verificador === '') {
            throw new ErroIfood('userCode', 200, 'resposta sem userCode');
        }

        $segundos = (int) ($resposta['expiresIn'] ?? 0);
        $segundos = $segundos > 0 ? $segundos : static::VALIDADE_DO_CODIGO_SEGUNDOS;
        Cache::put(static::chaveDoVerificador($vendorUuid), encrypt($verificador), $segundos);
        Cache::forget(static::chaveDaEscolha($vendorUuid));

        return [
            'codigo'             => $codigo,
            'link'               => (string) ($resposta['verificationUrlComplete'] ?? $resposta['verificationUrl'] ?? ''),
            'expira_em_segundos' => $segundos,
        ];
    }

    /**
     * Passo 2: troca o código de autorização pelos tokens. Devolve ['situacao' => 'vinculada'] ou, com várias lojas na
     * conta, ['situacao' => 'escolher', 'lojas' => [['id', 'nome'], ...]].
     */
    public function concluir(string $companyUuid, string $vendorUuid, string $codigoDeAutorizacao): array
    {
        $verificador = Cache::get(static::chaveDoVerificador($vendorUuid));
        if (!$verificador) {
            throw new ErroDeVinculo('O código de vínculo venceu (vale 10 minutos). Gere um código novo.');
        }

        $tokens = $this->cliente->trocarCodigo(trim($codigoDeAutorizacao), decrypt($verificador));
        // o código de autorização vale uma vez só: daqui em diante valem os tokens
        Cache::forget(static::chaveDoVerificador($vendorUuid));

        $lojas = $this->lojasIfood($tokens['accessToken']);
        if (!$lojas) {
            throw new ErroDeVinculo('A conta do iFood que autorizou não tem lojas para este aplicativo.');
        }
        if (count($lojas) === 1) {
            $this->gravar($companyUuid, $vendorUuid, $lojas[0]['id'], $lojas[0]['nome'], $tokens);

            return ['situacao' => static::VINCULADA];
        }

        Cache::put(static::chaveDaEscolha($vendorUuid), encrypt(['tokens' => $tokens, 'lojas' => $lojas]), static::VALIDADE_DO_CODIGO_SEGUNDOS);

        return ['situacao' => 'escolher', 'lojas' => $lojas];
    }

    /** Passo 2b: a central escolheu a loja do iFood entre as da conta autorizada. */
    public function escolher(string $companyUuid, string $vendorUuid, string $merchantId): array
    {
        $cifrado = Cache::get(static::chaveDaEscolha($vendorUuid));
        if (!$cifrado) {
            throw new ErroDeVinculo('A escolha da loja do iFood venceu (10 minutos). Comece o vínculo de novo.');
        }

        $pendente = decrypt($cifrado);
        foreach ($pendente['lojas'] as $loja) {
            if ($loja['id'] === $merchantId) {
                $this->gravar($companyUuid, $vendorUuid, $loja['id'], $loja['nome'], $pendente['tokens']);
                Cache::forget(static::chaveDaEscolha($vendorUuid));

                return ['situacao' => static::VINCULADA];
            }
        }

        throw new ErroDeVinculo('Esta loja não está entre as lojas da conta do iFood que autorizou.');
    }

    /** Apaga os tokens e tira a loja do polling. O merchant fica livre para outra loja. */
    public function desvincular(string $vendorUuid): void
    {
        DB::table(static::TABELA)->where('vendor_uuid', $vendorUuid)->update([
            'situacao'      => static::DESVINCULADA,
            'merchant_id'   => null,
            'nome_ifood'    => null,
            'access_token'  => null,
            'refresh_token' => null,
            'expira_em'     => null,
            'updated_at'    => now()->toDateTimeString(),
        ]);
        Cache::forget(static::chaveDoVerificador($vendorUuid));
        Cache::forget(static::chaveDaEscolha($vendorUuid));
        Log::info('[entregas] ifood: loja desvinculada', ['loja' => $vendorUuid]);
    }

    /** As lojas do polling: vinculadas e com merchant. */
    public function vinculadas(): array
    {
        return DB::table(static::TABELA)->where('situacao', static::VINCULADA)->whereNotNull('merchant_id')->orderBy('id')->get()->all();
    }

    public function porMerchant(string $merchantId): ?object
    {
        return DB::table(static::TABELA)->where('merchant_id', $merchantId)->where('situacao', static::VINCULADA)->first();
    }

    /** O token de acesso, renovado antes se vence em menos de RENOVAR_ANTES_SEGUNDOS. */
    public function tokenValido(object $vinculo): string
    {
        $expira = $vinculo->expira_em ? Carbon::parse($vinculo->expira_em, 'UTC')->getTimestamp() : 0;
        if (!$vinculo->access_token || $expira - now()->getTimestamp() < static::RENOVAR_ANTES_SEGUNDOS) {
            return $this->renovar($vinculo);
        }

        return decrypt($vinculo->access_token);
    }

    /** Roda $chamada(token); no 401, renova o token e repete uma vez (a segunda falha sobe). */
    public function comToken(object $vinculo, Closure $chamada): mixed
    {
        $token = $this->tokenValido($vinculo);

        try {
            return $chamada($token);
        } catch (ErroIfood $e) {
            if (!$e->naoAutorizado()) {
                throw $e;
            }
            Log::info('[entregas] ifood: token recusado (401), renovando', ['merchant' => $vinculo->merchant_id]);

            return $chamada($this->renovar($this->recarregar($vinculo)));
        }
    }

    /**
     * Renova pelo refresh token e devolve o token novo. Com trava por loja: se outro processo renovou enquanto este
     * esperava, usa o token dele. Refresh recusado = vínculo perdido (VinculoPerdido); rede, 429 e 5xx sobem como
     * ErroIfood e a loja continua vinculada.
     */
    public function renovar(object $vinculo): string
    {
        return Cache::lock('entregas:ifood-token:' . $vinculo->id, 30)->block(20, function () use ($vinculo) {
            $atual = $this->recarregar($vinculo);
            if ($atual->situacao !== static::VINCULADA) {
                throw new VinculoPerdido('a loja não está mais vinculada');
            }
            if ($atual->access_token && $atual->renovado_em !== $vinculo->renovado_em) {
                return decrypt($atual->access_token);
            }

            $refresh = $atual->refresh_token ? decrypt($atual->refresh_token) : null;
            if (!$refresh) {
                $this->perder($atual, 0);

                throw new VinculoPerdido('sem refresh token');
            }

            try {
                $tokens = $this->cliente->renovar($refresh);
            } catch (ErroIfood $e) {
                if ($e->temporario()) {
                    throw $e;
                }
                $this->perder($atual, $e->status);

                throw new VinculoPerdido('refresh recusado: ' . $e->status);
            }

            $agora = now()->toDateTimeString();
            DB::table(static::TABELA)->where('id', $atual->id)->update([
                'access_token'  => encrypt($tokens['accessToken']),
                'refresh_token' => encrypt(static::refreshDaResposta($tokens) ?? $refresh),
                'expira_em'     => static::expiraEm($tokens),
                'renovado_em'   => $agora,
                'updated_at'    => $agora,
            ]);

            return $tokens['accessToken'];
        });
    }

    /** Renova os tokens que vencem em menos de RENOVACAO_PROATIVA_SEGUNDOS (entregas:ifood-tokens). */
    public function renovarVencendo(): array
    {
        $limite    = now()->addSeconds(static::RENOVACAO_PROATIVA_SEGUNDOS)->toDateTimeString();
        $resultado = ['renovados' => 0, 'perdidos' => 0, 'falhas' => 0];
        $vencendo  = DB::table(static::TABELA)->where('situacao', static::VINCULADA)->where('expira_em', '<', $limite)->orderBy('id')->get()->all();

        foreach ($vencendo as $vinculo) {
            try {
                $this->renovar($vinculo);
                $resultado['renovados']++;
            } catch (VinculoPerdido) {
                $resultado['perdidos']++;
            } catch (ErroIfood $e) {
                $resultado['falhas']++;
                Log::warning('[entregas] ifood: renovação do token falhou', ['merchant' => $vinculo->merchant_id, 'status' => $e->status]);
            }
        }

        return $resultado;
    }

    /** Marca o vínculo como perdido: sem tokens e fora do polling. */
    public function perder(object $vinculo, int $status): void
    {
        DB::table(static::TABELA)->where('id', $vinculo->id)->update([
            'situacao'      => static::PERDIDO,
            'access_token'  => null,
            'refresh_token' => null,
            'expira_em'     => null,
            'updated_at'    => now()->toDateTimeString(),
        ]);
        Log::warning('[entregas] ifood: vínculo perdido', ['loja' => $vinculo->vendor_uuid, 'merchant' => $vinculo->merchant_id, 'status' => $status]);
    }

    /** O iFood recusou o merchant no polling (403, unauthorizedMerchants): o dono revogou a autorização. */
    public function perderPorMerchant(string $merchantId, int $status): void
    {
        $vinculo = $this->porMerchant($merchantId);
        if ($vinculo) {
            $this->perder($vinculo, $status);
        }
    }

    /** Situação de uma loja para a tela Lojas (sem tokens). */
    public static function resumo(string $vendorUuid): array
    {
        return static::resumos([$vendorUuid])[$vendorUuid];
    }

    /** Situação de várias lojas de uma vez: uuid do Vendor => ['situacao', 'nome', 'merchant_id']. */
    public static function resumos(array $vendorUuids): array
    {
        $resumos = array_fill_keys($vendorUuids, ['situacao' => null, 'nome' => null, 'merchant_id' => null]);
        if (!$vendorUuids) {
            return $resumos;
        }

        foreach (DB::table(static::TABELA)->whereIn('vendor_uuid', array_values($vendorUuids))->get()->all() as $vinculo) {
            if ($vinculo->situacao === static::DESVINCULADA) {
                continue;
            }
            $resumos[$vinculo->vendor_uuid] = ['situacao' => $vinculo->situacao, 'nome' => $vinculo->nome_ifood, 'merchant_id' => $vinculo->merchant_id];
        }

        return $resumos;
    }

    /** O refresh token da resposta do /oauth/token: `refreshToken` (a confirmar no primeiro vínculo real) ou `refresh_token`. */
    public static function refreshDaResposta(array $tokens): ?string
    {
        foreach (['refreshToken', 'refresh_token'] as $campo) {
            if (is_string($tokens[$campo] ?? null) && $tokens[$campo] !== '') {
                return $tokens[$campo];
            }
        }

        return null;
    }

    protected static function expiraEm(array $tokens): string
    {
        $segundos = (int) ($tokens['expiresIn'] ?? 0);

        return now()->addSeconds($segundos > 0 ? $segundos : static::VALIDADE_PADRAO_DO_TOKEN)->toDateTimeString();
    }

    /** Lojas da conta autorizada: [['id', 'nome'], ...]. */
    protected function lojasIfood(string $token): array
    {
        $lojas = [];
        foreach ($this->cliente->lojasDoToken($token) as $loja) {
            if (is_string($loja['id'] ?? null) && $loja['id'] !== '') {
                $lojas[] = ['id' => $loja['id'], 'nome' => (string) ($loja['name'] ?? $loja['corporateName'] ?? $loja['id'])];
            }
        }

        return $lojas;
    }

    protected function gravar(string $companyUuid, string $vendorUuid, string $merchantId, string $nome, array $tokens): void
    {
        $emOutraLoja = DB::table(static::TABELA)->where('merchant_id', $merchantId)->where('vendor_uuid', '!=', $vendorUuid)->exists();
        if ($emOutraLoja) {
            throw new ErroDeVinculo('Esta loja do iFood já está vinculada a outra loja. Desvincule a outra antes.');
        }

        $refresh = static::refreshDaResposta($tokens);
        if ($refresh === null) {
            // sem refresh, o vínculo cai quando o token vencer (6 h): conferir o nome do campo no log do primeiro vínculo
            Log::warning('[entregas] ifood: resposta do token sem refresh token', ['loja' => $vendorUuid, 'merchant' => $merchantId, 'campos' => array_keys($tokens)]);
        }

        $agora = now()->toDateTimeString();
        $dados = [
            'company_uuid'  => $companyUuid,
            'merchant_id'   => $merchantId,
            'nome_ifood'    => mb_substr($nome, 0, 190),
            'access_token'  => encrypt($tokens['accessToken']),
            'refresh_token' => $refresh === null ? null : encrypt($refresh),
            'expira_em'     => static::expiraEm($tokens),
            'situacao'      => static::VINCULADA,
            'vinculado_em'  => $agora,
            'renovado_em'   => null,
            'updated_at'    => $agora,
        ];

        if (DB::table(static::TABELA)->where('vendor_uuid', $vendorUuid)->exists()) {
            DB::table(static::TABELA)->where('vendor_uuid', $vendorUuid)->update($dados);
        } else {
            DB::table(static::TABELA)->insert($dados + ['vendor_uuid' => $vendorUuid, 'created_at' => $agora]);
        }

        Log::info('[entregas] ifood: loja vinculada', ['loja' => $vendorUuid, 'merchant' => $merchantId]);
    }

    protected function recarregar(object $vinculo): object
    {
        return DB::table(static::TABELA)->where('id', $vinculo->id)->first() ?? $vinculo;
    }
}
```

- [ ] **Step 5: rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-vinculos.php`
Expected: `FALHAS: 0`.

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/Ifood/ErroDeVinculo.php api/app/Support/Entregas/Ifood/VinculoPerdido.php api/app/Support/Entregas/Ifood/VinculosIfood.php`
Expected: `OK` nos três.

- [ ] **Step 6: commit**

```bash
git rev-parse --show-toplevel
git add api/app/Support/Entregas/Ifood/ErroDeVinculo.php api/app/Support/Entregas/Ifood/VinculoPerdido.php api/app/Support/Entregas/Ifood/VinculosIfood.php scripts/teste-php/ifood-vinculos.php
git commit -m "iFood: vínculo das lojas (userCode, código de autorização, escolha), tokens cifrados e renovação

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: eventos (EventosIfood)

**Files:**
- Create: `api/app/Support/Entregas/Ifood/EventosIfood.php`
- Create: `scripts/teste-php/ifood-eventos.php`

- [ ] **Step 1: escrever o teste**

Crie `scripts/teste-php/ifood-eventos.php`:

```php
<?php

// Integração iFood: funções puras dos eventos (EventosIfood).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-eventos.php

require __DIR__ . '/stubs-ifood.php';

use App\Support\Entregas\Ifood\EventosIfood;

function evento(string $id, string $codigo, string $criado, string $pedido = 'pedido-1'): array
{
    return ['id' => $id, 'code' => $codigo, 'fullCode' => $codigo, 'orderId' => $pedido, 'merchantId' => 'merchant-1', 'createdAt' => $criado, 'salesChannel' => 'IFOOD'];
}

echo '== Deduplicar' . PHP_EOL;
$plc  = evento('ev-1', 'PLC', '2026-10-05T18:24:40.624Z');
$cfm  = evento('ev-2', 'CFM', '2026-10-05T18:26:27.803Z');
$ddcr = evento('ev-3', 'DDCR', '2026-10-05T18:26:28.234Z');
confere(EventosIfood::deduplicar([$plc, $cfm, $plc, ['code' => 'PLC'], 'lixo', $cfm]) === [$plc, $cfm], 'um por id, na ordem em que chegaram; sem id fica de fora');

echo '== Ordenar por createdAt (milissegundos)' . PHP_EOL;
confere(array_column(EventosIfood::ordenar([$ddcr, $plc, $cfm]), 'id') === ['ev-1', 'ev-2', 'ev-3'], 'fora de ordem volta à ordem');
$a = evento('ev-a', 'CFM', '2021-02-17T19:36:55.295Z');
$b = evento('ev-b', 'RTP', '2021-02-17T19:36:55.2Z');
$c = evento('ev-c', 'DSP', '2021-02-17T19:36:55Z');
confere(array_column(EventosIfood::ordenar([$a, $b, $c]), 'id') === ['ev-c', 'ev-b', 'ev-a'], '"55Z" < "55.2Z" (200 ms) < "55.295Z"');
$empate = [evento('ev-z', 'CFM', '2026-10-05T18:00:00.000Z'), evento('ev-y', 'DDCR', '2026-10-05T18:00:00Z')];
confere(array_column(EventosIfood::ordenar($empate), 'id') === ['ev-y', 'ev-z'], 'empate: pelo id');
$semData = evento('ev-0', 'CFM', 'não é data');
confere(array_column(EventosIfood::ordenar([$semData, $plc]), 'id') === ['ev-1', 'ev-0'], 'data inválida vai para o fim');
confere(EventosIfood::instante('2026-10-05 18:24:40.624') === EventosIfood::instante('2026-10-05T18:24:40.624Z'), 'o formato gravado no banco (UTC, sem fuso) dá o mesmo instante');

echo '== Ação por código' . PHP_EOL;
confere(EventosIfood::acao('PLC') === EventosIfood::CRIA, 'PLC cria');
confere(EventosIfood::acao('DDCR') === EventosIfood::EXIGE_CODIGO, 'DDCR exige código');
confere(EventosIfood::acao('CAN') === EventosIfood::CANCELA, 'CAN cancela (só registra na etapa 2)');
foreach (['CFM', 'RTP', 'DSP', 'CON', 'CAR', 'CARF', 'ADR', 'GTO', 'AAO', 'DDD', 'CLT', 'AAD', 'DDCS', 'DPCR', 'OPA'] as $codigo) {
    confere(EventosIfood::acao($codigo) === EventosIfood::REGISTRA, "{$codigo} só registra");
}
confere(EventosIfood::acao('HSD') === EventosIfood::IGNORA && EventosIfood::acao('') === EventosIfood::IGNORA, 'desconhecido é ignorado');

echo '== Cria o pedido' . PHP_EOL;
confere(EventosIfood::criaPedido('PLC') && EventosIfood::criaPedido('CFM') && EventosIfood::criaPedido('DDCR'), 'PLC e, se o PLC se perdeu, outro conhecido');
confere(!EventosIfood::criaPedido('CAN') && !EventosIfood::criaPedido('HSD'), 'CAN e desconhecido não criam');
confere(EventosIfood::temCancelamento([$plc, evento('ev-9', 'CAN', '2026-10-05T18:30:00Z')]) && !EventosIfood::temCancelamento([$plc, $cfm]), 'acha o CAN entre os eventos');

echo '== Linha para gravar' . PHP_EOL;
$comMetadata = evento('ev-4', 'CAN', '2026-10-05T18:26:35.864Z') + ['metadata' => ['CANCEL_ORIGIN' => 'RESTAURANT', 'CANCEL_CODE' => '523']];
$linha       = EventosIfood::paraGravar($comMetadata, '2026-10-05 18:30:00');
confere($linha['evento_id'] === 'ev-4' && $linha['merchant_id'] === 'merchant-1' && $linha['pedido_ifood_id'] === 'pedido-1' && $linha['codigo'] === 'CAN', 'ids e código');
confere($linha['criado_no_ifood'] === '2026-10-05 18:26:35.864', 'createdAt em UTC, com milissegundos');
confere(json_decode($linha['payload'], true) === $comMetadata, 'payload = o evento inteiro');
confere($linha['processado_em'] === null && $linha['ignorado'] === false && $linha['created_at'] === '2026-10-05 18:30:00', 'pendente, com as datas');
confere(EventosIfood::paraGravar(['id' => 'ev-5', 'code' => 'PLC'], '2026-10-05 18:30:00') === null, 'sem orderId/merchantId: não grava');
confere(EventosIfood::paraGravar(evento('ev-6', 'PLC', 'sem data'), '2026-10-05 18:30:00')['criado_no_ifood'] === null, 'data inválida grava nula');

resumo();
```

- [ ] **Step 2: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-eventos.php`
Expected: erro de PHP `Class "App\Support\Entregas\Ifood\EventosIfood" not found` (sai com 1).

- [ ] **Step 3: implementar**

`api/app/Support/Entregas/Ifood/EventosIfood.php`:

```php
<?php

namespace App\Support\Entregas\Ifood;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Entregas RestaurantePro: funções puras sobre os eventos do polling do iFood.
 *
 * O iFood entrega eventos repetidos e fora de ordem (documentação do polling): deduplicar() fica com o primeiro de cada
 * id e ordenar() põe em ordem de createdAt (milissegundos), com o id de desempate. acao() diz o que o
 * ProcessarPedidoIfood faz com cada código na etapa 2:
 * - PLC cria o pedido (a sonda de 2026-10-05 mostrou o pedido disponível para a logística já no PLC); qualquer outro
 *   código conhecido, menos o CAN, também cria se o PLC se perdeu (criaPedido);
 * - DDCR (chega logo depois do CFM, sem metadata) marca exige_codigo;
 * - CAN só registra cancelado_pelo_ifood_em (o cancelamento do pedido no Entregas é da etapa 3);
 * - os outros códigos conhecidos só ficam registrados (as ações e o ORDER_PATCHED são da etapa 3);
 * - código desconhecido fica gravado como ignorado.
 */
final class EventosIfood
{
    public const CRIA         = 'cria';
    public const EXIGE_CODIGO = 'exige_codigo';
    public const CANCELA      = 'cancela';
    public const REGISTRA     = 'registra';
    public const IGNORA       = 'ignora';

    /** Códigos conhecidos (spec, "Eventos relevantes"). */
    public const CONHECIDOS = ['PLC', 'CFM', 'RTP', 'DSP', 'CON', 'CAN', 'CAR', 'CARF', 'ADR', 'GTO', 'AAO', 'DDD', 'CLT', 'AAD', 'DDCR', 'DDCS', 'DPCR', 'OPA'];

    /** Um evento por id (o primeiro); evento sem id é descartado. */
    public static function deduplicar(array $eventos): array
    {
        $vistos = [];
        foreach ($eventos as $evento) {
            $id = is_array($evento) ? ($evento['id'] ?? null) : null;
            if (is_string($id) && $id !== '' && !isset($vistos[$id])) {
                $vistos[$id] = $evento;
            }
        }

        return array_values($vistos);
    }

    /** Em ordem de createdAt; data inválida vai para o fim; empate pelo id. */
    public static function ordenar(array $eventos): array
    {
        usort($eventos, fn (array $a, array $b) => [static::instante($a['createdAt'] ?? null), (string) ($a['id'] ?? '')] <=> [static::instante($b['createdAt'] ?? null), (string) ($b['id'] ?? '')]);

        return $eventos;
    }

    /** Segundos desde 1970, com microssegundos; INF para data ausente ou inválida. Sem fuso no texto, vale UTC. */
    public static function instante(?string $createdAt): float
    {
        if ($createdAt === null || trim($createdAt) === '') {
            return INF;
        }

        try {
            return (float) (new DateTimeImmutable($createdAt, new DateTimeZone('UTC')))->format('U.u');
        } catch (\Exception) {
            return INF;
        }
    }

    public static function acao(string $codigo): string
    {
        return match (true) {
            $codigo === 'PLC'                         => static::CRIA,
            $codigo === 'DDCR'                        => static::EXIGE_CODIGO,
            $codigo === 'CAN'                         => static::CANCELA,
            in_array($codigo, static::CONHECIDOS, true) => static::REGISTRA,
            default                                   => static::IGNORA,
        };
    }

    /** O evento cria o pedido quando ele ainda não existe: qualquer código conhecido, menos o CAN. */
    public static function criaPedido(string $codigo): bool
    {
        return !in_array(static::acao($codigo), [static::CANCELA, static::IGNORA], true);
    }

    public static function temCancelamento(array $eventos): bool
    {
        foreach ($eventos as $evento) {
            if (($evento['code'] ?? null) === 'CAN') {
                return true;
            }
        }

        return false;
    }

    /** A linha da entregas_ifood_eventos para o evento, ou null se faltar id, orderId, merchantId ou code. */
    public static function paraGravar(array $evento, string $agora): ?array
    {
        foreach (['id', 'orderId', 'merchantId', 'code'] as $campo) {
            if (!is_string($evento[$campo] ?? null) || $evento[$campo] === '') {
                return null;
            }
        }

        $instante = static::instante($evento['createdAt'] ?? null);

        return [
            'evento_id'       => substr($evento['id'], 0, 64),
            'merchant_id'     => substr($evento['merchantId'], 0, 64),
            'pedido_ifood_id' => substr($evento['orderId'], 0, 64),
            'codigo'          => substr($evento['code'], 0, 10),
            'criado_no_ifood' => is_finite($instante) ? DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $instante))->format('Y-m-d H:i:s.v') : null,
            'payload'         => json_encode($evento, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'processado_em'   => null,
            'ignorado'        => false,
            'created_at'      => $agora,
            'updated_at'      => $agora,
        ];
    }
}
```

- [ ] **Step 4: rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-eventos.php`
Expected: `FALHAS: 0`.

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/Ifood/EventosIfood.php`
Expected: `OK`.

- [ ] **Step 5: commit**

```bash
git rev-parse --show-toplevel
git add api/app/Support/Entregas/Ifood/EventosIfood.php scripts/teste-php/ifood-eventos.php
git commit -m "iFood: eventos sem repetição, em ordem de createdAt e com a ação de cada código

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: pedido do Logistics (PedidoDoIfood)

**Files:**
- Create: `scripts/teste-php/fixtures-ifood.php`
- Create: `scripts/teste-php/ifood-pedido.php`
- Create: `api/app/Support/Entregas/Ifood/PedidoDoIfood.php`

- [ ] **Step 1: criar os pedidos fictícios**

Crie `scripts/teste-php/fixtures-ifood.php`:

```php
<?php

// Pedidos do Logistics do iFood para os testes, com dados fictícios. A estrutura é a vista na sonda de 2026-10-05
// (deploy/ifood-sonda/pedidos/*-logistics.json); o pedido com cobrança na porta e o agendado seguem o formato da
// documentação (docs/ifood/referencia-logistics.md, 3.2), que a sonda não pôde ver.

/** Pedido de teste pago online, como os da sonda: isTest, entrega em 0,0, sem `payments`. */
function pedidoDeTestePagoOnline(): array
{
    return [
        'id'          => 'pedido-teste-1',
        'orderType'   => 'DELIVERY',
        'delivery'    => [
            'mode'            => 'DEFAULT',
            'deliveredBy'     => 'MERCHANT',
            'deliveryDateTime' => '2026-10-05T18:45:00.000Z',
            'observations'    => 'Pedido de teste gerado automaticamente.',
            'deliveryAddress' => [
                'streetName' => 'Rua TESTE', 'streetNumber' => '999999', 'formattedAddress' => 'Rua TESTE, 999999', 'neighborhood' => 'Bairro TESTE',
                'complement' => 'Complemento TESTE', 'postalCode' => '99999999', 'city' => 'TESTE', 'state' => 'XX', 'country' => 'XX', 'reference' => 'TESTE',
                'coordinates' => ['latitude' => 0, 'longitude' => 0],
            ],
        ],
        'orderTiming' => 'IMMEDIATE',
        'displayId'   => '9753',
        'createdAt'   => '2026-10-05T17:59:00.000Z',
        'isTest'      => true,
        'merchant'    => [
            'id' => 'merchant-1', 'name' => 'Loja de Teste',
            'merchantAddress' => ['country' => 'BR', 'state' => 'AC', 'city' => 'Bujari', 'district' => 'Centro', 'street' => 'Ramal Bujari', 'number' => '0', 'postalCode' => '12345678', 'latitude' => -9.822384, 'longitude' => -67.948589],
        ],
        'customer'    => ['name' => 'PEDIDO DE TESTE - Cliente Ficticio', 'id' => 'cliente-1', 'phone' => ['number' => '0800 000 0001', 'localizer' => '11112222', 'localizerExpiration' => '2026-10-05T21:59:00.000Z']],
        'items'       => [['index' => 1, 'name' => 'PRODUTO TESTE', 'quantity' => 1, 'options' => []]],
        'total'       => ['additionalFees' => 1, 'subTotal' => 21, 'deliveryFee' => 5, 'benefits' => 0, 'orderAmount' => 27],
    ];
}

/** Pedido real com cobrança na porta em dinheiro, troco para R$ 100 (formato da documentação). */
function pedidoEmDinheiroComTroco(): array
{
    return [
        'id'          => 'pedido-real-1',
        'orderType'   => 'DELIVERY',
        'delivery'    => [
            'mode'             => 'DEFAULT',
            'deliveredBy'      => 'MERCHANT',
            'deliveryDateTime' => '2026-10-05T18:40:00.000Z',
            'observations'     => 'Interfone quebrado, ligar ao chegar.',
            'deliveryAddress'  => [
                'streetName' => 'Rua Ficticia', 'streetNumber' => '123', 'formattedAddress' => 'Rua Ficticia, 123', 'neighborhood' => 'Centro',
                'complement' => 'Apto 501', 'postalCode' => '14000000', 'city' => 'Ribeirão Preto', 'state' => 'SP', 'country' => 'BR', 'reference' => 'Perto da praça',
                'coordinates' => ['latitude' => -21.1700, 'longitude' => -47.8100],
            ],
        ],
        'orderTiming' => 'IMMEDIATE',
        'displayId'   => '4821',
        'createdAt'   => '2026-10-05T17:58:00.000Z',
        'isTest'      => false,
        'merchant'    => [
            'id' => 'merchant-1', 'name' => 'Pizzaria Ficticia',
            'merchantAddress' => ['country' => 'BR', 'state' => 'SP', 'city' => 'Ribeirão Preto', 'district' => 'Centro', 'street' => 'Rua da Loja', 'number' => '10', 'postalCode' => '14000000', 'latitude' => -21.1775, 'longitude' => -47.8103],
        ],
        'customer'    => ['name' => 'Cliente Ficticio', 'id' => 'cliente-2', 'phone' => ['number' => '0800 000 0002', 'localizer' => '33334444', 'localizerExpiration' => '2026-10-05T21:58:00.000Z']],
        'items'       => [['index' => 1, 'name' => 'Pizza grande', 'quantity' => 1, 'options' => []]],
        'payments'    => [
            'prepaid' => 0,
            'pending' => 58.9,
            'methods' => [['value' => 58.9, 'currency' => 'BRL', 'method' => 'CASH', 'prepaid' => false, 'type' => 'OFFLINE', 'cash' => ['changeFor' => 100]]],
        ],
        'total'       => ['additionalFees' => 0, 'subTotal' => 52.9, 'deliveryFee' => 6, 'benefits' => 0, 'orderAmount' => 58.9],
    ];
}

/** Pedido agendado: janela das 20:00 às 20:30 UTC (formato `schedule` da documentação). */
function pedidoAgendado(string $inicio = '2026-10-05T20:00:00.000Z'): array
{
    $pedido                = pedidoEmDinheiroComTroco();
    $pedido['id']          = 'pedido-agendado-1';
    $pedido['displayId']   = '5150';
    $pedido['orderTiming'] = 'SCHEDULED';
    $pedido['schedule']    = ['deliveryDateTimeStart' => $inicio, 'deliveryDateTimeEnd' => '2026-10-05T20:30:00.000Z'];
    unset($pedido['payments']);

    return $pedido;
}
```

- [ ] **Step 2: escrever o teste**

Crie `scripts/teste-php/ifood-pedido.php`:

```php
<?php

// Integração iFood: tradução do pedido do Logistics (PedidoDoIfood).
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-pedido.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/fixtures-ifood.php';

use App\Support\Entregas\Ifood\PedidoDoIfood;

// Local da loja (coleta) em Ribeirão Preto
const LAT_COLETA = -21.1775;
const LNG_COLETA = -47.8103;
$agora = new DateTimeImmutable('2026-10-05 18:00:00', new DateTimeZone('UTC'));

echo '== Pedido real, dinheiro com troco' . PHP_EOL;
$dados = PedidoDoIfood::mapear(pedidoEmDinheiroComTroco(), LAT_COLETA, LNG_COLETA, $agora);
confere($dados['numero'] === '4821' && $dados['notas'] === 'iFood #4821', 'número curto e notas "iFood #4821"');
confere($dados['teste'] === false && $dados['agendado'] === false && $dados['despachar_agora'] === true && $dados['scheduled_at'] === null, 'imediato: despacha na hora');
$entrega = $dados['entrega'];
confere($entrega['latitude'] === -21.17 && $entrega['longitude'] === -47.81, 'coordenadas do iFood');
confere($entrega['street1'] === 'Rua Ficticia, 123' && $entrega['street2'] === 'Apto 501 · Ref.: Perto da praça', 'rua e número; complemento e referência no street2');
confere($entrega['neighborhood'] === 'Centro' && $entrega['city'] === 'Ribeirão Preto' && $entrega['province'] === 'SP' && $entrega['postal_code'] === '14000000' && $entrega['country'] === 'BR', 'bairro, cidade, estado, CEP e país');
confere($entrega['nome'] === 'Cliente Ficticio', 'nome do cliente no Local');
$linha = $dados['linha'];
confere($linha['cobrar_centavos'] === 5890 && $linha['forma_pagamento'] === 'CASH' && $linha['troco_para_centavos'] === 10000, 'cobrar R$ 58,90 em dinheiro, troco para R$ 100');
confere($linha['telefone_0800'] === '0800 000 0002' && $linha['localizador'] === '33334444' && $linha['telefone_expira_em'] === '2026-10-05 21:58:00', '0800, localizador e expiração (UTC)');
confere($linha['observacoes'] === 'Interfone quebrado, ligar ao chegar.' && $linha['complemento'] === 'Apto 501' && $linha['referencia'] === 'Perto da praça', 'observações, complemento e referência');
confere($linha['despachar_em'] === '2026-10-05 18:00:00' && $linha['agendado'] === false && $linha['teste'] === false && $linha['exige_codigo'] === false, 'despachar_em = agora');

echo '== Pedido de teste pago online (como os da sonda)' . PHP_EOL;
$dados = PedidoDoIfood::mapear(pedidoDeTestePagoOnline(), LAT_COLETA, LNG_COLETA, $agora);
confere($dados['teste'] === true && $dados['notas'] === 'iFood #9753 [TESTE]', 'marcado [TESTE]');
confere($dados['despachar_agora'] === false && $dados['linha']['despachar_em'] === null, 'sem aviso aos motoboys (só a central atribui)');
$metros = PedidoDoIfood::metrosEntre(LAT_COLETA, LNG_COLETA, $dados['entrega']['latitude'], $dados['entrega']['longitude']);
confere(abs($metros - 1000) < 15 && $dados['entrega']['longitude'] === LNG_COLETA, 'entrega ~1 km ao norte da loja (' . round($metros) . ' m), não em 0,0');
confere($dados['entrega']['country'] === 'BR', 'país "XX" do teste vira BR');
confere($dados['linha']['cobrar_centavos'] === 0 && $dados['linha']['forma_pagamento'] === null && $dados['linha']['troco_para_centavos'] === null, 'sem payments = nada a cobrar');

echo '== Pedido real sem coordenadas' . PHP_EOL;
$semCoordenadas                                                = pedidoEmDinheiroComTroco();
$semCoordenadas['delivery']['deliveryAddress']['coordinates'] = ['latitude' => 0, 'longitude' => 0];
$dados                                                         = PedidoDoIfood::mapear($semCoordenadas, LAT_COLETA, LNG_COLETA, $agora);
confere($dados['sem_coordenadas'] === true && $dados['despachar_agora'] === false && $dados['linha']['despachar_em'] === null, 'não despacha: a central confere');

echo '== Agendado' . PHP_EOL;
$dados = PedidoDoIfood::mapear(pedidoAgendado(), LAT_COLETA, LNG_COLETA, $agora);
confere($dados['agendado'] === true && $dados['despachar_agora'] === false, 'agendado: não despacha agora');
confere($dados['scheduled_at'] === '2026-10-05 19:20:00' && $dados['linha']['despachar_em'] === '2026-10-05 19:20:00', 'vai aos motoboys 40 min antes da janela (20:00 → 19:20)');
$dados = PedidoDoIfood::mapear(pedidoAgendado('2026-10-05T18:30:00.000Z'), LAT_COLETA, LNG_COLETA, $agora);
confere($dados['agendado'] === false && $dados['despachar_agora'] === true && $dados['linha']['despachar_em'] === '2026-10-05 18:00:00', 'menos de 40 min para a janela: despacha na hora');
$semJanela = pedidoAgendado();
unset($semJanela['schedule']);
$semJanela['delivery']['deliveryDateTime'] = '2026-10-05T21:00:00.000Z';
confere(PedidoDoIfood::mapear($semJanela, LAT_COLETA, LNG_COLETA, $agora)['scheduled_at'] === '2026-10-05 20:20:00', 'sem schedule: usa o deliveryDateTime');

echo '== Cobrança' . PHP_EOL;
confere(PedidoDoIfood::cobranca(null) === [0, null, null], 'sem payments');
confere(PedidoDoIfood::cobranca(['prepaid' => 27, 'pending' => 0, 'methods' => [['method' => 'CREDIT', 'prepaid' => true, 'type' => 'ONLINE']]]) === [0, null, null], 'pending 0 = pago online');
confere(PedidoDoIfood::cobranca(['prepaid' => 0, 'pending' => 323.99, 'methods' => [['value' => 323.99, 'method' => 'CREDIT', 'prepaid' => false, 'type' => 'OFFLINE']]]) === [32399, 'CREDIT', null], 'cartão na porta, sem troco');
confere(PedidoDoIfood::cobranca(['pending' => 30, 'methods' => [['method' => 'PIX', 'prepaid' => true, 'type' => 'ONLINE'], ['method' => 'CASH', 'prepaid' => false, 'type' => 'OFFLINE', 'cash' => ['changeFor' => 50]]]]) === [3000, 'CASH', 5000], 'pago em parte: a forma é a do método não pago');
confere(PedidoDoIfood::cobranca(['pending' => 10.1, 'methods' => [['method' => 'CASH', 'prepaid' => false]]]) === [1010, 'CASH', null], 'dinheiro sem troco; centavos arredondados');

echo '== Campos faltando' . PHP_EOL;
$minimo = ['id' => 'abcdef123456', 'isTest' => false, 'delivery' => ['deliveryAddress' => ['coordinates' => ['latitude' => -21.17, 'longitude' => -47.81]]]];
$dados  = PedidoDoIfood::mapear($minimo, LAT_COLETA, LNG_COLETA, $agora);
confere($dados['numero'] === 'abcdef12' && $dados['entrega']['nome'] === 'Cliente iFood' && $dados['entrega']['street1'] === 'Endereço do iFood' && $dados['entrega']['street2'] === null, 'sem displayId, nome e rua: valores padrão');
confere($dados['linha']['telefone_0800'] === null && $dados['linha']['telefone_expira_em'] === null, 'sem telefone');

resumo();
```

- [ ] **Step 3: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-pedido.php`
Expected: erro de PHP `Class "App\Support\Entregas\Ifood\PedidoDoIfood" not found` (sai com 1).

- [ ] **Step 4: implementar**

`api/app/Support/Entregas/Ifood/PedidoDoIfood.php`:

```php
<?php

namespace App\Support\Entregas\Ifood;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Entregas RestaurantePro: função pura que traduz o pedido do módulo Logistics do iFood (GET /logistics/v1.0/orders/{id},
 * campos vistos na sonda de 2026-10-05) nos dados do pedido do Fleetbase e da linha de entregas_ifood_pedidos.
 *
 * - Número: `displayId` (ex.: 4821) vira o internal_id e o "iFood #4821" das notas.
 * - Entrega: coordenadas, rua + número, bairro, complemento e referência (no street2, para o motoboy ler) e o nome do
 *   cliente (nome do Local). Sem geocodificação: as coordenadas do iFood valem.
 * - Pedido de teste (`isTest: true`, entrega em 0,0): entrega na coleta deslocada ~1 km para o norte (o km não fica
 *   absurdo), "[TESTE]" nas notas e sem despacho aos motoboys (só a central atribui). Pedido real sem coordenadas
 *   (0,0) recebe o mesmo deslocamento, mas fica `sem_coordenadas` e também não é despachado: a central confere.
 * - Agendado (`orderTiming = SCHEDULED`): vai aos motoboys 40 min antes do início da janela (`schedule.
 *   deliveryDateTimeStart`; sem ele, `delivery.deliveryDateTime`). A sonda só viu pedidos imediatos: o formato do
 *   `schedule` é o da documentação (a conferir no primeiro agendado). Se faltar menos de 40 min, despacha na hora.
 * - Cobrança: sem `payments` (pedido pago online, visto na sonda) ou `pending` = 0, nada a cobrar; com `pending` > 0,
 *   o valor, a forma (o método não pago) e o troco (`methods[].cash.changeFor`) no formato da documentação (a conferir
 *   na homologação: o gerador de pedidos de teste só cria pedido pago online).
 * - 0800 e localizador do cliente (`customer.phone`) com a expiração do localizador.
 *
 * Datas em texto 'Y-m-d H:i:s', UTC (o fuso do banco).
 */
final class PedidoDoIfood
{
    /** O agendado vai aos motoboys este tanto antes do início da janela de entrega. */
    public const ANTECEDENCIA_AGENDADO_MINUTOS = 40;

    /** Deslocamento da entrega do pedido de teste: ~1 km para o norte. */
    public const DESLOCAMENTO_TESTE_GRAUS = 0.009;

    public static function mapear(array $pedido, float $latitudeColeta, float $longitudeColeta, DateTimeInterface $agora): array
    {
        $utc      = new DateTimeZone('UTC');
        $agora    = DateTimeImmutable::createFromInterface($agora)->setTimezone($utc);
        $numero   = static::texto($pedido['displayId'] ?? null, 20) ?? substr((string) ($pedido['id'] ?? ''), 0, 8);
        $teste    = ($pedido['isTest'] ?? false) === true;
        $entrega  = is_array($pedido['delivery'] ?? null) ? $pedido['delivery'] : [];
        $endereco = is_array($entrega['deliveryAddress'] ?? null) ? $entrega['deliveryAddress'] : [];
        $cliente  = is_array($pedido['customer'] ?? null) ? $pedido['customer'] : [];
        $telefone = is_array($cliente['phone'] ?? null) ? $cliente['phone'] : [];

        $latitude       = (float) ($endereco['coordinates']['latitude'] ?? 0);
        $longitude      = (float) ($endereco['coordinates']['longitude'] ?? 0);
        $semCoordenadas = !$teste && $latitude == 0.0 && $longitude == 0.0;
        if ($teste || $semCoordenadas) {
            $latitude  = $latitudeColeta + static::DESLOCAMENTO_TESTE_GRAUS;
            $longitude = $longitudeColeta;
        }

        $inicio = null;
        if (($pedido['orderTiming'] ?? null) === 'SCHEDULED') {
            $inicio = static::data($pedido['schedule']['deliveryDateTimeStart'] ?? null) ?? static::data($entrega['deliveryDateTime'] ?? null);
        }
        $despacharEm = $inicio ? $inicio->modify('-' . static::ANTECEDENCIA_AGENDADO_MINUTOS . ' minutes') : $agora;
        $agendado    = $despacharEm > $agora;
        if (!$agendado) {
            $despacharEm = $agora;
        }

        [$cobrar, $forma, $troco] = static::cobranca($pedido['payments'] ?? null);

        $complemento = static::texto($endereco['complement'] ?? null, 190);
        $referencia  = static::texto($endereco['reference'] ?? null, 190);
        $rua         = static::texto(trim(trim((string) ($endereco['streetName'] ?? '')) . ', ' . trim((string) ($endereco['streetNumber'] ?? '')), ', '), 190);
        $pais        = strtoupper((string) ($endereco['country'] ?? ''));
        $semDespacho = $teste || $semCoordenadas;

        return [
            'numero'          => $numero,
            'teste'           => $teste,
            'agendado'        => $agendado,
            'sem_coordenadas' => $semCoordenadas,
            'despachar_agora' => !$semDespacho && !$agendado,
            'scheduled_at'    => $agendado ? $despacharEm->format('Y-m-d H:i:s') : null,
            'notas'           => 'iFood #' . $numero . ($teste ? ' [TESTE]' : ''),
            'entrega'         => [
                'nome'         => static::texto($cliente['name'] ?? null, 120) ?? 'Cliente iFood',
                'street1'      => $rua ?? static::texto($endereco['formattedAddress'] ?? null, 190) ?? 'Endereço do iFood',
                'street2'      => static::texto(implode(' · ', array_filter([$complemento, $referencia ? 'Ref.: ' . $referencia : null])), 190),
                'neighborhood' => static::texto($endereco['neighborhood'] ?? null, 120),
                'city'         => static::texto($endereco['city'] ?? null, 120),
                'province'     => static::texto($endereco['state'] ?? null, 60),
                'postal_code'  => static::texto($endereco['postalCode'] ?? null, 20),
                'country'      => preg_match('/^[A-Z]{2}$/', $pais) && $pais !== 'XX' ? $pais : 'BR',
                'latitude'     => $latitude,
                'longitude'    => $longitude,
            ],
            'linha'           => [
                'numero'              => $numero,
                'telefone_0800'       => static::texto($telefone['number'] ?? null, 30),
                'localizador'         => static::texto($telefone['localizer'] ?? null, 20),
                'telefone_expira_em'  => static::data($telefone['localizerExpiration'] ?? null)?->format('Y-m-d H:i:s'),
                'cobrar_centavos'     => $cobrar,
                'forma_pagamento'     => $forma,
                'troco_para_centavos' => $troco,
                'observacoes'         => static::texto($entrega['observations'] ?? null, 1000),
                'complemento'         => $complemento,
                'referencia'          => $referencia,
                'exige_codigo'        => false,
                'teste'               => $teste,
                'agendado'            => $agendado,
                'despachar_em'        => $semDespacho ? null : $despacharEm->format('Y-m-d H:i:s'),
            ],
        ];
    }

    /** [centavos a cobrar, forma (CASH, CREDIT…), troco para (centavos) ou null]. */
    public static function cobranca($pagamentos): array
    {
        if (!is_array($pagamentos)) {
            return [0, null, null];
        }

        $pendente = (int) round(((float) ($pagamentos['pending'] ?? 0)) * 100);
        if ($pendente <= 0) {
            return [0, null, null];
        }

        $metodos = array_values(array_filter((array) ($pagamentos['methods'] ?? []), 'is_array'));
        $naPorta = array_values(array_filter($metodos, fn (array $metodo) => ($metodo['prepaid'] ?? null) === false || ($metodo['type'] ?? null) === 'OFFLINE'));
        $metodo  = $naPorta[0] ?? $metodos[0] ?? [];
        $forma   = static::texto($metodo['method'] ?? null, 30);
        $para    = (float) ($metodo['cash']['changeFor'] ?? 0);

        return [$pendente, $forma, $forma === 'CASH' && $para > 0 ? (int) round($para * 100) : null];
    }

    /** Distância em linha reta, em metros (haversine). */
    public static function metrosEntre(float $latitude1, float $longitude1, float $latitude2, float $longitude2): float
    {
        $dLatitude  = deg2rad($latitude2 - $latitude1);
        $dLongitude = deg2rad($longitude2 - $longitude1);
        $a          = sin($dLatitude / 2) ** 2 + cos(deg2rad($latitude1)) * cos(deg2rad($latitude2)) * sin($dLongitude / 2) ** 2;

        return 2 * 6371000 * asin(min(1, sqrt($a)));
    }

    protected static function texto($valor, int $maximo): ?string
    {
        $texto = is_scalar($valor) ? trim((string) $valor) : '';

        return $texto === '' ? null : mb_substr($texto, 0, $maximo);
    }

    protected static function data($valor): ?DateTimeImmutable
    {
        if (!is_string($valor) || trim($valor) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($valor, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
```

- [ ] **Step 5: rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-pedido.php`
Expected: `FALHAS: 0`.

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/Ifood/PedidoDoIfood.php`
Expected: `OK`.

- [ ] **Step 6: commit**

```bash
git rev-parse --show-toplevel
git add scripts/teste-php/fixtures-ifood.php scripts/teste-php/ifood-pedido.php api/app/Support/Entregas/Ifood/PedidoDoIfood.php
git commit -m "iFood: pedido do Logistics para o Entregas (entrega, cobrança, 0800, teste e agendado)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: criação e despacho no Fleetbase (CriadorDoPedidoIfood)

**Files:**
- Create: `scripts/teste-php/stubs-ifood-fleetbase.php`
- Create: `scripts/teste-php/ifood-criador.php`
- Create: `api/app/Support/Entregas/Ifood/CriadorDoPedidoIfood.php`

Os models do Fleetbase falsos registram as chamadas: o teste confere que a empresa está na sessão antes do
`Order::create` (o `TrackingNumberObserver` grava o `company_uuid` dela), a coleta = Local da loja, a entrega = Place
novo, o despacho como o do portal e que a linha de `entregas_ifood_pedidos` entra na mesma transação. O stub também
traz o `App\Http\Controllers\Controller`, o `Request` e o `Auth`, usados na Task 11.

- [ ] **Step 1: criar `scripts/teste-php/stubs-ifood-fleetbase.php`**

```php
<?php

// Stubs dos models do Fleetbase (e do Request e do Auth) para os testes da integração iFood que criam e despacham
// pedidos (ifood-criador.php, ifood-processar.php, ifood-agendador.php) e da tela Lojas (ifood-lojas.php).
// Carregar depois do stubs-ifood.php. Cada model guarda os seus objetos numa lista estática ($todos), e as consultas
// (where/orWhere/closure) filtram essa lista com a precedência do SQL.

namespace Fleetbase\LaravelMysqlSpatial\Types {
    class Point
    {
        public function __construct(private float $latitude, private float $longitude) {}
        public function getLat() { return $this->latitude; }
        public function getLng() { return $this->longitude; }
    }
}

namespace Fleetbase\FleetOps\Support {
    class Utils
    {
        public static function getMutationType($modelo): string { return 'fleet-ops:' . strtolower((new \ReflectionClass($modelo))->getShortName()); }
    }
}

namespace Fleetbase\Support {
    class Auth
    {
        public static $usuario = null;
        public static function getUserFromSession($request = null) { return self::$usuario; }
    }
}

namespace App\Http\Controllers {
    // o real estende o Controller do Laravel; os nossos controllers só herdam dele
    class Controller {}
}

namespace Illuminate\Http {
    class Request
    {
        public function __construct(public array $dados = []) {}

        // as regras do Laravel não rodam aqui: devolve só os campos que têm regra
        public function validate(array $regras): array
        {
            $validos = [];
            foreach (array_keys($regras) as $campo) {
                if (array_key_exists($campo, $this->dados)) {
                    $validos[$campo] = $this->dados[$campo];
                }
            }

            return $validos;
        }
    }
}

namespace Teste {
    // consulta de model sobre uma lista de objetos: OU de grupos E; where com closure vira um subgrupo
    class ConsultaDeModelo
    {
        private array $grupos = [[]];
        public function __construct(private array $itens) {}

        public function where($coluna, $valor = null)
        {
            $this->grupos[array_key_last($this->grupos)][] = $coluna instanceof \Closure ? $this->subgrupo($coluna) : fn ($item) => ($item->$coluna ?? null) === $valor;

            return $this;
        }

        public function orWhere($coluna, $valor = null)
        {
            $this->grupos[] = [];

            return $this->where($coluna, $valor);
        }

        public function first()
        {
            foreach ($this->itens as $item) {
                if ($this->passa($item)) {
                    return $item;
                }
            }

            return null;
        }

        public function firstOrFail()
        {
            return $this->first() ?? throw new \RuntimeException('404: registro não encontrado');
        }

        public function passa($item): bool
        {
            if ($this->grupos === [[]]) {
                return true;
            }
            foreach ($this->grupos as $grupo) {
                $todos = (bool) $grupo;
                foreach ($grupo as $filtro) {
                    if (!$filtro($item)) {
                        $todos = false;
                        break;
                    }
                }
                if ($todos) {
                    return true;
                }
            }

            return false;
        }

        private function subgrupo(\Closure $definicao): \Closure
        {
            $sub = new static([]);
            $definicao($sub);

            return fn ($item) => $sub->passa($item);
        }
    }

    trait ModeloDeTeste
    {
        public static array $todos = [];

        public function __construct(array $atributos = [])
        {
            foreach ($atributos as $chave => $valor) {
                $this->$chave = $valor;
            }
        }

        public static function where($coluna, $valor = null) { return (new ConsultaDeModelo(static::$todos))->where($coluna, $valor); }
        public function refresh() { return $this; }
    }
}

namespace Fleetbase\FleetOps\Models {
    #[\AllowDynamicProperties]
    class Vendor
    {
        use \Teste\ModeloDeTeste;
        public $uuid;
        public $public_id;
        public $company_uuid;
        public $type = 'customer';
        public $name;
        public $place_uuid;
    }

    #[\AllowDynamicProperties]
    class Place
    {
        use \Teste\ModeloDeTeste;
        public static array $criados = [];
        public $uuid;
        public $company_uuid;
        public $location;
        public $name;

        public static function create(array $atributos): static
        {
            $place          = new static($atributos);
            $place->uuid  ??= 'place-novo-' . (count(self::$criados) + 1);
            self::$criados[] = $place;
            self::$todos[]   = $place;

            return $place;
        }
    }

    #[\AllowDynamicProperties]
    class Payload
    {
        public static array $salvos = [];
        public $uuid;
        public $company_uuid;
        public $pickup;
        public $dropoff;
        public $atual;

        public function setPickup($place, array $opcoes = [])
        {
            $this->pickup = $place;
            if (isset($opcoes['callback'])) {
                ($opcoes['callback'])($place, $this);
            }

            return $this;
        }

        public function setDropoff($place) { $this->dropoff = $place; return $this; }
        public function setCurrentWaypoint($place) { $this->atual = $place; return $this; }

        public function save()
        {
            $this->uuid ??= 'payload-' . (count(self::$salvos) + 1);
            self::$salvos[] = $this;

            return true;
        }
    }

    #[\AllowDynamicProperties]
    class OrderConfig
    {
        use \Teste\ModeloDeTeste;
        public $uuid;
        public $key;
        public $company_uuid;
        public $namespace;

        public static function default()
        {
            foreach (self::$todos as $config) {
                if ($config->company_uuid === session('company') && $config->namespace === 'system:order-config:transport') {
                    return $config;
                }
            }

            return null;
        }
    }

    #[\AllowDynamicProperties]
    class Order
    {
        use \Teste\ModeloDeTeste;
        public static array $criados = [];
        public static bool $falharDespacho = false;
        public $uuid;
        public $public_id;
        public $company_uuid;
        public $status = 'created';
        public $dispatched = false;
        public $adhoc = false;
        public array $chamadas = [];
        public bool $temStatusDespachado = false;
        /** A empresa da sessão no momento do create (o TrackingNumberObserver depende dela). */
        public ?string $empresaNaSessao = null;

        public static function create(array $atributos): static
        {
            $pedido                  = new static($atributos);
            $numero                  = count(self::$criados) + 1;
            $pedido->uuid          ??= 'order-uuid-' . $numero;
            $pedido->public_id     ??= 'order_' . $numero;
            $pedido->empresaNaSessao = session('company');
            self::$criados[]         = $pedido;
            self::$todos[]           = $pedido;

            return $pedido;
        }

        public function saveQuietly() { $this->chamadas[] = 'saveQuietly'; return true; }
        public function hasDispatchedStatus(): bool { return $this->temStatusDespachado; }

        public function firstDispatchWithActivity()
        {
            $this->chamadas[] = 'firstDispatchWithActivity';
            if (self::$falharDespacho) {
                throw new \RuntimeException('falha no despacho');
            }
            $this->dispatched          = true;
            $this->status              = 'dispatched';
            $this->temStatusDespachado = true;

            return $this;
        }

        public function insertDispatchActivity()
        {
            $this->chamadas[]          = 'insertDispatchActivity';
            $this->temStatusDespachado = true;

            return $this;
        }
    }
}

namespace {
    /** Zera os models e cria a loja de teste: Vendor com o Local de coleta e o tipo de pedido transport da empresa. */
    function reiniciarFleetbase(): void
    {
        \Fleetbase\FleetOps\Models\Vendor::$todos      = [];
        \Fleetbase\FleetOps\Models\Place::$todos       = [];
        \Fleetbase\FleetOps\Models\Place::$criados     = [];
        \Fleetbase\FleetOps\Models\Payload::$salvos    = [];
        \Fleetbase\FleetOps\Models\OrderConfig::$todos = [];
        \Fleetbase\FleetOps\Models\Order::$todos       = [];
        \Fleetbase\FleetOps\Models\Order::$criados     = [];
        \Fleetbase\FleetOps\Models\Order::$falharDespacho = false;
        \Fleetbase\Support\Auth::$usuario              = null;

        \Fleetbase\FleetOps\Models\Place::$todos[]       = new \Fleetbase\FleetOps\Models\Place(['uuid' => 'place-loja', 'company_uuid' => 'empresa-1', 'name' => 'PIZZARIA FICTICIA', 'location' => new \Fleetbase\LaravelMysqlSpatial\Types\Point(-21.1775, -47.8103)]);
        \Fleetbase\FleetOps\Models\Vendor::$todos[]      = new \Fleetbase\FleetOps\Models\Vendor(['uuid' => 'vendor-a', 'public_id' => 'vendor_a', 'company_uuid' => 'empresa-1', 'name' => 'Pizzaria Ficticia', 'place_uuid' => 'place-loja']);
        \Fleetbase\FleetOps\Models\OrderConfig::$todos[] = new \Fleetbase\FleetOps\Models\OrderConfig(['uuid' => 'config-transport', 'key' => 'transport', 'company_uuid' => 'empresa-1', 'namespace' => 'system:order-config:transport']);
    }

    /** O vínculo da loja de teste (vendor-a ↔ merchant-1), gravado na tabela; devolve a linha. */
    function vinculoDaLojaA(string $expiraEm = '2026-10-05 23:00:00'): object
    {
        \Teste\Banco::inserir('entregas_ifood_lojas', [
            'company_uuid' => 'empresa-1', 'vendor_uuid' => 'vendor-a', 'merchant_id' => 'merchant-1', 'nome_ifood' => 'Pizzaria Ficticia',
            'access_token' => encrypt('token-a'), 'refresh_token' => encrypt('refresh-a'), 'expira_em' => $expiraEm, 'situacao' => 'vinculada',
            'vinculado_em' => '2026-10-05 12:00:00', 'renovado_em' => null, 'created_at' => '2026-10-05 12:00:00', 'updated_at' => '2026-10-05 12:00:00',
        ], false);

        return (new \Teste\Consulta('entregas_ifood_lojas'))->where('vendor_uuid', 'vendor-a')->first();
    }
}
```

- [ ] **Step 2: escrever o teste**

Crie `scripts/teste-php/ifood-criador.php`:

```php
<?php

// Integração iFood: criação e despacho do pedido no Fleetbase (CriadorDoPedidoIfood), com models do Fleetbase falsos.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-criador.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';
require __DIR__ . '/fixtures-ifood.php';

use App\Support\Entregas\Ifood\CriadorDoPedidoIfood;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Teste\Banco;
use Teste\Sessao;

function preparar(): object
{
    reiniciarIfood();
    reiniciarFleetbase();

    return vinculoDaLojaA();
}

echo '== Pedido real: cria e despacha' . PHP_EOL;
$vinculo = preparar();
$linha   = (new CriadorDoPedidoIfood())->criar($vinculo, pedidoEmDinheiroComTroco());
$pedido  = Order::$criados[0] ?? null;
confere(count(Order::$criados) === 1 && $pedido->customer_uuid === 'vendor-a' && $pedido->customer_type === 'fleet-ops:vendor', 'um pedido, cliente = a Loja');
confere($pedido->company_uuid === 'empresa-1' && $pedido->empresaNaSessao === 'empresa-1', 'empresa no pedido e na sessão antes do create');
confere($pedido->type === 'transport' && $pedido->order_config_uuid === 'config-transport' && $pedido->status === 'dispatched', 'tipo transport (despachado em seguida)');
confere($pedido->internal_id === '4821' && $pedido->notes === 'iFood #4821' && $pedido->scheduled_at === null, 'número do iFood no internal_id e nas notas');
$destino = Place::$criados[0] ?? null;
confere($destino && $destino->location->getLat() === -21.17 && $destino->location->getLng() === -47.81, 'entrega = Place novo nas coordenadas do iFood');
confere($destino->name === 'CLIENTE FICTICIO' && $destino->street1 === 'RUA FICTICIA, 123' && $destino->street2 === 'APTO 501 · REF.: PERTO DA PRAÇA' && $destino->city === 'RIBEIRÃO PRETO', 'nome e endereço em maiúsculas (com acento)');
confere(!isset($destino->owner_uuid), 'sem dono: não entra nos endereços salvos da loja');
$payload = Payload::$salvos[0] ?? null;
confere($payload && $payload->pickup->uuid === 'place-loja' && $payload->dropoff === $destino && $payload->atual->uuid === 'place-loja' && $pedido->payload_uuid === $payload->uuid, 'coleta = Local da loja; entrega = o Place novo');
confere($linha && $linha->order_uuid === $pedido->uuid && $linha->pedido_ifood_id === 'pedido-real-1' && $linha->merchant_id === 'merchant-1' && $linha->vendor_uuid === 'vendor-a', 'linha do iFood ligada ao pedido');
confere($linha->cobrar_centavos === 5890 && $linha->forma_pagamento === 'CASH' && $linha->numero === '4821', 'cobrança na linha (fora do meta)');
confere($pedido->adhoc === true && $pedido->chamadas === ['saveQuietly', 'firstDispatchWithActivity'], 'despacho como o do portal: adhoc + firstDispatchWithActivity');
confere($linha->despachado_em === '2026-10-05 18:00:00', 'despachado_em marcado');
confere(!logou('coleta diverge'), 'coleta a menos de 300 m do endereço do iFood: sem aviso');
confere(logou('[entregas] ifood: pedido criado', 'info') && logsSem(['Cliente Ficticio', 'CLIENTE FICTICIO', '0800 000 0002', 'Rua Ficticia', '33334444']), 'log sem nome, telefone, endereço nem localizador');

echo '== Pedido de teste: não despacha' . PHP_EOL;
$vinculo = preparar();
$linha   = (new CriadorDoPedidoIfood())->criar($vinculo, pedidoDeTestePagoOnline());
$pedido  = Order::$criados[0];
confere($pedido->notes === 'iFood #9753 [TESTE]' && $pedido->chamadas === [] && $pedido->status === 'created', '[TESTE], sem aviso aos motoboys');
confere($linha->teste === true && $linha->despachar_em === null && ($linha->despachado_em ?? null) === null, 'linha de teste, fora do agendador');
confere(abs(Place::$criados[0]->location->getLat() - (-21.1775 + 0.009)) < 0.000001, 'entrega ~1 km ao norte da loja');
confere(!logou('coleta diverge'), 'loja de teste (Acre) não gera aviso de divergência');

echo '== Agendado: não despacha agora' . PHP_EOL;
$vinculo = preparar();
$linha   = (new CriadorDoPedidoIfood())->criar($vinculo, pedidoAgendado());
$pedido  = Order::$criados[0];
confere($pedido->scheduled_at === '2026-10-05 19:20:00' && $pedido->chamadas === [], 'scheduled_at 40 min antes da janela, sem despacho');
confere($linha->agendado === true && $linha->despachar_em === '2026-10-05 19:20:00' && ($linha->despachado_em ?? null) === null, 'na fila do entregas:ifood-agendados');

echo '== Coleta longe do endereço do iFood' . PHP_EOL;
$vinculo                                          = preparar();
$longe                                            = pedidoEmDinheiroComTroco();
$longe['merchant']['merchantAddress']['latitude'] = -21.1900;
(new CriadorDoPedidoIfood())->criar($vinculo, $longe);
confere(logou('[entregas] ifood: coleta diverge do iFood (1390 m)', 'warning'), 'aviso com a distância (1390 m)');
confere(count(Order::$criados) === 1, 'o pedido é criado mesmo assim (a coleta é o Local da loja)');

echo '== Loja sem Local de coleta' . PHP_EOL;
$vinculo                    = preparar();
Vendor::$todos[0]->place_uuid = null;
confere((new CriadorDoPedidoIfood())->criar($vinculo, pedidoEmDinheiroComTroco()) === null && Order::$criados === [], 'não cria');
confere(logou('loja sem local de coleta', 'error'), 'erro no log');

echo '== Falha na linha desfaz o pedido' . PHP_EOL;
$vinculo = preparar();
Banco::inserir('entregas_ifood_pedidos', ['pedido_ifood_id' => 'pedido-real-1', 'order_uuid' => 'outro', 'merchant_id' => 'merchant-1', 'company_uuid' => 'empresa-1'], false);
$erro = excecao(fn () => (new CriadorDoPedidoIfood())->criar($vinculo, pedidoEmDinheiroComTroco()));
confere($erro instanceof Teste\ErroDeBanco && count(Banco::linhas('entregas_ifood_pedidos')) === 1, 'pedido_ifood_id repetido: a transação falha');
confere(Order::$criados[0]->chamadas === [], 'e nada é despachado');

echo '== Despacho' . PHP_EOL;
preparar();
$pedido = Order::create(['company_uuid' => 'empresa-1', 'dispatched' => true]);
Banco::inserir('entregas_ifood_pedidos', ['pedido_ifood_id' => 'p-1', 'order_uuid' => $pedido->uuid, 'merchant_id' => 'merchant-1', 'company_uuid' => 'empresa-1'], false);
Sessao::$dados = [];
confere((new CriadorDoPedidoIfood())->despachar($pedido) && $pedido->chamadas === ['insertDispatchActivity'], 'já despachado pelo fleetops:dispatch-orders: só a atividade');
confere(Sessao::$dados['company'] === 'empresa-1' && Banco::linhas('entregas_ifood_pedidos')[0]->despachado_em === '2026-10-05 18:00:00', 'com a empresa na sessão; marca despachado_em');
$pedido->chamadas = [];
(new CriadorDoPedidoIfood())->despachar($pedido);
confere($pedido->chamadas === [], 'atividade já existe: nada a fazer');
$outro                 = Order::create(['company_uuid' => 'empresa-1']);
Order::$falharDespacho = true;
confere((new CriadorDoPedidoIfood())->despachar($outro) === false && logou('falha ao despachar o pedido', 'error'), 'falha no despacho: false e log (o agendador tenta de novo)');

resumo();
```

- [ ] **Step 3: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-criador.php`
Expected: erro de PHP `Class "App\Support\Entregas\Ifood\CriadorDoPedidoIfood" not found` (sai com 1).

- [ ] **Step 4: implementar**

`api/app/Support/Entregas/Ifood/CriadorDoPedidoIfood.php`:

```php
<?php

namespace App\Support\Entregas\Ifood;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: cria no Fleetbase o pedido que veio do iFood e o despacha aos motoboys. Adaptador fino sobre
 * os models do Fleet-Ops, no mesmo formato dos pedidos do portal da loja (PortalOrderService::buildPayloadFromInput e o
 * OrderController do customer-portal):
 * - cliente = a Loja (Vendor) do vínculo; coleta = o Local da Loja (não o endereço do iFood);
 * - entrega = Place novo com as coordenadas do iFood (sem geocodificação) e sem dono (não entra nos endereços salvos
 *   da loja no portal);
 * - Order tipo transport, status created, adhoc, internal_id = número do iFood, notas "iFood #4821";
 * - a linha de entregas_ifood_pedidos entra na mesma transação do Order: o pedido_ifood_id único impede um segundo
 *   pedido para o mesmo pedido do iFood (se a linha falhar, o Order é desfeito junto).
 *
 * A empresa vai para a sessão antes de criar: o TrackingNumberObserver e o OrderConfig::default() a leem de lá.
 * Despacho como o do portal (RegrasPortalLoja::despacharParaMotoboys): adhoc + firstDispatchWithActivity. Pedido de
 * teste, sem coordenadas ou agendado não é despachado aqui (o agendado sai pelo entregas:ifood-agendados). Coleta a
 * mais de 300 m do endereço da loja no iFood gera aviso no log (um dos cadastros deve estar errado).
 */
class CriadorDoPedidoIfood
{
    public const TABELA = 'entregas_ifood_pedidos';

    /** Distância máxima entre o Local da loja e o endereço dela no iFood antes do aviso no log. */
    public const DIVERGENCIA_MAXIMA_METROS = 300;

    /** Cria o pedido e devolve a linha de entregas_ifood_pedidos; null se a loja não tem Local de coleta. */
    public function criar(object $vinculo, array $pedidoIfood): ?object
    {
        $vendor = Vendor::where('uuid', $vinculo->vendor_uuid)->where('company_uuid', $vinculo->company_uuid)->first();
        $coleta = $vendor && $vendor->place_uuid ? Place::where('uuid', $vendor->place_uuid)->first() : null;
        if (!$vendor || !$coleta || !$coleta->location) {
            Log::error('[entregas] ifood: loja sem local de coleta; pedido não criado', ['merchant' => $vinculo->merchant_id, 'numero' => $pedidoIfood['displayId'] ?? null]);

            return null;
        }

        $dados = PedidoDoIfood::mapear($pedidoIfood, (float) $coleta->location->getLat(), (float) $coleta->location->getLng(), now());
        $this->conferirColeta($pedidoIfood, $coleta, $dados);
        if ($dados['sem_coordenadas']) {
            Log::warning('[entregas] ifood: pedido sem coordenadas de entrega; não despachado', ['merchant' => $vinculo->merchant_id, 'numero' => $dados['numero']]);
        }

        // o TrackingNumberObserver e o OrderConfig::default() leem a empresa da sessão
        session(['company' => $vinculo->company_uuid]);

        $pedido = DB::transaction(function () use ($vinculo, $vendor, $coleta, $dados, $pedidoIfood) {
            $entrega = $dados['entrega'];
            // nome e endereço em maiúsculas com mb_strtoupper: o PlaceObserver só faz strtoupper (ASCII) e gravaria "RIBEIRãO"
            $maiusculo = fn (?string $texto) => $texto === null ? null : mb_strtoupper($texto, 'UTF-8');
            $destino   = Place::create([
                'company_uuid' => $vinculo->company_uuid,
                'name'         => $maiusculo($entrega['nome']),
                'street1'      => $maiusculo($entrega['street1']),
                'street2'      => $maiusculo($entrega['street2']),
                'neighborhood' => $maiusculo($entrega['neighborhood']),
                'city'         => $maiusculo($entrega['city']),
                'province'     => $maiusculo($entrega['province']),
                'postal_code'  => $entrega['postal_code'],
                'country'      => $entrega['country'],
                'location'     => new Point($entrega['latitude'], $entrega['longitude']),
            ]);

            $payload               = new Payload();
            $payload->company_uuid = $vinculo->company_uuid;
            $payload->setPickup($coleta, [
                'callback' => function ($pickup, $payload) {
                    $payload->setCurrentWaypoint($pickup);
                },
            ]);
            $payload->setDropoff($destino);
            $payload->save();

            $config = OrderConfig::default() ?? OrderConfig::where('company_uuid', $vinculo->company_uuid)->first();
            if (!$config) {
                throw new \RuntimeException('a empresa não tem tipo de pedido (OrderConfig)');
            }

            $pedido = Order::create([
                'company_uuid'      => $vinculo->company_uuid,
                'customer_uuid'     => $vendor->uuid,
                'customer_type'     => Utils::getMutationType($vendor),
                'payload_uuid'      => $payload->uuid,
                'order_config_uuid' => $config->uuid,
                'type'              => $config->key,
                'status'            => 'created',
                'adhoc'             => true,
                'internal_id'       => $dados['numero'],
                'scheduled_at'      => $dados['scheduled_at'],
                'notes'             => $dados['notas'],
            ]);

            $agora = now()->toDateTimeString();
            DB::table(static::TABELA)->insert($dados['linha'] + [
                'company_uuid'    => $vinculo->company_uuid,
                'order_uuid'      => $pedido->uuid,
                'pedido_ifood_id' => (string) $pedidoIfood['id'],
                'merchant_id'     => $vinculo->merchant_id,
                'vendor_uuid'     => $vendor->uuid,
                'created_at'      => $agora,
                'updated_at'      => $agora,
            ]);

            return $pedido;
        });

        Log::info('[entregas] ifood: pedido criado', ['pedido' => $pedido->public_id, 'numero' => $dados['numero'], 'teste' => $dados['teste'], 'agendado' => $dados['agendado']]);

        if ($dados['despachar_agora']) {
            $this->despachar($pedido);
        }

        return DB::table(static::TABELA)->where('pedido_ifood_id', (string) $pedidoIfood['id'])->first();
    }

    /**
     * Pedido aberto (adhoc) aos motoboys próximos da coleta, como o portal. Se o fleetops:dispatch-orders já despachou o
     * agendado (ele despacha quem cai a ±1 min do scheduled_at, sem a atividade), só falta a atividade "dispatched".
     * Marca despachado_em; em falha, só registra no log (o entregas:ifood-agendados tenta de novo).
     */
    public function despachar(Order $pedido): bool
    {
        try {
            session(['company' => $pedido->company_uuid]);
            if ($pedido->dispatched) {
                if (!$pedido->hasDispatchedStatus()) {
                    $pedido->insertDispatchActivity();
                }
            } else {
                $pedido->adhoc = true;
                $pedido->saveQuietly();
                // dispatched + atividade "dispatched" + OrderDispatched na fila → avisa os motoboys no raio da coleta
                $pedido->firstDispatchWithActivity();
            }

            $agora = now()->toDateTimeString();
            DB::table(static::TABELA)->where('order_uuid', $pedido->uuid)->update(['despachado_em' => $agora, 'updated_at' => $agora]);

            return true;
        } catch (\Throwable $e) {
            Log::error('[entregas] ifood: falha ao despachar o pedido', ['pedido' => $pedido->public_id, 'erro' => get_class($e)]);

            return false;
        }
    }

    /** Aviso se o Local da loja está a mais de 300 m do endereço dela no iFood (pedido de teste não conta). */
    protected function conferirColeta(array $pedidoIfood, object $coleta, array $dados): void
    {
        $endereco = $pedidoIfood['merchant']['merchantAddress'] ?? null;
        if ($dados['teste'] || !is_array($endereco) || !isset($endereco['latitude'], $endereco['longitude'])) {
            return;
        }

        $metros = PedidoDoIfood::metrosEntre((float) $coleta->location->getLat(), (float) $coleta->location->getLng(), (float) $endereco['latitude'], (float) $endereco['longitude']);
        if ($metros > static::DIVERGENCIA_MAXIMA_METROS) {
            Log::warning('[entregas] ifood: coleta diverge do iFood (' . (int) round($metros) . ' m)', ['merchant' => $pedidoIfood['merchant']['id'] ?? null, 'numero' => $dados['numero']]);
        }
    }
}
```

- [ ] **Step 5: rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-criador.php`
Expected: `FALHAS: 0`.

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/Ifood/CriadorDoPedidoIfood.php scripts/teste-php/stubs-ifood-fleetbase.php`
Expected: `OK` nos dois.

- [ ] **Step 6: commit**

```bash
git rev-parse --show-toplevel
git add scripts/teste-php/stubs-ifood-fleetbase.php scripts/teste-php/ifood-criador.php api/app/Support/Entregas/Ifood/CriadorDoPedidoIfood.php
git commit -m "iFood: cria o pedido no Fleetbase (coleta na loja, entrega do iFood) e despacha como o portal

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: processamento por pedido (ProcessarPedidoIfood)

**Files:**
- Create: `api/app/Jobs/Entregas/ProcessarPedidoIfood.php`
- Create: `scripts/teste-php/ifood-processar.php`

A pasta `api/app/Jobs` ainda não existe (o autoload PSR-4 `App\` já a cobre). O job segue o padrão do
`packages/fleetops/server/src/Jobs/FinalizeApiOrderCreation.php` (`ShouldQueue`, `Dispatchable`, `InteractsWithQueue`,
`Queueable`), sem `SerializesModels` (só leva o id do pedido do iFood).

- [ ] **Step 1: escrever o teste**

Crie `scripts/teste-php/ifood-processar.php`:

```php
<?php

// Integração iFood: processamento dos eventos de um pedido (ProcessarPedidoIfood), com um criador falso.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-processar.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';
require __DIR__ . '/fixtures-ifood.php';

use App\Jobs\Entregas\ProcessarPedidoIfood;
use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\CriadorDoPedidoIfood;
use App\Support\Entregas\Ifood\ErroIfood;
use App\Support\Entregas\Ifood\EventosIfood;
use App\Support\Entregas\Ifood\VinculosIfood;
use Teste\Banco;
use Teste\Http;
use Teste\Trava;

// grava a linha do pedido como o de verdade, sem os models do Fleetbase
class CriadorFalso extends CriadorDoPedidoIfood
{
    public array $criados = [];

    public function criar(object $vinculo, array $pedidoIfood): ?object
    {
        $this->criados[] = [$vinculo->merchant_id, $pedidoIfood['id']];
        Banco::inserir('entregas_ifood_pedidos', [
            'company_uuid' => $vinculo->company_uuid, 'order_uuid' => 'order-' . count($this->criados), 'pedido_ifood_id' => $pedidoIfood['id'],
            'numero' => $pedidoIfood['displayId'], 'merchant_id' => $vinculo->merchant_id, 'exige_codigo' => false, 'cancelado_pelo_ifood_em' => null,
        ], false);

        return (new Teste\Consulta('entregas_ifood_pedidos'))->where('pedido_ifood_id', $pedidoIfood['id'])->first();
    }
}

function gravarEvento(string $id, string $codigo, string $criado, string $pedido = 'pedido-real-1', string $merchant = 'merchant-1'): void
{
    Banco::inserir('entregas_ifood_eventos', EventosIfood::paraGravar(['id' => $id, 'code' => $codigo, 'orderId' => $pedido, 'merchantId' => $merchant, 'createdAt' => $criado], '2026-10-05 18:00:00'), false);
}

function rodar(CriadorFalso $criador, string $pedido = 'pedido-real-1'): ProcessarPedidoIfood
{
    $job = new ProcessarPedidoIfood($pedido);
    $job->handle(new VinculosIfood(new ClienteIfood()), new ClienteIfood(), $criador);

    return $job;
}

function evento(string $id): object
{
    foreach (Banco::linhas('entregas_ifood_eventos') as $linha) {
        if ($linha->evento_id === $id) {
            return $linha;
        }
    }

    throw new LogicException("evento {$id} não gravado");
}

function linhaDoPedido(string $pedido = 'pedido-real-1'): ?object
{
    return (new Teste\Consulta('entregas_ifood_pedidos'))->where('pedido_ifood_id', $pedido)->first();
}

echo '== PLC cria o pedido' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00.100Z');
Http::responder(200, pedidoEmDinheiroComTroco());
$criador = new CriadorFalso();
rodar($criador);
confere(Http::urls() === ['GET /logistics/v1.0/orders/pedido-real-1'] && Http::$chamadas[0]['token'] === 'token-a', 'busca o pedido no Logistics com o token da loja');
confere($criador->criados === [['merchant-1', 'pedido-real-1']], 'cria uma vez, com o vínculo da loja');
confere(evento('ev-1')->processado_em === '2026-10-05 18:00:00' && evento('ev-1')->ignorado === false, 'evento processado');

echo '== Eventos seguintes não criam de novo' . PHP_EOL;
gravarEvento('ev-2', 'CFM', '2026-10-05T18:01:00Z');
gravarEvento('ev-3', 'DDCR', '2026-10-05T18:01:00.400Z');
rodar($criador);
confere(count($criador->criados) === 1 && count(Http::$chamadas) === 1, 'pedido já existe: não busca nem cria');
confere(linhaDoPedido()->exige_codigo === true, 'DDCR marca exige_codigo');
confere(evento('ev-2')->processado_em !== null && evento('ev-3')->processado_em !== null, 'CFM e DDCR processados');
rodar($criador);
confere(count($criador->criados) === 1, 'sem eventos pendentes: nada a fazer');

echo '== PLC, CFM e DDCR fora de ordem na mesma rodada' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-3', 'DDCR', '2026-10-05T18:01:00.400Z');
gravarEvento('ev-2', 'CFM', '2026-10-05T18:01:00Z');
gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00.100Z');
Http::responder(200, pedidoEmDinheiroComTroco());
$criador = new CriadorFalso();
rodar($criador);
confere(count($criador->criados) === 1 && linhaDoPedido()->exige_codigo === true, 'cria uma vez e marca exige_codigo');

echo '== PLC perdido: o primeiro evento conhecido cria' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-2', 'CFM', '2026-10-05T18:01:00Z');
Http::responder(200, pedidoEmDinheiroComTroco());
$criador = new CriadorFalso();
rodar($criador);
confere(count($criador->criados) === 1, 'CFM sem PLC também cria');

echo '== CAN depois de criado: só registra' . PHP_EOL;
gravarEvento('ev-4', 'CAR', '2026-10-05T18:05:00Z');
gravarEvento('ev-5', 'CAN', '2026-10-05T18:05:00.500Z');
rodar($criador);
confere(linhaDoPedido()->cancelado_pelo_ifood_em === '2026-10-05 18:05:00', 'cancelado_pelo_ifood_em = createdAt do CAN');
confere(logou('pedido cancelado pelo iFood', 'warning') && evento('ev-5')->processado_em !== null && evento('ev-4')->ignorado === false, 'log; CAR e CAN processados');

echo '== Cancelado antes de entrar: não cria' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00Z');
gravarEvento('ev-2', 'CAR', '2026-10-05T17:59:30Z');
gravarEvento('ev-3', 'CAN', '2026-10-05T17:59:31Z');
$criador = new CriadorFalso();
rodar($criador);
confere($criador->criados === [] && Http::$chamadas === [] && linhaDoPedido() === null, 'não busca nem cria');
confere(evento('ev-1')->processado_em !== null && evento('ev-3')->processado_em !== null && logou('cancelado antes de entrar'), 'eventos processados, com log');

echo '== Código desconhecido' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00Z');
gravarEvento('ev-2', 'HSD', '2026-10-05T18:00:00Z');
Http::responder(200, pedidoEmDinheiroComTroco());
rodar(new CriadorFalso());
confere(evento('ev-2')->ignorado === true && evento('ev-2')->processado_em !== null && evento('ev-1')->ignorado === false, 'gravado como ignorado');
confere(logou('[entregas] ifood: evento ignorado', 'info'), 'log do evento ignorado');

echo '== Loja não vinculada' . PHP_EOL;
reiniciarIfood();
gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00Z', 'pedido-x', 'merchant-9');
$criador = new CriadorFalso();
rodar($criador, 'pedido-x');
confere($criador->criados === [] && Http::$chamadas === [] && evento('ev-1')->ignorado === true, 'não busca, não cria, evento ignorado');
confere(logou('pedido de loja não vinculada', 'warning'), 'aviso no log');

echo '== Trava ocupada: volta para a fila' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00Z');
Trava::$ocupadas['entregas:ifood-pedido:pedido-real-1'] = true;
$criador = new CriadorFalso();
$job     = rodar($criador);
confere($job->liberadoPor === ProcessarPedidoIfood::ESPERA_DA_TRAVA && $criador->criados === [] && evento('ev-1')->processado_em === null, 'release(15) sem processar');

echo '== Pedido ainda indisponível (404): tenta de novo depois' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00Z');
Http::responder(404, ['message' => 'Order not found']);
$criador = new CriadorFalso();
$erro    = excecao(fn () => rodar($criador));
confere($erro instanceof ErroIfood && $erro->status === 404 && evento('ev-1')->processado_em === null, 'o erro sobe e o evento fica pendente');
confere(Trava::$ocupadas === [], 'a trava é solta mesmo com erro');
Http::responder(200, pedidoEmDinheiroComTroco());
rodar($criador);
confere(count($criador->criados) === 1, 'na tentativa seguinte, cria');

echo '== 401 no Logistics: renova e repete' . PHP_EOL;
reiniciarIfood();
vinculoDaLojaA();
gravarEvento('ev-1', 'PLC', '2026-10-05T17:59:00Z');
Http::responder(401, ['error' => ['code' => 'Unauthorized']]);
Http::responder(200, ['accessToken' => 'token-a2', 'type' => 'bearer', 'expiresIn' => 21600, 'refreshToken' => 'refresh-a2']);
Http::responder(200, pedidoEmDinheiroComTroco());
$criador = new CriadorFalso();
rodar($criador);
confere(Http::urls() === ['GET /logistics/v1.0/orders/pedido-real-1', 'POST /authentication/v1.0/oauth/token', 'GET /logistics/v1.0/orders/pedido-real-1'] && Http::$chamadas[2]['token'] === 'token-a2', 'token novo e a mesma busca de novo');
confere(count($criador->criados) === 1, 'cria');

echo '== Falha definitiva' . PHP_EOL;
reiniciarIfood();
(new ProcessarPedidoIfood('pedido-real-1'))->failed(new ErroIfood('pedido', 503, 'Cliente Ficticio, Rua Ficticia'));
confere(logou('[entregas] ifood: pedido não processado', 'error') && logsSem(['Cliente Ficticio', 'Rua Ficticia']), 'log só com a classe e o status');

resumo();
```

- [ ] **Step 2: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-processar.php`
Expected: erro de PHP `Class "App\Jobs\Entregas\ProcessarPedidoIfood" not found` (sai com 1).

- [ ] **Step 3: implementar**

`api/app/Jobs/Entregas/ProcessarPedidoIfood.php`:

```php
<?php

namespace App\Jobs\Entregas;

use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\CriadorDoPedidoIfood;
use App\Support\Entregas\Ifood\ErroIfood;
use App\Support\Entregas\Ifood\EventosIfood;
use App\Support\Entregas\Ifood\VinculoPerdido;
use App\Support\Entregas\Ifood\VinculosIfood;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: processa os eventos ainda pendentes de um pedido do iFood, em ordem de createdAt
 * (EventosIfood), um job por pedido (enfileirado pelo entregas:ifood-polling).
 *
 * Com uma trava por pedido do iFood (Cache::lock no Redis): dois jobs do mesmo pedido nunca rodam juntos; o segundo
 * volta para a fila. Na etapa 2:
 * - o primeiro evento que cria (PLC ou, se ele se perdeu, outro conhecido) busca o pedido no Logistics e cria o pedido
 *   (CriadorDoPedidoIfood). Pedido já existente (pedido_ifood_id único) nunca é criado de novo;
 * - pedido cancelado (CAN) antes de entrar não é criado;
 * - DDCR marca exige_codigo; CAN só registra cancelado_pelo_ifood_em (o cancelamento no Entregas é da etapa 3);
 * - código desconhecido, loja não vinculada (ou vínculo perdido) e loja sem Local de coleta ficam como ignorados.
 *
 * Erro do iFood ao buscar o pedido (404 ainda indisponível, 5xx, 429, rede) sobe: os eventos continuam pendentes e a
 * fila tenta de novo ($backoff). Logs sem dados do cliente (só ids e o número do pedido).
 */
class ProcessarPedidoIfood implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const EVENTOS = 'entregas_ifood_eventos';
    public const PEDIDOS = 'entregas_ifood_pedidos';

    /** Segundos até tentar de novo quando outro job do mesmo pedido está rodando. */
    public const ESPERA_DA_TRAVA = 15;

    public int $tries = 8;

    public array $backoff = [15, 30, 60, 120, 300];

    public function __construct(public string $pedidoIfoodId)
    {
    }

    public function handle(VinculosIfood $vinculos, ClienteIfood $cliente, CriadorDoPedidoIfood $criador): void
    {
        $trava = Cache::lock("entregas:ifood-pedido:{$this->pedidoIfoodId}", 120);
        if (!$trava->get()) {
            $this->release(static::ESPERA_DA_TRAVA);

            return;
        }

        try {
            $this->processar($vinculos, $cliente, $criador);
        } finally {
            $trava->release();
        }
    }

    public function processar(VinculosIfood $vinculos, ClienteIfood $cliente, CriadorDoPedidoIfood $criador): void
    {
        $linhas = DB::table(static::EVENTOS)->where('pedido_ifood_id', $this->pedidoIfoodId)->whereNull('processado_em')->get()->all();
        if (!$linhas) {
            return;
        }

        $eventos = EventosIfood::ordenar(array_map(fn (object $linha) => [
            'id'         => $linha->evento_id,
            'code'       => $linha->codigo,
            'merchantId' => $linha->merchant_id,
            'createdAt'  => $linha->criado_no_ifood,
        ], $linhas));
        $pedido = DB::table(static::PEDIDOS)->where('pedido_ifood_id', $this->pedidoIfoodId)->first();

        if (!$pedido && EventosIfood::temCancelamento($eventos)) {
            // cancelado antes de entrar (ex.: a loja recusou antes do polling): não vai aos motoboys
            Log::info('[entregas] ifood: pedido cancelado antes de entrar; não foi criado', ['pedido_ifood' => $this->pedidoIfoodId]);
            $this->marcar(array_column($eventos, 'id'), false);

            return;
        }

        foreach ($eventos as $evento) {
            $acao = EventosIfood::acao($evento['code']);

            if (!$pedido && EventosIfood::criaPedido($evento['code'])) {
                $pedido = $this->criar($evento['merchantId'], $vinculos, $cliente, $criador);
                if (!$pedido) {
                    $this->marcar(array_column($eventos, 'id'), true);

                    return;
                }
            }

            if ($acao === EventosIfood::EXIGE_CODIGO && $pedido && !$pedido->exige_codigo) {
                $this->atualizarPedido($pedido, ['exige_codigo' => true]);
                $pedido->exige_codigo = true;
            }

            if ($acao === EventosIfood::CANCELA && $pedido && !$pedido->cancelado_pelo_ifood_em) {
                $quando = $evento['createdAt'] ? substr((string) $evento['createdAt'], 0, 19) : now()->toDateTimeString();
                $this->atualizarPedido($pedido, ['cancelado_pelo_ifood_em' => $quando]);
                $pedido->cancelado_pelo_ifood_em = $quando;
                Log::warning('[entregas] ifood: pedido cancelado pelo iFood (o cancelamento no Entregas é da etapa 3)', ['pedido' => $pedido->order_uuid, 'numero' => $pedido->numero]);
            }

            if ($acao === EventosIfood::IGNORA) {
                Log::info('[entregas] ifood: evento ignorado', ['codigo' => $evento['code'], 'pedido_ifood' => $this->pedidoIfoodId]);
            }

            $this->marcar([$evento['id']], $acao === EventosIfood::IGNORA);
        }
    }

    /** A fila desistiu (tentativas esgotadas): só a classe do erro e o status, nunca a mensagem (pode trazer dados). */
    public function failed(\Throwable $erro): void
    {
        Log::error('[entregas] ifood: pedido não processado', [
            'pedido_ifood' => $this->pedidoIfoodId,
            'erro'         => get_class($erro),
            'status'       => $erro instanceof ErroIfood ? $erro->status : null,
        ]);
    }

    protected function criar(string $merchantId, VinculosIfood $vinculos, ClienteIfood $cliente, CriadorDoPedidoIfood $criador): ?object
    {
        $vinculo = $vinculos->porMerchant($merchantId);
        if (!$vinculo) {
            Log::warning('[entregas] ifood: pedido de loja não vinculada', ['merchant' => $merchantId, 'pedido_ifood' => $this->pedidoIfoodId]);

            return null;
        }

        try {
            $dados = $vinculos->comToken($vinculo, fn (string $token) => $cliente->pedidoLogistics($token, $this->pedidoIfoodId));
        } catch (VinculoPerdido) {
            return null;
        }

        return $criador->criar($vinculo, $dados);
    }

    protected function atualizarPedido(object $pedido, array $valores): void
    {
        DB::table(static::PEDIDOS)->where('id', $pedido->id)->update($valores + ['updated_at' => now()->toDateTimeString()]);
    }

    protected function marcar(array $ids, bool $ignorado): void
    {
        $agora = now()->toDateTimeString();
        DB::table(static::EVENTOS)->whereIn('evento_id', $ids)->update(['processado_em' => $agora, 'ignorado' => $ignorado, 'updated_at' => $agora]);
    }
}
```

- [ ] **Step 4: rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-processar.php`
Expected: `FALHAS: 0`.

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Jobs/Entregas/ProcessarPedidoIfood.php`
Expected: `OK`.

- [ ] **Step 5: commit**

```bash
git rev-parse --show-toplevel
git add api/app/Jobs/Entregas/ProcessarPedidoIfood.php scripts/teste-php/ifood-processar.php
git commit -m "iFood: job que processa os eventos de um pedido em ordem e cria o pedido no PLC

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: polling (entregas:ifood-polling)

**Files:**
- Create: `api/app/Console/Commands/Entregas/PollingIfood.php`
- Create: `scripts/teste-php/ifood-polling.php`

O `Kernel` carrega `app/Console/Commands` (com subpastas): o comando é registrado sozinho. Ele **não** usa
`storeOutputInDb` (o agendamento da Task 10 manda a saída para o stdout do container).

- [ ] **Step 1: escrever o teste**

Crie `scripts/teste-php/ifood-polling.php`:

```php
<?php

// Integração iFood: o polling (entregas:ifood-polling): lotes, gravação antes do ack, 429, 401, 403 e limpeza.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-polling.php

require __DIR__ . '/stubs-ifood.php';

use App\Console\Commands\Entregas\PollingIfood;
use App\Jobs\Entregas\ProcessarPedidoIfood;
use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\VinculosIfood;
use Illuminate\Support\Facades\Cache;
use Teste\Banco;
use Teste\Config;
use Teste\Fila;
use Teste\Http;

function lojaVinculada(string $vendor, string $merchant, string $token, string $expiraEm = '2026-10-05 23:00:00'): void
{
    Banco::inserir('entregas_ifood_lojas', [
        'company_uuid' => 'empresa-1', 'vendor_uuid' => $vendor, 'merchant_id' => $merchant, 'nome_ifood' => 'Loja ' . $merchant,
        'access_token' => encrypt($token), 'refresh_token' => encrypt('refresh-' . $merchant), 'expira_em' => $expiraEm, 'situacao' => 'vinculada',
        'vinculado_em' => '2026-10-05 12:00:00', 'renovado_em' => null, 'created_at' => '2026-10-05 12:00:00', 'updated_at' => '2026-10-05 12:00:00',
    ], false);
}

function eventoDoIfood(string $id, string $codigo, string $pedido, string $merchant = 'merchant-1'): array
{
    return ['id' => $id, 'code' => $codigo, 'fullCode' => $codigo, 'orderId' => $pedido, 'merchantId' => $merchant, 'createdAt' => '2026-10-05T17:59:00.000Z', 'salesChannel' => 'IFOOD'];
}

function rodarPolling(): int
{
    return (new PollingIfood())->handle(new VinculosIfood(new ClienteIfood()), new ClienteIfood());
}

function jobs(): array
{
    return array_map(fn (ProcessarPedidoIfood $job) => $job->pedidoIfoodId, Fila::$jobs);
}

echo '== Desligada' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
Config::$valores['services.ifood.ativo'] = '';
confere(rodarPolling() === 0 && Http::$chamadas === [], 'ENTREGAS_IFOOD vazio: sai sem chamar o iFood');

echo '== Duas lojas, um polling por token' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
lojaVinculada('vendor-b', 'merchant-2', 'token-b');
Http::responder(200, [eventoDoIfood('ev-1', 'PLC', 'pedido-1'), eventoDoIfood('ev-2', 'CFM', 'pedido-1'), eventoDoIfood('ev-1', 'PLC', 'pedido-1')]);
Http::responder(202);
Http::responder(204);
rodarPolling();
confere(Http::urls() === ['GET /events/v1.0/events:polling', 'POST /events/v1.0/events/acknowledgment', 'GET /events/v1.0/events:polling'], 'polling da loja A, ack, polling da loja B (204, sem ack)');
confere(Http::$chamadas[0]['token'] === 'token-a' && Http::$chamadas[0]['headers'] === ['x-polling-merchants' => 'merchant-1'], 'loja A com o token e o merchant dela');
confere(Http::$chamadas[2]['token'] === 'token-b' && Http::$chamadas[2]['headers'] === ['x-polling-merchants' => 'merchant-2'], 'loja B com o token e o merchant dela');
confere(count(Banco::linhas('entregas_ifood_eventos')) === 2, 'eventos gravados uma vez (o repetido não entra)');
confere(Http::$chamadas[1]['dados'] === [['id' => 'ev-1'], ['id' => 'ev-2']] && Http::$chamadas[1]['token'] === 'token-a', 'ack dos recebidos, com o token da loja');
confere(jobs() === ['pedido-1'], 'um processamento por pedido');

echo '== Evento repetido em outra rodada' . PHP_EOL;
Fila::$jobs = [];
foreach (Banco::$tabelas['entregas_ifood_eventos'] as $id => $linha) {
    Banco::$tabelas['entregas_ifood_eventos'][$id]['processado_em'] = '2026-10-05 18:00:10';
}
Http::responder(200, [eventoDoIfood('ev-1', 'PLC', 'pedido-1')]);
Http::responder(202);
Http::responder(204);
rodarPolling();
confere(count(Banco::linhas('entregas_ifood_eventos')) === 2 && Http::$chamadas[4]['dados'] === [['id' => 'ev-1']], 'não grava de novo, mas manda o ack');
confere(jobs() === [], 'nada pendente: nenhum processamento');

echo '== Banco fora do ar: sem ack' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
Banco::$falhar['entregas_ifood_eventos'] = 'MySQL server has gone away';
Http::responder(200, [eventoDoIfood('ev-1', 'PLC', 'pedido-1')]);
rodarPolling();
confere(Http::urls() === ['GET /events/v1.0/events:polling'], 'nenhum ack (o iFood reenvia na rodada seguinte)');
confere(jobs() === [] && logou('falha ao gravar os eventos', 'error'), 'nenhum processamento; erro no log');

echo '== Ack falhou: o processamento segue' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
Http::responder(200, [eventoDoIfood('ev-1', 'PLC', 'pedido-1')]);
Http::responder(500, 'erro');
rodarPolling();
confere(count(Banco::linhas('entregas_ifood_eventos')) === 1 && jobs() === ['pedido-1'] && logou('ack falhou', 'warning'), 'evento gravado e enfileirado; aviso no log');

echo '== 429: pausa pelo Retry-After' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
lojaVinculada('vendor-b', 'merchant-2', 'token-b');
Http::responder(429, ['message' => 'Too Many Requests'], ['Retry-After' => '17']);
rodarPolling();
confere(count(Http::$chamadas) === 1, 'para a rodada (a loja B fica para depois)');
confere(Cache::$dados[PollingIfood::CHAVE_PAUSA] === true && Cache::$validades[PollingIfood::CHAVE_PAUSA] === 17, 'pausa de 17 s no cache');
confere(logou('[entregas] ifood: 429, esperando 17 s', 'warning'), 'log "429, esperando 17 s"');
rodarPolling();
confere(count(Http::$chamadas) === 1, 'durante a pausa, não chama o iFood');

echo '== 401: renova e repete' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
Http::responder(401, ['error' => ['code' => 'Unauthorized']]);
Http::responder(200, ['accessToken' => 'token-a2', 'type' => 'bearer', 'expiresIn' => 21600, 'refreshToken' => 'refresh-2']);
Http::responder(204);
rodarPolling();
confere(Http::urls() === ['GET /events/v1.0/events:polling', 'POST /authentication/v1.0/oauth/token', 'GET /events/v1.0/events:polling'] && Http::$chamadas[2]['token'] === 'token-a2', 'polling, token novo, polling de novo');

echo '== Token vencendo: renova antes do polling' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a', '2026-10-05 18:02:00');
Http::responder(200, ['accessToken' => 'token-a2', 'type' => 'bearer', 'expiresIn' => 21600, 'refreshToken' => 'refresh-2']);
Http::responder(204);
rodarPolling();
confere(Http::urls() === ['POST /authentication/v1.0/oauth/token', 'GET /events/v1.0/events:polling'] && Http::$chamadas[1]['token'] === 'token-a2', 'renovação proativa (vence em 2 min)');

echo '== Vínculo perdido sai do polling' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a', '2026-10-05 18:02:00');
lojaVinculada('vendor-b', 'merchant-2', 'token-b');
Http::responder(400, ['error' => 'invalid_grant']);
Http::responder(204);
rodarPolling();
confere(Http::urls() === ['POST /authentication/v1.0/oauth/token', 'GET /events/v1.0/events:polling'] && Http::$chamadas[1]['token'] === 'token-b', 'a loja A cai (refresh recusado); a B segue');
confere(logou('[entregas] ifood: vínculo perdido', 'warning'), 'log de vínculo perdido');

echo '== 403: o dono revogou' . PHP_EOL;
reiniciarIfood();
lojaVinculada('vendor-a', 'merchant-1', 'token-a');
Http::responder(403, ['unauthorizedMerchants' => ['merchant-1']]);
rodarPolling();
confere(Banco::linhas('entregas_ifood_lojas')[0]->situacao === 'vinculo_perdido' && logou('polling falhou', 'warning'), 'loja vira vínculo perdido');

echo '== Lotes de até 100 lojas por token' . PHP_EOL;
$comToken = [];
foreach (range(1, 150) as $i) {
    $comToken[] = ['token' => 'token-unico', 'vinculo' => (object) ['id' => $i], 'merchant_id' => "merchant-{$i}"];
}
$comToken[] = ['token' => 'token-outro', 'vinculo' => (object) ['id' => 999], 'merchant_id' => 'merchant-999'];
$lotes      = PollingIfood::lotes($comToken);
confere(count($lotes) === 3 && count($lotes[0]['merchants']) === 100 && count($lotes[1]['merchants']) === 50 && $lotes[2]['merchants'] === ['merchant-999'], '150 lojas do mesmo token = 100 + 50; outro token, outro lote');
confere($lotes[1]['vinculo']->id === 101 && $lotes[2]['token'] === 'token-outro', 'cada lote leva o primeiro vínculo dele');

echo '== Limpeza diária' . PHP_EOL;
reiniciarIfood();
foreach (['velho' => '2026-09-27 10:00:00', 'recente' => '2026-10-01 10:00:00', 'pendente' => null] as $id => $processado) {
    Banco::inserir('entregas_ifood_eventos', ['evento_id' => $id, 'merchant_id' => 'm', 'pedido_ifood_id' => 'p', 'codigo' => 'CFM', 'processado_em' => $processado], false);
}
rodarPolling();
confere(array_column(Banco::linhas('entregas_ifood_eventos'), 'evento_id') === ['recente', 'pendente'], 'apaga só os processados há mais de 7 dias');
confere(Cache::$validades[PollingIfood::CHAVE_LIMPEZA] === 86400, 'uma vez por dia');
Banco::inserir('entregas_ifood_eventos', ['evento_id' => 'velho-2', 'merchant_id' => 'm', 'pedido_ifood_id' => 'p', 'codigo' => 'CFM', 'processado_em' => '2026-09-01 10:00:00'], false);
rodarPolling();
confere(count(Banco::linhas('entregas_ifood_eventos')) === 3, 'na mesma data, não apaga de novo');

echo '== Sem dados sensíveis no log' . PHP_EOL;
confere(logsSem(['token-', 'refresh-']), 'nenhum token nos logs');

resumo();
```

- [ ] **Step 2: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-polling.php`
Expected: erro de PHP `Class "App\Console\Commands\Entregas\PollingIfood" not found` (sai com 1).

- [ ] **Step 3: implementar**

`api/app/Console/Commands/Entregas/PollingIfood.php`:

```php
<?php

namespace App\Console\Commands\Entregas;

use App\Jobs\Entregas\ProcessarPedidoIfood;
use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\ErroIfood;
use App\Support\Entregas\Ifood\EventosIfood;
use App\Support\Entregas\Ifood\VinculoPerdido;
use App\Support\Entregas\Ifood\VinculosIfood;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: polling de eventos do iFood (agendado a cada 30 s no App\Console\Kernel, sem sobrepor).
 *
 * Só roda com a integração ligada (ENTREGAS_IFOOD=1 e as credenciais). As lojas vinculadas são agrupadas pelo token
 * (no app distribuído cada loja tem o seu, então em geral é um polling por loja), até 100 lojas por chamada. Para cada
 * lote: grava os eventos em entregas_ifood_eventos (o evento_id único descarta repetidos), manda o ack de todos os
 * recebidos só depois de gravar (se o banco falhar, sem ack: o iFood reenvia na rodada seguinte) e enfileira um
 * ProcessarPedidoIfood por pedido com evento pendente.
 *
 * 429: para a rodada e pausa o polling pelo Retry-After (cache). 401: renova o token e repete uma vez
 * (VinculosIfood::comToken). 403 com unauthorizedMerchants: o dono revogou; a loja vira vínculo perdido. Uma vez por
 * dia apaga os eventos processados há mais de 7 dias. Logs "[entregas] ifood:" só com ids e contagens.
 */
class PollingIfood extends Command
{
    protected $signature = 'entregas:ifood-polling';

    protected $description = 'iFood: busca os eventos das lojas vinculadas, grava, confirma (ack) e enfileira o processamento';

    public const EVENTOS = 'entregas_ifood_eventos';

    /** Cache com a pausa depois de um 429 (validade = Retry-After). */
    public const CHAVE_PAUSA = 'entregas:ifood-polling-pausa';

    /** Cache que marca a limpeza do dia. */
    public const CHAVE_LIMPEZA = 'entregas:ifood-eventos-limpeza';

    public const DIAS_GUARDADOS = 7;

    public function handle(VinculosIfood $vinculos, ClienteIfood $cliente): int
    {
        if (!ClienteIfood::ligada() || Cache::get(static::CHAVE_PAUSA)) {
            return self::SUCCESS;
        }

        $comToken = [];
        foreach ($vinculos->vinculadas() as $vinculo) {
            try {
                $comToken[] = ['token' => $vinculos->tokenValido($vinculo), 'vinculo' => $vinculo, 'merchant_id' => $vinculo->merchant_id];
            } catch (VinculoPerdido) {
                continue;
            } catch (ErroIfood $e) {
                Log::warning('[entregas] ifood: token indisponível para o polling', ['merchant' => $vinculo->merchant_id, 'status' => $e->status]);
            }
        }

        foreach (static::lotes($comToken) as $lote) {
            if (!$this->rodarLote($lote, $vinculos, $cliente)) {
                break;
            }
        }

        $this->limparAntigos();

        return self::SUCCESS;
    }

    /**
     * Agrupa pelo token e corta em lotes de até 100 lojas: [['token', 'vinculo' (o primeiro do lote, para renovar no
     * 401), 'merchants' => [...]], ...].
     */
    public static function lotes(array $comToken): array
    {
        $porToken = [];
        foreach ($comToken as $item) {
            $porToken[(string) $item['token']][] = $item;
        }

        $lotes = [];
        foreach ($porToken as $token => $itens) {
            foreach (array_chunk($itens, ClienteIfood::MAX_MERCHANTS_POR_POLLING) as $pedaco) {
                $lotes[] = ['token' => (string) $token, 'vinculo' => $pedaco[0]['vinculo'], 'merchants' => array_column($pedaco, 'merchant_id')];
            }
        }

        return $lotes;
    }

    /** false = 429: a rodada para. */
    protected function rodarLote(array $lote, VinculosIfood $vinculos, ClienteIfood $cliente): bool
    {
        try {
            $eventos = $vinculos->comToken($lote['vinculo'], fn (string $token) => $cliente->polling($token, $lote['merchants']));
        } catch (VinculoPerdido) {
            return true;
        } catch (ErroIfood $e) {
            if ($e->limiteExcedido()) {
                $espera = $e->retryAfter ?? ClienteIfood::ESPERA_PADRAO_429;
                Cache::put(static::CHAVE_PAUSA, true, $espera);
                Log::warning("[entregas] ifood: 429, esperando {$espera} s", ['lojas' => count($lote['merchants'])]);

                return false;
            }
            if ($e->status === 403) {
                foreach ((array) ($e->corpoJson()['unauthorizedMerchants'] ?? []) as $merchant) {
                    if (is_string($merchant)) {
                        $vinculos->perderPorMerchant($merchant, 403);
                    }
                }
            }
            Log::warning('[entregas] ifood: polling falhou', ['status' => $e->status, 'lojas' => count($lote['merchants'])]);

            return true;
        }

        if (!$eventos) {
            return true;
        }

        $unicos = EventosIfood::deduplicar($eventos);
        $agora  = now()->toDateTimeString();
        $linhas = array_values(array_filter(array_map(fn (array $evento) => EventosIfood::paraGravar($evento, $agora), $unicos)));

        try {
            DB::table(static::EVENTOS)->insertOrIgnore($linhas);
        } catch (\Throwable $e) {
            Log::error('[entregas] ifood: falha ao gravar os eventos; sem ack, o iFood reenvia', ['erro' => get_class($e), 'eventos' => count($linhas)]);

            return true;
        }

        // ack de todos os recebidos, inclusive os repetidos e os que não vamos usar (a documentação pede)
        $ids = array_column($unicos, 'id');
        try {
            $vinculos->comToken($lote['vinculo'], fn (string $token) => $cliente->ack($token, $ids));
        } catch (\Throwable $e) {
            Log::warning('[entregas] ifood: ack falhou', ['status' => $e instanceof ErroIfood ? $e->status : get_class($e), 'eventos' => count($ids)]);
        }

        // um processamento por pedido com evento pendente (inclui os que falharam em rodadas anteriores)
        $pedidos = array_values(array_unique(array_column($linhas, 'pedido_ifood_id')));
        if ($pedidos) {
            $pendentes = DB::table(static::EVENTOS)->whereIn('pedido_ifood_id', $pedidos)->whereNull('processado_em')->pluck('pedido_ifood_id')->all();
            foreach (array_values(array_unique($pendentes)) as $pedido) {
                ProcessarPedidoIfood::dispatch($pedido);
            }
        }

        return true;
    }

    protected function limparAntigos(): void
    {
        if (Cache::get(static::CHAVE_LIMPEZA)) {
            return;
        }
        Cache::put(static::CHAVE_LIMPEZA, true, 86400);

        $apagados = DB::table(static::EVENTOS)
            ->whereNotNull('processado_em')
            ->where('processado_em', '<', now()->subDays(static::DIAS_GUARDADOS)->toDateTimeString())
            ->delete();

        if ($apagados) {
            Log::info('[entregas] ifood: eventos antigos apagados', ['quantidade' => $apagados]);
        }
    }
}
```

- [ ] **Step 4: rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-polling.php`
Expected: `FALHAS: 0`.

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Console/Commands/Entregas/PollingIfood.php`
Expected: `OK`.

- [ ] **Step 5: commit**

```bash
git rev-parse --show-toplevel
git add api/app/Console/Commands/Entregas/PollingIfood.php scripts/teste-php/ifood-polling.php
git commit -m "iFood: polling a cada 30 s com gravação antes do ack, 429, 401, 403 e limpeza diária

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 10: renovação dos tokens, agendados e o agendador

**Files:**
- Create: `api/app/Console/Commands/Entregas/RenovarTokensIfood.php`
- Create: `api/app/Console/Commands/Entregas/AgendadosIfood.php`
- Modify: `api/app/Console/Kernel.php`
- Create: `scripts/teste-php/ifood-agendador.php`

- [ ] **Step 1: escrever o teste**

Crie `scripts/teste-php/ifood-agendador.php`:

```php
<?php

// Integração iFood: os comandos entregas:ifood-tokens e entregas:ifood-agendados e o agendamento no Kernel.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-agendador.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Console\Commands\Entregas\AgendadosIfood;
use App\Console\Commands\Entregas\RenovarTokensIfood;
use App\Console\Kernel;
use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\CriadorDoPedidoIfood;
use App\Support\Entregas\Ifood\VinculosIfood;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Console\Scheduling\Schedule;
use Teste\Banco;
use Teste\Config;
use Teste\Http;

/** Linha de entregas_ifood_pedidos com um Order; devolve o Order. */
function pedidoIfood(string $id, ?string $despacharEm, array $extra = [], string $status = 'created'): Order
{
    $pedido = Order::create(['company_uuid' => 'empresa-1', 'status' => $status]);
    Banco::inserir('entregas_ifood_pedidos', $extra + [
        'company_uuid' => 'empresa-1', 'order_uuid' => $pedido->uuid, 'pedido_ifood_id' => $id, 'numero' => $id, 'merchant_id' => 'merchant-1',
        'teste' => false, 'agendado' => true, 'despachar_em' => $despacharEm, 'despachado_em' => null, 'cancelado_pelo_ifood_em' => null,
    ], false);

    return $pedido;
}

function linhaDe(string $id): object
{
    return (new Teste\Consulta('entregas_ifood_pedidos'))->where('pedido_ifood_id', $id)->first();
}

function rodarAgendados(): int
{
    return (new AgendadosIfood())->handle(new CriadorDoPedidoIfood());
}

echo '== entregas:ifood-tokens' . PHP_EOL;
reiniciarIfood();
reiniciarFleetbase();
vinculoDaLojaA('2026-10-05 18:40:00');
Config::$valores['services.ifood.ativo'] = '';
(new RenovarTokensIfood())->handle(new VinculosIfood(new ClienteIfood()));
confere(Http::$chamadas === [], 'desligada: não renova');
Config::$valores['services.ifood.ativo'] = '1';
Http::responder(200, ['accessToken' => 'token-a2', 'type' => 'bearer', 'expiresIn' => 21600, 'refreshToken' => 'refresh-a2']);
confere((new RenovarTokensIfood())->handle(new VinculosIfood(new ClienteIfood())) === 0, 'sai com 0');
confere(Http::urls() === ['POST /authentication/v1.0/oauth/token'] && logou('[entregas] ifood: renovação dos tokens', 'info'), 'renova o que vence em 40 min e registra a contagem');

echo '== entregas:ifood-agendados' . PHP_EOL;
reiniciarIfood();
reiniciarFleetbase();
$vencido   = pedidoIfood('vencido', '2026-10-05 17:50:00');
$futuro    = pedidoIfood('futuro', '2026-10-05 19:20:00');
$agora     = pedidoIfood('agora', '2026-10-05 17:59:30');
$teste     = pedidoIfood('teste', null, ['teste' => true]);
$cancelado = pedidoIfood('cancelado', '2026-10-05 17:00:00', ['cancelado_pelo_ifood_em' => '2026-10-05 17:30:00']);
$feito     = pedidoIfood('feito', '2026-10-05 17:00:00', ['despachado_em' => '2026-10-05 17:00:05']);
$encerrado = pedidoIfood('encerrado', '2026-10-05 17:00:00', [], 'canceled');
Config::$valores['services.ifood.ativo'] = '';
rodarAgendados();
confere($vencido->chamadas === [], 'desligada: não despacha');
Config::$valores['services.ifood.ativo'] = '1';
rodarAgendados();
confere($vencido->chamadas === ['saveQuietly', 'firstDispatchWithActivity'] && linhaDe('vencido')->despachado_em === '2026-10-05 18:00:00', 'despacha o vencido e marca despachado_em');
confere($futuro->chamadas === [] && $agora->chamadas === [], 'o futuro espera; o recém-criado tem 1 min de folga (o job despacha)');
confere($teste->chamadas === [] && $cancelado->chamadas === [] && $feito->chamadas === [], 'teste, cancelado pelo iFood e já despachado ficam de fora');
confere($encerrado->chamadas === [] && linhaDe('encerrado')->despachar_em === null, 'encerrado pela central: sai da fila');
confere(logou('[entregas] ifood: pedido despachado pelo agendador', 'info'), 'log do despacho');
$vencido->chamadas = [];
rodarAgendados();
confere($vencido->chamadas === [], 'na rodada seguinte, não despacha de novo');

echo '== Kernel' . PHP_EOL;
$schedule = new Schedule();
$metodo   = new ReflectionMethod(Kernel::class, 'schedule');
$metodo->setAccessible(true);
$metodo->invoke((new ReflectionClass(Kernel::class))->newInstanceWithoutConstructor(), $schedule);
$porComando = [];
foreach ($schedule->eventos as $evento) {
    $porComando[$evento->comando] = $evento->chamadas;
}
confere(array_keys($porComando) === ['entregas:ifood-polling', 'entregas:ifood-agendados', 'entregas:ifood-tokens'], 'os três comandos agendados');
confere(isset($porComando['entregas:ifood-polling']['everyThirtySeconds']) && isset($porComando['entregas:ifood-agendados']['everyMinute']) && isset($porComando['entregas:ifood-tokens']['everyThirtyMinutes']), 'a cada 30 s, a cada minuto e a cada 30 min');
foreach ($porComando as $comando => $chamadas) {
    confere(($chamadas['withoutOverlapping'][0] ?? 0) > 0 && ($chamadas['withoutOverlapping'][0] ?? 99) <= 10, "{$comando}: sem sobrepor, com trava de validade curta");
    confere(($chamadas['appendOutputTo'] ?? null) === ['/proc/1/fd/1'], "{$comando}: saída no stdout do container");
    confere(!isset($chamadas['storeOutputInDb']), "{$comando}: sem storeOutputInDb");
}
confere(isset($porComando['entregas:ifood-polling']['runInBackground']), 'polling em segundo plano (não espera os comandos do Fleet-Ops)');

resumo();
```

- [ ] **Step 2: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-agendador.php`
Expected: erro de PHP `Class "App\Console\Commands\Entregas\RenovarTokensIfood" not found` (sai com 1).

- [ ] **Step 3: criar `entregas:ifood-tokens`**

`api/app/Console/Commands/Entregas/RenovarTokensIfood.php`:

```php
<?php

namespace App\Console\Commands\Entregas;

use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\VinculosIfood;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: renovação proativa dos tokens do iFood (agendada a cada 30 min no App\Console\Kernel):
 * renova os que vencem em menos de 1 h. Refresh recusado = vínculo perdido (VinculosIfood). Só com a integração ligada.
 */
class RenovarTokensIfood extends Command
{
    protected $signature = 'entregas:ifood-tokens';

    protected $description = 'iFood: renova os tokens das lojas vinculadas que vencem em menos de 1 h';

    public function handle(VinculosIfood $vinculos): int
    {
        if (!ClienteIfood::ligada()) {
            return self::SUCCESS;
        }

        $resultado = $vinculos->renovarVencendo();
        if ($resultado['renovados'] || $resultado['perdidos'] || $resultado['falhas']) {
            Log::info('[entregas] ifood: renovação dos tokens', $resultado);
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: criar `entregas:ifood-agendados`**

`api/app/Console/Commands/Entregas/AgendadosIfood.php`:

```php
<?php

namespace App\Console\Commands\Entregas;

use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\CriadorDoPedidoIfood;
use App\Support\Entregas\StatusDoPedido;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: despacha aos motoboys os pedidos iFood com `despachar_em` vencido e ainda não despachados
 * (agendado a cada minuto no App\Console\Kernel). Pega:
 * - o agendado (despachar_em = início da janela − 40 min). O fleetops:dispatch-orders não basta: só despacha quem cai
 *   a ±1 min do scheduled_at na rodada, e um minuto perdido deixaria o pedido parado. Se ele despachar antes, o
 *   CriadorDoPedidoIfood::despachar só põe a atividade que falta;
 * - o imediato cujo despacho falhou no job (1 min de folga, para não correr junto com o job).
 * Fica de fora: pedido de teste (sem despachar_em), cancelado pelo iFood e pedido encerrado ou apagado (sai da fila).
 * A chegada pelo GPS entra aqui na etapa 3 (entregas:ifood-acompanhar da spec).
 */
class AgendadosIfood extends Command
{
    protected $signature = 'entregas:ifood-agendados';

    protected $description = 'iFood: despacha os pedidos agendados (e os de despacho falho) quando chega a hora';

    public const PEDIDOS = 'entregas_ifood_pedidos';

    public const POR_RODADA = 50;

    public function handle(CriadorDoPedidoIfood $criador): int
    {
        if (!ClienteIfood::ligada()) {
            return self::SUCCESS;
        }

        $limite = now()->subMinute()->toDateTimeString();
        $linhas = DB::table(static::PEDIDOS)
            ->whereNull('despachado_em')
            ->whereNull('cancelado_pelo_ifood_em')
            ->whereNotNull('order_uuid')
            ->whereNotNull('despachar_em')
            ->where('despachar_em', '<=', $limite)
            ->where('teste', false)
            ->orderBy('despachar_em')
            ->limit(static::POR_RODADA)
            ->get()
            ->all();

        foreach ($linhas as $linha) {
            $pedido = Order::where('uuid', $linha->order_uuid)->first();
            if (!$pedido || in_array($pedido->status, StatusDoPedido::ENCERRADOS, true)) {
                // apagado, cancelado ou concluído pela central: sai da fila
                DB::table(static::PEDIDOS)->where('id', $linha->id)->update(['despachar_em' => null, 'updated_at' => now()->toDateTimeString()]);
                continue;
            }

            if ($criador->despachar($pedido)) {
                Log::info('[entregas] ifood: pedido despachado pelo agendador', ['pedido' => $pedido->public_id, 'numero' => $linha->numero, 'agendado' => (bool) $linha->agendado]);
            }
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: agendar no Kernel**

Substitua o conteúdo de `api/app/Console/Kernel.php` (hoje com o `schedule()` vazio) por:

```php
<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Saída dos comandos agendados do Entregas: o stdout do container do scheduler. O go-crond é o PID 1 e roda como
     * root, então dá para escrever em /proc/1/fd/1. Sem isso o Laravel joga a saída do comando agendado em /dev/null e os
     * logs "[entregas] ifood:" (LOG_CHANNEL=stdout) não apareceriam no `docker service logs entregas_scheduler`.
     */
    public const SAIDA_DO_CONTAINER = '/proc/1/fd/1';

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Entregas RestaurantePro: integração iFood (cada comando sai sem fazer nada com ENTREGAS_IFOOD desligado).
        // everyThirtySeconds é do Laravel 10.15+ (o composer.lock está no 10.x de 2026-08): o schedule:run que o go-crond
        // chama a cada minuto fica rodando o minuto inteiro e repete o polling aos 30 s. runInBackground: o polling não
        // espera os comandos do Fleet-Ops da mesma rodada. withoutOverlapping com validade curta (minutos): se o processo
        // morrer segurando a trava, ela some logo (o padrão é 24 h).
        $schedule->command('entregas:ifood-polling')->everyThirtySeconds()->withoutOverlapping(5)->runInBackground()->appendOutputTo(static::SAIDA_DO_CONTAINER);
        $schedule->command('entregas:ifood-agendados')->everyMinute()->withoutOverlapping(5)->runInBackground()->appendOutputTo(static::SAIDA_DO_CONTAINER);
        $schedule->command('entregas:ifood-tokens')->everyThirtyMinutes()->withoutOverlapping(10)->appendOutputTo(static::SAIDA_DO_CONTAINER);
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');
    }
}
```

- [ ] **Step 6: rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-agendador.php`
Expected: `FALHAS: 0`.

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Console/Commands/Entregas/RenovarTokensIfood.php api/app/Console/Commands/Entregas/AgendadosIfood.php api/app/Console/Kernel.php`
Expected: `OK` nos três.

- [ ] **Step 7: commit**

```bash
git rev-parse --show-toplevel
git add api/app/Console/Commands/Entregas/RenovarTokensIfood.php api/app/Console/Commands/Entregas/AgendadosIfood.php api/app/Console/Kernel.php scripts/teste-php/ifood-agendador.php
git commit -m "iFood: renovação proativa dos tokens, despacho dos agendados e agendamento dos comandos

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 11: endpoints do vínculo e o bloco `ifood` da loja

**Files:**
- Create: `api/app/Http/Controllers/Entregas/IfoodLojasController.php`
- Modify: `api/app/Http/Controllers/Entregas/LojasController.php`
- Modify: `api/app/Providers/RouteServiceProvider.php`
- Create: `scripts/teste-php/ifood-lojas.php`

Contrato para o plano 2B (todas em `int/v1/entregas`, só admin; erros no formato `{"errors": ["…"]}` que o
`notifications.serverError` do console já mostra):

| Rota | Corpo | Resposta |
|---|---|---|
| `GET lojas` | — | `{lojas: [{…, ifood: {situacao, nome, merchant_id}}], ifood_ligado: bool}` |
| `POST lojas/{id}/ifood/codigo` | — | `{codigo, link, expira_em_segundos}`; 409 desligada; 502 iFood fora |
| `POST lojas/{id}/ifood/vincular` | `{authorizationCode}` ou `{merchant_id}` | `{loja}` ou `{escolher: [{id, nome}]}`; 422 com a mensagem; 409; 502 |
| `DELETE lojas/{id}/ifood` | — | `{loja}` (funciona com a integração desligada) |

`ifood.situacao`: `"vinculada"`, `"vinculo_perdido"` ou `null` (nunca vinculada ou desvinculada).

- [ ] **Step 1: escrever o teste**

Crie `scripts/teste-php/ifood-lojas.php`:

```php
<?php

// Integração iFood: as rotas do vínculo na tela Lojas (IfoodLojasController) e o bloco `ifood` da loja.
// Uso: PHP_WASM_DIR=<pasta> node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-lojas.php

require __DIR__ . '/stubs-ifood.php';
require __DIR__ . '/stubs-ifood-fleetbase.php';

use App\Http\Controllers\Entregas\IfoodLojasController;
use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\VinculosIfood;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;
use Teste\Banco;
use Teste\Config;
use Teste\Http;
use Teste\RespostaJson;
use Teste\Sessao;

// o formatar() de verdade lê usuários e endereço pelo Eloquent; aqui fica só o id e o bloco do iFood
class ControllerDeTeste extends IfoodLojasController
{
    protected function formatar(Vendor $vendor): array
    {
        return ['id' => $vendor->public_id, 'ifood' => $this->resumoIfood($vendor)];
    }
}

class Usuario
{
    public function __construct(private bool $admin) {}
    public function isNotAdmin(): bool { return !$this->admin; }
}

function preparar(bool $admin = true): ControllerDeTeste
{
    reiniciarIfood();
    reiniciarFleetbase();
    Sessao::$dados = ['company' => 'empresa-1'];
    Auth::$usuario = new Usuario($admin);

    return new ControllerDeTeste();
}

function vinculos(): VinculosIfood
{
    return new VinculosIfood(new ClienteIfood());
}

function respostaDoCodigo(): void
{
    Http::responder(200, ['userCode' => 'ABCD-EFGH', 'authorizationCodeVerifier' => 'verificador-1', 'verificationUrl' => 'https://portal.ifood.com.br/apps/code', 'verificationUrlComplete' => 'https://portal.ifood.com.br/apps/code?c=ABCD-EFGH', 'expiresIn' => 600]);
}

echo '== Só administradores' . PHP_EOL;
$controller = preparar(false);
foreach (['codigo' => [], 'vincular' => ['authorizationCode' => 'AUTH-1'], 'desvincular' => []] as $metodo => $dados) {
    $resposta = $controller->$metodo(new Request($dados), vinculos(), 'vendor_a');
    confere($resposta instanceof RespostaJson && $resposta->status === 403, "{$metodo}: 403 para quem não é admin");
}
confere(Http::$chamadas === [], 'sem chamar o iFood');

echo '== Integração desligada' . PHP_EOL;
$controller                              = preparar();
Config::$valores['services.ifood.ativo'] = '';
confere($controller->codigo(new Request(), vinculos(), 'vendor_a')->status === 409, 'código: 409');
$resposta = $controller->vincular(new Request(['authorizationCode' => 'AUTH-1']), vinculos(), 'vendor_a');
confere($resposta->status === 409 && $resposta->dados['errors'] === ['Integração iFood desligada.'], 'vínculo: 409 "Integração iFood desligada."');

echo '== Código de vínculo' . PHP_EOL;
$controller = preparar();
respostaDoCodigo();
$resposta = $controller->codigo(new Request(), vinculos(), 'vendor_a');
confere($resposta->status === 200 && $resposta->dados === ['codigo' => 'ABCD-EFGH', 'link' => 'https://portal.ifood.com.br/apps/code?c=ABCD-EFGH', 'expira_em_segundos' => 600], 'devolve código, link e validade (pela loja do public_id)');
confere(isset(\Illuminate\Support\Facades\Cache::$dados[VinculosIfood::chaveDoVerificador('vendor-a')]), 'verificador guardado pelo uuid da loja');
Http::responder(503, 'fora do ar');
confere($controller->codigo(new Request(), vinculos(), 'vendor_a')->status === 502, 'iFood fora do ar: 502');
confere(excecao(fn () => $controller->codigo(new Request(), vinculos(), 'vendor_x')) !== null, 'loja de outra empresa ou inexistente: 404');

echo '== Vincular' . PHP_EOL;
confere($controller->vincular(new Request([]), vinculos(), 'vendor_a')->status === 422, 'sem código: 422');
respostaDoCodigo();
$controller->codigo(new Request(), vinculos(), 'vendor_a');
Http::responder(200, ['accessToken' => 'token-1', 'type' => 'bearer', 'expiresIn' => 21600, 'refreshToken' => 'refresh-1']);
Http::responder(200, [['id' => 'merchant-1', 'name' => 'Pizzaria Um']]);
$resposta = $controller->vincular(new Request(['authorizationCode' => 'AUTH-1']), vinculos(), 'vendor_a');
confere($resposta->status === 200 && $resposta->dados === ['loja' => ['id' => 'vendor_a', 'ifood' => ['situacao' => 'vinculada', 'nome' => 'Pizzaria Um', 'merchant_id' => 'merchant-1']]], 'devolve a loja com o bloco ifood (sem tokens)');
confere(Banco::linhas('entregas_ifood_lojas')[0]->company_uuid === 'empresa-1', 'gravado na empresa da sessão');

echo '== Várias lojas no iFood' . PHP_EOL;
$controller = preparar();
respostaDoCodigo();
$controller->codigo(new Request(), vinculos(), 'vendor_a');
Http::responder(200, ['accessToken' => 'token-1', 'type' => 'bearer', 'expiresIn' => 21600, 'refreshToken' => 'refresh-1']);
Http::responder(200, [['id' => 'merchant-1', 'name' => 'Centro'], ['id' => 'merchant-2', 'name' => 'Bairro']]);
$resposta = $controller->vincular(new Request(['authorizationCode' => 'AUTH-1']), vinculos(), 'vendor_a');
confere($resposta->dados === ['escolher' => [['id' => 'merchant-1', 'nome' => 'Centro'], ['id' => 'merchant-2', 'nome' => 'Bairro']]], 'pede a escolha');
$resposta = $controller->vincular(new Request(['merchant_id' => 'merchant-2']), vinculos(), 'vendor_a');
confere($resposta->dados['loja']['ifood']['merchant_id'] === 'merchant-2', 'a escolha conclui o vínculo');

echo '== Erros do vínculo' . PHP_EOL;
$controller = preparar();
$resposta   = $controller->vincular(new Request(['authorizationCode' => 'AUTH-1']), vinculos(), 'vendor_a');
confere($resposta->status === 422 && str_contains($resposta->dados['errors'][0], 'venceu'), 'sem código de vínculo gerado: 422 "venceu"');
respostaDoCodigo();
$controller->codigo(new Request(), vinculos(), 'vendor_a');
Http::responder(401, ['error' => ['code' => 'Unauthorized', 'message' => 'Bad credentials']]);
$resposta = $controller->vincular(new Request(['authorizationCode' => 'ERRADO']), vinculos(), 'vendor_a');
confere($resposta->status === 422 && str_contains($resposta->dados['errors'][0], 'recusou o código'), 'código recusado pelo iFood: 422');
confere(logou('[entregas] ifood: vínculo falhou', 'warning') && logsSem(['ERRADO', 'verificador-1']), 'log sem o código');

echo '== Desvincular' . PHP_EOL;
$controller = preparar();
vinculoDaLojaA();
Config::$valores['services.ifood.ativo'] = '';
$resposta = $controller->desvincular(new Request(), vinculos(), 'vendor_a');
confere($resposta->status === 200 && $resposta->dados['loja']['ifood'] === ['situacao' => null, 'nome' => null, 'merchant_id' => null], 'desvincula (mesmo com a integração desligada)');

resumo();
```

- [ ] **Step 2: rodar e ver falhar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-lojas.php`
Expected: erro de PHP `Class "App\Http\Controllers\Entregas\IfoodLojasController" not found` (sai com 1).

- [ ] **Step 3: criar o controller do vínculo**

`api/app/Http/Controllers/Entregas/IfoodLojasController.php`:

```php
<?php

namespace App\Http\Controllers\Entregas;

use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\ErroDeVinculo;
use App\Support\Entregas\Ifood\ErroIfood;
use App\Support\Entregas\Ifood\VinculosIfood;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: vínculo da Loja com o iFood na tela Lojas (só administradores), pelo fluxo distribuído
 * (VinculosIfood):
 * - POST lojas/{id}/ifood/codigo: pede o código de vínculo ao iFood → {codigo, link, expira_em_segundos};
 * - POST lojas/{id}/ifood/vincular: {authorizationCode} troca o código de autorização pelos tokens → {loja} ou, com
 *   várias lojas na conta do iFood, {escolher: [{id, nome}]}; {merchant_id} conclui a escolha → {loja};
 * - DELETE lojas/{id}/ifood: desvincula → {loja}.
 * Com a integração desligada (ENTREGAS_IFOOD), o código e o vínculo respondem 409; desvincular funciona sempre (não
 * chama o iFood). A {loja} é a do LojasController::formatar, com o bloco `ifood` (sem tokens).
 */
class IfoodLojasController extends LojasController
{
    public function codigo(Request $request, VinculosIfood $vinculos, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request) ?? $this->negarSeDesligada()) {
            return $erro;
        }
        $vendor = $this->acharLoja($id);

        try {
            return response()->json($vinculos->iniciar($vendor->uuid));
        } catch (ErroIfood $e) {
            return $this->erroDoIfood($e, 'código de vínculo');
        }
    }

    public function vincular(Request $request, VinculosIfood $vinculos, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request) ?? $this->negarSeDesligada()) {
            return $erro;
        }
        $vendor = $this->acharLoja($id);
        $dados  = $request->validate([
            'authorizationCode' => ['nullable', 'string', 'max:500'],
            'merchant_id'       => ['nullable', 'string', 'max:64'],
        ]);
        $merchant = trim((string) ($dados['merchant_id'] ?? ''));
        $codigo   = trim((string) ($dados['authorizationCode'] ?? ''));

        try {
            if ($merchant !== '') {
                $resultado = $vinculos->escolher(session('company'), $vendor->uuid, $merchant);
            } elseif ($codigo !== '') {
                $resultado = $vinculos->concluir(session('company'), $vendor->uuid, $codigo);
            } else {
                return response()->json(['errors' => ['Cole o código de autorização que o iFood mostrou ao dono da loja.']], 422);
            }
        } catch (ErroDeVinculo $e) {
            return response()->json(['errors' => [$e->getMessage()]], 422);
        } catch (ErroIfood $e) {
            return $this->erroDoIfood($e, 'vínculo');
        }

        if ($resultado['situacao'] === 'escolher') {
            return response()->json(['escolher' => $resultado['lojas']]);
        }

        return response()->json(['loja' => $this->formatar($vendor->refresh())]);
    }

    public function desvincular(Request $request, VinculosIfood $vinculos, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $vendor = $this->acharLoja($id);
        $vinculos->desvincular($vendor->uuid);

        return response()->json(['loja' => $this->formatar($vendor->refresh())]);
    }

    protected function negarSeDesligada()
    {
        return ClienteIfood::ligada() ? null : response()->json(['errors' => ['Integração iFood desligada.']], 409);
    }

    protected function erroDoIfood(ErroIfood $e, string $etapa)
    {
        Log::warning('[entregas] ifood: vínculo falhou', ['etapa' => $etapa, 'status' => $e->status]);

        // 400/401/403 na troca: código errado, já usado, vencido ou de outro aplicativo
        if (in_array($e->status, [400, 401, 403], true)) {
            return response()->json(['errors' => ['O iFood recusou o código. Confira o código de autorização ou gere um código de vínculo novo.']], 422);
        }

        return response()->json(['errors' => ['O iFood não respondeu agora. Tente de novo em instantes.']], 502);
    }
}
```

- [ ] **Step 4: bloco `ifood` no `LojasController`**

Em `api/app/Http/Controllers/Entregas/LojasController.php`:

1. Nos `use`, logo depois de `use App\Http\Controllers\Controller;`:

```php
use App\Support\Entregas\Ifood\ClienteIfood;
use App\Support\Entregas\Ifood\VinculosIfood;
```

2. Depois de `public const TIPO_LOJA = 'customer';`:

```php

    /** Situação do iFood das lojas da listagem, lida de uma vez no index (uuid do Vendor => resumo). */
    protected array $resumosIfood = [];
```

3. No `index()`, troque:

```php
        $lojas->loadMissing('vendorPersonnel.contact.anyUser');

        return response()->json(['lojas' => $lojas->map(fn ($vendor) => $this->formatar($vendor))->values()]);
```

por:

```php
        $lojas->loadMissing('vendorPersonnel.contact.anyUser');
        // o vínculo com o iFood de todas as lojas, de uma vez
        $this->resumosIfood = VinculosIfood::resumos($lojas->pluck('uuid')->all());

        return response()->json([
            'lojas'        => $lojas->map(fn ($vendor) => $this->formatar($vendor))->values(),
            // a tela só mostra "Vincular iFood" com a integração ligada (ENTREGAS_IFOOD)
            'ifood_ligado' => ClienteIfood::ligada(),
        ]);
```

4. No fim do array do `formatar()`, troque:

```php
                'ativo'    => $m->status === 'active' && $m->contact->anyUser?->status === 'active',
            ])->values(),
        ];
    }
```

por:

```php
                'ativo'    => $m->status === 'active' && $m->contact->anyUser?->status === 'active',
            ])->values(),
            // vínculo com o iFood: situacao (vinculada | vinculo_perdido | null), nome da loja no iFood e merchant_id; nunca os tokens
            'ifood'    => $this->resumoIfood($vendor),
        ];
    }

    protected function resumoIfood(Vendor $vendor): array
    {
        return $this->resumosIfood[$vendor->uuid] ?? VinculosIfood::resumo($vendor->uuid);
    }
```

- [ ] **Step 5: rotas**

Em `api/app/Providers/RouteServiceProvider.php`:

1. Nos `use`, entre `ConversasDaLojaController` e `LojasController`:

```php
use App\Http\Controllers\Entregas\IfoodLojasController;
```

2. Logo depois da linha `Route::put('lojas/{id}/usuarios/{contato}/ativo', [LojasController::class, 'alterarAcesso']);`:

```php
                        // vínculo da loja com o iFood: código de vínculo, troca do código de autorização (ou escolha da loja) e desvínculo
                        Route::post('lojas/{id}/ifood/codigo', [IfoodLojasController::class, 'codigo'])->middleware('throttle:20,1');
                        Route::post('lojas/{id}/ifood/vincular', [IfoodLojasController::class, 'vincular'])->middleware('throttle:20,1');
                        Route::delete('lojas/{id}/ifood', [IfoodLojasController::class, 'desvincular']);
```

- [ ] **Step 6: rodar e ver passar**

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/ifood-lojas.php`
Expected: `FALHAS: 0`.

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Http/Controllers/Entregas/IfoodLojasController.php api/app/Http/Controllers/Entregas/LojasController.php api/app/Providers/RouteServiceProvider.php`
Expected: `OK` nos três.

- [ ] **Step 7: commit**

```bash
git rev-parse --show-toplevel
git add api/app/Http/Controllers/Entregas/IfoodLojasController.php api/app/Http/Controllers/Entregas/LojasController.php api/app/Providers/RouteServiceProvider.php scripts/teste-php/ifood-lojas.php
git commit -m "iFood: rotas do vínculo na tela Lojas e situação do iFood em cada loja

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 12: bateria completa e documentação

**Files:**
- Modify: `CLAUDE.md`
- Modify: `docs/superpowers/specs/2026-10-05-integracao-ifood-logistics-design.md`

- [ ] **Step 1: todos os testes de PHP**

Run:
```bash
for t in scripts/teste-php/*.php; do case "$t" in */stubs*|*/fixtures*) continue;; esac; printf "%s: " "$t"; PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs "$t" 2>&1 | tail -1; done
```
Expected: `FALHAS: 0` em todos (os antigos não usam nada do iFood; o `mapa.php` define um `LojasController` próprio).

Run: `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/Ifood/*.php api/app/Jobs/Entregas/ProcessarPedidoIfood.php api/app/Console/Commands/Entregas/*.php api/app/Console/Kernel.php api/app/Http/Controllers/Entregas/IfoodLojasController.php api/app/Http/Controllers/Entregas/LojasController.php api/app/Providers/RouteServiceProvider.php api/config/services.php api/database/migrations/2026_10_05_*.php`
Expected: `OK` em todos.

- [ ] **Step 2: documentar no `CLAUDE.md`**

Logo antes da seção `## Marca Entregas RestaurantePro (sem Fleetbase na tela)`, acrescente:

```markdown
## Integração iFood (etapa 2: entrada dos pedidos)

Pedidos iFood dos restaurantes clientes entram pelo módulo **Logistics** da Merchant API (app **distribuído**; entrega
própria, `deliveredBy: MERCHANT`). Desenho: `docs/superpowers/specs/2026-10-05-integracao-ifood-logistics-design.md`;
referência da API: `docs/ifood/referencia-logistics.md` ("Descobertas da sonda" vale mais que o resto); planos
`docs/superpowers/plans/2026-10-05-ifood-etapa-2a-servidor.md` e `-2b-tela-lojas.md`. As etapas 3 (ações de logística,
GPS, cancelamento) e 4 (APK e console) ainda não existem.

- **Interruptor:** `ENTREGAS_IFOOD=1` + `IFOOD_CLIENT_ID`/`IFOOD_CLIENT_SECRET` (do app distribuído) no `stack.env`
  (`config('services.ifood')`). Desligada, os comandos saem sem fazer nada, a tela Lojas não mostra "Vincular iFood" e
  as rotas de vínculo respondem 409. Mudou? Update the stack e `docker service update --force` na fila e no scheduler.
- **Código** em `api/app/Support/Entregas/Ifood/`: `ClienteIfood` (única porta HTTP; erros tipados `ErroIfood`),
  `VinculosIfood` (tabela `entregas_ifood_lojas`, tokens cifrados com `encrypt()`), `EventosIfood` e `PedidoDoIfood`
  (funções puras), `CriadorDoPedidoIfood` (models do Fleet-Ops). Job `App\Jobs\Entregas\ProcessarPedidoIfood`.
- **Vínculo (tela Lojas, só admin):** `POST int/v1/entregas/lojas/{id}/ifood/codigo` (userCode; verificador no cache,
  cifrado, 10 min), `POST .../ifood/vincular` (`authorizationCode`; com várias lojas na conta, devolve `escolher` e a
  central manda `merchant_id`), `DELETE .../ifood`. Um merchant, uma loja. **Coleta = Local da Loja**, não o endereço
  do iFood (a mais de 300 m dele, log `[entregas] ifood: coleta diverge do iFood`).
- **Tokens:** renovados quando vencem em < 5 min (antes de usar), pelo `entregas:ifood-tokens` (a cada 30 min, os que
  vencem em < 1 h) e no 401 (renova e repete uma vez), com trava por loja. Refresh recusado = `vinculo_perdido`
  (selo vermelho na tela, log `[entregas] ifood: vínculo perdido`, loja fora do polling até novo vínculo). **A
  conferir no primeiro vínculo real:** o nome do campo do refresh token (tratamos `refreshToken`/`refresh_token`; sem
  ele, o log `resposta do token sem refresh token` lista os campos que vieram).
- **Polling:** `entregas:ifood-polling` a cada 30 s (`excludeHeartbeat=true`, `x-polling-merchants` até 100). Grava em
  `entregas_ifood_eventos` (`evento_id` único) e **só depois** manda o ack de todos os recebidos; banco fora = sem ack
  (o iFood reenvia). 429 pausa pelo `Retry-After`; 403 com `unauthorizedMerchants` = vínculo perdido. Eventos
  processados há mais de 7 dias são apagados uma vez por dia.
- **Processamento:** um `ProcessarPedidoIfood` por pedido (trava `entregas:ifood-pedido:<id>`), eventos em ordem de
  `createdAt`. **O pedido nasce no PLC** (ou no primeiro evento conhecido, se o PLC se perdeu; nunca no CAN): busca
  `GET logistics/orders/{id}` e cria Place da entrega (coordenadas do iFood, sem dono), Payload (coleta = Local da
  loja) e Order (cliente = Vendor da loja, `transport`, adhoc, `internal_id` = número do iFood, notas "iFood #4821"), com a
  linha de `entregas_ifood_pedidos` na mesma transação (`pedido_ifood_id` único: nunca dois pedidos). Despacho como o
  do portal. DDCR marca `exige_codigo`; **CAN só registra `cancelado_pelo_ifood_em`** (o cancelamento de verdade é da
  etapa 3: até lá, cancele à mão no console). Código desconhecido e loja não vinculada ficam `ignorado`.
- **Pedido de teste** (`isTest`, entrega em 0,0): "[TESTE]" nas notas, entrega ~1 km ao norte da loja e **sem aviso
  aos motoboys** (a central atribui). **Agendado:** `scheduled_at` = início da janela − 40 min e despacho pelo
  `entregas:ifood-agendados` (a cada minuto; também refaz despacho que falhou). **Cobrança:** sem `payments` = pago
  online; `payments.pending` vira `cobrar_centavos`, forma e troco (formato a conferir na homologação).
- **Logs** `[entregas] ifood:` só com ids e número do pedido. Scheduler: `docker service logs entregas_scheduler | grep
  '\[entregas\] ifood'` (os comandos agendados escrevem em `/proc/1/fd/1`); fila: `docker service logs entregas_queue`.
- **Testes:** `scripts/teste-php/ifood-*.php` (stubs próprios `stubs-ifood.php` e `stubs-ifood-fleetbase.php`, pedidos
  fictícios em `fixtures-ifood.php`).
- **Ao atualizar o Laravel ou o fleetops-api:** confira `everyThirtySeconds` e `appendOutputTo` no agendador, e os
  métodos do Order que o `CriadorDoPedidoIfood` usa (`firstDispatchWithActivity`, `insertDispatchActivity`,
  `hasDispatchedStatus`, `saveQuietly`) e o `OrderConfig::default()`.
```

Na seção "Escopo enxuto", no item **Cobrança das lojas**, no fim do subitem que começa com "**A integração iFood manda,
em cada pedido (`POST v1/orders`)**", acrescente: " Substituído em 2026-10-05 pela integração iFood dentro da `api/app`
(ver "Integração iFood"): os pedidos do iFood entram com a loja como cliente e o Local dela como coleta."

- [ ] **Step 3: situação na spec**

Em `docs/superpowers/specs/2026-10-05-integracao-ifood-logistics-design.md`, na linha 3, troque
"etapa 1 implementada;" por "etapas 1 e 2 implementadas (etapa 2: planos `2026-10-05-ifood-etapa-2a-servidor.md` e
`2026-10-05-ifood-etapa-2b-tela-lojas.md`);".

- [ ] **Step 4: commit**

```bash
git rev-parse --show-toplevel
git add CLAUDE.md docs/superpowers/specs/2026-10-05-integracao-ifood-logistics-design.md
git commit -m "CLAUDE.md: integração iFood, etapa 2 (vínculo, polling e criação do pedido)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 13: verificação em produção (com o Edgard, depois do 2B)

**Files:** nenhum (só conferência). Roda depois do plano 2B, porque o vínculo é feito pela tela Lojas. O Claude não tem
SSH: os comandos na VPS são do Edgard.

- [ ] **Step 1: preparar (Edgard)**

1. Pare a sonda (`scripts/ifood-sonda.mjs`), se estiver rodando no PC.
2. No `deploy/stack.env` do PC: `IFOOD_CLIENT_ID` e `IFOOD_CLIENT_SECRET` do app **distribuído** "Teste (D)" (Portal
   do Desenvolvedor → Meus Apps → Credenciais) e `ENTREGAS_IFOOD=1`.
3. `git push` (com a autorização dele) e, na VPS: `cd ~/entregas && bash deploy/atualizar.sh` (API + console; o
   `deploy.sh` roda as migrations novas).
4. Portainer → Stacks → entregas → Editor → cole as variáveis novas → Update the stack ("Re-pull image" desligado).
   Depois: `docker service update --force entregas_queue && docker service update --force entregas_scheduler`.

- [ ] **Step 2: conferir a instalação (Edgard)**

```bash
docker exec $(docker ps -q -f name=entregas_application) printenv ENTREGAS_IFOOD
docker exec $(docker ps -q -f name=entregas_application) php artisan migrate:status | grep ifood
docker exec $(docker ps -q -f name=entregas_scheduler) php artisan schedule:list | grep ifood
```
Expected: `1`; as três migrations `Ran`; `entregas:ifood-polling` a cada 30 s, `entregas:ifood-agendados` a cada minuto
e `entregas:ifood-tokens` a cada 30 min. Se o `schedule:list` reclamar de `everyThirtySeconds`, o Laravel da imagem é
anterior ao 10.15: pare e avise (troque por `everyMinute()` e reabra a decisão).

- [ ] **Step 3: vincular a loja de teste**

1. Console (Ctrl+Shift+R) → Fleet-Ops → Recursos → Lojas → **Terraço Pizza Bar** (loja só de testes) → "Vincular iFood".
2. Abra o link com o código no Portal do Parceiro com o login da loja de teste do iFood (merchant `4173843`) e
   autorize; cole o código de autorização no modal.
3. Expected: selo "Vinculada · <nome da loja de teste no iFood>". No log da API:
   `docker service logs entregas_application 2>&1 | grep '\[entregas\] ifood'` → "loja vinculada". **Se aparecer
   "resposta do token sem refresh token", anote os `campos` do log** e acrescente o nome em
   `VinculosIfood::refreshDaResposta` (com teste) antes de seguir.
4. No banco: `docker exec -it $(docker ps -q -f name=entregas_database) mysql -uroot -p fleetbase -e "select vendor_uuid, merchant_id, situacao, expira_em, refresh_token is not null as tem_refresh from entregas_ifood_lojas;"`
   Expected: `merchant_id` = `d5d191fa-2e43-4b86-aa9c-9f8c8b251378`, `vinculada`, `tem_refresh` = 1.

- [ ] **Step 4: pedido de teste**

1. Gere um pedido de teste para a loja de teste (Portal do Desenvolvedor → pedido de teste).
2. Em até ~1 min: `docker service logs entregas_queue 2>&1 | grep '\[entregas\] ifood'` → "pedido criado" com o
   número; nenhum "coleta diverge" (pedido de teste não confere).
3. No console → Pedidos: o pedido com o número do iFood, notas "iFood #NNNN [TESTE]", cliente Terraço Pizza Bar,
   coleta no Local da loja, entrega ~1 km ao norte, status criado e **sem alarme nos celulares dos motoboys**.
4. `docker exec -it $(docker ps -q -f name=entregas_database) mysql -uroot -p fleetbase -e "select codigo, processado_em, ignorado from entregas_ifood_eventos order by id desc limit 10; select numero, teste, exige_codigo, cobrar_centavos, despachar_em from entregas_ifood_pedidos order by id desc limit 3;"`
   Expected: PLC (e, ~2 min depois, CFM e DDCR) processados e não ignorados; `teste` = 1, `exige_codigo` = 1 depois do
   DDCR, `cobrar_centavos` = 0 (pago online), `despachar_em` nulo.
5. No Gestor de Pedidos do iFood, a loja de teste **não** deve abrir sozinha (o `excludeHeartbeat=true` está no
   polling).
6. Cancele o pedido de teste no iFood (ou deixe o ambiente de teste cancelar). Expected: em ~30 s,
   `cancelado_pelo_ifood_em` preenchido e o log "pedido cancelado pelo iFood". O pedido continua no console (o
   cancelamento é da etapa 3): cancele à mão.

- [ ] **Step 5: logs do scheduler e desvínculo**

1. `docker service logs --since 10m entregas_scheduler 2>&1 | grep '\[entregas\] ifood'`: sem "polling falhou" repetido.
   (Se nada aparecer em 10 min sem pedidos, é normal: o polling sem eventos não loga.)
2. Tela Lojas → "Desvincular iFood" → confirma. Expected: selo "—" e, no banco, `situacao = desvinculada` sem tokens.
   Vincule de novo para deixar a loja de teste pronta para a etapa 3.
3. Decida com o Edgard se `ENTREGAS_IFOOD` fica `1` (só a loja de teste está vinculada; nenhum restaurante real entra
   antes da etapa 4 e da homologação).

- [ ] **Step 6: como saber que o polling roda (Edgard)**

Acrescentado na revisão final da branch: o polling sem eventos não loga, então "nada no log" não prova que ele roda.

1. Agendamento: `docker exec $(docker ps -q -f name=entregas_scheduler) php artisan schedule:list | grep ifood`
   Expected: os três comandos `entregas:ifood-*` com o próximo horário (polling a cada 30 s).
2. Depois da primeira rodada do polling (~1 min depois de ligar), a marca da limpeza diária dos eventos
   (`PollingIfood::CHAVE_LIMPEZA`) fica no cache:
   `docker exec $(docker ps -q -f name=entregas_scheduler) php artisan tinker --execute="dump(cache('entregas:ifood-eventos-limpeza'))"`
   Expected: `true`. `null` depois de alguns minutos = o polling não rodou ou a limpeza falhou: veja
   `docker service logs --since 10m entregas_scheduler 2>&1 | grep '\[entregas\] ifood'` ("limpeza dos eventos antigos
   falhou", "polling falhou") e a trava do `withoutOverlapping` (seção "Armadilhas" do `CLAUDE.md`).
3. **Não rode `php artisan entregas:ifood-polling` à mão para testar:** o comando à mão ignora o `withoutOverlapping`
   (a trava é do agendamento no `Kernel`, não do comando) e corre junto com a rodada agendada, com chamadas e acks em
   dobro ao iFood. Se precisar, só para diagnóstico e com o scheduler parado.
4. Depois do "Desvincular iFood" (Step 5), os tokens são apagados: para a loja voltar, é preciso um **vínculo novo
   completo**, com código novo na tela Lojas e nova autorização do dono no Portal do Parceiro. Não há como reativar o
   vínculo desvinculado.
