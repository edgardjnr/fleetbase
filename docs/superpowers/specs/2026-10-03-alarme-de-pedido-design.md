# Alarme de pedido e avisos em pt-BR — desenho

Data: 2026-10-03 · Status: aprovado em conversa (item 3 da instabilidade do app do motoboy)

## Objetivo

O motoboy não pode perder pedido com o celular no bolso. Hoje:

- com o app fechado ou a tela bloqueada, o aviso de pedido é um push comum que o Android mostra no
  canal `pedidos` (toque de 30 s com uso de toque de chamada): respeita o silencioso e toca uma vez;
- o alarme em loop (`AlertaPedido`, uso de alarme) só toca com o app aberto, na tela do pedido;
- os avisos que o servidor manda ao motoboy chegam em inglês ("New incoming order!",
  "Order … was canceled", "Message from …").

Meta: com o app fechado, em segundo plano ou com a tela bloqueada, pedido novo e pedido atribuído tocam
um **alarme em loop, mesmo no silencioso**, com **tela cheia** no celular bloqueado; e **todos os
avisos** ao motoboy saem em **pt-BR**.

## Decisões (conversa de 2026-10-03)

- Alarme para o pedido novo aberto (e os reenvios de 4/8/12 min) **e** para o pedido atribuído ou
  liberado pela central ao motoboy.
- O chat continua com o toque longo (canal próprio "Mensagens"). Os avisos de status (cancelado,
  concluído…) passam para o som normal de notificação.
- Ao abrir, o app verifica o que pode bloquear o alarme e avisa em pt-BR com o botão certo. Sem
  problema, não mostra nada.
- Abordagem escolhida: push de dados + alarme nativo. Descartadas: um canal de alarme com push comum
  (sem loop de verdade nem tela cheia, e continua tocando depois de outro motoboy aceitar) e um serviço
  sempre ativo ouvindo o socket (bateria, notificação fixa, complexidade).

## Como funciona hoje (base do desenho)

- **App:** o push passa pela biblioteca `react-native-notifications` 5.1.0.
  - App na frente: a mensagem vai ao JS. O `DriverLayout` abre o pedido com `alerta: true` e a tela
    do pedido toca o `AlertaPedido`.
  - App fora da frente: push comum é mostrado pelo SDK do FCM. Push de dados é mostrado pela
    biblioteca (`PushNotification.postNotification`) **se trouxer `title`/`body`**, no canal indicado
    em `android_channel_id` nos dados.
  - Ao tocar na notificação, a biblioteca abre o app e entrega o push ao JS (evento "opened").
  - Ponto de extensão: se a `Application` implementa `INotificationsApplication`, é ela que cria o
    objeto de cada push (`getPushNotification`).
- **Servidor:** todos os avisos ao motoboy (fleetops-api 0.6.65 e core-api 1.6.61) montam o push com
  `PushNotification::createFcmMessage($title, $message, $data)` e saem pelo
  `NotificationChannels\Fcm\FcmChannel` 4.5.0. O `data.type` identifica o aviso.

## 1. Servidor (`api/app`)

### Canal

`App\Notifications\Entregas\CanalFcmEntregas` estende o `FcmChannel` e o substitui no container
(`AppServiceProvider`, como no reenvio). O `send()` repete o do pacote (tokens do motoboy,
`sendMulticast` com o cliente da mensagem, relatório de falhas), mas passa a mensagem por
`AvisosDoMotoboy::adaptar($notificacao, $mensagem)` antes de enviar. Se a adaptação lançar erro, envia
a mensagem original e registra `[entregas] aviso push sem adaptação` no log: nenhum aviso se perde
por causa da tradução.

### Textos

`App\Notifications\Entregas\AvisosDoMotoboy` escolhe o texto pela classe da notificação:

| Notificação (`data.type`) | Título | Texto |
|---|---|---|
| OrderPing (`order_ping`) | Novo pedido disponível | Coleta a {distância} de você. Toque para ver o pedido. |
| LembretePedidoAberto (`order_ping`) | Pedido ainda sem motoboy (já sai assim) | Coleta a {distância} de você. Toque para ver o pedido. |
| OrderAssigned (`order_assigned`) | Novo pedido para você | Pedido {código}. Toque para ver os detalhes. |
| OrderAssigned agendado | Novo pedido para você | Pedido {código} agendado para {dd/mm} às {hh:mm}. |
| OrderDispatched (`order_dispatched`) | Pedido {código} liberado para você | Toque para ver e iniciar a entrega. |
| OrderCanceled (`order_canceled`) | Pedido {código} cancelado | O pedido {código} foi cancelado. |
| OrderFailed (`order_canceled`) | Entrega do pedido {código} não concluída | A entrega do pedido {código} falhou. |
| OrderCompleted (`order_completed`) | Pedido {código} concluído | O pedido {código} foi concluído. |
| WaypointCompleted (`waypoint_completed`) | Pedido {código}: parada concluída | Uma parada do pedido {código} foi concluída. |
| ChatMessageReceived (`chat_message_received`) | Mensagem de {nome} | o texto da mensagem |
| TestPushNotification (`test`) | o que o admin digitou | o que o admin digitou |
| qualquer outra | texto original | texto original, e o log registra `[entregas] aviso push sem tradução` |

- **{distância}:** o `distance` do aviso, em metros: "800 m" ou "1,2 km". Sem distância, o texto é só
  "Toque para ver o pedido.".
- **{código}:** o código de rastreamento, tirado do título original (`Order X …` ou `New order X …`).
  Sem ele, a frase sai sem o código: "Pedido cancelado", "O pedido foi cancelado.", "Parada
  concluída", "Toque para ver os detalhes.".
- **{nome}:** o que vem depois de "Message from " no título original.
- **Agendado:** `scheduled_at` do pedido no fuso da organização (America/Sao_Paulo se faltar).

### Formato

- **Alarme** (`order_ping`, `order_assigned`, `order_dispatched`), com a chave desligada (padrão):
  push comum no canal `alarme_pedido`. No APK 16 é o canal de alarme (toca no silencioso, uma vez,
  sem loop nem tela cheia); no APK antigo, que não tem o canal, o Android usa o padrão `pedidos`,
  como hoje.
- **Alarme com a chave ligada:** push **de dados**. Saem o bloco `notification` e o
  `android.notification`. Os dados levam `id`, `type`, `title`, `body` e
  `android_channel_id = pedidos`, todos como texto. Vai com `android.priority = high` e
  `android.ttl = 900s`: um alarme que não chega em 15 min não toca mais tarde.
- **Chat:** push comum, `android.notification.channel_id = mensagens`.
- **Status** (cancelado, falhou, concluído, parada) e teste: push comum, `channel_id = avisos`.
- **Outros:** só o texto (fica no canal padrão).
- APK sem os canais `alarme_pedido`, `mensagens` ou `avisos`: o Android usa o canal padrão
  `pedidos`, como hoje.

### Chave do push de dados

`ENTREGAS_ALARME_POR_DADOS`, lida com `getenv` (sobrevive ao `config:cache`). **Desligada por
padrão**; `1`, `true` ou `on` liga. Ligar só quando todos os motoboys tiverem o APK 16: no APK
antigo, a biblioteca de push monta a notificação do push de dados com os dados embrulhados num campo
`pushNotification` (o JS não desembrulha), e tocar nela abre o app sem ir ao pedido. Para ligar ou
desligar: variável nos serviços da API do stack (application, queue, scheduler) + update no
Portainer, sem deploy de código.

### Junto: lista única de status encerrados

`ReenviarPedidosAbertos` passa a usar `StatusDoPedido::ENCERRADOS` (a lista já está na main) e perde
a constante própria.

## 2. App (entregas-navigator)

### Recebimento

- `MainApplication` implementa `INotificationsApplication`. O `getPushNotification` devolve
  `PushDoEntregas`, subclasse do `PushNotification` da biblioteca.
- `PushDoEntregas.postNotification` (a biblioteca só chama com o app fora da frente): se o `type` é de
  alarme, chama `AlarmeDePedido.disparar`; senão, segue o comportamento da biblioteca.
- App na frente: nada muda (o JS abre o pedido e toca o `AlertaPedido`).
- Tocar no alarme ou em "Ver pedido" abre o app com os dados do push no mesmo formato do push comum
  (extras com `google.message_id`), que a biblioteca entrega ao JS como "opened".
- App que estava fechado: a biblioteca guarda o push como "push inicial", mas o app nunca o lia, e
  tocar numa notificação com o app fechado não abria o pedido. O `DriverLayout` passa a ler o push
  inicial ao abrir (`getInitialNotification`).

### Voltar para o app com o alarme tocando

O último alarme fica guardado no aparelho ("pendente") por até 3 min. Se o motoboy voltar ao app por
outro caminho (desbloqueando com o app na frente, pelo ícone), o `DriverLayout` lê o pendente e abre
o pedido com o alarme do app. "Silenciar", arrastar a notificação para o lado, tocar nela ou a tela do
pedido abrir apagam o pendente.

### AlarmeDePedido

- Notificação no canal `alarme_pedido`: uso de alarme, som `novo_pedido`, vibração, importância alta,
  visível na tela de bloqueio.
- Categoria alarme, prioridade máxima, `FLAG_INSISTENT` (o som repete até abrir, silenciar ou
  cancelar) e `setTimeoutAfter(3 min)`.
- Tag `alarme_pedido` e id calculado pelo pedido: um aviso repetido do mesmo pedido substitui o
  anterior.
- Tocar na notificação segue o caminho de hoje: o intent da biblioteca abre o app no pedido, com o
  alarme do app.
- Ação "Silenciar" (`SilenciarAlarmeReceiver`): cancela o alarme.
- Tela cheia: `AlarmePedidoActivity`.
- `cancelarTodos()` cancela as notificações de alarme e fecha a tela cheia. É chamado pelo
  `AlertaPedido.iniciar()` (a tela do pedido assumiu o som, sem som duplicado) e pelo "Silenciar".

### Tela cheia (`AlarmePedidoActivity`)

- Aparece sobre a tela de bloqueio e acende a tela (`showWhenLocked`, `turnScreenOn`); fica fora dos
  recentes.
- Mostra o título e o texto do aviso e dois botões:
  - **Ver pedido:** pede o desbloqueio; desbloqueado, abre o app no pedido pelo intent da biblioteca
    e cancela o alarme. Exigir o desbloqueio impede usar o app pela tela de bloqueio.
  - **Silenciar:** cancela o alarme; o pedido continua na lista. Voltar faz o mesmo.
- Fecha sozinha quando o alarme é cancelado ou passa de 3 min.
- Layout em código (sem XML); textos em `strings.xml`.

### Canais (nomes e descrições em pt-BR, em `strings.xml`)

| Id | Nome | Som |
|---|---|---|
| `alarme_pedido` (novo) | Alarme de novo pedido | `novo_pedido`, uso de alarme (toca no silencioso) |
| `mensagens` (novo) | Mensagens | `novo_pedido`, uso de toque de chamada (o toque longo de hoje) |
| `avisos` (novo) | Avisos de pedidos | som padrão de notificação |
| `pedidos` (existente) | Pedidos | inalterado; continua o padrão do FCM e o fallback |

### Verificação ao abrir o app

- `AlertaPedido.verificar()` informa: notificações liberadas, canal de alarme ativo, tela cheia
  permitida (Android 14+), volume de alarme acima de zero e app fora da restrição de bateria.
- Hook `useVerificacaoDoAlarme` no `DriverLayout`, ao abrir e ao voltar para o app: se algum item
  falha, mostra um alerta em pt-BR com o primeiro problema e o botão certo (abrir as configurações de
  notificação, do canal ou da tela cheia; pedir a liberação da bateria; ou subir o volume do alarme
  para 80%), mais "Agora não". O "Agora não" vale até a próxima abertura do app.
- Permissões novas no manifest: `USE_FULL_SCREEN_INTENT` e `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS`.

### Outros avisos do app em pt-BR

- Notificação fixa do rastreamento: opção `notification` no `BackgroundGeolocation.ready` (título
  "Entregas", texto "Localização ativa para receber pedidos próximos", canal "Localização").
- Textos do JS em `translations/pt.json` (e em `en.json`).

## 3. Compatibilidade e implantação

1. Commit e push do servidor (Delivery, main) e do app (navigator, main, que gera o APK 16 no CI).
2. Deploy da API (chave desligada) com o **APK 15** ainda no celular do Motoca. Conferir que pedido
   (com o app fechado), chat e cancelamento chegam em pt-BR com o toque longo e que tocar no pedido
   abre o pedido: é o que os APKs antigos vão ver.
3. Instalar o APK 16 e testar com a chave desligada: alarme no silencioso (uma vez), canais novos,
   verificação ao abrir.
4. Ligar a chave (Portainer) num horário calmo e testar o alarme completo: loop e tela cheia. Se
   ainda houver motoboy com APK antigo, avisar antes (tocar na notificação não abre o pedido para
   ele) ou desligar a chave depois do teste.
5. Distribuir o APK 16 aos motoboys e deixar a chave ligada.

## 4. Testes

- **Servidor** (php-wasm, como no reenvio): cada linha da tabela de textos; formato de dados ou comum
  por tipo; prioridade, validade e canal; todos os dados como texto; chave desligada; erro na
  adaptação envia o original; o `CanalFcmEntregas` usa o cliente da mensagem e mantém o relatório de
  falhas.
- **App:** o CI compila (não há Android SDK nesta máquina). Teste guiado no celular, com pedidos de
  teste:

| Situação | Esperado |
|---|---|
| Chave desligada, app fechado, silencioso | Som de alarme (uma vez); tocar abre o pedido |
| App fechado, tocar numa notificação de pedido | O app abre direto no pedido (antes não abria) |
| Chave ligada a partir daqui: app fechado (arrastado), tela bloqueada, silencioso | Tela acende, alarme de tela cheia em loop; "Ver pedido" pede o desbloqueio e abre o pedido |
| O mesmo, tocando "Silenciar" | Alarme para; pedido continua na lista |
| App em segundo plano, desbloqueado | Notificação no topo com loop; ao tocar, abre o pedido sem som duplicado |
| App aberto | Como hoje: tela do pedido com o loop |
| Ninguém aceita | Alarme de novo aos 4, 8 e 12 min ("Pedido ainda sem motoboy") |
| Central atribui o pedido ao Motoca | Alarme |
| Mensagem no chat | Toque longo, "Mensagem de …" |
| Pedido cancelado | Som normal, "Pedido … cancelado" |
| Volume de alarme zerado, abrir o app | Aviso em pt-BR com "Aumentar volume" |

## Fora desta etapa

- Parar o alarme nos outros celulares quando um motoboy aceita (hoje para em no máximo 3 min).
- E-mails do Fleet-Ops ao motoboy (o e-mail está em `log`).
- O toast "LICENSE VALIDATION FAILURE", que vem da biblioteca de localização.
- iOS.

## Riscos

- Fabricantes que matam o app (algumas Xiaomi, "autostart") ou "Não perturbe" sem alarmes: o push não
  acorda o app ou o som não sai. A verificação cobre bateria e volume, não esses dois casos.
- Android 14+ sem a tela cheia liberada: o alarme toca como notificação no topo (a verificação avisa).
- Atualização do fleetops-api, do core-api ou do pacote FCM: o `CanalFcmEntregas` repete o `send()`
  do FcmChannel 4.5.0 e os textos dependem das classes de aviso. Conferir ao atualizar.
