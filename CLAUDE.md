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

## Escopo enxuto: delivery iFood → motoboy

O produto é só isto: o pedido chega do iFood pela API, é despachado para o motoboy, que usa o app **Navigator**, e o motoboy é pago por km.

- **Extensões fora do console:** storefront, ledger, customer-portal, registry-bridge (Extensions), ai, valhalla e vroom.
  - Saíram do `console/package.json`, do `pnpm-workspace.yaml` e do `Dockerfile.dockerignore`.
  - Os `app/router.js` e `app/extensions/*` são gerados no build a partir do `node_modules`.
  - As pastas em `packages/` e as APIs no `api/composer.json` continuam. Para reativar, reverta essas listas.
- **Ficam:** Fleet-Ops, IAM e **Developers** (chaves de API e webhooks da integração iFood).
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
- **Cobrança das lojas** (mesma tela, renomeada "Pagamento e cobrança"): modelo A = **uma organização só** (o operador de entregas) e cada restaurante é um **Local**.
  - **Loja = local de coleta (pickup)** do pedido. Agrupa pelo **nome** do Place (a integração pode criar um Place por pedido); sem nome, pelo próprio Place. A integração iFood de cada loja deve mandar como pickup o Local da loja (de preferência pelo `public_id`), com nome igual sempre.
  - Cobrança = valor "loja" da faixa do km, igual para todas as lojas. A tela mostra a pagar, a cobrar e a margem; o CSV traz motoboys, lojas e o detalhe com loja, faixa e os dois valores.
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

## Tradução pt-BR (convenções)

O objetivo é que nenhum texto de interface apareça em inglês com pt-BR selecionado. O inglês continua funcionando.

- **ember-intl 6.** Cada módulo tem `translations/en-us.yaml` e `pt-br.yaml`. As chaves de ember-ui e ember-core ficam em `console/translations/`.
- **As chaves são globais**, porque todos os YAML se mesclam no build. Toda chave nova leva o prefixo do módulo: `fleet-ops.ui.*`, `ledger.ui.*`, `storefront.ui.*`, `iam.ui.*`, `developers.ui.*`, `registry-bridge.ui.*`, `customer-portal.ui.*`, `ai.ui.*`, `console.ui.*` e `ember-ui.*`. Nunca crie chaves novas em `common.*`.
- **Validação obrigatória** antes de commitar:
  ```bash
  node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine
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
- Alarme de novo pedido: canal `pedidos` (toque de 30 s, padrão do FCM) + módulo nativo `AlertaPedido` (loop até aceitar/iniciar, máx. 3 min). Chat ainda usa o mesmo canal (separar exige mudar o envio na API).
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
