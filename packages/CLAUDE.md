# Pacotes (`packages/*`)

Parte do `CLAUDE.md` da raiz (índice em "Onde está cada assunto"). Responda e documente em português do Brasil.

Extensões e bibliotecas incorporadas ao repo (eram submódulos; as cópias originais seguem em `.git/modules/packages/*`, base para diff contra o upstream). Só o frontend chega à produção (console buildado com `packages/*`); o PHP de `packages/*/server` não. Fleet-Ops: `packages/fleetops/CLAUDE.md`; portal da loja: `packages/customer-portal/CLAUDE.md`.

## Chat do console (ember-ui)

- **Mensagem lida** (2026-10-20): o recibo de leitura sai quando a mensagem aparece 90% na janela, ou ocupa 90% da altura dela no caso de mensagem longa (`ember-ui/addon/utils/chat-mensagem-vista.js`, usado no `components/chat-window/message.js`). O Fleetbase pedia 100% (`threshold: 1.0`), e a última mensagem ficava a fração de pixel da borda (99,66%): sem recibo, o ícone de não lida ficava aceso com a conversa aberta. O ícone apaga pelo evento `chat_receipt.created` do socket (conferido em produção). Teste: `scripts/teste-portal/chat-mensagem-vista.test.mjs`.

## Tradução pt-BR (convenções)

O objetivo é que nenhum texto de interface apareça em inglês com pt-BR selecionado. O inglês continua funcionando.

- **ember-intl 6.** Cada módulo tem `translations/en-us.yaml` e `pt-br.yaml`. As chaves de ember-ui e ember-core ficam em `console/translations/`.
- **As chaves são globais**, porque todos os YAML se mesclam no build. Toda chave nova leva o prefixo do módulo: `fleet-ops.ui.*`, `iam.ui.*`, `developers.ui.*`, `customer-portal.ui.*`, `console.ui.*` e `ember-ui.*`. Nunca crie chaves novas em `common.*`.
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
