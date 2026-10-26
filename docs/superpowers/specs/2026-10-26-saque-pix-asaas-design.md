# Saque Pix do motoboy pelo app (Asaas)

Decisão do Edgard de 2026-10-26. O motoboy pede, na "Minha carteira" do app, o saque do saldo dele, e o dinheiro cai
direto no Pix dele (a chave cadastrada pelo admin), saindo da conta Asaas da central. Opcional por central: só funciona
na central que conectou a conta Asaas e ligou o saque.

Modelo: o pagamento de freelancers do RestaurantePro (`restaurantepro-main`: edge `supabase/functions/freelancer-payout`,
migrations `20260709*`, spec `docs/superpowers/specs/2026-07-05-freelancer-pix-payout-design.md`). Ficam de lá: a chave
colada pelo cliente e guardada cifrada numa tabela própria, a chave Pix e o valor sempre lidos do banco, o claim atômico,
o `externalReference` estável e a consulta ao Asaas antes de dar algo por falho. Muda aqui: quem dispara é o motoboy (lá
é o gerente), então entram limites, carência na troca da chave Pix e a validação de saque do Asaas, obrigatória.

## Como é hoje

- O Financeiro (`api/app/Support/Entregas/Financeiro/CLAUDE.md`) é **só registro**: o admin paga o motoboy pelo app do
  banco e registra o pagamento (`entregas_pagamentos` + lançamento `pagamento` negativo). O saldo do motoboy é a soma
  dos lançamentos.
- A chave Pix do motoboy (`entregas_motoboys_financeiro`: `chave_pix`, `tipo_chave` em `cpf`, `cnpj`, `telefone`,
  `email`, `aleatoria`) é cadastrada pelo admin no extrato do console. O motoboy só vê.
- O app mostra o saldo na "Minha carteira" (`GET v1/entregas/motoboy/carteira`, `Carteira::doMotoboy`;
  `FolhaDaCarteira.tsx` no `entregas-navigator`).

## Decisões

| Assunto | Decisão |
|---|---|
| De onde sai o dinheiro | Da conta Asaas **da central** (a organização que paga os motoboys). Conta comum ou subconta; recomendada conta própria, só para isso |
| Quanto pode sacar | O saldo, com limites que a central configura (mínimo por saque, máximo por dia, saques por dia). Sem carência das corridas |
| Tarifa do Asaas | A central configura uma taxa fixa por saque (padrão R$ 0), debitada do saldo do motoboy à parte |
| Validação de saque do Asaas | **Obrigatória** para ligar o saque |
| Quem saca | Todo motoboy com chave Pix, menos os bloqueados pelo admin |
| Quem configura no console | Admin do sistema e admin da organização (papel Administrador); conectar, trocar e desconectar pedem a senha |
| Confirmação no app | Tela de confirmação + desbloqueio do aparelho (digital, rosto ou PIN). Celular sem bloqueio de tela não saca |
| Chave que cifra | Docker secret `entregas_saque_chave` (fora do MySQL, fora do `stack.env` e do Portainer) |

## Segurança

### Onde fica cada segredo

| O quê | Onde | Como |
|---|---|---|
| Chave de API do Asaas da central | MySQL do stack (`entregas_asaas_conexoes`) | Cifrada (AES-256-GCM, `Illuminate\Encryption\Encrypter` com a chave do secret) |
| Token da validação de saque | MySQL do stack | Só o hash (SHA-256). Mostrado uma vez na geração |
| Chave que cifra (`ENTREGAS_SAQUE_CHAVE`) | Docker secret `entregas_saque_chave`, arquivo `/run/secrets/entregas_saque_chave` | Só nos serviços `application`, `queue` e `scheduler` |

- O backup diário do MySQL (bucket da Oracle) e um acesso indevido ao banco só mostram texto cifrado.
- A chave decifrada vive só na memória do processo que chama o Asaas. Nunca vai para log, resposta, cache nem fila
  (o job recebe o uuid do saque, não a chave).
- Sem o secret (arquivo ausente ou vazio), conectar responde 503 "Saque Pix indisponível: chave de criptografia não
  configurada no servidor." e nenhum saque sai.
- Nenhuma rota devolve a chave do Asaas. O console recebe `conectada`, nome da conta, CPF/CNPJ parcialmente escondido
  e a wallet.

### Camadas contra transferência sem permissão

1. **Chave Pix e valor sempre do banco.** O pedido do app traz só o valor desejado e o uuid do saque. A chave Pix
   destino é a cadastrada pelo admin; o saldo é recalculado no banco dentro da transação.
2. **Validação de saque do Asaas** (Integrações → Mecanismos de segurança, na conta da central): antes de cada
   transferência por API, o Asaas chama `POST v1/entregas/asaas/validar-saque` com o token no cabeçalho
   `asaas-access-token`. Só um saque nosso em andamento, com o mesmo `transfer.id`, valor e chave Pix, recebe
   `APPROVED`. Uma chave do Asaas vazada não tira dinheiro, porque o Entregas recusa qualquer transferência que não criou.
3. **Wallet fixada:** a wallet da conta é gravada ao conectar e conferida (`GET /wallets`) antes de cada transferência.
   Uma chave trocada por outra de outra conta Asaas não paga.
4. **Troca da chave Pix do motoboy:** bloqueia o saque por 24 h, avisa o motoboy por push ("Sua chave Pix de saque foi
   alterada") e grava quem trocou e quando. Fecha o desvio do saque por um admin mal-intencionado ou com a conta
   invadida.
5. **Limites e um saque por vez**, trava por motoboy (`lockForUpdate`) e idempotência pelo uuid do saque gerado no app.
6. **Bloqueio por motoboy** no console (celular roubado, acerto pendente).
7. **Recomendação** (na tela, sem obrigar): lista de IPs do Asaas com o IP fixo da VPS.

Ameaça residual aceita: com o token do motoboy e o celular desbloqueado, alguém saca o dinheiro dele **para a chave Pix
dele mesmo**; não há perda para terceiros.

## Conexão da central (console)

Card "Saque Pix (Asaas)" em Financeiro → Configurações, em três passos.

### 1. Conectar

- O admin cola a chave de API e digita a própria senha (`Hash::check` contra `users.password`; errada → 422 "Senha
  incorreta.").
- A API chama `GET /myAccount` e `GET /wallets` com a chave. 401/403 → 422 "Chave de API inválida ou sem permissão.
  Gere uma nova no Asaas (Integrações → Chaves de API)."; outro erro → 502 com a mensagem do Asaas.
- Grava cifrada, com nome, CPF/CNPJ, wallet, `conectada_por` e `conectada_em`. Responde com os dados da conta para o
  admin confirmar.
- **Trocar a chave** (com a senha): a nova chave precisa ser da mesma wallet; senão 422 "Esta chave é de outra conta
  Asaas. Para mudar de conta, desconecte e conecte de novo." Mudar de conta = desconectar e conectar, e isso apaga a
  validação testada e desliga o saque.
- **Desconectar** (com a senha): apaga a chave e o hash do token, desliga o saque. Sem chave a rotina não consegue
  acompanhar saque em andamento, então desconectar com saque em andamento → 422 "Há saque em andamento; espere ele
  terminar."

### 2. Validação de saque

- "Gerar token": token aleatório de 48 caracteres, mostrado uma vez junto da URL
  `https://entregas-api.restaurantepro.com.br/v1/entregas/asaas/validar-saque`, com o passo a passo do Asaas. Gerar de
  novo invalida o anterior e pede novo teste.
- "Testar validação": o admin informa uma chave Pix própria. A API cria `POST /transfers` de **R$ 1,00** para ela com
  `externalReference` `teste:<uuid>`. A rota de validação reconhece o teste pelo `transfer.id` gravado e responde
  `REFUSED` ("Teste do Entregas"); ao receber o pedido com o token certo, grava `validacao_confirmada_em`. A tela
  consulta o resultado por até 60 s: "Recusada pelo Entregas ✓", ou "O Asaas não chamou o Entregas: confira a URL e o
  token" (se a validação não estiver configurada, o R$ 1 cai na conta do próprio admin).
- Limites do teste: **um por vez** (com o anterior ainda sem resposta da validação há menos de 2 min → 422 "Espere o
  resultado do teste anterior.") e **no máximo 5 por dia** (dia de Brasília → 422 "Limite de testes de hoje atingido.").
  Grava quem pediu (`teste_pedido_por`) e loga com a chave Pix escondida. Se o Asaas recusar o teste ou não responder,
  a validação e o teste voltam ao que eram: um teste que falha não desliga o saque de uma central já confirmada.

### 3. Ligar o saque

- Interruptor travado até os passos 1 e 2 (`validacao_confirmada_em` preenchido).
- Configurações (padrões): `minimo` R$ 10,00; `maximo_dia` R$ 500,00; `saques_dia` 3; `taxa` R$ 0,00. Validação:
  mínimo ≥ R$ 2,00 (acima do R$ 1,00 do teste, para não se confundirem); máximo do dia ≥ mínimo; saques por dia de 1 a
  20; taxa de R$ 0 a R$ 10, menor que o mínimo; valores de 0 a 9.999.999,99.

## Fluxo do saque

### Disponível

- `disponivel = min(saldo − taxa, maximo_dia − sacado_hoje)`, arredondado a centavos; ≤ 0 ou abaixo do mínimo → sem
  saque. "Hoje" e "sacado hoje" no dia de Brasília (`Financeiro::fuso`), contando os saques não falhos.
- Um saque em andamento (`enviando` ou `processando`) por motoboy.

### Pedido

`POST v1/entregas/motoboy/saques` `{uuid, valor}` (grupo `v1/entregas/motoboy`, token de motoboy). O `uuid` é gerado no
app e é a chave de idempotência: repetido devolve o saque já criado (200), sem criar outro.

Conferências, nesta ordem, cada uma com mensagem pt-BR (422): chave `ENTREGAS_SAQUE` ligada; central conectada, com
validação confirmada e saque ligado; motoboy com chave Pix; sem troca de chave nas últimas 24 h ("Chave Pix alterada;
saque liberado às HH:MM de DD/MM."); motoboy não bloqueado; nenhum saque em andamento; saques do dia abaixo do limite;
valor ≥ mínimo e ≤ disponível.

### Reserva (uma transação)

1. `lockForUpdate` na linha do motoboy em `entregas_motoboys_financeiro` (o saque exige chave Pix, então a linha existe).
2. Recalcula saldo, sacado hoje e saques do dia no banco e refaz as conferências de valor.
3. Insere o saque `enviando` com valor, taxa, a chave Pix e o tipo copiados naquele instante e o
   `externalReference` = `saque:<uuid>`.
4. `Pagamentos::registrar`: pagamento `forma = pix`, `observacao_interna` "Saque Pix pelo app", ligado ao saque
   (`entregas_saques.pagamento_uuid`).
5. Taxa > 0: lançamento `ajuste` negativo, motivo "Taxa do saque Pix", ligado ao saque por `taxa_lancamento_id` (sem
   `pagamento_uuid`, para o extrato não mostrar como pagamento).

O dinheiro sai do saldo na hora: console, painel e extrato já o mostram como pago.

### Transferência (fora da transação)

Decifra a chave, confere a wallet e chama `POST /transfers`:
`{value, operationType: "PIX", pixAddressKey, pixAddressKeyType, description: "Saque Entregas", externalReference}`.
Tipos: `cpf` → `CPF`, `cnpj` → `CNPJ`, `telefone` → `PHONE`, `email` → `EMAIL`, `aleatoria` → `EVP` (formato do
telefone a confirmar no sandbox).

- Resposta com `id`: grava `transfer_id`, situação `processando` (ou `concluido`/`falhou` se já vier `DONE`/`FAILED`).
- Asaas recusou (4xx com `errors`): `falhou` com a mensagem do Asaas e reembolso (abaixo).
- Erro de rede ou 5xx: fica `enviando` sem `transfer_id`; a rotina resolve. Nunca dá por falho o que pode ter saído.
- Wallet não confere: `falhou` "A chave do Asaas não é mais da conta conectada." e reembolso; a central é avisada no
  painel.

### Validação (`POST v1/entregas/asaas/validar-saque`)

- Sem token ou token sem central → 401. Comparação pelo hash, `hash_equals`. `throttle` próprio.
- `type` diferente de `TRANSFER` → `REFUSED`.
- `transfer.id` de um teste da central → `REFUSED` "Teste do Entregas" e grava `validacao_confirmada_em`.
- `transfer.id` de um saque da central em `enviando`/`processando`, com o mesmo valor e a mesma chave Pix →
  `APPROVED`. Se o pedido chegar antes de o `transfer_id` ser gravado (corrida rara): aceita o saque `enviando` da
  central criado há até 2 min com mesmo valor e chave Pix e grava o `transfer_id`.
- Qualquer outro caso → `REFUSED` "Transferência não reconhecida pelo Entregas".
- Cada decisão vai ao log `[entregas] saque: validação` com central, transfer, decisão e motivo (chave Pix parcialmente
  escondida).

### Acompanhamento (`entregas:saques-conferir`, a cada minuto)

Saques `enviando`/`processando` de centrais conectadas:

- Com `transfer_id`: `GET /transfers/{id}`.
- Sem `transfer_id` há mais de 5 min: procura pelo `externalReference` nas transferências recentes (paginado, como no
  RestaurantePro). Achou → adota o `id`. Não achou → `falhou` "Saque interrompido antes de chegar ao Asaas" e reembolso.
- `DONE` → `concluido`, `concluido_em`, guarda `transactionReceiptUrl` como comprovante, push "Seu saque de R$ X caiu
  no Pix".
- `FAILED`/`CANCELLED` → `falhou` com o motivo (`failReason` ou o da validação) e reembolso.
- Outros (`PENDING`, `BANK_PROCESSING`) → continua.
- Erro de rede ou 5xx → tenta no minuto seguinte.
- `withoutOverlapping`, `when(ENTREGAS_SAQUE ligada)`, falha numa central não para as outras.

### Reembolso

Estorno automático do pagamento do saque e da taxa: `Pagamentos::estornar` com o motivo padrão "Pagamento estornado" (o
app reconhece o estorno de pagamento por esse texto; o motivo da falha fica no saque e no push) e `Lancamentos::estornar`
da taxa com "Taxa do saque devolvida". Push
"Saque não concluído; o valor voltou para o seu saldo." com o motivo. Idempotente (estorno repetido → ignora o 409).

### Console

- O pagamento de saque não se estorna à mão: em andamento → 422 "Saque em andamento."; concluído → 422 "O dinheiro do
  saque já saiu; corrija com um ajuste."; falho já vem estornado.
- Bloquear/desbloquear o saque do motoboy: `PUT financeiro/motoboys/{id}/saque` `{bloqueado}`.
- `PUT financeiro/motoboys/{id}/pix` passa a gravar `pix_alterada_em`/`pix_alterada_por` e a mandar o push, quando a
  chave muda (não no primeiro cadastro).

## Telas

### Console

- **Financeiro → Configurações → "Saque Pix (Asaas)"**: os três passos acima. Textos explicam a conta própria e a lista
  de IPs com o IP da VPS.
- **Financeiro → aba "Saques"**: últimos 30 dias, mais novo primeiro: data e hora, motoboy, valor, taxa, situação
  (enviando, processando, concluído, falhou + motivo), link do comprovante do Asaas; filtro por situação.
- **Extrato do motoboy**: interruptor "Saque liberado / bloqueado" junto da chave Pix; aviso de carência depois da troca
  ("Saque liberado às 14:30 de 27/10 · chave trocada por Fulano"); selo "Saque Pix · app" com a situação no pagamento.
- **Lista de motoboys**: selo "saque bloqueado".
- **Painel do Financeiro → Atenção**: `saque_falhou` (últimas 24 h) e `saque_parado` (processando há mais de 1 h).
- Tradução pt-BR nas chaves do engine, `i18n-check` com exit 0.

### App (`entregas-navigator`)

- **Minha carteira** (`FolhaDaCarteira`): botão "Sacar via Pix" quando a `carteira` traz o bloco `saque`
  (`{disponivel, minimo, maximo_hoje, taxa, em_andamento, indisponivel_motivo}`; ausente = central sem saque ligado ou
  API antiga, sem botão). Indisponível: botão apagado com o motivo.
- **Sacar**: valor com atalho "Tudo"; mostra o que cai no Pix, a taxa que sai do saldo e a chave Pix parcialmente
  escondida com o tipo; "Confirmar" pede o desbloqueio do aparelho. Sem bloqueio de tela configurado: "Ative a senha de
  tela do celular para sacar."
- **Acompanhamento**: `GET v1/entregas/motoboy/saques/{uuid}` a cada 3 s por até 1 min ("Enviando…" → "Caiu no seu Pix
  ✓" ou "Não concluído; o valor voltou ao saldo"); depois, o push avisa.
- **Extrato/recibo**: o saque aparece como pagamento "Saque Pix"; o recibo traz o link do comprovante do Asaas.
- APK novo = versão mínima (trava "Atualize o app").

## Dados

Migration nova (`*_entregas_saques_*`):

- `entregas_asaas_conexoes` (uma por `company_uuid`, única): `chave_cifrada`, `conta_nome`, `conta_documento`,
  `wallet_id`, `conectada_por`, `conectada_em`, `token_hash`, `token_gerado_em`, `validacao_confirmada_em`,
  `teste_transfer_id`, `saque_ligado`, `minimo`, `maximo_dia`, `saques_dia`, `taxa`, `atualizada_por`, timestamps.
- `entregas_saques`: `uuid` (único, vem do app), `company_uuid`, `driver_uuid`, `valor`, `taxa`, `chave_pix`,
  `tipo_chave`, `situacao` (`enviando`, `processando`, `concluido`, `falhou`), `motivo_falha`, `transfer_id` (único
  quando preenchido), `external_reference`, `pagamento_uuid`, `comprovante_url`, `asaas_status`, `concluido_em`,
  timestamps. Índices por `company_uuid + situacao` e `driver_uuid + created_at`.
- `entregas_motoboys_financeiro`: + `saque_bloqueado` (bool, padrão falso), `pix_alterada_em`, `pix_alterada_por`.

Classes em `App\Support\Entregas\Saques`: `Cofre` (cifra/decifra com o secret), `AsaasCliente` (único ponto de HTTP com
o Asaas, base `services.entregas.asaas_url`, padrão `https://api.asaas.com/v3`; só `https://` e sem seguir
redirecionamento, para o `access_token` não ir a outro endereço), `ConexaoAsaas`, `Saques` (disponível,
pedido, reserva, transferência, reembolso), `ValidacaoDeSaque`, `AcompanhamentoDeSaques`. Controllers em
`api/app/Http/Controllers/Entregas/` (`SaquesController` do app, `SaquePixController` do console,
`ValidacaoAsaasController` público). Rotas no `RouteServiceProvider` (o `imports-dos-providers.php` confere).

Configuração: `ENTREGAS_SAQUE` (`services.entregas.saque`), `ASAAS_API_URL` (sandbox nos testes de ensaio) e o secret.

## Testes

PHP (`scripts/teste-php/`, php-wasm), com um Asaas falso no lugar do `AsaasCliente`:

- `saque-cofre.php`: cifra e decifra; texto no banco ≠ chave; sem secret → erro claro, nada gravado.
- `saque-conexao.php`: senha errada, chave inválida, chave de outra wallet na troca, desconectar com saque em
  andamento, token gerado (hash gravado, original nunca), teste da validação.
- `saque-pedido.php`: cada conferência; valor acima do disponível; mesmo uuid duas vezes; trava; lançamentos do
  pagamento e da taxa; reembolso dos dois; estorno à mão bloqueado; carência de 24 h; bloqueio.
- `saque-validacao.php`: sem token/token errado → 401; tipo errado, transferência desconhecida, valor ou chave
  diferentes → `REFUSED`; saque nosso → `APPROVED`; corrida do `transfer_id` ainda não gravado; teste → `REFUSED` e
  `validacao_confirmada_em`.
- `saque-acompanhamento.php`: DONE, FAILED, CANCELLED, sem `transfer_id` (adota ou falha), erro de rede nunca falha,
  pushes, agenda, rotas, stack (secret e `ENTREGAS_SAQUE`).

Front: `scripts/teste-portal/` (card de configurações, aba Saques, interruptor do motoboy). App:
`scripts/testes/` do `entregas-navigator` (disponível, mensagens, tela de acompanhamento).

Ensaio antes de produção: sandbox do Asaas (`ASAAS_API_URL=https://api-sandbox.asaas.com/v3`), conta de teste com a
validação configurada, saque de ponta a ponta, falha e reembolso.

## Implantação

1. VPS: `openssl rand -base64 32 | docker secret create entregas_saque_chave -` (no README do deploy).
2. Push e `bash deploy/atualizar.sh` (api e console); a migration roda no `deploy.sh`.
3. Stack no Portainer com o secret nos serviços `application`, `queue` e `scheduler` e `ENTREGAS_SAQUE=1` ("Re-pull
   image" desligado).
4. A central conecta a chave, gera o token, configura e testa a validação no Asaas, define os limites e liga o saque.
5. APK novo nos celulares.
6. Primeiro saque real: um motoboy avisado, valor pequeno.

## Documentação

- `api/app/Support/Entregas/Saques/CLAUDE.md` novo + linha na tabela do `CLAUDE.md` da raiz.
- Financeiro: de "só registro" para "registro + saque Pix pelo app" (a seção das regras e os 422 novos).
- `packages/fleetops/CLAUDE.md` (telas), `api/app/Http/Controllers/Entregas/CLAUDE.md` (app), `deploy/CLAUDE.md`
  (secret), `docs/historico.md`.

## Fora do escopo

- Webhooks `TRANSFER_*` do Asaas (a rotina de 1 min basta).
- Saque agendado, saque para outra chave, o motoboy editar a chave Pix.
- Pagamento de motoboy disparado pelo admin via Asaas (o console continua só registrando os pagamentos manuais).
- Abrir o Financeiro inteiro ao admin da organização. As rotas novas do saque (conexão, configurações, aba Saques,
  bloqueio por motoboy) já aceitam o admin do sistema **e** o admin da organização. As outras do Financeiro seguem só
  com o admin do sistema (pendência do `negarSeNaoAdmin`), então, até ela ser resolvida, o admin da organização de uma
  central nova vê o card e a aba Saques, mas não o extrato do motoboy. Por isso o interruptor de bloqueio aparece também
  na aba Saques (lista dos motoboys com chave Pix). Hoje a única central é a do Edgard, em que os dois admins são ele.
