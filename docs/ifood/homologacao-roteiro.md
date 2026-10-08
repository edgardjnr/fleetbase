# Homologação do iFood (Logistics): roteiro de ensaio e gravação

Base: os critérios em `docs/ifood/referencia-logistics.md`, seção 6. Quando o iFood mandar o checklist de gravação, ligue
cada cenário dele a um passo deste roteiro (o checklist vale mais que este texto).

## 0. Checklist do iFood (recebido em 2026-10-08)

O iFood pede **um vídeo por cenário**, cada um num link separado (Google Drive, não anexo no chamado), com a **data e a
hora da execução** e o **client_id do app de teste** no texto do chamado. Deixe o relógio do Windows visível na
gravação. Faltou alguma informação? O iFood pede tudo de novo.

| Cenário do iFood | Nosso passo | Observação |
|---|---|---|
| 1. Configuração inicial: Polling | Seção 3, critérios 1 e 9 | O log `chamada {operacao: polling}` mostra `excludeHeartbeat: true` e `x-polling-merchants: <id da loja>`; o `chamada ack` sai logo depois de cada `polling 200`. Grave ~1 min de 204 a cada 30 s e depois um pedido de teste entrando. |
| 2. Configuração inicial: Webhook | **Não se aplica** | Pela documentação (Events → Webhook e Critérios de homologação), o webhook só existe para app **centralizado**, e a homologação é "Polling **OU** Webhook". O nosso app é **distribuído** e usa polling. Escreva isso no chamado, em vez de mandar o vídeo. |
| 3. Fluxo de entrega | Seção 3, critérios 2 a 7 | Um pedido do começo ao fim: `assignDriver` → `goingToOrigin` → `arrivedAtOrigin` → `dispatch` → `arrivedAtDestination`, cada um com `chamada <ação> 202` no log. |
| 4. Etapa obrigatória (código) | Seção 3, critério 8 | Pedido com `DELIVERY_DROP_CODE_REQUESTED` (DDCR no log `eventos recebidos`), o app pede o código, um **errado** (400/422) e depois o **certo** (`verifyDeliveryCode` 200) e o pedido conclui. Antes, descubra de onde vem o código do pedido de teste. |

Pode ser o mesmo pedido nos cenários 3 e 4 (o código é o fim do fluxo), mas grave os dois vídeos separados, ou mande o
mesmo vídeo em dois links com o trecho de cada um indicado.

**Logs do Developer Portal** (também obrigatórios): Developer Portal → Logs da API → HTTP Requests → selecione o app de
teste → Buscar → baixe e **anexe no chamado** (estes vão anexados; os vídeos vão por link). Baixe depois das gravações,
para cobrirem os quatro cenários.

**Ensaie o roteiro inteiro antes de gravar.** Se um critério falhar na análise, o chamado é encerrado e é preciso
abrir outro. Pela documentação, depois de uma reprovação é preciso esperar 15 dias para pedir de novo.

## 1. Preparação (um dia antes)

1. Código no ar: `cd ~/entregas && bash deploy/atualizar.sh api`. Isso traz o modo homologação e o comando
   `entregas:ifood-homologacao`.
2. `docker-stack.yml` novo colado no Portainer, com `ENTREGAS_IFOOD_HOMOLOGACAO=1` no `stack.env` (Stacks → entregas →
   Editor → Update the stack, "Re-pull image" desligado). Confira:
   `docker exec $(docker ps -q -f name=entregas_application) printenv ENTREGAS_IFOOD_HOMOLOGACAO` → `1`.
3. Sistema limpo: `deploy/limpeza/LEIAME.md`. Sem pedidos antigos no console, no app e no Gestor de Pedidos da loja de
   teste (conclua ou cancele no iFood o que estiver aberto lá).
4. Vínculo ok:
   `docker exec -it $(docker ps -q -f name=entregas_application) php artisan entregas:ifood-homologacao situacao`
   → loja de teste com `"situacao": "vinculada"` e `"homologacao": true`.
5. Celular do vídeo com o APK novo (o que tem o código de entrega do iFood), logado como um motoboy ativo e online, perto
   do Terraço Pizza Bar, ou com a distribuição testada para ele receber a oferta.
6. Avise os outros motoboys: o pedido de teste vai a quem estiver perto da loja de teste.
7. Descubra antes de onde vem o código de entrega de um pedido de teste (pergunte ao analista ou procure no Gestor de
   Pedidos). Sem ele, o cenário obrigatório do código não fecha.

## 2. Telas para gravar

Grave a tela do PC com quatro áreas visíveis: o Gestor de Pedidos do iFood (loja de teste), o console
(Fleet-Ops → Pedidos, painel iFood do pedido aberto), um terminal com os logs e o celular espelhado (scrcpy ou similar).

Terminal com os logs da integração (três serviços, cada um num painel ou aba):

```bash
# polling, ack, renovação de token, chegada pelo GPS
docker service logs -f --since 1m entregas_scheduler 2>&1 | grep --line-buffered '\[entregas\] ifood'
# criação do pedido e ações de logística (assignDriver, goingToOrigin, dispatch...)
docker service logs -f --since 1m entregas_queue-ifood 2>&1 | grep --line-buffered '\[entregas\] ifood'
docker service logs -f --since 1m entregas_queue 2>&1 | grep --line-buffered '\[entregas\] ifood'
# código de entrega (verifyDeliveryCode) e conclusão pelo app
docker service logs -f --since 1m entregas_application 2>&1 | grep --line-buffered '\[entregas\] ifood'
```

Com o modo homologação, cada chamada ao iFood sai como `[entregas] ifood: chamada` com `operacao`, `status`, `ms` e só ids
(`pedido_ifood`, quantidade de lojas ou de eventos). Nenhum token, nome, telefone, endereço ou código vai ao log.

## 3. Fluxo completo (critérios 1 a 9)

| # | Critério | O que fazer | O que mostrar |
|---|---|---|---|
| 1 | Recepção (polling 30 s, ack de todos) | Antes de tudo, deixe o log do scheduler rodando 1 min | `chamada {operacao: polling, status: 204}` a cada 30 s |
| 1 | | Gere um pedido de teste no iFood e confirme no Gestor | `eventos recebidos {codigos: [PLC]}`, `chamada polling 200`, logo depois `chamada ack 202 {eventos: N}` |
| 2 | Detalhes do pedido | Espere o pedido aparecer no console (Pedidos) com o selo "iFood #N" | `chamada pedido 200 {pedido_ifood}`, `pedido criado`; no console, cliente, endereço, cobrança e observações no painel iFood. O objeto do Logistics não traz os itens. |
| 3 | `assignDriver` | O motoboy aceita no app (ou a central atribui) | `ação enviada {acao: assignDriver}` e `chamada assignDriver 202`; no painel iFood, a última etapa informada |
| 4 | `goingToOrigin` | Logo depois do aceite | `ação enviada {acao: goingToOrigin}` |
| 5 | `arrivedAtOrigin` | O motoboy chega a até 100 m da loja (GPS) | `chegada pelo GPS {acao: arrivedAtOrigin}` (scheduler) e `ação enviada` |
| 6 | `dispatch` | O motoboy toca "A caminho" | `ação enviada {acao: dispatch}` |
| 7 | `arrivedAtDestination` | O motoboy chega ao cliente, ou toca concluir (o app avisa o iFood antes) | `ação enviada {acao: arrivedAtDestination}`. Mostre que a ordem dos passos 3 a 7 é sempre a mesma. |
| 8 | Código de entrega (obrigatório) | No app, concluir abre o campo do código. Digite um código **errado** | `chamada verifyDeliveryCode 400` (o iFood respondeu 400 na sonda; a documentação fala em 422) e `código de entrega incorreto`; o app mostra o erro no campo e deixa digitar de novo |
| 8 | | Digite o código **certo** | `chamada verifyDeliveryCode 200`, `código de entrega conferido`, o pedido conclui no app e no console |
| 9 | Ack | Já mostrado no 1: um ack depois de cada polling com eventos, com todos os ids, uma vez só | `chamada ack 202` logo depois de cada `polling 200` |

Pedido cancelado (CAN): cancele um pedido de teste no Gestor de Pedidos → `pedido cancelado pelo iFood`, o pedido some
dos abertos, e o motoboy atribuído recebe o push "Pedido #N cancelado pelo iFood". O cancelamento pelo console fica
barrado ("o cancelamento é feito no iFood").

## 4. Requisitos não funcionais (erros forçados)

Os comandos rodam no container da API (prefixo abaixo como `H`):

```bash
H="docker exec -it $(docker ps -q -f name=entregas_application) php artisan entregas:ifood-homologacao"
```

| Cenário | Comando | O que aparece no log |
|---|---|---|
| Token vencido (renovação proativa) | `$H token-vencido` | No próximo polling (até 30 s): `chamada refresh 200`, `token renovado {motivo: vencimento}`, `chamada polling 204/200`. Não pede token novo a cada chamada: entre renovações, só polling. |
| Token inválido (renovação reativa no 401) | `$H token-invalido` | `chamada polling 401` (resposta real do iFood), `token recusado (401), renovando`, `chamada refresh 200`, `token renovado {motivo: 401}`, `chamada polling` repetida com sucesso |
| 429 no polling (respeita o `Retry-After`) | `$H simular --operacao=polling --status=429 --espera=90` | `erro simulado (homologação) {retry_after: 90}`, `429, esperando 90 s`; nenhum `chamada polling` por ~90 s; depois volta a cada 30 s |
| 5xx no polling | `$H simular --operacao=polling --status=503` | `polling falhou {status: 503}`; a rodada seguinte (30 s) tenta de novo |
| 404 no GET do pedido | Antes de gerar o pedido: `$H simular --operacao=pedido --status=404` | `chamada`/`erro simulado` 404; o job tenta de novo em 15 s: `chamada pedido 200`, `pedido criado` |
| 5xx numa ação | Com um pedido em andamento, antes do "A caminho": `$H simular --operacao=dispatch --status=500` | `erro simulado ... dispatch 500`; o job repete em 10 s: `chamada dispatch 202`, `ação enviada` |
| 409 (não repete a operação) | Antes do aceite: `$H simular --operacao=assignDriver --status=409` | `ação já aceita pelo iFood (409)`: a sequência avança (`goingToOrigin` em seguida) sem reenviar o `assignDriver` |
| 400 (payload recusado) | **Só num pedido separado, no fim**: `$H simular --operacao=goingToOrigin --status=400` | `ação recusada {status: 400}` e o aviso fixo no console. A sequência daquele pedido para (o pedido só conclui com "Liberar sem código"). |

- O erro simulado vale **uma vez** e vence em 10 min. `$H situacao` mostra os que estão pendentes, e `$H limpar` tira
  todos.
- O token e o refresh nunca são simulados: um refresh recusado derruba o vínculo.
- Diga ao analista que o 429, o 5xx, o 404 e o 400 são simulados no nosso cliente HTTP (o iFood de teste não os
  produz quando queremos). O tratamento que aparece é o mesmo da resposta real, coberto pelos testes
  `scripts/teste-php/ifood-cliente.php`, `ifood-polling.php`, `ifood-processar.php` e `ifood-acoes.php`.
- O polling nunca roda com menos de 30 s (`everyThirtySeconds` com `withoutOverlapping`). Não há como "fazer requisições
  rápidas demais" pelo sistema.

## 5. Segurança

- HTTPS em todas as chamadas (`https://merchant-api.ifood.com.br`).
- Credenciais só no `stack.env` (variáveis de ambiente); tokens cifrados no banco (`encrypt()`, APP_KEY).
- Logs só com ids e códigos (mostre o log da gravação: sem nome, telefone, endereço ou token).

## 6. Depois da homologação

- `ENTREGAS_IFOOD_HOMOLOGACAO=` vazio no `stack.env` → Update the stack. Com o modo ligado, o log ganha uma linha por
  chamada (uma a cada 30 s só do polling).
- `$H limpar`, se sobrou algum erro simulado.
