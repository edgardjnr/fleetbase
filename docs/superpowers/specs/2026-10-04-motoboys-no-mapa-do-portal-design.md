# Motoboys no mapa do portal da loja — desenho

Data: 2026-10-04 · Status: aprovado em conversa

## Objetivo

Na tela Pedidos do portal da loja (`/customer-portal/orders`), a loja quer ver o motoboy se deslocando no
mapa grande, como vê no Fleet-Ops (`/fleet-ops/<pedido>`). Hoje o mapa grande mostra só a rota coleta →
entrega, e a posição do motoboy aparece num mapinha no painel "Motoboy", relida a cada 20 s e só com o
pedido aceito há no máximo 4 h.

## Decisões (Edgard, 2026-10-04)

- O mapa do portal mostra **todos os motoboys online, o tempo todo**, cada um com o capacete na cor da
  situação (o mesmo do mapa ao vivo do console) e o nome fixo embaixo. **A loja passa a ver as entregas das
  outras lojas** (risco aceito; ver Riscos). Os offline não aparecem (regra exata em 1.1).
- As posições vêm por **consulta a cada 5 s**, com o capacete deslizando entre os pontos. Socket foi
  descartado (ver abaixo).
- O mapinha do painel "Motoboy" sai.
- A regra "a posição só com o pedido aceito há no máximo 4 h" deixa de existir: com todos os online no mapa,
  ela não protege mais nada.

### Por que não o socket, como no Fleet-Ops

O Fleet-Ops move o motoboy pelo canal `driver.<id>` do SocketCluster, que recebe uma posição a cada ~10 m
andados (`distanceFilter: 10` no app). Esse canal transmite também o **telefone** do motoboy, nunca para e o
nosso socket não confere quem se inscreve. Por isso o id do motoboy (`driver_…` ou uuid) **nunca** vai para
a loja. Um canal secreto por pedido daria tempo real, mas com bem mais peças (evento novo, observador das
posições, fila, socket) e teste só em produção. Com consulta de 5 s e deslize, o movimento parece contínuo,
com uns 5 a 10 s de atraso.

## 1. O que a loja vê

### 1.1 Quem aparece

- **Aparece:** motoboy online (livre, indo à loja ou a caminho do cliente) e motoboy com o app offline que
  **já aceitou** um pedido ainda aberto (está trabalhando; o pedido vale mais que o "online", como no
  console).
- **Some:** motoboy com o app offline e sem pedido aceito. Isso inclui quem a central atribuiu e ainda não
  aceitou: a última posição dele pode ser a casa. Some também quem não tem coordenada válida (nula ou 0,0,
  que é o "sem GPS" do Fleetbase).
- Pedido em andamento segue a regra do console (`SituacaoDoMotoboy`): status fora de
  `StatusDoPedido::ENCERRADOS` e atualizado nas últimas 12 h.

### 1.2 Como aparece

- **Ícone:** o capacete do console (`/engines-dist/images/capacete-*.png`, publicado pelo engine do
  Fleet-Ops), 36 × 36 px, sempre em pé (não gira com o GPS).
- **Nome:** sempre visível, num rótulo escuro fixo embaixo do capacete, no mesmo estilo do console.
- **Cor**, pela regra do console (`SituacaoDoMotoboy::classificar`): verde = livre; amarelo = pedido aceito
  ou atribuído, ainda indo à loja; vermelho = a caminho do cliente (`enroute`). Vale para pedidos de
  qualquer loja. Cinza não aparece no portal.
- Clicar no capacete não faz nada (marcador não interativo): o portal não tem detalhes do motoboy para
  mostrar.

### 1.3 Movimento

- O portal pede as posições a cada **5 s**. Cada capacete desliza em linha reta até o ponto novo em
  4,5 s, então o movimento parece contínuo. Se uma posição nova chegar no meio do deslize, o capacete
  recomeça do ponto onde está.
- **Salto sem deslize:** na primeira posição de cada motoboy, em pulos de mais de **1 km** (GPS voltando
  depois de um tempo sem sinal, por exemplo) e quando o sistema pede menos animação
  (`prefers-reduced-motion: reduce`).

### 1.4 Pedido aberto no detalhe

- O motoboy desse pedido fica por cima dos outros, com o rótulo do nome destacado em azul.
- Quando ele aparece pela primeira vez para aquele pedido, o mapa enquadra coleta, entrega e motoboy (útil
  enquanto ele ainda vai até a loja). Depois o mapa fica parado e só os capacetes andam; a loja arrasta à
  vontade.

### 1.5 Onde e quando

- Tela Pedidos, modo **Mapa**: na lista, no detalhe e no novo pedido. No modo **Tabela** não há mapa nem
  consulta.
- Com a aba oculta, o portal não consulta; volta assim que a aba aparece.
- Painel "Motoboy" do detalhe: sem o mapinha; ficam a foto, o nome e a frase ("Motoca está com o pedido.").

### 1.6 O que nunca vai para a loja

O id do motoboy (`public_id`, uuid), telefone, e-mail, veículo e os pedidos de outras lojas (desses, a loja
vê só a cor do capacete).

## 2. API (`api/app`)

### 2.1 Rota nova

`GET int/v1/entregas/loja/motoboys` → `PortalLojaController@motoboysNoMapa`, no grupo `fleetbase.protected`
das outras rotas da loja. O `ProtegerPortalLoja` já libera `entregas/loja/*` para o usuário de loja; quem não
é loja recebe 404 (`lojaDaSessao`).

```json
{
  "motoboys": [
    {
      "id": "3f9a0c1d2e4b5a69",
      "nome": "Motoca",
      "latitude": -21.1702,
      "longitude": -47.8101,
      "situacao": "coleta",
      "pedidos": ["order_bmzholnp8v"]
    }
  ]
}
```

- `id`: os 16 primeiros caracteres de `hash_hmac('sha256', <uuid do motoboy>, config('app.key'))`. Estável
  (o portal sabe qual capacete mover) e não permite chegar ao `driver.<id>` do socket.
- `nome`: `Driver::name` (lê o usuário do motoboy, carregado junto para não consultar um a um).
- `situacao`: `livre`, `coleta` ou `entrega` (`SituacaoDoMotoboy::classificar`).
- `pedidos`: só os `public_id` dos pedidos em andamento daquele motoboy cujo dono é a loja da sessão (o
  Vendor e o contato do usuário, a mesma lista de donos do `motoboy()`).
- Filtros explícitos de `company_uuid` (o `CompanyScope` não está registrado nesta versão).

### 2.2 Consulta compartilhada com o mapa do console

A consulta dos pedidos em andamento por motoboy (não encerrados, atualizados nas últimas
`SituacaoDoMotoboy::HORAS_PEDIDO_EM_ANDAMENTO` horas) passa para um lugar só, usado pelo
`MapaController@motoboys` e pela rota nova. Ela traz também `started`, `public_id` e `customer_uuid`, que a
rota nova usa para a visibilidade (1.1) e para `pedidos`.

### 2.3 Limite de chamadas

Limitador próprio `entregas-loja-mapa`: 60 por minuto por usuário (`session('user')`, ou o IP sem usuário),
no molde do `entregas-motoboy`. Fica separado do `throttle:60,1` que as outras rotas da loja dividem. Uma aba
aberta faz 12 chamadas por minuto.

### 2.4 Rota do motoboy

`loja/pedidos/{id}/motoboy` deixa de mandar `latitude` e `longitude` (o painel não tem mais mapinha). Saem
junto a regra das 4 h e a constante `HORAS_POSICAO`. O resto da resposta (`nome`, `foto`, `aceitou`) não
muda.

## 3. Portal (`packages/customer-portal/addon`)

| Arquivo | O que muda |
|---|---|
| `utils/motoboys-no-mapa.js` (novo) | Funções puras, sem Ember: `CAPACETES` (caminhos literais completos, para o fingerprint do build trocá-los), `capaceteDaSituacao`, `coordenadaValida`, `distanciaEmMetros` (haversine), `deveSaltar` (primeira posição, > 1 km ou menos animação), `interpolar` e `motoboyDoPedido` |
| `utils/camada-de-motoboys.js` (novo) | Classe que cria, move e remove os marcadores no Leaflet: `atualizar(motoboys, { destaque })` e `destruir()`. Um laço de animação (`requestAnimationFrame`) para todos, que só anima quem mudou de posição. Rótulo com o nome por `textContent`, nunca HTML. Marcador não interativo; o destacado ganha `zIndexOffset` |
| `components/portal/order/workspace/map.js` e `.hbs` | Quando o Leaflet carrega (`setupMap`), cria a camada e inicia o ciclo de 5 s (task do ember-concurrency); ao sair, a task é cancelada e a camada destruída; destaque pelo `public_id` do `@selectedOrder` |
| `services/customer-portal-order-route-preview.js` | Guarda a posição do motoboy do pedido selecionado e a inclui no enquadramento (também no recálculo da rota pelo OSRM, `routesfound`), uma vez por pedido |
| `utils/entregas-pedido.js` | `INTERVALO_MAPA_MS = 5000` |
| `components/portal/order/details/motoboy.hbs` e `.js` | Sai o mapinha (o `LeafletMap` e os getters `posicao` e `temPosicao`) |
| `styles/customer-portal-engine.css` | Rótulo do nome (cópia do `entregas-nome-motoboy` do console, cujo CSS não carrega no portal) e a variante azul do destaque |
| `translations/en-us.yaml` e `pt-br.yaml` | Sai `customer-portal.ui.entregas.courier-position`, que fica sem uso |

O ciclo do mapa usa a mesma `espera()` de `entregas-pedido.js` e o mesmo padrão do acompanhamento do detalhe
(aba oculta: espera o `visibilitychange`). O ciclo de 20 s do detalhe continua igual: ele cuida do painel e
das releituras do pedido.

## 4. Erros e casos de borda

- **Falha na consulta (rede, 429, 500):** os capacetes ficam onde estavam; a espera dobra a cada falha
  seguida até 2 min e volta a 5 s no primeiro sucesso.
- **Motoboy sumiu da resposta** (ficou offline ou sem coordenada): o capacete sai do mapa.
- **Mapa ainda não carregou:** nada a fazer; o ciclo só começa quando o Leaflet fica pronto.
- **Troca para Tabela ou saída da página:** a task é cancelada e a camada é destruída (marcadores e
  animação).
- **Usuário sem loja** (404): nenhum capacete; o ciclo segue com a espera crescente.
- **Nome com HTML:** entra como texto.
- **Muitos motoboys:** um laço de animação só.

## 5. Testes

- **PHP (php-wasm),** `scripts/teste-php/mapa-da-loja.php` (novo), com os stubs no molde do `mapa.php`:
  - visibilidade: offline sem pedido some, offline com pedido só atribuído some, offline com pedido aceito
    aparece, coordenada nula ou 0,0 some;
  - situação: livre, coleta e entrega;
  - `pedidos` traz só os pedidos da loja da sessão;
  - a resposta não tem `uuid`, `public_id`, telefone nem `driver_`;
  - usuário sem loja recebe 404;
  - `motoboy()` sem latitude e longitude;
  - o `mapa.php` do console continua passando depois da consulta compartilhada.
- **`node --test`,** `scripts/teste-portal/motoboys-no-mapa.test.mjs` (novo), para as funções puras.
- **Validação do CLAUDE.md:**
  `node scripts/i18n-check.cjs console dev-engine ember-core ember-ui fleetops fleetops-data iam-engine customer-portal`
  com exit 0, e todo JS alterado parseando com o `@babel/parser` de `console/node_modules/.pnpm/@babel+parser@7*`.
- **Teste de isolamento** (`scripts/teste-isolamento-lojas.mjs`): item novo em que a loja B pede
  `loja/motoboys` e nenhum `pedidos` traz o pedido de teste da loja A; o corpo não tem `driver_`, `uuid` nem
  telefone.
- **No navegador:** build local contra a produção (procedimento do CLAUDE.md) e depois do deploy, com um
  motoboy de verdade andando. Conferir também, na aba Rede, que a consulta para com a aba oculta e no modo
  Tabela.

## 6. Deploy e documentação

- `bash deploy/atualizar.sh api` e depois `bash deploy/atualizar.sh console`; sem migration. No intervalo
  entre os dois, quem estiver com o portal antigo aberto vê o mapinha sem posição, sem quebrar nada.
- Depois do deploy do console: Ctrl+Shift+R, ou janela anônima no portal (a lista de extensões fica 1 h no
  localStorage).
- CLAUDE.md, seção "Portal da loja": a rota `loja/motoboys`, o mapa com todos os motoboys online a cada 5 s,
  a decisão de 2026-10-04, os riscos abaixo e a retirada de "a posição só com o pedido aceito há no máximo
  4 h".

## Fora deste trabalho

- Rastro do caminho já percorrido e capacete girando com a direção.
- Socket (tempo real de ~1 s).
- Cache da resposta no servidor. Se o número de lojas crescer, dá para guardar a parte comum a todas por
  2 a 3 s.
- Avisar os motoboys de que as lojas veem a posição deles (ação da central, fora do código).

## Riscos (aceitos)

- **Entregas das outras lojas visíveis:** a loja acompanha todos os motoboys com a cor da situação. Um
  capacete vermelho que para numa casa indica o endereço de um cliente de outra loja (dado pessoal de
  terceiro), e dá para estimar o movimento dos concorrentes. Decisão do Edgard em 2026-10-04.
- **Motoboy que esquece de ficar offline** ao fim do expediente continua aparecendo, possivelmente em casa:
  o app segue rastreando mesmo fechado (`stopOnTerminate: false`). O código não distingue isso; a central
  deve orientar os motoboys a ficar offline ao terminar e informá-los de que as lojas veem a posição
  enquanto estão online (LGPD).
- **Nome completo** do motoboy visível para todas as lojas.
- **Pedido esquecido aberto:** se o motoboy o aceitou, ele aparece mesmo offline por até 12 h da última
  atualização do pedido (mesma regra do console).
