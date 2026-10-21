---
sketch: 003
name: gaveta-do-portal
question: "Como fica a gaveta do menu (☰) da tela Pedidos do portal da loja, opaca e moderna, com a loja, o resumo do dia, Novo pedido e a conta?"
winner: "B"
tags: [portal-da-loja, menu, gaveta, pedidos]
---

# Sketch 003: gaveta do portal

## Pergunta de design
Na tela Pedidos (`/customer-portal/orders`) o menu só aparece como gaveta pelo ☰ (decisão do sketch 001). Hoje ela é
a coluna do menu presa à esquerda, com o fundo **translúcido** (`rgb(255 255 255 / 92%)` no claro e
`rgb(31 41 55 / 80%)` no escuro, do `.portal-sidebar`), e o mapa aparece por trás: fica feia e difícil de ler
(Edgard, 2026-10-21). Ele pediu uma ideia nova e moderna ("me surpreenda") e que a gaveta traga também o **cartão da
loja**, o **resumo do dia**, o atalho **Novo pedido** e a **conta e o tema**.

## Como ver
Abra `.planning/sketches/003-gaveta-do-portal/index.html` no navegador (ou sirva a pasta `.planning/sketches` e abra
`/003-gaveta-do-portal/`).

## Variantes (as mesmas 3 telas em cada uma, e "Comparar por tela")
- **A: Painel sólido**: gaveta opaca de ponta a ponta (estilo Linear/Notion). Cartão da loja com logo de iniciais,
  endereço e "Recebendo pedidos"; Novo pedido; resumo de hoje em três números; item ativo com barra de cor e a
  contagem em Pedidos; conta, seletor Claro/Escuro e Sair embaixo.
- **B: Marca da loja**: topo em bloco laranja (cor da loja) com o nome grande; resumo em três cartões sobrepostos,
  com ícones coloridos; itens maiores com ícone em círculo de cor própria; Novo pedido escuro e grande; chave do
  tema escuro e sair no rodapé.
- **C: Ilha flutuante**: painel solto das bordas, cantos de 22 px e sombra forte; busca "Buscar pedido ou tela"
  (Ctrl K; filtra o menu e acha pedidos pelo número ou cliente); loja numa linha; resumo em chips; menu em blocos
  2×3 com Novo pedido como bloco azul; tema e sair em ícones.

Telas: 1 · gaveta aberta (claro), 2 · gaveta aberta (escuro), 3 · celular (375 px). Em todas, a gaveta é opaca e o
fundo atrás escurece com um leve desfoque; fecha no ✕, no fundo, no Esc e ao escolher um item.

## O que olhar
- A leitura por cima do mapa (claro e escuro): nada de transparência.
- O peso do topo: cartão discreto (A), bloco de cor (B) ou busca primeiro (C).
- Se o resumo do dia e o Novo pedido ajudam ou poluem a gaveta.
- No celular: gaveta de quase toda a largura (A, B) ou ilha com margem (C).

## Dados que a gaveta pede (para a implementação)
- Em andamento: a lista da tela já tem. Motoboys online: o mapa do portal (`MotoboysNoMapaDaLoja`) já tem.
- **Entregues hoje: não existe rota hoje**; precisa de uma contagem nova no `int/v1/entregas/loja/*`.
- Endereço da loja: o Local de coleta do Vendor. "Recebendo pedidos": não há estado de loja aberta/fechada; o selo
  pode sair ou virar outra informação (ex.: vínculo iFood).

## Resultado
**B (Marca da loja)** escolhida em 2026-10-21: topo em bloco laranja com o nome da loja, resumo do dia em três cartões sobrepostos, Novo pedido grande, itens com ícone em círculo colorido e a conta com a chave do tema escuro e o sair no rodapé. Gaveta opaca, fundo com desfoque.
