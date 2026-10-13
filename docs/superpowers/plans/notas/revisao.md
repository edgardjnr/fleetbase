# Revisão de código do Financeiro (b788ab95..37ba21ba + app financeiro-app)

Achados confirmados no código. Nada crítico; isolamento entre lojas/motoboys e admin-only conferidos.
Dono da correção entre colchetes: [S] servidor, [C] console, [A] app.

## Alto

1. [S] **"Não pagar"/"Não cobrar" seguido de estorno automático debita duas vezes.** `LancadorDeCorridas::estornarCancelados`
   (`LancadorDeCorridas.php:196-212`), `Faturas::naoCobrar` (`Faturas.php:239-277`), ajuste com pedido no
   `FinanceiroController.php:244-261`. Corrida R$ 10 no rascunho → "Não cobrar" (−10) → pedido cancelado sem pagamento →
   conferência estorna a corrida (−10 de novo). Igual no motoboy. Correção: no `estornarCancelados`, descontar os ajustes
   negativos com o mesmo `order_uuid` na mesma conta (e os estornos deles) e estornar só o que sobra (ou nada); no lançamento
   de ajuste com pedido, recusar quando a corrida daquele pedido já foi estornada (o caminho inverso já é recusado).

## Médio

2. [S] **Pedido achado pelo número pode ser o errado.** `FinanceiroController.php:583-588` usa
   `orWhere('internal_id', $codigo)->first()`; o `internal_id` (número iFood/origem) se repete entre dias e lojas.
   Correção: aceitar public_id/uuid; por `internal_id`, buscar só entre pedidos com corrida lançada **na conta escolhida**,
   e se ainda houver mais de um, 422 "Há mais de um pedido com esse número; use o código do pedido (order_…)".
3. [C] **"1.500" digitado vira R$ 1,50.** `packages/fleetops/addon/utils/financeiro.js:89-102`,
   `utils/financeiro-faturas.js:172-179`. Correção: `^\d{1,3}(\.\d{3})+(,\d{1,2})?$` = milhar com ponto; mostrar ao lado do
   campo o valor interpretado ("= R$ 1.500,00") em todos os campos de valor (pagamento, lançar, ajuste na fatura).
   [S] no servidor (`FinanceiroController.php:468-483`, `FinanceiroLojasController.php:317-331`): recusar com 422 texto
   no formato de milhar com ponto sem vírgula (`^\d{1,3}(\.\d{3})+$`), para não gravar 1,5 por engano vindo de outro cliente.
4. [C] **Recebimento aberto pela fatura usa o "em aberto" dela como saldo da loja.** `controllers/financeiro/fatura.js:203-213`,
   `controllers/financeiro/lojas.js:133-142`, `components/modals/registrar-pagamento.js:37-39`. O servidor quita a fatura mais
   antiga primeiro. Correção: o modal recebe `saldo` = saldo real da loja (buscar se não houver) e `sugestao` = em aberto da
   fatura; o aviso de excedente compara com o saldo real; texto no modal: "O recebimento quita primeiro a fatura mais antiga
   em aberto da loja."
5. [S] **Rotina de avisos reavalia para sempre as faturas pagas, cada uma lendo a conta inteira.**
   `FinanceiroAvisos.php:31-49`, `AvisosDaFatura.php:115`. Correção: calcular as situações uma vez por loja no comando
   (`Faturas::situacoesDaEmpresa` ou por loja) e limitar a busca a `vencimento >= hoje − 60 dias`.
6. [S] **Corrida estornada não volta se o pedido voltar a ser pagável** (cancelado pago marcado depois, ou volta a
   completed). `LancadorDeCorridas.php:82,91,149-157`. Correção: na conferência, pedidos do relatório cuja corrida da conta
   está estornada (e cujo estorno foi automático, motivo `MOTIVO_CANCELADO`) → relançar com `chave_unica`
   `corrida:<conta>:<order>:<n>` (n = 2, 3…); teste cobrindo.
7. [S] **Lançamento da loja nunca é refeito se faltar.** `LancadorDeCorridas.php:78-94` (sem transação),
   `:149-157` (só olha a corrida do motoboy). Correção: os dois inserts numa transação; a conferência também procura pedidos
   com corrida do motoboy, sem corrida da loja e com `customer_uuid` de Vendor, e lança a da loja.

## Baixo/médio

8. [S] **Conferência olha só 7 dias** (`FinanceiroConferir.php:37-39`); painel olha 30. Correção: 30 dias (respeitando o
   corte) e o aviso "sem valor" do painel cita o `entregas:financeiro-lancar-historico` [C texto].
9. [C] **Período padrão congelado no dia em que a tela abriu** (`controllers/financeiro/{motoboy,motoboys,painel,loja}.js`).
   Correção: recalcular no `setupController` enquanto o usuário não escolheu um período.
10. [S] **Rascunho só nasce se o ciclo fechou "ontem"** (`FinanceiroRascunhos.php:33-37`). Correção: procurar fechamentos
    dos últimos 7 dias sem rascunho/emitida com aquele `periodo_fim`.
11. [S] **Estorno de corrida que já está num rascunho fica fora dele** (`Lancamentos.php:111`). Correção: se o lançamento
    estornado está num rascunho, o estorno entra no mesmo rascunho (`fatura_uuid` igual).

## Baixo

12. [S] `session(['company' => ...])` no worker da fila persiste entre jobs (`LancadorDeCorridas.php:53,185,230`):
    restaurar a sessão anterior no fim.
13. [S] `insertOrIgnore` engole qualquer erro (`Lancamentos.php:47`): se voltar `null`, conferir se a `chave_unica` existe;
    se não existir, lançar exceção.
14. [S] Fatura com total R$ 0 é emitida e enviada (`Faturas.php:150`): recusar total ≤ 0 com 422 ("Fatura sem valor a
    cobrar; cancele o rascunho").
15. [S] Motoboy excluído (soft delete) com saldo some da lista e não aceita pagamento, mas entra no "a pagar"
    (`FinanceiroController.php:109,570`): incluir os excluídos com saldo ≠ 0 na lista (marcados `excluido: true`) e aceitar
    pagamento/lançamento para eles. [C] mostrar o selo "excluído".
16. (aceito, não corrigir) Loja de tipo diferente de `customer`.
17. [C] CSV do console sem proteção contra fórmula (`utils/financeiro-faturas.js:246`, `utils/financeiro.js:384`): reusar a
    regra do portal (`INICIO_DE_FORMULA`).
18. [A] App: totais do topo usam créditos e débitos brutos (`MeusGanhosScreen.tsx:509-510`): "ganhos" = soma de corridas,
    diárias, bônus e ajustes positivos do período (sem estornos de pagamento); "recebido" = soma dos pagamentos não
    estornados do período. Calcular no app a partir dos lançamentos do período (função pura testada).
19. [A] App: comprovante HEIC no recibo (`src/utils/extrato.ts:213-224`): heic/heif → "Abrir comprovante" no navegador.
20. [C] Formatadores `Intl` recriados a cada célula (`utils/financeiro.js:65-84`): cache por idioma.
