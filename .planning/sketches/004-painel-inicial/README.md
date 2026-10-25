---
sketch: 004
name: painel-inicial
question: "Como o painel que abre depois do login no console divide os números do período (dono) e a operação ao vivo (despachante), no desktop e no celular?"
winner: "A"
tags: [console, painel, dashboard, layout, mobile]
---

# Sketch 004: painel inicial do console

## Pergunta de design
Hoje o painel (`console/app/templates/console/home.hbs` → `<Dashboard @slot="console.home">`) é o padrão do
Fleetbase: os cartões de KPI (ganhos, ticket médio, pedidos ativos, motoboys online), "Frota ao vivo", tendência de
receita, melhores motoboys e manutenção (widgets em `packages/fleetops/addon/extension.js`, `registerWidgets`).
Edgard pediu para refazer a tela inteira, no desktop e no celular, com estas decisões (2026-10-25):
- **Para quem:** dono e despachante, meio a meio (números do período em cima, operação ao vivo embaixo).
- **Visual:** marca Entregas (laranja, papel quente e números em Barlow Condensed, os tokens do `landing.css`).
- **Celular:** os mesmos blocos do desktop, empilhados.

## Como ver
Abra `.planning/sketches/004-painel-inicial/index.html` no navegador. Os capacetes vêm de
`console/public/images/entregas/`. Se o navegador bloquear arquivo local, sirva a raiz do repo
(`python -m http.server 5179`) e abra `/.planning/sketches/004-painel-inicial/`.

## Variantes (as mesmas 4 telas em cada uma, e "Comparar por tela")
- **A: Faixa + sala de controle**: seis cartões de números no topo (Corridas em laranja); seção "Ao vivo" com a fila
  à esquerda (filtros por situação) e o mapa à direita; corridas por hora, lojas e motoboys lado a lado; faixa
  "Atenção" no fim. No pico, uma barra vermelha acima do Ao vivo.
- **B: Mapa no centro**: a primeira tela é a operação. Mapa no centro com as contagens flutuando (filtram a fila) e o
  gráfico no canto; à esquerda, os números do período e "Atenção"; à direita, a fila em cartões com a barra do
  prazo. Lojas e motoboys abaixo da dobra.
- **C: Jornal do dia**: manchete laranja com "142 corridas hoje" bem grande, os outros números e as barras por hora;
  "Agora" em quatro blocos grandes que mudam de cor e têm a ação; fila em tabela com o mapa ao lado; lojas,
  motoboys e "Atenção" em três colunas.

Telas: 1 · Desktop, dia normal; 2 · Desktop, horário de pico; 3 · Celular, dia normal; 4 · Celular, horário de pico.
O pico tem 3 pedidos sem motoboy (oferta em rodadas de 50 s, contando), 2 atrasados, só 2 motoboys livres e o
iFood da Kenzo desconectado.

Funciona: Hoje / 7 dias / 30 dias (números, gráfico, lojas e motoboys), filtros da fila, **Atribuir** (motoboys
livres por distância até a loja, desempate por menos corridas hoje; ao enviar, o pedido vai para "Indo à loja"),
relógio das ofertas, tema claro/escuro, larguras 1366/1440/1920 e "Anotações" (nome de cada bloco).

## O que olhar
- O que aparece sem rolar: números (A), operação (B) ou manchete + "Agora" (C).
- No pico, qual variante faz você enxergar primeiro o pedido sem motoboy e o atrasado.
- Se o laranja da marca cansa numa tela que fica aberta o dia todo (C usa muito; A e B, pouco).
- No celular: mapa logo no topo (B) ou depois dos números (A, C).

## Dados que o painel pede (para a implementação)
- Pedidos ao vivo, situação, motoboy, prazo (`meta` do cronômetro): já existem (lista de Operações e o painel
  "Distribuição"). Rodada e tempo da oferta: `api/app/Support/Entregas/Distribuicao`.
- Corridas, faturado, a pagar, margem e km do período: o Financeiro já calcula (`financeiro.painel`).
- **No prazo (%) e tempo médio**: não há rota; precisa de uma contagem nova na API.
- Corridas por hora e a média dos sábados: rota nova (agregação por hora do dia).
- Situação do iFood por loja: selo/painel iFood (`api/app/Support/Entregas/Ifood`).
- "Atenção": faturas vencidas e em rascunho, pedido sem valor (lista do painel do Financeiro), Pix ausente.

## Resultado
**A (Faixa + sala de controle)** escolhida em 2026-10-25: seis cartões com os números do período no topo (Corridas
em laranja, seletor Hoje / 7 dias / 30 dias e Novo pedido no cabeçalho), seção "Ao vivo" com a fila de pedidos à
esquerda (filtros por situação, Atribuir na oferta, cronômetro do prazo) e o mapa à direita; corridas por hora,
lojas e motoboys lado a lado; faixa "Atenção" no fim. No pico, barra vermelha acima do Ao vivo. No celular, os mesmos
blocos empilhados (números em 2 colunas). A tela é a raiz do console (`/`, `console.home`), não o `/fleet-ops`.
