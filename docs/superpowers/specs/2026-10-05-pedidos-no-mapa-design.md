# Pedidos em andamento no mapa (console e portal da loja)

Data: 2026-10-05. Desenho aprovado pelo Edgard na conversa de 2026-10-05.

## Objetivo

Cada pedido em andamento aparece no mapa como um **alfinete vermelho no endereço de entrega**. A central vê todos os
pedidos no mapa ao vivo do Fleet-Ops, e cada loja vê os dela no modo Mapa da tela Pedidos do portal. O objetivo é que
todos enxerguem onde estão as entregas e decidam melhor (a qual motoboy atribuir, que região está carregada, que pedido
está parado sem motoboy).

## Decisões

1. **A loja vê só os pedidos dela.** A central vê todos. O alfinete marca o endereço do cliente, e mostrar os clientes das
   outras lojas exporia dado pessoal (LGPD) e a carteira de clientes de uma loja para a concorrente. Isso é diferente do
   mapa de motoboys, em que a loja vê todos (risco aceito em 2026-10-04).
2. **Uma cor só: vermelho.** A fase do pedido (esperando motoboy, indo à loja, a caminho do cliente) aparece só no clique.
3. **Período no mapa:** da criação até concluir, cancelar ou expirar, com ou sem motoboy. Pedido parado há mais de 12 h
   (`updated_at`) não aparece, a mesma regra dos capacetes (`SituacaoDoMotoboy::HORAS_PEDIDO_EM_ANDAMENTO`).
4. **Os alfinetes vêm na mesma resposta dos capacetes** (abordagem A). Não há rota nova nem consulta a mais. O alfinete
   muda junto com o capacete, e o limite de requisições do portal não é afetado.

## Servidor

### `App\Support\Entregas\PedidosNoMapa`

`listar(string $companyUuid, ?array $donos = null): array`

- Pedidos da empresa com `status` fora de `StatusDoPedido::ENCERRADOS` e `updated_at` nas últimas
  `SituacaoDoMotoboy::HORAS_PEDIDO_EM_ANDAMENTO` horas, com ou sem `driver_assigned_uuid`.
- Com `$donos` (portal): só os pedidos com `customer_uuid` em `$donos`, que são o Vendor da loja e o contato do usuário,
  o mesmo critério do `pedidos` em `MotoboysNoMapaDaLoja`. Sem `$donos` (console): todos.
- Destino = o `dropoff` do payload do pedido. Pedido sem dropoff, ou com coordenada inválida (fora da faixa ou o (0, 0)
  "sem GPS"), fica de fora. O critério de coordenada é o mesmo do `MotoboysNoMapaDaLoja::coordenadaValida`, extraído para
  um lugar comum e reaproveitado pelas duas classes.
- Ordem: os mais recentes primeiro. **Limite de 300 alfinetes.**
- Consulta com eager loading (payload → dropoff, motoboy → user e, no console, a loja), sem N+1.

Cada item:

| campo | console | portal | conteúdo |
|---|---|---|---|
| `id` | sim | sim | `public_id` do pedido |
| `numero` | sim | sim | número de rastreio, se houver; senão o `public_id` |
| `latitude`, `longitude` | sim | sim | destino |
| `endereco` | sim | sim | endereço do dropoff |
| `status` | sim | sim | `orders.status` cru (o front traduz por `ember-ui.status.*`) |
| `motoboy` | sim | sim | nome do motoboy atribuído ou `null` |
| `criado_em` | sim | sim | `created_at` em ISO 8601 |
| `loja` | sim | **não** | nome do Vendor do `customer_uuid`; sem Vendor, o nome do local de coleta; senão `null` |

O portal **nunca** recebe id do motoboy (uuid ou public_id), telefone, nem pedido de outra loja.

### Respostas

- `MapaController@motoboys` (`GET int/v1/entregas/mapa/motoboys`): passa a devolver `{ motoboys, pedidos }`, com
  `pedidos = PedidosNoMapa::listar(session('company'))`. Mesmo filtro de permissão do resto do mapa
  (`applyDirectivesForPermissions('fleet-ops list order')`).
- `PortalLojaController@motoboysNoMapa` (`GET int/v1/entregas/loja/motoboys`): passa a devolver `{ motoboys, pedidos }`,
  com `pedidos = PedidosNoMapa::listar(session('company'), $this->donosDosPedidos($vendor))`, sem o campo `loja`.
- Console ou portal antigo ignora o campo novo.

## Console (Fleet-Ops → mapa ao vivo)

- `leaflet-live-map.js`: a task `acompanharSituacoesDosMotoboys` também guarda `resposta.pedidos` em
  `@tracked pedidosNoMapa`. A troca só acontece quando algo mudou (comparação como a das situações), para não recriar os
  marcadores à toa.
- `leaflet-live-map.hbs`: um `layers.marker` por pedido, com o ícone de alfinete vermelho e `zIndexOffset` negativo, para
  os capacetes ficarem por cima.
- Popup:
  - número;
  - loja;
  - status traduzido;
  - motoboy ou "Sem motoboy";
  - "há X min", calculado a partir de `criado_em`;
  - endereço;
  - botão **Abrir pedido** (`console.fleet-ops.operations.orders.index.details` com o `public_id`).
- Atualização: a releitura que já existe (eventos `order.*`, `waypoint.*` e `entity.*` do socket, ~0,6 s depois, com a
  reserva de 20 s e a pausa com a aba oculta).
- O ramo do Google Maps (`google-live-map`) fica de fora. O console usa Leaflet.

## Portal da loja (Pedidos → Mapa)

- `Workspace::Map`: o ciclo `acompanharMotoboys` (5 s) também guarda `resposta.pedidos` e desenha os alfinetes da loja.
- O pedido aberto no detalhe já mostra P e D pela rota (`customerPortalOrderRoutePreview`), então o alfinete dele fica
  escondido para não aparecer duas vezes.
- Popup:
  - número;
  - status traduzido;
  - motoboy ou "Sem motoboy";
  - "há X min";
  - endereço;
  - botão **Ver pedido**, que abre o detalhe do pedido (rota `portal.orders.details` com o `public_id`, a mesma do
    `Portal::Order::ListCard`).
- Os capacetes ficam por cima dos alfinetes.

## Ícone

O alfinete vermelho é um SVG embutido num `L.divIcon` (ou data URL), com ponta no ponto exato do destino
(`iconAnchor` na base). O desenho é o mesmo nos dois mapas, e cada pacote tem a sua cópia: o fleetops e o
customer-portal não importam um do outro.

## Funções puras (front)

- `packages/fleetops/addon/utils/entregas-pedidos-no-mapa.js` e
  `packages/customer-portal/addon/utils/pedidos-no-mapa.js`:
  - validar a lista (descartar item sem id ou sem coordenada numérica);
  - tirar o pedido aberto (portal);
  - formatar "há X min" / "há X h";
  - comparar duas listas para decidir se redesenha.

## Erros

- Se a consulta falhar, os alfinetes ficam onde estavam, como os capacetes. No portal vale a espera crescente que já
  existe.
- Se a resposta vier sem `pedidos` (API antiga durante o deploy), os alfinetes não aparecem e nada quebra.
- Se um item vier com coordenada inválida, o front o descarta.

## Textos (pt-BR e en-us)

- Chaves novas em `fleet-ops.ui.map.leaflet-live-map.pedido-*` e `customer-portal.ui.entregas.mapa-pedido-*`:
  - "Sem motoboy";
  - "Abrir pedido";
  - "Ver pedido";
  - "há {n} min";
  - "há {n} h";
  - rótulos do popup.
- O status usa as chaves `ember-ui.status.*`, que já existem.
- Validação: `node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal`.

## Testes

- `scripts/teste-php/pedidos-no-mapa.php` (php-wasm):
  - inclui pedido sem motoboy, com motoboy indo à loja e a caminho do cliente;
  - exclui concluído, cancelado e expirado;
  - exclui pedido parado há mais de 12 h;
  - exclui coordenada inválida e pedido sem dropoff;
  - no portal, só os pedidos dos donos;
  - no portal, sem `loja` e sem nenhum id de motoboy;
  - respeita o limite de 300.
- `scripts/teste-php/mapa.php` e `mapa-da-loja.php`: a resposta traz `pedidos`.
- `node --import ./scripts/teste-portal/resolver.mjs --test scripts/teste-portal/*.test.mjs`: as funções puras do portal
  e, no mesmo estilo, as do fleetops.
- Parse de todo JS alterado com `@babel/parser`.

## Deploy

API e console: `bash deploy/atualizar.sh` (tudo). Não há migration.

## Fora do escopo

- Alfinete no app do motoboy.
- Pedidos de outras lojas no portal (decisão 1).
- Linha entre o alfinete e o motoboy.
- Cor por fase.
- Agrupamento (cluster) de alfinetes.
