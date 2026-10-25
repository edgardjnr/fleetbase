# Painel inicial do console — plano de implementação

> **Para agentes:** SUB-SKILL OBRIGATÓRIA: use superpowers:subagent-driven-development (recomendado) ou
> superpowers:executing-plans para executar tarefa por tarefa. Os passos usam caixas (`- [ ]`) para acompanhar.

**Objetivo:** trocar o painel de widgets do Fleetbase na raiz do console (`/`, rota `console.home`) pelo painel da
variante A do sketch 004 (`.planning/sketches/004-painel-inicial/`): números do período no topo, "Ao vivo" com a fila
de pedidos e o mapa, corridas por hora, lojas, motoboys e "Atenção", em pt-BR, na marca Entregas, responsivo.

**Arquitetura:**
- **API** (`api/app`, a única que chega à produção): três endpoints novos em `int/v1/entregas/painel-inicial*` num
  controller próprio.
  - As contas ficam em `App\Support\Entregas\Painel\*`: um agregador puro mais duas classes que leem o banco, com cache
    curto por central.
  - Reaproveita o `MapaDoLider` (pedidos + motoboys com posição), o `PainelFinanceiro` (lista Atenção), o
    `TrocaDoMotoboy` (atribuir) e o `VinculosIfood::resumos` (vínculo iFood da loja).
- **Front:** um componente do engine Fleet-Ops (`home/painel-inicial`, com o subcomponente do mapa), registrado no
  registry `console:home:before-dashboard`, que já existe no `home.hbs`. O `<Dashboard>` sai do `home.hbs`.
  - As contas da tela ficam num util puro testado no Node.
  - O CSS fica no console (`console/app/styles/painel-inicial.css`), que está sempre carregado.

**Tecnologias:** Laravel (PHP 8.2), Ember (Glimmer components, ember-concurrency, ember-leaflet), ember-intl (YAML),
testes php-wasm (`scripts/teste-php`) e `node --test` (`scripts/teste-portal`).

## Desempenho (decidido com o Edgard, 2026-10-25)

- `ao-vivo`: cache de **5 s por central**. Várias telas da mesma central dividem o mesmo cálculo. O atribuir limpa o cache.
- `resumo`: cache de **60 s** por central + período + admin. A lista Atenção (a parte pesada) tem cache próprio de **5 min**.
- O estado da oferta de todos os pedidos sem motoboy sai em **2 consultas em lote**, nunca uma por pedido.
- Dos pedidos, o resumo lê só `uuid`, `started_at`, `dispatched_at` e `meta`, em lotes de 500.
- Período limitado a **31 dias**.
- Front:
  - `ao-vivo` a cada 20 s e no evento do socket (espera de 0,8 s);
  - `resumo` ao abrir, ao trocar o período e a cada 5 min;
  - **nada com a aba escondida.**

## Regras do repo que valem aqui

- Responda e documente em pt-BR.
- **Fuso:** Brasília. As colunas `DATETIME`/`TIMESTAMP` já saem em Brasília. Nunca formate em UTC (`api/CLAUDE.md`).
- **Empresa:** sem `CompanyScope`. Toda consulta filtra `company_uuid` de forma explícita, com a empresa de `session('company')`.
- **Admin:** `isNotAdmin()` do usuário da sessão (`Fleetbase\Support\Auth::getUserFromSession`). Dinheiro, Atenção e
  Atribuir são só para admin.
- **Rota nova:** o `use` do controller no `RouteServiceProvider` precisa existir. Se faltar, a API cai; o teste
  `imports-dos-providers.php` confere.
- **Front na home:**
  - não existe `hostRouter`; use `@service router` com o nome completo da rota (`console.fleet-ops.…`);
  - não use o serviço `order-socket-events`; escute com `utils/escutar-canal-da-empresa.js`.
- **Traduções:** toda chave nova vai em `en-us.yaml` **e** em `pt-br.yaml`. Texto com ": " no meio leva aspas. Antes de
  commitar, rode `node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal`
  (exit 0).
- **JS alterado:** precisa parsear com o `@babel/parser` (comando na Tarefa 11).
- Confira `git rev-parse --show-toplevel` antes de commitar: a home `C:\Users\Edgardjr` também é um repo git.

## Mapa de arquivos

| Arquivo | Papel |
|---|---|
| `api/app/Support/Entregas/Painel/AgregadorDoPainel.php` (novo) | contas puras: período anterior, variação, por hora, por dia, média, ranking, prazos |
| `api/app/Support/Entregas/Painel/PainelInicial.php` (novo) | resumo do período (banco + cache) |
| `api/app/Support/Entregas/Painel/AoVivo.php` (novo) | pedidos + motoboys do mapa, com a oferta e a origem iFood (banco + cache) |
| `api/app/Http/Controllers/Entregas/PainelInicialController.php` (novo) | `resumo`, `aoVivo`, `atribuir` |
| `api/app/Providers/RouteServiceProvider.php` | `use` + 3 rotas |
| `scripts/teste-php/painel-inicial-agregador.php`, `painel-inicial.php`, `painel-inicial-ao-vivo.php`, `painel-inicial-console.php` (novos) | testes php-wasm |
| `packages/fleetops/addon/utils/painel-inicial.js` (novo) | contas puras da tela |
| `scripts/teste-portal/painel-inicial.test.mjs` (novo) | teste do util |
| `packages/fleetops/addon/components/home/painel-inicial.{js,hbs}` (novos) | o painel |
| `packages/fleetops/addon/components/home/painel-inicial/mapa.{js,hbs}` (novos) | o mapa do Ao vivo |
| `packages/fleetops/app/components/home/painel-inicial.js`, `.../painel-inicial/mapa.js` (novos) | reexports |
| `packages/fleetops/addon/extension.js` | registra o painel na home |
| `console/app/templates/console/home.hbs` | sem o `<Dashboard>` |
| `console/app/styles/painel-inicial.css` (novo), `console/app/styles/app.css` | estilo |
| `packages/fleetops/translations/{en-us,pt-br}.yaml` | `fleet-ops.ui.painel-inicial.*` |
| `packages/fleetops/CLAUDE.md`, `docs/historico.md` | documentação |

---

### Tarefa 1: agregador puro (API)

**Arquivos:**
- Criar: `api/app/Support/Entregas/Painel/AgregadorDoPainel.php`
- Teste: `scripts/teste-php/painel-inicial-agregador.php`

- [ ] **Passo 1: escrever o teste que falha**

`scripts/teste-php/painel-inicial-agregador.php`:

```php
<?php

// Contas puras do painel inicial do console (App\Support\Entregas\Painel\AgregadorDoPainel).
// Uso: PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/painel-inicial-agregador.php

require __DIR__ . '/stubs-financeiro.php';

use App\Support\Entregas\Painel\AgregadorDoPainel as A;

// período anterior: um dia → mesmo dia da semana passada; N dias → os N dias antes
confere(A::periodoAnterior('2026-10-24', '2026-10-24') === ['2026-10-17', '2026-10-17'], 'hoje compara com o mesmo dia da semana passada');
confere(A::periodoAnterior('2026-10-18', '2026-10-24') === ['2026-10-11', '2026-10-17'], '7 dias comparam com os 7 anteriores');
confere(A::periodoAnterior('2026-09-25', '2026-10-24') === ['2026-08-26', '2026-09-24'], '30 dias comparam com os 30 anteriores');

// variação
confere(A::variacao(142, 127) === 12, 'variação arredondada');
confere(A::variacao(3, 0) === null, 'sem base, sem variação');
confere(A::variacao(0, 4) === -100, 'queda total');

// por hora
$horas = A::porHora(['2026-10-24 19:10:00', '2026-10-24 19:59:59', '2026-10-24 00:05:00']);
confere(count($horas) === 24 && $horas[19] === 2 && $horas[0] === 1 && $horas[20] === 0, 'conta por hora do dia');

// por dia (com os dias vazios)
$dias = A::porDia(['2026-10-22 10:00:00', '2026-10-24 21:00:00', '2026-10-24 22:00:00'], '2026-10-22', '2026-10-24');
confere($dias === [['dia' => '2026-10-22', 'total' => 1], ['dia' => '2026-10-23', 'total' => 0], ['dia' => '2026-10-24', 'total' => 2]], 'conta por dia, com dia vazio');

// média por hora de N semanas
$media = A::mediaPorHora(['2026-10-17 19:00:00', '2026-10-10 19:30:00', '2026-10-03 20:00:00'], 4);
confere($media[19] === 0.5 && $media[20] === 0.3 && $media[10] === 0.0, 'média por hora com uma casa');

// ranking por loja (nome da linha; sem uuid fica fora; empate pelo nome)
$ranking = A::ranking([
    (object) ['loja_uuid' => 'v-b', 'loja_nome' => 'Spunto'],
    (object) ['loja_uuid' => 'v-a', 'loja_nome' => 'Terraço'],
    (object) ['loja_uuid' => 'v-a', 'loja_nome' => 'Terraço'],
    (object) ['loja_uuid' => null, 'loja_nome' => 'Sem loja'],
    (object) ['loja_uuid' => 'v-c', 'loja_nome' => 'Brasa'],
], 'loja_uuid', 'loja_nome');
confere(array_column($ranking, 'uuid') === ['v-a', 'v-c', 'v-b'] && $ranking[0]['corridas'] === 2, 'ranking: mais corridas, depois o nome');

// prazos: só os pedidos com prazo entram no %; tempo médio do aceite à conclusão
$prazos = A::prazos([
    ['concluido_em' => '2026-10-24 19:10:00', 'aceito_em' => '2026-10-24 18:40:00', 'prazo_em' => '2026-10-24T19:30:00-03:00'],
    ['concluido_em' => '2026-10-24 19:40:00', 'aceito_em' => '2026-10-24 19:00:00', 'prazo_em' => '2026-10-24T19:30:00-03:00'],
    ['concluido_em' => '2026-10-24 20:05:00', 'aceito_em' => '2026-10-24 19:35:00', 'prazo_em' => null],
    ['concluido_em' => '2026-10-24 20:30:00', 'aceito_em' => null, 'prazo_em' => 'lixo'],
], 'America/Sao_Paulo');
confere($prazos === ['no_prazo' => 50, 'tempo_medio_min' => 33], 'no prazo 50% e tempo médio 33 min');
confere(A::prazos([], 'America/Sao_Paulo') === ['no_prazo' => null, 'tempo_medio_min' => null], 'sem pedidos: nulos');

resumo();
```

- [ ] **Passo 2: rodar e ver falhar**

Rode (Git Bash, na raiz):
`PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/painel-inicial-agregador.php`

Esperado: erro de classe `AgregadorDoPainel` não encontrada.

- [ ] **Passo 3: implementar**

`api/app/Support/Entregas/Painel/AgregadorDoPainel.php`:

```php
<?php

namespace App\Support\Entregas\Painel;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Entregas RestaurantePro: contas do painel inicial do console (PainelInicial), sem banco, testadas no php-wasm
 * (scripts/teste-php/painel-inicial-agregador.php).
 * Instantes sem fuso ('Y-m-d H:i:s', DATETIME/TIMESTAMP já em Brasília) são lidos no fuso da empresa.
 */
final class AgregadorDoPainel
{
    /** Período de comparação: um dia → o mesmo dia da semana anterior; N dias → os N dias antes do início. */
    public static function periodoAnterior(string $inicio, string $fim): array
    {
        $de    = new DateTimeImmutable($inicio);
        $ate   = new DateTimeImmutable($fim);
        $dias  = (int) $de->diff($ate)->days + 1;
        $passo = $dias === 1 ? 7 : $dias;

        return [$de->modify("-{$passo} days")->format('Y-m-d'), $ate->modify("-{$passo} days")->format('Y-m-d')];
    }

    /** Variação em % (inteiro) do atual sobre o anterior; null sem base. */
    public static function variacao(int $atual, int $anterior): ?int
    {
        return $anterior > 0 ? (int) round(($atual - $anterior) / $anterior * 100) : null;
    }

    /**
     * Quantas corridas em cada hora do dia (0 a 23).
     *
     * @param array<int, string> $instantes 'Y-m-d H:i:s'
     *
     * @return array<int, int>
     */
    public static function porHora(array $instantes): array
    {
        $horas = array_fill(0, 24, 0);
        foreach ($instantes as $instante) {
            $hora = (int) substr((string) $instante, 11, 2);
            if ($hora >= 0 && $hora < 24) {
                $horas[$hora]++;
            }
        }

        return $horas;
    }

    /**
     * Corridas por dia, do início ao fim (dia sem corrida com 0).
     *
     * @return array<int, array{dia: string, total: int}>
     */
    public static function porDia(array $instantes, string $inicio, string $fim): array
    {
        $contagem = [];
        foreach ($instantes as $instante) {
            $dia            = substr((string) $instante, 0, 10);
            $contagem[$dia] = ($contagem[$dia] ?? 0) + 1;
        }

        $dias = [];
        $ate  = new DateTimeImmutable($fim);
        for ($dia = new DateTimeImmutable($inicio); $dia <= $ate; $dia = $dia->modify('+1 day')) {
            $chave  = $dia->format('Y-m-d');
            $dias[] = ['dia' => $chave, 'total' => $contagem[$chave] ?? 0];
        }

        return $dias;
    }

    /**
     * Média por hora de várias semanas (as corridas do mesmo dia da semana), com uma casa.
     *
     * @return array<int, float>
     */
    public static function mediaPorHora(array $instantes, int $semanas): array
    {
        return array_map(fn (int $total) => round($total / max(1, $semanas), 1), static::porHora($instantes));
    }

    /**
     * Ranking por conta (loja ou motoboy): mais corridas primeiro, empate pelo nome. Linha sem uuid fica fora.
     *
     * @param array<int, object|array> $linhas
     *
     * @return array<int, array{uuid: string, nome: string, corridas: int}>
     */
    public static function ranking(array $linhas, string $campoUuid, string $campoNome): array
    {
        $grupos = [];
        foreach ($linhas as $linha) {
            $linha = (array) $linha;
            $uuid  = (string) ($linha[$campoUuid] ?? '');
            if ($uuid === '') {
                continue;
            }
            $grupos[$uuid] ??= ['uuid' => $uuid, 'nome' => '', 'corridas' => 0];
            $grupos[$uuid]['corridas']++;
            if ($grupos[$uuid]['nome'] === '' && !empty($linha[$campoNome])) {
                $grupos[$uuid]['nome'] = (string) $linha[$campoNome];
            }
        }

        $lista = array_values($grupos);
        usort($lista, fn ($a, $b) => [$b['corridas'], $a['nome']] <=> [$a['corridas'], $b['nome']]);

        return $lista;
    }

    /**
     * % entregue no prazo (só pedidos com prazo legível) e tempo médio do aceite à conclusão, em minutos.
     *
     * @param array<int, array{concluido_em: ?string, aceito_em: ?string, prazo_em: ?string}> $pedidos
     *
     * @return array{no_prazo: ?int, tempo_medio_min: ?int}
     */
    public static function prazos(array $pedidos, string $fuso): array
    {
        $zona     = new DateTimeZone($fuso);
        $comPrazo = 0;
        $noPrazo  = 0;
        $minutos  = [];

        foreach ($pedidos as $pedido) {
            $concluido = static::instante($pedido['concluido_em'] ?? null, $zona);
            if (!$concluido) {
                continue;
            }
            $prazo = static::instante($pedido['prazo_em'] ?? null, $zona);
            if ($prazo) {
                $comPrazo++;
                if ($concluido <= $prazo) {
                    $noPrazo++;
                }
            }
            $aceito = static::instante($pedido['aceito_em'] ?? null, $zona);
            if ($aceito && $aceito <= $concluido) {
                $minutos[] = ($concluido->getTimestamp() - $aceito->getTimestamp()) / 60;
            }
        }

        return [
            'no_prazo'        => $comPrazo ? (int) round($noPrazo / $comPrazo * 100) : null,
            'tempo_medio_min' => $minutos ? (int) round(array_sum($minutos) / count($minutos)) : null,
        ];
    }

    /** Texto → instante (com o fuso do texto, se tiver; senão o da empresa); null se vazio ou inválido. */
    private static function instante($valor, DateTimeZone $zona): ?DateTimeImmutable
    {
        if (!is_string($valor) || trim($valor) === '') {
            return null;
        }
        try {
            return new DateTimeImmutable(trim($valor), $zona);
        } catch (\Exception) {
            return null;
        }
    }
}
```

- [ ] **Passo 4: rodar e ver passar**

Rode o mesmo comando do Passo 2. Esperado: `FALHAS: 0`.

Rode também:
`PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/Painel/AgregadorDoPainel.php`

Esperado: `OK`.

- [ ] **Passo 5: commit**

```bash
git add api/app/Support/Entregas/Painel/AgregadorDoPainel.php scripts/teste-php/painel-inicial-agregador.php
git commit -m "Painel inicial: contas puras do resumo (período anterior, por hora/dia, ranking, prazos)"
```

---

### Tarefa 2: resumo do período (API)

**Arquivos:**
- Criar: `api/app/Support/Entregas/Painel/PainelInicial.php`
- Teste: `scripts/teste-php/painel-inicial.php`

Fontes:
- Corridas, hora e rankings: `entregas_corridas_finalizadas`. Tem `finalizada_em` com hora e não depende da chave do
  Financeiro.
- Dinheiro e km: as linhas `tipo='corrida'` não estornadas de `entregas_lancamentos`, somadas em centavos. Só com
  `Financeiro::ligada()`; dinheiro só para admin.
- Prazo e aceite: `orders` (`started_at ?: dispatched_at`, `meta.entregas_prazo_em`).

- [ ] **Passo 1: escrever o teste que falha**

`scripts/teste-php/painel-inicial.php`:

```php
<?php

// Resumo do período do painel inicial (App\Support\Entregas\Painel\PainelInicial).
// Uso: PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/painel-inicial.php

require __DIR__ . '/stubs-financeiro.php';

use App\Support\Entregas\Financeiro\Lancamentos;
use App\Support\Entregas\Painel\PainelInicial;
use Fleetbase\FleetOps\Models\Order;
use Teste\Banco;
use Teste\Relogio;

function corrida(string $pedido, string $finalizadaEm, ?string $loja, ?string $lojaNome, ?string $motoboy, ?string $motoboyNome, string $empresa = 'empresa-1'): void
{
    Banco::inserir('entregas_corridas_finalizadas', [
        'order_uuid' => 'uuid-' . $pedido, 'company_uuid' => $empresa, 'pedido' => $pedido, 'numero' => null,
        'loja_uuid' => $loja, 'loja_nome' => $lojaNome, 'motoboy_uuid' => $motoboy, 'motoboy_nome' => $motoboyNome,
        'finalizada_em' => $finalizadaEm, 'origem' => 'conclusao', 'created_at' => $finalizadaEm,
    ], false);
}

function lancamento(string $conta, string $contaTipo, string $tipo, float $valor, string $pedido, ?float $km = null): void
{
    Lancamentos::inserir([
        'company_uuid' => 'empresa-1', 'conta_tipo' => $contaTipo, 'conta_uuid' => $conta, 'tipo' => $tipo, 'valor' => $valor,
        'data_referencia' => '2026-10-24', 'order_uuid' => 'uuid-' . $pedido, 'chave_unica' => "{$tipo}:{$contaTipo}:{$pedido}",
        'detalhes' => ['pedido' => $pedido, 'numero' => $pedido, 'km' => $km],
    ]);
}

function cenario(): void
{
    reiniciarFinanceiro();
    Relogio::$agora = '2026-10-24 21:14:00';

    corrida('p1', '2026-10-24 19:10:00', 'vendor-a', 'Terraço', 'driver-1', 'Carlos');
    corrida('p2', '2026-10-24 19:40:00', 'vendor-a', 'Terraço', 'driver-2', 'Juliana');
    corrida('p3', '2026-10-24 20:05:00', 'vendor-b', 'Spunto', 'driver-1', 'Carlos');
    corrida('p4', '2026-10-25 00:10:00', 'vendor-a', 'Terraço', 'driver-1', 'Carlos');   // outro dia
    corrida('p5', '2026-10-17 19:30:00', 'vendor-a', 'Terraço', 'driver-1', 'Carlos');   // sábado passado
    corrida('p6', '2026-10-24 19:00:00', 'vendor-x', 'Outra', 'driver-x', 'Outro', 'empresa-2');

    Order::$todos[] = new Order(['uuid' => 'uuid-p1', 'public_id' => 'order_p1', 'company_uuid' => 'empresa-1', 'started_at' => '2026-10-24 18:40:00', 'dispatched_at' => '2026-10-24 18:35:00', 'meta' => ['entregas_prazo_em' => '2026-10-24T19:30:00-03:00']]);
    Order::$todos[] = new Order(['uuid' => 'uuid-p2', 'public_id' => 'order_p2', 'company_uuid' => 'empresa-1', 'started_at' => '2026-10-24 19:00:00', 'dispatched_at' => null, 'meta' => ['entregas_prazo_em' => '2026-10-24T19:30:00-03:00']]);
    Order::$todos[] = new Order(['uuid' => 'uuid-p3', 'public_id' => 'order_p3', 'company_uuid' => 'empresa-1', 'started_at' => null, 'dispatched_at' => '2026-10-24 19:35:00', 'meta' => []]);

    lancamento('vendor-a', 'loja', 'corrida', 14.00, 'p1');
    lancamento('vendor-a', 'loja', 'corrida', 14.00, 'p2');
    lancamento('vendor-b', 'loja', 'corrida', 15.50, 'p3');
    lancamento('driver-1', 'motoboy', 'corrida', 10.00, 'p1', 3.2);
    lancamento('driver-2', 'motoboy', 'corrida', 9.50, 'p2', 2.0);
    lancamento('driver-1', 'motoboy', 'corrida', 11.00, 'p3', 4.1);
    lancamento('driver-1', 'motoboy', 'diaria', 50.00, 'd1');                                // não é corrida
}

cenario();
$r = PainelInicial::montar('empresa-1', '2026-10-24', '2026-10-24', true);

confere($r['corridas'] === 3, 'conta só as corridas do dia e da central');
confere($r['corridas_anterior'] === 1 && $r['variacao'] === 200, 'compara com o sábado passado');
confere($r['no_prazo'] === 50 && $r['tempo_medio_min'] === 33, 'no prazo e tempo médio');
confere($r['faturado'] === 43.5 && $r['a_pagar'] === 30.5 && $r['margem'] === 13.0, 'faturado, a pagar e margem só de corridas');
confere($r['km'] === 9.3, 'km das corridas dos motoboys');
confere($r['grafico']['tipo'] === 'hora' && count($r['grafico']['barras']) === 24, 'gráfico por hora com 24 barras');
confere($r['grafico']['barras'][19]['total'] === 2 && $r['grafico']['barras'][19]['media'] === 0.3, 'hora 19: 2 hoje, média 1/4 = 0,3');
confere(array_column($r['lojas'], 'nome') === ['Terraço', 'Spunto'] && $r['lojas'][0]['faturado'] === 28.0, 'lojas com corridas e faturado');
confere(array_key_exists('ifood', $r['lojas'][0]) && $r['lojas'][0]['ifood'] === null, 'loja sem vínculo iFood: null');
confere(array_column($r['motoboys'], 'nome') === ['Carlos', 'Juliana'] && $r['motoboys'][0]['ganhos'] === 21.0, 'motoboys com corridas e ganhos');
confere(is_array($r['atencao']), 'admin recebe a lista Atenção');

// não admin: sem dinheiro e sem Atenção, com km e números
cenario();
$r = PainelInicial::montar('empresa-1', '2026-10-24', '2026-10-24', false);
confere($r['faturado'] === null && $r['a_pagar'] === null && $r['margem'] === null, 'não admin: sem dinheiro');
confere($r['lojas'][0]['faturado'] === null && $r['motoboys'][0]['ganhos'] === null, 'não admin: listas sem dinheiro');
confere($r['atencao'] === [] && $r['corridas'] === 3 && $r['km'] === 9.3, 'não admin: sem Atenção, com corridas e km');

// Financeiro desligado: sem dinheiro nem km, mesmo para admin
cenario();
\Teste\Config::$valores['services.entregas.financeiro'] = '0';
$r = PainelInicial::montar('empresa-1', '2026-10-24', '2026-10-24', true);
confere($r['faturado'] === null && $r['km'] === null && $r['corridas'] === 3, 'Financeiro desligado: só as contagens');

// 7 dias: gráfico por dia, sem média
cenario();
$r = PainelInicial::montar('empresa-1', '2026-10-18', '2026-10-24', true);
confere($r['grafico']['tipo'] === 'dia' && count($r['grafico']['barras']) === 7, '7 dias: uma barra por dia');
confere(end($r['grafico']['barras'])['total'] === 3 && end($r['grafico']['barras'])['media'] === null, 'último dia com 3, sem média');
confere($r['corridas'] === 3 && $r['corridas_anterior'] === 1 && $r['variacao'] === 200, '7 dias (18 a 24): o sábado 17 vai para os 7 anteriores');

// cache: a segunda chamada devolve o guardado (60 s)
cenario();
$primeira = PainelInicial::montar('empresa-1', '2026-10-24', '2026-10-24', true);
corrida('p7', '2026-10-24 21:00:00', 'vendor-a', 'Terraço', 'driver-1', 'Carlos');
confere(PainelInicial::montar('empresa-1', '2026-10-24', '2026-10-24', true)['corridas'] === $primeira['corridas'], 'resumo em cache por 60 s');

resumo();
```

- [ ] **Passo 2: rodar e ver falhar**

Rode:
`PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/painel-inicial.php`

Esperado: erro de classe `PainelInicial` não encontrada.

- [ ] **Passo 3: implementar**

`api/app/Support/Entregas/Painel/PainelInicial.php`:

```php
<?php

namespace App\Support\Entregas\Painel;

use App\Support\Entregas\Financeiro\Financeiro;
use App\Support\Entregas\Financeiro\Lancamentos;
use App\Support\Entregas\Financeiro\PainelFinanceiro;
use App\Support\Entregas\Ifood\VinculosIfood;
use App\Support\Entregas\PrazoDaEntrega;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entregas RestaurantePro: resumo do período do painel inicial do console (GET int/v1/entregas/painel-inicial).
 *
 * - Corridas, por hora/dia e rankings: entregas_corridas_finalizadas (tem a hora; independe da chave do Financeiro).
 * - Dinheiro e km: lançamentos tipo corrida não estornados (em centavos), só com o Financeiro ligado; dinheiro só para
 *   admin (null para os outros).
 * - No prazo e tempo médio: orders das corridas (aceite = started_at ?: dispatched_at; prazo = meta.entregas_prazo_em).
 *   Pedido sem prazo (antes de 2026-10-20) fica fora do %.
 * - Atenção: a lista do PainelFinanceiro (só admin), com cache de 5 min.
 * Cache do resumo inteiro: 60 s por central + período + admin (várias telas abertas dividem a conta).
 */
final class PainelInicial
{
    public const TABELA_CORRIDAS   = 'entregas_corridas_finalizadas';
    public const LIMITE_RANKING    = 6;
    public const SEMANAS_DA_MEDIA  = 4;
    public const LOTE              = 500;
    public const CACHE_RESUMO_S    = 60;
    public const CACHE_ATENCAO_S   = 300;

    public static function montar(string $companyUuid, string $inicio, string $fim, bool $admin): array
    {
        $chave = "entregas:painel-inicial:resumo:{$companyUuid}:{$inicio}:{$fim}:" . ($admin ? '1' : '0');
        $guardado = Cache::get($chave);
        if (is_array($guardado)) {
            return $guardado;
        }

        $resumo = static::calcular($companyUuid, $inicio, $fim, $admin);
        Cache::put($chave, $resumo, static::CACHE_RESUMO_S);

        return $resumo;
    }

    private static function calcular(string $co, string $inicio, string $fim, bool $admin): array
    {
        $corridas              = static::corridas($co, $inicio, $fim);
        [$deAntes, $ateAntes]  = AgregadorDoPainel::periodoAnterior($inicio, $fim);
        $anterior              = count(static::corridas($co, $deAntes, $ateAntes));
        $instantes             = array_map(fn ($corrida) => (string) $corrida->finalizada_em, $corridas);
        $livro                 = Financeiro::ligada() ? static::livro($co, $inicio, $fim) : null;
        $dinheiro              = $admin && $livro !== null;

        $lojas = array_slice(AgregadorDoPainel::ranking($corridas, 'loja_uuid', 'loja_nome'), 0, static::LIMITE_RANKING);
        $ifood = VinculosIfood::resumos(array_column($lojas, 'uuid'));
        $motoboys = array_slice(AgregadorDoPainel::ranking($corridas, 'motoboy_uuid', 'motoboy_nome'), 0, static::LIMITE_RANKING);

        return array_merge([
            'inicio'            => $inicio,
            'fim'               => $fim,
            'corridas'          => count($corridas),
            'corridas_anterior' => $anterior,
            'variacao'          => AgregadorDoPainel::variacao(count($corridas), $anterior),
        ], AgregadorDoPainel::prazos(static::pedidosParaPrazo($co, $corridas), Financeiro::fuso($co)), [
            'km'       => $livro['km'] ?? null,
            'faturado' => $dinheiro ? $livro['faturado'] : null,
            'a_pagar'  => $dinheiro ? $livro['a_pagar'] : null,
            'margem'   => $dinheiro ? round($livro['faturado'] - $livro['a_pagar'], 2) : null,
            'grafico'  => static::grafico($co, $inicio, $fim, $instantes),
            'lojas'    => array_map(fn (array $loja) => $loja + [
                'faturado' => $dinheiro ? round($livro['por_loja'][$loja['uuid']] ?? 0, 2) : null,
                'ifood'    => $ifood[$loja['uuid']]['situacao'] ?? null,
            ], $lojas),
            'motoboys' => array_map(fn (array $motoboy) => $motoboy + [
                'ganhos' => $dinheiro ? round($livro['por_motoboy'][$motoboy['uuid']] ?? 0, 2) : null,
            ], $motoboys),
            'atencao'  => $admin ? static::atencao($co, $inicio, $fim) : [],
        ]);
    }

    /** Corridas finalizadas da central no período (dias em Brasília). */
    private static function corridas(string $co, string $inicio, string $fim): array
    {
        return DB::table(static::TABELA_CORRIDAS)
            ->where('company_uuid', $co)
            ->where('finalizada_em', '>=', $inicio . ' 00:00:00')
            ->where('finalizada_em', '<=', $fim . ' 23:59:59')
            ->get()
            ->all();
    }

    /** Somas das corridas no livro do Financeiro (centavos → reais), por conta, e o km dos motoboys. */
    private static function livro(string $co, string $inicio, string $fim): array
    {
        $linhas = DB::table(Lancamentos::TABELA)
            ->where('company_uuid', $co)
            ->where('tipo', 'corrida')
            ->where('data_referencia', '>=', $inicio)
            ->where('data_referencia', '<=', $fim)
            ->get()
            ->all();
        $estornados = array_flip(Lancamentos::jaEstornados(array_map(fn ($linha) => (int) $linha->id, $linhas)));

        $centavos = [Financeiro::CONTA_LOJA => [], Financeiro::CONTA_MOTOBOY => []];
        $km       = 0.0;
        foreach ($linhas as $linha) {
            $tipo = (string) $linha->conta_tipo;
            if (isset($estornados[(int) $linha->id]) || !isset($centavos[$tipo])) {
                continue;
            }
            $conta                    = (string) $linha->conta_uuid;
            $centavos[$tipo][$conta]  = ($centavos[$tipo][$conta] ?? 0) + (int) round((float) $linha->valor * 100);
            if ($tipo === Financeiro::CONTA_MOTOBOY) {
                $detalhes = is_string($linha->detalhes) ? json_decode($linha->detalhes, true) : (array) $linha->detalhes;
                $km      += (float) ($detalhes['km'] ?? 0);
            }
        }

        return [
            'faturado'    => array_sum($centavos[Financeiro::CONTA_LOJA]) / 100,
            'a_pagar'     => array_sum($centavos[Financeiro::CONTA_MOTOBOY]) / 100,
            'km'          => round($km, 1),
            'por_loja'    => array_map(fn (int $c) => $c / 100, $centavos[Financeiro::CONTA_LOJA]),
            'por_motoboy' => array_map(fn (int $c) => $c / 100, $centavos[Financeiro::CONTA_MOTOBOY]),
        ];
    }

    /** Um dia: por hora, com a média do mesmo dia da semana nas 4 semanas antes. Vários dias: por dia, sem média. */
    private static function grafico(string $co, string $inicio, string $fim, array $instantes): array
    {
        if ($inicio !== $fim) {
            return ['tipo' => 'dia', 'barras' => array_map(
                fn (array $dia) => ['rotulo' => $dia['dia'], 'total' => $dia['total'], 'media' => null],
                AgregadorDoPainel::porDia($instantes, $inicio, $fim)
            )];
        }

        $antes = [];
        for ($semana = 1; $semana <= static::SEMANAS_DA_MEDIA; $semana++) {
            $dia = (new DateTimeImmutable($inicio))->modify('-' . (7 * $semana) . ' days')->format('Y-m-d');
            foreach (static::corridas($co, $dia, $dia) as $corrida) {
                $antes[] = (string) $corrida->finalizada_em;
            }
        }
        $hoje  = AgregadorDoPainel::porHora($instantes);
        $media = AgregadorDoPainel::mediaPorHora($antes, static::SEMANAS_DA_MEDIA);

        return ['tipo' => 'hora', 'barras' => array_map(
            fn (int $hora) => ['rotulo' => sprintf('%02d', $hora), 'total' => $hoje[$hora], 'media' => $media[$hora]],
            range(0, 23)
        )];
    }

    /** Conclusão, aceite e prazo dos pedidos das corridas (só as colunas usadas, em lotes). */
    private static function pedidosParaPrazo(string $co, array $corridas): array
    {
        $concluidoEm = [];
        foreach ($corridas as $corrida) {
            $concluidoEm[(string) $corrida->order_uuid] = (string) $corrida->finalizada_em;
        }

        $pedidos = [];
        foreach (array_chunk(array_keys($concluidoEm), static::LOTE) as $lote) {
            $linhas = DB::table('orders')
                ->select(['uuid', 'started_at', 'dispatched_at', 'meta'])
                ->where('company_uuid', $co)
                ->whereIn('uuid', $lote)
                ->get()
                ->all();
            foreach ($linhas as $pedido) {
                $pedidos[] = [
                    'concluido_em' => $concluidoEm[(string) $pedido->uuid] ?? null,
                    'aceito_em'    => static::texto($pedido->started_at ?? null) ?? static::texto($pedido->dispatched_at ?? null),
                    'prazo_em'     => PrazoDaEntrega::daMeta($pedido->meta ?? null),
                ];
            }
        }

        return $pedidos;
    }

    private static function texto($valor): ?string
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d H:i:s');
        }

        return is_string($valor) && $valor !== '' ? $valor : null;
    }

    /** A lista Atenção do Financeiro, com cache de 5 min (a conta do "sem valor" é a mais pesada do painel). */
    private static function atencao(string $co, string $inicio, string $fim): array
    {
        $chave    = "entregas:painel-inicial:atencao:{$co}";
        $guardado = Cache::get($chave);
        if (is_array($guardado)) {
            return $guardado;
        }

        try {
            $lista = PainelFinanceiro::montar($co, $inicio, $fim)['atencao'] ?? [];
        } catch (\Throwable $erro) {
            Log::warning('[entregas] painel inicial: sem a lista Atenção', ['erro' => $erro->getMessage()]);

            return [];
        }
        Cache::put($chave, $lista, static::CACHE_ATENCAO_S);

        return $lista;
    }
}
```

- [ ] **Passo 4: rodar e ver passar**

Rode o mesmo comando do Passo 2. Esperado: `FALHAS: 0`.

Se o caso "loja sem vínculo iFood" falhar porque o `VinculosIfood::resumos` não roda nos stubs, **não troque a regra**:
confira o `require` do teste (o `stubs-financeiro.php` carrega o `stubs-ifood.php`, que conhece a tabela
`entregas_ifood_lojas`).

Rode também:
`PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/Painel/PainelInicial.php`

- [ ] **Passo 5: commit**

```bash
git add api/app/Support/Entregas/Painel/PainelInicial.php scripts/teste-php/painel-inicial.php
git commit -m "Painel inicial: resumo do período (corridas, dinheiro, prazo, gráfico, lojas, motoboys, Atenção) com cache"
```

---

### Tarefa 3: ao vivo (API)

**Arquivos:**
- Criar: `api/app/Support/Entregas/Painel/AoVivo.php`
- Teste: `scripts/teste-php/painel-inicial-ao-vivo.php`

Base: `MapaDoLider::mapa($co)`, que devolve:
- os pedidos em andamento do `PedidosNoMapa::doLider`, com `id` (public_id), `numero`, `latitude`/`longitude` do
  destino, `endereco`, `status`, `motoboy`, `motoboy_id`, `aceito`, `criado_em`, `prazo_em`, `loja` e `coleta`;
- os motoboys do mapa, com `id`, `nome`, `latitude`, `longitude`, `situacao` e `online`.

Este passo acrescenta `ifood` (bool) e `oferta` (a distribuição do pedido sem motoboy).

Limite conhecido: pedido sem coordenada no destino não entra (é a regra do `PedidosNoMapa`; o mapa de Operações tem o
mesmo comportamento).

- [ ] **Passo 1: escrever o teste que falha**

`scripts/teste-php/painel-inicial-ao-vivo.php`:

```php
<?php

// Ao vivo do painel inicial (App\Support\Entregas\Painel\AoVivo): oferta da distribuição e origem iFood.
// Uso: PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/painel-inicial-ao-vivo.php

require __DIR__ . '/stubs-financeiro.php';

use App\Support\Entregas\Painel\AoVivo;
use Fleetbase\FleetOps\Models\Order;
use Teste\Banco;
use Teste\Relogio;

function mapaFalso(): array
{
    return [
        'pedidos' => [
            ['id' => 'order_1', 'numero' => '4821', 'motoboy_id' => 'driver_a', 'status' => 'enroute'],
            ['id' => 'order_2', 'numero' => '4836', 'motoboy_id' => null, 'status' => 'dispatched'],
            ['id' => 'order_3', 'numero' => 'RP12', 'motoboy_id' => null, 'status' => 'created'],
        ],
        'motoboys' => [['id' => 'driver_a', 'nome' => 'Carlos', 'latitude' => -21.1, 'longitude' => -47.8, 'situacao' => 'entrega', 'online' => true]],
    ];
}

function cenarioAoVivo(): void
{
    reiniciarFinanceiro();
    Relogio::$agora = '2026-10-24 21:14:00';
    Order::$todos[] = new Order(['uuid' => 'uuid-1', 'public_id' => 'order_1', 'company_uuid' => 'empresa-1']);
    Order::$todos[] = new Order(['uuid' => 'uuid-2', 'public_id' => 'order_2', 'company_uuid' => 'empresa-1']);
    Order::$todos[] = new Order(['uuid' => 'uuid-3', 'public_id' => 'order_3', 'company_uuid' => 'empresa-1']);
    Order::$todos[] = new Order(['uuid' => 'uuid-9', 'public_id' => 'order_1', 'company_uuid' => 'empresa-2']); // mesmo id, outra central
    Banco::inserir('entregas_ifood_pedidos', ['company_uuid' => 'empresa-1', 'order_uuid' => 'uuid-1', 'pedido_ifood_id' => 'if-1', 'numero' => '4821', 'merchant_id' => 'm-1'], false);
    Banco::inserir('entregas_distribuicoes', ['id' => 1, 'pedido_uuid' => 'uuid-2', 'company_uuid' => 'empresa-1', 'fase' => 'ofertas', 'rodada' => 2, 'volta' => 1], false);
    Banco::inserir('entregas_ofertas', ['distribuicao_id' => 1, 'pedido_uuid' => 'uuid-2', 'motoboy_uuid' => 'd-x', 'resposta' => 'recusada', 'vence_em' => '2026-10-24 21:13:00', 'rodada' => 1], false);
    Banco::inserir('entregas_ofertas', ['distribuicao_id' => 1, 'pedido_uuid' => 'uuid-2', 'motoboy_uuid' => 'd-y', 'resposta' => 'pendente', 'vence_em' => '2026-10-24 21:14:40', 'rodada' => 2], false);
}

cenarioAoVivo();
$r = AoVivo::montar('empresa-1', mapaFalso());
[$p1, $p2, $p3] = $r['pedidos'];

confere($p1['ifood'] === true && $p2['ifood'] === false, 'origem iFood pela tabela entregas_ifood_pedidos');
confere($p1['oferta'] === null, 'pedido com motoboy: sem oferta');
confere($p2['oferta']['fase'] === 'ofertas' && $p2['oferta']['rodada'] === 2, 'oferta em andamento com a rodada');
confere($p2['oferta']['vence_em'] === '2026-10-24T21:14:40-03:00', 'vencimento da oferta pendente em ISO de Brasília');
confere($p3['oferta'] === null, 'sem distribuição (aguardando a central): oferta nula');
confere($r['motoboys'][0]['nome'] === 'Carlos', 'motoboys do mapa passam como vieram');

// distribuição encerrada não conta
cenarioAoVivo();
Banco::$tabelas['entregas_distribuicoes'][0]['fase'] = 'encerrada';
confere(AoVivo::montar('empresa-1', mapaFalso())['pedidos'][1]['oferta'] === null, 'distribuição encerrada: sem oferta');

// cache de 5 s e o esquecer
cenarioAoVivo();
AoVivo::montar('empresa-1', mapaFalso());
$outro = ['pedidos' => [], 'motoboys' => []];
confere(count(AoVivo::montar('empresa-1', $outro)['pedidos']) === 3, 'ao vivo em cache');
AoVivo::esquecer('empresa-1');
confere(count(AoVivo::montar('empresa-1', $outro)['pedidos']) === 0, 'esquecer limpa o cache da central');

resumo();
```

> Se `Banco::$tabelas` não for público no stub, troque a linha da "encerrada" por um cenário que já insere
> `'fase' => 'encerrada'` (copie o `cenarioAoVivo` com esse valor).

- [ ] **Passo 2: rodar e ver falhar**

Rode:
`PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/painel-inicial-ao-vivo.php`

Esperado: erro de classe não encontrada.

- [ ] **Passo 3: implementar**

`api/app/Support/Entregas/Painel/AoVivo.php`:

```php
<?php

namespace App\Support\Entregas\Painel;

use App\Support\Entregas\Distribuicao\Distribuicao;
use App\Support\Entregas\Distribuicao\Distribuicoes;
use App\Support\Entregas\Financeiro\Financeiro;
use App\Support\Entregas\Ifood\PedidosIfood;
use App\Support\Entregas\MapaDoLider;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: seção "Ao vivo" do painel inicial do console (GET int/v1/entregas/painel-inicial/ao-vivo).
 *
 * Os pedidos em andamento e os motoboys do mapa são os do MapaDoLider (mesma lista do mapa do console e da aba Mapa do
 * líder). Acrescenta a cada pedido:
 * - ifood: o pedido veio do iFood (entregas_ifood_pedidos);
 * - oferta: no pedido sem motoboy, a distribuição em andamento (fase, rodada e o vencimento da oferta pendente, em ISO
 *   de Brasília). É null sem distribuição (aguardando a central) ou com ela encerrada.
 * Tudo em lote (3 consultas, qualquer que seja o número de pedidos) e com cache de 5 s por central: várias telas da
 * mesma central dividem a conta. O atribuir do painel chama esquecer().
 */
final class AoVivo
{
    public const CACHE_S = 5;

    /** @param array|null $mapa o MapaDoLider::mapa já montado (os testes passam um pronto) */
    public static function montar(string $companyUuid, ?array $mapa = null): array
    {
        $chave    = static::chave($companyUuid);
        $guardado = Cache::get($chave);
        if (is_array($guardado)) {
            return $guardado;
        }

        $resposta = static::calcular($companyUuid, $mapa ?? MapaDoLider::mapa($companyUuid));
        Cache::put($chave, $resposta, static::CACHE_S);

        return $resposta;
    }

    public static function esquecer(string $companyUuid): void
    {
        Cache::forget(static::chave($companyUuid));
    }

    private static function chave(string $companyUuid): string
    {
        return "entregas:painel-inicial:ao-vivo:{$companyUuid}";
    }

    private static function calcular(string $co, array $mapa): array
    {
        $pedidos = array_values($mapa['pedidos'] ?? []);
        $ids     = array_values(array_filter(array_map(fn ($pedido) => (string) ($pedido['id'] ?? ''), $pedidos)));

        $uuids = [];
        if ($ids) {
            $linhas = DB::table('orders')->select(['uuid', 'public_id'])->where('company_uuid', $co)->whereIn('public_id', $ids)->get()->all();
            foreach ($linhas as $linha) {
                $uuids[(string) $linha->public_id] = (string) $linha->uuid;
            }
        }

        $doIfood = [];
        if ($uuids) {
            foreach (DB::table(PedidosIfood::TABELA)->whereIn('order_uuid', array_values($uuids))->get()->all() as $linha) {
                $doIfood[(string) $linha->order_uuid] = true;
            }
        }

        $semMotoboy = [];
        foreach ($pedidos as $pedido) {
            $uuid = $uuids[(string) ($pedido['id'] ?? '')] ?? null;
            if ($uuid !== null && empty($pedido['motoboy_id'])) {
                $semMotoboy[] = $uuid;
            }
        }
        $ofertas = static::ofertas($co, $semMotoboy);

        foreach ($pedidos as &$pedido) {
            $uuid            = $uuids[(string) ($pedido['id'] ?? '')] ?? null;
            $pedido['ifood'] = $uuid !== null && isset($doIfood[$uuid]);
            $pedido['oferta'] = ($uuid !== null && empty($pedido['motoboy_id'])) ? ($ofertas[$uuid] ?? null) : null;
        }
        unset($pedido);

        return ['pedidos' => $pedidos, 'motoboys' => array_values($mapa['motoboys'] ?? [])];
    }

    /**
     * Distribuição aberta (ofertas ou aberta a todos) de cada pedido e o vencimento da oferta pendente: 2 consultas.
     *
     * @return array<string, array{fase: string, rodada: ?int, rodadas: bool, vence_em: ?string}>
     */
    private static function ofertas(string $co, array $pedidoUuids): array
    {
        if (!$pedidoUuids) {
            return [];
        }

        $abertas = DB::table(Distribuicoes::TABELA)
            ->where('company_uuid', $co)
            ->whereIn('pedido_uuid', $pedidoUuids)
            ->whereIn('fase', [Distribuicao::FASE_OFERTAS, Distribuicao::FASE_ABERTA])
            ->get()
            ->all();
        if (!$abertas) {
            return [];
        }

        $pendentes = [];
        $linhas    = DB::table(Distribuicoes::OFERTAS)->whereIn('pedido_uuid', $pedidoUuids)->where('resposta', Distribuicao::PENDENTE)->get()->all();
        foreach ($linhas as $oferta) {
            $pendentes[(string) $oferta->pedido_uuid] = $oferta;
        }

        $fuso    = Financeiro::fuso($co);
        $rodadas = Distribuicao::emRodadas();
        $saida   = [];
        foreach ($abertas as $distribuicao) {
            $uuid         = (string) $distribuicao->pedido_uuid;
            $pendente     = $pendentes[$uuid] ?? null;
            $saida[$uuid] = [
                'fase'     => (string) $distribuicao->fase,
                'rodada'   => isset($distribuicao->rodada) ? (int) $distribuicao->rodada : null,
                'rodadas'  => $rodadas,
                'vence_em' => $pendente ? static::iso($pendente->vence_em ?? null, $fuso) : null,
            ];
        }

        return $saida;
    }

    /** TIMESTAMP já em Brasília → ISO com o fuso ('2026-10-24T21:14:40-03:00'); null se vazio. */
    private static function iso($valor, string $fuso): ?string
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format(DATE_ATOM);
        }
        if (!is_string($valor) || $valor === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($valor, new DateTimeZone($fuso)))->format(DATE_ATOM);
        } catch (\Exception) {
            return null;
        }
    }
}
```

- [ ] **Passo 4: rodar e ver passar**

Rode o comando do Passo 2. Esperado: `FALHAS: 0`.

Rode também:
`PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Support/Entregas/Painel/AoVivo.php`

- [ ] **Passo 5: commit**

```bash
git add api/app/Support/Entregas/Painel/AoVivo.php scripts/teste-php/painel-inicial-ao-vivo.php
git commit -m "Painel inicial: ao vivo com a oferta da distribuição e a origem iFood, em lote e com cache de 5 s"
```

---

### Tarefa 4: controller e rotas (API)

**Arquivos:**
- Criar: `api/app/Http/Controllers/Entregas/PainelInicialController.php`
- Modificar: `api/app/Providers/RouteServiceProvider.php` (`use` no topo; 3 rotas no grupo `int/v1/entregas`)
- Teste: `scripts/teste-php/painel-inicial-console.php`

- [ ] **Passo 1: escrever o teste que falha**

`scripts/teste-php/painel-inicial-console.php`:

```php
<?php

// Controller do painel inicial (PainelInicialController) e as rotas no RouteServiceProvider.
// Uso: PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/painel-inicial-console.php

require __DIR__ . '/stubs-financeiro.php';

use App\Http\Controllers\Entregas\PainelInicialController;
use Teste\Banco;
use Teste\Relogio;

class UsuarioDoPainel
{
    public function __construct(public string $uuid, public bool $admin) {}
    public function isNotAdmin(): bool { return !$this->admin; }
}

function sessaoDoPainel(bool $admin): void
{
    \Fleetbase\Support\Auth::$usuario = new UsuarioDoPainel($admin ? 'user-admin' : 'user-comum', $admin);
    session(['company' => 'empresa-1', 'user' => $admin ? 'user-admin' : 'user-comum']);
}

function pedidoDoPainel(array $dados = []): \Illuminate\Http\Request
{
    return new \Illuminate\Http\Request($dados);
}

reiniciarFinanceiro();
Relogio::$agora = '2026-10-24 21:14:00';
Banco::inserir('entregas_corridas_finalizadas', [
    'order_uuid' => 'uuid-p1', 'company_uuid' => 'empresa-1', 'pedido' => 'order_p1', 'numero' => null, 'loja_uuid' => 'vendor-a',
    'loja_nome' => 'Terraço', 'motoboy_uuid' => 'driver-1', 'motoboy_nome' => 'Carlos', 'finalizada_em' => '2026-10-24 19:10:00',
    'origem' => 'conclusao', 'created_at' => '2026-10-24 19:10:00',
], false);

$controller = new PainelInicialController();

// resumo: sem datas = hoje (fuso da empresa)
sessaoDoPainel(false);
$resposta = $controller->resumo(pedidoDoPainel());
confere($resposta->status === 200 && $resposta->dados['inicio'] === '2026-10-24' && $resposta->dados['corridas'] === 1, 'resumo sem datas = hoje');
confere($resposta->dados['faturado'] === null && $resposta->dados['atencao'] === [], 'não admin: sem dinheiro e sem Atenção');

sessaoDoPainel(true);
confere($controller->resumo(pedidoDoPainel(['inicio' => '2026-10-24', 'fim' => '2026-10-24']))->status === 200, 'admin: resumo 200');
confere($controller->resumo(pedidoDoPainel(['inicio' => '2026-13-01', 'fim' => '2026-10-24']))->status === 422, 'data inválida → 422');
confere($controller->resumo(pedidoDoPainel(['inicio' => '2026-10-24', 'fim' => '2026-10-01']))->status === 422, 'período invertido → 422');
confere($controller->resumo(pedidoDoPainel(['inicio' => '2026-08-01', 'fim' => '2026-10-24']))->status === 422, 'mais de 31 dias → 422');

// atribuir: só admin; motoboy vazio → 422 vem do TrocaDoMotoboy (ou 404 sem o pedido)
sessaoDoPainel(false);
$negado = $controller->atribuir(pedidoDoPainel(['motoboy' => 'driver_1']), 'order_x');
confere($negado->status === 403 && str_contains((string) ($negado->dados['errors'][0] ?? ''), 'administradores'), 'atribuir: não admin → 403');

// rotas e use no provider
$provider = file_get_contents(__DIR__ . '/../../api/app/Providers/RouteServiceProvider.php');
confere(str_contains($provider, 'use App\Http\Controllers\Entregas\PainelInicialController;'), 'provider importa o controller');
confere(str_contains($provider, "Route::get('painel-inicial', [PainelInicialController::class, 'resumo']);"), 'rota do resumo');
confere(str_contains($provider, "Route::get('painel-inicial/ao-vivo', [PainelInicialController::class, 'aoVivo']);"), 'rota do ao vivo');
confere(str_contains($provider, "Route::post('painel-inicial/pedidos/{id}/motoboy', [PainelInicialController::class, 'atribuir']);"), 'rota do atribuir');

resumo();
```

- [ ] **Passo 2: rodar e ver falhar**

Rode:
`PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/painel-inicial-console.php`

Esperado: erro de classe `PainelInicialController` não encontrada.

- [ ] **Passo 3: implementar o controller**

`api/app/Http/Controllers/Entregas/PainelInicialController.php`:

```php
<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use App\Support\Entregas\Financeiro\Financeiro;
use App\Support\Entregas\Painel\AoVivo;
use App\Support\Entregas\Painel\PainelInicial;
use App\Support\Entregas\TrocaDoMotoboy;
use DateTimeImmutable;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;

/**
 * Entregas RestaurantePro: painel inicial do console (rota console.home, componente home/painel-inicial do Fleet-Ops).
 *
 * - resumo: números do período (até 31 dias; sem datas = hoje no fuso da empresa), gráfico, lojas, motoboys e Atenção.
 *   Dinheiro e Atenção só para admin (null / []).
 * - aoVivo: pedidos em andamento (com a oferta da distribuição e a origem iFood) e os motoboys do mapa; o console relê
 *   a cada 20 s e nos eventos do socket.
 * - atribuir: a central escolhe o motoboy de um pedido sem motoboy (só admin). Usa a mesma troca do líder
 *   (TrocaDoMotoboy: trava do pedido, adhoc falso, um aviso só ao motoboy; o log sai como "líder trocou o motoboy").
 */
class PainelInicialController extends Controller
{
    public const DIAS_MAXIMOS = 31;

    public function resumo(Request $request)
    {
        $empresa = $this->empresa();
        $hoje    = Financeiro::hoje($empresa);
        $inicio  = (string) ($request->input('inicio') ?: $hoje);
        $fim     = (string) ($request->input('fim') ?: $hoje);

        if (!static::dia($inicio) || !static::dia($fim)) {
            return $this->erro('Período inválido.');
        }
        if ($fim < $inicio) {
            return $this->erro('O fim do período precisa ser depois do início.');
        }
        if ((new DateTimeImmutable($inicio))->diff(new DateTimeImmutable($fim))->days >= static::DIAS_MAXIMOS) {
            return $this->erro('Escolha um período de até 31 dias.');
        }

        return response()->json(PainelInicial::montar($empresa, $inicio, $fim, $this->ehAdmin($request)));
    }

    public function aoVivo()
    {
        return response()->json(AoVivo::montar($this->empresa()));
    }

    public function atribuir(Request $request, string $id)
    {
        if (!$this->ehAdmin($request)) {
            return response()->json(['errors' => ['Somente administradores podem atribuir o motoboy pelo painel.']], 403);
        }

        $usuario           = Auth::getUserFromSession($request);
        [$status, $corpo]  = TrocaDoMotoboy::trocar($this->empresa(), $id, trim((string) $request->input('motoboy', '')), (string) ($usuario?->uuid ?? ''));
        if ($status === 200) {
            AoVivo::esquecer($this->empresa());
        }

        return response()->json($corpo, $status);
    }

    protected static function dia(string $dia): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia)
            && checkdate((int) substr($dia, 5, 2), (int) substr($dia, 8, 2), (int) substr($dia, 0, 4));
    }

    protected function ehAdmin(Request $request): bool
    {
        $usuario = Auth::getUserFromSession($request);

        return $usuario !== null && !$usuario->isNotAdmin();
    }

    protected function empresa(): string
    {
        return (string) session('company');
    }

    protected function erro(string $mensagem, int $status = 422)
    {
        return response()->json(['errors' => [$mensagem]], $status);
    }
}
```

- [ ] **Passo 4: registrar as rotas**

Em `api/app/Providers/RouteServiceProvider.php`, acrescente o `use` em ordem alfabética, logo depois de
`use App\Http\Controllers\Entregas\PagamentoMotoboysController;`:

```php
use App\Http\Controllers\Entregas\PainelInicialController;
```

No grupo `Route::prefix('int/v1/entregas')->middleware(['fleetbase.protected'])`, logo depois da linha
`Route::put('pagamento-motoboys/faixas-juntas', [PagamentoMotoboysController::class, 'salvarFaixasJuntas']);`:

```php

                        // Painel inicial do console (console.home → componente home/painel-inicial do Fleet-Ops): resumo do
                        // período, ao vivo (pedidos + motoboys, relido a cada 20 s) e atribuir motoboy (só admin)
                        Route::get('painel-inicial', [PainelInicialController::class, 'resumo']);
                        Route::get('painel-inicial/ao-vivo', [PainelInicialController::class, 'aoVivo']);
                        Route::post('painel-inicial/pedidos/{id}/motoboy', [PainelInicialController::class, 'atribuir']);
```

- [ ] **Passo 5: rodar e ver passar**

Rode:
```bash
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/painel-inicial-console.php
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/imports-dos-providers.php
PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/sintaxe.mjs api/app/Http/Controllers/Entregas/PainelInicialController.php api/app/Providers/RouteServiceProvider.php
```

Esperado: `FALHAS: 0` nos dois testes e `OK` na sintaxe.

- [ ] **Passo 6: commit**

```bash
git add api/app/Http/Controllers/Entregas/PainelInicialController.php api/app/Providers/RouteServiceProvider.php scripts/teste-php/painel-inicial-console.php
git commit -m "Painel inicial: rotas int/v1/entregas/painel-inicial (resumo, ao vivo, atribuir)"
```

---

### Tarefa 5: contas da tela (util puro do front)

**Arquivos:**
- Criar: `packages/fleetops/addon/utils/painel-inicial.js`
- Teste: `scripts/teste-portal/painel-inicial.test.mjs`

- [ ] **Passo 1: escrever o teste que falha**

`scripts/teste-portal/painel-inicial.test.mjs`:

```js
// Contas do painel inicial do console (packages/fleetops/addon/utils/painel-inicial.js).
// Uso: node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/painel-inicial.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    periodoDoPainel,
    saudacao,
    situacaoDoPedido,
    atrasado,
    contagens,
    filaDoPainel,
    resumoDosMotoboys,
    motoboysParaAtribuir,
    barrasDoGrafico,
    mostrarAlerta,
} from '../../packages/fleetops/addon/utils/painel-inicial.js';

const AGORA = Date.parse('2026-10-24T21:14:00-03:00');
const prazo = (min) => new Date(AGORA + min * 60000).toISOString();

test('periodoDoPainel: hoje, 7 e 30 dias em datas locais', () => {
    const hoje = new Date(2026, 9, 24, 23, 30);
    assert.deepEqual(periodoDoPainel('hoje', hoje), { inicio: '2026-10-24', fim: '2026-10-24' });
    assert.deepEqual(periodoDoPainel('semana', hoje), { inicio: '2026-10-18', fim: '2026-10-24' });
    assert.deepEqual(periodoDoPainel('mes', hoje), { inicio: '2026-09-25', fim: '2026-10-24' });
    assert.deepEqual(periodoDoPainel('qualquer', hoje), { inicio: '2026-10-24', fim: '2026-10-24' });
});

test('saudacao pela hora', () => {
    assert.equal(saudacao(3), 'boa-noite');
    assert.equal(saudacao(8), 'bom-dia');
    assert.equal(saudacao(13), 'boa-tarde');
    assert.equal(saudacao(21), 'boa-noite');
});

test('situacaoDoPedido', () => {
    assert.equal(situacaoDoPedido({ motoboy_id: null, status: 'dispatched' }), 'oferta');
    assert.equal(situacaoDoPedido({ motoboy_id: 'driver_a', status: 'started' }), 'loja');
    assert.equal(situacaoDoPedido({ motoboy_id: 'driver_a', status: 'ENROUTE' }), 'rota');
});

test('atrasado só com motoboy e prazo vencido', () => {
    assert.equal(atrasado({ motoboy_id: 'd', status: 'enroute', prazo_em: prazo(-3) }, AGORA), true);
    assert.equal(atrasado({ motoboy_id: 'd', status: 'enroute', prazo_em: prazo(10) }, AGORA), false);
    assert.equal(atrasado({ motoboy_id: null, status: 'dispatched', prazo_em: prazo(-3) }, AGORA), false);
    assert.equal(atrasado({ motoboy_id: 'd', status: 'enroute', prazo_em: null }, AGORA), false);
});

const PEDIDOS = [
    { id: 'a', motoboy_id: 'd1', status: 'enroute', prazo_em: prazo(20) },
    { id: 'b', motoboy_id: null, status: 'dispatched', criado_em: '2026-10-24T21:10:00-03:00' },
    { id: 'c', motoboy_id: 'd2', status: 'started', prazo_em: prazo(-8) },
    { id: 'd', motoboy_id: null, status: 'dispatched', criado_em: '2026-10-24T21:05:00-03:00' },
    { id: 'e', motoboy_id: 'd3', status: 'enroute', prazo_em: prazo(-2) },
    { id: 'f', motoboy_id: 'd4', status: 'started', prazo_em: null },
    { id: 'g', motoboy_id: 'd5', status: 'enroute', prazo_em: prazo(5) },
];

test('contagens', () => {
    assert.deepEqual(contagens(PEDIDOS, AGORA), { todos: 7, oferta: 2, loja: 2, rota: 3, atrasado: 2 });
});

test('filaDoPainel: ofertas (mais antiga antes), atrasados (mais atrasado antes), prazo, sem prazo', () => {
    assert.deepEqual(filaDoPainel(PEDIDOS, 'todos', AGORA).map((p) => p.id), ['d', 'b', 'c', 'e', 'g', 'a', 'f']);
    assert.deepEqual(filaDoPainel(PEDIDOS, 'atrasado', AGORA).map((p) => p.id), ['c', 'e']);
    assert.deepEqual(filaDoPainel(PEDIDOS, 'loja', AGORA).map((p) => p.id), ['c', 'f']);
});

test('resumoDosMotoboys', () => {
    const motoboys = [
        { online: true, situacao: 'livre' },
        { online: true, situacao: 'entrega' },
        { online: false, situacao: 'entrega' },
        { online: true, situacao: 'livre' },
    ];
    assert.deepEqual(resumoDosMotoboys(motoboys), { online: 3, livres: 2 });
});

test('motoboysParaAtribuir: só livres online, do mais perto da loja', () => {
    const coleta = { latitude: -21.17, longitude: -47.81 };
    const motoboys = [
        { id: 'longe', nome: 'Ana', online: true, situacao: 'livre', latitude: -21.25, longitude: -47.81 },
        { id: 'perto', nome: 'Bia', online: true, situacao: 'livre', latitude: -21.171, longitude: -47.81 },
        { id: 'ocupado', nome: 'Cid', online: true, situacao: 'coleta', latitude: -21.17, longitude: -47.81 },
        { id: 'off', nome: 'Duda', online: false, situacao: 'livre', latitude: -21.17, longitude: -47.81 },
    ];
    const lista = motoboysParaAtribuir(motoboys, coleta);
    assert.deepEqual(lista.map((m) => m.id), ['perto', 'longe']);
    assert.ok(lista[0].km < 0.2 && lista[1].km > 8);
    assert.deepEqual(motoboysParaAtribuir(motoboys, null).map((m) => m.id), ['longe', 'perto'], 'sem coleta: pelo nome');
});

test('barrasDoGrafico por hora: começa às 10h (ou antes, se houver), futuro sem barra', () => {
    const barras = Array.from({ length: 24 }, (_, h) => ({ rotulo: String(h).padStart(2, '0'), total: h === 9 ? 1 : h === 19 ? 4 : 0, media: h === 19 ? 2 : 0 }));
    const r = barrasDoGrafico({ tipo: 'hora', barras }, { hoje: true, horaAtual: 21 });
    assert.equal(r[0].rotuloCurto, '9h');
    assert.equal(r.length, 15);
    const b19 = r.find((b) => b.rotuloCurto === '19h');
    assert.equal(b19.altura, 100);
    assert.equal(b19.alturaMedia, 50);
    assert.equal(r.find((b) => b.rotuloCurto === '21h').agora, true);
    assert.equal(r.find((b) => b.rotuloCurto === '22h').futura, true);
});

test('barrasDoGrafico por dia', () => {
    const r = barrasDoGrafico({ tipo: 'dia', barras: [{ rotulo: '2026-10-23', total: 2, media: null }, { rotulo: '2026-10-24', total: 4, media: null }] });
    assert.deepEqual(r.map((b) => [b.rotuloCurto, b.altura, b.agora]), [['23/10', 50, false], ['24/10', 100, true]]);
    assert.deepEqual(barrasDoGrafico(null), []);
});

test('mostrarAlerta: atrasado ou 2+ sem motoboy', () => {
    assert.equal(mostrarAlerta({ oferta: 1, atrasado: 0 }), false);
    assert.equal(mostrarAlerta({ oferta: 2, atrasado: 0 }), true);
    assert.equal(mostrarAlerta({ oferta: 0, atrasado: 1 }), true);
});
```

- [ ] **Passo 2: rodar e ver falhar**

Rode:
`node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/painel-inicial.test.mjs`

Esperado: erro de módulo não encontrado.

- [ ] **Passo 3: implementar**

`packages/fleetops/addon/utils/painel-inicial.js`:

```js
// Entregas: contas do painel inicial do console (componente home/painel-inicial). Sem imports do Ember, para testar no
// Node (scripts/teste-portal/painel-inicial.test.mjs). As listas vêm de GET int/v1/entregas/painel-inicial/ao-vivo.
import { isoDate } from './financeiro';
import { cronometro } from './entregas-cronometro';

export const PERIODOS = ['hoje', 'semana', 'mes'];
export const FILTROS = ['todos', 'oferta', 'loja', 'rota', 'atrasado'];
// Mesma lista do App\Support\Entregas\SituacaoDoMotoboy::STATUS_EM_ENTREGA (capacete vermelho)
export const STATUS_EM_ENTREGA = ['enroute', 'picked_up', 'dropping_off', 'in_progress'];
// Barra vermelha do Ao vivo: algum atrasado ou tantos pedidos sem motoboy ao mesmo tempo
export const ALERTA_SEM_MOTOBOY = 2;

const DIAS_DO_PERIODO = { hoje: 1, semana: 7, mes: 30 };
const PRIMEIRA_HORA = 10;

/** { inicio, fim } em 'Y-m-d' local (Brasília) do período do seletor. */
export function periodoDoPainel(chave, hoje = new Date()) {
    const dias = DIAS_DO_PERIODO[chave] ?? 1;
    const inicio = new Date(hoje.getFullYear(), hoje.getMonth(), hoje.getDate() - (dias - 1));
    return { inicio: isoDate(inicio), fim: isoDate(hoje) };
}

export function saudacao(hora) {
    if (hora >= 5 && hora < 12) return 'bom-dia';
    if (hora >= 12 && hora < 18) return 'boa-tarde';
    return 'boa-noite';
}

/** oferta = sem motoboy; rota = levando ao cliente; loja = indo buscar (com motoboy, fora da entrega). */
export function situacaoDoPedido(pedido) {
    if (!pedido?.motoboy_id) return 'oferta';
    return STATUS_EM_ENTREGA.includes(String(pedido.status ?? '').toLowerCase()) ? 'rota' : 'loja';
}

export function atrasado(pedido, agora = Date.now()) {
    return situacaoDoPedido(pedido) !== 'oferta' && cronometro(pedido?.prazo_em, agora)?.estado === 'atrasado';
}

export function contagens(pedidos = [], agora = Date.now()) {
    const c = { todos: 0, oferta: 0, loja: 0, rota: 0, atrasado: 0 };
    for (const pedido of pedidos ?? []) {
        c.todos++;
        c[situacaoDoPedido(pedido)]++;
        if (atrasado(pedido, agora)) c.atrasado++;
    }
    return c;
}

/** Minutos até o prazo (negativo = atrasado), ou null sem prazo. */
function minutosAtePrazo(pedido, agora) {
    const prazo = Date.parse(pedido?.prazo_em ?? '');
    return Number.isFinite(prazo) ? (prazo - agora) / 60000 : null;
}

/**
 * Fila filtrada e ordenada: sem motoboy (o mais antigo antes), atrasados (o mais atrasado antes), com prazo (o mais
 * perto antes) e sem prazo no fim.
 */
export function filaDoPainel(pedidos = [], filtro = 'todos', agora = Date.now()) {
    const chave = (pedido) => {
        if (situacaoDoPedido(pedido) === 'oferta') return [0, Date.parse(pedido.criado_em ?? '') || 0];
        const minutos = minutosAtePrazo(pedido, agora);
        if (minutos === null) return [3, 0];
        return [minutos < 0 ? 1 : 2, minutos];
    };
    const passa = (pedido) => filtro === 'todos' || (filtro === 'atrasado' ? atrasado(pedido, agora) : situacaoDoPedido(pedido) === filtro);

    return (pedidos ?? [])
        .filter(passa)
        .map((pedido) => ({ pedido, chave: chave(pedido) }))
        .sort((a, b) => a.chave[0] - b.chave[0] || a.chave[1] - b.chave[1])
        .map(({ pedido }) => pedido);
}

export function resumoDosMotoboys(motoboys = []) {
    const online = (motoboys ?? []).filter((motoboy) => motoboy?.online);
    return { online: online.length, livres: online.filter((motoboy) => motoboy.situacao === 'livre').length };
}

/** Distância em km entre dois pontos { latitude, longitude } (haversine). */
export function distanciaKm(a, b) {
    const rad = (graus) => (graus * Math.PI) / 180;
    const dLat = rad(b.latitude - a.latitude);
    const dLng = rad(b.longitude - a.longitude);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.latitude)) * Math.cos(rad(b.latitude)) * Math.sin(dLng / 2) ** 2;
    return 6371 * 2 * Math.asin(Math.sqrt(h));
}

/** Motoboys livres e online para o Atribuir, do mais perto da loja (coleta) para o mais longe; sem coleta, pelo nome. */
export function motoboysParaAtribuir(motoboys = [], coleta = null) {
    const temColeta = Number.isFinite(coleta?.latitude) && Number.isFinite(coleta?.longitude);
    return (motoboys ?? [])
        .filter((motoboy) => motoboy?.online && motoboy.situacao === 'livre')
        .map((motoboy) => ({ ...motoboy, km: temColeta && Number.isFinite(motoboy.latitude) ? distanciaKm(coleta, motoboy) : null }))
        .sort((a, b) => (a.km ?? Infinity) - (b.km ?? Infinity) || String(a.nome ?? '').localeCompare(String(b.nome ?? ''), 'pt-BR'));
}

/**
 * Barras do gráfico em % da maior (total ou média). Por hora: das 10h (ou da primeira hora com corrida) às 23h, com a
 * hora atual marcada e as horas futuras de hoje sem barra. Por dia: o último dia marcado.
 */
export function barrasDoGrafico(grafico, { hoje = false, horaAtual = 23 } = {}) {
    const porHora = grafico?.tipo === 'hora';
    let barras = (grafico?.barras ?? []).map((barra, indice) => ({ ...barra, hora: porHora ? indice : null }));

    if (porHora) {
        const usadas = barras.filter((barra) => (barra.total ?? 0) > 0 || (barra.media ?? 0) > 0).map((barra) => barra.hora);
        const primeira = Math.min(PRIMEIRA_HORA, ...usadas);
        barras = barras.filter((barra) => barra.hora >= primeira);
    }

    const maior = Math.max(1, ...barras.map((barra) => Math.max(barra.total ?? 0, barra.media ?? 0)));
    return barras.map((barra, indice) => {
        const futura = porHora && hoje && barra.hora > horaAtual;
        const rotulo = String(barra.rotulo ?? '');
        return {
            rotulo,
            rotuloCurto: porHora ? `${barra.hora}h` : `${rotulo.slice(8, 10)}/${rotulo.slice(5, 7)}`,
            total: barra.total ?? 0,
            media: barra.media ?? null,
            altura: futura ? 0 : Math.round(((barra.total ?? 0) / maior) * 100),
            alturaMedia: barra.media === null || barra.media === undefined ? null : Math.round((barra.media / maior) * 100),
            agora: porHora ? hoje && barra.hora === horaAtual : indice === barras.length - 1,
            futura,
            mostrarRotulo: porHora ? barra.hora % 2 === 0 : barras.length <= 7 || indice % 5 === 0,
        };
    });
}

export function mostrarAlerta(contagem) {
    return (contagem?.atrasado ?? 0) > 0 || (contagem?.oferta ?? 0) >= ALERTA_SEM_MOTOBOY;
}
```

- [ ] **Passo 4: rodar e ver passar**

Rode o comando do Passo 2. Esperado: todos os testes `ok`.

Se `periodoDoPainel` falhar por fuso, confira se a máquina está em Brasília. O `isoDate` usa a data local de propósito
(é a regra do Financeiro).

- [ ] **Passo 5: commit**

```bash
git add packages/fleetops/addon/utils/painel-inicial.js scripts/teste-portal/painel-inicial.test.mjs
git commit -m "Painel inicial: contas da tela (período, fila, contagens, atribuir, barras) com teste no Node"
```

---

### Tarefa 6: traduções

**Arquivos:**
- Modificar: `packages/fleetops/translations/pt-br.yaml` e `packages/fleetops/translations/en-us.yaml`

As chaves ficam em `fleet-ops.ui.painel-inicial`.
- No `pt-br.yaml`, `ui:` tem 2 espaços e as chaves dele têm 4: insira o bloco logo **antes** da linha `    financeiro:`.
- No `en-us.yaml`, `ui:` tem 4 espaços e as chaves têm 8: insira o mesmo bloco antes da linha `        financeiro:`,
  **com 4 espaços a mais em cada linha**.

- [ ] **Passo 1: pt-br** (antes de `    financeiro:` em `pt-br.yaml`)

```yaml
    painel-inicial:
      saudacao:
        bom-dia: Bom dia
        boa-tarde: Boa tarde
        boa-noite: Boa noite
      periodo:
        rotulo: Período
        hoje: Hoje
        semana: 7 dias
        mes: 30 dias
      novo-pedido: Novo pedido
      cartoes:
        corridas: Corridas
        faturado: Faturado das lojas
        a-pagar: A pagar aos motoboys
        margem: Margem
        no-prazo: No prazo
        tempo-medio: Tempo médio
        variacao: '{variacao}% sobre {base}'
        base-hoje: o mesmo dia da semana passada
        base-semana: os 7 dias anteriores
        base-mes: os 30 dias anteriores
        sem-comparacao: sem base para comparar
        por-corrida: '{valor} por corrida'
        km: '{km} km rodados'
        do-faturado: '{pct}% do faturado'
        meta: 'meta: 90%'
        minutos: '{n} min'
        do-aceite: do aceite à entrega
      ao-vivo:
        titulo: Ao vivo
        agora: agora
        online: '{online} motoboys online · {livres} livres'
        pedidos: Pedidos em andamento
        ver-operacoes: Ver em Operações →
        mapa: Mapa
        tela-cheia: Tela cheia →
      filtros:
        todos: Todos
        oferta: Sem motoboy
        loja: Indo à loja
        rota: A caminho
        atrasado: Atrasados
      vazio:
        todos: Nenhum pedido em andamento
        oferta: Todos os pedidos têm motoboy
        loja: Ninguém indo à loja agora
        rota: Nenhum pedido a caminho
        atrasado: Nenhum pedido atrasado
      situacao:
        loja: Indo à loja
        rota: A caminho
      oferta:
        rodada: 'Oferta · rodada {rodada}/3 · {tempo}'
        simples: 'Oferta · {tempo}'
        aberta: Aberto a todos
        aguardando: Aguardando a central
      alerta:
        titulo: '{oferta} sem motoboy · {atrasado} atrasados'
        texto: '{livres} motoboys livres agora. A distribuição continua oferecendo; você pode atribuir na mão.'
        ver: Ver agora
      atribuir:
        botao: Atribuir
        enviar: Enviar
        ninguem-livre: Nenhum motoboy livre agora.
        nota: A distribuição continua oferecendo até você escolher.
        enviado: Motoboy atribuído.
        km: '{km} km da loja'
        sem-local: sem posição da loja
      legenda:
        livre: livre
        loja: indo à loja
        rota: a caminho
        cliente: cliente
      grafico:
        hora: Corridas por hora
        dia: Corridas por dia
        atual: no período
        media: média das 4 semanas anteriores
        dica: '{rotulo}: {total} corridas'
      lojas:
        titulo: Lojas
        todas: Todas →
        ifood-vinculada: iFood vinculado
        ifood-perdido: Vínculo do iFood perdido
        sem-ifood: Sem iFood
      motoboys:
        titulo: Motoboys
        todos: Todos →
      atencao:
        titulo: Atenção
        itens: '{n} itens'
        nada: Nada pendente no Financeiro.
        financeiro: Financeiro →
      sem-corridas: Nenhuma corrida no período.
```

- [ ] **Passo 2: en-us** (antes de `        financeiro:` em `en-us.yaml`, com 4 espaços a mais em cada linha)

```yaml
        painel-inicial:
          saudacao:
            bom-dia: Good morning
            boa-tarde: Good afternoon
            boa-noite: Good evening
          periodo:
            rotulo: Period
            hoje: Today
            semana: 7 days
            mes: 30 days
          novo-pedido: New order
          cartoes:
            corridas: Deliveries
            faturado: Billed to stores
            a-pagar: Owed to drivers
            margem: Margin
            no-prazo: On time
            tempo-medio: Average time
            variacao: '{variacao}% vs {base}'
            base-hoje: the same weekday last week
            base-semana: the previous 7 days
            base-mes: the previous 30 days
            sem-comparacao: nothing to compare with
            por-corrida: '{valor} per delivery'
            km: '{km} km driven'
            do-faturado: '{pct}% of billed'
            meta: 'goal: 90%'
            minutos: '{n} min'
            do-aceite: from acceptance to delivery
          ao-vivo:
            titulo: Live
            agora: now
            online: '{online} drivers online · {livres} free'
            pedidos: Orders in progress
            ver-operacoes: See in Operations →
            mapa: Map
            tela-cheia: Full screen →
          filtros:
            todos: All
            oferta: No driver
            loja: Going to store
            rota: On the way
            atrasado: Late
          vazio:
            todos: No orders in progress
            oferta: Every order has a driver
            loja: Nobody going to a store now
            rota: No orders on the way
            atrasado: No late orders
          situacao:
            loja: Going to store
            rota: On the way
          oferta:
            rodada: 'Offer · round {rodada}/3 · {tempo}'
            simples: 'Offer · {tempo}'
            aberta: Open to everyone
            aguardando: Waiting for dispatch
          alerta:
            titulo: '{oferta} without driver · {atrasado} late'
            texto: '{livres} drivers free now. Dispatch keeps offering; you can assign by hand.'
            ver: See now
          atribuir:
            botao: Assign
            enviar: Send
            ninguem-livre: No free driver right now.
            nota: Dispatch keeps offering until you choose.
            enviado: Driver assigned.
            km: '{km} km from the store'
            sem-local: store location unknown
          legenda:
            livre: free
            loja: going to store
            rota: on the way
            cliente: customer
          grafico:
            hora: Deliveries per hour
            dia: Deliveries per day
            atual: in the period
            media: average of the previous 4 weeks
            dica: '{rotulo}: {total} deliveries'
          lojas:
            titulo: Stores
            todas: All →
            ifood-vinculada: iFood linked
            ifood-perdido: iFood link lost
            sem-ifood: No iFood
          motoboys:
            titulo: Drivers
            todos: All →
          atencao:
            titulo: Attention
            itens: '{n} items'
            nada: Nothing pending in Finance.
            financeiro: Finance →
          sem-corridas: No deliveries in the period.
```

- [ ] **Passo 3: conferir**

Rode:
`node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal`

Esperado: exit 0, com os dois YAML abrindo sem erro. As chaves ainda não estão em uso; isso não é erro.

- [ ] **Passo 4: commit**

```bash
git add packages/fleetops/translations/pt-br.yaml packages/fleetops/translations/en-us.yaml
git commit -m "Painel inicial: textos em pt-BR e en-US"
```

---

### Tarefa 7: o mapa do Ao vivo (componente)

**Arquivos:**
- Criar: `packages/fleetops/addon/components/home/painel-inicial/mapa.js`
- Criar: `packages/fleetops/addon/components/home/painel-inicial/mapa.hbs`
- Criar: `packages/fleetops/app/components/home/painel-inicial/mapa.js`

Argumentos:
- `@motoboys`: `{id, nome, latitude, longitude, situacao}`;
- `@pedidos`: `{id, latitude, longitude, numero}`;
- `@lojas`: modelos `place` do `entregas/mapa/locais-de-coleta`;
- `@aoAbrirPedido(id)`.

Segue o widget `live-fleet`, que já roda na home: `ensureLeafletDrawEditNamespace` e o tile do `mapSettings`. Usa o
`<layers.marker>` simples (o `tracking-marker` não precisa).

- [ ] **Passo 1: o JS**

`packages/fleetops/addon/components/home/painel-inicial/mapa.js`:

```js
import Component from '@glimmer/component';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { CAPACETES } from '../../../utils/entregas-capacete';
import { ICONE_DA_LOJA } from '../../../utils/entregas-icone-da-loja';
import { ALFINETE } from '../../../utils/entregas-pedidos-no-mapa';
import ensureLeafletDrawEditNamespace from '../../../utils/leaflet-draw-namespace-guard';

// Ribeirão Preto: o centro até chegar o primeiro motoboy ou pedido
const CENTRO_PADRAO = { lat: -21.1775, lng: -47.8103 };

/**
 * Entregas: mapa do Ao vivo do painel inicial (home/painel-inicial). Capacete na cor da situação com o nome fixo, pin
 * laranja das lojas e alfinete vermelho no destino (clique abre o pedido). Enquadra uma vez, quando chegam os primeiros
 * pontos; depois quem mexe é a pessoa. Sem zoom na rolagem (a página rola por cima do mapa).
 */
export default class HomePainelInicialMapaComponent extends Component {
    @service mapSettings;

    centro = CENTRO_PADRAO;
    loja = ICONE_DA_LOJA;
    alfinete = ALFINETE;
    mapa = null;
    enquadrado = false;

    constructor() {
        super(...arguments);
        ensureLeafletDrawEditNamespace();
    }

    get tileUrl() {
        const escuro = typeof document !== 'undefined' && document.body?.dataset?.theme === 'dark';
        return this.mapSettings.getLeafletTileUrl(escuro ? 'dark' : 'light');
    }

    get motoboys() {
        return (this.args.motoboys ?? [])
            .filter((motoboy) => Number.isFinite(motoboy?.latitude) && Number.isFinite(motoboy?.longitude))
            .map((motoboy) => ({ ...motoboy, capacete: CAPACETES[motoboy.situacao] ?? CAPACETES.offline }));
    }

    get pedidos() {
        return (this.args.pedidos ?? []).filter((pedido) => Number.isFinite(pedido?.latitude) && Number.isFinite(pedido?.longitude));
    }

    @action aoCarregar(evento) {
        this.mapa = evento?.target ?? null;
        if (!this.mapa) return;
        this.observador = new ResizeObserver(() => this.mapa?.invalidateSize());
        this.observador.observe(this.mapa.getContainer());
        this.enquadrar();
    }

    @action enquadrar() {
        if (!this.mapa || this.enquadrado) return;
        const pontos = [...this.motoboys.map((m) => [m.latitude, m.longitude]), ...this.pedidos.map((p) => [p.latitude, p.longitude])];
        if (!pontos.length) return;
        this.mapa.fitBounds(pontos, { padding: [30, 30], maxZoom: 14 });
        this.enquadrado = true;
    }

    @action abrir(id) {
        this.args.aoAbrirPedido?.(id);
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this.observador?.disconnect();
        this.mapa = null;
    }
}
```

- [ ] **Passo 2: o template**

`packages/fleetops/addon/components/home/painel-inicial/mapa.hbs`:

```hbs
<div class="pi-mapa {{@class}}" {{did-update this.enquadrar @motoboys @pedidos}}>
    <LeafletMap
        class="w-full h-full"
        @lat={{this.centro.lat}}
        @lng={{this.centro.lng}}
        @zoom={{12}}
        @zoomControl={{true}}
        @scrollWheelZoom={{false}}
        @onLoad={{this.aoCarregar}}
        as |layers|
    >
        <layers.tile @url={{this.tileUrl}} />

        {{#each @lojas key="id" as |loja|}}
            <layers.marker
                @location={{point-to-coordinates loja.location}}
                @icon={{icon iconUrl=this.loja.url iconSize=this.loja.tamanho iconAnchor=this.loja.ponta}}
                as |marker|
            >
                <marker.tooltip @permanent={{true}} @direction="bottom" @className="pi-rotulo pi-rotulo-loja">{{loja.name}}</marker.tooltip>
            </layers.marker>
        {{/each}}

        {{#each this.pedidos key="id" as |pedido|}}
            <layers.marker
                @lat={{pedido.latitude}}
                @lng={{pedido.longitude}}
                @icon={{icon iconUrl=this.alfinete.url iconSize=this.alfinete.tamanho iconAnchor=this.alfinete.ponta}}
                @onClick={{fn this.abrir pedido.id}}
            />
        {{/each}}

        {{#each this.motoboys key="id" as |motoboy|}}
            <layers.marker
                @lat={{motoboy.latitude}}
                @lng={{motoboy.longitude}}
                @zIndexOffset={{1000}}
                @icon={{icon iconUrl=motoboy.capacete iconSize=(array 32 32) tooltipAnchor=(array 0 12)}}
                as |marker|
            >
                <marker.tooltip @permanent={{true}} @direction="bottom" @className="pi-rotulo">{{motoboy.nome}}</marker.tooltip>
            </layers.marker>
        {{/each}}
    </LeafletMap>
</div>
```

- [ ] **Passo 3: reexport**

`packages/fleetops/app/components/home/painel-inicial/mapa.js`:

```js
export { default } from '@fleetbase/fleetops-engine/components/home/painel-inicial/mapa';
```

- [ ] **Passo 4: parse do JS**

Rode na raiz (Git Bash):

```bash
node -e "const p=require(require('path').resolve(require('fs').readdirSync('console/node_modules/.pnpm').filter(d=>d.startsWith('@babel+parser@7')).map(d=>'console/node_modules/.pnpm/'+d+'/node_modules/@babel/parser')[0]));for(const f of process.argv.slice(1)){p.parse(require('fs').readFileSync(f,'utf8'),{sourceType:'module',plugins:['decorators-legacy','classProperties','classPrivateProperties','classPrivateMethods']});console.log('OK',f)}" packages/fleetops/addon/components/home/painel-inicial/mapa.js packages/fleetops/app/components/home/painel-inicial/mapa.js
```

Esperado: `OK` nos dois.

- [ ] **Passo 5: commit**

```bash
git add packages/fleetops/addon/components/home/painel-inicial/mapa.js packages/fleetops/addon/components/home/painel-inicial/mapa.hbs packages/fleetops/app/components/home/painel-inicial/mapa.js
git commit -m "Painel inicial: mapa do Ao vivo com capacetes, lojas e alfinetes"
```

---

### Tarefa 8: o painel (componente)

**Arquivos:**
- Criar: `packages/fleetops/addon/components/home/painel-inicial.js`
- Criar: `packages/fleetops/addon/components/home/painel-inicial.hbs`
- Criar: `packages/fleetops/app/components/home/painel-inicial.js`

- [ ] **Passo 1: o JS**

`packages/fleetops/addon/components/home/painel-inicial.js`:

```js
import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import escutarCanalDaEmpresa from '../../utils/escutar-canal-da-empresa';
import { formatadores, itemDeAtencao } from '../../utils/financeiro';
import { cronometro } from '../../utils/entregas-cronometro';
import { CAPACETES } from '../../utils/entregas-capacete';
import { segundosRestantes } from '../../utils/distribuicao';
import {
    PERIODOS,
    FILTROS,
    periodoDoPainel,
    saudacao,
    situacaoDoPedido,
    contagens,
    filaDoPainel,
    resumoDosMotoboys,
    motoboysParaAtribuir,
    barrasDoGrafico,
    mostrarAlerta,
} from '../../utils/painel-inicial';

const INTERVALO_AO_VIVO_MS = 20000;
const INTERVALO_RESUMO_MS = 5 * 60000;
const ESPERA_DO_SOCKET_MS = 800;
const EVENTOS_AO_VIVO = /^(order\.|waypoint\.|entity\.|driver\.(created|updated|deleted)$|entregas\.motoboy_online$)/;
const ROTA_DO_PEDIDO = 'console.fleet-ops.operations.orders.index.details';
const P = 'fleet-ops.ui.painel-inicial';

/**
 * Entregas: painel inicial do console (rota console.home; registrado em console:home:before-dashboard pelo extension.js).
 * Variante A do sketch 004: números do período, Ao vivo (fila + mapa), corridas por hora/dia, lojas, motoboys e Atenção.
 * Dados:
 * - GET entregas/painel-inicial (ao abrir, ao trocar o período e a cada 5 min);
 * - GET entregas/painel-inicial/ao-vivo (a cada 20 s e 0,8 s depois dos eventos do socket);
 * - GET entregas/mapa/locais-de-coleta (uma vez).
 * Com a aba escondida, nada é consultado.
 * Roda no contexto do console (owner do host): o roteador é o `router`, com o nome completo da rota.
 */
export default class HomePainelInicialComponent extends Component {
    @service fetch;
    @service intl;
    @service currentUser;
    @service socket;
    @service router;
    @service notifications;

    @tracked periodo = 'hoje';
    @tracked filtro = 'todos';
    @tracked resumo = null;
    @tracked aoVivo = { pedidos: [], motoboys: [] };
    @tracked lojasDoMapa = [];
    @tracked atribuindo = null;
    @tracked agora = Date.now();

    capaceteLivre = CAPACETES.livre;
    esqueleto = [1, 2, 3, 4, 5, 6];

    constructor() {
        super(...arguments);
        this.carregarResumo.perform();
        this.carregarAoVivo.perform();
        this.carregarLojas.perform();
        this._intervaloAoVivo = setInterval(() => this.#seVisivel(() => this.carregarAoVivo.perform()), INTERVALO_AO_VIVO_MS);
        this._intervaloResumo = setInterval(() => this.#seVisivel(() => this.carregarResumo.perform()), INTERVALO_RESUMO_MS);
        // 1 s: o relógio das ofertas e do cronômetro (só troca o tracked; nada vai ao servidor)
        this._tique = setInterval(() => this.#seVisivel(() => (this.agora = Date.now())), 1000);
        this._escuta = escutarCanalDaEmpresa({
            socket: this.socket,
            currentUser: this.currentUser,
            aoReceber: (mensagem) => {
                if (EVENTOS_AO_VIVO.test(String(mensagem?.event ?? ''))) this.#agendarAoVivo();
            },
        });
    }

    willDestroy() {
        super.willDestroy(...arguments);
        clearInterval(this._intervaloAoVivo);
        clearInterval(this._intervaloResumo);
        clearInterval(this._tique);
        clearTimeout(this._espera);
        this._escuta?.parar();
    }

    #seVisivel(fazer) {
        if (typeof document === 'undefined' || !document.hidden) fazer();
    }

    #agendarAoVivo() {
        clearTimeout(this._espera);
        this._espera = setTimeout(() => this.#seVisivel(() => this.carregarAoVivo.perform()), ESPERA_DO_SOCKET_MS);
    }

    get f() {
        return formatadores(this.intl.primaryLocale);
    }

    get ehAdmin() {
        return this.currentUser.isAdmin === true;
    }

    /* ---------- cabeçalho ---------- */

    get saudacao() {
        const textos = { 'bom-dia': `${P}.saudacao.bom-dia`, 'boa-tarde': `${P}.saudacao.boa-tarde`, 'boa-noite': `${P}.saudacao.boa-noite` };
        const texto = this.intl.t(textos[saudacao(new Date(this.agora).getHours())]);
        const nome = String(this.currentUser.name ?? '').trim().split(/\s+/)[0];
        return nome ? `${texto}, ${nome}` : texto;
    }

    get dataDeHoje() {
        const texto = new Intl.DateTimeFormat(this.intl.primaryLocale, { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'America/Sao_Paulo' }).format(this.agora);
        const hora = new Intl.DateTimeFormat(this.intl.primaryLocale, { hour: '2-digit', minute: '2-digit', timeZone: 'America/Sao_Paulo' }).format(this.agora);
        return `${texto.charAt(0).toUpperCase()}${texto.slice(1)} · ${hora}`;
    }

    get periodos() {
        const textos = { hoje: this.intl.t(`${P}.periodo.hoje`), semana: this.intl.t(`${P}.periodo.semana`), mes: this.intl.t(`${P}.periodo.mes`) };
        return PERIODOS.map((chave) => ({ chave, texto: textos[chave], ativo: chave === this.periodo }));
    }

    /* ---------- cartões ---------- */

    get cartoes() {
        const r = this.resumo;
        if (!r) return [];
        const f = this.f;
        const t = (chave, opcoes) => this.intl.t(`${P}.cartoes.${chave}`, opcoes);
        const bases = { hoje: t('base-hoje'), semana: t('base-semana'), mes: t('base-mes') };
        const lista = [
            {
                chave: 'corridas',
                icone: 'motorcycle',
                rotulo: t('corridas'),
                valor: String(r.corridas),
                detalhe: r.variacao === null ? t('sem-comparacao') : t('variacao', { variacao: `${r.variacao > 0 ? '+' : ''}${r.variacao}`, base: bases[this.periodo] }),
                destaque: true,
            },
        ];
        if (this.ehAdmin) {
            lista.push(
                { chave: 'faturado', icone: 'store', rotulo: t('faturado'), valor: r.faturado === null ? '—' : f.valor(r.faturado), detalhe: r.faturado && r.corridas ? t('por-corrida', { valor: f.valor(r.faturado / r.corridas) }) : '' },
                { chave: 'a-pagar', icone: 'sack-dollar', rotulo: t('a-pagar'), valor: r.a_pagar === null ? '—' : f.valor(r.a_pagar), detalhe: r.km === null ? '' : t('km', { km: f.km(r.km) }) },
                { chave: 'margem', icone: 'chart-line', rotulo: t('margem'), valor: r.margem === null ? '—' : f.valor(r.margem), detalhe: r.margem !== null && r.faturado ? t('do-faturado', { pct: f.km((r.margem / r.faturado) * 100).replace(/,(\d)\d$/, ',$1') }) : '' }
            );
        }
        lista.push(
            { chave: 'no-prazo', icone: 'clock', rotulo: t('no-prazo'), valor: r.no_prazo === null ? '—' : `${r.no_prazo}%`, detalhe: t('meta'), alerta: r.no_prazo !== null && r.no_prazo < 90 },
            { chave: 'tempo-medio', icone: 'stopwatch', rotulo: t('tempo-medio'), valor: r.tempo_medio_min === null ? '—' : t('minutos', { n: r.tempo_medio_min }), detalhe: t('do-aceite') }
        );
        return lista;
    }

    /* ---------- ao vivo ---------- */

    get contagem() {
        return contagens(this.aoVivo.pedidos, this.agora);
    }

    get motoboysOnline() {
        return resumoDosMotoboys(this.aoVivo.motoboys);
    }

    get textoOnline() {
        return this.intl.t(`${P}.ao-vivo.online`, this.motoboysOnline);
    }

    get alerta() {
        return mostrarAlerta(this.contagem);
    }

    get textoDoAlerta() {
        return {
            titulo: this.intl.t(`${P}.alerta.titulo`, { oferta: this.contagem.oferta, atrasado: this.contagem.atrasado }),
            texto: this.intl.t(`${P}.alerta.texto`, { livres: this.motoboysOnline.livres }),
        };
    }

    get filtros() {
        const c = this.contagem;
        const textos = {
            todos: this.intl.t(`${P}.filtros.todos`),
            oferta: this.intl.t(`${P}.filtros.oferta`),
            loja: this.intl.t(`${P}.filtros.loja`),
            rota: this.intl.t(`${P}.filtros.rota`),
            atrasado: this.intl.t(`${P}.filtros.atrasado`),
        };
        return FILTROS.map((chave) => ({ chave, texto: textos[chave], n: c[chave], ativo: this.filtro === chave, alerta: (chave === 'oferta' || chave === 'atrasado') && c[chave] > 0 }));
    }

    get textoDoVazio() {
        const textos = {
            todos: this.intl.t(`${P}.vazio.todos`),
            oferta: this.intl.t(`${P}.vazio.oferta`),
            loja: this.intl.t(`${P}.vazio.loja`),
            rota: this.intl.t(`${P}.vazio.rota`),
            atrasado: this.intl.t(`${P}.vazio.atrasado`),
        };
        return textos[this.filtro];
    }

    get textosDoCronometro() {
        return {
            faltam: (n) => this.intl.t('fleet-ops.ui.cronometro.faltam', { n }),
            atrasado: (n) => this.intl.t('fleet-ops.ui.cronometro.atrasado', { n }),
        };
    }

    get pedidos() {
        return filaDoPainel(this.aoVivo.pedidos, this.filtro, this.agora).map((pedido) => this.#linha(pedido));
    }

    #linha(pedido) {
        const situacao = situacaoDoPedido(pedido);
        const prazo = situacao === 'oferta' ? null : cronometro(pedido.prazo_em, this.agora, this.textosDoCronometro);
        const aberto = this.atribuindo === pedido.id;
        return {
            ...pedido,
            situacao,
            ehOferta: situacao === 'oferta',
            numeroTexto: pedido.ifood ? `#${pedido.numero}` : pedido.numero,
            capacete: CAPACETES[situacao === 'oferta' ? 'offline' : situacao === 'rota' ? 'entrega' : 'coleta'],
            textoDaSituacao: situacao === 'rota' ? this.intl.t(`${P}.situacao.rota`) : this.intl.t(`${P}.situacao.loja`),
            textoDaOferta: situacao === 'oferta' ? this.#textoDaOferta(pedido) : '',
            prazo,
            atrasado: prazo?.estado === 'atrasado',
            aberto,
            candidatos: aberto ? this.#candidatos(pedido) : [],
        };
    }

    #textoDaOferta(pedido) {
        const oferta = pedido.oferta;
        if (!oferta) return this.intl.t(`${P}.oferta.aguardando`);
        if (oferta.fase === 'aberta' || !oferta.vence_em) return this.intl.t(`${P}.oferta.aberta`);
        const segundos = segundosRestantes(oferta.vence_em, this.agora) ?? 0;
        const tempo = `${Math.floor(segundos / 60)}:${String(segundos % 60).padStart(2, '0')}`;
        return oferta.rodadas && oferta.rodada ? this.intl.t(`${P}.oferta.rodada`, { rodada: oferta.rodada, tempo }) : this.intl.t(`${P}.oferta.simples`, { tempo });
    }

    #candidatos(pedido) {
        return motoboysParaAtribuir(this.aoVivo.motoboys, pedido.coleta).map((motoboy) => ({
            ...motoboy,
            kmTexto: motoboy.km === null ? this.intl.t(`${P}.atribuir.sem-local`) : this.intl.t(`${P}.atribuir.km`, { km: this.f.km(motoboy.km).replace(/,(\d)\d$/, ',$1') }),
        }));
    }

    /* ---------- gráfico, lojas, motoboys, atenção ---------- */

    get tituloDoGrafico() {
        return this.resumo?.grafico?.tipo === 'dia' ? this.intl.t(`${P}.grafico.dia`) : this.intl.t(`${P}.grafico.hora`);
    }

    get barras() {
        return barrasDoGrafico(this.resumo?.grafico, { hoje: this.periodo === 'hoje', horaAtual: new Date(this.agora).getHours() }).map((barra) => ({
            ...barra,
            dica: this.intl.t(`${P}.grafico.dica`, { rotulo: barra.rotuloCurto, total: barra.total }),
        }));
    }

    get temMedia() {
        return this.barras.some((barra) => barra.alturaMedia !== null);
    }

    get lojas() {
        const lista = this.resumo?.lojas ?? [];
        const maior = Math.max(1, ...lista.map((loja) => loja.corridas));
        const textos = { vinculada: this.intl.t(`${P}.lojas.ifood-vinculada`), vinculo_perdido: this.intl.t(`${P}.lojas.ifood-perdido`) };
        return lista.map((loja) => ({
            ...loja,
            largura: Math.round((loja.corridas / maior) * 100),
            ifoodTexto: textos[loja.ifood] ?? this.intl.t(`${P}.lojas.sem-ifood`),
            ifoodClasse: loja.ifood === 'vinculada' ? 'pi-ifood-ok' : loja.ifood === 'vinculo_perdido' ? 'pi-ifood-erro' : 'pi-ifood-nao',
            faturadoTexto: loja.faturado === null ? '' : this.f.valor(loja.faturado),
        }));
    }

    get motoboysDoPeriodo() {
        return (this.resumo?.motoboys ?? []).map((motoboy, indice) => ({ ...motoboy, posicao: indice + 1, ganhosTexto: motoboy.ganhos === null ? '' : this.f.valor(motoboy.ganhos) }));
    }

    get atencao() {
        return (this.resumo?.atencao ?? [])
            .map((item) => itemDeAtencao(item, this.f))
            .filter(Boolean)
            .map((item) => ({ ...item, texto: this.intl.t(item.chave, item.params) }));
    }

    get textoDosItens() {
        return this.intl.t(`${P}.atencao.itens`, { n: this.atencao.length });
    }

    /* ---------- ações ---------- */

    @action escolherPeriodo(chave) {
        if (this.periodo === chave) return;
        this.periodo = chave;
        this.carregarResumo.perform();
    }

    @action escolherFiltro(chave) {
        this.filtro = this.filtro === chave && chave !== 'todos' ? 'todos' : chave;
    }

    @action abrirPedido(id) {
        this.router.transitionTo(ROTA_DO_PEDIDO, id);
    }

    @action novoPedido() {
        this.router.transitionTo('console.fleet-ops.operations.orders.index.new');
    }

    @action abrirOperacoes() {
        this.router.transitionTo('console.fleet-ops.operations.orders.index', { queryParams: { layout: 'map' } });
    }

    @action abrirFinanceiro() {
        this.router.transitionTo('console.fleet-ops.financeiro.painel');
    }

    @action abrirLojas() {
        this.router.transitionTo('console.fleet-ops.management.lojas');
    }

    @action abrirMotoboys() {
        this.router.transitionTo('console.fleet-ops.financeiro.motoboys');
    }

    @action abrirAtencao(item) {
        if (item?.rota && item?.modelo) this.router.transitionTo(`console.fleet-ops.${item.rota}`, item.modelo);
    }

    @action alternarAtribuir(id, evento) {
        evento?.stopPropagation?.();
        this.atribuindo = this.atribuindo === id ? null : id;
    }

    @action enviar(pedidoId, motoboyId, evento) {
        evento?.stopPropagation?.();
        this.atribuir.perform(pedidoId, motoboyId);
    }

    /* ---------- dados ---------- */

    @task({ restartable: true }) *carregarResumo() {
        const { inicio, fim } = periodoDoPainel(this.periodo, new Date());
        try {
            this.resumo = yield this.fetch.get('entregas/painel-inicial', { inicio, fim });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ restartable: true }) *carregarAoVivo() {
        try {
            const resposta = yield this.fetch.get('entregas/painel-inicial/ao-vivo');
            this.aoVivo = { pedidos: resposta?.pedidos ?? [], motoboys: resposta?.motoboys ?? [] };
        } catch {
            // silencioso: o próximo ciclo (20 s) ou o próximo evento do socket tenta de novo
        }
    }

    @task *carregarLojas() {
        try {
            this.lojasDoMapa = yield this.fetch.get('entregas/mapa/locais-de-coleta', {}, { normalizeToEmberData: true, normalizeModelType: 'place' });
        } catch {
            this.lojasDoMapa = [];
        }
    }

    @task({ drop: true }) *atribuir(pedidoId, motoboyId) {
        try {
            yield this.fetch.post(`entregas/painel-inicial/pedidos/${pedidoId}/motoboy`, { motoboy: motoboyId });
            this.atribuindo = null;
            this.notifications.success(this.intl.t(`${P}.atribuir.enviado`));
            yield this.carregarAoVivo.perform();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
```

> As rotas `management.lojas` e `financeiro.motoboys` existem em `packages/fleetops/addon/routes.js` (conferido em
> 2026-10-25).

- [ ] **Passo 2: o template**

`packages/fleetops/addon/components/home/painel-inicial.hbs`:

```hbs
<div class="painel-inicial" ...attributes>
    <header class="pi-cabecalho">
        <div>
            <h1 class="pi-titulo">{{this.saudacao}}</h1>
            <p class="pi-sub">{{this.dataDeHoje}}{{#if this.currentUser.companyName}} · {{this.currentUser.companyName}}{{/if}}</p>
        </div>
        <div class="pi-acoes">
            <div class="pi-seg" role="group" aria-label={{t "fleet-ops.ui.painel-inicial.periodo.rotulo"}}>
                {{#each this.periodos as |periodo|}}
                    <button type="button" class={{if periodo.ativo "pi-ativo"}} aria-pressed={{if periodo.ativo "true" "false"}} {{on "click" (fn this.escolherPeriodo periodo.chave)}}>{{periodo.texto}}</button>
                {{/each}}
            </div>
            <button type="button" class="pi-botao pi-botao-marca" {{on "click" this.novoPedido}}><FaIcon @icon="plus" /> {{t "fleet-ops.ui.painel-inicial.novo-pedido"}}</button>
        </div>
    </header>

    <div class="pi-cartoes">
        {{#each this.cartoes as |cartao|}}
            <div class="pi-cartao {{if cartao.destaque 'pi-destaque'}} {{if cartao.alerta 'pi-alerta'}}">
                <div class="pi-cartao-rotulo"><FaIcon @icon={{cartao.icone}} /> {{cartao.rotulo}}</div>
                <div class="pi-cartao-valor">{{cartao.valor}}</div>
                <div class="pi-cartao-detalhe">{{cartao.detalhe}}</div>
            </div>
        {{else}}
            {{#each this.esqueleto}}<div class="pi-cartao pi-esqueleto"></div>{{/each}}
        {{/each}}
    </div>

    <div class="pi-secao">
        <h2>{{t "fleet-ops.ui.painel-inicial.ao-vivo.titulo"}}</h2>
        <span class="pi-ao-vivo"><i></i>{{t "fleet-ops.ui.painel-inicial.ao-vivo.agora"}}</span>
        <span class="pi-linha-fina"></span>
        <span class="pi-sub">{{this.textoOnline}}</span>
    </div>

    {{#if this.alerta}}
        <div class="pi-alerta-faixa" role="alert">
            <FaIcon @icon="triangle-exclamation" />
            <div><b>{{this.textoDoAlerta.titulo}}</b><span>{{this.textoDoAlerta.texto}}</span></div>
            <button type="button" class="pi-botao" {{on "click" (fn this.escolherFiltro "oferta")}}>{{t "fleet-ops.ui.painel-inicial.alerta.ver"}}</button>
        </div>
    {{/if}}

    <div class="pi-grade-ao-vivo">
        <section class="pi-card pi-fila">
            <div class="pi-card-cabecalho">
                <h3>{{t "fleet-ops.ui.painel-inicial.ao-vivo.pedidos"}}</h3>
                <button type="button" class="pi-link" {{on "click" this.abrirOperacoes}}>{{t "fleet-ops.ui.painel-inicial.ao-vivo.ver-operacoes"}}</button>
            </div>
            <div class="pi-filtros">
                {{#each this.filtros as |filtro|}}
                    <button type="button" class="{{if filtro.ativo 'pi-ativo'}} {{if filtro.alerta 'pi-com-alerta'}}" {{on "click" (fn this.escolherFiltro filtro.chave)}}>{{filtro.texto}} <b>{{filtro.n}}</b></button>
                {{/each}}
            </div>
            <div class="pi-lista">
                {{#each this.pedidos key="id" as |pedido|}}
                    <div class="pi-pedido {{if pedido.ehOferta 'pi-oferta'}} {{if pedido.atrasado 'pi-atrasado'}}">
                        <button type="button" class="pi-pedido-principal" {{on "click" (fn this.abrirPedido pedido.id)}}>
                            <img class="pi-capacete" src={{pedido.capacete}} alt="" />
                            <span class="pi-pedido-meio">
                                <span class="pi-pedido-topo">
                                    <b class="pi-numero">{{pedido.numeroTexto}}</b>
                                    {{#if pedido.ifood}}<span class="pi-selo pi-selo-ifood">iFood</span>{{/if}}
                                    <span class="pi-loja">{{pedido.loja}}</span>
                                </span>
                                <span class="pi-pedido-baixo">{{pedido.endereco}}</span>
                            </span>
                            <span class="pi-pedido-situacao">
                                {{#if pedido.ehOferta}}
                                    <span class="pi-texto-oferta"><FaIcon @icon="tower-broadcast" /> {{pedido.textoDaOferta}}</span>
                                {{else}}
                                    {{pedido.textoDaSituacao}}<small>{{pedido.motoboy}}</small>
                                {{/if}}
                            </span>
                        </button>
                        {{#if pedido.ehOferta}}
                            {{#if this.ehAdmin}}
                                <button type="button" class="pi-botao pi-botao-marca pi-pequeno" aria-expanded={{if pedido.aberto "true" "false"}} {{on "click" (fn this.alternarAtribuir pedido.id)}}><FaIcon @icon="user-plus" /> {{t "fleet-ops.ui.painel-inicial.atribuir.botao"}}</button>
                            {{/if}}
                        {{else if pedido.prazo}}
                            <span class="pi-prazo pi-prazo-{{pedido.prazo.estado}}"><FaIcon @icon="clock" /> {{pedido.prazo.texto}}</span>
                        {{/if}}
                        {{#if pedido.aberto}}
                            <div class="pi-candidatos">
                                {{#each pedido.candidatos key="id" as |motoboy|}}
                                    <div class="pi-candidato">
                                        <img src={{this.capaceteLivre}} alt="" />
                                        <span><b>{{motoboy.nome}}</b><small>{{motoboy.kmTexto}}</small></span>
                                        <button type="button" class="pi-botao pi-botao-marca pi-pequeno" disabled={{this.atribuir.isRunning}} {{on "click" (fn this.enviar pedido.id motoboy.id)}}>{{t "fleet-ops.ui.painel-inicial.atribuir.enviar"}}</button>
                                    </div>
                                {{else}}
                                    <p class="pi-nota">{{t "fleet-ops.ui.painel-inicial.atribuir.ninguem-livre"}}</p>
                                {{/each}}
                                <p class="pi-nota"><FaIcon @icon="circle-info" /> {{t "fleet-ops.ui.painel-inicial.atribuir.nota"}}</p>
                            </div>
                        {{/if}}
                    </div>
                {{else}}
                    <div class="pi-vazio"><FaIcon @icon="circle-check" /> {{this.textoDoVazio}}</div>
                {{/each}}
            </div>
        </section>

        <section class="pi-card">
            <div class="pi-card-cabecalho">
                <h3>{{t "fleet-ops.ui.painel-inicial.ao-vivo.mapa"}}</h3>
                <span class="pi-legenda">
                    <span><img src={{this.capaceteLivre}} alt="" />{{t "fleet-ops.ui.painel-inicial.legenda.livre"}}</span>
                    <span><span class="pi-bolinha pi-bolinha-loja"></span>{{t "fleet-ops.ui.painel-inicial.legenda.loja"}}</span>
                    <span><span class="pi-bolinha pi-bolinha-rota"></span>{{t "fleet-ops.ui.painel-inicial.legenda.rota"}}</span>
                    <span><FaIcon @icon="location-dot" class="pi-cor-cliente" />{{t "fleet-ops.ui.painel-inicial.legenda.cliente"}}</span>
                </span>
                <button type="button" class="pi-link" {{on "click" this.abrirOperacoes}}>{{t "fleet-ops.ui.painel-inicial.ao-vivo.tela-cheia"}}</button>
            </div>
            <Home::PainelInicial::Mapa class="pi-mapa-altura" @motoboys={{this.aoVivo.motoboys}} @pedidos={{this.aoVivo.pedidos}} @lojas={{this.lojasDoMapa}} @aoAbrirPedido={{this.abrirPedido}} />
        </section>
    </div>

    <div class="pi-grade-baixo">
        <section class="pi-card">
            <div class="pi-card-cabecalho"><h3>{{this.tituloDoGrafico}}</h3><span class="pi-sub">{{t "fleet-ops.ui.painel-inicial.grafico.atual"}}</span></div>
            {{#if this.barras.length}}
                <div class="pi-grafico">
                    {{#each this.barras as |barra|}}
                        <div class="pi-coluna" title={{barra.dica}}>
                            <div class="pi-caixa">
                                {{#unless barra.futura}}<div class="pi-barra {{if barra.agora 'pi-agora'}}" style={{html-safe (concat "height:" barra.altura "%")}}></div>{{/unless}}
                                {{#if barra.alturaMedia}}<div class="pi-media" style={{html-safe (concat "bottom:" barra.alturaMedia "%")}}></div>{{/if}}
                            </div>
                            <span class="pi-rotulo-hora">{{if barra.mostrarRotulo barra.rotuloCurto ""}}</span>
                        </div>
                    {{/each}}
                </div>
                <div class="pi-legenda pi-legenda-grafico">
                    <span><span class="pi-amostra"></span>{{t "fleet-ops.ui.painel-inicial.grafico.atual"}}</span>
                    {{#if this.temMedia}}<span><span class="pi-amostra pi-amostra-media"></span>{{t "fleet-ops.ui.painel-inicial.grafico.media"}}</span>{{/if}}
                </div>
            {{else}}
                <div class="pi-vazio">{{t "fleet-ops.ui.painel-inicial.sem-corridas"}}</div>
            {{/if}}
        </section>

        <section class="pi-card">
            <div class="pi-card-cabecalho">
                <h3>{{t "fleet-ops.ui.painel-inicial.lojas.titulo"}}</h3>
                {{#if this.ehAdmin}}<button type="button" class="pi-link" {{on "click" this.abrirLojas}}>{{t "fleet-ops.ui.painel-inicial.lojas.todas"}}</button>{{/if}}
            </div>
            <div class="pi-ranking">
                {{#each this.lojas key="uuid" as |loja|}}
                    <div class="pi-item">
                        <div class="pi-item-nome">
                            {{loja.nome}}
                            <small class="pi-ifood {{loja.ifoodClasse}}"><i></i>{{loja.ifoodTexto}}</small>
                            <div class="pi-mini"><i style={{html-safe (concat "width:" loja.largura "%")}}></i></div>
                        </div>
                        <div class="pi-item-valor"><b>{{loja.corridas}}</b><small>{{loja.faturadoTexto}}</small></div>
                    </div>
                {{else}}
                    <div class="pi-vazio">{{t "fleet-ops.ui.painel-inicial.sem-corridas"}}</div>
                {{/each}}
            </div>
        </section>

        <section class="pi-card">
            <div class="pi-card-cabecalho">
                <h3>{{t "fleet-ops.ui.painel-inicial.motoboys.titulo"}}</h3>
                {{#if this.ehAdmin}}<button type="button" class="pi-link" {{on "click" this.abrirMotoboys}}>{{t "fleet-ops.ui.painel-inicial.motoboys.todos"}}</button>{{/if}}
            </div>
            <div class="pi-ranking">
                {{#each this.motoboysDoPeriodo key="uuid" as |motoboy|}}
                    <div class="pi-item">
                        <span class="pi-posicao">{{motoboy.posicao}}</span>
                        <div class="pi-item-nome">{{motoboy.nome}}</div>
                        <div class="pi-item-valor"><b>{{motoboy.corridas}}</b><small>{{motoboy.ganhosTexto}}</small></div>
                    </div>
                {{else}}
                    <div class="pi-vazio">{{t "fleet-ops.ui.painel-inicial.sem-corridas"}}</div>
                {{/each}}
            </div>
        </section>
    </div>

    {{#if this.ehAdmin}}
        <section class="pi-card">
            <div class="pi-card-cabecalho">
                <h3>{{t "fleet-ops.ui.painel-inicial.atencao.titulo"}}</h3>
                <span class="pi-sub">{{this.textoDosItens}}</span>
                <button type="button" class="pi-link" {{on "click" this.abrirFinanceiro}}>{{t "fleet-ops.ui.painel-inicial.atencao.financeiro"}}</button>
            </div>
            <div class="pi-atencao">
                {{#each this.atencao as |item|}}
                    <button type="button" class="pi-atencao-item" {{on "click" (fn this.abrirAtencao item)}}>
                        <FaIcon @icon={{item.icone}} class={{item.cor}} />
                        <span>{{item.texto}}</span>
                    </button>
                {{else}}
                    <div class="pi-vazio"><FaIcon @icon="circle-check" /> {{t "fleet-ops.ui.painel-inicial.atencao.nada"}}</div>
                {{/each}}
            </div>
        </section>
    {{/if}}
</div>
```

- [ ] **Passo 3: reexport**

`packages/fleetops/app/components/home/painel-inicial.js`:

```js
export { default } from '@fleetbase/fleetops-engine/components/home/painel-inicial';
```

- [ ] **Passo 4: conferir**

Rode o parse da Tarefa 7, Passo 4, trocando os arquivos por
`packages/fleetops/addon/components/home/painel-inicial.js packages/fleetops/app/components/home/painel-inicial.js`.

Depois rode o `i18n-check`, que agora encontra as chaves usadas nos templates e nos `intl.t`. Esperado: exit 0.

As chaves montadas com crase (`${P}.…`) não são vistas pelo i18n-check. Confira à mão que **todas** existem nos dois
YAML (Tarefa 6).

- [ ] **Passo 5: commit**

```bash
git add packages/fleetops/addon/components/home/painel-inicial.js packages/fleetops/addon/components/home/painel-inicial.hbs packages/fleetops/app/components/home/painel-inicial.js
git commit -m "Painel inicial: componente com números, ao vivo, atribuir, gráfico, lojas, motoboys e Atenção"
```

---

### Tarefa 9: estilo (marca Entregas, claro e escuro, celular)

**Arquivos:**
- Criar: `console/app/styles/painel-inicial.css`
- Modificar: `console/app/styles/app.css` (import depois de `entregas-telas.css`)

- [ ] **Passo 1: o CSS**

`console/app/styles/painel-inicial.css`:

```css
/* Entregas: painel inicial do console (home/painel-inicial do Fleet-Ops; variante A do sketch 004). Tokens da marca
   (landing.css) com versão escura; no celular, os mesmos blocos empilhados. Fica no console porque o CSS do engine só
   carrega com o engine. */
.painel-inicial {
    --pi-fundo: oklch(0.975 0.01 75);
    --pi-cartao: oklch(0.995 0.004 80);
    --pi-cartao-2: oklch(0.955 0.013 75);
    --pi-linha: oklch(0.89 0.014 70);
    --pi-tinta: oklch(0.2 0.014 60);
    --pi-tinta-2: oklch(0.36 0.014 60);
    --pi-apagado: oklch(0.52 0.014 60);
    --pi-laranja: oklch(0.74 0.165 62);
    --pi-laranja-forte: oklch(0.68 0.17 56);
    --pi-sobre-laranja: oklch(0.27 0.05 55);
    --pi-laranja-texto: oklch(0.5 0.14 50);
    --pi-verde: oklch(0.6 0.15 150);
    --pi-verde-claro: oklch(0.95 0.04 150);
    --pi-amarelo: oklch(0.8 0.15 85);
    --pi-amarelo-texto: oklch(0.5 0.11 70);
    --pi-amarelo-claro: oklch(0.96 0.05 90);
    --pi-vermelho: oklch(0.57 0.2 27);
    --pi-vermelho-claro: oklch(0.95 0.03 27);
    --pi-display: 'Barlow Condensed', 'Arial Narrow', sans-serif;
    --pi-corpo: 'Barlow', system-ui, sans-serif;

    background: var(--pi-fundo);
    color: var(--pi-tinta);
    font-family: var(--pi-corpo);
    font-size: 14px;
    line-height: 1.4;
    padding: 22px 24px 40px;
    display: flex;
    flex-direction: column;
    gap: 18px;
    min-height: 100%;
}
body[data-theme='dark'] .painel-inicial {
    --pi-fundo: oklch(0.18 0.012 60);
    --pi-cartao: oklch(0.22 0.013 60);
    --pi-cartao-2: oklch(0.26 0.014 60);
    --pi-linha: oklch(0.33 0.014 60);
    --pi-tinta: oklch(0.95 0.01 75);
    --pi-tinta-2: oklch(0.83 0.012 70);
    --pi-apagado: oklch(0.67 0.012 65);
    --pi-sobre-laranja: oklch(0.22 0.05 55);
    --pi-laranja-texto: oklch(0.8 0.14 66);
    --pi-verde: oklch(0.72 0.15 150);
    --pi-verde-claro: oklch(0.3 0.05 150);
    --pi-amarelo-texto: oklch(0.85 0.13 85);
    --pi-amarelo-claro: oklch(0.32 0.06 85);
    --pi-vermelho: oklch(0.65 0.2 27);
    --pi-vermelho-claro: oklch(0.3 0.07 27);
}
.painel-inicial h1,
.painel-inicial h2,
.painel-inicial h3 { margin: 0; }
.painel-inicial button { font: inherit; color: inherit; cursor: pointer; }

/* cabeçalho */
.painel-inicial .pi-cabecalho { display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap; }
.painel-inicial .pi-titulo { font-family: var(--pi-display); font-size: 34px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.01em; line-height: 1; }
.painel-inicial .pi-sub { color: var(--pi-apagado); font-size: 13px; margin: 4px 0 0; }
.painel-inicial .pi-acoes { margin-left: auto; display: flex; gap: 10px; align-items: center; }
.painel-inicial .pi-seg { display: inline-flex; background: var(--pi-cartao-2); border: 1px solid var(--pi-linha); border-radius: 10px; padding: 3px; }
.painel-inicial .pi-seg button { border: 0; background: transparent; padding: 5px 12px; border-radius: 7px; font-weight: 600; color: var(--pi-tinta-2); font-size: 13px; }
.painel-inicial .pi-seg button.pi-ativo { background: var(--pi-cartao); color: var(--pi-tinta); box-shadow: 0 1px 2px oklch(0.2 0.02 60 / 10%); }
.painel-inicial .pi-botao { border: 0; border-radius: 10px; padding: 9px 14px; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; white-space: nowrap; transition: background 0.15s ease; background: var(--pi-cartao); color: var(--pi-tinta); }
.painel-inicial .pi-botao-marca { background: var(--pi-laranja); color: var(--pi-sobre-laranja); }
.painel-inicial .pi-botao-marca:hover { background: var(--pi-laranja-forte); }
.painel-inicial .pi-botao:disabled { opacity: 0.6; cursor: wait; }
.painel-inicial .pi-pequeno { padding: 5px 10px; font-size: 12px; border-radius: 8px; }
.painel-inicial .pi-link { border: 0; background: transparent; margin-left: auto; font-size: 13px; color: var(--pi-laranja-texto); font-weight: 700; }
.painel-inicial .pi-link:hover { text-decoration: underline; }

/* cartões */
.painel-inicial .pi-cartoes { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; }
.painel-inicial .pi-cartao { background: var(--pi-cartao); border: 1px solid var(--pi-linha); border-radius: 14px; padding: 14px 16px; min-height: 104px; }
.painel-inicial .pi-cartao-rotulo { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--pi-apagado); display: flex; gap: 6px; align-items: center; }
.painel-inicial .pi-cartao-valor { font-family: var(--pi-display); font-size: 36px; font-weight: 700; line-height: 1.05; margin-top: 4px; font-variant-numeric: tabular-nums; }
.painel-inicial .pi-cartao-detalhe { font-size: 12px; color: var(--pi-apagado); margin-top: 2px; }
.painel-inicial .pi-cartao.pi-destaque { background: var(--pi-laranja); border-color: var(--pi-laranja); color: var(--pi-sobre-laranja); }
.painel-inicial .pi-cartao.pi-destaque .pi-cartao-rotulo,
.painel-inicial .pi-cartao.pi-destaque .pi-cartao-detalhe { color: var(--pi-sobre-laranja); opacity: 0.8; }
.painel-inicial .pi-cartao.pi-alerta { background: var(--pi-amarelo-claro); border-color: var(--pi-amarelo); }
.painel-inicial .pi-cartao.pi-alerta .pi-cartao-valor { color: var(--pi-amarelo-texto); }
.painel-inicial .pi-esqueleto { animation: pi-piscar 1.2s ease-in-out infinite; }
@keyframes pi-piscar { 50% { opacity: 0.5; } }

/* seção ao vivo */
.painel-inicial .pi-secao { display: flex; align-items: center; gap: 10px; }
.painel-inicial .pi-secao h2 { font-family: var(--pi-display); font-size: 22px; font-weight: 800; text-transform: uppercase; }
.painel-inicial .pi-secao .pi-sub { margin: 0; }
.painel-inicial .pi-linha-fina { flex: 1; height: 1px; background: var(--pi-linha); }
.painel-inicial .pi-ao-vivo { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: var(--pi-verde); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
.painel-inicial .pi-ao-vivo i { width: 8px; height: 8px; border-radius: 50%; background: var(--pi-verde); animation: pi-pulso 1.6s infinite; }
@keyframes pi-pulso { 0% { box-shadow: 0 0 0 0 oklch(0.6 0.15 150 / 55%); } 70% { box-shadow: 0 0 0 8px transparent; } 100% { box-shadow: 0 0 0 0 transparent; } }
.painel-inicial .pi-alerta-faixa { display: flex; align-items: center; gap: 14px; padding: 12px 16px; border-radius: 14px; background: var(--pi-vermelho); color: #fff; }
.painel-inicial .pi-alerta-faixa > svg { font-size: 20px; }
.painel-inicial .pi-alerta-faixa b { display: block; font-family: var(--pi-display); font-size: 21px; text-transform: uppercase; }
.painel-inicial .pi-alerta-faixa .pi-botao { margin-left: auto; background: #fff; color: var(--pi-vermelho); }

/* grades */
.painel-inicial .pi-grade-ao-vivo { display: grid; grid-template-columns: minmax(0, 5fr) minmax(0, 7fr); gap: 16px; }
.painel-inicial .pi-grade-baixo { display: grid; grid-template-columns: minmax(0, 6fr) minmax(0, 3fr) minmax(0, 3fr); gap: 16px; }
.painel-inicial .pi-card { background: var(--pi-cartao); border: 1px solid var(--pi-linha); border-radius: 14px; min-width: 0; overflow: hidden; }
.painel-inicial .pi-card-cabecalho { display: flex; align-items: center; gap: 10px; padding: 14px 16px 10px; flex-wrap: wrap; }
.painel-inicial .pi-card-cabecalho h3 { font-family: var(--pi-display); font-size: 19px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.02em; }
.painel-inicial .pi-card-cabecalho .pi-sub { margin: 0; }

/* fila */
.painel-inicial .pi-fila { display: flex; flex-direction: column; }
.painel-inicial .pi-filtros { display: flex; gap: 6px; padding: 0 16px 10px; overflow-x: auto; scrollbar-width: none; }
.painel-inicial .pi-filtros button { flex: none; border: 1px solid var(--pi-linha); background: var(--pi-cartao); border-radius: 999px; padding: 4px 11px; font-size: 13px; font-weight: 600; color: var(--pi-tinta-2); display: inline-flex; gap: 6px; align-items: center; }
.painel-inicial .pi-filtros button b { font-family: var(--pi-display); font-size: 15px; }
.painel-inicial .pi-filtros button.pi-ativo { background: var(--pi-tinta); color: var(--pi-fundo); border-color: var(--pi-tinta); }
.painel-inicial .pi-filtros button.pi-com-alerta { border-color: var(--pi-vermelho); color: var(--pi-vermelho); }
.painel-inicial .pi-filtros button.pi-com-alerta.pi-ativo { background: var(--pi-vermelho); color: #fff; }
.painel-inicial .pi-lista { flex: 1; overflow: auto; max-height: 480px; }
.painel-inicial .pi-pedido { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 9px 16px; border-top: 1px solid var(--pi-linha); }
.painel-inicial .pi-pedido.pi-oferta { background: color-mix(in oklch, var(--pi-vermelho-claro) 70%, transparent); }
.painel-inicial .pi-pedido.pi-atrasado { box-shadow: inset 3px 0 0 var(--pi-vermelho); }
.painel-inicial .pi-pedido-principal { flex: 1; min-width: 0; display: flex; align-items: center; gap: 10px; border: 0; background: transparent; padding: 0; text-align: left; }
.painel-inicial .pi-capacete { width: 28px; height: 28px; flex: none; }
.painel-inicial .pi-pedido-meio { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.painel-inicial .pi-pedido-topo { display: flex; align-items: center; gap: 6px; }
.painel-inicial .pi-numero { font-family: var(--pi-display); font-size: 17px; font-weight: 700; }
.painel-inicial .pi-loja { color: var(--pi-apagado); }
.painel-inicial .pi-pedido-baixo { color: var(--pi-apagado); font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.painel-inicial .pi-pedido-situacao { text-align: right; font-size: 13px; font-weight: 600; line-height: 1.25; flex: none; display: flex; flex-direction: column; }
.painel-inicial .pi-pedido-situacao small { color: var(--pi-apagado); font-weight: 500; font-size: 12px; }
.painel-inicial .pi-texto-oferta { color: var(--pi-vermelho); font-weight: 700; font-size: 12px; font-variant-numeric: tabular-nums; }
.painel-inicial .pi-selo { font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 999px; text-transform: uppercase; letter-spacing: 0.03em; }
.painel-inicial .pi-selo-ifood { background: #ea1d2c; color: #fff; }
.painel-inicial .pi-prazo { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 700; padding: 3px 8px; border-radius: 8px; white-space: nowrap; font-variant-numeric: tabular-nums; }
.painel-inicial .pi-prazo-no_prazo { background: var(--pi-verde-claro); color: var(--pi-verde); }
.painel-inicial .pi-prazo-perto { background: var(--pi-amarelo-claro); color: var(--pi-amarelo-texto); }
.painel-inicial .pi-prazo-atrasado { background: var(--pi-vermelho); color: #fff; }
.painel-inicial .pi-candidatos { flex-basis: 100%; margin: 4px 0 2px 38px; border: 1px solid var(--pi-linha); border-radius: 12px; background: var(--pi-cartao); overflow: hidden; }
.painel-inicial .pi-candidato { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-bottom: 1px solid var(--pi-linha); }
.painel-inicial .pi-candidato img { width: 24px; height: 24px; }
.painel-inicial .pi-candidato span { flex: 1; display: flex; flex-direction: column; }
.painel-inicial .pi-candidato small { color: var(--pi-apagado); font-size: 12px; }
.painel-inicial .pi-nota { margin: 0; padding: 8px 12px; font-size: 12px; color: var(--pi-apagado); }
.painel-inicial .pi-vazio { padding: 28px 16px; text-align: center; color: var(--pi-apagado); border-top: 1px solid var(--pi-linha); }

/* mapa */
.painel-inicial .pi-mapa { margin: 0 12px 12px; border-radius: 12px; overflow: hidden; }
.painel-inicial .pi-mapa-altura { height: 480px; }
.painel-inicial .pi-legenda { display: inline-flex; gap: 10px; align-items: center; font-size: 12px; color: var(--pi-apagado); flex-wrap: wrap; }
.painel-inicial .pi-legenda > span { display: inline-flex; align-items: center; gap: 4px; }
.painel-inicial .pi-legenda img { width: 18px; height: 18px; }
.painel-inicial .pi-bolinha { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
.painel-inicial .pi-bolinha-loja { background: var(--pi-amarelo); }
.painel-inicial .pi-bolinha-rota { background: var(--pi-vermelho); }
.painel-inicial .pi-cor-cliente { color: #dc2626; }
.leaflet-tooltip.pi-rotulo { font: 700 11px var(--pi-corpo, 'Barlow', sans-serif); padding: 0 6px; border-radius: 6px; box-shadow: none; }
.leaflet-tooltip.pi-rotulo-loja { background: oklch(0.2 0.014 60); color: #fff; border-color: oklch(0.2 0.014 60); }

/* gráfico */
.painel-inicial .pi-grafico { display: flex; align-items: flex-end; gap: 4px; padding: 6px 16px 0; }
.painel-inicial .pi-coluna { flex: 1; min-width: 0; display: flex; flex-direction: column; align-items: center; gap: 4px; }
.painel-inicial .pi-caixa { position: relative; width: 100%; height: 150px; display: flex; align-items: flex-end; }
.painel-inicial .pi-barra { width: 100%; border-radius: 4px 4px 0 0; background: color-mix(in oklch, var(--pi-laranja) 55%, var(--pi-cartao-2)); }
.painel-inicial .pi-coluna:hover .pi-barra { background: var(--pi-laranja); }
.painel-inicial .pi-barra.pi-agora { background: repeating-linear-gradient(135deg, var(--pi-laranja) 0 6px, var(--pi-laranja-forte) 6px 12px); }
.painel-inicial .pi-media { position: absolute; left: -2px; right: -2px; height: 2px; background: var(--pi-tinta); opacity: 0.35; border-radius: 2px; }
.painel-inicial .pi-rotulo-hora { font-size: 11px; color: var(--pi-apagado); height: 14px; font-variant-numeric: tabular-nums; }
.painel-inicial .pi-legenda-grafico { padding: 8px 16px 14px; }
.painel-inicial .pi-amostra { width: 12px; height: 8px; border-radius: 2px; background: color-mix(in oklch, var(--pi-laranja) 55%, var(--pi-cartao-2)); display: inline-block; }
.painel-inicial .pi-amostra-media { height: 2px; background: var(--pi-tinta); opacity: 0.4; }

/* rankings */
.painel-inicial .pi-ranking { padding: 0 16px 12px; }
.painel-inicial .pi-item { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-top: 1px solid var(--pi-linha); }
.painel-inicial .pi-posicao { width: 18px; text-align: center; color: var(--pi-apagado); font-weight: 700; font-size: 12px; }
.painel-inicial .pi-item-nome { flex: 1; min-width: 0; font-weight: 600; overflow: hidden; text-overflow: ellipsis; }
.painel-inicial .pi-item-nome small { display: flex; align-items: center; gap: 5px; font-weight: 600; font-size: 11px; color: var(--pi-apagado); }
.painel-inicial .pi-ifood i { width: 7px; height: 7px; border-radius: 50%; background: var(--pi-apagado); }
.painel-inicial .pi-ifood-ok i { background: var(--pi-verde); }
.painel-inicial .pi-ifood-erro { color: var(--pi-vermelho); }
.painel-inicial .pi-ifood-erro i { background: var(--pi-vermelho); }
.painel-inicial .pi-mini { height: 5px; border-radius: 5px; background: var(--pi-cartao-2); margin-top: 4px; overflow: hidden; }
.painel-inicial .pi-mini i { display: block; height: 100%; background: var(--pi-laranja); border-radius: 5px; }
.painel-inicial .pi-item-valor { text-align: right; flex: none; font-size: 13px; line-height: 1.2; display: flex; flex-direction: column; }
.painel-inicial .pi-item-valor b { font-family: var(--pi-display); font-size: 18px; }
.painel-inicial .pi-item-valor small { color: var(--pi-apagado); }

/* atenção */
.painel-inicial .pi-atencao { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); border-top: 1px solid var(--pi-linha); }
.painel-inicial .pi-atencao-item { display: flex; gap: 10px; align-items: flex-start; padding: 12px 16px; border: 0; border-right: 1px solid var(--pi-linha); background: transparent; text-align: left; }
.painel-inicial .pi-atencao-item:hover { background: var(--pi-cartao-2); }
.painel-inicial .pi-atencao-item svg { margin-top: 2px; }
.painel-inicial .pi-atencao .pi-vazio { border-top: 0; }

/* celular e tablet: os mesmos blocos empilhados */
@media (max-width: 1023px) {
    .painel-inicial { padding: 16px 14px 28px; gap: 14px; }
    .painel-inicial .pi-titulo { font-size: 28px; }
    .painel-inicial .pi-acoes { margin-left: 0; width: 100%; justify-content: space-between; }
    .painel-inicial .pi-cartoes { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .painel-inicial .pi-cartao-valor { font-size: 30px; }
    .painel-inicial .pi-grade-ao-vivo,
    .painel-inicial .pi-grade-baixo { grid-template-columns: minmax(0, 1fr); }
    .painel-inicial .pi-lista { max-height: none; }
    .painel-inicial .pi-mapa-altura { height: 320px; }
    .painel-inicial .pi-caixa { height: 110px; }
    .painel-inicial .pi-alerta-faixa { flex-wrap: wrap; }
    .painel-inicial .pi-alerta-faixa .pi-botao { margin-left: 0; width: 100%; justify-content: center; }
    .painel-inicial .pi-secao .pi-sub { display: none; }
    .painel-inicial .pi-candidatos { margin-left: 0; }
}
```

- [ ] **Passo 2: importar**

Em `console/app/styles/app.css`, logo depois de `@import 'entregas-telas.css';`:

```css
@import 'painel-inicial.css';
```

- [ ] **Passo 3: commit**

```bash
git add console/app/styles/painel-inicial.css console/app/styles/app.css
git commit -m "Painel inicial: estilo na marca Entregas, claro e escuro, empilhado no celular"
```

---

### Tarefa 10: ligar o painel na home

**Arquivos:**
- Modificar: `packages/fleetops/addon/extension.js` (linhas ~105-106 e ~333-335)
- Modificar: `console/app/templates/console/home.hbs`

- [ ] **Passo 1: registrar o painel**

Em `packages/fleetops/addon/extension.js`, troque:

```js
        // // Register console home guidance
        // this.registerHomeComponents(registryService);
```

por:

```js
        // Entregas: o painel inicial do console (home/painel-inicial) no lugar dos widgets do Fleetbase
        this.registerHomeComponents(registryService);
```

E troque o método:

```js
    registerHomeComponents(registryService) {
        registryService.registerRenderableComponent('console:home:before-dashboard', new ExtensionComponent('@fleetbase/fleetops-engine', 'home/getting-started-guidance'));
    },
```

por:

```js
    registerHomeComponents(registryService) {
        registryService.registerRenderableComponent('console:home:before-dashboard', new ExtensionComponent('@fleetbase/fleetops-engine', 'home/painel-inicial'));
    },
```

- [ ] **Passo 2: tirar o Dashboard da home**

Substitua `console/app/templates/console/home.hbs` inteiro por:

```hbs
{{page-title (t "common.dashboard")}}
<Layout::Section::Body class="overflow-y-scroll h-full">
    <TwoFaEnforcementAlert />
    {{! Entregas: o painel inicial (home/painel-inicial do Fleet-Ops) entra por este registry; o <Dashboard> de widgets saiu }}
    <RegistryYield @registry="console:home:before-dashboard" as |RegistryComponent|>
        <RegistryComponent @controller={{this}} @position="before-dashboard" />
    </RegistryYield>
    <RegistryYield @registry="console:home:after-dashboard" as |RegistryComponent|>
        <RegistryComponent @controller={{this}} @position="after-dashboard" />
    </RegistryYield>
</Layout::Section::Body>
<div id="console-home-wormhole" />
```

Os widgets continuam registrados (`registerWidgets` não muda). Para voltar atrás, basta reverter estes dois arquivos.

- [ ] **Passo 3: parse e i18n**

Rode o parse da Tarefa 7, Passo 4, com `packages/fleetops/addon/extension.js`. Depois rode o `i18n-check` (exit 0).

- [ ] **Passo 4: commit**

```bash
git add packages/fleetops/addon/extension.js console/app/templates/console/home.hbs
git commit -m "Painel inicial: no lugar dos widgets na raiz do console"
```

---

### Tarefa 11: conferência geral, build local e documentação

- [ ] **Passo 1: todos os testes do painel e os vizinhos**

```bash
for t in painel-inicial-agregador painel-inicial painel-inicial-ao-vivo painel-inicial-console imports-dos-providers financeiro-console corridas-do-sistema distribuicao-rotas; do
  PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/$t.php | tail -1
done
node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/painel-inicial.test.mjs scripts/teste-portal/financeiro.test.mjs
node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal; echo "exit $?"
```

Esperado: `FALHAS: 0` em todos os PHP, os testes do Node `ok` e `exit 0`.

- [ ] **Passo 2: build local do console e conferência na tela**

O build leva ~10 min:

```bash
cd console && DISABLE_RUNTIME_CONFIG=false pnpm build --environment production
```

Para olhar a tela contra a API de produção (`console/CLAUDE.md`, "Build do console"):
1. grave `dist/fleetbase.config.json` com `API_HOST=https://entregas-api.restaurantepro.com.br`;
2. rode `npx serve -s dist -l 4200`;
3. faça login em `http://localhost:4200`.

A API de produção **ainda não tem** as rotas novas. Até o deploy da API, os blocos de números e o Ao vivo ficam vazios;
dá para conferir o layout, o mapa e a navegação.

Confira:
- claro e escuro;
- largura de 390 px (DevTools);
- Novo pedido abre o formulário;
- clique num pedido do mapa abre o detalhe.

- [ ] **Passo 3: documentação**

Em `packages/fleetops/CLAUDE.md`, acrescente uma seção "Painel inicial (raiz do console)" com:
- os arquivos;
- os 3 endpoints;
- o cache (5 s, 60 s, 5 min);
- quem vê o quê (admin);
- o "Atribuir" pelo `TrocaDoMotoboy`;
- o limite do pedido sem coordenada;
- como reverter (`extension.js` + `home.hbs`).

Em `api/CLAUDE.md` ou num `CLAUDE.md` novo em `api/app/Support/Entregas/Painel/`, descreva:
- as fontes (`entregas_corridas_finalizadas`, livro do Financeiro, `orders.meta`);
- a regra do "no prazo" (pedido sem prazo fica fora do %).

Se criar o arquivo novo, ponha uma linha na tabela do `CLAUDE.md` da raiz.

Em `docs/historico.md`, registre a entrega (2026-10-25, sketch 004 variante A).

- [ ] **Passo 4: commit**

```bash
git rev-parse --show-toplevel   # precisa ser .../Delivery
git add packages/fleetops/CLAUDE.md docs/historico.md api/app/Support/Entregas/Painel/CLAUDE.md CLAUDE.md
git commit -m "Painel inicial: documentação"
```

- [ ] **Passo 5: deploy (o Edgard roda na VPS)**

```bash
cd ~/entregas && bash deploy/atualizar.sh api && bash deploy/atualizar.sh console
```

Depois, abra o console com Ctrl+Shift+R.
