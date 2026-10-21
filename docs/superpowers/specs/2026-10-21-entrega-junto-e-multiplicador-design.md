# Entrega junto (2ª tabela de faixas) e multiplicador por período

Decisão do Edgard de 2026-10-21. Dois recursos opcionais no Financeiro, os dois definidos pelo console:

1. **Entrega junto:** uma segunda tabela de faixas de km, com valores próprios, para o pedido que o motoboy pega
   **enquanto já leva outro**. A mesma tabela vale para a 2ª, 3ª, 4ª entrega em diante.
2. **Multiplicador por período:** em dia de chuva ou outra intempérie, a empresa cria um período ("Chuva", ×1,2) e as
   entregas atribuídas dentro dele valem o valor da faixa × o multiplicador. Motoboy e loja têm multiplicadores próprios.

Os dois desligados (segunda tabela vazia e nenhum período) = tudo exatamente como hoje.

## Como é hoje

- Cada pedido vale a faixa do seu km (rota de rua loja → cliente, `CalculoEntregas`), da tabela única
  `entregas.faixas` = `[{ate_km, motoboy, loja}]`. O motoboy que leva dois pedidos recebe duas corridas cheias.
- O valor congela na tabela `entregas_valores_pedido` na primeira vez que o pedido tem km (normalmente no card de
  aceitar). Mudar a tabela de faixas só vale para as entregas calculadas depois. Km diferente recalcula, menos no pedido
  com corrida lançada no Financeiro.
- O `LancadorDeCorridas` lança a corrida (motoboy e loja) com o valor congelado; depois de lançada, não muda.

## Regras

### Entrega junto

- **Segunda tabela:** Setting `company.<uuid>.entregas.faixas_juntas`, mesmo formato e mesmas validações da tabela
  atual (`[{ate_km, motoboy, loja}]`, até `MAX_FAIXAS`), com faixas próprias (os limites de km não precisam ser iguais
  aos da tabela normal). **Vazia = recurso desligado.** Mesma regra da faixa: 0 < km ≤ 1ª → 1ª faixa…; acima da última,
  a última.
- **"Junto" = levando ao mesmo tempo:** no momento em que o pedido ganha motoboy, se ele já tem outro pedido em aberto, o
  pedido novo é "junto" (posição ≥ 2) e usa a segunda tabela. A 1ª entrega continua na tabela normal.
  - **Pedido em aberto do motoboy** = outro pedido da mesma empresa com `driver_assigned_uuid` = ele, status fora de
    `StatusDoPedido::ENCERRADOS` e `updated_at` nas últimas `SituacaoDoMotoboy::HORAS_PEDIDO_EM_ANDAMENTO` horas (o
    mesmo critério do `Candidatos::paradas`, que ignora pedido esquecido aberto).
  - **Posição** = pedidos em aberto dele + 1. Fica gravada (2, 3, 4…) para os selos; a regra de valor só distingue 1 de
    "2 ou mais".
- **A posição fica fixa depois da atribuição.** Se o pedido 1 for cancelado ou concluído, o 2 continua como 2ª.
- **Troca de motoboy** (líder, central, `TrocaDoMotoboy`): a posição é recalculada para o motoboy novo, no momento da troca.
- **Pedido volta a ficar sem motoboy:** a atribuição gravada é apagada; vale de novo a próxima atribuição.
- Segunda tabela vazia no momento do cálculo: o pedido junto usa a tabela normal (a posição continua gravada, mas sem
  efeito no valor e sem selo "2ª entrega").

### Multiplicador por período

- **Período:** nome (até 40 caracteres, ex.: "Chuva"), ×motoboy e ×loja (de 1,00 a 5,00, duas casas; ×1,00 = não muda),
  início e fim (data e hora de Brasília). O fim é obrigatório e no máximo 7 dias depois do início, para ninguém esquecer
  um período ligado.
- **Vale para o pedido atribuído dentro do período** (`início ≤ atribuição < fim`). Pedido atribuído às 17:59 num
  período que começa às 18:00 não tem multiplicador.
- **Períodos não se sobrepõem** (validação no servidor, numa transação com trava das linhas da empresa): no máximo um
  multiplicador por instante, sem regra de combinação.
- **Edição:**
  - agendado (ainda não começou): edita tudo ou apaga;
  - em andamento: só o fim (prorrogar ou "Encerrar agora"). Para mudar o multiplicador, encerre e crie outro;
  - encerrado: não muda (os valores já estão congelados nos pedidos).
- **Atalhos na tela:** "Começar agora" (início = agora, fim sugerido = agora + 3 h, editável) e "Encerrar agora" (fim = agora).

### Valor final

```
tabela  = (posição ≥ 2 e houver valor da segunda tabela) ? segunda tabela : tabela normal
motoboy = round(tabela.motoboy × mult_motoboy, 2)
loja    = round(tabela.loja    × mult_loja,    2)
```

Sem atribuição gravada (pedido ainda aberto, sem motoboy): tabela normal × 1.

## Quando congela

O valor congelado passa a ter duas partes:

1. **Parte do km** (como hoje, no primeiro cálculo com km): a faixa e os valores da **tabela normal e da segunda
   tabela** vigentes naquele momento. Mudar qualquer uma das duas tabelas só vale para as entregas calculadas depois. Km
   diferente recalcula as duas, menos no pedido com corrida lançada.
2. **Parte da atribuição** (nova, quando o pedido ganha motoboy): motoboy, instante, posição e o multiplicador do período
   ativo (uuid, nome, ×motoboy, ×loja, copiados para o período poder mudar sem mexer no pedido).

O valor final sai sempre da fórmula acima, a partir da linha. **Depois de lançada no Financeiro, a corrida não muda**
(regra atual): a atribuição não é regravada em pedido com corrida lançada.

### Onde a atribuição é gravada

- **Observador novo** `ObservadorDoValorDaEntrega`, em `Order::updated` no `AppServiceProvider`, ao lado dos outros:
  quando `driver_assigned_uuid` muda, calcula a posição e o multiplicador e grava a parte da atribuição (e a parte do km,
  se o pedido ainda não tem). Independe da distribuição estar ligada. Nunca lança (vai para o log), como o
  `ObservadorDaDistribuicao`.
- **Reserva para o que escapar do observador** (`saveQuietly`, bulk-assign pelo query builder): o `CalculoEntregas`, ao
  montar a entrega de um pedido concluído que tem motoboy mas não tem atribuição gravada para ele, reconstitui a partir
  dos horários. Instante = `started_at` (sem ele, `dispatched_at`). Posição = 1 + outros pedidos do mesmo motoboy aceitos
  antes desse instante e não concluídos ou cancelados até ele. Grava como se fosse o observador. Pedido de antes do
  deploy fica assim também: só que, sem segunda tabela congelada e sem período, o valor é o de hoje.

## Prévia antes do aceite

- `CalculoEntregas::valorDoPedido($pedido, ?Driver $motoboy)`: com o motoboy e o pedido ainda sem atribuição, devolve a
  **prévia para ele** (posição pelos pedidos em aberto dele agora, multiplicador do período ativo agora), sem gravar a
  parte da atribuição. A parte do km continua congelando como hoje.
- A rota do valor do app (`MotoboyController@valor`) passa o motoboy da sessão.
- **Push de alarme** (`CartaoDoAlarme::doPedido`): ganha o motoboy quando o push é para um motoboy só (oferta da
  distribuição); no aviso aberto a vários, prévia sem posição (tabela normal × multiplicador ativo).
- A prévia pode diferir do valor gravado se algo mudar entre ver e aceitar (ele concluiu o outro pedido, o período
  começou ou acabou). Vale o da atribuição.

## Dados

### Migration: `entregas_valores_pedido` (colunas novas, todas nullable)

| Coluna | Tipo | O quê |
|---|---|---|
| `junto_de_km`, `junto_ate_km` | decimal(8,2) | faixa da segunda tabela para este km |
| `junto_acima` | boolean | acima da última faixa da segunda tabela |
| `junto_motoboy`, `junto_loja` | decimal(10,2) | valores da segunda tabela (null = tabela vazia no cálculo) |
| `motoboy_uuid` | uuid | motoboy da atribuição |
| `atribuido_em` | datetime | instante da atribuição (Brasília) |
| `posicao` | tinyint unsigned | 1, 2, 3… |
| `multiplicador_uuid` | uuid | período aplicado (null = sem período) |
| `multiplicador_nome` | varchar(40) | cópia do nome |
| `mult_motoboy`, `mult_loja` | decimal(4,2) | cópias dos fatores |

As colunas atuais (`de_km`, `ate_km`, `acima`, `valor_motoboy`, `valor_loja`) continuam sendo a **tabela normal**. Os
valores finais não são gravados nessa tabela: saem da fórmula, num lugar só (`valorDaLinha`). As colunas da parte do km
entram no `COLUNAS_ATUALIZADAS` (recongelar); as da atribuição são gravadas por um método próprio do
`ValoresCongelados`, para o recongelamento do km não apagá-las.

### Migration: `entregas_multiplicadores` (tabela nova)

`id`, `uuid` (único), `company_uuid` (índice com `inicio`), `nome`, `mult_motoboy` decimal(4,2), `mult_loja`
decimal(4,2), `inicio` datetime, `fim` datetime, `criado_por_uuid`, `encerrado_por_uuid` (nullable), `created_at`,
`updated_at`, `deleted_at` (só agendado é apagado).

O `deploy.sh` roda as duas no banco principal e no sandbox, como as outras `entregas_*`.

### Lançamento da corrida

Os `detalhes` da corrida ganham, na conta do motoboy e na da loja:

- `junto` (posição, só quando a segunda tabela foi usada);
- `multiplicador`: `{nome, fator}`, com o fator **daquela conta** (o motoboy nunca vê o ×loja, e vice-versa), só
  quando o fator ≠ 1.

A `faixa` dos detalhes passa a ser a da tabela usada.

## Servidor (`api/app`)

- `App\Support\Entregas\Financeiro\Multiplicadores` (novo): CRUD dos períodos, validação de sobreposição, `ativoEm($empresa, $instante)`.
- `App\Support\Entregas\ValorDaEntrega` (novo, funções puras): posição a partir dos pedidos em aberto, fórmula do valor
  final, reconstituição a partir dos horários. Testável no php-wasm sem banco.
- `CalculoEntregas`: segunda tabela (`faixasJuntas()`, `CHAVE_FAIXAS_JUNTAS`), parte do km congelando as duas tabelas,
  `valorDaLinha` com a fórmula e os selos, `valorDoPedido` com o motoboy, reserva da atribuição em `entregas()`. As linhas
  do relatório ganham `posicao`, `tabela` (`normal`/`junto`) e `multiplicador`.
- `ObservadorDoValorDaEntrega` (novo) registrado no `AppServiceProvider`.
- `PagamentoMotoboysController`: `GET` devolve também `faixas_juntas`; `PUT .../faixas-juntas` substitui a segunda tabela
  (mesma validação; lista vazia desliga).
- `FinanceiroController` (ou controller novo `MultiplicadoresController`): `GET/POST financeiro/multiplicadores`,
  `PATCH financeiro/multiplicadores/{uuid}` (fim, ou tudo no agendado), `POST .../{uuid}/encerrar`, `DELETE .../{uuid}`
  (só agendado). Só admin (`negarSeNaoAdmin`), como o resto do Financeiro.
- `LancadorDeCorridas::detalhes`: `junto` e `multiplicador` por conta.
- `GanhosDoMotoboy::linha` e `::valor`: `junto` (bool) e `multiplicador` (`{nome, fator}` do motoboy, ou null). Campos
  novos e opcionais: o APK antigo ignora.

## Console (Fleet-Ops → Financeiro)

- **Tabela de km e relatório:** abaixo da tabela atual, a seção **"A partir da 2ª entrega junto"**, com o mesmo editor de
  faixas e a ajuda "Vale quando o motoboy pega o pedido enquanto ainda leva outro. Deixe vazia para pagar todas as
  entregas pela tabela acima." No relatório e no CSV: colunas "Junto" (2ª, 3ª…) e "Multiplicador" (nome e fatores).
- **Multiplicadores** (rota nova `financeiro.multiplicadores`, item no menu do Financeiro, só admin): o período em
  andamento em destaque, com "Encerrar agora"; a lista com nome, ×motoboy, ×loja, início, fim e situação (agendado, em
  andamento, encerrado); "Novo período" e "Começar agora" num modal (`keepOpen`, como os outros do Financeiro). O erro de
  sobreposição diz com qual período bateu.
- Funções puras em `addon/utils/financeiro-multiplicadores.js` (situação do período, texto "×1,2", validação do
  formulário), com teste em `scripts/teste-portal/`.
- Traduções `fleet-ops.ui.financeiro.*` em pt-BR e en; `i18n-check` com exit 0.

## Portal da loja

No extrato e nos itens da fatura (console, portal e impressão), a corrida mostra o selo só quando o valor da loja mudou:
"2ª entrega" quando a segunda tabela foi usada, e "Chuva ×1,1" quando o ×loja ≠ 1.

## App do motoboy (`entregas-navigator`, APK novo)

- Card de aceitar, detalhes do pedido, Início (extrato) e Meus ganhos: valor final com os selos "2ª entrega" e
  "Chuva ×1,2" (nome e fator do motoboy), quando vierem.
- O APK novo vira versão obrigatória (trava "Atualize o app"). A API vai antes: os campos novos são opcionais.

## Fora do escopo

- Multiplicador automático por previsão do tempo.
- Valor diferente para a 3ª e a 4ª entrega (uma regra só da 2ª em diante, decisão do Edgard).
- Recalcular corridas já lançadas quando o período ou as tabelas mudam (correção continua por ajuste).
- Aviso no app de "multiplicador ativo agora" fora do card.

## Riscos

- **A ordem de aceite manda.** Se o motoboy aceita dois pedidos quase juntos, o segundo aceito é a 2ª entrega, mesmo
  que ele entregue esse primeiro.
- **Prévia ≠ valor gravado** quando a situação muda entre ver o card e aceitar (ver "Prévia antes do aceite").
- **Atribuição fora do observador** cai na reserva por horários, que pode errar a posição se os horários do pedido
  estiverem incompletos.

## Testes

- php-wasm (`scripts/teste-php/`): `valor-da-entrega.php` (posição, fórmula, arredondamento, segunda tabela vazia,
  reconstituição por horários), `multiplicadores.php` (sobreposição, edição por situação, `ativoEm` nas bordas
  início/fim), casos novos em `financeiro-*.php` (detalhes por conta, sem vazar o ×loja ao motoboy, corrida lançada não
  muda) e no teste do congelamento (recongelar km não apaga a atribuição; troca de motoboy recalcula).
- `scripts/teste-portal/financeiro-multiplicadores.test.mjs` para as funções da tela.
- Conferência manual em produção: criar um período curto de ×1,01 e uma segunda tabela, fazer dois pedidos com o mesmo
  motoboy numa loja de teste (o Terraço é loja real: não usar sem combinar) e conferir card, extrato, relatório e fatura.

## Documentação

Atualizar `api/app/Support/Entregas/Financeiro/CLAUDE.md` (regras do valor final e da atribuição),
`packages/fleetops/CLAUDE.md` (Tabela de km e Multiplicadores), `api/app/Http/Controllers/Entregas/CLAUDE.md` (selos do
app) e `docs/historico.md`.
