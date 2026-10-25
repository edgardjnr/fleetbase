# Sketch Manifest

## Design Direction
Portal da loja (Entregas RestaurantePro, `/customer-portal`): manter o visual atual (cores, cantos e fontes do
`customer-portal-engine.css`) e mudar só a estrutura da tela Pedidos: menu retrátil e a lista de pedidos em
andamento no lugar do menu. Nas outras telas o menu continua como hoje. O foco da loja é acompanhar os pedidos no mapa.

Painel inicial do console (sketch 004, 2026-10-25): tela que abre depois do login, para o dono e o despachante
(números do período + operação ao vivo), na marca Entregas da landing (laranja, papel quente, Barlow Condensed;
temas `entregas.css` e `entregas-escuro.css`), com o celular trazendo os mesmos blocos empilhados.

## Reference Points
- O próprio portal em produção (https://entregas.restaurantepro.com.br/customer-portal).
- Mesmas telas em A, B e C, com "comparar por tela" (preferência do Edgard).

## Sketches

| # | Name | Design Question | Winner | Tags |
|---|------|----------------|--------|------|
| 001 | menu-retratil-lista-na-coluna | Como o menu recolhe e a lista de pedidos assume a coluna da esquerda na tela Pedidos? | **B** (menu na gaveta ☰; lista no cartão do menu) | portal-da-loja, layout, menu, pedidos |
| 002 | card-do-pedido | Card com o número do pedido em destaque e sem endereços de coleta e entrega | **S** (A compacto: número + origem + situação; motoboy, cliente, hora e tempo; sem barra e sem endereços) | portal-da-loja, card, pedidos |
| 003 | gaveta-do-portal | Gaveta do ☰ na tela Pedidos opaca e moderna, com loja, resumo do dia, Novo pedido e conta | **B** (marca da loja: topo laranja, resumo em cartões, Novo pedido, itens com ícone colorido, tema e sair no rodapé; opaca) | portal-da-loja, menu, gaveta, pedidos |
| 004 | painel-inicial | Como o painel inicial do console divide números do período e operação ao vivo, no desktop e no celular? | **A** (faixa de 6 números no topo; Ao vivo com fila à esquerda e mapa à direita; corridas por hora, lojas e motoboys; faixa Atenção; barra vermelha no pico; celular empilhado) | console, painel, dashboard, layout, mobile |
