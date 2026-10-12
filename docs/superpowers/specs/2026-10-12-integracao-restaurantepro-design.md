# Integração RestaurantePro → Entregas (pedidos de delivery)

Decisão de 2026-10-12. Os pedidos de delivery do cardápio do RestaurantePro (`restaurantepro-main`) passam a entrar no Entregas e vão aos motoboys pela distribuição. O contrato é genérico: outros sistemas (Saipos, Anota AI…) poderão usar a mesma API depois.

## Decisões

| Tema | Decisão |
|---|---|
| Ligação | Chave por loja, gerada no Entregas (tela Lojas) e colada no RestaurantePro. A chave só cria, consulta e cancela pedidos daquela loja. |
| Momento do envio | Automático quando o pedido do RestaurantePro entra em `preparing` (impressão da comanda ou kanban), como a Foody. |
| Foody | Um provedor por vez por loja (Nenhum, Foody ou Entregas). Ligar um desliga o outro. |
| Cobrança na porta | Estruturada (`dinheiro`, `cartao`, `pix`, `pago`), com a faixa amarela no app, como no iFood. Exige APK novo. |
| Cancelamento | Sempre aceito: sem aceite ou antes de "A caminho" cancela sem pagar; depois de "A caminho" cancela como **pago mesmo cancelado** (regra do iFood); concluído = 409. |
| Rastreio do cliente | No "A caminho", o RestaurantePro manda o WhatsApp com `https://entregas.restaurantepro.com.br/~/track-order?order=<código>` e o código de rastreio no texto (a página aceita `?order=` e abre preenchida). |
| Arquitetura | API própria na `api/app` (`v1/entregas/integracao/*`), fora da API v1 do Fleetbase. Avisos de volta por webhook assinado. |

Por que não a API v1 do Fleetbase (`POST v1/orders`): a chave Fleetbase dá acesso à organização inteira, os webhooks do Fleetbase são por organização (a loja A receberia os eventos da loja B), o `dropoff` com endereço sempre consulta o Google Geocoding (pago) e o contrato (waypoints, meta) é ruim de documentar para terceiros.

## Parte 1: chave e API de entrada (Entregas)

### Chave da loja

- **Onde:** Fleet-Ops → Recursos → Lojas → card da loja → bloco "Integração" → **Gerar chave** (só admin).
- **Formato:** `ent_live_` + 40 caracteres aleatórios (`random_bytes`, base62). Mostrada **uma vez**. Guardamos só o SHA-256 e os 4 últimos caracteres (exibidos na tela).
- **Gerar de novo** revoga a anterior. **Revogar** desliga a integração da loja (as chamadas passam a dar 401 e os avisos param).
- **Tabela `entregas_integracao_lojas`:**
  - `id`, `company_uuid`, `vendor_uuid` (único entre as não revogadas);
  - `chave_hash` (único), `chave_final`;
  - `sistema` (texto informado na conexão, ex.: "RestaurantePro");
  - `webhook_url`, `webhook_segredo` (cifrado com `encrypt()`);
  - `conectada_em`, `ultimo_uso_em`, `ultima_falha_aviso_em`, `ultima_falha_aviso` (texto curto, sem corpo de resposta);
  - `revogada_em`, `created_at`, `updated_at`.
- Datas em TIMESTAMP gravadas no fuso do app (ver "Fuso" no `CLAUDE.md`).

### Autenticação e rotas

- Prefixo `v1/entregas/integracao`, **fora** do grupo `fleetbase.api` (que exige a chave Fleetbase). Middleware próprio `ChaveDeIntegracao`: lê `Authorization: Bearer ent_live_…`, procura pelo hash, recusa revogada, confere a loja (Vendor existe, não apagado, da empresa) e põe a loja e a empresa no request. Atualiza `ultimo_uso_em` no máximo uma vez por minuto.
- Limitador `entregas-integracao`: 60 chamadas por minuto por chave.
- Interruptor geral `ENTREGAS_INTEGRACAO=1` (`config('services.entregas.integracao')`, no `x-api-env` do `deploy/docker-stack.yml`). Vazio: todas as rotas respondem 409 `{"errors": ["Integração desligada."]}` e nenhum aviso sai.
- **`ProtegerPortalLoja`** (global) não pode barrar a chave nova: ele identifica usuário de loja pelo token Sanctum; um Bearer `ent_live_…` não é token Sanctum. **Conferir na implementação** que ele deixa passar (teste dedicado).
- Erros sempre `{"errors": ["…"]}`: 401 (chave ausente, inválida ou revogada), 422 (validação), 409 (integração desligada, loja desativada, regra de estado), 429 (limite).

| Rota | Função |
|---|---|
| `POST conexao` | `{sistema, webhook_url}` → grava os dois, gera um `webhook_segredo` novo e responde `{loja: {nome}, webhook_segredo}`. O segredo só sai aqui. Chamar de novo troca URL e segredo. |
| `POST pedidos` | Cria o pedido (corpo abaixo). |
| `GET pedidos/{id}` | Status, motoboy (nome e foto), `codigo_rastreio`, `link_rastreio`, cancelamento. |
| `POST pedidos/{id}/cancelar` | Cancela (ver Parte 2). |

`{id}` aceita o `pedido_externo_id` ou o `public_id` do Order (`order_…`), sempre filtrado pela loja da chave.

### Validação da `webhook_url`

Só `https`, com host resolvido para IP público: recusa `localhost`, `127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`, `::1`, `fc00::/7` e nomes internos do Swarm (sem ponto). A conferência do IP se repete no envio de cada aviso (DNS pode mudar).

### `POST pedidos`

```json
{
  "pedido_externo_id": "ord-6f1c…",
  "numero": "4821",
  "cliente": { "nome": "Maria Souza", "telefone": "16999990000" },
  "entrega": {
    "endereco": "Rua Olinda, 45",
    "complemento": "Ap 12",
    "bairro": "Jardim Paulista",
    "cidade": "Ribeirão Preto",
    "uf": "SP",
    "cep": "14090-000",
    "referencia": "",
    "latitude": -21.1931,
    "longitude": -47.8122
  },
  "cobranca": { "forma": "dinheiro", "valor": 58.90, "troco_para": 100 },
  "observacoes": "Sem cebola"
}
```

- **Obrigatórios:** `pedido_externo_id` (até 100), `numero` (até 20), `cliente.nome`, `entrega.endereco`, `entrega.cidade`, `cobranca.forma`.
- **`cobranca`:** `forma` ∈ `dinheiro | cartao | pix | pago`. `valor` obrigatório e > 0 fora de `pago`; até R$ 100 mil. `troco_para` só com `dinheiro` e ≥ `valor`.
- **Idempotência:** `pedido_externo_id` é único por loja. Repetir devolve 200 com o mesmo pedido (corpo igual ao 201), sem criar outro, mesmo que o corpo novo seja diferente.
- **Coordenadas:** opcionais. Sem elas, uma consulta ao Google Geocoding com a chave de Admin → Serviços (endereço, bairro, cidade, UF, CEP; `region=br`), com log `[entregas] integração: endereço geocodificado`. Ponto ausente, inválido (0, fora da faixa) ou a mais de 50 km da coleta: o pedido nasce **sem despacho**, com "[SEM LOCALIZAÇÃO]" nas notas e a entrega ~1 km ao norte da loja (regra do iFood), e a central atribui.
- **Resposta 201:** `{id, codigo_rastreio, link_rastreio, status}`. `link_rastreio` = `https://entregas.restaurantepro.com.br/~/track-order?order=<codigo_rastreio>` (domínio do console por configuração, `ENTREGAS_CONSOLE_URL`).

## Parte 2: ciclo do pedido e avisos de volta (Entregas)

### Criação

`App\Support\Entregas\Integracao\CriadorDoPedidoDaIntegracao`, no molde do `CriadorDoPedidoIfood`, numa transação:

- Place da entrega (sem dono) com endereço estruturado (`street1` = endereço, `street2` = complemento, `neighborhood`, `city`, `province`, `postal_code`, `country` BR) e as coordenadas. **Sem** `Place::createFromMixed` (ele geocodifica sempre).
- Payload com a coleta no Local da loja.
- Order: cliente = Vendor da loja, tipo `transport`, `internal_id` = `numero`, notas `"RestaurantePro #4821"` (o `sistema` da conexão + número) seguidas das observações.
- Linha em **`entregas_integracao_pedidos`**: `integracao_loja_id`, `company_uuid`, `order_uuid` (único), `pedido_externo_id` (único com a loja), `numero`, `cliente_nome`, `cliente_telefone`, `complemento`, `referencia`, `cobranca_forma`, `cobranca_valor`, `cobranca_troco`, `observacoes`, `ultimo_aviso`, `cancelado_em`, `cancelado_por` (`loja | central | sistema`), `pago_mesmo_cancelado`, timestamps. Fora do `meta` de propósito (o `meta` sai na API v1 e no socket).
- Despacho como o do iFood: adhoc + `firstDispatchWithActivity`, com a `TravaDoPedido` e o pedido relido. Com a distribuição ligada, entra na oferta um a um / rodadas.

### Cancelamento pela origem

`POST pedidos/{id}/cancelar`, com a `TravaDoPedido` (`App\Support\Entregas\Integracao\CancelamentoPelaOrigem`):

| Estado do Order | O que acontece | Resposta |
|---|---|---|
| Sem aceite (`started` falso) | `saveQuietly` (`dispatched`/`adhoc` falsos) + `Order::cancel()`; a distribuição encerra pelo observador. | 200 `{"resultado": "cancelado"}` |
| Aceito, antes de `enroute` | `Order::cancel()`; o motoboy recebe "Pedido #4821 cancelado pela loja." | 200 `cancelado` |
| `enroute` | `Order::cancel()` + `pago_mesmo_cancelado`; o push acrescenta "Você recebe por esta entrega. Combine com a loja a devolução." | 200 `cancelado_pago` |
| Concluído | Nada. | 409 `{"resultado": "ja_concluido"}` |
| Já cancelado | Nada. | 200 com o resultado gravado |

- **Pago mesmo cancelado** entra no relatório, na cobrança da loja, no extrato do portal e nos ganhos do app pela data do cancelamento, como o `cancelado_pago` do iFood: o `CalculoEntregas::pedidosConcluidos` passa a ler também esta tabela.
- A central (console, API v1) e a loja (portal, antes do aceite) continuam cancelando; o cancelamento sai no aviso com `por: central` ou `por: loja`. Pedido cancelado por outro caminho grava `cancelado_em`/`cancelado_por` pelo observador.

### Avisos de volta (webhook)

- **Eventos**, nesta ordem:
  - `pedido.aceito` — `motoboy: {nome, foto_url}`. Repetido quando o motoboy muda (troca pela central ou pelo líder).
  - `pedido.a_caminho` — status `enroute`.
  - `pedido.concluido`.
  - `pedido.cancelado` — `por` e `pago`. Encerra a sequência (pode vir em qualquer ponto).
- **Corpo:** `{evento, pedido_externo_id, id, status, motoboy, codigo_rastreio, link_rastreio, cancelamento: {por, pago} | null, ocorrido_em}` (ISO com `-03:00`).
- **Cabeçalhos:** `X-Entregas-Evento`, `X-Entregas-Id` (uuid do aviso; o destino descarta repetido) e `X-Entregas-Assinatura: sha256=<hex do HMAC-SHA256 do corpo cru com o webhook_segredo>`.
- **Gancho:** `Order::updated` com mudança em `status`, `driver_assigned_uuid` ou `started` → `ObservadorDosPedidosDaIntegracao` (nunca lança; só pedidos com linha na tabela) → job `EnviarAvisoDaIntegracao` (fila `default`, `afterCommit`, um por pedido de cada vez, com marca no cache como o `EnviarAcaoIfood`).
- **O job não recebe o evento:** relê o pedido, calcula o alvo pelo estado e envia, em ordem, o que falta depois do `ultimo_aviso` (funções puras em `SequenciaDaIntegracao`). Cada aviso aceito grava o `ultimo_aviso` (e o motoboy avisado) na hora.
- **Entrega:** timeout de 5 s; qualquer 2xx vale. Falha (rede, timeout, não 2xx): até 5 tentativas em 30 min (10 s a 5 min). Esgotadas: grava `ultima_falha_aviso(_em)` na loja, log `[entregas] integração: aviso não entregue` e para; a próxima mudança do pedido ou a reconciliação tenta de novo. 410 Gone = o destino desistiu da integração: log e para sem retentativa.
- **Reconciliação:** `entregas:integracao-acompanhar` a cada minuto (`withoutOverlapping(5)`, em segundo plano), para os pedidos das últimas 24 h com estado além do `ultimo_aviso` e sem job pendente. Cobre o `saveQuietly` e jobs perdidos com o Redis reiniciado.
- Integração revogada ou `ENTREGAS_INTEGRACAO` vazia: nenhum aviso sai.

### Logs

Prefixo `[entregas] integração:`, só ids e números: `chave gerada`, `chave revogada`, `conectada`, `pedido criado`, `pedido repetido`, `endereço geocodificado`, `pedido sem localização`, `pedido cancelado pela origem`, `aviso enviado`, `aviso não entregue`, `webhook recusado (URL privada)`.

## Parte 3: lado do RestaurantePro (`restaurantepro-main`)

No molde da integração Foody (`supabase/functions/_shared/foody.ts`, `foody-tick`, `foody-webhook`, `foody-delivery`, `foody_integrations`). Deploy do próprio RestaurantePro (migrations via MCP, edges com `supabase functions deploy`, front na Vercel).

### Ligação

- Configurações de delivery: seletor **"Entregas por"** (Nenhum, Foody, Entregas RestaurantePro).
- Edge `entregas-delivery` (JWT + perfil + permissão, como a `foody-delivery`), ações `status`, `connect`, `disconnect`:
  - `connect`: recebe a chave, chama `POST conexao` com `webhook_url` = `https://api.restaurantepro.com.br/functions/v1/entregas-webhook?t=<webhook_token>`, guarda a chave e o `webhook_segredo` cifrados no Vault e **desliga a Foody** da loja (a tela confirma antes);
  - `disconnect`: desliga e rotaciona o `webhook_token`;
  - a chave nunca volta ao navegador.
- Tabela `entregas_integrations` (só service_role): `company_id` PK, `enabled`, `encrypted_token`, `encrypted_webhook_secret`, `webhook_token` (único), `loja_nome`, `connected_at`, `last_error`, `created_at`, `updated_at`.
- Exclusividade: o `connect` de um provedor desliga o outro, e o trigger de cada um confere que o outro está desligado.
- URL da API do Entregas por variável da edge (`ENTREGAS_API_URL`, padrão `https://entregas-api.restaurantepro.com.br`).

### Pedido

- Colunas em `orders`: `entregas_id` (índice único parcial), `entregas_status`, `entregas_tracking_code`, `entregas_tracking_url`, `entregas_driver_name`, `entregas_error`.
- Trigger `orders_entregas_enqueue()` (`WHEN old.status IS DISTINCT FROM new.status`):
  - `preparing` + `order_type = 'delivery'` + sem `entregas_id` + integração ligada → `entregas_dispatch` na `message_outbox`;
  - `cancelled` com `entregas_id` e `entregas_status` não terminal → `entregas_cancel`;
  - amplia o CHECK `message_outbox_kind_check`.
- Edge `entregas-tick` (pg_cron por minuto com `x-cron-secret`, claim pelas RPCs da `message_outbox`):
  - geocodifica o `delivery_address` no Google (reaproveitando a função da Foody; sem ponto, envia sem coordenadas e o Entregas tenta);
  - pagamento: `money` → `dinheiro` (valor = `total`, troco = `payment_change_for`), `card` → `cartao`, `pix` → `pix`, `pix_online`/`card_online` com `payment_status = 'PAID'` → `pago`;
  - `numero` = código curto de 4 dígitos (`utils/orderCode.ts`, espelhado no `_shared`);
  - `pedido_externo_id` = `orders.id` (retentativa segura);
  - grava `entregas_id`, `entregas_status`, o código e o link; erro → `entregas_error`;
  - `entregas_cancel` → `POST pedidos/{id}/cancelar`.
- Edge `entregas-webhook`:
  - identifica a loja pelo `webhook_token` da URL e confere o HMAC com o segredo decifrado (assinatura errada → 401; loja desconhecida → 200 e ignora);
  - erro de banco → 500 (o Entregas tenta de novo);
  - `pedido.aceito` → `entregas_driver_name`; `pedido.a_caminho` → `out_for_delivery` + código e link; `pedido.concluido` → `completed`; `pedido.cancelado` → `cancelled`;
  - cerca otimista: nunca volta status (`completed`/`cancelled` não mudam) e descarta `X-Entregas-Id` repetido.
- WhatsApp no "A caminho": template `pedido_a_caminho_v1` com `…/~/track-order?order=<código>` e o código no texto. Seguir o que a Foody faz para não sair um segundo WhatsApp pelo aviso genérico de status (`orders_status_notify_enqueue_trg`).

### Tela

- Card do pedido: selo "Entregas", situação ("procurando motoboy", "com <nome>", "a caminho", "entregue", "cancelado") e o link de rastreio.
- Com `entregas_error`: a mensagem e o botão **"Tentar de novo"** (reenfileira o `entregas_dispatch`; não é envio manual).

## Parte 4: app, console e portal (Entregas)

### App do motoboy (`entregas-navigator`)

- Rota `GET v1/entregas/motoboy/pedidos/{id}/integracao` (limitador próprio, 120 por minuto por motoboy): sistema, número, cobrança em texto, observações, complemento, referência, cancelamento. O telefone do cliente só para o motoboy do pedido, com o pedido em andamento (regra do 0800 do iFood).
- O `PedidoIfood` vira um componente de origem: selo "RestaurantePro #4821" e a faixa amarela de cobrança no card de aceitar e nos detalhes; "Ligar para o cliente" com o telefone real.
- O `CartaoDoAlarme` preenche o `entregas_cobrar` também com a cobrança da integração (o cartão nativo já lê esse campo).
- Sem código de entrega: conclusão de sempre.

### Console

- Selo de origem (util generalizado: iFood ou o `sistema` da integração) na tabela, no quadro, no mapa e no cabeçalho.
- Painel "Integração" no detalhe do pedido (só admin; `GET int/v1/entregas/pedidos/{id}/integracao`): sistema, id externo, cobrança, último aviso entregue, última falha.
- Tela Lojas: bloco "Integração" no card (Gerar chave com a chave mostrada uma vez, Revogar, situação "Conectada a RestaurantePro · último uso há 3 min" ou a falha do último aviso). API `int/v1/entregas/lojas/{id}/integracao*`, só admin.
- Pagamento e cobrança: `cancelado_pago` também para os pedidos da integração.

### Portal da loja

- Selo de origem na lista, na tabela e no cabeçalho.

## Testes

- php-wasm (`scripts/teste-php/`):
  - `integracao-chave.php`: geração, hash, revogação, 401, `ProtegerPortalLoja` deixando a chave passar, limitador;
  - `integracao-pedidos.php`: criação, idempotência, validação da cobrança, coordenada ausente/inválida/longe, geocodificação;
  - `integracao-cancelamento.php`: as cinco situações e o `cancelado_pago` no `CalculoEntregas`;
  - `integracao-avisos.php`: sequência, troca de motoboy, HMAC, retentativa, 410, URL privada recusada, reconciliação;
  - `imports-dos-providers.php` cobrindo os arquivos novos.
- Front: `node --test` nos utils de origem (console e portal); no app, `scripts/testes/pedido-de-origem.teste.ts`.
- RestaurantePro: testes do mapeamento de pagamento e da conferência do HMAC; `smoke:entregas` no molde do `smoke:foody`.

## Implantação

1. API do Entregas (`bash deploy/atualizar.sh api`), com `ENTREGAS_INTEGRACAO` vazia: migrations, rotas, observador e comando; nada muda.
2. Console (`bash deploy/atualizar.sh console`).
3. `docker-stack.yml` novo no Portainer (variáveis `ENTREGAS_INTEGRACAO`, `ENTREGAS_CONSOLE_URL`; "Re-pull image" desligado).
4. APK novo nos celulares (a trava "Atualize o app" obriga).
5. RestaurantePro: migration, edges e front.
6. `ENTREGAS_INTEGRACAO=1` + Update the stack.
7. Teste com a Terraço Pizza Bar no Entregas ligada a uma empresa de teste no RestaurantePro, motoboys avisados: criar, aceitar, "A caminho" (WhatsApp com o link), concluir; cancelar nas três fases.
8. Primeira loja real.

Se algo der errado: `ENTREGAS_INTEGRACAO=` vazio e Update the stack (o RestaurantePro passa a receber 409 e grava `entregas_error`; a loja pode voltar à Foody pelo seletor).

## Fora desta versão

- Itens do pedido.
- Agendamento (o RestaurantePro não tem).
- Pedido de retirada (não vai ao motoboy).
- Documentação pública da API para terceiros (o contrato já serve; escreve-se quando o primeiro terceiro chegar).

## Planos

1. API do Entregas (Partes 1 e 2, rota do app e do console).
2. Console, portal e APK (Parte 4).
3. RestaurantePro (Parte 3), no repositório `restaurantepro-main`.
