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

Decisão de 2026-10-05: o servidor inteiro roda no horário de Brasília (`America/Sao_Paulo`, sem horário de verão desde 2019), para acabar com os erros de 3 h (ex.: a lista "Hoje" do app, `GET v1/orders?on=...-03:00`, perdia os pedidos criados depois das 21h). Roteiro da troca em produção e scripts do banco: `deploy/fuso/LEIAME.md`.

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
  - os stubs dos outros testes continuam em UTC.
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
- **Situação:** etapa 2 implementada em 2026-10-05. O primeiro teste real com a loja de teste é a Task 13 do plano 2A. As etapas 3 (ações de logística, GPS, cancelamento) e 4 (APK e console) ainda não existem.
- **Não vincule loja real antes da etapa 3.**
  - O CAN não cancela o pedido no Entregas: só grava `cancelado_pelo_ifood_em` e, se o pedido ainda não foi despachado (agendado ou despacho que falhou), o tira do agendamento e da fila. O pedido já despachado continua aberto aos motoboys, e a central precisa cancelar à mão no console.
  - Até lá, vincule só a loja de teste.

### Como ligar

Nesta ordem:

1. Preencha o `stack.env` com `ENTREGAS_IFOOD=1`, `IFOOD_CLIENT_ID` e `IFOOD_CLIENT_SECRET`, as credenciais do app **distribuído** (Portal do Desenvolvedor → Meus Apps → Credenciais), e faça Stacks → entregas → Editor → Update the stack ("Re-pull image" desligado).
   - Os três são lidos em `config('services.ifood')`. O `ClienteIfood::ligada()` exige os três preenchidos.
   - O Update the stack já recria a API, a fila e o scheduler, porque as variáveis mudaram. Reiniciar à mão é opcional: `docker service update --force entregas_queue && docker service update --force entregas_scheduler`.
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

- **Sem despacho**: nasce com adhoc falso e a central atribui. Cada caso deixa uma marca nas notas:
  - pedido de teste (`isTest`): "[TESTE]", com a entrega ~1 km ao norte da loja;
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
  - CAN grava `cancelado_pelo_ifood_em`. No pedido ainda não despachado nem aceito (agendado ou despacho que falhou), tira o Order do agendamento (`CriadorDoPedidoIfood::tirarDoAgendamento`: `scheduled_at` nulo e adhoc desligado, com a `TravaDoPedido`; senão o `fleetops:dispatch-orders` o despacharia aos motoboys na hora marcada) e a linha da fila do agendador. O Order **não é cancelado** (etapa 3). Com a trava ocupada, o CAN fica pendente e o job tenta de novo; o `entregas:ifood-agendados` faz o mesmo como reserva;
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
- **Job** (criação, eventos, `coleta diverge`, `pagamento inconsistente`): `docker service logs entregas_queue`.
- **Vínculo pela tela** (`vínculo falhou`, `loja vinculada`): `docker service logs entregas_application`.

### Armadilhas

- **Trava do `withoutOverlapping`:** se o processo morrer segurando a trava, ela segura o comando por até 5 min (polling e agendados) ou 10 min (tokens).
  - `cache:clear` não solta a trava, porque as travas ficam na conexão Redis `default` (`lock_connection` do store `redis`).
  - Espere vencer, ou rode `php artisan schedule:clear-cache` no scheduler.
- **`appendOutputTo('/proc/1/fd/1')` depende do go-crond como PID 1 rodando como root.** Sem isso, o redirecionamento falha, e o comando pode nem rodar.
- **Redis sem persistência** (`--save "" --appendonly no`): um restart perde os jobs da fila. Os eventos continuam pendentes no MySQL, e a varredura enfileira de novo depois de 2 min.
- **Um worker só** (`queue` com `replicas: 1`, um `queue:work`): o `Order::create` põe o broadcast na fila antes do commit da transação.
  - Com mais workers, outro worker poderia pegar o broadcast antes do commit.
  - Antes de aumentar, ver `afterCommit` no docblock do `CriadorDoPedidoIfood`.
- **A conferir no primeiro vínculo real:** o nome do campo do refresh token (tratamos `refreshToken` e `refresh_token`). Sem ele, o log `resposta do token sem refresh token` lista os campos que vieram, e o vínculo cai quando o token vencer (6 h).
- **A conferir no primeiro agendado:** o formato do `schedule`.

### Riscos que ficam

- **CAN não cancela o pedido até a etapa 3** (ver acima): o já despachado continua aberto aos motoboys; o não despachado só sai do agendamento. Pela mesma razão, a central e a loja (no portal, antes do aceite) ainda conseguem cancelar o pedido iFood do nosso lado sem o iFood saber.
- **Agendado com motoboy atribuído pela central antes do horário:** o `fleetops:dispatch-orders` roda no mesmo minuto e pode despachar antes, com o adhoc ainda ligado.
  - Aí todos os motoboys livres do raio recebem o aviso, e qualquer um pode tomar o pedido.
  - Ao atribuir um agendado, desligue o "pedido aberto".
- **Pedido sem coordenadas ou agendado sem janela** fica `created`, sem aviso à central além das notas e do log.
- **Pedido que entra já depois da coleta** (integração parada) não é criado; fica só o warning `pedido já passou da coleta`.
- **Na etapa 3, o CAN precisa da `TravaDoPedido`:** a trava do job é por id do iFood, não pelo pedido do Fleetbase.

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
- **Detalhes do pedido no app** (`src/screens/OrderScreen.tsx`): X de fechar no topo (nas abas Início e Pedidos a tela abre sem cabeçalho; o `OrderModal` tem o X dele); sem as seções "Progresso do pedido" e "Documentos e arquivos".
  - Cartão do Cliente (a loja, `OrderCustomerCard`): Ligar e E-mail abrem o discador e o app de e-mail (cada um só aparece com o dado cadastrado no Fornecedor da loja). **Chat abre a conversa do motoboy com a loja do pedido**, a mesma do portal (ver "Chat da loja com os motoboys"), só com ele e a loja: `POST v1/entregas/motoboy/pedidos/{id}/chat` (`MotoboyController@chatDoPedido`), só com pedido atribuído a ele (aberto sem aceite: 403); a loja é o Vendor do `customer_uuid`. Pedido sem loja abre a conversa com a central (`ChatComACentral`, um canal "Central · <nome>" por motoboy, com os usuários `admin`/`user`), que também atende os APKs anteriores pela rota `POST v1/entregas/motoboy/chat-central`. As mensagens chegam ao chat do console. Testes: `scripts/teste-php/chat-do-pedido.php` e `chat-central.php`.
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
- **Mudou algo em Admin → Configurações (push, e-mail, socket)? Reinicie a fila:** `docker service update --force entregas_queue`. O worker só lê as configurações ao iniciar; o push e o tempo real só passaram a funcionar depois de um restart.

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
