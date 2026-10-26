---
sketch: 009
name: portal-da-loja-unificado
question: "Como o portal da loja (/customer-portal) fica no mesmo padrão do console (/fleet-ops), em todas as telas, no desktop e no celular?"
winner: "S"
tags: [portal-da-loja, layout, menu, pedidos, mapa, financeiro, conversas, mobile, tema]
---

# Sketch 009: portal da loja unificado

## Pergunta de design
Pedido do Edgard (2026-10-25): refazer todas as telas do portal da loja para seguir o padrão do `/fleet-ops`:
- moldura areia (006 C);
- mapa com painéis (005 A invertida);
- pedido aberto em folha (008 A);
- doca no celular (007 B).

Decisões dele na conversa:
- O sketch cobre todas as telas.
- O menu varia entre as variantes, e a A fica igual ao console.
- O Início vira um painel da loja, com os números e o ao vivo.

## Como ver
Sirva a raiz do repo (`python -m http.server 5179`) e abra `/.planning/sketches/009-portal-da-loja-unificado/`.
- Os capacetes e o pin da loja vêm de `console/public/images/entregas/`.
- **Comparar por tela** mostra a mesma tela nas três variantes.
- Para abrir já num estado, use `?v=b&tela=3&ped=p3` (`v` = a, b, c ou cmp).
- Esc fecha o pedido, a gaveta e os menus. "Reiniciar dados" (canto de baixo) volta ao início.

## Telas (as mesmas, na mesma ordem, em A, B e C)
1. **Início:** números de hoje (cada um leva à tela), pedidos ao vivo e mapa com os motoboys.
2. **Pedidos (mapa):**
   - alfinete com "#número · motoboy" na cor do prazo;
   - todos os motoboys online;
   - as contagens filtram.
3. **Pedido aberto:**
   - rota com o trecho feito e o que falta;
   - motoboy com Conversar;
   - cobrança, entrega, produtos e o "Mais" (linha do tempo, valor e rastreio);
   - sem motoboy (#4833): rodada, relógio e Cancelar, que só existe antes do aceite.
4. **Pedidos (tabela):** abas Em andamento, Concluídos e Cancelados. O clique abre o pedido no mapa ("Voltar para a Tabela").
5. **Novo pedido:**
   - só o destino;
   - o endereço sai de um salvo, das sugestões ao digitar ou de um clique no mapa;
   - coleta fixa na loja;
   - estimativa de km e valor da faixa.
6. **Conversas:** uma por motoboy, com o pedido. Dá para digitar e enviar.
7. **Extrato:** período, três números, selos (2ª entrega, chuva, cancelado cobrado), CSV e "Mostrar mais".
8. **Financeiro:** o ciclo em andamento e as faturas.
9. **Fatura:** cabeçalho, itens, totais, CSV e Imprimir.
10. **Configurações:** Minha conta, Loja (coleta e iFood) e Membros (só leitura).
11. **Celular, Pedidos:** mapa com a folha de baixo (a alça muda a altura) e o pedido aberto dentro dela.
12. **Celular, Início e menu:** painel empilhado e o menu de cada variante.

## Variantes
- **A: Igual ao console**
  - Cabeçalho areia com a loja, a busca de pedido, as Conversas e o avatar.
  - Trilho de ícones que abre por cima. O Novo pedido fica em tinta.
  - O conteúdo fica numa folha de canto arredondado.
  - Pedidos é o `/fleet-ops` da loja: Motoboys à esquerda, Pedidos à direita, e o pedido aberto e o novo pedido numa folha de 440 px.
  - Conversas é uma tela de duas colunas.
  - No celular, a doca flutuante: Início, Pedidos, +, Conversas e Mais.
- **B: Moldura com gaveta**
  - A moldura areia sem trilho: o ☰ abre a gaveta "Marca da loja" (003 B, nas cores novas).
  - O cabeçalho traz o título da tela, Conversas e Novo pedido.
  - Em Pedidos, a lista fica fixa à esquerda, como hoje, e o pedido aberto flutua à direita do mapa.
  - Conversas abrem numa gaveta à direita, como hoje.
  - No celular, o ☰ e as abas na areia: Início, Pedidos, Novo, Conversas e Extrato.
- **C: Abas no topo**
  - Sem menu lateral: Início, Pedidos, Extrato e Financeiro ficam na barra segmentada do cabeçalho.
  - O cabeçalho traz o Novo pedido laranja e as Conversas num balão. Configurações e o tema ficam no avatar.
  - Em Pedidos, o mapa vai de ponta a ponta, e a lista flutua à esquerda e vira o pedido ("← Pedidos").
  - No celular, abas no topo e a barra Novo pedido embaixo.

- **S: Síntese ★ (vencedora).** É a escolha do Edgard: a tela 1 vem da B e as telas 2 a 12 vêm da C, "com os modais
  sempre à direita da tela". Detalhes em "Resultado".

## O que olhar
- **Unificar ou simplificar:**
  - A é o console tal qual: quem usa os dois não estranha, mas são 7 ícones para uma loja.
  - C é a mais enxuta: são 4 seções.
  - B mantém o jeito de hoje do portal.
- **Pedidos:**
  - painel à direita (A, igual ao console);
  - lista fixa à esquerda (B, como hoje);
  - lista flutuante que vira o pedido (C).
- **Conversas:** tela própria (A), gaveta (B) ou balão (C).
- **Celular:** a doca da A é igual à do console, a B tem abas fixas com rótulo e a C deixa o Novo pedido sempre à mão.

## Para a implementação (qualquer variante)
- O CSS do portal hoje é `customer-portal-engine.css`, com classes `portal-*`. O padrão do console está em
  `console/app/styles/{moldura,tela-de-pedidos,painel-inicial,celular}.css`, com os tokens `--pi-*`. O portal passaria a
  usar os mesmos tokens. Como o CSS do console vale nas telas dos engines, o portal pode reaproveitar as classes `tp-*`
  e `pi-*`.
- **Dados que já existem:**
  - `loja/minha-loja`, `loja/resumo-do-dia` (ganharia "pedidos hoje", "no prazo" e "tempo médio"), `loja/motoboys`
    (alfinetes e capacetes), `loja/pedidos/{id}/itens`, `loja/pedidos/{id}/motoboy`, `loja/conversas`, `loja/extrato`,
    `loja/financeiro` e `loja/faturas/{uuid}`;
  - "A pagar no ciclo" sai do `ciclo_atual` do Financeiro.
- **A conferir:**
  - rota feito/falta no portal: hoje é P → D. A comanda do console usa o `RotaDoPedido`, então seria preciso uma rota
    da loja;
  - cobrança na porta para a loja: confira se a loja deve ver esse dado (hoje o portal não mostra);
  - a busca de pedido no cabeçalho (A).

## Resultado
Em 2026-10-26 ficou a **S**, montada a partir do pedido do Edgard: "1 B, 2 ao 12 C, com os modais sempre à direita da
tela".
- **Moldura da C em todo o portal:**
  - cabeçalho areia com a loja;
  - abas Início, Pedidos (com a contagem), Extrato e Financeiro;
  - o botão laranja Novo pedido, as Conversas com o número de não lidas e o avatar (Configurações, tema e sair);
  - sem menu lateral e sem gaveta ☰.
- **Início (tela 1) da B:**
  - faixa laranja com "Boa noite, <loja>!", o resumo e o Novo pedido;
  - os 6 números de hoje, cada um levando à sua tela;
  - Ao vivo à esquerda e o mapa à direita.
- **Pedidos (telas 2 a 5):** o mapa vai de ponta a ponta, com a lista de pedidos flutuando **à direita** (380 px). As
  contagens que filtram e o "N motoboys online" ficam no canto de cima à esquerda.
- **Ordem da lista** (pedido do Edgard em 2026-10-26, "pra facilitar ver os atrasados"): **quem vence o prazo de entrega
  primeiro fica em cima**. Os atrasados vão para o topo, o mais atrasado antes. Pedido sem prazo vai para o fim, o mais
  antigo antes. Os encerrados de hoje vêm embaixo, separados. A mesma ordem vale na lista do celular.
  - Na implementação, ordenar pelo `meta.entregas_prazo_em` (o mesmo do cronômetro do card), não pela criação.
- **Tudo o que abre fica à direita, numa folha de 440 px, no lugar da lista** (pedido do Edgard em 2026-10-26: "quando
  abrir algo que sobrepõe ele, ele some, e depois volta"):
  - o pedido aberto (comanda do 008 A);
  - o Novo pedido;
  - as Conversas.
  - Com a folha aberta, a lista e as contagens somem e o mapa se encaixa na área livre à esquerda. Ao fechar (✕ ou Esc),
    a lista volta.
- **Tabela, Extrato, Financeiro, Fatura e Configurações:** páginas da C. O clique na linha da Tabela abre o pedido no
  mapa com a pílula "Voltar para a Tabela".
- **Celular (telas 11 e 12):** a C, com as abas no topo, a barra Novo pedido embaixo, o mapa com a folha de baixo e o
  menu no avatar.
