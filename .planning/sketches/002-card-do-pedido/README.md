---
sketch: 002
name: card-do-pedido
question: "Como fica o card do pedido na coluna, com o número do pedido em destaque e sem os endereços de coleta e entrega?"
winner: "S"
tags: [portal-da-loja, card, pedidos, lista]
---

# Sketch 002: card do pedido

## Pergunta de design
No layout escolhido no 001 (B: menu na gaveta ☰, lista no lugar do menu), o card precisa mostrar o **número do
pedido** e deixar de mostrar a coleta (sempre a própria loja) e a entrega. O endereço de entrega fica só no detalhe do
pedido e no alfinete do mapa (decisão do Edgard, 2026-10-19).

## Como ver
Abra `.planning/sketches/002-card-do-pedido/index.html` no navegador.

## Variantes (as mesmas 4 telas em cada uma)
- **A: Número em destaque**: número grande + origem + situação; cliente e hora; barra de progresso; motoboy e tempo.
- **B: Linha compacta**: borda colorida pela situação, número à esquerda, tempo grande à direita, motoboy e cliente
  embaixo. Cabem cerca de 2,5× mais pedidos.
- **S: A compacto (síntese)**: o visual do A em duas linhas, na densidade do B.
- **C: Etapas**: número + trilha de 4 pontos (Criado, Aceito, Coletado, Entregue) com a etapa atual pulsando.

## Resultado
**S (A compacto)** escolhida em 2026-10-19, sem a barra de progresso. Linha 1: número grande, origem (iFood,
RestaurantePro ou Portal) e a situação em etiqueta à direita. Linha 2: capacete e nome do motoboy (ou "procurando
motoboy" em laranja), cliente e hora, e o tempo até a entrega à direita. Sem endereços de coleta e entrega (ficam no
detalhe e no alfinete do mapa).

Telas: 1 Lista (5 pedidos) · 2 Lista cheia (12) · 3 Pedido aberto · 4 Celular. "Comparar por tela" mostra os três.

## De onde vem o número (para a implementação)
- iFood e RestaurantePro: o número já existe no selo de origem (`origemDoPedido(notas, internal_id)` em
  `addon/utils/entregas-pedido.js`, hoje mostrado como "iFood #4821").
- Pedido criado no portal não tem número próprio: o sketch mostra o código de rastreio (o que o card mostra hoje).
- O nome do cliente no card depende do dado chegar no pedido (a conferir na implementação).
