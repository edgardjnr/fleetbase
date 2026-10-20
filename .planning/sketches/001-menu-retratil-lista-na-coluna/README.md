---
sketch: 001
name: menu-retratil-lista-na-coluna
question: "Na tela Pedidos do portal da loja, como o menu recolhe e a lista de pedidos assume a coluna da esquerda?"
winner: "B"
tags: [portal-da-loja, layout, menu, pedidos]
---

# Sketch 001: menu retrátil e lista de pedidos na coluna

## Pergunta de design
Hoje o portal (`/customer-portal`) tem o menu fixo de 15,5rem à esquerda e, na tela Pedidos, a lista de pedidos
flutua por cima do mapa (22rem). A ideia: em Pedidos, o menu fica retrátil e a lista ocupa o lugar dele. Nas outras
telas (Início, Extrato, Financeiro, Configurações) o menu volta ao normal. Visual igual ao atual.

## Como ver
Abra `.planning/sketches/001-menu-retratil-lista-na-coluna/index.html` no navegador.

## Variantes (as mesmas 6 telas em cada uma)
- **A: Trilho de ícones**: o menu recolhe para uma faixa de 56 px só com ícones (nome ao passar o mouse); a lista fica
  ao lado; ☰ no trilho abre o menu completo por cima da lista. No Extrato, « recolhe para o trilho. No celular, o
  trilho vira uma barra de abas embaixo.
- **B: Menu na gaveta ☰**: o menu sai da tela e vira um botão ☰ na barra superior; a lista ocupa o cartão do menu e o
  mapa ganha mais espaço; ☰ abre a gaveta por cima de tudo. É o caminho mais curto no Ember (troca a coluna do grid do
  `.portal-main` pela lista e reaproveita o `Layout::Sidebar::Item` dentro de uma gaveta).
- **C: Menu dobrável na coluna**: a coluna continua sendo do menu, dobrado numa faixa de ícones no topo (o item atual
  com nome); a lista fica embaixo; a seta desdobra o menu dentro da coluna, empurrando a lista.

Telas: 1 Pedidos (menu recolhido) · 2 Menu aberto · 3 Detalhe do pedido · 4 Novo pedido · 5 Extrato · 6 Celular.
"Comparar por tela" mostra A, B e C lado a lado.

## Resultado
**B (menu na gaveta ☰)** escolhida em 2026-10-19: em Pedidos, a lista ocupa o cartão do menu e o menu vira uma gaveta
aberta pelo ☰ da barra superior. Nas outras telas, o menu continua na coluna (o ☰ esconde ou mostra).

## O que observar
- Quanto de mapa sobra no notebook (1366) e no monitor (1920; botão na barra de ferramentas).
- Se dá para chegar ao Extrato/Financeiro sem pensar (um clique em A e C, dois em B).
- No detalhe, a lista continua visível com o pedido destacado (hoje a lista some quando o detalhe abre).
- Novo pedido: a lista continua na coluna (hoje ela sai de cena).
- Celular: barra de abas (A), ☰ (B) ou faixa de ícones (C).
