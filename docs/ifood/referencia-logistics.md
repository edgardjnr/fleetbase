# Referência técnica: API do iFood (módulo Logistics e dependências)

Fonte: texto das páginas de https://developer.ifood.com.br/pt-BR/docs lidas no navegador em 2026-10-05 (sessão `9231b34b-…`, `get_page_text`). Este arquivo segue a documentação o mais literalmente possível. Quando algo é dedução, vem marcado como **(dedução)**; quando o texto lido não diz, vem **(não consta no texto lido)**.

Aviso importante: a documentação do Logistics **se contradiz em vários pontos** (URL base, validade do token, código HTTP das ações, limite do acknowledgment, formato do ack). A seção "Inconsistências" lista tudo. Antes de codificar, confirme com um pedido de teste.

## Páginas lidas

Sob `https://developer.ifood.com.br/pt-BR/docs/food/guides/modules/`:

| # | Slug | Conteúdo |
|---|------|----------|
| 1 | `logistics/introduction` | Visão geral, fluxo, rate limit, erros |
| 2 | `logistics/order-structure` | Estrutura do pedido (`GET logistics/orders/{id}`) |
| 3 | `logistics/best-practices-troubleshooting` | Boas práticas e troubleshooting |
| 4 | `logistics/endpoints` | Endpoints e payloads |
| 5 | `logistics/homologation` | Critérios de homologação do Logistics |
| 6 | `authentication/distributed` | Fluxo distribuído (userCode, token, refresh) |
| 7 | `authentication/centralized` | Fluxo centralizado |
| 8 | `events/polling-overview` | Polling, throttling, filtros, ACK, presença |
| 9 | `events/order-events` | Estrutura e catálogo de eventos |
| 10 | `order/workflow` | Guia de implementação do módulo Order (contexto) |

Também lidas, só como contexto (sem detalhe técnico): `https://developer.ifood.com.br/pt-BR/docs/getting-started`, `.../getting-started/first-steps/integration-flow` e `.../food/categories` (páginas de boas-vindas).

Não lidas (aparecem no menu lateral, mas o texto não foi extraído): `events/webhook-overview`, `events/webhook-request` (menu: "Webhook", "Conceitos do webhook"), "Validação de assinatura", "Presença", "Alertas de webhook via Slack", "Boas práticas e troubleshooting" e "Critérios de homologação" do módulo Events, `authentication/introduction` (a URL devolveu "Puxa, esta página não existe"; o item "Introdução" do menu de Authentication não abriu por esse slug), "Erros e troubleshooting" de Authentication, "Rate limit", "Política de homologação", "Gerar pedido de teste", "Solicitar acessos", módulo Shipping, e a Referência de API (OpenAPI).

---

## 1. Autenticação

Regra comum: tokens não podem exceder 8.000 caracteres ("Garanta armazenamento adequado para esses tokens"). Autenticação nas APIs: HTTP Bearer (`Authorization: Bearer <accessToken>`).

### 1.1 Fluxo distribuído (o que serve para vários restaurantes)

Usar quando o aplicativo:
- é público e acessível pela internet;
- precisa de autorização explícita do proprietário da loja.

Como funciona (texto da página):
1. O proprietário da loja se autentica no Portal do Parceiro.
2. O proprietário autoriza o aplicativo a acessar recursos específicos da loja.
3. O aplicativo recebe permissão para interagir com os dados da loja autorizada.

"Esse método garante que apenas aplicativos aprovados pelo proprietário acessem os recursos da loja."

Passo a passo:
1. Obtenha um código de vínculo: requisição à API de Autenticação (`userCode`). "Este código funciona como identificador temporário do aplicativo."
2. Armazene o código verificador: a API retorna um `authorizationCodeVerifier` junto com o código de vínculo; guarde com segurança, é necessário para obter o token.
3. Compartilhe o código com o usuário: exiba o código de vínculo e a URL do Portal do Parceiro. O proprietário da loja digita o código no Portal.
4. Colete o código de autorização: depois da autorização no Portal, o proprietário recebe um código de autorização que deve fornecer ao aplicativo.
5. Solicite o token de acesso: envie o código de autorização e o código verificador.
6. Armazene os tokens recebidos (token de acesso e refresh token).
7. Use o token nas requisições (Bearer).
8. Renove o token antes da expiração: chamar a API de token com `grantType: refresh_token`, credenciais `clientId` e `clientSecret` e o refresh token obtido no passo 6.

"Revogação de acesso: Apenas o usuário que autorizou o aplicativo pode revogar o acesso."

#### POST /oauth/userCode

Descrição: "Solicita um código de usuário para vincular aplicativos no Portal do Parceiro e conceder permissões para acessar recursos do comerciante. Este código é essencial para iniciar o fluxo OAuth de autorização."

```bash
curl -X POST "https://merchant-api.ifood.com.br/authentication/v1.0/oauth/userCode" \
-H "Content-Type: application/x-www-form-urlencoded" \
-d "clientId=YOUR_CLIENT_ID"
```

- Método: POST. URL: `https://merchant-api.ifood.com.br/authentication/v1.0/oauth/userCode`.
- Content-Type: `application/x-www-form-urlencoded` (corpo form, não JSON).
- Corpo: `clientId=YOUR_CLIENT_ID` (só o `clientId`; sem `clientSecret` no exemplo).

Resposta 200 (campos, descrição, exemplo):

| Campo | Descrição | Exemplo |
|---|---|---|
| `userCode` | Código de usuário que vincula o aplicativo ao Portal do Parceiro. Exiba ao usuário para que ele insira no Portal do Parceiro. | `HJLX-LPSQ` |
| `authorizationCodeVerifier` | Código de verificação adicional a ser usado ao solicitar o token de acesso. Mantenha este código até que o token de acesso seja emitido. | `test123` |
| `verificationUrl` | URL do Portal do Parceiro que permite aos usuários inserir o código de usuário e conceder acesso ao aplicativo. Exiba ao usuário. | `https://portal.ifood.com.br/apps/code` |
| `verificationUrlComplete` | URL de verificação completa com o código de usuário como parâmetro de query. Útil para ambientes que permitem clicar e abrir um navegador. | `https://portal.ifood.com.br/apps/code?c=HJLX-LPSQ` |
| `expiresIn` | Expiração do código de usuário em segundos. "O código é válido por 10 minutos." | `600` |

Erros desta rota: (não consta no texto lido; a página só documenta 401 e 500 da rota `/oauth/token`).

#### POST /oauth/token (distribuído)

Descrição: "Solicita um novo token de acesso para acessar recursos da API. Por padrão, o token expira em 6 horas. Para aplicativos distribuídos, suporta dois tipos de grant: `authorization_code` e `refresh_token`."

Authorization Code:
```bash
curl -X POST "https://merchant-api.ifood.com.br/authentication/v1.0/oauth/token" \
-H "Content-Type: application/x-www-form-urlencoded" \
-d "grantType=authorization_code&clientId=YOUR_CLIENT_ID&clientSecret=YOUR_CLIENT_SECRET&authorizationCode=AUTH_CODE&authorizationCodeVerifier=CODE_VERIFIER"
```

Refresh Token:
```bash
curl -X POST "https://merchant-api.ifood.com.br/authentication/v1.0/oauth/token" \
-H "Content-Type: application/x-www-form-urlencoded" \
-d "grantType=refresh_token&clientId=YOUR_CLIENT_ID&clientSecret=YOUR_CLIENT_SECRET&refreshToken=REFRESH_TOKEN"
```

Parâmetros de requisição:

| Parâmetro | Obrigatório | Descrição |
|---|---|---|
| `grantType` | Sim | Tipo de grant OAuth. Para aplicativos distribuídos: `authorization_code`, `refresh_token` |
| `clientId` | Sim | Identificador do cliente |
| `clientSecret` | Sim | Segredo do cliente |
| `authorizationCode` | Apenas para `authorization_code` | Código de autorização retornado após a autorização do aplicativo |
| `authorizationCodeVerifier` | Apenas para `authorization_code` | Código verificador retornado na requisição de código de usuário. "A requisição falhará se este código não estiver presente ou não corresponder ao retornado." |
| `refreshToken` | Apenas para `refresh_token` | Token de refresh retornado após a solicitação de um token de acesso. "Disponível apenas em aplicativos distribuídos." |

Resposta 200 (campos documentados na tabela):

| Campo | Descrição | Exemplo |
|---|---|---|
| `accessToken` | JWT representando o token de acesso | `eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzUxMiJ9.…` (exemplo longo) |
| `type` | Tipo do token. "Atualmente, o único tipo suportado é bearer" | `bearer` |
| `expiresIn` | Tempo de expiração do token em segundos | `21600` |

Atenção: a tabela de resposta da página **não lista o campo `refreshToken`**, embora o passo a passo diga que "a API retorna um token de acesso e um refresh token". O nome exato do campo do refresh token na resposta **(não consta no texto lido)**. **(dedução)** provavelmente `refreshToken`, por simetria com o parâmetro de requisição; confirmar na primeira resposta real.

O que o exemplo de `accessToken` contém (decodifiquei o JWT de exemplo; **dedução**, não é texto da documentação): header `{"typ":"JWT","alg":"RS512"}`; payload com `sub`, `aud:"order"`, `user_name`, `scope:["order"]`, `tenantId`, `iss:"iFood"`, `merchant_scope:["<merchantId>:order"]`, `exp`, `iat`, `merchant_scoped:true`, `client_id`, `authorities:["ROLE_CLIENT"]`. Ou seja, o token carrega o escopo de lojas (`merchant_scope`) autorizadas.

Erros documentados (JSON):

- 401 Unauthorized: `error.code` = `Unauthorized`, `error.message` = `Bad credentials`.
- 500 Internal Server: `error.code` = `InternalServerError`, `error.message` = `Unexpected error`.

Validade do access token: 6 horas (`expiresIn: 21600`). Validade do refresh token: (não consta no texto lido). Rotação do refresh token (se o refresh devolve um refresh novo): (não consta no texto lido).

#### Como listar as lojas (merchants) da conta depois do vínculo

(não consta no texto lido). Nenhuma das páginas lidas descreve um endpoint para listar os merchants autorizados (o módulo Merchant não foi lido). O que o texto diz:
- em `events/polling-overview`: "Merchants no token", com cenários "Até 500" e "Mais de 500" merchants no token; o header `x-polling-merchants` filtra por loja; o 403 devolve `unauthorizedMerchants`;
- "Se o acesso foi aprovado recentemente, gere um novo token (o token atual pode ter sido criado antes da aprovação)";
- **(dedução)** o `merchant_scope` do JWT lista as lojas autorizadas (ver acima).

### 1.2 Fluxo centralizado (apenas para comparação)

Usar quando o aplicativo: opera em ambientes internos/privados, não é acessível diretamente pela internet e consegue armazenar com segurança a chave secreta no servidor (exemplo: servidores em VPC privada que consomem APIs do iFood para expor serviços próprios).

- Credenciais: Portal do Desenvolvedor > Meus Apps > Credenciais do aplicativo, onde ficam `clientId` e `clientSecret`.
- "Aplicativos centralizados não recebem refresh tokens. Consulte a FAQ para detalhes."

```bash
curl -X POST "https://merchant-api.ifood.com.br/authentication/v1.0/oauth/token" \
-H "Content-Type: application/x-www-form-urlencoded" \
-d "grantType=client_credentials&clientId=YOUR_CLIENT_ID&clientSecret=YOUR_CLIENT_SECRET"
```

Parâmetros: `grantType` = `client_credentials` (obrigatório), `clientId`, `clientSecret`. Resposta 200: `accessToken` (JWT), `type` (`bearer`), `expiresIn` (21600). Erros 401/500 iguais aos do distribuído. Expira em 6 horas por padrão.

Diferença prática: no centralizado o app "enxerga" as lojas vinculadas à conta do desenvolvedor; no distribuído cada dono de loja autoriza individualmente. **(dedução)**: a página não explicita isso; só descreve os cenários de uso.

---

## 2. Eventos

### 2.1 Polling (`GET /events:polling`)

Descrição: "Seu sistema envia requisições regulares ao endpoint `GET /events:polling` e verifica se há novos eventos. A API retorna as informações novas desde a última requisição."

- URL: `https://merchant-api.ifood.com.br/events/v1.0/events:polling`
- Autenticação: JWT Bearer.
- Respostas: `200` lista (array) de eventos; `204` nenhum evento pendente; `400` requisição inválida (ex.: muitos merchants no polling); `403` Forbidden (sem permissão para algum merchant); `429` limite de 6000 RPM excedido.
- Parâmetros de query: `categories` (FOOD, GROCERY, FOOD_SELF_SERVICE etc.), `types` (PLC, CFM, SPS, SPE, RTP, DSP, CON, CAN etc.), `groups` (ORDER_STATUS, DELIVERY, TAKEOUT etc.).
- Headers: `x-polling-merchants` (lista de merchant IDs, "máximo 100 por requisição" na referência). Na referência aparece também "`excludeHeartbeat=true` (para integradoras logísticas)" listado entre os Headers; nos outros textos é descrito como parâmetro de query (`events:polling?excludeHeartbeat=true`). Se é header ou query: ver Inconsistências.
- Exemplo:

```bash
curl --location 'https://merchant-api.ifood.com.br/events/v1.0/events:polling?categories=FOOD,GROCERY&types=PLC,CFM' \
--header 'Authorization: Bearer YOUR_JWT_TOKEN' \
--header 'x-polling-merchants: 0a0000aa-0aa0-00aa-aa00-0000aa000001'
```

Resposta 200 (exemplo da página):

```json
[
{
"id": "cd40582b-0ef2-4d52-bc7c-507fdff12e21",
"code": "PLC",
"fullCode": "PLACED",
"orderId": "07110e1b-8191-4670-baed-407219481ffb",
"merchantId": "820af392-002c-47b1-bfae-d7ef31743c99",
"createdAt": "2019-09-19T13:40:11.822Z",
"metadata": {
"CLIENT_ID": "3c587f8f-fb22-46a7-88f8-781246a3ea3f"
}
}
]
```

Frequência e limites:
- Intervalo recomendado: "Execute polling a cada 30 segundos para manter a loja online e receber eventos sem atrasos."
- Rate limit: limite absoluto de **6000 requisições por minuto (RPM) por token**. Exceder resulta em 429 e "possível bloqueio temporário da sua integração". Com vários merchants: "agrupe as requisições sequencialmente dentro do mesmo ciclo de 30 segundos".
- Retenção: "A API mantém eventos por até 8 horas após a entrega do pedido. Após esse prazo, os eventos não são mais retornados."
- "A API retorna apenas eventos sem acknowledgment (ACK). Envie o ACK somente após garantir que armazenou o evento com segurança."
- Ordenação: "A API pode entregar eventos fora de ordem. Ordene os eventos pelo campo `createdAt` após recebê-los."
- Duplicidade: "A API pode retornar o mesmo evento mais de uma vez, incluindo eventos antigos de PLACED." Boas práticas: verificar o ID do evento antes de processar; descartar duplicados; não processar o mesmo evento mais de uma vez; **enviar acknowledgment mesmo para eventos já processados**; se receber PLACED repetido, não criar novo pedido.
- Presença: "O merchant fica online enquanto sua integração realiza polling a cada 30 segundos. Se o polling parar, o merchant perde o status online."
- `excludeHeartbeat`: para integradoras logísticas, enviar `excludeHeartbeat=true` "para evitar abrir a loja indevidamente. Isso previne cancelamento de pedidos e penalização do merchant." (Logistics, introdução: "Obrigatório no polling... para evitar que a loja fique aberta indevidamente".) Best practices: "Sem este parâmetro, você receberá eventos 'heartbeat' desnecessários que abrem a loja indevidamente."

#### Throttling por falta de ACK ("Esta funcionalidade será implementada a partir de 02/04/2026")

Mecanismo de "strikes":

| Regra | Descrição |
|---|---|
| Acúmulo de strikes | Cada evento que atinge 50 entregas sem ACK e recebe Auto Ack gera 1 strike |
| TTL do strike | 30 minutos (renovados a cada novo strike) |
| Bloqueio | 100 strikes = bloqueio imediato de 5 minutos no polling |
| Cooldown | Após o bloqueio, 60 minutos de proteção (polling desbloqueado, mas sem poder sofrer novos bloqueios) |

Fases: (1) acúmulo até 100 strikes; novo strike renova o TTL de todos; sem novo strike em 30 min, todos expiram; (2) bloqueio de 5 min, polling retorna 429; (3) cooldown de 60 min sem novos bloqueios (strikes continuam sendo registrados); depois volta à fase 1.

Resposta durante bloqueio:
```json
{
"code": "429",
"message": "Multiple events have been delivered without proper acknowledgment confirmation. Throttling applied."
}
```
"Para evitar bloqueios, garanta o fluxo: receber evento → persistir localmente → enviar ACK."

#### Filtros

- `categories`: padrão FOOD e GROCERY. Ex.: `GET /events:polling?categories=FOOD,GROCERY,ANOTAI,FOOD_SELF_SERVICE` e `?categories=ALL` (todas, inclusive futuras). ANOTAI só aparece se o restaurante usa Pagamento Online iFood no Anota AI.
- `types` e `groups`: `?groups=STATUS,DELIVERY,TAKEOUT`, `?types=COL,CFM,CAN,AAO`, `?groups=ORDER_STATUS&types=AAO,AAD`.
- Auto-acknowledgment dos não filtrados: "Quando você aplica filtros, os eventos que não correspondem aos critérios recebem acknowledgment automático. Isso significa que eventos já confirmados não serão retornados em requisições futuras." Se mudar os filtros depois, os eventos já confirmados não voltam; por isso: defina filtros desde o início e consuma todos os eventos em um único fluxo.
- `groups` já incluem os `types`; não usar os dois para os mesmos eventos. Não é possível filtrar o grupo OUTROS. Sem `types`/`groups` = todos os eventos. Recomendação: consumir sem filtros e filtrar no sistema.
- Para o Logistics, o catálogo de grupos relevantes está na seção 2.3 (DELIVERY, ORDER_STATUS etc.). Qual `categories` o Logistics precisa: (não consta no texto lido).

#### Filtro por merchants (`x-polling-merchants`)

- "O endpoint retorna eventos de até 500 merchants por requisição. Use o header `x-polling-merchants` para especificar quais lojas."
- Exemplo: `x-polling-merchants: 0a0000aa-0aa0-00aa-aa00-0000aa000001,0a0000aa-0aa0-00aa-aa00-0000aa000002` (IDs separados por vírgula).
- Cenários:

| Merchants no token | Header obrigatório? | Comportamento |
|---|---|---|
| Até 500 | Opcional | Sem header: retorna eventos de todos os merchants |
| Mais de 500 | Obrigatório | Divida em lotes de até 100 merchants por requisição |

- Aplicativos centralizados: sempre usar `x-polling-merchants`; limite de 100 merchant IDs por header; sem o header em token com muitos merchants: erro "Bad request. Too many polling merchants".
- Para mais de 500 merchants, a página recomenda webhooks.
- Erro 403 Forbidden: token sem permissão para um ou mais merchants do header. Corpo: `{"unauthorizedMerchants": ["merchant-id-1", "merchant-id-2"]}`. Resolver: remover os listados e reenviar; verificar se o merchant revogou o acesso; se o acesso foi aprovado recentemente, gerar novo token.
- Erro 400 "Too many polling merchants": máximo de 100 por requisição. Causas: app com mais de 100 merchants tentando polling em todos; ausência/uso incorreto do header; lista com mais de 100 IDs. Resolução: validar contagem, usar o header, fazer batching em lotes de no máximo 100, executar em série ou com concorrência controlada respeitando rate limits.

#### Múltiplos devices

"Múltiplos aplicativos podem consumir eventos da mesma loja simultaneamente. A API gera um identificador único ('device') para cada aplicativo baseado nas credenciais." Cada device tem ACK independente; você pode receber eventos de confirmação gerados por outros devices; atualize o status do pedido com base em todos os eventos, "independente da origem". Exemplo: a loja usa seu app e o Gestor de Pedidos do iFood ao mesmo tempo.

### 2.2 Acknowledgment (`POST /events/acknowledgment`)

"Confirma recebimento de eventos, impedindo que sejam retornados em futuras requisições de polling."

- URL: `https://merchant-api.ifood.com.br/events/v1.0/events/acknowledgment`
- Autenticação: JWT Bearer.
- Respostas: `202` Accepted (processado de forma assíncrona); `400` payload malformado; `403` Forbidden; `413` Payload too large ("máximo 10000 eventos por requisição"); `500` erro interno.
- Corpo (array JSON com `id` de cada evento):

```json
[
{
"id": "cd40582b-0ef2-4d52-bc7c-507fdff12e21"
},
{
"id": "193dccf8-bf1d-4860-85a0-8019f5809877"
}
]
```

```bash
curl --location 'https://merchant-api.ifood.com.br/events/v1.0/events/acknowledgment' \
--header 'Authorization: Bearer YOUR_JWT_TOKEN' \
--header 'Content-Type: application/json' \
--data '[
{
"id": "cd40582b-0ef2-4d52-bc7c-507fdff12e21"
},
{
"id": "193dccf8-bf1d-4860-85a0-8019f5809877"
}
]'
```

Regras:
- Enviar array com os IDs ou o payload completo recebido; "A API usa apenas o campo `id` para processar o acknowledgment".
- Limite: o texto de conceitos diz "até 2000 IDs por requisição"; a referência diz "Máximo 10000 eventos por requisição" (413). IDs devem ser únicos.
- Enviar ACK para cada polling que retornar eventos (código 200); enviar ACK de **todos** os eventos, mesmo os não usados; enviar apenas uma vez por evento.
- Strikes: um evento que chega a 50 entregas sem ACK gera strike (seção 2.1).
- Homologação (Events): "Envie `POST /events/acknowledgment` imediatamente após receber eventos (código 200)".

### 2.3 Estrutura do evento

Campos (todos os eventos seguem o mesmo formato):

| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| `id` | String (UUID) | Sim | Identificador único do evento na plataforma |
| `code` | String | Sim | Código abreviado do evento (ex.: PLC, CFM, CAN) |
| `fullCode` | String | Sim | Nome completo do evento (ex.: PLACED, CONFIRMED, CANCELLED) |
| `orderId` | String (UUID) | Sim | ID do pedido ao qual o evento está vinculado |
| `merchantId` | String (UUID) | Sim | ID do merchant do pedido |
| `createdAt` | String (date-time) | Sim | Data e hora de criação (ISO 8601, UTC) |
| `salesChannel` | String | Sim | Canal de vendas. Valores: IFOOD, DIGITAL_CATALOG, POS, ECONOMIC, TOTEM, IFOOD_SHOP, IFOOD_APP, IFOOD_SITE, WAITER, PDV, QR_CODE, IFOOD_SHOP_APP, IFOOD_SHOP_SITE, GROCERY_WHITELABEL_SITE ("novos canais podem ser adicionados") |
| `metadata` | Object | Não | Informações adicionais específicas do evento |

Observação: o exemplo do polling (`PLC`) **não traz `salesChannel`**, apesar de a tabela marcá-lo como obrigatório.

Exemplo:
```json
{
"id": "b03392c5-61dd-47c4-a503-bce3109c96c8",
"code": "CFM",
"fullCode": "CONFIRMED",
"orderId": "93ba4bf4-f4ae-4de8-8017-35d7c7de9bf1",
"merchantId": "820af392-002c-47b1-bfae-d7ef31743c99",
"createdAt": "2021-02-17T19:36:55.295Z",
"salesChannel": "IFOOD",
"metadata": {
"CLIENT_ID": "3c587f8f-fb22-46a7-88f8-781246a3ea3f"
}
}
```

Formato de data: ISO 8601 em UTC; zeros à direita da fração de segundo são omitidos (`...55.295Z` = 295 ms; `...55.2Z` = 200 ms; `...55Z` = sem fração).

Webhook: "O iFood envia eventos automaticamente para um endpoint configurado pela integração assim que eles ocorrem." Detalhes do webhook: (não consta no texto lido).

Grupos de eventos: ORDER_STATUS, CANCELLATION_REQUEST, ORDER_TAKEOUT (retirada), DELIVERY, DELIVERY_ADDRESS, DELIVERY_GROUP, DELIVERY_ONDEMAND, DELIVERY_COMPLEMENT, ORDER_HANDSHAKE (plataforma de negociação). O catálogo abaixo ainda cita HANDSHAKE_PLATFORM e OUTROS.

"Eventos opcionais": alguns eventos não exigem ação (ex.: RECOMMENDED_PREPARATION_START); mesmo ignorando, envie o ACK.

### 2.4 Catálogo de códigos de evento

#### ORDER_STATUS
| Nome | Código | Descrição |
|---|---|---|
| PLACED | PLC | Novo pedido na plataforma |
| CONFIRMED | CFM | Pedido foi confirmado e será preparado |
| SEPARATION_STARTED | SPS | Início da separação dos itens (exclusivo de pedidos de Mercado) |
| SEPARATION_ENDED | SPE | Conclusão da separação (exclusivo de Mercado) |
| READY_TO_PICKUP | RTP | Pedido pronto para ser retirado (pelo cliente ou pelo entregador) |
| DISPATCHED | DSP | Pedido saiu para entrega (Delivery) |
| CONCLUDED | CON | Pedido foi concluído |
| CANCELLED | CAN | Pedido foi cancelado |

"O iFood gera o evento CONCLUDED automaticamente. O tempo de geração pode variar entre o momento da entrega e até duas horas depois." Metadata: o exemplo de PLC/CFM traz `CLIENT_ID` (quem originou). Metadata específico do CAN: (não consta no texto lido).

#### CANCELLATION_REQUEST
Contexto: "Sempre que uma solicitação de cancelamento é aprovada, um evento CANCELLED (CAN) também é gerado."

| Nome | Código | Descrição |
|---|---|---|
| CANCELLATION_REQUESTED | CAR | Solicitação de cancelamento feita pelo Merchant (loja) ou pelo iFood, de maneira automática (pedidos não confirmados dentro do prazo) ou manual (time de atendimento) |
| CANCELLATION_REQUEST_FAILED | CARF | Solicitação de cancelamento negada |

Limite: no máximo 5 eventos por usuário e código de cancelamento a cada 12 horas para CAR e CARF; excedentes são ignorados e não geram novos eventos.

Exemplo CAR:
```json
{
"id": "4d063d88-32f6-4b89-b68b-48890b098c71",
"orderId": "dd2796df-1c09-446b-a3b4-81f28849c459",
"merchantId": "820af392-002c-47b1-bfae-d7ef31743c99",
"code": "CAR",
"fullCode": "CANCELLATION_REQUESTED",
"createdAt": "2021-02-17T19:51:45.704Z",
"salesChannel": "IFOOD",
"metadata": {
"reason_code": "504",
"details": "MANUAL(RESTAURANT_WITHOUT_DELIVERY_MAN) - Restaurante sem entregador",
"ORIGIN": "ORDER_MANAGER",
"CLIENT_ID": "iconnect_v3_homologation"
}
}
```
Metadata do CARF: (não consta no texto lido; a página mostra uma única aba de exemplo, o do CAR).

#### HANDSHAKE_PLATFORM (grupo ORDER_HANDSHAKE na lista de grupos)
"Esses eventos exigem ação obrigatória da loja dentro do prazo especificado."
| Nome | Código | Descrição |
|---|---|---|
| HANDSHAKE_DISPUTE | HSD | Uma negociação foi iniciada e obrigatoriamente deve ser respondida |
| HANDSHAKE_SETTLEMENT | HSS | Sinaliza e formaliza que uma negociação foi respondida |

Metadata: (não consta no texto lido).

#### DELIVERY (o grupo central do Logistics)
Uso: "Monitore o ciclo completo de entrega, desde alocação do entregador até a confirmação final... essenciais para rastrear pedidos em tempo real e validar coletas/entregas."

| Nome | Código | Descrição |
|---|---|---|
| ASSIGN_DRIVER | ADR | Um entregador foi alocado para realizar a entrega |
| GOING_TO_ORIGIN | GTO | Entregador está a caminho da origem para retirar o pedido |
| ARRIVED_AT_ORIGIN | AAO | Entregador chegou na origem para retirar o pedido |
| DELIVERY_DRIVER_DEALLOCATED | DDD | Entregador foi desalocado da rota de entrega |
| COLLECTED | CLT | Entregador coletou o pedido |
| ARRIVED_AT_DESTINATION | AAD | Entregador chegou no endereço de destino |
| DELIVERY_RETURNING_TO_ORIGIN | DRGO | Entregador está retornando ao local de origem (coleta) |
| DELIVERY_RETURNED_TO_ORIGIN | DRDO | Entregador já retornou ao local de origem (coleta) |
| DELIVERY_CANCELLATION_REQUESTED | DCR | Solicitação de cancelamento de entregador |
| DELIVERY_DROP_CODE_REQUESTED | DDCR | Informa o código de confirmação de entrega do pedido |
| DELIVERY_DROP_CODE_VALIDATION_SUCCESS | DDCS | O código de confirmação de entrega foi validado com sucesso |
| DELIVERY_RETURN_CODE_REQUESTED | DRCR | Informa a decisão e o código de confirmação da devolução |
| DELIVERY_PICKUP_CODE_REQUESTED | DPCR | Informa o código de confirmação de coleta do pedido |
| DELIVERY_PICKUP_CODE_VALIDATION_SUCCESS | DPCS | O código de confirmação de coleta foi validado com sucesso |

Exemplo do evento ADR (a página mostra um exemplo só, para o primeiro item das abas):
```json
{
"id": "4d063d88-32f6-4b89-b68b-48890b098c71",
"orderId": "dd2796df-1c09-446b-a3b4-81f28849c459",
"merchantId": "820af392-002c-47b1-bfae-d7ef31743c99",
"code": "ADR",
"fullCode": "ASSIGN_DRIVER",
"createdAt": "2021-02-17T19:51:45.704Z",
"salesChannel": "IFOOD",
"metadata": {
"deliveryId": "b0954b6b-f99c-44b6-ba1e-987f32b2b22a",
"deliveryType": "MAIN",
"workerVehicleType": "CAR",
"workerName": "Fulano da Silva",
"workerExternalUuid": "99d1d32e-7001-4b94-b969-17366d40159b",
"workerPhotoUrl": "https://nv-production-logistics-driver-account.s3.amazonaws.com/static/drivers/photo/99d1d32e-7001-4b94-b969-17366d401999.jpg"
}
}
```
Metadata dos demais eventos DELIVERY (GTO, AAO, DDD, CLT, AAD, DRGO, DRDO, DCR, DDCR, DDCS, DRCR, DPCR, DPCS): (não consta no texto lido; as abas de exemplo não foram extraídas). O que o texto afirma sobre metadata de DELIVERY:
- "Os eventos trazem três campos no metadata para identificar o tipo de entrega": `deliveryId` (identifica cada entrega de forma única e permite rastrear todas as etapas, mesmo em pedidos com múltiplas entregas ou eventos logísticos repetidos), `deliveryType` (`MAIN` ou `COMPLEMENT`), `deliveryComplementType` (`HUGE_ORDER` ou `MISSING_ITEM`; complementares são exclusivas de pedidos feitos pelo iFood).
- "Pedidos de entrega parceira (`deliveryBy: "IFOOD"`) podem ter múltiplas entregas" (item faltante ou pedido grande dividido entre entregadores).
- "Em pedidos Sob Demanda (`salesChannel: "POS"`), os eventos DELIVERY_DROP_CODE incluem o campo `code` no metadata. Em pedidos Full-Service (`salesChannel: "IFOOD"`), esses eventos não incluem o campo `code`."
- DELIVERY_PICKUP_CODE_REQUESTED: ler `HANDSHAKE_VALIDATION_TYPE` no metadata. `VISUAL`: o cliente vê o código e não precisa validar; não chamar o endpoint de validação; o campo `code` está no evento para exibir. `CODE`: o cliente informa o código; renderizar campo de entrada e chamar o endpoint de validação; o endpoint retorna HTTP 200 com `success: true` se correto e HTTP 200 com `success: false` se incorreto ou se a validação não se aplicar. "Todos os cenários retornam HTTP 200. Use o campo `success` no corpo da resposta para determinar o resultado."

Exemplo do DDCR dado na página `logistics/endpoints` (sem metadata nem salesChannel):
```json
{
"id": "f24e3b11-d292-48f0-a74b-e984575a1a0a",
"code": "DDCR",
"fullCode": "DELIVERY_DROP_CODE_REQUESTED",
"orderId": "9c964c28-c833-44bb-9457-9b9accca3a6c",
"merchantId": "b8ce1930-463b-4794-80b4-1823c261c1fb",
"createdAt": "2023-04-17T17:56:39.029Z"
}
```

Importante: o evento DSP (DISPATCHED) é do grupo ORDER_STATUS (visão do pedido); não há evento "DISPATCH" no grupo DELIVERY. Qual evento corresponde ao retorno de cada ação de logística: ver seção 4.

#### DELIVERY_ADDRESS (exclusivo de pedidos Sob Demanda, `salesChannel: "POS"`)
| Nome | Código | Descrição |
|---|---|---|
| DELIVERY_ADDRESS_CHANGE_REQUESTED | DAR | Cliente solicitou alteração do endereço |
| DELIVERY_ADDRESS_CHANGE_USER_CONFIRMED | DAU | Cliente confirmou o endereço de entrega |
| DELIVERY_ADDRESS_CHANGE_ACCEPTED | DAA | Alteração aprovada pelo parceiro |
| DELIVERY_ADDRESS_CHANGE_DENIED | DAD | Alteração negada pelo parceiro |

Exemplo DAR (metadata):
```json
"metadata": {
"address": {
"streetName": "Rua de teste",
"streetNumber": "1",
"complement": "Teste",
"reference": "Teste",
"neighborhood": "Centro",
"city": "Bairro de Teste",
"state": "AC",
"country": "BR",
"coordinates": {
"latitude": -9.11,
"longitude": -67.22
}
}
}
```
(Envelope igual aos outros: `id`, `orderId`, `merchantId`, `code: "DAR"`, `fullCode: "DELIVERY_ADDRESS_CHANGE_REQUESTED"`, `createdAt`, `salesChannel`.) As variantes de DAD (manual, timeout, region mismatch) existem como abas; seu conteúdo não foi extraído.

#### DELIVERY_GROUP
| Nome | Código | Descrição |
|---|---|---|
| DELIVERY_GROUP_ASSIGNED (antigo) | DGA | 2 ou mais pedidos agrupados na mesma rota |
| DELIVERY_GROUP_DISMISSED (antigo) | DGD | Rota de pedidos agrupados destituída |
| DELIVERY_GROUP_ASSOCIATED | DGAC | Pedido associado a um grupo de entrega (rota com múltiplos pedidos) |
| DELIVERY_GROUP_DISSOCIATED | DGDC | Pedido desassociado do grupo |
| DELIVERY_GROUP_UPDATED | DGU | Alteração no grupo de entrega |

Migração: 12/09/2025 novos eventos ASSOCIATED, DISSOCIATED e UPDATED entram em produção; 13/10/2025 os antigos ASSIGNED e DISMISSED serão descontinuados.

Exemplo DGAC (metadata): `{"deliveryGroupId": "4d063d88–32f6–4b89-b68b-48890b098c71", "orderIds": ["849516e1–1493–40d9–82a0–49640dc09311", "a874d6c2–0a57–447a-b8a0-c0080299f158", "ece9e2a1-da09–47f8–9e85-ee7828d2a31f"]}` (a página usa travessões no lugar de hífens nos UUIDs do exemplo; copie só a estrutura).

#### DELIVERY_ONDEMAND (modelo híbrido/marketplace; quando a loja solicita entrega iFood)
| Nome | Código | Descrição |
|---|---|---|
| REQUEST_DRIVER | RDR | Foi feita uma requisição do serviço de entrega sob demanda |
| REQUEST_DRIVER_SUCCESS | RDS | Requisição de entrega aprovada |
| REQUEST_DRIVER_FAILED | RDF | Requisição de entrega negada |
| DELIVERY_CANCELLATION_REQUEST_ACCEPTED | DCRA | Solicitação de cancelamento de entregador realizada com sucesso |
| DELIVERY_CANCELLATION_REQUEST_REJECTED | DCRR | Solicitação de cancelamento de entregador não realizada |

Na página, logo depois da linha de RDF aparece solta a frase "Valores possíveis: SAFE_MODE_ON, OFF_WORKING_SHIFT_POST, CLOSED_REGION, SATURATED_REGION" (sem dizer a que campo se refere; **dedução**: motivos da recusa no metadata do RDF). Exemplo RDR (metadata): `{"ORIGIN": "ORDER_MANAGER", "CLIENT_ID": "iconnect_v3_homologation"}`.

#### DELIVERY_COMPLEMENT
| Nome | Código | Descrição |
|---|---|---|
| RETURN_TO_STORE | RTS | Solicitação de retorno para buscar itens que faltaram (complementar o pedido entregue incompleto) |

Exemplo RTS (sem metadata): `{"id":"05dec672-5fd7-46ce-bdd2-fda515d33eb8","code":"RTS","fullCode":"RETURN_TO_STORE","orderId":"96eebd06-50a5-4e87-908d-d93bbb1ef17b","merchantId":"820af392-002c-47b1-bfae-d7ef31743c99","createdAt":"2021-02-23T15:28:13.084Z","salesChannel":"IFOOD"}`

#### OUTROS (não é possível filtrar por grupo)
| Nome | Código | Descrição |
|---|---|---|
| ORDER_PATCHED | OPA | Houve uma alteração no pedido |
| RECOMMENDED_PREPARATION_START | RPS | Recomendação de início do preparo (somente pedidos com entrega iFood) |
| PREPARATION_STARTED | PRS | Pedido começou a ser preparado |
| CONSUMER_PREPARATION_TIME_REQUESTED | CPR | Cliente solicita informações sobre o tempo de preparo |
| CHANGE_PREPARATION_TIME | CPT | Informa ao cliente mudança no tempo de preparo |
| BOX_ASSIGNED | BOA | Pedido elegível para ser deixado no iFood Box |
| READY_FOR_INVOICE | RFI | Pedido elegível para geração e impressão da Nota Fiscal |

Metadata do ORDER_PATCHED (exemplo da aba EDIT_ITEMS; existem abas EDIT/DELETE/ADD/REPLACE_ITEMS):
```json
"metadata": {
"changes": [
{
"changeType": "EDIT_ITEMS",
"items": [
{
"id": "3dc09021-be6b-4be6-92a1-15a07b464141",
"uniqueId": "3dc09021-be6b-4be6-92a1-15a07b464141",
"externalCode": "123",
"changes": {
"optionsChanges": [
{ "changeType": "ADD_OPTIONS", "options": [ { "id": "d585214c-b95a-4c4d-9d05-16f7d8f99999", "externalCode": "1234567", "quantity": 1, "unitPrice": 2 } ] },
{ "changeType": "EDIT_OPTIONS", "options": [ { "id": "d585214c-b95a-4c4d-9d05-16f7d8f98799", "quantity": { "from": 2, "to": 1 }, "unitPrice": 7 } ] },
{ "changeType": "DELETE_OPTIONS", "options": [ { "id": "d585214c-b95a-4c4d-9d05-16f7d8f98789" } ] }
],
"quantity": { "from": "1", "to": "800" },
"unit": { "from": "KG", "to": "g" }
},
"unitPrice": 20,
"optionsPrice": { "from": 8.5, "to": 2 },
"totalPrice": { "from": 28.5, "to": 22 }
}
]
}
],
"total": { "subtotal": { "from": 28.5, "to": 22 } },
"payments": { "methods": [ { "value": 22, "currency": "BRL", "type": "ONLINE", "method": "CREDIT / DEBIT / MEAL_VOUCHER / FOOD_VOUCHER", "card": { "brand": "Nome da Bandeira" } } ] }
}
```
(Reformatei em linhas compactas; os campos e valores são os do exemplo.) Se o ORDER_PATCHED altera endereço/valor de um pedido de entrega própria, ou o que o Logistics deve fazer com ele: (não consta no texto lido). No guia do módulo Order: "Atualizar comanda na cozinha, atualizar billing, confirmar leitura do evento".

### 2.5 Qual evento marca o pedido como disponível para a logística

(não consta no texto lido). Veja "Lacunas".

---

## 3. Pedido do Logistics (`GET logistics/orders/{id}`)

- Método e URL: `GET https://merchant-api.ifood.com.br/logistics/v1.0/orders/{id}` (a página `order-structure` e a `endpoints` usam esta URL; ver Inconsistências para outras variantes).
- Headers do exemplo: `Authorization: Bearer YOUR_JWT_TOKEN`, `Content-Type: application/json`.
- Respostas: 200 com o objeto; 404 "ID inválido ou pedido não encontrado" (intro: "Pedido não encontrado ou não elegível").
- Um pedido contém: informações gerais (ID, tipo, data de criação), Merchant, Customer, Items (quantidade e peso), Payments, Delivery (endereço e método), Schedule (quando aplicável).

### 3.1 Referência de campos

Informações gerais:
| Campo | Tipo | Descrição |
|---|---|---|
| `id` | uuid | Identificador único do pedido |
| `displayId` | string | ID amigável para exibir na interface da loja |
| `orderType` | enum | `DELIVERY` ou `TAKEOUT` |
| `orderTiming` | enum | `IMMEDIATE` ou `SCHEDULED` |
| `createdAt` | date | Data e hora de criação |
| `isTest` | boolean | Indica se é pedido de teste |

`merchant`:
| Campo | Tipo | Descrição |
|---|---|---|
| `id` | uuid | Identificador único da loja |
| `name` | string | Nome da loja |

`merchant.merchantAddress`:
| Campo | Tipo | Descrição |
|---|---|---|
| `country` | string | Código do país |
| `state` | string | Estado |
| `city` | string | Cidade |
| `district` | string | Bairro |
| `street` | string | Rua |
| `number` | string | Número |
| `postalCode` | string | CEP |
| `latitude` | number | Coordenada latitude |
| `longitude` | number | Coordenada longitude |

`customer`:
| Campo | Tipo | Descrição |
|---|---|---|
| `id` | uuid | Identificador único do cliente |
| `name` | string | Nome do cliente |
| `phone.number` | string | Telefone do cliente ou 0800 do iFood |
| `phone.localizer` | string | Código localizador para usar ao ligar para o 0800 |
| `phone.localizerExpiration` | date | Data de expiração do localizador |

"Campo `phone` é opcional. O campo `phone` expira 3 horas após a data de entrega do pedido." (Os exemplos completos da página não trazem `customer.id`.)

`items`:
| Campo | Tipo | Descrição |
|---|---|---|
| `index` | integer | Posição do item na lista |
| `quantity` | integer | Quantidade |
| `weight.unit` | string | `GRAMS` ou `LITERS` |
| `weight.value` | number | Valor do peso |

Observação: `items` aqui não traz nome do produto nem preço (só índice, quantidade e peso); `weight` é omitido em alguns exemplos.

`payments`:
| Campo | Tipo | Descrição |
|---|---|---|
| `prepaid` | double | Valor já pago (online) |
| `pending` | double | Valor a cobrar na entrega |
| `methods.value` | double | Valor do pagamento |
| `methods.currency` | string | Moeda (ex.: BRL) |
| `methods.method` | enum | `CASH`, `CREDIT`, `DEBIT`, `MEAL_VOUCHER`, `FOOD_VOUCHER`, `GIFT_CARD`, `DIGITAL_WALLET`, `PIX`, `OTHER` |
| `methods.type` | string | `ONLINE` ou `OFFLINE` |
| `methods.prepaid` | boolean | Indica se já foi pago |
| `methods.cash.changeFor` | double | Valor para troco (pagamento em dinheiro) |

Bandeira do cartão (`card.brand`) **não** consta na estrutura do Logistics; só aparece no `metadata` do evento ORDER_PATCHED (`payments.methods[].card.brand`). Em pedido totalmente pago online, o exemplo 2 da página **omite o bloco `payments` inteiro**.

`delivery`:
| Campo | Tipo | Descrição |
|---|---|---|
| `mode` | enum | `DEFAULT` ou `EXPRESS` |
| `deliveredBy` | enum | Responsável: `MERCHANT` (entrega própria) |
| `deliveryDateTime` | date | Data e hora estimada da entrega |
| `observations` | string | Observações do cliente sobre a entrega |
| `deliveryAddress` | object | Endereço de entrega |

`delivery.deliveryAddress`:
| Campo | Tipo | Descrição |
|---|---|---|
| `streetName` | string | Nome da rua |
| `streetNumber` | string | Número (pode conter letras) |
| `formattedAddress` | string | Endereço formatado completo |
| `neighborhood` | string | Bairro |
| `complement` | string | Complemento (apartamento, bloco, etc.) |
| `reference` | string | Ponto de referência |
| `postalCode` | string | CEP (opcional, pode vir zerado) |
| `city` | string | Cidade |
| `state` | string | Estado |
| `country` | string | País |
| `coordinates.latitude` | double | Coordenada latitude |
| `coordinates.longitude` | double | Coordenada longitude |

`schedule` ("Presente apenas em pedidos agendados, `orderTiming: SCHEDULED`"):
| Campo | Tipo | Descrição |
|---|---|---|
| `deliveryDateTimeStart` | date | Início da janela de entrega |
| `deliveryDateTimeEnd` | date | Fim da janela de entrega |

Total do pedido / subtotal / taxa de entrega / valor dos itens: (não consta no texto lido) na estrutura do Logistics; só `payments.pending`, `payments.prepaid` e `methods[].value`. Preço por item: (não consta no texto lido).

### 3.2 Exemplos de cenário (JSON copiado da página)

Cenário 1: entrega imediata, pagamento offline em cartão de crédito (pedido de teste):
```json
{
"id": "4934a1e8-2071-4ac7-9ff6-6e634bb6008d",
"orderType": "DELIVERY",
"orderTiming": "IMMEDIATE",
"displayId": "9843",
"createdAt": "2024-03-20T14:33:08.052Z",
"isTest": true,
"merchant": {
"id": "b0954b6b-f99c-44b6-ba1e-987f32b2b22a",
"name": "Teste - murilogontijo",
"merchantAddress": {
"country": "BR",
"state": "AC",
"city": "Bujari",
"district": "Bujari",
"street": "Ramal Bujari",
"number": "122",
"postalCode": "12345678",
"latitude": -9.822384,
"longitude": -67.948589
}
},
"customer": {
"name": "PEDIDO DE TESTE - Murilo Gontijo",
"phone": {
"number": "0800 705 4050",
"localizer": "89121704",
"localizerExpiration": "2024-03-20T18:33:08.052Z"
}
},
"items": [
{ "index": 1, "quantity": 3, "weight": { "unit": "GRAMS", "value": 2250 } },
{ "index": 2, "quantity": 1, "weight": { "unit": "GRAMS", "value": 200 } }
],
"payments": {
"prepaid": 0,
"pending": 323.99,
"methods": [
{ "value": 323.99, "currency": "BRL", "method": "CREDIT", "prepaid": false, "type": "OFFLINE" }
]
},
"delivery": {
"mode": "DEFAULT",
"deliveredBy": "MERCHANT",
"deliveryDateTime": "2024-04-01T15:18:04.801Z",
"observations": "PEDIDO DE TESTE! NÃO ENTREGAR",
"deliveryAddress": {
"streetName": "Rua TESTE",
"streetNumber": "999999",
"formattedAddress": "Rua TESTE, 999999",
"neighborhood": "Bairro TESTE",
"complement": "Complemento TESTE",
"postalCode": "99999999",
"city": "TESTE",
"state": "XX",
"country": "XX",
"reference": "TESTE",
"coordinates": { "latitude": 0, "longitude": 0 }
}
}
}
```
(Compactei apenas a quebra de linha de `items`, `methods` e `coordinates`; chaves e valores idênticos.)

Cenário 2: entrega imediata, pagamento online já pago. Mesma estrutura, com estas diferenças: `id` `63ec432e-4f0d-4097-b4fe-11bc2dac14af`, `displayId` `1728`, `createdAt` `2024-04-01T15:18:04.801Z`, `isTest: true`, `merchantAddress.number` `"0"`, `customer.phone.localizer` `83984177` com `localizerExpiration` `2024-04-01T19:18:04.801Z`, `items` = `[{"index":1,"quantity":1},{"index":2,"quantity":1}]` (sem `weight`), **sem o bloco `payments`**, e `delivery.deliveryAddress` igual ao de teste (coordenadas 0,0).

Cenário 3: entrega agendada, dinheiro com troco:
```json
{
"id": "5076514a-2ad1-49fe-b38c-e849b4cbeaab",
"orderType": "DELIVERY",
"orderTiming": "SCHEDULED",
"displayId": "9305",
"createdAt": "2024-01-08T20:44:42.547Z",
"isTest": false,
"schedule": {
"deliveryDateTimeStart": "2024-01-16T18:00:00.000Z",
"deliveryDateTimeEnd": "2024-01-16T18:30:00.000Z"
},
"merchant": { "id": "b0954b6b-f99c-44b6-ba1e-987f32b2b22a", "name": "Teste - murilogontijo", "merchantAddress": { "...igual ao cenário 1...": "" } },
"customer": {
"name": "PEDIDO DE TESTE - Murilo Gontijo",
"phone": { "number": "0800 705 3040", "localizer": "75159000", "localizerExpiration": "2024-01-09T00:44:42.547Z" }
},
"items": [
{ "index": 1, "quantity": 1, "weight": { "unit": "GRAMS", "value": 750 } },
{ "index": 2, "quantity": 1, "weight": { "unit": "LITERS", "value": 2 } }
],
"payments": {
"prepaid": 0,
"pending": 103.99,
"methods": [
{ "value": 103.99, "currency": "BRL", "method": "CASH", "prepaid": false, "type": "OFFLINE", "cash": { "changeFor": 150 } }
]
},
"delivery": {
"mode": "DEFAULT",
"deliveredBy": "MERCHANT",
"deliveryDateTime": "2024-01-08T22:14:42.547Z",
"observations": "PEDIDO DE TESTE! NÃO ENTREGAR",
"deliveryAddress": { "...igual ao cenário 1 (coordenadas 0,0)...": "" }
}
}
```
(O `merchantAddress` e o `deliveryAddress` desse cenário são os mesmos do cenário 1; troquei por marcadores só para encurtar.)

Resposta do `GET` na página `endpoints` (formato resumido, 200):
```json
{
"id": "9c964c28-c833-44bb-9457-9b9accca3a6c",
"displayId": "12345",
"orderType": "DELIVERY",
"orderTiming": "IMMEDIATE",
"createdAt": "2024-01-15T10:30:00.000Z",
"merchant": { "id": "merchant-id", "name": "Restaurante Exemplo" },
"customer": { "name": "João Silva", "phone": { "number": "0800 123 4567", "localizer": "12345678" } },
"items": [ { "index": 1, "quantity": 2, "weight": { "unit": "GRAMS", "value": 500 } } ],
"payments": { "prepaid": 0, "pending": 89.99, "methods": [ { "value": 89.99, "currency": "BRL", "method": "CASH" } ] },
"delivery": {
"mode": "DEFAULT",
"deliveredBy": "MERCHANT",
"deliveryDateTime": "2024-01-15T11:30:00.000Z",
"deliveryAddress": {
"streetName": "Rua Exemplo",
"streetNumber": "123",
"complement": "Apto 501",
"neighborhood": "Centro",
"city": "São Paulo",
"state": "SP",
"postalCode": "01234-567",
"coordinates": { "latitude": -23.5505, "longitude": -46.6333 }
}
}
}
```

### 3.3 Pedido de teste

- Indicado por `isTest: true`. Nos exemplos de teste: `customer.name` começa com "PEDIDO DE TESTE - ...", `delivery.observations` = "PEDIDO DE TESTE! NÃO ENTREGAR", endereço com `streetName` "Rua TESTE", `streetNumber` "999999", `country` "XX", `state` "XX", e `coordinates` `latitude: 0`, `longitude: 0`; endereço da loja de teste no Acre (Bujari, `-9.822384`, `-67.948589`).
- Que **todo** pedido de teste vem com coordenadas 0,0 ou o `isTest` é o único indicador confiável: (não consta no texto lido); os três exemplos de teste da página têm 0,0, mas a página não afirma a regra.

### 3.4 Detalhes de pedido do módulo Order (contexto; não é o Logistics)

Do guia `order/workflow` (módulo Order, usado por restaurantes/gestores, não pelo Logistics):
- `GET https://merchant-api.ifood.com.br/order/v1.0/orders/{id}`: 200 com todos os detalhes; 404 para IDs inválidos, pedidos indisponíveis ou antigos.
- "Pedido ainda não disponível: O evento PLACED pode chegar antes dos detalhes. Se receber 404, implemente retry com exponential backoff por até 10 minutos. Não faça retentativas infinitas."
- "A API mantém detalhes por apenas 7 dias."
- Tabela de conclusão automática: o iFood marca CONCLUDED após: Restaurante + iFood Entrega, imediato: 6h (agendado: 6h após `scheduling.to`); Restaurante + Própria: 4h (4h após `scheduling.to`); Mercado/Farmácia: 13h (13h após `scheduling.to`). "Todos incluem `deliveryTimeInSeconds` (configuração da loja)."
- Entrega própria (Order): o pedido segue CONFIRMED → READY_TO_PICKUP → DISPATCHED; "notifique o pedido pronto via `/readyToPickup` antes de despachar via `/dispatch`". Changelog de 08/09/2026: `POST /orders/{id}/readyToPickup` tornou-se obrigatório em pedidos TAKEOUT, DINE_IN e DELIVERY. Isso é feito pelo restaurante/PDV; se o Logistics precisa dele: (não consta no texto lido).
- Confirmar pedido: dentro de 8 minutos; ver "prazo de confirmação" (página Fundamentos, não lida).

---

## 4. Ações de logística

Base (conforme `logistics/endpoints`): `https://merchant-api.ifood.com.br/logistics/v1.0/orders/{id}/<ação>`, onde `{id}` é o `id` (UUID) do pedido, "não o `displayId`". Headers: `Authorization: Bearer <JWT>`, `Content-Type: application/json`. Todas as ações são `POST`.

Resumo (página de endpoints):
| Operação | Método | Endpoint |
|---|---|---|
| Detalhes | GET | `/logistics/orders/{id}` |
| Alocar motorista | POST | `/logistics/orders/{id}/assignDriver` |
| Saindo para coleta | POST | `/logistics/orders/{id}/goingToOrigin` |
| Chegou na origem | POST | `/logistics/orders/{id}/arrivedAtOrigin` |
| Saindo para entrega | POST | `/logistics/orders/{id}/dispatch` |
| Chegou no destino | POST | `/logistics/orders/{id}/arrivedAtDestination` |
| Validar código | POST | `/logistics/orders/{id}/verifyDeliveryCode` |

Ordem obrigatória (repetida em 3 páginas): `assignDriver → goingToOrigin → arrivedAtOrigin → dispatch → arrivedAtDestination → verifyDeliveryCode`. Fora da sequência: 409 Conflict (ex.: alocar driver duas vezes; chamar `arrivedAtDestination` antes de `dispatch`).

Resposta das ações de movimento: `202 Accepted`, sem body ("Operação assíncrona aceita (resultado virá via evento)").

### 4.1 assignDriver

```bash
curl -X POST "https://merchant-api.ifood.com.br/logistics/v1.0/orders/{id}/assignDriver" \
-H "Authorization: Bearer YOUR_JWT_TOKEN" \
-H "Content-Type: application/json" \
-d '{
"workerName": "José Maria",
"workerPhone": "11999999999",
"workerVehicleType": "MOTORCYCLE"
}'
```
Corpo obrigatório:
```json
{
"workerName": "José Maria",
"workerPhone": "11999999999",
"workerVehicleType": "MOTORCYCLE"
}
```
Resposta: `202 Accepted` (sem body). Valores válidos de `workerVehicleType`: `BICYCLE`, `ONFOOT`, `PATINETE`, `EBIKE`, `SUPERBIKE`, `CAR`, `MOTORCYCLE`, `MOTORBIKE`. Formato de `workerPhone` (DDD + número, sem máscara no exemplo; exigência de formato): (não consta no texto lido). Campos opcionais (foto, id externo do entregador): (não consta no texto lido); note que o evento ADR devolve `workerExternalUuid` e `workerPhotoUrl`, mas a página não diz que se possa enviá-los. Realocar/trocar entregador: (não consta no texto lido); só "tentar alocar driver duas vezes" gera 409. Evento DDD (desalocado) existe no catálogo.

### 4.2 goingToOrigin
`POST /logistics/v1.0/orders/{id}/goingToOrigin` (sem corpo). Usar "quando o entregador iniciar deslocamento para o local de coleta". Resposta 202. Evento esperado **(dedução pelo nome)**: GTO (GOING_TO_ORIGIN).

### 4.3 arrivedAtOrigin
`POST .../arrivedAtOrigin` (sem corpo). "Quando o entregador chegar ao local de coleta." 202. Evento **(dedução)**: AAO.

### 4.4 dispatch
`POST .../dispatch` (sem corpo). "Quando o entregador iniciar deslocamento para o local de entrega." 202. Evento **(dedução)**: CLT (COLLECTED) e/ou DSP (DISPATCHED); a documentação não mapeia. Homologação: "Chama POST /dispatch após coleta". Nota: o módulo Order também tem `POST /orders/{id}/dispatch` (`order/v1.0`) para o restaurante com entrega própria; o do Logistics é `logistics/v1.0`.

### 4.5 arrivedAtDestination
`POST .../arrivedAtDestination` (sem corpo). "Quando o entregador chegar ao local de entrega." 202. Evento **(dedução)**: AAD.

### 4.6 verifyDeliveryCode ("Obrigatório para todas integradoras")

```bash
curl -X POST "https://merchant-api.ifood.com.br/logistics/v1.0/orders/{id}/verifyDeliveryCode" \
-H "Authorization: Bearer YOUR_JWT_TOKEN" \
-H "Content-Type: application/json" \
-d '{
"code": "9999"
}'
```
Corpo: `{"code": "9999"}` (string numérica; best practices: "Código deve ser string numérica (ex: "1234")", sem espaços/caracteres especiais; "geralmente 4-6 dígitos").
Resposta documentada: `{"success": true}` ou `{"success": false}`. Página de eventos: "Todos os cenários retornam HTTP 200. Use o campo `success`".
Como funciona: (1) verificar se o pedido requer validação pelo evento `DELIVERY_DROP_CODE_REQUESTED` (DDCR); (2) solicitar o código ao destinatário; (3) enviar o código. "Monitore o evento DELIVERY_DROP_CODE_REQUESTED antes de chamar este endpoint." Se o evento não chegou, "o pedido não requer validação de código". Evento de sucesso: DDCS (DELIVERY_DROP_CODE_VALIDATION_SUCCESS). No módulo Order, após a validação o sistema marca CONCLUDED (aquele guia mostra resposta `{ "valid": true }`, divergente).
Há também, no módulo Events, o endpoint de validação do código de **coleta** (DPCR): o nome e a URL (não consta no texto lido).

### 4.7 Códigos de resposta e erros

Tabela da introdução (Logistics):
| Código | Significado | O que fazer |
|---|---|---|
| 200 | OK | Requisição bem-sucedida |
| 202 | Accepted | Operação assíncrona aceita (resultado virá via evento) |
| 400 | Bad Request | Payload inválido ou campo faltando |
| 401 | Unauthorized | Token expirado ou inválido; renove |
| 403 | Forbidden | Sem permissão para este merchant |
| 404 | Not Found | Pedido não encontrado ou não elegível |
| 409 | Conflict | Operação inválida para o estado atual do pedido |
| 412 | Precondition Failed | Pedido deve estar em estado válido antes desta ação |
| 422 | Unprocessable Entity | Dados rejeitados pela lógica de negócio (ex.: código incorreto) |
| 429 | Too Many Requests | Limite de taxa excedido; aguarde |
| 500 | Server Error | Tente novamente após 30 segundos |

Específicos de logistics:
- 202: ações como `/assignDriver`, `/goingToOrigin` são assíncronas; resultado via evento (polling ou webhook).
- 409: tentar alocar driver duas vezes; chamar `/arrivedAtDestination` antes de `/dispatch`; fora de sequência. Solução: seguir a sequência.
- 412 (na introdução): retornado ao chamar `/verifyDeliveryCode` quando o pedido não está elegível: não recebeu o evento DDCR; não completou `arrivedAtDestination`; pedido cancelado.
- 422: código de entrega incorreto ou diferente do esperado; código já verificado; pedido em estado inválido. "Leia a mensagem de erro na resposta."

Formatos de corpo de erro (exemplos das boas práticas, texto genérico, não confirmado como o real):
- 401: `{"statusCode": 401, "message": "Unauthorized", "details": "Token expirado ou inválido"}`
- 429: `{"statusCode": 429, "message": "Too Many Requests", "retryAfter": 60}`
- verifyDeliveryCode 400: `{"statusCode": 400, "message": "Invalid delivery code"}`; 422: `{"statusCode": 422, "message": "Verification failed - code does not match"}`; 404: `{"statusCode": 404, "message": "Order not found or not eligible for code verification"}`.

Os códigos de erro por ação (por exemplo, o que gera 400 em `assignDriver`, o 404 de `goingToOrigin`): (não consta no texto lido) além do geral acima. 

### 4.8 Limites de taxa e Retry-After (Logistics)

- "100 requisições por minuto para a maioria dos endpoints" (best practices: "alguns endpoints mais restritivos", "Resets a cada minuto").
- 429 + header `Retry-After` (segundos a esperar). Recomendado: backoff exponencial (1 s, 2 s, 4 s...; usar o `Retry-After` se vier), polling a cada 30-60 s, processar eventos em lotes, cache, distribuir requisições.
- Limites específicos por endpoint: a página aponta para a documentação "Rate Limit" (não lida).

---

## 5. Boas práticas e troubleshooting (página `best-practices-troubleshooting`)

Resumo fiel das 10 seções:

1. Tokens. Renovar: (a) proativamente, 5-10 minutos antes da expiração, monitorando o tempo de expiração, para evitar 401 em requisições críticas; (b) reativamente, após 401, renovando e repetindo a requisição. Padrão: guardar token + timestamp de quando foi obtido; antes de cada requisição, se `(agora - timestamp) > (expires_in - 600)`, renovar. (O exemplo de renovação da página é genérico: `POST /auth/oauth/token` com `grant_type`/`client_id`/`client_secret` em snake_case e `expires_in: 3600`; difere dos fluxos reais da seção 1.) 401: causas = token expirado, token inválido/corrompido, header malformado. Resolver: renovar já, verificar `Authorization: Bearer YOUR_TOKEN` (com espaço), conferir que se usa o token (não o secret), implementar renovação automática. "Nunca ignore erros 401."
2. Polling vs webhook (tabela):

| Aspecto | Polling | Webhook |
|---|---|---|
| Como funciona | Você faz requisições periodicamente | iFood envia eventos para seu servidor |
| Frequência típica | A cada 30 segundos | Em tempo real |
| Latência | Até 30 segundos de atraso | Milissegundos |
| Carga do servidor | Aumenta com frequência | Constante |
| Requer URL pública | Não | Sim |
| Confiabilidade | Alta (você controla) | Média (dependência iFood) |
| Implementação | Simples | Moderada |

   Usar polling se: o servidor não tem URL pública, quer controlar quando buscar, 30 s de latência é aceitável. Usar webhook se: tem URL pública e HTTPS, precisa de latência mínima, está pronto para lidar com retentativas. **CRÍTICO:** sempre `excludeHeartbeat=true` no polling (`GET /events/v1.0/events:polling?excludeHeartbeat=true`).
3. Rate limit e backoff exponencial (seção 4.8). 429 causas: polling muito frequente, muitas requisições simultâneas, backoff insuficiente.
4. Verificação de código de entrega (obrigatória): monitorar DDCR; pedir o código ao cliente ao chegar no destino; validar se é numérico e tem comprimento correto; enviar; tratar erros (400 código inválido, 422 incorreto, 404 inelegível). Checklist de falha: recebeu o DDCR? Formato correto? Completou `arrivedAtDestination`? Pedido não cancelado? URL/método/payload corretos? "FALHA COMUM: Não processar o evento."
5. Tratamento de erros: 400 (JSON inválido, campo faltando, tipo errado, enum inválido); 403 (app não autorizado para o merchant, tentando dados de outro app); 404 (ID inválido, deletado, URL errada; "pedidos antigos podem expirar"; usar `/logistics/orders/{id}`, não `/order/`); 409 (alocar driver duas vezes, ordem incorreta, operação já concluída; consultar estado e seguir a sequência); 422 (código incorreto/já verificado, "valor inválido para contexto, ex.: troco negativo"); 500 (aguardar 10-30 s, repetir "(idempotente)", contatar suporte se persistir por mais de 5 minutos, retry automático com backoff).
6. Circuit breaker para operações críticas (exemplo de classe com `maxFailures = 5`, `resetTimeout = 60000`, estados CLOSED/OPEN/HALF_OPEN).
7. Segurança: credenciais em variáveis de ambiente (nunca no código); HTTPS sempre, validar certificados, TLS 1.2 ou superior; se usar webhooks, validar a assinatura (o exemplo da página usa HMAC SHA-256 em base64 com um `secret`; o algoritmo real está na página "Validação de assinatura", não lida).
8. Performance: cache de pedidos (exemplo com TTL de 5 minutos); processamento assíncrono (responder 200 ao webhook e processar depois); monitoramento com logs e métricas (endpoint, duração, status, timestamp).
9. Problemas comuns. "Não estou recebendo pedidos": polling configurado (`events:polling?excludeHeartbeat=true` a cada 30 s)? webhook registrado, URL pública, responde 200? loja de teste tem pedidos / monitorando a loja certa? filtros corretos (`x-polling-merchants`; não excluir o evento de pedido novo)? token válido? Passo a passo: loja de teste ativa; criar pedido de teste; polling direto via curl; 401 = renovar. "Pedidos não estão atualizando após chamar a API": status HTTP real, ID exato do pedido (UUID, não `displayId`), estado correto (sem alocar duas vezes; sequência), token válido; teste rápido com GET → assignDriver → GET.
10. Checklist geral: Autenticação (token válido, header, client id/secret, app autorizado para o merchant); Dados (UUIDs, `id` e não `displayId`, JSON válido, campos obrigatórios, tipos); Fluxo (sequência, estado válido, sem repetir operações, processou DDCR); Conexão (internet, HTTPS, firewall, proxy); Rate limit (polling não mais frequente que 30 s, backoff em 429, respeitar Retry-After).

Idempotência: a página só diz que repetir uma requisição após 500 é "idempotente", e (módulo Order) que confirmação duplicada é ignorada. Logs sem PII: aparece só como requisito de homologação ("Sem dados sensíveis em logs: PII filtrada").

---

## 6. Homologação do Logistics (`logistics/homologation`)

- Objetivo: validar que a integração está completa, funcional e segue os padrões iFood. "Todos os aplicativos de Logistics devem passar por este processo antes de ir para produção."
- O aplicativo deve estar pronto: testam o aplicativo como um todo, não só chamadas de API.
- Conta Profissional (CNPJ) obrigatória; pedidos com conta pessoal/estudante (CPF) são rejeitados.

Pré-requisitos:
- Técnicos: aplicativo completo (interface de entregador, dashboard de pedidos, integração com API de Logistics, tratamento de erros); loja de teste cadastrada (ID e nome), acesso administrativo à loja e capacidade de criar pedidos de teste; Client ID/Secret válidos, token funcionando, aplicativo autorizado para o merchant; URL acessível (se webhook: pública, HTTPS, responde 200, sem certificado SSL expirado).
- Operacionais: documentação interna (como executar testes, como acessar logs), contato técnico disponível; internet confiável (upload/download > 5 Mbps), sem VPN que bloqueie conexões, latência < 500 ms; loja de teste limpa (sem pedidos antigos), serviço rodando, logs de debug habilitados.

Checklist de validação (o que é testado):
1. Recepção de pedidos: polling em `/events/v1.0/events:polling?excludeHeartbeat=true` a cada 30 s, processa eventos, envia ACK de todos, não perde eventos entre requisições; via webhook: URL pública, 200 OK, retry interno. Teste: criar pedido de teste, app recebe, aparece na lista.
2. Detalhes do pedido: `GET /logistics/orders/{id}` após o novo pedido; exibe cliente (nome, endereço), pagamento, itens; trata 404.
3. Alocação: `POST /assignDriver` com `workerName`, `workerPhone`, `workerVehicleType` válido; "Processa resposta 200 OK" (a página diz 200; ver Inconsistências); trata 409; testar que não dá para alocar duas vezes.
4. `goingToOrigin` no momento correto, depois de `assignDriver` e antes de `arrivedAtOrigin` (resposta esperada "200 OK" na página).
5. `arrivedAtOrigin` quando o motorista chega; "Pedido saiu do restaurante (não pode fazer pickup duas vezes)".
6. `dispatch` após a coleta, depois de `arrivedAtOrigin`.
7. `arrivedAtDestination`, mantendo a sequência; "Pronto para validação de código".
8. Verificação de código (OBRIGATÓRIO/CRÍTICO): monitorar DDCR; chamar `verifyDeliveryCode` com o código correto ("200 OK se código correto"); tratar 422 se incorreto; permitir digitar novamente.
9. Acknowledgments (se polling): após cada polling, imediatamente; "Status: 200 para todos os eventos"; "Endpoint: `/events/v1.0/events:acknowledgement`"; payload `{"acknowledgements":[{"id":"event-id","status":200}]}` (diverge da página de Events, ver Inconsistências).

Requisitos não funcionais:
- Token: renovação proativa e reativa (401), armazenamento seguro (variáveis de ambiente), não pedir token novo a cada requisição. Validação: simular token expirado.
- Rate limit: polling não mais frequente que 30 s; respeitar 429 com backoff exponencial; aguardar `Retry-After`; sem picos. Validação: fazer requisições rápidas demais.
- Erros: 400 valida payload; 401 renova; 404 trata; 409 não repete operações; 429 faz backoff; 500 aguarda e repete. "Analista forçará erros."
- Segurança: HTTPS, credenciais em env, sem PII em logs, validação de entrada contra injection.

Como agendar:
1. Preparar: cumprir pré-requisitos, testar todos os cenários, preparar loja de teste, ter contato técnico.
2. Solicitar: na **Área de Chamados**, abrir ticket com assunto "Solicitação de Homologação - Logistics API" e descrição: Aplicativo: [nome]; Merchant ID: [ID da loja de teste]; Contact: [email/telefone]; Status: Pronto para homologação.
3. Contato: a equipe iFood confirma pré-requisitos, agenda data/hora (horário comercial do Brasil) e solicita acesso remoto (TeamViewer ou similar).
4. Execução no dia: ambiente 100% funcional, loja de teste limpa, acesso remoto, documentação à mão.

Sessão: duração estimada 45-60 minutos: boas-vindas (5), setup/compartilhamento de tela (5), testes funcionais (30: recepção, fluxo completo assign → coleta → entrega → código, tratamento de erros), não funcionais (10: expiração de token, rate limit, erros), revisão de logs (5), conclusão (5). "Foco em funcionalidade: não em UI/UX"; "Sequência correta é crítica".

Resultados: Aprovado = pode ir para produção, certificado de homologação, acesso às credenciais de produção, suporte prioritário. Reprovado = relatório detalhado das falhas (exemplos: não processa DDCR, não envia ACK no polling, não renova token, não respeita 429), corrigir, **aguardar 15 dias** e agendar nova homologação.

Checklist final 24 h antes: loja de teste limpa, API respondendo, polling recebendo eventos, webhook registrado (se aplicável), teste completo fim-a-fim, sem erros nos logs, internet estável, documentação atualizada, contato técnico confirmado, credenciais corretas.

Homologação do módulo Events (dentro de `polling-overview`), também aplicável: executar `GET /events:polling` a cada 30 s; usar `x-polling-merchants`; filtrar eventos por tipo/grupo "conforme necessário"; ACK imediato após receber eventos (200); para integradoras logísticas, `excludeHeartbeat=true`. "Testamos o aplicativo completo, não apenas chamadas individuais"; contas CPF não aceitas. (As páginas gerais "Política de homologação" e "Critérios de homologação" de Getting Started não foram lidas.)

Prazo/tempo de espera entre o pedido do ticket e a data da sessão: (não consta no texto lido).

---

## Inconsistências entre páginas (confirmar na prática)

| Assunto | Variante A | Variante B |
|---|---|---|
| URL base do Logistics | `https://merchant-api.ifood.com.br/logistics/v1.0/orders/{id}[/ação]` (order-structure, endpoints) | `https://api.ifood.com.br/logistics/v1.0/orders/{id}/assignDriver` (introdução); `https://api.ifood.com.br/logistics/orders/ID` e `.../events/v1.0/events:polling` (best practices, sem `v1.0` no logistics) |
| Validade do token | 6 h (`expiresIn: 21600`, página de autenticação) | "padrão: 3 horas" (introdução do Logistics); `expires_in: 3600` (exemplo genérico de best practices) |
| Resposta das ações | 202 Accepted sem body (introdução, endpoints) | "200 OK" (homologação) |
| Limite do ACK | até 2000 IDs (conceitos do polling) | máximo 10000 (referência do polling, 413) |
| `x-polling-merchants` | "até 500 merchants por requisição" | máximo 100 por requisição (referência, centralizados, 400) |
| `excludeHeartbeat` | parâmetro de query (`events:polling?excludeHeartbeat=true`) | listado entre os Headers na referência do polling |
| Endpoint e formato do ACK | `POST /events/v1.0/events/acknowledgment`, corpo `[{"id": "..."}]` (Events) | `/events/v1.0/events:acknowledgement` com `{"acknowledgements":[{"id","status":200}]}` (homologação do Logistics); `/events:acknowledgment` e `/events:pooling` (texto da página endpoints); `POST /order/v1.0/orders:acknowledgment` com `{"acknowledgedEventIds": [...]}` e polling em `/order/v1.0/orders:polling` com resposta `{"events":[...]}` (guia do módulo Order) |
| Resposta do `verifyDeliveryCode` | `200 {"success": true/false}` (endpoints, eventos) | 422 para código incorreto, 412 para inelegível (introdução); 400/422/404 (best practices); `200 {"valid": true}` (módulo Order) |
| Respostas vazias do polling | 204 sem eventos (polling) | `{"events": []}` (best practices) |
| Rate limit do polling | 6000 RPM por token (Events) | "100 requisições por minuto para a maioria dos endpoints" e "Faça polling a cada 30-60 segundos" (Logistics) |
| Evento de pedido novo | `PLACED` (código `PLC`) | exemplo do Order mostra `CONFIRMED`/`ORDER_CONFIRMED` como "novo pedido"; best practices cita "ORDER_CREATED", que não existe no catálogo |
| `salesChannel` | obrigatório (tabela) | ausente no exemplo do polling e no exemplo do DDCR |
| Tipo do `phone` | opcional | os exemplos sempre trazem `phone` |

---

## Lacunas

O que a documentação lida **não** responde:

1. **Qual evento marca o pedido como disponível para a logística.** O Logistics diz "Receber pedidos - Via polling ou webhook" e "Obter detalhes", sem nomear PLC, CFM ou RTP. Para pedidos `deliveredBy: MERCHANT`, o restaurante confirma/prepara; o evento que libera `GET logistics/orders/{id}` (e quando esse GET deixa de dar 404) não é dito. Descobrir com pedido de teste. No módulo Order há a regra "PLACED pode chegar antes dos detalhes; 404 → retry com backoff por até 10 min" (aplicada ao `order/v1.0`).
2. **Como listar as lojas (merchants) vinculadas** após o vínculo distribuído (endpoint do módulo Merchant, não lido). O nome do campo do refresh token na resposta, sua validade e se ele é rotacionado.
3. **Metadata por evento** de quase todos os eventos DELIVERY (GTO, AAO, CLT, AAD, DDCR, DDCS etc.), do CAN, CARF, HSD, HSS, DAD, DGU. Só ADR, CAR, DAR, DGAC, RDR e OPA têm exemplo extraído. O exemplo do DDCR da página `endpoints` não traz `code`; o código que o cliente informa (em pedidos de entrega própria) vem de onde (do cliente, pelo localizador/telefone?) **não é dito**. O `code` no metadata só consta para Sob Demanda (POS).
4. **Mapeamento ação → evento de retorno.** A documentação diz só que o "resultado virá via evento". Não afirma que `dispatch` gera CLT ou DSP.
5. **Webhook** (registro da URL, formato do envelope, retentativas, validação de assinatura, presença): páginas não lidas.
6. **Como o iFood trata a presença/abertura da loja** além de `excludeHeartbeat=true` e o "Status de Conexão" (página não lida).
7. **Erros específicos por ação** (400/404/409/412/422 por endpoint) e o formato real do corpo de erro; o formato do telefone (`workerPhone`), campos opcionais do `assignDriver`, troca/realocação de entregador, cancelamento da entrega pelo parceiro no Logistics, retorno ao restaurante (DRGO/DRDO) e devolução (DRCR).
8. **Comportamento quando o pedido é cancelado (CAN) com entrega em andamento** e quais ações ainda são aceitas (só "pedido cancelado" como causa de 412 no `verifyDeliveryCode`).
9. **Pedido de teste:** como gerar ("Gerar pedido de teste" em Getting Started, não lida), se coordenadas 0,0 são regra, e se a loja de teste vem do ticket de homologação ("Solicitar acessos", não lida).
10. **Limites exatos de rate limit por endpoint** do Logistics (a página aponta para "Rate Limit", não lida) e o rate limit do `GET logistics/orders/{id}`.
11. **Endpoint de validação do código de coleta** (DPCR, `HANDSHAKE_VALIDATION_TYPE=CODE`): nome/URL.
12. **Prazos** entre o ticket de homologação e a sessão, e as exigências de homologação gerais (Getting Started > Homologação).
13. **Valor da corrida / frete / total do pedido / itens com nome e preço** no objeto do Logistics: ausentes (só `payments`). Para o cálculo por km do Entregas isso não importa, mas o valor a cobrar do cliente é `payments.pending`.
14. **A FAQ sobre refresh token no fluxo centralizado** (o texto remete a ela, não lida) e a página "Erros e troubleshooting" de Authentication (não lida).

---

## Descobertas da sonda (2026-10-05, loja de teste, app centralizado de teste)

Observado com `scripts/ifood-sonda.mjs` nos pedidos de teste 9753 (`efebeaaf…`) e 5397 (`ec4e4654…`). Vale mais que o texto
da documentação onde os dois divergem.

- **Token (`client_credentials`)**: resposta só com `accessToken`, `type` (`bearer`) e `expiresIn` (21599). URL base
  `https://merchant-api.ifood.com.br` funciona para auth, events, logistics e order.
- **Polling** `GET /events/v1.0/events:polling?excludeHeartbeat=true` + header `x-polling-merchants: <merchant UUID>`:
  200 com array ou 204 sem eventos. Cada evento: `id, code, fullCode, orderId, merchantId (UUID), createdAt,
  salesChannel ("IFOOD")` e às vezes `metadata`. **Ack** `POST /events/v1.0/events/acknowledgment` com `[{id}]` → 202.
- **Sequência real de um pedido de teste**: PLC → (≈107 s depois, o próprio ambiente de teste confirma:
  `metadata.CLIENT_ID = "ifood:iconnect_v3_homologation"`, `ORIGIN = "ORDER_API"`) CFM → DDCR (0,4 s depois do CFM).
  - **O pedido fica disponível para a logística já no PLC**: `GET /logistics/v1.0/orders/{id}` respondeu 200 e
    `assignDriver` e `goingToOrigin` responderam 202 antes do CFM.
  - **O DDCR chega na confirmação**, não na chegada ao cliente, e **sem metadata**. Saber se o pedido exige código não
    precisa de polling imediato na hora de concluir: basta ter recebido o DDCR antes.
  - **As nossas ações não geram evento de volta** para o nosso app (nenhum evento depois de assignDriver,
    goingToOrigin, arrivedAtOrigin, dispatch e arrivedAtDestination). O efeito aparece no Gestor de Pedidos
    ("Em rota", "Entregador chegou no cliente", "Confirmação de entrega pendente"). Portanto o estado das ações tem de
    ser guardado do nosso lado (a `ultima_acao` da spec), não deduzido dos eventos.
- **Ações de logística** (`POST /logistics/v1.0/orders/{id}/<ação>`): todas 202 com corpo vazio, inclusive fora da
  ordem "loja confirmou". `assignDriver` aceitou `{"workerName","workerPhone":"16999990000","workerVehicleType":"MOTORCYCLE"}`.
- **`verifyDeliveryCode` com código errado: HTTP 400** `{"errorType":"NOT_FOUND","description":"Confirmation code is
  invalid","code":"400"}` (não 422). O código certo é o que **o cliente vê no app dele**; não está em nenhum campo do
  pedido (o `delivery.pickupCode` do módulo Order é o código de **coleta**, e não serve). No pedido de teste não há
  como obtê-lo: a confirmação fica para a sessão de homologação. Alternativa do iFood para o entregador:
  `https://confirmacao-entrega-propria.ifood.com.br/` (pede o localizador e o código do cliente). Sem confirmação, o
  iFood conclui sozinho 4 h depois (restaurante com entrega própria).
- **Pedido do Logistics** (campos de topo): `id, orderType, delivery{mode, deliveredBy:"MERCHANT", deliveryDateTime,
  observations, deliveryAddress{streetName, streetNumber, formattedAddress, neighborhood, complement, postalCode, city,
  state, country, reference, coordinates{latitude, longitude}}}, orderTiming, displayId, createdAt, isTest, merchant{id,
  name, merchantAddress{country, state, city, district, street, number, postalCode, latitude, longitude}},
  customer{name, id, phone{number, localizer, localizerExpiration}}, items[{index, name, quantity, options[{name,
  quantity}]}], total{additionalFees, subTotal, deliveryFee, benefits, orderAmount}`.
  - **Pedido todo pago online não traz `payments`** no Logistics (o módulo Order traz `payments{prepaid, pending,
    methods[...]}`). Regra: sem `payments` no Logistics = nada a cobrar na porta. O gerador de pedidos de teste só faz
    pedido pago online; o formato com `pending > 0` (dinheiro/troco) não pôde ser visto.
  - Pedido de teste: `isTest: true`, coordenadas de entrega 0,0, cidade "TESTE".
- **CAN** traz `metadata.CANCEL_ORIGIN` (ex.: `RESTAURANT`), `CANCEL_CODE`, `CANCEL_REASON`, `CANCEL_STAGE`,
  `CANCELLATION_OCCURRENCE.CONSUMER.FINANCIAL_OCCURRENCE` (ex.: `ESTORNO_TOTAL_PAGAMENTO_ONLINE`). CAR vem antes, com
  `details` e `reason_code`.
- `POST /order/v1.0/orders/{id}/confirm` sem corpo falhou duas vezes com erro de rede no Node (não investigado; o
  ambiente de teste já confirma sozinho).
