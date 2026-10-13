# Revisão do Financeiro: correções do console ([C])

Ramo `worktree-agent-a66e6b83e0e347f9a` (worktree `.claude/worktrees/agent-a66e6b83e0e347f9a`), a partir da `main`
`44884428` (fast-forward). Só frontend, engine `packages/fleetops`. Itens de `revisao.md` marcados [C].

## O que mudou, item a item

- **3. "1.500" virava R$ 1,50.** `numeroDigitado` (`utils/financeiro.js`) agora tem três formas aceitas, sem o sinal:
  milhar com ponto (`^[1-9]\d{0,2}(\.\d{3})+(,\d{1,2})?$`: "1.500", "1.234,56"), vírgula decimal ("30", "30,5") e
  ponto decimal com 1 ou 2 casas ("12.5"). Ponto seguido de 3 dígitos é sempre milhar; mais de 2 casas ("1,505",
  "1.5000") e "0.500" viram NaN (erro no formulário). O `lerValor` da fatura (`utils/financeiro-faturas.js`) passou a
  chamar a mesma função (o `financeiro-faturas.js` agora importa do `financeiro.js`, também puro).
  - `valorParaEnviar` manda sempre o texto normalizado ("1500.00", "-2.50"): pagamento (multipart, via
    `camposDoPagamento`), Lançar (antes ia número JSON) e ajuste na fatura (antes número JSON). O servidor pode recusar
    "1.500" sem vírgula com 422 sem afetar o console.
  - `valorInterpretado` mostra "= R$ 1.500,00" embaixo do campo no Registrar pagamento/recebimento e no Lançar
    (chave `fleet-ops.ui.financeiro.valor-interpretado`). O ajuste na fatura já mostrava "Desconto/Acréscimo de R$ …"
    com o valor lido, que agora segue a regra nova.
- **4. Recebimento aberto pela fatura.** O modal `registrar-pagamento` recebe `saldo` (saldo real da conta) e
  `sugestao` (o em aberto da fatura); o valor sugerido sai de `valorSugerido` (sugestão positiva, senão saldo positivo).
  Sem `saldo`, o modal busca `GET financeiro/{lojas|motoboys}/{id}/extrato?inicio=hoje&fim=hoje` (o `saldo` do extrato
  é o da conta inteira) e mostra "saldo atual …" até chegar; se falhar, só não avisa o excedente. O aviso de excedente
  compara com o saldo real. Na loja, o modal diz "O recebimento quita primeiro a fatura mais antiga em aberto da loja."
  (`pagar.ordem-da-quitacao`). Lojas e faturas manda o `saldo` da linha da aba Lojas; a tela da fatura deixa o modal
  buscar; o extrato da loja e as telas de motoboy já mandavam o saldo real.
- **8 (texto).** Com algum "sem valor" na lista Atenção, o painel mostra `painel.sem-valor-ajuda`: corrigir o km ou a
  Tabela de km; a conferência diária lança as corridas dos últimos 30 dias (o número do item 8 [S]); mais antigos,
  `php artisan entregas:financeiro-lancar-historico`. **Se o servidor não passar a conferência para 30 dias, ajuste o
  texto.**
- **9. Período congelado.** Painel, Motoboys, extrato do motoboy e extrato da loja guardam o `atalho` em uso (padrão
  `this-month`) e o recalculam no `setupController` (`controller.atualizarPeriodo()` → `periodoAoAbrir`). Mudar uma
  data à mão zera o atalho (as datas ficam); clicar num atalho o grava. O extrato da loja passou a usar o
  `periodoDoAtalho` (atalho novo `last-90-days`; o template manda `this-month`/`last-month`/`last-90-days`).
- **15 (console).** `estaExcluido(registro)` = `registro?.excluido === true` (campo ausente = não excluído). Selo
  "Excluído" (com a ajuda no `title`) ao lado do nome na lista de motoboys e faixa com o selo no topo do extrato
  (`conta.excluido`). Chaves `financeiro.motoboys.excluido` e `excluido-ajuda`. Pagar/Lançar continuam iguais (o
  servidor passa a aceitar).
- **17. CSV com fórmula.** `celulaCsv` (exportada de `utils/financeiro.js`) copia a regra do portal
  (`INICIO_DE_FORMULA = /^[=+\-@\t\r]/`, número formatado `^-?\d+(,\d+)?$` passa): texto que começa como fórmula ganha
  um apóstrofo. Vale no CSV do extrato do motoboy, da fatura e do extrato da loja. Não dá para importar do
  customer-portal (outro engine): **se a regra mudar lá, mude aqui também.**
- **20. Formatadores.** `formatadores(idioma)` guarda um conjunto por idioma (Map) e ganhou `instante` (data e hora de
  um ISO; inválido = ''). Fatura, extrato da loja, Lojas e faturas, relatório (Tabela de km) e o modal de ajuste usam
  esses formatadores em vez de criar `Intl.*` a cada célula.

## Para o `packages/fleetops/CLAUDE.md` (seção do Financeiro)

- Valor digitado: uma regra só (`numeroDigitado`; `lerValor` = a mesma); "1.500" = mil e quinhentos; ao servidor vai
  sempre `valorParaEnviar` ("1500.00"); o campo mostra o valor interpretado.
- `registrar-pagamento`: opções `saldo` (real; sem ele o modal busca no extrato) e `sugestao` (valor sugerido).
- Período das telas: atalho recalculado ao voltar (`periodoAoAbrir`); datas à mão ficam.
- CSV: `celulaCsv` com a regra de fórmula do portal (cópia).
- Selo "Excluído" pelo `excluido` do servidor (`estaExcluido`).

## Verificação

- `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs`: 195 passam (testes
  novos em `financeiro.test.mjs` e `financeiro-faturas.test.mjs`).
- `node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal`:
  exit 0 (com a junção `console/node_modules`, removida depois com `cmd /c rmdir`, não commitada).
- Parse `@babel/parser` 7.29.8 (decorators-legacy, classProperties, sem import duplicado) dos 18 JS alterados: ok.

## Pendências

- No navegador (sem API aqui): "= R$" embaixo dos campos, busca do saldo no modal aberto pela fatura, selo
  "Excluído" com o servidor novo, período depois da virada do dia/mês, CSV aberto no Excel.
- Item 8: o texto cita 30 dias, contando com a correção [S] da conferência.
