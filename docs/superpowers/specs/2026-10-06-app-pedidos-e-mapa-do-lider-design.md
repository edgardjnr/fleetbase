# App do motoboy: aba Pedidos enxuta e aba Mapa do líder

Data: 2026-10-06. Aprovado pelo Edgard na conversa de 2026-10-06.

## Objetivo

1. **Parte A:** a aba Pedidos do app deixa de ser histórico (que agora fica no Início, "Meus ganhos") e mostra só os
   pedidos novos para aceitar e os pedidos em andamento, com um card mais enxuto.
2. **Parte B:** a aba Relatórios sai do app. No lugar dela, só para quem é **líder dos motoboys** ou administrador, entra
   a aba **Mapa**: todos os pedidos em andamento e os motoboys no mapa, com a troca do motoboy de um pedido.

Repo do app: `entregas-navigator` (ramo novo a partir do `ifood-etapa-4`, que já mexeu no `OrderScreen` e nos cards).
Servidor: `api/app` deste repo.

## Parte A: aba Pedidos

Arquivo: `src/screens/DriverOrderManagementScreen.tsx`.

- **Sai** o seletor de data (`CalendarStrip`) e o cabeçalho do dia ("Pedidos de segunda-feira", contagem de pedidos,
  paradas, tempo e km). Com isso saem do uso na tela o `currentDate`/`setCurrentDate`, o `activeOrderMarkedDates` e o
  `PastOrderCard` (o contexto `OrderManagerContext` continua igual; o Início usa os ganhos, não o `currentOrders`).
- **A lista tem duas seções:**
  1. **Novos pedidos:** os pedidos abertos por perto (`nearbyOrders`, adhoc sem motoboy, menos os dispensados e os que
     já são dele), com o `AdhocOrderCard` de hoje (aceitar, dispensar, selo iFood).
  2. **Em andamento:** os pedidos atribuídos a ele e não encerrados, de qualquer dia (`allActiveOrders`), com o
     `OrderCard`.
  - Seção vazia não aparece. As duas vazias: "Nenhum pedido no momento".
  - Puxar para baixo recarrega os dois (`reloadNearbyOrders` e `reloadActiveOrders`). Continuam o push (`order_*`
    recarrega), o socket `driver.<id>` (`order.ready`, `order.ping`) e os intervalos com a tela em foco, agora
    recarregando `allActiveOrders` no lugar do `currentOrders`.
  - O selo do número de pedidos da aba (`tabBarBadge`) já usa o `allActiveOrders`: não muda.
- **`OrderCard` (`src/components/OrderCard.tsx`):**
  - título = **número do pedido** = `internal_id` (o número do iFood); sem `internal_id` (pedido da central ou do portal),
    o código de rastreio como hoje. Função pura `numeroDoPedido(order)` em `src/utils/`;
  - **saem** os seis campos: Cliente, Comprovação, Tempo estimado, Agendado para, Despachado em e Previsão de conclusão;
  - **entra**, no lugar deles, a **loja da coleta**: rótulo "Coleta" e o nome do local de coleta
    (`payload.pickup.name`, que é o nome da loja no Local dela); sem nome, o nome do cliente do pedido (`customer.name`,
    o Vendor da loja); sem os dois, "—";
  - continuam o mapa (`LiveOrderRoute`), as paradas (`OrderWaypointList`) e a barra de progresso.
- Traduções em `translations/pt.json` e `en.json` (chaves novas no bloco da tela/card; as que deixarem de ser usadas
  podem ficar).

## Parte B: aba Mapa do líder

### Quem é líder

- **Papel do IAM.** A central cria em Admin → IAM um papel (ex.: "Líder de motoboys") com a permissão já existente do
  Fleet-Ops **`fleet-ops assign-driver-for order`** e o dá ao usuário do motoboy. Também valem **administradores**
  (`$user->isAdmin()`) e quem tem o curinga (`fleet-ops *`), pela regra do `Fleetbase\Support\Auth::can`.
- Regra única no servidor: `App\Support\Entregas\LiderDosMotoboys::ehLider(User)` = admin **ou**
  `Auth::can('fleet-ops assign-driver-for order')` para o usuário da sessão. **A conferir no plano:** como o
  `Auth::can`/`hasPermissionTo` resolve o papel do usuário na empresa da sessão (`CompanyUser`) numa chamada da API v1
  com token de motoboy; se não resolver pela sessão, consultar as permissões do `CompanyUser` da empresa diretamente.
- **A conferir no plano:** se a tela de usuários do IAM lista os usuários `type=driver` para receber o papel. Se não
  listar, documentar o caminho (ou um comando artisan para dar o papel).

### Servidor (`api/app`)

Rotas na API v1, com o token do motoboy (`fleetbase.api`), em `v1/entregas/lider/*`, limitador próprio
`entregas-lider` (120 por minuto por usuário). Controller `Entregas/LiderController.php`.

- `GET v1/entregas/lider/acesso` → `{"lider": true|false}`. Nunca 403 (o app usa para mostrar ou esconder a aba).
- `GET v1/entregas/lider/mapa` (só líder; senão 403):
  - `pedidos`: os pedidos em andamento de todas as lojas, como o mapa do console (`PedidosNoMapa::daCentral`: não
    encerrados, atualizados nas últimas 12 h, com ou sem motoboy, até 300), com `id` (public_id), número
    (`internal_id`), loja, status, motoboy (nome e public_id), aceito, "há X min" (data de atualização), endereço e
    coordenadas da entrega e da coleta;
  - `motoboys`: os motoboys online e os offline com pedido aceito em aberto (mesma regra do mapa do portal), com
    `id` (public_id), nome, coordenadas, `situacao` (`SituacaoDoMotoboy`: livre, indo à loja, em entrega) e `online`.
    Sem telefone.
- `POST v1/entregas/lider/pedidos/{id}/motoboy` com `{"motoboy": "<public_id do Driver>"}` (só líder; senão 403):
  - pedido da empresa da sessão (busca por public_id ou uuid, `company_uuid` explícito); não achou → 404;
  - pedido encerrado (`StatusDoPedido::ENCERRADOS`) → 409 "Este pedido já foi encerrado.";
  - motoboy da mesma empresa e existente; senão 422; mesmo motoboy de agora → 200 sem mudança;
  - com a `TravaDoPedido`: relê o pedido, desliga o pedido aberto (`adhoc` falso) e chama o `assignDriver` do Order do
    Fleet-Ops (o `OrderDriverAssigned` dispara o push "atribuído" ao novo, já traduzido pelo `AvisosDoMotoboy`);
  - o motoboy **anterior** recebe um push novo (`App\Notifications\Entregas\PedidoPassadoParaOutro`, canal `avisos`):
    "Pedido #4821 passou para outro motoboy." (número = `internal_id` ou o public_id);
  - log `[entregas] líder trocou o motoboy` com pedido, motoboy anterior, novo e o usuário líder (só ids);
  - pedido iFood: o `ObservadorDosPedidosIfood` (etapa 3) manda o `assignDriver` ao iFood sozinho; a recusa vira o aviso
    de sempre no console;
  - resposta: o pedido como no `mapa` (para o app atualizar o cartão).
- O pedido trocado fica com o status que tinha (como a troca pelo console).

### App

- **Aba:** `DriverReportTab` sai da lista de abas. Entra `DriverMapaTab` (ícone de mapa, rótulo "Mapa") só quando
  `GET lider/acesso` diz `lider: true`. O acesso é lido ao entrar no app e quando o app volta para a frente; guardado em
  memória (sem acesso = sem aba; erro = mantém o último valor, começa sem aba).
- **Tela `MapaDoLiderScreen`:**
  - mapa (Google Maps, como o do pedido) com o **alfinete vermelho** de cada pedido no endereço de entrega e o
    **capacete** de cada motoboy na cor da situação (`MarcadorCapacete`), com o nome embaixo;
  - relê o `lider/mapa` a cada 10 s com a tela em foco e o app na frente; espera crescente em erro; 403 → some a aba;
  - tocar no alfinete abre um **cartão** (bottom sheet) com número, loja, status, motoboy atual (ou "Sem motoboy"),
    "há X min", endereço de entrega e o botão **Trocar motoboy**;
  - **Trocar motoboy** abre a lista dos motoboys do mapa que estão online, com a situação e a distância em linha reta
    até a coleta do pedido (mais perto primeiro); o motoboy atual aparece marcado e não é escolhível; tocar pede
    confirmação ("Passar o pedido #4821 para Fulano?") e chama o `POST`; sucesso → toast e releitura do mapa; erro →
    toast com a mensagem do servidor.
- Funções puras em `src/utils/mapa-do-lider.ts` (ordem dos motoboys por distância, texto do cartão, regras de exibição),
  testadas com `node --experimental-strip-types --test`.

## Erros e casos de borda

- Líder perde o papel com a aba aberta: a próxima chamada responde 403 e a aba some.
- Pedido encerrado entre a leitura e a troca: 409 com a mensagem; o mapa relê.
- Dois líderes trocando ao mesmo tempo: a `TravaDoPedido` serializa; o segundo vê o motoboy do primeiro na releitura.
- Motoboy escolhido ficou offline depois da leitura: a troca vale assim mesmo (a central também atribui a offline).

## Riscos aceitos

- O líder (um motoboy) vê os endereços de entrega e os pedidos de todas as lojas, como a central.
- O papel com `fleet-ops assign-driver-for order` também vale no console se esse usuário entrar nele.

## Testes

- App: `scripts/testes/numero-do-pedido.teste.ts` (ou junto de um teste existente) e `scripts/testes/mapa-do-lider.teste.ts`.
- Servidor (php-wasm, `scripts/teste-php/`): `lider.php` com acesso (admin, papel, sem papel), mapa (403 e formato),
  troca (404, 409, 422, mesmo motoboy, troca com push ao anterior, adhoc desligado, outra empresa) e as rotas no
  `RouteServiceProvider`.

## Fora do escopo

- Tirar o motoboy de um pedido (voltar para aberto) pelo app.
- Chat ou ligação do líder com os motoboys pela aba Mapa.
- Mudanças no console (o papel é criado na tela padrão do IAM).
