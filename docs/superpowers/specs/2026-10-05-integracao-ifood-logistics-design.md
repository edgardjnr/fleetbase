# Integração iFood Logistics: desenho

Data: 2026-10-05. Situação: desenho aprovado pelo Edgard; etapa 1 implementada; ajustes da sonda da API (PLC, ações sem evento, DDCR na confirmação, pedido de teste sem código) aprovados em 2026-10-05; **etapa 2 implementada** em 2026-10-05 (ramo `ifood-etapa-2`, planos `2026-10-05-ifood-etapa-2a-servidor.md` e `2026-10-05-ifood-etapa-2b-tela-lojas.md`), em produção e testada com a loja de teste em 2026-10-06. Onde a etapa 2 divergiu deste desenho, o texto abaixo já foi corrigido; o resumo operacional está no `CLAUDE.md`, seção "Integração iFood".

## Objetivo

Receber os pedidos iFood dos restaurantes clientes do Entregas e cuidar da entrega com os nossos motoboys, pelo
módulo **Logistics** da Merchant API do iFood: o restaurante confirma e prepara no Gestor de Pedidos ou no PDV dele
(entrega própria, `deliveredBy: MERCHANT`), e nós informamos ao iFood cada etapa da entrega até a confirmação do
código do cliente.

Substitui a ideia antiga de um sistema externo chamando `POST v1/orders` (ver `LojasController` e
`2026-10-03-portal-da-loja-continuacao.md`): a integração nasce dentro da `api/app`.

## Decisões (perguntas do brainstorming)

| # | Tema | Decisão |
|---|---|---|
| 1 | Onde mora a integração | Dentro da `api/app` (Laravel), sem serviço externo |
| 2 | Quando o pedido vai aos motoboys | Na hora em que chega, como pedido aberto (adhoc) aos motoboys próximos, igual ao portal. Atraso por loja fica para depois |
| 3 | Autenticação | App **distribuído**; cada loja é vinculada pela tela Lojas (userCode + authorizationCode) |
| 4 | Conta iFood Developer | O Edgard tem conta CNPJ; faltam o app distribuído e a loja de teste (etapa 0) |
| 5 | Código de entrega | Digitado só pelo motoboy, no app (sem reserva no console) |
| 6 | Pagamento na entrega | Valor a cobrar, forma e troco no alarme e nos detalhes; sem filtro de motoboy |
| 7 | iFood cancela depois da coleta | Se o `dispatch` já foi enviado, o motoboy recebe e a loja é cobrada pelo valor da faixa |
| 8 | Ninguém aceita | Raio crescente nos reenvios e aviso à central no fim |
| 9 | Alcance do raio crescente | **Todos** os pedidos abertos: R, 1,5R, 2R, 2R; aviso à central com 12 min sem aceite (pelo tempo desde o despacho, mesmo sem motoboy no raio) |
| 10 | Pedido agendado | Vai aos motoboys 40 min antes do início da janela de entrega |
| — | Arquitetura | Polling pelo agendador, tabela de eventos e fila (abordagem 1; webhook pode ser ligado depois na mesma tabela) |

## Referência rápida da API do iFood (lida em 2026-10-05)

- **Token:** `POST https://merchant-api.ifood.com.br/authentication/v1.0/oauth/token` (form-urlencoded: `grantType`,
  `clientId`, `clientSecret` e, conforme o tipo, `authorizationCode` + `authorizationCodeVerifier` ou `refreshToken`).
  Token de 6 h, até 8000 caracteres. Distribuído: `POST /authentication/v1.0/oauth/userCode` devolve `userCode`,
  `authorizationCodeVerifier`, `verificationUrl` e `verificationUrlComplete`; o código vale 10 min.
- **Polling:** `GET /events/v1.0/events:polling?excludeHeartbeat=true` a cada 30 s (sem o parâmetro, a loja abre
  indevidamente), header `x-polling-merchants` com até 100 ids, limite de 6000 RPM. Eventos podem vir duplicados e
  fora de ordem; retenção de 8 h.
- **Ack:** `POST /events/v1.0/events/acknowledgment` com `[{id}]`, só depois de gravar. 50 entregas sem ack = 1
  strike; 100 strikes bloqueiam o polling por 5 min.
- **Pedido:** `GET /logistics/v1.0/orders/{id}`. Traz `merchant.merchantAddress` (lat/lng), `customer.phone` (0800
  do iFood, localizador e expiração; some 3 h depois da entrega), `payments.pending` e `methods` (CASH com
  `cash.changeFor`), `delivery.deliveryAddress` (coordenadas, complemento, referência), `delivery.observations` e
  `schedule`. Pedido de teste vem com coordenadas 0,0.
- **Ações** (`POST /logistics/v1.0/orders/{id}/...`, respondem 202 e o resultado chega por evento), nesta ordem
  (fora dela, 409): `assignDriver` (`{workerName, workerPhone, workerVehicleType: "MOTORCYCLE"}`), `goingToOrigin`,
  `arrivedAtOrigin`, `dispatch`, `arrivedAtDestination`, `verifyDeliveryCode` (`{code}` → `{success}`; código
  errado = 422), este último obrigatório quando chega `DELIVERY_DROP_CODE_REQUESTED` (DDCR). 100 req/min; em 429,
  respeitar `Retry-After`.
- **Eventos relevantes:** PLC, CFM, RTP, DSP, CON, CAN, CAR, CARF, ADR, GTO, AAO, DDD, CLT, AAD, DDCR, DDCS, DPCR,
  OPA.
- **Homologação:** chamado "Solicitação de Homologação - Logistics API", sessão remota de 45 a 60 min; reprovação =
  15 dias de espera. Testam ack, renovação proativa e reativa do token, 429/409/422, DDCR, credenciais em variável
  de ambiente e PII fora dos logs.

## Arquitetura

```
iFood ──polling 30 s──> entregas:ifood-polling (scheduler) ──grava──> entregas_ifood_eventos ──ack──> iFood
                                                      └──fila──> ProcessarPedidoIfood (queue, 1 por pedido)
                                                                   ├─ cria/atualiza o Order do Fleetbase
                                                                   └─ cancela (CAN)
Order do Fleetbase (aceite, started, enroute, GPS, conclusão) ──fila──> EnviarAcaoIfood ──> iFood
```

### Unidades

Classes novas em `api/app` (PHP próprio entra na imagem da API; nada em `packages/*/server`).

| Unidade | Papel | Depende de |
|---|---|---|
| `Support/Entregas/Ifood/ClienteIfood` | Única porta HTTP para o iFood: token, userCode, lojas do token, polling, ack, pedido (ações na etapa 3). Devolve erros tipados (`ErroIfood`, com o `Retry-After` do 429, teto de 300 s; rede = status 0). Não renova: o 401 é tratado no `VinculosIfood::comToken` (renova uma vez e repete) | config |
| `Support/Entregas/Ifood/VinculosIfood` | Lê e grava `entregas_ifood_lojas`; troca código por tokens; renova; marca "vínculo perdido" | `ClienteIfood` |
| `Support/Entregas/Ifood/EventosIfood` | Funções puras: deduplicar e ordenar eventos, classificar o código (cria, atualiza, cancela, pede código, ignora) | — |
| `Support/Entregas/Ifood/PedidoDoIfood` | Função pura: payload do iFood → dados do Order (entrega, `internal_id`, agendamento, teste 0,0) e da `entregas_ifood_pedidos` (cobrança, 0800, observações) | — |
| `Support/Entregas/Ifood/CriadorDoPedidoIfood` (etapa 2) | Adaptador sobre os models do Fleet-Ops: cria Place, Payload, Order e a linha de `entregas_ifood_pedidos` numa transação e despacha (com a `TravaDoPedido`) | `PedidoDoIfood` |
| `Support/Entregas/Ifood/SequenciaIfood` | Função pura: dada a última ação aceita e a ação desejada, lista as ações a enviar antes | — |
| `Support/Entregas/Ifood/CobrancaIfood` | Função pura: texto "Cobrar R$ 58,90 · dinheiro · troco p/ R$ 100" | — |
| `Support/Entregas/Ifood/ChegadaPeloGps` | Função pura: posição do motoboy × coleta/entrega → ação de chegada a enviar (raio de 100 m) | — |
| `Console/Commands/Entregas/PollingIfood` (`entregas:ifood-polling`) | A cada 30 s, sem sobrepor: polling por grupos de 100 lojas, grava, ack, enfileira | `ClienteIfood`, `EventosIfood` |
| `Console/Commands/Entregas/AgendadosIfood` (`entregas:ifood-agendados`, etapa 2) | A cada minuto: despacha os agendados iFood vencidos e refaz o despacho que falhou; desiste depois de 30 min, com aviso à central | `CriadorDoPedidoIfood` |
| `Console/Commands/Entregas/AcompanharIfood` (`entregas:ifood-acompanhar`, etapa 3) | A cada 30 s: chegadas pelo GPS (o despacho dos agendados ficou no `entregas:ifood-agendados`) | `ChegadaPeloGps` |
| `Console/Commands/Entregas/RenovarTokensIfood` (`entregas:ifood-tokens`) | A cada 30 min: renova os tokens que vencem em menos de 1 h | `VinculosIfood` |
| `Jobs/Entregas/ProcessarPedidoIfood` | Processa os eventos pendentes de um pedido, em ordem, com uma trava própria por pedido do iFood (`entregas:ifood-pedido:<id>`; a `TravaDoPedido` fica no despacho) | `CriadorDoPedidoIfood`, `EventosIfood` |
| `Jobs/Entregas/EnviarAcaoIfood` | Envia uma ação (e as anteriores que faltam), com novas tentativas | `SequenciaIfood`, `ClienteIfood` |
| Observador dos pedidos iFood | Converte mudança do Order (motoboy definido, `started`, `enroute`) em `EnviarAcaoIfood` | — |
| `Http/Controllers/Entregas/IfoodLojasController` | Vínculo na tela Lojas | `VinculosIfood` |
| `MotoboyController` (rotas novas) | Dados iFood do pedido, conclusão e código de entrega | `ClienteIfood` |

Os comandos entram no agendador da `api/app` e só rodam com `ENTREGAS_IFOOD=1`.

### Tabelas novas (migrations em `api/database/migrations`, rodadas pelo `deploy.sh`)

- **`entregas_ifood_lojas`**: `company_uuid`, `vendor_uuid` (único), `merchant_id` (único), `nome_ifood`,
  `access_token` e `refresh_token` (criptografados com `encrypt()`), `expira_em`, `situacao`
  (`vinculada` | `vinculo_perdido` | `desvinculada`), datas.
- **`entregas_ifood_eventos`**: `id` do iFood (chave única), `merchant_id`, `pedido_ifood_id`, `codigo`,
  `criado_no_ifood` (`createdAt`), `payload` (JSON), `processado_em`, `ignorado` (bool). Processados há mais de 7
  dias e pendentes gravados há mais de 30 dias são apagados (uma vez por dia).
- **`entregas_ifood_pedidos`**: `order_uuid` (único), `pedido_ifood_id` (único), `numero` (ex.: 4821),
  `merchant_id`, `telefone_0800`, `localizador`, `telefone_expira_em`, `cobrar_centavos`, `forma_pagamento`,
  `troco_para_centavos`, `observacoes`, `complemento`, `referencia`, `exige_codigo` (bool), `ultima_acao`,
  `cancelado_pelo_ifood_em`, `pago_mesmo_cancelado` (bool), `teste` (bool) e, desde a etapa 2, também `company_uuid`,
  `vendor_uuid`, `agendado` (bool), `despachar_em` e `despachado_em` (fila do `entregas:ifood-agendados`, com índice
  composto `despachado_em, despachar_em`).
  Fica **fora do `meta`** do Order de propósito: o `meta` sai na API v1 e no socket.

As credenciais do app (`IFOOD_CLIENT_ID`, `IFOOD_CLIENT_SECRET`) e o interruptor (`ENTREGAS_IFOOD`) ficam no
`stack.env`, nunca no banco nem no código.

## 1. Vínculo das lojas

- Cada Loja (Vendor) tem no máximo um `merchantId`, e cada `merchantId` pertence a uma Loja só.
- **Tela Lojas (só admin):** coluna **iFood** com a situação ("Vinculada · Pizzaria X", "Vínculo perdido" em
  vermelho, "—").
- **Vincular iFood** (modal em dois passos):
  1. O servidor pede o userCode ao iFood; o modal mostra o código grande (ex.: `HJLX-LPSQ`), o link do Portal do
     Parceiro com o código preenchido (`verificationUrlComplete`) e a contagem de 10 min. Quem tem o login do
     Portal do Parceiro (normalmente o dono do restaurante) abre o link e autoriza.
  2. O dono passa o código de autorização à central, que cola no campo. O servidor troca pelos tokens (com o
     `authorizationCodeVerifier` guardado no passo 1, no cache, por 10 min) e lista as lojas da conta iFood: uma só
     é escolhida sozinha; várias, a central escolhe.
- **Desvincular** apaga os tokens e tira a loja do polling.
- **Renovação:** proativa a cada 30 min (tokens que vencem em menos de 1 h, pelo refresh token) e reativa (401 →
  renova uma vez e repete). Refresh recusado pelo `/oauth/token` (400 ou 401) → `vinculo_perdido`, log `[entregas]
  ifood: vínculo perdido` e a loja sai do polling até novo vínculo. O access token é apagado e o refresh fica cifrado,
  para reativar se a causa foi configuração (credencial errada, APP_KEY trocado). Os outros erros da renovação não
  derrubam a loja.
- **Coleta = Local da Loja**, não o endereço do iFood. A mais de 300 m de `merchant.merchantAddress`, o log avisa
  (`[entregas] ifood: coleta diverge do iFood`), porque um dos cadastros provavelmente está errado.

## 2. Entrada dos pedidos

- **Polling:** `entregas:ifood-polling` a cada 30 s, `withoutOverlapping`. Agrupa as lojas vinculadas pelo token
  (até 100 por chamada) e chama o polling com `excludeHeartbeat=true`.
- **Gravação e ack:** cada evento entra em `entregas_ifood_eventos` (o `id` único descarta duplicados). O ack só sai
  depois da gravação; se o banco falhar, o iFood reenvia na rodada seguinte.
- **Processamento:** um `ProcessarPedidoIfood` por pedido com evento novo, com uma trava própria por pedido do iFood
  (`entregas:ifood-pedido:<id>`), processa os pendentes **em ordem de `createdAt`**. Evento desconhecido fica gravado
  como ignorado. Na etapa 2 os eventos de etapa da entrega só ficam registrados; a regra "evento mais antigo que o
  estado atual é ignorado" (um "a caminho" atrasado não volta a etapa) é da etapa 3. Como o Redis da produção não
  persiste a fila, cada rodada do polling enfileira de novo os pedidos com evento pendente há mais de 2 min (varredura).
- **Criação do pedido:** no **PLC** (a sonda de 2026-10-05 mostrou que o pedido já está disponível para a logística no
  PLC: o `GET logistics/orders/{id}` responde e as ações são aceitas antes do CFM). Se o PLC se perder, só um evento
  **anterior à coleta** cria (CFM, RTP, DDCR, DPCR). Pedido com CAN, ou que já passou da coleta (DSP, CON, CLT, DDD,
  AAD, DDCS, GTO, ADR, AAO entre os eventos gravados dele), nunca é criado: iria aos motoboys um pedido que não existe
  mais. O job busca `GET logistics/orders/{id}` e cria o Order:
  - cliente = Vendor da Loja; coleta = Local da Loja;
  - entrega = Place novo com coordenadas, rua, número, bairro, complemento, referência e nome do cliente;
  - tipo `transport`, `internal_id` = número curto do iFood (`#4821`), que aparece no console, no app e no portal;
    adhoc só no pedido que vai aos motoboys sozinho (imediato e agendado com janela);
  - despacho na hora, como no portal (com a `TravaDoPedido` e o pedido relido);
  - **agendado:** `scheduled_at` = início da janela − 40 min, sem despacho na hora. Quem despacha é o
    `entregas:ifood-agendados` (a cada minuto: pedido iFood não despachado com `despachar_em` vencido; também refaz o
    despacho imediato que falhou e desiste depois de 30 min, com o aviso sonoro "sem motoboy" à central). O
    `fleetops:dispatch-orders` não serve sozinho: só despacha quem cai na janela de ±1 min da rodada, e um minuto
    perdido deixaria o pedido parado. Agendado que chega com menos de 40 min para a janela é despachado na hora.
    Agendado sem janela legível não é despachado: `[AGENDADO SEM HORÁRIO]` nas notas e log, para a central conferir;
  - **pedido real sem coordenadas válidas** (ausentes, 0, fora da faixa ou a mais de 50 km da coleta): entrega
    deslocada como a do teste, `[SEM LOCALIZAÇÃO]` nas notas, log e sem despacho (a central confere);
  - **pedido de teste (`isTest: true`, coordenadas 0,0):** marcado `[TESTE]` (`teste = true`), entrega na coordenada da loja
    deslocada 1 km (para não calcular km absurdo). **Vai aos motoboys como o real** (decisão de 2026-10-06: adhoc ligado,
    despacho na hora; o agendado com janela, 40 min antes): quem está online perto da loja recebe o alarme, com o
    endereço falso.
- **Alteração (ORDER_PATCHED):** busca o pedido de novo e atualiza entrega, observações e pagamento. Endereço
  mudado depois do aceite → push "Endereço alterado" ao motoboy. O km se recalcula pela regra existente
  (`CalculoEntregas`/`ValoresCongelados`).
- **Pagamento:** pedido todo pago online **não traz `payments`** no Logistics (visto na sonda) = nada a cobrar na
  porta. Com `payments.pending > 0`, vale o formato da documentação (a conferir na homologação: o gerador de pedidos de
  teste só cria pedido pago online). O `pending` explícito manda (0 = nada a cobrar, mesmo com método não pago); só
  sem ele vale a soma dos métodos não pagos. Formato divergente ou valor implausível vai para o log
  `[entregas] ifood: pagamento inconsistente`.
- **Código de entrega:** o DDCR chega **logo depois do CFM** (na confirmação, sem metadata). O job marca
  `exige_codigo = true` no pedido assim que o recebe.
- **Recusa da loja antes da coleta:** como o pedido vai aos motoboys já no PLC, a loja pode recusar depois; isso chega
  como CAN e segue a regra de cancelamento (seção 3), sem pagamento se o `dispatch` ainda não saiu. **Até a etapa 3, o
  CAN não cancela o pedido no Entregas:** grava `cancelado_pelo_ifood_em` e, no pedido ainda não despachado nem aceito
  (agendado ou despacho que falhou), tira o Order do agendamento (`scheduled_at` nulo e adhoc desligado, com a
  `TravaDoPedido`; senão o `fleetops:dispatch-orders` o despacharia aos motoboys na hora marcada) e a linha da fila do
  agendador. O pedido já despachado continua aberto aos motoboys, por isso nenhuma loja real é vinculada antes da
  etapa 3.
- **PII:** os logs `[entregas] ifood:` levam só ids e números de pedido, nunca nome, telefone ou endereço.

## 3. Ciclo da entrega

O app do motoboy continua com as etapas de hoje; o servidor converte cada uma em ação do iFood e completa as que o
fluxo do Fleetbase (criado → despachado → iniciado → a caminho → concluído) não tem.

| No nosso sistema | Ação no iFood |
|---|---|
| Motoboy definido (aceite do pedido aberto ou atribuição pela central) | `assignDriver` (nome, telefone, MOTORCYCLE) |
| Pedido iniciado (`started`) | `goingToOrigin` |
| Motoboy a até 100 m da coleta, pelo GPS | `arrivedAtOrigin` |
| Motoboy toca "A caminho" (`enroute`) | `dispatch` |
| Motoboy a até 100 m da entrega, pelo GPS | `arrivedAtDestination` |
| Motoboy conclui | `verifyDeliveryCode`, quando exigido (seção 4) |

- **Sequência garantida:** `ultima_acao` guarda a última ação aceita. Antes de qualquer ação, o job envia as
  anteriores que faltam (ex.: GPS falhou e o motoboy tocou "A caminho" → `arrivedAtOrigin` e depois `dispatch`).
  **A `ultima_acao` é a única fonte do estado das ações:** o iFood não devolve evento para as nossas próprias ações e
  o pedido do Logistics não tem campo de status (visto na sonda). As ações respondem 202 sem corpo.
- **Chegada pelo GPS:** o `entregas:ifood-acompanhar` (30 s) compara a última posição do motoboy dos pedidos iFood
  em andamento com a coleta ou a entrega. Não escuta cada posição, então não pesa no socket.
- **Troca de motoboy pela central:** `assignDriver` de novo com o motoboy novo. Se o iFood responder 409 (a
  documentação não diz se aceita depois do `goingToOrigin`), a central recebe aviso e o log registra.
- **Falhas das ações:** 429 → espera o `Retry-After`; 5xx ou rede → até 5 tentativas com espera crescente; 409 (ou
  outro 4xx) → não há estado no iFood para consultar: registra `[entregas] ifood: ação recusada` (com a ação, o status
  e o corpo da resposta) e avisa a central no console, sem nova tentativa. Tudo roda na fila; nada trava o app.
- **Cancelamento pelo iFood (CAN)**, com a `TravaDoPedido`:
  - pedido já concluído: nada muda, só log;
  - nos outros casos, cancela como o portal (atividade, evento `OrderCanceled`, sai dos abertos), e o motoboy, se
    houver, recebe o push "Pedido #4821 cancelado pelo iFood";
  - **se o `dispatch` já tinha sido enviado:** `pago_mesmo_cancelado = true`. O relatório de pagamento e a cobrança
    da loja passam a contar o pedido pelo valor congelado da faixa, **na data do cancelamento** (os concluídos
    continuam pela data do `COMPLETED`). O push acrescenta "Você recebe por esta entrega. Combine com a loja a
    devolução.";
  - o pedido de cancelamento da loja (CAR) só fica registrado: quem decide é o iFood, e para nós vale o CAN.
- **Cancelamento do nosso lado é proibido** no pedido iFood: portal da loja, console e API v1 respondem 400 "Pedido
  do iFood: o cancelamento é feito no iFood" (no `RegrasPortalLoja`, no `BarrarAceiteDePedidoEncerrado` e na rota de
  cancelar do console). O Logistics não tem ação de cancelar; com problema do motoboy, a central troca o motoboy.

## 4. App do motoboy, console e portal

### App do motoboy (APK novo, repo `entregas-navigator`)

- **`GET v1/entregas/motoboy/pedidos/{id}/ifood`** (card de aceitar e detalhes): número iFood, valor a cobrar,
  forma e troco, observações, complemento e referência. **0800 e localizador** só para o motoboy do pedido e
  enquanto não expirarem; no pedido ainda aberto, não vão. Mesmas regras de acesso das rotas do motoboy
  (`MotoboyDaSessao`).
- **Alarme:** o push ganha `entregas_cobrar` (texto da `CobrancaIfood`) e `entregas_ifood` (número); o cartão
  mostra os dois em destaque.
- **Detalhes:** faixa amarela da cobrança; **Ligar para o cliente** (disca o 0800 e mostra o localizador com botão
  copiar); complemento e referência ao lado do endereço.
- **Concluir pedido iFood:**
  1. o app chama `POST v1/entregas/motoboy/pedidos/{id}/concluir-ifood`;
  2. o servidor garante o `arrivedAtDestination` (o DDCR já chegou na confirmação do pedido, então `exige_codigo` já
     está gravado; não precisa de polling na hora);
  3. com `exige_codigo`, a resposta é "precisa de código"; o app abre o campo do código e envia
     `POST .../codigo-ifood`. O servidor confere com o iFood **na hora, fora da fila**: certo → conclui; **400
     `Confirmation code is invalid`** (visto na sonda; a documentação dizia 422) → "Código incorreto, peça de novo ao
     cliente"; outro erro → "Não consegui conferir agora, tente de novo";
  4. sem `exige_codigo`, conclui direto;
  5. **pedido de teste (`isTest`) conclui sem código**: o código só aparece no app do cliente, que não existe no teste.
     O código de verdade é testado na sessão de homologação.
- **Trava:** a conclusão comum (atividade `completed` da API v1) de pedido iFood é recusada com 400 "Atualize o
  app". **O APK novo precisa estar em todos os celulares antes de vincular a primeira loja real.**

### Console (central)

- Selo **iFood #4821** na lista, no quadro e no detalhe do pedido.
- Painel **iFood** no detalhe: última ação aceita, cobrança e observações (rota interna própria, só admin).
- Botão **Cancelar** escondido nos pedidos iFood.
- **Aviso "sem motoboy" (todos os pedidos):** com 12 min sem aceite desde o despacho (mesmo sem motoboy no raio), a API transmite `entregas.pedido_sem_motoboy` no canal
  `company.<uuid>`; o console toca um som e mostra uma notificação fixa com o link do pedido até alguém atribuir um
  motoboy ou cancelar.
- **Raio crescente (todos os pedidos)**, no `ReenviarPedidosAbertos`: aviso inicial com R
  (`getAdhocPingDistance()`), reenvios com 1,5R a partir de ~4 min e 2R a partir de ~8 min do despacho (pelo tempo, mesmo sem reenvio antes).

### Portal da loja

- O pedido iFood aparece normalmente (o cliente é o Vendor da loja), com o selo iFood e sem o botão Cancelar; o
  `RegrasPortalLoja` recusa o cancelamento também no servidor.

## 5. Erros, testes e implantação

### Interruptor e observabilidade

- `ENTREGAS_IFOOD=1` no `stack.env` liga polling, acompanhamento, renovação e ações; começa desligado. Com ele
  ligado, só entram pedidos das lojas vinculadas.
- Logs `[entregas] ifood:` sem PII. No `entregas_scheduler`: "polling falhou", "vínculo perdido", "429, esperando
  N s". No `entregas_queue`: "ação recusada", "evento ignorado", "coleta diverge do iFood (N m)".

### Testes

- O `ClienteIfood` é a única porta HTTP; nos testes, uma versão falsa devolve respostas gravadas (as da sonda:
  `docs/ifood/referencia-logistics.md`, "Descobertas da sonda") e 400, 401, 409, 429 e 5xx.
- php-wasm (`scripts/teste-php/ifood-*.php`): `EventosIfood`, `SequenciaIfood`, `PedidoDoIfood`, `CobrancaIfood`,
  `ChegadaPeloGps`, a regra do pago mesmo cancelado, as travas de cancelamento e de conclusão e o ack só depois da
  gravação.
- App: fluxo do código de entrega e texto da cobrança em `scripts/testes/*.teste.ts`.
- Real: roteiro com a loja de teste na etapa 5, seguindo a lista da homologação.

### Roteiro de implantação

| Etapa | O quê | Quem |
|---|---|---|
| 0 | ✅ (2026-10-05) App de teste **distribuído** "Teste (D)" com todos os módulos (inclusive Logistics), app centralizado "Teste (C)" ativo na loja de teste, loja de teste (merchant `4173843`, UUID `d5d191fa-2e43-4b86-aa9c-9f8c8b251378`, entrega própria) e sonda da API (`scripts/ifood-sonda.mjs`). No deploy da etapa 2: `IFOOD_CLIENT_ID`/`IFOOD_CLIENT_SECRET` do app distribuído no `stack.env` | Edgard + Claude |
| 1 | Raio crescente e aviso "sem motoboy" (todos os pedidos; independe do iFood) | Claude; deploy pelo Edgard |
| 2 | ✅ código (2026-10-05, ramo `ifood-etapa-2`; faltam push, deploy e o teste real). API: tabelas, vínculo na tela Lojas, polling, ack e criação do pedido (no PLC). Primeiro teste real com a loja de teste | Claude + Edgard |
| 3 | Ciclo da entrega, chegada pelo GPS, cancelamento (inclusive o pago mesmo cancelado) e as travas | Claude |
| 4 | APK novo (cobrança, 0800, código) e console/portal (selo, painel, sem Cancelar) | Claude; instalação pelo Edgard |
| 5 | Ensaio da homologação com a loja de teste | Claude + Edgard |
| 6 | Chamado "Solicitação de Homologação - Logistics API" e sessão com o iFood | Edgard |
| 7 | Vincular os restaurantes reais, um por vez | Central |

Cada etapa (1 a 4) vira um plano de implementação próprio.

## Riscos e pontos em aberto

- Celular do motoboy com problema trava a conclusão da entrega iFood (o código só é digitado no app).
- A documentação não responde (e a sonda não testou): se o iFood aceita trocar o motoboy depois do `goingToOrigin`,
  o formato de `payments` com cobrança na porta e se o raio de 100 m da chegada é adequado. Confirmar nas etapas 3 e 5.
- O pedido vai aos motoboys no PLC, antes de a loja confirmar: a recusa da loja vira CAN com o motoboy talvez a
  caminho (sem pagamento antes da coleta).
- O código de entrega não pode ser testado com pedido de teste; só na homologação.
- Reprovação na homologação = 15 dias de espera.
- O raio crescente e o aviso "sem motoboy" mudam o comportamento de todos os pedidos abertos (portal e central).
- A trava de conclusão faz um APK antigo não conseguir concluir pedido iFood.

## Fora do escopo

- Webhook (a tabela de eventos já deixa o caminho pronto).
- Atraso de despacho configurável por loja.
- Filtro de motoboy por troco ou maquininha.
- Código de entrega digitado pela central.
