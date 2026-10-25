---
sketch: 007
name: console-no-celular
question: "Como fica o console inteiro no celular, na marca Entregas, com um rodapé que substitui a barra do Fleetbase?"
winner: "B"
tags: [console, mobile, rodapé, navegação, tema, fleet-ops, financeiro]
---

# Sketch 007: console no celular

## Pergunta de design
Em produção (conferido em 2026-10-25 com 390 px), o console no celular ainda é o do Fleetbase:
- **Rodapé:** a barra branca do `Layout::MobileNavbar`, com 12 itens que rolam para o lado e misturam módulos e
  subpáginas (Fleet-Ops, Desenvolvedores, IAM, Chaves de API, Logs, Orders, Drivers, Finances…). Três aparecem em
  inglês e alguns rótulos saem cortados ("Chaves de A"). Nada marca a tela atual, e o "Menu" só abre o menu lateral,
  por isso não faz nada no Início.
- **Menu lateral:** abre sozinho por cima do conteúdo, ocupando meia tela, em Pedidos, IAM e nos outros módulos.
- **Cabeçalho:** sete ícones espremidos (logo, menu, idioma, caixa de entrada, chat, organização e avatar).
- **Tabela de pedidos:** fica larga, rola para o lado e começa pelo ID técnico (`order_xxx`).

Decisões do Edgard:
- O celular serve para **tudo, igual ao PC**.
- O rodapé fixo tem **Início · Pedidos · Mapa · Motoboys · Mais**.
- O sketch cobre Início e Pedidos, Motoboys e Lojas, Financeiro, além do "Mais" e do cabeçalho.

## Como ver
Sirva a raiz do repo (`python -m http.server 5179`) e abra `/.planning/sketches/007-console-no-celular/`.
- **Todas as telas:** mostra as 8 telas da variante. Dois cliques num celular abrem a tela em tamanho real.
- **Comparar por tela:** mostra a mesma tela nas três variantes.
- O tema claro ou escuro fica na barrinha do canto, e também no "Mais" de cada variante.

## Telas (iguais nas três variantes)
1. **Início:** números do período empilhados, faixa Atenção, Ao vivo e motoboys online.
2. **Pedidos:** busca, abas (Em andamento, Sem motoboy, Concluídos, Cancelados) e cartões no lugar da tabela, cada um
   com número, origem, loja → bairro, prazo com barra e o motoboy ou o botão Atribuir.
3. **Detalhe e Atribuir:** prazo, rota loja → cliente, "Quem leva?" (o mais perto e o com menos corridas marcados),
   ações Cliente, Loja e Cancelar, itens e linha do tempo.
4. **Mapa:** capacetes, lojas e destinos, com as contagens que filtram e a folha de baixo (toque na alça).
5. **Motoboys:** filtros Online, Livres, Em corrida e Offline, e cartões com situação, corridas e km.
6. **Lojas:** cartões com o iFood, a situação da fatura e os pedidos de hoje.
7. **Financeiro:** a pagar aos motoboys e a receber das lojas, extrato dos motoboys ou das lojas, e "Pagar semana" ou
   "Gerar faturas".
8. **Mais:** os grupos do trilho do Fleet-Ops (Operação, Recursos, Financeiro, Configurações), os outros módulos (IAM,
   Desenvolvedores, Chaves de API), a organização e a conta (tema, avisos, sair).

## Variantes
- **A: Abas na areia**
  - A moldura do desktop (006 C) vai para o celular: cabeçalho e rodapé em areia, e o conteúdo numa folha com os
    cantos de cima arredondados.
  - Na aba ativa, o ícone fica no quadradinho laranja.
  - O título da tela fica no cabeçalho, com o "+" laranja ao lado.
  - O "Mais" é uma página com a lista direta.
  - É a mais simples de implementar.
- **B: Doca flutuante ★ (vencedora)**
  - O conteúdo vai de ponta a ponta, com título grande que encolhe ao rolar.
  - O rodapé é uma pílula escura flutuante; a aba ativa vira uma pílula laranja com o nome.
  - O "+" é um botão flutuante.
  - O "Mais" sobe numa folha de baixo, com atalhos em grade.
- **C: Ao vivo no rodapé**
  - Em cima das abas fica a faixa da operação ao vivo: sem motoboy, atrasados, em rota e livres. Cada número leva
    à lista filtrada, e o "+" cria pedido.
  - Na aba ativa, um traço laranja em cima, e a aba Pedidos mostra quantos estão sem motoboy.
  - O cabeçalho mostra a organização.
  - O "Mais" é uma gaveta pela esquerda com o topo laranja e o resumo do dia.

## O que olhar
- O rodapé diz onde você está? Compare o quadradinho laranja (A), a pílula com nome (B) e o traço (C).
- Espaço para o conteúdo: em A o rodapé e o cabeçalho são cheios; em B a doca flutua por cima; em C a faixa ao vivo
  ocupa mais uma linha, mas mostra a operação em qualquer tela.
- O "Mais", com tudo o que não cabe no rodapé: lista (A), grade (B) ou gaveta (C)?
- O "+ Novo pedido" no cabeçalho (A), flutuante (B) ou na faixa (C).
- No escuro, a doca da B inverte (fica clara), e a folha da A continua separada da moldura.

## Para a implementação
- **Rodapé:** trocar o `packages/ember-ui/addon/components/layout/mobile-navbar.{hbs,js}`, hoje gerado dos
  `universe.headerMenuItems`, por 5 destinos fixos:
  - `console.home`;
  - `console.fleet-ops.operations.orders.index` (lista e mapa por `layout`);
  - motoristas;
  - o "Mais".
- **Menu lateral no celular:** começar fechado. Quem mostra o menu do módulo é o "Mais", que pode reaproveitar o
  navigator com `@plano` (as seções do trilho).
- **Cabeçalho no celular:** esconder idioma, caixa de entrada e chat (ficam no "Mais"), deixando logo/título, busca e
  avisos.
- **Pedidos em cartões:** no celular, a Tabela vira lista de cartões (o mesmo `linha-do-pedido` da tela de pedidos) e
  o Mapa já tem a folha de baixo (sketch 005).
- **Tokens:** os `--pi-*` do `painel-inicial.css`, os mesmos da moldura (`console/app/styles/moldura.css`).
