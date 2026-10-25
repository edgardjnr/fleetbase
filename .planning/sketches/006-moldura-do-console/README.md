---
sketch: 006
name: moldura-do-console
question: "Como o cabeçalho do console e o menu lateral do Fleet-Ops ficam nas cores do painel (marca Entregas), no claro e no escuro?"
winner: null
tags: [console, cabeçalho, menu, sidebar, fleet-ops, tema, mobile]
---

# Sketch 006: moldura do console (cabeçalho + menu lateral)

## Pergunta de design
O painel inicial (sketch 004) e a tela de pedidos (sketch 005) estão na marca Entregas: laranja, papel quente e
Barlow. O cabeçalho e o menu lateral continuam do Fleetbase (azul e cinza, Inter). Edgard pediu para levar os dois às
cores do painel, para seguir um padrão (2026-10-25), com estas decisões:
- **Alcance:** o console inteiro. O cabeçalho é um só, e o menu lateral muda em todos os módulos (Fleet-Ops, IAM,
  Admin).
- **Menu:** trilho de ícones em **todas** as telas do Fleet-Ops, que abre ao passar o mouse (hoje só em Pedidos).
- **Tema:** o cabeçalho e o menu seguem o claro e o escuro, como o painel. Exceção: a faixa da variante B fica laranja
  nos dois.

## Como ver
Sirva a raiz do repo (`python -m http.server 5179`) e abra `/.planning/sketches/006-moldura-do-console/`. Use o tema
Claro/Escuro na barrinha do canto e a linha "Menu" para ver o trilho fechado ou aberto.

## Variantes
As três têm as mesmas 4 telas: Painel inicial, Pedidos (mapa), Financeiro → Motoboys e Celular com o menu aberto.

- **A: Papel**
  - Cabeçalho e menu na cor dos cartões do painel, com borda fina.
  - Módulo ativo com sublinhado laranja.
  - No menu, o item ativo em laranja-claro e o "Criar novo pedido" em laranja cheio.
  - É a mais discreta e a mais simples de implementar: quase só CSS sobre o `layout/header` e o `.next-sidebar`.
- **B: Faixa laranja**
  - O cabeçalho é a faixa laranja da marca nos dois temas, com o módulo ativo numa pílula escura.
  - O menu fica na cor dos cartões, com o item ativo em tinta escura e o ícone laranja.
  - Marca forte em todas as telas. Também é CSS.
- **C: Moldura em L**
  - Cabeçalho e menu formam uma moldura só, em tom de areia, e o conteúdo fica numa folha com o canto arredondado.
  - Módulos numa barra segmentada com ícone.
  - No menu, cada ícone num quadradinho, com o ativo em laranja cheio, e uma lista direta com títulos de grupo
    (Operação, Recursos, Financeiro, Configurações), sem o "entrar no grupo e voltar" de hoje.
  - Precisa mexer no `fleet-ops-sidebar.js` (lista plana) além do CSS.

Nas três, o celular troca os módulos do cabeçalho por um ☰ que abre uma gaveta com os módulos no topo e o menu do
módulo embaixo.

## O que olhar
- Numa tela que fica aberta o dia todo, o laranja cheio no cabeçalho (B) cansa?
- O trilho recolhido diz onde você está? Compare o laranja-claro (A), a tinta escura (B) e o quadradinho laranja (C).
- Grupos que abrem (A, B, como hoje) ou lista direta (C), que mostra mais ícones no trilho mas tira um clique.
- No escuro, a moldura continua separada do conteúdo?

## Para a implementação
- Cabeçalho: `ember-ui/addon/components/layout/header*`, com o CSS em `ember-ui/addon/styles/layout/next.css`.
- Menu: `.next-sidebar` e `.next-sidebar-navigator*`, mais o `fleet-ops-sidebar.js`. O trilho de Pedidos já existe
  em CSS (`console/app/styles/tela-de-pedidos.css`); passaria a valer em todo o Fleet-Ops (ou em todo o console).
- Tokens: os `--pi-*` do `painel-inicial.css`, que valeriam para o `body` inteiro.
