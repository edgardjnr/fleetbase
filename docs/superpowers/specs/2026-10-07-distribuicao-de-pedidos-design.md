# Distribuição de pedidos abertos: oferta um a um pelo tempo até o cliente

Data: 2026-10-07. Decisões do Edgard na conversa de desenho.

## 1. Problema

Hoje o pedido aberto (adhoc) vai por alarme a todos os motoboys online no raio da loja (`HandleOrderDispatched` do Fleet-Ops: `online = 1`, `status = available`, `distanceSphere` da coleta, raio `orders.adhoc_distance` → `fleetops.adhoc_distance` da empresa → 6000 m). O primeiro que aceita leva. Nada olha se o motoboy já carrega pedido: o `status = busy` do Fleet-Ops é manual e o `current_job_uuid` guarda um pedido só e nunca filtra o despacho. Motoboys sobrecarregados aceitam, e o cliente espera.

## 2. Decisões

| Pergunta | Decisão |
|---|---|
| Carga aceitável por motoboy | Depende do caso: posição, distância e atraso dos pedidos que já carrega. Não há teto fixo. |
| Quem decide | O servidor oferece a **um motoboy por vez**; ele ainda escolhe aceitar. |
| Critério | **Tempo estimado até o cliente**: posição → loja → cliente. Quem já carrega pedido entra com o que ainda tem para fazer. |
| Motoboy ocupado | **Encaixe no caminho quando compensa**, desde que nenhuma entrega já aceita atrase mais de **10 min**. Senão, "termina tudo e depois vai". |
| Oferta | **30 s** cada. Fila esgotada (ou **3 min** do despacho): abre a todos no raio, como hoje. |
| Preparo | Não entra. Só tempo de rota, com paradas fixas. |
| Candidatos | Os online **dentro do raio** da loja, como hoje, ordenados pelo tempo. |
| Recusa ou silêncio | Só passa ao próximo. Sem pausa nem penalidade. |
| Pico | **Uma oferta por vez por motoboy.** |
| App | "Novos pedidos" = a oferta atual dele (com cronômetro) + os abertos a todos. "Em andamento" como está. |
| Abordagem | Fila de ofertas com jobs atrasados e tempos pela matriz do OSRM. |

## 3. Fluxo de um pedido aberto

1. **Despacho.** O pedido é despachado como hoje (portal, iFood imediato ou agendado, central com "pedido aberto"). Em vez do alarme geral nasce uma **distribuição** na fase `ofertas`.
2. **Oferta.** O servidor calcula a fila de candidatos (seção 4) e oferece ao primeiro: push de alarme com `entregas_oferta=1` e `entregas_oferta_vence_em` (ISO), TTL de 30 s. O card do alarme já tem Aceitar e Recusar.
3. **Resposta.** Aceitar (`POST v1/orders/{id}/start` com `assign`) fecha a distribuição (`motivo = aceita`) e o pedido segue como hoje. Recusar (`POST v1/entregas/motoboy/pedidos/{id}/recusar`) marca a oferta `recusada` e avança na hora. Sem resposta em 30 s, o job `AvancarOferta` marca `vencida` e avança.
4. **A fila é recalculada a cada passo.** Saem: quem já respondeu (recusada ou vencida) **neste despacho**; quem tem oferta pendente de outro pedido; quem ficou offline ou sem GPS recente. A posição e a carga de cada um são as do momento.
5. **Abertura a todos** (`fase = aberta`): quando a fila acaba (`motivo = fila_esgotada`), quando passam 3 min do despacho (`prazo`), quando a central clica "Abrir a todos agora" (`aberta_pela_central`) ou quando não há candidato no despacho (abre na hora). O servidor manda o `OrderPing` comum a todos os online no raio, inclusive a quem recusou, e daí em diante vale o que já existe: reenvios com raio crescente (`ReenviarPedidosAbertos`, contados do `dispatched_at`) e o aviso "sem motoboy" à central aos 12 min.
6. **A central manda.** Atribuir pelo console, trocar pelo mapa do líder ou cancelar encerra a distribuição (`atribuida` / `cancelada`). Um despacho novo do mesmo pedido encerra a distribuição anterior e cria outra.

Compatível com o APK atual: a lista é filtrada no servidor, então o app antigo já vê só a oferta dele e os abertos a todos. O card antigo mostra 3 min, mas o servidor vence aos 30 s e o Aceitar atrasado recebe 409 com o texto. O Recusar antigo só cala no celular, e a oferta vence sozinha.

## 4. Candidatos e a conta do tempo

### Candidatos

Motoboys da empresa do pedido com `online = 1`, `status = available`, posição dentro do raio de pedido aberto da loja (`Order::getAdhocDistance()`, linha reta, a mesma consulta de hoje, agora filtrando `company_uuid`) e `drivers.updated_at` há menos de **5 min** (posição velha não serve para estimar). Tiram-se os excluídos da seção 3, item 4.

### Carga de cada candidato

Os pedidos em andamento dele, pela mesma consulta do capacete do mapa (`SituacaoDoMotoboy::pedidosEmAndamento`: `driver_assigned_uuid`, status fora de `StatusDoPedido::ENCERRADOS`, atualizado nas últimas 12 h). Cada pedido vira as paradas que faltam: coleta, se o status ainda é de coleta (fora de `enroute`, `picked_up`, `dropping_off`, `in_progress`), e entrega. A ordem das paradas segue a ordem de aceite dos pedidos (`started_at`, depois `dispatched_at`), coleta antes da entrega de cada um.

### Tempos

Uma chamada `table` ao OSRM (`OSRM_HOST`, o mesmo do km) com todos os pontos: posições dos candidatos, todas as paradas em andamento e a coleta e a entrega novas. Devolve a matriz de durações. Se o OSRM falhar ou não responder em 3 s: linha reta × 1,3 a 25 km/h, e toda estimativa dessa rodada sai marcada `aproximado`.

Paradas fixas: **3 min na loja** e **2 min no cliente**, em toda parada (sem tempo de preparo).

### Tempo do motoboy livre

`posição → loja → cliente`, com as paradas fixas.

### Tempo do motoboy ocupado (`Encaixe`)

- Base: a sequência S das paradas que faltam, a partir da posição atual. Calcula a hora de chegada em cada parada.
- Testa todas as inserções da coleta nova P e da entrega nova D em S, com P antes de D (inclusive P e D juntas no fim = "termina tudo e depois vai").
- Para cada inserção, o atraso de cada entrega já aceita = chegada nova − chegada da base. A inserção **cabe** se o maior atraso é ≤ 10 min.
- Vale a inserção que cabe com o menor tempo até D. Pôr P e D no fim sempre cabe (atraso 0).
- Resultado: tempo até D, `encaixe = true` quando P ou D entraram antes da última parada da base.

### Ordem da fila

Menor tempo até D. Empate: o livre primeiro; depois, o `public_id` (determinismo nos testes).

## 5. Servidor (`api/app`; nada em `packages/*/server` chega à produção)

### Tabelas (migrations em `api/database/migrations`, rodadas pelo `deploy.sh`)

- `entregas_distribuicoes`: uma por despacho. `id`, `pedido_uuid`, `company_uuid`, `despachada_em`, `fase` (`ofertas` | `aberta` | `encerrada`), `motivo` (nulo | `aceita` | `atribuida` | `cancelada` | `aberta_pela_central` | `fila_esgotada` | `prazo` | `sem_candidato`), `fila` (JSON da última fila calculada: `[{motoboy_uuid, nome, tempo_s, encaixe, aproximado}]`, para a central ver), `aberta_em`, `encerrada_em`, `created_at`, `updated_at`. Índice em (`pedido_uuid`, `fase`).
- `entregas_ofertas`: uma por oferta. `id`, `distribuicao_id`, `pedido_uuid`, `motoboy_uuid`, `posicao`, `tempo_estimado_s`, `encaixe`, `aproximado`, `oferecida_em`, `vence_em`, `resposta` (`pendente` | `aceita` | `recusada` | `vencida` | `cancelada`), `respondida_em`. Índices em (`pedido_uuid`, `resposta`) e (`motoboy_uuid`, `resposta`).

Datas em TIMESTAMP gravadas no fuso do app, como as tabelas do iFood (ver "Fuso" no CLAUDE.md).

### Código em `App\Support\Entregas\Distribuicao\`

- `Candidatos`: a consulta dos motoboys e as exclusões. Devolve motoboy, posição e paradas em andamento.
- `EstimadorDeTempo`: monta a chamada `table`, devolve a matriz; reserva em linha reta. Sem estado.
- `Encaixe`: funções puras da seção 4 (base, inserções, atraso, melhor tempo).
- `FilaDeCandidatos`: junta os três e ordena. Devolve a fila que vai ao JSON e ao próximo passo.
- `Distribuidor`: o ciclo. `iniciar(Order)`, `avancar(Distribuicao)`, `recusar(Oferta)`, `abrirATodos(Distribuicao, motivo)`, `encerrar(Distribuicao, motivo)`. Cada operação roda sob a `TravaDoPedido` do pedido (a mesma do aceite e do cancelamento) e relê a distribuição antes de agir. `avancar` com distribuição fora da fase `ofertas` não faz nada.
- `Distribuicao` e `Oferta`: models Eloquent das duas tabelas.
- Liga/desliga: `ENTREGAS_DISTRIBUICAO=1` no `stack.env` (`config('services.entregas.distribuicao')`). Desligada, o listener chama o original do Fleet-Ops e nada mais muda; distribuições em curso ficam onde estão e o aceite volta a ser livre.

### Gatilhos

- **Listener `App\Listeners\Entregas\DistribuirPedidoAberto`** no lugar do `HandleOrderDispatched`. O `EventServiceProvider` do Fleet-Ops registra três listeners no `OrderDispatched` (`HandleOrderDispatched`, `SendResourceLifecycleWebhook`, `NotifyOrderEvent`). O `AppServiceProvider` faz `Event::forget(OrderDispatched::class)` e registra de novo o webhook, o `NotifyOrderEvent` e o nosso. O nosso: pedido não aberto, integração desligada ou pedido sem coleta com coordenadas → chama o `HandleOrderDispatched` original; pedido aberto → `Distribuidor::iniciar`. **Ao atualizar o fleetops-api, confira a lista de listeners desse evento.**
- **Job `App\Jobs\Entregas\AvancarOferta`** (fila `default`, `delay` de 30 s, `afterCommit`, id da oferta no corpo): se a oferta ainda está `pendente`, marca `vencida` e chama `avancar`; senão sai. Jobs velhos são inofensivos.
- **Comando `entregas:distribuicao-varrer`** a cada minuto, em segundo plano, `withoutOverlapping`: reserva para Redis reiniciado (ver "Redis sem persistência" no CLAUDE.md). Oferta `pendente` vencida há mais de 20 s → vence e avança. Distribuição em `ofertas` há mais de 3 min → abre a todos. Distribuição em `ofertas` ou `aberta` cujo pedido já tem motoboy ou está encerrado → encerra.
- **`Order::updated`** (gancho já usado pelo iFood, `AppServiceProvider`): `driver_assigned_uuid` preenchido ou status encerrado → `encerrar` com `atribuida` ou `cancelada`. Vale nas duas fases: na `aberta`, o aceite de qualquer motoboy também encerra por aqui (`atribuida`; o motivo `aceita` é só da oferta). Nunca lança.
- **`ReenviarPedidosAbertos`** pula pedidos com distribuição em `ofertas`. Na fase `aberta` segue como hoje.

### Rotas e regras

- `POST v1/entregas/motoboy/pedidos/{id}/recusar` (`MotoboyController@recusar`, token de motoboy, limitador `entregas-motoboy-recusa` 30 por minuto): só a oferta `pendente` dele neste pedido; senão 409 `{"errors": ["Esta oferta não está mais com você."]}`. Responde 200 `{"resultado": "recusada"}`.
- **`BarrarAceiteDePedidoEncerrado`** ganha a regra, dentro da trava, depois das atuais: pedido com distribuição em `ofertas` só pode ser aceito por quem tem a oferta `pendente` dele. Oferta `vencida` dele ainda vale se nenhuma outra foi oferecida depois (o job venceu antes do toque chegar). Outro motoboy → 409 `{"error": "...", "errors": ["Este pedido está sendo oferecido a outro motoboy."]}` (os dois campos, como o 409 de hoje). Aceite válido marca a oferta `aceita` e encerra a distribuição na mesma trava.
- **Middleware `FiltrarPedidosAbertosDoMotoboy`** (grupo `fleetbase.api`, depois do `$next`, só em `Api\v1\OrderController@query` com `adhoc=1&unassigned=1` e token de motoboy): tira da resposta os pedidos com distribuição em `ofertas` que não são a oferta `pendente` dele e acrescenta `entregas_oferta: {"vence_em": ISO, "tempo_estimado_s": n}` ao que é. Nunca lança: em erro devolve a resposta original e registra `[entregas] distribuição: filtro da lista: <classe>`.
- `GET int/v1/entregas/pedidos/{id}/distribuicao` (só admin): fase, motivo, oferta atual (motoboy, oferecida_em, vence_em), fila do JSON e histórico das ofertas. `POST .../distribuicao/abrir` (só admin): `abrirATodos(..., aberta_pela_central)`; já aberta ou encerrada → 409.

### Push

A oferta usa o alarme de hoje (`CartaoDoAlarme` + `AvisosDoMotoboy`, canal `alarme_pedido`) com `entregas_oferta=1` e `entregas_oferta_vence_em`, `android.ttl` de 30 s. Classe `OfertaDePedido`, extensão do `OrderPing`, texto "Oferta para você: pedido #…". Ao abrir a todos, o `OrderPing` comum a todos os online no raio (consulta nossa, filtrada pela empresa).

### Logs

Prefixo `[entregas] distribuição:`, só ids e números: `iniciada`, `oferta enviada`, `recusada`, `vencida`, `aberta a todos (<motivo>)`, `encerrada (<motivo>)`, `OSRM indisponível; estimativa em linha reta` (warning), `filtro da lista`. Listener, job e aceite no `entregas_queue` e no `entregas_application`; o comando no `entregas_scheduler` (`/proc/1/fd/1`, como os do iFood).

## 6. App (`entregas-navigator`, APK novo)

Só o necessário à oferta. As outras melhorias do APK são de outro escopo.

- **Cartão do alarme** (`AlarmePedidoActivity`): com `entregas_oferta=1`, o cronômetro conta até `entregas_oferta_vence_em` (30 s) em vez de 3 min, e o título diz "Oferta para você". Recusar chama a rota de recusa (além de calar). Aceitar segue o `aceitarPeloAlarme` de hoje.
- **"Novos pedidos"** (`DriverOrderManagementScreen`): a lista continua vindo do `GET v1/orders?nearby…`. O pedido com `entregas_oferta` vai em destaque, no topo, com o cronômetro; abaixo, os abertos a todos. O card de aceitar já existe; ganha o botão Recusar quando é oferta. Recarrega ao vencer o cronômetro.
- O 409 do aceite aparece em toast, como hoje (o SDK lê `errors`).
- Funções puras em `src/utils/oferta.ts` (segundos restantes, ordem da lista, se é oferta), teste `scripts/testes/oferta.teste.ts`.

## 7. Console (`packages/fleetops`)

- Painel **"Distribuição"** no detalhe do pedido (`order/details/distribuicao`), só em pedido aberto com distribuição: fase, oferta atual com cronômetro, fila calculada (nome, tempo, "encaixe", "≈" quando aproximado), histórico (motoboy, resposta, hora) e o botão **"Abrir a todos agora"** (com confirmação). Recarrega com os eventos do pedido, como o painel iFood.
- Funções puras em `packages/fleetops/addon/utils/distribuicao.js`, teste `scripts/teste-portal/distribuicao.test.mjs`. Textos em `fleet-ops.ui.distribuicao.*` nos dois YAML.

## 8. Erros e casos de borda

- **OSRM fora:** estimativa em linha reta, marcada; a fila continua.
- **Push não entregue** (celular sem rede): a oferta vence em 30 s e passa adiante. O silêncio não pune.
- **Aceite depois do vencimento:** vale se ninguém foi oferecido depois; senão 409.
- **Dois pedidos para o mesmo melhor candidato:** o segundo pula quem tem oferta pendente e vai ao próximo. Quando o primeiro responde, ele volta a ser candidato na próxima recalculada do segundo (a carga já inclui o que aceitou).
- **Motoboy some do raio ou fica offline no meio:** sai na próxima recalculada; a oferta pendente dele vence normalmente.
- **Central atribui durante a oferta:** `Order::updated` encerra; a oferta pendente vira `cancelada`; o aceite pelo alarme recebe o 409 "passou para outro motoboy" que já existe.
- **Pedido cancelado durante a oferta:** encerra; o aceite recebe o 400 de pedido encerrado que já existe.
- **Integração desligada no meio:** distribuições em curso param de avançar (job e comando saem sem agir); o aceite volta a ser livre; os reenvios seguem.
- **Pedido iFood agendado:** despachado pelo `entregas:ifood-agendados` ou pelo `fleetops:dispatch-orders` → `OrderDispatched` → mesma distribuição. Com motoboy atribuído antes do horário o adhoc já é desligado e nada muda.

## 9. Testes

- php-wasm (`scripts/teste-php/`): `distribuicao-encaixe.php` (livre; ocupado indo à mesma loja; encaixe dentro de 10 min; encaixe fora de 10 min cai no "termina e vai"; matriz aproximada; empate), `distribuicao-fila.php` (candidatos, exclusões, ordem), `distribuicao-ciclo.php` (iniciar, avançar, recusar, vencer, prazo de 3 min, abrir pela central, encerrar por atribuição e cancelamento, desligada), `barrar-aceite.php` (regra da oferta) e `filtrar-pedidos-abertos.php`.
- Console: `scripts/teste-portal/distribuicao.test.mjs`. App: `scripts/testes/oferta.teste.ts`.
- `scripts/teste-php/sintaxe.mjs` e `node scripts/i18n-check.cjs …` antes do commit.

## 10. Implantação

1. API: `bash deploy/atualizar.sh api` (migrations, listener, job, comando, rotas, middleware). Com `ENTREGAS_DISTRIBUICAO` vazio nada muda.
2. `ENTREGAS_DISTRIBUICAO=1` no `stack.env` e Update the stack ("Re-pull image" desligado). Funciona com o APK atual.
3. Console: `bash deploy/atualizar.sh console` (painel).
4. APK novo (cartão com 30 s e Recusar pelo servidor).

A conferir no primeiro teste real: o serviço `table` responde no `OSRM_HOST` (osrm-routed serve `route` e `table` juntos, mas o deploy pode limitar); o push com TTL de 30 s chega a tempo em celular com economia de bateria; o tempo da chamada `table` com todos os motoboys online no pico.

## 11. Riscos aceitos

- A sequência das paradas do motoboy ocupado é suposta pela ordem de aceite. Se ele entrega em outra ordem, o encaixe erra.
- Motoboy com GPS parado há mais de 5 min não recebe oferta; recebe só na fase aberta.
- Um motoboy mudo custa 30 s por pedido. Decisão: sem pausa.
- A fase aberta repete o comportamento de hoje: o sobrecarregado pode aceitar. O prazo de 3 min limita a espera, não o aceite.
