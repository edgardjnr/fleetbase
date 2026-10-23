# Pedidos "Meus / Todos" para o líder e Mapa para todos os motoboys

Data: 2026-10-23. Pedido do Edgard. Repo do código: `entregas-navigator` (só o app; a API não muda).

## O que muda

1. **Aba Pedidos.** O líder dos motoboys (ou o administrador com cadastro de motoboy, o mesmo `useEhLider` de hoje) passa
   a ver duas abas no topo: **Meus pedidos** e **Todos os pedidos**. Quem não é líder vê a tela de hoje, sem mudança.
2. **Aba Mapa.** O botão aparece para todos os motoboys. O líder continua com o mapa de hoje (todos os pedidos, todos os
   motoboys, filtros e "Trocar motoboy"). O motoboy comum vê **só os pedidos dele em andamento e o próprio capacete**.

Decisões do Edgard (2026-10-23): o mapa comum mostra só o dele (sem outros motoboys, sem pedidos abertos); tocar num
pedido de "Todos os pedidos" abre o Mapa com o pedido selecionado; o card de "Todos os pedidos" é igual ao de Em andamento
mais o nome do motoboy. Abordagem escolhida: tudo no app, sem rota nova na API.

## Aba Pedidos (`DriverOrderManagementScreen`)

- **Não líder:** nada muda (Novos pedidos + Em andamento).
- **Líder:** um seletor de duas abas no topo da tela (estilo dos chips do mapa, sketch 005 A): **Meus pedidos** (a tela de
  hoje, inteira) e **Todos os pedidos (n)**. Abre sempre em Meus pedidos. A escolha fica só em memória da tela.
- **Todos os pedidos:**
  - Fonte: `GET v1/entregas/lider/mapa` (`pedidos`, já no formato `PedidoDoLider` pelo `lerMapa`). Os mesmos pedidos do
    mapa do líder: não encerrados, atualizados nas últimas 12 h, de todas as lojas da empresa, com coordenada no destino.
  - Ordem: o mais antigo em cima (`criado_em`), estável, sem data no fim (a mesma regra do `doMaisAntigo`).
  - Card (`CardDeTodosOsPedidos`, novo, visual do `OrderCard`): número do pedido (`rotuloDoPedido`), loja, situação em
    palavras (a mesma do `OrderCard` pelo `status`), o cronômetro do prazo (`CronometroDoPrazo` com o `prazo_em`) e o nome
    do motoboy, ou **"Sem motoboy"** em destaque quando `semMotoboy(pedido)`.
  - Leitura: ao abrir a aba, a cada 30 s com ela aberta e o app na frente, e no puxar para atualizar. Uma leitura por vez.
  - Toque: vai para a aba Mapa com o pedido selecionado (`navigate('DriverMapaTab', { screen: 'MapaDoLider', params:
    { pedido: id } })`) e o cartão aberto, com "Trocar motoboy".
  - Vazio: "Nenhum pedido em andamento". Erro de rede: mantém a última lista e mostra o aviso de falha no topo da lista.
  - 403 (perdeu o papel): `conferirAcessoDoLider`; com `useEhLider` falso, o seletor some e a tela volta para Meus pedidos.

## Aba Mapa (`MapaDoLiderScreen`)

- **Botão da aba:** aparece para todos. As `options` da `DriverMapaTab` continuam sem `if` (a regra dos hooks do
  navegador); só deixam de esconder o botão (`tabBarButton`/`tabBarItemStyle` saem).
- **Modo pelo `useEhLider`, ao vivo:** o acesso começa falso e é lido ao entrar; o líder vê o modo comum por um instante
  e passa ao modo líder quando a leitura chega. Trocar de modo limpa a seleção, a folha e o enquadramento (enquadra de
  novo com os pontos do modo novo).
- **Sai** o efeito que mandava quem não é líder de volta para o Início.
- **Modo líder:** como hoje, mais o parâmetro `pedido` da rota: quando o mapa lido tem esse pedido, seleciona, abre o
  cartão e centraliza nele; depois limpa o parâmetro (`setParams({ pedido: undefined })`) para não reabrir ao voltar.
- **Modo comum (motoboy não líder):**
  - Dados sem rede nova: os pedidos dele em andamento do `OrderManagerContext` (`allActiveOrders`, os mesmos da aba
    Pedidos) e a posição do celular (`LocationContext`). A função pura `mapaDoMotoboy(pedidos, posicao, online)` monta um
    `MapaDoLider`:
    - cada pedido não encerrado com coordenada válida no destino (`payload.dropoff`) vira um `PedidoDoLider` (número pelo
      `numeroDoPedido`, loja pela `lojaDaColeta`, endereço do destino, status, `aceito` pelo `started`, `prazo_em` pelo
      `meta.entregas_prazo_em`, coleta pelo `payload.pickup`, `motoboy` = nome dele);
    - `motoboys` = só ele, na posição do celular, com a situação pela regra do `SituacaoDoMotoboy` do servidor
      (`enroute`, `picked_up`, `dropping_off`, `in_progress` = entrega; outro pedido em andamento = coleta; sem pedido =
      livre ou offline pelo `online`). Sem posição: sem capacete.
  - Redesenha quando muda a lista de pedidos dele ou a posição; não lê `lider/mapa` (403 para ele).
  - Topo laranja com o título **Mapa**, sem o selo Líder; sem os chips de filtro; legenda só com as cores do prazo.
  - Alfinete vermelho no destino com o rótulo na cor do prazo (o mesmo do líder).
  - Tocar no alfinete abre a folha simples: número, loja, endereço, situação e prazo, e o botão **Ver pedido** (abre a tela
    `Order` de sempre com o pedido do `allActiveOrders`). Sem "Trocar motoboy".
  - Sem pedido: centraliza na posição dele (ou no ponto padrão) e mostra o aviso "Nenhum pedido em andamento".
- **Riscos:** nenhum dado novo sai do servidor; o motoboy comum só vê o que já recebe hoje na aba Pedidos.

## Código (repo `entregas-navigator`)

- `src/utils/mapa-do-motoboy.ts` (novo, sem imports, para o Node puro): `mapaDoMotoboy` e `situacaoDoMotoboy`. Testes em
  `scripts/testes/mapa-do-motoboy.teste.ts`.
- `src/utils/mapa-do-lider.ts`: `todosOsPedidos(mapa)` (a lista ordenada da aba). Testes em `mapa-do-lider.teste.ts`.
- `src/components/CardDeTodosOsPedidos.tsx` (novo) e o seletor de abas na `DriverOrderManagementScreen`.
- `MapaDoLiderScreen`: os dois modos e o parâmetro `pedido`. Se a tela passar muito do tamanho de hoje, o modo comum vai
  para um componente próprio (`FolhaDoMeuPedido`).
- `DriverNavigator`: o botão da aba Mapa para todos.
- Textos novos em pt-BR nas traduções do app.
- Documentação: o `CLAUDE.md` do app e `api/app/Http/Controllers/Entregas/CLAUDE.md` (seções "Aba Pedidos" e "Aba Mapa do
  líder", que vira "Aba Mapa").

## Testes

- `node --experimental-strip-types --test scripts/testes/mapa-do-motoboy.teste.ts scripts/testes/mapa-do-lider.teste.ts`
  e os demais `scripts/testes/*.teste.ts`.
- `tsc` do app sem erro novo.
- No celular (APK pelo GitHub Actions; adb no S24): motoboy comum vê a aba Mapa só com os pedidos dele; líder vê as duas
  abas em Pedidos, a lista de todos e o toque abrindo o cartão no Mapa. Terraço é loja real: sem pedido de teste nela sem
  combinar.

## Deploy

Só o APK. Cada build vira a versão mínima (trava "Atualize o app"), então todos atualizam.

## Limites que ficam

- Pedido sem coordenada no destino não aparece em "Todos os pedidos" (o `lider/mapa` já o descarta).
- No modo comum, a posição do capacete é a do celular, não a última gravada no servidor.
