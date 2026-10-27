# Financeiro (servidor)

Parte do `CLAUDE.md` da raiz (índice em "Onde está cada assunto"). Responda e documente em português do Brasil.

Extrato e pagamento dos motoboys (corridas, diária, bônus, ajuste, adiantamento), faturas das lojas (ciclo, rascunho, emissão, recebimento), painel, portal da loja, e-mails e app. Desenho: `docs/superpowers/specs/2026-10-12-financeiro-design.md`; plano e contrato (tabelas, classes, rotas, formatos JSON): `docs/superpowers/plans/2026-10-12-financeiro.md` (seção 1); notas de cada etapa e da revisão: `docs/superpowers/plans/notas/`.

Telas do console: `packages/fleetops/CLAUDE.md` ("Financeiro"). Portal: `packages/customer-portal/CLAUDE.md` ("Financeiro da loja"). App: `api/app/Http/Controllers/Entregas/CLAUDE.md` ("Início = extrato do Financeiro"). E-mails: `api/app/Notifications/Entregas/Email/CLAUDE.md`.

**Registro + saque Pix pelo app:** o pagamento feito pela central continua só registro (o dinheiro sai pelo app do banco). O motoboy pode sacar o saldo pelo app quando a central conecta o Asaas e liga o saque (`api/app/Support/Entregas/Saques/CLAUDE.md`): o saque vira um pagamento Pix do sistema, que não se estorna à mão.

**Loja pré-paga** (2026-10-27): a loja escolhida como pré-paga no cadastro não recebe fatura (rascunho recusado e pulado
pela rotina); recarrega a carteira por Pix no portal, e cada recarga paga vira um recebimento Pix na conta dela. Regras:
`api/app/Support/Entregas/PrePago/CLAUDE.md`.

## Regras

- **Livro próprio, sem Models:** `DB::table` nas tabelas `entregas_*`, serviços aqui, controllers em `api/app/Http/Controllers/Entregas/`. O MySQL devolve decimal como texto (`(float)`) e booleano como 0/1; o servidor sempre devolve `round((float) …, 2)`.
- **Sinal:** crédito > 0 (devo ao motoboy / a loja me deve), débito < 0 (pagamento, recebimento, ajuste negativo). O saldo é a soma; saldo negativo do motoboy = adiantamento. O saldo inicial aceita negativo (o motoboy devia).
- **`chave_unica`** (nullable, unique): `corrida:<conta_tipo>:<order_uuid>` (uma corrida por pedido e conta), `corrida:<conta_tipo>:<order_uuid>:<n>` (corrida relançada, n = 2, 3…) e `estorno:<id>`. O `Lancamentos::inserir` usa `insertOrIgnore` só quando há chave: repetida devolve `null` sem erro. Se o INSERT IGNORE não gravou e a chave **não** existe (engoliu outro erro: dado truncado, outra chave única), lança `\RuntimeException`. Sem chave (diária, bônus, ajuste, pagamento), lança quantas vezes for pedido.
- **Nada se apaga nem se edita:** erro se corrige com **estorno** (`Lancamentos::estornar`: outro lançamento com o valor oposto, `estorno_de` e `chave_unica = estorno:<id>`, data de hoje no fuso da empresa, copia `order_uuid`, `pagamento_uuid` e `detalhes`). Estorno não se estorna; lançamento já estornado → 409. Cada lançamento, pagamento e fatura guarda quem fez (`criado_por`, `emitida_por`…; null = sistema). O único campo que muda depois de gravado é o `fatura_uuid` da conta loja (rascunho liga, cancelamento solta). **Única exceção ao "nada se apaga":** apagar um rascunho de fatura (ver "Apagar" em Faturas), que nunca saiu do console.
- **Quitação por antiguidade** (`Quitacao`, funções puras, em centavos; calculada na hora, nada gravado):
  - motoboy (`creditos`): primeiro o débito com `estorno_de` abate o crédito estornado (fica `anulado`); depois o ajuste negativo com pedido abate **as corridas do pedido que ainda têm valor** na mesma conta (o "Não pagar"; mais de uma quando a corrida foi relançada); o resto (pagamento, ajuste sem pedido, saldo inicial negativo) vai ao "caixa", que quita do crédito mais antigo (`data_referencia`, `id`): `pago`, `parcial` ou `aberto`. Um débito estornado e o estorno positivo dele se anulam (estorno de pagamento devolve o caixa; estorno do "Não pagar" devolve a corrida a receber). O admin informa só o valor pago; não se escolhem corridas a dedo;
  - loja (`faturas`): o recebido (recebimentos menos os estornos deles) quita as **faturas emitidas** por vencimento, depois número: `aberta`, `parcial`, `paga`; **vencida** = em aberto > 0 e vencimento anterior a hoje. O recebido além do total não aparece (é crédito da loja e quita a próxima). Lançamento ainda não faturado entra no saldo da loja, mas não vence.
- **Depois de lançada, a corrida não muda de valor:** o `CalculoEntregas::valorCongelado` não recalcula o pedido com corrida lançada (consulta `entregas_lancamentos` uma vez por lote em `entregas()`; no `valorDoPedido`, só quando o km mudou). Correção só por ajuste. Se a tabela não existir (API nova antes da migration) ou o banco falhar, segue a regra antiga e loga `[entregas] financeiro: não deu para ler as corridas lançadas`.
- **Data de corte** (`Setting` `company.<uuid>.entregas.financeiro_corte`, `'Y-m-d'`, lido com `Setting::lookup` pela chave inteira, porque comando e job não têm sessão): sem ela (ou inválida), nada é lançado automaticamente na empresa. Corrida com `data_referencia` anterior ao corte não entra; pendência antiga entra à mão como lançamento "Saldo inicial".
- **Chave `ENTREGAS_FINANCEIRO`** (`services.entregas.financeiro`, `Financeiro::ligada()`; stack `x-api-env` e `stack.env.example`): `1` liga o observador do pedido, as três rotinas e os e-mails das faturas. Desligada, as telas, os lançamentos manuais, os pagamentos e as faturas feitas à mão funcionam, mas nada é lançado sozinho e nenhum e-mail sai. O `entregas:financeiro-lancar-historico` roda mesmo desligada (uso manual).
- **Datas:** `data_referencia` é o dia no fuso da empresa (`Financeiro::fuso`, só fuso válido, padrão `America/Sao_Paulo`); `created_at`/`updated_at` com `now()->format('Y-m-d H:i:s')` (hora de Brasília). Instantes nas respostas em ISO 8601 no fuso da empresa. Nunca formate em UTC para gravar nem para consultar (ver "Fuso" em `api/CLAUDE.md`).
- **Faixas pela sessão:** o `CalculoEntregas` lê as faixas pela empresa da sessão. O `LancadorDeCorridas` põe a empresa na sessão só enquanto calcula (`naEmpresa()`) e devolve a anterior no `finally`: no worker da fila, a empresa de um job não vaza para o seguinte.
- **Cada entrega do `CalculoEntregas::entregas()`** tem também `order_uuid`, `motoboy_uuid` (o `driver_assigned_uuid`) e `loja_uuid` (Vendor dono ou `null`). O portal e o app escolhem os campos e não as recebem.
- **Detalhes da corrida** (`detalhes`, JSON): `{pedido, numero (internal_id), km, faixa:{de_km, ate_km, acima}, loja_nome, destino, cancelado_pago, cancelado_origem}`, iguais nas duas contas e **sem valor nenhum** (o motoboy nunca vê o valor da loja). O `json_encode` grava `2.0` como `2`: quem lê converte.
- **Público** (app e portal): sem `observacao_interna`, sem `criado_por` e sem `pagamento.comprovante_url`; o `motivo` é visível ao motoboy e à loja. O motoboy nunca vê lançamento de outra conta nem o valor da loja.
- **Só administradores** (`negarSeNaoAdmin` em todos os métodos do console: 403 "Somente administradores podem mexer no financeiro." / "… podem ver e mexer no financeiro." nas lojas). Sem papel "Financeiro" ou só leitura.
  - **Pendência (2026-10-22), antes de vender às centrais:** o `negarSeNaoAdmin` usa `isNotAdmin()` (`type !== 'admin'`), que só o primeiro usuário da instalação passa (o administrador do sistema). Hoje o Financeiro inteiro e o painel da distribuição (`DistribuicaoController`) só abrem para ele. Quando as centrais entrarem como organizações novas, o administrador de cada uma não vai conseguir usá-los na própria organização: trocar pela regra do papel "Administrator" da organização. O mesmo `negarSeNaoAdmin` existe no `IfoodPedidosController` e no `LojasController`: confira na mesma troca. A tela "Corridas do sistema" fica de fora (trava própria: `api/app/Support/Entregas/Sistema/CLAUDE.md`).
- Mesma tabela de faixas de km para todas as lojas e motoboys (ver "Valores por faixa de km" em `packages/fleetops/CLAUDE.md`), mais a segunda tabela da entrega junto e os multiplicadores por período (seção abaixo); o que muda por motoboy entra como lançamento manual. A diária é manual (atalho de lançar para vários motoboys de uma vez).

## Entrega junto e multiplicador

Spec `docs/superpowers/specs/2026-10-21-entrega-junto-e-multiplicador-design.md`; planos `docs/superpowers/plans/2026-10-21-entrega-junto-e-multiplicador-*.md`.

- **Segunda tabela** (`Setting` `company.<uuid>.entregas.faixas_juntas`, `CalculoEntregas::CHAVE_FAIXAS_JUNTAS`, mesmo formato da `entregas.faixas`; vazia = desligada; `PUT .../pagamento-motoboys/faixas-juntas`): vale para o pedido que o motoboy pega enquanto já leva outro (posição ≥ 2), igual da 2ª entrega em diante. Congela com o km (colunas `junto_*` da `entregas_valores_pedido`, no `COLUNAS_ATUALIZADAS`).
- **Multiplicadores** (`entregas_multiplicadores`, `Financeiro\Multiplicadores`, `MultiplicadoresController`, rotas `financeiro/multiplicadores` com `PUT` para editar): período com nome, ×motoboy e ×loja (1,00 a 5,00), início e fim (até 7 dias, sem sobreposição, numa transação com trava). Vale para o pedido **atribuído** em `[início, fim)`. Agendado muda tudo ou é apagado; em andamento só muda o fim ou encerra; encerrado não muda. Quem criou e encerrou: `criado_por`, `encerrado_por`.
- **Atribuição** (`ObservadorDoValorDaEntrega` → `CalculoEntregas::registrarAtribuicao`): quando o `driver_assigned_uuid` muda, grava motoboy, instante, posição (1 + pedidos em aberto dele, `AtribuicaoDoValor::emAberto`, critério do mapa: não encerrados e atualizados nas últimas 12 h) e cópias do período. Fixa depois disso; troca de motoboy recalcula; sem motoboy apaga. Pedido com corrida lançada não muda; pedido sem km não grava (fica para a reserva).
  - O observador é registrado no `AppServiceProvider` **antes** do lançador (`congelarValorNaAtribuicao()` antes de `lancarCorridasNoFinanceiro()`): a atribuição fica gravada antes de o lançamento ir para a fila.
  - Ele relê o pedido e põe a empresa do pedido na sessão (as faixas vêm da sessão); no `finally`, sem empresa antes, **tira** a chave (`session()->forget`), porque `company` = null faz o `Setting::lookupFromCompany` montar `company..`.
  - A gravação é do `ValoresCongelados::gravarAtribuicoes`, que manda só as `COLUNAS_DA_LINHA` (o lote mistura linhas lidas do banco, com id, datas e qualquer coluna futura, e linhas montadas; uma coluna a mais desalinharia o upsert). O recongelamento do km (`gravar`, `COLUNAS_ATUALIZADAS`) não apaga a atribuição.
- **Valor final** = faixa (normal ou segunda tabela) × fator, arredondado a 2 casas, sempre pelo `ValorDaEntrega::final` (a linha guarda as partes, não o final). Segunda tabela vazia no cálculo: a posição fica gravada, mas vale a tabela normal (e não há selo).
- **Prévia** (`valorDoPedido($pedido, $motoboyUuid)`): **só no pedido ainda sem motoboy** (`driver_assigned_uuid` vazio: o card de aceitar e a oferta da distribuição), a posição para quem pergunta (sem ele, a 1ª) e o multiplicador de agora, sem gravar. O pedido com motoboy e sem atribuição gravada fica com o valor **sem prévia** (um pedido antigo, concluído ou lançado não ganha o multiplicador de agora nos detalhes); a reserva corrige na conclusão. O push de alarme (`CartaoDoAlarme`) não sabe o destinatário: mostra a prévia sem posição. O `MotoboyController@valor` passa o uuid do motoboy da sessão.
- **Reserva**: pedido concluído sem a atribuição do motoboy dele (escapou do observador: `saveQuietly`, query builder, pedido de antes do deploy) é reconstituído pelo aceite (`started_at`, senão `dispatched_at`) e pelos horários dos outros pedidos dele (`AtribuicaoDoValor::posicoesPorHorarios`), só quando havia segunda tabela ou período no instante.
- **`junto` é a posição** (2, 3…) quando a segunda tabela foi usada, senão null, em todo lugar: detalhes da corrida (nas duas contas), extrato do app e do portal, card do app (`GanhosDoMotoboy::linha`/`::valor`). `multiplicador` `{nome, fator}` só na conta cujo fator ≠ 1 (`ValorDaEntrega::doMotoboy`/`::daLoja`): o motoboy nunca vê o fator da loja, e vice-versa. O extrato do portal sai do `PortalLojaController::entregaDoExtrato`.
- **Selos só nas linhas de corrida**: o estorno copia os `detalhes` da corrida original, então as telas (console, portal, impressão) só mostram "2ª entrega" e "Chuva ×1,2" quando o lançamento é `corrida`.
- **Testes:** `scripts/teste-php/entrega-junto.php`, `financeiro-multiplicadores.php`, casos em `financeiro-lancador.php`, `financeiro-console.php`, `financeiro-pagamentos.php`, `financeiro-portal-emails.php` (extrato do portal) e `ganhos-motoboy.php` (motoboy da sessão no valor do card).

## Tabelas

Migration `api/database/migrations/2026_10_13_100000_create_entregas_financeiro_tables.php` (colunas no contrato, seção 1.1; o nome tem `_entregas_financeiro_` porque o banco em memória dos testes lê o esquema por glob). Tudo fora do `meta` do pedido e do motorista (sai na API v1 e no socket). O `deploy.sh` roda a migration no banco principal e no sandbox.

| Tabela | O quê |
|---|---|
| `entregas_lancamentos` | crédito ou débito numa conta (`conta_tipo` `motoboy`/`loja`, `conta_uuid` = `drivers.uuid`/`vendors.uuid`); `tipo` `corrida`, `diaria`, `bonus`, `ajuste`, `saldo_inicial`, `pagamento`, `recebimento`, `estorno`; `valor` com sinal; `order_uuid`, `pagamento_uuid`, `fatura_uuid` (só loja), `estorno_de`, `motivo`, `observacao_interna`, `detalhes`, `chave_unica`, `criado_por` |
| `entregas_pagamentos` | pagamento ao motoboy ou recebimento da loja (`valor` positivo, `forma` `pix`/`dinheiro`/`transferencia`/`outro`, `data`, `comprovante_path/nome/tipo`, `observacao_interna`, `estornado_em/por`) |
| `entregas_faturas` | `numero` (sequencial por empresa na emissão, exibido `FAT-0001`; único com `company_uuid`), período, vencimento, `situacao` (`rascunho`, `emitida`, `cancelada`; paga/parcial/vencida são calculadas), `total` (gravado na emissão), quem emitiu/cancelou, `email_enviado_em`, `lembrete_enviado_em`, `aviso_vencida_enviado_em` |
| `entregas_cobranca_lojas` | ciclo por loja (`ciclo`, `dia_fechamento`, `prazo_dias`); loja sem linha usa o padrão |
| `entregas_motoboys_financeiro` | chave Pix do motoboy (`chave_pix`, `tipo_chave`) |

Settings da empresa: `company.<uuid>.entregas.financeiro_corte` e `company.<uuid>.entregas.cobranca_padrao` (`{ciclo, dia_fechamento, prazo_dias}`, padrão `Financeiro::COBRANCA_PADRAO` = semanal, fecha no domingo, vence em 3 dias; aceita array ou JSON e completa com o padrão).

Classes (`App\Support\Entregas\Financeiro`): `Financeiro` (constantes, `ligada`, `corte`, `cobrancaPadrao`, `fuso`, `hoje`, `numeroDaFatura`), `Lancamentos`, `Quitacao`, `LancadorDeCorridas`, `Pagamentos`, `ExtratoDaConta`, `PainelFinanceiro`, `ChavePix`, `Carteira` (a "Minha carteira" do app), `CicloDeCobranca`, `Faturas`, `FaturasDaLoja` (só o que é do portal), `AvisosDaFatura`.

## Lançamento das corridas (`LancadorDeCorridas`)

- **Quais pedidos:** os do relatório (`CalculoEntregas::pedidosConcluidos`: concluído, ou cancelado pago pelo iFood ou pela integração) com data a partir do corte. Cada pedido gera **duas corridas, numa transação** (a do motoboy não fica sem a da loja por falha no meio): `valor_motoboy` na conta do motoboy atribuído na conclusão e `valor_loja` na conta da loja dona do pedido (`orders.customer_uuid` = Vendor). Valores do congelado (`ValoresCongelados`); `data_referencia` = dia da conclusão (ou do cancelamento pago) em Brasília.
- Pedido **sem valor** (sem faixas, OSRM fora e sem estimativa) fica para a próxima conferência (`sem_valor`). Pedido **sem loja** como cliente: só a corrida do motoboy (o painel lista "sem loja"). Pedido concluído **sem motoboy** não é lançado (o `pedidosConcluidos` exige `driver_assigned_uuid`). Até `LIMITE_CALCULOS` (200) rotas por chamada; `lancados` conta lançamentos, não pedidos.
- **Observador:** `Order::updated` → `App\Listeners\Entregas\ObservadorFinanceiroDosPedidos::aoAtualizar` (registrado no `AppServiceProvider::lancarCorridasNoFinanceiro()`): com a chave ligada e `status` mudando para `completed`/`done` ou cancelado, enfileira `App\Jobs\Entregas\LancarCorridaDoPedido::enfileirar($uuid)` (fila `default`, depois do commit, 15 s de atraso, 3 tentativas; o `handle` confere a chave de novo e chama `lancarPedido`). Nunca lança exceção nem bloqueia a conclusão.
- **Conferência** (`entregas:financeiro-conferir`, a cada 5 min, `FinanceiroConferir`): para cada empresa com corte (`FinanceiroConferir::empresasComCorte()`, chaves `company.%.entregas.financeiro_corte` da tabela `settings`), olha **30 dias** para trás respeitando o corte (`DIAS_DA_CONFERENCIA`):
  - `pedidosSemLancamento`: pedidos do relatório sem a corrida do motoboy (`whereNotExists`) → `lancar`;
  - `pedidosSemLancamentoDaLoja`: pedidos **com** a corrida do motoboy, **sem** a da loja e com cliente Vendor (`whereExists` em `vendors`, inclusive excluída) → lança a da loja;
  - `estornarCancelados` e `relancarEstornados` (últimos 30 dias, `DIAS_DO_ESTORNO`).
  - Falha numa empresa loga `[entregas] financeiro: conferência falhou` e segue.
- **Estorno automático** (`estornarCancelados` + `estornosDevidos`): corrida cujo pedido está **hoje cancelado** (`StatusDoPedido::CANCELADOS`) e fora do relatório (o cancelado pago continua) é estornada com o motivo `MOTIVO_CANCELADO` ("Pedido cancelado após a conclusão"), pelo sistema. **Sem debitar duas vezes:** por conta e pedido, o líquido = corridas + ajustes negativos com o pedido + os estornos desses dois; cada corrida é estornada só até esse líquido (`Lancamentos::estornar(..., $parte)`). Depois de um "Não pagar"/"Não cobrar" do valor inteiro, nada; depois de um desconto de R$ 3 numa corrida de R$ 8, R$ 5. O pedido cancelado fica com zero na conta. Pedido apagado ou concluído que perdeu o motoboy não é estornado.
- **Corrida que volta** (`relancarEstornados`): o pedido cuja **última** corrida na conta tem o estorno automático (`MOTIVO_CANCELADO`, `criado_por` nulo) e voltou ao relatório (cancelado pago marcado depois, ou concluído de novo) ganha uma corrida nova com **o valor que o estorno tirou**, a `data_referencia` e os `detalhes` da estornada, `chave_unica` `…:<n>` e o motivo `MOTIVO_RELANCADO` ("Pedido voltou a valer depois do cancelamento"). Estorno feito à mão não volta. Pode repetir (cancela de novo → estorna a nova; volta → `:3`). Só a conferência relança (o job do observador não).
- **Histórico** (`entregas:financeiro-lancar-historico {--company=}`): do corte até agora, em janelas de 7 dias. Uso único na implantação, seguro de repetir, roda com a chave desligada. Os pedidos sem valor ficam para a conferência (30 dias); mais antigos, rode o comando de novo depois de corrigir o km ou a tabela.

## Lançamentos manuais e estornos (console)

- `POST financeiro/lancamentos`: `conta_tipo`, `contas` (1..100 public_ids; repetidas contam uma vez; o mesmo lançamento para todas, numa transação), `tipo` nos `TIPOS_MANUAIS_*` da conta (motoboy: diária, bônus, ajuste, saldo inicial; loja: ajuste, saldo inicial), `valor` (número ou texto: "30", "-2,50", "1.234,56"; ≠ 0 depois de arredondar; negativo só em ajuste e saldo inicial), `data` `Y-m-d`, `pedido` (só em ajuste), `motivo` (até 255, visível), `observacao_interna` (até 500).
  - **Pedido do ajuste:** public_id ou uuid; pelo número (`internal_id`, que se repete entre dias e lojas) só entre os pedidos com corrida lançada numa das contas escolhidas. Gravado em `order_uuid` e nos `detalhes` (`{pedido, numero}`).
  - Lançamento manual na conta da loja fica sem fatura e entra no próximo rascunho (na fatura, só pelo `faturas/{id}/ajustes`).
- **Estornar lançamento** (`POST financeiro/lancamentos/{id}/estornar` `{motivo}`): estorno → 422; pagamento e recebimento → 422 "Estorne o pagamento pelo botão do pagamento."; já estornado → 409. Estorno de lançamento de loja que **está num rascunho entra no mesmo rascunho** (relê o `fatura_uuid` e trava a fatura na transação, para não ser emitida no meio); de fatura emitida ou sem fatura, fica sem fatura (próximo rascunho).
- **Motoboy excluído (soft delete):** a lista inclui o excluído **com saldo ≠ 0** (`excluido: true`); lançar, pagar e abrir o extrato aceitam (`Driver::withTrashed()`), para o acerto final. O push do pagamento não sai para ele. O extrato do console traz `conta.excluido` (o do app não).
- **Chave Pix** (`ChavePix`, `PUT financeiro/motoboys/{id}/pix` `{tipo, chave}`): CPF 11, CNPJ 14 e telefone 10..13 dígitos (aceitam pontuação, **gravados só com os dígitos**), e-mail e aleatória (32..36 hex/hífen) em minúsculas; até 140. Uma linha por motoboy (troca, não duplica). O motoboy vê no app (`conta.pix`), não edita.

## Pagamentos e comprovante (`Pagamentos`)

- `registrar`: linha em `entregas_pagamentos` + lançamento `pagamento`/`recebimento` negativo com o mesmo `pagamento_uuid`, na mesma transação; a `observacao_interna` vai para os dois. Valor acima do saldo vira adiantamento (motoboy) ou crédito da loja. Dois admins pagando ao mesmo tempo = dois pagamentos (corrige-se com estorno).
- `POST financeiro/pagamentos` (multipart): `conta_tipo`, `conta` (public_id), `valor` ("150.50" ou "150,50"), `forma`, `data`, `observacao_interna`, arquivo em `comprovante` **ou** `file` (um só); o campo `fatura` é ignorado (o recebimento quita a fatura mais antiga). Erros de entrada do `registrar` (`\InvalidArgumentException`, pt-BR) → 422. Resposta `{pagamento, saldo}` (sem a observação).
- **Push ao motoboy** (`App\Notifications\Entregas\PagamentoRegistrado`, `ShouldQueue`, canal `avisos`): "Pagamento de R$ 150,00 registrado (Pix)." (forma "outro" sem os parênteses), dados `{type: 'entregas_pagamento', pagamento: <uuid>}`. Falha no envio só vai ao log (`[entregas] financeiro: push do pagamento não saiu`) e não desfaz o pagamento.
- **Estornar pagamento** (`POST financeiro/pagamentos/{uuid}/estornar`): marca `estornado_em`/`estornado_por` e cria o estorno positivo, motivo "Pagamento estornado" / "Recebimento estornado" (o app usa esse texto para reconhecer o estorno de pagamento). Segunda vez → 409.
- **Comprovante:** imagem (jpeg, png, webp, heic, heif, gif) ou PDF até 10 MB, pelo tipo detectado no servidor (`getMimeType`), no **disco `local`** (privado, volume da API) em `entregas/comprovantes/<company>/<uuid>.<ext>`. Se o lançamento falhar, a transação desfaz o pagamento e o arquivo é apagado.
  - Sai só por **URL assinada de 30 min** (`Pagamentos::urlDoComprovante`, `URL::temporarySignedRoute` da rota nomeada `Pagamentos::ROTA_DO_COMPROVANTE` = `entregas.comprovantes.mostrar`): `GET v1/entregas/comprovantes/{uuid}`, grupo próprio com `signed` + `throttle:60,1`, **sem token** (a assinatura é a proteção). `ComprovantesController@mostrar` serve com `Storage::disk('local')->response`, `inline`, `Content-Type` gravado, `X-Content-Type-Options: nosniff`, `Cache-Control: private, max-age=1800`, nome sem barras nem caracteres de controle; 404 sem pagamento, sem comprovante ou arquivo sumido.
  - A URL depende do HTTPS atrás do tunnel (`TRUSTED_PROXIES`, ver `deploy/CLAUDE.md`): gerada e conferida com o mesmo esquema e host.

## Extrato (`ExtratoDaConta`)

- `montar(company, contaTipo, contaUuid, inicio, fim, publico, tipo)`: `saldo` = todos os lançamentos; `saldo_anterior` = antes de `inicio`; `totais` = créditos e débitos do período (os dois **positivos**, brutos: incluem estornos); o filtro `tipo` só muda a lista. Situação de cada crédito pelo `Quitacao::creditos` da **conta inteira**. Lista: mais novo primeiro. `conta`: `{tipo, id, nome}` e, no motoboy, `pix` (também no público). `ultimo_pagamento`: o não estornado mais recente.
- `linhas(...)`: vários lançamentos no formato "Lancamento" com as consultas em lote (pagamentos, nomes, número das faturas, pedidos). Usado também nos itens da fatura (`Faturas::itensFormatados`, situação nula: na loja quem quita é a fatura). `pedido` sai dos `detalhes` ou, sem eles, do `Order`.
- `pagamento(obj)`: o recibo `{id, valor, forma, data, estornado, comprovante: {url, tipo, nome} | null}` (app e resposta do console).
- O console recebe `pagamento.comprovante_url` (assinada) em todo pagamento/recebimento com arquivo; `criado_por` sai `{nome: null}` se o usuário sumiu da tabela `users` (raro, ficou como está).

## Faturas das lojas (`Faturas`, `CicloDeCobranca`)

- **Ciclo** (`entregas_cobranca_lojas`; sem linha = `cobrancaPadrao`; config inválida cai no `COBRANCA_PADRAO`): semanal (fecha no dia da semana `dia_fechamento`, 0 = domingo; período de 7 dias), quinzenal (1–15 e 16–último dia; `dia_fechamento` gravado 0), mensal (fecha no dia 1–28, ou 0 = último dia; começa no dia seguinte ao fechamento do mês anterior). Vencimento = fim + `prazo_dias` (0 a 60). `CicloDeCobranca` é puro, só com dias do calendário: quem chama passa o dia no fuso da empresa. `validar` devolve a mensagem pt-BR (a mesma regra do `validarCiclo` do console; o `FinanceiroController` usa ela também no ciclo padrão).
- **Itens = lançamentos da loja com `fatura_uuid`** (os lançamentos são imutáveis, então a ligação é o retrato).
- **Rascunho** (`gerarRascunho`): lançamentos da loja sem fatura com `data_referencia <= fim`, inclusive os atrasados (corrida que concluiu tarde, fatura cancelada, estorno de item faturado), menos os que têm `pagamento_uuid` (recebimento e estorno dele). Não cria vazio nem repete o fim de período de outra fatura da loja (devolve `null`). O `periodo_inicio` é só informativo. Nasce pela rotina ou à mão no console (`POST financeiro/faturas` `{loja, inicio, fim}` → `{fatura}` ou `{fatura: null}` com **200**, não 422).
  - **Rotina** `entregas:financeiro-rascunhos` (00:30): procura os fechamentos de ciclo dos **7 dias até ontem** (`FinanceiroRascunhos::DIAS_PARA_TRAS`, do mais antigo ao mais novo) e cria o rascunho se a loja ainda **não tem fatura** (rascunho, emitida ou cancelada) com aquele fim (`jaTemFatura`): o agendador fora do ar um dia não perde o ciclo, e o rascunho descartado não volta. Repetir é seguro. Falha numa loja loga `[entregas] financeiro: rascunho da fatura falhou` e segue.
- **Conferência** (só no rascunho; fora dele, `\DomainException` → 422):
  - `ajuste` (`POST faturas/{id}/ajustes` `{valor, motivo, observacao_interna}`): valor ≠ 0 (desconto, taxa, mensalidade; até `AJUSTE_MAXIMO`), **motivo obrigatório** (a loja vê), data = fim do período, já na fatura;
  - `naoCobrar` (`POST faturas/{id}/nao-cobrar` `{lancamento_id}`): ajuste negativo do valor inteiro da corrida, com o `order_uuid`, os `detalhes` e o motivo `MOTIVO_NAO_COBRAR` ("Entrega não cobrada"); uma vez por corrida, só corrida da própria fatura e não estornada;
  - `mudarVencimento` (`PUT faturas/{id}` `{vencimento}`): não antes do fim do período.
- **Emitir** (`POST faturas/{id}/emitir`): número sequencial por empresa (`max(numero)` com `lockForUpdate` na transação), total gravado (soma em centavos), `emitida_em`/`emitida_por`; a fatura aparece no portal e o e-mail sai (`AvisosDaFatura::emitida`; erro no envio só no log `[entregas] financeiro: e-mail da fatura falhou`, a fatura continua emitida; o emitir espera o SMTP). Recusa fatura sem itens e **total ≤ 0** ("Fatura sem valor a cobrar; cancele o rascunho.").
- **Cancelar** (`POST faturas/{id}/cancelar`, rascunho ou emitida): `situacao = cancelada`, os lançamentos voltam a `fatura_uuid = null` e entram no próximo rascunho; o número da emitida não é reaproveitado. Rascunho cancelado = descartado (o mesmo período pode ser gerado de novo à mão). O que já foi recebido fica como crédito da loja e quita a próxima.
- **Apagar** (`DELETE faturas/{id}`, `Faturas::apagar`, decisão de 2026-10-23: rascunho feito errado ou de teste, para gerar de novo): **só rascunho** (emitida → 422 "A fatura já foi emitida: cancele em vez de apagar."; cancelada → 422 "A fatura foi cancelada."; outra empresa → 404). Numa transação: apaga os ajustes da conferência dele (`tipo = ajuste`, com o `fatura_uuid` dele e `created_at` **depois** do da fatura: avulso e "Não cobrar") e os estornos desses ajustes; solta o resto (`fatura_uuid = null`: corridas, ajuste lançado no extrato, estorno de corrida), que entra no próximo rascunho; apaga a linha da fatura. Resposta `{apagada: true}`. Diferente do cancelar, que leva os ajustes para o próximo rascunho e deixa a fatura na lista. O ajuste lançado no extrato no mesmo segundo em que o rascunho nasceu fica (o `>` estrito; a conferência não acontece no mesmo segundo). Sem rastro de quem apagou (aceito: o rascunho nunca foi à loja). Como a fatura some, a rotina `entregas:financeiro-rascunhos` pode recriar o rascunho daquele fim de ciclo se ele estiver nos 7 dias até ontem.
- **Reenviar** (`POST faturas/{id}/reenviar`): `{enviado: true}` ou `{enviado: false, motivo: sem_email|desligado|falha}` (o console mostra o aviso certo); não emitida → 422 "Só a fatura emitida vai por e-mail."; 502 só se a classe de avisos lançar.
- **Situação da emitida** (`situacoesDaLoja`, `situacoesDaEmpresa`): `Quitacao::faturas` com o recebido da loja = −(soma dos lançamentos com `pagamento_uuid`, tipos `recebimento` e `estorno`). Cancelada sai da conta.
- **Formatos** (contrato, 1.5): `resumo`/`resumos` → "Fatura" (rascunho: `numero` null, `total` = soma atual, `pagamento` null; cancelada: sem pagamento, `entregas` 0; público sem `email_enviado_em`); `linhasDasLojas` → "Lojas" (Vendors `type = customer`; `email` = o do Vendor ou, sem ele, o do primeiro usuário ativo do portal).
- **Erros:** entrada inválida → `\InvalidArgumentException`; situação que não permite → `\DomainException`; as duas com a mensagem pt-BR, e o controller devolve 422.

## Portal da loja

`PortalLojaController@financeiro|fatura` (`int/v1/entregas/loja/financeiro` e `loja/faturas/{uuid}`, `throttle:60,1`, passam no `ProtegerPortalLoja` por estarem em `entregas/loja/`; loja pela `LojaDoUsuario`):

- `financeiro`: `ciclo_atual` (lançamentos da loja ainda sem fatura, menos recebimentos: `entregas` = corridas não estornadas, `valor` = soma; `inicio` = o mais antigo entre o início do ciclo da loja e o lançamento sem fatura mais antigo; `fim` = fim do ciclo) e as faturas **emitidas e canceladas**, mais nova primeiro, pelo `Faturas::resumo(..., publico: true)`. Os lançamentos de um rascunho não aparecem nem no ciclo nem nas faturas até a emissão.
- `faturas/{uuid}`: `{fatura, itens, loja: {nome, endereco}, empresa: {nome}}`, itens pelo `Faturas::itensFormatados(..., true)`. **Rascunho, fatura de outra loja ou de outra empresa → 404** `{"errors": ["Fatura não encontrada."]}`.
- `FaturasDaLoja` só tem o que é do portal (`porUuid`, `daLoja`, `VISIVEIS`, `ehRecebimento`, `cicloAtual`, `periodoEmAndamento`). O teste "Console × portal" prova que lista, detalhe e itens são iguais nos dois lados (menos os campos internos).

## E-mails para a loja e avisos (`AvisosDaFatura`)

- **Destinatários:** e-mail do Vendor + e-mails dos contatos `customer` com `vendor_personnels.status = active` (sem apagados), em minúsculas, sem repetir, sem vazio nem inválido, num envio só (`Notification::route('mail', [...])`). Loja de outra empresa: nenhum.
- `emitida`: na emissão e no reenviar (pode repetir); grava `email_enviado_em`. `lembrete` (vence amanhã) e `vencida` (venceu antes de hoje): uma vez (`lembrete_enviado_em`/`aviso_vencida_enviado_em` nulos) e só com valor em aberto. A que vence hoje não recebe nada.
- **Não envia** (devolve `false`, nada gravado): chave desligada (log `[entregas] financeiro: e-mail da fatura não enviado (ENTREGAS_FINANCEIRO desligado)`), fatura que não está `emitida`, loja sem e-mail (log `[entregas] financeiro: fatura sem e-mail para enviar`; o painel mostra "não enviada por e-mail") e falha no envio (warning `[entregas] financeiro: falha ao enviar o e-mail da fatura`). O log nunca traz os e-mails. Relê a fatura do banco antes de enviar.
- Envio na hora (as notificações não são `ShouldQueue`), para o `*_enviado_em` ser o do envio de verdade. Textos, layout e link: `api/app/Notifications/Entregas/Email/CLAUDE.md`.
- **Rotina** `entregas:financeiro-avisos` (09:00): lê a situação das emitidas **uma vez por empresa** (`situacoesDaEmpresa`), pula a paga sem chamar a `AvisosDaFatura` e só olha faturas com vencimento nos **últimos 60 dias** (`FinanceiroAvisos::DIAS_PARA_TRAS`). A vencida há mais de 60 dias sem aviso não recebe mais. Falha numa fatura não para as outras; o que não saiu é tentado no dia seguinte.

## Painel (`PainelFinanceiro::montar`)

Só consulta (nunca lança). Sem período: dia 1 do mês até hoje, no fuso da empresa.

- `a_pagar`/`adiantado`: saldos de agora dos motoboys (positivos / negativos em módulo; o excluído com saldo também soma). `a_receber`/`vencido`: em aberto e vencido das faturas emitidas (cálculo próprio com `Quitacao::faturas`, que dá o mesmo número das Lojas). `nao_faturado`: lançamentos de loja sem fatura **ou num rascunho**, sem os recebimentos.
- `periodo.cobrado`: corrida + ajuste + estorno (sem `pagamento_uuid`) da loja no período; `periodo.pago_motoboys`: corrida + diária + bônus + ajuste + estorno (sem `pagamento_uuid`) dos motoboys (saldo inicial não conta); `margem` = diferença.
- `atencao` (até 20 de cada, nesta ordem): `fatura_vencida`, `rascunho`, `email_nao_enviado` (emitida **em aberto** com `email_enviado_em` null), `sem_loja` (corrida de motoboy dos últimos 30 dias, não estornada, sem a da loja) e `sem_valor` (`pedidosSemLancamento` do corte, até 30 dias para trás, concluídos há mais de 1 h; **só com a chave ligada e corte**, senão todo pedido apareceria; falha só loga `[entregas] financeiro: painel sem a lista de corridas sem valor`).

## Rotas

Console: grupo `int/v1/entregas` (`fleetbase.protected`), todos com `negarSeNaoAdmin`. Ids: motoboy = `drivers.public_id`; loja = `vendors.public_id` (ou uuid); lançamento = `entregas_lancamentos.id`; pagamento e fatura = uuid. Erros em `{"errors": ["…"]}` (422 entrada, 404 conta ou registro de outra empresa, 409 já estornado).

| Método e caminho | Controller@método |
|---|---|
| GET `financeiro/painel?inicio&fim` | `FinanceiroController@painel` |
| GET `financeiro/motoboys?inicio&fim` | `@motoboys` (todos os Drivers da empresa, saldo desc e nome; `excluido`) |
| GET `financeiro/motoboys/{id}/extrato?inicio&fim&tipo` | `@extratoDoMotoboy` (sem período = conta inteira; `tipo` inválido → 422) |
| PUT `financeiro/motoboys/{id}/pix` | `@salvarPix` |
| POST `financeiro/lancamentos` | `@lancar` |
| POST `financeiro/lancamentos/{id}/estornar` | `@estornarLancamento` |
| POST `financeiro/pagamentos` (multipart) | `@registrarPagamento` |
| POST `financeiro/pagamentos/{uuid}/estornar` | `@estornarPagamento` |
| GET / PUT `financeiro/configuracoes` | `@configuracoes` / `@salvarConfiguracoes` (`{corte, cobranca_padrao, ligada}`; PUT aceita `corte` `Y-m-d` ou null e `cobranca_padrao`, cada um opcional; `ligada` só leitura) |
| GET `financeiro/lojas` | `FinanceiroLojasController@lojas` |
| PUT `financeiro/lojas/{id}/ciclo` | `@salvarCiclo` |
| GET `financeiro/lojas/{id}/extrato?inicio&fim` | `@extratoDaLoja` |
| GET `financeiro/faturas?situacao&loja` | `@faturas` (situação gravada ou calculada: rascunho, emitida, cancelada, vencida, aberta, paga) |
| POST `financeiro/faturas` | `@gerarRascunho` |
| GET / PUT / DELETE `financeiro/faturas/{id}` | `@fatura` / `@mudarVencimento` (`{fatura, itens}`) / `@apagar` (só rascunho, `{apagada: true}`) |
| POST `financeiro/faturas/{id}/ajustes`, `/nao-cobrar`, `/emitir`, `/cancelar` | `@ajuste`, `@naoCobrar`, `@emitir`, `@cancelar` |
| POST `financeiro/faturas/{id}/reenviar` | `@reenviar` |
| GET / POST `financeiro/multiplicadores` | `MultiplicadoresController@lista` / `@criar` |
| PUT / DELETE `financeiro/multiplicadores/{id}`, POST `.../{id}/encerrar` | `@alterar` / `@apagar` (só agendado) / `@encerrar` |
| PUT `pagamento-motoboys/faixas-juntas` | `PagamentoMotoboysController@salvarFaixasJuntas` (segunda tabela; lista vazia desliga) |

Portal (mesmo grupo, `throttle:60,1`): GET `loja/financeiro` e `loja/faturas/{id}` (`PortalLojaController`).

App (grupo `v1/entregas/motoboy`, `fleetbase.api` + `throttle:entregas-motoboy`, token de motoboy): GET `extrato?inicio&fim` (até 92 dias, público + `conta.pix`) e `pagamentos/{uuid}` (só pagamento dele; senão 404) (`MotoboyController`). O `ganhos` continua para os APKs antigos.

Comprovante: GET `v1/entregas/comprovantes/{uuid}` (`ComprovantesController@mostrar`, `signed` + `throttle:60,1`, sem token).

**`use` de controller faltando no `RouteServiceProvider` derruba a API** (o `scripts/teste-php/imports-dos-providers.php` confere).

## Mensagens 422 que o admin pode ver

- "Valor ambíguo: para mil e quinhentos, escreva 1500 ou 1.500,00 (vírgula antes dos centavos)." (`FinanceiroController::MILHAR_SEM_CENTAVOS`): texto no formato de milhar com ponto e sem vírgula (`^[+-]?\d{1,3}(\.\d{3})+$`), no lançar, no pagamento/recebimento e no ajuste da fatura. "1.500,00", "1500" e "1.50" valem.
- "Há mais de um pedido com esse número; use o código do pedido (order_…)" e "Pedido não encontrado." (pedido do ajuste).
- "A corrida deste pedido já foi estornada." (ajuste negativo com pedido sem corrida valendo em alguma das contas; o caminho inverso é o `estornosDevidos`).
- "Diária e bônus não aceitam valor negativo.", "Só o ajuste pode ter pedido.", "Lance para até 100 contas de uma vez.", "Estorne o pagamento pelo botão do pagamento.".
- "Saque em andamento.", "O dinheiro do saque já saiu; corrija com um ajuste.", "Este saque não foi concluído e o pagamento já foi estornado." (estornar pagamento de saque).
- "O comprovante precisa ser uma imagem ou um PDF.", "O comprovante pode ter até 10 MB.", "Envie um comprovante só.".
- Faturas: "Fatura sem valor a cobrar; cancele o rascunho.", "A fatura já foi emitida: cancele em vez de apagar.", "Informe o motivo do ajuste (a loja vê este texto).", "Esta entrega já está marcada como não cobrada.", "O vencimento não pode ser antes do fim do período.", "Só a fatura emitida vai por e-mail.", e as do ciclo (`CicloDeCobranca::validar`).

## Rotinas agendadas

Em `api/app/Console/Kernel.php`, todas com `when(fn () => Financeiro::ligada())`, `runInBackground()` e saída no container (`docker service logs entregas_scheduler`), na hora de Brasília:

| Comando | Quando | Trava |
|---|---|---|
| `entregas:financeiro-conferir` | a cada 5 min | `withoutOverlapping(10)` |
| `entregas:financeiro-rascunhos` | 00:30 | `withoutOverlapping(30)` |
| `entregas:financeiro-avisos` | 09:00 | `withoutOverlapping(10)` |
| `entregas:financeiro-lancar-historico {--company=}` | manual | — |

O job `LancarCorridaDoPedido` e o push `PagamentoRegistrado` rodam na fila: log em `docker service logs entregas_queue | grep '\[entregas\]'`.

## Implantação

1. Push (Edgard) e `bash deploy/atualizar.sh` (api + console). A migration roda no `deploy.sh`; entre o restart da API e a migration, as telas novas podem dar 500 por alguns segundos (o relatório antigo não: ver "Depois de lançada").
2. `docker-stack.yml` novo no Portainer com `ENTREGAS_FINANCEIRO=1` no `stack.env` (Stacks → entregas → Editor → Update the stack, "Re-pull image" desligado).
3. Data de corte em Fleet-Ops → Financeiro → Configurações (e o ciclo padrão das lojas).
4. `docker exec -it $(docker ps -q -f name=entregas_application) php artisan entregas:financeiro-lancar-historico` (repetir é seguro).
5. Pendências antigas como "Saldo inicial"; ciclo e prazo de cada loja na aba Lojas; chave Pix dos motoboys.
6. **App:** o APK do extrato (ramo `financeiro-app` do `entregas-navigator`) só depois do deploy da API: cada push na `main` do app vira versão obrigatória (trava "Atualize o app"), e sem as rotas o Início mostra "Não foi possível carregar o extrato".
7. Conferir em produção: upload do comprovante e link assinado em nova aba (HTTPS atrás do tunnel), impressão da fatura, e-mail real na loja de testes (Terraço; precisa de SMTP), push e recibo no celular, `node scripts/teste-isolamento-lojas.mjs` (a loja A não abre a fatura da B).

## Testes

`PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/<teste>.php` (terminam em `FALHAS: 0`):

- `financeiro-quitacao.php` (Quitacao, puro);
- `financeiro-pagamentos.php` (migration, Financeiro, Lancamentos, Pagamentos, insert ignorado sem a chave);
- `financeiro-lancador.php` (lançador com `CalculoEntregas` falso, transação, conferência da loja que faltou, estorno sem debitar duas vezes, relançar, janela de 30 dias, sessão devolvida, observador, job, comandos, agenda, provider, config e stack);
- `financeiro-console.php` (ExtratoDaConta, FinanceiroController, PainelFinanceiro, MotoboyController@extrato, @pagamento e @carteira, Carteira, ComprovantesController, rotas);
- `financeiro-ciclo.php` (CicloDeCobranca, puro); `financeiro-faturas.php` (Faturas no banco em memória); `financeiro-lojas.php` (FinanceiroLojasController, rascunhos, rotas);
- `financeiro-portal-emails.php` (portal, e-mails, avisos, "Console × portal", entrega do extrato do portal);
- `entrega-junto.php` (ValorDaEntrega, congelamento das duas tabelas, prévia, atribuição, reserva, observador) e `financeiro-multiplicadores.php` (períodos, MultiplicadoresController, rotas);
- e também `ganhos-motoboy.php` (uuids na entrega e valor que não muda depois de lançado), `ifood-agendador.php` (horários e `when` das rotinas), `avisos-push.php` (`PagamentoRegistrado`), `emails.php`, `imports-dos-providers.php`.

Os testes usam `scripts/teste-php/stubs-financeiro.php` (sobre `stubs-ifood.php` e `stubs-ifood-fleetbase.php`): Setting, Company, Str, Storage (disco `Teste\Disco`, com `response()`), UploadedFile, URL e `reiniciarFinanceiro()` (empresa-1 com corte 2026-10-01, chave ligada). O banco em memória do `stubs-ifood.php` lê a migration `*_entregas_financeiro_*` e tem `sum`, `selectRaw` com `groupBy`, `lockForUpdate` (sem efeito), `like` e `Command::option()`; o `Driver` falso tem soft delete. O `CalculoEntregas` real só roda com o `stubs-ganhos.php`, que não convive com o `stubs-ifood.php` (daí o teste do valor congelado no `ganhos-motoboy.php`).

Front: `scripts/teste-portal/financeiro.test.mjs`, `financeiro-faturas.test.mjs` e `entregas-financeiro.test.mjs`; app: `scripts/testes/extrato.teste.ts` no `entregas-navigator`.

## Armadilhas

- Um pedido sem valor (sem faixas, OSRM fora) é consultado de novo a cada 5 min por até 30 dias depois da conclusão; o painel avisa depois de 1 h.
- Rascunho gerado à mão para o ciclo em andamento ocupa o fim daquele período: a rotina não cria outro, e o que concluir depois fica para o ciclo seguinte. Agendador fora do ar por mais de 7 dias perde o rascunho automático daquele ciclo (os lançamentos entram no seguinte, ou o admin gera à mão).
- Com a chave desligada, emitir e reenviar não mandam e-mail (`motivo: desligado`), e o painel mostra a fatura em "não enviada por e-mail".
- O `insertOrIgnore` do MySQL engole outros erros além da chave repetida: por isso o `Lancamentos::inserir` confere a chave quando volta `null`.
- `json_encode` grava `2.0` como `2` nos `detalhes`; o portal recebe `detalhes.faixa` com casas.
- Loja de tipo diferente de `customer` fica fora da lista de Lojas (aceito na revisão).

## Ao atualizar o fleetops-api ou o core-api, confira

- `Order::updated` ainda dispara na conclusão (o app conclui pela API v1) e o `status` muda para `completed`/`done`.
- `orders.uuid`, `orders.status` e `orders.customer_uuid` → `vendors.uuid` (a conferência usa `whereNotExists`/`whereExists` e a junção do estorno).
- O `CalculoEntregas::pedidosConcluidos` (tracking status `COMPLETED`, `driver_assigned_uuid`) e o `StatusDoPedido::CANCELADOS`.
- `Driver` continua com `SoftDeletes` (`withTrashed()` e `deleted_at`).
- `Setting::lookup`/`Setting::configure` e a tabela `settings` (`key like 'company.%.entregas.financeiro_corte'`).
- `vendor_personnels.status` e os contatos `customer` do Vendor (destinatários dos e-mails).
- No Laravel: `URL::temporarySignedRoute` e o middleware `signed` (comprovante), `insertOrIgnore`, e o `Storage::disk('local')->response`.
