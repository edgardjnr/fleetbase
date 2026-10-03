# Ganhos do motoboy no app — desenho

Data: 2026-10-03 · Status: aprovado em conversa

## Objetivo

O motoboy precisa saber quanto ganha em cada corrida e quanto tem a receber, sem perguntar à central:

1. **Início do app (Navigator) vira "Meus ganhos":** filtro de período (De / Até), lista das corridas
   concluídas com o valor de cada uma e o total a receber. Ao abrir o app, o período é o mês atual; o
   motoboy pode trocar.
2. **Card de aceitar corrida:** mostra o km da entrega e o valor que ele vai receber, antes do aceite.
3. **Detalhes do pedido:** mostram a mesma informação (km, faixa e valor).

O valor que o motoboy vê tem de ser exatamente o que a central paga a ele (tela Pagamento e cobrança).

## Decisões (conversa de 2026-10-03)

- O Início atual (rastreamento, latitude/longitude, direção, altitude, velocidade, pedidos ativos) é
  **substituído** pelos ganhos. O status online continua no cabeçalho e os pedidos ativos no selo da
  aba Pedidos.
- Filtro **só por período**, com atalhos: Hoje, 7 dias, Este mês, Mês passado. Sem filtro por loja ou
  por situação.
- **O valor da corrida é congelado** (vale para o motoboy e para a cobrança da loja): na primeira vez
  que é calculado, o km e os valores da faixa ficam gravados; uma tabela de faixas nova vale só para as
  entregas calculadas depois. Hoje o relatório aplica a tabela atual até às corridas antigas.
- **Sem** botão "Recalcular valores do período" no console: valor congelado errado se corrige via SQL
  (apagando as linhas do período em `entregas_valores_pedido`; elas voltam com a tabela vigente na
  próxima consulta).

## Abordagem escolhida

Rotas novas do motoboy na API (`api/app`), usando o mesmo `CalculoEntregas` do relatório da central,
com os valores congelados numa **tabela própria**. O app chama essas rotas com o token do motoboy.

Descartadas:
- gravar o valor no `meta` do pedido: o `meta` sai na API v1 (recurso Order) e nos eventos do socket. A
  loja veria quanto o motoboy ganha e o motoboy veria quanto a loja paga;
- o app calcular sozinho a partir da lista de pedidos: `orders.distance` é a distância restante (vai a
  ~0 na conclusão) e a regra das faixas ficaria duplicada no celular, sem garantia de bater com o
  relatório.

## 1. Servidor (`api/app`)

### Congelamento dos valores

Tabela nova **`entregas_valores_pedido`** (migration em `api/database/migrations`, que o `deploy.sh`
roda com `php artisan migrate --force`; o `sandbox:migrate` só roda as migrations dos pacotes):

| coluna | tipo | |
|---|---|---|
| `id` | bigint, auto | |
| `company_uuid` | char(36), índice | |
| `order_uuid` | char(36), **único** | um valor por pedido |
| `chave` | string(32), nulo | chave das coordenadas do km (a mesma do `meta.entregas.km_rota`) |
| `metros` | int sem sinal | km usado no cálculo, em metros |
| `fonte` | string(20) | `osrm` ou `estimativa` |
| `de_km`, `ate_km` | decimal(8,2) | limites da faixa usada |
| `acima` | booleano | km acima da última faixa |
| `valor_motoboy`, `valor_loja` | decimal(10,2) | os dois valores da faixa naquele momento |
| `created_at`, `updated_at` | timestamps | |

Regra (`CalculoEntregas`, usada pelo relatório da central, pelo extrato da loja e pelas rotas do
motoboy):

- O km continua como hoje: rota loja → cliente pelo OSRM, guardada em `meta.entregas.km_rota` (o km em
  si não é sensível).
- Com km e com faixas cadastradas, o valor vem da linha congelada do pedido. Sem linha, calcula a faixa
  pela tabela vigente e **grava** a linha.
- Se o km mudar (os `metros` da rota diferem dos da linha: endereço alterado, ou a estimativa trocada
  pela rota do OSRM), recalcula com a tabela vigente e regrava.
- Sem faixas cadastradas, nada é gravado e o valor fica nulo (como hoje).
- As linhas são lidas em lote (uma consulta por chamada) e as novas gravadas com um `upsert` só.
- Pedidos já concluídos congelam na primeira consulta depois do deploy, com a tabela atual, que é o
  valor que já aparece hoje.
- A resposta do relatório da central e do extrato da loja **não muda de formato**: `faixa`
  (`de_km`, `ate_km`, `acima`, `motoboy`, `loja`), `valor_motoboy` e `valor_loja` passam a vir da
  linha congelada.

### Rotas do motoboy

Grupo `fleetbase.api` (o mesmo da API v1 que o app já usa), com limite de 60 chamadas por minuto por
usuário:

- **`GET v1/entregas/motoboy/ganhos?inicio=AAAA-MM-DD&fim=AAAA-MM-DD`**
  - Pedidos **concluídos** do motoboy (`driver_assigned_uuid`) no período, pela data de conclusão no
    fuso da organização: o mesmo critério do pagamento (`pedidosConcluidos`).
  - Período de até 3 meses (92 dias), senão 422 "Escolha um período de até 3 meses.".
  - Calcula até 10 rotas novas por chamada (como o extrato da loja); as que sobrarem voltam sem km e
    entram em `pendentes`.
  - Resposta:
    ```json
    {
      "inicio": "2026-10-01", "fim": "2026-10-03", "pendentes": 0,
      "totais": { "entregas": 23, "km": 71.4, "valor": 184.0 },
      "entregas": [
        { "pedido": "order_…", "concluido_em": "2026-10-03T19:42:00-03:00", "loja": "Loja Centro",
          "destino": "Rua X, 120, Jardim Paulista", "km": 3.21, "aproximado": false,
          "faixa": { "de_km": 3, "ate_km": 4, "acima": false }, "valor": 8.0 }
      ]
    }
    ```
  - `aproximado` = o km veio da estimativa (`fonte` = `estimativa`). `concluido_em` já vem no fuso da
    organização. Linha sem km ou sem faixas: `km`/`faixa`/`valor` nulos; `totais.valor` soma só os
    valores presentes.
- **`GET v1/entregas/motoboy/pedidos/{id}/valor`** (`{id}` = `public_id` ou `uuid`)
  - Só responde para pedido da empresa que seja **dele** (`driver_assigned_uuid`) ou **aberto**
    (`adhoc`, sem motoboy e fora de `StatusDoPedido::ENCERRADOS`). Qualquer outro → 404.
  - Calcula a rota na hora se precisar (uma chamada ao OSRM, espera de ~1 s no máximo; se falhar, a
    estimativa linha reta × 1,3, como hoje) e congela o valor.
  - Resposta: `{ "pedido", "km", "aproximado", "faixa": { "de_km", "ate_km", "acima" }, "valor" }`.
    Sem coordenadas: `km` e `valor` nulos. Sem faixas: `valor` nulo.

### Quem é o motoboy

- Vem do token, nunca de parâmetro: o `Driver` da empresa da sessão (`session('company')`) cujo
  `user_uuid` é o usuário da sessão (`session('user')`).
- Só **token de usuário** (Sanctum, formato `id|token`). Chave de API (a `flb_live_` do APK, a da
  integração iFood) → 403, mesmo que o admin dono da chave tenha cadastro de motoboy.
- Usuário sem cadastro de motoboy → 403 "Disponível só para motoboys.".
- Usuário de loja já é barrado antes pelo `ProtegerPortalLoja` (nega `v1/*` por padrão).
- As respostas **nunca** trazem o valor cobrado da loja, a margem nem dados de outro motoboy.

### Console (Pagamento e cobrança)

Uma frase no editor de faixas (`fleet-ops.ui.driver-payouts.*`, pt-br e en-us): a tabela nova vale para
as entregas calculadas a partir de agora; as já calculadas mantêm o valor. O texto "Como funciona" passa a
citar o congelamento.

## 2. App do motoboy (`entregas-navigator`)

### Início = "Meus ganhos"

Tela nova no lugar da `DriverDashboardScreen` (a rota `DriverDashboard` e o nome da aba, "Início",
continuam).

- **Filtro**
  - Atalhos de um toque: Hoje (hoje a hoje) · 7 dias (hoje e os 6 dias anteriores) · Este mês (dia 1
    até hoje) · Mês passado (do dia 1 ao último dia do mês anterior). O atalho cujo período é o
    escolhido fica destacado.
  - Campos **De** e **Até** que abrem o calendário nativo (`@react-native-community/datetimepicker`, já
    instalado e compilado no APK).
  - Sem data futura; período de até 3 meses; se o De passar do Até (ou o contrário), o outro campo se
    ajusta.
- **Período padrão:** ao abrir o app (montagem da tela), sempre o mês atual, do dia 1 até hoje. Trocando
  de aba e voltando, o filtro escolhido continua e a lista recarrega (a corrida recém-concluída aparece).
  Também recarrega quando o app volta para a frente (`useRessincronizar`).
- **Total:** cartão em destaque "Total a receber R$ 184,00" e, abaixo, "23 corridas · 71,4 km". Se
  nenhuma corrida do período tem valor (faixas não cadastradas), o total mostra "—" e o aviso "Os valores
  ainda não foram cadastrados pela central.".
- **Lista** (`SectionList`), agrupada por dia, do mais recente para o mais antigo. Dia e hora saem do
  `concluido_em` como vem do servidor (fuso da organização), sem converter para o fuso do celular, para
  bater com o filtro do período:
  - cabeçalho do dia: "sex, 03/10 · 6 corridas · R$ 48,00";
  - cada corrida: hora da conclusão, loja, destino, km e valor;
  - "≈" antes do km e do valor quando o km é estimado;
  - "calculando…" quando ainda não tem km; com `pendentes > 0`, um aviso no topo: puxe para atualizar.
- **Tocar numa corrida** carrega o pedido (`GET v1/orders/{id}`) e abre os detalhes (`OrderScreen`) na
  própria aba Início, que ganha as telas `Order` e `Entity` na pilha; o voltar retorna à lista.
- **Estados:** carregando; vazio ("Nenhuma corrida concluída neste período."); erro com puxar para
  tentar de novo; período inválido avisado antes de consultar.

### Card de aceitar (`AdhocOrderCard`)

- Logo abaixo de "Pedido disponível por perto", uma faixa verde: **"Entrega de 3,2 km · Você recebe
  R$ 8,00"**.
- Enquanto calcula: "Calculando valor…". Sem faixas cadastradas: só "Entrega de 3,2 km". Se a consulta
  falhar ou o pedido não tiver coordenadas, a faixa some; o aceite continua funcionando.
- Esse km é o da rota loja → cliente (o que é pago). A distância até a coleta continua no "a X de você".

### Detalhes (`OrderScreen`)

- Seção nova **"Valor da entrega"** no alto, logo abaixo dos botões de ação:
  - Distância (loja → cliente): 3,2 km
  - Faixa: de 3 a 4 km (ou "acima de 10 km")
  - Você recebe: R$ 8,00
- Aparece no pedido aberto (modal aberto pelo card) e nos pedidos dele, em andamento ou concluídos.
  Com 404 ou erro, a seção não aparece.

### Peças

- `src/utils/ganhos.ts` (funções puras): período padrão e atalhos, limites do período, formatação de
  R$ (`R$ 1.234,56`, sem depender do `Intl`) e de km (`3,2 km`), agrupamento por dia pela data do
  `concluido_em`.
- `src/hooks/use-valor-da-entrega.ts`: consulta o valor de um pedido, com cache em memória por pedido
  (5 min), compartilhado entre o card e os detalhes.
- `src/components/ValorDaEntrega.tsx`: variante compacta (card) e detalhada (detalhes).
- Chamadas pelo adapter do SDK, com o token do motoboy (o mesmo das outras chamadas `v1/*`).
- Textos novos em `translations/pt.json` e `en.json`.

## 3. Erros

| Situação | O que acontece |
|---|---|
| OSRM fora do ar | Estimativa (linha reta × 1,3), marcada como aproximada ("≈"); refeita quando o OSRM voltar, e aí o valor é recalculado |
| Sem faixas cadastradas | Km aparece, valor não (card só com o km; total "—" com aviso) |
| Rota do valor falha no card | A faixa do valor some; aceitar e dispensar funcionam |
| Ganhos falham | Aviso de erro na tela; puxar para tentar de novo |
| Período maior que 3 meses | O app avisa antes de consultar; o servidor responde 422 se chegar |
| Token que não é de motoboy | 403; o app trata como erro |

## 4. Testes

- **PHP** (`scripts/teste-php`, php-wasm 8.2, com os arquivos reais do Fleet-Ops e do core):
  - congela na primeira vez; tabela nova não muda o congelado; km diferente recalcula; sem faixas não
    grava;
  - o relatório e o extrato continuam com o mesmo formato;
  - rota de ganhos: só os pedidos do motoboy, período pela conclusão, limite de 3 meses, sem valor da
    loja na resposta;
  - rota do valor: pedido dele e pedido aberto respondem; pedido de outro motoboy → 404; chave de API e
    usuário sem motoboy → 403.
- **App** (jest): atalhos de período nas viradas de mês e de ano, limites do período, formatação de R$ e
  km, agrupamento por dia.
- **Manual:** com o APK novo, o valor do card de um pedido aberto = o valor desse pedido nos detalhes e,
  depois de concluído, na tela Pagamento e cobrança; o total do Início de um motoboy no mês = o "a pagar"
  dele em Pagamento e cobrança no mesmo período.

## 5. Entrega

1. API: `bash deploy/atualizar.sh api` (build, migration da tabela nova pelo `deploy.sh`).
2. Console: `bash deploy/atualizar.sh console` (frase das faixas).
3. App: push no `entregas-navigator` → o GitHub Actions gera o APK → instalar nos celulares.

O APK antigo não chama as rotas novas: a API pode ir antes sem risco. Depois do passo 1, o relatório da
central e o extrato da loja já usam os valores congelados.

Documentação: CLAUDE.md (congelamento, tabela nova, rotas do motoboy, Início do app) e o docblock do
`CalculoEntregas`.

## Fora do escopo

- Botão de recalcular valores no console (decidido na conversa).
- Valor da corrida no push de pedido novo e no alarme em tela cheia.
- Filtros por loja ou por situação; corridas canceladas na lista.
- Controle de pagamento (pago / a pagar por período): o total é "a receber" no período escolhido.
- iOS (o app só é gerado e testado para Android).
