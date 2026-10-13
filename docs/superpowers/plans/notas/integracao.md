# Integração do Financeiro (onda 3): notas

Ramo `worktree-agent-a4b7dad611c5f66dd` (worktree `.claude/worktrees/agent-a4b7dad611c5f66dd`), avançado por
fast-forward até a `main` `37ba21ba` (B1–B4 e F1–F3). App do motoboy: worktree `C:\tmp\fin-app`, ramo `financeiro-app`
(nenhuma correção foi preciso lá).

## 1. Contrato ponta a ponta

Cada chamada das telas conferida contra o servidor: método, caminho, campos enviados, formato da resposta e cada campo
lido (inclusive aninhados, sinal, nulos e tipos). Decimais: o servidor sempre converte (`round((float) …, 2)`), nenhum
valor sai como texto; as telas também aceitam texto (`Number(...)`).

| Tela → rota | Envia | Lê | Resultado |
|---|---|---|---|
| Console Painel → GET `financeiro/painel?inicio&fim` | período | `a_pagar`, `adiantado`, `a_receber`, `vencido`, `nao_faturado`, `periodo.{cobrado,pago_motoboys,margem}`, `atencao[].{tipo,fatura,pedido,numero,loja,valor,vencimento}` | ok; item sem `pedido`/`fatura` (corrida sem detalhes) montaria `LinkTo` sem modelo → **corrigido no front** (`itemDeAtencao` devolve null) |
| Console Motoboys → GET `financeiro/motoboys` | período | `id`, `nome`, `saldo` (com sinal), `ultimo_pagamento.{valor,data}` (ou null), `corridas`, `valor_corridas`, `tem_pix` | ok |
| Console extrato do motoboy → GET `financeiro/motoboys/{id}/extrato` | `inicio`, `fim`, `tipo` só filtrado | `conta.{nome,pix.{chave,tipo}}`, `saldo`, `saldo_anterior`, `totais.{creditos,debitos}` (positivos), `ultimo_pagamento.{valor,data,forma}`, Lancamento completo (`pagamento.comprovante_url`, `criado_por.nome`, `observacao_interna`) | ok |
| PUT `financeiro/motoboys/{id}/pix` | `{tipo, chave}` | só 2xx (relê) | ok |
| Modal Lançar → POST `financeiro/lancamentos` | `conta_tipo`, `contas[]` (public_id), `tipo`, `valor` (número com sinal), `data`, `pedido`, `motivo`, `observacao_interna` | `lancamentos.length` | ok |
| Modal Estornar → POST `lancamentos/{id}/estornar` / `pagamentos/{uuid}/estornar` | `{motivo}` / nada | só 2xx | ok |
| Modal Registrar pagamento/recebimento → POST `financeiro/pagamentos` (multipart) | `conta_tipo`, `conta`, `valor` ("150.50"), `forma`, `data`, `observacao_interna`, arquivo `comprovante` (+ opção `fatura`, ignorada) | só 2xx | ok (o `fetch.request` com `headers` em `data` tira o `Content-Type`: o `...data` sobrescreve) |
| Console Configurações → GET/PUT `financeiro/configuracoes` | `{corte, cobranca_padrao:{ciclo,dia_fechamento,prazo_dias}}` (números) | `corte`, `cobranca_padrao` (inteiros), `ligada` | ok; servidor validava com cópia das regras → **agora `CicloDeCobranca::validar`** |
| Console Lojas e faturas → GET `financeiro/faturas`, GET `financeiro/lojas` | nada (filtro no cliente) | Fatura (`id`, `numero`, `loja.{id,nome}`, período, `vencimento`, `situacao`, `pagamento`, `vencida`, `total`, `recebido`, `em_aberto`); Lojas (`id`, `nome`, `saldo`, `em_aberto`, `vencido`, `nao_faturado`, `ciclo.{ciclo,dia_fechamento,prazo_dias,padrao}`, `ciclo_atual.{inicio,fim}`, `email`) | `email` olhava só o Vendor, mas a fatura vai também aos usuários do portal → **corrigido no servidor** (sem e-mail no Vendor, o do primeiro usuário ativo) |
| PUT `financeiro/lojas/{id}/ciclo` | `{ciclo, dia_fechamento, prazo_dias}` | `loja` (troca a linha) | ok |
| Modal Gerar fatura → POST `financeiro/faturas` | `{loja, inicio, fim}` | `fatura.id` ou `fatura: null` (200) | ok |
| Console fatura → GET/PUT `faturas/{id}`, POST `ajustes`, `nao-cobrar`, `emitir`, `cancelar` | `{vencimento}`; `{valor, motivo, observacao_interna}`; `{lancamento_id}` | `{fatura, itens}`: fatura + `email_enviado_em`, `emitida_em`, `entregas`; itens `data`, `tipo`, `valor`, `motivo`, `observacao_interna`, `pedido.{id,numero}`, `detalhes.{km,destino}` | ok (itens agora pelo `ExtratoDaConta::linhas`, ver 2) |
| POST `faturas/{id}/reenviar` | nada | `enviado` | **divergência**: `{enviado:false}` cobria sem e-mail, chave desligada e SMTP fora (a `AvisosDaFatura` engole a exceção), e o console dizia sempre "A loja não tem e-mail" → servidor manda `motivo` (`sem_email`, `desligado`, `falha`) e o console mostra o aviso certo (`chaveDoNaoEnvio`) |
| Console extrato da loja → GET `financeiro/lojas/{id}/extrato` | `inicio`, `fim` | Extrato + Lancamento completo, `fatura.{id,numero}` (rascunho: número null), `pagamento.{forma,comprovante_url}` | ok; `inicio[]=…` dava TypeError → **422** |
| Portal Financeiro → GET `loja/financeiro` | nada | `ciclo_atual.{inicio,fim,entregas,valor}`; Fatura pública | ok (agora pelo `Faturas::resumo`) |
| Portal fatura → GET `loja/faturas/{uuid}` | nada | `fatura` (+`entregas`, `emitida_em`, `loja.nome`), `itens[].{data,tipo,valor,motivo,pedido,detalhes.{km,destino,cancelado_pago,cancelado_origem}}`, `loja.{nome,endereco}`, `empresa.nome` | ok (agora pelo `Faturas::resumo`/`itensFormatados` públicos); `detalhes.faixa` sai com casas (`2.0`), o mesmo número no JSON |
| App Início → GET `v1/entregas/motoboy/extrato?inicio&fim` | período (até 92 dias, mesmo `MAX_DIAS` dos dois lados) | Extrato público: `saldo`, `totais` (positivos; a tela ainda usa `Math.abs`), `ultimo_pagamento.{valor,data,forma}`, `conta.pix.chave`, Lancamento público (`situacao`, `valor_pago`, `pagamento.{id,forma,estornado}`, `pedido.{id,numero}`, `detalhes.{km,loja_nome}`) | ok |
| App Recibo → GET `v1/entregas/motoboy/pagamentos/{uuid}` | nada | `valor`, `forma`, `data`, `estornado`, `comprovante.{url,tipo,nome}` ou null | ok |
| Push `entregas_pagamento` | — | `data.pagamento` (uuid) | ok (`PagamentoRegistrado` manda `{type, pagamento}`) |

## 2. Duplicações B3 × B4 (e B2)

- **Itens da fatura:** `Faturas::itensFormatados` (B3) virou `ExtratoDaConta::linhas($itens, [], $publico)` (B2). O da B3
  repassava chaves desconhecidas dos `detalhes`; o da B4 (`FaturasDaLoja::itensPublicos`) era uma terceira cópia.
- **Fatura do portal:** `PortalLojaController@financeiro|fatura` usam `Faturas::resumo(..., publico: true)`, com
  `Faturas::situacoesDaLoja`; a `AvisosDaFatura` também. `FaturasDaLoja` ficou só com `porUuid`, `daLoja`, `VISIVEIS`,
  `ehRecebimento`, `cicloAtual` e `periodoEmAndamento` (agora com o `CicloDeCobranca` e o `Faturas::configDaLoja` de
  verdade; o falso do teste saiu).
- **Prova:** `financeiro-portal-emails.php`, seção "Console × portal": lista, detalhe e itens de cada fatura iguais nos
  dois lados (menos `email_enviado_em`, `observacao_interna` e `criado_por`), e a lista de Lojas e o Painel somando as
  mesmas faturas (em aberto e vencido).
- O `PainelFinanceiro` continua com o próprio cálculo da situação (B2 decidiu não depender da B3); o teste acima prova
  que dá o mesmo número.

## 3. Pendências que vieram das notas

- `PENDENTE extrato da loja com dados` (`financeiro-lojas.php`) virou teste de verdade (fatura emitida, rascunho, "Não
  cobrar" e recebimento).
- `FinanceiroController::cobranca` usa `CicloDeCobranca::validar` + `normalizar` (as mensagens são as do ciclo da loja).
- `AvisosDaFatura::emitida`: grava `email_enviado_em`, devolve false sem e-mail (já testado na B4). Teste novo ponta a
  ponta: `FinanceiroLojasController@reenviar` com a `AvisosDaFatura` real manda o `FaturaEmitida`, grava o
  `email_enviado_em`, a fatura sai do aviso `email_nao_enviado` do painel; sem e-mail, nada gravado e o aviso fica.
- `emitir`/`reenviar` da B3 chamam a `AvisosDaFatura` (`AVISOS_PADRAO`, testado).
- Ajuda do "Ainda sem fatura" do painel: o número inclui os itens de rascunho (B2, decisão 6), diferente do "Não
  faturado" da lista de lojas; o texto passou a dizer isso.

## Para os CLAUDE.md (corrige os textos prontos da B3 e da B4)

- Seção "Faturas das lojas" (B3): `itensFormatados` = `ExtratoDaConta::linhas` (situação nula); `reenviar` →
  `{enviado: true}` ou `{enviado: false, motivo: sem_email|desligado|falha}` (o 502 só se a classe de avisos lançar);
  `linhasDasLojas.email` = e-mail do Vendor ou, sem ele, o do primeiro usuário ativo do portal.
- Seção "Portal e e-mails" (B4) e `packages/customer-portal/CLAUDE.md`: o portal usa `Faturas::resumo`/`itensFormatados`
  públicos e `Faturas::situacoesDaLoja` (o item 1 das decisões da B4 está resolvido); `FaturasDaLoja` só tem o que é do
  portal. Teste "Console × portal" no `financeiro-portal-emails.php`.
- `packages/fleetops/CLAUDE.md`: `chaveDoNaoEnvio` (aviso do reenviar) em `utils/financeiro-faturas.js`.

## Verificação

- PHP (php-wasm 8.2): os 70 testes de `scripts/teste-php/` (sem `stubs*`/`fixtures*`) com `FALHAS: 0` e nenhum
  `PENDENTE`; `sintaxe.mjs` OK nos PHP alterados.
- `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs`: 187 passam.
- `node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal`:
  exit 0 (com a junção `console/node_modules`, não commitada).
- Parse `@babel/parser` 7.29.8 (decorators-legacy) dos JS alterados: ok.
- App (`C:\tmp\fin-app`): `node --experimental-strip-types --test scripts/testes/*.teste.ts`: 143 passam.

## Pendências

- Passar os textos das notas (B1–B4, F1–F3 e este) para os CLAUDE.md e o `docs/historico.md`; merge do `financeiro-app`
  na `main` do app só depois do deploy da API (trava "Atualize o app").
- No navegador e no celular (sem como testar aqui): upload multipart, link assinado do comprovante atrás do tunnel,
  impressão da fatura, e-mail real na loja de testes, push "Pagamento registrado" e o recibo.
- `criado_por` do extrato sai `{nome: null}` se o usuário sumiu da tabela `users` (o console mostra "Lançado por" vazio);
  raro (os usuários só são apagados com soft delete) e ficou como está.
