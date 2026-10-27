# Fleet-Ops (console)

Parte do `CLAUDE.md` da raiz (índice em "Onde está cada assunto"). Responda e documente em português do Brasil.

O escopo do produto e as extensões fora do console: `CLAUDE.md` da raiz ("Escopo enxuto"). Selo e painel iFood: `api/app/Support/Entregas/Ifood/CLAUDE.md` ("Etapa 4"); painel "Distribuição": `api/app/Support/Entregas/Distribuicao/CLAUDE.md`; painel "Integração" e tela Lojas: `api/app/Support/Entregas/Integracao/CLAUDE.md`.

- **Nome na tela: "Operação"** (decisão do Edgard, 2026-10-26: "condiz mais com o português"). Vale para o menu do topo, o "⋯" (Extensões) e todos os textos pt-BR que diziam "Fleet-Ops" ("do Fleet-Ops" virou "da Operação"). Os identificadores ficam: rota e URL `/fleet-ops`, `console.fleet-ops`, o engine `@fleetbase/fleetops-engine`, as chaves `fleet-ops.*` e o título `'Fleet-Ops'` do `registerHeaderMenuItem` (gera o id `fleet-ops` dos atalhos salvos). O texto da tela sai de `menu-text.fleet-ops*` no `translations/pt-br.yaml`. Texto novo em pt-BR: escreva "Operação", nunca "Fleet-Ops".
  - **Atalhos do cabeçalho:** `intl.t()` no `extension.js` roda na inicialização, ainda em inglês, e o texto fica congelado (era o "Orders"/"Finances" no topo). Por isso os atalhos são traduzidos pelo id em `menu-text.fleet-ops-sc-*`; atalho novo precisa da chave lá.

## Telas, pagamento e cobrança, mapa ao vivo

- **Telas ocultas do Fleet-Ops:** a lista está em `packages/fleetops/addon/utils/entregas-hidden-routes.js`.
  - Inclui manutenção, conectividade/telemática, veículos, frotas, fornecedores, combustível, ocorrências, orquestrador, agenda, tarifas e várias configurações. Desde 2026-10-25, também Análises inteiro (painel de widgets e relatórios), Lugares, Contatos, o hub de Recursos (`management.index`; o grupo Recursos abre em Motoristas pelo `defaultRoute`) e a aba Agenda do motorista.
  - A lista tira os itens do menu (`fleet-ops-sidebar`) e dos hubs (`localize-hub`, `settings/index`), e a URL direta cai em Pedidos (`routes/application.js`). Locais e clientes continuam abrindo por modal e painel (`placeActions`, `customerActions`) no pedido e no mapa.
  - Os atalhos do cabeçalho ficam no `extension.js`. Esse arquivo é copiado para o console e não pode importar utils do engine. Sem o atalho Lugares e sem Admin → Fleet-Ops → Roteamento (ficam Mapa e Navigator App), desde 2026-10-25.
  - **Widgets:** só os do escopo continuam registrados (kpi-distance, kpi-active-orders, kpi-drivers-online, operations-pulse, live-fleet, orders-by-status, on-time-delivery, top-drivers). Legado, receita, ticket médio, ocorrências, combustível, manutenção e geocercas saíram do `registerWidgets`. Hoje nenhuma tela mostra widgets do Fleet-Ops.
- **Itens ocultos nas telas que ficam** (2026-10-25, comentários "Entregas:" no código; para voltar, desfaça os commits da limpeza):
  - Lista de pedidos: sem Importar (botão e estado vazio), coluna/filtro Tipo e as colunas Veículo e Facilitador.
  - Pedido novo: sem Importar, "Criar novo facilitador", campo Facilitador, Atribuir veículo, comprovante (POD), Otimizar rota e os painéis Itens/Entidades, Serviço/Tarifa, Documentos, Restrições do orquestrador e Metadados (o formulário mostra só Notas). A importação passaria por fora da regra "só cria com loja e ID interno", que vive no front.
  - Detalhe do pedido: sem Veículo atribuído, Facilitador, Comprovante de entrega, Otimizar rota (Editar rota fica), Itens/Entidades, Documentos, Comentários, o botão Editar dos Metadados e "Ouvir canal do socket".
  - Motoristas: sem as colunas Veículo e Fornecedor, Atribuir/Desatribuir veículo, Criar ocorrência, aba Agenda, campos Fornecedor e Veículo e "Restrições do orquestrador" no formulário.
  - Contatos: sem "Converter em fornecedor" (criaria um Vendor que o pedido novo trataria como loja).
- **Pedido novo da central** (Pedidos → Novo; decisão de 2026-10-13, 9 pedidos de teste saíram sem loja e ficaram fora da cobrança): abre com **ad-hoc e despacho ligados e sem comprovante** (`pod_required: false`; `OrderCreationService::newOrder`, raio = `fleetops.adhoc_distance` da empresa ou 5000 m) e **só cria com uma loja como cliente e com o ID interno** (`OrderValidationService::validate`; o botão Criar fica desativado e o formulário mostra o que falta). Regras em `utils/entregas-pedido-novo.js` (teste `scripts/teste-portal/entregas-pedido-novo.test.mjs`). Vale só na criação; a edição de pedido antigo sem loja continua livre. Com o despacho ligado, o campo Agendar fica desativado (regra do Fleet-Ops): para agendar, desligue o despacho.
- **Cronômetro do prazo de entrega** (decisão de 2026-10-20; servidor em "Prazo de entrega (cronômetro)" de `api/CLAUDE.md`): `Badge` com relógio (`success` verde com mais de 15 min, `warning` amarelo com 15 min ou menos, `error` vermelho "Atrasado há N min"), que some no pedido encerrado e no sem prazo.
  - Onde: coluna "Prazo" da tabela de pedidos (`cell/prazo-do-pedido`, sem ordenação: o prazo fica no `meta`) e ao lado do status no cabeçalho do detalhe (`order/panel-header`). Componente `PrazoDoPedido`.
  - A lista recebe o prazo pelo `IncluirNotasNaListaDePedidos` (o recurso enxuto não traz o `meta`): sem a API nova, a coluna fica vazia e só o detalhe mostra.
  - Função pura `utils/entregas-cronometro.js` (`cronometro`, `cronometroDoPedido`, `TIPO_DO_BADGE`), copiada no portal (`customer-portal/addon/utils/entregas-cronometro.js`: mudou aqui, mude lá); relógio no serviço `entregas-relogio` (30 s e na volta para a aba; com a aba oculta o intervalo para e volta sem duplicar). O getter `cronometro` do componente é `@cached` (a conta roda uma vez por tique). Traduções `fleet-ops.ui.cronometro.*`. Teste: `scripts/teste-portal/cronometro.test.mjs` (os mesmos casos nas duas cópias).
- **Financeiro** (decisão de 2026-10-12; grupo próprio no menu do Fleet-Ops, depois de Recursos, **só admin**). Servidor (regras, tabelas, rotas, rotinas, implantação): `api/app/Support/Entregas/Financeiro/CLAUDE.md`; desenho: `docs/superpowers/specs/2026-10-12-financeiro-design.md`; contrato: `docs/superpowers/plans/2026-10-12-financeiro.md` (seção 1).
  - **Rotas** `financeiro.*` em `addon/routes.js`: `painel`, `motoboys`, `motoboy` (`/motoboys/:id`, public_id do Driver), `saques` (Saques Pix), `lojas`, `loja` (`/lojas/:id`, public_id do Vendor), `fatura` (`/faturas/:id`, uuid), `relatorio`, `multiplicadores`, `configuracoes` e `corridas-do-sistema` (guarda própria, ver abaixo). `financeiro.index` abre o Painel. A guarda de admin fica na rota pai (`routes/financeiro.js`), como a API (`negarSeNaoAdmin`); `corridas-do-sistema` tem também a própria. Exceção: o admin da organização (papel Administrator) entra em `saques` e `configuracoes` (`rotaDoAdminDaCentral`); `financeiro.index` o manda para `saques`.
  - **Menu:** `createBranch` `financeiro` no `components/layout/fleet-ops-sidebar.js` (ícone `sack-dollar`, `requiresVisibleChildren`), filhos com `visible: currentUser.isAdmin === true`: Painel, Motoboys, Saques Pix (`money-bill-transfer`, também para o admin da organização), Lojas e faturas, Tabela de km e relatório, Multiplicadores, Configurações e, por último, Corridas do sistema (`visible` próprio). O atalho do cabeçalho `fleet-ops-sc-driver-payouts` (`extension.js`) manteve o id e abre o Painel. Configurações também aparece para o admin da organização (só com o card do saque).
  - **A antiga "Pagamento e cobrança" (Recursos) virou Financeiro → Tabela de km e relatório** (`controllers|templates/financeiro/relatorio`, movidos com `git mv`, mesmo comportamento). `management.driver-payouts` ficou só como redirecionamento para `financeiro.relatorio` (`routes/management/driver-payouts.js`, links e favoritos antigos). As chaves `fleet-ops.ui.driver-payouts.*` continuam: o util `pedido-de-origem.js` depende delas.
  - **Painel** (`financeiro.painel`): a pagar, adiantado, a receber (com o vencido), ainda sem fatura, margem do período e a lista "Atenção" (fatura vencida, rascunho, e-mail não enviado, sem loja, sem valor) com link para a fatura ou o pedido. Com "sem valor", a ajuda cita a conferência de 30 dias e o `entregas:financeiro-lancar-historico`.
  - **Motoboys** (`financeiro.motoboys`): saldo (verde a pagar, vermelho adiantado), último pagamento, corridas no período, aviso de Pix ausente, selo "Excluído" (`estaExcluido`, campo `excluido` do servidor); marcar vários → Lançar para os selecionados; Pagar por linha; clique abre o extrato.
  - **Extrato do motoboy** (`financeiro.motoboy`): Pix com copiar e editar inline, Registrar pagamento, Lançar diária / bônus / ajuste / saldo inicial, filtros de período e tipo, saldo anterior e totais, lançamentos por dia com a situação (pago, pago em parte, a receber, anulado), 🔒 observação interna no `title`, "lançado por", Estornar, comprovante (`pagamento.comprovante_url`, assinada, em nova aba) e CSV.
  - **Lojas e faturas** (`financeiro.lojas`, abas sem rota com `TabNavigation`, aba em `?aba=faturas|lojas|ciclo`): Faturas (filtro de situação e loja **no cliente**; rascunho → "Conferir e emitir" e "Apagar"; emitida em aberto → "Registrar recebimento"; vencida em vermelho; "Gerar fatura"), Lojas (saldo, em aberto, vencido, não faturado, ciclo em texto, aviso "sem e-mail", "Editar ciclo" inline) e Ciclo em andamento ("Gerar fatura agora").
  - **Fatura** (`financeiro.fatura`): rascunho → Não cobrar por corrida, + Ajuste, vencimento editável, Emitir, Apagar (decisão de 2026-10-23: confirmação, `DELETE financeiro/faturas/{id}`, volta para a lista; as entregas voltam para a próxima fatura e os ajustes do rascunho somem; regras em `api/app/Support/Entregas/Financeiro/CLAUDE.md`, "Apagar"); emitida → Registrar recebimento, Reenviar e-mail (aviso pelo `motivo` do servidor: `chaveDoNaoEnvio`), Imprimir, Cancelar fatura (os lançamentos voltam para o próximo rascunho); CSV. **Imprimir:** o controller copia o bloco `[data-fatura-impressao]` para o `<body>`, e o CSS de impressão no fim do `addon/styles/fleetops-engine.css` (`.entregas-imprimindo`, `.entregas-impressao-copia`, `.entregas-nao-imprimir`, `.entregas-so-imprimir`) esconde o resto do console; a cópia sai no `afterprint`.
  - **Extrato da loja** (`financeiro.loja`): como o do motoboy, sem Pix, com a coluna Fatura (positivo sem fatura = "Não faturado"), Registrar recebimento e Lançar ajuste / saldo inicial.
  - **Configurações** (`financeiro.configuracoes`): data de corte, aviso quando `ENTREGAS_FINANCEIRO` está desligado no servidor e ciclo padrão das lojas.
  - **Multiplicadores** (`financeiro.multiplicadores`, decisão de 2026-10-21; item do menu entre Tabela de km e Configurações, ícone `cloud-rain`): período em andamento em destaque (mudar o fim, encerrar agora), agendados (editar, apagar), encerrados recolhidos (`ContentPanel` fechado); "Novo período" e "Começar agora" (início agora, fim sugerido +3 h); modal `periodo-multiplicador` (`keepOpen`; em andamento: só o fim; o erro de sobreposição do servidor diz com qual período bateu). API `financeiro/multiplicadores` com `PUT` para editar (regras: "Entrega junto e multiplicador" em `api/app/Support/Entregas/Financeiro/CLAUDE.md`). Funções puras em `addon/utils/financeiro-multiplicadores.js` (teste `scripts/teste-portal/financeiro-multiplicadores.test.mjs`). Datas: `datetime-local` em Brasília; para preencher a partir do servidor, `campoDoInstante` (a hora escrita no ISO).
  - **Corridas do sistema** (`financeiro.corridas-do-sistema`, decisão de 2026-10-22; último item do menu, ícone `building`): relatório do dono do sistema com as corridas finalizadas de **todas as organizações**, por central, com o preço por corrida. **Só o administrador do sistema** (`currentUser.isAdmin`), com `visible` e guarda de rota próprios, independentes do resto do Financeiro (que vai passar a ser o administrador da organização). Período (padrão este mês, atalhos este mês e mês passado), resumo com aviso "N corridas sem preço", por central (preço único ou "variado", selo "sem preço", total no rodapé), preços por central (modal `preco-da-central`, `keepOpen`; apagar com confirmação), lista das corridas (data e hora pela hora escrita no ISO, sem converter o fuso; aviso quando passa de 20.000 linhas, `lista_cortada`) e CSV (`;`, BOM `﻿`, vírgula). Funções puras em `addon/utils/corridas-do-sistema.js` (teste `scripts/teste-portal/corridas-do-sistema.test.mjs`); traduções `fleet-ops.ui.financeiro.corridas-do-sistema.*`. Servidor: `api/app/Support/Entregas/Sistema/CLAUDE.md`.
  - **Saque Pix pelo app** (decisão de 2026-10-26; servidor em `api/app/Support/Entregas/Saques/CLAUDE.md`; planos `docs/superpowers/plans/2026-10-26-saque-pix-asaas-api.md` e `…-console.md`). Quem configura: o admin do sistema **e o da organização** (`ehAdminDaCentral`: `currentUser.isAdmin` ou `roleName === 'Administrator'`, o nome gravado do papel, como o `AdminDaCentral` da API).
    - **Configurações → card "Saque Pix (Asaas)"** (`components/financeiro/saque-pix.{js,hbs}` + `saque-pix/{conta,validacao,ligar}`, reexports em `app/components/financeiro/`): passo 1 conecta com a chave de API e a senha (troca só pela chave da mesma conta; desconectar pede a senha); passo 2 mostra a URL, o token (só uma vez, só na memória da tela), o passo a passo do Asaas e a lista de IPs com o `ip_de_saida` (env `ENTREGAS_IP_DE_SAIDA`), e o "Testar validação" relê `GET financeiro/saque-pix` a cada 3 s por até 60 s; passo 3 tem o interruptor (travado até `validacao_confirmada_em`) e os limites (`validarLimites`, as regras do servidor). O admin da organização vê só o card (o controller nem chama `financeiro/configuracoes`).
    - **Senha no passo 2:** gerar o token (`POST …/saque-pix/token {senha}`) e testar (`POST …/saque-pix/teste {tipo, chave, senha}`) pedem a senha do admin, cada um com o seu campo (`senhaDoToken`, `senhaDoTeste`: `type="password"`, `autocomplete="off"`, botão apagado sem senha). A senha sai do campo assim que o pedido parte, deu certo ou não. "Senha incorreta." (422) e o 429 do token (10/min) aparecem como o servidor manda (`notifications.serverError`). "Gerar outro token" confere a senha antes de abrir a confirmação.
    - **Saques Pix** (`financeiro.saques`, `?situacao=`): saques dos últimos 30 dias (situação em `Badge`, motivo da falha, "Ver no Asaas") e os motoboys com chave Pix com o interruptor de bloqueio (`PUT financeiro/motoboys/{id}/saque`). O nome abre o extrato só para o admin do sistema. O `motivo` do console já é o texto do Asaas quando a falha veio dele; com `motivo_asaas` preenchido, a linha ganha o selo pequeno "Asaas" antes do motivo (`linhaDoSaque` → `doAsaas`).
    - **Extrato do motoboy:** bloco `saque` do extrato do console (`SaqueNoConsole`, só com o saque ligado e a conta conectada): interruptor "Saque liberado / bloqueado" junto da chave Pix e a carência ("Saque liberado às 14:30 de 27/10 · chave trocada por Fulano"). Pagamento de saque (`pagamento.saque`): selo "Saque Pix · app · <situação>", link do comprovante do Asaas e **sem Estornar** (`podeEstornar`).
    - **Motoboys:** selo "saque bloqueado" (`saque_bloqueado`). **Painel → Atenção:** `saque_falhou` e `saque_parado`, que abrem Saques Pix filtrada (`itemDeAtencao` devolve `modelo: null` e `consulta`; o template tem o ramo com `@query`, porque o `LinkTo` com `@model` vazio quebra a tela).
    - **Hora:** tudo pela hora escrita no ISO (`quandoDoIso` em `utils/financeiro.js`). **Links do Asaas:** só `https://` (`linkSeguro`).
    - **Funções puras:** `addon/utils/financeiro-saque-pix.js` (teste `scripts/teste-portal/financeiro-saque-pix.test.mjs`). **Textos:** `fleet-ops.ui.financeiro.saque-pix.*`, `fleet-ops.ui.financeiro.menu.saques`, `fleet-ops.ui.financeiro.painel.atencao.saque_*` e `console.ui.celular.mais.saques-pix`. Chaves montadas no JS (`situacoes.*`, `erros.*`, `motoboys.salvo-*`, `carencia*`, os avisos do JS do card): confira à mão nas duas línguas.
  - **Selos de entrega junto e multiplicador** ("2ª entrega", "Chuva ×1,2"; `selosDaCorrida`/`selosDoRelatorio`, chaves `fleet-ops.ui.financeiro.selos.*`) no extrato do motoboy, no da loja e nos itens da fatura, **só nas linhas `corrida`**: o estorno copia os `detalhes` da corrida original e não pode ganhar o selo. Cada conta só traz o próprio fator (o servidor filtra).
  - **Modais** (`addon/components/modals/`, com re-export em `app/components/modals/`): `registrar-pagamento` (motoboy ou loja; opções `saldo` = saldo real da conta, que o modal busca no extrato se não vier, e `sugestao` = valor sugerido, ex.: o em aberto da fatura; o aviso de adiantamento/excedente compara com o saldo real; na loja, "O recebimento quita primeiro a fatura mais antiga em aberto da loja."), `lancar-no-financeiro` (uma ou várias contas), `estornar-no-financeiro` (motivo; pagamento e recebimento estornam pelo uuid do pagamento), `gerar-fatura` e `ajuste-na-fatura` (`keepOpen`: só fecham quando gravam). Devolvem o resultado em `options` para o `onFinish(options)` (`registrado`, `lancado`, `estornado`): quem abriu só relê a tela quando o campo vem preenchido.
  - **Armadilha do `modals-manager`:** se o `confirm` devolve promessa (inclusive uma TaskInstance do ember-concurrency, que é "thenable"), o modal fecha quando ela termina, mesmo com erro. Os modais do Financeiro usam `confirm` sem `return` e chamam o `done` (2º argumento) só depois de gravar; em erro, `stopLoading` e o modal fica aberto com o que foi digitado.
  - **Upload multipart:** o `fetch.upload` do ember-core manda `Content-Type: application/json` (quebra o boundary). O `registrar-pagamento` usa `fetch.request(path, 'POST', { body: formData, headers })` com os cabeçalhos do `fetch.getHeaders()` sem o `Content-Type`.
  - **Valor digitado:** uma regra só (`numeroDigitado` de `utils/financeiro.js`; o `lerValor` da fatura chama a mesma): "1.500" = mil e quinhentos, "1.234,56", "30,5" e "12.5" valem; mais de 2 casas vira erro. Ao servidor vai sempre `valorParaEnviar` ("1500.00", "-2.50"), e o campo mostra o valor interpretado ("= R$ 1.500,00").
  - **Datas:** `'Y-m-d'` do servidor vira `Date` local por `dataDoDia` (nunca `new Date('Y-m-d')`, que é UTC e cai no dia anterior em Brasília). Período padrão = este mês; o atalho em uso é recalculado ao voltar à tela (`periodoAoAbrir`), e datas digitadas à mão ficam.
  - **CSV:** `celulaCsv` com a regra de fórmula do portal (`INICIO_DE_FORMULA`, cópia: não dá para importar de outro engine; **se a regra mudar no customer-portal, mude aqui também**). Formatadores `Intl` em cache por idioma (`formatadores(idioma)`).
  - **Funções puras** (sem import do Ember): `addon/utils/financeiro.js` e `addon/utils/financeiro-faturas.js`; testes `scripts/teste-portal/financeiro.test.mjs` e `financeiro-faturas.test.mjs` (`node --import ./scripts/teste-portal/resolver.mjs --test ...`).
  - **Traduções:** `fleet-ops.ui.financeiro.*` (logo depois de `distribuicao:`) e `fleet-ops.ui.financeiro-lojas.*` (logo antes de `pedido-sem-motoboy:`). Várias chaves são montadas em tempo de execução (tipos, situação, erros, formas, tipos de Pix, ciclos, dias da semana, variantes `-motoboy`/`-loja`): o `i18n-check` não as vê; ao mexer, confira as duas línguas. **Texto com ": " no meio precisa de aspas** no YAML (ex.: `modal-so-fim: 'Em andamento: só dá para mudar o fim. …'`); sem elas, o YAML lê como outro mapa e o arquivo de tradução quebra.
- **Tabela de km e relatório** (Financeiro → Tabela de km e relatório, `financeiro.relatorio`; antes "Pagamento e cobrança" em Recursos), só para admin. É o relatório por período, para consultar o passado; o que foi pago e recebido está no Financeiro.
  - API: `api/app/Http/Controllers/Entregas/PagamentoMotoboysController.php`, `GET int/v1/entregas/pagamento-motoboys` e `PUT .../faixas`.
  - **km = rota de rua loja (pickup) → cliente (dropoff), só ida**, pelo OSRM (`OSRM_HOST`). O valor fica em cache no `meta.entregas.km_rota` do pedido.
  - Se o OSRM falha, usa uma estimativa (linha reta × 1,3), recalculada na próxima consulta.
  - **Não use `orders.distance`:** é a distância *restante* e vai a ~0 quando o pedido conclui.
  - O período usa a data do tracking status `COMPLETED`, no fuso da organização.
  - **Valores por faixa de km** (`Setting` `company.<uuid>.entregas.faixas` = `[{ate_km, motoboy, loja}]`): uma tabela só, com o valor pago ao motoboy e o cobrado da loja. A entrega vale o valor da faixa em que o km cai (0 < km ≤ 1 → 1ª, 1 < km ≤ 2 → 2ª…); acima da última vale a última. `PUT .../faixas` substitui a tabela.
  - **Segunda tabela ("A partir da 2ª entrega junto")**, decisão de 2026-10-21: mesmo editor, `PUT .../faixas-juntas`; vazia = desligada (botão "Desligar"). Selos e colunas "Entrega junto" e "Multiplicador" no relatório e no CSV. Regras no servidor: "Entrega junto e multiplicador" em `api/app/Support/Entregas/Financeiro/CLAUDE.md`.
    - **Armadilha do `perform`:** o helper `perform` (e o `fn`) acrescenta o **evento do clique como último argumento**. O `salvarFaixas(qual, apagar)` só desliga com `apagar === true` (literal) e só na segunda tabela; com um teste "truthy", o clique em "Salvar faixas" passaria o evento como `apagar` e gravaria a tabela vazia.
  - **Valor congelado por entrega** (tabela `entregas_valores_pedido`, migration em `api/database/migrations`, que o `deploy.sh` roda no banco principal e no sandbox; fica fora do `meta` de propósito, porque o `meta` sai na API v1 e no socket): na primeira vez que o pedido tem km e há faixas, o `CalculoEntregas` grava a faixa e os dois valores (`ValoresCongelados`). Relatório, extrato da loja e app do motoboy leem o congelado.
    - **Mudar a tabela de faixas só vale para as entregas calculadas depois.** Normalmente o valor congela quando o pedido aparece no card de aceitar do motoboy.
    - Km diferente (endereço alterado, estimativa trocada pela rota do OSRM) recalcula com a tabela vigente, **menos no pedido com corrida lançada no Financeiro**.
    - **Depois de lançada no Financeiro, a corrida não muda de valor:** o `valorCongelado` não recalcula o pedido com lançamento `corrida`, e correção só por ajuste (ver `api/app/Support/Entregas/Financeiro/CLAUDE.md`).
    - Valor congelado errado: apague as linhas do período nessa tabela; elas voltam com a tabela atual na próxima consulta. Para pedido já lançado no Financeiro, isso não muda o lançamento: corrija com ajuste.
- **Cobrança das lojas** (na Tabela de km e relatório; as faturas ficam no Financeiro → Lojas e faturas): modelo A = **uma organização só** (o operador de entregas) e cada restaurante é uma **Loja** (ver "Portal da loja" em `packages/customer-portal/CLAUDE.md`).
  - **Loja do pedido = o Vendor dono do pedido** (`orders.customer_uuid`), como nos pedidos do portal e nos que a central cria escolhendo a loja.
  - **Os pedidos do iFood entram com a loja como cliente e o Local dela como coleta** (ver "Integração iFood" em `api/app/Support/Entregas/Ifood/CLAUDE.md`). Assim o pedido entra no portal da loja, no extrato e na cobrança dela.
  - Quem criar pedido pela API v1 (`POST v1/orders`) manda `customer` = `public_id` do Fornecedor da loja (`vendor_…`) e `pickup` = `public_id` do Local da loja (`place_…`). Os dois ids aparecem na tela Lojas. Era o caminho previsto para o iFood em 2026-10-03, substituído em 2026-10-05 pela integração dentro da `api/app`.
  - Pedido sem loja como cliente fica fora do portal, do extrato e das faturas (no Financeiro, só a corrida do motoboy, e o painel o lista em "sem loja"). No relatório, ele agrupa pelo **nome** do local de coleta (a integração pode criar um Place por pedido); sem nome, pelo próprio Place.
  - Cobrança = valor "loja" da faixa do km, igual para todas as lojas. A tela mostra a pagar, a cobrar e a margem; o CSV traz motoboys, lojas e o detalhe com loja, faixa e os dois valores.
- **Mapa ao vivo** (Fleet-Ops → mapa, `components/map/leaflet-live-map.*`). Desde a tela de pedidos nova (2026-10-25), nenhuma tela usa mais os `components/map/*`: a de pedidos usa `tela-pedidos/mapa/leaflet`. O texto abaixo vale para quem reaproveitar o mapa antigo:
  - **Locais:** só os locais de coleta, isto é, o Local de cada loja. O mapa lê `GET int/v1/entregas/mapa/locais-de-coleta` (`Entregas/MapaController.php`) no lugar do `fleet-ops/live/places`, que trazia um prédio por endereço de entrega.
    - Ícone = **pin laranja com a lojinha** (`packages/fleetops/assets/images/loja-pin.png`, 102×120 exibido em 34×40, ponta embaixo no meio; util `entregas-icone-da-loja.js`), no lugar do predinho preto (avatar padrão `basic-building` do Fleetbase). Vale no mapa ao vivo (Leaflet e Google), no mapa do detalhe do local e no "ver no mapa". Local com avatar próprio continua com ele. Teste: `scripts/teste-portal/entregas-icone-da-loja.test.mjs`.
  - **Motoboy = capacete na cor da situação** (`packages/fleetops/assets/images/capacete-*.png`, util `entregas-capacete.js`): verde = livre; amarelo = pedido aceito ou atribuído, ainda na coleta (`started`, `dispatched`…); vermelho = a caminho do cliente (`enroute`); cinza = offline sem pedido. A regra fica em `App\Support\Entregas\SituacaoDoMotoboy` e é lida em `GET int/v1/entregas/mapa/motoboys`. Pedido parado há mais de 12 h não conta.
  - **A cor muda junto com o status.** O mapa escuta o canal `company.<uuid>` do socket e relê a situação ~0,6 s depois de cada evento `order.*`, `waypoint.*`, `entity.*`, `driver.(created|updated|deleted)` ou `entregas.motoboy_online`. A posição (`driver.location_changed`) não conta. A releitura a cada 20 s fica só como reserva. Com a aba oculta o mapa não lê; ao voltar para a aba, lê na hora.
    - Do `localhost` o socket de produção recusa a conexão, porque o `SOCKET_ALLOWED_ORIGINS` só aceita o console. Para testar a reação local, injete o evento no canal: `socket.instance()._channelDataDemux.write('company.<uuid>', {event: 'order.updated'})`.
  - O liga/desliga do online no app (`toggleOnline` do Fleet-Ops) grava com `updateQuietly` e não gera evento. O middleware `AvisarOnlineDoMotoboy` (grupo `fleetbase.api`) transmite o `entregas.motoboy_online` (`App\Events\Entregas\OnlineDoMotoboyMudou`, na hora, sem fila). **Ao atualizar o fleetops-api, confira se a ação `Api\v1\DriverController@toggleOnline` ainda existe.**
  - A lista **Operações ao vivo** da barra lateral (`fleet-ops-sidebar/operations-monitor`) lê o `online` do motorista no store, não a situação do mapa. Ela escuta o `entregas.motoboy_online` no canal da empresa (util `escutar-canal-da-empresa.js`: consumidor próprio, em qualquer tela do Fleet-Ops; refaz a inscrição se outra tela fechar ou desinscrever o canal) e grava o `online` com `store.push` (util `entregas-online-do-motoboy.js`, sem deixar o registro "alterado"). Reserva: a releitura do mapa (`entregas/mapa/motoboys`, que traz o `online`) faz o mesmo. Teste: `scripts/teste-portal/online-do-motoboy.test.mjs`.
  - **Pedidos em tempo real** (`services/order-socket-events.js`): cada evento `order.*`, `waypoint.*` ou `entity.*` do canal `company.<uuid>` relê o pedido, recarrega a lista da rota (tabela/quadro) quando o pedido é novo ou mudou de status, e relê o painel de pedidos do mapa (`services/order-list-overlay.js`, ~0,8 s depois). O painel montava as listas uma vez só e o pedido concluído ficava até atualizar a página. As listas do painel não repetem pedido (o `fetch` põe o pedido novo no store e a leitura do store o devolveria de novo) e "sem motoboy" ignora cancelado, concluído e expirado, como o servidor.
  - **Posição pelo socket** (decisão de 2026-10-07; o capacete ia e voltava ao voltar para a aba):
    - o `EventBuffer` do `services/movement-tracker.js` desenha **só a posição mais recente** da fila, e nunca uma anterior à última desenhada (`utils/entregas-posicao-do-socket.js`). Antes, reproduzia a fila inteira, 0,55 s por evento; com a aba oculta o navegador segura os timers (uma vez por minuto depois de ~5 min) e para a animação, e ao voltar o capacete corria pelo trajeto perdido;
    - com a aba oculta a posição vai direto (`setLatLng`, sem animação), e ao voltar para a aba a fila é processada na hora;
    - no servidor, o `track()` (`DriverControllerSemGeocodificacao`) passa antes pelo `App\Support\Entregas\FiltroDePosicaoDoMotoboy`: posição com `timestamp` anterior à última aceita (`antiga`: batimento com posição em cache, fila do plugin) ou com `accuracy` pior que 500 m havendo uma boa aceita há menos de 5 min (`imprecisa`: rede de celular) é descartada. Não grava nada nem vai ao socket, e a resposta é 200 com o motoboy (o plugin reenviaria um erro). Mesmo instante passa (o batimento repetindo a última mantém o motoboy na distribuição). A última aceita fica no cache `entregas:motoboy-ultima-posicao:<id da rota>` por 24 h; o instante guardado nunca passa da hora do servidor (relógio do celular adiantado). Sem `timestamp` (SDK do app) ou sem `accuracy`, a regra correspondente não vale;
    - o APK manda a `accuracy` no `locationTemplate` do plugin desde o merge `77356ce` do `entregas-navigator` (2026-10-07); com o APK anterior, só a regra `antiga` vale;
    - log `[entregas] posição do motoboy descartada (<motivo>)` (`docker service logs entregas_application`), com o id e a precisão, sem coordenadas;
    - testes: `scripts/teste-portal/entregas-posicao-do-socket.test.mjs`, `scripts/teste-php/posicao-do-motoboy.php` e `track-sem-geocodificacao.php`.
  - O nome do motoboy fica num rótulo fixo embaixo do capacete. Os detalhes saem no clique (popup). O capacete não gira com a direção do GPS (`@disableRotation` do `leaflet-tracking-marker`).
  - A consulta dos pedidos em andamento (`SituacaoDoMotoboy::pedidosEmAndamento`) é a mesma do mapa de motoboys do portal da loja.
  - Teste: `scripts/teste-php/mapa.php`.
  - **Pedidos em andamento = alfinete vermelho no endereço de entrega** (decisão de 2026-10-05; desenho: `docs/superpowers/specs/2026-10-05-pedidos-no-mapa-design.md`).
    - A lista vem em `pedidos`, na mesma resposta de `entregas/mapa/motoboys`, e é relida junto com os capacetes.
    - Ela sai do `App\Support\Entregas\PedidosNoMapa::daCentral`: pedidos não encerrados e atualizados nas últimas 12 h, com ou sem motoboy, até 300.
    - O popup mostra número, loja, status, motoboy, "há X min" e endereço, e tem o botão Abrir pedido.
    - Pedido **aceito** (`aceito` = `orders.started`): o número do pedido e o nome do motoboy ficam fixos embaixo do alfinete ("#4821 · Carlos", `rotuloNoAlfinete`, decisão de 2026-10-19), num rótulo (`entregas-nome-no-alfinete`) diferente do do capacete. Desde 2026-10-20 o fundo segue o prazo de entrega (`prazo_em` do `PedidosNoMapa`, `classeDoRotulo`: `entregas-prazo-no_prazo` verde, `-perto` amarelo, `-atrasado` vermelho); **sem prazo, o rótulo fica neutro (cinza), decisão do Edgard**, e não mais o vermelho-escuro de antes. Cores: verde `rgb(21 128 61)`, amarelo `rgb(250 204 21)` com texto escuro, vermelho red-700 `rgb(185 28 28)`, neutro slate-500 `rgb(100 116 139)` com texto branco (o rótulo do capacete é slate-900; não escureça o neutro, senão confunde). Mecanismo: **um tooltip só**, com `@className="entregas-nome-no-alfinete"` fixo (o `className` do Leaflet só vale na criação, e o `TooltipLayer` sai com `unbindTooltip()`, que tira o tooltip ligado ao marcador naquele momento: recriar o tooltip a cada cor dependia da ordem); a classe do estado (`classeDoRotulo` devolve só `entregas-prazo-*`, vazia sem prazo) vai num `<span>` dentro do conteúdo, que é DOM do Glimmer (`in-element` do ember-leaflet) e muda sozinho, e o CSS pinta fundo e seta com `.leaflet-tooltip.entregas-nome-no-alfinete:has(.entregas-prazo-*)`. A cor anda com o `relogio` do mapa (a cada releitura, ~20 s, e a cada evento do socket). O `numero` do `PedidosNoMapa` é o `internal_id` (número do iFood ou do sistema da loja, com #); sem ele, o código de rastreio (sem #). Pedido só atribuído pela central fica sem rótulo. **Cor chapada** (decisão do Edgard, 2026-10-24): fundo e seta sem transparência e `opacity: 1 !important` no tooltip, porque o Leaflet põe `opacity: 0.9` no style. **Motinha** (2026-10-24): pedido a caminho do cliente (`emEntrega`: status em `enroute`, `picked_up`, `dropping_off`, `in_progress`, o mesmo `SituacaoDoMotoboy::STATUS_EM_ENTREGA` do capacete vermelho) ganha `<FaIcon @icon="motorcycle">` na frente do texto, na cor do texto do rótulo; o pino não muda. O app faz o mesmo no Mapa (`emEntrega` em `src/utils/mapa-do-lider.ts`). **Nome da loja na segunda linha** (decisão do Edgard, 2026-10-26, em todos os mapas: console, portal e app): embaixo do "#4821 · Carlos", o nome da loja do pedido (`lojaNoAlfinete`, o `loja` do Ao vivo e do `PedidosNoMapa::daCentral`), numa `<span class="entregas-loja-no-alfinete">` dentro do mesmo span do estado (mesma cor do prazo); com ela o rótulo deixa de ser pílula e vira um cartãozinho de duas linhas (`:has(.entregas-loja-no-alfinete)` no `fleetops-engine.css`). Sem loja, só a primeira linha. Vale na tela de pedidos (`tela-pedidos/mapa/leaflet`) e no mapa ao vivo antigo (`map/leaflet-live-map`); o mapa do painel inicial não tem rótulo nos alfinetes. Vale também para o portal (a função é copiada em `customer-portal/addon/utils/pedidos-no-mapa.js`: mudou aqui, mude lá).
    - Funções puras: `utils/entregas-pedidos-no-mapa.js`.
    - Testes: `scripts/teste-php/pedidos-no-mapa.php` e `scripts/teste-portal/entregas-pedidos-no-mapa.test.mjs`.

## Painel inicial (raiz do console)

A tela que abre depois do login (rota `console.home`), feita em 2026-10-25 a partir do sketch 004, variante A. API e
regras dos números: `api/app/Support/Entregas/Painel/CLAUDE.md`.

- **Componente:** `addon/components/home/painel-inicial.{js,hbs}`, com o mapa em `home/painel-inicial/mapa.{js,hbs}` e os
  reexports em `app/components/home/`.
  - Registrado em `console:home:before-dashboard` pelo `registerHomeComponents` do `extension.js` (a chamada estava
    comentada).
  - O `<Dashboard>` de widgets saiu do `console/app/templates/console/home.hbs`; os widgets do escopo continuam registrados (ver "Widgets" no começo deste arquivo).
  - **Para voltar aos widgets:** reverta o `extension.js` e o `home.hbs`.
- **Roda com o owner do console:** use `@service router` com o nome completo da rota (`console.fleet-ops.…`); o
  `hostRouter` não existe ali. O socket é escutado pelo `utils/escutar-canal-da-empresa.js`; o `order-socket-events` está
  preso à tela de pedidos.
- **Dados:**
  - `entregas/painel-inicial`: ao abrir, ao trocar o período (Hoje / 7 dias / 30 dias) e a cada 5 min;
  - `entregas/painel-inicial/ao-vivo`: a cada 20 s e 0,8 s depois dos eventos `order.*`, `waypoint.*`, `entity.*`,
    `driver.created|updated|deleted` e `entregas.motoboy_online`;
  - `entregas/mapa/locais-de-coleta`: uma vez, para os pins das lojas.
  - Com a aba escondida, nada é consultado. O relógio de 1 s só atualiza o cronômetro e a contagem da oferta.
- **Quem vê o quê:** o admin (`currentUser.isAdmin`) vê o dinheiro (faturado, a pagar, margem e os valores das listas), a
  lista Atenção e o botão Atribuir. Os outros usuários veem corridas, % no prazo, tempo médio e o Ao vivo.
- **Fila:** sem motoboy (o mais antigo antes), atrasados (o mais atrasado antes), por prazo e sem prazo no fim
  (`utils/painel-inicial.js`, `filaDoPainel`).
  - A barra vermelha aparece com algum atrasado ou com 2 ou mais sem motoboy (`ALERTA_SEM_MOTOBOY`).
  - O "Atribuir" lista os motoboys livres e online, do mais perto da loja (coleta) para o mais longe.
- **Estilo:** `console/app/styles/painel-inicial.css` (classes `pi-*`, tokens da marca com versão escura em
  `body[data-theme="dark"]`). Fica no console porque o CSS do engine só carrega com o engine. No celular (< 1024 px), os
  mesmos blocos empilhados.
- **Textos:** `fleet-ops.ui.painel-inicial.*`. As chaves montadas com `${P}.…` no JS não são vistas pelo i18n-check:
  confira à mão nas duas línguas.
- **Testes:** `scripts/teste-portal/painel-inicial.test.mjs` e os quatro `scripts/teste-php/painel-inicial*.php`.

## Tela de pedidos (`/fleet-ops`)

A tela de pedidos do Fleet-Ops (rota `operations.orders.index`), refeita em 2026-10-25 a partir do sketch 005 (variante
A com os lados invertidos, `.planning/sketches/005-tela-de-pedidos/`). As vistas são o query param `layout` (`map`,
`table`, `kanban`), como antes.

- **Barra** (`components/tela-pedidos/barra.hbs`): título, ao vivo, Mapa / Tabela / Quadro (os ids
  `ops-*-view-button` ficaram) e Novo pedido. Substituiu o `MapContainer::Topbar`.
- **Mapa** (`tela-pedidos/mapa.{js,hbs}`, com o Leaflet em `tela-pedidos/mapa/leaflet.*`): mapa em tela cheia.
  - O painel **Motoboys** flutua à esquerda e o **Pedidos** à direita, os dois recolhíveis.
  - As contagens ficam no topo e filtram o mapa e a lista.
  - Clicar num pedido abre o **pedido aberto** (ver abaixo): a folha da comanda no lugar do painel Pedidos.
  - O admin atribui ou troca o motoboy pela folha; o Atribuir da linha abre a folha já com os candidatos.
  - O enquadramento desconta os painéis (`@margens`, `paddingTopLeft`/`paddingBottomRight`), e o zoom fica embaixo à
    esquerda (o painel Motoboys para 110 px antes do fim).
  - No celular (< 768 px, `matchMedia`), uma folha de baixo com Pedidos e Motoboys (3 alturas).
  - **O mapa ao vivo antigo (`<Map>`: barra de ícones, gaveta, painel de pedidos) saiu da tela.** Os componentes
    `map/*` continuam no engine.
- **Menu do Fleet-Ops:** trilho de ícones em todo o Fleet-Ops. Ver "Moldura do console" em `console/CLAUDE.md`.
- **Tabela:** continua o `Layout::Resource::Tabular` do Fleetbase (filtros, ações em massa, importar, exportar), com
  as abas por situação em cima (`tela-pedidos/abas`).
  - As abas viram query params: Em andamento = `status=active` (alias do `OrderFilter`), Sem motoboy = `unassigned=1`
    (novo query param na rota e no controller), Concluídos = `status=completed` e Cancelados = `status=canceled`.
- **Quadro** (`tela-pedidos/quadro.*`): colunas Sem motoboy, Indo à loja, A caminho e Concluídos (os de hoje). Saiu o
  `Order::Kanban`. "Ver todos" abre a Tabela na aba Concluídos.
- **Dados:** os do Ao vivo do painel inicial (`GET entregas/painel-inicial/ao-vivo`, com `corridas_hoje` e
  `concluidos`; ver `api/app/Support/Entregas/Painel/CLAUDE.md`).
  - Lê ao abrir, a cada 20 s, 0,8 s depois dos eventos do socket e ao voltar para a aba
    (`utils/ao-vivo-da-central.js`).
  - Mapa e Quadro herdam de `utils/tela-de-pedidos-base.js`, que tem a linha do pedido, a oferta, o prazo e o Atribuir.
- **Contas puras:** `utils/tela-de-pedidos.js` (ordem dos motoboys: livres com menos corridas hoje antes; colunas;
  abas). Teste: `scripts/teste-portal/tela-de-pedidos.test.mjs`.
- **Estilo:** `console/app/styles/tela-de-pedidos.css` (classes `tp-*`), com os tokens `--pi-*` do
  `painel-inicial.css`, que valem para `.painel-inicial` e `.tela-pedidos`.
  - O `.tp-mapa-tela` tem `isolation: isolate`: os painéis (1001) e o Leaflet (até 1000) ficam presos nele e não
    passam por cima das Mensagens do cabeçalho (caixa e janelas do chat, z-index 800-860). Sobreposição nova do mapa
    que precise ficar acima do chat vai fora do `.tp-mapa-tela` (ou num wormhole).
- **Textos:** `fleet-ops.ui.tela-pedidos.*`. A fila, a oferta e o Atribuir reaproveitam `fleet-ops.ui.painel-inicial.*`.
  As chaves montadas no JS (`contagens.*`, `abas.*`, `motoboy.*`, `filtros.*`, `vazio.*`) não são vistas pelo
  i18n-check.
- **Pedido aberto** (sketch 008, variante A, `.planning/sketches/008-pedido-aberto-no-console/`; 2026-10-25):
  - **Onde abre:** o pedido abre sempre no mapa do `/fleet-ops`, com `?layout=map&pedido=<public_id>&de=tabela|quadro|inicio`
    (query params `pedido` e `de` do controller; a rota tira os dois da consulta da lista).
    - A rota antiga do detalhe do Fleetbase (`operations.orders.index.details`) **redireciona** para lá no
      `beforeModel`. Assim valem todos os lugares que abriam o painel: Tabela, Quadro, Início, avisos (pedido sem
      motoboy, ação recusada pelo iFood) e Financeiro. O `de` sai da transição
      (`origemDaTransicao`); o Quadro e o Início já mandam o `de` direto.
    - **Quem espera a transição (`yield`/`await transitionTo`) não pode ir à rota de detalhes:** o redirecionamento
      troca a transição e a promessa rejeita com `TransitionAborted`. Foi o que aconteceu no salvar do pedido novo
      (2026-10-26: o pedido era criado, mas aparecia "TransitionAborted" no lugar do aviso de sucesso). Por isso o
      `controllers/operations/orders/index/new.js` vai direto a `orders.index` com `?layout=map&pedido=<public_id>`.
    - A pílula "Voltar para a Tabela/o Quadro/o Início" (`voltarDoPedido`) volta à origem. O X fecha e fica no mapa.
      Trocar de vista fecha o pedido.
  - **Folha** (`tela-pedidos/pedido-aberto.*`, 440 px no lugar do painel Pedidos; no celular, dentro da folha de baixo,
    que abre alta): a comanda do app do motoboy.
    - Número, origem, situação, prazo e "para <cliente>" (nome em caixa normal; sem nome, a rua).
    - Motoboy com Trocar, ou a oferta com Atribuir (só admin), ou "Entregue por X às HH:MM" / "Cancelado".
    - Cobrança, endereço com complemento e referência, observação do cliente, produtos em cupom e a loja da coleta.
    - **Sem 0800 nem localizador**: só o motoboy do pedido recebe (regra do servidor).
    - **Os blocos do corpo não encolhem** (`.tp-pa-corpo > * { flex-shrink: 0 }` no `tela-de-pedidos.css`): quem
      rola é o corpo. Sem isso, com a comanda mais alta que a folha, a lista do Trocar/Atribuir (`overflow: hidden`,
      que zera o mínimo do item flex) era espremida a 2 px e parecia não abrir (2026-10-26).
    - **"Mais"**: linha do tempo (`tracking-status-text`), rota e valores (só admin) e o que era do painel do
      Fleetbase: os painéis iFood, Integração e Distribuição (`Order::Details::*`) e as ações (editar rota, editar
      detalhes, atualizar status, tirar o motoboy, etiqueta, metadados, cancelar fora do iFood, excluir). O pedido do
      Ember Data só carrega ao abrir o "Mais".
  - **Dados:** `GET entregas/pedidos/{id}/comanda` (ver "Comanda do pedido" em `api/app/Support/Entregas/Painel/CLAUDE.md`).
    Relida ao abrir, quando o pedido muda no Ao vivo (status, motoboy, aceite), depois de atribuir e a cada minuto (com
    a aba à vista). Pedido encerrado, fora do Ao vivo, também abre (o alfinete vem da comanda).
  - **Rota do motoboy no mapa** (`trechosDaRota` em `utils/pedido-aberto.js`, desenhada pelo `tela-pedidos/mapa/leaflet`
    com contorno branco e o P na coleta): a linha pelas ruas é a do `RotaDoPedido` (OSRM coleta → cliente, a mesma do
    app).
    - A caminho do cliente: a linha é cortada no ponto mais perto do motoboy; o já feito sai em cinza e o que falta em
      laranja.
    - Indo à loja: motoboy → loja em linha reta tracejada (não há OSRM desse trecho) e loja → cliente em laranja.
    - Sem motoboy: a rota prevista em cinza tracejado. Encerrado: a rota da entrega em cinza.
    - Rota aproximada (o OSRM falhou): tracejada. Sem rota do servidor: linha reta.
    - A legenda (`tela-pedidos/legenda-da-rota`) fica embaixo, ao lado do zoom. O enquadramento desconta a folha e
      acontece uma vez por pedido (de novo quando chega a rota das ruas).
  - **Contas puras:** `utils/pedido-aberto.js`. Teste: `scripts/teste-portal/pedido-aberto.test.mjs`.
  - **Textos:** `fleet-ops.ui.tela-pedidos.pedido-aberto.*`. As chaves `voltar.*` e `situacao.*` são montadas no JS.
  - **Para voltar ao painel do Fleetbase:** tire o redirecionamento do `beforeModel` de
    `routes/operations/orders/index/details.js`.
- **Para voltar à tela antiga:** reverta o `templates/operations/orders/index.hbs` (o controller e a rota só ganharam o
  `unassigned` e as ações novas).
