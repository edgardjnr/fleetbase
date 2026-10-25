# Integração iFood

Parte do `CLAUDE.md` da raiz (índice em "Onde está cada assunto"). Responda e documente em português do Brasil.

## Integração iFood (etapa 2: entrada dos pedidos)

Os pedidos iFood dos restaurantes entram pelo módulo **Logistics** da Merchant API: app **distribuído**, entrega própria (`deliveredBy: MERCHANT`).

- **Onde ler mais:**
  - desenho e decisões: `docs/superpowers/specs/2026-10-05-integracao-ifood-logistics-design.md`;
  - referência da API: `docs/ifood/referencia-logistics.md` (a seção "Descobertas da sonda" vale mais que o resto);
  - planos `docs/superpowers/plans/2026-10-05-ifood-etapa-2a-servidor.md` e `-2b-tela-lojas.md`, executados com ajustes de revisão: **o código é a referência**.
- **Situação:** etapas 2 e 3 em produção e testadas com a loja de teste em 2026-10-06 (plano da 3: `docs/superpowers/plans/2026-10-06-ifood-etapa-3-ciclo.md`). Etapa 4 (APK, console e portal; plano `2026-10-06-ifood-etapa-4-app-console.md`) implementada em 2026-10-06 nos ramos `ifood-etapa-4` deste repo e do `entregas-navigator`, ainda sem push, deploy e teste real (ver "Etapa 4: APK, console e portal").
- **Não vincule loja real antes do APK da etapa 4 em todos os celulares** e da trava "Atualize o app" ligada (ver "Integração iFood (etapa 3: ciclo da entrega)"). Até lá, só a loja de teste.
- **O pedido de teste (`isTest`) vai aos motoboys como o real** (decisão de 2026-10-06): os motoboys online perto da loja de teste recebem o alarme, com o endereço falso "RUA TESTE" (entrega ~1 km ao norte). Avise-os antes de gerar um pedido de teste. Desde a etapa 3 ele também pede o código de entrega quando o iFood exige (a central pode usar "Liberar sem código").

### Como ligar

Nesta ordem:

1. Preencha o `stack.env` com `ENTREGAS_IFOOD=1`, `IFOOD_CLIENT_ID` e `IFOOD_CLIENT_SECRET`, as credenciais do app **distribuído** (Portal do Desenvolvedor → Meus Apps → Credenciais), e faça Stacks → entregas → Editor → Update the stack ("Re-pull image" desligado).
   - Os três são lidos em `config('services.ifood')`. O `ClienteIfood::ligada()` exige os três preenchidos.
   - O Update the stack já recria a API, a fila e o scheduler, porque as variáveis mudaram. Reiniciar à mão é opcional: `docker service update --force entregas_queue && docker service update --force entregas_queue-ifood && docker service update --force entregas_scheduler`.
2. Se houver código novo, rode `bash deploy/atualizar.sh api`: ele roda as migrations (as três tabelas) e o `config:cache`.
- **Desligada:**
  - os três comandos nem sobem (`when(ClienteIfood::ligada())` no `Kernel`; cada comando confere de novo);
  - a tela Lojas esconde os botões;
  - código e vínculo respondem 409. Desvincular funciona sempre.

### Vínculo (tela Lojas)

Fleet-Ops → Recursos → Lojas, só admin. API: `IfoodLojasController`, `int/v1/entregas/lojas/{id}/ifood/*`, limitador `entregas-ifood-vinculo` (20 por minuto por usuário) no pedido do código e no vínculo (`POST .../ifood/codigo` e `POST .../ifood/vincular`). O `DELETE .../ifood` (desvincular) não tem limitador.

- **Vincular iFood** abre o modal (`modals/vincular-ifood`):
  1. `POST .../ifood/codigo` traz o código de vínculo (userCode), o link do Portal do Parceiro com o código preenchido (só vira botão se for https de `*.ifood.com.br`) e a contagem de 10 min;
  2. o dono da loja autoriza no Portal do Parceiro e passa o código de autorização à central, que cola no campo (`POST .../ifood/vincular` com `authorizationCode`);
  3. conta com várias lojas: a central escolhe uma (`POST .../ifood/vincular` com `merchant_id`).
- **Selo no card:** "Vinculada · <loja no iFood>", "Vínculo perdido" em vermelho (com os botões "Vincular de novo" e "Desvincular iFood") ou "—". Os botões só aparecem com `ifood_ligado` na lista.
- **Desvincular iFood** (com confirmação; `DELETE .../ifood`) apaga os tokens e libera o merchant para outra loja.
- Front: controller e template de `management/lojas`, modal `components/modals/vincular-ifood.*` e funções puras em `packages/fleetops/addon/utils/vinculo-ifood.js`.
- **Lojas pelo token** (2026-10-19): o app de produção "Restaurante Pro" (`517fd57d…`) só tem Logistics e Events homologados, e o `GET /merchant/v1.0/merchants` voltou vazio no vínculo do Terraço ("não tem lojas"). Com a lista vazia ou 403, as lojas saem do `merchant_scope` do JWT (`VinculosIfood::lojasDoEscopo`; a assinatura não é conferida, porque o token acabou de vir do `/oauth/token`). Sem o módulo Merchant não há nome: o card mostra "Loja iFood <8 primeiros do id>". Logs (`entregas_application`): `lojas tiradas do token (merchant_scope)` com os ids, ou `token sem lojas no merchant_scope` com só os nomes dos campos do token (aí a conta que autorizou não tem a loja).
- **Um merchant, uma loja** (checagem e chave única).
- **Coleta = Local da Loja**, não o endereço do iFood. A mais de 300 m dele, o log avisa (`coleta diverge do iFood (N m)`).
- **O código de autorização vale uma vez.** Os tokens da troca ficam 10 min no cache, cifrados:
  - se a lista de lojas falhar por rede ou 5xx, clicar de novo em Vincular usa esses tokens, sem código novo;
  - se a lista for recusada (400, 401, 403), é preciso um código novo.
- **Erros**, sempre `{"errors": ["…"]}`:
  - 422: validação, código vencido ou recusado, merchant já em outra loja, conta sem lojas;
  - 422 também no pedido do código, quando o iFood recusa as credenciais do app ("Confira as credenciais");
  - a recusa (400, 401, 403) termina com "(iFood <status>: <motivo>)", tirado do corpo do iFood (`IfoodLojasController::motivoDoIfood`: `error.code`/`error.message`, `errorType`/`code`/`description`, até 200 caracteres, 6+ dígitos mascarados), para a central ver o motivo sem abrir o log (até 2026-10-19 o log da `application` nem chegava ao `docker service logs`; ver "Logs da API" em `deploy/CLAUDE.md`). "Recusou o código" com o código certo = código de autorização de outra rodada: a janela gera um código de vínculo novo a cada abertura;
  - 409: integração desligada;
  - 502: iFood fora (rede, 429, 5xx, resposta incompleta).

### Código

Em `api/app/Support/Entregas/Ifood/`:

- `ClienteIfood`: única porta HTTP, com erros tipados (`ErroIfood`; rede = status 0);
- `VinculosIfood`: vínculo, tokens cifrados com `encrypt()` e renovação;
- `EventosIfood` e `PedidoDoIfood`: funções puras;
- `CriadorDoPedidoIfood`: adaptador sobre os models do Fleet-Ops.

Fora dessa pasta:

- job `App\Jobs\Entregas\ProcessarPedidoIfood`;
- comandos `entregas:ifood-polling` (a cada 30 s), `entregas:ifood-agendados` (a cada minuto) e `entregas:ifood-tokens` (a cada 30 min), em `Console/Commands/Entregas/`. No `Kernel`, os três rodam em segundo plano, com `withoutOverlapping`.

### Fluxo do pedido

1. **Polling** (`entregas:ifood-polling`):
   - agrupa as lojas vinculadas pelo token (até 100 por chamada) e chama com `excludeHeartbeat=true`;
   - grava em `entregas_ifood_eventos` (o `evento_id` único descarta os repetidos) e **só depois** manda o ack. Banco fora = sem ack, e o iFood reenvia;
   - enfileira um `ProcessarPedidoIfood` por pedido com evento pendente.
2. **Job** (trava `entregas:ifood-pedido:<id do iFood>`): processa os pendentes em ordem de `createdAt`.
   - O pedido nasce no **PLC** ou, se o PLC se perdeu, num evento anterior à coleta (CFM, RTP, DDCR, DPCR).
   - **Não nasce** se o pedido tem CAN ou RDS (entrega parceira) ou já passou da coleta (DSP, CON, CLT, DDD, AAD, DDCS, GTO, ADR, AAO), procurando entre todos os eventos gravados dele. Também não nasce só com eventos de entrega, cancelamento ou alteração.
   - **Retirada na loja ou consumo no local não nasce** (2026-10-25, caso real #5830 do Terraço, que virou corrida "[SEM LOCALIZAÇÃO]" à espera de motoboy): `orderType` diferente de `DELIVERY` (TAKEOUT, DINE_IN, INDOOR; o TAKEOUT vem sem o bloco `delivery`) é descartado depois do GET (`PedidoDoIfood::semEntrega`), com os eventos marcados como ignorados e o log info `pedido sem entrega (retirada ou no local)`. Sem `orderType`, vale como entrega.
3. **Criação** (`GET logistics/orders/{id}`), numa transação só:
   - Place da entrega com as coordenadas do iFood, sem dono;
   - Payload com a coleta no Local da loja;
   - Order: cliente = Vendor da loja, tipo `transport`, `internal_id` = número do iFood, notas "iFood #4821" e o prazo de entrega no `meta` (`entregas_prazo_em`, `PrazoDaEntrega::doIfood`: imediato = `deliveryDateTime`; agendado = fim da janela ou início + 30 min; sem data legível, 50 min depois da criação; ver "Prazo de entrega (cronômetro)" em `api/CLAUDE.md`);
   - a linha de `entregas_ifood_pedidos`. O `pedido_ifood_id` único garante que nunca nascem dois pedidos.
4. **Despacho como o do portal:** adhoc + `firstDispatchWithActivity`, com a `TravaDoPedido` e o pedido relido.

- **Pedido de teste (`isTest`):** "[TESTE]" nas notas e a entrega ~1 km ao norte da loja (o iFood manda 0,0). Despacha como o real: imediato na hora, agendado com janela 40 min antes, e o `entregas:ifood-agendados` o recupera se o despacho falhar.
- **Sem despacho**: nasce com adhoc falso e a central atribui. Cada caso deixa uma marca nas notas:
  - pedido real sem coordenadas válidas (ausentes, 0, fora da faixa ou a mais de 50 km da coleta): "[SEM LOCALIZAÇÃO]", com o mesmo deslocamento e um warning no log;
  - agendado sem janela legível: "[AGENDADO SEM HORÁRIO]", com o log `agendado sem janela`.
- **Agendado:** `scheduled_at` = `despachar_em` = início da janela − 40 min. Se faltar menos que isso, despacha na hora.
  - Quem despacha é o `entregas:ifood-agendados` ou o `fleetops:dispatch-orders`, o que vier primeiro.
  - Se a central atribuiu um motoboy antes do horário, o agendador desliga o adhoc e despacha só para ele.
- **`entregas:ifood-agendados`:**
  - refaz o despacho imediato que falhou no job (com 1 min de folga);
  - pedido encerrado, aceito ou apagado sai da fila;
  - 30 min depois do `despachar_em` sem conseguir, desiste: tira da fila, grava o log `despacho desistiu` e toca no console o aviso sonoro "sem motoboy" (`entregas.pedido_sem_motoboy`, uma tentativa).
- **Outros eventos:**
  - DDCR marca `exige_codigo`;
  - CAN cancela o pedido no Entregas (etapa 3, `CancelamentoPeloIfood`; ver "Integração iFood (etapa 3: ciclo da entrega)") e tira a linha da fila do agendador (`despachar_em` nulo);
  - RDS (`REQUEST_DRIVER_SUCCESS`: a loja pediu o entregador parceiro do iFood e ele foi aprovado) faz o mesmo que o CAN (ver "Entrega parceira" em "Cancelamento"). RDR, RDF, DCRA e DCRR só ficam registrados;
  - os outros códigos conhecidos só ficam registrados;
  - código desconhecido fica como ignorado, com warning no HSD (exige resposta da loja no iFood) e info nos outros.
- **Cobrança:**
  - sem `payments` = pago online;
  - o `payments.pending` explícito manda (0 = nada a cobrar). Sem ele, vale a soma dos métodos não pagos;
  - a forma vem do método não pago; o troco só vale para dinheiro;
  - formato divergente ou valor implausível (acima de R$ 100 mil) vai para o log `pagamento inconsistente`;
  - o formato com cobrança na porta ainda é para conferir na homologação.

### Produtos do pedido (2026-10-19)

Spec `docs/superpowers/specs/2026-10-19-produtos-do-pedido-servidor-design.md`; plano `docs/superpowers/plans/2026-10-19-produtos-do-pedido-servidor.md`. É a parte de servidor da tela "Comanda" do app.

- **Origem:** `items[{index, name, quantity, options[{name, quantity}]}]` do `GET logistics/orders/{id}` (formato da sonda; a tabela da documentação, sem `name`, é de outra resposta). `PedidoDoIfood::itens` ordena pelo `index` (sem `index`, no fim), converte para o formato do app e o `ItensDoPedido` normaliza: `[{quantidade, nome, opcoes?: [{quantidade, nome}], observacao?}]`, até 50 itens e 20 opções, nome cortado em 120 e observação em 200, quantidade inválida vale 1, item sem `name` não entra. O Logistics não traz observação por item (só se vier `observations`).
- **Gravação:** coluna `itens` (JSON, nula) da `entregas_ifood_pedidos`, na `linha` do `mapear`, gravada pelo criador na mesma transação. Pedidos antigos ficam nulos (sem preenchimento retroativo).
- **App:** `itens` no `GET v1/entregas/motoboy/pedidos/{id}/ifood` para qualquer motoboy que pode ler a rota, inclusive com o pedido aberto. JSON quebrado na coluna = `null` e o warning `[entregas] itens do pedido ilegíveis no banco` (só o `public_id`; `entregas_application`), sem derrubar a resposta. O console e o portal não mostram os itens.
- **Fora (risco aceito):** o ORDER_PATCHED (EDIT_ITEMS) não atualiza os itens: o pedido editado depois de criado mostra os da criação.
- **A conferir no primeiro pedido de teste:** se o `name` vem preenchido nos `items` do pedido real. Sem ele, `itens` fica nulo e o bloco some no app.
- **Janela da migration (deploy):** o `atualizar.sh api` reinicia application, queue, queue-ifood e scheduler antes do `deploy.sh` (migrations). Nesses segundos o insert em `entregas_ifood_pedidos` (e em `entregas_integracao_pedidos`) falha com "Unknown column 'itens'": o job do iFood tenta de novo pelo backoff e a `entregas-tick` do RestaurantePro reenvia. Faça o deploy fora do pico e confira a `failed_jobs` depois.
- Testes: `scripts/teste-php/itens-do-pedido.php`, os casos novos de `ifood-pedido.php`, `ifood-criador.php`, `ifood-conclusao.php` e `ifood-migrations.php`.

### Tabelas

As migrations ficam em `api/database/migrations`.

- `entregas_ifood_lojas`: o vínculo. Tokens cifrados (APP_KEY); `situacao` = `vinculada` | `vinculo_perdido` | `desvinculada`.
- `entregas_ifood_eventos`: um registro por evento. Uma vez por dia, a limpeza apaga os processados há mais de 7 dias e os pendentes há mais de 30.
- `entregas_ifood_pedidos`: dados do iFood de cada pedido (0800, cobrança, observações, `exige_codigo`, `cancelado_pelo_ifood_em`, `entrega_parceira_em`, `teste`, `agendado`, `despachar_em`, `despachado_em`, `itens`). Fica fora do `meta` do Order de propósito (o `meta` sai na API v1 e no socket). A exceção é o prazo de entrega (`entregas_prazo_em`, 2026-10-20), que não é sensível. `itens` (JSON, migration `2026_10_19_120000_add_itens_*`): ver "Produtos do pedido".
- Datas em TIMESTAMP, gravadas como texto no fuso do app (`PedidoDoIfood::paraOBanco`, `EventosIfood::paraOBanco`): as contas com o iFood (ISO com `Z`) são feitas em UTC e só a saída é convertida. Ver "Fuso (horário de Brasília)" em `api/CLAUDE.md`.

### Tokens e erros

- **Renovação**, com trava por loja e compare-and-set:
  - antes de usar, quando o token vence em menos de 5 min;
  - pelo `entregas:ifood-tokens`, que renova os que vencem em menos de 1 h;
  - no 401: renova e repete uma vez.
- **Refresh recusado pelo `/oauth/token` (400 ou 401)** = `vinculo_perdido`:
  - grava o log `vínculo perdido`, apaga o access token e **mantém o refresh cifrado** (para reativar);
  - um 401 ali pode ser credencial errada no `stack.env`, e não o dono revogando;
  - os outros erros da renovação (403, 404, 408, 409, 429, 5xx, rede, trava ocupada) não derrubam a loja.
- **Token ilegível** (APP_KEY trocado ou texto corrompido): a loja vai para `vinculo_perdido` com o texto cifrado mantido, e sai o log `token ilegível (APP_KEY mudou?)`.
- **429** (polling ou ack): pausa todas as lojas do token pelo `Retry-After` (até 300 s; sem cabeçalho, 60 s). A pausa fica no cache `entregas:ifood-polling-pausa:<id do vínculo>`. No job, o 429 devolve o pedido para a fila pelo `Retry-After`.
- **403 no polling:** as lojas do lote listadas em `unauthorizedMerchants` viram vínculo perdido. Sem lista legível, o lote fica 5 min em pausa.
- **Limites da rodada:**
  - nenhum lote novo depois de 25 s;
  - para depois de 3 falhas temporárias seguidas;
  - o cursor `entregas:ifood-polling-cursor` alterna o ponto de partida.
- **Job:**
  - prazo de 30 min (`retryUntil`), até 6 exceções, espera de 15 s a 5 min;
  - 404, 5xx e rede tentam de novo;
  - 400, 403 ou 401 depois da renovação, no GET do pedido, falham na hora (os eventos ficam pendentes para a varredura);
  - ao desistir, grava o log `pedido não processado`.
- **Varredura** (em toda rodada do polling):
  - o pedido com evento pendente gravado entre 2 min e 6 h atrás volta para a fila (até 50 por rodada; marca de 35 min, depois 2 h e 4 h);
  - com mais de 6 h, só um warning por pedido (`evento pendente há mais de 6 h`), para a central conferir.
- **Reativar lojas caídas por credencial errada ou APP_KEY revertido:**
  1. corrija o `stack.env` e faça o Update the stack;
  2. rode `UPDATE entregas_ifood_lojas SET situacao='vinculada' WHERE situacao='vinculo_perdido' AND refresh_token IS NOT NULL;` no banco (`docker exec -it $(docker ps -q -f name=entregas_database) mysql -uroot -p fleetbase`).
  - Sem access token, a renovação usa o refresh. Refresh vencido ou revogado derruba a loja de novo, e aí só um vínculo novo resolve.

### Logs

Todos com o prefixo `[entregas] ifood:` e só com ids, códigos e o número do pedido.

- **Comandos** (polling, agendados, tokens): `docker service logs entregas_scheduler 2>&1 | grep '\[entregas\] ifood'`. Eles rodam em segundo plano e escrevem em `/proc/1/fd/1` (`Kernel::SAIDA_DO_CONTAINER`).
- **Job** (criação, eventos, `coleta diverge`, `pagamento inconsistente`): `docker service logs entregas_queue-ifood` (a fila própria do iFood; o `entregas_queue` também pega jobs iFood quando está livre, então confira os dois).
- **Vínculo pela tela** (`vínculo falhou`, `loja vinculada`): `docker service logs entregas_application`.

### Armadilhas

- **Trava do `withoutOverlapping`:** se o processo morrer segurando a trava, ela segura o comando por até 5 min (polling e agendados) ou 10 min (tokens).
  - `cache:clear` não solta a trava, porque as travas ficam na conexão Redis `default` (`lock_connection` do store `redis`).
  - Espere vencer, ou rode `php artisan schedule:clear-cache` no scheduler.
- **`appendOutputTo('/proc/1/fd/1')` depende de um PID 1 rodando como root com a saída do container** (o init do Docker, `init: true` no `scheduler`, com o go-crond embaixo). Sem isso, o redirecionamento falha, e o comando pode nem rodar.
- **Zumbis no scheduler** (2026-10-08): sem o `init: true`, o go-crond era o PID 1 e não recolhia os comandos em segundo plano do `schedule:run`; acumulavam ~6 zumbis por minuto até o container reiniciar. Conferir: `ps -eo ppid=,stat= | awk '$2 ~ /^Z/ {print $1}' | sort | uniq -c`.
- **Redis sem persistência** (`--save "" --appendonly no`): um restart perde os jobs da fila. Os eventos continuam pendentes no MySQL, e a varredura enfileira de novo depois de 2 min.
- **Fila própria do iFood** (2026-10-06, `App\Support\Entregas\Ifood\FilaIfood`): o `ProcessarPedidoIfood` e o `EnviarAcaoIfood` vão para a fila `ifood`, atendida pelo serviço `queue-ifood` (`queue:work --queue=ifood`). O `queue` atende `default,ifood`: push e notificações primeiro, iFood só quando está livre (e assim nada para se o `queue-ifood` não existir). Teste: `scripts/teste-php/fila-ifood.php`.
  - **`after_commit` na conexão `redis`** (`api/config/queue.php`): job ou broadcast disparado dentro de uma transação só entra na fila depois do commit (e some se ela desfizer). Sem isso, com dois workers, o broadcast do pedido criado pelo `CriadorDoPedidoIfood` (o `Order::create` o põe na fila dentro da transação) podia ser lido antes do commit.
  - O serviço novo só existe depois de colar o `deploy/docker-stack.yml` no Portainer (Stacks → entregas → Editor → Update the stack, "Re-pull image" desligado). O `atualizar.sh` avisa quando ele não existe.
- **A conferir no primeiro vínculo real:** o nome do campo do refresh token (tratamos `refreshToken` e `refresh_token`). Sem ele, o log `resposta do token sem refresh token` lista os campos que vieram, e o vínculo cai quando o token vencer (6 h).
- **A conferir no primeiro agendado:** o formato do `schedule`, inclusive o `deliveryDateTimeEnd` (o fim da janela é o prazo de entrega; sem ele, vale o início + 30 min).

### Riscos que ficam

- **Agendado com motoboy atribuído pela central antes do horário:** o `fleetops:dispatch-orders` roda no mesmo minuto e pode despachar antes, com o adhoc ainda ligado.
  - Aí todos os motoboys livres do raio recebem o aviso, e qualquer um pode tomar o pedido.
  - Ao atribuir um agendado, desligue o "pedido aberto".
- **Pedido sem coordenadas ou agendado sem janela** fica `created`, sem aviso à central além das notas e do log.
- **Pedido que entra já depois da coleta** (integração parada) não é criado; fica só o warning `pedido já passou da coleta`.

### Ao atualizar o Laravel, o core-api ou o fleetops-api, confira

- no agendador: `everyThirtySeconds` (Laravel 10.15+), `runInBackground`, `when` e `appendOutputTo`;
- no Order, os métodos que o `CriadorDoPedidoIfood` usa: `firstDispatchWithActivity`, `insertDispatchActivity`, `hasDispatchedStatus`, `saveQuietly` e `fresh`;
- `OrderConfig::default()` e o `TrackingNumberObserver`, que leem a empresa da sessão;
- `Payload::setPickup`/`setDropoff`/`setCurrentWaypoint` e `Utils::getMutationType`;
- o `HandleOrderDispatched`: com adhoc, avisa todos os motoboys livres do raio; sem adhoc e com motoboy, só o atribuído;
- a janela de ±1 min do `fleetops:dispatch-orders`;
- o `handle()` dos comandos agendados trocados por causa do fuso (ver "Fuso (horário de Brasília)" em `api/CLAUDE.md`).

### Testes

- `scripts/teste-php/ifood-*.php` (php-wasm):
  - stubs `stubs-ifood.php` e `stubs-ifood-fleetbase.php`;
  - o banco em memória conhece as colunas das migrations e recusa coluna inexistente (`ifood-stub-banco.php`);
  - pedidos fictícios em `fixtures-ifood.php`.
- Funções da tela Lojas: `scripts/teste-portal/vinculo-ifood.test.mjs`.

### Homologação

Decisão de 2026-10-08. O iFood homologa por vídeos com a loja de teste e encerra o chamado se um critério falhar. Roteiro de ensaio e gravação: `docs/ifood/homologacao-roteiro.md`.

- **Modo homologação** (`ENTREGAS_IFOOD_HOMOLOGACAO=1` no `stack.env`, `services.ifood.homologacao`, desligado por padrão; está no `x-api-env`):
  - cada chamada ao iFood sai no log `[entregas] ifood: chamada` (operação, status, ms e só ids);
  - o polling com eventos registra `eventos recebidos` (quantidade e códigos);
  - a renovação do token registra `token renovado` com o motivo `vencimento` ou `401`. Esta linha sai sempre, mesmo com o modo desligado.
- **Comando `entregas:ifood-homologacao`** (`HomologacaoIfood`, no container da API), só com o modo ligado (a ação `situacao` funciona sempre):
  - `situacao`;
  - `token-vencido`: marca o `expira_em` no passado;
  - `token-invalido`: grava um access token inválido, e o 401 vem do iFood de verdade;
  - `simular --operacao=… --status=… [--espera=…]`: erro simulado uma vez, por até 10 min, no `ClienteIfood::enviar`, sem ir ao iFood;
  - `limpar`.
  - O token e o refresh nunca são simulados: um refresh recusado derruba o vínculo.
  - O 400 numa ação para a sequência daquele pedido: use num pedido separado.
- Teste: `scripts/teste-php/ifood-homologacao.php`.
- **Limpeza antes da homologação:** `deploy/limpeza/` (inventário, revisão com ROLLBACK, backup e execução). Apaga todos os pedidos, as conversas de teste, as lojas do teste de isolamento e os locais sem uso. Mantém o Terraço Pizza Bar e o vínculo iFood dele.

## Integração iFood (etapa 3: ciclo da entrega)

Desenho: spec, seções 3 e 4. Plano: `docs/superpowers/plans/2026-10-06-ifood-etapa-3-ciclo.md` (executado com ajustes de revisão: **o código é a referência**). Tudo em `api/app` (nada em `packages/*/server` chega à produção).

### Ações de logística

- O servidor informa ao iFood cada etapa: motoboy definido → `assignDriver` (nome e telefone do cadastro, só dígitos, sem o 55, `MOTORCYCLE`); `started` → `goingToOrigin`; a até 100 m da coleta pelo GPS → `arrivedAtOrigin`; `enroute` ("A caminho") → `dispatch`; a até 100 m da entrega pelo GPS (ou a conclusão) → `arrivedAtDestination`.
- **Gancho:** `Order::updated` (Eloquent, só com mudança em `driver_assigned_uuid`, `started` ou `status`) e `OrderDriverAssigned` (atribuição em lote), registrados no `AppServiceProvider::acompanharPedidosIfood` → `ObservadorDosPedidosIfood` → job `EnviarAcaoIfood` (`afterCommit`). O observador nunca lança. O `scheduleOrder` do console (`saveQuietly`) e jobs perdidos ficam com a reconciliação.
- **Um job por pedido de cada vez:** `EnviarAcaoIfood::enfileirar` põe a marca `entregas:ifood-acao-pendente:<uuid>` no cache (120 s; volta junto com o `release()` e some no `failed()`); o job apaga a marca antes de ler o pedido.
- **`AcoesIfood::sincronizar`** (`SequenciaIfood`, funções puras; trava `entregas:ifood-acao:<uuid>`, 90 s): o job **não recebe a ação**. Relê o pedido, calcula o alvo pelo estado e envia, em ordem, o que falta depois da `ultima_acao` (a única fonte do estado: o iFood não devolve evento das nossas ações). Cada ação aceita grava a `ultima_acao` na hora.
  - **Orçamento de 45 s** no laço do job (20 s nas rotas síncronas `concluir-ifood`/`codigo-ifood`, `ConclusaoIfood::ORCAMENTO_NA_ROTA`): o resto volta como `incompleto` (o job volta para a fila em 1 s; a rota responde "tente de novo").
  - **409 numa ação da sequência = já aceita** (reenvio depois de resposta ou gravação perdida): avança a `ultima_acao` e registra `[entregas] ifood: ação já aceita pelo iFood (409)` (info), sem recusa nem aviso. Um 409 por ordem errada também avança.
  - **Troca de motoboy** (`motoboy_no_ifood` diferente do atribuído): `assignDriver` de novo. A documentação não diz se o iFood aceita depois do `goingToOrigin`. O 409 da troca é recusa com aviso à central **uma vez só**: o motoboy novo fica como marca (o iFood continua com o anterior) e o `dispatch` e o `arrivedAtDestination` seguem. Outra recusa na troca (400, vínculo, cadastro) para a sequência.
  - **Recusa** (4xx que não seja o 409 da sequência, vínculo perdido, motoboy sem nome ou telefone): grava `recusa_acao`/`recusa_status`/`recusa_em`, log `[entregas] ifood: ação recusada` (ação, status e só `errorType`/`code`/`description`, nunca o corpo cru; no `assignDriver`, nome, telefone e sequências de 6+ dígitos saem mascarados) e aviso no console (`entregas.ifood_acao_recusada`, `IfoodAcaoRecusada`). Uma recusa não se repete sozinha: a próxima mudança do pedido tenta de novo, e uma ação aceita limpa a recusa.
  - **Falha temporária** (429, 408, 5xx, rede): 429 volta pelo `Retry-After`; o resto, até 5 tentativas (10 s a 5 min) em 30 min. Esgotadas, `failed()` registra `ação não enviada; tentativas esgotadas` e a próxima ação vira recusa com aviso (`AcoesIfood::desistir`, trava com 2 s de espera; sai sem gravar se já há recusa).
  - O corpo do `assignDriver` leva `#[\SensitiveParameter]` (fora do stack trace); ação sem corpo vai sem corpo.
- **`entregas:ifood-acompanhar`** (30 s, `withoutOverlapping(5)`, em segundo plano): chegada pelo GPS (`ChegadaPeloGps`, `drivers.location` × coleta/entrega, raio de 100 m) e reconciliação (estado além da `ultima_acao`, ou motoboy trocado).
  - O filtro é no SQL, antes do limite de 300 por rodada: join com `orders`, sem cancelado pelo iFood, sem recusa, sem `arrivedAtDestination`, Order não encerrado (exceto o concluído) e não apagado.
  - Janela de 24 h pelo `despachado_em` ou pelo `created_at` (o agendado criado há mais de 24 h entra pelo despacho). Migration `2026_10_06_130000_add_indice_acompanhar_*` (índice em `created_at`).
  - **Posição do motoboy com mais de 5 min** (`drivers.updated_at`: app fechado, sem sinal) não vale como chegada.

### Cancelamento

- **CAN** (`CancelamentoPeloIfood`, chamado pelo `ProcessarPedidoIfood`, com a `TravaDoPedido` e a trava das ações): concluído → nada muda (log); nos outros, sai dos abertos e do agendamento (`saveQuietly`: `dispatched` e `adhoc` falsos, `scheduled_at` nulo) e `Order::cancel()` (atividade, `OrderCanceled` → push ao motoboy atribuído, "Pedido #4821 cancelado pelo iFood"). Sem Order: só grava e registra (info). Idempotente: o job repete enquanto o CAN estiver pendente (trava ocupada).
- **`pago_mesmo_cancelado`** quando o `dispatch` já foi aceito (`ultima_acao`) **ou** o Order está `enroute` (o motoboy tocou "A caminho", mesmo que o `dispatch` não tenha chegado ao iFood). O push acrescenta "Você recebe por esta entrega. Combine com a loja a devolução." e o pedido entra no relatório, na cobrança da loja e nos ganhos do app **pela data do cancelamento** (`CalculoEntregas::pedidosConcluidos`, `cancelado_pago` na linha).
  - O extrato do portal da loja repassa o `cancelado_pago` (`PortalLojaController::extrato`) e mostra o selo "Cancelado pelo iFood (cobrado)" na linha (coluna Situação) e uma coluna com o mesmo rótulo no CSV (vazia nas demais).
- **Entrega parceira** (2026-10-25; caso real: #4047 do Terraço, que recebeu RDR + RDS às 18:16 e um motoboy nosso aceitou às 18:23, porque os dois eram códigos desconhecidos e ficaram ignorados): o RDS chama o `CancelamentoPeloIfood` com `$entregaParceira`. O pedido sai do Entregas como no CAN (mesmas travas, mesma regra do `pago_mesmo_cancelado`, push ao motoboy atribuído) e a linha ganha o `cancelado_pelo_ifood_em` (que para as ações, o agendador e o acompanhamento, e libera o cancelamento pela central) e o `entrega_parceira_em` (migration `2026_10_25_190000_add_entrega_parceira_*`). O push diz "Pedido #4047 passou para o entregador do iFood"; o log é `pedido saiu para a entrega parceira do iFood` (warning). O RDR (pedido feito) e o RDF (negado) não mexem no pedido.
  - **Risco aceito:** se a loja cancelar o entregador do iFood depois do RDS (DCR → DCRA), o pedido não volta sozinho; a central cria outro. O app ainda mostra o aviso "cancelado pelo iFood" nos detalhes (o `DadosIfoodDoMotoboy` não traz o motivo).
- Exceção no cancelamento sobe como `RuntimeException` só com a classe e o SQLSTATE (o `failed_jobs` guarda a exceção inteira, e a do banco traz o SQL); `LockTimeoutException` passa como veio.
- **Cancelamento do nosso lado é proibido** no pedido iFood (400 "Pedido do iFood: o cancelamento é feito no iFood."): `RegrasPortalLoja` (portal) e `RegrasDoPedidoIfood` (API v1 e console):
  - API v1: `cancelOrder`, a atividade "canceled" no `updateActivity` e o `PUT v1/orders/{id}` (`update`) com `status` de cancelamento;
  - console: `cancel`, `bulkCancel`, a atividade "canceled" (inclusive o arrastar do quadro) e o `PUT/PATCH int/v1/orders/{id}` (`updateRecord`) com `order.status` de cancelamento.
  - Com problema do motoboy, a central troca o motoboy.
  - **Saída da central:** o cancelamento pelo console e pela API v1 passa quando a linha já tem `cancelado_pelo_ifood_em` (o iFood já cancelou: CAN que não cancelou o Order, ou cancelamento local que falhou) **ou** com a integração desligada (`ENTREGAS_IFOOD=` vazio, desligamento de emergência). Fica no log `cancelamento liberado no pedido do iFood`. O portal da loja continua barrado sempre.
  - O `RegrasDoPedidoIfood` só consulta o pedido quando a ação pode ser barrada (cancelamento, ou conclusão com a trava ligada): a `update-activity` comum do app passa sem ir ao banco.

### Conclusão e código de entrega

- App (APK da etapa 4): `POST v1/entregas/motoboy/pedidos/{id}/concluir-ifood` (`ConclusaoIfood::concluir`) garante o `arrivedAtDestination` na hora. Recusa dessas ações não prende o motoboy (a central já foi avisada). Com `exige_codigo` (DDCR) responde `precisa_codigo`; senão libera. iFood fora do ar, trava ocupada ou ação por enviar: 503 "tente de novo".
- `POST .../codigo-ifood` (3 a 10 dígitos) confere o código na hora (`verifyDeliveryCode`): 400/422 cuja `description`/`message`/`code` diz código inválido ("Confirmation code is invalid", "Invalid delivery code", "code does not match") ou `success: false` num 2xx = `codigo_incorreto` (HTTP 422, conta para o teto); outro 4xx (inclusive 409 e 412) = 409 `codigo_nao_conferido` ("Não foi possível conferir o código neste pedido. Peça à central para liberar.", não conta para o teto); 408, 429, 5xx e rede = 503 "tente de novo". O log leva só `errorType`/`code`/`description` (`AcoesIfood::resumoDoErro`), com o código digitado mascarado. Certo (ou sem código exigido) grava `conclusao_liberada_em` e o app conclui pelo `update-activity` de sempre.
  - Com a trava das ações, a linha é relida antes do `verifyDeliveryCode`: já liberada (dois cliques, ou a central liberou) → `pode_concluir` sem chamar o iFood.
  - **Teto de 10 códigos errados por pedido** (contador no cache `entregas:ifood-codigo-erros:<uuid>`, 3 dias; iFood fora do ar não conta): depois dele, 429 `{"resultado": "muitas_tentativas"}` ("Muitas tentativas: peça à central para liberar.") sem chamar o iFood. Vale além do limitador `entregas-ifood-codigo` (10 por minuto por motoboy).
- **Pedido de teste também pede o código** (o Gestor de Pedidos pediu no teste): segue o `exige_codigo`. De onde tirar o código de um pedido de teste fica a conferir. Sem ele, a central usa **"Liberar sem código"** no painel iFood do console (`POST int/v1/entregas/pedidos/{id}/ifood/liberar-sem-codigo`, só admin): `conclusao_sem_codigo`, log `[entregas] ifood: conclusão sem código liberada pela central`; o iFood conclui sozinho 4 h depois. Já liberado, não grava nem registra de novo. Concluir pelo console um pedido que exigia código também marca `conclusao_sem_codigo` (warning no log).
- **Trava "Atualize o app"** (`ENTREGAS_IFOOD_EXIGE_APP_NOVO=1` no `stack.env`, **desligada por padrão**): a conclusão comum de pedido iFood pela API v1 (`updateActivity` que conclui, `completeOrder`) sem `conclusao_liberada_em` responde 400 "Atualize o app para concluir pedidos do iFood.". A conclusão pelo console passa. **Ligue só depois do APK da etapa 4 em todos os celulares**; com ela desligada, o APK antigo conclui sem código (o iFood conclui sozinho 4 h depois).
- **Não vincule loja real antes do APK da etapa 4 em todos os celulares.** Sem ele, o motoboy não vê o valor a cobrar na porta nem confere o código do cliente, e a trava não pode ser ligada. Até lá, só a loja de teste.
- `GET v1/entregas/motoboy/pedidos/{id}/ifood` (`DadosIfoodDoMotoboy`): número, cobrança (texto da `CobrancaIfood`; o troco aparece sempre que existe, inclusive com "MISTO"; `GIFT_CARD` = "vale-presente", `OTHER` = "outra forma"), observações, complemento, referência, código exigido, conclusão liberada, cancelamento; 0800 e localizador só para o motoboy do pedido, em andamento e antes de expirar; `itens` (os produtos) para qualquer motoboy que pode ler a rota, inclusive com o pedido aberto (ver "Produtos do pedido"). O alarme leva `entregas_ifood` e `entregas_cobrar`.
- Painel do console: `GET int/v1/entregas/pedidos/{id}/ifood` (só admin; `IfoodPedidosController`; ações pelo código, traduzidas no console).

### Colunas novas e logs

- `entregas_ifood_pedidos`: `ultima_acao`, `motoboy_no_ifood`, `recusa_acao`/`recusa_status`/`recusa_em`, `conclusao_liberada_em` e `conclusao_sem_codigo` (migration `2026_10_06_120000_add_ciclo_*`).
- Fila (`docker service logs entregas_queue-ifood 2>&1 | grep '\[entregas\] ifood'`; o `entregas_queue` também pega jobs iFood quando está livre): `ação enviada`, `ação já aceita pelo iFood (409)`, `ação recusada`, `ação não enviada; tentativas esgotadas`, `pedido cancelado pelo iFood`, `pedido concluído sem o código do cliente`.
- Scheduler (`entregas_scheduler`): `chegada pelo GPS`, `falha ao acompanhar o pedido`.
- Aplicação (`entregas_application`): `código de entrega conferido`/`incorreto`/`não conferido pelo iFood`, `falha ao conferir o código de entrega`, `conclusão sem código liberada pela central`, `ação barrada no pedido do iFood` (API v1, console e portal da loja; só ids nossos), `cancelamento liberado no pedido do iFood`, `conclusão sem resposta do iFood`.

### Armadilhas da etapa 3

- **O `LogApiRequests` do core grava o corpo das chamadas da API v1**, inclusive o `POST v1/entregas/motoboy/pedidos/{id}/codigo-ifood` (o código de entrega) e o token, na tabela `api_request_logs` (Developers → Logs, só admin). O código vale uma vez, mas quem tem acesso aos logs o enxerga.
- **O `internal_id` do pedido pode repetir entre pedidos** (é o número do iFood). O app e as rotas do motoboy usam o `public_id`; a busca do `RegrasDoPedidoIfood` aceita uuid, `public_id` e `internal_id`, sempre filtrada pela empresa da sessão.
- **Ação recusada para a sequência:** uma ação recusada antes (ex.: `dispatch` com 400) impede o `arrivedAtDestination` de ir na ordem. O motoboy segue para o código, e a central usa "Liberar sem código" no painel iFood.
- Ação sem corpo (ex.: `goingToOrigin`) vai com o `send('POST')` do Laravel e `Content-Type: application/json`: **a conferir na produção** (400 ou 415 → trocar por POST sem cabeçalho de corpo).
- **Fila própria do iFood** (resolvido em 2026-10-06): os jobs iFood têm o worker `queue-ifood`; ver "Armadilhas" da etapa 2.
- **A conferir (Task 15 do plano):** troca de motoboy depois do `goingToOrigin`; raio de 100 m; origem do código de um pedido de teste; formato do `workerPhone`; `fleetbase.protected` com o `RegrasDoPedidoIfood` (cancelar pelo console deve dar 400).

### Ao atualizar o fleetops-api, confira

- que todo status ainda passa por `setStatus(…, true)` → `save()` e que o `startOrder` grava `started` com `save()` (gancho `Order::updated`);
- o `OrderDriverAssigned` e o `NotifyBulkAssignedDriver`;
- os nomes das ações barradas (`Api\v1\OrderController@cancelOrder|update|updateActivity|completeOrder`, `Internal\v1\OrderController@cancel|bulkCancel|updateActivity|updateRecord`) e o corpo (`order`, `ids`, `activity.code`, `status`, `order.status`);
- que o `Order::cancel()` ainda não tira o motoboy e que o `HandleOrderCanceled` notifica o atribuído;
- (etapa 4) as ações `Internal\v1\OrderController@queryRecord` e `Internal\v1\LiveController@orders` e se o recurso `Http\Resources\v1\Index\Order` passou a trazer `notes` (aí o `IncluirNotasNaListaDePedidos` sobra; ele não sobrescreve um `notes` que já venha). Ele também põe o `entregas_prazo_em` no `meta` do recurso enxuto (cronômetro): confira se o `Index\Order` passou a trazer o `meta` completo.

### Testes da etapa 3

`scripts/teste-php/ifood-sequencia.php`, `ifood-cliente-acoes.php`, `ifood-acoes.php`, `ifood-acompanhar.php`, `ifood-cancelamento.php`, `ifood-regras.php`, `ifood-conclusao.php` e `ifood-ligacoes.php` (php-wasm), além de `ganhos-motoboy.php` e `cartao-do-alarme.php`.

### Etapa 4: APK, console e portal

- **Pedido iFood na tela = notas "iFood #N" (com ou sem marcas, ex. " [TESTE]") + `internal_id` N** (`packages/fleetops/addon/utils/pedido-ifood.js`; no portal, `numeroIfood` em `customer-portal/addon/utils/entregas-pedido.js` e o helper `numero-ifood`). Nada novo no `meta` além do prazo de entrega: o pedido do iFood agora nasce com `meta.entregas_prazo_em` (2026-10-20). O servidor confere de verdade (`RegrasDoPedidoIfood`, `RegrasPortalLoja`).
- **Notas nas listas do console:** o recurso enxuto `Index\Order` (lista `GET int/v1/orders` e `GET int/v1/fleet-ops/live/orders`) não traz `notes`. O middleware `IncluirNotasNaListaDePedidos` (`api/app`, grupo `fleetbase.protected`, depois do `$next`) acrescenta o `notes` de cada pedido numa consulta só, filtrada pela empresa. Nunca lança: em erro devolve a resposta original e registra `[entregas] notas na lista de pedidos: <classe>` (`entregas_application`). Teste: `scripts/teste-php/notas-na-lista.php`. **Sem a API nova, o selo e o Cancelar escondido só valem no detalhe.**
- **App** (APK `entregas-motoboy-<n>` do push na `main` do `entregas-navigator`):
  - card de aceitar e detalhes com o selo "iFood #4821" e a faixa amarela da cobrança (`PedidoIfood`, dados de `useDadosIfood(id, estado)`: cache de 2 min por pedido **e estado** `status|motoboy|started`, relido quando o pedido muda);
  - detalhes na folha "Comanda" (decisão de 2026-10-19): a caminho do cliente, "Ligar (0800)" (só no pedido dele; o discador digita o localizador sozinho ~6 s depois do atendimento, ver "Ligar (0800) digita o localizador sozinho" em `api/app/Http/Controllers/Entregas/CLAUDE.md`) ao lado do localizador (toque longo copia), observações em amarelo, complemento e referência sem corte; na coleta, o cupom do balcão com os produtos (`itens`) e a cobrança numa linha (sem 0800 nem localizador); o aviso do cancelamento pelo iFood continua;
  - atividade que conclui um pedido iFood passa antes por `concluir-ifood` (overlay "Avisando o iFood…", toque repetido ignorado; sem os dados carregados, busca na hora e, se falhar, "tente de novo" sem concluir) e, com código exigido, abre o campo do código (`CodigoDeEntregaIfood` → `codigo-ifood`; o erro do servidor aparece no próprio campo). O erro do servidor no `update-activity` aparece em toast (ex.: a trava "Atualize o app");
  - cartão do alarme com o selo e a cobrança (`entregas_ifood`, `entregas_cobrar`); Meus ganhos marca "Cancelado pelo iFood: você recebe" (`cancelado_pago`);
  - o `GET v1/entregas/motoboy/pedidos/{id}/ifood` tem limitador próprio, `entregas-motoboy-ifood` (120 por minuto por motoboy), para a lista de pedidos não gastar o limite do `concluir-ifood`.
- **Console:** coluna "iFood" na tabela e selo no quadro, no mapa e no cabeçalho (`pedido-ifood/selo`); painel iFood no detalhe (`order/details/ifood`: última etapa informada, última recusa, cobrança, código, observações; "Liberar sem código"; recarrega quando o pedido, o status ou o `updated_at` mudam); Cancelar escondido (detalhe e linha da tabela) e barrado na tela (lote, mapa, arrastar para "cancelado" no quadro); aviso de ação recusada (serviço `ifood-acao-recusada`: som e notificação fixa que abre o pedido; texto próprio quando o status é 0, isto é, não chegou ao iFood; iniciado na rota raiz do engine, como o `pedido-sem-motoboy`); "Pagamento e cobrança" marca o cancelado pelo iFood (pago), também no CSV.
- **Portal:** selo na lista, na tabela e no cabeçalho; Cancelar desabilitado com o aviso "o cancelamento é feito no Gestor de Pedidos do iFood"; o extrato mostra a marca "Cancelado pelo iFood (cobrado)" na linha e no CSV.
- **Ordem de implantação:** API (`bash deploy/atualizar.sh api`: middleware das notas e limitador) e console (`bash deploy/atualizar.sh console`) → APK novo em todos os celulares → `ENTREGAS_IFOOD_EXIGE_APP_NOVO=1` → só então vincular loja real (a fila própria do iFood já existe: atualize o stack no Portainer para criar o `queue-ifood`).
- **A conferir no teste real (Task 12 do plano):** o texto do servidor no `err?.message` do SDK (422 do código, 400 "Atualize o app"); de onde vem o código de um pedido de teste; o 0800 no pedido de teste; o card do quadro voltando à coluna ao arrastar um pedido iFood para "cancelado"; o Kotlin do alarme compilando no Actions.
- Testes: `scripts/teste-portal/pedido-ifood.test.mjs`, `scripts/teste-php/notas-na-lista.php` e, no app, `scripts/testes/pedido-ifood.teste.ts`.
