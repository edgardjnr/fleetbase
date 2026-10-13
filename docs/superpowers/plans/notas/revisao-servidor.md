# Revisão do Financeiro: correções do servidor ([S])

Itens da `revisao.md` marcados [S]: 1, 2, 3 (servidor), 5, 6, 7, 8 (servidor), 10, 11, 12, 13, 14, 15 (servidor). O 16
ficou como está (aceito). Cada item com teste que reproduzia o problema (falhou antes, passa depois). Nenhum item se
mostrou falso.

## Texto pronto para `api/app/Support/Entregas/Financeiro/CLAUDE.md`

Corrige e completa os textos da B1–B4 e da integração.

### Lançamento das corridas (`LancadorDeCorridas`)

- **As duas corridas do pedido numa transação** (`lancar`): a do motoboy não fica sem a da loja por uma falha no meio.
- **Conferência** (`entregas:financeiro-conferir`, a cada 5 min): olha **30 dias** para trás, respeitando o corte
  (`FinanceiroConferir::DIAS_DA_CONFERENCIA = 30`, a mesma janela do "sem valor" do painel). Além dos pedidos sem a
  corrida do motoboy (`pedidosSemLancamento`), procura os que **têm a do motoboy, não têm a da loja e o cliente é uma
  loja** (`pedidosSemLancamentoDaLoja`, `whereExists` em `vendors`, inclusive excluída) e lança a da loja. Depois:
  `estornarCancelados` e `relancarEstornados` (últimos 30 dias).
- **Estorno automático sem debitar duas vezes** (`estornarCancelados` + `estornosDevidos`): para cada conta e pedido, o
  líquido = corridas + ajustes negativos com o pedido + os estornos desses dois. Cada corrida ainda não estornada é
  estornada só até esse líquido (`Lancamentos::estornar(..., $parte)`): depois de um "Não pagar"/"Não cobrar" do valor
  inteiro, nada; depois de um desconto de R$ 3 numa corrida de R$ 8, R$ 5. Ajuste já estornado não desconta. O pedido
  cancelado fica com zero na conta.
- **Corrida que volta** (`relancarEstornados`): o pedido cuja **última** corrida na conta tem o estorno automático
  (motivo `MOTIVO_CANCELADO`, `criado_por` nulo) e que voltou ao relatório (cancelado pago marcado depois, ou concluído de
  novo) ganha uma corrida nova com **o valor que o estorno tirou** (o desconto de antes continua), a `data_referencia` e
  os `detalhes` da corrida estornada, `chave_unica` `corrida:<conta_tipo>:<order_uuid>:<n>` (n = 2, 3…) e o motivo
  `MOTIVO_RELANCADO` ("Pedido voltou a valer depois do cancelamento"). Estorno feito à mão pelo admin não volta. Pode
  repetir (cancela de novo → estorna a nova; volta → `:3`). Só a conferência relança (o job do observador não).
- **Sessão**: `naEmpresa()` põe a empresa na sessão só enquanto o `CalculoEntregas` roda e devolve a anterior no
  `finally` (no worker da fila, a empresa de um job não vaza para o seguinte).

### Livro (`Lancamentos`, `Quitacao`)

- `Lancamentos::inserir` com `chave_unica`: se o `insertOrIgnore` não gravou e a chave **não** existe (o INSERT IGNORE
  engoliu outro erro: dado truncado, outra chave única), lança `\RuntimeException`; chave existente continua `null`.
- `Lancamentos::estornar($lancamento, $usuario, $motivo, ?float $parte = null)`: `$parte` estorna só uma parte (o
  estorno automático). O estorno de um lançamento de loja que **está num rascunho entra no mesmo rascunho** (relê o
  `fatura_uuid` e trava a fatura na transação, para não ser emitida no meio); de fatura emitida ou sem fatura, fica sem
  fatura (próximo rascunho).
- `Quitacao::creditos`: primeiro o débito com `estorno_de` abate o crédito estornado; depois o ajuste negativo com pedido
  abate **as corridas do pedido que ainda têm valor** (mais de uma quando a corrida foi relançada); o resto vai ao caixa.

### API do console

- **Pedido do ajuste** (`POST financeiro/lancamentos`): public_id ou uuid; pelo número (`internal_id`, que se repete
  entre dias e lojas) só entre os pedidos com corrida lançada numa das contas escolhidas; com mais de um, 422 "Há mais
  de um pedido com esse número; use o código do pedido (order_…)"; sem nenhum, 422 "Pedido não encontrado.".
- **Ajuste negativo com pedido cuja corrida já foi estornada** (em alguma das contas, sem corrida valendo): 422 "A
  corrida deste pedido já foi estornada." (o caminho inverso é o `estornosDevidos`).
- **Valor "1.500"** (milhar com ponto e sem vírgula, `^[+-]?\d{1,3}(\.\d{3})+$`): 422
  `FinanceiroController::MILHAR_SEM_CENTAVOS` ("Valor ambíguo: para mil e quinhentos, escreva 1500 ou 1.500,00 (vírgula
  antes dos centavos).") no lançar, no registrar pagamento/recebimento e no ajuste da fatura
  (`FinanceiroLojasController@ajuste`). "1.500,00", "1500" e "1.50" continuam valendo.
- **Motoboy excluído (soft delete)**: `GET financeiro/motoboys` devolve `excluido: true|false` em cada motoboy e inclui
  o excluído **com saldo ≠ 0** (com saldo zero, fica fora). Lançar, pagar e abrir o extrato aceitam o excluído
  (`Driver::withTrashed()`), para o acerto final; o push do pagamento não sai para ele. O extrato do console traz
  `conta.excluido` (só na conta motoboy; o extrato público do app não tem o campo).

### Faturas

- **Emitir** recusa total ≤ 0: `\DomainException` "Fatura sem valor a cobrar; cancele o rascunho." (422 no controller;
  antes, só o negativo era recusado e a fatura de R$ 0 ia por e-mail).
- **`entregas:financeiro-rascunhos`** procura os fechamentos de ciclo dos **7 dias até ontem**
  (`FinanceiroRascunhos::DIAS_PARA_TRAS`, do mais antigo ao mais novo) e cria o rascunho se a loja ainda **não tem
  fatura** (rascunho, emitida ou cancelada) com aquele fim (`jaTemFatura`): o agendador fora do ar no dia seguinte não
  perde o ciclo, e o rascunho descartado pelo admin não volta. Substitui a armadilha "se o agendador ficar fora do ar no
  dia seguinte ao fechamento, aquele ciclo não ganha rascunho" (agora só depois de 7 dias).
- **`entregas:financeiro-avisos`**: decide o aviso pela data, lê a situação de todas as emitidas **uma vez por empresa**
  (`Faturas::situacoesDaEmpresa`) e pula a paga sem chamar a `AvisosDaFatura`; só entram faturas com vencimento nos
  **últimos 60 dias** (`FinanceiroAvisos::DIAS_PARA_TRAS`; a paga sempre tem aviso pendente e seria reavaliada para
  sempre). A vencida há mais de 60 dias sem aviso não recebe mais.

### Testes

- `financeiro-lancador.php`: estorno depois de "Não pagar"/"Não cobrar"/desconto parcial, relançar (inclusive pela
  conferência e de novo com `:3`), transação das duas corridas, conferência da loja que faltou, janela de 30 dias, sessão
  devolvida (lancar, lancarPedido, job, estornar, relançar).
- `financeiro-quitacao.php`: duas corridas do mesmo pedido.
- `financeiro-console.php`: pedido pelo número repetido, ajuste de corrida estornada, "1.500", motoboy excluído.
- `financeiro-lojas.php`: "1.500" no ajuste da fatura, rascunho de fechamento perdido e descartado, total zero.
- `financeiro-faturas.php`: estorno no rascunho, emitir sem valor.
- `financeiro-pagamentos.php`: insert ignorado sem a chave.
- `financeiro-portal-emails.php`: avisos (pagas lidas uma vez, vencida há 70 dias fora).
- `stubs-ifood-fleetbase.php`: o `Driver` falso tem soft delete (`deleted_at`; `where`/`whereIn` não acham o excluído,
  `withTrashed()` acha).

## Campos e mensagens novos da API (para o console)

- `GET financeiro/motoboys` → cada motoboy com **`excluido`** (bool).
- `GET financeiro/motoboys/{id}/extrato` → **`conta.excluido`** (bool; só no console).
- 422 novas: "Valor ambíguo: para mil e quinhentos, escreva 1500 ou 1.500,00 (vírgula antes dos centavos).", "Há mais
  de um pedido com esse número; use o código do pedido (order_…)", "A corrida deste pedido já foi estornada.", "Fatura
  sem valor a cobrar; cancele o rascunho." (substitui "O total da fatura não pode ser negativo.").
- Lançamento novo visível no extrato: corrida relançada com o motivo "Pedido voltou a valer depois do cancelamento".

## Ao atualizar o fleetops-api / core-api, confira

- `Driver` continua com `SoftDeletes` (`withTrashed()` e `deleted_at`).
- `orders.customer_uuid` ainda aponta para `vendors.uuid` (a conferência da loja que faltou usa `whereExists` em
  `vendors`).
