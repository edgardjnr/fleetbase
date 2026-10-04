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

O produto é só isto: o pedido chega do iFood pela API, é despachado para o motoboy, que usa o app **Navigator**, e o motoboy é pago por km.

- **Extensões fora do console:** storefront, ledger, registry-bridge (Extensions), ai, valhalla e vroom.
  - Saíram do `console/package.json`, do `pnpm-workspace.yaml` e do `Dockerfile.dockerignore`.
  - Os `app/router.js` e `app/extensions/*` são gerados no build a partir do `node_modules`.
  - As pastas em `packages/` e as APIs no `api/composer.json` continuam. Para reativar, reverta essas listas.
- **Ficam:** Fleet-Ops, IAM, **Developers** (chaves de API e webhooks da integração iFood) e o **Portal do Cliente**, que voltou como portal da loja (ver "Portal da loja").
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
  - **A integração iFood manda, em cada pedido (`POST v1/orders`), `customer` = `public_id` do Fornecedor da loja (`vendor_…`) e `pickup` = `public_id` do Local da loja (`place_…`).** Os dois ids aparecem na tela Lojas. Assim o pedido entra no portal da loja (lista, acompanhamento e cancelamento antes do aceite), no extrato e na cobrança dela (decisão de 2026-10-03).
  - Pedido sem loja como cliente fica fora do portal e do extrato. Na cobrança, ele agrupa pelo **nome** do local de coleta (a integração pode criar um Place por pedido); sem nome, pelo próprio Place.
  - Cobrança = valor "loja" da faixa do km, igual para todas as lojas. A tela mostra a pagar, a cobrar e a margem; o CSV traz motoboys, lojas e o detalhe com loja, faixa e os dois valores.
- **Mapa ao vivo** (Fleet-Ops → mapa, `components/map/leaflet-live-map.*`):
  - **Locais:** só os locais de coleta, isto é, o Local de cada loja. O mapa lê `GET int/v1/entregas/mapa/locais-de-coleta` (`Entregas/MapaController.php`) no lugar do `fleet-ops/live/places`, que trazia um prédio por endereço de entrega.
  - **Motoboy = capacete na cor da situação** (`packages/fleetops/assets/images/capacete-*.png`, util `entregas-capacete.js`): verde = livre; amarelo = pedido aceito ou atribuído, ainda na coleta (`started`, `dispatched`…); vermelho = a caminho do cliente (`enroute`); cinza = offline sem pedido. A regra fica em `App\Support\Entregas\SituacaoDoMotoboy` e é lida em `GET int/v1/entregas/mapa/motoboys`. Pedido parado há mais de 12 h não conta.
  - **A cor muda junto com o status.** O mapa escuta o canal `company.<uuid>` do socket e relê a situação ~0,6 s depois de cada evento `order.*`, `waypoint.*`, `entity.*`, `driver.(created|updated|deleted)` ou `entregas.motoboy_online`. A posição (`driver.location_changed`) não conta. A releitura a cada 20 s fica só como reserva. Com a aba oculta o mapa não lê; ao voltar para a aba, lê na hora.
    - Do `localhost` o socket de produção recusa a conexão, porque o `SOCKET_ALLOWED_ORIGINS` só aceita o console. Para testar a reação local, injete o evento no canal: `socket.instance()._channelDataDemux.write('company.<uuid>', {event: 'order.updated'})`.
  - O liga/desliga do online no app (`toggleOnline` do Fleet-Ops) grava com `updateQuietly` e não gera evento. O middleware `AvisarOnlineDoMotoboy` (grupo `fleetbase.api`) transmite o `entregas.motoboy_online` (`App\Events\Entregas\OnlineDoMotoboyMudou`, na hora, sem fila). **Ao atualizar o fleetops-api, confira se a ação `Api\v1\DriverController@toggleOnline` ainda existe.**
  - O nome do motoboy fica num rótulo fixo embaixo do capacete. Os detalhes saem no clique (popup). O capacete não gira com a direção do GPS (`@disableRotation` do `leaflet-tracking-marker`).
  - A consulta dos pedidos em andamento (`SituacaoDoMotoboy::pedidosEmAndamento`) é a mesma do mapa de motoboys do portal da loja.
  - Teste: `scripts/teste-php/mapa.php`.
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
  - **o pedido do portal entrega o motoboy do pedido** (achado na revisão de 2026-10-04, anterior ao mapa de motoboys; pelo código do core-api 1.6.61 e do customer-portal-api 0.0.13). Rota com segmento `int` é "interna" para o Fleetbase, então a lista e o detalhe de `customer-portal/int/v1/orders` trazem o `driver_assigned_uuid`. O detalhe roda o tracker, que carrega o `driverAssigned` na mesma instância do pedido, e por isso traz também o `driver_assigned` completo (nome, telefone, e-mail e posição atual) e o `tracker_data.driver.location`. Vale para qualquer pedido da loja, inclusive concluído, e com o uuid a loja pode assinar `driver.<uuid>` no socket. O front do portal não usa esses campos: a correção é tirá-los da resposta para o usuário de loja (no `RegrasPortalLoja`, depois do `$next`).
- **Armadilhas do Fleetbase achadas aqui:**
  - `Contact::user()` filtra pelo `type` da instância e falha em `with`/`whereHas`: use `Contact::anyUser`.
  - O `CompanyScope` existe, mas **não** está registrado nos models desta versão: filtre `company_uuid` de forma explícita.
  - `orders.customer_type` varia com e sem a barra inicial: a loja do pedido é o `customer_uuid`.
  - O `cancelOrder` do portal só troca o status (sem atividade nem evento), e o `startOrder` da API v1 não confere cancelamento: daí a trava e o `BarrarAceiteDePedidoEncerrado`.

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
- **Alarme de novo pedido:** com o app aberto, a tela do pedido toca o `AlertaPedido` (loop até aceitar/iniciar, máx. 3 min). Com o app fora da frente, o que toca depende da chave `ENTREGAS_ALARME_POR_DADOS`. Nos dois casos o push de alarme adaptado vale 15 min (`android.ttl`): um pedido velho não toca quando o celular volta à rede. O original reenviado como reserva (quando o FCM recusa o formato adaptado) não tem esse prazo.
  - **Desligada (padrão):** push comum no canal `alarme_pedido`. O APK 16 toca **uma vez**, até no silencioso (sem loop e sem tela cheia); os APKs atuais (até o 15) mostram no canal `pedidos` (toque longo, respeita o silencioso).
  - **Ligada (`1`):** push de dados, e o APK 16+ toca em loop com tela cheia. **Só ligar com todos os motoboys no APK 16:** no antigo, tocar no push de dados não abre o pedido.
  - **Como ligar:** `ENTREGAS_ALARME_POR_DADOS=1` nas variáveis do stack (Portainer, como as demais entradas do `stack.env`). O YAML do stack no Portainer precisa ter a linha `ENTREGAS_ALARME_POR_DADOS: ${ENTREGAS_ALARME_POR_DADOS:-}` do `x-api-env` do `deploy/docker-stack.yml` (copie se o editor do Portainer for anterior a ela) → Update the stack ("Re-pull image" desligado). Conferir: `docker exec $(docker ps -q -f name=entregas_queue) printenv ENTREGAS_ALARME_POR_DADOS` (tem de mostrar `1`). Para desligar, deixe a variável vazia e atualize o stack de novo.
- **Reenvio de pedido aberto:** o `fleetops:dispatch-adhoc` (agendado pelo Fleet-Ops a cada minuto) roda a nossa `api/app/Console/Commands/Entregas/ReenviarPedidosAbertos.php`, trocada no `AppServiceProvider`. O original nunca achava pedido (Carbon mutável) e, corrigido só nisso, avisaria em dobro por até 2 dias. Agora o aviso volta a cada 4 min, no máximo 3 vezes, para os motoboys livres no raio da coleta, com texto em pt-BR (`LembretePedidoAberto`). **Ao atualizar o fleetops-api, confira se os métodos herdados ainda existem** (lista no docblock da classe).
- **Início = Meus ganhos** (`src/screens/MeusGanhosScreen.tsx`): atalhos (Hoje, 7 dias, Este mês, Mês passado) e De/Até, total a receber e corridas concluídas por dia. Abre sempre no mês atual; tocar numa corrida abre os detalhes.
  - O card de aceitar (`AdhocOrderCard`) e os detalhes (`OrderScreen`) mostram km (loja → cliente), faixa e o valor do motoboy (`ValorDaEntrega` + `use-valor-da-entrega`, cache de 5 min por pedido).
  - API: `api/app/Http/Controllers/Entregas/MotoboyController.php`, `GET v1/entregas/motoboy/ganhos?inicio&fim` (até 3 meses) e `GET v1/entregas/motoboy/pedidos/{id}/valor` (pedido dele ou aberto). Só token de motoboy (`MotoboyDaSessao`: chave de API → 403), nunca com o valor da loja (`GanhosDoMotoboy`), 60 chamadas por minuto por usuário.
  - Funções puras do app em `src/utils/ganhos.ts`, testadas com `node --experimental-strip-types --test scripts/testes/ganhos.teste.ts`.
- Mapa: chave do Maps SDK for Android restrita ao app (grátis). Directions e Geocoding do app estão desativadas (pagas acima de 10 mil/mês): o mapa abre sem a linha da rota.
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
