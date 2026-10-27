# Loja pré-paga (carteira recarregada por Pix)

Parte do `CLAUDE.md` da raiz (índice em "Onde está cada assunto"). Responda e documente em português do Brasil.

Decisão de 2026-10-27 (pedido do Edgard): cada loja trabalha num de dois modelos, escolhidos pelo administrador do
sistema no cadastro da loja (Fleet-Ops → Recursos → Lojas, seção "Cobrança").

- **Pós-pago** (o padrão, loja sem linha em `entregas_carteiras_lojas`): como sempre, corridas → fatura → a loja paga
  (`api/app/Support/Entregas/Financeiro/CLAUDE.md`).
- **Pré-pago**: a loja recarrega a "Minha carteira" no portal com um Pix na **conta Asaas da central** (a mesma conexão do
  saque Pix, `api/app/Support/Entregas/Saques/CLAUDE.md`); o servidor confirma o Pix e o crédito entra; as corridas
  consomem o crédito; sem crédito, o portal não cria pedido novo.

Telas: console em `packages/fleetops/CLAUDE.md` (Lojas e Financeiro → Lojas e faturas); portal em
`packages/customer-portal/CLAUDE.md` ("Minha carteira").

## Como o crédito funciona (sem livro novo)

- A carteira é a **conta da loja no livro do Financeiro**: corrida = lançamento positivo (como no pós-pago), recarga paga =
  um **recebimento Pix** (`Pagamentos::registrar`, forma `pix`, sistema, observação "Recarga Pix pelo portal"). Crédito =
  −saldo (negativo = a loja deve). Extrato, painel e "Registrar recebimento" do console continuam valendo: um recebimento
  manual na loja pré-paga vira crédito.
- **Reservado** = `valor_loja` congelado (`entregas_valores_pedido`) dos pedidos da loja não encerrados e mexidos nas
  últimas 12 h (a regra do mapa) e, no pedido ainda sem valor (o valor congela na atribuição do motoboy), o preço da
  entrega mais barata. **Disponível** = crédito − reservado.
- **Bloqueio:** o `RegrasPortalLoja` (`criarComCredito`) recusa o `POST customer-portal/int/v1/orders` com 422 "Crédito
  insuficiente. Recarregue a carteira (Minha carteira) para pedir entregas." quando o disponível é menor que a entrega
  mais barata (menor `loja` da tabela de km; sem tabela, basta disponível > 0) — `CarteiraDaLoja::motivoDoBloqueio`.
  Conferência e criação rodam com a trava `entregas:carteira:<loja>` (Redis, 30 s, espera 10 s; ocupada → 409): o pedido
  criado já reserva, então pedidos em sequência param quando o crédito acaba (revisão de segurança de 2026-10-27).
- **A tabela de km sai da empresa informada** (`Setting::lookup` pela chave inteira, `menorPreco($empresa)`), nunca da
  sessão: o `RegrasPortalLoja` é middleware global e roda antes de o Fleetbase montar a sessão (sem isso, o mínimo virava
  zero no pedido novo).
- **Pedidos do iFood, da integração e da central não são barrados** (decisão de projeto: recusar deixaria o cliente final
  sem a entrega). O saldo fica negativo e a próxima recarga desconta; o portal mostra "Você está devendo". Dois pedidos
  feitos no portal não passam juntos (a trava da carteira).
- **Sem fatura:** `Faturas::gerarRascunho` recusa a loja pré-paga (422 "Loja pré-paga não recebe fatura…") e a rotina
  `entregas:financeiro-rascunhos` a pula. A lista do Financeiro → Lojas traz `modelo`.
- **Trocar de modelo:** pós → pré: o que estava em aberto fica como dívida e a primeira recarga quita (as faturas já
  emitidas seguem quitáveis pelo recebido). Pré → pós: os lançamentos sem fatura do período pré-pago entram no próximo
  rascunho, e as recargas (recebimentos) quitam essa fatura pelo `Quitacao::faturas`; a primeira fatura sai grande, mas o
  "em aberto" fica certo.

## Segurança

- **Sem webhook.** Ninguém de fora avisa pagamento: quem confirma é sempre o servidor, consultando `GET /payments/{id}` com
  a chave da central (o portal enquanto o QR está na tela, no máximo a cada 5 s por recarga; a rotina a cada minuto). Não
  há rota pública nova, nem token para vazar.
- **Crédito só com a cobrança conferida** (`Recargas::confere`): mesmo `id`, mesmo valor (centavos), mesmo
  `externalReference` (`recarga:<uuid>`), `billingType` PIX e, quando os dois existem, o mesmo `customer`; status
  `RECEIVED` ou `CONFIRMED` (`RECEIVED_IN_CASH`, a baixa manual no painel do Asaas, **não** credita). Não confere →
  `Log::critical` e nada muda.
- **Uma vez só:** `creditar` trava a recarga (`lockForUpdate`) e só credita a que ainda não está paga, na mesma transação
  do recebimento; portal e rotina ao mesmo tempo creditam uma vez. `payment_id` é único.
- **Chave do Asaas:** a do saque (`entregas_asaas_conexoes.chave_cifrada`, Cofre AES-256-GCM com o Docker secret
  `entregas_saque_chave`). O `AsaasCliente` só chama https e não segue redirecionamento. A wallet da conexão é conferida
  antes de criar a cobrança e gravada na recarga; o `pedir` relê a conexão travada (`lockForUpdate`) na transação, como o
  saque, e o `desconectar` trava a mesma linha; a conferência só consulta com a conexão da mesma wallet (senão
  `Log::critical` "a conta Asaas da recarga não está mais conectada"). **Desconectar a conta Asaas é recusado** com Pix de
  recarga criando ou aguardando ("Há Pix de recarga de loja aguardando pagamento; espere ele expirar.").
- **Portal isolado:** loja sempre da sessão (`LojaDoUsuario`); recarga de outra loja, de outra empresa ou uuid malformado →
  404 "Recarga não encontrada.". A resposta nunca traz `payment_id`, `cliente_id`, wallet nem o texto do Asaas (a loja vê
  "Não foi possível gerar o Pix agora…"; o motivo do Asaas vai para o log). A imagem do QR só é gravada se for base64
  (vira `data:image/png;base64,…` no portal).
- **Limites:** recarga de R$ 100,00 a R$ 5.000,00 (`PrePago::VALOR_MINIMO/MAXIMO`; o mínimo de R$ 100 é decisão do Edgard de 2026-10-27, o primeiro teste foi de R$ 10); uma em andamento por loja (carteira
  travada na transação); até 10 por loja por dia de Brasília; `throttle:entregas-loja-recarga` (6/min por usuário e 20/min
  por IP) no pedir e no cancelar; `throttle:entregas-loja-carteira` (60/min por usuário) nas leituras. A consulta do
  portal ao Asaas é reivindicada num `update` condicional do `consultada_em` (`reivindicarConsulta`): pedidos em paralelo
  não multiplicam as chamadas (a cota da conta é a mesma do saque Pix).
- **Log** nunca leva a chave, o CPF/CNPJ nem o copia e cola; o CPF/CNPJ da loja fica só no banco (`documento`, dígitos) e no
  cliente do Asaas (`notificationDisabled`: o Asaas não manda e-mail/SMS à loja).
- **Interruptor** `ENTREGAS_RECARGA` (`services.entregas.recarga`, `PrePago::ligada`): desligado, a loja não pede Pix
  novo; o crédito, o bloqueio e a conferência dos Pix já gerados continuam (a rotina roda sempre).

## Fluxo da recarga (`Recargas`)

Situações: `criando` → `aguardando` → `paga` | `expirada` | `falhou`.

1. `pedir` (portal `POST loja/carteira/recargas {valor}`): confere chave, valor, conexão e Cofre; numa transação com a
   carteira travada (pré-pago, documento válido, nenhuma em andamento, limite do dia) grava `criando`. Depois do commit:
   `GET /wallets` (tem de ser a wallet da conexão), cliente da loja (`POST /customers` com nome, CPF/CNPJ,
   `externalReference` `loja:<uuid>`; reaproveitado enquanto wallet e documento não mudam), `POST /payments` (PIX, vence
   hoje, `externalReference` `recarga:<uuid>`) e `GET /payments/{id}/pixQrCode`.
   - Recusa clara do Asaas (400/401/403/404/422 com `errors`) ou problema antes do POST → `falhou` (o portal recebe 502).
   - POST sem resposta (rede, 5xx, 200 sem id) → fica `criando`: a cobrança pode ter nascido.
   - QR sem resposta → `aguardando` sem QR; a próxima consulta busca de novo.
2. `conferir` (portal a cada 5 s com o QR na tela; rotina a cada minuto): paga e conferida → `creditar`; apagada no Asaas →
   `expirada`; passou de 30 min (`MINUTOS_DO_PIX`) → `DELETE /payments/{id}` e `expirada` (se o Asaas não apagar, é porque
   pode ter sido paga: a próxima consulta vê e credita).
3. `criando` há mais de 2 min: `GET /payments?externalReference=…` (a cada minuto). Nenhuma: continua procurando até 10
   min (`MINUTOS_SEM_COBRANCA`, a busca do Asaas pode demorar) e só então `falhou`; resposta sem a lista `data`: espera.
   Uma → adota o id (paga → credita; senão apaga, porque ninguém viu o QR); mais de uma → `Log::critical` e fica. Id já
   de outra recarga (chave única, erro 1062) → `falhou` com `Log::critical`; outro erro do banco sobe e a rotina tenta de
   novo.
4. **Conferência tardia** (`conferirDepois`): a expirada com cobrança, 1 h depois de expirar (nos últimos 2 dias), é
   consultada **uma vez**; paga mesmo assim → credita com warning "Pix pago depois de expirar; creditado".
5. `cancelar` (portal `DELETE loja/carteira/recargas/{id}`): apaga no Asaas → `expirada` "Pix cancelado." com
   `cancelada_por`; o Asaas não apagou → confere (a paga é creditada); senão 422 "Não foi possível cancelar agora.".

## Tabelas

Migration `api/database/migrations/2026_10_27_200000_create_entregas_financeiro_prepago_tables.php` (o nome tem
`_entregas_financeiro_` por causa do glob do banco em memória dos testes).

| Tabela | O quê |
|---|---|
| `entregas_carteiras_lojas` | uma linha por loja (`company_uuid` + `loja_uuid` únicos): `modelo` (`pos`/`pre`), `documento` (dígitos), `asaas_cliente_id`/`_wallet`/`_documento`, `modelo_alterado_em/_por` |
| `entregas_recargas` | `uuid`, loja, `valor`, `situacao`, `external_reference`, `wallet_id`, `cliente_id`, `payment_id` (único), `asaas_status`, `pix_copia_cola`, `pix_imagem`, `expira_em`, `paga_em`, `pagamento_uuid`, `motivo_falha`, `consultada_em`, `conferida_depois_em`, `pedida_por`, `cancelada_por` |

Classes (`App\Support\Entregas\PrePago`): `PrePago` (constantes, `ligada`, `modelo`, `definir`, `documento` com os
dígitos verificadores de CPF/CNPJ, `lojasPrePagas`), `CarteiraDaLoja` (`resumo`, `motivoDoBloqueio`, `reservado`,
`menorPreco`), `Recargas`, `RecargaRecusada` (422). Rotina `entregas:recargas-conferir` (`RecargasConferir`, todo
minuto, sem `when`, `withoutOverlapping(5)`). Cliente do Asaas: métodos novos no `Saques\AsaasCliente` (`criarCliente`,
`criarCobrancaPix`, `qrCodePix`, `cobranca`, `cobrancasDaReferencia`, `apagarCobranca`).

## Rotas

| Quem | Método e caminho | Controller |
|---|---|---|
| Portal (loja da sessão) | GET `int/v1/entregas/loja/carteira` → `{carteira, em_andamento, recargas}` | `CarteiraDaLojaController@index` |
| Portal | POST `…/loja/carteira/recargas {valor}` → 201 `{recarga, carteira}` (422/502/503) | `@pedir` |
| Portal | GET / DELETE `…/loja/carteira/recargas/{uuid}` → `{recarga, carteira}` | `@mostrar` / `@cancelar` |
| Portal | GET `…/loja/resumo-do-dia` ganhou `carteira` (troca a aba Financeiro pela Minha carteira) | `PortalLojaController@resumoDoDia` |
| Console (admin do sistema) | POST/PUT `int/v1/entregas/lojas` aceita `cobranca {modelo, documento}`; a loja sai com `cobranca` | `LojasController` |

Formatos: `carteira` = `{modelo: 'pos'}` ou `{modelo: 'pre', credito, reservado, disponivel, minimo_para_pedir,
pode_pedir, recarga: {ligada, minimo, maximo}}`; recarga = `{id, valor, situacao, motivo, criada_em, expira_em, paga_em,
pix: {copia_e_cola, imagem} | null}` (`pix` só na aguardando).

## Implantação

1. Push e `bash deploy/atualizar.sh` (api + console; a migration roda no `deploy.sh`).
2. `ENTREGAS_RECARGA=1` no `stack.env` e o `docker-stack.yml` novo no Portainer (Update the stack, "Re-pull image"
   desligado). Precisa da conta Asaas conectada em Financeiro → Configurações (o card do saque Pix) e do secret.
3. **A conta Asaas da central precisa de chave Pix cadastrada** (sem ela, o Asaas recusa a cobrança e a loja vê "Não foi
   possível gerar o Pix agora").
4. Cadastro da loja: "Pré-pago" + CPF/CNPJ. Ensaio no sandbox (`ASAAS_API_URL=https://api-sandbox.asaas.com/v3`): o sandbox
   tem "Confirmar pagamento" na cobrança, que vira `RECEIVED`.
5. Conferir: recarga de R$ 100 numa loja de teste (o Terraço é loja real: combine antes), o crédito na carteira e no
   extrato, o bloqueio sem crédito e `node scripts/teste-isolamento-lojas.mjs`.

## A confirmar no sandbox

- Se a cobrança vencida no mesmo dia ainda aceita Pix (o QR é
  apagado aos 30 min de qualquer jeito).
- Se o `GET /payments?externalReference=` filtra mesmo (o código filtra de novo na resposta).
- **Estorno do Pix pela central ou MED** depois de creditado: não é acompanhado. Se a central devolver uma recarga no
  Asaas, estorne o recebimento no console (Financeiro → Lojas → extrato da loja), e o crédito sai.
- Taxa do Asaas por Pix recebido: fica com a central (a loja recebe o valor cheio de crédito).
- **Pendência:** um alerta periódico das recargas pagas que viraram `REFUNDED`/chargeback no Asaas (hoje não há).

## Recarga parada (resolver à mão)

Uma recarga fica em andamento para sempre quando a chave do Asaas é revogada (401), quando a cobrança paga não confere
(log crítico "não confere") ou com duas cobranças com o mesmo `externalReference`. Ela segura novas recargas da loja e o
desconectar. Confira a cobrança no painel do Asaas da central (busca por `recarga:<uuid>`):

- **Paga e é desta loja:** registre o recebimento à mão no console (Financeiro → Lojas → Registrar recebimento, Pix) e,
  na VPS, `UPDATE entregas_recargas SET situacao = 'paga', paga_em = NOW(), updated_at = NOW() WHERE uuid = '<uuid>' AND
  situacao IN ('criando', 'aguardando');`.
- **Não paga:** apague a cobrança no Asaas e, na VPS, `UPDATE entregas_recargas SET situacao = 'expirada', motivo_falha =
  'O Pix expirou sem pagamento.', pix_copia_cola = NULL, pix_imagem = NULL, updated_at = NOW() WHERE uuid = '<uuid>' AND
  situacao IN ('criando', 'aguardando');`.
- **Paga mas não confere** (valor, referência): investigação (chave de API vazada?), não SQL. Troque a chave do Asaas antes.

## Testes

`PHP_WASM_DIR=/c/tmp/php-wasm node scripts/teste-php/rodar.mjs scripts/teste-php/<teste>.php`: `prepago-recargas.php`
(pedir, QR, conferir, defesas, expirar, tardia, procurar, cancelar, desconectar, rotina) e `prepago-loja.php` (CPF/CNPJ,
modelo, carteira, bloqueio, faturas, controller do portal com isolamento, cadastro, rotas e implantação); também
`financeiro-lojas.php` (modelo na lista) e `ifood-agendador.php` (agenda). O Http falso ganhou `delete`.

## Ao atualizar o Laravel, o core-api ou o fleetops-api, confira

- `Illuminate\Http\Client` com `delete()` (o `AsaasCliente` usa para apagar a cobrança).
- `orders.customer_uuid`, `orders.status` e `orders.deleted_at` (reservado) e o `RegrasPortalLoja` ainda vendo o
  `POST customer-portal/int/v1/orders`.
- Os mesmos itens do saque Pix (`api/app/Support/Entregas/Saques/CLAUDE.md`).
