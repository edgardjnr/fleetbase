# Financeiro (motoboys e lojas) — desenho

Data: 2026-10-12 · Status: aprovado em conversa

## Objetivo

A tela "Pagamento e cobrança" calcula quanto pagar a cada motoboy e quanto cobrar de cada loja num
período, mas **não sabe o que já foi pago nem recebido**. O Financeiro passa a controlar toda a parte
financeira da operadora de entregas:

1. **Quanto devo a cada motoboy agora**, com corridas, diárias, bônus, ajustes, adiantamentos e
   pagamentos, e quais corridas já estão pagas.
2. **Lançamentos extras ao motoboy**, como a diária (ajuda de custo) que alguns recebem além das corridas.
3. **Quanto cada loja me deve**, com faturas por período, vencimento e situação (em aberto, paga, vencida).
4. **Visão geral**: a pagar × a receber × margem, e o que pede atenção.

Tudo entra na primeira entrega, construída em 4 etapas (ver "Ordem de construção").

## Decisões (conversa de 2026-10-12)

- **Todos os módulos** (extrato do motoboy, pagamentos, cobrança das lojas, painel) na primeira entrega.
- **Diária = lançamento manual.** Não é de todo motoboy nem de todo dia, então não há regra automática.
  Há o atalho de lançar a mesma diária para vários motoboys de uma vez.
- **Pagamento ao motoboy sem regra fixa** ("paga quando dá", às vezes parte, às vezes adiantamento):
  conta corrente, com saldo que pode ficar negativo (adiantamento).
- **Corrida paga = quitação automática por antiguidade.** O admin informa só o valor pago; os débitos
  quitam os créditos mais antigos. Não se escolhem corridas a dedo.
- **Lojas pagam por fatura** com período e vencimento.
- **Ciclo por loja**: semanal, quinzenal ou mensal, com prazo de vencimento próprio. Loja sem ciclo
  configurado usa o padrão.
- **Fatura nasce como rascunho automático** no fechamento do ciclo. O admin confere, ajusta e **emite**.
  Só a emitida vai para o portal e para o e-mail.
- **Mesma tabela de faixas de km para todas as lojas e todos os motoboys** (a de hoje). O que muda por
  motoboy entra como lançamento manual.
- **Correção = lançamento de ajuste** (com o pedido e o motivo), nunca a edição do valor da corrida. Atalho
  "Não pagar / Não cobrar" gera o ajuste do valor inteiro.
- **Só registro**: o dinheiro sai e entra fora do sistema (app do banco). O sistema guarda valor, forma,
  data e comprovante. A chave Pix do motoboy fica no cadastro. Integração bancária (Pix com baixa
  automática) fica para depois; o desenho não a impede.
- **Motoboy vê o extrato completo** no app (saldo, todos os lançamentos com a situação, recibos).
  A observação interna e o valor cobrado da loja nunca chegam a ele.
- **Só administradores** mexem no financeiro do console (como hoje). Cada lançamento, pagamento e fatura
  guarda quem fez e quando. **Nada se apaga**: erro se corrige com estorno.
- **Loja vê no portal** as faturas e o ciclo em andamento, e recebe e-mails (emitida, lembrete, vencida).
- **Implantação com data de corte**: corridas anteriores à data ficam fora; pendência antiga entra como
  lançamento "Saldo inicial".

## Abordagem escolhida

**Livro de lançamentos próprio na `api/app`** (tabelas `entregas_*`, serviços em
`api/app/Support/Entregas/Financeiro/`, controllers em `api/app/Http/Controllers/Entregas/`). Chega à
produção (é a imagem da API) e segue o padrão de `entregas_valores_pedido`.

Descartadas:
- **Calcular as corridas na hora e gravar só o manual:** saldo, fatura e app dependeriam do cálculo sob
  demanda (OSRM), mais lento e frágil. A fatura precisa de retrato fixo de qualquer forma.
- **Reativar a extensão Ledger do Fleetbase:** genérica, em inglês, e o PHP dela vem do pacote publicado
  (mudanças em `packages/*/server` não chegam à produção). Adaptar custaria mais que fazer o próprio.

## Dados

Migrations em `api/database/migrations` (o `deploy.sh` roda no banco principal e no sandbox). Nada disso
vai no `meta` do pedido ou do motorista, que sai na API v1 e no socket.

### `entregas_lancamentos`

Um crédito ou débito numa conta. A conta é um motoboy (Driver) ou uma loja (Vendor).

| Campo | Observação |
|---|---|
| `id`, `uuid`, `company_uuid` | |
| `conta_tipo` | `motoboy` ou `loja` |
| `conta_uuid` | `drivers.uuid` ou `vendors.uuid` |
| `tipo` | `corrida`, `diaria`, `bonus`, `ajuste`, `saldo_inicial`, `pagamento` (motoboy), `recebimento` (loja), `estorno` |
| `valor` | decimal(10,2) com sinal: crédito > 0 (devo ao motoboy / a loja me deve), débito < 0 |
| `data_referencia` | dia (date) em Brasília: a conclusão da corrida, o dia da diária, a data do pagamento |
| `order_uuid` | pedido, quando houver (corrida, ajuste de corrida) |
| `pagamento_uuid` | o pagamento ou recebimento que gerou o lançamento |
| `fatura_uuid` | só na conta da loja: a fatura em que o lançamento entrou (null = ainda não faturado) |
| `estorno_de` | o lançamento que este estorna |
| `motivo` | texto visível ao motoboy ou à loja |
| `observacao_interna` | nunca sai para o app nem para o portal |
| `chave_unica` | nullable, unique: `corrida:<conta_tipo>:<order_uuid>` e `estorno:<id>`; impede lançar duas vezes |
| `criado_por` | `users.uuid`; null = sistema |
| `created_at`, `updated_at` | |

Índices: `(company_uuid, conta_tipo, conta_uuid, data_referencia)`, `fatura_uuid`, `order_uuid`.

### `entregas_pagamentos`

Pagamento ao motoboy ou recebimento da loja. Ao registrar, cria o lançamento negativo correspondente
(`pagamento` ou `recebimento`).

| Campo | Observação |
|---|---|
| `id`, `uuid`, `company_uuid`, `conta_tipo`, `conta_uuid` | |
| `valor` | positivo |
| `forma` | `pix`, `dinheiro`, `transferencia`, `outro` |
| `data` | date |
| `comprovante_uuid` | arquivo (`Fleetbase\Models\File`, MinIO, privado), imagem ou PDF até 10 MB, opcional |
| `observacao_interna` | |
| `estornado_em`, `estornado_por` | estornar cria o lançamento `estorno` positivo e marca aqui |
| `criado_por`, timestamps | |

### `entregas_faturas`

| Campo | Observação |
|---|---|
| `id`, `uuid`, `company_uuid`, `vendor_uuid` | |
| `numero` | sequencial por organização (`FAT-0001`), atribuído na emissão; o rascunho não tem número |
| `periodo_inicio`, `periodo_fim`, `vencimento` | dates |
| `situacao` | `rascunho`, `emitida`, `cancelada` (paga, paga em parte e vencida são calculadas) |
| `total` | soma dos lançamentos da fatura, gravada na emissão |
| `emitida_em`, `emitida_por`, `cancelada_em`, `cancelada_por` | |
| `email_enviado_em`, `lembrete_enviado_em`, `aviso_vencida_enviado_em` | controle dos e-mails |
| timestamps | |

Itens = lançamentos da loja com `fatura_uuid` igual ao da fatura (os lançamentos já são imutáveis, então
a ligação é o retrato). Ao **cancelar** uma fatura emitida, os lançamentos voltam a `fatura_uuid = null` e
entram no próximo rascunho; o número não é reaproveitado.

### `entregas_cobranca_lojas`

| Campo | Observação |
|---|---|
| `vendor_uuid` (unique), `company_uuid` | |
| `ciclo` | `semanal`, `quinzenal` (1–15 e 16–fim do mês), `mensal` |
| `dia_fechamento` | semanal: dia da semana (0 = domingo) em que o ciclo termina; mensal: dia do mês (1–28, ou 0 = último dia) |
| `prazo_dias` | dias entre o fim do período e o vencimento |

Padrão da organização (loja sem linha) em `Setting` `company.<uuid>.entregas.cobranca_padrao`; sugerido:
semanal, fecha no domingo, vence em 3 dias.

### `entregas_motoboys_financeiro`

`driver_uuid` (unique), `company_uuid`, `chave_pix`, `tipo_chave` (`cpf`, `cnpj`, `telefone`, `email`,
`aleatoria`). Cadastrada pelo admin; o motoboy vê, não edita.

### Configuração

- `Setting` `company.<uuid>.entregas.financeiro_corte` (date): corridas com data anterior não são lançadas.
- Variável `ENTREGAS_FINANCEIRO=1` no stack: liga o lançamento automático, as rotinas e os e-mails.
  Desligada, as telas funcionam (lançamentos manuais, pagamentos), mas nada é lançado sozinho.

## Regras

### Lançamento das corridas (`LancadorDeCorridas`)

- Pedido que entra no relatório de hoje (concluído, ou cancelado pago pelo iFood ou pela integração,
  pela mesma consulta do `CalculoEntregas::pedidosConcluidos`) com data a partir do corte gera **dois
  lançamentos `corrida`**: `valor_motoboy` na conta do motoboy atribuído e `valor_loja` na conta da loja
  dona do pedido (`orders.customer_uuid` = Vendor). Os valores vêm do valor congelado
  (`ValoresCongelados`), e a `data_referencia` é a data da conclusão (ou do cancelamento pago) em Brasília.
- É chamado em dois lugares: quando o pedido conclui (observador do pedido, sem bloquear a conclusão) e
  na **conferência a cada 5 min** (`entregas:financeiro-conferir`), que pega o que escapou. A `chave_unica`
  garante um lançamento por pedido e conta; tentativa repetida é ignorada.
- Pedido sem valor congelado (sem faixas, OSRM fora e sem estimativa) fica para a próxima conferência. O
  painel lista "corridas sem valor" quando passa de 1 h.
- Pedido sem loja como cliente: só o lançamento do motoboy. O painel lista "sem loja para cobrar".
- Corrida do motoboy vai para quem estava atribuído na conclusão.
- **Depois de lançado, o valor da corrida não muda.** O recálculo do valor congelado (km alterado) deixa
  de valer para pedido com lançamento. Correção só por ajuste.
- Pedido lançado que deixa de ser pagável (concluído e depois cancelado sem pagamento): a conferência
  cria o **estorno automático** de cada lançamento, com motivo "Pedido cancelado após a conclusão".
- Comando `entregas:financeiro-lancar-historico`: lança as corridas desde a data de corte (uso único na
  implantação, seguro de repetir).

### Quitação por antiguidade (calculada na hora, nada gravado)

- **Motoboy:** créditos (corrida, diária, bônus, saldo inicial, ajuste positivo, estorno de pagamento)
  ordenados por `data_referencia`, `id`. Primeiro, o débito ligado a um crédito abate esse crédito: o
  estorno abate o lançamento estornado, e o ajuste negativo com pedido abate a corrida daquele pedido
  (assim a corrida marcada "Não pagar" não fica "a receber"). O que sobra desses débitos e os débitos
  soltos (pagamentos, ajustes sem pedido, saldo inicial negativo) quitam do mais antigo para o mais novo.
  Cada crédito fica **pago**, **pago em parte** ou **a receber**. Saldo = soma de todos os lançamentos;
  negativo = adiantamento. O saldo inicial aceita valor negativo (o motoboy devia).
- **Loja:** os recebimentos (e estornos de recebimento) quitam as **faturas emitidas** por ordem de
  vencimento. Fatura: **em aberto**, **paga em parte**, **paga**; **vencida** = não paga e vencimento
  anterior a hoje. Lançamentos ainda não faturados entram no saldo da loja, mas não vencem.
- Estorno de um lançamento: cria o lançamento oposto com `estorno_de`; os dois aparecem no extrato.
  Estorno de pagamento marca `estornado_em` e o saldo volta.

### Faturas

- **Rascunho** (`entregas:financeiro-rascunhos`, 00:30): para cada loja cujo ciclo terminou no dia anterior,
  junta os lançamentos da loja sem fatura com `data_referencia` até o fim do período e cria o rascunho
  (vencimento = fim + `prazo_dias`). Sem lançamentos, não cria. Lançamento de data antiga ainda sem
  fatura (corrida que concluiu tarde, fatura cancelada) entra no próximo rascunho.
- **Conferência:** o admin pode marcar "Não cobrar" numa linha (gera ajuste negativo do valor, na fatura),
  incluir ajuste avulso (desconto, taxa, mensalidade; positivo ou negativo), mudar o vencimento e emitir.
  Ajuste lançado fora do rascunho fica sem fatura e entra no próximo.
- **Emitir:** atribui o número, grava o total, a fatura aparece no portal e o e-mail sai.
- **Cancelar** fatura emitida: libera os lançamentos para o próximo rascunho.
- Rascunho pode ser gerado à mão (loja + período) pelo console, para o caso fora do ciclo.

## Telas do console (Fleet-Ops → Financeiro, só admin)

Substitui "Recursos → Pagamento e cobrança". Rotas no engine do Fleet-Ops; API em
`int/v1/entregas/financeiro/*` com o mesmo `negarSeNaoAdmin`.

- **Painel:** a pagar aos motoboys (soma dos saldos positivos), a receber das lojas (com o vencido),
  margem do período (cobrado − pago em corridas, diárias e bônus), e a lista "Atenção": faturas vencidas,
  rascunhos para conferir, pedidos sem loja, corridas sem valor, faturas não enviadas por e-mail.
- **Motoboys:** lista (nome, saldo, último pagamento, corridas no período, botão Pagar). O extrato do
  motoboy tem saldo, chave Pix com botão de copiar, filtro de período e tipo, a lista de lançamentos com
  a situação, **Registrar pagamento**, **Lançar diária / bônus / ajuste / saldo inicial**, **Estornar** por
  linha e CSV. Na lista dá para marcar vários motoboys e lançar a mesma diária para todos.
- **Lojas e faturas:** abas Faturas (rascunho, emitida, paga, vencida, com filtro), Lojas (saldo, ciclo e
  prazo editáveis) e Ciclo em andamento. Conferência do rascunho como descrito acima; na emitida:
  registrar recebimento, reenviar e-mail, imprimir, cancelar.
- **Tabela de km:** as faixas de hoje, sem mudança.
- **Relatório por período:** o relatório atual de "Pagamento e cobrança", para consultar o passado.
- **Configurações:** data de corte e ciclo padrão das lojas.
- **Modais:** Registrar pagamento/recebimento (valor sugerido = saldo, forma, data, comprovante,
  observação interna; aviso "vai ficar como adiantamento de R$ X" quando passa do saldo). Lançar
  (tipo, valor, data, pedido opcional, motivo visível, observação interna).

## App do motoboy (repo `entregas-navigator`)

- **Início "Meus ganhos" vira o extrato:** cartão "A receber agora" (saldo) com o último pagamento; os
  lançamentos agrupados por dia, cada corrida com a situação (pago, pago em parte, a receber); diárias,
  bônus, ajustes (com o motivo), adiantamentos e pagamentos. Os atalhos de período continuam (abre no
  mês atual).
- Tocar num pagamento abre o **recibo** (valor, forma, data, comprovante). Tocar numa corrida abre os
  detalhes do pedido.
- **Push** "Pagamento de R$ X registrado" ao registrar o pagamento.
- O card de aceitar e os detalhes do pedido não mudam.
- API (token de motoboy, `MotoboyDaSessao`): `GET v1/entregas/motoboy/extrato?inicio&fim` (até 3 meses) e
  `GET v1/entregas/motoboy/pagamentos/{id}` (link temporário do comprovante). Nunca devolvem a
  observação interna nem lançamentos da conta da loja. O `GET v1/entregas/motoboy/ganhos` continua para
  os APKs antigos até a trava de versão subir o mínimo.

## Portal da loja

- Menu **Financeiro:** no topo, o ciclo em andamento ("desde 06/10: 42 entregas, R$ 315,00", lançamentos
  da loja ainda sem fatura); embaixo, as faturas emitidas e canceladas com período, valor, vencimento e
  situação.
- Detalhe da fatura: entregas (pedido, data, km, valor) e ajustes (com o motivo), total, recebido, em
  aberto, e o botão **"Imprimir / salvar PDF"** (página própria para impressão; sem gerar PDF no servidor).
- Rascunhos não aparecem. A loja só vê as faturas dela (`LojaDoUsuario`). O extrato atual continua.

## E-mails para a loja

No layout pt-BR atual (`api/app/Notifications/Entregas/Email/`), para o e-mail da loja e dos usuários do
portal dela:

- **Fatura emitida** (na emissão), com o link do portal.
- **Lembrete** 1 dia antes do vencimento, se ainda houver valor em aberto.
- **Fatura vencida**, uma vez, no dia seguinte ao vencimento, se ainda houver valor em aberto.

Rotina `entregas:financeiro-avisos` às 09:00. Loja sem e-mail: a fatura é emitida e o painel mostra
"não enviada por e-mail".

## Rotinas agendadas (`api/app/Console/Kernel.php`, `when(ENTREGAS_FINANCEIRO)`)

| Comando | Quando |
|---|---|
| `entregas:financeiro-conferir` | a cada 5 min (lança o que faltou, estorna cancelados) |
| `entregas:financeiro-rascunhos` | 00:30 |
| `entregas:financeiro-avisos` | 09:00 |

Todas com `withoutOverlapping` e no fuso de Brasília (nunca formatar em UTC para gravar ou consultar).

## Falhas e casos de borda

- OSRM fora: corrida sem valor fica pendente; a conferência tenta de novo; o painel avisa depois de 1 h.
- Lançamento repetido: a `chave_unica` impede; a segunda tentativa é ignorada sem erro.
- Dois admins pagando ao mesmo tempo: são dois pagamentos; o saldo é a soma, nada se perde; o engano se
  corrige com estorno.
- Fatura de período sem lançamentos: não é criada.
- Troca de motoboy: a corrida vai para o atribuído na conclusão.
- Entre o restart da API e a migration, as telas novas podem dar 500 por alguns segundos (como no
  `entregas_valores_pedido`).

## Testes

- `scripts/teste-php/`: lançador (uma vez só, cancelado pago, estorno automático, data de corte, pedido
  sem loja), quitação por antiguidade (pago, em parte, adiantamento, estorno), ciclos (semanal, quinzenal,
  mensal, último dia do mês, virada de mês, fuso), situação da fatura (paga em parte, vencida, cancelada
  devolvendo os lançamentos) e permissões (motoboy sem observação interna e sem valor da loja; loja só as
  próprias faturas; console só admin).
- `scripts/teste-portal/`: funções puras de formatação e agrupamento do console e do portal.
- App: funções do extrato em `src/utils/`, com `node --experimental-strip-types --test`.
- Tradução: `node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal` com exit 0.

## Ordem de construção

Cada etapa vira um plano e pode ir para produção sozinha:

1. **Base e motoboys:** tabelas, `LancadorDeCorridas`, conferência, quitação, data de corte, API do console,
   telas Painel, Motoboys (extrato, lançar, pagar, estornar, Pix), Tabela de km, Relatório por período e
   Configurações.
2. **Lojas:** ciclo, rascunho, conferência, emissão, cancelamento, recebimento, situação da fatura, tela
   Lojas e faturas.
3. **Portal da loja e e-mails:** menu Financeiro do portal, impressão da fatura, os três e-mails e a rotina
   de avisos.
4. **App do motoboy:** extrato, recibo, push do pagamento (novo APK).

## Fora do escopo

- Integração bancária (Pix com QR code e baixa automática, Pix para o motoboy).
- Tabela de preço por loja, taxa fixa por entrega ou mensalidade automática (mensalidade entra como ajuste
  na conferência).
- Diária automática.
- Papel "Financeiro" ou só leitura no console.
- Edição da chave Pix pelo motoboy no app.
