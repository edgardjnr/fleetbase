# Distribuição em rodadas: oferta um a um até alguém aceitar

Data: 2026-10-07. Decisões do Edgard na conversa de desenho, depois do primeiro teste real da distribuição
(`2026-10-07-distribuicao-de-pedidos-design.md`, em produção desde 05:25 do mesmo dia).

## 1. Problema

A distribuição de hoje oferece o pedido a um motoboy por vez, mas só numa passada. Quando a fila acaba ou passam
3 min do despacho, o pedido **abre a todos**: todos os celulares do raio tocam juntos, o primeiro a aceitar leva, e
depois vêm os reenvios do Fleet-Ops (4 em 4 min, 3 vezes) e o silêncio. No teste, o motoboy recusou, o pedido voltou
para ele na hora (era o único da fila) e a segunda recusa não ficou registrada em lugar nenhum.

O que o Edgard quer: **o pedido tem que ser entregue**. O servidor continua passando motoboy por motoboy, com o raio
crescendo, e recomeça até alguém aceitar. Sem alarme para todos de uma vez.

## 2. Decisões

| Pergunta | Decisão |
|---|---|
| Critério da fila | **Quem entrega o cliente novo mais cedo.** O motoboy ocupado termina tudo o que já leva e só depois vai à loja nova (o pedido novo vai para o fim da fila dele). Empate: o livre primeiro, depois o `public_id`. |
| Encaixe no meio do caminho | **Sai**, com o limite de 10 min de atraso. O pedido novo nunca atrasa quem já espera. |
| Tempo de cada oferta | **20 s** (era 30 s). |
| Rodadas | 1 = até **R**; 2 = até **1,5R**; 3 = até **2R** (R = raio de pedido aberto da empresa, hoje 6 km → 6, 9 e 12 km). |
| Quem entra em cada rodada | Os disponíveis até o raio da rodada **que ainda não receberam oferta nem dispensaram nesta volta**. |
| Fim da rodada 3 | **Volta nova** na rodada 1, com todos de novo (inclusive quem recusou). Repete até alguém aceitar, por até 1 h. |
| Limite de 1 h | Passada **1 h do despacho** (`Distribuicao::MINUTOS_ATE_PARAR_DE_TOCAR`), ninguém mais recebe oferta: a distribuição segue em `ofertas`, só na lista aberta (abre nesse momento, se ainda estava fechada), e o aceite pela lista vale. A oferta que já corria termina normalmente. Log `limite de 1 h; só na lista` uma vez. Decisão do Edgard (2026-10-07): o pedido de teste esquecido não toca o dia inteiro, e a `posicao` (`unsignedSmallInteger`) não estoura. |
| Intervalo entre voltas | A volta nova só começa **1 min depois do início da anterior**. |
| Recálculo | A cada oferta (quem ficou livre, online ou terminou uma entrega entra na hora). |
| Lista "Novos pedidos" | Volta 1, rodada 1: só quem está com a oferta. **A partir da rodada 2 da volta 1: todos os disponíveis até 2R**, menos quem recusou ou dispensou nesta volta. Quem aceitar primeiro leva. |
| Alarme | Sempre **um por vez** (só quem está com a oferta). Os outros veem na lista, sem tocar. |
| Recusar e Dispensar | Fazem a mesma coisa em todo lugar (cartão da tela bloqueada, tela do pedido, card da lista): avisam o servidor, o pedido some da lista dele até a volta seguinte e não toca para ele nesta volta. |
| Sem ninguém em nenhum raio | Tenta de novo a cada 1 min (até o limite de 1 h). |
| Central | O aviso "sem motoboy" aos 12 min continua (uma vez). O ciclo segue. |
| Sai | O prazo de 3 min, o alarme para todos (`OrderPing` a todos do raio) e os reenvios do Fleet-Ops nesses pedidos. |
| Botão do console | "Abrir a todos agora" vira **"Mostrar a todos agora"**: abre a lista na hora, sem alarme geral; o ciclo continua. |
| Implantação | Atrás de uma chave nova, `ENTREGAS_DISTRIBUICAO_RODADAS=1`, ligada só com o APK 30 em todos os celulares. |

Referência de mercado (pesquisa de 2026-10-07): oferta exclusiva ao melhor pelo tempo, recusa passa ao próximo na hora
(Uber, DoorDash com 30 s); raio crescente quando ninguém aceita (Uber, iFood começa em 5 km); lista aberta com os
pedidos que ninguém pegou, leva quem aceitar primeiro (Trip Radar do Uber, Rotas Disponíveis do iFood).

## 3. O ciclo

Exemplo com R = 6 km e quatro motoboys livres que não aceitam: Ana (2 km), Bruno (4 km), Caio (7 km), Davi (11 km).

| Tempo | Volta · rodada (raio) | Oferta (toca) | Lista "Novos pedidos" |
|---|---|---|---|
| 0:00 | 1 · 1 (6 km) | Ana | só Ana |
| 0:20 | 1 · 1 | Bruno | só Bruno |
| 0:40 | 1 · 2 (9 km) | Caio | todos até 12 km, menos quem recusou nesta volta |
| 1:00 | 1 · 3 (12 km) | Davi | idem |
| 1:20 | volta 2 · 1 (já passou 1 min do início da volta 1) | Ana | todos de novo |
| … | 2 · 1 → 2 · 2 → 2 · 3 → 3 · 1… | um por vez | todos (menos quem recusou na volta atual) |

### Um passo do ciclo (`Distribuidor::avancarSemTrava`, sob a `TravaDoPedido`)

1. Pedido com motoboy, encerrado ou apagado: encerra a distribuição, como hoje.
2. Oferta pendente: espera.
3. Calcula os candidatos da rodada atual: disponíveis até o raio da rodada, com GPS de menos de 30 min, **fora** quem
   tem oferta (de qualquer resposta) ou dispensa **nesta volta** e quem tem oferta pendente de outro pedido.
4. Tem candidato: oferece ao primeiro da fila (push `OfertaDePedido`, TTL 20 s, job `AvancarOferta` em 20 s).
5. Vazia: passa à rodada seguinte. Ao sair da rodada 1 da volta 1, grava `lista_aberta_em`. Depois da rodada 3:
   - se já passou 1 min do início da volta atual, começa a volta seguinte (rodada 1) e volta ao item 3;
   - senão, agenda o próximo passo para quando completar 1 min (job atrasado; a varredura é a reserva) e para.
6. Uma volta inteira sem nenhum candidato (ninguém disponível em 2R): para e espera. A varredura tenta de novo a cada
   minuto. No máximo uma volta nova por passo, para nunca girar em falso.
7. Passada 1 h do despacho (`despachada_em`), depois do item 2: não oferece mais nem agenda o próximo passo. Grava
   `lista_aberta_em` se ainda estava fechada e registra `limite de 1 h; só na lista` uma vez. O pedido fica só na lista
   até alguém aceitar ou a distribuição encerrar (atribuição, cancelamento, `adhoc` desligado).

O motoboy que tem oferta pendente de outro pedido conta como "ainda não perguntado": entra num passo seguinte da mesma
rodada, se ficar livre a tempo, ou na volta seguinte.

### A fila (sem encaixe)

`Encaixe::calcular` passa a testar só "termina tudo e depois vai": as paradas que ele ainda tem, na ordem de aceite
(como hoje), e depois a coleta e a entrega novas. Tempo = chegada no cliente novo. Paradas fixas de 3 min na loja e
2 min no cliente, linha reta × 1,3 a 25 km/h (OSRM desligado), como hoje. O campo `encaixe` some da fila e do painel.

Exemplo: Ana termina a entrega dela em 10 min e levaria mais 10 min para o pedido novo (20 min); Bruno, livre, leva
19 min. Bruno recebe primeiro; se recusar, Ana é a próxima da rodada.

## 4. Aceite

- **Lista fechada** (volta 1, rodada 1, sem `lista_aberta_em`): só quem tem a oferta (ou a vencida, enquanto ninguém
  foi oferecido depois), como hoje. Os outros levam 409 "Este pedido está sendo oferecido a outro motoboy.".
- **Lista aberta**: qualquer motoboy aceita (o primeiro leva; a `TravaDoPedido` garante um só). A oferta pendente, se
  houver, vira `cancelada`. Quem aceitou pela oferta grava `aceita` na oferta; quem aceitou pela lista ganha uma linha
  `aceita_pela_lista`. Motivo da distribuição: `aceita` nos dois casos.
- Quem chega depois leva o 409 de sempre ("Este pedido passou para outro motoboy.", do `BarrarAceiteDePedidoEncerrado`).
- O celular de quem estava com a oferta para de tocar sozinho no vencimento (até 20 s). Se ele tocar em Aceitar nesse
  intervalo, leva o mesmo 409.

## 5. Recusar e Dispensar

`POST v1/entregas/motoboy/pedidos/{id}/recusar` (mesma rota, limitador `entregas-motoboy-recusa`):

| Situação | Resposta | Efeito |
|---|---|---|
| Ele tem a oferta pendente | 200 `{"resultado": "recusada"}` | oferta `recusada`, próximo passo na hora |
| Sem oferta dele, distribuição em `ofertas` e pedido aberto sem motoboy (lista aberta **ou fechada**) | 200 `{"resultado": "dispensada"}` | linha `dispensada` na volta atual: some da lista dele e não recebe oferta até a volta seguinte |
| Distribuição fora de `ofertas`, pedido com motoboy, encerrado ou que deixou de ser aberto | 409 "Esta oferta não está mais com você." | nada |
| Trava ocupada | 503 | nada |

A dispensa vale também com a lista fechada (rodada 1 da volta 1: ex.: a oferta dele venceu e ele tocou Recusar, ou
dispensou pelo alarme): com o 409, o APK guardaria o pedido nos dispensados locais e ele sumiria até reiniciar o app,
em vez de voltar na volta seguinte.

O app chama essa rota no Recusar e no Dispensar de todo pedido marcado como distribuído (seção 7). A recusa local de
2 h do cartão nativo não vale para esses pedidos.

## 6. Dados

Migration nova (`..._add_rodadas_entregas_distribuicao_table.php`; o sufixo mantém o esquema no banco em memória dos
testes):

- `entregas_distribuicoes`: `volta` (int, padrão 1), `rodada` (tinyint, padrão 1), `volta_iniciada_em` (timestamp),
  `lista_aberta_em` (timestamp, nulo).
- `entregas_ofertas`: `volta` (int, padrão 1), `rodada` (tinyint, padrão 1), `raio_m` (int, nulo). A coluna `resposta`
  ganha `dispensada` e `aceita_pela_lista` (texto, sem enum). Linhas `dispensada` e `aceita_pela_lista` têm
  `oferecida_em` = `vence_em` = `respondida_em` = a hora da ação.
- Índice `(distribuicao_id, volta)` em `entregas_ofertas`.

Fases: `ofertas` passa a durar até o fim (aceite, atribuição, cancelamento). `aberta` só sobra para `falha` e para as
distribuições do ciclo antigo (chave desligada). Motivos novos: nenhum além dos existentes.

## 7. Lista "Novos pedidos" (`FiltrarPedidosAbertosDoMotoboy`)

Com a chave das rodadas ligada, para cada pedido da lista em distribuição na fase `ofertas`:

- lista fechada: só aparece para quem tem a oferta (como hoje);
- lista aberta: aparece para todos, **menos** quem tem linha `recusada` ou `dispensada` na volta atual;
- quem tem a oferta pendente recebe `entregas_oferta: {vence_em, tempo_estimado_s, segundos_restantes}` (como hoje);
- todo pedido em distribuição recebe `entregas_distribuicao: true` (o app usa para chamar o servidor no Dispensar).

**Pedidos além de R:** a lista do Fleet-Ops (`nearby`) só traz pedidos até R. O filtro **acrescenta** os pedidos da
empresa em distribuição com lista aberta cuja coleta está até 2R da posição do motoboy e que ainda não vieram, no mesmo
formato (`Http\Resources\v1\Order`), com as mesmas regras acima. Erro em qualquer parte: devolve a resposta original e
registra (`filtro da lista: <classe>`), como hoje.

## 8. Console (painel "Distribuição")

- Cabeçalho: "Volta 2 · rodada 1 · até 6 km" e "Lista aberta desde 05:31" (ou "Lista só para quem recebe a oferta").
- Oferta atual com o cronômetro de 20 s; fila da rodada (sem "no caminho"; mantém "já leva pedido" e "≈").
- Histórico agrupado por volta, com a rodada e o raio em cada linha e as respostas novas: "dispensou pela lista",
  "aceitou pela lista".
- Botão **"Mostrar a todos agora"** (`POST .../distribuicao/abrir`, só admin, com confirmação): grava `lista_aberta_em`
  e segue o ciclo, sem alarme geral. 409 se a lista já está aberta ou a distribuição saiu de `ofertas`.
- `GET .../distribuicao` ganha `volta`, `rodada`, `raio_m`, `lista_aberta_em` e `rodadas` (a chave).

## 9. App (APK 30)

- Dispensar (card da lista e tela do pedido) em pedido com `entregas_distribuicao`: chama `recusar`, tira o pedido da
  lista local e recarrega. 409 some sem aviso; rede e 503 avisam e o pedido fica.
- Recusar do cartão nativo: já chama a rota quando é oferta. O cartão de pedido aberto comum não aparece mais para
  esses pedidos (o servidor não manda `OrderPing` a todos).
- Cronômetro e prazo vêm dos `segundos_restantes`/`entregas_oferta_segundos` do servidor: os 20 s não precisam de
  mudança no app.
- APK anterior: o cartão conta 3 min e seguiria tocando depois que a oferta passou ao próximo; com ofertas de 20 s,
  vários celulares tocariam juntos. Por isso a chave só liga com o APK 30 em todos.

## 10. Chave e compatibilidade

- `ENTREGAS_DISTRIBUICAO_RODADAS` (`services.entregas.distribuicao_rodadas`, `Distribuicao::emRodadas()`), no
  `x-api-env` do `deploy/docker-stack.yml`. Só vale com `ENTREGAS_DISTRIBUICAO=1`.
- Desligada: o ciclo de hoje (30 s, encaixe, 3 min, abre a todos), sem mudança.
- Ligada: as distribuições que já estavam em `ofertas` seguem no ciclo novo a partir do próximo passo (volta 1, rodada
  1 pelas colunas padrão). As que já estavam `aberta` seguem como abertas.
- Desligar no meio: as distribuições em `ofertas` voltam ao ciclo antigo no próximo passo (o prazo de 3 min do
  despacho pode abrir a todos na hora, se já passou).

## 11. Varredura (`entregas:distribuicao-varrer`, a cada minuto)

Com as rodadas ligadas:

- vence as ofertas pendentes vencidas há mais de 20 s (como hoje);
- encerra as distribuições cujo pedido ganhou motoboy, encerrou ou sumiu (como hoje);
- **avança** as distribuições em `ofertas` sem oferta pendente e paradas há mais de 1 min (`updated_at`): volta que
  esperava o intervalo, ninguém disponível, job perdido. Cada tentativa sem candidato toca o `updated_at`;
- não acorda as despachadas há mais de 1 h (limite de 1 h), exceto a que ainda está com a lista fechada: essa é
  avançada uma vez, só para abrir a lista;
- não abre mais pelo prazo.

## 12. Erros

- Falha no ciclo (banco, bug): continua abrindo a todos com o alarme geral (`falha`), como hoje. É a rede de segurança.
- Push da oferta falhou: a oferta vence sozinha e o ciclo segue (como hoje).
- Job perdido (Redis reiniciado): a varredura vence a oferta e avança a distribuição parada.
- Logs novos (prefixo `[entregas] distribuição:`): `rodada <n> (raio <m> m)`, `volta <n>`, `lista aberta`,
  `oferta dispensada`, `aceita pela lista`, `aguardando motoboy`, `limite de 1 h; só na lista`.

## 13. Testes

- php-wasm: `distribuicao-encaixe.php` (só "termina tudo e depois vai"), `distribuicao-fila.php`,
  `distribuicao-ciclo.php` (rodadas, raios, volta nova com e sem o intervalo de 1 min, rodada vazia, ninguém em 2R,
  novato entrando na rodada 2, oferta pendente de outro pedido), `distribuicao-rotas.php` (recusar × dispensar × 409,
  "Mostrar a todos agora"), `filtrar-pedidos-abertos.php` (lista fechada × aberta, dispensados, acréscimo até 2R,
  `entregas_distribuicao`), `barrar-aceite.php` (aceite pela lista com a oferta de outro pendente), `reenvio.php`
  (nada de reenvio em `ofertas`, aviso "sem motoboy" mantido).
- Chave desligada: os testes de hoje continuam passando sem mudança.
- Console: `scripts/teste-portal/distribuicao.test.mjs` (textos de volta, rodada, lista aberta e respostas novas).
- App: `scripts/testes/oferta.teste.ts` e `lista-de-pedidos.teste.ts` (Dispensar de pedido distribuído).

## 14. Riscos aceitos

- Com um motoboy só, ele recebe a oferta a cada 1 min por até 1 h (20 s tocando, 40 s parado); depois, o pedido fica
  só na lista.
- O tempo é estimado em linha reta; o OSRM próprio continua sendo a melhoria (`ENTREGAS_DISTRIBUICAO_OSRM`).
- A sequência das paradas do motoboy ocupado é suposta pela ordem de aceite.
- O motoboy a até 2R vê na lista pedidos de lojas a 12 km; o que é longe demais ele dispensa.
- Dois pedidos podem ser oferecidos ao mesmo motoboy no mesmo instante (trava por pedido), como hoje.
