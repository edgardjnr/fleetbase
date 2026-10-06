# Roteiro da homologação do iFood (Logistics)

Para a sessão com o analista do iFood (45 a 60 min, acesso remoto). Critérios: `docs/ifood/referencia-logistics.md`,
seção 6. Arquitetura: `CLAUDE.md`, seções "Integração iFood". Escrito em 2026-10-06, com as etapas 2 a 4 em produção.

## 1. Pedir a sessão

No Portal do Desenvolvedor do iFood → **Área de Chamados** → novo ticket:

- **Assunto:** `Solicitação de Homologação - Logistics API`
- **Descrição:**
  ```
  Aplicativo: <nome do app distribuído no Portal do Desenvolvedor>
  Merchant ID: <merchant da loja de teste (Terraço Pizza Bar), UUID mostrado em Lojas → selo "Vinculada">
  Contact: <e-mail e telefone do responsável técnico>
  Status: Pronto para homologação
  Modelo: entrega própria (deliveredBy MERCHANT), recepção por polling a cada 30 s, app do entregador próprio (Android).
  ```
- Conta **CNPJ** (CPF é recusado). O iFood confirma os pré-requisitos e marca dia e hora (horário comercial). Reprovação =
  esperar **15 dias** para a próxima: só peça quando a véspera (seção 2) estiver toda verde.

## 2. Véspera (24 h antes)

- [ ] **Loja de teste limpa:** sem pedidos de teste em andamento (conclua ou deixe o iFood concluir; os antigos da
      lista do console podem ficar, mas nenhum aberto).
- [ ] **Vínculo ativo:** Fleet-Ops → Recursos → Lojas → Terraço Pizza Bar com "Vinculada · …".
- [ ] **Polling rodando:** `docker service logs --since 10m entregas_scheduler 2>&1 | grep '\[entregas\] ifood'` sem
      `polling interrompido`, `vínculo perdido` nem `token ilegível`.
- [ ] **Filas no ar:** `docker service ls | grep queue` → `entregas_queue` e `entregas_queue-ifood` com 1/1.
- [ ] **Teste completo de ponta a ponta** com um pedido de teste (seção 4), até "Liberar sem código" (o código do
      pedido de teste não é obtível fora da sessão).
- [ ] **Celular de teste:** APK 28 ou mais novo, motoboy online, GPS ligado, perto da loja de teste; carregado.
- [ ] **Motoboys avisados:** o pedido de teste toca o alarme nos motoboys online perto da loja de teste.
- [ ] **Console aberto como admin** (o painel iFood do pedido é só de admin), com o som liberado (um clique na página).
- [ ] **Terminal da VPS aberto** com os comandos da seção 5 à mão.
- [ ] Internet estável (> 5 Mbps, sem VPN), TeamViewer ou AnyDesk instalado no PC que vai ser compartilhado.

## 3. O que o analista testa e como mostrar

| Critério do iFood | O que o Entregas faz | Como mostrar |
|---|---|---|
| **Recepção por polling a cada 30 s** com `excludeHeartbeat=true` e `x-polling-merchants` | `entregas:ifood-polling` a cada 30 s (agendador), lojas agrupadas pelo token | Gerar pedido de teste → em até 30 s aparece em Fleet-Ops → Pedidos com o selo "iFood #N" |
| **ACK de todos os eventos, sem perder evento** | Grava em `entregas_ifood_eventos` (id único) e **só depois** manda o ack; banco fora = sem ack, o iFood reenvia | Consulta 1 da seção 5 (eventos gravados e processados) |
| **Detalhes do pedido** (`GET /logistics/orders/{id}`), cliente, pagamento, itens; trata 404 | O job `ProcessarPedidoIfood` lê o pedido no PLC e cria o pedido com coleta na loja; 404/5xx tentam de novo | Detalhe do pedido no console: painel **iFood #N** (cobrança, observações, código exigido) |
| **assignDriver** (`workerName`, `workerPhone`, `MOTORCYCLE`); não aloca duas vezes; trata 409 | Quando o motoboy aceita (ou a central atribui), o servidor envia uma vez; 409 = já aceita (não repete) | Motoboy aceita no app → painel iFood "Última etapa: Motoboy informado"; log `ação enviada` |
| **goingToOrigin** depois do assignDriver | Enviado quando o pedido fica "Iniciado" | Painel: "A caminho da loja" |
| **arrivedAtOrigin** quando chega; não coleta duas vezes | GPS a até 100 m da loja (`entregas:ifood-acompanhar`, 30 s) | Motoboy chega à loja → "Chegou na loja"; log `chegada pelo GPS` (scheduler) |
| **dispatch** depois da coleta | Quando o motoboy toca "A caminho" no app | Painel: "Saiu para entrega" |
| **arrivedAtDestination** antes do código | GPS a até 100 m da entrega, ou na conclusão pelo app | Painel: "Chegou no cliente" |
| **verifyDeliveryCode** (obrigatório): monitora DDCR, pede o código, trata incorreto, deixa digitar de novo | DDCR marca `exige_codigo`; o app abre o campo do código ao concluir; código errado = "Código incorreto…" e o campo continua aberto (teto de 10 erros) | No app: concluir → campo do código → digitar um errado (mensagem) → digitar **o código que o analista passar** → conclui; painel "Conferido pelo motoboy"; log `código de entrega conferido` |
| **Cancelamento (CAN)** | Cancela o pedido no Entregas, avisa o motoboy; depois do "A caminho", o motoboy recebe mesmo cancelado | Analista cancela → o app mostra "Cancelado pelo iFood…"; log `pedido cancelado pelo iFood` (fila) |
| **Token: renovação proativa e no 401; guardado com segurança; sem token novo a cada chamada** | Renova quando faltam < 5 min, a cada 30 min os que vencem em < 1 h, e no 401 (repete uma vez); tokens cifrados com a APP_KEY no banco; credenciais no `stack.env` | Simular token vencido: comando 3 da seção 5 → a próxima chamada renova (consulta 2 mostra `renovado_em` novo); log `token recusado (401), renovando` quando for o 401 |
| **Rate limit:** polling ≥ 30 s; 429 com `Retry-After` | 429 pausa as lojas do token pelo `Retry-After` (até 300 s; sem cabeçalho, 60 s); no job, volta para a fila pelo `Retry-After` | Explicar (o analista força o 429); depois, o polling volta sozinho |
| **Erros:** 400 valida; 401 renova; 404 trata; 409 não repete; 500 espera e repete | Erros tipados (`ErroIfood`); 5xx/rede: até 5 tentativas (10 s a 5 min) em 30 min; 4xx: recusa registrada e aviso na central | Painel iFood: "Última recusa"; aviso sonoro "O iFood recusou…" no console |
| **Sem PII nos logs** | Logs `[entregas] ifood:` só com ids, códigos e número do pedido; corpo cru nunca vai ao log; nome, telefone e código digitado mascarados | `docker service logs` da seção 5 durante a sessão |
| **HTTPS, credenciais em variável de ambiente** | API atrás do Cloudflare Tunnel (HTTPS); `IFOOD_CLIENT_ID`/`SECRET` no `stack.env` | — |

## 4. Fluxo da demonstração (fim a fim)

1. Analista (ou você, em "Gerar pedido de teste") cria o pedido na loja de teste.
2. Console → Pedidos: aparece com o selo **iFood #N**; abrir o detalhe → painel iFood ("Nenhuma ainda").
3. Celular: o alarme toca → **Aceitar** (vai para "Iniciado" → assignDriver + goingToOrigin).
4. Ir até a loja (ou já estar a < 100 m) → arrivedAtOrigin em até ~30 s.
5. App: **Atualizar status → A caminho** → dispatch.
6. Ir até a entrega (o pedido de teste fica ~1 km ao norte da loja, "RUA TESTE") ou concluir direto → arrivedAtDestination.
7. App: **Atualizar status → Concluído** → abre o campo do **código** → errado (mensagem) → certo (o analista passa) →
   conclui. Painel: "Conferido pelo motoboy".
8. Outro pedido de teste para o **cancelamento**: aceitar e o analista cancela no Gestor de Pedidos.

Se algo sair do roteiro: o painel iFood mostra a última etapa e a última recusa; "Liberar sem código" é a saída da
central quando o código não confere.

## 5. Comandos na VPS

Logs (deixe um terminal para cada):

```bash
docker service logs -f --since 5m entregas_scheduler 2>&1 | grep '\[entregas\] ifood'   # polling, tokens, GPS
docker service logs -f --since 5m entregas_queue-ifood 2>&1 | grep '\[entregas\] ifood' # pedido criado, ações, CAN
docker service logs -f --since 5m entregas_application 2>&1 | grep '\[entregas\] ifood' # código de entrega, vínculo
```

Banco (`docker exec -it $(docker ps -q -f name=entregas_database) mysql -uroot -p fleetbase`; antes, `SET time_zone='-03:00';`):

```sql
-- 1. eventos recebidos e processados (prova do polling + ack)
SELECT codigo, pedido_ifood_id, criado_no_ifood, created_at, processado_em, ignorado FROM entregas_ifood_eventos ORDER BY id DESC LIMIT 20;
-- 2. vínculo e tokens (sem mostrar o token)
SELECT merchant_id, situacao, expira_em, renovado_em FROM entregas_ifood_lojas;
-- 3. simular token vencido (a próxima chamada renova sozinha; NÃO mexa no access_token: texto ilegível derruba o vínculo)
UPDATE entregas_ifood_lojas SET expira_em = NOW() WHERE merchant_id = '<merchant da loja de teste>';
-- 4. estado do pedido do lado do iFood
SELECT numero, ultima_acao, recusa_acao, recusa_status, exige_codigo, conclusao_liberada_em, cancelado_pelo_ifood_em
  FROM entregas_ifood_pedidos ORDER BY created_at DESC LIMIT 5;
```

## 6. Depois da aprovação

1. Credenciais de produção (se o iFood trocar o Client ID/Secret): `stack.env` → Update the stack.
2. `ENTREGAS_IFOOD_EXIGE_APP_NOVO=1` já ligado (todos os celulares com o APK novo).
3. Vincular as lojas reais: Fleet-Ops → Recursos → Lojas → **Vincular iFood** (código de vínculo → dono autoriza no
   Portal do Parceiro → código de autorização). A coleta é o Local da loja: confira a coordenada antes.
4. Conferir no primeiro pedido real: o refresh token no vínculo, o formato do `schedule` do agendado e o pagamento na
   porta (dinheiro/troco), que o pedido de teste não traz.
