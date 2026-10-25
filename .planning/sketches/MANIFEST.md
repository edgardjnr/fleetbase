# Sketch Manifest

## Design Direction
Portal da loja (Entregas RestaurantePro, `/customer-portal`): manter o visual atual (cores, cantos e fontes do
`customer-portal-engine.css`) e mudar só a estrutura da tela Pedidos: menu retrátil e a lista de pedidos em
andamento no lugar do menu. Nas outras telas o menu continua como hoje. O foco da loja é acompanhar os pedidos no mapa.

Painel inicial do console (sketch 004, 2026-10-25): tela que abre depois do login, para o dono e o despachante
(números do período + operação ao vivo), na marca Entregas da landing (laranja, papel quente, Barlow Condensed;
temas `entregas.css` e `entregas-escuro.css`), com o celular trazendo os mesmos blocos empilhados.

Tela de pedidos `/fleet-ops` (sketch 005, 2026-10-25): Mapa, Tabela e Quadro refeitos na marca Entregas, com o mapa
como protagonista (acompanhar a operação; fila e motoboys em painéis), e no celular o mapa com uma folha que sobe de
baixo.

Console no celular (sketch 007, 2026-10-25): o console inteiro no celular na marca Entregas, para fazer tudo o que se
faz no PC, com o rodapé do Fleetbase trocado por 5 destinos fixos (Início · Pedidos · Mapa · Motoboys · Mais).

Pedido aberto no console (sketch 008, 2026-10-25): o clique no pedido, em qualquer tela do console, abre o pedido como
no portal (rota no mapa), com o conteúdo da Comanda do motoboy (app, sketch 007 B) e as ações do painel do Fleetbase
no "Mais".

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
| 005 | tela-de-pedidos | Como fica a tela /fleet-ops (Mapa, Tabela e Quadro) com o mapa como protagonista, no desktop e no celular? | **A invertida** (mapa em tela cheia; Motoboys flutuando à esquerda e Pedidos à direita, recolhíveis; contagens no topo filtram; detalhe junto aos Pedidos; Tabela com abas; Quadro por situação; celular com folha de baixo) | console, fleet-ops, pedidos, mapa, tabela, quadro, mobile |
| 006 | moldura-do-console | Como o cabeçalho do console e o menu lateral do Fleet-Ops ficam nas cores do painel, no claro e no escuro? | **C** (moldura em L cor de areia; folha do conteúdo com canto arredondado; módulos em barra segmentada; trilho de ícones em quadradinhos, ativo em laranja; lista direta com títulos de grupo) | console, cabeçalho, menu, sidebar, tema, mobile |
| 007 | console-no-celular | Como fica o console inteiro no celular, na marca Entregas, com um rodapé que substitui a barra do Fleetbase? | **B** Doca flutuante | console, mobile, rodapé, navegação, tema |
| 008 | pedido-aberto-no-console | Como o pedido abre no console (Mapa, Tabela, Painel e celular) com a rota no mapa e a folha no estilo Comanda do motoboy? | **A** (folha do portal: o clique leva ao mapa com a folha à direita e a pílula Voltar; rota do motoboy pelas ruas, feito em cinza e falta em laranja; comanda com cliente, cobrança, 0800, cupom, motoboy e loja; ações do Fleetbase no Mais) | console, pedido, detalhe, comanda, cliente, mobile |
