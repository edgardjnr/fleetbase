# Entregas RestaurantePro (fork do Fleetbase)

Fork de `fleetbase/fleetbase` em `edgardjnr/fleetbase` (branch `main`), customizado como o produto
**Entregas** do RestaurantePro. Toda a interface fica em **pt-BR**, roda em produção numa VPS ARM64 e
o código das extensões está incorporado ao repositório.

Responda e documente em português do Brasil.

## Produção

| | |
|---|---|
| Console | https://entregas.restaurantepro.com.br → `entregas_console:4200` |
| API | https://entregas-api.restaurantepro.com.br → `entregas_application:8000` |
| Socket | https://entregas-socket.restaurantepro.com.br → `entregas_socket:8000` |

- **VPS:** Oracle **ARM64** (aarch64), Docker Swarm + Portainer. É a mesma VPS do RestoFit.
- **Entrada:** Cloudflare Tunnel `restaurantepro-oracle`, na rede overlay `network_public`, com 3 rotas publicadas (Zero Trust → Conectores). Não há portas abertas.
- **Stack:** `entregas` no Portainer. O arquivo é `deploy/docker-stack.yml`; as variáveis ficam em `deploy/stack.env`, que é ignorado pelo git e existe só no PC do Edgard.
- **Imagens:** `entregas-api`, `entregas-console` e `entregas-socket`, todas buildadas **na VPS**. O stack não builda.
- **Clone na VPS:** `~/entregas`. O Claude não tem SSH; quem roda os comandos é o usuário.

### Deploy / atualização

```bash
cd ~/entregas && bash deploy/atualizar.sh            # pull + build de tudo + service update + deploy.sh
bash deploy/atualizar.sh console                      # só o console (~15 min, usa packages/*)
bash deploy/atualizar.sh api                          # API + queue + scheduler, depois roda o deploy.sh (migrations)
SEM_PULL=1 bash deploy/atualizar.sh socket
```

Ao atualizar o stack pelo Portainer, deixe "Re-pull image" **desligado**, porque as imagens são locais.
Mudou alguma variável do stack? Use Stacks → entregas → Editor → Update the stack.
Depois de cada deploy do console, abra o site com Ctrl+Shift+R. O console guarda o `fleetbase.config.json` no localStorage por 1 h.

### Decisões e armadilhas de produção (não desfazer)

- **ARM64.**
  - A imagem oficial `socketcluster/socketcluster` só existe para amd64. Use `docker/socket/` (SocketCluster 17.4.0, MIT, multi-arch).
  - O `go-crond` do `docker/Dockerfile` é baixado conforme `dpkg --print-architecture`.
  - Toda imagem nova precisa ter versão arm64.
- **Healthcheck.** A base `dunglas/frankenphp` testa `:2019/metrics`, que não existe no scheduler, e o Swarm matava o task a cada ~90 s. No stack: `scheduler` com `healthcheck.disable`, e `application` testando `curl localhost:8000/`.
- **Origem no socket.**
  - O `origins` nativo do SocketCluster recusa conexões sem `Origin` e com isso bloqueava a própria API.
  - O `docker/socket/server.js` usa um middleware próprio: navegador só entra a partir de `SOCKET_ALLOWED_ORIGINS`; conexão sem `Origin` (servidor) passa.
  - O app do motoboy (React Native) manda `Origin: https://entregas-socket...`, o próprio host do socket. Por isso o `SOCKET_ALLOWED_ORIGINS` do stack inclui o `SOCKET_DOMAIN` além do console.
- **Console em produção.**
  - O `config/environment.js` desliga o runtime config quando `ENVIRONMENT=production`. O `console/Dockerfile` força `DISABLE_RUNTIME_CONFIG=false`.
  - O `console/nginx-entrypoint/40-fleetbase-runtime-config.sh` gera o `fleetbase.config.json` a partir das env vars do serviço `console`.
- **HTTPS atrás do tunnel.** O `api/app/Http/Middleware/TrustProxies.php` lê `TRUSTED_PROXIES` via `getenv` (sobrevive ao `config:cache`).
- **Outros ajustes da API.**
  - `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=false` (MySQL interno).
  - `XDEBUG_MODE=off`.
  - O link `public/storage` já vem criado na imagem.
- **Banco.** Na primeira instalação, ou quando houver migrations novas, rode `bash deploy/atualizar.sh api`, que executa o `deploy.sh` dentro do container.
- **Organização.**
  - Moeda **BRL**, fuso **America/Sao_Paulo**.
  - A moeda foi gravada via API, porque o `CurrencySelect` só dispara a mudança quando o valor muda. Se a tela mostrar BRL só como sugestão, nada é salvo.
- **E-mail:** `MAIL_MAILER=log` até alguém configurar SMTP no `stack.env`.

## Fuso (horário de Brasília)

Decisão de 2026-10-05: o servidor inteiro roda no horário de Brasília (`America/Sao_Paulo`, sem horário de verão desde 2019), para acabar com os erros de 3 h (ex.: a lista "Hoje" do app, `GET v1/orders?on=...-03:00`, perdia os pedidos criados depois das 21h). Roteiro da troca em produção (com ensaio obrigatório num MySQL descartável) e scripts do banco: `deploy/fuso/LEIAME.md`.

- **PHP:** `api/config/app.php` com `timezone` = `America/Sao_Paulo`.
- **Sessão do MySQL:** `-03:00` (ou `DB_TIMEZONE`) nas conexões `mysql`, `sandbox` e `storefront`, pelo `AppServiceProvider::configurarFuso` (`App\Support\Entregas\FusoDoServidor`).
  - Não dá para pôr no `config/database.php`: as conexões vêm do core-api por `mergeConfigFrom`, que é raso, e uma chave `mysql` ali substituiria a conexão inteira.
- **TIMESTAMP** (`created_at`, `updated_at`, `deleted_at`, tabelas `entregas_*`): o MySQL guarda em UTC e converte para a sessão. Os dados antigos continuaram certos.
- **DATETIME** (`orders.dispatched_at`, `started_at`, `scheduled_at`, `time_window_*` e mais algumas) e o `users.last_login` (VARCHAR): guardam a hora de Brasília, sem fuso. Os dados antigos foram deslocados 3 h (`deploy/fuso/2-converter.sql`, com trava contra rodar duas vezes).
- **Datas da fachada `Date`** (Eloquent ao gravar e ler atributos de data, `now()`, `$request->date()`): o `Date::useCallable` converte para o fuso do app. O Eloquent formata no fuso do próprio objeto, e o ISO com `Z` do console (`scheduled_at`) era gravado com a hora UTC.
- **Nunca formate em UTC para gravar nem para consultar.**
  - O query builder (`where`, `whereBetween`, `whereDate`, `insert`/`update` do `DB::table`) formata o Carbon no fuso do objeto, e o `Date::useCallable` não cobre isso.
  - Use datas no fuso do app (`date_default_timezone_get()`) e leia texto sem fuso do banco nesse fuso.
  - A API continua respondendo em ISO UTC com `Z` (`serializeDate`): console e app não mudaram.
- **Consulta manual no MySQL:** rode `SET time_zone='-03:00';` antes, a menos que o servidor esteja com `--default-time-zone=-03:00` (opcional; ver o LEIAME). Sem isso, o TIMESTAMP sai em UTC e o DATETIME em Brasília.
- **Comandos agendados do Fleet-Ops que fixavam o PHP em UTC** (`date_default_timezone_set('UTC')`), com a sessão em -03:00, despachavam os agendados 3 h antes e gravavam o `updated_at` 3 h no futuro.
  - Rodam por subclasses sem essa linha (`api/app/Console/Commands/Entregas/Fuso/`), trocadas com `bind` (`AppServiceProvider::COMANDOS_SEM_UTC`): `fleetops:dispatch-orders`, `update-estimations`, `process-maintenance-triggers` e `send-maintenance-reminders`.
  - O `ReenviarPedidosAbertos` (`dispatch-adhoc`) também não fixa mais.
- **Ao atualizar o fleetops-api ou o core-api:** confira se o `handle()` desses comandos mudou e se outro comando passou a chamar `date_default_timezone_set`. O `scripts/teste-php/fuso.php` compara o `handle()` das subclasses com o da cópia em `packages/fleetops` e procura a chamada no Fleet-Ops, no core e no `api/app`.
- **Testes:**
  - `scripts/teste-php/fuso.php`: configuração, `Date::useCallable`, comandos e datas do iFood;
  - `fuso-relatorio.php`: período e conclusão do relatório e dos ganhos em Brasília;
  - os stubs (`stubs.php`, `stubs-ganhos.php`, `stubs-ifood.php`) também rodam em Brasília: `date_default_timezone_set('America/Sao_Paulo')`, `now()` no fuso padrão, e os textos do banco nas expectativas em hora de Brasília.
- **Efeitos colaterais aceitos:**
  - as tarefas `daily()`, `twiceDaily(1, 13)` e `dailyAt()` do agendador (purges do core, `telemetry:ping`, manutenção do Fleet-Ops, `materialize-schedules`) passam a rodar na hora de Brasília, porque o agendador usa o `app.timezone`;
  - os logs do Laravel saem em -03:00;
  - cliente externo da API v1 que manda ISO com `Z` em **filtro** de data (`created_at`, `on`… vão ao query builder) erra 3 h; ao gravar, o `Date::useCallable` converte. Texto sem fuso passa a valer como hora de Brasília (antes, UTC). A integração iFood é interna e não é afetada;
  - telas ocultas (agenda/escalas, manutenção, orquestrador) e o `sandbox:sync` não foram revisados: podem errar 3 h.
- **Risco:** se o horário de verão voltar, o `-03:00` fixo da sessão diverge do PHP. Aí use o fuso nomeado (exige as tabelas `mysql.time_zone_name`, que o inventário confere) e trate a hora ambígua das DATETIME.

## Estrutura

- `api/`: Laravel. Em produção, a API usa os pacotes **publicados** no Composer (fleetbase/core-api, fleetops-api…).
- `console/`: Ember. É buildado com os pacotes **locais** de `packages/*` (pnpm workspace via `scripts/package-linker.mjs`, só frontend).
- `packages/*`: extensões e bibliotecas (fleetops, storefront, ledger, iam-engine, dev-engine, registry-bridge, customer-portal, ai, ember-ui, ember-core, fleetops-data…).
  - Eram submódulos e foram **incorporados ao repo**: o `.gitmodules` foi removido. As cópias originais seguem em `.git/modules/packages/*` e servem de base para diff contra o upstream.
  - Pallet não está instalado no console.
  - **Mudanças em `packages/*/server` (PHP) não chegam à produção.** Texto que vem da API é traduzido no frontend.
  - Código PHP próprio vai em `api/app/` (entra na imagem da API). Exemplo: `Http/Controllers/Entregas/`, com as rotas na `Providers/RouteServiceProvider.php`.
- `deploy/`: stack, modelo de env, `atualizar.sh` e README do deploy.
- `scripts/teste-php/`: testes de comportamento do PHP do `api/app` sem PHP instalado (php-wasm, PHP 8.2), com os arquivos reais do Fleet-Ops e do core-api (cópias em `packages/`) e stubs do resto. Uso no cabeçalho do `rodar.mjs`. O `sintaxe.mjs` confere a sintaxe com o `php -l` do PHP 8.2.

## Escopo enxuto: delivery iFood → motoboy

O produto é só isto: o pedido chega do iFood (integração Logistics dentro da `api/app`, ver "Integração iFood"), é despachado para o motoboy, que usa o app **Navigator**, e o motoboy é pago por km.

- **Extensões fora do console:** storefront, ledger, registry-bridge (Extensions), ai, valhalla e vroom.
  - Saíram do `console/package.json`, do `pnpm-workspace.yaml` e do `Dockerfile.dockerignore`.
  - Os `app/router.js` e `app/extensions/*` são gerados no build a partir do `node_modules`.
  - As pastas em `packages/` e as APIs no `api/composer.json` continuam. Para reativar, reverta essas listas.
- **Ficam:** Fleet-Ops, IAM, **Developers** (chaves de API e webhooks; a integração iFood não usa) e o **Portal do Cliente**, que voltou como portal da loja (ver "Portal da loja").
- **Telas ocultas do Fleet-Ops:** a lista está em `packages/fleetops/addon/utils/entregas-hidden-routes.js`.
  - Inclui manutenção, conectividade/telemática, veículos, frotas, fornecedores, combustível, ocorrências, orquestrador, agenda, tarifas e várias configurações.
  - A lista tira os itens do menu (`fleet-ops-sidebar`) e dos hubs (`localize-hub`, `settings/index`), e a URL direta cai em Pedidos (`routes/application.js`).
  - Os atalhos do cabeçalho ficam no `extension.js`. Esse arquivo é copiado para o console e não pode importar utils do engine.
- **Pagamento e cobrança:** Fleet-Ops → Recursos → Pagamento e cobrança (`management.driver-payouts`), só para admin.
  - API: `api/app/Http/Controllers/Entregas/PagamentoMotoboysController.php`, `GET int/v1/entregas/pagamento-motoboys` e `PUT .../faixas`.
  - **km = rota de rua loja (pickup) → cliente (dropoff), só ida**, pelo OSRM (`OSRM_HOST`). O valor fica em cache no `meta.entregas.km_rota` do pedido.
  - Se o OSRM falha, usa uma estimativa (linha reta × 1,3), recalculada na próxima consulta.
  - **Não use `orders.distance`:** é a distância *restante* e vai a ~0 quando o pedido conclui.
  - O período usa a data do tracking status `COMPLETED`, no fuso da organização.
  - **Valores por faixa de km** (`Setting` `company.<uuid>.entregas.faixas` = `[{ate_km, motoboy, loja}]`): uma tabela só, com o valor pago ao motoboy e o cobrado da loja. A entrega vale o valor da faixa em que o km cai (0 < km ≤ 1 → 1ª, 1 < km ≤ 2 → 2ª…); acima da última vale a última. `PUT .../faixas` substitui a tabela.
  - **Valor congelado por entrega** (tabela `entregas_valores_pedido`, migration em `api/database/migrations`, que o `deploy.sh` roda no banco principal e no sandbox; fica fora do `meta` de propósito, porque o `meta` sai na API v1 e no socket): na primeira vez que o pedido tem km e há faixas, o `CalculoEntregas` grava a faixa e os dois valores (`ValoresCongelados`). Relatório, extrato da loja e app do motoboy leem o congelado.
    - **Mudar a tabela de faixas só vale para as entregas calculadas depois.** Normalmente o valor congela quando o pedido aparece no card de aceitar do motoboy.
    - Km diferente (endereço alterado, estimativa trocada pela rota do OSRM) recalcula com a tabela vigente.
    - Valor congelado errado: apague as linhas do período nessa tabela; elas voltam com a tabela atual na próxima consulta.
- **Cobrança das lojas** (mesma tela, renomeada "Pagamento e cobrança"): modelo A = **uma organização só** (o operador de entregas) e cada restaurante é uma **Loja** (ver "Portal da loja").
  - **Loja do pedido = o Vendor dono do pedido** (`orders.customer_uuid`), como nos pedidos do portal e nos que a central cria escolhendo a loja.
  - **Os pedidos do iFood entram com a loja como cliente e o Local dela como coleta** (ver "Integração iFood"). Assim o pedido entra no portal da loja, no extrato e na cobrança dela.
  - Quem criar pedido pela API v1 (`POST v1/orders`) manda `customer` = `public_id` do Fornecedor da loja (`vendor_…`) e `pickup` = `public_id` do Local da loja (`place_…`). Os dois ids aparecem na tela Lojas. Era o caminho previsto para o iFood em 2026-10-03, substituído em 2026-10-05 pela integração dentro da `api/app`.
  - Pedido sem loja como cliente fica fora do portal e do extrato. Na cobrança, ele agrupa pelo **nome** do local de coleta (a integração pode criar um Place por pedido); sem nome, pelo próprio Place.
  - Cobrança = valor "loja" da faixa do km, igual para todas as lojas. A tela mostra a pagar, a cobrar e a margem; o CSV traz motoboys, lojas e o detalhe com loja, faixa e os dois valores.
- **Mapa ao vivo** (Fleet-Ops → mapa, `components/map/leaflet-live-map.*`):
  - **Locais:** só os locais de coleta, isto é, o Local de cada loja. O mapa lê `GET int/v1/entregas/mapa/locais-de-coleta` (`Entregas/MapaController.php`) no lugar do `fleet-ops/live/places`, que trazia um prédio por endereço de entrega.
  - **Motoboy = capacete na cor da situação** (`packages/fleetops/assets/images/capacete-*.png`, util `entregas-capacete.js`): verde = livre; amarelo = pedido aceito ou atribuído, ainda na coleta (`started`, `dispatched`…); vermelho = a caminho do cliente (`enroute`); cinza = offline sem pedido. A regra fica em `App\Support\Entregas\SituacaoDoMotoboy` e é lida em `GET int/v1/entregas/mapa/motoboys`. Pedido parado há mais de 12 h não conta.
  - **A cor muda junto com o status.** O mapa escuta o canal `company.<uuid>` do socket e relê a situação ~0,6 s depois de cada evento `order.*`, `waypoint.*`, `entity.*`, `driver.(created|updated|deleted)` ou `entregas.motoboy_online`. A posição (`driver.location_changed`) não conta. A releitura a cada 20 s fica só como reserva. Com a aba oculta o mapa não lê; ao voltar para a aba, lê na hora.
    - Do `localhost` o socket de produção recusa a conexão, porque o `SOCKET_ALLOWED_ORIGINS` só aceita o console. Para testar a reação local, injete o evento no canal: `socket.instance()._channelDataDemux.write('company.<uuid>', {event: 'order.updated'})`.
  - O liga/desliga do online no app (`toggleOnline` do Fleet-Ops) grava com `updateQuietly` e não gera evento. O middleware `AvisarOnlineDoMotoboy` (grupo `fleetbase.api`) transmite o `entregas.motoboy_online` (`App\Events\Entregas\OnlineDoMotoboyMudou`, na hora, sem fila). **Ao atualizar o fleetops-api, confira se a ação `Api\v1\DriverController@toggleOnline` ainda existe.**
  - A lista **Operações ao vivo** da barra lateral (`fleet-ops-sidebar/operations-monitor`) lê o `online` do motorista no store, não a situação do mapa. Ela escuta o `entregas.motoboy_online` no canal da empresa (util `escutar-canal-da-empresa.js`: consumidor próprio, em qualquer tela do Fleet-Ops; refaz a inscrição se outra tela fechar ou desinscrever o canal) e grava o `online` com `store.push` (util `entregas-online-do-motoboy.js`, sem deixar o registro "alterado"). Reserva: a releitura do mapa (`entregas/mapa/motoboys`, que traz o `online`) faz o mesmo. Teste: `scripts/teste-portal/online-do-motoboy.test.mjs`.
  - **Pedidos em tempo real** (`services/order-socket-events.js`): cada evento `order.*`, `waypoint.*` ou `entity.*` do canal `company.<uuid>` relê o pedido, recarrega a lista da rota (tabela/quadro) quando o pedido é novo ou mudou de status, e relê o painel de pedidos do mapa (`services/order-list-overlay.js`, ~0,8 s depois). O painel montava as listas uma vez só e o pedido concluído ficava até atualizar a página. As listas do painel não repetem pedido (o `fetch` põe o pedido novo no store e a leitura do store o devolveria de novo) e "sem motoboy" ignora cancelado, concluído e expirado, como o servidor.
  - O nome do motoboy fica num rótulo fixo embaixo do capacete. Os detalhes saem no clique (popup). O capacete não gira com a direção do GPS (`@disableRotation` do `leaflet-tracking-marker`).
  - A consulta dos pedidos em andamento (`SituacaoDoMotoboy::pedidosEmAndamento`) é a mesma do mapa de motoboys do portal da loja.
  - Teste: `scripts/teste-php/mapa.php`.
  - **Pedidos em andamento = alfinete vermelho no endereço de entrega** (decisão de 2026-10-05; desenho: `docs/superpowers/specs/2026-10-05-pedidos-no-mapa-design.md`).
    - A lista vem em `pedidos`, na mesma resposta de `entregas/mapa/motoboys`, e é relida junto com os capacetes.
    - Ela sai do `App\Support\Entregas\PedidosNoMapa::daCentral`: pedidos não encerrados e atualizados nas últimas 12 h, com ou sem motoboy, até 300.
    - O popup mostra número, loja, status, motoboy, "há X min" e endereço, e tem o botão Abrir pedido.
    - Pedido **aceito** (`aceito` = `orders.started`): o nome do motoboy fica fixo embaixo do alfinete, num rótulo vermelho-escuro (`entregas-nome-no-alfinete`), diferente do rótulo do capacete. Pedido só atribuído pela central fica sem rótulo. Vale também para o portal.
    - Funções puras: `utils/entregas-pedidos-no-mapa.js`.
    - Testes: `scripts/teste-php/pedidos-no-mapa.php` e `scripts/teste-portal/entregas-pedidos-no-mapa.test.mjs`.
- `docker/`: Dockerfile da API, `docker/socket/` (socket ARM) e crontab.

### Build do console

- O Docker usa a raiz como contexto: `docker build -f console/Dockerfile .`. O `console/Dockerfile.dockerignore` inclui só `console` e os pacotes frontend.
  - Padrões com `/` no final excluíam tudo. Valide com `@balena/dockerignore` antes de mexer.
- Build local (Windows): `cd console && DISABLE_RUNTIME_CONFIG=false pnpm build --environment production`, ~10 min.
  - O `packages/ember-ui/index.js` tem uma regex do intl-tel-input ajustada para aceitar `\`.
- Para testar o build local contra a API de produção:
  - grave `dist/fleetbase.config.json` com `API_HOST=https://entregas-api...` e sirva com `npx serve -s dist -l 4200`;
  - o CORS da API aceita `http://localhost:4200`;
  - faça login nessa aba.
- O `~/postcss.config.mjs` da home do Windows atrapalha builds Tailwind v4 em subpastas. O console usa Tailwind 3 e não é afetado.

## Portal da loja

Cada restaurante entra em `https://entregas.restaurantepro.com.br/customer-portal` (login próprio), cria e acompanha os próprios pedidos e vê o extrato, sem enxergar os pedidos de outra loja. Os motoboys continuam recebendo os pedidos de todas, e o mapa do portal mostra todos os motoboys online (ver "Mapa de motoboys"). Desenho: `docs/superpowers/specs/2026-10-02-portal-da-loja-design.md`; plano: `docs/superpowers/plans/2026-10-02-portal-da-loja.md`; situação e pendências: `docs/superpowers/plans/2026-10-03-portal-da-loja-continuacao.md`.

- **Cadastro (só a central):** Fleet-Ops → Recursos → Lojas (`management.lojas`, só admin; API `Entregas/LojasController.php`, `int/v1/entregas/lojas*`).
  - Loja = Vendor `type=customer` + um Place próprio (dono = o Vendor), que é a **coleta fixa**.
  - Usuário da loja = Contact `type=customer` + `VendorPersonnel`. O login é o User `customer` do contato, com senha inicial e e-mail já verificado. Um login, uma loja.
  - Trocar a senha e desativar apagam os tokens. O Fleetbase não barra usuário inativo; quem barra é o `ProtegerPortalLoja`.
  - **Mudou a coordenada da loja? Nasce um Local novo.** Os pedidos antigos ficam com a coordenada antiga (o km deles se acerta à mão), e o Local antigo fica sem dono, com o nome da loja, na lista de Locais.
  - **Não edite o Local da loja pela lista de Locais nem pelo editor de rota do pedido:** isso muda a coleta de todos os pedidos da loja. Use a tela Lojas.
- **Segurança:** middlewares nossos em `api/app/Http/Middleware/`, que rodam antes do código do Composer.
  - `ProtegerPortalLoja` (global): identifica o usuário de loja pelo token Sanctum (Bearer ou `Customer-Token`) e **nega por padrão**, inclusive a API pública `v1/*`.
    - Listas: `PERMITIDAS_INTERNAS` (`int/v1`) e `NEGADAS_NO_PORTAL` (`customer-portal/int/v1`).
    - Perfil só com nome, foto e fuso; upload só da foto (PNG/JPG/WEBP até 5 MB). Usuário desativado não passa, nem no login.
    - Uma chamada necessária do portal voltando 403 aparece no log `[entregas] portal da loja: acesso negado`. Libere só aquele método e caminho.
  - `RegrasPortalLoja` (global):
    - coleta = Local da loja em todo pedido (descarta pickup, payload, paradas, arquivos, meta, agendamento, itens, `internal_id` e `pod_*`);
    - destino = endereço salvo da loja, com coordenadas e a pelo menos 30 m da coleta;
    - despacho como pedido aberto (adhoc) aos motoboys próximos;
    - cancelamento só antes do aceite, atômico, com atividade e evento `OrderCanceled`, e o pedido sai dos abertos;
    - endereço novo só com coordenadas e sem sobrescrever um salvo; `PATCH`/`DELETE places` → 403.
  - `BarrarAceiteDePedidoEncerrado` (grupo `fleetbase.api`, depois da autenticação): pedido encerrado não pode ser aceito (`POST v1/orders/{id}/start` → 400), e o cancelamento da API v1 entra na mesma trava. Compara o nome da ação (`Api\v1\OrderController@startOrder|cancelOrder`): **ao atualizar o fleetops-api, confira se essas ações ainda existem.**
  - `App\Support\Entregas\TravaDoPedido` (Redis) serializa o cancelamento da loja, o da API v1 e o aceite do motoboy. `StatusDoPedido` tem a lista única de status encerrados.
  - `RestringirChaveDoApp` (global, **desligado**): ver "Chave do app do motoboy" abaixo.
- **API do portal** (`Entregas/PortalLojaController.php`, `int/v1/entregas/loja/*`, `throttle:60,1`):
  - `minha-loja`;
  - `extrato`: até 3 meses, com o valor "loja" das faixas;
  - `pedidos/{id}/motoboy`: nome, foto e aceite (a posição fica no mapa de motoboys);
  - `motoboys`: o mapa de motoboys (`App\Support\Entregas\MotoboysNoMapaDaLoja`), com limitador próprio `entregas-loja-mapa` (60 por minuto por usuário).
- **Front** (`packages/customer-portal/addon`):
  - menu enxuto (Início, Pedidos, Extrato, Configurações); as telas fora do escopo redirecionam para Pedidos; membros e login só leitura;
  - novo pedido só com o destino;
  - acompanhamento num ciclo só: motoboy a cada 20 s, detalhe ao mudar ou a cada ~60 s, espera crescente em erro, pausa com a aba oculta;
  - extrato com CSV.
  - **O servidor não tem geocodificação:** o endereço de entrega é marcado no mapa, que abre na loja. Com `@mapCenter`, o `CoordinatesInput` do ember-ui só marca o ponto com arrasto, autocomplete ou Localizar; sem ele (console), nada mudou.
  - O canal de socket `company.<uuid>` não é usado no portal, porque transmite os pedidos de todas as lojas.
- **Mapa de motoboys** (decisão de 2026-10-04; desenho: `docs/superpowers/specs/2026-10-04-motoboys-no-mapa-do-portal-design.md`):
  - a tela Pedidos, no modo Mapa, mostra os motoboys online e os offline que já aceitaram um pedido ainda aberto, com o capacete do console na cor da situação (verde livre, amarelo indo à loja, vermelho a caminho do cliente) e o nome fixo embaixo. Some o offline sem pedido aceito, inclusive com pedido só atribuído pela central (a última posição pode ser a casa dele);
  - **a loja vê as entregas das outras lojas** (risco aceito pelo Edgard);
  - `Workspace::Map` consulta `loja/motoboys` a cada 5 s com a aba visível (espera crescente em erro), e `utils/camada-de-motoboys.js` desliza cada capacete até a posição nova (salta na primeira posição, em pulos de mais de 1 km e com "reduzir animações");
  - o motoboy do pedido aberto fica por cima, com o rótulo azul, e entra uma vez no enquadramento (`definirMotoboyDoPedido` no serviço da rota). O painel "Motoboy" do detalhe não tem mais mapinha;
  - a rota `loja/motoboys` nunca manda o id do motoboy (com ele, o canal `driver.<id>` do socket entrega a posição ao vivo e o telefone): traz só um id opaco (HMAC do uuid), nome, coordenadas, situação e os `public_id` dos pedidos da própria loja. **O detalhe do pedido do portal ainda entrega o motoboy do pedido** (ver "Riscos conhecidos");
  - testes: `scripts/teste-php/mapa-da-loja.php` (php-wasm) e `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs`.
  - **Pedidos da loja no mapa** (decisão de 2026-10-05): alfinete vermelho no endereço de entrega de cada pedido em andamento, **só da própria loja**.
    - O endereço é dado do cliente: a loja nunca vê os pedidos das outras lojas.
    - Os alfinetes vêm em `pedidos`, na resposta de `loja/motoboys` (`PedidosNoMapa::daLoja`, sem o nome da loja e sem id de motoboy), no mesmo ciclo de 5 s dos capacetes.
    - O pedido aberto no detalhe não ganha alfinete, porque já mostra P e D.
    - Funções puras: `utils/pedidos-no-mapa.js`.
- **Chat da loja com os motoboys** (decisão de 2026-10-04; desenho: `docs/superpowers/specs/2026-10-04-chat-da-loja-design.md`):
  - tela Pedidos: botão **Conversas** (com o total de não lidas) abre uma gaveta à direita com as conversas da loja e "Nova conversa" (os motoboys do mapa, pelo id opaco); no detalhe do pedido, **Conversar com o motoboy** abre a mesma conversa com "Pedido <número>: " no texto;
  - **uma conversa por par loja × motoboy**, sobre o chat do Fleetbase (canal marcado no `meta`: `entregas_conversa_loja`/`entregas_conversa_motoboy`), **só com o motoboy e os usuários ativos da loja** (decisão de 2026-10-04: a central não entra sozinha, nem pelo app nem pelo portal; pode ser adicionada depois pelo chat do console, e quem já estava nas conversas antigas continua). O motoboy responde pela aba Conversas do app (push no canal `mensagens`);
  - API: `ConversasDaLojaController` + `App\Support\Entregas\ConversasDaLoja`, `GET|POST int/v1/entregas/loja/conversas` e `GET|POST .../conversas/{id}/mensagens` (até 1000 caracteres; limitador `entregas-loja-conversas`, 120 por minuto por usuário). A loja nunca usa as rotas de chat do Fleetbase (a de participantes lista todos os usuários) e as respostas não trazem id de motoboy, participante ou usuário;
  - front: serviço `entregas-conversas` (lista a cada 20 s na tela Pedidos, inclusive com a aba oculta; conversa aberta a cada 5 s, pausada com a aba oculta; espera crescente em erro), componente `Portal::Conversas`, funções puras em `utils/conversas.js`;
  - **aviso sonoro** de mensagem nova de outra pessoa (`utils/som-de-mensagem.js`, Web Audio, sem arquivo), uma vez por mensagem e nunca na primeira leitura; o navegador só libera o som depois do primeiro clique ou tecla na página;
  - a gaveta fica em `z-index: 1250`, acima da barra superior do portal (`.portal-topbar`, 1200), que escondia o fechar;
  - testes: `scripts/teste-php/conversas-da-loja.php`, `mapa-da-loja.php` (`motoboyDoIdOpaco`) e `scripts/teste-portal/conversas.test.mjs`.
- **Pedido da central:** no formulário do operador, escolher uma loja como cliente põe a coleta no Local da loja e a trava.
  - **Escolha a loja, não o usuário dela.** O contato do usuário também aparece como cliente. Com ele, a coleta não trava e o pedido fica fora do extrato.
- **Ao atualizar o customer-portal-api:** em `customer-portal/int/v1` o padrão do `ProtegerPortalLoja` é liberar, menos o que está em `NEGADAS_NO_PORTAL`. Revise as rotas novas da versão.
- **Configuração do portal** (Admin → Customer Portal): só o tipo `transport`, pagamentos desligados.
- **Teste de isolamento:** `node scripts/teste-isolamento-lojas.mjs`, contra a produção, com `deploy/teste-lojas.env` (ignorado pelo git; o cabeçalho do script explica).
  - Dispara aviso real aos motoboys perto da loja de teste A e deixa endereços de teste salvos.
  - Use **janela anônima** no portal e também no Admin → Customer Portal logo depois do deploy. O console guarda a lista de extensões no localStorage por 1 h (só invalida quando muda a versão do console), e o Ctrl+Shift+R não limpa o localStorage.
- **Chave do app do motoboy** (`ENTREGAS_CHAVE_APP_MOTOBOY`, **desligada**): restringe ao login a chave pública `flb_live_` que vai no APK.
  - Só ligar depois de um APK novo em todos os celulares: o Driver criado com o token do motoboy e o `useFleetbase` síncrono no `entregas-navigator`.
  - Os passos e a checagem com `curl` estão no docblock do `RestringirChaveDoApp.php`.
- **Riscos conhecidos:**
  - o socket não autentica a inscrição nos canais `company.*`;
  - a chave `flb_live_` do APK funciona enquanto a restrição estiver desligada;
  - o login por SMS não limita tentativas e o código de 6 dígitos não expira. Quem extrai a chave pode chegar a um token de motoboy, que lista os pedidos de todas as lojas;
  - o upload do core aceita `disk`/`path` de usuários que não são de loja;
  - a loja vê todos os motoboys online, com nome completo e situação, inclusive os que levam pedidos de outras lojas: um capacete vermelho parado numa casa indica o endereço de um cliente de outra loja;
  - o motoboy que esquece de ficar offline ao fim do expediente continua no mapa das lojas, possivelmente em casa (o app rastreia mesmo fechado). A central orienta os motoboys;
  - pedido esquecido aberto: o motoboy que o aceitou continua no mapa das lojas mesmo offline, por até 12 h da última atualização do pedido (mesma regra do mapa do console);
  - **o pedido do portal entrega o motoboy do pedido** (achado na revisão de 2026-10-04, anterior ao mapa de motoboys; pelo código do core-api 1.6.61 e do customer-portal-api 0.0.13). Rota com segmento `int` é "interna" para o Fleetbase, então a lista e o detalhe de `customer-portal/int/v1/orders` trazem o `driver_assigned_uuid`. O detalhe roda o tracker, que carrega o `driverAssigned` na mesma instância do pedido, e por isso traz também o `driver_assigned` completo (nome, telefone, e-mail e posição atual) e o `tracker_data.driver.location`. Vale para qualquer pedido da loja, inclusive concluído, e com o uuid a loja pode assinar `driver.<uuid>` no socket. O front do portal não usa esses campos: a correção seria tirá-los da resposta para o usuário de loja (no `RegrasPortalLoja`, depois do `$next`). **Decisão do Edgard (2026-10-04): fica como está; não corrigir sem perguntar.**
- **Armadilhas do Fleetbase achadas aqui:**
  - `Contact::user()` filtra pelo `type` da instância e falha em `with`/`whereHas`: use `Contact::anyUser`.
  - O `CompanyScope` existe, mas **não** está registrado nos models desta versão: filtre `company_uuid` de forma explícita.
  - `orders.customer_type` varia com e sem a barra inicial: a loja do pedido é o `customer_uuid`.
  - O `cancelOrder` do portal só troca o status (sem atividade nem evento), e o `startOrder` da API v1 não confere cancelamento: daí a trava e o `BarrarAceiteDePedidoEncerrado`.

## Integração iFood (etapa 2: entrada dos pedidos)

Os pedidos iFood dos restaurantes entram pelo módulo **Logistics** da Merchant API: app **distribuído**, entrega própria (`deliveredBy: MERCHANT`).

- **Onde ler mais:**
  - desenho e decisões: `docs/superpowers/specs/2026-10-05-integracao-ifood-logistics-design.md`;
  - referência da API: `docs/ifood/referencia-logistics.md` (a seção "Descobertas da sonda" vale mais que o resto);
  - planos `docs/superpowers/plans/2026-10-05-ifood-etapa-2a-servidor.md` e `-2b-tela-lojas.md`, executados com ajustes de revisão: **o código é a referência**.
- **Situação:** etapas 2 e 3 em produção e testadas com a loja de teste em 2026-10-06 (plano da 3: `docs/superpowers/plans/2026-10-06-ifood-etapa-3-ciclo.md`). Etapa 4 (APK, console e portal; plano `2026-10-06-ifood-etapa-4-app-console.md`) implementada em 2026-10-06 nos ramos `ifood-etapa-4` deste repo e do `entregas-navigator`, ainda sem push, deploy e teste real (ver "Etapa 4: APK, console e portal").
- **Não vincule loja real antes do APK da etapa 4 em todos os celulares** e da trava "Atualize o app" ligada (ver "Integração iFood (etapa 3: ciclo da entrega)"). Até lá, só a loja de teste.
- **O pedido de teste (`isTest`) vai aos motoboys como o real** (decisão de 2026-10-06): os motoboys online perto da loja de teste recebem o alarme, com o endereço falso "RUA TESTE" (entrega ~1 km ao norte). Avise-os antes de gerar um pedido de teste. Desde a etapa 3 ele também pede o código de entrega quando o iFood exige (a central pode usar "Liberar sem código").

### Como ligar

Nesta ordem:

1. Preencha o `stack.env` com `ENTREGAS_IFOOD=1`, `IFOOD_CLIENT_ID` e `IFOOD_CLIENT_SECRET`, as credenciais do app **distribuído** (Portal do Desenvolvedor → Meus Apps → Credenciais), e faça Stacks → entregas → Editor → Update the stack ("Re-pull image" desligado).
   - Os três são lidos em `config('services.ifood')`. O `ClienteIfood::ligada()` exige os três preenchidos.
   - O Update the stack já recria a API, a fila e o scheduler, porque as variáveis mudaram. Reiniciar à mão é opcional: `docker service update --force entregas_queue && docker service update --force entregas_queue-ifood && docker service update --force entregas_scheduler`.
2. Se houver código novo, rode `bash deploy/atualizar.sh api`: ele roda as migrations (as três tabelas) e o `config:cache`.
- **Desligada:**
  - os três comandos nem sobem (`when(ClienteIfood::ligada())` no `Kernel`; cada comando confere de novo);
  - a tela Lojas esconde os botões;
  - código e vínculo respondem 409. Desvincular funciona sempre.

### Vínculo (tela Lojas)

Fleet-Ops → Recursos → Lojas, só admin. API: `IfoodLojasController`, `int/v1/entregas/lojas/{id}/ifood/*`, limitador `entregas-ifood-vinculo` (20 por minuto por usuário) no pedido do código e no vínculo (`POST .../ifood/codigo` e `POST .../ifood/vincular`). O `DELETE .../ifood` (desvincular) não tem limitador.

- **Vincular iFood** abre o modal (`modals/vincular-ifood`):
  1. `POST .../ifood/codigo` traz o código de vínculo (userCode), o link do Portal do Parceiro com o código preenchido (só vira botão se for https de `*.ifood.com.br`) e a contagem de 10 min;
  2. o dono da loja autoriza no Portal do Parceiro e passa o código de autorização à central, que cola no campo (`POST .../ifood/vincular` com `authorizationCode`);
  3. conta com várias lojas: a central escolhe uma (`POST .../ifood/vincular` com `merchant_id`).
- **Selo no card:** "Vinculada · <loja no iFood>", "Vínculo perdido" em vermelho (com os botões "Vincular de novo" e "Desvincular iFood") ou "—". Os botões só aparecem com `ifood_ligado` na lista.
- **Desvincular iFood** (com confirmação; `DELETE .../ifood`) apaga os tokens e libera o merchant para outra loja.
- Front: controller e template de `management/lojas`, modal `components/modals/vincular-ifood.*` e funções puras em `packages/fleetops/addon/utils/vinculo-ifood.js`.
- **Um merchant, uma loja** (checagem e chave única).
- **Coleta = Local da Loja**, não o endereço do iFood. A mais de 300 m dele, o log avisa (`coleta diverge do iFood (N m)`).
- **O código de autorização vale uma vez.** Os tokens da troca ficam 10 min no cache, cifrados:
  - se a lista de lojas falhar por rede ou 5xx, clicar de novo em Vincular usa esses tokens, sem código novo;
  - se a lista for recusada (400, 401, 403), é preciso um código novo.
- **Erros**, sempre `{"errors": ["…"]}`:
  - 422: validação, código vencido ou recusado, merchant já em outra loja, conta sem lojas;
  - 422 também no pedido do código, quando o iFood recusa as credenciais do app ("Confira as credenciais");
  - 409: integração desligada;
  - 502: iFood fora (rede, 429, 5xx, resposta incompleta).

### Código

Em `api/app/Support/Entregas/Ifood/`:

- `ClienteIfood`: única porta HTTP, com erros tipados (`ErroIfood`; rede = status 0);
- `VinculosIfood`: vínculo, tokens cifrados com `encrypt()` e renovação;
- `EventosIfood` e `PedidoDoIfood`: funções puras;
- `CriadorDoPedidoIfood`: adaptador sobre os models do Fleet-Ops.

Fora dessa pasta:

- job `App\Jobs\Entregas\ProcessarPedidoIfood`;
- comandos `entregas:ifood-polling` (a cada 30 s), `entregas:ifood-agendados` (a cada minuto) e `entregas:ifood-tokens` (a cada 30 min), em `Console/Commands/Entregas/`. No `Kernel`, os três rodam em segundo plano, com `withoutOverlapping`.

### Fluxo do pedido

1. **Polling** (`entregas:ifood-polling`):
   - agrupa as lojas vinculadas pelo token (até 100 por chamada) e chama com `excludeHeartbeat=true`;
   - grava em `entregas_ifood_eventos` (o `evento_id` único descarta os repetidos) e **só depois** manda o ack. Banco fora = sem ack, e o iFood reenvia;
   - enfileira um `ProcessarPedidoIfood` por pedido com evento pendente.
2. **Job** (trava `entregas:ifood-pedido:<id do iFood>`): processa os pendentes em ordem de `createdAt`.
   - O pedido nasce no **PLC** ou, se o PLC se perdeu, num evento anterior à coleta (CFM, RTP, DDCR, DPCR).
   - **Não nasce** se o pedido tem CAN ou já passou da coleta (DSP, CON, CLT, DDD, AAD, DDCS, GTO, ADR, AAO), procurando entre todos os eventos gravados dele. Também não nasce só com eventos de entrega, cancelamento ou alteração.
3. **Criação** (`GET logistics/orders/{id}`), numa transação só:
   - Place da entrega com as coordenadas do iFood, sem dono;
   - Payload com a coleta no Local da loja;
   - Order: cliente = Vendor da loja, tipo `transport`, `internal_id` = número do iFood, notas "iFood #4821";
   - a linha de `entregas_ifood_pedidos`. O `pedido_ifood_id` único garante que nunca nascem dois pedidos.
4. **Despacho como o do portal:** adhoc + `firstDispatchWithActivity`, com a `TravaDoPedido` e o pedido relido.

- **Pedido de teste (`isTest`):** "[TESTE]" nas notas e a entrega ~1 km ao norte da loja (o iFood manda 0,0). Despacha como o real: imediato na hora, agendado com janela 40 min antes, e o `entregas:ifood-agendados` o recupera se o despacho falhar.
- **Sem despacho**: nasce com adhoc falso e a central atribui. Cada caso deixa uma marca nas notas:
  - pedido real sem coordenadas válidas (ausentes, 0, fora da faixa ou a mais de 50 km da coleta): "[SEM LOCALIZAÇÃO]", com o mesmo deslocamento e um warning no log;
  - agendado sem janela legível: "[AGENDADO SEM HORÁRIO]", com o log `agendado sem janela`.
- **Agendado:** `scheduled_at` = `despachar_em` = início da janela − 40 min. Se faltar menos que isso, despacha na hora.
  - Quem despacha é o `entregas:ifood-agendados` ou o `fleetops:dispatch-orders`, o que vier primeiro.
  - Se a central atribuiu um motoboy antes do horário, o agendador desliga o adhoc e despacha só para ele.
- **`entregas:ifood-agendados`:**
  - refaz o despacho imediato que falhou no job (com 1 min de folga);
  - pedido encerrado, aceito ou apagado sai da fila;
  - 30 min depois do `despachar_em` sem conseguir, desiste: tira da fila, grava o log `despacho desistiu` e toca no console o aviso sonoro "sem motoboy" (`entregas.pedido_sem_motoboy`, uma tentativa).
- **Outros eventos:**
  - DDCR marca `exige_codigo`;
  - CAN cancela o pedido no Entregas (etapa 3, `CancelamentoPeloIfood`; ver "Integração iFood (etapa 3: ciclo da entrega)") e tira a linha da fila do agendador (`despachar_em` nulo);
  - os outros códigos conhecidos só ficam registrados;
  - código desconhecido fica como ignorado, com warning no HSD (exige resposta da loja no iFood) e info nos outros.
- **Cobrança:**
  - sem `payments` = pago online;
  - o `payments.pending` explícito manda (0 = nada a cobrar). Sem ele, vale a soma dos métodos não pagos;
  - a forma vem do método não pago; o troco só vale para dinheiro;
  - formato divergente ou valor implausível (acima de R$ 100 mil) vai para o log `pagamento inconsistente`;
  - o formato com cobrança na porta ainda é para conferir na homologação.

### Tabelas

As migrations ficam em `api/database/migrations`.

- `entregas_ifood_lojas`: o vínculo. Tokens cifrados (APP_KEY); `situacao` = `vinculada` | `vinculo_perdido` | `desvinculada`.
- `entregas_ifood_eventos`: um registro por evento. Uma vez por dia, a limpeza apaga os processados há mais de 7 dias e os pendentes há mais de 30.
- `entregas_ifood_pedidos`: dados do iFood de cada pedido (0800, cobrança, observações, `exige_codigo`, `cancelado_pelo_ifood_em`, `teste`, `agendado`, `despachar_em`, `despachado_em`). Fica fora do `meta` do Order de propósito.
- Datas em TIMESTAMP, gravadas como texto no fuso do app (`PedidoDoIfood::paraOBanco`, `EventosIfood::paraOBanco`): as contas com o iFood (ISO com `Z`) são feitas em UTC e só a saída é convertida. Ver "Fuso (horário de Brasília)".

### Tokens e erros

- **Renovação**, com trava por loja e compare-and-set:
  - antes de usar, quando o token vence em menos de 5 min;
  - pelo `entregas:ifood-tokens`, que renova os que vencem em menos de 1 h;
  - no 401: renova e repete uma vez.
- **Refresh recusado pelo `/oauth/token` (400 ou 401)** = `vinculo_perdido`:
  - grava o log `vínculo perdido`, apaga o access token e **mantém o refresh cifrado** (para reativar);
  - um 401 ali pode ser credencial errada no `stack.env`, e não o dono revogando;
  - os outros erros da renovação (403, 404, 408, 409, 429, 5xx, rede, trava ocupada) não derrubam a loja.
- **Token ilegível** (APP_KEY trocado ou texto corrompido): a loja vai para `vinculo_perdido` com o texto cifrado mantido, e sai o log `token ilegível (APP_KEY mudou?)`.
- **429** (polling ou ack): pausa todas as lojas do token pelo `Retry-After` (até 300 s; sem cabeçalho, 60 s). A pausa fica no cache `entregas:ifood-polling-pausa:<id do vínculo>`. No job, o 429 devolve o pedido para a fila pelo `Retry-After`.
- **403 no polling:** as lojas do lote listadas em `unauthorizedMerchants` viram vínculo perdido. Sem lista legível, o lote fica 5 min em pausa.
- **Limites da rodada:**
  - nenhum lote novo depois de 25 s;
  - para depois de 3 falhas temporárias seguidas;
  - o cursor `entregas:ifood-polling-cursor` alterna o ponto de partida.
- **Job:**
  - prazo de 30 min (`retryUntil`), até 6 exceções, espera de 15 s a 5 min;
  - 404, 5xx e rede tentam de novo;
  - 400, 403 ou 401 depois da renovação, no GET do pedido, falham na hora (os eventos ficam pendentes para a varredura);
  - ao desistir, grava o log `pedido não processado`.
- **Varredura** (em toda rodada do polling):
  - o pedido com evento pendente gravado entre 2 min e 6 h atrás volta para a fila (até 50 por rodada; marca de 35 min, depois 2 h e 4 h);
  - com mais de 6 h, só um warning por pedido (`evento pendente há mais de 6 h`), para a central conferir.
- **Reativar lojas caídas por credencial errada ou APP_KEY revertido:**
  1. corrija o `stack.env` e faça o Update the stack;
  2. rode `UPDATE entregas_ifood_lojas SET situacao='vinculada' WHERE situacao='vinculo_perdido' AND refresh_token IS NOT NULL;` no banco (`docker exec -it $(docker ps -q -f name=entregas_database) mysql -uroot -p fleetbase`).
  - Sem access token, a renovação usa o refresh. Refresh vencido ou revogado derruba a loja de novo, e aí só um vínculo novo resolve.

### Logs

Todos com o prefixo `[entregas] ifood:` e só com ids, códigos e o número do pedido.

- **Comandos** (polling, agendados, tokens): `docker service logs entregas_scheduler 2>&1 | grep '\[entregas\] ifood'`. Eles rodam em segundo plano e escrevem em `/proc/1/fd/1` (`Kernel::SAIDA_DO_CONTAINER`).
- **Job** (criação, eventos, `coleta diverge`, `pagamento inconsistente`): `docker service logs entregas_queue-ifood` (a fila própria do iFood; o `entregas_queue` também pega jobs iFood quando está livre, então confira os dois).
- **Vínculo pela tela** (`vínculo falhou`, `loja vinculada`): `docker service logs entregas_application`.

### Armadilhas

- **Trava do `withoutOverlapping`:** se o processo morrer segurando a trava, ela segura o comando por até 5 min (polling e agendados) ou 10 min (tokens).
  - `cache:clear` não solta a trava, porque as travas ficam na conexão Redis `default` (`lock_connection` do store `redis`).
  - Espere vencer, ou rode `php artisan schedule:clear-cache` no scheduler.
- **`appendOutputTo('/proc/1/fd/1')` depende do go-crond como PID 1 rodando como root.** Sem isso, o redirecionamento falha, e o comando pode nem rodar.
- **Redis sem persistência** (`--save "" --appendonly no`): um restart perde os jobs da fila. Os eventos continuam pendentes no MySQL, e a varredura enfileira de novo depois de 2 min.
- **Fila própria do iFood** (2026-10-06, `App\Support\Entregas\Ifood\FilaIfood`): o `ProcessarPedidoIfood` e o `EnviarAcaoIfood` vão para a fila `ifood`, atendida pelo serviço `queue-ifood` (`queue:work --queue=ifood`). O `queue` atende `default,ifood`: push e notificações primeiro, iFood só quando está livre (e assim nada para se o `queue-ifood` não existir). Teste: `scripts/teste-php/fila-ifood.php`.
  - **`after_commit` na conexão `redis`** (`api/config/queue.php`): job ou broadcast disparado dentro de uma transação só entra na fila depois do commit (e some se ela desfizer). Sem isso, com dois workers, o broadcast do pedido criado pelo `CriadorDoPedidoIfood` (o `Order::create` o põe na fila dentro da transação) podia ser lido antes do commit.
  - O serviço novo só existe depois de colar o `deploy/docker-stack.yml` no Portainer (Stacks → entregas → Editor → Update the stack, "Re-pull image" desligado). O `atualizar.sh` avisa quando ele não existe.
- **A conferir no primeiro vínculo real:** o nome do campo do refresh token (tratamos `refreshToken` e `refresh_token`). Sem ele, o log `resposta do token sem refresh token` lista os campos que vieram, e o vínculo cai quando o token vencer (6 h).
- **A conferir no primeiro agendado:** o formato do `schedule`.

### Riscos que ficam

- **Agendado com motoboy atribuído pela central antes do horário:** o `fleetops:dispatch-orders` roda no mesmo minuto e pode despachar antes, com o adhoc ainda ligado.
  - Aí todos os motoboys livres do raio recebem o aviso, e qualquer um pode tomar o pedido.
  - Ao atribuir um agendado, desligue o "pedido aberto".
- **Pedido sem coordenadas ou agendado sem janela** fica `created`, sem aviso à central além das notas e do log.
- **Pedido que entra já depois da coleta** (integração parada) não é criado; fica só o warning `pedido já passou da coleta`.

### Ao atualizar o Laravel, o core-api ou o fleetops-api, confira

- no agendador: `everyThirtySeconds` (Laravel 10.15+), `runInBackground`, `when` e `appendOutputTo`;
- no Order, os métodos que o `CriadorDoPedidoIfood` usa: `firstDispatchWithActivity`, `insertDispatchActivity`, `hasDispatchedStatus`, `saveQuietly` e `fresh`;
- `OrderConfig::default()` e o `TrackingNumberObserver`, que leem a empresa da sessão;
- `Payload::setPickup`/`setDropoff`/`setCurrentWaypoint` e `Utils::getMutationType`;
- o `HandleOrderDispatched`: com adhoc, avisa todos os motoboys livres do raio; sem adhoc e com motoboy, só o atribuído;
- a janela de ±1 min do `fleetops:dispatch-orders`;
- o `handle()` dos comandos agendados trocados por causa do fuso (ver "Fuso (horário de Brasília)").

### Testes

- `scripts/teste-php/ifood-*.php` (php-wasm):
  - stubs `stubs-ifood.php` e `stubs-ifood-fleetbase.php`;
  - o banco em memória conhece as colunas das migrations e recusa coluna inexistente (`ifood-stub-banco.php`);
  - pedidos fictícios em `fixtures-ifood.php`.
- Funções da tela Lojas: `scripts/teste-portal/vinculo-ifood.test.mjs`.

## Integração iFood (etapa 3: ciclo da entrega)

Desenho: spec, seções 3 e 4. Plano: `docs/superpowers/plans/2026-10-06-ifood-etapa-3-ciclo.md` (executado com ajustes de revisão: **o código é a referência**). Tudo em `api/app` (nada em `packages/*/server` chega à produção).

### Ações de logística

- O servidor informa ao iFood cada etapa: motoboy definido → `assignDriver` (nome e telefone do cadastro, só dígitos, sem o 55, `MOTORCYCLE`); `started` → `goingToOrigin`; a até 100 m da coleta pelo GPS → `arrivedAtOrigin`; `enroute` ("A caminho") → `dispatch`; a até 100 m da entrega pelo GPS (ou a conclusão) → `arrivedAtDestination`.
- **Gancho:** `Order::updated` (Eloquent, só com mudança em `driver_assigned_uuid`, `started` ou `status`) e `OrderDriverAssigned` (atribuição em lote), registrados no `AppServiceProvider::acompanharPedidosIfood` → `ObservadorDosPedidosIfood` → job `EnviarAcaoIfood` (`afterCommit`). O observador nunca lança. O `scheduleOrder` do console (`saveQuietly`) e jobs perdidos ficam com a reconciliação.
- **Um job por pedido de cada vez:** `EnviarAcaoIfood::enfileirar` põe a marca `entregas:ifood-acao-pendente:<uuid>` no cache (120 s; volta junto com o `release()` e some no `failed()`); o job apaga a marca antes de ler o pedido.
- **`AcoesIfood::sincronizar`** (`SequenciaIfood`, funções puras; trava `entregas:ifood-acao:<uuid>`, 90 s): o job **não recebe a ação**. Relê o pedido, calcula o alvo pelo estado e envia, em ordem, o que falta depois da `ultima_acao` (a única fonte do estado: o iFood não devolve evento das nossas ações). Cada ação aceita grava a `ultima_acao` na hora.
  - **Orçamento de 45 s** no laço do job (20 s nas rotas síncronas `concluir-ifood`/`codigo-ifood`, `ConclusaoIfood::ORCAMENTO_NA_ROTA`): o resto volta como `incompleto` (o job volta para a fila em 1 s; a rota responde "tente de novo").
  - **409 numa ação da sequência = já aceita** (reenvio depois de resposta ou gravação perdida): avança a `ultima_acao` e registra `[entregas] ifood: ação já aceita pelo iFood (409)` (info), sem recusa nem aviso. Um 409 por ordem errada também avança.
  - **Troca de motoboy** (`motoboy_no_ifood` diferente do atribuído): `assignDriver` de novo. A documentação não diz se o iFood aceita depois do `goingToOrigin`. O 409 da troca é recusa com aviso à central **uma vez só**: o motoboy novo fica como marca (o iFood continua com o anterior) e o `dispatch` e o `arrivedAtDestination` seguem. Outra recusa na troca (400, vínculo, cadastro) para a sequência.
  - **Recusa** (4xx que não seja o 409 da sequência, vínculo perdido, motoboy sem nome ou telefone): grava `recusa_acao`/`recusa_status`/`recusa_em`, log `[entregas] ifood: ação recusada` (ação, status e só `errorType`/`code`/`description`, nunca o corpo cru; no `assignDriver`, nome, telefone e sequências de 6+ dígitos saem mascarados) e aviso no console (`entregas.ifood_acao_recusada`, `IfoodAcaoRecusada`). Uma recusa não se repete sozinha: a próxima mudança do pedido tenta de novo, e uma ação aceita limpa a recusa.
  - **Falha temporária** (429, 408, 5xx, rede): 429 volta pelo `Retry-After`; o resto, até 5 tentativas (10 s a 5 min) em 30 min. Esgotadas, `failed()` registra `ação não enviada; tentativas esgotadas` e a próxima ação vira recusa com aviso (`AcoesIfood::desistir`, trava com 2 s de espera; sai sem gravar se já há recusa).
  - O corpo do `assignDriver` leva `#[\SensitiveParameter]` (fora do stack trace); ação sem corpo vai sem corpo.
- **`entregas:ifood-acompanhar`** (30 s, `withoutOverlapping(5)`, em segundo plano): chegada pelo GPS (`ChegadaPeloGps`, `drivers.location` × coleta/entrega, raio de 100 m) e reconciliação (estado além da `ultima_acao`, ou motoboy trocado).
  - O filtro é no SQL, antes do limite de 300 por rodada: join com `orders`, sem cancelado pelo iFood, sem recusa, sem `arrivedAtDestination`, Order não encerrado (exceto o concluído) e não apagado.
  - Janela de 24 h pelo `despachado_em` ou pelo `created_at` (o agendado criado há mais de 24 h entra pelo despacho). Migration `2026_10_06_130000_add_indice_acompanhar_*` (índice em `created_at`).
  - **Posição do motoboy com mais de 5 min** (`drivers.updated_at`: app fechado, sem sinal) não vale como chegada.

### Cancelamento

- **CAN** (`CancelamentoPeloIfood`, chamado pelo `ProcessarPedidoIfood`, com a `TravaDoPedido` e a trava das ações): concluído → nada muda (log); nos outros, sai dos abertos e do agendamento (`saveQuietly`: `dispatched` e `adhoc` falsos, `scheduled_at` nulo) e `Order::cancel()` (atividade, `OrderCanceled` → push ao motoboy atribuído, "Pedido #4821 cancelado pelo iFood"). Sem Order: só grava e registra (info). Idempotente: o job repete enquanto o CAN estiver pendente (trava ocupada).
- **`pago_mesmo_cancelado`** quando o `dispatch` já foi aceito (`ultima_acao`) **ou** o Order está `enroute` (o motoboy tocou "A caminho", mesmo que o `dispatch` não tenha chegado ao iFood). O push acrescenta "Você recebe por esta entrega. Combine com a loja a devolução." e o pedido entra no relatório, na cobrança da loja e nos ganhos do app **pela data do cancelamento** (`CalculoEntregas::pedidosConcluidos`, `cancelado_pago` na linha).
  - O extrato do portal da loja repassa o `cancelado_pago` (`PortalLojaController::extrato`) e mostra o selo "Cancelado pelo iFood (cobrado)" na linha (coluna Situação) e uma coluna com o mesmo rótulo no CSV (vazia nas demais).
- Exceção no cancelamento sobe como `RuntimeException` só com a classe e o SQLSTATE (o `failed_jobs` guarda a exceção inteira, e a do banco traz o SQL); `LockTimeoutException` passa como veio.
- **Cancelamento do nosso lado é proibido** no pedido iFood (400 "Pedido do iFood: o cancelamento é feito no iFood."): `RegrasPortalLoja` (portal) e `RegrasDoPedidoIfood` (API v1 e console):
  - API v1: `cancelOrder`, a atividade "canceled" no `updateActivity` e o `PUT v1/orders/{id}` (`update`) com `status` de cancelamento;
  - console: `cancel`, `bulkCancel`, a atividade "canceled" (inclusive o arrastar do quadro) e o `PUT/PATCH int/v1/orders/{id}` (`updateRecord`) com `order.status` de cancelamento.
  - Com problema do motoboy, a central troca o motoboy.
  - **Saída da central:** o cancelamento pelo console e pela API v1 passa quando a linha já tem `cancelado_pelo_ifood_em` (o iFood já cancelou: CAN que não cancelou o Order, ou cancelamento local que falhou) **ou** com a integração desligada (`ENTREGAS_IFOOD=` vazio, desligamento de emergência). Fica no log `cancelamento liberado no pedido do iFood`. O portal da loja continua barrado sempre.
  - O `RegrasDoPedidoIfood` só consulta o pedido quando a ação pode ser barrada (cancelamento, ou conclusão com a trava ligada): a `update-activity` comum do app passa sem ir ao banco.

### Conclusão e código de entrega

- App (APK da etapa 4): `POST v1/entregas/motoboy/pedidos/{id}/concluir-ifood` (`ConclusaoIfood::concluir`) garante o `arrivedAtDestination` na hora. Recusa dessas ações não prende o motoboy (a central já foi avisada). Com `exige_codigo` (DDCR) responde `precisa_codigo`; senão libera. iFood fora do ar, trava ocupada ou ação por enviar: 503 "tente de novo".
- `POST .../codigo-ifood` (3 a 10 dígitos) confere o código na hora (`verifyDeliveryCode`): 400/422 cuja `description`/`message`/`code` diz código inválido ("Confirmation code is invalid", "Invalid delivery code", "code does not match") ou `success: false` num 2xx = `codigo_incorreto` (HTTP 422, conta para o teto); outro 4xx (inclusive 409 e 412) = 409 `codigo_nao_conferido` ("Não foi possível conferir o código neste pedido. Peça à central para liberar.", não conta para o teto); 408, 429, 5xx e rede = 503 "tente de novo". O log leva só `errorType`/`code`/`description` (`AcoesIfood::resumoDoErro`), com o código digitado mascarado. Certo (ou sem código exigido) grava `conclusao_liberada_em` e o app conclui pelo `update-activity` de sempre.
  - Com a trava das ações, a linha é relida antes do `verifyDeliveryCode`: já liberada (dois cliques, ou a central liberou) → `pode_concluir` sem chamar o iFood.
  - **Teto de 10 códigos errados por pedido** (contador no cache `entregas:ifood-codigo-erros:<uuid>`, 3 dias; iFood fora do ar não conta): depois dele, 429 `{"resultado": "muitas_tentativas"}` ("Muitas tentativas: peça à central para liberar.") sem chamar o iFood. Vale além do limitador `entregas-ifood-codigo` (10 por minuto por motoboy).
- **Pedido de teste também pede o código** (o Gestor de Pedidos pediu no teste): segue o `exige_codigo`. De onde tirar o código de um pedido de teste fica a conferir. Sem ele, a central usa **"Liberar sem código"** no painel iFood do console (`POST int/v1/entregas/pedidos/{id}/ifood/liberar-sem-codigo`, só admin): `conclusao_sem_codigo`, log `[entregas] ifood: conclusão sem código liberada pela central`; o iFood conclui sozinho 4 h depois. Já liberado, não grava nem registra de novo. Concluir pelo console um pedido que exigia código também marca `conclusao_sem_codigo` (warning no log).
- **Trava "Atualize o app"** (`ENTREGAS_IFOOD_EXIGE_APP_NOVO=1` no `stack.env`, **desligada por padrão**): a conclusão comum de pedido iFood pela API v1 (`updateActivity` que conclui, `completeOrder`) sem `conclusao_liberada_em` responde 400 "Atualize o app para concluir pedidos do iFood.". A conclusão pelo console passa. **Ligue só depois do APK da etapa 4 em todos os celulares**; com ela desligada, o APK antigo conclui sem código (o iFood conclui sozinho 4 h depois).
- **Não vincule loja real antes do APK da etapa 4 em todos os celulares.** Sem ele, o motoboy não vê o valor a cobrar na porta nem confere o código do cliente, e a trava não pode ser ligada. Até lá, só a loja de teste.
- `GET v1/entregas/motoboy/pedidos/{id}/ifood` (`DadosIfoodDoMotoboy`): número, cobrança (texto da `CobrancaIfood`; o troco aparece sempre que existe, inclusive com "MISTO"; `GIFT_CARD` = "vale-presente", `OTHER` = "outra forma"), observações, complemento, referência, código exigido, conclusão liberada, cancelamento; 0800 e localizador só para o motoboy do pedido, em andamento e antes de expirar. O alarme leva `entregas_ifood` e `entregas_cobrar`.
- Painel do console: `GET int/v1/entregas/pedidos/{id}/ifood` (só admin; `IfoodPedidosController`; ações pelo código, traduzidas no console).

### Colunas novas e logs

- `entregas_ifood_pedidos`: `ultima_acao`, `motoboy_no_ifood`, `recusa_acao`/`recusa_status`/`recusa_em`, `conclusao_liberada_em` e `conclusao_sem_codigo` (migration `2026_10_06_120000_add_ciclo_*`).
- Fila (`docker service logs entregas_queue-ifood 2>&1 | grep '\[entregas\] ifood'`; o `entregas_queue` também pega jobs iFood quando está livre): `ação enviada`, `ação já aceita pelo iFood (409)`, `ação recusada`, `ação não enviada; tentativas esgotadas`, `pedido cancelado pelo iFood`, `pedido concluído sem o código do cliente`.
- Scheduler (`entregas_scheduler`): `chegada pelo GPS`, `falha ao acompanhar o pedido`.
- Aplicação (`entregas_application`): `código de entrega conferido`/`incorreto`/`não conferido pelo iFood`, `falha ao conferir o código de entrega`, `conclusão sem código liberada pela central`, `ação barrada no pedido do iFood` (API v1, console e portal da loja; só ids nossos), `cancelamento liberado no pedido do iFood`, `conclusão sem resposta do iFood`.

### Armadilhas da etapa 3

- **O `LogApiRequests` do core grava o corpo das chamadas da API v1**, inclusive o `POST v1/entregas/motoboy/pedidos/{id}/codigo-ifood` (o código de entrega) e o token, na tabela `api_request_logs` (Developers → Logs, só admin). O código vale uma vez, mas quem tem acesso aos logs o enxerga.
- **O `internal_id` do pedido pode repetir entre pedidos** (é o número do iFood). O app e as rotas do motoboy usam o `public_id`; a busca do `RegrasDoPedidoIfood` aceita uuid, `public_id` e `internal_id`, sempre filtrada pela empresa da sessão.
- **Ação recusada para a sequência:** uma ação recusada antes (ex.: `dispatch` com 400) impede o `arrivedAtDestination` de ir na ordem. O motoboy segue para o código, e a central usa "Liberar sem código" no painel iFood.
- Ação sem corpo (ex.: `goingToOrigin`) vai com o `send('POST')` do Laravel e `Content-Type: application/json`: **a conferir na produção** (400 ou 415 → trocar por POST sem cabeçalho de corpo).
- **Fila própria do iFood** (resolvido em 2026-10-06): os jobs iFood têm o worker `queue-ifood`; ver "Armadilhas" da etapa 2.
- **A conferir (Task 15 do plano):** troca de motoboy depois do `goingToOrigin`; raio de 100 m; origem do código de um pedido de teste; formato do `workerPhone`; `fleetbase.protected` com o `RegrasDoPedidoIfood` (cancelar pelo console deve dar 400).

### Ao atualizar o fleetops-api, confira

- que todo status ainda passa por `setStatus(…, true)` → `save()` e que o `startOrder` grava `started` com `save()` (gancho `Order::updated`);
- o `OrderDriverAssigned` e o `NotifyBulkAssignedDriver`;
- os nomes das ações barradas (`Api\v1\OrderController@cancelOrder|update|updateActivity|completeOrder`, `Internal\v1\OrderController@cancel|bulkCancel|updateActivity|updateRecord`) e o corpo (`order`, `ids`, `activity.code`, `status`, `order.status`);
- que o `Order::cancel()` ainda não tira o motoboy e que o `HandleOrderCanceled` notifica o atribuído;
- (etapa 4) as ações `Internal\v1\OrderController@queryRecord` e `Internal\v1\LiveController@orders` e se o recurso `Http\Resources\v1\Index\Order` passou a trazer `notes` (aí o `IncluirNotasNaListaDePedidos` sobra; ele não sobrescreve um `notes` que já venha).

### Testes da etapa 3

`scripts/teste-php/ifood-sequencia.php`, `ifood-cliente-acoes.php`, `ifood-acoes.php`, `ifood-acompanhar.php`, `ifood-cancelamento.php`, `ifood-regras.php`, `ifood-conclusao.php` e `ifood-ligacoes.php` (php-wasm), além de `ganhos-motoboy.php` e `cartao-do-alarme.php`.

### Etapa 4: APK, console e portal

- **Pedido iFood na tela = notas "iFood #N" (com ou sem marcas, ex. " [TESTE]") + `internal_id` N** (`packages/fleetops/addon/utils/pedido-ifood.js`; no portal, `numeroIfood` em `customer-portal/addon/utils/entregas-pedido.js` e o helper `numero-ifood`). Nada novo no `meta`. O servidor confere de verdade (`RegrasDoPedidoIfood`, `RegrasPortalLoja`).
- **Notas nas listas do console:** o recurso enxuto `Index\Order` (lista `GET int/v1/orders` e `GET int/v1/fleet-ops/live/orders`) não traz `notes`. O middleware `IncluirNotasNaListaDePedidos` (`api/app`, grupo `fleetbase.protected`, depois do `$next`) acrescenta o `notes` de cada pedido numa consulta só, filtrada pela empresa. Nunca lança: em erro devolve a resposta original e registra `[entregas] notas na lista de pedidos: <classe>` (`entregas_application`). Teste: `scripts/teste-php/notas-na-lista.php`. **Sem a API nova, o selo e o Cancelar escondido só valem no detalhe.**
- **App** (APK `entregas-motoboy-<n>` do push na `main` do `entregas-navigator`):
  - card de aceitar e detalhes com o selo "iFood #4821" e a faixa amarela da cobrança (`PedidoIfood`, dados de `useDadosIfood(id, estado)`: cache de 2 min por pedido **e estado** `status|motoboy|started`, relido quando o pedido muda);
  - detalhes com "Ligar para o cliente" (0800 do iFood, só no pedido dele) e o localizador em letras grandes (toque longo copia), observações, complemento e referência sem corte, e o aviso do cancelamento pelo iFood;
  - atividade que conclui um pedido iFood passa antes por `concluir-ifood` (overlay "Avisando o iFood…", toque repetido ignorado; sem os dados carregados, busca na hora e, se falhar, "tente de novo" sem concluir) e, com código exigido, abre o campo do código (`CodigoDeEntregaIfood` → `codigo-ifood`; o erro do servidor aparece no próprio campo). O erro do servidor no `update-activity` aparece em toast (ex.: a trava "Atualize o app");
  - cartão do alarme com o selo e a cobrança (`entregas_ifood`, `entregas_cobrar`); Meus ganhos marca "Cancelado pelo iFood: você recebe" (`cancelado_pago`);
  - o `GET v1/entregas/motoboy/pedidos/{id}/ifood` tem limitador próprio, `entregas-motoboy-ifood` (120 por minuto por motoboy), para a lista de pedidos não gastar o limite do `concluir-ifood`.
- **Console:** coluna "iFood" na tabela e selo no quadro, no mapa e no cabeçalho (`pedido-ifood/selo`); painel iFood no detalhe (`order/details/ifood`: última etapa informada, última recusa, cobrança, código, observações; "Liberar sem código"; recarrega quando o pedido, o status ou o `updated_at` mudam); Cancelar escondido (detalhe e linha da tabela) e barrado na tela (lote, mapa, arrastar para "cancelado" no quadro); aviso de ação recusada (serviço `ifood-acao-recusada`: som e notificação fixa que abre o pedido; texto próprio quando o status é 0, isto é, não chegou ao iFood; iniciado na rota raiz do engine, como o `pedido-sem-motoboy`); "Pagamento e cobrança" marca o cancelado pelo iFood (pago), também no CSV.
- **Portal:** selo na lista, na tabela e no cabeçalho; Cancelar desabilitado com o aviso "o cancelamento é feito no Gestor de Pedidos do iFood"; o extrato mostra a marca "Cancelado pelo iFood (cobrado)" na linha e no CSV.
- **Ordem de implantação:** API (`bash deploy/atualizar.sh api`: middleware das notas e limitador) e console (`bash deploy/atualizar.sh console`) → APK novo em todos os celulares → `ENTREGAS_IFOOD_EXIGE_APP_NOVO=1` → só então vincular loja real (a fila própria do iFood já existe: atualize o stack no Portainer para criar o `queue-ifood`).
- **A conferir no teste real (Task 12 do plano):** o texto do servidor no `err?.message` do SDK (422 do código, 400 "Atualize o app"); de onde vem o código de um pedido de teste; o 0800 no pedido de teste; o card do quadro voltando à coluna ao arrastar um pedido iFood para "cancelado"; o Kotlin do alarme compilando no Actions.
- Testes: `scripts/teste-portal/pedido-ifood.test.mjs`, `scripts/teste-php/notas-na-lista.php` e, no app, `scripts/testes/pedido-ifood.teste.ts`.

## Distribuição de pedidos abertos (oferta um a um)

Decisão de 2026-10-07. Desenho: `docs/superpowers/specs/2026-10-07-distribuicao-de-pedidos-design.md`; plano da API: `docs/superpowers/plans/2026-10-07-distribuicao-de-pedidos-api.md` (executado com ajustes de revisão: **o código é a referência**). O painel do console e o APK (cartão de 30 s, Recusar pelo servidor) vêm no plano `2026-10-07-distribuicao-de-pedidos-console-e-app.md`.

- **O que muda:** o pedido aberto (adhoc) não vai mais por alarme a todos os motoboys do raio. Nasce uma **distribuição** (`entregas_distribuicoes`), e o servidor oferece o pedido a **um motoboy por vez, por 30 s** (`entregas_ofertas`), na ordem do **menor tempo estimado até o cliente**.
- **Fase aberta:** a fila acaba (`fila_esgotada`), não há candidato no despacho (`sem_candidato`), passam **3 min do despacho** (`prazo`, mesmo com uma oferta pendente, que vira `cancelada`), a central abre (`aberta_pela_central`) ou o ciclo falha (`falha`). Na aberta, o `OrderPing` comum vai a todos no raio, e daí em diante valem os reenvios e o aviso "sem motoboy" de sempre.
- **Encerrada:** `aceita`, `atribuida` (ganhou motoboy por outro caminho), `cancelada` (pedido encerrado ou apagado) ou `redespachada` (um despacho novo do mesmo pedido cria outra distribuição).

### Como ligar

- `ENTREGAS_DISTRIBUICAO=1` no `stack.env` (`config('services.entregas.distribuicao')`, `Distribuicao::ligada()`). Vazia, tudo volta ao alarme geral do Fleet-Ops, sem deploy.
- `ENTREGAS_DISTRIBUICAO_OSRM` (`services.entregas.distribuicao_osrm`) fica **desligada por padrão**, e a fila usa linha reta × 1,3 a 25 km/h.
  - A produção usa o OSRM público (`router.project-osrm.org`): servidor de demonstração, com limite de uso, que receberia as posições dos motoboys.
  - **Ligue só com um OSRM próprio.**
- As duas estão no `x-api-env` do `deploy/docker-stack.yml`. **Cole o `docker-stack.yml` novo no Portainer** (Stacks → entregas → Editor → Update the stack, "Re-pull image" desligado) junto com a variável. Sem isso, ela não chega aos containers.
- **Desligar no meio:** as distribuições em curso param de avançar, o aceite volta a ser livre e os reenvios seguem.
- **Religar depois de dias desligada:** a varredura abre a todos as distribuições que ficaram em `ofertas` (alarme geral no raio), se o pedido ainda estiver sem motoboy e não encerrado.

### Código

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
- **Troca do listener:** o `AppServiceProvider::distribuirPedidosAbertos` chama o `TrocaDoListenerDoDespacho::aplicar` no `booted()`. Ele lê a lista crua do `OrderDispatched` (`getRawListeners`), esquece o evento, registra o nosso primeiro e depois **os outros, na ordem em que estavam** (o webhook, o `NotifyOrderEvent` e o `HandleOrderDispatched` do **Storefront**, que segue no composer).
- **`ObservadorDaDistribuicao`** (`Order::updated`): ganhou motoboy → `atribuida`; status encerrado → `cancelada`. Nunca lança. No aceite, o `registrarAceite` do middleware sobrescreve com `aceita`.
- **Job `App\Jobs\Entregas\AvancarOferta`** (fila `default`, atraso de 30 s, `afterCommit`): vence a oferta pendente. Com a trava ocupada, volta à fila em 2 s, até 3 tentativas.
- **Comando `entregas:distribuicao-varrer`** (`VarrerDistribuicoes`, a cada minuto, `withoutOverlapping(5)`, em segundo plano): reserva do job para o Redis reiniciado.
  - Vence a pendente vencida há mais de 20 s.
  - Encerra a distribuição cujo pedido já tem motoboy, está encerrado ou sumiu.
  - Abre pelo prazo as que estão em `ofertas` há mais de 3 min.
  - O encerramento só olha as despachadas nas últimas 24 h (as mais antigas, o observador do Order encerra). Vencer e abrir pelo prazo não têm esse limite.

### A fila

- **Candidatos** (`Candidatos::noRaio`): motoboys da empresa do pedido, online, `status=available`, com posição válida no raio de pedido aberto da coleta (`Order::getAdhocDistance`, a mesma consulta do Fleet-Ops, agora filtrada pela empresa) e com GPS (`drivers.updated_at`) de menos de **30 min**.
  - A janela é de 30 min porque o app para de mandar posição quando o motoboy está parado.
  - O APK novo vai mandar a posição a cada 1 min parado (batimento), e aí a janela pode cair.
- **Carga:** os pedidos em andamento dele (a regra do capacete, `SituacaoDoMotoboy`), na ordem de aceite (`started_at`, depois `dispatched_at`): coleta, se ainda não pegou, e entrega.
- **Tempo** (`EstimadorDeTempo`):
  - com o OSRM ligado, uma chamada `table` com todos os pontos (3 s de timeout);
  - com ele desligado, falhando ou com mais de 100 pontos (o `max-table-size` do OSRM), a matriz inteira sai em linha reta, marcada `aproximado`.
- **Encaixe:** testa onde a coleta e a entrega novas entram na sequência dele. Vale a mais rápida que não atrase nenhuma entrega já aceita mais de 10 min. Paradas fixas: 3 min na loja, 2 min no cliente. Sem tempo de preparo.
- **Ordem:** menor tempo até o cliente. Empate: o livre primeiro, depois o `public_id`.
- **A fila é recalculada a cada passo.**
  - Saem quem já recusou ou deixou vencer neste despacho e quem tem oferta pendente de outro pedido (uma oferta por vez por motoboy).
  - Recusa e silêncio só passam ao próximo.
  - A última fila não vazia fica no JSON `fila` da distribuição, para o painel.

### Aceite, recusa, lista e push

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
  - tira os pedidos em oferta a outro motoboy e põe `entregas_oferta: {vence_em, tempo_estimado_s}` na oferta dele;
  - o `vence_em` sai em ISO com `-03:00`;
  - nunca lança. Funciona com o APK atual, que só lista.
- **Push** (`OfertaDePedido`, extensão do `OrderPing`; `order_ping` no push, `order.ping` no socket):
  - título "Oferta para você";
  - dados `entregas_oferta=1` e `entregas_oferta_vence_em`, também no push original de reserva;
  - `android.ttl` = o que falta até vencer (1 a 30 s), pelo `AvisosDoMotoboy`.
- **Console** (só admin, `DistribuicaoController`):
  - `GET int/v1/entregas/pedidos/{id}/distribuicao`: fase, motivo, oferta atual, fila e histórico; `{"distribuicao": false}` se nunca houve;
  - `POST .../distribuicao/abrir` ("Abrir a todos agora"): 409 se não está em oferta, 503 com a trava ocupada.
- **Reenvio:** o `ReenviarPedidosAbertos` não reenvia aos motoboys enquanto o pedido está na fase `ofertas`. O aviso "sem motoboy" à central não muda.

### Logs

Prefixo `[entregas] distribuição:`, só ids e números:

- ciclo: `iniciada`, `oferta enviada`, `oferta recusada`, `oferta vencida`, `aberta a todos (<motivo>)`, `encerrada (<motivo>)`;
- falhas: `falha ao iniciar a distribuição; alarme geral`, `falha no ciclo; aberta a todos`, `push da oferta falhou`, `job da oferta não entrou na fila`, `alarme geral falhou para um motoboy`, `falha ao registrar o aceite`, `falha ao encerrar a distribuição do pedido`, `varredura não conseguiu …`, `trava ocupada ao vencer a oferta; tentando de novo`, `filtro da lista: <classe>`;
- OSRM: `OSRM indisponível; estimativa em linha reta` e `pontos demais para a matriz do OSRM; estimativa em linha reta`;
- boot: `listener do Fleet-Ops não encontrado no OrderDispatched` (o fleetops-api mudou).

Onde ver:

- listener e job: `docker service logs entregas_queue 2>&1 | grep 'distribuição'`;
- aceite, recusa, lista e painel: `entregas_application`;
- varredura: `entregas_scheduler`.

### Armadilhas

- **A `TravaDoPedido` não é reentrante.** Nada que rode dentro do aceite (middleware) ou da `TrocaDoMotoboy` pode chamar `iniciar`, `avancar`, `vencer`, `recusar` ou `abrirATodos`. Por isso o `encerrar` e o `registrarAceite` não tomam a trava.
- **Worker da fila `default`:** com a trava do pedido ocupada, o `AvancarOferta` espera até 10 s por tentativa no worker `queue`, o único da `default`, que também entrega os pushes das ofertas. Se isso atrasar as ofertas, crie um worker extra para a `default`.
- **Trava presa da varredura** (`withoutOverlapping(5)`): `docker exec $(docker ps -q -f name=entregas_scheduler) php artisan schedule:clear-cache`.
- **Lista "Novos pedidos" (`nearby`):** usa só o raio da empresa (`fleetops.adhoc_distance`). Com um `orders.adhoc_distance` maior (só pela API v1 ou pelo formulário da central), o motoboy recebe a oferta pelo alarme, mas ela não aparece na lista.
- **Alarme duplo na abertura pelo prazo.**
  - O intervalo do reenvio conta do `dispatched_at`. Assim, o primeiro `LembretePedidoAberto` (1,5R) sai ~30 a 90 s depois do alarme geral da abertura, e o motoboy dentro de R pode receber dois alarmes seguidos.
  - Aceito por ora. Se incomodar, conte o reenvio a partir da abertura da distribuição.
- Pedido sem coleta ou entrega com coordenadas: fila vazia, abre a todos na hora (`sem_candidato`).
- O `OrderResource` da API v1 traz `id` = `public_id`. O filtro da lista converte para uuid pela tabela `orders`, filtrando a empresa.

### Riscos aceitos

- Dois pedidos de lojas diferentes distribuídos no mesmo instante podem ser oferecidos ao mesmo motoboy: a trava é por pedido, e a checagem de oferta pendente de outro pedido deixa uma janela pequena.
- Motivo `falha`: quando o ciclo lança exceção, a distribuição abre a todos e sai o alarme geral (pelo listener, no despacho; pelo `Distribuidor`, depois de uma resposta).
- O painel mostra a última fila não vazia calculada, que pode não ser a do momento.
- A sequência das paradas do motoboy ocupado é suposta pela ordem de aceite. Se ele entrega em outra ordem, o encaixe erra.
- Um motoboy mudo custa 30 s por pedido (sem pausa nem penalidade). Na fase aberta, o sobrecarregado ainda pode aceitar.

### Ao atualizar o fleetops-api, confira

- a lista de listeners do `OrderDispatched` no `EventServiceProvider` do Fleet-Ops (e do Storefront);
- o `handle()` do `HandleOrderDispatched` (a parte repetida no `DistribuirPedidoAberto`) e os métodos protegidos `doesntHaveDispatchActivity`, `getDispatchActivity`, `nearbyAvailableDrivers` e `notifyAdhocDriver` (o `distribuicao-ciclo.php` confere na cópia de `packages/fleetops`);
- o `Api\v1\OrderController@query` (filtros `adhoc`, `unassigned` e `nearby`) e o formato do `OrderResource`;
- o `OrderPing` (construtor, `title`, `message` e `data`), estendido pelo `OfertaDePedido`.

### Testes

- php-wasm: `scripts/teste-php/distribuicao-encaixe.php`, `distribuicao-fila.php`, `distribuicao-ciclo.php`, `distribuicao-rotas.php`, `filtrar-pedidos-abertos.php`, `barrar-aceite.php`, `avisos-push.php` e `reenvio.php`.
- `scripts/teste-php/imports-dos-providers.php` (estático): pega `use` faltando nos providers e nos arquivos novos. Um `use` faltando no `RouteServiceProvider` derruba a API v1 inteira, e o `php -l` não pega.

### Implantação

1. `bash deploy/atualizar.sh api`: migration `2026_10_07_100000_create_entregas_distribuicao_table`, listener, job, comando e rotas. Com a variável vazia, nada muda.
2. `ENTREGAS_DISTRIBUICAO=1` no `stack.env` e o `docker-stack.yml` novo colado no Portainer → Update the stack ("Re-pull image" desligado).
3. Console (painel) e APK, pelo plano do console e do app.

- **A conferir no primeiro teste real:**
  - a sequência no log: `iniciada` → `oferta enviada` → recusa ou vencida → `oferta enviada` → `aberta a todos` ou `encerrada (aceita)`;
  - o motoboy sem oferta não vê o pedido na lista e leva 409 ao aceitar pelo alarme velho;
  - se o `@fleetbase/sdk` do app mantém o atributo extra `entregas_oferta` no recurso Order e no `serializeCollection`;
  - se o push com TTL de 30 s chega em celular com economia de bateria;
  - com OSRM próprio: se o `table` responde no `OSRM_HOST` e quanto a chamada demora no pico.
- **Se algo der errado:** `ENTREGAS_DISTRIBUICAO=` vazio e Update the stack.

## Marca Entregas RestaurantePro (sem Fleetbase na tela)

- Nome: `app.name` = "Entregas RestaurantePro" em todos os idiomas do console (título da aba e `{appName}`). Os textos de tradução não citam a Fleetbase (só os de licença `ember-ui.modals.legal-notice.*`, que não aparecem).
- Imagens em `console/public`: `images/icon.png` (símbolo num quadrado branco), `images/logo.png` (logo completo) e os favicons, gerados de `Documents/RestaurantePro/logo-512.png` (Pillow).
- Marca padrão da API (ícone do cabeçalho, login, portal e e-mails): `api/config/fleetbase.php` sobrescreve só `branding` do core-api (`mergeConfigFrom`), apontando para as imagens do console. Imagem enviada em Admin → Marca tem prioridade.
- Rodapé "Fleetbase vX · Aviso legal" e o modal de licença: desligados (`DISABLE_FLEETBASE_ATTRIBUTION` padrão `true` no `config/environment.js` e no `console/Dockerfile`), por decisão do Edgard em 2026-10-04. A AGPL-3.0 continua exigindo oferecer o código modificado a quem usa pela rede.
- Fora da tela: menu do usuário sem Discord, Ajuda (GitHub), Documentação, Novidades e versão; tabelas vazias sem botão de guia (`table/empty-state.js`); hubs Recursos/Configurações sem a caixa "Guias"; painel inicial sem os cards Blog/GitHub (componentes apagados) e sem "Recursos recomendados".
- Ficam com o nome da Fleetbase, de propósito: os modelos de importação baixados do S3 dela (`Fleetbase_*_Import_Template.xlsx`), os e-mails transacionais do PHP e os nomes técnicos (pacotes `@fleetbase/*`, chaves de tradução).

## E-mails (pt-BR, layout do RestaurantePro)

- Desenho: `docs/superpowers/specs/2026-10-04-emails-em-portugues-design.md`.
  - Os pacotes trazem os textos fixos em inglês.
  - Tudo é sobrescrito em `api/`, sem editar os pacotes.
- **Layout (estilo C):**
  - `api/resources/views/vendor/mail/html/{message,codigo}.blade.php`, mais `themes/default.css` e `text/*`.
  - Os Mailables da Fleetbase usam o mesmo layout (`vendor/fleetbase/layout/mail.blade.php`).
  - Logo: `MarcaDoEmail` (Admin → Marca ou `api/config/fleetbase.php`).
- **Notificações:**
  - `CanalEmailEntregas` (bind do `MailChannel` no `AppServiceProvider`) monta assunto e texto pelo catálogo `EmailsEmPortugues`.
  - **Não manda e-mail ao motoboy:** nem para o Driver, nem o convite para usuário `type=driver`.
  - Aviso sem tradução sai em inglês no layout novo e aparece no log como `[entregas] e-mail sem tradução`. Notificação na fila registra no serviço da **fila** (`docker service logs entregas_queue | grep '[entregas]'`); as que não vão para a fila, no `entregas_application`.
- **Mailables (código, credenciais, teste):**
  - Corpo em `vendor/fleetbase/mail/*.blade.php`; os textos do código ficam em `CodigosPorEmail`.
  - Assunto pelo `AssuntoDosEmailsEmPortugues` (`MessageSending`, nunca devolver `false`: cancela o envio).
- **Nomes e e-mails no texto passam pelo `TextoDoEmail::semLink`.** É o `delinkify` do core, que quebra o autolink, desfeito do escape HTML. Sem isso, a view escapava de novo e saíam `&amp;` e `&#8203;`.
- **"Esqueci a senha" da loja** (usuário `customer`) leva ao portal: `customer-portal/auth/reset-password/<uuid>?code=`.
  - A rota do portal ganhou o `:id`.
- Testes:
  - `scripts/teste-php/emails.php` (php-wasm, com a `MailMessage` real do Laravel 10 em `scripts/teste-php/laravel10/`);
  - `node scripts/teste-emails-views.mjs`.
- **Ao atualizar o Laravel, o core-api ou o fleetops-api:** confira a lista no docblock do `CanalEmailEntregas`:
  - o `send()` do `MailChannel`;
  - os nomes e as variáveis das views sobrescritas;
  - as classes e as propriedades do catálogo.

## Tradução pt-BR (convenções)

O objetivo é que nenhum texto de interface apareça em inglês com pt-BR selecionado. O inglês continua funcionando.

- **ember-intl 6.** Cada módulo tem `translations/en-us.yaml` e `pt-br.yaml`. As chaves de ember-ui e ember-core ficam em `console/translations/`.
- **As chaves são globais**, porque todos os YAML se mesclam no build. Toda chave nova leva o prefixo do módulo: `fleet-ops.ui.*`, `ledger.ui.*`, `storefront.ui.*`, `iam.ui.*`, `developers.ui.*`, `registry-bridge.ui.*`, `customer-portal.ui.*`, `ai.ui.*`, `console.ui.*` e `ember-ui.*`. Nunca crie chaves novas em `common.*`.
- **Validação obrigatória** antes de commitar:
  ```bash
  node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal
  ```
  - Precisa sair com exit 0. O `VERBOSE=1` lista o que falta e os textos fixos restantes.
  - Também confira que todo JS alterado parseia com `@babel/parser` de `console/node_modules/.pnpm/@babel+parser@7*`. Um import duplicado já quebrou o build.
- **Menus registrados por extensões** (header, atalhos, painéis admin): o título gera `id`/`slug`/`view` (URLs). Não troque o título; traduza por `menu-text.<id>.title|description`, com o helper e util `menu-text` do ember-ui. Itens de painel admin usam `<panel-slug>-<item-view>`.
- **Widgets do painel:** `widget-text.<widget-id>.name|description` (util `widget-text`). As categorias usam `ember-ui.dashboard.widget-category.*`.
- **Status e enums:** o `Badge` e o `smart-humanize` traduzem por `ember-ui.status.<valor>`. O `@status` continua cru, porque vira classe CSS de cor.
- **Texto que vem da API**, sempre por identificador estável e com fallback ao texto do servidor:
  - hubs: `fleetops/addon/utils/localize-hub.js`;
  - outros payloads: `localize-api-payload.js`;
  - métricas do admin: `console/app/utils/localize-admin-metrics.js`;
  - IAM: `localize-iam-record.js` e `localize-iam-metrics.js`;
  - erros: `ember-core/utils/api-message-keys.js` + `notifications.serverError`.
  - atividades do pedido ("Order Created", "Order has been dispatched."…, gravadas em inglês no `tracking_statuses` a partir do fluxo do pedido): `ember-ui/utils/tracking-status-text.js` e helper `tracking-status-text`, pelos textos padrão conhecidos (`ember-ui.tracking-status.*`); texto digitado pela central fica como está. Usado na linha do tempo e na lista de atividades, nos modais de atividade, no rastreio e no portal da loja. No app do motoboy, a descrição usa `src/utils/textos-da-atividade.ts` (o título já sai pelo código, `translateStatus`). **Ao atualizar o fleetops-api, confira se os textos padrão mudaram** (lista no docblock do util).
- **Papéis e políticas de sistema:** o nome e a descrição exibidos são traduzidos (`ember-ui/utils/localize-iam-name.js`, getters `localizedName`/`localizedDescription`). O **nome gravado não muda**, porque é o identificador das atribuições.
- **Permissões:** o código técnico ("fleet-ops create order") é o identificador. A descrição é montada no idioma ativo em `console/app/models/permission.js`, com `console.ui.permission-text.*`.
- **Datas:**
  - O locale do date-fns é compartilhado via `globalThis`: `ember-core/utils/date-fns-locale.js` + `console/app/instance-initializers/date-fns-locale.js`.
  - Chamadas de exibição usam `dateFnsLocaleOptions()`. **Não** aplique isso a `format()` numérico ou que vira chave para cruzar com dados do servidor.
- **Países:** `ember-ui/utils/country-name.js` (`Intl.DisplayNames`), usado nos seletores de país e moeda.
- **Mapa:** o fallback é Ribeirão Preto (-21.1775, -47.8103), e não Singapura.
- **Ainda em inglês, de propósito:**
  - nomes técnicos de permissão;
  - valores gravados no banco (rótulos de faixa de tarifa, nomes de papéis criados pela organização);
  - mensagens de erro da API fora da tabela conhecida;
  - textos livres do servidor.

## Git

- Confirme `git rev-parse --show-toplevel` antes de commitar. A home `C:\Users\Edgardjr` também é um repo git (acidental).
- Push em `main` **não** faz deploy automático; o deploy é o `atualizar.sh` na VPS.
- Nunca commitar `deploy/stack.env`, `api/.env` nem `console/dist`.

## App do motoboy (Navigator próprio)

- Repo **separado e privado**: `edgardjnr/entregas-navigator` (pasta `Documents/vibe coding/entregas-navigator`), fork do Fleetbase Navigator v2.0.11. O da Play Store não recebe push do nosso servidor (usa o Firebase da Fleetbase).
- APK do build type **`entregas`** (otimizado como release, mas `debuggable`, assinado com a chave de debug e C++ em Release/NDEBUG), gerado no GitHub Actions a cada push na `main` (artifact `entregas-motoboy-<n>`, só arm64-v8a). Ser depurável dispensa a licença do background-geolocation (US$ 399; o toast "LICENSE VALIDATION FAILURE" é esperado). O build `debug` deixava o app lento. Instalação direta no Android; ao trocar o tipo de build, desinstalar antes.
- **Avisos ao motoboy (push):** todos passam pelo `CanalFcmEntregas` (troca do `FcmChannel` no `AppServiceProvider`), que usa o `AvisosDoMotoboy` (`api/app/Notifications/Entregas/`):
  - Texto em pt-BR por classe de notificação. Aviso novo do Fleet-Ops sem tradução aparece no log como `[entregas] aviso push sem tradução`.
  - Canal do app: `alarme_pedido` (pedido novo, reenvio, atribuído, liberado), `mensagens` (chat, toque longo) e `avisos` (status, som normal). APK sem o canal usa o padrão `pedidos`.
  - Recusa do FCM: aparece no log como `[entregas] push recusado pelo FCM`, com o motoboy (`motoboy` = `public_id`). Token que não existe mais (404) sai como `info`; o resto, como warning. Se o formato adaptado for recusado como inválido, o canal reenvia o push original do Fleet-Ops (`[entregas] push adaptado recusado pelo FCM; enviado o original`), menos para token malformado. Se a adaptação falhar, vai o original direto (`[entregas] aviso push sem adaptação`).
  - Onde ver o log: o push é notificação enfileirada, então as linhas `[entregas]` ficam no serviço da **fila**: `docker service logs entregas_queue | grep '\[entregas\]'`.
  - **Ao atualizar o pacote `laravel-notification-channels/fcm` (hoje 4.5.0) ou o `kreait/firebase-php` (hoje 7.24.1), confira:** o `send()` do `FcmChannel` (o `CanalFcmEntregas` repete o dele), o `checkReportForFailures()` (ele o sobrescreve) e, no `SendReport` do kreait, `messageWasInvalid()`, `messageTargetWasInvalid()` e `messageWasSentToUnknownToken()`, que o canal usa.
- **Alarme de novo pedido:** com o app aberto, a tela do pedido toca o `AlertaPedido` (loop até aceitar/iniciar, máx. 3 min). Com o app fora da frente, o alarme é um push de dados (**ligado por padrão desde 2026-10-04**; `ENTREGAS_ALARME_POR_DADOS=0` volta ao push comum, que toca uma vez e **não acende a tela** com o app fechado: era por isso que nada aparecia com o celular bloqueado). O push de alarme vale 15 min (`android.ttl`): um pedido velho não toca quando o celular volta à rede. O original reenviado como reserva (quando o FCM recusa o formato adaptado) não tem esse prazo.
  - **Cartão como o aviso de corrida da Uber** (APK 22+, `AlarmePedidoActivity`): som em loop, tela cheia sobre a tela de bloqueio e, com a permissão **sobrepor a outros apps**, por cima do app em uso (`AlarmeDePedido.abrirPorCima`; sem ela, com o celular em uso o Android mostra só o aviso no topo). Mostra o valor do motoboy, a distância loja → cliente com o tempo de moto ("3,2 km · 9 min", `entregas_km` e `entregas_tempo`; coleta e entrega quase no mesmo ponto saem como "menos de 100 m") e loja → destino (dados `entregas_*` do push, montados pelo `App\Support\Entregas\CartaoDoAlarme` com o `CalculoEntregas::valorDoPedido`; falha no cálculo manda o alarme sem o cartão) e o tempo até fechar (3 min). Tem também um **mapa igual ao do pedido no app** (Google Maps normal sem gestos, não o modo lite; grátis como o do app): **P** verde na coleta, **D** vermelho na entrega e a linha da rota em duas camadas na cor do status, tracejada quando é a linha reta. Dados: `entregas_coleta`/`entregas_entrega` ("lat,lng"), `entregas_rota` (traçado do `RotaDoPedido`, reduzido a 80 pontos e codificado como polyline do Google, para caber no limite de 4 KB do push), `entregas_status` e `entregas_rota_aproximada`.
  - Pedido aberto (`order_ping`): **Aceitar** pede o desbloqueio, abre o app no pedido e aceita sozinho (`entregas_aceitar=1` nos dados → `aceitar` nos params do `OrderModal`, `aceitarPeloAlarme` em `src/utils/alarme-do-pedido.ts`); erro do servidor (outro motoboy aceitou antes) aparece na tela. **Recusar** cala e o reenvio do mesmo pedido não toca por 2 h (só naquele celular). Atribuído ou liberado pela central: "Ver pedido" e "Silenciar".
  - A verificação ao abrir o app (`useVerificacaoDoAlarme`) avisa, nesta ordem: notificações, canal de alarme, tela cheia, **sobrepor a outros apps**, **permissões da Xiaomi** ("Mostrar na tela de bloqueio" e "Abrir novas janelas em segundo plano", sem as quais o cartão não aparece em Xiaomi/Redmi/Poco), volume, bateria e **início automático** (Xiaomi, Oppo, Realme, OnePlus, Vivo, Huawei, Honor, Asus: sem ele, fechar o app arrastando corta o push). Os dois últimos o Android não informa: o aviso sai até o motoboy abrir a configuração uma vez. As telas dos fabricantes precisam dos pacotes em `<queries>` no manifesto.
  - Testes: `scripts/teste-php/cartao-do-alarme.php`, `avisos-push.php` e, no app, `scripts/testes/alarme-do-pedido.teste.ts`. O Kotlin só compila no GitHub Actions (não há SDK do Android no PC).
- **Reenvio de pedido aberto:** o `fleetops:dispatch-adhoc` (agendado pelo Fleet-Ops a cada minuto, com `withoutOverlapping`) roda a nossa `api/app/Console/Commands/Entregas/ReenviarPedidosAbertos.php`, trocada no `AppServiceProvider`. O original nunca achava pedido (Carbon mutável) e, corrigido só nisso, avisaria em dobro por até 2 dias. Agora o aviso volta a cada 4 min, no máximo 3 vezes, para os motoboys livres perto da coleta, com texto em pt-BR (`LembretePedidoAberto`). **Ao atualizar o fleetops-api, confira se os métodos herdados ainda existem** (lista no docblock da classe). Teste: `scripts/teste-php/reenvio.php`.
  - **Raio crescente** (decisão de 2026-10-05, vale para todos os pedidos abertos): o primeiro aviso (do despacho) vai até o raio de pedido aberto do Fleet-Ops (R); os reenvios usam o raio da etapa pelo **tempo desde o despacho**: 1,5R a partir de ~4 min e 2R a partir de ~8 min, mesmo quando um reenvio anterior não achou ninguém. O motoboy além de R recebe o alarme e pode aceitar por ele, mas o pedido não aparece na lista de pedidos próximos do app, que filtra por R.
  - **Aviso à central ("sem motoboy"):** com 12 min sem aceite desde o despacho, uma vez por despacho e mesmo sem motoboy no raio, o comando transmite `entregas.pedido_sem_motoboy` (`App\Events\Entregas\PedidoSemMotoboy`) no canal `company.<uuid>`. O envio passa pelo `App\Support\Entregas\TransmissaoNoSocket`, que confere o retorno do SocketCluster: o `broadcast()` do Fleetbase engole a falha do socket (o `SocketClusterBroadcaster` ignora o `false` do `send()`). Se falhar, aparece no `docker service logs entregas_scheduler` (`[entregas] aviso de pedido sem motoboy não chegou ao socket`; gravado direto na saída do container, `/proc/1/fd/2`, porque o `storeOutputInDb()` do Fleet-Ops desvia a saída do comando) e é tentado de novo a cada minuto por até 60 min do despacho. Depois de 16 min, só a central é avisada (os motoboys não recebem mais reenvio). A conferência pega falha de conexão, não a recusa do publish pelo servidor. Os avisos saem depois dos reenvios aos motoboys e, na primeira falha, os demais ficam para o minuto seguinte.
  - **No console**, o serviço `pedido-sem-motoboy` do Fleet-Ops (iniciado na rota raiz do engine, ou seja, só depois que a central entra no Fleet-Ops pela primeira vez) toca três toques (Web Audio; o navegador só libera o som depois do primeiro clique ou tecla na página) e mostra um aviso fixo que abre o pedido no clique. Ele some quando o pedido ganha motoboy, é iniciado, cancelado, concluído, falha ou chega um `order.updated` encerrado ou com motoboy. Com várias abas abertas, cada uma toca. Evento perdido com o console fechado não volta, e o aviso que chega durante os ~5 s de reinscrição do canal (outra tela fechou o canal) também se perde. Se o aceite acontecer no meio da execução do comando, o aviso pode chegar depois do aceite e ficar aberto até o clique ou o "x". Testes: `scripts/teste-portal/pedido-sem-motoboy.test.mjs` e `escutar-canal-da-empresa.test.mjs`.
  - **Armadilha:** se o container do agendador morrer no meio da execução (ex.: deploy), a trava do `withoutOverlapping` no Redis pode segurar o `fleetops:dispatch-adhoc` por até 24 h: sem reenvios e sem aviso à central. Para liberar: `docker exec $(docker ps -q -f name=entregas_scheduler) php artisan schedule:clear-cache`.
- **Início = Meus ganhos** (`src/screens/MeusGanhosScreen.tsx`): atalhos (Hoje, 7 dias, Este mês, Mês passado) e De/Até, total a receber e corridas concluídas por dia. Abre sempre no mês atual; tocar numa corrida abre os detalhes.
  - O card de aceitar (`AdhocOrderCard`) e os detalhes (`OrderScreen`) mostram km (loja → cliente), faixa e o valor do motoboy (`ValorDaEntrega` + `use-valor-da-entrega`, cache de 5 min por pedido).
  - API: `api/app/Http/Controllers/Entregas/MotoboyController.php`, `GET v1/entregas/motoboy/ganhos?inicio&fim` (até 3 meses) e `GET v1/entregas/motoboy/pedidos/{id}/valor` (pedido dele ou aberto). Só token de motoboy (`MotoboyDaSessao`: chave de API → 403), nunca com o valor da loja (`GanhosDoMotoboy`), 60 chamadas por minuto por usuário.
  - Funções puras do app em `src/utils/ganhos.ts`, testadas com `node --experimental-strip-types --test scripts/testes/ganhos.teste.ts`.
- **Aba Pedidos** (`src/screens/DriverOrderManagementScreen.tsx`, decisão de 2026-10-06; desenho: `docs/superpowers/specs/2026-10-06-app-pedidos-e-mapa-do-lider-design.md`): só o que o motoboy tem para fazer agora, em duas seções: **Novos pedidos** (os abertos por perto, com o card de aceitar) e **Em andamento** (os dele não encerrados, de qualquer dia: `allActiveOrders`, que junta o filtro `active` do Fleet-Ops com uma consulta `status=created,pending`, porque o `active` tira o pedido atribuído pela central ainda não despachado). Sem o seletor de data e sem o resumo do dia (o histórico fica no Início). Os dois cards mostram o número do pedido (o do iFood; sem ele, o código de rastreio), e o `OrderCard` mostra a loja da coleta no lugar dos campos do Fleetbase. A recusa do servidor no aceite (ex.: "Este pedido passou para outro motoboy.") aparece em toast e a lista recarrega. Funções puras em `src/utils/lista-de-pedidos.ts` (`scripts/testes/lista-de-pedidos.teste.ts`).
- **Aba Mapa do líder dos motoboys** (no lugar da aba Relatórios, que saiu; mesma decisão e desenho): só para o **líder** (ou administrador com cadastro de motoboy). Mostra os pedidos em andamento de todas as lojas (alfinete vermelho) e os motoboys (capacete na cor da situação, com o nome), e deixa passar um pedido para outro motoboy.
  - **Quem é líder** (`App\Support\Entregas\LiderDosMotoboys`): o motoboy da sessão (token do app; a chave `flb_live_` do APK nunca é líder) que é administrador ou tem, na empresa, a permissão `fleet-ops assign-driver-for order` (ou `fleet-ops * order`, `fleet-ops *`), lida pelo `CompanyUser::getAllPermissions` (diretas, papéis e políticas, como o `AuthorizationGuard` do console; o `Auth::can` não vê as políticas). Vínculo desativado no IAM não é líder.
  - **Dar o papel:** Admin → IAM → Papéis → papel **"Líder de motoboys"** com a política "Operações do motorista" (`DriverOperations`, a do papel Motorista) e a permissão `fleet-ops assign-driver-for order`; depois IAM → Usuários → aba **Motoristas** → editar o motoboy → Papel. O papel substitui o "Motorista" (um papel por usuário), por isso a política vai junto. Alternativa: manter "Motorista" e marcar só a permissão em "Selecionar Permissões" do usuário. O `fleetops:assign-driver-roles` do Fleet-Ops (não agendado) devolveria o papel "Motorista" a todos.
  - **API** (`Entregas/LiderController.php`, limitador `entregas-lider`, 120 por minuto por usuário): `GET v1/entregas/lider/acesso` → `{"lider": bool}` (nunca 403); `GET v1/entregas/lider/mapa` (só líder; `MapaDoLider`): `pedidos` = `PedidosNoMapa::doLider` (os do mapa do console, com o número do iFood, `motoboy_id`, coleta e `atualizado_em`) e `motoboys` = os do mapa do portal (`MotoboysNoMapaDaLoja::noMapa`) com o public_id e o online, sem telefone; `POST v1/entregas/lider/pedidos/{id}/motoboy` com `{"motoboy": "driver_…"}` (`TrocaDoMotoboy`): 404 pedido, 409 encerrado, não despachado ("Este pedido ainda não foi despachado pela central.") ou trava ocupada, 422 motoboy, 200 com o pedido (mesmo motoboy: sem mudança).
  - **O `doLider` não aplica as diretivas do IAM** (o `daCentral` do console aplica): a política "Operações do motorista" tem a diretiva `orders.driver_assigned_uuid = session.driver`, e ninguém grava `session('driver')`; com ela, o líder só veria os pedidos sem motoboy. O acesso já é conferido pelo `LiderDosMotoboys` e a empresa é filtrada na consulta.
  - **A troca** segue a do console: com a `TravaDoPedido`, desliga o pedido aberto (`adhoc` falso) e chama o `assignDriver($motoboy, true)` do Order (silencioso: o save → `OrderObserver` → um `OrderDriverAssigned`, com o push "Novo pedido para você" ao novo e, no pedido iFood, o `assignDriver` ao iFood). O pedido fica com o status que tinha; o `current_job_uuid` do anterior é limpo se apontava para ele. O anterior recebe o `PedidoPassadoParaOutro` ("Pedido #4821 passou para outro motoboy.", canal `avisos`, tipo `entregas_pedido_trocado`, **sem `id`** nos dados: o app recarrega a lista e, se ele estava com aquele pedido aberto, para o alarme e volta). Log `[entregas] líder trocou o motoboy` (só ids) no `docker service logs entregas_application`.
  - **Aceite depois da troca:** o `BarrarAceiteDePedidoEncerrado` responde 409 "Este pedido passou para outro motoboy." quando o pedido não é aberto, tem motoboy e quem aceita (motoboy da sessão ou `assign`) é outro. Sem isso, o anterior que ainda via o card iniciava o pedido em nome do novo (o `startOrder` ignora o `assign` sem adhoc). Teste: `scripts/teste-php/barrar-aceite.php`.
  - **App:** aba `DriverMapaTab` sempre na navegação; só o botão dela some para quem não é líder (`useEhLider` nas `options`: `tabBarButton` nulo e `display: 'none'`; `src/hooks/use-lider.ts`: acesso em memória, lido ao entrar e ao voltar para o app, zerado no logout). **Não use `if` para tirar ou pôr aba nesse navegador:** as `options`/`screenOptions` das abas chamam hooks uma vez por aba, e uma aba que aparece no meio muda a ordem dos hooks; o APK 26 fechava ao abrir para o líder (`TypeError: Cannot read property 'length' of undefined` no `updateCallback`). `MapaDoLiderScreen` relê o mapa a cada 10 s com a tela aberta e o app na frente (espera crescente em erro; no erro, relê o acesso e, sem o papel, o botão some e a tela volta para o Início); cartão do pedido (bottom sheet) com "Trocar motoboy": os online, mais perto da coleta primeiro, e o atual marcado. Funções puras em `src/utils/mapa-do-lider.ts`.
  - **Riscos aceitos:** o líder vê os endereços e os pedidos de todas as lojas, como a central; o papel também vale no console se esse usuário entrar nele; o líder recebe o `public_id` de todos os motoboys, e com ele pode assinar o canal `driver.<id>` do socket (posição ao vivo e telefone), porque o socket não autentica a inscrição.
  - **Ao atualizar o fleetops-api, confira** o `Order::assignDriver($driver, $silent)`, o `OrderObserver::updated` (dispara o `OrderDriverAssigned`) e o `$order->adhoc === false` do `HandleOrderDriverAssigned`: o `scripts/teste-php/lider.php` confere os três na cópia de `packages/`. E a diretiva `list order` da `DriverOperations`.
  - Testes: `scripts/teste-php/lider.php`, `pedidos-no-mapa.php`, `mapa-da-loja.php`, `avisos-push.php` e `barrar-aceite.php`; no app, `scripts/testes/mapa-do-lider.teste.ts`.
- **Detalhes do pedido no app** (`src/screens/OrderScreen.tsx`): X de fechar no topo (nas abas Início e Pedidos a tela abre sem cabeçalho; o `OrderModal` tem o X dele); sem as seções "Progresso do pedido" e "Documentos e arquivos".
  - Cartão do Cliente (a loja, `OrderCustomerCard`): Ligar e E-mail abrem o discador e o app de e-mail (cada um só aparece com o dado cadastrado no Fornecedor da loja). **Chat abre a conversa do motoboy com a loja do pedido**, a mesma do portal (ver "Chat da loja com os motoboys"), só com ele e a loja: `POST v1/entregas/motoboy/pedidos/{id}/chat` (`MotoboyController@chatDoPedido`), só com pedido atribuído a ele (aberto sem aceite: 403); a loja é o Vendor do `customer_uuid`. Pedido sem loja abre a conversa com a central (`ChatComACentral`, um canal "Central · <nome>" por motoboy, com os usuários `admin`/`user`), que também atende os APKs anteriores pela rota `POST v1/entregas/motoboy/chat-central`. As mensagens chegam ao chat do console. Testes: `scripts/teste-php/chat-do-pedido.php` e `chat-central.php`.
  - Pedido do iFood: bloco "Pedido do iFood" (selo, cobrança, ligar, localizador) e a conclusão com o código de entrega (ver "Integração iFood (etapa 3: ciclo da entrega)" → "Etapa 4: APK, console e portal").
- Mapa: chave do Maps SDK for Android restrita ao app (grátis). Directions e Geocoding do Google estão desativadas no app (pagas acima de 10 mil/mês).
- **Mapa do pedido igual ao do console** (`src/components/LiveOrderRoute.tsx`, decisão de 2026-10-04; desenho: `docs/superpowers/specs/2026-10-04-mapa-do-app-igual-ao-console-design.md`):
  - **P** verde na coleta e **D** vermelho na entrega (`MarcadorParada`), com o endereço no toque;
  - a linha loja → cliente vem da **nossa API** (`GET v1/entregas/motoboy/pedidos/{id}/rota`, `MotoboyController@rota` + `App\Support\Entregas\RotaDoPedido`), pelo mesmo OSRM do console, na cor do status do pedido. O traçado fica no cache (Redis) por pedido e coordenadas: 24 h do OSRM, 5 min da linha reta (tracejada no app) quando o OSRM falha. Limitador próprio `entregas-motoboy-rota` (120 por minuto por motoboy);
  - o motoboy é o capacete do console (`MarcadorCapacete`, PNGs em `assets/images/capacete-*.png`) na cor da `situacao` que a mesma rota devolve (`SituacaoDoMotoboy`), com o nome embaixo e sem girar. A posição é a do GPS do celular; some em pedido encerrado e a mais de 30 km das paradas;
  - resumo "3,2 km · 9 min" no canto ("≈" na linha reta). O card de aceitar mostra loja → cliente, não mais motoboy → loja;
  - funções puras em `src/utils/mapa-da-entrega.ts`, testadas com `node --experimental-strip-types --test scripts/testes/mapa-da-entrega.teste.ts`; a API, com `scripts/teste-php/rota-do-motoboy.php`.
- Secrets do repo: `GOOGLE_SERVICES_JSON`, `FLEETBASE_KEY` (chave pública `flb_live_`), `GOOGLE_MAPS_API_KEY`. O projeto Google `entregas-restaurantepro` está no plano Blaze (conta de faturamento vinculada para o Maps).
- Push: Firebase `entregas-restaurantepro`; o JSON da conta de serviço foi enviado em Admin → Notificações Push.
- **Tempo real do app:** o socket vem do `.env` gerado no CI (`SOCKETCLUSTER_HOST/PORT/SECURE`). Sem essas variáveis o app conectava no `socket.fleetbase.io` e ficava sem tempo real: pedido novo, status e chat chegavam só por push ou pela atualização periódica da lista. O app também recarrega pedidos e a conversa aberta quando volta para a frente ou o socket reconecta (`src/hooks/use-ressincronizar.ts`).
- **Mudou algo em Admin → Configurações (push, e-mail, socket)? Reinicie as filas:** `docker service update --force entregas_queue && docker service update --force entregas_queue-ifood`. O worker só lê as configurações ao iniciar; o push e o tempo real só passaram a funcionar depois de um restart.

## Histórico (2026-10-01)

1. Clone do fork e preparo do deploy Swarm ARM64 com Cloudflare Tunnel (`efd1c075`).
2. Correções de produção: healthcheck (`5769e8a8`), socket aceita a API (`d10e3921`) e runtime config do console (`7aa73281`).
3. Submódulos incorporados e tradução pt-BR completa via agentes por módulo (`fbd90f49`). Também o `.dockerignore` do console (`20610fb5`).
4. Textos que vêm prontos da API traduzidos no frontend (`6c164670`).
5. Papéis e políticas, log de atividades, date-fns no idioma ativo e mapa em Ribeirão Preto (`c49ded0c`).
6. Descrições de permissões, nomes de países e "Nunca" (`b7625d16`).
7. Escopo enxuto iFood→motoboy (extensões removidas, telas ocultas) e pagamento de motoboys por km (`1725beee`).
8. Pedido novo já vem com o tipo padrão `transport` (`b41654f9`) e marcador do mapa usa o avatar do motorista (`19a6816d`).
9. Cobrança das lojas (loja = local de coleta) e valores por faixa de km para motoboy e loja (`ce4d4c42`).
10. Portal da loja (2026-10-03, ramo `portal-da-loja`):
    - tela Lojas;
    - portal reativado, com coleta fixa, destino marcado no mapa, acompanhamento e extrato;
    - coleta travada no formulário da central;
    - cobrança pela loja dona do pedido;
    - middlewares de segurança e trava cancelamento × aceite;
    - teste de isolamento.
11. Ganhos do motoboy no app (2026-10-03): Início com filtro de período e total a receber, valor da entrega no card de aceitar e nos detalhes, e valor congelado por entrega (`entregas_valores_pedido`).
12. Motoboys no mapa do portal da loja (2026-10-04): todos os motoboys online com o capacete e o nome, posição a cada 5 s com deslize e destaque do motoboy do pedido aberto; a rota do motoboy do pedido deixou de mandar a posição.
13. Mapa do pedido no app do motoboy igual ao do console (2026-10-04): P/D, linha da rota pelo OSRM via API (`v1/entregas/motoboy/pedidos/{id}/rota`), capacete na cor da situação e resumo de km e tempo.
14. Atividades do pedido em pt-BR na tela (2026-10-04), detalhes do pedido no app com fechar e Chat com a central, e chat da loja com os motoboys no portal.
15. Pedidos em andamento no mapa (2026-10-05): alfinete vermelho no endereço de entrega. O console mostra todos os pedidos; o portal, só os da loja.
16. Raio crescente nos reenvios de pedido aberto e aviso "sem motoboy" à central no console (2026-10-05), etapa 1 da integração iFood (spec `docs/superpowers/specs/2026-10-05-integracao-ifood-logistics-design.md`).
17. Servidor no horário de Brasília (2026-10-05, ramo `fuso-brasilia`): PHP e sessão do MySQL em -03:00, `Date::useCallable`, comandos agendados do Fleet-Ops sem `date_default_timezone_set('UTC')`, relatório, ganhos e iFood no fuso do app, e conversão das DATETIME (`deploy/fuso/`).
17. Integração iFood, etapa 2 (2026-10-05, ramo `ifood-etapa-2`): três tabelas, vínculo das lojas na tela Lojas (app distribuído), polling a cada 30 s com ack depois da gravação, job por pedido que cria o pedido no PLC e despacha como o portal, agendados 40 min antes da janela, renovação dos tokens e varredura dos pendentes (ver "Integração iFood").
18. Integração iFood, etapa 3 (2026-10-06, ramo `ifood-etapa-3`): ações de logística pelos eventos do Fleetbase e pelo GPS, CAN cancela (pago mesmo cancelado), cancelamento do nosso lado proibido, rotas do motoboy (dados, conclusão, código), "Liberar sem código" no console e a trava "Atualize o app" atrás do `ENTREGAS_IFOOD_EXIGE_APP_NOVO` (desligada) (ver "Integração iFood (etapa 3: ciclo da entrega)").
19. Integração iFood, etapa 4 (2026-10-06, ramos `ifood-etapa-4` aqui e no `entregas-navigator`): APK com cobrança, 0800 e código de entrega; selo, painel iFood e aviso de recusa no console, com as notas nas listas pelo `IncluirNotasNaListaDePedidos`; portal com o selo e sem o Cancelar.
20. App do motoboy: aba Pedidos enxuta (novos e em andamento, card com o número e a loja da coleta) e aba Mapa do líder dos motoboys no lugar de Relatórios, com a troca do motoboy de um pedido (2026-10-06, ramos `app-pedidos-e-mapa-do-lider` aqui e no `entregas-navigator`, sobre o `ifood-etapa-4`).
21. Fila própria do iFood (2026-10-06, ramo `fila-ifood`): jobs iFood na fila `ifood` com o worker `queue-ifood`, o `queue` em `default,ifood` e `after_commit` na conexão `redis` (ver "Integração iFood" → "Armadilhas").
22. Distribuição de pedidos abertos (2026-10-07, ramo `distribuicao-de-pedidos`): oferta um a um, por 30 s, pelo tempo até o cliente, abrindo a todos ao esgotar a fila ou aos 3 min (ver "Distribuição de pedidos abertos").
