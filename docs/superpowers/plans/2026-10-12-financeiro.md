# Financeiro — plano de implementação

> **Para agentes:** SUB-SKILL OBRIGATÓRIA: use superpowers:subagent-driven-development (recomendado) ou superpowers:executing-plans. Passos com checkbox (`- [ ]`).
> Cada agente recebe UMA tarefa deste plano, trabalha numa worktree própria (ramo `fin-<tarefa>`) e só toca os arquivos da tarefa.
> O **contrato** (seção 1) é lei: nomes de tabela, coluna, classe, método, rota e campo JSON não mudam sem voltar aqui.

**Objetivo:** controlar o financeiro da operadora de entregas: extrato e pagamento dos motoboys (diária, bônus, ajuste,
adiantamento), faturas das lojas (ciclo, rascunho, emissão, recebimento), painel, portal da loja, e-mails e app.

**Desenho:** `docs/superpowers/specs/2026-10-12-financeiro-design.md` (ler antes de qualquer tarefa).

**Arquitetura:** livro de lançamentos próprio na `api/app` (tabelas `entregas_*`, `DB::table`, sem Models), serviços em
`api/app/Support/Entregas/Financeiro/`, controllers em `api/app/Http/Controllers/Entregas/`. Console (engine Fleet-Ops,
`packages/fleetops`), portal (`packages/customer-portal`) e app (`entregas-navigator`) consomem as rotas do contrato.

**Stack:** Laravel 10 (PHP 8.2), Ember (engines Fleetbase), React Native (app). Testes: `scripts/teste-php` (php-wasm),
`scripts/teste-portal` (`node --test`), app `node --experimental-strip-types --test`.

---

## 0. Regras para todos os agentes

- Leia o `CLAUDE.md` da raiz e o da pasta que vai mexer (`api/CLAUDE.md`, `packages/fleetops/CLAUDE.md`,
  `packages/customer-portal/CLAUDE.md`, `packages/CLAUDE.md`, `api/app/Notifications/Entregas/Email/CLAUDE.md`,
  `api/app/Http/Controllers/Entregas/CLAUDE.md`). Tudo em pt-BR (código de domínio, comentários, textos).
- **Fuso:** servidor em Brasília. Nunca formate data em UTC para gravar ou consultar. `data_referencia` é o dia em
  `America/Sao_Paulo` (fuso da Company). Datas gravadas com `now()->format('Y-m-d H:i:s')`.
- **Sem Models** para `entregas_*`: `DB::table`. Decimais voltam do MySQL como texto (`(float)`), booleanos como 0/1.
- **Setting:** em comando/job não há sessão. Leia com `Setting::lookup('company.<uuid>.entregas.<chave>', $padrao)`;
  antes de usar o `CalculoEntregas` (que lê as faixas pela sessão) faça `session(['company' => $companyUuid])`.
- **Env:** nunca `env()` no código; a variável vai em `config/services.php` (`services.entregas.financeiro`),
  `deploy/docker-stack.yml` (`x-api-env`) e `deploy/stack.env.example`.
- **Rotas:** `use` de controller faltando no `RouteServiceProvider` derruba a API (o teste
  `scripts/teste-php/imports-dos-providers.php` confere).
- **Erros JSON:** `response()->json(['errors' => ['mensagem pt-BR']], status)`.
- **Tradução (front):** `node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal`
  com exit 0; chaves novas em en-us **e** pt-br; nunca em `common.*`. JS alterado tem de parsear com `@babel/parser`.
- **PHP:** `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs <arquivos>` sem ERRO, e os testes da tarefa com
  `PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/<teste>.php` terminando em `FALHAS: 0`.
- **Não edite nenhum `CLAUDE.md` nem `docs/historico.md` na worktree** (vários estão fora do git no checkout principal e
  dariam conflito). Leia-os pelo caminho absoluto do checkout principal
  (`C:UsersEdgardjrDocumentsibe codingDelivery...`). O que precisar ir para a documentação, escreva em
  `docs/superpowers/plans/notas/<tarefa>.md`; a integração passa para os CLAUDE.md.
- Commits pequenos, mensagem em pt-BR, terminando com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
  **Nunca** push, nunca commitar `deploy/stack.env`, `api/.env`, `console/dist`.

---

## 1. Contrato

### 1.1 Migration única (todas as tabelas, já na tarefa B1)

Arquivo: `api/database/migrations/2026_10_13_100000_create_entregas_financeiro_tables.php` (o nome contém
`_entregas_financeiro_` porque o banco em memória dos testes, `scripts/teste-php/stubs-ifood.php`, lê o esquema por glob;
a tarefa B1 acrescenta esse glob).

```php
Schema::create('entregas_lancamentos', function (Blueprint $table) {
    $table->id();
    $table->char('uuid', 36)->unique();
    $table->char('company_uuid', 36);
    $table->string('conta_tipo', 10);            // motoboy | loja
    $table->char('conta_uuid', 36);              // drivers.uuid | vendors.uuid
    $table->string('tipo', 16);                  // corrida diaria bonus ajuste saldo_inicial pagamento recebimento estorno
    $table->decimal('valor', 10, 2);             // com sinal: crédito > 0, débito < 0
    $table->date('data_referencia');             // dia em Brasília
    $table->char('order_uuid', 36)->nullable();
    $table->char('pagamento_uuid', 36)->nullable();
    $table->char('fatura_uuid', 36)->nullable(); // só conta loja
    $table->unsignedBigInteger('estorno_de')->nullable(); // id do lançamento estornado
    $table->string('motivo', 255)->nullable();            // visível ao motoboy / à loja
    $table->string('observacao_interna', 500)->nullable(); // nunca sai para app/portal
    $table->json('detalhes')->nullable();        // corrida: {pedido, numero, km, faixa:{de_km,ate_km,acima}, loja_nome, destino, cancelado_pago, cancelado_origem}
    $table->string('chave_unica', 80)->nullable()->unique(); // corrida:<conta_tipo>:<order_uuid> | estorno:<id>
    $table->char('criado_por', 36)->nullable();  // users.uuid; null = sistema
    $table->timestamps();
    $table->index(['company_uuid', 'conta_tipo', 'conta_uuid', 'data_referencia'], 'ent_lanc_conta_data');
    $table->index('fatura_uuid');
    $table->index('order_uuid');
});

Schema::create('entregas_pagamentos', function (Blueprint $table) {
    $table->id();
    $table->char('uuid', 36)->unique();
    $table->char('company_uuid', 36)->index();
    $table->string('conta_tipo', 10);
    $table->char('conta_uuid', 36);
    $table->decimal('valor', 10, 2);             // positivo
    $table->string('forma', 16);                 // pix dinheiro transferencia outro
    $table->date('data');
    $table->string('comprovante_path', 255)->nullable(); // disco 'local' (privado)
    $table->string('comprovante_nome', 255)->nullable();
    $table->string('comprovante_tipo', 100)->nullable(); // mime
    $table->string('observacao_interna', 500)->nullable();
    $table->timestamp('estornado_em')->nullable();
    $table->char('estornado_por', 36)->nullable();
    $table->char('criado_por', 36)->nullable();
    $table->timestamps();
    $table->index(['company_uuid', 'conta_tipo', 'conta_uuid'], 'ent_pag_conta');
});

Schema::create('entregas_faturas', function (Blueprint $table) {
    $table->id();
    $table->char('uuid', 36)->unique();
    $table->char('company_uuid', 36);
    $table->char('vendor_uuid', 36);
    $table->unsignedInteger('numero')->nullable(); // sequencial por company, na emissão; exibido FAT-0001
    $table->date('periodo_inicio');
    $table->date('periodo_fim');
    $table->date('vencimento');
    $table->string('situacao', 12);              // rascunho emitida cancelada
    $table->decimal('total', 10, 2)->nullable(); // gravado na emissão
    $table->timestamp('emitida_em')->nullable();
    $table->char('emitida_por', 36)->nullable();
    $table->timestamp('cancelada_em')->nullable();
    $table->char('cancelada_por', 36)->nullable();
    $table->timestamp('email_enviado_em')->nullable();
    $table->timestamp('lembrete_enviado_em')->nullable();
    $table->timestamp('aviso_vencida_enviado_em')->nullable();
    $table->timestamps();
    $table->index(['company_uuid', 'situacao'], 'ent_fat_situacao');
    $table->index(['vendor_uuid', 'periodo_fim'], 'ent_fat_loja_fim');
    $table->unique(['company_uuid', 'numero'], 'ent_fat_numero');
});

Schema::create('entregas_cobranca_lojas', function (Blueprint $table) {
    $table->id();
    $table->char('company_uuid', 36)->index();
    $table->char('vendor_uuid', 36)->unique();
    $table->string('ciclo', 10);                 // semanal quinzenal mensal
    $table->unsignedTinyInteger('dia_fechamento'); // semanal: 0=dom..6=sáb; mensal: 1..28 ou 0=último dia; quinzenal: ignorado (0)
    $table->unsignedSmallInteger('prazo_dias');
    $table->timestamps();
});

Schema::create('entregas_motoboys_financeiro', function (Blueprint $table) {
    $table->id();
    $table->char('company_uuid', 36)->index();
    $table->char('driver_uuid', 36)->unique();
    $table->string('chave_pix', 140)->nullable();
    $table->string('tipo_chave', 12)->nullable(); // cpf cnpj telefone email aleatoria
    $table->timestamps();
});
```

`down()` derruba as cinco. Docblock em pt-BR explicando que fica fora do `meta` (sai na API v1 e no socket).

### 1.2 Configuração

- `config/services.php` → bloco `entregas`: `'financeiro' => env('ENTREGAS_FINANCEIRO')` (1 liga lançamento automático,
  rotinas e e-mails). Stack: `ENTREGAS_FINANCEIRO: ${ENTREGAS_FINANCEIRO:-}` e linha comentada no `stack.env.example`.
- Settings por company: `company.<uuid>.entregas.financeiro_corte` (`'Y-m-d'` ou ausente = nada é lançado
  automaticamente nessa company) e `company.<uuid>.entregas.cobranca_padrao` (`{ciclo, dia_fechamento, prazo_dias}`,
  padrão `{ciclo:'semanal', dia_fechamento:0, prazo_dias:3}`).

### 1.3 Classes PHP (namespace `App\Support\Entregas\Financeiro`)

```php
final class Financeiro {
    public const CONTA_MOTOBOY = 'motoboy'; public const CONTA_LOJA = 'loja';
    public const TIPOS_MANUAIS_MOTOBOY = ['diaria', 'bonus', 'ajuste', 'saldo_inicial'];
    public const TIPOS_MANUAIS_LOJA    = ['ajuste', 'saldo_inicial'];
    public const FORMAS = ['pix', 'dinheiro', 'transferencia', 'outro'];
    public const TIPOS_CHAVE_PIX = ['cpf', 'cnpj', 'telefone', 'email', 'aleatoria'];
    public static function ligada(): bool;                         // filter_var(config('services.entregas.financeiro'), FILTER_VALIDATE_BOOLEAN)
    public static function corte(string $companyUuid): ?string;     // Setting::lookup(...financeiro_corte)
    public static function cobrancaPadrao(string $companyUuid): array; // com o padrão acima
    public static function fuso(string $companyUuid): string;       // Company timezone ?: 'America/Sao_Paulo'
    public static function hoje(string $companyUuid): string;       // 'Y-m-d' no fuso
    public static function numeroDaFatura(?int $numero): ?string;   // 12 → 'FAT-0012'
}

final class Lancamentos {   // repositório, DB::table('entregas_lancamentos')
    public const TABELA = 'entregas_lancamentos';
    /** Insere e devolve o id; com chave_unica repetida devolve null sem erro (insertOrIgnore). Gera uuid. */
    public static function inserir(array $valores): ?int;
    public static function porId(string $companyUuid, int $id): ?object;
    public static function porUuid(string $companyUuid, string $uuid): ?object;
    /** Todos os lançamentos da conta, ordenados por data_referencia, id (a quitação precisa de todos). */
    public static function daConta(string $companyUuid, string $contaTipo, string $contaUuid): array;
    /** saldo de cada conta do tipo: [conta_uuid => float] */
    public static function saldos(string $companyUuid, string $contaTipo): array;
    /** Cria o estorno (valor oposto, estorno_de, chave_unica 'estorno:<id>'); null se já estornado ou se for estorno. */
    public static function estornar(object $lancamento, ?string $usuarioUuid, ?string $motivo): ?int;
    public static function jaEstornados(array $ids): array; // ids que têm estorno
}

final class Quitacao {      // funções puras, sem banco
    /**
     * Situação de cada crédito da conta (motoboy ou loja sem fatura). Entrada: lançamentos (objetos ou arrays com
     * id, tipo, valor, data_referencia, order_uuid, estorno_de), em qualquer ordem.
     * Saída: [id => ['situacao' => 'pago'|'parcial'|'aberto'|'anulado', 'pago' => float, 'restante' => float]] só para créditos.
     * Regras (desenho, "Quitação por antiguidade"):
     *  1. crédito = valor > 0 e tipo != 'estorno'.
     *  2. débito com estorno_de apontando para crédito abate esse crédito; ajuste negativo com order_uuid abate a corrida
     *     do mesmo pedido (até o que ela tem); o que sobra vai para o "caixa".
     *  3. os demais débitos (pagamento, recebimento, ajuste sem pedido, saldo_inicial negativo) vão para o caixa;
     *     estorno positivo (de pagamento/recebimento) tira do caixa; caixa nunca < 0.
     *  4. créditos ordenados por (data_referencia, id); restante = valor - abatido; abatido == valor → 'anulado';
     *     o caixa quita do mais antigo: tudo → 'pago', parte → 'parcial', nada → 'aberto'.
     */
    public static function creditos(array $lancamentos): array;
    /**
     * Situação das faturas emitidas de uma loja. $faturas: [{uuid, total, vencimento}] (só emitidas);
     * $recebido: soma positiva dos recebimentos menos estornos de recebimento.
     * Saída: [uuid => ['pagamento' => 'aberta'|'parcial'|'paga', 'recebido' => float, 'em_aberto' => float, 'vencida' => bool]]
     * Quita por vencimento (depois numero); vencida = em_aberto > 0 e vencimento < $hoje.
     */
    public static function faturas(array $faturas, float $recebido, string $hoje): array;
}

final class LancadorDeCorridas {
    public function __construct(?CalculoEntregas $calculo = null);
    /**
     * Lança as corridas dos pedidos (Collection vinda de CalculoEntregas::pedidosConcluidos) da company: um lançamento
     * 'corrida' por pedido e conta (motoboy: valor_motoboy; loja: valor_loja quando há Vendor). Pula pedido sem valor,
     * com data < corte, ou já lançado (chave_unica). Devolve ['lancados' => int, 'sem_valor' => int].
     */
    public function lancar(string $companyUuid, Collection $pedidos): array;
    /** Pedidos concluídos (ou cancelados pagos) de $inicio até agora sem lançamento de corrida do motoboy. */
    public function conferir(string $companyUuid, Carbon $inicio): array;
    /** Corridas lançadas cujo pedido deixou de ser pagável → estorno automático, motivo 'Pedido cancelado após a conclusão'. */
    public function estornarCancelados(string $companyUuid, Carbon $desde): int;
    public function lancarPedido(string $orderUuid): void; // usado pelo job do observador
}

final class Pagamentos {
    /**
     * Registra pagamento (conta motoboy) ou recebimento (conta loja): linha em entregas_pagamentos + lançamento
     * 'pagamento'|'recebimento' com valor negativo, na mesma transação. $comprovante opcional (UploadedFile, imagem ou PDF,
     * até 10 MB) salvo no disco 'local' em entregas/comprovantes/<company>/<uuid>.<ext>. Devolve o objeto do pagamento.
     */
    public static function registrar(string $companyUuid, string $contaTipo, string $contaUuid, float $valor, string $forma,
        string $data, ?UploadedFile $comprovante, ?string $observacao, ?string $usuarioUuid): object;
    public static function porUuid(string $companyUuid, string $uuid): ?object;
    /** Marca estornado_em e cria o estorno do lançamento do pagamento. false se já estornado. */
    public static function estornar(object $pagamento, ?string $usuarioUuid): bool;
    /** URL assinada (30 min) de GET v1/entregas/comprovantes/{uuid}; null sem comprovante. */
    public static function urlDoComprovante(object $pagamento): ?string;
    public static function ultimoDaConta(string $companyUuid, string $contaTipo, string $contaUuid): ?object;
}

final class ExtratoDaConta {
    /**
     * Extrato pronto para o console (completo) ou para o app/portal ($publico = true: sem observacao_interna, sem
     * criado_por). Formato JSON "Extrato" (1.5). $inicio/$fim filtram a lista (a situação é calculada com a conta inteira).
     */
    public static function montar(string $companyUuid, string $contaTipo, string $contaUuid, ?string $inicio, ?string $fim,
        bool $publico, ?string $tipo = null): array;
    /** Um lançamento no formato JSON "Lancamento" (1.5). */
    public static function linha(object $lancamento, ?array $situacao, bool $publico, array $pagamentos, array $nomes): array;
}

final class CicloDeCobranca {   // funções puras
    /** O período que termina exatamente em $dia ('Y-m-d') pela config {ciclo, dia_fechamento}; null se $dia não fecha ciclo. */
    public static function periodoQueFechaEm(array $config, string $dia): ?array; // ['inicio' => 'Y-m-d', 'fim' => 'Y-m-d']
    /** Período em andamento que contém $dia (para "ciclo em andamento"). */
    public static function periodoQueContem(array $config, string $dia): array;
    public static function vencimento(array $config, string $fim): string; // fim + prazo_dias
    public static function validar(array $config): ?string; // mensagem de erro pt-BR ou null
}

final class Faturas {
    public const TABELA = 'entregas_faturas';
    public static function configDaLoja(string $companyUuid, string $vendorUuid): array; // linha ou padrão + ['padrao' => bool]
    public static function salvarConfig(string $companyUuid, string $vendorUuid, array $config): void;
    /** Rascunho com os lançamentos da loja sem fatura e data_referencia <= $fim (exceto recebimento/estorno de recebimento). null se não há lançamento ou se já existe rascunho/emitida com o mesmo periodo_fim. */
    public static function gerarRascunho(string $companyUuid, string $vendorUuid, string $inicio, string $fim, ?string $usuarioUuid): ?object;
    public static function porUuid(string $companyUuid, string $uuid): ?object;
    public static function itens(object $fatura): array;           // lançamentos ligados
    public static function emitir(object $fatura, ?string $usuarioUuid): object;   // numero (lockForUpdate), total
    public static function cancelar(object $fatura, ?string $usuarioUuid): void;    // libera os lançamentos
    public static function ajuste(object $fatura, float $valor, string $motivo, ?string $obs, ?string $usuarioUuid): int; // só rascunho
    public static function naoCobrar(object $fatura, object $lancamento, ?string $usuarioUuid): int; // ajuste negativo com order_uuid, na fatura
    public static function mudarVencimento(object $fatura, string $vencimento): void; // só rascunho
    /** Formato JSON "Fatura" (1.5) com a situação calculada. */
    public static function resumo(object $fatura, array $situacoes, ?string $lojaNome, bool $publico): array;
    /** [uuid => situação] de todas as emitidas da loja (Quitacao::faturas). */
    public static function situacoesDaLoja(string $companyUuid, string $vendorUuid): array;
}

final class PainelFinanceiro {
    public static function montar(string $companyUuid, string $inicio, string $fim): array; // JSON "Painel" (1.5)
}
```

Fora do namespace Financeiro:

- `App\Console\Commands\Entregas\FinanceiroConferir` → `entregas:financeiro-conferir` (a cada 5 min): para cada
  company com corte, `session(['company'=>…])`, `conferir(max(corte, hoje-7d))` e `estornarCancelados(hoje-30d)`.
- `App\Console\Commands\Entregas\FinanceiroLancarHistorico` → `entregas:financeiro-lancar-historico {--company=}`:
  lança do corte até agora, em janelas de 7 dias. Roda mesmo com a chave desligada (uso manual).
- `App\Console\Commands\Entregas\FinanceiroRascunhos` → `entregas:financeiro-rascunhos` (00:30): para cada loja com
  lançamento sem fatura, se ontem fecha o ciclo dela, `gerarRascunho`.
- `App\Console\Commands\Entregas\FinanceiroAvisos` → `entregas:financeiro-avisos` (09:00): lembrete (vence amanhã,
  em aberto, `lembrete_enviado_em` null) e vencida (venceu ontem ou antes, em aberto, `aviso_vencida_enviado_em` null).
- `App\Listeners\Entregas\ObservadorFinanceiroDosPedidos::aoAtualizar(object $pedido)`: com a chave ligada e
  `wasChanged('status')` para `completed`/`done` (ou cancelado), enfileira `App\Jobs\Entregas\LancarCorridaDoPedido`
  (`ShouldQueue`, recebe só o uuid, faz `session(['company'=>…])` e chama `lancarPedido`). Nunca lança exceção.
  Registro no `AppServiceProvider::boot()` (`lancarCorridasNoFinanceiro()`), no padrão dos outros observadores.
- `App\Notifications\Entregas\PagamentoRegistrado` (push FCM, `data.type = 'entregas_pagamento'`, canal `avisos`):
  título "Pagamento registrado", texto "Pagamento de R$ 150,00 registrado (Pix)." Registrar em `AvisosDoMotoboy::texto()`
  e `AvisosDoMotoboy::CANAIS`. `data` = `{type, pagamento: <uuid>}` (sem `id` de pedido).
- `App\Notifications\Entregas\Email\FaturaEmitida`, `LembreteDeFatura`, `FaturaVencida`: `toMail()` já em pt-BR
  (`MailMessage`, `Saudacao::para`, `<x-mail::dados>`), botão para o portal (`MarcaDoEmail::urlDeEntrada`/URL do portal
  `…/portal/financeiro/<uuid>`). Envio por `Notification::route('mail', $emails)`; `$emails` = e-mail do Vendor + e-mails
  dos usuários do portal da loja (contatos `customer` do `vendorPersonnel` ativo). `CanalEmailEntregas` deixa de logar
  "sem tradução" para classes do namespace `App\Notifications\Entregas\`.

### 1.4 Rotas (em `api/app/Providers/RouteServiceProvider.php`)

Console (grupo `int/v1/entregas`, `fleetbase.protected`; todos os métodos começam com `negarSeNaoAdmin`):

| Método e caminho | Controller@método | Tarefa |
|---|---|---|
| GET `financeiro/painel?inicio&fim` | `FinanceiroController@painel` | B2 |
| GET `financeiro/motoboys?inicio&fim` | `FinanceiroController@motoboys` | B2 |
| GET `financeiro/motoboys/{id}/extrato?inicio&fim&tipo` | `FinanceiroController@extratoDoMotoboy` | B2 |
| PUT `financeiro/motoboys/{id}/pix` | `FinanceiroController@salvarPix` | B2 |
| POST `financeiro/lancamentos` | `FinanceiroController@lancar` | B2 |
| POST `financeiro/lancamentos/{id}/estornar` | `FinanceiroController@estornarLancamento` | B2 |
| POST `financeiro/pagamentos` (multipart) | `FinanceiroController@registrarPagamento` | B2 |
| POST `financeiro/pagamentos/{id}/estornar` | `FinanceiroController@estornarPagamento` | B2 |
| GET `financeiro/configuracoes` / PUT idem | `FinanceiroController@configuracoes` / `salvarConfiguracoes` | B2 |
| GET `financeiro/lojas` | `FinanceiroLojasController@lojas` | B3 |
| PUT `financeiro/lojas/{id}/ciclo` | `FinanceiroLojasController@salvarCiclo` | B3 |
| GET `financeiro/lojas/{id}/extrato?inicio&fim` | `FinanceiroLojasController@extratoDaLoja` | B3 |
| GET `financeiro/faturas?situacao&loja` | `FinanceiroLojasController@faturas` | B3 |
| POST `financeiro/faturas` | `FinanceiroLojasController@gerarRascunho` | B3 |
| GET `financeiro/faturas/{id}` | `FinanceiroLojasController@fatura` | B3 |
| PUT `financeiro/faturas/{id}` | `FinanceiroLojasController@mudarVencimento` | B3 |
| POST `financeiro/faturas/{id}/ajustes` | `FinanceiroLojasController@ajuste` | B3 |
| POST `financeiro/faturas/{id}/nao-cobrar` | `FinanceiroLojasController@naoCobrar` | B3 |
| POST `financeiro/faturas/{id}/emitir` | `FinanceiroLojasController@emitir` | B3 |
| POST `financeiro/faturas/{id}/cancelar` | `FinanceiroLojasController@cancelar` | B3 |
| POST `financeiro/faturas/{id}/reenviar` | `FinanceiroLojasController@reenviar` (chama `AvisosDaFatura::emitida` se a classe existir) | B3 |

Portal da loja (mesmo grupo, `throttle:60,1`; passam no `ProtegerPortalLoja` por estarem em `entregas/loja/`):

| GET `loja/financeiro` | `PortalLojaController@financeiro` | B4 |
| GET `loja/faturas/{id}` | `PortalLojaController@fatura` | B4 |

App (grupo `v1/entregas/motoboy`, `fleetbase.api` + `throttle:entregas-motoboy`):

| GET `extrato?inicio&fim` | `MotoboyController@extrato` | B2 |
| GET `pagamentos/{id}` | `MotoboyController@pagamento` | B2 |

Comprovante (grupo próprio `v1/entregas/comprovantes`, middleware `signed` + `throttle:60,1`, sem token):

| GET `{uuid}` | `ComprovantesController@mostrar` (stream do disco `local`, `Content-Type` gravado, `inline`) | B2 |

`{id}` de motoboy = `drivers.public_id`; de loja = `vendors.public_id`; de lançamento = `entregas_lancamentos.id`;
de pagamento e fatura = `uuid`.

### 1.5 Formatos JSON

Valores em reais com 2 casas (`float`), datas `'Y-m-d'`, instantes ISO 8601 no fuso da company.

**Lancamento**
```json
{ "id": 812, "tipo": "corrida", "valor": 8.5, "data": "2026-10-12", "criado_em": "2026-10-12T14:03:00-03:00",
  "pedido": { "id": "order_ab12", "numero": "RP5706" },
  "detalhes": { "km": 3.21, "faixa": { "de_km": 2, "ate_km": 4, "acima": false }, "loja_nome": "Pizzaria do Zé",
                "destino": "Rua X, 10", "cancelado_pago": false, "cancelado_origem": null },
  "motivo": null, "observacao_interna": null,
  "situacao": "aberto", "valor_pago": 0,
  "pagamento": null,
  "estorno_de": null, "estornado": false,
  "fatura": null,
  "criado_por": null }
```
- `situacao`/`valor_pago` só em créditos (senão `null`). `pagamento` (lançamentos `pagamento`/`recebimento`):
  `{ "id": "<uuid>", "forma": "pix", "tem_comprovante": true, "estornado": false }`. `fatura` (conta loja):
  `{ "id": "<uuid>", "numero": "FAT-0031" }`. `criado_por`: `{ "nome": "Edgard" }` ou `null` (sistema).
- **Público** (app e portal): sem `observacao_interna` e sem `criado_por`; para o motoboy, `detalhes` nunca tem valor
  da loja (o lançamento da conta motoboy só guarda o valor dele).

**Extrato**
```json
{ "conta": { "tipo": "motoboy", "id": "driver_x1", "nome": "João Silva", "pix": { "chave": "11999994321", "tipo": "telefone" } },
  "inicio": "2026-10-01", "fim": "2026-10-12",
  "saldo": 212.5, "saldo_anterior": 120.0,
  "totais": { "creditos": 812.5, "debitos": 600.0 },
  "ultimo_pagamento": { "id": "<uuid>", "valor": 150.0, "data": "2026-10-10", "forma": "pix" },
  "lancamentos": [ /* Lancamento, mais novo primeiro */ ] }
```
`saldo` = soma de todos os lançamentos; `saldo_anterior` = soma antes de `inicio`; `totais` = no período. Conta loja:
`pix` ausente. App: `conta.pix` presente (só leitura).

**Pagamento (app, GET `pagamentos/{id}`)**
```json
{ "id": "<uuid>", "valor": 150.0, "forma": "pix", "data": "2026-10-10", "estornado": false,
  "comprovante": { "url": "https://…/v1/entregas/comprovantes/<uuid>?expires=…&signature=…", "tipo": "image/jpeg", "nome": "pix.jpg" } }
```
`comprovante` = `null` sem arquivo. O console recebe a mesma estrutura no `pagamento` da resposta de
`POST financeiro/pagamentos` (`{ "pagamento": {...}, "saldo": 62.5 }`), e o extrato do console traz
`pagamento.comprovante_url` (assinada) nos lançamentos de pagamento/recebimento.

**Motoboys (console)**
```json
{ "motoboys": [ { "id": "driver_x1", "nome": "João Silva", "saldo": 212.5,
                  "ultimo_pagamento": { "valor": 150.0, "data": "2026-10-10" },
                  "corridas": 31, "valor_corridas": 268.5, "tem_pix": true } ] }
```
Lista todos os motoboys da company (mesmo sem lançamento), ordenados por saldo desc, depois nome.

**Lancar (POST `financeiro/lancamentos`)** — entrada:
```json
{ "conta_tipo": "motoboy", "contas": ["driver_x1", "driver_x2"], "tipo": "diaria", "valor": 30, "data": "2026-10-12",
  "pedido": null, "motivo": "Diária", "observacao_interna": null }
```
Regras: `tipo` em `TIPOS_MANUAIS_*` da conta; `valor` ≠ 0, positivo para diária/bônus; ajuste e saldo inicial aceitam
negativo; `pedido` (public_id) só para ajuste e precisa ser da company; `contas` 1..100. Saída
`{ "lancamentos": [Lancamento…] }`. Lançamento manual na conta loja nunca entra direto numa fatura (só via
`faturas/{id}/ajustes`).

**Configuracoes**: `{ "corte": "2026-11-01" | null, "cobranca_padrao": { "ciclo": "semanal", "dia_fechamento": 0, "prazo_dias": 3 }, "ligada": true }`
(PUT aceita `corte` e `cobranca_padrao`; `ligada` só leitura).

**Painel**
```json
{ "inicio": "2026-10-01", "fim": "2026-10-31",
  "a_pagar": 1284.5, "adiantado": 50.0, "a_receber": 3910.0, "vencido": 620.0, "nao_faturado": 412.0,
  "periodo": { "cobrado": 4380.0, "pago_motoboys": 3278.0, "margem": 1102.0 },
  "atencao": [
    { "tipo": "fatura_vencida", "fatura": "<uuid>", "numero": "FAT-0031", "loja": "Pizzaria do Zé", "valor": 620.0, "vencimento": "2026-10-08" },
    { "tipo": "rascunho", "fatura": "<uuid>", "loja": "Terraço", "valor": 412.0 },
    { "tipo": "email_nao_enviado", "fatura": "<uuid>", "numero": "FAT-0033", "loja": "Burger Hub" },
    { "tipo": "sem_loja", "pedido": "order_x", "numero": "RP5702" },
    { "tipo": "sem_valor", "pedido": "order_y", "numero": "RP5710" } ] }
```
`a_pagar` = soma dos saldos positivos dos motoboys; `adiantado` = soma dos negativos (positiva); `a_receber` = em aberto
das faturas emitidas; `vencido` = parte vencida; `nao_faturado` = lançamentos de loja sem fatura; `periodo.cobrado` =
créditos de loja (corrida + ajuste) com data no período; `periodo.pago_motoboys` = créditos de motoboy (corrida, diária,
bônus, ajuste) no período; `margem` = diferença. `sem_loja`: lançamento de corrida de motoboy cujo pedido não tem
lançamento de loja (últimos 30 dias, até 20). `sem_valor`: pedidos concluídos há mais de 1 h desde o corte sem lançamento
(até 20; usa a mesma consulta da conferência).

**Fatura**
```json
{ "id": "<uuid>", "numero": "FAT-0031", "loja": { "id": "vendor_x", "nome": "Pizzaria do Zé" },
  "periodo_inicio": "2026-09-28", "periodo_fim": "2026-10-04", "vencimento": "2026-10-08",
  "situacao": "emitida", "pagamento": "aberta", "vencida": true,
  "total": 620.0, "recebido": 0, "em_aberto": 620.0, "entregas": 42,
  "emitida_em": "2026-10-05T10:00:00-03:00", "email_enviado_em": null }
```
Rascunho: `numero` null, `total` = soma atual dos itens, `pagamento` null. Público (portal): sem `email_enviado_em`.
`GET financeiro/faturas/{id}` → `{ "fatura": Fatura, "itens": [Lancamento…] }` (itens mais antigos primeiro).
`GET financeiro/faturas` → `{ "faturas": [Fatura…] }` (filtros `situacao` = rascunho|emitida|cancelada|vencida|aberta|paga, `loja` = vendor public_id).

**Lojas (console)**
```json
{ "lojas": [ { "id": "vendor_x", "nome": "Pizzaria do Zé", "saldo": 1032.0, "em_aberto": 620.0, "vencido": 620.0,
               "nao_faturado": 412.0, "ciclo": { "ciclo": "semanal", "dia_fechamento": 0, "prazo_dias": 3, "padrao": true },
               "ciclo_atual": { "inicio": "2026-10-05", "fim": "2026-10-11" }, "email": "ze@x.com" } ] }
```

**Portal `loja/financeiro`**
```json
{ "ciclo_atual": { "inicio": "2026-10-06", "fim": "2026-10-12", "entregas": 42, "valor": 315.0 },
  "faturas": [ /* Fatura pública, só emitidas e canceladas, mais nova primeiro */ ] }
```
`loja/faturas/{id}` → `{ "fatura": Fatura pública, "itens": [Lancamento público], "loja": { "nome", "endereco" }, "empresa": { "nome" } }`;
rascunho ou fatura de outra loja → 404.

---

## 2. Tarefas e ondas

Onda 1 (em paralelo, contrato fixo): **B1**, **F1**, **F2**, **F3**, **A1**.
Onda 2 (depois do merge do B1 na `main` local): **B2**, **B3**, **B4** em paralelo.
Onda 3: integração (merge, testes completos, i18n, revisão, CLAUDE.md e histórico).

Cada tarefa abaixo lista arquivos, passos e testes. Código PHP segue os padrões citados (arquivo:linha) — leia o
modelo antes de escrever.

### B1 — Base do servidor (tabelas, lançamentos, quitação, lançador, pagamentos, comandos, observador)

**Arquivos**
- Criar: a migration de 1.1; `api/app/Support/Entregas/Financeiro/{Financeiro,Lancamentos,Quitacao,LancadorDeCorridas,Pagamentos}.php`;
  `api/app/Console/Commands/Entregas/{FinanceiroConferir,FinanceiroLancarHistorico}.php`;
  `api/app/Listeners/Entregas/ObservadorFinanceiroDosPedidos.php`; `api/app/Jobs/Entregas/LancarCorridaDoPedido.php`;
  testes `scripts/teste-php/financeiro-quitacao.php`,
  `scripts/teste-php/financeiro-lancador.php`, `scripts/teste-php/financeiro-pagamentos.php`.
- Modificar: `api/app/Support/Entregas/CalculoEntregas.php` (cada entrega ganha `order_uuid`, `motoboy_uuid`,
  `loja_uuid` — uuid do Vendor ou null; e `valorCongelado` NÃO recalcula se o pedido já tem lançamento `corrida` —
  carregar os order_uuid lançados junto com os congelados, num `whereIn` só);
  `api/app/Providers/AppServiceProvider.php` (`lancarCorridasNoFinanceiro()` no `boot()`);
  `api/app/Console/Kernel.php` (`entregas:financeiro-conferir` everyFiveMinutes `when(Financeiro::ligada())`
  withoutOverlapping(10) runInBackground appendOutputTo);
  `api/config/services.php`, `deploy/docker-stack.yml`, `deploy/stack.env.example`;
  `scripts/teste-php/stubs-ifood.php` (glob `*_entregas_financeiro_*`; o que faltar no banco em memória para as consultas
  novas: `insertOrIgnore`, `sum`, `groupBy`, `whereNull`, `lockForUpdate` no-op…);
  `scripts/teste-php/ganhos-motoboy.php` se as chaves novas da entrega quebrarem alguma asserção.

**Passos (TDD)**
- [ ] `financeiro-quitacao.php` (só `Quitacao`, sem banco) com os casos: tudo pago; pagamento cobre 1,5 corrida
  (pago/parcial/aberto); adiantamento (caixa > créditos → tudo pago, saldo negativo); estorno de corrida → `anulado`;
  ajuste −2 com pedido abate a corrida do pedido (não a mais antiga); ajuste sem pedido vai ao caixa; estorno de
  pagamento devolve o caixa; ordem por data e depois id; arredondamento (0,1+0,2); faturas: pagas por vencimento,
  parcial, vencida só com em_aberto > 0 e vencimento < hoje. Rodar → FALHA. Implementar `Quitacao`. Rodar → `FALHAS: 0`. Commit.
- [ ] Migration + `Financeiro` + `Lancamentos`. `financeiro-pagamentos.php`: a migration cria as 5 tabelas com as colunas
  de 1.1 (inspecionar `Schema::$criadas`); `inserir` com chave repetida devolve null; `estornar` cria o oposto e não
  estorna duas vezes nem estorna estorno; `Pagamentos::registrar` grava as duas linhas (pagamento + lançamento negativo,
  mesmo `pagamento_uuid`), `estornar` marca e cria estorno positivo, segunda vez false; comprovante salvo no disco
  `local` com o caminho de 1.3 (stub de `Storage`). Commit.
- [ ] `financeiro-lancador.php`: pedido concluído gera 2 lançamentos (motoboy valor_motoboy, loja valor_loja, `detalhes`
  sem valor da loja na conta motoboy, `data_referencia` = dia em Brasília de `concluido_em`); repetido não duplica; sem
  Vendor → só motoboy; antes do corte → nada; sem valor → `sem_valor`++; cancelado pago entra; corrida lançada cujo
  pedido virou cancelado sem pagamento → `estornarCancelados` cria estorno com o motivo; `valorCongelado` não recalcula
  pedido lançado (km diferente mantém o valor). Implementar `LancadorDeCorridas` e a mudança no `CalculoEntregas`.
  Rodar também `ganhos-motoboy.php`, `fuso-relatorio.php` → `FALHAS: 0`. Commit.
- [ ] Comandos, job, observador, Kernel, AppServiceProvider, config, stack. Teste no `financeiro-lancador.php`: observador
  ignora com a chave desligada, enfileira só quando `status` muda para concluído/cancelado, nunca lança exceção.
  `sintaxe.mjs` em todos os PHP novos/alterados; `imports-dos-providers.php` → `FALHAS: 0`. Commit.
- [ ] `docs/superpowers/plans/notas/B1.md`: texto pronto para o futuro `api/app/Support/Entregas/Financeiro/CLAUDE.md`
  (regras, tabelas, comandos, testes, armadilhas). Commit.

### B2 — API do console (motoboys, painel, configurações) e do app

Depende de B1. **Arquivos**: criar `api/app/Support/Entregas/Financeiro/{ExtratoDaConta,PainelFinanceiro}.php`,
`api/app/Http/Controllers/Entregas/{FinanceiroController,ComprovantesController}.php`,
`api/app/Notifications/Entregas/PagamentoRegistrado.php`, teste `scripts/teste-php/financeiro-console.php`;
modificar `MotoboyController.php` (`extrato`, `pagamento`), `RouteServiceProvider.php` (rotas de 1.4 marcadas B2),
`AvisosDoMotoboy.php` (texto + canal); notas em `docs/superpowers/plans/notas/B2.md`.
`registrarPagamento` aceita o arquivo no campo `comprovante` **ou** `file` (multipart; demais campos no mesmo form).

**Passos**
- [ ] Teste do `ExtratoDaConta::montar`: formato de 1.5, `saldo`/`saldo_anterior`/`totais`, ordem (mais novo primeiro),
  situação calculada com a conta inteira mesmo filtrando o período, público sem `observacao_interna`/`criado_por`,
  filtro `tipo`. Implementar. Commit.
- [ ] Teste do `FinanceiroController` (modelo: `ganhos-motoboy.php:221-256`, chamada direta com `Request`): não admin →
  403; `lancar` valida tipo/valor/sinal/pedido/contas e cria um por conta; `registrarPagamento` com valor > saldo funciona
  (adiantamento) e manda o push `PagamentoRegistrado` (registrar o notify num stub); `estornar*`; `salvarPix` valida tipo;
  `configuracoes` lê/grava `financeiro_corte` e `cobranca_padrao` (valida com `CicloDeCobranca::validar` — se B3 ainda não
  existir, validar aqui com as mesmas regras: ciclo ∈ {semanal,quinzenal,mensal}, dia 0..6 semanal, 0..28 mensal,
  prazo 0..60). Implementar. Commit.
- [ ] `PainelFinanceiro` com teste (números de 1.5 a partir de lançamentos e faturas em memória; sem faturas → zeros).
  Calcula as faturas direto das tabelas `entregas_faturas`/`entregas_lancamentos` com `Quitacao::faturas` (B1), sem
  depender da classe `Faturas` da B3. Commit.
- [ ] `MotoboyController@extrato` (só token de motoboy, até 92 dias, `ExtratoDaConta::montar(..., publico: true)` +
  `conta.pix`) e `@pagamento` (só pagamento da conta dele; senão 404) + `ComprovantesController@mostrar` (URL assinada,
  404 sem arquivo). Teste: motoboy nunca vê lançamento de outra conta nem `observacao_interna`. Commit.
- [ ] Rotas + `imports-dos-providers.php` + `sintaxe.mjs`. Notas B2. Commit.

### B3 — Faturas das lojas (servidor)

Depende de B1. **Arquivos**: criar `api/app/Support/Entregas/Financeiro/{CicloDeCobranca,Faturas}.php`,
`api/app/Http/Controllers/Entregas/FinanceiroLojasController.php`, `api/app/Console/Commands/Entregas/FinanceiroRascunhos.php`,
testes `scripts/teste-php/financeiro-ciclo.php` e `scripts/teste-php/financeiro-faturas.php`; modificar
`RouteServiceProvider.php` (rotas B3) e `Console/Kernel.php` (`dailyAt('00:30')`, `when(Financeiro::ligada())`).

**Passos**
- [ ] `financeiro-ciclo.php` (puro): semanal fecha no dia certo (domingo → período seg..dom), não fecha nos outros;
  quinzenal fecha em 15 (1–15) e no último dia (16–fim, fevereiro 28/29); mensal dia 5 (6 do mês anterior..5), dia 0 =
  último dia; `periodoQueContem`; `vencimento` = fim + prazo; `validar`. Implementar. Commit.
- [ ] `financeiro-faturas.php`: rascunho pega só lançamentos da loja sem fatura e data ≤ fim (inclui antigos atrasados,
  exclui recebimentos), não duplica para o mesmo `periodo_fim`, não cria vazio; `ajuste`/`naoCobrar`/`mudarVencimento`
  só em rascunho; `emitir` numera sequencial (1, 2…) e grava total; `cancelar` libera os itens e o próximo rascunho os
  pega; `situacoesDaLoja` usa `Quitacao::faturas` com os recebimentos; `resumo` no formato de 1.5. Implementar. Commit.
- [ ] `FinanceiroLojasController` com teste (admin; formatos `Lojas`, `Fatura`; extrato da loja via `ExtratoDaConta` do B2 —
  se B2 ainda não estiver na `main`, a rota `extratoDaLoja` fica para a integração e o teste marca o caso como pendente).
  Emitir chama `App\Support\Entregas\Financeiro\AvisosDaFatura::emitida($fatura)` **se a classe existir**
  (`class_exists`) — ela é da B4. Commit.
- [ ] `FinanceiroRascunhos` (comando) com teste (ontem fecha → cria; não fecha → nada). Kernel. Rotas, sintaxe,
  imports. Notas em `docs/superpowers/plans/notas/B3.md`. Commit.

### B4 — Portal da loja e e-mails (servidor)

Depende de B1 (usa `Faturas`/`CicloDeCobranca` da B3: implemente contra o contrato de 1.3; na integração, a onda 3 roda
os testes juntos). **Arquivos**: criar `api/app/Support/Entregas/Financeiro/AvisosDaFatura.php` (`emitida(object $fatura): bool`,
`lembrete(object): bool`, `vencida(object): bool`, `emailsDaLoja(string $companyUuid, string $vendorUuid): array`),
`api/app/Notifications/Entregas/Email/{FaturaEmitida,LembreteDeFatura,FaturaVencida}.php`,
`api/app/Console/Commands/Entregas/FinanceiroAvisos.php`, teste `scripts/teste-php/financeiro-portal-emails.php`;
modificar `PortalLojaController.php` (`financeiro`, `fatura`), `RouteServiceProvider.php` (rotas B4),
`Email/CanalEmailEntregas.php` (sem warning para `App\Notifications\Entregas\`), `Console/Kernel.php` (`dailyAt('09:00')`),
notas em `docs/superpowers/plans/notas/B4.md`.

**Passos**
- [ ] Teste: `loja/financeiro` só da loja da sessão, só emitidas/canceladas, `ciclo_atual` com o não faturado; `loja/faturas/{id}`
  de outra loja ou rascunho → 404; formato público (sem `observacao_interna`, sem `criado_por`, sem `email_enviado_em`).
  Implementar. Commit.
- [ ] Teste dos e-mails: textos pt-BR (assunto "Fatura FAT-0031 emitida", "Fatura FAT-0031 vence amanhã", "Fatura FAT-0031
  está vencida"), destinatários (Vendor + usuários do portal, sem repetir, sem vazio), sem e-mail → `false` e
  `email_enviado_em` continua null; grava `*_enviado_em` ao enviar. `FinanceiroAvisos` manda lembrete e vencida uma vez
  só e só com em aberto. Implementar. Commit.
- [ ] Rotas, Kernel, sintaxe, imports, `node scripts/teste-emails-views.mjs` e `scripts/teste-php/emails.php`. Notas B4. Commit.

### Padrões do console (F1 e F2) — ler antes

Engine `packages/fleetops`. Modelos reais:
- Tela com API e task: `addon/routes/management/lojas.js` (guarda de admin no `beforeModel` com `currentUser.isAdmin`,
  `setupController` → `controller.carregar.perform()`, `resetController` → `controller.limpar()`),
  `addon/controllers/management/lojas.js` (`@service fetch/intl/notifications/modalsManager`, `@task({restartable:true})`
  para leitura, `@task({drop:true})` para gravação, `notifications.serverError(error)`, validação no cliente com getters),
  `addon/templates/management/lojas.hbs` e `management/driver-payouts.hbs` (`Layout::Section::Header/Body`, `ContentPanel`,
  `InputGroup` + `<input type="date">`, `<input type="text" inputmode="decimal">`, `<table class="table w-full text-sm">`,
  cards `rounded-lg border border-gray-200 dark:border-gray-700 p-4`, `Badge`, `ClickToCopy`, `Spinner`).
- `this.fetch.get('entregas/financeiro/...', query)` (namespace padrão `int/v1`), `post`, `put`. Upload multipart: ver
  `packages/ember-core/addon/services/fetch.js:509-614` (`upload`); o servidor aceita o arquivo no campo `comprovante`
  **ou** `file` e os demais campos no mesmo FormData.
- Moeda: `new Intl.NumberFormat(this.intl.primaryLocale ?? 'pt-BR', { style: 'currency', currency: 'BRL' })`; datas ISO
  locais (`isoDate` de `controllers/management/driver-payouts.js:10`, nunca `toISOString()`); número digitado com vírgula
  (`numero()` de `driver-payouts.js:11`).
- CSV: o de `driver-payouts.js:214-262` (`;`, BOM, vírgula decimal, aspas).
- Modal próprio: `this.modalsManager.show('modals/<nome>', {...})` + `addon/components/modals/<nome>.{hbs,js}` +
  re-export `app/components/modals/<nome>.js` (`export { default } from '@fleetbase/fleetops-engine/components/modals/<nome>';`).
  Template `<Modal::Default @modalIsOpened={{@modalIsOpened}} @options={{@options}} @confirm={{@onConfirm}} @decline={{@onDecline}}>`.
  Confirmação: `modalsManager.confirm({... confirm: async (modal) => { modal.startLoading(); ... } })`.
- Rotas do engine **não** têm re-export em `app/`; componentes sim.
- Utils puros sem import do Ember em `addon/utils/`, testados em `scripts/teste-portal/<nome>.test.mjs`
  (`node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/<nome>.test.mjs`).
- Traduções: `packages/fleetops/translations/pt-br.yaml` (2 espaços) e `en-us.yaml` (4 espaços), grupo
  `fleet-ops.ui.financeiro.*` (F1) e `fleet-ops.ui.financeiro-lojas.*` (F2). **Não mexa no grupo `driver-payouts`**
  (o util `pedido-de-origem.js` depende dele). F1 insere o grupo dela logo depois do grupo `distribuicao:`; F2 logo
  antes de `pedido-sem-motoboy:` (evita conflito de merge).
- Na worktree não há `console/node_modules`; para o `i18n-check` e o `@babel/parser`, crie uma junção:
  `cmd //c mklink /J console\\node_modules "C:\\Users\\Edgardjr\\Documents\\vibe coding\\Delivery\\console\\node_modules"`
  (é ignorada pelo git; não commite).

### F1 — Console: grupo Financeiro, painel, motoboys, relatório, configurações

**Rotas** (em `packages/fleetops/addon/routes.js`, nível de topo, **F1 registra também as da F2**):
```js
this.route('financeiro', function () {
    this.route('painel');
    this.route('motoboys');
    this.route('motoboy', { path: '/motoboys/:id' });
    this.route('lojas');                         // F2
    this.route('loja', { path: '/lojas/:id' });  // F2
    this.route('fatura', { path: '/faturas/:id' }); // F2
    this.route('relatorio');
    this.route('configuracoes');
});
```
`financeiro.index` redireciona para `financeiro.painel`. `management.driver-payouts` vira só um redirecionamento para
`financeiro.relatorio` (mantenha o arquivo da rota com `beforeModel` → `transitionTo`).

**Arquivos**
- Criar: `addon/routes/financeiro.js` + `templates/financeiro.hbs` (`{{outlet}}`), `routes/financeiro/index.js`;
  rota/controller/template de `financeiro/{painel,motoboys,motoboy,relatorio,configuracoes}`;
  `addon/components/modals/{registrar-pagamento,lancar-no-financeiro}.{hbs,js}` + re-exports em `app/components/modals/`;
  `addon/utils/financeiro.js` + `scripts/teste-portal/financeiro.test.mjs`.
- Mover: `controllers/management/driver-payouts.js` e `templates/management/driver-payouts.hbs` →
  `controllers/financeiro/relatorio.js` e `templates/financeiro/relatorio.hbs` com `git mv` (conteúdo igual: faixas de km +
  relatório antigo; título "Tabela de km e relatório"). Ajustar imports relativos (`../../utils/...`).
- Modificar: `addon/components/layout/fleet-ops-sidebar.js` (novo `createBranch` `id:'financeiro'`, ícone `sack-dollar`,
  depois de `resources`, `requiresVisibleChildren`, filhos todos `visible: this.currentUser.isAdmin === true`: Painel
  (`chart-pie`), Motoboys (`motorcycle`), Lojas e faturas (`file-invoice-dollar`, rota `financeiro.lojas`), Tabela de km e
  relatório (`table`), Configurações (`gear`); remover o item `management.driver-payouts` de Recursos; prioridades em
  `defaultPriorityForRoute`); `addon/extension.js` (atalho `fleet-ops-sc-driver-payouts`: manter `id`, rota
  `console.fleet-ops.financeiro.painel`); traduções; `routes/management/driver-payouts.js` (redirecionamento).

**`utils/financeiro.js`** (puro, testado): `rotuloDoTipo(tipo)` → chave i18n `fleet-ops.ui.financeiro.tipos.<tipo>`;
`corDaSituacao(situacao)` → classes do selo (pago verde, parcial amarelo, aberto cinza, anulado riscado cinza, vencida
vermelho); `agruparPorDia(lancamentos)` → `[{ data, lancamentos }]` mantendo a ordem; `podeEstornar(lancamento)` (não é
estorno, não está estornado, não é corrida de pedido com fatura emitida — corrida sim pode); `validarLancamento({tipo,valor,contas,pedido})`
→ lista de chaves de erro (valor zero; diária/bônus negativos; pedido só em ajuste; nenhuma conta); `avisoDeAdiantamento(valor, saldo)` →
excedente (> 0) ou 0; `linhasCsvDoExtrato(extrato)` → array de linhas (cabeçalho: Data;Tipo;Pedido;Motivo;Valor;Situação).

**Telas**
- **Painel** (`financeiro.painel`): período (Este mês padrão; atalhos como o relatório), cards `a_pagar`, `adiantado`,
  `a_receber` (+ `vencido` em vermelho), `nao_faturado`, margem do período (`periodo.*`); lista "Atenção" com ícone e
  link: fatura → `financeiro.fatura`, rascunho → `financeiro.fatura`, pedido → `operations.orders.index.details`.
- **Motoboys**: tabela (nome, saldo colorido: positivo verde "a pagar", negativo vermelho "adiantado", último pagamento,
  corridas e valor no período, ícone de Pix ausente); período no topo; checkbox por linha + botão "Lançar para os
  selecionados" (abre `lancar-no-financeiro` com várias contas); botão "Pagar" por linha (abre `registrar-pagamento`);
  clique na linha → `financeiro.motoboy`.
- **Extrato do motoboy** (`financeiro.motoboy`, `:id` = driver public_id): cabeçalho com nome, saldo, Pix (`ClickToCopy`;
  botão "Editar Pix" com formulário inline: tipo + chave → `PUT .../pix`), botões **Registrar pagamento** e
  **Lançar diária / bônus / ajuste / saldo inicial**; filtros período e tipo; `saldo_anterior` e `totais`; tabela agrupada
  por dia (data, descrição: corrida "#numero · loja · km" com link para o pedido, motivo, 🔒 observação interna em
  `title`, "lançado por"), valor colorido, selo de situação, ação **Estornar** (confirmação com campo motivo →
  `POST lancamentos/{id}/estornar`; em pagamento → `POST pagamentos/{uuid}/estornar`); link "comprovante" abre
  `pagamento.comprovante_url` em nova aba; botão CSV.
- **Modal `registrar-pagamento`** (opções: `contaTipo` 'motoboy'|'loja', `contaId`, `nome`, `saldo`, `onFinish`): valor
  (sugere o saldo se > 0), forma (select das 4), data (hoje local), comprovante (input file `accept="image/*,application/pdf"`,
  até 10 MB validado no cliente), observação interna; aviso amarelo "Vai ficar como adiantamento de R$ X" (motoboy) /
  "Valor acima do saldo da loja" (loja) quando `avisoDeAdiantamento > 0`; envia multipart `POST financeiro/pagamentos`.
  Título "Registrar pagamento" (motoboy) / "Registrar recebimento" (loja). **A F2 usa este modal.**
- **Modal `lancar-no-financeiro`** (opções: `contaTipo`, `contas` [{id,nome}], `onFinish`): tipo (motoboy: diária, bônus,
  ajuste, saldo inicial; loja: ajuste, saldo inicial), valor (aceita "-" em ajuste/saldo inicial), data, pedido (só ajuste:
  texto com o número/public_id), motivo (visível), observação interna; mostra "Para N motoboys" quando várias contas.
- **Relatório** (`financeiro.relatorio`): a tela antiga movida, sem mudança de comportamento.
- **Configurações**: data de corte (input date; aviso "Corridas antes desta data não entram no financeiro"; aviso se
  `ligada` = false: "O lançamento automático está desligado no servidor (ENTREGAS_FINANCEIRO)"), ciclo padrão das lojas
  (ciclo, dia de fechamento: select de dias da semana para semanal, 1–28 ou "último dia" para mensal, oculto em
  quinzenal; prazo em dias) → `PUT financeiro/configuracoes`.

**Passos**
- [ ] `utils/financeiro.js` + teste (cada função com 2–4 casos, incluindo listas vazias e valores com vírgula). Rodar → passa. Commit "Console: funções puras do Financeiro".
- [ ] Rotas, rota pai, index, sidebar, extension.js, redirecionamento, `git mv` do relatório, traduções do menu. Commit.
- [ ] Painel. Commit. — [ ] Motoboys + modal lançar. Commit. — [ ] Extrato + modal registrar pagamento + estornos + Pix + CSV. Commit. — [ ] Configurações. Commit.
- [ ] `i18n-check` exit 0; parse babel de todo JS alterado; testes do `scripts/teste-portal` (todos) passam.
  Notas para o CLAUDE.md do Fleet-Ops em `docs/superpowers/plans/notas/F1.md`. Commit.

### F2 — Console: Lojas e faturas

Mesmos padrões. Rotas já registradas pela F1 (`financeiro.lojas`, `financeiro.loja`, `financeiro.fatura`); se a sua
worktree ainda não as tiver, acrescente as três linhas no `routes.js` (a integração resolve o conflito).

**Arquivos**: rota/controller/template de `financeiro/{lojas,loja,fatura}`; `addon/components/modals/{gerar-fatura,ajuste-na-fatura}.{hbs,js}`
+ re-exports; `addon/utils/financeiro-faturas.js` + `scripts/teste-portal/financeiro-faturas.test.mjs`; traduções
`fleet-ops.ui.financeiro-lojas.*`.

**`utils/financeiro-faturas.js`** (puro): `rotuloDaFatura(fatura)` → chave da situação exibida (`rascunho`, `cancelada`,
`vencida`, `paga`, `parcial`, `aberta`, nessa precedência); `corDaFatura(fatura)`; `filtrarFaturas(faturas, filtro)`
(todas, rascunho, aberta, vencida, paga, cancelada); `textoDoCiclo(ciclo)` → `{chave, params}` (ex.: semanal + domingo);
`opcoesDeDia(ciclo)`; `numeroOuRascunho(fatura)`; `linhasCsvDaFatura(fatura, itens)`.

**Telas**
- **Lojas e faturas** (`financeiro.lojas`, abas sem rota com `TabNavigation @tabs @activeTabId @onTabChange` — modelo
  `components/map/drawer.hbs:9-11`): aba **Faturas** (filtro de situação e loja; tabela: nº ou "Rascunho", loja, período,
  vencimento, valor, recebido, selo; botão por linha: rascunho → "Conferir e emitir" (abre `financeiro.fatura`),
  emitida em aberto → "Registrar recebimento" (modal `registrar-pagamento` da F1 com `contaTipo:'loja'`), vencida →
  destaque vermelho; botão "Gerar fatura" → modal `gerar-fatura` (loja, início, fim → `POST financeiro/faturas`; resposta
  null/422 → aviso "Sem lançamentos para faturar nesse período")); aba **Lojas** (nome, saldo, em aberto, vencido, não
  faturado, ciclo em texto, botão "Editar ciclo" inline: ciclo/dia/prazo → `PUT lojas/{id}/ciclo`; clique → `financeiro.loja`);
  aba **Ciclo em andamento** (por loja: período atual e não faturado).
- **Fatura** (`financeiro.fatura`, `:id` = uuid): cabeçalho (nº, loja, período, vencimento, situação, total, recebido, em
  aberto); tabela de itens (data, pedido com link, km, destino, motivo, valor); **rascunho**: "Não cobrar" por linha de
  corrida (confirmação → `POST .../nao-cobrar {lancamento_id}`), "+ Ajuste" (modal `ajuste-na-fatura`: valor ±, motivo,
  observação → `POST .../ajustes`), vencimento editável (`PUT faturas/{id} {vencimento}`), **Emitir** (confirmação "A loja
  vai ver a fatura no portal e receber por e-mail" → `POST .../emitir`); **emitida**: Registrar recebimento, Reenviar e-mail
  (`POST .../reenviar`), Imprimir (`window.print()` com CSS de impressão que esconde menu e botões), **Cancelar fatura**
  (confirmação danger "Os lançamentos voltam para a próxima fatura" → `POST .../cancelar`); CSV.
- **Extrato da loja** (`financeiro.loja`, `:id` = vendor public_id): mesmo layout do extrato do motoboy da F1, sem Pix,
  com coluna "Fatura" (link) e botões Registrar recebimento e Lançar ajuste/saldo inicial (modais da F1 com
  `contaTipo:'loja'`). Se o componente/tabela do extrato da F1 ainda não existir na sua worktree, faça a tabela no próprio
  template (a integração unifica depois, se valer a pena).

**Passos**: util + teste → commit; Lojas e faturas → commit; Fatura → commit; Extrato da loja → commit; i18n-check +
babel + testes → notas em `docs/superpowers/plans/notas/F2.md` → commit.

### F3 — Portal da loja: Financeiro e impressão da fatura

Modelo: tela Extrato do portal (`packages/customer-portal/addon/{routes,controllers,templates}/portal/extrato.*` + re-exports
em `app/{routes,controllers,templates}/portal/extrato.js` — **no portal os re-exports são obrigatórios**). Ler
`packages/customer-portal/CLAUDE.md` (no checkout principal, caminho absoluto).

**Rotas** (`packages/customer-portal/addon/routes.js`, antes de `virtual`):
```js
this.route('financeiro');
this.route('fatura', { path: '/financeiro/:id' });
```
**Arquivos**: route/controller/template de `portal/financeiro` e `portal/fatura` + re-exports; `addon/utils/entregas-financeiro.js`
+ `scripts/teste-portal/entregas-financeiro.test.mjs`; menu em `addon/templates/portal.hbs` (item "Financeiro", ícone
`file-invoice`, depois de Extrato) e atalho na home (`portal/home.hbs`, como o do extrato); estilos de impressão (no CSS do
engine: `@media print` escondendo `.portal-sidebar`, cabeçalho e botões; mostrando o bloco `.fatura-impressao`);
traduções `customer-portal.ui.entregas.financeiro.*` em pt-br e en-us.

**Telas**
- **Financeiro**: card KPI do ciclo em andamento ("Desde 06/10 · 42 entregas · R$ 315,00 até agora"; texto "Este valor
  entra na próxima fatura"); tabela de faturas (nº, período, vencimento, valor, recebido, em aberto, selo: Em aberto,
  Paga em parte, Paga, Vencida em vermelho, Cancelada riscada); clique → `portal.fatura`; estado vazio "Nenhuma fatura
  emitida ainda".
- **Fatura**: cabeçalho para impressão (empresa, loja, endereço, nº, período, vencimento), itens (data, pedido, km,
  destino, motivo, valor), totais (total, recebido, em aberto), botão **Imprimir / salvar PDF** (`window.print()`), botão
  CSV, voltar. 404 → aviso e volta para Financeiro.

**`utils/entregas-financeiro.js`** (puro): `situacaoDaFatura(fatura)` (mesma precedência da F2, sem rascunho),
`classeDoSelo(situacao)`, `textoDoCiclo(ciclo_atual)` → `{desde, entregas, valor}` formatável, `linhasCsvDaFatura`.

**Passos**: util + teste → commit; rotas, menu, Financeiro → commit; Fatura + impressão → commit; i18n-check + babel +
`node scripts/teste-isolamento-lojas.mjs` (se rodar sem servidor; senão registrar nas notas) → notas
`docs/superpowers/plans/notas/F3.md` → commit.

### A1 — App do motoboy: extrato, recibo e push

Repo `C:\Users\Edgardjr\Documents\vibe coding\entregas-navigator` (branch `main`). Crie a worktree
`git -C "<repo>" worktree add C:/tmp/fin-app -b financeiro-app` e trabalhe só nela. Leia o `CLAUDE.md` do app
(no checkout principal; não rastreado) e `api/app/Http/Controllers/Entregas/CLAUDE.md` do Delivery.

**Arquivos**: criar `src/utils/extrato.ts` + `scripts/testes/extrato.teste.ts`, `src/screens/ReciboScreen.tsx`;
modificar `src/screens/MeusGanhosScreen.tsx` (vira o extrato), `src/navigation/DriverNavigator.tsx` (rota `Recibo` na pilha
do Início), `translations/pt.json` e `en.json` (grupo `Extrato.*` e `Recibo.*`), o tratamento de push (achar onde
`data.type` é lido — ex.: `entregas_pedido_trocado` — e fazer `entregas_pagamento` abrir `Recibo` com `{ id: data.pagamento }`).

**`src/utils/extrato.ts`** (sem imports, Node puro): tipos `Lancamento`, `Extrato`, `Pagamento` (formatos 1.5, público);
`agruparPorDia(lancamentos)` → `[{dia, data, itens}]` (entrada já mais nova primeiro); `rotuloDoTipo(tipo)` → chave
`Extrato.tipos.<tipo>`; `rotuloDaSituacao(situacao)` → chave `Extrato.situacao.<situacao>` ou null; `sinal(valor)` →
'+'|'−'; `descricao(l)` → `{titulo, subtitulo}` (corrida: "Corrida #numero" + "loja · km"; ajuste: motivo; pagamento: forma);
reaproveite `formatarReais`/`formatarKm`/`periodoDoAtalho` de `src/utils/ganhos.ts` (importe de lá).

**Telas**
- **Meus ganhos** (mesma rota `DriverDashboard`): mantém cabeçalho, online, atalhos de período e a folha do calendário;
  troca o cartão "Total a receber" pelo cartão **"A receber agora"** (`saldo`; negativo → "Adiantamento de R$ X" em
  vermelho) com "Último pagamento: R$ 150,00 em 10/10 (Pix)" e a linha "No período: ganhos R$ X · recebido R$ Y" (`totais`);
  a lista vira `agruparPorDia(extrato.lancamentos)`: cada item com título/subtítulo, valor com sinal e cor, selo de situação
  (pago verde, pago em parte amarelo, a receber cinza, anulado riscado). Toque: corrida → detalhes do pedido (código atual
  `:395-407`); pagamento → `Recibo`. Chamada `api.get('entregas/motoboy/extrato', { inicio, fim })`.
- **Recibo** (`Recibo`, param `id`): `api.get('entregas/motoboy/pagamentos/' + id)`; valor grande, forma, data, "Estornado"
  se for o caso; comprovante: imagem (`Image` com `source={{ uri }}`) ou botão "Abrir comprovante" (`Linking.openURL`) para PDF;
  erro → mensagem e voltar.

**Passos**: `extrato.ts` + teste (`node --experimental-strip-types --test scripts/testes/extrato.teste.ts`) → commit;
tela Meus ganhos → commit; Recibo + navegação + push → commit; `npx tsc --noEmit` (se o projeto tiver) e os testes de
`scripts/testes/` → notas em `C:/tmp/fin-app/NOTAS-FINANCEIRO.md` (não commitar) → commit final. **Não** dispare build
nem push.

### Onda 3 — Integração (sessão principal)

- [ ] Merge dos ramos na `main` local na ordem B1 → B2 → B3 → B4 → F1 → F2 → F3; resolver conflitos (`routes.js`,
  traduções, `RouteServiceProvider`, `Kernel`, `stubs-ifood.php`).
- [ ] Rodar todos os testes PHP do financeiro + `ganhos-motoboy`, `fuso-relatorio`, `imports-dos-providers`, `emails`;
  `sintaxe.mjs` em todos os PHP novos; `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs`;
  `i18n-check` exit 0; parse babel.
- [ ] Conferir o contrato ponta a ponta (formatos usados pelo front = devolvidos pelo servidor).
- [ ] Revisão de código (superpowers:requesting-code-review) e correções.
- [ ] Documentação: `api/app/Support/Entregas/Financeiro/CLAUDE.md`, linhas novas no `CLAUDE.md` da raiz e nos de
  `packages/fleetops`, `packages/customer-portal`, `api/app/Http/Controllers/Entregas`, e-mails; `docs/historico.md`
  (a partir de `docs/superpowers/plans/notas/*.md`).
- [ ] Merge do app (`financeiro-app` na `main` do entregas-navigator).
- [ ] Roteiro de implantação para o Edgard: push (ele), `bash deploy/atualizar.sh` (api + console), `ENTREGAS_FINANCEIRO=1`
  no stack, definir a data de corte no console, `php artisan entregas:financeiro-lancar-historico`, APK novo.
