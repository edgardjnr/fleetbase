# Distribuição de pedidos abertos (oferta um a um)

Parte do `CLAUDE.md` da raiz (índice em "Onde está cada assunto"). Responda e documente em português do Brasil.

Decisão de 2026-10-07. Desenho: `docs/superpowers/specs/2026-10-07-distribuicao-de-pedidos-design.md`; plano da API: `docs/superpowers/plans/2026-10-07-distribuicao-de-pedidos-api.md` (executado com ajustes de revisão: **o código é a referência**). O painel do console e o APK (cartão de 30 s, Recusar pelo servidor) vêm no plano `2026-10-07-distribuicao-de-pedidos-console-e-app.md`, também executado com ajustes: valem o código e as seções "Painel do console" e "APK" abaixo.

- **O que muda:** o pedido aberto (adhoc) não vai mais por alarme a todos os motoboys do raio. Nasce uma **distribuição** (`entregas_distribuicoes`), e o servidor oferece o pedido a **um motoboy por vez, por 30 s** (`entregas_ofertas`), na ordem do **menor tempo estimado até o cliente**.
- **Fase aberta:** a fila acaba (`fila_esgotada`), não há candidato no despacho (`sem_candidato`), passam **3 min do despacho** (`prazo`, mesmo com uma oferta pendente, que vira `cancelada`), a central abre (`aberta_pela_central`) ou o ciclo falha (`falha`). Na aberta, o `OrderPing` comum vai a todos no raio, e daí em diante valem os reenvios e o aviso "sem motoboy" de sempre.
- **Encerrada:** `aceita`, `atribuida` (ganhou motoboy por outro caminho), `cancelada` (pedido encerrado ou apagado) ou `redespachada` (um despacho novo do mesmo pedido cria outra distribuição).
  - Só o aceite **da oferta** (fase `ofertas`) grava `aceita`. O aceite na fase `aberta` é livre e encerra como `atribuida` (pelo `ObservadorDaDistribuicao`, quando o pedido ganha o motoboy).
  - Pedido apagado: o ciclo (`avancar`, `vencer`, `recusar`) e o `abrirATodos` encerram como `cancelada`. Sem isso, a varredura tentaria abrir pelo prazo para sempre.

## Como ligar

- `ENTREGAS_DISTRIBUICAO=1` no `stack.env` (`config('services.entregas.distribuicao')`, `Distribuicao::ligada()`). Vazia, tudo volta ao alarme geral do Fleet-Ops, sem deploy.
- `ENTREGAS_DISTRIBUICAO_OSRM` (`services.entregas.distribuicao_osrm`) fica **desligada por padrão**, e a fila usa linha reta × 1,3 a 25 km/h.
  - A produção usa o OSRM público (`router.project-osrm.org`): servidor de demonstração, com limite de uso, que receberia as posições dos motoboys.
  - **Ligue só com um OSRM próprio.**
- `ENTREGAS_DISTRIBUICAO_RAIO_M` (`services.entregas.distribuicao_raio_m`, `Distribuicao::raioBase`): o **raio base R** em metros, que vale para tudo na distribuição (candidatos, rodadas R/1,5R/2R, lista aberta até 2R, `raio_m` do painel). Vazia, zero ou inválida: o `Order::getAdhocDistance()` do pedido (6000). **Produção: 8000 desde 2026-10-24** (decisão do Edgard): às 19:29 o Roger, livre a ~7,3 km, ficou fora da rodada 1 de 6 km, e o pedido foi ao Davi, que já levava dois (~48 min até o cliente). O alarme geral de reserva do listener (`DistribuirPedidoAberto`, falha ao iniciar) e o `nearby` do Fleet-Ops na lista continuam com o raio do pedido/empresa.
- As três estão no `x-api-env` do `deploy/docker-stack.yml`. **Cole o `docker-stack.yml` novo no Portainer** (Stacks → entregas → Editor → Update the stack, "Re-pull image" desligado) junto com a variável. Sem isso, ela não chega aos containers.
- **Desligar no meio:** as distribuições em curso param de avançar, o aceite volta a ser livre e os reenvios seguem.
- **Religar depois de dias desligada:** a varredura abre a todos as distribuições que ficaram em `ofertas` (alarme geral no raio), se o pedido ainda estiver sem motoboy e não encerrado.

## Código

Em `api/app/Support/Entregas/Distribuicao/`:

- `Distribuicao`: constantes (prazos, fases, motivos, respostas), `ligada()` e `osrmLigado()`;
- `Distribuicoes`: leitura e gravação das duas tabelas com `DB::table` (sem models), datas no fuso do app;
- `Pontos`: `[lat, lng]` de um `Point` e a linha reta (Haversine);
- `Candidatos`, `EstimadorDeTempo`, `Encaixe` (funções puras) e `FilaDeCandidatos`: a fila;
- `Distribuidor`: o ciclo. `iniciar`, `avancar`, `vencer`, `recusar` e `abrirATodos` rodam sob a `TravaDoPedido`; `encerrar`, `encerrarDistribuicao` e `registrarAceite`, sem a trava;
- `TrocaDoListenerDoDespacho`: a troca do listener.

Fora dessa pasta:

- **Listener `App\Listeners\Entregas\DistribuirPedidoAberto`** (estende o `HandleOrderDispatched`, fila `default`).
  - Pedido aberto sem motoboy, com a distribuição ligada: a mesma atividade de despacho do original e o `Distribuidor::iniciar`.
  - O resto (não aberto, desligada, aberto que já tem motoboy): o original.
  - Falha ao iniciar: alarme geral do Fleet-Ops.
- **Troca do listener:** o `AppServiceProvider::distribuirPedidosAbertos` chama o `TrocaDoListenerDoDespacho::aplicar` no `booted()`. Ele lê a lista crua do `OrderDispatched` (`getRawListeners`), esquece o evento, registra o nosso primeiro e depois **os outros, na ordem em que estavam** (o webhook e o `NotifyOrderEvent`; o `HandleOrderDispatched` do Storefront não sobe mais desde 2026-10-25, com o pacote fora do package:discover).
- **`ObservadorDaDistribuicao`** (`Order::updated`): ganhou motoboy → `atribuida`; status encerrado → `cancelada`; deixou de ser aberto (`adhoc` desligado pela central) sem motoboy → `cancelada`. Nunca lança. No aceite, o `registrarAceite` do middleware sobrescreve com `aceita`.
- **Job `App\Jobs\Entregas\AvancarOferta`** (fila `default`, atraso de 30 s, `afterCommit`): vence a oferta pendente. Com a trava ocupada, volta à fila em 2 s, até 3 tentativas.
- **Comando `entregas:distribuicao-varrer`** (`VarrerDistribuicoes`, a cada minuto, `withoutOverlapping(5)`, em segundo plano): reserva do job para o Redis reiniciado.
  - Vence a pendente vencida há mais de 20 s.
  - Encerra a distribuição cujo pedido já tem motoboy, está encerrado, sumiu ou deixou de ser aberto sem motoboy (`Distribuidor::motivoParaEncerrar`, a mesma regra do ciclo: o pedido que deixou de ser aberto não recebe mais oferta, nos dois ciclos).
  - Abre pelo prazo as que estão em `ofertas` há mais de 3 min.
  - O encerramento só olha as despachadas nas últimas 24 h (as mais antigas, o observador do Order encerra). Vencer e abrir pelo prazo não têm esse limite.

## A fila

- **Candidatos** (`Candidatos::noRaio`): motoboys da empresa do pedido, online, `status=available`, com posição válida no raio base da coleta (`Distribuicao::raioBase`: `ENTREGAS_DISTRIBUICAO_RAIO_M` ou o `Order::getAdhocDistance`; a mesma consulta do Fleet-Ops, agora filtrada pela empresa) e com GPS (`drivers.updated_at`) de menos de **30 min**.
  - A janela é de 30 min porque o APK anterior para de mandar posição quando o motoboy está parado.
  - O APK novo manda a posição a cada 1 min parado (batimento; ver "APK"), e aí a janela pode cair, mas deve ficar **bem acima de 5 min**: o batimento reaproveita uma posição em cache de até 5 min.
- **Carga:** os pedidos em andamento dele (a regra do capacete, `SituacaoDoMotoboy`), na ordem de aceite (`started_at`, depois `dispatched_at`): coleta, se ainda não pegou, e entrega.
- **Tempo** (`EstimadorDeTempo`):
  - com o OSRM ligado, uma chamada `table` com todos os pontos (3 s de timeout);
  - com ele desligado, falhando ou com mais de 100 pontos (o `max-table-size` do OSRM), a matriz inteira sai em linha reta, marcada `aproximado`.
- **Encaixe:** testa onde a coleta e a entrega novas entram na sequência dele. Vale a mais rápida que não atrase nenhuma entrega já aceita mais de 10 min (`ATRASO_MAXIMO_S`; 5 min em rodadas, ver "Rodadas"). Paradas fixas: 3 min na loja, 2 min no cliente. Sem tempo de preparo. **Duas coletas seguidas no mesmo ponto (a mesma loja, rota de 0 s) são uma parada só** (`Encaixe::chegadas`, 2026-10-24): antes somava 3 min por pedido, e o pedido 9888 (Terraço, 20:12) não foi ao Roger, que pegaria os dois na loja e entregaria o novo no caminho em ~20 min — a parada dobrada atrasava o cliente dele em ~6,7 min (acima dos 5 min das rodadas), sobrou "entrega o dele e depois o novo" (~38 min), empatou com o Davi (38 min) e o desempate por corridas deu ao Davi. Lojas diferentes continuam com uma parada cada.
- **Ordem** (`FilaDeCandidatos::ordenar`; decisão do Edgard de 2026-10-19, spec `docs/superpowers/specs/2026-10-19-desempate-por-corridas-design.md`):
  - **Desempate por corridas do dia:** quem entregaria o cliente em até **3 min** (`Distribuicao::DESEMPATE_CORRIDAS_S` = 180, inclusive) do mais rápido forma um grupo ordenado por **menos corridas hoje** (empate: menor tempo, o livre, o `public_id`). O resto vem depois, pelo menor tempo, o livre e o `public_id`. A fila é refeita a cada passo, então o grupo sai do mais rápido que sobrou.
  - **Corridas hoje** (`Candidatos::corridasHoje`, uma consulta para todos os candidatos): pedidos da empresa atribuídos a ele (`driver_assigned_uuid`), sem cancelados (`StatusDoPedido::CANCELADOS`), `expired` e apagados, com o aceite (`started_at`; sem ele, `dispatched_at`) desde as 00:00 de Brasília. Em andamento e concluídos contam. Falha: todos com 0 (fila só por tempo) e o warning `não deu para contar as corridas de hoje; fila só por tempo`.
  - Cada linha da fila gravada traz `corridas_hoje` e `desempate` (passou à frente de alguém mais rápido).
  - Vale com e sem rodadas; em rodadas, o grupo se forma entre os candidatos da rodada. O cliente pode esperar até 3 min a mais.
- **A fila é recalculada a cada passo.**
  - Saem quem já recusou ou deixou vencer neste despacho e quem tem oferta pendente de outro pedido (uma oferta por vez por motoboy).
  - Recusa e silêncio só passam ao próximo.
  - A última fila não vazia fica no JSON `fila` da distribuição, para o painel.

## Aceite, recusa, lista e push

- **Aceite** (`BarrarAceiteDePedidoEncerrado`, dentro da trava). Na fase `ofertas`:
  - só aceita quem tem a oferta pendente, ou a dele vencida enquanto ninguém foi oferecido depois (`Distribuicoes::ofertaParaAceite`);
  - quem aceita é o motoboy da sessão ou, sem ele, o `assign`. Um `assign` diferente do motoboy da sessão é barrado;
  - os outros levam 409 "Este pedido está sendo oferecido a outro motoboy." (log `[entregas] aceite do motoboy barrado: pedido oferecido a outro motoboy`);
  - aceite 2xx → `registrarAceite`. Uma falha ali nunca derruba o aceite (a varredura encerra).
  - Na fase `aberta`, o aceite é livre.
- **Recusa:** `POST v1/entregas/motoboy/pedidos/{id}/recusar` (`MotoboyController@recusar`, token de motoboy, limitador `entregas-motoboy-recusa`, 30 por minuto).
  - 200 `{"resultado": "recusada"}`;
  - sem oferta pendente dele: 409 "Esta oferta não está mais com você.";
  - trava ocupada: 503.
- **Lista do app** (`FiltrarPedidosAbertosDoMotoboy`, grupo `fleetbase.api`, depois do `$next`, só no `Api\v1\OrderController@query` com `adhoc=1&unassigned=1`):
  - tira os pedidos em oferta a outro motoboy e põe `entregas_oferta: {vence_em, tempo_estimado_s, segundos_restantes}` na oferta dele (`segundos_restantes` = `max(0, vence_em − agora)` calculado no servidor: o app conta a partir do recebimento e não depende do relógio do celular, que adiantado ~30 s via toda oferta já vencida);
  - o `vence_em` sai em ISO com `-03:00`;
  - nunca lança. Funciona com o APK atual, que só lista.
- **Push** (`OfertaDePedido`, extensão do `OrderPing`; `order_ping` no push, `order.ping` no socket):
  - título "Oferta para você";
  - dados `entregas_oferta=1`, `entregas_oferta_vence_em` e `entregas_oferta_segundos` (segundos que faltam, calculados no envio, pelo mesmo motivo do relógio do celular), também no push original de reserva;
  - `android.ttl` = o que falta até vencer (1 a 30 s), pelo `AvisosDoMotoboy`.
- **Console** (só admin, `DistribuicaoController`):
  - `GET int/v1/entregas/pedidos/{id}/distribuicao`: fase, motivo, oferta atual, fila e histórico; `{"distribuicao": false}` se nunca houve;
  - `POST .../distribuicao/abrir` ("Abrir a todos agora"): 409 se não está em oferta, 503 com a trava ocupada.
- **Reenvio:** o `ReenviarPedidosAbertos` não reenvia aos motoboys enquanto o pedido está na fase `ofertas`. O aviso "sem motoboy" à central não muda.

## Painel do console

- **Onde:** Fleet-Ops → detalhe do pedido, painel "Distribuição" ao lado do painel iFood (`packages/fleetops/addon/components/order/details/distribuicao.*`; funções puras em `utils/distribuicao.js`; textos em `fleet-ops.ui.distribuicao.*`).
- **Só admin:** para os outros, o painel nem carrega (a rota do servidor é só de admin).
- **Quando aparece:** sempre que o pedido teve distribuição (`distribuicao: true`), inclusive encerrada, pelo histórico. **Não depende do `adhoc`**: a atribuição da central e a troca pelo líder desligam o adhoc, e o histórico continua ali. No erro de leitura, aparece só em pedido aberto.
- **Conteúdo:** fase e motivo, oferta atual com o cronômetro, a fila calculada (**só em ofertas**: tempo até o cliente, "no caminho" (encaixe), "já leva pedido", "≈" na linha reta, "N corridas hoje" e "desempate", estes dois só na fila gravada depois de 2026-10-19; função pura `corridasHoje`) e o histórico das ofertas.
- **Com rodadas** (`rodadas: true` na resposta, `ENTREGAS_DISTRIBUICAO_RODADAS=1`):
  - em ofertas, a linha "Volta N · rodada M · até X km" e, embaixo, "Lista aberta desde HH:MM" (hora do navegador, 24 h) ou "Lista só para quem recebe a oferta";
  - a oferta conta os 30 s pelo `vence_em`;
  - a fila mostra o "no caminho" como sem rodadas (encaixe com até 5 min de atraso para quem já espera);
  - o histórico vem agrupado por volta ("Volta N"), com "rodada M · até X km" em cada linha e as respostas novas "dispensou pela lista" e "aceitou pela lista". As linhas sem oferta (dispensa, aceite pela lista) não mostram número;
  - funções puras: `emRodadas`, `inteiroPositivo`, `kmTexto`, `horaCurta`, `agruparPorVolta` e `textosDoBotao`;
  - sem `rodadas` (chave desligada ou API antiga), o painel é o de antes.
- **Releitura:** em ofertas, a cada 5 s e uma vez quando a oferta vence (o cronômetro chega a 0). Não relê com a aba oculta nem com a distribuição desligada no servidor (`ligada: false` na resposta, `ENTREGAS_DISTRIBUICAO` vazia). Recarrega também quando o pedido, o status ou o `updated_at` mudam.
- **O botão** (`POST .../distribuicao/abrir`, com confirmação): só em ofertas, com a distribuição ligada e o pedido não encerrado (`podeAbrir`).
  - Sem rodadas: **"Abrir a todos agora"** (alarme a todos no raio).
  - Com rodadas: **"Mostrar a todos agora"**. Grava a `lista_aberta_em`: o pedido aparece na lista de todos até 2R, sem alarme, e as ofertas continuam. Só aparece com a lista ainda fechada. Depois do sucesso, o painel volta a reler, porque a fase continua em ofertas.
  - O 409 (a lista já aberta, ou a distribuição saiu de ofertas) mostra a mensagem do servidor e relê o painel.
- O cronômetro compara o relógio do PC com o `vence_em` do servidor: é só exibição (quem vence a oferta é o servidor).

## APK

Ramos `distribuicao-de-pedidos` e `distribuicao-rodadas` do `entregas-navigator` (APK do push na `main` depois do merge; as rodadas pedem o APK 30). Funções puras em `src/utils/oferta.ts`.

- **Card de aceitar** (`AdhocOrderCard`, aba Pedidos): a oferta vem no topo de "Novos pedidos" (`comOfertaPrimeiro`), com "Oferta para você · fecha em N s".
  - O cronômetro conta pelos `segundos_restantes` da lista, a partir do recebimento: **não depende do relógio do celular**.
  - Aceitar e Recusar vão direto, sem confirmação (o tempo corre). Recusar chama `POST .../recusar`. O 409 some sem aviso; rede e 503 avisam.
  - A recusa tira o pedido só da lista local, **sem pôr em `dismissedOrders`**: quando o pedido abre a todos, ele volta à lista e quem recusou também pode aceitar.
  - Vencida (inclusive a que já chega vencida): botões desabilitados e a lista recarrega.
  - Os 50 s das rodadas (eram 20 s até 2026-10-08 e 30 s até 2026-10-24) vêm do servidor (`segundos_restantes`, `entregas_oferta_segundos`): o app não tem prazo fixo.
- **Dispensar de pedido em distribuição** (rodadas): vale para todo pedido que a lista marca com `entregas_distribuicao: true`; na tela do pedido, conta também a oferta vinda do push ou do card (`ehDistribuido`).
  - No card e na tela do pedido, a confirmação de sempre, com texto próprio, e depois `POST .../recusar` (200 `recusada` ou `dispensada`).
  - Sucesso (2xx): o pedido sai da lista local, **sem ir para os dispensados**, e a lista recarrega (o servidor o devolve na volta seguinte).
  - 409: some sem aviso e vai para os dispensados locais, para não voltar a cada recarga. A oferta dele continua aparecendo, mesmo dispensada localmente: o `secoesDaLista` não esconde pedido com `entregas_oferta`.
  - Rede e 503: avisa e o pedido fica.
  - O desfecho sai do `desfechoDaDispensa`. A recusa local de 2 h do cartão nativo não vale para esses pedidos.
- **Tela do pedido** (`OrderScreen`, aberta pelo `OrderModal`): com oferta, o cronômetro, Aceitar direto e Recusar (avisa o servidor e fecha; erro de rede ou 503 fica na tela para tentar de novo).
  - O prazo vem do `ofertaPrazo` dos params (push pelo `DriverLayout`, ou o card) ou da `entregas_oferta` do pedido, com teto de 180 s.
  - No vencimento, o alarme da tela para e a lista recarrega. A tela volta ao pedido aberto comum (Aceitar com confirmação, Dispensar), e o servidor decide (409 se está com outro; aceita se abriu a todos).
- **Cartão nativo do alarme** (`AlarmeDePedido.kt`, `AlarmePedidoActivity.kt`): título "Oferta para você" e o prazo pelos `entregas_oferta_segundos` do push (contados no servidor; de reserva, `entregas_oferta_vence_em`), gravado como fim em `elapsedRealtime` (`entregas_oferta_fim_elapsed`) para a contagem não reiniciar ao reabrir o cartão. A notificação some no vencimento (`setTimeoutAfter`).
  - **Recusar** abre o app com `entregas_recusar=1` (com o celular bloqueado, pede o desbloqueio, como o Aceitar), e o `DriverLayout` chama a rota de recusa (qualquer 2xx, `recusada` ou `dispensada`, vale como recusa). O Kotlin não tem o token nem cliente HTTP. Sem desbloquear, a oferta vence sozinha no prazo dela (30 s, com ou sem rodadas).
  - Com rodadas, o Kotlin não mudou: só chegam pushes de oferta, porque não sai o `OrderPing` a todos. Caso de borda: um pedido recusado localmente no cartão de pedido aberto comum (ciclo antigo ou motivo `falha`) não toca por 2 h, nem como oferta.
  - Na oferta, Recusar **não** grava a recusa local de 2 h (a do pedido aberto comum): quando a fila esgota, o servidor abre o pedido a todos, e este celular tem de tocar.
  - Os segundos que faltam são regravados nos dados entregues ao app (`MainActivity`, ao abrir pelo cartão, pela notificação ou pelo pendente), porque os do envio já estão velhos.
- **Batimento do GPS** (`LocationContext`, `heartbeatInterval: 60`): com o plugin parado, a cada 1 min pega uma posição (`maximumAge` de 5 min, reaproveita a do cache) e a envia pelo `/track`. Com o app fechado roda em headless (`index.native.tsx`). Sem isso, o motoboy livre parado perto da loja saía da fila depois de 30 min.
  - Custo: um POST por minuto parado e uma linha de posição por minuto no servidor.
  - **A conferir no teste real:** a frequência do `/track` com o motoboy parado.
- **APK anterior com a chave ligada:**
  - o cartão conta 3 min e segue tocando depois que a oferta passou ao próximo, então **vários celulares podem tocar** ao mesmo tempo;
  - o Aceitar atrasado leva 409 "Este pedido está sendo oferecido a outro motoboy." (aceita só se o pedido já abriu a todos);
  - o Recusar do cartão só cala e grava a recusa local de 2 h: o servidor não fica sabendo (a oferta vence em 30 s), e o celular não toca quando o pedido abre a todos;
  - por isso: **ligue primeiro num teste controlado** (loja de teste, motoboys avisados) e de vez só com o APK novo em todos os celulares.
- **APK anterior com as rodadas ligadas:** o cartão conta 3 min e segue tocando depois que a oferta de 30 s passou ao próximo (vários celulares tocam juntos). O Dispensar só esconde o pedido no celular: o servidor não fica sabendo e pode oferecê-lo de novo na mesma volta. Por isso, a chave das rodadas só liga com o APK 30 em todos.
- **Tela Sobre:** o parágrafo `ofertaDesempate` explica o desempate por corridas do dia (ramo `desempate-corridas` do app, 2026-10-19). Se a regra mudar, revise o texto.
- Testes: `scripts/testes/oferta.teste.ts` e `lista-de-pedidos.teste.ts` (`node --experimental-strip-types --test ...`). O Kotlin só compila no GitHub Actions.

## Logs

Prefixo `[entregas] distribuição:`, só ids e números:

- ciclo: `iniciada`, `oferta enviada`, `oferta recusada`, `oferta vencida`, `aberta a todos (<motivo>)`, `encerrada (<motivo>)`;
- falhas: `falha ao iniciar a distribuição; alarme geral`, `falha no ciclo; aberta a todos`, `push da oferta falhou`, `job da oferta não entrou na fila`, `alarme geral falhou para um motoboy`, `falha ao registrar o aceite`, `falha ao encerrar a distribuição do pedido`, `varredura não conseguiu …`, `trava ocupada ao vencer a oferta; tentando de novo`, `filtro da lista: <classe>`, `não deu para contar as corridas de hoje; fila só por tempo`;
- OSRM: `OSRM indisponível; estimativa em linha reta` e `pontos demais para a matriz do OSRM; estimativa em linha reta`;
- boot: `listener do Fleet-Ops não encontrado no OrderDispatched` (o fleetops-api mudou).

Onde ver:

- listener e job: `docker service logs entregas_queue 2>&1 | grep 'distribuição'`;
- aceite, recusa, lista e painel: `entregas_application`;
- varredura: `entregas_scheduler`.

## Armadilhas

- **A `TravaDoPedido` não é reentrante.** Nada que rode dentro do aceite (middleware) ou da `TrocaDoMotoboy` pode chamar `iniciar`, `avancar`, `vencer`, `recusar` ou `abrirATodos`. Por isso o `encerrar` e o `registrarAceite` não tomam a trava.
- **Worker da fila `default`:** com a trava do pedido ocupada, o `AvancarOferta` espera até 10 s por tentativa no worker `queue`, o único da `default`, que também entrega os pushes das ofertas. Se isso atrasar as ofertas, crie um worker extra para a `default`.
- **Trava presa da varredura** (`withoutOverlapping(5)`): `docker exec $(docker ps -q -f name=entregas_scheduler) php artisan schedule:clear-cache`.
- **Lista "Novos pedidos" (`nearby`):** usa só o raio da empresa (`fleetops.adhoc_distance`). Com um `orders.adhoc_distance` maior (só pela API v1 ou pelo formulário da central), o motoboy recebe a oferta pelo alarme, mas ela não aparece na lista.
- **Alarme duplo na abertura pelo prazo.**
  - O intervalo do reenvio conta do `dispatched_at`. Assim, o primeiro `LembretePedidoAberto` (1,5R) sai ~30 a 90 s depois do alarme geral da abertura, e o motoboy dentro de R pode receber dois alarmes seguidos.
  - Aceito por ora. Se incomodar, conte o reenvio a partir da abertura da distribuição.
- Pedido sem coleta ou entrega com coordenadas: fila vazia, abre a todos na hora (`sem_candidato`).
- O `OrderResource` da API v1 traz `id` = `public_id`. O filtro da lista converte para uuid pela tabela `orders`, filtrando a empresa.

## Riscos aceitos

- Dois pedidos de lojas diferentes distribuídos no mesmo instante podem ser oferecidos ao mesmo motoboy: a trava é por pedido, e a checagem de oferta pendente de outro pedido deixa uma janela pequena.
- Motivo `falha`: quando o ciclo lança exceção, a distribuição abre a todos e sai o alarme geral (pelo listener, no despacho; pelo `Distribuidor`, depois de uma resposta).
- O painel mostra a última fila não vazia calculada, que pode não ser a do momento.
- A sequência das paradas do motoboy ocupado é suposta pela ordem de aceite. Se ele entrega em outra ordem, o encaixe erra.
- Um motoboy mudo custa 30 s por pedido (sem pausa nem penalidade). Na fase aberta, o sobrecarregado ainda pode aceitar.

## Ao atualizar o fleetops-api, confira

- a lista de listeners do `OrderDispatched` no `EventServiceProvider` do Fleet-Ops (o do Storefront não sobe mais);
- o `handle()` do `HandleOrderDispatched` (a parte repetida no `DistribuirPedidoAberto`) e os métodos protegidos `doesntHaveDispatchActivity`, `getDispatchActivity`, `nearbyAvailableDrivers` e `notifyAdhocDriver` (o `distribuicao-ciclo.php` confere na cópia de `packages/fleetops`);
- o `Api\v1\OrderController@query` (filtros `adhoc`, `unassigned` e `nearby`) e o formato do `OrderResource`;
- o `OrderPing` (construtor, `title`, `message` e `data`), estendido pelo `OfertaDePedido`;
- as relações que o `FiltrarPedidosAbertosDoMotoboy` carrega (`RELACOES_DO_FILTRO` e `RELACOES_DO_RECURSO`): os nomes precisam existir no `Order` (o `filtrar-pedidos-abertos.php` os confere, de forma estática, na cópia de `packages/fleetops`).

## Testes

- php-wasm: `scripts/teste-php/distribuicao-encaixe.php`, `distribuicao-fila.php`, `distribuicao-ciclo.php`, `distribuicao-rotas.php`, `filtrar-pedidos-abertos.php`, `barrar-aceite.php`, `avisos-push.php` e `reenvio.php`.
- `scripts/teste-php/imports-dos-providers.php` (estático): pega `use` faltando nos providers e nos arquivos novos. Um `use` faltando no `RouteServiceProvider` derruba a API v1 inteira, e o `php -l` não pega.
- Console: `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/distribuicao.test.mjs`.
- App: ver "APK".

## Implantação

1. `bash deploy/atualizar.sh api`: migration `2026_10_07_100000_create_entregas_distribuicao_table`, listener, job, comando e rotas. Com a variável vazia, nada muda.
   - Confira a troca do listener dentro do container da API: `php artisan event:list --event="Fleetbase\FleetOps\Events\OrderDispatched"` deve listar o `DistribuirPedidoAberto`, o webhook e o `NotifyOrderEvent`, e **nenhum** `HandleOrderDispatched` (nem o do Fleet-Ops, nem o do Storefront).
   - E que o log `listener do Fleet-Ops não encontrado` não aparece (`docker service logs entregas_application`).
2. `bash deploy/atualizar.sh console`: o painel já está neste ramo. Antes da API, o painel daria 404.
3. `docker-stack.yml` novo colado no Portainer com as variáveis (`ENTREGAS_DISTRIBUICAO=1` no `stack.env`) → Update the stack ("Re-pull image" desligado).
4. Teste controlado, ainda com o APK anterior nos celulares (ver "APK" → "APK anterior com a chave ligada"): loja de teste, motoboys avisados. Se não for seguir, `ENTREGAS_DISTRIBUICAO=` vazio e Update the stack.
5. APK novo em todos os celulares.
6. Ligar de vez.

- **A conferir no primeiro teste real:**
  - a sequência no log: `iniciada` → `oferta enviada` → recusa ou vencida → `oferta enviada` → `aberta a todos` ou `encerrada (aceita)`;
  - o motoboy sem oferta não vê o pedido na lista e leva 409 ao aceitar pelo alarme velho;
  - se o `@fleetbase/sdk` do app mantém o atributo extra `entregas_oferta` no recurso Order e no `serializeCollection`;
  - se o push com TTL de 30 s chega em celular com economia de bateria;
  - com OSRM próprio: se o `table` responde no `OSRM_HOST` e quanto a chamada demora no pico.
- **Se algo der errado:** `ENTREGAS_DISTRIBUICAO=` vazio e Update the stack.

## Rodadas (`ENTREGAS_DISTRIBUICAO_RODADAS`)

Decisão de 2026-10-07, depois do primeiro teste real. Desenho: `docs/superpowers/specs/2026-10-07-distribuicao-em-rodadas-design.md`; planos: `docs/superpowers/plans/2026-10-07-distribuicao-em-rodadas-api.md` e `-console-e-app.md` (**o código é a referência**). O painel e o APK 30 estão nas subseções "Painel do console" e "APK".

- **Chave:** `ENTREGAS_DISTRIBUICAO_RODADAS=1` (`services.entregas.distribuicao_rodadas`, `Distribuicao::emRodadas()`), só vale com `ENTREGAS_DISTRIBUICAO=1`. Vazia: o ciclo das seções acima (30 s, encaixe de até 10 min, 3 min, abre a todos), sem mudança. **Ligue só com o APK 30 em todos os celulares.** Está no `x-api-env`: cole o `docker-stack.yml` novo no Portainer.
- **O ciclo** (`Distribuidor::avancarEmRodadas`, sob a `TravaDoPedido`): oferta de **50 s** (era 20 s; 30 s em 2026-10-08; 50 s por decisão do Edgard de 2026-10-24, depois de o Roger, parado na loja, deixar vencer a oferta do 1948, que foi ao Davi a 38 min; `SEGUNDOS_DA_OFERTA_EM_RODADAS`), um motoboy por vez, e **nunca abre a todos** (sem prazo de 3 min e sem `OrderPing` geral). Só a `falha` ainda abre com o alarme geral, como rede de segurança.
  - Rodadas: 1 = até R, 2 = até 1,5R, 3 = até 2R (R = `Distribuicao::raioBase`, 8 km em produção: 8, 12 e 16 km; `Distribuicao::raioDaRodada`).
  - Candidatos da rodada: disponíveis até o raio, GPS de menos de 30 min, **fora** quem tem qualquer linha nesta volta (oferta de qualquer resposta ou dispensa) e quem tem oferta pendente de outro pedido. Recalculados a cada oferta.
  - Rodada vazia passa à seguinte. Sair da rodada 1 da volta 1 grava `lista_aberta_em`.
  - Depois da rodada 3: volta nova (rodada 1, todos de novo, inclusive quem recusou) só 1 min depois do início da volta (`volta_iniciada_em`; nulo nas distribuições antigas = `despachada_em`). Antes disso, o job `AvancarDistribuicao` (fila `default`) volta na hora certa, e a varredura é a reserva. No máximo uma volta nova por passo: volta inteira sem ninguém = "aguardando motoboy", e a varredura tenta a cada minuto.
  - **A fila usa o encaixe com até 5 min de atraso** para quem já espera (`Encaixe::calcular` com `Distribuicao::ATRASO_MAXIMO_EM_RODADAS_S` = 300, no `FilaDeCandidatos::para`; decisão do Edgard de 2026-10-07, depois do primeiro teste real). Testa todas as posições da coleta e da entrega novas na sequência do motoboy (coleta antes da entrega) e vale a que entrega o cliente novo mais cedo dentro do limite; se nenhuma couber, "termina tudo e depois vai" (sempre cabe). Na prática: **coleta junto** o pedido da mesma loja que ele ainda não coletou (o cliente antigo espera só a parada de 3 min) e passa numa **loja no caminho** da entrega que ele leva. O `encaixe` marca quando o pedido novo entrou antes do fim.
    - Antes era "termina tudo e depois vai" sempre (`Encaixe::noFim`, removido): no teste real, Edgard a 2,07 km, com um pedido da mesma loja aceito e ainda não coletado, teve 23,8 min e perdeu para um motoboy livre a ~5,3 km (22,8 min). O caso reproduzido em `distribuicao-fila.php` e `distribuicao-rodadas.php` dá 24,2 min "termina tudo", 12,9 min com o encaixe (17,9 min antes da parada única na mesma loja) e 23,0 min ao livre.
    - Sem rodadas, o limite continua 10 min (`ATRASO_MAXIMO_S`).
  - **Pedido sem coordenada válida de coleta ou entrega** abre a todos na hora (`sem_candidato`, alarme geral), com o warning `pedido sem coordenada; aberta a todos`: não há como medir raio.
  - **Limite de 1 h** (decisão do Edgard, 2026-10-07; `Distribuicao::MINUTOS_ATE_PARAR_DE_TOCAR` = 60): passada 1 h do `despachada_em`, o `avancarEmRodadas` não oferece mais a ninguém nem agenda o próximo passo (`Distribuidor::soNaLista`). A distribuição segue em `ofertas`, a lista fica aberta a todos (grava `lista_aberta_em` nesse momento, se ainda estava fechada) e o aceite pela lista vale. A oferta que já corria termina normalmente (vence ou recusa); o passo seguinte é que não oferece. Log info `limite de 1 h; só na lista` uma vez por distribuição (marca no cache `entregas:distribuicao-parou-de-tocar:<id>`). O encerramento (aceite, atribuição, cancelamento, `adhoc` desligado) não muda. Sem isso, o pedido de teste esquecido tocaria o dia inteiro e a `posicao` (`unsignedSmallInteger`) estouraria em ~15 dias.
- **Aceite** (`BarrarAceiteDePedidoEncerrado`): com a lista fechada, só quem tem a oferta (como antes). Com a lista aberta, qualquer motoboy: o primeiro leva e a pendente vira `cancelada`.
  - Quem tinha a oferta grava `aceita` nela; os outros ganham a linha `aceita_pela_lista` (`Distribuidor::registrarAceitePelaLista`). Motivo `aceita` nos dois.
  - Com a lista aberta, um `assign` de outro motoboy leva 409, como na fase de ofertas.
  - Só em rodadas, quem tenta aceitar um pedido aberto que outro já iniciou leva 409 "Este pedido passou para outro motoboy." (sem rodadas, segue o "Order has already started." do Fleet-Ops). O log tem `motivo` próprio para distinguir os 409.
  - **Decisão (revisão final, 2026-10-07):** o aceite pela lista **não** barra quem recusou ou dispensou na volta. A lista já não mostra o pedido a ele, mas, se ele mudou de ideia (card ou alarme ainda abertos), pode aceitar.
- **Recusar e Dispensar** (`POST v1/entregas/motoboy/pedidos/{id}/recusar`, `Distribuidor::recusarOuDispensar`):
  - oferta pendente dele: 200 `{"resultado": "recusada"}` e o próximo passo na hora;
  - sem oferta dele, com a distribuição em `ofertas` e o pedido aberto sem motoboy, **com a lista aberta ou fechada**: 200 `{"resultado": "dispensada"}` (linha `dispensada`, uma por volta: some da lista dele e não recebe oferta até a volta seguinte). Com a lista fechada (rodada 1 da volta 1), o 409 faria o APK guardar o pedido nos dispensados locais até reiniciar;
  - senão (fora de `ofertas`, pedido com motoboy, encerrado ou que deixou de ser aberto) 409 "Esta oferta não está mais com você."; trava ocupada 503.
- **Lista do app** (`FiltrarPedidosAbertosDoMotoboy`): lista fechada como antes; aberta para todos, menos quem recusou ou dispensou na volta atual; `entregas_distribuicao: true` em todo pedido em `ofertas`.
  - Acrescenta os pedidos com a lista aberta cuja coleta está até 2R da posição do motoboy (`drivers.location`) e que o `nearby` do Fleet-Ops (só até R) não trouxe, no formato `Http\Resources\v1\Order` (até 50 distribuições por consulta). Entra também o pedido em que **ele** tem a oferta pendente, mesmo fora de 2R.
  - O acréscimo tem try/catch próprio (log `filtro da lista: acréscimo: <classe>`) e não derruba o filtro principal. As relações do recurso (`RELACOES_DO_RECURSO`) só são carregadas, em lote, nos pedidos que entram; o filtro carrega só `RELACOES_DO_FILTRO`.
- **Console:** `POST .../distribuicao/abrir` vira "Mostrar a todos agora" (`Distribuidor::mostrarATodos`): grava `lista_aberta_em`, sem alarme, e o ciclo segue. 409 "A lista deste pedido já está aberta a todos." ou "Este pedido não está em oferta."; com as rodadas desligadas, `mostrarATodos` devolve `fora_de_ofertas`. O `GET .../distribuicao` traz `rodadas`, `volta`, `rodada`, `raio_m` (da rodada atual) e `lista_aberta_em`; cada linha do histórico traz `volta`, `rodada` e `raio_m`.
- **Varredura:** vence as pendentes vencidas e encerra (como antes) e **avança** as distribuições em `ofertas` sem pendente e paradas (`updated_at`) há mais de 50 s (`SEGUNDOS_PARADA - FOLGA_DO_RELOGIO_S`, despachadas nas últimas 24 h; a folga de 10 s mantém a cadência de 1 min). Não abre pelo prazo. Não acorda as despachadas há mais de 1 h (`Distribuicoes::emOfertasParadasHa`), exceto a que ainda está com a lista fechada, avançada uma vez só para abrir a lista.
- **Reenvio:** em rodadas a fase `ofertas` dura até o fim, então o `ReenviarPedidosAbertos` nunca reenvia esses pedidos; o aviso "sem motoboy" aos 12 min continua.
- **Push:** `OfertaDePedido` leva `$segundosDaOferta` (50 em rodadas, 30 sem), o teto do `android.ttl`. O app não tem prazo fixo: o anel enche com o que chega (`totalDoAnel` = máx. de 30 e o recebido) e o cartão do alarme conta pelo fim do push; só os textos e o desenho do Sobre ainda dizem 30 s (próximo APK).
- **Dados:** migration `2026_10_07_120000_add_rodadas_entregas_distribuicao_table`: `volta`, `rodada`, `volta_iniciada_em` e `lista_aberta_em` na distribuição; `volta`, `rodada` e `raio_m` nas ofertas, mais o índice `(distribuicao_id, volta)`.
  - `entregas_ofertas.resposta` passa a 32 caracteres (`aceita_pela_lista` tem 17). O `down()` converte `aceita_pela_lista` em `aceita` e apaga as `dispensada`.
  - Respostas novas: `dispensada` e `aceita_pela_lista`. São linhas sem oferta (`oferecida_em` = `vence_em` = `respondida_em`), com `posicao` 0: **não contam na numeração das ofertas**, e o painel não mostra número nelas.
- **Logs** (`[entregas] distribuição:`): `rodada <n> (raio <m> m)`, `volta <n>`, `lista aberta`, `oferta dispensada`, `aceita pela lista`, `aguardando motoboy`, `pedido sem coordenada; aberta a todos`, `job da próxima volta não entrou na fila`, `trava ocupada ao avançar a distribuição`, `limite de 1 h; só na lista`.
- **Ligar e desligar no meio:** ligada, as distribuições em `ofertas` seguem no ciclo novo no próximo passo (volta 1, rodada 1 pelas colunas padrão); desligada, voltam ao ciclo antigo (o prazo de 3 min pode abrir a todos na hora). O job `AvancarDistribuicao` com a chave desligada não faz nada.
- **Riscos aceitos:** com um motoboy só, ele recebe a oferta a cada 1 min por até 1 h (50 s tocando, 10 s parado); depois, o pedido fica só na lista; o motoboy até 2R vê na lista pedidos de lojas a 12 km; o tempo é em linha reta (OSRM próprio continua sendo a melhoria); job `AvancarDistribuicao` repetido não faz mal (com pendente, espera).
- **Testes:** `scripts/teste-php/distribuicao-rodadas.php` (novo) e os casos "Rodadas" no fim de `distribuicao-encaixe.php`, `distribuicao-fila.php`, `distribuicao-rotas.php`, `filtrar-pedidos-abertos.php`, `barrar-aceite.php` e `avisos-push.php`. Os testes de antes continuam valendo com a chave desligada.
- **Implantação:** `bash deploy/atualizar.sh api` (migration nova) → `bash deploy/atualizar.sh console` → `docker-stack.yml` novo colado no Portainer (variável `ENTREGAS_DISTRIBUICAO_RODADAS`, ainda vazia; Update the stack, "Re-pull image" desligado) → APK 30 em todos os celulares → `ENTREGAS_DISTRIBUICAO_RODADAS=1` no `stack.env` e Update the stack → teste controlado na loja de teste, com os motoboys avisados. Se algo der errado: `ENTREGAS_DISTRIBUICAO_RODADAS=` vazio e Update the stack (volta ao ciclo atual).
  - **Janela da migration:** com `ENTREGAS_DISTRIBUICAO=1` já ligada, há alguns segundos entre o `service update` e o `deploy.sh` (migrations) do `atualizar.sh api` em que o código novo roda sem as colunas novas (`volta`, `rodada`, `lista_aberta_em`…): o filtro da lista falha (log `filtro da lista: <classe>`, e a lista sai sem filtro) e o `criar` da distribuição cai no alarme geral (`falha ao iniciar a distribuição; alarme geral`). O sistema se recupera sozinho depois da migration. Para evitar, deixe `ENTREGAS_DISTRIBUICAO` vazia (Update the stack) durante o `bash deploy/atualizar.sh api` e religue depois; ou aceite a janela.
