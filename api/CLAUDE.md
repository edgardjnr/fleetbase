# API (Laravel)

Parte do `CLAUDE.md` da raiz (índice em "Onde está cada assunto"). Responda e documente em português do Brasil.

Código próprio em `api/app/` (entra na imagem da API); a API usa os pacotes **publicados** no Composer, então mudanças em `packages/*/server` não chegam à produção. Os assuntos com arquivo próprio: `app/Support/Entregas/Ifood/`, `Distribuicao/`, `Integracao/`, `Enderecos/` e `Financeiro/` (extrato e pagamento dos motoboys, faturas das lojas, painel; `ENTREGAS_FINANCEIRO`), `Sistema/` (corridas do sistema: relatório do dono e preço por central), `Painel/` (painel inicial do console), `app/Http/Controllers/Entregas/` (app do motoboy) e `app/Notifications/Entregas/Email/` (e-mails). Portal da loja (middlewares `ProtegerPortalLoja`, `RegrasPortalLoja` e as rotas `int/v1/entregas/loja/*`): `packages/customer-portal/CLAUDE.md`. Tabela de km, telas do Financeiro e mapa ao vivo: `packages/fleetops/CLAUDE.md`.

## Fuso (horário de Brasília)

Decisão de 2026-10-05: o servidor inteiro roda no horário de Brasília (`America/Sao_Paulo`, sem horário de verão desde 2019), para acabar com os erros de 3 h (ex.: a lista "Hoje" do app, `GET v1/orders?on=...-03:00`, perdia os pedidos criados depois das 21h). Roteiro da troca em produção (com ensaio obrigatório num MySQL descartável) e scripts do banco: `deploy/fuso/LEIAME.md`.

- **PHP:** `api/config/app.php` com `timezone` = `America/Sao_Paulo`.
- **Sessão do MySQL:** `-03:00` (ou `DB_TIMEZONE`) nas conexões `mysql`, `sandbox` e `storefront` (esta só existe para o `mysql:createdb`, ver `ConexaoDoStorefront`), pelo `AppServiceProvider::configurarFuso` (`App\Support\Entregas\FusoDoServidor`).
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
  - as tarefas `daily()`, `twiceDaily(1, 13)` e `dailyAt()` do agendador (purges do core, manutenção do Fleet-Ops, `materialize-schedules`) passam a rodar na hora de Brasília, porque o agendador usa o `app.timezone`;
  - os logs do Laravel saem em -03:00;
  - cliente externo da API v1 que manda ISO com `Z` em **filtro** de data (`created_at`, `on`… vão ao query builder) erra 3 h; ao gravar, o `Date::useCallable` converte. Texto sem fuso passa a valer como hora de Brasília (antes, UTC). A integração iFood é interna e não é afetada;
  - telas ocultas (agenda/escalas, manutenção, orquestrador) não foram revisadas: podem errar 3 h (o `sandbox:sync` saiu da agenda em 2026-10-25).
- **Risco:** se o horário de verão voltar, o `-03:00` fixo da sessão diverge do PHP. Aí use o fuso nomeado (exige as tabelas `mysql.time_zone_name`, que o inventário confere) e trate a hora ambígua das DATETIME.

## Sem chave do Google no servidor

- **Sem chave do Google no servidor** (decisão de 2026-10-07; relatório: `docs/superpowers/specs/2026-10-07-custo-geocoding-track-motoboy.md`).
  - O `track()` do Fleet-Ops (cada posição do app do motoboy) geocodificava no Google enquanto a cidade do motoboy estivesse vazia. O Google não devolve a `locality` no Brasil, então foram ~16 mil consultas pagas em 5 dias, com a chave do RestaurantePro salva em Admin → Serviços.
  - A chave saiu de Admin → Serviços (`settings` `system.services.google_maps`, `api_key` nulo). O `GOOGLE_MAPS_API_KEY` do stack também fica vazio.
  - Só a busca de endereço usa o Google no servidor (ver "Busca de endereço (Google Places)" em `api/app/Support/Entregas/Enderecos/CLAUDE.md`). O resto não usa: iFood traz as coordenadas, e km e rotas vêm do OSRM. O mapa do app usa outra chave (Maps SDK for Android, grátis).
  - **Defesa no código:** o `DriverControllerSemGeocodificacao` (trocado no `AppServiceProvider` pelo `Api\v1\DriverController`) roda o `track()` do Fleet-Ops com o geocoder desligado (`GeocoderDesligado`). Mesmo com uma chave de volta, a posição do motoboy não consulta o Google. Teste: `scripts/teste-php/track-sem-geocodificacao.php`. **Ao atualizar o fleetops-api, confira se o `track()` ainda geocodifica só pelo `Geocoder::reverse`** (o teste confere na cópia em `packages/fleetops`).
  - **A chave volta com a busca de endereço** (2026-10-07) como **chave própria do Entregas** ("Entregas servidor - Geocoding", projeto `entregas-restaurantepro`, restrita ao IP da VPS e às APIs Geocoding e Places New, com limite diário), salva em Admin → Serviços. Sem ela, a busca mostra só os locais salvos e o ponto é marcado no mapa. O stack traz `TRACKING_PROVIDER=osrm` (padrão no `deploy/docker-stack.yml`); sem ele, o rastreio do pedido (`tracker`) tentaria a Routes API do Google, que também é paga.
  - **Armadilha de Admin → Serviços:** o `deploy.sh` roda o `config:cache` com as configurações do banco já mescladas, e o core descarta o valor vazio que vem do banco (`array_filter` no `EnvironmentMapper`). Apagar um campo só vale depois de `docker service update --force` em `entregas_application`, `entregas_queue`, `entregas_queue-ifood` e `entregas_scheduler`. Se alguém salvar a tela antes disso, o valor antigo volta ao banco.

## Prazo de entrega (cronômetro)

Decisão de 2026-10-20 (spec `docs/superpowers/specs/2026-10-20-cronometro-do-prazo-design.md`; plano `docs/superpowers/plans/2026-10-20-cronometro-do-prazo-delivery.md`). Cada pedido nasce com o prazo de entrega no `meta`, chave `entregas_prazo_em`, sempre no formato exato `Y-m-d\TH:i:sP` com o fuso de Brasília e sem microssegundos (`2026-10-20T19:57:51-03:00`; o Hermes do app lê assim). Console, portal da loja e app do motoboy mostram o cronômetro: verde com mais de 15 min, amarelo com 15 min ou menos, vermelho "Atrasado há N min"; some no pedido encerrado.

- **Regra única:** `App\Support\Entregas\PrazoDaEntrega`. iFood imediato = `delivery.deliveryDateTime`; agendado = fim da janela (sem o fim, início + 30 min); sem data legível e todos os outros (RestaurantePro, portal, central, API) = 50 min depois do `max(criação, scheduled_at)`.
- **Quem grava:**
  - o `CriadorDoPedidoIfood`, no `Order::create` (antes do save);
  - o hook `Order::creating` → `GravarPrazoDoPedido` (`AppServiceProvider::gravarPrazoDosPedidos`), quando o `meta` não tem um prazo legível (prazo ilegível vira o padrão; `meta` em texto solto, que não é JSON, fica sem prazo). O `creating` não dispara o `updated`: a distribuição e os observadores não percebem. O `RegrasPortalLoja` descarta o `meta` da requisição, e o hook roda depois, no model. Insert pelo query builder (ou criação com `saveQuietly`) escapa. Falha vira o warning `[entregas] falha ao gravar o prazo do pedido` (só a classe; `entregas_application`) e o pedido nasce sem prazo; o hook nunca lança.
  - Reforços do hook (revisão de 2026-10-20): o `meta` pode chegar como array, objeto (`stdClass`, vira array) ou texto JSON (decodificado); as outras chaves são preservadas. Um prazo legível mas fora do formato canônico (`...Z`, com microssegundos, sem segundos) é regravado com `PrazoDaEntrega::formatar` (mesmo instante, Brasília); o canônico fica intacto. Se a leitura do `scheduled_at` lançar, o pedido ganha o padrão a partir de agora em vez de nascer sem prazo.
- **O prazo é o da criação:** ORDER_PATCHED, CHANGE_PREPARATION_TIME do iFood e reagendamento pela central não o mudam. Pedidos de antes do deploy ficam sem a chave (sem cronômetro); não há preenchimento retroativo.
- **`PUT` da API v1 com `meta` apaga o prazo:** o Fleet-Ops troca o `meta` inteiro na atualização (o hook só roda na criação), então um `PUT` com `meta` leva o `entregas_prazo_em` junto. Comportamento do Fleet-Ops; ninguém usa hoje.
- **Onde sai:** o recurso v1 (app, portal) e o completo do console já trazem o `meta`. O recurso enxuto das listas do console (`Index\Order`: `GET int/v1/orders` e `fleet-ops/live/orders`) troca o `meta` por `{"_index_resource": true}`: o `IncluirNotasNaListaDePedidos` põe ali só o `entregas_prazo_em`, na mesma consulta das notas. O `PedidosNoMapa` (console, portal e Mapa do líder) devolve `prazo_em`. O prazo não é sensível: vai aos motoboys e ao socket.
- **Testes:** `scripts/teste-php/prazo-da-entrega.php` e os casos novos de `ifood-pedido.php`, `ifood-criador.php`, `pedidos-no-mapa.php` e `notas-na-lista.php`; front em `scripts/teste-portal/cronometro.test.mjs`.
- **Ao atualizar o fleetops-api ou o core-api, confira:** se o `Index\Order` passou a trazer o `meta` completo (aí o acréscimo do prazo sobra; ele não sobrescreve um prazo que já venha); se o cast `Json` do `meta` e o `HasMetaAttributes` mudaram; e se algum caminho de criação de pedido passou a usar `saveQuietly`/`createQuietly` (pula o `creating`).

## Pacotes fora do package:discover e agendados desligados (2026-10-25)

- **Pacotes:** storefront-api, ledger-api, registry-bridge, ai, valhalla-api, vroom-api e laravel/cashier seguem no `composer.lock`, mas estão no `extra.laravel.dont-discover` do `api/composer.json`. Os providers deles não sobem: nada de rotas (inclusive as públicas do `~registry/v1/auth/*`, do `ledger/public/*` e do `stripe/webhook`), agendados, listeners (os 3 jobs do Storefront por despacho) nem observers (as carteiras do Ledger). Teste: `scripts/teste-php/pacotes-desligados.php`.
  - O **ledger** não sai do lock: o `customer-portal-api` exige a classe `LedgerServiceProvider`, que continua no autoload.
  - O `mysql:createdb`, o `sandbox:migrate` e o `fleetbase:seed` leem os pacotes do `installed.json`, não dos providers. A conexão `storefront` que o primeiro pede vem do `App\Support\Entregas\ConexaoDoStorefront` (chamado no `AppServiceProvider::register`).
  - O console pede `GET ~registry/v1/engines` a cada página: é rota própria do `RouteServiceProvider`, sempre `[]`.
  - Ao mexer no `composer.json` sem gerar lock novo (não há PHP local), recalcule o `content-hash` do lock (o `pacotes-desligados.php` confere).
  - Para religar um pacote, tire-o do dont-discover; as tabelas dele continuam no banco.
- **Agendados desligados:** `fleetops:sync-telematics` (a cada minuto; o Entregas não tem rastreador veicular) e `sandbox:sync` (de hora em hora; sem modo de teste) saem da agenda no `Kernel::bootstrap()`, pelo `App\Support\Entregas\AgendadosDesligados`. Não dá para fazer no `schedule()`: os pacotes agendam no `booted()` deles, depois dele. Sem o `sandbox:sync`, empresa ou usuário novo não vai ao banco de teste (criar chave de API em modo de teste para eles pode falhar).
  - **Ao atualizar o core-api ou o fleetops-api,** confira os nomes dos comandos (`scheduleCommands` dos providers; teste `scripts/teste-php/agendados-desligados.php`).

## Armadilhas do Fleetbase

Achadas no portal da loja; valem para todo o código da `api/app`.

- **Armadilhas do Fleetbase achadas aqui:**
  - `Contact::user()` filtra pelo `type` da instância e falha em `with`/`whereHas`: use `Contact::anyUser`.
  - O `CompanyScope` existe, mas **não** está registrado nos models desta versão: filtre `company_uuid` de forma explícita.
  - `orders.customer_type` varia com e sem a barra inicial: a loja do pedido é o `customer_uuid`.
  - O `cancelOrder` do portal só troca o status (sem atividade nem evento), e o `startOrder` da API v1 não confere cancelamento: daí a trava e o `BarrarAceiteDePedidoEncerrado`.
  - **A troca de motoboy pelo console deixava o pedido sem `payload_uuid`** (2026-10-12, RP570671 e RP444998): ele sumia da lista do motoboy (a consulta da API v1 exige `payload_uuid`) e dos mapas, mas seguia com o motoboy (capacete amarelo). O `ManterPayloadDoPedido` (`Order::updating`, `AppServiceProvider::manterPayloadDosPedidos`) devolve o payload anterior e registra `[entregas] pedido ia perder a coleta e a entrega (payload); mantido` com a ação da rota (`entregas_application`). Teste: `scripts/teste-php/manter-payload.php`.
