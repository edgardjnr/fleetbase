---
sketch: 008
name: pedido-aberto-no-console
question: "Como o pedido abre no console (Mapa, Tabela, Painel inicial e celular), com a rota no mapa como no portal e uma folha no estilo da Comanda do motoboy?"
winner: "A"
tags: [console, pedido, detalhe, comanda, cliente, produtos, mapa, mobile]
---

# Sketch 008: pedido aberto no console

## Pergunta de design
Hoje, no console:
- Na vista Mapa do `/fleet-ops`, clicar num pedido abre um cartão pequeno (`tela-pedidos/detalhe.hbs`) com loja,
  endereço e motoboy, e o botão **Abrir** leva ao painel do Fleetbase (`operations.orders.index.details`): 530 px, com
  abas e campos técnicos.
- No Quadro, o clique vai direto a esse painel do Fleetbase. A Tabela também usa o painel do Fleetbase.

Pedido do Edgard (2026-10-25):
- O clique no pedido já abre o pedido, **como no portal da loja** (mapa com P → D e o painel do pedido).
- O que abre, com os dados do cliente, fica **parecido com o pedido aberto do motoboy** (Comanda, sketch 007 B do app).
- **Vale em todas as telas:** Mapa, Tabela e Quadro do `/fleet-ops` e a fila Ao vivo do Painel inicial.
- **O painel do Fleetbase sai.** Atribuir/Trocar, Editar rota, Cancelar e a linha do tempo vão para o "Mais".

## Como ver
Sirva a raiz do repo (`python -m http.server 5179`) e abra `/.planning/sketches/008-pedido-aberto-no-console/`. Os
capacetes vêm de `console/public/images/entregas/`. As teclas 1 a 4 trocam a tela e o Esc fecha o pedido.
Para abrir já num estado: `?v=b&tela=2&ped=p3` (`v` = a, b, c ou cmp).

## Telas (as mesmas, na mesma ordem, em A, B e C)
1. **Mapa (`/fleet-ops`)**: clicar no alfinete, no capacete ou na lista Pedidos.
2. **Tabela**: clicar na linha. O Quadro se comporta igual (cartão clicável).
3. **Painel inicial**: clicar na fila Ao vivo.
4. **Celular**: os Pedidos em cartões com a doca (sketch 007 B do console).

**Pedidos (linha "Pedido"):**
- iFood a caminho, cobrar na porta;
- indo à loja, pago, com multiplicador;
- sem motoboy, em oferta (rodada e relógio de 50 s, Atribuir);
- RestaurantePro, sem 0800;
- iFood sem nome do cliente e sem produtos;
- concluído.

## Conteúdo da comanda (igual nas três)
- **Cabeçalho:** número, origem, situação, cronômetro do prazo e "para <cliente>". O nome sai em caixa normal; sem
  nome, aparece a rua.
- **Motoboy:** com Conversar e Trocar. Sem motoboy, a oferta com rodada e relógio, e Atribuir com os livres por
  distância (desempate por corridas hoje).
- **Cobrança:** cobrar na porta (valor e forma, troco) ou pago online.
- **Cliente:** rua, bairro, complemento e referência, Ligar 0800 + localizador (só iFood em andamento) e a observação
  do cliente em amarelo.
- **Produtos em cupom:** a borda serrilhada da Comanda, com opções e a observação do item. Some sem produtos.
- **Loja da coleta:** com o telefone.
- **Mais:** linha do tempo, valores (o motoboy recebe, a loja paga, a margem e o multiplicador), rota, e as ações
  Editar rota, Link de rastreio, Dados técnicos e Cancelar (com confirmação na própria folha).

## Variantes
- **A: Folha do portal.** Como no portal, o pedido abre no **mapa**: a folha de 440 px fica à direita, no lugar do
  painel Pedidos, e o mapa se desloca para mostrar a rota com o motoboy na área livre. Da Tabela e do Painel inicial,
  o clique troca para o mapa com o pedido aberto e uma pílula "Voltar para a Tabela/o Início". No celular, uma página
  com o mapa no topo e a folha embaixo.
  - É o menor esforço, porque reaproveita o mapa e o `tela-pedidos/detalhe`.
- **B: Modal Comanda.** Um modal largo **por cima da tela onde você está**, sem sair dela.
  - À esquerda: o mapa da rota, enquadrado, com o motoboy embaixo.
  - À direita: o cupom com o número enorme, "para Mariana Souza" e os produtos, ao lado dos cartões de cobrança,
    cliente e loja.
  - Abas: Comanda, Linha do tempo, e Valores e ações.
  - Rodapé fixo: Conversar com o motoboy, 0800 com o localizador, Trocar/Atribuir e "Mais".
  - No celular, uma folha que sobe com o mapa no topo.
- **C: Gaveta do app.** Uma gaveta à direita que **é a folha do motoboy**.
  - Título com o nome do cliente e a rua embaixo.
  - Botões redondos: Ligar 0800, Conversar, Trocar/Atribuir e Mais.
  - Cartões curtos: cobrança, motoboy, observação, localizador, cupom e loja.
  - Fora do Mapa, traz o mapa da rota no topo, que encolhe ao rolar ou no botão.
  - No celular, é o app: o mapa cheio e a folha que sobe pela alça.

## O que olhar
- **Ficar ou sair da tela:** a A sempre leva ao mapa, igual ao portal. A B e a C abrem por cima da Tabela ou do
  Início, e o operador não perde a lista.
- **Quanto do motoboy cabe:** a C é a mais fiel ao app. Os botões redondos funcionam com mouse? A B usa melhor a
  largura do PC, com o cupom e o cliente lado a lado.
- **Atribuir/Trocar:** na A e na C, a lista de livres abre dentro da folha. Na B, abre na coluna do mapa.
- **Ações no "Mais":** expansível no fim da folha (A e C) ou num menu no rodapé, com abas (B).
- **Concluído e cancelado:** somem 0800, Conversar e Trocar, e a linha do tempo mostra o tempo total.

## Para a implementação (qualquer variante)
- Um componente novo de pedido aberto no Fleet-Ops, com o conteúdo acima. O `ROTA_DO_PEDIDO` do
  `utils/tela-de-pedidos-base.js` e o clique da Tabela (`Layout::Resource::Tabular`) passam a abrir esse componente,
  e não mais `operations.orders.index.details`.
- **Dados que já existem:**
  - itens e observação: `ItensDoPedido`, a mesma rota que o portal usa em `pedidos/{id}/itens`, que precisa de uma
    irmã na central;
  - prazo: `meta.entregas_prazo_em`;
  - oferta e rodada, e Atribuir/Trocar: Ao vivo e `painel-inicial/pedidos/{id}/motoboy`;
  - nome do cliente: o Local de entrega (`nomeDoCliente`).
- **A conferir:**
  - cobrança e troco, o localizador do iFood e o valor do motoboy e da loja por pedido: o app já mostra os três, então
    vêm do mesmo lugar;
  - o editor de rota e o cancelar fora do painel do Fleetbase.

## Resultado
Em 2026-10-25 ficou a **A (Folha do portal)**, com um pedido a mais do Edgard: **mostrar no mapa a rota do motoboy**.
- O clique no pedido (Mapa, Tabela, Quadro e Ao vivo do Painel inicial) leva ao mapa do `/fleet-ops` com o pedido
  aberto: a folha de 440 px no lugar do painel Pedidos, o painel Motoboys recolhido num botão e o mapa deslocado para
  a rota ficar na área livre. Da Tabela e do Início, a pílula "Voltar para a Tabela/o Início" volta à tela de origem.
- **Rota do motoboy, pelas ruas** (no sistema: o traçado do OSRM do `RotaDoPedido`, o mesmo do app do motoboy, e não
  a linha reta de hoje), em trechos:
  - **a caminho do cliente:** loja → posição do motoboy em cinza ("já feito") e motoboy → cliente em laranja
    ("falta"), com "chega em N min" sobre o capacete;
  - **indo à loja:** motoboy → loja tracejado e loja → cliente em laranja;
  - **sem motoboy:** loja → cliente tracejado em cinza (rota prevista);
  - **concluído:** loja → cliente em cinza (rota feita, km e tempo total).
  - Uma legenda no canto do mapa diz o que cada linha é.
- A folha é a comanda descrita acima. O painel do Fleetbase sai, e as ações vão para o "Mais".
- No celular, uma página com o mapa (a mesma rota) no topo e a folha embaixo.

**Na implementação:**
- O `RotaDoPedido` só traça loja → cliente. O trecho motoboy → loja (indo à loja) e o corte do "já feito" na posição
  do motoboy pedem:
  - uma rota do OSRM da posição atual até a loja (ou até o cliente), com cache curto, porque a posição muda;
  - ou o ponto da linha mais perto do motoboy, para dividir o traçado em feito e falta.
- O enquadramento desconta a folha (`paddingBottomRight` do tamanho dela).
