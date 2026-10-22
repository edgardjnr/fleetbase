# Corridas do sistema (relatório do dono) — design

Data: 2026-10-22. Aprovado pelo Edgard na conversa (respostas: "Mesma instalação", "Valor fixo por corrida",
"Trazer o histórico", "Só o relatório", "segue").

## Objetivo

O Edgard vai vender o Entregas a centrais de motoqueiros. Cada central será uma **organização (company) nova na mesma
instalação** (entregas.restaurantepro.com.br). Ele cobra de cada central um **valor fixo por corrida finalizada**.

Esta tela é o controle dele para cobrar: todas as corridas finalizadas no sistema, de todas as organizações, separadas
por central, com quantidade × preço = valor a cobrar. **Só o Edgard vê.**

Regras pedidas por ele:

- A corrida entra no relatório quando o motoqueiro finaliza e **nunca mais sai** (cancelar, excluir o pedido ou o
  roteiro `deploy/zerar-pedidos` não tiram).
- Na implantação, trazer o **histórico** de tudo o que já foi concluído e ainda está no banco (inclusive cancelado ou
  excluído depois).
- Só o relatório: registrar os pagamentos recebidos das centrais fica fora (ele controla fora por enquanto).

## Quem vê

- **Administrador do sistema** = `users.type = 'admin'` (`User::isAdmin()`; no console, `currentUser.isAdmin`). O
  Fleetbase só cria esse tipo para o **primeiro usuário** da instalação (`OnboardController::createAccount`:
  `$isAdmin = !User::exists()`); quem cria organização depois entra como `user`, mesmo sendo "Administrator" da própria
  organização. Hoje o único é o Edgard Junior (conferido em 22/10: `type: admin`, organização Restaurante Pro).
- API: toda rota da tela responde **403** "Somente o administrador do sistema pode ver esta tela." para quem não é
  `isAdmin()`. Método próprio `negarSeNaoDonoDoSistema` (não reaproveitar o `negarSeNaoAdmin` do financeiro, ver
  "Fora do escopo").
- Console: o item do menu tem `visible: currentUser.isAdmin === true` próprio, independente da regra dos outros itens.

## Registro permanente

### Tabela `entregas_corridas_finalizadas`

Uma linha por pedido (`order_uuid` **único**). Sem `updated_at` e sem `deleted_at`: o código nunca atualiza nem apaga.

| coluna | tipo | |
|---|---|---|
| `id` | bigint auto | |
| `order_uuid` | char(36) único | |
| `company_uuid` | char(36), índice | a central (organização dona do pedido) |
| `pedido` | string(64) | `orders.public_id` |
| `numero` | string(191) null | `orders.internal_id` (número da loja / iFood) |
| `loja_uuid`, `loja_nome` | null | `orders.customer_uuid` quando é Vendor; nome gravado na hora |
| `motoboy_uuid`, `motoboy_nome` | null | `driver_assigned_uuid` na conclusão; nome gravado na hora |
| `finalizada_em` | dateTime, índice | hora de Brasília, texto `Y-m-d H:i:s` (regra de fuso do `api/CLAUDE.md`) |
| `origem` | string(16) | `conclusao`, `conferencia` ou `historico` |
| `created_at` | timestamp | |

Índice composto (`company_uuid`, `finalizada_em`) para o relatório. Os nomes ficam gravados para o relatório não
depender de loja, motoboy ou organização que forem excluídos depois. O nome da central é lido na hora do relatório em
`companies` **com** as excluídas (`withTrashed` ou consulta direta), e cai no `company_uuid` curto se não existir mais.

### O que conta como finalizada

`orders.status` em `completed` ou `done` (os mesmos `CONCLUIDOS` do `ObservadorFinanceiroDosPedidos`). O cancelado pago
pelo iFood ou pela integração **não** entra: não foi finalizado pelo motoqueiro.

`finalizada_em`: o primeiro `tracking_statuses.created_at` com `code = 'COMPLETED'` do pedido; sem ele, `orders.updated_at`
(a mesma regra do `CalculoEntregas::DATA_DA_ENTREGA`). Na gravação pelo observador, `now()` no fuso do app.

### Quando grava

1. **Observador** `Order::updated` (novo, registrado no `AppServiceProvider`): `status` mudou para concluído → grava a
   linha (`insertOrIgnore` pelo `order_uuid`), origem `conclusao`. Nunca lança exceção nem atrasa a conclusão (try/catch
   com log de aviso, como os outros observadores de `App\Listeners\Entregas`).
2. **Conferência** `entregas:corridas-conferir`, a cada 5 min no agendador, **sem** depender da chave do financeiro:
   pedidos concluídos (status atual concluído **ou** com status `COMPLETED` no rastreio) dos últimos 2 dias, de todas as
   organizações, `withTrashed`, sem linha → grava com origem `conferencia`. Cobre fila, observador ou banco fora do ar
   no momento da conclusão. Pedido com rastreio `COMPLETED` que depois foi cancelado também entra: ele foi finalizado.
3. **Histórico**: a conferência, quando a tabela está **vazia**, importa tudo o que existe no banco (sem limite de data),
   origem `historico`, em lotes. Assim o deploy não exige passo manual por SSH. Também há a opção
   `--historico` para rodar à mão (idempotente pelo `order_uuid`).

Gravar é idempotente: pedido reaberto e concluído de novo continua com uma linha só (a primeira).

## Preço por central

### Tabela `entregas_precos_centrais`

| coluna | tipo | |
|---|---|---|
| `id` | bigint auto | |
| `company_uuid` | char(36), índice | |
| `valor` | decimal(10,2) | R$ por corrida, de 0,00 a 1000,00 |
| `vale_a_partir_de` | date | dia (Brasília) |
| `criado_por` | char(36) null | usuário |
| `created_at` | timestamp | |

Único (`company_uuid`, `vale_a_partir_de`).

- O preço de uma corrida é o da linha da central com o maior `vale_a_partir_de` ≤ dia de `finalizada_em`. Calculado na
  hora do relatório: trocar o preço "a partir de" uma data nova não muda o que veio antes dela; cadastrar um preço com
  data antiga vale também para o histórico (é assim que o Edgard precifica o que veio da importação).
- Sem preço para aquele dia: a corrida vale R$ 0,00 e conta em "sem preço" (aviso na central).
- Editar não existe: cadastra-se um preço novo com outra data. Apagar um preço é permitido (correção de digitação), com
  confirmação na tela, porque muda os valores calculados do período dele.

## API (console, grupo `int/v1/entregas`, `fleetbase.protected`)

Todas com `negarSeNaoDonoDoSistema`. Controller novo `App\Http\Controllers\Entregas\CorridasDoSistemaController`; regras
em `App\Support\Entregas\Sistema\` (classe de registro `CorridasFinalizadas` e de preço `PrecosDasCentrais`).

- `GET sistema/corridas?inicio=Y-m-d&fim=Y-m-d` (período em Brasília, até 366 dias):
  `{inicio, fim, total: {corridas, a_cobrar, sem_preco}, centrais: [{company, nome, corridas, a_cobrar, sem_preco,
  precos: [valores distintos usados no período]}], corridas: [{finalizada_em (ISO -03:00), company, central, pedido,
  numero, loja_nome, motoboy_nome, preco|null, origem}]}`. Lista ordenada por `finalizada_em`. Sem paginação na v1 (o
  volume esperado cabe; limite de 20.000 linhas na lista, com `lista_cortada: true` se passar; os totais são sempre
  completos).
- `GET sistema/precos` → `{centrais: [{company, nome, precos: [{id, valor, vale_a_partir_de}]}]}` com **todas** as
  organizações (para precificar central sem corrida ainda).
- `POST sistema/precos` `{company, valor, vale_a_partir_de}` → 422 para valor fora da faixa, data inválida, organização
  inexistente ou data repetida para a central.
- `DELETE sistema/precos/{id}`.

## Console (Fleet-Ops → Financeiro)

- Rota `financeiro.corridas-do-sistema`, item "Corridas do sistema" (ícone `building`), último do menu do Financeiro,
  `visible: currentUser.isAdmin === true`.
- Tela:
  - **Período** (padrão: mês atual; atalhos "Este mês", "Mês passado").
  - **Resumo**: corridas, a cobrar, e aviso "N corridas sem preço" quando houver.
  - **Por central**: nome, corridas, preço (um valor, ou "variado" quando mudou no período), a cobrar; rodapé com o
    total. Central com "sem preço" ganha selo.
  - **Preços**: por central, lista dos preços com "vale a partir de", botão para adicionar (modal com central, valor e
    data) e apagar (confirmação).
  - **Corridas**: lista detalhada (data e hora, central, pedido, nº, loja, motoboy, preço).
  - **Exportar CSV** (mesmo padrão do relatório: `;`, BOM `﻿`, valores com vírgula).
- Textos em pt-br e en-us (`fleet-ops.ui.financeiro.corridas-do-sistema.*`), com o `i18n-check` zerado.

## Fora do escopo (anotado para depois)

- **O Financeiro inteiro (e o painel da distribuição) hoje só abre para o administrador do sistema** (`negarSeNaoAdmin`
  usa `isNotAdmin()` = `type !== 'admin'`). Quando as centrais entrarem, o administrador de cada uma não vai conseguir
  usar o Financeiro da própria organização. Precisa de outro trabalho: trocar essa regra pelo papel "Administrator" da
  organização. A tela desta spec continua com a trava do administrador do sistema.
- Registrar recebimentos das centrais e saldo em aberto.
- Cobrança automática, fatura para a central, e-mail.

## Testes

- php-wasm (`scripts/teste-php/`): registro idempotente; observador só em status concluído e sem lançar exceção;
  conferência acha o que faltou (incluindo excluído e cancelado depois de `COMPLETED`); histórico só com tabela vazia;
  preço pela vigência (antes da primeira data = sem preço; troca no meio do período = "variado"); totais por central;
  403 para usuário que não é `admin`; validações do POST de preço.
- teste-portal: funções puras do console (CSV, agregação de preços "variado", formatação).
- `node scripts/i18n-check.cjs …` exit 0.

## Implantação

API + console (`bash deploy/atualizar.sh` na VPS). A migration cria as duas tabelas; a primeira conferência (até 5 min
depois do deploy) importa o histórico. Depois, o Edgard cadastra o preço da Restaurante Pro (e de cada central nova).
