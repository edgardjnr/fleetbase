---
sketch: 005
name: tela-de-pedidos
question: "Como fica a tela /fleet-ops (Pedidos: Mapa, Tabela e Quadro) com o mapa como protagonista, no desktop e no celular?"
winner: null
tags: [console, fleet-ops, pedidos, mapa, tabela, quadro, mobile]
---

# Sketch 005: tela de pedidos (/fleet-ops)

## Pergunta de design
Hoje a tela `https://entregas.restaurantepro.com.br/fleet-ops` (rota `operations.orders.index` do Fleet-Ops) tem
três vistas: Mapa, Tabela e Quadro (`packages/fleetops/addon/templates/operations/orders/index.hbs`).
- **Mapa:** o mapa Leaflet fica em tela cheia. À esquerda estão o menu do Fleet-Ops e a lista "Operações ao vivo", que
  só mostra o nome e se o motoboy está online. Por cima do mapa há uma barra de ícones e um painel de pedidos, que
  abre à direita.
- **Tabela e Quadro:** os do Fleetbase, sem nenhuma personalização.

Edgard pediu para refazer a tela (2026-10-25) com estas decisões:
- **Tarefa principal:** acompanhar no mapa. O mapa é o protagonista; a fila e os motoboys vão para painéis.
- **Escopo:** Mapa, Tabela e Quadro.
- **Visual:** marca Entregas, igual ao painel inicial (sketch 004).
- **Celular:** mapa com uma folha que sobe de baixo.

O painel inicial (sketch 004, em produção) já tem um "Ao vivo" resumido. Esta tela é a operação em tela cheia.

## Como ver
Sirva a raiz do repo (`python -m http.server 5179`) e abra `/.planning/sketches/005-tela-de-pedidos/`. Os capacetes vêm
de `console/public/images/entregas/`.

## Variantes
As três variantes têm as mesmas 4 telas e o botão "Comparar por tela". O cenário pode ser Dia normal ou Horário de
pico.

- **A: Painéis flutuantes**
  - **Mapa:** em tela cheia, com flutuando por cima:
    - as contagens no topo (sem motoboy, atrasados, indo à loja, a caminho, livres), que filtram o mapa e a lista;
    - o painel Pedidos à esquerda e o painel Motoboys à direita, os dois recolhíveis;
    - o detalhe do pedido escolhido, embaixo.
  - **Tabela:** abas por situação, filtros em chips, ordenação por coluna, seleção com ações em massa e paginação.
  - **Quadro:** colunas Sem motoboy, Indo à loja, A caminho e Concluídos.
  - **Celular:** contagens no topo e uma folha com Pedidos e Motoboys.
- **B: Mapa + coluna à direita**
  - **Mapa:** à esquerda, com as camadas no topo (motoboys, pedidos, lojas e rotas, cada uma liga e desliga) e a faixa
    "Agora há pouco" embaixo, onde os eventos chegam ao vivo.
  - **Coluna à direita:** fixa, com as abas Pedidos, Motoboys (agrupados por situação) e Lojas (com o iFood). Ao
    clicar num pedido, a lista dá lugar ao detalhe.
  - **Tabela:** visões salvas numa coluna (Em andamento, Sem motoboy, Atrasados, Perto do prazo, Concluídos…, além das
    "Minhas visões"), filtros em chips e o detalhe ao lado da tabela.
  - **Quadro:** raias por loja cruzadas com colunas por situação.
  - **Celular:** folha com as três abas.
- **C: Mapa imersivo**
  - **Mapa:** noturno, em tela cheia. No topo, a faixa dos motoboys online e um placar com as contagens; embaixo, os
    pedidos em cartões lado a lado, do mais urgente para o menos. O cartão escolhido abre o detalhe e acende a rota.
  - **Tabela:** agrupada por situação, com grupos recolhíveis. Cada linha abre o detalhe com a linha do tempo.
  - **Quadro:** uma coluna por motoboy (livres primeiro), com "Sem motoboy" na frente. Para atribuir, arraste o pedido
    até o motoboy.
  - **Celular:** placar no topo e os cartões deslizando na base.

**O que funciona no sketch:**
- filtros por situação;
- seleção de pedido (acende a rota e apaga o resto) e de motoboy;
- **Atribuir / Trocar motoboy**: livres por distância até a loja, com desempate por menos corridas hoje;
- relógio das ofertas, em rodadas de 50 s;
- motoboys andando no mapa a cada 2 s;
- busca (Ctrl K), ordenação, paginação e seleção em massa;
- tema claro e escuro, larguras 1366, 1440 e 1920, e "Anotações".

## O que olhar
- O mapa como protagonista: o que cobre menos o mapa sem esconder a fila? Painéis flutuantes (A), coluna fixa (B) ou
  cartões na base (C).
- No pico, qual variante mostra primeiro o pedido sem motoboy e o atrasado.
- O mapa noturno da C cansa menos numa tela que fica aberta o dia todo, ou destoa do resto do console?
- **Quadro:** as colunas por situação (A) servem só para ver. As raias por loja (B) respondem "qual loja está
  atrasando". As colunas por motoboy (C) servem para despachar de verdade, arrastando.
- **Tabela:** abas (A), visões salvas (B) ou grupos (C).
- **Celular:** folha com abas (A, B) ou cartões deslizando (C).

## Dados que a tela pede (para a implementação)
- **Já existem:** pedidos em andamento, situação, motoboy, prazo e rótulo no alfinete (`PedidosNoMapa`,
  `entregas/mapa/motoboys`), além de oferta e rodada (`AoVivo` do painel inicial, em `entregas/painel-inicial/ao-vivo`).
- **Também já existem:** Atribuir/Trocar (`painel-inicial/pedidos/{id}/motoboy`, que usa o `TrocaDoMotoboy`) e o socket
  da empresa (`escutar-canal-da-empresa`).
- **"Livre há X min" e corridas hoje por motoboy:** precisam de um campo novo. As corridas vêm de
  `entregas_corridas_finalizadas`.
- **Faixa de eventos (B):** não existe. Seria um feed com os eventos `order.*` do socket, mantido no cliente.
- **Tabela e Quadro:** hoje usam o `Layout::Resource::Tabular` e o `Order::Kanban` do Fleetbase. As variantes trocam
  esses componentes por próprios, que leem a mesma rota de pedidos (`fleet-ops/orders`) com filtros.
- **Quadro por motoboy com arrastar (C):** no soltar, usa o mesmo atribuir. Num motoboy ocupado, vira "entrega junto"
  (`entrega-junto-e-multiplicador`).
