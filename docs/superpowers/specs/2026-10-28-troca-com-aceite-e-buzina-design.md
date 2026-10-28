# Troca de motoboy com aceite e buzina na loja

Decisão do Edgard de 2026-10-27/28. Dois pedidos:

1. Quando um pedido passa de um motoboy para outro (ou a central atribui um motoboy), o escolhido vê a mesma tela de
   aceitar do pedido novo. Se recusar (ou deixar vencer), o pedido volta para a fila até achar um motoboy.
2. Quando um motoboy aceita uma corrida, toca uma buzina de moto no portal da loja. O áudio é do Edgard.

## Decisões

| Pergunta | Decisão |
|---|---|
| Troca depois que o anterior já coletou (status `enroute` em diante) | **Troca direta, como hoje** (sem aceite) |
| Atribuir do console (pedido sem motoboy) | **Também pede aceite** (Atribuir e Trocar usam a mesma rota) |
| Motoboy anterior na fila depois da recusa | **Entra como qualquer um** |
| Quando a buzina toca | **Em todo aceite**, inclusive o da troca |
| Onde a buzina toca | Em **qualquer tela** do portal da loja (rota nova + serviço global) |

## Abordagem

A troca reaproveita a distribuição em rodadas (`api/app/Support/Entregas/Distribuicao/`): o pedido volta a ser aberto
sem motoboy e nasce uma distribuição nova cuja **primeira oferta é dirigida** ao motoboy escolhido. O cartão "Oferta
para você", o alarme nativo (aceita e recusa direto na API, com a tela bloqueada), o Aceitar (`POST v1/orders/{id}/start`
com `assign`) e o Recusar (`POST v1/entregas/motoboy/pedidos/{id}/recusar`) já fazem isso no APK 62. **Objetivo: sem APK
novo.** Um texto do app do líder pode mudar no próximo APK, se a tela dele não mostrar bem o pedido "aguardando aceite".

Descartada: uma "oferta de troca" separada, com o pedido preso ao anterior até alguém aceitar. Pediria tabela nova,
rota de aceite nova e cartão novo no Kotlin e no React (APK obrigatório) para o mesmo resultado.

## 1. Troca e Atribuir com aceite (API)

Ponto de entrada único: `TrocaDoMotoboy::trocar` (líder pelo `LiderController@trocarMotoboy`; console pelo
`PainelInicialController`, Atribuir e Trocar). As validações de hoje ficam (404, 409 encerrado, 409 não despachado, 422
motoboy, 409 trava ocupada, mesmo motoboy = sem mudança).

### Quando vira oferta

Todas as condições:

- a distribuição está ligada (`Distribuicao::ligada()`);
- o pedido **não foi coletado**: status fora de `SituacaoDoMotoboy::STATUS_EM_ENTREGA` (`enroute`, `picked_up`,
  `dropping_off`, `in_progress`) e não encerrado;
- o motoboy escolhido é diferente do atual.

Caso contrário, a troca direta de hoje (`assignDriver($novo, true)`), sem mudança.

### O que a troca com aceite faz

Dentro da `TravaDoPedido` (relê o pedido, como hoje):

1. guarda o motoboy anterior (pode ser nenhum, no Atribuir);
2. devolve o pedido ao estado "aberto, despachado, não iniciado": `driver_assigned_uuid` nulo, `adhoc` verdadeiro,
   `started` falso, `started_at` nulo e status `dispatched`, sem disparar o `OrderDriverAssigned` ao anterior nem ao
   novo (quem avisa o novo é o push da oferta);
3. limpa o `current_job_uuid` do anterior, se apontava para este pedido (como hoje);
4. encerra a distribuição em curso do pedido, se houver, como `redespachada` (a oferta pendente de outro motoboy vira
   `cancelada`; o Aceitar atrasado dele leva o 409 de sempre).

**Fora da trava** (ela não é reentrante; a `TrocaDoMotoboy` não pode chamar o `Distribuidor` lá dentro):

5. inicia a distribuição nova com a **oferta dirigida** ao escolhido: volta 1, rodada 1, lista fechada, prazo da oferta de
   sempre (50 s em rodadas, 30 s sem), push `OfertaDePedido`. A oferta vai a ele **mesmo fora do raio, ocupado ou com
   GPS velho** (a central ou o líder escolheu). O job de vencimento e a varredura são os de sempre;
6. o anterior recebe o `PedidoPassadoParaOutro` (como hoje), depois do passo 5;
7. log `[entregas] troca do motoboy: oferta dirigida` (pedido, anterior, escolhido, quem trocou; só ids).

Se o passo 5 falhar, cai na reserva da distribuição (alarme geral, motivo `falha`), como o listener do despacho.

### Depois da oferta dirigida

- **Aceitou:** o aceite normal (`BarrarAceiteDePedidoEncerrado` → `startOrder` com `assign` → `registrarAceite` =
  `aceita`). O `startOrder` grava `started`, `started_at`, a atividade de início, o `current_job_uuid` e o
  `OrderDriverAssigned` (iFood recebe o `assignDriver` do novo motoboy).
- **Recusou ou venceu:** `avancarEmRodadas` normal. O escolhido já tem linha na volta 1 e fica fora dela; volta nova
  (1 min depois) o inclui de novo. O **anterior é candidato como qualquer um** (sem regra nova).
- **Limite de 1 h:** conta da distribuição nova (`despachada_em` = hora da troca).
- Sem rodadas (`ENTREGAS_DISTRIBUICAO_RODADAS` vazia), o mesmo mecanismo no ciclo antigo: oferta dirigida, depois a fila,
  e abre a todos pelo prazo de 3 min ou fila esgotada. A produção usa rodadas.

### Resposta

200 com o pedido, agora sem motoboy, e um campo novo `oferta: {motoboy: <public_id>, nome, vence_em}` para o console e o
líder mostrarem "Aguardando o aceite de Fulano". A troca direta (já coletado, distribuição desligada) responde como hoje,
com `oferta: null`.

### Console

O Atribuir e o Trocar (painel inicial, folha do pedido) passam a mostrar "Oferta enviada a Fulano. Se ele recusar, o
pedido volta para a fila." e o pedido fica no estado de oferta da folha (já existe pelo painel de distribuição). Textos
em `fleet-ops.ui.*` pt-BR.

### A conferir no plano

- **iFood:** o `ObservadorDosPedidosIfood` e a reconciliação do `entregas:ifood-acompanhar` com o status voltando de
  `started` para `dispatched` (o `goingToOrigin` já foi enviado; o iFood continua com o anterior até o novo aceitar; no
  aceite sai o `assignDriver` do novo e o `started` de novo não pode reenviar fora de ordem).
- **Valor congelado** (`ValoresCongelados`, `CalculoEntregas`): o pedido perde o motoboy e ganha outro; a parte da
  atribuição (motoboy, posição, multiplicador, "entrega junto") tem de valer para o novo.
- **`corridasHoje`:** pedido trocado conta para quem ficou com ele (`driver_assigned_uuid`), não para o anterior.
- **Lista do app** (`FiltrarPedidosAbertosDoMotoboy`): a oferta dirigida aparece só ao escolhido, mesmo fora de 2R
  (já há a regra "pedido em que ele tem a oferta pendente").
- **Aceite pelo alarme do anterior:** o anterior que ainda vê o pedido leva 409 enquanto a oferta é do escolhido.
- **App do líder:** como a aba Mapa mostra a resposta (pedido sem motoboy) e o texto de sucesso.
- O status `dispatched` e a atividade: o Fleet-Ops aceita gravar o status de volta sem atividade nova, e a linha do tempo
  do pedido mostra o segundo "iniciado".

## 2. Buzina na loja (portal)

### API

`GET int/v1/entregas/loja/aceites` (lista `PERMITIDAS_INTERNAS` do `ProtegerPortalLoja`; só usuário de loja, pedidos da
loja da sessão, como as outras `loja/*`):

- pedidos da loja com `started_at` nas últimas **2 h** e motoboy atribuído, não apagados;
- cada linha: `pedido` (public_id), `numero` (iFood, rastreio ou public_id, a regra do `TrocaDoMotoboy::numero`),
  `aceito_em` (`started_at` em ISO com `-03:00`) e `motoboy` (só o primeiro nome, sem id nem telefone);
- limite de 50 linhas, mais recentes primeiro; limitador próprio.

### Portal

- Serviço `entregas-buzina` iniciado no layout do portal (vale em qualquer tela): lê `loja/aceites` a cada **10 s**,
  para com a aba oculta e lê na volta.
- Chave do aceite: `pedido + aceito_em`. A troca aceita grava outro `started_at` e toca de novo; a troca direta depois
  da coleta não muda o `started_at` e não toca.
- **A primeira leitura não toca** (só guarda as chaves). Depois, cada chave nova toca a buzina **uma vez** (vários aceites
  na mesma leitura: um toque só).
- Áudio: arquivo do Edgard nos assets do engine do portal (caminho definido no plano), tocado com `HTMLAudioElement`. O
  navegador só libera o som depois do primeiro clique ou tecla na página: o serviço "destrava" o áudio no primeiro gesto,
  como o `som-de-mensagem.js`. Sem som liberado ou com erro no `play()`, não faz nada.
- Erro de rede: tenta na próxima leitura, sem aviso.

## 3. Testes

- php-wasm (`scripts/teste-php/`):
  - troca com aceite: pedido `started` com motoboy → aberto, sem motoboy, `started` falso, oferta dirigida ao escolhido
    (inclusive fora do raio), anterior avisado;
  - recusa e vencimento da oferta dirigida → próxima oferta pelas rodadas; anterior pode ser candidato;
  - já coletado (`enroute`) → troca direta; distribuição desligada → troca direta;
  - Atribuir em pedido em oferta a outro → distribuição antiga `redespachada`, oferta antiga `cancelada`;
  - `loja/aceites`: só pedidos da loja, 2 h, primeiro nome;
  - `imports-dos-providers.php` para a rota nova.
- `scripts/teste-portal/`: detecção de aceite novo (primeira leitura não toca, chave nova toca uma vez, troca aceita
  toca).
- `node scripts/i18n-check.cjs ...` com exit 0.

## Implantação

1. `bash deploy/atualizar.sh api` (sem migration prevista).
2. `bash deploy/atualizar.sh console` (portal e console).
3. Conferir com pedido real: Trocar pelo console → cartão no celular do escolhido → recusar → próxima oferta; aceitar →
   buzina no portal da loja. **O Terraço é loja real**: combinar antes de testar nele.

## Fora do escopo

- Oferta de troca depois da coleta (decisão: troca direta).
- Aviso visual no portal junto da buzina.
- Buzina no console da central.
